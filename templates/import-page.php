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
					<span class="dashicons dashicons-controls-play" style="vertical-align:middle;margin-top:3px;"></span>
					<?php esc_html_e( 'Начать импорт', 'beestore-integration' ); ?>
				</button>

				<button type="button" class="button button-primary" id="bsi-btn-continue" <?php echo ( 'paused' === $state['status'] || 'error' === $state['status'] ) ? '' : 'disabled'; ?>>
					<span class="dashicons dashicons-controls-play" style="vertical-align:middle;margin-top:3px;"></span>
					<?php esc_html_e( 'Продолжить', 'beestore-integration' ); ?>
				</button>

				<button type="button" class="button button-secondary" id="bsi-btn-pause" <?php echo 'running' === $state['status'] ? '' : 'disabled'; ?>>
					<span class="dashicons dashicons-controls-pause" style="vertical-align:middle;margin-top:3px;"></span>
					<?php esc_html_e( 'Пауза', 'beestore-integration' ); ?>
				</button>

				<button type="button" class="button button-link-delete" id="bsi-btn-stop" <?php echo 'idle' === $state['status'] ? 'disabled' : ''; ?> onclick="return confirm('<?php esc_attr_e( 'Остановить импорт и сбросить прогресс?', 'beestore-integration' ); ?>')">
					<span class="dashicons dashicons-no-alt" style="vertical-align:middle;margin-top:3px;"></span>
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

	<!-- Докачать картинки (backfill) -->
	<div class="bsi-card">
		<h2><?php esc_html_e( 'Докачать картинки (backfill)', 'beestore-integration' ); ?></h2>
		<p>
			<?php esc_html_e( 'Если при импорте картинки не скачивались (Sirio блокировал доступ), но URL сохранены в meta товаров — можно докачать их сейчас.', 'beestore-integration' ); ?>
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
				appendLog('AJAX error — возможно PHP timeout. Подождите 10 сек и попробуйте ещё раз.', 'error');
				importRunning = false;
				stopBatchLoop();
				// Авто-возобновление через 10 секунд.
				setTimeout(function() {
					$.post(bsiAdmin.ajaxUrl, {
						action: 'bsi_import_status',
						nonce: bsiAdmin.nonce
					}, function(resp) {
						if (resp.success && resp.data.state.status === 'running') {
							appendLog('Авто-возобновление...', 'info');
							startBatchLoop();
						}
					});
				}, 10000);
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
	$('#bsi-btn-start').on('click', function() {
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

	// Backfill картинок.
	var backfillOffset = 0;
	$('#bsi-backfill-images').on('click', function(e) {
		e.preventDefault();
		var $btn = $(this);
		$btn.prop('disabled', true);
		$('#bsi-backfill-progress').show();
		backfillOffset = 0;
		$('#bsi-backfill-status').html('<span class="bsi-spinner"></span> Запуск...');
		runBackfill();
	});

	function runBackfill() {
		$.post(bsiAdmin.ajaxUrl, {
			action: 'bsi_backfill_images',
			nonce: bsiAdmin.nonce,
			offset: backfillOffset,
			batch_size: 20
		}, function(response) {
			if (response.success) {
				var d = response.data;
				$('#bsi-backfill-counter').text(d.next_offset + ' / ' + d.total);
				var percent = d.total > 0 ? Math.round((d.next_offset / d.total) * 100) : 100;
				$('.bsi-progress-fill').css('width', percent + '%');
				$('#bsi-backfill-status').html('<span style="color:#2e7d32;">✓ Успешно: ' + d.success + ', ошибок: ' + d.failed + '</span>');

				if (d.has_more) {
					backfillOffset = d.next_offset;
					runBackfill();
				} else {
					$('#bsi-backfill-images').prop('disabled', false);
					$('#bsi-backfill-status').html('<span style="color:#2e7d32;">✓ Готово! Всего: ' + d.total + ', скачано: ' + d.success + ', ошибок: ' + d.failed + '</span>');
				}
			} else {
				$('#bsi-backfill-images').prop('disabled', false);
				$('#bsi-backfill-status').html('<span style="color:#c62828;">✗ ' + (response.data.message || 'Ошибка') + '</span>');
			}
		}).fail(function() {
			$('#bsi-backfill-images').prop('disabled', false);
			$('#bsi-backfill-status').html('<span style="color:#c62828;">✗ AJAX error</span>');
		});
	}

	// Инициализация при загрузке страницы.
	initUI();
});
</script>
