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

// Преобразуем массивы в текст для текстовых полей.
$cats_text = '';
if ( ! empty( $filter_cats ) ) {
	$lines = array();
	foreach ( $filter_cats as $name => $limit ) {
		if ( $limit > 0 ) {
			$lines[] = $name . '|' . $limit;
		} else {
			$lines[] = $name;
		}
	}
	$cats_text = implode( "\n", $lines );
}

$brands_text = '';
if ( ! empty( $filter_brands ) ) {
	$lines = array();
	foreach ( $filter_brands as $name => $limit ) {
		if ( $limit > 0 ) {
			$lines[] = $name . '|' . $limit;
		} else {
			$lines[] = $name;
		}
	}
	$brands_text = implode( "\n", $lines );
}

$webp_enabled  = isset( $settings['webp_enabled'] ) && '1' === $settings['webp_enabled'] ? true : false;
$webp_strategy = isset( $settings['webp_strategy'] ) ? $settings['webp_strategy'] : 3;
$webp_supports = BSI_WebP::instance()->server_supports();
?>

<div class="wrap">
	<h1><?php echo esc_html__( 'BeeStore — Фильтры импорта', 'beestore-integration' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Введите категории и бренды для импорта. Ничего сканировать не нужно — просто введите названия.', 'beestore-integration' ); ?>
	</p>

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
							<strong><?php esc_html_e( 'Импортировать всё', 'beestore-integration' ); ?></strong> — <?php esc_html_e( 'без фильтров', 'beestore-integration' ); ?>
						</label>
						<label style="display:block;margin-bottom:8px;">
							<input type="radio" name="bsi_settings[import_filter_mode]" value="whitelist" <?php checked( $mode, 'whitelist' ); ?>>
							<strong><?php esc_html_e( 'Только выбранные (whitelist)', 'beestore-integration' ); ?></strong> — <?php esc_html_e( 'импортировать только указанные категории И бренды', 'beestore-integration' ); ?>
						</label>
						<label style="display:block;">
							<input type="radio" name="bsi_settings[import_filter_mode]" value="blacklist" <?php checked( $mode, 'blacklist' ); ?>>
							<strong><?php esc_html_e( 'Все кроме выбранных (blacklist)', 'beestore-integration' ); ?></strong>
						</label>
					</td>
				</tr>
			</table>
		</div>

		<!-- Категории -->
		<div class="bsi-card">
			<h2><?php esc_html_e( 'Категории', 'beestore-integration' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'По одной на строку. Лимит через |: CLOTHING|10 = максимум 10 товаров. Без лимита: CLOTHING', 'beestore-integration' ); ?>
			</p>
			<p class="description" style="color:#2271b1;">
				<?php esc_html_e( 'Макро: CLOTHING, SHOES, BAGS, ACCESSORIES', 'beestore-integration' ); ?><br>
				<?php esc_html_e( 'Подкатегории: JEANS, PANTS, SNEAKERS, HANDBAGS, CLUTCHES, JACKETS, DRESSES, BOOTS, SANDALS, и т.д.', 'beestore-integration' ); ?>
			</p>
			<textarea name="bsi_settings[filter_cats_text]" rows="8" style="width:100%;max-width:500px;font-family:monospace;" placeholder="CLOTHING|10&#10;SHOES&#10;BAGS"><?php echo esc_textarea( $cats_text ); ?></textarea>
		</div>

		<!-- Бренды -->
		<div class="bsi-card">
			<h2><?php esc_html_e( 'Бренды', 'beestore-integration' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'По одной на строку. Лимит через |: VERSACE|20 = максимум 20 товаров. Без лимита: VERSACE', 'beestore-integration' ); ?>
			</p>
			<textarea name="bsi_settings[filter_brands_text]" rows="8" style="width:100%;max-width:500px;font-family:monospace;" placeholder="VERSACE|20&#10;LEVI'S&#10;SALVATORE FERRAGAMO"><?php echo esc_textarea( $brands_text ); ?></textarea>
		</div>

		<!-- WebP -->
		<div class="bsi-card">
			<h2><?php esc_html_e( 'Конвертация картинок в WebP', 'beestore-integration' ); ?></h2>
			<?php if ( ! $webp_supports ) : ?>
				<div class="notice notice-error"><p><strong><?php esc_html_e( 'Сервер не поддерживает WebP!', 'beestore-integration' ); ?></strong></p></div>
			<?php else : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e( 'Конвертировать в WebP', 'beestore-integration' ); ?></th>
						<td>
							<label><input type="checkbox" name="bsi_settings[webp_enabled]" value="1" <?php checked( $webp_enabled ); ?>> <?php esc_html_e( 'Конвертировать и удалить оригинал', 'beestore-integration' ); ?></label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Стратегия', 'beestore-integration' ); ?></th>
						<td>
							<select name="bsi_settings[webp_strategy]">
								<option value="1" <?php selected( $webp_strategy, 1 ); ?>><?php esc_html_e( '1 — Макс. компрессия', 'beestore-integration' ); ?></option>
								<option value="2" <?php selected( $webp_strategy, 2 ); ?>><?php esc_html_e( '2 — Высокая компрессия', 'beestore-integration' ); ?></option>
								<option value="3" <?php selected( $webp_strategy, 3 ); ?>><?php esc_html_e( '3 — Сбалансированно (рекомендуется)', 'beestore-integration' ); ?></option>
								<option value="4" <?php selected( $webp_strategy, 4 ); ?>><?php esc_html_e( '4 — Высокое качество', 'beestore-integration' ); ?></option>
								<option value="5" <?php selected( $webp_strategy, 5 ); ?>><?php esc_html_e( '5 — Lossless', 'beestore-integration' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
			<?php endif; ?>
		</div>

		<?php submit_button( __( 'Сохранить фильтры', 'beestore-integration' ) ); ?>
	</form>

	<!-- Инструкция -->
	<div class="bsi-card">
		<h3><?php esc_html_e( 'Как пользоваться', 'beestore-integration' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Выбрать режим «Только выбранные»', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Ввести категории (по одной на строку, с лимитом через |)', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Ввести бренды (по одной на строку, с лимитом через |)', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Сохранить', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Перейти в «Импорт каталога» → «Начать импорт»', 'beestore-integration' ); ?></li>
		</ol>
		<p class="description">
			<strong><?php esc_html_e( 'Логика AND:', 'beestore-integration' ); ?></strong>
			<?php esc_html_e( 'если введены и категории, и бренды — импортируются только товары, которые подходят И по категории, И по бренду.', 'beestore-integration' ); ?>
		</p>
	</div>
</div>
