<?php
/**
 * Логирование работы плагина в БД и файл.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSI_Logger {

	const LEVEL_DEBUG = 'debug';
	const LEVEL_INFO  = 'info';
	const LEVEL_WARN  = 'warning';
	const LEVEL_ERROR = 'error';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Записать лог.
	 *
	 * @param string $level   debug|info|warning|error.
	 * @param string $source  Источник (importer, order_sync, ftp, soap, ...).
	 * @param string $message Сообщение.
	 * @param array  $context Контекст (будет сериализован).
	 */
	public function log( $level, $source, $message, $context = array() ) {
		global $wpdb;

		$settings = get_option( 'bsi_settings', array() );
		$log_level_threshold = isset( $settings['log_level'] ) ? $settings['log_level'] : self::LEVEL_INFO;

		if ( ! $this->should_log( $level, $log_level_threshold ) ) {
			return;
		}

		$table = $wpdb->prefix . BSI_LOG_TABLE;

		$wpdb->insert(
			$table,
			array(
				'created_at' => current_time( 'mysql' ),
				'level'      => $level,
				'source'     => $source,
				'message'    => $message,
				'context'    => $context ? wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) : null,
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);

		// Дублируем ERROR в error_log PHP.
		if ( self::LEVEL_ERROR === $level ) {
			error_log( '[BeeStore] ' . $source . ': ' . $message );
		}
	}

	public function debug( $source, $message, $context = array() ) {
		$this->log( self::LEVEL_DEBUG, $source, $message, $context );
	}

	public function info( $source, $message, $context = array() ) {
		$this->log( self::LEVEL_INFO, $source, $message, $context );
	}

	public function warn( $source, $message, $context = array() ) {
		$this->log( self::LEVEL_WARN, $source, $message, $context );
	}

	public function error( $source, $message, $context = array() ) {
		$this->log( self::LEVEL_ERROR, $source, $message, $context );
	}

	private function should_log( $level, $threshold ) {
		$levels = array(
			self::LEVEL_DEBUG => 0,
			self::LEVEL_INFO  => 1,
			self::LEVEL_WARN  => 2,
			self::LEVEL_ERROR => 3,
		);
		if ( ! isset( $levels[ $level ] ) || ! isset( $levels[ $threshold ] ) ) {
			return true;
		}
		return $levels[ $level ] >= $levels[ $threshold ];
	}

	/**
	 * Получить записи лога с фильтром.
	 */
	public function get_logs( $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . BSI_LOG_TABLE;

		$defaults = array(
			'level'    => '',
			'source'   => '',
			'per_page' => 100,
			'offset'   => 0,
			'from'     => '',
			'to'       => '',
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['level'] ) ) {
			$where[]  = 'level = %s';
			$params[] = $args['level'];
		}
		if ( ! empty( $args['source'] ) ) {
			$where[]  = 'source = %s';
			$params[] = $args['source'];
		}
		if ( ! empty( $args['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['from'];
		}
		if ( ! empty( $args['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $args['to'];
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC';
		if ( $args['per_page'] > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $args['per_page'];
			$params[] = (int) $args['offset'];
		}

		$sql = $wpdb->prepare( $sql, $params );
		return $wpdb->get_results( $sql ); // phpcs:ignore
	}

	/**
	 * Очистить логи старше N дней.
	 *
	 * @param int $days Количество дней.
	 */
	public function cleanup_old_logs( $days = 30 ) {
		global $wpdb;
		$table = $wpdb->prefix . BSI_LOG_TABLE;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)", $days ) ); // phpcs:ignore
	}

	/**
	 * Полная очистка лога.
	 */
	public function truncate() {
		global $wpdb;
		$table = $wpdb->prefix . BSI_LOG_TABLE;
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore
	}
}
