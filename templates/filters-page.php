<?php
/**
 * Шаблон страницы «Фильтры импорта».
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mode          = $filters['mode'];
$filter_cats   = $filters['categories'];
$filter_brands = $filters['brands'];

$webp_enabled  = isset( $settings['webp_enabled'] ) && '1' === $settings['webp_enabled'] ? true : false;
$webp_strategy = isset( $settings['webp_strategy'] ) ? $settings['webp_strategy'] : 3;
$webp_supports = BSI_WebP::instance()->server_supports();

// Результаты сканирования.
$scan_macro  = isset( $scan['macro'] ) ? $scan['macro'] : array();
$scan_sub    = isset( $scan['sub'] ) ? $scan['sub'] : array();
$scan_brands = isset( $scan['brands'] ) ? $scan['brands'] : array();
ksort( $scan_macro );
ksort( $scan_sub );
ksort( $scan_brands );
$has_scan = ! empty( $scan_macro ) || ! empty( $scan_brands );
?>

<div class="wrap">
	<h1><?php echo esc_html__( 'BeeStore — Фильтры импорта', 'beestore-integration' ); ?></h1>

	<!-- Шаг 1: Сканировать CSV -->
	<div class="bsi-card" id="bsi-scan-section">
		<h2><?php esc_html_e( 'Шаг 1: Сканировать CSV', 'beestore-integration' ); ?></h2>
		<p>
			<?php esc_html_e( 'Нажмите кнопку, чтобы плагин собрал список всех категорий и брендов из CSV. Это займёт 1-2 минуты.', 'beestore-integration' ); ?>
		</p>
		<p>
			<button type="button" class="button button-primary button-large" id="bsi-scan-start">
				<span class="dashicons dashicons-search"></span>
				<?php echo $has_scan ? esc_html__( 'Сканировать заново', 'beestore-integration' ) : esc_html__( 'Сканировать CSV', 'beestore-integration' ); ?>
			</button>
			<span id="bsi-scan-status" style="margin-left:10px;"></span>
		</p>
		<div id="bsi-scan-progress" style="display:none;margin-top:15px;max-width:500px;">
			<div class="bsi-progress-bar">
				<div class="bsi-progress-fill" style="width:0%"></div>
			</div>
			<p style="margin-top:5px;font-size:12px;color:#666;">
				<?php esc_html_e( 'Сканирование...', 'beestore-integration' ); ?> <span id="bsi-scan-counter">0 / 0</span>
			</p>
		</div>
	</div>

	<?php if ( $has_scan ) : ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'bsi_settings_group' ); ?>

			<!-- Режим -->
			<div class="bsi-card">
				<h2><?php esc_html_e( 'Шаг 2: Режим фильтрации', 'beestore-integration' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Режим', 'beestore-integration' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:8px;">
								<input type="radio" name="bsi_settings[import_filter_mode]" value="all" <?php checked( $mode, 'all' ); ?>>
								<strong><?php esc_html_e( 'Импортировать всё', 'beestore-integration' ); ?></strong>
							</label>
							<label style="display:block;margin-bottom:8px;">
								<input type="radio" name="bsi_settings[import_filter_mode]" value="whitelist" <?php checked( $mode, 'whitelist' ); ?>>
								<strong><?php esc_html_e( 'Только выбранные (whitelist)', 'beestore-integration' ); ?></strong> — <?php esc_html_e( 'импортировать только отмеченные ниже категории И бренды', 'beestore-integration' ); ?>
							</label>
							<label style="display:block;">
								<input type="radio" name="bsi_settings[import_filter_mode]" value="blacklist" <?php checked( $mode, 'blacklist' ); ?>>
								<strong><?php esc_html_e( 'Все кроме выбранных (blacklist)', 'beestore-integration' ); ?></strong>
							</label>
						</td>
					</tr>
				</table>
			</div>

			<!-- Макро-категории -->
			<?php if ( ! empty( $scan_macro ) ) : ?>
			<div class="bsi-card">
				<h2><?php esc_html_e( 'Макро-категории', 'beestore-integration' ); ?> (<?php echo esc_html( count( $scan_macro ) ); ?>)</h2>
				<p style="margin-bottom:10px;">
					<button type="button" class="button button-small bsi-select-all" data-target="bsi-macro-table"><?php esc_html_e( 'Выбрать все', 'beestore-integration' ); ?></button>
					<button type="button" class="button button-small bsi-deselect-all" data-target="bsi-macro-table"><?php esc_html_e( 'Снять выделение', 'beestore-integration' ); ?></button>
				</p>
				<table class="widefat striped" id="bsi-macro-table">
					<thead>
						<tr>
							<th style="width:30px;">✓</th>
							<th><?php esc_html_e( 'Категория', 'beestore-integration' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Строк', 'beestore-integration' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Лимит', 'beestore-integration' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $scan_macro as $name => $count ) : ?>
							<?php $is_selected = isset( $filter_cats[ $name ] ); ?>
							<tr>
								<td><input type="checkbox" name="bsi_settings[filter_cat_check][<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( $is_selected ); ?>></td>
								<td><strong><?php echo esc_html( $name ); ?></strong></td>
								<td><code><?php echo esc_html( $count ); ?></code></td>
								<td><input type="number" min="0" style="width:80px;" name="bsi_settings[filter_cat_limit][<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( $is_selected ? $filter_cats[ $name ] : '' ); ?>" placeholder="0"></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<!-- Подкатегории -->
			<?php if ( ! empty( $scan_sub ) ) : ?>
			<div class="bsi-card">
				<h2><?php esc_html_e( 'Подкатегории', 'beestore-integration' ); ?> (<?php echo esc_html( count( $scan_sub ) ); ?>)</h2>
				<p style="margin-bottom:10px;">
					<button type="button" class="button button-small bsi-select-all" data-target="bsi-sub-table"><?php esc_html_e( 'Выбрать все', 'beestore-integration' ); ?></button>
					<button type="button" class="button button-small bsi-deselect-all" data-target="bsi-sub-table"><?php esc_html_e( 'Снять выделение', 'beestore-integration' ); ?></button>
				</p>
				<div style="max-height:500px;overflow-y:auto;">
				<table class="widefat striped" id="bsi-sub-table">
					<thead>
						<tr>
							<th style="width:30px;">✓</th>
							<th><?php esc_html_e( 'Подкатегория', 'beestore-integration' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Родитель', 'beestore-integration' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Строк', 'beestore-integration' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Лимит', 'beestore-integration' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $scan_sub as $name => $info ) : ?>
							<?php $is_selected = isset( $filter_cats[ $name ] ); ?>
							<tr>
								<td><input type="checkbox" name="bsi_settings[filter_cat_check][<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( $is_selected ); ?>></td>
								<td><strong><?php echo esc_html( $name ); ?></strong></td>
								<td><small style="color:#666;"><?php echo esc_html( $info['parent'] ?: '—' ); ?></small></td>
								<td><code><?php echo esc_html( $info['count'] ); ?></code></td>
								<td><input type="number" min="0" style="width:80px;" name="bsi_settings[filter_cat_limit][<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( $is_selected ? $filter_cats[ $name ] : '' ); ?>" placeholder="0"></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</div>
			<?php endif; ?>

			<!-- Бренды -->
			<?php if ( ! empty( $scan_brands ) ) : ?>
			<div class="bsi-card">
				<h2><?php esc_html_e( 'Бренды', 'beestore-integration' ); ?> (<?php echo esc_html( count( $scan_brands ) ); ?>)</h2>
				<p style="margin-bottom:10px;">
					<button type="button" class="button button-small bsi-select-all" data-target="bsi-brand-table"><?php esc_html_e( 'Выбрать все', 'beestore-integration' ); ?></button>
					<button type="button" class="button button-small bsi-deselect-all" data-target="bsi-brand-table"><?php esc_html_e( 'Снять выделение', 'beestore-integration' ); ?></button>
				</p>
				<div style="max-height:500px;overflow-y:auto;">
				<table class="widefat striped" id="bsi-brand-table">
					<thead>
						<tr>
							<th style="width:30px;">✓</th>
							<th><?php esc_html_e( 'Бренд', 'beestore-integration' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Строк', 'beestore-integration' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Лимит', 'beestore-integration' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $scan_brands as $name => $count ) : ?>
							<?php $is_selected = isset( $filter_brands[ $name ] ); ?>
							<tr>
								<td><input type="checkbox" name="bsi_settings[filter_brand_check][<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( $is_selected ); ?>></td>
								<td><strong><?php echo esc_html( $name ); ?></strong></td>
								<td><code><?php echo esc_html( $count ); ?></code></td>
								<td><input type="number" min="0" style="width:80px;" name="bsi_settings[filter_brand_limit][<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( $is_selected ? $filter_brands[ $name ] : '' ); ?>" placeholder="0"></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</div>
			<?php endif; ?>

			<!-- WebP -->
			<div class="bsi-card">
				<h2><?php esc_html_e( 'Конвертация картинок в WebP', 'beestore-integration' ); ?></h2>
				<?php if ( ! $webp_supports ) : ?>
					<div class="notice notice-error"><p><strong><?php esc_html_e( 'Сервер не поддерживает WebP!', 'beestore-integration' ); ?></strong></p></div>
				<?php else : ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><?php esc_html_e( 'Конвертировать в WebP', 'beestore-integration' ); ?></th>
							<td><label><input type="checkbox" name="bsi_settings[webp_enabled]" value="1" <?php checked( $webp_enabled ); ?>> <?php esc_html_e( 'Конвертировать и удалить оригинал', 'beestore-integration' ); ?></label></td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Стратегия', 'beestore-integration' ); ?></th>
							<td>
								<select name="bsi_settings[webp_strategy]">
									<option value="1" <?php selected( $webp_strategy, 1 ); ?>>1 — Макс. компрессия</option>
									<option value="2" <?php selected( $webp_strategy, 2 ); ?>>2 — Высокая компрессия</option>
									<option value="3" <?php selected( $webp_strategy, 3 ); ?>>3 — Сбалансированно (рекомендуется)</option>
									<option value="4" <?php selected( $webp_strategy, 4 ); ?>>4 — Высокое качество</option>
									<option value="5" <?php selected( $webp_strategy, 5 ); ?>>5 — Lossless</option>
								</select>
							</td>
						</tr>
					</table>
				<?php endif; ?>
			</div>

			<?php submit_button( __( 'Сохранить фильтры', 'beestore-integration' ) ); ?>
		</form>
	<?php else : ?>
		<div class="bsi-card">
			<p style="color:#c62828;font-weight:600;">
				⚠ <?php esc_html_e( 'Список категорий и брендов пуст. Сначала нажмите «Сканировать CSV».', 'beestore-integration' ); ?>
			</p>
		</div>
	<?php endif; ?>
</div>

<script>
jQuery(document).ready(function($){
	$('#bsi-scan-start').on('click', function() {
		var $btn = $(this);
		var $status = $('#bsi-scan-status');
		var $progress = $('#bsi-scan-progress');
		var $counter = $('#bsi-scan-counter');
		var $fill = $progress.find('.bsi-progress-fill');

		$btn.prop('disabled', true);
		$status.html('');
		$progress.show();

		$.post(bsiAdmin.ajaxUrl, {
			action: 'bsi_scan_start',
			nonce: bsiAdmin.nonce
		}, function(res) {
			if (res && res.success) {
				$status.html('<span style="color:#2271b1;">' + res.data.message + '</span>');
				runScanStep();
			} else {
				$btn.prop('disabled', false);
				$progress.hide();
				var msg = (res && res.data && res.data.message) ? res.data.message : 'Ошибка';
				$status.html('<span style="color:#c62828;">✗ ' + msg + '</span>');
			}
		}).fail(function() {
			$btn.prop('disabled', false);
			$progress.hide();
			$status.html('<span style="color:#c62828;">✗ AJAX error</span>');
		});

		function runScanStep() {
			$.post(bsiAdmin.ajaxUrl, {
				action: 'bsi_scan_step',
				nonce: bsiAdmin.nonce
			}, function(res) {
				if (res && res.success) {
					var d = res.data;
					var percent = d.total > 0 ? Math.round((d.processed / d.total) * 100) : 100;
					$counter.text(d.processed + ' / ' + d.total);
					$fill.css('width', percent + '%');

					if (d.done) {
						$status.html('<span style="color:#2e7d32;">✓ Сканирование завершено. Перезагрузка...</span>');
						setTimeout(function() { location.reload(); }, 1500);
					} else {
						runScanStep();
					}
				} else {
					$btn.prop('disabled', false);
					$progress.hide();
					var msg = (res && res.data && res.data.message) ? res.data.message : 'Ошибка';
					$status.html('<span style="color:#c62828;">✗ ' + msg + '</span>');
				}
			}).fail(function() {
				$btn.prop('disabled', false);
				$progress.hide();
				$status.html('<span style="color:#c62828;">✗ AJAX error</span>');
			});
		}
	});

	// Select All / Deselect All.
	$('.bsi-select-all').on('click', function() {
		$('#' + $(this).data('target') + ' input[type="checkbox"]').prop('checked', true);
	});
	$('.bsi-deselect-all').on('click', function() {
		$('#' + $(this).data('target') + ' input[type="checkbox"]').prop('checked', false);
	});
});
</script>
