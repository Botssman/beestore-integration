<?php
/**
 * Шаблон страницы ручного импорта.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}
?>

<div class="wrap">
        <h1><?php echo esc_html__( 'BeeStore — Импорт каталога', 'beestore-integration' ); ?></h1>

        <div class="bsi-card">
                <h2><?php esc_html_e( 'Импорт с FTP', 'beestore-integration' ); ?></h2>
                <p><?php esc_html_e( 'Плагин подключится к FTP, скачает самый свежий ZIP-файл BeeStore (COMPANY_*.zip), распакует и запустит импорт CSV в WooCommerce.', 'beestore-integration' ); ?></p>
                <p>
                        <button type="button" class="button button-primary button-large" id="bsi-import-ftp">
                                <span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span>
                                <?php esc_html_e( 'Импортировать с FTP', 'beestore-integration' ); ?>
                        </button>
                </p>
        </div>

        <div class="bsi-card">
                <h2><?php esc_html_e( 'Ручная загрузка CSV', 'beestore-integration' ); ?></h2>
                <p><?php esc_html_e( 'Если у вас уже есть распакованный CSV-файл BeeStore — загрузите его через Media Library и вставьте путь ниже.', 'beestore-integration' ); ?></p>
                <form id="bsi-import-manual">
                        <table class="form-table" role="presentation">
                                <tr>
                                        <th><label for="bsi-csv-path"><?php esc_html_e( 'Путь к CSV на сервере', 'beestore-integration' ); ?></label></th>
                                        <td>
                                                <input type="text" id="bsi-csv-path" class="large-text" placeholder="/var/www/wp-content/uploads/beestore/.../file.csv">
                                                <p class="description"><?php esc_html_e( 'Абсолютный путь к CSV-файлу на сервере.', 'beestore-integration' ); ?></p>
                                        </td>
                                </tr>
                        </table>
                        <p>
                                <button type="button" class="button button-primary button-large" id="bsi-import-csv">
                                        <?php esc_html_e( 'Импортировать CSV', 'beestore-integration' ); ?>
                                </button>
                        </p>
                </form>
        </div>

        <div class="bsi-card" id="bsi-import-status" style="display:none;">
                <h2><?php esc_html_e( 'Прогресс импорта', 'beestore-integration' ); ?></h2>
                <div class="bsi-progress-bar">
                        <div class="bsi-progress-fill" style="width:0%"></div>
                </div>
                <pre class="bsi-log-output" style="margin-top:15px;max-height:400px;overflow:auto;background:#1e1e1e;color:#0f0;padding:15px;border-radius:4px;"></pre>
        </div>

        <div class="bsi-card">
                <h2><?php esc_html_e( 'Докачать картинки (backfill)', 'beestore-integration' ); ?></h2>
                <p>
                        <?php esc_html_e( 'Если при импорте картинки не скачивались (например, Sirio блокировал доступ), но URL сохранены в meta товаров — можно докачать их сейчас.', 'beestore-integration' ); ?>
                </p>
                <p>
                        <?php esc_html_e( 'Плагин найдёт все товары без картинок и попробует скачать их по сохранённым URL. Работает пачками по 20 товаров за раз.', 'beestore-integration' ); ?>
                </p>
                <p>
                        <button type="button" class="button button-secondary" id="bsi-backfill-images">
                                <span class="dashicons dashicons-images-alt2" style="vertical-align:middle;margin-top:3px;"></span>
                                <?php esc_html_e( 'Докачать картинки', 'beestore-integration' ); ?>
                        </button>
                        <span id="bsi-backfill-status" style="margin-left:10px;"></span>
                </p>
                <div id="bsi-backfill-progress" style="display:none;margin-top:15px;">
                        <div class="bsi-progress-bar">
                                <div class="bsi-progress-fill" style="width:0%"></div>
                        </div>
                        <p style="margin-top:5px;font-size:12px;color:#666;">
                                <span id="bsi-backfill-counter">0 / 0</span>
                        </p>
                </div>
        </div>

        <div class="bsi-card">
                <h2><?php esc_html_e( 'Последний импорт', 'beestore-integration' ); ?></h2>
                <table class="widefat striped">
                        <tr>
                                <th><?php esc_html_e( 'ZIP-файл', 'beestore-integration' ); ?></th>
                                <td><code><?php echo esc_html( $last_zip ?: '—' ); ?></code></td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'Запущен', 'beestore-integration' ); ?></th>
                                <td><?php echo esc_html( $last_started ?: '—' ); ?></td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'Завершён', 'beestore-integration' ); ?></th>
                                <td><?php echo esc_html( $last_finished ?: '—' ); ?></td>
                        </tr>
                        <?php if ( ! empty( $last_report ) ) : ?>
                                <tr>
                                        <th><?php esc_html_e( 'Строк обработано', 'beestore-integration' ); ?></th>
                                        <td><?php echo esc_html( number_format_i18n( $last_report['rows_processed'] ?? 0 ) ); ?></td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Затрачено, сек', 'beestore-integration' ); ?></th>
                                        <td><?php echo esc_html( $last_report['elapsed_seconds'] ?? '—' ); ?></td>
                                </tr>
                        <?php endif; ?>
                </table>
        </div>
</div>
