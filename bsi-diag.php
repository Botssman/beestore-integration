<?php
/**
 * BeeStore Integration — полная диагностика соединения (v2 standalone).
 *
 * Не зависит от WordPress — можно класть в любую папку.
 *
 * Использование:
 *   https://ваш-сайт.ru/bsi-diag.php
 *   https://ваш-сайт.ru/bsi-diag.php?ftp_host=93.62.222.XXX&ftp_user=USER&ftp_pass=PASS&ftp_path=/
 *
 * ВНИМАНИЕ: после использования УДАЛИТЕ этот файл с сервера!
 */
header( 'Content-Type: text/plain; charset=utf-8' );
error_reporting( E_ALL );
ini_set( 'display_errors', 1 );

// Безопасное чтение GET-параметров (без зависимости от WP).
$ftp_host = isset( $_GET['ftp_host'] ) ? trim( stripslashes( (string) $_GET['ftp_host'] ) ) : '';
$ftp_port = isset( $_GET['ftp_port'] ) ? (int) $_GET['ftp_port'] : 21;
$ftp_user = isset( $_GET['ftp_user'] ) ? trim( stripslashes( (string) $_GET['ftp_user'] ) ) : '';
$ftp_pass = isset( $_GET['ftp_pass'] ) ? (string) $_GET['ftp_pass'] : '';
$ftp_path = isset( $_GET['ftp_path'] ) ? trim( stripslashes( (string) $_GET['ftp_path'] ) ) : '/';
$wsdl_url = isset( $_GET['wsdl_url'] ) ? trim( stripslashes( (string) $_GET['wsdl_url'] ) ) : 'http://93.62.215.115:8180/baylico/soapBeestore.wsdl';

function section( $title ) {
	echo "\n" . str_repeat( '=', 70 ) . "\n";
	echo $title . "\n";
	echo str_repeat( '=', 70 ) . "\n";
}
function ok( $msg )   { echo "[ OK ] " . $msg . "\n"; }
function err( $msg )  { echo "[FAIL] " . $msg . "\n"; }
function warn( $msg ) { echo "[WARN] " . $msg . "\n"; }
function info( $msg ) { echo "[INFO] " . $msg . "\n"; }

// =====================================================================
section( "1. PHP-ОКРУЖЕНИЕ" );
// =====================================================================
echo "PHP Version: " . PHP_VERSION . "\n";
echo "SAPI: " . PHP_SAPI . "\n";
echo "Server IP: " . ( $_SERVER['SERVER_ADDR'] ?? 'не определён' ) . "\n";
echo "Server Software: " . ( $_SERVER['SERVER_SOFTWARE'] ?? 'не определён' ) . "\n";
echo "\nРасширения:\n";
echo "  SoapClient:   " . ( class_exists( 'SoapClient' ) ? 'OK' : 'НЕ УСТАНОВЛЕН' ) . "\n";
echo "  openssl:      " . ( extension_loaded( 'openssl' ) ? 'OK' : 'НЕ УСТАНОВЛЕН' ) . "\n";
echo "  curl:         " . ( function_exists( 'curl_version' ) ? 'OK ' . curl_version()['version'] : 'НЕ УСТАНОВЛЕН' ) . "\n";
echo "  ftp:          " . ( function_exists( 'ftp_connect' ) ? 'OK' : 'НЕ УСТАНОВЛЕН' ) . "\n";
echo "  ssh2:         " . ( function_exists( 'ssh2_connect' ) ? 'OK' : 'НЕ УСТАНОВЛЕН (только обычный FTP)' ) . "\n";
echo "  ZipArchive:   " . ( class_exists( 'ZipArchive' ) ? 'OK' : 'НЕ УСТАНОВЛЕН' ) . "\n";

echo "\nНастройки:\n";
echo "  allow_url_fopen:        " . ( ini_get( 'allow_url_fopen' ) ? 'On (хорошо)' : 'Off (ПРОБЛЕМА!)' ) . "\n";
echo "  soap.wsdl_cache_enabled:" . ini_get( 'soap.wsdl_cache_enabled' ) . " (должно быть 0 для отладки)\n";
echo "  max_execution_time:     " . ini_get( 'max_execution_time' ) . " сек\n";
echo "  memory_limit:           " . ini_get( 'memory_limit' ) . "\n";
echo "  disable_functions:      " . ( ini_get( 'disable_functions' ) ?: 'нет' ) . "\n";

// =====================================================================
section( "2. ИСХОДЯЩИЙ IP И ГЕОЛОКАЦИЯ" );
// =====================================================================
$out_ip = '';
$ip_services = array(
	'https://api.ipify.org?format=text',
	'https://ifconfig.me/ip',
	'https://ipinfo.io/ip',
);
foreach ( $ip_services as $svc ) {
	$ctx = stream_context_create( array( 'http' => array( 'timeout' => 5 ) ) );
	$out_ip = @file_get_contents( $svc, false, $ctx );
	if ( $out_ip && filter_var( trim( $out_ip ), FILTER_VALIDATE_IP ) ) {
		$out_ip = trim( $out_ip );
		break;
	}
}
if ( $out_ip ) {
	echo "Исходящий IP: " . $out_ip . "\n";
	$geo = @file_get_contents( "http://ip-api.com/json/{$out_ip}?fields=country,countryCode,regionName,city,isp,query" );
	if ( $geo ) {
		$geo_data = json_decode( $geo, true );
		if ( $geo_data ) {
			echo "Страна:     " . ( $geo_data['country'] ?? '?' ) . " (" . ( $geo_data['countryCode'] ?? '?' ) . ")\n";
			echo "Регион:     " . ( $geo_data['regionName'] ?? '?' ) . "\n";
			echo "Город:      " . ( $geo_data['city'] ?? '?' ) . "\n";
			echo "ISP:        " . ( $geo_data['isp'] ?? '?' ) . "\n";
		}
	}
} else {
	err( "Не удалось определить исходящий IP сервера" );
}

// =====================================================================
section( "3. TCP-ДОСТУПНОСТЬ FTP-СЕРВЕРА (порт 21)" );
// =====================================================================
$ftp_targets = array_unique( array_filter( array(
	'93.62.215.115',
	$ftp_host,
	'93.62.222.1',
) ) );

if ( empty( $ftp_host ) ) {
	info( "FTP_HOST не передан в GET — проверяю 93.62.215.115:21 как гипотезу" );
	info( "Для проверки вашего FTP добавьте параметры: ?ftp_host=ВАШ_IP&ftp_user=...&ftp_pass=..." );
}

foreach ( $ftp_targets as $host ) {
	if ( ! $host ) { continue; }
	echo "\nПроверка {$host}:21...\n";
	$start = microtime( true );
	$fp = @fsockopen( $host, 21, $errno, $errstr, 10 );
	$elapsed = round( microtime( true ) - $start, 2 );
	if ( $fp ) {
		$banner = fread( $fp, 1024 );
		ok( "TCP к {$host}:21 — соединение установлено за {$elapsed} сек" );
		echo "      Баннер FTP: " . trim( $banner ) . "\n";
		fclose( $fp );
	} else {
		err( "TCP к {$host}:21 — НЕ УДАЛОСЬ [{$errno}] {$errstr} (за {$elapsed} сек)" );
	}
}

// =====================================================================
section( "4. TCP-ДОСТУПНОСТЬ SOAP-СЕРВЕРА (порт 8180)" );
// =====================================================================
echo "Проверка 93.62.215.115:8180...\n";
$start = microtime( true );
$fp = @fsockopen( '93.62.215.115', 8180, $errno, $errstr, 10 );
$elapsed = round( microtime( true ) - $start, 2 );
if ( $fp ) {
	$out = "GET /baylico/soapBeestore.wsdl HTTP/1.1\r\n";
	$out .= "Host: 93.62.215.115:8180\r\n";
	$out .= "User-Agent: BeeStoreDiag/1.0\r\n";
	$out .= "Connection: Close\r\n\r\n";
	fwrite( $fp, $out );
	$response = '';
	while ( ! feof( $fp ) && strlen( $response ) < 4096 ) {
		$response .= fgets( $fp, 128 );
	}
	fclose( $fp );
	ok( "TCP к 93.62.215.115:8180 — соединение установлено за {$elapsed} сек" );
	echo "      Первые 500 символов HTTP-ответа:\n";
	foreach ( explode( "\r\n", substr( $response, 0, 500 ) ) as $line ) {
		echo "      > " . $line . "\n";
	}
} else {
	err( "TCP к 93.62.215.115:8180 — НЕ УДАЛОСЬ [{$errno}] {$errstr} (за {$elapsed} сек)" );
	if ( $errno == 110 || $errno == 10060 ) {
		warn( "Таймаут — хостинг блокирует порт 8180 или Sirio блокирует ваш IP" );
	} elseif ( $errno == 111 || $errno == 10061 ) {
		warn( "Connection refused — сервер недоступен" );
	}
}

// =====================================================================
section( "5. FTP-ЛОГИН (если переданы доступы)" );
// =====================================================================
if ( $ftp_host && $ftp_user && $ftp_pass && function_exists( 'ftp_connect' ) ) {
	echo "Подключение к FTP {$ftp_host}:{$ftp_port} как {$ftp_user}...\n";
	$conn = @ftp_connect( $ftp_host, $ftp_port, 15 );
	if ( $conn ) {
		ok( "FTP-соединение установлено" );
		$login = @ftp_login( $conn, $ftp_user, $ftp_pass );
		if ( $login ) {
			ok( "FTP-логин успешен" );
			ftp_pasv( $conn, true );
			echo "Текущий каталог: " . ftp_pwd( $conn ) . "\n";
			echo "\nСодержимое каталога {$ftp_path}:\n";
			$listing = @ftp_nlist( $conn, $ftp_path );
			if ( $listing ) {
				foreach ( $listing as $entry ) {
					echo "  " . $entry . "\n";
				}
				$zips = array_filter( $listing, function ( $name ) {
					return preg_match( '/COMPANY_\d+_0000_[0-9_\-]+\.(zip|csv)$/i', basename( $name ) );
				} );
				if ( ! empty( $zips ) ) {
					ok( "Найдено файлов BeeStore: " . count( $zips ) );
					foreach ( array_slice( $zips, 0, 5 ) as $zip ) {
						echo "  → " . basename( $zip ) . "\n";
					}
				} else {
					warn( "В каталоге {$ftp_path} нет файлов BeeStore" );
					info( "Попробуйте другие пути: /, /beestore, /0540, /baylico" );
				}
			} else {
				err( "Не удалось получить список файлов в {$ftp_path}" );
			}
		} else {
			err( "FTP-логин НЕ УДАЛСЯ — неверный логин/пароль" );
		}
		ftp_close( $conn );
	} else {
		err( "FTP-соединение НЕ УСТАНОВЛЕНО с {$ftp_host}:{$ftp_port}" );
		warn( "Проверьте: правильный ли FTP_HOST? Sirio блокирует IP? Хостинг блокирует порт 21?" );
	}
} else {
	info( "FTP-проверка пропущена — передайте ?ftp_host=...&ftp_user=...&ftp_pass=...&ftp_path=/" );
}

// =====================================================================
section( "6. ЗАГРУЗКА WSDL ЧЕРЕЗ file_get_contents" );
// =====================================================================
echo "URL: {$wsdl_url}\n";
$ctx = stream_context_create( array(
	'http' => array(
		'timeout'       => 15,
		'method'        => 'GET',
		'ignore_errors' => true,
		'user_agent'    => 'BeeStoreDiag/1.0',
	),
) );
$start = microtime( true );
$response = @file_get_contents( $wsdl_url, false, $ctx );
$elapsed = round( microtime( true ) - $start, 2 );
if ( $response === false ) {
	err( "file_get_contents: НЕ УДАЛОСЬ загрузить (за {$elapsed} сек)" );
	$e = error_get_last();
	echo "PHP error: " . ( $e['message'] ?? 'нет данных' ) . "\n";
} else {
	$len = strlen( $response );
	ok( "file_get_contents: получено {$len} байт за {$elapsed} сек" );
	echo "Первые 500 символов:\n";
	echo substr( $response, 0, 500 ) . "\n";
	if ( strpos( $response, '<definitions' ) !== false || strpos( $response, '<wsdl:' ) !== false ) {
		ok( "Ответ похож на валидный WSDL" );
	} elseif ( strpos( $response, '<html' ) !== false ) {
		warn( "Получен HTML вместо WSDL — возможно страница ошибки/логина" );
	} else {
		warn( "Ответ не похож на WSDL" );
	}
}

// =====================================================================
section( "7. ЗАГРУЗКА WSDL ЧЕРЕЗ cURL" );
// =====================================================================
if ( function_exists( 'curl_version' ) ) {
	$ch = curl_init();
	curl_setopt_array( $ch, array(
		CURLOPT_URL            => $wsdl_url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_USERAGENT      => 'BeeStoreDiag/1.0',
	) );
	$start   = microtime( true );
	$body    = curl_exec( $ch );
	$elapsed = round( microtime( true ) - $start, 2 );
	$info    = curl_getinfo( $ch );
	$errno   = curl_errno( $ch );
	$error   = curl_error( $ch );
	curl_close( $ch );
	echo "HTTP code: " . $info['http_code'] . "\n";
	echo "Время: {$elapsed} сек\n";
	echo "Размер: " . strlen( $body ?? '' ) . " байт\n";
	if ( $errno ) {
		err( "cURL error [{$errno}]: {$error}" );
		if ( $errno == 28 ) {
			warn( "Timeout — сервер не отвечает или блокирует" );
		} elseif ( $errno == 7 ) {
			warn( "Couldn't connect — хостинг блокирует порт или Sirio блокирует IP" );
		} elseif ( $errno == 35 ) {
			warn( "SSL error — для HTTPS, не для HTTP" );
		}
	} else {
		ok( "cURL: запрос выполнен, HTTP {$info['http_code']}" );
		echo "Первые 300 символов:\n" . substr( $body, 0, 300 ) . "\n";
	}
}

// =====================================================================
section( "8. ТЕСТ SoapClient" );
// =====================================================================
if ( class_exists( 'SoapClient' ) ) {
	echo "Попытка инициализации SoapClient...\n";
	try {
		$client = new SoapClient( $wsdl_url, array(
			'wsdl_cache_enabled' => 0,
			'connection_timeout' => 15,
			'trace'              => true,
			'exceptions'         => true,
			'features'           => SOAP_SINGLE_ELEMENT_ARRAYS,
		) );
		ok( "SoapClient ИНИЦИАЛИЗИРОВАН УСПЕШНО" );
		$functions = $client->__getFunctions();
		echo "Доступные методы (" . count( $functions ) . "):\n";
		foreach ( array_slice( $functions, 0, 15 ) as $f ) {
			echo "  - " . $f . "\n";
		}
	} catch ( SoapFault $e ) {
		err( "SoapFault: " . $e->getMessage() );
		echo "FaultCode: " . $e->faultcode . "\n";
	} catch ( Exception $e ) {
		err( "Exception: " . $e->getMessage() );
	}
} else {
	err( "SoapClient не установлен — обратитесь к хостингу" );
}

// =====================================================================
section( "РЕКОМЕНДАЦИИ" );
// =====================================================================
echo "1. Если TCP к 93.62.215.115:8180 НЕ УДАЛОСЬ:\n";
echo "   → Хостинг блокирует порт 8180 ИЛИ Sirio блокирует ваш IP.\n";
echo "   → Писать в Namecheap поддержку + Sirio поддержку.\n\n";
echo "2. Если TCP есть, но file_get_contents/cURL возвращают пусто:\n";
echo "   → Включить allow_url_fopen=On в cPanel → MultiPHP INI Editor.\n";
echo "   → Удалить кэш WSDL: rm -f /tmp/wsdl-*\n\n";
echo "3. Если HTTP-код 401/403:\n";
echo "   → Sirio требует IP-whitelist — напишите им.\n\n";
echo "4. Если FTP не отвечает:\n";
echo "   → Проверьте FTP_HOST в письме Sirio (должен быть 93.62.222.XXX, а не 93.62.215.115).\n\n";
echo "=== УДАЛИТЕ ЭТОТ ФАЙЛ С СЕРВЕРА ПОСЛЕ ПРОВЕРКИ ===\n";
