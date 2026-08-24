<?php

/**
 * Plugin Name: 1 Click Migration
 * Plugin URI: https://wordpress.org/plugins/1-click-migration/
 * Description: Migrate, copy, or clone your entire site with 1 click. <strong>Any host, no size limitation, no premium versions.</strong>
 * Version: 3.1.8
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

define('OCM_DEBUG_LOG_FILENAME', 'ocm_debug.log');
define('OCM_DEBUG_LOG_FILE', OCM_PLUGIN_ROOT . OCM_DEBUG_LOG_FILENAME);
define('OCM_DEBUG_LOG_FILE_URL', plugins_url(OCM_DEBUG_LOG_FILENAME, OCM_MAIN_FILE));
define('OCM_WP_CONFIG_FILE', ABSPATH . 'wp-config.php');
define('OCM_API_ENDPOINT', 'https://1clickmigration.com/api/');

require 'vendor/autoload.php';

require_once __DIR__ . '/inc/admin/class-admin-page.php';
require_once __DIR__ . '/inc/backup/class-ocm-backup.php';
require_once __DIR__ . '/inc/s3/class-ocm-s3.php';
require_once __DIR__ . '/inc/db/class-ocm-db-import.php';
require_once __DIR__ . '/inc/db/class-ocm-search-replace-db.php';
require_once __DIR__ . '/inc/db/class-ocm-db.php';
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

    /** @var OCM_BackgroundBackup */
    public static $process_backup_single;

    /**@var OCM_BackgroundRestore */
    public static $process_restore_single;

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
        // add_action('admin_init', array(__CLASS__, 'ensure_mu_plugin_isolation_shim')); // disabled while debugging restore
        add_action('admin_enqueue_scripts', array(__CLASS__, 'admin_styles'));
        add_action('template_redirect', array(__CLASS__, 'handle_maintenance_mode'));
        add_action('admin_post_start_backup', array(OCM_Backup::class, 'start_backup'));
        add_action('wp_ajax_ocm_loopback_check', array(__CLASS__, 'loopback_check'));
        add_action('wp_ajax_nopriv_ocm_loopback_check', array(__CLASS__, 'loopback_check'));
        add_action('wp_ajax_ocm_restart_failed_process', array(OCM_Backup::class, 'restart_failed_process'));
        add_action('admin_post_start_restore', array(OCM_Backup::class, 'start_restore'));
        add_action('admin_post_cancel_actions', array(OCM_Backup::class, 'cancel_actions'));
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

        wp_enqueue_style('custom_ocm_admin_css', plugins_url('css/admin-style.css', __FILE__), array(), self::$version);

        wp_enqueue_script('custom_ocm_admin_js', plugins_url('js/admin-script.js', __FILE__), array('jquery'), self::$version, true);
        $ocm_user_email = get_option('ocm_user_email');
        $user_email = '';
        $urlparts = parse_url(home_url());
		$domain = $urlparts['host'];

        if($ocm_user_email){
          $user_email = $ocm_user_email;
        }
        $timeout = One_Click_Migration::get_timeout();

        $active_plugins = get_option('active_plugins', array());
        $this_plugin_basename = plugin_basename(OCM_MAIN_FILE);
        $other_active_count = 0;
        foreach ($active_plugins as $plugin) {
            if ($plugin !== $this_plugin_basename) {
                $other_active_count++;
            }
        }

        // Permanent dismiss of the in-plugin Review ask (never ask again on this site).
        if (!empty($_GET['ocm_dismiss_review']) && !empty($_GET['_wpnonce']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'ocm_dismiss_review') // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ) {
            update_option('ocm_review_ask_dismissed', 1, false);
            update_option('ocm_show_review_cta', 0, false);
        }

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

        wp_localize_script('custom_ocm_admin_js', 'siteData', array(
            'progressUrl' => get_rest_url(null, 'ocm/v1/progress'),
            'userEmail' => $user_email,
            'domain' => $domain,
            'timeout' => $timeout,
            'ajaxurl' => admin_url('admin-ajax.php'),
            'restartNonce' => wp_create_nonce('ocm_restart_failed_process'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'backupNonce' => wp_create_nonce('one_click_migration_start_backup'),
            'restoreNonce' => wp_create_nonce('one_click_migration_start_restore'),
            'otherPluginsActive' => $other_active_count,
            'pluginPageUrl' => admin_url('tools.php?page=one-click-migration'),
            'showRestoreSuccess' => $show_restore_success ? 1 : 0,
            'showReviewCta' => $show_review_cta ? 1 : 0,
            'dismissReviewUrl' => wp_nonce_url(admin_url('tools.php?page=one-click-migration&ocm_dismiss_review=1'), 'ocm_dismiss_review'),
            'reviewUrl' => 'https://wordpress.org/support/plugin/1-click-migration/reviews/#new-post',
        ));
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


    public static function write_to_log($string)
    {
      // sleep(1);
      $file = OCM_DEBUG_LOG_FILE;
      $urls = get_option('ocm_presigned_urls');

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

        try {
            $request = new Request(
                'PUT',
                $urls->log,
                [],
                file_get_contents($file)
            );

            $client = new Client();
            $client->send($request);
        } catch (Exception $e) {
            if ($e->getCode() === 404) {
                self::delete_log_file();
                wp_safe_redirect(admin_url('tools.php?page=one-click-migration&message=no_bucket'));
                exit;
            }
        }catch(ClientException $e){

          }

      }

      if(!$urls){
          $log_file = fopen($file, 'ab');
          fwrite($log_file, $line . PHP_EOL);
          fclose($log_file);

      }

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

        // Delete ocm_restore folder
        if(!WP_DEBUG){
          if (is_dir(WP_CONTENT_DIR . '/ocm_restore/')) {
              OCM_Backup::deleteDir(WP_CONTENT_DIR . '/ocm_restore/', 'Deleting ocm_restore', null);
          }
        }

        // Clear tmp folder
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
        $structured = false;

        if (preg_match_all('/BACKUP_PARTS bucket=' . preg_quote($bucket_id, '/') . ' ([a-z_= ]+)/', $remote_log, $matches)) {
            $structured = true;
            $line = end($matches[1]);
            foreach (explode(' ', trim($line)) as $pair) {
                $kv = explode('=', $pair, 2);
                if (count($kv) === 2 && 'skipped' === $kv[1]) {
                    $missing[] = $kv[0];
                }
            }
        } elseif (preg_match_all('/Notice: File (\w+) was skipped due to timeout/i', $remote_log, $matches)) {
            foreach ($matches[1] as $part) {
                $missing[] = strtolower($part);
            }
        }

        $missing = array_values(array_unique($missing));
        update_option('ocm_backup_missing_parts', $missing, false);
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
        
        $maintenance_message = 'Site is currently being restored. Please check back in a few minutes.';
        
        echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Maintenance</title>
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
        <h1>Site Under Maintenance</h1>
        <p>' . esc_html($maintenance_message) . '</p>
        <p>We apologize for any inconvenience. Please check back shortly.</p>
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
        admin_url('tools.php?page=one-click-migration') .
        '">' . __('Settings') . '</a>';
    return $links;
});
