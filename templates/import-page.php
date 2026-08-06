<?php
/**
 * Шаблон страницы ручного импорта с сохранением прогресса.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

// Текущее состояние импорта.
$state = BSI_Importer::instance()->get_import_state();
$last_report = get_option( 'bsi_last_import_report', array() );
$last_zip    = get_option( 'bsi_last_import_zip', '' );
$last_started = get_option( 'bsi_last_import_started', '' );
$last_finished = get_option( 'bsi_last_import_finished', '' );

// Рассчитываем процент.
$percent = $state['total_rows'] > 0
        ? round( ( $state['processed_rows'] / $state['total_rows'] ) * 100, 1 )
        : 0;

// Статус на русском.
$status_labels = array(
        'idle'      => __( 'Нет активного импорта', 'beestore-integration' ),
        'running'   => __( 'Идёт импорт...', 'beestore-integration' ),
        'paused'    => __( 'Приостановлен', 'beestore-integration' ),
        'completed' => __( 'Завершён', 'beestore-integration' ),
        'error'     => __( 'Ошибка', 'beestore-integration' ),
);
$status_label = isset( $status_labels[ $state['status'] ] ) ? $status_labels[ $state['status'] ] : $state['status'];

// Цвета статусов.
$status_colors = array(
        'idle'      => '#666',
        'running'   => '#2271b1',
        'paused'    => '#f57c00',
        'completed' => '#2e7d32',
        'error'     => '#c62828',
);
$status_color = isset( $status_colors[ $state['status'] ] ) ? $status_colors[ $state['status'] ] : '#666';
?>

<div class="wrap">
        <h1><?php echo esc_html__( 'BeeStore — Импорт каталога', 'beestore-integration' ); ?></h1>

        <!-- Фоновый cron-импорт: статус и управление -->
        <div class="bsi-card" id="bsi-cron-status-card" style="border-color:#2271b1;">
                <h2 style="display:flex;align-items:center;gap:8px;">
                        <span class="dashicons dashicons-clock"></span>
                        <?php esc_html_e( 'Фоновый импорт (Cron)', 'beestore-integration' ); ?>
                        <span id="bsi-cron-badge" style="background:#666;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;font-weight:600;">
                                <?php esc_html_e( 'загрузка...', 'beestore-integration' ); ?>
                        </span>
                </h2>
                <div id="bsi-cron-info" style="display:flex;gap:30px;flex-wrap:wrap;margin:10px 0;">
                        <div>
                                <strong><?php esc_html_e( 'Статус:', 'beestore-integration' ); ?></strong>
                                <span id="bsi-cron-running">—</span>
                        </div>
                        <div>
                                <strong><?php esc_html_e( 'Следующий запуск:', 'beestore-integration' ); ?></strong>
                                <span id="bsi-cron-next">—</span>
                        </div>
                        <div>
                                <strong><?php esc_html_e( 'Последний импорт:', 'beestore-integration' ); ?></strong>
                                <span id="bsi-cron-last">—</span>
                        </div>
                </div>
                <p>
                        <button type="button" class="button button-secondary" id="bsi-stop-cron-import"
                                onclick="return confirm('<?php esc_attr_e( 'Остановить фоновый импорт? Cron возобновит работу по расписанию.', 'beestore-integration' ); ?>')">
                                <span class="dashicons dashicons-controls-pause"></span>
                                <?php esc_html_e( 'Остановить фоновый импорт', 'beestore-integration' ); ?>
                        </button>
                        <span id="bsi-cron-stop-status" style="margin-left:10px;"></span>
                </p>
                <p class="description">
                        <?php esc_html_e( 'Cron автоматически запускает импорт по расписанию (раз в час). Кнопка выше останавливает текущий процесс, но не отключает cron — при следующем срабатывании импорт запустится снова.', 'beestore-integration' ); ?>
                </p>
        </div>

        <!-- Карточка: текущий статус импорта -->
        <div class="bsi-card" id="bsi-import-status-card">
                <h2>
                        <?php esc_html_e( 'Статус импорта', 'beestore-integration' ); ?>
                        <span id="bsi-status-badge" style="background:<?php echo esc_attr( $status_color ); ?>;color:#fff;padding:3px 10px;border-radius:3px;font-size:12px;font-weight:600;margin-left:10px;">
                                <?php echo esc_html( $status_label ); ?>
                        </span>
                </h2>

                <div id="bsi-import-empty" <?php echo 'idle' === $state['status'] ? '' : 'style="display:none;"'; ?>>
                        <p><?php esc_html_e( 'Импорт не запущен. Нажмите кнопку «Начать импорт» ниже, чтобы скачать файл с FTP Sirio и начать обработку.', 'beestore-integration' ); ?></p>
                        <p>
                                <button type="button" class="button button-primary button-large" id="bsi-btn-start-empty">
                                        <span class="dashicons dashicons-controls-play"></span>
                                        <?php esc_html_e( 'Начать импорт', 'beestore-integration' ); ?>
                                </button>
                        </p>
                </div>

                <div id="bsi-import-active" <?php echo 'idle' === $state['status'] ? 'style="display:none;"' : ''; ?>>
                        <table class="widefat striped" id="bsi-import-info-table">
                                <tr>
                                        <th style="width:200px;"><?php esc_html_e( 'Файл:', 'beestore-integration' ); ?></th>
                                        <td><code id="bsi-remote-name"><?php echo esc_html( $state['remote_name'] ?: '—' ); ?></code></td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Тип файла:', 'beestore-integration' ); ?></th>
                                        <td id="bsi-file-type">
                                                <?php echo $state['is_full_catalog'] ? '⭐ ' . esc_html__( 'Полный каталог', 'beestore-integration' ) : '📄 ' . esc_html__( 'Инкрементальный', 'beestore-integration' ); ?>
                                        </td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Запущен:', 'beestore-integration' ); ?></th>
                                        <td id="bsi-started-at"><?php echo esc_html( $state['started_at'] ?: '—' ); ?></td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Последнее обновление:', 'beestore-integration' ); ?></th>
                                        <td id="bsi-last-update"><?php echo esc_html( $state['last_update'] ?: '—' ); ?></td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Прогресс:', 'beestore-integration' ); ?></th>
                                        <td>
                                                <div class="bsi-progress-bar" style="margin:5px 0;">
                                                        <div class="bsi-progress-fill" id="bsi-progress-fill" style="width:<?php echo esc_attr( $percent ); ?>%;"></div>
                                                </div>
                                                <strong id="bsi-progress-text"><?php echo esc_html( number_format_i18n( $state['processed_rows'] ) . ' / ' . number_format_i18n( $state['total_rows'] ) . ' (' . $percent . '%)' ); ?></strong>
                                        </td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Создано товаров:', 'beestore-integration' ); ?></th>
                                        <td id="bsi-created-products"><?php echo esc_html( number_format_i18n( $state['created_products'] ) ); ?></td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Обновлено товаров:', 'beestore-integration' ); ?></th>
                                        <td id="bsi-updated-products"><?php echo esc_html( number_format_i18n( $state['updated_products'] ) ); ?></td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Ошибок:', 'beestore-integration' ); ?></th>
                                        <td>
                                                <span id="bsi-errors-count" style="color:<?php echo $state['errors_count'] > 0 ? '#c62828' : '#666'; ?>;font-weight:600;">
                                                        <?php echo esc_html( number_format_i18n( $state['errors_count'] ) ); ?>
                                                </span>
                                                <?php if ( ! empty( $state['last_error'] ) ) : ?>
                                                        <br><small style="color:#c62828;" id="bsi-last-error"><?php echo esc_html( $state['last_error'] ); ?></small>
                                                <?php endif; ?>
                                        </td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Затрачено времени:', 'beestore-integration' ); ?></th>
                                        <td id="bsi-elapsed"><?php echo esc_html( round( $state['elapsed_seconds'] ) . ' сек' ); ?></td>
                                </tr>
                        </table>

                        <!-- Кнопки управления -->
                        <p style="margin-top:15px;">
                                <button type="button" class="button button-primary" id="bsi-btn-start" <?php echo 'running' === $state['status'] ? 'disabled' : ''; ?>>
                                        <span class="dashicons dashicons-controls-play"></span>
                                        <?php esc_html_e( 'Начать импорт', 'beestore-integration' ); ?>
                                </button>

                                <button type="button" class="button button-primary" id="bsi-btn-continue" <?php echo ( 'paused' === $state['status'] || 'error' === $state['status'] ) ? '' : 'disabled'; ?>>
                                        <span class="dashicons dashicons-controls-play"></span>
                                        <?php esc_html_e( 'Продолжить', 'beestore-integration' ); ?>
                                </button>

                                <button type="button" class="button button-secondary" id="bsi-btn-pause" <?php echo 'running' === $state['status'] ? '' : 'disabled'; ?>>
                                        <span class="dashicons dashicons-controls-pause"></span>
                                        <?php esc_html_e( 'Пауза', 'beestore-integration' ); ?>
                                </button>

                                <button type="button" class="button button-link-delete" id="bsi-btn-stop" <?php echo 'idle' === $state['status'] ? 'disabled' : ''; ?> onclick="return confirm('<?php esc_attr_e( 'Остановить импорт и сбросить прогресс?', 'beestore-integration' ); ?>')">
                                        <span class="dashicons dashicons-no-alt"></span>
                                        <?php esc_html_e( 'Остановить и сбросить', 'beestore-integration' ); ?>
                                </button>
                        </p>

                        <!-- Лог в реальном времени -->
                        <div id="bsi-realtime-log" style="margin-top:15px;display:none;">
                                <h4><?php esc_html_e( 'Лог в реальном времени:', 'beestore-integration' ); ?></h4>
                                <pre class="bsi-log-output" style="max-height:200px;overflow:auto;background:#1e1e1e;color:#0f0;padding:10px;border-radius:4px;font-size:11px;"></pre>
                        </div>
                </div>
        </div>

        <!-- Инструкция -->
        <div class="bsi-card">
                <h2><?php esc_html_e( 'Как это работает', 'beestore-integration' ); ?></h2>
                <ol>
                        <li><?php esc_html_e( 'Нажмите «Начать импорт» — плагин скачает самый свежий файл _0000001.csv (полный каталог) с FTP Sirio', 'beestore-integration' ); ?></li>
                        <li><?php esc_html_e( 'Плагин обработает файл пачками по 50 строк. Прогресс сохраняется в БД.', 'beestore-integration' ); ?></li>
                        <li><?php esc_html_e( 'Можно в любой момент нажать «Пауза» — прогресс сохранится', 'beestore-integration' ); ?></li>
                        <li><?php esc_html_e( 'Можно закрыть браузер или перезагрузить страницу — прогресс не потеряется', 'beestore-integration' ); ?></li>
                        <li><?php esc_html_e( 'После перезагрузки нажмите «Продолжить» — импорт продолжится с того же места', 'beestore-integration' ); ?></li>
                        <li><?php esc_html_e( 'По завершении статус сменится на «Завершён», появятся товары в WooCommerce', 'beestore-integration' ); ?></li>
                </ol>
        </div>

        <!-- Импорт картинок -->
        <div class="bsi-card" id="bsi-image-import-card" style="border-color:#2271b1;">
                <h2 style="display:flex;align-items:center;gap:8px;">
                        <span class="dashicons dashicons-images-alt2"></span>
                        <?php esc_html_e( 'Импорт картинок', 'beestore-integration' ); ?>
                </h2>
                <p>
                        <?php esc_html_e( 'Скачать картинки для товаров, у которых они ещё не скачаны. URL картинок сохранены при основном импорте. Если картинка уже скачана — она будет применена без повторного скачивания. WebP конвертация выполняется по настройкам плагина.', 'beestore-integration' ); ?>
                </p>
                <p>
                        <button type="button" class="button button-primary" id="bsi-backfill-images">
                                <span class="dashicons dashicons-images-alt2"></span>
                                <?php esc_html_e( 'Начать импорт картинок', 'beestore-integration' ); ?>
                        </button>
                        <button type="button" class="button button-secondary" id="bsi-backfill-pause" style="display:none;">
                                <span class="dashicons dashicons-controls-pause"></span>
                                <?php esc_html_e( 'Пауза', 'beestore-integration' ); ?>
                        </button>
                        <button type="button" class="button button-secondary" id="bsi-backfill-resume" style="display:none;">
                                <span class="dashicons dashicons-controls-play"></span>
                                <?php esc_html_e( 'Продолжить', 'beestore-integration' ); ?>
                        </button>
                        <button type="button" class="button button-link-delete" id="bsi-backfill-stop" style="display:none;">
                                <span class="dashicons dashicons-no-alt"></span>
                                <?php esc_html_e( 'Остановить', 'beestore-integration' ); ?>
                        </button>
                        <span id="bsi-backfill-status" style="margin-left:10px;"></span>
                </p>
                <div id="bsi-backfill-progress" style="display:none;margin-top:15px;">
                        <div class="bsi-progress-bar" style="background:#f0f0f1;border-radius:3px;height:24px;overflow:hidden;">
                                <div class="bsi-progress-fill" style="background:#2271b1;height:100%;width:0%;transition:width 0.3s;"></div>
                        </div>
                        <p style="margin-top:8px;font-size:13px;color:#666;">
                                <span id="bsi-backfill-counter">0 / 0</span>
                        </p>
                        <p style="font-size:12px;color:#666;">
                                <?php esc_html_e( 'Скачано:', 'beestore-integration' ); ?>
                                <strong id="bsi-backfill-downloaded">0</strong>
                                | <?php esc_html_e( 'Уже есть:', 'beestore-integration' ); ?>
                                <strong id="bsi-backfill-skipped">0</strong>
                                | <?php esc_html_e( 'Ошибок:', 'beestore-integration' ); ?>
                                <strong id="bsi-backfill-failed">0</strong>
                        </p>
                </div>
        </div>

        <!-- Полная очистка -->
        <div class="bsi-card" style="border-color:#c62828;">
                <h2 style="color:#c62828;"><?php esc_html_e( '⚠ Опасная зона: полная очистка', 'beestore-integration' ); ?></h2>
                <p>
                        <?php esc_html_e( 'Удалить ВСЕ товары BeeStore (по meta _bsi_igu_articolo), все бренды, категории, цвета, размеры и другие атрибуты, созданные плагином.', 'beestore-integration' ); ?>
                </p>
                <p style="color:#c62828;font-weight:600;">
                        <?php esc_html_e( 'Это действие необратимо! Используйте, если импорт пошёл криво (например, бренды создались неправильно) и хотите начать с чистого листа.', 'beestore-integration' ); ?>
                </p>
                <p>
                        <button type="button" class="button button-link-delete" id="bsi-purge-all" onclick="return confirm('<?php esc_attr_e( 'Удалить ВСЕ товары BeeStore и атрибуты? Это необратимо!', 'beestore-integration' ); ?>')">
                                <span class="dashicons dashicons-trash"></span>
                                <?php esc_html_e( 'Удалить все товары и атрибуты BeeStore', 'beestore-integration' ); ?>
                        </button>
                        <span id="bsi-purge-status" style="margin-left:10px;"></span>
                </p>

                <hr style="margin:20px 0;border:none;border-top:1px solid #ddd;">

                <h3 style="color:#b88000;margin-top:20px;">
                        <span class="dashicons dashicons-images-alt2"></span>
                        <?php esc_html_e( 'Очистка только картинок', 'beestore-integration' ); ?>
                </h3>
                <p>
                        <?php esc_html_e( 'Удалить из Media Library все картинки, импортированные плагином BeeStore (по meta _bsi_imported_by и _bsi_image_basename).', 'beestore-integration' ); ?>
                </p>
                <p>
                        <?php esc_html_e( 'Полезно, когда накопились дубликаты (2000019154266_3.jpg, 2000019154266_3-1.jpg, 2000019154266_3-2.jpg …) или когда нужно пересоздать картинки с нуля после ошибки. Товары остаются на месте — следующий импорт заново скачает картинки.', 'beestore-integration' ); ?>
                </p>
                <p>
                        <button type="button" class="button button-secondary" id="bsi-purge-images" onclick="return confirm('<?php esc_attr_e( 'Удалить все картинки BeeStore из Media Library? Это необратимо!', 'beestore-integration' ); ?>')">
                                <span class="dashicons dashicons-trash"></span>
                                <?php esc_html_e( 'Удалить только картинки BeeStore', 'beestore-integration' ); ?>
                        </button>
                        <span id="bsi-purge-images-status" style="margin-left:10px;"></span>
                </p>
        </div>

        <!-- Последний импорт -->
        <div class="bsi-card">
                <h2><?php esc_html_e( 'Последний завершённый импорт', 'beestore-integration' ); ?></h2>
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
                                        <td><?php echo esc_html( round( $last_report['elapsed_seconds'] ?? 0 ) ); ?></td>
                                </tr>
                        <?php endif; ?>
                </table>
        </div>
</div>

<script>
jQuery(document).ready(function($){
        // Состояние импорта.
        var importRunning = false;
        var pollInterval = null;
        var batchInterval = null;

        // Инициализация UI на основе текущего состояния.
        function initUI() {
                var state = <?php echo wp_json_encode( $state ); ?>;
                updateUI(state, <?php echo $percent; ?>);

                if (state.status === 'running') {
                        startBatchLoop();
                        startPolling();
                }
        }

        // Обновление UI на основе состояния.
        function updateUI(state, percent) {
                var statusLabels = {
                        idle: 'Нет активного импорта',
                        running: 'Идёт импорт...',
                        paused: 'Приостановлен',
                        completed: 'Завершён',
                        error: 'Ошибка'
                };
                var statusColors = {
                        idle: '#666',
                        running: '#2271b1',
                        paused: '#f57c00',
                        completed: '#2e7d32',
                        error: '#c62828'
                };

                $('#bsi-status-badge')
                        .text(statusLabels[state.status] || state.status)
                        .css('background', statusColors[state.status] || '#666');

                if (state.status === 'idle') {
                        $('#bsi-import-empty').show();
                        $('#bsi-import-active').hide();
                        $('#bsi-btn-start').prop('disabled', false);
                        $('#bsi-btn-continue').prop('disabled', true);
                        $('#bsi-btn-pause').prop('disabled', true);
                        $('#bsi-btn-stop').prop('disabled', true);
                } else {
                        $('#bsi-import-empty').hide();
                        $('#bsi-import-active').show();
                        $('#bsi-btn-start').prop('disabled', state.status === 'running');
                        $('#bsi-btn-continue').prop('disabled', !(state.status === 'paused' || state.status === 'error'));
                        $('#bsi-btn-pause').prop('disabled', state.status !== 'running');
                        $('#bsi-btn-stop').prop('disabled', false);
                }

                // Обновляем поля.
                $('#bsi-remote-name').text(state.remote_name || '—');
                $('#bsi-started-at').text(state.started_at || '—');
                $('#bsi-last-update').text(state.last_update || '—');
                $('#bsi-created-products').text(state.created_products.toLocaleString('ru-RU'));
                $('#bsi-updated-products').text(state.updated_products.toLocaleString('ru-RU'));
                $('#bsi-errors-count').text(state.errors_count.toLocaleString('ru-RU'));
                $('#bsi-errors-count').css('color', state.errors_count > 0 ? '#c62828' : '#666');
                if (state.last_error) {
                        if ($('#bsi-last-error').length) {
                                $('#bsi-last-error').text(state.last_error);
                        } else {
                                $('#bsi-errors-count').parent().append('<br><small style="color:#c62828;" id="bsi-last-error">' + state.last_error + '</small>');
                        }
                }
                $('#bsi-elapsed').text(Math.round(state.elapsed_seconds) + ' сек');

                // Прогресс.
                var progressText = state.processed_rows.toLocaleString('ru-RU') + ' / ' + state.total_rows.toLocaleString('ru-RU') + ' (' + percent + '%)';
                $('#bsi-progress-text').text(progressText);
                $('#bsi-progress-fill').css('width', percent + '%');

                // Тип файла.
                var typeText = state.is_full_catalog ? '⭐ Полный каталог' : '📄 Инкрементальный';
                $('#bsi-file-type').text(typeText);
        }

        // Добавить строку в лог.
        function appendLog(message, level) {
                level = level || 'info';
                var $log = $('.bsi-log-output');
                if ($log.length === 0) return;
                var now = new Date().toLocaleTimeString();
                var $line = $('<div class="log-line log-' + level + '">[' + now + '] ' + message + '</div>');
                $log.append($line);
                $log.scrollTop($log[0].scrollHeight);
        }

        // Цикл обработки батчей.
        function startBatchLoop() {
                if (batchInterval) return;
                importRunning = true;
                $('#bsi-realtime-log').show();
                $('.bsi-log-output').empty();

                function processNext() {
                        if (!importRunning) return;

                        $.post(bsiAdmin.ajaxUrl, {
                                action: 'bsi_import_process_batch',
                                nonce: bsiAdmin.nonce
                        }, function(response) {
                                if (response.success) {
                                        var d = response.data;
                                        appendLog(d.message, 'info');
                                        updateUI(d.state, d.percent);

                                        if (d.finished) {
                                                importRunning = false;
                                                appendLog('✓ Импорт завершён!', 'info');
                                                stopBatchLoop();
                                                stopPolling();
                                        } else if (d.state.status === 'running') {
                                                // Продолжаем.
                                                setTimeout(processNext, 500);
                                        } else {
                                                // Пауза или другая остановка.
                                                importRunning = false;
                                                stopBatchLoop();
                                        }
                                } else {
                                        appendLog('Ошибка: ' + (response.data.message || 'unknown'), 'error');
                                        importRunning = false;
                                        stopBatchLoop();
                                }
                        }).fail(function() {
                                appendLog('AJAX error — возможно PHP timeout. Сбрасываю lock и возобновляю...', 'error');
                                importRunning = false;
                                stopBatchLoop();
                                // Сбрасываем lock от убитого PHP-процесса.
                                $.post(bsiAdmin.ajaxUrl, {
                                        action: 'bsi_stop_cron_import',
                                        nonce: bsiAdmin.nonce
                                }, function() {
                                        // Ждём 3 сек и возобновляем.
                                        setTimeout(function() {
                                                $.post(bsiAdmin.ajaxUrl, {
                                                        action: 'bsi_import_status',
                                                        nonce: bsiAdmin.nonce
                                                }, function(resp) {
                                                        if (resp.success && resp.data.state.status === 'running') {
                                                                appendLog('Авто-возобновление после таймаута...', 'info');
                                                                startBatchLoop();
                                                        } else if (resp.success) {
                                                                // Статус не 'running' — возможно completed или error.
                                                                appendLog('Импорт остановлен (статус: ' + resp.data.state.status + ')', 'info');
                                                        } else {
                                                                appendLog('Не удалось получить статус импорта', 'error');
                                                        }
                                                }).fail(function() {
                                                        appendLog('Не удалось возобновить. Обновите страницу и нажмите «Продолжить».', 'error');
                                                });
                                        }, 3000);
                                }).fail(function() {
                                        appendLog('Не удалось сбросить lock. Обновите страницу и нажмите «Продолжить».', 'error');
                                });
                        });
                }

                processNext();
        }

        function stopBatchLoop() {
                importRunning = false;
        }

        // Polling — обновление UI раз в 5 секунд (на случай если состояние изменилось в другой вкладке).
        function startPolling() {
                if (pollInterval) return;
                pollInterval = setInterval(function() {
                        $.post(bsiAdmin.ajaxUrl, {
                                action: 'bsi_import_status',
                                nonce: bsiAdmin.nonce
                        }, function(response) {
                                if (response.success) {
                                        updateUI(response.data.state, response.data.percent);
                                }
                        });
                }, 5000);
        }

        function stopPolling() {
                if (pollInterval) {
                        clearInterval(pollInterval);
                        pollInterval = null;
                }
        }

        // Обработчики кнопок.
        $('#bsi-btn-start, #bsi-btn-start-empty').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true);
                appendLog('Запуск импорта...', 'info');
                $('.bsi-log-output').empty();

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_import_start',
                        nonce: bsiAdmin.nonce
                }, function(response) {
                        if (response.success) {
                                appendLog(response.data.message, 'info');
                                updateUI(response.data.state, 0);
                                // Переключаемся с empty на active вид.
                                $('#bsi-import-empty').hide();
                                $('#bsi-import-active').show();
                                startBatchLoop();
                                startPolling();
                        } else {
                                appendLog('Ошибка: ' + (response.data.message || 'unknown'), 'error');
                                $btn.prop('disabled', false);
                        }
                }).fail(function() {
                        appendLog('AJAX error', 'error');
                        $btn.prop('disabled', false);
                });
        });

        $('#bsi-btn-continue').on('click', function() {
                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_import_continue',
                        nonce: bsiAdmin.nonce
                }, function(response) {
                        if (response.success) {
                                appendLog(response.data.message, 'info');
                                // Обновляем состояние.
                                $.post(bsiAdmin.ajaxUrl, {
                                        action: 'bsi_import_status',
                                        nonce: bsiAdmin.nonce
                                }, function(resp) {
                                        if (resp.success) {
                                                updateUI(resp.data.state, resp.data.percent);
                                                if (resp.data.state.status === 'running') {
                                                        startBatchLoop();
                                                        startPolling();
                                                }
                                        }
                                });
                        } else {
                                appendLog('Ошибка: ' + (response.data.message || 'unknown'), 'error');
                        }
                });
        });

        $('#bsi-btn-pause').on('click', function() {
                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_import_pause',
                        nonce: bsiAdmin.nonce
                }, function(response) {
                        if (response.success) {
                                appendLog(response.data.message, 'info');
                                importRunning = false;
                                stopBatchLoop();
                                // Обновляем UI.
                                $.post(bsiAdmin.ajaxUrl, {
                                        action: 'bsi_import_status',
                                        nonce: bsiAdmin.nonce
                                }, function(resp) {
                                        if (resp.success) {
                                                updateUI(resp.data.state, resp.data.percent);
                                        }
                                });
                        }
                });
        });

        $('#bsi-btn-stop').on('click', function() {
                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_import_stop',
                        nonce: bsiAdmin.nonce
                }, function(response) {
                        if (response.success) {
                                appendLog(response.data.message, 'info');
                                importRunning = false;
                                stopBatchLoop();
                                stopPolling();
                                // Сбрасываем UI.
                                var emptyState = {
                                        status: 'idle',
                                        remote_name: '',
                                        is_full_catalog: false,
                                        total_rows: 0,
                                        processed_rows: 0,
                                        started_at: '',
                                        last_update: '',
                                        elapsed_seconds: 0,
                                        errors_count: 0,
                                        last_error: '',
                                        created_products: 0,
                                        updated_products: 0
                                };
                                updateUI(emptyState, 0);
                                $('#bsi-realtime-log').hide();
                        }
                });
        });

        // ─── Импорт картинок ──────────────────────────────────────────
        var backfillRunning = false;

        $('#bsi-backfill-images').on('click', function(e) {
                e.preventDefault();
                backfillRunning = true;
                $(this).prop('disabled', true);
                $('#bsi-backfill-pause').show();
                $('#bsi-backfill-stop').show();
                $('#bsi-backfill-progress').show();
                $('#bsi-backfill-status').html('<span class="bsi-spinner"></span> Запуск...');
                runBackfill();
        });

        $('#bsi-backfill-pause').on('click', function(e) {
                e.preventDefault();
                $.post(bsiAdmin.ajaxUrl, { action: 'bsi_backfill_pause', nonce: bsiAdmin.nonce }, function() {
                        backfillRunning = false;
                        $('#bsi-backfill-pause').hide();
                        $('#bsi-backfill-resume').show();
                        $('#bsi-backfill-status').html('<span style="color:#f57c00;">⏸ На паузе</span>');
                });
        });

        $('#bsi-backfill-resume').on('click', function(e) {
                e.preventDefault();
                $.post(bsiAdmin.ajaxUrl, { action: 'bsi_backfill_resume', nonce: bsiAdmin.nonce }, function() {
                        backfillRunning = true;
                        $('#bsi-backfill-resume').hide();
                        $('#bsi-backfill-pause').show();
                        $('#bsi-backfill-status').html('<span style="color:#2271b1;">▶ Продолжение...</span>');
                        runBackfill();
                });
        });

        $('#bsi-backfill-stop').on('click', function(e) {
                e.preventDefault();
                if (!confirm('<?php esc_attr_e( 'Остановить импорт картинок? Прогресс будет сброшен.', 'beestore-integration' ); ?>')) return;
                $.post(bsiAdmin.ajaxUrl, { action: 'bsi_backfill_stop', nonce: bsiAdmin.nonce }, function() {
                        backfillRunning = false;
                        $('#bsi-backfill-images').prop('disabled', false);
                        $('#bsi-backfill-pause').hide();
                        $('#bsi-backfill-resume').hide();
                        $('#bsi-backfill-stop').hide();
                        $('#bsi-backfill-status').html('<span style="color:#c62828;">✗ Остановлено</span>');
                });
        });

        function runBackfill() {
                if (!backfillRunning) return;
                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_backfill_images',
                        nonce: bsiAdmin.nonce,
                        batch_size: 10
                }, function(response) {
                        if (!backfillRunning) return;
                        if (response.success) {
                                var d = response.data;
                                var percent = d.total > 0 ? Math.round((d.next_offset / d.total) * 100) : 100;
                                $('#bsi-backfill-counter').text(d.next_offset + ' / ' + d.total + ' (' + percent + '%)');
                                $('#bsi-backfill-progress .bsi-progress-fill').css('width', percent + '%');
                                $('#bsi-backfill-downloaded').text(d.downloaded);
                                $('#bsi-backfill-skipped').text(d.skipped);
                                $('#bsi-backfill-failed').text(d.failed);
                                $('#bsi-backfill-status').html('<span style="color:#2271b1;">▶ Идёт импорт... ' + percent + '%</span>');

                                if (d.has_more) {
                                        setTimeout(runBackfill, 500);
                                } else {
                                        backfillRunning = false;
                                        $('#bsi-backfill-images').prop('disabled', false);
                                        $('#bsi-backfill-pause').hide();
                                        $('#bsi-backfill-stop').hide();
                                        $('#bsi-backfill-status').html('<span style="color:#2e7d32;">✓ Готово! Скачано: ' + d.downloaded + ', пропущено: ' + d.skipped + ', ошибок: ' + d.failed + '</span>');
                                }
                        } else {
                                $('#bsi-backfill-status').html('<span style="color:#c62828;">✗ ' + (response.data.message || 'Ошибка') + '</span>');
                        }
                }).fail(function() {
                        if (!backfillRunning) return;
                        $('#bsi-backfill-status').html('<span style="color:#c62828;">AJAX timeout, возобновление через 3 сек...</span>');
                        setTimeout(runBackfill, 3000);
                });
        }

        // Полная очистка.
        $('#bsi-purge-all').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                $btn.prop('disabled', true);
                $('#bsi-purge-status').html('<span class="bsi-spinner"></span> Удаление...');

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_purge_all',
                        nonce: bsiAdmin.nonce
                }, function(response) {
                        $btn.prop('disabled', false);
                        if (response.success) {
                                $('#bsi-purge-status').html('<span style="color:#2e7d32;">✓ ' + response.data.message + '</span>');
                                // Сбрасываем UI импорта.
                                var emptyState = {
                                        status: 'idle',
                                        remote_name: '',
                                        is_full_catalog: false,
                                        total_rows: 0,
                                        processed_rows: 0,
                                        started_at: '',
                                        last_update: '',
                                        elapsed_seconds: 0,
                                        errors_count: 0,
                                        last_error: '',
                                        created_products: 0,
                                        updated_products: 0
                                };
                                updateUI(emptyState, 0);
                        } else {
                                $('#bsi-purge-status').html('<span style="color:#c62828;">✗ ' + (response.data.message || 'Ошибка') + '</span>');
                        }
                }).fail(function() {
                        $btn.prop('disabled', false);
                        $('#bsi-purge-status').html('<span style="color:#c62828;">✗ AJAX error</span>');
                });
        });

        // Очистка только картинок.
        $('#bsi-purge-images').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                $btn.prop('disabled', true);
                $('#bsi-purge-images-status').html('<span class="bsi-spinner"></span> Удаление картинок...');

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_purge_images',
                        nonce: bsiAdmin.nonce
                }, function(response) {
                        $btn.prop('disabled', false);
                        if (response.success) {
                                var d = response.data;
                                var html = '<span style="color:#2e7d32;">✓ ' + d.message + '</span>';
                                if (d.failed > 0) {
                                        html += ' <span style="color:#c62828;">(ошибок: ' + d.failed + ')</span>';
                                }
                                if (d.total_found === 0) {
                                        html = '<span style="color:#666;">Картинок BeeStore не найдено — удалять нечего.</span>';
                                }
                                $('#bsi-purge-images-status').html(html);
                        } else {
                                $('#bsi-purge-images-status').html('<span style="color:#c62828;">✗ ' + (response.data.message || 'Ошибка') + '</span>');
                        }
                }).fail(function() {
                        $btn.prop('disabled', false);
                        $('#bsi-purge-images-status').html('<span style="color:#c62828;">✗ AJAX error</span>');
                });
        });

        // Инициализация при загрузке страницы.
        initUI();

        // ─── Фоновый cron-импорт: статус и управление ─────────────────
        function updateCronStatus() {
                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_cron_import_status',
                        nonce: bsiAdmin.nonce
                }, function(response) {
                        if (!response.success) return;
                        var d = response.data;
                        var $badge = $('#bsi-cron-badge');
                        var $running = $('#bsi-cron-running');
                        var $next = $('#bsi-cron-next');
                        var $last = $('#bsi-cron-last');

                        if (d.is_running) {
                                $badge.text('▶ Идёт импорт').css('background', '#2271b1');
                                $running.html('<span style="color:#2271b1;font-weight:600;">▶ Идёт (' + d.lock_age + ' сек, PID ' + d.lock_pid + ')</span>');
                        } else if (d.stop_pending) {
                                $badge.text('⏸ Пауза').css('background', '#f57c00');
                                $running.html('<span style="color:#f57c00;font-weight:600;">⏸ Остановлен (cron возобновит)</span>');
                        } else {
                                $badge.text('✓ Ожидание').css('background', '#2e7d32');
                                $running.html('<span style="color:#2e7d32;">Ожидание расписания</span>');
                        }

                        if (d.next_cron) {
                                $next.html(d.next_cron + ' <small style="color:#666;">(через ' + d.next_cron_in + ')</small>');
                        } else {
                                $next.html('<span style="color:#c62828;">не запланирован</span>');
                        }

                        if (d.last_import) {
                                $last.html(d.last_import + (d.last_zip ? ' <small style="color:#666;">(' + d.last_zip + ')</small>' : ''));
                        } else {
                                $last.html('—');
                        }
                });
        }

        // Обновляем статус при загрузке.
        updateCronStatus();
        // И каждые 15 секунд.
        setInterval(updateCronStatus, 15000);

        // Кнопка "Остановить фоновый импорт".
        $('#bsi-stop-cron-import').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $status = $('#bsi-cron-stop-status');
                $btn.prop('disabled', true);
                $status.html('<span class="spinner is-active" style="float:none;vertical-align:middle;"></span> Остановка...');

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_stop_cron_import',
                        nonce: bsiAdmin.nonce
                }, function(response) {
                        $btn.prop('disabled', false);
                        if (response.success) {
                                $status.html('<span style="color:#2e7d32;">✓ ' + response.data.message + '</span>');
                                if (response.data.next_cron) {
                                        $status.append('<br><small style="color:#666;">Следующий запуск: ' + response.data.next_cron + '</small>');
                                }
                                updateCronStatus();
                        } else {
                                $status.html('<span style="color:#c62828;">✗ ' + (response.data.message || 'Ошибка') + '</span>');
                        }
                }).fail(function() {
                        $btn.prop('disabled', false);
                        $status.html('<span style="color:#c62828;">✗ AJAX error</span>');
                });
        });
});
</script>
