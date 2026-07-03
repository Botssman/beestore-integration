<?php
/**
 * SOAP-клиент BeeStore.
 *
 * Обёртка над нативным PHP SoapClient для вызова методов BeeStore:
 *  - fDisponibilita
 *  - fDisponibilitaModello
 *  - fInserimentoPrenotazione
 *  - fStatoPrenotazioni
 *  - fModificaPrenotazione
 *  - fInserimentoDocumento
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSI_Client {

	private static $instance = null;
	private $soap_client  = null;
	private $wsdl_url     = '';
	private $user         = '';
	private $pass         = '';
	private $settings     = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = get_option( 'bsi_settings', array() );
		$this->wsdl_url = isset( $this->settings['wsdl_url'] ) ? $this->settings['wsdl_url'] : '';
		$this->user     = isset( $this->settings['soap_user'] ) ? $this->settings['soap_user'] : '';
		$this->pass     = isset( $this->settings['soap_pass'] ) ? $this->settings['soap_pass'] : '';
	}

	/**
	 * Ленивая инициализация SoapClient.
	 */
	private function get_client() {
		if ( null !== $this->soap_client ) {
			return $this->soap_client;
		}
		if ( empty( $this->wsdl_url ) ) {
			return new WP_Error( 'bsi_no_wsdl', __( 'Не задан WSDL URL в настройках BeeStore Integration.', 'beestore-integration' ) );
		}
		if ( ! class_exists( 'SoapClient' ) ) {
			return new WP_Error( 'bsi_no_soap', __( 'Расширение PHP SOAP не установлено.', 'beestore-integration' ) );
		}

		try {
			// Кэш WSDL отключаем для dev-окружения, на проде можно включить.
			$options = array(
				'wsdl_cache_enabled' => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 0 : 1,
				'exceptions'         => true,
				'trace'              => true,
				'connection_timeout' => 30,
				'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
			);

			$this->soap_client = new SoapClient( $this->wsdl_url, $options );
			return $this->soap_client;
		} catch ( SoapFault $e ) {
			BSI_Logger::instance()->error( 'soap', 'Не удалось инициализировать SoapClient', array( 'message' => $e->getMessage() ) );
			return new WP_Error( 'bsi_soap_init_failed', $e->getMessage() );
		}
	}

	/**
	 * Получить актуальный остаток по товару.
	 *
	 * @param string $codice_articolo CodArticolo (Model/Color/Size).
	 * @param string $barcode         BarCode (если задан — приоритетнее).
	 * @return float|WP_Error
	 */
	public function get_availability( $codice_articolo, $barcode = '' ) {
		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		try {
			$result = $client->fDisponibilita(
				$codice_articolo,
				$barcode,
				$this->settings['igu_negozio'],
				$this->user,
				$this->pass
			);
			BSI_Logger::instance()->debug( 'soap', 'fDisponibilita OK', array(
				'cod'   => $codice_articolo,
				'result' => $result,
			) );
			return (float) $result;
		} catch ( SoapFault $e ) {
			BSI_Logger::instance()->error( 'soap', 'fDisponibilita error', array(
				'cod'     => $codice_articolo,
				'barcode' => $barcode,
				'fault'   => $e->getMessage(),
				'code'    => $e->faultcode,
			) );
			return new WP_Error( 'bsi_soap_disponibilita', $e->getMessage() );
		}
	}

	/**
	 * Получить остаток по всем цветам/размерам модели.
	 *
	 * @param string $igu_articolo   IGUArticolo (главный ID модели).
	 * @param string $codice_articolo Опционально.
	 * @param string $barcode         Опционально.
	 * @return array|WP_Error Массив [ [CodArticolo => ..., Disponibilita => ...], ... ].
	 */
	public function get_availability_by_model( $igu_articolo, $codice_articolo = '', $barcode = '' ) {
		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}
		try {
			$result = $client->fDisponibilitaModello(
				$igu_articolo,
				$codice_articolo,
				$barcode,
				$this->settings['igu_negozio'],
				$this->user,
				$this->pass
			);
			// Приводим к массиву записей.
			$records = array();
			if ( is_array( $result ) ) {
				$records = $result;
			} elseif ( is_object( $result ) ) {
				$records = array( $result );
			}
			return $records;
		} catch ( SoapFault $e ) {
			BSI_Logger::instance()->error( 'soap', 'fDisponibilitaModello error', array(
				'igu'   => $igu_articolo,
				'fault' => $e->getMessage(),
			) );
			return new WP_Error( 'bsi_soap_disponibilita_modello', $e->getMessage() );
		}
	}

	/**
	 * Вставить заказ клиента (Prenotazione) в BeeStore.
	 *
	 * @param array $testata Ассоциативный массив с полями testataPrenotazione.
	 * @param array $righe   Массив массивов righePrenotazione.
	 * @return string|WP_Error  IGUPrenotazione при успехе.
	 */
	public function insert_prenotazione( $testata, $righe ) {
		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		// Преобразуем в stdClass (SoapClient требует объекты, не массивы).
		$testata_obj = (object) $testata;
		$righe_objs  = array();
		foreach ( $righe as $riga ) {
			$righe_objs[] = (object) $riga;
		}

		// Обёртка-структура (как в inserimentoPrenotazione.php).
		$structure = (object) array(
			'testata' => $testata_obj,
			'righe'   => $righe_objs,
		);

		try {
			$result = $client->fInserimentoPrenotazione( $structure, $this->user, $this->pass );

			BSI_Logger::instance()->info( 'soap', 'fInserimentoPrenotazione OK', array(
				'num_prenotazione' => isset( $testata['numPrenotazioneChr'] ) ? $testata['numPrenotazioneChr'] : '',
				'igu_prenotazione' => $result,
			) );
			return (string) $result;
		} catch ( SoapFault $e ) {
			BSI_Logger::instance()->error( 'soap', 'fInserimentoPrenotazione FAILED', array(
				'num_prenotazione' => isset( $testata['numPrenotazioneChr'] ) ? $testata['numPrenotazioneChr'] : '',
				'fault'            => $e->getMessage(),
				'code'             => $e->faultcode,
				'request'          => $this->format_last_request( $client ),
			) );
			return new WP_Error( 'bsi_soap_insert_prenotazione', $e->getMessage() );
		}
	}

	/**
	 * Получить статусы заказов.
	 *
	 * @param array $params Параметры фильтра (IGUCliente, Tessera, Email, IGUPrenotazione, NumPrenotazione, NumPrenotazioneChr, DTPrenotazione).
	 * @return array|WP_Error
	 */
	public function get_status_prenotazioni( $params ) {
		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$defaults = array(
			'IGUCliente'        => '',
			'Tessera'           => '',
			'Email'             => '',
			'IGUPrenotazione'   => '',
			'NumPrenotazione'   => 0,
			'NumPrenotazioneChr' => '',
			'DTPrenotazione'    => '',
		);
		$params = wp_parse_args( $params, $defaults );

		try {
			$result = $client->fStatoPrenotazioni(
				$params['IGUCliente'],
				$params['Tessera'],
				$params['Email'],
				$params['IGUPrenotazione'],
				$params['NumPrenotazione'],
				$params['NumPrenotazioneChr'],
				$params['DTPrenotazione'],
				$this->user,
				$this->pass
			);

			// Нормализуем в массив.
			$records = array();
			if ( is_array( $result ) ) {
				$records = $result;
			} elseif ( is_object( $result ) ) {
				$records = array( $result );
			}

			BSI_Logger::instance()->info( 'soap', 'fStatoPrenotazioni OK', array(
				'count' => count( $records ),
			) );
			return $records;
		} catch ( SoapFault $e ) {
			BSI_Logger::instance()->error( 'soap', 'fStatoPrenotazioni FAILED', array(
				'params' => $params,
				'fault'  => $e->getMessage(),
			) );
			return new WP_Error( 'bsi_soap_status_prenotazioni', $e->getMessage() );
		}
	}

	/**
	 * Изменить заказ: добавить/обновить депозит или отменить заказ.
	 *
	 * @param string $igu_prenotazione
	 * @param array  $params  Поля: DTAccontoWeb, CodIVAAcconto, AccontoVersato, IDTipoIncasso, Annullato, IGUCarta, AccontoValuta, CambioPag, CodiceEsternoValuta, DSPagamento, ImportoFinanziato, CodCausaleFinanziaria, DaFatturare.
	 * @return string|WP_Error  IGUPrenotazione при успехе.
	 */
	public function modify_prenotazione( $igu_prenotazione, $params ) {
		$client = $this->get_client();
		if ( is_wp_error( $client ) ) {
			return $client;
		}

		$defaults = array(
			'DTAccontoWeb'           => current_time( 'Ymd' ),
			'CodIVAAcconto'          => '',
			'AccontoVersato'         => 0,
			'IDTipoIncasso'          => 0,
			'Annullato'              => 0,
			'IGUCarta'               => '',
			'AccontoValuta'          => 0,
			'CambioPag'              => 0,
			'CodiceEsternoValuta'    => '',
			'DSPagamento'            => '',
			'ImportoFinanziato'      => 0,
			'CodCausaleFinanziaria'  => '',
			'DaFatturare'            => false,
		);
		$params = wp_parse_args( $params, $defaults );

		try {
			$result = $client->fModificaPrenotazione(
				$igu_prenotazione,
				$params['DTAccontoWeb'],
				$params['CodIVAAcconto'],
				$params['AccontoVersato'],
				$params['IDTipoIncasso'],
				$params['Annullato'],
				$this->user,
				$this->pass
			);
			BSI_Logger::instance()->info( 'soap', 'fModificaPrenotazione OK', array(
				'igu_prenotazione' => $igu_prenotazione,
				'annullato'        => $params['Annullato'],
			) );
			return (string) $result;
		} catch ( SoapFault $e ) {
			BSI_Logger::instance()->error( 'soap', 'fModificaPrenotazione FAILED', array(
				'igu'   => $igu_prenotazione,
				'fault' => $e->getMessage(),
			) );
			return new WP_Error( 'bsi_soap_modify_prenotazione', $e->getMessage() );
		}
	}

	/**
	 * Возвращает последний SOAP-запрос для отладки.
	 */
	private function format_last_request( $client ) {
		if ( ! $client instanceof SoapClient ) {
			return '';
		}
		try {
			return $client->__getLastRequest();
		} catch ( Exception $e ) {
			return '';
		}
	}
}
