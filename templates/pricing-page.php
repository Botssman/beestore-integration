<?php
/**
 * Шаблон страницы «Конвертация цен» — отдельный раздел под Импортом.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

$settings = get_option( 'bsi_settings', array() );

// Текущие настройки.
$supplier_currency         = isset( $settings['supplier_currency'] ) ? $settings['supplier_currency'] : 'EUR';
$shop_currency             = isset( $settings['shop_currency'] ) ? $settings['shop_currency'] : 'RUB';
$currency_rate             = isset( $settings['currency_rate'] ) ? (float) $settings['currency_rate'] : 1;
$currency_rate_mode        = isset( $settings['currency_rate_mode'] ) ? $settings['currency_rate_mode'] : 'manual';
$currency_rate_auto_source = isset( $settings['currency_rate_auto_source'] ) ? $settings['currency_rate_auto_source'] : 'auto';
$markup_coefficient        = isset( $settings['markup_coefficient'] ) ? (float) $settings['markup_coefficient'] : 1;
$fixed_markup              = isset( $settings['fixed_markup'] ) ? (float) $settings['fixed_markup'] : 0;
$round_prices              = isset( $settings['round_prices'] ) && '1' === $settings['round_prices'];

// Информация о текущем курсе (через BSI_Currency).
$current_rate_info = class_exists( 'BSI_Currency' ) ? BSI_Currency::instance()->get_current_rate() : array(
        'rate'    => $currency_rate,
        'source'  => 'manual',
        'updated' => '',
        'mode'    => $currency_rate_mode,
);

$source_names = array(
        'manual'        => __( 'Ручной ввод', 'beestore-integration' ),
        'cbrf'          => __( 'ЦБ РФ', 'beestore-integration' ),
        'ecb'           => __( 'Европейский ЦБ (ECB)', 'beestore-integration' ),
        'er_api'        => __( 'open.er-api.com', 'beestore-integration' ),
        'same_currency' => __( 'Валюты совпадают', 'beestore-integration' ),
);
$source_label = isset( $source_names[ $current_rate_info['source'] ] ) ? $source_names[ $current_rate_info['source'] ] : $current_rate_info['source'];

// Следующее авто-обновление (когда сработает cron).
$next_refresh = wp_next_scheduled( 'bsi_cron_refresh_rate' );

$currencies = array( 'EUR', 'USD', 'GBP', 'RUB', 'CHF', 'JPY', 'CNY' );

// Пример расчёта.
$example_supplier = 100;
$example_result   = ( $example_supplier * $currency_rate * $markup_coefficient ) + $fixed_markup;
if ( $round_prices ) {
        $example_result = round( $example_result );
}
?>

<div class="wrap">
        <h1><?php echo esc_html__( 'BeeStore — Конвертация цен', 'beestore-integration' ); ?></h1>

        <!-- Баннер-предупреждение: конвертация обязательна при импорте -->
        <div class="notice notice-info" style="border-left-color:#2271b1;">
                <p>
                        <strong><?php esc_html_e( 'Конвертация цен работает автоматически при каждом импорте.', 'beestore-integration' ); ?></strong>
                        <?php esc_html_e( 'Настройки ниже применяются ко всем товарам из BeeStore. Цены пересчитываются по формуле:', 'beestore-integration' ); ?>
                </p>
                <p style="background:#f6f7f7;padding:10px 15px;border-radius:3px;font-family:monospace;">
                        <code><?php esc_html_e( 'цена_магазина = цена_BeeStore × курс_валюты × коэффициент_надбавки + фиксированная_надбавка', 'beestore-integration' ); ?></code>
                </p>
        </div>

        <!-- Текущий курс — крупная карточка -->
        <div class="bsi-card" style="background:#fff;border:1px solid #e0e0e0;border-left:4px solid #2271b1;padding:20px 24px;margin:15px 0 25px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Текущий курс', 'beestore-integration' ); ?></h2>
                <div style="font-size:28px;font-weight:700;color:#2271b1;">
                        1 <?php echo esc_html( $supplier_currency ); ?> =
                        <span id="bsi-current-rate-display"><?php echo esc_html( number_format( $current_rate_info['rate'], 4, '.', ' ' ) ); ?></span>
                        <?php echo esc_html( $shop_currency ); ?>
                </div>
                <p style="color:#666;margin-top:8px;">
                        <?php esc_html_e( 'Источник:', 'beestore-integration' ); ?>
                        <strong id="bsi-rate-source-display"><?php echo esc_html( $source_label ); ?></strong>
                        <?php if ( ! empty( $current_rate_info['updated'] ) ) : ?>
                                | <?php esc_html_e( 'последнее обновление:', 'beestore-integration' ); ?>
                                <strong id="bsi-rate-updated-display"><?php echo esc_html( $current_rate_info['updated'] ); ?></strong>
                        <?php else : ?>
                                | <span id="bsi-rate-updated-display">—</span>
                        <?php endif; ?>
                </p>
                <?php if ( $next_refresh ) : ?>
                        <p style="color:#666;font-size:12px;">
                                <?php esc_html_e( 'Следующее авто-обновление (cron):', 'beestore-integration' ); ?>
                                <?php echo esc_html( date_i18n( 'd.m.Y H:i', $next_refresh + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ) ); ?>
                        </p>
                <?php endif; ?>
        </div>

        <!-- Форма настроек -->
        <form method="post" action="">
                <?php wp_nonce_field( 'bsi_pricing_save', 'bsi_pricing_nonce' ); ?>

                <h2 class="nav-tab-wrapper" style="margin-bottom:15px;">
                        <a href="#bsi-pricing-general" class="nav-tab nav-tab-active" data-tab="general"><?php esc_html_e( 'Валюта и курс', 'beestore-integration' ); ?></a>
                        <a href="#bsi-pricing-markup" class="nav-tab" data-tab="markup"><?php esc_html_e( 'Надбавки', 'beestore-integration' ); ?></a>
                        <a href="#bsi-pricing-preview" class="nav-tab" data-tab="preview"><?php esc_html_e( 'Пример расчёта', 'beestore-integration' ); ?></a>
                </h2>

                <!-- Вкладка: валюта и курс -->
                <div id="bsi-pricing-general" class="bsi-pricing-tab">
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th><label for="supplier_currency"><?php esc_html_e( 'Валюта поставщика (BeeStore)', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <select name="supplier_currency" id="supplier_currency">
                                                        <?php foreach ( $currencies as $cur ) : ?>
                                                                <option value="<?php echo esc_attr( $cur ); ?>" <?php selected( $supplier_currency, $cur ); ?>><?php echo esc_html( $cur ); ?></option>
                                                        <?php endforeach; ?>
                                                </select>
                                                <p class="description"><?php esc_html_e( 'Валюта, в которой BeeStore присылает цены (PrezzoIvato). Обычно EUR.', 'beestore-integration' ); ?></p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="shop_currency"><?php esc_html_e( 'Валюта магазина (WooCommerce)', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <select name="shop_currency" id="shop_currency">
                                                        <?php foreach ( $currencies as $cur ) : ?>
                                                                <option value="<?php echo esc_attr( $cur ); ?>" <?php selected( $shop_currency, $cur ); ?>><?php echo esc_html( $cur ); ?></option>
                                                        <?php endforeach; ?>
                                                </select>
                                                <p class="description"><?php esc_html_e( 'Валюта вашего магазина. Должна совпадать с настройкой WooCommerce.', 'beestore-integration' ); ?></p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Режим обновления курса', 'beestore-integration' ); ?></th>
                                        <td>
                                                <label style="display:block;margin-bottom:10px;">
                                                        <input type="radio" name="currency_rate_mode" value="manual" <?php checked( $currency_rate_mode, 'manual' ); ?>>
                                                        <strong><?php esc_html_e( 'Ручной режим', 'beestore-integration' ); ?></strong> —
                                                        <?php esc_html_e( 'вписать курс вручную ниже', 'beestore-integration' ); ?>
                                                </label>
                                                <label style="display:block;">
                                                        <input type="radio" name="currency_rate_mode" value="auto" <?php checked( $currency_rate_mode, 'auto' ); ?>>
                                                        <strong><?php esc_html_e( 'Автоматический режим', 'beestore-integration' ); ?></strong> —
                                                        <?php esc_html_e( 'курс тянется онлайн через API и обновляется каждый день в 06:00 по cron', 'beestore-integration' ); ?>
                                                </label>
                                        </td>
                                </tr>
                                <tr id="bsi-manual-row" <?php echo 'manual' === $currency_rate_mode ? '' : 'style="display:none;"'; ?>>
                                        <th><label for="currency_rate"><?php esc_html_e( 'Курс (ручной)', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="number" step="0.0001" min="0" name="currency_rate" id="currency_rate" value="<?php echo esc_attr( $currency_rate ); ?>" class="small-text">
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
                                        </td>
                                </tr>
                                <tr id="bsi-auto-row" <?php echo 'auto' === $currency_rate_mode ? '' : 'style="display:none;"'; ?>>
                                        <th><label for="currency_rate_auto_source"><?php esc_html_e( 'Источник курса (авто)', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <select name="currency_rate_auto_source" id="currency_rate_auto_source">
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
                                                <p class="description">
                                                        <?php esc_html_e( 'В авто-режиме курс обновляется автоматически каждый день в 06:00 по системному cron-событию bsi_cron_refresh_rate.', 'beestore-integration' ); ?>
                                                </p>

                                                <!-- Кнопка ручного обновления (через AJAX) -->
                                                <div style="margin-top:15px;padding:12px 15px;background:#f6f7f7;border-radius:3px;">
                                                        <button type="button" class="button button-secondary" id="bsi-refresh-rate">
                                                                <span class="dashicons dashicons-update" style="vertical-align:middle;"></span>
                                                                <?php esc_html_e( 'Обновить курс сейчас', 'beestore-integration' ); ?>
                                                        </button>
                                                        <span id="bsi-refresh-status" style="margin-left:10px;"></span>
                                                </div>
                                        </td>
                                </tr>
                        </table>
                </div>

                <!-- Вкладка: надбавки -->
                <div id="bsi-pricing-markup" class="bsi-pricing-tab" style="display:none;">
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th><label for="markup_coefficient"><?php esc_html_e( 'Коэффициент надбавки', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="number" step="0.01" min="0" name="markup_coefficient" id="markup_coefficient" value="<?php echo esc_attr( $markup_coefficient ); ?>" class="small-text">
                                                <p class="description">
                                                        <?php esc_html_e( 'Множитель наценки. 1.0 = без надбавки, 1.3 = +30%, 1.5 = +50%, 2.0 = +100%.', 'beestore-integration' ); ?>
                                                </p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="fixed_markup"><?php esc_html_e( 'Фиксированная надбавка', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="number" step="0.01" min="0" name="fixed_markup" id="fixed_markup" value="<?php echo esc_attr( $fixed_markup ); ?>" class="small-text">
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
                                                        <input type="checkbox" name="round_prices" value="1" <?php checked( $round_prices ); ?>>
                                                        <?php esc_html_e( 'Округлять итоговую цену до целого числа (например, 13 549.78 → 13 550)', 'beestore-integration' ); ?>
                                                </label>
                                        </td>
                                </tr>
                        </table>
                </div>

                <!-- Вкладка: пример расчёта -->
                <div id="bsi-pricing-preview" class="bsi-pricing-tab" style="display:none;">
                        <div style="background:#e7f5ed;border:1px solid #46b450;border-radius:4px;padding:20px 25px;">
                                <h3 style="margin-top:0;"><?php esc_html_e( 'Пример расчёта цены товара', 'beestore-integration' ); ?></h3>
                                <p>
                                        <?php
                                        echo esc_html( sprintf(
                                                /* translators: 1: example price, 2: supplier currency */
                                                __( 'Товар в BeeStore стоит %1$s %2$s.', 'beestore-integration' ),
                                                $example_supplier,
                                                $supplier_currency
                                        ) );
                                        ?>
                                </p>
                                <p style="font-size:18px;">
                                        <?php esc_html_e( 'После конвертации в магазине:', 'beestore-integration' ); ?>
                                        <strong style="color:#2e7d32;font-size:22px;">
                                                <?php echo esc_html( number_format( $example_result, 2, ',', ' ' ) ); ?>
                                                <?php echo esc_html( $shop_currency ); ?>
                                        </strong>
                                </p>
                                <p style="font-size:13px;color:#666;font-family:monospace;">
                                        <?php
                                        printf(
                                                '%s × %s × %s + %s = %s',
                                                esc_html( $example_supplier ),
                                                esc_html( $currency_rate ),
                                                esc_html( $markup_coefficient ),
                                                esc_html( $fixed_markup ),
                                                esc_html( number_format( $example_result, 2, '.', '' ) )
                                        );
                                        ?>
                                </p>
                        </div>

                        <p style="margin-top:20px;color:#666;">
                                <?php esc_html_e( 'Измените параметры выше, сохраните — и при следующем импорте каталога все цены пересчитаются по новой формуле.', 'beestore-integration' ); ?>
                        </p>
                </div>

                <p style="margin-top:20px;">
                        <?php submit_button( __( 'Сохранить настройки', 'beestore-integration' ), 'primary', 'bsi_pricing_submit', false ); ?>
                </p>
        </form>
</div>

<script>
jQuery(document).ready(function($){
        // Переключение табов внутри страницы.
        $('.nav-tab-wrapper .nav-tab').on('click', function(e){
                e.preventDefault();
                var tab = $(this).data('tab');
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.bsi-pricing-tab').hide();
                $('#bsi-pricing-' + tab).show();
        });

        // Переключение режима (manual / auto).
        $('input[name="currency_rate_mode"]').on('change', function(){
                var mode = $(this).val();
                $('#bsi-manual-row').toggle('manual' === mode);
                $('#bsi-auto-row').toggle('auto' === mode);
        });

        // AJAX: ручное обновление курса.
        $('#bsi-refresh-rate').on('click', function(){
                var $btn = $(this);
                var $status = $('#bsi-refresh-status');
                $btn.prop('disabled', true);
                $status.html('<span class="spinner is-active" style="float:none;vertical-align:middle;"></span> <?php esc_html_e( 'Обновление...', 'beestore-integration' ); ?>');

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_refresh_rate',
                        nonce: bsiAdmin.nonce
                }, function(response){
                        $btn.prop('disabled', false);
                        if (response.success) {
                                var d = response.data;
                                $status.html('<span style="color:#2e7d32;">✓ ' + d.message + '</span>');
                                // Обновляем отображение курса.
                                $('#bsi-current-rate-display').text(parseFloat(d.rate).toFixed(4));
                                $('#bsi-rate-source-display').text(d.source);
                                $('#bsi-rate-updated-display').text(d.updated);
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
