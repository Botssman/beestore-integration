<?php
/**
 * Проверка доступности разных портов на серверах Sirio.
 * Помогает понять — кто блокирует: Namecheap или Sirio.
 *
 * После использования — УДАЛИТЬ!
 */
header( 'Content-Type: text/plain; charset=utf-8' );
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

echo "=== Проверка доступности серверов Sirio с разных портов ===\n\n";

$tests = array(
	// Sirio SOAP сервер (должен слушать 8180)
	array( '93.62.215.115', 80,   'HTTP' ),
	array( '93.62.215.115', 443,  'HTTPS' ),
	array( '93.62.215.115', 8180, 'SOAP BeeStore (ожидаемо открыт)' ),
	array( '93.62.215.115', 21,   'FTP (если на этом IP есть FTP)' ),
	array( '93.62.215.115', 22,   'SSH' ),

	// Sirio FTP сервер (вы указали 93.62.222.XXX — пробуем разные IP из подсети)
	array( '93.62.222.1', 21, 'FTP (тест подсети 222)' ),
	array( '93.62.222.10', 21, 'FTP (тест подсети 222)' ),
	array( '93.62.222.100', 21, 'FTP (тест подсети 222)' ),

	// Сравнение — публичные серверы (точно работают)
	array( 'google.com', 80,  'Google HTTP (тест исходящего трафика)' ),
	array( 'google.com', 443, 'Google HTTPS (тест исходящего трафика)' ),
	array( 'cloudflare.com', 443, 'Cloudflare (тест)' ),

	// Тест Italian сервера (для сравнения — открыта ли Италия вообще)
	array( 'www.sirio-is.it', 80,  'Сайт Sirio (HTTP)' ),
	array( 'www.sirio-is.it', 443, 'Сайт Sirio (HTTPS)' ),
);

foreach ( $tests as $test ) {
	list( $host, $port, $desc ) = $test;
	$start = microtime( true );
	$fp = @fsockopen( $host, $port, $errno, $errstr, 8 );
	$elapsed = round( microtime( true ) - $start, 2 );

	if ( $fp ) {
		$banner = '';
		if ( in_array( $port, array( 21, 22, 25, 110, 143 ) ) ) {
			// Читаем баннер для FTP/SSH/SMTP/POP3/IMAP
			$banner = fread( $fp, 256 );
		}
		fclose( $fp );
		echo sprintf( "[ OK ] %-25s:%-5d  %s  (%.2f сек)\n", $host, $port, $desc, $elapsed );
		if ( $banner ) {
			echo "       Баннер: " . trim( $banner ) . "\n";
		}
	} else {
		echo sprintf( "[FAIL] %-25s:%-5d  %s  [%d] %s  (%.2f сек)\n", $host, $port, $desc, $errno, $errstr, $elapsed );
	}
}

echo "\n=== Выводы ===\n";
echo "• Если Google/Cloudflare ОК, но Sirio FAIL → Sirio блокирует ваш IP\n";
echo "• Если И sirio-is.it (80/443) FAIL → Sirio блокирует всю подсеть\n";
echo "• Если sirio-is.it ОК, но 8180 FAIL → закрыт только порт 8180\n";
echo "• Если Google тоже FAIL → Namecheap блокирует исходящий трафик\n";
