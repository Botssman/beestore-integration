<?php
/**
 * Шаблон страницы настроек BeeStore Integration.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

$settings = get_option( 'bsi_settings', array() );
$gateways = function_exists( 'WC' ) ? WC()->payment_gateways()->payment_gateways() : array();

// Дефолтные значения.
$ftp_host     = isset( $settings['ftp_host'] ) ? $settings['ftp_host'] : '';
$ftp_port     = isset( $settings['ftp_port'] ) ? $settings['ftp_port'] : 21;
$ftp_user     = isset( $settings['ftp_user'] ) ? $settings['ftp_user'] : '';
$ftp_pass     = isset( $settings['ftp_pass'] ) ? $settings['ftp_pass'] : '';
$ftp_path     = isset( $settings['ftp_path'] ) ? $settings['ftp_path'] : '/';
$ftp_use_sftp = isset( $settings['ftp_use_sftp'] ) && '1' === $settings['ftp_use_sftp'];

$wsdl_url    = isset( $settings['wsdl_url'] ) ? $settings['wsdl_url'] : '';
$soap_user   = isset( $settings['soap_user'] ) ? $settings['soap_user'] : '';
$soap_pass   = isset( $settings['soap_pass'] ) ? $settings['soap_pass'] : '';

$igu_negozio        = isset( $settings['igu_negozio'] ) ? $settings['igu_negozio'] : '';
$igu_cliente        = isset( $settings['igu_cliente'] ) ? $settings['igu_cliente'] : '';
$igu_magazzino_riga = isset( $settings['igu_magazzino_riga'] ) ? $settings['igu_magazzino_riga'] : '';
$cod_iva_default    = isset( $settings['cod_iva_default'] ) ? $settings['cod_iva_default'] : '22';
$default_tax_rate   = isset( $settings['default_tax_rate'] ) ? $settings['default_tax_rate'] : 22;
$igu_valuta         = isset( $settings['igu_valuta'] ) ? $settings['igu_valuta'] : '';
$cod_dest_sdi       = isset( $settings['cod_dest_sdi'] ) ? $settings['cod_dest_sdi'] : '';

$enable_order_sync     = isset( $settings['enable_order_sync'] ) && '1' === $settings['enable_order_sync'];
$enable_status_sync    = isset( $settings['enable_status_sync'] ) && '1' === $settings['enable_status_sync'];
$enable_realtime_stock = isset( $settings['enable_realtime_stock'] ) && '1' === $settings['enable_realtime_stock'];
$delete_out_of_stock   = isset( $settings['delete_out_of_stock'] ) && '1' === $settings['delete_out_of_stock'];
$download_images       = ! isset( $settings['download_images'] ) || '1' === $settings['download_images'];

$sync_frequency        = isset( $settings['sync_frequency'] ) ? $settings['sync_frequency'] : 'hourly';
$status_sync_frequency = isset( $settings['status_sync_frequency'] ) ? $settings['status_sync_frequency'] : 'hourly';
$import_batch_size     = isset( $settings['import_batch_size'] ) ? $settings['import_batch_size'] : 200;
$id_tipo_incasso_default = isset( $settings['id_tipo_incasso_default'] ) ? $settings['id_tipo_incasso_default'] : 3;
$log_level             = isset( $settings['log_level'] ) ? $settings['log_level'] : 'info';
$mapping_payment       = isset( $settings['mapping_payment'] ) && is_array( $settings['mapping_payment'] ) ? $settings['mapping_payment'] : array();
// Конвертация цен.
$enable_price_conversion = isset( $settings['enable_price_conversion'] ) && '1' === $settings['enable_price_conversion'];
$currency_rate           = isset( $settings['currency_rate'] ) ? $settings['currency_rate'] : 1;
$currency_rate_mode      = isset( $settings['currency_rate_mode'] ) ? $settings['currency_rate_mode'] : 'manual';
$currency_rate_auto_source = isset( $settings['currency_rate_auto_source'] ) ? $settings['currency_rate_auto_source'] : 'auto';
$currency_rate_last_source = isset( $settings['currency_rate_last_source'] ) ? $settings['currency_rate_last_source'] : '';
$currency_rate_last_update = isset( $settings['currency_rate_last_update'] ) ? $settings['currency_rate_last_update'] : '';
$markup_coefficient      = isset( $settings['markup_coefficient'] ) ? $settings['markup_coefficient'] : 1;
$fixed_markup            = isset( $settings['fixed_markup'] ) ? $settings['fixed_markup'] : 0;
$supplier_currency       = isset( $settings['supplier_currency'] ) ? $settings['supplier_currency'] : 'EUR';
$shop_currency           = isset( $settings['shop_currency'] ) ? $settings['shop_currency'] : 'RUB';
$round_prices            = isset( $settings['round_prices'] ) && '1' === $settings['round_prices'];

// Текущий курс через BSI_Currency (для отображения).
$current_rate_info = class_exists( 'BSI_Currency' ) ? BSI_Currency::instance()->get_current_rate() : array(
        'rate'    => $currency_rate,
        'source'  => 'manual',
        'updated' => '',
        'mode'    => $currency_rate_mode,
);

// Человекопонятные названия источников.
$source_names = array(
        'manual'        => __( 'Ручной ввод', 'beestore-integration' ),
        'cbrf'          => __( 'ЦБ РФ', 'beestore-integration' ),
        'ecb'           => __( 'Европейский ЦБ (ECB)', 'beestore-integration' ),
        'er_api'        => __( 'open.er-api.com', 'beestore-integration' ),
        'same_currency' => __( 'Валюты совпадают', 'beestore-integration' ),
);
$source_label = isset( $source_names[ $current_rate_info['source'] ] ) ? $source_names[ $current_rate_info['source'] ] : $current_rate_info['source'];
?>

<div class="wrap">
        <h1><?php echo esc_html__( 'BeeStore Integration — Настройки', 'beestore-integration' ); ?></h1>

        <form method="post" action="options.php">
                <?php settings_fields( 'bsi_settings_group' ); ?>

                <h2 class="nav-tab-wrapper" style="margin-bottom:15px;">
                        <a href="#bsi-ftp" class="nav-tab nav-tab-active" data-tab="ftp"><?php esc_html_e( 'FTP', 'beestore-integration' ); ?></a>
                        <a href="#bsi-soap" class="nav-tab" data-tab="soap"><?php esc_html_e( 'SOAP / BeeStore', 'beestore-integration' ); ?></a>
                        <a href="#bsi-sync" class="nav-tab" data-tab="sync"><?php esc_html_e( 'Синхронизация', 'beestore-integration' ); ?></a>
                        <a href="#bsi-payments" class="nav-tab" data-tab="payments"><?php esc_html_e( 'Платежи', 'beestore-integration' ); ?></a>
                        <a href="#bsi-pricing" class="nav-tab" data-tab="pricing"><?php esc_html_e( 'Конвертация цен', 'beestore-integration' ); ?></a>
                </h2>

                <!-- FTP -->
                <div id="bsi-ftp" class="bsi-tab">
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th><label for="ftp_host"><?php esc_html_e( 'FTP/SFTP хост', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="text" name="bsi_settings[ftp_host]" id="ftp_host" value="<?php echo esc_attr( $ftp_host ); ?>" class="regular-text" placeholder="ftp.example.com">
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="ftp_port"><?php esc_html_e( 'Порт', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="number" name="bsi_settings[ftp_port]" id="ftp_port" value="<?php echo esc_attr( $ftp_port ); ?>" class="small-text">
                                                <p class="description"><?php esc_html_e( 'FTP по умолчанию — 21, SFTP — 22.', 'beestore-integration' ); ?></p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="ftp_user"><?php esc_html_e( 'Пользователь', 'beestore-integration' ); ?></label></th>
                                        <td><input type="text" name="bsi_settings[ftp_user]" id="ftp_user" value="<?php echo esc_attr( $ftp_user ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                        <th><label for="ftp_pass"><?php esc_html_e( 'Пароль', 'beestore-integration' ); ?></label></th>
                                        <td><input type="password" name="bsi_settings[ftp_pass]" id="ftp_pass" value="<?php echo esc_attr( $ftp_pass ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                        <th><label for="ftp_path"><?php esc_html_e( 'Каталог на FTP', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="text" name="bsi_settings[ftp_path]" id="ftp_path" value="<?php echo esc_attr( $ftp_path ); ?>" class="regular-text" placeholder="/">
                                                <p class="description"><?php esc_html_e( 'Путь к каталогу, куда BeeStore выкладывает ZIP-файлы COMPANY_*.zip.', 'beestore-integration' ); ?></p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Использовать SFTP', 'beestore-integration' ); ?></th>
                                        <td>
                                                <label>
                                                        <input type="checkbox" name="bsi_settings[ftp_use_sftp]" value="1" <?php checked( $ftp_use_sftp ); ?>>
                                                        <?php esc_html_e( 'Включить SFTP (требуется расширение PHP ssh2)', 'beestore-integration' ); ?>
                                                </label>
                                        </td>
                                </tr>
                        </table>
                </div>

                <!-- SOAP -->
                <div id="bsi-soap" class="bsi-tab" style="display:none;">
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th><label for="wsdl_url"><?php esc_html_e( 'WSDL URL', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="url" name="bsi_settings[wsdl_url]" id="wsdl_url" value="<?php echo esc_attr( $wsdl_url ); ?>" class="large-text" placeholder="http://www.sirio-is.it:8180/.../soapBeestore.wsdl">
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="soap_user"><?php esc_html_e( 'Пользователь SOAP', 'beestore-integration' ); ?></label></th>
                                        <td><input type="text" name="bsi_settings[soap_user]" id="soap_user" value="<?php echo esc_attr( $soap_user ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                        <th><label for="soap_pass"><?php esc_html_e( 'Пароль SOAP', 'beestore-integration' ); ?></label></th>
                                        <td><input type="password" name="bsi_settings[soap_pass]" id="soap_pass" value="<?php echo esc_attr( $soap_pass ); ?>" class="regular-text"></td>
                                </tr>
                                <tr>
                                        <th><label for="igu_negozio"><?php esc_html_e( 'IGU Negozio (магазин)', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="text" name="bsi_settings[igu_negozio]" id="igu_negozio" value="<?php echo esc_attr( $igu_negozio ); ?>" class="regular-text">
                                                <p class="description"><?php esc_html_e( 'В вашей выгрузке: 540 (магазин BAY).', 'beestore-integration' ); ?></p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="igu_cliente"><?php esc_html_e( 'IGU Cliente (клиент по умолчанию)', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="text" name="bsi_settings[igu_cliente]" id="igu_cliente" value="<?php echo esc_attr( $igu_cliente ); ?>" class="regular-text" placeholder="13\51\39\1\419339\0">
                                                <p class="description"><?php esc_html_e( 'Уникальный клиент-призрак, под которым оформляются все заказы маркетплейса в BeeStore. Код предоставляет Sirio.', 'beestore-integration' ); ?></p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="igu_valuta"><?php esc_html_e( 'IGU Valuta', 'beestore-integration' ); ?></label></th>
                                        <td><input type="text" name="bsi_settings[igu_valuta]" id="igu_valuta" value="<?php echo esc_attr( $igu_valuta ); ?>" class="regular-text" placeholder="15\1\1\1"></td>
                                </tr>
                                <tr>
                                        <th><label for="igu_magazzino_riga"><?php esc_html_e( 'IGU Magazzino Riga', 'beestore-integration' ); ?></label></th>
                                        <td><input type="text" name="bsi_settings[igu_magazzino_riga]" id="igu_magazzino_riga" value="<?php echo esc_attr( $igu_magazzino_riga ); ?>" class="regular-text" placeholder="оставьте пустым"></td>
                                </tr>
                                <tr>
                                        <th><label for="cod_iva_default"><?php esc_html_e( 'Cod IVA по умолчанию', 'beestore-integration' ); ?></label></th>
                                        <td><input type="text" name="bsi_settings[cod_iva_default]" id="cod_iva_default" value="<?php echo esc_attr( $cod_iva_default ); ?>" class="small-text" placeholder="22"></td>
                                </tr>
                                <tr>
                                        <th><label for="default_tax_rate"><?php esc_html_e( 'Ставка НДС, %', 'beestore-integration' ); ?></label></th>
                                        <td><input type="number" step="0.01" name="bsi_settings[default_tax_rate]" id="default_tax_rate" value="<?php echo esc_attr( $default_tax_rate ); ?>" class="small-text"></td>
                                </tr>
                                <tr>
                                        <th><label for="cod_dest_sdi"><?php esc_html_e( 'CodDestSDI', 'beestore-integration' ); ?></label></th>
                                        <td><input type="text" name="bsi_settings[cod_dest_sdi]" id="cod_dest_sdi" value="<?php echo esc_attr( $cod_dest_sdi ); ?>" class="regular-text" maxlength="7"></td>
                                </tr>
                        </table>
                </div>

                <!-- Синхронизация -->
                <div id="bsi-sync" class="bsi-tab" style="display:none;">
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th><?php esc_html_e( 'Передавать заказы в BeeStore', 'beestore-integration' ); ?></th>
                                        <td>
                                                <label>
                                                        <input type="checkbox" name="bsi_settings[enable_order_sync]" value="1" <?php checked( $enable_order_sync ); ?>>
                                                        <?php esc_html_e( 'Включить (fInserimentoPrenotazione при оплате)', 'beestore-integration' ); ?>
                                                </label>
                                        </td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Получать статусы заказов', 'beestore-integration' ); ?></th>
                                        <td>
                                                <label>
                                                        <input type="checkbox" name="bsi_settings[enable_status_sync]" value="1" <?php checked( $enable_status_sync ); ?>>
                                                        <?php esc_html_e( 'Включить cron-опрос fStatoPrenotazioni', 'beestore-integration' ); ?>
                                                </label>
                                        </td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Проверка остатков real-time', 'beestore-integration' ); ?></th>
                                        <td>
                                                <label>
                                                        <input type="checkbox" name="bsi_settings[enable_realtime_stock]" value="1" <?php checked( $enable_realtime_stock ); ?>>
                                                        <?php esc_html_e( 'Запрос fDisponibilita при оформлении заказа (замедляет checkout)', 'beestore-integration' ); ?>
                                                </label>
                                        </td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Снимать с публикации отсутствующие товары', 'beestore-integration' ); ?></th>
                                        <td>
                                                <label>
                                                        <input type="checkbox" name="bsi_settings[delete_out_of_stock]" value="1" <?php checked( $delete_out_of_stock ); ?>>
                                                        <?php esc_html_e( 'Если товар не встретился в утренней выгрузке — скрывать', 'beestore-integration' ); ?>
                                                </label>
                                        </td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Скачивать картинки', 'beestore-integration' ); ?></th>
                                        <td>
                                                <label>
                                                        <input type="checkbox" name="bsi_settings[download_images]" value="1" <?php checked( $download_images ); ?>>
                                                        <?php esc_html_e( 'Загружать URLImg1..10 в Media Library', 'beestore-integration' ); ?>
                                                </label>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="sync_frequency"><?php esc_html_e( 'Частота импорта каталога', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <select name="bsi_settings[sync_frequency]" id="sync_frequency">
                                                        <?php
                                                        $frequencies = array(
                                                                'every15min' => __( 'Каждые 15 минут', 'beestore-integration' ),
                                                                'every30min' => __( 'Каждые 30 минут', 'beestore-integration' ),
                                                                'hourly'     => __( 'Каждый час', 'beestore-integration' ),
                                                                'twicedaily' => __( 'Дважды в день', 'beestore-integration' ),
                                                                'daily'      => __( 'Раз в день', 'beestore-integration' ),
                                                        );
                                                        foreach ( $frequencies as $k => $label ) {
                                                                echo '<option value="' . esc_attr( $k ) . '" ' . selected( $sync_frequency, $k, false ) . '>' . esc_html( $label ) . '</option>';
                                                        }
                                                        ?>
                                                </select>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="status_sync_frequency"><?php esc_html_e( 'Частота опроса статусов', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <select name="bsi_settings[status_sync_frequency]" id="status_sync_frequency">
                                                        <?php
                                                        foreach ( $frequencies as $k => $label ) {
                                                                echo '<option value="' . esc_attr( $k ) . '" ' . selected( $status_sync_frequency, $k, false ) . '>' . esc_html( $label ) . '</option>';
                                                        }
                                                        ?>
                                                </select>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="import_batch_size"><?php esc_html_e( 'Размер пакета импорта', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="number" name="bsi_settings[import_batch_size]" id="import_batch_size" value="<?php echo esc_attr( $import_batch_size ); ?>" class="small-text" min="10" max="2000" step="10">
                                                <p class="description"><?php esc_html_e( 'Сколько моделей обрабатывается за один проход перед очисткой памяти (10–2000).', 'beestore-integration' ); ?></p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="log_level"><?php esc_html_e( 'Уровень логирования', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <select name="bsi_settings[log_level]" id="log_level">
                                                        <?php
                                                        $levels = array(
                                                                'debug'   => __( 'Debug (всё)', 'beestore-integration' ),
                                                                'info'    => __( 'Info (рекомендуется)', 'beestore-integration' ),
                                                                'warning' => __( 'Только предупреждения', 'beestore-integration' ),
                                                                'error'   => __( 'Только ошибки', 'beestore-integration' ),
                                                        );
                                                        foreach ( $levels as $k => $label ) {
                                                                echo '<option value="' . esc_attr( $k ) . '" ' . selected( $log_level, $k, false ) . '>' . esc_html( $label ) . '</option>';
                                                        }
                                                        ?>
                                                </select>
                                        </td>
                                </tr>
                        </table>
                </div>

                <!-- Платежи -->
                <div id="bsi-payments" class="bsi-tab" style="display:none;">
                        <p><?php esc_html_e( 'Укажите соответствие между способами оплаты WooCommerce и кодами IDTipoIncasso BeeStore.', 'beestore-integration' ); ?></p>
                        <p class="description"><?php echo esc_html__( 'Типы BeeStore: 2 = чек, 3 = карта/PayPal/Stripe, 20 = депозит, 21 = продажа в кредит (оплата при доставке).', 'beestore-integration' ); ?></p>

                        <table class="widefat striped">
                                <thead>
                                        <tr>
                                                <th><?php esc_html_e( 'Способ оплаты WC', 'beestore-integration' ); ?></th>
                                                <th><?php esc_html_e( 'IDTipoIncasso BeeStore', 'beestore-integration' ); ?></th>
                                        </tr>
                                </thead>
                                <tbody>
                                        <?php if ( empty( $gateways ) ) : ?>
                                                <tr><td colspan="2"><?php esc_html_e( 'WooCommerce не обнаружил способов оплаты. Включите хотя бы один платёжный плагин.', 'beestore-integration' ); ?></td></tr>
                                        <?php else : ?>
                                                <?php foreach ( $gateways as $gateway ) : ?>
                                                        <?php
                                                        $gid     = $gateway->id;
                                                        $current = isset( $mapping_payment[ $gid ] ) ? (int) $mapping_payment[ $gid ] : 0;
                                                        ?>
                                                        <tr>
                                                                <td>
                                                                        <strong><?php echo esc_html( $gateway->title ); ?></strong>
                                                                        <code style="margin-left:8px;color:#666;"><?php echo esc_html( $gid ); ?></code>
                                                                </td>
                                                                <td>
                                                                        <select name="bsi_settings[mapping_payment][<?php echo esc_attr( $gid ); ?>]">
                                                                                <option value="0"><?php esc_html_e( '— использовать по умолчанию —', 'beestore-integration' ); ?></option>
                                                                                <option value="2" <?php selected( $current, 2 ); ?>><?php esc_html_e( '2 — Чек', 'beestore-integration' ); ?></option>
                                                                                <option value="3" <?php selected( $current, 3 ); ?>><?php esc_html_e( '3 — Карта/PayPal/Stripe', 'beestore-integration' ); ?></option>
                                                                                <option value="20" <?php selected( $current, 20 ); ?>><?php esc_html_e( '20 — Депозит', 'beestore-integration' ); ?></option>
                                                                                <option value="21" <?php selected( $current, 21 ); ?>><?php esc_html_e( '21 — Кредит/при доставке', 'beestore-integration' ); ?></option>
                                                                        </select>
                                                                </td>
                                                        </tr>
                                                <?php endforeach; ?>
                                        <?php endif; ?>
                                </tbody>
                        </table>

                        <table class="form-table" role="presentation" style="margin-top:20px;">
                                <tr>
                                        <th><label for="id_tipo_incasso_default"><?php esc_html_e( 'IDTipoIncasso по умолчанию', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="number" name="bsi_settings[id_tipo_incasso_default]" id="id_tipo_incasso_default" value="<?php echo esc_attr( $id_tipo_incasso_default ); ?>" class="small-text" min="0" max="99">
                                                <p class="description"><?php esc_html_e( 'Используется, если для способа оплаты WC не задан явный маппинг.', 'beestore-integration' ); ?></p>
                                        </td>
                                </tr>
                        </table>
                </div>

                <!-- Конвертация цен -->
                <div id="bsi-pricing" class="bsi-tab" style="display:none;">
                        <p>
                                <?php
                                echo wp_kses_post( __( '<strong>Формула обновления цены для каждого из поставщиков:</strong>', 'beestore-integration' ) );
                                ?>
                        </p>
                        <p style="background:#f6f7f7;padding:15px 20px;border-left:4px solid #2271b1;font-size:14px;">
                                <code style="font-size:14px;background:transparent;">
                                        <?php esc_html_e( 'цена поставщика × курс валюты × коэффициент надбавки + фиксированная надбавка', 'beestore-integration' ); ?>
                                </code>
                        </p>
                        <p class="description">
                                <?php esc_html_e( 'Пример: 100 EUR × 100 (RUB/EUR) × 1.3 (наценка 30%) + 500 RUB = 13 500 RUB', 'beestore-integration' ); ?>
                        </p>

                        <table class="form-table" role="presentation" style="margin-top:20px;">
                                <tr>
                                        <th><?php esc_html_e( 'Включить конвертацию цен', 'beestore-integration' ); ?></th>
                                        <td>
                                                <label>
                                                        <input type="checkbox" name="bsi_settings[enable_price_conversion]" value="1" <?php checked( $enable_price_conversion ); ?>>
                                                        <?php esc_html_e( 'Применять формулу к ценам при импорте', 'beestore-integration' ); ?>
                                                </label>
                                                <p class="description">
                                                        <?php esc_html_e( 'Если выключено — цены импортируются как есть (в валюте BeeStore).', 'beestore-integration' ); ?>
                                                </p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="supplier_currency"><?php esc_html_e( 'Валюта поставщика (BeeStore)', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <select name="bsi_settings[supplier_currency]" id="supplier_currency">
                                                        <?php
                                                        $currencies = array( 'EUR', 'USD', 'GBP', 'RUB', 'CHF', 'JPY', 'CNY' );
                                                        foreach ( $currencies as $cur ) {
                                                                echo '<option value="' . esc_attr( $cur ) . '" ' . selected( $supplier_currency, $cur, false ) . '>' . esc_html( $cur ) . '</option>';
                                                        }
                                                        ?>
                                                </select>
                                                <p class="description"><?php esc_html_e( 'Валюта, в которой BeeStore присылает цены (PrezzoIvato). Обычно EUR.', 'beestore-integration' ); ?></p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="shop_currency"><?php esc_html_e( 'Валюта магазина (WooCommerce)', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <select name="bsi_settings[shop_currency]" id="shop_currency">
                                                        <?php
                                                        foreach ( $currencies as $cur ) {
                                                                echo '<option value="' . esc_attr( $cur ) . '" ' . selected( $shop_currency, $cur, false ) . '>' . esc_html( $cur ) . '</option>';
                                                        }
                                                        ?>
                                                </select>
                                                <p class="description"><?php esc_html_e( 'Валюта вашего магазина. Должна совпадать с настройкой WooCommerce.', 'beestore-integration' ); ?></p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="currency_rate"><?php esc_html_e( 'Курс валюты', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <!-- Текущее состояние курса -->
                                                <div id="bsi-rate-status" style="background:#f6f7f7;padding:10px 15px;border-radius:4px;margin-bottom:15px;">
                                                        <strong style="font-size:16px;">
                                                                1 <?php echo esc_html( $supplier_currency ); ?> = <span id="bsi-current-rate"><?php echo esc_html( number_format( $current_rate_info['rate'], 4, '.', '' ) ); ?></span> <?php echo esc_html( $shop_currency ); ?>
                                                        </strong>
                                                        <br>
                                                        <small style="color:#666;">
                                                                <?php esc_html_e( 'Источник:', 'beestore-integration' ); ?>
                                                                <span id="bsi-rate-source"><?php echo esc_html( $source_label ); ?></span>
                                                                <?php if ( ! empty( $current_rate_info['updated'] ) ) : ?>
                                                                        | <?php esc_html_e( 'обновлён:', 'beestore-integration' ); ?>
                                                                        <span id="bsi-rate-updated"><?php echo esc_html( $current_rate_info['updated'] ); ?></span>
                                                                <?php endif; ?>
                                                        </small>
                                                </div>

                                                <!-- Выбор режима -->
                                                <label style="display:block;margin-bottom:10px;">
                                                        <input type="radio" name="bsi_settings[currency_rate_mode]" value="manual" <?php checked( $currency_rate_mode, 'manual' ); ?>>
                                                        <strong><?php esc_html_e( 'Ручной режим', 'beestore-integration' ); ?></strong> —
                                                        <?php esc_html_e( 'вписать курс вручную ниже', 'beestore-integration' ); ?>
                                                </label>
                                                <label style="display:block;margin-bottom:10px;">
                                                        <input type="radio" name="bsi_settings[currency_rate_mode]" value="auto" <?php checked( $currency_rate_mode, 'auto' ); ?>>
                                                        <strong><?php esc_html_e( 'Автоматический режим', 'beestore-integration' ); ?></strong> —
                                                        <?php esc_html_e( 'курс тянется онлайн через API (ежедневное обновление по cron)', 'beestore-integration' ); ?>
                                                </label>

                                                <!-- Ручной режим -->
                                                <div id="bsi-manual-mode" style="margin-top:15px;<?php echo 'manual' === $currency_rate_mode ? '' : 'display:none;'; ?>">
                                                        <input type="number" step="0.0001" min="0" name="bsi_settings[currency_rate]" id="currency_rate" value="<?php echo esc_attr( $currency_rate ); ?>" class="small-text">
                                                        <p class="description">
                                                                <?php
                                                                echo esc_html( sprintf(
                                                                        /* translators: 1: shop currency, 2: supplier currency */
                                                                        __( 'Сколько единиц %1$s за 1 %2$s. Например: 100 = 100 RUB за 1 EUR.', 'beestore-integration' ),
                                                                        $shop_currency,
                                                                        $supplier_currency
                                                                ) );
                                                                ?>
                                                        </p>
                                                </div>

                                                <!-- Авто режим -->
                                                <div id="bsi-auto-mode" style="margin-top:15px;<?php echo 'auto' === $currency_rate_mode ? '' : 'display:none;'; ?>">
                                                        <label style="display:block;margin-bottom:8px;">
                                                                <?php esc_html_e( 'Источник курса:', 'beestore-integration' ); ?>
                                                                <select name="bsi_settings[currency_rate_auto_source]" id="currency_rate_auto_source">
                                                                        <option value="auto" <?php selected( $currency_rate_auto_source, 'auto' ); ?>>
                                                                                <?php esc_html_e( 'Авто (рекомендуется) — лучший источник по валюте', 'beestore-integration' ); ?>
                                                                        </option>
                                                                        <option value="cbrf" <?php selected( $currency_rate_auto_source, 'cbrf' ); ?>>
                                                                                <?php esc_html_e( 'ЦБ РФ (только для RUB)', 'beestore-integration' ); ?>
                                                                        </option>
                                                                        <option value="ecb" <?php selected( $currency_rate_auto_source, 'ecb' ); ?>>
                                                                                <?php esc_html_e( 'Европейский ЦБ (ECB)', 'beestore-integration' ); ?>
                                                                        </option>
                                                                        <option value="er_api" <?php selected( $currency_rate_auto_source, 'er_api' ); ?>>
                                                                                <?php esc_html_e( 'open.er-api.com (универсальный)', 'beestore-integration' ); ?>
                                                                        </option>
                                                                </select>
                                                        </label>
                                                        <p class="description" style="margin-bottom:15px;">
                                                                <?php esc_html_e( 'В авто-режиме курс обновляется автоматически каждый день в 06:00. Также можно обновить вручную кнопкой ниже.', 'beestore-integration' ); ?>
                                                        </p>
                                                        <button type="button" class="button button-secondary" id="bsi-refresh-rate">
                                                                <span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:3px;"></span>
                                                                <?php esc_html_e( 'Обновить курс сейчас', 'beestore-integration' ); ?>
                                                        </button>
                                                        <span id="bsi-refresh-status" style="margin-left:10px;"></span>
                                                </div>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="markup_coefficient"><?php esc_html_e( 'Коэффициент надбавки', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="number" step="0.01" min="0" name="bsi_settings[markup_coefficient]" id="markup_coefficient" value="<?php echo esc_attr( $markup_coefficient ); ?>" class="small-text">
                                                <p class="description">
                                                        <?php esc_html_e( 'Множитель наценки. 1.0 = без надбавки, 1.3 = +30%, 1.5 = +50%, 2.0 = +100%.', 'beestore-integration' ); ?>
                                                </p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="fixed_markup"><?php esc_html_e( 'Фиксированная надбавка', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="number" step="0.01" min="0" name="bsi_settings[fixed_markup]" id="fixed_markup" value="<?php echo esc_attr( $fixed_markup ); ?>" class="small-text">
                                                <p class="description">
                                                        <?php
                                                        echo esc_html( sprintf(
                                                                /* translators: 1: shop currency */
                                                                __( 'Фиксированная сумма, добавляемая к каждой цене (в %s). Например: 500 = +500 к каждой цене.', 'beestore-integration' ),
                                                                $shop_currency
                                                        ) );
                                                        ?>
                                                </p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Округлять цены до целых', 'beestore-integration' ); ?></th>
                                        <td>
                                                <label>
                                                        <input type="checkbox" name="bsi_settings[round_prices]" value="1" <?php checked( $round_prices ); ?>>
                                                        <?php esc_html_e( 'Округлять итоговую цену до целого числа (например, 13 549.78 → 13 550)', 'beestore-integration' ); ?>
                                                </label>
                                        </td>
                                </tr>
                        </table>

                        <?php if ( $enable_price_conversion ) : ?>
                                <div style="background:#e7f5ed;border:1px solid #46b450;border-radius:4px;padding:15px 20px;margin-top:20px;">
                                        <h3 style="margin-top:0;"><?php esc_html_e( 'Пример расчёта', 'beestore-integration' ); ?></h3>
                                        <?php
                                        $example_supplier = 100;
                                        $example_result = ( $example_supplier * $currency_rate * $markup_coefficient ) + $fixed_markup;
                                        ?>
                                        <p>
                                                Товар в BeeStore стоит <strong><?php echo esc_html( $example_supplier ); ?> <?php echo esc_html( $supplier_currency ); ?></strong>.<br>
                                                После конвертации в магазине:
                                                <strong><?php echo esc_html( number_format( $example_result, 2, ',', ' ' ) ); ?> <?php echo esc_html( $shop_currency ); ?></strong>
                                        </p>
                                        <p style="font-size:12px;color:#666;">
                                                Формула: <?php echo esc_html( $example_supplier ); ?> × <?php echo esc_html( $currency_rate ); ?> × <?php echo esc_html( $markup_coefficient ); ?> + <?php echo esc_html( $fixed_markup ); ?> = <?php echo esc_html( number_format( $example_result, 2, '.', '' ) ); ?>
                                        </p>
                                </div>
                        <?php endif; ?>
                </div>

                <?php submit_button( __( 'Сохранить настройки', 'beestore-integration' ) ); ?>
        </form>
</div>

<script>
jQuery(document).ready(function($){
        $('.nav-tab-wrapper .nav-tab').on('click', function(e){
                e.preventDefault();
                var tab = $(this).data('tab');
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.bsi-tab').hide();
                $('#bsi-' + tab).show();
        });

        // Переключение режима курса (manual / auto).
        $('input[name="bsi_settings[currency_rate_mode]"]').on('change', function(){
                var mode = $(this).val();
                $('#bsi-manual-mode').toggle('manual' === mode);
                $('#bsi-auto-mode').toggle('auto' === mode);
        });

        // AJAX обновление курса.
        $('#bsi-refresh-rate').on('click', function(){
                var $btn = $(this);
                var $status = $('#bsi-refresh-status');
                $btn.prop('disabled', true);
                $status.html('<span class="bsi-spinner"></span> Обновление...');

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_refresh_rate',
                        nonce: bsiAdmin.nonce
                }, function(response){
                        $btn.prop('disabled', false);
                        if (response.success) {
                                var d = response.data;
                                $status.html('<span style="color:#2e7d32;">✓ ' + d.message + '</span>');
                                // Обновляем отображение.
                                $('#bsi-current-rate').text(parseFloat(d.rate).toFixed(4));
                                $('#bsi-rate-source').text(d.source);
                                if ($('#bsi-rate-updated').length) {
                                        $('#bsi-rate-updated').text(d.updated);
                                } else {
                                        $('#bsi-rate-source').after(' | обновлён: <span id="bsi-rate-updated">' + d.updated + '</span>');
                                }
                        } else {
                                $status.html('<span style="color:#c62828;">✗ ' + (response.data.message || 'Ошибка') + '</span>');
                        }
                }).fail(function(){
                        $btn.prop('disabled', false);
                        $status.html('<span style="color:#c62828;">✗ AJAX error</span>');
                });
        });
});
</script>
