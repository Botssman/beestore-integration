<?php
/**
 * Шаблон страницы настроек BeeStore Integration.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$settings = get_option( 'bsi_settings', array() );
$gateways = function_exists( 'WC' ) ? WC()->payment_gateways()->payment_gateways() : array();

// Дефолтные значения.
$ftp_host     = isset( $settings['ftp_host'] ) ? $settings['ftp_host'] : '';
$ftp_port     = isset( $settings['ftp_port'] ) ? $settings['ftp_port'] : 21;
$ftp_user     = isset( $settings['ftp_user'] ) ? $settings['ftp_user'] : '';
$ftp_pass     = isset( $settings['ftp_pass'] ) ? $settings['ftp_pass'] : '';
$ftp_path     = isset( $settings['ftp_path'] ) ? $settings['ftp_path'] : '/';
$ftp_use_sftp = isset( $settings['ftp_use_sftp'] ) && '1' === $settings['ftp_use_sftp'];

$wsdl_url    = isset( $settings['wsdl_url'] ) ? $settings['wsdl_url'] : '';
$soap_user   = isset( $settings['soap_user'] ) ? $settings['soap_user'] : '';
$soap_pass   = isset( $settings['soap_pass'] ) ? $settings['soap_pass'] : '';

$igu_negozio        = isset( $settings['igu_negozio'] ) ? $settings['igu_negozio'] : '';
$igu_cliente        = isset( $settings['igu_cliente'] ) ? $settings['igu_cliente'] : '';
$igu_magazzino_riga = isset( $settings['igu_magazzino_riga'] ) ? $settings['igu_magazzino_riga'] : '';
$cod_iva_default    = isset( $settings['cod_iva_default'] ) ? $settings['cod_iva_default'] : '22';
$default_tax_rate   = isset( $settings['default_tax_rate'] ) ? $settings['default_tax_rate'] : 22;
$igu_valuta         = isset( $settings['igu_valuta'] ) ? $settings['igu_valuta'] : '';
$cod_dest_sdi       = isset( $settings['cod_dest_sdi'] ) ? $settings['cod_dest_sdi'] : '';

$enable_order_sync     = isset( $settings['enable_order_sync'] ) && '1' === $settings['enable_order_sync'];
$enable_status_sync    = isset( $settings['enable_status_sync'] ) && '1' === $settings['enable_status_sync'];
$enable_realtime_stock = isset( $settings['enable_realtime_stock'] ) && '1' === $settings['enable_realtime_stock'];
$delete_out_of_stock   = isset( $settings['delete_out_of_stock'] ) && '1' === $settings['delete_out_of_stock'];
$download_images       = ! isset( $settings['download_images'] ) || '1' === $settings['download_images'];

$sync_frequency        = isset( $settings['sync_frequency'] ) ? $settings['sync_frequency'] : 'hourly';
$status_sync_frequency = isset( $settings['status_sync_frequency'] ) ? $settings['status_sync_frequency'] : 'hourly';
$import_batch_size     = isset( $settings['import_batch_size'] ) ? $settings['import_batch_size'] : 200;
$id_tipo_incasso_default = isset( $settings['id_tipo_incasso_default'] ) ? $settings['id_tipo_incasso_default'] : 3;
$log_level             = isset( $settings['log_level'] ) ? $settings['log_level'] : 'info';
$mapping_payment       = isset( $settings['mapping_payment'] ) && is_array( $settings['mapping_payment'] ) ? $settings['mapping_payment'] : array();
?>

<div class="wrap">
	<h1><?php echo esc_html__( 'BeeStore Integration — Настройки', 'beestore-integration' ); ?></h1>

	<form method="post" action="options.php">
		<?php settings_fields( 'bsi_settings_group' ); ?>

		<h2 class="nav-tab-wrapper" style="margin-bottom:15px;">
			<a href="#bsi-ftp" class="nav-tab nav-tab-active" data-tab="ftp"><?php esc_html_e( 'FTP', 'beestore-integration' ); ?></a>
			<a href="#bsi-soap" class="nav-tab" data-tab="soap"><?php esc_html_e( 'SOAP / BeeStore', 'beestore-integration' ); ?></a>
			<a href="#bsi-sync" class="nav-tab" data-tab="sync"><?php esc_html_e( 'Синхронизация', 'beestore-integration' ); ?></a>
			<a href="#bsi-payments" class="nav-tab" data-tab="payments"><?php esc_html_e( 'Платежи', 'beestore-integration' ); ?></a>
		</h2>

		<!-- FTP -->
		<div id="bsi-ftp" class="bsi-tab">
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="ftp_host"><?php esc_html_e( 'FTP/SFTP хост', 'beestore-integration' ); ?></label></th>
					<td>
						<input type="text" name="bsi_settings[ftp_host]" id="ftp_host" value="<?php echo esc_attr( $ftp_host ); ?>" class="regular-text" placeholder="ftp.example.com">
					</td>
				</tr>
				<tr>
					<th><label for="ftp_port"><?php esc_html_e( 'Порт', 'beestore-integration' ); ?></label></th>
					<td>
						<input type="number" name="bsi_settings[ftp_port]" id="ftp_port" value="<?php echo esc_attr( $ftp_port ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'FTP по умолчанию — 21, SFTP — 22.', 'beestore-integration' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="ftp_user"><?php esc_html_e( 'Пользователь', 'beestore-integration' ); ?></label></th>
					<td><input type="text" name="bsi_settings[ftp_user]" id="ftp_user" value="<?php echo esc_attr( $ftp_user ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="ftp_pass"><?php esc_html_e( 'Пароль', 'beestore-integration' ); ?></label></th>
					<td><input type="password" name="bsi_settings[ftp_pass]" id="ftp_pass" value="<?php echo esc_attr( $ftp_pass ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="ftp_path"><?php esc_html_e( 'Каталог на FTP', 'beestore-integration' ); ?></label></th>
					<td>
						<input type="text" name="bsi_settings[ftp_path]" id="ftp_path" value="<?php echo esc_attr( $ftp_path ); ?>" class="regular-text" placeholder="/">
						<p class="description"><?php esc_html_e( 'Путь к каталогу, куда BeeStore выкладывает ZIP-файлы COMPANY_*.zip.', 'beestore-integration' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Использовать SFTP', 'beestore-integration' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bsi_settings[ftp_use_sftp]" value="1" <?php checked( $ftp_use_sftp ); ?>>
							<?php esc_html_e( 'Включить SFTP (требуется расширение PHP ssh2)', 'beestore-integration' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>

		<!-- SOAP -->
		<div id="bsi-soap" class="bsi-tab" style="display:none;">
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="wsdl_url"><?php esc_html_e( 'WSDL URL', 'beestore-integration' ); ?></label></th>
					<td>
						<input type="url" name="bsi_settings[wsdl_url]" id="wsdl_url" value="<?php echo esc_attr( $wsdl_url ); ?>" class="large-text" placeholder="http://www.sirio-is.it:8180/.../soapBeestore.wsdl">
					</td>
				</tr>
				<tr>
					<th><label for="soap_user"><?php esc_html_e( 'Пользователь SOAP', 'beestore-integration' ); ?></label></th>
					<td><input type="text" name="bsi_settings[soap_user]" id="soap_user" value="<?php echo esc_attr( $soap_user ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="soap_pass"><?php esc_html_e( 'Пароль SOAP', 'beestore-integration' ); ?></label></th>
					<td><input type="password" name="bsi_settings[soap_pass]" id="soap_pass" value="<?php echo esc_attr( $soap_pass ); ?>" class="regular-text"></td>
				</tr>
				<tr>
					<th><label for="igu_negozio"><?php esc_html_e( 'IGU Negozio (магазин)', 'beestore-integration' ); ?></label></th>
					<td>
						<input type="text" name="bsi_settings[igu_negozio]" id="igu_negozio" value="<?php echo esc_attr( $igu_negozio ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'В вашей выгрузке: 540 (магазин BAY).', 'beestore-integration' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="igu_cliente"><?php esc_html_e( 'IGU Cliente (клиент по умолчанию)', 'beestore-integration' ); ?></label></th>
					<td>
						<input type="text" name="bsi_settings[igu_cliente]" id="igu_cliente" value="<?php echo esc_attr( $igu_cliente ); ?>" class="regular-text" placeholder="13\51\39\1\419339\0">
						<p class="description"><?php esc_html_e( 'Уникальный клиент-призрак, под которым оформляются все заказы маркетплейса в BeeStore. Код предоставляет Sirio.', 'beestore-integration' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="igu_valuta"><?php esc_html_e( 'IGU Valuta', 'beestore-integration' ); ?></label></th>
					<td><input type="text" name="bsi_settings[igu_valuta]" id="igu_valuta" value="<?php echo esc_attr( $igu_valuta ); ?>" class="regular-text" placeholder="15\1\1\1"></td>
				</tr>
				<tr>
					<th><label for="igu_magazzino_riga"><?php esc_html_e( 'IGU Magazzino Riga', 'beestore-integration' ); ?></label></th>
					<td><input type="text" name="bsi_settings[igu_magazzino_riga]" id="igu_magazzino_riga" value="<?php echo esc_attr( $igu_magazzino_riga ); ?>" class="regular-text" placeholder="оставьте пустым"></td>
				</tr>
				<tr>
					<th><label for="cod_iva_default"><?php esc_html_e( 'Cod IVA по умолчанию', 'beestore-integration' ); ?></label></th>
					<td><input type="text" name="bsi_settings[cod_iva_default]" id="cod_iva_default" value="<?php echo esc_attr( $cod_iva_default ); ?>" class="small-text" placeholder="22"></td>
				</tr>
				<tr>
					<th><label for="default_tax_rate"><?php esc_html_e( 'Ставка НДС, %', 'beestore-integration' ); ?></label></th>
					<td><input type="number" step="0.01" name="bsi_settings[default_tax_rate]" id="default_tax_rate" value="<?php echo esc_attr( $default_tax_rate ); ?>" class="small-text"></td>
				</tr>
				<tr>
					<th><label for="cod_dest_sdi"><?php esc_html_e( 'CodDestSDI', 'beestore-integration' ); ?></label></th>
					<td><input type="text" name="bsi_settings[cod_dest_sdi]" id="cod_dest_sdi" value="<?php echo esc_attr( $cod_dest_sdi ); ?>" class="regular-text" maxlength="7"></td>
				</tr>
			</table>
		</div>

		<!-- Синхронизация -->
		<div id="bsi-sync" class="bsi-tab" style="display:none;">
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Передавать заказы в BeeStore', 'beestore-integration' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bsi_settings[enable_order_sync]" value="1" <?php checked( $enable_order_sync ); ?>>
							<?php esc_html_e( 'Включить (fInserimentoPrenotazione при оплате)', 'beestore-integration' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Получать статусы заказов', 'beestore-integration' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bsi_settings[enable_status_sync]" value="1" <?php checked( $enable_status_sync ); ?>>
							<?php esc_html_e( 'Включить cron-опрос fStatoPrenotazioni', 'beestore-integration' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Проверка остатков real-time', 'beestore-integration' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bsi_settings[enable_realtime_stock]" value="1" <?php checked( $enable_realtime_stock ); ?>>
							<?php esc_html_e( 'Запрос fDisponibilita при оформлении заказа (замедляет checkout)', 'beestore-integration' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Снимать с публикации отсутствующие товары', 'beestore-integration' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bsi_settings[delete_out_of_stock]" value="1" <?php checked( $delete_out_of_stock ); ?>>
							<?php esc_html_e( 'Если товар не встретился в утренней выгрузке — скрывать', 'beestore-integration' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Скачивать картинки', 'beestore-integration' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="bsi_settings[download_images]" value="1" <?php checked( $download_images ); ?>>
							<?php esc_html_e( 'Загружать URLImg1..10 в Media Library', 'beestore-integration' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><label for="sync_frequency"><?php esc_html_e( 'Частота импорта каталога', 'beestore-integration' ); ?></label></th>
					<td>
						<select name="bsi_settings[sync_frequency]" id="sync_frequency">
							<?php
							$frequencies = array(
								'every15min' => __( 'Каждые 15 минут', 'beestore-integration' ),
								'every30min' => __( 'Каждые 30 минут', 'beestore-integration' ),
								'hourly'     => __( 'Каждый час', 'beestore-integration' ),
								'twicedaily' => __( 'Дважды в день', 'beestore-integration' ),
								'daily'      => __( 'Раз в день', 'beestore-integration' ),
							);
							foreach ( $frequencies as $k => $label ) {
								echo '<option value="' . esc_attr( $k ) . '" ' . selected( $sync_frequency, $k, false ) . '>' . esc_html( $label ) . '</option>';
							}
							?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="status_sync_frequency"><?php esc_html_e( 'Частота опроса статусов', 'beestore-integration' ); ?></label></th>
					<td>
						<select name="bsi_settings[status_sync_frequency]" id="status_sync_frequency">
							<?php
							foreach ( $frequencies as $k => $label ) {
								echo '<option value="' . esc_attr( $k ) . '" ' . selected( $status_sync_frequency, $k, false ) . '>' . esc_html( $label ) . '</option>';
							}
							?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="import_batch_size"><?php esc_html_e( 'Размер пакета импорта', 'beestore-integration' ); ?></label></th>
					<td>
						<input type="number" name="bsi_settings[import_batch_size]" id="import_batch_size" value="<?php echo esc_attr( $import_batch_size ); ?>" class="small-text" min="10" max="2000" step="10">
						<p class="description"><?php esc_html_e( 'Сколько моделей обрабатывается за один проход перед очисткой памяти (10–2000).', 'beestore-integration' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="log_level"><?php esc_html_e( 'Уровень логирования', 'beestore-integration' ); ?></label></th>
					<td>
						<select name="bsi_settings[log_level]" id="log_level">
							<?php
							$levels = array(
								'debug'   => __( 'Debug (всё)', 'beestore-integration' ),
								'info'    => __( 'Info (рекомендуется)', 'beestore-integration' ),
								'warning' => __( 'Только предупреждения', 'beestore-integration' ),
								'error'   => __( 'Только ошибки', 'beestore-integration' ),
							);
							foreach ( $levels as $k => $label ) {
								echo '<option value="' . esc_attr( $k ) . '" ' . selected( $log_level, $k, false ) . '>' . esc_html( $label ) . '</option>';
							}
							?>
						</select>
					</td>
				</tr>
			</table>
		</div>

		<!-- Платежи -->
		<div id="bsi-payments" class="bsi-tab" style="display:none;">
			<p><?php esc_html_e( 'Укажите соответствие между способами оплаты WooCommerce и кодами IDTipoIncasso BeeStore.', 'beestore-integration' ); ?></p>
			<p class="description"><?php echo esc_html__( 'Типы BeeStore: 2 = чек, 3 = карта/PayPal/Stripe, 20 = депозит, 21 = продажа в кредит (оплата при доставке).', 'beestore-integration' ); ?></p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Способ оплаты WC', 'beestore-integration' ); ?></th>
						<th><?php esc_html_e( 'IDTipoIncasso BeeStore', 'beestore-integration' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $gateways ) ) : ?>
						<tr><td colspan="2"><?php esc_html_e( 'WooCommerce не обнаружил способов оплаты. Включите хотя бы один платёжный плагин.', 'beestore-integration' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $gateways as $gateway ) : ?>
							<?php
							$gid     = $gateway->id;
							$current = isset( $mapping_payment[ $gid ] ) ? (int) $mapping_payment[ $gid ] : 0;
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $gateway->title ); ?></strong>
									<code style="margin-left:8px;color:#666;"><?php echo esc_html( $gid ); ?></code>
								</td>
								<td>
									<select name="bsi_settings[mapping_payment][<?php echo esc_attr( $gid ); ?>]">
										<option value="0"><?php esc_html_e( '— использовать по умолчанию —', 'beestore-integration' ); ?></option>
										<option value="2" <?php selected( $current, 2 ); ?>><?php esc_html_e( '2 — Чек', 'beestore-integration' ); ?></option>
										<option value="3" <?php selected( $current, 3 ); ?>><?php esc_html_e( '3 — Карта/PayPal/Stripe', 'beestore-integration' ); ?></option>
										<option value="20" <?php selected( $current, 20 ); ?>><?php esc_html_e( '20 — Депозит', 'beestore-integration' ); ?></option>
										<option value="21" <?php selected( $current, 21 ); ?>><?php esc_html_e( '21 — Кредит/при доставке', 'beestore-integration' ); ?></option>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<table class="form-table" role="presentation" style="margin-top:20px;">
				<tr>
					<th><label for="id_tipo_incasso_default"><?php esc_html_e( 'IDTipoIncasso по умолчанию', 'beestore-integration' ); ?></label></th>
					<td>
						<input type="number" name="bsi_settings[id_tipo_incasso_default]" id="id_tipo_incasso_default" value="<?php echo esc_attr( $id_tipo_incasso_default ); ?>" class="small-text" min="0" max="99">
						<p class="description"><?php esc_html_e( 'Используется, если для способа оплаты WC не задан явный маппинг.', 'beestore-integration' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'Сохранить настройки', 'beestore-integration' ) ); ?>
	</form>
</div>

<script>
jQuery(document).ready(function($){
	$('.nav-tab-wrapper .nav-tab').on('click', function(e){
		e.preventDefault();
		var tab = $(this).data('tab');
		$('.nav-tab').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');
		$('.bsi-tab').hide();
		$('#bsi-' + tab).show();
	});
});
</script>
