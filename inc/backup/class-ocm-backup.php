<?php

namespace OCM;

use DirectoryIterator;
use phpseclib\Crypt\Rijndael;
use PhpZip\Constants\ZipCompressionMethod;
use PhpZip\Exception\ZipException;
use PhpZip\ZipFile;
use WP_Error;
use wpdb;
use ZipArchive;

class OCM_Backup
{
    /** @var bool A selected archive backend ran and failed; do not retry via another huge-tree backend. */
    private static $archive_backend_failed = false;
    /** @var bool The host stopped an earlier worker while it packed this archive. */
    private static $archive_interrupted = false;

    /** @var string Run owner captured by this PHP request. */
    private static $backup_run_id = '';

    /** @var string Restore run this worker serves; '' outside a queued restore. */
    private static $restore_run_id = '';

    /** @var bool Once this worker's restore run has ended it stays ended. */
    private static $restore_run_ended = false;

    /** @var int Last time a long transfer re-read the restore run. */
    private static $restore_run_checked_at = 0;

    const BACKUP_RUN_OPTION = 'ocm_active_backup_run';
    const RESTORE_RUN_OPTION = 'ocm_active_restore_run';
    const BACKUP_UPLOADED_PARTS_OPTION = 'ocm_backup_uploaded_parts';
    /** Automatic resumes a restore run gets after its worker dies on a PHP fatal. */
    const RESTORE_CRASH_RESUMES = 2;
    /** array('run' => run id, 'count' => automatic resumes used). */
    const RESTORE_CRASH_OPTION = 'ocm_restore_crash_resumes';
    /** Set by a dying restore worker: the watchdog resumes the run without waiting for a stale heartbeat. */
    const RESTORE_RESUME_OPTION = 'ocm_restore_resume_requested';
    /** Same as RESTORE_CRASH_OPTION, for backup runs. */
    const BACKUP_CRASH_OPTION = 'ocm_backup_crash_resumes';
    /** Same as RESTORE_RESUME_OPTION, for backup runs. */
    const BACKUP_RESUME_OPTION = 'ocm_backup_resume_requested';
    /** WordPress cron hook that checks on the backup or restore worker while a run is active. */
    const WORKER_WATCHDOG_HOOK = 'ocm_worker_watchdog';
    /** 3.1.31's name for WORKER_WATCHDOG_HOOK; still handled for checks queued before an update. */
    const RESTORE_WATCHDOG_HOOK = 'ocm_restore_watchdog';
    /** Start of the visible line a crashed restore writes when it resumes on its own. No "Error:" in it. */
    const LOG_RESTORE_RESUMING = 'Notice: The restore was interrupted by an unexpected problem and is picking up again on its own from where it stopped';
    /** The same for a backup. */
    const LOG_BACKUP_RESUMING = 'Notice: The backup was interrupted by an unexpected problem and is picking up again on its own from where it stopped';

    /** Restore workspace under wp-content when the private work dir can't be used. */
    const RESTORE_WORKSPACE_FALLBACK_PREFIX = 'ocm_restore-';
    /** Restore workspace of 3.1.20 and earlier, still removed by every cleanup. */
    const LEGACY_RESTORE_WORKSPACE = 'ocm_restore';

    const STEP_BACKUP_CHILD_URL_GENERATED = 'urlGenerated';
    const STEP_BACKUP_CHILD_INITIATE = 'initiate';
    const STEP_BACKUP_CHILD_INITIATE_DB_BACKUP = 'initiateDbBackup';
    const STEP_BACKUP_CHILD_INITIATE_BACKUP_THEMES = 'initiateBackupThemes';
    const STEP_BACKUP_CHILD_INITIATE_BACKUP_PLUGINS = 'initiateBackupPlugins';
    const STEP_BACKUP_CHILD_INITIATE_BACKUP_UPLOADS = 'initiateBackupUploads';
    const STEP_BACKUP_CHILD_COMPRESS = 'compress';
    const STEP_BACKUP_CHILD_ENCRYPT = 'encrypt';
    const STEP_BACKUP_CHILD_UPLOAD = 'upload';
    const STEP_BACKUP_CHILD_ZIP_FILE_PATH = 'zipFilePath';

    const STEP_RESTORE_CHILD_INITIATE = 'initiate';
    const STEP_RESTORE_CHILD_DOWNLOAD = 'download';
    const STEP_RESTORE_CHILD_PRESIGNED_URLS = 'presigned_urls';
    const STEP_RESTORE_CHILD_DOWNLOAD_TMP_PATH = 'download_tmp_path';
    const STEP_RESTORE_CHILD_UNENCRYPTED_ZIP = 'unencrypted_zip';
    const STEP_RESTORE_CHILD_DECRYPT = 'decrypt';
    const STEP_RESTORE_CHILD_EXTRACT = 'extract';
    const STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW = 'deleteOldAndMoveNew';
    const STEP_RESTORE_CHILD_THEMES_UPLOADS_RESTORED = 'themesUploadsRestored';
    const STEP_RESTORE_CHILD_THEMES_RESTORED = 'themesRestored';
    const STEP_RESTORE_CHILD_UPLOADS_RESTORED = 'UploadsRestored';
    const STEP_RESTORE_CHILD_RESTORE = 'restore';
    /** In-flight import state for restore_db() (array; see db_import_state()). */
    const STEP_RESTORE_CHILD_DB_IMPORT = 'dbImport';
    /** Every dump imported into staging and the options gate passed. */
    const STEP_RESTORE_CHILD_DB_IMPORTED = 'dbImported';
    /** URL rewrite on the staging tables finished. */
    const STEP_RESTORE_CHILD_DB_URLS = 'dbUrlsRewritten';
    /** Staging tables are live. */
    const STEP_RESTORE_CHILD_DB_PROMOTED = 'dbPromoted';

    const LOG_MESSAGE_BG_PROCESS_RESTARTING = 'Timeout approaching. Restarting';
    const LOG_MESSAGE_BG_PROCESS_RESTARTING_LOG = 'Notice: Please wait. This is taking longer than expected but we are still making progress';
    const MAX_STEP_RETRIES = 4;
    const MAX_TRANSFER_RETRIES = 24;
    /**
     * curl errors that end a download step without saying anything about the
     * file: the next worker tries again (one of MAX_TRANSFER_RETRIES) instead
     * of failing the restore. 6 resolve, 7 connect, 18 partial file,
     * 28 timeout, 35 TLS handshake, 52 empty reply, 55 send, 56 receive.
     */
    const TRANSIENT_TRANSFER_ERRNOS = array(6, 7, 18, 28, 35, 52, 55, 56);

    /** Where the UI sends users who need help (the WordPress.org forum is public). */
    const SUPPORT_FORUM_URL = 'https://wordpress.org/support/plugin/1-click-migration/';

    /** Sender of the restore code email, named on the backup-completed banner. */
    const RESTORE_CODE_SENDER = 'pr@1clickmigration.com';

    /** Inline markup translated progress-card text may keep (ui_html()). */
    const UI_HTML = array('strong' => array(), 'code' => array(), 'em' => array());

    private static $skipMessages = [
        self::LOG_MESSAGE_BG_PROCESS_RESTARTING,
        self::LOG_MESSAGE_BG_PROCESS_RESTARTING_LOG
    ];

    private static $progress_data = [
        ['Error uploading file', '80%'],
        ['Error downloading file', '80%'],
        ['Time out approaching. Restarting', '0%'],

        ['Backup started', '1%'],
        ['Registering backup', '1%'],
        ['Retrying backup registration', '1%'],

        ['OS:', '1%'],
        ['Restore started', '1%'],
        ['Excluding', '1%'],
        ['Email address', '1%'],
        ['Site URL', '1%'],
        ['WP Version', '1%'],
        ['PHP Version', '1%'],
        ['OCM Plugin Version', '1%'],
        ['Backup ID', '1%'],
        ['Available Space', '1%'],
        ['default max_execution_time', '1%'],
        ['max_execution_time', '1%'],
        ['timeout set:', '1%'],
        ['proc_open / proc_close', '1%'],
        ['Current IP Address', '1%'],
        ['Creating temporary directory', '1%'],
        ['SYSLOG: Admin URL:', '1%'],
        ['SYSLOG: Using direct processing', '1%'],
        ['SYSLOG: Dispatching backup worker', '1%'],
        ['SYSLOG: Server IP:', '1%'],
        ['SYSLOG: WP_HTTP_BLOCK_EXTERNAL:', '1%'],
        ['SYSLOG: WP_ACCESSIBLE_HOSTS:', '1%'],


        ['URL generated: db', '5%'],
        ['URL generated: uploads', '9%'],
        ['URL generated: themes', '14%'],
        ['URL generated: plugins', '18%'],
        ['URL generated: log_download', '27%'],
        ['URL generated: log', '23%'],


        ['URL generated: db', '5%'],
        ['URL generated: uploads', '9%'],
        ['URL generated: themes', '14%'],
        ['URL generated: plugins', '18%'],
        ['URL generated: log_download', '27%'],
        ['URL generated: log', '23%'],

        ['db added to queue', '28%'],
        ['themes added to queue', '29%'],
        ['plugins added to queue', '30%'],
        ['uploads added to queue', '31%'],

        ['db task has been started.', '38%'],
        ['DB Size', '39%'],
        ['Database backup started', '40%'],
        // Removed: ['Database backup in progress. Please do not leave the plugin page', '40%'], - appears for every table, causes progress bar jumping
        ['SYSLOG: Table being backed up', '40%'],
        ['SYSLOG: No table structure for table', '40%'],
        ['SYSLOG: Inserting table data into table', '40%'],
        ['SYSLOG: End of data contents of table', '40%'],
        ['SYSLOG: There is no mysqldump support', '40%'],
        ['SYSLOG: Column statistics are supported', '40%'],
        ['SYSLOG: Table retrieved', '40%'],
        ['SYSLOG: Column statistics are not supported', '40%'],
        ['SYSLOG: DB memory preflight (dump)', '40%'],
        ['SYSLOG: Using row increment of', '40%'],
        ['SYSLOG: Low memory available, using conservative increment', '40%'],
        ['Calculated increment', '40%'],
        ['Notice: Database is larger than PHP memory_limit; streaming dump to disk.', '40%'],
        ['DB Zip file has been encrypted.', '41%'],
        ['Encrypting db Retry 1', '41%'],
        ['Encrypting db Retry 2', '41%'],
        ['Skipping DB Encrypting', '41%'],
        ['Compressing db Retry 1', '42%'],
        ['Compressing db Retry 2', '42%'],
        ['Skipping DB compression', '42%'],

        ['Database ZIP file created.', '42%'],
        ['Uploading db Retry 1', '42%'],
        ['Uploading db Retry 2', '42%'],
        ['Skipping DB upload', '42%'],
        ['db upload has been started.', '43%'],
        ['db task has been completed.', '44%'],

        ['themes task has been started.', '45%'],
        ['themes size', '45%'],
        ['Archiving themes to', '45%'],
        ['Compressing themes Retry 1', '45%'],
        ['Compressing themes Retry 2', '45%'],
        ['Skipping themes Compressing', '45%'],
        ['themes ZIP has been created.', '46%'],
        ['Encrypting themes Retry 1', '46%'],
        ['Encrypting themes Retry 2', '46%'],
        ['Skipping themes Encrypting', '46%'],
        ['themes ZIP has been encrypted.', '46%'],
        ['Uploading themes Retry 1', '47%'],
        ['Uploading themes Retry 2', '47%'],
        ['Skipping themes Upload', '46%'],
        ['themes folder upload has been started.', '47%'],
        ['themes task has been completed.', '48%'],

        ['plugins task has been started.', '49%'],
        ['plugins size:', '50%'],
        ['Archiving plugins to', '50%'],
        ['plugins ZIP has been created.', '51%'],
        ['Compressing plugins Retry 1', '50%'],
        ['Compressing plugins Retry 2', '50%'],
        ['Skipping plugins Compressing', '50%'],
        ['plugins ZIP has been encrypted.', '51%'],
        ['Encrypting plugins Retry 1', '51%'],
        ['Encrypting plugins Retry 2', '51%'],
        ['Skipping plugins Encrypting', '51%'],
        ['plugins folder upload has been started.', '52%'],
        ['Uploading plugins Retry 1', '52%'],
        ['Uploading plugins Retry 2', '52%'],
        ['Skipping plugins Upload', '52%'],
        ['plugins task has been completed.', '53%'],

        ['uploads task has been started.', '60%'],
        ['uploads size:', '61%'],
        ['Archiving uploads to', '61%'],
        ['Compressing uploads Retry 1', '61%'],
        ['Compressing uploads Retry 2', '61%'],
        ['Skipping uploads Compressing', '61%'],
        ['uploads ZIP has been created.', '62%'],
        ['uploads ZIP has been encrypted.', '63%'],
        ['Encrypting uploads Retry 1', '62%'],
        ['Encrypting uploads Retry 2', '62%'],
        ['Skipping uploads Encrypting', '62%'],
        ['uploads folder upload has been started.', '63%'],
        ['Uploading uploads Retry 1', '63%'],
        ['Uploading uploads Retry 2', '63%'],
        ['Skipping uploads Upload', '63%'],
        ['uploads task has been completed.', '65%'],

        ['Backup completed.', '100%'],
        ['Download task has been started', '6%'],

        ['Downloading restore files', '58%'],
        ['Downloading restore files. Please wait.', '58%'],

        ['Downloading file: "db.zip.crypt"', '60%'],
        ['Downloading db Retry 1', '60%'],
        ['Downloading db Retry 2', '60%'],
        ['Skipping db Download', '60%'],
        ['Decrypting file: "db.zip.crypt"', '60%'],
        ['Decrypting db Retry 1', '60%'],
        ['Decrypting db Retry 2', '60%'],
        ['Skipping db Decrypting', '60%'],
        ['Extracting file: "db.zip.crypt"', '60%'],
        ['Extracting db Retry 1', '60%'],
        ['Extracting db Retry 2', '60%'],
        ['Skipping db Extraction', '60%'],
        ['Restoring "DB"', '60%'],

        ['Downloading file: "uploads.zip.crypt"', '61%'],
        ['Downloading uploads Retry 1', '61%'],
        ['Downloading uploads Retry 2', '61%'],
        ['Skipping uploads Download', '61%'],
        ['Decrypting file: "uploads.zip.crypt"', '61%'],
        ['Decrypting uploads Retry 1', '61%'],
        ['Decrypting uploads Retry 2', '61%'],
        ['Skipping uploads Decrypting', '61%'],
        ['Extracting file: "uploads.zip.crypt"', '61%'],
        ['Extracting uploads Retry 1', '61%'],
        ['Extracting uploads Retry 2', '61%'],
        ['Skipping uploads Extraction', '61%'],
        ['Restoring "Uploads"', '88%'],

        ['Downloading file: "themes.zip.crypt"', '62%'],
        ['Downloading themes Retry 1', '62%'],
        ['Downloading themes Retry 2', '62%'],
        ['Skipping themes Download', '62%'],
        ['Decrypting file: "themes.zip.crypt"', '62%'],
        ['Decrypting themes Retry 1', '62%'],
        ['Decrypting themes Retry 2', '62%'],
        ['Skipping themes Decrypting', '62%'],
        ['Extracting file: "themes.zip.crypt"', '62%'],
        ['Extracting themes Retry 1', '62%'],
        ['Extracting themes Retry 2', '62%'],
        ['Skipping themes Extraction', '62%'],
        ['Restoring "Themes"', '88%'],

        ['Downloading file: "plugins.zip.crypt"', '63%'],
        ['Downloading plugins Retry 1', '63%'],
        ['Downloading plugins Retry 2', '63%'],
        ['Downloading file: "plugins.zip.crypt"', '63%'],
        ['Skipping plugins Download', '63%'],
        ['Decrypting file: "plugins.zip.crypt"', '63%'],
        ['Decrypting plugins Retry 1', '63%'],
        ['Decrypting plugins Retry 2', '63%'],
        ['Skipping plugins Decrypting', '63%'],
        ['Extracting file: "plugins.zip.crypt"', '63%'],
        ['Extracting plugins Retry 1', '63%'],
        ['Extracting plugins Retry 2', '63%'],
        ['Skipping plugins Extraction', '63%'],
        ['Restoring "Plugins"', '88%'],

        ['Download Error:', '60%'],
        ['Notice: file uploads.zip.crypt not found', '60%'],
        ['Notice: file themes.zip.crypt not found', '60%'],
        ['Notice: file plugins.zip.crypt not found', '60%'],
        ['Notice: file db.zip.crypt not found', '60%'],

        // The file swaps run after the database step (apply_deferred_file_swaps()),
        // so their steps sit between "Database has been restored" and "Cleaning up.".
        ['Themes have been restored', '89%'],
        ['Uploads have been restored', '89%'],
        ['Plugins have been restored', '90%'],

        ['Restore plugins', '75%'],
        ['Notice: Database restore in progress. Please don\'t leave the plugin page.', '80%'],
        ['SYSLOG: DB memory preflight (import)', '80%'],
        ['Notice: Database is larger than PHP memory_limit; streaming import from disk.', '80%'],
        ['Starting to update URLs in database', '84%'],
        ['SYSLOG: Number of updates made from replace', '85%'],
        ['SYSLOG: Finished updating URLs in the database', '85%'],
        ['Database has been restored', '86%'],
        ['SYSLOG: Low memory available (', '84%'],
        ['SYSLOG: Low memory - Page Size reduced', '84%'],
        ['Cleaning up.', '92%'],
        ['Restore completed with warnings:', '100%'],
        ['Restore completed.', '100%'],
        ['Restore stopped before import: backup needs a fresh 3.1.19 backup. No changes were made.', '0%'],
        ['Restore stopped: database part could not be downloaded or unpacked. No changes were made.', '0%'],
        ['Restore stopped: the backup\'s main options table didn\'t pass the check. No changes were made.', '0%'],
        ['Restore stopped: the database could not be imported. No changes were made.', '0%'],
        ['Error:', '0%'],
        ['Backup failed:', '0%'],
        ['Restore failed:', '0%'],
        ['Allowed memory size', '0%'],
        ['SYSLOG: "[PHP ERR][FATAL]', '0%'],
        ['Stop & Reset Finished', '0%'],
        ['Maximum execution time', '0%'],
        ['Backup completed with missing parts', '100%'],
        ['Notice: File uploads.zip.crypt was not found on the remote server. Please try to back it up again.', '100%'],
        ['Notice: File db.zip.crypt was not found on the remote server. Please try to back it up again.', '100%'],
        ['Notice: File plugins.zip.crypt was not found on the remote server. Please try to back it up again.', '100%'],
        ['Notice: File themes.zip.crypt was not found on the remote server. Please try to back it up again.', '100%']

    ];

    private static  $restore_site_url = '';

    /**
     * Set when the unsafe-binary preflight refuses the dump. Early gate runs
     * after DB extract and before themes/plugins/uploads are replaced, so a
     * refusal leaves files and the database untouched.
     */
    private static $db_restore_refused_binary = false;
    /** Set when the options/prefix gate rejects the staging import. */
    private static $db_restore_refused_options = false;
    /**
     * When true, a fatal during staging import / URL rewrite is converted into
     * LOG_RESTORE_STOPPED_IMPORT (live site untouched; no worker retry loop).
     * @var bool
     */
    private static $import_oom_guard_active = false;
    /** @var bool Prevents writing the import-stop line more than once from the OOM guard. */
    private static $import_oom_stop_written = false;
    /** @var bool The restore crash guard's shutdown callback is registered. */
    private static $restore_crash_guard_registered = false;
    /**
     * @var bool This request is inside a restore task. Kept in memory: a fatal's
     * own "Error:" log line clears ocm_action_start_restore before the guard runs.
     */
    private static $restore_crash_guard_armed = false;
    /** @var bool The backup crash guard's shutdown callback is registered. */
    private static $backup_crash_guard_registered = false;
    /** @var bool This request is inside a backup task. */
    private static $backup_crash_guard_armed = false;
    /**
     * Staging table name when URL rewrite hit a dedicated stop (keyless/unpageable
     * or oversized row). Used so discard staging does not insert a log line between
     * that Error and LOG_RESTORE_STOPPED_IMPORT.
     * @var string|null
     */
    private static $url_rewrite_unpageable_table = null;
    /** @var bool Memo for ensure_binary_preflight_before_file_replace() in one request. */
    private static $binary_preflight_passed = false;
    /** @var bool This worker finished at least one DB-import step, so it may hand over. */
    private static $db_phase_progress = false;
    private static $restore_files = [];

    /** Mid-restore db download/decrypt/extract failure (not a user exclude). */
    const OPTION_RESTORE_DB_PART_FAILED = 'ocm_restore_db_part_failed';
    const LOG_RESTORE_STOPPED_DB_PART = 'Restore stopped: database part could not be downloaded or unpacked. No changes were made.';
    const LOG_RESTORE_STOPPED_IMPORT = 'Restore stopped: the database could not be imported. No changes were made.';
    const LOG_RESTORE_STOPPED_OPTIONS = 'Restore stopped: the backup\'s main options table didn\'t pass the check. No changes were made.';
    const LOG_RESTORE_COMPLETED_WARNINGS_PREFIX = 'Restore completed with warnings:';
    const OPTION_RESTORE_WAS_WARNED = 'ocm_restore_was_warned';
    /** part => reason (timeout|not found|partially copied|not in backup|copied, old copy not removed). */
    const OPTION_RESTORE_PART_WARNINGS = 'ocm_restore_part_warnings';
    /** Persisted binary-preflight verdict for this restore's dump fingerprint. */
    const OPTION_BINARY_PREFLIGHT = 'ocm_binary_preflight_verdict';
    const OPTION_RESTORE_PLUGINS_ISOLATED = 'ocm_restore_plugins_isolated';
    /** Hosts the staging URL rewrite collected so far, for a resumed rewrite. */
    const OPTION_RESTORE_URL_HOSTS = 'ocm_restore_url_hosts';
    /** Suffix of the untouched copy kept while a dump's table prefix is rewritten. */
    const PRISTINE_DUMP_SUFFIX = '.ocm-orig';
    /** A DB-import step cut off this many times in a row stops the restore. */
    const MAX_DB_UNIT_ATTEMPTS = 3;
    /** write_deny_files(): name => contents. */
    const DENY_FILES = array(
        '.htaccess' => "Deny from all\nRequire all denied\n",
        'web.config' => '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>',
        'index.php' => "<?php\nhttp_response_code(404);\n",
    );


    public static function cancel_actions()
    {
        // Add nonce verification
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'one_click_migration_cancel_action')) {
            wp_die(esc_html__('This page has expired. Reload the page and try again.', '1-click-migration'));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', '1-click-migration'));
        }

        wp_safe_redirect(admin_url('tools.php?page=one-click-migration'));
        OCM_Debug_Mode::disable();
        update_option('ocm_is_stopped', true, true);
        One_Click_Migration::$process_backup_single->cancel_all_process();
        One_Click_Migration::$process_restore_single->cancel_all_process();
        One_Click_Migration::$process_backup_single->cancel_scheduled_event();
        One_Click_Migration::$process_restore_single->cancel_scheduled_event();
        OCM_BackgroundHelper::delete_all_batch_process();
        One_Click_Migration::cancel_all_process();
        One_Click_Migration::stop_and_reset();
        exit;
    }

    public static function start_backup()
    {
        // Verify nonce for security
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'one_click_migration_start_backup')) {
            wp_die(esc_html__('This page has expired. Reload the page and try again.', '1-click-migration'));
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', '1-click-migration'));
        }

        // Claim ownership before touching any shared options or queues. The
        // background-process transient is only created after dispatch and its
        // real identifier includes the wp_ prefix, so it cannot close the
        // start/dispatch race by itself.
        if (get_option('ocm_action_start_backup')
            || get_option('ocm_action_start_restore')
            || get_site_transient('wp_ocm_background_backup_process_lock')
        ) {
            self::refuse_backup_start();
        }

        $run_id = self::claim_backup_run();
        // No backup or restore is active and no worker holds the lock (both
        // checked above), so a run still reserved was left by a backup that
        // crashed. Before 3.1.32 only Stop & Reset could clear it.
        $released_stale_run = false;
        if (false === $run_id && self::release_stale_backup_run()) {
            $released_stale_run = true;
            $run_id = self::claim_backup_run();
        }
        if (false === $run_id) {
            self::refuse_backup_start();
        }

        @set_time_limit(One_Click_Migration::get_timeout());
        update_option('ocm_action_start_backup', true, true);
        update_option('ocm_is_stopped', false, true);
        One_Click_Migration::reset_worker_watchdog();
        update_option('ocm_upload_auto_try_nb_db', 0, true);
        update_option('ocm_upload_auto_try_nb_themes', 0, true);
        update_option('ocm_upload_auto_try_nb_plugins', 0, true);
        update_option('ocm_upload_auto_try_nb_uploads', 0, true);

        // As start_restore() does: with the previous run's presigned URLs still
        // set, the first write_to_log() below finds no local log and downloads
        // the old bucket's S3 log (with every restore of it) into this backup's
        // log, which then grows with each backup.
        delete_option('ocm_presigned_urls');
        One_Click_Migration::delete_log_file();
        One_Click_Migration::cancel_all_process();

        self::reset_current_backup_steps();
        self::reset_current_restore_steps();
        self::delete_backup_options();
        delete_option('ocm_log_merged_bucket');
        One_Click_Migration::write_to_log('Backup started');
        if ($released_stale_run) {
            One_Click_Migration::write_to_log(' SYSLOG: Released a backup run left by an earlier backup that did not finish.');
        }

        if (!One_Click_Migration::$process_backup_single->is_queue_empty()) {
            self::fail_backup('stale backup queue could not be cleared; use Stop & Reset before retrying');
            wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=backup_in_progress'));
            exit;
        }

        $username = self::get_username();
        update_option('ocm_user_email', $username, true);

        $excluded_folders = self::get_excluded_folders();

        if (!self::ensure_temporary_directory()) {
            self::fail_backup('temporary directory could not be created or is not writable');
            wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=write_permission'));
            exit;
        }

        $disk = self::check_backup_disk_space($excluded_folders);
        if (!$disk['ok']) {
            $disk_message = sprintf(
                'Error: Not enough disk space to run backup. Need %s, only %s available.',
                self::convertToReadableSize($disk['required']),
                self::convertToReadableSize($disk['free'])
            );
            One_Click_Migration::write_to_log($disk_message);
            self::fail_backup($disk_message);
            wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=insufficient_disk_space'));
            exit;
        }

        // Register backup with Lambda → MySQL gateway. Lambda generates bucket_id,
        // restore code, and AES key. The restore code is emailed only after all
        // selected backup parts have finished uploading. register_backup()
        // retries the failures that pass by themselves (no answer, 5xx, 429).
        $site_url = get_site_url();
        $register_attempts = 0;
        $registration = OCM_S3::register_backup($username, $site_url, $register_attempts);

        if (!$registration) {
            $attempts_text = $register_attempts . (1 === $register_attempts ? ' attempt' : ' attempts');
            One_Click_Migration::write_to_log('Error: Could not register backup after ' . $attempts_text);
            self::fail_backup('could not register backup after ' . $attempts_text);
            wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=endpoint_failure'));
            exit;
        }

        $bucket_id      = $registration->bucket_id;
        $aes_key        = base64_decode($registration->aes_key_b64);
        $presigned_urls = $registration->urls;
        $restore_code   = $registration->restore_code;

        update_option('ocm_bucket_id', $bucket_id, true);
        update_option('ocm_aes_key',   base64_encode($aes_key), true);
        update_option('ocm_presigned_urls', $presigned_urls, true);
        update_option('ocm_restore_code_plain', $restore_code, false);
        self::set_backup_run_bucket($run_id, $bucket_id);

        // Publish the uncompressed part sizes into the log. The log is uploaded to
        // S3 with the backup and merged back on the restore side before its disk
        // preflight, which uses these numbers instead of guessing from zip sizes.
        if (!empty($disk['sizes'])) {
            $size_pairs = [];
            foreach ($disk['sizes'] as $part => $bytes) {
                $size_pairs[] = $part . '=' . (int) $bytes;
            }
            One_Click_Migration::write_to_log(
                ' SYSLOG: BACKUP_UNCOMPRESSED_SIZES bucket=' . $bucket_id . ' ' . implode(' ', $size_pairs)
            );
        }

        // The DB part holds every table in the database, other installs' too.
        // The restore uses this to tell this site's tables from theirs.
        global $wpdb;
        if (isset($wpdb->base_prefix) && preg_match('/^[A-Za-z0-9_]+$/', (string) $wpdb->base_prefix)) {
            One_Click_Migration::write_to_log(
                ' SYSLOG: BACKUP_TABLE_PREFIX bucket=' . $bucket_id . ' prefix=' . $wpdb->base_prefix
            );
        }

        if (!self::check_is_complete_backup_step('init', self::STEP_BACKUP_CHILD_INITIATE)) {

            One_Click_Migration::write_to_log('Backup started');
            $all_plugins = get_option('active_plugins', []);
            $all_plugins = is_array($all_plugins) ? $all_plugins : [];
            $other_count = count($all_plugins) - 1;
            One_Click_Migration::write_to_log('Other active plugins: ' . max(0, $other_count));
            OCM_Debug_Mode::enable();
            One_Click_Migration::write_to_log(' SYSLOG: Debug mode enabled for backup run');

            $excluding = self::get_excluded_folders();
            
            // Check and convert to array if necessary
            if (empty($excluding)) {
                $excluding = [];  // Handle empty or null values
            } elseif (!is_array($excluding)) {
                $excluding = [$excluding];  // Convert to array if it's not already
            }
            
            $excluding_str = implode(", ", $excluding);
            One_Click_Migration::write_to_log(sprintf('Excluding "%s"', $excluding_str));

            self::save_system_data_to_log_file($username, $bucket_id);
            One_Click_Migration::write_to_log(sprintf(
                ' SYSLOG: Backup worker time budget: %ds',
                One_Click_Migration::get_worker_time_budget()
            ));

            // Server configuration diagnostics
            One_Click_Migration::write_to_log(' SYSLOG: Server IP: ' . ($_SERVER['SERVER_ADDR'] ?? 'unknown'));
            One_Click_Migration::write_to_log(' SYSLOG: WP_HTTP_BLOCK_EXTERNAL: ' . (defined('WP_HTTP_BLOCK_EXTERNAL') ? (WP_HTTP_BLOCK_EXTERNAL ? 'true' : 'false') : 'not set'));
            One_Click_Migration::write_to_log(' SYSLOG: WP_ACCESSIBLE_HOSTS: ' . (defined('WP_ACCESSIBLE_HOSTS') ? WP_ACCESSIBLE_HOSTS : 'not set'));
            One_Click_Migration::write_to_log(' SYSLOG: Admin URL: ' . admin_url('admin-ajax.php'));
            
            foreach ($presigned_urls as $key => $generated_url) {

                One_Click_Migration::write_to_log("URL generated: $key");
            }

            self::set_complete_backup_step('init', self::STEP_BACKUP_CHILD_INITIATE);
        }

          if(!(in_array('db', $excluded_folders))){
            if (!self::check_is_complete_backup_step('db', self::STEP_BACKUP_CHILD_INITIATE_DB_BACKUP)) {
                One_Click_Migration::write_to_log('db added to queue');
                One_Click_Migration::$process_backup_single->push_to_queue(array('db', $run_id));
            }
          }
          if(!(in_array('themes', $excluded_folders))){
            if (!self::check_is_complete_backup_step('themes', self::STEP_BACKUP_CHILD_INITIATE_BACKUP_THEMES)) {

                One_Click_Migration::write_to_log('themes added to queue');
                One_Click_Migration::$process_backup_single->push_to_queue(array('themes', $run_id));
            }
          }
          if(!(in_array('plugins', $excluded_folders))){
            if (!self::check_is_complete_backup_step('plugins', self::STEP_BACKUP_CHILD_INITIATE_BACKUP_PLUGINS)) {
                One_Click_Migration::write_to_log('plugins added to queue');
                One_Click_Migration::$process_backup_single->push_to_queue(array('plugins', $run_id));
            }
          }
          if(!(in_array('uploads', $excluded_folders))){
            if (!self::check_is_complete_backup_step('uploads', self::STEP_BACKUP_CHILD_INITIATE_BACKUP_UPLOADS)) {
                One_Click_Migration::write_to_log('uploads added to queue');
                One_Click_Migration::$process_backup_single->push_to_queue(array('uploads', $run_id));
            }
          }
        // Plugin isolation is intentionally disabled while restore is being debugged.
        // The function still runs to clear any stale mu-plugin shim from disk.
        One_Click_Migration::start_backup_plugin_isolation();

        One_Click_Migration::$process_backup_single->save();
        // Checks on the worker once a minute until the run ends, so a stalled or
        // crashed backup resumes even if the plugin page is closed.
        self::schedule_worker_watchdog(60);
        self::dispatch_backup_worker_with_diagnostics();

        wp_die('Backup started');
    }

    /**
     * Another backup or restore is running. The admin page starts a backup
     * with an XHR, which follows a redirect silently, so it gets the reason
     * as JSON; a plain page load still gets the banner.
     *
     * @return void Never returns.
     */
    private static function refuse_backup_start()
    {
        $requested_with = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) : '';
        if ('xmlhttprequest' === $requested_with) {
            wp_send_json_error(array('message' => __('A backup or restore is already running on this site. Wait for it to finish, or click Stop & Reset to cancel it, then start again.', '1-click-migration')), 409);
        }
        wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=backup_in_progress'));
        exit;
    }

    private static function dispatch_backup_worker_with_diagnostics()
    {
        if (!self::admin_ajax_loopback_check_passed()) {
            // Async dispatch would be sent into the void; run the worker inline
            // instead (the same "maximum compatibility" path restore uses).
            update_option('ocm_dispatch_blocked', 1, false);
            One_Click_Migration::write_to_log('Notice: This host blocks loopback requests to admin-ajax.php; running the backup in direct processing mode.');
            One_Click_Migration::$process_backup_single->trigger_processing();
            return;
        }

        delete_option('ocm_dispatch_blocked');

        One_Click_Migration::write_to_log(' SYSLOG: Dispatching backup worker');
        $response = One_Click_Migration::$process_backup_single->dispatch();
        self::log_backup_worker_dispatch_response($response);

        if (false === $response || is_wp_error($response)) {
            One_Click_Migration::write_to_log('Notice: Backup worker dispatch failed; falling back to direct processing.');
            One_Click_Migration::$process_backup_single->trigger_processing();
        }
    }

    private static function admin_ajax_loopback_check_passed()
    {
        $url = add_query_arg(
            array(
                'action' => 'ocm_loopback_check',
                'ocm_ts' => time(),
            ),
            admin_url('admin-ajax.php')
        );

        One_Click_Migration::write_to_log(' SYSLOG: Checking admin-ajax loopback before backup worker dispatch');

        $response = wp_remote_get($url, array(
            'timeout' => 8,
            'redirection' => 3,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
        ));

        if (is_wp_error($response)) {
            One_Click_Migration::write_to_log(sprintf(
                ' SYSLOG: admin-ajax loopback check failed before backup worker dispatch: %s',
                $response->get_error_message()
            ));
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $message = wp_remote_retrieve_response_message($response);
        $body = trim((string) wp_remote_retrieve_body($response));
        $body_preview = substr(preg_replace('/\s+/', ' ', $body), 0, 160);

        if (200 !== $code || false === strpos($body, '"success":true')) {
            One_Click_Migration::write_to_log(sprintf(
                ' SYSLOG: admin-ajax loopback check returned HTTP %d %s before backup worker dispatch. Body: %s',
                $code,
                $message,
                $body_preview
            ));
            return false;
        }

        One_Click_Migration::write_to_log(sprintf(
            ' SYSLOG: admin-ajax loopback check passed with HTTP %d %s',
            $code,
            $message
        ));
        return true;
    }

    private static function log_backup_worker_dispatch_response($response)
    {
        if (false === $response) {
            One_Click_Migration::write_to_log(' SYSLOG: Backup worker dispatch was not attempted because the process is already running or dispatch was cancelled.');
            return;
        }

        if (is_wp_error($response)) {
            One_Click_Migration::write_to_log(sprintf(
                ' SYSLOG: Backup worker dispatch returned WP_Error: %s',
                $response->get_error_message()
            ));
            return;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $message = wp_remote_retrieve_response_message($response);

        if (0 === $code) {
            // The dispatch is a fire-and-forget request, so 0 just means "sent".
            // Whether the worker actually started is confirmed by its heartbeat.
            One_Click_Migration::write_to_log(' SYSLOG: Backup worker dispatch request sent (async)');
            return;
        }

        One_Click_Migration::write_to_log(sprintf(
            ' SYSLOG: Backup worker dispatch HTTP response: %d %s',
            $code,
            $message
        ));
    }



    public static function start_restore()
    {
        // Verify nonce for security
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'one_click_migration_start_restore')) {
            wp_die(esc_html__('This page has expired. Reload the page and try again.', '1-click-migration'));
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to perform this action.', '1-click-migration'));
        }

        if (get_option('ocm_action_start_backup') || get_option('ocm_action_start_restore')) {
            wp_send_json_error(array('message' => __('Another operation started. Wait for it to finish or stop it first.', '1-click-migration')), 409);
            return;
        }
        // A worker that died on a fatal keeps its process lock for up to a
        // minute, and trigger_processing() used to skip the new run with only a
        // hidden SYSLOG line: the page sat at "Restore started" and the dead
        // run's maintenance mode stayed on (#127). A stale lock is cleared; a
        // lock a live worker may hold stops here, before anything is reset.
        $restore_process = One_Click_Migration::$process_restore_single;
        $lock_state = is_object($restore_process) && method_exists($restore_process, 'clear_stale_process_lock')
            ? $restore_process->clear_stale_process_lock()
            : 'free';
        if ('held' === $lock_state) {
            self::refuse_restore_start();
            return;
        }
        $stale_state = 'cleared' === $lock_state ? array('process lock') : array();
        $restore_code = self::get_restore_code();
        $url_plan = OCM_Restore_Precheck::consume(
            $_POST['precheck_token'] ?? '', $restore_code, $_GET['selected'] ?? '',
            isset($_POST['domain_ids']) ? wp_unslash($_POST['domain_ids']) : array()
        );
        if (is_wp_error($url_plan)) {
            wp_send_json_error(array('message' => $url_plan->get_error_message()), 400);
            return;
        }

        // No run is active (both checks above), so maintenance mode still on
        // was left by a run that died. This run turns it on again once its code
        // is redeemed; a stop before that must not leave the site serving 503s.
        if (get_option('ocm_maintenance_mode', false)) {
            One_Click_Migration::write_to_log('Notice: Maintenance mode was still on from an earlier restore that did not finish. Turning it off before the new restore starts.');
            One_Click_Migration::disable_maintenance_mode();
            $stale_state[] = 'maintenance mode';
        }

        update_option('ocm_download_auto_try_nb_db', 0, true);
        update_option('ocm_download_auto_try_nb_themes', 0, true);
        update_option('ocm_download_auto_try_nb_plugins', 0, true);
        update_option('ocm_download_auto_try_nb_uploads', 0, true);

        One_Click_Migration::cancel_all_process();
        self::release_backup_run(self::get_stored_backup_run_id());
        delete_option(self::BACKUP_UPLOADED_PARTS_OPTION);

        self::reset_current_backup_steps();
        self::reset_current_restore_steps();
        self::delete_restore_options();
        if (!update_option(OCM_Restore_Precheck::OPTION, $url_plan, false)) {
            wp_send_json_error(array('message' => __('The address choices could not be saved. Check the backup again before restoring.', '1-click-migration')), 500);
            return;
        }
        // Clear stale presigned URLs from a prior migration so early write_to_log
        // calls don't create a local log file keyed to a dead bucket (which would
        // later block the merge-in of the backup log from the new bucket).
        delete_option('ocm_presigned_urls');
        delete_option('ocm_log_merged_bucket');
        delete_option(self::OPTION_RESTORE_DB_PART_FAILED);
        One_Click_Migration::delete_log_file();
        if ($stale_state) {
            One_Click_Migration::write_to_log(sprintf(
                ' SYSLOG: Cleared state left by an earlier restore that did not finish: %s',
                implode(', ', $stale_state)
            ));
        }
        $exluded_folders = self::get_excluded_folders();

        // A fresh watchdog clock for this run (see reset_worker_watchdog()).
        One_Click_Migration::reset_worker_watchdog();
        // cancel_all_process() above emptied the queue; any item still left
        // belongs to an older run and its worker drops it (#96).
        $run_id = self::claim_restore_run();
        One_Click_Migration::$process_restore_single->push_to_queue(array($restore_code, $run_id));

        // Save the queue and use direct processing for maximum reliability
        // A "cancelled" flag left by an earlier Stop & Reset would make the
        // first dispatched worker delete this run's queue.
        One_Click_Migration::clear_background_cancel_status();
        One_Click_Migration::$process_restore_single->save();
        // Checks on the worker once a minute until the run ends, so a stalled or
        // crashed restore resumes even if the plugin page is closed.
        self::schedule_worker_watchdog(60);
        One_Click_Migration::write_to_log(' SYSLOG: Using direct restore processing for maximum compatibility');
        // handle() ends the request with wp_die(), so false means no worker
        // ran: the run would wait forever with nothing to restart it.
        if (false === One_Click_Migration::$process_restore_single->trigger_processing()) {
            update_option('ocm_is_stopped', true, true);
            self::end_restore_run();
            self::refuse_restore_start();
        }
    }

    /**
     * The restore worker can't start: say so in the log and to the admin page.
     *
     * @return void
     */
    private static function refuse_restore_start()
    {
        One_Click_Migration::write_to_log('Error: Restore could not start because a previous restore worker still holds its lock or did not shut down cleanly. Click Stop & Reset, then start the restore again.');
        wp_send_json_error(array('message' => __('A restore is already running on this site. Wait for it to finish, or click Stop & Reset to cancel it, then start again.', '1-click-migration')), 409);
    }

    public static function start_restore_process($restore_code)
    {
        global $wpdb;

        @set_time_limit(One_Click_Migration::get_timeout());
        update_option('ocm_action_start_restore', true, true);
        update_option('ocm_is_stopped', false, true);

        // Cleared at restore start so a prior failed attempt cannot block a new run.
        // Mid-restore db skip paths set this; user-excluded db never does.
        // Do NOT clear ocm_backup_*_parts here: a worker restart after the backup
        // log was merged (ocm_log_merged_bucket set) would wipe exclusions while
        // skipping re-read, causing excluded parts to be requested again. Fresh
        // restores already clear those via delete_restore_options() at kickoff.
        if (!self::check_is_complete_restore_step('init', self::STEP_RESTORE_CHILD_INITIATE)) {
            delete_option(self::OPTION_RESTORE_DB_PART_FAILED);
            delete_option(self::OPTION_BINARY_PREFLIGHT);
            delete_option(self::OPTION_RESTORE_PART_WARNINGS);
            delete_option(self::OPTION_RESTORE_PLUGINS_ISOLATED);
            delete_option(self::OPTION_RESTORE_WAS_WARNED);
            self::$db_restore_refused_binary = false;
            self::$binary_preflight_passed = false;
        }

        $content_dir = self::get_restore_work_path();
        $excluded_restore_files = get_option('ocm_excluded_folders', []);

        if (!self::check_is_complete_restore_step('init', self::STEP_RESTORE_CHILD_INITIATE)) {

            $redeem = OCM_S3::redeem_restore_code($restore_code);

            $status      = is_object($redeem) ? (int) ($redeem->_status ?? 0) : 0;
            $has_error   = is_object($redeem) && !empty($redeem->error);
            $has_payload = is_object($redeem) && !empty($redeem->bucket_id) && !empty($redeem->aes_key_b64) && !empty($redeem->urls);

            if ($status !== 200 || $has_error || !$has_payload) {
                $error = $has_error ? $redeem->error : 'malformed_response';
                One_Click_Migration::write_to_log(sprintf(
                    'Error: Restore code lookup failed (status=%d error=%s payload_ok=%s)',
                    $status, $error, $has_payload ? 'yes' : 'no'
                ));
                One_Click_Migration::reset_actions_start_mark();
                update_option('ocm_is_stopped', true, true);
                self::end_restore_run();
                $message = OCM_Restore_Precheck::lookup_unavailable($status, $has_error ? $redeem->error : '')
                    ? 'service_busy' : 'invalid_restore_code';
                wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=' . $message));
                exit;
            }

            $url_plan = get_option(OCM_Restore_Precheck::OPTION, array());
            if ($url_plan && ($url_plan['bucket_id'] !== $redeem->bucket_id || $url_plan['destination'] !== site_url())) {
                One_Click_Migration::write_to_log('Error: Restore preparation no longer matches this backup or destination. Check the backup again.');
                update_option('ocm_is_stopped', true, true);
                self::end_restore_run();
                exit;
            }
            $bucket_id      = $redeem->bucket_id;
            $aes_key        = base64_decode($redeem->aes_key_b64);
            $presigned_urls = $redeem->urls;
            $username       = $redeem->email ?? '';

            update_option('ocm_bucket_id', $bucket_id, true);
            update_option('ocm_aes_key',   base64_encode($aes_key), true);
            update_option('ocm_presigned_urls', $presigned_urls, true);
            if ($username) {
                update_option('ocm_user_email', $username, true);
            }

            // Pull backup-side log from S3 and prepend to whatever the restore worker
            // has already written locally (e.g. "*** RESTORE TASK STARTED ***").
            One_Click_Migration::merge_backup_log_from_s3();

            $backup_missing_parts = get_option('ocm_backup_missing_parts', []);
            if (is_array($backup_missing_parts) && $backup_missing_parts) {
                One_Click_Migration::write_to_log(sprintf(
                    'Notice: This backup does not include: %s. These parts were skipped when the backup was created, so the restored site will be missing them.',
                    implode(', ', $backup_missing_parts)
                ));
            }

            // Promote backup-log =excluded parts into ocm_excluded_folders so the
            // rest of restore matches 3.1.18 selective exclude (no download, and
            // for db: complete_restore / no import / no URL rewrite).
            // Call AFTER get_excluded_folders() so a $_GET selected list cannot wipe them.
            $excluded_for_check = self::get_excluded_folders();
            self::apply_backup_excluded_parts_to_restore_exclusions();
            $backup_excluded_for_disk = get_option('ocm_backup_excluded_parts', array());
            $backup_excluded_for_disk = is_array($backup_excluded_for_disk) ? $backup_excluded_for_disk : array();
            $excluded_for_check = array_values(array_unique(array_merge(
                is_array($excluded_for_check) ? $excluded_for_check : array(),
                get_option('ocm_excluded_folders', array()) ?: array(),
                $backup_excluded_for_disk
            )));
            $disk = self::check_restore_disk_space($presigned_urls, $excluded_for_check);
            if (!$disk['ok']) {
                if (!empty($disk['reason']) && 'write_probe_failed' === $disk['reason']) {
                    One_Click_Migration::write_to_log(
                        'Error: Restore workspace is not writable or disk quota is exhausted. Please free space or increase the hosting account quota, then try again.'
                    );
                } else {
                    One_Click_Migration::write_to_log(sprintf(
                        'Error: Not enough disk space to run restore. Need %s, only %s available.',
                        self::convertToReadableSize($disk['required']),
                        self::convertToReadableSize($disk['free'])
                    ));
                }
                One_Click_Migration::write_to_log('Restore failed: Not enough disk space or the hosting disk quota is exhausted (see previous log line).');
                One_Click_Migration::reset_actions_start_mark();
                update_option('ocm_is_stopped', true, true);
                self::end_restore_run();
                wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=insufficient_disk_space'));
                exit;
            }

            One_Click_Migration::write_to_log('Restore started');
            $all_plugins = get_option('active_plugins', []);
            $all_plugins = is_array($all_plugins) ? $all_plugins : [];
            $other_count = count($all_plugins) - 1;
            One_Click_Migration::write_to_log('Other active plugins: ' . max(0, $other_count));
            One_Click_Migration::write_to_log(' SYSLOG: ' . self::background_library_diagnostic());
            // Plugin deactivation waits until binary preflight / db-part checks
            // pass (gate_restore_before_file_replace). Stops that say
            // "No changes were made" must not have deactivated anything.
            OCM_Debug_Mode::enable();
            One_Click_Migration::write_to_log(' SYSLOG: Debug mode enabled for restore run');
            One_Click_Migration::enable_maintenance_mode();
            $excluded_restore_files = get_option('ocm_excluded_folders', []);

            // Item A: backup-skipped / failed db → stop unless db is excluded for
            // this restore (user selection OR backup-log db=excluded files-only).
            $excluded_restore_files = get_option('ocm_excluded_folders', []);
            self::stop_if_db_unavailable_for_restore($excluded_restore_files);

            $exluding = implode(", ", $excluded_restore_files);

            // One_Click_Migration::write_to_log(sprintf('Excluding "%s"', $exluding));
            self::save_system_data_to_log_file($username, $bucket_id);
            One_Click_Migration::write_to_log(sprintf(
                ' SYSLOG: Restore worker time budget: %ds',
                One_Click_Migration::get_worker_time_budget()
            ));

            foreach ($presigned_urls as $key => $generated_url) {
              $writable_file_path = OCM_PLUGIN_WRITABLE_PATH . $key . '.zip';

              if(file_exists($writable_file_path)){
                One_Click_Migration::write_to_log("URL generated: $key");
              }

            }

            $attempted_dir = '';
            $content_dir = self::prepare_restore_workspace($attempted_dir);
            if ('' === $content_dir) {
                One_Click_Migration::write_to_log(sprintf(
                    'Downloading restore files, Error: Directory "%s" could not be prepared. Please check the write permission for the parent directory "%s".',
                    $attempted_dir, dirname($attempted_dir)
                ));
                exit;
            }

            One_Click_Migration::write_to_log('Downloading restore files. Please wait.');

            self::set_complete_restore_step('init', self::STEP_RESTORE_CHILD_INITIATE);
            self::set_complete_restore_step('init', self::STEP_RESTORE_CHILD_PRESIGNED_URLS, $presigned_urls);
        }

        $presigned_urls = self::get_restore_step_value('init', self::STEP_RESTORE_CHILD_PRESIGNED_URLS);
        // Resume path: get_excluded_folders may no-op (no $_GET); still re-apply
        // backup exclusions so a resumed files-only restore never re-requests db.
        $excluded_folders = self::get_excluded_folders();
        self::apply_backup_excluded_parts_to_restore_exclusions();

        // Process db first so the binary preflight can run after DB extract and
        // before themes/plugins/uploads replace destination files.
        $presigned_urls = self::presigned_urls_db_first($presigned_urls);

        // Download each of the zip files
        foreach ($presigned_urls as $key => $download_url) {
            // A worker left over from a failed, stopped or replaced run must not
            // log or touch the restore workspace again (#96).
            if (self::restore_run_ended()) {
                return OCM_BackgroundRestore::RETURN_TYPE_STOP_PROCESSS;
            }
            // Don't try and download the log file

            $excluded_restore_files = get_option('ocm_excluded_folders',[]);
            $keyZipCrypt = $key . '.zip.crypt';
            $file_path = $content_dir . '/' . $keyZipCrypt;
            $extract_path = $content_dir . "/$key";
            if(!in_array($key, $excluded_restore_files)){

            if (in_array($key, array('log', 'log_download'))) {
                continue;
            }

            // Never request a part the backup log marked =excluded (including
            // db=excluded files-only moves).
            if (self::maybe_skip_backup_excluded_restore_part($key, $excluded_restore_files)) {
                continue;
            }

            $excluded_restore_files = get_option('ocm_excluded_folders',[]);
            $skipped_restore_files = get_option('ocm_skipped_folders', []);
            $eexcluded_restore_files = get_option('ocm_eexcluded_folders', []);



              if(is_dir($content_dir)){
                $retry_count = get_option('ocm_restore_download_retry_' . $key, 0);

                if(!$retry_count){
                  update_option('ocm_restore_download_retry_' . $key, 1, true);

                }
                if($retry_count){
                  if($retry_count <= self::MAX_TRANSFER_RETRIES){
                    if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DOWNLOAD)) {
                    One_Click_Migration::write_to_log(sprintf('Downloading %s Retry %d', $key, $retry_count));
                    $retry_count = $retry_count + 1;
                    update_option('ocm_restore_download_retry_' . $key, $retry_count, true);
                    }
                  }else{
                      if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DOWNLOAD)) {

                        One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Download'));
                        One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Decrypting'));
                        One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Extraction'));
                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DOWNLOAD);
                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DECRYPT);
                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT);
                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                        self::capture_skipped_restore_part($excluded_restore_files, $skipped_restore_files, $key);
                        continue;

                      }
                    }
                  }
                  if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DOWNLOAD)) {
                    // Reset time limit for file download
                    @set_time_limit(One_Click_Migration::get_timeout());

                    One_Click_Migration::write_to_log(sprintf('Downloading file: "%s.zip.crypt"', $key));

                    self::$restore_download_requests[] = $key;
                    $downloadTmpPathFile = self::download_restore_file($download_url, $file_path, $key);
                    if (self::restore_run_ended()) {
                        return OCM_BackgroundRestore::RETURN_TYPE_STOP_PROCESSS;
                    }

                    if(is_wp_error($downloadTmpPathFile)){
                      $error_message = $downloadTmpPathFile->get_error_message();
                      if($error_message === 'Not Found'){

                        $not_found = self::handle_restore_part_download_not_found(
                            $key,
                            $keyZipCrypt,
                            $excluded_restore_files,
                            $eexcluded_restore_files
                        );
                        if ($not_found === 'fail_restore') {
                            return self::fail_restore(sprintf(
                                'Error: Restore failed because "%s" should exist in this backup but is missing from storage. Create a fresh backup and restore with the new code.',
                                $keyZipCrypt
                            ));
                        }
                        continue;
                      }

                      // The next worker continues or retries the download; a
                      // stalled step counts toward MAX_TRANSFER_RETRIES.
                      if (in_array($downloadTmpPathFile->get_error_code(), array('ocm_partial_download', 'ocm_transfer_stalled'), true)) {
                        One_Click_Migration::write_to_log($error_message);
                        One_Click_Migration::write_to_log("Process is Restarting");
                        One_Click_Migration::$process_restore_single->restart_task();
                      }

                      return self::fail_restore(sprintf(
                          'Error: Restore failed while downloading "%s": %s',
                          $keyZipCrypt,
                          $error_message
                      ));
                    }

                    // The file is complete: record it before handing over, or the
                    // next worker would redo the step and, once its retries ran
                    // out, skip the part.
                    self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DOWNLOAD_TMP_PATH, $downloadTmpPathFile);
                    self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DOWNLOAD);
                    if (One_Click_Migration::$process_restore_single->time_exceeded()) {
                        One_Click_Migration::write_to_log("Process is Restarting");
                        One_Click_Migration::$process_restore_single->restart_task();
                    }
                }
              }
              if (self::restore_run_ended()) {
                  return OCM_BackgroundRestore::RETURN_TYPE_STOP_PROCESSS;
              }
              if(is_dir($content_dir)){
                $retry_count = get_option('ocm_restore_decrypt_retry_' . $key, 0);
                if(!$retry_count){
                  update_option('ocm_restore_decrypt_retry_' . $key, 1, true);

                }
                if($retry_count){
                  if($retry_count <= self::MAX_STEP_RETRIES){
                    if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DECRYPT)) {
                    One_Click_Migration::write_to_log(sprintf('Decrypting %s Retry %d', $key, $retry_count));
                    $retry_count = $retry_count + 1;
                    update_option('ocm_restore_decrypt_retry_' . $key, $retry_count, true);
                    }
                  }else{
                      if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DECRYPT)) {

                        One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Decrypting'));
                        One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Extraction'));

                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DECRYPT);
                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT);
                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                        self::capture_skipped_restore_part($excluded_restore_files, $skipped_restore_files, $key);
                        continue;
                      }
                    }
                  }
                if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DECRYPT)) {
                  // Reset time limit for file decryption
                  @set_time_limit(One_Click_Migration::get_timeout());

                  One_Click_Migration::write_to_log(sprintf('Decrypting file: "%s.zip.crypt"', $key));

                  $downloadTmpPathFile = self::get_restore_step_value($key, self::STEP_RESTORE_CHILD_DOWNLOAD_TMP_PATH);
                  if (!is_string($downloadTmpPathFile) || !file_exists($downloadTmpPathFile)) {
                      return self::fail_restore(sprintf(
                          'Error: Restore failed because downloaded file for "%s.zip.crypt" was missing.',
                          $key
                      ));
                  }

                  if ($downloadTmpPathFile !== $file_path && !copy($downloadTmpPathFile, $file_path)) {
                      return self::fail_restore(sprintf(
                          'Error: Restore failed while copying "%s.zip.crypt" into the restore workspace. Please check disk quota and write permissions.',
                          $key
                      ));
                  }
                  $aes_key_b64 = get_option('ocm_aes_key');
                  $aes_key = $aes_key_b64 ? base64_decode($aes_key_b64) : '';
                  $unencrypted_zip = self::decryptZipFile($file_path, $aes_key);
                  if (self::restore_run_ended()) {
                      return OCM_BackgroundRestore::RETURN_TYPE_STOP_PROCESSS;
                  }
                  if (!$unencrypted_zip) {
                      clearstatcache(true, $file_path);
                      if (!file_exists($file_path)) {
                          // The file vanished mid-step; that's not a quota or permission problem.
                          return self::fail_restore(sprintf(
                              'Error: Restore failed because downloaded file for "%s.zip.crypt" was missing.',
                              $key
                          ));
                      }
                      return self::fail_restore(sprintf(
                          'Error: Restore failed while decrypting "%s.zip.crypt". Please check disk quota and write permissions.',
                          $key
                      ));
                  }

                  // Decrypted: record it before handing over (see download above).
                  self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_UNENCRYPTED_ZIP, $unencrypted_zip);
                  self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DECRYPT);

                  // Later steps only need the decrypted zip; drop the encrypted copy
                  // now so the part never occupies 3x its size on disk at once.
                  @unlink($file_path);
                  if (is_string($downloadTmpPathFile) && $downloadTmpPathFile !== $file_path && file_exists($downloadTmpPathFile)) {
                      @unlink($downloadTmpPathFile);
                  }

                  if (One_Click_Migration::$process_restore_single->time_exceeded()) {
                      One_Click_Migration::write_to_log("Process is Restarting");
                      One_Click_Migration::$process_restore_single->restart_task();
                  }
                }
              }

              if (class_exists('ZipArchive') != true) {
                        One_Click_Migration::write_to_log('Error: Class ZipArchive not found. Please see <a href="http://www.php.net/manual/en/zip.installation.php">this page</a> for installation instructions or ask your hosting provider to enable the zip extension');
                  exit;
                      }

              if (self::restore_run_ended()) {
                  return OCM_BackgroundRestore::RETURN_TYPE_STOP_PROCESSS;
              }
              if(is_dir($content_dir)){
                $retry_count = get_option('ocm_restore_extract_retry_' . $key, 0);
                if(!$retry_count){
                  update_option('ocm_restore_extract_retry_' . $key, 1, true);

                }
                if($retry_count){
                  if($retry_count <= self::MAX_STEP_RETRIES){
                    if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT)) {
                    One_Click_Migration::write_to_log(sprintf('Extracting %s Retry %d', $key, $retry_count));
                    $retry_count = $retry_count + 1;
                    update_option('ocm_restore_extract_retry_' . $key, $retry_count, true);
                    }
                  }else{
                      if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT)) {

                        One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Extraction'));

                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT);
                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                        self::capture_skipped_restore_part($excluded_restore_files, $skipped_restore_files, $key);
                        continue;
                      }
                    }
                  }
                }
                if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT)) {
                  // Reset time limit for file extraction
                  @set_time_limit(One_Click_Migration::get_timeout());

                  One_Click_Migration::write_to_log(sprintf('Extracting file: "%s.zip.crypt"', $key));

                  $unencrypted_zip = self::get_restore_step_value($key, self::STEP_RESTORE_CHILD_UNENCRYPTED_ZIP);
                  if (!is_array($unencrypted_zip) || empty($unencrypted_zip['filename']) || !is_string($unencrypted_zip['filename']) || !file_exists($unencrypted_zip['filename'])) {
                      return self::fail_restore(sprintf(
                          'Error: Restore failed because decrypted zip for "%s.zip.crypt" was missing.',
                          $key
                      ));
                  }

                  $zip = new ZipArchive;
                  $res = $zip->open($unencrypted_zip['filename']);
                  if ($res !== true) {
                      return self::fail_restore(sprintf(
                          'Error: Zip file "%s" could not be opened for extraction.',
                          $unencrypted_zip['filename']
                      ));
                  }

                  $zip_uncompressed = 0;
                  for ($i = 0; $i < $zip->numFiles; $i++) {
                      $stat = $zip->statIndex($i);
                      if ($stat !== false) {
                          $zip_uncompressed += (int) $stat['size'];
                      }
                  }
                  $free_before_extract = self::get_free_disk_space($content_dir);
                  if ($free_before_extract > 0 && $zip_uncompressed > 0 && $free_before_extract < $zip_uncompressed) {
                      $zip->close();
                      return self::fail_restore(sprintf(
                          'Error: Not enough disk space to extract "%s.zip": need %s free, only %s available. Free space or increase the hosting account quota, then run the restore again.',
                          $key,
                          self::convertToReadableSize($zip_uncompressed),
                          self::convertToReadableSize($free_before_extract)
                      ));
                  }

                  $extract_result = $zip->extractTo($extract_path);
                  $zip->close();
                  if (self::restore_run_ended()) {
                      return OCM_BackgroundRestore::RETURN_TYPE_STOP_PROCESSS;
                  }

                  if (!$extract_result || !is_dir($extract_path)) {
                      return self::fail_restore(sprintf(
                          'Error: Zip file could not be extracted at "%s". Please check disk quota and write permissions.',
                          $extract_path
                      ));
                  }

                  // Extracted: record it before handing over (see download above).
                  self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT);

                  // Extraction succeeded, so the zip is no longer needed; free its
                  // space before the next part starts downloading.
                  @unlink($unencrypted_zip['filename']);

                  if (One_Click_Migration::$process_restore_single->time_exceeded()) {
                      One_Click_Migration::write_to_log("Process is Restarting");
                      One_Click_Migration::$process_restore_single->restart_task();
                  }
                }

              }
              if (self::restore_run_ended()) {
                  return OCM_BackgroundRestore::RETURN_TYPE_STOP_PROCESSS;
              }
              if(is_dir($content_dir)){
                $retry_count = get_option('ocm_restore_child_delete_retry_' . $key, 0);
                if(!$retry_count){
                  update_option('ocm_restore_child_delete_retry_' . $key, 1, true);

                }
                // Until the DB phase is done, themes/uploads only wait for their
                // deferred swap: a worker passing by (the DB import can span
                // several) is not a failed swap attempt.
                $swap_waits_for_db = in_array($key, array('themes', 'uploads'), true)
                    && !in_array('db', $excluded_restore_files, true)
                    && !self::check_is_complete_restore_step('db', self::STEP_RESTORE_CHILD_RESTORE);
                if($retry_count && !$swap_waits_for_db){
                  if($retry_count <= self::MAX_STEP_RETRIES){
                    if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW)) {
                    One_Click_Migration::write_to_log(sprintf('Deleting old %s Retry %d', $key, $retry_count));
                    $retry_count = $retry_count + 1;
                    update_option('ocm_restore_child_delete_retry_' . $key, $retry_count, true);
                    }
                  }else{
                      if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW)) {

                        One_Click_Migration::write_to_log(sprintf('Skipping %s restore after repeated timeouts (folder replace incomplete).', $key));

                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                        self::record_restore_part_warning($key, 'timeout');
                        self::capture_skipped_restore_part($excluded_restore_files, $skipped_restore_files, $key);
                        continue;
                      }
                    }
                  }
                if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW)) {
                    // themes/uploads/plugins file swaps wait until DB import AND
                    // options/prefix validation succeed (or until files-only path).
                    // Mark db/plugins extract-side steps only; deferred swap owns
                    // themes/uploads DELETE_OLD_AND_MOVE_NEW.
                    if (in_array($key, array('db', 'plugins'), true)) {
                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                    }
                }

                // After DB extract (db-first ordering), refuse before any later
                // themes/plugins/uploads replacement in this or a resumed run.
                if ($key === 'db'
                    && self::check_is_complete_restore_step('db', self::STEP_RESTORE_CHILD_EXTRACT)
                ) {
                    self::gate_restore_before_file_replace();
                }

              }
            }
        if(!is_dir($content_dir)){
          return OCM_BackgroundRestore::RETURN_TYPE_STOP_PROCESSS;
          update_option('ocm_is_stopped', true, true);
          die();

        }

        if (self::restore_run_ended()) {
            return OCM_BackgroundRestore::RETURN_TYPE_STOP_PROCESSS;
        }

        // Mid-restore db download/decrypt/extract failure must never fall through
        // to complete_restore() (which would replace nothing but still report success).
        if (get_option(self::OPTION_RESTORE_DB_PART_FAILED)) {
            self::stop_restore_after_db_part_failure();
        }
        if(in_array('db', $excluded_restore_files)){
          // Files-only: no DB import — swap extracted files now, then complete.
          self::apply_deferred_file_swaps($presigned_urls);
          self::complete_restore();
        }else{
          self::finish_restore_db_phase();
        }

        exit;
    }

    /**
     * Reduce a detailed "Error: ..." message to a short single-line reason
     * suitable for the terminal "Backup failed:" / "Restore failed:" markers.
     */
    private static function short_failure_reason($message)
    {
        $reason = trim(preg_replace('/^\s*Error:\s*/i', '', (string) $message));
        $reason = preg_replace('/\s+/', ' ', $reason);
        if (strlen($reason) > 160) {
            $reason = substr($reason, 0, 160) . '…';
        }
        return '' !== $reason ? $reason : 'unknown error (see previous log lines)';
    }

    /**
     * Terminal failure marker for a backup run. Support tooling and the
     * Mailgun alerter key off the literal "Backup failed:" prefix (mirroring
     * "Backup completed." on success) to tell a dead run from a hung one.
     * Does not exit; callers keep their own exit/redirect behaviour.
     */
    public static function fail_backup($message)
    {
        One_Click_Migration::write_to_log('Backup failed: ' . self::short_failure_reason($message));
        self::stop_zip_failover_job();
        One_Click_Migration::reset_actions_start_mark();
        update_option('ocm_is_stopped', true, true);
        $run_id = self::get_stored_backup_run_id();
        // Before the release, which forgets the run id the dir is named after.
        self::remove_empty_backup_work_dir($run_id);
        self::release_backup_run($run_id);
    }

    public static function fail_part_upload($part, $message)
    {
        $skipped = get_option('ocm_skipped_folders', array());
        self::capture_failed_folder(is_array($skipped) ? $skipped : array(), $part, 'ocm_skipped_folders');
        self::log_backup_parts_manifest(self::get_backup_missing_parts());
        self::fail_backup(sprintf('%s upload failed: %s', $part, $message));
        return OCM_BackgroundBackup::RETURN_TYPE_END_PROCESSS;
    }

    /**
     * Hand the backup over to a new worker once this one has used up its time
     * budget. Call it only after the finished step is recorded: the next worker
     * then moves on instead of redoing the step against its retry limit.
     */
    private static function hand_over_if_backup_time_exceeded()
    {
        $process = One_Click_Migration::$process_backup_single;
        if (is_object($process) && method_exists($process, 'time_exceeded') && $process->time_exceeded()) {
            One_Click_Migration::write_to_log("Process is Restarting");
            $process->restart_task();
        }
    }

    private static function fail_restore($message)
    {
        // This run already ended (another worker failed it, or a new run took
        // over): its failure is not news and must not reach the log (#96).
        if (self::restore_run_ended()) {
            return OCM_BackgroundRestore::RETURN_TYPE_STOP_PROCESSS;
        }
        One_Click_Migration::write_to_log($message);
        // Terminal marker, same contract as "Backup failed:" above.
        One_Click_Migration::write_to_log('Restore failed: ' . self::short_failure_reason($message));
        One_Click_Migration::disable_maintenance_mode();
        OCM_Debug_Mode::disable();
        One_Click_Migration::reset_actions_start_mark();
        update_option('ocm_is_stopped', true, true);
        self::end_restore_run();
        // Nothing was swapped in yet and a retry starts from scratch. Don't
        // leave the plaintext SQL dump under wp-content, where many servers
        // serve .sql files to anyone.
        self::remove_extracted_restore_sql();

        return OCM_BackgroundRestore::RETURN_TYPE_STOP_PROCESSS;
    }

    public static function complete_restore(){
      delete_option(OCM_Restore_Precheck::OPTION);
      $presigned_urls = get_option('ocm_presigned_urls');

        self::post_restore_cleanup();

        One_Click_Migration::write_to_log('Cleaning up.');
        
        // Add a small delay to ensure all file operations complete before cleanup
        sleep(2);

        // Delete the restore workspace (deleteDir() logs and stops on failure).
        if(WP_DEBUG !== true){
            self::delete_restore_workspaces('Cleaning up the restore workspace', $presigned_urls);
        }
        self::print_folders_skipped();
        self::print_folders_not_found();

        $backup_missing_parts = get_option('ocm_backup_missing_parts', []);
        if (is_array($backup_missing_parts) && $backup_missing_parts) {
            One_Click_Migration::write_to_log('Notice: Restore finished without: ' . implode(', ', $backup_missing_parts) . ' (these were not included in the backup).');
        }

        One_Click_Migration::disable_maintenance_mode();
        OCM_Debug_Mode::disable();
        // Durable flag so the admin UI can show the success modal after
        // post-restore logout / re-login (session cookies often invalidate).
        // Hard failures never reach here — fail_restore() / db-part stop /
        // finish_restore_db_phase() exit first.
        self::finalize_restore_completion_status();

        // Partial address-update completion was removed: URL rewrite errors now
        // stop before promote with LOG_RESTORE_STOPPED_IMPORT.
        delete_option('ocm_restore_partial_address_updates');
        delete_option('ocm_restore_failed_address_pairs');
        One_Click_Migration::reset_actions_start_mark();
        self::reset_current_restore_steps();
        update_option('ocm_is_stopped', true, true);
        self::end_restore_run();


    }

    /**
     * Write the restore terminal marker and arm UI flags.
     * Clean runs: "Restore completed." Warned runs name skipped parts when known.
     * Public for unit tests.
     */
    public static function finalize_restore_completion_status()
    {
        $missing_parts = get_option('ocm_backup_missing_parts', []);
        $skipped_folders = get_option('ocm_skipped_folders', []);
        $not_found_folders = get_option('ocm_eexcluded_folders', []);
        $backup_excluded_parts = get_option('ocm_backup_excluded_parts', []);
        $backup_excluded_parts = is_array($backup_excluded_parts) ? $backup_excluded_parts : array();
        $part_warnings = get_option(self::OPTION_RESTORE_PART_WARNINGS, array());
        $part_warnings = is_array($part_warnings) ? $part_warnings : array();

        // Defense in depth: never warn about parts the backup marked =excluded.
        // Must not hide a 404 on an included part (those are not in this list).
        foreach ($backup_excluded_parts as $excluded_part) {
            $excluded_part = strtolower((string) $excluded_part);
            if ($excluded_part !== '') {
                unset($part_warnings[$excluded_part]);
            }
        }

        // Derive warnings from legacy lists when a reason was not recorded.
        foreach (array(
            'timeout' => $skipped_folders,
            'not found' => $not_found_folders,
            'not in backup' => $missing_parts,
        ) as $reason => $list) {
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $part) {
                if (!is_string($part) || $part === '' || $part === 'log' || $part === 'log_download') {
                    continue;
                }
                $part = strtolower($part);
                if (in_array($part, $backup_excluded_parts, true)) {
                    continue;
                }
                if (!isset($part_warnings[$part])) {
                    $part_warnings[$part] = $reason;
                }
            }
        }
        ksort($part_warnings);

        if ($part_warnings !== array()) {
            $line = self::format_restore_warnings_terminal_line($part_warnings);
            One_Click_Migration::write_to_log(
                'Notice: Restore finished with warnings for: ' . implode(', ', array_keys($part_warnings)) . '.'
            );
            One_Click_Migration::write_to_log($line);
            self::apply_restore_success_flags(true, 'warnings');
            return;
        }

        One_Click_Migration::write_to_log('Restore completed.');
        // When the database was not restored for any intentional reason
        // (backup db=excluded, or the user unticked db for this restore on a
        // db=ok backup), take the files-only path: no review CTA / success
        // email until a later release reactivates other plugins. Warnings and
        // failures already returned above.
        $user_excluded = get_option('ocm_excluded_folders', array());
        $user_excluded = is_array($user_excluded) ? $user_excluded : array();
        $db_not_restored = in_array('db', $backup_excluded_parts, true)
            || in_array('db', $user_excluded, true);
        if ($db_not_restored) {
            self::apply_restore_success_flags(true, 'files-only');
            return;
        }
        self::apply_restore_success_flags(false, '');
    }

    /**
     * Build a warned terminal line that never contains the substring "Restore completed."
     * (period after completed). Example:
     * "Restore completed with warnings: uploads skipped (timeout), themes partially copied."
     *
     * @param array<string, string> $part_warnings
     * @return string
     */
    public static function format_restore_warnings_terminal_line(array $part_warnings)
    {
        $chunks = array();
        foreach ($part_warnings as $part => $reason) {
            $part = strtolower((string) $part);
            $reason = strtolower(trim((string) $reason));
            if ($part === '') {
                continue;
            }
            if ($reason === 'partially copied') {
                $chunks[] = $part . ' partially copied';
            } elseif ($reason === 'copied, old copy not removed') {
                $chunks[] = $part . ' copied, old copy not removed';
            } elseif ($reason === 'not in backup') {
                $chunks[] = $part . ' skipped (not in backup)';
            } elseif ($reason === 'not found') {
                $chunks[] = $part . ' skipped (not found)';
            } elseif ($reason === 'timeout') {
                $chunks[] = $part . ' skipped (timeout)';
            } else {
                $chunks[] = $part . ' skipped (' . ($reason !== '' ? $reason : 'see log') . ')';
            }
        }
        if ($chunks === array()) {
            return self::LOG_RESTORE_COMPLETED_WARNINGS_PREFIX . ' some parts were skipped (see log).';
        }
        return self::LOG_RESTORE_COMPLETED_WARNINGS_PREFIX . ' ' . implode(', ', $chunks) . '.';
    }

    /**
     * @param string $part
     * @param string $reason
     */
    public static function record_restore_part_warning($part, $reason)
    {
        $part = strtolower(trim((string) $part));
        $reason = strtolower(trim((string) $reason));
        if ($part === '' || $part === 'log' || $part === 'log_download') {
            return;
        }
        $current = get_option(self::OPTION_RESTORE_PART_WARNINGS, array());
        $current = is_array($current) ? $current : array();
        // Prefer the more specific "partially copied" / "copied, old copy not
        // removed" over a generic timeout for the same part.
        if (isset($current[$part]) && $reason === 'timeout'
            && ($current[$part] === 'partially copied' || $current[$part] === 'copied, old copy not removed')
        ) {
            return;
        }
        $current[$part] = $reason !== '' ? $reason : 'see log';
        update_option(self::OPTION_RESTORE_PART_WARNINGS, $current, false);
    }

    /**
     * Set restore-success modal + review CTA flags. Warned / missing-parts /
     * partial-address runs never arm the Review ask or increment the clean
     * count, so a later clean restore can still show Leave a review once.
     *
     * @param bool   $is_warned
     * @param string $reason    Short reason for the review_ask log line.
     */
    public static function apply_restore_success_flags($is_warned, $reason = '')
    {
        update_option('ocm_show_restore_success', time(), false);
        $is_partial = (is_string($reason) && false !== strpos($reason, 'partial'));
        // files-only is a clean Restore completed. — not a warned UI state.
        $is_files_only = ($reason === 'files-only');
        $is_warned_ui = $is_warned && !$is_partial && !$is_files_only;
        update_option('ocm_restore_was_partial', $is_partial ? 1 : 0, false);
        update_option(self::OPTION_RESTORE_WAS_WARNED, $is_warned_ui ? 1 : 0, false);
        if ($is_warned) {
            update_option('ocm_show_review_cta', 0, false);
            // files-only: do not consume ocm_clean_restore_count (flag kept).
            One_Click_Migration::write_to_log(sprintf(
                'SYSLOG: review_ask: none (%s)',
                $reason !== '' ? $reason : 'warned'
            ));
            return;
        }
        $clean_count = (int) get_option('ocm_clean_restore_count', 0) + 1;
        update_option('ocm_clean_restore_count', $clean_count, false);
        $dismissed = (bool) get_option('ocm_review_ask_dismissed', false);
        $show_cta = (!$dismissed && $clean_count >= 1);
        update_option('ocm_show_review_cta', $show_cta ? 1 : 0, false);
        if ($show_cta) {
            One_Click_Migration::write_to_log('SYSLOG: review_ask: restore-success with review CTA');
        } else {
            One_Click_Migration::write_to_log('SYSLOG: review_ask: none (dismissed or not eligible)');
        }
    }


    /**
     * Finish the destructive phase of the restore: DB restore (including staging
     * URL rewrite + promote) + deferred file swaps + cleanup.
     * Called inline at the end of start_restore_process() once downloads complete.
     */
    public static function finish_restore_db_phase()
       {
        global $wpdb;
        $presigned_urls   = get_option('ocm_presigned_urls');
        $excluded_folders = get_option('ocm_excluded_folders', []);

        //cater for the database restore.
        if(!in_array('db', $excluded_folders)){
          if (!self::check_is_complete_restore_step('db', self::STEP_RESTORE_CHILD_RESTORE)) {
              self::$db_restore_refused_binary = false;
              self::$db_restore_refused_options = false;
              if (!self::restore_db($presigned_urls, $wpdb)) {
                  if (self::$db_restore_refused_binary) {
                      // Terminal marker already written by stop_restore_during_db_import()
                      // or the early binary preflight path. Do not add generic
                      // import-failure lines.
                  } elseif (self::$db_restore_refused_options) {
                      // LOG_RESTORE_STOPPED_OPTIONS already written; do not add
                      // generic import-failure lines (those arm proc_open advice).
                  } elseif (self::$import_oom_stop_written) {
                      // LOG_RESTORE_STOPPED_IMPORT already written by the import
                      // OOM shutdown guard. Do not add a second stop line.
                  } else {
                      // Any other import-stage failure before the swap maps to
                      // RESTORE-STOPPED-IMPORT (live site untouched).
                      One_Click_Migration::write_to_log(self::LOG_RESTORE_STOPPED_IMPORT);
                  }
                  self::cleanup_after_restore_no_change_stop();
                  exit;
              }
              self::set_complete_restore_step('db', self::STEP_RESTORE_CHILD_RESTORE);
              // active_plugins is now the backup's list (just restored from the options table).
              // Leave it alone — URL rewrite already ran on staging tables inside
              // restore_db() (before promote), and we want the user's original
              // plugin set to be active again after restore.
          }
          // DB+options gate passed — only now deactivate other plugins and swap files.
          self::apply_deferred_file_swaps($presigned_urls);
        }

        // URL search-replace runs on temp-prefixed staging tables inside
        // restore_db() before the atomic RENAME / file swap. A failure there
        // stops with LOG_RESTORE_STOPPED_IMPORT and never reaches this point.
        self::complete_restore();
        exit;
    }

    /**
     * Log each failed approved address rewrite as `old → new` (optional table.column).
     * Never logs row payloads or secrets.
     *
     * @param array $failed_pairs
     */
    public static function log_failed_address_pairs($failed_pairs)
    {
        if (!is_array($failed_pairs)) {
            return;
        }
        foreach ($failed_pairs as $pair) {
            if (!is_array($pair) || empty($pair['search'])) {
                continue;
            }
            $line = sprintf(
                'Address update failed: %s → %s',
                (string) $pair['search'],
                isset($pair['replace']) ? (string) $pair['replace'] : ''
            );
            if (!empty($pair['table']) && !empty($pair['column'])) {
                $line .= sprintf(' (%s.%s)', (string) $pair['table'], (string) $pair['column']);
            }
            One_Click_Migration::write_to_log($line);
        }
    }

    public static function initiate_db_backup($presigned_urls, $aes_key)
    {
        global $wpdb;
        $work_path = self::get_backup_work_path();
        
        // Reset logging flags for this backup session
        OCM_SQL_Utils::reset_backup_logging_flags();

        One_Click_Migration::write_to_log('Database backup started');
        $db_size_mb = self::DBSize();
        One_Click_Migration::write_to_log('DB Size '. $db_size_mb.'MB');

        // Pre-flight: if DB ≳ memory_limit, warn and force streamed dump (never hold the SQL in RAM).
        OCM_SQL_Utils::preflight_db_memory((int) ((float) $db_size_mb * 1024 * 1024), 'dump');

        // Pre-flight disk space check: need ~2x DB size for SQL dumps + ZIP
        if (!self::ensure_temporary_directory()) {
            return OCM_BackgroundBackup::RETURN_TYPE_END_PROCESSS;
        }

        // A worker that only resumes the upload needs no room for a new dump, and
        // the encrypted archive it uploads already takes part of the space.
        $needs_dump_space = !self::check_is_complete_backup_step('db', self::STEP_BACKUP_CHILD_COMPRESS);
        $free_bytes = self::get_free_disk_space($work_path);
        if ($needs_dump_space && $free_bytes > 0 && $db_size_mb > 0) {
            $required_bytes = $db_size_mb * 2 * 1024 * 1024;
            if ($free_bytes < $required_bytes) {
                $disk_message = sprintf(
                    'Error: Not enough disk space to complete backup. Need %sMB, only %sMB available.',
                    round($required_bytes / 1024 / 1024),
                    round($free_bytes / 1024 / 1024)
                );
                One_Click_Migration::write_to_log($disk_message);
                self::fail_backup($disk_message);
                exit;
            }
        }

        $skipped_restore_files = get_option('ocm_skipped_folders', []);

        $retry_count = get_option('ocm_backup_compress_retry_db', 0);


        if($retry_count == 0){
          update_option('ocm_backup_compress_retry_db', 1, true);

        }
        if($retry_count){
          if($retry_count <= self::MAX_STEP_RETRIES){
            if (!self::check_is_complete_backup_step('db', self::STEP_BACKUP_CHILD_COMPRESS)) {
            One_Click_Migration::write_to_log(sprintf('Compressing %s Retry %d','db', $retry_count));
            $retry_count = $retry_count + 1;
            update_option('ocm_backup_compress_retry_db' , $retry_count, true);
          }
        }else{
            if (!self::check_is_complete_backup_step('db', self::STEP_BACKUP_CHILD_COMPRESS)) {

              One_Click_Migration::write_to_log(sprintf('Skipping DB compression'));
              One_Click_Migration::write_to_log(sprintf('Skipping DB encrypting'));
              One_Click_Migration::write_to_log(sprintf('Skipping DB upload'));
              self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_COMPRESS);
              self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_ENCRYPT);
              self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_UPLOAD);
              self::capture_failed_folder($skipped_restore_files, 'db', 'ocm_skipped_folders');
              One_Click_Migration::write_to_log("Notice: File DB was skipped due to timeout");
            }
          }
        }


        // If file exists already, delete it
        if (file_exists($work_path . 'db_backup.sql')) {
            unlink($work_path . 'db_backup.sql');
        }

        if (!self::check_is_complete_backup_step('db', self::STEP_BACKUP_CHILD_COMPRESS)) {


        $tables = $wpdb->get_results('SHOW TABLES');

        // If tmp directory doesn't exist, create it (in wp-content/tmp when
        // the private work dir isn't writable here)
        self::ensure_temporary_directory();
        $work_path = self::get_backup_work_path();
        if (!is_dir($work_path . 'db/')) {
            if (!mkdir($concurrentDirectory = $work_path . 'db/', 0700, true) && !is_dir($concurrentDirectory)) {
                One_Click_Migration::write_to_log(sprintf('Database backup started, Error: Directory "%s" was not created', $concurrentDirectory));

                One_Click_Migration::write_to_log(sprintf(
                    'Database backup started, Error: Directory "%s" was not created. Please check the write permission for the parent directory "%s".',
                    $concurrentDirectory, WP_CONTENT_DIR
                ));

                self::fail_backup('database backup directory could not be created (write permission)');
                exit;
            }
        }

        $url_found = array();
        $collect_urls = function ($value) use (&$url_found) {
            OCM_Extra_Domains::collect_from_text($url_found, $value);
        };
        foreach ($tables as $table) {
            foreach ($table as $t) {

                $file = $work_path . "db/$t.sql";
                if(file_exists($work_path . "db")){
                  // Suppressed: generates one log line per table, causing excessive log noise
                  // One_Click_Migration::write_to_log(sprintf(' SYSLOG: Table being backed up: "%s"', $t));
                  $exported = OCM_SQL_Utils::export([$file], [
                      'tables' => $t,
                      'quick' => null,
                      'ocm_collect_urls' => $collect_urls,
                  ]);
                  if ($exported === false) {
                      return self::fail_part_upload('db', sprintf('SQL dump write failed for table "%s"', $t));
                  }

                }else{
                  return OCM_BackgroundBackup::RETURN_TYPE_END_PROCESSS;

                }

            }
        }


          self::publish_url_manifest($url_found);
          $zip_filepath = self::createZipFile($work_path . 'db/', $aes_key, $presigned_urls);
          if (!$zip_filepath) {
              self::fail_backup('could not create the database archive (see previous error line)');
              exit;
          }

          // The encrypted archive is complete: record it before handing over, or
          // the next worker would dump the database again and, once its retries
          // ran out, skip the part.
          One_Click_Migration::write_to_log('Database ZIP file created.');
          self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_COMPRESS);
          self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_ENCRYPT);
          self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_ZIP_FILE_PATH, $zip_filepath);

          // Only the encrypted zip is uploaded; drop the per-table SQL dumps and
          // the plain zip now so they don't stack under later parts' disk needs.
          self::deleteDirectoryBestEffort($work_path . 'db/');
          @unlink($work_path . 'db.zip');

          self::hand_over_if_backup_time_exceeded();
        }

        $retry_count = get_option('ocm_backup_upload_retry_db', 0);

        if(!$retry_count){
          update_option('ocm_backup_upload_retry_db', 1, true);

        }
        if($retry_count){
          if($retry_count <= self::MAX_TRANSFER_RETRIES){

          One_Click_Migration::write_to_log(sprintf('Uploading %s Retry %d', 'db', $retry_count));
          $retry_count = $retry_count + 1;
          update_option('ocm_backup_upload_retry_db' , $retry_count, true);
        }else{

          One_Click_Migration::write_to_log(sprintf('Skipping DB upload'));
          self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_UPLOAD);
          self::capture_failed_folder($skipped_restore_files, 'db', 'ocm_skipped_folders');
          One_Click_Migration::write_to_log("Notice: File DB was skipped due to timeout");
        }

        }



        if (!self::check_is_complete_backup_step('db', self::STEP_BACKUP_CHILD_UPLOAD)) {
          One_Click_Migration::write_to_log(sprintf('%s upload has been started.', 'db'));

          if (empty($zip_filepath)) {
              $zip_filepath = self::get_backup_step_value('db', self::STEP_BACKUP_CHILD_ZIP_FILE_PATH);
          }
          
          if (empty($zip_filepath)) {
              // Fallback if path was not saved after encryption.
              $zip_filepath = $work_path . 'db.zip.crypt';
          }

          if (!is_string($zip_filepath) || !is_file($zip_filepath) || !is_readable($zip_filepath)) {
              return self::fail_part_upload('db', 'encrypted archive is missing or unreadable');
          }

          try {
              $return = OCM_S3::upload_zip($zip_filepath);
          } catch (\Throwable $e) {
              return self::fail_part_upload('db', $e->getMessage());
          }
          if ($return !== true) {
              return self::fail_part_upload('db', 'storage did not confirm the encrypted archive upload');
          }

          // A confirmed PUT is terminal success even if the request consumed the
          // worker's time budget. Persist success before any restart decision so
          // this part can never later be classified as skipped.
          self::mark_backup_part_uploaded('db');
          self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_UPLOAD);
          if (is_string($zip_filepath)) {
              @unlink($zip_filepath);
          }
          return false;

        }

    }

    public static function createZipFile($filepath, $aes_key, $presigned_urls)
    {
        // Suppress PHP 8+ compatibility warnings from vendor library
        $old_error_reporting = error_reporting();
        error_reporting($old_error_reporting & ~E_DEPRECATED & ~E_NOTICE);

        // Reset time limit for DB zip creation
        @set_time_limit(One_Click_Migration::get_timeout());

        $filename = self::get_backup_work_path() . 'db.zip';
        $skipped_restore_files = get_option('ocm_skipped_folders', []);

        if (!self::create_native_zip_from_directory($filepath, $filename)) {
            // The DB dump is bounded in size, so the in-process streaming
            // zip is the last resort even after the monitored system zip
            // failed (#69); only its own failure ends the backup.
            if (self::$archive_backend_failed) {
                One_Click_Migration::write_to_log(' SYSLOG: Trying in-process streaming zip as the last database archive failover.');
            }
            @unlink($filename);
            $zip = new ZipFile();
            $dir = new DirectoryIterator($filepath);
            foreach ($dir as $fileinfo) {
                if (!$fileinfo->isDot()) {
                    $zip->addFile($fileinfo->getPathname(), '/' . $fileinfo->getFilename(), ZipCompressionMethod::STORED);
                }
            }

            try {
                $zip->saveAsFile($filename);
            } catch (\Throwable $e) {
                @unlink($filename);
                One_Click_Migration::write_to_log(sprintf(
                    'Error: In-process zip failover could not save "%s": %s (free: %d bytes)',
                    basename($filename),
                    $e->getMessage(),
                    (int) self::get_free_disk_space(dirname($filename))
                ));

                self::fail_backup('database archive could not be created by ZipArchive, system zip or in-process zip: ' . $e->getMessage());
                exit;
            }
            $zip->close();
            delete_option('ocm_zip_close_in_progress_' . md5($filename));
            One_Click_Migration::write_to_log(sprintf(' SYSLOG: Created "%s" with in-process streaming zip.', basename($filename)));
        }

        $retry_count = get_option('ocm_backup_encrypt_retry_db', 0);
        if(!$retry_count){
          update_option('ocm_backup_encrypt_retry_db', 1, true);
        }
        if($retry_count){
          if($retry_count <= self::MAX_STEP_RETRIES){
            if (!self::check_is_complete_backup_step('db', self::STEP_BACKUP_CHILD_ENCRYPT)) {
              One_Click_Migration::write_to_log(sprintf('Encrypting %s Retry %d', 'db', $retry_count));
              $retry_count = $retry_count + 1;
              update_option('ocm_backup_encrypt_retry_db' , $retry_count, true);
            }
          }
          else{
            if (!self::check_is_complete_backup_step('db', self::STEP_BACKUP_CHILD_ENCRYPT)) {
              One_Click_Migration::write_to_log(sprintf('Skipping DB Encrypting'));
              One_Click_Migration::write_to_log(sprintf('Skipping DB upload'));
              self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_ENCRYPT);
              self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_UPLOAD);
              self::capture_failed_folder($skipped_restore_files, 'db', 'ocm_skipped_folders');
              One_Click_Migration::write_to_log("Notice: File DB was skipped due to timeout");
            }
          }
      }

        if (!self::check_is_complete_backup_step('db', self::STEP_BACKUP_CHILD_ENCRYPT)) {
          // Reset time limit for DB encryption
          @set_time_limit(One_Click_Migration::get_timeout());

          $return = self::encryptZipFile($filename, $aes_key);
          if (!$return) {
              self::fail_backup('encryption of the database archive failed (see previous error line)');
              exit;
          }

          // No hand-over here: initiate_db_backup() records the finished
          // archive first, then hands over if the budget is used up.
          One_Click_Migration::write_to_log('DB Zip file has been encrypted.');
          error_reporting($old_error_reporting);
          return $return;
        }

        // Restore original error reporting
        error_reporting($old_error_reporting);
    }

    public static function initiate_folder_backup($folder_name, $presigned_urls, $aes_key)
    {
        // Re-create tmp directory if it was deleted (e.g. by Stop & Reset while background process was still running)
        if (!self::ensure_temporary_directory()) {
            return OCM_BackgroundBackup::RETURN_TYPE_END_PROCESSS;
        }
        $work_path = self::get_backup_work_path();

        // Suppress PHP 8+ compatibility warnings from vendor library
        $old_error_reporting = error_reporting();
        error_reporting($old_error_reporting & ~E_DEPRECATED & ~E_NOTICE);
        
        // Initialize archive object
        $zip = new ZipFile();
        $filename = sprintf('%s%s.zip', $work_path, $folder_name);
        $folder_path = sprintf('%s/%s', WP_CONTENT_DIR, $folder_name);
        $skipped_restore_files = get_option('ocm_skipped_folders', []);



        // compress
        $retry_count = get_option('ocm_backup_compress_retry_' . $folder_name, 0);
        if(!$retry_count){
          update_option('ocm_backup_compress_retry_' . $folder_name, 1, true);

        }

        if($retry_count){
          if($retry_count <= self::MAX_STEP_RETRIES){
            if (!self::check_is_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_COMPRESS)) {
            One_Click_Migration::write_to_log(sprintf('Compressing %s Retry %d', $folder_name, $retry_count));
            $retry_count = $retry_count + 1;
            update_option('ocm_backup_compress_retry_' . $folder_name, $retry_count, true);
            }
          }
          else{
            if (!self::check_is_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_COMPRESS)) {

              One_Click_Migration::write_to_log(sprintf('Skipping ' .$folder_name.  ' Compressing'));
              One_Click_Migration::write_to_log(sprintf('Skipping ' .$folder_name.  ' Encypting'));
              One_Click_Migration::write_to_log(sprintf('Skipping ' .$folder_name.  ' Upload'));
              self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_COMPRESS);
              self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ENCRYPT);
              self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_UPLOAD);
              self::capture_failed_folder($skipped_restore_files, $folder_name, 'ocm_skipped_folders');
              One_Click_Migration::write_to_log("Notice: File $folder_name was skipped due to timeout");
            }

          }

        }
        if (!self::check_is_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_COMPRESS)) {
            //log folder sizes
            $folder_size_bytes = self::folderSize($folder_path);
            $folder_size = self::convertToReadableSize($folder_size_bytes);
            One_Click_Migration::write_to_log(sprintf('%s size: %s', $folder_name, $folder_size));

            // Pre-flight disk space check: need ~1.5x folder size for ZIP + encrypted copy
            $free_bytes = self::get_free_disk_space($work_path);
            if ($free_bytes > 0 && $folder_size_bytes > 0) {
                $required_bytes = $folder_size_bytes * 1.5;
                if ($free_bytes < $required_bytes) {
                    One_Click_Migration::write_to_log(sprintf(
                        'Error: Not enough disk space to backup %s. Need %s, only %s available.',
                        $folder_name,
                        self::convertToReadableSize($required_bytes),
                        self::convertToReadableSize($free_bytes)
                    ));
                    self::capture_failed_folder($skipped_restore_files, $folder_name, 'ocm_skipped_folders');
                    self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_COMPRESS);
                    self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ENCRYPT);
                    self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_UPLOAD);
                    error_reporting($old_error_reporting);
                    return false;
                }
            }

            // Reset time limit for folder archiving
            @set_time_limit(One_Click_Migration::get_timeout());

            try {
                One_Click_Migration::write_to_log(sprintf('Archiving %s to %s', $folder_name, $filename));
                if (!self::create_native_zip_from_directory($folder_path, $filename)) {
                    if (self::$archive_backend_failed) {
                        error_reporting($old_error_reporting);
                        if (self::$archive_interrupted) {
                            One_Click_Migration::write_to_log(sprintf(
                                'Notice: Your hosting kept stopping the backup while it packed the %1$s folder (%2$s), so the folder could not be packed. Remove files you don\'t need from wp-content/%1$s (for example old backups or large videos), or tick "Exclude %3$s" under Advanced options and move that folder another way, then start the backup again.',
                                $folder_name,
                                $folder_size,
                                ucfirst($folder_name)
                            ));
                        }
                        self::fail_backup(sprintf('archive creation failed for "%s" (see previous error line)', $folder_name));
                        return OCM_BackgroundBackup::RETURN_TYPE_END_PROCESSS;
                    }
                    foreach (self::folder_archive_files($folder_path) as $relativePath => $filePath) {
                        if (!is_readable($filePath)) {
                            continue;
                        }
                        $zip->addFile($filePath, $relativePath, ZipCompressionMethod::STORED);
                    }

                    $zip->saveAsFile($filename);
                    $zip->close();
                }
            } catch (ZipException $e) {



                One_Click_Migration::write_to_log(sprintf('Error: %s', $e->getMessage()));

                $next_retry_count = get_option('ocm_backup_compress_retry_' . $folder_name, 1);

                if($next_retry_count && $next_retry_count <= self::MAX_STEP_RETRIES){

                  One_Click_Migration::write_to_log("Process is Restarting");

                  update_option('ocm_backup_compress_retry_' . $folder_name, $next_retry_count, true);

                  One_Click_Migration::$process_backup_single->restart_task();
                }
                else{


                  One_Click_Migration::write_to_log(sprintf('Skipping ' .$folder_name.  ' Compressing'));
                  One_Click_Migration::write_to_log(sprintf('Skipping ' .$folder_name.  ' Encypting'));
                  One_Click_Migration::write_to_log(sprintf('Skipping ' .$folder_name.  ' Upload'));
                  self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_COMPRESS);
                  self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ENCRYPT);
                  self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_UPLOAD);
                  self::capture_failed_folder($skipped_restore_files, $folder_name, 'ocm_skipped_folders');
                  One_Click_Migration::write_to_log("Notice: File $folder_name was skipped due to timeout");
                }

            }

            // The archive is complete: record it before handing over, or the next
            // worker would zip the folder again and, once its retries ran out,
            // skip the part. (A ZipException that ran out of retries already
            // marked the part skipped.)
            if (!self::check_is_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_COMPRESS)) {
              self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_COMPRESS);

              One_Click_Migration::write_to_log(sprintf('%s ZIP has been created.', $folder_name));
            }

            self::hand_over_if_backup_time_exceeded();
        }

        // encrypt
        $retry_count = get_option('ocm_backup_encrypt_retry_' . $folder_name, 0);
        if(!$retry_count){
          update_option('ocm_backup_encrypt_retry_' . $folder_name, 1, true);

        }
        if($retry_count){
          if($retry_count <= self::MAX_STEP_RETRIES){
            if (!self::check_is_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ENCRYPT)) {
            One_Click_Migration::write_to_log(sprintf('Encrypting %s Retry %d', $folder_name, $retry_count));
            $retry_count = $retry_count + 1;
            update_option('ocm_backup_encrypt_retry_' . $folder_name, $retry_count, true);
            }
          }else{
            if (!self::check_is_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ENCRYPT)) {

              One_Click_Migration::write_to_log(sprintf('Skipping ' .$folder_name.  ' Encypting'));
              One_Click_Migration::write_to_log(sprintf('Skipping ' .$folder_name.  ' Upload'));
              self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ENCRYPT);
              self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_UPLOAD);
              self::capture_failed_folder($skipped_restore_files, $folder_name, 'ocm_skipped_folders');
              One_Click_Migration::write_to_log("Notice: File $folder_name was skipped due to timeout");
            }

          }

        }
        if (!self::check_is_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ENCRYPT)) {
            // Reset time limit for folder encryption
            @set_time_limit(One_Click_Migration::get_timeout());

            $zip_filepath = self::encryptZipFile($filename, $aes_key);
            if (!$zip_filepath) {
                self::fail_backup(sprintf('encryption of the "%s" archive failed (see previous error line)', $folder_name));
                exit;
            }

            // Encrypted: record it (and the path the upload reads) before handing
            // over, as for the archive above.
            self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ZIP_FILE_PATH, $zip_filepath);
            self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ENCRYPT);
            One_Click_Migration::write_to_log(sprintf('%s ZIP has been encrypted.', $folder_name));
            // The upload only needs the encrypted copy.
            @unlink($filename);

            self::hand_over_if_backup_time_exceeded();
        }

        // upload
        $retry_count = get_option('ocm_backup_upload_retry_' . $folder_name, 0);
        if(!$retry_count){
          update_option('ocm_backup_upload_retry_' . $folder_name, 1, true);

        }
        if($retry_count){
          if($retry_count <= self::MAX_TRANSFER_RETRIES){
            if (!self::check_is_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_UPLOAD)) {
            One_Click_Migration::write_to_log(sprintf('Uploading %s Retry %d', $folder_name, $retry_count));
            $retry_count = $retry_count + 1;
            update_option('ocm_backup_upload_retry_' . $folder_name, $retry_count, true);
            }
          }else{
            if (!self::check_is_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_UPLOAD)) {

            One_Click_Migration::write_to_log(sprintf('Skipping ' .$folder_name.  ' Uploading'));
            self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_UPLOAD);
            self::capture_failed_folder($skipped_restore_files, $folder_name, 'ocm_skipped_folders');
            One_Click_Migration::write_to_log("Notice: File $folder_name was skipped due to timeout");
            }
          }

        }
        if (!self::check_is_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_UPLOAD)) {
            $zip_filepath = self::get_backup_step_value($folder_name, self::STEP_BACKUP_CHILD_ZIP_FILE_PATH);

            if (empty($zip_filepath)) {
                // Construct the expected path as fallback
                $expected_path = sprintf('%s%s.zip.crypt', $work_path, $folder_name);
                if (file_exists($expected_path)) {
                    One_Click_Migration::write_to_log(sprintf(' SYSLOG: %s zipFilePath was empty but found encrypted file at expected path', $folder_name));
                    $zip_filepath = $expected_path;
                    self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ZIP_FILE_PATH, $zip_filepath);
                } else {
                    One_Click_Migration::write_to_log(sprintf(' SYSLOG: %s upload skipped - encrypted zip file not found at %s', $folder_name, $expected_path));
                    error_reporting($old_error_reporting);
                    return self::fail_part_upload($folder_name, 'encrypted archive is missing');
                }
            }

            One_Click_Migration::write_to_log(sprintf('%s folder upload has been started.', $folder_name));
            if (!is_string($zip_filepath) || !is_file($zip_filepath) || !is_readable($zip_filepath)) {
                error_reporting($old_error_reporting);
                return self::fail_part_upload($folder_name, 'encrypted archive is missing or unreadable');
            }

            try {
                $return = OCM_S3::upload_zip($zip_filepath);
            } catch (\Throwable $e) {
                error_reporting($old_error_reporting);
                return self::fail_part_upload($folder_name, $e->getMessage());
            }
            if ($return !== true) {
                error_reporting($old_error_reporting);
                return self::fail_part_upload($folder_name, 'storage did not confirm the encrypted archive upload');
            }

            // Upload confirmation wins over the worker time budget. Recording it
            // immediately prevents a retry from converting success into skipped.
            self::mark_backup_part_uploaded($folder_name);
            self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_UPLOAD);
            if (is_string($zip_filepath)) {
                @unlink($zip_filepath);
            }
            error_reporting($old_error_reporting);
            return false;
        }
        
        // Restore original error reporting
        error_reporting($old_error_reporting);
    }

    public static function deleteDir($dir, $action_log, $presigned_urls, $keep_root = false)
    {
        if (is_dir($dir)) {
            $objects = @scandir($dir);
            if (!$objects) {
                One_Click_Migration::write_to_log(sprintf(
                    '%s. Error: "%s" directory could not be deleted. Please check the write permission for this directory or the parent directory.',
                    $action_log, $dir
                ));

                update_option('ocm_is_stopped', true, true);
                exit;
            }

            foreach ($objects as $object) {
                if ($object !== '.' && $object !== '..') {
                    if (filetype($dir . '/' . $object) === 'dir') {
                        self::deleteDir($dir . '/' . $object, $action_log, $presigned_urls);
                    } else if (!unlink($dir . '/' . $object)) {
                        One_Click_Migration::write_to_log(sprintf(
                            '%s. Error: "%s" file could not be deleted. Please check the write permission for the parent directory.',
                            $action_log, $dir
                        ));

                        update_option('ocm_is_stopped', true, true);
                        exit;
                    }
                }
            }

            if (!$keep_root) {
                reset($objects);
                if (!rmdir($dir)) {
                    One_Click_Migration::write_to_log(sprintf(
                        '%s. Error: "%s" directory could not be deleted. Please check the write permission for this directory or the parent directory.',
                        $action_log, $dir
                    ));

                    update_option('ocm_is_stopped', true, true);
                    exit;
                }
            }
        }
    }

    private static function force_only_ocm_plugin_active($context)
    {
        wp_cache_delete('active_plugins', 'options');
        wp_cache_delete('alloptions', 'options');

        $active_plugins = get_option('active_plugins', array());
        if (!is_array($active_plugins)) {
            return;
        }

        $this_plugin_basename = plugin_basename(OCM_MAIN_FILE);
        $ocm_only = array($this_plugin_basename);
        $other_active_count = 0;

        foreach ($active_plugins as $plugin) {
            if ($plugin !== $this_plugin_basename) {
                $other_active_count++;
            }
        }

        if ($active_plugins === $ocm_only) {
            return;
        }

        if (!get_option('ocm_force_deactivated_plugins')) {
            update_option('ocm_force_deactivated_plugins', $active_plugins, true);
        }

        update_option('active_plugins', $ocm_only, true);
        One_Click_Migration::write_to_log(sprintf(
            'Temporarily deactivated %d plugins during restore (%s).',
            $other_active_count,
            $context
        ));
    }

    private static function replaceDirectoryFromExtract($extract_path, $directory, $key)
    {
        if (!is_dir($extract_path)) {
            return false;
        }

        $bak = rtrim($directory, '/\\') . '.ocm_bak';

        // Recover a previous interrupted swap: dest missing, bak still present.
        if (!is_dir($directory) && is_dir($bak)) {
            @rename($bak, $directory);
        }

        $had_dest = is_dir($directory);
        if ($had_dest) {
            if (is_dir($bak)) {
                self::deleteDirectoryBestEffort($bak);
            }
            if (!@rename($directory, $bak)) {
                One_Click_Migration::write_to_log(sprintf(
                    'Notice: Could not rename "%s" aside; merging restored files over the existing directory.',
                    $directory
                ));
                if (!self::copyDirectory($extract_path, $directory)) {
                    return false;
                }
                self::deleteDirectoryBestEffort($extract_path);
                return true;
            }
        }

        if (@rename($extract_path, $directory)) {
            if ($had_dest && is_dir($bak)) {
                self::deleteDirectoryBestEffort($bak);
            }
            return true;
        }

        // New tree failed to take dest's place — put the old tree back first.
        if ($had_dest && is_dir($bak) && !is_dir($directory)) {
            @rename($bak, $directory);
        }

        One_Click_Migration::write_to_log(sprintf(
            'Notice: Could not rename extract into "%s"; copying instead.',
            $directory
        ));

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            return false;
        }

        if (!self::copyDirectory($extract_path, $directory)) {
            return false;
        }

        self::deleteDirectoryBestEffort($extract_path);
        if ($had_dest && is_dir($bak)) {
            self::deleteDirectoryBestEffort($bak);
        }
        return true;
    }

    private static function deleteDirectoryBestEffort($dir, $keep_root = false)
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (is_link($dir) || is_file($dir)) {
            @chmod($dir, 0644);
            return @unlink($dir);
        }

        if (!is_dir($dir)) {
            return false;
        }

        $objects = @scandir($dir);
        if ($objects === false) {
            @chmod($dir, 0755);
            $objects = @scandir($dir);
        }

        if ($objects === false) {
            return false;
        }

        $success = true;
        foreach ($objects as $object) {
            if ($object === '.' || $object === '..') {
                continue;
            }

            $path = $dir . '/' . $object;
            if (is_dir($path) && !is_link($path)) {
                $success = self::deleteDirectoryBestEffort($path) && $success;
            } else {
                @chmod($path, 0644);
                $success = @unlink($path) && $success;
            }
        }

        if (!$keep_root) {
            @chmod($dir, 0755);
            $success = @rmdir($dir) && $success;
        }

        return $success;
    }

    /**
     * Start every restore with one empty workspace: remove all old ones (they
     * may hold a plaintext SQL dump), then create it in the private work dir,
     * or under wp-content with an unguessable name and deny files when that
     * isn't possible.
     *
     * @param string $attempted Set to the directory that could not be cleared or created.
     * @return string The workspace, or '' on failure.
     */
    private static function prepare_restore_workspace(&$attempted = '')
    {
        foreach (self::existing_restore_work_paths() as $old) {
            if (!self::deleteDirectoryBestEffort($old)) {
                $attempted = $old;
                return '';
            }
        }

        $candidates = array();
        if (self::private_work_dir_usable(true)) {
            $candidates[] = self::get_private_work_dir() . '/restore';
        }
        $candidates[] = WP_CONTENT_DIR . '/' . self::RESTORE_WORKSPACE_FALLBACK_PREFIX . self::random_dir_token();

        foreach ($candidates as $dir) {
            $attempted = $dir;
            if ((@mkdir($dir, 0700, true) || is_dir($dir)) && is_writable($dir)) {
                self::write_deny_files($dir);
                return $dir;
            }
            @rmdir($dir);
        }
        return '';
    }

    private static function copyDirectory($src, $dst)
    {
        if (!is_dir($src)) {
            return false;
        }

        if (!is_dir($dst)) {
            if (!@mkdir($dst, 0755, true) && !is_dir($dst)) {
                return false;
            }
        }

        $objects = @scandir($src);
        if (!$objects) {
            return false;
        }

        foreach ($objects as $object) {
            if ($object !== '.' && $object !== '..') {
                $srcPath = $src . '/' . $object;
                $dstPath = $dst . '/' . $object;

                if (is_link($srcPath)) {
                    if (file_exists($dstPath) || is_link($dstPath)) {
                        self::deleteDirectoryBestEffort($dstPath);
                    }

                    $link_target = readlink($srcPath);
                    if ($link_target === false || !@symlink($link_target, $dstPath)) {
                        return false;
                    }
                } else if (is_dir($srcPath)) {
                    if (!self::copyDirectory($srcPath, $dstPath)) {
                        return false;
                    }
                } else {
                    if (file_exists($dstPath)) {
                        @chmod($dstPath, 0644);
                    }

                    if (!@copy($srcPath, $dstPath)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private static function create_native_zip_from_directory($folder_path, $filename)
    {
        self::$archive_backend_failed = false;
        self::$archive_interrupted = false;
        $close_marker = 'ocm_zip_close_in_progress_' . md5($filename);

        // Empty uploads/themes/plugins must not fail the backup. ZipArchive::close()
        // with zero queued files returns true but writes no archive on several
        // PHP/libzip builds (field backup 48b6b15f). An empty DB dump must still
        // fail — never write a valid empty db.zip.
        if (!is_dir($folder_path) || !self::directory_contains_files($folder_path)) {
            delete_option($close_marker);
            if (self::archive_allows_empty($filename)) {
                One_Click_Migration::write_to_log(sprintf(
                    ' SYSLOG: "%s" has no files; writing a valid empty archive.',
                    basename($filename)
                ));
                return self::write_empty_zip_archive($filename);
            }
            One_Click_Migration::write_to_log(sprintf(
                'Error: "%s" source folder is empty; refusing to write an empty database archive.',
                basename($filename)
            ));
            return false;
        }

        // Normal sites always use ZipArchive. This marker survives a PHP
        // timeout/worker death inside close(). The close may nevertheless have
        // committed a valid central directory before PHP was terminated, so
        // accept that completed primary result before attempting failover.
        if (get_option($close_marker, false)) {
            if (self::is_valid_zip_archive($filename)) {
                delete_option($close_marker);
                One_Click_Migration::write_to_log(sprintf(
                    ' SYSLOG: Previous ZipArchive::close() completed "%s" before the worker exited; using the finished archive.',
                    basename($filename)
                ));
                return true;
            }

            self::$archive_interrupted = true;
            One_Click_Migration::write_to_log(sprintf(
                'Notice: Previous ZipArchive::close() did not finish for "%s"; switching to slower monitored zip failover.',
                basename($filename)
            ));
            return self::run_zip_close_failover($folder_path, $filename, $close_marker);
        }

        if (!class_exists('ZipArchive')) {
            return false;
        }

        $zip = new ZipArchive();
        $opened = $zip->open($filename, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            return false;
        }

        $queued_files = 0;
        $queued_bytes = 0;
        $last_tick = time();

        foreach (self::folder_archive_files($folder_path) as $relative_path => $file_path) {
            if (!is_readable($file_path)) {
                continue;
            }

            if (!$zip->addFile($file_path, $relative_path)) {
                $zip->close();
                @unlink($filename);
                return false;
            }

            if (defined('ZipArchive::CM_STORE')) {
                $zip->setCompressionName($relative_path, ZipArchive::CM_STORE);
            }

            $queued_files++;
            $size = @filesize($file_path);
            $queued_bytes += $size ? $size : 0;

            // Enumerating a huge uploads dir can take minutes on its own, and
            // nothing else in this loop resets the PHP timer or tells the
            // stall watchdog the worker is alive.
            if (0 === ($queued_files % 500) || (time() - $last_tick) >= 5) {
                @set_time_limit(One_Click_Migration::get_timeout());
                One_Click_Migration::worker_heartbeat();
                $last_tick = time();
            }
        }

        if ($queued_files === 0) {
            @$zip->close();
            delete_option($close_marker);
            if (self::archive_allows_empty($filename)) {
                One_Click_Migration::write_to_log(sprintf(
                    ' SYSLOG: "%s" queued 0 readable files; writing a valid empty archive.',
                    basename($filename)
                ));
                return self::write_empty_zip_archive($filename);
            }
            One_Click_Migration::write_to_log(sprintf(
                'Error: "%s" queued 0 readable files; refusing to write an empty database archive.',
                basename($filename)
            ));
            return false;
        }

        // addFile() only queues entries; close() is the single blocking call
        // that reads and writes every byte, so on multi-GB archives it can
        // exceed max_execution_time by itself. Lift the limit entirely where
        // the host allows it, otherwise fall back to the usual budget.
        One_Click_Migration::worker_heartbeat();
        if (!@set_time_limit(0)) {
            @set_time_limit(One_Click_Migration::get_timeout());
        }
        One_Click_Migration::write_to_log(sprintf(
            ' SYSLOG: Zip close starting for "%s": %d files, %d bytes queued.',
            basename($filename),
            $queued_files,
            $queued_bytes
        ));

        update_option($close_marker, time(), false);
        if (function_exists('error_clear_last')) {
            error_clear_last();
        }
        // libzip reports progress during close() (PHP 8.0+). Keep the worker's
        // heartbeat and lock fresh so the watchdog doesn't take it for a dead
        // one, and log how far it got in case the host stops it.
        if (method_exists($zip, 'registerProgressCallback')) {
            $worker = isset(One_Click_Migration::$process_backup_single) ? One_Click_Migration::$process_backup_single : null;
            $last_report = time();
            $zip->registerProgressCallback(0.001, function ($rate) use ($worker, $filename, &$last_report) {
                self::zip_heartbeat($worker);
                if (time() - $last_report >= 60) {
                    $last_report = time();
                    One_Click_Migration::write_to_log(sprintf(
                        ' SYSLOG: Zip close progress for "%s": %d%%',
                        basename($filename),
                        (int) floor($rate * 100)
                    ));
                }
            });
        }
        $closed = @$zip->close();
        if ($closed || self::is_valid_zip_archive($filename)) {
            delete_option($close_marker);
            if (!$closed) {
                One_Click_Migration::write_to_log(sprintf(
                    ' SYSLOG: ZipArchive::close() reported failure for "%s" but the archive is already valid; keeping the primary result.',
                    basename($filename)
                ));
            }
            return true;
        }

        // close() returned false: libzip discarded the archive. Record why
        // (status/warning, bytes left on disk, free space) so support can tell
        // a disk/quota failure from a libzip one, then fail over.
        $close_warning = error_get_last();
        try {
            $status = @$zip->getStatusString();
        } catch (\Throwable $e) {
            $status = '';
        }
        One_Click_Migration::write_to_log(sprintf(
            'Notice: ZipArchive::close() failed for "%s" (%d files, %d bytes queued; status: %s; warning: %s; on disk: %d bytes; free: %d bytes); switching to slower monitored zip failover.',
            basename($filename),
            $queued_files,
            $queued_bytes,
            is_string($status) && '' !== $status ? $status : 'n/a',
            is_array($close_warning) && isset($close_warning['message']) ? $close_warning['message'] : 'n/a',
            is_file($filename) ? (int) @filesize($filename) : 0,
            (int) self::get_free_disk_space(dirname($filename))
        ));
        return self::run_zip_close_failover($folder_path, $filename, $close_marker);
    }

    /**
     * Shared failover after ZipArchive::close() failed or hung. Tries the
     * monitored system zip first. When that is unavailable on the host (no
     * proc_open or zip binary), returns false with $archive_backend_failed
     * cleared so callers use their in-process PhpZip streaming fallback
     * instead of dead-ending. A system zip that ran and failed sets
     * $archive_backend_failed.
     */
    private static function run_zip_close_failover($folder_path, $filename, $close_marker)
    {
        // Never let a half-written primary archive be mistaken for a result,
        // unless it is the finished output of an earlier worker's system zip.
        $job = get_option(self::ZIP_FAILOVER_JOB_OPTION, null);
        if (!is_array($job) || !isset($job['filename']) || $job['filename'] !== $filename) {
            @unlink($filename);
        }
        $failover_result = self::create_cli_zip_from_directory($folder_path, $filename);
        if ($failover_result === true) {
            delete_option($close_marker);
            return true;
        }
        @unlink($filename);

        if ($failover_result === null) {
            self::$archive_backend_failed = false;
            One_Click_Migration::write_to_log(sprintf(
                'Notice: Monitored system zip is unavailable on this host for "%s"; switching to in-process streaming zip failover.',
                basename($filename)
            ));
            return false;
        }

        self::$archive_backend_failed = true;
        One_Click_Migration::write_to_log(sprintf(
            'Notice: Slower zip failover failed for "%s".',
            basename($filename)
        ));
        return false;
    }

    private static function is_valid_zip_archive($filename)
    {
        if (!class_exists('ZipArchive') || !is_file($filename) || !is_readable($filename)) {
            return false;
        }

        $zip = new ZipArchive();
        $flags = defined('ZipArchive::CHECKCONS') ? ZipArchive::CHECKCONS : 0;
        $opened = $zip->open($filename, $flags);
        if (true !== $opened) {
            return false;
        }

        return $zip->close();
    }

    /**
     * Slower failover used only after ZipArchive::close() failed or a persisted
     * marker proves it hung/timed out. It streams outside PHP and keeps worker
     * heartbeats moving instead of entering another uninterruptible close().
     */
    private static function create_cli_zip_from_directory($folder_path, $filename)
    {
        if (!is_dir($folder_path)) {
            return null;
        }

        if (!self::directory_contains_files($folder_path)) {
            if (self::archive_allows_empty($filename)) {
                return self::write_empty_zip_archive($filename);
            }
            return false;
        }

        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('proc_open')) {
            return null;
        }

        $probe = @proc_open(
            '/usr/bin/env zip -v',
            array(
                1 => array('file', '/dev/null', 'w'),
                2 => array('file', '/dev/null', 'w'),
            ),
            $probe_pipes
        );
        if (!is_resource($probe) || proc_close($probe) !== 0) {
            return null;
        }

        // A zip started by an earlier worker outlives that worker when the host
        // kills only the PHP process. Wait for it (or take its result) instead
        // of starting another one beside it: on a folder too big to pack in one
        // request, starting over never finishes and the copies fill the disk.
        $job = get_option(self::ZIP_FAILOVER_JOB_OPTION, null);
        if (is_array($job) && isset($job['filename']) && $job['filename'] === $filename) {
            if (self::zip_failover_job_running($job)) {
                One_Click_Migration::write_to_log(sprintf(
                    ' SYSLOG: Monitored system zip for "%s" from an earlier worker is still running (pid %d, %d s); waiting for it.',
                    basename($filename),
                    (int) $job['pid'],
                    time() - (int) $job['started']
                ));
                return self::wait_for_zip_failover_job($job, null);
            }
            if (is_file((string) $job['exit_file'])) {
                // It finished while no worker was watching.
                return self::finish_zip_failover_job($job, null);
            }
            self::stop_zip_failover_job();
        }

        if (self::is_valid_zip_archive($filename)) {
            return true;
        }

        @unlink($filename);
        self::remove_stale_zip_temp_files($filename);
        $job = array(
            'filename'  => $filename,
            'exit_file' => $filename . '.exit',
            'err_file'  => $filename . '.err',
            'pid'       => 0,
            'started'   => time(),
            'bytes'     => 0,
        );
        @unlink($job['exit_file']);
        @unlink($job['err_file']);
        // The shell records zip's exit code even after this worker is gone, and
        // a TERM to it (stop_zip_failover_job) stops zip as well.
        $script = "trap 'kill \$z 2>/dev/null; exit 143' TERM INT; "
            . '/usr/bin/env zip -0 -q -r ' . escapeshellarg($filename) . ' . 2>' . escapeshellarg($job['err_file'])
            . ' & z=$!; wait $z; echo $? > ' . escapeshellarg($job['exit_file']);
        $process = @proc_open(
            'exec /bin/sh -c ' . escapeshellarg($script),
            array(
                0 => array('file', '/dev/null', 'r'),
                1 => array('file', '/dev/null', 'w'),
                2 => array('file', '/dev/null', 'w'),
            ),
            $pipes,
            $folder_path
        );
        if (!is_resource($process)) {
            return null;
        }
        $status = proc_get_status($process);
        $job['pid'] = isset($status['pid']) ? (int) $status['pid'] : 0;
        update_option(self::ZIP_FAILOVER_JOB_OPTION, $job, false);

        return self::wait_for_zip_failover_job($job, $process);
    }

    /** Heartbeat plus process-lock refresh (throttled) from inside a long zip step. */
    private static function zip_heartbeat($worker)
    {
        if (method_exists(One_Click_Migration::class, 'transfer_heartbeat')) {
            One_Click_Migration::transfer_heartbeat($worker);
        } else {
            One_Click_Migration::worker_heartbeat();
        }
    }

    /** Monitored system zip that may outlive the worker that started it. */
    const ZIP_FAILOVER_JOB_OPTION = 'ocm_zip_failover_job';
    /** Never wait on (or signal) a recorded zip older than this. */
    const ZIP_FAILOVER_MAX_AGE = 21600;

    /**
     * Wait for the monitored zip, keeping the worker's heartbeat and lock
     * fresh. $process is this worker's own child; null for one adopted from
     * an earlier worker, which is watched through its pid instead.
     *
     * @param array         $job
     * @param resource|null $process
     * @return bool
     */
    private static function wait_for_zip_failover_job(array $job, $process)
    {
        $worker = isset(One_Click_Migration::$process_backup_single) ? One_Click_Migration::$process_backup_single : null;
        $last_progress = 0;
        while (true) {
            if (is_resource($process)) {
                $status = proc_get_status($process);
                $running = !empty($status['running']);
            } else {
                $running = self::zip_failover_job_running($job);
            }
            if (!$running) {
                break;
            }
            self::zip_heartbeat($worker);
            @set_time_limit(One_Click_Migration::get_timeout());
            // Packing that moves on is not a failed attempt: a big folder can
            // take several workers, each counted as a compress retry.
            if (time() - $last_progress >= 30) {
                $last_progress = time();
                $bytes = self::zip_failover_output_bytes($job);
                if ($bytes > (int) $job['bytes']) {
                    $job['bytes'] = $bytes;
                    update_option(self::ZIP_FAILOVER_JOB_OPTION, $job, false);
                    $folder_name = basename((string) $job['filename'], '.zip');
                    if (in_array($folder_name, array('themes', 'plugins', 'uploads'), true)) {
                        update_option('ocm_backup_compress_retry_' . $folder_name, 1, true);
                    }
                }
            }
            sleep(is_resource($process) ? 1 : 2);
        }

        return self::finish_zip_failover_job($job, $process);
    }

    /**
     * @param array         $job
     * @param resource|null $process
     * @return bool
     */
    private static function finish_zip_failover_job(array $job, $process)
    {
        $exit_code = -1;
        if (is_resource($process)) {
            $exit_code = proc_close($process);
        }
        $recorded = @file_get_contents((string) $job['exit_file']);
        if (is_string($recorded) && preg_match('/^\s*(\d+)\s*$/', $recorded, $m)) {
            $exit_code = (int) $m[1];
        } elseif ($exit_code === 0) {
            // The shell ended without recording zip's result.
            $exit_code = -1;
        }
        $error = (string) @file_get_contents((string) $job['err_file']);
        $filename = (string) $job['filename'];
        @unlink((string) $job['exit_file']);
        @unlink((string) $job['err_file']);
        delete_option(self::ZIP_FAILOVER_JOB_OPTION);

        if ($exit_code !== 0 || !is_file($filename) || filesize($filename) === 0) {
            @unlink($filename);
            One_Click_Migration::write_to_log(sprintf(
                'Error: Monitored system zip failed for "%s" (exit=%d): %s',
                basename($filename),
                $exit_code,
                substr(trim($error), 0, 500)
            ));
            return false;
        }

        One_Click_Migration::write_to_log(sprintf(
            ' SYSLOG: Created "%s" with monitored system zip.',
            basename($filename)
        ));
        return true;
    }

    /**
     * Whether the recorded zip job is still running. Only a live process whose
     * command line names this job's exit file counts, so a recycled pid is
     * never taken for it.
     *
     * @param array $job
     * @return bool
     */
    private static function zip_failover_job_running(array $job)
    {
        $pid = isset($job['pid']) ? (int) $job['pid'] : 0;
        if ($pid <= 1 || empty($job['exit_file']) || is_file($job['exit_file'])) {
            return false;
        }
        if (time() - (int) ($job['started'] ?? 0) > self::ZIP_FAILOVER_MAX_AGE) {
            return false;
        }
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
        if (!is_string($cmdline) || $cmdline === '') {
            $cmdline = self::process_command_line($pid);
        }
        return is_string($cmdline) && strpos($cmdline, (string) $job['exit_file']) !== false;
    }

    /** Command line of a process from ps (no /proc, e.g. BSD). */
    private static function process_command_line($pid)
    {
        if (!function_exists('proc_open')) {
            return null;
        }
        $ps = @proc_open(
            'ps -o args= -p ' . (int) $pid,
            array(1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w')),
            $pipes
        );
        if (!is_resource($ps)) {
            return null;
        }
        $out = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($ps);
        return trim($out);
    }

    /** Bytes zip has written so far: its temp files plus the finished archive. */
    private static function zip_failover_output_bytes(array $job)
    {
        $dir = dirname((string) $job['filename']);
        $bytes = 0;
        foreach (@scandir($dir) ?: array() as $name) {
            if (preg_match('/^zi[A-Za-z0-9]{6}$/', $name) || $name === basename((string) $job['filename'])) {
                $bytes += (int) @filesize($dir . '/' . $name);
            }
        }
        return $bytes;
    }

    /**
     * Temp files a killed zip left beside the archive: info-zip's "ziXXXXXX"
     * and libzip's "<archive>.XXXXXX". Each can be as big as the folder.
     * Only called when no zip job for this archive is running.
     *
     * @param string $filename
     * @return void
     */
    private static function remove_stale_zip_temp_files($filename)
    {
        $dir = dirname($filename);
        $libzip = '/^' . preg_quote(basename($filename), '/') . '\.[A-Za-z0-9]{6}$/';
        foreach (@scandir($dir) ?: array() as $name) {
            if (preg_match('/^zi[A-Za-z0-9]{6}$/', $name) || preg_match($libzip, $name)) {
                @unlink($dir . '/' . $name);
            }
        }
    }

    /**
     * Stop the recorded zip job, if it is still running, and forget it. Used
     * when a backup fails, starts again or is reset, so an orphaned zip
     * doesn't keep filling the disk.
     *
     * @return void
     */
    public static function stop_zip_failover_job()
    {
        $job = get_option(self::ZIP_FAILOVER_JOB_OPTION, null);
        delete_option(self::ZIP_FAILOVER_JOB_OPTION);
        if (!is_array($job) || !self::zip_failover_job_running($job)) {
            return;
        }
        $pid = (int) $job['pid'];
        if (function_exists('posix_kill')) {
            @posix_kill($pid, 15);
        } elseif (function_exists('proc_open')) {
            $kill = @proc_open('kill -TERM ' . $pid, array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes);
            if (is_resource($kill)) {
                proc_close($kill);
            }
        }
        One_Click_Migration::write_to_log(sprintf(' SYSLOG: Stopped monitored system zip for "%s" (pid %d).', basename((string) $job['filename']), $pid));
    }

    private static function directory_contains_files($folder_path)
    {
        if (!is_dir($folder_path)) {
            return false;
        }
        foreach (self::folder_archive_files($folder_path) as $file_path) {
            return true;
        }
        return false;
    }

    /**
     * The files a folder part packs, as entry name => real path to read.
     *
     * Entry names are the paths as walked, relative to $folder_path, never
     * cut from realpath(): wp-content, or a folder or file in it, may be
     * reached through a symlink (Bitnami links wp-content to another path,
     * themes and plugins are often linked in from elsewhere), so a real path
     * need not start with $folder_path at all.
     *
     * Symlinks are followed, as system zip follows them, so the archive holds
     * the files themselves and the restore extracts plain files. (PhpZip
     * would store a link it is given as a link, hence the real path.) A
     * directory link to a directory the walk is already inside, or to one
     * containing it, is skipped: it would only pack the same files again, or
     * never end. Dangling links are skipped, and so are subfolders that
     * cannot be opened, as system zip skips them; the part folder itself
     * throws UnexpectedValueException instead, so it never looks empty.
     * Readability of the files themselves is left to the caller.
     *
     * @param string   $dir
     * @param string   $prefix    entry name of $dir plus "/", '' for the part folder
     * @param string[] $ancestors real paths (with a trailing "/") of the directories being walked
     * @return \Generator
     */
    private static function folder_archive_files($dir, $prefix = '', array $ancestors = array())
    {
        try {
            $entries = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        } catch (\UnexpectedValueException $e) {
            if ('' === $prefix) {
                throw $e;
            }
            return;
        }
        $real = realpath($dir);
        $ancestors[] = rtrim(str_replace('\\', '/', false === $real ? $dir : $real), '/') . '/';

        foreach ($entries as $entry) {
            $path = $entry->getPathname();
            $name = $prefix . $entry->getFilename();
            if ($entry->isDir()) {
                if ($entry->isLink() && !self::can_follow_directory_link($path, $ancestors)) {
                    continue;
                }
                yield from self::folder_archive_files($path, $name . '/', $ancestors);
            } elseif ($entry->isFile()) {
                $real_path = $entry->getRealPath();
                yield $name => (false === $real_path ? $path : $real_path);
            }
        }
    }

    /**
     * @param string   $path      a symlink to a directory
     * @param string[] $ancestors see folder_archive_files()
     * @return bool
     */
    private static function can_follow_directory_link($path, array $ancestors)
    {
        $target = realpath($path);
        if (false === $target) {
            return false;
        }
        $target = rtrim(str_replace('\\', '/', $target), '/') . '/';
        foreach ($ancestors as $ancestor) {
            if (0 === strpos($ancestor, $target)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Empty archives are allowed for uploads/themes/plugins only — never for db.zip.
     *
     * @param string $filename
     * @return bool
     */
    private static function archive_allows_empty($filename)
    {
        $base = strtolower(basename((string) $filename));
        if ($base === 'db.zip') {
            return false;
        }
        return in_array($base, array('uploads.zip', 'themes.zip', 'plugins.zip'), true)
            || preg_match('/^(uploads|themes|plugins)\.zip$/i', $base);
    }

    /**
     * Write a minimal valid empty ZIP (EOCD only). Used for empty uploads/themes/
     * plugins parts so encryption and restore still have a real archive.
     *
     * @param string $filename
     * @return bool
     */
    public static function write_empty_zip_archive($filename)
    {
        @unlink($filename);
        $empty_zip = hex2bin('504b0506000000000000000000000000000000000000');
        if (!is_string($empty_zip)) {
            return false;
        }
        return file_put_contents($filename, $empty_zip) === strlen($empty_zip)
            && is_file($filename)
            && is_readable($filename);
    }


    private static function post_restore_cleanup()
    {
        global $wp_rewrite;
        $wp_rewrite->init();

        if (function_exists('save_mod_rewrite_rules')) {
            save_mod_rewrite_rules();
        }
        if (function_exists('iis7_save_url_rewrite_rules')) {
            iis7_save_url_rewrite_rules();
        }
    }

    /**
     * Short, log-safe summary of the last PHP filesystem error. Never includes
     * file contents; distinguishes hosting quota (EDQUOT, errno 122) from a
     * full disk (ENOSPC, errno 28) because Support triages them differently.
     */
    public static function last_fs_error_summary()
    {
        $err = error_get_last();
        $message = ($err && isset($err['message'])) ? (string) $err['message'] : '';

        $errno = 0;
        if (preg_match('/errno=(\d+)/', $message, $m)) {
            $errno = (int) $m[1];
        } elseif (false !== stripos($message, 'Disk quota exceeded')) {
            $errno = 122;
        } elseif (false !== stripos($message, 'No space left on device')) {
            $errno = 28;
        }

        if (122 === $errno) {
            return 'Reason: errno=122 EDQUOT (hosting disk quota exceeded — not the same as disk full/ENOSPC).';
        }
        if (28 === $errno) {
            return 'Reason: errno=28 ENOSPC (no space left on device).';
        }
        if ('' === $message) {
            return 'Reason: unknown I/O error.';
        }

        // Keep the reason short and printable so nothing binary can reach the log.
        $message = preg_replace('/[^\x20-\x7E]/', '?', $message);
        return 'Reason: ' . substr($message, 0, 200);
    }

    public static function encryptZipFile($filename, $key)
    {
        // open file to read
        if (false === ($file_handle = fopen($filename, 'rb'))) {
            One_Click_Migration::write_to_log(sprintf('Error: Failed to open file "%s" for encryption. %s', $filename, self::last_fs_error_summary()));
            return false;
        }

        $encrypted_path = sprintf('%s/encrypted_%s', dirname($filename), basename($filename));
        $data_encrypted = 0;
        $buffer_size = 2097152;
        $file_size = filesize($filename);

        $rijndael = new Rijndael();
        $rijndael->setKey($key);
        $rijndael->disablePadding();
        $rijndael->enableContinuousBuffer();

        // open new file from new path
        if (false === ($encrypted_handle = fopen($encrypted_path, 'wb+'))) {
            One_Click_Migration::write_to_log(sprintf('Error: Failed to open file "%s" for writing. %s', $encrypted_path, self::last_fs_error_summary()));
            fclose($file_handle);
            return false;
        }

        $chunk_index = 0;
        $last_progress_mark = 0;

        // loop around the file
        while ($data_encrypted < $file_size) {

            // A 41.5GB archive is ~20k 2MB chunks — far beyond one
            // max_execution_time budget. Refresh the timer and the stall
            // watchdog every 8 chunks (16MB) to keep the heartbeat cheap.
            if (0 === ($chunk_index % 8)) {
                @set_time_limit(One_Click_Migration::get_timeout());
                One_Click_Migration::worker_heartbeat();
            }
            $chunk_index++;

            // read buffer-sized amount from file
            if (false === ($file_part = fread($file_handle, $buffer_size))) {
                One_Click_Migration::write_to_log(sprintf(
                    'Error: Failed to read from file "%s" at offset %d of %d bytes. %s',
                    $filename,
                    $data_encrypted,
                    $file_size,
                    self::last_fs_error_summary()
                ));
                fclose($encrypted_handle);
                fclose($file_handle);
                return false;
            }

            // check to ensure padding is needed before encryption
            $length = strlen($file_part);
            if (0 !== $length % 16) {
                $pad = 16 - ($length % 16);
                $file_part = str_pad($file_part, $length + $pad, chr($pad));
            }

            $encrypted_data = $rijndael->encrypt($file_part);

            // A short write (quota/disk full) corrupts the archive the same way a
            // failed write does, so treat both as fatal. Never log the payload —
            // binary ciphertext in the log breaks the JSON log consumers.
            $written = fwrite($encrypted_handle, $encrypted_data);
            if (false === $written || $written < strlen($encrypted_data)) {
                One_Click_Migration::write_to_log(sprintf(
                    'Error: Failed to write encrypted data to "%s" (wrote %s of %d bytes for this chunk, %d of %d source bytes done). %s',
                    $encrypted_path,
                    false === $written ? 'none' : (string) $written,
                    strlen($encrypted_data),
                    min($data_encrypted, $file_size),
                    $file_size,
                    self::last_fs_error_summary()
                ));
                fclose($encrypted_handle);
                fclose($file_handle);
                return false;
            }

            $data_encrypted += $buffer_size;

            // Progress marker for Support (offsets only, never payload) so a
            // long-running encrypt is distinguishable from a hang.
            if (($data_encrypted - $last_progress_mark) >= 536870912) {
                $last_progress_mark = $data_encrypted;
                One_Click_Migration::write_to_log(sprintf(
                    ' SYSLOG: Encrypting "%s": %d MB of %d MB done.',
                    basename($filename),
                    (int) floor(min($data_encrypted, $file_size) / 1048576),
                    (int) floor($file_size / 1048576)
                ));
            }
        }

        // close the main file handle
        fclose($encrypted_handle);
        fclose($file_handle);

        // encrypted path
        $result_path = $filename . '.crypt';

        // need to replace original file with tmp file
        if (false === @rename($encrypted_path, $result_path)) {
            One_Click_Migration::write_to_log(sprintf('Error: Failed rename failed: "%s" -> "%s". %s', $encrypted_path, $result_path, self::last_fs_error_summary()));
            return false;
        }

        return $result_path;
    }

    public static function decryptZipFile($filename, $key)
    {
        // open file to read
        if (false === ($file_handle = fopen($filename, 'rb'))) {
            One_Click_Migration::write_to_log(sprintf('Error: Failed to open file "%s" for decryption. %s', $filename, self::last_fs_error_summary()));
            return false;
        }

        $decrypted_path = dirname($filename) . '/decrypt_' . basename($filename);
        // open new file from new path
        if (false === ($decrypted_handle = fopen($decrypted_path, 'wb+'))) {
            One_Click_Migration::write_to_log(sprintf('Error: Failed to open file "%s" for writing. %s', $decrypted_path, self::last_fs_error_summary()));
            fclose($file_handle);
            return false;
        }

        // setup encryption
        $rijndael = new Rijndael();
        $rijndael->setKey($key);
        $rijndael->disablePadding();
        $rijndael->enableContinuousBuffer();

        $file_size = filesize($filename);
        $bytes_decrypted = 0;
        $buffer_size = 2097152;

        // loop around the file
        while ($bytes_decrypted < $file_size) {
            // read buffer sized amount from file
            if (false === ($file_part = fread($file_handle, $buffer_size))) {
                One_Click_Migration::write_to_log(sprintf(
                    'Error: Failed to read from file "%s" at offset %d of %d bytes. %s',
                    $filename,
                    $bytes_decrypted,
                    $file_size,
                    self::last_fs_error_summary()
                ));
                fclose($decrypted_handle);
                fclose($file_handle);
                return false;
            }
            // check to ensure padding is needed before decryption
            $length = strlen($file_part);
            if (0 !== $length % 16) {
                $pad = 16 - ($length % 16);
                $file_part = str_pad($file_part, $length + $pad, chr($pad));
            }

            $decrypted_data = $rijndael->decrypt($file_part);

            $is_last_block = ($bytes_decrypted + strlen($decrypted_data) >= $file_size);

            $write_bytes = min($file_size - $bytes_decrypted, strlen($decrypted_data));
            if ($is_last_block) {
                $is_padding = false;
                $last_byte = ord($decrypted_data[strlen($decrypted_data) - 1]);
                if ($last_byte < 16) {
                    $is_padding = true;
                    for ($j = 1; $j <= $last_byte; $j++) {
                        if ($decrypted_data[strlen($decrypted_data) - $j] !== chr($last_byte)) {
                            $is_padding = false;
                        }
                    }
                }
                if ($is_padding) {
                    $write_bytes -= $last_byte;
                }
            }

            // Never log the decrypted payload here — path, sizes and errno only.
            // A full disk or quota can return a short count rather than false.
            $written = fwrite($decrypted_handle, $decrypted_data, $write_bytes);
            if (false === $written || $written < $write_bytes) {
                One_Click_Migration::write_to_log(sprintf(
                    'Error: Failed to write decrypted data to "%s" (%d of %d source bytes done). %s',
                    $decrypted_path,
                    min($bytes_decrypted, $file_size),
                    $file_size,
                    self::last_fs_error_summary()
                ));
                fclose($decrypted_handle);
                fclose($file_handle);
                return false;
            }
            $bytes_decrypted += $buffer_size;
            One_Click_Migration::transfer_heartbeat(One_Click_Migration::$process_restore_single);
            if (self::restore_run_ended_throttled()) {
                // The run ended while this worker was decrypting (#96).
                fclose($decrypted_handle);
                fclose($file_handle);
                @unlink($decrypted_path);
                return false;
            }
        }

        // close the main file handle
        fclose($decrypted_handle);
        // close original file
        fclose($file_handle);

        // remove the crypt extension from the end as this causes issues when opening
        $filename_new = preg_replace('/\.crypt$/', '', $filename, 1);
        // //need to replace original file with tmp file

        $filename_basename = basename($filename_new);

        if (false === @rename($decrypted_path, $filename_new)) {
            One_Click_Migration::write_to_log(sprintf('Error: Failed rename failed: "%s" -> "%s". %s', $decrypted_path, $filename_new, self::last_fs_error_summary()));
            return false;
        }

        // need to send back the new decrypted path
        return array(
            'filename' => $filename_new,
            'basename' => $filename_basename
        );
    }

    /**
     * Revive a stalled background worker from the progress poller. The poller is
     * the only request guaranteed to keep running during a migration on hosts
     * that block loopback requests, so it doubles as the watchdog: when a run is
     * in flight but the worker heartbeat has gone stale, resume it inline.
     * WordPress cron runs the same check for a restore (run_restore_watchdog()),
     * so a restore also resumes with the plugin page closed.
     *
     * Only runs in REST/ajax or cron context — trigger_processing() ends its
     * request with wp_die(), which is fine for a poll or a cron run but would
     * blank an admin page render.
     *
     * @param bool $is_stopped
     * @param bool $from_cron  Called from the restore watchdog cron event.
     */
    private static function maybe_revive_stalled_worker($is_stopped, $from_cron = false)
    {
        if ($is_stopped) {
            return;
        }

        if (!$from_cron && !(defined('REST_REQUEST') && REST_REQUEST) && !wp_doing_ajax()) {
            return;
        }

        $backup_active  = (bool) get_option('ocm_action_start_backup');
        $restore_active = (bool) get_option('ocm_action_start_restore');
        if (!$backup_active && !$restore_active) {
            return;
        }

        $process = $restore_active
            ? One_Click_Migration::$process_restore_single
            : One_Click_Migration::$process_backup_single;
        if (!is_object($process) || !method_exists($process, 'trigger_processing')) {
            return;
        }

        $now = time();
        // A worker that died on a PHP fatal asked for this: no need to wait for
        // its heartbeat to go stale.
        $resume_option = $restore_active ? self::RESTORE_RESUME_OPTION : self::BACKUP_RESUME_OPTION;
        $resume_requested = (int) get_option($resume_option, 0) > 0;
        // On hosts where the loopback gate already failed there is no healthy
        // dispatch chain to wait for, so stale means stalled much sooner.
        $threshold = get_option('ocm_dispatch_blocked') ? 25 : 45;

        $heartbeat = (int) get_option('ocm_worker_heartbeat', 0);
        if (!$resume_requested) {
            if (0 === $heartbeat) {
                // First sighting of this run: start the clock instead of assuming a stall.
                One_Click_Migration::worker_heartbeat();
                return;
            }
            if (($now - $heartbeat) <= $threshold) {
                return;
            }
        }

        // A restore worker in a long step without heartbeats (e.g. extracting a
        // large zip) still holds its process lock. It isn't stalled; a second
        // worker would race it on the same files (#79).
        if ($restore_active && OCM_BackgroundRestore::is_worker_file_lock_held()) {
            return;
        }

        // Throttle so a burst of concurrent polls produces a single pump.
        $last_pump = (int) get_option('ocm_worker_watchdog_last', 0);
        if (($now - $last_pump) <= 20) {
            return;
        }
        update_option('ocm_worker_watchdog_last', $now, false);

        // Still marked active, but no work item left: nothing can ever move
        // this run on. Without this it kept the backup slot (Start backup
        // answered "in progress") or the maintenance page forever.
        if (is_callable(array($process, 'is_queue_empty')) && $process->is_queue_empty()) {
            self::end_orphaned_run($restore_active);
            return;
        }

        if ($resume_requested) {
            if ($restore_active) {
                delete_option(self::RESTORE_RESUME_OPTION);
            } else {
                delete_option(self::BACKUP_RESUME_OPTION);
            }
            One_Click_Migration::write_to_log(sprintf(
                ' SYSLOG: Resuming the %s after its worker stopped on a PHP fatal (%s)',
                $restore_active ? 'restore' : 'backup',
                $from_cron ? 'cron' : 'progress poll'
            ));
            // trigger_processing() never returns once a worker runs; false means
            // it couldn't start one yet, so ask again on the next check.
            if (false === $process->trigger_processing()) {
                if ($restore_active) {
                    update_option(self::RESTORE_RESUME_OPTION, $now, false);
                } else {
                    update_option(self::BACKUP_RESUME_OPTION, $now, false);
                }
            }
            return;
        }

        $revivals = (int) get_option('ocm_worker_revival_count', 0) + 1;
        update_option('ocm_worker_revival_count', $revivals, false);

        One_Click_Migration::write_to_log(sprintf(
            ' SYSLOG: Worker heartbeat is %d seconds old; resuming via direct processing (revival #%d%s)',
            $now - $heartbeat,
            $revivals,
            $from_cron ? ', cron' : ''
        ));

        if (3 === $revivals) {
            One_Click_Migration::write_to_log('Notice: Background dispatch appears blocked on this host; the process continues in direct mode and may take longer than usual.');
        }

        // trigger_processing() no-ops behind the process-lock transient and the
        // MySQL advisory lock, so a false-positive pump cannot double-process.
        $process->trigger_processing();
    }

    public static function get_progress()
    {
        $log_url = OCM_DEBUG_LOG_FILE_URL;
        $lastLog = self::get_last_log_message();

        $uploadFileData = get_option('ocm_upload_file');
        $isStopped = get_option('ocm_is_stopped');

        // A poll must not tear anything down. It used to run cancel_all_process()
        // whenever ocm_is_stopped was set, which a new restore only clears once its
        // worker starts: a poll in that window deleted the new run's queue row and
        // restore_steps behind the worker's option cache, and dispatched both
        // cancelled processes. Terminal paths drop their own queue rows, and stale
        // items are refused by run ownership.
        self::maybe_revive_stalled_worker($isStopped);

        $action_in_flight = get_option('ocm_action_start_backup') || get_option('ocm_action_start_restore');

        // Workers hand over every few seconds (the worker time budget), and some
        // lines aren't in the progress map. Neither replaces the step on screen:
        // that showed "Process is Restarting", or "Starting restore…" back at 1%.
        // Nor does a PHP fatal's SYSLOG copy: it can come from any request during
        // the run (a poll that loaded a plugin mid-swap), and the raw message
        // logged right after it is what the card shows as the error.
        // Show the newest step the map knows instead.
        $last_message = is_string($lastLog) ? trim($lastLog) : '';
        // A raw PHP error while a run is active: Error_Handler logs fatals from
        // any request on the site then, and the run itself either resumes or
        // isn't affected. Say so in plain words and keep the step's percentage
        // instead of dropping to 0%.
        if ($action_in_flight && self::is_raw_php_fatal_line($last_message)) {
            $step = self::get_last_progress_step(true);
            $percent = null !== $step ? self::get_percent_by_log_message($step) : false;
            if (0 === strpos($last_message, 'Maximum execution time of ') || 0 === strpos($last_message, 'Allowed memory size of ')) {
                $text = self::progress_status_text($last_message);
            } else {
                $text = esc_html__('The site reported an unexpected error. The migration keeps going on its own; if it stops, the details are in the log.', '1-click-migration');
            }
            return array(
                'text' => $text,
                'value' => false !== $percent ? $percent : '1%',
                'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false),
            );
        }
        // A crashed backup or restore resuming on its own: show its notice at
        // the percentage of the step it was on, not the crash's 0%.
        if (self::is_resume_notice($last_message)) {
            $step = self::get_last_progress_step();
            $percent = null !== $step ? self::get_percent_by_log_message($step) : false;
            return array(
                'text' => self::progress_status_text($last_message),
                'value' => false !== $percent ? $percent : '1%',
                'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false),
            );
        }
        if ($last_message !== ''
            && strpos($last_message, 'was skipped due to timeout') === false
            && (self::is_handover_line($last_message)
                || self::is_php_fatal_syslog_line($last_message)
                || false === self::get_percent_by_log_message($last_message))
        ) {
            $step = self::get_last_progress_step();
            $lastLog = null !== $step ? $step : '';
        }

        // "Taking longer than expected" is for a real stall (the watchdog had to
        // revive a worker), not for a planned hand-over.
        // The notice itself stays English: admin-script.js files it under
        // "Notice:". The forum help goes after a line break, out of that check.
        $stall_notice = ($action_in_flight && (int) get_option('ocm_worker_revival_count', 0) > 0)
            ? self::LOG_MESSAGE_BG_PROCESS_RESTARTING_LOG . '.</br>' . self::support_forum_html(__('Stuck for a long time?', '1-click-migration'))
            : '';
        // On a host that blocks loopback requests, the run moves on only while
        // this page polls (or WordPress cron gets traffic): the one case where
        // the user should keep the page open, so tell them.
        if ($action_in_flight && get_option('ocm_dispatch_blocked')) {
            $keep_open = 'Notice: ' . esc_html__('This host limits background work, so keep this page open until it finishes.', '1-click-migration');
            $stall_notice = '' === $stall_notice ? $keep_open : $keep_open . '</br>' . $stall_notice;
        }

        if ($lastLog && strpos($lastLog, 'was skipped due to timeout') !== false) {


            return [
                'text' => $lastLog,
                'value' => self::get_previous_log_percentage(),
                'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
            ];
        }

        if ($action_in_flight) {
            $default_text_value = get_option('ocm_action_start_restore')
                ? esc_html__('Starting restore…', '1-click-migration')
                : esc_html__('Starting backup…', '1-click-migration');
        } else {
            $default_text_value = esc_html__('Start a backup or a restore to see current progress here.', '1-click-migration')
                . '</br></br>' . esc_html__('Backups and restores run in the background, so you can leave this page and come back later.', '1-click-migration')
                . '</br> </br>' . esc_html__('If you get logged out during a restore, log back in with the username and password of the site you backed up, then refresh this page.', '1-click-migration');
        }

        $default_text = array(
            'text' => $default_text_value,
            'value' => '1%',  // Changed from 0% to 1% to prevent initial progress bar jump from 1% → 0% → 1%
            'debugLogLine' => $lastLog,  // Add the unmapped log line for debugging
            'wpDebug' => defined('WP_DEBUG') && WP_DEBUG,  // Add WP_DEBUG flag for conditional console logging
            'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
        );
        if ($stall_notice !== '') {
            $default_text['customNotice'] = $stall_notice;
        }

        $multipart_pct = self::get_multipart_upload_percentage($lastLog);
        if ($multipart_pct !== false) {
            $progress_item_text = array(
                'uploadFileData' => $uploadFileData,
                'text' => self::progress_status_text($lastLog),
                'value' => $multipart_pct,
                'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
            );

            if ($isStopped) {
                $progress_item_text['isStopped'] = true;
            } elseif ($stall_notice !== '') {
                $progress_item_text['customNotice'] = $stall_notice;
            }

            return $progress_item_text;
        }

        foreach (self::$progress_data as $item_key => $progress_item) {


            if (isset($progress_item[0]) && strpos($lastLog, $progress_item[0]) !== false) {

                $progress_item_text = array(
                    'uploadFileData' => $uploadFileData,
                    'text' => self::progress_status_text($lastLog),
                    'value' => $progress_item[1],
                    'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
                );
                if ($stall_notice !== '') {
                    $progress_item_text['customNotice'] = $stall_notice;
                }

                $restore_terminal = ($lastLog === 'Restore completed.'
                    || strpos($lastLog, self::LOG_RESTORE_COMPLETED_WARNINGS_PREFIX) === 0);
                // An "Error:" line with no backup or restore active ended the
                // run (write_to_log() resets the marks on it). Some failures
                // don't set ocm_is_stopped, and the page polled forever.
                $isStopped = $isStopped || (!$action_in_flight && strpos($lastLog, 'Error:') !== false);
                if ($isStopped && !$restore_terminal) {
                    $progress_item_text = array(
                        'isStopped' => true,
                        'text' => self::progress_status_text($lastLog),
                        'value' => $progress_item[1],
                        'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
                    );
                }


                $faq_link = '<a target="_blank" href="https://1clickmigration.com/faq/">';
                if ($lastLog === 'Downloading restore files. Please wait.') {
                    $progress_item_text['text'] .= '</br></br>' . esc_html__('This step could take a while depending on your backup size', '1-click-migration');

                } elseif (self::is_logout_window_line($lastLog)) {
                    $progress_item_text['text'] .= '</br></br>' . esc_html__('You may be logged out around now, because the site\'s users are being replaced with the ones from your backup. If so, log back in with the username and password of the site you backed up, then refresh this page.', '1-click-migration');
                } elseif ($restore_terminal) {
                    if (strpos($lastLog, self::LOG_RESTORE_COMPLETED_WARNINGS_PREFIX) === 0) {
                        $progress_item_text['text'] .= '</br></br>' . esc_html__('Restore finished, but some parts were skipped or missing. Check the migration log, then verify themes, plugins, and media on your site.', '1-click-migration')
                            /* translators: 1: opening link tag to the FAQ page, 2: closing link tag. */
                            . ' ' . sprintf(self::ui_html(__('Our %1$sFAQ page%2$s covers common fixes.', '1-click-migration')), $faq_link, '</a>');
                    } else {
                        /* translators: 1: opening link tag to the FAQ page, 2: closing link tag. */
                        $progress_item_text['text'] .= '</br></br>' . sprintf(self::ui_html(__('Please check your website. If you have any issues, see our %1$sFAQ page%2$s.', '1-click-migration')), $faq_link, '</a>');
                    }
                    $progress_item_text['text'] .= '</br></br>' . self::support_forum_html(__('Need help?', '1-click-migration'));
                    $progress_item_text['restoreCompleted'] = true;
                } elseif ($lastLog === 'Backup completed.') {
                    $progress_item_text['topBanner'] = sprintf(
                        /* translators: 1: sender address of the restore code email (bold), 2: the same address (code) */
                        self::ui_html(__('<strong>Check your email</strong> for the restore code from %1$s. If you don\'t see it within a few minutes, check your <strong>spam/junk folder</strong> and mark messages from %2$s as not spam so future restore codes arrive in your inbox.', '1-click-migration')),
                        '<strong>' . self::RESTORE_CODE_SENDER . '</strong>',
                        '<code>' . self::RESTORE_CODE_SENDER . '</code>'
                    ) . self::backup_id_banner_line();
                } elseif (strpos($lastLog, 'Backup completed with missing parts') === 0) {
                    $missing_label = esc_html(trim((string) substr($lastLog, strlen('Backup completed with missing parts:'))));
                    $progress_item_text['topBanner'] = sprintf(
                        /* translators: %s: backup parts that are missing, e.g. "uploads, plugins" */
                        self::ui_html(__('<strong>Backup finished, but it is INCOMPLETE.</strong> These parts could not be backed up and will NOT be restored: <strong>%s</strong>.', '1-click-migration')),
                        $missing_label
                    )
                        . '<br>' . sprintf(
                            /* translators: %s: sender address of the restore code email */
                            self::ui_html(__('A restore code was still emailed from %s in case you want to migrate the rest.', '1-click-migration')),
                            '<strong>' . self::RESTORE_CODE_SENDER . '</strong>'
                        )
                        . '<br>' . sprintf(
                            /* translators: %s: the PHP setting max_execution_time */
                            self::ui_html(__('To get a complete backup, ask your host to raise the PHP %s, then run the backup again.', '1-click-migration')),
                            '<code>max_execution_time</code>'
                        )
                        . self::backup_id_banner_line();
                }
                $recovery_hint = self::get_recovery_hint_for_log($lastLog);
                if ($recovery_hint !== '') {
                    $progress_item_text['text'] .= '</br></br><strong>' . esc_html__('How to fix:', '1-click-migration') . '</strong> ' . self::ui_html($recovery_hint);
                }
                if (self::is_failure_progress_line($lastLog, $progress_item[1], $isStopped)) {
                    $progress_item_text['text'] .= '</br></br>' . self::support_forum_html($recovery_hint !== ''
                        ? __('Still stuck?', '1-click-migration')
                        : __('Need help?', '1-click-migration'));
                    // The admin page keeps showing a failed run's outcome after a reload.
                    $progress_item_text['failed'] = true;
                }
                return $progress_item_text;
            }
        }

        return $default_text;
    }

    /**
     * "Ask on the support forum" line for the progress card. The forum is
     * public, so it asks for the site's domain only: anyone with the restore
     * code can restore the backup. Same sentence as
     * OCM_Admin_Page::support_forum_sentence().
     *
     * @param string $lead Translated question in front of the link, e.g. "Need help?".
     * @return string HTML
     */
    private static function support_forum_html($lead)
    {
        return esc_html($lead) . ' ' . sprintf(
            /* translators: 1: opening link tag to the WordPress.org support forum, 2: closing link tag. */
            self::ui_html(__('%1$sAsk on the support forum%2$s and share your site\'s domain. Never post your restore code: anyone who has it can restore your backup.', '1-click-migration')),
            '<a target="_blank" rel="noopener noreferrer" href="' . self::SUPPORT_FORUM_URL . '">',
            '</a>'
        );
    }

    /**
     * Translated progress-card text, safe to send as HTML: only the inline
     * tags in UI_HTML survive. Apostrophes stay as they are.
     *
     * @param string $text
     * @return string
     */
    private static function ui_html($text)
    {
        return wp_kses($text, self::UI_HTML);
    }

    /** @return string "<br>Backup ID: …" for the backup-completed banner, or ''. */
    private static function backup_id_banner_line()
    {
        $bucket_id = (string) get_option('ocm_bucket_id', '');
        if ($bucket_id === '') {
            return '';
        }
        /* translators: %s: backup ID */
        return '<br>' . sprintf(esc_html__('Backup ID: %s', '1-click-migration'), '<code>' . esc_html($bucket_id) . '</code>');
    }

    /**
     * Does the progress card show a run that failed? The terminal failure
     * lines, and once the run has stopped, any error line or line the progress
     * map puts at 0% other than the user's own Stop & Reset. A run still in
     * flight can get past an error line (the worker is retried), so it gets
     * no forum link until it fails.
     *
     * @param string      $line       Log line the card shows.
     * @param string      $percentage Its progress map value.
     * @param bool|string $is_stopped ocm_is_stopped.
     * @return bool
     */
    private static function is_failure_progress_line($line, $percentage, $is_stopped)
    {
        foreach (array('Backup failed:', 'Restore failed:', 'Restore stopped') as $prefix) {
            if (strpos($line, $prefix) === 0) {
                return true;
            }
        }
        if (!$is_stopped || $line === 'Stop & Reset Finished' || strpos($line, 'Time out approaching') === 0) {
            return false;
        }
        return $percentage === '0%' || strpos($line, 'Error') !== false;
    }

    /**
     * @param string[] $folders Folder names, unescaped.
     * @return string The folders as a comma-separated list of <code> items,
     *                ending with the private folder that holds the restore
     *                workspace when there is one.
     */
    private static function hint_folder_list(array $folders)
    {
        $dir = self::get_private_work_dir();
        if ('' !== $dir) {
            $folders[] = dirname($dir);
        }
        $items = array();
        foreach ($folders as $folder) {
            $items[] = '<code>' . esc_html($folder) . '</code>';
        }
        return implode(', ', $items);
    }

    private static function get_recovery_hint_for_log($log)
    {
        if (!is_string($log) || $log === '') {
            return '';
        }

        if (strpos($log, 'disk quota') !== false || strpos($log, 'Not enough disk space') !== false || strpos($log, 'Restore workspace is not writable') !== false) {
            return __('The hosting account is out of disk space. Delete files you no longer need or ask your host for more space, then try again. If your hosting panel shows plenty of free space, ask your host to check the account\'s disk and file limits.', '1-click-migration');
        }

        if (strpos($log, 'files could not be moved into place') !== false) {
            return sprintf(
                /* translators: %s: comma-separated list of folders */
                __('The database is already restored, so your site stays in maintenance mode on purpose: visitors see a short "be right back" page instead of a half-finished site. Ask your host to let WordPress write to %s, then start the restore again. To take the site out of maintenance mode now, click Stop & Reset.', '1-click-migration'),
                self::hint_folder_list(array('wp-content', 'wp-content/plugins', 'wp-content/themes', 'wp-content/uploads'))
            );
        }

        if (strpos($log, 'write permissions') !== false || strpos($log, 'could not be moved or copied') !== false || strpos($log, 'could not be moved into place') !== false || strpos($log, 'could not be extracted') !== false) {
            return sprintf(
                /* translators: %s: comma-separated list of folders */
                __('WordPress isn\'t allowed to write to some folders (%s). Ask your host to fix the folder permissions, then try again.', '1-click-migration'),
                self::hint_folder_list(array('wp-content', 'wp-content/plugins', 'wp-content/themes', 'wp-content/uploads'))
            );
        }

        if (strpos($log, 'Restore failed while downloading') !== false) {
            return __('The backup could not be downloaded. Backups are kept for 48 hours: if yours is older, make a new backup on the old site and use its new restore code. Otherwise try again, because some hosts and firewalls interrupt large downloads.', '1-click-migration');
        }

        if (strpos($log, 'Restore failed while decrypting') !== false || strpos($log, 'could not be opened for extraction') !== false) {
            return __('The downloaded backup file looks incomplete. Free some disk space and try again. If it fails again, make a new backup on the old site and use its new restore code.', '1-click-migration');
        }

        if (strpos($log, 'cannot be restored safely (not hex-encoded)') !== false
            || strpos($log, 'binary/BLOB column values that cannot be restored safely') !== false
        ) {
            return self::fresh_3_1_19_backup_hint();
        }

        if (strpos($log, 'Restore stopped before import: backup needs a fresh 3.1.19 backup') !== false) {
            return self::fresh_3_1_19_backup_hint();
        }

        if (strpos($log, 'Restore stopped: database part could not be downloaded or unpacked') !== false) {
            return sprintf(
                /* translators: %s: comma-separated list of folders */
                __('The database part of the backup could not be downloaded or unpacked, so nothing on this site was changed. Free some disk space, or ask your host to check write permissions for %s, then try again. If it fails again, make a new backup and use its new restore code.', '1-click-migration'),
                self::hint_folder_list(array('wp-content'))
            );
        }

        if (strpos($log, "Restore stopped: the backup's main options table didn't pass the check") !== false
            || strpos($log, 'incomplete or wrong-prefix') !== false
        ) {
            return __('The backup\'s site settings did not pass our safety check, so nothing on this site was changed. Make a new backup on the old site and restore with its new restore code. If this happens again, ask in the support forum.', '1-click-migration');
        }

        if (strpos($log, 'Restore stopped: the database could not be imported') !== false) {
            return __('The database could not be imported, so nothing on this site was changed. Make a new backup on the old site and restore with its new restore code. If it stops again, download the log and ask in the support forum: the log tells us exactly what to change, and we\'ll walk you through it.', '1-click-migration');
        }

        if (strpos($log, 'Database restore failed while importing') !== false || strpos($log, 'DB Restore') !== false) {
            return __('The database import ran into data it could not handle. Download the log and ask in the support forum, and we\'ll help you finish the move.', '1-click-migration');
        }

        if (strpos($log, 'Backup worker dispatch failed') !== false || strpos($log, 'admin-ajax loopback check failed') !== false || strpos($log, 'blocks loopback requests') !== false) {
            return __('This host blocks the background requests the plugin uses, so keep this page open while it works. To make it run in the background, ask your host to allow WordPress loopback requests to <code>wp-admin/admin-ajax.php</code>.', '1-click-migration');
        }

        if (strpos($log, 'was skipped due to timeout') !== false || strpos($log, 'Backup completed with missing parts') !== false) {
            return __('Some parts were skipped because the server\'s time limit is too short for them. Ask your host to raise the PHP time limit (<code>max_execution_time</code>), then run the backup again so everything is included.', '1-click-migration');
        }

        if (stripos($log, 'could not register backup') !== false || strpos($log, 'endpoint') !== false) {
            return __('The plugin could not reach the 1-Click Migration service. Try again in a few minutes. If it keeps failing, ask your host to allow outgoing HTTPS connections from the server.', '1-click-migration');
        }

        return '';
    }

    /** @return string Hint for a restore that needs a backup from 1-Click Migration 3.1.19 or later. */
    private static function fresh_3_1_19_backup_hint()
    {
        return __('This backup was made with an older version of 1-Click Migration. Update the plugin on the <strong>old</strong> site, make a new backup, then restore with its new restore code. Nothing on this site was changed.', '1-click-migration');
    }

    private static function get_multipart_upload_percentage($log)
    {
        if ($log && preg_match('/Uploaded\s+(db|themes|plugins|uploads)\s+part\s+(\d+)\/(\d+)/i', $log, $matches)) {
            $folder_type = strtolower($matches[1]);
            $part = (int) $matches[2];
            $total = (int) $matches[3];

            $ranges = [
                'db'      => [43, 44],
                'themes'  => [47, 48],
                'plugins' => [52, 53],
                'uploads' => [63, 65],
            ];

            if (isset($ranges[$folder_type]) && $total > 0) {
                $start = $ranges[$folder_type][0];
                $end   = $ranges[$folder_type][1];
                $pct_val = $start + ($part / $total) * ($end - $start);
                $pct = (int) round($pct_val);
                $pct = min($end, max($start, $pct));
                return $pct . '%';
            }
        }
        return false;
    }

    private static function get_percent_by_log_message($log)
    {
        $multipart_pct = self::get_multipart_upload_percentage($log);
        if ($multipart_pct !== false) {
            return $multipart_pct;
        }

        $return = false;

        foreach (self::$progress_data as $item) {


            list($template, $percent) = $item;
            if (strpos($log, $template) !== false) {
                $return = $percent;
                break;
            }
        }

        return $return;
    }

    /** Bytes a folder part packs (same walk as the archive, so link loops end). */
    private static function folderSize($dir)
    {
        $size = 0;
        if (!is_dir($dir)) {
            return $size;
        }
        try {
            foreach (self::folder_archive_files($dir) as $file_path) {
                $size += (int) @filesize($file_path);
            }
        } catch (\UnexpectedValueException $e) {
            // The folder itself can't be opened; packing it reports that.
        }
        return $size;
    }

    public static function convertToReadableSize($size)
    {
        $decimals = 1;
        $sizes = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
        $factor = floor((strlen($size) - 1) / 3);
        return sprintf("%.{$decimals}f", $size / (1024 ** $factor)) . @$sizes[$factor];
    }

    private static function DBSize () {
        global $wpdb;
        $db_size_obj = $wpdb->get_results ('
        SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 1) "db_size"
        FROM information_schema.tables
        WHERE table_schema = "'.DB_NAME.'"
        GROUP BY table_schema
        ');
        foreach ($db_size_obj as $db_size)
            {
            return $db_size->db_size;
            }
    }

    /**
     * Check whether the local disk has enough free space to run a backup.
     * Backup processes parts sequentially and clears the temp dir between
     * uploads, so peak disk overhead is the size of the *largest* part:
     *   - DB: ~2x DB engine size (SQL dumps + zip), matches the in-flow check.
     *   - Each folder: ~1.5x folder size (zip + encrypted copy).
     *
     * Also returns the raw per-part sizes so the caller can publish them (via the
     * log that gets uploaded to S3) for the restore-side disk preflight.
     *
     * @return array{ok:bool, required:int, free:int, peak_label:string, sizes:array<string,int>}
     */
    private static function check_backup_disk_space($excluded_folders)
    {
        $excluded = is_array($excluded_folders) ? $excluded_folders : [];
        $peak_bytes = 0;
        $peak_label = '';
        $sizes = [];

        if (!in_array('db', $excluded, true)) {
            $db_bytes = (int) ((float) self::DBSize() * 1024 * 1024);
            $sizes['db'] = $db_bytes;
            $need = (int) ($db_bytes * 2);
            if ($need > $peak_bytes) { $peak_bytes = $need; $peak_label = 'db'; }
        }
        foreach (['plugins', 'themes', 'uploads'] as $folder) {
            if (in_array($folder, $excluded, true)) continue;
            $path = WP_CONTENT_DIR . '/' . $folder;
            if (!is_dir($path)) continue;
            $folder_bytes = (int) self::folderSize($path);
            $sizes[$folder] = $folder_bytes;
            $need = (int) ($folder_bytes * 1.5);
            if ($need > $peak_bytes) { $peak_bytes = $need; $peak_label = $folder; }
        }

        $free = self::get_free_disk_space(self::get_backup_work_path());

        return [
            'ok'         => $free === 0 ? true : ($free >= $peak_bytes),
            'required'   => $peak_bytes,
            'free'       => $free,
            'peak_label' => $peak_label,
            'sizes'      => $sizes,
        ];
    }

    /**
     * Check disk space for restore. Each part is downloaded (encrypted zip),
     * decrypted (crypt deleted right after), extracted into the workspace (zip
     * deleted right after), and db/plugins content stays staged until the final
     * phases, so peak ≈ sum(unzipped_sizes) + one part's zip in flight.
     *
     * When the backup published its uncompressed part sizes (BACKUP_UNCOMPRESSED_SIZES
     * line in the merged S3 log), use those: sum(uncompressed) + largest zip, +10%.
     * Otherwise fall back to 1.6x sum(zip_sizes) — typical mixed content (DB SQL
     * compresses well, uploads barely compress at all) lands around there. The
     * in-flow download/extract checks catch any overshoot.
     *
     * @return array{ok:bool, required:int, free:int, total:int, reason:string}
     */
    private static function check_restore_disk_space($presigned_urls, $excluded_folders)
    {
        $excluded = is_array($excluded_folders) ? $excluded_folders : [];
        $total_bytes = 0;
        $largest_zip = 0;
        $write_probe_ok = self::probe_restore_workspace_write();

        if (is_array($presigned_urls) || is_object($presigned_urls)) {
            foreach ((array) $presigned_urls as $key => $url) {
                if (in_array($key, $excluded, true)) continue;
                if (in_array($key, array('log', 'log_download'), true)) continue;
                if (!is_string($url) || $url === '') continue;
                $resp = wp_remote_head($url, ['timeout' => 8, 'redirection' => 3]);
                if (is_wp_error($resp)) continue;
                $len = wp_remote_retrieve_header($resp, 'content-length');
                if ($len !== '' && is_numeric($len)) {
                    $total_bytes += (int) $len;
                    $largest_zip = max($largest_zip, (int) $len);
                }
            }
        }

        $manifest = self::get_backup_size_manifest();
        $uncompressed_total = 0;
        foreach ($manifest as $part => $bytes) {
            if (in_array($part, $excluded, true)) continue;
            if (!array_key_exists($part, (array) $presigned_urls)) continue;
            $uncompressed_total += $bytes;
        }

        if ($uncompressed_total > 0) {
            $required = (int) (($uncompressed_total + $largest_zip) * 1.1);
        } else {
            $required = (int) ($total_bytes * 1.6);
        }

        $free = self::get_free_disk_space(self::get_restore_work_path());
        $space_ok = ($free === 0 || $required === 0) ? true : ($free >= $required);

        return [
            'ok'       => $write_probe_ok && $space_ok,
            'required' => $required,
            'free'     => $free,
            'total'    => $total_bytes,
            'reason'   => $write_probe_ok ? ($space_ok ? '' : 'insufficient_space') : 'write_probe_failed',
        ];
    }

    /**
     * Parse the uncompressed part sizes the backup published into its log
     * (merged from S3 before the restore disk check). Matched by bucket_id so a
     * stale line from an earlier migration on this site cannot be picked up.
     *
     * @return array<string,int> part => bytes, empty when unavailable.
     */
    private static function get_backup_size_manifest()
    {
        $bucket_id = (string) get_option('ocm_bucket_id', '');
        if ($bucket_id === '' || !defined('OCM_DEBUG_LOG_FILE') || !file_exists(OCM_DEBUG_LOG_FILE)) {
            return [];
        }

        $contents = (string) @file_get_contents(OCM_DEBUG_LOG_FILE);
        if ($contents === '') {
            return [];
        }

        $pattern = '/BACKUP_UNCOMPRESSED_SIZES bucket=' . preg_quote($bucket_id, '/') . ' ([a-z_]+=\d+(?: [a-z_]+=\d+)*)/';
        if (!preg_match_all($pattern, $contents, $matches) || empty($matches[1])) {
            return [];
        }

        $sizes = [];
        foreach (explode(' ', end($matches[1])) as $pair) {
            list($part, $bytes) = explode('=', $pair, 2);
            $sizes[$part] = (int) $bytes;
        }

        return $sizes;
    }

    private static function get_free_disk_space($preferred_path)
    {
        $paths = array($preferred_path, WP_CONTENT_DIR, ABSPATH, '.');

        foreach ($paths as $path) {
            if (!is_string($path) || '' === $path) {
                continue;
            }

            $candidate = $path;
            if (!is_dir($candidate)) {
                $candidate = dirname($candidate);
            }

            if (!is_dir($candidate)) {
                continue;
            }

            $free = @disk_free_space($candidate);
            if (false !== $free) {
                return (int) $free;
            }
        }

        return 0;
    }

    /**
     * Atomically reserve the single backup slot.
     *
     * add_option() is a unique-key insert, unlike a transient get/set pair, so
     * two admin requests cannot both become the owner.
     *
     * @return string|false
     */
    public static function claim_backup_run()
    {
        $run_id = function_exists('wp_generate_uuid4')
            ? wp_generate_uuid4()
            : wp_generate_password(32, false, false);
        $run_id = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $run_id));
        if ('' === $run_id) {
            return false;
        }

        $run = array(
            'id' => $run_id,
            'bucket_id' => '',
            'started_at' => time(),
        );

        if (!add_option(self::BACKUP_RUN_OPTION, $run, '', false)) {
            return false;
        }

        delete_option(self::BACKUP_CRASH_OPTION);
        delete_option(self::BACKUP_RESUME_OPTION);
        self::$backup_run_id = $run_id;
        return $run_id;
    }

    public static function set_backup_run_context($run_id)
    {
        if (!self::is_current_backup_run($run_id)) {
            return false;
        }

        self::$backup_run_id = $run_id;
        return true;
    }

    public static function is_current_backup_run($run_id)
    {
        $run = get_option(self::BACKUP_RUN_OPTION, array());
        return is_array($run)
            && isset($run['id'])
            && is_string($run_id)
            && hash_equals((string) $run['id'], $run_id);
    }

    /**
     * Free the backup slot held by a run that can no longer finish. Only call
     * it when no backup or restore is active and no backup worker holds its
     * lock: then the reserved run was left by a backup whose worker crashed.
     *
     * @return bool True when a stale run was released.
     */
    private static function release_stale_backup_run()
    {
        $run = get_option(self::BACKUP_RUN_OPTION, array());
        if (!is_array($run) || empty($run['id'])) {
            return false;
        }
        return self::release_backup_run((string) $run['id']);
    }

    private static function backup_context_owns_current_run()
    {
        return '' !== self::$backup_run_id && self::is_current_backup_run(self::$backup_run_id);
    }

    public static function get_stored_backup_run_id()
    {
        if ('' !== self::$backup_run_id) {
            return self::$backup_run_id;
        }

        $run = get_option(self::BACKUP_RUN_OPTION, array());
        return is_array($run) && isset($run['id']) ? (string) $run['id'] : '';
    }

    private static function set_backup_run_bucket($run_id, $bucket_id)
    {
        $run = get_option(self::BACKUP_RUN_OPTION, array());
        if (!self::is_current_backup_run($run_id) || !is_array($run)) {
            return false;
        }

        $run['bucket_id'] = (string) $bucket_id;
        return update_option(self::BACKUP_RUN_OPTION, $run, false);
    }

    public static function release_backup_run($run_id = null)
    {
        if (null === $run_id) {
            $run_id = self::$backup_run_id;
        }
        if (!is_string($run_id) || '' === $run_id) {
            return false;
        }

        $run = get_option(self::BACKUP_RUN_OPTION, array());
        if (!is_array($run) || empty($run['id']) || !hash_equals((string) $run['id'], $run_id)) {
            return false;
        }

        // Compare-and-delete so a delayed releaser cannot drop a newly claimed run.
        $serialized = maybe_serialize($run);
        $released = self::delete_option_if_value(self::BACKUP_RUN_OPTION, $serialized);
        if ($released) {
            delete_option(self::BACKUP_UPLOADED_PARTS_OPTION);
            delete_option(self::BACKUP_CRASH_OPTION);
            delete_option(self::BACKUP_RESUME_OPTION);
            if (hash_equals(self::$backup_run_id, $run_id)) {
                self::$backup_run_id = '';
            }
        }
        return $released;
    }

    /**
     * Start a new restore run. Its id travels with the queue item, so a worker
     * left over from a failed or replaced run can tell it no longer owns the
     * site and stop without logging or touching the restore workspace (#96).
     *
     * @return string
     */
    public static function claim_restore_run()
    {
        $run_id = function_exists('wp_generate_uuid4')
            ? wp_generate_uuid4()
            : wp_generate_password(32, false, false);
        $run_id = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $run_id));
        update_option(self::RESTORE_RUN_OPTION, $run_id, false);
        delete_option(self::RESTORE_CRASH_OPTION);
        delete_option(self::RESTORE_RESUME_OPTION);
        self::set_restore_run_context($run_id);
        return $run_id;
    }

    public static function set_restore_run_context($run_id)
    {
        self::$restore_run_id = is_string($run_id) ? $run_id : '';
        self::$restore_run_ended = false;
        self::$restore_run_checked_at = 0;
    }

    public static function is_current_restore_run($run_id)
    {
        if (!is_string($run_id) || '' === $run_id) {
            return false;
        }
        // Another request ends or replaces the run, so never trust this
        // request's cached copy of the option.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::RESTORE_RUN_OPTION, 'options');
        }
        $current = get_option(self::RESTORE_RUN_OPTION, '');
        return is_string($current) && '' !== $current && hash_equals($current, $run_id);
    }

    /**
     * True when this worker serves a restore run that has since failed,
     * stopped, completed or been replaced by a newer one.
     *
     * @return bool
     */
    public static function restore_run_ended()
    {
        if ('' === self::$restore_run_id) {
            return false;
        }
        if (!self::$restore_run_ended && !self::is_current_restore_run(self::$restore_run_id)) {
            self::$restore_run_ended = true;
        }
        return self::$restore_run_ended;
    }

    /**
     * restore_run_ended() for tight transfer loops: re-reads the option at most
     * every 5 seconds.
     *
     * @return bool
     */
    public static function restore_run_ended_throttled()
    {
        if ('' === self::$restore_run_id || self::$restore_run_ended) {
            return self::$restore_run_ended;
        }
        $now = time();
        if ($now - self::$restore_run_checked_at < 5) {
            return false;
        }
        self::$restore_run_checked_at = $now;
        return self::restore_run_ended();
    }

    /**
     * Terminal bookkeeping for a restore run: drop its queue rows so neither
     * the cron healthcheck nor the watchdog can start it again, and release
     * the run so any worker still busy with it stops at its next check.
     *
     * @return void
     */
    private static function end_restore_run()
    {
        if ('' !== self::$restore_run_id && !self::is_current_restore_run(self::$restore_run_id)) {
            // A newer run owns the slot and its queue.
            return;
        }
        self::clear_restore_batch_option_rows();
        delete_option(self::RESTORE_RUN_OPTION);
        delete_option(self::RESTORE_CRASH_OPTION);
        delete_option(self::RESTORE_RESUME_OPTION);
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete(self::RESTORE_RUN_OPTION, 'options');
        }
        if ('' !== self::$restore_run_id) {
            self::$restore_run_ended = true;
        }
    }

    private static function delete_option_if_value($name, $expected_serialized)
    {
        global $wpdb;

        if (isset($wpdb) && is_object($wpdb) && isset($wpdb->options) && method_exists($wpdb, 'delete')) {
            $deleted = $wpdb->delete(
                $wpdb->options,
                array(
                    'option_name' => $name,
                    'option_value' => $expected_serialized,
                ),
                array('%s', '%s')
            );
            if ($deleted) {
                wp_cache_delete($name, 'options');
                return true;
            }
            return false;
        }

        $current = get_option($name, null);
        if (null === $current || maybe_serialize($current) !== $expected_serialized) {
            return false;
        }

        return delete_option($name);
    }

    public static function get_backup_work_path($run_id = null)
    {
        if (!is_string($run_id) || '' === $run_id) {
            $run_id = self::$backup_run_id;
        }
        if ('' === $run_id) {
            $run = get_option(self::BACKUP_RUN_OPTION, array());
            $run_id = is_array($run) && isset($run['id']) ? (string) $run['id'] : '';
        }
        $run_id = strtolower(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $run_id));
        $name = 'backup-' . ($run_id !== '' ? $run_id : 'unowned') . '/';

        // The SQL dump and zips live in the private work dir, not under the
        // web root. A run that had to fall back to wp-content/tmp (see
        // ensure_temporary_directory) keeps using it.
        $legacy = OCM_PLUGIN_WRITABLE_PATH . $name;
        $private = self::get_private_work_dir();
        if ('' === $private || (!is_dir($private . '/' . $name) && is_dir($legacy))) {
            return $legacy;
        }
        if (!is_dir($private . '/' . $name) && !self::private_work_dir_usable(false)) {
            return $legacy;
        }
        return $private . '/' . $name;
    }

    /**
     * This site's directory for backup and restore work files, beside the
     * private debug log and outside the web root. OCM_DEBUG_LOG_DIR is already
     * per site (OCM_Private_Dir), so sibling installs never share it.
     *
     * @return string Directory without trailing slash; '' when there is no private dir.
     */
    public static function get_private_work_dir()
    {
        if (!defined('OCM_DEBUG_LOG_DIR') || !is_string(OCM_DEBUG_LOG_DIR)) {
            return '';
        }
        $base = rtrim(OCM_DEBUG_LOG_DIR, '/\\');
        if ('' === $base) {
            return '';
        }
        return $base . '/work';
    }

    /**
     * Whether new work dirs may go in get_private_work_dir(). It must not be
     * inside the web root (a WordPress install in a subdirectory of the
     * document root puts the private dir there). The restore also needs it on
     * wp-content's filesystem, so the deferred file swap stays a rename.
     * Writability is checked when the directory is created.
     *
     * @param bool $same_filesystem_as_content
     * @return bool
     */
    private static function private_work_dir_usable($same_filesystem_as_content)
    {
        $dir = self::get_private_work_dir();
        if ('' === $dir || self::is_inside_web_root($dir)) {
            return false;
        }
        if ($same_filesystem_as_content) {
            $dir_device = self::filesystem_device($dir);
            $content_device = self::filesystem_device(WP_CONTENT_DIR);
            if (null !== $dir_device && null !== $content_device && $dir_device !== $content_device) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param string $path
     * @return bool True when $path is ABSPATH, WP_CONTENT_DIR or the document
     *              root, or inside one of them.
     */
    private static function is_inside_web_root($path)
    {
        $roots = array(ABSPATH, WP_CONTENT_DIR);
        if (isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) && '' !== $_SERVER['DOCUMENT_ROOT']) {
            $roots[] = $_SERVER['DOCUMENT_ROOT'];
        }
        $path = self::resolve_path($path) . '/';
        foreach ($roots as $root) {
            if (!is_string($root) || '' === $root) {
                continue;
            }
            if (0 === strpos($path, self::resolve_path($root) . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * realpath() for a path that may not exist yet: resolve its nearest
     * existing ancestor and append the rest.
     *
     * @param string $path
     * @return string Forward slashes, no trailing slash ('' for the filesystem root).
     */
    private static function resolve_path($path)
    {
        $path = rtrim(str_replace('\\', '/', (string) $path), '/');
        $rest = '';
        while ('' !== $path) {
            $real = @realpath($path);
            if (false !== $real) {
                return rtrim(str_replace('\\', '/', $real), '/') . $rest;
            }
            $parent = rtrim(str_replace('\\', '/', dirname($path)), '/');
            if ($parent === $path) {
                break;
            }
            $rest = '/' . basename($path) . $rest;
            $path = $parent;
        }
        return $path . $rest;
    }

    /**
     * @param string $path
     * @return int|null Device id of the nearest existing ancestor; null when unknown.
     */
    private static function filesystem_device($path)
    {
        $path = (string) $path;
        while ('' !== $path) {
            if (file_exists($path)) {
                $stat = @stat($path);
                return is_array($stat) && isset($stat['dev']) ? (int) $stat['dev'] : null;
            }
            $parent = dirname($path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        }
        return null;
    }

    /**
     * Keep web servers that honour these files (Apache, IIS) from serving or
     * listing a work dir that had to go under wp-content. nginx ignores them,
     * which is why such a dir also gets an unguessable name.
     *
     * @param string $dir
     * @return void
     */
    private static function write_deny_files($dir)
    {
        foreach (self::DENY_FILES as $name => $contents) {
            if (!is_file($dir . '/' . $name)) {
                @file_put_contents($dir . '/' . $name, $contents);
            }
        }
    }

    /**
     * @return string 24 lowercase hex characters.
     */
    private static function random_dir_token()
    {
        try {
            return bin2hex(random_bytes(12));
        } catch (\Throwable $e) {
            return strtolower(substr(preg_replace('/[^a-zA-Z0-9]/', '', wp_generate_password(32, false, false)), 0, 24));
        }
    }

    /**
     * Where restore parts are downloaded, decrypted and extracted: the private
     * work dir, or wp-content/ocm_restore-<random> when
     * prepare_restore_workspace() could not use that. Found from what is on
     * disk, so every worker of a run, before and after the database swap,
     * resolves the same directory.
     *
     * @return string Directory without trailing slash. Never '' (callers append "/db/").
     */
    public static function get_restore_work_path()
    {
        $private = self::get_private_work_dir();
        if ('' !== $private && is_dir($private . '/restore')) {
            return $private . '/restore';
        }
        $fallbacks = self::find_fallback_restore_work_paths();
        if ($fallbacks) {
            return $fallbacks[0];
        }
        if ('' !== $private && self::private_work_dir_usable(true)) {
            return $private . '/restore';
        }
        // Nothing prepared yet: a path that doesn't exist.
        return WP_CONTENT_DIR . '/' . self::RESTORE_WORKSPACE_FALLBACK_PREFIX . 'none';
    }

    /**
     * @return string[] Existing wp-content/ocm_restore-<token> dirs, newest first.
     */
    private static function find_fallback_restore_work_paths()
    {
        $entries = @scandir(WP_CONTENT_DIR);
        if (!is_array($entries)) {
            return array();
        }
        $found = array();
        foreach ($entries as $entry) {
            if (!preg_match('/^' . preg_quote(self::RESTORE_WORKSPACE_FALLBACK_PREFIX, '/') . '[a-z0-9]+$/', $entry)) {
                continue;
            }
            $path = WP_CONTENT_DIR . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                $found[$path] = (int) @filemtime($path);
            }
        }
        arsort($found);
        return array_keys($found);
    }

    /**
     * Every restore workspace this site may have on disk: the private one,
     * wp-content fallbacks, and wp-content/ocm_restore from 3.1.20 and earlier.
     *
     * @return string[]
     */
    private static function existing_restore_work_paths()
    {
        $paths = array();
        $private = self::get_private_work_dir();
        if ('' !== $private && is_dir($private . '/restore')) {
            $paths[] = $private . '/restore';
        }
        $paths = array_merge($paths, self::find_fallback_restore_work_paths());
        if (is_dir(WP_CONTENT_DIR . '/' . self::LEGACY_RESTORE_WORKSPACE)) {
            $paths[] = WP_CONTENT_DIR . '/' . self::LEGACY_RESTORE_WORKSPACE;
        }
        return $paths;
    }

    /**
     * 3.1.20 and earlier extracted restores into wp-content/ocm_restore, and a
     * failed run could leave its SQL dump there for nginx to serve. Plugin
     * updates don't run the activation hook, so remove it on the next admin
     * page load while no restore is running.
     *
     * @return void
     */
    public static function remove_legacy_restore_workspace()
    {
        $legacy = WP_CONTENT_DIR . '/' . self::LEGACY_RESTORE_WORKSPACE;
        if (!is_dir($legacy) || get_option('ocm_action_start_restore')) {
            return;
        }
        self::deleteDirectoryBestEffort($legacy);
    }

    /**
     * Delete every restore workspace, stopping the run on the first failure
     * (deleteDir() logs it). For Stop & Reset and a completed restore.
     *
     * @param string $action_log
     * @param mixed  $presigned_urls
     * @return void
     */
    public static function delete_restore_workspaces($action_log, $presigned_urls = null)
    {
        foreach (self::existing_restore_work_paths() as $dir) {
            self::deleteDir($dir, $action_log, $presigned_urls);
        }
    }

    /**
     * Delete this site's backup work dirs in the private work dir (Stop & Reset;
     * fallbacks under wp-content/tmp go with that folder).
     *
     * @param string $action_log
     * @return void
     */
    public static function delete_private_backup_work_dirs($action_log)
    {
        $private = self::get_private_work_dir();
        if ('' === $private || !is_dir($private)) {
            return;
        }
        foreach ((array) @scandir($private) as $entry) {
            if (is_string($entry) && 0 === strpos($entry, 'backup-') && is_dir($private . '/' . $entry)) {
                self::deleteDir($private . '/' . $entry, $action_log, null);
            }
        }
    }

    /**
     * Remove a backup run's work dir once it holds nothing but deny files.
     * Each part deletes its archive after the upload, and the completion
     * cleanup is skipped under WP_DEBUG, so a finished or failed run would
     * otherwise leave an empty backup-<run>/ behind. A dir that still holds
     * work files is left for Stop & Reset. Never touches the parent work dir
     * or another run's dir.
     *
     * @param string|null $run_id Defaults to this request's run.
     * @return bool Whether the dir is gone.
     */
    public static function remove_empty_backup_work_dir($run_id = null)
    {
        $dir = rtrim(self::get_backup_work_path($run_id), '/\\');
        if (!is_dir($dir)) {
            return true;
        }
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return false;
        }
        $deny_files = array();
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            if (!array_key_exists($entry, self::DENY_FILES) || !is_file($dir . '/' . $entry)) {
                return false;
            }
            $deny_files[] = $entry;
        }
        foreach ($deny_files as $name) {
            @unlink($dir . '/' . $name);
        }
        if (@rmdir($dir)) {
            return true;
        }
        // Something was written meanwhile: keep the dir covered.
        if ($deny_files) {
            self::write_deny_files($dir);
        }
        return false;
    }

    /**
     * Legacy shared dump directory used by overlapping 3.1.15 runs.
     * Kept only so tests can prove the isolation contract against that path.
     */
    public static function get_shared_tmp_db_path()
    {
        return OCM_PLUGIN_WRITABLE_PATH . 'db/';
    }

    private static function ensure_temporary_directory()
    {
        $work_path = self::get_backup_work_path();
        if (is_dir($work_path)) {
            if (is_writable($work_path)) {
                return true;
            }

            One_Click_Migration::write_to_log(sprintf(
                'Error: Temporary directory "%s" is not writable.',
                $work_path
            ));
            return false;
        }

        if (@mkdir($work_path, 0700, true) || is_dir($work_path)) {
            return true;
        }

        // The private dir isn't writable on this host: fall back to
        // wp-content/tmp. The run id makes the name unguessable; the deny
        // files cover servers that would list or serve it.
        $legacy = OCM_PLUGIN_WRITABLE_PATH . basename(rtrim($work_path, '/\\')) . '/';
        if ($legacy !== $work_path && (@mkdir($legacy, 0700, true) || is_dir($legacy))) {
            // Only an index.php in the shared tmp folder: other plugins may
            // serve files from it.
            if (!is_file(OCM_PLUGIN_WRITABLE_PATH . 'index.php')) {
                @file_put_contents(OCM_PLUGIN_WRITABLE_PATH . 'index.php', "<?php\nhttp_response_code(404);\n");
            }
            self::write_deny_files(rtrim($legacy, '/\\'));
            return true;
        }

        One_Click_Migration::write_to_log(sprintf(
            'Error: Temporary directory "%s" could not be created. Please check write permissions for "%s".',
            $work_path,
            WP_CONTENT_DIR
        ));
        return false;
    }

    private static function get_remote_file_size($url)
    {
        if (!is_string($url) || $url === '') {
            return 0;
        }

        $response = wp_remote_head($url, array('timeout' => 15, 'redirection' => 3));
        if (is_wp_error($response)) {
            return 0;
        }

        $length = wp_remote_retrieve_header($response, 'content-length');
        return ($length !== '' && is_numeric($length)) ? (int) $length : 0;
    }

    /**
     * The real size of a file whose range request got 416, or 0 if unknown.
     * S3 sends no Content-Range with a 416 and puts the size in its XML
     * error body instead (<ActualObjectSize>). Failing both, a one-byte
     * range GET asks for it: presigned GET URLs refuse HEAD.
     *
     * @param string $url
     * @param string $content_range The 416's Content-Range header, if any.
     * @param string $error_body    The start of the 416's body.
     * @return int
     */
    private static function range_not_satisfiable_size($url, $content_range, $error_body)
    {
        if (preg_match('#^bytes \*/(\d+)$#i', $content_range, $m)) {
            return (int) $m[1];
        }
        if (preg_match('#<ActualObjectSize>(\d+)</ActualObjectSize>#', $error_body, $m)) {
            return (int) $m[1];
        }

        $content_range = '';
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RANGE, '0-0');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, $header) use (&$content_range) {
            if (0 === stripos($header, 'Content-Range:')) {
                $content_range = trim(substr($header, strlen('Content-Range:')));
            }
            return strlen($header);
        });
        curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        unset($ch);

        return ($http_code === 206 && preg_match('#^bytes 0-0/(\d+)$#i', $content_range, $m)) ? (int) $m[1] : 0;
    }

    private static function get_restore_transfer_timeout()
    {
        $timeout = max(20, One_Click_Migration::get_timeout());
        $process = One_Click_Migration::$process_restore_single;
        // Inside a worker, stay within what is left of its budget, however
        // little: a worker that had spent 12 s redeeming the code fell back to
        // the full max_execution_time, so one slow download ran for 20 minutes
        // and, on Windows (wall-clock time limit), PHP killed the worker first.
        // A partial download is kept and the next worker resumes it.
        if (is_object($process) && method_exists($process, 'has_batch_clock') && $process->has_batch_clock()) {
            $timeout = max(10, min($timeout, (int) $process->remaining_time() - 5));
        }

        return $timeout;
    }

    /**
     * The download moved forward, so the next worker continues it rather than
     * retrying it: like a stored multipart upload part, the retry limit counts
     * workers since the last saved bytes, and a slow connection over many short
     * workers never gets the part skipped.
     */
    private static function reset_restore_download_retries($key)
    {
        update_option('ocm_restore_download_retry_' . $key, 0, true);
    }

    private static function download_restore_file($url, $target_path, $key)
    {
        $target_dir = dirname($target_path);
        if (!is_dir($target_dir) && !@mkdir($target_dir, 0700, true) && !is_dir($target_dir)) {
            return new WP_Error('ocm_restore_workspace_missing', sprintf(
                'Restore workspace "%s" could not be created.',
                $target_dir
            ));
        }

        $expected_size = self::get_remote_file_size($url);
        if ($expected_size > 0 && file_exists($target_path) && filesize($target_path) >= $expected_size) {
            return $target_path;
        }
        if (file_exists($target_path)) {
            @unlink($target_path);
        }

        if (!function_exists('curl_init')) {
            $download_tmp_path = download_url($url, One_Click_Migration::get_timeout());
            if (is_wp_error($download_tmp_path)) {
                return $download_tmp_path;
            }
            // copy('') throws a ValueError on PHP 8 instead of returning false.
            if (!is_string($download_tmp_path) || '' === $download_tmp_path || !is_file($download_tmp_path)) {
                return new WP_Error('ocm_download_missing', sprintf(
                    'Download for "%s" returned no file.',
                    $key
                ));
            }
            if (!@rename($download_tmp_path, $target_path)) {
                if (!@copy($download_tmp_path, $target_path)) {
                    return new WP_Error('ocm_download_move_failed', sprintf(
                        'Downloaded file for "%s" could not be moved into the restore workspace.',
                        $key
                    ));
                }
                @unlink($download_tmp_path);
            }
            return $target_path;
        }

        $partial_path = $target_path . '.part';
        $offset = file_exists($partial_path) ? (int) filesize($partial_path) : 0;
        if ($expected_size > 0 && $offset >= $expected_size) {
            if (@rename($partial_path, $target_path)) {
                return $target_path;
            }
            clearstatcache(true, $target_path);
            if (file_exists($target_path) && filesize($target_path) >= $expected_size) {
                return $target_path;
            }
            return new WP_Error('ocm_download_move_failed', sprintf(
                'Downloaded file for "%s" could not be finalized.',
                $key
            ));
        }

        $handle = @fopen($partial_path, 'ab');
        if (!$handle) {
            return new WP_Error('ocm_download_open_failed', sprintf(
                'Could not open partial download file for "%s".',
                $key
            ));
        }

        $before = $offset;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $handle);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::get_restore_transfer_timeout());
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        if ($offset > 0) {
            curl_setopt($ch, CURLOPT_RANGE, $offset . '-');
        }
        // Most servers name the file's real size in a 416's Content-Range.
        $content_range = '';
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, $header) use (&$content_range) {
            if (0 === stripos($header, 'Content-Range:')) {
                $content_range = trim(substr($header, strlen('Content-Range:')));
            }
            return strlen($header);
        });
        // A single curl_exec() can run for minutes; keep the heartbeat and
        // process lock fresh so the watchdog doesn't start a second worker
        // on the same .part file (#79).
        curl_setopt($ch, CURLOPT_NOPROGRESS, false);
        curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, static function () {
            One_Click_Migration::transfer_heartbeat(One_Click_Migration::$process_restore_single);
            // Abort the transfer once its restore run has ended (#96).
            return OCM_Backup::restore_run_ended_throttled() ? 1 : 0;
        });

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // Release the handle (and its hold on the .part file) before fclose();
        // curl_close() is a no-op since 8.0 and deprecated in 8.5.
        unset($ch);
        $error_body = '';
        if ($http_code === 416) {
            // S3 names the real size only in this body (#134); read it before
            // the truncate below drops it.
            fflush($handle);
            $error_body = (string) @file_get_contents($partial_path, false, null, $before, 4096);
        }
        if ($http_code >= 300) {
            // CURLOPT_FILE also wrote the error response's body (S3's XML):
            // keep it out of the saved part, or the next resume continues
            // from a corrupted offset.
            fflush($handle);
            ftruncate($handle, $before);
        }
        fclose($handle);
        clearstatcache(true, $partial_path);

        if ($http_code === 404) {
            return new WP_Error('http_404', 'Not Found');
        }

        // Range starts at or past the end: an earlier worker saved the whole
        // file and stopped before finalizing it (the HEAD size check can't see
        // presigned GET URLs, so $expected_size is often 0).
        if ($http_code === 416 && $offset > 0) {
            if (self::range_not_satisfiable_size($url, $content_range, $error_body) === $offset) {
                if (@rename($partial_path, $target_path)) {
                    return $target_path;
                }
                clearstatcache(true, $target_path);
                if (file_exists($target_path) && filesize($target_path) === $offset) {
                    return $target_path;
                }
                return new WP_Error('ocm_download_move_failed', sprintf(
                    'Downloaded file for "%s" could not be finalized.',
                    $key
                ));
            }
            @unlink($partial_path);
            return new WP_Error('ocm_partial_download', sprintf(
                'Saved part of "%s.zip.crypt" did not match the backup file; restarting cleanly.',
                $key
            ));
        }

        if ($errno === 23) {
            // CURLE_WRITE_ERROR: writing the downloaded bytes to disk failed, which
            // means a full disk or quota — retrying cannot succeed until space is freed.
            return new WP_Error('ocm_disk_full', sprintf(
                'Not enough disk space while downloading "%s.zip.crypt" (%s free). Free space or increase the hosting account quota, then run the restore again.',
                $key,
                self::convertToReadableSize(self::get_free_disk_space(dirname($target_path)))
            ));
        }

        $downloaded = file_exists($partial_path) ? (int) filesize($partial_path) : 0;
        if ($offset > 0 && $http_code === 200) {
            @unlink($partial_path);
            return new WP_Error('ocm_partial_download', sprintf(
                'Server restarted the download for "%s.zip.crypt"; restarting cleanly.',
                $key
            ));
        }
        if ($expected_size > 0 && $downloaded > $expected_size) {
            @unlink($partial_path);
            return new WP_Error('ocm_partial_download', sprintf(
                'Partial download for "%s.zip.crypt" exceeded the expected size; restarting cleanly.',
                $key
            ));
        }
        if (!$ok || $errno !== 0) {
            if ($downloaded > $before) {
                self::reset_restore_download_retries($key);
                return new WP_Error('ocm_partial_download', sprintf(
                    'Partial download saved for "%s.zip.crypt" (%s downloaded%s).',
                    $key,
                    self::convertToReadableSize($downloaded),
                    $expected_size > 0 ? ' of ' . self::convertToReadableSize($expected_size) : ''
                ));
            }
            // A step that got no bytes before its short timeout (or lost the
            // connection) says nothing about the file; failing the whole
            // restore over it stopped one on a slow connection.
            if (in_array($errno, self::TRANSIENT_TRANSFER_ERRNOS, true)) {
                One_Click_Migration::write_to_log(sprintf(
                    ' SYSLOG: Download of "%s.zip.crypt" got no data this step: %s',
                    $key,
                    $error
                ));
                return new WP_Error('ocm_transfer_stalled', sprintf(
                    'No data arrived for "%s.zip.crypt" in this step; trying again.',
                    $key
                ));
            }

            return new WP_Error('http_request_failed', $error ? $error : sprintf('HTTP %d', $http_code));
        }

        if ($http_code < 200 || $http_code >= 300) {
            return new WP_Error('http_request_failed', sprintf('HTTP %d', $http_code));
        }

        if ($expected_size > 0 && $downloaded < $expected_size) {
            if ($downloaded > $before) {
                self::reset_restore_download_retries($key);
            }
            return new WP_Error('ocm_partial_download', sprintf(
                'Partial download saved for "%s.zip.crypt" (%s downloaded of %s).',
                $key,
                self::convertToReadableSize($downloaded),
                self::convertToReadableSize($expected_size)
            ));
        }

        if (!@rename($partial_path, $target_path)) {
            clearstatcache(true, $target_path);
            if ($expected_size > 0 && file_exists($target_path) && filesize($target_path) >= $expected_size) {
                // Already finalized (e.g. by a worker that resumed this download).
                return $target_path;
            }
            return new WP_Error('ocm_download_move_failed', sprintf(
                'Downloaded file for "%s" could not be finalized.',
                $key
            ));
        }

        return $target_path;
    }

    private static function probe_restore_workspace_write()
    {
        if (!is_dir(WP_CONTENT_DIR) || !is_writable(WP_CONTENT_DIR)) {
            return false;
        }

        $probe_file = WP_CONTENT_DIR . '/.ocm-write-probe-' . wp_generate_password(8, false, false) . '.tmp';
        $probe_bytes = str_repeat('0', 1048576);
        $written = @file_put_contents($probe_file, $probe_bytes, LOCK_EX);

        if (file_exists($probe_file)) {
            @unlink($probe_file);
        }

        return $written === strlen($probe_bytes);
    }

    private static function save_system_data_to_log_file($username, $bucket_id)
    {
        global $wp_version;

        One_Click_Migration::write_to_log("Email address: $username");
        One_Click_Migration::write_to_log('Site URL: ' . get_site_url());
        One_Click_Migration::write_to_log("WP Version: $wp_version");
        One_Click_Migration::write_to_log('PHP Version: ' . PHP_VERSION);
        One_Click_Migration::write_to_log('OCM Plugin Version: ' . One_Click_Migration::get_version());
        One_Click_Migration::write_to_log('default max_execution_time: ' . One_Click_Migration::get_default_max_execution_time());
        One_Click_Migration::write_to_log('Available Space: ' . self::convertToReadableSize(self::get_free_disk_space(".")));
        One_Click_Migration::write_to_log('max_execution_time: ' . One_Click_Migration::get_current_max_execution_time());
        One_Click_Migration::write_to_log('timeout set: ' . One_Click_Migration::get_timeout());
        One_Click_Migration::write_to_log(sprintf('proc_open / proc_close: %s', OCM_SQL_Utils::check_proc_available(true) ? 'enabled' : 'disabled'));

        One_Click_Migration::write_to_log("Backup ID: $bucket_id");

        $host = gethostname();
        $ip = gethostbyname($host);

        One_Click_Migration::write_to_log("Current IP Address: $ip");
        
        // Log initial memory status
        $memory_limit = ini_get('memory_limit');
        $memory_usage = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        One_Click_Migration::write_to_log(" SYSLOG: Initial Memory - Limit: " . self::format_bytes(self::convert_to_bytes($memory_limit)) . 
            ", Usage: " . self::format_bytes($memory_usage) . 
            ", Peak: " . self::format_bytes($memory_peak));
        
        self::set_server_settings();

    }

    public static function send_restore_code_email($missing_parts = array())
    {
        $email        = get_option('ocm_user_email');
        $bucket_id    = get_option('ocm_bucket_id');
        $restore_code = get_option('ocm_restore_code_plain');

        if (!$email || !$bucket_id || !$restore_code) {
            One_Click_Migration::write_to_log('Error: Restore-code email could not be sent because backup registration data is missing');
            return false;
        }

        // The Lambda marks the backup ready: from then on a restore precheck can
        // read the URL inventory and BACKUP_PARTS lines from the S3 log.
        One_Click_Migration::flush_log_upload();

        // OCM_S3 retries no answer, a 5xx or a 429 itself. It never repeats
        // a request that got a 200, which could email the code twice.
        if (OCM_S3::send_restore_code_email($email, $bucket_id, $restore_code, get_site_url(), $missing_parts)) {
            delete_option('ocm_restore_code_plain');
            return true;
        }

        One_Click_Migration::write_to_log('Error: Backup finished but the restore-code email could not be sent. Please run the backup again.');
        return false;
    }

    private static function set_server_settings(){
      if (!defined('MB_IN_BYTES')) { define('MB_IN_BYTES', 1024 * KB_IN_BYTES); }
      if (!defined('OCM_PHP_MAX_MEMORY')) { define('OCM_PHP_MAX_MEMORY', 4096 * MB_IN_BYTES); }
      if (!function_exists('wp_is_ini_value_changeable')) {
          /**
          * Determines whether a PHP ini value is changeable at runtime.
          *
          * @staticvar array $ini_all
          *
          * @link https://secure.php.net/manual/en/function.ini-get-all.php
          *
          * @param string $setting The name of the ini setting to check.
          * @return bool True if the value is changeable at runtime. False otherwise.
          */
          function wp_is_ini_value_changeable( $setting ) {
              static $ini_all;
              if ( ! isset( $ini_all ) ) {
                  $ini_all = false;
                  // Sometimes `ini_get_all()` is disabled via the `disable_functions` option for "security purposes".
                  if ( function_exists( 'ini_get_all' ) ) {
                      $ini_all = ini_get_all();
                  }
              }

              // Bit operator to workaround https://bugs.php.net/bug.php?id=44936 which changes access level to 63 in PHP 5.2.6 - 5.2.17.
              if ( isset( $ini_all[ $setting ]['access'] ) && ( INI_ALL === ( $ini_all[ $setting ]['access'] & 7 ) || INI_USER === ( $ini_all[ $setting ]['access'] & 7 ) ) ) {
                  return true;
              }

              // If we were unable to retrieve the details, fail gracefully to assume it's changeable.
              if ( ! is_array( $ini_all ) ) {
                  return true;
              }

              return false;
          }
      }


      if (wp_is_ini_value_changeable('memory_limit'))
          @ini_set('memory_limit', OCM_PHP_MAX_MEMORY);
      if (wp_is_ini_value_changeable('pcre.backtrack_limit'))
          @ini_set('pcre.backtrack_limit', PHP_INT_MAX);
      if (wp_is_ini_value_changeable('default_socket_timeout'))
          @ini_set('default_socket_timeout', 3600);
    }

    private static function get_excluded_folders()
    {
        $excluded_folders = '';

        if(isset($_GET['selected'])){
          $excluded = sanitize_text_field($_GET['selected']);;
          $excluded_folders = explode(",", $excluded);
          update_option( 'ocm_excluded_folders', $excluded_folders);
        }
        else $excluded_folders = [];

        return $excluded_folders;
    }

    private static function get_username()
    {
        $username = '';
        if(isset($_GET['username'])){
          $username = sanitize_email($_GET['username']);

          return $username;

        }else{
          // Log the error instead of redirecting immediately
          One_Click_Migration::write_to_log('Error: Username parameter is missing');
          wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=missing_username'));
          exit;
        }

    }

    private static function get_restore_code()
    {
        if (!isset($_GET['restore_code']) || $_GET['restore_code'] === '') {
            One_Click_Migration::write_to_log('Error: Restore code parameter is missing');
            wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=missing_restore_code'));
            exit;
        }
        // Strip dashes/spaces, uppercase, keep only Crockford-safe chars (the Lambda re-normalizes anyway).
        $raw = preg_replace('/[\s\-]+/', '', (string) $_GET['restore_code']);
        $raw = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw));

        if (strlen($raw) < 6 || strlen($raw) > 32) {
            One_Click_Migration::write_to_log('Error: Restore code length out of range');
            wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=invalid_restore_code'));
            exit;
        }
        return $raw;
    }

    private static function get_restore_steps_template()
    {
        $child_steps = array(
            self::STEP_RESTORE_CHILD_INITIATE => false,
            self::STEP_RESTORE_CHILD_DOWNLOAD => false,
            self::STEP_RESTORE_CHILD_DOWNLOAD_TMP_PATH => null,
            self::STEP_RESTORE_CHILD_PRESIGNED_URLS => null,
            self::STEP_RESTORE_CHILD_DECRYPT => false,
            self::STEP_RESTORE_CHILD_EXTRACT => false,
            self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW => false,
            self::STEP_RESTORE_CHILD_THEMES_UPLOADS_RESTORED => false,
            self::STEP_RESTORE_CHILD_RESTORE => false,
            self::STEP_RESTORE_CHILD_THEMES_RESTORED => false,
            self::STEP_RESTORE_CHILD_UPLOADS_RESTORED => false,
            self::STEP_RESTORE_CHILD_DB_IMPORT => null,
            self::STEP_RESTORE_CHILD_DB_IMPORTED => false,
            self::STEP_RESTORE_CHILD_DB_URLS => false,
            self::STEP_RESTORE_CHILD_DB_PROMOTED => false,
        );

        return array(
            'init' => $child_steps,
            'db' => $child_steps,
            'uploads' => $child_steps,
            'themes' => $child_steps,
            'plugins' => $child_steps,
            'themesUploads' => $child_steps
        );
    }

    public static function get_current_restore_steps()
    {
        $restore_steps = get_option('restore_steps', null);
        if (null === $restore_steps) {
            $restore_steps = self::get_restore_steps_template();
        }

        return $restore_steps;
    }

    public static function reset_current_restore_steps()
    {
        $restore_steps = self::get_restore_steps_template();
        update_option('restore_steps', $restore_steps, true);
    }

    public static function check_is_complete_restore_step($step, $child)
    {
        $restore_steps = self::get_current_restore_steps();
        $step_key = strtolower($step);
        return isset($restore_steps[$step_key])
            && is_array($restore_steps[$step_key])
            && isset($restore_steps[$step_key][$child])
            && $restore_steps[$step_key][$child] === true;
    }

    private static function get_restore_step_value($step, $child)
    {
        $restore_steps = self::get_current_restore_steps();
        $step_key = strtolower($step);
        return (isset($restore_steps[$step_key]) && is_array($restore_steps[$step_key]) && array_key_exists($child, $restore_steps[$step_key]))
            ? $restore_steps[$step_key][$child]
            : null;
    }

    private static function set_complete_restore_step($step, $child, $value = null)
    {
        $restore_steps = self::get_current_restore_steps();
        $step_key = strtolower($step);
        if (!isset($restore_steps[$step_key]) || !is_array($restore_steps[$step_key])) {
            $template = self::get_restore_steps_template();
            $restore_steps[$step_key] = isset($template[$step_key]) ? $template[$step_key] : array();
        }
        $restore_steps[$step_key][$child] = (null !== $value ? $value : true);

        update_option('restore_steps', $restore_steps, true);
    }

    private static function get_backup_steps_template()
    {
        $child_steps = array(
            self::STEP_BACKUP_CHILD_URL_GENERATED => false,
            self::STEP_BACKUP_CHILD_INITIATE => false,
            self::STEP_BACKUP_CHILD_INITIATE_DB_BACKUP => false,
            self::STEP_BACKUP_CHILD_INITIATE_BACKUP_THEMES => false,
            self::STEP_BACKUP_CHILD_INITIATE_BACKUP_PLUGINS => false,
            self::STEP_BACKUP_CHILD_INITIATE_BACKUP_UPLOADS => false,

            self::STEP_BACKUP_CHILD_COMPRESS => false,
            self::STEP_BACKUP_CHILD_ENCRYPT => false,
            self::STEP_BACKUP_CHILD_UPLOAD => false,
            self::STEP_BACKUP_CHILD_ZIP_FILE_PATH => null,
        );

        return array(
            'init' => $child_steps,
            'url' => $child_steps,
            'db' => $child_steps,
            'themes' => $child_steps,
            'plugins' => $child_steps,
            'uploads' => $child_steps,
        );
    }

    public static function get_current_backup_steps()
    {
        $backup_steps = get_option('backup_steps', null);
        if (null === $backup_steps) {
            $backup_steps = self::get_backup_steps_template();
        }

        return $backup_steps;
    }

    public static function reset_current_backup_steps()
    {
        $backup_steps = self::get_backup_steps_template();
        update_option('backup_steps', $backup_steps, true);
    }

    private static function check_is_complete_backup_step($step, $child)
    {
        $backup_steps = self::get_current_backup_steps();
        $step_key = strtolower($step);
        return isset($backup_steps[$step_key])
            && is_array($backup_steps[$step_key])
            && isset($backup_steps[$step_key][$child])
            && $backup_steps[$step_key][$child] === true;
    }

    private static function get_backup_step_value($step, $child)
    {
        $backup_steps = self::get_current_backup_steps();
        $step_key = strtolower($step);
        return (isset($backup_steps[$step_key]) && is_array($backup_steps[$step_key]) && array_key_exists($child, $backup_steps[$step_key]))
            ? $backup_steps[$step_key][$child]
            : null;
    }

    public static function set_complete_backup_step($step, $child, $value = null)
    {
        if (!self::backup_context_owns_current_run()) {
            return false;
        }

        $backup_steps = self::get_current_backup_steps();
        $step_key = strtolower($step);
        if (!isset($backup_steps[$step_key]) || !is_array($backup_steps[$step_key])) {
            $template = self::get_backup_steps_template();
            $backup_steps[$step_key] = isset($template[$step_key]) ? $template[$step_key] : array();
        }
        $backup_steps[$step_key][$child] = (null !== $value ? $value : true);

        update_option('backup_steps', $backup_steps, true);
    }

    public static function unset_empty_log_line($lines){
      foreach ($lines as $key => $line) {
        if(empty($line)){
          unset($lines[$key]);
        }

        $line_arr = explode("-", $line);
        $date_string = $line_arr[0];

        $is_date = OCM_Backup::validate_date($date_string);
        if(!$is_date){
          unset($lines[$key]);

        }

      }

      return $lines;
    }

    public static function validate_date($date_string){
      $timestamp = strtotime($date_string);

      return $timestamp ? true : false;
    }

    /**
     * Check if a SYSLOG message should be allowed for progress tracking
     * @param string $line The log line to check
     * @return bool True if the SYSLOG message should be shown in progress
     */
    private static function is_syslog_allowed_for_progress($line) {
        // Always allow these specific SYSLOG messages
        $allowed_syslog_patterns = [
            // Only allow these essential SYSLOG messages to prevent progress bar jumping
            'SYSLOG: Table being backed up',
            'SYSLOG: "[PHP ERR][FATAL]',
            'SYSLOG: Number of updates made from replace',
            'SYSLOG: Finished updating URLs in the database',
            'SYSLOG: No table structure for table',
            // Removed: 'SYSLOG: Inserting table data into table', - too frequent, causes jumping
            // Removed: 'SYSLOG: End of data contents of table', - too frequent, causes jumping  
            // Removed: 'SYSLOG: There is no mysqldump support', - appears for every table, causes jumping
            'SYSLOG: Column statistics are supported',
            'SYSLOG: Table retrieved',
            'SYSLOG: Column statistics are not supported',
            // Allow only critical memory issues, not routine operations
            'SYSLOG: Low memory available',
            'SYSLOG: Low memory - Page Size reduced',
            'SYSLOG: DB memory preflight',
            'SYSLOG: Using row increment of',
            'Calculated increment',
            'SYSLOG: Error calculating dynamic increment',
            'SYSLOG: Using direct processing',
            'SYSLOG: Dispatching backup worker',
            'SYSLOG: Server IP:',
            'SYSLOG: WP_HTTP_BLOCK_EXTERNAL:',
            'SYSLOG: WP_ACCESSIBLE_HOSTS:',
            'SYSLOG: Admin URL:'
        ];

        foreach ($allowed_syslog_patterns as $pattern) {
            if (strpos($line, $pattern) !== false) {
                return true;
            }
        }

        // Block all other SYSLOG messages from affecting progress bar
        return false;
    }

    /**
     * The message of a log line: what follows the "<timestamp> - " prefix that
     * write_to_log() adds. Only the first separator counts; splitting on the
     * last one cut messages such as "SYSLOG: Queue is empty - nothing to
     * process" down to a tail without "SYSLOG", which then showed as the
     * current step and sent the progress bar back to 1%.
     *
     * @param string $line
     * @return string
     */
    public static function log_line_message($line)
    {
        $line = (string) $line;
        $sep = strpos($line, ' - ');
        return $sep === false ? $line : (string) substr($line, $sep + 3);
    }

    public static function get_last_log_message($skip = true)
    {
        $file = OCM_DEBUG_LOG_FILE;
        $contents = null;
        if (file_exists($file)) {
            $contents = file_get_contents($file);
        } else {
            return false;
        }

        $lastLine = '';
        $lines = array_reverse(explode(PHP_EOL, $contents));
        $lines = OCM_Backup::unset_empty_log_line($lines);

        foreach ($lines as $key => $line) {


            $line = self::log_line_message($line);

            if (empty(trim($line))) {
                continue;
            }

            if ($skip && in_array($line, self::$skipMessages, true)) {
                continue;
            }

            // Skip SYSLOG messages unless they're allowed for progress tracking
            if (strpos($line,'SYSLOG') !== false) {
                if (!self::is_syslog_allowed_for_progress($line)) {
                    continue;
                }
            }

            $lastLine = $line;
            break;
        }

        return $lastLine;
    }

    /**
     * Lines a worker writes when it hands over to the next one (routine since
     * the worker time budget, see One_Click_Migration::get_worker_time_budget()).
     *
     * @param string $line
     * @return bool
     */
    private static function is_handover_line($line)
    {
        return in_array(trim((string) $line), array(
            'Process is Restarting',
            self::LOG_MESSAGE_BG_PROCESS_RESTARTING,
            self::LOG_MESSAGE_BG_PROCESS_RESTARTING_LOG,
        ), true);
    }

    /**
     * The SYSLOG copy Error_Handler logs for a PHP fatal, in whichever request
     * hit it. The raw message follows it on its own line.
     *
     * @param string $line
     * @return bool
     */
    private static function is_php_fatal_syslog_line($line)
    {
        return strpos(trim((string) $line), 'SYSLOG: "[PHP ERR][FATAL]') === 0;
    }

    /**
     * Lines that end a run (or report its failure): a look back for the
     * current step must never cross one of them.
     *
     * @param string $line
     * @return bool
     */
    private static function is_terminal_progress_line($line)
    {
        return self::is_run_end_line($line) || strpos($line, 'Error:') !== false;
    }

    /**
     * Restore steps from the URL update (just before the database swap that
     * replaces the site's users and logs the admin out) until the files are in
     * place.
     *
     * @param string $line
     * @return bool
     */
    private static function is_logout_window_line($line)
    {
        foreach (array('Starting to update URLs in database', 'SYSLOG: Number of updates made from replace',
            'SYSLOG: Finished updating URLs in the database', 'Database has been restored', 'Themes have been restored',
            'Uploads have been restored', 'Plugins have been restored') as $needle) {
            if (false !== strpos($line, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The raw message Error_Handler logs for a PHP fatal (not its SYSLOG copy).
     *
     * @param string $line
     * @return bool
     */
    private static function is_raw_php_fatal_line($line)
    {
        return (bool) preg_match('/^(?:PHP Fatal error|Uncaught (?:[A-Za-z]*Error|[A-Za-z]*Exception)\b)/', $line)
            || 0 === strpos($line, 'Maximum execution time of ')
            || 0 === strpos($line, 'Allowed memory size of ');
    }

    /**
     * The notice a crashed backup or restore writes when it resumes on its own.
     *
     * @param string $line
     * @return bool
     */
    private static function is_resume_notice($line)
    {
        return 0 === strpos($line, self::LOG_RESTORE_RESUMING) || 0 === strpos($line, self::LOG_BACKUP_RESUMING);
    }

    /**
     * A line that ends a backup or restore run.
     *
     * @param string $line
     * @return bool
     */
    private static function is_run_end_line($line)
    {
        foreach (array('Backup completed', 'Backup failed:', 'Restore completed', 'Restore failed:', 'Restore stopped', 'Stop & Reset Finished') as $prefix) {
            if (strpos($line, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * The newest log line that names a progress step, for when the newest line
     * doesn't. Skips hand-overs, hidden SYSLOG lines, fatals' SYSLOG copies and
     * lines the map doesn't know, and stops at a terminal line: in a restore that can be the merged
     * backup log's "Backup completed.", which must not count as this run's step.
     *
     * @return string|null
     */
    private static function get_last_progress_step($skip_error_lines = false)
    {
        $file = OCM_DEBUG_LOG_FILE;
        if (!file_exists($file)) {
            return null;
        }
        // Lines before an automatic-resume notice belong to the crash it
        // recovered from: skip its error lines and find the step it was on.
        // $skip_error_lines does the same from the newest line on.
        $crash_block = (bool) $skip_error_lines;
        foreach (array_reverse(explode(PHP_EOL, (string) file_get_contents($file))) as $raw) {
            $line = trim(self::log_line_message($raw));
            if ($line === '' || self::is_handover_line($line) || self::is_php_fatal_syslog_line($line)) {
                continue;
            }
            if (self::is_resume_notice($line)) {
                $crash_block = true;
                continue;
            }
            if (strpos($line, 'SYSLOG') !== false && !self::is_syslog_allowed_for_progress($line)) {
                continue;
            }
            if ($crash_block && !self::is_run_end_line($line)
                && (strpos($line, 'Error:') !== false || '0%' === self::get_percent_by_log_message($line))
            ) {
                continue;
            }
            if (self::is_terminal_progress_line($line)) {
                return null;
            }
            if (false !== self::get_percent_by_log_message($line)) {
                return $line;
            }
        }
        return null;
    }

    /**
     * What the progress card shows for a log line. SYSLOG lines are internal,
     * so they become a plain label; routine steps drop the server path they
     * log, which names the site's private folder. Error lines keep their
     * paths: the admin needs them to fix permissions or quota.
     *
     * @param string $line
     * @return string
     */
    private static function progress_status_text($line)
    {
        $line = trim((string) $line);
        if (strpos($line, 'SYSLOG:') === 0) {
            $labels = array(
                'Dispatching backup worker' => __('Starting the backup', '1-click-migration'),
                'Using direct processing' => __('Starting the backup', '1-click-migration'),
                'Server IP:' => __('Checking the server', '1-click-migration'),
                'WP_HTTP_BLOCK_EXTERNAL:' => __('Checking the server', '1-click-migration'),
                'WP_ACCESSIBLE_HOSTS:' => __('Checking the server', '1-click-migration'),
                'Admin URL:' => __('Checking the server', '1-click-migration'),
                'Number of updates made from replace' => __('Updating site addresses', '1-click-migration'),
                'Finished updating URLs in the database' => __('Updating site addresses', '1-click-migration'),
                'DB memory preflight (import)' => __('Importing the database', '1-click-migration'),
                'Low memory' => __('Working with limited server memory', '1-click-migration'),
            );
            foreach ($labels as $needle => $label) {
                if (strpos($line, $needle) !== false) {
                    return esc_html($label);
                }
            }
            return esc_html__('Exporting the database', '1-click-migration');
        }
        if (preg_match('/^Archiving (\S+) to /', $line, $m)) {
            /* translators: %s: backup part being archived: db, themes, plugins or uploads */
            return esc_html(sprintf(__('Archiving %s', '1-click-migration'), $m[1]));
        }
        if (preg_match('/^(Restoring "[^"]+") to directory "/', $line, $m)) {
            return $m[1];
        }
        $plain = self::plain_language_status($line);
        return null !== $plain ? $plain : $line;
    }

    /**
     * Plain-language wording for log lines that would otherwise show
     * server jargon on the progress card. Display only: the log keeps the
     * original line, which the monitor, the restore side and the progress
     * logic parse.
     *
     * @param string $line
     * @return string|null HTML-safe text, or null to show the line as it is.
     */
    private static function plain_language_status($line)
    {
        if ((self::is_raw_php_fatal_line($line) && 0 !== strpos($line, 'Maximum execution time of ') && 0 !== strpos($line, 'Allowed memory size of '))
            || 0 === strpos($line, 'Restore worker crashed on a PHP fatal')
        ) {
            return esc_html__('The server stopped this step with an unexpected error. The details are in the log.', '1-click-migration');
        }
        if (0 === strpos($line, 'Maximum execution time of ')) {
            return esc_html__('This step reached the server\'s time limit. The plugin picks it up again on its own.', '1-click-migration');
        }
        if (0 === strpos($line, 'Allowed memory size of ')) {
            return esc_html__('The server ran short of memory on this step. The plugin picks it up again on its own.', '1-click-migration');
        }
        if (preg_match('/^(?:Compressing|Uploading|Encrypting|Downloading|Decrypting|Extracting) (\S+) Retry (\d+)$/', $line, $m)) {
            return esc_html(sprintf(
                /* translators: 1: part of the site (the database, themes, plugins or media uploads), 2: attempt number */
                __('Trying %1$s again (attempt %2$d)', '1-click-migration'),
                self::part_label($m[1]),
                (int) $m[2] + 1
            ));
        }
        if (preg_match('/^Retrying backup registration \(attempt (\d+)\/(\d+)\)/', $line, $m)) {
            return esc_html(sprintf(
                /* translators: 1: attempt number, 2: number of attempts */
                __('Connecting to the 1-Click Migration service (attempt %1$d of %2$d)', '1-click-migration'),
                (int) $m[1] + 1,
                (int) $m[2] + 1
            ));
        }
        foreach (array('PHP Version:', 'WP Version:', 'default max_execution_time:', 'max_execution_time:', 'timeout set:',
            'proc_open / proc_close:', 'Current IP Address:', 'Available Space:', 'Site URL:', 'Email address:',
            'OCM Plugin Version:', 'Other active plugins:', 'Backup ID:') as $prefix) {
            if (0 === strpos($line, $prefix)) {
                return esc_html__('Checking the server', '1-click-migration');
            }
        }
        if (0 === strpos($line, 'Excluding "') || 0 === strpos($line, 'URL generated:') || preg_match('/^\w+ added to queue$/', $line)) {
            return esc_html__('Preparing the backup', '1-click-migration');
        }
        if (0 === strpos($line, 'Notice: Database restore in progress.')) {
            return esc_html__('Notice: Importing the database. This can take a while.', '1-click-migration');
        }
        if (0 === strpos($line, 'Notice: This host blocks loopback requests') || 0 === strpos($line, 'Notice: Backup worker dispatch failed')) {
            return esc_html__('Notice: This host limits background work, so keep this page open until the backup finishes.', '1-click-migration');
        }
        if (0 === strpos($line, 'Notice: Background dispatch appears blocked')) {
            return esc_html__('Notice: Background work is slow on this host. It keeps going, and keeping this page open helps it finish sooner.', '1-click-migration');
        }
        if (0 === strpos($line, 'Notice: Database is larger than PHP memory_limit')) {
            return esc_html__('Notice: The database is large for this server\'s memory, so it is handled in smaller pieces. This takes longer but works.', '1-click-migration');
        }
        if (preg_match('/^Notice: File (\S+?)(?:\.zip(?:\.crypt)?)? was skipped due to timeout/', $line, $m)) {
            return esc_html(sprintf(
                /* translators: %s: part of the site (the database, themes, plugins or media uploads) */
                __('Notice: %s took longer than the server allows and was skipped.', '1-click-migration'),
                self::part_label($m[1])
            ));
        }
        // Not "status=429 error=malformed_response": a busy service must not
        // read as a lost backup. "Error: " stays English so admin-script.js
        // still shows it as an error.
        if (preg_match('/Error: Restore code lookup failed \(status=(\d+) error=(\S*)/', $line, $m)) {
            return 'Error: ' . esc_html(OCM_Restore_Precheck::lookup_unavailable((int) $m[1], $m[2])
                ? OCM_Restore_Precheck::service_busy_message()
                : OCM_Restore_Precheck::code_not_found_message());
        }
        if (0 === strpos($line, 'Error: Restore could not start because a previous restore worker still holds its lock')) {
            return esc_html__('A restore is already running on this site. Wait for it to finish, or click Stop & Reset to cancel it, then start again.', '1-click-migration');
        }
        if (0 === strpos($line, 'Restore failed: Restore failed ')) {
            return 'Restore failed ' . substr($line, strlen('Restore failed: Restore failed '));
        }
        return null;
    }

    /**
     * A backup part for people: "the database", "themes", "plugins", "media uploads".
     *
     * @param string $part db, themes, plugins or uploads (any other value is returned as is).
     * @return string
     */
    private static function part_label($part)
    {
        switch ($part) {
            case 'db':
                return __('the database', '1-click-migration');
            case 'themes':
                return __('themes', '1-click-migration');
            case 'plugins':
                return __('plugins', '1-click-migration');
            case 'uploads':
                return __('media uploads', '1-click-migration');
        }
        return (string) $part;
    }

    public static function get_previous_log_percentage(){
      $file = OCM_DEBUG_LOG_FILE;
      $contents = null;
      if (file_exists($file)) {
          $contents = file_get_contents($file);
      } else {
          return false;
      }

      $lastLine = '';
      $lines = array_reverse(explode(PHP_EOL, $contents));

      foreach ($lines as $key => $line) {

          $line = self::log_line_message($line);
          if (empty(trim($line))) {
              continue;
          }

          if ($line === self::LOG_MESSAGE_BG_PROCESS_RESTARTING) {
            $line = $lines[$key+2];
          }

          if ($line === self::LOG_MESSAGE_BG_PROCESS_RESTARTING_LOG) {
            $line = $lines[$key+3];
          }

          if($line === "Process is Restarting"){

            $line = $lines[$key+1];


          }
          $lastLine = $line;
          break;

      }
      $percentage = self::get_percent_by_log_message($lastLine);

      return $percentage;

    }

    /**
     * Put db ahead of themes/plugins/uploads so extract+preflight can gate file replace.
     *
     * @param array|object $presigned_urls
     * @return array<string, mixed>
     */
    private static function presigned_urls_db_first($presigned_urls)
    {
        $as_array = array();
        foreach ($presigned_urls as $key => $url) {
            $as_array[(string) $key] = $url;
        }
        if (!array_key_exists('db', $as_array)) {
            return $as_array;
        }
        $ordered = array('db' => $as_array['db']);
        foreach ($as_array as $key => $url) {
            if ($key === 'db') {
                continue;
            }
            $ordered[$key] = $url;
        }
        return $ordered;
    }

    /**
     * Mid-restore skip of a part after max retries. User-excluded parts never
     * call this. A skipped database part sets OPTION_RESTORE_DB_PART_FAILED and
     * stops before any site files are replaced / plugins deactivated.
     *
     * @param array  $excluded_restore_files
     * @param array  $skipped_restore_files
     * @param string $key
     */
    private static function capture_skipped_restore_part($excluded_restore_files, $skipped_restore_files, $key)
    {
        self::capture_failed_folder($excluded_restore_files, $key, 'ocm_excluded_folders');
        self::capture_failed_folder($skipped_restore_files, $key, 'ocm_skipped_folders');
        if ($key !== 'db') {
            $warnings = get_option(self::OPTION_RESTORE_PART_WARNINGS, array());
            if (!is_array($warnings) || !isset($warnings[$key])) {
                self::record_restore_part_warning($key, 'timeout');
            }
        }
        if ($key === 'db') {
            update_option(self::OPTION_RESTORE_DB_PART_FAILED, 1, true);
            self::stop_restore_after_db_part_failure();
        }
    }

    /**
     * Stop when the database was meant to be included but is unavailable.
     * Does not stop for db excluded for THIS restore (selective UI exclude or
     * backup-log db=excluded files-only — those live on ocm_excluded_folders).
     * Backup-side db=skipped (ocm_backup_missing_parts) still stops.
     *
     * @param array $user_excluded
     * @return void
     */
    public static function stop_if_db_unavailable_for_restore($user_excluded = null)
    {
        if ($user_excluded === null) {
            $user_excluded = get_option('ocm_excluded_folders', array());
        }
        $user_excluded = is_array($user_excluded) ? $user_excluded : array();
        if (in_array('db', $user_excluded, true)) {
            return;
        }
        if (get_option(self::OPTION_RESTORE_DB_PART_FAILED)) {
            self::stop_restore_after_db_part_failure();
        }
        $missing = get_option('ocm_backup_missing_parts', array());
        $missing = is_array($missing) ? $missing : array();
        // Only backup-side skipped db (meant to be included, failed at backup).
        // db=excluded is intentional files-only and must not stop here.
        if (in_array('db', $missing, true)) {
            update_option(self::OPTION_RESTORE_DB_PART_FAILED, 1, true);
            self::stop_restore_after_db_part_failure();
        }
    }

    /**
     * Shared cleanup for terminal stops that leave the live site unchanged
     * (apart from this plugin's progress options). The caller writes its one
     * stop line first; this adds "review_ask: none (stopped)", clears the
     * review flags, queue rows and staging tables, then the extracted SQL.
     */
    private static function cleanup_after_restore_no_change_stop()
    {
        self::fail_restore_before_file_swap();
        self::remove_extracted_restore_sql();
    }

    /**
     * Remove the restore workspace (extracted SQL dump and part archives),
     * including a 3.1.20 wp-content/ocm_restore, after a no-change stop or a
     * Restore failed.
     */
    private static function remove_extracted_restore_sql()
    {
        foreach (self::existing_restore_work_paths() as $dir) {
            self::deleteDirectoryBestEffort($dir);
        }
    }

    /**
     * Terminal stop when the database archive could not be downloaded,
     * decrypted, or extracted. No file replace, DB import, or plugin
     * deactivation has run.
     *
     * @return never
     */
    private static function stop_restore_after_db_part_failure()
    {
        One_Click_Migration::write_to_log(self::LOG_RESTORE_STOPPED_DB_PART);
        self::cleanup_after_restore_no_change_stop();
        exit;
    }

    /**
     * Deactivate other plugins only after DB import + options validation succeed
     * (or on files-only restores), immediately before file swap.
     */
    private static function ensure_plugins_deactivated_for_restore()
    {
        if (get_option(self::OPTION_RESTORE_PLUGINS_ISOLATED)) {
            return;
        }
        self::force_only_ocm_plugin_active('before file replace');
        update_option(self::OPTION_RESTORE_PLUGINS_ISOLATED, 1, true);
    }

    /**
     * Reinstate the active_plugins list saved at isolation time.
     * On a DB restore that list is the backup's own plugins (imported before
     * deferral). On failure before isolation this is a no-op.
     *
     * @return void
     */
    private static function restore_active_plugins_after_failed_restore()
    {
        $saved = get_option('ocm_force_deactivated_plugins', null);
        if (!is_array($saved) || !$saved) {
            return;
        }
        update_option('active_plugins', $saved, true);
        delete_option('ocm_force_deactivated_plugins');
        delete_option(self::OPTION_RESTORE_PLUGINS_ISOLATED);
        wp_cache_delete('active_plugins', 'options');
        wp_cache_delete('alloptions', 'options');
    }

    /**
     * Shared bookkeeping when restore fails before any file swap: clear review
     * flags, restore active_plugins if needed, drop staging tables and queue
     * rows, log "review_ask: none (stopped)", stop cleanly.
     *
     * @return void
     */
    private static function fail_restore_before_file_swap()
    {
        self::restore_active_plugins_after_failed_restore();
        self::drop_leftover_staging_tables_after_stop();
        // No review CTA; do not consume the first-clean restore flag.
        update_option('ocm_show_restore_success', 0, false);
        update_option('ocm_show_review_cta', 0, false);
        update_option(self::OPTION_RESTORE_WAS_WARNED, 0, false);
        delete_option(OCM_Extra_Domains::OPTION);
        One_Click_Migration::write_to_log('SYSLOG: review_ask: none (stopped)');
        One_Click_Migration::disable_maintenance_mode();
        OCM_Debug_Mode::disable();
        One_Click_Migration::reset_actions_start_mark();
        update_option('ocm_is_stopped', true, true);
        // Drops the queue rows so no worker restarts the stopped restore.
        self::end_restore_run();
    }

    /**
     * Remove leftover background-restore batch rows so a stopped restore cannot
     * be restarted by a queued worker item.
     *
     * @return void
     */
    private static function clear_restore_batch_option_rows()
    {
        global $wpdb;
        $prefix = 'wp_ocm_background_restore_batch_';
        if (is_object($wpdb) && method_exists($wpdb, 'query') && !empty($wpdb->options)) {
            $table = $wpdb->options;
            $column = 'option_name';
            if (function_exists('is_multisite') && is_multisite() && !empty($wpdb->sitemeta)) {
                $table = $wpdb->sitemeta;
                $column = 'meta_key';
            }
            if (method_exists($wpdb, 'get_col')) {
                $names = $wpdb->get_col(
                    "SELECT {$column} FROM {$table} WHERE {$column} LIKE '{$prefix}%'"
                );
                if (is_array($names)) {
                    foreach ($names as $name) {
                        if (is_string($name) && $name !== '') {
                            delete_option($name);
                        }
                    }
                }
            }
            $wpdb->query("DELETE FROM {$table} WHERE {$column} LIKE '{$prefix}%'");
        }
        // Test harnesses (and any in-request option bag) store rows via update_option.
        if (isset($GLOBALS['ocm_options']) && is_array($GLOBALS['ocm_options'])) {
            foreach (array_keys($GLOBALS['ocm_options']) as $name) {
                if (is_string($name) && strpos($name, $prefix) === 0) {
                    delete_option($name);
                }
            }
        }
    }

    /**
     * Drop any ocmXXXX_ staging tables left after an import-stage stop.
     *
     * @return void
     */
    private static function drop_leftover_staging_tables_after_stop()
    {
        global $wpdb;
        if (!is_object($wpdb)) {
            return;
        }
        OCM_SQL_Utils::drop_stale_temp_tables(
            $wpdb,
            null,
            isset($wpdb->base_prefix) ? $wpdb->base_prefix : null
        );
        delete_option(OCM_SQL_Utils::OPTION_STAGING_PREFIX);
        self::clear_db_import_state();
    }

    /**
     * Arm a shutdown guard for the staging-import + URL-rewrite window.
     * A PHP fatal (e.g. memory exhausted) must not leave staging tables or
     * restart the worker mid-URL-step.
     *
     * @return void
     */
    public static function register_import_oom_guard()
    {
        if (self::$import_oom_guard_active) {
            return;
        }
        self::$import_oom_guard_active = true;
        self::$import_oom_stop_written = false;
        register_shutdown_function(array(__CLASS__, 'handle_import_oom_shutdown'));
    }

    /**
     * Disarm the import OOM guard after a normal (non-fatal) exit from the stage.
     *
     * @return void
     */
    public static function clear_import_oom_guard()
    {
        self::$import_oom_guard_active = false;
    }

    /**
     * Shutdown callback: on fatal during import/URL stage, stop with
     * LOG_RESTORE_STOPPED_IMPORT and run the shared stop bookkeeping
     * (fail_restore_before_file_swap: staging tables, restore batches, flags).
     *
     * @param array|null $error Optional error array (tests); defaults to error_get_last().
     * @return void
     */
    public static function handle_import_oom_shutdown($error = null)
    {
        if (!self::$import_oom_guard_active || self::$import_oom_stop_written) {
            return;
        }
        if ($error === null) {
            $error = error_get_last();
        }
        if (!is_array($error) || empty($error['type'])) {
            return;
        }
        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (defined('E_RECOVERABLE_ERROR')) {
            $fatal_types[] = E_RECOVERABLE_ERROR;
        }
        if (!in_array((int) $error['type'], $fatal_types, true)) {
            return;
        }

        self::$import_oom_stop_written = true;
        self::$import_oom_guard_active = false;

        One_Click_Migration::write_to_log(self::LOG_RESTORE_STOPPED_IMPORT);
        self::fail_restore_before_file_swap();
    }

    /**
     * Which WP_Background_Process copy this request loaded. Another plugin's
     * older copy (WooCommerce's, #127) wins if it loads first.
     *
     * @return string
     */
    public static function background_library_diagnostic()
    {
        if (!class_exists('WP_Background_Process', false)) {
            return 'WP_Background_Process: not loaded';
        }
        try {
            $file = (string) (new \ReflectionClass('WP_Background_Process'))->getFileName();
            $public = method_exists('WP_Background_Process', 'lock_process')
                && (new \ReflectionMethod('WP_Background_Process', 'lock_process'))->isPublic();
        } catch (\Throwable $e) {
            return 'WP_Background_Process: reflection failed';
        }
        $file = str_replace('\\', '/', $file);
        $content_dir = defined('WP_CONTENT_DIR') ? rtrim(str_replace('\\', '/', WP_CONTENT_DIR), '/') . '/' : '';
        if ('' !== $content_dir && 0 === strpos($file, $content_dir)) {
            $file = 'wp-content/' . substr($file, strlen($content_dir));
        }
        return sprintf(
            'WP_Background_Process loaded from %s (lock_process public: %s)',
            '' !== $file ? $file : 'unknown',
            $public ? 'yes' : 'no'
        );
    }

    /**
     * Arm a shutdown guard for the backup worker, like the restore one: a
     * backup whose worker dies on a PHP fatal resumes on its own.
     *
     * @return void
     */
    public static function register_backup_crash_guard()
    {
        self::$backup_crash_guard_armed = true;
        if (self::$backup_crash_guard_registered) {
            return;
        }
        self::$backup_crash_guard_registered = true;
        register_shutdown_function(array(__CLASS__, 'handle_backup_crash_shutdown'));
    }

    /**
     * The backup task returned normally.
     *
     * @return void
     */
    public static function disarm_backup_crash_guard()
    {
        self::$backup_crash_guard_armed = false;
    }

    /**
     * Shutdown callback after a PHP fatal inside a backup task. Runs at most
     * once per request.
     *
     * - The run is still active (a time-limit or memory fatal logs no
     *   "Error:" line): it resumes from its last completed step.
     * - The fatal's own "Error:" line ended the run: it gets up to
     *   RESTORE_CRASH_RESUMES automatic resumes, then fails with "Backup
     *   failed:" and frees the backup slot, so Start backup works again.
     *
     * @param array|null $error Optional error array (tests); defaults to error_get_last().
     * @return void
     */
    public static function handle_backup_crash_shutdown($error = null)
    {
        if (!self::$backup_crash_guard_armed) {
            return;
        }
        if ($error === null) {
            $error = error_get_last();
        }
        if (!is_array($error) || empty($error['type'])) {
            return;
        }
        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (!in_array((int) $error['type'], $fatal_types, true)) {
            return;
        }
        self::$backup_crash_guard_armed = false;
        $message = isset($error['message']) ? strtok((string) $error['message'], "\n") : '';

        if (get_option('ocm_action_start_backup')) {
            One_Click_Migration::write_to_log(sprintf(
                ' SYSLOG: Backup worker stopped on a PHP fatal (%s); the run is still active and resumes from its last completed step.',
                $message
            ));
            self::request_worker_resume('backup');
            One_Click_Migration::flush_log_upload();
            return;
        }

        $run_id = self::$backup_run_id;
        if ('' === $run_id || !self::is_current_backup_run($run_id)) {
            // A worker from an ended or replaced run: nothing to resume.
            return;
        }
        $used = self::crash_resumes_used(self::BACKUP_CRASH_OPTION, $run_id);
        if ($used < self::RESTORE_CRASH_RESUMES) {
            update_option(self::BACKUP_CRASH_OPTION, array('run' => $run_id, 'count' => $used + 1), false);
            One_Click_Migration::write_to_log(sprintf(
                ' SYSLOG: Backup worker crashed on a PHP fatal (%s); resuming from the last completed step (automatic resume %d of %d).',
                $message,
                $used + 1,
                self::RESTORE_CRASH_RESUMES
            ));
            One_Click_Migration::write_to_log(sprintf(
                '%s (attempt %d of %d). You don\'t need to do anything.',
                self::LOG_BACKUP_RESUMING,
                $used + 1,
                self::RESTORE_CRASH_RESUMES
            ));
            // After the log lines: the fatal's text quoted above can hold
            // "Error:", and write_to_log() ends the run on any such line.
            update_option('ocm_action_start_backup', true, true);
            self::request_worker_resume('backup');
            One_Click_Migration::flush_log_upload();
            return;
        }
        One_Click_Migration::write_to_log(sprintf(
            ' SYSLOG: Backup worker crashed on a PHP fatal (%s); no automatic resumes left.',
            $message
        ));
        self::fail_backup(sprintf(
            'it stopped after %d unexpected problems. Start the backup again. If it stops again, ask for help in the support forum.',
            $used + 1
        ));
        One_Click_Migration::flush_log_upload();
    }

    /**
     * Arm a shutdown guard for the restore worker. A PHP fatal anywhere in the
     * restore must not leave the site serving the maintenance page (#127).
     * Error_Handler also calls the guard before it die()s on a plugin-file
     * fatal, since that die() skips every later shutdown callback.
     *
     * @return void
     */
    public static function register_restore_crash_guard()
    {
        self::$restore_crash_guard_armed = true;
        if (self::$restore_crash_guard_registered) {
            return;
        }
        self::$restore_crash_guard_registered = true;
        register_shutdown_function(array(__CLASS__, 'handle_restore_crash_shutdown'));
    }

    /**
     * The restore task returned normally: a later fatal in this request is
     * not a restore crash.
     *
     * @return void
     */
    public static function disarm_restore_crash_guard()
    {
        self::$restore_crash_guard_armed = false;
    }

    /**
     * Shutdown callback after a PHP fatal inside a restore task. Runs at most
     * once per request.
     *
     * - The run is still active (a time-limit or memory fatal logs no
     *   "Error:" line): it resumes from its last completed step, so the site
     *   stays in maintenance mode.
     * - The fatal's own "Error:" line ended the run: it gets up to
     *   RESTORE_CRASH_RESUMES automatic resumes, still in maintenance mode.
     *   After that it fails for good and maintenance mode goes off (#127).
     * Either way the dying worker's locks are released and the watchdog is
     * asked to resume at once: from the progress poll while the plugin page
     * is open, otherwise from WordPress cron.
     *
     * @param array|null $error Optional error array (tests); defaults to error_get_last().
     * @return void
     */
    public static function handle_restore_crash_shutdown($error = null)
    {
        if (!self::$restore_crash_guard_armed) {
            return;
        }
        if ($error === null) {
            $error = error_get_last();
        }
        if (!is_array($error) || empty($error['type'])) {
            return;
        }
        $fatal_types = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
        if (!in_array((int) $error['type'], $fatal_types, true)) {
            return;
        }
        self::$restore_crash_guard_armed = false;
        $message = isset($error['message']) ? strtok((string) $error['message'], "\n") : '';

        if (get_option('ocm_action_start_restore')) {
            One_Click_Migration::write_to_log(sprintf(
                ' SYSLOG: Restore worker stopped on a PHP fatal (%s); the run is still active and resumes from its last completed step.',
                $message
            ));
            self::request_worker_resume('restore');
            One_Click_Migration::flush_log_upload();
            return;
        }

        $run_id = self::$restore_run_id;
        if ('' !== $run_id && self::is_current_restore_run($run_id)) {
            $used = self::crash_resumes_used(self::RESTORE_CRASH_OPTION, $run_id);
            if ($used < self::RESTORE_CRASH_RESUMES) {
                update_option(self::RESTORE_CRASH_OPTION, array('run' => $run_id, 'count' => $used + 1), false);
                One_Click_Migration::write_to_log(sprintf(
                    ' SYSLOG: Restore worker crashed on a PHP fatal (%s); resuming from the last completed step (automatic resume %d of %d).',
                    $message,
                    $used + 1,
                    self::RESTORE_CRASH_RESUMES
                ));
                One_Click_Migration::write_to_log(sprintf(
                    '%s (attempt %d of %d). You don\'t need to do anything.',
                    self::LOG_RESTORE_RESUMING,
                    $used + 1,
                    self::RESTORE_CRASH_RESUMES
                ));
                // After the log lines: the fatal's text quoted above can hold
                // "Error:", and write_to_log() ends the run on any such line.
                update_option('ocm_action_start_restore', true, true);
                self::request_worker_resume('restore');
                One_Click_Migration::flush_log_upload();
                return;
            }
            One_Click_Migration::write_to_log(sprintf(
                ' SYSLOG: Restore worker crashed on a PHP fatal (%s); no automatic resumes left.',
                $message
            ));
            One_Click_Migration::write_to_log(sprintf(
                'Restore failed: it stopped after %d unexpected problems. Start the restore again. If it stops again, ask for help in the support forum.',
                $used + 1
            ));
            update_option('ocm_is_stopped', true, true);
            self::end_restore_run();
            if (get_option('ocm_maintenance_mode', false)) {
                One_Click_Migration::disable_maintenance_mode();
            }
            One_Click_Migration::flush_log_upload();
            return;
        }

        // A worker from an ended or replaced run: nothing to resume.
        if (!get_option('ocm_maintenance_mode', false)) {
            return;
        }
        One_Click_Migration::write_to_log(sprintf(
            'Restore worker crashed on a PHP fatal (%s). Turning maintenance mode off.',
            $message
        ));
        One_Click_Migration::disable_maintenance_mode();
    }

    /**
     * End a run that is still marked active but has no work item left, with a
     * plain-language failure line.
     *
     * @param bool $restore True for a restore run, false for a backup.
     * @return void
     */
    private static function end_orphaned_run($restore)
    {
        if (!$restore) {
            One_Click_Migration::write_to_log(' SYSLOG: Backup queue is empty while the run is still active; ending it.');
            // fail_backup() releases the run this request knows about.
            $run = get_option(self::BACKUP_RUN_OPTION, array());
            if (is_array($run) && !empty($run['id'])) {
                self::$backup_run_id = (string) $run['id'];
            }
            self::fail_backup('it stopped unexpectedly and could not continue. Start the backup again. If it stops again, ask for help in the support forum.');
            return;
        }
        One_Click_Migration::write_to_log(' SYSLOG: Restore queue is empty while the run is still active; ending it.');
        One_Click_Migration::write_to_log('Restore failed: it stopped unexpectedly and could not continue. Start the restore again. If it stops again, ask for help in the support forum.');
        One_Click_Migration::disable_maintenance_mode();
        OCM_Debug_Mode::disable();
        One_Click_Migration::reset_actions_start_mark();
        update_option('ocm_is_stopped', true, true);
        self::end_restore_run();
    }

    /**
     * Automatic resumes a run has used after worker crashes.
     *
     * @param string $option RESTORE_CRASH_OPTION or BACKUP_CRASH_OPTION.
     * @param string $run_id
     * @return int
     */
    private static function crash_resumes_used($option, $run_id)
    {
        $state = get_option($option, array());
        if (!is_array($state) || !isset($state['run'], $state['count']) || !is_string($state['run'])
            || !hash_equals($state['run'], (string) $run_id)
        ) {
            return 0;
        }
        return max(0, (int) $state['count']);
    }

    /**
     * Called from a dying backup or restore worker: free its locks now instead
     * of when they expire, and ask the watchdog to resume the run on its next
     * check.
     *
     * @param string $kind 'backup' or 'restore'.
     * @return void
     */
    private static function request_worker_resume($kind)
    {
        if ('restore' === $kind) {
            $process = One_Click_Migration::$process_restore_single;
            if (is_object($process) && $process instanceof OCM_BackgroundRestore) {
                $process->unlock_process();
            }
            OCM_BackgroundRestore::release_worker_file_lock();
            update_option(self::RESTORE_RESUME_OPTION, time(), false);
        } else {
            $process = One_Click_Migration::$process_backup_single;
            if (is_object($process) && $process instanceof OCM_BackgroundBackup) {
                $process->unlock_process();
            }
            update_option(self::BACKUP_RESUME_OPTION, time(), false);
        }
        self::schedule_worker_watchdog(10, true);
    }

    /**
     * Queue a WordPress cron check of the backup or restore worker. Cron runs
     * on any request to the site, including visitors shown the maintenance
     * page, so a stalled or crashed run resumes even with the plugin page
     * closed.
     *
     * @param int  $delay  Seconds from now.
     * @param bool $sooner Move an already queued check forward to $delay.
     * @return void
     */
    public static function schedule_worker_watchdog($delay = 60, $sooner = false)
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return;
        }
        $at = time() + max(1, (int) $delay);
        $next = wp_next_scheduled(self::WORKER_WATCHDOG_HOOK);
        if ($next) {
            if (!$sooner || $next <= $at || !function_exists('wp_unschedule_event')) {
                return;
            }
            wp_unschedule_event($next, self::WORKER_WATCHDOG_HOOK);
        }
        wp_schedule_single_event($at, self::WORKER_WATCHDOG_HOOK);
    }

    /**
     * Cron callback: while a backup or restore is active, check on its worker
     * once a minute and resume it if it stalled or crashed. The chain stops
     * once the run ends.
     *
     * @return void
     */
    public static function run_worker_watchdog()
    {
        if (!get_option('ocm_action_start_restore') && !get_option('ocm_action_start_backup')) {
            return;
        }
        self::schedule_worker_watchdog(60);
        self::maybe_revive_stalled_worker((bool) get_option('ocm_is_stopped'), true);
    }

    /**
     * Block themes/plugins/uploads replacement when db download/unpack failed
     * or when binary preflight refuses the dump. Does not deactivate plugins —
     * that waits until apply_deferred_file_swaps() after a successful DB step.
     *
     * @return void
     */
    private static function gate_restore_before_file_replace()
    {
        if (get_option(self::OPTION_RESTORE_DB_PART_FAILED)) {
            self::stop_restore_after_db_part_failure();
        }
        self::stop_if_db_unavailable_for_restore();
        self::keep_only_source_table_set();
        $preflight = self::ensure_binary_preflight_before_file_replace();
        if ($preflight === 'continue') {
            One_Click_Migration::write_to_log('SYSLOG: Binary preflight scan still running; restarting worker.');
            One_Click_Migration::write_to_log('Process is Restarting');
            if (isset(One_Click_Migration::$process_restore_single) && is_object(One_Click_Migration::$process_restore_single)) {
                One_Click_Migration::$process_restore_single->restart_task();
            }
            exit;
        }
        if ($preflight === false) {
            self::stop_restore_after_binary_preflight_refusal();
        }
    }

    /**
     * The DB part holds every table in the source database, so other installs
     * or staging copies sharing it come along as extra WordPress table sets.
     * Their dumps would be renamed onto this site's prefix and overwrite its
     * tables, so keep the backed-up site's set and delete the others'
     * extracted dumps (usermeta last: while any of a set's dumps remain, the
     * set is still recognisable to a resumed worker). Stops the restore,
     * before anything changes, when the backup can't say which set is the
     * site's.
     *
     * @return void
     */
    private static function keep_only_source_table_set()
    {
        $excluded = get_option('ocm_excluded_folders', array());
        if (is_array($excluded) && in_array('db', $excluded, true)) {
            return;
        }
        $dbfiles = self::get_restore_work_path() . '/db/';
        if (!is_dir($dbfiles)) {
            return;
        }
        $prefixes = OCM_SQL_Utils::wordpress_table_set_prefixes($dbfiles);
        if (count($prefixes) < 2) {
            return;
        }

        $bucket_id = (string) get_option('ocm_bucket_id', '');
        $log = '';
        if ($bucket_id !== '' && defined('OCM_DEBUG_LOG_FILE') && file_exists(OCM_DEBUG_LOG_FILE)) {
            $log = (string) @file_get_contents(OCM_DEBUG_LOG_FILE);
        }
        $recorded = '';
        if ($log !== '' && preg_match_all('/BACKUP_TABLE_PREFIX bucket=' . preg_quote($bucket_id, '/') . ' prefix=([A-Za-z0-9_]+)/', $log, $matches)) {
            $recorded = (string) end($matches[1]);
        }
        $manifest = $log !== '' ? OCM_URL_Manifest::from_log($log, $bucket_id) : null;
        $source_url = is_array($manifest) ? (string) $manifest['source'] : '';

        $resolved = OCM_SQL_Utils::resolve_source_table_set($dbfiles, $prefixes, $recorded, $source_url);
        if ($resolved === false) {
            One_Click_Migration::write_to_log(sprintf(
                'Error: The backup\'s database holds the tables of %d WordPress sites (table prefixes: %s), and this backup doesn\'t say which one is yours. Update 1-Click Migration on the site you backed up, take a new backup there, and restore that one.',
                count($prefixes),
                implode(', ', $prefixes)
            ));
            self::stop_restore_during_db_import();
        }

        $source = $resolved['prefix'];
        $others = array_values(array_diff($prefixes, array($source)));
        $deleted = 0;
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
            if (@unlink($filepath)) {
                $deleted++;
            }
        }
        foreach ($last as $filepath) {
            if (@unlink($filepath)) {
                $deleted++;
            }
        }

        One_Click_Migration::write_to_log(sprintf(
            'Notice: The backup\'s database also holds the tables of %d other WordPress site(s) (table prefixes: %s). Only this site\'s tables (prefix "%s") will be restored.',
            count($others),
            implode(', ', $others),
            $source
        ));
        One_Click_Migration::write_to_log(sprintf(
            ' SYSLOG: Source table set "%s" chosen by %s; removed %d dump(s) of other sets.',
            $source,
            $resolved['how'],
            $deleted
        ));
    }

    /**
     * Swap extracted themes/uploads/plugins into place only after DB import and
     * options/prefix validation have succeeded (or on an intentional files-only
     * restore where db is excluded). A failed DB step must never reach here.
     *
     * @param array $presigned_urls
     * @return void
     */
    private static function apply_deferred_file_swaps($presigned_urls)
    {
        $excluded_restore_files = get_option('ocm_excluded_folders', array());
        if (!is_array($excluded_restore_files)) {
            $excluded_restore_files = array();
        }
        $skipped_restore_files = get_option('ocm_skipped_folders', array());
        if (!is_array($skipped_restore_files)) {
            $skipped_restore_files = array();
        }
        $content_dir = self::get_restore_work_path();

        self::gate_restore_before_file_replace();
        // Plugins stay active through DB staging/validation; isolate only now.
        self::ensure_plugins_deactivated_for_restore();

        foreach (array('themes', 'uploads') as $key) {
            if (in_array($key, $excluded_restore_files, true)) {
                continue;
            }
            if (self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW)) {
                continue;
            }

            $extract_path = $content_dir . '/' . $key;
            if (!is_dir($extract_path)) {
                self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                continue;
            }

            $directory = WP_CONTENT_DIR . '/' . $key;

            // An empty uploads archive must not wipe the destination's
            // existing media (mirror of an empty tree).
            if ($key === 'uploads' && !self::directory_contains_files($extract_path)) {
                One_Click_Migration::write_to_log(
                    'Notice: Backup uploads archive is empty; leaving the destination uploads folder unchanged.'
                );
                self::deleteDirectoryBestEffort($extract_path);
                self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                continue;
            }

            One_Click_Migration::write_to_log(sprintf('Restoring "%s" to directory "%s"', ucfirst($key), $directory));

            if (!self::replaceDirectoryFromExtract($extract_path, $directory, $key)) {
                One_Click_Migration::write_to_log(sprintf(
                    'Downloading restore files. Error: "%s" directory could not be moved or copied to "%s" directory. Please check the write permission for these directories.',
                    $extract_path,
                    $directory
                ));
                One_Click_Migration::write_to_log(sprintf(
                    'Restore failed: restored "%s" files could not be moved into place (write permission).',
                    $key
                ));
                update_option('ocm_is_stopped', true, true);
                die();
            }

            if (isset(One_Click_Migration::$process_restore_single)
                && is_object(One_Click_Migration::$process_restore_single)
                && One_Click_Migration::$process_restore_single->time_exceeded()
            ) {
                $next_retry_count = get_option('ocm_restore_child_delete_retry_' . $key, 1);
                if ($next_retry_count && $next_retry_count <= self::MAX_STEP_RETRIES) {
                    One_Click_Migration::write_to_log('Process is Restarting');
                    update_option('ocm_restore_child_delete_retry_' . $key, $next_retry_count, true);
                    One_Click_Migration::$process_restore_single->restart_task();
                }

                One_Click_Migration::write_to_log(sprintf(
                    'Skipping %s old-folder cleanup after repeated timeouts (new copy is in place).',
                    $key
                ));
                self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                self::record_restore_part_warning($key, 'copied, old copy not removed');
                self::capture_skipped_restore_part($excluded_restore_files, $skipped_restore_files, $key);
                continue;
            }

            self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
        }

        if (!in_array('themes', $excluded_restore_files, true)) {
            if (!self::check_is_complete_restore_step('themes', self::STEP_RESTORE_CHILD_THEMES_RESTORED)) {
                One_Click_Migration::write_to_log('Themes have been restored');
                self::set_complete_restore_step('themes', self::STEP_RESTORE_CHILD_THEMES_RESTORED);
            }
        }
        if (!in_array('uploads', $excluded_restore_files, true)) {
            if (!self::check_is_complete_restore_step('uploads', self::STEP_RESTORE_CHILD_UPLOADS_RESTORED)) {
                One_Click_Migration::write_to_log('Uploads have been restored');
                self::set_complete_restore_step('uploads', self::STEP_RESTORE_CHILD_UPLOADS_RESTORED);
            }
        }

        if (!in_array('plugins', $excluded_restore_files, true)) {
            if (!self::check_is_complete_restore_step('plugins', self::STEP_RESTORE_CHILD_RESTORE)) {
                self::restore_plugins($presigned_urls);
            }
        }

        // File swap done: put back the plugins list saved at isolation. After a
        // DB import that list is the source dump's active_plugins (master behaviour).
        self::restore_active_plugins_after_failed_restore();
    }

    /**
     * Fingerprint for the extracted dump set + backup id. A stored verdict
     * whose key does not match must be ignored (rescan, never pass).
     *
     * @return string
     */
    public static function binary_preflight_fingerprint()
    {
        $bucket = (string) get_option('ocm_bucket_id', '');
        $dbfiles = self::get_restore_work_path() . '/db/';
        $parts = array('bucket=' . $bucket);
        $files = OCM_SQL_Utils::list_restore_sql_dump_files($dbfiles);
        foreach ($files as $filepath) {
            $parts[] = basename($filepath) . ':' . (int) @filesize($filepath) . ':' . (int) @filemtime($filepath);
        }
        return sha1(implode('|', $parts));
    }

    /**
     * After prefix remap rewrites dumps in place (size/mtime change), re-key a
     * stored pass verdict so a resumed import still sees a matching pass and
     * does not rescan / refuse. Only rekeys when the stored key still equals
     * the fingerprint captured before this rewrite; otherwise leave it so the
     * scan reruns.
     *
     * @param string $pre_rewrite_key Fingerprint from before swap_table_prefix.
     * @return void
     */
    public static function rekey_binary_preflight_after_prefix_rewrite($pre_rewrite_key)
    {
        $pre_rewrite_key = (string) $pre_rewrite_key;
        if ($pre_rewrite_key === '') {
            return;
        }
        $stored = get_option(self::OPTION_BINARY_PREFLIGHT, null);
        if (!is_array($stored) || !isset($stored['status'], $stored['key']) || $stored['status'] !== 'pass') {
            return;
        }
        if ((string) $stored['key'] !== $pre_rewrite_key) {
            return;
        }
        $stored['key'] = self::binary_preflight_fingerprint();
        update_option(self::OPTION_BINARY_PREFLIGHT, $stored, false);
    }

    /**
     * Scan already-extracted dumps under the workspace's db/ before replacing files.
     * Verdicts are persisted against binary_preflight_fingerprint(); a mismatched
     * key is treated as no verdict (rescan). Large dumps resume across requests.
     *
     * @return bool|string True when restore may continue; false when refused;
     *                     'continue' when the scan needs another worker pass.
     */
    public static function ensure_binary_preflight_before_file_replace()
    {
        if (self::$db_restore_refused_binary) {
            return false;
        }
        if (get_option(self::OPTION_RESTORE_DB_PART_FAILED)) {
            return false;
        }
        if (self::$binary_preflight_passed) {
            return true;
        }

        $excluded = get_option('ocm_excluded_folders', array());
        // User-excluded db (selective restore) skips binary preflight.
        if (is_array($excluded) && in_array('db', $excluded, true)) {
            self::$binary_preflight_passed = true;
            return true;
        }

        $dbfiles = self::get_restore_work_path() . '/db/';
        if (!is_dir($dbfiles)) {
            // DB not extracted yet. Callers with db-first ordering only replace
            // files after the db iteration has finished extract+preflight.
            return true;
        }

        $sql_files = OCM_SQL_Utils::list_restore_sql_dump_files($dbfiles);
        if ($sql_files === array()) {
            // Db part extracted but no SQL dumps — fail closed.
            One_Click_Migration::write_to_log(
                'Error: Database restore folder has no .sql dump files to import.'
            );
            One_Click_Migration::write_to_log(
                'Restore stopped before import: backup needs a fresh 3.1.19 backup. No changes were made.'
            );
            self::$db_restore_refused_binary = true;
            update_option(self::OPTION_BINARY_PREFLIGHT, array(
                'key' => self::binary_preflight_fingerprint(),
                'status' => 'refuse',
            ), false);
            return false;
        }

        $key = self::binary_preflight_fingerprint();
        $stored = get_option(self::OPTION_BINARY_PREFLIGHT, null);
        if (is_array($stored) && isset($stored['key'], $stored['status']) && $stored['key'] === $key) {
            if ($stored['status'] === 'pass') {
                self::$binary_preflight_passed = true;
                return true;
            }
            if ($stored['status'] === 'refuse') {
                self::$db_restore_refused_binary = true;
                return false;
            }
        } else {
            // Key mismatch or absent → treat as no verdict (never pass on stale).
            $stored = array('key' => $key, 'status' => 'pending', 'file_index' => 0, 'byte_offset' => 0);
            delete_option(self::OPTION_BINARY_PREFLIGHT);
        }

        $deadline = microtime(true) + 8.0;
        $result = OCM_SQL_Utils::preflight_reject_unsafe_binary_dumps_resumable($sql_files, $stored, $deadline);
        $status = isset($result['status']) ? $result['status'] : 'refuse';
        $state = isset($result['state']) && is_array($result['state']) ? $result['state'] : array();
        $state['key'] = $key;
        $state['status'] = $status;
        update_option(self::OPTION_BINARY_PREFLIGHT, $state, false);

        if ($status === 'continue') {
            return 'continue';
        }
        if ($status === 'refuse') {
            self::$db_restore_refused_binary = true;
            return false;
        }

        self::$binary_preflight_passed = true;
        return true;
    }

    /**
     * Terminal stop after binary preflight refusal (marker already in the log).
     *
     * @return never
     */
    private static function stop_restore_after_binary_preflight_refusal()
    {
        self::cleanup_after_restore_no_change_stop();
        exit;
    }

    /**
     * Terminal stop when binary preflight refuses (or cannot finish) at the
     * import step. Deferred file swap + staging import mean live files/tables
     * are untouched — LOG_RESTORE_STOPPED_IMPORT states that honestly.
     *
     * @return never
     */
    public static function stop_restore_during_db_import()
    {
        self::$db_restore_refused_binary = true;
        self::clear_import_oom_guard();
        One_Click_Migration::write_to_log(self::LOG_RESTORE_STOPPED_IMPORT);
        self::cleanup_after_restore_no_change_stop();
        exit;
    }

    private static function restore_db($presigned_urls, wpdb $wpdb)
    {

        OCM_SQL_Utils::check_proc_available(false, $presigned_urls);

        // Get current site URL here
        $current_url = site_url();
        $dbfiles = self::get_restore_work_path() . '/db/';
        $excluded_folders = get_option( 'ocm_excluded_folders', []);



        //cater for the database restore.
        if(!in_array('db', $excluded_folders)){

          if (!is_dir($dbfiles)) {
              One_Click_Migration::write_to_log(sprintf('Error: DB directory "%s" does not exist in unzipped files.', $dbfiles));
              return false;
          }

          $sql_bytes = 0;
          foreach (OCM_SQL_Utils::list_restore_sql_dump_files($dbfiles) as $sql_file) {
              $sql_bytes += (int) filesize($sql_file);
          }
          OCM_SQL_Utils::preflight_db_memory($sql_bytes, 'import');
        }

        // Progress of an import an earlier worker started. Its dumps on disk
        // are already rewritten for its staging prefix.
        $state = self::db_import_state();

        // Detect source table prefix from backup SQL filenames (via usermeta.sql),
        // once other installs' table sets are gone (normally done at the gate).
        self::keep_only_source_table_set();
        $source_prefix = is_dir($dbfiles)
            ? OCM_SQL_Utils::get_table_prefix($dbfiles . 'dummy.sql')
            : false;
        if ($source_prefix === false || $source_prefix === '') {
            One_Click_Migration::write_to_log('Error: Database restore could not determine one unambiguous source table prefix.');
            return false;
        }
        if ($state === null) {
            One_Click_Migration::write_to_log(sprintf(
                'Detected source_prefix="%s" dest_base_prefix="%s" dest_prefix="%s"',
                $source_prefix,
                $wpdb->base_prefix,
                $wpdb->prefix
            ));
        }

        $found_files = OCM_SQL_Utils::list_restore_sql_dump_files($dbfiles);
        if (is_dir($dbfiles)) {
            $dir = new DirectoryIterator($dbfiles);
            foreach ($dir as $fileinfo) {
                if ($fileinfo->isDot() || !$fileinfo->isFile()) {
                    continue;
                }
                if (strtolower(substr($fileinfo->getFilename(), -4)) === '.sql') {
                    continue;
                }
                // Untouched copy of a dump whose prefix rewrite was cut off;
                // prepare_dump_for_staging_once() takes it back.
                if (substr($fileinfo->getFilename(), -strlen(self::PRISTINE_DUMP_SUFFIX)) === self::PRISTINE_DUMP_SUFFIX) {
                    continue;
                }
                // Leftover work files from an interrupted run (e.g. *.ocm-stream.tmp)
                // must never be imported: they can carry a stale or partial prefix
                // state and would DROP + re-create tables that were just restored.
                One_Click_Migration::write_to_log(sprintf('Notice: Skipping non-SQL file "%s" in the DB restore directory.', $fileinfo->getFilename()));
            }
        }

        // Give priority to WP tables so they're imported first
        $priority = array(
            'options.sql',
            'users.sql',
            'links.sql',
            'commentmeta.sql',
            'term_relationships.sql',
            'postmeta.sql',
            'posts.sql',
            'term_taxonomy.sql',
            'usermeta.sql',
            'terms.sql',
            'comments.sql',
            'termmeta.sql',
        );

        $result = array();
        foreach ($priority as $p) {
            $result = array_unique(array_merge($result, preg_grep('/' . $p . '/i', $found_files)));
        }

        $sorted_table_list = array_unique(array_merge($result, $found_files));

        sleep(1);

        // Preflight: refuse dumps whose declared binary/BLOB INSERT values cannot
        // round-trip, before allocating staging or touching live tables. Usually
        // already ran after DB extract (before file replace); memoized on pass so
        // the happy path does not scan twice in one request.
        $preflight = self::ensure_binary_preflight_before_file_replace();
        if ($preflight === 'continue') {
            // Never treat an incomplete scan as refusal at the import step —
            // requeue so the resumable scan can finish.
            One_Click_Migration::write_to_log('SYSLOG: Binary preflight scan still running at import; restarting worker.');
            One_Click_Migration::write_to_log('Process is Restarting');
            if (isset(One_Click_Migration::$process_restore_single) && is_object(One_Click_Migration::$process_restore_single)) {
                One_Click_Migration::$process_restore_single->restart_task();
            }
            exit;
        }
        if ($preflight !== true) {
            // Files and live tables are still untouched (staging import + deferred
            // file swap). Same no-change stop path as the early gate.
            self::stop_restore_during_db_import();
        }

        $dump_names = array_values(array_map('basename', $sorted_table_list));
        if ($state !== null && !self::db_import_state_matches($state, $source_prefix, $dump_names)) {
            if (!empty($state['prepared'])) {
                // Some dumps were rewritten for a prefix this worker can't confirm.
                One_Click_Migration::write_to_log('Error: Database restore could not resume: the saved staging import no longer matches the extracted database dumps.');
                return false;
            }
            // Nothing was rewritten yet: start over.
            self::clear_db_import_state();
            $state = null;
        }
        if ($state === null && (string) get_option(OCM_SQL_Utils::OPTION_STAGING_PREFIX, '') !== '') {
            // An earlier attempt rewrote the dumps for that prefix without
            // recording how far it got.
            One_Click_Migration::write_to_log('Error: Database restore could not resume: an earlier attempt left a staging import without its progress.');
            return false;
        }

        if ($state === null) {
            // Drop leftover staging tables from restores killed mid-import. Trash
            // (ocd…/ocmd…) is kept when live counterparts are missing.
            OCM_SQL_Utils::drop_stale_temp_tables($wpdb, null, $wpdb->base_prefix);

            // Import into a temporary staging prefix first. Live destination tables
            // and site files stay intact until every dump imports cleanly and the
            // options/prefix gate passes; then we promote tables and swap files.
            $staging_prefix = OCM_SQL_Utils::allocate_staging_prefix($wpdb);
            if ($staging_prefix === false || $staging_prefix === '') {
                One_Click_Migration::write_to_log('Error: Database restore could not allocate a staging table prefix.');
                return false;
            }
            if (!OCM_SQL_Utils::assert_staging_identifier_headroom($sorted_table_list, $staging_prefix, $source_prefix)) {
                return false;
            }
            // A worker cut off from here on resumes with this prefix: the dumps
            // are rewritten for it in place.
            $state = array(
                'prefix' => $staging_prefix,
                'source_prefix' => $source_prefix,
                'files' => $dump_names,
                'prepared' => array(),
                'imported' => array(),
                'site_url' => '',
                'unit' => '',
                'attempts' => 0,
            );
            self::save_db_import_state($state);
            update_option(OCM_SQL_Utils::OPTION_STAGING_PREFIX, $staging_prefix, false);
            One_Click_Migration::write_to_log(sprintf(
                'SYSLOG: Importing database into staging prefix "%s"; live tables with prefix "%s" stay intact until import succeeds.',
                $staging_prefix,
                $wpdb->base_prefix
            ));

            $leftover_foreign_options = 0;
            $tables_before_import = $wpdb->get_results('SHOW TABLES');
            foreach ($tables_before_import as $table) {
                foreach ($table as $table_name) {
                    if (substr($table_name, -8) === '_options'
                        && strpos($table_name, $wpdb->base_prefix) !== 0
                        && strpos($table_name, $staging_prefix) !== 0
                    ) {
                        $leftover_foreign_options++;
                    }
                }
            }
            if ($leftover_foreign_options > 0) {
                One_Click_Migration::write_to_log(sprintf(
                    'Notice: Found %d leftover foreign-prefix *_options table(s) on destination; siteurl/home gate will use staging "%soptions" matching source_prefix="%s" only.',
                    $leftover_foreign_options,
                    $staging_prefix,
                    $source_prefix
                ));
            }
        } else {
            $staging_prefix = $state['prefix'];
            // Keep this import's tables; anything else under ocmXXXX_ is stale.
            OCM_SQL_Utils::drop_stale_temp_tables($wpdb, $staging_prefix, $wpdb->base_prefix);
            One_Click_Migration::write_to_log(sprintf(
                'SYSLOG: Resuming database import into staging prefix "%s" (%d of %d dumps rewritten, %d imported).',
                $staging_prefix,
                count($state['prepared']),
                count($dump_names),
                count($state['imported'])
            ));
        }
        // Fatals (including memory exhausted) during staging import / URL rewrite
        // must stop with LOG_RESTORE_STOPPED_IMPORT and never retry the URL step.
        self::register_import_oom_guard();

        $source_suffixes = OCM_SQL_Utils::source_table_suffixes_from_dumps($sorted_table_list, $source_prefix);

        if (!self::check_is_complete_restore_step('db', self::STEP_RESTORE_CHILD_DB_IMPORTED)) {
            // Data swap source → live dest; remap only statement table names to staging
            // so row data keeps {dest}capabilities / {dest}user_roles. Foreign-prefix
            // dumps (ktv_options, nst_options) are also pulled under staging.
            foreach ($sorted_table_list as $filepath) {
                $dump = basename($filepath);
                if (!empty($state['prepared'][$dump])) {
                    // Recorded already; drop the untouched copy a cut-off worker left.
                    self::unlink_if_present($filepath . self::PRISTINE_DUMP_SUFFIX);
                    continue;
                }
                if (!self::begin_db_unit($state, sprintf('rewriting the table prefix in "%s"', $dump))) {
                    self::clear_import_oom_guard();
                    self::discard_staging_import($staging_prefix, $wpdb->base_prefix);
                    return false;
                }
                // Prefix rewrite changes dump size/mtime; keep a stored pass valid only
                // when it still matches the fingerprint from before this rewrite.
                $pre_rewrite_key = self::binary_preflight_fingerprint();
                if (!self::prepare_dump_for_staging_once($filepath, $wpdb->base_prefix, $staging_prefix, $source_prefix)) {
                    One_Click_Migration::write_to_log(sprintf('Error: Database restore failed while remapping the table prefix in "%s".', $dump));
                    self::clear_import_oom_guard();
                    self::discard_staging_import($staging_prefix, $wpdb->base_prefix);
                    return false;
                }
                if (!is_file($filepath) || !is_readable($filepath) || filesize($filepath) === 0) {
                    One_Click_Migration::write_to_log(sprintf('Error: Database restore rejected invalid SQL dump "%s".', $dump));
                    self::clear_import_oom_guard();
                    self::discard_staging_import($staging_prefix, $wpdb->base_prefix);
                    return false;
                }
                $state['prepared'][$dump] = true;
                self::end_db_unit($state);
                self::unlink_if_present($filepath . self::PRISTINE_DUMP_SUFFIX);
                self::rekey_binary_preflight_after_prefix_rewrite($pre_rewrite_key);
                self::maybe_yield_db_phase('table prefix rewrite');
            }

            foreach ($sorted_table_list as $filepath) {
                $dump = basename($filepath);
                if (!empty($state['imported'][$dump])) {
                    continue;
                }
                if (!self::begin_db_unit($state, sprintf('importing "%s"', $dump))) {
                    self::clear_import_oom_guard();
                    self::discard_staging_import($staging_prefix, $wpdb->base_prefix);
                    return false;
                }
                // Import remaps statement heads from source_prefix → staging for any
                // remaining source-prefixed names; foreign tables already use staging.
                if (!OCM_SQL_Utils::import($filepath, $source_prefix, $staging_prefix)) {
                    One_Click_Migration::write_to_log(sprintf('Error: Database restore failed while importing "%s".', $dump));
                    self::clear_import_oom_guard();
                    self::discard_staging_import($staging_prefix, $wpdb->base_prefix);
                    return false;
                }

                // Gate siteurl/home only after the source-prefix options dump imports.
                // Stray *options.sql files (ktv_options, nst_options, multisite
                // {base}{id}_options) must import without this check.
                if ($dump === $source_prefix . 'options.sql') {
                    if (!self::verify_staged_main_options($wpdb, $source_prefix, $staging_prefix, $current_url)) {
                        return self::refuse_options_prefix_gate($staging_prefix, $source_prefix, $wpdb);
                    }
                    $state['site_url'] = (string) self::$restore_site_url;
                }
                $state['imported'][$dump] = true;
                self::end_db_unit($state);
                self::maybe_yield_db_phase('import');
            }

            if (!in_array($source_prefix . 'options.sql', $dump_names, true)) {
                // No source-prefix options dump — still require a resolvable options
                // table with siteurl/home under staging before promoting.
                $table_name = OCM_SQL_Utils::resolve_main_options_table($wpdb, $source_prefix, $staging_prefix);
                if ($table_name === false) {
                    return self::refuse_options_prefix_gate($staging_prefix, $source_prefix, $wpdb);
                }
                $db_url = $wpdb->get_results("SELECT option_value FROM `{$table_name}` WHERE option_name = 'siteurl'");
                if (!empty($db_url) && !empty($db_url[0]->option_value)) {
                    $state['site_url'] = (string) $db_url[0]->option_value;
                    self::save_db_import_state($state);
                }
            }
            self::set_complete_restore_step('db', self::STEP_RESTORE_CHILD_DB_IMPORTED);
        }
        self::$restore_site_url = isset($state['site_url']) ? (string) $state['site_url'] : '';

        if (!self::check_is_complete_restore_step('db', self::STEP_RESTORE_CHILD_DB_URLS)) {
            // URL rewrite on temp-prefixed staging tables only — before atomic RENAME
            // and before any file swap. Failure / OOM leaves the live site untouched.
            if (!self::rewrite_urls_on_staging_tables($staging_prefix)) {
                self::clear_import_oom_guard();
                if (self::$url_rewrite_unpageable_table) {
                    // Drop staging quietly so the dedicated Error stays immediately
                    // before LOG_RESTORE_STOPPED_IMPORT (no discard Error in between).
                    OCM_SQL_Utils::drop_tables_with_prefix($staging_prefix);
                    delete_option(OCM_SQL_Utils::OPTION_STAGING_PREFIX);
                    self::clear_db_import_state();
                } else {
                    self::discard_staging_import($staging_prefix, $wpdb->base_prefix);
                }
                return false;
            }
            self::set_complete_restore_step('db', self::STEP_RESTORE_CHILD_DB_URLS);
            // Promote and the file swaps after it start with a full time budget.
            self::maybe_yield_db_phase('URL update');
        }

        if (!self::check_is_complete_restore_step('db', self::STEP_RESTORE_CHILD_DB_PROMOTED)) {
            $state = self::db_import_state();
            $was_promoting = !empty($state['promoting']);
            if ($was_promoting && !self::staging_tables_exist($wpdb, $staging_prefix)) {
                // The RENAME went through and the worker was cut off after it.
                One_Click_Migration::write_to_log(sprintf(
                    'SYSLOG: Staging prefix "%s" was already promoted by an earlier worker.',
                    $staging_prefix
                ));
            } else {
                // Saved before the RENAME, so the promoted options table (which
                // keeps this site's restore_steps) carries the marker.
                $state['promoting'] = true;
                if (!self::begin_db_unit($state, 'promoting the staging tables')) {
                    self::clear_import_oom_guard();
                    self::discard_staging_import($staging_prefix, $wpdb->base_prefix);
                    return false;
                }
                $review_options = self::snapshot_destination_review_options($wpdb);
                if (!OCM_SQL_Utils::promote_staging_tables($staging_prefix, $wpdb->base_prefix, $source_suffixes)) {
                    One_Click_Migration::write_to_log(sprintf(
                        'Error: Database import failed while promoting staging prefix "%s" to live prefix "%s"; discarding staging tables and leaving any recoverable live tables in place.',
                        $staging_prefix,
                        $wpdb->base_prefix
                    ));
                    self::clear_import_oom_guard();
                    self::discard_staging_import($staging_prefix, $wpdb->base_prefix);
                    return false;
                }

                self::clear_import_oom_guard();

                // Destination review prefs were snapshotted from the live options table
                // before promote; write them onto the newly promoted options table.
                self::restore_destination_review_options($wpdb, $review_options);
            }
            self::set_complete_restore_step('db', self::STEP_RESTORE_CHILD_DB_PROMOTED);
        }

        self::clear_import_oom_guard();

        // The source's queue rows and step checkpoints never reach the live
        // table: promote swaps them for this site's own (keep_destination_plugin_state).
        // Deleting queue rows here would drop this restore's own queue item and
        // restore_steps, and a worker restarted after promote could not resume.

        One_Click_Migration::write_to_log('Database has been restored');
        return true;
    }

    /**
     * In-flight database import of this restore (restore_steps db/dbImport):
     * staging prefix, dumps rewritten and imported so far, the source siteurl
     * read at the options gate and the URL-rewrite position. Null when no
     * import has started.
     *
     * @return array|null
     */
    private static function db_import_state()
    {
        $state = self::get_restore_step_value('db', self::STEP_RESTORE_CHILD_DB_IMPORT);
        if (!is_array($state) || empty($state['prefix']) || !is_string($state['prefix'])) {
            return null;
        }
        return $state + array(
            'source_prefix' => '',
            'files' => array(),
            'prepared' => array(),
            'imported' => array(),
            'site_url' => '',
            'unit' => '',
            'attempts' => 0,
        );
    }

    private static function save_db_import_state(array $state)
    {
        self::set_complete_restore_step('db', self::STEP_RESTORE_CHILD_DB_IMPORT, $state);
    }

    /**
     * Forget the in-flight import: its staging tables were dropped.
     *
     * @return void
     */
    private static function clear_db_import_state()
    {
        $steps = get_option('restore_steps', null);
        if (is_array($steps) && isset($steps['db']) && is_array($steps['db'])
            && (!empty($steps['db'][self::STEP_RESTORE_CHILD_DB_IMPORT])
                || !empty($steps['db'][self::STEP_RESTORE_CHILD_DB_IMPORTED])
                || !empty($steps['db'][self::STEP_RESTORE_CHILD_DB_URLS])
                || !empty($steps['db'][self::STEP_RESTORE_CHILD_DB_PROMOTED]))
        ) {
            $steps['db'][self::STEP_RESTORE_CHILD_DB_IMPORT] = null;
            $steps['db'][self::STEP_RESTORE_CHILD_DB_IMPORTED] = false;
            $steps['db'][self::STEP_RESTORE_CHILD_DB_URLS] = false;
            $steps['db'][self::STEP_RESTORE_CHILD_DB_PROMOTED] = false;
            update_option('restore_steps', $steps, true);
        }
        delete_option(self::OPTION_RESTORE_URL_HOSTS);
    }

    /**
     * May this worker continue the saved import? The dumps, the source prefix
     * and (until promote) the stored staging prefix must all still match.
     *
     * @param array    $state
     * @param string   $source_prefix
     * @param string[] $dump_names
     * @return bool
     */
    private static function db_import_state_matches(array $state, $source_prefix, array $dump_names)
    {
        if ($state['source_prefix'] !== $source_prefix || array_values((array) $state['files']) !== $dump_names) {
            return false;
        }
        return !empty($state['promoting'])
            || (string) get_option(OCM_SQL_Utils::OPTION_STAGING_PREFIX, '') === $state['prefix'];
    }

    /**
     * Record that this worker starts $unit of the import. Starting the same
     * unit again without having finished it means the worker before was cut
     * off in it (PHP-FPM request_terminate_timeout sends SIGTERM, which skips
     * shutdown functions). After MAX_DB_UNIT_ATTEMPTS of those the restore
     * stops instead of looping.
     *
     * @param array  $state
     * @param string $unit  Human-readable step, e.g. 'importing "wp_posts.sql"'.
     * @return bool False when the unit was cut off too often.
     */
    private static function begin_db_unit(array &$state, $unit)
    {
        $attempts = (isset($state['unit']) && $state['unit'] === $unit)
            ? (int) $state['attempts'] + 1
            : 1;
        if ($attempts > self::MAX_DB_UNIT_ATTEMPTS) {
            One_Click_Migration::write_to_log(sprintf(
                'Error: Database restore was cut off %d times in a row while %s. The server stops PHP before this step can finish; raise its PHP time limit (max_execution_time, PHP-FPM request_terminate_timeout) and run the restore again.',
                $attempts - 1,
                $unit
            ));
            return false;
        }
        $state['unit'] = $unit;
        $state['attempts'] = $attempts;
        self::save_db_import_state($state);
        return true;
    }

    /**
     * The unit begin_db_unit() recorded has finished; save it with $state.
     *
     * @param array $state
     * @return void
     */
    private static function end_db_unit(array &$state)
    {
        $state['unit'] = '';
        $state['attempts'] = 0;
        self::save_db_import_state($state);
        self::$db_phase_progress = true;
    }

    /**
     * True once this restore worker has used up its time budget.
     *
     * @return bool
     */
    private static function restore_worker_out_of_time()
    {
        $process = One_Click_Migration::$process_restore_single;
        return is_object($process) && method_exists($process, 'time_exceeded') && $process->time_exceeded();
    }

    /**
     * After a finished unit: when the worker is out of time, hand the rest of
     * the DB phase to a new worker. Progress is saved, and the queue item stays
     * queued, so the next worker re-enters restore_db() at the checkpoint.
     *
     * @param string $stage For the log.
     * @return void
     */
    private static function maybe_yield_db_phase($stage)
    {
        if (self::$db_phase_progress && self::restore_worker_out_of_time()) {
            self::yield_db_phase($stage);
        }
    }

    /**
     * @param string $stage For the log.
     * @return never
     */
    private static function yield_db_phase($stage)
    {
        self::clear_import_oom_guard();
        One_Click_Migration::write_to_log(sprintf('SYSLOG: Database restore: %s continues in a new worker.', $stage));
        One_Click_Migration::write_to_log('Process is Restarting');
        One_Click_Migration::$process_restore_single->restart_task();
        exit;
    }

    /**
     * Rewrite one extracted dump for the staging prefix. The untouched dump is
     * kept (hard link, else a copy) until the caller records the rewrite: every
     * rewrite pass replaces the file via a temp file and rename(), so the link
     * still points at the original bytes. A worker cut off halfway leaves that
     * copy behind, and the next one starts again from it instead of rewriting a
     * half-rewritten dump a second time.
     *
     * @param string $filepath
     * @param string $dest_prefix
     * @param string $staging_prefix
     * @param string $source_prefix
     * @return bool
     */
    private static function prepare_dump_for_staging_once($filepath, $dest_prefix, $staging_prefix, $source_prefix)
    {
        $pristine = $filepath . self::PRISTINE_DUMP_SUFFIX;
        self::unlink_if_present($pristine . '.tmp');
        if (is_file($pristine)) {
            if (!@rename($pristine, $filepath)) {
                One_Click_Migration::write_to_log(sprintf(
                    'Error: Could not put back the untouched copy of "%s" after an interrupted table prefix rewrite.',
                    basename($filepath)
                ));
                return false;
            }
            One_Click_Migration::write_to_log(sprintf(
                'SYSLOG: Restarting the table prefix rewrite of "%s" from its untouched copy.',
                basename($filepath)
            ));
        }
        $kept = function_exists('link') && @link($filepath, $pristine);
        if (!$kept) {
            $kept = @copy($filepath, $pristine . '.tmp') && @rename($pristine . '.tmp', $pristine);
        }
        if (!$kept) {
            self::unlink_if_present($pristine . '.tmp');
            One_Click_Migration::write_to_log(sprintf(
                'Error: Could not keep an untouched copy of "%s" while rewriting its table prefix. Please check disk quota and write permissions.',
                basename($filepath)
            ));
            return false;
        }
        return OCM_SQL_Utils::prepare_sql_dump_for_staging($filepath, $dest_prefix, $staging_prefix, $source_prefix);
    }

    /**
     * Delete a work file that is often not there. The error handler logs
     * @-suppressed warnings too, so a bare @unlink() of a missing file adds a
     * SYSLOG "[PHP ERR][WARN]" line to the restore log.
     *
     * @param string $path
     * @return void
     */
    private static function unlink_if_present($path)
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }

    /**
     * Staging table a prepared dump creates: source- or destination-prefixed
     * names keep their suffix under the staging prefix, foreign tables are
     * nested under it (see OCM_SQL_Utils::prepare_sql_dump_for_staging()).
     *
     * @param string $filepath
     * @param string $source_prefix
     * @param string $dest_prefix
     * @param string $staging_prefix
     * @return string
     */
    private static function staged_table_for_dump($filepath, $source_prefix, $dest_prefix, $staging_prefix)
    {
        $table = preg_replace('/\.sql$/i', '', basename((string) $filepath));
        foreach (array($source_prefix, $dest_prefix) as $prefix) {
            if (is_string($prefix) && $prefix !== '' && strpos($table, $prefix) === 0) {
                return $staging_prefix . substr($table, strlen($prefix));
            }
        }
        return $staging_prefix . $table;
    }

    /**
     * @param wpdb   $wpdb
     * @param string $prefix
     * @return bool
     */
    private static function staging_tables_exist(wpdb $wpdb, $prefix)
    {
        $tables = $wpdb->get_col('SHOW TABLES');
        foreach (is_array($tables) ? $tables : array() as $table) {
            if (strpos((string) $table, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Options gate on the imported main options table: siteurl and home must be
     * there. Remembers the source siteurl for the URL rewrite and points
     * siteurl/home at this site.
     *
     * @return bool False when the gate refuses.
     */
    private static function verify_staged_main_options(wpdb $wpdb, $source_prefix, $staging_prefix, $current_url)
    {
        $table_name = OCM_SQL_Utils::resolve_main_options_table($wpdb, $source_prefix, $staging_prefix);
        if ($table_name === false) {
            return false;
        }

        $db_url = $wpdb->get_results("SELECT option_value FROM `{$table_name}` WHERE option_name = 'siteurl'");
        self::$restore_site_url = $db_url[0]->option_value;

        One_Click_Migration::write_to_log(sprintf(
            'Main options verified on staging table "%s" (source_prefix="%s" staging_prefix="%s" dest_base_prefix="%s" dest_prefix="%s").',
            $table_name,
            $source_prefix,
            $staging_prefix,
            $wpdb->base_prefix,
            $wpdb->prefix
        ));

        self::point_staged_options_at_destination($wpdb, $table_name, $current_url);
        return true;
    }

    private static function point_staged_options_at_destination(wpdb $wpdb, $table_name, $current_url)
    {
        if ($current_url === self::$restore_site_url) {
            return;
        }
        $wpdb->update(
            $table_name,
            array('option_value' => $current_url),
            array('option_name' => 'siteurl'),
            array('%s'),
            array('%s')
        );

        $wpdb->update(
            $table_name,
            array('option_value' => $current_url),
            array('option_name' => 'home'),
            array('%s'),
            array('%s')
        );
    }

    /**
     * Re-import the dump behind one staging table. A worker cut off while
     * rewriting that table's URLs leaves rows after its last checkpoint
     * possibly rewritten, and a second pass is not always safe (a new address
     * can contain the old one), so the table starts over from its dump.
     *
     * @param string $table
     * @param array  $state
     * @return bool
     */
    private static function reimport_staging_table($table, array $state)
    {
        global $wpdb;
        $dbfiles = self::get_restore_work_path() . '/db/';
        foreach (OCM_SQL_Utils::list_restore_sql_dump_files($dbfiles) as $filepath) {
            if (self::staged_table_for_dump($filepath, $state['source_prefix'], $wpdb->base_prefix, $state['prefix']) !== $table) {
                continue;
            }
            $wpdb->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
            if (!OCM_SQL_Utils::import($filepath, $state['source_prefix'], $state['prefix'])) {
                One_Click_Migration::write_to_log(sprintf(
                    'Error: Database restore failed while importing "%s" again after an interrupted URL update.',
                    basename($filepath)
                ));
                return false;
            }
            if (basename($filepath) === $state['source_prefix'] . 'options.sql') {
                self::point_staged_options_at_destination($wpdb, $table, site_url());
            }
            One_Click_Migration::write_to_log(sprintf(
                'SYSLOG: Imported "%s" again: a worker was cut off while updating its URLs.',
                basename($filepath)
            ));
            return true;
        }
        One_Click_Migration::write_to_log(sprintf(
            'Error: Database restore could not find the dump behind staging table "%s" to import it again after an interrupted URL update.',
            $table
        ));
        return false;
    }

    /**
     * Run URL search-replace against staging-prefixed tables only. Must complete
     * before promote_staging_tables() / file swap. Returns false on rewrite errors
     * so the caller can discard staging and stop with LOG_RESTORE_STOPPED_IMPORT.
     *
     * Inside restore_db() the rewrite checkpoints after every table and when
     * the worker runs out of time mid-table (keyset position), then hands over
     * to a new worker. A table a worker was cut off in is imported again.
     *
     * @param string $staging_prefix
     * @return bool
     */
    private static function rewrite_urls_on_staging_tables($staging_prefix)
    {
        self::$url_rewrite_unpageable_table = null;
        $old_url = self::$restore_site_url;
        if (empty($old_url) || !is_string($staging_prefix) || $staging_prefix === '') {
            return true;
        }
        $current_url = site_url();
        $url_plan = get_option(OCM_Restore_Precheck::OPTION, array());
        if (!is_array($url_plan)) {
            $url_plan = array();
        }
        $extra_arguments = OCM_Restore_Precheck::replacement_args($url_plan, $old_url, $current_url);

        // Checkpoints exist only for restore_db()'s own staging import.
        $state = self::db_import_state();
        if ($state !== null && $state['prefix'] !== $staging_prefix) {
            $state = null;
        }
        $progress = ($state !== null && isset($state['url']) && is_array($state['url'])) ? $state['url'] : array();
        $progress += array(
            'done' => array(),
            'table' => null,
            'last_key' => null,
            'clean' => true,
            'updates' => 0,
            'errors' => array(),
            'error_count' => 0,
            'failed_pairs' => array(),
        );
        $started = $progress['done'] || $progress['table'] !== null;

        $db_details = new OCM_DB();
        $collect = empty($url_plan['reviewed']);
        if ($collect) {
            $found = $started ? get_option(self::OPTION_RESTORE_URL_HOSTS, array()) : array();
            $db_details->enable_url_collection(is_array($found) ? $found : array());
        }
        $all_tables = OCM_DB::get_tables();
        if (!is_array($all_tables)) {
            $all_tables = array();
        }
        $staging_tables = array();
        foreach ($all_tables as $table) {
            if (is_string($table) && strpos($table, $staging_prefix) === 0) {
                $staging_tables[] = $table;
            }
        }
        if (!$staging_tables) {
            return true;
        }
        // Finish the table the last worker was in before any other: its
        // checkpoint (or the mark that it was cut off) is only kept until the
        // next table starts. SHOW TABLES order can change when it is re-imported.
        $in_progress = array_search($progress['table'], $staging_tables, true);
        if ($in_progress !== false) {
            unset($staging_tables[$in_progress]);
            array_unshift($staging_tables, $progress['table']);
        } elseif ($progress['table'] !== null) {
            One_Click_Migration::write_to_log(sprintf(
                'Error: Database restore could not resume the URL update: staging table "%s" is gone.',
                $progress['table']
            ));
            return false;
        }

        $save_progress = static function (array $progress) use (&$state, $collect, $db_details) {
            $state['url'] = $progress;
            if ($collect) {
                update_option(self::OPTION_RESTORE_URL_HOSTS, $db_details->collected_urls(), false);
            }
        };
        $should_yield = $state !== null
            ? static function () {
                return self::restore_worker_out_of_time();
            }
            : null;

        $remaining = count($staging_tables) - count(array_intersect_key(array_flip($staging_tables), $progress['done']));
        foreach ($staging_tables as $table) {
            if (!empty($progress['done'][$table])) {
                continue;
            }
            $resume_key = null;
            if ($progress['table'] === $table) {
                if (empty($progress['clean'])) {
                    if (!self::reimport_staging_table($table, $state)) {
                        return false;
                    }
                } else {
                    $resume_key = is_array($progress['last_key']) ? $progress['last_key'] : null;
                }
            }
            if ($state !== null) {
                $progress['table'] = $table;
                $progress['last_key'] = $resume_key;
                $progress['clean'] = false;
                $state['url'] = $progress;
                if (!self::begin_db_unit($state, sprintf('updating URLs in "%s"', $table))) {
                    return false;
                }
            }

            $table_result = $db_details->search_replace_db(array($table), $extra_arguments + array(
                'continuing' => $started,
                'log_summary' => false,
                'resume_key' => $resume_key,
                'should_yield' => $should_yield,
            ));
            $started = true;
            // Dedicated stop (keyless unpageable or oversized row): Error was already
            // logged; return before Finished/Number SYSLOG so that Error sits
            // immediately before LOG_RESTORE_STOPPED_IMPORT.
            if (!empty($table_result['unpageable_table'])) {
                self::$url_rewrite_unpageable_table = (string) $table_result['unpageable_table'];
                return false;
            }
            if (!empty($table_result['oversized_table'])) {
                self::$url_rewrite_unpageable_table = (string) $table_result['oversized_table'];
                return false;
            }
            if (!empty($table_result['paging_stuck_table'])) {
                self::$url_rewrite_unpageable_table = (string) $table_result['paging_stuck_table'];
                return false;
            }

            $progress['updates'] += isset($table_result['updates']) ? (int) $table_result['updates'] : 0;
            $table_errors = isset($table_result['errors']) && is_array($table_result['errors']) ? $table_result['errors'] : array();
            $progress['error_count'] += count($table_errors);
            // Only the count is logged; keep a few messages, not one per row.
            $progress['errors'] = array_slice(array_merge($progress['errors'], $table_errors), 0, 20);
            if (isset($table_result['failed_pairs']) && is_array($table_result['failed_pairs'])) {
                foreach ($table_result['failed_pairs'] as $pair) {
                    if (is_array($pair) && isset($pair['search']) && !isset($progress['failed_pairs'][$pair['search']])) {
                        $progress['failed_pairs'][$pair['search']] = $pair;
                    }
                }
            }

            if (!empty($table_result['yielded']) && $state !== null) {
                $progress['last_key'] = isset($table_result['cursor']['last_key']) ? $table_result['cursor']['last_key'] : null;
                $progress['clean'] = true;
                $save_progress($progress);
                self::end_db_unit($state);
                self::yield_db_phase('URL update');
            }

            $progress['done'][$table] = true;
            $progress['table'] = null;
            $progress['last_key'] = null;
            $progress['clean'] = true;
            $remaining--;
            if ($state !== null) {
                $save_progress($progress);
                self::end_db_unit($state);
                if ($remaining > 0) {
                    self::maybe_yield_db_phase('URL update');
                }
            }
        }

        // Logging the summary and the extra-domain candidates can take a while
        // (every log line is uploaded); count cut-offs here too.
        if ($state !== null && !self::begin_db_unit($state, 'finishing the URL update')) {
            return false;
        }
        $result = array(
            'updates' => $progress['updates'],
            'errors' => $progress['errors'],
            'failed_pairs' => array_values($progress['failed_pairs']),
        );
        One_Click_Migration::write_to_log(sprintf(
            'SYSLOG: URL update: peak_bytes=%d memory_limit=%s',
            (int) memory_get_peak_usage(true),
            (string) ini_get('memory_limit')
        ));
        $report_update = isset($result['updates']) ? $result['updates'] : 0;
        One_Click_Migration::write_to_log(sprintf('SYSLOG: Number of updates made from replace "%s"', $report_update));
        One_Click_Migration::write_to_log('SYSLOG: Finished updating URLs in the database');
        if (!empty($result['errors'])) {
            One_Click_Migration::write_to_log(sprintf(
                'Error: Database restore failed while updating URLs on staging tables (%d).',
                (int) $progress['error_count']
            ));
            $failed_pairs = isset($result['failed_pairs']) && is_array($result['failed_pairs'])
                ? $result['failed_pairs']
                : array();
            self::log_failed_address_pairs($failed_pairs);
            return false;
        }
        // Only after a clean rewrite: candidates make the admin page open the
        // restore-success modal, which must never follow a stopped restore.
        if ($collect) {
            self::store_extra_domain_candidates($db_details->collected_urls(), $old_url, $current_url);
            delete_option(self::OPTION_RESTORE_URL_HOSTS);
        }
        if (!empty($url_plan['reviewed'])) {
            delete_option(OCM_Extra_Domains::OPTION);
            delete_option('ocm_restore_partial_address_updates');
            delete_option('ocm_restore_failed_address_pairs');
        }
        return true;
    }

    /**
     * Options/prefix gate failure: write the dedicated stop line, discard staging,
     * leave live tables/files/plugins unchanged.
     *
     * @param string $staging_prefix
     * @param string $source_prefix
     * @param wpdb   $wpdb
     * @return false
     */
    private static function refuse_options_prefix_gate($staging_prefix, $source_prefix, wpdb $wpdb)
    {
        self::$db_restore_refused_options = true;
        self::clear_import_oom_guard();
        One_Click_Migration::write_to_log(sprintf(
            'Error: Database restore failed: main options table "%soptions" is incomplete or wrong-prefix (missing siteurl and/or home). source_prefix="%s" staging_prefix="%s" dest_base_prefix="%s" dest_prefix="%s".',
            $staging_prefix,
            $source_prefix,
            $staging_prefix,
            $wpdb->base_prefix,
            $wpdb->prefix
        ));
        self::discard_staging_import($staging_prefix, $wpdb->base_prefix);
        One_Click_Migration::write_to_log(self::LOG_RESTORE_STOPPED_OPTIONS);
        return false;
    }

    /**
     * Drop an in-flight staging import and leave live destination tables alone.
     *
     * @param string $staging_prefix
     * @param string $dest_prefix
     * @return void
     */
    private static function discard_staging_import($staging_prefix, $dest_prefix)
    {
        One_Click_Migration::write_to_log(sprintf(
            'Error: Database import failed; discarding staging prefix "%s" and leaving live tables with prefix "%s" unchanged.',
            $staging_prefix,
            $dest_prefix
        ));
        OCM_SQL_Utils::drop_tables_with_prefix($staging_prefix);
        delete_option(OCM_SQL_Utils::OPTION_STAGING_PREFIX);
        self::clear_db_import_state();
    }

    private static function snapshot_destination_review_options(wpdb $wpdb)
    {
        $table = $wpdb->base_prefix . 'options';
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value, autoload FROM {$table} WHERE option_name IN (%s, %s)",
                'ocm_review_ask_dismissed',
                'ocm_clean_restore_count'
            ),
            ARRAY_A
        );
        return is_array($rows) ? $rows : array();
    }

    private static function restore_destination_review_options(wpdb $wpdb, $rows)
    {
        if (!is_array($rows)) {
            return;
        }

        $table = $wpdb->base_prefix . 'options';
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['option_name'], $row['option_value'], $row['autoload'])) {
                continue;
            }
            $wpdb->replace(
                $table,
                array(
                    'option_name' => $row['option_name'],
                    'option_value' => $row['option_value'],
                    'autoload' => $row['autoload'],
                ),
                array('%s', '%s', '%s')
            );
            wp_cache_delete($row['option_name'], 'options');
        }
    }

    /**
     * @param $presigned_urls
     */
    private static function restore_plugins($presigned_urls)
    {
        // Handle plugin extraction. GLOB_ONLYDIR so loose files like the WP placeholder
        // index.php aren't treated as plugin directories.
        $plugins = glob(WP_CONTENT_DIR . '/plugins/*', GLOB_ONLYDIR) ?: array();

        // Loop through the list and delete every plugin directory except 1-click-migration
        foreach ($plugins as $plugin) {
            if ($plugin === WP_CONTENT_DIR . '/plugins/1-click-migration') {
                continue;
            }

            if (!self::deleteDirectoryBestEffort($plugin) && is_dir($plugin)) {
                One_Click_Migration::write_to_log(sprintf(
                    'Notice: Could not fully remove plugin directory "%s"; restored plugin files will be merged over it.',
                    $plugin
                ));
            }
        }

        // Move every plugin from the restore directory one by one in to the existing plugins directory.
        // GLOB_ONLYDIR so the WP-generated index.php placeholder isn't treated as a plugin directory.
        $restore_plugins_dir = self::get_restore_work_path() . '/plugins';
        $restore_plugins = glob($restore_plugins_dir . '/*', GLOB_ONLYDIR) ?: array();
        foreach ($restore_plugins as $plugin) {
            if ($plugin === $restore_plugins_dir . '/1-click-migration') {
                continue;
            }

            $directory_path_split = explode('/', $plugin);
            $plugin_name = $directory_path_split[count($directory_path_split) - 1];
            $pluginDirectoryName = WP_CONTENT_DIR . '/plugins/' . $plugin_name;

            if (!self::replaceDirectoryFromExtract($plugin, $pluginDirectoryName, 'plugin ' . $plugin_name)) {
                One_Click_Migration::write_to_log(sprintf(
                    'Restore plugins. Error: "%s" directory could not be moved to "%s" directory. Please check the write permission for these directories.',
                    $plugin, $pluginDirectoryName
                ));
                die();
            }
        }

        One_Click_Migration::write_to_log('Plugins have been restored');
    }

    public static function add_file_not_found_notice(){
      $file = OCM_DEBUG_LOG_FILE;
      $lines = [];

    }
    private static function substring_in_array($needle, array $haystack)
    {
      $filtered = array_filter($haystack, function ($item) use ($needle) {
          return false !== strpos($item, $needle);
      });

      return !empty($filtered);
    }

    /**
     * Rewrite only the extra hosts an administrator checked on the restore-complete screen.
     * An empty selection records the decision and rewrites nothing.
     */
    public static function apply_extra_domains()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Only a site administrator can do this.', '1-click-migration')), 403);
        }
        check_ajax_referer('ocm_apply_extra_domains');

        $stored = get_option(OCM_Extra_Domains::OPTION, array());
        if (!is_array($stored) || !$stored) {
            wp_send_json_success(array('updates' => 0));
        }

        $raw_ids = array();
        if (isset($_POST['ids'])) {
            $raw_ids = wp_unslash($_POST['ids']);
            if (!is_array($raw_ids)) {
                $raw_ids = explode(',', (string) $raw_ids);
            }
        }
        $wanted = array();
        foreach ($raw_ids as $id) {
            $id = sanitize_text_field($id);
            if ('' !== $id) {
                $wanted[$id] = true;
            }
        }

        $selected = array();
        foreach ($stored as $row) {
            if (is_array($row) && isset($row['id']) && isset($wanted[$row['id']])) {
                $selected[] = $row;
            }
        }

        $updates = 0;
        if ($selected) {
            $pairs = array();
            foreach ($selected as $row) {
                $pairs = array_merge($pairs, OCM_Extra_Domains::replacement_pairs($row, site_url()));
            }
            if ($pairs) {
                One_Click_Migration::enable_maintenance_mode();
                $maintenance_on = true;
                register_shutdown_function(function () use (&$maintenance_on) {
                    if ($maintenance_on) {
                        One_Click_Migration::disable_maintenance_mode();
                        $maintenance_on = false;
                    }
                });
                try {
                    @set_time_limit(One_Click_Migration::get_timeout());
                    $db = new OCM_DB();
                    $result = $db->search_replace_db($db->get_tables(), array('pairs' => $pairs));
                    $updates = isset($result['updates']) ? (int) $result['updates'] : 0;
                } finally {
                    One_Click_Migration::disable_maintenance_mode();
                    $maintenance_on = false;
                }
                if (!empty($result['errors'])) {
                    One_Click_Migration::write_to_log(sprintf('Error: Extra-domain rewrite failed for %d rows.', count($result['errors'])));
                    wp_send_json_error(array(
                        'message' => __('Some addresses could not be updated. Please retry.', '1-click-migration'),
                        'updates' => $updates,
                    ), 500);
                    return;
                }
            }
        }

        delete_option(OCM_Extra_Domains::OPTION);
        One_Click_Migration::write_to_log(sprintf('SYSLOG: Extra-domain rewrite updates "%d"', $updates));
        wp_send_json_success(array('updates' => $updates));
    }

    /**
     * Hosts were collected during the primary rewrite, so this does not scan the database again.
     *
     * @param array  $found
     * @param string $source_siteurl
     * @param string $dest_siteurl
     */
    private static function store_extra_domain_candidates($found, $source_siteurl, $dest_siteurl)
    {
        $report = OCM_Extra_Domains::finalize($found, $source_siteurl, $dest_siteurl);
        $candidates = isset($report['candidates']) && is_array($report['candidates']) ? $report['candidates'] : array();
        if (!$candidates) {
            delete_option(OCM_Extra_Domains::OPTION);
            One_Click_Migration::write_to_log('SYSLOG: Extra domains awaiting confirmation "0"');
            return;
        }

        update_option(OCM_Extra_Domains::OPTION, $candidates, false);
        One_Click_Migration::write_to_log(sprintf('SYSLOG: Extra domains awaiting confirmation "%d"', count($candidates)));
        if (!empty($report['omitted'])) {
            One_Click_Migration::write_to_log(sprintf('SYSLOG: Extra domains omitted from the checklist "%d"', (int) $report['omitted']));
        }
        foreach ($candidates as $candidate) {
            One_Click_Migration::write_to_log(sprintf(
                'SYSLOG: Extra domain host "%s" class "%s" count "%d" example "%s"',
                $candidate['host'],
                $candidate['class'],
                $candidate['count'],
                $candidate['example']
            ));
        }
    }


    public static function delete_restore_options(){
      delete_option('ocm_force_deactivated_plugins');
      delete_option(self::RESTORE_CRASH_OPTION);
      delete_option(self::RESTORE_RESUME_OPTION);

      delete_option('ocm_restore_download_retry_db');
      delete_option('ocm_restore_download_retry_themes');
      delete_option('ocm_restore_download_retry_plugins');
      delete_option('ocm_restore_download_retry_uploads');

      delete_option('ocm_restore_decrypt_retry_db');
      delete_option('ocm_restore_decrypt_retry_themes');
      delete_option('ocm_restore_decrypt_retry_plugins');
      delete_option('ocm_restore_decrypt_retry_uploads');

      delete_option('ocm_restore_extract_retry_db');
      delete_option('ocm_restore_extract_retry_themes');
      delete_option('ocm_restore_extract_retry_plugins');
      delete_option('ocm_restore_extract_retry_uploads');

      delete_option('ocm_restore_child_delete_retry_db');
      delete_option('ocm_restore_child_delete_retry_themes');
      delete_option('ocm_restore_child_delete_retry_plugins');
      delete_option('ocm_restore_child_delete_retry_uploads');
      delete_option('ocm_excluded_folders');
      delete_option('ocm_skipped_folders');
      delete_option('ocm_eexcluded_folders');
      delete_option('ocm_backup_missing_parts');
      delete_option('ocm_backup_excluded_parts');
      delete_option('ocm_backup_parts_known');
      delete_option(self::OPTION_RESTORE_DB_PART_FAILED);
      delete_option(self::OPTION_BINARY_PREFLIGHT);
      delete_option(self::OPTION_RESTORE_PART_WARNINGS);
      delete_option(self::OPTION_RESTORE_PLUGINS_ISOLATED);
      delete_option(self::OPTION_RESTORE_WAS_WARNED);
      delete_option(self::OPTION_RESTORE_URL_HOSTS);
      // A run abandoned mid-import left its prefix; this run's dumps are new.
      delete_option(OCM_SQL_Utils::OPTION_STAGING_PREFIX);
      delete_option('ocm_restore_was_partial');
      // A new run's log must show its own PHP errors, not skip ones the
      // previous run already logged.
      delete_option('ocm_error_log_dedupe');
      delete_option('ocm_restore_partial_address_updates');
      delete_option('ocm_restore_failed_address_pairs');
      delete_option(OCM_SQL_Utils::OPTION_BACKUP_PLUGIN_VERSION);
      delete_option(OCM_Extra_Domains::OPTION);
      delete_option(OCM_Restore_Precheck::OPTION);
      delete_option(self::RESTORE_RUN_OPTION);

    }

    public static function delete_backup_options(){
      // A zip left running by the previous backup would keep writing into its
      // work dir; stop it before this run starts.
      self::stop_zip_failover_job();
      delete_option(self::BACKUP_CRASH_OPTION);
      delete_option(self::BACKUP_RESUME_OPTION);
      // Clean up transient backup state from previous runs.
      delete_option('ocm_force_deactivated_plugins');
      delete_option('ocm_error_log_dedupe');

      delete_option('ocm_backup_compress_retry_db');
      delete_option('ocm_backup_compress_retry_themes');
      delete_option('ocm_backup_compress_retry_plugins');
      delete_option('ocm_backup_compress_retry_uploads');

      delete_option('ocm_backup_encrypt_retry_db');
      delete_option('ocm_backup_encrypt_retry_themes');
      delete_option('ocm_backup_encrypt_retry_plugins');
      delete_option('ocm_backup_encrypt_retry_uploads');

      delete_option('ocm_backup_upload_retry_db');
      delete_option('ocm_backup_upload_retry_themes');
      delete_option('ocm_backup_upload_retry_plugins');
      delete_option('ocm_backup_upload_retry_uploads');
      delete_option('ocm_multipart_upload_db');
      delete_option('ocm_multipart_upload_themes');
      delete_option('ocm_multipart_upload_plugins');
      delete_option('ocm_multipart_upload_uploads');
      delete_option('ocm_excluded_folders');
      delete_option('ocm_skipped_folders');
      delete_option('ocm_eexcluded_folders');
      delete_option('ocm_restore_code_plain');
      delete_option(self::BACKUP_UPLOADED_PARTS_OPTION);


    }

    public static function capture_failed_folder($excluded_restore_files, $key, $option){
      $backup_active = is_array(get_option(self::BACKUP_RUN_OPTION, array()))
          && !empty(get_option(self::BACKUP_RUN_OPTION, array())['id']);
      if ('ocm_skipped_folders' === $option && $backup_active && !self::backup_context_owns_current_run()) {
          return;
      }

      if ('ocm_skipped_folders' === $option && self::was_backup_part_uploaded($key)) {
          return;
      }

      // Always merge with current storage. Callers often retain an array across
      // long compression/upload operations; writing that stale snapshot can
      // erase another part's status.
      $current = get_option($option, array());
      $current = is_array($current) ? $current : array();
      $excluded_restore_files = is_array($excluded_restore_files) ? $excluded_restore_files : array();
      $excluded_restore_files = array_merge($current, $excluded_restore_files, array($key));
      update_option($option, array_values(array_unique($excluded_restore_files)));
    }

    /** @var array<int, string> Parts for which download_restore_file was invoked (tests). */
    public static $restore_download_requests = array();

    /**
     * Merge backup-log =excluded parts into ocm_excluded_folders so downloads,
     * DB import, and URL rewrite follow the same path as a selective exclude
     * in 3.1.18 (files-only: complete_restore, no search-replace).
     *
     * @return array Updated ocm_excluded_folders
     */
    public static function apply_backup_excluded_parts_to_restore_exclusions()
    {
        $backup_excluded = get_option('ocm_backup_excluded_parts', array());
        $backup_excluded = is_array($backup_excluded) ? $backup_excluded : array();
        if ($backup_excluded === array()) {
            $current = get_option('ocm_excluded_folders', array());
            return is_array($current) ? $current : array();
        }
        $current = get_option('ocm_excluded_folders', array());
        $current = is_array($current) ? $current : array();
        $merged = array_values(array_unique(array_merge($current, $backup_excluded)));
        update_option('ocm_excluded_folders', $merged, true);
        return $merged;
    }

    /**
     * If the backup log marked this part =excluded, never request/download it.
     * Records it on ocm_excluded_folders and marks restore steps complete.
     * Includes db=excluded (files-only restore → complete_restore, no import).
     *
     * @param string $key
     * @param array  $excluded_restore_files
     * @return bool True when the caller must continue (skip download).
     */
    public static function maybe_skip_backup_excluded_restore_part($key, array &$excluded_restore_files)
    {
        $key = strtolower((string) $key);
        if ($key === '' || $key === 'log' || $key === 'log_download') {
            return false;
        }
        $backup_excluded = get_option('ocm_backup_excluded_parts', array());
        $backup_excluded = is_array($backup_excluded) ? $backup_excluded : array();
        if (!in_array($key, $backup_excluded, true)) {
            return false;
        }

        One_Click_Migration::write_to_log(sprintf(
            'Notice: %s was excluded from this backup by the user; not downloading.',
            $key
        ));
        $excluded_restore_files[] = $key;
        $excluded_restore_files = array_values(array_unique($excluded_restore_files));
        update_option('ocm_excluded_folders', $excluded_restore_files, true);
        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DOWNLOAD);
        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DECRYPT);
        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT);
        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
        return true;
    }

    /**
     * Real download-path entry used by the restore loop (and tests): skip
     * backup-excluded parts without requesting; otherwise record a request and
     * optionally run the Not Found handler.
     *
     * @param string $key
     * @param bool   $simulate_404
     * @return string 'skipped_excluded'|'continue'|'fail_restore'|'requested'
     */
    public static function process_restore_part_download_decision($key, $simulate_404 = false)
    {
        $key = strtolower((string) $key);
        $excluded_restore_files = get_option('ocm_excluded_folders', array());
        $excluded_restore_files = is_array($excluded_restore_files) ? $excluded_restore_files : array();
        if (in_array($key, $excluded_restore_files, true)) {
            return 'skipped_excluded';
        }
        if (self::maybe_skip_backup_excluded_restore_part($key, $excluded_restore_files)) {
            return 'skipped_excluded';
        }
        self::$restore_download_requests[] = $key;
        if (!$simulate_404) {
            return 'requested';
        }
        $eexcluded_restore_files = get_option('ocm_eexcluded_folders', array());
        $eexcluded_restore_files = is_array($eexcluded_restore_files) ? $eexcluded_restore_files : array();
        return self::handle_restore_part_download_not_found(
            $key,
            $key . '.zip.crypt',
            $excluded_restore_files,
            $eexcluded_restore_files
        );
    }

    /**
     * Handle a Not Found download for one restore part.
     * - Backup =excluded (incl. db=excluded): no warning, treat as selective exclude.
     * - Included / skipped db, or pre-manifest missing db: DB-part stop.
     * - Included non-db 404 / pre-manifest missing non-db: warn skipped (not found).
     *
     * @param string $key
     * @param string $keyZipCrypt
     * @param array  $excluded_restore_files
     * @param array  $eexcluded_restore_files
     * @return string 'continue' or 'fail_restore' (db stop never returns)
     */
    public static function handle_restore_part_download_not_found(
        $key,
        $keyZipCrypt,
        array &$excluded_restore_files,
        array &$eexcluded_restore_files
    ) {
        $key = (string) $key;
        $backup_missing_parts = get_option('ocm_backup_missing_parts', []);
        $backup_missing_parts = is_array($backup_missing_parts) ? $backup_missing_parts : [];
        $backup_excluded_parts = get_option('ocm_backup_excluded_parts', []);
        $backup_excluded_parts = is_array($backup_excluded_parts) ? $backup_excluded_parts : [];
        $excluded_at_backup = in_array($key, $backup_excluded_parts, true);
        $skipped_at_backup = in_array($key, $backup_missing_parts, true);

        // Intentional backup exclusion (files-only db, or non-db): never warn.
        if ($excluded_at_backup) {
            One_Click_Migration::write_to_log("Notice: $key was excluded from this backup by the user; continuing without it.");
            $excluded_restore_files[] = $key;
            $excluded_restore_files = array_values(array_unique($excluded_restore_files));
            update_option('ocm_excluded_folders', $excluded_restore_files, true);
            self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DOWNLOAD);
            self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DECRYPT);
            self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT);
            self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
            return 'continue';
        }

        // Meant-to-be-included db missing (skipped-at-backup, included 404, or
        // pre-manifest with no BACKUP_PARTS list): stop before file replace.
        if ($key === 'db') {
            update_option(self::OPTION_RESTORE_DB_PART_FAILED, 1, true);
            self::stop_restore_after_db_part_failure();
        }

        if ($skipped_at_backup) {
            One_Click_Migration::write_to_log("Notice: $key was not included in this backup (skipped while the backup was created); continuing without it.");
            self::record_restore_part_warning($key, 'not in backup');
        } elseif (get_option('ocm_backup_parts_known')) {
            return 'fail_restore';
        } else {
            // No BACKUP_PARTS line: cannot tell excluded from missing → warn.
            One_Click_Migration::write_to_log("Notice: file $keyZipCrypt not found");
            self::record_restore_part_warning($key, 'not found');
        }

        $excluded_restore_files[] = $key;
        $excluded_restore_files = array_values(array_unique($excluded_restore_files));
        update_option('ocm_excluded_folders', $excluded_restore_files, true);
        self::capture_failed_folder($eexcluded_restore_files, $key, 'ocm_eexcluded_folders');
        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DOWNLOAD);
        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DECRYPT);
        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT);
        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
        return 'continue';
    }

    public static function mark_backup_part_uploaded($part)
    {
        if (!self::backup_context_owns_current_run()) {
            return false;
        }

        $run = get_option(self::BACKUP_RUN_OPTION, array());
        if (!is_array($run) || empty($run['id']) || empty($run['bucket_id'])) {
            return false;
        }

        $state = get_option(self::BACKUP_UPLOADED_PARTS_OPTION, array());
        if (!is_array($state)
            || !isset($state['run_id'], $state['bucket_id'])
            || !hash_equals((string) $run['id'], (string) $state['run_id'])
            || !hash_equals((string) $run['bucket_id'], (string) $state['bucket_id'])
        ) {
            $state = array(
                'run_id' => (string) $run['id'],
                'bucket_id' => (string) $run['bucket_id'],
                'parts' => array(),
            );
        }

        $parts = isset($state['parts']) && is_array($state['parts']) ? $state['parts'] : array();
        $parts[] = strtolower((string) $part);
        $state['parts'] = array_values(array_unique($parts));
        return update_option(self::BACKUP_UPLOADED_PARTS_OPTION, $state, false);
    }

    public static function was_backup_part_uploaded($part)
    {
        $run = get_option(self::BACKUP_RUN_OPTION, array());
        $state = get_option(self::BACKUP_UPLOADED_PARTS_OPTION, array());
        if (!is_array($run) || !is_array($state)
            || empty($run['id']) || empty($run['bucket_id'])
            || empty($state['run_id']) || empty($state['bucket_id'])
            || !hash_equals((string) $run['id'], (string) $state['run_id'])
            || !hash_equals((string) $run['bucket_id'], (string) $state['bucket_id'])
        ) {
            return false;
        }

        $parts = isset($state['parts']) && is_array($state['parts']) ? $state['parts'] : array();
        return in_array(strtolower((string) $part), $parts, true);
    }

    public static function print_folders_not_found(){
      $not_found_folders = get_option("ocm_eexcluded_folders", []);


      foreach ($not_found_folders as $key => $not_found_folder) {

        // One_Click_Migration::write_to_log("Notice: File " . $not_found_folder. ".zip.crypt was not found on the remote server. Please try to back it up again.");

      }

    }

    public static function print_folders_skipped(){
      $skipped_folders = get_option("ocm_skipped_folders", []);
      foreach ($skipped_folders as $key => $skipped_folder) {
        One_Click_Migration::write_to_log("File $skipped_folder was skipped due to timeout");

      }

      return true;

    }

    /**
     * Parts the just-finished backup does not contain (skipped after retry
     * exhaustion). Normalized, deduped, sorted.
     *
     * @return string[]
     */
    public static function get_backup_missing_parts()
    {
        $skipped = get_option('ocm_skipped_folders', []);
        $excluded = get_option('ocm_excluded_folders', array());
        $excluded = is_array($excluded) ? $excluded : array();
        $missing = array();
        foreach ((is_array($skipped) ? $skipped : array()) as $part) {
            if (is_string($part) && $part !== '' && !self::was_backup_part_uploaded($part)) {
                $missing[] = strtolower($part);
            }
        }

        foreach (array('db', 'themes', 'plugins', 'uploads') as $part) {
            if (!in_array($part, $excluded, true)
                && !self::was_backup_part_uploaded($part)
                && !self::check_is_complete_backup_step($part, self::STEP_BACKUP_CHILD_UPLOAD)
            ) {
                $missing[] = $part;
            }
        }

        $missing = array_values(array_unique($missing));
        sort($missing);
        return $missing;
    }

    /** Append bounded manifest records once; the final log write mirrors them to S3. */
    private static function publish_url_manifest($found)
    {
        if (!self::backup_context_owns_current_run() || !One_Click_Migration::ensure_private_log_directory()) {
            return;
        }
        $run = get_option(self::BACKUP_RUN_OPTION, array());
        $bucket = $run['bucket_id'] ?? '';
        if (!$bucket) {
            return;
        }
        $lines = OCM_URL_Manifest::lines($found, site_url(), $bucket);
        if (!$lines) {
            One_Click_Migration::write_to_log('Notice: Backup address inventory unavailable; addresses can be reviewed after restore.');
            return;
        }
        $text = '';
        foreach ($lines as $line) {
            $text .= date('m/d/YTH:i:sa') . ' - ' . $line . PHP_EOL;
        }
        if (@file_put_contents(OCM_DEBUG_LOG_FILE, $text, FILE_APPEND | LOCK_EX) !== strlen($text)) {
            One_Click_Migration::write_to_log('Notice: Backup address inventory could not be saved; addresses can be reviewed after restore.');
            return;
        }
        One_Click_Migration::write_to_log('SYSLOG: Backup address inventory saved.');
    }

    /**
     * Write the per-part status marker into the log. write_to_log mirrors the
     * whole log to S3, so the restore side can read this back before it starts
     * downloading (see One_Click_Migration::extract_missing_parts_from_backup_log).
     */
    public static function log_backup_parts_manifest($missing_parts)
    {
        if (!self::backup_context_owns_current_run()) {
            return;
        }

        $run = get_option(self::BACKUP_RUN_OPTION, array());
        $bucket_id = is_array($run) && isset($run['bucket_id']) ? (string) $run['bucket_id'] : '';
        if ('' === $bucket_id) {
            return;
        }

        $excluded = get_option('ocm_excluded_folders', []);
        $excluded = is_array($excluded) ? $excluded : array();
        $missing_parts = is_array($missing_parts) ? $missing_parts : array();

        $pairs = array();
        foreach (array('db', 'themes', 'plugins', 'uploads') as $part) {
            if (in_array($part, $excluded, true)) {
                $pairs[] = $part . '=excluded';
            } elseif (in_array($part, $missing_parts, true)) {
                $pairs[] = $part . '=skipped';
            } else {
                $pairs[] = $part . '=ok';
            }
        }

        One_Click_Migration::write_to_log(' SYSLOG: BACKUP_PARTS bucket=' . $bucket_id . ' ' . implode(' ', $pairs));
    }

    /**
     * Convert memory limit string to bytes.
     * @param string $val Memory limit string (e.g., "512M")
     * @return int
     */
    private static function convert_to_bytes($val) {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        $val = (int) $val;

        switch($last) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;
        }

        return $val;
    }

    /**
     * Format bytes to human readable format.
     * @param int $bytes
     * @return string
     */
    private static function format_bytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }


}
