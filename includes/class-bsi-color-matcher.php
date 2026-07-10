<?php
/**
 * Автоматическое определение HEX-кода цвета по названию.
 *
 * Ищет ключевые слова в названии цвета (BLACK, WHITE, RED, и т.д.)
 * и возвращает соответствующий HEX-код.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSI_Color_Matcher {

	/**
	 * Словарь: ключевое слово → HEX-код.
	 * Проверяется по порядку — первый найденный wins.
	 * Порядок важен: BLACKXGREEN должен дать #000000 (BLACK), а не #008000 (GREEN).
	 * Поэтому BLACK идёт первым.
	 */
	private static $color_map = array(
		// Базовые цвета — по приоритету (тёмные первыми).
		'BLACK'         => '#000000',
		'NERO'          => '#000000',
		'NOIR'          => '#000000',
		'WHITE'         => '#ffffff',
		'BIANCO'        => '#ffffff',
		'BLANC'         => '#ffffff',
		'WHT'           => '#ffffff',
		'RED'           => '#e60000',
		'ROSSO'         => '#e60000',
		'ROUGE'         => '#e60000',
		'BLUE'          => '#0066cc',
		'BLU'           => '#0066cc',
		'NAVY'          => '#1a2b4a',
		'GREEN'         => '#008000',
		'VERDE'         => '#008000',
		'BROWN'         => '#8b4513',
		'MARRONE'       => '#8b4513',
		'BEIGE'         => '#f5f5dc',
		'GREY'          => '#808080',
		'GRAY'          => '#808080',
		'GRIGIO'        => '#808080',
		'PINK'          => '#ffc0cb',
		'ROSA'          => '#ffc0cb',
		'ROSE'          => '#ff007f',
		'YELLOW'        => '#ffff00',
		'GIALLO'        => '#ffff00',
		'JAUNE'         => '#ffff00',
		'ORANGE'        => '#ffa500',
		'ARANCIO'       => '#ffa500',
		'PURPLE'        => '#800080',
		'VIOLA'         => '#800080',
		'VIOLET'        => '#ee82ee',
		'GOLD'          => '#ffd700',
		'ORO'           => '#ffd700',
		'SILVER'        => '#c0c0c0',
		'ARGENTO'       => '#c0c0c0',
		'CREAM'         => '#fffdd0',
		'BLUSH'         => '#de5d83',
		'CORAL'         => '#ff7f50',
		'BURGUNDY'      => '#800020',
		'EMERALD'       => '#50c878',
		'EMERALDGOLD'   => '#88ab7d',
		'BONEGOLD'      => '#c9a96e',
		'IVORY'         => '#fffff0',
		'KHAKI'         => '#c3b091',
		'LAVENDER'      => '#e6e6fa',
		'MUSTARD'       => '#ffdb58',
		'OLIVE'         => '#808000',
		'PEACH'         => '#ffe5b4',
		'SAGE'          => '#9caf88',
		'TAUPE'         => '#483c32',
		'ALABASTER'     => '#f0f0f0',
		'STONE'         => '#928574',
		'CAMEL'         => '#c19a6b',
		'SAND'          => '#e2ca9c',
		'WINE'          => '#722f37',
		'RUBINE'        => '#e0115f',
		'INDIGO'        => '#4b0082',
		'TEAL'          => '#008080',
		'TURQUOISE'     => '#40e0d0',
		'COBALT'        => '#0047ab',
		'SKY'           => '#87ceeb',
		'CIELO'         => '#87ceeb',
		'AZURE'         => '#007fff',
		'AZUR'          => '#007fff',
		'COGNAC'        => '#9f381d',
		'BRONZE'        => '#cd7f32',
		'CHAMPAGNE'     => '#f7e7ce',
		'CHARCOAL'      => '#36454f',
		'GRAPHITE'      => '#41424c',
		'BLUET'         => '#4682b4',
		'DUNE'          => '#967259',
		'PEARL'         => '#eae0c8',
		'FUCHSIA'       => '#ff00ff',
		'FUCSIA'        => '#ff00ff',
		'MAGENTA'       => '#ff00ff',
		'LILAC'         => '#c8a2c8',
		'PLUM'          => '#8e4585',
		'RUBI'          => '#e0115f',
		'RUBY'          => '#e0115f',
		'ANTRACITE'     => '#293133',
		'ANTRACITA'     => '#293133',
		'MULTICOLOR'    => '#linear-gradient(135deg,#ff0000,#ffff00,#00ff00,#0000ff)',
		'MULTI'         => '#linear-gradient(135deg,#ff0000,#ffff00,#00ff00,#0000ff)',
		'FANTASY'       => '#linear-gradient(135deg,#ff6b6b,#feca57,#48dbfb)',
		'MIX'           => '#linear-gradient(135deg,#c0c0c0,#808080)',
		'PRINT'         => '#e0e0e0',
		'VARI'          => '#linear-gradient(135deg,#ff0000,#ffff00,#00ff00,#0000ff)',
	);

	/**
	 * Определить HEX-код по названию цвета.
	 *
	 * @param string $color_name Название цвета (например 'BLACK', 'EMERALDGOLD', 'WHITEXGREEN')
	 * @return string|null HEX-код (например '#000000') или null если не распознано
	 */
	public static function match( $color_name ) {
		if ( empty( $color_name ) ) {
			return null;
		}

		$name = strtoupper( trim( $color_name ) );

		// 1. Точное совпадение.
		if ( isset( self::$color_map[ $name ] ) ) {
			return self::$color_map[ $name ];
		}

		// 2. Поиск по включению (например 'WHITEXGREEN' содержит 'WHITE').
		// Проверяем от самых длинных ключей к коротким — чтобы 'EMERALDGOLD'
		// совпал с 'EMERALDGOLD', а не с 'GOLD'.
		$keys = array_keys( self::$color_map );
		usort( $keys, function( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		});

		foreach ( $keys as $key ) {
			if ( strpos( $name, $key ) !== false ) {
				return self::$color_map[ $key ];
			}
		}

		return null;
	}
}
