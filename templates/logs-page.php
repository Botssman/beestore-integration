<?php
/**
 * Шаблон страницы логов.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap">
	<h1>
		<?php echo esc_html__( 'BeeStore — Логи', 'beestore-integration' ); ?>
		<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=bsi-logs&action=clear_logs' ), 'bsi_clear_logs' ) ); ?>" class="page-title-action" onclick="return confirm(bsiAdmin.i18n.confirm);">
			<?php esc_html_e( 'Очистить', 'beestore-integration' ); ?>
		</a>
	</h1>

	<form method="get" action="">
		<input type="hidden" name="page" value="bsi-logs">
		<p>
			<label><?php esc_html_e( 'Уровень:', 'beestore-integration' ); ?>
				<select name="level">
					<option value=""><?php esc_html_e( 'Все', 'beestore-integration' ); ?></option>
					<option value="debug" <?php selected( $level, 'debug' ); ?>><?php esc_html_e( 'Debug', 'beestore-integration' ); ?></option>
					<option value="info" <?php selected( $level, 'info' ); ?>><?php esc_html_e( 'Info', 'beestore-integration' ); ?></option>
					<option value="warning" <?php selected( $level, 'warning' ); ?>><?php esc_html_e( 'Warning', 'beestore-integration' ); ?></option>
					<option value="error" <?php selected( $level, 'error' ); ?>><?php esc_html_e( 'Error', 'beestore-integration' ); ?></option>
				</select>
			</label>
			<label style="margin-left:15px;"><?php esc_html_e( 'Источник:', 'beestore-integration' ); ?>
				<select name="source">
					<option value=""><?php esc_html_e( 'Все', 'beestore-integration' ); ?></option>
					<?php foreach ( $sources as $s ) : ?>
						<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $source, $s ); ?>><?php echo esc_html( $s ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="button button-secondary"><?php esc_html_e( 'Применить', 'beestore-integration' ); ?></button>
		</p>
	</form>

	<table class="widefat striped bsi-logs-table">
		<thead>
			<tr>
				<th style="width:150px;"><?php esc_html_e( 'Дата', 'beestore-integration' ); ?></th>
				<th style="width:80px;"><?php esc_html_e( 'Уровень', 'beestore-integration' ); ?></th>
				<th style="width:120px;"><?php esc_html_e( 'Источник', 'beestore-integration' ); ?></th>
				<th><?php esc_html_e( 'Сообщение', 'beestore-integration' ); ?></th>
				<th style="width:30%;"><?php esc_html_e( 'Контекст', 'beestore-integration' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( empty( $logs ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Логи отсутствуют.', 'beestore-integration' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $logs as $log ) : ?>
					<tr class="bsi-level-<?php echo esc_attr( $log->level ); ?>">
						<td><?php echo esc_html( $log->created_at ); ?></td>
						<td><span class="bsi-level-badge bsi-level-<?php echo esc_attr( $log->level ); ?>"><?php echo esc_html( strtoupper( $log->level ) ); ?></span></td>
						<td><code><?php echo esc_html( $log->source ); ?></code></td>
						<td><?php echo esc_html( $log->message ); ?></td>
						<td>
							<?php if ( $log->context ) : ?>
								<details>
									<summary style="cursor:pointer;color:#2271b1;"><?php esc_html_e( 'показать', 'beestore-integration' ); ?></summary>
									<pre style="font-size:11px;background:#f6f7f7;padding:8px;overflow:auto;max-height:200px;"><?php echo esc_html( $log->context ); ?></pre>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>
</div>
