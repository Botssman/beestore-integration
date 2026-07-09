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

                // Новые AJAX-эндпоинты для импорта с сохранением прогресса.
                add_action( 'wp_ajax_bsi_import_start', array( $this, 'ajax_import_start' ) );
                add_action( 'wp_ajax_bsi_import_process_batch', array( $this, 'ajax_import_process_batch' ) );
                add_action( 'wp_ajax_bsi_import_pause', array( $this, 'ajax_import_pause' ) );
                add_action( 'wp_ajax_bsi_import_stop', array( $this, 'ajax_import_stop' ) );
                add_action( 'wp_ajax_bsi_import_status', array( $this, 'ajax_import_status' ) );

                // AJAX: полная очистка товаров и категорий BeeStore.
                add_action( 'wp_ajax_bsi_purge_all', array( $this, 'ajax_purge_all' ) );

                // AJAX: скачать CSV с FTP для настройки фильтров (без запуска импорта).
                add_action( 'wp_ajax_bsi_download_csv_for_filters', array( $this, 'ajax_download_csv_for_filters' ) );

                // AJAX: батчевое сканирование CSV для фильтров.
                add_action( 'wp_ajax_bsi_scan_start', array( $this, 'ajax_scan_start' ) );
                add_action( 'wp_ajax_bsi_scan_step', array( $this, 'ajax_scan_step' ) );
        }

        /* ---------------------------------------------------------------------
         * Управление состоянием импорта (сохраняется в БД).
         * --------------------------------------------------------------------- */

        /**
         * Получить текущее состояние импорта.
         *
         * @return array
         */
        public function get_import_state() {
                $state = get_option( 'bsi_import_state', array() );
                $defaults = array(
                        'status'          => 'idle',     // idle | running | paused | completed | error
                        'csv_file'        => '',         // локальный путь к CSV
                        'remote_name'     => '',         // имя файла на FTP
                        'is_full_catalog' => false,
                        'total_rows'      => 0,
                        'processed_rows'  => 0,
                        'last_offset'     => 0,
                        'started_at'      => '',
                        'last_update'     => '',
                        'elapsed_seconds' => 0,
                        'errors_count'    => 0,
                        'last_error'      => '',
                        'batch_size'      => 50,
                        'created_products' => 0,
                        'updated_products' => 0,
                );
                return wp_parse_args( $state, $defaults );
        }

        /**
         * Сохранить состояние импорта.
         *
         * @param array $state
         */
        private function save_import_state( $state ) {
                $state['last_update'] = current_time( 'mysql' );
                update_option( 'bsi_import_state', $state, false );
        }

        /**
         * Обновить отдельные поля состояния.
         */
        private function update_import_state( $fields ) {
                $state = $this->get_import_state();
                $state = array_merge( $state, $fields );
                $this->save_import_state( $state );
        }

        /* ---------------------------------------------------------------------
         * AJAX: начать новый импорт (скачивает файл с FTP, инициализирует state).
         * --------------------------------------------------------------------- */
        public function ajax_import_start() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                // Если уже идёт импорт — не запускать новый.
                $state = $this->get_import_state();
                if ( 'running' === $state['status'] ) {
                        wp_send_json_error( array( 'message' => __( 'Импорт уже идёт. Обновите страницу.', 'beestore-integration' ) ) );
                }

                // СНАЧАЛА ищем уже скачанный CSV во всех папках.
                $upload_dir    = wp_upload_dir();
                $beestore_dir  = trailingslashit( $upload_dir['basedir'] ) . 'beestore';
                $dirs_to_check = array( 'downloads', 'extracted', 'processed', 'manual-downloads' );

                $csvs = array();
                foreach ( $dirs_to_check as $subdir ) {
                        $path = $beestore_dir . '/' . $subdir;
                        if ( is_dir( $path ) ) {
                                $csvs = array_merge( $csvs, glob( $path . '/*.csv' ) );
                                $csvs = array_merge( $csvs, glob( $path . '/*/*.csv' ) );
                        }
                }

                if ( ! empty( $csvs ) ) {
                        // Приоритет: файл с _0000001 в имени (полный каталог).
                        $full_catalog = array_filter( $csvs, function( $f ) {
                                return false !== strpos( basename( $f ), '_0000001.' );
                        });
                        if ( ! empty( $full_catalog ) ) {
                                usort( $full_catalog, function( $a, $b ) {
                                        return filemtime( $b ) - filemtime( $a );
                                });
                                $csv_file = $full_catalog[0];
                        } else {
                                usort( $csvs, function( $a, $b ) {
                                        return filemtime( $b ) - filemtime( $a );
                                });
                                $csv_file = $csvs[0];
                        }
                        $remote_name = basename( $csv_file );
                } else {
                        // CSV нет — скачиваем с FTP.
                        if ( function_exists( 'set_time_limit' ) ) {
                                @set_time_limit( 600 );
                        }
                        $fetch_result = BSI_FTP::instance()->fetch_latest_zip();
                        if ( is_wp_error( $fetch_result ) ) {
                                wp_send_json_error( array( 'message' => $fetch_result->get_error_message() ) );
                        }
                        $csv_file    = $fetch_result['csv'];
                        $remote_name = basename( ltrim( $fetch_result['remote_name'], './' ) );
                }

                // Считаем количество строк.
                $count_result = BSI_CSV_Parser::instance()->count_lines( $csv_file );
                $total_rows = max( 0, $count_result - 1 ); // минус заголовок.

                // Определяем, полный ли это каталог.
                $is_full = preg_match( '/_0000001\./', $remote_name );

                // ПРЕДСКАНИРОВАНИЕ: строим индекс IGUArticolo → количество вариантов.
                // Это критически важно: без этого плагин не знает, сколько всего вариантов
                // у товара во всём файле, и может создать простой товар вместо вариативного.
                $index = array();
                $scan_parser = BSI_CSV_Parser::instance()->open( $csv_file );
                if ( ! is_wp_error( $scan_parser ) ) {
                        foreach ( $scan_parser as $row ) {
                                $igu = isset( $row['IGUArticolo'] ) ? $row['IGUArticolo'] : '';
                                if ( $igu ) {
                                        if ( ! isset( $index[ $igu ] ) ) {
                                                $index[ $igu ] = 0;
                                        }
                                        $index[ $igu ]++;
                                }
                        }
                        $scan_parser->close();
                }

                // Сохраняем индекс в файл (он нужен при обработке батчей).
                $upload_dir = wp_upload_dir();
                $index_file = trailingslashit( $upload_dir['basedir'] ) . 'beestore/import-index.json';
                file_put_contents( $index_file, wp_json_encode( $index ) );

                $multi_variant_count = count( array_filter( $index, function ( $c ) { return $c > 1; } ) );

                BSI_Logger::instance()->info( 'importer', 'Предсканирование завершено', array(
                        'total_igu'       => count( $index ),
                        'multi_variant'   => $multi_variant_count,
                        'single_variant'  => count( $index ) - $multi_variant_count,
                ) );

                // Сбрасываем счётчики фильтров (лимиты категорий/брендов).
                BSI_Import_Filters::instance()->reset_counters();

                // Размер батча — из настроек (по умолчанию 200).
                $settings = get_option( 'bsi_settings', array() );
                $batch_size = isset( $settings['import_batch_size'] ) ? (int) $settings['import_batch_size'] : 200;
                if ( $batch_size < 10 ) {
                        $batch_size = 50;
                }

                // Сохраняем состояние.
                $new_state = array(
                        'status'          => 'running',
                        'csv_file'        => $csv_file,
                        'remote_name'     => $remote_name,
                        'is_full_catalog' => (bool) $is_full,
                        'total_rows'      => $total_rows,
                        'processed_rows'  => 0,
                        'last_offset'     => 0,
                        'started_at'      => current_time( 'mysql' ),
                        'last_update'     => current_time( 'mysql' ),
                        'elapsed_seconds' => 0,
                        'errors_count'    => 0,
                        'last_error'      => '',
                        'batch_size'      => $batch_size,
                        'created_products' => 0,
                        'updated_products' => 0,
                );
                $this->save_import_state( $new_state );

                // Сохраняем имя файла как маркер.
                update_option( 'bsi_last_import_zip', $remote_name );
                update_option( 'bsi_last_import_started', current_time( 'mysql' ) );

                $this->log( 'info', 'Старт импорта (новая система с прогрессом)', array(
                        'file'        => $remote_name,
                        'total_rows'  => $total_rows,
                        'is_full'     => $is_full,
                ) );

                wp_send_json_success( array(
                        'message'     => sprintf( __( 'Импорт запущен. Файл: %s, строк: %d', 'beestore-integration' ), $remote_name, $total_rows ),
                        'state'       => $new_state,
                ) );
        }

        /* ---------------------------------------------------------------------
         * AJAX: обработать один батч (50 строк).
         * --------------------------------------------------------------------- */
        public function ajax_import_process_batch() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $state = $this->get_import_state();
                if ( 'running' !== $state['status'] ) {
                        wp_send_json_error( array( 'message' => sprintf( __( 'Импорт не запущен (статус: %s)', 'beestore-integration' ), $state['status'] ) ) );
                }

                if ( empty( $state['csv_file'] ) || ! file_exists( $state['csv_file'] ) ) {
                        $this->update_import_state( array(
                                'status'     => 'error',
                                'last_error' => 'CSV файл не найден: ' . $state['csv_file'],
                        ) );
                        wp_send_json_error( array( 'message' => 'CSV файл не найден' ) );
                }

                // Открываем CSV, пропускаем заголовок и уже обработанные строки.
                $parser = BSI_CSV_Parser::instance()->open( $state['csv_file'] );
                if ( is_wp_error( $parser ) ) {
                        $this->update_import_state( array(
                                'status'     => 'error',
                                'last_error' => $parser->get_error_message(),
                        ) );
                        wp_send_json_error( array( 'message' => $parser->get_error_message() ) );
                }

                // Пропускаем уже обработанные строки.
                $skip = (int) $state['last_offset'];
                $current_index = 0;
                $batch_size = (int) $state['batch_size'];
                $batch_rows = array();
                $start_time = microtime( true );

                foreach ( $parser as $idx => $row ) {
                        $current_index++;
                        if ( $current_index <= $skip ) {
                                continue;
                        }
                        $batch_rows[] = $row;
                        if ( count( $batch_rows ) >= $batch_size ) {
                                break;
                        }
                }
                $parser->close();

                if ( empty( $batch_rows ) ) {
                        // Импорт завершён.
                        $this->update_import_state( array(
                                'status'         => 'completed',
                                'processed_rows' => $state['total_rows'],
                                'last_offset'    => $state['total_rows'],
                        ) );

                        // Пометить файл как обработанный.
                        $file_to_mark = $fetch_result['zip'] ?? '';
                        if ( ! $file_to_mark ) {
                                $file_to_mark = $state['csv_file'];
                        }
                        BSI_FTP::instance()->mark_processed( $file_to_mark );

                        update_option( 'bsi_last_import_finished', current_time( 'mysql' ) );

                        $report = array(
                                'success'         => true,
                                'rows_processed'  => $state['processed_rows'],
                                'elapsed_seconds' => $state['elapsed_seconds'],
                        );
                        update_option( 'bsi_last_import_report', $report );

                        $this->log( 'info', 'Импорт завершён', $report );

                        wp_send_json_success( array(
                                'message' => __( 'Импорт завершён!', 'beestore-integration' ),
                                'state'   => $this->get_import_state(),
                                'finished' => true,
                        ) );
                }

                // Обрабатываем батч.
                $settings = get_option( 'bsi_settings', array() );
                $created = 0;
                $updated = 0;
                $errors = 0;
                $last_error = '';

                // Загружаем индекс IGUArticolo → count (построен при старте импорта).
                $upload_dir = wp_upload_dir();
                $index_file = trailingslashit( $upload_dir['basedir'] ) . 'beestore/import-index.json';
                $index = array();
                if ( file_exists( $index_file ) ) {
                        $index = json_decode( file_get_contents( $index_file ), true );
                        if ( ! is_array( $index ) ) {
                                $index = array();
                        }
                }

                // Группируем по IGUArticolo внутри батча.
                $models_in_batch = array();
                foreach ( $batch_rows as $row ) {
                        $igu = isset( $row['IGUArticolo'] ) ? $row['IGUArticolo'] : '';
                        if ( ! $igu ) {
                                continue;
                        }
                        if ( ! isset( $models_in_batch[ $igu ] ) ) {
                                $models_in_batch[ $igu ] = array(
                                        'parent'   => $row,
                                        'variants' => array(),
                                );
                        }
                        $models_in_batch[ $igu ]['variants'][] = $row;
                }

                // Импортируем каждую модель.
                $skipped_by_filter = 0;
                foreach ( $models_in_batch as $igu => $data ) {
                        try {
                                // Получаем макро-категорию, подкатегорию и бренд из строки для проверки фильтром.
                                $row = $data['parent'];

                                // Макро-категория (CLOTHING, SHOES, BAGS).
                                $category = '';
                                if ( ! empty( $row['DSRepartoWeb'] ) ) {
                                        $category = $row['DSRepartoWeb'];
                                } elseif ( ! empty( $row['DSReparto'] ) ) {
                                        $category = $row['DSReparto'];
                                }

                                // Если макро-категории нет — используем подкатегорию.
                                if ( ! $category ) {
                                        if ( ! empty( $row['DSCategoriaMerceologicaWeb'] ) ) {
                                                $category = $row['DSCategoriaMerceologicaWeb'];
                                        } elseif ( ! empty( $row['DSCategoriaMerceologica'] ) ) {
                                                $category = $row['DSCategoriaMerceologica'];
                                        }
                                }

                                $brand = '';
                                if ( ! empty( $row['DSLinea'] ) ) {
                                        $brand = $row['DSLinea'];
                                } elseif ( ! empty( $row['RaggruppamentoLinea'] ) ) {
                                        $brand = $row['RaggruppamentoLinea'];
                                }

                                // Проверяем фильтром.
                                if ( ! BSI_Import_Filters::instance()->should_import( $category, $brand ) ) {
                                        $skipped_by_filter++;
                                        continue;
                                }

                                // Определяем, многовариантный ли это товар ВО ВСЁМ ФАЙЛЕ (не в батче!).
                                $total_count = isset( $index[ $igu ] ) ? $index[ $igu ] : count( $data['variants'] );
                                $is_multi_variant = $total_count > 1;

                                // Проверяем, новый ли товар (по meta).
                                $existing_id = $this->find_product_by_meta( '_bsi_igu_articolo', $igu );
                                $this->upsert_model( $igu, $data['parent'], $data['variants'], $is_multi_variant );

                                // Увеличиваем счётчики фильтров (для лимитов).
                                BSI_Import_Filters::instance()->increment_counters( $category, $brand );

                                if ( $existing_id ) {
                                        $updated++;
                                } else {
                                        $created++;
                                }
                        } catch ( Exception $e ) {
                                $errors++;
                                $last_error = $e->getMessage();
                                $this->log( 'error', 'Ошибка импорта модели', array(
                                        'igu' => $igu,
                                        'err' => $last_error,
                                ) );
                        }
                }

                $elapsed_batch = microtime( true ) - $start_time;

                // Обновляем состояние.
                $new_offset = $current_index;
                $new_processed = $state['processed_rows'] + count( $batch_rows );
                $total_elapsed = $state['elapsed_seconds'] + $elapsed_batch;

                $this->update_import_state( array(
                        'processed_rows'  => $new_processed,
                        'last_offset'     => $new_offset,
                        'elapsed_seconds' => $total_elapsed,
                        'errors_count'    => $state['errors_count'] + $errors,
                        'last_error'      => $last_error ?: $state['last_error'],
                        'created_products' => $state['created_products'] + $created,
                        'updated_products' => $state['updated_products'] + $updated,
                ));

                $updated_state = $this->get_import_state();
                $percent = $updated_state['total_rows'] > 0
                        ? round( ( $updated_state['processed_rows'] / $updated_state['total_rows'] ) * 100, 1 )
                        : 0;

                wp_send_json_success( array(
                        'message'  => sprintf(
                                __( 'Обработано: %d / %d (%.1f%%). Создано: %d, обновлено: %d, ошибок: %d', 'beestore-integration' ),
                                $updated_state['processed_rows'],
                                $updated_state['total_rows'],
                                $percent,
                                $created,
                                $updated,
                                $errors
                        ),
                        'state'    => $updated_state,
                        'percent'  => $percent,
                        'finished' => false,
                ) );
        }

        /* ---------------------------------------------------------------------
         * AJAX: пауза импорта.
         * --------------------------------------------------------------------- */
        public function ajax_import_pause() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $state = $this->get_import_state();
                if ( 'running' !== $state['status'] ) {
                        wp_send_json_error( array( 'message' => __( 'Импорт не запущен.', 'beestore-integration' ) ) );
                }

                $this->update_import_state( array( 'status' => 'paused' ) );
                wp_send_json_success( array( 'message' => __( 'Импорт приостановлен.', 'beestore-integration' ) ) );
        }

        /* ---------------------------------------------------------------------
         * AJAX: продолжить импорт.
         * --------------------------------------------------------------------- */
        public function ajax_import_continue() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $state = $this->get_import_state();
                if ( 'paused' !== $state['status'] && 'error' !== $state['status'] ) {
                        wp_send_json_error( array( 'message' => sprintf( __( 'Нельзя продолжить (статус: %s)', 'beestore-integration' ), $state['status'] ) ) );
                }

                $this->update_import_state( array( 'status' => 'running' ) );
                wp_send_json_success( array( 'message' => __( 'Импорт продолжён.', 'beestore-integration' ) ) );
        }

        /* ---------------------------------------------------------------------
         * AJAX: остановить импорт (сброс).
         * --------------------------------------------------------------------- */
        public function ajax_import_stop() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                // Сбрасываем состояние.
                delete_option( 'bsi_import_state' );
                wp_send_json_success( array( 'message' => __( 'Импорт остановлен. Прогресс сброшен.', 'beestore-integration' ) ) );
        }

        /* ---------------------------------------------------------------------
         * AJAX: полная очистка товаров BeeStore, брендов, категорий.
         * --------------------------------------------------------------------- */
        public function ajax_purge_all() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                global $wpdb;
                $deleted_products = 0;
                $deleted_terms    = 0;

                // 1. Находим все товары BeeStore (по meta _bsi_igu_articolo).
                $product_ids = $wpdb->get_col( $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
                        '_bsi_igu_articolo'
                ) );

                if ( ! empty( $product_ids ) ) {
                        // Включаем вариации этих товаров.
                        $variation_ids = $wpdb->get_col(
                                "SELECT ID FROM {$wpdb->posts}
                                 WHERE post_type = 'product_variation'
                                 AND post_parent IN (" . implode( ',', array_map( 'intval', $product_ids ) ) . ')'
                        );
                        $all_ids = array_merge( $product_ids, $variation_ids );

                        foreach ( $all_ids as $pid ) {
                                wp_delete_post( $pid, true );
                                $deleted_products++;
                        }
                }

                // 2. Очищаем все термы BeeStore в таксономиях product_cat, pa_brand, pa_color, pa_size, pa_stagione, pa_country, pa_sesso, pa_collezione.
                $taxonomies_to_clean = array( 'product_cat', 'pa_brand', 'pa_color', 'pa_size', 'pa_stagione', 'pa_country', 'pa_sesso', 'pa_collezione' );
                if ( taxonomy_exists( 'product_brand' ) ) {
                        $taxonomies_to_clean[] = 'product_brand';
                }

                foreach ( $taxonomies_to_clean as $tax ) {
                        if ( ! taxonomy_exists( $tax ) ) {
                                continue;
                        }
                        $terms = get_terms( array(
                                'taxonomy'   => $tax,
                                'hide_empty' => false,
                                'number'     => 0,
                        ) );

                        if ( is_wp_error( $terms ) ) {
                                continue;
                        }

                        foreach ( $terms as $term ) {
                                // Удаляем только термы, которые точно созданы BeeStore.
                                // Чтобы не удалить вручную созданные категории, проверяем: удаляем только если есть товары с meta _bsi_igu_articolo, и они привязаны к этому терму.
                                // Упрощаем: удаляем все термы в pa_* таксономиях, которые создал плагин.
                                // НЕ ТРОГАЕМ Uncategorized (id=1) в product_cat.
                                if ( 'product_cat' === $tax && 'uncategorized' === $term->slug ) {
                                        continue;
                                }
                                wp_delete_term( $term->term_id, $tax );
                                $deleted_terms++;
                        }
                }

                // 3. Сбрасываем состояние импорта.
                delete_option( 'bsi_import_state' );

                $this->log( 'info', 'Полная очистка BeeStore данных', array(
                        'deleted_products' => $deleted_products,
                        'deleted_terms'    => $deleted_terms,
                ) );

                wp_send_json_success( array(
                        'message' => sprintf(
                                /* translators: 1: товаров, 2: термов */
                                __( 'Очистка завершена. Удалено товаров: %1$d, термов: %2$d. Можно запускать чистый импорт.', 'beestore-integration' ),
                                $deleted_products,
                                $deleted_terms
                        ),
                        'deleted_products' => $deleted_products,
                        'deleted_terms'    => $deleted_terms,
                ) );
        }

        /* ---------------------------------------------------------------------
         * AJAX: скачать CSV с FTP для настройки фильтров (без запуска импорта).
         * --------------------------------------------------------------------- */
        public function ajax_download_csv_for_filters() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                // Скачиваем файл с FTP (без пометки как processed).
                $fetch_result = BSI_FTP::instance()->fetch_latest_zip();
                if ( is_wp_error( $fetch_result ) ) {
                        wp_send_json_error( array( 'message' => $fetch_result->get_error_message() ) );
                }

                $csv_file = $fetch_result['csv'];
                $remote_name = basename( ltrim( $fetch_result['remote_name'], './' ) );

                // Сканируем CSV для получения списка категорий и брендов.
                $available = BSI_Import_Filters::instance()->scan_csv_for_filters( $csv_file );

                $total_cats   = count( $available['categories'] );
                $total_brands = count( $available['brands'] );

                $this->log( 'info', 'CSV скачан для настройки фильтров', array(
                        'file'       => $remote_name,
                        'categories' => $total_cats,
                        'brands'     => $total_brands,
                ) );

                wp_send_json_success( array(
                        'message'    => sprintf(
                                __( 'CSV скачан: %s. Найдено категорий: %d, брендов: %d. Теперь настройте фильтры и сохраните.', 'beestore-integration' ),
                                $remote_name,
                                $total_cats,
                                $total_brands
                        ),
                        'csv_file'   => $csv_file,
                        'remote_name' => $remote_name,
                        'categories' => $available['categories'],
                        'brands'     => $available['brands'],
                ) );
        }

        /* ---------------------------------------------------------------------
         * AJAX: батчевое сканирование CSV для фильтров.
         * --------------------------------------------------------------------- */
        public function ajax_scan_start() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                // Ищем скачанный CSV во всех папках плагина.
                $upload_dir    = wp_upload_dir();
                $beestore_dir  = trailingslashit( $upload_dir['basedir'] ) . 'beestore';
                $dirs_to_check = array( 'downloads', 'extracted', 'processed', 'manual-downloads' );

                $csvs = array();
                foreach ( $dirs_to_check as $subdir ) {
                        $path = $beestore_dir . '/' . $subdir;
                        if ( is_dir( $path ) ) {
                                $csvs = array_merge( $csvs, glob( $path . '/*.csv' ) );
                                // Также проверяем подпапки (extracted/COMPANY.../file.csv).
                                $csvs = array_merge( $csvs, glob( $path . '/*/*.csv' ) );
                        }
                }

                if ( empty( $csvs ) ) {
                        // CSV нет — скачиваем с FTP.
                        if ( function_exists( 'set_time_limit' ) ) {
                                @set_time_limit( 600 );
                        }
                        $fetch_result = BSI_FTP::instance()->fetch_latest_zip();
                        if ( is_wp_error( $fetch_result ) ) {
                                wp_send_json_error( array( 'message' => $fetch_result->get_error_message() ) );
                        }
                        $csv_file = $fetch_result['csv'];
                } else {
                        // Приоритет: файл с _0000001 в имени (полный каталог).
                        $full_catalog = array_filter( $csvs, function( $f ) {
                                return false !== strpos( basename( $f ), '_0000001.' );
                        });
                        if ( ! empty( $full_catalog ) ) {
                                usort( $full_catalog, function( $a, $b ) {
                                        return filemtime( $b ) - filemtime( $a );
                                });
                                $csv_file = $full_catalog[0];
                        } else {
                                usort( $csvs, function( $a, $b ) {
                                        return filemtime( $b ) - filemtime( $a );
                                });
                                $csv_file = $csvs[0];
                        }
                }

                BSI_Import_Filters::instance()->init_scan( $csv_file );

                wp_send_json_success( array(
                        'message' => 'Сканирование началось...',
                        'file'    => basename( $csv_file ),
                        'size'    => size_format( filesize( $csv_file ) ),
                ) );
        }

        public function ajax_scan_step() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $result = BSI_Import_Filters::instance()->scan_batch();

                if ( is_wp_error( $result ) ) {
                        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
                }

                wp_send_json_success( $result );
        }

        /* ---------------------------------------------------------------------
         * AJAX: получить текущее состояние импорта (для polling).
         * --------------------------------------------------------------------- */
        public function ajax_import_status() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $state = $this->get_import_state();
                $percent = $state['total_rows'] > 0
                        ? round( ( $state['processed_rows'] / $state['total_rows'] ) * 100, 1 )
                        : 0;

                wp_send_json_success( array(
                        'state'   => $state,
                        'percent' => $percent,
                ) );
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
         * @param array  $variant_rows Массив строк вариантов (цвет/размер) из текущего батча.
         * @param bool   $is_multi_variant true если во всём файле у этого IGUArticolo > 1 варианта.
         *                                 Передаётся из предсканирования, чтобы избежать создания
         *                                 простого товара когда варианты разбросаны по батчам.
         */
        private function upsert_model( $igu_articolo, $parent_row, $variant_rows, $is_multi_variant = null ) {
                if ( null === $is_multi_variant ) {
                        $is_multi_variant = count( $variant_rows ) > 1;
                }

                // Решение: простой или вариативный товар.
                // Если во всём файле > 1 варианта — всегда вариативный, даже если в текущем батче 1 вариант.
                // (остальные варианты придут в следующих батчах)
                $is_variable = $is_multi_variant;

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

                // Если существующий товар — ПРОСТОЙ, а должен быть ВАРИАТИВНЫМ,
                // удаляем простой товар (это миграция при повторном импорте с фиксом).
                if ( $product_id ) {
                        $existing = wc_get_product( $product_id );
                        if ( $existing && ! ( $existing instanceof WC_Product_Variable ) ) {
                                $this->log( 'info', 'Удаление простого товара для создания вариативного', array(
                                        'igu'         => $igu_articolo,
                                        'old_id'      => $product_id,
                                        'old_type'    => $existing->get_type(),
                                ) );
                                // Удаляем старый простой товар (force=true, чтобы удалить без корзины).
                                wp_delete_post( $product_id, true );
                                $product_id = 0;
                        }
                }

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

                // Атрибуты: Color + Size (через глобальные таксономии pa_color, pa_size).
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
                        // Создаём термы в pa_color и собираем их slug'и.
                        $color_slugs = array();
                        foreach ( $colors as $color_name ) {
                                $slug = $this->ensure_attribute_term( $color_name, 'pa_color' );
                                if ( $slug ) {
                                        $color_slugs[] = $slug;
                                }
                        }

                        $attr_color = new WC_Product_Attribute();
                        $attr_color->set_id( $this->get_attribute_id( 'pa_color' ) );
                        $attr_color->set_name( 'pa_color' );
                        $attr_color->set_options( $color_slugs );
                        $attr_color->set_position( 1 );
                        $attr_color->set_visible( true );
                        $attr_color->set_variation( true );
                        $attributes['pa_color'] = $attr_color;
                }
                if ( ! empty( $sizes ) ) {
                        // Создаём термы в pa_size.
                        $size_slugs = array();
                        foreach ( $sizes as $size_name ) {
                                $slug = $this->ensure_attribute_term( $size_name, 'pa_size' );
                                if ( $slug ) {
                                        $size_slugs[] = $slug;
                                }
                        }

                        $attr_size = new WC_Product_Attribute();
                        $attr_size->set_id( $this->get_attribute_id( 'pa_size' ) );
                        $attr_size->set_name( 'pa_size' );
                        $attr_size->set_options( $size_slugs );
                        $attr_size->set_position( 2 );
                        $attr_size->set_visible( true );
                        $attr_size->set_variation( true );
                        $attributes['pa_size'] = $attr_size;
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
         * @param string $slug Slug атрибута (например 'colore' или 'pa_color').
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
         * @param string $taxonomy Таксономия ('pa_color' или 'pa_size').
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
                                        $color_slug = $this->ensure_attribute_term( $row['DSColore'], 'pa_color' );
                                        if ( $color_slug ) {
                                                $default_color = $color_slug;
                                        }
                                }
                                if ( ! empty( $row['Taglia'] ) && empty( $default_size ) ) {
                                        $size_slug = $this->ensure_attribute_term( $row['Taglia'], 'pa_size' );
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
                        $default_color = $this->ensure_attribute_term( $variant_rows[0]['DSColore'], 'pa_color' );
                }
                if ( empty( $default_size ) && ! empty( $variant_rows[0]['Taglia'] ) ) {
                        $default_size = $this->ensure_attribute_term( $variant_rows[0]['Taglia'], 'pa_size' );
                }

                $default_attrs = array();
                if ( $default_color ) {
                        $default_attrs['pa_color'] = $default_color;
                }
                if ( $default_size ) {
                        $default_attrs['pa_size'] = $default_size;
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
                        $color_slug = $this->ensure_attribute_term( $row['DSColore'], 'pa_color' );
                        if ( $color_slug ) {
                                $attrs['pa_color'] = $color_slug;
                        }
                }

                if ( ! empty( $row['Taglia'] ) ) {
                        $size_slug = $this->ensure_attribute_term( $row['Taglia'], 'pa_size' );
                        if ( $size_slug ) {
                                $attrs['pa_size'] = $size_slug;
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
                // === МАКРО-КАТЕГОРИЯ (DSRepartoWeb — CLOTHING, SHOES, BAGS) ===
                $macro_cat = '';
                if ( ! empty( $row['DSRepartoWeb'] ) ) {
                        $macro_cat = $row['DSRepartoWeb'];
                } elseif ( ! empty( $row['DSReparto'] ) ) {
                        $macro_cat = $row['DSReparto'];
                }
                // Перевод макро-категории.
                if ( $macro_cat ) {
                        $translated = BSI_Translations::instance()->get_translation( 'product_cat', $macro_cat );
                        if ( $translated ) {
                                $macro_cat = $translated;
                        }
                }

                // === ПОДКАТЕГОРИЯ (DSCategoriaMerceologicaWeb — JEANS, SNEAKERS, HANDBAGS) ===
                $sub_cat = '';
                if ( ! empty( $row['DSCategoriaMerceologicaWeb'] ) ) {
                        $sub_cat = $row['DSCategoriaMerceologicaWeb'];
                } elseif ( ! empty( $row['DSCategoriaMerceologica'] ) ) {
                        $sub_cat = $row['DSCategoriaMerceologica'];
                }
                // Перевод подкатегории.
                if ( $sub_cat ) {
                        $translated_sub = BSI_Translations::instance()->get_translation( 'product_cat', $sub_cat );
                        if ( $translated_sub ) {
                                $sub_cat = $translated_sub;
                        }
                }

                // Создаём вложенную структуру: макро-категория (родитель) → подкатегория (ребёнок).
                $cat_ids = array();
                $macro_id = 0;
                if ( $macro_cat ) {
                        $macro_id = $this->ensure_term( $macro_cat, 'product_cat' );
                        $cat_ids[] = $macro_id;
                }
                if ( $sub_cat ) {
                        // Создаём подкатегорию с макро-категорией как родителем.
                        $sub_id = $this->ensure_term( $sub_cat, 'product_cat', $macro_cat );
                        $cat_ids[] = $sub_id;
                }
                if ( ! empty( $cat_ids ) ) {
                        wp_set_post_terms( $product_id, array_filter( $cat_ids ), 'product_cat' );
                }

                // Бренд — DSLinea (VERSACE, BENEDETTA BRUZZICHES, LEVI'S).
                $brand_name = '';
                if ( ! empty( $row['DSLinea'] ) ) {
                        $brand_name = $row['DSLinea'];
                } elseif ( ! empty( $row['RaggruppamentoLinea'] ) ) {
                        $brand_name = $row['RaggruppamentoLinea'];
                }
                if ( $brand_name ) {
                        $brand_tax = taxonomy_exists( 'product_brand' ) ? 'product_brand' : 'pa_brand';
                        if ( taxonomy_exists( $brand_tax ) ) {
                                $brand_id = $this->ensure_term( $brand_name, $brand_tax );
                                wp_set_post_terms( $product_id, array( $brand_id ), $brand_tax );
                        }
                }

                // Сезон.
                $season_name = '';
                if ( ! empty( $row['DSStagioneWeb'] ) ) {
                        $season_name = $row['DSStagioneWeb'];
                } elseif ( ! empty( $row['DSStagione'] ) ) {
                        $season_name = $row['DSStagione'];
                }
                if ( $season_name && taxonomy_exists( 'pa_stagione' ) ) {
                        $season_id = $this->ensure_term( $season_name, 'pa_stagione' );
                        wp_set_post_terms( $product_id, array( $season_id ), 'pa_stagione' );
                }

                // Страна производства.
                if ( ! empty( $row['DSMarca'] ) && taxonomy_exists( 'pa_country' ) ) {
                        $country_id = $this->ensure_term( $row['DSMarca'], 'pa_country' );
                        wp_set_post_terms( $product_id, array( $country_id ), 'pa_country' );
                }

                // Пол.
                $gender_name = '';
                if ( ! empty( $row['DSSessoWeb'] ) ) {
                        $gender_name = $row['DSSessoWeb'];
                } elseif ( ! empty( $row['DSSesso'] ) ) {
                        $gender_name = $row['DSSesso'];
                }
                if ( $gender_name ) {
                        $translated_gender = BSI_Translations::instance()->get_translation( 'pa_sesso', $gender_name );
                        if ( $translated_gender ) {
                                $gender_name = $translated_gender;
                        }
                }
                if ( $gender_name && taxonomy_exists( 'pa_sesso' ) ) {
                        $gender_id = $this->ensure_term( $gender_name, 'pa_sesso' );
                        wp_set_post_terms( $product_id, array( $gender_id ), 'pa_sesso' );
                }

                // Тип коллекции.
                if ( ! empty( $row['DSCampionario'] ) && taxonomy_exists( 'pa_collezione' ) ) {
                        $coll_id = $this->ensure_term( $row['DSCampionario'], 'pa_collezione' );
                        wp_set_post_terms( $product_id, array( $coll_id ), 'pa_collezione' );
                }
        }

        /**
         * Создать/получить term.
         *
         * ВАЖНО: При обновлении существующего терма НЕ ТРОГАЕМ slug и thumbnail_id.
         * Это позволяет админу загружать картинки для категорий — плагин их не перезапишет.
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
         * Переиспользование: ищет существующую картинку по basename без расширения.
         * Например: URL "http://...2000019668213_1.jpg" → ищем "2000019668213_1"
         * Если уже скачана (как .webp или .jpg) — переиспользуем, не качаем заново.
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

                // Извлекаем basename без расширения для поиска.
                $basename = basename( parse_url( $url, PHP_URL_PATH ) );
                $filename_without_ext = pathinfo( $basename, PATHINFO_FILENAME );

                // 1. Сначала ищем по meta _bsi_image_url (точное совпадение URL).
                $existing = $this->find_attachment_by_meta( '_bsi_image_url', $url );
                if ( $existing ) {
                        return $existing;
                }

                // 2. Ищем по _bsi_image_basename (без расширения) — переиспользование.
                $existing_by_name = $this->find_attachment_by_basename( $filename_without_ext );
                if ( $existing_by_name ) {
                        // Сохраняем URL в meta для будущего точного поиска.
                        update_post_meta( $existing_by_name, '_bsi_image_url', $url );
                        return $existing_by_name;
                }

                if ( ! $download ) {
                        return 0;
                }

                // Скачиваем.
                $attach_id = media_sideload_image( $url, $product_id, null, 'id' );
                if ( is_wp_error( $attach_id ) ) {
                        $this->log( 'warning', 'Не удалось скачать картинку', array( 'url' => $url, 'err' => $attach_id->get_error_message() ) );
                        return false;
                }

                // Сохраняем meta.
                update_post_meta( $attach_id, '_bsi_image_url', $url );
                update_post_meta( $attach_id, '_bsi_image_basename', $filename_without_ext );

                // Конвертация в WebP (если включена).
                $settings = get_option( 'bsi_settings', array() );
                $webp_enabled = isset( $settings['webp_enabled'] ) && '1' === $settings['webp_enabled'];

                if ( $webp_enabled ) {
                        $this->convert_attachment_to_webp( $attach_id );
                }

                return $attach_id;
        }

        /**
         * Найти attachment по basename (без расширения).
         * Ищет в _bsi_image_basename meta.
         *
         * @param string $basename Filename без расширения (например "2000019668213_1")
         * @return int|false
         */
        private function find_attachment_by_basename( $basename ) {
                if ( empty( $basename ) ) {
                        return false;
                }
                global $wpdb;
                $found = $wpdb->get_var( $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta}
                         WHERE meta_key = '_bsi_image_basename' AND meta_value = %s
                         LIMIT 1",
                        $basename
                ) );
                return $found ? (int) $found : false;
        }

        /**
         * Конвертировать attachment в WebP.
         *
         * После конвертации:
         *   - В Media Library остаётся только WebP
         *   - Оригинальный JPG/PNG удаляется
         *   - MIME-тип attachment меняется на image/webp
         *
         * @param int $attachment_id
         * @return bool true — успешно, false — пропущено или ошибка.
         */
        private function convert_attachment_to_webp( $attachment_id ) {
                $file = get_attached_file( $attachment_id );
                if ( ! $file || ! file_exists( $file ) ) {
                        return false;
                }

                $mime = get_post_mime_type( $attachment_id );
                if ( 'image/webp' === $mime ) {
                        return true; // Уже WebP.
                }

                if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
                        return false; // Не JPEG/PNG.
                }

                $settings = get_option( 'bsi_settings', array() );
                $strategy = isset( $settings['webp_strategy'] ) ? (int) $settings['webp_strategy'] : 3;

                $result = BSI_WebP::instance()->convert( $file, null, $strategy );

                if ( is_wp_error( $result ) ) {
                        $this->log( 'warning', 'WebP конвертация не удалась', array(
                                'file'  => basename( $file ),
                                'error' => $result->get_error_message(),
                        ) );
                        return false;
                }

                // Удаляем оригинальный файл.
                wp_delete_file( $file );

                // Обновляем путь attachment на WebP.
                update_attached_file( $attachment_id, $result['path'] );

                // Меняем MIME-тип на image/webp.
                wp_update_post( array(
                        'ID'             => $attachment_id,
                        'post_mime_type' => 'image/webp',
                ) );

                // Перегенерируем метаданные (миниатюры и т.д.).
                require_once ABSPATH . 'wp-admin/includes/image.php';
                $new_meta = wp_generate_attachment_metadata( $attachment_id, $result['path'] );
                if ( is_array( $new_meta ) ) {
                        wp_update_attachment_metadata( $attachment_id, $new_meta );
                }

                clean_attachment_cache( $attachment_id );

                $this->log( 'info', 'Картинка конвертирована в WebP', array(
                        'file'          => basename( $result['path'] ),
                        'original_size' => size_format( $result['original_size'] ),
                        'webp_size'     => size_format( $result['filesize'] ),
                        'saved_percent' => $result['saved_percent'] . '%',
                ) );

                return true;
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
                // Ищем ТОЛЬКО среди товаров (post_type=product), НЕ среди вариаций!
                // Иначе найдёт вариацию вместо родителя и создаст дубликат.
                global $wpdb;
                $found = $wpdb->get_var( $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} pm
                         JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = %s AND pm.meta_value = %s
                         AND p.post_type = 'product'
                         LIMIT 1",
                        $key,
                        $value
                ) );
                return $found ? (int) $found : 0;
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
