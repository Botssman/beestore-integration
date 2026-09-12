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

                // Фоновая обработка импорта (независимо от вкладки браузера).
                // Запускается каждые 2 минуты, обрабатывает 1 батч, снимает сама себя
                // когда импорт завершён.
                add_action( 'bsi_cron_background_batch', array( $this, 'process_background_batch' ) );

                // Раз в день чистим старые логи.
                add_action( 'wp_scheduled_delete', array( $this, 'cleanup_logs' ) );
        }

        /**
         * Фоновый батч: обрабатывает батчи импорта напрямую, планирует следующий запуск.
         * Не зависит от вкладки браузера — работает через WP-Cron.
         */
        public function process_background_batch() {
                $state = BSI_Importer::instance()->get_import_state();

                // Если импорт не running — не делаем ничего.
                if ( 'running' !== $state['status'] ) {
                        return;
                }

                // Если last_update свежее (< 30 сек назад) — значит AJAX-вкладка
                // ещё активно обрабатывает. Не вмешиваемся.
                $last_update_ts = strtotime( $state['last_update'] );
                if ( $last_update_ts > 0 && ( time() - $last_update_ts ) < 30 ) {
                        wp_schedule_single_event( time() + 120, 'bsi_cron_background_batch' );
                        return;
                }

                // Вкладка закрылась — подхватываем импорт напрямую.
                BSI_Logger::instance()->info( 'cron', 'Фоновый батч: подхватываем импорт напрямую', array(
                        'last_update' => $state['last_update'],
                        'processed'   => $state['processed_rows'],
                        'total'       => $state['total_rows'],
                ) );

                // Авторизуемся как админ — нужно для wp_create_nonce и check_ajax_referer.
                if ( ! function_exists( 'get_current_user_id' ) || ! get_current_user_id() ) {
                        $admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
                        if ( ! empty( $admins ) ) {
                                wp_set_current_user( $admins[0]->ID );
                        }
                }

                // Обрабатываем до 10 батчей за запуск (каждый ~25 строк = ~250 строк).
                // Напрямую вызываем логику, без HTTP запросов.
                $max_batches = 10;
                $max_seconds = 50; // Лимит времени для cron (WP-Cron обычно 60 сек).
                $start_time = microtime( true );

                for ( $i = 0; $i < $max_batches; $i++ ) {
                        // Проверяем лимит времени.
                        if ( ( microtime( true ) - $start_time ) > $max_seconds ) {
                                BSI_Logger::instance()->info( 'cron', 'Фоновый батч: достигнут лимит времени', array(
                                        'batches_done' => $i,
                                        'elapsed'      => round( microtime( true ) - $start_time, 1 ),
                                ) );
                                break;
                        }

                        // Обрабатываем один батч напрямую.
                        $this->process_single_batch_direct();

                        // Проверяем статус.
                        $state = BSI_Importer::instance()->get_import_state();
                        if ( 'running' !== $state['status'] ) {
                                BSI_Logger::instance()->info( 'cron', 'Фоновый батч: импорт завершён', array(
                                        'status'    => $state['status'],
                                        'processed' => $state['processed_rows'],
                                        'total'     => $state['total_rows'],
                                ) );
                                return; // Не планируем следующий — импорт завершён.
                        }
                }

                // Планируем следующий фоновый батч.
                wp_schedule_single_event( time() + 60, 'bsi_cron_background_batch' );

                BSI_Logger::instance()->info( 'cron', 'Фоновый батч: запланирован следующий', array(
                        'batches_done' => $i,
                        'processed'    => $state['processed_rows'],
                        'total'        => $state['total_rows'],
                ) );
        }

        /**
         * Обработать один батч напрямую — без HTTP запроса.
         * Использует тот же код что и AJAX, но вызывает функцию напрямую.
         */
        private function process_single_batch_direct() {
                $importer = BSI_Importer::instance();
                $state = $importer->get_import_state();

                if ( 'running' !== $state['status'] ) {
                        return;
                }

                // Проверяем lock — если другой процесс уже обрабатывает, выходим.
                $lock = get_transient( 'bsi_import_lock' );
                if ( false !== $lock ) {
                        $lock_age = time() - (int) $lock;
                        $lock_pid = (int) get_transient( 'bsi_import_lock_pid' );
                        $current_pid = function_exists( 'getmypid' ) ? getmypid() : 0;

                        // Если lock от нашего PID — снимаем (мы в cron, не в AJAX).
                        if ( $lock_pid === $current_pid ) {
                                delete_transient( 'bsi_import_lock' );
                                delete_transient( 'bsi_import_lock_pid' );
                        } elseif ( $lock_age < 60 ) {
                                // Lock свежий от другого процесса — выходим.
                                BSI_Logger::instance()->debug( 'cron', 'Фоновый батч: lock занят, пропускаем', array(
                                        'lock_age' => $lock_age,
                                        'lock_pid' => $lock_pid,
                                ) );
                                return;
                        } else {
                                // Старый зависший lock — сбрасываем.
                                delete_transient( 'bsi_import_lock' );
                                delete_transient( 'bsi_import_lock_pid' );
                        }
                }

                // Устанавливаем свой lock.
                set_transient( 'bsi_import_lock', time(), 1800 );
                set_transient( 'bsi_import_lock_pid', $current_pid, 1800 );

                // Вызываем внутреннюю логику обработки батча.
                // process_batch_internal() использует wp_send_json_* которые вызывают wp_die().
                // Перехватываем через output buffering + custom die handler.
                $this->call_process_batch_silent();

                // Снимаем lock.
                delete_transient( 'bsi_import_lock' );
                delete_transient( 'bsi_import_lock_pid' );
        }

        /**
         * Вызвать ajax_import_process_batch без HTTP — перехватываем wp_die.
         */
        private function call_process_batch_silent() {
                // Устанавливаем флаг AJAX чтобы wp_send_json работал.
                if ( ! defined( 'DOING_AJAX' ) ) {
                        define( 'DOING_AJAX', true );
                }

                // Создаём nonce и кладём в $_POST — check_ajax_referer проверит его.
                $current_user_id = get_current_user_id();
                if ( ! $current_user_id ) {
                        $admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
                        if ( ! empty( $admins ) ) {
                                $current_user_id = $admins[0]->ID;
                                wp_set_current_user( $current_user_id );
                        }
                }
                $_POST['nonce'] = wp_create_nonce( 'bsi_admin_nonce' );
                $_REQUEST['nonce'] = $_POST['nonce'];

                // Подменяем wp_die_handler чтобы он не убивал процесс.
                add_filter( 'wp_die_handler', function() {
                        return function( $message = '', $title = '', $args = array() ) {
                                // Не делаем die() — просто возвращаем управление.
                                throw new Exception( 'batch_complete' );
                        };
                } );

                ob_start();
                try {
                        BSI_Importer::instance()->ajax_import_process_batch();
                } catch ( Exception $e ) {
                        // Нормальное завершение через wp_die от wp_send_json.
                }
                $output = ob_get_clean();

                // Парсим JSON ответ для логирования.
                if ( $output ) {
                        $json = json_decode( $output, true );
                        if ( is_array( $json ) && isset( $json['data'] ) ) {
                                BSI_Logger::instance()->debug( 'cron', 'Фоновый батч обработан', array(
                                        'message'   => isset( $json['data']['message'] ) ? $json['data']['message'] : '',
                                        'processed' => isset( $json['data']['state']['processed_rows'] ) ? $json['data']['state']['processed_rows'] : 0,
                                ) );
                        }
                }
        }

        public function cleanup_logs() {
                BSI_Logger::instance()->cleanup_old_logs( 30 );
        }
}
