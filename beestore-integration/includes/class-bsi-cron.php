<?php
/**
 * Класс-инициализатор cron-задач.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class BSI_Cron {

        private static $instance = null;

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {
                add_action( 'bsi_cron_import_catalog', array( BSI_Importer::instance(), 'cron_import' ) );
                add_action( 'bsi_cron_status_sync', array( BSI_Status_Sync::instance(), 'cron_sync' ) );
                add_action( 'bsi_cron_process_queue', array( BSI_Order_Sync::instance(), 'process_queue' ) );
                add_action( 'bsi_cron_refresh_rate', array( BSI_Currency::instance(), 'cron_refresh_rate' ) );

                // Раз в день чистим старые логи.
                add_action( 'wp_scheduled_delete', array( $this, 'cleanup_logs' ) );
        }

        public function cleanup_logs() {
                BSI_Logger::instance()->cleanup_old_logs( 30 );
        }
}
