<?php
/**
 * Админ-страницы плагина: Import, Logs, Diagnostics.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class BSI_Admin {

        private static $instance = null;

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {
                add_action( 'admin_menu', array( $this, 'register_submenus' ) );
                add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        }

        public function register_submenus() {
                $cap = 'manage_woocommerce';

                add_submenu_page(
                        'beestore-integration',
                        __( 'Импорт каталога', 'beestore-integration' ),
                        __( 'Импорт каталога', 'beestore-integration' ),
                        $cap,
                        'bsi-import',
                        array( $this, 'render_import_page' )
                );

                add_submenu_page(
                        'beestore-integration',
                        __( 'Логи', 'beestore-integration' ),
                        __( 'Логи', 'beestore-integration' ),
                        $cap,
                        'bsi-logs',
                        array( $this, 'render_logs_page' )
                );

                add_submenu_page(
                        'beestore-integration',
                        __( 'Диагностика', 'beestore-integration' ),
                        __( 'Диагностика', 'beestore-integration' ),
                        $cap,
                        'bsi-diagnostics',
                        array( $this, 'render_diagnostics_page' )
                );
        }

        public function enqueue_admin_assets( $hook ) {
                if ( false === strpos( $hook, 'beestore' ) && false === strpos( $hook, 'bsi-' ) ) {
                        return;
                }
                wp_enqueue_style(
                        'bsi-admin',
                        BSI_PLUGIN_URL . 'assets/css/admin.css',
                        array(),
                        BSI_VERSION
                );
                wp_enqueue_script(
                        'bsi-admin',
                        BSI_PLUGIN_URL . 'assets/js/admin.js',
                        array( 'jquery' ),
                        BSI_VERSION,
                        true
                );
                wp_localize_script( 'bsi-admin', 'bsiAdmin', array(
                        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                        'nonce'   => wp_create_nonce( 'bsi_admin_nonce' ),
                        'i18n'    => array(
                                'importing'   => __( 'Идёт импорт…', 'beestore-integration' ),
                                'importDone'  => __( 'Импорт завершён.', 'beestore-integration' ),
                                'importError' => __( 'Ошибка импорта.', 'beestore-integration' ),
                                'confirm'     => __( 'Вы уверены?', 'beestore-integration' ),
                        ),
                ) );
        }

        public function render_import_page() {
                $last_report    = get_option( 'bsi_last_import_report', array() );
                $last_zip       = get_option( 'bsi_last_import_zip', '' );
                $last_started   = get_option( 'bsi_last_import_started', '' );
                $last_finished  = get_option( 'bsi_last_import_finished', '' );

                // Проверяем, доступен ли загрузочный каталог.
                $upload     = wp_upload_dir();
                $upload_dir = trailingslashit( $upload['basedir'] ) . 'beestore/downloads';
                $upload_url = trailingslashit( $upload['baseurl'] ) . 'beestore/downloads';

                include BSI_PLUGIN_DIR . 'templates/import-page.php';
        }

        public function render_logs_page() {
                // Обработка очистки.
                if ( isset( $_GET['action'] ) && 'clear_logs' === $_GET['action'] && check_admin_referer( 'bsi_clear_logs' ) ) {
                        BSI_Logger::instance()->truncate();
                        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Логи очищены.', 'beestore-integration' ) . '</p></div>';
                }

                $level  = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';
                $source = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';

                $logs = BSI_Logger::instance()->get_logs( array(
                        'level'    => $level,
                        'source'   => $source,
                        'per_page' => 200,
                ) );

                // Уникальные source для фильтра.
                global $wpdb;
                $table      = $wpdb->prefix . BSI_LOG_TABLE;
                $sources    = $wpdb->get_col( "SELECT DISTINCT source FROM {$table} ORDER BY source ASC" ); // phpcs:ignore

                include BSI_PLUGIN_DIR . 'templates/logs-page.php';
        }

        public function render_diagnostics_page() {
                // Тест SOAP.
                $soap_test = null;
                if ( isset( $_GET['run'] ) && 'soap' === $_GET['run'] && check_admin_referer( 'bsi_test_soap' ) ) {
                        $soap_test = $this->run_soap_test();
                }

                // Тест FTP.
                $ftp_test = null;
                if ( isset( $_GET['run'] ) && 'ftp' === $_GET['run'] && check_admin_referer( 'bsi_test_ftp' ) ) {
                        $ftp_test = $this->run_ftp_test();
                }

                // PHP info.
                $php_info = array(
                        'php_version'    => PHP_VERSION,
                        'soap_enabled'   => class_exists( 'SoapClient' ),
                        'zip_enabled'    => class_exists( 'ZipArchive' ),
                        'ftp_enabled'    => function_exists( 'ftp_connect' ),
                        'ssh2_enabled'   => function_exists( 'ssh2_connect' ),
                        'curl_enabled'   => function_exists( 'curl_version' ),
                        'memory_limit'   => ini_get( 'memory_limit' ),
                        'max_exec_time'  => ini_get( 'max_execution_time' ),
                );

                // WP-Cron статусы.
                $crons = array(
                        'import'   => wp_next_scheduled( 'bsi_cron_import_catalog' ),
                        'status'   => wp_next_scheduled( 'bsi_cron_status_sync' ),
                        'queue'    => wp_next_scheduled( 'bsi_cron_process_queue' ),
                );

                include BSI_PLUGIN_DIR . 'templates/diagnostics-page.php';
        }

        private function run_soap_test() {
                $client = BSI_Client::instance();
                $result = $client->get_availability( 'TEST', '' );

                if ( is_wp_error( $result ) ) {
                        return array(
                                'success' => false,
                                'message' => $result->get_error_message(),
                        );
                }
                return array(
                        'success' => true,
                        'message' => __( 'SOAP-вызов выполнен успешно (ответ получен от BeeStore).', 'beestore-integration' ),
                        'data'    => $result,
                );
        }

        private function run_ftp_test() {
                $files = BSI_FTP::instance()->list_remote_zips();
                if ( is_wp_error( $files ) ) {
                        return array(
                                'success' => false,
                                'message' => $files->get_error_message(),
                        );
                }
                // Берём basename для отображения (FTP может вернуть полные пути).
                $display_files = array_map(
                        function ( $f ) {
                                return basename( ltrim( $f, './' ) );
                        },
                        $files
                );
                return array(
                        'success' => true,
                        'message' => sprintf(
                                /* translators: %d — количество файлов. */
                                __( 'Подключение успешно. Найдено ZIP-файлов BeeStore: %d', 'beestore-integration' ),
                                count( $files )
                        ),
                        'files'   => array_slice( $display_files, 0, 10 ),
                );
        }
}
