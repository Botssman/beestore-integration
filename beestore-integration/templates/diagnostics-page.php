<?php
/**
 * Шаблон страницы диагностики.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}
?>

<div class="wrap">
        <h1><?php echo esc_html__( 'BeeStore — Диагностика', 'beestore-integration' ); ?></h1>

        <div class="bsi-card">
                <h2><?php esc_html_e( 'Окружение PHP', 'beestore-integration' ); ?></h2>
                <table class="widefat striped">
                        <tbody>
                                <tr><th>PHP Version</th><td><?php echo esc_html( $php_info['php_version'] ); ?></td></tr>
                                <tr>
                                        <th>SoapClient</th>
                                        <td>
                                                <?php if ( $php_info['soap_enabled'] ) : ?>
                                                        <span class="bsi-ok"><?php esc_html_e( 'установлено', 'beestore-integration' ); ?></span>
                                                <?php else : ?>
                                                        <span class="bsi-error"><?php esc_html_e( 'НЕ установлено (обязательно для SOAP)', 'beestore-integration' ); ?></span>
                                                <?php endif; ?>
                                        </td>
                                </tr>
                                <tr>
                                        <th>ZipArchive</th>
                                        <td>
                                                <?php if ( $php_info['zip_enabled'] ) : ?>
                                                        <span class="bsi-ok"><?php esc_html_e( 'установлено', 'beestore-integration' ); ?></span>
                                                <?php else : ?>
                                                        <span class="bsi-error"><?php esc_html_e( 'НЕ установлено (для распаковки ZIP)', 'beestore-integration' ); ?></span>
                                                <?php endif; ?>
                                        </td>
                                </tr>
                                <tr>
                                        <th>FTP extension</th>
                                        <td>
                                                <?php if ( $php_info['ftp_enabled'] ) : ?>
                                                        <span class="bsi-ok"><?php esc_html_e( 'установлено', 'beestore-integration' ); ?></span>
                                                <?php else : ?>
                                                        <span class="bsi-warn"><?php esc_html_e( 'не установлено (только SFTP)', 'beestore-integration' ); ?></span>
                                                <?php endif; ?>
                                        </td>
                                </tr>
                                <tr>
                                        <th>SSH2 extension</th>
                                        <td>
                                                <?php if ( $php_info['ssh2_enabled'] ) : ?>
                                                        <span class="bsi-ok"><?php esc_html_e( 'установлено', 'beestore-integration' ); ?></span>
                                                <?php else : ?>
                                                        <span class="bsi-warn"><?php esc_html_e( 'не установлено (только FTP)', 'beestore-integration' ); ?></span>
                                                <?php endif; ?>
                                        </td>
                                </tr>
                                <tr>
                                        <th>cURL</th>
                                        <td>
                                                <?php if ( $php_info['curl_enabled'] ) : ?>
                                                        <span class="bsi-ok"><?php esc_html_e( 'установлено', 'beestore-integration' ); ?></span>
                                                <?php else : ?>
                                                        <span class="bsi-error"><?php esc_html_e( 'НЕ установлено', 'beestore-integration' ); ?></span>
                                                <?php endif; ?>
                                        </td>
                                </tr>
                                <tr><th>memory_limit</th><td><?php echo esc_html( $php_info['memory_limit'] ); ?></td></tr>
                                <tr><th>max_execution_time</th><td><?php echo esc_html( $php_info['max_exec_time'] ); ?> сек.</td></tr>
                        </tbody>
                </table>
        </div>

        <div class="bsi-card">
                <h2><?php esc_html_e( 'Тест SOAP-подключения', 'beestore-integration' ); ?></h2>
                <p>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=bsi-diagnostics&run=soap' ), 'bsi_test_soap' ) ); ?>" class="button button-primary">
                                <?php esc_html_e( 'Запустить тест SOAP', 'beestore-integration' ); ?>
                        </a>
                </p>
                <?php if ( null !== $soap_test ) : ?>
                        <div class="notice <?php echo $soap_test['success'] ? 'notice-success' : 'notice-error'; ?>">
                                <p><strong><?php echo $soap_test['success'] ? 'OK' : 'ERROR'; ?>:</strong> <?php echo esc_html( $soap_test['message'] ); ?></p>
                                <?php if ( isset( $soap_test['data'] ) ) : ?>
                                        <pre style="font-size:11px;background:#f6f7f7;padding:8px;"><?php echo esc_html( print_r( $soap_test['data'], true ) ); // phpcs:ignore ?></pre>
                                <?php endif; ?>
                        </div>
                <?php endif; ?>
        </div>

        <div class="bsi-card">
                <h2><?php esc_html_e( 'Тест FTP-подключения', 'beestore-integration' ); ?></h2>
                <p>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=bsi-diagnostics&run=ftp' ), 'bsi_test_ftp' ) ); ?>" class="button button-primary">
                                <?php esc_html_e( 'Запустить тест FTP', 'beestore-integration' ); ?>
                        </a>
                </p>
                <?php if ( null !== $ftp_test ) : ?>
                        <div class="notice <?php echo $ftp_test['success'] ? 'notice-success' : 'notice-error'; ?>">
                                <p><strong><?php echo $ftp_test['success'] ? 'OK' : 'ERROR'; ?>:</strong> <?php echo esc_html( $ftp_test['message'] ); ?></p>

                                <?php if ( isset( $ftp_test['ftp_path_setting'] ) ) : ?>
                                        <p style="font-size:12px;color:#666;">
                                                Текущий "Каталог на FTP" в настройках:
                                                <code><?php echo esc_html( $ftp_test['ftp_path_setting'] ); ?></code>
                                        </p>
                                <?php endif; ?>

                                <?php if ( isset( $ftp_test['files'] ) && ! empty( $ftp_test['files'] ) ) : ?>
                                        <p style="margin-top:10px;"><strong>Найденные файлы BeeStore:</strong></p>
                                        <ul style="margin-top:5px;">
                                                <?php foreach ( $ftp_test['files'] as $f ) : ?>
                                                        <li><code><?php echo esc_html( $f ); ?></code></li>
                                                <?php endforeach; ?>
                                        </ul>
                                <?php endif; ?>

                                <?php if ( isset( $ftp_test['all_files'] ) && ! empty( $ftp_test['all_files'] ) ) : ?>
                                        <p style="margin-top:10px;"><strong>Все файлы в каталоге (для отладки):</strong></p>
                                        <ul style="margin-top:5px;max-height:200px;overflow:auto;font-size:11px;">
                                                <?php foreach ( $ftp_test['all_files'] as $f ) : ?>
                                                        <li><code><?php echo esc_html( $f ); ?></code></li>
                                                <?php endforeach; ?>
                                        </ul>
                                <?php endif; ?>

                                <?php if ( isset( $ftp_test['all_files'] ) && empty( $ftp_test['all_files'] ) && isset( $ftp_test['files'] ) && empty( $ftp_test['files'] ) ) : ?>
                                        <p style="margin-top:10px;color:#c62828;">
                                                <strong>Каталог пуст или недоступен.</strong><br>
                                                Возможные причины:
                                        </p>
                                        <ul style="font-size:12px;color:#666;">
                                                <li>Неверный путь — попробуйте вписать в "Каталог на FTP" один из вариантов: <code>/public_html/</code>, <code>/</code>, <code>/home/USER/domains/DOMAIN/public_html/</code></li>
                                                <li>Файл удалён — проверьте через FileZilla, что ZIP всё ещё на FTP</li>
                                                <li>Нет прав на чтение каталога</li>
                                        </ul>
                                <?php endif; ?>
                        </div>
                <?php endif; ?>
        </div>

        <div class="bsi-card">
                <h2><?php esc_html_e( 'WP-Cron статусы', 'beestore-integration' ); ?></h2>
                <table class="widefat striped">
                        <tr>
                                <th><?php esc_html_e( 'Импорт каталога', 'beestore-integration' ); ?></th>
                                <td>
                                        <?php if ( $crons['import'] ) : ?>
                                                <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $crons['import'] + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ); ?>
                                                <span class="bsi-ok"> (запланирован)</span>
                                        <?php else : ?>
                                                <span class="bsi-error"><?php esc_html_e( 'не запланирован', 'beestore-integration' ); ?></span>
                                        <?php endif; ?>
                                </td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'Синхронизация статусов', 'beestore-integration' ); ?></th>
                                <td>
                                        <?php if ( $crons['status'] ) : ?>
                                                <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $crons['status'] + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ); ?>
                                                <span class="bsi-ok"> (запланирован)</span>
                                        <?php else : ?>
                                                <span class="bsi-error"><?php esc_html_e( 'не запланирован', 'beestore-integration' ); ?></span>
                                        <?php endif; ?>
                                </td>
                        </tr>
                        <tr>
                                <th><?php esc_html_e( 'Обработка очереди ретраев', 'beestore-integration' ); ?></th>
                                <td>
                                        <?php if ( $crons['queue'] ) : ?>
                                                <?php echo esc_html( gmdate( 'Y-m-d H:i:s', $crons['queue'] + get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ); ?>
                                                <span class="bsi-ok"> (запланирован)</span>
                                        <?php else : ?>
                                                <span class="bsi-error"><?php esc_html_e( 'не запланирован', 'beestore-integration' ); ?></span>
                                        <?php endif; ?>
                                </td>
                        </tr>
                </table>
                <p class="description">
                        <?php esc_html_e( 'Если cron не запускается — проверьте, что в wp-config.php не отключён WP-Cron (DISABLE_WP_CRON).', 'beestore-integration' ); ?>
                </p>
        </div>
</div>
