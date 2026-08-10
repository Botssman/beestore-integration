<?php
/**
 * Автообновление плагина из GitHub.
 *
 * Базируется на YahnisLabs/github-updater.
 * Проверяет release на GitHub при заходе в админку и предлагает
 * стандартное уведомление «Доступно обновление» в WordPress.
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class BSI_GitHub_Updater {

        private $slug;
        private $plugin_file;
        private $version;
        private $github_repo = 'Botssman/beestore-integration';
        private $api_url     = 'https://api.github.com/repos/Botssman/beestore-integration';
        private $raw_url     = 'https://raw.githubusercontent.com/Botssman/beestore-integration/main';
        private $access_token = ''; // Опционально: для приватных репозиториев.

        public function __construct() {
                $this->plugin_file = BSI_PLUGIN_FILE;
                $this->slug        = dirname( BSI_PLUGIN_BASENAME ); // 'beestore-integration' (папка).
                $this->basename    = BSI_PLUGIN_BASENAME;            // 'beestore-integration/beestore-integration.php'.
                $this->version     = BSI_VERSION;

                // Опционально: GitHub Personal Access Token для приватных репозиториев.
                // Берётся из wp-config.php (define('BSI_GITHUB_TOKEN', 'xxx')) или из опции.
                if ( defined( 'BSI_GITHUB_TOKEN' ) && BSI_GITHUB_TOKEN ) {
                        $this->access_token = BSI_GITHUB_TOKEN;
                } else {
                        $settings = get_option( 'bsi_settings', array() );
                        if ( ! empty( $settings['github_token'] ) ) {
                                $this->access_token = $settings['github_token'];
                        }
                }

                add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
                add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

                // AJAX: принудительная проверка обновления (для кнопки в админке).
                add_action( 'wp_ajax_bsi_check_github_update', array( $this, 'ajax_check_update' ) );
        }

        /**
         * AJAX: Принудительно проверить обновление через GitHub API.
         * Минуем кеш transient и кеш WP — напрямую дёргаем GitHub.
         * Очищаем transient update_plugins и заполняем заново.
         */
        public function ajax_check_update() {
                check_ajax_referer( 'bsi_admin_nonce', 'nonce' );
                if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        wp_send_json_error( array( 'message' => __( 'Недостаточно прав.', 'beestore-integration' ) ) );
                }

                // Очищаем кеш transient'ов.
                delete_transient( 'bsi_github_latest_release' );
                delete_transient( 'bsi_github_readme' );
                delete_site_transient( 'update_plugins' );

                // Принудительно дёргаем GitHub API.
                $release = $this->get_latest_release();

                if ( is_wp_error( $release ) ) {
                        wp_send_json_error( array(
                                'message' => sprintf(
                                    /* translators: %s — сообщение об ошибке */
                                    __( 'Ошибка GitHub API: %s', 'beestore-integration' ),
                                    $release->get_error_message()
                                ),
                                'error_code' => $release->get_error_code(),
                        ) );
                }

                if ( ! $release || empty( $release['tag_name'] ) ) {
                        wp_send_json_error( array(
                                'message' => __( 'GitHub вернул пустой ответ. Проверьте internet-соединение сервера.', 'beestore-integration' ),
                        ) );
                }

                $remote_version = ltrim( $release['tag_name'], 'v' );
                $has_update     = version_compare( $this->version, $remote_version, '<' );

                // Если обновление есть — заполняем transient update_plugins вручную,
                // чтобы WP сразу показал уведомление.
                if ( $has_update ) {
                        $package_url = $this->get_zip_url( $release );

                        $obj                          = new stdClass();
                        $obj->slug                    = $this->slug;
                        $obj->plugin                  = $this->basename;
                        $obj->new_version              = $remote_version;
                        $obj->package                 = $package_url;
                        $obj->url                     = 'https://github.com/' . $this->github_repo;
                        $obj->icons                   = array();
                        $obj->banners                 = array();

                        // Получаем текущий transient (пустой после delete_site_transient).
                        $transient = get_site_transient( 'update_plugins' );
                        if ( ! is_object( $transient ) ) {
                                $transient = new stdClass();
                                $transient->checked = array( $this->basename => $this->version );
                                $transient->response = array();
                                $transient->last_checked = time();
                                $transient->translations = array();
                        }
                        $transient->response[ $this->basename ] = $obj;
                        set_site_transient( 'update_plugins', $transient );

                        // Логируем.
                        if ( class_exists( 'BSI_Logger' ) ) {
                                BSI_Logger::instance()->info( 'updater', 'Принудительная проверка: обновление доступно', array(
                                        'current' => $this->version,
                                        'remote'  => $remote_version,
                                        'package' => $package_url,
                                ) );
                        }
                } else {
                        // Обновления нет — логируем.
                        if ( class_exists( 'BSI_Logger' ) ) {
                                BSI_Logger::instance()->info( 'updater', 'Принудительная проверка: обновлений нет', array(
                                        'current' => $this->version,
                                        'remote'  => $remote_version,
                                ) );
                        }
                }

                // Проверка ограничений WordPress.
                $warnings = array();
                if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
                        $warnings[] = __( 'В wp-config.php задано DISALLOW_FILE_MODS = true. WordPress запрещает любые изменения файлов через админку. Уведомление может не появиться.', 'beestore-integration' );
                }
                if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
                        $warnings[] = __( 'В wp-config.php задано AUTOMATIC_UPDATER_DISABLED = true. Автообновления отключены.', 'beestore-integration' );
                }
                if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
                        $warnings[] = __( 'В wp-config.php задано DISABLE_WP_CRON = true. WP-Cron отключён — нужна системная задача crontab.', 'beestore-integration' );
                }

                wp_send_json_success( array(
                        'current_version' => $this->version,
                        'remote_version'  => $remote_version,
                        'tag_name'        => $release['tag_name'],
                        'has_update'      => $has_update,
                        'package_url'     => $has_update ? $this->get_zip_url( $release ) : '',
                        'published_at'    => $release['published_at'] ?? '',
                        'warnings'        => $warnings,
                        'message'         => $has_update
                                ? sprintf(
                                    /* translators: 1: текущая версия, 2: новая версия */
                                    __( 'Доступно обновление: %1$s → %2$s. Зайдите в Консоль → Обновления чтобы установить.', 'beestore-integration' ),
                                    $this->version,
                                    $remote_version
                                )
                                : sprintf(
                                    /* translators: %s — текущая версия */
                                    __( 'Установлена последняя версия (%s).', 'beestore-integration' ),
                                    $this->version
                                ),
                ) );
        }

        /**
         * Проверить наличие новой версии через GitHub API.
         */
        public function check_update( $transient ) {
                if ( empty( $transient->checked ) ) {
                        return $transient;
                }

                // ВАЖНО: ранее тут был ранний return если $transient->response[ $this->basename ]
                // уже существует. Это ломало автообновление: после обновления с 1.7.0 → 1.7.3
                // WP кешировал response на 12 часов, и при появлении v1.7.4 плагин не
                // перезаписывал кеш → обновление не появлялось. Убрали этот return.

                $release = $this->get_latest_release();
                if ( is_wp_error( $release ) || ! $release ) {
                        if ( class_exists( 'BSI_Logger' ) ) {
                                BSI_Logger::instance()->debug( 'updater', 'GitHub release не получен', array(
                                        'err' => is_wp_error( $release ) ? $release->get_error_message() : 'empty',
                                ) );
                        }
                        return $transient;
                }

                // Сравниваем версии.
                $remote_version = $release['tag_name'];
                // Убираем 'v' префикс если есть.
                $remote_version = ltrim( $remote_version, 'v' );

                if ( class_exists( 'BSI_Logger' ) ) {
                        BSI_Logger::instance()->debug( 'updater', 'Проверка версии плагина', array(
                                'current' => $this->version,
                                'remote'  => $remote_version,
                                'tag'     => $release['tag_name'],
                        ) );
                }

                if ( version_compare( $this->version, $remote_version, '<' ) ) {
                        $package_url = $this->get_zip_url( $release );

                        if ( class_exists( 'BSI_Logger' ) ) {
                                BSI_Logger::instance()->info( 'updater', 'Доступно обновление', array(
                                        'current' => $this->version,
                                        'remote'  => $remote_version,
                                        'package' => $package_url,
                                ) );
                        }

                        $obj                          = new stdClass();
                        $obj->slug                    = $this->slug;       // 'beestore-integration'.
                        $obj->plugin                  = $this->basename;   // 'beestore-integration/beestore-integration.php'.
                        $obj->new_version              = $remote_version;
                        $obj->package                 = $package_url;
                        $obj->url                     = 'https://github.com/' . $this->github_repo;
                        $obj->icons                   = array();
                        $obj->banners                 = array();

                        $transient->response[ $this->basename ] = $obj;
                }

                return $transient;
        }

        /**
         * Информация о плагине для окна "Подробнее" в админке.
         */
        public function plugin_info( $result, $action, $args ) {
                if ( 'plugin_information' !== $action ) {
                        return $result;
                }
                if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
                        return $result;
                }

                $release = $this->get_latest_release();
                if ( is_wp_error( $release ) || ! $release ) {
                        return $result;
                }

                $remote_version = ltrim( $release['tag_name'], 'v' );

                // Получаем README с описанием.
                $readme = $this->fetch_readme();
                $description = $readme ? $readme : sprintf(
                        /* translators: %s — GitHub repo URL */
                        __( 'Обновление плагина BeeStore Integration. Подробности на GitHub: %s', 'beestore-integration' ),
                        'https://github.com/' . $this->github_repo
                );

                $plugin_info = array(
                        'name'              => 'BeeStore Integration for WooCommerce',
                        'slug'              => dirname( $this->slug ),
                        'version'           => $remote_version,
                        'author'            => '<a href="https://github.com/Botssman">Kirill Andreev</a>',
                        'homepage'          => 'https://github.com/' . $this->github_repo,
                        'short_description' => __( 'Интеграция WooCommerce с BeeStore (Sirio Informatica).', 'beestore-integration' ),
                        'sections'          => array(
                                'description' => $description,
                                'changelog'   => $this->get_changelog( $release ),
                        ),
                        'download_link'     => $this->get_zip_url( $release ),
                        'last_updated'      => $release['published_at'] ?? current_time( 'mysql' ),
                        'requires'          => '6.2',
                        'requires_php'      => '7.4',
                );

                return (object) $plugin_info;
        }

        /**
         * Заголовки для GitHub API запросов.
         * Если задан access_token — добавляем Authorization (для приватных репо).
         */
        private function get_api_headers() {
                $headers = array(
                        'Accept' => 'application/vnd.github.v3+json',
                );
                if ( $this->access_token ) {
                        $headers['Authorization'] = 'token ' . $this->access_token;
                }
                return $headers;
        }

        /**
         * Получить последний release с GitHub.
         */
        private function get_latest_release() {
                $cache_key = 'bsi_github_latest_release';
                $cached    = get_transient( $cache_key );
                if ( false !== $cached && is_array( $cached ) ) {
                        return $cached;
                }

                $response = wp_remote_get(
                        $this->api_url . '/releases/latest',
                        array(
                                'timeout'    => 15,
                                'user-agent' => 'BeeStoreIntegration/' . BSI_VERSION,
                                'headers'    => $this->get_api_headers(),
                        )
                );

                if ( is_wp_error( $response ) ) {
                        return $response;
                }

                $code = wp_remote_retrieve_response_code( $response );
                if ( 200 !== $code ) {
                        return new WP_Error(
                                'bsi_github_release_failed',
                                sprintf( __( 'GitHub API вернул HTTP %d', 'beestore-integration' ), $code )
                        );
                }

                $body  = wp_remote_retrieve_body( $response );
                $data  = json_decode( $body, true );

                if ( ! is_array( $data ) || empty( $data['tag_name'] ) ) {
                        return new WP_Error( 'bsi_github_release_bad_json', __( 'Неверный формат ответа GitHub API.', 'beestore-integration' ) );
                }

                // Кешируем на 1 час.
                set_transient( $cache_key, $data, HOUR_IN_SECONDS );

                return $data;
        }

        /**
         * Получить URL для скачивания zip из release.
         */
        private function get_zip_url( $release ) {
                // Если в release есть готовый zip asset — берём его (быстрее).
                if ( ! empty( $release['assets'] ) ) {
                        foreach ( $release['assets'] as $asset ) {
                                if ( ! empty( $asset['browser_download_url'] ) && false !== strpos( $asset['name'], '.zip' ) ) {
                                        return $asset['browser_download_url'];
                                }
                        }
                }

                // Иначе берём автоматически сгенерированный zip с main-ветки.
                return sprintf(
                        'https://github.com/%s/archive/refs/tags/%s.zip',
                        $this->github_repo,
                        $release['tag_name']
                );
        }

        /**
         * Получить changelog из body release.
         */
        private function get_changelog( $release ) {
                $body = isset( $release['body'] ) ? $release['body'] : '';
                if ( ! $body ) {
                        return __( 'Список изменений недоступен.', 'beestore-integration' );
                }

                // Markdown→HTML (упрощённо). WP не умеет парсить markdown нативно,
                // поэтому оборачиваем в <pre> чтобы сохранить перенос строк.
                $body = esc_html( $body );
                $body = preg_replace( '/\r?\n/', "<br>\n", $body );

                return '<p>' . $body . '</p>';
        }

        /**
         * Получить README.md с main-ветки.
         */
        private function fetch_readme() {
                $cache_key = 'bsi_github_readme';
                $cached    = get_transient( $cache_key );
                if ( false !== $cached ) {
                        return $cached;
                }

                $response = wp_remote_get(
                        $this->raw_url . '/README.md',
                        array(
                                'timeout'    => 15,
                                'user-agent' => 'BeeStoreIntegration/' . BSI_VERSION,
                                'headers'    => $this->get_api_headers(),
                        )
                );

                if ( is_wp_error( $response ) ) {
                        return '';
                }

                $code = wp_remote_retrieve_response_code( $response );
                if ( 200 !== $code ) {
                        return '';
                }

                $body = wp_remote_retrieve_body( $response );
                // Упрощённый markdown → HTML.
                $body = esc_html( $body );
                $body = preg_replace( '/\r?\n/', "<br>\n", $body );

                set_transient( $cache_key, $body, DAY_IN_SECONDS );

                return $body;
        }
}
