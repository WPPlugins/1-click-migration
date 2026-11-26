<?php
/**
 * A database change the user can undo: the shared part of Search & Replace
 * and Site Cleanup.
 *
 * A run takes a database-only local backup first, then does its work in
 * short steps that the open admin page asks for one after another (each
 * step is a new request, time-boxed inside the host's PHP limit). A MySQL
 * named lock keeps two tabs from working at once. Stop ends it; Undo
 * restores the database from the backup through the normal local restore;
 * Done hides the finished run.
 *
 * A subclass sets the constants below and implements work_step(), which
 * does one step of work and returns the run (saved, or ended with finish()).
 */

namespace OCM;

use WP_Error;

abstract class OCM_Guarded_Run
{
    // Subclasses set these.
    /** Option holding the current or last run. An ocm_ option, so no run ever changes it. */
    const OPTION_RUN = '';
    /** MySQL named lock held while a step works. */
    const STEP_LOCK = '';
    /** The state while the run changes the database, after its backup. */
    const STATE_WORKING = 'working';
    /** Activity history type and run id prefix. */
    const ACTIVITY_TYPE = '';
    const RUN_ID_PREFIX = 'run_';
    /** Prefix of this run's local log lines. */
    const LOG_NAME = '';

    const STATE_BACKING_UP = 'backing_up';
    const STATE_DONE = 'done';
    const STATE_FAILED = 'failed';
    const STATE_STOPPED = 'stopped';

    const STEP_SECONDS = 15;

    /** Every kind of run, so one never starts while another works. */
    const RUN_CLASSES = array('OCM_Search_Replace', 'OCM_Site_Cleanup');

    /** @var float|null Tests set a step length; null uses the host's limits. */
    public static $step_seconds_override = null;

    /**
     * One step of work. Returns the run, saved with save_run() or ended
     * with finish().
     *
     * @param array $run
     * @return array
     */
    abstract protected static function work_step(array $run);

    /**
     * What the screen shows when the run worked, e.g. "Replaced in 12 rows."
     */
    abstract protected static function done_message(array $run);

    /** The label of the backup and of the activity history entry. */
    abstract protected static function run_label(array $run);

    /**
     * Messages that differ per kind of run, keyed by:
     * backup_failed, stopped_before, stopped_during, interrupted, db_error.
     *
     * @return array<string, string>
     */
    abstract protected static function messages();

    /** Fields only this kind of run shows. */
    protected static function public_extra(array $run)
    {
        return array();
    }

    /** 0-100 while working. */
    protected static function progress_percent(array $run)
    {
        return 0;
    }

    /** Bytes removed, for the activity history and the report size range. */
    protected static function size_bytes(array $run)
    {
        return 0;
    }

    // ------------------------------------------------------------------
    // Run record
    // ------------------------------------------------------------------

    /** @return array|null */
    public static function get_run()
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(static::OPTION_RUN, 'options');
        }
        $run = get_option(static::OPTION_RUN, null);
        return is_array($run) && isset($run['id'], $run['state']) ? $run : null;
    }

    protected static function save_run(array $run)
    {
        $run['updated_at'] = time();
        update_option(static::OPTION_RUN, $run, false);
        return $run;
    }

    /** Whether this run is changing the database right now (past its backup). */
    public static function is_working()
    {
        $run = static::get_run();
        return $run !== null && $run['state'] === static::STATE_WORKING;
    }

    /** Whether this run is backing up or changing the database. */
    public static function is_running()
    {
        $run = static::get_run();
        return $run !== null && in_array($run['state'], array(self::STATE_BACKING_UP, static::STATE_WORKING), true);
    }

    /** Whether any kind of run is changing the database. Backups and restores wait for it. */
    public static function any_working()
    {
        foreach (self::RUN_CLASSES as $class) {
            $class = __NAMESPACE__ . '\\' . $class;
            if (class_exists($class) && $class::is_working()) {
                return true;
            }
        }
        return false;
    }

    /** Whether any kind of run is backing up or changing the database. */
    public static function any_running()
    {
        foreach (self::RUN_CLASSES as $class) {
            $class = __NAMESPACE__ . '\\' . $class;
            if (class_exists($class) && $class::is_running()) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Start and steps
    // ------------------------------------------------------------------

    /**
     * Refuse while anything else runs, take the database backup that Undo
     * restores, and save the run waiting for it.
     *
     * @param array $fields This kind of run's own fields.
     * @return array|WP_Error The run as the screen shows it.
     */
    protected static function begin(array $fields)
    {
        if (self::any_running() || OCM_Local_Backup::any_operation_running()) {
            return new WP_Error('operation_in_progress', __('Another backup, restore, search & replace or cleanup is already running on this site.', '1-click-migration'));
        }

        // An undo backup: "Keep last N" counts these apart from the site's own backups.
        $backup = OCM_Local_Backup::start_backup(array('db'), static::run_label($fields), 'manual', true, OCM_Local_Storage::PURPOSE_UNDO);
        if (is_wp_error($backup)) {
            return $backup;
        }

        $run = static::save_run(array_merge($fields, array(
            'id' => static::RUN_ID_PREFIX . (function_exists('wp_generate_password') ? wp_generate_password(12, false, false) : bin2hex(random_bytes(6))),
            'state' => self::STATE_BACKING_UP,
            'backup_id' => isset($backup['backup_id']) ? (string) $backup['backup_id'] : '',
            'warnings' => array(),
            'message' => '',
            'started_at' => time(),
            'finished_at' => 0,
        )));
        static::log_line(' SYSLOG: ' . static::LOG_NAME . ': started, waiting for the database backup ' . $run['backup_id']);
        return static::public_run($run);
    }

    /**
     * Advance the run by one step: wait for the backup, or work for a few
     * seconds. The screen calls this until the run ends.
     *
     * @return array
     */
    public static function step()
    {
        $run = static::get_run();
        if ($run === null) {
            return array('state' => 'idle');
        }

        if ($run['state'] === self::STATE_BACKING_UP) {
            $run = static::check_backup($run);
        }
        if ($run['state'] !== static::STATE_WORKING) {
            return static::public_run($run);
        }

        global $wpdb;
        $got = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', static::STEP_LOCK));
        if (!$got) {
            // Another tab or request is working right now.
            return static::public_run($run, array('busy' => true));
        }
        try {
            $run = static::work_step($run);
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', static::STEP_LOCK));
        }
        return static::public_run($run);
    }

    /**
     * While the backup runs, show its progress; once it is saved, move on to
     * the work. A backup that failed or was stopped ends the run unchanged.
     */
    protected static function check_backup(array $run)
    {
        // The backup worker is another request: read its flag fresh.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(OCM_Local_Backup::OPTION_ACTION_BACKUP, 'options');
            wp_cache_delete(OCM_Local_Backup::OPTION_ACTION_RESTORE, 'options');
        }
        if (OCM_Local_Backup::active_action() === 'backup') {
            $progress = OCM_Local_Backup::get_progress(); // also revives a stalled worker
            $run['backup_progress'] = isset($progress['value']) ? (string) $progress['value'] : '';
            $run['backup_text'] = isset($progress['text']) ? (string) $progress['text'] : '';
            return $run;
        }
        $manifest = $run['backup_id'] !== '' ? OCM_Local_Storage::read_manifest($run['backup_id']) : null;
        if (!is_array($manifest) || (isset($manifest['status']) && $manifest['status'] !== 'complete')) {
            $messages = static::messages();
            return static::finish($run, self::STATE_FAILED, $messages['backup_failed'], 'backup_failed');
        }
        $run['state'] = static::STATE_WORKING;
        unset($run['backup_progress'], $run['backup_text']);
        static::log_line(' SYSLOG: ' . static::LOG_NAME . ': backup ' . $run['backup_id'] . ' saved, working');
        return static::save_run($run);
    }

    /**
     * After a step's work: when Stop was pressed meanwhile, the stopped run
     * (with $changes merged in) to save instead; otherwise null.
     *
     * @param array $run
     * @param array $changes Fields the step updated, e.g. its counts.
     * @return array|null
     */
    protected static function stopped_meanwhile(array $run, array $changes)
    {
        $latest = static::get_run();
        if ($latest !== null && $latest['id'] === $run['id'] && $latest['state'] === self::STATE_STOPPED) {
            return static::save_run(array_merge($latest, $changes));
        }
        return null;
    }

    /**
     * End the run, record it in the activity history (and, if the site
     * allowed it, the anonymous report), and clear the object cache so the
     * site reads the new values.
     */
    protected static function finish(array $run, $state, $message, $error_code)
    {
        $run['state'] = $state;
        $run['clean'] = true;
        $run['finished_at'] = time();
        if ($message === '' && $state === self::STATE_DONE) {
            $message = static::done_message($run);
        }
        $run['message'] = $message;
        $run = static::save_run($run);

        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        static::log_line(' SYSLOG: ' . static::LOG_NAME . ': ' . $state . ($error_code !== '' ? ' (' . $error_code . ')' : ''));

        OCM_Local_Backup::record_activity(array(
            'id' => $run['id'],
            'type' => static::ACTIVITY_TYPE,
            'trigger' => 'manual',
            'status' => $state === self::STATE_DONE ? 'completed' : ($state === self::STATE_STOPPED ? 'cancelled' : 'failed'),
            'backup_id' => $run['backup_id'],
            'label' => static::run_label($run),
            'parts' => array('db'),
            'size_bytes' => static::size_bytes($run),
            'started_gmt' => (int) $run['started_at'],
            'finished_gmt' => (int) $run['finished_at'],
            'message' => $message,
            'error_code' => $error_code === 'backup_failed' ? 'archive_error' : ($error_code === '' ? '' : 'db_error'),
        ));
        return $run;
    }

    /**
     * Stop: before the backup is saved, nothing has changed; after, what is
     * done so far stays done and Undo is offered.
     *
     * @return array|WP_Error
     */
    public static function stop()
    {
        $run = static::get_run();
        if ($run === null || !in_array($run['state'], array(self::STATE_BACKING_UP, static::STATE_WORKING), true)) {
            return new WP_Error('not_running', __('Nothing is running.', '1-click-migration'));
        }
        $messages = static::messages();
        if ($run['state'] === self::STATE_BACKING_UP) {
            if (OCM_Local_Backup::active_action() === 'backup') {
                $stopped = OCM_Local_Backup::cancel_and_reset();
                if (is_wp_error($stopped)) {
                    return $stopped;
                }
            }
            $run['backup_id'] = '';
            return static::public_run(static::finish($run, self::STATE_STOPPED, $messages['stopped_before'], ''));
        }
        return static::public_run(static::finish($run, self::STATE_STOPPED, $messages['stopped_during'], ''));
    }

    /**
     * Undo: restore the database part of the backup taken before the run.
     *
     * @return array|WP_Error The local restore's start result.
     */
    public static function undo()
    {
        $run = static::get_run();
        if ($run === null || $run['backup_id'] === '' || !in_array($run['state'], array(self::STATE_DONE, self::STATE_FAILED, self::STATE_STOPPED), true)) {
            return new WP_Error('no_undo', __('There is nothing finished to undo.', '1-click-migration'));
        }
        if (!is_array(OCM_Local_Storage::read_manifest($run['backup_id']))) {
            return new WP_Error('backup_gone', __('The backup taken before this change was deleted, so it can\'t be undone here.', '1-click-migration'));
        }
        $result = OCM_Local_Backup::start_restore($run['backup_id'], array('db'), true);
        if (is_wp_error($result)) {
            return $result;
        }
        $run['undone_at'] = time();
        static::save_run($run);
        return $result;
    }

    /** Hide a finished run from the screen. The backup stays in Local Backups. */
    public static function dismiss()
    {
        if (!static::is_running()) {
            delete_option(static::OPTION_RUN);
        }
        return array('state' => 'idle');
    }

    /**
     * The run as the screen needs it: never internal positions.
     *
     * @param array|null $run
     * @param array      $extra
     * @return array
     */
    public static function public_run($run, array $extra = array())
    {
        if (!is_array($run)) {
            return array('state' => 'idle');
        }
        $manifest = !empty($run['backup_id']) ? OCM_Local_Storage::read_manifest($run['backup_id']) : null;
        $ended = in_array($run['state'], array(self::STATE_DONE, self::STATE_FAILED, self::STATE_STOPPED), true);
        $out = array(
            'id' => $run['id'],
            'state' => $run['state'],
            'warnings' => isset($run['warnings']) ? array_values($run['warnings']) : array(),
            'message' => isset($run['message']) ? (string) $run['message'] : '',
            'backup_id' => (string) $run['backup_id'],
            'backup_exists' => is_array($manifest),
            'backup_time' => is_array($manifest) && isset($manifest['created_gmt']) ? (int) $manifest['created_gmt'] : 0,
            'backup_progress' => isset($run['backup_progress']) ? $run['backup_progress'] : '',
            'backup_text' => isset($run['backup_text']) ? $run['backup_text'] : '',
            'undone' => !empty($run['undone_at']),
            'can_undo' => is_array($manifest) && $ended,
            'progress' => $run['state'] === self::STATE_DONE ? 100 : (int) static::progress_percent($run),
        );
        return array_merge($out, static::public_extra($run), $extra);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** Run lines go to the local log, never the migration log the monitor reads. */
    public static function log_line($line)
    {
        $line = (string) $line;
        if (strpos($line, 'SYSLOG:') === false) {
            $line = ' SYSLOG: ' . static::LOG_NAME . ': ' . $line;
        }
        if (method_exists(One_Click_Migration::class, 'write_to_local_log')) {
            One_Click_Migration::write_to_local_log($line);
        }
    }

    /** Seconds one request may spend working, inside the host's PHP limit. */
    public static function step_seconds()
    {
        if (null !== self::$step_seconds_override) {
            return (float) self::$step_seconds_override;
        }
        $limit = (int) ini_get('max_execution_time');
        $seconds = self::STEP_SECONDS;
        if ($limit > 0) {
            $seconds = min($seconds, max(3, $limit - 10));
        }
        return (float) $seconds;
    }

    protected static function ajax_guard($nonce_action)
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Insufficient permissions.', '1-click-migration')), 403);
        }
        check_ajax_referer($nonce_action, 'nonce');
    }

    protected static function send($result)
    {
        if (is_wp_error($result)) {
            $code = $result->get_error_code();
            $status = in_array($code, array('operation_in_progress', 'restore_cannot_stop'), true) ? 409 : 400;
            wp_send_json_error(array('message' => $result->get_error_message(), 'code' => $code), $status);
        }
        wp_send_json_success($result);
    }
}
