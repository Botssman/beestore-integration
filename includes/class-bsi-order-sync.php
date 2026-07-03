<?php
/**
 * Синхронизация заказов WooCommerce → BeeStore.
 *
 * Заказ WC (с типом плательщика B2C) передаётся через SOAP fInserimentoPrenotazione.
 * Структура: testata + righe (массив).
 *
 * Хук запуска: woocommerce_payment_complete (после оплаты) или
 * woocommerce_order_status_processing (если оплата была наличными/наложка).
 *
 * В мета заказа сохраняем:
 *   _bsi_igu_prenotazione — IGU, возвращённый BeeStore.
 *   _bsi_sync_attempts     — количество попыток.
 *   _bsi_sync_status       — pending|success|error.
 *   _bsi_sync_error        — последнее сообщение об ошибке.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSI_Order_Sync {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$settings = get_option( 'bsi_settings', array() );

		if ( isset( $settings['enable_order_sync'] ) && '1' === $settings['enable_order_sync'] ) {
			// После оплаты — самый надёжный момент.
			add_action( 'woocommerce_payment_complete', array( $this, 'on_payment_complete' ), 20, 1 );
			// Для COD/offline gateway — срабатывает при переводе в processing.
			add_action( 'woocommerce_order_status_processing', array( $this, 'on_status_processing' ), 20, 1 );
			// Отмена заказа.
			add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_cancelled' ), 20, 1 );
			add_action( 'woocommerce_order_status_refunded', array( $this, 'on_order_refunded' ), 20, 1 );
		}

		// Обработка очереди — отдельный cron.
		add_action( 'bsi_cron_process_queue', array( $this, 'process_queue' ) );
	}

	/* ---------------------------------------------------------------------
	 * Хуки.
	 * --------------------------------------------------------------------- */
	public function on_payment_complete( $order_id ) {
		$this->enqueue_or_send( $order_id );
	}

	public function on_status_processing( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		// Если уже синхронизирован — выходим.
		if ( $order->get_meta( '_bsi_sync_status' ) === 'success' ) {
			return;
		}
		$this->enqueue_or_send( $order_id );
	}

	public function on_order_cancelled( $order_id ) {
		$this->cancel_in_beestore( $order_id );
	}

	public function on_order_refunded( $order_id ) {
		$this->cancel_in_beestore( $order_id );
	}

	/* ---------------------------------------------------------------------
	 * Отправить заказ в BeeStore (или поставить в очередь).
	 * --------------------------------------------------------------------- */
	private function enqueue_or_send( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Если уже отправлен — не дублируем.
		if ( $order->get_meta( '_bsi_sync_status' ) === 'success' ) {
			return;
		}

		// Пытаемся отправить сразу.
		$result = $this->send_order( $order_id );

		if ( is_wp_error( $result ) ) {
			// Ставим в очередь для ретраев.
			$this->enqueue_retry( $order_id, $result->get_error_message() );
		}
	}

	/**
	 * Реальная отправка заказа в BeeStore.
	 *
	 * @param int $order_id
	 * @return string|WP_Error  IGUPrenotazione при успехе.
	 */
	public function send_order( $order_id ) {
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'bsi_no_order', __( 'Заказ не найден.', 'beestore-integration' ) );
		}

		$settings = get_option( 'bsi_settings', array() );

		// Если уже отправлен — выходим.
		if ( $order->get_meta( '_bsi_sync_status' ) === 'success' ) {
			return $order->get_meta( '_bsi_igu_prenotazione' );
		}

		// Строим testata.
		$testata = $this->build_testata( $order, $settings );

		// Строим righe.
		$righe = $this->build_righe( $order, $settings );

		if ( empty( $righe ) ) {
			return new WP_Error( 'bsi_empty_lines', __( 'В заказе нет позиций для передачи в BeeStore.', 'beestore-integration' ) );
		}

		// Сохраняем информацию о попытке.
		$attempts = (int) $order->get_meta( '_bsi_sync_attempts' );
		$order->update_meta_data( '_bsi_sync_attempts', $attempts + 1 );
		$order->update_meta_data( '_bsi_sync_status', 'pending' );
		$order->save();

		BSI_Logger::instance()->info( 'order_sync', 'Отправка заказа в BeeStore', array(
			'order_id'  => $order_id,
			'num'       => $order->get_order_number(),
			'attempts'  => $attempts + 1,
			'lines'     => count( $righe ),
		) );

		$result = BSI_Client::instance()->insert_prenotazione( $testata, $righe );

		if ( is_wp_error( $result ) ) {
			$order->update_meta_data( '_bsi_sync_status', 'error' );
			$order->update_meta_data( '_bsi_sync_error', $result->get_error_message() );
			$order->save();
			// Добавим заметку.
			$order->add_order_note( sprintf(
				/* translators: %s — сообщение об ошибке. */
				__( 'BeeStore: не удалось передать заказ. Ошибка: %s', 'beestore-integration' ),
				$result->get_error_message()
			) );
			return $result;
		}

		// Успех.
		$order->update_meta_data( '_bsi_sync_status', 'success' );
		$order->update_meta_data( '_bsi_igu_prenotazione', $result );
		$order->update_meta_data( '_bsi_sync_error', '' );
		$order->save();

		$order->add_order_note( sprintf(
			/* translators: %s — IGU Prenotazione. */
			__( 'BeeStore: заказ передан, IGUPrenotazione = %s', 'beestore-integration' ),
			$result
		) );

		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Построение testata.
	 * --------------------------------------------------------------------- */
	private function build_testata( $order, $settings ) {
		$order_id     = $order->get_id();
		$order_number = $order->get_order_number();
		$now          = current_time( 'Ymd' );
		$delivery     = current_time( 'Ymd' );

		// Адреса.
		$billing_first = $order->get_billing_first_name();
		$billing_last  = $order->get_billing_last_name();
		$nominativo    = trim( $billing_first . ' ' . $billing_last );

		// Способ оплаты → IDTipoIncasso.
		$payment_method = $order->get_payment_method();
		$payment_title  = $order->get_payment_method_title();
		$tipo_incasso   = $this->map_payment_to_tipo_incasso( $payment_method, $settings );

		// Акортно (если уже оплачен).
		$paid = $order->is_paid();
		$acconto_versato = $paid ? (float) $order->get_total() : 0;

		$testata = array(
			'iguNegozio'         => isset( $settings['igu_negozio'] ) ? $settings['igu_negozio'] : '',
			'dtPrenotazione'     => $now,
			'numPrenotazione'    => $order_id, // Числовой номер.
			'numPrenotazioneChr' => (string) $order_number, // Альтернативный строковый.
			'iguNegozioPrelievo' => '',
			'consegna'           => 2, // 2 = доставка клиенту.
			'dtConsegna'         => $delivery,
			'iguCliente'         => isset( $settings['igu_cliente'] ) ? $settings['igu_cliente'] : '',
			'tessera'            => '',
			'nominativo'         => $nominativo,
			'indirizzo'          => $order->get_billing_address_1(),
			'citta'              => $order->get_billing_city(),
			'cap'                => $order->get_billing_postcode(),
			'provincia'          => $order->get_billing_state(),
			'telefono'           => $order->get_billing_phone(),
			'codiceStato'        => $order->get_billing_country() ?: 'IT',
			'partitaIva'         => $order->get_billing_company() ?: '',
			'codFisc'            => '',
			// Адрес доставки.
			'nominativoSped'     => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ) ?: $nominativo,
			'indirizzoSped'      => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
			'cittaSped'          => $order->get_shipping_city() ?: $order->get_billing_city(),
			'capSped'            => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
			'provinciaSped'      => $order->get_shipping_state() ?: $order->get_billing_state(),
			'telefonoSped'       => $order->get_billing_phone(),
			'codiceStatoSped'    => $order->get_shipping_country() ?: $order->get_billing_country() ?: 'IT',
			'partitaIvaSped'     => '',
			'codFiscSped'        => '',
			// Платёж.
			'dtAccontoWeb'       => $paid ? $now : '',
			'codIvaAcconto'      => isset( $settings['cod_iva_default'] ) ? $settings['cod_iva_default'] : '22',
			'accontoVersato'     => $acconto_versato,
			'idTipoIncasso'      => $tipo_incasso,
			'iguCarta'           => '',
			'accontoValuta'      => 0,
			'cambioPag'          => 0,
			'codiceEsternoValuta'=> '',
			'dsPagamento'        => $payment_title,
			'idTipoPrenotazione' => 0,
			'note'               => substr( $order->get_customer_note() ?: '', 0, 1000 ),
			'noteEvidenza'       => '',
			'pathUrl'            => '',
			'daFatturare'        => true,
			'emailPren'          => $order->get_billing_email(),
			'cognome'            => $billing_last,
			'nome'               => $billing_first,
			'codDestSDI'         => isset( $settings['cod_dest_sdi'] ) ? $settings['cod_dest_sdi'] : '',
		);

		return $testata;
	}

	/* ---------------------------------------------------------------------
	 * Построение righe.
	 * --------------------------------------------------------------------- */
	private function build_righe( $order, $settings ) {
		$righe = array();
		$line_index = 1;

		foreach ( $order->get_items() as $item_id => $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			// Получаем BeeStore-коды из meta.
			$cod_articolo = $product->get_meta( '_bsi_cod_articolo' );
			$barcode      = $product->get_meta( '_bsi_barcode' );
			$ean          = $product->get_meta( '_bsi_ean' );
			$cod_iva      = isset( $settings['cod_iva_default'] ) ? $settings['cod_iva_default'] : '22';

			if ( ! $cod_articolo && $barcode ) {
				// Если CodArticolo пустой, пробуем barcode.
				$cod_articolo = '';
			} elseif ( ! $cod_articolo ) {
				// Пропускаем позицию, если нет ни кода, ни штрихкода BeeStore.
				BSI_Logger::instance()->warn( 'order_sync', 'У товара нет кода BeeStore — позиция пропущена', array(
					'product_id' => $product->get_id(),
					'sku'        => $product->get_sku(),
				) );
				continue;
			}

			$qty       = (float) $item->get_quantity();
			$gross     = (float) $item->get_subtotal() / max( $qty, 1 );
			$discount  = 0;
			$net_unit  = (float) $item->get_total() / max( $qty, 1 );

			if ( $gross > 0 && $gross > $net_unit ) {
				$discount = ( 1 - $net_unit / $gross ) * 100;
			}

			$righe[] = array(
				'codArticolo'   => $cod_articolo,
				'barcode'       => $barcode ?: $ean,
				'quantitaMov'   => $qty,
				'przVenditaLordo' => round( $gross, 2 ),
				'sconto'        => round( $discount, 2 ),
				'przVenditaNetto' => round( $net_unit, 2 ),
				'tipoPrezzo'    => 1, // 1 = Listino.
				'codIva'        => $cod_iva,
				'przLordoValuta'=> 0,
				'przNettoValuta'=> 0,
				'iguMagazzinoRiga' => isset( $settings['igu_magazzino_riga'] ) ? $settings['igu_magazzino_riga'] : '',
				'rigaDaConfermare' => false,
				'codiceRiga'    => $order->get_id() . '_' . $line_index,
				'annullato'     => false,
				'matricola'     => '',
				'taxPercentuale'=> 0,
				'taxValore'     => 0,
			);

			$line_index++;
		}

		// Если в заказе есть shipping как отдельная позиция — добавляем как информационную строку.
		$shipping_total = (float) $order->get_shipping_total();
		if ( $shipping_total > 0 ) {
			// BeeStore обычно не ожидает строку доставки. Записываем в note заказа.
			// Если в будущем потребуется — можно добавить как специальную строку.
		}

		return $righe;
	}

	/* ---------------------------------------------------------------------
	 * Маппинг способа оплаты WooCommerce → IDTipoIncasso BeeStore.
	 * --------------------------------------------------------------------- */
	private function map_payment_to_tipo_incasso( $gateway_id, $settings ) {
		$mapping = isset( $settings['mapping_payment'] ) && is_array( $settings['mapping_payment'] ) ? $settings['mapping_payment'] : array();
		if ( isset( $mapping[ $gateway_id ] ) && $mapping[ $gateway_id ] > 0 ) {
			return (int) $mapping[ $gateway_id ];
		}
		// Дефолтное значение.
		return isset( $settings['id_tipo_incasso_default'] ) ? (int) $settings['id_tipo_incasso_default'] : 3;
	}

	/* ---------------------------------------------------------------------
	 * Очередь ретраев.
	 * --------------------------------------------------------------------- */
	private function enqueue_retry( $order_id, $error_message ) {
		global $wpdb;
		$table = $wpdb->prefix . BSI_QUEUE_TABLE;

		$wpdb->insert(
			$table,
			array(
				'created_at' => current_time( 'mysql' ),
				'type'       => 'order_sync',
				'object_id'  => $order_id,
				'payload'    => wp_json_encode( array( 'error' => $error_message ) ),
				'status'     => 'pending',
				'attempts'   => 0,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d' )
		);

		BSI_Logger::instance()->warn( 'order_sync', 'Заказ поставлен в очередь ретраев', array(
			'order_id' => $order_id,
			'error'    => $error_message,
		) );
	}

	/**
	 * Cron-обработчик очереди ретраев.
	 */
	public function process_queue() {
		global $wpdb;
		$table = $wpdb->prefix . BSI_QUEUE_TABLE;

		// Берём до 20 записей, у которых attempts < 5.
		$items = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE type = 'order_sync' AND status = 'pending' AND attempts < 5 ORDER BY created_at ASC LIMIT 20"
		) ); // phpcs:ignore

		if ( empty( $items ) ) {
			return;
		}

		foreach ( $items as $item ) {
			// Увеличиваем счётчик попыток.
			$wpdb->update(
				$table,
				array(
					'attempts' => $item->attempts + 1,
				),
				array( 'id' => $item->id ),
				array( '%d' ),
				array( '%d' )
			);

			$result = $this->send_order( $item->object_id );

			if ( is_wp_error( $result ) ) {
				$wpdb->update(
					$table,
					array(
						'status'     => 'pending',
						'last_error' => $result->get_error_message(),
					),
					array( 'id' => $item->id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			} else {
				$wpdb->update(
					$table,
					array(
						'status'       => 'done',
						'processed_at' => current_time( 'mysql' ),
						'last_error'   => '',
					),
					array( 'id' => $item->id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Отмена заказа в BeeStore.
	 * --------------------------------------------------------------------- */
	private function cancel_in_beestore( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$igu_prenotazione = $order->get_meta( '_bsi_igu_prenotazione' );
		if ( ! $igu_prenotazione ) {
			return; // Заказ не был передан в BeeStore.
		}

		$result = BSI_Client::instance()->modify_prenotazione(
			$igu_prenotazione,
			array(
				'Annullato'        => true,
				'AccontoVersato'   => 0,
				'DTAccontoWeb'     => current_time( 'Ymd' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			$order->add_order_note( sprintf(
				__( 'BeeStore: ошибка отмены заказа. %s', 'beestore-integration' ),
				$result->get_error_message()
			) );
		} else {
			$order->add_order_note( __( 'BeeStore: заказ отменён.', 'beestore-integration' ) );
			$order->update_meta_data( '_bsi_cancelled_in_beestore', current_time( 'mysql' ) );
			$order->save();
		}
	}
}
