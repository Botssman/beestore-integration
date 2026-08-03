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

        public function __construct() {
                $this->plugin_file = BSI_PLUGIN_FILE;
                $this->slug        = BSI_PLUGIN_BASENAME;
                $this->version     = BSI_VERSION;

                add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
                add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
        }

        /**
         * Проверить наличие новой версии через GitHub API.
         */
        public function check_update( $transient ) {
                if ( empty( $transient->checked ) ) {
                        return $transient;
                }

                $release = $this->get_latest_release();
                if ( is_wp_error( $release ) || ! $release ) {
                        return $transient;
                }

                // Сравниваем версии.
                $remote_version = $release['tag_name'];
                // Убираем 'v' префикс если есть.
                $remote_version = ltrim( $remote_version, 'v' );

                if ( version_compare( $this->version, $remote_version, '<' ) ) {
                        $package_url = $this->get_zip_url( $release );

                        $obj                          = new stdClass();
                        $obj->slug                    = $this->slug;
                        $obj->plugin                  = $this->slug;
                        $obj->new_version              = $remote_version;
                        $obj->package                 = $package_url;
                        $obj->url                     = 'https://github.com/' . $this->github_repo;
                        $obj->icons                   = array();
                        $obj->banners                 = array();

                        $transient->response[ $this->slug ] = $obj;
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
                if ( ! isset( $args->slug ) || $args->slug !== dirname( $this->slug ) ) {
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
                                'headers'    => array(
                                        'Accept' => 'application/vnd.github.v3+json',
                                ),
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
