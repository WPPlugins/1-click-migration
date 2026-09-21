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
	public function enable_url_collection() {
		$this->url_found = array();
	}

	/**
	 * @return array
	 */
	public function collected_urls() {
		return is_array( $this->url_found ) ? $this->url_found : array();
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
	 * Returns the current page size with memory-aware calculation.
	 * @access public
	 * @return int
	 */
	public function get_page_size() {
		return $this->get_dynamic_page_size();
	}

	/**
	 * Calculate dynamic page size based on available memory.
	 * @access private
	 * @param int $estimated_row_size Estimated bytes per row (default: 1024)
	 * @return int
	 */
	private function get_dynamic_page_size($estimated_row_size = 1024) {
		// Get current memory usage and limits
		$memory_limit = ini_get('memory_limit');
		$memory_bytes = $this->convert_to_bytes($memory_limit);
		$usage_memory = memory_get_usage(true);
		$peak_memory = memory_get_peak_usage(true);
		
		// Calculate available memory (use conservative estimate)
		$free_memory = $memory_bytes - $usage_memory;
		$available_memory = min($free_memory, $memory_bytes * 0.25); // Use max 25% of total memory
		
		// Ensure we have at least 64MB available for safety
		$minimum_memory = 64 * 1024 * 1024;
		if ($available_memory < $minimum_memory) {
			One_Click_Migration::write_to_log(" SYSLOG: Low memory available (" . $this->format_bytes($available_memory) . "), using conservative page size");
			return 1000; // Conservative fallback
		}
		
		// Calculate max rows based on estimated row size
		$max_rows = floor($available_memory / ($estimated_row_size * 3)); // 3x safety factor
		
		// Clamp between reasonable bounds
		$page_size = max(1000, min($max_rows, 50000));
		
		// Only log memory issues to avoid progress bar jumping
		if ($available_memory < 64 * 1024 * 1024) {
			One_Click_Migration::write_to_log(" SYSLOG: Low memory - Page Size reduced to: " . $page_size);
		}
		
		return absint($page_size);
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
	 * Gets the columns in a table.
	 * @access public
	 * @param  string $table The table to check.
	 * @return array
	 */
	public function get_columns( $table ) {
		$primary_key 	= null;
		$columns 		= array();
		$fields  		= $this->wpdb->get_results( 'DESCRIBE ' . $table );

		if ( is_array( $fields ) ) {
			foreach ( $fields as $column ) {
				$columns[] = $column->Field;
				if ( $column->Key == 'PRI' ) {
					$primary_key = $column->Field;
				}
			}
		}

		return array( $primary_key, $columns );
	}

	/**
	 * Adapated from interconnect/it's search/replace script.
	 *
	 * Modified to use WordPress wpdb functions instead of PHP's native mysql/pdo functions,
	 * and to be compatible with batch processing via AJAX.
	 *
	 * @link https://interconnectit.com/products/search-and-replace-for-wordpress-databases/
	 *
	 * @access public
	 * @param  string 	$table 	The table to run the replacement on.
	 * @param  int 		$page  	The page/block to begin the query on.
	 * @param  array 	$args 	An associative array containing arguements for this run.
	 * @return array
	 */
	public function search_replace_db( $tables, $args ) {
		One_Click_Migration::write_to_log('Starting to update URLs in database');

		$pairs = $this->replacement_pairs_from_args( $args );

		$table_report = array(
			'change' 	=> 0,
			'updates' 	=> 0,
			'start' 	=> microtime( true ),
			'end'		=> microtime( true ),
			'errors' 	=> array(),
			'skipped' 	=> false
		);

		foreach ($tables as $table) {
			// Load up the default settings for this chunk.
			$table 			= esc_sql( $table );
			$pages 			= $this->get_pages_in_table( $table );

			// Get a list of columns in this table.
			list( $primary_key, $columns ) = $this->get_columns( $table );

			// Bail out early if there isn't a primary key.
			if ( null === $primary_key ) {
				$table_report['skipped'] = true;
				One_Click_Migration::write_to_log( sprintf( 'Notice: Skipping URL replace on table "%s" (no primary key).', $table ) );
				continue;
			}

			$current_row 	= 0;

			for ( $current_page = 0; $current_page < $pages; $current_page++ ) {
				$this->touch_replace_limits();
				$start 	= $current_page * $this->page_size;
				$end 	= $this->page_size;

				// Grab the content of the table.
				$data = $this->wpdb->get_results( "SELECT * FROM `$table` LIMIT $start, $end", ARRAY_A );

				if ( empty( $data ) ) {
					break;
				}

				// Loop through the data.
				foreach ( $data as $row ) {
					$current_row++;
					$update_sql = array();
					$where_sql 	= array();
					$upd 		= false;

					foreach( $columns as $column ) {

						$data_to_fix = $row[ $column ];


						if ( $column == $primary_key ) {
							$where_sql[] = $column . ' = "' .  $this->mysql_escape_mimic( $data_to_fix ) . '"';
							continue;
						}

						if ( is_array( $this->url_found ) ) {
							OCM_Extra_Domains::collect_from_text( $this->url_found, $data_to_fix );
						}

						// Run a search replace on the data that'll respect the serialisation.
						// Pairs are longest-first so a path-prefixed temp URL is rewritten before the bare host.
						$edited_data = $this->apply_replacement_pairs( $data_to_fix, $pairs );

						// Something was changed
						if ( $edited_data != $data_to_fix ) {
							$update_sql[] = $column . ' = "' . $this->mysql_escape_mimic( $edited_data ) . '"';
							$upd = true;
							$table_report['change']++;
						}

					}

				 if ( $upd && ! empty( $where_sql ) ) {
						// If there are changes to make, run the query.
						$sql 	= 'UPDATE ' . $table . ' SET ' . implode( ', ', $update_sql ) . ' WHERE ' . implode( ' AND ', array_filter( $where_sql ) );
						$result = $this->wpdb->query( $sql );

						if ( ! $result ) {
							$table_report['errors'][] = sprintf( __( 'Error updating row: %d.', '1-click-migration' ), $current_row );
						} else {
							$table_report['updates']++;
						}

					}

				} // end row loop

				// Flush the results after each page.
				$this->wpdb->flush();

			} // end page loop

			$table_report['end'] = microtime( true );

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


			if ( is_string( $data ) && ! is_serialized_string( $data ) && ( $unserialized = $this->unserialize( $data ) ) !== false ) {
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

				$data = $this->unserialize( $data );
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
	 * @param string $method            The name of the caller method.
	 *
	 * @return mixed, false on failure
	 */
	public static function unserialize( $serialized_string ) {

		if ( ! is_serialized( $serialized_string ) ) {

			return false;
		}


		$serialized_string   = trim( $serialized_string );
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
			$pattern = '~' . preg_quote( $from, '~' ) . '(?=$|[/?#\s"\'<>\\\\])~i';
			return preg_replace_callback( $pattern, function () use ( $to ) {
				return $to;
			}, $data );
		}


			$data = str_ireplace( $from, $to, $data );

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
	 * @param mixed $data
	 * @param array $pairs
	 * @return mixed
	 */
	private function apply_replacement_pairs( $data, $pairs ) {
		$edited = $data;
		foreach ( $pairs as $pair ) {
			$edited = $this->recursive_unserialize_replace( $pair['search'], $pair['replace'], $edited, false, ! empty( $pair['url_boundary'] ) );
		}
		return $edited;
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
