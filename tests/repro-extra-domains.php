<?php
/**
 * Extra hosts left after the primary siteurl rewrite.
 *
 * Covers the bridles.org field case: a GoDaddy temp domain with a /~user path
 * must be offered separately from the source siteurl, CDN/embed hosts must
 * start unchecked, and the serialized-safe replace must map the path prefix
 * onto the destination origin. A table with no primary key must not abort
 * the rest of the rewrite.
 *
 *     php tests/repro-extra-domains.php
 */

namespace OCM {
    class One_Click_Migration {
        public static $logs = array();
        public static $maintenance = false;
        public static function enable_maintenance_mode() { self::$maintenance = true; }
        public static function disable_maintenance_mode() { self::$maintenance = false; }
        public static function write_to_log( $message ) {
            self::$logs[] = $message;
        }
        public static function worker_heartbeat() {}
        public static function get_timeout() {
            return 30;
        }
    }
}

namespace {
    error_reporting(E_ALL);

    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    function esc_sql($data) { return $data; }
    function absint($maybeint) { return abs((int) $maybeint); }
    function __($text, $domain = 'default') { return $text; }

    function is_serialized($data, $strict = true) {
        if (!is_string($data)) {
            return false;
        }
        $data = trim($data);
        if ('N;' === $data) {
            return true;
        }
        if (strlen($data) < 4 || ':' !== $data[1]) {
            return false;
        }
        if ($strict) {
            $lastc = substr($data, -1);
            if (';' !== $lastc && '}' !== $lastc) {
                return false;
            }
        }
        $token = $data[0];
        switch ($token) {
            case 's':
                if ($strict && '"' !== substr($data, -2, 1)) {
                    return false;
                }
            case 'a':
            case 'O':
                return (bool) preg_match("/^{$token}:[0-9]+:/s", $data);
            case 'b':
            case 'i':
            case 'd':
                return (bool) preg_match("/^{$token}:[0-9.E+-]+;$/", $data);
        }
        return false;
    }

    function is_serialized_string($data) {
        return is_string($data)
            && strlen(trim($data)) >= 4
            && 's' === trim($data)[0]
            && ':' === trim($data)[1]
            && '";' === substr(trim($data), -2);
    }

    require __DIR__ . '/../inc/db/class-ocm-extra-domains.php';
    require __DIR__ . '/../inc/db/class-ocm-db.php';

    $failures = 0;
    function check($label, $cond) {
        global $failures;
        if ($cond) {
            echo "ok  $label\n";
            return;
        }
        $failures++;
        echo "FAIL $label\n";
    }

    class OCM_Discover_Wpdb {
        public function get_col($sql) {
            return array('wp_posts');
        }
        public function get_results($sql, $mode = null) {
            if (false !== strpos($sql, 'SHOW COLUMNS')) {
                return array(
                    (object) array('Field' => 'ID', 'Type' => 'bigint(20)'),
                    (object) array('Field' => 'post_content', 'Type' => 'longtext'),
                    (object) array('Field' => 'guid', 'Type' => 'varchar(255)'),
                    (object) array('Field' => 'blobbed', 'Type' => 'longblob'),
                );
            }
            static $pages = 0;
            $pages++;
            if ($pages > 1) {
                return array();
            }
            return array(array(
                'post_content' => 'https://localladygardener.org/about https://www.bridles.org/x https://www.youtube.com/watch?v=abc https://cdn.example.com/app.js http://127.0.0.1/wp https://bucket.s3.amazonaws.com/file.jpg',
                'guid' => 'http://gator3316.temp.domains/~localner/wp-content/uploads/a.jpg',
            ));
        }
    }

    $report = OCM\OCM_Extra_Domains::discover(
        new OCM_Discover_Wpdb(),
        'https://localladygardener.org',
        'https://bridles.org'
    );
    $by_host = array();
    foreach ($report['candidates'] as $row) {
        $by_host[$row['host']] = $row;
    }

    check('source siteurl is not a candidate', !isset($by_host['localladygardener.org']));
    check('destination siteurl is not a candidate', !isset($by_host['bridles.org']));
    check('temp domain is suggested but requires selection', isset($by_host['gator3316.temp.domains']) && $by_host['gator3316.temp.domains']['class'] === 'suggest' && $by_host['gator3316.temp.domains']['checked'] === false);
    check('temp domain keeps /~user path', isset($by_host['gator3316.temp.domains']) && in_array('/~localner', $by_host['gator3316.temp.domains']['path_prefixes'], true));
    check('youtube starts unchecked', isset($by_host['youtube.com']) && $by_host['youtube.com']['class'] === 'leave_alone' && $by_host['youtube.com']['checked'] === false);
    check('s3 host starts unchecked', isset($by_host['bucket.s3.amazonaws.com']) && $by_host['bucket.s3.amazonaws.com']['class'] === 'leave_alone');
    check('loopback address is suggested', isset($by_host['127.0.0.1']) && $by_host['127.0.0.1']['class'] === 'suggest' && $by_host['127.0.0.1']['checked'] === false);
    check('unknown host is optional', isset($by_host['cdn.example.com']) && $by_host['cdn.example.com']['class'] === 'other' && $by_host['cdn.example.com']['checked'] === false);
    check('box.com is not treated as x.com', OCM\OCM_Extra_Domains::classify_host('box.com') === 'other');
    check('notlocal is not a .local host', OCM\OCM_Extra_Domains::classify_host('notlocal') === 'other');
    check('foo.local is a staging host', OCM\OCM_Extra_Domains::classify_host('foo.local') === 'suggest');

    $temp = $by_host['gator3316.temp.domains'];
    $pairs = OCM\OCM_Extra_Domains::replacement_pairs($temp, 'https://bridles.org/');
    $searches = array();
    $replace_ok = true;
    foreach ($pairs as $pair) {
        $searches[] = $pair['search'];
        $replace = $pair['replace'];
        if ($replace !== 'https://bridles.org' && !preg_match('#^https://bridles\\.org[/?\#]$#', $replace)) {
            $replace_ok = false;
        }
    }
    check('pairs replace onto the destination origin', $replace_ok);
    $prefix_at = array_search('http://gator3316.temp.domains/~localner', $searches, true);
    $bare_at = array_search('http://gator3316.temp.domains', $searches, true);
    check('path prefix is rewritten before the bare host', $prefix_at !== false && $bare_at !== false && $prefix_at < $bare_at);
    check('ipv6 loopback is not treated as a port', OCM\OCM_Extra_Domains::classify_host('::1') === 'suggest');
    check('www variant is included', in_array('https://www.gator3316.temp.domains/~localner', $searches, true));

    $ip_pairs = OCM\OCM_Extra_Domains::replacement_pairs(
        array('search_host' => '127.0.0.1', 'path_prefixes' => array()),
        'https://bridles.org'
    );
    $ip_has_www = false;
    foreach ($ip_pairs as $pair) {
        if (false !== strpos($pair['search'], 'www.')) {
            $ip_has_www = true;
        }
    }
    check('ip host has no www variant', !$ip_has_www);

    $payload = serialize(array(
        'guid' => 'http://gator3316.temp.domains/~localner/wp-content/uploads/a.jpg',
        'embed' => 'https://www.youtube.com/watch?v=abc',
    ));
    $db = new OCM\OCM_DB();
    $edited = $payload;
    foreach ($pairs as $pair) {
        $edited = $db->recursive_unserialize_replace($pair['search'], $pair['replace'], $edited, false, !empty($pair['url_boundary']));
    }
    $back = unserialize($edited);
    check('serialized guid mapped off the temp path', is_array($back) && $back['guid'] === 'https://bridles.org/wp-content/uploads/a.jpg');
    check('serialized embed left in place', is_array($back) && $back['embed'] === 'https://www.youtube.com/watch?v=abc');
    $nerve = 'http://gator3316.temp.domains/~localnerve/a';
    foreach ($pairs as $pair) {
        $nerve = $db->recursive_unserialize_replace($pair['search'], $pair['replace'], $nerve, false, !empty($pair['url_boundary']));
    }
    check('longer temp username is not clipped', $nerve === 'https://bridles.org/~localnerve/a');
    check('result is still serialized', is_serialized($edited));

    class OCM_Pk_Wpdb {
        public $queries = array();
        public function get_results($sql, $mode = null) {
            if (false !== strpos($sql, 'DESCRIBE no_pk')) {
                return array((object) array('Field' => 'id', 'Key' => '', 'Type' => 'int'));
            }
            if (false !== strpos($sql, 'DESCRIBE wp_posts')) {
                return array(
                    (object) array('Field' => 'id', 'Key' => 'PRI', 'Type' => 'bigint'),
                    (object) array('Field' => 'content', 'Key' => '', 'Type' => 'longtext'),
                );
            }
            if (false !== strpos($sql, 'FROM `wp_posts`')) {
                return array(array(
                    'id' => 1,
                    'content' => 'http://gator3316.temp.domains/~localner/wp-content/uploads/a.jpg',
                ));
            }
            return array();
        }
        public function get_var($sql) { return 1; }
        public function query($sql) {
            $this->queries[] = $sql;
            return 1;
        }
        public function flush() {}
    }

    $pkdb = new OCM_Pk_Wpdb();
    $GLOBALS['wpdb'] = $pkdb;
    $rewriter = new OCM\OCM_DB();
    $rewriter->enable_url_collection();
    $rewriter->search_replace_db(array('no_pk', 'wp_posts'), array('pairs' => $pairs));
    check('primary rewrite collects the temp host', isset($rewriter->collected_urls()['gator3316.temp.domains']));
    check('missing primary key does not stop later tables', count($pkdb->queries) === 1);
    check('later table rewrite dropped the temp path', count($pkdb->queries) === 1 && false === strpos($pkdb->queries[0], '~localner') && false !== strpos($pkdb->queries[0], 'https://bridles.org/wp-content/uploads/a.jpg'));
    $logged_skip = false;
    foreach (OCM\One_Click_Migration::$logs as $line) {
        if (false !== strpos($line, 'no primary key') && false !== strpos($line, 'no_pk')) {
            $logged_skip = true;
        }
    }
    check('missing primary key is logged', $logged_skip);


    // Exercise the real serialized replacement path, including host collisions.
    $apply = new ReflectionMethod(OCM\OCM_DB::class, 'apply_replacement_pairs');
    $apply->setAccessible(true);
    $boundary_pairs = OCM\OCM_Extra_Domains::replacement_pairs(
        array('search_host' => 'foo.local', 'path_prefixes' => array('/~user')),
        'https://new.example'
    );
    $input = array(
        'exact' => 'https://foo.local',
        'path' => 'https://foo.local/image.jpg',
        'query' => 'https://foo.local?q=1',
        'fragment' => 'https://foo.local#top',
        'longer' => 'https://foo.local.example.org/keep.jpg',
        'port' => 'https://foo.local:8080/keep.jpg',
        'userinfo' => 'https://foo.local@other.example/keep.jpg',
        'temp' => 'https://foo.local/~user',
        'temp_path' => 'https://foo.local/~user/image.jpg',
        'temp_longer' => 'https://foo.local/~username/image.jpg',
        'html' => '<a href="https://foo.local">link</a>',
    );
    $actual = unserialize($apply->invoke($rewriter, serialize($input), $boundary_pairs));
    foreach (array(
        'exact' => 'https://new.example',
        'path' => 'https://new.example/image.jpg',
        'query' => 'https://new.example?q=1',
        'fragment' => 'https://new.example#top',
        'longer' => $input['longer'],
        'port' => $input['port'],
        'userinfo' => $input['userinfo'],
        'temp' => 'https://new.example',
        'temp_path' => 'https://new.example/image.jpg',
        'temp_longer' => 'https://new.example/~username/image.jpg',
        'html' => '<a href="https://new.example">link</a>',
    ) as $key => $expected) {
        check('URL boundary: ' . $key, $actual[$key] === $expected);
    }

    $scalar = $apply->invoke($rewriter, serialize('https://foo.local/image.jpg'), $boundary_pairs);
    check('serialized scalar preserves its length and URL', unserialize($scalar) === 'https://new.example/image.jpg');
    $nested = $apply->invoke($rewriter, serialize(array('value' => serialize('https://foo.local/image.jpg'))), $boundary_pairs);
    check('nested serialized scalar stays valid', unserialize(unserialize($nested)['value']) === 'https://new.example/image.jpg');

    // Run the actual AJAX handler with a failing database, then retry successfully.
    function current_user_can($cap) { return true; }
    function check_ajax_referer($action) {}
    function wp_unslash($value) { return $value; }
    function sanitize_text_field($value) { return $value; }
    function site_url() { return 'https://bridles.org'; }
    function get_option($name, $default = false) { return $GLOBALS['test_options'][$name] ?? $default; }
    function delete_option($name) { unset($GLOBALS['test_options'][$name]); }
    class AjaxResponse extends \Exception {
        public $success;
        public $data;
        public function __construct($success, $data) {
            $this->success = $success;
            $this->data = $data;
        }
    }
    function wp_send_json_error($data, $status = null) { throw new AjaxResponse(false, $data); }
    function wp_send_json_success($data) { throw new AjaxResponse(true, $data); }
    class RetryWpdb extends OCM_Pk_Wpdb {
        public $fail = true;
        public function get_col($sql) { return array('wp_posts'); }
        public function query($sql) { return $this->fail ? false : parent::query($sql); }
    }
    require __DIR__ . '/../inc/backup/class-ocm-backup.php';
    $GLOBALS['wpdb'] = new RetryWpdb();
    $GLOBALS['test_options'] = array(OCM\OCM_Extra_Domains::OPTION => array($temp));
    $_POST = array('ids' => array($temp['id']));
    foreach (array(true, false) as $fail) {
        $GLOBALS['wpdb']->fail = $fail;
        try {
            OCM\OCM_Backup::apply_extra_domains();
            check('AJAX sends a response', false);
        } catch (AjaxResponse $response) {
            check($fail ? 'failed UPDATE reports an error' : 'retry reports success', $response->success === !$fail);
            check($fail ? 'failure retains checklist' : 'success clears checklist',
                isset($GLOBALS['test_options'][OCM\OCM_Extra_Domains::OPTION]) === $fail);
            check('maintenance is disabled after response', !OCM\One_Click_Migration::$maintenance);
        }
    }

    if ($failures) {
        fwrite(STDERR, "$failures check(s) failed\n");
        exit(1);
    }
    echo "all checks passed\n";
}
