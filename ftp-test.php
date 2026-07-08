<?php
/**
 * Проверка подключения к FTP.
 * Можно использовать для теста любого FTP (своего или Sirio).
 *
 * Откройте в браузере с GET-параметрами:
 *   https://ваш-сайт.ru/ftp-test.php?host=ftp.ваш-сайт.ru&user=USER&pass=PASS&path=/beestore-test/
 *
 * После использования — УДАЛИТЬ!
 */
header( 'Content-Type: text/plain; charset=utf-8' );
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

$host = isset( $_GET['host'] ) ? (string) $_GET['host'] : '';
$port = isset( $_GET['port'] ) ? (int) $_GET['port'] : 21;
$user = isset( $_GET['user'] ) ? (string) $_GET['user'] : '';
$pass = isset( $_GET['pass'] ) ? (string) $_GET['pass'] : '';
$path = isset( $_GET['path'] ) ? (string) $_GET['path'] : '/';

echo "=== Проверка FTP-подключения ===\n\n";
echo "Host: {$host}:{$port}\n";
echo "User: {$user}\n";
echo "Path: {$path}\n\n";

if ( empty( $host ) || empty( $user ) || empty( $pass ) ) {
	echo "Использование:\n";
	echo "  ftp-test.php?host=FTP_HOST&user=FTP_USER&pass=FTP_PASS&path=FTP_PATH\n\n";
	echo "Пример:\n";
	echo "  ftp-test.php?host=ftp.example.com&user=test@example.com&pass=secret&path=/beestore-test/\n";
	exit;
}

if ( ! function_exists( 'ftp_connect' ) ) {
	echo "[FAIL] Расширение PHP ftp не установлено.\n";
	exit;
}

// 1. TCP-подключение.
echo "1. Подключение к серверу...\n";
$start = microtime( true );
$conn = @ftp_connect( $host, $port, 15 );
$elapsed = round( microtime( true ) - $start, 2 );

if ( ! $conn ) {
	echo "[FAIL] Не удалось подключиться к {$host}:{$port} за {$elapsed} сек\n";
	echo "Возможные причины:\n";
	echo "  - Неверный хост или порт\n";
	echo "  - Файрвол блокирует исходящий порт 21\n";
	echo "  - Сервер недоступен\n";
	exit;
}
echo "[ OK ] Соединение установлено за {$elapsed} сек\n";
echo "       Баннер: " . trim( ftp_raw( $conn, 'NOOP' )[0] ?? '' ) . "\n";

// 2. Логин.
echo "\n2. Авторизация...\n";
$login = @ftp_login( $conn, $user, $pass );
if ( ! $login ) {
	echo "[FAIL] Неверный логин или пароль\n";
	ftp_close( $conn );
	exit;
}
echo "[ OK ] Логин успешен\n";

// 3. Пассивный режим.
ftp_pasv( $conn, true );
echo "[INFO] Включён пассивный режим\n";

// 4. Текущий каталог.
echo "\n4. Текущий каталог после входа:\n";
$pwd = ftp_pwd( $conn );
echo "       {$pwd}\n";

// 5. Переход в указанный путь.
echo "\n5. Переход в каталог '{$path}'...\n";
if ( ! @ftp_chdir( $conn, $path ) ) {
	echo "[WARN] Не удалось перейти в {$path} — попробую список из корня\n";
	ftp_chdir( $conn, '/' );
	$path = '/';
}

// 6. Список файлов.
echo "\n6. Содержимое каталога {$path}:\n";
$listing = ftp_nlist( $conn, $path );
if ( $listing === false ) {
	$listing = ftp_rawlist( $conn, $path );
}

if ( empty( $listing ) ) {
	echo "       (пусто)\n";
} else {
	foreach ( $listing as $entry ) {
		echo "  - " . $entry . "\n";
	}
}

// 7. Ищем файлы BeeStore.
echo "\n7. Поиск файлов BeeStore (COMPANY_*.zip или COMPANY_*.csv)...\n";
$beestore_files = array();
foreach ( $listing as $entry ) {
	$name = is_string( $entry ) ? basename( $entry ) : '';
	if ( preg_match( '/^COMPANY_\d+_0000_[0-9_\-]+\.(zip|csv)$/i', $name ) ) {
		$beestore_files[] = $name;
	}
}

if ( empty( $beestore_files ) ) {
	echo "       Файлов BeeStore не найдено.\n";
	echo "       Загрузите файл COMPANY_0540_0000_2026-06-26_01-03-01_0000001.zip в эту папку.\n";
} else {
	rsort( $beestore_files );
	echo "       [ OK ] Найдено файлов BeeStore: " . count( $beestore_files ) . "\n";
	foreach ( array_slice( $beestore_files, 0, 10 ) as $f ) {
		// Размер файла.
		$size = ftp_size( $conn, $path . '/' . $f );
		$size_str = ( $size > 0 ) ? ' (' . round( $size / 1024 / 1024, 2 ) . ' MB)' : '';
		echo "         → " . $f . $size_str . "\n";
	}
	echo "\n       Последний файл (для импорта): " . $beestore_files[0] . "\n";
}

ftp_close( $conn );

echo "\n=== Итог ===\n";
echo "Если видите [ OK ] во всех шагах — плагин сможет подключиться к этому FTP.\n";
echo "Внесите эти доступы в BeeStore → Настройки → FTP и запустите импорт.\n";
