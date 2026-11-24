<?php

namespace OCM;

use DirectoryIterator;
use WP_Error;
use wpdb;
use ZipArchive;

class OCM_Local_Backup
{
    const OPTION_ACTION_BACKUP = 'ocm_local_action_backup';
    const OPTION_ACTION_RESTORE = 'ocm_local_action_restore';
    const OPTION_IS_STOPPED = 'ocm_local_is_stopped';
    const OPTION_ACTIVE_BACKUP_RUN = 'ocm_local_active_backup_run';
    const OPTION_ACTIVE_RESTORE_RUN = 'ocm_local_active_restore_run';
    const OPTION_BACKUP_STEPS = 'ocm_local_backup_steps';
    const OPTION_RESTORE_STEPS = 'ocm_local_restore_steps';
    const OPTION_BACKUP_STARTING = 'ocm_local_backup_starting';
    const OPTION_WORKER_HEARTBEAT = 'ocm_local_worker_heartbeat';
    const OPTION_WATCHDOG_LAST = 'ocm_local_worker_watchdog_last';
    const OPTION_REVIVAL_COUNT = 'ocm_local_worker_revival_count';
    const OPTION_DISPATCH_BLOCKED = 'ocm_local_dispatch_blocked';
    const OPTION_BINARY_PREFLIGHT = 'ocm_local_restore_binary_preflight';
    const OPTION_RESTORE_PLUGINS_ISOLATED = 'ocm_local_restore_plugins_isolated';
    const OPTION_RESTORE_DEACTIVATED_PLUGINS = 'ocm_local_restore_deactivated_plugins';
    const OPTION_ACTIVITY_LOG = 'ocm_local_activity_log';

    const WATCHDOG_HOOK = 'ocm_local_worker_watchdog';
    const RUN_MARKER_PREFIX = '--- OCM_LOCAL_RUN_START ';
    const BACKUP_START_GRACE = 600;
    /** A run record with no action flag older than this is a start that died. */
    const STALE_START_LOCK_AGE = 300;
    const MAX_STEP_RETRIES = 4;
    const PRISTINE_DUMP_SUFFIX = '.ocm-pristine';
    const MAX_ACTIVITY_ENTRIES = 50;
    const LOG_VIEWER_MAX_BYTES = 262144;
    const LOG_VIEWER_DEFAULT_LINES = 250;

    /** @var string */
    private static $backup_run_id = '';

    /** @var string */
    private static $restore_run_id = '';

    /** @var bool */
    private static $restore_run_ended = false;

    /** @var int */
    private static $restore_run_checked_at = 0;

    /** @var array */
    private static $progress_map = array(
        array('Local backup started', '5%'),
        array('Database local backup started', '15%'),
        array('db local backup completed.', '35%'),
        array('themes local backup started', '40%'),
        array('themes local backup completed.', '55%'),
        array('plugins local backup started', '60%'),
        array('plugins local backup completed.', '75%'),
        array('uploads local backup started', '80%'),
        array('uploads local backup completed.', '95%'),
        array('Local backup completed.', '100%'),
        array('Local backup failed:', '0%'),
        array('Local restore started', '5%'),
        array('Extracting local archive: "db.zip"', '15%'),
        array('Extracting local archive: "themes.zip"', '25%'),
        array('Extracting local archive: "plugins.zip"', '35%'),
        array('Extracting local archive: "uploads.zip"', '45%'),
        array('SYSLOG: Importing database into staging prefix', '55%'),
        array('Main options verified on staging table', '68%'),
        array('Starting to update URLs in database', '72%'),
        array('SYSLOG: Finished updating URLs in the database', '78%'),
        array('Database has been restored', '82%'),
        array('Restoring "Themes"', '86%'),
        array('Themes have been restored', '88%'),
        array('Restoring "Uploads"', '90%'),
        array('Uploads have been restored', '92%'),
        array('Plugins have been restored', '95%'),
        array('Local restore completed.', '100%'),
        array('Local restore stopped', '0%'),
        array('Local restore failed:', '0%'),
        array('Error:', '0%'),
    );

    /**
     * @return bool
     */
    public static function is_running()
    {
        return (bool) get_option(self::OPTION_ACTION_BACKUP) || (bool) get_option(self::OPTION_ACTION_RESTORE);
    }

    /**
     * @return bool
     */
    public static function any_operation_running()
    {
        return self::is_running()
            || (bool) get_option('ocm_action_start_backup')
            || (bool) get_option('ocm_action_start_restore');
    }

    /**
     * @return string 'backup'|'restore'|''
     */
    public static function active_action()
    {
        if (get_option(self::OPTION_ACTION_BACKUP)) {
            return 'backup';
        }
        if (get_option(self::OPTION_ACTION_RESTORE)) {
            return 'restore';
        }
        return '';
    }

    /**
     * @return string
     */
    public static function active_restore_backup_id()
    {
        if (!get_option(self::OPTION_ACTION_RESTORE)) {
            return '';
        }
        $run = get_option(self::OPTION_ACTIVE_RESTORE_RUN, array());
        return is_array($run) && isset($run['backup_id']) ? (string) $run['backup_id'] : '';
    }

    public static function delete_backup_options()
    {
        delete_option(self::OPTION_ACTIVE_BACKUP_RUN);
        delete_option(self::OPTION_BACKUP_STEPS);
        delete_option(self::OPTION_BACKUP_STARTING);
    }

    public static function delete_restore_options()
    {
        delete_option(self::OPTION_ACTIVE_RESTORE_RUN);
        delete_option(self::OPTION_RESTORE_STEPS);
        delete_option(self::OPTION_BINARY_PREFLIGHT);
        delete_option(self::OPTION_RESTORE_PLUGINS_ISOLATED);
        delete_option(self::OPTION_RESTORE_DEACTIVATED_PLUGINS);
        delete_option(OCM_SQL_Utils::OPTION_STAGING_PREFIX);
    }

    public static function delete_runtime_options()
    {
        // Stop & Reset on the cloud tab and the watchdog end a restore from
        // outside it: put the plugins it switched off back before the saved
        // list is deleted below.
        self::restore_isolated_plugins();
        self::delete_backup_options();
        self::delete_restore_options();
        delete_option(self::OPTION_ACTION_BACKUP);
        delete_option(self::OPTION_ACTION_RESTORE);
        delete_option(self::OPTION_IS_STOPPED);
        delete_option(self::OPTION_WORKER_HEARTBEAT);
        delete_option(self::OPTION_WATCHDOG_LAST);
        delete_option(self::OPTION_REVIVAL_COUNT);
        delete_option(self::OPTION_DISPATCH_BLOCKED);
    }

    public static function reset_watchdog()
    {
        delete_option(self::OPTION_WORKER_HEARTBEAT);
        delete_option(self::OPTION_WATCHDOG_LAST);
        delete_option(self::OPTION_REVIVAL_COUNT);
    }

    /**
     * Mark the local worker alive, whatever log channel the caller is in.
     * Long steps with no queue work left (hashing, publishing, pruning) call
     * this so the watchdog doesn't take a finishing run for a dead one.
     *
     * @return void
     */
    private static function touch_heartbeat()
    {
        update_option(self::OPTION_WORKER_HEARTBEAT, time(), false);
    }

    public static function set_backup_run_context($run_id)
    {
        self::$backup_run_id = is_string($run_id) ? $run_id : '';
    }

    public static function is_current_backup_run($run_id)
    {
        if (!is_string($run_id) || $run_id === '') {
            return false;
        }
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_ACTIVE_BACKUP_RUN, 'options');
        }
        $run = get_option(self::OPTION_ACTIVE_BACKUP_RUN, array());
        return is_array($run) && isset($run['id']) && is_string($run['id']) && hash_equals($run['id'], $run_id);
    }

    public static function release_backup_run($run_id = null)
    {
        if (null === $run_id) {
            $run_id = self::$backup_run_id;
        }
        $run = get_option(self::OPTION_ACTIVE_BACKUP_RUN, array());
        if (is_string($run_id) && $run_id !== '' && is_array($run) && isset($run['id']) && !hash_equals((string) $run['id'], $run_id)) {
            return false;
        }
        delete_option(self::OPTION_ACTIVE_BACKUP_RUN);
        if (is_string($run_id) && $run_id !== '' && hash_equals(self::$backup_run_id, $run_id)) {
            self::$backup_run_id = '';
        }
        return true;
    }

    public static function set_restore_run_context($run_id)
    {
        self::$restore_run_id = is_string($run_id) ? $run_id : '';
        self::$restore_run_ended = false;
        self::$restore_run_checked_at = 0;
    }

    public static function is_current_restore_run($run_id)
    {
        if (!is_string($run_id) || $run_id === '') {
            return false;
        }
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_ACTIVE_RESTORE_RUN, 'options');
        }
        $run = get_option(self::OPTION_ACTIVE_RESTORE_RUN, array());
        return is_array($run) && isset($run['id']) && is_string($run['id']) && hash_equals($run['id'], $run_id);
    }

    public static function restore_run_ended()
    {
        if (self::$restore_run_id === '') {
            return false;
        }
        if (!self::$restore_run_ended && !self::is_current_restore_run(self::$restore_run_id)) {
            self::$restore_run_ended = true;
        }
        return self::$restore_run_ended;
    }

    public static function restore_run_ended_throttled()
    {
        if (self::$restore_run_id === '' || self::$restore_run_ended) {
            return self::$restore_run_ended;
        }
        $now = time();
        if ($now - self::$restore_run_checked_at < 5) {
            return false;
        }
        self::$restore_run_checked_at = $now;
        return self::restore_run_ended();
    }

    private static function generate_run_id()
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable $e) {
            return strtolower(substr(preg_replace('/[^a-zA-Z0-9]/', '', wp_generate_password(16, false, false)), 0, 16));
        }
    }

    /**
     * Normalize a requested parts array in canonical order (db first).
     *
     * @param mixed $parts
     * @return string[]
     */
    public static function normalize_parts($parts)
    {
        if (!is_array($parts)) {
            return array();
        }
        $out = array();
        foreach (OCM_Local_Storage::PARTS as $part) {
            if (in_array($part, $parts, true)) {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * Prepend a local backup/restore entry to the persistent capped activity log.
     *
     * @param array $entry
     * @return void
     */
    public static function record_activity(array $entry)
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_ACTIVITY_LOG, 'options');
        }
        $entries = get_option(self::OPTION_ACTIVITY_LOG, array());
        if (!is_array($entries)) {
            $entries = array();
        }

        $type = isset($entry['type']) && $entry['type'] === 'restore' ? 'restore' : 'backup';
        $trigger = isset($entry['trigger']) && $entry['trigger'] === 'scheduled' ? 'scheduled' : 'manual';
        $allowed_statuses = array('completed', 'failed', 'skipped', 'cancelled');
        $raw_status = isset($entry['status']) ? (string) $entry['status'] : 'completed';
        $status = in_array($raw_status, $allowed_statuses, true) ? $raw_status : 'completed';

        $backup_id = isset($entry['backup_id']) ? sanitize_text_field((string) $entry['backup_id']) : '';
        $label = isset($entry['label']) ? substr(sanitize_text_field((string) $entry['label']), 0, 80) : '';
        $parts = isset($entry['parts']) && is_array($entry['parts']) ? self::normalize_parts($entry['parts']) : array();
        $size_bytes = isset($entry['size_bytes']) ? max(0, (int) $entry['size_bytes']) : 0;
        $started_gmt = isset($entry['started_gmt']) ? max(0, (int) $entry['started_gmt']) : 0;
        $finished_gmt = isset($entry['finished_gmt']) && (int) $entry['finished_gmt'] > 0
            ? (int) $entry['finished_gmt']
            : time();
        $duration_sec = $started_gmt > 0 ? max(0, $finished_gmt - $started_gmt) : 0;
        $message = isset($entry['message']) ? trim(preg_replace('/\s+/', ' ', sanitize_text_field((string) $entry['message']))) : '';
        if (strlen($message) > 300) {
            $message = substr($message, 0, 297) . '...';
        }

        $clean = array(
            'id' => isset($entry['id']) && is_string($entry['id']) && $entry['id'] !== '' ? $entry['id'] : self::generate_run_id(),
            'type' => $type,
            'trigger' => $trigger,
            'status' => $status,
            'backup_id' => $backup_id,
            'label' => $label,
            'parts' => $parts,
            'size_bytes' => $size_bytes,
            'size_readable' => $size_bytes > 0 ? OCM_Backup::readable_bytes($size_bytes) : '',
            'started_gmt' => $started_gmt,
            'finished_gmt' => $finished_gmt,
            'duration_sec' => $duration_sec,
            'message' => $message,
        );

        array_unshift($entries, $clean);
        if (count($entries) > self::MAX_ACTIVITY_ENTRIES) {
            $entries = array_slice($entries, 0, self::MAX_ACTIVITY_ENTRIES);
        }
        update_option(self::OPTION_ACTIVITY_LOG, $entries, false);
    }

    /**
     * Build an activity entry from an active/ended run array and record it.
     *
     * @param string $type    'backup'|'restore'
     * @param string $status  'completed'|'failed'|'skipped'|'cancelled'
     * @param array  $run
     * @param string $message
     * @param array  $extra
     * @return void
     */
    private static function record_run_activity($type, $status, array $run, $message, array $extra = array())
    {
        $parts = array();
        if (isset($extra['parts']) && is_array($extra['parts'])) {
            $parts = $extra['parts'];
        } elseif (isset($run['parts']) && is_array($run['parts'])) {
            $parts = $run['parts'];
        } elseif (isset($run['expected_parts']) && is_array($run['expected_parts'])) {
            $parts = $run['expected_parts'];
        }

        $backup_id = isset($extra['backup_id'])
            ? (string) $extra['backup_id']
            : (isset($run['backup_id']) ? (string) $run['backup_id'] : '');
        $label = isset($extra['label'])
            ? (string) $extra['label']
            : (isset($run['label']) ? (string) $run['label'] : '');
        if ($label === '' && $type === 'restore' && $backup_id !== '' && class_exists(__NAMESPACE__ . '\\OCM_Local_Storage')) {
            $manifest = OCM_Local_Storage::read_manifest($backup_id);
            if (is_array($manifest) && isset($manifest['label'])) {
                $label = (string) $manifest['label'];
            }
        }

        self::record_activity(array(
            'id' => isset($run['id']) && is_string($run['id']) && $run['id'] !== '' ? $run['id'] : self::generate_run_id(),
            'type' => $type,
            'trigger' => isset($extra['trigger']) ? $extra['trigger'] : (isset($run['trigger']) ? $run['trigger'] : 'manual'),
            'status' => $status,
            'backup_id' => $backup_id,
            'label' => $label,
            'parts' => $parts,
            'size_bytes' => isset($extra['size_bytes']) ? (int) $extra['size_bytes'] : 0,
            'started_gmt' => isset($run['started_at']) ? (int) $run['started_at'] : 0,
            'finished_gmt' => time(),
            'message' => $message,
        ));
    }

    /**
     * Format seconds into a compact human-readable duration.
     *
     * @param int $seconds
     * @return string
     */
    private static function format_duration($seconds)
    {
        $sec = max(0, (int) $seconds);
        if ($sec === 0) {
            return '';
        }
        if ($sec < 60) {
            return $sec . 's';
        }
        $mins = (int) floor($sec / 60);
        $rem_sec = $sec % 60;
        if ($mins < 60) {
            return sprintf('%dm %02ds', $mins, $rem_sec);
        }
        $hrs = (int) floor($mins / 60);
        $rem_mins = $mins % 60;
        return sprintf('%dh %02dm', $hrs, $rem_mins);
    }

    /**
     * Return formatted activity log entries (newest first).
     *
     * @param int $limit
     * @return array[]
     */
    public static function get_activity_log($limit = self::MAX_ACTIVITY_ENTRIES)
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_ACTIVITY_LOG, 'options');
        }
        $entries = get_option(self::OPTION_ACTIVITY_LOG, array());
        if (!is_array($entries)) {
            return array();
        }
        $limit = max(1, min(self::MAX_ACTIVITY_ENTRIES, (int) $limit));
        $sliced = array_slice($entries, 0, $limit);
        $out = array();
        foreach ($sliced as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ts = !empty($row['finished_gmt']) ? (int) $row['finished_gmt'] : (isset($row['started_gmt']) ? (int) $row['started_gmt'] : 0);
            $row['time_formatted'] = $ts > 0 ? OCM_Local_Scheduler::format_timestamp($ts) : '';
            $dur = isset($row['duration_sec']) ? (int) $row['duration_sec'] : 0;
            $row['duration_readable'] = $dur > 0 ? self::format_duration($dur) : (!empty($row['started_gmt']) ? '< 1s' : '');
            if (!isset($row['size_readable'])) {
                $sb = isset($row['size_bytes']) ? (int) $row['size_bytes'] : 0;
                $row['size_readable'] = $sb > 0 ? OCM_Backup::readable_bytes($sb) : '';
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Clear the persistent activity log without deleting the option key.
     *
     * @return true
     */
    public static function clear_activity_log()
    {
        update_option(self::OPTION_ACTIVITY_LOG, array(), false);
        return true;
    }

    /**
     * Read the tail of a local log file for safe in-page display.
     *
     * @param string $file_path
     * @param int    $max_lines
     * @param int    $max_bytes
     * @return array{exists:bool,content:string,truncated:bool,size:int,size_readable:string,line_count:int,updated_at:string}
     */
    public static function read_log_tail($file_path, $max_lines = self::LOG_VIEWER_DEFAULT_LINES, $max_bytes = self::LOG_VIEWER_MAX_BYTES)
    {
        $file_path = (string) $file_path;
        if ($file_path === '' || !is_file($file_path) || !is_readable($file_path)) {
            return array(
                'exists' => false,
                'content' => '',
                'truncated' => false,
                'size' => 0,
                'size_readable' => '0 B',
                'line_count' => 0,
                'updated_at' => '',
            );
        }

        clearstatcache(true, $file_path);
        $size = (int) @filesize($file_path);
        $mtime = (int) @filemtime($file_path);
        $updated_at = $mtime > 0 ? OCM_Local_Scheduler::format_timestamp($mtime) : '';
        $size_readable = OCM_Backup::readable_bytes($size);

        $max_lines = max(1, (int) $max_lines);
        $max_bytes = max(1024, (int) $max_bytes);
        $truncated = false;
        $raw = '';

        if ($size > 0) {
            $fh = @fopen($file_path, 'rb');
            if (is_resource($fh)) {
                if ($size > $max_bytes) {
                    $truncated = true;
                    @fseek($fh, -$max_bytes, SEEK_END);
                    $raw = (string) @fread($fh, $max_bytes);
                    $nl = strpos($raw, "\n");
                    if ($nl !== false) {
                        $raw = substr($raw, $nl + 1);
                    }
                } else {
                    $raw = (string) @fread($fh, $size);
                }
                @fclose($fh);
            }
        }

        $normalized = str_replace(array("\r\n", "\r"), "\n", $raw);
        $normalized = trim($normalized, "\n");
        if ($normalized === '') {
            return array(
                'exists' => true,
                'content' => '',
                'truncated' => $truncated,
                'size' => $size,
                'size_readable' => $size_readable,
                'line_count' => 0,
                'updated_at' => $updated_at,
            );
        }

        $lines = explode("\n", $normalized);
        $total = count($lines);
        if ($total > $max_lines) {
            $lines = array_slice($lines, -$max_lines);
            $truncated = true;
        }

        return array(
            'exists' => true,
            'content' => implode("\n", $lines),
            'truncated' => $truncated,
            'size' => $size,
            'size_readable' => $size_readable,
            'line_count' => count($lines),
            'updated_at' => $updated_at,
        );
    }

    /**
     * Delete ocm_local_backup.log when idle.
     *
     * @return true|WP_Error
     */
    public static function clear_local_log()
    {
        if (self::is_running()) {
            return new WP_Error(
                'operation_in_progress',
                __('Cannot clear the log while a local backup or restore is running.', '1-click-migration')
            );
        }
        $file = defined('OCM_LOCAL_LOG_FILE') ? (string) OCM_LOCAL_LOG_FILE : '';
        if ($file !== '' && is_file($file)) {
            if (!@unlink($file) && is_file($file)) {
                return new WP_Error('log_clear_failed', __('Could not delete the local log file.', '1-click-migration'));
            }
        }
        return true;
    }

    /**
     * Start a local backup (manual or scheduled).
     *
     * @param string[] $parts
     * @param string   $label
     * @param string   $trigger  'manual'|'scheduled'
     * @param bool     $dispatch Whether to dispatch/trigger the background worker immediately.
     * @return array|WP_Error    Run info on success, WP_Error on refusal.
     */
    public static function start_backup(array $parts, $label = '', $trigger = 'manual', $dispatch = true)
    {
        $included = self::normalize_parts($parts);
        if (empty($included)) {
            return new WP_Error('no_parts_selected', __('Please select at least one component to back up.', '1-click-migration'));
        }
        $excluded = array_values(array_diff(OCM_Local_Storage::PARTS, $included));
        $trigger = ($trigger === 'scheduled') ? 'scheduled' : 'manual';
        $label = substr(sanitize_text_field((string) $label), 0, 80);

        if (self::any_operation_running()) {
            $msg = __('Another backup or restore is already running on this site.', '1-click-migration');
            if ($trigger === 'scheduled') {
                One_Click_Migration::write_to_local_log('Notice: Scheduled local backup skipped — another backup or restore is in progress.');
                OCM_Local_Scheduler::record_status('skipped', $msg);
                self::record_run_activity('backup', 'skipped', array(
                    'trigger' => 'scheduled',
                    'label' => $label,
                    'parts' => $included,
                    'started_at' => time(),
                ), $msg);
            }
            return new WP_Error('operation_in_progress', $msg);
        }

        if (!OCM_Local_Storage::ensure_base_dir()) {
            $msg = __('Local backup directory could not be created or is not writable.', '1-click-migration');
            if ($trigger === 'scheduled') {
                One_Click_Migration::write_to_local_log('Local backup failed: ' . $msg);
                OCM_Local_Scheduler::record_status('failed', $msg);
            }
            self::record_run_activity('backup', 'failed', array(
                'trigger' => $trigger,
                'label' => $label,
                'parts' => $included,
                'started_at' => time(),
            ), $msg);
            return new WP_Error('storage_unwritable', $msg);
        }

        $disk = OCM_Local_Storage::check_disk_space_guard($excluded);
        if (empty($disk['ok'])) {
            $run_id = self::generate_run_id();
            $reason_msg = sprintf(
                'Not enough free disk space (available: %s, required including minimum free floor %s: %s).',
                OCM_Backup::readable_bytes($disk['free']),
                OCM_Backup::readable_bytes($disk['min_free']),
                OCM_Backup::readable_bytes($disk['required'])
            );
            One_Click_Migration::run_in_local_log(static function () use ($run_id, $reason_msg, $trigger) {
                One_Click_Migration::rotate_local_log_if_needed();
                One_Click_Migration::write_to_local_log(OCM_Local_Backup::RUN_MARKER_PREFIX . $run_id . ' ---');
                One_Click_Migration::write_to_local_log('Error: ' . $reason_msg);
                One_Click_Migration::write_to_local_log('Local backup failed: ' . $reason_msg);
                if ($trigger === 'scheduled') {
                    OCM_Local_Scheduler::record_status('skipped', $reason_msg);
                }
            });
            self::record_run_activity('backup', ($trigger === 'scheduled' ? 'skipped' : 'failed'), array(
                'id' => $run_id,
                'trigger' => $trigger,
                'label' => $label,
                'parts' => $included,
                'started_at' => time(),
            ), $reason_msg);
            return new WP_Error(
                'insufficient_disk_space',
                sprintf(
                    __('Not enough disk space for local backup (available: %1$s, required with safety reserve: %2$s).', '1-click-migration'),
                    OCM_Backup::readable_bytes($disk['free']),
                    OCM_Backup::readable_bytes($disk['required'])
                )
            );
        }

        $backup_id = OCM_Local_Storage::generate_backup_id();
        $run_id = self::generate_run_id();
        $run_record = array(
            'id' => $run_id,
            'backup_id' => $backup_id,
            'trigger' => $trigger,
            'label' => $label,
            'excluded' => $excluded,
            'expected_parts' => $included,
            'sizes' => $disk['sizes'],
            'started_at' => time(),
        );

        $locked = add_option(self::OPTION_ACTIVE_BACKUP_RUN, $run_record, '', false);
        if (!$locked && self::clear_stale_backup_start_lock()) {
            $locked = add_option(self::OPTION_ACTIVE_BACKUP_RUN, $run_record, '', false);
        }
        if (!$locked) {
            $msg = __('Another backup or restore is already running on this site.', '1-click-migration');
            if ($trigger === 'scheduled') {
                One_Click_Migration::write_to_local_log('Notice: Scheduled local backup skipped — another local backup is starting.');
                OCM_Local_Scheduler::record_status('skipped', $msg);
                self::record_run_activity('backup', 'skipped', $run_record, $msg);
            }
            return new WP_Error('operation_in_progress', $msg);
        }

        OCM_Local_Storage::cleanup_stale_staging_dirs($backup_id);
        $staging_dir = OCM_Local_Storage::staging_dir($backup_id);
        if ($staging_dir === '' || !OCM_Local_Storage::ensure_dir_protected($staging_dir)) {
            self::release_backup_run($run_id);
            $msg = __('Could not create temporary local backup directory.', '1-click-migration');
            if ($trigger === 'scheduled') {
                One_Click_Migration::write_to_local_log('Local backup failed: ' . $msg);
                OCM_Local_Scheduler::record_status('failed', $msg);
            }
            self::record_run_activity('backup', 'failed', $run_record, $msg);
            return new WP_Error('storage_unwritable', $msg);
        }

        OCM_Local_Storage::write_manifest($staging_dir, array(
            'version' => 1,
            'id' => $backup_id,
            'trigger' => $trigger,
            'label' => $label,
            'status' => 'in_progress',
            'started_at' => gmdate('c'),
        ));

        self::set_backup_run_context($run_id);
        update_option(self::OPTION_ACTION_BACKUP, true, false);
        update_option(self::OPTION_IS_STOPPED, false, false);
        update_option(self::OPTION_BACKUP_STARTING, time(), false);
        update_option(self::OPTION_BACKUP_STEPS, array(), false);
        self::reset_watchdog();

        $clear_process = One_Click_Migration::$process_local_backup;
        if (is_object($clear_process)) {
            $clear_process->clear_cancel_status();
            self::clear_queue_rows_for_action('local_backup');
        }

        One_Click_Migration::run_in_local_log(static function () use ($run_id, $backup_id, $trigger, $label, $included, $excluded, $disk) {
            global $wp_version;
            One_Click_Migration::rotate_local_log_if_needed();
            One_Click_Migration::write_to_local_log(OCM_Local_Backup::RUN_MARKER_PREFIX . $run_id . ' ---');
            One_Click_Migration::write_to_local_log('Local backup started');
            One_Click_Migration::write_to_local_log(sprintf('SYSLOG: Backup ID: %s | Trigger: %s%s', $backup_id, $trigger, $label !== '' ? ' | Label: ' . $label : ''));
            One_Click_Migration::write_to_local_log('SYSLOG: Site URL: ' . get_site_url());
            One_Click_Migration::write_to_local_log('SYSLOG: WP Version: ' . (isset($wp_version) ? $wp_version : 'unknown') . ' | PHP Version: ' . PHP_VERSION);
            One_Click_Migration::write_to_local_log('SYSLOG: Parts: ' . implode(', ', $included) . ($excluded ? ' | Excluded: ' . implode(', ', $excluded) : ''));
            One_Click_Migration::write_to_local_log('SYSLOG: Free disk space: ' . ($disk['free'] > 0 ? OCM_Backup::readable_bytes($disk['free']) : 'unknown'));

            if ($trigger === 'scheduled') {
                OCM_Local_Scheduler::record_status('started', __('Scheduled local backup in progress.', '1-click-migration'), $backup_id);
            }

            $proc = One_Click_Migration::$process_local_backup;
            if (is_object($proc)) {
                foreach ($included as $part) {
                    $proc->push_to_queue(array($part, $run_id));
                    One_Click_Migration::write_to_local_log(sprintf('SYSLOG: %s added to local backup queue', $part));
                }
                $proc->save();
            }

            delete_option(OCM_Local_Backup::OPTION_BACKUP_STARTING);
            One_Click_Migration::worker_heartbeat();
            OCM_Local_Backup::schedule_watchdog(60);
        });

        if ($dispatch && is_object(One_Click_Migration::$process_local_backup)) {
            $mode = self::dispatch_worker(One_Click_Migration::$process_local_backup, 'local backup');
            return array(
                'backup_id' => $backup_id,
                'run_id' => $run_id,
                'dispatch_mode' => $mode,
            );
        }

        return array(
            'backup_id' => $backup_id,
            'run_id' => $run_id,
            'dispatch_mode' => 'queued',
        );
    }

    /**
     * start_backup() takes the run record before it sets the action flag. A
     * request that dies in between leaves a record nothing else clears, and
     * every later start is refused. Remove it once it is too old to be a start
     * still in flight and no backup is flagged as running.
     *
     * @return bool Whether a stale record was removed.
     */
    private static function clear_stale_backup_start_lock()
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_ACTIVE_BACKUP_RUN, 'options');
            wp_cache_delete(self::OPTION_ACTION_BACKUP, 'options');
        }
        if (get_option(self::OPTION_ACTION_BACKUP)) {
            return false;
        }
        $run = get_option(self::OPTION_ACTIVE_BACKUP_RUN, null);
        if (null === $run || false === $run) {
            return false;
        }
        $started = is_array($run) && isset($run['started_at']) ? (int) $run['started_at'] : 0;
        if ($started > 0 && (time() - $started) < self::STALE_START_LOCK_AGE) {
            return false;
        }
        One_Click_Migration::write_to_local_log(' SYSLOG: Removed a stale local backup run record left by a start that never finished.');
        delete_option(self::OPTION_ACTIVE_BACKUP_RUN);
        return true;
    }

    /**
     * Triggered by WP-Cron via OCM_Local_Scheduler::handle_cron_event().
     *
     * @param array|null $schedule
     * @return array|WP_Error
     */
    public static function start_scheduled_backup($schedule = null)
    {
        if (!is_array($schedule)) {
            $schedule = OCM_Local_Scheduler::get_schedule();
        }
        $parts = isset($schedule['parts']) && is_array($schedule['parts']) ? $schedule['parts'] : OCM_Local_Storage::PARTS;
        return self::start_backup($parts, '', 'scheduled', true);
    }

    /**
     * Process a single part ('db', 'themes', 'plugins', 'uploads') inside the local backup worker.
     *
     * @param string      $part
     * @param string      $run_id
     * @param object|null $worker
     * @return mixed      false when done, RETURN_TYPE_* on abort.
     */
    public static function process_backup_part($part, $run_id, $worker = null)
    {
        if (!in_array($part, OCM_Local_Storage::PARTS, true)) {
            return false;
        }
        if (!self::is_current_backup_run($run_id)) {
            return OCM_BackgroundLocalBackup::RETURN_TYPE_END_PROCESS;
        }

        $steps = get_option(self::OPTION_BACKUP_STEPS, array());
        if (!is_array($steps)) {
            $steps = array();
        }
        if (!empty($steps[$part]['done'])) {
            return false;
        }

        $retries = isset($steps[$part]['retries']) ? (int) $steps[$part]['retries'] + 1 : 1;
        if ($retries > self::MAX_STEP_RETRIES) {
            self::fail_backup(sprintf('Local backup timed out while archiving "%s".', $part), $run_id);
            return OCM_BackgroundLocalBackup::RETURN_TYPE_END_PROCESS;
        }
        $steps[$part]['retries'] = $retries;
        update_option(self::OPTION_BACKUP_STEPS, $steps, false);

        $run = get_option(self::OPTION_ACTIVE_BACKUP_RUN, array());
        $backup_id = is_array($run) && isset($run['backup_id']) ? (string) $run['backup_id'] : '';
        $staging_dir = OCM_Local_Storage::staging_dir($backup_id);
        if ($staging_dir === '' || !is_dir($staging_dir)) {
            self::fail_backup('Local backup staging directory is missing.', $run_id);
            return OCM_BackgroundLocalBackup::RETURN_TYPE_END_PROCESS;
        }

        @set_time_limit(One_Click_Migration::get_timeout());
        $target_zip = $staging_dir . '/' . $part . '.zip';
        $uncompressed = 0;

        if ($part === 'db') {
            One_Click_Migration::write_to_local_log('Database local backup started');
            OCM_SQL_Utils::reset_backup_logging_flags();
            $db_mb = (float) OCM_Backup::DBSize();
            OCM_SQL_Utils::preflight_db_memory((int) ($db_mb * 1024 * 1024), 'dump');

            $db_tmp = $staging_dir . '/db_tmp';
            if (!is_dir($db_tmp) && !@mkdir($db_tmp, 0700, true) && !is_dir($db_tmp)) {
                self::fail_backup('Could not create temporary directory for database export.', $run_id);
                return OCM_BackgroundLocalBackup::RETURN_TYPE_END_PROCESS;
            }

            global $wpdb;
            $tables = $wpdb->get_results('SHOW TABLES');
            if (!is_array($tables) || empty($tables)) {
                OCM_Backup::deleteDirectoryBestEffort($db_tmp);
                self::fail_backup('No database tables found to export.', $run_id);
                return OCM_BackgroundLocalBackup::RETURN_TYPE_END_PROCESS;
            }

            foreach ($tables as $row) {
                if (!self::is_current_backup_run($run_id)) {
                    OCM_Backup::deleteDirectoryBestEffort($db_tmp);
                    return OCM_BackgroundLocalBackup::RETURN_TYPE_END_PROCESS;
                }
                foreach ((array) $row as $table_name) {
                    $table_name = (string) $table_name;
                    if ($table_name === '') {
                        continue;
                    }
                    $sql_file = $db_tmp . '/' . $table_name . '.sql';
                    $exported = OCM_SQL_Utils::export(array($sql_file), array(
                        'tables' => $table_name,
                        'quick' => null,
                    ));
                    if ($exported === false || !is_file($sql_file)) {
                        OCM_Backup::deleteDirectoryBestEffort($db_tmp);
                        self::fail_backup(sprintf('SQL dump write failed for table "%s".', $table_name), $run_id);
                        return OCM_BackgroundLocalBackup::RETURN_TYPE_END_PROCESS;
                    }
                    $uncompressed += (int) @filesize($sql_file);
                    One_Click_Migration::worker_heartbeat();
                }
            }

            $archived = OCM_Backup::create_local_zip_archive($db_tmp, $target_zip, true, $worker);
            OCM_Backup::deleteDirectoryBestEffort($db_tmp);
            if (!$archived) {
                self::fail_backup('Could not create the database zip archive.', $run_id);
                return OCM_BackgroundLocalBackup::RETURN_TYPE_END_PROCESS;
            }
        } else {
            One_Click_Migration::write_to_local_log(sprintf('%s local backup started', $part));
            $folder_path = WP_CONTENT_DIR . '/' . $part;
            $uncompressed = is_dir($folder_path) ? (int) OCM_Backup::folderSize($folder_path) : 0;

            if (!is_dir($folder_path) || !OCM_Backup::directory_contains_files($folder_path)) {
                if (!OCM_Backup::write_empty_zip_archive($target_zip)) {
                    self::fail_backup(sprintf('Could not create empty archive for "%s".', $part), $run_id);
                    return OCM_BackgroundLocalBackup::RETURN_TYPE_END_PROCESS;
                }
            } else {
                if (!OCM_Backup::create_local_zip_archive($folder_path, $target_zip, false, $worker)) {
                    self::fail_backup(sprintf('Could not create archive for "%s".', $part), $run_id);
                    return OCM_BackgroundLocalBackup::RETURN_TYPE_END_PROCESS;
                }
            }
        }

        if (!self::is_current_backup_run($run_id)) {
            return OCM_BackgroundLocalBackup::RETURN_TYPE_END_PROCESS;
        }

        self::touch_heartbeat();
        $zip_size = is_file($target_zip) ? (int) @filesize($target_zip) : 0;
        $sha256 = is_file($target_zip) ? (string) @hash_file('sha256', $target_zip) : '';

        $steps = get_option(self::OPTION_BACKUP_STEPS, array());
        if (!is_array($steps)) {
            $steps = array();
        }
        $steps[$part] = array(
            'done' => true,
            'file' => $part . '.zip',
            'size' => $zip_size,
            'uncompressed' => $uncompressed,
            'sha256' => $sha256,
        );
        update_option(self::OPTION_BACKUP_STEPS, $steps, false);
        One_Click_Migration::write_to_local_log(sprintf('%s local backup completed.', $part));
        // Before the queue row goes: the watchdog re-reads this once it finds
        // the queue empty, so a run that is about to publish is never orphaned.
        self::touch_heartbeat();

        return false;
    }

    /**
     * Finalize manifest, copy per-backup log, atomically publish backup dir,
     * enforce storage retention, and clear runtime state.
     *
     * @param string $run_id
     * @return bool
     */
    public static function complete_backup($run_id)
    {
        if (!self::is_current_backup_run($run_id)) {
            return false;
        }
        self::touch_heartbeat();

        $run = get_option(self::OPTION_ACTIVE_BACKUP_RUN, array());
        $steps = get_option(self::OPTION_BACKUP_STEPS, array());
        if (!is_array($run) || empty($run['backup_id']) || !is_array($steps)) {
            self::fail_backup('Local backup run metadata missing at completion.', $run_id);
            return false;
        }

        $backup_id = (string) $run['backup_id'];
        $staging_dir = OCM_Local_Storage::staging_dir($backup_id);
        $final_dir = OCM_Local_Storage::backup_dir($backup_id);
        $expected = isset($run['expected_parts']) && is_array($run['expected_parts']) ? $run['expected_parts'] : array();

        $parts_manifest = array();
        $total_size = 0;
        foreach ($expected as $part) {
            $zip_path = $staging_dir . '/' . $part . '.zip';
            if (empty($steps[$part]['done']) || !OCM_Backup::is_valid_zip_archive($zip_path)) {
                self::fail_backup(sprintf('Archive verification failed for "%s".', $part), $run_id);
                return false;
            }
            self::touch_heartbeat();
            $size = (int) @filesize($zip_path);
            $total_size += $size;
            $parts_manifest[$part] = array(
                'file' => $part . '.zip',
                'size' => $size,
                'uncompressed' => isset($steps[$part]['uncompressed']) ? (int) $steps[$part]['uncompressed'] : $size,
                'sha256' => isset($steps[$part]['sha256']) ? (string) $steps[$part]['sha256'] : '',
            );
        }

        global $wpdb, $wp_version;
        $manifest = array(
            'version' => 1,
            'id' => $backup_id,
            'created_at' => function_exists('current_time') ? current_time('mysql') : gmdate('Y-m-d H:i:s'),
            'created_gmt' => time(),
            'created_us' => microtime(true),
            'trigger' => isset($run['trigger']) ? (string) $run['trigger'] : 'manual',
            'label' => isset($run['label']) ? (string) $run['label'] : '',
            'site_url' => get_site_url(),
            'home_url' => function_exists('get_home_url') ? get_home_url() : get_site_url(),
            'wp_version' => isset($wp_version) ? (string) $wp_version : '',
            'php_version' => PHP_VERSION,
            'plugin_version' => One_Click_Migration::get_version(),
            'table_prefix' => isset($wpdb->base_prefix) ? (string) $wpdb->base_prefix : 'wp_',
            'excluded' => isset($run['excluded']) && is_array($run['excluded']) ? $run['excluded'] : array(),
            'parts' => $parts_manifest,
            'total_size' => $total_size,
            'status' => 'complete',
        );

        if (!OCM_Local_Storage::write_manifest($staging_dir, $manifest)) {
            self::fail_backup('Could not write local backup manifest.', $run_id);
            return false;
        }

        if ($final_dir === '' || is_dir($final_dir)) {
            self::fail_backup('Target local backup directory already exists or is invalid.', $run_id);
            return false;
        }

        self::write_run_log_snapshot($run_id, $staging_dir . '/backup.log');

        if (!@rename($staging_dir, $final_dir)) {
            self::fail_backup('Could not publish local backup directory.', $run_id);
            return false;
        }

        self::touch_heartbeat();
        OCM_Local_Storage::prune_backups(null, self::active_restore_backup_id());
        self::touch_heartbeat();

        if (isset($run['trigger']) && $run['trigger'] === 'scheduled') {
            OCM_Local_Scheduler::record_status('success', __('Scheduled local backup completed.', '1-click-migration'), $backup_id);
        }

        self::record_run_activity('backup', 'completed', $run, __('Local backup completed.', '1-click-migration'), array(
            'parts' => $expected,
            'size_bytes' => $total_size,
        ));

        update_option(self::OPTION_ACTION_BACKUP, false, false);
        update_option(self::OPTION_IS_STOPPED, true, false);
        self::release_backup_run($run_id);
        self::delete_backup_options();
        self::reset_watchdog();
        self::clear_queue_rows_for_action('local_backup');

        One_Click_Migration::write_to_local_log('Local backup completed.');
        @file_put_contents($final_dir . '/backup.log', date('m/d/YTH:i:sa') . " - Local backup completed.\n", FILE_APPEND);
        return true;
    }

    /**
     * @param string      $message
     * @param string|null $run_id
     * @return void
     */
    public static function fail_backup($message, $run_id = null)
    {
        // A worker left over from a cancelled run fails once its staging dir is
        // gone. It must not take the run that replaced it down with it.
        if (is_string($run_id) && $run_id !== '' && !self::is_current_backup_run($run_id)) {
            One_Click_Migration::write_to_local_log(sprintf(
                ' SYSLOG: Ignoring failure from a local backup run that is no longer current: %s',
                $message
            ));
            return;
        }
        $run = get_option(self::OPTION_ACTIVE_BACKUP_RUN, array());
        $backup_id = is_array($run) && isset($run['backup_id']) ? (string) $run['backup_id'] : '';
        $trigger = is_array($run) && isset($run['trigger']) ? (string) $run['trigger'] : 'manual';

        One_Click_Migration::run_in_local_log(static function () use ($message, $backup_id, $trigger) {
            One_Click_Migration::write_to_local_log('Error: ' . $message);
            One_Click_Migration::write_to_local_log('Local backup failed: ' . $message);
            if ($trigger === 'scheduled') {
                OCM_Local_Scheduler::record_status('failed', $message, $backup_id);
            }
        });

        self::record_run_activity('backup', 'failed', is_array($run) ? $run : array(), $message);

        if ($backup_id !== '') {
            $staging_dir = OCM_Local_Storage::staging_dir($backup_id);
            if ($staging_dir !== '' && is_dir($staging_dir)) {
                OCM_Backup::deleteDirectoryBestEffort($staging_dir);
            }
        }

        update_option(self::OPTION_ACTION_BACKUP, false, false);
        update_option(self::OPTION_IS_STOPPED, true, false);
        self::release_backup_run($run_id);
        self::delete_backup_options();
        self::reset_watchdog();
        self::clear_queue_rows_for_action('local_backup');
    }

    /**
     * Start restoring a saved local backup (full or partial).
     *
     * @param string   $backup_id
     * @param string[] $parts
     * @param bool     $dispatch
     * @return array|WP_Error
     */
    public static function start_restore($backup_id, array $parts, $dispatch = true)
    {
        if (!OCM_Local_Storage::is_valid_backup_id($backup_id)) {
            return new WP_Error('invalid_backup_id', __('Invalid backup ID.', '1-click-migration'));
        }

        $manifest = OCM_Local_Storage::read_manifest($backup_id);
        if (!$manifest) {
            return new WP_Error('backup_not_found', __('Local backup not found or incomplete.', '1-click-migration'));
        }

        $requested = self::normalize_parts($parts);
        $available = array_keys($manifest['parts']);
        $selected_parts = array();
        foreach ($requested as $part) {
            if (in_array($part, $available, true)) {
                $selected_parts[] = $part;
            }
        }
        if (empty($selected_parts)) {
            return new WP_Error('no_parts_selected', __('Select at least one available component to restore.', '1-click-migration'));
        }

        if (self::any_operation_running()) {
            return new WP_Error('operation_in_progress', __('Another backup or restore is already running on this site.', '1-click-migration'));
        }

        $restore_process = One_Click_Migration::$process_local_restore;
        if (is_object($restore_process) && method_exists($restore_process, 'clear_stale_process_lock')) {
            if ($restore_process->clear_stale_process_lock() === 'held') {
                return new WP_Error('operation_in_progress', __('A local restore worker is still running.', '1-click-migration'));
            }
        }

        // Verify archives and check restore disk preflight.
        $uncompressed_total = 0;
        $largest_zip = 0;
        foreach ($selected_parts as $part) {
            $zip_path = OCM_Local_Storage::part_file_path($backup_id, $part);
            if ($zip_path === '' || !OCM_Backup::is_valid_zip_archive($zip_path)) {
                return new WP_Error('archive_invalid', sprintf(__('Archive file for "%s" is missing or corrupted.', '1-click-migration'), $part));
            }
            $zip_bytes = (int) @filesize($zip_path);
            $largest_zip = max($largest_zip, $zip_bytes);
            $uncompressed = isset($manifest['parts'][$part]['uncompressed']) ? (int) $manifest['parts'][$part]['uncompressed'] : (int) ($zip_bytes * 1.6);
            $uncompressed_total += max($zip_bytes, $uncompressed);
        }

        if (!OCM_Backup::probe_restore_workspace_write()) {
            return new WP_Error('storage_unwritable', __('WordPress cannot write to wp-content for restore staging.', '1-click-migration'));
        }

        $required = (int) (($uncompressed_total + $largest_zip) * 1.1);
        $free = OCM_Backup::get_free_disk_space(WP_CONTENT_DIR);
        if (function_exists('apply_filters')) {
            $free = (int) apply_filters('ocm_local_free_disk_space', $free, WP_CONTENT_DIR);
        }
        if ($free > 0 && $required > 0 && $free < $required) {
            return new WP_Error(
                'insufficient_disk_space',
                sprintf(
                    __('Not enough disk space to restore (need %1$s, available %2$s).', '1-click-migration'),
                    OCM_Backup::readable_bytes($required),
                    OCM_Backup::readable_bytes($free)
                )
            );
        }

        $run_id = self::generate_run_id();
        self::delete_restore_options();
        self::clear_queue_rows_for_action('local_restore');
        if (is_object($restore_process)) {
            $restore_process->clear_cancel_status();
        }

        update_option(self::OPTION_ACTIVE_RESTORE_RUN, array(
            'id' => $run_id,
            'backup_id' => $backup_id,
            'parts' => $selected_parts,
            'started_at' => time(),
        ), false);
        update_option(self::OPTION_ACTION_RESTORE, true, false);
        update_option(self::OPTION_IS_STOPPED, false, false);
        update_option(self::OPTION_RESTORE_STEPS, array(), false);
        self::set_restore_run_context($run_id);
        self::reset_watchdog();

        One_Click_Migration::run_in_local_log(static function () use ($run_id, $backup_id, $selected_parts) {
            global $wp_version;
            One_Click_Migration::rotate_local_log_if_needed();
            One_Click_Migration::write_to_local_log(OCM_Local_Backup::RUN_MARKER_PREFIX . $run_id . ' ---');
            One_Click_Migration::write_to_local_log('Local restore started');
            One_Click_Migration::write_to_local_log(sprintf('SYSLOG: Restoring local backup "%s" | Parts: %s', $backup_id, implode(', ', $selected_parts)));
            One_Click_Migration::write_to_local_log('SYSLOG: Site URL: ' . get_site_url() . ' | WP: ' . (isset($wp_version) ? $wp_version : '') . ' | PHP: ' . PHP_VERSION);
            One_Click_Migration::enable_maintenance_mode();

            $proc = One_Click_Migration::$process_local_restore;
            if (is_object($proc)) {
                $proc->push_to_queue(array($backup_id, $selected_parts, $run_id));
                $proc->save();
            }
            One_Click_Migration::worker_heartbeat();
            OCM_Local_Backup::schedule_watchdog(60);
        });

        if ($dispatch && is_object($restore_process)) {
            $mode = self::dispatch_worker($restore_process, 'local restore');
            return array(
                'backup_id' => $backup_id,
                'run_id' => $run_id,
                'parts' => $selected_parts,
                'dispatch_mode' => $mode,
            );
        }

        return array(
            'backup_id' => $backup_id,
            'run_id' => $run_id,
            'parts' => $selected_parts,
            'dispatch_mode' => 'queued',
        );
    }

    /**
     * Execute local restore steps (resumable across worker handovers).
     *
     * @param string      $backup_id
     * @param string[]    $selected_parts
     * @param string      $run_id
     * @param object|null $worker
     * @return mixed      false when finished, RETURN_TYPE_STOP_PROCESS on stop.
     */
    public static function process_restore($backup_id, array $selected_parts, $run_id, $worker = null)
    {
        if (!self::is_current_restore_run($run_id)) {
            return OCM_BackgroundLocalRestore::RETURN_TYPE_STOP_PROCESS;
        }

        $manifest = OCM_Local_Storage::read_manifest($backup_id);
        if (!$manifest) {
            return self::fail_restore('Local backup archive or manifest is missing.');
        }

        $selected_parts = self::normalize_parts($selected_parts);
        if (empty($selected_parts)) {
            return self::fail_restore('No valid components selected for local restore.');
        }

        $steps = get_option(self::OPTION_RESTORE_STEPS, array());
        if (!is_array($steps)) {
            $steps = array();
        }

        $stalled = self::count_restore_attempt($steps);
        if ($stalled > self::MAX_STEP_RETRIES) {
            $attempts = $stalled - 1;
            if (self::restore_changed_live_site($steps)) {
                return self::fail_restore(sprintf(
                    'The restore was interrupted %d times in a row at the same step and has been stopped. Part of this backup is already restored; start the restore again to finish it.',
                    $attempts
                ));
            }
            return self::stop_restore_no_change(sprintf(
                'Local restore stopped: it was interrupted %d times in a row at the same step (the server may be ending long requests). No changes were made.',
                $attempts
            ));
        }

        $workspace = isset($steps['init']['workspace']) && is_string($steps['init']['workspace']) ? $steps['init']['workspace'] : '';
        if ($workspace === '' || !is_dir($workspace)) {
            $attempted = '';
            $workspace = OCM_Backup::prepare_restore_workspace($attempted);
            if ($workspace === '' || !is_dir($workspace)) {
                return self::fail_restore(sprintf('Could not prepare restore workspace "%s".', $attempted));
            }
            $steps['init']['workspace'] = $workspace;
            update_option(self::OPTION_RESTORE_STEPS, $steps, false);
        }

        @set_time_limit(One_Click_Migration::get_timeout());

        // 1. Extract every selected part directly from local storage (read-only; keep original zips!).
        foreach ($selected_parts as $part) {
            if (self::restore_run_ended()) {
                return OCM_BackgroundLocalRestore::RETURN_TYPE_STOP_PROCESS;
            }
            if (!empty($steps[$part]['extracted'])) {
                continue;
            }

            $zip_path = OCM_Local_Storage::part_file_path($backup_id, $part);
            if ($zip_path === '' || !is_file($zip_path)) {
                return self::fail_restore(sprintf('Local archive "%s.zip" not found.', $part));
            }

            $extract_path = $workspace . '/' . $part;
            $zip = new ZipArchive();
            if ($zip->open($zip_path) !== true) {
                return self::fail_restore(sprintf('Could not open local archive "%s.zip".', $part));
            }

            $resume = isset($steps[$part]['extract_resume']) && is_array($steps[$part]['extract_resume'])
                ? $steps[$part]['extract_resume']
                : array('index' => 0, 'offset' => 0);
            $resume_index = isset($resume['index']) ? (int) $resume['index'] : 0;
            $resume_offset = isset($resume['offset']) ? (int) $resume['offset'] : 0;

            $remaining_bytes = 0;
            for ($i = $resume_index; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat !== false) {
                    $remaining_bytes += (int) $stat['size'] - ($i === $resume_index ? min($resume_offset, (int) $stat['size']) : 0);
                }
            }
            $free = OCM_Backup::get_free_disk_space($workspace);
            if (function_exists('apply_filters')) {
                $free = (int) apply_filters('ocm_local_free_disk_space', $free, $workspace);
            }
            if ($free > 0 && $remaining_bytes > 0 && $free < $remaining_bytes) {
                $zip->close();
                return self::fail_restore(sprintf(
                    'Not enough disk space to extract "%s.zip" (need %s, available %s).',
                    $part,
                    OCM_Backup::readable_bytes($remaining_bytes),
                    OCM_Backup::readable_bytes($free)
                ));
            }

            One_Click_Migration::write_to_local_log(sprintf('Extracting local archive: "%s.zip"', $part));
            $extract_result = OCM_Backup::extract_zip_resumable($zip, $zip_path, $extract_path, $resume_index, $resume_offset, $worker);
            $total_files = (int) $zip->numFiles;
            $zip->close();

            if (self::restore_run_ended()) {
                return OCM_BackgroundLocalRestore::RETURN_TYPE_STOP_PROCESS;
            }

            if (is_array($extract_result)) {
                $steps = get_option(self::OPTION_RESTORE_STEPS, array());
                $steps[$part]['extract_resume'] = $extract_result;
                update_option(self::OPTION_RESTORE_STEPS, $steps, false);
                One_Click_Migration::write_to_local_log(sprintf(
                    'Extracting local archive: "%s.zip" (%d of %d files done)',
                    $part,
                    (int) $extract_result['index'],
                    $total_files
                ));
                if (is_object($worker) && method_exists($worker, 'restart_task')) {
                    $worker->restart_task();
                }
                return false;
            }

            if (!$extract_result || !is_dir($extract_path)) {
                return self::fail_restore(sprintf('Could not extract "%s.zip" into workspace.', $part));
            }

            $steps = get_option(self::OPTION_RESTORE_STEPS, array());
            $steps[$part]['extracted'] = true;
            unset($steps[$part]['extract_resume']);
            update_option(self::OPTION_RESTORE_STEPS, $steps, false);

            // Early DB safety preflight right after DB extract and before any file swap.
            if ($part === 'db') {
                $preflight = self::run_db_binary_preflight($workspace, $backup_id, $manifest, $worker);
                if ($preflight === 'continue') {
                    if (is_object($worker) && method_exists($worker, 'restart_task')) {
                        $worker->restart_task();
                    }
                    return false;
                }
                if ($preflight === false) {
                    return self::stop_restore_no_change('Local restore stopped before import: unsafe database binary content rejected. No changes were made.');
                }
            }

            if (is_object($worker) && method_exists($worker, 'has_batch_clock') && $worker->has_batch_clock() && $worker->time_exceeded()) {
                $worker->restart_task();
                return false;
            }
        }

        // 2. Database staging import, options gate, URL rewrite (if needed), and atomic promotion.
        if (in_array('db', $selected_parts, true)) {
            $steps = get_option(self::OPTION_RESTORE_STEPS, array());
            if (empty($steps['db']['promoted'])) {
                $db_ok = self::restore_db_from_workspace($workspace, $backup_id, $manifest, $worker);
                if ($db_ok === 'yield') {
                    return false;
                }
                if ($db_ok !== true) {
                    return OCM_BackgroundLocalRestore::RETURN_TYPE_STOP_PROCESS;
                }
                $steps = get_option(self::OPTION_RESTORE_STEPS, array());
                $steps['db']['promoted'] = true;
                update_option(self::OPTION_RESTORE_STEPS, $steps, false);
            }
        }

        // 3. Deferred file swaps (themes, uploads, plugins) after DB succeeds (or files-only).
        if (array_intersect($selected_parts, array('themes', 'uploads', 'plugins'))) {
            if (!self::apply_file_swaps($workspace, $selected_parts, $backup_id, $manifest, $worker)) {
                return OCM_BackgroundLocalRestore::RETURN_TYPE_STOP_PROCESS;
            }
        }

        // 4. Complete restore cleanly.
        self::finish_restore($workspace, $run_id);
        return false;
    }

    /**
     * Bound the restore the way backup parts are bounded. Every checkpoint
     * (extract position, prepared or imported dump, preflight offset, swapped
     * folder) changes the saved state, so a worker that starts from the state
     * the previous one started from made no progress: it died mid-step.
     * Planned handovers always follow a checkpoint and never count.
     *
     * @param array $steps OPTION_RESTORE_STEPS; the counter is saved in it.
     * @return int Consecutive starts from the same state, this one included.
     */
    private static function count_restore_attempt(array &$steps)
    {
        $guard = isset($steps['guard']) && is_array($steps['guard']) ? $steps['guard'] : array();
        $state = $steps;
        unset($state['guard']);
        $signature = sha1(serialize(array($state, get_option(self::OPTION_BINARY_PREFLIGHT, null))));
        $count = (isset($guard['sig'], $guard['count']) && $guard['sig'] === $signature) ? (int) $guard['count'] + 1 : 1;
        $steps['guard'] = array('sig' => $signature, 'count' => $count);
        update_option(self::OPTION_RESTORE_STEPS, $steps, false);
        return $count;
    }

    /**
     * Whether this restore has already replaced live tables or folders.
     *
     * @param array $steps
     * @return bool
     */
    private static function restore_changed_live_site(array $steps)
    {
        if (get_option(self::OPTION_RESTORE_PLUGINS_ISOLATED)) {
            return true;
        }
        if (!empty($steps['db']['promoted'])) {
            return true;
        }
        foreach (array('themes', 'uploads', 'plugins') as $part) {
            if (!empty($steps[$part]['swapped']) || !empty($steps[$part]['live_cleared'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Filter extra WP table sets out of extracted db/ and run resumable binary preflight.
     *
     * @param string      $workspace
     * @param string      $backup_id
     * @param array       $manifest
     * @param object|null $worker
     * @return bool|string True on pass, false on refuse, 'continue' to yield.
     */
    private static function run_db_binary_preflight($workspace, $backup_id, array $manifest, $worker = null)
    {
        $dbfiles = rtrim($workspace, '/\\') . '/db/';
        if (!is_dir($dbfiles)) {
            return false;
        }

        self::keep_only_source_table_set($dbfiles, $manifest);
        $sql_files = OCM_SQL_Utils::list_restore_sql_dump_files($dbfiles);
        if (empty($sql_files)) {
            One_Click_Migration::write_to_local_log('Error: Database backup contains no .sql dump files.');
            return false;
        }

        $fingerprint = self::binary_preflight_fingerprint($backup_id, $dbfiles);
        $stored = get_option(self::OPTION_BINARY_PREFLIGHT, null);
        if (is_array($stored) && isset($stored['key'], $stored['status']) && $stored['key'] === $fingerprint) {
            if ($stored['status'] === 'pass') {
                return true;
            }
            if ($stored['status'] === 'refuse') {
                return false;
            }
        } else {
            $stored = array('key' => $fingerprint, 'status' => 'pending', 'file_index' => 0, 'byte_offset' => 0);
            delete_option(self::OPTION_BINARY_PREFLIGHT);
        }

        do {
            $deadline = microtime(true) + 8.0;
            $result = OCM_SQL_Utils::preflight_reject_unsafe_binary_dumps_resumable($sql_files, $stored, $deadline);
            $status = isset($result['status']) ? $result['status'] : 'refuse';
            $state = isset($result['state']) && is_array($result['state']) ? $result['state'] : array();
            $state['key'] = $fingerprint;
            $state['status'] = $status;
            update_option(self::OPTION_BINARY_PREFLIGHT, $state, false);
            $stored = $state;

            if ($status === 'continue' && is_object($worker)) {
                return 'continue';
            }
        } while ($status === 'continue');

        return $status === 'pass';
    }

    private static function binary_preflight_fingerprint($backup_id, $dbfiles)
    {
        $parts = array('local=' . $backup_id);
        foreach (OCM_SQL_Utils::list_restore_sql_dump_files($dbfiles) as $filepath) {
            $parts[] = basename($filepath) . ':' . (int) @filesize($filepath) . ':' . (int) @filemtime($filepath);
        }
        return sha1(implode('|', $parts));
    }

    private static function rekey_binary_preflight_after_rewrite($pre_key, $backup_id, $dbfiles)
    {
        $stored = get_option(self::OPTION_BINARY_PREFLIGHT, null);
        if (!is_array($stored) || !isset($stored['status'], $stored['key']) || $stored['status'] !== 'pass') {
            return;
        }
        if ((string) $stored['key'] !== (string) $pre_key) {
            return;
        }
        $stored['key'] = self::binary_preflight_fingerprint($backup_id, $dbfiles);
        update_option(self::OPTION_BINARY_PREFLIGHT, $stored, false);
    }

    private static function keep_only_source_table_set($dbfiles, array $manifest)
    {
        $prefixes = OCM_SQL_Utils::wordpress_table_set_prefixes($dbfiles);
        if (count($prefixes) < 2) {
            return true;
        }
        $recorded = isset($manifest['table_prefix']) ? (string) $manifest['table_prefix'] : '';
        $source_url = isset($manifest['site_url']) ? (string) $manifest['site_url'] : '';
        $resolved = OCM_SQL_Utils::resolve_source_table_set($dbfiles, $prefixes, $recorded, $source_url);
        if ($resolved === false) {
            return false;
        }

        $source = $resolved['prefix'];
        $last = array();
        foreach (OCM_SQL_Utils::list_restore_sql_dump_files($dbfiles) as $filepath) {
            $table = substr(basename($filepath), 0, -4);
            $owner = OCM_SQL_Utils::table_set_prefix_for($table, $prefixes);
            if ($owner === null || $owner === $source) {
                continue;
            }
            if (strcasecmp($table, $owner . 'usermeta') === 0) {
                $last[] = $filepath;
                continue;
            }
            @unlink($filepath);
        }
        foreach ($last as $filepath) {
            @unlink($filepath);
        }
        One_Click_Migration::write_to_local_log(sprintf('SYSLOG: Source table set "%s" isolated for local restore.', $source));
        return true;
    }

    /**
     * Import DB into a staging prefix, verify options/siteurl gate, rewrite URLs
     * only if site_url changed, and promote tables atomically.
     *
     * @param string      $workspace
     * @param string      $backup_id
     * @param array       $manifest
     * @param object|null $worker
     * @return bool|string True on success, 'yield' if handed over, false on failure (already stopped).
     */
    private static function restore_db_from_workspace($workspace, $backup_id, array $manifest, $worker = null)
    {
        global $wpdb;
        $dbfiles = rtrim($workspace, '/\\') . '/db/';
        if (!is_dir($dbfiles)) {
            self::fail_restore('Extracted database directory does not exist.');
            return false;
        }

        if (!self::keep_only_source_table_set($dbfiles, $manifest)) {
            self::stop_restore_no_change('Local restore stopped: multiple table prefixes found and source prefix could not be determined. No changes were made.');
            return false;
        }

        $source_prefix = OCM_SQL_Utils::get_table_prefix($dbfiles . 'dummy.sql');
        if ($source_prefix === false || $source_prefix === '') {
            self::stop_restore_no_change('Local restore stopped: could not determine source table prefix. No changes were made.');
            return false;
        }

        $sql_bytes = 0;
        $found_files = OCM_SQL_Utils::list_restore_sql_dump_files($dbfiles);
        foreach ($found_files as $sql_file) {
            $sql_bytes += (int) @filesize($sql_file);
        }
        OCM_SQL_Utils::preflight_db_memory($sql_bytes, 'import');

        $priority = array(
            'options.sql', 'users.sql', 'links.sql', 'commentmeta.sql',
            'term_relationships.sql', 'postmeta.sql', 'posts.sql', 'term_taxonomy.sql',
            'usermeta.sql', 'terms.sql', 'comments.sql', 'termmeta.sql',
        );
        $prioritized = array();
        foreach ($priority as $p) {
            $prioritized = array_unique(array_merge($prioritized, preg_grep('/' . $p . '/i', $found_files)));
        }
        $sorted_tables = array_unique(array_merge($prioritized, $found_files));
        $dump_names = array_values(array_map('basename', $sorted_tables));

        $preflight = self::run_db_binary_preflight($workspace, $backup_id, $manifest, $worker);
        if ($preflight === 'continue') {
            if (is_object($worker) && method_exists($worker, 'restart_task')) {
                $worker->restart_task();
            }
            return 'yield';
        }
        if ($preflight !== true) {
            self::stop_restore_no_change('Local restore stopped before import: unsafe database binary content rejected. No changes were made.');
            return false;
        }

        $steps = get_option(self::OPTION_RESTORE_STEPS, array());
        $state = isset($steps['db']['dbImport']) && is_array($steps['db']['dbImport']) ? $steps['db']['dbImport'] : null;

        if ($state === null) {
            OCM_SQL_Utils::drop_stale_temp_tables($wpdb, null, $wpdb->base_prefix);
            $staging_prefix = OCM_SQL_Utils::allocate_staging_prefix($wpdb);
            if ($staging_prefix === false || $staging_prefix === '') {
                self::stop_restore_no_change('Local restore stopped: could not allocate a staging table prefix. No changes were made.');
                return false;
            }
            if (!OCM_SQL_Utils::assert_staging_identifier_headroom($sorted_tables, $staging_prefix, $source_prefix)) {
                self::stop_restore_no_change('Local restore stopped: staging table name exceeds identifier limit. No changes were made.');
                return false;
            }
            $state = array(
                'prefix' => $staging_prefix,
                'source_prefix' => $source_prefix,
                'files' => $dump_names,
                'prepared' => array(),
                'imported' => array(),
                'site_url' => '',
                'url_done' => false,
                'url_progress' => array(),
            );
            $steps['db']['dbImport'] = $state;
            update_option(self::OPTION_RESTORE_STEPS, $steps, false);
            update_option(OCM_SQL_Utils::OPTION_STAGING_PREFIX, $staging_prefix, false);
            One_Click_Migration::write_to_local_log(sprintf(
                'SYSLOG: Importing database into staging prefix "%s"; live tables with prefix "%s" remain untouched until import succeeds.',
                $staging_prefix,
                $wpdb->base_prefix
            ));
        } else {
            $staging_prefix = (string) $state['prefix'];
            OCM_SQL_Utils::drop_stale_temp_tables($wpdb, $staging_prefix, $wpdb->base_prefix);
        }

        $source_suffixes = OCM_SQL_Utils::source_table_suffixes_from_dumps($sorted_tables, $source_prefix);

        // Prepare (rewrite prefix inside dumps)
        foreach ($sorted_tables as $filepath) {
            $dump = basename($filepath);
            if (!empty($state['prepared'][$dump])) {
                if (is_file($filepath . self::PRISTINE_DUMP_SUFFIX)) {
                    @unlink($filepath . self::PRISTINE_DUMP_SUFFIX);
                }
                continue;
            }
            $pre_key = self::binary_preflight_fingerprint($backup_id, $dbfiles);
            if (!OCM_Backup::prepare_dump_for_staging_once($filepath, $wpdb->base_prefix, $staging_prefix, $source_prefix)) {
                OCM_SQL_Utils::drop_tables_with_prefix($staging_prefix);
                self::stop_restore_no_change(sprintf('Local restore stopped while preparing "%s" for staging. No changes were made.', $dump));
                return false;
            }
            $state['prepared'][$dump] = true;
            $steps['db']['dbImport'] = $state;
            update_option(self::OPTION_RESTORE_STEPS, $steps, false);
            if (is_file($filepath . self::PRISTINE_DUMP_SUFFIX)) {
                @unlink($filepath . self::PRISTINE_DUMP_SUFFIX);
            }
            self::rekey_binary_preflight_after_rewrite($pre_key, $backup_id, $dbfiles);

            if (is_object($worker) && method_exists($worker, 'has_batch_clock') && $worker->has_batch_clock() && $worker->time_exceeded()) {
                $worker->restart_task();
                return 'yield';
            }
        }

        // Import each SQL dump into staging tables
        $current_url = site_url();
        foreach ($sorted_tables as $filepath) {
            $dump = basename($filepath);
            if (!empty($state['imported'][$dump])) {
                continue;
            }
            if (!OCM_SQL_Utils::import($filepath, $source_prefix, $staging_prefix)) {
                OCM_SQL_Utils::drop_tables_with_prefix($staging_prefix);
                self::stop_restore_no_change(sprintf('Local restore stopped while importing "%s". No changes were made.', $dump));
                return false;
            }
            if ($dump === $source_prefix . 'options.sql') {
                if (!OCM_Backup::verify_staged_main_options($wpdb, $source_prefix, $staging_prefix, $current_url)) {
                    OCM_SQL_Utils::drop_tables_with_prefix($staging_prefix);
                    self::stop_restore_no_change('Local restore stopped: main options table failed siteurl/home validation. No changes were made.');
                    return false;
                }
                $state['site_url'] = (string) OCM_Backup::get_restore_site_url();
            }
            $state['imported'][$dump] = true;
            $steps['db']['dbImport'] = $state;
            update_option(self::OPTION_RESTORE_STEPS, $steps, false);
            One_Click_Migration::worker_heartbeat();

            if (is_object($worker) && method_exists($worker, 'has_batch_clock') && $worker->has_batch_clock() && $worker->time_exceeded()) {
                $worker->restart_task();
                return 'yield';
            }
        }

        if (!in_array($source_prefix . 'options.sql', $dump_names, true)) {
            $table_name = OCM_SQL_Utils::resolve_main_options_table($wpdb, $source_prefix, $staging_prefix);
            if ($table_name === false) {
                OCM_SQL_Utils::drop_tables_with_prefix($staging_prefix);
                self::stop_restore_no_change('Local restore stopped: main options table could not be resolved. No changes were made.');
                return false;
            }
            $db_url = $wpdb->get_results("SELECT option_value FROM `{$table_name}` WHERE option_name = 'siteurl'");
            if (!empty($db_url) && !empty($db_url[0]->option_value)) {
                $state['site_url'] = (string) $db_url[0]->option_value;
                $steps['db']['dbImport'] = $state;
                update_option(self::OPTION_RESTORE_STEPS, $steps, false);
            }
        }

        // URL rewrite only when the site's primary URL changed since backup.
        if (empty($state['url_done'])) {
            $old_url = isset($state['site_url']) ? (string) $state['site_url'] : '';
            if ($old_url === '' || $old_url === $current_url) {
                One_Click_Migration::write_to_local_log('SYSLOG: Source and destination site URLs match; skipping URL rewrite.');
            } else {
                $db_tool = new OCM_DB();
                $all_tables = OCM_DB::get_tables();
                $staging_tables = array();
                foreach (is_array($all_tables) ? $all_tables : array() as $t) {
                    if (is_string($t) && strpos($t, $staging_prefix) === 0) {
                        $staging_tables[] = $t;
                    }
                }
                if (!empty($staging_tables)) {
                    $result = $db_tool->search_replace_db($staging_tables, array(
                        'search' => $old_url,
                        'replace' => $current_url,
                        'log_summary' => false,
                    ));
                    if (!empty($result['errors']) || !empty($result['unpageable_table']) || !empty($result['oversized_table']) || !empty($result['paging_stuck_table'])) {
                        OCM_SQL_Utils::drop_tables_with_prefix($staging_prefix);
                        self::stop_restore_no_change('Local restore stopped while updating URLs on staging tables. No changes were made.');
                        return false;
                    }
                }
                One_Click_Migration::write_to_local_log('SYSLOG: Finished updating URLs in the database');
            }
            $state['url_done'] = true;
            $steps['db']['dbImport'] = $state;
            update_option(self::OPTION_RESTORE_STEPS, $steps, false);
        }

        // Promote staging tables atomically (preserves all ocm_* / ocm_local_* options and cron).
        $review_options = OCM_Backup::snapshot_destination_review_options($wpdb);
        if (!OCM_SQL_Utils::promote_staging_tables($staging_prefix, $wpdb->base_prefix, $source_suffixes)) {
            OCM_SQL_Utils::drop_tables_with_prefix($staging_prefix);
            self::stop_restore_no_change('Local restore stopped: promoting staging tables failed. Live tables remain unchanged.');
            return false;
        }
        OCM_Backup::restore_destination_review_options($wpdb, $review_options);
        delete_option(OCM_SQL_Utils::OPTION_STAGING_PREFIX);
        One_Click_Migration::write_to_local_log('Database has been restored');
        return true;
    }

    private static function apply_file_swaps($workspace, array $selected_parts, $backup_id, array $manifest, $worker = null)
    {
        self::isolate_plugins_for_restore();

        $steps = get_option(self::OPTION_RESTORE_STEPS, array());
        if (!is_array($steps)) {
            $steps = array();
        }

        foreach (array('themes', 'uploads') as $part) {
            if (!in_array($part, $selected_parts, true)) {
                continue;
            }
            if (!empty($steps[$part]['swapped'])) {
                continue;
            }
            $extract_path = rtrim($workspace, '/\\') . '/' . $part;
            if (!is_dir($extract_path)) {
                $steps[$part]['swapped'] = true;
                update_option(self::OPTION_RESTORE_STEPS, $steps, false);
                continue;
            }
            if ($part === 'uploads' && !OCM_Backup::directory_contains_files($extract_path)) {
                One_Click_Migration::write_to_local_log('Notice: Backup uploads archive is empty; leaving destination uploads unchanged.');
                OCM_Backup::deleteDirectoryBestEffort($extract_path);
                $steps[$part]['swapped'] = true;
                update_option(self::OPTION_RESTORE_STEPS, $steps, false);
                continue;
            }

            $dest = WP_CONTENT_DIR . '/' . $part;
            One_Click_Migration::write_to_local_log(sprintf('Restoring "%s" to directory "%s"', ucfirst($part), $dest));
            if (!OCM_Backup::replaceDirectoryFromExtract($extract_path, $dest, $part)) {
                self::restore_isolated_plugins();
                self::fail_restore(sprintf('Could not replace "%s" directory (check permissions).', $dest));
                return false;
            }
            $steps[$part]['swapped'] = true;
            update_option(self::OPTION_RESTORE_STEPS, $steps, false);
            One_Click_Migration::write_to_local_log(sprintf('%s have been restored', ucfirst($part)));
        }

        if (in_array('plugins', $selected_parts, true) && empty($steps['plugins']['swapped'])) {
            $extract_plugins_dir = rtrim($workspace, '/\\') . '/plugins';
            if (is_dir($extract_plugins_dir)) {
                // Restored folders are moved out of the workspace, so the live
                // folders are cleared once only: a worker resuming after the
                // moves began must not delete what is already restored.
                if (empty($steps['plugins']['live_cleared'])) {
                    $live_plugins = glob(WP_CONTENT_DIR . '/plugins/*', GLOB_ONLYDIR);
                    if (is_array($live_plugins)) {
                        foreach ($live_plugins as $plugin_dir) {
                            if (basename($plugin_dir) === '1-click-migration') {
                                continue;
                            }
                            OCM_Backup::deleteDirectoryBestEffort($plugin_dir);
                        }
                    }
                    $steps['plugins']['live_cleared'] = true;
                    update_option(self::OPTION_RESTORE_STEPS, $steps, false);
                }
                $extracted_plugins = glob($extract_plugins_dir . '/*', GLOB_ONLYDIR);
                if (is_array($extracted_plugins)) {
                    foreach ($extracted_plugins as $plugin_dir) {
                        $plugin_name = basename($plugin_dir);
                        if ($plugin_name === '1-click-migration') {
                            continue;
                        }
                        $target = WP_CONTENT_DIR . '/plugins/' . $plugin_name;
                        if (!OCM_Backup::replaceDirectoryFromExtract($plugin_dir, $target, 'plugin ' . $plugin_name)) {
                            self::restore_isolated_plugins();
                            self::fail_restore(sprintf('Could not restore plugin directory "%s".', $plugin_name));
                            return false;
                        }
                    }
                }
            }
            $steps['plugins']['swapped'] = true;
            update_option(self::OPTION_RESTORE_STEPS, $steps, false);
            One_Click_Migration::write_to_local_log('Plugins have been restored');
        }

        self::restore_isolated_plugins();
        return true;
    }

    private static function isolate_plugins_for_restore()
    {
        if (get_option(self::OPTION_RESTORE_PLUGINS_ISOLATED)) {
            return;
        }
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('active_plugins', 'options');
            wp_cache_delete('alloptions', 'options');
        }
        $active = get_option('active_plugins', array());
        if (!is_array($active)) {
            $active = array();
        }
        $own = defined('OCM_MAIN_FILE') ? plugin_basename(OCM_MAIN_FILE) : '1-click-migration/one-click-migration.php';
        $ocm_only = array($own);
        if ($active !== $ocm_only) {
            update_option(self::OPTION_RESTORE_DEACTIVATED_PLUGINS, $active, false);
            update_option('active_plugins', $ocm_only, true);
        }
        update_option(self::OPTION_RESTORE_PLUGINS_ISOLATED, 1, false);
    }

    private static function restore_isolated_plugins()
    {
        $saved = get_option(self::OPTION_RESTORE_DEACTIVATED_PLUGINS, null);
        if (is_array($saved)) {
            $own = defined('OCM_MAIN_FILE') ? plugin_basename(OCM_MAIN_FILE) : '1-click-migration/one-click-migration.php';
            if (!in_array($own, $saved, true)) {
                $saved[] = $own;
            }
            update_option('active_plugins', $saved, true);
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete('active_plugins', 'options');
                wp_cache_delete('alloptions', 'options');
            }
        }
        delete_option(self::OPTION_RESTORE_DEACTIVATED_PLUGINS);
        delete_option(self::OPTION_RESTORE_PLUGINS_ISOLATED);
    }

    /**
     * Deactivating the plugin while a restore has the other plugins switched
     * off: WordPress writes its own copy of active_plugins after the
     * deactivation hook, which would undo restore_isolated_plugins(). Call
     * this before the reset; it hands WordPress the saved list (without this
     * plugin) to write instead.
     *
     * @return void
     */
    public static function keep_isolated_plugins_through_deactivation()
    {
        $saved = get_option(self::OPTION_RESTORE_DEACTIVATED_PLUGINS, null);
        if (!is_array($saved) || !$saved || !function_exists('add_filter')) {
            return;
        }
        $own = defined('OCM_MAIN_FILE') ? plugin_basename(OCM_MAIN_FILE) : '1-click-migration/one-click-migration.php';
        $keep = array_values(array_diff($saved, array($own)));
        $armed = false;
        add_filter('pre_update_option_active_plugins', static function ($value) use ($keep, $own, &$armed) {
            // Skip writes that still list this plugin (the reset's own restore).
            if ($armed || !is_array($value) || in_array($own, $value, true)) {
                return $value;
            }
            $armed = true;
            return array_values(array_unique(array_merge($value, $keep)));
        });
    }

    private static function finish_restore($workspace, $run_id)
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_ACTIVE_RESTORE_RUN, 'options');
        }
        $run = get_option(self::OPTION_ACTIVE_RESTORE_RUN, array());

        self::cleanup_restore_workspaces($workspace);
        if (function_exists('wp_cache_flush')) {
            @wp_cache_flush();
        }
        global $wp_rewrite;
        if (isset($wp_rewrite) && is_object($wp_rewrite) && method_exists($wp_rewrite, 'init')) {
            $wp_rewrite->init();
        }
        if (function_exists('save_mod_rewrite_rules')) {
            @save_mod_rewrite_rules();
        }

        One_Click_Migration::disable_maintenance_mode();

        self::record_run_activity(
            'restore',
            'completed',
            is_array($run) ? $run : array(),
            __('Local restore completed.', '1-click-migration')
        );

        update_option(self::OPTION_ACTION_RESTORE, false, false);
        update_option(self::OPTION_IS_STOPPED, true, false);
        self::end_restore_run();
        self::delete_restore_options();
        self::reset_watchdog();

        One_Click_Migration::write_to_local_log('Local restore completed.');
    }

    private static function stop_restore_no_change($message)
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_ACTIVE_RESTORE_RUN, 'options');
        }
        $run = get_option(self::OPTION_ACTIVE_RESTORE_RUN, array());

        self::restore_isolated_plugins();
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) {
            OCM_SQL_Utils::drop_stale_temp_tables($wpdb, null, isset($wpdb->base_prefix) ? $wpdb->base_prefix : null);
        }
        self::cleanup_restore_workspaces();
        One_Click_Migration::run_in_local_log(static function () use ($message) {
            One_Click_Migration::disable_maintenance_mode();
            One_Click_Migration::write_to_local_log($message);
        });
        self::record_run_activity('restore', 'failed', is_array($run) ? $run : array(), $message);
        update_option(self::OPTION_ACTION_RESTORE, false, false);
        update_option(self::OPTION_IS_STOPPED, true, false);
        self::end_restore_run();
        self::delete_restore_options();
        self::reset_watchdog();
        return OCM_BackgroundLocalRestore::RETURN_TYPE_STOP_PROCESS;
    }

    public static function fail_restore($message)
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_ACTIVE_RESTORE_RUN, 'options');
        }
        $run = get_option(self::OPTION_ACTIVE_RESTORE_RUN, array());

        self::restore_isolated_plugins();
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) {
            OCM_SQL_Utils::drop_stale_temp_tables($wpdb, null, isset($wpdb->base_prefix) ? $wpdb->base_prefix : null);
        }
        self::cleanup_restore_workspaces();
        One_Click_Migration::run_in_local_log(static function () use ($message) {
            One_Click_Migration::disable_maintenance_mode();
            One_Click_Migration::write_to_local_log('Error: ' . $message);
            One_Click_Migration::write_to_local_log('Local restore failed: ' . $message);
        });
        self::record_run_activity('restore', 'failed', is_array($run) ? $run : array(), $message);
        update_option(self::OPTION_ACTION_RESTORE, false, false);
        update_option(self::OPTION_IS_STOPPED, true, false);
        self::end_restore_run();
        self::delete_restore_options();
        self::reset_watchdog();
        return OCM_BackgroundLocalRestore::RETURN_TYPE_STOP_PROCESS;
    }

    private static function cleanup_restore_workspaces($workspace = '')
    {
        if (is_string($workspace) && $workspace !== '' && is_dir($workspace)) {
            OCM_Backup::deleteDirectoryBestEffort($workspace);
        }
        foreach (OCM_Backup::existing_restore_work_paths() as $dir) {
            if (is_dir($dir)) {
                OCM_Backup::deleteDirectoryBestEffort($dir);
            }
        }
    }

    private static function end_restore_run()
    {
        self::clear_queue_rows_for_action('local_restore');
        delete_option(self::OPTION_ACTIVE_RESTORE_RUN);
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::OPTION_ACTIVE_RESTORE_RUN, 'options');
        }
        self::$restore_run_ended = true;
    }

    private static function clear_queue_rows_for_action($action_suffix)
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !isset($wpdb->options)) {
            return;
        }
        $table = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
        $column = is_multisite() ? 'meta_key' : 'option_name';
        $like = $wpdb->esc_like('wp_ocm_background_' . $action_suffix . '_batch_') . '%';
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE {$column} LIKE %s", $like));
    }

    private static function dispatch_worker($process, $label)
    {
        $can_loopback = One_Click_Migration::run_in_local_log(static function () use ($label) {
            return OCM_Backup::admin_ajax_loopback_check_passed($label);
        });
        if ($can_loopback) {
            delete_option(self::OPTION_DISPATCH_BLOCKED);
            $response = $process->dispatch();
            if ($response !== false && !is_wp_error($response)) {
                return 'async';
            }
        }

        update_option(self::OPTION_DISPATCH_BLOCKED, 1, false);
        One_Click_Migration::write_to_local_log(sprintf(
            'Notice: Loopback dispatch unavailable for %s; running in direct processing mode.',
            $label
        ));
        if (function_exists('fastcgi_finish_request') && defined('DOING_AJAX') && DOING_AJAX) {
            @fastcgi_finish_request();
        }
        $process->trigger_processing();
        return 'direct';
    }

    public static function schedule_watchdog($delay = 60)
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }
        if (wp_next_scheduled(self::WATCHDOG_HOOK)) {
            return;
        }
        wp_schedule_single_event(time() + max(1, (int) $delay), self::WATCHDOG_HOOK);
    }

    public static function run_worker_watchdog()
    {
        if (!self::is_running()) {
            return;
        }
        self::schedule_watchdog(60);
        self::maybe_revive_stalled_worker(true);
    }

    private static function maybe_revive_stalled_worker($from_cron = false)
    {
        if (get_option(self::OPTION_IS_STOPPED)) {
            return;
        }
        if (!$from_cron && !(defined('REST_REQUEST') && REST_REQUEST) && !wp_doing_ajax()) {
            return;
        }
        $backup_active = (bool) get_option(self::OPTION_ACTION_BACKUP);
        $restore_active = (bool) get_option(self::OPTION_ACTION_RESTORE);
        if (!$backup_active && !$restore_active) {
            return;
        }

        $process = $restore_active ? One_Click_Migration::$process_local_restore : One_Click_Migration::$process_local_backup;
        if (!is_object($process) || !method_exists($process, 'trigger_processing')) {
            return;
        }

        $now = time();
        if ($backup_active && !$restore_active) {
            $starting = (int) get_option(self::OPTION_BACKUP_STARTING, 0);
            if ($starting > 0 && ($now - $starting) < self::BACKUP_START_GRACE) {
                return;
            }
        }

        $threshold = get_option(self::OPTION_DISPATCH_BLOCKED) ? 25 : 45;
        $heartbeat = (int) get_option(self::OPTION_WORKER_HEARTBEAT, 0);
        if (0 === $heartbeat) {
            update_option(self::OPTION_WORKER_HEARTBEAT, $now, false);
            return;
        }
        if (($now - $heartbeat) <= $threshold) {
            return;
        }
        if ($restore_active && OCM_BackgroundLocalRestore::is_worker_file_lock_held()) {
            return;
        }

        $last_pump = (int) get_option(self::OPTION_WATCHDOG_LAST, 0);
        if (($now - $last_pump) <= 20) {
            return;
        }
        update_option(self::OPTION_WATCHDOG_LAST, $now, false);

        if (method_exists($process, 'is_queue_empty') && $process->is_queue_empty()) {
            // A backup touches its heartbeat before its last queue row goes and
            // while it publishes. Read it again now that the queue is known to
            // be empty: a fresh one means the run is finishing, not dead.
            if (function_exists('wp_cache_delete')) {
                wp_cache_delete(self::OPTION_WORKER_HEARTBEAT, 'options');
            }
            if (($now - (int) get_option(self::OPTION_WORKER_HEARTBEAT, 0)) <= $threshold) {
                return;
            }
            // Queue drained without completing — mark stopped cleanly.
            $orphan_run = get_option($restore_active ? self::OPTION_ACTIVE_RESTORE_RUN : self::OPTION_ACTIVE_BACKUP_RUN, array());
            $orphan_msg = 'Local operation stopped unexpectedly because no queued work remained.';
            One_Click_Migration::write_to_local_log('Error: ' . $orphan_msg);
            update_option(self::OPTION_ACTION_BACKUP, false, false);
            update_option(self::OPTION_ACTION_RESTORE, false, false);
            update_option(self::OPTION_IS_STOPPED, true, false);
            One_Click_Migration::disable_maintenance_mode();
            self::delete_runtime_options();
            self::record_run_activity(
                $restore_active ? 'restore' : 'backup',
                'failed',
                is_array($orphan_run) ? $orphan_run : array(),
                $orphan_msg
            );
            return;
        }

        $revivals = (int) get_option(self::OPTION_REVIVAL_COUNT, 0) + 1;
        update_option(self::OPTION_REVIVAL_COUNT, $revivals, false);
        One_Click_Migration::write_to_local_log(sprintf(
            'SYSLOG: Resuming stalled local worker via direct processing (revival #%d%s).',
            $revivals,
            $from_cron ? ', cron' : ''
        ));
        $process->trigger_processing();
    }

    /**
     * Copy lines belonging to the current run from ocm_local_backup.log to
     * <backup>/backup.log.
     *
     * @param string $run_id
     * @param string $target_path
     * @return void
     */
    private static function write_run_log_snapshot($run_id, $target_path)
    {
        $file = defined('OCM_LOCAL_LOG_FILE') ? OCM_LOCAL_LOG_FILE : '';
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            return;
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines) || empty($lines)) {
            return;
        }
        $marker = self::RUN_MARKER_PREFIX . $run_id;
        $start = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (strpos($lines[$i], $marker) !== false) {
                $start = $i;
                break;
            }
        }
        $slice = implode("\n", array_slice($lines, $start)) . "\n";
        @file_put_contents($target_path, $slice);
        @chmod($target_path, 0600);
    }

    /**
     * Read current run's log lines after the latest RUN_MARKER_PREFIX.
     *
     * @return string[]
     */
    private static function current_run_log_messages()
    {
        $file = defined('OCM_LOCAL_LOG_FILE') ? OCM_LOCAL_LOG_FILE : '';
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            return array();
        }
        $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines) || empty($lines)) {
            return array();
        }
        $start = 0;
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (strpos($lines[$i], self::RUN_MARKER_PREFIX) !== false) {
                $start = $i + 1;
                break;
            }
        }
        $messages = array();
        foreach (array_slice($lines, $start) as $raw) {
            $pos = strpos($raw, ' - ');
            $msg = $pos !== false ? trim(substr($raw, $pos + 3)) : trim($raw);
            if ($msg !== '') {
                $messages[] = $msg;
            }
        }
        return $messages;
    }

    /**
     * Return progress status strictly from the local log (never touches cloud log).
     *
     * @return array
     */
    public static function get_progress()
    {
        self::maybe_revive_stalled_worker(false);

        $action = self::active_action();
        $is_stopped = (bool) get_option(self::OPTION_IS_STOPPED, false);
        $messages = self::current_run_log_messages();

        $last_visible = '';
        $last_pct = $action !== '' ? '1%' : '0%';
        $failed = false;
        $completed = false;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];
            if (strpos($msg, 'SYSLOG:') === 0 || strpos($msg, 'Timeout approaching.') === 0) {
                continue;
            }
            if ($last_visible === '') {
                $last_visible = $msg;
            }
            foreach (self::$progress_map as $entry) {
                if (strpos($msg, $entry[0]) !== false) {
                    $last_pct = $entry[1];
                    $last_visible = $msg;
                    if (strpos($msg, 'Local backup completed.') !== false || strpos($msg, 'Local restore completed.') !== false) {
                        $completed = true;
                    }
                    if (strpos($msg, 'Local backup failed:') !== false || strpos($msg, 'Local restore failed:') !== false || strpos($msg, 'Local restore stopped') !== false || strpos($msg, 'Error:') === 0) {
                        $failed = true;
                    }
                    break 2;
                }
            }
        }

        if ($last_visible === '') {
            $last_visible = $action === 'restore'
                ? __('Starting local restore…', '1-click-migration')
                : ($action === 'backup'
                    ? __('Starting local backup…', '1-click-migration')
                    : __('Idle — ready to run a local backup or restore.', '1-click-migration'));
        }

        return array(
            'text' => esc_html($last_visible),
            'value' => $last_pct,
            'action' => $action,
            'active' => ($action !== ''),
            'isStopped' => $is_stopped || $failed,
            'failed' => $failed,
            'completed' => $completed,
            'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false),
        );
    }

    /**
     * Cancel and reset any in-progress local backup or restore without deleting
     * completed local backups or user settings.
     *
     * @return void
     */
    public static function cancel_and_reset()
    {
        $was_restore = (bool) get_option(self::OPTION_ACTION_RESTORE);
        $was_backup = (bool) get_option(self::OPTION_ACTION_BACKUP);
        $active_run = $was_restore
            ? get_option(self::OPTION_ACTIVE_RESTORE_RUN, array())
            : ($was_backup ? get_option(self::OPTION_ACTIVE_BACKUP_RUN, array()) : array());

        One_Click_Migration::run_in_local_log(static function () {
            One_Click_Migration::write_to_local_log('Local operation cancelled by user.');
            if (get_option('ocm_maintenance_mode', false)) {
                One_Click_Migration::disable_maintenance_mode();
            }
        });

        foreach (array(One_Click_Migration::$process_local_backup, One_Click_Migration::$process_local_restore) as $proc) {
            if (is_object($proc)) {
                $proc->unlock_process();
                $proc->cancel_all_process();
                $proc->cancel_scheduled_event();
                $proc->clear_cancel_status();
            }
        }

        OCM_BackgroundLocalRestore::release_worker_file_lock();
        delete_option('wp_ocm_background_local_backup_status');
        delete_option('wp_ocm_background_local_restore_status');
        delete_site_transient('wp_ocm_background_local_backup_process_lock');
        delete_site_transient('wp_ocm_background_local_restore_process_lock');
        self::clear_queue_rows_for_action('local_backup');
        self::clear_queue_rows_for_action('local_restore');

        self::restore_isolated_plugins();
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) {
            OCM_SQL_Utils::drop_stale_temp_tables($wpdb, null, isset($wpdb->base_prefix) ? $wpdb->base_prefix : null);
        }

        self::cleanup_restore_workspaces();
        OCM_Local_Storage::cleanup_stale_staging_dirs();

        self::delete_runtime_options();
        update_option(self::OPTION_IS_STOPPED, true, false);

        if ($was_restore || $was_backup) {
            self::record_run_activity(
                $was_restore ? 'restore' : 'backup',
                'cancelled',
                is_array($active_run) ? $active_run : array(),
                __('Cancelled by user.', '1-click-migration')
            );
        }
    }

    // -------------------------------------------------------------------------
    // AJAX Endpoints (manage_options + check_ajax_referer)
    // -------------------------------------------------------------------------

    public static function ajax_start_backup()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', '1-click-migration')), 403);
        }
        check_ajax_referer('ocm_local_start_backup', 'nonce');

        $parts = isset($_POST['parts']) && is_array($_POST['parts']) ? wp_unslash($_POST['parts']) : array();
        $label = isset($_POST['label']) ? sanitize_text_field(wp_unslash($_POST['label'])) : '';

        $result = self::start_backup($parts, $label, 'manual', true);
        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            $status = ($code === 'operation_in_progress') ? 409 : (($code === 'insufficient_disk_space') ? 507 : 400);
            wp_send_json_error(array('message' => $result->get_error_message(), 'code' => $code), $status);
        }
        wp_send_json_success($result);
    }

    public static function ajax_start_restore()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', '1-click-migration')), 403);
        }
        check_ajax_referer('ocm_local_start_restore', 'nonce');

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';
        $parts = isset($_POST['parts']) && is_array($_POST['parts']) ? wp_unslash($_POST['parts']) : array();

        $result = self::start_restore($backup_id, $parts, true);
        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            $status = ($code === 'operation_in_progress') ? 409 : (($code === 'insufficient_disk_space') ? 507 : 400);
            wp_send_json_error(array('message' => $result->get_error_message(), 'code' => $code), $status);
        }
        wp_send_json_success($result);
    }

    public static function ajax_delete_backup()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', '1-click-migration')), 403);
        }
        check_ajax_referer('ocm_local_delete_backup', 'nonce');

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';
        $res = OCM_Local_Storage::delete_backup($backup_id, self::active_restore_backup_id());
        if (is_wp_error($res)) {
            wp_send_json_error(array('message' => $res->get_error_message()), 400);
        }
        wp_send_json_success(array(
            'backups' => OCM_Local_Storage::list_backups(),
            'storage' => OCM_Local_Storage::storage_summary(),
        ));
    }

    public static function ajax_save_settings()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', '1-click-migration')), 403);
        }
        check_ajax_referer('ocm_local_save_settings', 'nonce');

        $schedule_input = array(
            'enabled' => !empty($_POST['enabled']),
            'frequency' => isset($_POST['frequency']) ? sanitize_text_field(wp_unslash($_POST['frequency'])) : 'daily',
            'time' => isset($_POST['time']) ? sanitize_text_field(wp_unslash($_POST['time'])) : '02:00',
            'day_of_week' => isset($_POST['day_of_week']) ? (int) $_POST['day_of_week'] : 0,
            'day_of_month' => isset($_POST['day_of_month']) ? (int) $_POST['day_of_month'] : 1,
            'parts' => isset($_POST['parts']) && is_array($_POST['parts']) ? wp_unslash($_POST['parts']) : array(),
        );
        $schedule = OCM_Local_Scheduler::save_schedule($schedule_input);
        if (is_wp_error($schedule)) {
            wp_send_json_error(array('message' => $schedule->get_error_message()), 400);
        }

        $storage_input = array(
            'keep_last' => isset($_POST['keep_last']) ? (int) $_POST['keep_last'] : OCM_Local_Storage::DEFAULT_KEEP_LAST,
            'min_free_space_mb' => isset($_POST['min_free_space_mb']) ? (int) $_POST['min_free_space_mb'] : OCM_Local_Storage::DEFAULT_MIN_FREE_SPACE_MB,
        );
        $storage = OCM_Local_Storage::save_settings($storage_input);

        // If user reduced keep_last, prune old backups now (never while a restore is active).
        OCM_Local_Storage::prune_backups($storage['keep_last'], self::active_restore_backup_id());

        $next_ts = OCM_Local_Scheduler::next_scheduled_timestamp();
        wp_send_json_success(array(
            'schedule' => $schedule,
            'storage' => OCM_Local_Storage::storage_summary(),
            'backups' => OCM_Local_Storage::list_backups(),
            'next_run_ts' => $next_ts,
            'next_run_formatted' => OCM_Local_Scheduler::format_timestamp($next_ts),
            'status' => OCM_Local_Scheduler::get_status(),
        ));
    }

    public static function ajax_list_backups()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', '1-click-migration')), 403);
        }
        check_ajax_referer('ocm_local_list_backups', 'nonce');

        $next_ts = OCM_Local_Scheduler::next_scheduled_timestamp();
        $log_exists = defined('OCM_LOCAL_LOG_FILE') && is_file(OCM_LOCAL_LOG_FILE);

        wp_send_json_success(array(
            'backups' => OCM_Local_Storage::list_backups(),
            'storage' => OCM_Local_Storage::storage_summary(),
            'schedule' => OCM_Local_Scheduler::get_schedule(),
            'status' => OCM_Local_Scheduler::get_status(),
            'next_run_ts' => $next_ts,
            'next_run_formatted' => OCM_Local_Scheduler::format_timestamp($next_ts),
            'log_exists' => $log_exists,
            'activity' => self::get_activity_log(),
        ));
    }

    public static function ajax_get_log()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', '1-click-migration')), 403);
        }
        check_ajax_referer('ocm_local_get_log', 'nonce');

        $backup_id = isset($_POST['backup_id']) ? sanitize_text_field(wp_unslash($_POST['backup_id'])) : '';
        if ($backup_id !== '') {
            if (!OCM_Local_Storage::is_valid_backup_id($backup_id)) {
                wp_send_json_error(array('message' => __('Invalid backup ID.', '1-click-migration')), 400);
            }
            $dir = OCM_Local_Storage::backup_dir($backup_id);
            if ($dir === '' || !is_dir($dir)) {
                wp_send_json_error(array('message' => __('Local backup not found.', '1-click-migration')), 404);
            }
            $log = self::read_log_tail($dir . '/backup.log', 500);
            wp_send_json_success(array(
                'source' => 'backup',
                'backup_id' => $backup_id,
                'log' => $log,
            ));
        }

        $log_file = defined('OCM_LOCAL_LOG_FILE') ? OCM_LOCAL_LOG_FILE : '';
        wp_send_json_success(array(
            'source' => 'global',
            'log' => self::read_log_tail($log_file, self::LOG_VIEWER_DEFAULT_LINES),
            'activity' => self::get_activity_log(),
        ));
    }

    public static function ajax_clear_log()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', '1-click-migration')), 403);
        }
        check_ajax_referer('ocm_local_clear_log', 'nonce');

        $res = self::clear_local_log();
        if (is_wp_error($res)) {
            $status = ($res->get_error_code() === 'operation_in_progress') ? 409 : 500;
            wp_send_json_error(array('message' => $res->get_error_message()), $status);
        }

        $log_file = defined('OCM_LOCAL_LOG_FILE') ? OCM_LOCAL_LOG_FILE : '';
        wp_send_json_success(array(
            'cleared' => true,
            'log_exists' => false,
            'log' => self::read_log_tail($log_file, self::LOG_VIEWER_DEFAULT_LINES),
        ));
    }

    public static function ajax_clear_activity()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', '1-click-migration')), 403);
        }
        check_ajax_referer('ocm_local_clear_activity', 'nonce');

        self::clear_activity_log();
        wp_send_json_success(array(
            'activity' => array(),
        ));
    }

    public static function ajax_cancel()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', '1-click-migration')), 403);
        }
        check_ajax_referer('ocm_local_cancel', 'nonce');

        self::cancel_and_reset();
        wp_send_json_success(array('cancelled' => true));
    }
}
