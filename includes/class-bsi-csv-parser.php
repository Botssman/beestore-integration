<?php
/**
 * Потоковый парсер CSV BeeStore.
 *
 * CSV-файл может содержать десятки тысяч строк и 132 колонки. Использовать
 * file() / array_map() нельзя — память кончится. Используем fgetcsv()
 * и итератор.
 *
 * Поддерживается:
 *  - BOM UTF-8 в начале файла
 *  - Разделитель ", " и ограничитель "
 *  - Чтение заголовка и предоставление строк в виде ассоциативных массивов
 *
 * @package BeeStoreIntegration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSI_CSV_Parser implements Iterator, Countable {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Открыть CSV-файл и вернуть итератор.
	 *
	 * @param string $file Путь к CSV-файлу.
	 * @return BSI_CSV_Iterator|WP_Error
	 */
	public function open( $file ) {
		if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
			return new WP_Error( 'bsi_csv_not_found', sprintf( __( 'CSV-файл не найден или недоступен для чтения: %s', 'beestore-integration' ), $file ) );
		}
		return new BSI_CSV_Iterator( $file );
	}

	/**
	 * Подсчитать количество строк (используется для прогресс-бара).
	 * На больших файлах может быть медленным — использовать только для отображения прогресса.
	 *
	 * @param string $file
	 * @return int
	 */
	public function count_lines( $file ) {
		$count = 0;
		$handle = fopen( $file, 'rb' );
		if ( ! $handle ) {
			return 0;
		}
		// Пропускаем BOM.
		$bom = fread( $handle, 3 );
		if ( "\xEF\xBB\xBF" !== $bom ) {
			fseek( $handle, 0 );
		}
		while ( ! feof( $handle ) ) {
			$chunk = fread( $handle, 8192 );
			$count += substr_count( $chunk, "\n" );
		}
		fclose( $handle );
		return $count;
	}

	/* ---------------------------------------------------------------------
	 * Iterator/Countable интерфейсы делегируются BSI_CSV_Iterator.
	 * Методы ниже оставлены для обратной совместимости API.
	 * --------------------------------------------------------------------- */
	public function current() { return null; }
	public function key() { return 0; }
	public function next() {}
	public function rewind() {}
	public function valid() { return false; }
	public function count() { return 0; }
}

/**
 * Итератор по CSV с low-memory потреблением.
 */
class BSI_CSV_Iterator implements Iterator, Countable {

	private $file;
	private $handle;
	private $headers;
	private $current_row;
	private $current_index;
	private $total_lines = 0;
	private $delimiter;
	private $enclosure;

	public function __construct( $file, $delimiter = ',', $enclosure = '"' ) {
		$this->file       = $file;
		$this->delimiter  = $delimiter;
		$this->enclosure  = $enclosure;
		$this->open_file();
	}

	private function open_file() {
		$this->handle = fopen( $this->file, 'rb' );
		if ( ! $this->handle ) {
			return;
		}
		// Пропускаем UTF-8 BOM если есть.
		$bom = fread( $this->handle, 3 );
		if ( "\xEF\xBB\xBF" !== $bom ) {
			fseek( $this->handle, 0 );
		}

		// Читаем заголовок.
		$headers    = fgetcsv( $this->handle, 0, $this->delimiter, $this->enclosure );
		$this->headers = $headers ? array_map( 'trim', $headers ) : array();

		$this->current_index = 0;
		$this->read_next();
	}

	private function read_next() {
		if ( ! $this->handle || feof( $this->handle ) ) {
			$this->current_row = false;
			return;
		}
		$row = fgetcsv( $this->handle, 0, $this->delimiter, $this->enclosure );
		if ( false === $row || null === $row ) {
			$this->current_row = false;
			return;
		}
		// Если колонок меньше, чем в заголовке — дополняем пустыми.
		if ( count( $row ) < count( $this->headers ) ) {
			$row = array_pad( $row, count( $this->headers ), '' );
		}
		// Если больше — обрезаем.
		if ( count( $row ) > count( $this->headers ) ) {
			$row = array_slice( $row, 0, count( $this->headers ) );
		}
		$this->current_row = array_combine( $this->headers, array_map( 'trim', $row ) );
	}

	public function get_headers() {
		return $this->headers;
	}

	public function current() {
		return $this->current_row;
	}

	public function key() {
		return $this->current_index;
	}

	public function next() {
		$this->current_index++;
		$this->read_next();
	}

	public function rewind() {
		// Перечитываем файл с начала.
		if ( $this->handle ) {
			fclose( $this->handle );
		}
		$this->open_file();
	}

	public function valid() {
		return false !== $this->current_row && ! empty( $this->current_row );
	}

	public function count() {
		if ( 0 === $this->total_lines ) {
			$this->total_lines = BSI_CSV_Parser::instance()->count_lines( $this->file ) - 1; // минус заголовок.
		}
		return $this->total_lines;
	}

	public function close() {
		if ( $this->handle ) {
			fclose( $this->handle );
			$this->handle = null;
		}
	}

	public function __destruct() {
		$this->close();
	}
}
