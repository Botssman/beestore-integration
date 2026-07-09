<?php
/**
 * Простой скрипт для определения исходящего IP сервера WordPress.
 *
 * Использование:
 *   1. Закачайте этот файл в корень WordPress (где wp-config.php)
 *   2. Откройте в браузере: https://ваш-сайт.ru/my-ip.php
 *   3. Скопируйте результат
 *   4. УДАЛИТЕ файл с сервера
 */
header( 'Content-Type: text/plain; charset=utf-8' );

echo "=== IP-адрес сервера WordPress ===\n\n";

// Пытаемся получить IP через несколько сервисов (для надёжности).
$services = array(
	'https://api.ipify.org?format=text',
	'https://ifconfig.me/ip',
	'https://ipinfo.io/ip',
	'https://api.my-ip.io/ip',
);

$found_ip = '';
foreach ( $services as $svc ) {
	$ctx = stream_context_create( array(
		'http' => array(
			'timeout' => 5,
			'user_agent' => 'WP-IP-Check/1.0',
		),
	) );
	$result = @file_get_contents( $svc, false, $ctx );
	if ( $result ) {
		$result = trim( $result );
		if ( filter_var( $result, FILTER_VALIDATE_IP ) ) {
			$found_ip = $result;
			echo "Исходящий IP:     " . $found_ip . "\n";
			echo "Получен через:    " . $svc . "\n\n";
			break;
		}
	}
}

if ( empty( $found_ip ) ) {
	// Пробуем через cURL если file_get_contents не сработал.
	if ( function_exists( 'curl_version' ) ) {
		foreach ( $services as $svc ) {
			$ch = curl_init();
			curl_setopt_array( $ch, array(
				CURLOPT_URL            => $svc,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 5,
				CURLOPT_USERAGENT      => 'WP-IP-Check/1.0',
			) );
			$result = curl_exec( $ch );
			$errno  = curl_errno( $ch );
			curl_close( $ch );
			if ( ! $errno && $result ) {
				$result = trim( $result );
				if ( filter_var( $result, FILTER_VALIDATE_IP ) ) {
					$found_ip = $result;
					echo "Исходящий IP:     " . $found_ip . "\n";
					echo "Получен через:    " . $svc . " (cURL)\n\n";
					break;
				}
			}
		}
	}
}

if ( empty( $found_ip ) ) {
	echo "ОШИБКА: Не удалось определить IP. Все внешние сервисы недоступны.\n";
	echo "→ Ваш хостинг блокирует исходящие соединения (нужно разблокировать port 80/443)\n";
	exit;
}

// Геолокация.
echo "=== Геолокация IP ===\n\n";
$geo_url = "http://ip-api.com/json/{$found_ip}?fields=country,countryCode,regionName,city,isp,org,as,query";
$ctx = stream_context_create( array( 'http' => array( 'timeout' => 5 ) ) );
$geo = @file_get_contents( $geo_url, false, $ctx );
if ( $geo ) {
	$g = json_decode( $geo, true );
	if ( $g ) {
		echo "Страна:       " . ( $g['country'] ?? '?' ) . " (" . ( $g['countryCode'] ?? '?' ) . ")\n";
		echo "Регион:       " . ( $g['regionName'] ?? '?' ) . "\n";
		echo "Город:        " . ( $g['city'] ?? '?' ) . "\n";
		echo "ISP:          " . ( $g['isp'] ?? '?' ) . "\n";
		echo "Organization: " . ( $g['org'] ?? '?' ) . "\n";
		echo "AS:           " . ( $g['as'] ?? '?' ) . "\n";
	}
} else {
	echo "Геолокация недоступна — но IP вы узнали выше.\n";
}

echo "\n=== Что делать с этим IP ===\n\n";
echo "1. Отправьте IP в Sirio — попросите добавить в whitelist FTP и SOAP:\n";
echo "   \"Our WordPress server IP for whitelist: {$found_ip}\"\n\n";
echo "2. Если Sirio блокирует по гео-признаку — IP находится в " . ( $g['country'] ?? '?' ) . "\n";
echo "   Если это не Италия/EU — попросите Sirio добавить в whitelist.\n\n";
echo "=== УДАЛИТЕ ЭТОТ ФАЙЛ С СЕРВЕРА ПОСЛЕ ПРОВЕРКИ ===\n";
