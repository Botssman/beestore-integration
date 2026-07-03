<?php
/**
 * Опрос FTP/SFTP, скачивание ZIP-файлов с каталогом BeeStore, распаковка.
 *
 * Именование ZIP: COMPANY_<Num>_0000_<Date>_<Time>_<Seq>.zip
 * Внутри ZIP — CSV-файл с тем же именем (см. реальную выгрузку).
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class BSI_FTP {

        private static $instance = null;

        public static function instance() {
                if ( null === self::$instance ) {
                        self::$instance = new self();
                }
                return self::$instance;
        }

        private function __construct() {}

        /**
         * Каталог загрузок плагина.
         *
         * @return string
         */
        public function get_download_dir() {
                $upload = wp_upload_dir();
                return trailingslashit( $upload['basedir'] ) . 'beestore/downloads';
        }

        public function get_extract_dir() {
                $upload = wp_upload_dir();
                return trailingslashit( $upload['basedir'] ) . 'beestore/extracted';
        }

        public function get_processed_dir() {
                $upload = wp_upload_dir();
                return trailingslashit( $upload['basedir'] ) . 'beestore/processed';
        }

        /**
         * Получить список ZIP-файлов на FTP, соответствующих шаблону BeeStore.
         *
         * @return array|WP_Error  Массив имён файлов (отсортировано по имени desc).
         */
        public function list_remote_zips() {
                $settings = get_option( 'bsi_settings', array() );
                $host     = isset( $settings['ftp_host'] ) ? $settings['ftp_host'] : '';
                $port     = isset( $settings['ftp_port'] ) ? (int) $settings['ftp_port'] : 21;
                $user     = isset( $settings['ftp_user'] ) ? $settings['ftp_user'] : '';
                $pass     = isset( $settings['ftp_pass'] ) ? $settings['ftp_pass'] : '';
                $path     = isset( $settings['ftp_path'] ) ? $settings['ftp_path'] : '/';
                $use_sftp = isset( $settings['ftp_use_sftp'] ) && '1' === $settings['ftp_use_sftp'];

                if ( empty( $host ) || empty( $user ) ) {
                        return new WP_Error( 'bsi_ftp_no_config', __( 'FTP-доступы не настроены в настройках плагина.', 'beestore-integration' ) );
                }

                if ( $use_sftp && ! function_exists( 'ssh2_connect' ) ) {
                        return new WP_Error( 'bsi_no_ssh2', __( 'Включён SFTP, но расширение ssh2 не установлено в PHP. Используйте обычный FTP или попросите хостинг включить ssh2.', 'beestore-integration' ) );
                }
                if ( ! $use_sftp && ! function_exists( 'ftp_connect' ) ) {
                        return new WP_Error( 'bsi_no_ftp', __( 'Расширение ftp не установлено в PHP.', 'beestore-integration' ) );
                }

                BSI_Logger::instance()->info( 'ftp', 'Подключение к FTP', array(
                        'host' => $host,
                        'port' => $port,
                        'path' => $path,
                        'sftp' => $use_sftp,
                ) );

                $files = $use_sftp
                        ? $this->list_sftp( $host, $port, $user, $pass, $path )
                        : $this->list_ftp( $host, $port, $user, $pass, $path );

                if ( is_wp_error( $files ) ) {
                        return $files;
                }

                // Фильтруем по шаблону BeeStore.
                // Поддерживаем 3 варианта (Sirio присылает файлы в разных форматах):
                //   1. COMPANY_0540_0000_2026-06-26_01-03-01_0000001.zip  (ZIP с дефисами)
                //   2. COMPANY_0032_0000_2019_09_27_08_27_34_0000001.zip  (ZIP с подчёркиваниями)
                //   3. COMPANY_0540_0000_2026-06-26_01-03-01_0000001.csv  (голый CSV — по письму Sirio)
                // ВАЖНО: FTP-сервер может возвращать полные пути (/home/.../COMPANY_...zip)
                // или с префиксом ./, поэтому берём basename() перед regex.
                $filtered = array();
                foreach ( $files as $name ) {
                        $basename = basename( ltrim( $name, './' ) );
                        if ( preg_match( '/^COMPANY_\d+_0000_[0-9_\-]+\.(zip|csv)$/i', $basename ) ) {
                                // Сохраняем оригинальный путь (как его вернул FTP), чтобы потом
                                // можно было скачать файл.
                                $filtered[] = $name;
                        }
                }

                // Сортируем по убыванию имени (новейший — первым).
                rsort( $filtered );

                BSI_Logger::instance()->info( 'ftp', 'Найдено ZIP-файлов BeeStore', array( 'count' => count( $filtered ) ) );

                return $filtered;
        }

        private function list_ftp( $host, $port, $user, $pass, $path ) {
                $conn = @ftp_connect( $host, $port, 30 );
                if ( ! $conn ) {
                        return new WP_Error( 'bsi_ftp_connect', sprintf( __( 'Не удалось подключиться к FTP %s:%d', 'beestore-integration' ), $host, $port ) );
                }
                if ( ! @ftp_login( $conn, $user, $pass ) ) {
                        ftp_close( $conn );
                        return new WP_Error( 'bsi_ftp_login', __( 'Неверный логин/пароль FTP.', 'beestore-integration' ) );
                }
                ftp_pasv( $conn, true );

                $raw_list = @ftp_nlist( $conn, $path );
                ftp_close( $conn );

                if ( false === $raw_list ) {
                        return new WP_Error( 'bsi_ftp_nlist', __( 'Не удалось получить список файлов FTP.', 'beestore-integration' ) );
                }
                // Оставляем только basename.
                $files = array();
                foreach ( $raw_list as $entry ) {
                        $files[] = ltrim( basename( $entry ), './' );
                }
                return $files;
        }

        private function list_sftp( $host, $port, $user, $pass, $path ) {
                $conn = @ssh2_connect( $host, $port );
                if ( ! $conn ) {
                        return new WP_Error( 'bsi_sftp_connect', sprintf( __( 'Не удалось подключиться к SFTP %s:%d', 'beestore-integration' ), $host, $port ) );
                }
                if ( ! @ssh2_auth_password( $conn, $user, $pass ) ) {
                        return new WP_Error( 'bsi_sftp_login', __( 'Неверный логин/пароль SFTP.', 'beestore-integration' ) );
                }
                $sftp = ssh2_sftp( $conn );
                $dir  = 'ssh2.sftp://' . $sftp . $path;
                $dh   = @opendir( $dir );
                if ( ! $dh ) {
                        return new WP_Error( 'bsi_sftp_opendir', sprintf( __( 'Не удалось открыть каталог SFTP: %s', 'beestore-integration' ), $path ) );
                }
                $files = array();
                while ( false !== ( $entry = readdir( $dh ) ) ) {
                        if ( '.' === $entry || '..' === $entry ) {
                                continue;
                        }
                        $files[] = $entry;
                }
                closedir( $dh );
                return $files;
        }

        /**
         * Скачать конкретный ZIP с FTP.
         *
         * @param string $remote_name Имя файла на FTP.
         * @return string|WP_Error Локальный путь к скачанному файлу.
         */
        public function download_remote_zip( $remote_name ) {
                $settings = get_option( 'bsi_settings', array() );
                $host     = isset( $settings['ftp_host'] ) ? $settings['ftp_host'] : '';
                $port     = isset( $settings['ftp_port'] ) ? (int) $settings['ftp_port'] : 21;
                $user     = isset( $settings['ftp_user'] ) ? $settings['ftp_user'] : '';
                $pass     = isset( $settings['ftp_pass'] ) ? $settings['ftp_pass'] : '';
                $path     = isset( $settings['ftp_path'] ) ? $settings['ftp_path'] : '/';
                $use_sftp = isset( $settings['ftp_use_sftp'] ) && '1' === $settings['ftp_use_sftp'];

                $local_dir  = $this->get_download_dir();
                // Берём basename на случай, если FTP вернул полный путь.
                $local_file = trailingslashit( $local_dir ) . sanitize_file_name( basename( $remote_name ) );

                if ( ! file_exists( $local_dir ) ) {
                        wp_mkdir_p( $local_dir );
                }

                if ( $use_sftp ) {
                        $conn = @ssh2_connect( $host, $port );
                        if ( ! $conn || ! @ssh2_auth_password( $conn, $user, $pass ) ) {
                                return new WP_Error( 'bsi_sftp_connect', __( 'Не удалось подключиться к SFTP для скачивания.', 'beestore-integration' ) );
                        }
                        if ( ! ssh2_scp_recv( $conn, $remote_name, $local_file ) ) {
                                return new WP_Error( 'bsi_sftp_download', __( 'Ошибка скачивания файла по SFTP.', 'beestore-integration' ) );
                        }
                } else {
                        $conn = @ftp_connect( $host, $port, 30 );
                        if ( ! $conn || ! @ftp_login( $conn, $user, $pass ) ) {
                                return new WP_Error( 'bsi_ftp_connect', __( 'Не удалось подключиться к FTP для скачивания.', 'beestore-integration' ) );
                        }
                        ftp_pasv( $conn, true );
                        // Используем $remote_name как есть — FTP-сервер знает свои пути.
                        if ( ! @ftp_get( $conn, $local_file, $remote_name, FTP_BINARY ) ) {
                                // Если не получилось с полным путём — пробуем относительно $path.
                                $fallback = trailingslashit( $path ) . basename( $remote_name );
                                if ( ! @ftp_get( $conn, $local_file, $fallback, FTP_BINARY ) ) {
                                        ftp_close( $conn );
                                        return new WP_Error( 'bsi_ftp_download', __( 'Ошибка скачивания файла по FTP.', 'beestore-integration' ) );
                                }
                        }
                        ftp_close( $conn );
                }

                BSI_Logger::instance()->info( 'ftp', 'ZIP скачан', array( 'file' => $remote_name, 'local' => $local_file, 'size' => filesize( $local_file ) ) );

                return $local_file;
        }

        /**
         * Распаковать ZIP в каталог extracted/<base_name>/.
         *
         * @param string $zip_file Локальный путь к ZIP.
         * @return array|WP_Error Список распакованных файлов.
         */
        public function extract_zip( $zip_file ) {
                if ( ! class_exists( 'ZipArchive' ) ) {
                        return new WP_Error( 'bsi_no_zip', __( 'Расширение ZipArchive не установлено в PHP.', 'beestore-integration' ) );
                }

                $base_name = pathinfo( $zip_file, PATHINFO_FILENAME );
                $extract_dir = trailingslashit( $this->get_extract_dir() ) . $base_name;

                if ( ! file_exists( $extract_dir ) ) {
                        wp_mkdir_p( $extract_dir );
                }

                $zip = new ZipArchive();
                if ( true !== $zip->open( $zip_file ) ) {
                        return new WP_Error( 'bsi_zip_open', sprintf( __( 'Не удалось открыть ZIP-архив: %s', 'beestore-integration' ), $zip_file ) );
                }
                $zip->extractTo( $extract_dir );
                $zip->close();

                // Ищем CSV внутри.
                $files = array();
                foreach ( glob( $extract_dir . '/*' ) as $f ) {
                        if ( is_file( $f ) ) {
                                $files[] = $f;
                        }
                }

                BSI_Logger::instance()->info( 'ftp', 'ZIP распакован', array(
                        'zip' => $base_name,
                        'files' => array_map( 'basename', $files ),
                ) );

                return $files;
        }

        /**
         * Найти CSV-файл в распакованном каталоге.
         */
        public function find_csv_in_extracted( $extract_dir ) {
                $csvs = glob( trailingslashit( $extract_dir ) . '*.csv' );
                return ! empty( $csvs ) ? $csvs[0] : '';
        }

        /**
         * Сдвинуть файл и распакованные данные в processed/ после успешного импорта.
         *
         * @param string $zip_file Путь к локальному ZIP или CSV-файлу.
         */
        public function mark_processed( $zip_file ) {
                if ( empty( $zip_file ) ) {
                        return;
                }
                $dest_dir = $this->get_processed_dir();
                if ( ! file_exists( $dest_dir ) ) {
                        wp_mkdir_p( $dest_dir );
                }
                $base_name = basename( $zip_file );
                $target    = trailingslashit( $dest_dir ) . $base_name;
                if ( file_exists( $zip_file ) ) {
                        @rename( $zip_file, $target ); // phpcs:ignore
                }
                // Если это был ZIP — переместить каталог extracted тоже.
                $extracted_dir = trailingslashit( $this->get_extract_dir() ) . pathinfo( $zip_file, PATHINFO_FILENAME );
                if ( is_dir( $extracted_dir ) ) {
                        @rename( $extracted_dir, trailingslashit( $dest_dir ) . pathinfo( $zip_file, PATHINFO_FILENAME ) ); // phpcs:ignore
                }
        }

        /**
         * Полный цикл: опрос FTP → скачивание самого нового файла → распаковка (если ZIP).
         *
         * Поддерживает 2 типа файлов от Sirio:
         *   - .zip   — архив с CSV внутри (нужно распаковать)
         *   - .csv   — голый CSV-файл (по письму Sirio от 2026-06)
         *
         * @return array|WP_Error  [ 'zip' => local_zip_path|'', 'csv' => extracted_csv_path, 'remote_name' => name ]
         */
        public function fetch_latest_zip() {
                $files = $this->list_remote_zips();
                if ( is_wp_error( $files ) ) {
                        return $files;
                }
                if ( empty( $files ) ) {
                        return new WP_Error( 'bsi_ftp_empty', __( 'На FTP нет файлов BeeStore (COMPANY_*.zip или COMPANY_*.csv).', 'beestore-integration' ) );
                }

                // Проверим — не обработан ли уже самый свежий.
                $latest_name = $files[0];
                $latest_basename = basename( ltrim( $latest_name, './' ) );
                $processed_marker = trailingslashit( $this->get_processed_dir() ) . sanitize_file_name( $latest_basename );
                if ( file_exists( $processed_marker ) ) {
                        return new WP_Error( 'bsi_already_processed', sprintf( __( 'Самый свежий файл %s уже обработан ранее.', 'beestore-integration' ), $latest_basename ) );
                }

                $local_file = $this->download_remote_zip( $latest_name );
                if ( is_wp_error( $local_file ) ) {
                        return $local_file;
                }

                // Определяем тип файла по расширению.
                $ext = strtolower( pathinfo( $latest_name, PATHINFO_EXTENSION ) );

                if ( 'csv' === $ext ) {
                        // Голый CSV — ничего распаковывать не нужно, файл уже готов.
                        BSI_Logger::instance()->info( 'ftp', 'Получен голый CSV-файл (без ZIP-обёртки)', array(
                                'file' => $latest_name,
                                'local' => $local_file,
                        ) );
                        return array(
                                'zip'         => '',
                                'csv'         => $local_file,
                                'remote_name' => $latest_name,
                        );
                }

                // Это ZIP — распаковываем.
                $files = $this->extract_zip( $local_file );
                if ( is_wp_error( $files ) ) {
                        return $files;
                }

                $csv_file = '';
                foreach ( $files as $f ) {
                        if ( '.csv' === strtolower( substr( $f, -4 ) ) ) {
                                $csv_file = $f;
                                break;
                        }
                }
                if ( empty( $csv_file ) ) {
                        return new WP_Error( 'bsi_no_csv_in_zip', __( 'Внутри ZIP не найден CSV-файл.', 'beestore-integration' ) );
                }

                return array(
                        'zip'         => $local_file,
                        'csv'         => $csv_file,
                        'remote_name' => $latest_name,
                );
        }
}
