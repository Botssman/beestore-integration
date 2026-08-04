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

        <!-- Проверка обновлений GitHub -->
        <div class="bsi-card">
                <h2><?php esc_html_e( 'Проверка обновлений плагина (GitHub)', 'beestore-integration' ); ?></h2>
                <p>
                        <?php esc_html_e( 'Принудительно проверить наличие новой версии на GitHub. Кнопка очищает кеш WordPress и напрямую вызывает GitHub API.', 'beestore-integration' ); ?>
                </p>
                <p>
                        <strong><?php esc_html_e( 'Текущая версия:', 'beestore-integration' ); ?></strong>
                        <code><?php echo esc_html( BSI_VERSION ); ?></code>
                </p>
                <p>
                        <button type="button" class="button button-primary" id="bsi-check-github-update">
                                <span class="dashicons dashicons-update"></span>
                                <?php esc_html_e( 'Проверить обновления GitHub', 'beestore-integration' ); ?>
                        </button>
                        <span id="bsi-github-update-status" style="margin-left:15px;"></span>
                </p>
                <div id="bsi-github-update-result" style="display:none;margin-top:15px;"></div>
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

<script>
jQuery(document).ready(function($){
        $('#bsi-check-github-update').on('click', function(){
                var $btn = $(this);
                var $status = $('#bsi-github-update-status');
                var $result = $('#bsi-github-update-result');
                $btn.prop('disabled', true);
                $status.html('<span class="spinner is-active" style="float:none;vertical-align:middle;"></span> Проверка...');
                $result.hide().empty();

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_check_github_update',
                        nonce: bsiAdmin.nonce
                }, function(response){
                        $btn.prop('disabled', false);
                        if (response.success) {
                                var d = response.data;
                                $status.html('<span style="color:#2e7d32;">✓ Проверка выполнена</span>');

                                var html = '<div style="background:' + (d.has_update ? '#e7f5ed' : '#f0f0f1') + ';border:1px solid ' + (d.has_update ? '#46b450' : '#ccc') + ';border-radius:4px;padding:15px;">';
                                html += '<h3 style="margin-top:0;">' + (d.has_update ? '🔄 Доступно обновление' : '✓ Установлена последняя версия') + '</h3>';
                                html += '<p><strong>Текущая версия:</strong> <code>' + d.current_version + '</code></p>';
                                if (d.has_update) {
                                        html += '<p><strong>Новая версия:</strong> <code>' + d.remote_version + '</code></p>';
                                        html += '<p><strong>Tag:</strong> <code>' + d.tag_name + '</code></p>';
                                        html += '<p><strong>Опубликован:</strong> ' + d.published_at + '</p>';
                                        html += '<p><strong>ZIP:</strong> <a href="' + d.package_url + '" target="_blank">' + d.package_url + '</a></p>';
                                        html += '<p style="margin-top:15px;font-size:14px;">' + d.message + '</p>';
                                        html += '<p style="margin-top:15px;"><a href="' + (window.location.origin + window.location.pathname.replace(/\/wp-admin\/.*$/, '/wp-admin/update-core.php')) + '" class="button button-primary">Перейти к обновлению →</a></p>';
                                } else {
                                        html += '<p style="margin-top:10px;">' + d.message + '</p>';
                                }
                                if (d.warnings && d.warnings.length > 0) {
                                        html += '<div style="margin-top:15px;background:#fff8e5;border:1px solid #ffb900;padding:10px 14px;border-radius:3px;">';
                                        html += '<strong>⚠ Предупреждения:</strong><ul style="margin-top:8px;">';
                                        for (var i = 0; i < d.warnings.length; i++) {
                                                html += '<li>' + d.warnings[i] + '</li>';
                                        }
                                        html += '</ul></div>';
                                }
                                html += '</div>';
                                $result.html(html).show();
                        } else {
                                $status.html('<span style="color:#c62828;">✗ Ошибка</span>');
                                $result.html('<div style="background:#fbeaea;border:1px solid #c62828;padding:15px;border-radius:4px;"><strong>Ошибка:</strong> ' + (response.data.message || 'Неизвестная ошибка') + '</div>').show();
                        }
                }).fail(function(){
                        $btn.prop('disabled', false);
                        $status.html('<span style="color:#c62828;">✗ AJAX error</span>');
                        $result.html('<div style="background:#fbeaea;border:1px solid #c62828;padding:15px;border-radius:4px;">AJAX error — проверьте консоль браузера.</div>').show();
                });
        });
});
</script>
