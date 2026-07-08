<?php
/**
 * Installer: активация/деактивация плагина, создание таблиц, расписаний.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class BSI_Installer {

        /**
         * Активация плагина.
         */
        public static function activate() {
                self::create_tables();
                self::create_upload_dir();
                self::register_attributes();
                self::schedule_cron_events();

                // Дефолтные опции (только если ещё не заданы).
                if ( false === get_option( 'bsi_settings', false ) ) {
                        $defaults = array(
                                'ftp_host'           => '',
                                'ftp_port'           => 21,
                                'ftp_user'           => '',
                                'ftp_pass'           => '',
                                'ftp_path'           => '/',
                                'ftp_use_sftp'       => '0',
                                'wsdl_url'           => 'http://www.sirio-is.it:8180/XXXXX/soapBeestore.wsdl',
                                'soap_user'          => '',
                                'soap_pass'          => '',
                                'igu_negozio'        => '',
                                'igu_cliente'        => '',
                                'igu_magazzino_riga' => '',
                                'cod_iva_default'    => '22',
                                'default_tax_rate'   => 22,
                                'igu_valuta'         => '15\\1\\1\\1',
                                'cod_dest_sdi'       => '',
                                'id_tipo_incasso_default' => 3,
                                'enable_order_sync'  => '1',
                                'enable_status_sync' => '1',
                                'enable_realtime_stock' => '0',
                                'sync_frequency'     => 'hourly',
                                'status_sync_frequency' => 'hourly',
                                'import_batch_size'  => 200,
                                'download_images'    => '1',
                                'delete_out_of_stock' => '0', // Если 1 — снимать с публикации товары, отсутствующие в выгрузке.
                                'mapping_payment'    => array(), // WC gateway_id => IDTipoIncasso.
                                // Конвертация цен.
                                'enable_price_conversion' => '0',
                                'currency_rate'           => 1,    // Курс валюты (например, 100 = 100 RUB за 1 EUR).
                                'currency_rate_mode'      => 'manual', // manual | auto
                                'currency_rate_auto_source' => 'auto', // auto | cbrf | ecb | er_api
                                'currency_rate_last_source' => '',   // Заполняется при авто-обновлении.
                                'currency_rate_last_update' => '',   // Заполняется при авто-обновлении.
                                'markup_coefficient'      => 1,    // Коэффициент надбавки (1.3 = наценка 30%).
                                'fixed_markup'            => 0,    // Фиксированная надбавка в валюте магазина.
                                'supplier_currency'       => 'EUR', // Валюта поставщика (BeeStore).
                                'shop_currency'           => 'RUB', // Валюта магазина (WooCommerce).
                                'round_prices'            => '0',  // Округлять цены до целых.
                        );
                        update_option( 'bsi_settings', $defaults );
                }

                flush_rewrite_rules();
        }

        /**
         * Деактивация плагина.
         */
        public static function deactivate() {
                self::clear_cron_events();
        }

        /**
         * Регистрация глобальных атрибутов WooCommerce (pa_colore, pa_taglia).
         *
         * Без этого вариации не привязываются к значениям атрибутов.
         */
        public static function register_attributes() {
                if ( ! function_exists( 'wc_create_attribute' ) ) {
                        return;
                }

                $attributes_to_create = array(
                        'colore'     => __( 'Colore', 'beestore-integration' ),
                        'taglia'     => __( 'Taglia', 'beestore-integration' ),
                        'brand'      => __( 'Бренд', 'beestore-integration' ),
                        'stagione'   => __( 'Сезон', 'beestore-integration' ),
                        'country'    => __( 'Страна', 'beestore-integration' ),
                        'sesso'      => __( 'Пол', 'beestore-integration' ),
                        'collezione' => __( 'Коллекция', 'beestore-integration' ),
                );

                // Получаем существующие атрибуты.
                $existing_attributes = function_exists( 'wc_get_attribute_taxonomies' ) ? wc_get_attribute_taxonomies() : array();
                $existing_slugs = array();
                foreach ( $existing_attributes as $attr ) {
                        $existing_slugs[] = $attr->attribute_name;
                }

                foreach ( $attributes_to_create as $slug => $name ) {
                        if ( in_array( $slug, $existing_slugs, true ) ) {
                                continue;
                        }
                        $args = array(
                                'name'         => $name,
                                'slug'         => $slug,
                                'type'         => 'select',
                                'order_by'     => 'menu_order',
                                'has_archives' => false,
                        );
                        wc_create_attribute( $args );
                }

                // Важно: сбрасываем кэш атрибутов.
                if ( function_exists( 'delete_transient' ) ) {
                        delete_transient( 'wc_attribute_taxonomies' );
                }
        }

        /**
         * Создание пользовательских таблиц.
         */
        private static function create_tables() {
                global $wpdb;
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $charset_collate = $wpdb->get_charset_collate();

                $table_log   = $wpdb->prefix . BSI_LOG_TABLE;
                $table_queue = $wpdb->prefix . BSI_QUEUE_TABLE;

                $sql_log = "CREATE TABLE {$table_log} (
                        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                        created_at DATETIME NOT NULL,
                        level VARCHAR(20) NOT NULL DEFAULT 'info',
                        source VARCHAR(50) NOT NULL DEFAULT 'system',
                        message TEXT NOT NULL,
                        context LONGTEXT NULL,
                        PRIMARY KEY  (id),
                        KEY created_at (created_at),
                        KEY level (level),
                        KEY source (source)
                ) {$charset_collate};";

                $sql_queue = "CREATE TABLE {$table_queue} (
                        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                        created_at DATETIME NOT NULL,
                        type VARCHAR(40) NOT NULL, -- 'order_sync', 'status_sync', 'image_download'
                        object_id BIGINT(20) UNSIGNED NOT NULL, -- order_id, product_id и т.д.
                        payload LONGTEXT NULL,
                        status VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending, done, error
                        attempts INT(2) NOT NULL DEFAULT 0,
                        last_error TEXT NULL,
                        processed_at DATETIME NULL,
                        PRIMARY KEY  (id),
                        KEY type_status (type, status),
                        KEY object_id (object_id)
                ) {$charset_collate};";

                dbDelta( $sql_log );
                dbDelta( $sql_queue );
        }

        /**
         * Создание каталога для загрузок (ZIP, CSV, распакованные данные).
         */
        private static function create_upload_dir() {
                $upload_dir = wp_upload_dir();
                $base       = trailingslashit( $upload_dir['basedir'] ) . 'beestore';

                $dirs = array(
                        $base,
                        $base . '/downloads',
                        $base . '/extracted',
                        $base . '/processed',
                        $base . '/images',
                        $base . '/logs',
                );

                foreach ( $dirs as $dir ) {
                        if ( ! file_exists( $dir ) ) {
                                wp_mkdir_p( $dir );
                        }
                        // Защита от прямого доступа.
                        $htaccess = $dir . '/.htaccess';
                        if ( ! file_exists( $htaccess ) && basename( $dir ) !== 'images' ) {
                                @file_put_contents( $htaccess, "Deny from all\n" ); // phpcs:ignore
                        }
                }

                // index.php заглушки.
                foreach ( $dirs as $dir ) {
                        $index = $dir . '/index.php';
                        if ( ! file_exists( $index ) ) {
                                @file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore
                        }
                }
        }

        /**
         * Регистрация расписаний.
         */
        private static function schedule_cron_events() {
                if ( ! wp_next_scheduled( 'bsi_cron_import_catalog' ) ) {
                        wp_schedule_event( time() + 300, 'hourly', 'bsi_cron_import_catalog' );
                }
                if ( ! wp_next_scheduled( 'bsi_cron_status_sync' ) ) {
                        wp_schedule_event( time() + 600, 'hourly', 'bsi_cron_status_sync' );
                }
                if ( ! wp_next_scheduled( 'bsi_cron_process_queue' ) ) {
                        wp_schedule_event( time() + 120, 'every5min', 'bsi_cron_process_queue' );
                }
                // Ежедневное обновление курса валют (в 06:00).
                if ( ! wp_next_scheduled( 'bsi_cron_refresh_rate' ) ) {
                        wp_schedule_event( strtotime( 'tomorrow 06:00' ), 'daily', 'bsi_cron_refresh_rate' );
                }
        }

        /**
         * Очистка расписаний.
         */
        private static function clear_cron_events() {
                wp_clear_scheduled_hook( 'bsi_cron_import_catalog' );
                wp_clear_scheduled_hook( 'bsi_cron_status_sync' );
                wp_clear_scheduled_hook( 'bsi_cron_process_queue' );
                wp_clear_scheduled_hook( 'bsi_cron_refresh_rate' );
        }
}
