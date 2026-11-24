<?php

namespace OCM;

use WP_Background_Process;

class OCM_BackgroundLocalBackup extends WP_Background_Process
{
    const RETURN_TYPE_STOP_PROCESS = 'stop_process';
    const RETURN_TYPE_END_PROCESS = 'end_process';

    protected $action = 'ocm_background_local_backup';

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
     * @param mixed $data Queue item [part, run_id]
     * @return mixed
     */
    protected function task($data)
    {
        $self = $this;
        return One_Click_Migration::run_in_local_log(static function () use ($data, $self) {
            $part = isset($data[0]) ? (string) $data[0] : '';
            $run_id = isset($data[1]) ? (string) $data[1] : '';

            if (!OCM_Local_Backup::is_current_backup_run($run_id)) {
                One_Click_Migration::write_to_local_log(' SYSLOG: Ignoring local backup queue item owned by a different run.');
                return self::RETURN_TYPE_END_PROCESS;
            }

            OCM_Local_Backup::set_backup_run_context($run_id);
            One_Click_Migration::write_to_local_log(sprintf(' SYSLOG: *** LOCAL BACKUP TASK STARTED: %s ***', $part));

            $result = OCM_Local_Backup::process_backup_part($part, $run_id, $self);

            if (!OCM_Local_Backup::is_current_backup_run($run_id)) {
                return self::RETURN_TYPE_END_PROCESS;
            }

            if (in_array($result, array(self::RETURN_TYPE_STOP_PROCESS, self::RETURN_TYPE_END_PROCESS), true)) {
                return $result;
            }

            return false;
        });
    }

    protected function complete()
    {
        $self = $this;
        One_Click_Migration::run_in_local_log(static function () use ($self) {
            $run = get_option(OCM_Local_Backup::OPTION_ACTIVE_BACKUP_RUN, array());
            $run_id = is_array($run) && isset($run['id']) ? (string) $run['id'] : '';
            if ($run_id !== '') {
                OCM_Local_Backup::complete_backup($run_id);
            }
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
        if (get_option(OCM_Local_Backup::OPTION_ACTION_RESTORE) || get_option('ocm_action_start_restore')) {
            One_Click_Migration::write_to_local_log(' SYSLOG: Local backup worker refused: a restore is running; clearing local backup queue.');
            $this->delete_all();
            return;
        }

        global $wpdb;
        $lock_name = $this->identifier . '_handle_lock';
        $got_lock = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 0)", $lock_name));

        if (!$got_lock) {
            One_Click_Migration::write_to_local_log(' SYSLOG: Another local backup handler is already running - exiting.');
            wp_die();
        }

        One_Click_Migration::write_to_local_log(' SYSLOG: *** LOCAL BACKUP WORKER STARTED ***');
        One_Click_Migration::worker_heartbeat();

        $this->lock_process();
        $isStopProcess = false;
        $isEndProcess = false;
        $drainedElsewhere = false;

        do {
            $batch = $this->get_batch();
            if (!OCM_BackgroundBackup::is_usable_batch($batch)) {
                if (is_object($batch) && isset($batch->key) && $batch->key !== '') {
                    One_Click_Migration::write_to_local_log(' SYSLOG: Dropping unreadable local backup batch row.');
                    $this->delete($batch->key);
                    continue;
                }
                $drainedElsewhere = true;
                break;
            }

            foreach ($batch->data as $key => $value) {
                $isStopProcess = false;
                $isEndProcess = false;
                One_Click_Migration::worker_heartbeat();
                $task = $this->task($value);

                if (self::RETURN_TYPE_STOP_PROCESS === $task) {
                    $isStopProcess = self::RETURN_TYPE_STOP_PROCESS;
                    break;
                } elseif (self::RETURN_TYPE_END_PROCESS === $task) {
                    $isEndProcess = self::RETURN_TYPE_END_PROCESS;
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

            if (!empty($batch->data) && self::RETURN_TYPE_STOP_PROCESS !== $isStopProcess && self::RETURN_TYPE_END_PROCESS !== $isEndProcess) {
                $this->update($batch->key, $batch->data);
            } else {
                $this->delete($batch->key);
            }
        } while (
            !$isStopProcess && $isStopProcess !== self::RETURN_TYPE_STOP_PROCESS &&
            !$isEndProcess && $isEndProcess !== self::RETURN_TYPE_END_PROCESS &&
            !$this->time_exceeded() && !$this->memory_exceeded() && !$this->is_queue_empty()
        );

        $this->unlock_process();
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));

        if ($drainedElsewhere) {
            One_Click_Migration::write_to_local_log(' SYSLOG: Local backup queue already drained by another worker.');
            wp_die();
        }

        if (self::RETURN_TYPE_STOP_PROCESS === $isStopProcess) {
            wp_die();
        }

        if (self::RETURN_TYPE_END_PROCESS === $isEndProcess) {
            $this->cancel_all_process();
            wp_die();
        }

        if (!$this->is_queue_empty()) {
            $this->dispatch();
        } else {
            $this->complete();
        }

        wp_die();
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

    public function trigger_processing()
    {
        $self = $this;
        return One_Click_Migration::run_in_local_log(static function () use ($self) {
            One_Click_Migration::write_to_local_log(' SYSLOG: *** LOCAL BACKUP DIRECT PROCESSING TRIGGERED ***');
            if (get_site_transient($self->getIdentifier() . '_process_lock')) {
                One_Click_Migration::write_to_local_log(' SYSLOG: Local backup background process already running.');
                return false;
            }
            if ($self->is_queue_empty()) {
                One_Click_Migration::write_to_local_log(' SYSLOG: Local backup queue is empty.');
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
