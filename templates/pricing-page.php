<?php
/**
 * Шаблон страницы «Конвертация цен».
 *
 * Только новая логика: курс евро (ручной ввод) + минимальная доходность.
 * Никакого автообновления курса.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

$settings = get_option( 'bsi_settings', array() );

// Настройки новой логики цен.
$pricing_settings = class_exists( 'BSI_Pricing' ) ? BSI_Pricing::instance()->get_settings() : array(
        'min_income' => 0,
        'eur_rate'   => 100,
);
?>

<div class="wrap">
        <h1><?php echo esc_html__( 'BeeStore — Конвертация цен', 'beestore-integration' ); ?></h1>

        <div class="notice notice-info" style="border-left-color:#2271b1;">
                <p>
                        <strong><?php esc_html_e( 'Цены считаются по новой логике при каждом импорте.', 'beestore-integration' ); ?></strong>
                        <?php esc_html_e( 'Курс евро вводится вручную. Автообновление курса удалено.', 'beestore-integration' ); ?>
                </p>
        </div>

        <form method="post" action="">
                <?php wp_nonce_field( 'bsi_pricing_save', 'bsi_pricing_nonce' ); ?>

                <table class="form-table" role="presentation">
                        <tr>
                                <th><label for="pricing_eur_rate"><?php esc_html_e( 'Курс евро (₽ за 1 €)', 'beestore-integration' ); ?></label></th>
                                <td>
                                        <input type="number" step="0.01" min="0" name="pricing_eur_rate" id="pricing_eur_rate" value="<?php echo esc_attr( $pricing_settings['eur_rate'] ); ?>" class="small-text">
                                        <p class="description"><?php esc_html_e( 'Сколько рублей в одном евро. Умножается на цену BeeStore для получения цены в рублях. Только ручной ввод.', 'beestore-integration' ); ?></p>
                                </td>
                        </tr>
                        <tr>
                                <th><label for="pricing_min_income"><?php esc_html_e( 'Минимальная доходность (€ с товара)', 'beestore-integration' ); ?></label></th>
                                <td>
                                        <input type="number" step="0.01" min="0" name="pricing_min_income" id="pricing_min_income" value="<?php echo esc_attr( $pricing_settings['min_income'] ); ?>" class="small-text">
                                        <p class="description"><?php esc_html_e( 'Минимальная наценка на товар в евро: если розничная цена ниже (закупка + доходность), цена поднимается.', 'beestore-integration' ); ?></p>
                                </td>
                        </tr>
                </table>

                <p style="background:#f0f6ff;padding:12px 16px;border-radius:3px;">
                        <?php esc_html_e( 'Формула расчёта: пол цены = (закупка + мин. доходность) × 1.22. База = max(РРЦ, пол). Скидка ≤ 50% скидки поставщика, ступень — 5%. Цена в ₽ округляется до 100 вверх.', 'beestore-integration' ); ?>
                </p>

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
                        <?php esc_html_e( 'Применяет текущие настройки (курс евро и мин. доходность) ко всем товарам. Полезно, когда изменили курс или мин. доходность.', 'beestore-integration' ); ?>
                </p>
                <p style="background:#fff8e5;border:1px solid #ffb900;border-radius:3px;padding:10px 14px;margin:15px 0;">
                        <strong><?php esc_html_e( 'Важно:', 'beestore-integration' ); ?></strong>
                        <?php
                        echo wp_kses_post( __( 'Берутся <strong>оригинальные цены BeeStore</strong> (в EUR), сохранённые в мете товара при импорте. Если товар импортирован до версии, где мета не сохранялась — пересчёт его пропустит. Для таких товаров запустите полный импорт каталога заново.', 'beestore-integration' ) );
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
        // ─── Пересчёт цен всех товаров ──────────────────────────────────
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