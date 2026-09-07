<?php
/**
 * Шаблон страницы «⚠ Для разработчика» — опасная зона с парольной защитой.
 *
 * Доступ:
 *   1. Право 'manage_options' (проверяется в BSI_Admin::render_dev_zone_page)
 *   2. Дополнительный пароль (4-часовая сессия через transient)
 *
 * Содержит:
 *   - Удаление ВСЕХ картинок BeeStore (из БД + с диска)
 *   - Удаление только дублей картинок
 *   - Очистка диска: дубликаты файлов (-1, -2, -3 суффиксы)
 *   - Скан orphan миниатюр WordPress
 *   - Удаление orphan миниатюр
 *   - Смена пароля
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

// $session уже должна быть установлена в BSI_Admin::render_dev_zone_page().
if ( ! isset( $session ) ) {
        $session = false;
}
?>

<div class="wrap bsi-wrap">
        <h1>
                <span class="dashicons dashicons-shield" style="color:#d63638;"></span>
                <?php esc_html_e( '⚠ Для разработчика — Опасная зона', 'beestore-integration' ); ?>
        </h1>

        <?php if ( ! $session ) : ?>
                <!-- ═══ ФОРМА ВХОДА ═══ -->
                <div class="bsi-card" style="border-left:4px solid #d63638;max-width:500px;">
                        <h2 style="color:#d63638;">
                                <span class="dashicons dashicons-lock"></span>
                                <?php esc_html_e( 'Вход в опасную зону', 'beestore-integration' ); ?>
                        </h2>
                        <p>
                                <?php esc_html_e( 'Эта страница содержит инструменты, которые могут необратимо удалить данные. Введите пароль для доступа.', 'beestore-integration' ); ?>
                        </p>
                        <p>
                                <?php esc_html_e( 'Пароль по умолчанию:', 'beestore-integration' ); ?>
                                <code>beestore-dev</code>
                                <em style="color:#d63638;"><?php esc_html_e( '(обязательно смените после первого входа!)', 'beestore-integration' ); ?></em>
                        </p>

                        <form method="post" action="">
                                <?php wp_nonce_field( 'bsi_dev_zone' ); ?>
                                <input type="hidden" name="bsi_dev_action" value="login">
                                <table class="form-table">
                                        <tr>
                                                <th scope="row">
                                                        <label for="bsi_dev_password"><?php esc_html_e( 'Пароль', 'beestore-integration' ); ?></label>
                                                </th>
                                                <td>
                                                        <input type="password" name="bsi_dev_password" id="bsi_dev_password" class="regular-text" required autocomplete="current-password">
                                                </td>
                                        </tr>
                                </table>
                                <p class="submit">
                                        <button type="submit" class="button button-primary">
                                                <span class="dashicons dashicons-unlock-alt"></span>
                                                <?php esc_html_e( 'Войти', 'beestore-integration' ); ?>
                                        </button>
                                </p>
                        </form>
                </div>

                <div class="bsi-card" style="max-width:500px;">
                        <h3>Что в опасной зоне?</h3>
                        <ul style="list-style:disc;padding-left:20px;">
                                <li><strong style="color:#c62828;">Удаление ВСЕХ товаров и атрибутов BeeStore</strong> — полная очистка каталога</li>
                                <li><strong style="color:#d63638;">Удаление всех картинок BeeStore</strong> — полная очистка Media Library от картинок плагина</li>
                                <li><strong style="color:#d63636;">Удаление дублей картинок</strong> — оставляет по одной каждого изображения</li>
                                <li><strong style="color:#d63638;">Очистка диска от дублей файлов</strong> — удаление файлов с суффиксами -1, -2, -3</li>
                                <li><strong style="color:#d63628;">Удаление orphan миниатюр</strong> — миниатюры с незарегистрированными размерами</li>
                                <li><strong>Смена пароля</strong></li>
                        </ul>
                </div>

        <?php else : ?>
                <!-- ═══ АВТОРИЗОВАН — показываем инструменты ═══ -->

                <div class="bsi-card" style="border-left:4px solid #2e7d32;display:flex;justify-content:space-between;align-items:center;">
                        <div>
                                <strong style="color:#2e7d32;">
                                        <span class="dashicons dashicons-yes-alt"></span>
                                        <?php esc_html_e( 'Доступ разрешён', 'beestore-integration' ); ?>
                                </strong>
                                &nbsp;|&nbsp;
                                <?php esc_html_e( 'Сессия истекает через 4 часа после входа.', 'beestore-integration' ); ?>
                        </div>
                        <form method="post" action="" style="display:inline;">
                                <?php wp_nonce_field( 'bsi_dev_zone' ); ?>
                                <input type="hidden" name="bsi_dev_action" value="logout">
                                <button type="submit" class="button button-small">
                                        <span class="dashicons dashicons-exit"></span>
                                        <?php esc_html_e( 'Выйти', 'beestore-integration' ); ?>
                                </button>
                        </form>
                </div>

                <!-- ═══ ВНУТРЕННИЕ ТАБЫ ═══ -->
                <?php
                $current_tab = isset( $_GET['devtab'] ) ? sanitize_key( $_GET['devtab'] ) : 'danger';
                $base_url = admin_url( 'admin.php?page=bsi-dev-zone' );
                ?>
                <script>
			jQuery(function($){
				// Показываем все .bsi-tab (на случай если settings-page JS скрыл их).
				$('.bsi-tab').show();
			});
			</script>
			
		<style>
			.bsi-dev-tabs-wrapper { display:flex; gap:2px; flex-wrap:wrap; }
			.bsi-devtab {
				display:inline-block;
				padding:8px 14px;
				margin:0 1px -1px 0;
				background:#f0f0f1;
				border:1px solid #c3c4c7;
				border-bottom:none;
				border-radius:4px 4px 0 0;
				text-decoration:none;
				color:#50575e;
				font-weight:500;
				font-size:13px;
				line-height:1.5;
			}
			.bsi-devtab:hover { background:#e5e5e7; color:#1d2327; }
			.bsi-devtab-active {
				background:#fff;
				border-bottom:1px solid #fff;
				color:#1d2327;
				font-weight:600;
			}
		</style>
		<h2 class="bsi-dev-tabs-wrapper" style="margin:15px 0 20px;border-bottom:1px solid #c3c4c7;">
                        <a href="<?php echo esc_url( add_query_arg( 'devtab', 'settings', $base_url ) ); ?>" class="nav-tab bsi-devtab <?php echo 'settings' === $current_tab ? 'nav-tab-active' : ''; ?>">
                                <span class="dashicons dashicons-admin-settings" style="vertical-align:middle;margin-right:4px;"></span>
                                <?php esc_html_e( 'Настройки', 'beestore-integration' ); ?>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( 'devtab', 'catalog', $base_url ) ); ?>" class="nav-tab bsi-devtab <?php echo 'catalog' === $current_tab ? 'nav-tab-active' : ''; ?>">
                                <span class="dashicons dashicons-category" style="vertical-align:middle;margin-right:4px;"></span>
                                <?php esc_html_e( 'Каталог с FTP', 'beestore-integration' ); ?>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( 'devtab', 'logs', $base_url ) ); ?>" class="nav-tab bsi-devtab <?php echo 'logs' === $current_tab ? 'nav-tab-active' : ''; ?>">
                                <span class="dashicons dashicons-list-view" style="vertical-align:middle;margin-right:4px;"></span>
                                <?php esc_html_e( 'Логи', 'beestore-integration' ); ?>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( 'devtab', 'diagnostics', $base_url ) ); ?>" class="nav-tab bsi-devtab <?php echo 'diagnostics' === $current_tab ? 'nav-tab-active' : ''; ?>">
                                <span class="dashicons dashicons-search" style="vertical-align:middle;margin-right:4px;"></span>
                                <?php esc_html_e( 'Диагностика', 'beestore-integration' ); ?>
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( 'devtab', 'danger', $base_url ) ); ?>" class="nav-tab bsi-devtab <?php echo 'danger' === $current_tab ? 'nav-tab-active' : ''; ?>">
                                <span class="dashicons dashicons-warning" style="vertical-align:middle;margin-right:4px;color:#d63638;"></span>
                                <?php esc_html_e( '⚠ Опасные операции', 'beestore-integration' ); ?>
                        </a>
                </h2>

                <?php
                // Контент табов.
                if ( 'settings' === $current_tab ) {
                        // Рендерим страницу настроек.
                        echo '<div class="bsi-card">';
                        BSI_Settings::instance()->render_settings_page();
                        echo '</div>';
                } elseif ( 'catalog' === $current_tab ) {
                        // Рендерим каталог с FTP.
                        echo '<div class="bsi-card">';
                        BSI_Admin::instance()->render_catalog_browser_page();
                        echo '</div>';
                } elseif ( 'logs' === $current_tab ) {
                        // Рендерим логи.
                        echo '<div class="bsi-card">';
                        BSI_Admin::instance()->render_logs_page();
                        echo '</div>';
                } elseif ( 'diagnostics' === $current_tab ) {
                        // Рендерим диагностику.
                        echo '<div class="bsi-card">';
                        BSI_Admin::instance()->render_diagnostics_page();
                        echo '</div>';
                } else {
                        // 'danger' — опасные операции (по умолчанию).
                        // Контент ниже — старые блоки опасных операций.
                }
                ?>

                <?php if ( 'danger' === $current_tab ) : ?>

                <!-- ═══ 1. УДАЛЕНИЕ ВСЕХ ТОВАРОВ И АТРИБУТОВ BEESTORE ═══ -->
                <div class="bsi-card" style="border-left:4px solid #c62828;background:#fef7f7;">
                        <h2 style="color:#c62828;">
                                <span class="dashicons dashicons-warning"></span>
                                <?php esc_html_e( '⚠ Полная очистка: удалить ВСЕ товары и атрибуты BeeStore', 'beestore-integration' ); ?>
                        </h2>
                        <p>
                                <?php esc_html_e( 'Удалит ВСЕ товары BeeStore (по meta _bsi_igu_articolo), все бренды, категории, цвета, размеры и другие атрибуты, созданные плагином.', 'beestore-integration' ); ?>
                        </p>
                        <p style="color:#c62828;font-weight:600;">
                                <?php esc_html_e( 'Это действие необратимо! Используйте, если импорт пошёл криво (например, бренды создались неправильно) и хотите начать с чистого листа.', 'beestore-integration' ); ?>
                        </p>
                        <p>
                                <button type="button" class="button button-link-delete" id="bsi-purge-all">
                                        <span class="dashicons dashicons-trash"></span>
                                        <?php esc_html_e( 'Удалить все товары и атрибуты BeeStore', 'beestore-integration' ); ?>
                                </button>
                                <button type="button" class="button" id="bsi-purge-cancel" style="display:none;background:#c62828;color:#fff;border-color:#c62828;">
                                        <span class="dashicons dashicons-no-alt"></span>
                                        <?php esc_html_e( 'ОТМЕНИТЬ УДАЛЕНИЕ', 'beestore-integration' ); ?>
                                </button>
                                <span id="bsi-purge-status" style="margin-left:10px;"></span>
                        </p>
                </div>

                <!-- ═══ 2. УДАЛЕНИЕ ВСЕХ КАРТИНОК BEESTORE ═══ -->
                <div class="bsi-card" style="border-left:4px solid #d63638;">
                        <h2 style="color:#d63638;">
                                <span class="dashicons dashicons-warning"></span>
                                <?php esc_html_e( 'Удалить ВСЕ картинки BeeStore', 'beestore-integration' ); ?>
                        </h2>
                        <p>
                                <?php esc_html_e( 'Удалит все attachments, импортированные плагином BeeStore (по meta _bsi_imported_by и _bsi_image_basename), вместе с файлами на диске. Товары остаются на месте — следующий импорт заново скачает картинки.', 'beestore-integration' ); ?>
                        </p>
                        <p>
                                <?php esc_html_e( 'Полезно когда накопились дубликаты или когда нужно пересоздать картинки с нуля после ошибки.', 'beestore-integration' ); ?>
                        </p>
                        <p>
                                <button type="button" class="button button-secondary" id="bsi-purge-images">
                                        <span class="dashicons dashicons-trash"></span>
                                        <?php esc_html_e( 'Удалить только картинки BeeStore', 'beestore-integration' ); ?>
                                </button>
                                <button type="button" class="button" id="bsi-purge-images-cancel" style="display:none;background:#c62828;color:#fff;border-color:#c62828;">
                                        <span class="dashicons dashicons-no-alt"></span>
                                        <?php esc_html_e( 'ОТМЕНИТЬ', 'beestore-integration' ); ?>
                                </button>
                                <span id="bsi-purge-images-status" style="margin-left:10px;"></span>
                        </p>
                </div>

                <!-- ═══ 2. УДАЛЕНИЕ ТОЛЬКО ДУБЛЕЙ КАРТИНОК ═══ -->
                <div class="bsi-card" style="border-left:4px solid #f57c00;">
                        <h2 style="color:#f57c00;">
                                <span class="dashicons dashicons-admin-page"></span>
                                <?php esc_html_e( 'Удалить только дубликаты картинок', 'beestore-integration' ); ?>
                        </h2>
                        <p>
                                <?php esc_html_e( 'Группирует все attachments BeeStore по basename. Для каждой группы с > 1 attachment — оставляет первый (самый старый), остальные удаляет. Также проверяет по _wp_attached_file.', 'beestore-integration' ); ?>
                        </p>
                        <p>
                                <button type="button" class="button button-secondary" id="bsi-purge-dup-images" style="border-color:#f57c00;color:#f57c00;">
                                        <span class="dashicons dashicons-admin-page"></span>
                                        <?php esc_html_e( 'Удалить только дубликаты картинок', 'beestore-integration' ); ?>
                                </button>
                                <span id="bsi-purge-dup-status" style="margin-left:10px;"></span>
                        </p>
                </div>

                <!-- ═══ 3. ОЧИСТКА ДИСКА: ДУБЛИ ФАЙЛОВ (-1, -2, -3) ═══ -->
                <div class="bsi-card" style="border-left:4px solid #d63638;">
                        <h2 style="color:#d63638;">
                                <span class="dashicons dashicons-warning"></span>
                                <?php esc_html_e( 'Очистка диска: дубликаты файлов (-1, -2, -3)', 'beestore-integration' ); ?>
                        </h2>
                        <p>
                                <?php esc_html_e( 'Находит и удаляет orphan-файлы с суффиксами -1, -2, -3 в папке uploads/. Эти файлы созданы старыми версиями плагина и не привязаны к БД.', 'beestore-integration' ); ?>
                        </p>
                        <p>
                                <strong><?php esc_html_e( 'Что считается дубликатом:', 'beestore-integration' ); ?></strong>
                                <code>2000019668213_1-1.jpg</code>, <code>2000019668213_1-2.jpg</code>
                                &nbsp;|&nbsp;
                                <strong><?php esc_html_e( 'Оригинал НЕ трогается:', 'beestore-integration' ); ?></strong>
                                <code>2000019668213_1.jpg</code>
                        </p>

                        <table class="widefat" id="bsi-disk-stats" style="margin:10px 0;">
                                <tr>
                                        <th><?php esc_html_e( 'Всего файлов в uploads/', 'beestore-integration' ); ?></th>
                                        <td id="bsi-total-inodes">—</td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Найдено дублей (-N.ext)', 'beestore-integration' ); ?></th>
                                        <td id="bsi-dup-count">—</td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Размер дублей', 'beestore-integration' ); ?></th>
                                        <td id="bsi-dup-size">—</td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Удалено за сессию', 'beestore-integration' ); ?></th>
                                        <td id="bsi-deleted-total">0</td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Освобождено места', 'beestore-integration' ); ?></th>
                                        <td id="bsi-freed-total">0 B</td>
                                </tr>
                        </table>

                        <p>
                                <button type="button" class="button button-secondary" id="bsi-scan-disk">
                                        <span class="dashicons dashicons-search"></span>
                                        <?php esc_html_e( 'Сканировать диск на дубли', 'beestore-integration' ); ?>
                                </button>
                                <button type="button" class="button" id="bsi-delete-disk-dup" style="display:none;background:#d63638;color:#fff;border-color:#d63638;">
                                        <span class="dashicons dashicons-trash"></span>
                                        <?php esc_html_e( 'УДАЛИТЬ ВСЕ ДУБЛИ С ДИСКА', 'beestore-integration' ); ?>
                                </button>
                                <button type="button" class="button" id="bsi-delete-disk-dup-cancel" style="display:none;">
                                        <?php esc_html_e( 'Остановить', 'beestore-integration' ); ?>
                                </button>
                                <span id="bsi-disk-status" style="margin-left:10px;font-weight:bold;"></span>
                        </p>

                        <div id="bsi-dup-preview" style="max-height:300px;overflow-y:auto;border:1px solid #ddd;padding:10px;display:none;background:#fafafa;font-family:monospace;font-size:11px;">
                        </div>
                </div>

                <!-- ═══ 4. УПРАВЛЕНИЕ ПАРОЛЕМ ═══ -->
                <div class="bsi-card" style="border-left:4px solid #2271b1;">
                        <h2 style="color:#2271b1;">
                                <span class="dashicons dashicons-key"></span>
                                <?php esc_html_e( 'Сменить пароль', 'beestore-integration' ); ?>
                        </h2>
                        <p>
                                <?php esc_html_e( 'Пароль хранится в БД в виде hash (password_hash). Рекомендуется сменить пароль по умолчанию на свой.', 'beestore-integration' ); ?>
                        </p>
                        <form method="post" action="">
                                <?php wp_nonce_field( 'bsi_dev_zone' ); ?>
                                <input type="hidden" name="bsi_dev_action" value="change_password">
                                <table class="form-table">
                                        <tr>
                                                <th scope="row">
                                                        <label for="bsi_dev_new_password"><?php esc_html_e( 'Новый пароль', 'beestore-integration' ); ?></label>
                                                </th>
                                                <td>
                                                        <input type="password" name="bsi_dev_new_password" id="bsi_dev_new_password" class="regular-text" required minlength="6" autocomplete="new-password">
                                                        <p class="description"><?php esc_html_e( 'Минимум 6 символов.', 'beestore-integration' ); ?></p>
                                                </td>
                                        </tr>
                                </table>
                                <p class="submit">
                                        <button type="submit" class="button button-primary">
                                                <span class="dashicons dashicons-update"></span>
                                                <?php esc_html_e( 'Сменить пароль', 'beestore-integration' ); ?>
                                        </button>
                                </p>
                        </form>
                </div>

                <!-- ═══ ИНФО ═══ -->
                <div class="bsi-card" style="background:#f0f0f0;">
                        <h3><?php esc_html_e( 'Информация о защите', 'beestore-integration' ); ?></h3>
                        <table class="widefat">
                                <tr>
                                        <th><?php esc_html_e( 'Уровень доступа', 'beestore-integration' ); ?></th>
                                        <td><code>manage_options</code> (<?php esc_html_e( 'только администраторы', 'beestore-integration' ); ?>)</td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Доп. пароль', 'beestore-integration' ); ?></th>
                                        <td><?php esc_html_e( 'Да, hash в опции bsi_dev_zone_password_hash', 'beestore-integration' ); ?></td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Сессия', 'beestore-integration' ); ?></th>
                                        <td><?php esc_html_e( '4 часа, через transient', 'beestore-integration' ); ?> <code>bsi_dev_zone_session_{user_id}</code></td>
                                </tr>
                                <tr>
                                        <th><?php esc_html_e( 'Текущий пользователь', 'beestore-integration' ); ?></th>
                                        <td>
                                                <?php
                                                $current_user = wp_get_current_user();
                                                echo esc_html( $current_user->display_name . ' (' . $current_user->user_login . ')' );
                                                ?>
                                        </td>
                                </tr>
                        </table>
                </div>

		<?php endif; // конец danger tab ?>

	<?php endif; // конец session ?>
</div>

<!-- ═══ JavaScript (только если авторизован) ═══ -->
<?php if ( $session ) : ?>
<script>
jQuery(function($) {
        // ═══ Полная очистка: удалить все товары и атрибуты BeeStore ═══
        var purgeAbort = false;
        $('#bsi-purge-all').on('click', function(e) {
                e.preventDefault();
                if (!confirm('<?php esc_attr_e( 'ВНИМАНИЕ! Будут удалены ВСЕ товары BeeStore, атрибуты, категории. Это НЕОБРАТИМО! Вы уверены?', 'beestore-integration' ); ?>')) return;
                var $btn = $(this);
                purgeAbort = false;
                $btn.prop('disabled', true);
                $('#bsi-purge-cancel').show();
                $('#bsi-purge-status').html('<span style="color:#c62828;font-weight:600;">⚠ УДАЛЕНИЕ ТОВАРОВ ИДЁТ... Нажмите ОТМЕНИТЬ чтобы остановить!</span>');

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_purge_all',
                        nonce: bsiAdmin.nonce
                }, function(response) {
                        $btn.prop('disabled', false);
                        $('#bsi-purge-cancel').hide();
                        if (purgeAbort) {
                                $('#bsi-purge-status').html('<span style="color:#f57c00;">⏸ Удаление отменено пользователем</span>');
                                return;
                        }
                        if (response.success) {
                                $('#bsi-purge-status').html('<span style="color:#2e7d32;">✓ ' + response.data.message + '</span>');
                        } else {
                                $('#bsi-purge-status').html('<span style="color:#c62828;">✗ ' + (response.data.message || 'Ошибка') + '</span>');
                        }
                }).fail(function() {
                        $btn.prop('disabled', false);
                        $('#bsi-purge-cancel').hide();
                        $('#bsi-purge-status').html('<span style="color:#c62828;">✗ AJAX error</span>');
                });
        });

        $('#bsi-purge-cancel').on('click', function(e) {
                e.preventDefault();
                purgeAbort = true;
                $(this).hide();
                $('#bsi-purge-all').prop('disabled', false);
                $('#bsi-purge-status').html('<span style="color:#f57c00;">⏸ Отмена... текущий батч доработает и остановится.</span>');
        });

        // ═══ Удаление всех картинок BeeStore ═══
        var purgeImgAbort = false;
        $('#bsi-purge-images').on('click', function(e) {
                e.preventDefault();
                if (!confirm('<?php esc_attr_e( 'Удалить ВСЕ картинки BeeStore из Media Library? Это необратимо. Товары останутся.', 'beestore-integration' ); ?>')) return;
                var $btn = $(this);
                $btn.prop('disabled', true);
                $('#bsi-purge-images-cancel').show();
                $('#bsi-purge-images-status').html('<span class="bsi-spinner"></span> Удаление...');

                function purgeBatch() {
                        if (purgeImgAbort) {
                                $btn.prop('disabled', false);
                                $('#bsi-purge-images-cancel').hide();
                                $('#bsi-purge-images-status').html('<span style="color:#f57c00;">⏸ Остановлено</span>');
                                return;
                        }
                        $.post(bsiAdmin.ajaxUrl, {
                                action: 'bsi_purge_images',
                                nonce: bsiAdmin.nonce
                        }, function(response) {
                                if (response.success) {
                                        var d = response.data;
                                        if (d.has_more) {
                                                $('#bsi-purge-images-status').html('<span class="bsi-spinner"></span> Удалено: ' + d.deleted + ', ошибок: ' + d.failed);
                                                setTimeout(purgeBatch, 500);
                                        } else {
                                                $btn.prop('disabled', false);
                                                $('#bsi-purge-images-cancel').hide();
                                                var msg = '✓ Готово! Удалено: ' + d.deleted;
                                                if (d.failed > 0) msg += ', ошибок: ' + d.failed;
                                                $('#bsi-purge-images-status').html('<span style="color:#2e7d32;">' + msg + '</span>');
                                        }
                                } else {
                                        $btn.prop('disabled', false);
                                        $('#bsi-purge-images-cancel').hide();
                                        $('#bsi-purge-images-status').html('<span style="color:#c62828;">✗ ' + (d.message || 'Ошибка') + '</span>');
                                }
                        }).fail(function() {
                                $btn.prop('disabled', false);
                                $('#bsi-purge-images-cancel').hide();
                                $('#bsi-purge-images-status').html('<span style="color:#c62828;">✗ AJAX error</span>');
                        });
                }
                purgeBatch();
        });

        $('#bsi-purge-images-cancel').on('click', function(e) {
                e.preventDefault();
                purgeImgAbort = true;
                $(this).hide();
                $('#bsi-purge-images').prop('disabled', false);
                $('#bsi-purge-images-status').html('<span style="color:#f57c00;">⏸ Отмена...</span>');
        });

        // ═══ Удаление только дублей картинок ═══
        $('#bsi-purge-dup-images').on('click', function(e) {
                e.preventDefault();
                if (!confirm('<?php esc_attr_e( 'Удалить дубликаты картинок? Останется по одной каждого изображения.', 'beestore-integration' ); ?>')) return;
                var $btn = $(this);
                $btn.prop('disabled', true);
                $('#bsi-purge-dup-status').html('<span class="bsi-spinner"></span> Поиск и удаление дублей...');
                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_purge_duplicate_images',
                        nonce: bsiAdmin.nonce
                }, function(response) {
                        $btn.prop('disabled', false);
                        if (response.success) {
                                $('#bsi-purge-dup-status').html('<span style="color:#2e7d32;">✓ ' + response.data.message + '</span>');
                        } else {
                                $('#bsi-purge-dup-status').html('<span style="color:#c62828;">✗ ' + (response.data.message || 'Ошибка') + '</span>');
                        }
                }).fail(function() {
                        $btn.prop('disabled', false);
                        $('#bsi-purge-dup-status').html('<span style="color:#c62828;">✗ AJAX error</span>');
                });
        });

        // ═══ Очистка диска: дубликаты файлов (-1, -2, -3) ═══
        var bsiDiskDup = {
                deletedTotal: 0,
                freedTotal: 0,
                abort: false,
        };

        function bsiFormatBytes(bytes) {
                if (bytes < 1024) return bytes + ' B';
                if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
                if (bytes < 1024 * 1024 * 1024) return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
                return (bytes / (1024 * 1024 * 1024)).toFixed(2) + ' GB';
        }

        $('#bsi-scan-disk').on('click', function(e) {
                e.preventDefault();
                var $btn = $(this);
                $btn.prop('disabled', true);
                $('#bsi-disk-status').html('<span class="bsi-spinner"></span> Сканирование...');
                $('#bsi-dup-preview').hide().empty();

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_scan_disk_duplicates',
                        nonce: bsiAdmin.nonce,
                        offset: 0
                }, function(response) {
                        $btn.prop('disabled', false);
                        if (response.success) {
                                var d = response.data;
                                $('#bsi-total-inodes').html('<strong style="color:' + (d.total_inodes > 200000 ? '#d63638' : '#2e7d32') + ';">' + d.total_inodes.toLocaleString() + '</strong>');
                                $('#bsi-dup-count').html('<strong style="color:' + (d.duplicates_count > 0 ? '#d63638' : '#2e7d32') + ';">' + d.duplicates_count.toLocaleString() + '</strong>');
                                $('#bsi-dup-size').html('<strong>' + d.duplicates_size_human + '</strong>');

                                if (d.duplicates_count > 0) {
                                        $('#bsi-delete-disk-dup').show();
                                        $('#bsi-disk-status').html('<span style="color:#d63638;">Найдено ' + d.duplicates_count + ' дублей.</span>');

                                        var html = '<strong>Превью (первые 100):</strong><br>';
                                        var preview = d.duplicates.slice(0, 100);
                                        for (var i = 0; i < preview.length; i++) {
                                                var f = preview[i];
                                                html += '<div>' + (f.has_original ? '✓' : '⚠') + ' ' + f.file + ' <em>(' + f.size_human + ')</em></div>';
                                        }
                                        if (d.duplicates_count > 100) {
                                                html += '<div style="margin-top:5px;color:#666;">... и ещё ' + (d.duplicates_count - 100) + '</div>';
                                        }
                                        $('#bsi-dup-preview').html(html).show();
                                } else {
                                        $('#bsi-disk-status').html('<span style="color:#2e7d32;">✓ Дубликатов не найдено!</span>');
                                }
                        } else {
                                $('#bsi-disk-status').html('<span style="color:#c62828;">✗ ' + (response.data.message || 'Ошибка') + '</span>');
                        }
                }).fail(function(xhr) {
                        $btn.prop('disabled', false);
                        $('#bsi-disk-status').html('<span style="color:#c62828;">✗ AJAX error: ' + xhr.status + '</span>');
                });
        });

        function bsiDeleteDiskDupBatch() {
                if (bsiDiskDup.abort) {
                        $('#bsi-delete-disk-dup').show().prop('disabled', false);
                        $('#bsi-delete-disk-dup-cancel').hide();
                        $('#bsi-disk-status').html('<span style="color:#f57c00;">⏸ Остановлено. Удалено: ' + bsiDiskDup.deletedTotal + '</span>');
                        return;
                }

                $('#bsi-disk-status').html('<span class="bsi-spinner"></span> Удаление... (всего: ' + bsiDiskDup.deletedTotal + ', освобождено: ' + bsiFormatBytes(bsiDiskDup.freedTotal) + ')');

                $.post(bsiAdmin.ajaxUrl, {
                        action: 'bsi_delete_disk_duplicates',
                        nonce: bsiAdmin.nonce,
                        delete_all: '1'
                }, function(response) {
                        if (response.success) {
                                var d = response.data;
                                bsiDiskDup.deletedTotal += d.deleted;
                                bsiDiskDup.freedTotal += d.freed_bytes;
                                $('#bsi-deleted-total').text(bsiDiskDup.deletedTotal.toLocaleString());
                                $('#bsi-freed-total').text(bsiFormatBytes(bsiDiskDup.freedTotal));

                                if (d.has_more && !bsiDiskDup.abort) {
                                        setTimeout(bsiDeleteDiskDupBatch, 500);
                                } else {
                                        $('#bsi-delete-disk-dup').show().prop('disabled', false);
                                        $('#bsi-delete-disk-dup-cancel').hide();
                                        var msg = '✓ Готово! Удалено: ' + bsiDiskDup.deletedTotal + ', освобождено: ' + bsiFormatBytes(bsiDiskDup.freedTotal);
                                        $('#bsi-disk-status').html('<span style="color:#2e7d32;">' + msg + '</span>');
                                        $('#bsi-scan-disk').trigger('click');
                                }
                        } else {
                                $('#bsi-delete-disk-dup').show().prop('disabled', false);
                                $('#bsi-delete-disk-dup-cancel').hide();
                                $('#bsi-disk-status').html('<span style="color:#c62828;">✗ ' + (response.data.message || 'Ошибка') + '</span>');
                        }
                }).fail(function(xhr) {
                        $('#bsi-delete-disk-dup').show().prop('disabled', false);
                        $('#bsi-delete-disk-dup-cancel').hide();
                        $('#bsi-disk-status').html('<span style="color:#c62828;">✗ AJAX error: ' + xhr.status + '</span>');
                });
        }

        $('#bsi-delete-disk-dup').on('click', function(e) {
                e.preventDefault();
                if (!confirm('<?php esc_attr_e( 'ВНИМАНИЕ! Будут удалены ВСЕ файлы вида basename-N.ext. Оригиналы НЕ трогаются. Необратимо. Продолжить?', 'beestore-integration' ); ?>')) return;
                bsiDiskDup.deletedTotal = 0;
                bsiDiskDup.freedTotal = 0;
                bsiDiskDup.abort = false;
                $(this).prop('disabled', true).hide();
                $('#bsi-delete-disk-dup-cancel').show();
                bsiDeleteDiskDupBatch();
        });

        $('#bsi-delete-disk-dup-cancel').on('click', function(e) {
                e.preventDefault();
                bsiDiskDup.abort = true;
                $(this).prop('disabled', true);
        });
});
</script>
<?php endif; ?>
