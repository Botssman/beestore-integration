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
                // #6 ФИКС: убран дубль add_action('bsi_cron_import_catalog') —
                // он уже зарегистрирован в BSI_Importer::__construct(). Раньше
                // каждый cron-тик запускал cron_import() ДВАЖДЫ: первый процесс
                // ставил lock, второй сразу выходил, но тратил ресурсы на инициализацию.
                // Теперь регистрация единая — в BSI_Importer.
                add_action( 'bsi_cron_status_sync', array( BSI_Status_Sync::instance(), 'cron_sync' ) );
                add_action( 'bsi_cron_process_queue', array( BSI_Order_Sync::instance(), 'process_queue' ) );

                // Раз в день чистим старые логи.
                add_action( 'wp_scheduled_delete', array( $this, 'cleanup_logs' ) );

                // #2 ФИКС: автоматически проверяем и регистрируем cron-задачи при каждом
                // запуске WP-Cron. Раньше reschedule_all_from_settings() вызывалась только
                // при сохранении настроек — если настройки не сохраняли, задачи не планируются.
                add_action( 'wp', array( $this, 'ensure_cron_scheduled' ) );
        }

        /**
         * Проверить что все cron-задачи зарегистрированы согласно настройкам.
         * Если нет — планируем. Вызывается при каждом запросе (через wp hook).
         */
        public function ensure_cron_scheduled() {
                // Проверяем только если это НЕ ajax запрос (чтобы не тормозить).
                if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
                        return;
                }

                $settings = get_option( 'bsi_settings', array() );

                // Карта: hook → настройка частоты.
                $tasks = array(
                        'bsi_cron_import_catalog' => isset( $settings['sync_frequency'] ) ? $settings['sync_frequency'] : 'hourly',
                        'bsi_cron_status_sync'    => isset( $settings['status_sync_frequency'] ) ? $settings['status_sync_frequency'] : 'hourly',
                        'bsi_cron_stock_sync'     => isset( $settings['stock_sync_frequency'] ) ? $settings['stock_sync_frequency'] : 'disabled',
                );

                foreach ( $tasks as $hook => $freq ) {
                        if ( 'disabled' === $freq || ! $freq ) {
                                // Отключено — снимаем если есть.
                                if ( wp_next_scheduled( $hook ) ) {
                                        wp_clear_scheduled_hook( $hook );
                                }
                                continue;
                        }

                        // Проверяем — запланирована ли задача.
                        if ( ! wp_next_scheduled( $hook ) ) {
                                // Не запланирована — планируем.
                                $schedules = wp_get_schedules();
                                $interval = isset( $schedules[ $freq ]['interval'] ) ? $schedules[ $freq ]['interval'] : 3600;
                                wp_schedule_event( time() + $interval, $freq, $hook );

                                BSI_Logger::instance()->info( 'cron', 'Авто-регистрация cron-задачи', array(
                                        'hook' => $hook,
                                        'freq' => $freq,
                                ) );
                        }
                }

                // Полный импорт — ежедневно.
                $full_freq = isset( $settings['full_sync_frequency'] ) ? $settings['full_sync_frequency'] : 'daily';
                if ( 'disabled' !== $full_freq ) {
                        if ( ! wp_next_scheduled( 'bsi_cron_full_import' ) ) {
                                $time = isset( $settings['full_import_time'] ) ? $settings['full_import_time'] : '02:00';
                                $ts = strtotime( 'today ' . $time );
                                if ( $ts && $ts < time() ) {
                                        $ts = strtotime( 'tomorrow ' . $time );
                                }
                                if ( ! $ts ) {
                                        $ts = strtotime( 'tomorrow 02:00' );
                                }
                                wp_schedule_event( $ts, 'daily', 'bsi_cron_full_import' );

                                BSI_Logger::instance()->info( 'cron', 'Авто-регистрация полного импорта', array(
                                        'time' => $time,
                                ) );
                        }
                }
        }

        public function cleanup_logs() {
                BSI_Logger::instance()->cleanup_old_logs( 30 );
        }
}
