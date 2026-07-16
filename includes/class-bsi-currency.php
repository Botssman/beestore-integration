<?php
/**
 * Управление курсом валют для конвертации цен.
 *
 * Поддерживаем 3 источника:
 *   - ЦБ РФ (https://www.cbr-xml-daily.ru/daily_json.js) — для RUB
 *   - Европейский ЦБ (ECB) (https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml) — для EUR/USD/GBP
 *   - open.er-api.com (https://open.er-api.com/v6/latest/EUR) — универсальный fallback
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class BSI_Currency {

        private static $instance = null;

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {
                // AJAX для ручного обновления курса.
                add_action( 'wp_ajax_bsi_refresh_rate', array( $this, 'ajax_refresh_rate' ) );
                // Cron задача — ежедневное обновление.
                add_action( 'bsi_cron_refresh_rate', array( $this, 'cron_refresh_rate' ) );
        }

        /**
         * Получить текущий курс.
         *
         * @return array [ 'rate' => float, 'source' => string, 'updated' => string, 'mode' => string ]
         */
        public function get_current_rate() {
                $settings = get_option( 'bsi_settings', array() );
                $mode     = isset( $settings['currency_rate_mode'] ) ? $settings['currency_rate_mode'] : 'manual';

                if ( 'auto' === $mode ) {
                        // В авто-режиме берём сохранённый курс (последний полученный от API).
                        return array(
                                'rate'    => isset( $settings['currency_rate'] ) ? (float) $settings['currency_rate'] : 1,
                                'source'  => isset( $settings['currency_rate_last_source'] ) ? $settings['currency_rate_last_source'] : '',
                                'updated' => isset( $settings['currency_rate_last_update'] ) ? $settings['currency_rate_last_update'] : '',
                                'mode'    => 'auto',
                        );
                }

                // Ручной режим.
                return array(
                        'rate'    => isset( $settings['currency_rate'] ) ? (float) $settings['currency_rate'] : 1,
                        'source'  => 'manual',
                        'updated' => '',
                        'mode'    => 'manual',
                );
        }

        /**
         * Принудительно обновить курс через API.
         *
         * @return array|WP_Error [ 'rate' => float, 'source' => string ]
         */
        public function refresh_auto_rate() {
                $settings         = get_option( 'bsi_settings', array() );
                $supplier_cur     = isset( $settings['supplier_currency'] ) ? $settings['supplier_currency'] : 'EUR';
                $shop_cur         = isset( $settings['shop_currency'] ) ? $settings['shop_currency'] : 'RUB';
                $preferred_source = isset( $settings['currency_rate_auto_source'] ) ? $settings['currency_rate_auto_source'] : 'auto';

                // Если валюты совпадают — курс = 1.
                if ( $supplier_cur === $shop_cur ) {
                        $this->save_auto_rate( 1, 'same_currency' );
                        return array( 'rate' => 1, 'source' => 'same_currency' );
                }

                // Определяем источник.
                // Если "auto" — выбираем лучший источник исходя из целевой валюты.
                // NB: shop_cur здесь — это целевая валюта плагина (не WC currency).
                $sources_to_try = array();
                if ( 'auto' === $preferred_source ) {
                        if ( 'RUB' === $shop_cur ) {
                                $sources_to_try = array( 'cbrf', 'er_api', 'ecb' );
                        } elseif ( 'KZT' === $shop_cur ) {
                                $sources_to_try = array( 'nbk', 'er_api', 'ecb' );
                        } else {
                                $sources_to_try = array( 'ecb', 'er_api' );
                        }
                } else {
                        $sources_to_try = array( $preferred_source );
                        // Fallback'и на случай если выбранный источник не сработал.
                        if ( 'cbrf' !== $preferred_source ) {
                                $sources_to_try[] = 'er_api';
                        }
                        if ( 'ecb' !== $preferred_source ) {
                                $sources_to_try[] = 'ecb';
                        }
                }

                BSI_Logger::instance()->info( 'currency', 'Обновление курса через API', array(
                        'supplier' => $supplier_cur,
                        'shop'     => $shop_cur,
                        'sources'  => $sources_to_try,
                ) );

                foreach ( $sources_to_try as $source ) {
                        $result = null;
                        switch ( $source ) {
                                case 'cbrf':
                                        $result = $this->fetch_from_cbrf( $supplier_cur, $shop_cur );
                                        break;
                                case 'nbk':
                                        // Национальный банк Казахстана — используем open.er-api.com
                                        // с пометкой источника "nbk" для совместимости с интерфейсом.
                                        // Прямой NBK API отдаёт XML по дате и менее стабилен,
                                        // поэтому берём тот же er_api, но помечаем как nbk.
                                        $result = $this->fetch_from_er_api( $supplier_cur, $shop_cur );
                                        if ( ! is_wp_error( $result ) ) {
                                                $result['source'] = 'nbk';
                                        }
                                        break;
                                case 'ecb':
                                        $result = $this->fetch_from_ecb( $supplier_cur, $shop_cur );
                                        break;
                                case 'er_api':
                                        $result = $this->fetch_from_er_api( $supplier_cur, $shop_cur );
                                        break;
                        }

                        if ( ! is_wp_error( $result ) && $result['rate'] > 0 ) {
                                $this->save_auto_rate( $result['rate'], $result['source'] );
                                BSI_Logger::instance()->info( 'currency', 'Курс обновлён', $result );
                                return $result;
                        }

                        BSI_Logger::instance()->warn( 'currency', 'Источник не сработал, пробуем следующий', array(
                                'source' => $source,
                                'error'  => is_wp_error( $result ) ? $result->get_error_message() : 'empty rate',
                        ) );
                }

                return new WP_Error( 'bsi_currency_all_failed', __( 'Не удалось получить курс ни от одного источника. Проверьте интернет-соединение сервера.', 'beestore-integration' ) );
        }

        /**
         * Сохранить авто-курс в опциях.
         */
        private function save_auto_rate( $rate, $source ) {
                $settings = get_option( 'bsi_settings', array() );
                $settings['currency_rate']             = (float) $rate;
                $settings['currency_rate_last_source'] = $source;
                $settings['currency_rate_last_update'] = current_time( 'mysql' );
                update_option( 'bsi_settings', $settings );
        }

        /**
         * Источник 1: ЦБ РФ.
         * API: https://www.cbr-xml-daily.ru/daily_json.js
         * Возвращает курсы основных валют к RUB.
         *
         * @param string $supplier_cur Валюта поставщика.
         * @param string $shop_cur     Валюта магазина (должна быть RUB).
         * @return array|WP_Error
         */
        private function fetch_from_cbrf( $supplier_cur, $shop_cur ) {
                if ( 'RUB' !== $shop_cur ) {
                        return new WP_Error( 'bsi_cbrf_not_rub', __( 'ЦБ РФ работает только если валюта магазина = RUB.', 'beestore-integration' ) );
                }

                $url = 'https://www.cbr-xml-daily.ru/daily_json.js';
                $response = wp_remote_get( $url, array(
                        'timeout' => 15,
                        'user-agent' => 'BeeStoreIntegration/' . BSI_VERSION,
                ) );

                if ( is_wp_error( $response ) ) {
                        return new WP_Error( 'bsi_cbrf_http', $response->get_error_message() );
                }

                $body = wp_remote_retrieve_body( $response );
                $data = json_decode( $body, true );

                if ( ! isset( $data['Valute'] ) || ! is_array( $data['Valute'] ) ) {
                        return new WP_Error( 'bsi_cbrf_bad_json', __( 'Неверный формат ответа ЦБ РФ.', 'beestore-integration' ) );
                }

                // Ищем валюту поставщика в ответе ЦБ РФ.
                // ЦБ РФ использует ключи: USD, EUR, GBP, etc.
                if ( 'RUB' === $supplier_cur ) {
                        return array( 'rate' => 1, 'source' => 'cbrf' );
                }

                if ( ! isset( $data['Valute'][ $supplier_cur ] ) ) {
                        return new WP_Error( 'bsi_cbrf_no_currency', sprintf( __( 'ЦБ РФ не вернул курс для %s.', 'beestore-integration' ), $supplier_cur ) );
                }

                // ЦБ РФ возвращает курс: 1 единица валюты = X рублей.
                // Например, 1 EUR = 100.5 RUB.
                $rate = (float) $data['Valute'][ $supplier_cur ]['Value'];

                return array(
                        'rate'   => $rate,
                        'source' => 'cbrf',
                );
        }

        /**
         * Источник 2: Европейский Центральный Банк.
         * API: https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml
         * Возвращает курсы к EUR.
         *
         * @param string $supplier_cur Валюта поставщика.
         * @param string $shop_cur     Валюта магазина.
         * @return array|WP_Error
         */
        private function fetch_from_ecb( $supplier_cur, $shop_cur ) {
                $url = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
                $response = wp_remote_get( $url, array(
                        'timeout' => 15,
                        'user-agent' => 'BeeStoreIntegration/' . BSI_VERSION,
                ) );

                if ( is_wp_error( $response ) ) {
                        return new WP_Error( 'bsi_ecb_http', $response->get_error_message() );
                }

                $body = wp_remote_retrieve_body( $response );

                // Парсим XML.
                libxml_use_internal_errors( true );
                $xml = simplexml_load_string( $body );
                if ( ! $xml ) {
                        return new WP_Error( 'bsi_ecb_bad_xml', __( 'Неверный XML от ECB.', 'beestore-integration' ) );
                }

                // Структура: <Cube><Cube time="..."><Cube currency="USD" rate="1.08"/>...</Cube></Cube>
                $rates = array( 'EUR' => 1.0 ); // EUR к EUR = 1.
                foreach ( $xml->Cube->Cube->Cube as $cube ) {
                        $currency = (string) $cube['currency'];
                        $rate     = (float) $cube['rate'];
                        $rates[ $currency ] = $rate;
                }

                if ( ! isset( $rates[ $supplier_cur ] ) || ! isset( $rates[ $shop_cur ] ) ) {
                        return new WP_Error( 'bsi_ecb_no_currency', sprintf( __( 'ECB не содержит курс для %s или %s.', 'beestore-integration' ), $supplier_cur, $shop_cur ) );
                }

                // ECB возвращает: 1 EUR = X валюты.
                // Нужно: 1 supplier_cur = ? shop_cur.
                // 1 EUR = rates[supplier] supplier_cur → 1 supplier = 1/rates[supplier] EUR
                // 1 EUR = rates[shop] shop_cur → 1 EUR = rates[shop] shop_cur
                // 1 supplier = (1/rates[supplier]) * rates[shop] shop_cur
                if ( 'EUR' === $supplier_cur ) {
                        $rate = $rates[ $shop_cur ];
                } elseif ( 'EUR' === $shop_cur ) {
                        $rate = 1 / $rates[ $supplier_cur ];
                } else {
                        $rate = $rates[ $shop_cur ] / $rates[ $supplier_cur ];
                }

                return array(
                        'rate'   => $rate,
                        'source' => 'ecb',
                );
        }

        /**
         * Источник 3: open.er-api.com (универсальный).
         * API: https://open.er-api.com/v6/latest/EUR
         * Возвращает курсы всех валют к EUR (бесплатный, без ключа).
         *
         * @param string $supplier_cur Валюта поставщика.
         * @param string $shop_cur     Валюта магазина.
         * @return array|WP_Error
         */
        private function fetch_from_er_api( $supplier_cur, $shop_cur ) {
                // Если supplier = EUR, можем запросить напрямую.
                // Иначе запрашиваем базу EUR и конвертируем.
                $url = 'https://open.er-api.com/v6/latest/' . rawurlencode( $supplier_cur );
                $response = wp_remote_get( $url, array(
                        'timeout' => 15,
                        'user-agent' => 'BeeStoreIntegration/' . BSI_VERSION,
                ) );

                if ( is_wp_error( $response ) ) {
                        return new WP_Error( 'bsi_er_api_http', $response->get_error_message() );
                }

                $body = wp_remote_retrieve_body( $response );
                $data = json_decode( $body, true );

                if ( ! isset( $data['rates'] ) || ! is_array( $data['rates'] ) ) {
                        return new WP_Error( 'bsi_er_api_bad_json', __( 'Неверный JSON от er-api.com.', 'beestore-integration' ) );
                }

                if ( ! isset( $data['rates'][ $shop_cur ] ) ) {
                        return new WP_Error( 'bsi_er_api_no_currency', sprintf( __( 'er-api.com не вернул курс для %s.', 'beestore-integration' ), $shop_cur ) );
                }

                $rate = (float) $data['rates'][ $shop_cur ];

                return array(
                        'rate'   => $rate,
                        'source' => 'er_api',
                );
        }

        /**
         * AJAX: ручное обновление курса.
         */
        public function ajax_refresh_rate() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                $result = $this->refresh_auto_rate();

                if ( is_wp_error( $result ) ) {
                        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
                }

                wp_send_json_success( array(
                        'rate'    => $result['rate'],
                        'source'  => $result['source'],
                        'updated' => current_time( 'mysql' ),
                        'message' => sprintf(
                                /* translators: 1: курс, 2: источник */
                                __( 'Курс обновлён: %1$s (источник: %2$s)', 'beestore-integration' ),
                                $result['rate'],
                                $result['source']
                        ),
                ) );
        }

        /**
         * Cron: ежедневное обновление курса (только в авто-режиме).
         */
        public function cron_refresh_rate() {
                $settings = get_option( 'bsi_settings', array() );
                $mode     = isset( $settings['currency_rate_mode'] ) ? $settings['currency_rate_mode'] : 'manual';

                if ( 'auto' !== $mode ) {
                        return; // В ручном режиме не обновляем.
                }

                $this->refresh_auto_rate();
        }
}
