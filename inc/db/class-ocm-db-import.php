<?php

namespace OCM;

use wpdb;

class OCM_SQL_Utils
{
    protected static $sql_line = '';
    protected static $mysqldump_support_logged = false;
    protected static $db_backup_progress_logged = false;
    protected static $windows_logged = false;
    protected static $descriptors = array(
        array('pipe', 'r'),
        array('pipe', 'w'),
        array('pipe', 'w')
    );

    public static function mysql_host_to_cli_args($raw_host)
    {
        $assoc_args = array();

        /**
         * If the host string begins with 'p:' for a persistent db connection,
         * replace 'p:' with nothing.
         */
        if (strpos($raw_host, 'p:') === 0) {
            $raw_host = substr_replace($raw_host, '', 0, 2);
        }

        $host_parts = explode(':', $raw_host);
        if (count($host_parts) === 2) {
            list($assoc_args['host'], $extra) = $host_parts;
            $extra = trim($extra);
            if (is_numeric($extra)) {
                $assoc_args['port'] = (int)$extra;
                $assoc_args['protocol'] = 'tcp';
            } elseif ('' !== $extra) {
                $assoc_args['socket'] = $extra;
            }
        } else {
            $assoc_args['host'] = $raw_host;
        }

        return $assoc_args;
    }

    public static function is_windows()
    {
        $test_is_windows = getenv('WP_CLI_TEST_IS_WINDOWS');
        return false !== $test_is_windows ? (bool)$test_is_windows : stripos(PHP_OS, 'WIN') === 0;
    }

    public static function check_proc_available($return = false, $urls = null)
    {
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            if ($return) {
                return false;
            }

            One_Click_Migration::write_to_log('Notice: Database restore in progress. Please don\'t leave the plugin page.');
        }

        return true;
    }

    public static function force_env_on_nix_systems($command)
    {
        $env_prefix = '/usr/bin/env ';
        $env_prefix_len = strlen($env_prefix);
        if (self::is_windows()) {
            if (0 === strncmp($command, $env_prefix, $env_prefix_len)) {
                $command = substr($command, $env_prefix_len);
            }
        } else if (0 !== strncmp($command, $env_prefix, $env_prefix_len)) {
            $command = $env_prefix . $command;
        }
        return $command;
    }

    public static function check_mysql_support()
    {
        if (!self::check_proc_available(true)) {
            return false;
        }

        $final_cmd = self::force_env_on_nix_systems('mysql --version');

        $proc = self::proc_open_compat($final_cmd, self::$descriptors, $pipes);
        if (!$proc) {
            return false;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return !empty($output);
    }

    public static function check_mysqldump_support($check_support_col_statistics = false)
    {
        if (!self::check_proc_available(true)) {
            return false;
        }

        if ($check_support_col_statistics) {
            $final_cmd = self::force_env_on_nix_systems('mysqldump --help | grep "column-statistics"');
        } else {
            $final_cmd = self::force_env_on_nix_systems('mysqldump --version');
        }

        $proc = self::proc_open_compat($final_cmd, self::$descriptors, $pipes);
        if (!$proc) {
            return false;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return !empty($output);
    }

    public static function run_mysql_command($cmd, $assoc_args, $descriptors = null)
    {
        if (!$descriptors) {
            $descriptors = self::$descriptors;
        }

        if (isset($assoc_args['host'])) {
            $assoc_args = array_merge($assoc_args, self::mysql_host_to_cli_args($assoc_args['host']));
        }

        $assoc_args['password'] = $assoc_args['pass'];
        unset($assoc_args['pass'], $assoc_args['port']);

        $final_cmd = self::force_env_on_nix_systems($cmd) . self::assoc_args_to_str($assoc_args);

        $proc = self::proc_open_compat($final_cmd, $descriptors, $pipes);
        if (!$proc) {
            return false;
        }

        $r = proc_close($proc);

        if ($r) {
            return false;
        }

        return true;
    }

    public static function proc_open_compat($cmd, $descriptorspec, &$pipes, $cwd = null, $env = null, $other_options = null)
    {
        if (self::is_windows()) {
	        if (!self::$windows_logged) {
	            One_Click_Migration::write_to_log('OS: Windows');
	            self::$windows_logged = true;
	        }
	        if(!function_exists("_proc_open_compat_win_env")) return false;
	        
            // Need to encompass the whole command in double quotes - PHP bug https://bugs.php.net/bug.php?id=49139
            $cmd = '"' . _proc_open_compat_win_env($cmd, $env) . '"';
        }

        return proc_open($cmd, $descriptorspec, $pipes, $cwd, $env, $other_options);
    }

    public static function assoc_args_to_str($assoc_args)
    {
        $str = '';

        foreach ($assoc_args as $key => $value) {
            if (null === $value) {
                $str .= " --$key";
                continue;
            }

            if (true === $value) {
                $str .= " --$key";
            } elseif (is_array($value)) {
                foreach ($value as $_ => $v) {
                    $str .= assoc_args_to_str(
                        array(
                            $key => $v,
                        )
                    );
                }
            } else {
                $str .= " --$key=" . escapeshellarg($value);
            }
        }

        return $str;
    }

    public static function run($cmd, $assoc_args = array(), $descriptors = null)
    {
        $required = array(
            'host' => DB_HOST,
            'user' => DB_USER,
            'pass' => DB_PASSWORD,
        );

        if (!isset($assoc_args['default-character-set'])
            && defined('DB_CHARSET') && constant('DB_CHARSET')) {
            $required['default-character-set'] = constant('DB_CHARSET');
        }

        // Using 'dbuser' as option name to workaround clash with WP-CLI's global WP 'user' parameter, with 'dbpass' also available for tidyness.
        if (isset($assoc_args['dbuser'])) {
            $required['user'] = $assoc_args['dbuser'];
            unset($assoc_args['dbuser']);
        }
        if (isset($assoc_args['dbpass'])) {
            $required['pass'] = $assoc_args['dbpass'];
            unset($assoc_args['dbpass'], $assoc_args['password']);
        }

        $final_args = array_merge($assoc_args, $required);

        return ( self::run_mysql_command($cmd, $final_args, $descriptors));
    }

    public static function import($result_file)
    {
        $result_file = self::sql_fix($result_file);

        if (!self::check_mysql_support()) {
            $result = self::import_use_wpdb($result_file);
            if ($result) {
                self::sql_fix_after($result_file);
            }
            return $result;
        }

        $mysql_args = array(
            'database' => DB_NAME,
        );

        if ('-' !== $result_file) {
            $query = 'SET autocommit = 0; SET unique_checks = 0; SET foreign_key_checks = 0; SOURCE %s; COMMIT;';

            $mysql_args['execute'] = sprintf($query, $result_file);
        }

        $result = self::run('/usr/bin/env mysql --no-defaults --no-auto-rehash', $mysql_args);
        if ($result == false)
        {
	        One_Click_Migration::write_to_log('Notice: Database restore in progress. Please don\'t leave the plugin page.');
            $fallback_result = self::import_use_wpdb($result_file);
            if ($fallback_result) {
                self::sql_fix_after($result_file);
            }
            return $fallback_result;
        }

        self::sql_fix_after($result_file);
        return true;
    }

    public static function sql_fix($filepath)
    {
        if (preg_match('/users|comments|links|posts/', $filepath)) {
            // DATETIME fix
            $sql_code = file_get_contents($filepath);
            $sql_code = preg_replace('/0000-00-00 00:00:00/', '1000-01-01 00:00:00', $sql_code);
            file_put_contents($filepath, $sql_code);
        }

        return $filepath;
    }

    public static function sql_fix_after($filepath)
    {
        if (preg_match('/options/', $filepath)) {
            OCM_BackgroundHelper::delete_deprecated_batch_process();
        }
    }

	    public static function import_use_wpdb($result_file)
	    {
	        /** @var wpdb $wpdb */
	        global $wpdb,$EZSQL_ERROR;

	        $sql_code = file_get_contents($result_file);
	        $queries = self::split_sql_file($sql_code);
	        $has_check_current_query = property_exists($wpdb, 'check_current_query');
	        $previous_check_current_query = $has_check_current_query ? $wpdb->check_current_query : null;

	        $error_count = 0;
	        $statement_index = 0;
	        foreach ($queries as $query) {
	            $query = self::prepare_sql_query($query);
            if (empty($query)) {
                continue;
	            }
	            $statement_index++;
	            $wpdb->last_error = '';
	            if ($has_check_current_query) {
	                $wpdb->check_current_query = false;
	            }
	            $wpdb->query($query);
	            if (!empty($wpdb->last_error)) {
	                $error_str = !empty($EZSQL_ERROR[0]['error_str']) ? $EZSQL_ERROR[0]['error_str'] : $wpdb->last_error;
	                One_Click_Migration::write_to_log(sprintf('Error: DB Restore. Table: "%s" error_str: "%s"', $result_file, $error_str));
	                self::log_sql_restore_failure_context($result_file, $query, $statement_index);
                $error_count++;

	                // Abort if a CREATE TABLE statement fails — the table won't exist
	                if (stripos($query, 'CREATE TABLE') !== false) {
	                    One_Click_Migration::write_to_log(sprintf('Critical: CREATE TABLE failed for "%s". Aborting import of this file.', $result_file));
	                    if ($has_check_current_query) {
	                        $wpdb->check_current_query = $previous_check_current_query;
	                    }
	                    return false;
	                }
	            }
	        }
	        if ($has_check_current_query) {
	            $wpdb->check_current_query = $previous_check_current_query;
	        }

	        if ($error_count > 0) {
	            One_Click_Migration::write_to_log(sprintf('Warning: %d error(s) during import of "%s"', $error_count, basename($result_file)));
	            return false;
        }

        return true;
    }

    private static function prepare_sql_query($query)
    {
        $lines = preg_split('/\R/', $query);
        $statement_lines = array();

        foreach ($lines as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || strpos($trimmed, '#') === 0 || preg_match('/^--\s/', $trimmed)) {
                continue;
            }

            $statement_lines[] = $line;
        }

        return trim(implode("\n", $statement_lines));
    }

    private static function log_sql_restore_failure_context($result_file, $query, $statement_index)
    {
        global $wpdb;

        One_Click_Migration::write_to_log(sprintf(
            'SYSLOG: DB restore failure context: file="%s" statement=%d type="%s" table="%s" db_prefix="%s" base_prefix="%s" bytes=%d sample="%s"',
            basename($result_file),
            $statement_index,
            self::get_sql_statement_type($query),
            self::get_sql_statement_table($query),
            isset($wpdb->prefix) ? $wpdb->prefix : '',
            isset($wpdb->base_prefix) ? $wpdb->base_prefix : '',
            strlen($query),
            self::redact_sql_for_log($query)
        ));
    }

    private static function get_sql_statement_type($query)
    {
        if (preg_match('/^\s*([a-z]+)/i', $query, $matches)) {
            return strtoupper($matches[1]);
        }

        return 'UNKNOWN';
    }

    private static function get_sql_statement_table($query)
    {
        $patterns = array(
            '/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+(`[^`]+`|[^\s(]+)/i',
            '/^\s*DROP\s+TABLE(?:\s+IF\s+EXISTS)?\s+(`[^`]+`|[^\s(]+)/i',
            '/^\s*ALTER\s+TABLE\s+(`[^`]+`|[^\s(]+)/i',
            '/^\s*TRUNCATE\s+TABLE\s+(`[^`]+`|[^\s(]+)/i',
            '/^\s*(?:INSERT|REPLACE)\s+INTO\s+(`[^`]+`|[^\s(]+)/i',
            '/^\s*UPDATE\s+(`[^`]+`|[^\s(]+)/i',
            '/^\s*DELETE\s+FROM\s+(`[^`]+`|[^\s(]+)/i',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $query, $matches)) {
                return trim($matches[1], '`');
            }
        }

        return 'unknown';
    }

    private static function redact_sql_for_log($query)
    {
        $sample = self::safe_preg_replace('/\/\*.*?\*\//s', '/* ... */', $query);
        $sample = self::safe_preg_replace("/'([^'\\\\]|\\\\.)*'/s", "'?'", $sample);
        $sample = self::safe_preg_replace('/"([^"\\\\]|\\\\.)*"/s', '"?"', $sample);
        $sample = self::safe_preg_replace('/\s+/', ' ', $sample);
        $sample = trim($sample);

        if (preg_match('/^\s*(?:INSERT|REPLACE)\s+INTO/i', $sample)) {
            $sample = self::safe_preg_replace('/\bVALUES\s*\(.*/i', 'VALUES (...)', $sample);
        } elseif (preg_match('/^\s*UPDATE\s+/i', $sample)) {
            if (preg_match('/\bWHERE\b/i', $sample)) {
                $sample = self::safe_preg_replace('/\bSET\b\s+.*?\s+\bWHERE\b/i', 'SET ... WHERE', $sample);
            } else {
                $sample = self::safe_preg_replace('/\bSET\b\s+.*/i', 'SET ...', $sample);
            }
        }

        if (strlen($sample) > 700) {
            $sample = substr($sample, 0, 700) . '...';
        }

        return $sample;
    }

    private static function safe_preg_replace($pattern, $replacement, $subject)
    {
        if (!is_string($subject)) {
            return '';
        }

        $result = @preg_replace($pattern, $replacement, $subject);

        return is_string($result) ? $result : '';
    }

    /**
     * Taken partially from phpMyAdmin and partially from Alain Wolf, Zurich - Switzerland to use the WordPress $wpdb object
     * Website: http://restkultur.ch/personal/wolf/scripts/db_backup/
     * Modified by Scott Merrill (http://www.skippy.net/)
     */
    public static function export_table($assoc_args)
    {
        global $wpdb;

        $table = $assoc_args['tables'];
        $table_structure = $wpdb->get_results('DESCRIBE ' . $table);
        if (!$table_structure) {
          if (WP_DEBUG === true) One_Click_Migration::write_to_log(sprintf('SYSLOG: No table structure for table "%s"', $table));

            return false;
        }

        self::clear_sql_content();

        // Add SQL statement to drop existing table
        self::add_sql_line("\n# Delete any existing table $table \n\n");
        self::add_sql_line("DROP TABLE IF EXISTS $table;");

        // Table structure
        // Comment in SQL-file

        $description = 'table';

        self::add_sql_line("\n# Table structure of $description $table\n\n");

        $create_table = $wpdb->get_results("SHOW CREATE TABLE $table", ARRAY_N);
        if (false === $create_table) {
            $err_msg = 'Error with SHOW CREATE TABLE for ' . $table;
            self::add_sql_line("#\n# $err_msg\n#\n");
            One_Click_Migration::write_to_log(sprintf('Could not created table "%s"', $table));
        }
        $create_line = self::str_lreplace('TYPE=', 'ENGINE=', $create_table[0][1]);

        // Remove PAGE_CHECKSUM parameter from MyISAM - was internal, undocumented, later removed (so causes errors on import)
        if (preg_match('/ENGINE=([^\s;]+)/', $create_line, $eng_match)) {
            $engine = $eng_match[1];
            if ('myisam' === strtolower($engine)) {
                $create_line = preg_replace('/PAGE_CHECKSUM=\d\s?/', '', $create_line, 1);
            }
        }

        self::add_sql_line($create_line . ' ;');

        if (false === $table_structure) {
            $err_msg = sprintf("Error getting $description structure of %s", $table);
            One_Click_Migration::write_to_log(sprintf('Failed to get structure for table "%s"', $table));
            self::add_sql_line("#\n# $err_msg\n#\n");
        }

        // Comment in SQL-file
        self::add_sql_line("\n\n# " . sprintf("Data contents of $description %s", $table) . "\n\n");

        $defs = array();
        $integer_fields = array();
        foreach ($table_structure as $struct) {
            if ((0 === strpos($struct->Type, 'tinyint'))
                || (0 === stripos($struct->Type, 'smallint'))
                || (0 === stripos($struct->Type, 'mediumint'))
                || (0 === stripos($struct->Type, 'int'))
                || (0 === stripos($struct->Type, 'bigint'))
            ) {
                $defs[strtolower($struct->Field)] = (null === $struct->Default) ? 'NULL' : $struct->Default;
                $integer_fields[strtolower($struct->Field)] = '1';
            }
        }

        // Dynamic page sizing based on available memory
        $row_start = 0;
        $row_inc = self::get_dynamic_row_increment($table);

        // Suppress row increment logging to avoid progress bar jumping
        // if ($row_inc < 5000) {
        //     One_Click_Migration::write_to_log(" SYSLOG: Using row increment of {$row_inc} for table {$table}");
        // }
        
        $search = array("\x00", "\x0a", "\x0d", "\x1a");
        $replace = array('\0', '\n', '\r', '\Z');

        do {
            $table_data = $wpdb->get_results("SELECT * FROM $table LIMIT {$row_start}, {$row_inc}", ARRAY_A);
            $entries = 'INSERT INTO ' . $table . ' VALUES ';

            // \x08\\x09, not required
            if ($table_data) {
              // Suppress frequent SYSLOG messages that cause progress bar jumping
              // if (WP_DEBUG === true) One_Click_Migration::write_to_log(sprintf('SYSLOG: Inserting table data into table "%s"', $table));
                $thisEntry = '';
                foreach ($table_data as $row) {
                    $values = array();
                    foreach ($row as $key => $value) {
                        if (isset($integer_fields[strtolower($key)])) {
                            // make sure there are no blank spots in the insert syntax,
                            // yet try to avoid quotation marks around integers
                            $value = (null === $value || '' === $value) ? $defs[strtolower($key)] : $value;
                            $values[] = ('' === $value) ? "''" : $value;
                        } else {
                            $values[] = (null === $value) ? 'NULL' : "'" . str_replace($search, $replace, str_replace('\'', '\\\'', str_replace('\\', '\\\\', $value))) . "'";
                        }
                    }

                    if ($thisEntry) {
                        $thisEntry .= ",\n ";
                    }

                    $thisEntry .= '(' . implode(', ', $values) . ')';

                    // Flush every 512KB
                    if (strlen($thisEntry) > 524288) {
                        self::add_sql_line(" \n" . $entries . $thisEntry . ';');
                        $thisEntry = '';
                    }

                }

                if ($thisEntry) {
                    self::add_sql_line(" \n" . $entries . $thisEntry . ';');
                }

                $row_start += $row_inc;
            }
        } while (count($table_data) > 0);

        // Create footer/closing comment in SQL-file
        self::add_sql_line("\n# End of data contents of table $table\n\n");
        // Suppress frequent SYSLOG messages that cause progress bar jumping
        // if (WP_DEBUG === true) One_Click_Migration::write_to_log(sprintf('SYSLOG: End of data contents of table "%s"', $table));

        file_put_contents($assoc_args['result-file'], self::$sql_line);
    }

    public static function export($args, $assoc_args)
    {
        if (!empty($args[0])) {
            $result_file = $args[0];
        } else {
            $hash = substr(md5(mt_rand()), 0, 7);
            $result_file = sprintf('%s-%s-%s.sql', DB_NAME, date('Y-m-d'), $hash);
        }

        $stdout = ('-' === $result_file);
        if (!$stdout) {
            $assoc_args['result-file'] = $result_file;
        }

        if (!self::check_mysqldump_support()) {
            // Only log once per backup session to prevent progress bar jumping
            if (!self::$mysqldump_support_logged) {
                One_Click_Migration::write_to_log('SYSLOG: There is no mysqldump support');
                self::$mysqldump_support_logged = true;
            }
            // Only log the progress message once per backup session to prevent progress bar jumping
            if (!self::$db_backup_progress_logged) {
                One_Click_Migration::write_to_log('Database backup in progress. Please do not leave the plugin page.');
                self::$db_backup_progress_logged = true;
            }
            return self::export_table($assoc_args);
        }

        $support_column_statistics = self::check_mysqldump_support(true);

        if ($support_column_statistics) {
          if (WP_DEBUG === true) One_Click_Migration::write_to_log('SYSLOG: Column statistics are supported');
          $command = '/usr/bin/env mysqldump --no-defaults --skip-column-statistics %s';
        } else {
          if (WP_DEBUG === true) One_Click_Migration::write_to_log('SYSLOG: Column statistics are not supported');
          $command = '/usr/bin/env mysqldump --no-defaults %s';
        }

        $command_esc_args = array(DB_NAME);

        $saved_tables = null;
        if (isset($assoc_args['tables'])) {
            $saved_tables = $assoc_args['tables'];
            $tables = explode(',', trim($assoc_args['tables'], ','));
            unset($assoc_args['tables']);
            $command .= ' --tables';
            foreach ($tables as $table) {
                $command .= ' %s';
                $command_esc_args[] = trim($table);
                if (WP_DEBUG === true) One_Click_Migration::write_to_log(sprintf('SYSLOG: Table retrieved: "%s"', $table));
            }
        }

        $escaped_command = self::esc_cmd(...array_merge(array($command), $command_esc_args));

        $result = self::run($escaped_command, $assoc_args);
        if ($result == false)
        {
            if ($saved_tables !== null) {
                $assoc_args['tables'] = $saved_tables;
            }
	        One_Click_Migration::write_to_log('Database backup in progress. Please do not leave the plugin page.');
	        return self::export_table($assoc_args);
        }
    }

    public static function get_table_prefix($result_file)
    {
        $parent_directory = dirname($result_file);
        $table_name = '';

        foreach (scandir($parent_directory) as $k => $v) {
            if (strpos($v, 'usermeta') !== false) {
                $table_name = $v;
            }
        }

        $split = explode('usermeta.sql', $table_name);
        $prefix = $split[0];

        if ($prefix) {
            return $prefix;
        }

        return 'wp_';
    }

    /**
     * Given a template string and an arbitrary number of arguments,
     * returns the final command, with the parameters escaped.
     */
    public static function esc_cmd($cmd)
    {
        if (func_num_args() < 2) {
            trigger_error('esc_cmd() requires at least two arguments.', E_USER_WARNING);
        }
        $args = func_get_args();
        $cmd = array_shift($args);
        return vsprintf($cmd, array_map('escapeshellarg', $args));
    }

    public static function swap_table_prefix($filepath)
    {
        global $wpdb;

        $old_table_prefix = self::get_table_prefix($filepath);

        $file_contents = file_get_contents($filepath);
        $file_contents = str_replace($old_table_prefix, $wpdb->base_prefix, $file_contents);
        file_put_contents($filepath, $file_contents);
    }

    private static function clear_sql_content()
    {
        self::$sql_line = '';
    }

    private static function add_sql_line($line)
    {
        self::$sql_line .= sprintf("%s\n", $line);
    }

    private static function str_lreplace($search, $replace, $subject, $case_sensitive = true)
    {
        $pos = $case_sensitive ? strrpos($subject, $search) : strripos($subject, $search);
        if (false !== $pos) {
            $subject = substr_replace($subject, $replace, $pos, strlen($search));
        }
        return $subject;
    }

    private static function split_sql_file($sql)
    {
        return preg_split('/;[\s]*\n/', $sql, -1, PREG_SPLIT_NO_EMPTY);
    }

    /**
	 * Search through the file name passed for a set of defines used to set up
	 * WordPress db access.
	 * https://github.com/e-dimensionz/Search-Replace-DB/blob/master/index.php#L726
     *
	 * @param string $filename The file name we need to scan for the defines.
	 *
	 * @return array    List of db connection details.
	 */
    public static function define_find( $filename = 'wp-config.php' ){
        if ( $filename == 'wp-config.php' ) {
			$filename = dirname( __FILE__ ) . '/' . basename( $filename );

			// look up one directory if config file doesn't exist in current directory
			if ( ! file_exists( $filename ) )
				$filename = dirname( __FILE__ ) . '/../' . basename( $filename );
		}

		if ( file_exists( $filename ) && is_file( $filename ) && is_readable( $filename ) ) {
			$file = @fopen( $filename, 'r' );
			$file_content = fread( $file, filesize( $filename ) );
			@fclose( $file );
		}

		preg_match_all( '/define\s*?\(\s*?([\'"])(DB_NAME|DB_USER|DB_PASSWORD|DB_HOST|DB_CHARSET|DB_COLLATE)\1\s*?,\s*?([\'"])([^\3]*?)\3\s*?\)\s*?;/si', $file_content, $defines );

		if ( ( isset( $defines[ 2 ] ) && ! empty( $defines[ 2 ] ) ) && ( isset( $defines[ 4 ] ) && ! empty( $defines[ 4 ] ) ) ) {
			foreach( $defines[ 2 ] as $key => $define ) {

				switch( $define ) {
					case 'DB_NAME':
						$name = $defines[ 4 ][ $key ];
						break;
					case 'DB_USER':
						$user = $defines[ 4 ][ $key ];
						break;
					case 'DB_PASSWORD':
						$pass = $defines[ 4 ][ $key ];
						break;
					case 'DB_HOST':
						$host = $defines[ 4 ][ $key ];
						break;
					case 'DB_CHARSET':
						$char = $defines[ 4 ][ $key ];
						break;
					case 'DB_COLLATE':
						$coll = $defines[ 4 ][ $key ];
						break;
				}
			}
        }


		return array(
			'host' => $host,
			'name' => $name,
			'user' => $user,
			'pass' => $pass,
			'char' => $char,
			'coll' => $coll
        );
    }

    /**
     * Get dynamic row increment based on available memory and table characteristics.
     * @param string $table Table name
     * @return int
     */
    private static function get_dynamic_row_increment($table) {
        global $wpdb;
        
        try {
            // Get table row count and average row size estimate
            $row_count = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            $table_size_result = $wpdb->get_row("
                SELECT 
                    ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'size_mb',
                    table_rows
                FROM information_schema.TABLES 
                WHERE table_schema = '" . DB_NAME . "' 
                AND table_name = '{$table}'
            ", ARRAY_A);
            
            $table_size_mb = $table_size_result ? max((float)$table_size_result['size_mb'], 0) : 0;
            $estimated_row_size = $row_count > 0 ? ($table_size_mb * 1024 * 1024) / $row_count : 1024;
            $estimated_row_size = max($estimated_row_size, 128); // Guard against zero-size tables causing division by zero
            
            // Calculate available memory
            $memory_limit = ini_get('memory_limit');
            $memory_bytes = self::convert_to_bytes($memory_limit);
            $usage_memory = memory_get_usage(true);
            $available_memory = min($memory_bytes - $usage_memory, $memory_bytes * 0.20); // Use max 20% of total memory
            
            // Ensure minimum memory available
            $minimum_memory = 32 * 1024 * 1024;
            if ($available_memory < $minimum_memory) {
                One_Click_Migration::write_to_log(" SYSLOG: Low memory available, using conservative increment for table {$table}");
                return 500; // Very conservative
            }
            
            // Calculate safe row increment with safety factor
            $safety_factor = 4; // Account for PHP overhead, string processing, etc.
            $max_rows = floor($available_memory / ($estimated_row_size * $safety_factor));
            
            // Clamp between reasonable bounds
            $increment = max(100, min($max_rows, 20000));
            
            // Suppress memory calculation logging to avoid progress bar jumping
            // if ($estimated_row_size > 2048 || $available_memory < 128 * 1024 * 1024) {
            //     One_Click_Migration::write_to_log(" SYSLOG: Table {$table} - Calculated increment: {$increment}");
            // }
                
            return (int)$increment;
            
        } catch (\Throwable $e) {
            One_Click_Migration::write_to_log(" SYSLOG: Error calculating dynamic increment for {$table}, using fallback: " . $e->getMessage());
            return 1000; // Safe fallback
        }
    }

    /**
     * Convert memory limit string to bytes.
     * @param string $val Memory limit string (e.g., "512M")
     * @return int
     */
    private static function convert_to_bytes($val) {
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
     * @param int $bytes
     * @return string
     */
    private static function format_bytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Reset the logging flags for new backup sessions
     */
    public static function reset_backup_logging_flags() {
        self::$mysqldump_support_logged = false;
        self::$db_backup_progress_logged = false;
    }
}
