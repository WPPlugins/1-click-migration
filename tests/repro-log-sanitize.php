<?php
/**
 * Repro + regression test for the 2026-08-27 field incident:
 * a host-quota fwrite failure (errno=122 EDQUOT) during uploads.zip encryption
 * made the plugin dump raw ciphertext into ocm_debug.log. The binary blob made
 * json_encode() fail downstream (Lightsail get_logs.php returned HTTP 200 with
 * an empty body), so field diagnosis went blind.
 *
 * Covers:
 *  1. encryptZipFile() failure path logs errno/path/size only — never payload.
 *  2. last_fs_error_summary() distinguishes EDQUOT (122) from ENOSPC (28).
 *  3. write_to_log() sanitizes/truncates binary input; the resulting log file
 *     is always json_encode()-able.
 *
 * Run from the plugin root (requires `composer install` for vendor/):
 *     php tests/repro-log-sanitize.php
 */

// ---------------------------------------------------------------------------
// Minimal WordPress stubs so the real plugin bootstrap loads under CLI.
// Deliberately in the GLOBAL namespace: the plugin code (namespace OCM) falls
// back to global functions, exactly like it does inside WordPress.
// ---------------------------------------------------------------------------

error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$GLOBALS['ocm_test_root'] = sys_get_temp_dir() . '/ocm-test-' . getmypid();
@mkdir($GLOBALS['ocm_test_root'] . '/plugin', 0777, true);
@mkdir($GLOBALS['ocm_test_root'] . '/content', 0777, true);

define('WPINC', 'wp-includes');
define('WP_CONTENT_DIR', $GLOBALS['ocm_test_root'] . '/content');
define('ABSPATH', $GLOBALS['ocm_test_root'] . '/');

function plugin_dir_path($file) { return $GLOBALS['ocm_test_root'] . '/plugin/'; }
function plugins_url($path = '', $plugin = '') { return 'http://example.test/wp-content/plugins/1-click-migration/' . ltrim($path, '/'); }
function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
function add_action($hook, $cb = null, $prio = 10, $args = 1) {}
function add_filter($hook, $cb = null, $prio = 10, $args = 1) {}
function register_activation_hook($file, $cb) {}
function register_deactivation_hook($file, $cb) {}
function admin_url($path = '') { return 'http://example.test/wp-admin/' . $path; }
function __($text, $domain = 'default') { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
function get_option($name, $default = false) { return $default; }
function update_option($name, $value, $autoload = null) { return true; }
function delete_option($name) { return true; }
function download_url($url, $timeout = 300) { return ''; } // avoids the wp-admin/includes/file.php require

chdir(__DIR__ . '/..');
if (!is_file('vendor/autoload.php')) {
    fwrite(STDERR, "SKIP: vendor/autoload.php missing — run `composer install` in the plugin root first.\n");
    exit(2);
}
require 'one-click-migration.php';

// ---------------------------------------------------------------------------
// Stream wrapper that accepts only N bytes and then fails writes the way a
// host over its disk quota does (fwrite warning with errno=122 EDQUOT).
// ---------------------------------------------------------------------------
class OCM_Failing_Stream_Wrapper
{
    public static $files = [];
    public static $fail_write_after = 0;
    public $context;
    private $path;
    private $pos = 0;
    private $written = 0;

    public function stream_open($path, $mode, $options, &$opened_path)
    {
        $this->path = $path;
        if (0 === strpos($mode, 'r') && !isset(self::$files[$path])) {
            return false;
        }
        if (0 === strpos($mode, 'w')) {
            self::$files[$path] = '';
        }
        $this->pos = 0;
        return true;
    }

    public function stream_read($count)
    {
        $data = substr(self::$files[$this->path], $this->pos, $count);
        $this->pos += strlen($data);
        return $data;
    }

    public function stream_write($data)
    {
        $allowed = max(0, self::$fail_write_after - $this->written);
        $take = min($allowed, strlen($data));
        self::$files[$this->path] .= substr($data, 0, $take);
        $this->written += $take;
        if ($take < strlen($data)) {
            // What PHP itself raises on a quota hit; @ still fills error_get_last().
            @trigger_error('fwrite(): Write of ' . strlen($data) . ' bytes failed with errno=122 Disk quota exceeded', E_USER_WARNING);
        }
        return $take;
    }

    public function stream_eof() { return $this->pos >= strlen(self::$files[$this->path]); }
    public function stream_stat() { return ['size' => strlen(self::$files[$this->path])]; }
    public function stream_close() {}
    public function url_stat($path, $flags)
    {
        return isset(self::$files[$path]) ? ['size' => strlen(self::$files[$path])] : false;
    }
}
stream_wrapper_register('ocmtest', OCM_Failing_Stream_Wrapper::class);

// ---------------------------------------------------------------------------

$failures = 0;
function check($label, $ok) {
    global $failures;
    if ($ok) {
        echo "  ok  - $label\n";
    } else {
        $failures++;
        echo "  FAIL - $label\n";
    }
}

$log_file = OCM_DEBUG_LOG_FILE;
@unlink($log_file);

// --- 1. encryptZipFile: quota failure mid-write logs a short line only ------
echo "encryptZipFile quota failure path:\n";

$plaintext_marker = 'OCM_SECRET_PLAINTEXT_MARKER_do_not_leak';
$source = 'ocmtest://work/uploads.zip';
OCM_Failing_Stream_Wrapper::$files[$source] = str_repeat('A', 65536) . $plaintext_marker;
OCM_Failing_Stream_Wrapper::$fail_write_after = 10; // quota hits after 10 bytes

$result = \OCM\OCM_Backup::encryptZipFile($source, str_repeat('k', 32));
$log = (string) @file_get_contents($log_file);
$lines = array_values(array_filter(explode(PHP_EOL, trim($log))));
$last = $lines ? end($lines) : '';

check('returns false', false === $result);
check('a log line was written', '' !== $last);
check('log line names the failed write path', false !== strpos($last, 'Error: Failed to write encrypted data to'));
check('log line carries errno=122 EDQUOT', false !== strpos($last, 'errno=122 EDQUOT'));
check('log line says quota, distinct from disk full', false !== strpos($last, 'quota') && false !== strpos($last, 'not the same as disk full'));
check('log line is short (< 2500 bytes)', strlen($last) < 2500);
check('log contains no plaintext payload', false === strpos($log, $plaintext_marker));
check('log contains no ciphertext (valid UTF-8 throughout)', 1 === preg_match('//u', $log));
check('log file is json_encode()-able', false !== json_encode(['log' => $log]));

// --- 2. last_fs_error_summary: EDQUOT vs ENOSPC ------------------------------
echo "last_fs_error_summary errno classification:\n";

@trigger_error('fwrite(): Write of 2097152 bytes failed with errno=122 Disk quota exceeded', E_USER_WARNING);
$quota = \OCM\OCM_Backup::last_fs_error_summary();
@trigger_error('fwrite(): Write of 2097152 bytes failed with errno=28 No space left on device', E_USER_WARNING);
$enospc = \OCM\OCM_Backup::last_fs_error_summary();

check('errno=122 maps to EDQUOT/quota', false !== strpos($quota, 'errno=122 EDQUOT'));
check('errno=28 maps to ENOSPC', false !== strpos($enospc, 'errno=28 ENOSPC'));
check('the two summaries are distinct', $quota !== $enospc);

// --- 3. write_to_log: binary input can no longer poison the log -------------
echo "write_to_log binary sanitization (incident regression):\n";

@unlink($log_file);
$binary_blob = random_bytes(2 * 1024 * 1024); // what 3.1.10 dumped: a 2MB chunk
\OCM\One_Click_Migration::write_to_log('Error: Encrypted data from file "' . $binary_blob . '"');
$log = (string) @file_get_contents($log_file);

check('entry was truncated (< 2200 bytes total)', strlen($log) < 2200);
check('log is valid UTF-8', 1 === preg_match('//u', $log));
check('json_encode of the log no longer returns false', false !== json_encode(['log' => $log]));

// -----------------------------------------------------------------------------
echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n$failures TEST(S) FAILED\n";
exit($failures === 0 ? 0 : 1);
