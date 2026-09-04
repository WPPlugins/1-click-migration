<?php
/**
 * Repro + regression test for the 2026-09 field incident (backup
 * 65a0b1d5c23e782a517a47bbb7f19726, plugin 3.1.14, varescort.com):
 *
 *   Restore remapped wplt_ → wpn1_ correctly (INSERT into wpn1_options).
 *   DB Restore wplt_options.sql then failed with the Turkish WP error
 *   "Sorgu, içinde geçersiz veriler bulunduğundan işlenemedi"
 *   (= "The query could not be processed because it contains invalid data").
 *   SYSLOG: statement=4,7,18,20 type=INSERT table=wpn1_options bytes up to 155688.
 *
 * Root cause: import_use_wpdb() / execute_imported_query() ran remapped
 * statements through $wpdb->query(). wpdb::query() calls
 * strip_invalid_text_from_query() on non-ASCII SQL (charset mismatch,
 * binary/serialized option values, or PCRE failure on ~155KB INSERTs) and
 * rejects statements MySQL would accept. ActionScheduler FATALS about a
 * missing wpn1_actionscheduler_actions table were aftermath only.
 *
 * Covers (3.1.15 harden, no version bump):
 *  1. preferred_import_charset() promotes utf8/utf8mb3 → utf8mb4; leaves
 *     latin1 alone; rejects unsafe identifiers.
 *  2. is_raw_import_driver() / get_raw_import_driver() accept mysqli-shaped
 *     handles and ignore a bare $wpdb with no dbh.
 *  3. query_imported_sql() uses the raw driver; $wpdb->query() is never
 *     called — even when $wpdb->query() is wired to reject with the WP
 *     invalid-data error (the field signature).
 *  4. import_use_wpdb() remaps wplt_ → wpn1_ then executes the remapped
 *     INSERT on the raw driver, including a ~155KB payload with bytes that
 *     are not valid UTF-8 (strip_invalid_text would drop them).
 *  5. No-dbh fallback still uses $wpdb->query() (existing prefix-remap
 *     tests keep working).
 *  6. CREATE+INSERT dest-prefix remap from 3.1.14 is unchanged.
 *
 * Standalone — requires only PHP CLI, no WordPress and no vendor/:
 *     php tests/repro-options-import-wpdb-sanitize.php
 */

namespace OCM {
    class One_Click_Migration
    {
        public static $log = array();

        public static function write_to_log($message)
        {
            self::$log[] = (string) $message;
        }
    }
}

namespace {

    error_reporting(E_ALL & ~E_DEPRECATED);

    require __DIR__ . '/../inc/db/class-ocm-db-import.php';

    use OCM\OCM_SQL_Utils;
    use OCM\One_Click_Migration;

    class OCM_Fake_Mysqli
    {
        public $error = '';
        public $queries = array();
        public $fail_next = false;

        public function query($q)
        {
            $this->queries[] = $q;
            if ($this->fail_next) {
                $this->fail_next = false;
                $this->error = 'simulated driver error';
                return false;
            }
            $this->error = '';
            return true;
        }
    }

    class OCM_Strip_Invalid_Wpdb
    {
        public $base_prefix = 'wpn1_';
        public $prefix = 'wpn1_';
        public $last_error = '';
        public $check_current_query = true;
        public $charset = 'utf8';
        public $collate = 'utf8_general_ci';
        public $dbh;
        public $wpdb_queries = array();

        public function query($q)
        {
            $this->wpdb_queries[] = $q;
            // Reproduce wpdb::query() after strip_invalid_text_from_query()
            // changes the SQL (or PCRE-fails on a large INSERT).
            $this->last_error = 'WordPress database error: Could not perform query because it contains invalid data.';
            return false;
        }
    }

    $failures = 0;
    function check($label, $ok)
    {
        global $failures;
        if ($ok) {
            echo "  ok  - $label\n";
        } else {
            $failures++;
            echo "  FAIL - $label\n";
        }
    }

    // --- 1. Charset promotion ------------------------------------------------
    echo "preferred_import_charset:\n";

    $utf8_wpdb = (object) array('charset' => 'utf8');
    check('utf8 is promoted to utf8mb4', OCM_SQL_Utils::preferred_import_charset($utf8_wpdb) === 'utf8mb4');

    $utf8mb3_wpdb = (object) array('charset' => 'utf8mb3');
    check('utf8mb3 is promoted to utf8mb4', OCM_SQL_Utils::preferred_import_charset($utf8mb3_wpdb) === 'utf8mb4');

    $utf8mb4_wpdb = (object) array('charset' => 'utf8mb4');
    check('utf8mb4 is left as utf8mb4', OCM_SQL_Utils::preferred_import_charset($utf8mb4_wpdb) === 'utf8mb4');

    $latin1_wpdb = (object) array('charset' => 'latin1');
    check('latin1 is left as latin1', OCM_SQL_Utils::preferred_import_charset($latin1_wpdb) === 'latin1');

    $evil_wpdb = (object) array('charset' => 'utf8mb4; DROP TABLE wp_users');
    check('unsafe charset identifiers are ignored (fallback utf8mb4)', OCM_SQL_Utils::preferred_import_charset($evil_wpdb) === 'utf8mb4');

    // --- 2. Driver detection -------------------------------------------------
    echo "raw import driver detection:\n";

    check('null is not a raw driver', false === OCM_SQL_Utils::is_raw_import_driver(null));
    check('stdClass without query() is not a raw driver', false === OCM_SQL_Utils::is_raw_import_driver(new \stdClass()));

    $fake = new OCM_Fake_Mysqli();
    check('mysqli-shaped double is a raw driver', true === OCM_SQL_Utils::is_raw_import_driver($fake));

    $bare = new OCM_Strip_Invalid_Wpdb();
    check('wpdb with no dbh has no raw driver', null === OCM_SQL_Utils::get_raw_import_driver($bare));

    $bare->dbh = $fake;
    check('wpdb->dbh double is returned as the raw driver', $fake === OCM_SQL_Utils::get_raw_import_driver($bare));

    // --- 3. query_imported_sql prefers raw; wpdb strip is never hit ----------
    echo "query_imported_sql raw path vs strip_invalid_text:\n";

    OCM_SQL_Utils::reset_import_runtime_state();
    $wpdb = new OCM_Strip_Invalid_Wpdb();
    $dbh = new OCM_Fake_Mysqli();
    $wpdb->dbh = $dbh;

    $binary = "\x80\x81\x82" . str_repeat('x', 800);
    $insert = "INSERT INTO wpn1_options VALUES (1, 'widget_block', '{$binary}', 'yes')";
    $ok = OCM_SQL_Utils::query_imported_sql($wpdb, $insert);

    check('raw execute succeeds', true === $ok);
    check('$wpdb->last_error is cleared', $wpdb->last_error === '');
    check('$wpdb->query() was never called', array() === $wpdb->wpdb_queries);
    $non_set_names = array_values(array_filter($dbh->queries, static function ($q) {
        return stripos($q, 'SET NAMES') !== 0;
    }));
    check('raw driver received the INSERT', array($insert) === $non_set_names);
    check(
        'SET NAMES utf8mb4 was applied (utf8 + incompatible utf8_general_ci dropped)',
        isset($dbh->queries[0]) && $dbh->queries[0] === 'SET NAMES utf8mb4'
    );

    // --- 4. Field-shaped import_use_wpdb: remap + large binary INSERT --------
    echo "import_use_wpdb field repro (wplt_ → wpn1_, ~155KB binary options INSERT):\n";

    $work = sys_get_temp_dir() . '/ocm-options-import-' . getmypid();
    @mkdir($work, 0777, true);
    $dump = $work . '/wplt_options.sql';

    // ~155688 bytes matches the field SYSLOG; include bytes that are not
    // valid UTF-8 so a utf8 strip_invalid_text pass would rewrite the SQL.
    $payload = str_repeat('A', 155000) . "\x80\x81\x82\x83" . 'a:1:{s:4:"data";s:4:"' . "\x90\x91\x92\x93" . '";}';
    file_put_contents(
        $dump,
        "DROP TABLE IF EXISTS wplt_options;\n" .
        "CREATE TABLE `wplt_options` (\n" .
        "  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n" .
        "  PRIMARY KEY (`option_id`)\n" .
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n" .
        "INSERT INTO wplt_options VALUES (1, 'siteurl', 'https://varescort.com', 'yes');\n" .
        "INSERT INTO wplt_options VALUES (4, 'widget_block', '" . $payload . "', 'yes');\n"
    );

    $wpdb = new OCM_Strip_Invalid_Wpdb();
    $dbh = new OCM_Fake_Mysqli();
    $wpdb->dbh = $dbh;
    $GLOBALS['wpdb'] = $wpdb;
    $GLOBALS['EZSQL_ERROR'] = array();
    One_Click_Migration::$log = array();
    OCM_SQL_Utils::reset_import_runtime_state();

    $ok = OCM_SQL_Utils::import_use_wpdb($dump, 'wplt_', 'wpn1_');
    $executed = $dbh->queries;
    $heads_on_source = array();
    $creates_dest = 0;
    $inserts_dest = 0;
    $big_insert = '';
    foreach ($executed as $q) {
        if (preg_match('/^\s*(?:DROP TABLE(?: IF EXISTS)?|CREATE TABLE|INSERT INTO)\s+`?wplt_/i', $q)) {
            $heads_on_source[] = $q;
        }
        if (preg_match('/^\s*CREATE TABLE\s+`?wpn1_options`?/i', $q)) {
            $creates_dest++;
        }
        if (preg_match('/^\s*INSERT INTO\s+`?wpn1_options`?/i', $q)) {
            $inserts_dest++;
            if (strlen($q) > 100000) {
                $big_insert = $q;
            }
        }
    }

    check('import succeeds despite $wpdb->query always rejecting', true === $ok);
    check('$wpdb->query() was never called', array() === $wpdb->wpdb_queries);
    check('no executed statement targets the source prefix', array() === $heads_on_source);
    check('CREATE TABLE ran against wpn1_options', 1 === $creates_dest);
    check('both INSERTs ran against wpn1_options', 2 === $inserts_dest);
    check('large INSERT is at least 155000 bytes (field-sized)', strlen($big_insert) >= 155000);
    check('invalid-UTF-8 payload bytes survived remap+execute', false !== strpos($big_insert, "\x80\x81\x82\x83"));
    check('serialized payload survived verbatim', false !== strpos($big_insert, 'a:1:{s:4:"data";s:4:"' . "\x90\x91\x92\x93" . '";}'));
    $driver_logs = preg_grep('/SYSLOG: SQL import of "wplt_options.sql" using raw mysqli\/driver path/', One_Click_Migration::$log);
    check('one SYSLOG records the raw-driver path', 1 === count($driver_logs));
    $notices = preg_grep('/Notice: Remapped 4 statement\(s\) from source prefix "wplt_" to destination prefix "wpn1_"/', One_Click_Migration::$log);
    check('3.1.14 remap Notice still logs DROP+CREATE+2 INSERTs', 1 === count($notices));

    // --- 5. Fallback when no raw handle --------------------------------------
    echo "import_use_wpdb fallback without dbh:\n";

    $fallback_wpdb = new class {
        public $base_prefix = 'wpn1_';
        public $prefix = 'wpn1_';
        public $last_error = '';
        public $check_current_query = true;
        public $queries = array();

        public function query($q)
        {
            $this->queries[] = $q;
            return true;
        }
    };
    $GLOBALS['wpdb'] = $fallback_wpdb;
    One_Click_Migration::$log = array();
    $small = $work . '/wplt_users.sql';
    file_put_contents($small, "INSERT INTO wplt_users VALUES (1, 'admin');\n");
    $ok = OCM_SQL_Utils::import_use_wpdb($small, 'wplt_', 'wpn1_');
    check('fallback import succeeds', true === $ok);
    check('fallback used $wpdb->query()', 1 === count($fallback_wpdb->queries));
    check(
        'fallback still remaps the statement head',
        isset($fallback_wpdb->queries[0]) && 0 === strpos($fallback_wpdb->queries[0], 'INSERT INTO wpn1_users')
    );
    $fallback_logs = preg_grep('/\$wpdb->query fallback/', One_Click_Migration::$log);
    check('one SYSLOG records the wpdb fallback', 1 === count($fallback_logs));

    echo "looks_like_wp_invalid_data_error:\n";
    check('English WP core string matches', true === OCM_SQL_Utils::looks_like_wp_invalid_data_error('WordPress database error: Could not perform query because it contains invalid data.'));
    check('Turkish field string matches', true === OCM_SQL_Utils::looks_like_wp_invalid_data_error('Sorgu, içinde geçersiz veriler bulunduğundan işlenemedi'));
    check('unrelated MySQL error does not match', false === OCM_SQL_Utils::looks_like_wp_invalid_data_error("Table 'db.wpn1_options' doesn't exist"));

    $rejecting = new OCM_Strip_Invalid_Wpdb();
    $GLOBALS['wpdb'] = $rejecting;
    One_Click_Migration::$log = array();
    $ok = OCM_SQL_Utils::import_use_wpdb($small, 'wplt_', 'wpn1_');
    check('wpdb-only invalid-data reject fails the import', false === $ok);
    $hint = preg_grep('/Raw mysqli driver was not available/', One_Click_Migration::$log);
    check('fallback logs a strip_invalid_text hint when raw driver is missing', 1 === count($hint));

    // --- 6. Raw driver error is surfaced (not swallowed) ---------------------
    echo "raw driver errors surface as last_error:\n";

    OCM_SQL_Utils::reset_import_runtime_state();
    $wpdb = new OCM_Strip_Invalid_Wpdb();
    $dbh = new OCM_Fake_Mysqli();
    $dbh->fail_next = true;
    $wpdb->dbh = $dbh;
    $ok = OCM_SQL_Utils::query_imported_sql($wpdb, 'INSERT INTO wpn1_options VALUES (1)');
    // fail_next trips on SET NAMES first; force a second fail on the INSERT.
    if ($ok === true) {
        $dbh->fail_next = true;
        $ok = OCM_SQL_Utils::query_imported_sql($wpdb, 'INSERT INTO wpn1_options VALUES (1)');
    }
    check('driver failure returns false', false === $ok);
    check('driver error is copied onto $wpdb->last_error', $wpdb->last_error === 'simulated driver error');
    check('$wpdb->query() still not called', array() === $wpdb->wpdb_queries);

    foreach (glob($work . '/*') ?: array() as $f) {
        @unlink($f);
    }
    @rmdir($work);

    echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n$failures TEST(S) FAILED\n";
    exit($failures === 0 ? 0 : 1);
}
