<?php
/**
 * Диагностика доступа к BeeStore SOAP.
 * Удалите этот файл после проверки!
 */
header( 'Content-Type: text/plain; charset=utf-8' );

echo "=== Проверка PHP-окружения ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "SoapClient: " . ( class_exists( 'SoapClient' ) ? 'OK' : 'NOT INSTALLED' ) . "\n";
echo "allow_url_fopen: " . ( ini_get( 'allow_url_fopen' ) ? 'On (хорошо)' : 'Off (ПРОБЛЕМА!)' ) . "\n";
echo "soap.wsdl_cache_enabled: " . ini_get( 'soap.wsdl_cache_enabled' ) . "\n";
echo "openssl: " . ( extension_loaded( 'openssl' ) ? 'OK' : 'NOT INSTALLED' ) . "\n";
echo "curl: " . ( function_exists( 'curl_version' ) ? 'OK' : 'NOT INSTALLED' ) . "\n";

echo "\n=== Исходящий IP сервера ===\n";
echo file_get_contents( 'https://api.ipify.org?format=text' ) . "\n";

echo "\n=== Проверка TCP-доступности 93.62.215.115:8180 ===\n";
$start = microtime( true );
$fp = @fsockopen( '93.62.215.115', 8180, $errno, $errstr, 10 );
$elapsed = round( microtime( true ) - $start, 2 );
if ( $fp ) {
	echo "TCP-соединение УСПЕШНО (за {$elapsed} сек)\n";
	fclose( $fp );
} else {
	echo "TCP-соединение НЕ УДАЛОСЬ: [{$errno}] {$errstr} (за {$elapsed} сек)\n";
	echo "→ Хостинг блокирует исходящий порт 8180, либо Sirio блокирует ваш IP\n";
}

echo "\n=== HTTP-запрос к WSDL через file_get_contents ===\n";
$url = 'http://93.62.215.115:8180/baylico/soapBeestore.wsdl';
$ctx = stream_context_create( array(
	'http' => array(
		'timeout'       => 15,
		'method'        => 'GET',
		'ignore_errors' => true,
		'user_agent'    => 'BeeStoreIntegration/1.0',
	),
) );
$start = microtime( true );
$response = @file_get_contents( $url, false, $ctx );
$elapsed = round( microtime( true ) - $start, 2 );
if ( $response === false ) {
	echo "file_get_contents: НЕ УДАЛОСЬ загрузить (за {$elapsed} сек)\n";
	$error = error_get_last();
	echo "PHP error: " . ( $error['message'] ?? 'нет данных' ) . "\n";
} else {
	echo "Получено байт: " . strlen( $response ) . " (за {$elapsed} сек)\n";
	echo "Первые 500 символов ответа:\n";
	echo substr( $response, 0, 500 ) . "\n";
	if ( strpos( $response, '<definitions' ) !== false || strpos( $response, '<wsdl:' ) !== false ) {
		echo "→ WSDL валиден,SoapClient должен работать\n";
	} else {
		echo "→ Ответ не похож на WSDL (нет <definitions>)\n";
	}
}

echo "\n=== HTTP-запрос к WSDL через cURL ===\n";
if ( function_exists( 'curl_version' ) ) {
	$ch = curl_init();
	curl_setopt_array( $ch, array(
		CURLOPT_URL            => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 15,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_USERAGENT      => 'BeeStoreIntegration/1.0',
		CURLOPT_HTTPHEADER     => array( 'Accept: text/xml' ),
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
		echo "cURL error [{$errno}]: {$error}\n";
	} else {
		echo "Первые 300 символов:\n" . substr( $body, 0, 300 ) . "\n";
	}
} else {
	echo "cURL не установлен\n";
}

echo "\n=== Тест SoapClient напрямую ===\n";
if ( class_exists( 'SoapClient' ) ) {
	try {
		$client = new SoapClient( $url, array(
			'wsdl_cache_enabled' => 0,
			'connection_timeout' => 15,
			'trace'              => true,
			'exceptions'         => true,
		) );
		echo "SoapClient ИНИЦИАЛИЗИРОВАН УСПЕШНО\n";
		echo "Доступные методы:\n";
		$functions = $client->__getFunctions();
		foreach ( array_slice( $functions, 0, 10 ) as $f ) {
			echo "  - " . $f . "\n";
		}
		echo "Всего методов: " . count( $functions ) . "\n";
	} catch ( SoapFault $e ) {
		echo "SoapFault: " . $e->getMessage() . "\n";
		echo "FaultCode: " . $e->faultcode . "\n";
	} catch ( Exception $e ) {
		echo "Exception: " . $e->getMessage() . "\n";
	}
}

echo "\n=== Готово. Удалите этот файл! ===\n";
