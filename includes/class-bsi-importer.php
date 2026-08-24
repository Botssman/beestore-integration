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
                // Cron hook полного каталога (ежедневно).
                add_action( 'bsi_cron_full_import', array( $this, 'cron_full_import' ) );
                // Cron hook синхронизации остатков (отдельная, быстрая).
                add_action( 'bsi_cron_stock_sync', array( $this, 'cron_stock_sync' ) );
                // AJAX/ручной запуск.
                add_action( 'wp_ajax_bsi_manual_import', array( $this, 'ajax_manual_import' ) );
                // AJAX backfill картинок (докачка после разблокировки Sirio).
                add_action( 'wp_ajax_bsi_backfill_images', array( $this, 'ajax_backfill_images' ) );

                // Новые AJAX-эндпоинты для импорта с сохранением прогресса.
                add_action( 'wp_ajax_bsi_import_start', array( $this, 'ajax_import_start' ) );
                add_action( 'wp_ajax_bsi_import_process_batch', array( $this, 'ajax_import_process_batch' ) );
                add_action( 'wp_ajax_bsi_import_pause', array( $this, 'ajax_import_pause' ) );
                add_action( 'wp_ajax_bsi_import_continue', array( $this, 'ajax_import_continue' ) );
                add_action( 'wp_ajax_bsi_import_stop', array( $this, 'ajax_import_stop' ) );
                add_action( 'wp_ajax_bsi_import_status', array( $this, 'ajax_import_status' ) );

                // AJAX: полная очистка товаров и категорий BeeStore.
                add_action( 'wp_ajax_bsi_purge_all', array( $this, 'ajax_purge_all' ) );

                // AJAX: удалить только картинки, импортированные плагином.
                add_action( 'wp_ajax_bsi_purge_images', array( $this, 'ajax_purge_images' ) );

                // AJAX: остановить фоновый cron-импорт (сброс lock).
                add_action( 'wp_ajax_bsi_stop_cron_import', array( $this, 'ajax_stop_cron_import' ) );

                // AJAX: удалить только дубликаты картинок (оставить по одной).
                add_action( 'wp_ajax_bsi_purge_duplicate_images', array( $this, 'ajax_purge_duplicate_images' ) );

                // AJAX: сканировать диск на дубликаты и orphan-файлы.
                add_action( 'wp_ajax_bsi_scan_disk_duplicates', array( $this, 'ajax_scan_disk_duplicates' ) );

                // AJAX: удалить дубликаты файлов с диска (-1, -2 суффиксы).
                add_action( 'wp_ajax_bsi_delete_disk_duplicates', array( $this, 'ajax_delete_disk_duplicates' ) );

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

                // AJAX: синхронизация остатков (ручная кнопка + прогресс).
                add_action( 'wp_ajax_bsi_stock_start', array( $this, 'ajax_stock_start' ) );
                add_action( 'wp_ajax_bsi_stock_process_batch', array( $this, 'ajax_stock_process_batch' ) );
                add_action( 'wp_ajax_bsi_stock_stop', array( $this, 'ajax_stock_stop' ) );
                add_action( 'wp_ajax_bsi_stock_status', array( $this, 'ajax_stock_status' ) );
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
                        'skipped_products' => 0,
                        'filtered_products' => 0,
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
                        'created_products'  => 0,
                        'updated_products'  => 0,
                        'skipped_products'  => 0,
                        'filtered_products' => 0,
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
                // Батч ПЛАВАЮЩИЙ: не режем товар посреди его вариантов.
                // При достижении $batch_size продолжаем читать, пока не завершится
                // текущий товар (IGUArticolo на строке разреза). Так варианты одного
                // товара ВСЕГДА попадают в один батч → товар обрабатывается 1 раз.
                //
                // ВАЖНО про потери строк: читаем "с опережением" (peek). Строка,
                // которая не вошла в батч (следующий товар), остаётся в $pending_row
                // и в начале следующего батча добавляется первой. Так ни одна строка
                // не теряется между батчами.
                $pending_row  = null; // строка, прочитанная но не вошедшая в этот батч.
                $cut_igu      = '';   // IGU товара, «перешагнувшего» лимит.
                $limit_done   = false;

                // Вливаем недочитанную строку с прошлого батча (если была).
                // state['pending_row'] — массив этой строки или null.
                if ( isset( $state['pending_row'] ) && is_array( $state['pending_row'] ) ) {
                        $pending_row = $state['pending_row'];
                }

                while ( true ) {
                        // Берём следующую строку: либо накопленную, либо читаем из файла.
                        if ( null !== $pending_row ) {
                                $row         = $pending_row;
                                $pending_row = null;
                        } else {
                                if ( feof( $handle ) ) {
                                        break;
                                }
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
                                $row = array_combine( $headers, array_map( 'trim', $raw_row ) );
                        }

                        $at_limit = count( $batch_rows ) >= $batch_size;
                        $igu_now  = isset( $row['IGUArticolo'] ) ? $row['IGUArticolo'] : '';

                        if ( $at_limit && $limit_done ) {
                                // Лимит достигнут И товар-разрезчик уже определён.
                                if ( $igu_now === $cut_igu ) {
                                        // Этот же товар продолжается — берём строку.
                                        $batch_rows[] = $row;
                                } else {
                                        // Товар завершился. Сохраняем строку для следующего батча.
                                        $pending_row = $row;
                                        break;
                                }
                        } else {
                                $batch_rows[] = $row;
                                if ( $at_limit && ! $limit_done ) {
                                        // Только что перешагнули лимит — фиксируем товар-разрез.
                                        $cut_igu    = $this->last_igu( $batch_rows );
                                        $limit_done = true;
                                }
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
                                'pending_row'    => null, // хвоста не остаётся.
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
                $batch_updated_items = array(); // Список обновлённых товаров (для UI).
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
                                        // Даже если данные не изменились — проверим статус по наличию
                                        // картинок В CSV (нет ни одного URLImg → черновик).
                                        $this->sync_visibility_by_images( $existing_id, $data['variants'] );
                                        $batch_skipped++;
                                        BSI_Import_Filters::instance()->increment_counters( $category, $brand );
                                        continue;
                                }

                                // Определяем ПРИЧИНУ ДО пересоздания — когда карта хешей ещё
                                // от прошлого импорта (иначе после upsert карта станет новой
                                // и причина покажет «неизвестная» вместо реальной).
                                $given_reason = '';
                                if ( $existing_id ) {
                                        $given_reason = $this->unchanged_reason( $existing_id, $data['variants'] );
                                }

                                $this->upsert_model( $igu, $data['parent'], $data['variants'], $is_multi_variant );
                                BSI_Import_Filters::instance()->increment_counters( $category, $brand );
                                if ( $existing_id ) {
                                        $batch_updated++;
                                        $item_name = ( ! empty( $data['parent']['DSArticoloAgg'] ) ) ? $data['parent']['DSArticoloAgg'] : ( ! empty( $data['parent']['DSArticolo'] ) ? $data['parent']['DSArticolo'] : $igu );
                                        $reason     = $given_reason;
                                        // Собираем для показа на странице импорта (живая лента).
                                        $batch_updated_items[] = array(
                                                'igu'      => $igu,
                                                'name'     => $item_name,
                                                'variants' => count( $data['variants'] ),
                                                'reason'   => $reason,
                                        );
                                        // Логируем какой именно товар обновлён — чтобы можно было
                                        // отследить что именно изменилось при повторном импорте.
                                        $this->log( 'info', 'Товар обновлён (повторный импорт)', array(
                                                'igu'      => $igu,
                                                'name'     => $item_name,
                                                'variants' => count( $data['variants'] ),
                                                'reason'   => $reason,
                                        ) );
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
                        'pending_row'      => ( null !== $pending_row ) ? $pending_row : null,
                        // Реальное прошедшее время с начала импорта (не только обработка батча).
                        'elapsed_seconds'  => (int) ( strtotime( current_time( 'mysql' ) ) - ( isset( $db_state['started_at'] ) && $db_state['started_at'] ? strtotime( $db_state['started_at'] ) : time() ) ),
                        'errors_count'     => $db_state['errors_count'] + $batch_errors,
                        'last_error'       => $batch_last_err ?: $db_state['last_error'],
                        'created_products' => $db_state['created_products'] + $batch_created,
                        'updated_products' => $db_state['updated_products'] + $batch_updated,
                        'skipped_products' => $db_state['skipped_products'] + $batch_skipped,
                        'filtered_products' => $db_state['filtered_products'] + $skipped_by_filter,
                ));

                $updated_state = $this->get_import_state();
                $percent = $updated_state['total_rows'] > 0
                        ? round( ( $updated_state['processed_rows'] / $updated_state['total_rows'] ) * 100, 1 )
                        : 0;

                wp_send_json_success( array(
                        'message'  => sprintf(
                                __( 'Обработано: %d / %d (%.1f%%). Создано: %d, обновлено: %d, пропущено: %d, отфильтровано: %d, ошибок: %d', 'beestore-integration' ),
                                $updated_state['processed_rows'],
                                $updated_state['total_rows'],
                                $percent,
                                $batch_created,
                                $batch_updated,
                                $batch_skipped,
                                $skipped_by_filter,
                                $batch_errors
                        ),
                        'state'       => $updated_state,
                        'percent'     => $percent,
                        'finished'    => false,
                        'updated'     => $batch_updated_items, // Список обновлённых товаров за этот батч.
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

                // Учитываем настройку: если импорт отключён — не показываем «следующий запуск».
                $settings  = get_option( 'bsi_settings', array() );
                $freq      = isset( $settings['sync_frequency'] ) ? $settings['sync_frequency'] : 'hourly';

                $next_cron = wp_next_scheduled( 'bsi_cron_import_catalog' );
                if ( 'disabled' === $freq || ! $freq ) {
                        $next_cron = false; // Отключено в настройках — не планируем.
                }
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

        /**
         * AJAX: удалить только дубликаты картинок (оставить по одной каждого basename).
         *
         * Группирует все attachments BeeStore по _bsi_image_basename.
         * Для каждой группы с > 1 attachment — оставляет первый (самый старый),
         * остальные удаляет. Также проверяет по _wp_attached_file (basename файла).
         */
        public function ajax_purge_duplicate_images() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                global $wpdb;

                // 1. Находим все attachments BeeStore и их basename.
                $rows = $wpdb->get_results(
                        "SELECT pm1.post_id, pm1.meta_value AS basename, pm2.meta_value AS attached_file
                         FROM {$wpdb->postmeta} pm1
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm1.post_id AND p.post_type = 'attachment' AND p.post_status != 'trash'
                         LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm1.post_id AND pm2.meta_key = '_wp_attached_file'
                         WHERE pm1.meta_key = '_bsi_image_basename'
                         ORDER BY pm1.post_id ASC",
                        ARRAY_A
                );

                // 2. Группируем по basename.
                $groups = array();
                foreach ( $rows as $row ) {
                        $basename = $row['basename'];
                        if ( ! $basename ) {
                                // Если нет _bsi_image_basename — извлекаем из _wp_attached_file.
                                if ( ! empty( $row['attached_file'] ) ) {
                                        $basename = pathinfo( $row['attached_file'], PATHINFO_FILENAME );
                                }
                        }
                        if ( $basename ) {
                                $groups[ $basename ][] = (int) $row['post_id'];
                        }
                }

                // 3. Для групп с > 1 attachment — удаляем дубликаты.
                $deleted = 0;
                $kept_groups = 0;
                $duplicate_groups = 0;
                foreach ( $groups as $basename => $ids ) {
                        if ( count( $ids ) <= 1 ) {
                                $kept_groups++;
                                continue;
                        }
                        $duplicate_groups++;
                        // Оставляем первый (самый старый, т.к. отсортировано по post_id ASC).
                        $keep_id = $ids[0];
                        $duplicates = array_slice( $ids, 1 );
                        foreach ( $duplicates as $dup_id ) {
                                wp_delete_attachment( $dup_id, true );
                                $deleted++;
                        }
                }

                $this->log( 'info', 'Удаление дубликатов картинок', array(
                        'total_attachments' => count( $rows ),
                        'unique_basenames'  => count( $groups ),
                        'duplicate_groups'  => $duplicate_groups,
                        'deleted'           => $deleted,
                        'kept'              => $kept_groups,
                ) );

                wp_send_json_success( array(
                        'message'    => sprintf(
                                /* translators: 1: deleted, 2: groups */
                                _n(
                                        'Удалено дубликатов: %1$d (из %2$d групп). Уникальных картинок: %3$d.',
                                        'Удалено дубликатов: %1$d (из %2$d групп). Уникальных картинок: %3$d.',
                                        $deleted,
                                        'beestore-integration'
                                ),
                                $deleted,
                                $duplicate_groups,
                                $kept_groups
                        ),
                        'deleted'         => $deleted,
                        'duplicate_groups' => $duplicate_groups,
                        'unique_images'   => $kept_groups,
                ) );
        }

        /* ---------------------------------------------------------------------
         * AJAX: сканировать диск на дубликаты файлов (-1, -2, -3 суффиксы).
         *
         * Ищет в wp-content/uploads/ файлы вида:
         *   2000019668213_1-1.jpg, 2000019668213_1-2.jpg, ...
         *   2000019668213_1-1.webp, 2000019668213_1-2.webp, ...
         *
         * Эти файлы — дубли, созданные media_handle_sideload() в старых версиях
         * плагина (до v1.9.5). Они не удаляются через wp_delete_attachment(),
         * потому что часто не привязаны ни к одному attachment в БД.
         * --------------------------------------------------------------------- */
        public function ajax_scan_disk_duplicates() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $upload_dir = wp_upload_dir();
                $basedir = $upload_dir['basedir'];

                if ( ! is_dir( $basedir ) ) {
                        wp_send_json_error( array( 'message' => 'Директория uploads не существует.' ) );
                }

                // Получаем параметры пагинации.
                $offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
                $limit  = 5000; // Сканируем по 5000 файлов за раз.

                $duplicates = array();
                $total_scanned = 0;
                $total_size    = 0;
                $skipped       = 0;

                try {
                        $iterator = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS ),
                                RecursiveIteratorIterator::LEAVES_ONLY
                        );

                        foreach ( $iterator as $file ) {
                                // Ограничиваем количество за один запрос (для shared hosting).
                                if ( $total_scanned >= $limit ) {
                                        break;
                                }

                                if ( ! $file->isFile() ) {
                                        continue;
                                }

                                $total_scanned++;

                                $filename = $file->getFilename();
                                $path     = $file->getPathname();
                                $size     = $file->getSize();

                                // Ищем файлы вида: basename-N.ext где N — число.
                                // Это дубли, созданные wp_unique_filename в WP.
                                if ( preg_match( '/^(.+)-(\d+)(\.[a-zA-Z0-9]+)$/', $filename, $m ) ) {
                                        $original_filename = $m[1] . $m[3];
                                        $original_path     = $file->getPath() . '/' . $original_filename;

                                        $duplicates[] = array(
                                                'file'        => substr( $path, strlen( $basedir ) + 1 ),
                                                'size'        => $size,
                                                'size_human'  => size_format( $size ),
                                                'has_original' => file_exists( $original_path ),
                                        );
                                        $total_size += $size;
                                }
                        }
                } catch ( Exception $e ) {
                        wp_send_json_error( array( 'message' => 'Ошибка сканирования: ' . $e->getMessage() ) );
                }

                // Считаем общее использование диска.
                $total_inodes = $this->count_inodes( $basedir );

                $this->log( 'info', 'Сканирование диска на дубликаты', array(
                        'scanned'      => $total_scanned,
                        'duplicates'   => count( $duplicates ),
                        'duplicates_size' => $total_size,
                ) );

                wp_send_json_success( array(
                        'scanned'        => $total_scanned,
                        'duplicates'     => $duplicates,
                        'duplicates_count' => count( $duplicates ),
                        'duplicates_size'  => $total_size,
                        'duplicates_size_human' => size_format( $total_size ),
                        'total_inodes'   => $total_inodes,
                        'basedir'        => $basedir,
                        'has_more'       => $total_scanned >= $limit,
                ) );
        }

        /**
         * Подсчитать количество файлов в директории (рекурсивно).
         * Кешируется в transient на 5 минут.
         */
        private function count_inodes( $dir ) {
                $cache_key = 'bsi_inode_count_' . md5( $dir );
                $cached = get_transient( $cache_key );
                if ( false !== $cached ) {
                        return (int) $cached;
                }

                $count = 0;
                try {
                        $iterator = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
                                RecursiveIteratorIterator::LEAVES_ONLY
                        );
                        foreach ( $iterator as $file ) {
                                if ( $file->isFile() ) {
                                        $count++;
                                }
                        }
                } catch ( Exception $e ) {
                        return 0;
                }

                set_transient( $cache_key, $count, 5 * MINUTE_IN_SECONDS );
                return $count;
        }

        /* ---------------------------------------------------------------------
         * AJAX: удалить дубликаты файлов с диска (-1, -2 суффиксы).
         *
         * Принимает список файлов или удаляет все найденные.
         * ВАЖНО: удаляет только файлы вида basename-N.ext — это дубли.
         * Оригинал basename.ext НЕ трогается.
         * --------------------------------------------------------------------- */
        public function ajax_delete_disk_duplicates() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $upload_dir = wp_upload_dir();
                $basedir = $upload_dir['basedir'];

                // Список файлов для удаления (от клиента).
                $files_to_delete = isset( $_POST['files'] ) ? (array) $_POST['files'] : array();
                $delete_all      = isset( $_POST['delete_all'] ) && '1' === $_POST['delete_all'];

                if ( empty( $files_to_delete ) && ! $delete_all ) {
                        wp_send_json_error( array( 'message' => 'Не указаны файлы для удаления.' ) );
                }

                // Если delete_all — сначала сканируем.
                if ( $delete_all ) {
                        $files_to_delete = array();
                        try {
                                $iterator = new RecursiveIteratorIterator(
                                        new RecursiveDirectoryIterator( $basedir, FilesystemIterator::SKIP_DOTS ),
                                        RecursiveIteratorIterator::LEAVES_ONLY
                                );
                                foreach ( $iterator as $file ) {
                                        if ( ! $file->isFile() ) {
                                                continue;
                                        }
                                        $filename = $file->getFilename();
                                        if ( preg_match( '/^(.+)-(\d+)(\.[a-zA-Z0-9]+)$/', $filename ) ) {
                                                $files_to_delete[] = substr( $file->getPathname(), strlen( $basedir ) + 1 );
                                        }
                                }
                        } catch ( Exception $e ) {
                                wp_send_json_error( array( 'message' => 'Ошибка сканирования: ' . $e->getMessage() ) );
                        }
                }

                $deleted = 0;
                $failed  = 0;
                $freed_bytes = 0;
                $errors  = array();

                foreach ( $files_to_delete as $rel_path ) {
                        // БЕЗОПАСНОСТЬ: только файлы внутри uploads/, только дубли.
                        $full_path = realpath( $basedir . '/' . $rel_path );
                        if ( ! $full_path ) {
                                $failed++;
                                continue;
                        }

                        // Проверяем, что путь внутри basedir.
                        if ( strpos( $full_path, realpath( $basedir ) ) !== 0 ) {
                                $failed++;
                                $errors[] = "Path traversal detected: $rel_path";
                                continue;
                        }

                        $filename = basename( $full_path );

                        // Проверяем, что это действительно дубликат (-N.ext).
                        if ( ! preg_match( '/^(.+)-(\d+)(\.[a-zA-Z0-9]+)$/', $filename ) ) {
                                $failed++;
                                $errors[] = "Not a duplicate: $filename";
                                continue;
                        }

                        $size = @filesize( $full_path );

                        if ( @unlink( $full_path ) ) {
                                $deleted++;
                                $freed_bytes += $size;
                        } else {
                                $failed++;
                                $errors[] = "Failed to delete: $rel_path";
                        }

                        // Лимит на один запрос — 1000 файлов (для shared hosting).
                        if ( $deleted >= 1000 ) {
                                break;
                        }
                }

                // Сбрасываем кеш inode.
                delete_transient( 'bsi_inode_count_' . md5( $basedir ) );

                $this->log( 'info', 'Удаление дублей файлов с диска', array(
                        'deleted'      => $deleted,
                        'failed'       => $failed,
                        'freed_bytes'  => $freed_bytes,
                        'freed_human'  => size_format( $freed_bytes ),
                ) );

                wp_send_json_success( array(
                        'deleted'        => $deleted,
                        'failed'         => $failed,
                        'freed_bytes'    => $freed_bytes,
                        'freed_human'    => size_format( $freed_bytes ),
                        'errors'         => array_slice( $errors, 0, 20 ),
                        'has_more'       => $deleted >= 1000,
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

                // Обрабатываем ВСЕ накопившиеся файлы по очереди за один запуск.
                // Инкрементальные файлы приходят каждые 15 минут, а cron (часто раз
                // в час) — поэтому важно за раз обработать все, что накопились.
                $processed_count = 0;
                $failed_count    = 0;
                $max_files       = 100;   // защита от невозможного количества.
                $max_seconds     = 55;    // максимальная длительность одного cron (иначе таймаут).

                $started = microtime( true );

                while ( $processed_count < $max_files ) {
                        // Прерываемся по времени — вдруг много файлов.
                        if ( ( microtime( true ) - $started ) > $max_seconds ) {
                                $this->log( 'info', 'Cron импорт: лимит времени достигнут', array(
                                        'processed' => $processed_count,
                                        'elapsed'   => round( microtime( true ) - $started, 1 ),
                                ) );
                                break;
                        }

                        $result = BSI_FTP::instance()->fetch_latest_zip( 'incremental' );
                        if ( is_wp_error( $result ) ) {
                                // Нет следующего файла (bsi_no_new_files) — всё обработано.
                                $code = $result->get_error_code();
                                if ( 'bsi_no_new_files' === $code ) {
                                        $this->log( 'info', 'Cron: новых файлов больше нет', array(
                                                'processed' => $processed_count,
                                        ) );
                                        break;
                                }
                                $this->log( 'warning', 'Cron: ошибка выборки файла', array(
                                        'err' => $result->get_error_message(),
                                ) );
                                $failed_count++;
                                if ( $failed_count >= 3 ) {
                                        break; // Защита от цикла при повторных ошибках.
                                }
                                continue;
                        }

                        $this->import_csv_file( $result['csv'], $result['zip'] );

                        // Пометить как обработанный.
                        $file_to_mark = $result['zip'] ? $result['zip'] : $result['csv'];
                        BSI_FTP::instance()->mark_processed( $file_to_mark );

                        $processed_count++;
                        $this->log( 'info', 'Cron: файл обработан', array(
                                'name'      => basename( $result['remote_name'] ),
                                'processed' => $processed_count,
                        ) );
                }

                if ( $processed_count > 0 ) {
                        $this->log( 'info', 'Cron: импорт всех накопившихся файлов завершён', array(
                                'processed' => $processed_count,
                                'elapsed'   => round( microtime( true ) - $started, 1 ),
                        ) );
                }
        }

        /* ---------------------------------------------------------------------
         * Ежедневный импорт ПОЛНОГО каталога (BSI_0000001).
         * Запускается cron раз в сутки (по настройке time_full_import, по умолчанию 02:00).
         * Отдельно от инкрементальных — большой файл обрабатывается один раз в день.
         * --------------------------------------------------------------------- */
        public function cron_full_import() {
                $settings = get_option( 'bsi_settings', array() );
                $freq = isset( $settings['full_sync_frequency'] ) ? $settings['full_sync_frequency'] : 'daily';
                if ( 'disabled' === $freq ) {
                        return;
                }

                // Как и инкрементальный — не конфликтуем с другим импортом.
                $lock = get_transient( 'bsi_import_lock' );
                if ( false !== $lock ) {
                        $lock_age = time() - (int) $lock;
                        if ( $lock_age < 1800 ) {
                                $this->log( 'info', 'Cron полный: импорт уже идёт — пропускаем', array( 'lock_age' => $lock_age ) );
                                return;
                        }
                        delete_transient( 'bsi_import_lock' );
                        delete_transient( 'bsi_import_lock_pid' );
                }

                $this->log( 'info', 'Запуск ежедневного полного импорта каталога' );

                $result = BSI_FTP::instance()->fetch_latest_zip( 'full' );
                if ( is_wp_error( $result ) ) {
                        $this->log( 'warning', 'Cron полный: нет полного файла', array(
                                'err' => $result->get_error_message(),
                        ) );
                        return;
                }

                // Импортируем полный каталог (замена).
                $this->import_csv_file( $result['csv'], $result['zip'] );

                // Пометим как обработанный.
                $file_to_mark = $result['zip'] ? $result['zip'] : $result['csv'];
                BSI_FTP::instance()->mark_processed( $file_to_mark );

                $this->log( 'info', 'Cron полный: каталог импортирован', array(
                        'file' => basename( $result['remote_name'] ),
                ) );
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
         * Теперь также проверяет через product_unchanged() — неизменённые товары
         * целиком пропускаются, без загрузки/сохранения в WooCommerce.
         *
         * @param array $models Ассоциативный массив: IGUArticolo => [parent, variants].
         */
        private function process_models_batch( $models ) {
                foreach ( $models as $igu_articolo => $data ) {
                        try {
                                // ─── Проверка: изменился ли товар ─────────────────────
                                // Если товар уже существует и данные не изменились —
                                // полностью пропускаем, без каких-либо операций WC.
                                $existing_id = $this->find_product_by_meta( '_bsi_igu_articolo', $igu_articolo );
                                if ( $existing_id && $this->product_unchanged( $existing_id, $data['variants'] ) ) {
                                        BSI_Import_Filters::instance()->increment_counters(
                                                $this->extract_category( $data['parent'] ),
                                                $this->extract_brand( $data['parent'] )
                                        );
                                        continue;
                                }

                                $this->upsert_model( $igu_articolo, $data['parent'], $data['variants'] );
                                // Логируем обновлённый товар.
                                $this->log( 'info', 'Товар импортирован (legacy)', array(
                                        'igu'  => $igu_articolo,
                                        'name' => ( ! empty( $data['parent']['DSArticoloAgg'] ) ) ? $data['parent']['DSArticoloAgg'] : $igu_articolo,
                                ) );
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
         * @return int ID товара или 0 при ошибке.
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
                        $product_id = $this->upsert_variable_product( $igu_articolo, $parent_row, $variant_rows );
                } else {
                        $product_id = $this->upsert_simple_product( $igu_articolo, $parent_row, $variant_rows[0] );
                }

                // Сохраняем карту хешей вариаций (SKU → хеш) для быстрого определения
                // изменений при повторном импорте.
                //
                // ВАЖНО: мержим (добавляем/обновляем) в существующую карту вместо
                // полной замены. Т.к. варианты одного товара разбросаны по батчам,
                // полная замена ломала сравнение (каждый батч стирал вчерашние SKU).
                if ( $product_id ) {
                        $map = get_post_meta( $product_id, '_bsi_data_hash', true );
                        if ( ! is_array( $map ) ) {
                                $map = array();
                        }
                        foreach ( $variant_rows as $row ) {
                                $sku = isset( $row['CodArticolo'] ) ? $row['CodArticolo'] : '';
                                if ( ! $sku ) {
                                        continue;
                                }
                                $map[ $sku ] = $this->compute_variant_hash( $row );
                        }
                        update_post_meta( $product_id, '_bsi_data_hash', $map );
                }

                return $product_id;
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

        /**
         * Извлечь категорию из строки CSV.
         * Единая логика: DSRepartoWeb → DSReparto → DSCategoriaMerceologicaWeb → DSCategoriaMerceologica.
         *
         * @param array $row Строка CSV.
         * @return string
         */
        private function extract_category( $row ) {
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
                return $category;
        }

        /**
         * Извлечь бренд из строки CSV.
         * Единая логика: DSLinea → RaggruppamentoLinea.
         *
         * @param array $row Строка CSV.
         * @return string
         */
        private function extract_brand( $row ) {
                $brand = '';
                if ( ! empty( $row['DSLinea'] ) ) {
                        $brand = $row['DSLinea'];
                } elseif ( ! empty( $row['RaggruppamentoLinea'] ) ) {
                        $brand = $row['RaggruppamentoLinea'];
                }
                return $brand;
        }

        /**
         * Найти IGUArticolo последней строки в массиве батча.
         * Используется для плавающего батча: чтобы не резать товар посреди
         * вариантов, смотрим IGU той строки, на которой «споткнулись» о лимит.
         *
         * @param array $rows Массив строк CSV (батч).
         * @return string
         */
        private function last_igu( $rows ) {
                $last = null;
                foreach ( $rows as $r ) {
                        if ( isset( $r['IGUArticolo'] ) && '' !== $r['IGUArticolo'] ) {
                                $last = $r['IGUArticolo'];
                        }
                }
                return (string) $last;
        }

        /**
         * Вычислить детерминированный хеш данных ОТДЕЛЬНОГО варианта.
         *
         * ВАЖНО: хешируем по ключам отсортированную строку — свойство одной
         * вариации (SKU, цена, остаток, атрибуты, картинки). Таким образом хеш
         * НЕ зависит от того в каком батче пришла строка: всегда одинаков для
         * одинакового набора полей. Это ключевое отличие от хеша «всего товара»,
         * который ломался когда варианты разбросаны по батчам.
         *
         * @param array $row Строка CSV (один вариант).
         * @return string md5-хеш вариации.
         */
        private function compute_variant_hash( $row ) {
                if ( empty( $row ) ) {
                        return '';
                }
                ksort( $row );
                return md5( serialize( $row ) );
        }

        /* ---------------------------------------------------------------------
         * Проверить, изменился ли товар с прошлого импорта.
         *
         * Стратегия (v2 — стабильна для батчей):
         *   В meta товара хранится карта: SKU вариации → её хеш
         *   (_bsi_data_hash). Сравниваем КАЖДУЮ вариацию из CSV с сохранённым
         *   хешем. Пока хеши всех вариаций совпадают — товар не изменился.
         *
         * Преимущества:
         *   - Стабильность: не зависит от того, какие варианты попали в батч
         *     (раньше хешировался весь товар — батч перезаписывал его частично,
         *     поэтому между сеансами хеши не совпадали и товар всегда «обновлялся»)
         *   - Никаких SQL-запросов к вариациям — только чтение одной meta
         *
         * @param array $variant_rows Массив строк CSV (варианты одного IGUArticolo).
         * @return bool true — товар не изменился, можно пропустить.
         * --------------------------------------------------------------------- */
        private function product_unchanged( $product_id, $variant_rows ) {
                if ( ! $product_id ) {
                        return false; // Новый товар — не пропускаем.
                }

                // ─── Быстрая проверка по карте хешей вариаций ──────────────
                $map = get_post_meta( $product_id, '_bsi_data_hash', true );
                if ( ! is_array( $map ) || empty( $map ) ) {
                        return false; // Нет данных прошлого импорта — считаем изменённым.
                }

                foreach ( $variant_rows as $row ) {
                        $sku = isset( $row['CodArticolo'] ) ? $row['CodArticolo'] : '';
                        if ( ! $sku ) {
                                return false; // Без SKU не можем сверить — обновляем.
                        }
                        $current_hash = $this->compute_variant_hash( $row );
                        if ( ! isset( $map[ $sku ] ) || $map[ $sku ] !== $current_hash ) {
                                return false; // Вариация изменилась или новая.
                        }
                }

                // ─── Проверка картинок (только если включено скачивание) ──────
                $settings = get_option( 'bsi_settings', array() );
                $download_images = ! isset( $settings['download_images'] ) || '1' === $settings['download_images'];

                if ( $download_images ) {
                        $first_row = isset( $variant_rows[0] ) ? $variant_rows[0] : array();
                        $has_csv_image = false;
                        for ( $i = 1; $i <= 10; $i++ ) {
                                if ( ! empty( $first_row[ 'URLImg' . $i ] ) ) {
                                        $has_csv_image = true;
                                        break;
                                }
                        }

                        if ( $has_csv_image ) {
                                $thumb_id = (int) get_post_thumbnail_id( $product_id );
                                if ( ! $thumb_id ) {
                                        return false; // Картинки нет — товар "изменён", нужно скачать.
                                }
                        }
                }

                // ─── Проверка meta _bsi_original_purchase ────────────────────
                // Если meta не сохранена (товар импортирован до v1.9.30) — считаем
                // товар изменённым, чтобы apply_pricing() сохранил meta и пересчитал
                // цену по правильной формуле (с floor_price).
                $original_purchase = get_post_meta( $product_id, '_bsi_original_purchase', true );
                if ( empty( $original_purchase ) ) {
                        return false; // Meta нет — нужно обновить (сохранит purchase и пересчитает цену).
                }

                // Все вариации совпали + картинки на месте + meta есть → товар не изменился.
                return true;
        }

        /**
         * Определить ПРИЧИНУ, почему товар считается изменённым.
         * Используется для диагностики: показывает в ленте/логах что именно
         * изменилось (нет карты, новая вариация, изменённая вариация, нет картинки).
         *
         * @param int   $product_id
         * @param array $variant_rows
         * @return string Удобочитаемая причина на русском.
         */
        private function unchanged_reason( $product_id, $variant_rows ) {
                $map = get_post_meta( $product_id, '_bsi_data_hash', true );
                if ( ! is_array( $map ) || empty( $map ) ) {
                        return 'нет карты хешей (первый импорт после обновления версии)';
                }

                foreach ( $variant_rows as $row ) {
                        $sku = isset( $row['CodArticolo'] ) ? $row['CodArticolo'] : '';
                        if ( ! $sku ) {
                                return 'вариант без SKU';
                        }
                        $current_hash = $this->compute_variant_hash( $row );
                        if ( ! isset( $map[ $sku ] ) ) {
                                return 'новая вариация: ' . $sku;
                        }
                        if ( $map[ $sku ] !== $current_hash ) {
                                return 'изменена вариация: ' . $sku;
                        }
                }

                // Если всё совпало — дошли сюда только из-за картинки.
                $settings = get_option( 'bsi_settings', array() );
                $download_images = ! isset( $settings['download_images'] ) || '1' === $settings['download_images'];
                if ( $download_images ) {
                        $first_row = isset( $variant_rows[0] ) ? $variant_rows[0] : array();
                        $has_csv_image = false;
                        for ( $i = 1; $i <= 10; $i++ ) {
                                if ( ! empty( $first_row[ 'URLImg' . $i ] ) ) {
                                        $has_csv_image = true;
                                        break;
                                }
                        }
                        if ( $has_csv_image ) {
                                if ( ! (int) get_post_thumbnail_id( $product_id ) ) {
                                        return 'нет основной картинки у товара';
                                }
                        }
                }

                return 'неизвестная причина';
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
                $discount     = isset( $row['Sconto'] ) ? (float) $row['Sconto'] : 0;

                // Сохраняем ОРИГИНАЛЬНЫЕ цены BeeStore в мете — нужно для кнопки
                // «Пересчитать цены», чтобы не зависеть от повторного импорта.
                if ( $price_gross > 0 ) {
                        $product->update_meta_data( '_bsi_original_price_gross', $price_gross );
                }
                if ( $discount > 0 ) {
                        $product->update_meta_data( '_bsi_original_discount', $discount );
                }
                // Закупочную цену сохраняем тоже — нужна для пересчёта.
                $pricing_inst = class_exists( 'BSI_Pricing' ) ? BSI_Pricing::instance() : null;
                if ( $pricing_inst ) {
                        $purchase = $pricing_inst->get_purchase_price( $row );
                        if ( $purchase > 0 ) {
                                $product->update_meta_data( '_bsi_original_purchase', $purchase );
                        }
                }

                // Новая логика: BSI_Pricing считает regular / old / new цены в ₽.
                $pricing = class_exists( 'BSI_Pricing' ) ? BSI_Pricing::instance() : null;
                $r       = false;
                if ( $pricing ) {
                        $r = $pricing->from_item( $row );
                        if ( is_array( $r ) ) {
                                $pricing->apply( $product, $r );
                        }
                }

                // Страховка (если BSI_Pricing недоступен или не сработал) —
                // оставляем прежнее поведение по PrezzoIvato напрямую.
                if ( false === $r ) {
                        $this->price_fallback_pricing( $product, $row );
                }
        }

        /**
         * Запасной путь если новая логика не дала результат.
         * Ставит обычную цену PrezzoIvato без конвертации (на случай сбоя).
         *
         * @param WC_Product $product
         * @param array      $row
         */
        private function price_fallback_pricing( $product, $row ) {
                $price_gross = isset( $row['PrezzoIvato'] ) ? (float) $row['PrezzoIvato'] : 0;
                if ( $price_gross > 0 ) {
                        $product->set_regular_price( wc_format_decimal( $price_gross, 2 ) );
                        $product->set_sale_price( '' );
                        $product->set_price( wc_format_decimal( $price_gross, 2 ) );
                }
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
                // Настройка: снимать с публикации (черновик) товары без картинок.
                // Включена по умолчанию (даже если ключа ещё нет в сохранённых настройках).
                $draft_no_image = ! isset( $settings['draft_no_image'] ) || '1' === $settings['draft_no_image'];
                // Это родитель (не вариация)?
                $is_parent = ( null === $parent_id );

                $image_urls = array();
                for ( $i = 1; $i <= 10; $i++ ) {
                        $key = 'URLImg' . $i;
                        if ( ! empty( $row[ $key ] ) ) {
                                $image_urls[] = $row[ $key ];
                        }
                }

                if ( empty( $image_urls ) ) {
                        // В CSV нет ссылок на фото.
                        // Если это родитель, опция вкл и скачивание вкл — делаем черновиком.
                        if ( $is_parent && $draft_no_image && $download_images ) {
                                $this->maybe_set_product_status( $product_id, 'draft' );
                        }
                        return false;
                }

                // Сохраняем ВСЕ URL картинок в meta — даже если не скачиваем.
                // Это позволит потом запустить "backfill" и докачать их,
                // когда Sirio разблокирует ваш IP на сервере картинок.
                update_post_meta( $product_id, '_bsi_image_urls', $image_urls );
                // Совместимость со старым полем — первая картинка.
                update_post_meta( $product_id, '_bsi_image_url', $image_urls[0] );

                // Если скачивание выключено — выходим, URL уже сохранены в meta.
                if ( ! $download_images ) {
                        return true;
                }

                // Первая картинка = featured, остальные — галерея.
                // FALLBACK: если первая (featured) URL недоступна — подставляем
                // следующую рабочую картинку как основную, а не пропускаем товар.
                $featured_url = array_shift( $image_urls );

                // Пробуем скачать featured; если не получилось — перебираем остальные.
                $thumb_id = 0;
                $attempts = array( $featured_url );
                if ( ! empty( $image_urls ) ) {
                        $attempts = array_merge( $attempts, $image_urls );
                }
                foreach ( $attempts as $candidate ) {
                        $aid = $this->attach_image( $candidate, $product_id, true );
                        if ( $aid ) {
                                $thumb_id = $aid;
                                // Запоминаем какой URL взяли как featured.
                                $featured_with_id = ( $candidate === $featured_url ) ? $featured_url : $candidate;
                                break;
                        }
                }

                if ( $thumb_id ) {
                        set_post_thumbnail( $product_id, $thumb_id );
                }

                // Галерея — остальные картинки (кроме той, что уже стала featured).
                $gallery_urls = array();
                // Начинаем с исходных image_urls (после array_shift оттуда уже убрали первую).
                // Но если featured взят НЕ из первой — исключаем использованный.
                foreach ( $image_urls as $url ) {
                        if ( $url === $featured_url || $url === ( isset( $featured_with_id ) ? $featured_with_id : '' ) ) {
                                continue;
                        }
                        $gallery_urls[] = $url;
                }

                $gallery_ids = array();
                foreach ( $gallery_urls as $url ) {
                        $attach_id = $this->attach_image( $url, $product_id, true );
                        if ( $attach_id ) {
                                $gallery_ids[] = $attach_id;
                        }
                }
                if ( ! empty( $gallery_ids ) ) {
                        update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
                }

                // Если это родитель, картинки есть (featured или галерея) и включена опция —
                // удостоверяемся что товар опубликован (если был черновиком).
                $has_image = (bool) $thumb_id || ! empty( $gallery_ids );
                if ( $is_parent && $draft_no_image ) {
                        if ( $has_image ) {
                                $this->maybe_set_product_status( $product_id, 'publish' );
                        } else {
                                $this->maybe_set_product_status( $product_id, 'draft' );
                        }
                }

                return $has_image;
        }

        /**
         * Безопасно обновить статус товара, не трогая даты публикации.
         *
         * @param int    $product_id
         * @param string $status        draft | publish
         */
        private function maybe_set_product_status( $product_id, $status ) {
                wp_update_post( array(
                        'ID'          => $product_id,
                        'post_status' => $status,
                ) );
        }

        /**
         * Синхронизировать статус товара по наличию картинок В ВЫГРУЗКЕ (CSV).
         * Используется для НЕИЗМЕНЁННЫХ товаров (пропущенных при импорте):
         *   - если в CSV у товара нет ни одного URLImg → черновик
         *   - если хотя бы один URLImg есть → публикуем (если был черновиком)
         *
         * @param int   $product_id  ID родителя.
         * @param array $variant_rows Строки CSV вариантов товара.
         */
        private function sync_visibility_by_images( $product_id, $variant_rows = array() ) {
                $settings = get_option( 'bsi_settings', array() );
                $download_images = ! isset( $settings['download_images'] ) || '1' === $settings['download_images'];
                // Включена по умолчанию.
                $draft_no_image  = ! isset( $settings['draft_no_image'] ) || '1' === $settings['draft_no_image'];

                if ( ! $download_images || ! $draft_no_image ) {
                        return; // Функция отключена.
                }

                // Ищем хоть одну картинку в строках CSV вариантов товара.
                $has_csv_image = false;
                foreach ( $variant_rows as $vr ) {
                        for ( $i = 1; $i <= 10; $i++ ) {
                                if ( ! empty( $vr[ 'URLImg' . $i ] ) ) {
                                        $has_csv_image = true;
                                        break 2;
                                }
                        }
                }

                $status = get_post_status( $product_id );

                if ( ! $has_csv_image && 'publish' === $status ) {
                        $this->maybe_set_product_status( $product_id, 'draft' );
                } elseif ( $has_csv_image && 'draft' === $status ) {
                        $this->maybe_set_product_status( $product_id, 'publish' );
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

                global $wpdb;

                // ════════════════════════════════════════════════════════════════════
                // MySQL ADVISORY LOCK — защищаем от race condition между
                // AJAX-импортом, WP-Cron и параллельными батчами.
                // GET_LOCK работает на уровне СЕССИИ — если сессия завершится
                // (PHP timeout) — лок автоматически снимется.
                // ════════════════════════════════════════════════════════════════════
                $lock_name = 'bsi_img_' . md5( $filename_without_ext );
                $lock_acquired = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 30 )', $lock_name ) );
                if ( 1 !== $lock_acquired ) {
                        // Кто-то другой держит лок — ждём и пробуем ещё раз.
                        // Если не удалось получить лок за 30 сек — возвращаем 0,
                        // чтобы не создавать дубль.
                        $this->log( 'warning', 'Не удалось получить лок для картинки', array(
                                'url'      => $url,
                                'basename' => $filename_without_ext,
                        ) );
                        return 0;
                }

                // ════════════════════════════════════════════════════════════════════
                // ДВОЙНАЯ ПРОВЕРКА после получения лока.
                // Важно: даже если проверка до лока ничего не нашла — между
                // проверкой и локом другой процесс мог создать attachment.
                // ════════════════════════════════════════════════════════════════════
                $existing_id = $this->find_existing_attachment_locked( $filename_without_ext, $url );
                if ( $existing_id ) {
                        $cache[ $cache_key ] = $existing_id;
                        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name ) );
                        return $existing_id;
                }

                if ( ! $download ) {
                        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name ) );
                        return 0;
                }

                // ════════════════════════════════════════════════════════════════════
                // СКАЧИВАНИЕ ВО ВРЕМЕННЫЙ ФАЙЛ (с повторными попытками).
                //
                // 3 попытки с паузой 2 секунды между ними — защита от флаповых
                // сетевых ошибок (таймаут Sirio, кратковременные сбои DNS и т.п.).
                // ════════════════════════════════════════════════════════════════════
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                $max_attempts = 3;
                $attempt      = 0;
                $tmp_file     = null;
                $last_error   = '';

                while ( $attempt < $max_attempts ) {
                        $attempt++;
                        $tmp_file = download_url( $url, 60 );
                        if ( ! is_wp_error( $tmp_file ) ) {
                                break; // Успешно скачано.
                        }

                        $last_error = $tmp_file->get_error_message();
                        $this->log( 'warning', 'Попытка скачать картинку не удалась', array(
                                'url'       => $url,
                                'attempt'   => $attempt,
                                'max'       => $max_attempts,
                                'error'     => $last_error,
                        ) );

                        if ( $attempt < $max_attempts ) {
                                // Пауза перед повторной попыткой.
                                usleep( 2000000 ); // 2 секунды.
                        }
                }

                if ( is_wp_error( $tmp_file ) ) {
                        $this->log( 'warning', 'Не удалось скачать картинку после ' . $max_attempts . ' попыток', array(
                                'url'   => $url,
                                'err'   => $last_error,
                        ) );
                        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name ) );
                        return false;
                }

                // Определяем реальное расширение файла по содержимому.
                $real_mime = wp_get_image_mime( $tmp_file );
                $ext_map = array(
                        'image/jpeg' => 'jpg',
                        'image/png'  => 'png',
                        'image/gif'  => 'gif',
                        'image/webp' => 'webp',
                );
                $ext = isset( $ext_map[ $real_mime ] ) ? $ext_map[ $real_mime ] : pathinfo( $basename, PATHINFO_EXTENSION );
                if ( ! $ext ) {
                        $ext = 'jpg';
                }

                // ════════════════════════════════════════════════════════════════════
                // ДЕТЕРМИНИРОВАННОЕ ИМЯ ФАЙЛА — НЕ wp_unique_filename, который
                // добавляет -1, -2 суффиксы. Если файл существует — перезапишем.
                // Это гарантия: один basename = один файл на диске.
                // ════════════════════════════════════════════════════════════════════
                $upload_dir = wp_upload_dir();
                $subdir     = $upload_dir['subdir'];
                $target_dir = $upload_dir['basedir'] . $subdir;
                if ( ! file_exists( $target_dir ) ) {
                        wp_mkdir_p( $target_dir );
                }
                $target_filename = $filename_without_ext . '.' . $ext;
                $target_path     = $target_dir . '/' . $target_filename;
                $target_url      = $upload_dir['baseurl'] . $subdir . '/' . $target_filename;
                $relative_path   = ltrim( $subdir . '/' . $target_filename, '/' );

                // Если файл на диске уже существует с тем же именем — заменяем его
                // свежескачанным (всё равно содержимое одинаковое для одного URL).
                if ( ! @rename( $tmp_file, $target_path ) ) {
                    // rename между дисками может не сработать — fallback на copy + unlink.
                    if ( @copy( $tmp_file, $target_path ) ) {
                        @unlink( $tmp_file );
                    } else {
                        @unlink( $tmp_file );
                        $this->log( 'warning', 'Не удалось переместить файл в uploads', array( 'url' => $url, 'target' => $target_path ) );
                        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name ) );
                        return false;
                    }
                }

                // ════════════════════════════════════════════════════════════════════
                // ПРОВЕРКА ПО _wp_attached_file — точное совпадение relative path.
                // Поскольку мы используем детерминированное имя, можно сравнивать
                // ТОЧНО, без LIKE и wildcard.
                // ════════════════════════════════════════════════════════════════════
                $existing_by_file = $wpdb->get_var( $wpdb->prepare(
                        "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_wp_attached_file'
                         AND pm.meta_value = %s
                         AND p.post_type = 'attachment'
                         AND p.post_status != 'trash'
                         LIMIT 1",
                        $relative_path
                ) );
                if ( $existing_by_file ) {
                        $attach_id = (int) $existing_by_file;
                        update_post_meta( $attach_id, '_bsi_image_url', $url );
                        update_post_meta( $attach_id, '_bsi_image_basename', $filename_without_ext );
                        update_post_meta( $attach_id, '_bsi_imported_by', 'beestore-integration' );
                        $cache[ $cache_key ] = $attach_id;
                        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name ) );
                        return $attach_id;
                }

                // ════════════════════════════════════════════════════════════════════
                // СОЗДАЁМ ATTACHMENT напрямую через wp_insert_attachment —
                // БЕЗ media_handle_sideload, БЕЗ wp_handle_sideload, БЕЗ wp_unique_filename.
                // Полный контроль над именем файла → никаких -1, -2 суффиксов.
                // ════════════════════════════════════════════════════════════════════
                $attach_data = array(
                        'post_mime_type' => $real_mime ? $real_mime : 'image/jpeg',
                        'guid'           => $target_url,
                        'post_parent'    => $product_id,
                        'post_title'     => 'BeeStore ' . $filename_without_ext,
                        'post_content'   => '',
                        'post_status'    => 'inherit',
                        'post_name'      => $filename_without_ext,
                );
                $attach_id = wp_insert_attachment( $attach_data, $target_path, $product_id );
                if ( is_wp_error( $attach_id ) || ! $attach_id ) {
                        $this->log( 'warning', 'wp_insert_attachment не удался', array(
                                'url'   => $url,
                                'path'  => $target_path,
                                'error' => is_wp_error( $attach_id ) ? $attach_id->get_error_message() : 'empty ID',
                        ) );
                        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name ) );
                        return false;
                }

                // Сохраняем meta ПЕРВЫМ делом — даже если wp_generate_attachment_metadata
                // упадёт по таймауту, attachment уже привязан и будет найден следующим импортом.
                // _wp_attached_file уже сохранён самим wp_insert_attachment().
                update_post_meta( $attach_id, '_bsi_image_url', $url );
                update_post_meta( $attach_id, '_bsi_image_basename', $filename_without_ext );
                update_post_meta( $attach_id, '_bsi_imported_by', 'beestore-integration' );

                // Генерируем метаданные (миниатюры и т.д.) — с защитой от ошибок.
                $metadata = wp_generate_attachment_metadata( $attach_id, $target_path );
                if ( is_array( $metadata ) && ! empty( $metadata ) ) {
                        wp_update_attachment_metadata( $attach_id, $metadata );
                }
                clean_attachment_cache( $attach_id );

                // WebP конвертация (опционально).
                $settings = get_option( 'bsi_settings', array() );
                $webp_enabled = isset( $settings['webp_enabled'] ) && '1' === $settings['webp_enabled'];
                if ( $webp_enabled ) {
                        $this->convert_attachment_to_webp( $attach_id );
                }

                $cache[ $cache_key ] = $attach_id;
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock_name ) );
                return $attach_id;
        }

        /**
         * Найти существующий attachment по basename ИЛИ URL — используется
         * ТОЛЬКО после получения MySQL GET_LOCK (чтобы быть уверенным что
         * другой процесс не создаст запись между проверкой и созданием).
         *
         * Проверяет:
         *  1. _bsi_image_basename (точное совпадение, без LIKE wildcards)
         *  2. _bsi_image_url (точный URL)
         *  3. _wp_attached_file (LIKE с ESCAPE — корректно экранирует _)
         *
         * @param string $basename  Filename без расширения.
         * @param string $url       Полный URL картинки.
         * @return int|false
         */
        private function find_existing_attachment_locked( $basename, $url ) {
                global $wpdb;

                // 1. Точное совпадение по _bsi_image_basename — самый быстрый и надёжный.
                $found = $wpdb->get_var( $wpdb->prepare(
                        "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_bsi_image_basename'
                         AND pm.meta_value = %s
                         AND p.post_type = 'attachment'
                         AND p.post_status != 'trash'
                         LIMIT 1",
                        $basename
                ) );
                if ( $found ) {
                        // Обновляем URL на случай если он изменился.
                        update_post_meta( (int) $found, '_bsi_image_url', $url );
                        return (int) $found;
                }

                // 2. Точное совпадение по URL.
                $found_url = $wpdb->get_var( $wpdb->prepare(
                        "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_bsi_image_url'
                         AND pm.meta_value = %s
                         AND p.post_type = 'attachment'
                         AND p.post_status != 'trash'
                         LIMIT 1",
                        $url
                ) );
                if ( $found_url ) {
                        update_post_meta( (int) $found_url, '_bsi_image_basename', $basename );
                        return (int) $found_url;
                }

                // 3. LIKE по _wp_attached_file с КОРРЕКТНЫМ экранированием.
                //    ВАЖНО: _ в SQL LIKE — это wildcard, который совпадает с любым
                //    символом. Мы используем esc_like + ESCAPE '|' — это работает
                //    на ВСЕХ хостингах, включая NO_BACKSLASH_ESCAPES mode
                //    (где \ НЕ является escape-символом в строках).
                //    Также добавляем / перед basename, чтобы НЕ совпасть с вложенными
                //    именами вида 2000019668213_1.jpg когда ищем 2000019668213.
                $esc_basename = str_replace( array( '\\', '%', '_' ), array( '\\\\', '\\%', '\\_' ), $basename );
                $like_pattern = '%/' . $esc_basename . '.%';
                $found_file = $wpdb->get_var( $wpdb->prepare(
                        "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_wp_attached_file'
                         AND pm.meta_value LIKE %s ESCAPE '\\\\'
                         AND p.post_type = 'attachment'
                         AND p.post_status != 'trash'
                         LIMIT 1",
                        $like_pattern
                ) );
                if ( $found_file ) {
                        update_post_meta( (int) $found_file, '_bsi_image_url', $url );
                        update_post_meta( (int) $found_file, '_bsi_image_basename', $basename );
                        update_post_meta( (int) $found_file, '_bsi_imported_by', 'beestore-integration' );
                        return (int) $found_file;
                }

                return false;
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

                // ─── Сброс статистики при новом запуске (offset = 0) ──────────
                // Если offset = 0 и прошлый статус не "paused" — это новый запуск.
                // Сбрасываем cumulative счётчики чтобы цифры начинались с нуля.
                if ( 0 === $offset && 'paused' !== $status ) {
                        delete_option( 'bsi_image_import_stats' );
                        $cumulative_fresh = array(
                                'downloaded' => 0,
                                'skipped'    => 0,
                                'failed'     => 0,
                        );
                        update_option( 'bsi_image_import_stats', $cumulative_fresh, false );
                }

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
                        // FALLBACK: если первая URL недоступна — пробуем следующие,
                        // первую рабочую назначаем основной, остальные идём в галерею.
                        $thumb_id = get_post_thumbnail_id( $product_id );
                        $used_featured = '';
                        $featured_got = false;

                        if ( ! $thumb_id ) {
                                foreach ( $urls as $candidate_url ) {
                                        $attach_id = $this->attach_image( $candidate_url, $product_id, true );
                                        if ( $attach_id ) {
                                                set_post_thumbnail( $product_id, $attach_id );
                                                $used_featured = $candidate_url;
                                                $featured_got  = true;
                                                $downloaded++;
                                                break;
                                        }
                                }
                                if ( ! $featured_got ) {
                                        $failed++;
                                        if ( count( $errors ) < 5 ) {
                                                $errors[] = sprintf( 'Product #%d: %s', $product_id, ( isset( $urls[0] ) ? $urls[0] : 'no url' ) );
                                        }
                                        // НЕ continue — галерея всё равно должна обрабатываться.
                                }
                        } else {
                                $skipped++;
                                $used_featured = $urls[0];
                        }

                        // Галерея (все картинки, кроме выбранной featured).
                        $gallery_urls  = array();
                        foreach ( $urls as $url ) {
                                if ( $url === $used_featured ) {
                                        continue;
                                }
                                $gallery_urls[] = $url;
                        }
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
                        $original_gross       = (float) $product->get_meta( '_bsi_original_price_gross' );
                        $original_purchase    = (float) $product->get_meta( '_bsi_original_purchase' ) > 0
                                ? (float) $product->get_meta( '_bsi_original_purchase' )
                                : 0;
                        $original_disc_pct = (float) $product->get_meta( '_bsi_original_discount' );

                        if ( $original_gross <= 0 ) {
                                $skipped++;
                                continue;
                        }

                        // Новая логика: пересчитываем через BSI_Pricing.
                        $pricing = class_exists( 'BSI_Pricing' ) ? BSI_Pricing::instance() : null;
                        if ( $pricing ) {
                                $s     = $pricing->get_settings();
                                $retail = $pricing->num( $original_gross );
                                // Закупку берём из meta. Если её нет (старый товар) — передаём 0,
                                // BSI_Pricing::calculate() сам обработает этот случай:
                                // floor_price = 0, base_price = retail_price (без подъёма).
                                $r = $pricing->calculate(
                                        $retail,
                                        $original_purchase,
                                        $original_disc_pct,
                                        $s['min_income'],
                                        $s['eur_rate']
                                );

                                $pricing->apply( $product, $r );
                        } else {
                                // Запасной старый путь (без конвертации) — ставим грубую цену.
                                $product->set_regular_price( wc_format_decimal( $original_gross, 2 ) );
                                $product->set_sale_price( '' );
                                $product->set_price( wc_format_decimal( $original_gross, 2 ) );
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

        /* ---------------------------------------------------------------------
         * Синхронизация остатков (отдельная от импорта каталога).
         *
         * Берёт инкрементальный CSV с FTP, для КАЖДОЙ строки обновляет остаток
         * (Disponibilita) у существующей вариации по SKU. Если вариации нет —
         * создаёт её (с атрибутами и картинкой). Быстро: не трогает товар целиком.
         * --------------------------------------------------------------------- */

        /**
         * CRON: синхронизация остатков.
         */
        public function cron_stock_sync() {
                $settings = get_option( 'bsi_settings', array() );
                $freq = isset( $settings['stock_sync_frequency'] ) ? $settings['stock_sync_frequency'] : 'disabled';
                if ( 'disabled' === $freq || ! $freq ) {
                        return;
                }
                $this->log( 'info', 'Cron: запуск синхронизации остатков' );
                $result = $this->run_stock_sync();
                $this->log( 'info', 'Синхронизация остатков завершена', $result );
        }

        /**
         * AJAX: начать синхронизацию остатков (скачивает CSV, сохраняет state).
         */
        public function ajax_stock_start() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                // Если остаточная синхронизация уже идёт — не запускаем новую.
                $st = get_option( 'bsi_stock_state', array() );
                $status = isset( $st['status'] ) ? $st['status'] : 'idle';
                if ( 'running' === $status ) {
                        wp_send_json_error( array( 'message' => __( 'Синхронизация остатков уже идёт.', 'beestore-integration' ) ) );
                }

                // Ищем CSV (тот же механизм, что и импорт каталога).
                $fetch = $this->get_stock_csv_item();
                if ( is_wp_error( $fetch ) ) {
                        wp_send_json_error( array( 'message' => $fetch->get_error_message() ) );
                }
                $csv_file = $fetch['csv_file'];
                $remote   = $fetch['remote_name'];

                // Считаем строки.
                $count_result = BSI_CSV_Parser::instance()->count_lines( $csv_file );
                $total_rows = max( 0, $count_result - 1 );

                $new_state = array(
                        'status'          => 'running',
                        'csv_file'        => $csv_file,
                        'remote_name'     => $remote,
                        'total_rows'      => $total_rows,
                        'processed_rows'  => 0,
                        'last_offset'     => 0,
                        'file_position'   => 0,
                        'pending_row'     => null,
                        'created_variations' => 0,
                        'updated_stock'   => 0,
                        'errors_count'    => 0,
                );
                update_option( 'bsi_stock_state', $new_state, false );

                $this->log( 'info', 'Синхронизация остатков: старт', array(
                        'file' => $remote,
                        'rows' => $total_rows,
                ) );

                wp_send_json_success( array(
                        'message' => sprintf( __( 'Синхронизация остатков запущена. Файл: %s, строк: %d', 'beestore-integration' ), $remote, $total_rows ),
                        'state'   => $new_state,
                ) );
        }

        /**
         * AJAX: обработать батч синхронизации остатков.
         */
        public function ajax_stock_process_batch() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $st = get_option( 'bsi_stock_state', array() );
                if ( empty( $st ) || 'running' !== ( isset( $st['status'] ) ? $st['status'] : '' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Синхронизация остатков не запущена.', 'beestore-integration' ) ) );
                }

                if ( empty( $st['csv_file'] ) || ! file_exists( $st['csv_file'] ) ) {
                        // Если CSV не найден — ищем свежий.
                        $fetch = $this->get_stock_csv_item();
                        if ( is_wp_error( $fetch ) ) {
                                wp_send_json_error( array( 'message' => $fetch->get_error_message() ) );
                        }
                        $st['csv_file'] = $fetch['csv_file'];
                        $st['total_rows'] = max( 0, BSI_CSV_Parser::instance()->count_lines( $fetch['csv_file'] ) - 1 );
                }

                // Открываем CSV, читаем батч.
                $handle = fopen( $st['csv_file'], 'rb' );
                if ( ! $handle ) {
                        wp_send_json_error( array( 'message' => 'Не удалось открыть CSV остатков' ) );
                }
                $bom = fread( $handle, 3 );
                if ( "\xEF\xBB\xBF" !== $bom ) { fseek( $handle, 0 ); }
                $headers = fgetcsv( $handle, 0, ',', '"' );
                if ( ! $headers ) { fclose( $handle ); wp_send_json_error( array( 'message' => 'Empty header' ) ); }
                $headers = array_map( 'trim', $headers );

                $file_pos = isset( $st['file_position'] ) ? (int) $st['file_position'] : 0;
                if ( $file_pos > 0 ) { fseek( $handle, $file_pos ); }

                $batch_size = 100;
                $batch_rows = array();
                while ( ! feof( $handle ) ) {
                        $raw = fgetcsv( $handle, 0, ',', '"' );
                        if ( false === $raw || null === $raw ) { break; }
                        if ( count( $raw ) < count( $headers ) ) { $raw = array_pad( $raw, count( $headers ), '' ); }
                        if ( count( $raw ) > count( $headers ) ) { $raw = array_slice( $raw, 0, count( $headers ) ); }
                        $batch_rows[] = array_combine( $headers, array_map( 'trim', $raw ) );
                        if ( count( $batch_rows ) >= $batch_size ) { break; }
                }
                $new_file_pos = ftell( $handle );
                fclose( $handle );

                if ( empty( $batch_rows ) ) {
                        // Конец файла — завершено.
                        $done_state = get_option( 'bsi_stock_state', array() );
                        $done_state['status'] = 'completed';
                        $done_state['processed_rows'] = isset( $done_state['total_rows'] ) ? (int) $done_state['total_rows'] : 0;
                        $done_state['file_position'] = 0;
                        update_option( 'bsi_stock_state', $done_state, false );
                        wp_send_json_success( array(
                                'message'  => __( 'Синхронизация остатков завершена!', 'beestore-integration' ),
                                'state'    => $done_state,
                                'finished' => true,
                        ) );
                }

                // Обрабатываем строки.
                $updated = 0;
                $created = 0;
                $errors  = 0;
                foreach ( $batch_rows as $row ) {
                        try {
                                $ret = $this->apply_stock_row( $row );
                                if ( 'created' === $ret ) { $created++; }
                                elseif ( 'updated' === $ret ) { $updated++; }
                                else { $errors++; }
                        } catch ( Exception $e ) {
                                $errors++;
                        }
                }

                $db_s = get_option( 'bsi_stock_state', array() );
                $db_s['processed_rows'] = ( isset( $db_s['processed_rows'] ) ? (int) $db_s['processed_rows'] : 0 ) + count( $batch_rows );
                $db_s['last_offset']    = ( isset( $db_s['last_offset'] ) ? (int) $db_s['last_offset'] : 0 ) + count( $batch_rows );
                $db_s['file_position']  = $new_file_pos;
                $db_s['updated_stock']  = ( isset( $db_s['updated_stock'] ) ? (int) $db_s['updated_stock'] : 0 ) + $updated;
                $db_s['started_variations'] = ( isset( $db_s['started_variations'] ) ? (int) $db_s['started_variations'] : 0 ) + $created;
                $db_s['errors_count']   = ( isset( $db_s['errors_count'] ) ? (int) $db_s['errors_count'] : 0 ) + $errors;
                update_option( 'bsi_stock_state', $db_s, false );

                wp_send_json_success( array(
                        'message'  => sprintf( __( 'Остатки: обработано %d, обновлено: %d, создано вариаций: %d, ошибок: %d', 'beestore-integration' ), count( $batch_rows ), $updated, $created, $errors ),
                        'state'    => $db_s,
                        'finished' => false,
                ) );
        }

        /**
         * Обновить остаток одной строки CSV: найти вариацию по SKU (или создать)
         * и обновить stock. Возвращает 'created' | 'updated' | 'skipped' | 'error'.
         *
         * @param array $row Строка CSV.
         * @return string
         */
        private function apply_stock_row( $row ) {
                $sku = isset( $row['CodArticolo'] ) ? $row['CodArticolo'] : '';
                $stock = isset( $row['Disponibilita'] ) ? (float) $row['Disponibilita'] : 0;
                if ( ! $sku ) {
                        return 'error';
                }

                // Ищем вариацию по SKU.
                $variation_id = $this->find_variation_by_sku_anywhere( $sku );
                if ( $variation_id ) {
                        $variation = wc_get_product( $variation_id );
                        if ( $variation && $variation instanceof WC_Product_Variation ) {
                                $variation->set_manage_stock( true );
                                $variation->set_stock_quantity( $stock );
                                $variation->set_stock_status( $stock > 0 ? 'instock' : 'outofstock' );
                                $variation->save();
                                return 'updated';
                        }
                        return 'error';
                }

                // Вариации нет — создаём через существующий механизм.
                // Нужен родитель: ищем по IGUArticolo.
                $igu = isset( $row['IGUArticolo'] ) ? $row['IGUArticolo'] : '';
                $parent_id = $igu ? $this->find_product_by_meta( '_bsi_igu_articolo', $igu ) : 0;
                if ( ! $parent_id ) {
                        return 'error'; // Нет родителя — нельзя создать.
                }
                $parent = wc_get_product( $parent_id );
                if ( ! $parent || ! ( $parent instanceof WC_Product_Variable ) ) {
                        return 'error';
                }
                $existing_vars = $parent->get_children();
                $new_var_id = $this->upsert_variation( $parent_id, $row, $existing_vars );
                if ( $new_var_id ) {
                        // Применяем картинку вариации.
                        $this->apply_images( $new_var_id, $row, $parent_id );
                        return 'created';
                }
                return 'error';
        }

        /**
         * Найти вариацию по SKU в любом товаре (для быстрой синхронизации остатков).
         * Возвращает ID вариации или 0.
         */
        private function find_variation_by_sku_anywhere( $sku ) {
                global $wpdb;
                $found = $wpdb->get_var( $wpdb->prepare(
                        "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                         WHERE pm.meta_key = '_sku' AND pm.meta_value = %s
                         AND p.post_type = 'product_variation'
                         AND p.post_status != 'trash'
                         LIMIT 1",
                        $sku
                ) );
                return $found ? (int) $found : 0;
        }

        /**
         * AJAX: остановка синхронизации остатков.
         */
        public function ajax_stock_stop() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }
                delete_option( 'bsi_stock_state' );
                wp_send_json_success( array( 'message' => __( 'Синхронизация остатков остановлена.', 'beestore-integration' ) ) );
        }

        /**
         * AJAX: статус синхронизации остатков.
         */
        public function ajax_stock_status() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }
                $s = get_option( 'bsi_stock_state', array() );
                $status = isset( $s['status'] ) ? $s['status'] : 'idle';
                $percent = isset( $s['total_rows'] ) && $s['total_rows'] > 0
                        ? round( ( ( isset( $s['processed_rows'] ) ? $s['processed_rows'] : 0 ) / $s['total_rows'] ) * 100, 1 )
                        : 0;
                wp_send_json_success( array(
                        'status' => $status,
                        'state'  => $s,
                        'percent' => $percent,
                ) );
        }

        /**
         * Получить CSV для импорта остатков (переиспользует логику каталога).
         *
         * @return array|WP_Error
         */
        private function get_stock_csv_item() {
                $upload_dir = wp_upload_dir();
                $beestore_dir = trailingslashit( $upload_dir['basedir'] ) . 'beestore';
                $dirs = array( 'downloads', 'extracted', 'processed', 'manual-downloads' );
                $csvs = array();
                foreach ( $dirs as $subdir ) {
                        $path = $beestore_dir . '/' . $subdir;
                        if ( is_dir( $path ) ) {
                                $csvs = array_merge( $csvs, glob( $path . '/*.csv' ), glob( $path . '/*/*.csv' ) );
                        }
                }
                if ( ! empty( $csvs ) ) {
                        usort( $csvs, function ( $a, $b ) { return filemtime( $b ) - filemtime( $a ); } );
                        return array( 'csv_file' => $csvs[0], 'remote_name' => basename( $csvs[0] ) );
                }
                return new WP_Error( 'bsi_stock_no_csv', __( 'Нет CSV-файла для синхронизации остатков.', 'beestore-integration' ) );
        }

        /**
         * Выполнить полную синхронизацию остатков из найденного CSV (для cron).
         *
         * @return array Статистика.
         */
        public function run_stock_sync() {
                $fetch = $this->get_stock_csv_item();
                if ( is_wp_error( $fetch ) ) {
                        return array( 'success' => false, 'error' => $fetch->get_error_message() );
                }

                $csv_file = $fetch['csv_file'];
                $handle = fopen( $csv_file, 'rb' );
                if ( ! $handle ) {
                        return array( 'success' => false, 'error' => 'Не удалось открыть CSV' );
                }
                $bom = fread( $handle, 3 );
                if ( "\xEF\xBB\xBF" !== $bom ) { fseek( $handle, 0 ); }
                $headers = fgetcsv( $handle, 0, ',', '"' );
                if ( ! $headers ) { fclose( $handle ); return array( 'success' => false, 'error' => 'Empty header' ); }
                $headers = array_map( 'trim', $headers );

                $updated = 0; $created = 0; $errors = 0; $processed = 0;

                while ( ! feof( $handle ) ) {
                        $raw = fgetcsv( $handle, 0, ',', '"' );
                        if ( false === $raw || null === $raw ) { break; }
                        if ( count( $raw ) < count( $headers ) ) { $raw = array_pad( $raw, count( $headers ), '' ); }
                        if ( count( $raw ) > count( $headers ) ) { $raw = array_slice( $raw, 0, count( $headers ) ); }
                        $row = array_combine( $headers, array_map( 'trim', $raw ) );
                        $processed++;
                        try {
                                $r = $this->apply_stock_row( $row );
                                if ( 'created' === $r ) { $created++; }
                                elseif ( 'updated' === $r ) { $updated++; }
                                else { $errors++; }
                        } catch ( Exception $e ) {
                                $errors++;
                        }
                }
                fclose( $handle );

                return array(
                        'success'   => true,
                        'processed' => $processed,
                        'updated'   => $updated,
                        'created'   => $created,
                        'errors'    => $errors,
                );
        }
}
