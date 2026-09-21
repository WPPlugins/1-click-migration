<?php
/** Run with: php tests/repro-restore-precheck.php */
namespace OCM {
    class OCM_S3 {
        public static $response;
        public static function redeem_restore_code($code) { return self::$response; }
    }
}
namespace {
    require __DIR__ . '/repro-extra-domains.php';
    require __DIR__ . '/../inc/db/class-ocm-url-manifest.php';
    require __DIR__ . '/../inc/backup/class-ocm-restore-precheck.php';

    class WP_Error {
        private $message;
        public function __construct($code, $message) { $this->message = $message; }
        public function get_error_message() { return $this->message; }
    }
    function wp_verify_nonce($nonce, $action) { return true; }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    $test_user = 1;
    $transients = array();
    function get_current_user_id() { return $GLOBALS['test_user']; }
    function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; return true; }
    function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
    function delete_transient($key) { unset($GLOBALS['transients'][$key]); }
    function wp_generate_password($length, $special = false, $extra = false) { return bin2hex(random_bytes($length / 2)); }
    function wp_remote_get($url, $args) { return $GLOBALS['remote_log']; }
    function wp_remote_retrieve_response_code($response) { return $response['status']; }
    function wp_remote_retrieve_body($response) { return $response['body']; }

    function manifest_log($lines) {
        return implode("\n", array_map(function ($line) { return '09/21/2026T12:00:00pm - ' . $line; }, $lines)) . "\n";
    }

    $found = array();
    OCM\OCM_Extra_Domains::collect_from_text($found,
        'https://source.example https://gator.temp.domains/~owner/wp-content/a.jpg?private=secret https://www.youtube.com/watch?v=123');
    $lines = OCM\OCM_URL_Manifest::lines($found, 'https://source.example', 'bucket-one');
    $log = manifest_log($lines);
    $manifest = OCM\OCM_URL_Manifest::from_log($log, 'bucket-one');
    check('manifest round trip', is_array($manifest) && count($manifest['found']) === 2);
    check('manifest omits query strings', strpos(json_encode($manifest), 'private') === false);
    check('manifest bound to bucket', OCM\OCM_URL_Manifest::from_log($log, 'bucket-two') === null);
    check('legacy log has no manifest', OCM\OCM_URL_Manifest::from_log('Backup completed.', 'bucket-one') === null);
    check('truncated payload is not an empty inventory', OCM\OCM_URL_Manifest::from_log(substr($log, 0, -10), 'bucket-one') === null);
    $empty = OCM\OCM_URL_Manifest::from_log(manifest_log(OCM\OCM_URL_Manifest::lines(array(), 'https://source.example', 'bucket-one')), 'bucket-one');
    check('empty inventory differs from missing manifest', is_array($empty) && !$empty['found']);

    $many = array();
    for ($i = 0; $i < 45; $i++) {
        OCM\OCM_Extra_Domains::collect_from_text($many, 'https://host' . $i . '.temp.domains/~owner/path');
    }
    $large_lines = OCM\OCM_URL_Manifest::lines($many, 'https://source.example', 'bucket-one');
    $large = OCM\OCM_URL_Manifest::from_log(manifest_log($large_lines), 'bucket-one');
    check('chunked manifest preserves inventory', count($large_lines) > 1 && count($large['found']) === 40 && $large['omitted'] === 5);
    check('manifest records fit the log sanitizer', max(array_map('strlen', $large_lines)) < 2000);
    array_pop($large_lines);
    check('missing chunk rejects inventory', OCM\OCM_URL_Manifest::from_log(manifest_log($large_lines), 'bucket-one') === null);

    $dump = tempnam(sys_get_temp_dir(), 'ocm-url-dump-');
    // Exercise every scheme split around the 64 KiB boundary.
    for ($split = 1; $split <= 25; $split++) {
        file_put_contents($dump, str_repeat('x', 65536 - $split) . 'https://split.temp.domains/~owner/a.jpg' . "'\n");
        $seen = array();
        $ok = OCM\OCM_URL_Manifest::collect_dump($dump, function ($text) use (&$seen) {
            OCM\OCM_Extra_Domains::collect_from_text($seen, $text);
        });
        check('streamed dump boundary ' . $split, $ok && isset($seen['split.temp.domains']) && $seen['split.temp.domains']['count'] === 1);
    }
    unlink($dump);

    OCM\OCM_S3::$response = (object) array(
        '_status' => 200, 'bucket_id' => 'bucket-one', 'aes_key_b64' => 'key',
        'urls' => (object) array('log_download' => 'https://storage.example/log'),
    );
    $remote_log = array('status' => 200, 'body' => $log);
    $GLOBALS['test_options'] = array();
    $_POST = array('restore_code' => 'ABCD-EFGH', 'selected' => '');
    try {
        OCM\OCM_Restore_Precheck::prepare();
    } catch (AjaxResponse $response) {
        check('preparation succeeds without starting restore', $response->success && empty($GLOBALS['test_options']));
        $prepared = $response->data;
    }
    check('all choices start unchecked', array_sum(array_column($prepared['candidates'], 'checked')) === 0);
    $host_rows = array_column($prepared['candidates'], null, 'host');
    check('temporary URL preview removes username', $host_rows['gator.temp.domains']['preview'] === 'https://bridles.org/wp-content/a.jpg');
    $id = $host_rows['gator.temp.domains']['id'];
    $token = $prepared['token'];
    check('code changes reject selection', is_wp_error(OCM\OCM_Restore_Precheck::consume($token, 'DIFFERENT', '', array($id))));
    check('exclusion changes reject selection', is_wp_error(OCM\OCM_Restore_Precheck::consume($token, 'ABCDEFGH', 'db', array($id))));
    check('unknown domains cannot be injected', is_wp_error(OCM\OCM_Restore_Precheck::consume($token, 'ABCDEFGH', '', array('attacker'))));
    $test_user = 2;
    check('another administrator cannot use token', is_wp_error(OCM\OCM_Restore_Precheck::consume($token, 'ABCDEFGH', '', array($id))));
    $test_user = 1;
    $plan = OCM\OCM_Restore_Precheck::consume($token, 'ABCDEFGH', '', array($id));
    check('only selected candidate enters restore plan', count($plan['selected']) === 1 && $plan['selected'][0]['id'] === $id);
    check('token cannot be replayed', is_wp_error(OCM\OCM_Restore_Precheck::consume($token, 'ABCDEFGH', '', array($id))));
    $args = OCM\OCM_Restore_Precheck::replacement_args($plan, 'https://source.example', site_url());
    $normalize = new ReflectionMethod(OCM\OCM_DB::class, 'replacement_pairs_from_args');
    $normalize->setAccessible(true);
    $actual = $apply->invoke($rewriter, serialize(array(
        'primary' => 'https://source.example/post',
        'extra' => 'https://gator.temp.domains/~owner/wp-content/a.jpg',
        'external' => 'https://www.youtube.com/watch?v=123',
    )), $normalize->invoke($rewriter, $args));
    $actual = unserialize($actual);
    check('one rewrite updates primary and approved extra address', $actual['primary'] === 'https://bridles.org/post' && $actual['extra'] === 'https://bridles.org/wp-content/a.jpg');
    check('unchecked external address unchanged', $actual['external'] === 'https://www.youtube.com/watch?v=123');

    foreach (array('legacy', 'no_domains', 'excluded_db', 'network_failure') as $scenario) {
        $_POST = array('restore_code' => 'ABCDEFGH', 'selected' => $scenario === 'excluded_db' ? 'db' : '');
        $remote_log = array('status' => 200, 'body' => $scenario === 'no_domains'
            ? manifest_log(OCM\OCM_URL_Manifest::lines(array(), 'https://source.example', 'bucket-one')) : 'Old backup log');
        if ($scenario === 'network_failure') $remote_log = new WP_Error('network', 'offline');
        try {
            OCM\OCM_Restore_Precheck::prepare();
        } catch (AjaxResponse $response) {
            if ($scenario === 'network_failure') {
                check('download failure does not silently become empty inventory', !$response->success);
            } else {
                check($scenario . ' has no extra choices', $response->success && !$response->data['candidates']);
                check($scenario . ' fallback state correct', $response->data['legacy'] === ($scenario === 'legacy'));
            }
        }
    }

    $_GET = array('_wpnonce' => 'nonce', 'restore_code' => 'ABCDEFGH', 'selected' => '');
    $_POST = array('precheck_token' => 'invalid', 'domain_ids' => array());
    try {
        OCM\OCM_Backup::start_restore();
    } catch (AjaxResponse $response) {
        check('restore rejects missing preparation before changing the site', !$response->success && empty($GLOBALS['test_options']));
    }

    if ($failures) exit(1);
    echo "restore precheck checks passed\n";
}
