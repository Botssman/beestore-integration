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

        private function __construct() {
                // AJAX: предпросмотр количества товаров по выбранным фильтрам.
                add_action( 'wp_ajax_bsi_preview_filter_count', array( $this, 'ajax_preview_filter_count' ) );
        }

        /**
         * AJAX: посчитать сколько УНИКАЛЬНЫХ товаров (не строк!) будет импортировано
         * при текущих выбранных фильтрах. Использует данные сканирования.
         */
        public function ajax_preview_filter_count() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $scan = $this->get_scan_results();
                if ( ! $scan ) {
                        wp_send_json_error( array( 'message' => __( 'Сначала сканируйте CSV.', 'beestore-integration' ) ) );
                }

                // Получаем выбранные фильтры из POST.
                $mode = isset( $_POST['mode'] ) ? sanitize_text_field( wp_unslash( $_POST['mode'] ) ) : 'all';
                $cats = isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['categories'] ) ) : array();
                $brands = isset( $_POST['brands'] ) && is_array( $_POST['brands'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['brands'] ) ) : array();
                $genders = isset( $_POST['genders'] ) && is_array( $_POST['genders'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['genders'] ) ) : array();

                // Данные сканирования (строки).
                $scan_macro  = isset( $scan['macro'] ) ? $scan['macro'] : array();
                $scan_sub    = isset( $scan['sub'] ) ? $scan['sub'] : array();
                $scan_brands = isset( $scan['brands'] ) ? $scan['brands'] : array();
                $scan_genders = isset( $scan['genders'] ) ? $scan['genders'] : array();

                // Данные сканирования (уникальные товары).
                $macro_products  = isset( $scan['macro_products'] ) ? $scan['macro_products'] : array();
                $sub_products    = isset( $scan['sub_products'] ) ? $scan['sub_products'] : array();
                $brand_products  = isset( $scan['brand_products'] ) ? $scan['brand_products'] : array();
                $gender_products = isset( $scan['gender_products'] ) ? $scan['gender_products'] : array();

                // Считаем.
                $total_rows = 0;
                $total_products = 0;

                if ( 'all' === $mode ) {
                        // Все товары — считаем все строки и все уникальные IGU.
                        $total_rows = array_sum( $scan_macro );
                        $total_products = array_sum( $macro_products );
                } elseif ( 'whitelist' === $mode ) {
				// Только выбранные.
				// Логика: если тип фильтра НЕ выбран — не учитываем его (PHP_INT_MAX).
				// Если выбран — берём сумму по выбранным.
				// Если выбраны несколько типов — минимум (AND логика).

				$cat_products = 0;
				$cat_selected = ! empty( $cats );
				if ( $cat_selected ) {
					foreach ( $cats as $cat ) {
						if ( isset( $macro_products[ $cat ] ) ) {
							$cat_products += $macro_products[ $cat ];
						} elseif ( isset( $sub_products[ $cat ] ) ) {
							$cat_products += $sub_products[ $cat ];
						} elseif ( isset( $scan_macro[ $cat ] ) ) {
							$cat_products += $scan_macro[ $cat ];
						} elseif ( isset( $scan_sub[ $cat ] ) ) {
							$cat_products += $scan_sub[ $cat ];
						}
					}
				} else {
					$cat_products = PHP_INT_MAX;
				}

				$brand_products_count = 0;
				$brand_selected = ! empty( $brands );
				if ( $brand_selected ) {
					foreach ( $brands as $brand ) {
						if ( isset( $brand_products[ $brand ] ) ) {
							$brand_products_count += $brand_products[ $brand ];
						} elseif ( isset( $scan_brands[ $brand ] ) ) {
							$brand_products_count += $scan_brands[ $brand ];
						}
					}
				} else {
					$brand_products_count = PHP_INT_MAX;
				}

				$gender_products_count = 0;
				$gender_selected = ! empty( $genders );
				if ( $gender_selected ) {
					foreach ( $genders as $gender ) {
						if ( isset( $gender_products[ $gender ] ) ) {
							$gender_products_count += $gender_products[ $gender ];
						} elseif ( isset( $scan_genders[ $gender ] ) ) {
							$gender_products_count += $scan_genders[ $gender ];
						}
					}
				} else {
					$gender_products_count = PHP_INT_MAX;
				}

				// Если ничего не выбрано — все товары.
				if ( ! $cat_selected && ! $brand_selected && ! $gender_selected ) {
					$total_products = array_sum( $macro_products ) ?: array_sum( $scan_macro );
					$total_rows = array_sum( $scan_macro );
				} else {
					// Берём минимум из всех активных фильтров (AND логика).
					$total_products = min( $cat_products, $brand_products_count, $gender_products_count );
					if ( PHP_INT_MAX === $total_products ) {
						$total_products = array_sum( $macro_products ) ?: array_sum( $scan_macro );
					}
					$total_rows = $total_products * 3;
				}
			} elseif ( 'blacklist' === $mode ) {
                        // Все КРОМЕ выбранных.
                        $total_products = array_sum( $macro_products );
                        $total_rows = array_sum( $scan_macro );

                        // Вычитаем выбранные.
                        foreach ( $cats as $cat ) {
                                if ( isset( $macro_products[ $cat ] ) ) {
                                        $total_products -= $macro_products[ $cat ];
                                } elseif ( isset( $sub_products[ $cat ] ) ) {
                                        $total_products -= $sub_products[ $cat ];
                                }
                        }
                        foreach ( $brands as $brand ) {
                                if ( isset( $brand_products[ $brand ] ) ) {
                                        $total_products -= $brand_products[ $brand ];
                                }
                        }
                        foreach ( $genders as $gender ) {
                                if ( isset( $gender_products[ $gender ] ) ) {
                                        $total_products -= $gender_products[ $gender ];
                                }
                        }
                        $total_rows = $total_products * 3;
                }

                wp_send_json_success( array(
                        'products' => max( 0, $total_products ),
                        'rows'     => max( 0, $total_rows ),
                        'message'  => sprintf(
                                /* translators: 1: товаров, 2: строк */
                                __( 'Будет импортировано ~%1$d товаров (%2$d строк)', 'beestore-integration' ),
                                max( 0, $total_products ),
                                max( 0, $total_rows )
                        ),
                ) );
        }

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
                        'genders'    => isset( $settings['import_filter_genders'] ) && is_array( $settings['import_filter_genders'] ) ? $settings['import_filter_genders'] : array(),
                );
        }

        /**
         * Проверить, проходит ли товар через фильтр.
         *
         * @param string $category Имя категории (например 'CLOTHING').
         * @param string $brand    Имя бренда (например 'VERSACE').
         * @param string $gender   Пол (например 'WOMAN'). Опционально.
         * @return bool true — товар проходит фильтр, false — пропустить.
         */
        public function should_import( $category, $brand, $gender = '' ) {
                $filters = $this->get_settings();

                // Режим 'all' — импортировать всё.
                if ( 'all' === $filters['mode'] ) {
                        return true;
                }

                // Режим 'whitelist' — товар должен быть И в выбранной категории И в выбранном бренде.
                if ( 'whitelist' === $filters['mode'] ) {
                        $cat_selected   = ! empty( $filters['categories'] );
                        $brand_selected = ! empty( $filters['brands'] );
                        $gender_selected = ! empty( $filters['genders'] );

                        // Если ничего не выбрано — импортировать всё (как 'all').
                        if ( ! $cat_selected && ! $brand_selected && ! $gender_selected ) {
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

                        // Проверяем пол.
                        if ( $gender_selected ) {
                                if ( ! isset( $filters['genders'][ $gender ] ) ) {
                                        return false; // Пол не выбран.
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
                        // Если пол в чёрном списке — пропустить.
                        if ( $gender && isset( $filters['genders'][ $gender ] ) ) {
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
         * @param string $gender Опционально.
         */
        public function increment_counters( $category, $brand, $gender = '' ) {
                $counters = $this->get_counters();

                if ( ! isset( $counters['categories'][ $category ] ) ) {
                        $counters['categories'][ $category ] = 0;
                }
                $counters['categories'][ $category ]++;

                if ( ! isset( $counters['brands'][ $brand ] ) ) {
                        $counters['brands'][ $brand ] = 0;
                }
                $counters['brands'][ $brand ]++;

                if ( $gender ) {
                        if ( ! isset( $counters['genders'][ $gender ] ) ) {
                                $counters['genders'][ $gender ] = 0;
                        }
                        $counters['genders'][ $gender ]++;
                }

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
                                        'genders'    => array(),
                                );
                        }
                        if ( ! isset( $this->counters['categories'] ) ) {
                                $this->counters['categories'] = array();
                        }
                        if ( ! isset( $this->counters['brands'] ) ) {
                                $this->counters['brands'] = array();
                        }
                        if ( ! isset( $this->counters['genders'] ) ) {
                                $this->counters['genders'] = array();
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
                        'genders'    => array(),
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
                        'genders'   => array(),
                        'igu_seen'  => array(
                                'macro'  => array(),
                                'sub'    => array(),
                                'brand'  => array(),
                                'gender' => array(),
                        ),
                        'macro_products'  => array(),
                        'sub_products'    => array(),
                        'brand_products'  => array(),
                        'gender_products' => array(),
                        'scanning'  => true,
                );
                update_option( 'bsi_scan_state', $state, false );
        }

        /**
         * Сканировать один батч CSV (5000 строк).
         * Использует fgets() + str_getcsv() — без буферизации, ftell() работает точно.
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

                // Восстанавливаем позицию файла.
                $file_pos = isset( $state['file_pos'] ) ? (int) $state['file_pos'] : 0;
                fseek( $handle, $file_pos );

                // Если это первый батч (позиция 0) — читаем заголовок.
                $headers = isset( $state['headers'] ) ? $state['headers'] : null;
                if ( null === $headers ) {
                        $header_line = fgets( $handle, 1048576 );
                        if ( false === $header_line ) {
                                fclose( $handle );
                                return new WP_Error( 'bsi_no_headers', 'Не удалось прочитать заголовок CSV.' );
                        }
                        // Убираем BOM если есть.
                        $header_line = preg_replace( '/^\xEF\xBB\xBF/', '', $header_line );
                        $header_line = trim( $header_line );
                        $headers = str_getcsv( $header_line, ',', '"' );
                        $headers = array_map( 'trim', $headers );
                }

                $batch  = 5000;
                $count  = 0;

                $macro  = isset( $state['macro'] ) ? $state['macro'] : array();
                $sub    = isset( $state['sub'] ) ? $state['sub'] : array();
                $brands = isset( $state['brands'] ) ? $state['brands'] : array();
                $genders = isset( $state['genders'] ) ? $state['genders'] : array();
                // Уникальные IGUArticolo для подсчёта товаров (а не строк).
                $igu_seen = isset( $state['igu_seen'] ) ? $state['igu_seen'] : array(
                        'macro'  => array(),
                        'sub'    => array(),
                        'brand'  => array(),
                        'gender' => array(),
                );
                // Счётчики товаров (а не строк).
                $macro_products  = isset( $state['macro_products'] ) ? $state['macro_products'] : array();
                $sub_products    = isset( $state['sub_products'] ) ? $state['sub_products'] : array();
                $brand_products  = isset( $state['brand_products'] ) ? $state['brand_products'] : array();
                $gender_products = isset( $state['gender_products'] ) ? $state['gender_products'] : array();

                while ( ! feof( $handle ) && $count < $batch ) {
                        $line = fgets( $handle, 1048576 );
                        if ( false === $line || '' === trim( $line ) ) {
                                continue;
                        }

                        $row = str_getcsv( $line, ',', '"' );
                        if ( false === $row || count( $row ) < 5 ) {
                                continue;
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

                        // Пол (Sesso / DSSesso).
                        $gender = '';
                        if ( ! empty( $row_data['DSSessoWeb'] ) ) {
                                $gender = $row_data['DSSessoWeb'];
                        } elseif ( ! empty( $row_data['DSSesso'] ) ) {
                                $gender = $row_data['DSSesso'];
                        }
                        if ( $gender ) {
                                if ( ! isset( $genders[ $gender ] ) ) {
                                        $genders[ $gender ] = 0;
                                }
                                $genders[ $gender ]++;
                        }

                        // Подсчёт уникальных товаров (IGUArticolo) для каждого фильтра.
                        $igu = isset( $row_data['IGUArticolo'] ) ? trim( $row_data['IGUArticolo'] ) : '';
                        if ( $igu ) {
                                $igu_key = $igu;
                                // Мacro: считаем уникальные IGU для каждой макро-категории.
                                if ( $macro_name && ! isset( $igu_seen['macro'][ $macro_name . '|' . $igu ] ) ) {
                                        $igu_seen['macro'][ $macro_name . '|' . $igu ] = true;
                                        if ( ! isset( $macro_products[ $macro_name ] ) ) {
                                                $macro_products[ $macro_name ] = 0;
                                        }
                                        $macro_products[ $macro_name ]++;
                                }
                                // Sub: уникальные для подкатегории.
                                if ( $sub_name && ! isset( $igu_seen['sub'][ $sub_name . '|' . $igu ] ) ) {
                                        $igu_seen['sub'][ $sub_name . '|' . $igu ] = true;
                                        if ( ! isset( $sub_products[ $sub_name ] ) ) {
                                                $sub_products[ $sub_name ] = 0;
                                        }
                                        $sub_products[ $sub_name ]++;
                                }
                                // Brand: уникальные для бренда.
                                if ( $brand && ! isset( $igu_seen['brand'][ $brand . '|' . $igu ] ) ) {
                                        $igu_seen['brand'][ $brand . '|' . $igu ] = true;
                                        if ( ! isset( $brand_products[ $brand ] ) ) {
                                                $brand_products[ $brand ] = 0;
                                        }
                                        $brand_products[ $brand ]++;
                                }
                                // Gender: уникальные для пола.
                                if ( $gender && ! isset( $igu_seen['gender'][ $gender . '|' . $igu ] ) ) {
                                        $igu_seen['gender'][ $gender . '|' . $igu ] = true;
                                        if ( ! isset( $gender_products[ $gender ] ) ) {
                                                $gender_products[ $gender ] = 0;
                                        }
                                        $gender_products[ $gender ]++;
                                }
                        }
                }

                // Сохраняем позицию файла (fgets не буферизует — ftell точный).
                $new_pos = ftell( $handle );
                $at_eof  = feof( $handle );
                fclose( $handle );

                $state['file_pos']  = $new_pos;
                $state['headers']   = $headers;
                $state['processed'] = ( isset( $state['processed'] ) ? (int) $state['processed'] : 0 ) + $count;
                $state['macro']     = $macro;
                $state['sub']       = $sub;
                $state['brands']    = $brands;
                $state['genders']   = $genders;
                $state['igu_seen']  = $igu_seen;
                $state['macro_products']  = $macro_products;
                $state['sub_products']    = $sub_products;
                $state['brand_products']  = $brand_products;
                $state['gender_products'] = $gender_products;

                $done = $at_eof || ( 0 === $count );
                $state['scanning'] = ! $done;

                update_option( 'bsi_scan_state', $state, false );

                return array(
                        'done'      => $done,
                        'processed' => $state['processed'],
                        'total'     => (int) $state['total'],
                        'macro'     => count( $macro ),
                        'sub'       => count( $sub ),
                        'brands'    => count( $brands ),
                        'genders'   => count( $genders ),
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
