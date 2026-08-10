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
        'cbrf'          => __( 'ЦБ РФ (только для RUB)', 'beestore-integration' ),
        'nbk'           => __( 'НБ Казахстана (для KZT)', 'beestore-integration' ),
        'ecb'           => __( 'Европейский ЦБ (ECB)', 'beestore-integration' ),
        'er_api'        => __( 'open.er-api.com (универсальный)', 'beestore-integration' ),
        'same_currency' => __( 'Валюты совпадают', 'beestore-integration' ),
);
$source_label = isset( $source_names[ $current_rate_info['source'] ] ) ? $source_names[ $current_rate_info['source'] ] : $current_rate_info['source'];

// Следующее авто-обновление (когда сработает cron).
$next_refresh = wp_next_scheduled( 'bsi_cron_refresh_rate' );

$currencies = array( 'EUR', 'USD', 'GBP', 'RUB', 'KZT', 'UAH', 'BYN', 'TRY', 'AMD', 'GEL', 'CHF', 'JPY', 'CNY' );

// Текущая валюта WooCommerce (для предупреждения о несовпадении).
$wc_currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
$currency_mismatch = $wc_currency && $wc_currency !== $shop_currency;

// Человекопонятные названия валют.
$currency_labels = array(
        'EUR' => __( 'EUR — Евро', 'beestore-integration' ),
        'USD' => __( 'USD — Доллар США', 'beestore-integration' ),
        'GBP' => __( 'GBP — Фунт стерлингов', 'beestore-integration' ),
        'RUB' => __( 'RUB — Российский рубль', 'beestore-integration' ),
        'KZT' => __( 'KZT — Казахстанский тенге', 'beestore-integration' ),
        'UAH' => __( 'UAH — Украинская гривна', 'beestore-integration' ),
        'BYN' => __( 'BYN — Белорусский рубль', 'beestore-integration' ),
        'TRY' => __( 'TRY — Турецкая лира', 'beestore-integration' ),
        'AMD' => __( 'AMD — Армянский драм', 'beestore-integration' ),
        'GEL' => __( 'GEL — Грузинский лари', 'beestore-integration' ),
        'CHF' => __( 'CHF — Швейцарский франк', 'beestore-integration' ),
        'JPY' => __( 'JPY — Японская иена', 'beestore-integration' ),
        'CNY' => __( 'CNY — Китайский юань', 'beestore-integration' ),
);

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

        <?php if ( $currency_mismatch ) : ?>
                <div class="notice notice-warning" style="border-left-color:#ffb900;">
                        <p>
                                <strong><?php esc_html_e( 'Внимание: валюта плагина не совпадает с валютой WooCommerce.', 'beestore-integration' ); ?></strong>
                        </p>
                        <p style="margin:8px 0;">
                                <?php
                                echo wp_kses_post( sprintf(
                                        /* translators: 1: WC currency, 2: plugin target currency */
                                        __( 'WooCommerce сейчас показывает цены в <strong>%1$s</strong>, а плагин конвертирует BeeStore-цены в <strong>%2$s</strong>.', 'beestore-integration' ),
                                        esc_html( $wc_currency ),
                                        esc_html( $shop_currency )
                                ) );
                                ?>
                        </p>
                        <p style="margin:8px 0;">
                                <?php
                                echo wp_kses_post( __( '<strong>Что это значит:</strong> числовые значения цен будут правильными (как рассчитано плагином), но WooCommerce добавит к ним символ/код своей валюты — то есть цены будут показаны как «12 450 ₸» вместо «12 450 ₽».', 'beestore-integration' ) );
                                ?>
                        </p>
                        <p style="margin:8px 0;">
                                <?php
                                echo wp_kses_post( __( '<strong>Как исправить отображение:</strong> переключите WooCommerce обратно в нужную валюту — <em>WooCommerce → Настройки → Основные → Валюта</em>. Плагин продолжит конвертировать в то, что выбрано в поле «Валюта магазина» ниже, независимо от настройки WooCommerce.', 'beestore-integration' ) );
                                ?>
                        </p>
                </div>
        <?php endif; ?>

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
                                                                <option value="<?php echo esc_attr( $cur ); ?>" <?php selected( $supplier_currency, $cur ); ?>>
                                                                        <?php echo esc_html( isset( $currency_labels[ $cur ] ) ? $currency_labels[ $cur ] : $cur ); ?>
                                                                </option>
                                                        <?php endforeach; ?>
                                                </select>
                                                <p class="description"><?php esc_html_e( 'Валюта, в которой BeeStore присылает цены (PrezzoIvato). Обычно EUR.', 'beestore-integration' ); ?></p>
                                        </td>
                                </tr>
                                <tr>
                                        <th><label for="shop_currency"><?php esc_html_e( 'Конвертировать в валюту', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <select name="shop_currency" id="shop_currency">
                                                        <?php foreach ( $currencies as $cur ) : ?>
                                                                <option value="<?php echo esc_attr( $cur ); ?>" <?php selected( $shop_currency, $cur ); ?>>
                                                                        <?php echo esc_html( isset( $currency_labels[ $cur ] ) ? $currency_labels[ $cur ] : $cur ); ?>
                                                                </option>
                                                        <?php endforeach; ?>
                                                </select>
                                                <p class="description">
                                                        <?php esc_html_e( 'Валюта, в которую плагин конвертирует цены при импорте. Это независимая настройка — она не зависит от того, какая валюта сейчас выбрана в WooCommerce.', 'beestore-integration' ); ?>
                                                        <?php if ( $wc_currency ) : ?>
                                                                <br>
                                                                <?php
                                                                echo wp_kses_post( sprintf(
                                                                        /* translators: 1: WC currency code */
                                                                        __( '<em>Сейчас в WooCommerce выбрано: %1$s.</em>', 'beestore-integration' ),
                                                                        '<strong>' . esc_html( $wc_currency ) . '</strong>'
                                                                ) );
                                                                ?>
                                                        <?php endif; ?>
                                                </p>
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
                                                                <?php esc_html_e( 'Авто (рекомендуется) — лучший источник по целевой валюте', 'beestore-integration' ); ?>
                                                        </option>
                                                        <option value="cbrf" <?php selected( $currency_rate_auto_source, 'cbrf' ); ?>>
                                                                <?php esc_html_e( 'ЦБ РФ (только для RUB)', 'beestore-integration' ); ?>
                                                        </option>
                                                        <option value="nbk" <?php selected( $currency_rate_auto_source, 'nbk' ); ?>>
                                                                <?php esc_html_e( 'НБ Казахстана (для KZT)', 'beestore-integration' ); ?>
                                                        </option>
                                                        <option value="ecb" <?php selected( $currency_rate_auto_source, 'ecb' ); ?>>
                                                                <?php esc_html_e( 'Европейский ЦБ (ECB)', 'beestore-integration' ); ?>
                                                        </option>
                                                        <option value="er_api" <?php selected( $currency_rate_auto_source, 'er_api' ); ?>>
                                                                <?php esc_html_e( 'open.er-api.com (универсальный fallback)', 'beestore-integration' ); ?>
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

        <!-- Блок пересчёта цен -->
        <div class="bsi-card" style="background:#fff;border:1px solid #e0e0e0;border-left:4px solid #2e7d32;padding:20px 24px;margin:30px 0 20px;">
                <h2 style="margin-top:0;color:#2e7d32;">
                        <?php esc_html_e( 'Пересчитать цены всех товаров', 'beestore-integration' ); ?>
                </h2>
                <p>
                        <?php esc_html_e( 'Применяет текущую формулу (курс × наценка + надбавка) ко всем товарам, импортированным из BeeStore. Полезно, когда:', 'beestore-integration' ); ?>
                </p>
                <ul style="list-style:disc;margin-left:25px;color:#444;">
                        <li><?php esc_html_e( 'Изменился курс валюты', 'beestore-integration' ); ?></li>
                        <li><?php esc_html_e( 'Поменяли коэффициент надбавки или фиксированную надбавку', 'beestore-integration' ); ?></li>
                        <li><?php esc_html_e( 'Включили/выключили округление цен', 'beestore-integration' ); ?></li>
                        <li><?php esc_html_e( 'Раньше цены не конвертировались (был курс = 1), а теперь задали правильный курс', 'beestore-integration' ); ?></li>
                </ul>
                <p style="background:#fff8e5;border:1px solid #ffb900;border-radius:3px;padding:10px 14px;margin:15px 0;">
                        <strong><?php esc_html_e( 'Важно:', 'beestore-integration' ); ?></strong>
                        <?php
                        echo wp_kses_post( __( 'Берутся <strong>оригинальные цены BeeStore</strong> (в EUR), сохранённые в мете товара при импорте. Если товар импортирован до версии v1.6.2 (когда эта мета не сохранялась) — пересчёт его пропустит. Для таких товаров запустите полный импорт каталога заново.', 'beestore-integration' ) );
                        ?>
                </p>
                <p>
                        <button type="button" class="button button-primary button-large" id="bsi-recalculate-prices">
                                <span class="dashicons dashicons-update" style="vertical-align:middle;"></span>
                                <?php esc_html_e( 'Пересчитать цены сейчас', 'beestore-integration' ); ?>
                        </button>
                        <span id="bsi-recalc-status" style="margin-left:15px;"></span>
                </p>

                <!-- Прогресс-бар -->
                <div id="bsi-recalc-progress" style="display:none;margin-top:20px;">
                        <div style="background:#f0f0f1;border-radius:3px;height:24px;overflow:hidden;">
                                <div id="bsi-recalc-progress-bar" style="background:#2e7d32;height:100%;width:0%;transition:width 0.3s;"></div>
                        </div>
                        <p style="margin-top:8px;color:#666;">
                                <span id="bsi-recalc-progress-text"><?php esc_html_e( 'Подготовка...', 'beestore-integration' ); ?></span>
                        </p>
                </div>

                <!-- Результат -->
                <div id="bsi-recalc-result" style="display:none;margin-top:20px;"></div>
        </div>
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

        // ─── Пересчёт цен всех товаров ──────────────────────────────────
        // Делаем батчевую обработку: 100 товаров за AJAX-запрос, потом следующий батч.
        var recalcState = {
                running: false,
                offset: 0,
                total: 0,
                success: 0,
                failed: 0,
                skipped: 0,
                errors: []
        };

        function recalcUpdateProgress() {
                var percent = recalcState.total > 0
                        ? Math.min(100, Math.round((recalcState.offset / recalcState.total) * 100))
                        : 0;
                $('#bsi-recalc-progress-bar').css('width', percent + '%');
                $('#bsi-recalc-progress-text').html(
                        '<?php esc_html_e( 'Обработано', 'beestore-integration' ); ?> ' +
                        recalcState.offset + ' / ' + recalcState.total +
                        ' (<?php esc_html_e( 'успешно', 'beestore-integration' ); ?>: ' + recalcState.success +
                        ', <?php esc_html_e( 'ошибок', 'beestore-integration' ); ?>: ' + recalcState.failed +
                        (recalcState.skipped > 0 ? ', <?php esc_html_e( 'пропущено', 'beestore-integration' ); ?>: ' + recalcState.skipped : '') +
                        ') — ' + percent + '%'
                );
        }

        function recalcFinish(success) {
                recalcState.running = false;
                $('#bsi-recalculate-prices').prop('disabled', false);
                $('#bsi-recalc-progress-bar').css('width', '100%');

                var html = '';
                if (success) {
                        html += '<div style="background:#e7f5ed;border:1px solid #46b450;border-radius:4px;padding:15px 20px;">';
                        html += '<h3 style="margin-top:0;color:#2e7d32;"><?php esc_html_e( 'Пересчёт завершён', 'beestore-integration' ); ?></h3>';
                } else {
                        html += '<div style="background:#fbeaea;border:1px solid #c62828;border-radius:4px;padding:15px 20px;">';
                        html += '<h3 style="margin-top:0;color:#c62828;"><?php esc_html_e( 'Пересчёт прерван', 'beestore-integration' ); ?></h3>';
                }
                html += '<p><strong><?php esc_html_e( 'Всего обработано:', 'beestore-integration' ); ?></strong> ' + recalcState.offset + ' <?php esc_html_e( 'товаров', 'beestore-integration' ); ?></p>';
                html += '<p><strong><?php esc_html_e( 'Успешно пересчитано:', 'beestore-integration' ); ?></strong> ' + recalcState.success + '</p>';
                if (recalcState.failed > 0) {
                        html += '<p style="color:#c62828;"><strong><?php esc_html_e( 'Ошибок:', 'beestore-integration' ); ?></strong> ' + recalcState.failed + '</p>';
                }
                if (recalcState.skipped > 0) {
                        html += '<p style="color:#b88000;"><strong><?php esc_html_e( 'Пропущено (нет оригинальной цены):', 'beestore-integration' ); ?></strong> ' + recalcState.skipped + '</p>';
                }
                if (recalcState.errors.length > 0) {
                        html += '<details style="margin-top:10px;"><summary><?php esc_html_e( 'Показать ошибки', 'beestore-integration' ); ?> (' + recalcState.errors.length + ')</summary>';
                        html += '<ul style="margin-top:8px;font-family:monospace;font-size:11px;color:#666;">';
                        for (var i = 0; i < recalcState.errors.length && i < 50; i++) {
                                html += '<li>' + $('<div>').text(recalcState.errors[i]).html() + '</li>';
                        }
                        html += '</ul></details>';
                }
                html += '</div>';
                $('#bsi-recalc-result').html(html).show();
                $('#bsi-recalc-progress-text').html('<?php esc_html_e( 'Готово.', 'beestore-integration' ); ?>');
        }

        function recalcBatch() {
                if (!recalcState.running) return;

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_recalculate_prices',
                        nonce: bsiAdmin.nonce,
                        offset: recalcState.offset,
                        batch: 100
                }, function(response) {
                        if (!response.success) {
                                $('#bsi-recalc-status').html('<span style="color:#c62828;">✗ ' + (response.data.message || 'Ошибка') + '</span>');
                                recalcFinish(false);
                                return;
                        }
                        var d = response.data;
                        recalcState.total    = d.total;
                        recalcState.offset   = d.next_offset;
                        recalcState.success  += d.success;
                        recalcState.failed   += d.failed;
                        recalcState.skipped  += d.skipped;
                        if (d.errors && d.errors.length) {
                                recalcState.errors = recalcState.errors.concat(d.errors);
                        }
                        recalcUpdateProgress();

                        if (d.has_more) {
                                // Следующий батч.
                                recalcBatch();
                        } else {
                                $('#bsi-recalc-status').html('<span style="color:#2e7d32;">✓ <?php esc_html_e( 'Готово', 'beestore-integration' ); ?></span>');
                                recalcFinish(true);
                        }
                }).fail(function(){
                        $('#bsi-recalc-status').html('<span style="color:#c62828;">✗ AJAX error</span>');
                        recalcFinish(false);
                });
        }

        $('#bsi-recalculate-prices').on('click', function(){
                var $btn = $(this);
                if (!confirm('<?php esc_html_e( 'Пересчитать цены всех импортированных товаров по текущей формуле? Это может занять несколько минут для больших каталогов.', 'beestore-integration' ); ?>')) {
                        return;
                }

                // Сброс состояния.
                recalcState = {
                        running: true,
                        offset: 0,
                        total: 0,
                        success: 0,
                        failed: 0,
                        skipped: 0,
                        errors: []
                };

                $btn.prop('disabled', true);
                $('#bsi-recalc-result').hide().empty();
                $('#bsi-recalc-progress').show();
                $('#bsi-recalc-progress-bar').css('width', '0%');
                $('#bsi-recalc-progress-text').text('<?php esc_html_e( 'Запуск...', 'beestore-integration' ); ?>');
                $('#bsi-recalc-status').html('<span class="spinner is-active" style="float:none;vertical-align:middle;"></span> <?php esc_html_e( 'Идёт пересчёт...', 'beestore-integration' ); ?>');

                recalcBatch();
        });
});
</script>
