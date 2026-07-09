<?php
/**
 * Конвертер картинок в WebP.
 *
 * Адаптировано из плагина wp-modern-gallery (media-webp-converter.php).
 * Использует стандартный WP_Image_Editor (автоматически выбирает Imagick или GD).
 *
 * Стратегии (баланс качество/размер):
 *   1 — максимальная компрессия (quality ~35-42)
 *   2 — высокая компрессия (quality ~50-58)
 *   3 — сбалансированно (quality ~72-75)  ← по умолчанию
 *   4 — высокое качество (quality ~85-88)
 *   5 — lossless (quality 100)
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSI_WebP {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Проверить, поддерживает ли сервер WebP.
	 *
	 * @return bool
	 */
	public function server_supports() {
		if ( class_exists( 'Imagick' ) ) {
			$formats = Imagick::queryFormats( 'WEBP' );
			if ( ! empty( $formats ) ) {
				return true;
			}
		}
		return function_exists( 'imagewebp' );
	}

	/**
	 * Получить качество для стратегии и типа источника.
	 *
	 * @param int    $strategy 1-5.
	 * @param string $source_mime 'image/jpeg' или 'image/png'.
	 * @return int quality 1-100.
	 */
	private function get_quality_for_strategy( $strategy, $source_mime = '' ) {
		$strategy = max( 1, min( 5, (int) $strategy ) );

		// Для JPEG — отдельные таблицы качества (более агрессивные).
		if ( 'image/jpeg' === $source_mime && $strategy < 5 ) {
			$jpeg_map = array(
				1 => 35,
				2 => 50,
				3 => 72,
				4 => 85,
			);
			return $jpeg_map[ $strategy ];
		}

		// Для PNG и lossless.
		$map = array(
			1 => 42,
			2 => 58,
			3 => 75,
			4 => 88,
			5 => 100,
		);
		return $map[ $strategy ];
	}

	/**
	 * Применить опции кодировщика к WP_Image_Editor.
	 *
	 * @param WP_Image_Editor $editor
	 * @param int             $strategy
	 * @param string          $source_mime
	 * @return WP_Image_Editor
	 */
	private function prepare_editor( $editor, $strategy, $source_mime ) {
		$quality  = $this->get_quality_for_strategy( $strategy, $source_mime );
		$lossless = ( 5 === (int) $strategy );

		$editor->set_quality( $quality );

		// Для Imagick — дополнительные опции WebP.
		if ( $editor instanceof WP_Image_Editor_Imagick ) {
			try {
				$imagick = $this->get_imagick_from_editor( $editor );
				if ( $imagick instanceof Imagick ) {
					$imagick->stripImage();
					if ( $lossless ) {
						$imagick->setOption( 'webp:lossless', 'true' );
					} else {
						$imagick->setOption( 'webp:lossless', 'false' );
						$imagick->setOption( 'webp:method', '6' );
						$imagick->setOption( 'webp:auto-filter', 'true' );
						$alpha_quality = $strategy <= 1 ? '70' : ( $strategy <= 2 ? '78' : '85' );
						$imagick->setOption( 'webp:alpha-quality', $alpha_quality );
					}
				}
			} catch ( Throwable $e ) {
				// Игнорируем — продолжаем с базовыми настройками.
			}
		}

		return $editor;
	}

	/**
	 * Получить объект Imagick из WP_Image_Editor_Imagick.
	 *
	 * @param WP_Image_Editor_Imagick $editor
	 * @return Imagick|null
	 */
	private function get_imagick_from_editor( $editor ) {
		if ( method_exists( $editor, 'get_image' ) ) {
			$image = $editor->get_image();
			return ( $image instanceof Imagick ) ? $image : null;
		}
		try {
			$reflection = new ReflectionClass( $editor );
			if ( ! $reflection->hasProperty( 'image' ) ) {
				return null;
			}
			$property = $reflection->getProperty( 'image' );
			$property->setAccessible( true );
			$image = $property->getValue( $editor );
			return ( $image instanceof Imagick ) ? $image : null;
		} catch ( ReflectionException $e ) {
			return null;
		}
	}

	/**
	 * Конвертировать файл в WebP.
	 *
	 * Алгоритм:
	 *   1. Если WebP получается меньше оригинала — сохраняем WebP, удаляем оригинал.
	 *   2. Если WebP больше оригинала — постепенно снижаем качество до минимума.
	 *   3. Если даже минимальное качество даёт файл больше оригинала — возвращаем ошибку.
	 *
	 * @param string $source_path Путь к исходному JPG/PNG.
	 * @param string $dest_path   Путь куда сохранить WebP (если null — рядом с источником).
	 * @param int    $strategy    1-5 (по умолчанию из настроек плагина).
	 * @return array|WP_Error [ 'path' => ..., 'filesize' => ..., 'saved_percent' => ... ]
	 */
	public function convert( $source_path, $dest_path = null, $strategy = null ) {
		if ( ! $source_path || ! file_exists( $source_path ) ) {
			return new WP_Error( 'bsi_webp_missing', 'Исходный файл не найден: ' . $source_path );
		}

		if ( ! $this->server_supports() ) {
			return new WP_Error( 'bsi_webp_unsupported', 'Сервер не поддерживает WebP (нужен Imagick или GD с imagewebp).' );
		}

		// Стратегия по умолчанию — из настроек.
		if ( null === $strategy ) {
			$settings = get_option( 'bsi_settings', array() );
			$strategy = isset( $settings['webp_strategy'] ) ? (int) $settings['webp_strategy'] : 3;
		}
		$strategy = max( 1, min( 5, (int) $strategy ) );

		// Определяем MIME-тип источника.
		$mime = wp_check_filetype( $source_path )['type'];
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			return new WP_Error( 'bsi_webp_type', 'Поддерживаются только JPEG и PNG. Получено: ' . $mime );
		}

		// Путь назначения.
		$info = pathinfo( $source_path );
		if ( ! $dest_path ) {
			$dest_path = $info['dirname'] . '/' . $info['filename'] . '.webp';
		}
		$dest_path = wp_normalize_path( $dest_path );

		if ( wp_normalize_path( $source_path ) === $dest_path ) {
			return new WP_Error( 'bsi_webp_same', 'Путь источника и назначения совпадают.' );
		}

		$original_size = (int) filesize( $source_path );
		$lossless      = ( 5 === $strategy );
		$quality       = $this->get_quality_for_strategy( $strategy, $mime );
		$min_quality   = $this->get_absolute_min_quality( $strategy, $mime );
		$quality_step  = $strategy <= 2 ? 3 : 4;
		$best          = null;

		try {
			// Постепенно снижаем качество, пока WebP не станет меньше оригинала.
			while ( $quality >= $min_quality ) {
				if ( file_exists( $dest_path ) ) {
					wp_delete_file( $dest_path );
				}

				$editor = wp_get_image_editor( $source_path );
				if ( is_wp_error( $editor ) ) {
					return $editor;
				}

				if ( ! $editor->supports_mime_type( 'image/webp' ) ) {
					return new WP_Error( 'bsi_webp_unsupported', 'WP_Image_Editor не поддерживает WebP.' );
				}

				$editor->set_quality( $quality );

				// Для Imagick — дополнительные опции.
				if ( $editor instanceof WP_Image_Editor_Imagick ) {
					$editor = $this->prepare_editor( $editor, $strategy, $mime );
				}

				$saved = $editor->save( $dest_path, 'image/webp' );
				if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
					return is_wp_error( $saved ) ? $saved : new WP_Error( 'bsi_webp_save', 'Не удалось сохранить WebP.' );
				}

				$best = array(
					'path'     => wp_normalize_path( $saved['path'] ),
					'filesize' => (int) filesize( $saved['path'] ),
				);

				// Для lossless — не оптимизируем размер.
				if ( $lossless ) {
					break;
				}

				// Если WebP меньше оригинала — отлично, выходим.
				if ( $best['filesize'] <= $original_size ) {
					break;
				}

				$quality -= $quality_step;
			}

			if ( ! $best ) {
				return new WP_Error( 'bsi_webp_save', 'Не удалось создать WebP.' );
			}

			// Если даже при минимальном качестве WebP больше оригинала — не конвертируем.
			if ( ! $lossless && $best['filesize'] > $original_size ) {
				if ( file_exists( $dest_path ) ) {
					wp_delete_file( $dest_path );
				}
				return new WP_Error(
					'bsi_webp_larger',
					'WebP получился больше оригинала (' . size_format( $best['filesize'] ) . ' > ' . size_format( $original_size ) . '). Пропущено.'
				);
			}

			$saved_percent = $original_size > 0 ? round( ( 1 - $best['filesize'] / $original_size ) * 100, 1 ) : 0;

			return array(
				'path'          => $best['path'],
				'filesize'      => $best['filesize'],
				'original_size' => $original_size,
				'saved_percent' => $saved_percent,
			);
		} catch ( Throwable $e ) {
			if ( file_exists( $dest_path ) ) {
				wp_delete_file( $dest_path );
			}
			return new WP_Error( 'bsi_webp_exception', 'Ошибка: ' . $e->getMessage() );
		}
	}

	/**
	 * Минимальное качество (пол) для адаптивного цикла.
	 *
	 * @param int    $strategy
	 * @param string $source_mime
	 * @return int
	 */
	private function get_absolute_min_quality( $strategy, $source_mime = '' ) {
		$strategy = max( 1, min( 5, (int) $strategy ) );

		if ( 'image/jpeg' === $source_mime ) {
			$map = array(
				1 => 12,
				2 => 18,
				3 => 28,
				4 => 38,
				5 => 92,
			);
		} else {
			$map = array(
				1 => 18,
				2 => 24,
				3 => 32,
				4 => 42,
				5 => 92,
			);
		}
		return $map[ $strategy ];
	}
}
