<?php
/**
 * Repro + regression test for the 2026-08/09 field incident (backup
 * 529b1087648aae6ee0c6d4bb0d0aa5b4): restoring a wp_ backup onto an eol_
 * destination failed with
 *
 *   Error: DB Restore. Table: ".../ocm_restore/db/wp_options.sql"
 *   error_str: "Table 'eyesonlips_wp.eol_options' doesn't exist"
 *
 * because the executed statement stream mixed prefixes: CREATE TABLE ran
 * against the source prefix (wp_options) while INSERTs targeted the
 * destination prefix (eol_options), which was never created. The prefix
 * remap was a one-shot, unverified whole-file str_replace and nothing
 * enforced that CREATE and INSERT agree on the destination prefix.
 *
 * Covers (3.1.14):
 *  1. remap_statement_table_prefix() rewrites statement heads only
 *     (DROP/CREATE/INSERT/REPLACE/ALTER/LOCK/TRUNCATE), never VALUES data.
 *  2. import_use_wpdb() executes CREATE TABLE and INSERT against the same
 *     destination prefix even when fed the exact mixed-prefix dump from the
 *     incident, and logs one Notice with the remap count.
 *  3. swap_table_prefix() returns success/failure and leaves the file with
 *     consistent destination-prefix statements.
 *  4. Same-prefix (wp_ -> wp_) restores leave the dump byte-identical.
 *  5. enforce_statement_table_prefix() repairs straggler source-prefix
 *     statement heads (including on lines longer than the 4 KiB head window)
 *     without touching INSERT payload data.
 *
 * Standalone — requires only PHP CLI, no WordPress and no vendor/:
 *     php tests/repro-prefix-remap.php
 */

namespace OCM {
    // Minimal stub replacing the real bootstrap: the import code only calls
    // One_Click_Migration::write_to_log() on the paths under test.
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

    class OCM_Short_Write_Stream
    {
        public static $data = '';
        public static $fail_after = PHP_INT_MAX;

        public function stream_open($path, $mode, $options, &$opened_path)
        {
            self::$data = '';
            return true;
        }

        public function stream_write($data)
        {
            $remaining = self::$fail_after - strlen(self::$data);
            if ($remaining <= 0) {
                return 0;
            }
            $written = min(3, $remaining, strlen($data));
            self::$data .= substr($data, 0, $written);
            return $written;
        }

        public function stream_eof()
        {
            return true;
        }

        public function stream_stat()
        {
            return array();
        }
    }
    stream_wrapper_register('ocmshort', OCM_Short_Write_Stream::class);

    // Fake $wpdb: records every executed query, never errors.
    $GLOBALS['wpdb'] = new class {
        public $base_prefix = 'eol_';
        public $prefix = 'eol_';
        public $last_error = '';
        public $check_current_query = true;
        public $queries = array();

        public function query($q)
        {
            $this->queries[] = $q;
            return true;
        }
    };
    $GLOBALS['EZSQL_ERROR'] = array();

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

    $work = sys_get_temp_dir() . '/ocm-prefix-test-' . getmypid();
    @mkdir($work . '/db', 0777, true);

    // --- 1. Statement-head remap: heads rewritten, data never touched --------
    echo "remap_statement_table_prefix statement heads:\n";

    check(
        'CREATE TABLE `wp_options` -> `eol_options`',
        OCM_SQL_Utils::remap_statement_table_prefix("CREATE TABLE `wp_options` (\n  `option_id` bigint(20)\n)", 'wp_', 'eol_')
            === "CREATE TABLE `eol_options` (\n  `option_id` bigint(20)\n)"
    );
    check(
        'DROP TABLE IF EXISTS wp_options (no backticks)',
        OCM_SQL_Utils::remap_statement_table_prefix('DROP TABLE IF EXISTS wp_options', 'wp_', 'eol_')
            === 'DROP TABLE IF EXISTS eol_options'
    );
    check(
        'INSERT INTO wp_options VALUES -> eol_options',
        OCM_SQL_Utils::remap_statement_table_prefix("INSERT INTO wp_options VALUES (1, 'a')", 'wp_', 'eol_')
            === "INSERT INTO eol_options VALUES (1, 'a')"
    );
    check(
        'mysqldump conditional ALTER TABLE ... DISABLE KEYS',
        OCM_SQL_Utils::remap_statement_table_prefix('/*!40000 ALTER TABLE `wp_options` DISABLE KEYS */', 'wp_', 'eol_')
            === '/*!40000 ALTER TABLE `eol_options` DISABLE KEYS */'
    );
    check(
        'LOCK TABLES `wp_options` WRITE',
        OCM_SQL_Utils::remap_statement_table_prefix('LOCK TABLES `wp_options` WRITE', 'wp_', 'eol_')
            === 'LOCK TABLES `eol_options` WRITE'
    );
    check(
        'TRUNCATE TABLE wp_posts',
        OCM_SQL_Utils::remap_statement_table_prefix('TRUNCATE TABLE wp_posts', 'wp_', 'eol_')
            === 'TRUNCATE TABLE eol_posts'
    );
    $data_stmt = "INSERT INTO eol_options VALUES (1, 'stored_sql', 'INSERT INTO wp_options VALUES (9)')";
    check(
        'wp_ inside VALUES payload is never rewritten',
        OCM_SQL_Utils::remap_statement_table_prefix($data_stmt, 'wp_', 'eol_') === $data_stmt
    );
    $same = "INSERT INTO wp_options VALUES (1, 'a')";
    check(
        'same-prefix restore is a no-op',
        OCM_SQL_Utils::remap_statement_table_prefix($same, 'wp_', 'wp_') === $same
    );
    check(
        'empty prefixes are a no-op',
        OCM_SQL_Utils::remap_statement_table_prefix($same, '', '') === $same
    );

    // --- 2. Field incident repro through the real importer -------------------
    echo "import_use_wpdb on the incident's mixed-prefix wp_options.sql:\n";

    $mixed = $work . '/db/wp_options.sql';
    file_put_contents(
        $mixed,
        "# Delete any existing table wp_options\n\n" .
        "DROP TABLE IF EXISTS wp_options;\n" .
        "CREATE TABLE `wp_options` (\n" .
        "  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n" .
        "  PRIMARY KEY (`option_id`)\n" .
        ") ENGINE=InnoDB ;\n" .
        // The remapped region of the incident file: INSERTs already target eol_.
        "INSERT INTO eol_options VALUES (1, 'siteurl', 'https://eyesonlips.com', 'yes');\n" .
        // Unswapped straggler INSERT plus payload that must survive verbatim.
        "INSERT INTO wp_options VALUES (2, 'wp_user_roles', 'a:1:{s:13:\"administrator\";}', 'yes');\n"
    );

    One_Click_Migration::$log = array();
    $GLOBALS['wpdb']->queries = array();
    $ok = OCM_SQL_Utils::import_use_wpdb($mixed, 'wp_', 'eol_');
    $queries = $GLOBALS['wpdb']->queries;
    $heads_on_wp = array();
    foreach ($queries as $q) {
        if (preg_match('/^\s*(?:DROP TABLE(?: IF EXISTS)?|CREATE TABLE|INSERT INTO)\s+`?wp_/i', $q)) {
            $heads_on_wp[] = $q;
        }
    }
    $creates_eol = 0;
    $inserts_eol = 0;
    foreach ($queries as $q) {
        if (preg_match('/^\s*CREATE TABLE\s+`?eol_options`?/i', $q)) {
            $creates_eol++;
        }
        if (preg_match('/^\s*INSERT INTO\s+`?eol_options`?/i', $q)) {
            $inserts_eol++;
        }
    }

    check('import succeeds', true === $ok);
    check('4 statements executed', 4 === count($queries));
    check('no executed statement targets the source prefix', array() === $heads_on_wp);
    check('CREATE TABLE ran against eol_options', 1 === $creates_eol);
    check('both INSERTs ran against eol_options', 2 === $inserts_eol);
    check(
        "payload data ('wp_user_roles') untouched by the statement remap",
        false !== strpos(implode("\n", $queries), "'wp_user_roles'")
    );
    $notices = preg_grep('/Notice: Remapped 3 statement\(s\) from source prefix "wp_" to destination prefix "eol_"/', One_Click_Migration::$log);
    check('one Notice logs the remap count (DROP + CREATE + 1 INSERT)', 1 === count($notices));

    // --- 3. swap_table_prefix: whole-dump swap, verified, returns bool -------
    echo "swap_table_prefix wp_ -> eol_ dump:\n";

    // get_table_prefix() derives the source prefix from the usermeta dump name.
    file_put_contents($work . '/db/wp_usermeta.sql', "DROP TABLE IF EXISTS wp_usermeta;\n");
    $dump = $work . '/db/wp_posts.sql';
    file_put_contents(
        $dump,
        "DROP TABLE IF EXISTS wp_posts;\n" .
        "CREATE TABLE `wp_posts` (\n  `ID` bigint(20)\n) ENGINE=InnoDB ;\n" .
        "INSERT INTO wp_posts VALUES (1, 'wp_attached_file');\n"
    );

    check('swap_table_prefix returns true', true === OCM_SQL_Utils::swap_table_prefix($dump));
    $swapped = file_get_contents($dump);
    check('CREATE TABLE targets eol_posts', false !== strpos($swapped, 'CREATE TABLE `eol_posts`'));
    check('INSERT targets eol_posts', false !== strpos($swapped, 'INSERT INTO eol_posts VALUES'));
    check('no wp_ prefix remains anywhere in the dump', false === strpos($swapped, 'wp_'));
    check('no leftover temp files', array() === glob($work . '/db/*.tmp'));

    // --- 4. Same-prefix restore leaves the dump byte-identical ---------------
    echo "swap_table_prefix wp_ -> wp_ (same prefix):\n";

    $GLOBALS['wpdb']->base_prefix = 'wp_';
    $same_dump = $work . '/db/wp_comments.sql';
    $same_content = "DROP TABLE IF EXISTS wp_comments;\nCREATE TABLE `wp_comments` (\n  `x` int\n) ;\nINSERT INTO wp_comments VALUES (1);\n";
    file_put_contents($same_dump, $same_content);
    check('swap_table_prefix returns true', true === OCM_SQL_Utils::swap_table_prefix($same_dump));
    check('dump is byte-identical', file_get_contents($same_dump) === $same_content);
    $GLOBALS['wpdb']->base_prefix = 'eol_';

    // --- 5. enforce: straggler heads repaired, long lines + data intact ------
    echo "enforce_statement_table_prefix on a mixed dump:\n";

    $long_payload = str_repeat('x', 3 * OCM_SQL_Utils::SQL_STATEMENT_HEAD_BYTES);
    $straggler = $work . '/db/eol_options.sql';
    file_put_contents(
        $straggler,
        "DROP TABLE IF EXISTS eol_options;\n" .
        "CREATE TABLE `wp_options` (\n  `option_id` bigint(20)\n) ;\n" .
        "INSERT INTO wp_options VALUES (1, '{$long_payload}'),\n" .
        " (2, 'literal wp_options inside data');\n"
    );

    One_Click_Migration::$log = array();
    check('enforce returns true', true === OCM_SQL_Utils::enforce_statement_table_prefix($straggler, 'wp_', 'eol_'));
    $repaired = file_get_contents($straggler);
    check('straggler CREATE head remapped', false !== strpos($repaired, 'CREATE TABLE `eol_options`'));
    check('straggler INSERT head remapped (line > head window)', false !== strpos($repaired, 'INSERT INTO eol_options VALUES'));
    check('long payload preserved verbatim', false !== strpos($repaired, "'{$long_payload}'"));
    check('continuation-line data untouched', false !== strpos($repaired, "'literal wp_options inside data'"));
    check('a Notice was logged for the repair', 1 === count(preg_grep('/Notice: "eol_options.sql" still contained statements targeting source prefix "wp_"/', One_Click_Migration::$log)));

    check('enforce on an already-clean dump is a silent no-op', true === OCM_SQL_Utils::enforce_statement_table_prefix($straggler, 'wp_', 'eol_'));
    check('clean dump unchanged', file_get_contents($straggler) === $repaired);

    // --- 6. Checked writes and canonical source-prefix detection -------------
    echo "checked writes and source-prefix detection:\n";

    OCM_Short_Write_Stream::$fail_after = PHP_INT_MAX;
    $short_handle = fopen('ocmshort://success', 'wb');
    check('write_all completes repeated short writes', OCM_SQL_Utils::write_all($short_handle, 'abcdefghij'));
    check('write_all preserves every byte', OCM_Short_Write_Stream::$data === 'abcdefghij');
    fclose($short_handle);

    OCM_Short_Write_Stream::$fail_after = 5;
    $short_handle = fopen('ocmshort://failure', 'wb');
    check('write_all reports a zero/failed continuation', !OCM_SQL_Utils::write_all($short_handle, 'abcdefghij'));
    check('failed write is visibly truncated', strlen(OCM_Short_Write_Stream::$data) === 5);
    fclose($short_handle);

    if (!defined('WP_DEBUG')) {
        define('WP_DEBUG', false);
    }
    if (!defined('ARRAY_N')) {
        define('ARRAY_N', 'ARRAY_N');
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    $original_wpdb = $GLOBALS['wpdb'];
    $GLOBALS['wpdb'] = new class {
        public function get_results($query, $format = null)
        {
            if (strpos($query, 'DESCRIBE ') === 0) {
                return array((object) array('Type' => 'int(11)', 'Field' => 'id', 'Default' => null));
            }
            if (strpos($query, 'SHOW CREATE TABLE ') === 0) {
                return array(array('wp_test', 'CREATE TABLE `wp_test` (`id` int(11)) ENGINE=InnoDB'));
            }
            if (strpos($query, 'SELECT * FROM ') === 0) {
                return array();
            }
            return false;
        }
    };
    OCM_Short_Write_Stream::$fail_after = 20;
    check(
        'export_table propagates a short dump write',
        false === OCM_SQL_Utils::export_table(array('tables' => 'wp_test', 'result-file' => 'ocmshort://dump'))
    );
    $GLOBALS['wpdb'] = $original_wpdb;

    file_put_contents($work . '/db/other_usermeta.sql', "DROP TABLE IF EXISTS other_usermeta;\n");
    One_Click_Migration::$log = array();
    check(
        'ambiguous canonical usermeta dumps are rejected',
        false === OCM_SQL_Utils::get_table_prefix($work . '/db/dummy.sql')
    );
    check('ambiguity is logged', 1 === count(preg_grep('/Expected exactly one canonical/', One_Click_Migration::$log)));
    @unlink($work . '/db/other_usermeta.sql');

    // ---------------------------------------------------------------------------
    foreach (glob($work . '/db/*') as $f) {
        @unlink($f);
    }
    @rmdir($work . '/db');
    @rmdir($work);

    echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n$failures TEST(S) FAILED\n";
    exit($failures === 0 ? 0 : 1);
}
