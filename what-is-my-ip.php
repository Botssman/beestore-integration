<?php
/**
 * Показывает ВСЕ IP вашего сервера Namecheap.
 *
 * После использования — УДАЛИТЬ!
 */
header( 'Content-Type: text/plain; charset=utf-8' );

echo "=== Все IP вашего сервера ===\n\n";

echo "1. SERVER_ADDR (входящий IP сервера):\n";
echo "   " . ( $_SERVER['SERVER_ADDR'] ?? 'не определён' ) . "\n\n";

echo "2. HTTP_HOST (домен, к которому обратились):\n";
echo "   " . ( $_SERVER['HTTP_HOST'] ?? 'не определён' ) . "\n\n";

echo "3. SERVER_NAME:\n";
echo "   " . ( $_SERVER['SERVER_NAME'] ?? 'не определён' ) . "\n\n";

echo "4. gethostname() (имя сервера):\n";
echo "   " . gethostname() . "\n\n";

echo "5. gethostbyname() (IP по имени сервера):\n";
echo "   " . gethostbyname( gethostname() ) . "\n\n";

echo "6. gethostbyname(домен сайта) — IP по DNS:\n";
$host = $_SERVER['HTTP_HOST'] ?? '';
if ( $host ) {
	echo "   " . $host . " → " . gethostbyname( $host ) . "\n\n";
}

echo "7. DNS A-запись через dns_get_record:\n";
$records = @dns_get_record( $host, DNS_A );
if ( ! empty( $records ) ) {
	foreach ( $records as $r ) {
		echo "   " . $r['ip'] . " (TTL=" . $r['ttl'] . ")\n";
	}
}
echo "\n";

echo "8. Исходящий IP (видит внешний мир при запросе ИЗ сервера):\n";
$ctx = stream_context_create( array( 'http' => array( 'timeout' => 5 ) ) );
$out = @file_get_contents( 'https://api.ipify.org?format=text', false, $ctx );
echo "   " . ( $out ?: 'не удалось определить' ) . "\n\n";

echo "9. Список всех IP-адресов на сервере (ifconfig):\n";
$interfaces = net_get_interfaces();
if ( $interfaces ) {
	foreach ( $interfaces as $name => $info ) {
		if ( ! empty( $info['unicast'] ) ) {
			foreach ( $info['unicast'] as $addr ) {
				if ( ! empty( $addr['address'] ) && filter_var( $addr['address'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					echo "   {$name}: " . $addr['address'] . "\n";
				}
			}
		}
	}
}

echo "\n=== Что означает каждый IP ===\n";
echo "• SERVER_ADDR / gethostbyname(домен) — IP, который DNS указывает для вашего сайта.\n";
echo "  Это тот IP, который вы отправили Sirio, если ссылались на домен.\n";
echo "  Скорее всего это 104.219.248.238.\n\n";
echo "• Исходящий IP (ipify) — IP, с которого ваш сервер подключается к внешним сервисам.\n";
echo "  Sirio видит именно этот IP при попытке подключения.\n";
echo "  Скорее всего это 68.65.120.168.\n\n";
echo "• Если эти IP РАЗНЫЕ — Sirio нужно добавить ОБА в whitelist,\n";
echo "  но ОСОБЕННО исходящий (68.65.120.168) — он важнее для блокировки.\n\n";

echo "=== УДАЛИТЕ ЭТОТ ФАЙЛ ПОСЛЕ ПРОВЕРКИ ===\n";
