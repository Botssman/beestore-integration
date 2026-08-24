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
         * Фоновый батч: обрабатывает 1 батч импорта, планирует следующий запуск.
         * Не зависит от вкладки браузера — работает через WP-Cron.
         */
        public function process_background_batch() {
                $state = BSI_Importer::instance()->get_import_state();

                // Если импорт не running — не делаем ничего (cron сам снимется).
                if ( 'running' !== $state['status'] ) {
                        return;
                }

                // Если last_update свежее (< 30 сек назад) — значит AJAX-вкладка
                // ещё активно обрабатывает. Не вмешиваемся, чтобы не было гонки.
                // Cron подхватит только если вкладка закрылась (статус устарел).
                $last_update_ts = strtotime( $state['last_update'] );
                if ( $last_update_ts > 0 && ( time() - $last_update_ts ) < 30 ) {
                        // AJAX активен — перепланируем cron и выходим.
                        wp_schedule_single_event( time() + 120, 'bsi_cron_background_batch' );
                        return;
                }

                // Вкладка закрылась (last_update устарел) — обрабатываем батч через
                // внутренний HTTP-запрос к AJAX endpoint. Так мы используем всю
                // существующую логику без дублирования кода.
                BSI_Logger::instance()->info( 'cron', 'Фоновый батч: подхватываем импорт (вкладка закрыта)', array(
                        'last_update' => $state['last_update'],
                        'processed'   => $state['processed_rows'],
                        'total'       => $state['total_rows'],
                ) );

                // Обрабатываем до 5 батчей за запуск (каждый ~25 строк = ~125 строк).
                for ( $i = 0; $i < 5; $i++ ) {
                        $this->trigger_batch_via_http();
                        $state = BSI_Importer::instance()->get_import_state();
                        if ( 'running' !== $state['status'] ) {
                                break; // Импорт завершён/пауза.
                        }
                }

                // Планируем следующий фоновый батч.
                wp_schedule_single_event( time() + 120, 'bsi_cron_background_batch' );
        }

        /**
         * Триггер батча через внутренний HTTP-запрос к admin-ajax.php.
         *
         * Использует тот же endpoint что и JS из браузера, но без участия вкладки.
         * Создаёт nonce и cookie для авторизации.
         */
        private function trigger_batch_via_http() {
                // Создаём nonce от имени текущего пользователя (должен быть админ).
                $current_user_id = get_current_user_id();
                if ( ! $current_user_id ) {
                        // Если cron запущен без пользователя (wp-cron.php), берём первого админа.
                        $admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
                        if ( empty( $admins ) ) {
                                return;
                        }
                        $current_user_id = $admins[0]->ID;
                }

                wp_set_current_user( $current_user_id );
                $nonce = wp_create_nonce( 'bsi_admin_nonce' );

                $url = admin_url( 'admin-ajax.php' );
                $body = array(
                        'action' => 'bsi_import_process_batch',
                        'nonce'  => $nonce,
                );

                $response = wp_remote_post( $url, array(
                        'timeout'   => 30,
                        'body'      => $body,
                        'cookies'   => array(),
                        'sslverify' => false,
                ) );

                if ( is_wp_error( $response ) ) {
                        BSI_Logger::instance()->warning( 'cron', 'Фоновый батч: HTTP ошибка', array(
                                'err' => $response->get_error_message(),
                        ) );
                }
        }

        public function cleanup_logs() {
                BSI_Logger::instance()->cleanup_old_logs( 30 );
        }
}
