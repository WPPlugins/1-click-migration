<?php

/**
 * Plugin Name: 1 Click Migration
 * Plugin URI: https://wordpress.org/plugins/1-click-migration/
 * Description: Migrate, copy, or clone your entire site with 1 click. <strong>Any host, no size limitation.</strong> $9.99 per migration, success guaranteed or a refund.
 * Version: 3.3.1
 * Requires at least: 5.3
 * Requires PHP: 7.4
 * Author: 1ClickMigration
 * Author URI: https://1clickmigration.com/
 * Text Domain: 1-click-migration
 */

namespace OCM;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

if (!defined('WPINC')) {
    die;
}

define('OCM_PLUGIN_ROOT', plugin_dir_path(__FILE__));
define('OCM_PLUGIN_WRITABLE_PATH', WP_CONTENT_DIR . '/tmp/');
define('OCM_MAIN_FILE', __FILE__);
define('OCM_SET_TIME_LIMIT', 900);
define('OCM_WORKER_TIME_BUDGET', 20);

require_once __DIR__ . '/inc/handler/class-ocm-private-dir.php';

define('OCM_DEBUG_LOG_FILENAME', 'ocm_debug.log');
// This site's private directory (log, restore worker lock), inside the
// .ocm-private shared with sibling installs.
define('OCM_DEBUG_LOG_DIR', OCM_Private_Dir::site_dir_for(ABSPATH));
define('OCM_DEBUG_LOG_FILE', OCM_DEBUG_LOG_DIR . '/' . OCM_DEBUG_LOG_FILENAME);
define('OCM_DEBUG_LOG_FILE_URL', '');
define('OCM_WP_CONFIG_FILE', ABSPATH . 'wp-config.php');
define('OCM_API_ENDPOINT', 'https://1clickmigration.com/api/');

require 'vendor/autoload.php';

require_once __DIR__ . '/inc/admin/class-admin-page.php';
require_once __DIR__ . '/inc/backup/class-ocm-backup.php';
require_once __DIR__ . '/inc/s3/class-ocm-s3.php';
require_once __DIR__ . '/inc/db/class-ocm-db-import.php';
require_once __DIR__ . '/inc/db/class-ocm-db.php';
require_once __DIR__ . '/inc/db/class-ocm-extra-domains.php';
require_once __DIR__ . '/inc/db/class-ocm-url-manifest.php';
require_once __DIR__ . '/inc/backup/class-ocm-restore-precheck.php';
require_once __DIR__ . '/inc/handler/class-error-handler.php';
require_once __DIR__ . '/inc/handler/class-ocm-debug-mode.php';

// If the function it's not available, require it.
if ( ! function_exists( 'download_url' ) ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
}

class One_Click_Migration
{
    const MU_PLUGIN_ISOLATION_FILE = 'ocm-plugin-isolation.php';
    const BACKUP_ISOLATION_TOKEN_OPTION = 'ocm_backup_isolation_token';
    const BACKUP_ISOLATION_TOKEN_HASH_OPTION = 'ocm_backup_isolation_token_hash';
    const BACKUP_ISOLATION_ALLOWED_PLUGINS_OPTION = 'ocm_backup_isolation_allowed_plugins';

    /**
     * Seconds between two S3 copies of the debug log from one request
     * (filter: ocm_log_upload_interval; 0 uploads on every line). Each copy
     * PUTs the whole log, about 0.3-0.5 s on a typical host.
     */
    const LOG_UPLOAD_INTERVAL = 10;

    /**
     * Lines that go to S3 as soon as they are written: the terminal markers
     * 1CmSyncers/monitor_lib.php alerts on (latestTerminalEvent()). Every line
     * after one of them in the same request goes at once too: the monitor reads
     * the lines that follow it (review_ask: none (files-only) / (stopped)).
     */
    const LOG_UPLOAD_NOW_MARKERS = array(
        'Backup completed',
        'Backup failed:',
        'Restore completed',
        'Restore stopped',
        'Restore failed:',
        'Restore finished without:',
    );

    /** @var OCM_BackgroundBackup */
    public static $process_backup_single;

    /**@var OCM_BackgroundRestore */
    public static $process_restore_single;

    /** @var int Last transfer_heartbeat() write, for its 10-second throttle. */
    private static $last_transfer_heartbeat = 0;

    /** @var string Presigned log URL this request last uploaded the log to. */
    private static $log_uploaded_url = '';

    /** @var float microtime(true) when that upload finished. */
    private static $log_uploaded_at = 0.0;

    /**
     * @var array|null Lines written but not yet in S3: the log URL, and the
     *                 local log's size and first bytes when they were written.
     */
    private static $log_upload_pending = null;

    /** @var int 0 = no shutdown flush registered, 1 = registered, 2 = running. */
    private static $log_shutdown_state = 0;

    /** @var bool This request logged a LOG_UPLOAD_NOW_MARKERS line. */
    private static $log_terminal_written = false;

    /** @var string */
    private static $version;

    /** @var int */
    private static $default_max_execution_time;

    /** @var int */
    private static $current_max_execution_time;

    private static $error_handler;

    public static $is_stop_and_reset;

    public function __construct()
    {
        // Move an upgrade-era public plugin log before any admin page or new
        // migration write can expose/use it.
        if (is_file(OCM_PLUGIN_ROOT . OCM_DEBUG_LOG_FILENAME)) {
            self::ensure_private_log_directory();
        }

        $this->fire_hooks();
        $this->get_version_from_php_doc();

        register_activation_hook(__FILE__, array($this, 'install'));
        register_deactivation_hook(__FILE__, array($this, 'uninstall'));

        self::set_current_max_execution_time();

    }

    public function fire_hooks()
    {
        add_action('admin_menu', array(OCM_Admin_Page::class, 'add_admin_page'));
        add_action('admin_init', array(__CLASS__, 'register_settings'));
        add_action('admin_init', array(OCM_Backup::class, 'remove_legacy_restore_workspace'));
        // add_action('admin_init', array(__CLASS__, 'ensure_mu_plugin_isolation_shim')); // disabled while debugging restore
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_styles'));
        add_action('template_redirect', array(__CLASS__, 'handle_maintenance_mode'));
        add_action('admin_post_start_backup', array(OCM_Backup::class, 'start_backup'));
        add_action('wp_ajax_ocm_loopback_check', array(__CLASS__, 'loopback_check'));
        add_action('wp_ajax_nopriv_ocm_loopback_check', array(__CLASS__, 'loopback_check'));
        add_action('wp_ajax_ocm_prepare_restore', array(OCM_Restore_Precheck::class, 'prepare'));
        add_action('wp_ajax_ocm_apply_extra_domains', array(OCM_Backup::class, 'apply_extra_domains'));
        add_action('admin_post_start_restore', array(OCM_Backup::class, 'start_restore'));
        add_action(OCM_Backup::WORKER_WATCHDOG_HOOK, array(OCM_Backup::class, 'run_worker_watchdog'));
        add_action(OCM_Backup::RESTORE_WATCHDOG_HOOK, array(OCM_Backup::class, 'run_worker_watchdog'));
        add_action('admin_post_cancel_actions', array(OCM_Backup::class, 'cancel_actions'));
        add_action('admin_post_ocm_download_log', array(__CLASS__, 'download_log'));
        add_action('plugins_loaded', array(__CLASS__, 'init_background_jobs'));
        add_action('plugins_loaded', array(__CLASS__, 'init_handler'));
        add_action('rest_api_init', static function () {
            register_rest_route('ocm/v1', '/progress/', array(
                'methods' => 'GET',
                'callback' => array(One_Click_Migration::class, 'progress_endpoint'),
                'permission_callback' => function() {
                    return is_user_logged_in() && current_user_can('manage_options');
                },
            ));
        });

        add_action( 'admin_notices', array(OCM_Backup::class, 'add_file_not_found_notice'));

        // Review ask (shared with the restore-success modal flags): permanent
        // dismiss handling on every admin screen + persistent admin notice.
        add_action('admin_init', array(__CLASS__, 'handle_review_dismiss'));
        add_action('admin_notices', array(__CLASS__, 'maybe_show_review_notice'));

    }

    public function get_version_from_php_doc()
    {
        if ($fp = fopen(__FILE__, 'rb')) {
            $file_data = fread($fp, 1024);
            if (preg_match("/Version: ([\d.]+)(\r|\n)/", $file_data, $matches)) {
                self::$version = $matches[1];
            }
            fclose($fp);
        }
    }

    public static function get_version()
    {
        return self::$version;
    }

    public static function init_background_jobs()
    {
        require_once __DIR__ . '/inc/background/class-background-backup.php';
        require_once __DIR__ . '/inc/background/class-background-restore.php';
        require_once __DIR__ . '/inc/background/class-background-helper.php';

        self::$process_backup_single = new OCM_BackgroundBackup();
        self::$process_restore_single = new OCM_BackgroundRestore();
    }

    public static function init_handler(){

      Error_Handler::init_error_handler();
      OCM_Debug_Mode::restore_if_active();
    }

    public static function register_settings()
    {
        // The admin page renders fields directly via class-admin-page.php; we only need
        // register_setting() so that options.php accepts the form submission for ocm_user_email.
        register_setting('one-click-migration', 'ocm_user_email');
    }

    public static function admin_styles($hook)
    {
        if ($hook !== 'tools_page_one-click-migration') {
            return;
        }

        wp_enqueue_style('custom_ocm_admin_css', plugins_url('css/admin-style.css', __FILE__), array(), self::$version . '.' . filemtime(__DIR__ . '/css/admin-style.css'));

        wp_enqueue_script('custom_ocm_admin_js', plugins_url('js/admin-script.js', __FILE__), array('jquery'), self::$version . '.' . filemtime(__DIR__ . '/js/admin-script.js'), true);
        $ocm_user_email = get_option('ocm_user_email');
        $user_email = '';
        $urlparts = parse_url(home_url());
		$domain = $urlparts['host'];

        if($ocm_user_email){
          $user_email = $ocm_user_email;
        }
        $timeout = One_Click_Migration::get_timeout();

        $other_active_count = OCM_Admin_Page::other_active_plugins_count();

        // Permanent dismiss of the in-plugin Review ask is handled on admin_init
        // (handle_review_dismiss), which runs before this hook, so the modal's
        // dismiss URL keeps working and the flags below already reflect it.

        $show_restore_success = false;
        $show_review_cta = false;
        $flag = (int) get_option('ocm_show_restore_success', 0);
        if ($flag > 0 && (time() - $flag) < DAY_IN_SECONDS * 2) {
            $show_restore_success = true;
            $show_review_cta = (bool) get_option('ocm_show_review_cta', false);
            delete_option('ocm_show_restore_success');
            delete_option('ocm_show_review_cta');
        } elseif (!empty($_GET['ocm_restore_done'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $show_restore_success = true;
            // Query-param smoke path: honor stored CTA flag if present, else no Review ask.
            $show_review_cta = (bool) get_option('ocm_show_review_cta', false);
            delete_option('ocm_show_restore_success');
            delete_option('ocm_show_review_cta');
        }
        // Never show Review CTA if permanently dismissed.
        if ((bool) get_option('ocm_review_ask_dismissed', false)) {
            $show_review_cta = false;
        }

        $extra_domains = OCM_Extra_Domains::public_rows(get_option(OCM_Extra_Domains::OPTION, array()));
        $show_restore_partial = (bool) get_option('ocm_restore_was_partial', false);
        $show_restore_warned = (bool) get_option(OCM_Backup::OPTION_RESTORE_WAS_WARNED, false);

        wp_localize_script('custom_ocm_admin_js', 'siteData', array(
            // _locale=user: the poll's help text in the admin's own language,
            // like the rest of this page (WordPress 5.0+; ignored before).
            'progressUrl' => add_query_arg('_locale', 'user', get_rest_url(null, 'ocm/v1/progress')),
            'userEmail' => $user_email,
            'domain' => $domain,
            'ajaxurl' => admin_url('admin-ajax.php'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'backupNonce' => wp_create_nonce('one_click_migration_start_backup'),
            'restoreNonce' => wp_create_nonce('one_click_migration_start_restore'),
            'prepareRestoreNonce' => wp_create_nonce('ocm_prepare_restore'),
            'otherPluginsActive' => $other_active_count,
            'pluginPageUrl' => admin_url('tools.php?page=one-click-migration'),
            'showRestoreSuccess' => $show_restore_success ? 1 : 0,
            'showRestorePartial' => $show_restore_partial ? 1 : 0,
            'showRestoreWarned' => $show_restore_warned ? 1 : 0,
            'showReviewCta' => $show_review_cta ? 1 : 0,
            'dismissReviewUrl' => wp_nonce_url(admin_url('tools.php?page=one-click-migration&ocm_dismiss_review=1'), 'ocm_dismiss_review'),
            'reviewUrl' => 'https://wordpress.org/plugins/1-click-migration/reviews/#new-post',
            'supportUrl' => OCM_Backup::SUPPORT_FORUM_URL,
            'extraDomains' => $extra_domains,
            'extraDomainsNonce' => wp_create_nonce('ocm_apply_extra_domains'),
            'i18n' => OCM_Admin_Page::script_strings(),
        ));
    }

    /**
     * Permanent dismiss of the Review ask (never ask again on this site).
     * Runs on admin_init so it works from any wp-admin screen; shared by the
     * restore-success modal ("Don't ask again" link) and the admin notice.
     */
    public static function handle_review_dismiss()
    {
        if (empty($_GET['ocm_dismiss_review']) || empty($_GET['_wpnonce']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'ocm_dismiss_review') // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        update_option('ocm_review_ask_dismissed', 1, false);
        update_option('ocm_show_review_cta', 0, false);
    }

    /**
     * Persistent admin notice asking for a review after the first clean restore.
     * Gated on ocm_clean_restore_count (never consumed) rather than
     * ocm_show_review_cta, which the restore-success modal deletes one-shot.
     */
    public static function maybe_show_review_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if ((int) get_option('ocm_clean_restore_count', 0) < 1) {
            return;
        }
        if ((bool) get_option('ocm_review_ask_dismissed', false)) {
            return;
        }

        $review_url  = 'https://wordpress.org/plugins/1-click-migration/reviews/#new-post';
        $dismiss_url = wp_nonce_url(add_query_arg('ocm_dismiss_review', '1'), 'ocm_dismiss_review');
        ?>
        <div class="notice notice-info ocm-review-notice">
            <p><?php esc_html_e('Your site was restored. If 1-Click Migration helped, leave a review on WordPress.org. You need a free WordPress.org account.', '1-click-migration'); ?></p>
            <p>
                <a class="button button-primary" href="<?php echo esc_url($review_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Leave a review', '1-click-migration'); ?></a>
                <a href="<?php echo esc_url($dismiss_url); ?>" style="margin-left: 12px;"><?php esc_html_e('Don\'t ask again', '1-click-migration'); ?></a>
            </p>
        </div>
        <?php
    }

    public static function install()
    {
        // Activation fires before plugins_loaded, so plugin infrastructure
        // (background jobs, S3, write_to_log) may not be available.
        // Do a direct, minimal cleanup that doesn't depend on any of that.

        // 1. Delete critical options via direct SQL first (before any plugin code runs)
        global $wpdb;
        $table = $wpdb->options;
        $wpdb->query("DELETE FROM {$table} WHERE option_name IN (
            'ocm_presigned_urls',
            'ocm_bucket_key',
            'ocm_bucket_id',
            'ocm_aes_key',
            'ocm_restore_code_plain',
            'ocm_action_start_backup',
            'ocm_action_start_restore',
            'ocm_is_stopped',
            'ocm_show_restore_success',
            'ocm_show_review_cta',
            'ocm_extra_domain_candidates',
            'ocm_restore_url_plan',
            'ocm_clean_restore_count',
            'ocm_review_ask_dismissed',
            'ocm_user_email',
            'ocm_user_password',
            'backup_steps',
            'restore_steps',
            'ocm_excluded_folders',
            'ocm_skipped_folders',
            'ocm_log_merged_bucket'
        )");

        // 2. Delete all remaining plugin options
        self::delete_options();

        // 3. Clean up background process batch rows and transients via direct SQL
        $wpdb->query("DELETE FROM {$table} WHERE option_name LIKE 'wp_ocm_background_%'");
        $wpdb->query("DELETE FROM {$table} WHERE option_name LIKE '_site_transient_wp_ocm_background_%'");
        $wpdb->query("DELETE FROM {$table} WHERE option_name LIKE '_site_transient_timeout_wp_ocm_background_%'");
        $wpdb->query("DELETE FROM {$table} WHERE option_name = 'backup_steps'");
        $wpdb->query("DELETE FROM {$table} WHERE option_name = 'restore_steps'");

        // 3. Delete the local debug log file
        $log_file = defined('OCM_DEBUG_LOG_FILE') ? OCM_DEBUG_LOG_FILE : (plugin_dir_path(__FILE__) . 'ocm_debug.log');
        if (file_exists($log_file)) {
            @unlink($log_file);
        }

        // 4. Disable maintenance mode if it was left on
        $maintenance_file = ABSPATH . '.maintenance';
        if (file_exists($maintenance_file)) {
            @unlink($maintenance_file);
        }

        // self::ensure_mu_plugin_isolation_shim(); // disabled while debugging restore
        self::remove_mu_plugin_isolation_shim();

        flush_rewrite_rules();
    }

    public static function delete_options(){
      // Clean up the setting, if there's an ocm_key in the table
      delete_option('ocm_user_email');
      delete_option('ocm_user_password');
      delete_option('ocm_is_stopped');
      delete_option('ocm_zip_failover_job');
      delete_option('ocm_action_start_backup');
      delete_option('ocm_action_start_restore');
      delete_option('backup_steps');
      delete_option('restore_steps');
      delete_option('ocm_presigned_urls');
      delete_option('ocm_upload_file');
      delete_option('ocm_download_auto_try_nb_db');
      delete_option('ocm_download_auto_try_nb_themes');
      delete_option('ocm_download_auto_try_nb_plugins');
      delete_option('ocm_download_auto_try_nb_uploads');
      delete_option('ocm_upload_auto_try_nb_db');
      delete_option('ocm_upload_auto_try_nb_themes');
      delete_option('ocm_upload_auto_try_nb_plugins');
      delete_option('ocm_upload_auto_try_nb_uploads');
      delete_option('ocm_excluded_folders');
      delete_option('ocm_skipped_folders');
      delete_option('ocm_log_download');
      delete_option('ocm_log_url');
      delete_option('ocm_bucket_key');
      delete_option('ocm_bucket_id');
      delete_option('ocm_aes_key');
      delete_option('ocm_restore_code_plain');
      delete_option('ocm_backup_price');
      delete_option('ocm_extra_domain_candidates');
      delete_option('ocm_restore_url_plan');
      delete_option('ocm_active_backup_run');
      delete_option('ocm_active_restore_run');
      delete_option('ocm_backup_uploaded_parts');
      self::clear_backup_plugin_isolation();
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

      // Clear additional options that might cause redirect loops
      delete_option('ocm_s3_bucket');
      delete_option('ocm_s3_region');
      delete_option('ocm_s3_access_key');
      delete_option('ocm_s3_secret_key');
      delete_option('ocm_backup_id');
      delete_option('ocm_backup_password');
      delete_option('ocm_backup_completed_steps');
      delete_option('ocm_restore_completed_steps');
      delete_option('ocm_maintenance_mode');

      delete_option('ocm_worker_heartbeat');
      delete_option('ocm_worker_watchdog_last');
      delete_option('ocm_worker_revival_count');
      delete_option('ocm_dispatch_blocked');
      delete_option('ocm_backup_missing_parts');
      delete_option('ocm_backup_excluded_parts');
      delete_option('ocm_backup_parts_known');

    }


    public static function uninstall()
    {
        // Use stop_and_reset for complete cleanup on deactivation
        self::stop_and_reset();
        self::remove_mu_plugin_isolation_shim();
    }
    
    /**
     * Recursively delete a directory without logging (for safe deactivation)
     * 
     * @param string $dir Directory path to delete
     * @return bool True on success, false on failure
     */
    private static function delete_directory_recursive($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }
        
        $items = @scandir($dir);
        if ($items === false) {
            return false;
        }
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $path = $dir . '/' . $item;
            
            if (is_dir($path)) {
                self::delete_directory_recursive($path);
            } else {
                @unlink($path);
            }
        }
        
        return @rmdir($dir);
    }


    /**
     * The debug log is mirrored to S3 and consumed by JSON APIs (Lightsail
     * get_logs/view_logs) and Mailgun alerts. A single binary/ciphertext blob
     * in one entry used to make json_encode() of the whole log return false,
     * so every entry must stay valid UTF-8, control-char free and short.
     * Never pass file contents, ciphertext or credentials to write_to_log.
     */
    public static function sanitize_log_message($string)
    {
        $string = (string) $string;

        // Strip control characters except tab/newline (CR normalised away).
        $string = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $string);

        // Cap runaway entries (a 2MB chunk dump is never useful in a log line).
        $max_bytes = 2000;
        if (strlen($string) > $max_bytes) {
            $string = substr($string, 0, $max_bytes) . ' [log entry truncated]';
        }

        // Replace invalid UTF-8 byte sequences so JSON consumers never choke.
        if (!preg_match('//u', $string)) {
            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($string, 'UTF-8', 'UTF-8');
                $string = is_string($converted) ? $converted : preg_replace('/[\x80-\xFF]+/', '?', $string);
            } elseif (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $string);
                $string = is_string($converted) ? $converted : preg_replace('/[\x80-\xFF]+/', '?', $string);
            } else {
                $string = preg_replace('/[\x80-\xFF]+/', '?', $string);
            }
        }

        return $string;
    }

    public static function write_to_log($string)
    {
      // sleep(1);
      $string = self::sanitize_log_message($string);
      $file = OCM_DEBUG_LOG_FILE;
      $urls = get_option('ocm_presigned_urls');
      if (!self::ensure_private_log_directory()) {
          error_log('[1-Click Migration] Could not create private log directory.');
          return;
      }

      if (preg_match('/Error:/', $string)) {
          self::reset_actions_start_mark();
      }

      // Log essential messages to WordPress debug.log
      if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
          $essential_patterns = [
              'Backup started',
              'Restore started', 
              'Restore completed',
              'Backup completed',
              'Error:',
              'Uploads have been restored',
              'Themes have been restored',
              'Database backup started',
              'Database has been restored'
          ];
          
          foreach ($essential_patterns as $pattern) {
              if (strpos($string, $pattern) !== false) {
                  error_log('[1-Click Migration] ' . $string);
                  break;
              }
          }
      }

      $timestamp = date('m/d/YTH:i:sa');
      $line = $timestamp . ' - ' . $string;

      if($urls){
        $log_url = is_object($urls) && !empty($urls->log) ? (string) $urls->log : '';

        // Lines held back for another log URL go there before this line is
        // appended, so that copy never carries lines written under this one.
        if (self::$log_upload_pending && self::$log_upload_pending['url'] !== $log_url) {
            self::flush_log_upload();
        }

        // If ocm_debug doesn't exist, make sure to grab the latest from S3 so we can append.
        // Download to memory first; only write the local file on 200 so a failed download
        // does not leave an empty file that suppresses later download attempts.
        if (isset($urls->log_download) && !file_exists($file)) {
            try {
                $client = new Client();
                $response = $client->get($urls->log_download, ['http_errors' => false]);

                if ($response->getStatusCode() === 200) {
                    $body = (string) $response->getBody();
                    if ($body !== '') {
                        file_put_contents($file, $body);
                    }
                }
            } catch (Exception $e) {
                // Presigned URL may have expired — leave no local file so the next
                // write_to_log call can retry the download once fresh URLs land.
                error_log('[1-Click Migration] Could not download existing log from S3: ' . $e->getMessage());
            }
        }

        $log_file = fopen($file, 'ab');
        fwrite($log_file, $line . PHP_EOL);
        fclose($log_file);

        // Every S3 copy PUTs the whole log, so copy at most every
        // LOG_UPLOAD_INTERVAL seconds; flush_log_upload() sends the rest.
        if ($log_url !== '') {
            if (self::log_upload_due($log_url, $string)) {
                self::upload_log($log_url, self::$log_shutdown_state !== 2);
            } else {
                self::defer_log_upload($log_url);
            }
        }

      }

      if(!$urls){
          if (self::$log_upload_pending) {
              self::flush_log_upload();
          }

          $log_file = fopen($file, 'ab');
          fwrite($log_file, $line . PHP_EOL);
          fclose($log_file);

      }

    }

    /**
     * Upload log lines write_to_log() held back. Called before a worker
     * dispatches the next one, before the restore code is emailed, before the
     * local log is deleted and at shutdown, so no line stays out of S3.
     */
    public static function flush_log_upload()
    {
        $pending = self::$log_upload_pending;
        if (!$pending) {
            return;
        }
        self::$log_upload_pending = null;

        // The log only grows during a run. A shorter file or other first bytes
        // mean another request deleted or restarted it: never send that log
        // to this run's URL.
        $file = OCM_DEBUG_LOG_FILE;
        clearstatcache(true, $file);
        if (!is_file($file)
            || (int) filesize($file) < $pending['size']
            || self::read_log_head($pending['head_len']) !== $pending['head']
        ) {
            return;
        }

        self::upload_log($pending['url'], false);
    }

    /** Shutdown callback. Lines logged after it ran (later shutdown callbacks) upload at once. */
    public static function flush_log_upload_on_shutdown()
    {
        self::$log_shutdown_state = 2;
        self::flush_log_upload();
    }

    private static function log_upload_due($url, $message)
    {
        foreach (self::LOG_UPLOAD_NOW_MARKERS as $marker) {
            if (strpos($message, $marker) !== false) {
                self::$log_terminal_written = true;
                return true;
            }
        }

        // The first line for a URL in a request uploads at once, as before:
        // that keeps the no_bucket redirect on the request's first write.
        if (self::$log_terminal_written
            || self::$log_shutdown_state === 2
            || $url !== self::$log_uploaded_url
        ) {
            return true;
        }

        $interval = function_exists('apply_filters')
            ? (float) apply_filters('ocm_log_upload_interval', self::LOG_UPLOAD_INTERVAL)
            : (float) self::LOG_UPLOAD_INTERVAL;

        return microtime(true) - self::$log_uploaded_at >= $interval;
    }

    private static function defer_log_upload($url)
    {
        $file = OCM_DEBUG_LOG_FILE;
        clearstatcache(true, $file);
        $size = (int) @filesize($file);

        if (!self::$log_upload_pending) {
            $head_len = min($size, 512);
            self::$log_upload_pending = array(
                'url' => $url,
                'head_len' => $head_len,
                'head' => self::read_log_head($head_len),
            );
        }
        self::$log_upload_pending['size'] = $size;

        if (self::$log_shutdown_state === 0) {
            self::$log_shutdown_state = 1;
            register_shutdown_function(array(__CLASS__, 'flush_log_upload_on_shutdown'));
        }
    }

    private static function read_log_head($length)
    {
        if ($length <= 0) {
            return '';
        }
        $fh = @fopen(OCM_DEBUG_LOG_FILE, 'rb');
        if (!$fh) {
            return false;
        }
        $head = (string) fread($fh, $length);
        fclose($fh);
        return $head;
    }

    /**
     * PUT the whole local log to the presigned log URL. A 404 means the bucket
     * is gone: drop the local log and, unless called from a flush, send the
     * admin to the no_bucket notice.
     */
    private static function upload_log($url, $may_redirect)
    {
        self::$log_upload_pending = null;
        $body = @file_get_contents(OCM_DEBUG_LOG_FILE);
        if ($body === false) {
            return;
        }

        try {
            $request = new Request(
                'PUT',
                $url,
                [],
                $body
            );

            $client = new Client();
            $client->send($request);
        } catch (Exception $e) {
            if ($e->getCode() === 404) {
                self::delete_log_file();
                if ($may_redirect) {
                    wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=no_bucket'));
                    exit;
                }
                return;
            }
            // Try again at the next upload, hand-over or shutdown.
            self::defer_log_upload($url);
        }

        self::$log_uploaded_url = $url;
        self::$log_uploaded_at = microtime(true);
    }

    public static function ensure_private_log_directory()
    {
        static $ready = false;
        if ($ready) {
            return true;
        }

        if (!OCM_Private_Dir::ensure(OCM_DEBUG_LOG_DIR)) {
            return false;
        }

        // The plugin-folder log was this site's own, so it moves in. The one
        // older versions kept in the shared .ocm-private root stays put.
        $legacy_file = OCM_PLUGIN_ROOT . OCM_DEBUG_LOG_FILENAME;
        if (is_file($legacy_file) && !is_file(OCM_DEBUG_LOG_FILE)) {
            if (!@rename($legacy_file, OCM_DEBUG_LOG_FILE) && @copy($legacy_file, OCM_DEBUG_LOG_FILE)) {
                @unlink($legacy_file);
            }
        }

        $ready = true;
        return true;
    }

    public static function download_log()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this log.', '1-click-migration'));
        }
        check_admin_referer('ocm_download_log');
        self::ensure_private_log_directory();
        if (!is_file(OCM_DEBUG_LOG_FILE) || !is_readable(OCM_DEBUG_LOG_FILE)) {
            wp_die(esc_html__('Migration log not found.', '1-click-migration'));
        }

        nocache_headers();
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . OCM_DEBUG_LOG_FILENAME . '"');
        header('X-Content-Type-Options: nosniff');
        // A few plain lines for the person who downloads it. The log has no
        // restore code, keys or signed links, but it has the email address and
        // server details, so it's not for posting whole in public.
        echo '# ' . __('1-Click Migration log: the technical details of the last backup or restore on this site.', '1-click-migration') . "\n";
        echo '# ' . sprintf(
            /* translators: %s: support forum URL */
            __('Need help? Ask in the support forum: %s', '1-click-migration'),
            OCM_Backup::SUPPORT_FORUM_URL
        ) . "\n";
        echo '# ' . __('This log includes your email address and server details, so share only the lines we ask for. It never includes your restore code.', '1-click-migration') . "\n#\n";
        readfile(OCM_DEBUG_LOG_FILE);
        exit;
    }

    public static function set_default_max_execution_time($v)
    {
        self::$default_max_execution_time = (int) $v;
    }

    public static function get_default_max_execution_time()
    {
        return self::$default_max_execution_time;
    }

    public static function set_current_max_execution_time()
    {
        self::set_default_max_execution_time(ini_get('max_execution_time'));
        $isApache = extension_loaded('apache2handler') || (strpos(getenv('SERVER_SOFTWARE'),'Apache') !== false);
        if ($isApache) {
           if ( (int) ini_get('max_execution_time') < 300 ) @set_time_limit(OCM_SET_TIME_LIMIT);
        }
        else if ( (int) ini_get('max_execution_time') < 60 ) @set_time_limit( 60 );
        self::$current_max_execution_time = (int) ini_get('max_execution_time');
    }

    public static function get_current_max_execution_time()
    {
        return self::$current_max_execution_time;
    }

    public static function get_timeout()
    {
        return (self::get_current_max_execution_time()-5) > 0 ? self::get_current_max_execution_time()-5 : OCM_SET_TIME_LIMIT;
    }

    /**
     * Seconds a backup or restore worker works before it hands over to a new one.
     *
     * set_current_max_execution_time() raises PHP's own limit, but the server
     * can still kill the request sooner: PHP-FPM's request_terminate_timeout
     * sends SIGTERM (no shutdown functions run), and those limits are often
     * 30 s, near php.ini's max_execution_time. So stay well under both.
     *
     * @return int
     */
    public static function get_worker_time_budget()
    {
        $budget = min(self::get_timeout(), OCM_WORKER_TIME_BUDGET);
        $ini_limit = (int) self::get_default_max_execution_time();
        if ($ini_limit > 0) {
            $budget = min($budget, max(10, $ini_limit - 10));
        }
        return max(5, (int) apply_filters('ocm_worker_time_budget', $budget));
    }

    public static function ensure_mu_plugin_isolation_shim()
    {
        // Plugin isolation disabled while debugging restore. Actively remove any
        // existing shim file so a stale copy doesn't keep firing on admin loads.
        self::remove_mu_plugin_isolation_shim();
        return false;
    }

    public static function remove_mu_plugin_isolation_shim()
    {
        $dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
        $file = trailingslashit($dir) . self::MU_PLUGIN_ISOLATION_FILE;

        if (is_file($file)) {
            @unlink($file);
        }
    }

    public static function start_backup_plugin_isolation()
    {
        if (!self::ensure_mu_plugin_isolation_shim()) {
            self::clear_backup_plugin_isolation();
            return false;
        }

        try {
            $token = bin2hex(random_bytes(32));
        } catch (Exception $e) {
            $token = wp_generate_password(64, false, false);
        }

        update_option(self::BACKUP_ISOLATION_TOKEN_OPTION, $token, false);
        update_option(self::BACKUP_ISOLATION_TOKEN_HASH_OPTION, hash_hmac('sha256', $token, wp_salt('auth')), false);
        update_option(self::BACKUP_ISOLATION_ALLOWED_PLUGINS_OPTION, array(plugin_basename(OCM_MAIN_FILE)), false);

        return true;
    }

    public static function get_backup_plugin_isolation_token()
    {
        $token = get_option(self::BACKUP_ISOLATION_TOKEN_OPTION, '');
        return is_string($token) ? $token : '';
    }

    public static function clear_backup_plugin_isolation()
    {
        delete_option(self::BACKUP_ISOLATION_TOKEN_OPTION);
        delete_option(self::BACKUP_ISOLATION_TOKEN_HASH_OPTION);
        delete_option(self::BACKUP_ISOLATION_ALLOWED_PLUGINS_OPTION);
    }

    private static function get_mu_plugin_isolation_shim_contents()
    {
        return <<<'PHP'
<?php
/**
 * Plugin Name: 1 Click Migration Plugin Isolation
 * Description: Keeps normal plugins out of signed 1 Click Migration backup worker requests.
 */

if (!defined('ABSPATH')) {
    exit;
}

function ocm_mu_plugin_isolation_authorized()
{
    $token = isset($_SERVER['HTTP_X_OCM_ISOLATION_TOKEN']) ? (string) $_SERVER['HTTP_X_OCM_ISOLATION_TOKEN'] : '';
    if ($token === '') {
        return false;
    }

    $expected_hash = get_option('ocm_backup_isolation_token_hash', '');
    if (!is_string($expected_hash) || $expected_hash === '') {
        return false;
    }

    $actual_hash = hash_hmac('sha256', $token, wp_salt('auth'));
    return hash_equals($expected_hash, $actual_hash);
}

function ocm_mu_plugin_isolation_allowed_plugins()
{
    $allowed = get_option('ocm_backup_isolation_allowed_plugins', array());
    if (!is_array($allowed) || empty($allowed)) {
        $allowed = array('1-click-migration/one-click-migration.php');
    }

    return array_values(array_unique(array_filter($allowed, 'is_string')));
}

add_filter('option_active_plugins', function ($plugins) {
    if (!ocm_mu_plugin_isolation_authorized()) {
        return $plugins;
    }

    return ocm_mu_plugin_isolation_allowed_plugins();
}, 1);

add_filter('site_option_active_sitewide_plugins', function ($plugins) {
    if (!ocm_mu_plugin_isolation_authorized() || !is_array($plugins)) {
        return $plugins;
    }

    return array_intersect_key($plugins, array_flip(ocm_mu_plugin_isolation_allowed_plugins()));
}, 1);
PHP;
    }

    public static function progress_endpoint()
    {
        return OCM_Backup::get_progress();
    }

    public static function loopback_check()
    {
        wp_send_json_success(array(
            'plugin' => '1-click-migration',
            'status' => 'ok',
        ));
    }

    public static function reset_actions_start_mark()
    {
        update_option('ocm_action_start_backup', false);
        update_option('ocm_action_start_restore', false);

        // Reset watchdog state so the next run starts with a fresh clock
        // instead of inheriting a stale heartbeat from this one.
        self::reset_worker_watchdog();
    }

    /**
     * Forget the watchdog's heartbeat, last pump and revival count. A run's
     * kickoff calls this too: the loopback request that cancel_all_process()
     * dispatches after a run ends writes a heartbeat after the cleanup above,
     * and the next run would see it as a stall at once (a needless revival,
     * and the "taking longer than expected" notice for the whole run).
     *
     * @return void
     */
    public static function reset_worker_watchdog()
    {
        delete_option('ocm_worker_heartbeat');
        delete_option('ocm_worker_watchdog_last');
        delete_option('ocm_worker_revival_count');
    }

    public static function cancel_all_process()
    {
        self::clear_backup_plugin_isolation();

        // Load the background helper class before using it
        if (!class_exists('OCM\OCM_BackgroundHelper')) {
            require_once __DIR__ . '/inc/background/class-background-helper.php';
        }
        OCM_BackgroundHelper::delete_all_batch_process();
        
        // Only call cancel methods if the process objects exist
        if (self::$process_backup_single) {
            self::$process_backup_single->cancel_all_process();
        }
        if (self::$process_restore_single) {
            self::$process_restore_single->cancel_all_process();
        }
        // The queues are already gone and run ownership stops any worker still
        // running, so the "cancelled" flag cancel() just set has nothing left to
        // do. Left set, the next run's first dispatched worker deletes that run's
        // queue whenever the cancel's own request hasn't arrived yet.
        self::clear_background_cancel_status();
    }

    /**
     * Clear both workers' "cancelled" flags (see clear_cancel_status()).
     *
     * @return void
     */
    public static function clear_background_cancel_status()
    {
        foreach (array(self::$process_backup_single, self::$process_restore_single) as $process) {
            if (is_object($process) && method_exists($process, 'clear_cancel_status')) {
                $process->clear_cancel_status();
            }
        }
    }

    public static function stop_and_reset()
    {
        OCM_Debug_Mode::disable();

        One_Click_Migration::write_to_log("stop and reset started");

        // Disable maintenance mode when stopping the process
        self::disable_maintenance_mode();

        // Unlock any locked processes before cleanup
        if (self::$process_backup_single) {
            self::$process_backup_single->unlock_process();
        }
        if (self::$process_restore_single) {
            self::$process_restore_single->unlock_process();
        }

        // Clear all queues and batches
        if (self::$process_backup_single) {
            self::$process_backup_single->cancel_all_process();
            self::$process_backup_single->cancel_scheduled_event();
        }
        if (self::$process_restore_single) {
            self::$process_restore_single->cancel_all_process();
            self::$process_restore_single->cancel_scheduled_event();
        }

        // A backup's system zip can outlive its worker and would keep writing
        // into the work dir deleted below; stop it while its record exists.
        OCM_Backup::stop_zip_failover_job();

        // Delete all options and batch data
        self::delete_options();
        
        // Load the background helper class before using it
        if (!class_exists('OCM\OCM_BackgroundHelper')) {
            require_once __DIR__ . '/inc/background/class-background-helper.php';
        }
        OCM_BackgroundHelper::delete_all_batch_process();

        // Clear specific process status options that might be stuck
        delete_option('wp_ocm_background_backup_status');
        delete_option('wp_ocm_background_restore_status');
        delete_site_transient('wp_ocm_background_backup_process_lock');
        delete_site_transient('wp_ocm_background_restore_process_lock');

        // Drop leftover staging tables from an interrupted restore.
        if (class_exists('OCM\\OCM_SQL_Utils')) {
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb)) {
                \OCM\OCM_SQL_Utils::drop_stale_temp_tables($wpdb, null, isset($wpdb->base_prefix) ? $wpdb->base_prefix : null);
            }
            delete_option(\OCM\OCM_SQL_Utils::OPTION_STAGING_PREFIX);
        }

        // Delete the restore workspace
        if(!WP_DEBUG){
          OCM_Backup::delete_restore_workspaces('Deleting restore workspace');
        }

        // Clear backup work files (private work dir, then the tmp folder)
        OCM_Backup::delete_private_backup_work_dirs('Deleting TMP Folder');
        if (is_dir(OCM_PLUGIN_WRITABLE_PATH)) {
            OCM_Backup::deleteDir(OCM_PLUGIN_WRITABLE_PATH, 'Deleting TMP Folder', null);
        }

        // Make sure we don't leave un-necessary routes laying around
        flush_rewrite_rules();

        One_Click_Migration::write_to_log("Stop & Reset Finished");

    }


    public static function clear_log_file(){
      $file = OCM_DEBUG_LOG_FILE;
      if (file_exists($file)) {
        $fh = fopen($file, 'w');
        $file_content = "";
        fwrite($fh, $file_content);
        fclose($fh);
      }

    }


    public static function delete_log_file()
    {
        // Lines this request held back still belong in the old log's S3 copy.
        self::flush_log_upload();

        $file = OCM_DEBUG_LOG_FILE;
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Pull the backup-side log from S3 and prepend it to whatever the restore
     * has already written locally. Idempotent for a given bucket via the
     * ocm_log_merged_bucket option, so it's safe to call repeatedly.
     */
    public static function merge_backup_log_from_s3()
    {
        $urls = get_option('ocm_presigned_urls');
        $bucket_id = get_option('ocm_bucket_id');
        if (!$urls || !is_object($urls) || empty($urls->log_download) || !$bucket_id) {
            return;
        }
        if (get_option('ocm_log_merged_bucket') === $bucket_id) {
            return;
        }

        try {
            $client = new Client();
            $response = $client->get($urls->log_download, ['http_errors' => false, 'timeout' => 15]);
            if ($response->getStatusCode() !== 200) {
                return;
            }
            $remote = (string) $response->getBody();
            if ($remote === '') {
                update_option('ocm_log_merged_bucket', $bucket_id, false);
                return;
            }
            self::extract_missing_parts_from_backup_log($remote, $bucket_id);
            OCM_SQL_Utils::store_backup_plugin_version_from_log($remote);
            $file = OCM_DEBUG_LOG_FILE;
            $local = file_exists($file) ? (string) @file_get_contents($file) : '';
            $combined = rtrim($remote, "\r\n") . PHP_EOL . $local;
            file_put_contents($file, $combined);
            update_option('ocm_log_merged_bucket', $bucket_id, false);
        } catch (Exception $e) {
            error_log('[1-Click Migration] merge_backup_log_from_s3 failed: ' . $e->getMessage());
        }
    }

    /**
     * Detect parts the backup itself could not include and persist them for the
     * restore flow. Prefers the structured BACKUP_PARTS marker; falls back to the
     * skip notices for backups made by older plugin versions. Only the remote
     * (backup-side) log is inspected so this site's own older runs cannot leak in.
     */
    public static function extract_missing_parts_from_backup_log($remote_log, $bucket_id)
    {
        $missing = array();
        $excluded_at_backup = array();
        $structured = false;

        if (preg_match_all('/BACKUP_PARTS bucket=' . preg_quote($bucket_id, '/') . ' ([a-z_= ]+)/', $remote_log, $matches)) {
            $structured = true;
            $line = end($matches[1]);
            foreach (explode(' ', trim($line)) as $pair) {
                $kv = explode('=', $pair, 2);
                if (count($kv) !== 2) {
                    continue;
                }
                $part = strtolower($kv[0]);
                $status = $kv[1];
                // Skipped (timeout / failure) parts warn on restore. User-excluded
                // parts at backup time are intentional and must not warn.
                if ($status === 'skipped') {
                    $missing[] = $part;
                } elseif ($status === 'excluded') {
                    $excluded_at_backup[] = $part;
                }
            }
        } elseif (preg_match_all('/Notice: File (\w+) was skipped due to timeout/i', $remote_log, $matches)) {
            foreach ($matches[1] as $part) {
                $missing[] = strtolower($part);
            }
        }

        $missing = array_values(array_unique($missing));
        $excluded_at_backup = array_values(array_unique($excluded_at_backup));
        update_option('ocm_backup_missing_parts', $missing, false);
        update_option('ocm_backup_excluded_parts', $excluded_at_backup, false);
        update_option('ocm_backup_parts_known', $structured ? 1 : 0, false);

        return $missing;
    }

    /**
     * Record that a background worker is alive. The progress poller treats a
     * stale heartbeat as a stalled worker and resumes it inline, so every worker
     * entry point must refresh this.
     */
    public static function worker_heartbeat()
    {
        update_option('ocm_worker_heartbeat', time(), false);
    }

    /**
     * Heartbeat from inside one long call (a download or decrypt) that doesn't
     * return to the worker loop. At most every 10 seconds it refreshes the
     * heartbeat and the worker's process lock, so the watchdog never takes a
     * busy worker for a stalled one and starts a second one (#79).
     *
     * @param \WP_Background_Process|null $process The worker running the call.
     */
    public static function transfer_heartbeat($process = null)
    {
        $now = time();
        if ($now - self::$last_transfer_heartbeat < 10) {
            return;
        }
        self::$last_transfer_heartbeat = $now;

        self::worker_heartbeat();
        // Never call lock_process() from here: in an older library copy loaded
        // by another plugin it is protected, and calling it is a fatal (#127).
        if (is_object($process) && is_callable(array($process, 'refresh_process_lock'))) {
            $process->refresh_process_lock();
        }
    }

    /**
     * Enable WordPress maintenance mode (custom implementation)
     */
    public static function enable_maintenance_mode()
    {
        // Set option to indicate maintenance mode is active
        update_option('ocm_maintenance_mode', true);

        One_Click_Migration::write_to_log('Maintenance mode enabled');
        return true;
    }

    /**
     * Handle maintenance mode with admin bypass
     */
    public static function handle_maintenance_mode()
    {
        // Check if maintenance mode is active
        if (!get_option('ocm_maintenance_mode', false)) {
            return;
        }
        
        // Allow admin users and REST API requests to bypass maintenance mode
        if (is_admin() || defined('REST_REQUEST') || (is_user_logged_in() && current_user_can('manage_options'))) {
            return;
        }
        
        // Show maintenance page for frontend visitors
        status_header(503);
        nocache_headers();
        
        echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . esc_html__('Site Maintenance', '1-click-migration') . '</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            text-align: center; 
            padding: 50px; 
            background: #f1f1f1; 
        }
        .maintenance-container { 
            max-width: 600px; 
            margin: 0 auto; 
            background: white; 
            padding: 40px; 
            border-radius: 8px; 
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); 
        }
        h1 { color: #333; margin-bottom: 20px; }
        p { color: #666; font-size: 16px; line-height: 1.5; }
        .icon { font-size: 48px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="maintenance-container">
        <div class="icon">🛠️</div>
        <h1>' . esc_html__('Site Under Maintenance', '1-click-migration') . '</h1>
        <p>' . esc_html__('Site is currently being restored. Please check back in a few minutes.', '1-click-migration') . '</p>
        <p>' . esc_html__('We apologize for any inconvenience. Please check back shortly.', '1-click-migration') . '</p>
    </div>
</body>
</html>';
        exit;
    }

    /**
     * Disable WordPress maintenance mode
     */
    public static function disable_maintenance_mode()
    {
        // Remove the custom maintenance mode option
        delete_option('ocm_maintenance_mode');

        // Also clean up the .maintenance file if it exists
        $maintenance_file = ABSPATH . '.maintenance';
        if (file_exists($maintenance_file)) {
            unlink($maintenance_file);
        }

        One_Click_Migration::write_to_log('Maintenance mode disabled');
        return true;
    }
}

new One_Click_Migration();

add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function ($links) {
    $links[] = '<a href="' .
        esc_url(admin_url('tools.php?page=one-click-migration')) .
        '">' . esc_html__('Settings', '1-click-migration') . '</a>';
    return $links;
});
