<?php

namespace OCM;

use DirectoryIterator;
use phpseclib\Crypt\Rijndael;
use PhpZip\Constants\ZipCompressionMethod;
use PhpZip\Exception\ZipException;
use PhpZip\ZipFile;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Finder\SplFileInfo;
use WP_Error;
use wpdb;
use ZipArchive;

class OCM_Backup
{
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

    const LOG_MESSAGE_BG_PROCESS_RESTARTING = 'Timeout approaching. Restarting';
    const LOG_MESSAGE_BG_PROCESS_RESTARTING_LOG = 'Notice: Please wait. This is taking longer than expected but we are still making progress';
    const MAX_STEP_RETRIES = 4;
    const MAX_TRANSFER_RETRIES = 24;

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
        ['URL generated: log', '23%'],
        ['URL generated: log_download', '27%'],


        ['URL generated: db', '5%'],
        ['URL generated: uploads', '9%'],
        ['URL generated: themes', '14%'],
        ['URL generated: plugins', '18%'],
        ['URL generated: log', '23%'],
        ['URL generated: log_download', '27%'],

        ['db added to queue', '28%'],
        ['themes added to queue', '29%'],
        ['plugins added to queue', '30%'],
        ['uploads added to queue', '31%'],

        ['db task has been started.', '38%'],
        ['DB Size', '39%'],
        ['Database backup started', '40%'],
        // Removed: ['Database backup in progress. Please do not leave the plugin page', '40%'], - appears for every table, causes progress bar jumping
        ['SYSLOG: Number of updates made from replace', '40%'],
        ['SYSLOG: Finished updating URLs in the database', '40%'],
        ['SYSLOG: Table being backed up', '40%'],
        ['SYSLOG: No table structure for table', '40%'],
        ['SYSLOG: Inserting table data into table', '40%'],
        ['SYSLOG: End of data contents of table', '40%'],
        ['SYSLOG: There is no mysqldump support', '40%'],
        ['SYSLOG: Column statistics are supported', '40%'],
        ['SYSLOG: Table retrieved', '40%'],
        ['SYSLOG: Column statistics are not supported', '40%'],
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
        ['Restoring "Uploads"', '61%'],

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
        ['Restoring "Themes"', '62%'],

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
        ['Restoring "Plugins"', '63%'],

        ['Download Error:', '60%'],
        ['Notice: file uploads.zip.crypt not found', '60%'],
        ['Notice: file themes.zip.crypt not found', '60%'],
        ['Notice: file plugins.zip.crypt not found', '60%'],
        ['Notice: file db.zip.crypt not found', '60%'],

        ['Themes have been restored', '64%'],
        ['Uploads have been restored', '64%'],
        ['Plugins have been restored', '64%'],

        ['Restore plugins', '75%'],
        ['Notice: Database restore in progress. Please don\'t leave the plugin page.', '80%'],
        ['Database has been restored', '83%'],
        ['Starting to update URLs in database', '84%'],
        ['Cleaning up.', '92%'],
        ['Restore completed.', '100%'],
        ['Error:', '0%'],
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
    private static $restore_files = [];


    public static function cancel_actions()
    {
        // Add nonce verification
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'one_click_migration_cancel_action')) {
            wp_die('Security check failed. Please try again.');
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to perform this action.');
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
            wp_die('Security check failed. Please try again.');
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to perform this action.');
        }

        // Prevent re-entry while a backup is already running
        if (get_site_transient('ocm_background_backup_process_lock')) {
            wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=backup_in_progress'));
            exit;
        }

        @set_time_limit(One_Click_Migration::get_timeout());
        update_option('ocm_action_start_backup', true, true);
        update_option('ocm_is_stopped', false, true);
        update_option('ocm_upload_auto_try_nb_db', 0, true);
        update_option('ocm_upload_auto_try_nb_themes', 0, true);
        update_option('ocm_upload_auto_try_nb_plugins', 0, true);
        update_option('ocm_upload_auto_try_nb_uploads', 0, true);

        One_Click_Migration::delete_log_file();
        One_Click_Migration::cancel_all_process();

        self::reset_current_backup_steps();
        self::reset_current_restore_steps();
        self::delete_backup_options();
        delete_option('ocm_log_merged_bucket');
        One_Click_Migration::write_to_log('Backup started');

        if (!One_Click_Migration::$process_backup_single->is_queue_empty()) {
            // Plugin isolation is intentionally disabled while restore is being debugged.
            // The function still runs to clear any stale mu-plugin shim from disk.
            One_Click_Migration::start_backup_plugin_isolation();

            self::dispatch_backup_worker_with_diagnostics();
            wp_die('Backup started');
        }

        $username = self::get_username();
        update_option('ocm_user_email', $username, true);

        $excluded_folders = self::get_excluded_folders();

        if (!self::ensure_temporary_directory()) {
            One_Click_Migration::reset_actions_start_mark();
            update_option('ocm_is_stopped', true, true);
            wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=write_permission'));
            exit;
        }

        $disk = self::check_backup_disk_space($excluded_folders);
        if (!$disk['ok']) {
            One_Click_Migration::write_to_log(sprintf(
                'Error: Not enough disk space to run backup. Need %s, only %s available.',
                self::convertToReadableSize($disk['required']),
                self::convertToReadableSize($disk['free'])
            ));
            One_Click_Migration::reset_actions_start_mark();
            update_option('ocm_is_stopped', true, true);
            wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=insufficient_disk_space'));
            exit;
        }

        // Register backup with Lambda → MySQL gateway. Lambda generates bucket_id,
        // restore code, and AES key. The restore code is emailed only after all
        // selected backup parts have finished uploading.
        $site_url = get_site_url();
        $registration = OCM_S3::register_backup($username, $site_url);

        $retry_count = 0;
        $max_retries = 3;
        while (!$registration && $retry_count < $max_retries) {
            $retry_count++;
            One_Click_Migration::write_to_log("Retrying backup registration (attempt $retry_count/$max_retries)...");
            sleep(2);
            $registration = OCM_S3::register_backup($username, $site_url);
        }

        if (!$registration) {
            One_Click_Migration::write_to_log('Error: Could not register backup after ' . ($retry_count + 1) . ' attempts');
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

            // Server configuration diagnostics
            One_Click_Migration::write_to_log(' SYSLOG: Server IP: ' . ($_SERVER['SERVER_ADDR'] ?? 'unknown'));
            One_Click_Migration::write_to_log(' SYSLOG: WP_HTTP_BLOCK_EXTERNAL: ' . (defined('WP_HTTP_BLOCK_EXTERNAL') ? (WP_HTTP_BLOCK_EXTERNAL ? 'true' : 'false') : 'not set'));
            One_Click_Migration::write_to_log(' SYSLOG: WP_ACCESSIBLE_HOSTS: ' . (defined('WP_ACCESSIBLE_HOSTS') ? WP_ACCESSIBLE_HOSTS : 'not set'));
            One_Click_Migration::write_to_log(' SYSLOG: Admin URL: ' . admin_url('admin-ajax.php'));
            
            // Clear tmp directory contents but keep the directory itself to avoid
            // a window where other code tries to write to a non-existent path
            if (is_dir(OCM_PLUGIN_WRITABLE_PATH)) {
                self::deleteDir(OCM_PLUGIN_WRITABLE_PATH, 'Cleaning temporary directory', $presigned_urls, true);
            } else {
                if (!@mkdir($concurrentDirectory = OCM_PLUGIN_WRITABLE_PATH, 0700, true) && !is_dir($concurrentDirectory)) {
                    One_Click_Migration::write_to_log(sprintf(
                        'Creating temporary directory ("%s"), Error: Directory "%s" was not created. Please check the write permission for the parent directory "%s".',
                        OCM_PLUGIN_WRITABLE_PATH, $concurrentDirectory, WP_CONTENT_DIR
                    ));
                    exit;
                }
            }

            foreach ($presigned_urls as $key => $generated_url) {

                One_Click_Migration::write_to_log("URL generated: $key");
            }

            self::set_complete_backup_step('init', self::STEP_BACKUP_CHILD_INITIATE);
        }

          if(!(in_array('db', $excluded_folders))){
            if (!self::check_is_complete_backup_step('db', self::STEP_BACKUP_CHILD_INITIATE_DB_BACKUP)) {
                One_Click_Migration::write_to_log('db added to queue');
                One_Click_Migration::$process_backup_single->push_to_queue(array('db'));
            }
          }
          if(!(in_array('themes', $excluded_folders))){
            if (!self::check_is_complete_backup_step('themes', self::STEP_BACKUP_CHILD_INITIATE_BACKUP_THEMES)) {

                One_Click_Migration::write_to_log('themes added to queue');
                One_Click_Migration::$process_backup_single->push_to_queue(array('themes'));
            }
          }
          if(!(in_array('plugins', $excluded_folders))){
            if (!self::check_is_complete_backup_step('plugins', self::STEP_BACKUP_CHILD_INITIATE_BACKUP_PLUGINS)) {
                One_Click_Migration::write_to_log('plugins added to queue');
                One_Click_Migration::$process_backup_single->push_to_queue(array('plugins'));
            }
          }
          if(!(in_array('uploads', $excluded_folders))){
            if (!self::check_is_complete_backup_step('uploads', self::STEP_BACKUP_CHILD_INITIATE_BACKUP_UPLOADS)) {
                One_Click_Migration::write_to_log('uploads added to queue');
                One_Click_Migration::$process_backup_single->push_to_queue(array('uploads'));
            }
          }
        // Plugin isolation is intentionally disabled while restore is being debugged.
        // The function still runs to clear any stale mu-plugin shim from disk.
        One_Click_Migration::start_backup_plugin_isolation();

        One_Click_Migration::$process_backup_single->save();
        self::dispatch_backup_worker_with_diagnostics();

        wp_die('Backup started');
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
            wp_die('Security check failed. Please try again.');
        }
        
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to perform this action.');
        }

        $restore_code = self::get_restore_code();

        update_option('ocm_download_auto_try_nb_db', 0, true);
        update_option('ocm_download_auto_try_nb_themes', 0, true);
        update_option('ocm_download_auto_try_nb_plugins', 0, true);
        update_option('ocm_download_auto_try_nb_uploads', 0, true);

        One_Click_Migration::cancel_all_process();

        self::reset_current_backup_steps();
        self::reset_current_restore_steps();
        self::delete_restore_options();
        // Clear stale presigned URLs from a prior migration so early write_to_log
        // calls don't create a local log file keyed to a dead bucket (which would
        // later block the merge-in of the backup log from the new bucket).
        delete_option('ocm_presigned_urls');
        delete_option('ocm_log_merged_bucket');
        One_Click_Migration::delete_log_file();
        $exluded_folders = self::get_excluded_folders();

        if (!One_Click_Migration::$process_restore_single->is_queue_empty()) {
            // Queue already exists, just process it directly
            One_Click_Migration::$process_restore_single->trigger_processing();
        } else {
            One_Click_Migration::$process_restore_single->push_to_queue(array($restore_code));

            // Save the queue and use direct processing for maximum reliability
            One_Click_Migration::$process_restore_single->save();
            One_Click_Migration::write_to_log(' SYSLOG: Using direct restore processing for maximum compatibility');
            One_Click_Migration::$process_restore_single->trigger_processing();
        }
    }

    public static function start_restore_process($restore_code)
    {
        global $wpdb;

        @set_time_limit(One_Click_Migration::get_timeout());
        update_option('ocm_action_start_restore', true, true);
        update_option('ocm_is_stopped', false, true);



        $content_dir = WP_CONTENT_DIR . '/ocm_restore';
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
                wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=invalid_restore_code'));
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

            $excluded_for_check = self::get_excluded_folders();
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
                One_Click_Migration::reset_actions_start_mark();
                update_option('ocm_is_stopped', true, true);
                wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=insufficient_disk_space'));
                exit;
            }

            One_Click_Migration::write_to_log('Restore started');
            $all_plugins = get_option('active_plugins', []);
            $all_plugins = is_array($all_plugins) ? $all_plugins : [];
            $other_count = count($all_plugins) - 1;
            One_Click_Migration::write_to_log('Other active plugins: ' . max(0, $other_count));
            self::force_only_ocm_plugin_active('starting restore');
            OCM_Debug_Mode::enable();
            One_Click_Migration::write_to_log(' SYSLOG: Debug mode enabled for restore run');
            One_Click_Migration::enable_maintenance_mode();
            $excluded_restore_files = get_option('ocm_excluded_folders', []);

            $exluding = implode(", ", $excluded_restore_files);

            // One_Click_Migration::write_to_log(sprintf('Excluding "%s"', $exluding));
            self::save_system_data_to_log_file($username, $bucket_id);

            foreach ($presigned_urls as $key => $generated_url) {
              $writable_file_path = OCM_PLUGIN_WRITABLE_PATH . $key . '.zip';

              if(file_exists($writable_file_path)){
                One_Click_Migration::write_to_log("URL generated: $key");
              }

            }

            if (!self::prepare_restore_workspace($content_dir)) {
                One_Click_Migration::write_to_log(sprintf(
                    'Downloading restore files, Error: Directory "%s" could not be prepared. Please check the write permission for the parent directory "%s".',
                    $content_dir, WP_CONTENT_DIR
                ));
                exit;
            }

            One_Click_Migration::write_to_log('Downloading restore files. Please wait.');

            self::set_complete_restore_step('init', self::STEP_RESTORE_CHILD_INITIATE);
            self::set_complete_restore_step('init', self::STEP_RESTORE_CHILD_PRESIGNED_URLS, $presigned_urls);
        }

        $presigned_urls = self::get_restore_step_value('init', self::STEP_RESTORE_CHILD_PRESIGNED_URLS);
        $excluded_folders = self::get_excluded_folders();

        // Download each of the zip files
        foreach ($presigned_urls as $key => $download_url) {
            // Don't try and download the log file

            $excluded_restore_files = get_option('ocm_excluded_folders',[]);
            $keyZipCrypt = $key . '.zip.crypt';
            $file_path = $content_dir . '/' . $keyZipCrypt;
            $extract_path = $content_dir . "/$key";
            if(!in_array($key, $excluded_restore_files)){

            if (in_array($key, array('log', 'log_download'))) {
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
                        self::capture_failed_folder($excluded_restore_files, $key, 'ocm_excluded_folders');
                        self::capture_failed_folder($skipped_restore_files, $key, 'ocm_skipped_folders');
                        continue;

                      }
                    }
                  }
                  if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DOWNLOAD)) {
                    // Reset time limit for file download
                    @set_time_limit(One_Click_Migration::get_timeout());

                    One_Click_Migration::write_to_log(sprintf('Downloading file: "%s.zip.crypt"', $key));

                    $downloadTmpPathFile = self::download_restore_file($download_url, $file_path, $key);

                    if(is_wp_error($downloadTmpPathFile)){
                      $error_message = $downloadTmpPathFile->get_error_message();
                      if($error_message === 'Not Found'){

                        $backup_missing_parts = get_option('ocm_backup_missing_parts', []);
                        $backup_missing_parts = is_array($backup_missing_parts) ? $backup_missing_parts : [];

                        if (in_array($key, $backup_missing_parts, true)) {
                            One_Click_Migration::write_to_log("Notice: $key was not included in this backup (skipped while the backup was created); continuing without it.");
                        } elseif (get_option('ocm_backup_parts_known')) {
                            // The backup manifest says this part was uploaded, so its
                            // absence means the stored backup is damaged or expired.
                            return self::fail_restore(sprintf(
                                'Error: Restore failed because "%s" should exist in this backup but is missing from storage. Create a fresh backup and restore with the new code.',
                                $keyZipCrypt
                            ));
                        } else {
                            One_Click_Migration::write_to_log("Notice: file $keyZipCrypt not found");
                        }

                        array_push($excluded_restore_files, $key);
                        $excluded_restore_files = array_unique($excluded_restore_files);

                        update_option( 'ocm_excluded_folders', $excluded_restore_files);
                        self::capture_failed_folder($eexcluded_restore_files, $key, 'ocm_eexcluded_folders');

                        continue;
                      }

                      if ($downloadTmpPathFile->get_error_code() === 'ocm_partial_download') {
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

                    if (One_Click_Migration::$process_restore_single->time_exceeded()) {
                        $next_retry_count = get_option('ocm_restore_download_retry_' . $key, 1);
                        if($next_retry_count && $next_retry_count <= self::MAX_TRANSFER_RETRIES){
                          One_Click_Migration::write_to_log("Process is Restarting");
                          update_option('ocm_restore_download_retry_' . $key, $next_retry_count, true);
                          One_Click_Migration::$process_restore_single->restart_task();
                        }
                        else{
                          One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Download'));
                          One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Decrypting'));
                          One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Extraction'));
                          self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DOWNLOAD);
                          self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DECRYPT);
                          self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT);
                          self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                          self::capture_failed_folder($excluded_restore_files, $key, 'ocm_excluded_folders');
                          self::capture_failed_folder($skipped_restore_files, $key, 'ocm_skipped_folders');
                          continue;
                        }
                    }
                    if (!One_Click_Migration::$process_restore_single->time_exceeded()) {

                      self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DOWNLOAD_TMP_PATH, $downloadTmpPathFile);
                      self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DOWNLOAD);
                  }
                }
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

                        One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Decypting'));
                        One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Extraction'));

                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DECRYPT);
                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT);
                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                        self::capture_failed_folder($excluded_restore_files, $key, 'ocm_excluded_folders');
                        self::capture_failed_folder($skipped_restore_files, $key, 'ocm_skipped_folders');
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
                  if (!$unencrypted_zip) {
                      return self::fail_restore(sprintf(
                          'Error: Restore failed while decrypting "%s.zip.crypt". Please check disk quota and write permissions.',
                          $key
                      ));
                  }

                  if (One_Click_Migration::$process_restore_single->time_exceeded()) {
                      $next_retry_count = get_option('ocm_restore_decrypt_retry_' . $key, 1);
                      if($next_retry_count && $next_retry_count <= self::MAX_STEP_RETRIES){
                        One_Click_Migration::write_to_log("Process is Restarting");
                        update_option('ocm_restore_decrypt_retry_' . $key, $next_retry_count, true);
                        One_Click_Migration::$process_restore_single->restart_task();
                      }
                      else{
                        One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Decypting'));
                        One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Extraction'));

                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DECRYPT);
                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT);
                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                        self::capture_failed_folder($excluded_restore_files, $key, 'ocm_excluded_folders');
                        self::capture_failed_folder($skipped_restore_files, $key, 'ocm_skipped_folders');
                        continue;
                      }
                    }

                    if (!One_Click_Migration::$process_restore_single->time_exceeded()) {

                      self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_UNENCRYPTED_ZIP, $unencrypted_zip);
                      self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DECRYPT);

                      // Later steps only need the decrypted zip; drop the encrypted copy
                      // now so the part never occupies 3x its size on disk at once.
                      @unlink($file_path);
                      if (is_string($downloadTmpPathFile) && $downloadTmpPathFile !== $file_path && file_exists($downloadTmpPathFile)) {
                          @unlink($downloadTmpPathFile);
                      }
                  }
                }
              }

              if (class_exists('ZipArchive') != true) {
                        One_Click_Migration::write_to_log('Error: Class ZipArchive not found. Please see <a href="http://www.php.net/manual/en/zip.installation.php">this page</a> for installation instructions or ask your hosting provider to enable the zip extension');
                  exit;
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
                        self::capture_failed_folder($excluded_restore_files, $key, 'ocm_excluded_folders');
                        self::capture_failed_folder($skipped_restore_files, $key, 'ocm_skipped_folders');
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

                  if (!$extract_result || !is_dir($extract_path)) {
                      return self::fail_restore(sprintf(
                          'Error: Zip file could not be extracted at "%s". Please check disk quota and write permissions.',
                          $extract_path
                      ));
                  }

                    if (One_Click_Migration::$process_restore_single->time_exceeded()) {
                      $next_retry_count = get_option('ocm_restore_extract_retry_' . $key, 1);
                      if($next_retry_count && $next_retry_count <= self::MAX_STEP_RETRIES){
                        One_Click_Migration::write_to_log("Process is Restarting");
                        update_option('ocm_restore_extract_retry_' . $key, $next_retry_count, true);
                        One_Click_Migration::$process_restore_single->restart_task();
                      }
                      else{

                        One_Click_Migration::write_to_log(sprintf('Skipping ' .$key.  ' Extraction'));

                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT);
                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                        self::capture_failed_folder($excluded_restore_files, $key, 'ocm_excluded_folders');
                        self::capture_failed_folder($skipped_restore_files, $key, 'ocm_skipped_folders');
                        continue;
                      }
                    }

                    if (!One_Click_Migration::$process_restore_single->time_exceeded()) {


                    self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_EXTRACT);

                    // Extraction succeeded, so the zip is no longer needed; free its
                    // space before the next part starts downloading.
                    @unlink($unencrypted_zip['filename']);
                  }
                }

              }
              if(is_dir($content_dir)){
                $retry_count = get_option('ocm_restore_child_delete_retry_' . $key, 0);
                if(!$retry_count){
                  update_option('ocm_restore_child_delete_retry_' . $key, 1, true);

                }
                if($retry_count){
                  if($retry_count <= self::MAX_STEP_RETRIES){
                    if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW)) {
                    One_Click_Migration::write_to_log(sprintf('Deleting old %s Retry %d', $key, $retry_count));
                    $retry_count = $retry_count + 1;
                    update_option('ocm_restore_child_delete_retry_' . $key, $retry_count, true);
                    }
                  }else{
                      if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW)) {

                        One_Click_Migration::write_to_log(sprintf('Skipping Old ' .$key.  ' Deletion'));

                        self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                        self::capture_failed_folder($excluded_restore_files, $key, 'ocm_excluded_folders');
                        continue;
                      }
                    }
                  }
                if (!self::check_is_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW)) {
                    if (!in_array($key, array('db', 'plugins')) && is_dir($extract_path)) {

                        $directory = WP_CONTENT_DIR . '/' . $key;

                        One_Click_Migration::write_to_log(sprintf('Restoring "%s" to directory "%s"', ucfirst($key), WP_CONTENT_DIR));

                        if (!self::replaceDirectoryFromExtract($extract_path, $directory, $key)) {
                            One_Click_Migration::write_to_log(sprintf(
                                'Downloading restore files. Error: "%s" directory could not be moved or copied to "%s" directory. Please check the write permission for these directories.',
                                $extract_path, $directory
                            ));

                            update_option('ocm_is_stopped', true, true);
                            die();
                        }


                      if (One_Click_Migration::$process_restore_single->time_exceeded()) {
                        $next_retry_count = get_option('ocm_restore_child_delete_retry_' . $key, 1);
                        if($next_retry_count && $next_retry_count <= self::MAX_STEP_RETRIES){
                          One_Click_Migration::write_to_log("Process is Restarting");
                          update_option('ocm_restore_child_delete_retry_' . $key, $next_retry_count, true);
                          One_Click_Migration::$process_restore_single->restart_task();
                        }
                        else{

                          One_Click_Migration::write_to_log(sprintf('Skipping Old ' .$key.  ' Deletion'));

                          self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                          self::capture_failed_folder($excluded_restore_files, $key, 'ocm_excluded_folders');
                          continue;
                        }
                      }
                    }

                    self::set_complete_restore_step($key, self::STEP_RESTORE_CHILD_DELETE_OLD_AND_MOVE_NEW);
                }

              }
            }
        if(!is_dir($content_dir)){
          return OCM_BackgroundRestore::RETURN_TYPE_STOP_PROCESSS;
          update_option('ocm_is_stopped', true, true);
          die();

        }

        if(!in_array('themes', $excluded_restore_files)){
          if (!self::check_is_complete_restore_step('themes', self::STEP_RESTORE_CHILD_THEMES_RESTORED)) {
              One_Click_Migration::write_to_log('Themes have been restored');
              self::set_complete_restore_step('themes', self::STEP_RESTORE_CHILD_THEMES_RESTORED);
          }
        }
        if(!in_array('uploads', $excluded_restore_files)){
          if (!self::check_is_complete_restore_step('uploads', self::STEP_RESTORE_CHILD_UPLOADS_RESTORED)) {
              One_Click_Migration::write_to_log('Uploads have been restored');
              self::set_complete_restore_step('uploads', self::STEP_RESTORE_CHILD_UPLOADS_RESTORED);
          }
        }

        if(!in_array('plugins', $excluded_restore_files)){
          if (!self::check_is_complete_restore_step('plugins', self::STEP_RESTORE_CHILD_RESTORE)) {

            self::restore_plugins($presigned_urls);
          }

        }
        if(in_array('db', $excluded_restore_files)){
          self::complete_restore();
        }else{
          self::finish_restore_db_phase();
        }

        exit;
    }

    private static function fail_restore($message)
    {
        One_Click_Migration::write_to_log($message);
        One_Click_Migration::disable_maintenance_mode();
        OCM_Debug_Mode::disable();
        One_Click_Migration::reset_actions_start_mark();
        update_option('ocm_is_stopped', true, true);

        return OCM_BackgroundRestore::RETURN_TYPE_STOP_PROCESSS;
    }

    public static function complete_restore(){
      $presigned_urls = get_option('ocm_presigned_urls');

        self::post_restore_cleanup();

        One_Click_Migration::write_to_log('Cleaning up.');
        
        // Add a small delay to ensure all file operations complete before cleanup
        sleep(2);

        // Delete ocm_restore folder
        $ocmRestoreDir = WP_CONTENT_DIR . '/ocm_restore/';



        if(WP_DEBUG !== true){

        if (is_dir($ocmRestoreDir)) {
            self::deleteDir($ocmRestoreDir, 'Cleaning up "ocm_restore" directory', $presigned_urls);
            // Delete ocm_restore folder
              if (file_exists($ocmRestoreDir) && !rmdir($ocmRestoreDir)) {
                One_Click_Migration::write_to_log(sprintf(
                    'Cleaning up "ocm_restore" directory. Error: "%s" directory could not be deleted. Please check the write permission for this directory or the parent directory.',
                    $ocmRestoreDir
                ));

                update_option('ocm_is_stopped', true, true);
                exit;
            }
          }
        }
        self::print_folders_skipped();
        self::print_folders_not_found();

        $backup_missing_parts = get_option('ocm_backup_missing_parts', []);
        if (is_array($backup_missing_parts) && $backup_missing_parts) {
            One_Click_Migration::write_to_log('Notice: Restore finished without: ' . implode(', ', $backup_missing_parts) . ' (these were not included in the backup).');
        }

        One_Click_Migration::disable_maintenance_mode();
        OCM_Debug_Mode::disable();
        One_Click_Migration::write_to_log('Restore completed.');
        One_Click_Migration::reset_actions_start_mark();
        self::reset_current_restore_steps();
        update_option('ocm_is_stopped', true, true);


    }


    /**
     * Finish the destructive phase of the restore: DB restore + URL search-replace + cleanup.
     * Called inline at the end of start_restore_process() once all file-based steps complete.
     */
    public static function finish_restore_db_phase()
       {
        global $wpdb;
        $presigned_urls   = get_option('ocm_presigned_urls');
        $excluded_folders = get_option('ocm_excluded_folders', []);

        //cater for the database restore.
        if(!in_array('db', $excluded_folders)){
          if (!self::check_is_complete_restore_step('db', self::STEP_RESTORE_CHILD_RESTORE)) {
              if (!self::restore_db($presigned_urls, $wpdb)) {
                  One_Click_Migration::write_to_log('Error: Restore failed during database import.');
                  One_Click_Migration::disable_maintenance_mode();
                  OCM_Debug_Mode::disable();
                  update_option('ocm_is_stopped', true, true);
                  exit;
              }
              // active_plugins is now the backup's list (just restored from the options table).
              // Leave it alone — search-replace below runs raw DB queries and doesn't load plugins,
              // and we want the user's original plugin set to be active again after restore.
          }
        }


        //search and replace all the  old urls in the database.
        $current_url = site_url();
        $old_url = self::$restore_site_url;


        if (!empty($old_url)) {
            $extra_arguments = array(
                'search_for'=> $old_url,
                'replace_with' => $current_url
            );
            $db_details = new OCM_DB();
            $tables = $db_details->get_tables();
            $result = $db_details->search_replace_db( $tables, $extra_arguments );
            $report_update = $result['updates'];
            One_Click_Migration::write_to_log(sprintf('SYSLOG: Number of updates made from replace "%s"',$report_update) );
            One_Click_Migration::write_to_log(sprintf('SYSLOG: Finished updating URLs in the database'));
        }
        self::complete_restore();
        exit;
    }

    public static function initiate_db_backup($presigned_urls, $aes_key)
    {
        global $wpdb;
        
        // Reset logging flags for this backup session
        OCM_SQL_Utils::reset_backup_logging_flags();

        One_Click_Migration::write_to_log('Database backup started');
        $db_size_mb = self::DBSize();
        One_Click_Migration::write_to_log('DB Size '. $db_size_mb.'MB');

        // Pre-flight disk space check: need ~2x DB size for SQL dumps + ZIP
        if (!self::ensure_temporary_directory()) {
            return OCM_BackgroundBackup::RETURN_TYPE_END_PROCESSS;
        }

        $free_bytes = self::get_free_disk_space(OCM_PLUGIN_WRITABLE_PATH);
        if ($free_bytes > 0 && $db_size_mb > 0) {
            $required_bytes = $db_size_mb * 2 * 1024 * 1024;
            if ($free_bytes < $required_bytes) {
                One_Click_Migration::write_to_log(sprintf(
                    'Error: Not enough disk space to complete backup. Need %sMB, only %sMB available.',
                    round($required_bytes / 1024 / 1024),
                    round($free_bytes / 1024 / 1024)
                ));
                update_option('ocm_is_stopped', true, true);
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
        if (file_exists(OCM_PLUGIN_WRITABLE_PATH . 'db_backup.sql')) {
            unlink(OCM_PLUGIN_WRITABLE_PATH . 'db_backup.sql');
        }

        if (!self::check_is_complete_backup_step('db', self::STEP_BACKUP_CHILD_COMPRESS)) {


        $tables = $wpdb->get_results('SHOW TABLES');

        // If tmp directory doesn't exist, create it
        if (!is_dir(OCM_PLUGIN_WRITABLE_PATH . 'db/')) {
            if (!mkdir($concurrentDirectory = OCM_PLUGIN_WRITABLE_PATH . 'db/', 0700, true) && !is_dir($concurrentDirectory)) {
                One_Click_Migration::write_to_log(sprintf('Database backup started, Error: Directory "%s" was not created', $concurrentDirectory));

                One_Click_Migration::write_to_log(sprintf(
                    'Database backup started, Error: Directory "%s" was not created. Please check the write permission for the parent directory "%s".',
                    $concurrentDirectory, WP_CONTENT_DIR
                ));

                update_option('ocm_is_stopped', true, true);
                exit;
            }
        }

        foreach ($tables as $table) {
            foreach ($table as $t) {

                $file = OCM_PLUGIN_WRITABLE_PATH . "db/$t.sql";
                if(file_exists(OCM_PLUGIN_WRITABLE_PATH . "db")){
                  // Suppressed: generates one log line per table, causing excessive log noise
                  // One_Click_Migration::write_to_log(sprintf(' SYSLOG: Table being backed up: "%s"', $t));
                  OCM_SQL_Utils::export([$file], [
                      'tables' => $t,
                      'quick' => null
                  ]);

                }else{
                  return OCM_BackgroundBackup::RETURN_TYPE_END_PROCESSS;

                }

            }
        }


          $zip_filepath = self::createZipFile(OCM_PLUGIN_WRITABLE_PATH . 'db/', $aes_key, $presigned_urls);
          if (!$zip_filepath) {
              update_option('ocm_is_stopped', true, true);
              exit;
          }

          if (!One_Click_Migration::$process_backup_single->time_exceeded()) {
            One_Click_Migration::write_to_log('Database ZIP file created.');
            self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_COMPRESS);
            self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_ENCRYPT);
            self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_ZIP_FILE_PATH, $zip_filepath);

            // Only the encrypted zip is uploaded; drop the per-table SQL dumps and
            // the plain zip now so they don't stack under later parts' disk needs.
            self::deleteDirectoryBestEffort(OCM_PLUGIN_WRITABLE_PATH . 'db/');
            @unlink(OCM_PLUGIN_WRITABLE_PATH . 'db.zip');
          }


          if (One_Click_Migration::$process_backup_single->time_exceeded()) {
            $next_retry_count = get_option('ocm_backup_compress_retry_db', 1);

            if($next_retry_count && $next_retry_count <= self::MAX_STEP_RETRIES){
              One_Click_Migration::write_to_log("Process is Restarting");
              update_option('ocm_backup_compress_retry_db', $next_retry_count, true);
              One_Click_Migration::$process_backup_single->restart_task();

            }else{

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
              $zip_filepath = OCM_PLUGIN_WRITABLE_PATH . 'db.zip.crypt';
          }

          $return = OCM_S3::upload_zip($zip_filepath);
          if (in_array($return, [OCM_BackgroundBackup::RETURN_TYPE_STOP_PROCESSS, OCM_BackgroundBackup::RETURN_TYPE_END_PROCESSS], true))
          {
             return $return;
          }

          if (!One_Click_Migration::$process_backup_single->time_exceeded()) {
            self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_UPLOAD);
            if (is_string($zip_filepath)) {
                @unlink($zip_filepath);
            }
            return false;
          }

          if (One_Click_Migration::$process_backup_single->time_exceeded()) {
            $next_retry_count = get_option('ocm_backup_upload_retry_db', 1);
            if($next_retry_count && $next_retry_count <= self::MAX_TRANSFER_RETRIES){
              One_Click_Migration::write_to_log("Process is Restarting");
              update_option('ocm_backup_upload_retry_db', $next_retry_count, true);
              One_Click_Migration::$process_backup_single->restart_task();
            }else{

              One_Click_Migration::write_to_log(sprintf('Skipping DB upload'));
              self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_UPLOAD);
              self::capture_failed_folder($skipped_restore_files, 'db', 'ocm_skipped_folders');
              One_Click_Migration::write_to_log("Notice: File DB was skipped due to timeout");
            }

          }

        }

    }

    public static function createZipFile($filepath, $aes_key, $presigned_urls)
    {
        // Suppress PHP 8+ compatibility warnings from vendor library
        $old_error_reporting = error_reporting();
        error_reporting($old_error_reporting & ~E_DEPRECATED & ~E_NOTICE);

        // Reset time limit for DB zip creation
        @set_time_limit(One_Click_Migration::get_timeout());

        $filename = OCM_PLUGIN_WRITABLE_PATH . 'db.zip';
        $skipped_restore_files = get_option('ocm_skipped_folders', []);

        if (!self::create_native_zip_from_directory($filepath, $filename)) {
            $zip = new ZipFile();
            $dir = new DirectoryIterator($filepath);
            foreach ($dir as $fileinfo) {
                if (!$fileinfo->isDot()) {
                    $zip->addFile($fileinfo->getPathname(), '/' . $fileinfo->getFilename(), ZipCompressionMethod::STORED);
                }
            }

            try {
                $zip->saveAsFile($filename);
            } catch (ZipException $e) {
                One_Click_Migration::write_to_log(sprintf('Error: %s', $e->getMessage()));

                update_option('ocm_is_stopped', true, true);
                exit;
            }
            $zip->close();
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
              update_option('ocm_is_stopped', true, true);
              exit;
          }

          if (One_Click_Migration::$process_backup_single->time_exceeded()) {
            $next_retry_count = get_option('ocm_backup_encrypt_retry_db', 1);

            if($next_retry_count && $next_retry_count <= self::MAX_STEP_RETRIES){
              One_Click_Migration::write_to_log("Process is Restarting");
              update_option('ocm_backup_encrypt_retry_db', $next_retry_count, true);
              One_Click_Migration::$process_backup_single->restart_task();
            }
            else{
              One_Click_Migration::write_to_log(sprintf('Skipping DB Encrypting'));
              One_Click_Migration::write_to_log(sprintf('Skipping DB upload'));
              self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_ENCRYPT);
              self::set_complete_backup_step('db', self::STEP_BACKUP_CHILD_UPLOAD);
              self::capture_failed_folder($skipped_restore_files, 'db', 'ocm_skipped_folders');
              One_Click_Migration::write_to_log("Notice: File DB was skipped due to timeout");

            }

          }
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

        // Suppress PHP 8+ compatibility warnings from vendor library
        $old_error_reporting = error_reporting();
        error_reporting($old_error_reporting & ~E_DEPRECATED & ~E_NOTICE);
        
        // Initialize archive object
        $zip = new ZipFile();
        $filename = sprintf('%s%s.zip', OCM_PLUGIN_WRITABLE_PATH, $folder_name);
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
            $free_bytes = self::get_free_disk_space(OCM_PLUGIN_WRITABLE_PATH);
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
                    /** @var SplFileInfo[] $files */
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($folder_path, \FilesystemIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );

                    foreach ($files as $name => $file) {
                        if (!$file->isDir()) {
                            $filePath = $file->getRealPath();
                            if (!$filePath || !is_readable($filePath)) {
                                continue;
                            }
                            $relativePath = str_replace('\\', '/', substr($filePath, strlen($folder_path) + 1));
                            $zip->addFile($filePath, $relativePath, ZipCompressionMethod::STORED);
                        }
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

            if (!One_Click_Migration::$process_backup_single->time_exceeded()) {
              self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_COMPRESS);

              One_Click_Migration::write_to_log(sprintf('%s ZIP has been created.', $folder_name));

            }

            if (One_Click_Migration::$process_backup_single->time_exceeded()) {
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
                update_option('ocm_is_stopped', true, true);
                exit;
            }

            // CRITICAL: Always save the zip file path immediately after encryption succeeds,
            // regardless of time_exceeded status. This prevents "Zip file path is empty" errors
            // when the process restarts and tries to upload.
            self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ZIP_FILE_PATH, $zip_filepath);

            if (!One_Click_Migration::$process_backup_single->time_exceeded()) {
              self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ENCRYPT);
              One_Click_Migration::write_to_log(sprintf('%s ZIP has been encrypted.', $folder_name));
              // The upload only needs the encrypted copy.
              @unlink($filename);
            }

            if (One_Click_Migration::$process_backup_single->time_exceeded()) {
              $next_retry_count = get_option('ocm_backup_encrypt_retry_' . $folder_name, 1);
              if($next_retry_count && $next_retry_count <= self::MAX_STEP_RETRIES){
                One_Click_Migration::write_to_log("Process is Restarting");
                update_option('ocm_backup_encrypt_retry_' . $folder_name, $next_retry_count, true);
                One_Click_Migration::$process_backup_single->restart_task();

              }else{

                One_Click_Migration::write_to_log(sprintf('Skipping ' .$folder_name.  ' Encypting'));
                One_Click_Migration::write_to_log(sprintf('Skipping ' .$folder_name.  ' Upload'));
                self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ENCRYPT);
                self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_UPLOAD);
                self::capture_failed_folder($skipped_restore_files, $folder_name, 'ocm_skipped_folders');
                One_Click_Migration::write_to_log("Notice: File $folder_name was skipped due to timeout");
              }

            }

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
            }
          }

        }
        if (!self::check_is_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_UPLOAD)) {
            $zip_filepath = self::get_backup_step_value($folder_name, self::STEP_BACKUP_CHILD_ZIP_FILE_PATH);

            if (empty($zip_filepath)) {
                // Construct the expected path as fallback
                $expected_path = sprintf('%s%s.zip.crypt', OCM_PLUGIN_WRITABLE_PATH, $folder_name);
                if (file_exists($expected_path)) {
                    One_Click_Migration::write_to_log(sprintf(' SYSLOG: %s zipFilePath was empty but found encrypted file at expected path', $folder_name));
                    $zip_filepath = $expected_path;
                    self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_ZIP_FILE_PATH, $zip_filepath);
                } else {
                    One_Click_Migration::write_to_log(sprintf(' SYSLOG: %s upload skipped - encrypted zip file not found at %s', $folder_name, $expected_path));
                    error_reporting($old_error_reporting);
                    return false;
                }
            }

            One_Click_Migration::write_to_log(sprintf('%s folder upload has been started.', $folder_name));
            $return = OCM_S3::upload_zip($zip_filepath);

            if (in_array($return, [OCM_BackgroundBackup::RETURN_TYPE_STOP_PROCESSS, OCM_BackgroundBackup::RETURN_TYPE_END_PROCESSS], true)) {
                error_reporting($old_error_reporting);
                return $return;
            }

            if (!One_Click_Migration::$process_backup_single->time_exceeded()) {
              self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_UPLOAD);
              if (is_string($zip_filepath)) {
                  @unlink($zip_filepath);
              }

              error_reporting($old_error_reporting);
              return false;
            }

            if (One_Click_Migration::$process_backup_single->time_exceeded()) {
                $next_retry_count = get_option('ocm_backup_upload_retry_' . $folder_name, 1);
                if($next_retry_count && $next_retry_count <= self::MAX_TRANSFER_RETRIES){
                  One_Click_Migration::write_to_log("Process is Restarting");

                  update_option('ocm_backup_upload_retry_' . $folder_name, $next_retry_count, true);
                  One_Click_Migration::$process_backup_single->restart_task();
                }else{

                  One_Click_Migration::write_to_log(sprintf('Skipping ' .$folder_name.  ' Uploading'));
                  self::set_complete_backup_step($folder_name, self::STEP_BACKUP_CHILD_UPLOAD);
                  self::capture_failed_folder($skipped_restore_files, $folder_name, 'ocm_skipped_folders');
                  One_Click_Migration::write_to_log("File $folder_name was skipped due to timeout");
                }

            }
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

        if (is_dir($directory)) {
            $deleted = self::deleteDirectoryBestEffort($directory);
            if (!$deleted || is_dir($directory)) {
                One_Click_Migration::write_to_log(sprintf(
                    'Notice: Could not fully remove old %s directory; merging restored files over the existing directory.',
                    $key
                ));
            }
        }

        if (!is_dir($directory) && @rename($extract_path, $directory)) {
            return true;
        }

        if (!self::copyDirectory($extract_path, $directory)) {
            return false;
        }

        self::deleteDirectoryBestEffort($extract_path);
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

    private static function prepare_restore_workspace($content_dir)
    {
        if (is_dir($content_dir) && !self::deleteDirectoryBestEffort($content_dir)) {
            return false;
        }

        return @mkdir($content_dir, 0700, true) || is_dir($content_dir);
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
        if (!class_exists('ZipArchive')) {
            return false;
        }

        $zip = new ZipArchive();
        $opened = $zip->open($filename, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            return false;
        }

        $base_length = strlen(rtrim($folder_path, '/\\')) + 1;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($folder_path, \FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                continue;
            }

            $file_path = $file->getRealPath();
            if (!$file_path || !is_readable($file_path)) {
                continue;
            }

            $relative_path = str_replace('\\', '/', substr($file_path, $base_length));
            if (!$zip->addFile($file_path, $relative_path)) {
                $zip->close();
                @unlink($filename);
                return false;
            }

            if (defined('ZipArchive::CM_STORE')) {
                $zip->setCompressionName($relative_path, ZipArchive::CM_STORE);
            }
        }

        return $zip->close();
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

    public static function encryptZipFile($filename, $key)
    {
        // open file to read
        if (false === ($file_handle = fopen($filename, 'rb'))) {
            One_Click_Migration::write_to_log(sprintf('Error: Failed to open file "%s"', $filename));
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
            One_Click_Migration::write_to_log(sprintf('Error: Failed to open file "%s"'));
            return false;
        }

        // loop around the file
        while ($data_encrypted < $file_size) {


            // read buffer-sized amount from file
            if (false === ($file_part = fread($file_handle, $buffer_size))) {
                One_Click_Migration::write_to_log(sprintf('Error: read buffer-sized amount from file "%s"'));
                return false;
            }

            // check to ensure padding is needed before encryption
            $length = strlen($file_part);
            if (0 !== $length % 16) {
                $pad = 16 - ($length % 16);
                $file_part = str_pad($file_part, $length + $pad, chr($pad));
            }

            $encrypted_data = $rijndael->encrypt($file_part);

            if (false === fwrite($encrypted_handle, $encrypted_data)) {
                One_Click_Migration::write_to_log(sprintf('Error: Encrypted data from file "%s"', $file_part));
                return false;
            }

            $data_encrypted += $buffer_size;
        }

        // close the main file handle
        fclose($encrypted_handle);
        fclose($file_handle);

        // encrypted path
        $result_path = $filename . '.crypt';

        // need to replace original file with tmp file
        if (false === @rename($encrypted_path, $result_path)) {
            One_Click_Migration::write_to_log(sprintf('Error: Failed rename failed: "%s" -> "%s"', $encrypted_path, $result_path));
            return false;
        }

        return $result_path;
    }

    public static function decryptZipFile($filename, $key)
    {
        // open file to read
        if (false === ($file_handle = fopen($filename, 'rb'))) {
            return false;
        }

        $decrypted_path = dirname($filename) . '/decrypt_' . basename($filename);
        // open new file from new path
        if (false === ($decrypted_handle = fopen($decrypted_path, 'wb+'))) {
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

            if (false === fwrite($decrypted_handle, $decrypted_data, $write_bytes)) {
                return false;
            }
            $bytes_decrypted += $buffer_size;
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
     *
     * Only runs in REST/ajax context — trigger_processing() ends its request with
     * wp_die(), which is fine for a poll but would blank an admin page render.
     */
    private static function maybe_revive_stalled_worker($is_stopped)
    {
        if ($is_stopped) {
            return;
        }

        if (!(defined('REST_REQUEST') && REST_REQUEST) && !wp_doing_ajax()) {
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
        // On hosts where the loopback gate already failed there is no healthy
        // dispatch chain to wait for, so stale means stalled much sooner.
        $threshold = get_option('ocm_dispatch_blocked') ? 25 : 45;

        $heartbeat = (int) get_option('ocm_worker_heartbeat', 0);
        if (0 === $heartbeat) {
            // First sighting of this run: start the clock instead of assuming a stall.
            One_Click_Migration::worker_heartbeat();
            return;
        }
        if (($now - $heartbeat) <= $threshold) {
            return;
        }

        // Throttle so a burst of concurrent polls produces a single pump.
        $last_pump = (int) get_option('ocm_worker_watchdog_last', 0);
        if (($now - $last_pump) <= 20) {
            return;
        }
        update_option('ocm_worker_watchdog_last', $now, false);

        $revivals = (int) get_option('ocm_worker_revival_count', 0) + 1;
        update_option('ocm_worker_revival_count', $revivals, false);

        One_Click_Migration::write_to_log(sprintf(
            ' SYSLOG: Worker heartbeat is %d seconds old; resuming via direct processing (revival #%d)',
            $now - $heartbeat,
            $revivals
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
        $lastLotNoSkip = self::get_last_log_message(false);

        $uploadFileData = get_option('ocm_upload_file');
        $isStopped = get_option('ocm_is_stopped');

        if ($isStopped) { // cancel all bg process
            One_Click_Migration::cancel_all_process();
        }

        self::maybe_revive_stalled_worker($isStopped);

        if ($lastLog && $lastLotNoSkip === self::LOG_MESSAGE_BG_PROCESS_RESTARTING_LOG) {

            return [
                'text' => $lastLog,
                'value' => self::get_previous_log_percentage(),
                'customNotice' => self::LOG_MESSAGE_BG_PROCESS_RESTARTING_LOG,
                'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
            ];
        }

        if ($lastLog && $lastLotNoSkip === self::LOG_MESSAGE_BG_PROCESS_RESTARTING) {

            return [
                'text' => $lastLog,
                'value' => self::get_previous_log_percentage(),
                'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
            ];
        }

        if ($lastLog && $lastLotNoSkip === "Process is Restarting") {

            return [
                'text' => 'Process is Restarting',
                'value' => self::get_previous_log_percentage(),
                'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
            ];
        }

        if ($lastLog && strpos($lastLog, 'was skipped due to timeout') !== false) {


            return [
                'text' => $lastLog,
                'value' => self::get_previous_log_percentage(),
                'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
            ];
        }


        $action_in_flight = get_option('ocm_action_start_backup') || get_option('ocm_action_start_restore');
        if ($action_in_flight) {
            $action_label = get_option('ocm_action_start_restore') ? 'restore' : 'backup';
            $default_text_value = sprintf('Starting %s&hellip;', $action_label);
        } else {
            $default_text_value = 'Start a backup or a restore to see current progress here.</br></br>Entire process runs in the background, independent of your browser activity.</br> </br>If you get logged out during restore, log back in using your backup old WordPress credentials and refresh this page for progress.';
        }

        $default_text = array(
            'text' => $default_text_value,
            'value' => '1%',  // Changed from 0% to 1% to prevent initial progress bar jump from 1% → 0% → 1%
            'debugLogLine' => $lastLog,  // Add the unmapped log line for debugging
            'wpDebug' => defined('WP_DEBUG') && WP_DEBUG,  // Add WP_DEBUG flag for conditional console logging
            'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
        );

        $multipart_pct = self::get_multipart_upload_percentage($lastLog);
        if ($multipart_pct !== false) {
            $progress_item_text = array(
                'uploadFileData' => $uploadFileData,
                'text' => $lastLog,
                'value' => $multipart_pct,
                'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
            );

            if ($isStopped) {
                $progress_item_text['isStopped'] = true;
            }

            return $progress_item_text;
        }

        foreach (self::$progress_data as $item_key => $progress_item) {


            if (isset($progress_item[0]) && strpos($lastLog, $progress_item[0]) !== false) {

                if (preg_match('/Notice:/', $lastLog)) {
                    $progress_item_text = array(
                        'uploadFileData' => $uploadFileData,
                        'text' => $lastLog,
                        'value' => $progress_item[1],
                        'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
                    );
                } else {
                    $progress_item_text = array(
                        'uploadFileData' => $uploadFileData,
                        'text' => $lastLog,
                        'value' => $progress_item[1],
                        'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
                    );
                }

                if ($isStopped && $lastLog !== 'Restore completed.') {
                    $progress_item_text = array(
                        'isStopped' => true,
                        'text' => $lastLog,
                        'value' => $progress_item[1],
                        'maintenanceMode' => (bool) get_option('ocm_maintenance_mode', false)
                    );
                }


                if ($lastLog === 'Downloading restore files. Please wait.') {
                    $progress_item_text['text'] .= '</br></br>This step could take a while depending on your backup size';

                } elseif ($lastLog === 'Plugins have been restored') {
                    $progress_item_text['text'] .= '</br></br>Around this step you might get logged out. Log back in using your old WordPress credentials and refresh this page for progress updates.';
                } elseif ($lastLog === 'Restore completed.') {
                    $progress_item_text['text'] .= '</br></br>Please check you website. If you have any issues please check our <a target="_blank" href="https://1clickmigration.com/faq/">FAQ page</a> or contact use via <a target="_blank" href="https://1clickmigration.com/contact-us/">this Contact Form</a>.</br>If you like this plugin please leave us a <a target="_blank" href="https://wordpress.org/plugins/1-click-migration/#reviews">review</a>.';
                } elseif ($lastLog === 'Backup completed.') {
                    $bucket_id = (string) get_option('ocm_bucket_id', '');
                    $bucket_line = $bucket_id !== ''
                        ? '<br>Backup ID: <code>' . esc_html($bucket_id) . '</code>'
                        : '';
                    $progress_item_text['topBanner'] = '<strong>Check your email</strong> for the restore code from <strong>pr@1clickmigration.com</strong>. If you don\'t see it within a few minutes, check your <strong>spam/junk folder</strong> and mark messages from <code>pr@1clickmigration.com</code> as not spam so future restore codes arrive in your inbox.'
                        . $bucket_line;
                } elseif (strpos($lastLog, 'Backup completed with missing parts') === 0) {
                    $bucket_id = (string) get_option('ocm_bucket_id', '');
                    $bucket_line = $bucket_id !== ''
                        ? '<br>Backup ID: <code>' . esc_html($bucket_id) . '</code>'
                        : '';
                    $missing_label = esc_html(trim((string) substr($lastLog, strlen('Backup completed with missing parts:'))));
                    $progress_item_text['topBanner'] = '<strong>Backup finished, but it is INCOMPLETE.</strong> These parts could not be backed up and will NOT be restored: <strong>' . $missing_label . '</strong>.'
                        . '<br>A restore code was still emailed from <strong>pr@1clickmigration.com</strong> in case you want to migrate the rest.'
                        . '<br>To get a complete backup, ask your host to raise the PHP <code>max_execution_time</code>, then run the backup again.'
                        . $bucket_line;
                }
                $recovery_hint = self::get_recovery_hint_for_log($lastLog);
                if ($recovery_hint !== '') {
                    $progress_item_text['text'] .= '</br></br><strong>How to fix:</strong> ' . $recovery_hint;
                }
                return $progress_item_text;
            }
        }

        return $default_text;
    }

    private static function get_recovery_hint_for_log($log)
    {
        if (!is_string($log) || $log === '') {
            return '';
        }

        if (strpos($log, 'disk quota') !== false || strpos($log, 'Not enough disk space') !== false || strpos($log, 'Restore workspace is not writable') !== false) {
            return 'Free space or increase the hosting account disk quota, then run the restore again. If the hosting panel shows enough space, ask the host to check account quota or inode limits because PHP may report filesystem space instead of account quota.';
        }

        if (strpos($log, 'write permissions') !== false || strpos($log, 'could not be moved or copied') !== false || strpos($log, 'could not be extracted') !== false) {
            return 'Make sure WordPress can write to <code>wp-content</code>, <code>wp-content/plugins</code>, <code>wp-content/themes</code>, <code>wp-content/uploads</code>, and <code>wp-content/ocm_restore</code>, then retry the restore.';
        }

        if (strpos($log, 'Restore failed while downloading') !== false) {
            return 'Create a fresh backup and restore with the new code. Backups expire after the retention window, and some hosts or firewalls can interrupt large downloads.';
        }

        if (strpos($log, 'Restore failed while decrypting') !== false || strpos($log, 'could not be opened for extraction') !== false) {
            return 'Retry after freeing space. If it fails again, create a fresh backup because the downloaded archive may be incomplete.';
        }

        if (strpos($log, 'Database restore failed while importing') !== false || strpos($log, 'Restore failed during database import') !== false || strpos($log, 'DB Restore') !== false) {
            return 'The database import failed on a SQL file. If <code>proc_open</code> is disabled, ask the host to enable it so the restore can use the MySQL CLI path. If that is not possible, export a new backup after cleaning invalid or binary data stored in options by third-party plugins.';
        }

        if (strpos($log, 'Backup worker dispatch failed') !== false || strpos($log, 'admin-ajax loopback check failed') !== false || strpos($log, 'blocks loopback requests') !== false) {
            return 'Ask the host to allow WordPress loopback requests to <code>wp-admin/admin-ajax.php</code>. Security plugins, WAF rules, basic auth, or firewall settings can block the background worker.';
        }

        if (strpos($log, 'was skipped due to timeout') !== false || strpos($log, 'Backup completed with missing parts') !== false) {
            return 'Parts get skipped when the host repeatedly hits its PHP execution time limit. Ask the host to raise <code>max_execution_time</code> (or temporarily reduce the folder size), then run the backup again so every part is included.';
        }

        if (strpos($log, 'Could not register backup') !== false || strpos($log, 'endpoint') !== false) {
            return 'Check outbound HTTPS access from the server and try again. If outbound requests are blocked, ask the host to allow connections to 1Click Migration endpoints.';
        }

        return '';
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

    private static function folderSize($dir)
    {
        $size = 0;
        foreach (glob(rtrim($dir, '/') . '/*', GLOB_NOSORT) as $each) {
            $size += is_file($each) ? filesize($each) : self::folderSize($each);
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

        $free = self::get_free_disk_space(OCM_PLUGIN_WRITABLE_PATH);

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
     * decrypted (crypt deleted right after), extracted into ocm_restore/ (zip
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

        $free = self::get_free_disk_space(OCM_PLUGIN_WRITABLE_PATH);
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

    private static function ensure_temporary_directory()
    {
        if (is_dir(OCM_PLUGIN_WRITABLE_PATH)) {
            if (is_writable(OCM_PLUGIN_WRITABLE_PATH)) {
                return true;
            }

            One_Click_Migration::write_to_log(sprintf(
                'Error: Temporary directory "%s" is not writable.',
                OCM_PLUGIN_WRITABLE_PATH
            ));
            return false;
        }

        if (@mkdir(OCM_PLUGIN_WRITABLE_PATH, 0700, true) || is_dir(OCM_PLUGIN_WRITABLE_PATH)) {
            return true;
        }

        One_Click_Migration::write_to_log(sprintf(
            'Error: Temporary directory "%s" could not be created. Please check write permissions for "%s".',
            OCM_PLUGIN_WRITABLE_PATH,
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

    private static function get_restore_transfer_timeout()
    {
        $timeout = max(20, One_Click_Migration::get_timeout());
        if (is_object(One_Click_Migration::$process_restore_single)
            && method_exists(One_Click_Migration::$process_restore_single, 'remaining_time')) {
            $remaining = (int) One_Click_Migration::$process_restore_single->remaining_time();
            if ($remaining > 10) {
                $timeout = max(10, min($timeout, $remaining - 5));
            }
        }

        return $timeout;
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

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        fclose($handle);
        clearstatcache(true, $partial_path);

        if ($http_code === 404) {
            return new WP_Error('http_404', 'Not Found');
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
                return new WP_Error('ocm_partial_download', sprintf(
                    'Partial download saved for "%s.zip.crypt" (%s downloaded%s).',
                    $key,
                    self::convertToReadableSize($downloaded),
                    $expected_size > 0 ? ' of ' . self::convertToReadableSize($expected_size) : ''
                ));
            }

            return new WP_Error('http_request_failed', $error ? $error : sprintf('HTTP %d', $http_code));
        }

        if ($http_code < 200 || $http_code >= 300) {
            return new WP_Error('http_request_failed', sprintf('HTTP %d', $http_code));
        }

        if ($expected_size > 0 && $downloaded < $expected_size) {
            return new WP_Error('ocm_partial_download', sprintf(
                'Partial download saved for "%s.zip.crypt" (%s downloaded of %s).',
                $key,
                self::convertToReadableSize($downloaded),
                self::convertToReadableSize($expected_size)
            ));
        }

        if (!@rename($partial_path, $target_path)) {
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

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if (OCM_S3::send_restore_code_email($email, $bucket_id, $restore_code, get_site_url(), $missing_parts)) {
                delete_option('ocm_restore_code_plain');
                return true;
            }

            if ($attempt < 3) {
                One_Click_Migration::write_to_log("Retrying restore-code email (attempt $attempt/3)...");
                sleep(2);
            }
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


            $line_array = explode('- ', $line);

            $line = $line_array[count($line_array) - 1];

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

          $line_array = explode('- ', $line);


          $line = $line_array[count($line_array) - 1];
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

    private static function restore_db($presigned_urls, wpdb $wpdb)
    {

        OCM_SQL_Utils::check_proc_available(false, $presigned_urls);

        // Get current site URL here
        $current_url = site_url();
        $dbfiles = WP_CONTENT_DIR . '/ocm_restore/db/';
        $excluded_folders = get_option( 'ocm_excluded_folders', []);



        //cater for the database restore.
        if(!in_array('db', $excluded_folders)){

          if (!is_dir($dbfiles)) {
              One_Click_Migration::write_to_log(sprintf('Error: DB directory "%s" does not exist in unzipped files.', $dbfiles));
              return false;
          }
        }

        // Only drop tables that match the destination prefix.
        // Each SQL file already contains DROP TABLE IF EXISTS for its own table,
        // so we only need to clean up destination-prefix tables that might not
        // have a corresponding file in the backup (e.g. plugin tables that existed
        // on destination but not on source).
        $tables = $wpdb->get_results('SHOW TABLES');
        foreach ($tables as $table) {
            foreach ($table as $table_name) {
                if (strpos($table_name, $wpdb->base_prefix) === 0) {
                    $wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table_name) . '`');
                }
            }
        }

        $dir = new DirectoryIterator($dbfiles);
        $found_files = array();
        foreach ($dir as $fileinfo) {
            if (!$fileinfo->isDot()) {
                $found_files[] = $fileinfo->getPathname();
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

        foreach ($sorted_table_list as $filepath) {
            OCM_SQL_Utils::swap_table_prefix($filepath);
            if (!OCM_SQL_Utils::import($filepath)) {
                One_Click_Migration::write_to_log(sprintf('Error: Database restore failed while importing "%s".', basename($filepath)));
                return false;
            }

            if (strpos($filepath, 'options.sql') !== false) {
                $db_url = $wpdb->get_results("SELECT option_value FROM {$wpdb->prefix}options WHERE option_name = 'siteurl'");

                $table_name = "{$wpdb->prefix}options";

                if (empty($db_url) || empty($db_url[0]->option_value)) {
                    One_Click_Migration::write_to_log(sprintf('Error: Database restore failed because table "%s" does not contain the siteurl option.', $table_name));
                    return false;
                }

                self::$restore_site_url = $db_url[0]->option_value;

                if ($current_url !== self::$restore_site_url) {
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
            }
        }
        // Clear any stale background process queues imported from the source site's database
        // to prevent them from firing on the destination site after restore.
        OCM_BackgroundHelper::delete_all_batch_process();

        One_Click_Migration::write_to_log('Database has been restored');
        return true;
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
        $restore_plugins = glob(WP_CONTENT_DIR . '/ocm_restore/plugins/*', GLOB_ONLYDIR) ?: array();
        foreach ($restore_plugins as $plugin) {
            if ($plugin === WP_CONTENT_DIR . '/ocm_restore/plugins/1-click-migration') {
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

    public static function restart_failed_process(){
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to perform this action.');
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ocm_restart_failed_process')) {
            wp_die('Security check failed. Please try again.');
        }

        if(isset($_POST['process'])){
            $process = sanitize_text_field($_POST['process']);

            One_Click_Migration::write_to_log("Process is Restarting");
            if($process == 'backup'){
                if (One_Click_Migration::get_backup_plugin_isolation_token() === '') {
                    One_Click_Migration::start_backup_plugin_isolation();
                }
                One_Click_Migration::$process_backup_single->restart_task();
            }

            if($process == 'restore'){
                One_Click_Migration::$process_restore_single->restart_task();
            }
        }
        wp_send_json_success("success");

    }


    public static function delete_restore_options(){
      delete_option('ocm_force_deactivated_plugins');

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

    }

    public static function delete_backup_options(){
      // Clean up transient backup state from previous runs.
      delete_option('ocm_force_deactivated_plugins');

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


    }

    public static function capture_failed_folder($excluded_restore_files, $key, $option){

      array_push($excluded_restore_files, $key);
      $excluded_restore_files = array_unique($excluded_restore_files);
      update_option( $option, $excluded_restore_files);
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
        $missing = array();
        foreach ((is_array($skipped) ? $skipped : array()) as $part) {
            if (is_string($part) && $part !== '') {
                $missing[] = strtolower($part);
            }
        }
        $missing = array_values(array_unique($missing));
        sort($missing);
        return $missing;
    }

    /**
     * Write the per-part status marker into the log. write_to_log mirrors the
     * whole log to S3, so the restore side can read this back before it starts
     * downloading (see One_Click_Migration::extract_missing_parts_from_backup_log).
     */
    public static function log_backup_parts_manifest($missing_parts)
    {
        $bucket_id = (string) get_option('ocm_bucket_id', '');
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
