<?php
/**
 * Шаблон страницы «Каталог с FTP» — просмотр и скачивание файлов BeeStore.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = get_option( 'bsi_settings', array() );
?>
<div class="wrap">
	<h1><?php echo esc_html__( 'Каталог с FTP Sirio', 'beestore-integration' ); ?></h1>

	<p class="description">
		<?php esc_html_e( 'Здесь можно посмотреть все файлы выгрузки на FTP Sirio, скачать их на сервер и затем — на компьютер для просмотра.', 'beestore-integration' ); ?>
	</p>

	<?php if ( ! empty( $fetch_result ) ) : ?>
		<div class="notice <?php echo $fetch_result['success'] ? 'notice-success' : 'notice-error'; ?> is-dismissible">
			<p><?php echo esc_html( $fetch_result['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( is_wp_error( $remote_files ) ) : ?>
		<div class="notice notice-error">
			<p><strong><?php esc_html_e( 'Ошибка подключения к FTP:', 'beestore-integration' ); ?></strong> <?php echo esc_html( $remote_files->get_error_message() ); ?></p>
			<p><?php esc_html_e( 'Проверьте доступы в настройках плагина.', 'beestore-integration' ); ?></p>
		</div>
	<?php else : ?>

		<!-- Файлы уже на сервере -->
		<?php if ( ! empty( $local_files ) ) : ?>
			<div class="bsi-card">
				<h2><?php esc_html_e( '💾 Файлы на сервере (готовы к скачиванию)', 'beestore-integration' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Имя файла', 'beestore-integration' ); ?></th>
							<th><?php esc_html_e( 'Размер', 'beestore-integration' ); ?></th>
							<th><?php esc_html_e( 'Действия', 'beestore-integration' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $local_files as $local_file ) : ?>
							<?php
							$name = basename( $local_file );
							$size = size_format( filesize( $local_file ) );
							$download_url = wp_nonce_url(
								admin_url( 'admin.php?page=bsi-catalog-browser&bsi_action=download&file=' . rawurlencode( $name ) ),
								'bsi_download_file'
							);
							$delete_url = wp_nonce_url(
								admin_url( 'admin.php?page=bsi-catalog-browser&bsi_action=delete_local&file=' . rawurlencode( $name ) ),
								'bsi_delete_local'
							);
							?>
							<tr>
								<td><code><?php echo esc_html( $name ); ?></code></td>
								<td><?php echo esc_html( $size ); ?></td>
								<td>
									<a href="<?php echo esc_url( $download_url ); ?>" class="button button-primary">
										<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;"></span>
										<?php esc_html_e( 'Скачать на компьютер', 'beestore-integration' ); ?>
									</a>
									<a href="<?php echo esc_url( $delete_url ); ?>" class="button button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Удалить файл с сервера?', 'beestore-integration' ); ?>')">
										<?php esc_html_e( 'Удалить', 'beestore-integration' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<!-- Файлы на FTP -->
		<div class="bsi-card">
			<h2>
				<?php esc_html_e( '📋 Файлы на FTP Sirio', 'beestore-integration' ); ?>
				<span class="button button-secondary button-small" style="margin-left:10px;" onclick="location.reload()">
					<span class="dashicons dashicons-update" style="vertical-align:middle;margin-top:3px;"></span>
					<?php esc_html_e( 'Обновить', 'beestore-integration' ); ?>
				</span>
			</h2>

			<p>
				<strong><?php esc_html_e( 'Всего файлов:', 'beestore-integration' ); ?></strong>
				<?php echo esc_html( number_format_i18n( count( $remote_parsed ) ) ); ?>
				|
				<strong><?php esc_html_e( 'Полных каталогов (_0000001):', 'beestore-integration' ); ?></strong>
				<?php
				$full_count = count( array_filter( $remote_parsed, function ( $f ) { return $f['is_full']; } ) );
				echo esc_html( number_format_i18n( $full_count ) );
				?>
				|
				<strong><?php esc_html_e( 'Инкрементальных:', 'beestore-integration' ); ?></strong>
				<?php echo esc_html( number_format_i18n( count( $remote_parsed ) - $full_count ) ); ?>
			</p>

			<p class="description">
				⭐ <?php esc_html_e( 'Файлы с _0000001 — это ПОЛНЫЙ каталог (~57 000 строк, ~66 MB). Это то, что нужно скачать для просмотра.', 'beestore-integration' ); ?><br>
				📄 <?php esc_html_e( 'Файлы с _0000002 и больше — инкрементальные (только изменения за 15 минут, ~50-100 строк).', 'beestore-integration' ); ?>
			</p>

			<div style="max-height:600px;overflow-y:auto;border:1px solid #ddd;margin-top:15px;">
				<table class="widefat striped">
					<thead>
						<tr>
							<th style="width:30px;"></th>
							<th><?php esc_html_e( 'Имя файла', 'beestore-integration' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Дата', 'beestore-integration' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Время', 'beestore-integration' ); ?></th>
							<th style="width:120px;"><?php esc_html_e( 'Тип', 'beestore-integration' ); ?></th>
							<th style="width:180px;"><?php esc_html_e( 'Действия', 'beestore-integration' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $remote_parsed as $f ) : ?>
							<?php
							$fetch_url = wp_nonce_url(
								admin_url( 'admin.php?page=bsi-catalog-browser&bsi_action=fetch&file=' . rawurlencode( $f['remote_path'] ) ),
								'bsi_fetch_file'
							);
							?>
							<tr>
								<td style="font-size:16px;text-align:center;">
									<?php echo $f['is_full'] ? '⭐' : '📄'; ?>
								</td>
								<td><code style="font-size:11px;"><?php echo esc_html( $f['name'] ); ?></code></td>
								<td><code><?php echo esc_html( $f['date'] ); ?></code></td>
								<td><code><?php echo esc_html( $f['time'] ); ?></code></td>
								<td>
									<?php if ( $f['is_full'] ) : ?>
										<span style="color:#2e7d32;font-weight:600;"><?php esc_html_e( 'Полный', 'beestore-integration' ); ?></span>
									<?php else : ?>
										<span style="color:#666;"><?php esc_html_e( 'Инкремент.', 'beestore-integration' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( $fetch_url ); ?>" class="button button-secondary button-small">
										<span class="dashicons dashicons-download" style="vertical-align:middle;margin-top:3px;font-size:14px;width:14px;height:14px;"></span>
										<?php esc_html_e( 'Скачать на сервер', 'beestore-integration' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

		<div class="bsi-card">
			<h3><?php esc_html_e( 'ℹ️ Как пользоваться', 'beestore-integration' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Найдите файл с пометкой ⭐ _0000001.csv (это полный каталог за последнюю ночь)', 'beestore-integration' ); ?></li>
				<li><?php esc_html_e( 'Нажмите «Скачать на сервер» — файл скачается с FTP Sirio на ваш WordPress-сервер', 'beestore-integration' ); ?></li>
				<li><?php esc_html_e( 'После скачивания файл появится в верхнем блоке «Файлы на сервере»', 'beestore-integration' ); ?></li>
				<li><?php esc_html_e( 'Нажмите «Скачать на компьютер» — файл скачается на ваш ПК', 'beestore-integration' ); ?></li>
				<li><?php esc_html_e( 'Откройте CSV в Excel, Notepad++ или VS Code', 'beestore-integration' ); ?></li>
				<li><?php esc_html_e( 'После просмотра нажмите «Удалить», чтобы освободить место на сервере', 'beestore-integration' ); ?></li>
			</ol>
		</div>

	<?php endif; ?>
</div>
