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
$webp_enabled    = isset( $settings['webp_enabled'] ) && '1' === $settings['webp_enabled'] ? true : false;
$webp_strategy   = isset( $settings['webp_strategy'] ) ? $settings['webp_strategy'] : 3;
$webp_supports   = BSI_WebP::instance()->server_supports();
?>

<div class="wrap">
	<h1><?php echo esc_html__( 'BeeStore — Фильтры импорта', 'beestore-integration' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Выберите какие категории и бренды импортировать, и укажите лимиты количества товаров.', 'beestore-integration' ); ?>
	</p>

	<?php if ( empty( $available_cats ) && empty( $available_brands ) ) : ?>
		<div class="notice notice-warning">
			<p>
				<?php esc_html_e( 'Нет скачанного CSV-файла для анализа. Сначала запустите импорт (BeeStore → Импорт каталога → Начать импорт) — плагин скачает CSV, и на этой странице появится список всех категорий и брендов.', 'beestore-integration' ); ?>
			</p>
		</div>
	<?php else : ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'bsi_settings_group' ); ?>

			<!-- Режим фильтрации -->
			<div class="bsi-card">
				<h2><?php esc_html_e( 'Режим фильтрации', 'beestore-integration' ); ?></h2>
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
				<h2><?php esc_html_e( 'Категории', 'beestore-integration' ); ?> (<?php echo esc_html( count( $available_cats ) ); ?>)</h2>
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
									<input type="checkbox" name="bsi_settings[import_filter_categories][<?php echo esc_attr( $cat_name ); ?>]" value="0" <?php checked( $is_selected ); ?>>
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
				<h2><?php esc_html_e( 'Бренды', 'beestore-integration' ); ?> (<?php echo esc_html( count( $available_brands ) ); ?>)</h2>
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
									<input type="checkbox" name="bsi_settings[import_filter_brands][<?php echo esc_attr( $brand_name ); ?>]" value="0" <?php checked( $is_selected ); ?>>
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

			<?php submit_button( __( 'Сохранить фильтры', 'beestore-integration' ) ); ?>
		</form>

	<?php endif; ?>

	<!-- WebP конвертация -->
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
			<form method="post" action="options.php">
				<?php settings_fields( 'bsi_settings_group' ); ?>
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
							<p class="description">
								<?php esc_html_e( 'Чем выше число — тем лучше качество, но больше размер файла.', 'beestore-integration' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Сохранить настройки WebP', 'beestore-integration' ) ); ?>
			</form>
		<?php endif; ?>
	</div>

	<!-- Инструкция -->
	<div class="bsi-card">
		<h3><?php esc_html_e( 'Как пользоваться фильтрами', 'beestore-integration' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Запустите импорт (BeeStore → Импорт → Начать импорт) — плагин скачает CSV с FTP', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Вернитесь на эту страницу — появятся списки всех категорий и брендов из CSV', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Выберите режим: «Импортировать всё», «Только выбранные» или «Все кроме»', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Отметьте нужные категории и бренды чекбоксами', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Укажите лимиты (0 = без лимита, N = максимум N товаров)', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Нажмите «Сохранить фильтры»', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'В BeeStore → Импорт → нажмите «Остановить и сбросить», затем «Начать импорт» заново', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Плагин импортирует только выбранные товары с учётом лимитов', 'beestore-integration' ); ?></li>
		</ol>
		<p class="description">
			<strong><?php esc_html_e( 'Логика AND:', 'beestore-integration' ); ?></strong>
			<?php esc_html_e( 'если выбраны и категории, и бренды — импортируются только товары, которые подходят И по категории, И по бренду.', 'beestore-integration' ); ?>
		</p>
	</div>
</div>
