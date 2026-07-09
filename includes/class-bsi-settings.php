<?php
/**
 * Управление настройками плагина и админ-страница Settings.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class BSI_Settings {

        private static $instance = null;
        private $settings_key = 'bsi_settings';

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {
                add_action( 'admin_menu', array( $this, 'register_menu' ) );
                add_action( 'admin_init', array( $this, 'register_settings' ) );
                add_filter( 'cron_schedules', array( $this, 'add_cron_interval' ) );
        }

        /**
         * Получить все настройки.
         */
        public function get_settings() {
                return get_option( $this->settings_key, array() );
        }

        /**
         * Получить конкретную настройку.
         *
         * @param string $key     Ключ.
         * @param mixed  $default По умолчанию.
         */
        public function get( $key, $default = null ) {
                $opts = $this->get_settings();
                return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
        }

        /**
         * Получить маппинг способов оплаты WooCommerce → IDTipoIncasso BeeStore.
         *
         * @return array
         */
        public function get_payment_mapping() {
                $opts = $this->get_settings();
                return isset( $opts['mapping_payment'] ) && is_array( $opts['mapping_payment'] ) ? $opts['mapping_payment'] : array();
        }

        /**
         * Расширение интервалов WP-Cron.
         */
        public function add_cron_interval( $schedules ) {
                $schedules['every5min']  = array(
                        'interval' => 300,
                        'display'  => __( 'Каждые 5 минут', 'beestore-integration' ),
                );
                $schedules['every15min'] = array(
                        'interval' => 900,
                        'display'  => __( 'Каждые 15 минут', 'beestore-integration' ),
                );
                $schedules['every30min'] = array(
                        'interval' => 1800,
                        'display'  => __( 'Каждые 30 минут', 'beestore-integration' ),
                );
                return $schedules;
        }

        public function register_menu() {
                $capability = 'manage_woocommerce';

                add_menu_page(
                        __( 'BeeStore Integration', 'beestore-integration' ),
                        __( 'BeeStore', 'beestore-integration' ),
                        $capability,
                        'beestore-integration',
                        array( $this, 'render_settings_page' ),
                        'dashicons-products',
                        58
                );

                add_submenu_page(
                        'beestore-integration',
                        __( 'Настройки', 'beestore-integration' ),
                        __( 'Настройки', 'beestore-integration' ),
                        $capability,
                        'beestore-integration',
                        array( $this, 'render_settings_page' )
                );
        }

        public function register_settings() {
                register_setting(
                        'bsi_settings_group',
                        $this->settings_key,
                        array( $this, 'sanitize_settings' )
                );
        }

        public function sanitize_settings( $input ) {
                $output = array();

                // FTP.
                $output['ftp_host']     = isset( $input['ftp_host'] ) ? sanitize_text_field( $input['ftp_host'] ) : '';
                $output['ftp_port']     = isset( $input['ftp_port'] ) ? absint( $input['ftp_port'] ) : 21;
                $output['ftp_user']     = isset( $input['ftp_user'] ) ? sanitize_text_field( $input['ftp_user'] ) : '';
                $output['ftp_pass']     = isset( $input['ftp_pass'] ) ? $input['ftp_pass'] : ''; // Пароль как есть.
                $output['ftp_path']     = isset( $input['ftp_path'] ) ? sanitize_text_field( $input['ftp_path'] ) : '/';
                $output['ftp_use_sftp'] = isset( $input['ftp_use_sftp'] ) ? '1' : '0';

                // SOAP.
                $output['wsdl_url']    = isset( $input['wsdl_url'] ) ? esc_url_raw( $input['wsdl_url'] ) : '';
                $output['soap_user']   = isset( $input['soap_user'] ) ? sanitize_text_field( $input['soap_user'] ) : '';
                $output['soap_pass']   = isset( $input['soap_pass'] ) ? $input['soap_pass'] : '';

                // BeeStore IDs.
                $output['igu_negozio']        = isset( $input['igu_negozio'] ) ? sanitize_text_field( $input['igu_negozio'] ) : '';
                $output['igu_cliente']        = isset( $input['igu_cliente'] ) ? sanitize_text_field( $input['igu_cliente'] ) : '';
                $output['igu_magazzino_riga'] = isset( $input['igu_magazzino_riga'] ) ? sanitize_text_field( $input['igu_magazzino_riga'] ) : '';
                $output['cod_iva_default']    = isset( $input['cod_iva_default'] ) ? sanitize_text_field( $input['cod_iva_default'] ) : '22';
                $output['default_tax_rate']   = isset( $input['default_tax_rate'] ) ? floatval( $input['default_tax_rate'] ) : 22;
                $output['igu_valuta']         = isset( $input['igu_valuta'] ) ? sanitize_text_field( $input['igu_valuta'] ) : '';
                $output['cod_dest_sdi']       = isset( $input['cod_dest_sdi'] ) ? sanitize_text_field( $input['cod_dest_sdi'] ) : '';

                // Behaviour.
                $output['enable_order_sync']     = isset( $input['enable_order_sync'] ) ? '1' : '0';
                $output['enable_status_sync']    = isset( $input['enable_status_sync'] ) ? '1' : '0';
                $output['enable_realtime_stock'] = isset( $input['enable_realtime_stock'] ) ? '1' : '0';
                $output['delete_out_of_stock']   = isset( $input['delete_out_of_stock'] ) ? '1' : '0';
                $output['download_images']       = isset( $input['download_images'] ) ? '1' : '0';

                $output['sync_frequency']        = isset( $input['sync_frequency'] ) ? sanitize_text_field( $input['sync_frequency'] ) : 'hourly';
                $output['status_sync_frequency'] = isset( $input['status_sync_frequency'] ) ? sanitize_text_field( $input['status_sync_frequency'] ) : 'hourly';
                $output['import_batch_size']     = isset( $input['import_batch_size'] ) ? absint( $input['import_batch_size'] ) : 200;
                $output['id_tipo_incasso_default'] = isset( $input['id_tipo_incasso_default'] ) ? absint( $input['id_tipo_incasso_default'] ) : 3;
                $output['log_level']             = isset( $input['log_level'] ) ? sanitize_text_field( $input['log_level'] ) : 'info';

                // Маппинг платежей.
                $output['mapping_payment'] = array();
                if ( isset( $input['mapping_payment'] ) && is_array( $input['mapping_payment'] ) ) {
                        foreach ( $input['mapping_payment'] as $gateway_id => $tipo_incasso ) {
                                $gateway_id = sanitize_text_field( $gateway_id );
                                $tipo_incasso = absint( $tipo_incasso );
                                if ( '' !== $gateway_id && $tipo_incasso > 0 ) {
                                        $output['mapping_payment'][ $gateway_id ] = $tipo_incasso;
                                }
                        }
                }

                // Конвертация цен.
                $output['enable_price_conversion'] = isset( $input['enable_price_conversion'] ) ? '1' : '0';
                $output['currency_rate']           = isset( $input['currency_rate'] ) ? floatval( $input['currency_rate'] ) : 1;
                $output['currency_rate_mode']      = isset( $input['currency_rate_mode'] ) ? sanitize_text_field( $input['currency_rate_mode'] ) : 'manual';
                $output['currency_rate_auto_source'] = isset( $input['currency_rate_auto_source'] ) ? sanitize_text_field( $input['currency_rate_auto_source'] ) : 'auto';
                $output['markup_coefficient']      = isset( $input['markup_coefficient'] ) ? floatval( $input['markup_coefficient'] ) : 1;
                $output['fixed_markup']            = isset( $input['fixed_markup'] ) ? floatval( $input['fixed_markup'] ) : 0;
                $output['supplier_currency']       = isset( $input['supplier_currency'] ) ? sanitize_text_field( $input['supplier_currency'] ) : 'EUR';
                $output['shop_currency']           = isset( $input['shop_currency'] ) ? sanitize_text_field( $input['shop_currency'] ) : 'RUB';
                $output['round_prices']            = isset( $input['round_prices'] ) ? '1' : '0';

                // Сохраняем информацию о последнем авто-обновлении (не из формы, а из текущих опций).
                $current = get_option( $this->settings_key, array() );
                $output['currency_rate_last_source'] = isset( $current['currency_rate_last_source'] ) ? $current['currency_rate_last_source'] : '';
                $output['currency_rate_last_update'] = isset( $current['currency_rate_last_update'] ) ? $current['currency_rate_last_update'] : '';

                // Если курс задан неверно (0 или меньше) — сбрасываем на 1.
                if ( $output['currency_rate'] <= 0 ) {
                        $output['currency_rate'] = 1;
                }
                if ( $output['markup_coefficient'] <= 0 ) {
                        $output['markup_coefficient'] = 1;
                }

                // WebP конвертация.
                $output['webp_enabled']  = isset( $input['webp_enabled'] ) ? '1' : '0';
                $output['webp_strategy'] = isset( $input['webp_strategy'] ) ? max( 1, min( 5, absint( $input['webp_strategy'] ) ) ) : 3;

                // Фильтры импорта.
                $output['import_filter_mode'] = isset( $input['import_filter_mode'] ) ? sanitize_text_field( $input['import_filter_mode'] ) : 'all';
                if ( ! in_array( $output['import_filter_mode'], array( 'all', 'whitelist', 'blacklist' ), true ) ) {
                        $output['import_filter_mode'] = 'all';
                }

                // Фильтр категорий: парсим текстовое поле (одна категория на строку, лимит через |).
                $output['import_filter_categories'] = array();
                $cats_text = isset( $input['filter_cats_text'] ) ? wp_unslash( $input['filter_cats_text'] ) : '';
                if ( $cats_text ) {
                        $lines = explode( "\n", $cats_text );
                        foreach ( $lines as $line ) {
                                $line = trim( $line );
                                if ( '' === $line ) {
                                        continue;
                                }
                                $parts = explode( '|', $line );
                                $name  = trim( $parts[0] );
                                $limit = isset( $parts[1] ) ? trim( $parts[1] ) : '0';
                                $limit = '' === $limit ? 0 : absint( $limit );
                                if ( $name ) {
                                        $output['import_filter_categories'][ $name ] = $limit;
                                }
                        }
                }

                // Фильтр брендов: парсим текстовое поле.
                $output['import_filter_brands'] = array();
                $brands_text = isset( $input['filter_brands_text'] ) ? wp_unslash( $input['filter_brands_text'] ) : '';
                if ( $brands_text ) {
                        $lines = explode( "\n", $brands_text );
                        foreach ( $lines as $line ) {
                                $line = trim( $line );
                                if ( '' === $line ) {
                                        continue;
                                }
                                $parts = explode( '|', $line );
                                $name  = trim( $parts[0] );
                                $limit = isset( $parts[1] ) ? trim( $parts[1] ) : '0';
                                $limit = '' === $limit ? 0 : absint( $limit );
                                if ( $name ) {
                                        $output['import_filter_brands'][ $name ] = $limit;
                                }
                        }
                }

                return $output;
        }

        /**
         * Рендер страницы настроек.
         */
        public function render_settings_page() {
                $settings = $this->get_settings();
                $gateways = function_exists( 'WC' ) ? WC()->payment_gateways()->payment_gateways() : array();

                include BSI_PLUGIN_DIR . 'templates/settings-page.php';
        }
}
