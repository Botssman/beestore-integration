<?php
/**
 * Управление переводами категорий и атрибутов.
 *
 * Хранит переводы в wp_options:
 *   - bsi_translations_product_cat  → ['CLOTHING' => 'Одежда', 'JACKETS' => 'Куртки', ...]
 *   - bsi_translations_pa_sesso     → ['WOMAN' => 'Женский', 'MAN' => 'Мужской', ...]
 *
 * При импорте плагин использует перевод (если есть), иначе — оригинальное название.
 * Слаг терма сохраняется всегда (CLOTHING → slug 'clothing', name 'Одежда').
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSI_Translations {

	private static $instance = null;

	/**
	 * Какие таксономии поддерживают перевод.
	 */
	const SUPPORTED_TAXONOMIES = array(
		'product_cat' => 'Категории товаров',
		'pa_sesso'    => 'Пол',
	);

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// AJAX для сохранения переводов.
		add_action( 'wp_ajax_bsi_save_translations', array( $this, 'ajax_save_translations' ) );
	}

	/**
	 * Получить ключ опции для таксономии.
	 *
	 * @param string $taxonomy
	 * @return string
	 */
	private function get_option_key( $taxonomy ) {
		return 'bsi_translations_' . $taxonomy;
	}

	/**
	 * Получить все переводы для таксономии.
	 *
	 * @param string $taxonomy
	 * @return array [оригинал => перевод]
	 */
	public function get_translations( $taxonomy ) {
		$translations = get_option( $this->get_option_key( $taxonomy ), array() );
		return is_array( $translations ) ? $translations : array();
	}

	/**
	 * Получить перевод для конкретного значения.
	 *
	 * @param string $taxonomy
	 * @param string $original Английское название (например 'CLOTHING').
	 * @return string Перевод (например 'Одежда') или пустая строка.
	 */
	public function get_translation( $taxonomy, $original ) {
		$original = trim( $original );
		if ( '' === $original ) {
			return '';
		}
		$translations = $this->get_translations( $taxonomy );
		return isset( $translations[ $original ] ) ? $translations[ $original ] : '';
	}

	/**
	 * Сохранить переводы для таксономии (полный массив).
	 *
	 * @param string $taxonomy
	 * @param array  $translations [оригинал => перевод]
	 */
	public function save_translations( $taxonomy, $translations ) {
		$clean = array();
		if ( is_array( $translations ) ) {
			foreach ( $translations as $orig => $ru ) {
				$orig = trim( (string) $orig );
				$ru   = trim( (string) $ru );
				if ( '' !== $orig && '' !== $ru ) {
					$clean[ $orig ] = $ru;
				}
			}
		}
		update_option( $this->get_option_key( $taxonomy ), $clean, false );
	}

	/**
	 * Применить переводы к существующим термам (переименовать name, не трогать slug).
	 * Вызывается после сохранения переводов в админке.
	 *
	 * @param string $taxonomy
	 * @return array [updated => N, skipped => M, errors => []]
	 */
	public function apply_to_existing_terms( $taxonomy ) {
		$translations = $this->get_translations( $taxonomy );
		$updated      = 0;
		$skipped      = 0;
		$errors       = array();

		if ( empty( $translations ) || ! taxonomy_exists( $taxonomy ) ) {
			return array( 'updated' => 0, 'skipped' => 0, 'errors' => array() );
		}

		// Получаем все термы таксономии.
		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 0,
		) );

		if ( is_wp_error( $terms ) ) {
			return array( 'updated' => 0, 'skipped' => 0, 'errors' => array( $terms->get_error_message() ) );
		}

		// Строим обратный индекс: name терма → новый перевод.
		// Если терм называется "CLOTHING" и в переводе есть "CLOTHING" => "Одежда",
		// переименуем терм в "Одежда", slug оставляем.
		foreach ( $terms as $term ) {
			$original_name_upper = strtoupper( $term->name );
			// Ищем точное совпадение по имени (case-insensitive).
			$new_name = '';
			foreach ( $translations as $orig => $ru ) {
				if ( 0 === strcasecmp( $orig, $term->name ) ) {
					$new_name = $ru;
					break;
				}
			}

			if ( empty( $new_name ) ) {
				$skipped++;
				continue;
			}

			// Если уже переведён — пропускаем.
			if ( $term->name === $new_name ) {
				$skipped++;
				continue;
			}

			// Переименуем (slug не трогаем).
			$result = wp_update_term( $term->term_id, $taxonomy, array(
				'name' => $new_name,
				'slug' => $term->slug, // явно сохраняем slug
			) );

			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( '%s: %s', $term->name, $result->get_error_message() );
			} else {
				$updated++;
			}
		}

		return array(
			'updated' => $updated,
			'skipped' => $skipped,
			'errors'  => $errors,
		);
	}

	/**
	 * AJAX: сохранить переводы из админки.
	 */
	public function ajax_save_translations() {
		check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
		}

		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['taxonomy'] ) ) : '';
		$translations_raw = isset( $_POST['translations'] ) ? wp_unslash( $_POST['translations'] ) : array();

		if ( ! isset( self::SUPPORTED_TAXONOMIES[ $taxonomy ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Неподдерживаемая таксономия.', 'beestore-integration' ) ) );
		}

		// Санитизация.
		$translations = array();
		if ( is_array( $translations_raw ) ) {
			foreach ( $translations_raw as $orig => $ru ) {
				$orig = sanitize_text_field( $orig );
				$ru   = sanitize_text_field( $ru );
				if ( '' !== $orig && '' !== $ru ) {
					$translations[ $orig ] = $ru;
				}
			}
		}

		// Сохраняем в БД.
		$this->save_translations( $taxonomy, $translations );

		// Применяем к существующим термам (переименовываем name, slug не трогаем).
		$apply_result = $this->apply_to_existing_terms( $taxonomy );

		BSI_Logger::instance()->info( 'translations', 'Сохранены переводы', array(
			'taxonomy' => $taxonomy,
			'count'    => count( $translations ),
			'updated'  => $apply_result['updated'],
			'skipped'  => $apply_result['skipped'],
			'errors'   => $apply_result['errors'],
		) );

		wp_send_json_success( array(
			'message' => sprintf(
				/* translators: 1: кол-во переводов, 2: кол-во обновлённых термов */
				__( 'Сохранено переводов: %1$d. Обновлено категорий: %2$d.', 'beestore-integration' ),
				count( $translations ),
				$apply_result['updated']
			),
			'apply_result' => $apply_result,
		) );
	}

	/**
	 * Получить список всех уникальных значений для таксономии из всех товаров BeeStore.
	 * Используется в админке, чтобы показать какие категории уже есть.
	 *
	 * @param string $taxonomy
	 * @return array [name => term_id]
	 */
	public function get_existing_terms( $taxonomy ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 0,
		) );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$result = array();
		foreach ( $terms as $term ) {
			$result[ $term->name ] = array(
				'term_id'    => $term->term_id,
				'slug'       => $term->slug,
				'count'      => $term->count,
				'current_name' => $term->name,
			);
		}
		return $result;
	}
}
