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
         * Работает через системный cron → wp-cron.php → bsi_cron_background_batch.
         */
        public function process_background_batch() {
                $state = BSI_Importer::instance()->get_import_state();

                // Если импорт не running — не делаем ничего.
                if ( 'running' !== $state['status'] ) {
                        return;
                }

                // Если last_update свежее (< 10 сек назад) — значит AJAX-вкладка
                // ещё активно обрабатывает. Не вмешиваемся.
                // ВАЖНО: используем current_time('timestamp') вместо time() —
                // потому что last_update хранится в WordPress времени (с учётом timezone).
                $last_update_ts = strtotime( $state['last_update'] );
                $now_ts = current_time( 'timestamp' );
                if ( $last_update_ts > 0 && ( $now_ts - $last_update_ts ) < 10 ) {
                        // Вкладка активна — перепланируем и выходим.
                        wp_schedule_single_event( time() + 30, 'bsi_cron_background_batch' );
                        return;
                }

                // Вкладка закрылась — подхватываем импорт.
                BSI_Logger::instance()->info( 'cron', 'Фоновый батч: подхватываем импорт', array(
                        'last_update' => $state['last_update'],
                        'processed'   => $state['processed_rows'],
                        'total'       => $state['total_rows'],
                ) );

                // Авторизуемся как админ.
                if ( ! function_exists( 'get_current_user_id' ) || ! get_current_user_id() ) {
                        $admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
                        if ( ! empty( $admins ) ) {
                                wp_set_current_user( $admins[0]->ID );
                        }
                }

                // Устанавливаем DOING_AJAX для wp_send_json.
                if ( ! defined( 'DOING_AJAX' ) ) {
                        define( 'DOING_AJAX', true );
                }

                // Перехват wp_die — чтобы wp_send_json не убивал процесс.
                remove_all_filters( 'wp_die_handler' );
                add_filter( 'wp_die_handler', function() {
                        return function( $m = '', $t = '', $a = array() ) {
                                throw new Exception( 'batch_done' );
                        };
                }, 999 );

                // Снимаем import_lock если он от старого процесса.
                $lock_pid = (int) get_transient( 'bsi_import_lock_pid' );
                $current_pid = function_exists( 'getmypid' ) ? getmypid() : 0;
                if ( $lock_pid && $lock_pid !== $current_pid ) {
                        delete_transient( 'bsi_import_lock' );
                        delete_transient( 'bsi_import_lock_pid' );
                }

                // Обрабатываем КАК МОЖНО БОЛЬШЕ батчей за один запуск cron.
                // Системный cron каждые 5 минут → каждый запуск ~50 секунд.
                $max_seconds = 25; // 25 сек (хостинг убивает через 30).
                $start_time = microtime( true );
                $batches_done = 0;

                while ( true ) {
                        // Проверяем лимит времени.
                        $elapsed = microtime( true ) - $start_time;
                        if ( $elapsed > $max_seconds ) {
                                break;
                        }

                        // Проверяем статус.
                        $state = BSI_Importer::instance()->get_import_state();
                        if ( 'running' !== $state['status'] ) {
                                BSI_Logger::instance()->info( 'cron', 'Фоновый батч: импорт завершён', array(
                                        'status'    => $state['status'],
                                        'processed' => $state['processed_rows'],
                                        'total'     => $state['total_rows'],
                                ) );
                                return; // Не планируем следующий.
                        }

                        // Обрабатываем один батч.
                        ob_start();
                        try {
                                BSI_Importer::instance()->process_batch_core();
                        } catch ( Exception $e ) {}
                        ob_end_clean();
                        // Снимаем lock — чтобы AJAX мог подхватить если вкладку открыли.
                        delete_transient( 'bsi_import_lock' );
                        delete_transient( 'bsi_import_lock_pid' );
                        $batches_done++;
                }

                // Планируем следующий запуск — через 10 сек.
                wp_schedule_single_event( time() + 10, 'bsi_cron_background_batch' );

                BSI_Logger::instance()->info( 'cron', 'Фоновый батч: завершён, запланирован следующий', array(
                        'batches_done' => $batches_done,
                        'elapsed'      => round( microtime( true ) - $start_time, 1 ),
                        'processed'    => BSI_Importer::instance()->get_import_state()['processed_rows'],
                        'total'        => BSI_Importer::instance()->get_import_state()['total_rows'],
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
         * Вызвать обработку батча напрямую — без AJAX, без nonce, без wp_die.
         */
        private function call_process_batch_silent() {
                // Устанавливаем флаг AJAX чтобы wp_send_json работал (но мы перехватим die).
                if ( ! defined( 'DOING_AJAX' ) ) {
                        define( 'DOING_AJAX', true );
                }

                // Подменяем wp_die_handler ОДИН раз — до вызова.
                // Используем фильтр с высоким приоритетом.
                remove_all_filters( 'wp_die_handler' );
                add_filter( 'wp_die_handler', function() {
                        return function( $message = '', $title = '', $args = array() ) {
                                // Не делаем die() — просто возвращаем управление.
                                throw new Exception( 'batch_complete' );
                        };
                }, 999 );

                ob_start();
                try {
                        // Вызываем process_batch_core напрямую — без check_ajax_referer.
                        BSI_Importer::instance()->process_batch_core();
                } catch ( Exception $e ) {
                        // Нормальное завершение через wp_die от wp_send_json.
                }
                $output = ob_get_clean();

                // Парсим JSON ответ для логирования.
                if ( $output ) {
                        $json = json_decode( $output, true );
                        if ( is_array( $json ) && isset( $json['data'] ) ) {
                                BSI_Logger::instance()->info( 'cron', 'Фоновый батч обработан', array(
                                        'message'   => isset( $json['data']['message'] ) ? $json['data']['message'] : '',
                                        'processed' => isset( $json['data']['state']['processed_rows'] ) ? $json['data']['state']['processed_rows'] : 0,
                                        'total'     => isset( $json['data']['state']['total_rows'] ) ? $json['data']['state']['total_rows'] : 0,
                                ) );
                        }
                }
        }

        public function cleanup_logs() {
                BSI_Logger::instance()->cleanup_old_logs( 30 );
        }
}
