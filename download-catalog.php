<?php
/**
 * Загрузчик файлов с FTP Sirio на ваш сервер.
 *
 * Что делает:
 *   1. Подключается к FTP Sirio (использует доступы из настроек плагина)
 *   2. Показывает список всех файлов
 *   3. По клику — скачивает выбранный файл на ваш сервер
 *   4. Даёт ссылку для скачивания через браузер
 *
 * Использование:
 *   1. Закачайте этот файл в /wp-content/uploads/beestore/ (НЕ в корень WP!)
 *   2. Откройте в браузере: https://viaqurata.com/wp-content/uploads/beestore/download-catalog.php
 *   3. Выберите файл из списка → нажмите "Скачать на сервер"
 *   4. После скачивания — нажмите "Скачать на компьютер"
 *
 * ВНИМАНИЕ: после использования — УДАЛИТЕ этот файл!
 */

// Загружаем WordPress, чтобы получить доступ к опциям плагина.
$wp_load_path = dirname( __FILE__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load_path ) ) {
	// Пробуем найти wp-load.php в типичных местах.
	$candidates = array(
		dirname( __FILE__, 4 ) . '/wp-load.php',
		dirname( __FILE__, 3 ) . '/wp-load.php',
		$_SERVER['DOCUMENT_ROOT'] . '/wp-load.php',
	);
	foreach ( $candidates as $c ) {
		if ( file_exists( $c ) ) {
			$wp_load_path = $c;
			break;
		}
	}
}

if ( file_exists( $wp_load_path ) ) {
	require_once $wp_load_path;
} else {
	die( 'Не удалось найти wp-load.php. Положите этот файл в /wp-content/uploads/beestore/' );
}

// Проверка авторизации — только админы.
if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_woocommerce' ) ) {
	die( 'Доступ запрещён. Зайдите как администратор WordPress.' );
}

header( 'Content-Type: text/html; charset=utf-8' );

$settings = get_option( 'bsi_settings', array() );
$ftp_host = isset( $settings['ftp_host'] ) ? $settings['ftp_host'] : '';
$ftp_port = isset( $settings['ftp_port'] ) ? (int) $settings['ftp_port'] : 21;
$ftp_user = isset( $settings['ftp_user'] ) ? $settings['ftp_user'] : '';
$ftp_pass = isset( $settings['ftp_pass'] ) ? $settings['ftp_pass'] : '';
$ftp_path = isset( $settings['ftp_path'] ) ? $settings['ftp_path'] : '/';

// Папка для скачиваемых файлов (текущая папка).
$download_dir = __DIR__;

// URL для скачивания файлов через браузер.
$download_url = plugin_dir_url( __FILE__ );
if ( empty( $download_url ) ) {
	// Если не через плагин — строим URL вручную.
	$download_url = ( isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . dirname( $_SERVER['PHP_SELF'] ) . '/';
}

$action = isset( $_GET['action'] ) ? $_GET['action'] : 'list';
$file_to_download = isset( $_GET['file'] ) ? sanitize_text_field( wp_unslash( $_GET['file'] ) ) : '';
$file_to_get      = isset( $_GET['get'] ) ? sanitize_text_field( wp_unslash( $_GET['get'] ) ) : '';

?>
<!DOCTYPE html>
<html lang="ru">
<head>
	<meta charset="UTF-8">
	<title>Загрузчик каталога BeeStore</title>
	<style>
		body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 1200px; margin: 20px auto; padding: 0 20px; color: #333; }
		h1 { color: #2271b1; }
		.card { background: #fff; border: 1px solid #ccd0d4; border-radius: 4px; padding: 15px 20px; margin: 15px 0; }
		.btn { display: inline-block; padding: 6px 14px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 3px; font-size: 13px; margin: 2px; }
		.btn:hover { background: #135e96; }
		.btn-secondary { background: #f0f0f1; color: #333; }
		.btn-secondary:hover { background: #dcdcde; }
		.btn-danger { background: #d63638; }
		.btn-danger:hover { background: #b32d2e; }
		.files-list { max-height: 500px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; }
		.file-row { padding: 6px 10px; border-bottom: 1px solid #f0f0f1; display: flex; justify-content: space-between; align-items: center; }
		.file-row:hover { background: #f6f7f7; }
		.file-name { font-family: monospace; font-size: 12px; }
		.file-size { color: #666; font-size: 12px; margin-left: 10px; }
		.success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; margin: 10px 0; }
		.error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; margin: 10px 0; }
		.warning { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 12px; border-radius: 4px; margin: 10px 0; }
		.small { font-size: 12px; color: #666; }
		iframe { width: 100%; height: 200px; border: 1px solid #ddd; }
	</style>
</head>
<body>

<h1>📦 Загрузчик каталога BeeStore</h1>

<?php if ( empty( $ftp_host ) || empty( $ftp_user ) ) : ?>
	<div class="error">
		<strong>Настройки FTP не заданы.</strong><br>
		Зайдите в <code>BeeStore → Настройки → FTP</code> и впишите доступы Sirio, потом вернитесь сюда.
	</div>
<?php endif; ?>

<div class="card">
	<h3>Текущие настройки FTP:</h3>
	<table class="small">
		<tr><td><strong>Хост:</strong></td><td><code><?php echo esc_html( $ftp_host ); ?></code></td></tr>
		<tr><td><strong>Порт:</strong></td><td><code><?php echo esc_html( $ftp_port ); ?></code></td></tr>
		<tr><td><strong>Пользователь:</strong></td><td><code><?php echo esc_html( $ftp_user ); ?></code></td></tr>
		<tr><td><strong>Каталог:</strong></td><td><code><?php echo esc_html( $ftp_path ); ?></code></td></tr>
	</table>
	<p class="small">Папка для скачивания на сервере: <code><?php echo esc_html( $download_dir ); ?></code></p>
</div>

<?php
// =====================================================================
// ДЕЙСТВИЕ: скачать файл с FTP на сервер
// =====================================================================
if ( 'fetch' === $action && $file_to_download ) {
	echo '<div class="card">';
	echo '<h3>📥 Скачивание файла с FTP...</h3>';
	echo '<p><strong>Файл:</strong> <code>' . esc_html( $file_to_download ) . '</code></p>';

	if ( ! function_exists( 'ftp_connect' ) ) {
		echo '<div class="error">PHP расширение ftp не установлено.</div>';
	} else {
		$start_time = microtime( true );
		$conn = @ftp_connect( $ftp_host, $ftp_port, 30 );
		if ( ! $conn ) {
			echo '<div class="error">Не удалось подключиться к ' . esc_html( $ftp_host ) . ':' . esc_html( $ftp_port ) . '</div>';
		} else {
			$login = @ftp_login( $conn, $ftp_user, $ftp_pass );
			if ( ! $login ) {
				echo '<div class="error">Неверный логин/пароль FTP.</div>';
			} else {
				ftp_pasv( $conn, true );
				$local_file = $download_dir . '/' . sanitize_file_name( basename( $file_to_download ) );
				echo '<p>Скачиваю с FTP на сервер... (это может занять 1-5 минут для большого файла)</p>';
				echo '<p class="small">Локальный путь: <code>' . esc_html( $local_file ) . '</code></p>';

				// Пробуем с полным путём.
				$success = @ftp_get( $conn, $local_file, $file_to_download, FTP_BINARY );
				if ( ! $success ) {
					// Fallback — путь относительно $ftp_path.
					$fallback_remote = trailingslashit( $ftp_path ) . basename( $file_to_download );
					$success = @ftp_get( $conn, $local_file, $fallback_remote, FTP_BINARY );
				}

				if ( $success ) {
					$elapsed = round( microtime( true ) - $start_time, 2 );
					$size    = size_format( filesize( $local_file ) );
					echo '<div class="success">';
					echo '<strong>✓ Файл скачан за ' . esc_html( $elapsed ) . ' сек</strong><br>';
					echo 'Размер: ' . esc_html( $size ) . '<br>';
					echo 'Локальный путь: <code>' . esc_html( $local_file ) . '</code><br><br>';
					echo '<a class="btn" href="?action=get&file=' . rawurlencode( basename( $file_to_download ) ) . '">💾 Скачать на компьютер</a> ';
					echo '<a class="btn btn-secondary" href="?action=list">← Назад к списку</a>';
					echo '</div>';
				} else {
					echo '<div class="error">Не удалось скачать файл. Проверьте права на папку.</div>';
				}
			}
			ftp_close( $conn );
		}
	}
	echo '</div>';
}

// =====================================================================
// ДЕЙСТВИЕ: отдать файл пользователю (скачать на компьютер)
// =====================================================================
if ( 'get' === $action && $file_to_get ) {
	$local_file = $download_dir . '/' . sanitize_file_name( $file_to_get );
	if ( file_exists( $local_file ) ) {
		// Отдаём файл.
		header( 'Content-Description: File Transfer' );
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $local_file ) . '"' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate' );
		header( 'Pragma: public' );
		header( 'Content-Length: ' . filesize( $local_file ) );
		readfile( $local_file );
		exit;
	} else {
		echo '<div class="error">Файл не найден на сервере: ' . esc_html( $file_to_get ) . '</div>';
	}
}

// =====================================================================
// ДЕЙСТВИЕ: удалить файл с сервера
// =====================================================================
if ( 'delete' === $action && $file_to_get ) {
	$local_file = $download_dir . '/' . sanitize_file_name( $file_to_get );
	if ( file_exists( $local_file ) ) {
		unlink( $local_file );
		echo '<div class="success">✓ Файл ' . esc_html( $file_to_get ) . ' удалён с сервера.</div>';
	}
}

// =====================================================================
// ДЕЙСТВИЕ: показать список файлов
// =====================================================================
if ( 'list' === $action || empty( $action ) ) {

	// Сначала покажем файлы, которые уже скачаны.
	$local_files = glob( $download_dir . '/COMPANY_*' );
	if ( ! empty( $local_files ) ) {
		echo '<div class="card">';
		echo '<h3>💾 Файлы на сервере (уже скачаны)</h3>';
		echo '<p class="small">Эти файлы лежат на вашем сервере и готовы к скачиванию на компьютер:</p>';
		foreach ( $local_files as $local_file ) {
			$name = basename( $local_file );
			$size = size_format( filesize( $local_file ) );
			echo '<div class="file-row">';
			echo '<span><span class="file-name">' . esc_html( $name ) . '</span><span class="file-size">(' . esc_html( $size ) . ')</span></span>';
			echo '<span>';
			echo '<a class="btn" href="?action=get&file=' . rawurlencode( $name ) . '">💾 Скачать</a> ';
			echo '<a class="btn btn-danger" href="?action=delete&file=' . rawurlencode( $name ) . '" onclick="return confirm(\'Удалить с сервера?\')">🗑 Удалить</a>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
	}

	// Подключаемся к FTP и показываем список файлов.
	echo '<div class="card">';
	echo '<h3>📋 Файлы на FTP Sirio</h3>';

	if ( ! function_exists( 'ftp_connect' ) ) {
		echo '<div class="error">PHP расширение ftp не установлено.</div>';
	} else {
		$conn = @ftp_connect( $ftp_host, $ftp_port, 30 );
		if ( ! $conn ) {
			echo '<div class="error">Не удалось подключиться к FTP ' . esc_html( $ftp_host ) . ':' . esc_html( $ftp_port ) . '</div>';
		} else {
			$login = @ftp_login( $conn, $ftp_user, $ftp_pass );
			if ( ! $login ) {
				echo '<div class="error">Неверный логин/пароль FTP.</div>';
			} else {
				ftp_pasv( $conn, true );
				$files = @ftp_nlist( $conn, $ftp_path );
				if ( $files === false ) {
					$files = ftp_nlist( $conn, '/' );
				}

				// Фильтруем только BeeStore файлы.
				$beestore_files = array();
				$other_files = array();
				foreach ( $files as $f ) {
					$basename = basename( ltrim( $f, './' ) );
					if ( preg_match( '/^COMPANY_\d+_0000_[0-9_\-]+\.(zip|csv)$/i', $basename ) ) {
						$size = @ftp_size( $conn, $f );
						$beestore_files[] = array(
							'name' => $basename,
							'path' => $f,
							'size' => $size > 0 ? $size : 0,
							'is_full' => preg_match( '/_0000001\./', $basename ),
						);
					} elseif ( '.' !== $basename && '..' !== $basename ) {
						$other_files[] = $basename;
					}
				}

				// Сортируем — новые сверху.
				usort( $beestore_files, function ( $a, $b ) {
					return strcmp( $b['name'], $a['name'] );
				} );

				echo '<div class="warning">';
				echo '<strong>Всего файлов BeeStore:</strong> ' . count( $beestore_files ) . '<br>';
				echo '<strong>Полных каталогов (_0000001):</strong> ' . count( array_filter( $beestore_files, function ( $f ) { return $f['is_full']; } ) ) . '<br>';
				echo '<strong>Инкрементальных:</strong> ' . count( array_filter( $beestore_files, function ( $f ) { return ! $f['is_full']; } ) );
				echo '</div>';

				echo '<p class="small">⭐ Файлы с <code>_0000001</code> — это ПОЛНЫЙ каталог (57000 строк, ~66 MB). Это то, что вам нужно.</p>';
				echo '<p class="small">Файлы с <code>_0000002</code> и больше — инкрементальные (только изменения за 15 минут, ~50-100 строк).</p>';

				echo '<div class="files-list">';
				foreach ( $beestore_files as $f ) {
					$icon = $f['is_full'] ? '⭐' : '📄';
					$label = $f['is_full'] ? ' (полный)' : '';
					$size = $f['size'] > 0 ? ' (' . size_format( $f['size'] ) . ')' : '';
					echo '<div class="file-row">';
					echo '<span><span style="font-size:16px;">' . $icon . '</span> <span class="file-name">' . esc_html( $f['name'] ) . '</span><span class="file-size">' . esc_html( $size . $label ) . '</span></span>';
					echo '<a class="btn" href="?action=fetch&file=' . rawurlencode( $f['path'] ) . '">📥 Скачать на сервер</a>';
					echo '</div>';
				}
				echo '</div>';

				if ( ! empty( $other_files ) ) {
					echo '<p class="small" style="margin-top:15px;"><strong>Другие файлы на FTP:</strong> ' . esc_html( count( $other_files ) ) . ' (не показаны)</p>';
				}
			}
			ftp_close( $conn );
		}
	}
	echo '</div>';
}
?>

<div class="card">
	<h3>ℹ️ Что делать дальше</h3>
	<ol>
		<li>Найдите файл <code>COMPANY_0540_..._0000001.csv</code> (с пометкой ⭐)</li>
		<li>Нажмите <strong>«📥 Скачать на сервер»</strong> — файл скачается с FTP Sirio на ваш WordPress-сервер</li>
		<li>После скачивания файл появится в верхнем блоке <strong>«Файлы на сервере»</strong></li>
		<li>Нажмите <strong>«💾 Скачать»</strong> — файл скачается на ваш компьютер</li>
		<li>Откройте CSV в Excel, Notepad++ или VS Code</li>
		<li>После просмотра — нажмите <strong>«🗑 Удалить»</strong>, чтобы освободить место на сервере</li>
	</ol>
	<p class="small">⚠️ После того как закончите — <strong>удалите этот файл (download-catalog.php) с сервера</strong> во избежание несанкционированного доступа.</p>
</div>

</body>
</html>
