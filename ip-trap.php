<?php
/**
 * Ловушка IP-адресов.
 *
 * Этот файл записывает все обращения к нему в лог-файл.
 * Используется, чтобы узнать IP-адреса серверов, которые подключаются к вашему сайту.
 *
 * Использование:
 *   1. Закачайте этот файл в корень WordPress
 *   2. Отправьте URL Sirio (или любому, кого хотите проверить):
 *      https://viaqurata.com/ip-trap.php
 *   3. После того, как они обратятся к этому URL — посмотрите лог:
 *      https://viaqurata.com/ip-trap.php?show=1
 *   4. После проверки — УДАЛИТЕ этот файл
 *
 * ВАЖНО: файл логирует ВСЕ обращения — не оставляйте его надолго.
 */

header( 'Content-Type: text/plain; charset=utf-8' );

$log_file = __DIR__ . '/ip-trap.log';

// Режим просмотра лога.
if ( isset( $_GET['show'] ) ) {
	if ( file_exists( $log_file ) ) {
		echo "=== Лог подключений ===\n\n";
		$content = file_get_contents( $log_file );
		// Показываем последние 100 записей.
		$lines = explode( "\n", $content );
		$lines = array_filter( $lines );
		$last  = array_slice( $lines, -100 );
		foreach ( $last as $line ) {
			echo $line . "\n";
		}
		echo "\n=== " . count( $last ) . " записей показано ===\n";
	} else {
		echo "Лог пуст — к этому файлу ещё никто не обращался.\n";
	}
	exit;
}

// Режим очистки лога.
if ( isset( $_GET['clear'] ) ) {
	@unlink( $log_file );
	echo "Лог очищен.\n";
	exit;
}

// Собираем всю информацию о подключении.
$ip        = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$xff       = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
$cf_ip     = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
$real_ip   = $_SERVER['HTTP_X_REAL_IP'] ?? '';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$method    = $_SERVER['REQUEST_METHOD'] ?? '';
$uri       = $_SERVER['REQUEST_URI'] ?? '';
$referer   = $_SERVER['HTTP_REFERER'] ?? '';
$time      = date( 'Y-m-d H:i:s' );

// Геолокация (если доступна).
$geo = '';
if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
	$geo_data = @file_get_contents( "http://ip-api.com/json/{$ip}?fields=country,countryCode,city,isp,query" );
	if ( $geo_data ) {
		$g = json_decode( $geo_data, true );
		if ( $g ) {
			$geo = sprintf(
				'%s (%s), %s, ISP: %s',
				$g['country'] ?? '?',
				$g['countryCode'] ?? '?',
				$g['city'] ?? '?',
				$g['isp'] ?? '?'
			);
		}
	}
}

// Записываем в лог.
$entry = sprintf(
	"[%s] IP=%s | XFF=%s | CF=%s | RealIP=%s | %s %s | UA: %s | Referer: %s | Geo: %s\n",
	$time,
	$ip,
	$xff ?: '-',
	$cf_ip ?: '-',
	$real_ip ?: '-',
	$method,
	$uri,
	$user_agent ?: '-',
	$referer ?: '-',
	$geo ?: '-'
);

file_put_contents( $log_file, $entry, FILE_APPEND | LOCK_EX );

echo "=== Спасибо, ваш IP записан ===\n\n";
echo "Ваш IP: {$ip}\n";
if ( $geo ) {
	echo "Геолокация: {$geo}\n";
}
echo "\nЕсли вы Mattia из Sirio — спасибо за подключение!\n";
echo "Мы увидим ваш IP в нашем логе и добавим в whitelist.\n";
echo "\nДля просмотра лога: https://viaqurata.com/ip-trap.php?show=1\n";
