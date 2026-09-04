<?php

namespace OCM;

use WP_Background_Process;

class OCM_BackgroundBackup extends WP_Background_Process
{
    const RETURN_TYPE_STOP_PROCESSS = 'stop_process';
    const RETURN_TYPE_END_PROCESSS = 'end_process';
    protected $action = 'ocm_background_backup';

    public function __construct()
    {
        parent::__construct();

        add_filter(
            $this->getIdentifier() . '_default_time_limit',
            static function () {
                return One_Click_Migration::get_timeout();
            }
        );

        add_filter($this->getIdentifier() . '_post_args', array($this, 'add_plugin_isolation_header'));
    }

    protected function getIdentifier()
    {
        return $this->identifier;
    }

    public function add_plugin_isolation_header($args)
    {
        $token = One_Click_Migration::get_backup_plugin_isolation_token();
        if ($token === '') {
            return $args;
        }

        if (empty($args['headers']) || !is_array($args['headers'])) {
            $args['headers'] = array();
        }

        $args['headers']['X-OCM-Isolation-Token'] = $token;
        return $args;
    }

    /**
     * Task
     *
     * Override this method to perform any actions required on each
     * queue item. Return the modified item for further processing
     * in the next pass through. Or, return false to remove the
     * item from the queue.
     *
     * @param mixed $item Queue item to iterate over
     *
     * @return mixed
     */
    protected function task($data)
    {


        list($action) = $data;
        $presigned_urls = get_option('ocm_presigned_urls');
        $aes_key_b64    = get_option('ocm_aes_key');
        $aes_key        = $aes_key_b64 ? base64_decode($aes_key_b64) : '';

        One_Click_Migration::write_to_log(sprintf(' SYSLOG: *** BACKGROUND TASK STARTED: %s ***', $action));



        if ('db' === $action) {
            $return = OCM_Backup::initiate_db_backup($presigned_urls, $aes_key);
        } else {
            $return = OCM_Backup::initiate_folder_backup($action, $presigned_urls, $aes_key);
        }

        if (in_array($return, [self::RETURN_TYPE_STOP_PROCESSS, self::RETURN_TYPE_END_PROCESSS], true)) {
            return $return;
        }

        One_Click_Migration::write_to_log(sprintf('%s task has been completed.', $action));

        if ('db' === $action) {
            OCM_Backup::set_complete_backup_step('db', OCM_Backup::STEP_BACKUP_CHILD_INITIATE_DB_BACKUP);

        } elseif ('themes' === $action) {
            OCM_Backup::set_complete_backup_step('themes', OCM_Backup::STEP_BACKUP_CHILD_INITIATE_BACKUP_THEMES);
        } elseif ('plugins' === $action) {
            OCM_Backup::set_complete_backup_step('plugins', OCM_Backup::STEP_BACKUP_CHILD_INITIATE_BACKUP_PLUGINS);
        } elseif ('uploads' === $action) {
            OCM_Backup::set_complete_backup_step('uploads', OCM_Backup::STEP_BACKUP_CHILD_INITIATE_BACKUP_UPLOADS);
        }


        return false;
    }

    /**
     * Complete
     *
     * Override if applicable, but ensure that the below actions are
     * performed, or, call parent::complete().
     */
    protected function complete()
    {
        OCM_Debug_Mode::disable();
        $missing_parts = OCM_Backup::get_backup_missing_parts();
        OCM_Backup::log_backup_parts_manifest($missing_parts);

        if ($missing_parts) {
            OCM_Backup::fail_backup('backup is incomplete; missing parts: ' . implode(', ', $missing_parts));
            One_Click_Migration::clear_backup_plugin_isolation();
            One_Click_Migration::cancel_all_process();
            parent::complete();
            return;
        }

        $urls = get_option('ocm_presigned_urls');
        if (WP_DEBUG !== true) {
            OCM_Backup::deleteDir(OCM_PLUGIN_WRITABLE_PATH, 'Cleaning temporary directory', $urls);
        }

        if (!OCM_Backup::send_restore_code_email()) {
            // Terminal marker: without the restore code email the backup is unusable.
            One_Click_Migration::write_to_log('Backup failed: restore code email could not be sent (see previous error line).');
            update_option('ocm_is_stopped', true, true);
            One_Click_Migration::clear_backup_plugin_isolation();
            One_Click_Migration::reset_actions_start_mark();
            One_Click_Migration::cancel_all_process();
            parent::complete();
            return;
        }

        One_Click_Migration::write_to_log('Backup completed.');

        OCM_Backup::reset_current_backup_steps();
        One_Click_Migration::clear_backup_plugin_isolation();
        One_Click_Migration::reset_actions_start_mark();
        One_Click_Migration::cancel_all_process();

        parent::complete();
    }

    /**
     * Handle
     *
     * Pass each queue item to the task handler, while remaining
     * within server memory and time limit constraints.
     *
     * Uses a MySQL advisory lock to prevent concurrent handlers
     * from processing the same batch simultaneously.
     */
    protected function handle() {
        // Acquire a database-level advisory lock to prevent concurrent handlers.
        // GET_LOCK with timeout=0 returns immediately: 1 if acquired, 0 if held by another session.
        // The lock is automatically released when the database connection closes (e.g. on wp_die/crash).
        global $wpdb;
        $lock_name = $this->identifier . '_handle_lock';
        $got_lock = $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 0)", $lock_name ) );

        if ( ! $got_lock ) {
            One_Click_Migration::write_to_log(' SYSLOG: Another handler is already running - this instance will exit');
            wp_die();
        }

        One_Click_Migration::write_to_log(' SYSLOG: *** BACKGROUND PROCESS HANDLER STARTED ***');
        One_Click_Migration::worker_heartbeat();

        $this->lock_process();
        $isStopProcess = false;
        $isEndProcess = false;

        do {
            $batch = $this->get_batch();
            One_Click_Migration::write_to_log(' SYSLOG: *** BATCH RETRIEVED: ' . count($batch->data) . ' items ***');

            foreach ( $batch->data as $key => $value ) {
                $isStopProcess = false;
                $isEndProcess = false;
                One_Click_Migration::worker_heartbeat();
                $task = $this->task( $value );

                if ( self::RETURN_TYPE_STOP_PROCESSS === $task ) {
                    $isStopProcess = self::RETURN_TYPE_STOP_PROCESSS;
                    break;
                } elseif ( self::RETURN_TYPE_END_PROCESSS === $task ) {
                    $isEndProcess = self::RETURN_TYPE_END_PROCESSS;
                    break;
                } elseif ( false !== $task ) {
                    $batch->data[ $key ] = $task;
                } else {
                    unset( $batch->data[ $key ] );
                    $this->update( $batch->key, $batch->data );
                }

                if ( $this->time_exceeded() || $this->memory_exceeded() ) {
                    // Batch limits reached.
                    break;
                }
            }

            // Update or delete current batch.
            if ( ! empty( $batch->data ) ) {
                $this->update( $batch->key, $batch->data );
            } else {
                $this->delete( $batch->key );
            }
        } while (
            !$isStopProcess && $isStopProcess !== self::RETURN_TYPE_STOP_PROCESSS &&
            !$isEndProcess && $isEndProcess !== self::RETURN_TYPE_END_PROCESSS &&
            !$this->time_exceeded() && !$this->memory_exceeded() && !$this->is_queue_empty()
        );

        $this->unlock_process();

        // Release the advisory lock before dispatching next process or completing.
        $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );

        if (self::RETURN_TYPE_STOP_PROCESSS === $isStopProcess) {
            wp_die();
        }

        if (self::RETURN_TYPE_END_PROCESSS === $isEndProcess) {
            One_Click_Migration::cancel_all_process();

            wp_die();
        }

        // Start next batch or complete process.
        if ( !$this->is_queue_empty() ) {
            $this->dispatch();
        } else {
            $this->complete();
        }

        wp_die();
    }

    /**
     * Is queue empty
     *
     * @return bool
     */
    public function is_queue_empty()
    {
        global $wpdb;

        $table  = $wpdb->options;
        $column = 'option_name';

        if ( is_multisite() ) {
            $table  = $wpdb->sitemeta;
            $column = 'meta_key';
        }

        $key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

        $count = $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*)
            FROM {$table}
            WHERE {$column} LIKE %s
        ", $key ) );

        return $count <= 0;
    }

    public function queue_size()
    {
        global $wpdb;

        $table  = $wpdb->options;
        $column = 'option_name';

        if ( is_multisite() ) {
            $table  = $wpdb->sitemeta;
            $column = 'meta_key';
        }

        $key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

        return $wpdb->get_var( $wpdb->prepare( "
            SELECT COUNT(*)
            FROM {$table}
            WHERE {$column} LIKE %s
        ", $key ) );
    }

    public function cancel_all_process()
    {

      $this->cancel_process();


    }

    public function cancel_scheduled_event()
    {

      $this->clear_scheduled_event();
    }

    /**
     * Public method to unlock the background process
     * Used during stop & reset to prevent stuck state
     */
    public function unlock_process()
    {
        parent::unlock_process();
    }

    /**
     * Public method to directly trigger background processing
     * Used as fallback when dispatch fails
     */
    public function trigger_processing()
    {
        One_Click_Migration::write_to_log(' SYSLOG: *** DIRECT PROCESSING TRIGGERED ***');
        
        // Check if process is already running using direct transient check
        if ( get_site_transient( $this->identifier . '_process_lock' ) ) {
            One_Click_Migration::write_to_log(' SYSLOG: Background process already running');
            return;
        }

        if ( $this->is_queue_empty() ) {
            One_Click_Migration::write_to_log(' SYSLOG: Queue is empty - nothing to process');
            return;
        }

        One_Click_Migration::write_to_log(' SYSLOG: Starting direct background processing');
        $this->handle();
    }

    /**
     * Time exceeded.
     *
     * Ensures the batch never exceeds a sensible time limit.
     * A timeout limit of 30s is common on shared hosting.
     *
     * @return bool
     */
    public function time_exceeded()
    {
        $finish = $this->start_time + apply_filters( $this->identifier . '_default_time_limit', 20 ); // 20 seconds
        $return = false;


        if ( time() >= $finish ) {
            $return = true;
        }

        return apply_filters( $this->identifier . '_time_exceeded', $return );
    }

    public function remaining_time()
    {
        $finish = $this->start_time + apply_filters( $this->identifier . '_default_time_limit', 20 ); // 20 seconds
        return $finish - time();
    }

    public function restart_task()
    {
        $this->unlock_process();

        // Release the advisory lock so the next handler can acquire it
        global $wpdb;
        $lock_name = $this->identifier . '_handle_lock';
        $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );

        if ( !$this->is_queue_empty() ) {
            One_Click_Migration::write_to_log(OCM_Backup::LOG_MESSAGE_BG_PROCESS_RESTARTING);
            One_Click_Migration::write_to_log(OCM_Backup::LOG_MESSAGE_BG_PROCESS_RESTARTING_LOG);
            One_Click_Migration::worker_heartbeat();
            sleep(1);

            // Start next batch
            $this->dispatch();
        }

        wp_die();
    }




}
