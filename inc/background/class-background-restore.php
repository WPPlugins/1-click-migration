<?php

namespace OCM;

use WP_Background_Process;

class OCM_BackgroundRestore extends WP_Background_Process
{
    const RETURN_TYPE_STOP_PROCESSS = 'stop_process';
    protected $action = 'ocm_background_restore';

    public function __construct()
    {
        parent::__construct();

        add_filter(
            $this->getIdentifier() . '_default_time_limit',
            static function () {
                return One_Click_Migration::get_timeout();
            }
        );
    }

    protected function getIdentifier()
    {
        return $this->identifier;
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
        list($restore_code) = $data;

        One_Click_Migration::write_to_log(' SYSLOG: *** RESTORE TASK STARTED ***');
        $result = OCM_Backup::start_restore_process($restore_code);
        if (self::RETURN_TYPE_STOP_PROCESSS === $result) {
            return $result;
        }
        One_Click_Migration::write_to_log('Download task has been completed.');

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

        One_Click_Migration::cancel_all_process();

        parent::complete();
    }

    /**
     * Handle
     *
     * Pass each queue item to the task handler, while remaining
     * within server memory and time limit constraints.
     */
    protected function handle() {
        // Acquire a database-level advisory lock to prevent concurrent handlers.
        global $wpdb;
        $lock_name = $this->identifier . '_handle_lock';
        $got_lock = $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 0)", $lock_name ) );

        if ( ! $got_lock ) {
            One_Click_Migration::write_to_log(' SYSLOG: Another restore handler is already running - this instance will exit');
            wp_die();
        }

        // The advisory lock belongs to this request's MySQL connection. A long
        // download runs no queries, so a host with a short wait_timeout can drop
        // the connection and free the lock while this worker is still busy. The
        // file lock lives as long as this PHP process, however long it runs (#79).
        if ( ! self::acquire_worker_file_lock() ) {
            $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
            One_Click_Migration::write_to_log(' SYSLOG: Another restore worker is still running - this instance will exit');
            wp_die();
        }

        One_Click_Migration::worker_heartbeat();
        $this->lock_process();
        $isStopProcess = false;
        $drainedElsewhere = false;

        do {
            $batch = $this->get_batch();
            if ( ! OCM_BackgroundBackup::is_usable_batch( $batch ) ) {
                if ( is_object( $batch ) && isset( $batch->key ) && $batch->key !== '' ) {
                    One_Click_Migration::write_to_log(' SYSLOG: Dropping unreadable restore batch row.');
                    $this->delete( $batch->key );
                    continue;
                }
                // The worker that released these locks finished the queue (#72).
                $drainedElsewhere = true;
                break;
            }

            foreach ( $batch->data as $key => $value ) {
                One_Click_Migration::worker_heartbeat();
                $task = $this->task( $value );

                if ( self::RETURN_TYPE_STOP_PROCESSS === $task ) {
                    $isStopProcess = self::RETURN_TYPE_STOP_PROCESSS;
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
            !$this->time_exceeded() && !$this->memory_exceeded() && !$this->is_queue_empty()
        );

        $this->unlock_process();

        // Release the advisory lock before dispatching next process or completing.
        $wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );

        if ( $drainedElsewhere ) {
            One_Click_Migration::write_to_log(' SYSLOG: Restore queue already drained by another worker - this instance will exit');
            wp_die();
        }

        if (self::RETURN_TYPE_STOP_PROCESSS === $isStopProcess) {
            wp_die();
        }

        // Start next batch or complete process.
        if ( !$this->is_queue_empty() ) {
            // The dispatched worker must be able to take the file lock.
            self::release_worker_file_lock();
            $this->dispatch();
        } else {
            // Keep the file lock through complete(); process exit releases it.
            $this->complete();
        }

        wp_die();
    }

    /** @var resource|null Open handle holding the restore worker's flock(). */
    private static $worker_lock_handle = null;

    /**
     * Path of the restore worker lock file. Kept beside the private debug log,
     * outside the web root, and never inside wp-content/ocm_restore (that
     * directory is deleted during cleanup).
     *
     * @return string
     */
    public static function worker_lock_path()
    {
        $dir = defined('OCM_DEBUG_LOG_DIR') ? OCM_DEBUG_LOG_DIR : WP_CONTENT_DIR;
        return rtrim($dir, '/\\') . '/restore-worker.lock';
    }

    /**
     * Take an exclusive, non-blocking flock() for this PHP process. The OS
     * releases it when the process exits, including on fatals and timeouts, so
     * a dead worker can never hold it.
     *
     * Returns false only when another live process holds the lock. If the lock
     * file can't be used at all (unwritable dir, no flock support), it returns
     * true and the advisory lock remains the guard.
     *
     * @return bool
     */
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

        // flock() also fails on filesystems without lock support; only a held
        // lock means another worker is alive.
        return !self::lock_is_contended($would_block, $dir);
    }

    /**
     * After a failed non-blocking flock(): contention, or no lock support here?
     * Not every platform reports would-block, so when it's unset, lock a private
     * probe file. If that works, flock() works and the lock file is held.
     *
     * @param int    $would_block
     * @param string $dir
     * @return bool
     */
    private static function lock_is_contended($would_block, $dir)
    {
        if ($would_block) {
            return true;
        }
        $probe = rtrim($dir, '/\\') . '/restore-worker.probe.' . getmypid() . '.' . mt_rand();
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

    /**
     * True while a live process (not this one) holds the restore worker lock.
     *
     * @return bool
     */
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
        One_Click_Migration::write_to_log(' SYSLOG: *** RESTORE DIRECT PROCESSING TRIGGERED ***');
        
        // Check if process is already running using direct transient check
        if ( get_site_transient( $this->identifier . '_process_lock' ) ) {
            One_Click_Migration::write_to_log(' SYSLOG: Restore background process already running');
            return;
        }

        if ( $this->is_queue_empty() ) {
            One_Click_Migration::write_to_log(' SYSLOG: Restore queue is empty - nothing to process');
            return;
        }

        One_Click_Migration::write_to_log(' SYSLOG: Starting direct restore processing');
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
        self::release_worker_file_lock();

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
