<?php
/**
 * Импорт товаров из CSV BeeStore в WooCommerce.
 *
 * Стратегия:
 *  - Каждая строка CSV = вариант (CodArticolo = Model/Color/Size).
 *  - Группируем строки по IGUArticolo (родительская модель) → вариативный товар.
 *  - Если для модели всего один размер UNICA → простой товар.
 *  - Атрибуты WC: "Colore" и "Taglia" создаются/используются как глобальные.
 *  - Картинки (URLImg1..10) — скачиваем во Media Library и привязываем:
 *      * родительский товар — URLImg1 (по первой строке модели)
 *      * галерея — URLImg2..10
 *      * variation image — URLImg1 соответствующей строки
 *  - Цены: PrezzoIvato (gross with VAT) → _price, _regular_price.
 *    Если есть Sconto — PrezzoScontatoIvato → _sale_price.
 *  - Остаток: Disponibilita → stock.
 *  - Ключ маппинга: postmeta _bsi_cod_articolo (для вариаций) и _bsi_igu_articolo (для родителя).
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class BSI_Importer {

        private static $instance = null;

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {
                // Cron hook импорта.
                add_action( 'bsi_cron_import_catalog', array( $this, 'cron_import' ) );
                // AJAX/ручной запуск.
                add_action( 'wp_ajax_bsi_manual_import', array( $this, 'ajax_manual_import' ) );
                // AJAX backfill картинок (докачка после разблокировки Sirio).
                add_action( 'wp_ajax_bsi_backfill_images', array( $this, 'ajax_backfill_images' ) );
        }

        /* ---------------------------------------------------------------------
         * Точка входа для cron.
         * --------------------------------------------------------------------- */
        public function cron_import() {
                $this->log( 'info', 'Запуск cron-импорта каталога' );

                $result = BSI_FTP::instance()->fetch_latest_zip();
                if ( is_wp_error( $result ) ) {
                        $this->log( 'info', 'Нет новых файлов для импорта: ' . $result->get_error_message() );
                        return;
                }

                $this->import_csv_file( $result['csv'], $result['zip'] );

                // Пометить как обработанный.
                // Для голого CSV (zip='') mark_processed берёт сам csv_path.
                $file_to_mark = $result['zip'] ? $result['zip'] : $result['csv'];
                BSI_FTP::instance()->mark_processed( $file_to_mark );
        }

        /* ---------------------------------------------------------------------
         * Ручной запуск через AJAX.
         * --------------------------------------------------------------------- */
        public function ajax_manual_import() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'ftp';

                if ( 'ftp' === $mode ) {
                        $result = BSI_FTP::instance()->fetch_latest_zip();
                        if ( is_wp_error( $result ) ) {
                                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
                        }
                        $csv = $result['csv'];
                        $zip = $result['zip'];
                } else {
                        // Ручная загрузка — ожидаем file_path во временной папке.
                        if ( empty( $_POST['csv_path'] ) ) {
                                wp_send_json_error( array( 'message' => __( 'Не передан путь к CSV.', 'beestore-integration' ) ) );
                        }
                        $csv = sanitize_text_field( wp_unslash( $_POST['csv_path'] ) );
                        $zip = '';
                }

                $report = $this->import_csv_file( $csv, $zip );

                // Пометить как обработанный. Для голого CSV используем сам csv_path.
                $file_to_mark = $zip ? $zip : $csv;
                BSI_FTP::instance()->mark_processed( $file_to_mark );

                wp_send_json_success( $report );
        }

        /* ---------------------------------------------------------------------
         * Основной метод импорта CSV-файла.
         *
         * @param string $csv_file Путь к CSV.
         * @param string $zip_file Опционально — путь к ZIP (для лога и mark_processed).
         *                         Если пусто — значит CSV был скачан напрямую (без ZIP-обёртки).
         * @return array Отчёт.
         * --------------------------------------------------------------------- */
        public function import_csv_file( $csv_file, $zip_file = '' ) {
                $start_time = microtime( true );
                $parser     = BSI_CSV_Parser::instance()->open( $csv_file );
                if ( is_wp_error( $parser ) ) {
                        $this->log( 'error', 'Не удалось открыть CSV', array( 'file' => $csv_file, 'err' => $parser->get_error_message() ) );
                        return array( 'success' => false, 'error' => $parser->get_error_message() );
                }

                // Сохраняем имя ZIP как маркер "последнего импорта".
                if ( $zip_file ) {
                        update_option( 'bsi_last_import_zip', basename( $zip_file ) );
                }
                update_option( 'bsi_last_import_started', current_time( 'mysql' ) );

                $settings    = get_option( 'bsi_settings', array() );
                $batch_size  = isset( $settings['import_batch_size'] ) ? (int) $settings['import_batch_size'] : 200;
                $delete_oos  = isset( $settings['delete_out_of_stock'] ) && '1' === $settings['delete_out_of_stock'];

                $this->log( 'info', 'Старт импорта CSV', array( 'file' => basename( $csv_file ) ) );

                // Шаг 1: Группируем строки по IGUArticolo в памяти (т.к. нам нужно знать
                // количество вариантов для принятия решения parent-type).
                // Если файл слишком большой — используем двухпроходный алгоритм: первый
                // проход строит индекс IGUArticolo → количество, второй проход импортирует.
                $models = array();   // IGUArticolo => [ parent_data, variations => [...] ]
                $processed_count = 0;

                foreach ( $parser as $idx => $row ) {
                        $processed_count++;
                        if ( empty( $row['IGUArticolo'] ) ) {
                                continue;
                        }
                        $igu_articolo = $row['IGUArticolo'];

                        if ( ! isset( $models[ $igu_articolo ] ) ) {
                                $models[ $igu_articolo ] = array(
                                        'parent'   => $row,
                                        'variants' => array(),
                                );
                        }
                        $models[ $igu_articolo ]['variants'][] = $row;

                        // Чтобы не держать все данные в памяти одновременно, обрабатываем чанками.
                        if ( count( $models ) >= $batch_size ) {
                                $this->process_models_batch( $models );
                                $models = array(); // сброс.
                        }
                }
                $parser->close();

                // Финальный чанк.
                if ( ! empty( $models ) ) {
                        $this->process_models_batch( $models );
                }

                // Шаг 2: Если включено delete_out_of_stock — снять с публикации товары,
                // не встретившиеся в выгрузке.
                if ( $delete_oos ) {
                        $this->deactivate_unseen_products();
                }

                $elapsed = round( microtime( true ) - $start_time, 2 );
                $report  = array(
                        'success'         => true,
                        'rows_processed'  => $processed_count,
                        'models_imported' => count( $models ),
                        'elapsed_seconds' => $elapsed,
                );
                update_option( 'bsi_last_import_report', $report );
                update_option( 'bsi_last_import_finished', current_time( 'mysql' ) );

                $this->log( 'info', 'Импорт завершён', $report );

                return $report;
        }

        /**
         * Обработать пачку моделей (parent + variants) и записать в WC.
         *
         * @param array $models Ассоциативный массив: IGUArticolo => [parent, variants].
         */
        private function process_models_batch( $models ) {
                foreach ( $models as $igu_articolo => $data ) {
                        try {
                                $this->upsert_model( $igu_articolo, $data['parent'], $data['variants'] );
                        } catch ( Exception $e ) {
                                $this->log( 'error', 'Ошибка импорта модели', array(
                                        'igu' => $igu_articolo,
                                        'err' => $e->getMessage(),
                                ) );
                        }
                }
        }

        /**
         * Создать или обновить товар (parent) и его варианты в WooCommerce.
         *
         * @param string $igu_articolo
         * @param array  $parent_row   Строка CSV родителя (первая встреченная).
         * @param array  $variant_rows Массив строк вариантов (цвет/размер).
         */
        private function upsert_model( $igu_articolo, $parent_row, $variant_rows ) {
                // Проверим количество вариантов.
                $variants_count = count( $variant_rows );
                $is_variable = $variants_count > 1;

                // Если все строки имеют одинаковый цвет и размер — простой товар.
                if ( 1 === $variants_count ) {
                        $is_variable = false;
                } else {
                        // Проверим — может быть одна модель, но разные размеры. Тогда variable.
                        $unique_combos = array();
                        foreach ( $variant_rows as $v ) {
                                $key = $v['CodColore'] . '|' . $v['Taglia'];
                                $unique_combos[ $key ] = true;
                        }
                        if ( count( $unique_combos ) <= 1 ) {
                                $is_variable = false;
                        }
                }

                if ( $is_variable ) {
                        $this->upsert_variable_product( $igu_articolo, $parent_row, $variant_rows );
                } else {
                        $this->upsert_simple_product( $igu_articolo, $parent_row, $variant_rows[0] );
                }
        }

        /* ---------------------------------------------------------------------
         * Простой товар.
         * --------------------------------------------------------------------- */
        private function upsert_simple_product( $igu_articolo, $parent_row, $row ) {
                $product_id = $this->find_product_by_meta( '_bsi_igu_articolo', $igu_articolo );

                $product = ( $product_id )
                        ? wc_get_product( $product_id )
                        : new WC_Product_Simple();

                if ( ! $product || ! ( $product instanceof WC_Product_Simple ) ) {
                        $product = new WC_Product_Simple();
                }

                // Базовые поля.
                $this->apply_common_fields( $product, $parent_row );

                // Цена.
                $this->apply_pricing( $product, $row );

                // Stock.
                $this->apply_stock( $product, $row );

                // SKU = CodArticolo (уникальный код варианта).
                $sku = isset( $row['CodArticolo'] ) ? $row['CodArticolo'] : '';
                if ( $sku ) {
                        try {
                                $product->set_sku( $sku );
                        } catch ( Exception $e ) {
                                $this->log( 'warning', 'Конфликт SKU', array( 'sku' => $sku, 'err' => $e->getMessage() ) );
                        }
                }

                // Barcode (EAN) — в meta.
                if ( ! empty( $row['EAN'] ) ) {
                        $product->update_meta_data( '_bsi_ean', $row['EAN'] );
                }
                if ( ! empty( $row['BarCode'] ) ) {
                        $product->update_meta_data( '_bsi_barcode', $row['BarCode'] );
                }
                $product->update_meta_data( '_bsi_igu_articolo', $igu_articolo );
                $product->update_meta_data( '_bsi_cod_articolo', $sku );

                $product_id = $product->save();

                // Картинки.
                $this->apply_images( $product_id, $row, null );

                // Категории/атрибуты.
                $this->apply_terms( $product_id, $parent_row );

                return $product_id;
        }

        /* ---------------------------------------------------------------------
         * Вариативный товар.
         * --------------------------------------------------------------------- */
        private function upsert_variable_product( $igu_articolo, $parent_row, $variant_rows ) {
                $product_id = $this->find_product_by_meta( '_bsi_igu_articolo', $igu_articolo );

                $product = ( $product_id )
                        ? wc_get_product( $product_id )
                        : new WC_Product_Variable();

                if ( ! $product || ! ( $product instanceof WC_Product_Variable ) ) {
                        $product = new WC_Product_Variable();
                }

                $this->apply_common_fields( $product, $parent_row );

                // SKU родителя = IGUArticolo (если он ещё не занят).
                try {
                        $product->set_sku( $igu_articolo );
                } catch ( Exception $e ) {
                        // Если SKU конфликтует — оставляем без изменений.
                }

                $product->update_meta_data( '_bsi_igu_articolo', $igu_articolo );

                $product_id = $product->save();

                // Атрибуты: Color + Size (через глобальные таксономии pa_colore, pa_taglia).
                $colors = array();
                $sizes  = array();
                foreach ( $variant_rows as $v ) {
                        if ( ! empty( $v['DSColore'] ) ) {
                                $colors[ $v['DSColore'] ] = true;
                        }
                        if ( ! empty( $v['Taglia'] ) ) {
                                $sizes[ $v['Taglia'] ] = true;
                        }
                }
                $colors = array_keys( $colors );
                $sizes  = array_keys( $sizes );

                $attributes = array();

                if ( ! empty( $colors ) ) {
                        // Создаём термы в pa_colore и собираем их slug'и.
                        $color_slugs = array();
                        foreach ( $colors as $color_name ) {
                                $slug = $this->ensure_attribute_term( $color_name, 'pa_colore' );
                                if ( $slug ) {
                                        $color_slugs[] = $slug;
                                }
                        }

                        $attr_color = new WC_Product_Attribute();
                        $attr_color->set_id( $this->get_attribute_id( 'pa_colore' ) );
                        $attr_color->set_name( 'pa_colore' );
                        $attr_color->set_options( $color_slugs );
                        $attr_color->set_position( 1 );
                        $attr_color->set_visible( true );
                        $attr_color->set_variation( true );
                        $attributes['pa_colore'] = $attr_color;
                }
                if ( ! empty( $sizes ) ) {
                        // Создаём термы в pa_taglia.
                        $size_slugs = array();
                        foreach ( $sizes as $size_name ) {
                                $slug = $this->ensure_attribute_term( $size_name, 'pa_taglia' );
                                if ( $slug ) {
                                        $size_slugs[] = $slug;
                                }
                        }

                        $attr_size = new WC_Product_Attribute();
                        $attr_size->set_id( $this->get_attribute_id( 'pa_taglia' ) );
                        $attr_size->set_name( 'pa_taglia' );
                        $attr_size->set_options( $size_slugs );
                        $attr_size->set_position( 2 );
                        $attr_size->set_visible( true );
                        $attr_size->set_variation( true );
                        $attributes['pa_taglia'] = $attr_size;
                }

                $product->set_attributes( $attributes );
                $product_id = $product->save();

                // Картинки для родителя (берём с первого варианта).
                $this->apply_images( $product_id, $parent_row, null );

                // Категории/атрибуты-термы.
                $this->apply_terms( $product_id, $parent_row );

                // Вариации.
                $existing_variations = $product->get_children();
                $seen_variant_ids    = array();

                foreach ( $variant_rows as $row ) {
                        $variation_id = $this->upsert_variation( $product_id, $row, $existing_variations );
                        if ( $variation_id ) {
                                $seen_variant_ids[] = $variation_id;
                        }
                }

                // Снимаем с публикации варианты, не встретившиеся в выгрузке (но не удаляем!).
                foreach ( $existing_variations as $vid ) {
                        if ( ! in_array( $vid, $seen_variant_ids, true ) ) {
                                $variation = wc_get_product( $vid );
                                if ( $variation ) {
                                        $variation->set_status( 'private' );
                                        $variation->save();
                                }
                        }
                }

                WC_Product_Variable::sync( $product_id );

                // Установим вариацию по умолчанию — первую доступную (с остатком > 0),
                // иначе просто первую.
                $this->set_default_variation( $product_id, $variant_rows );

                return $product_id;
        }

        /**
         * Получить ID глобального атрибута по slug.
         *
         * @param string $slug Slug атрибута (например 'colore' или 'pa_colore').
         * @return int
         */
        private function get_attribute_id( $slug ) {
                // Нормализуем slug — убираем префикс 'pa_'.
                $slug = str_replace( 'pa_', '', $slug );

                $taxonomy = function_exists( 'wc_attribute_taxonomy_name' ) ? wc_attribute_taxonomy_name( $slug ) : 'pa_' . $slug;
                $attr_id  = 0;

                if ( function_exists( 'wc_get_attribute' ) ) {
                        $attr = wc_get_attribute( $slug );
                        if ( $attr && isset( $attr->attribute_id ) ) {
                                $attr_id = (int) $attr->attribute_id;
                        }
                }

                return $attr_id;
        }

        /**
         * Создать или получить терм атрибута в глобальной таксономии.
         *
         * @param string $name     Имя (например 'BLACK' или 'XXL').
         * @param string $taxonomy Таксономия ('pa_colore' или 'pa_taglia').
         * @return string slug терма (или пустая строка при ошибке).
         */
        private function ensure_attribute_term( $name, $taxonomy ) {
                $name = trim( $name );
                if ( empty( $name ) ) {
                        return '';
                }

                // Проверяем существование таксономии.
                if ( ! taxonomy_exists( $taxonomy ) ) {
                        $this->log( 'warning', 'Таксономия атрибута не существует', array(
                                'taxonomy' => $taxonomy,
                                'hint'     => 'Деактивируйте и активируйте плагин BeeStore — атрибуты создаются при активации.',
                        ) );
                        return '';
                }

                // Ищем существующий терм по имени.
                $existing = term_exists( $name, $taxonomy );
                if ( is_array( $existing ) && isset( $existing['term_id'] ) ) {
                        $term = get_term( $existing['term_id'], $taxonomy );
                        return $term ? $term->slug : '';
                }

                // Создаём новый терм.
                $result = wp_insert_term( $name, $taxonomy );
                if ( is_wp_error( $result ) ) {
                        $this->log( 'warning', 'Не удалось создать терм атрибута', array(
                                'name'     => $name,
                                'taxonomy' => $taxonomy,
                                'error'    => $result->get_error_message(),
                        ) );
                        return '';
                }

                $term = get_term( $result['term_id'], $taxonomy );
                return $term ? $term->slug : '';
        }

        /**
         * Установить вариацию по умолчанию для вариативного товара.
         *
         * Без этого на странице товара не будет предвыбранного цвета/размера,
         * и кнопка "Add to cart" может быть недоступна.
         *
         * @param int   $product_id    ID родительского товара.
         * @param array $variant_rows  Массив строк CSV с вариациями.
         */
        private function set_default_variation( $product_id, $variant_rows ) {
                $product = wc_get_product( $product_id );
                if ( ! $product || ! ( $product instanceof WC_Product_Variable ) ) {
                        return;
                }

                // Ищем первую вариацию с остатком > 0.
                $default_color = '';
                $default_size  = '';

                foreach ( $variant_rows as $row ) {
                        $stock = isset( $row['Disponibilita'] ) ? (float) $row['Disponibilita'] : 0;
                        if ( $stock > 0 ) {
                                if ( ! empty( $row['DSColore'] ) && empty( $default_color ) ) {
                                        $color_slug = $this->ensure_attribute_term( $row['DSColore'], 'pa_colore' );
                                        if ( $color_slug ) {
                                                $default_color = $color_slug;
                                        }
                                }
                                if ( ! empty( $row['Taglia'] ) && empty( $default_size ) ) {
                                        $size_slug = $this->ensure_attribute_term( $row['Taglia'], 'pa_taglia' );
                                        if ( $size_slug ) {
                                                $default_size = $size_slug;
                                        }
                                }
                                if ( $default_color && $default_size ) {
                                        break;
                                }
                        }
                }

                // Если не нашли в наличии — берём первую вариацию.
                if ( empty( $default_color ) && ! empty( $variant_rows[0]['DSColore'] ) ) {
                        $default_color = $this->ensure_attribute_term( $variant_rows[0]['DSColore'], 'pa_colore' );
                }
                if ( empty( $default_size ) && ! empty( $variant_rows[0]['Taglia'] ) ) {
                        $default_size = $this->ensure_attribute_term( $variant_rows[0]['Taglia'], 'pa_taglia' );
                }

                $default_attrs = array();
                if ( $default_color ) {
                        $default_attrs['pa_colore'] = $default_color;
                }
                if ( $default_size ) {
                        $default_attrs['pa_taglia'] = $default_size;
                }

                if ( ! empty( $default_attrs ) ) {
                        $product->set_default_attributes( $default_attrs );
                        $product->save();
                }
        }

        /**
         * Создать или обновить вариацию.
         *
         * @param int   $product_id   ID родителя.
         * @param array $row          Строка CSV.
         * @param array $existing_ids Существующие ID вариаций.
         * @return int
         */
        private function upsert_variation( $product_id, $row, $existing_ids ) {
                $cod_articolo = isset( $row['CodArticolo'] ) ? $row['CodArticolo'] : '';
                $variation_id = $cod_articolo ? $this->find_variation_by_meta( '_bsi_cod_articolo', $cod_articolo, $product_id ) : 0;

                $variation = ( $variation_id )
                        ? wc_get_product( $variation_id )
                        : new WC_Product_Variation();

                if ( ! $variation || ! ( $variation instanceof WC_Product_Variation ) ) {
                        $variation = new WC_Product_Variation();
                }

                $variation->set_parent_id( $product_id );

                // Привязываем атрибуты к вариации через ГЛОБАЛЬНЫЕ термы таксономии.
                // Это критически важно — иначе WooCommerce не покажет выбор на странице товара.
                $attrs = array();

                if ( ! empty( $row['DSColore'] ) ) {
                        $color_slug = $this->ensure_attribute_term( $row['DSColore'], 'pa_colore' );
                        if ( $color_slug ) {
                                $attrs['pa_colore'] = $color_slug;
                        }
                }

                if ( ! empty( $row['Taglia'] ) ) {
                        $size_slug = $this->ensure_attribute_term( $row['Taglia'], 'pa_taglia' );
                        if ( $size_slug ) {
                                $attrs['pa_taglia'] = $size_slug;
                        }
                }

                if ( ! empty( $attrs ) ) {
                        $variation->set_attributes( $attrs );
                }

                // SKU.
                if ( $cod_articolo ) {
                        try {
                                $variation->set_sku( $cod_articolo );
                        } catch ( Exception $e ) {
                                $this->log( 'warning', 'Конфликт SKU variation', array( 'sku' => $cod_articolo, 'err' => $e->getMessage() ) );
                        }
                }

                $this->apply_pricing( $variation, $row );
                $this->apply_stock( $variation, $row );

                $variation->update_meta_data( '_bsi_cod_articolo', $cod_articolo );
                $variation->update_meta_data( '_bsi_igu_articolo', isset( $row['IGUArticolo'] ) ? $row['IGUArticolo'] : '' );
                if ( ! empty( $row['EAN'] ) ) {
                        $variation->update_meta_data( '_bsi_ean', $row['EAN'] );
                }
                if ( ! empty( $row['BarCode'] ) ) {
                        $variation->update_meta_data( '_bsi_barcode', $row['BarCode'] );
                }

                $variation->set_status( 'publish' );
                $variation_id = $variation->save();

                // Картинка вариации.
                $this->apply_images( $variation_id, $row, $product_id );

                return $variation_id;
        }

        /* ---------------------------------------------------------------------
         * Базовые поля товара (название, описание, статус).
         * --------------------------------------------------------------------- */
        private function apply_common_fields( $product, $row ) {
                // Название: модель + описание.
                $title = isset( $row['DSArticoloWeb'] ) && $row['DSArticoloWeb']
                        ? $row['DSArticoloWeb']
                        : ( isset( $row['DSArticolo'] ) ? $row['DSArticolo'] : '' );
                if ( $title ) {
                        $product->set_name( $title );
                }

                // Описание.
                $desc = '';
                if ( ! empty( $row['Nota'] ) ) {
                        $desc .= $row['Nota'] . "\n\n";
                }
                if ( ! empty( $row['ArticoloDescrizionePers'] ) ) {
                        $desc .= $row['ArticoloDescrizionePers'];
                }
                if ( $desc ) {
                        $product->set_description( wp_kses_post( $desc ) );
                }

                // Краткое описание.
                if ( ! empty( $row['DSArticoloAggWeb'] ) ) {
                        $product->set_short_description( wp_kses_post( $row['DSArticoloAggWeb'] ) );
                }

                // Публикуем только товары с остатком > 0 и не помеченные как Annullato.
                $annullato = isset( $row['Annullato'] ) && '1' === $row['Annullato'];
                $stock     = isset( $row['Disponibilita'] ) ? (float) $row['Disponibilita'] : 0;

                if ( $annullato || $stock <= 0 ) {
                        $product->set_stock_status( 'outofstock' );
                        // Не снимаем с публикации сразу — это делается отдельным шагом.
                } else {
                        $product->set_stock_status( 'instock' );
                }

                // Статус публикации.
                $product->set_status( 'publish' );

                // Tax (VAT).
                $settings = get_option( 'bsi_settings', array() );
                $tax_rate = isset( $settings['default_tax_rate'] ) ? (float) $settings['default_tax_rate'] : 22;
                $product->set_tax_status( 'taxable' );
                $product->set_tax_class( '' ); // Стандартная ставка.

                // Weight.
                if ( ! empty( $row['Peso'] ) ) {
                        $product->set_weight( wc_format_decimal( $row['Peso'] ) );
                }
        }

        /* ---------------------------------------------------------------------
         * Цены.
         * --------------------------------------------------------------------- */
        private function apply_pricing( $product, $row ) {
                $price_gross  = isset( $row['PrezzoIvato'] ) ? (float) $row['PrezzoIvato'] : 0;
                $price_disc   = isset( $row['PrezzoScontatoIvato'] ) ? (float) $row['PrezzoScontatoIvato'] : 0;
                $discount     = isset( $row['Sconto'] ) ? (float) $row['Sconto'] : 0;

                // Применяем конвертацию цен (если включена).
                // Формула: цена_поставщика × курс_валюты × коэффициент_надбавки + фиксированная_надбавка
                $price_gross = $this->convert_price( $price_gross );
                $price_disc  = $this->convert_price( $price_disc );

                if ( $price_gross > 0 ) {
                        $product->set_regular_price( wc_format_decimal( $price_gross, 2 ) );
                }

                if ( $price_disc > 0 && $price_disc < $price_gross ) {
                        $product->set_sale_price( wc_format_decimal( $price_disc, 2 ) );
                        $product->set_price( wc_format_decimal( $price_disc, 2 ) );
                } elseif ( $discount > 0 && $price_gross > 0 ) {
                        // Если есть скидка в %, но нет PrezzoScontatoIvato — вычисляем.
                        $sale = $price_gross * ( 1 - $discount / 100 );
                        $product->set_sale_price( wc_format_decimal( $sale, 2 ) );
                        $product->set_price( wc_format_decimal( $sale, 2 ) );
                } else {
                        $product->set_sale_price( '' );
                        $product->set_price( wc_format_decimal( $price_gross, 2 ) );
                }
        }

        /**
         * Конвертация цены по формуле:
         *   итог = цена_поставщика × курс_валюты × коэффициент_надбавки + фиксированная_надбавка
         *
         * Пример:
         *   цена поставщика = 100 EUR
         *   курс = 100 (100 RUB за 1 EUR)
         *   коэффициент = 1.3 (наценка 30%)
         *   фикс = 500 RUB
         *   итог = 100 × 100 × 1.3 + 500 = 13 500 RUB
         *
         * Если конвертация выключена — возвращает цену без изменений.
         *
         * @param float $price Цена в валюте поставщика.
         * @return float Цена в валюте магазина.
         */
        private function convert_price( $price ) {
                if ( $price <= 0 ) {
                        return $price;
                }

                $settings = get_option( 'bsi_settings', array() );
                $enabled  = isset( $settings['enable_price_conversion'] ) && '1' === $settings['enable_price_conversion'];

                if ( ! $enabled ) {
                        return $price;
                }

                $rate       = isset( $settings['currency_rate'] ) ? (float) $settings['currency_rate'] : 1;
                $markup     = isset( $settings['markup_coefficient'] ) ? (float) $settings['markup_coefficient'] : 1;
                $fixed      = isset( $settings['fixed_markup'] ) ? (float) $settings['fixed_markup'] : 0;
                $round      = isset( $settings['round_prices'] ) && '1' === $settings['round_prices'];

                // Формула.
                $result = ( $price * $rate * $markup ) + $fixed;

                // Округление до целых (если включено).
                if ( $round ) {
                        $result = round( $result );
                }

                return $result;
        }

        /* ---------------------------------------------------------------------
         * Остаток.
         * --------------------------------------------------------------------- */
        private function apply_stock( $product, $row ) {
                $stock = isset( $row['Disponibilita'] ) ? (float) $row['Disponibilita'] : 0;

                $product->set_manage_stock( true );
                $product->set_stock_quantity( $stock );

                if ( $stock > 0 ) {
                        $product->set_stock_status( 'instock' );
                        $product->set_backorders( 'no' );
                } else {
                        $product->set_stock_status( 'outofstock' );
                }
        }

        /* ---------------------------------------------------------------------
         * Категории / атрибуты как таксономии.
         * --------------------------------------------------------------------- */
        private function apply_terms( $product_id, $row ) {
                // Категория товара (Macro Category / Categoria Merceologica).
                $category = isset( $row['DSCategoriaMerceologicaWeb'] ) && $row['DSCategoriaMerceologicaWeb']
                        ? $row['DSCategoriaMerceologicaWeb']
                        : ( isset( $row['DSCategoriaMerceologica'] ) ? $row['DSCategoriaMerceologica'] : '' );
                $cat_ids = array();
                if ( $category ) {
                        $cat_ids[] = $this->ensure_term( $category, 'product_cat' );
                }
                // Подкатегория — Reparto.
                if ( ! empty( $row['DSRepartoWeb'] ) ) {
                        $cat_ids[] = $this->ensure_term( $row['DSRepartoWeb'], 'product_cat', $category );
                }
                if ( ! empty( $cat_ids ) ) {
                        wp_set_post_terms( $product_id, array_filter( $cat_ids ), 'product_cat' );
                }

                // Бренд (Marca) — таксономия product_brand (если активна) или pa_brand.
                if ( ! empty( $row['DSMarcaWeb'] ) ) {
                        $brand_tax = taxonomy_exists( 'product_brand' ) ? 'product_brand' : 'pa_brand';
                        if ( taxonomy_exists( $brand_tax ) ) {
                                $brand_id = $this->ensure_term( $row['DSMarcaWeb'], $brand_tax );
                                wp_set_post_terms( $product_id, array( $brand_id ), $brand_tax );
                        }
                }

                // Сезон — как атрибут-таксономия pa_stagione.
                if ( ! empty( $row['DSStagioneWeb'] ) && taxonomy_exists( 'pa_stagione' ) ) {
                        $season_id = $this->ensure_term( $row['DSStagioneWeb'], 'pa_stagione' );
                        wp_set_post_terms( $product_id, array( $season_id ), 'pa_stagione' );
                }
        }

        /**
         * Создать/получить term.
         */
        private function ensure_term( $name, $taxonomy, $parent_name = '' ) {
                $parent = 0;
                if ( $parent_name ) {
                        $parent_term = term_exists( $parent_name, $taxonomy );
                        if ( is_array( $parent_term ) ) {
                                $parent = (int) $parent_term['term_id'];
                        }
                }

                $existing = term_exists( $name, $taxonomy, $parent );
                if ( is_array( $existing ) ) {
                        return (int) $existing['term_id'];
                }

                $result = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent ) );
                if ( ! is_wp_error( $result ) ) {
                        return (int) $result['term_id'];
                }
                return 0;
        }

        /* ---------------------------------------------------------------------
         * Картинки.
         * --------------------------------------------------------------------- */
        private function apply_images( $product_id, $row, $parent_id = null ) {
                $settings = get_option( 'bsi_settings', array() );
                $download_images = ! isset( $settings['download_images'] ) || '1' === $settings['download_images'];

                $image_urls = array();
                for ( $i = 1; $i <= 10; $i++ ) {
                        $key = 'URLImg' . $i;
                        if ( ! empty( $row[ $key ] ) ) {
                                $image_urls[] = $row[ $key ];
                        }
                }

                if ( empty( $image_urls ) ) {
                        return;
                }

                // Сохраняем ВСЕ URL картинок в meta — даже если не скачиваем.
                // Это позволит потом запустить "backfill" и докачать их,
                // когда Sirio разблокирует ваш IP на сервере картинок.
                update_post_meta( $product_id, '_bsi_image_urls', $image_urls );
                // Совместимость со старым полем — первая картинка.
                update_post_meta( $product_id, '_bsi_image_url', $image_urls[0] );

                // Если скачивание выключено — выходим, URL уже сохранены в meta.
                if ( ! $download_images ) {
                        return;
                }

                // Первая картинка = featured, остальные — галерея.
                $featured_url = array_shift( $image_urls );

                $thumb_id = $this->attach_image( $featured_url, $product_id, true );
                if ( $thumb_id ) {
                        set_post_thumbnail( $product_id, $thumb_id );
                }

                // Галерея.
                $gallery_ids = array();
                foreach ( $image_urls as $url ) {
                        $attach_id = $this->attach_image( $url, $product_id, true );
                        if ( $attach_id ) {
                                $gallery_ids[] = $attach_id;
                        }
                }
                if ( ! empty( $gallery_ids ) ) {
                        update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
                }
        }

        /**
         * Скачать картинку и привязать к товару (или использовать hotlink).
         *
         * @param string $url
         * @param int    $product_id
         * @param bool   $download Скачивать в Media или использовать URL напрямую.
         * @return int|false  attachment ID или false.
         */
        private function attach_image( $url, $product_id, $download = true ) {
                if ( empty( $url ) ) {
                        return false;
                }

                // Проверяем — не привязывали ли уже.
                $existing = $this->find_attachment_by_meta( '_bsi_image_url', $url );
                if ( $existing ) {
                        return $existing;
                }

                if ( ! $download ) {
                        // Hotlink: просто записываем URL в meta (плагин должен уметь отображать).
                        return 0;
                }

                // Скачиваем.
                $attach_id = media_sideload_image( $url, $product_id, null, 'id' );
                if ( is_wp_error( $attach_id ) ) {
                        $this->log( 'warning', 'Не удалось скачать картинку', array( 'url' => $url, 'err' => $attach_id->get_error_message() ) );
                        return false;
                }

                update_post_meta( $attach_id, '_bsi_image_url', $url );
                return $attach_id;
        }

        /**
         * Найти attachment по meta-ключу.
         */
        private function find_attachment_by_meta( $key, $value ) {
                $posts = get_posts( array(
                        'post_type'      => 'attachment',
                        'posts_per_page' => 1,
                        'meta_key'       => $key, // phpcs:ignore
                        'meta_value'     => $value, // phpcs:ignore
                        'fields'         => 'ids',
                ) );
                return ! empty( $posts ) ? (int) $posts[0] : 0;
        }

        /* ---------------------------------------------------------------------
         * Поиск товаров по meta.
         * --------------------------------------------------------------------- */
        private function find_product_by_meta( $key, $value ) {
                $posts = get_posts( array(
                        'post_type'      => array( 'product', 'product_variation' ),
                        'posts_per_page' => 1,
                        'post_status'    => 'any',
                        'meta_key'       => $key, // phpcs:ignore
                        'meta_value'     => $value, // phpcs:ignore
                        'fields'         => 'ids',
                ) );
                return ! empty( $posts ) ? (int) $posts[0] : 0;
        }

        private function find_variation_by_meta( $key, $value, $parent_id ) {
                $posts = get_posts( array(
                        'post_type'      => 'product_variation',
                        'post_parent'    => $parent_id,
                        'posts_per_page' => 1,
                        'post_status'    => 'any',
                        'meta_key'       => $key, // phpcs:ignore
                        'meta_value'     => $value, // phpcs:ignore
                        'fields'         => 'ids',
                ) );
                return ! empty( $posts ) ? (int) $posts[0] : 0;
        }

        /* ---------------------------------------------------------------------
         * Снять с публикации товары, отсутствующие в выгрузке.
         * --------------------------------------------------------------------- */
        private function deactivate_unseen_products() {
                // Получаем все ID продуктов BeeStore, у которых last_updated < начала импорта.
                $import_started = get_option( 'bsi_last_import_started', current_time( 'mysql' ) );

                $query = new WC_Product_Query( array(
                        'limit'      => -1,
                        'status'     => 'publish',
                        'meta_key'   => '_bsi_igu_articolo', // phpcs:ignore
                        'return'     => 'ids',
                ) );

                $deactivated = 0;
                foreach ( $query->get_products() as $product_id ) {
                        $updated = get_post_meta( $product_id, '_bsi_last_seen', true );
                        if ( ! $updated || $updated < $import_started ) {
                                $product = wc_get_product( $product_id );
                                if ( $product ) {
                                        $product->set_stock_status( 'outofstock' );
                                        $product->set_catalog_visibility( 'hidden' );
                                        $product->save();
                                        $deactivated++;
                                }
                        }
                }

                $this->log( 'info', 'Сняты с публикации отсутствующие товары', array( 'count' => $deactivated ) );
        }

        /* ---------------------------------------------------------------------
         * Логирование-обёртка.
         * --------------------------------------------------------------------- */
        private function log( $level, $message, $context = array() ) {
                BSI_Logger::instance()->log( $level, 'importer', $message, $context );
        }

        /* ---------------------------------------------------------------------
         * Backfill картинок — докачка URLs сохранённых в meta.
         *
         * Используется когда изначально импорт прошёл без скачивания картинок
         * (например, Sirio блокировал сервер картинок), а потом доступ открыли.
         * --------------------------------------------------------------------- */
        public function ajax_backfill_images() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 20;
                $offset     = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

                // Ищем товары с сохранёнными URL картинок, но без featured image.
                $query = new WP_Query( array(
                        'post_type'      => array( 'product', 'product_variation' ),
                        'posts_per_page' => $batch_size,
                        'offset'         => $offset,
                        'post_status'    => 'any',
                        'meta_query'     => array(
                                array(
                                        'key'     => '_bsi_image_urls',
                                        'compare' => 'EXISTS',
                                ),
                        ),
                        'fields'         => 'ids',
                        'no_found_rows'  => false,
                ) );

                $total      = $query->found_posts;
                $processed  = 0;
                $success    = 0;
                $failed     = 0;
                $errors     = array();

                foreach ( $query->posts as $product_id ) {
                        $processed++;
                        $urls = get_post_meta( $product_id, '_bsi_image_urls', true );
                        if ( empty( $urls ) || ! is_array( $urls ) ) {
                                continue;
                        }

                        // Пропускаем если уже есть featured image.
                        if ( has_post_thumbnail( $product_id ) ) {
                                $success++;
                                continue;
                        }

                        // Пытаемся скачать первую картинку.
                        $featured_url = $urls[0];
                        $attach_id = $this->attach_image( $featured_url, $product_id, true );

                        if ( $attach_id ) {
                                set_post_thumbnail( $product_id, $attach_id );
                                $success++;

                                // Галерея.
                                $gallery_ids = array();
                                $gallery_urls = array_slice( $urls, 1 );
                                foreach ( $gallery_urls as $url ) {
                                        $g_attach = $this->attach_image( $url, $product_id, true );
                                        if ( $g_attach ) {
                                                $gallery_ids[] = $g_attach;
                                        }
                                }
                                if ( ! empty( $gallery_ids ) ) {
                                        update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
                                }
                        } else {
                                $failed++;
                                if ( count( $errors ) < 3 ) {
                                        $errors[] = sprintf( 'Product #%d: %s', $product_id, $featured_url );
                                }
                        }
                }

                $this->log( 'info', 'Backfill картинок: пачка обработана', array(
                        'offset'    => $offset,
                        'processed' => $processed,
                        'success'   => $success,
                        'failed'    => $failed,
                        'total'     => $total,
                ) );

                wp_send_json_success( array(
                        'processed' => $processed,
                        'success'   => $success,
                        'failed'    => $failed,
                        'total'     => $total,
                        'next_offset' => $offset + $processed,
                        'has_more'  => ( $offset + $processed ) < $total,
                        'errors'    => $errors,
                ) );
        }
}
