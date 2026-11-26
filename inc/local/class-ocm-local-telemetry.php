<?php

namespace OCM;

/**
 * Opt-in anonymous reports about Local Backups. Off until the site owner
 * allows it. A report holds only fixed, low-detail values (see build_payload):
 * no message text, no translated text, no site address, no absolute path.
 */
class OCM_Local_Telemetry
{
    const OPTION_TELEMETRY = 'ocm_local_telemetry';
    const ENDPOINT_PATH = 'local_telemetry.php';
    const SCHEMA_VERSION = 2;
    const CONSENT_UNSET = 'unset';
    const CONSENT_OPTED_IN = 'opted_in';
    const CONSENT_DECLINED = 'declined';

    /** A report must never hold up a backup, a restore or a crashing request. */
    const SEND_TIMEOUT_SEC = 0.5;
    /** No site sends more than this many reports in an hour, whatever goes wrong on it. */
    const MAX_EVENTS_PER_HOUR = 20;
    /** The same crash (error type, file, line) is reported at most once in this long. */
    const CRASH_THROTTLE_SEC = 3600;

    /**
     * Every error code a report can carry. Nothing else is sent about a
     * failure. The server (local_telemetry_lib.php) holds the same list.
     */
    const ERROR_CODES = array(
        'cancelled',
        'skipped',
        'operation_in_progress',
        'storage_unwritable',
        'insufficient_disk_space',
        'timeout',
        'interrupted',
        'worker_stalled',
        'state_missing',
        'invalid_selection',
        'archive_error',
        'db_error',
        'db_binary_rejected',
        'error',
        'php_e_error',
        'php_e_parse',
        'php_e_core_error',
        'php_e_compile_error',
    );

    const SIZE_BUCKETS = array('0', '<10MB', '10-100MB', '100-500MB', '500MB-1GB', '1-5GB', '5GB+');
    const EVENTS = array('opt_in', 'local_backup', 'local_restore', 'local_schedule', 'local_crash', 'search_replace', 'site_cleanup');
    const STATUSES = array('enabled', 'disabled', 'completed', 'failed', 'skipped', 'cancelled');
    const FREQUENCIES = array('hourly', 'daily', 'weekly', 'monthly');

    /**
     * Read persisted opt-in telemetry state.
     *
     * @return array{consent:string,decided_at:int,site_salt:string,schedule_sig:string}
     */
    public static function get_state()
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_TELEMETRY, 'options');
        }
        $raw = get_option(self::OPTION_TELEMETRY, array());
        if (!is_array($raw)) {
            $raw = array();
        }
        $allowed = array(self::CONSENT_UNSET, self::CONSENT_OPTED_IN, self::CONSENT_DECLINED);
        $consent = isset($raw['consent']) && in_array($raw['consent'], $allowed, true)
            ? (string) $raw['consent']
            : self::CONSENT_UNSET;

        $salt = isset($raw['site_salt']) && is_string($raw['site_salt']) ? (string) $raw['site_salt'] : '';
        if (!preg_match('/^[0-9a-f]{32}$/', $salt)) {
            $salt = '';
        }
        $schedule_sig = isset($raw['schedule_sig']) && is_string($raw['schedule_sig']) ? (string) $raw['schedule_sig'] : '';
        if (!preg_match('/^[0-9a-f]{32}$/', $schedule_sig)) {
            $schedule_sig = '';
        }

        return array(
            'consent' => $consent,
            'decided_at' => isset($raw['decided_at']) ? max(0, (int) $raw['decided_at']) : 0,
            'site_salt' => $salt,
            'schedule_sig' => $schedule_sig,
        );
    }

    /**
     * Whether anonymous telemetry and crash reporting is enabled.
     *
     * @return bool
     */
    public static function is_opted_in()
    {
        $state = self::get_state();
        if ($state['consent'] !== self::CONSENT_OPTED_IN) {
            return false;
        }
        if (function_exists('apply_filters')) {
            return (bool) apply_filters('ocm_local_telemetry_enabled', true);
        }
        return true;
    }

    /**
     * Whether the one-time consent modal should be shown on Local Backups.
     *
     * @return bool
     */
    public static function should_prompt()
    {
        $state = self::get_state();
        return $state['consent'] === self::CONSENT_UNSET;
    }

    /**
     * Persist opt-in or opt-out decision cleanly. Dispatches a single opt_in
     * event when transitioning from unset/declined to opted_in.
     *
     * @param string $consent 'opted_in'|'declined'
     * @return array{consent:string,decided_at:int,site_salt:string,schedule_sig:string}
     */
    public static function set_consent($consent)
    {
        $consent = ($consent === self::CONSENT_OPTED_IN) ? self::CONSENT_OPTED_IN : self::CONSENT_DECLINED;
        $prev = self::get_state();
        $was_opted_in = ($prev['consent'] === self::CONSENT_OPTED_IN);
        $salt = $prev['site_salt'];

        // Saving the settings again with the same choice changes nothing.
        if ($consent === $prev['consent'] && ($consent === self::CONSENT_DECLINED || $salt !== '')) {
            return $prev;
        }

        if ($consent === self::CONSENT_OPTED_IN && $salt === '') {
            $salt = self::generate_site_salt();
        }

        // The schedule as it stands when reporting is turned on is the
        // baseline: only a later change to it is reported.
        $schedule_sig = $prev['schedule_sig'];
        if ($consent === self::CONSENT_OPTED_IN && !$was_opted_in) {
            $schedule_sig = self::current_schedule_signature();
        }

        $next = array(
            'consent' => $consent,
            'decided_at' => time(),
            'site_salt' => $salt,
            'schedule_sig' => $schedule_sig,
        );
        self::save_state($next);

        if ($consent === self::CONSENT_OPTED_IN && !$was_opted_in) {
            self::send_event(array(
                'event' => 'opt_in',
                'trigger' => 'manual',
                'status' => 'enabled',
            ));
        }

        return $next;
    }

    /**
     * @param array $state
     * @return void
     */
    private static function save_state(array $state)
    {
        update_option(self::OPTION_TELEMETRY, $state, false);
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_TELEMETRY, 'options');
        }
    }

    /**
     * Generate a random 32-hex-char per-install salt for HMAC site hashing.
     *
     * @return string
     */
    private static function generate_site_salt()
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return md5(uniqid((string) mt_rand(), true));
        }
    }

    /**
     * Compute a stable, non-reversible 32-hex-char install identifier.
     *
     * @return string Empty string until opted in with a salt.
     */
    public static function site_hash()
    {
        $state = self::get_state();
        $salt = $state['site_salt'];
        if ($salt === '') {
            return '';
        }
        $abspath = defined('ABSPATH') ? (string) ABSPATH : '';
        $site_url = function_exists('get_site_url') ? (string) get_site_url() : '';
        return substr(hash_hmac('sha256', $abspath . '|' . $site_url, $salt), 0, 32);
    }

    /**
     * Map exact byte size to a coarse privacy-safe bucket.
     *
     * @param int $bytes
     * @return string
     */
    public static function size_bucket($bytes)
    {
        $b = max(0, (int) $bytes);
        if ($b === 0) {
            return '0';
        }
        $mb = $b / 1048576.0;
        if ($mb < 10.0) {
            return '<10MB';
        }
        if ($mb < 100.0) {
            return '10-100MB';
        }
        if ($mb < 500.0) {
            return '100-500MB';
        }
        if ($mb < 1024.0) {
            return '500MB-1GB';
        }
        if ($mb < 5120.0) {
            return '1-5GB';
        }
        return '5GB+';
    }

    /**
     * Path of a plugin file relative to the plugin folder, e.g.
     * "inc/local/class-ocm-local-backup.php". Empty for any file outside the
     * plugin: nothing about the rest of the site is reported, and an absolute
     * path never is.
     *
     * @param string $file
     * @return string
     */
    public static function relative_plugin_file($file)
    {
        $file_str = str_replace('\\', '/', (string) $file);
        if ($file_str === '') {
            return '';
        }
        $rel = '';
        if (defined('OCM_PLUGIN_ROOT') && is_string(OCM_PLUGIN_ROOT) && OCM_PLUGIN_ROOT !== '') {
            $root = rtrim(str_replace('\\', '/', OCM_PLUGIN_ROOT), '/') . '/';
            if (strpos($file_str, $root) === 0) {
                $rel = substr($file_str, strlen($root));
            }
        }
        if ($rel === '') {
            $needle = 'plugins/1-click-migration/';
            $pos = strrpos($file_str, $needle);
            if ($pos !== false) {
                $rel = substr($file_str, $pos + strlen($needle));
            }
        }
        // Only a plain relative PHP path gets out; anything else is dropped whole.
        if ($rel === '' || strlen($rel) > 120 || strpos($rel, '..') !== false
            || !preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.\-]*(?:/[A-Za-z0-9_][A-Za-z0-9_.\-]*)*\.php$#', $rel)
        ) {
            return '';
        }
        return $rel;
    }

    /**
     * Keep an error code only when it is on the list.
     *
     * @param string $code
     * @return string '' when it is not a known code
     */
    public static function normalize_error_code($code)
    {
        $code = is_string($code) ? strtolower(trim($code)) : '';
        return in_array($code, self::ERROR_CODES, true) ? $code : '';
    }

    /**
     * Map a failed run to one of the ERROR_CODES.
     *
     * $message is matched only against the plugin's own internal failure
     * texts: fail_backup(), fail_restore(), stop_restore_no_change() and the
     * watchdog pass English literals that are never translated. A failure
     * whose text IS translated passes its code instead (the 'error_code' of
     * OCM_Local_Backup::record_run_activity()), or it would end up as the
     * generic "error" on every non-English site.
     *
     * @param string $message
     * @param string $status
     * @return string
     */
    public static function infer_error_code($message, $status = '')
    {
        if ($status === 'completed' || $status === 'enabled' || $status === 'disabled') {
            return '';
        }
        if ($status === 'cancelled') {
            return 'cancelled';
        }
        // Quoted pieces are table, file or folder names: never match on those.
        $lower = strtolower((string) preg_replace('/"[^"]*"/', '""', (string) $message));
        $rules = array(
            'insufficient_disk_space' => array('disk space'),
            'timeout' => array('timed out'),
            'worker_stalled' => array('no queued work remained'),
            'interrupted' => array('interrupted'),
            'state_missing' => array('metadata missing'),
            'invalid_selection' => array('no valid components'),
            'db_binary_rejected' => array('unsafe database binary'),
            'storage_unwritable' => array(
                'could not create temporary', 'could not write', 'could not publish', 'could not replace',
                'could not restore plugin directory', 'restore workspace', 'staging directory',
                'already exists or is invalid',
            ),
            'archive_error' => array('archive', 'manifest', 'extract'),
            'db_error' => array('database', 'sql dump', 'table', 'staging', 'updating urls', 'while importing'),
        );
        foreach ($rules as $code => $needles) {
            foreach ($needles as $needle) {
                if (strpos($lower, $needle) !== false) {
                    return $code;
                }
            }
        }
        return ($status === 'skipped') ? 'skipped' : 'error';
    }

    /**
     * PHP error type as an error code, e.g. E_ERROR => "php_e_error".
     *
     * @param int $errno
     * @return string
     */
    public static function php_error_code($errno)
    {
        $map = array(
            E_ERROR => 'php_e_error',
            E_PARSE => 'php_e_parse',
            E_CORE_ERROR => 'php_e_core_error',
            E_COMPILE_ERROR => 'php_e_compile_error',
        );
        $errno = (int) $errno;
        return isset($map[$errno]) ? $map[$errno] : 'php_e_error';
    }

    /**
     * Out-of-memory fatals are never reported: with no memory left, the report
     * itself is the likeliest thing to fail next.
     *
     * @param string $errstr
     * @return bool
     */
    public static function is_out_of_memory($errstr)
    {
        $errstr = (string) $errstr;
        return stripos($errstr, 'Allowed memory size') !== false || stripos($errstr, 'Out of memory') !== false;
    }

    /**
     * Return minor-level WordPress version (e.g. "6.7").
     *
     * @return string
     */
    private static function wp_major_minor()
    {
        global $wp_version;
        if (!isset($wp_version) || !is_string($wp_version)) {
            return '';
        }
        if (preg_match('/^\d+\.\d+/', $wp_version, $m)) {
            return $m[0];
        }
        return '';
    }

    /**
     * Resolve the remote telemetry receiver URL.
     *
     * @return string
     */
    public static function endpoint_url()
    {
        $base = defined('OCM_API_ENDPOINT') && is_string(OCM_API_ENDPOINT) && OCM_API_ENDPOINT !== ''
            ? rtrim(OCM_API_ENDPOINT, '/') . '/'
            : 'https://1clickmigration.com/api/';
        $url = $base . self::ENDPOINT_PATH;
        if (function_exists('apply_filters')) {
            $url = (string) apply_filters('ocm_local_telemetry_endpoint', $url);
        }
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }
        return $url;
    }

    /**
     * The whole report: every field is a number, a version, or one value from
     * a fixed list. There is no free text in it.
     *
     * @param array  $event_data
     * @param string $site_hash
     * @param string $plugin_version
     * @return array|null Null when the event is not one that may be sent.
     */
    private static function build_payload(array $event_data, $site_hash, $plugin_version)
    {
        $event = isset($event_data['event']) ? (string) $event_data['event'] : '';
        $status = isset($event_data['status']) ? (string) $event_data['status'] : '';
        if (!in_array($event, self::EVENTS, true) || !in_array($status, self::STATUSES, true)) {
            return null;
        }

        $parts = array();
        if (isset($event_data['parts']) && is_array($event_data['parts'])) {
            foreach ($event_data['parts'] as $p) {
                $p = (string) $p;
                if (in_array($p, array('db', 'themes', 'plugins', 'uploads'), true) && !in_array($p, $parts, true)) {
                    $parts[] = $p;
                }
            }
        }

        $frequency = isset($event_data['frequency']) ? (string) $event_data['frequency'] : '';
        $size_bucket = isset($event_data['size_bucket']) ? (string) $event_data['size_bucket'] : '';

        // File and line go out for a crash only, and only for a plugin file.
        $location = '';
        if ($event === 'local_crash' && isset($event_data['error_location'])) {
            $candidate = (string) $event_data['error_location'];
            if (strlen($candidate) <= 130 && strpos($candidate, '..') === false
                && preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.\-/]*\.php:\d{1,7}$#', $candidate)
            ) {
                $location = $candidate;
            }
        }

        return array(
            'schema' => self::SCHEMA_VERSION,
            'site_hash' => (string) $site_hash,
            'plugin_version' => substr((string) preg_replace('/[^0-9A-Za-z.\-_]/', '', (string) $plugin_version), 0, 24),
            'wp_version' => self::wp_major_minor(),
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'event' => $event,
            'trigger' => isset($event_data['trigger']) && in_array($event_data['trigger'], array('manual', 'scheduled', 'system'), true)
                ? (string) $event_data['trigger']
                : 'manual',
            'parts' => $parts,
            'status' => $status,
            'frequency' => in_array($frequency, self::FREQUENCIES, true) ? $frequency : '',
            'duration_sec' => isset($event_data['duration_sec']) ? max(0, min(86400, (int) $event_data['duration_sec'])) : 0,
            'size_bucket' => in_array($size_bucket, self::SIZE_BUCKETS, true) ? $size_bucket : '',
            'error_code' => self::normalize_error_code(isset($event_data['error_code']) ? $event_data['error_code'] : ''),
            'error_location' => $location,
        );
    }

    /**
     * Count this report against the hourly cap.
     *
     * @return bool False when this site has already sent MAX_EVENTS_PER_HOUR in the last hour.
     */
    private static function take_send_slot()
    {
        if (!function_exists('get_transient') || !function_exists('set_transient')) {
            return true;
        }
        $now = time();
        $window = get_transient('ocm_ltel_rate');
        if (!is_array($window) || !isset($window['start'], $window['count'])
            || (int) $window['start'] > $now || ($now - (int) $window['start']) >= 3600
        ) {
            $window = array('start' => $now, 'count' => 0);
        }
        if ((int) $window['count'] >= self::MAX_EVENTS_PER_HOUR) {
            return false;
        }
        $window['count'] = (int) $window['count'] + 1;
        set_transient('ocm_ltel_rate', $window, max(60, 3600 - ($now - (int) $window['start'])));
        return true;
    }

    /**
     * Send an anonymous telemetry event if and only if opted in.
     * Never throws and never sends site URLs, emails, paths or message text.
     *
     * @param array $event_data
     * @return bool True if dispatched cleanly, false if skipped or failed.
     */
    public static function send_event(array $event_data)
    {
        try {
            if (!self::is_opted_in() || !function_exists('wp_remote_post')) {
                return false;
            }
            $url = self::endpoint_url();
            if ($url === '') {
                return false;
            }
            $site_hash = self::site_hash();
            if ($site_hash === '') {
                return false;
            }

            $plugin_version = class_exists(__NAMESPACE__ . '\\One_Click_Migration', false)
                && method_exists(One_Click_Migration::class, 'get_version')
                ? (string) One_Click_Migration::get_version()
                : '';

            $payload = self::build_payload($event_data, $site_hash, $plugin_version);
            if ($payload === null) {
                return false;
            }

            $body = function_exists('wp_json_encode') ? wp_json_encode($payload) : json_encode($payload);
            if (!is_string($body) || $body === '') {
                return false;
            }

            if (!self::take_send_slot()) {
                return false;
            }

            // Explicit non-URL User-Agent prevents WordPress's default "WordPress/x.y; https://site" header.
            $ua = '1-Click-Migration/' . ($payload['plugin_version'] !== '' ? $payload['plugin_version'] : 'unknown');
            // 'blocking' => false still waits for the connection and the TLS
            // handshake, so the timeout is what bounds the wait. A report that
            // cannot be handed over in that time is dropped, never retried.
            $res = wp_remote_post($url, array(
                'timeout' => self::SEND_TIMEOUT_SEC,
                'blocking' => false,
                'redirection' => 0,
                'sslverify' => true,
                'user-agent' => $ua,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => $body,
            ));

            return !(function_exists('is_wp_error') && is_wp_error($res));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Record a finished/failed/skipped/cancelled local backup or restore operation.
     *
     * @param array  $entry      Activity row sanitized by OCM_Local_Backup::record_activity().
     *                           Its 'message' is only read here to pick a code; it is never sent.
     * @param string $error_code One of ERROR_CODES when the caller knows it (required where the
     *                           message is translated); '' to work it out from the internal message.
     * @return bool
     */
    public static function record_operation(array $entry, $error_code = '')
    {
        try {
            if (!self::is_opted_in()) {
                return false;
            }
            $type = isset($entry['type']) && in_array($entry['type'], array('search_replace', 'site_cleanup'), true) ? $entry['type']
                : (isset($entry['type']) && $entry['type'] === 'restore' ? 'local_restore' : 'local_backup');
            $status = isset($entry['status']) ? (string) $entry['status'] : '';

            $code = '';
            if ($status !== 'completed') {
                $code = self::normalize_error_code($error_code);
                if ($code === '') {
                    $code = self::infer_error_code(isset($entry['message']) ? (string) $entry['message'] : '', $status);
                }
            }

            return self::send_event(array(
                'event' => $type,
                'trigger' => isset($entry['trigger']) ? (string) $entry['trigger'] : 'manual',
                'status' => $status,
                'parts' => isset($entry['parts']) && is_array($entry['parts']) ? $entry['parts'] : array(),
                'duration_sec' => isset($entry['duration_sec']) ? (int) $entry['duration_sec'] : 0,
                'size_bucket' => self::size_bucket(isset($entry['size_bytes']) ? (int) $entry['size_bytes'] : 0),
                'error_code' => $code,
            ));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Fingerprint of the schedule settings, to tell a real change from a
     * settings save that left the schedule alone.
     *
     * @param array $schedule
     * @return string 32 hex chars
     */
    public static function schedule_signature(array $schedule)
    {
        $parts = isset($schedule['parts']) && is_array($schedule['parts']) ? array_map('strval', $schedule['parts']) : array();
        sort($parts);
        return md5(implode('|', array(
            empty($schedule['enabled']) ? '0' : '1',
            isset($schedule['frequency']) ? (string) $schedule['frequency'] : '',
            isset($schedule['time']) ? (string) $schedule['time'] : '',
            isset($schedule['day_of_week']) ? (string) (int) $schedule['day_of_week'] : '',
            isset($schedule['day_of_month']) ? (string) (int) $schedule['day_of_month'] : '',
            implode(',', $parts),
        )));
    }

    /**
     * @return string Signature of the saved schedule, '' when it cannot be read.
     */
    private static function current_schedule_signature()
    {
        if (!class_exists(__NAMESPACE__ . '\\OCM_Local_Scheduler', false)) {
            return '';
        }
        try {
            $schedule = OCM_Local_Scheduler::get_schedule();
            return is_array($schedule) ? self::schedule_signature($schedule) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Report the schedule when it changed. Called on every settings save, so
     * it compares the saved schedule with the one last seen (the one in place
     * when reporting was turned on, or the last one reported) and sends
     * nothing when they match.
     *
     * @param array $schedule
     * @return bool
     */
    public static function record_schedule_change(array $schedule)
    {
        try {
            if (!self::is_opted_in()) {
                return false;
            }
            $state = self::get_state();
            $sig = self::schedule_signature($schedule);
            if ($state['schedule_sig'] === $sig) {
                return false;
            }
            $state['schedule_sig'] = $sig;
            self::save_state($state);

            $enabled = !empty($schedule['enabled']);
            return self::send_event(array(
                'event' => 'local_schedule',
                'trigger' => 'manual',
                'status' => $enabled ? 'enabled' : 'disabled',
                'frequency' => isset($schedule['frequency']) ? (string) $schedule['frequency'] : '',
                'parts' => isset($schedule['parts']) && is_array($schedule['parts']) ? $schedule['parts'] : array(),
            ));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Report a fatal PHP error during a local run, when opted in: the PHP
     * error type and, for a plugin file, its plugin-relative path and line.
     * The error message is read only to leave out-of-memory fatals alone; it
     * is never sent. The same crash is reported at most once an hour.
     * Never throws, so the shutdown path that calls it cannot break.
     *
     * @param int    $errno
     * @param string $errstr
     * @param string $errfile
     * @param int    $errline
     * @return bool
     */
    public static function report_crash($errno, $errstr, $errfile, $errline)
    {
        try {
            if (self::is_out_of_memory($errstr)) {
                return false;
            }
            if (!self::is_opted_in()) {
                return false;
            }
            $code = self::php_error_code($errno);
            $file = self::relative_plugin_file($errfile);
            $location = $file !== '' ? ($file . ':' . max(0, (int) $errline)) : '';

            if (function_exists('get_transient') && function_exists('set_transient')) {
                $throttle_key = 'ocm_ltel_crash_' . substr(md5($code . '|' . $location), 0, 20);
                if (get_transient($throttle_key)) {
                    return false;
                }
                set_transient($throttle_key, 1, self::CRASH_THROTTLE_SEC);
            }

            return self::send_event(array(
                'event' => 'local_crash',
                'trigger' => 'system',
                'status' => 'failed',
                'error_code' => $code,
                'error_location' => $location,
            ));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * AJAX handler for the one-time consent modal buttons.
     *
     * @return void
     */
    public static function ajax_save_consent()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', '1-click-migration')), 403);
        }
        check_ajax_referer('ocm_local_save_telemetry', 'nonce');

        $raw = isset($_POST['consent']) ? sanitize_text_field(wp_unslash($_POST['consent'])) : '';
        if (!in_array($raw, array(self::CONSENT_OPTED_IN, self::CONSENT_DECLINED), true)) {
            wp_send_json_error(array('message' => __('Invalid consent choice.', '1-click-migration')), 400);
        }

        $state = self::set_consent($raw);
        wp_send_json_success(array(
            'consent' => $state['consent'],
            'opted_in' => ($state['consent'] === self::CONSENT_OPTED_IN),
        ));
    }
}
