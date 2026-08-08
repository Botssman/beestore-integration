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

                // AJAX: удалить только картинки, импортированные плагином.
                add_action( 'wp_ajax_bsi_purge_images', array( $this, 'ajax_purge_images' ) );

                // AJAX: остановить фоновый cron-импорт (сброс lock).
                add_action( 'wp_ajax_bsi_stop_cron_import', array( $this, 'ajax_stop_cron_import' ) );

                // AJAX: получить статус фонового импорта (для индикатора).
                add_action( 'wp_ajax_bsi_cron_import_status', array( $this, 'ajax_cron_import_status' ) );

                // AJAX: скачать CSV с FTP для настройки фильтров (без запуска импорта).
                add_action( 'wp_ajax_bsi_download_csv_for_filters', array( $this, 'ajax_download_csv_for_filters' ) );

                // AJAX: батчевое сканирование CSV для фильтров.
                add_action( 'wp_ajax_bsi_scan_start', array( $this, 'ajax_scan_start' ) );
                add_action( 'wp_ajax_bsi_scan_step', array( $this, 'ajax_scan_step' ) );

                // AJAX: пересчёт цен всех товаров по текущей формуле (для кнопки на странице Конвертации).
                add_action( 'wp_ajax_bsi_recalculate_prices', array( $this, 'ajax_recalculate_prices' ) );

                // AJAX: импорт картинок (отдельный процесс с прогрессом/паузой/стопом).
                add_action( 'wp_ajax_bsi_backfill_pause', array( $this, 'ajax_backfill_pause' ) );
                add_action( 'wp_ajax_bsi_backfill_resume', array( $this, 'ajax_backfill_resume' ) );
                add_action( 'wp_ajax_bsi_backfill_stop', array( $this, 'ajax_backfill_stop' ) );
                add_action( 'wp_ajax_bsi_backfill_status', array( $this, 'ajax_backfill_status' ) );
        }

        /**
         * AJAX: пересчёт цен всех импортированных товаров.
         * Принимает offset для пагинации, возвращает статистику и has_more.
         */
        public function ajax_recalculate_prices() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
                $batch  = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 100;

                // Ограничиваем батч — не больше 500 за раз (защита от timeout).
                if ( $batch > 500 ) {
                        $batch = 500;
                }
                if ( $batch < 10 ) {
                        $batch = 10;
                }

                $result = $this->recalculate_all_prices( $offset, $batch );

                BSI_Logger::instance()->info( 'pricing', 'Пересчёт цен: батч обработан', $result );

                wp_send_json_success( $result );
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

                // Проверяем lock — если cron или другой процесс уже импортирует,
                // AJAX не запускает параллельный.
                $lock = get_transient( 'bsi_import_lock' );
                if ( false !== $lock ) {
                        $lock_age = time() - (int) $lock;
                        $lock_pid = (int) get_transient( 'bsi_import_lock_pid' );
                        $current_pid = function_exists( 'getmypid' ) ? getmypid() : 0;
                        // Если lock от другого PID и свежий — выходим.
                        if ( $lock_pid !== $current_pid && $lock_age < 1800 ) {
                                wp_send_json_error( array(
                                        'message' => sprintf(
                                            /* translators: 1: секунды, 2: PID процесса */
                                            __( 'Импорт уже идёт в другом процессе (%1$d сек, PID %2$d). Подождите или сбросьте lock.', 'beestore-integration' ),
                                            $lock_age,
                                            $lock_pid
                                        ),
                                ) );
                        }
                }

                if ( empty( $state['csv_file'] ) || ! file_exists( $state['csv_file'] ) ) {
                        $this->update_import_state( array(
                                'status'     => 'error',
                                'last_error' => 'CSV файл не найден: ' . $state['csv_file'],
                        ) );
                        wp_send_json_error( array( 'message' => 'CSV файл не найден' ) );
                }

                // Открываем CSV напрямую через fopen для fseek (мгновенный переход).
                // Раньше использовали BSI_CSV_Parser который читает файл с НАЧАЛА
                // и пропускает строки по счётчику → O(n²) → прогрессивное замедление.
                $handle = fopen( $state['csv_file'], 'rb' );
                if ( ! $handle ) {
                        $this->update_import_state( array(
                                'status'     => 'error',
                                'last_error' => 'Не удалось открыть CSV: ' . $state['csv_file'],
                        ) );
                        wp_send_json_error( array( 'message' => 'Не удалось открыть CSV' ) );
                }

                // Пропускаем BOM.
                $bom = fread( $handle, 3 );
                if ( "\xEF\xBB\xBF" !== $bom ) {
                        fseek( $handle, 0 );
                }

                // Читаем заголовок (первая строка).
                $headers = fgetcsv( $handle, 0, ',', '"' );
                if ( ! $headers ) {
                        fclose( $handle );
                        wp_send_json_error( array( 'message' => 'Не удалось прочитать заголовок CSV' ) );
                }
                $headers = array_map( 'trim', $headers );

                // Если есть сохранённая позиция в файле — мгновенный переход.
                $file_pos = isset( $state['file_position'] ) ? (int) $state['file_position'] : 0;
                if ( $file_pos > 0 ) {
                        fseek( $handle, $file_pos );
                }

                $batch_size = (int) $state['batch_size'];
                $batch_rows = array();
                $start_time = microtime( true );

                // Читаем батч напрямую — без пропуска строк!
                while ( ! feof( $handle ) ) {
                        $raw_row = fgetcsv( $handle, 0, ',', '"' );
                        if ( false === $raw_row || null === $raw_row ) {
                                break;
                        }
                        if ( count( $raw_row ) < count( $headers ) ) {
                                $raw_row = array_pad( $raw_row, count( $headers ), '' );
                        }
                        if ( count( $raw_row ) > count( $headers ) ) {
                                $raw_row = array_slice( $raw_row, 0, count( $headers ) );
                        }
                        $batch_rows[] = array_combine( $headers, array_map( 'trim', $raw_row ) );
                        if ( count( $batch_rows ) >= $batch_size ) {
                                break;
                        }
                }

                // Сохраняем позицию файла для следующего батча.
                $new_file_pos = ftell( $handle );
                fclose( $handle );

                if ( empty( $batch_rows ) ) {
                        // ─── ЗАЩИТНАЯ ПРОВЕРКА ─────────────────────────────────
                        // batch_rows пустой, но мы НЕ обработали все строки?
                        // Это значит file_position повредилась — fseek прыгнул в конец.
                        // НЕ завершаем импорт, а сбрасываем file_position и продолжаем.
                        if ( $state['processed_rows'] < $state['total_rows'] ) {
                                $this->log( 'error', 'batch_rows пустой но обработано не всё — сброс file_position', array(
                                        'processed'      => $state['processed_rows'],
                                        'total'          => $state['total_rows'],
                                        'file_position'  => $file_pos,
                                        'new_file_pos'   => $new_file_pos,
                                ) );
                                $this->update_import_state( array(
                                        'file_position' => 0,
                                ) );
                                wp_send_json_error( array(
                                        'message' => sprintf(
                                            /* translators: 1: processed, 2: total */
                                            __( 'Сбой позиции файла (обработано %1$d из %2$d). Перезапуск с начала файла...', 'beestore-integration' ),
                                            $state['processed_rows'],
                                            $state['total_rows']
                                        ),
                                ) );
                        }

                        // Действительно конец файла — импорт завершён.
                        $this->update_import_state( array(
                                'status'         => 'completed',
                                'processed_rows' => $state['total_rows'],
                                'last_offset'    => $state['total_rows'],
                                'file_position'  => 0,
                        ) );

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
                $batch_created  = 0;
                $batch_updated  = 0;
                $batch_errors   = 0;
                $batch_skipped  = 0;
                $batch_last_err = '';
                foreach ( $models_in_batch as $igu => $data ) {
                        try {
                                $row = $data['parent'];
                                $category = '';
                                if ( ! empty( $row['DSRepartoWeb'] ) ) {
                                        $category = $row['DSRepartoWeb'];
                                } elseif ( ! empty( $row['DSReparto'] ) ) {
                                        $category = $row['DSReparto'];
                                }
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
                                if ( ! BSI_Import_Filters::instance()->should_import( $category, $brand ) ) {
                                        $skipped_by_filter++;
                                        continue;
                                }
                                $total_count = isset( $index[ $igu ] ) ? $index[ $igu ] : count( $data['variants'] );
                                $is_multi_variant = $total_count > 1;
                                $existing_id = $this->find_product_by_meta( '_bsi_igu_articolo', $igu );

                                // ─── Пропуск неизменённых товаров ──────────────────────
                                if ( $existing_id && $this->product_unchanged( $existing_id, $data['variants'] ) ) {
                                        $batch_skipped++;
                                        BSI_Import_Filters::instance()->increment_counters( $category, $brand );
                                        continue;
                                }

                                $this->upsert_model( $igu, $data['parent'], $data['variants'], $is_multi_variant );
                                BSI_Import_Filters::instance()->increment_counters( $category, $brand );
                                if ( $existing_id ) {
                                        $batch_updated++;
                                } else {
                                        $batch_created++;
                                }
                        } catch ( Exception $e ) {
                                $batch_errors++;
                                $batch_last_err = $e->getMessage();
                                $this->log( 'error', 'Ошибка импорта модели', array(
                                        'igu' => $igu,
                                        'err' => $batch_last_err,
                                ) );
                        }
                }

                $elapsed_batch = microtime( true ) - $start_time;

                // Записываем в DB: текущие значения + результаты этого батча.
                // Читаем заново из DB — вдруг другой процесс (cron) тоже обновлял.
                $db_state = $this->get_import_state();
                $this->update_import_state( array(
                        'processed_rows'   => $db_state['processed_rows'] + count( $batch_rows ),
                        'last_offset'      => $db_state['last_offset'] + count( $batch_rows ),
                        'file_position'    => $new_file_pos,
                        'elapsed_seconds'  => $db_state['elapsed_seconds'] + $elapsed_batch,
                        'errors_count'     => $db_state['errors_count'] + $batch_errors,
                        'last_error'       => $batch_last_err ?: $db_state['last_error'],
                        'created_products' => $db_state['created_products'] + $batch_created,
                        'updated_products' => $db_state['updated_products'] + $batch_updated,
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

                // Сбрасываем lock импорта.
                delete_transient( 'bsi_import_lock' );
                delete_transient( 'bsi_import_lock_pid' );

                // Помечаем cron как «нужно пропустить следующую итерацию»
                // (на случай если cron уже запущен — он проверит этот флаг).
                set_transient( 'bsi_import_stop_requested', time(), 600 );

                wp_send_json_success( array(
                        'message' => __( 'Импорт остановлен. Прогресс сброшен. Lock снят. Cron продолжит работу по расписанию.', 'beestore-integration' ),
                ) );
        }

        /* ---------------------------------------------------------------------
         * AJAX: остановить фоновый cron-импорт (не сбрасывая прогресс AJAX).
         * Сбрасывает только lock — при следующем срабатывании cron
         * импорт запустится автоматически.
         * --------------------------------------------------------------------- */
        public function ajax_stop_cron_import() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                // Сбрасываем lock импорта — текущий фоновый процесс продолжит
                // работать до конца батча, но потом остановится.
                delete_transient( 'bsi_import_lock' );
                delete_transient( 'bsi_import_lock_pid' );

                // Помечаем — следующий cron должен пропустить (10 минут пауза).
                set_transient( 'bsi_import_stop_requested', time(), 600 );

                // Когда следующий раз запланирован cron.
                $next_cron = wp_next_scheduled( 'bsi_cron_import_catalog' );
                $next_cron_str = $next_cron
                        ? date_i18n( 'd.m.Y H:i', $next_cron + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) )
                        : __( 'не запланирован', 'beestore-integration' );

                wp_send_json_success( array(
                        'message'    => __( 'Фоновый импорт остановлен. Cron возобновит работу по расписанию.', 'beestore-integration' ),
                        'next_cron'  => $next_cron_str,
                ) );
        }

        /* ---------------------------------------------------------------------
         * AJAX: получить статус фонового импорта (для индикатора).
         * --------------------------------------------------------------------- */
        public function ajax_cron_import_status() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $lock      = get_transient( 'bsi_import_lock' );
                $lock_pid  = get_transient( 'bsi_import_lock_pid' );
                $stop_flag = get_transient( 'bsi_import_stop_requested' );

                $next_cron = wp_next_scheduled( 'bsi_cron_import_catalog' );
                $last_import = get_option( 'bsi_last_import_finished', '' );
                $last_zip = get_option( 'bsi_last_import_zip', '' );

                $is_running = false;
                $lock_age   = 0;

                if ( false !== $lock ) {
                        $lock_age = time() - (int) $lock;
                        if ( $lock_age < 1800 ) {
                                $is_running = true;
                        }
                }

                wp_send_json_success( array(
                        'is_running'   => $is_running,
                        'lock_age'     => $lock_age,
                        'lock_pid'     => $lock_pid ? (int) $lock_pid : 0,
                        'stop_pending' => false !== $stop_flag,
                        'next_cron'    => $next_cron
                                ? date_i18n( 'd.m.Y H:i', $next_cron + ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) )
                                : '',
                        'next_cron_in' => $next_cron
                                ? human_time_diff( $next_cron, current_time( 'timestamp' ) )
                                : '',
                        'last_import'  => $last_import,
                        'last_zip'     => $last_zip,
                ) );
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
         * AJAX: удалить только картинки (attachments) импортированные плагином.
         * --------------------------------------------------------------------- */
        public function ajax_purge_images() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                global $wpdb;

                // 1. Ищем attachments по meta _bsi_imported_by = 'beestore-integration'.
                $ids = $wpdb->get_col( $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta}
                         WHERE meta_key = %s AND meta_value = %s",
                        '_bsi_imported_by',
                        'beestore-integration'
                ) );

                // 2. Также ищем по _bsi_image_basename (для картинок импортированных старыми версиями).
                $ids_legacy = $wpdb->get_col(
                        "SELECT post_id FROM {$wpdb->postmeta}
                         WHERE meta_key = '_bsi_image_basename'"
                );

                $all_ids = array_unique( array_merge( $ids, $ids_legacy ) );

                $deleted = 0;
                $failed  = 0;
                foreach ( $all_ids as $attach_id ) {
                        $attach_id = (int) $attach_id;
                        if ( wp_delete_attachment( $attach_id, true ) ) {
                                $deleted++;
                        } else {
                                $failed++;
                        }
                }

                $this->log( 'info', 'Очистка картинок BeeStore', array(
                        'deleted'     => $deleted,
                        'failed'      => $failed,
                        'total_found' => count( $all_ids ),
                ) );

                wp_send_json_success( array(
                        'message'     => sprintf(
                                /* translators: 1: deleted count */
                                _n( 'Удалено картинок: %d', 'Удалено картинок: %d', $deleted, 'beestore-integration' ),
                                $deleted
                        ),
                        'deleted'     => $deleted,
                        'failed'      => $failed,
                        'total_found' => count( $all_ids ),
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
                // Если импорт каталога отключён в настройках — выходим.
                $settings = get_option( 'bsi_settings', array() );
                $freq = isset( $settings['sync_frequency'] ) ? $settings['sync_frequency'] : 'hourly';
                if ( 'disabled' === $freq ) {
                        return;
                }

                // Проверяем lock — если идёт другой импорт (например ручной AJAX),
                // cron не запускает параллельный.
                $lock = get_transient( 'bsi_import_lock' );
                if ( false !== $lock ) {
                        $lock_age = time() - (int) $lock;
                        if ( $lock_age < 1800 ) {
                                $this->log( 'info', 'Cron: импорт уже идёт в другом процессе — пропускаем', array(
                                        'lock_age_seconds' => $lock_age,
                                ) );
                                return;
                        }
                        // Старый зависший lock — сбрасываем.
                        $this->log( 'warning', 'Cron: обнаружен зависший lock импорта — сбрасываем', array( 'lock_age' => $lock_age ) );
                        delete_transient( 'bsi_import_lock' );
                        delete_transient( 'bsi_import_lock_pid' );
                }

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
                // ─── ЗАЩИТА ОТ ПАРАЛЛЕЛЬНОГО ИМПОРТА ────────────────────────────────
                // Если импорт уже идёт (другой процесс cron или AJAX) — выходим.
                // Без этого два процесса могут одновременно создавать дубли товаров.
                $lock = get_transient( 'bsi_import_lock' );
                if ( false !== $lock ) {
                        $lock_age = time() - (int) $lock;
                        // Если лок больше 30 минут — считаем зависшим, сбрасываем.
                        if ( $lock_age < 1800 ) {
                                $this->log( 'warning', 'Импорт уже идёт в другом процессе — пропускаем', array(
                                        'lock_age_seconds' => $lock_age,
                                        'csv'              => basename( $csv_file ),
                                ) );
                                return array(
                                        'success' => false,
                                        'error'   => 'import_in_progress',
                                        'message' => sprintf(
                                            /* translators: %d — секунды */
                                            __( 'Импорт уже идёт в другом процессе (%d сек назад начат). Пропускаем.', 'beestore-integration' ),
                                            $lock_age
                                        ),
                                );
                        }
                        $this->log( 'warning', 'Старый lock импорта обнаружен — сбрасываем', array( 'lock_age' => $lock_age ) );
                }
                set_transient( 'bsi_import_lock', time(), 1800 ); // 30 минут максимум.

                // Сохраняем PID для диагностики.
                $lock_pid = function_exists( 'getmypid' ) ? getmypid() : 0;
                set_transient( 'bsi_import_lock_pid', $lock_pid, 1800 );

                // Регистрируем shutdown-функцию чтобы гарантированно освободить lock
                // даже при fatal error или timeout.
                register_shutdown_function( function () {
                        delete_transient( 'bsi_import_lock' );
                        delete_transient( 'bsi_import_lock_pid' );
                } );
                // ────────────────────────────────────────────────────────────────────

                $start_time = microtime( true );
                $parser     = BSI_CSV_Parser::instance()->open( $csv_file );
                if ( is_wp_error( $parser ) ) {
                        $this->log( 'error', 'Не удалось открыть CSV', array( 'file' => $csv_file, 'err' => $parser->get_error_message() ) );
                        delete_transient( 'bsi_import_lock' );
                        delete_transient( 'bsi_import_lock_pid' );
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
                $skipped_by_filter = 0;

                // Сброс счётчиков фильтров (для лимитов) в начале импорта.
                BSI_Import_Filters::instance()->reset_counters();

                foreach ( $parser as $idx => $row ) {
                        $processed_count++;
                        if ( empty( $row['IGUArticolo'] ) ) {
                                continue;
                        }

                        // ─── Проверка фильтром ─────────────────────────────────────────
                        // Извлекаем категорию и бренд по той же логике, что и в apply_categories.
                        $category = '';
                        if ( ! empty( $row['DSRepartoWeb'] ) ) {
                                $category = $row['DSRepartoWeb'];
                        } elseif ( ! empty( $row['DSReparto'] ) ) {
                                $category = $row['DSReparto'];
                        }
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

                        // Проверяем фильтром — если не проходит, пропускаем строку.
                        if ( ! BSI_Import_Filters::instance()->should_import( $category, $brand ) ) {
                                $skipped_by_filter++;
                                continue;
                        }
                        // ──────────────────────────────────────────────────────────────

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

                // Логируем сколько отфильтровано.
                if ( $skipped_by_filter > 0 ) {
                        $this->log( 'info', 'Строк пропущено фильтром', array(
                                'skipped' => $skipped_by_filter,
                                'total'   => $processed_count,
                        ) );
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

                // Снимаем lock импорта.
                delete_transient( 'bsi_import_lock' );
                delete_transient( 'bsi_import_lock_pid' );

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

                // Если по meta не нашли — пробуем найти по SKU (CodArticolo).
                // Это помогает для товаров, импортированных старыми версиями плагина
                // без meta _bsi_igu_articolo.
                if ( ! $product_id && ! empty( $row['CodArticolo'] ) ) {
                        $found_by_sku = wc_get_product_id_by_sku( $row['CodArticolo'] );
                        if ( $found_by_sku ) {
                                $found_product = wc_get_product( $found_by_sku );
                                // Убеждаемся, что это простой товар (не вариация).
                                if ( $found_product instanceof WC_Product_Simple ) {
                                        $product_id = $found_by_sku;
                                        $this->log( 'info', 'Простой товар найден по SKU (а не по meta)', array(
                                                'sku'         => $row['CodArticolo'],
                                                'product_id'  => $product_id,
                                                'igu'         => $igu_articolo,
                                        ) );
                                }
                        }
                }

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
                                // Конфликт SKU — уже есть в другом товаре.
                                $existing_id = wc_get_product_id_by_sku( $sku );
                                if ( $existing_id && (int) $existing_id !== (int) $product->get_id() ) {
                                        $existing_product = wc_get_product( $existing_id );
                                        if ( $existing_product ) {
                                                $existing_product->set_sku( '' );
                                                $existing_product->save();
                                                $this->log( 'warning', 'Освобождён SKU у старого товара (simple)', array(
                                                        'sku'             => $sku,
                                                        'old_product_id'  => $existing_id,
                                                        'new_product_id'  => $product->get_id(),
                                                        'igu'             => isset( $row['IGUArticolo'] ) ? $row['IGUArticolo'] : '',
                                                        'modello'         => isset( $row['Modello'] ) ? $row['Modello'] : '',
                                                ) );
                                                try {
                                                        $product->set_sku( $sku );
                                                } catch ( Exception $e2 ) {
                                                        $this->log( 'warning', 'Конфликт SKU (повтор)', array(
                                                                'sku'      => $sku,
                                                                'err'      => $e2->getMessage(),
                                                                'igu'      => isset( $row['IGUArticolo'] ) ? $row['IGUArticolo'] : '',
                                                                'modello'  => isset( $row['Modello'] ) ? $row['Modello'] : '',
                                                        ) );
                                                }
                                        }
                                } else {
                                        $this->log( 'warning', 'Конфликт SKU', array(
                                                'sku'      => $sku,
                                                'err'      => $e->getMessage(),
                                                'igu'      => isset( $row['IGUArticolo'] ) ? $row['IGUArticolo'] : '',
                                                'modello'  => isset( $row['Modello'] ) ? $row['Modello'] : '',
                                        ) );
                                }
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
         * Проверить, изменился ли товар с прошлого импорта.
         * Сравниваем: цену, скидку, остаток, название для каждой вариации.
         * Если всё совпадает — пропускаем (return true = skip).
         * --------------------------------------------------------------------- */
        private function product_unchanged( $product_id, $variant_rows ) {
                if ( ! $product_id ) {
                        return false; // Новый товар — не пропускаем.
                }

                foreach ( $variant_rows as $row ) {
                        $cod_articolo = isset( $row['CodArticolo'] ) ? $row['CodArticolo'] : '';
                        if ( ! $cod_articolo ) {
                                return false;
                        }

                        // Находим вариацию по SKU.
                        global $wpdb;
                        $var_id = $wpdb->get_var( $wpdb->prepare(
                                "SELECT p.ID FROM {$wpdb->posts} p
                                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                                   AND pm.meta_key = '_sku' AND pm.meta_value = %s
                                 WHERE p.post_type = 'product_variation'
                                   AND p.post_parent = %d
                                   AND p.post_status != 'trash'
                                 LIMIT 1",
                                $cod_articolo,
                                $product_id
                        ) );

                        if ( ! $var_id ) {
                                return false; // Вариация не найдена — нужно создать.
                        }

                        $variation = wc_get_product( $var_id );
                        if ( ! $variation ) {
                                return false;
                        }

                        // Сравниваем цену.
                        $csv_price = $this->convert_price( isset( $row['PrezzoIvato'] ) ? (float) $row['PrezzoIvato'] : 0 );
                        $wc_price  = (float) $variation->get_regular_price();
                        if ( abs( $csv_price - $wc_price ) > 0.01 ) {
                                return false; // Цена изменилась.
                        }

                        // Сравниваем остаток.
                        $csv_stock = isset( $row['Disponibilita'] ) ? (float) $row['Disponibilita'] : 0;
                        $wc_stock  = (float) $variation->get_stock_quantity();
                        if ( $csv_stock !== $wc_stock ) {
                                return false; // Остаток изменился.
                        }
                }

                // Все вариации совпадают — товар не изменился.
                return true;
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

                // ВАЖНО: полностью регистрируем атрибуты WooCommerce.
                // Без этого data-taxonomy="" → пустой <select> на странице товара.
                $this->ensure_wc_attribute( 'color', __( 'Color', 'beestore-integration' ) );
                $this->ensure_wc_attribute( 'size', __( 'Size', 'beestore-integration' ) );

                $color_attr_id = $this->get_attribute_id( 'pa_color' );
                $size_attr_id  = $this->get_attribute_id( 'pa_size' );

                if ( ! empty( $colors ) ) {
                        // Создаём термы и собираем их TERM IDs (не slug'и!).
                        // WooCommerce для таксономий ждёт IDs в set_options().
                        $color_term_ids = array();
                        foreach ( $colors as $color_name ) {
                                $term_id = $this->ensure_attribute_term_id( $color_name, 'pa_color' );
                                if ( $term_id ) {
                                        $color_term_ids[] = $term_id;
                                }
                        }

                        $attr_color = new WC_Product_Attribute();
                        $attr_color->set_id( $color_attr_id );
                        $attr_color->set_name( 'pa_color' );
                        $attr_color->set_options( $color_term_ids );
                        $attr_color->set_position( 1 );
                        $attr_color->set_visible( true );
                        $attr_color->set_variation( true );
                        $attributes['pa_color'] = $attr_color;
                }
                if ( ! empty( $sizes ) ) {
                        $size_term_ids = array();
                        foreach ( $sizes as $size_name ) {
                                $term_id = $this->ensure_attribute_term_id( $size_name, 'pa_size' );
                                if ( $term_id ) {
                                        $size_term_ids[] = $term_id;
                                }
                        }

                        $attr_size = new WC_Product_Attribute();
                        $attr_size->set_id( $size_attr_id );
                        $attr_size->set_name( 'pa_size' );
                        $attr_size->set_options( $size_term_ids );
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

                // Принудительная синхронизация — без этого вариации не появятся
                // на странице товара до ручного "Обновить" в админке.
                $product_obj = wc_get_product( $product_id );
                if ( $product_obj && $product_obj instanceof WC_Product_Variable ) {
                        // Синхронизируем цены и наличие.
                        WC_Product_Variable::sync_stock_status( $product_id );
                        // Перестраиваем атрибуты вариаций.
                        $children = $product_obj->get_children();
                        foreach ( $children as $child_id ) {
                                $variation = wc_get_product( $child_id );
                                if ( $variation ) {
                                        $variation->save();
                                }
                        }
                }

                // КРИТИЧЕСКИ ВАЖНО: повторно сохраняем родителя ПОСЛЕ создания всех
                // вариаций. Без этого WooCommerce не связывает атрибуты родителя с
                // вариациями — на странице товара select пустой.
                $product_obj = wc_get_product( $product_id );
                if ( $product_obj && $product_obj instanceof WC_Product_Variable ) {
                        // Перечитываем атрибуты и сохраняем заново.
                        $attributes = $product_obj->get_attributes();
                        $product_obj->set_attributes( $attributes );
                        $product_obj->save();

                        // Финальная синхронизация после повторного сохранения.
                        WC_Product_Variable::sync( $product_id );
                        WC_Product_Variable::sync_stock_status( $product_id );
                }

                // Сбрасываем ВСЕ кэши.
                wc_delete_product_transients( $product_id );
                clean_post_cache( $product_id );

                // Удаляем специфичные transient'ы вариативного товара.
                delete_transient( 'wc_var_prices_' . $product_id );
                delete_transient( 'wc_product_children_' . $product_id );
                delete_transient( 'wc_product_total_stock_' . $product_id );
                wp_cache_delete( $product_id, 'product_variation_attributes' );
                wp_cache_delete( $product_id, 'products' );

                return $product_id;
        }

        /**
         * Полностью инициализировать атрибут WooCommerce.
         * 1. Создаёт запись в таблице wc_attribute_taxonomies (если нет)
         * 2. Регистрирует таксономию в текущем запросе
         * 3. Сбрасывает кэш WooCommerce
         *
         * @param string $slug  Slug атрибута без 'pa_' (например 'color', 'size')
         * @param string $label Название (например 'Color', 'Size')
         */
        private function ensure_wc_attribute( $slug, $label ) {
                // 1. Проверяем напрямую в БД — минуя кэш.
                global $wpdb;
                $exists = $wpdb->get_var( $wpdb->prepare(
                        "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s LIMIT 1",
                        $slug
                ) );

                if ( ! $exists ) {
                        // Атрибута нет в таблице — создаём через WooCommerce API.
                        if ( function_exists( 'wc_create_attribute' ) ) {
                                $result = wc_create_attribute( array(
                                        'name'         => $label,
                                        'slug'         => $slug,
                                        'type'         => 'select',
                                        'order_by'     => 'menu_order',
                                        'has_archives' => false,
                                ) );

                                if ( is_wp_error( $result ) ) {
                                        // Если wc_create_attribute не сработал — вставляем напрямую в БД.
                                        $wpdb->insert(
                                                $wpdb->prefix . 'woocommerce_attribute_taxonomies',
                                                array(
                                                        'attribute_label'    => $label,
                                                        'attribute_name'     => $slug,
                                                        'attribute_type'      => 'select',
                                                        'attribute_orderby'   => 'menu_order',
                                                        'attribute_public'    => 0,
                                                )
                                        );
                                        $this->log( 'info', 'Атрибут создан напрямую в БД', array( 'slug' => $slug ) );
                                }
                        }

                        // Сбрасываем ВСЕ кэши WooCommerce.
                        delete_transient( 'wc_attribute_taxonomies' );
                        if ( isset( $GLOBALS['wc_attribute_taxonomies'] ) ) {
                                $GLOBALS['wc_attribute_taxonomies'] = null;
                        }
                }

                // 2. Регистрируем таксономию в текущем запросе.
                $taxonomy = 'pa_' . $slug;
                if ( ! taxonomy_exists( $taxonomy ) ) {
                        // Вызываем WooCommerce функцию регистрации.
                        if ( function_exists( 'wc_register_attribute_taxonomies' ) ) {
                                wc_register_attribute_taxonomies();
                        }

                        // Если всё ещё не зарегистрирована — вручную.
                        if ( ! taxonomy_exists( $taxonomy ) ) {
                                register_taxonomy( $taxonomy, array( 'product' ), array(
                                        'labels'       => array( 'name' => $label ),
                                        'hierarchical' => true,
                                        'show_ui'      => false,
                                        'query_var'    => true,
                                        'rewrite'      => false,
                                ) );
                                register_taxonomy_for_object_type( $taxonomy, 'product' );
                        }
                }

                // 3. Проверяем что ID действительно получен.
                $attr_id = $this->get_attribute_id( 'pa_' . $slug );
                if ( ! $attr_id ) {
                        $this->log( 'error', 'Атрибут не найден после создания', array( 'slug' => $slug ) );
                }
        }

        /**
         * Получить ID глобального атрибута по slug.
         * Запрашивает НАПРЯМУЮ из БД — минуя кэш WooCommerce.
         *
         * @param string $slug Slug атрибута (например 'pa_color' или 'color').
         * @return int
         */
        private function get_attribute_id( $slug ) {
                $slug = str_replace( 'pa_', '', $slug );

                // Прямой запрос в БД — минуя ВСЕ кэши.
                global $wpdb;
                $attr_id = $wpdb->get_var( $wpdb->prepare(
                        "SELECT attribute_id FROM {$wpdb->prefix}woocommerce_attribute_taxonomies WHERE attribute_name = %s LIMIT 1",
                        $slug
                ) );

                return $attr_id ? (int) $attr_id : 0;
        }

        /**
         * Создать или получить терм атрибута — возвращает TERM ID (не slug).
         *
         * @param string $name     Имя (например 'BLACK' или 'XXL').
         * @param string $taxonomy Таксономия ('pa_color' или 'pa_size').
         * @return int term_id или 0 при ошибке.
         */
        private function ensure_attribute_term_id( $name, $taxonomy ) {
                $name = trim( $name );
                if ( empty( $name ) ) {
                        return 0;
                }

                if ( ! taxonomy_exists( $taxonomy ) ) {
                        $this->log( 'warning', 'Таксономия не существует', array( 'taxonomy' => $taxonomy ) );
                        return 0;
                }

                $existing = term_exists( $name, $taxonomy );
                if ( is_array( $existing ) && isset( $existing['term_id'] ) ) {
                        $term_id = (int) $existing['term_id'];

                        // Для pa_color — обновляем HEX-код (если ещё не задан).
                        if ( 'pa_color' === $taxonomy ) {
                                $this->maybe_set_color_hex( $term_id, $name );
                        }

                        return $term_id;
                }

                // Создаём с кастомным slug (сохраняет "+", "." и т.д.).
                $custom_slug = $this->make_attribute_slug( $name );
                $result = wp_insert_term( $name, $taxonomy, array( 'slug' => $custom_slug ) );
                if ( is_wp_error( $result ) ) {
                        // Возможно slug занят — пробуем без явного slug.
                        $result = wp_insert_term( $name, $taxonomy );
                        if ( is_wp_error( $result ) ) {
                                $this->log( 'warning', 'Не удалось создать терм', array(
                                        'name'     => $name,
                                        'taxonomy' => $taxonomy,
                                        'error'    => $result->get_error_message(),
                                ) );
                                return 0;
                        }
                }

                $term_id = (int) $result['term_id'];

                // Для pa_color — определяем HEX-код и сохраняем в ACF поле.
                if ( 'pa_color' === $taxonomy ) {
                        $this->maybe_set_color_hex( $term_id, $name );
                }

                return $term_id;
        }

        /**
         * Определить HEX-код цвета по названию и сохранить в ACF поле.
         * ACF field key: field_6a1259b521ec0 (color picker на таксономии pa_color)
         *
         * @param int    $term_id ID терма цвета
         * @param string $name    Название цвета (например 'EMERALDGOLD')
         */
        private function maybe_set_color_hex( $term_id, $name ) {
                // Проверяем — не задан ли уже HEX (по имени поля ACF 'cvet').
                $existing_hex = get_term_meta( $term_id, 'cvet', true );
                if ( $existing_hex ) {
                        return; // Уже задан — не перезаписываем.
                }

                // Определяем HEX по названию.
                $hex = BSI_Color_Matcher::match( $name );
                if ( ! $hex ) {
                        return; // Не удалось определить — оставляем пустым.
                }

                // Сохраняем в ACF поле по ИМЕНИ поля ('cvet'), а не по ключу.
                update_term_meta( $term_id, 'cvet', $hex );
                // ACF reference — ключ поля для корректного отображения в админке.
                update_term_meta( $term_id, '_cvet', 'field_6a1259b521ec0' );

                $this->log( 'info', 'HEX-код цвета определён автоматически', array(
                        'color' => $name,
                        'hex'   => $hex,
                        'term'  => $term_id,
                ) );
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

                // Создаём новый терм с КАСТОМНЫМ slug (сохраняет "+", "." и т.д.).
                // Без этого WordPress sanitize_title() срежет "+" → "7+" станет slug "7".
                $custom_slug = $this->make_attribute_slug( $name );
                $result = wp_insert_term( $name, $taxonomy, array( 'slug' => $custom_slug ) );
                if ( is_wp_error( $result ) ) {
                        // Возможно slug уже занят — пробуем без явного slug.
                        $result = wp_insert_term( $name, $taxonomy );
                        if ( is_wp_error( $result ) ) {
                                $this->log( 'warning', 'Не удалось создать терм атрибута', array(
                                        'name'     => $name,
                                        'taxonomy' => $taxonomy,
                                        'slug'     => $custom_slug,
                                        'error'    => $result->get_error_message(),
                                ) );
                                return '';
                        }
                }

                $term = get_term( $result['term_id'], $taxonomy );
                return $term ? $term->slug : '';
        }

        /**
         * Создать slug для атрибута, сохраняющий специальные символы.
         *
         * WordPress sanitize_title() срезает "+" → "7+" становится "7".
         * Это вызывает коллизии: "7+" и "7" получают одинаковый slug.
         *
         * Решение: конвертируем "+" в "-plus", "." в "-point", пробелы в "-".
         *
         * @param string $name
         * @return string
         */
        private function make_attribute_slug( $name ) {
                $slug = strtolower( trim( $name ) );
                $slug = str_replace( array( '+', ' plus', 'plus ' ), '-plus', $slug );
                $slug = str_replace( '.', '-point', $slug );
                $slug = str_replace( '/', '-', $slug );
                $slug = preg_replace( '/[^a-z0-9\-]/', '', $slug );
                $slug = preg_replace( '/-+/', '-', $slug );
                $slug = trim( $slug, '-' );
                return $slug ?: sanitize_title( $name );
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

                // Если по meta не нашли — ищем по SKU через прямой SQL-запрос.
                // Ищем ВСЕ вариации с этим SKU у данного parent_id, не только первую,
                // потому что в базе могут быть дубликаты от прошлых импортов.
                if ( ! $variation_id && $cod_articolo ) {
                        global $wpdb;
                        $dupe_ids = $wpdb->get_col( $wpdb->prepare(
                                "SELECT p.ID FROM {$wpdb->posts} p
                                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                                  AND pm.meta_key = '_sku' AND pm.meta_value = %s
                                 WHERE p.post_type = 'product_variation'
                                   AND p.post_parent = %d
                                   AND p.post_status != 'trash'
                                 ORDER BY p.ID ASC",
                                $cod_articolo,
                                $product_id
                        ) );

                        if ( ! empty( $dupe_ids ) ) {
                                // Берём первую (самую старую) как основную.
                                $variation_id = (int) $dupe_ids[0];

                                // Если нашли больше одной — удаляем дубликаты.
                                if ( count( $dupe_ids ) > 1 ) {
                                        $duplicates_to_delete = array_slice( $dupe_ids, 1 );
                                        $this->log( 'warning', 'Найдены дубликаты вариаций — удаляем', array(
                                                'sku'             => $cod_articolo,
                                                'parent_id'       => $product_id,
                                                'kept_id'         => $variation_id,
                                                'duplicate_ids'   => $duplicates_to_delete,
                                                'total_found'     => count( $dupe_ids ),
                                        ) );
                                        foreach ( $duplicates_to_delete as $dup_id ) {
                                                wp_delete_post( (int) $dup_id, true );
                                        }
                                } else {
                                        $this->log( 'info', 'Вариация найдена по SKU (SQL)', array(
                                                'sku'           => $cod_articolo,
                                                'variation_id'  => $variation_id,
                                                'parent_id'     => $product_id,
                                        ) );
                                }
                        }
                }

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
                                // Конфликт SKU — уже есть в другом товаре.
                                // Пытаемся разрешить: найти товар с этим SKU и,
                                // если это другой родитель — освободить SKU.
                                $existing_id = wc_get_product_id_by_sku( $cod_articolo );
                                if ( $existing_id && (int) $existing_id !== (int) $variation->get_id() ) {
                                        $existing_product = wc_get_product( $existing_id );
                                        if ( $existing_product ) {
                                                // Освобождаем SKU у старого товара.
                                                $existing_product->set_sku( '' );
                                                $existing_product->save();
                                                $this->log( 'warning', 'Освобождён SKU у старого товара', array(
                                                        'sku'                  => $cod_articolo,
                                                        'old_product_id'       => $existing_id,
                                                        'old_parent_id'        => $existing_product instanceof WC_Product_Variation ? $existing_product->get_parent_id() : 0,
                                                        'new_variation_id'     => $variation->get_id(),
                                                        'new_parent_id'        => $product_id,
                                                ) );
                                                // Повторно пытаемся установить SKU.
                                                try {
                                                        $variation->set_sku( $cod_articolo );
                                                } catch ( Exception $e2 ) {
                                                        $this->log( 'warning', 'Конфликт SKU variation (повтор)', array(
                                                                'sku'      => $cod_articolo,
                                                                'err'      => $e2->getMessage(),
                                                                'igu'      => isset( $row['IGUArticolo'] ) ? $row['IGUArticolo'] : '',
                                                                'modello'  => isset( $row['Modello'] ) ? $row['Modello'] : '',
                                                                'colore'   => isset( $row['DSColore'] ) ? $row['DSColore'] : '',
                                                                'taglia'   => isset( $row['Taglia'] ) ? $row['Taglia'] : '',
                                                                'parent'   => $product_id,
                                                        ) );
                                                }
                                        }
                                } else {
                                        $this->log( 'warning', 'Конфликт SKU variation', array(
                                                'sku'      => $cod_articolo,
                                                'err'      => $e->getMessage(),
                                                'igu'      => isset( $row['IGUArticolo'] ) ? $row['IGUArticolo'] : '',
                                                'modello'  => isset( $row['Modello'] ) ? $row['Modello'] : '',
                                                'colore'   => isset( $row['DSColore'] ) ? $row['DSColore'] : '',
                                                'taglia'   => isset( $row['Taglia'] ) ? $row['Taglia'] : '',
                                                'parent'   => $product_id,
                                        ) );
                                }
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
                // Название: приоритет — DSArticoloAgg (наиболее понятное),
                // затем DSArticoloWeb, затем DSArticolo (последнее средство).
                $title = '';
                if ( ! empty( $row['DSArticoloAgg'] ) ) {
                        $title = $row['DSArticoloAgg'];
                } elseif ( ! empty( $row['DSArticoloWeb'] ) ) {
                        $title = $row['DSArticoloWeb'];
                } elseif ( ! empty( $row['DSArticolo'] ) ) {
                        $title = $row['DSArticolo'];
                }
                if ( $title ) {
                        $product->set_name( $title );
                }

                // Описание: Note + габариты (ArticoloDescrizionePers) + состав (DSMateriale).
                $desc = '';
                if ( ! empty( $row['Nota'] ) ) {
                        $desc .= $row['Nota'] . "\n\n";
                }
                if ( ! empty( $row['ArticoloDescrizionePers'] ) ) {
                        $desc .= $row['ArticoloDescrizionePers'];
                }
                // Материал/состав: DSMaterialeWeb если есть, иначе DSMateriale.
                $materiale = '';
                if ( ! empty( $row['DSMaterialeWeb'] ) ) {
                        $materiale = $row['DSMaterialeWeb'];
                } elseif ( ! empty( $row['DSMateriale'] ) ) {
                        $materiale = $row['DSMateriale'];
                }
                if ( $materiale ) {
                        // Добавляем в описание как отдельный блок "Состав".
                        if ( $desc ) {
                                $desc .= "\n\n";
                        }
                        $desc .= '<strong>Состав:</strong> ' . nl2br( esc_html( $materiale ) );
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

                // Сохраняем ОРИГИНАЛЬНЫЕ цены BeeStore в мете — нужно для кнопки
                // «Пересчитать цены» на странице Конвертации, чтобы не зависеть
                // от повторного импорта при изменении курса/наценки.
                if ( $price_gross > 0 ) {
                        $product->update_meta_data( '_bsi_original_price_gross', $price_gross );
                }
                if ( $price_disc > 0 ) {
                        $product->update_meta_data( '_bsi_original_price_disc', $price_disc );
                }
                if ( $discount > 0 ) {
                        $product->update_meta_data( '_bsi_original_discount', $discount );
                }

                // Применяем конвертацию цен (если включена).
                // Формула: цена_поставщика × курс_валюты × коэффициент_надбавки + фиксированная_надбавка
                $price_gross_converted = $this->convert_price( $price_gross );
                $price_disc_converted  = $this->convert_price( $price_disc );

                if ( $price_gross_converted > 0 ) {
                        $product->set_regular_price( wc_format_decimal( $price_gross_converted, 2 ) );
                }

                if ( $price_disc_converted > 0 && $price_disc_converted < $price_gross_converted ) {
                        $product->set_sale_price( wc_format_decimal( $price_disc_converted, 2 ) );
                        $product->set_price( wc_format_decimal( $price_disc_converted, 2 ) );
                } elseif ( $discount > 0 && $price_gross_converted > 0 ) {
                        // Если есть скидка в %, но нет PrezzoScontatoIvato — вычисляем.
                        $sale = $price_gross_converted * ( 1 - $discount / 100 );
                        $product->set_sale_price( wc_format_decimal( $sale, 2 ) );
                        $product->set_price( wc_format_decimal( $sale, 2 ) );
                } else {
                        $product->set_sale_price( '' );
                        $product->set_price( wc_format_decimal( $price_gross_converted, 2 ) );
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

                // Конвертация цен ВСЕГДА применяется при импорте.
                // Курс берём через BSI_Currency::get_rate_for_conversion() — это
                // корректно работает и для ручного, и для авто-режима (в авто-режиме
                // курс читается из отдельной опции 'bsi_currency_rate_auto', которую
                // не могут затереть сохранения формы настроек).
                $rate   = class_exists( 'BSI_Currency' )
                        ? BSI_Currency::instance()->get_rate_for_conversion()
                        : ( isset( $settings['currency_rate'] ) ? (float) $settings['currency_rate'] : 1 );
                $markup = isset( $settings['markup_coefficient'] ) ? (float) $settings['markup_coefficient'] : 1;
                $fixed  = isset( $settings['fixed_markup'] ) ? (float) $settings['fixed_markup'] : 0;
                $round  = isset( $settings['round_prices'] ) && '1' === $settings['round_prices'];

                // Защита от нулевого курса/коэффициента.
                if ( $rate <= 0 ) {
                        $rate = 1;
                }
                if ( $markup <= 0 ) {
                        $markup = 1;
                }

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

                // Материал: DSMaterialeWeb если есть, иначе DSMateriale.
                $materiale = '';
                if ( ! empty( $row['DSMaterialeWeb'] ) ) {
                        $materiale = $row['DSMaterialeWeb'];
                } elseif ( ! empty( $row['DSMateriale'] ) ) {
                        $materiale = $row['DSMateriale'];
                }
                if ( $materiale && taxonomy_exists( 'pa_materiale' ) ) {
                        $mat_id = $this->ensure_term( $materiale, 'pa_materiale' );
                        if ( $mat_id ) {
                                wp_set_post_terms( $product_id, array( $mat_id ), 'pa_materiale' );
                        }
                }

                // Тип размерной сетки: DSTipoTagliaWeb если есть, иначе DSTipoTaglia.
                $tipo_taglia = '';
                if ( ! empty( $row['DSTipoTagliaWeb'] ) ) {
                        $tipo_taglia = $row['DSTipoTagliaWeb'];
                } elseif ( ! empty( $row['DSTipoTaglia'] ) ) {
                        $tipo_taglia = $row['DSTipoTaglia'];
                }
                if ( $tipo_taglia && taxonomy_exists( 'pa_tipo-taglia' ) ) {
                        $tt_id = $this->ensure_term( $tipo_taglia, 'pa_tipo-taglia' );
                        if ( $tt_id ) {
                                wp_set_post_terms( $product_id, array( $tt_id ), 'pa_tipo-taglia' );
                        }
                }

                // Indice — числовой индекс для сортировки размеров (S→M→L→XL).
                if ( isset( $row['Indice'] ) && '' !== trim( $row['Indice'] ) ) {
                        $indice = (int) $row['Indice'];
                        if ( $indice > 0 ) {
                                update_post_meta( $product_id, '_bsi_indice', $indice );
                        }
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
         * Умное переиспользование:
         *  1. Извлекаем basename без расширения (например "2000019668213_1")
         *  2. Ищем существующий attachment по meta _bsi_image_basename
         *  3. Если найден — проверяем, изменился ли файл на сервере (HEAD запрос)
         *  4. Если не изменился — переиспользуем (НЕ скачиваем, НЕ конвертируем)
         *  5. Если изменился или не найден — скачиваем, конвертируем в WebP
         *
         * WebP стратегия:
         *  - Если WebP получился МЕНЬШЕ оригинала — оставляем WebP, удаляем JPG
         *  - Если WebP получился БОЛЬШЕ — оставляем оригинал, удаляем WebP
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

                // СТАТИЧЕСКИЙ КЕШ внутри одного запроса/импорта: один и тот же URL
                // не должен обрабатываться дважды. Без этого при повторном появлении
                // URL в CSV (а BeeStore дублирует URL для разных размеров одной модели)
                // мы можем попасть в race condition.
                static $cache = array();
                $cache_key = $url . '|' . $filename_without_ext;
                if ( isset( $cache[ $cache_key ] ) ) {
                        return $cache[ $cache_key ];
                }

                // 1. Сначала ищем по meta _bsi_image_url (точное совпадение URL).
                $existing = $this->find_attachment_by_meta( '_bsi_image_url', $url );
                if ( $existing ) {
                        $cache[ $cache_key ] = $existing;
                        return $existing;
                }

                // 2. Ищем по _bsi_image_basename (без расширения).
                $existing_by_name = $this->find_attachment_by_basename( $filename_without_ext );
                if ( $existing_by_name ) {
                        $attach = get_post( $existing_by_name );
                        if ( $attach && 'attachment' === $attach->post_type ) {
                                update_post_meta( $existing_by_name, '_bsi_image_url', $url );
                                $cache[ $cache_key ] = $existing_by_name;
                                return $existing_by_name;
                        }
                }

                // 3. ИЩЕМ ПО _wp_attached_file ЧЕРЕЗ SQL LIKE — самый надёжный способ.
                // WordPress хранит путь в meta _wp_attached_file (например "2026/07/2000015777254_2.jpg").
                // Ищем по basename без расширения — найдёт в любой папке года/месяца.
                // Также проверяем .webp (если была конвертация).
                global $wpdb;
                $like_pattern = '%/' . $wpdb->esc_like( $filename_without_ext ) . '.%';
                $found_by_file = $wpdb->get_var( $wpdb->prepare(
                        "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_wp_attached_file'
                         AND pm.meta_value LIKE %s
                         AND p.post_type = 'attachment'
                         AND p.post_status != 'trash'
                         LIMIT 1",
                        $like_pattern
                ) );
                if ( $found_by_file ) {
                        $attach_id = (int) $found_by_file;
                        update_post_meta( $attach_id, '_bsi_image_url', $url );
                        update_post_meta( $attach_id, '_bsi_image_basename', $filename_without_ext );
                        update_post_meta( $attach_id, '_bsi_imported_by', 'beestore-integration' );
                        $cache[ $cache_key ] = $attach_id;
                        return $attach_id;
                }

                if ( ! $download ) {
                        return 0;
                }

                // ─── РУЧНОЕ СКАЧИВАНИЕ вместо media_sideload_image() ──────────
                // media_sideload_image() создаёт дубликаты файлов (-1, -2 суффиксы)
                // потому что НЕ проверяет существование файла перед скачиванием.
                // Мы скачиваем вручную: download_url → glob проверка → media_handle_sideload.

                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                // 1. Скачиваем во временный файл.
                $tmp_file = download_url( $url );
                if ( is_wp_error( $tmp_file ) ) {
                        $this->log( 'warning', 'Не удалось скачать картинку', array( 'url' => $url, 'err' => $tmp_file->get_error_message() ) );
                        return false;
                }

                // 2. ПРОВЕРКА ФАЙЛА НА ДИСКЕ через glob — поиск по всем подпапкам.
                // Если файл 2000015777254_2.jpg уже существует в любой папке uploads —
                // не создаём дубликат, а находим существующий attachment.
                $upload_dir = wp_upload_dir();
                $patterns = array(
                        trailingslashit( $upload_dir['basedir'] ) . '*/' . $filename_without_ext . '.*',
                        trailingslashit( $upload_dir['basedir'] ) . '*/*/' . $filename_without_ext . '.*',
                        trailingslashit( $upload_dir['path'] ) . $filename_without_ext . '.*',
                        trailingslashit( $upload_dir['basedir'] ) . $filename_without_ext . '.*',
                );
                $existing_files = array();
                foreach ( $patterns as $pattern ) {
                        $existing_files = array_merge( $existing_files, glob( $pattern ) );
                }
                $existing_files = array_unique( $existing_files );

                foreach ( $existing_files as $existing_file ) {
                        $relative = str_replace( trailingslashit( $upload_dir['basedir'] ), '', $existing_file );
                        global $wpdb;
                        $attach_id = $wpdb->get_var( $wpdb->prepare(
                                "SELECT post_id FROM {$wpdb->postmeta}
                                 WHERE meta_key = '_wp_attached_file' AND meta_value = %s
                                 LIMIT 1",
                                $relative
                        ) );
                        if ( $attach_id ) {
                                @unlink( $tmp_file );
                                $attach_id = (int) $attach_id;
                                update_post_meta( $attach_id, '_bsi_image_url', $url );
                                update_post_meta( $attach_id, '_bsi_image_basename', $filename_without_ext );
                                update_post_meta( $attach_id, '_bsi_imported_by', 'beestore-integration' );
                                $cache[ $cache_key ] = $attach_id;
                                return $attach_id;
                        }
                }

                // 3. Файл не существует — создаём новый attachment.
                $file_array = array(
                        'name'     => $basename,
                        'tmp_name'  => $tmp_file,
                );
                $attach_id = media_handle_sideload( $file_array, $product_id, 'BeeStore ' . $filename_without_ext );
                if ( is_wp_error( $attach_id ) ) {
                        @unlink( $tmp_file );
                        $this->log( 'warning', 'Не удалось создать attachment', array( 'url' => $url, 'err' => $attach_id->get_error_message() ) );
                        return false;
                }

                update_post_meta( $attach_id, '_bsi_image_url', $url );
                update_post_meta( $attach_id, '_bsi_image_basename', $filename_without_ext );
                update_post_meta( $attach_id, '_bsi_imported_by', 'beestore-integration' );

                $settings = get_option( 'bsi_settings', array() );
                $webp_enabled = isset( $settings['webp_enabled'] ) && '1' === $settings['webp_enabled'];
                if ( $webp_enabled ) {
                        $this->convert_attachment_to_webp( $attach_id );
                }

                $cache[ $cache_key ] = $attach_id;
                return $attach_id;
        }

        /**
         * Получить информацию о файле на сервере BeeStore через HTTP HEAD.
         *
         * Возвращает: array( 'etag' => ..., 'last_modified' => ..., 'size' => ... )
         * или false если запрос не удался.
         *
         * @param string $url
         * @return array|false
         */
        private function get_image_server_info( $url ) {
                $response = wp_remote_head( $url, array(
                        'timeout'    => 15,
                        'user-agent' => 'BeeStoreIntegration/' . BSI_VERSION,
                ) );

                if ( is_wp_error( $response ) ) {
                        return false;
                }

                $code = wp_remote_retrieve_response_code( $response );
                if ( 200 !== (int) $code ) {
                        return false;
                }

                return array(
                        'etag'          => wp_remote_retrieve_header( $response, 'etag' ),
                        'last_modified' => wp_remote_retrieve_header( $response, 'last-modified' ),
                        'size'          => (int) wp_remote_retrieve_header( $response, 'content-length' ),
                );
        }

        /**
         * Проверить, изменился ли файл на сервере по сравнению с сохранённым ранее.
         *
         * Сравнение по ETag (приоритет) или Last-Modified или Content-Length.
         * Если ETag совпадает — файл не изменился.
         * Если ETag нет, но Last-Modified совпадает — не изменился.
         * Если ничего нет — сравниваем по Content-Length.
         *
         * @param string $url
         * @param int    $attachment_id
         * @return bool true — изменился (нужно перескачать), false — не изменился.
         */
        private function image_changed_on_server( $url, $attachment_id ) {
                $saved_etag    = get_post_meta( $attachment_id, '_bsi_image_etag', true );
                $saved_lastmod = get_post_meta( $attachment_id, '_bsi_image_last_modified', true );
                $saved_size    = (int) get_post_meta( $attachment_id, '_bsi_image_size', true );

                // Если ничего не сохранено — считаем, что изменился (перестраховка).
                if ( ! $saved_etag && ! $saved_lastmod && ! $saved_size ) {
                        return true;
                }

                $server_info = $this->get_image_server_info( $url );
                if ( ! $server_info ) {
                        // Не удалось проверить — считаем, что не изменился (чтобы не дёргать сервер).
                        return false;
                }

                // 1. ETag — самый надёжный.
                if ( ! empty( $server_info['etag'] ) && ! empty( $saved_etag ) ) {
                        return $server_info['etag'] !== $saved_etag;
                }

                // 2. Last-Modified.
                if ( ! empty( $server_info['last_modified'] ) && ! empty( $saved_lastmod ) ) {
                        return $server_info['last_modified'] !== $saved_lastmod;
                }

                // 3. Content-Length — менее надёжный, но лучше чем ничего.
                if ( $server_info['size'] > 0 && $saved_size > 0 ) {
                        return $server_info['size'] !== $saved_size;
                }

                // Ничего не можем сравнить — считаем, что не изменился.
                return false;
        }

        /**
         * Найти attachment по basename (без расширения).
         * Ищет в _bsi_image_basename meta.
         *
         * @param string $basename Filename без расширения (например "2000019668213_1")
         * @return int|false
         */
        /**
         * Найти attachment по пути к файлу на диске.
         * Использует прямой SQL — ищет в _wp_attached_file meta.
         */
        private function find_attachment_by_file( $file_path ) {
                global $wpdb;
                // _wp_attached_file хранит относительный путь (например 2026/08/2000015777254_2.jpg).
                $upload_dir = wp_upload_dir();
                $relative_path = str_replace( trailingslashit( $upload_dir['basedir'] ), '', $file_path );

                $found = $wpdb->get_var( $wpdb->prepare(
                        "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_wp_attached_file' AND pm.meta_value = %s
                         AND p.post_type = 'attachment'
                         AND p.post_status != 'trash'
                         LIMIT 1",
                        $relative_path
                ) );
                return $found ? (int) $found : 0;
        }

        private function find_attachment_by_basename( $basename ) {
                if ( empty( $basename ) ) {
                        return false;
                }
                global $wpdb;
                $found = $wpdb->get_var( $wpdb->prepare(
                        "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_bsi_image_basename' AND pm.meta_value = %s
                         AND p.post_type = 'attachment'
                         AND p.post_status != 'trash'
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
                        // Если WebP получился больше оригинала — это не ошибка, а осознанный skip.
                        // Логируем как info, не как warning.
                        if ( 'bsi_webp_larger' === $result->get_error_code() ) {
                                $this->log( 'info', 'WebP больше оригинала — оставлен JPG', array(
                                        'file'   => basename( $file ),
                                        'reason' => $result->get_error_message(),
                                ) );
                                return false;
                        }
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
                // ПРЯМОЙ SQL вместо get_posts() — потому что WordPress кеширует
                // WP_Query результаты и после создания нового attachment
                // кеш не сбрасывается → find возвращает пустой → создаётся дубль.
                global $wpdb;
                $found = $wpdb->get_var( $wpdb->prepare(
                        "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = %s AND pm.meta_value = %s
                         AND p.post_type = 'attachment'
                         LIMIT 1",
                        $key,
                        $value
                ) );
                return $found ? (int) $found : 0;
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
                // Используем ПРЯМОЙ SQL вместо get_posts() — потому что WordPress
                // кеширует результаты WP_Query, и после создания новой вариации
                // через $variation->save() кеш не сбрасывается. В результате
                // find_variation_by_meta возвращает пустой результат из кеша
                // и плагин создаёт ДУБЛИКАТ вариации.
                global $wpdb;
                $found = $wpdb->get_var( $wpdb->prepare(
                        "SELECT p.ID FROM {$wpdb->posts} p
                         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                            AND pm.meta_key = %s AND pm.meta_value = %s
                         WHERE p.post_type = 'product_variation'
                           AND p.post_parent = %d
                           AND p.post_status != 'trash'
                         LIMIT 1",
                        $key,
                        $value,
                        $parent_id
                ) );
                return $found ? (int) $found : 0;
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

                // Проверяем флаг остановки — если пользователь нажал Stop,
                // не позволяем JS перезапустить процесс.
                $stop_flag = get_transient( 'bsi_image_stop_requested' );
                if ( false !== $stop_flag ) {
                        delete_transient( 'bsi_image_stop_requested' );
                        wp_send_json_error( array( 'message' => __( 'Импорт картинок остановлен пользователем.', 'beestore-integration' ) ) );
                }

                // Проверяем статус процесса (paused? stopped?).
                $img_state = get_option( 'bsi_image_import_state', array() );
                $status    = isset( $img_state['status'] ) ? $img_state['status'] : 'idle';
                if ( 'paused' === $status ) {
                        wp_send_json_error( array( 'message' => __( 'Импорт картинок на паузе. Нажмите «Продолжить».', 'beestore-integration' ) ) );
                }
                if ( 'stopped' === $status ) {
                        wp_send_json_error( array( 'message' => __( 'Импорт картинок остановлен.', 'beestore-integration' ) ) );
                }

                $batch_size = isset( $_POST['batch_size'] ) ? absint( $_POST['batch_size'] ) : 10;
                $offset     = isset( $img_state['offset'] ) ? (int) $img_state['offset'] : 0;

                // Помечаем как running.
                update_option( 'bsi_image_import_state', array(
                        'status'   => 'running',
                        'offset'   => $offset,
                        'total'    => isset( $img_state['total'] ) ? $img_state['total'] : 0,
                ), false );

                // Ищем товары с сохранёнными URL картинок.
                global $wpdb;
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
                $downloaded = 0;
                $skipped    = 0;
                $failed     = 0;
                $errors     = array();

                foreach ( $query->posts as $product_id ) {
                        $processed++;
                        $urls = get_post_meta( $product_id, '_bsi_image_urls', true );
                        if ( empty( $urls ) || ! is_array( $urls ) ) {
                                $skipped++;
                                continue;
                        }

                        // Featured image (первая картинка).
                        $featured_url = $urls[0];
                        $thumb_id     = get_post_thumbnail_id( $product_id );

                        if ( ! $thumb_id ) {
                                $attach_id = $this->attach_image( $featured_url, $product_id, true );
                                if ( $attach_id ) {
                                        set_post_thumbnail( $product_id, $attach_id );
                                        $downloaded++;
                                } else {
                                        $failed++;
                                        if ( count( $errors ) < 5 ) {
                                                $errors[] = sprintf( 'Product #%d: %s', $product_id, $featured_url );
                                        }
                                        continue;
                                }
                        } else {
                                $skipped++;
                        }

                        // Галерея (остальные картинки).
                        $gallery_urls  = array_slice( $urls, 1 );
                        $gallery_ids   = array();
                        $existing_gallery = get_post_meta( $product_id, '_product_image_gallery', true );
                        $existing_ids     = $existing_gallery ? array_map( 'intval', explode( ',', $existing_gallery ) ) : array();

                        foreach ( $gallery_urls as $url ) {
                                // Проверяем basename — если уже скачана, пропускаем.
                                $basename = pathinfo( basename( parse_url( $url, PHP_URL_PATH ) ), PATHINFO_FILENAME );
                                $existing_attach = $this->find_attachment_by_basename( $basename );
                                if ( $existing_attach ) {
                                        $gallery_ids[] = $existing_attach;
                                        $skipped++;
                                        continue;
                                }
                                $g_attach = $this->attach_image( $url, $product_id, true );
                                if ( $g_attach ) {
                                        $gallery_ids[] = $g_attach;
                                        $downloaded++;
                                } else {
                                        $failed++;
                                }
                        }
                        if ( ! empty( $gallery_ids ) ) {
                                update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
                        }
                }

                $new_offset = $offset + $processed;
                $has_more   = $new_offset < $total;

                // Обновляем состояние.
                $new_state = array(
                        'status'   => $has_more ? 'running' : 'completed',
                        'offset'   => $has_more ? $new_offset : 0,
                        'total'    => $total,
                );
                // Накапливаем счётчики.
                $cumulative = get_option( 'bsi_image_import_stats', array(
                        'downloaded' => 0,
                        'skipped'    => 0,
                        'failed'     => 0,
                ) );
                $cumulative['downloaded'] += $downloaded;
                $cumulative['skipped']    += $skipped;
                $cumulative['failed']     += $failed;
                update_option( 'bsi_image_import_stats', $cumulative, false );

                update_option( 'bsi_image_import_state', $new_state, false );

                $this->log( 'info', 'Импорт картинок: батч обработан', array(
                        'offset'     => $offset,
                        'processed'  => $processed,
                        'downloaded' => $downloaded,
                        'skipped'    => $skipped,
                        'failed'     => $failed,
                        'total'      => $total,
                        'has_more'   => $has_more,
                ) );

                wp_send_json_success( array(
                        'processed'  => $processed,
                        'downloaded' => $cumulative['downloaded'],
                        'skipped'    => $cumulative['skipped'],
                        'failed'     => $cumulative['failed'],
                        'total'      => $total,
                        'next_offset' => $new_offset,
                        'has_more'   => $has_more,
                        'errors'     => $errors,
                ) );
        }

        /**
         * AJAX: пауза импорта картинок.
         */
        public function ajax_backfill_pause() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }
                $state = get_option( 'bsi_image_import_state', array() );
                $state['status'] = 'paused';
                update_option( 'bsi_image_import_state', $state, false );
                wp_send_json_success( array( 'message' => __( 'Импорт картинок на паузе.', 'beestore-integration' ) ) );
        }

        /**
         * AJAX: продолжить импорт картинок.
         */
        public function ajax_backfill_resume() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }
                $state = get_option( 'bsi_image_import_state', array() );
                $state['status'] = 'running';
                update_option( 'bsi_image_import_state', $state, false );
                wp_send_json_success( array( 'message' => __( 'Импорт картинок продолжён.', 'beestore-integration' ) ) );
        }

        /**
         * AJAX: остановить импорт картинок (сброс).
         */
        public function ajax_backfill_stop() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }
                // Полный сброс ВСЕХ процессов импорта.
                delete_option( 'bsi_image_import_state' );
                delete_option( 'bsi_image_import_stats' );
                delete_option( 'bsi_import_state' );

                // Сбрасываем все lock'и.
                delete_transient( 'bsi_import_lock' );
                delete_transient( 'bsi_import_lock_pid' );

                // Флаги остановки.
                set_transient( 'bsi_image_stop_requested', time(), 60 );
                set_transient( 'bsi_import_stop_requested', time(), 60 );

                wp_send_json_success( array( 'message' => __( 'Импорт картинок и основной импорт остановлены. Все процессы сброшены.', 'beestore-integration' ) ) );
        }

        /**
         * AJAX: получить статус импорта картинок (для восстановления UI при перезагрузке).
         */
        public function ajax_backfill_status() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $state = get_option( 'bsi_image_import_state', array() );
                $stats = get_option( 'bsi_image_import_stats', array() );

                wp_send_json_success( array(
                        'status'   => isset( $state['status'] ) ? $state['status'] : 'idle',
                        'offset'   => isset( $state['offset'] ) ? (int) $state['offset'] : 0,
                        'total'    => isset( $state['total'] ) ? (int) $state['total'] : 0,
                        'stats'    => array(
                                'downloaded' => isset( $stats['downloaded'] ) ? (int) $stats['downloaded'] : 0,
                                'skipped'    => isset( $stats['skipped'] ) ? (int) $stats['skipped'] : 0,
                                'failed'     => isset( $stats['failed'] ) ? (int) $stats['failed'] : 0,
                        ),
                ) );
        }

        /* ---------------------------------------------------------------------
         * Пересчёт цен всех импортированных товаров по текущей формуле.
         *
         * Используется кнопкой на странице «Конвертация цен» — после изменения
         * курса/наценки/фиксированной надбавки. Берёт оригинальные цены из
         * меты _bsi_original_price_gross / _bsi_original_price_disc / _bsi_original_discount
         * (заполняются при импорте) и заново применяет convert_price().
         *
         * @param int $offset  Пагинация (для AJAX-обработки большими батчами).
         * @param int $batch   Размер батча.
         * @return array       Статистика обработки.
         * --------------------------------------------------------------------- */
        public function recalculate_all_prices( $offset = 0, $batch = 100 ) {
                $processed = 0;
                $success   = 0;
                $failed    = 0;
                $skipped   = 0;
                $errors    = array();

                // Получаем все товары (включая вариации), у которых есть
                // оригинальная цена BeeStore в мете.
                $args = array(
                        'post_type'      => array( 'product', 'product_variation' ),
                        'post_status'    => 'any',
                        'posts_per_page' => $batch,
                        'offset'         => $offset,
                        'fields'         => 'ids',
                        'meta_query'     => array(
                                array(
                                        'key'     => '_bsi_original_price_gross',
                                        'compare' => 'EXISTS',
                                ),
                        ),
                        'orderby'        => 'ID',
                        'order'          => 'ASC',
                );

                $query = new WP_Query( $args );
                $total = $query->found_posts;

                foreach ( $query->posts as $post_id ) {
                        $processed++;

                        $product = ( 'product_variation' === get_post_type( $post_id ) )
                                ? wc_get_product( $post_id )
                                : wc_get_product( $post_id );

                        if ( ! $product ) {
                                $failed++;
                                $errors[] = sprintf( 'Product %d: не удалось загрузить', $post_id );
                                continue;
                        }

                        // Читаем оригинальные цены из меты.
                        $original_gross = (float) $product->get_meta( '_bsi_original_price_gross' );
                        $original_disc  = (float) $product->get_meta( '_bsi_original_price_disc' );
                        $original_disc_pct = (float) $product->get_meta( '_bsi_original_discount' );

                        if ( $original_gross <= 0 ) {
                                $skipped++;
                                continue;
                        }

                        // Применяем текущую формулу.
                        $price_gross_converted = $this->convert_price( $original_gross );
                        $price_disc_converted  = $original_disc > 0 ? $this->convert_price( $original_disc ) : 0;

                        if ( $price_gross_converted > 0 ) {
                                $product->set_regular_price( wc_format_decimal( $price_gross_converted, 2 ) );
                        }

                        if ( $price_disc_converted > 0 && $price_disc_converted < $price_gross_converted ) {
                                $product->set_sale_price( wc_format_decimal( $price_disc_converted, 2 ) );
                                $product->set_price( wc_format_decimal( $price_disc_converted, 2 ) );
                        } elseif ( $original_disc_pct > 0 && $price_gross_converted > 0 ) {
                                $sale = $price_gross_converted * ( 1 - $original_disc_pct / 100 );
                                $product->set_sale_price( wc_format_decimal( $sale, 2 ) );
                                $product->set_price( wc_format_decimal( $sale, 2 ) );
                        } else {
                                $product->set_sale_price( '' );
                                $product->set_price( wc_format_decimal( $price_gross_converted, 2 ) );
                        }

                        $product->save();
                        $success++;
                }

                return array(
                        'processed'    => $processed,
                        'success'      => $success,
                        'failed'       => $failed,
                        'skipped'      => $skipped,
                        'total'        => $total,
                        'next_offset'  => $offset + $processed,
                        'has_more'     => ( $offset + $processed ) < $total,
                        'errors'       => $errors,
                );
        }
}
