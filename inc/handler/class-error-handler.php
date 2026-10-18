<?php
namespace OCM;
class Error_Handler
{
    /**
     *
     * @var bool
     */
    private static $inizialized = false;

    /**
     * This function only initializes the error handler the first time it is called
     */
    public static function init_error_handler()
    {
        if (!self::$inizialized) {
            @set_error_handler(array(__CLASS__, 'error'));
            @register_shutdown_function(array(__CLASS__, 'shutdown'));
            self::$inizialized = true;
        }
    }

    /**
     * Static cache for error deduplication.
     * Maps error fingerprint => ['count' => int, 'time' => float]
     */
    private static $error_cache = [];

    /**
     * Minimum interval (seconds) between logging the same error.
     */
    private static $dedup_interval = 60;

    /**
     * Minimum interval (seconds) between logging the same PHP error across
     * separate requests. The in-memory cache only dedupes within one request.
     */
    private static $persistent_dedup_interval = 3600;

    /**
     * Error handler
     *
     * @param  integer $errno   Error level
     * @param  string  $errstr  Error message
     * @param  string  $errfile Error file
     * @param  integer $errline Error line
     * @return void
     */
    public static function error($errno, $errstr, $errfile, $errline)
    {
      $is_plugin_error = strpos($errfile, 'plugins/1-click-migration/') !== false;
      $migration_active = get_option('ocm_action_start_backup') || get_option('ocm_action_start_restore') || OCM_Debug_Mode::is_active();

      // Do not keep appending unrelated frontend/theme/plugin fatals to an old
      // migration S3 log after backup/restore has finished.
      if (!$migration_active && !$is_plugin_error) {
          return;
      }

      // Skip vendor deprecation notices (e.g. nelexa/zip PHP 8.1+ return type warnings)
      if ($errno === E_DEPRECATED && strpos($errfile, '/vendor/') !== false) {
          return;
      }

      // Skip transient errno=11 (Resource deadlock avoided) read failures
      if (strpos($errstr, 'errno=11') !== false) {
          return;
      }

      // Skip unserialize notices — expected during URL search-replace on serialized data
      if ($errno === E_NOTICE && strpos($errstr, 'unserialize()') !== false) {
          return;
      }

      // A fatal ends the request, and the server monitor and progress bar key
      // on its line. Always log it: a retry within the hour, or a second
      // attempt whose log still holds the first one's copy, must show its own
      // crash. No dedup and no log read, which can itself fail right after an
      // out-of-memory fatal.
      if (self::is_fatal($errno)) {
          One_Click_Migration::write_to_log(sprintf(' SYSLOG: "%s"', self::getMessage($errno, $errstr, $errfile, $errline)));
          One_Click_Migration::write_to_log($errstr);
          // Only die for fatal errors originating from plugin code
          if ($is_plugin_error) {
              // die() here skips every later shutdown callback, so the backup
              // and restore crash guards (#127) must run first.
              if (class_exists(__NAMESPACE__ . '\\OCM_Backup', false)) {
                  $crash = array('type' => $errno, 'message' => $errstr, 'file' => $errfile, 'line' => $errline);
                  OCM_Backup::handle_restore_crash_shutdown($crash);
                  OCM_Backup::handle_backup_crash_shutdown($crash);
              }
              // The shutdown upload of held-back log lines is skipped too, so
              // the S3 copy would end before the fatal (#127).
              One_Click_Migration::flush_log_upload();
              die();
          }
          return;
      }

      // Other errors are only logged in debug mode; skip the dedup bookkeeping
      // (an option write per error) when they won't be logged anyway.
      if (WP_DEBUG !== true && !OCM_Debug_Mode::is_active()) {
          return;
      }

      // Deduplicate: suppress identical errors within the dedup interval
      $error_fingerprint = md5($errstr . '|' . $errfile . '|' . $errline);
      $now = microtime(true);

      if (isset(self::$error_cache[$error_fingerprint])) {
          $cached = self::$error_cache[$error_fingerprint];
          if (($now - $cached['time']) < self::$dedup_interval) {
              // Still within dedup window — increment count and suppress
              self::$error_cache[$error_fingerprint]['count']++;
              return;
          }
          // Dedup window expired — log with suppression count if any were suppressed
          $suppressed = $cached['count'];
          self::$error_cache[$error_fingerprint] = ['count' => 0, 'time' => $now];
          if ($suppressed > 0) {
              One_Click_Migration::write_to_log(sprintf(' SYSLOG: [Suppressed %d duplicate(s) of previous error in last %ds]', $suppressed, self::$dedup_interval));
          }
      } else {
          self::$error_cache[$error_fingerprint] = ['count' => 0, 'time' => $now];
      }

      if (!self::should_log_persistent_error($error_fingerprint, $now)) {
          return;
      }

      $file = OCM_DEBUG_LOG_FILE;
      $file_array = [];
      if(file_exists($file)){
        $all_lines = file($file);
        $file_array = array_slice($all_lines, -100);
      }

      $log_message = self::getMessage($errno, $errstr, $errfile, $errline);
      if(!self::substr_in_array($errstr, $file_array)){
        One_Click_Migration::write_to_log(sprintf(' SYSLOG: "%s"', $log_message));
      }

    }

    private static function should_log_persistent_error($fingerprint, $now)
    {
      $cache = get_option('ocm_error_log_dedupe', []);
      if (!is_array($cache)) {
          $cache = [];
      }

      $last_time = isset($cache[$fingerprint]['time']) ? (float) $cache[$fingerprint]['time'] : 0.0;
      $suppressed = isset($cache[$fingerprint]['count']) ? (int) $cache[$fingerprint]['count'] : 0;

      if ($last_time > 0 && ($now - $last_time) < self::$persistent_dedup_interval) {
          $cache[$fingerprint] = [
              'time' => $last_time,
              'count' => $suppressed + 1,
          ];
          update_option('ocm_error_log_dedupe', self::prune_persistent_error_cache($cache, $now), false);
          return false;
      }

      $cache[$fingerprint] = [
          'time' => $now,
          'count' => 0,
      ];
      update_option('ocm_error_log_dedupe', self::prune_persistent_error_cache($cache, $now), false);

      if ($suppressed > 0) {
          One_Click_Migration::write_to_log(sprintf(
              ' SYSLOG: [Suppressed %d duplicate(s) of previous error across requests in last %ds]',
              $suppressed,
              self::$persistent_dedup_interval
          ));
      }

      return true;
    }

    private static function prune_persistent_error_cache(array $cache, $now)
    {
      foreach ($cache as $fingerprint => $entry) {
          $last_time = isset($entry['time']) ? (float) $entry['time'] : 0.0;
          if ($last_time <= 0 || ($now - $last_time) > 86400) {
              unset($cache[$fingerprint]);
          }
      }

      return $cache;
    }


    private static function substr_in_array($needle, array $haystack)
    {
      $filtered = array_filter($haystack, function ($item) use ($needle) {
          return false !== strpos($item, $needle);
      });

      return !empty($filtered);
    }

    /**
     * Error types that end the request. None of them reach a set_error_handler()
     * callback; they arrive via shutdown() / error_get_last().
     *
     * @param int $errno
     * @return bool
     */
    private static function is_fatal($errno)
    {
        return in_array($errno, array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR), true);
    }

    private static function getMessage($errno, $errstr, $errfile, $errline)
    {
        $result = '[PHP ERR]';
        switch ($errno) {
            case E_ERROR :
            case E_PARSE :
            case E_CORE_ERROR :
            case E_COMPILE_ERROR :
                $result .= '[FATAL]';
                break;
            case E_WARNING :
                $result .= '[WARN]';
                break;
            case E_NOTICE :
                $result .= '[NOTICE]';
                break;
            default :
                $result .= '[ISSUE]';
                break;
        }
        $result .= ' MSG:';
        $result .= $errstr;
        $result .= ' [CODE:'.$errno.'|FILE:'.$errfile.'|LINE:'.$errline.']';
        return $result;
    }

    /**
     * Shutdown handler
     *
     * @return void
     */
    public static function shutdown()
    {
        if (($error = error_get_last())) {
          self::error($error['type'], $error['message'], $error['file'], $error['line']);
        }
    }
}
