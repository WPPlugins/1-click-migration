<?php
/**
 * Processes database-related functionality.
 */
namespace OCM;

class OCM_DB {

	/**
	 * The page size used throughout the plugin.
	 * @var int
	 */
	public $page_size;

	/**
	 * The name of the backup file.
	 * @var string
	 */
	public $file;

	/**
	 * The WordPress database class.
	 * @var WPDB
	 */
	private $wpdb;

	/**
	 * Hosts seen while rewriting, or null when collection is off.
	 * @var array|null
	 */
	private $url_found = null;

	/**
	 * Whether the current search_replace_db() call matches case exactly.
	 * Restores leave it off: hosts and URLs are case-insensitive.
	 * @var bool
	 */
	private $match_case = false;

	/**
	 * Where the current search_replace_db() call sends its log lines, or null
	 * for the migration log.
	 * @var callable|null
	 */
	private $log_sink = null;

	/**
	 * Whether the current search_replace_db() call may create the objects it
	 * finds in serialized values. Restores do, to rewrite addresses inside
	 * them. The Search & Replace tool does not: it runs on a live site, where
	 * a visitor can store text that looks like an object (a comment, a form
	 * entry), and creating it would run that class's code.
	 * @var bool
	 */
	private $allow_objects = true;

	/**
	 * Set while one cell is rewritten with $allow_objects off: the cell holds
	 * a match inside a stored object, which was left as it is.
	 * @var bool
	 */
	private $cell_object_match = false;

	/** Longest before/after snippet a dry run keeps per side. */
	const SAMPLE_CONTEXT = 60;

	/**
	 * Initializes the class and its properties.
	 * @access public
	 */
	public function __construct() {

		global $wpdb;
		$this->wpdb = $wpdb;

		$this->page_size = $this->get_page_size();
	}

	/**
	 * Record hosts from cells the current rewrite already reads.
	 */
	public function enable_url_collection( $found = array() ) {
		// A resumed rewrite continues the host list the earlier workers built.
		$this->url_found = is_array( $found ) ? $found : array();
	}

	/**
	 * @return array
	 */
	public function collected_urls() {
		return is_array( $this->url_found ) ? $this->url_found : array();
	}

	/**
	 * One log line: to the caller's sink when search_replace_db() was given
	 * one, otherwise to the migration log (which the progress bar and the
	 * server-side monitor read).
	 *
	 * @param string $message
	 */
	private function log_line( $message ) {
		if ( null !== $this->log_sink ) {
			call_user_func( $this->log_sink, (string) $message );
			return;
		}
		One_Click_Migration::write_to_log( $message );
	}

	/**
	 * Returns an array of tables in the database.
	 * @access public
	 * @return array
	 */
	public static function get_tables() {
		global $wpdb;

		if ( function_exists( 'is_multisite' ) && is_multisite() ) {

			if ( is_main_site() ) {
				$tables 	= $wpdb->get_col( 'SHOW TABLES' );
			} else {
				$blog_id 	= get_current_blog_id();
				$tables 	= $wpdb->get_col( "SHOW TABLES LIKE '" . $wpdb->base_prefix . absint( $blog_id ) . "\_%'" );
			}

		} else {
			$tables = $wpdb->get_col( 'SHOW TABLES' );
		}

		return $tables;
	}

	/**
	 * Max rows in one keyset batch (sane upper bound; no 1000-row floor).
	 */
	const KEYSET_BATCH_ROWS_MAX = 50000;

	/**
	 * Fallback batch budget when memory_limit is unlimited.
	 */
	const KEYSET_BATCH_BUDGET_UNLIMITED = 16777216; // 16 MiB

	/**
	 * Working-set multiplier for a fetched row (fetch + unserialize + replace + escape).
	 * Measured ~5.98 on a real serialized row; 6.5 used for batch sizing and the
	 * oversized cutoff (refuse when max_row×6.5 > current batch budget).
	 */
	const KEYSET_ROW_MEMORY_MULTIPLIER = 6.5;

	/**
	 * Inside a keyset page, ask should_yield every this many rows.
	 */
	const YIELD_CHECK_ROWS = 50;

	/**
	 * Returns the current page size with memory-aware calculation.
	 * @access public
	 * @return int
	 */
	public function get_page_size() {
		return $this->get_dynamic_page_size();
	}

	/**
	 * Resolved memory_limit in bytes, or null when unlimited / unknown.
	 *
	 * @return int|null
	 */
	private function memory_limit_bytes() {
		$memory_limit = ini_get( 'memory_limit' );
		if ( $memory_limit === false || $memory_limit === '' || (string) $memory_limit === '-1' ) {
			return null;
		}
		$bytes = $this->convert_to_bytes( (string) $memory_limit );
		return $bytes > 0 ? $bytes : null;
	}

	/**
	 * Runtime byte budget for one keyset batch:
	 * max(1, floor(25% of (memory_limit − memory_get_usage()))).
	 * Recomputed each batch so growing usage shrinks later pages.
	 * Uses remaining headroom so normal WP usage (30–60MB) does not collapse
	 * batches to 1 row.
	 *
	 * @return int
	 */
	private function batch_byte_budget() {
		$limit = $this->memory_limit_bytes();
		if ( null === $limit ) {
			return self::KEYSET_BATCH_BUDGET_UNLIMITED;
		}
		$usage     = (int) memory_get_usage();
		$remaining = (int) max( 0, $limit - $usage );
		return (int) max( 1, (int) floor( $remaining * 0.25 ) );
	}

	/**
	 * Largest row's text/blob payload: MAX(sum of LENGTH() over text/blob columns).
	 * Used for batch sizing and the oversized-row guard (never SELECT * first).
	 *
	 * @param string             $table
	 * @param array<int, string> $text_columns
	 * @return int|null Bytes, or null when uncertain.
	 */
	private function max_row_text_bytes( $table, $text_columns = array() ) {
		if ( ! is_array( $text_columns ) || ! $text_columns ) {
			return null;
		}
		$table_sql    = esc_sql( $table );
		$length_parts = array();
		foreach ( $text_columns as $column ) {
			$length_parts[] = 'COALESCE(LENGTH(' . $this->quote_identifier( $column ) . '), 0)';
		}
		if ( is_object( $this->wpdb ) ) {
			$this->wpdb->last_error = '';
		}
		$sql = 'SELECT MAX(' . implode( ' + ', $length_parts ) . ') FROM `' . $table_sql . '`';
		$raw = $this->wpdb->get_var( $sql );
		$query_failed = is_object( $this->wpdb ) && ! empty( $this->wpdb->last_error );
		if ( $query_failed || null === $raw || false === $raw || '' === $raw ) {
			return null;
		}
		$max = (int) $raw;
		return $max > 0 ? $max : null;
	}

	/**
	 * Per-row estimate for keyset batch sizing: largest text/blob row (MAX LENGTH sum).
	 *
	 * @param string             $table
	 * @param array<int, string> $text_columns
	 * @return int|null
	 */
	private function estimate_row_bytes( $table, $text_columns = array() ) {
		return $this->max_row_text_bytes( $table, $text_columns );
	}

	/**
	 * True when one row's working-set cost does not fit the current batch budget.
	 * Same budget batches use: 0.25 * (memory_limit − memory_get_usage()), measured
	 * now. Working-set = MAX(LENGTH) × 6.5. Checked before SELECT * so the
	 * batch_rows minimum of 1 never forces an oversized row through.
	 *
	 * @param string             $table
	 * @param array<int, string> $text_columns
	 * @param int|null           $max_row_bytes Cached MAX(LENGTH) for this table, or null to query.
	 * @return bool
	 */
	private function table_has_oversized_row( $table, $text_columns = array(), $max_row_bytes = null ) {
		$max_row = null !== $max_row_bytes
			? $max_row_bytes
			: $this->max_row_text_bytes( $table, $text_columns );
		if ( null === $max_row || $max_row < 1 ) {
			return false;
		}
		$budget = $this->batch_byte_budget();
		if ( $budget < 1 ) {
			return true;
		}
		$working_set = (float) $max_row * self::KEYSET_ROW_MEMORY_MULTIPLIER;
		return $working_set > (float) $budget;
	}

	/**
	 * Keyset batch row count. Coordinator formula (×6.5 inside the 25% budget):
	 * batch_rows = max(1, floor(0.25 * (memory_limit − usage) / (max_row_bytes × 6.5))),
	 * capped at KEYSET_BATCH_ROWS_MAX. Pass $row_bytes to avoid re-querying MAX.
	 *
	 * @param string             $table
	 * @param array<int, string> $text_columns
	 * @param int|null           $row_bytes Cached MAX(LENGTH), or null to query.
	 * @return int
	 */
	private function keyset_batch_rows( $table, $text_columns = array(), $row_bytes = null ) {
		if ( null === $row_bytes ) {
			$row_bytes = $this->estimate_row_bytes( $table, $text_columns );
		}
		$budget = $this->batch_byte_budget(); // 0.25 * (limit − usage), min 1
		if ( null === $row_bytes || $row_bytes < 1 ) {
			// Uncertain → one row per batch.
			return 1;
		}
		// batch_rows = max(1, floor(budget / (max_row_bytes * 6.5)))
		$row_cost   = (float) $row_bytes * self::KEYSET_ROW_MEMORY_MULTIPLIER;
		$batch_rows = (int) floor( (float) $budget / $row_cost );
		return absint( max( 1, min( self::KEYSET_BATCH_ROWS_MAX, $batch_rows ) ) );
	}

	/**
	 * Calculate dynamic page size based on available memory and estimated row size.
	 * Uses runtime budget (25% of remaining headroom) with no 1000-row floor.
	 *
	 * @access private
	 * @param int $estimated_row_size Estimated bytes per row (default: 1024)
	 * @return int
	 */
	private function get_dynamic_page_size( $estimated_row_size = 1024 ) {
		$budget    = $this->batch_byte_budget();
		$row       = max( 1, (int) $estimated_row_size );
		$page_size = (int) floor( $budget / $row );
		$page_size = max( 1, min( self::KEYSET_BATCH_ROWS_MAX, $page_size ) );

		if ( $budget <= 1024 * 1024 ) {
			$this->log_line(
				' SYSLOG: Low memory - Page Size reduced to: ' . $page_size
			);
		}

		return absint( $page_size );
	}

	/**
	 * Convert memory limit string to bytes.
	 * @access private
	 * @param string $val Memory limit string (e.g., "512M")
	 * @return int
	 */
	private function convert_to_bytes($val) {
		$val = trim($val);
		$last = strtolower($val[strlen($val)-1]);
		$val = (int) $val;

		switch($last) {
			case 'g': $val *= 1024;
			case 'm': $val *= 1024;
			case 'k': $val *= 1024;
		}

		return $val;
	}

	/**
	 * Format bytes to human readable format.
	 * @access private
	 * @param int $bytes
	 * @return string
	 */
	private function format_bytes($bytes) {
		$units = ['B', 'KB', 'MB', 'GB'];
		$bytes = max($bytes, 0);
		$pow = floor(($bytes ? log($bytes) : 0) / log(1024));
		$pow = min($pow, count($units) - 1);
		$bytes /= (1 << (10 * $pow));
		return round($bytes, 2) . ' ' . $units[$pow];
	}

	/**
	 * Returns the number of pages in a table.
	 * @access public
	 * @return int
	 */
	public function get_pages_in_table( $table ) {
		$table 	= esc_sql( $table );
		$rows 	= $this->wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
		$pages 	= ceil( $rows / $this->page_size );
		return absint( $pages );
	}


	/**
	 * Exact log line when a large keyless table still holds the old site URL.
	 */
	const LOG_URL_TABLE_UNPAGEABLE = 'Error: Table "%s" has no primary or unique key, so its site addresses can\'t be updated safely.';

	/**
	 * Exact log line when a single row is too large to load safely (~25% working-set).
	 */
	const LOG_URL_TABLE_ROW_TOO_LARGE = 'Error: Table "%s" has a row too large to update its site addresses safely with this site\'s PHP memory limit.';

	/**
	 * Exact log line when keyset paging makes no progress (stuck last key).
	 */
	const LOG_URL_TABLE_PAGING_STUCK = 'Error: Table "%s" stopped making progress while its site addresses were being updated.';

	/**
	 * Gets the columns in a table.
	 * @access public
	 * @param  string $table The table to check.
	 * @return array{0: array<int, string>, 1: array<int, string>, 2: array<string, string>}
	 *         Primary-key column names, all columns, and Field=>Type map.
	 */
	public function get_columns( $table ) {
		$primary_keys = array();
		$columns      = array();
		$types        = array();
		$fields       = $this->wpdb->get_results( 'DESCRIBE ' . $table );

		if ( is_array( $fields ) ) {
			foreach ( $fields as $column ) {
				$columns[] = $column->Field;
				$types[ $column->Field ] = isset( $column->Type ) ? (string) $column->Type : '';
				if ( $column->Key == 'PRI' ) {
					$primary_keys[] = $column->Field;
				}
			}
		}

		return array( $primary_keys, $columns, $types );
	}

	/**
	 * Quote a MySQL identifier (backticks).
	 *
	 * @param string $name
	 * @return string
	 */
	private function quote_identifier( $name ) {
		return '`' . str_replace( '`', '``', (string) $name ) . '`';
	}

	/**
	 * Build a SQL literal for keyset comparison (quoted string form used elsewhere).
	 *
	 * @param mixed $value
	 * @return string
	 */
	private function sql_quoted_literal( $value ) {
		return '"' . $this->mysql_escape_mimic( $value ) . '"';
	}

	/**
	 * WHERE equality for a column value. NULL must use IS NULL — `col = ""`
	 * does not match SQL NULL and would skip those rows on keyless updates.
	 *
	 * @param string $column Unquoted column name.
	 * @param mixed  $value
	 * @return string
	 */
	private function sql_where_equals( $column, $value ) {
		if ( null === $value ) {
			return $column . ' IS NULL';
		}
		return $column . ' = "' . $this->mysql_escape_mimic( $value ) . '"';
	}

	/**
	 * Columns that can safely keyset-page this table: full PRIMARY, else the
	 * first UNIQUE index whose columns are all NOT NULL. Unique indexes that
	 * allow NULL are rejected (NULL key values become '' in the next-batch
	 * predicate and silently skip remaining NULL/empty rows). Empty when no
	 * safe key exists — caller treats the table as keyless.
	 *
	 * @param string $table
	 * @param array  $describe_primary PRI columns from DESCRIBE (fallback).
	 * @return array<int, string>
	 */
	private function get_paging_key_columns( $table, $describe_primary = array() ) {
		$indexes = $this->wpdb->get_results( 'SHOW INDEX FROM `' . esc_sql( $table ) . '`' );
		$primary = array();
		$uniques = array();
		$unique_allows_null = array();
		if ( is_array( $indexes ) ) {
			foreach ( $indexes as $idx ) {
				$name = isset( $idx->Key_name ) ? (string) $idx->Key_name : '';
				$col  = isset( $idx->Column_name ) ? (string) $idx->Column_name : '';
				$seq  = isset( $idx->Seq_in_index ) ? (int) $idx->Seq_in_index : 0;
				if ( $name === '' || $col === '' || $seq < 1 ) {
					continue;
				}
				$non_unique = isset( $idx->Non_unique ) ? (int) $idx->Non_unique : 1;
				if ( $name === 'PRIMARY' ) {
					$primary[ $seq ] = $col;
					continue;
				}
				if ( 0 === $non_unique ) {
					if ( ! isset( $uniques[ $name ] ) ) {
						$uniques[ $name ] = array();
						$unique_allows_null[ $name ] = false;
					}
					$uniques[ $name ][ $seq ] = $col;
					// SHOW INDEX Null is 'YES' when the column may be NULL, else ''.
					$null_raw = isset( $idx->Null ) ? strtoupper( (string) $idx->Null ) : '';
					if ( $null_raw === 'YES' ) {
						$unique_allows_null[ $name ] = true;
					}
				}
			}
		}
		if ( $primary ) {
			ksort( $primary, SORT_NUMERIC );
			return array_values( $primary );
		}
		foreach ( $uniques as $name => $cols ) {
			if ( ! empty( $unique_allows_null[ $name ] ) ) {
				continue;
			}
			ksort( $cols, SORT_NUMERIC );
			return array_values( $cols );
		}
		if ( is_array( $describe_primary ) && $describe_primary ) {
			return array_values( $describe_primary );
		}
		return array();
	}

	/**
	 * @param array<string, string> $types Field=>Type from DESCRIBE.
	 * @return array<int, string>
	 */
	private function text_columns_from_types( $types ) {
		$text = array();
		if ( ! is_array( $types ) ) {
			return $text;
		}
		foreach ( $types as $field => $type ) {
			$t = strtolower( (string) $type );
			if ( preg_match( '/char|text|blob|json|enum|set/i', $t ) ) {
				$text[] = $field;
			}
		}
		return $text;
	}

	/**
	 * True when any text column may hold one of the bare search hosts
	 * (e.g. old.com — not the full URL). Bare-host LIKE matches JSON-escaped
	 * (https:\/\/old.com), protocol-relative (//old.com), and serialized forms.
	 *
	 * @param string             $table
	 * @param array<int, string> $text_columns
	 * @param array<int, string> $host_needles Bare hosts only.
	 * @return bool
	 */
	private function table_contains_host_needles( $table, $text_columns, $host_needles ) {
		if ( ! $text_columns || ! $host_needles ) {
			return false;
		}
		$likes = array();
		foreach ( $text_columns as $column ) {
			$col_sql = $this->quote_identifier( $column );
			foreach ( $host_needles as $host ) {
				$host = strtolower( (string) $host );
				if ( $host === '' || false !== strpos( $host, '/' ) || false !== strpos( $host, ':' ) ) {
					// Guard: never LIKE a full URL / scheme — only bare hosts.
					continue;
				}
				$likes[] = $col_sql . " LIKE \"%" . $this->mysql_escape_mimic( $host ) . "%\"";
			}
		}
		if ( ! $likes ) {
			return false;
		}
		$sql = 'SELECT 1 FROM `' . esc_sql( $table ) . '` WHERE ' . implode( ' OR ', $likes ) . ' LIMIT 1';
		$hit = $this->wpdb->get_var( $sql );
		return null !== $hit && false !== $hit && '' !== $hit;
	}

	/**
	 * Table payload bytes for the keyless whole-vs-stop decision.
	 *
	 * Prefer information_schema DATA_LENGTH (after ANALYZE TABLE). When that is
	 * missing, zero, or unreliable, fall back to SUM(LENGTH(...)) over text and
	 * blob columns only. Never uses rows×AVG_ROW_LENGTH. Returns null when size
	 * is still uncertain (query failure / NULL) so the caller treats the table
	 * as large and takes the keyless stop path.
	 *
	 * @param string $table
	 * @return int|null
	 */
	private function estimate_table_bytes( $table ) {
		$table_sql = esc_sql( $table );
		$schema    = defined( 'DB_NAME' ) ? (string) DB_NAME : '';

		if ( method_exists( $this->wpdb, 'query' ) ) {
			$this->wpdb->query( 'ANALYZE TABLE `' . $table_sql . '`' );
		}

		$data_length = null;
		if ( $schema !== '' && method_exists( $this->wpdb, 'prepare' )
			&& method_exists( $this->wpdb, 'get_row' )
		) {
			if ( is_object( $this->wpdb ) ) {
				$this->wpdb->last_error = '';
			}
			$row = $this->wpdb->get_row(
				$this->wpdb->prepare(
					'SELECT DATA_LENGTH AS data_length
					FROM information_schema.TABLES
					WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
					$schema,
					$table
				)
			);
			$query_failed = is_object( $this->wpdb ) && ! empty( $this->wpdb->last_error );
			if ( ! $query_failed && is_object( $row ) && property_exists( $row, 'data_length' )
				&& null !== $row->data_length && '' !== $row->data_length
			) {
				$data_length = (int) $row->data_length;
			}
		}

		if ( null !== $data_length && $data_length > 0 ) {
			return $data_length;
		}

		// DATA_LENGTH missing/zero/unreliable → measure text/blob payload.
		list( , , $types ) = $this->get_columns( $table );
		$text_columns = $this->text_columns_from_types( is_array( $types ) ? $types : array() );
		if ( ! $text_columns ) {
			return null;
		}

		$length_parts = array();
		foreach ( $text_columns as $column ) {
			$length_parts[] = 'COALESCE(LENGTH(' . $this->quote_identifier( $column ) . '), 0)';
		}
		if ( is_object( $this->wpdb ) ) {
			$this->wpdb->last_error = '';
		}
		// Outer SUM (not COALESCE): MySQL returns NULL for an empty table or on
		// failure — both are uncertain and must not be treated as a small size.
		$sum_sql = 'SELECT SUM(' . implode( ' + ', $length_parts ) . ') FROM `' . $table_sql . '`';
		$sum_raw = $this->wpdb->get_var( $sum_sql );
		$query_failed = is_object( $this->wpdb ) && ! empty( $this->wpdb->last_error );
		if ( $query_failed || null === $sum_raw || false === $sum_raw || '' === $sum_raw ) {
			return null;
		}

		return (int) $sum_raw;
	}

	/**
	 * Whether a keyless table is small enough to load in one pass (~3.1.18).
	 *
	 * @param int $estimated_bytes
	 * @return bool
	 */
	private function keyless_table_fits_whole_pass( $estimated_bytes ) {
		$memory_limit = ini_get( 'memory_limit' );
		if ( $memory_limit === false || $memory_limit === '' || (string) $memory_limit === '-1' ) {
			return true;
		}
		$memory_bytes = $this->convert_to_bytes( (string) $memory_limit );
		if ( $memory_bytes <= 0 ) {
			return true;
		}
		return (int) $estimated_bytes <= (int) floor( $memory_bytes * 0.25 );
	}

	/**
	 * Adapated from interconnect/it's search/replace script.
	 *
	 * Modified to use WordPress wpdb functions instead of PHP's native mysql/pdo functions,
	 * and to be compatible with batch processing via AJAX.
	 *
	 * @link https://interconnectit.com/products/search-and-replace-for-wordpress-databases/
	 *
	 * A restore spread over several workers passes, besides the replacement args:
	 * - should_yield: callable, true once the worker must stop. Checked every
	 *   YIELD_CHECK_ROWS rows and after each page of a keyset table.
	 * - resume_key: keyset position (values in key-column order) the first
	 *   table's rows up to and including were already rewritten.
	 * - continuing: an earlier call already logged the start line.
	 * - log_summary: false leaves the closing peak_bytes line to the caller.
	 * A yielded report has 'yielded' => true and 'cursor' => array('table',
	 * 'last_key'): the table to continue with and its keyset position.
	 *
	 * The Search & Replace tool also passes (restores leave them out, and
	 * behave as before):
	 * - match_case: true matches the search text case for case. Default false.
	 * - needles: the plain search strings. A cell, or a table without a
	 *   primary key, that contains none of them is skipped unread. Without it
	 *   the host of a URL-shaped search is used, as for restores.
	 * - skip_columns: column names never changed (for example guid).
	 * - skip_row: callable(table, row) returning true for a row to leave as it is.
	 * - dry_run: true finds and counts what would change but writes nothing.
	 * - sample_limit: how many before/after snippets a dry run keeps.
	 * - log: callable taking one line, instead of the migration log.
	 * - start_line: the first line written, instead of "Starting to update
	 *   URLs in database"; '' writes none.
	 * - allow_objects: false reads objects in serialized values as data and
	 *   never creates them, so no class code runs. Text inside them is left
	 *   unchanged, and the report counts those cells in 'objects_skipped'.
	 *   Default true.
	 * The report also has 'tables' => array(table => array('rows', 'cells',
	 * 'columns' => array(column => cells))) for rows written (or, in a dry
	 * run, that would be), and 'samples' in a dry run.
	 *
	 * @access public
	 * @param  array $tables Tables to run the replacement on.
	 * @param  array $args   Replacement args; optional page_size for tests.
	 * @return array
	 */
	public function search_replace_db( $tables, $args ) {
		$this->match_case = ! empty( $args['match_case'] );
		$this->log_sink   = isset( $args['log'] ) && is_callable( $args['log'] ) ? $args['log'] : null;
		$this->allow_objects = ! isset( $args['allow_objects'] ) || (bool) $args['allow_objects'];
		$dry_run          = ! empty( $args['dry_run'] );
		$sample_limit     = isset( $args['sample_limit'] ) ? max( 0, (int) $args['sample_limit'] ) : 0;
		$skip_row         = isset( $args['skip_row'] ) && is_callable( $args['skip_row'] ) ? $args['skip_row'] : null;
		$skip_columns     = array();
		if ( isset( $args['skip_columns'] ) && is_array( $args['skip_columns'] ) ) {
			foreach ( $args['skip_columns'] as $skip_column ) {
				$skip_columns[ strtolower( (string) $skip_column ) ] = true;
			}
		}

		if ( empty( $args['continuing'] ) ) {
			$start_line = array_key_exists( 'start_line', $args ) ? (string) $args['start_line'] : 'Starting to update URLs in database';
			if ( '' !== $start_line ) {
				$this->log_line( $start_line );
			}
		}
		$should_yield = isset( $args['should_yield'] ) && is_callable( $args['should_yield'] )
			? $args['should_yield']
			: null;
		$resume_key = isset( $args['resume_key'] ) && is_array( $args['resume_key'] ) && $args['resume_key']
			? array_values( $args['resume_key'] )
			: null;
		$tables = is_array( $tables ) ? array_values( $tables ) : array();

		$pairs = $this->replacement_pairs_from_args( $args );
		// Plain needles: the tool's own search text. Host needles: the host
		// inside a URL-shaped restore pair. Both only decide what to skip unread.
		$plain_needles = array();
		if ( isset( $args['needles'] ) && is_array( $args['needles'] ) ) {
			foreach ( $args['needles'] as $needle ) {
				if ( is_string( $needle ) && '' !== $needle ) {
					$plain_needles[] = $needle;
				}
			}
		}
		$host_needles = $plain_needles ? $plain_needles : $this->host_needles_from_pairs( $pairs );
		// Tests may force a fixed batch via $args['page_size']. Otherwise each
		// keyset table sizes its own batch from a byte budget / row estimate.
		$forced_page_size = null;
		if ( isset( $args['page_size'] ) ) {
			$forced_page_size = max( 1, (int) $args['page_size'] );
			$this->page_size  = $forced_page_size;
		} else {
			$this->page_size = $this->get_page_size();
		}

		$table_report = array(
			'change' 	=> 0,
			'updates' 	=> 0,
			'start' 	=> microtime( true ),
			'end'		=> microtime( true ),
			'errors' 	=> array(),
			'failed_pairs' => array(),
			'skipped' 	=> false,
			'unpageable_table' => null,
			'oversized_table' => null,
			'paging_stuck_table' => null,
			'yielded' => false,
			'cursor' => null,
			'tables' => array(),
			'samples' => array(),
			'objects_skipped' => 0,
		);

		foreach ($tables as $table_index => $table) {
			// Load up the default settings for this chunk.
			$table = esc_sql( $table );

			list( $describe_primary, $columns, $types ) = $this->get_columns( $table );
			if ( ! is_array( $describe_primary ) ) {
				$describe_primary = array();
			}
			if ( ! is_array( $columns ) ) {
				$columns = array();
			}
			if ( ! is_array( $types ) ) {
				$types = array();
			}

			$key_columns = $this->get_paging_key_columns( $table, $describe_primary );
			$text_columns = $this->text_columns_from_types( $types );
			if ( ! $text_columns ) {
				// No typed text columns from DESCRIBE — still consider all columns.
				$text_columns = $columns;
			}

			$path = 'keyset';
			if ( ! $key_columns ) {
				$has_match = $plain_needles
					? $this->table_contains_plain_needles( $table, $text_columns, $plain_needles )
					: $this->table_contains_host_needles( $table, $text_columns, $host_needles );
				if ( ! $has_match ) {
					$this->log_line( sprintf(
						'SYSLOG: URL update: table "%s" path=no-match',
						$table
					) );
					continue;
				}
				$estimated = $this->estimate_table_bytes( $table );
				// Uncertain size (null) ⇒ treat as large ⇒ dedicated keyless stop.
				if ( null !== $estimated && $this->keyless_table_fits_whole_pass( $estimated ) ) {
					$path = 'whole';
					$this->log_line( sprintf(
						'SYSLOG: URL update: table "%s" path=whole',
						$table
					) );
				} else {
					$table_report['unpageable_table'] = $table;
					$this->log_line( sprintf(
						self::LOG_URL_TABLE_UNPAGEABLE,
						$table
					) );
					break;
				}
			}

			// Largest-row LENGTH once per table (not per batch — MAX() is expensive).
			$cached_row_bytes = $this->max_row_text_bytes( $table, $text_columns );

			// Before any SELECT *: refuse when max_row×6.5 exceeds the same
			// batch budget (0.25 × remaining headroom). Never fall through to
			// the batch_rows=1 minimum for a row that cannot fit.
			if ( $this->table_has_oversized_row( $table, $text_columns, $cached_row_bytes ) ) {
				$table_report['oversized_table'] = $table;
				$this->log_line( sprintf(
					self::LOG_URL_TABLE_ROW_TOO_LARGE,
					$table
				) );
				break;
			}

			if ( 'keyset' === $path ) {
				if ( null === $forced_page_size ) {
					$this->page_size = $this->keyset_batch_rows( $table, $text_columns, $cached_row_bytes );
				}
				$this->log_line( sprintf(
					'SYSLOG: URL update: table "%s" path=keyset batch_rows=%d budget=%d',
					$table,
					(int) $this->page_size,
					(int) $this->batch_byte_budget()
				) );
			}

			$key_set = array_fill_keys( $key_columns, true );
			$order_sql = '';
			$key_tuple_sql = '';
			if ( $key_columns ) {
				$key_sql_parts = array();
				foreach ( $key_columns as $key_col ) {
					$key_sql_parts[] = $this->quote_identifier( $key_col );
				}
				$key_tuple_sql = '(' . implode( ', ', $key_sql_parts ) . ')';
				$order_sql     = implode( ', ', array_map(
					static function ( $ident ) {
						return $ident . ' ASC';
					},
					$key_sql_parts
				) );
			}

			$current_row = 0;
			$last_key    = null; // list of key values in column order, or null.
			if ( 0 === $table_index && null !== $resume_key ) {
				// Rows up to the saved position are already rewritten; a second
				// pass could replace inside a new value that contains the old one.
				if ( 'keyset' !== $path || count( $resume_key ) !== count( $key_columns ) ) {
					$table_report['errors'][] = sprintf( 'Could not resume %s at its saved position.', $table );
					break;
				}
				$last_key = $resume_key;
			}
			$yield_now = false;

			while ( true ) {
				$this->touch_replace_limits();
				// Recompute budget each batch; reuse cached MAX(LENGTH) for the table.
				if ( 'keyset' === $path && null === $forced_page_size ) {
					$this->page_size = $this->keyset_batch_rows( $table, $text_columns, $cached_row_bytes );
				}
				$limit = (int) $this->page_size;
				if ( $limit < 1 ) {
					$limit = 1;
				}

				$batch_start_key = $last_key;

				if ( 'whole' === $path ) {
					$data = $this->wpdb->get_results(
						"SELECT * FROM `{$table}`",
						ARRAY_A
					);
				} elseif ( null === $last_key ) {
					$data = $this->wpdb->get_results(
						"SELECT * FROM `{$table}` ORDER BY {$order_sql} LIMIT {$limit}",
						ARRAY_A
					);
				} else {
					$escaped_vals = array();
					foreach ( $last_key as $key_val ) {
						$escaped_vals[] = $this->sql_quoted_literal( $key_val );
					}
					$key_tuple_vals = '(' . implode( ', ', $escaped_vals ) . ')';
					$data = $this->wpdb->get_results(
						"SELECT * FROM `{$table}` WHERE {$key_tuple_sql} > {$key_tuple_vals} ORDER BY {$order_sql} LIMIT {$limit}",
						ARRAY_A
					);
				}

				if ( ! is_array( $data ) || empty( $data ) ) {
					// No rows: normal end on first page, or after a prior advance.
					// If a later keyset page returns nothing after a stuck driver
					// that previously returned a full page of the same key, the
					// advance guard below already fired. Empty ⇒ stop paging.
					break;
				}

				// Loop through the data.
				foreach ( $data as $row ) {
					$current_row++;
					$update_sql = array();
					$where_sql 	= array();
					$upd 		= false;
					$row_matched_pairs = array();
					$row_changed_columns = array();
					$skip_this_row = $skip_row && call_user_func( $skip_row, (string) $table, $row );

					foreach ( $columns as $column ) {
						if ( $skip_this_row ) {
							break;
						}

						$data_to_fix = $row[ $column ];

						if ( $key_columns && isset( $key_set[ $column ] ) ) {
							$where_sql[] = $this->sql_where_equals( $column, $data_to_fix );
							continue;
						}

						if ( $skip_columns && isset( $skip_columns[ strtolower( (string) $column ) ] ) ) {
							if ( ! $key_columns ) {
								$where_sql[] = $this->sql_where_equals( $column, $data_to_fix );
							}
							continue;
						}

						if ( is_array( $this->url_found ) ) {
							OCM_Extra_Domains::collect_from_text( $this->url_found, $data_to_fix );
						}

						// Skip cells that cannot contain any search host — pairs are
						// URL-shaped and scanning every longtext cell is expensive.
						if ( is_string( $data_to_fix ) && $host_needles && ! $this->cell_may_contain_host( $data_to_fix, $host_needles ) ) {
							// Still need this column in a keyless WHERE below.
							if ( ! $key_columns ) {
								$where_sql[] = $this->sql_where_equals( $column, $data_to_fix );
							}
							continue;
						}

						// One-pass longest-match replace (no sequential double replace).
						$cell_matches = array();
						$this->cell_object_match = false;
						$edited_data = $this->apply_replacement_pairs( $data_to_fix, $pairs, $cell_matches );
						if ( $this->cell_object_match ) {
							$table_report['objects_skipped']++;
						}

						// Something was changed
						if ( $edited_data != $data_to_fix ) {
							$update_sql[] = $column . ' = "' . $this->mysql_escape_mimic( $edited_data ) . '"';
							$upd = true;
							$table_report['change']++;
							$row_changed_columns[] = (string) $column;
							if ( $dry_run && count( $table_report['samples'] ) < $sample_limit ) {
								$table_report['samples'][] = $this->change_sample( $table, $column, $data_to_fix, $edited_data );
							}
							foreach ( $cell_matches as $match_key => $match_pair ) {
								if ( ! isset( $row_matched_pairs[ $match_key ] ) ) {
									$row_matched_pairs[ $match_key ] = array(
										'search'  => $match_pair['search'],
										'replace' => $match_pair['replace'],
										'table'   => $table,
										'column'  => $column,
									);
								}
							}
						} elseif ( ! $key_columns ) {
							// Keyless whole-table: pin the row by original cell values.
							$where_sql[] = $this->sql_where_equals( $column, $data_to_fix );
						}

					}

					// Keyless whole-table: also pin columns that were used as keys would be.
					if ( ! $key_columns && $upd ) {
						foreach ( $columns as $column ) {
							// where_sql already collected originals for unchanged cells;
							// for changed cells the SET has the new value — WHERE must
							// use the pre-image (already skipped into where only when unchanged).
							// Re-add pre-image for changed columns.
							$already = false;
							$eq_prefix = $column . ' = "';
							$null_clause = $column . ' IS NULL';
							foreach ( $where_sql as $clause ) {
								if ( strpos( $clause, $eq_prefix ) === 0 || $clause === $null_clause ) {
									$already = true;
									break;
								}
							}
							if ( ! $already && array_key_exists( $column, $row ) ) {
								$where_sql[] = $this->sql_where_equals( $column, $row[ $column ] );
							}
						}
					}

				 if ( $upd && $dry_run ) {
						$table_report['updates']++;
						$this->count_row_change( $table_report, $table, $row_changed_columns );
					} elseif ( $upd && ! empty( $where_sql ) ) {
						// Prefer the raw mysqli path so $wpdb->strip_invalid_text cannot
						// reject otherwise-valid UPDATEs (field cases aa8fe3a4 / 0afb3661).
						$sql 	= 'UPDATE ' . $table . ' SET ' . implode( ', ', $update_sql ) . ' WHERE ' . implode( ' AND ', array_filter( $where_sql ) );
						$this->wpdb->last_error = '';
						$raw_ok = OCM_SQL_Utils::query_imported_sql( $this->wpdb, $sql );

						$failed = ( false === $raw_ok );
						$affected = null;
						if ( ! $failed ) {
							$affected = $this->last_affected_rows();
							// 0 rows affected after we changed the in-memory value means
							// the WHERE matched nothing — keep as failure (B5).
							if ( null !== $affected && $affected < 1 ) {
								$failed = true;
							}
						}

						if ( $failed ) {
							$error_detail = '';
							if ( ! empty( $this->wpdb->last_error ) ) {
								$error_detail = ' ' . $this->wpdb->last_error;
							} elseif ( null !== $affected && $affected < 1 ) {
								$error_detail = ' (0 rows affected)';
							}
							$table_report['errors'][] = sprintf(
								/* translators: 1: table name, 2: row number, 3: optional DB error */
								__( 'Error updating %1$s row %2$d.%3$s', '1-click-migration' ),
								$table,
								$current_row,
								$error_detail
							);
							foreach ( $row_matched_pairs as $match_key => $failed_pair ) {
								if ( ! isset( $table_report['failed_pairs'][ $match_key ] ) ) {
									$table_report['failed_pairs'][ $match_key ] = $failed_pair;
								}
							}
						} else {
							$table_report['updates']++;
							$this->count_row_change( $table_report, $table, $row_changed_columns );
						}

					}

					if ( $key_columns ) {
						$next_key = array();
						$key_complete = true;
						foreach ( $key_columns as $key_col ) {
							if ( ! array_key_exists( $key_col, $row ) ) {
								$key_complete = false;
								break;
							}
							$next_key[] = $row[ $key_col ];
						}
						if ( $key_complete ) {
							$last_key = $next_key;
						}
					}

					// Every row before $last_key is done, so a long page can stop
					// here and the next worker continues after it.
					if ( $should_yield && 'keyset' === $path && null !== $last_key
						&& 0 === ( $current_row % self::YIELD_CHECK_ROWS )
						&& call_user_func( $should_yield )
					) {
						$yield_now = true;
						break;
					}

				} // end row loop

				// Flush the results after each page.
				$this->wpdb->flush();

				// Per-batch peak so OOM investigations see growth across pages.
				$this->log_line( sprintf(
					'SYSLOG: URL update: table "%s" batch_rows=%d peak_bytes=%d',
					$table,
					(int) count( $data ),
					(int) memory_get_peak_usage( true )
				) );

				// Keyset loop guard: if the last key did not advance (or no key
				// could be read), stop cleanly — a driver that ignores WHERE would
				// otherwise loop forever on 1-row batches.
				if ( 'keyset' === $path && $key_columns ) {
					$key_stuck = ( null === $last_key )
						|| ( null !== $batch_start_key && $last_key === $batch_start_key );
					if ( $key_stuck ) {
						$table_report['paging_stuck_table'] = $table;
						$this->log_line( sprintf(
							self::LOG_URL_TABLE_PAGING_STUCK,
							$table
						) );
						break 2;
					}
				}

				if ( ! $yield_now && ( 'whole' === $path || count( $data ) < $limit ) ) {
					break;
				}

				if ( $yield_now || ( $should_yield && 'keyset' === $path && call_user_func( $should_yield ) ) ) {
					$table_report['yielded'] = true;
					$table_report['cursor'] = array( 'table' => (string) $table, 'last_key' => $last_key );
					break 2;
				}

			} // end page loop

			$table_report['end'] = microtime( true );

		}

		$table_report['failed_pairs'] = array_values( $table_report['failed_pairs'] );
		$table_report['end'] = microtime( true );
		// On dedicated stop paths, keep the Error line adjacent to
		// LOG_RESTORE_STOPPED_IMPORT — skip trailing peak_bytes SYSLOG.
		if ( empty( $table_report['unpageable_table'] )
			&& empty( $table_report['oversized_table'] )
			&& empty( $table_report['paging_stuck_table'] )
			&& ( ! isset( $args['log_summary'] ) || $args['log_summary'] )
		) {
			$this->log_line( sprintf(
				'SYSLOG: URL update: peak_bytes=%d memory_limit=%s',
				(int) memory_get_peak_usage( true ),
				(string) ini_get( 'memory_limit' )
			) );
		}
		return $table_report;
	}

	/**
	 * Adapated from interconnect/it's search/replace script.
	 *
	 * @link https://interconnectit.com/products/search-and-replace-for-wordpress-databases/
	 *
	 * Take a serialised array and unserialise it replacing elements as needed and
	 * unserialising any subordinate arrays and performing the replace on those too.
	 *
	 * @access private
	 * @param  string 			$from       		String we're looking to replace.
	 * @param  string 			$to         		What we want it to be replaced with
	 * @param  array  			$data       		Used to pass any subordinate arrays back to in.
	 * @param  boolean 			$serialised 		Does the array passed via $data need serialising.
	 *
	 * @param boolean      $url_boundary Require a URL component boundary after the match.
	 * @return string|array	The original array with all elements replaced as needed.
	 */
	public function recursive_unserialize_replace( $from = '', $to = '', $data = '', $serialised = false, $url_boundary = false ) {


		try {


			if ( is_string( $data ) && ! is_serialized_string( $data ) && ( $unserialized = $this->unserialize( $data, $this->allow_objects ) ) !== false ) {
				$data = $this->recursive_unserialize_replace( $from, $to, $unserialized, true, $url_boundary );

			}

			elseif ( is_array( $data ) ) {
				$_tmp = array( );
				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->recursive_unserialize_replace( $from, $to, $value, false, $url_boundary );
				}

				$data = $_tmp;
				unset( $_tmp );

			}


			// Submitted by Tina Matter
			elseif ( is_object( $data ) && ! is_a( $data, '__PHP_Incomplete_Class' ) ) {
				// $data_class = get_class( $data );
				$_tmp = $data; // new $data_class( );
				$props = get_object_vars( $data );
				foreach ( $props as $key => $value ) {
					if(!is_a($value, '__PHP_Incomplete_Class')){
						$_tmp->$key = $this->recursive_unserialize_replace( $from, $to, $value, false, $url_boundary );
					}
				}

				$data = $_tmp;

				unset( $_tmp );
			}

			elseif ( is_serialized_string( $data ) ) {

				$data = $this->unserialize( $data, $this->allow_objects );
				if ( $data !== false ) {

					$data = $this->recursive_unserialize_replace( $from, $to, $data, false, $url_boundary );
					$data = serialize( $data );
				}

			}

			else {
				if ( is_string( $data ) ) {
					$data = $this->str_replace( $from, $to, $data, $url_boundary );
				}
			}

			if ( $serialised ) {

				return serialize( $data );
			}



		} catch( Exception $error ) {

		}

		return $data;
	}

	/**
	 * Mimics the mysql_real_escape_string function. Adapted from a post by 'feedr' on php.net.
	 * @link   http://php.net/manual/en/function.mysql-real-escape-string.php#101248
	 * @access public
	 * @param  string $input The string to escape.
	 * @return string
	 */
	public function mysql_escape_mimic( $input ) {
	    if ( is_array( $input ) ) {
	        return array_map( __METHOD__, $input );
	    }
	    if ( ! empty( $input ) && is_string( $input ) ) {
	        return str_replace( array( '\\', "\0", "\n", "\r", "'", '"', "\x1a" ), array( '\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z' ), $input );
	    }

	    return $input;
	}

	/**
	 * Return unserialized object or array
	 *
	 * @param string $serialized_string Serialized string.
	 * @param bool   $allow_objects     False reads objects as data
	 *                                  (__PHP_Incomplete_Class) and never
	 *                                  creates them, so no class code runs.
	 *
	 * @return mixed, false on failure
	 */
	public static function unserialize( $serialized_string, $allow_objects = true ) {

		if ( ! is_serialized( $serialized_string ) ) {

			return false;
		}


		$serialized_string   = trim( $serialized_string );
		if ( ! $allow_objects ) {
			return @unserialize( $serialized_string, array( 'allowed_classes' => false ) );
		}
		$unserialized_string = @unserialize( $serialized_string );

		return $unserialized_string;
	}

	/**
	 * Wrapper for str_replace
	 *
	 * @param string $from
	 * @param string $to
	 * @param string $data
	 *
	 * @return string
	 */
	public function str_replace( $from, $to, $data, $url_boundary = false ) {
		if ( $url_boundary ) {
			// Match the end of the authority/path component, never a longer host,
			// another port, userinfo, or a longer temporary username.
			$pattern = '~' . preg_quote( $from, '~' ) . '(?=$|[/?#\s"\'<>\\\\])~' . ( $this->match_case ? '' : 'i' );
			return preg_replace_callback( $pattern, function () use ( $to ) {
				return $to;
			}, $data );
		}


			$data = $this->match_case ? str_replace( $from, $to, $data ) : str_ireplace( $from, $to, $data );

		return $data;
	}

	/**
	 * One search/replace pair, or several ordered pairs when 'pairs' is set.
	 *
	 * @param array $args
	 * @return array<int, array{search: string, replace: string}>
	 */
	private function replacement_pairs_from_args( $args ) {
		$pairs = array();
		if ( isset( $args['pairs'] ) && is_array( $args['pairs'] ) ) {
			$pairs = $args['pairs'];
		} else {
			$pairs[] = array(
				'search'  => isset( $args['search_for'] ) ? $args['search_for'] : '',
				'replace' => isset( $args['replace_with'] ) ? $args['replace_with'] : '',
			);
		}

		$unique = array();
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['search'] ) || '' === $pair['search'] ) {
				continue;
			}
			$unique[ $pair['search'] ] = array(
				'search'  => $pair['search'],
				'replace' => isset( $pair['replace'] ) ? $pair['replace'] : '',
				'url_boundary' => ! empty( $pair['url_boundary'] ),
			);
		}
		$pairs = array_values( $unique );
		usort(
			$pairs,
			function ( $a, $b ) {
				$len = strlen( $b['search'] ) - strlen( $a['search'] );
				if ( 0 !== $len ) {
					return $len;
				}
				return strcmp( $a['search'], $b['search'] );
			}
		);
		return $pairs;
	}

	/**
	 * Count one written (or, in a dry run, found) row per table and column.
	 *
	 * @param array  $report
	 * @param string $table
	 * @param array  $columns Columns changed in this row.
	 */
	private function count_row_change( &$report, $table, $columns ) {
		$table = (string) $table;
		if ( ! isset( $report['tables'][ $table ] ) ) {
			$report['tables'][ $table ] = array( 'rows' => 0, 'cells' => 0, 'columns' => array() );
		}
		$report['tables'][ $table ]['rows']++;
		foreach ( $columns as $column ) {
			$report['tables'][ $table ]['cells']++;
			if ( ! isset( $report['tables'][ $table ]['columns'][ $column ] ) ) {
				$report['tables'][ $table ]['columns'][ $column ] = 0;
			}
			$report['tables'][ $table ]['columns'][ $column ]++;
		}
	}

	/**
	 * A short before/after pair around the first change in one cell.
	 *
	 * @param string $table
	 * @param string $column
	 * @param mixed  $before
	 * @param mixed  $after
	 * @return array{table: string, column: string, before: string, after: string}
	 */
	private function change_sample( $table, $column, $before, $after ) {
		$before = (string) $before;
		$after  = (string) $after;
		$max    = min( strlen( $before ), strlen( $after ) );
		$start  = 0;
		while ( $start < $max && $before[ $start ] === $after[ $start ] ) {
			$start++;
		}
		$end_before = strlen( $before );
		$end_after  = strlen( $after );
		while ( $end_before > $start && $end_after > $start && $before[ $end_before - 1 ] === $after[ $end_after - 1 ] ) {
			$end_before--;
			$end_after--;
		}
		$from = max( 0, $start - self::SAMPLE_CONTEXT );
		return array(
			'table'  => (string) $table,
			'column' => (string) $column,
			'before' => $this->sample_slice( $before, $from, $end_before + self::SAMPLE_CONTEXT ),
			'after'  => $this->sample_slice( $after, $from, $end_after + self::SAMPLE_CONTEXT ),
		);
	}

	/**
	 * Bytes $from..$to of $text as valid UTF-8, with an ellipsis where cut.
	 * Very long changed spans keep their start only.
	 */
	private function sample_slice( $text, $from, $to ) {
		$to    = min( strlen( $text ), $to, $from + 4 * self::SAMPLE_CONTEXT );
		$slice = substr( $text, $from, $to - $from );
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$slice = mb_convert_encoding( $slice, 'UTF-8', 'UTF-8' );
		}
		return ( $from > 0 ? '…' : '' ) . $slice . ( $to < strlen( $text ) ? '…' : '' );
	}

	/**
	 * Whether a table without a usable key holds any of the search strings,
	 * so a table that can't contain a match is never read row by row.
	 *
	 * @param string             $table
	 * @param array<int, string> $text_columns
	 * @param array<int, string> $needles Plain search strings.
	 * @return bool
	 */
	private function table_contains_plain_needles( $table, $text_columns, $needles ) {
		if ( ! $text_columns || ! $needles ) {
			return false;
		}
		$likes = array();
		foreach ( $text_columns as $column ) {
			// One case-insensitive collation for every column type (blob,
			// binary or _bin text included): finding more than an exact-case
			// search would change is fine here, missing a match is not.
			$col_sql = 'CONVERT(' . $this->quote_identifier( $column ) . ' USING utf8mb4) COLLATE utf8mb4_general_ci';
			foreach ( $needles as $needle ) {
				$pattern = '%' . addcslashes( (string) $needle, '\\%_' ) . '%';
				$likes[] = $col_sql . ' LIKE "' . $this->mysql_escape_mimic( $pattern ) . '"';
			}
		}
		$sql = 'SELECT 1 FROM `' . esc_sql( $table ) . '` WHERE ' . implode( ' OR ', $likes ) . ' LIMIT 1';
		$hit = $this->wpdb->get_var( $sql );
		return null !== $hit && false !== $hit && '' !== $hit;
	}

	/**
	 * @param array $pairs
	 * @return array<int, string> Distinct bare host needles (never full URLs).
	 */
	private function host_needles_from_pairs( $pairs ) {
		$needles = array();
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || empty( $pair['search'] ) ) {
				continue;
			}
			$search = (string) $pair['search'];
			// JSON-escaped search strings still yield a bare host via parse_url
			// after unescaping slashes.
			$for_parse = str_replace( '\\/', '/', $search );
			$parts = parse_url( $for_parse );
			if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
				$needles[ strtolower( (string) $parts['host'] ) ] = true;
				continue;
			}
			// Protocol-relative or bare host fallback.
			if ( preg_match( '#^(?:https?:)?//([^/?#]+)#i', $for_parse, $m )
				|| preg_match( '#^([a-z0-9.-]+\.[a-z]{2,})(?:[:/?#]|$)#i', $for_parse, $m )
			) {
				$host = strtolower( $m[1] );
				if ( false !== strpos( $host, ':' ) ) {
					$host = substr( $host, 0, strpos( $host, ':' ) );
				}
				if ( $host !== '' ) {
					$needles[ $host ] = true;
				}
			}
		}
		return array_keys( $needles );
	}

	/**
	 * @param string             $cell
	 * @param array<int, string> $host_needles
	 * @return bool
	 */
	private function cell_may_contain_host( $cell, $host_needles ) {
		foreach ( $host_needles as $host ) {
			if ( $host !== '' && false !== stripos( $cell, $host ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return int|null
	 */
	private function last_affected_rows() {
		if ( is_object( $this->wpdb ) && isset( $this->wpdb->dbh ) && is_object( $this->wpdb->dbh ) ) {
			if ( property_exists( $this->wpdb->dbh, 'affected_rows' ) ) {
				return (int) $this->wpdb->dbh->affected_rows;
			}
		}
		if ( is_object( $this->wpdb ) && isset( $this->wpdb->rows_affected ) ) {
			return (int) $this->wpdb->rows_affected;
		}
		return null;
	}

	/**
	 * @param mixed $data
	 * @param array $pairs
	 * @param array|null $matched Populated with search→replace pairs that changed $data.
	 * @return mixed
	 */
	private function apply_replacement_pairs( $data, $pairs, &$matched = null ) {
		return $this->recursive_unserialize_replace_pairs( $pairs, $data, false, $matched );
	}

	/**
	 * Apply all pairs in one pass at each string leaf (longest-first alternation),
	 * so a destination that contains the source is never double-replaced.
	 *
	 * @param array $pairs
	 * @param mixed $data
	 * @param bool  $serialised
	 * @param array|null $matched
	 * @return mixed
	 */
	private function recursive_unserialize_replace_pairs( $pairs, $data, $serialised = false, &$matched = null ) {
		try {
			if ( is_string( $data ) && ! is_serialized_string( $data ) && ( $unserialized = $this->unserialize( $data, $this->allow_objects ) ) !== false ) {
				$data = $this->recursive_unserialize_replace_pairs( $pairs, $unserialized, true, $matched );
			} elseif ( is_array( $data ) ) {
				$_tmp = array();
				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->recursive_unserialize_replace_pairs( $pairs, $value, false, $matched );
				}
				$data = $_tmp;
				unset( $_tmp );
			} elseif ( is_object( $data ) && $this->allow_objects && ! is_a( $data, '__PHP_Incomplete_Class' ) ) {
				$_tmp = $data;
				$props = get_object_vars( $data );
				foreach ( $props as $key => $value ) {
					if ( ! is_a( $value, '__PHP_Incomplete_Class' ) ) {
						$_tmp->$key = $this->recursive_unserialize_replace_pairs( $pairs, $value, false, $matched );
					}
				}
				$data = $_tmp;
				unset( $_tmp );
			} elseif ( is_object( $data ) ) {
				// An object read as data: left exactly as it is. With objects
				// off, remember that this cell kept a match inside one.
				if ( ! $this->allow_objects && ! $this->cell_object_match && $this->value_holds_match( $pairs, $data ) ) {
					$this->cell_object_match = true;
				}
			} elseif ( is_serialized_string( $data ) ) {
				$data = $this->unserialize( $data, $this->allow_objects );
				if ( $data !== false ) {
					$data = $this->recursive_unserialize_replace_pairs( $pairs, $data, false, $matched );
					$data = serialize( $data );
				}
			} else {
				if ( is_string( $data ) ) {
					$data = $this->str_replace_pairs_one_pass( $pairs, $data, $matched );
				}
			}

			if ( $serialised ) {
				return serialize( $data );
			}
		} catch ( \Exception $error ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
		}

		return $data;
	}

	/**
	 * Whether a value that is left unchanged (an object read as data) holds
	 * text the pairs would have replaced, in any of its values at any depth.
	 *
	 * @param array $pairs
	 * @param mixed $value
	 * @return bool
	 */
	private function value_holds_match( $pairs, $value ) {
		if ( is_string( $value ) ) {
			return $this->str_replace_pairs_one_pass( $pairs, $value ) !== $value;
		}
		if ( is_object( $value ) ) {
			// The cast reads the properties without running any class code.
			$value = (array) $value;
			unset( $value['__PHP_Incomplete_Class_Name'] );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( $this->value_holds_match( $pairs, $item ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Single-pass replace using longest-first alternation.
	 *
	 * @param array $pairs
	 * @param string $data
	 * @param array|null $matched
	 * @return string
	 */
	private function str_replace_pairs_one_pass( $pairs, $data, &$matched = null ) {
		if ( ! is_string( $data ) || $data === '' || ! is_array( $pairs ) || ! $pairs ) {
			return $data;
		}

		$alts = array();
		$map  = array();
		foreach ( $pairs as $i => $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['search'] ) || $pair['search'] === '' ) {
				continue;
			}
			$name = 'p' . $i;
			$map[ $name ] = $pair;
			$quoted = preg_quote( (string) $pair['search'], '~' );
			if ( ! empty( $pair['url_boundary'] ) ) {
				$alts[] = '(?P<' . $name . '>' . $quoted . '(?=$|[/?#\s"\'<>\\\\]))';
			} else {
				$alts[] = '(?P<' . $name . '>' . $quoted . ')';
			}
		}
		if ( ! $alts ) {
			return $data;
		}

		$pattern = '~' . implode( '|', $alts ) . ( $this->match_case ? '~' : '~i' );
		return preg_replace_callback(
			$pattern,
			function ( $m ) use ( $map, &$matched ) {
				foreach ( $map as $name => $pair ) {
					if ( isset( $m[ $name ] ) && $m[ $name ] !== '' ) {
						if ( is_array( $matched ) ) {
							$key = $pair['search'] . "\n" . $pair['replace'];
							$matched[ $key ] = array(
								'search'  => $pair['search'],
								'replace' => $pair['replace'],
							);
						}
						return $pair['replace'];
					}
				}
				return $m[0];
			},
			$data
		);
	}

	/**
	 * Keep a long URL rewrite inside the worker time limit.
	 */
	private function touch_replace_limits() {
		if ( method_exists( One_Click_Migration::class, 'worker_heartbeat' ) ) {
			One_Click_Migration::worker_heartbeat();
		}
		if ( method_exists( One_Click_Migration::class, 'get_timeout' ) ) {
			@set_time_limit( One_Click_Migration::get_timeout() );
		}
	}

}
