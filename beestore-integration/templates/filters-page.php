<?php
/**
 * Шаблон страницы «Фильтры импорта».
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

$mode            = $filters['mode'];
$filter_cats     = $filters['categories'];
$filter_brands   = $filters['brands'];
$available_cats  = $available['categories'];
$available_brands = $available['brands'];

$settings = get_option( 'bsi_settings', array() );
$webp_enabled    = isset( $settings['webp_enabled'] ) && '1' === $settings['webp_enabled'] ? true : false;
$webp_strategy   = isset( $settings['webp_strategy'] ) ? $settings['webp_strategy'] : 3;
$webp_supports   = BSI_WebP::instance()->server_supports();
?>

<div class="wrap">
        <h1><?php echo esc_html__( 'BeeStore — Фильтры импорта', 'beestore-integration' ); ?></h1>

        <p class="description">
                <?php esc_html_e( 'Выберите какие категории и бренды импортировать, и укажите лимиты количества товаров.', 'beestore-integration' ); ?>
        </p>

        <!-- Шаг 1: Скачать CSV для анализа -->
        <div class="bsi-card" id="bsi-download-section">
                <h2><?php esc_html_e( 'Шаг 1: Скачать CSV для анализа', 'beestore-integration' ); ?></h2>
                <p>
                        <?php esc_html_e( 'Чтобы настроить фильтры, нужно сначала скачать CSV-файл с FTP Sirio. Плагин просканирует его и покажет список всех категорий и брендов.', 'beestore-integration' ); ?>
                </p>

                <?php if ( ! empty( $available_cats ) || ! empty( $available_brands ) ) : ?>
                        <p style="color:#2e7d32;font-weight:600;">
                                ✓ <?php esc_html_e( 'CSV уже скачан и отсканирован. Найдено категорий:', 'beestore-integration' ); ?>
                                <strong><?php echo esc_html( count( $available_cats ) ); ?></strong>,
                                <?php esc_html_e( 'брендов:', 'beestore-integration' ); ?>
                                <strong><?php echo esc_html( count( $available_brands ) ); ?></strong>
                        </p>
                        <p>
                                <button type="button" class="button button-secondary" id="bsi-download-csv">
                                        <span class="dashicons dashicons-update"></span>
                                        <?php esc_html_e( 'Скачать заново', 'beestore-integration' ); ?>
                                </button>
                                <span id="bsi-download-status" style="margin-left:10px;"></span>
                        </p>
                <?php else : ?>
                        <p style="color:#c62828;font-weight:600;">
                                ⚠ <?php esc_html_e( 'CSV ещё не скачан. Нажмите кнопку ниже:', 'beestore-integration' ); ?>
                        </p>
                        <p>
                                <button type="button" class="button button-primary button-large" id="bsi-download-csv">
                                        <span class="dashicons dashicons-download"></span>
                                        <?php esc_html_e( 'Скачать CSV с FTP', 'beestore-integration' ); ?>
                                </button>
                                <span id="bsi-download-status" style="margin-left:10px;"></span>
                        </p>
                <?php endif; ?>
        </div>

        <!-- Шаг 2: Настройка фильтров -->
        <?php if ( ! empty( $available_cats ) || ! empty( $available_brands ) ) : ?>

                <form method="post" action="options.php">
                        <?php settings_fields( 'bsi_settings_group' ); ?>

                        <!-- Режим фильтрации -->
                        <div class="bsi-card">
                                <h2><?php esc_html_e( 'Шаг 2: Режим фильтрации', 'beestore-integration' ); ?></h2>
                                <table class="form-table" role="presentation">
                                        <tr>
                                                <th><?php esc_html_e( 'Режим', 'beestore-integration' ); ?></th>
                                                <td>
                                                        <label style="display:block;margin-bottom:8px;">
                                                                <input type="radio" name="bsi_settings[import_filter_mode]" value="all" <?php checked( $mode, 'all' ); ?>>
                                                                <strong><?php esc_html_e( 'Импортировать всё', 'beestore-integration' ); ?></strong> —
                                                                <?php esc_html_e( 'без фильтров, все товары из CSV', 'beestore-integration' ); ?>
                                                        </label>
                                                        <label style="display:block;margin-bottom:8px;">
                                                                <input type="radio" name="bsi_settings[import_filter_mode]" value="whitelist" <?php checked( $mode, 'whitelist' ); ?>>
                                                                <strong><?php esc_html_e( 'Только выбранные (whitelist)', 'beestore-integration' ); ?></strong> —
                                                                <?php esc_html_e( 'импортировать только товары из выбранных категорий И брендов', 'beestore-integration' ); ?>
                                                        </label>
                                                        <label style="display:block;">
                                                                <input type="radio" name="bsi_settings[import_filter_mode]" value="blacklist" <?php checked( $mode, 'blacklist' ); ?>>
                                                                <strong><?php esc_html_e( 'Все кроме выбранных (blacklist)', 'beestore-integration' ); ?></strong> —
                                                                <?php esc_html_e( 'импортировать всё, КРОМЕ выбранных категорий и брендов', 'beestore-integration' ); ?>
                                                        </label>
                                                </td>
                                        </tr>
                                </table>
                        </div>

                        <!-- Категории -->
                        <div class="bsi-card">
                                <h2><?php esc_html_e( 'Шаг 3: Категории', 'beestore-integration' ); ?> (<?php echo esc_html( count( $available_cats ) ); ?>)</h2>
                                <p class="description">
                                        <?php esc_html_e( 'Лимит = максимальное количество РОДИТЕЛЬСКИХ товаров (карточек). 0 = без лимита.', 'beestore-integration' ); ?>
                                </p>
                                <table class="widefat striped" style="max-height:400px;overflow-y:auto;display:block;">
                                        <thead>
                                                <tr>
                                                        <th style="width:30px;"><?php esc_html_e( '✓', 'beestore-integration' ); ?></th>
                                                        <th><?php esc_html_e( 'Категория', 'beestore-integration' ); ?></th>
                                                        <th style="width:100px;"><?php esc_html_e( 'Строк в CSV', 'beestore-integration' ); ?></th>
                                                        <th style="width:120px;"><?php esc_html_e( 'Лимит товаров', 'beestore-integration' ); ?></th>
                                                </tr>
                                        </thead>
                                        <tbody>
                                                <?php foreach ( $available_cats as $cat_name => $count ) : ?>
                                                        <?php $is_selected = isset( $filter_cats[ $cat_name ] ); ?>
                                                        <tr>
                                                                <td>
                                                                        <input type="checkbox" name="bsi_settings[import_filter_categories][<?php echo esc_attr( $cat_name ); ?>]" value="<?php echo esc_attr( $is_selected ? $filter_cats[ $cat_name ] : '0' ); ?>" <?php checked( $is_selected ); ?>>
                                                                </td>
                                                                <td><strong><?php echo esc_html( $cat_name ); ?></strong></td>
                                                                <td><code><?php echo esc_html( $count ); ?></code></td>
                                                                <td>
                                                                        <input type="number" min="0" style="width:80px;"
                                                                                name="bsi_settings[import_filter_categories][<?php echo esc_attr( $cat_name ); ?>]"
                                                                                value="<?php echo esc_attr( $is_selected ? $filter_cats[ $cat_name ] : '' ); ?>"
                                                                                placeholder="0">
                                                                </td>
                                                        </tr>
                                                <?php endforeach; ?>
                                        </tbody>
                                </table>
                        </div>

                        <!-- Бренды -->
                        <div class="bsi-card">
                                <h2><?php esc_html_e( 'Шаг 4: Бренды', 'beestore-integration' ); ?> (<?php echo esc_html( count( $available_brands ) ); ?>)</h2>
                                <p class="description">
                                        <?php esc_html_e( 'Лимит = максимальное количество РОДИТЕЛЬСКИХ товаров (карточек). 0 = без лимита.', 'beestore-integration' ); ?>
                                </p>
                                <table class="widefat striped" style="max-height:400px;overflow-y:auto;display:block;">
                                        <thead>
                                                <tr>
                                                        <th style="width:30px;"><?php esc_html_e( '✓', 'beestore-integration' ); ?></th>
                                                        <th><?php esc_html_e( 'Бренд', 'beestore-integration' ); ?></th>
                                                        <th style="width:100px;"><?php esc_html_e( 'Строк в CSV', 'beestore-integration' ); ?></th>
                                                        <th style="width:120px;"><?php esc_html_e( 'Лимит товаров', 'beestore-integration' ); ?></th>
                                                </tr>
                                        </thead>
                                        <tbody>
                                                <?php foreach ( $available_brands as $brand_name => $count ) : ?>
                                                        <?php $is_selected = isset( $filter_brands[ $brand_name ] ); ?>
                                                        <tr>
                                                                <td>
                                                                        <input type="checkbox" name="bsi_settings[import_filter_brands][<?php echo esc_attr( $brand_name ); ?>]" value="<?php echo esc_attr( $is_selected ? $filter_brands[ $brand_name ] : '0' ); ?>" <?php checked( $is_selected ); ?>>
                                                                </td>
                                                                <td><strong><?php echo esc_html( $brand_name ); ?></strong></td>
                                                                <td><code><?php echo esc_html( $count ); ?></code></td>
                                                                <td>
                                                                        <input type="number" min="0" style="width:80px;"
                                                                                name="bsi_settings[import_filter_brands][<?php echo esc_attr( $brand_name ); ?>]"
                                                                                value="<?php echo esc_attr( $is_selected ? $filter_brands[ $brand_name ] : '' ); ?>"
                                                                                placeholder="0">
                                                                </td>
                                                        </tr>
                                                <?php endforeach; ?>
                                        </tbody>
                                </table>
                        </div>

                        <!-- WebP конвертация (внутри той же формы) -->
                        <div class="bsi-card">
                                <h2><?php esc_html_e( 'Конвертация картинок в WebP', 'beestore-integration' ); ?></h2>

                                <?php if ( ! $webp_supports ) : ?>
                                        <div class="notice notice-error">
                                                <p>
                                                        <strong><?php esc_html_e( 'Сервер не поддерживает WebP!', 'beestore-integration' ); ?></strong>
                                                        <?php esc_html_e( 'Нужно установить PHP-расширение Imagick или GD с поддержкой WebP. Обратитесь к хостинг-провайдеру.', 'beestore-integration' ); ?>
                                                </p>
                                        </div>
                                <?php else : ?>
                                        <table class="form-table" role="presentation">
                                                <tr>
                                                        <th><?php esc_html_e( 'Конвертировать в WebP', 'beestore-integration' ); ?></th>
                                                        <td>
                                                                <label>
                                                                        <input type="checkbox" name="bsi_settings[webp_enabled]" value="1" <?php checked( $webp_enabled ); ?>>
                                                                        <?php esc_html_e( 'При скачивании картинок конвертировать в WebP и удалять оригинал', 'beestore-integration' ); ?>
                                                                </label>
                                                                <p class="description">
                                                                        <?php esc_html_e( 'WebP сжимает картинки на 30-50% без потери качества. Экономит место на сервере и ускоряет загрузку сайта.', 'beestore-integration' ); ?>
                                                                </p>
                                                        </td>
                                                </tr>
                                                <tr>
                                                        <th><?php esc_html_e( 'Стратегия сжатия', 'beestore-integration' ); ?></th>
                                                        <td>
                                                                <select name="bsi_settings[webp_strategy]">
                                                                        <option value="1" <?php selected( $webp_strategy, 1 ); ?>><?php esc_html_e( '1 — Максимальная компрессия (минимальный размер)', 'beestore-integration' ); ?></option>
                                                                        <option value="2" <?php selected( $webp_strategy, 2 ); ?>><?php esc_html_e( '2 — Высокая компрессия', 'beestore-integration' ); ?></option>
                                                                        <option value="3" <?php selected( $webp_strategy, 3 ); ?>><?php esc_html_e( '3 — Сбалансированно (рекомендуется)', 'beestore-integration' ); ?></option>
                                                                        <option value="4" <?php selected( $webp_strategy, 4 ); ?>><?php esc_html_e( '4 — Высокое качество', 'beestore-integration' ); ?></option>
                                                                        <option value="5" <?php selected( $webp_strategy, 5 ); ?>><?php esc_html_e( '5 — Lossless (без потерь, максимальный размер)', 'beestore-integration' ); ?></option>
                                                                </select>
                                                        </td>
                                                </tr>
                                        </table>
                                <?php endif; ?>
                        </div>

                        <?php submit_button( __( 'Сохранить фильтры и настройки WebP', 'beestore-integration' ) ); ?>
                </form>

        <?php endif; ?>

        <!-- Инструкция -->
        <div class="bsi-card">
                <h3><?php esc_html_e( 'Как пользоваться фильтрами', 'beestore-integration' ); ?></h3>
                <ol>
                        <li><strong><?php esc_html_e( 'Скачать CSV', 'beestore-integration' ); ?></strong> — <?php esc_html_e( 'нажмите «Скачать CSV с FTP» вверху страницы', 'beestore-integration' ); ?></li>
                        <li><strong><?php esc_html_e( 'Выбрать режим', 'beestore-integration' ); ?></strong> — <?php esc_html_e( '«Импортировать всё», «Только выбранные» или «Все кроме»', 'beestore-integration' ); ?></li>
                        <li><strong><?php esc_html_e( 'Отметить категории и бренды', 'beestore-integration' ); ?></strong> — <?php esc_html_e( 'чекбоксами', 'beestore-integration' ); ?></li>
                        <li><strong><?php esc_html_e( 'Указать лимиты', 'beestore-integration' ); ?></strong> — <?php esc_html_e( '0 = без лимита, N = максимум N товаров', 'beestore-integration' ); ?></li>
                        <li><strong><?php esc_html_e( 'Сохранить фильтры', 'beestore-integration' ); ?></strong></li>
                        <li><strong><?php esc_html_e( 'Перейти в «Импорт каталога»', 'beestore-integration' ); ?></strong> — <?php esc_html_e( 'нажать «Начать импорт» — плагин импортирует только выбранное', 'beestore-integration' ); ?></li>
                </ol>
                <p class="description">
                        <strong><?php esc_html_e( 'Логика AND:', 'beestore-integration' ); ?></strong>
                        <?php esc_html_e( 'если выбраны и категории, и бренды — импортируются только товары, которые подходят И по категории, И по бренду.', 'beestore-integration' ); ?>
                </p>
        </div>
</div>

<script>
jQuery(document).ready(function($){
        $('#bsi-download-csv').on('click', function() {
                var $btn = $(this);
                var $status = $('#bsi-download-status');
                $btn.prop('disabled', true);
                $status.html('<span class="bsi-spinner"></span> Скачивание и сканирование CSV... (это может занять 1-3 минуты)');

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_download_csv_for_filters',
                        nonce: bsiAdmin.nonce
                }, function(response) {
                        if (response.success) {
                                $status.html('<span style="color:#2e7d32;">✓ ' + response.data.message + '</span>');
                                // Перезагружаем страницу чтобы показать списки категорий и брендов.
                                setTimeout(function() {
                                        location.reload();
                                }, 2000);
                        } else {
                                $btn.prop('disabled', false);
                                $status.html('<span style="color:#c62828;">✗ ' + (response.data.message || 'Ошибка') + '</span>');
                        }
                }).fail(function() {
                        $btn.prop('disabled', false);
                        $status.html('<span style="color:#c62828;">✗ AJAX error. Возможно, превышен max_execution_time — попробуйте ещё раз.</span>');
                });
        });
});
</script>
