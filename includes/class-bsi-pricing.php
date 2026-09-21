<?php
/**
 * Расчёт розничных цен по данным импорта каталога поставщика.
 *
 * ВХОД (поля строки импорта):
 *   PrezzoIvato            — РРЦ в т.ч. НДС, €
 *   PrezzoImponibileNetto — цена со скидкой без НДС (закупка), €
 *   Sconto                — скидка поставщика, %
 *   IDArticolo            — ключ модели (одинаков для всех размеров)
 *   Taglia                — размер
 *
 * ВЫХОД:
 *   regular_price — обычная цена (без скидки)
 *   old_price     — старая цена до скидки (если скидка есть)
 *   new_price     — новая цена со скидкой (если скидка есть)
 *   discount      — размер скидки, % (0 — скидки нет)
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class BSI_Pricing {

        const VAT_RATE       = 0.22; // НДС на продажу.
        const STEP           = 5;   // шаг сетки скидок, %.
        const SUPPLIER_SHARE = 0.5; // доля скидки поставщика, уходящая клиенту.
        const RUB_ROUNDING   = 100; // округление рублёвой цены на ценнике.

        private static $instance = null;
        private static $options  = null;

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {}

        /**
         * Получить настройки расчёта цен (мин. доходность + курс евро).
         *
         * @return array [ 'min_income' => float, 'eur_rate' => float ]
         */
        public function get_settings() {
                if ( null === self::$options ) {
                        $this->load_settings();
                }
                return self::$options;
        }

        /**
         * Сбросить кэш настроек (после сохранения).
         */
        public function flush_settings_cache() {
                self::$options = null;
        }

        /**
         * Загрузить настройки из опции.
         */
        private function load_settings() {
                $opts = get_option( 'bsi_pricing', array() );
                self::$options = array(
                        'min_income' => isset( $opts['min_income'] ) ? (float) $opts['min_income'] : 0,
                        'eur_rate'   => isset( $opts['eur_rate'] ) ? (float) $opts['eur_rate'] : 100,
                );
        }

        /**
         * Приведение значения из импорта к числу.
         * Обрабатывает "811,48", "58%", "1 600", неразрывный пробел.
         */
        public function num( $value ) {
                if ( is_numeric( $value ) ) {
                        return (float) $value;
                }
                $value = str_replace( array( ' ', "\xC2\xA0", '%', '€' ), '', (string) $value );
                $value = str_replace( ',', '.', $value );
                return is_numeric( $value ) ? (float) $value : 0.0;
        }

        /**
         * Округление вниз до кратного шагу. 19,95 при шаге 5 даёт 15.
         */
        private function floor_to_step( $value, $step ) {
                return $value > 0 ? (int) ( floor( $value / $step ) * $step ) : 0;
        }

        /**
         * Извлечь закупочную цену (€) из строки импорта с fallback.
         * Приоритет: PrezzoImponibileNetto → Imponibile → CostoImponibileNetto.
         *
         * @param array $row Строка CSV.
         * @return float
         */
        public function get_purchase_price( $row ) {
                foreach ( array( 'PrezzoImponibileNetto', 'Imponibile', 'CostoImponibileNetto' ) as $key ) {
                        if ( isset( $row[ $key ] ) ) {
                                $val = $this->num( $row[ $key ] );
                                if ( $val > 0 ) {
                                        return $val;
                                }
                        }
                }
                return 0.0;
        }

        /**
         * Ядро расчёта. Входные суммы в евро, выходные в рублях.
         *
         * @param float $retail_price   РРЦ (PrezzoIvato), €
         * @param float $purchase_price закупка, €
         * @param float $supplier_discount Sconto, %
         * @param float $min_income     минимальная доходность, €
         * @param float $eur_rate       курс евро, ₽
         *
         * @return array
         */
        public function calculate( $retail_price, $purchase_price, $supplier_discount, $min_income, $eur_rate ) {
                // Пол цены: ниже неё доход упадёт меньше минимума.
                // ВАЖНО: если закупочная цена неизвестна (0) — пол не рассчитываем,
                // иначе floor_price = (0 + min_income) * 1.22 может оказаться выше РРЦ
                // и базовая цена необоснованно поднимется выше розницы.
                // В этом случае base_price = retail_price (без принудительного подъёма).
                if ( $purchase_price > 0 ) {
                        $floor_price = ( $purchase_price + $min_income ) * ( 1 + self::VAT_RATE );
                } else {
                        $floor_price = 0;
                }

                // Базовая цена.
                $base_price = max( $retail_price, $floor_price );

                // Два потолка скидки.
                $cap_supplier = $this->floor_to_step( $supplier_discount * self::SUPPLIER_SHARE, self::STEP );
                // Защита от деления на ноль: если floor_price = 0, cap_margin = 100%.
                $cap_margin = $base_price > 0
                        ? ( 1 - $floor_price / $base_price ) * 100
                        : 100;

                // Ступень скидки.
                $discount = $this->floor_to_step( min( $cap_supplier, $cap_margin ), self::STEP );
                if ( $discount < self::STEP ) {
                        $discount = 0;
                }

                // Перевод в рубли, округление только вверх.
                $base_rub = (int) ( ceil( $base_price * $eur_rate / self::RUB_ROUNDING ) * self::RUB_ROUNDING );

                if ( $discount > 0 ) {
                        $regular_price = null;
                        $old_price     = $base_rub;
                        $new_price     = (int) ( ceil( $base_rub * ( 1 - $discount / 100 ) / self::RUB_ROUNDING ) * self::RUB_ROUNDING );
                        $final_rub     = $new_price;
                } else {
                        $regular_price = $base_rub;
                        $old_price     = null;
                        $new_price     = null;
                        $final_rub     = $base_rub;
                }

                return array(
                        'regular_price' => $regular_price,
                        'old_price'     => $old_price,
                        'new_price'     => $new_price,
                        'discount'      => (int) $discount,
                        'net_income'    => round( $final_rub / $eur_rate / ( 1 + self::VAT_RATE ) - $purchase_price, 2 ),
                        'above_retail'  => $floor_price > $retail_price,
                );
        }

        /**
         * Расчёт по строке импорта.
         *
         * @return array|WP_Error (массив результата) или false если нет цены.
         */
        public function from_item( $item ) {
                $retail = $this->num( isset( $item['PrezzoIvato'] ) ? $item['PrezzoIvato'] : 0 );
                if ( $retail <= 0 ) {
                        return false;
                }
                $purchase = $this->get_purchase_price( $item );
                $discount = $this->num( isset( $item['Sconto'] ) ? $item['Sconto'] : 0 );
                $s        = $this->get_settings();

                return $this->calculate( $retail, $purchase, $discount, $s['min_income'], $s['eur_rate'] );
        }

        /**
         * Проставить цены товару или вариации.
         *
         * @param WC_Product $product
         * @param array      $r Результат calculate()
         */
        public function apply( $product, $r ) {
                $discount = (int) $r['discount'];

                if ( $discount > 0 && $r['new_price'] > 0 ) {
                        $product->set_regular_price( wc_format_decimal( $r['old_price'], 2 ) );
                        $product->set_sale_price( wc_format_decimal( $r['new_price'], 2 ) );
                        $product->set_price( wc_format_decimal( $r['new_price'], 2 ) );
                } else {
                        $product->set_regular_price( wc_format_decimal( $r['regular_price'], 2 ) );
                        $product->set_sale_price( '' );
                        $product->set_price( wc_format_decimal( $r['regular_price'], 2 ) );
                }

                $product->update_meta_data( '_pricing_discount', $discount );
                $product->update_meta_data( '_pricing_net_income', $r['net_income'] );
                $product->update_meta_data( '_pricing_above_retail', $r['above_retail'] ? 'yes' : 'no' );

                $product->save();
        }

        /**
         * Простой товар: одна строка импорта — один товар.
         *
         * @param int   $product_id
         * @param array $item Строка CSV.
         * @return array|false
         */
        public function apply_simple( $product_id, $item ) {
                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                        return false;
                }
                $r = $this->from_item( $item );
                if ( false === $r ) {
                        return false;
                }
                $this->apply( $product, $r );
                return $r;
        }

        /**
         * Вариативный товар.
         * Цена у модели одна на все размеры — считается один раз и проставляется
         * каждой вариации. Родителю цены не назначаем.
         *
         * @param int   $parent_id     ID вариативного товара.
         * @param array $items         Строки импорта одной модели.
         * @param array $variation_map ['размер' => variation_id].
         *
         * @return array|false
         */
        public function apply_variable( $parent_id, $items, $variation_map ) {
                if ( empty( $items ) ) {
                        return false;
                }
                $r    = $this->from_item( reset( $items ) );
                if ( false === $r ) {
                        return false;
                }
                $done = 0;

                foreach ( $items as $item ) {
                        $size = trim( (string) ( isset( $item['Taglia'] ) ? $item['Taglia'] : '' ) );
                        if ( empty( $variation_map[ $size ] ) ) {
                                continue;
                        }
                        $variation = wc_get_product( $variation_map[ $size ] );
                        if ( ! $variation ) {
                                continue;
                        }
                        $this->apply( $variation, $r );
                        $done++;
                }

                if ( $done > 0 ) {
                        WC_Product_Variable::sync( $parent_id );
                        wc_delete_product_transients( $parent_id );
                }

                $r['variations_done'] = $done;
                return $r;
        }

        /**
         * Группировка плоской выгрузки по моделям.
         * Строки с одинаковым IDArticolo — размеры одной модели.
         *
         * @param array $rows Строки импорта.
         * @return array [ IDArticolo => [ строки ] ]
         */
        public function group_items( $rows ) {
                $grouped = array();
                foreach ( (array) $rows as $row ) {
                        $key = trim( (string) ( isset( $row['IDArticolo'] ) ? $row['IDArticolo'] : '' ) );
                        if ( '' !== $key ) {
                                $grouped[ $key ][] = $row;
                        }
                }
                return $grouped;
        }
}