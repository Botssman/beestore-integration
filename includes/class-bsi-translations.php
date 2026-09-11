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

        /**
         * Предзаполненный словарь переводов для категорий BeeStore.
         * Ключ — английское название из BeeStore, значение — русский перевод.
         * Применяется автоматически при первом импорте, потом можно изменить.
         */
        const DEFAULT_TRANSLATIONS_PRODUCT_CAT = array(
                // Макро-категории (DSRepartoWeb).
                'CLOTHING'    => 'Одежда',
                'SHOES'       => 'Обувь',
                'BAGS'        => 'Сумки',
                'ACCESSORIES' => 'Аксессуары',

                // Подкатегории — Одежда.
                'JEANS'              => 'Джинсы',
                'PANTS'              => 'Брюки',
                'SHIRTS'             => 'Рубашки',
                'T-SHIRTS'           => 'Футболки',
                'POLO'               => 'Поло',
                'JACKETS'            => 'Куртки',
                'COATS'              => 'Пальто',
                'DRESSES'            => 'Платья',
                'LONG DRESSES'       => 'Длинные платья',
                'SKIRTS'             => 'Юбки',
                'SHORTS'             => 'Шорты',
                'BERMUDA SHORTS'     => 'Бермуды',
                'BLAZERS AND VESTS'  => 'Пиджаки и жилеты',
                'SWEATSHIRTS'        => 'Худи',
                'KNITWEARS'          => 'Трикотаж',
                'DOWN JACKETS'       => 'Пуховики',
                'LEATHER JACKETS'    => 'Кожаные куртки',
                'TRENCH COATS'       => 'Тренчи',
                'FURS'               => 'Шубы',
                'JUMPSUITS'          => 'Комбинезоны',
                'SUITS'              => 'Костюмы',
                'TAILLEURS'          => 'Женские костюмы',
                'TOPS'               => 'Топы',
                'LEGGINGS'           => 'Леггинсы',
                'SWIMSUITS'          => 'Купальники',
                'UNDERWEAR'          => 'Нижнее бельё',
                'SOCKS'              => 'Носки',
                'BEANIES'            => 'Шапки',
                'CASA/BAGNO'         => 'Дом/Ванна',

                // Подкатегории — Обувь.
                'SNEAKERS'           => 'Кроссовки',
                'BOOTS'              => 'Ботинки',
                'LOAFERS'            => 'Лоферы',
                'PUMPS'              => 'Туфли',
                'SANDALS'            => 'Сандалии',
                'SLIPPERS'           => 'Тапочки',
                'ESPADRILLES'        => 'Эспадрильи',
                'BALLERINAS'         => 'Балетки',
                'LACE-UP SHOES'      => 'Туфли на шнуровке',

                // Подкатегории — Сумки.
                'HANDBAGS'           => 'Сумки',
                'BACKPACKS'          => 'Рюкзаки',
                'BUCKET BAGS'        => 'Сумки-шопперы',
                'CROSSBODY BAGS'     => 'Сумки через плечо',
                'CLUTCHES'           => 'Клатчи',
                'WALLETS'            => 'Кошельки',
                'TRAVEL BAGS'        => 'Дорожные сумки',
                'PORTADOCUMENTI'     => 'Портфели',
                'BRIEFCASES'         => 'Портфели',
                'POUCH'              => 'Сумочки',
                'BEAUTY CASES'       => 'Косметички',
                'VALIGIE'            => 'Чемоданы',
                'SHOULDER STRAPS'    => 'Наплечные ремни',

                // Подкатегории — Аксессуары.
                'BELT'               => 'Ремни',
                'GLOVES'             => 'Перчатки',
                'SCARFS AND FOULARDS'=> 'Шарфы и платки',
                'HATS'               => 'Шляпы',
                'HATS AND HAIRBANDS' => 'Шляпы и ободки',
                'SUNGLASSES'         => 'Солнцезащитные очки',
                'EARRINGS'           => 'Серьги',
                'NECKLACES'          => 'Ожерелья',
                'RINGS'              => 'Кольца',
                'BRACELETS'          => 'Браслеты',
                'BROOCHES'           => 'Броши',
                'KEY RINGS'          => 'Брелоки',
                'TIES AND BOW TIES'  => 'Галстуки и бабочки',
                'UMBRELLAS'          => 'Зонты',
                'BEAUTY'             => 'Бьюти',
                'OBJECTS'            => 'Предметы',
                'CASES'              => 'Чехлы',
                'PERFUMES'           => 'Парфюмерия',

                // Прочее.
                'UOMO'               => 'Мужское',
        );

        /**
         * Предзаполненный словарь для пола.
         */
        const DEFAULT_TRANSLATIONS_PA_SESSO = array(
                'WOMAN' => 'Женский',
                'MAN'   => 'Мужской',
                'DONNA' => 'Женский',
                'UOMO'  => 'Мужской',
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
                // AJAX для слияния дублей категорий (slug -2, -3, ...).
                add_action( 'wp_ajax_bsi_merge_duplicate_terms', array( $this, 'ajax_merge_duplicate_terms' ) );
        }

        /**
         * AJAX: слить дубли категорий.
         * Находит термы с slug оканчивающимся на -N (N=число).
         * Переносит товары в оригинальный терм (без -N) и удаляет дубль.
         */
        public function ajax_merge_duplicate_terms() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_text_field( wp_unslash( $_POST['taxonomy'] ) ) : 'product_cat';
                if ( ! taxonomy_exists( $taxonomy ) ) {
                        wp_send_json_error( array( 'message' => 'Таксономия не существует.' ) );
                }

                $terms = get_terms( array(
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                        'number'     => 0,
                ) );

                if ( is_wp_error( $terms ) ) {
                        wp_send_json_error( array( 'message' => $terms->get_error_message() ) );
                }

                $merged = 0;
                $failed = 0;
                $errors = array();

                foreach ( $terms as $term ) {
                        // Ищем термы с slug оканчивающимся на -N (N = число).
                        if ( ! preg_match( '/^(.+)-(\d+)$/', $term->slug, $m ) ) {
                                continue;
                        }

                        $base_slug = $m[1];

                        // Ищем оригинальный терм (без -N).
                        $original = get_term_by( 'slug', $base_slug, $taxonomy );
                        if ( ! $original || is_wp_error( $original ) ) {
                                // Оригинал не найден — просто переименуем slug (убираем -N).
                                wp_update_term( $term->term_id, $taxonomy, array( 'slug' => $base_slug ) );
                                $merged++;
                                continue;
                        }

                        // Переносим товары из дубля в оригинал.
                        $products = get_objects_in_term( $term->term_id, $taxonomy );
                        if ( is_array( $products ) && ! empty( $products ) ) {
                                foreach ( $products as $product_id ) {
                                        wp_set_post_terms( $product_id, array( $original->term_id ), $taxonomy, true );
                                }
                        }

                        // Удаляем дубль.
                        $result = wp_delete_term( $term->term_id, $taxonomy );
                        if ( is_wp_error( $result ) ) {
                                $failed++;
                                $errors[] = $term->name . ': ' . $result->get_error_message();
                        } else {
                                $merged++;
                        }
                }

                BSI_Logger::instance()->info( 'translations', 'Слияние дублей категорий', array(
                        'taxonomy' => $taxonomy,
                        'merged'   => $merged,
                        'failed'   => $failed,
                ) );

                wp_send_json_success( array(
                        'merged' => $merged,
                        'failed' => $failed,
                        'errors' => $errors,
                        'message' => sprintf(
                                /* translators: 1: слито, 2: ошибок */
                                __( 'Слито дублей: %1$d, ошибок: %2$d', 'beestore-integration' ),
                                $merged,
                                $failed
                        ),
                ) );
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
         * Проверяет ТОЛЬКО пользовательские переводы (сохранённые в БД).
         * Автоперевод НЕ применяется — категории импортируются на английском,
         * пользователь переводит сам через вкладку «Переводы».
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

                // Проверяем только пользовательские переводы (из БД).
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
                        // Получаем оригинальное имя из meta (если есть).
                        $original_name = get_term_meta( $term->term_id, '_bsi_original_name', true );

                        // ВАЖНО: если meta содержит русские буквы — она сохранена с багом
                        // (предыдущие версии сохраняли русское имя вместо английского).
                        // Удаляем её и делаем reverse lookup заново.
                        if ( ! empty( $original_name ) && preg_match( '/[а-яё]/i', $original_name ) ) {
                                delete_term_meta( $term->term_id, '_bsi_original_name' );
                                $original_name = '';
                        }

                        // Если meta нет — reverse lookup по сохранённым переводам.
                        // НО: если найденный оригинал содержит русские буквы — пропускаем
                        // (переводы могли сохраниться с русским ключом, это баг).
                        if ( empty( $original_name ) ) {
                                $saved = $this->get_translations( $taxonomy );
                                foreach ( $saved as $orig => $ru ) {
                                        if ( 0 === strcasecmp( $ru, $term->name ) ) {
                                                // Проверяем что найденный оригинал — английский.
                                                if ( ! preg_match( '/[а-яё]/i', $orig ) ) {
                                                        $original_name = $orig;
                                                        break;
                                                }
                                        }
                                }
                        }

                        // Если и так не нашли — reverse lookup по встроенному словарю.
                        if ( empty( $original_name ) ) {
                                $default_dict = $this->get_default_dict( $taxonomy );
                                if ( $default_dict ) {
                                        foreach ( $default_dict as $orig => $ru ) {
                                                if ( 0 === strcasecmp( $ru, $term->name ) ) {
                                                        $original_name = $orig;
                                                        update_term_meta( $term->term_id, '_bsi_original_name', $orig );
                                                        break;
                                                }
                                        }
                                        // Если не нашли по имени — ищем по slug.
                                        if ( empty( $original_name ) && ! empty( $term->slug ) ) {
                                                foreach ( $default_dict as $orig => $ru ) {
                                                        if ( 0 === strcasecmp( str_replace( array( '-', ' ' ), '_', $orig ), str_replace( array( '-', ' ' ), '_', $term->slug ) ) ) {
                                                                $original_name = $orig;
                                                                update_term_meta( $term->term_id, '_bsi_original_name', $orig );
                                                                break;
                                                        }
                                                }
                                        }
                                }
                        }

                        // Если всё ещё не нашли — пробуем по slug без словаря.
                        // slug обычно = оригинал в нижнем регистре с заменой пробелов на дефисы.
                        if ( empty( $original_name ) && ! empty( $term->slug ) ) {
                                // Убираем суффикс дубля: "beauty-accessories-2" → "beauty-accessories".
                                $clean_slug = preg_replace( '/-\d+$/', '', $term->slug );

                                // Если slug не содержит русских букв — это английский оригинал.
                                if ( ! preg_match( '/[а-яё]/i', $clean_slug ) ) {
                                        $original_name = strtoupper( str_replace( '-', ' ', $clean_slug ) );
                                        update_term_meta( $term->term_id, '_bsi_original_name', $original_name );
                                }
                        }

                        // Если и так не нашли — оригинал = текущее имя.
                        if ( empty( $original_name ) ) {
                                $original_name = $term->name;
                        }

                        $result[ $original_name ] = array(
                                'term_id'       => $term->term_id,
                                'slug'          => $term->slug,
                                'count'         => $term->count,
                                'current_name'  => $term->name,
                                'original_name' => $original_name,
                        );
                }
                return $result;
        }
        /**
         * Получить встроенный словарь переводов для таксономии.
         * Используется для reverse lookup: по русскому имени найти английский оригинал.
         *
         * @param string $taxonomy
         * @return array|false
         */
        public function get_default_dict( $taxonomy ) {
                if ( 'product_cat' === $taxonomy ) {
                        return self::DEFAULT_TRANSLATIONS_PRODUCT_CAT;
                }
                if ( 'pa_sesso' === $taxonomy ) {
                        return self::DEFAULT_TRANSLATIONS_PA_SESSO;
                }
                return false;
        }

}
