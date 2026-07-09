<?php
/**
 * Фильтры импорта — выбор категорий и брендов с лимитами.
 *
 * Логика:
 *   - mode = 'all'         → импортировать всё, без фильтров
 *   - mode = 'whitelist'   → импортировать ТОЛЬКО выбранные категории И бренды (AND)
 *   - mode = 'blacklist'   → импортировать ВСЕ, КРОМЕ выбранных
 *
 * Лимиты:
 *   - limit = 0  → без лимита (все товары из категории/бренда)
 *   - limit = N  → максимум N родительских товаров из категории/бренда
 *
 * Лимиты считаются по РОДИТЕЛЬСКИМ товарам (IGUArticolo), не по вариациям.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class BSI_Import_Filters {

        private static $instance = null;

        /**
         * Счётчики импортированных товаров по категориям и брендам.
         * Хранятся в опции на время импорта.
         *
         * @var array
         */
        private $counters = null;

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {}

        /**
         * Получить настройки фильтров.
         *
         * @return array
         */
        public function get_settings() {
                $settings = get_option( 'bsi_settings', array() );
                return array(
                        'mode'       => isset( $settings['import_filter_mode'] ) ? $settings['import_filter_mode'] : 'all',
                        'categories' => isset( $settings['import_filter_categories'] ) && is_array( $settings['import_filter_categories'] ) ? $settings['import_filter_categories'] : array(),
                        'brands'     => isset( $settings['import_filter_brands'] ) && is_array( $settings['import_filter_brands'] ) ? $settings['import_filter_brands'] : array(),
                );
        }

        /**
         * Проверить, проходит ли товар через фильтр.
         *
         * @param string $category Имя категории (например 'CLOTHING').
         * @param string $brand    Имя бренда (например 'VERSACE').
         * @return bool true — товар проходит фильтр, false — пропустить.
         */
        public function should_import( $category, $brand ) {
                $filters = $this->get_settings();

                // Режим 'all' — импортировать всё.
                if ( 'all' === $filters['mode'] ) {
                        return true;
                }

                // Режим 'whitelist' — товар должен быть И в выбранной категории И в выбранном бренде.
                if ( 'whitelist' === $filters['mode'] ) {
                        $cat_selected = ! empty( $filters['categories'] );
                        $brand_selected = ! empty( $filters['brands'] );

                        // Если ничего не выбрано — импортировать всё (как 'all').
                        if ( ! $cat_selected && ! $brand_selected ) {
                                return true;
                        }

                        // Проверяем категорию.
                        if ( $cat_selected ) {
                                if ( ! isset( $filters['categories'][ $category ] ) ) {
                                        return false; // Категория не выбрана.
                                }
                        }

                        // Проверяем бренд.
                        if ( $brand_selected ) {
                                if ( ! isset( $filters['brands'][ $brand ] ) ) {
                                        return false; // Бренд не выбран.
                                }
                        }

                        // Проверяем лимиты.
                        $counters = $this->get_counters();

                        if ( $cat_selected ) {
                                $cat_limit = (int) $filters['categories'][ $category ];
                                $cat_count = isset( $counters['categories'][ $category ] ) ? $counters['categories'][ $category ] : 0;
                                if ( $cat_limit > 0 && $cat_count >= $cat_limit ) {
                                        return false; // Лимит категории исчерпан.
                                }
                        }

                        if ( $brand_selected ) {
                                $brand_limit = (int) $filters['brands'][ $brand ];
                                $brand_count = isset( $counters['brands'][ $brand ] ) ? $counters['brands'][ $brand ] : 0;
                                if ( $brand_limit > 0 && $brand_count >= $brand_limit ) {
                                        return false; // Лимит бренда исчерпан.
                                }
                        }

                        return true;
                }

                // Режим 'blacklist' — импортировать всё, КРОМЕ выбранных.
                if ( 'blacklist' === $filters['mode'] ) {
                        // Если категория в чёрном списке — пропустить.
                        if ( isset( $filters['categories'][ $category ] ) ) {
                                return false;
                        }
                        // Если бренд в чёрном списке — пропустить.
                        if ( isset( $filters['brands'][ $brand ] ) ) {
                                return false;
                        }
                        return true;
                }

                return true;
        }

        /**
         * Зафиксировать, что товар импортирован (увеличить счётчики).
         *
         * @param string $category
         * @param string $brand
         */
        public function increment_counters( $category, $brand ) {
                $counters = $this->get_counters();

                if ( ! isset( $counters['categories'][ $category ] ) ) {
                        $counters['categories'][ $category ] = 0;
                }
                $counters['categories'][ $category ]++;

                if ( ! isset( $counters['brands'][ $brand ] ) ) {
                        $counters['brands'][ $brand ] = 0;
                }
                $counters['brands'][ $brand ]++;

                $this->save_counters( $counters );
        }

        /**
         * Получить счётчики из БД.
         *
         * @return array
         */
        private function get_counters() {
                if ( null === $this->counters ) {
                        $this->counters = get_option( 'bsi_import_counters', array() );
                        if ( ! is_array( $this->counters ) ) {
                                $this->counters = array(
                                        'categories' => array(),
                                        'brands'     => array(),
                                );
                        }
                        if ( ! isset( $this->counters['categories'] ) ) {
                                $this->counters['categories'] = array();
                        }
                        if ( ! isset( $this->counters['brands'] ) ) {
                                $this->counters['brands'] = array();
                        }
                }
                return $this->counters;
        }

        /**
         * Сохранить счётчики в БД.
         *
         * @param array $counters
         */
        private function save_counters( $counters ) {
                $this->counters = $counters;
                update_option( 'bsi_import_counters', $counters, false );
        }

        /**
         * Сбросить счётчики (при старте нового импорта).
         */
        public function reset_counters() {
                $this->counters = array(
                        'categories' => array(),
                        'brands'     => array(),
                );
                delete_option( 'bsi_import_counters' );
        }

        /**
         * Инициализация сканирования CSV.
         * Сохраняет путь к файлу и сбрасывает результаты.
         *
         * @param string $csv_file
         */
        public function init_scan( $csv_file ) {
                $state = array(
                        'file'      => $csv_file,
                        'file_pos'  => 0,
                        'total'     => BSI_CSV_Parser::instance()->count_lines( $csv_file ),
                        'processed' => 0,
                        'macro'     => array(),
                        'sub'       => array(),
                        'brands'    => array(),
                        'scanning'  => true,
                );
                update_option( 'bsi_scan_state', $state, false );
        }

        /**
         * Сканировать один батч CSV (5000 строк).
         * Использует fseek() для прямого позиционирования — НЕ читает файл с начала!
         *
         * @return array|WP_Error
         */
        public function scan_batch() {
                $state = get_option( 'bsi_scan_state', array() );
                if ( empty( $state['file'] ) || ! file_exists( $state['file'] ) ) {
                        return new WP_Error( 'bsi_no_file', 'CSV файл не найден.' );
                }

                $handle = fopen( $state['file'], 'rb' );
                if ( ! $handle ) {
                        return new WP_Error( 'bsi_open', 'Не удалось открыть CSV.' );
                }

                // Пропускаем BOM.
                $bom = fread( $handle, 3 );
                if ( "\xEF\xBB\xBF" !== $bom ) {
                        fseek( $handle, 0 );
                }

                // Восстанавливаем позицию файла (быстрое позиционирование через fseek).
                $file_pos = isset( $state['file_pos'] ) ? (int) $state['file_pos'] : 0;
                if ( $file_pos > 0 ) {
                        fseek( $handle, $file_pos );
                } else {
                        // Первая итерация — читаем заголовок.
                        $headers = fgetcsv( $handle, 0, ',', '"' );
                        if ( ! $headers ) {
                                fclose( $handle );
                                return new WP_Error( 'bsi_no_headers', 'Не удалось прочитать заголовок CSV.' );
                        }
                        $headers = array_map( 'trim', $headers );
                }

                $batch  = 5000;
                $count  = 0;

                $macro  = isset( $state['macro'] ) ? $state['macro'] : array();
                $sub    = isset( $state['sub'] ) ? $state['sub'] : array();
                $brands = isset( $state['brands'] ) ? $state['brands'] : array();

                while ( ! feof( $handle ) && $count < $batch ) {
                        $row = fgetcsv( $handle, 0, ',', '"' );
                        if ( false === $row || null === $row ) {
                                break;
                        }

                        // Ассоциативный массив.
                        $row_data = array();
                        foreach ( $headers as $i => $h ) {
                                $row_data[ $h ] = isset( $row[ $i ] ) ? trim( $row[ $i ] ) : '';
                        }

                        $count++;

                        // Макро-категория.
                        $macro_name = '';
                        if ( ! empty( $row_data['DSRepartoWeb'] ) ) {
                                $macro_name = $row_data['DSRepartoWeb'];
                        } elseif ( ! empty( $row_data['DSReparto'] ) ) {
                                $macro_name = $row_data['DSReparto'];
                        }
                        if ( $macro_name ) {
                                if ( ! isset( $macro[ $macro_name ] ) ) {
                                        $macro[ $macro_name ] = 0;
                                }
                                $macro[ $macro_name ]++;
                        }

                        // Подкатегория.
                        $sub_name = '';
                        if ( ! empty( $row_data['DSCategoriaMerceologicaWeb'] ) ) {
                                $sub_name = $row_data['DSCategoriaMerceologicaWeb'];
                        } elseif ( ! empty( $row_data['DSCategoriaMerceologica'] ) ) {
                                $sub_name = $row_data['DSCategoriaMerceologica'];
                        }
                        if ( $sub_name ) {
                                if ( ! isset( $sub[ $sub_name ] ) ) {
                                        $sub[ $sub_name ] = array( 'count' => 0, 'parent' => $macro_name );
                                }
                                $sub[ $sub_name ]['count']++;
                                if ( $macro_name && empty( $sub[ $sub_name ]['parent'] ) ) {
                                        $sub[ $sub_name ]['parent'] = $macro_name;
                                }
                        }

                        // Бренд.
                        $brand = '';
                        if ( ! empty( $row_data['DSLinea'] ) ) {
                                $brand = $row_data['DSLinea'];
                        } elseif ( ! empty( $row_data['RaggruppamentoLinea'] ) ) {
                                $brand = $row_data['RaggruppamentoLinea'];
                        }
                        if ( $brand ) {
                                if ( ! isset( $brands[ $brand ] ) ) {
                                        $brands[ $brand ] = 0;
                                }
                                $brands[ $brand ]++;
                        }
                }

                // Сохраняем позицию файла для следующего батча.
                $new_pos = ftell( $handle );
                $at_eof  = feof( $handle );
                fclose( $handle );

                $state['file_pos']  = $new_pos;
                $state['processed'] = ( isset( $state['processed'] ) ? (int) $state['processed'] : 0 ) + $count;
                $state['macro']     = $macro;
                $state['sub']       = $sub;
                $state['brands']    = $brands;

                $done = $at_eof || ( 0 === $count );
                $state['scanning'] = ! $done;

                update_option( 'bsi_scan_state', $state, false );

                return array(
                        'done'      => $done,
                        'processed' => $state['processed'],
                        'total'     => (int) $state['total'],
                );
        }

        /**
         * Получить результаты сканирования.
         *
         * @return array|false
         */
        public function get_scan_results() {
                $state = get_option( 'bsi_scan_state', array() );
                if ( empty( $state ) || ! empty( $state['scanning'] ) ) {
                        return false;
                }
                return $state;
        }
}
