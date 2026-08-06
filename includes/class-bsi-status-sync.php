<?php
/**
 * Обратная синхронизация статусов заказов из BeeStore в WooCommerce.
 *
 * Cron-задача: вызывает fStatoPrenotazioni для каждого заказа WC, у которого
 * есть _bsi_igu_prenotazione и который не завершён/не отменён.
 *
 * Обновляем в WC:
 *  - Tracking Number
 *  - Статус (если Evasa=1 → completed)
 *  - Сумма доплаты (если AccettoUsato изменилось)
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class BSI_Status_Sync {

        private static $instance = null;

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {
                add_action( 'bsi_cron_status_sync', array( $this, 'cron_sync' ) );
        }

        /**
         * Cron: опросить BeeStore по всем незакрытым заказам.
         */
        public function cron_sync() {
                $settings = get_option( 'bsi_settings', array() );
                if ( ! isset( $settings['enable_status_sync'] ) || '1' !== $settings['enable_status_sync'] ) {
                        return;
                }
                // Если опрос статусов отключён в настройках — выходим.
                $freq = isset( $settings['status_sync_frequency'] ) ? $settings['status_sync_frequency'] : 'hourly';
                if ( 'disabled' === $freq ) {
                        return;
                }

                // Ищем заказы, у которых есть IGUPrenotazione, но статус — processing или on-hold.
                $query = wc_get_orders( array(
                        'limit'      => 100,
                        'status'     => array( 'processing', 'on-hold' ),
                        'meta_key'   => '_bsi_igu_prenotazione', // phpcs:ignore
                        'meta_compare' => 'EXISTS',
                        'orderby'    => 'date',
                        'order'      => 'DESC',
                        'return'     => 'ids',
                ) );

                BSI_Logger::instance()->info( 'status_sync', 'Cron статусов: запрос заказов', array(
                        'count' => count( $query ),
                ) );

                foreach ( $query as $order_id ) {
                        $this->sync_single_order( $order_id );
                }
        }

        /**
         * Запросить статус одного заказа из BeeStore и обновить WC.
         *
         * @param int $order_id
         * @return bool|WP_Error
         */
        public function sync_single_order( $order_id ) {
                $order = wc_get_order( $order_id );
                if ( ! $order ) {
                        return new WP_Error( 'bsi_no_order', __( 'Заказ не найден.', 'beestore-integration' ) );
                }

                $igu_prenotazione = $order->get_meta( '_bsi_igu_prenotazione' );
                if ( ! $igu_prenotazione ) {
                        return new WP_Error( 'bsi_no_igu', __( 'У заказа нет IGUPrenotazione.', 'beestore-integration' ) );
                }

                $settings = get_option( 'bsi_settings', array() );

                $result = BSI_Client::instance()->get_status_prenotazioni( array(
                        'IGUPrenotazione' => $igu_prenotazione,
                        'IGUCliente'      => isset( $settings['igu_cliente'] ) ? $settings['igu_cliente'] : '',
                ) );

                if ( is_wp_error( $result ) ) {
                        BSI_Logger::instance()->error( 'status_sync', 'Ошибка запроса статуса', array(
                                'order_id' => $order_id,
                                'err'      => $result->get_error_message(),
                        ) );
                        return $result;
                }

                if ( empty( $result ) ) {
                        BSI_Logger::instance()->warn( 'status_sync', 'BeeStore вернул пустой ответ', array(
                                'order_id'         => $order_id,
                                'igu_prenotazione' => $igu_prenotazione,
                        ) );
                        return false;
                }

                // Берём первую запись.
                $bs_record = is_array( $result ) ? $result[0] : $result;
                $bs_record = (array) $bs_record;

                $updated = $this->apply_status_to_order( $order, $bs_record );

                return $updated;
        }

        /**
         * Применить поля из BeeStore к заказу WC.
         *
         * @param WC_Order $order
         * @param array    $bs_record Массив полей fStatoPrenotazioni.
         */
        private function apply_status_to_order( $order, $bs_record ) {
                $changes = array();
                $order_id = $order->get_id();

                // Tracking Number.
                $tracking = isset( $bs_record['TrackingNumber'] ) ? (string) $bs_record['TrackingNumber'] : '';
                if ( $tracking && $tracking !== $order->get_meta( '_bsi_tracking_number' ) ) {
                        $order->update_meta_data( '_bsi_tracking_number', $tracking );
                        $changes[] = 'tracking=' . $tracking;
                }

                // Invoice number/date.
                $invoice_num = isset( $bs_record['NumeroFattura'] ) ? (string) $bs_record['NumeroFattura'] : '';
                if ( $invoice_num && $invoice_num !== $order->get_meta( '_bsi_invoice_number' ) ) {
                        $order->update_meta_data( '_bsi_invoice_number', $invoice_num );
                        $changes[] = 'invoice=' . $invoice_num;
                }

                // DaFatturare.
                $da_fatturare = isset( $bs_record['DaFatturare'] ) && (bool) $bs_record['DaFatturare'];
                $order->update_meta_data( '_bsi_da_fatturare', $da_fatturare );

                // Статус Evasa (исполнен) → completed.
                $evasa = isset( $bs_record['Evasa'] ) && (bool) $bs_record['Evasa'];
                if ( $evasa && ! $order->has_status( array( 'completed', 'refunded', 'cancelled' ) ) ) {
                        $changes[] = 'status=completed (Evasa=1)';
                }

                // Annullato.
                $annullato = isset( $bs_record['Annullato'] ) && (bool) $bs_record['Annullato'];
                if ( $annullato && ! $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
                        $changes[] = 'status=cancelled (Annullato=1)';
                }

                // Salda (оплачен).
                $salda = isset( $bs_record['Saldata'] ) && (bool) $bs_record['Saldata'];
                if ( $salda ) {
                        $changes[] = 'paid=1 (Saldata=1)';
                }

                // Сохраняем сырой ответ для отладки.
                $order->update_meta_data( '_bsi_last_status', wp_json_encode( $bs_record, JSON_UNESCAPED_UNICODE ) );
                $order->update_meta_data( '_bsi_last_sync', current_time( 'mysql' ) );
                $order->save();

                // Применяем изменения статуса (после save meta).
                if ( $annullato && ! $order->has_status( array( 'cancelled', 'refunded' ) ) ) {
                        $order->update_status( 'cancelled', __( 'BeeStore: заказ отменён на стороне BeeStore.', 'beestore-integration' ) );
                } elseif ( $evasa && ! $order->has_status( array( 'completed', 'refunded', 'cancelled' ) ) ) {
                        $order->update_status( 'completed', __( 'BeeStore: заказ исполнен (Evasa=1).', 'beestore-integration' ) );
                }

                if ( ! empty( $changes ) ) {
                        $order->add_order_note( sprintf(
                                /* translators: %s — список изменений. */
                                __( 'BeeStore sync: %s', 'beestore-integration' ),
                                implode( ', ', $changes )
                        ) );
                }

                BSI_Logger::instance()->info( 'status_sync', 'Заказ обновлён', array(
                        'order_id' => $order_id,
                        'changes'  => $changes,
                ) );

                return true;
        }
}
