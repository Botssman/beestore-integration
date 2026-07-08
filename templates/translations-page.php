<?php
/**
 * Шаблон страницы «Переводы» — перевод категорий и пола на русский.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tr                  = BSI_Translations::instance();
$supported_taxonomies = BSI_Translations::SUPPORTED_TAXONOMIES;
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'BeeStore — Переводы', 'beestore-integration' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Здесь можно перевести названия категорий и пола на русский. Перевод переписывает имя терма (name), но не трогает слаг (slug) и картинку категории.', 'beestore-integration' ); ?>
	</p>

	<!-- Вкладки таксономий -->
	<h2 class="nav-tab-wrapper" style="margin-bottom:15px;">
		<?php foreach ( $supported_taxonomies as $tax_slug => $tax_label ) : ?>
			<a href="?page=bsi-translations&taxonomy=<?php echo esc_attr( $tax_slug ); ?>"
				class="nav-tab <?php echo $current_tax === $tax_slug ? 'nav-tab-active' : ''; ?>">
				<?php echo esc_html( $tax_label ); ?>
			</a>
		<?php endforeach; ?>
	</h2>

	<div class="bsi-card">
		<h2><?php echo esc_html( $supported_taxonomies[ $current_tax ] ); ?> — <?php esc_html_e( 'перевод', 'beestore-integration' ); ?></h2>

		<?php if ( empty( $existing_terms ) ) : ?>
			<div class="notice notice-warning">
				<p>
					<?php esc_html_e( 'В этой таксономии пока нет термов. Запустите импорт каталога — плагин создаст категории из BeeStore, потом вернитесь сюда для перевода.', 'beestore-integration' ); ?>
				</p>
			</div>
		<?php else : ?>
			<p class="description">
				<?php
				echo esc_html( sprintf(
					/* translators: %d — кол-во термов */
					__( 'Всего термов: %d. Введите русский перевод в правую колонку и нажмите «Сохранить переводы».', 'beestore-integration' ),
					count( $existing_terms )
				) );
				?>
			</p>

			<table class="widefat striped" id="bsi-translations-table">
				<thead>
					<tr>
						<th style="width:40%;"><?php esc_html_e( 'Оригинал (из BeeStore)', 'beestore-integration' ); ?></th>
						<th style="width:40%;"><?php esc_html_e( 'Русский перевод', 'beestore-integration' ); ?></th>
						<th style="width:10%;"><?php esc_html_e( 'Товаров', 'beestore-integration' ); ?></th>
						<th style="width:10%;"><?php esc_html_e( 'Slug', 'beestore-integration' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $existing_terms as $term_name => $info ) : ?>
						<?php
						// Если терм уже переименован (его имя = переводу), показываем перевод в правой колонке.
						// Иначе ищем перевод по оригинальному имени (которое могло быть до переименования).
						$saved_ru = '';
						foreach ( $saved_translations as $orig => $ru ) {
							if ( 0 === strcasecmp( $orig, $term_name ) || 0 === strcasecmp( $orig, $info['slug'] ) ) {
								$saved_ru = $ru;
								break;
							}
						}
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $term_name ); ?></strong>
								<br><small style="color:#999;">slug: <?php echo esc_html( $info['slug'] ); ?></small>
							</td>
							<td>
								<input type="text"
									class="bsi-translation-input regular-text"
									data-original="<?php echo esc_attr( $term_name ); ?>"
									value="<?php echo esc_attr( $saved_ru ); ?>"
									placeholder="<?php esc_attr_e( 'введите перевод...', 'beestore-integration' ); ?>">
							</td>
							<td><code><?php echo esc_html( $info['count'] ); ?></code></td>
							<td><code><?php echo esc_html( $info['slug'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:15px;">
				<button type="button" class="button button-primary button-large" id="bsi-save-translations">
					<span class="dashicons dashicons-translation" style="vertical-align:middle;margin-top:3px;"></span>
					<?php esc_html_e( 'Сохранить переводы', 'beestore-integration' ); ?>
				</button>
				<span id="bsi-translations-status" style="margin-left:10px;"></span>
			</p>
		<?php endif; ?>
	</div>

	<div class="bsi-card">
		<h3><?php esc_html_e( 'Как это работает', 'beestore-integration' ); ?></h3>
		<ol>
			<li><?php esc_html_e( 'Запустите импорт каталога (BeeStore → Импорт → Начать импорт)', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Плагин создаст категории с английскими названиями (CLOTHING, JACKETS, и т.д.)', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Вернитесь сюда — увидите список всех категорий', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Впишите русские переводы в правую колонку', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Нажмите «Сохранить переводы» — категории переименуются, slug и картинки сохранятся', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'При следующем импорте плагин автоматически будет использовать русские названия для новых товаров', 'beestore-integration' ); ?></li>
			<li><?php esc_html_e( 'Можно загружать картинки для категорий в WooCommerce → Товары → Категории — плагин их не перезапишет', 'beestore-integration' ); ?></li>
		</ol>
	</div>
</div>

<script>
jQuery(document).ready(function($){
	var currentTax = '<?php echo esc_js( $current_tax ); ?>';

	$('#bsi-save-translations').on('click', function() {
		var $btn = $(this);
		var $status = $('#bsi-translations-status');
		var translations = {};

		// Собираем все непустые переводы.
		$('.bsi-translation-input').each(function() {
			var original = $(this).data('original');
			var ru = $(this).val().trim();
			if (original && ru) {
				translations[original] = ru;
			}
		});

		$btn.prop('disabled', true);
		$status.html('<span class="bsi-spinner"></span> Сохранение...');

		$.post(bsiAdmin.ajaxUrl, {
			action: 'bsi_save_translations',
			nonce: bsiAdmin.nonce,
			taxonomy: currentTax,
			translations: translations
		}, function(response) {
			$btn.prop('disabled', false);
			if (response.success) {
				$status.html('<span style="color:#2e7d32;">✓ ' + response.data.message + '</span>');
				// Перезагружаем через 2 секунды чтобы показать обновлённые имена.
				setTimeout(function() {
					location.reload();
				}, 2000);
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
