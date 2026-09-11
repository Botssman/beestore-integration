<?php
/**
 * Управление тегом «Новинки» для товаров последнего сезона.
 *
 * Логика:
 *   - В настройках хранится текущий сезон (например '26W').
 *   - При импорте товары этого сезона помечаются тегом 'product_tag' со slug 'novinki'.
 *   - Slug тега НЕ меняется — его можно переименовать (name) без поломки.
 *   - При смене сезона старые товары теряют тег, новые получают.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class BSI_Novelties {

        private static $instance = null;

        /**
         * Slug тега новинок — НЕ меняется никогда.
         * Даже если админ переименует name тега, slug остаётся 'novinki'.
         */
        const TAG_SLUG = 'novinki';

        /**
         * Имя тега по умолчанию (можно переименовать в админке WP).
         */
        const TAG_DEFAULT_NAME = 'Новинки';

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {
                // Хук после импорта — применить тег новинок.
                add_action( 'bsi_after_import', array( $this, 'apply_novelties_tag' ), 10, 2 );

                // AJAX: применить тег новинок вручную (кнопка в админке).
                add_action( 'wp_ajax_bsi_apply_novelties', array( $this, 'ajax_apply_novelties' ) );
        }

        /**
         * Получить текущий сезон из настроек.
         * Если не задан — автоматически определяем самый свежий.
         *
         * @return string Например '26W'.
         */
        public function get_current_season() {
                $settings = get_option( 'bsi_settings', array() );
                $saved = isset( $settings['novelties_season'] ) ? trim( $settings['novelties_season'] ) : '';

                // Если задано 'auto' или пусто — берём самый свежий автоматически.
                if ( empty( $saved ) || 'auto' === strtolower( $saved ) ) {
                        return $this->get_latest_season();
                }

                return $saved;
        }

        /**
         * Получить список всех сезонов из БД (meta _bsi_season у товаров).
         *
         * @return array Сезоны отсортированные от новых к старым.
         */
        public function get_available_seasons() {
                global $wpdb;

                // Прямой SQL — получаем все уникальные значения _bsi_season.
                $seasons = $wpdb->get_col(
                        "SELECT DISTINCT pm.meta_value
                         FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                           AND p.post_type = 'product'
                           AND p.post_status != 'trash'
                         WHERE pm.meta_key = '_bsi_season'
                           AND pm.meta_value != ''"
                );

                if ( empty( $seasons ) ) {
                        return array();
                }

                // Уникальные.
                $seasons = array_unique( $seasons );

                // Сортируем: парсим год + сезон (W=зима/весна, S=лето, PI=pre-fall, etc).
                // Формат: "26W", "25S", "26PI", "24W" и т.д.
                // Сортировка: год DESC, потом сезон DESC (W=весна, S=лето → S > W в одном году).
                usort( $seasons, function( $a, $b ) {
                        $pa = $this->parse_season( $a );
                        $pb = $this->parse_season( $b );
                        // Сравниваем год (обратный порядок).
                        if ( $pa['year'] !== $pb['year'] ) {
                                return $pb['year'] - $pa['year'];
                        }
                        // Тот же год — сравниваем сезон (S > W > PI > другие).
                        $order = array( 'S' => 4, 'W' => 3, 'PI' => 2, 'PE' => 1 );
                        $oa = isset( $order[ $pa['season'] ] ) ? $order[ $pa['season'] ] : 0;
                        $ob = isset( $order[ $pb['season'] ] ) ? $order[ $pb['season'] ] : 0;
                        return $ob - $oa;
                });

                return $seasons;
        }

        /**
         * Получить самый свежий сезон.
         *
         * @return string
         */
        public function get_latest_season() {
                $seasons = $this->get_available_seasons();
                return ! empty( $seasons ) ? $seasons[0] : '';
        }

        /**
         * Разобрать строку сезона на год + сезон.
         * "26W" → ['year' => 26, 'season' => 'W']
         * "25S" → ['year' => 25, 'season' => 'S']
         *
         * @param string $season
         * @return array
         */
        private function parse_season( $season ) {
                $season = trim( $season );
                if ( preg_match( '/^(\d{2})([A-Z]{1,2})$/i', $season, $m ) ) {
                        return array(
                                'year'    => (int) $m[1],
                                'season'  => strtoupper( $m[2] ),
                        );
                }
                // Если не парсится — сортируем как строку.
                return array( 'year' => 0, 'season' => $season );
        }

        /**
         * Получить ID тега новинок (создать если не существует).
         *
         * @return int term_id тега.
         */
        public function get_tag_id() {
                // Ищем по slug (slug НЕ меняется).
                $term = get_term_by( 'slug', self::TAG_SLUG, 'product_tag' );

                if ( $term && ! is_wp_error( $term ) ) {
                        return (int) $term->term_id;
                }

                // Создаём тег с slug 'novinki' и name 'Новинки'.
                $result = wp_insert_term(
                        self::TAG_DEFAULT_NAME,
                        'product_tag',
                        array( 'slug' => self::TAG_SLUG )
                );

                if ( is_wp_error( $result ) ) {
                        // Возможно тег существует с другим slug — пробуем по имени.
                        $term = get_term_by( 'name', self::TAG_DEFAULT_NAME, 'product_tag' );
                        if ( $term && ! is_wp_error( $term ) ) {
                                return (int) $term->term_id;
                        }
                        return 0;
                }

                return (int) $result['term_id'];
        }

        /**
         * Извлечь сезон из строки CSV.
         * Единая логика: DSStagioneWeb → DSStagione.
         *
         * @param array $row Строка CSV.
         * @return string
         */
        private function extract_season( $row ) {
                $season = '';
                if ( ! empty( $row['DSStagioneWeb'] ) ) {
                        $season = $row['DSStagioneWeb'];
                } elseif ( ! empty( $row['DSStagione'] ) ) {
                        $season = $row['DSStagione'];
                }
                return trim( $season );
        }

        /**
         * Применить тег новинок после импорта.
         *
         * 1. Найти все товары текущего сезона → добавить тег 'novinki'.
         * 2. Найти все товары ДРУГИХ сезонов с тегом 'novinki' → снять тег.
         *
         * Вызывается после завершения импорта.
         *
         * @param array $imported_products Список ID товаров, импортированных в этом заходе.
         * @param array $import_data        Данные импорта (csv_file, total_rows и т.д.).
         */
        public function apply_novelties_tag( $imported_products = array(), $import_data = array() ) {
                $current_season = $this->get_current_season();
                if ( empty( $current_season ) ) {
                        // Сезон не задан — ничего не делаем.
                        return;
                }

                $tag_id = $this->get_tag_id();
                if ( ! $tag_id ) {
                        BSI_Logger::instance()->warning( 'novelties', 'Не удалось создать/найти тег новинок', array(
                                'slug' => self::TAG_SLUG,
                        ) );
                        return;
                }

                // 1. Для товаров из import_data — применяем тег если сезон совпадает.
                // $imported_products = массив ID товаров.
                // Но мы не знаем их сезон здесь — нужно проверить через meta.
                // Сохраняем сезон в meta при импорте (см. ниже — фильтр bsi_save_product_season).

                $tagged_new = 0;
                foreach ( (array) $imported_products as $product_id ) {
                        $product_season = get_post_meta( $product_id, '_bsi_season', true );
                        if ( 0 === strcasecmp( $product_season, $current_season ) ) {
                                wp_set_object_terms( $product_id, array( $tag_id ), 'product_tag', true );
                                $tagged_new++;
                        }
                }

                // 2. Снять тег с товаров ДРУГИХ сезонов.
                // Получаем все товары с тегом 'novinki'.
                $tagged_products = get_posts( array(
                        'post_type'      => 'product',
                        'posts_per_page' => -1,
                        'fields'         => 'ids',
                        'tax_query'      => array(
                                array(
                                        'taxonomy' => 'product_tag',
                                        'field'    => 'term_id',
                                        'terms'    => $tag_id,
                                ),
                        ),
                ) );

                $untagged = 0;
                foreach ( $tagged_products as $product_id ) {
                        $product_season = get_post_meta( $product_id, '_bsi_season', true );
                        // Если сезон не совпадает с текущим — снимаем тег.
                        if ( 0 !== strcasecmp( $product_season, $current_season ) ) {
                                wp_remove_object_terms( $product_id, $tag_id, 'product_tag' );
                                $untagged++;
                        }
                }

                BSI_Logger::instance()->info( 'novelties', 'Тег новинок применён', array(
                        'season'       => $current_season,
                        'tagged_new'   => $tagged_new,
                        'untagged_old'  => $untagged,
                        'tag_id'       => $tag_id,
                ) );
        }

        /**
         * Сохранить сезон товара в meta при импорте.
         * Вызывается из импортера после создания/обновления товара.
         *
         * @param int   $product_id ID товара.
         * @param array $row        Строка CSV (первая — родитель).
         */
        public function save_product_season( $product_id, $row ) {
                $season = $this->extract_season( $row );
                if ( $season ) {
                        update_post_meta( $product_id, '_bsi_season', $season );
                }
        }

        /**
         * AJAX: применить тег новинок вручную (кнопка в админке).
         */
        public function ajax_apply_novelties() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $current_season = $this->get_current_season();
                if ( empty( $current_season ) ) {
                        wp_send_json_error( array(
                                'message' => __( 'Сезон не задан в настройках. Укажите текущий сезон.', 'beestore-integration' ),
                        ) );
                }

                $tag_id = $this->get_tag_id();
                if ( ! $tag_id ) {
                        wp_send_json_error( array( 'message' => __( 'Не удалось создать тег новинок.', 'beestore-integration' ) ) );
                }

                // Снимаем тег со ВСЕХ товаров (потом добавим только нужным).
                $tagged_products = get_posts( array(
                        'post_type'      => 'product',
                        'posts_per_page' => -1,
                        'fields'         => 'ids',
                        'tax_query'      => array(
                                array(
                                        'taxonomy' => 'product_tag',
                                        'field'    => 'term_id',
                                        'terms'    => $tag_id,
                                ),
                        ),
                ) );
                foreach ( $tagged_products as $product_id ) {
                        wp_remove_object_terms( $product_id, $tag_id, 'product_tag' );
                }

                // Ищем все товары с сезоном = текущему.
                $products = get_posts( array(
                        'post_type'      => 'product',
                        'posts_per_page' => -1,
                        'fields'         => 'ids',
                        'meta_query'     => array(
                                array(
                                        'key'     => '_bsi_season',
                                        'value'   => $current_season,
                                        'compare' => '=',
                                ),
                        ),
                ) );

                $tagged = 0;
                foreach ( $products as $product_id ) {
                        wp_set_object_terms( $product_id, array( $tag_id ), 'product_tag', true );
                        $tagged++;
                }

                BSI_Logger::instance()->info( 'novelties', 'Тег новинок применён вручную', array(
                        'season' => $current_season,
                        'tagged' => $tagged,
                ) );

                wp_send_json_success( array(
                        'message' => sprintf(
                                /* translators: 1: кол-во, 2: сезон */
                                __( 'Тег «Новинки» применён к %1$d товарам сезона %2$s.', 'beestore-integration' ),
                                $tagged,
                                $current_season
                        ),
                        'tagged' => $tagged,
                ) );
        }
}
