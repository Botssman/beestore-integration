<?php
/**
 * Plugin Name:       BeeStore Integration for WooCommerce
 * Plugin URI:        https://github.com/Botssman/beestore-integration
 * Description:       Интеграция WooCommerce с BeeStore (Sirio Informatica): импорт каталога из CSV-выгрузки FTP, синхронизация остатков, передача заказов через SOAP (fInserimentoPrenotazione), обратная синхронизация статусов и tracking number, отмена/доплата заказа, конвертация цен и картинок в WebP, гибкие фильтры импорта по категориям и брендам.
 * Version:           1.7.2
 * Author:            Kirill Andreev
 * License:           GPL-2.0+
 * Text Domain:       beestore-integration
 * Domain Path:       /languages
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit; // Запрет прямого доступа.
}

/* -------------------------------------------------------------------------
 * Константы плагина
 * ------------------------------------------------------------------------- */
define( 'BSI_VERSION', '1.7.2' );
define( 'BSI_PLUGIN_FILE', __FILE__ );
define( 'BSI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BSI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BSI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

define( 'BSI_LOG_TABLE', 'beestore_logs' );
define( 'BSI_QUEUE_TABLE', 'beestore_queue' );

/* -------------------------------------------------------------------------
 * Автозагрузка классов
 * ------------------------------------------------------------------------- */
spl_autoload_register(
        function ( $class ) {
                $prefix = 'BSI_';
                if ( strpos( $class, $prefix ) !== 0 ) {
                        return;
                }
                $relative  = substr( $class, strlen( $prefix ) );
                $file_path = BSI_PLUGIN_DIR . 'includes/class-bsi-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
                if ( file_exists( $file_path ) ) {
                        require_once $file_path;
                }
        }
);

/* -------------------------------------------------------------------------
 * Проверка зависимостей (WooCommerce + PHP SoapClient)
 * ------------------------------------------------------------------------- */
register_activation_hook(
        __FILE__,
        function () {
                if ( ! class_exists( 'WooCommerce' ) ) {
                        deactivate_plugins( BSI_PLUGIN_BASENAME );
                        wp_die( esc_html__( 'Для работы плагина BeeStore Integration требуется установленный и активированный WooCommerce.', 'beestore-integration' ) );
                }
                if ( ! class_exists( 'SoapClient' ) ) {
                        deactivate_plugins( BSI_PLUGIN_BASENAME );
                        wp_die( esc_html__( 'Для работы плагина BeeStore Integration требуется расширение PHP SOAP. Обратитесь к хостинг-провайдеру для его включения.', 'beestore-integration' ) );
                }
                BSI_Installer::activate();
        }
);

register_deactivation_hook(
        __FILE__,
        function () {
                BSI_Installer::deactivate();
        }
);

/* -------------------------------------------------------------------------
 * Инициализация плагина
 * ------------------------------------------------------------------------- */
add_action(
        'plugins_loaded',
        function () {
                // Тексты домена.
                load_plugin_textdomain( 'beestore-integration', false, dirname( BSI_PLUGIN_BASENAME ) . '/languages' );

                // Не продолжаем, если нет WC.
                if ( ! class_exists( 'WooCommerce' ) ) {
                        add_action(
                                'admin_notices',
                                function () {
                                        echo '<div class="notice notice-error"><p>' .
                                                esc_html__( 'BeeStore Integration: WooCommerce не активен. Плагин не будет работать.', 'beestore-integration' ) .
                                                '</p></div>';
                                }
                        );
                        return;
                }

                // Инициализация модулей.
                BSI_Settings::instance();
                BSI_Logger::instance();
                BSI_Client::instance();
                BSI_FTP::instance();
                BSI_CSV_Parser::instance();
                BSI_Importer::instance();
                BSI_Order_Sync::instance();
                BSI_Status_Sync::instance();
                BSI_Currency::instance();
                BSI_Translations::instance();
                BSI_WebP::instance();
                BSI_Import_Filters::instance();
                BSI_Admin::instance();
                BSI_Cron::instance();

                // Автообновление из GitHub.
                new BSI_GitHub_Updater();
        }
);
