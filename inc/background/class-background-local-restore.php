<?php

namespace OCM;

use WP_Background_Process;

class OCM_BackgroundLocalRestore extends WP_Background_Process
{
    const RETURN_TYPE_STOP_PROCESS = 'stop_process';

    protected $action = 'ocm_background_local_restore';

    /** @var resource|null Open handle holding the local restore worker's flock(). */
    private static $worker_lock_handle = null;

    public function __construct()
    {
        parent::__construct();

        add_filter(
            $this->getIdentifier() . '_default_time_limit',
            static function () {
                return One_Click_Migration::get_worker_time_budget();
            }
        );
    }

    protected function getIdentifier()
    {
        return $this->identifier;
    }

    /**
     * @param mixed $data Queue item [backup_id, selected_parts, run_id]
     * @return mixed
     */
    protected function task($data)
    {
        $self = $this;
        return One_Click_Migration::run_in_local_log(static function () use ($data, $self) {
            $backup_id = isset($data[0]) ? (string) $data[0] : '';
            $selected_parts = isset($data[1]) && is_array($data[1]) ? $data[1] : array();
            $run_id = isset($data[2]) && is_string($data[2]) ? $data[2] : '';

            if ($run_id === '' || !OCM_Local_Backup::is_current_restore_run($run_id)) {
                return self::RETURN_TYPE_STOP_PROCESS;
            }

            OCM_Local_Backup::set_restore_run_context($run_id);
            One_Click_Migration::write_to_local_log(' SYSLOG: *** LOCAL RESTORE TASK STARTED ***');

            $result = OCM_Local_Backup::process_restore($backup_id, $selected_parts, $run_id, $self);
            if (self::RETURN_TYPE_STOP_PROCESS === $result) {
                return $result;
            }

            return false;
        });
    }

    protected function complete()
    {
        $self = $this;
        One_Click_Migration::run_in_local_log(static function () use ($self) {
            $self->cancel_all_process();
            $self->parent_complete();
        });
    }

    public function parent_complete()
    {
        parent::complete();
    }

    protected function handle()
    {
        $self = $this;
        One_Click_Migration::run_in_local_log(static function () use ($self) {
            $self->handle_inner();
        });
    }

    public function handle_inner()
    {
        global $wpdb;
        $lock_name = $this->identifier . '_handle_lock';
        $got_lock = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 0)", $lock_name));

        if (!$got_lock) {
            One_Click_Migration::write_to_local_log(' SYSLOG: Another local restore handler is already running - exiting.');
            wp_die();
        }

        if (!self::acquire_worker_file_lock()) {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            One_Click_Migration::write_to_local_log(' SYSLOG: Another local restore worker holds the file lock - exiting.');
            wp_die();
        }

        One_Click_Migration::worker_heartbeat();
        $this->lock_process();
        $isStopProcess = false;
        $drainedElsewhere = false;

        do {
            $batch = $this->get_batch();
            if (!OCM_BackgroundBackup::is_usable_batch($batch)) {
                if (is_object($batch) && isset($batch->key) && $batch->key !== '') {
                    One_Click_Migration::write_to_local_log(' SYSLOG: Dropping unreadable local restore batch row.');
                    $this->delete($batch->key);
                    continue;
                }
                $drainedElsewhere = true;
                break;
            }

            foreach ($batch->data as $key => $value) {
                One_Click_Migration::worker_heartbeat();
                $task = $this->task($value);

                if (self::RETURN_TYPE_STOP_PROCESS === $task) {
                    $isStopProcess = self::RETURN_TYPE_STOP_PROCESS;
                    break;
                } elseif (false !== $task) {
                    $batch->data[$key] = $task;
                } else {
                    unset($batch->data[$key]);
                    $this->update($batch->key, $batch->data);
                }

                if ($this->time_exceeded() || $this->memory_exceeded()) {
                    break;
                }
            }

            if (!empty($batch->data) && self::RETURN_TYPE_STOP_PROCESS !== $isStopProcess) {
                $this->update($batch->key, $batch->data);
            } else {
                $this->delete($batch->key);
            }
        } while (
            !$isStopProcess && $isStopProcess !== self::RETURN_TYPE_STOP_PROCESS &&
            !$this->time_exceeded() && !$this->memory_exceeded() && !$this->is_queue_empty()
        );

        $this->unlock_process();
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));

        if ($drainedElsewhere) {
            One_Click_Migration::write_to_local_log(' SYSLOG: Local restore queue already drained by another worker.');
            wp_die();
        }

        if (self::RETURN_TYPE_STOP_PROCESS === $isStopProcess) {
            wp_die();
        }

        if (!$this->is_queue_empty()) {
            self::release_worker_file_lock();
            $this->dispatch();
        } else {
            $this->complete();
        }

        wp_die();
    }

    public static function worker_lock_path()
    {
        $dir = defined('OCM_DEBUG_LOG_DIR') ? OCM_DEBUG_LOG_DIR : WP_CONTENT_DIR;
        return rtrim($dir, '/\\') . '/local-restore-worker.lock';
    }

    public static function acquire_worker_file_lock()
    {
        if (is_resource(self::$worker_lock_handle)) {
            return true;
        }
        $path = self::worker_lock_path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return true;
        }
        $handle = @fopen($path, 'c');
        if (!$handle) {
            return true;
        }
        $would_block = 0;
        if (@flock($handle, LOCK_EX | LOCK_NB, $would_block)) {
            self::$worker_lock_handle = $handle;
            return true;
        }
        fclose($handle);
        return !self::lock_is_contended($would_block, $dir);
    }

    private static function lock_is_contended($would_block, $dir)
    {
        if ($would_block) {
            return true;
        }
        $probe = rtrim($dir, '/\\') . '/local-restore-worker.probe.' . getmypid() . '.' . mt_rand();
        $handle = @fopen($probe, 'c');
        if (!$handle) {
            return false;
        }
        $supported = @flock($handle, LOCK_EX | LOCK_NB);
        if ($supported) {
            @flock($handle, LOCK_UN);
        }
        fclose($handle);
        @unlink($probe);
        return $supported;
    }

    public static function is_worker_file_lock_held()
    {
        if (is_resource(self::$worker_lock_handle)) {
            return false;
        }
        $path = self::worker_lock_path();
        if (!is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'c');
        if (!$handle) {
            return false;
        }
        $would_block = 0;
        if (@flock($handle, LOCK_EX | LOCK_NB, $would_block)) {
            @flock($handle, LOCK_UN);
            fclose($handle);
            return false;
        }
        fclose($handle);
        return self::lock_is_contended($would_block, dirname($path));
    }

    public static function release_worker_file_lock()
    {
        if (!is_resource(self::$worker_lock_handle)) {
            self::$worker_lock_handle = null;
            return;
        }
        @flock(self::$worker_lock_handle, LOCK_UN);
        fclose(self::$worker_lock_handle);
        self::$worker_lock_handle = null;
    }

    public function is_queue_empty()
    {
        global $wpdb;
        $table = is_multisite() ? $wpdb->sitemeta : $wpdb->options;
        $column = is_multisite() ? 'meta_key' : 'option_name';
        $key = $wpdb->esc_like($this->identifier . '_batch_') . '%';
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE %s", $key));
        return ((int) $count) <= 0;
    }

    public function cancel_all_process()
    {
        if (function_exists('wp_schedule_event')) {
            $this->cancel_process();
        }
    }

    public function clear_cancel_status()
    {
        delete_site_option($this->identifier . '_status');
    }

    public function cancel_scheduled_event()
    {
        if (function_exists('wp_clear_scheduled_hook')) {
            $this->clear_scheduled_event();
        }
    }

    public function unlock_process()
    {
        parent::unlock_process();
    }

    public function refresh_process_lock()
    {
        $start_time = isset($this->start_time) ? $this->start_time : null;
        $this->lock_process(false);
        if (null !== $start_time) {
            $this->start_time = $start_time;
        }
    }

    public function clear_stale_process_lock()
    {
        if (!get_site_transient($this->identifier . '_process_lock')) {
            return 'free';
        }
        if (!self::acquire_worker_file_lock() || !is_resource(self::$worker_lock_handle)) {
            return 'held';
        }
        delete_site_transient($this->identifier . '_process_lock');
        return 'cleared';
    }

    public function trigger_processing()
    {
        $self = $this;
        return One_Click_Migration::run_in_local_log(static function () use ($self) {
            One_Click_Migration::write_to_local_log(' SYSLOG: *** LOCAL RESTORE DIRECT PROCESSING TRIGGERED ***');
            if (get_site_transient($self->getIdentifier() . '_process_lock')) {
                One_Click_Migration::write_to_local_log(' SYSLOG: Local restore background process already running.');
                return false;
            }
            if ($self->is_queue_empty()) {
                One_Click_Migration::write_to_local_log(' SYSLOG: Local restore queue is empty.');
                return false;
            }
            $self->handle_inner();
        });
    }

    public function time_exceeded()
    {
        $finish = $this->start_time + apply_filters($this->identifier . '_default_time_limit', 20);
        $return = time() >= $finish;
        return apply_filters($this->identifier . '_time_exceeded', $return);
    }

    public function remaining_time()
    {
        $finish = $this->start_time + apply_filters($this->identifier . '_default_time_limit', 20);
        return $finish - time();
    }

    public function has_batch_clock()
    {
        return (int) $this->start_time > 0;
    }

    public function restart_task()
    {
        $self = $this;
        One_Click_Migration::run_in_local_log(static function () use ($self) {
            $self->unlock_process();
            global $wpdb;
            $lock_name = $self->getIdentifier() . '_handle_lock';
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            OCM_BackgroundLocalRestore::release_worker_file_lock();

            if (!$self->is_queue_empty()) {
                One_Click_Migration::write_to_local_log('Timeout approaching. Restarting');
                One_Click_Migration::worker_heartbeat();
                sleep(1);
                $self->dispatch();
            }
            wp_die();
        });
    }
}
