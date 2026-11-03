<?php

namespace OCM;

use wpdb;

class OCM_SQL_Utils
{
    protected static $sql_line = ''; // unused; dump is streamed to disk
    /** @var resource|null Handle for streamed SQL dump writes. */
    protected static $sql_fh = null;
    /** @var string Bounded buffer flushed to $sql_fh in chunks. */
    protected static $sql_buffer = '';
    const SQL_STREAM_FLUSH_BYTES = 524288; // 512 KiB
    const SQL_STREAM_READ_BYTES = 1048576; // 1 MiB
    /** Statement keyword + table name always sit within this many bytes of a line start. */
    const SQL_STATEMENT_HEAD_BYTES = 4096;
    protected static $mysqldump_support_logged = false;
    protected static $db_backup_progress_logged = false;
    protected static $windows_logged = false;
    /** @var bool SET NAMES already applied on the raw import handle this file. */
    protected static $import_charset_applied = false;
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

    public static function import($result_file, $source_prefix = '', $dest_prefix = '')
    {
        $result_file = self::sql_fix($result_file);

        if (!self::check_mysql_support()) {
            $result = self::import_use_wpdb($result_file, $source_prefix, $dest_prefix);
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
        if ($result && '-' !== $result_file && !self::cli_import_visible($result_file)) {
            One_Click_Migration::write_to_log(sprintf(
                'SYSLOG: mysql command-line import of "%s" reported success, but WordPress\'s database connection can\'t see it; importing it through $wpdb instead.',
                basename($result_file)
            ));
            $result = false;
        }
        if ($result == false)
        {
	        One_Click_Migration::write_to_log('Notice: Database restore in progress. Please don\'t leave the plugin page.');
            $fallback_result = self::import_use_wpdb($result_file, $source_prefix, $dest_prefix);
            if ($fallback_result) {
                self::sql_fix_after($result_file);
            }
            return $fallback_result;
        }

        self::sql_fix_after($result_file);
        return true;
    }

    /**
     * After the mysql command line reports success, check through WordPress's
     * own connection that the dump's table is there, with rows when the dump
     * inserts any. The command line can land where WordPress doesn't look
     * (another server or socket, a db.php drop-in) or skip a file and still
     * exit 0, and the options check then finds no siteurl (field restore
     * 84630436). True when the table is visible, or when it can't be told.
     *
     * @param string $result_file
     * @return bool
     */
    public static function cli_import_visible($result_file)
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_col') || !method_exists($wpdb, 'get_var')) {
            return true;
        }
        $handle = @fopen($result_file, 'rb');
        if (!$handle) {
            return true;
        }
        $head = (string) fread($handle, self::SQL_STREAM_READ_BYTES);
        fclose($handle);
        if (!preg_match('/^\s*CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([^`\s(]+)`?/mi', $head, $m)) {
            return true;
        }
        $table = $m[1];

        $wpdb->last_error = '';
        $tables = $wpdb->get_col('SHOW TABLES');
        if (!is_array($tables) || (!$tables && '' !== (string) $wpdb->last_error)) {
            return true;
        }
        // lower_case_table_names hosts list names in lower case.
        if (!in_array(strtolower($table), array_map('strtolower', array_map('strval', $tables)), true)) {
            return false;
        }
        if (preg_match('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\s+`?' . preg_quote($table, '/') . '`?[\s(]/mi', $head)) {
            return null !== $wpdb->get_var('SELECT 1 FROM `' . str_replace('`', '``', $table) . '` LIMIT 1');
        }
        return true;
    }

    public static function sql_fix($filepath)
    {
        if (preg_match('/users|comments|links|posts/', $filepath)) {
            // DATETIME fix — streamed so large posts/users dumps never sit in RAM
            self::stream_str_replace($filepath, '0000-00-00 00:00:00', '1000-01-01 00:00:00');
        }

        return $filepath;
    }

    public static function sql_fix_after($filepath)
    {
        if (preg_match('/options/', $filepath)) {
            OCM_BackgroundHelper::delete_deprecated_batch_process();
        }
    }

	    public static function import_use_wpdb($result_file, $source_prefix = '', $dest_prefix = '')
	    {
	        /** @var wpdb $wpdb */
	        global $wpdb,$EZSQL_ERROR;

	        $handle = @fopen($result_file, 'rb');
	        if (!$handle) {
	            One_Click_Migration::write_to_log(sprintf('Error: Could not open SQL file "%s" for import.', $result_file));
	            return false;
	        }

	        self::reset_import_runtime_state();
	        self::log_import_driver($wpdb, $result_file);
	        // Dumps import one table per file in name order, so a child table
	        // (wp_pbb_ab_test_variants) is created and filled before its parent
	        // (wp_pbb_ab_tests). The CLI import and mysqldump's header turn the
	        // checks off for that; the PHP exporter writes no header.
	        self::query_imported_sql($wpdb, 'SET foreign_key_checks = 0');

	        $error_count = 0;
	        $statement_index = 0;
	        $remapped_statements = 0;
	        $buffer = '';
	        $aborted = false;

	        while (!$aborted && (!feof($handle) || $buffer !== '')) {
	            if (!feof($handle)) {
	                $chunk = fread($handle, self::SQL_STREAM_READ_BYTES);
	                if ($chunk === false) {
	                    break;
	                }
	                $buffer .= $chunk;
	            }

	            while (preg_match('/;[\s]*\n/', $buffer, $matches, PREG_OFFSET_CAPTURE)) {
	                $pos = $matches[0][1];
	                $len = strlen($matches[0][0]);
	                $query = substr($buffer, 0, $pos);
	                $buffer = substr($buffer, $pos + $len);
	                $result = self::execute_imported_query(
	                    $query,
	                    $result_file,
	                    $statement_index,
	                    $error_count,
	                    $source_prefix,
	                    $dest_prefix,
	                    $remapped_statements
	                );
	                if ($result === false) {
	                    $aborted = true;
	                    break;
	                }
	            }

	            if ($aborted) {
	                break;
	            }

	            if (feof($handle)) {
	                if (trim($buffer) !== '') {
	                    $query = preg_replace('/;\s*$/', '', $buffer);
	                    $buffer = '';
	                    $result = self::execute_imported_query(
	                        $query,
	                        $result_file,
	                        $statement_index,
	                        $error_count,
	                        $source_prefix,
	                        $dest_prefix,
	                        $remapped_statements
	                    );
	                    if ($result === false) {
	                        $aborted = true;
	                    }
	                }
	                break;
	            }
	        }
	        fclose($handle);
	        self::query_imported_sql($wpdb, 'SET foreign_key_checks = 1');

	        if ($remapped_statements > 0) {
	            One_Click_Migration::write_to_log(sprintf(
	                'Notice: Remapped %d statement(s) from source prefix "%s" to destination prefix "%s" while importing "%s".',
	                $remapped_statements,
	                $source_prefix,
	                $dest_prefix,
	                basename($result_file)
	            ));
	        }

	        if ($aborted) {
	            return false;
	        }

	        if ($error_count > 0) {
	            One_Click_Migration::write_to_log(sprintf('Warning: %d error(s) during import of "%s"', $error_count, basename($result_file)));
	            return false;
        }

        return true;
    }

    /**
     * Run one SQL statement from a streamed dump. Returns false to abort the file.
     *
     * @param string $query
     * @param string $result_file
     * @param int    $statement_index
     * @param int    $error_count
     * @param string $source_prefix   Table prefix of the backup being imported.
     * @param string $dest_prefix     Destination site table prefix.
     * @param int    $remapped_count  Incremented when a statement head is remapped.
     * @return bool
     */
    private static function execute_imported_query($query, $result_file, &$statement_index, &$error_count, $source_prefix = '', $dest_prefix = '', &$remapped_count = 0)
    {
        global $wpdb, $EZSQL_ERROR;

        $query = self::prepare_sql_query($query);
        if (empty($query)) {
            return true;
        }

        // Last line of defense for cross-prefix restores: whatever state the dump
        // file is in (unswapped, partially swapped after an interrupted run, or
        // mutated by a restarted restore), never execute a statement against the
        // source prefix. This guarantees CREATE TABLE and INSERT always agree on
        // the destination prefix.
        $remapped_query = self::remap_statement_table_prefix($query, $source_prefix, $dest_prefix);
        if ($remapped_query !== $query) {
            $query = $remapped_query;
            $remapped_count++;
        }

        $statement_index++;
        $wpdb->last_error = '';
        // Prefer the raw mysqli handle. $wpdb->query() runs strip_invalid_text
        // on non-ASCII SQL and rejects large options INSERTs that MySQL
        // itself would accept — field backup 65a0b1d5, Turkish WP error
        // "Sorgu, içinde geçersiz veriler bulunduğundan işlenemedi".
        self::query_imported_sql($wpdb, $query);
        if (!empty($wpdb->last_error)) {
            $error_str = !empty($EZSQL_ERROR[0]['error_str']) ? $EZSQL_ERROR[0]['error_str'] : $wpdb->last_error;
            One_Click_Migration::write_to_log(sprintf('Error: DB Restore. Table: "%s" error_str: "%s"', $result_file, $error_str));
            self::log_sql_restore_failure_context($result_file, $query, $statement_index);
            $error_count++;

            if (stripos($query, 'CREATE TABLE') !== false) {
                One_Click_Migration::write_to_log(sprintf('Critical: CREATE TABLE failed for "%s". Aborting import of this file.', $result_file));
                return false;
            }
        }

        return true;
    }

    /**
     * Reset per-file import driver state (charset SET NAMES latch).
     */
    public static function reset_import_runtime_state()
    {
        self::$import_charset_applied = false;
    }

    /**
     * Execute one remapped dump statement, preferring the raw mysqli/driver
     * handle so WordPress cannot strip_invalid_text() the SQL.
     *
     * @param object $wpdb
     * @param string $query
     * @return bool
     */
    public static function query_imported_sql($wpdb, $query)
    {
        $dbh = self::get_raw_import_driver($wpdb);
        if ($dbh !== null) {
            self::apply_import_connection_charset($wpdb, $dbh);
            try {
                $result = self::raw_driver_query($dbh, $query);
            } catch (\Throwable $e) {
                if (is_object($wpdb)) {
                    $wpdb->last_error = $e->getMessage();
                }
                return false;
            }
            if ($result === false) {
                if (is_object($wpdb)) {
                    $wpdb->last_error = self::raw_driver_error($dbh);
                }
                return false;
            }
            if (is_object($wpdb)) {
                $wpdb->last_error = '';
            }
            return true;
        }

        return self::query_imported_sql_via_wpdb($wpdb, $query);
    }

    /**
     * Raw mysqli (or a query()+error test double) on $wpdb->dbh, after a
     * lazy reconnect if the handle is not up yet.
     *
     * @param object $wpdb
     * @return object|null
     */
    public static function get_raw_import_driver($wpdb)
    {
        if (!is_object($wpdb)) {
            return null;
        }

        self::ensure_wpdb_connection($wpdb);

        if (isset($wpdb->dbh) && self::is_raw_import_driver($wpdb->dbh)) {
            return $wpdb->dbh;
        }

        return null;
    }

    /**
     * True for a real mysqli connection or a driver double that exposes
     * query() plus a public $error property (mysqli-shaped).
     *
     * @param mixed $dbh
     * @return bool
     */
    public static function is_raw_import_driver($dbh)
    {
        if (!is_object($dbh)) {
            return false;
        }
        if ($dbh instanceof \mysqli) {
            return true;
        }
        return method_exists($dbh, 'query') && property_exists($dbh, 'error');
    }

    /**
     * Connection charset for dump import. utf8/utf8mb3 is promoted to
     * utf8mb4 so 4-byte option values are not rejected by a 3-byte client
     * charset. Other charsets (latin1, etc.) are left as-is.
     *
     * @param object|null $wpdb
     * @return string
     */
    public static function preferred_import_charset($wpdb)
    {
        $candidates = array();
        if (is_object($wpdb) && isset($wpdb->charset) && is_string($wpdb->charset)) {
            $candidates[] = $wpdb->charset;
        }
        if (defined('DB_CHARSET') && is_string(DB_CHARSET) && DB_CHARSET !== '') {
            $candidates[] = DB_CHARSET;
        }

        foreach ($candidates as $candidate) {
            $name = self::sanitize_mysql_ident($candidate);
            if ($name === '') {
                continue;
            }
            if ($name === 'utf8' || $name === 'utf8mb3') {
                return 'utf8mb4';
            }
            return $name;
        }

        return 'utf8mb4';
    }

    private static function log_import_driver($wpdb, $result_file)
    {
        $raw = self::get_raw_import_driver($wpdb) !== null;
        One_Click_Migration::write_to_log(sprintf(
            'SYSLOG: SQL import of "%s" using %s.',
            basename($result_file),
            $raw
                ? 'raw mysqli/driver path (bypassing $wpdb->query strip_invalid_text)'
                : '$wpdb->query fallback (no raw mysqli handle)'
        ));
    }

    private static function ensure_wpdb_connection($wpdb)
    {
        if (!is_object($wpdb)) {
            return;
        }
        if (!empty($wpdb->dbh) && self::is_raw_import_driver($wpdb->dbh)) {
            return;
        }
        if (method_exists($wpdb, 'check_connection')) {
            $wpdb->check_connection(false);
            return;
        }
        if (method_exists($wpdb, 'db_connect')) {
            $wpdb->db_connect(false);
        }
    }

    private static function apply_import_connection_charset($wpdb, $dbh)
    {
        if (self::$import_charset_applied) {
            return;
        }
        self::$import_charset_applied = true;

        $charset = self::preferred_import_charset($wpdb);
        if ($charset === '') {
            return;
        }

        $collate = self::preferred_import_collate($wpdb);
        if ($charset === 'utf8mb4' && $collate !== '' && strpos($collate, 'utf8mb4_') !== 0) {
            // Do not pair SET NAMES utf8mb4 with a leftover utf8_* collation.
            $collate = '';
        }

        if ($dbh instanceof \mysqli && function_exists('mysqli_set_charset')) {
            @mysqli_set_charset($dbh, $charset);
        }

        $sql = 'SET NAMES ' . $charset;
        if ($collate !== '') {
            $sql .= ' COLLATE ' . $collate;
        }

        try {
            self::raw_driver_query($dbh, $sql);
        } catch (\Throwable $e) {
            // Charset apply is best-effort; the INSERT path still proceeds.
        }
    }

    private static function preferred_import_collate($wpdb)
    {
        $candidates = array();
        if (is_object($wpdb) && isset($wpdb->collate) && is_string($wpdb->collate)) {
            $candidates[] = $wpdb->collate;
        }
        if (defined('DB_COLLATE') && is_string(DB_COLLATE) && DB_COLLATE !== '') {
            $candidates[] = DB_COLLATE;
        }

        foreach ($candidates as $candidate) {
            $name = self::sanitize_mysql_ident($candidate);
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * Allow only charset / collation identifiers in SET NAMES.
     *
     * @param string $name
     * @return string
     */
    private static function sanitize_mysql_ident($name)
    {
        $name = strtolower(trim((string) $name));
        if ($name === '' || !preg_match('/^[a-z0-9_]+$/', $name)) {
            return '';
        }
        return $name;
    }

    private static function raw_driver_query($dbh, $query)
    {
        if ($dbh instanceof \mysqli) {
            if (function_exists('mysqli_query')) {
                return mysqli_query($dbh, $query);
            }
            return $dbh->query($query);
        }
        if (is_object($dbh) && method_exists($dbh, 'query')) {
            return $dbh->query($query);
        }
        return false;
    }

    private static function raw_driver_error($dbh)
    {
        if (is_object($dbh) && isset($dbh->error) && is_string($dbh->error) && $dbh->error !== '') {
            return $dbh->error;
        }
        if ($dbh instanceof \mysqli && function_exists('mysqli_error')) {
            $err = mysqli_error($dbh);
            return is_string($err) ? $err : '';
        }
        return '';
    }

    private static function query_imported_sql_via_wpdb($wpdb, $query)
    {
        if (!is_object($wpdb) || !method_exists($wpdb, 'query')) {
            return false;
        }

        try {
            $result = $wpdb->query($query);
        } catch (\Throwable $e) {
            $wpdb->last_error = $e->getMessage();
            return false;
        }

        if ($result === false && self::looks_like_wp_invalid_data_error(isset($wpdb->last_error) ? $wpdb->last_error : '')) {
            One_Click_Migration::write_to_log(
                'Notice: $wpdb rejected a dump statement as invalid data (strip_invalid_text). Raw mysqli driver was not available on this host.'
            );
        }

        return $result !== false;
    }

    /**
     * WordPress wpdb::query() last_error after strip_invalid_text_from_query()
     * rejects the SQL. Includes the Turkish translation seen on backup 65a0b1d5.
     *
     * @param string $error
     * @return bool
     */
    public static function looks_like_wp_invalid_data_error($error)
    {
        if (!is_string($error) || $error === '') {
            return false;
        }
        return (stripos($error, 'invalid data') !== false)
            || (stripos($error, 'geçersiz veriler') !== false);
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
        // SHOW FULL COLUMNS exposes Extra=INVISIBLE; fall back to DESCRIBE.
        $table_structure = $wpdb->get_results('SHOW FULL COLUMNS FROM ' . $table);
        if (!$table_structure) {
            $table_structure = $wpdb->get_results('DESCRIBE ' . $table);
        }
        if (!$table_structure) {
          if (WP_DEBUG === true) One_Click_Migration::write_to_log(sprintf('SYSLOG: No table structure for table "%s"', $table));

            return false;
        }

        if (!self::open_sql_dump($assoc_args['result-file'])) {
            return false;
        }

        // Add SQL statement to drop existing table
        if (!self::add_sql_line("\n# Delete any existing table $table \n\n")
            || !self::add_sql_line("DROP TABLE IF EXISTS $table;")
        ) {
            self::close_sql_dump();
            return false;
        }

        // Table structure
        // Comment in SQL-file

        $description = 'table';

        if (!self::add_sql_line("\n# Table structure of $description $table\n\n")) {
            self::close_sql_dump();
            return false;
        }

        $create_table = $wpdb->get_results("SHOW CREATE TABLE $table", ARRAY_N);
        if (false === $create_table) {
            $err_msg = 'Error with SHOW CREATE TABLE for ' . $table;
            if (!self::add_sql_line("#\n# $err_msg\n#\n")) {
                self::close_sql_dump();
                return false;
            }
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

        if (!self::add_sql_line($create_line . ' ;')) {
            self::close_sql_dump();
            return false;
        }

        if (false === $table_structure) {
            $err_msg = sprintf("Error getting $description structure of %s", $table);
            One_Click_Migration::write_to_log(sprintf('Failed to get structure for table "%s"', $table));
            if (!self::add_sql_line("#\n# $err_msg\n#\n")) {
                self::close_sql_dump();
                return false;
            }
        }

        // Comment in SQL-file
        if (!self::add_sql_line("\n\n# " . sprintf("Data contents of $description %s", $table) . "\n\n")) {
            self::close_sql_dump();
            return false;
        }

        $defs = array();
        $integer_fields = array();
        $binary_fields = array();
        $bit_fields = array();
        $select_exprs = array();
        $insert_col_sql = array();
        $skipped_invisible = false;
        foreach ($table_structure as $struct) {
            $extra_lower = strtolower((string) (isset($struct->Extra) ? $struct->Extra : ''));
            // MariaDB 10.3+ / MySQL 8.0.23+ INVISIBLE columns must not pad
            // INSERT VALUES counts; skip them in both SELECT and INSERT lists.
            if (strpos($extra_lower, 'invisible') !== false) {
                $skipped_invisible = true;
                continue;
            }
            $field_key = strtolower($struct->Field);
            $type_lower = strtolower((string) $struct->Type);
            $field_sql = '`' . str_replace('`', '``', $struct->Field) . '`';
            $insert_col_sql[] = $field_sql;
            if ((0 === strpos($struct->Type, 'tinyint'))
                || (0 === stripos($struct->Type, 'smallint'))
                || (0 === stripos($struct->Type, 'mediumint'))
                || (0 === stripos($struct->Type, 'int'))
                || (0 === stripos($struct->Type, 'bigint'))
            ) {
                $defs[$field_key] = (null === $struct->Default) ? 'NULL' : $struct->Default;
                $integer_fields[$field_key] = '1';
                $select_exprs[] = $field_sql;
            } elseif (self::is_bit_column_type($type_lower)) {
                // Force a decimal integer from the server so raw-byte drivers
                // cannot return ASCII digits that ctype_digit would misread.
                $bit_fields[$field_key] = '1';
                $select_exprs[] = $field_sql . '+0 AS ' . $field_sql;
            } elseif (self::is_binary_column_type($type_lower)) {
                // BINARY/BLOB/VARBINARY must not ride in quoted strings: invalid
                // UTF-8 / NUL bytes inside a quoted literal on a utf8mb4
                // connection break the INSERT (field backup b98f274e /
                // Wordfence wffilemods). Hex literals avoid that.
                $binary_fields[$field_key] = '1';
                $select_exprs[] = $field_sql;
            } else {
                $select_exprs[] = $field_sql;
            }
        }

        // Dynamic page sizing based on available memory
        $row_start = 0;
        $row_inc = self::get_dynamic_row_increment($table);

        if ($row_inc < 5000) {
            One_Click_Migration::write_to_log(" SYSLOG: Using row increment of {$row_inc} for table {$table}");
        }
        
        $search = array("\x00", "\x0a", "\x0d", "\x1a");
        $replace = array('\0', '\n', '\r', '\Z');
        // Explicit column lists when BIT needs ``col+0`` or INVISIBLE columns
        // were skipped — otherwise INSERT VALUES count mismatches CREATE TABLE.
        // Keep SELECT * / INSERT VALUES for the common path (tests + hosts).
        $use_explicit_cols = ($bit_fields !== array()) || $skipped_invisible;
        $select_list = $use_explicit_cols ? implode(', ', $select_exprs) : '*';

        do {
            $table_data = $wpdb->get_results(
                "SELECT {$select_list} FROM $table LIMIT {$row_start}, {$row_inc}",
                ARRAY_A
            );
            if (!is_array($table_data)) {
                $table_data = array();
            }
            $entries = $use_explicit_cols
                ? 'INSERT INTO ' . $table . ' (' . implode(', ', $insert_col_sql) . ') VALUES '
                : 'INSERT INTO ' . $table . ' VALUES ';

            // \x08\\x09, not required
            if ($table_data) {
              // Suppress frequent SYSLOG messages that cause progress bar jumping
              // if (WP_DEBUG === true) One_Click_Migration::write_to_log(sprintf('SYSLOG: Inserting table data into table "%s"', $table));
                $thisEntry = '';
                foreach ($table_data as $row) {
                    $values = array();
                    foreach ($row as $key => $value) {
                        if (isset($assoc_args['ocm_collect_urls']) && is_callable($assoc_args['ocm_collect_urls'])) {
                            call_user_func($assoc_args['ocm_collect_urls'], $value);
                        }
                        $field_key = strtolower($key);
                        if (isset($binary_fields[$field_key])) {
                            $values[] = self::sql_binary_literal($value);
                        } elseif (isset($bit_fields[$field_key])) {
                            $values[] = self::sql_bit_literal($value);
                        } elseif (isset($integer_fields[$field_key])) {
                            // make sure there are no blank spots in the insert syntax,
                            // yet try to avoid quotation marks around integers
                            $value = (null === $value || '' === $value) ? $defs[$field_key] : $value;
                            $values[] = ('' === $value) ? "''" : $value;
                        } else {
                            $values[] = (null === $value) ? 'NULL' : "'" . str_replace($search, $replace, str_replace('\'', '\\\'', str_replace('\\', '\\\\', $value))) . "'";
                        }
                    }

                    if ($thisEntry) {
                        $thisEntry .= ",\n ";
                    }

                    $thisEntry .= '(' . implode(', ', $values) . ')';

                    // Flush often so one INSERT statement never approaches remaining RAM.
                    if (strlen($thisEntry) > 65536) {
                        if (!self::add_sql_line(" \n" . $entries . $thisEntry . ';')) {
                            self::close_sql_dump();
                            return false;
                        }
                        $thisEntry = '';
                    }

                }

                if ($thisEntry) {
                    if (!self::add_sql_line(" \n" . $entries . $thisEntry . ';')) {
                        self::close_sql_dump();
                        return false;
                    }
                }

                $row_start += $row_inc;
            }
        } while (count($table_data) > 0);

        // Create footer/closing comment in SQL-file
        if (!self::add_sql_line("\n# End of data contents of table $table\n\n")) {
            self::close_sql_dump();
            return false;
        }
        // Suppress frequent SYSLOG messages that cause progress bar jumping
        // if (WP_DEBUG === true) One_Click_Migration::write_to_log(sprintf('SYSLOG: End of data contents of table "%s"', $table));

        return self::close_sql_dump();
    }

    public static function export($args, $assoc_args)
    {
        if (!empty($args[0])) {
            $result_file = $args[0];
        } else {
            $hash = substr(md5(mt_rand()), 0, 7);
            $result_file = sprintf('%s-%s-%s.sql', DB_NAME, date('Y-m-d'), $hash);
        }

        $collector = $assoc_args['ocm_collect_urls'] ?? null;
        unset($assoc_args['ocm_collect_urls']);
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
            $assoc_args['ocm_collect_urls'] = $collector;
            return self::export_table($assoc_args);
        }

        $support_column_statistics = self::check_mysqldump_support(true);

        if ($support_column_statistics) {
          if (WP_DEBUG === true) One_Click_Migration::write_to_log('SYSLOG: Column statistics are supported');
          $command = '/usr/bin/env mysqldump --no-defaults --hex-blob --skip-column-statistics %s';
        } else {
          if (WP_DEBUG === true) One_Click_Migration::write_to_log('SYSLOG: Column statistics are not supported');
          $command = '/usr/bin/env mysqldump --no-defaults --hex-blob %s';
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
            $assoc_args['ocm_collect_urls'] = $collector;
	        return self::export_table($assoc_args);
        }
        if (is_callable($collector) && !$stdout) {
            return OCM_URL_Manifest::collect_dump($result_file, $collector);
        }
    }

    public static function get_table_prefix($result_file)
    {
        $parent_directory = dirname($result_file);
        $matches = array();

        foreach (scandir($parent_directory) ?: array() as $filename) {
            if (preg_match('/^(.+)usermeta\.sql$/i', $filename, $match)
                && is_file($parent_directory . DIRECTORY_SEPARATOR . $filename)
            ) {
                $matches[$filename] = $match[1];
            }
        }

        if (count($matches) !== 1) {
            One_Click_Migration::write_to_log(sprintf(
                'Error: Expected exactly one canonical "*usermeta.sql" dump in "%s"; found %d.',
                $parent_directory,
                count($matches)
            ));
            return false;
        }

        return (string) reset($matches);
    }

    /**
     * Table prefixes of every WordPress table set in a restore db/ folder, one
     * per "*usermeta.sql" dump. The backup dumps every table in the database,
     * so other installs or staging copies sharing it show up here too.
     *
     * @param string $db_dir
     * @return string[] Sorted prefixes.
     */
    public static function wordpress_table_set_prefixes($db_dir)
    {
        $prefixes = array();
        $db_dir = rtrim((string) $db_dir, '/\\');
        foreach (@scandir($db_dir) ?: array() as $filename) {
            if (preg_match('/^(.+)usermeta\.sql$/i', $filename, $match)
                && is_file($db_dir . DIRECTORY_SEPARATOR . $filename)
            ) {
                $prefixes[] = $match[1];
            }
        }
        sort($prefixes, SORT_STRING);
        return array_values(array_unique($prefixes));
    }

    /**
     * The table set a table belongs to: the longest prefix that starts its
     * name, so "wp_old_options" belongs to "wp_old_" rather than "wp_".
     *
     * @param string   $table
     * @param string[] $prefixes
     * @return string|null
     */
    public static function table_set_prefix_for($table, array $prefixes)
    {
        $owner = null;
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && strpos((string) $table, $prefix) === 0
                && ($owner === null || strlen($prefix) > strlen($owner))
            ) {
                $owner = $prefix;
            }
        }
        return $owner;
    }

    /**
     * The siteurl value in an options table dump (mysqldump or the PHP
     * exporter), read in chunks so a large options table never loads whole.
     *
     * @param string $filepath
     * @return string|null
     */
    public static function siteurl_from_options_dump($filepath)
    {
        $handle = @fopen($filepath, 'rb');
        if (!$handle) {
            return null;
        }
        $pattern = "/'siteurl'\\s*,\\s*'([^'\\\\]*+(?:\\\\.[^'\\\\]*+)*+)'/";
        $carry = '';
        $found = null;
        while (!feof($handle)) {
            $chunk = fread($handle, 1048576);
            if ($chunk === false) {
                break;
            }
            $text = $carry . $chunk;
            if (preg_match($pattern, $text, $match) && strlen($match[1]) <= 2048) {
                $found = stripcslashes($match[1]);
                break;
            }
            // Keep enough of the tail for a row split across chunks.
            $carry = substr($text, -4200);
        }
        fclose($handle);
        return $found;
    }

    /**
     * Pick the backed-up site's table prefix when the dump holds more than one
     * WordPress table set. Prefers the prefix the backup recorded in its log;
     * a backup made before that line existed falls back to the set whose
     * options dump has the backup's site address as siteurl.
     *
     * @param string   $db_dir
     * @param string[] $prefixes        From wordpress_table_set_prefixes().
     * @param string   $recorded_prefix BACKUP_TABLE_PREFIX from the backup log, or ''.
     * @param string   $source_url      The backup's site address, or ''.
     * @return array{prefix:string,how:string}|false
     */
    public static function resolve_source_table_set($db_dir, array $prefixes, $recorded_prefix = '', $source_url = '')
    {
        if (count($prefixes) === 1) {
            return array('prefix' => (string) reset($prefixes), 'how' => 'only');
        }
        if (is_string($recorded_prefix) && $recorded_prefix !== '' && in_array($recorded_prefix, $prefixes, true)) {
            return array('prefix' => $recorded_prefix, 'how' => 'recorded');
        }

        $want = self::normalize_site_address($source_url);
        if ($want === '') {
            return false;
        }
        $db_dir = rtrim((string) $db_dir, '/\\') . DIRECTORY_SEPARATOR;
        $matches = array();
        foreach ($prefixes as $prefix) {
            $siteurl = self::siteurl_from_options_dump($db_dir . $prefix . 'options.sql');
            if ($siteurl !== null && self::normalize_site_address($siteurl) === $want) {
                $matches[] = $prefix;
            }
        }
        if (count($matches) === 1) {
            return array('prefix' => $matches[0], 'how' => 'siteurl');
        }
        return false;
    }

    /** "https://Example.com/" and "http://example.com" compare equal. */
    private static function normalize_site_address($url)
    {
        $url = strtolower(trim((string) $url));
        $url = preg_replace('#^https?://#', '', $url);
        return rtrim((string) $url, '/');
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

    public static function swap_table_prefix($filepath, $target_prefix = null, $source_prefix = '')
    {
        global $wpdb;

        if ($target_prefix === null || $target_prefix === '') {
            $target_prefix = $wpdb->base_prefix;
        }

        $old_table_prefix = is_string($source_prefix) && $source_prefix !== ''
            ? $source_prefix
            : self::get_table_prefix($filepath);
        if ($old_table_prefix === false || $old_table_prefix === '') {
            return false;
        }
        if ($old_table_prefix === $target_prefix) {
            return true;
        }

        if (!self::stream_str_replace($filepath, $old_table_prefix, $target_prefix)) {
            One_Click_Migration::write_to_log(sprintf(
                'Error: Failed to swap table prefix "%s" to "%s" in "%s".',
                $old_table_prefix,
                $target_prefix,
                basename($filepath)
            ));
            return false;
        }

        // The swap above rewrites every occurrence in one pass, but an interrupted
        // or restarted restore can leave a dump where some statements still target
        // the source prefix (field incident: CREATE TABLE ran against wp_options
        // while the INSERTs targeted eol_options, which was never created). Verify
        // the statement-level table names and repair any stragglers so CREATE and
        // INSERT both target the destination prefix before anything is imported.
        return self::enforce_statement_table_prefix($filepath, $old_table_prefix, $target_prefix);
    }

    /** In-flight staging prefix option (Stop & Reset / worker death). */
    const OPTION_STAGING_PREFIX = 'ocm_restore_staging_prefix';
    const MYSQL_IDENTIFIER_MAX_LEN = 64;

    /**
     * Prepare dump for staging: source→dest data swap, statement heads → staging,
     * foreign tables under staging; inside CREATE TABLE only, CONSTRAINT names
     * stripped and foreign keys pointed at the staged parent tables.
     */
    public static function prepare_sql_dump_for_staging($filepath, $dest_prefix, $staging_prefix, $source_prefix = '')
    {
        if (!is_string($filepath) || $filepath === ''
            || !is_string($dest_prefix) || $dest_prefix === ''
            || !is_string($staging_prefix) || $staging_prefix === ''
            || $dest_prefix === $staging_prefix
        ) {
            return false;
        }
        if (!self::swap_table_prefix($filepath, $dest_prefix, $source_prefix)) {
            return false;
        }
        if (!self::enforce_statement_table_prefix($filepath, $dest_prefix, $staging_prefix)) {
            return false;
        }
        if (!self::stage_foreign_table_statements($filepath, $staging_prefix, $dest_prefix, $source_prefix)) {
            return false;
        }
        return self::strip_create_table_constraint_names($filepath, $dest_prefix, $staging_prefix);
    }

    /** Remap foreign-prefix statement heads onto `{staging}{original_table}`. */
    public static function stage_foreign_table_statements($filepath, $staging_prefix, $dest_prefix, $source_prefix = '')
    {
        $base = basename((string) $filepath);
        if (!preg_match('/^(.+)\.sql$/i', $base, $m)) {
            return true;
        }
        $table = $m[1];
        if ((is_string($source_prefix) && $source_prefix !== '' && strpos($table, $source_prefix) === 0)
            || strpos($table, $staging_prefix) === 0
            || strpos($table, $dest_prefix) === 0
        ) {
            return true;
        }
        $staged_name = $staging_prefix . $table;
        if (strlen($staged_name) > self::MYSQL_IDENTIFIER_MAX_LEN) {
            One_Click_Migration::write_to_log(sprintf(
                'Error: Foreign table name "%s" is too long for staging prefix "%s" (MySQL identifier limit is %d).',
                $table, $staging_prefix, self::MYSQL_IDENTIFIER_MAX_LEN
            ));
            return false;
        }
        return self::enforce_statement_table_prefix($filepath, $table, $staged_name);
    }

    /** Source-prefix dump suffixes (e.g. "options" from wp1_123_options.sql). */
    public static function source_table_suffixes_from_dumps($sql_files, $source_prefix)
    {
        $suffixes = array();
        if (!is_string($source_prefix) || $source_prefix === '' || !is_array($sql_files)) {
            return $suffixes;
        }
        foreach ($sql_files as $filepath) {
            $base = basename((string) $filepath);
            if (preg_match('/^(.+)\.sql$/i', $base, $m) && strpos($m[1], $source_prefix) === 0) {
                $suffixes[] = substr($m[1], strlen($source_prefix));
            }
        }
        return array_values(array_unique($suffixes));
    }

    /**
     * Require `{import_prefix}options` with siteurl + home. Ignores stray ktv_/nst_ options.
     * $source_prefix is accepted for call-site clarity; the table identity is import_prefix + "options".
     */
    public static function resolve_main_options_table($wpdb, $source_prefix, $import_prefix)
    {
        if (!is_object($wpdb) || !is_string($import_prefix) || $import_prefix === '') {
            return false;
        }
        unset($source_prefix);
        $table_name = $import_prefix . 'options';
        $db_url = $wpdb->get_results("SELECT option_value FROM `{$table_name}` WHERE option_name = 'siteurl'");
        $db_home = $wpdb->get_results("SELECT option_value FROM `{$table_name}` WHERE option_name = 'home'");
        if (!empty($db_url) && !empty($db_url[0]->option_value)
            && !empty($db_home) && !empty($db_home[0]->option_value)
        ) {
            return $table_name;
        }
        return false;
    }

    /**
     * Microtime when the previous CONSTRAINT strip finished (same PHP process /
     * worker resume). Used to log idle time between resume runs.
     *
     * @var float|null
     */
    private static $constraint_strip_last_end = null;

    /**
     * Strip CONSTRAINT `name` only inside real CREATE TABLE … ; statements.
     * Bounded fread chunks (never fgets / never loads a whole line). Quote state
     * and a pending-escape flag span chunks so `\` at a 1 MiB boundary cannot
     * close a string early. Offset/strpos scan — no per-line remainder re-copy.
     *
     * With $reference_prefix and $staging_prefix, `REFERENCES `{reference}x``
     * in those statements becomes `{staging}x`: a staged child otherwise keeps
     * its foreign key on the live table, which a fresh site doesn't have
     * (field restore 4945c8ae: errno 150 "Foreign key constraint is
     * incorrectly formed") and which promote moves to the trash. RENAME TABLE
     * carries the key over to the live name.
     */
    public static function strip_create_table_constraint_names($filepath, $reference_prefix = '', $staging_prefix = '')
    {
        $t0 = microtime(true);
        if (!is_string($filepath) || $filepath === '' || !is_file($filepath) || !is_readable($filepath)) {
            One_Click_Migration::write_to_log(sprintf(
                'Error: Could not read SQL file "%s" to strip CONSTRAINT names.',
                is_string($filepath) ? basename($filepath) : ''
            ));
            return false;
        }

        $in = @fopen($filepath, 'rb');
        $tmp = $filepath . '.ocm-stream.tmp';
        $out = @fopen($tmp, 'wb');
        if (!$in || !$out) {
            if ($in) {
                fclose($in);
            }
            if ($out) {
                fclose($out);
            }
            @unlink($tmp);
            One_Click_Migration::write_to_log(sprintf(
                'Error: Could not stream SQL file "%s" to strip CONSTRAINT names.',
                basename($filepath)
            ));
            return false;
        }

        $in_string = false;
        $pending_escape = false;
        $at_line_start = true;
        $in_create = false;
        $create_buf = '';
        // Only an incomplete CREATE TABLE line-head prefix (≤64 bytes), never a chunk remainder.
        $create_prefix = '';
        $stripped = 0;
        $references = 0;
        $reference_pattern = null;
        if (is_string($reference_prefix) && $reference_prefix !== ''
            && is_string($staging_prefix) && $staging_prefix !== ''
            && $reference_prefix !== $staging_prefix
        ) {
            $reference_pattern = '/\b((?i:REFERENCES)\s+`)' . preg_quote($reference_prefix, '/') . '/';
        }
        $ok = true;
        $create_cap = self::SQL_STREAM_READ_BYTES;
        // Optional post-CREATE tail processed via offset scan (not prepended onto the next fread).
        $tail_chunk = null;

        while ($ok) {
            if ($tail_chunk !== null) {
                $chunk = $tail_chunk;
                $tail_chunk = null;
            } else {
                if (feof($in)) {
                    break;
                }
                $chunk = fread($in, self::SQL_STREAM_READ_BYTES);
                if ($chunk === false) {
                    $ok = false;
                    break;
                }
                if ($chunk === '') {
                    break;
                }
            }

            $len = strlen($chunk);
            $i = 0;
            while ($ok && $i < $len) {
                if ($in_create) {
                    $create_buf .= substr($chunk, $i);
                    $i = $len;
                    if (strlen($create_buf) > $create_cap) {
                        if (!self::write_all($out, $create_buf)) {
                            $ok = false;
                            break;
                        }
                        self::sql_advance_string_state($create_buf, $in_string);
                        $at_line_start = (substr($create_buf, -1) === "\n");
                        $pending_escape = false;
                        $in_create = false;
                        $create_buf = '';
                        break;
                    }
                    $tail = self::strip_flush_create_buffer($create_buf, $out, $stripped, $ok, $reference_pattern, $staging_prefix, $references);
                    if (!$ok) {
                        break;
                    }
                    if ($tail === null) {
                        break;
                    }
                    $in_create = false;
                    $create_buf = '';
                    $at_line_start = true;
                    $pending_escape = false;
                    if ($tail !== '') {
                        $tail_chunk = $tail;
                    }
                    break;
                }

                // Escaped byte carried from a prior chunk (\\ or \' split on a 1 MiB boundary).
                if ($pending_escape) {
                    if (!self::write_all($out, $chunk[$i])) {
                        $ok = false;
                        break;
                    }
                    $i++;
                    $pending_escape = false;
                    $at_line_start = false;
                    // Still inside the string after consuming the escaped byte.
                    $in_string = true;
                    continue;
                }

                // Real CREATE TABLE only at line head outside quotes.
                if (!$in_string && $at_line_start) {
                    $peek = $create_prefix . substr($chunk, $i, 64);
                    if (preg_match('/^\s*CREATE\s+TABLE\b/i', $peek)) {
                        $in_create = true;
                        $create_buf = $create_prefix;
                        $create_prefix = '';
                        continue;
                    }
                    $still_prefix = (bool) preg_match(
                        '/^\s*(?:C(?:R(?:E(?:A(?:T(?:E(?:\s+(?:T(?:A(?:B(?:L(?:E)?)?)?)?)?)?)?)?)?)?)?)?$/i',
                        $peek
                    );
                    if ($still_prefix && strpos($peek, "\n") === false && strlen($peek) < 64) {
                        $create_prefix .= substr($chunk, $i);
                        $i = $len;
                        break;
                    }
                    // Not CREATE — emit the small held prefix, then scan from $i (no remainder re-copy).
                    if ($create_prefix !== '') {
                        if (!self::write_all($out, $create_prefix)) {
                            $ok = false;
                            break;
                        }
                        $create_prefix = '';
                    }
                    $at_line_start = false;
                    // Fall through to the offset scan at the same $i.
                }

                if ($in_string) {
                    $start = $i;
                    while ($i < $len) {
                        $ch = $chunk[$i];
                        if ($ch === '\\') {
                            if ($i + 1 < $len) {
                                $i += 2;
                                continue;
                            }
                            // '\' is the last byte of this chunk — escape applies to the next chunk.
                            $i++;
                            if ($i > $start && !self::write_all($out, substr($chunk, $start, $i - $start))) {
                                $ok = false;
                                break 2;
                            }
                            $pending_escape = true;
                            $i = $len;
                            break 2;
                        }
                        if ($ch === "'") {
                            if ($i + 1 < $len && $chunk[$i + 1] === "'") {
                                $i += 2;
                                continue;
                            }
                            $in_string = false;
                            $i++;
                            break;
                        }
                        $i++;
                    }
                    if ($ok && $i > $start && !self::write_all($out, substr($chunk, $start, $i - $start))) {
                        $ok = false;
                        break;
                    }
                    continue;
                }

                // Outside string: copy until ' or newline via strpos (flat peak, no re-queue).
                $start = $i;
                $q = strpos($chunk, "'", $i);
                $nl = strpos($chunk, "\n", $i);
                $next = false;
                if ($q === false && $nl === false) {
                    $i = $len;
                    $at_line_start = false;
                } elseif ($q === false || ($nl !== false && $nl < $q)) {
                    $i = $nl + 1;
                    $at_line_start = true;
                    $next = true;
                } else {
                    // Enter quoted string at $q.
                    $i = $q + 1;
                    $in_string = true;
                    $at_line_start = false;
                    $next = true;
                }
                if ($i > $start && !self::write_all($out, substr($chunk, $start, $i - $start))) {
                    $ok = false;
                    break;
                }
                if (!$next && $i >= $len) {
                    break;
                }
            }
        }

        if ($ok && $create_prefix !== '') {
            if (!self::write_all($out, $create_prefix)) {
                $ok = false;
            }
        }
        if ($ok && $in_create && $create_buf !== '') {
            if (!self::write_all($out, $create_buf)) {
                $ok = false;
            }
        }

        fclose($in);
        if (!fflush($out) || !fclose($out)) {
            $ok = false;
        }

        if (!$ok) {
            @unlink($tmp);
            One_Click_Migration::write_to_log(sprintf(
                'Error: Failed while stripping CONSTRAINT names in "%s".',
                basename($filepath)
            ));
            return false;
        }

        if (!@rename($tmp, $filepath)) {
            @unlink($tmp);
            One_Click_Migration::write_to_log(sprintf(
                'Error: Could not replace "%s" after stripping CONSTRAINT names.',
                basename($filepath)
            ));
            return false;
        }

        $elapsed_ms = (int) round((microtime(true) - $t0) * 1000);
        $since_prev_ms = -1;
        if (self::$constraint_strip_last_end !== null) {
            $since_prev_ms = (int) round(($t0 - self::$constraint_strip_last_end) * 1000);
            if ($since_prev_ms < 0) {
                $since_prev_ms = 0;
            }
        }
        self::$constraint_strip_last_end = microtime(true);
        One_Click_Migration::write_to_log(sprintf(
            'SYSLOG: CONSTRAINT strip on "%s": stripped=%d peak_bytes=%d memory_limit=%s elapsed_ms=%d since_prev_ms=%s',
            basename($filepath),
            $stripped,
            (int) memory_get_peak_usage(true),
            (string) ini_get('memory_limit'),
            $elapsed_ms,
            $since_prev_ms < 0 ? 'n/a' : (string) $since_prev_ms
        ));
        if ($stripped > 0) {
            One_Click_Migration::write_to_log(sprintf(
                'Notice: Stripped %d CONSTRAINT name(s) from "%s" so staging CREATE TABLE does not clash with live foreign-key names.',
                $stripped,
                basename($filepath)
            ));
        }
        if ($references > 0) {
            One_Click_Migration::write_to_log(sprintf(
                'Notice: Pointed %d foreign key(s) in "%s" at the staging tables (prefix "%s" to "%s").',
                $references,
                basename($filepath),
                $reference_prefix,
                $staging_prefix
            ));
        }
        return true;
    }

    /**
     * If $create_buf holds a full CREATE…; statement, strip CONSTRAINT names
     * (and remap REFERENCES when $reference_pattern is set), write it, and
     * return any trailing bytes after ";". Returns null when more input is
     * needed. Sets $ok false on write/preg failure.
     *
     * @param string      $create_buf
     * @param resource    $out
     * @param int         $stripped
     * @param bool        $ok
     * @param string|null $reference_pattern
     * @param string      $staging_prefix
     * @param int         $references
     * @return string|null
     */
    private static function strip_flush_create_buffer(&$create_buf, $out, &$stripped, &$ok, $reference_pattern = null, $staging_prefix = '', &$references = 0)
    {
        $end = self::sql_statement_end_offset($create_buf);
        if ($end === null) {
            return null;
        }
        $stmt = substr($create_buf, 0, $end + 1);
        $tail = substr($create_buf, $end + 1);
        $rewritten = preg_replace('/\bCONSTRAINT\s+`[^`]+`\s+/i', '', $stmt, -1, $count);
        if (!is_string($rewritten)) {
            $ok = false;
            return null;
        }
        $stripped += (int) $count;
        if ($reference_pattern !== null) {
            $rewritten = preg_replace_callback(
                $reference_pattern,
                static function ($m) use ($staging_prefix) {
                    return $m[1] . $staging_prefix;
                },
                $rewritten,
                -1,
                $count
            );
            if (!is_string($rewritten)) {
                $ok = false;
                return null;
            }
            $references += (int) $count;
        }
        if (!self::write_all($out, $rewritten)) {
            $ok = false;
            return null;
        }
        return is_string($tail) ? $tail : '';
    }

    /** Offset of terminating ";" ignoring semicolons inside single-quoted strings. */
    private static function sql_statement_end_offset($sql)
    {
        $len = strlen($sql);
        $in_string = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];
            if ($in_string) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    if ($i + 1 < $len && $sql[$i + 1] === "'") {
                        $i++;
                        continue;
                    }
                    $in_string = false;
                }
                continue;
            }
            if ($ch === "'") {
                $in_string = true;
            } elseif ($ch === ';') {
                return $i;
            }
        }
        return null;
    }

    /** Advance $in_string across $chunk for single-quoted SQL string state. */
    private static function sql_advance_string_state($chunk, &$in_string)
    {
        $len = strlen($chunk);
        for ($i = 0; $i < $len; $i++) {
            $ch = $chunk[$i];
            if ($in_string) {
                if ($ch === '\\' && $i + 1 < $len) {
                    $i++;
                    continue;
                }
                if ($ch === "'") {
                    if ($i + 1 < $len && $chunk[$i + 1] === "'") {
                        $i++;
                        continue;
                    }
                    $in_string = false;
                }
                continue;
            }
            if ($ch === "'") {
                $in_string = true;
            }
        }
    }

    /** Drop leftover ocmXXXX_ staging tables (optionally protect the current prefix). */
    public static function drop_stale_temp_tables($wpdb, $protect_prefix = null, $live_prefix = null)
    {
        unset($live_prefix);
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_col')) {
            return 0;
        }
        $tables = $wpdb->get_col('SHOW TABLES');
        if (!is_array($tables) || !$tables) {
            return 0;
        }
        $stale = array();
        foreach ($tables as $table) {
            $table = (string) $table;
            if (!preg_match('/^(ocm[0-9a-f]{4}_)/', $table, $m)) {
                continue;
            }
            if (is_string($protect_prefix) && $protect_prefix !== '' && $m[1] === $protect_prefix) {
                continue;
            }
            $stale[] = $table;
        }
        self::drop_tables($wpdb, $stale);
        $dropped = count($stale);
        if ($dropped > 0) {
            One_Click_Migration::write_to_log(sprintf(
                'Notice: Dropped %d stale temporary staging table(s) left by an earlier restore.',
                $dropped
            ));
        }
        return $dropped;
    }

    /** Allocate unique staging prefix ocmXXXX_. */
    public static function allocate_staging_prefix($wpdb)
    {
        $existing = array();
        if (is_object($wpdb) && method_exists($wpdb, 'get_col')) {
            $tables = $wpdb->get_col('SHOW TABLES');
            if (is_array($tables)) {
                foreach ($tables as $table) {
                    $existing[(string) $table] = true;
                }
            }
        }
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $prefix = 'ocm' . substr(bin2hex(random_bytes(2)), 0, 4) . '_';
            foreach ($existing as $table => $_) {
                if (strpos($table, $prefix) === 0) {
                    continue 2;
                }
            }
            return $prefix;
        }
        One_Click_Migration::write_to_log('Error: Could not allocate a unique staging table prefix for database import.');
        return false;
    }

    /** Fail early when staging+suffix would exceed MySQL's 64-char identifier limit. */
    public static function assert_staging_identifier_headroom($sql_files, $staging_prefix, $source_prefix)
    {
        if (!is_string($staging_prefix) || $staging_prefix === '') {
            return false;
        }
        $source_prefix = is_string($source_prefix) ? $source_prefix : '';
        foreach ($sql_files as $filepath) {
            $base = basename((string) $filepath);
            if (!preg_match('/^(.+)\.sql$/i', $base, $m)) {
                continue;
            }
            $table = $m[1];
            $suffix = ($source_prefix !== '' && strpos($table, $source_prefix) === 0)
                ? substr($table, strlen($source_prefix))
                : $table;
            if (strlen($staging_prefix . $suffix) > self::MYSQL_IDENTIFIER_MAX_LEN) {
                One_Click_Migration::write_to_log(sprintf(
                    'Error: Table name "%s" is too long for staging prefix "%s" (MySQL identifier limit is %d). Shorten the table prefix on the source site and take a new backup.',
                    $table, $staging_prefix, self::MYSQL_IDENTIFIER_MAX_LEN
                ));
                return false;
            }
        }
        return true;
    }

    /** Drop every table whose name starts with $prefix. */
    public static function drop_tables_with_prefix($prefix)
    {
        global $wpdb;
        if (!is_string($prefix) || $prefix === '' || !is_object($wpdb)) {
            return;
        }
        $tables = method_exists($wpdb, 'get_col') ? $wpdb->get_col('SHOW TABLES') : array();
        if (!is_array($tables)) {
            return;
        }
        $matching = array();
        foreach ($tables as $table) {
            $table = (string) $table;
            if (strpos($table, $prefix) === 0) {
                $matching[] = $table;
            }
        }
        self::drop_tables($wpdb, $matching);
    }

    /**
     * DROP TABLE each of $tables with foreign key checks off: staged tables
     * reference each other once their keys point at staging, and a parent
     * can't be dropped before its child while checks are on (the tables come
     * in name order, wp_pbb_browsers before wp_pbb_log_browsers).
     *
     * @param object   $wpdb
     * @param string[] $tables
     * @return void
     */
    private static function drop_tables($wpdb, array $tables)
    {
        if (!$tables) {
            return;
        }
        $wpdb->query('SET foreign_key_checks = 0');
        foreach ($tables as $table) {
            $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', (string) $table) . '`');
        }
        $wpdb->query('SET foreign_key_checks = 1');
    }

    /**
     * Atomic promote: source suffixes → `{dest}{suffix}`; foreign staged tables → bare
     * original names. Existing same-named live tables (including stray ktv_options)
     * are moved aside in the same RENAME, then dropped.
     */
    public static function promote_staging_tables($staging_prefix, $dest_prefix, $source_suffixes = array())
    {
        global $wpdb;
        if (!is_string($staging_prefix) || $staging_prefix === ''
            || !is_string($dest_prefix) || $dest_prefix === ''
            || $staging_prefix === $dest_prefix || !is_object($wpdb)
        ) {
            return false;
        }
        $source_suffix_set = array();
        if (is_array($source_suffixes)) {
            foreach ($source_suffixes as $suffix) {
                if (is_string($suffix) && $suffix !== '') {
                    $source_suffix_set[$suffix] = true;
                }
            }
        }
        $tables = method_exists($wpdb, 'get_col') ? $wpdb->get_col('SHOW TABLES') : array();
        if (!is_array($tables)) {
            One_Click_Migration::write_to_log('Error: Could not list tables while promoting the staging import.');
            return false;
        }
        $staged = array();
        $live = array();
        $dest_only = array();
        foreach ($tables as $table) {
            $table = (string) $table;
            if (strpos($table, $staging_prefix) === 0) {
                $staged[] = $table;
            } else {
                $live[$table] = true;
                if (strpos($table, $dest_prefix) === 0) {
                    $dest_only[$table] = true;
                }
            }
        }
        if (!$staged) {
            One_Click_Migration::write_to_log(sprintf(
                'Error: Staging import under prefix "%s" produced no tables to promote.',
                $staging_prefix
            ));
            return false;
        }

        if (!self::keep_destination_plugin_state($wpdb, $staging_prefix, $dest_prefix, $staged, $live)) {
            return false;
        }

        $trash_prefix = 'ocd' . substr(bin2hex(random_bytes(2)), 0, 4) . '_';
        $renames = array();
        $trash_targets = array();
        foreach ($staged as $staging_table) {
            $suffix = substr($staging_table, strlen($staging_prefix));
            $dest_table = ($source_suffix_set && !isset($source_suffix_set[$suffix]))
                ? $suffix
                : $dest_prefix . $suffix;
            if (isset($live[$dest_table])) {
                $trash_table = $trash_prefix . substr(sha1($dest_table), 0, 10);
                $renames[] = self::sql_rename_pair($dest_table, $trash_table);
                $trash_targets[] = $trash_table;
                unset($live[$dest_table], $dest_only[$dest_table]);
            }
            $renames[] = self::sql_rename_pair($staging_table, $dest_table);
        }
        foreach ($dest_only as $dest_table => $_) {
            $trash_table = $trash_prefix . substr(sha1($dest_table), 0, 10);
            $renames[] = self::sql_rename_pair($dest_table, $trash_table);
            $trash_targets[] = $trash_table;
        }

        $wpdb->last_error = '';
        $result = self::query_imported_sql($wpdb, 'RENAME TABLE ' . implode(', ', $renames));
        if ($result === false || !empty($wpdb->last_error)) {
            One_Click_Migration::write_to_log(sprintf(
                'Error: Atomic promote of staging prefix "%s" to "%s" failed: %s',
                $staging_prefix,
                $dest_prefix,
                !empty($wpdb->last_error) ? $wpdb->last_error : 'RENAME TABLE rejected'
            ));
            return false;
        }
        self::drop_tables($wpdb, $trash_targets);
        if (function_exists('delete_option')) {
            delete_option(self::OPTION_STAGING_PREFIX);
        }
        One_Click_Migration::write_to_log(sprintf(
            'SYSLOG: Promoted %d staging table(s) from "%s" to live prefix "%s".',
            count($staged), $staging_prefix, $dest_prefix
        ));
        return true;
    }

    /**
     * The source dumps its options while its own backup is running, so every
     * backup carries the source's in-flight plugin state: start flags, run id,
     * presigned PUT URLs for the source bucket, heartbeat and queue rows. Left
     * in place, the destination's watchdog revives that backup after promote
     * and uploads into the source's bucket (#78). Plugin state belongs to the
     * site running the plugin, so swap the staged plugin rows for the
     * destination's live ones before the RENAME.
     *
     * @param \wpdb    $wpdb
     * @param string   $staging_prefix
     * @param string   $dest_prefix
     * @param string[] $staged Staged table names.
     * @param array    $live   Live table name => true.
     * @return bool False when the staged state could not be cleared; promote must stop.
     */
    private static function keep_destination_plugin_state($wpdb, $staging_prefix, $dest_prefix, array $staged, array $live)
    {
        $tables = array(
            'options' => array('option_name', array('option_name', 'option_value', 'autoload')),
            'sitemeta' => array('meta_key', array('site_id', 'meta_key', 'meta_value')),
        );
        foreach ($tables as $suffix => $spec) {
            list($key_column, $columns) = $spec;
            $staging_table = $staging_prefix . $suffix;
            if (!in_array($staging_table, $staged, true)) {
                continue;
            }
            $where = self::plugin_state_where($key_column);
            $staging_sql = '`' . str_replace('`', '``', $staging_table) . '`';

            $wpdb->last_error = '';
            $dropped = $wpdb->query("DELETE FROM {$staging_sql} WHERE {$where}");
            if ($dropped === false || !empty($wpdb->last_error)) {
                One_Click_Migration::write_to_log(sprintf(
                    'Error: Could not clear the source site\'s plugin state from staging table "%s": %s',
                    $staging_table,
                    !empty($wpdb->last_error) ? $wpdb->last_error : 'DELETE rejected'
                ));
                return false;
            }

            $kept = 0;
            $live_table = $dest_prefix . $suffix;
            if (isset($live[$live_table])) {
                $column_list = '`' . implode('`, `', $columns) . '`';
                $live_sql = '`' . str_replace('`', '``', $live_table) . '`';
                $wpdb->last_error = '';
                $kept = $wpdb->query(
                    "INSERT INTO {$staging_sql} ({$column_list}) SELECT {$column_list} FROM {$live_sql} WHERE {$where}"
                );
                if ($kept === false || !empty($wpdb->last_error)) {
                    One_Click_Migration::write_to_log(sprintf(
                        'Error: Could not carry this site\'s plugin state into staging table "%s": %s',
                        $staging_table,
                        !empty($wpdb->last_error) ? $wpdb->last_error : 'INSERT rejected'
                    ));
                    return false;
                }
            }

            One_Click_Migration::write_to_log(sprintf(
                'SYSLOG: Plugin state on "%s": dropped %d imported row(s), kept %d row(s) from "%s".',
                $staging_table,
                (int) $dropped,
                (int) $kept,
                $live_table
            ));

            if ($suffix === 'options'
                && !self::keep_destination_plugin_cron($wpdb, $staging_table, isset($live[$live_table]) ? $live_table : null)
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * The source's WP `cron` option still schedules its own 1CM events (the
     * background processes' healthchecks), which would fire on the destination
     * after promote. Drop them from the staged copy and carry over the
     * destination's own 1CM events instead, like the option rows above (#95).
     * Other plugins' events are left exactly as imported.
     *
     * @param \wpdb       $wpdb
     * @param string      $staging_table
     * @param string|null $live_table Null when the destination has no options table.
     * @return bool False when the staged cron could not be rewritten; promote must stop.
     */
    private static function keep_destination_plugin_cron($wpdb, $staging_table, $live_table)
    {
        if (!method_exists($wpdb, 'get_var')) {
            return true;
        }
        $staged = self::read_cron_option($wpdb, $staging_table);
        if (!is_array($staged)) {
            // No cron row, or one WordPress itself couldn't read: nothing to run.
            return true;
        }
        $live = $live_table !== null ? self::read_cron_option($wpdb, $live_table) : null;
        list($cron, $dropped, $kept) = self::merge_plugin_cron_events($staged, is_array($live) ? $live : array());
        if ($dropped === 0 && $kept === 0) {
            return true;
        }

        $staging_sql = '`' . str_replace('`', '``', $staging_table) . '`';
        $wpdb->last_error = '';
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$staging_sql} SET `option_value` = %s WHERE `option_name` = 'cron'",
            serialize($cron)
        ));
        if ($updated === false || !empty($wpdb->last_error)) {
            One_Click_Migration::write_to_log(sprintf(
                'Error: Could not clear the source site\'s scheduled plugin events from staging table "%s": %s',
                $staging_table,
                !empty($wpdb->last_error) ? $wpdb->last_error : 'UPDATE rejected'
            ));
            return false;
        }
        One_Click_Migration::write_to_log(sprintf(
            'SYSLOG: Plugin cron events on "%s": dropped %d imported event(s), kept %d event(s) from "%s".',
            $staging_table,
            $dropped,
            $kept,
            $live_table !== null ? $live_table : '-'
        ));
        return true;
    }

    /**
     * @param \wpdb  $wpdb
     * @param string $table
     * @return array|null The unserialized `cron` option, or null when missing or unreadable.
     */
    private static function read_cron_option($wpdb, $table)
    {
        $table_sql = '`' . str_replace('`', '``', $table) . '`';
        $raw = $wpdb->get_var("SELECT `option_value` FROM {$table_sql} WHERE `option_name` = 'cron' LIMIT 1");
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        // Event args never need live objects here; unknown classes round-trip
        // through serialize() unchanged as __PHP_Incomplete_Class.
        $cron = @unserialize($raw, array('allowed_classes' => false));
        return is_array($cron) ? $cron : null;
    }

    /**
     * True for hooks this plugin schedules: the background processes'
     * healthchecks (wp_ocm_background_*_cron) and any ocm_* hook.
     *
     * @param string $hook
     * @return bool
     */
    public static function is_plugin_cron_hook($hook)
    {
        $hook = (string) $hook;
        return strpos($hook, 'ocm_') === 0 || strpos($hook, 'ocm_background_') !== false;
    }

    /**
     * Remove 1CM hooks from the source cron array and add the destination's
     * 1CM hooks. Keeps WordPress's layout: timestamps ascending, `version` last.
     *
     * @param array $staged Source cron array (from the dump).
     * @param array $live   Destination cron array.
     * @return array{0: array, 1: int, 2: int} Cron array, events dropped, events kept.
     */
    public static function merge_plugin_cron_events(array $staged, array $live)
    {
        $dropped = 0;
        $kept = 0;
        foreach ($staged as $timestamp => $hooks) {
            if ($timestamp === 'version' || !is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $hook => $events) {
                if (self::is_plugin_cron_hook($hook)) {
                    $dropped += is_array($events) ? count($events) : 1;
                    unset($staged[$timestamp][$hook]);
                }
            }
            if (!$staged[$timestamp]) {
                unset($staged[$timestamp]);
            }
        }
        foreach ($live as $timestamp => $hooks) {
            if ($timestamp === 'version' || !is_array($hooks)) {
                continue;
            }
            foreach ($hooks as $hook => $events) {
                if (!self::is_plugin_cron_hook($hook) || !is_array($events)) {
                    continue;
                }
                foreach ($events as $event_key => $event) {
                    $staged[$timestamp][$hook][$event_key] = $event;
                    $kept++;
                }
            }
        }

        $version = array_key_exists('version', $staged) ? $staged['version'] : null;
        unset($staged['version']);
        ksort($staged, SORT_NUMERIC);
        if ($version !== null) {
            $staged['version'] = $version;
        }
        return array($staged, $dropped, $kept);
    }

    /**
     * WHERE clause matching rows owned by this plugin's runtime: ocm_* options,
     * background-process queue/status/lock rows, step checkpoints and ocm
     * transients. Constant patterns only; no user input.
     *
     * @param string $column option_name or meta_key.
     * @return string
     */
    public static function plugin_state_where($column)
    {
        $column = '`' . str_replace('`', '``', $column) . '`';
        $likes = array(
            'ocm\_%',
            '%ocm\_background\_%',
            '\_transient\_ocm\_%',
            '\_transient\_timeout\_ocm\_%',
            '\_site\_transient\_ocm\_%',
            '\_site\_transient\_timeout\_ocm\_%',
        );
        $parts = array();
        foreach ($likes as $like) {
            $parts[] = "{$column} LIKE '{$like}'";
        }
        $parts[] = "{$column} IN ('backup_steps', 'restore_steps')";
        return '(' . implode(' OR ', $parts) . ')';
    }

    private static function sql_rename_pair($from, $to)
    {
        return '`' . str_replace('`', '``', $from) . '` TO `' . str_replace('`', '``', $to) . '`';
    }

    /**
     * Regex matching a statement head (line start) that targets a table with the
     * given prefix: DROP/CREATE TABLE, INSERT/REPLACE INTO, ALTER TABLE (including
     * the "!40000 ALTER TABLE" conditional-comment form mysqldump emits),
     * LOCK TABLES and TRUNCATE.
     *
     * @param string $prefix
     * @return string
     */
    private static function statement_head_pattern($prefix)
    {
        return '/^(\s*(?:\/\*!\d+\s+)?(?:DROP\s+TABLE(?:\s+IF\s+EXISTS)?|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?|INSERT\s+(?:IGNORE\s+)?INTO|REPLACE\s+INTO|ALTER\s+TABLE|LOCK\s+TABLES|TRUNCATE(?:\s+TABLE)?)\s+`?)'
            . preg_quote($prefix, '/') . '/i';
    }

    private static function replace_statement_head_prefix($head, $pattern, $new_prefix)
    {
        return preg_replace_callback(
            $pattern,
            static function ($matches) use ($new_prefix) {
                return $matches[1] . $new_prefix;
            },
            $head,
            1
        );
    }

    /**
     * Rewrite the table identifier in a single SQL statement's head from
     * $old_prefix to $new_prefix. Anchored at the statement start, so table-name
     * lookalikes inside VALUES data are never touched. No-op for same-prefix
     * restores or when no prefixes were provided.
     *
     * @param string $query
     * @param string $old_prefix
     * @param string $new_prefix
     * @return string
     */
    public static function remap_statement_table_prefix($query, $old_prefix, $new_prefix)
    {
        if (!is_string($query) || $query === ''
            || !is_string($old_prefix) || $old_prefix === ''
            || !is_string($new_prefix) || $new_prefix === ''
            || $old_prefix === $new_prefix
        ) {
            return $query;
        }

        return self::replace_statement_head_prefix($query, self::statement_head_pattern($old_prefix), $new_prefix);
    }

    /**
     * Verify that no statement in the dump still targets a table with the source
     * prefix, and rewrite the statement heads to the destination prefix if any do.
     * Streamed; only line heads are inspected, so INSERT payload data is never
     * modified. Returns false only on I/O failure.
     *
     * @param string $filepath
     * @param string $old_prefix
     * @param string $new_prefix
     * @return bool
     */
    public static function enforce_statement_table_prefix($filepath, $old_prefix, $new_prefix)
    {
        if ($old_prefix === '' || $new_prefix === '' || $old_prefix === $new_prefix) {
            return true;
        }

        $matched = false;
        if (!self::scan_or_remap_statement_prefix($filepath, $old_prefix, $new_prefix, false, $matched)) {
            return false;
        }
        if (!$matched) {
            return true;
        }

        One_Click_Migration::write_to_log(sprintf(
            'Notice: "%s" still contained statements targeting source prefix "%s" after the prefix swap; remapping their table names to destination prefix "%s".',
            basename($filepath),
            $old_prefix,
            $new_prefix
        ));

        return self::scan_or_remap_statement_prefix($filepath, $old_prefix, $new_prefix, true, $matched);
    }

    /**
     * Streamed walk over the dump's line heads. In scan mode ($repair = false)
     * returns as soon as a statement head targeting $old_prefix is found. In
     * repair mode rewrites those heads to $new_prefix via a temp file + rename.
     * Memory stays bounded: only up to SQL_STATEMENT_HEAD_BYTES of a line is ever
     * buffered; the rest of long INSERT lines is passed through untouched.
     *
     * @param string $filepath
     * @param string $old_prefix
     * @param string $new_prefix
     * @param bool   $repair
     * @param bool   $matched Set true when at least one statement head matched.
     * @return bool  False on I/O failure.
     */
    private static function scan_or_remap_statement_prefix($filepath, $old_prefix, $new_prefix, $repair, &$matched)
    {
        $matched = false;
        $pattern = self::statement_head_pattern($old_prefix);

        $in = @fopen($filepath, 'rb');
        if (!$in) {
            One_Click_Migration::write_to_log(sprintf('Error: Could not read SQL file "%s" for prefix verification.', $filepath));
            return false;
        }

        $out = null;
        $tmp = null;
        if ($repair) {
            $tmp = $filepath . '.ocm-remap.tmp';
            $out = @fopen($tmp, 'wb');
            if (!$out) {
                fclose($in);
                One_Click_Migration::write_to_log(sprintf('Error: Could not open temp file to remap table prefix in "%s".', $filepath));
                return false;
            }
        }

        $head = '';
        $mid_line = false;
        $ok = true;

        while (!feof($in)) {
            $chunk = fread($in, self::SQL_STREAM_READ_BYTES);
            if ($chunk === false) {
                $ok = false;
                break;
            }

            $offset = 0;
            $len = strlen($chunk);
            while ($offset < $len) {
                if ($mid_line) {
                    $nl = strpos($chunk, "\n", $offset);
                    $take = ($nl === false) ? $len - $offset : $nl - $offset + 1;
                    if ($out) {
                        if (!self::write_all($out, substr($chunk, $offset, $take))) {
                            $ok = false;
                            break 2;
                        }
                    }
                    $offset += $take;
                    if ($nl !== false) {
                        $mid_line = false;
                    }
                    continue;
                }

                $nl = strpos($chunk, "\n", $offset);
                if ($nl === false) {
                    $head .= substr($chunk, $offset);
                    $offset = $len;
                } else {
                    $head .= substr($chunk, $offset, $nl - $offset + 1);
                    $offset = $nl + 1;
                }

                $line_complete = ($nl !== false);
                if ($line_complete || strlen($head) >= self::SQL_STATEMENT_HEAD_BYTES) {
                    if (preg_match($pattern, $head)) {
                        $matched = true;
                        if (!$repair) {
                            fclose($in);
                            return true;
                        }
                        $head = self::replace_statement_head_prefix($head, $pattern, $new_prefix);
                    }
                    if ($out) {
                        if (!self::write_all($out, $head)) {
                            $ok = false;
                            break 2;
                        }
                    }
                    $head = '';
                    if (!$line_complete) {
                        $mid_line = true;
                    }
                }
            }
        }

        if ($ok && $head !== '') {
            if (preg_match($pattern, $head)) {
                $matched = true;
                if ($repair) {
                    $head = self::replace_statement_head_prefix($head, $pattern, $new_prefix);
                }
            }
            if ($out) {
                $ok = self::write_all($out, $head);
            }
        }

        fclose($in);
        if (!$out) {
            if (!$ok) {
                One_Click_Migration::write_to_log(sprintf('Error: Read failed while verifying table prefix in SQL file "%s".', $filepath));
            }
            return $ok;
        }
        if (!fflush($out) || !fclose($out)) {
            $ok = false;
        }

        if (!$ok) {
            @unlink($tmp);
            One_Click_Migration::write_to_log(sprintf('Error: Read/write failed while remapping table prefix in SQL file "%s"; file left unchanged.', $filepath));
            return false;
        }

        if (!$matched) {
            @unlink($tmp);
            return true;
        }

        if (!@rename($tmp, $filepath)) {
            @unlink($tmp);
            One_Click_Migration::write_to_log(sprintf('Error: Could not atomically finalize prefix remap for SQL file "%s"; original left unchanged.', $filepath));
            return false;
        }

        return true;
    }

    private static function clear_sql_content()
    {
        self::$sql_line = '';
        self::$sql_buffer = '';
    }

    /**
     * Open a dump file for chunked writes. Peak RSS stays bounded to the flush size.
     *
     * @param string $filepath
     * @return bool
     */
    private static function open_sql_dump($filepath)
    {
        self::close_sql_dump();
        self::clear_sql_content();
        $handle = @fopen($filepath, 'wb');
        if (!$handle) {
            One_Click_Migration::write_to_log(sprintf('Error: Could not open SQL dump file "%s" for writing.', $filepath));
            return false;
        }
        self::$sql_fh = $handle;
        return true;
    }

    public static function write_all($handle, $data)
    {
        if (!is_resource($handle) || !is_string($data)) {
            return false;
        }

        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            $written = fwrite($handle, substr($data, $offset));
            if ($written === false || $written === 0) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }

    private static function add_sql_line($line)
    {
        // Write through to disk. Do not sprintf/concat into $sql_buffer — that
        // second copy is what OOM'd at :792 when WP had already used the limit.
        if (self::$sql_fh) {
            if (self::$sql_buffer !== '') {
                if (!self::flush_sql_dump()) {
                    return false;
                }
            }
            if (!self::write_all(self::$sql_fh, $line) || !self::write_all(self::$sql_fh, "\n")) {
                One_Click_Migration::write_to_log('Error: Failed to write SQL dump chunk to disk.');
                return false;
            }
            return true;
        }
        self::$sql_buffer .= $line . "\n";
        if (strlen(self::$sql_buffer) >= self::SQL_STREAM_FLUSH_BYTES) {
            return self::flush_sql_dump();
        }
        return true;
    }

    private static function flush_sql_dump()
    {
        if (!self::$sql_fh || self::$sql_buffer === '') {
            return true;
        }
        if (!self::write_all(self::$sql_fh, self::$sql_buffer)) {
            One_Click_Migration::write_to_log('Error: Failed to write SQL dump chunk to disk.');
            return false;
        }
        self::$sql_buffer = '';
        self::$sql_line = '';
        return true;
    }

    private static function close_sql_dump()
    {
        $ok = self::flush_sql_dump();
        if (self::$sql_fh) {
            $ok = fflush(self::$sql_fh) && $ok;
            $ok = fclose(self::$sql_fh) && $ok;
            self::$sql_fh = null;
        }
        self::$sql_buffer = '';
        self::$sql_line = '';
        return $ok;
    }

    /**
     * Replace $search with $replace in $filepath using bounded chunks so the
     * full dump is never held in PHP memory. Handles matches that span chunks.
     *
     * @param string $filepath
     * @param string $search
     * @param string $replace
     * @return bool
     */
    private static function stream_str_replace($filepath, $search, $replace)
    {
        if (!is_string($search) || $search === '' || $search === $replace) {
            return true;
        }
        if (!is_file($filepath) || !is_readable($filepath)) {
            One_Click_Migration::write_to_log(sprintf('Error: Could not read SQL file "%s" for streaming replace.', $filepath));
            return false;
        }

        $tmp = $filepath . '.ocm-stream.tmp';
        $in = @fopen($filepath, 'rb');
        $out = @fopen($tmp, 'wb');
        if (!$in || !$out) {
            if ($in) {
                fclose($in);
            }
            if ($out) {
                fclose($out);
            }
            One_Click_Migration::write_to_log(sprintf('Error: Could not stream-replace in SQL file "%s".', $filepath));
            return false;
        }

        $carry = '';
        $read_ok = true;
        while (!feof($in)) {
            $chunk = fread($in, self::SQL_STREAM_READ_BYTES);
            if ($chunk === false) {
                // Never rename a partial result over the original: a truncated
                // dump would import as silent data loss.
                $read_ok = false;
                break;
            }
            $data = $carry . $chunk;
            if ($data === '') {
                break;
            }
            if (!feof($in)) {
                $keep = self::longest_prefix_suffix($data, $search);
                if ($keep > 0) {
                    $carry = substr($data, -$keep);
                    $data = substr($data, 0, -$keep);
                } else {
                    $carry = '';
                }
            } else {
                $carry = '';
            }
            if (!self::write_all($out, str_replace($search, $replace, $data))) {
                $read_ok = false;
                break;
            }
        }
        if ($read_ok && $carry !== '' && !self::write_all($out, str_replace($search, $replace, $carry))) {
            $read_ok = false;
        }
        fclose($in);
        if (!fflush($out) || !fclose($out)) {
            $read_ok = false;
        }

        if (!$read_ok) {
            @unlink($tmp);
            One_Click_Migration::write_to_log(sprintf('Error: Read/write failed while stream-replacing in SQL file "%s"; file left unchanged.', $filepath));
            return false;
        }

        if (!@rename($tmp, $filepath)) {
            @unlink($tmp);
            One_Click_Migration::write_to_log(sprintf('Error: Could not atomically finalize streamed SQL file "%s"; original left unchanged.', $filepath));
            return false;
        }
        return true;
    }

    /**
     * Longest suffix of $data that is a proper prefix of $search.
     * Used so chunked str_replace does not split a match across a read boundary.
     *
     * @param string $data
     * @param string $search
     * @return int
     */
    private static function longest_prefix_suffix($data, $search)
    {
        $max = min(strlen($data), max(0, strlen($search) - 1));
        for ($k = $max; $k > 0; $k--) {
            if (substr($data, -$k) === substr($search, 0, $k)) {
                return $k;
            }
        }
        return 0;
    }

    /**
     * Option storing the SOURCE backup's plugin version (from the remote log).
     */
    const OPTION_BACKUP_PLUGIN_VERSION = 'ocm_backup_plugin_version';

    /**
     * Reject dumps whose BINARY/BLOB INSERT values cannot round-trip on a
     * utf8mb4 connection. Pre-3.1.19 PHP export put those bytes in quoted
     * strings (NUL as the escape `\0`, other bytes raw), which breaks restore.
     * Refuses only when a value of a column declared binary/blob/varbinary in
     * CREATE TABLE is unsafe — never because the dump merely mentions "blob"
     * in text, or because a BLOB holds plain UTF-8.
     *
     * Always scans dump contents. Plugin version (when known) is used only to
     * word the refusal message — never to skip the scan. 3.1.19 hex dumps pass
     * the value check on their own.
     *
     * Stops before import; does not modify the database.
     *
     * @param array<int, string> $sql_files
     * @return bool True when safe to proceed; false when restore must abort.
     */
    public static function preflight_reject_unsafe_binary_dumps($sql_files)
    {
        $result = self::preflight_reject_unsafe_binary_dumps_resumable(
            $sql_files,
            array('status' => 'pending', 'file_index' => 0, 'byte_offset' => 0),
            null
        );
        return isset($result['status']) && $result['status'] === 'pass';
    }

    /**
     * Resumable binary dump preflight. $deadline is microtime(true) or null (no limit).
     * Returns ['status' => 'pass'|'refuse'|'continue', 'state' => array].
     *
     * @param array<int, string> $sql_files
     * @param array              $state
     * @param float|null         $deadline
     * @return array{status: string, state: array}
     */
    public static function preflight_reject_unsafe_binary_dumps_resumable($sql_files, $state, $deadline = null)
    {
        $version = self::backup_source_plugin_version();
        $file_index = isset($state['file_index']) ? (int) $state['file_index'] : 0;
        $byte_offset = isset($state['byte_offset']) ? (int) $state['byte_offset'] : 0;
        $sql_files = array_values($sql_files);

        for ($i = $file_index; $i < count($sql_files); $i++) {
            $filepath = $sql_files[$i];
            if (!is_string($filepath) || $filepath === '') {
                return array(
                    'status' => 'refuse',
                    'state' => array('file_index' => $i, 'byte_offset' => 0, 'status' => 'refuse'),
                );
            }
            if (!is_file($filepath) || !is_readable($filepath)) {
                One_Click_Migration::write_to_log(sprintf(
                    'Error: Database dump "%s" is missing or unreadable during binary preflight.',
                    basename($filepath)
                ));
                One_Click_Migration::write_to_log(
                    'Restore stopped before import: backup needs a fresh 3.1.19 backup. No changes were made.'
                );
                return array(
                    'status' => 'refuse',
                    'state' => array('file_index' => $i, 'byte_offset' => 0, 'status' => 'refuse'),
                );
            }

            $scan = self::sql_dump_scan_unsafe_binary_resumable(
                $filepath,
                $i === $file_index ? $byte_offset : 0,
                $deadline,
                $i === $file_index ? $state : array()
            );
            if ($scan['status'] === 'continue') {
                One_Click_Migration::write_to_log(sprintf(
                    'SYSLOG: Binary preflight progress file=%s offset=%d',
                    basename($filepath),
                    (int) $scan['byte_offset']
                ));
                return array(
                    'status' => 'continue',
                    'state' => array(
                        'file_index' => $i,
                        'byte_offset' => (int) $scan['byte_offset'],
                        'status' => 'pending',
                        'binary_indexes' => isset($scan['binary_indexes']) ? $scan['binary_indexes'] : array(),
                        'binary_names' => isset($scan['binary_names']) ? $scan['binary_names'] : array(),
                        'create_unparsed' => !empty($scan['create_unparsed']),
                    ),
                );
            }
            if ($scan['status'] === 'refuse' || !empty($scan['unsafe'])) {
                One_Click_Migration::write_to_log(sprintf(
                    'Error: This backup was made with plugin version %s and contains binary/BLOB column values that cannot be restored safely (not hex-encoded). Update 1-Click Migration on the source site to 3.1.19 or later, take a new backup, then restore that.',
                    $version !== null ? $version : 'before 3.1.19'
                ));
                if (!empty($scan['reason'])) {
                    One_Click_Migration::write_to_log('Error: ' . $scan['reason']);
                }
                One_Click_Migration::write_to_log(
                    'Restore stopped before import: backup needs a fresh 3.1.19 backup. No changes were made.'
                );
                return array(
                    'status' => 'refuse',
                    'state' => array('file_index' => $i, 'byte_offset' => 0, 'status' => 'refuse'),
                );
            }
            $file_index = $i + 1;
            $byte_offset = 0;
        }

        return array(
            'status' => 'pass',
            'state' => array('file_index' => count($sql_files), 'byte_offset' => 0, 'status' => 'pass'),
        );
    }

    /**
     * Source backup plugin version from the option set when the remote backup
     * log was stored. Does not read the local debug log — that may already
     * contain the destination's own version line when no remote log was merged.
     *
     * @return string|null
     */
    public static function backup_source_plugin_version()
    {
        if (function_exists('get_option')) {
            $stored = get_option(self::OPTION_BACKUP_PLUGIN_VERSION, null);
            if (is_string($stored) && preg_match('/^[0-9]+(?:\.[0-9]+){0,3}$/', $stored)) {
                return $stored;
            }
        }
        return null;
    }

    /**
     * Persist the source backup's plugin version from the remote (backup-side)
     * log body. Call with the remote log only — never the destination's local log.
     *
     * @param string $remote_log
     * @return string|null
     */
    public static function store_backup_plugin_version_from_log($remote_log)
    {
        $version = null;
        if (is_string($remote_log) && preg_match('/OCM Plugin Version:\s*([0-9]+(?:\.[0-9]+){0,3})/i', $remote_log, $m)) {
            $version = $m[1];
        }
        if (function_exists('update_option')) {
            if ($version !== null) {
                update_option(self::OPTION_BACKUP_PLUGIN_VERSION, $version, false);
            } else {
                delete_option(self::OPTION_BACKUP_PLUGIN_VERSION);
            }
        }
        return $version;
    }

    /**
     * First "OCM Plugin Version:" in the debug log (source backup is prepended
     * ahead of destination lines). Kept for tests that explicitly exercise log
     * parsing; restore wording uses backup_source_plugin_version() instead.
     *
     * @return string|null
     */
    public static function backup_plugin_version_from_log()
    {
        $file = defined('OCM_DEBUG_LOG_FILE') ? OCM_DEBUG_LOG_FILE : '';
        if ($file === '' || !is_readable($file)) {
            return null;
        }
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return null;
        }
        $found = null;
        while (($line = fgets($fh)) !== false) {
            if (preg_match('/OCM Plugin Version:\s*([0-9]+(?:\.[0-9]+){0,3})/i', $line, $m)) {
                $found = $m[1];
                break;
            }
        }
        fclose($fh);
        return $found;
    }

    /**
     * True when CREATE TABLE declares binary/blob columns whose INSERT values
     * look like the broken 3.1.18 PHP exporter output (quoted literals with
     * `\0` / invalid UTF-8), not hex literals and not plain UTF-8 text.
     * Fail-closed: unreadable files, unparsed CREATE with INSERTs, and oversized
     * statements in binary-column tables are unsafe.
     *
     * @param string $filepath
     * @return bool
     */
    public static function sql_dump_has_unsafe_binary_insert_values($filepath)
    {
        $scan = self::sql_dump_scan_unsafe_binary_resumable($filepath, 0, null);
        return !empty($scan['unsafe']) || (isset($scan['status']) && $scan['status'] === 'refuse');
    }

    /**
     * Resumable scan of one dump. Returns status pass|refuse|continue.
     *
     * @param string     $filepath
     * @param int        $byte_offset
     * @param float|null $deadline
     * @param array      $resume     Prior binary-column parse state for mid-file resume.
     * @return array
     */
    public static function sql_dump_scan_unsafe_binary_resumable($filepath, $byte_offset = 0, $deadline = null, $resume = array())
    {
        $in = @fopen($filepath, 'rb');
        if (!$in) {
            return array(
                'status' => 'refuse',
                'unsafe' => true,
                'byte_offset' => 0,
                'reason' => 'Could not open SQL dump for binary preflight.',
            );
        }

        if ($byte_offset > 0) {
            if (@fseek($in, $byte_offset) !== 0) {
                fclose($in);
                return array(
                    'status' => 'refuse',
                    'unsafe' => true,
                    'byte_offset' => 0,
                    'reason' => 'Could not seek in SQL dump for binary preflight resume.',
                );
            }
        }

        $binary_indexes = isset($resume['binary_indexes']) && is_array($resume['binary_indexes'])
            ? $resume['binary_indexes']
            : array();
        $binary_names = isset($resume['binary_names']) && is_array($resume['binary_names'])
            ? $resume['binary_names']
            : array();
        $create_unparsed = !empty($resume['create_unparsed']);
        $buf = '';
        $unsafe = false;
        $reason = '';
        $pos = $byte_offset;

        while (!feof($in) && !$unsafe) {
            if ($deadline !== null && microtime(true) >= $deadline) {
                fclose($in);
                // Resume at the start of the unprocessed buffer — $pos is bytes
                // READ, but a partial statement may still sit in $buf. Saving
                // $pos alone skips those bytes on the next pass.
                $resume_offset = $pos - strlen($buf);
                if ($resume_offset < 0) {
                    $resume_offset = 0;
                }
                return array(
                    'status' => 'continue',
                    'byte_offset' => $resume_offset,
                    'binary_indexes' => $binary_indexes,
                    'binary_names' => $binary_names,
                    'create_unparsed' => $create_unparsed,
                );
            }
            $chunk = fread($in, self::SQL_STREAM_READ_BYTES);
            if ($chunk === false) {
                fclose($in);
                return array(
                    'status' => 'refuse',
                    'unsafe' => true,
                    'byte_offset' => $pos,
                    'reason' => 'Failed reading SQL dump during binary preflight.',
                );
            }
            if ($chunk === '') {
                break;
            }
            $pos += strlen($chunk);
            $buf .= $chunk;

            while (!$unsafe) {
                $stmt = self::sql_buffer_shift_statement(
                    $buf,
                    !feof($in),
                    !empty($binary_indexes) || !empty($binary_names) || $create_unparsed
                );
                if ($stmt === false) {
                    $unsafe = true;
                    $reason = 'SQL statement exceeded the binary-preflight scan window in a binary-column table.';
                    break;
                }
                if ($stmt === null) {
                    break;
                }
                $code = self::sql_ltrim_comments_and_whitespace($stmt);
                if ($code === '') {
                    continue;
                }
                if (preg_match('/^(?:\/\*!\d+\s+)?CREATE\s+TABLE\b/i', $code)) {
                    $parsed = self::binary_columns_from_create_table($code);
                    $binary_indexes = $parsed[0];
                    $binary_names = $parsed[1];
                    $create_unparsed = empty($parsed[2]);
                    continue;
                }
                if (!preg_match('/^INSERT\s+(?:IGNORE\s+)?INTO\b/i', $code)) {
                    continue;
                }
                if ($create_unparsed) {
                    $unsafe = true;
                    $reason = 'CREATE TABLE could not be parsed for a dump that contains INSERT statements.';
                    break;
                }
                if (!$binary_indexes && !$binary_names) {
                    continue;
                }
                if (self::insert_has_unsafe_binary_values($code, $binary_indexes, $binary_names)) {
                    $unsafe = true;
                    $reason = 'Unsafe binary/BLOB INSERT values detected.';
                }
            }
        }

        fclose($in);
        if ($unsafe) {
            return array(
                'status' => 'refuse',
                'unsafe' => true,
                'byte_offset' => $pos,
                'reason' => $reason,
            );
        }
        return array(
            'status' => 'pass',
            'unsafe' => false,
            'byte_offset' => $pos,
        );
    }

    /**
     * Strip leading whitespace, hash/dash line comments, and C-style block
     * comments so CREATE/INSERT detection still works when comment padding
     * precedes the statement in the same semicolon-terminated chunk.
     *
     * @param string $stmt
     * @return string
     */
    public static function sql_ltrim_comments_and_whitespace($stmt)
    {
        $stmt = (string) $stmt;
        while ($stmt !== '') {
            if (preg_match('/^\s+/', $stmt, $m)) {
                $stmt = substr($stmt, strlen($m[0]));
                continue;
            }
            if (preg_match('/^#[^\n]*(?:\n|$)/', $stmt, $m)) {
                $stmt = substr($stmt, strlen($m[0]));
                continue;
            }
            if (preg_match('/^--[^\n]*(?:\n|$)/', $stmt, $m)) {
                $stmt = substr($stmt, strlen($m[0]));
                continue;
            }
            if (preg_match('/^\/\*.*?\*\//s', $stmt, $m)) {
                $stmt = substr($stmt, strlen($m[0]));
                continue;
            }
            break;
        }
        return $stmt;
    }

    /**
     * Pull one complete SQL statement (ending in `;`) from $buf, respecting
     * quotes. Returns null when incomplete (and $may_need_more is true).
     * Returns false when the buffer exceeds 8MB while $fail_closed_oversized
     * is set (binary-column / unparsed CREATE context) — callers must refuse.
     *
     * @param string $buf
     * @param bool   $may_need_more
     * @param bool   $fail_closed_oversized
     * @return string|null|false
     */
    private static function sql_buffer_shift_statement(&$buf, $may_need_more, $fail_closed_oversized = false)
    {
        $len = strlen($buf);
        if ($len === 0) {
            return null;
        }
        $in_single = false;
        $in_double = false;
        $in_backtick = false;
        $escaped = false;
        for ($i = 0; $i < $len; $i++) {
            $ch = $buf[$i];
            if ($in_single) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($ch === "'") {
                    $in_single = false;
                }
                continue;
            }
            if ($in_double) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($ch === '"') {
                    $in_double = false;
                }
                continue;
            }
            if ($in_backtick) {
                if ($ch === '`') {
                    $in_backtick = false;
                }
                continue;
            }
            if ($ch === "'") {
                $in_single = true;
                continue;
            }
            if ($ch === '"') {
                $in_double = true;
                continue;
            }
            if ($ch === '`') {
                $in_backtick = true;
                continue;
            }
            if ($ch === ';') {
                $stmt = substr($buf, 0, $i + 1);
                $buf = substr($buf, $i + 1);
                return $stmt;
            }
        }
        if (!$may_need_more) {
            $stmt = $buf;
            $buf = '';
            return $stmt === '' ? null : $stmt;
        }
        // Bound memory: fail closed for binary-column tables; otherwise keep a window.
        if ($len > 8 * 1024 * 1024) {
            if ($fail_closed_oversized) {
                return false;
            }
            $buf = substr($buf, -1024 * 1024);
        }
        return null;
    }

    /**
     * @param string $create_sql
     * @return array{0: array<int, int>, 1: array<string, int>, 2: bool} indexes, name map, parsed_ok
     */
    public static function binary_columns_from_create_table($create_sql)
    {
        $indexes = array();
        $names = array();
        if (!preg_match('/CREATE\s+TABLE\b[^;(]*\((.*)\)\s*(?:ENGINE|TYPE|DEFAULT|COLLATE|COMMENT|AUTO_INCREMENT|\/\*|;)/is', $create_sql, $m)) {
            // Fallback: outermost parens after table name.
            if (!preg_match('/CREATE\s+TABLE\b[^;(]*\((.*)\)\s*;/is', $create_sql, $m)) {
                return array($indexes, $names, false);
            }
        }
        $cols = self::split_sql_column_defs($m[1]);
        $col_i = 0;
        foreach ($cols as $def) {
            $def = trim($def);
            if ($def === '' || preg_match('/^(?:PRIMARY\s+KEY|UNIQUE|KEY|INDEX|CONSTRAINT|FULLTEXT|SPATIAL|FOREIGN\s+KEY)\b/i', $def)) {
                continue;
            }
            if (!preg_match('/^`([^`]+)`\s+(.+)$/s', $def, $cm) && !preg_match('/^([^\s]+)\s+(.+)$/s', $def, $cm)) {
                $col_i++;
                continue;
            }
            $name = strtolower($cm[1]);
            $type = strtolower($cm[2]);
            // Only the column type token — not COMMENT/'blob' text elsewhere.
            if (self::is_binary_column_type($type)) {
                $indexes[] = $col_i;
                $names[$name] = $col_i;
            }
            $col_i++;
        }
        return array($indexes, $names, true);
    }

    /**
     * Split CREATE TABLE column list on commas at parentheses depth 0.
     *
     * @param string $body
     * @return array<int, string>
     */
    private static function split_sql_column_defs($body)
    {
        $parts = array();
        $depth = 0;
        $current = '';
        $in_single = false;
        $escaped = false;
        $len = strlen($body);
        for ($i = 0; $i < $len; $i++) {
            $ch = $body[$i];
            if ($in_single) {
                $current .= $ch;
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($ch === "'") {
                    $in_single = false;
                }
                continue;
            }
            if ($ch === "'") {
                $in_single = true;
                $current .= $ch;
                continue;
            }
            if ($ch === '(') {
                $depth++;
                $current .= $ch;
                continue;
            }
            if ($ch === ')') {
                $depth = max(0, $depth - 1);
                $current .= $ch;
                continue;
            }
            if ($ch === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        if (trim($current) !== '') {
            $parts[] = $current;
        }
        return $parts;
    }

    /**
     * @param string             $insert_sql
     * @param array<int, int>    $binary_indexes
     * @param array<string, int> $binary_names
     * @return bool
     */
    public static function insert_has_unsafe_binary_values($insert_sql, $binary_indexes, $binary_names)
    {
        $column_order = null;
        if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?[^`\s(]+`?\s*\(([^)]+)\)\s*VALUES/is', $insert_sql, $m)) {
            $column_order = array();
            foreach (preg_split('/\s*,\s*/', $m[1]) as $col) {
                $column_order[] = strtolower(trim($col, " \t\n\r\0\x0B`"));
            }
        }

        if (!preg_match('/\bVALUES\s*(.*)$/is', $insert_sql, $vm)) {
            return false;
        }
        $values_blob = rtrim($vm[1], " \t\n\r;");
        $rows = self::split_sql_value_tuples($values_blob);
        foreach ($rows as $row_vals) {
            $check_indexes = $binary_indexes;
            if (is_array($column_order)) {
                $check_indexes = array();
                foreach ($column_order as $pos => $name) {
                    if (isset($binary_names[$name])) {
                        $check_indexes[] = $pos;
                    }
                }
            }
            foreach ($check_indexes as $idx) {
                if (!isset($row_vals[$idx])) {
                    continue;
                }
                if (self::sql_literal_is_unsafe_binary_value($row_vals[$idx])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Split `(...), (...)` tuples at depth 0.
     *
     * @param string $values_blob
     * @return array<int, array<int, string>>
     */
    private static function split_sql_value_tuples($values_blob)
    {
        $rows = array();
        $len = strlen($values_blob);
        $i = 0;
        while ($i < $len) {
            while ($i < $len && ($values_blob[$i] === ',' || ctype_space($values_blob[$i]))) {
                $i++;
            }
            if ($i >= $len) {
                break;
            }
            if ($values_blob[$i] !== '(') {
                $i++;
                continue;
            }
            $i++; // past (
            $depth = 1;
            $current = '';
            $vals = array();
            $in_single = false;
            $escaped = false;
            for (; $i < $len; $i++) {
                $ch = $values_blob[$i];
                if ($in_single) {
                    $current .= $ch;
                    if ($escaped) {
                        $escaped = false;
                        continue;
                    }
                    if ($ch === '\\') {
                        $escaped = true;
                        continue;
                    }
                    if ($ch === "'") {
                        $in_single = false;
                    }
                    continue;
                }
                if ($ch === "'") {
                    $in_single = true;
                    $current .= $ch;
                    continue;
                }
                if ($ch === '(') {
                    $depth++;
                    $current .= $ch;
                    continue;
                }
                if ($ch === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $vals[] = trim($current);
                        $i++;
                        break;
                    }
                    $current .= $ch;
                    continue;
                }
                if ($ch === ',' && $depth === 1) {
                    $vals[] = trim($current);
                    $current = '';
                    continue;
                }
                $current .= $ch;
            }
            if ($vals) {
                $rows[] = $vals;
            }
        }
        return $rows;
    }

    /**
     * A binary-column SQL literal is unsafe when it is a quoted string whose
     * unescaped payload is not valid UTF-8 — the 3.1.18 PHP exporter shape
     * for raw binary (e.g. Wordfence `\x00\x01\x1B…`). Hex literals (`0x…`),
     * bit-string literals (`b'…'`), and valid UTF-8 text (including NULs) in
     * BLOB columns are safe.
     *
     * @param string $literal
     * @return bool
     */
    public static function sql_literal_is_unsafe_binary_value($literal)
    {
        $literal = trim((string) $literal);
        if ($literal === '' || strcasecmp($literal, 'NULL') === 0) {
            return false;
        }
        // 3.1.19 hex form (and mysqldump --hex-blob).
        if (preg_match('/^0x[0-9a-fA-F]*$/i', $literal)) {
            return false;
        }
        // BIT / bit-string literals from mysqldump.
        if (preg_match("/^[bB]'[01]*'$/", $literal)) {
            return false;
        }
        // MySQL _binary '...' introducer and X'ABCD' hex literals.
        if (preg_match("/^_binary\s+'/i", $literal)) {
            return false;
        }
        if (preg_match("/^[xX]'[0-9a-fA-F]*'$/", $literal)) {
            return false;
        }
        if ($literal[0] !== "'" || substr($literal, -1) !== "'") {
            // Unquoted non-NULL binary is unexpected; treat as unsafe.
            return true;
        }
        $inner = substr($literal, 1, -1);
        $bytes = self::mysql_unescape_string_literal($inner);
        // Empty quoted string is fine.
        if ($bytes === '') {
            return false;
        }
        // Valid UTF-8 (NUL bytes allowed) round-trips on utf8mb4; allow.
        // Invalid UTF-8 is the rolw/wffilemods failure mode — refuse.
        if (preg_match('//u', $bytes)) {
            return false;
        }
        return true;
    }

    /**
     * Reverse the escapes used by the pre-3.1.19 PHP exporter:
     * `\\`, `\'`, `\0`, `\n`, `\r`, `\Z` (and leave other `\x` as `x`).
     *
     * @param string $inner Bytes between the quotes.
     * @return string
     */
    public static function mysql_unescape_string_literal($inner)
    {
        $out = '';
        $len = strlen($inner);
        for ($i = 0; $i < $len; $i++) {
            if ($inner[$i] !== '\\' || $i + 1 >= $len) {
                $out .= $inner[$i];
                continue;
            }
            $next = $inner[$i + 1];
            $i++;
            switch ($next) {
                case '0':
                    $out .= "\0";
                    break;
                case 'n':
                    $out .= "\n";
                    break;
                case 'r':
                    $out .= "\r";
                    break;
                case 'Z':
                    $out .= "\x1a";
                    break;
                case '\\':
                    $out .= '\\';
                    break;
                case "'":
                    $out .= "'";
                    break;
                case '"':
                    $out .= '"';
                    break;
                default:
                    // MySQL: unknown escape → the character itself.
                    $out .= $next;
                    break;
            }
        }
        return $out;
    }

    /**
     * Build a 3.1.18-style quoted SQL literal (same escape order as export_table).
     * Exposed for tests.
     *
     * @param string $value
     * @return string Including surrounding quotes.
     */
    public static function php_exporter_quote_318($value)
    {
        $search = array("\x00", "\x0a", "\x0d", "\x1a");
        $replace = array('\0', '\n', '\r', '\Z');
        return "'" . str_replace($search, $replace, str_replace('\'', '\\\'', str_replace('\\', '\\\\', $value))) . "'";
    }

    /**
     * @param string $type Lowercased MySQL column type.
     * @return bool
     */
    public static function is_bit_column_type($type)
    {
        $type = strtolower(trim((string) $type));
        return (bool) preg_match('/^bit\b/', $type);
    }

    /**
     * Emit a BIT literal for values forced to decimal via ``col+0`` in the
     * PHP exporter SELECT. Unquoted integers round-trip; quoted strings do not.
     *
     * @param mixed $value
     * @return string
     */
    public static function sql_bit_literal($value)
    {
        if (null === $value) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) (int) $value;
        }
        if (!is_string($value)) {
            $value = (string) $value;
        }
        if ($value === '') {
            return '0';
        }
        // Already a bit-string literal from some drivers / tooling.
        if (preg_match("/^b'[01]+'$/i", $value)) {
            return $value;
        }
        // Decimal text from ``col+0`` (or a numeric driver).
        if (ctype_digit($value)) {
            return $value;
        }
        // Unexpected raw bytes — hex literal (should not occur when SELECT uses +0).
        return '0x' . bin2hex($value);
    }

    /**
     * @param string $type Lowercased MySQL column type.
     * @return bool
     */
    public static function is_binary_column_type($type)
    {
        $type = strtolower(trim((string) $type));
        if ($type === '') {
            return false;
        }
        // Match BINARY/VARBINARY and *BLOB, but not "bigint" etc.
        // BIT is excluded: mysqldump emits b'…' / 0x… which are safe, and
        // treating BIT as binary caused false refusals on bit columns.
        if (preg_match('/^(?:var)?binary\b/', $type)) {
            return true;
        }
        if (preg_match('/^(?:tiny|medium|long)?blob\b/', $type)) {
            return true;
        }
        return false;
    }

    /**
     * Emit a MySQL hex literal that round-trips arbitrary bytes.
     *
     * @param mixed $value
     * @return string
     */
    public static function sql_binary_literal($value)
    {
        if (null === $value) {
            return 'NULL';
        }
        if (!is_string($value)) {
            $value = (string) $value;
        }
        if ($value === '') {
            return "''";
        }
        return '0x' . bin2hex($value);
    }

    /**
     * Case-insensitive list of `*.sql` dumps under a restore DB directory.
     * Shared by binary preflight and import so `.Sql` / `.SQL` are never missed.
     *
     * @param string $dbfiles_dir
     * @return array<int, string>
     */
    public static function list_restore_sql_dump_files($dbfiles_dir)
    {
        $dbfiles_dir = (string) $dbfiles_dir;
        if ($dbfiles_dir === '' || !is_dir($dbfiles_dir)) {
            return array();
        }
        $files = array();
        $dir = @scandir($dbfiles_dir);
        if (!is_array($dir)) {
            return array();
        }
        foreach ($dir as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (strtolower(substr($name, -4)) !== '.sql') {
                continue;
            }
            $path = rtrim($dbfiles_dir, '/\\') . '/' . $name;
            if (is_file($path)) {
                $files[] = $path;
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Before dump/import, compare estimated DB size to memory_limit.
     * Always forces the streamed path; warns when an in-memory dump would OOM.
     *
     * @param int    $db_bytes Estimated database (or SQL dump) size in bytes.
     * @param string $context  'dump' or 'import'
     * @return array{force_stream:bool,exceeds:bool,memory_bytes:int,db_bytes:int}
     */
    public static function preflight_db_memory($db_bytes, $context = 'dump')
    {
        $db_bytes = max(0, (int) $db_bytes);
        $memory_limit = ini_get('memory_limit');
        $memory_bytes = self::convert_to_bytes($memory_limit);
        $usage = memory_get_usage(true);
        $unlimited = ($memory_bytes <= 0);
        $available = $unlimited ? PHP_INT_MAX : max(0, $memory_bytes - $usage);
        // INSERT syntax inflates dumps vs InnoDB data_length; ~1.5x is conservative.
        $estimated_dump = (int) ($db_bytes * 1.5);
        $exceeds = !$unlimited && ($db_bytes >= $memory_bytes || $estimated_dump >= $available);

        One_Click_Migration::write_to_log(sprintf(
            'SYSLOG: DB memory preflight (%s): estimated DB %s, memory_limit %s, used %s, available %s%s',
            $context,
            self::format_bytes($db_bytes),
            $unlimited ? 'unlimited' : self::format_bytes($memory_bytes),
            self::format_bytes($usage),
            $unlimited ? 'unlimited' : self::format_bytes($available),
            $exceeds ? ' — forcing streamed dump/import (in-memory dump would exceed memory_limit)' : ' — using streamed dump/import'
        ));

        if ($exceeds) {
            $notice = ($context === 'import')
                ? 'Notice: Database is larger than PHP memory_limit; streaming import from disk.'
                : 'Notice: Database is larger than PHP memory_limit; streaming dump to disk.';
            One_Click_Migration::write_to_log(sprintf(
                '%s Estimated DB %s vs memory_limit %s.',
                $notice,
                self::format_bytes($db_bytes),
                $unlimited ? 'unlimited' : self::format_bytes($memory_bytes)
            ));
        }

        return array(
            'force_stream' => true,
            'exceeds' => $exceeds,
            'memory_bytes' => $memory_bytes,
            'db_bytes' => $db_bytes,
        );
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
            if ($memory_bytes <= 0) {
                $available_memory = PHP_INT_MAX;
            } else {
                $available_memory = min($memory_bytes - $usage_memory, $memory_bytes * 0.20); // Use max 20% of total memory
            }
            
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
            
            if ($available_memory < 128 * 1024 * 1024) {
                One_Click_Migration::write_to_log(" SYSLOG: Table {$table} - Calculated increment: {$increment} (low memory)");
            }
                
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
        $val = trim((string) $val);
        if ($val === '' || $val === '-1') {
            return 0; // unlimited / unset
        }
        $last = strtolower($val[strlen($val)-1]);
        $bytes = (int) $val;

        switch($last) {
            case 'g': $bytes *= 1024;
            case 'm': $bytes *= 1024;
            case 'k': $bytes *= 1024;
        }

        return $bytes;
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
