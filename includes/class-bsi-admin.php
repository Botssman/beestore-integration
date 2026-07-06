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
                        __( 'Каталог с FTP', 'beestore-integration' ),
                        __( 'Каталог с FTP', 'beestore-integration' ),
                        $cap,
                        'bsi-catalog-browser',
                        array( $this, 'render_catalog_browser_page' )
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
                // Сначала получаем ВСЕ файлы в каталоге (для отладки).
                $settings = get_option( 'bsi_settings', array() );
                $path     = isset( $settings['ftp_path'] ) ? $settings['ftp_path'] : '/';

                // Подключаемся и получаем полный список.
                $all_files = array();
                if ( function_exists( 'ftp_connect' ) && ! empty( $settings['ftp_host'] ) && ! empty( $settings['ftp_user'] ) ) {
                        $conn = @ftp_connect( $settings['ftp_host'], isset( $settings['ftp_port'] ) ? (int) $settings['ftp_port'] : 21, 15 );
                        if ( $conn ) {
                                if ( @ftp_login( $conn, $settings['ftp_user'], isset( $settings['ftp_pass'] ) ? $settings['ftp_pass'] : '' ) ) {
                                        ftp_pasv( $conn, true );
                                        $all_files = @ftp_nlist( $conn, $path );
                                        if ( false === $all_files ) {
                                                $all_files = array();
                                        }
                                }
                                ftp_close( $conn );
                        }
                }

                // Теперь фильтруем по шаблону BeeStore.
                $files = BSI_FTP::instance()->list_remote_zips();
                if ( is_wp_error( $files ) ) {
                        return array(
                                'success'           => false,
                                'message'           => $files->get_error_message(),
                                'all_files'         => array_slice( array_map( function ( $f ) { return basename( ltrim( $f, './' ) ); }, $all_files ), 0, 20 ),
                                'ftp_path_setting'  => $path,
                        );
                }

                // Берём basename для отображения (FTP может вернуть полные пути).
                $display_files = array_map(
                        function ( $f ) {
                                return basename( ltrim( $f, './' ) );
                        },
                        $files
                );

                // Готовим список всех файлов для отладки.
                $all_display = array_map(
                        function ( $f ) {
                                return basename( ltrim( $f, './' ) );
                        },
                        $all_files
                );

                $message = sprintf(
                        /* translators: %d — количество файлов. */
                        __( 'Подключение успешно. Найдено ZIP-файлов BeeStore: %d', 'beestore-integration' ),
                        count( $files )
                );

                if ( 0 === count( $files ) && ! empty( $all_files ) ) {
                        $message .= ' — но в каталоге ' . $path . ' есть другие файлы (см. ниже). Проверьте, что файл называется COMPANY_*.zip или COMPANY_*.csv';
                } elseif ( 0 === count( $files ) && empty( $all_files ) ) {
                        $message .= ' — каталог ' . $path . ' пуст или недоступен. Попробуйте изменить "Каталог на FTP": /public_html/, /, /home/USER/domains/DOMAIN/public_html/';
                }

                return array(
                        'success'          => true,
                        'message'          => $message,
                        'files'            => array_slice( $display_files, 0, 10 ),
                        'all_files'        => array_slice( $all_display, 0, 20 ),
                        'ftp_path_setting' => $path,
                );
        }

        /* ---------------------------------------------------------------------
         * Страница «Каталог с FTP» — просмотр и скачивание файлов BeeStore.
         * --------------------------------------------------------------------- */
        public function render_catalog_browser_page() {
                // Обработка действий.
                $action = isset( $_GET['bsi_action'] ) ? sanitize_text_field( wp_unslash( $_GET['bsi_action'] ) ) : 'list';
                $file   = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';

                // Скачивание файла с FTP на сервер.
                if ( 'fetch' === $action && $file && check_admin_referer( 'bsi_fetch_file' ) ) {
                        $result = $this->fetch_file_from_ftp( $file );
                        $fetch_result = $result;
                } else {
                        $fetch_result = null;
                }

                // Удаление локального файла.
                if ( 'delete_local' === $action && $file && check_admin_referer( 'bsi_delete_local' ) ) {
                        $upload_dir = wp_upload_dir();
                        $local_file = trailingslashit( $upload_dir['basedir'] ) . 'beestore/manual-downloads/' . sanitize_file_name( $file );
                        if ( file_exists( $local_file ) ) {
                                unlink( $local_file );
                        }
                        $fetch_result = array(
                                'success' => true,
                                'message' => sprintf( __( 'Файл %s удалён с сервера.', 'beestore-integration' ), $file ),
                        );
                }

                // Отдача файла пользователю (скачать на компьютер).
                if ( 'download' === $action && $file && check_admin_referer( 'bsi_download_file' ) ) {
                        $this->download_file_to_browser( $file );
                        return;
                }

                // Получаем список файлов с FTP.
                $remote_files = BSI_FTP::instance()->list_remote_zips();

                // Парсим имена для отображения.
                $remote_parsed = array();
                if ( ! is_wp_error( $remote_files ) ) {
                        foreach ( $remote_files as $remote_path ) {
                                $name = basename( ltrim( $remote_path, './' ) );
                                if ( preg_match( '/^COMPANY_(\d+)_0000_([0-9\-]+)_([0-9\-]+)_(\d+)\.(zip|csv)$/i', $name, $m ) ) {
                                        $remote_parsed[] = array(
                                                'name'      => $name,
                                                'remote_path' => $remote_path,
                                                'date'      => $m[2],
                                                'time'      => $m[3],
                                                'sequence'  => (int) $m[4],
                                                'is_full'   => ( 1 === (int) $m[4] ),
                                        );
                                }
                        }
                        // Сортируем — новые сверху.
                        usort( $remote_parsed, function ( $a, $b ) {
                                return strcmp( $b['date'] . '_' . $b['time'], $a['date'] . '_' . $a['time'] );
                        } );
                }

                // Список уже скачанных файлов.
                $upload_dir = wp_upload_dir();
                $local_dir  = trailingslashit( $upload_dir['basedir'] ) . 'beestore/manual-downloads';
                $local_files = array();
                if ( is_dir( $local_dir ) ) {
                        $local_files = glob( $local_dir . '/COMPANY_*' );
                }

                include BSI_PLUGIN_DIR . 'templates/catalog-browser-page.php';
        }

        /**
         * Скачать файл с FTP на сервер (в папку manual-downloads).
         */
        private function fetch_file_from_ftp( $remote_path ) {
                $settings = get_option( 'bsi_settings', array() );
                if ( empty( $settings['ftp_host'] ) || empty( $settings['ftp_user'] ) ) {
                        return array( 'success' => false, 'message' => __( 'FTP не настроен.', 'beestore-integration' ) );
                }

                $upload_dir = wp_upload_dir();
                $local_dir  = trailingslashit( $upload_dir['basedir'] ) . 'beestore/manual-downloads';
                if ( ! file_exists( $local_dir ) ) {
                        wp_mkdir_p( $local_dir );
                }
                $local_file = trailingslashit( $local_dir ) . sanitize_file_name( basename( $remote_path ) );

                if ( ! function_exists( 'ftp_connect' ) ) {
                        return array( 'success' => false, 'message' => __( 'PHP ftp не установлен.', 'beestore-integration' ) );
                }

                $conn = @ftp_connect( $settings['ftp_host'], isset( $settings['ftp_port'] ) ? (int) $settings['ftp_port'] : 21, 30 );
                if ( ! $conn ) {
                        return array( 'success' => false, 'message' => __( 'Не удалось подключиться к FTP.', 'beestore-integration' ) );
                }

                $login = @ftp_login( $conn, $settings['ftp_user'], isset( $settings['ftp_pass'] ) ? $settings['ftp_pass'] : '' );
                if ( ! $login ) {
                        ftp_close( $conn );
                        return array( 'success' => false, 'message' => __( 'Неверный логин/пароль FTP.', 'beestore-integration' ) );
                }

                ftp_pasv( $conn, true );

                $success = @ftp_get( $conn, $local_file, $remote_path, FTP_BINARY );
                if ( ! $success ) {
                        // Fallback — путь относительно ftp_path.
                        $fallback = trailingslashit( $settings['ftp_path'] ) . basename( $remote_path );
                        $success = @ftp_get( $conn, $local_file, $fallback, FTP_BINARY );
                }

                ftp_close( $conn );

                if ( ! $success ) {
                        return array( 'success' => false, 'message' => __( 'Не удалось скачать файл с FTP.', 'beestore-integration' ) );
                }

                $size = size_format( filesize( $local_file ) );
                return array(
                        'success' => true,
                        'message' => sprintf(
                                /* translators: 1: имя файла, 2: размер */
                                __( 'Файл %1$s (%2$s) скачан на сервер.', 'beestore-integration' ),
                                basename( $remote_path ),
                                $size
                        ),
                );
        }

        /**
         * Отдать локальный файл пользователю для скачивания.
         */
        private function download_file_to_browser( $filename ) {
                $upload_dir = wp_upload_dir();
                $local_file = trailingslashit( $upload_dir['basedir'] ) . 'beestore/manual-downloads/' . sanitize_file_name( $filename );

                if ( ! file_exists( $local_file ) ) {
                        wp_die( esc_html__( 'Файл не найден на сервере.', 'beestore-integration' ) );
                }

                nocache_headers();
                header( 'Content-Description: File Transfer' );
                header( 'Content-Type: application/octet-stream' );
                header( 'Content-Disposition: attachment; filename="' . basename( $local_file ) . '"' );
                header( 'Content-Transfer-Encoding: binary' );
                header( 'Content-Length: ' . filesize( $local_file ) );
                readfile( $local_file );
                exit;
        }
}
