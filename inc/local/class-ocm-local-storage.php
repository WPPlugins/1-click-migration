<?php

namespace OCM;

use WP_Error;

class OCM_Local_Storage
{
    const STORAGE_SETTINGS_OPTION = 'ocm_local_storage_settings';
    const DEFAULT_KEEP_LAST = 5;
    /**
     * Manifest 'purpose' of a backup taken so one change can be undone (the
     * database copy before a search & replace or a cleanup). "Keep last N" counts these
     * apart from the site's own backups.
     */
    const PURPOSE_UNDO = 'undo';
    const DEFAULT_MIN_FREE_SPACE_MB = 500;
    const MAX_KEEP_LAST = 500;
    const MAX_MIN_FREE_SPACE_MB = 1048576;
    const PARTS = array('db', 'themes', 'plugins', 'uploads');
    const BACKUP_ID_REGEX = '/^[0-9]{8}-[0-9]{6}-[0-9a-f]{8}$/';
    const STAGING_PREFIX = '.in-progress-';

    /** @var bool Whether this request already tried moving backups out of the web root. */
    private static $legacy_moved = false;

    /**
     * Root directory where local backups are stored.
     *
     * Normally inside the private dir beside the WordPress root. That folder
     * can still sit inside the web server's document root (WordPress installed
     * in a subfolder), where servers that ignore the deny files (nginx) would
     * serve the plain archives to anyone who guesses the path. Then the
     * backups go to the wp-content folder with an unguessable name instead,
     * the one the log falls back to.
     *
     * @return string Without trailing slash.
     */
    public static function base_dir()
    {
        $preferred = self::preferred_base_dir();
        $hidden = self::hidden_base_dir();
        if ($hidden === '' || $hidden === $preferred) {
            return $preferred;
        }
        // Once chosen, stay there: WP-CLI and some cron runners have no
        // DOCUMENT_ROOT, so they couldn't repeat the check.
        if (is_dir($hidden)) {
            return $hidden;
        }
        if (class_exists(__NAMESPACE__ . '\\OCM_Backup')
            && method_exists(OCM_Backup::class, 'is_inside_web_root')
            && OCM_Backup::is_inside_web_root($preferred)
        ) {
            return $hidden;
        }
        return $preferred;
    }

    /**
     * local-backups inside the private dir the log uses.
     *
     * @return string
     */
    public static function preferred_base_dir()
    {
        $root = defined('OCM_DEBUG_LOG_DIR') && is_string(OCM_DEBUG_LOG_DIR) && OCM_DEBUG_LOG_DIR !== ''
            ? OCM_DEBUG_LOG_DIR
            : WP_CONTENT_DIR;

        return rtrim($root, '/\\') . '/local-backups';
    }

    /**
     * local-backups inside the unguessable wp-content folder, or '' when the
     * site has no secrets to build its name from.
     *
     * @return string
     */
    public static function hidden_base_dir()
    {
        if (!class_exists(__NAMESPACE__ . '\\OCM_Private_Dir') || !defined('ABSPATH') || !defined('WP_CONTENT_DIR')) {
            return '';
        }
        $dir = OCM_Private_Dir::fallback_site_dir_for(ABSPATH, WP_CONTENT_DIR, OCM_Private_Dir::site_secret());
        return $dir === '' ? '' : rtrim($dir, '/\\') . '/local-backups';
    }

    /**
     * Move backups 4.0.0 saved inside the web root to base_dir(). Skipped
     * while a local backup or restore is running, so no run loses its files.
     *
     * @return void
     */
    public static function move_legacy_backups()
    {
        if (self::$legacy_moved) {
            return;
        }
        $old = self::preferred_base_dir();
        $new = self::base_dir();
        if ($old === $new || !is_dir($old)) {
            return;
        }
        if (class_exists(__NAMESPACE__ . '\\OCM_Local_Backup') && OCM_Local_Backup::is_running()) {
            return;
        }
        self::$legacy_moved = true;
        if (!is_dir($new)) {
            $parent = dirname($new);
            if (class_exists(__NAMESPACE__ . '\\OCM_Private_Dir')) {
                OCM_Private_Dir::ensure($parent);
            } elseif (!is_dir($parent)) {
                @mkdir($parent, 0700, true);
            }
            if (@rename($old, $new)) {
                self::write_deny_files($new);
                return;
            }
            if (!self::ensure_dir_protected($new)) {
                return;
            }
        }
        foreach ((array) @scandir($old) as $entry) {
            if (!is_string($entry) || !self::is_valid_backup_id($entry)) {
                continue;
            }
            if (!is_dir($new . '/' . $entry)) {
                @rename($old . '/' . $entry, $new . '/' . $entry);
            }
        }
        $left = array_diff((array) @scandir($old), array('.', '..', '.htaccess', 'web.config', 'index.php'));
        if (empty($left)) {
            OCM_Backup::deleteDirectoryBestEffort($old);
        }
    }

    /**
     * Ensure the private root and local-backups directory exist and are
     * protected with deny files.
     *
     * @return bool
     */
    public static function ensure_base_dir()
    {
        self::move_legacy_backups();
        $private_dir = dirname(self::base_dir());
        if (class_exists(__NAMESPACE__ . '\\OCM_Private_Dir')) {
            if (!OCM_Private_Dir::ensure($private_dir)) {
                return false;
            }
        } elseif (!is_dir($private_dir) && !@mkdir($private_dir, 0700, true) && !is_dir($private_dir)) {
            return false;
        }

        return self::ensure_dir_protected(self::base_dir());
    }

    /**
     * Create a directory (0700) and write standard web-deny files into it.
     *
     * @param string $dir
     * @return bool
     */
    public static function ensure_dir_protected($dir)
    {
        $dir = rtrim((string) $dir, '/\\');
        if ($dir === '') {
            return false;
        }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return false;
        }
        if (!is_writable($dir)) {
            return false;
        }
        self::write_deny_files($dir);
        return true;
    }

    private static function write_deny_files($dir)
    {
        if (!is_file($dir . '/.htaccess')) {
            @file_put_contents($dir . '/.htaccess', "Deny from all\nRequire all denied\n");
        }
        if (!is_file($dir . '/web.config')) {
            @file_put_contents(
                $dir . '/web.config',
                '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>'
            );
        }
        if (!is_file($dir . '/index.php')) {
            @file_put_contents($dir . '/index.php', "<?php\nhttp_response_code(404);\n");
        }
    }

    /**
     * Generate a sortable UTC timestamp + microsecond-ordered 8-hex-char backup ID.
     *
     * Encoding the microsecond-of-second in the first 5 hex digits (0x00000..0xf423f)
     * keeps BACKUP_ID_REGEX unchanged while ordering same-second backups chronologically.
     *
     * @return string
     */
    public static function generate_backup_id()
    {
        static $last_sec = -1;
        static $last_usec = -1;

        $mt = microtime(true);
        $sec = (int) floor($mt);
        $usec = max(0, min(999999, (int) round(($mt - $sec) * 1000000)));
        if ($sec === $last_sec && $usec <= $last_usec) {
            $usec = min(999999, $last_usec + 1);
        }
        $last_sec = $sec;
        $last_usec = $usec;

        try {
            $rand = bin2hex(random_bytes(2));
        } catch (\Throwable $e) {
            $rand = sprintf('%04x', mt_rand(0, 0xffff));
        }

        return gmdate('Ymd-His', $sec) . '-' . sprintf('%05x%s', $usec, substr(strtolower($rand), 0, 3));
    }

    /**
     * Strict whitelist validation to block path traversal.
     *
     * @param mixed $id
     * @return bool
     */
    public static function is_valid_backup_id($id)
    {
        return is_string($id) && preg_match(self::BACKUP_ID_REGEX, $id) === 1;
    }

    /**
     * @param string $backup_id
     * @return string Empty string if invalid.
     */
    public static function backup_dir($backup_id)
    {
        if (!self::is_valid_backup_id($backup_id)) {
            return '';
        }
        return self::base_dir() . '/' . $backup_id;
    }

    /**
     * @param string $backup_id
     * @return string Empty string if invalid.
     */
    public static function staging_dir($backup_id)
    {
        if (!self::is_valid_backup_id($backup_id)) {
            return '';
        }
        return self::base_dir() . '/' . self::STAGING_PREFIX . $backup_id;
    }

    /**
     * @param string $backup_id
     * @param string $part
     * @return string Empty string if invalid.
     */
    public static function part_file_path($backup_id, $part)
    {
        if (!self::is_valid_backup_id($backup_id) || !in_array($part, self::PARTS, true)) {
            return '';
        }
        return self::backup_dir($backup_id) . '/' . $part . '.zip';
    }

    /**
     * @return array{keep_last:int,min_free_space_mb:int}
     */
    public static function default_settings()
    {
        return array(
            'keep_last' => self::DEFAULT_KEEP_LAST,
            'min_free_space_mb' => self::DEFAULT_MIN_FREE_SPACE_MB,
        );
    }

    /**
     * @param mixed $input
     * @return array{keep_last:int,min_free_space_mb:int}
     */
    public static function sanitize_settings($input)
    {
        $defaults = self::default_settings();
        if (!is_array($input)) {
            return $defaults;
        }

        $keep_last = isset($input['keep_last']) && is_numeric($input['keep_last'])
            ? (int) $input['keep_last']
            : $defaults['keep_last'];
        $keep_last = max(0, min(self::MAX_KEEP_LAST, $keep_last));

        $min_free = isset($input['min_free_space_mb']) && is_numeric($input['min_free_space_mb'])
            ? (int) $input['min_free_space_mb']
            : $defaults['min_free_space_mb'];
        $min_free = max(0, min(self::MAX_MIN_FREE_SPACE_MB, $min_free));

        return array(
            'keep_last' => $keep_last,
            'min_free_space_mb' => $min_free,
        );
    }

    /**
     * @return array{keep_last:int,min_free_space_mb:int}
     */
    public static function get_settings()
    {
        $stored = get_option(self::STORAGE_SETTINGS_OPTION, array());
        return self::sanitize_settings($stored);
    }

    /**
     * @param array $input
     * @return array{keep_last:int,min_free_space_mb:int}
     */
    public static function save_settings(array $input)
    {
        $clean = self::sanitize_settings($input);
        update_option(self::STORAGE_SETTINGS_OPTION, $clean, false);
        return $clean;
    }

    /**
     * Atomically write manifest.json into a backup or staging directory.
     *
     * @param string $dir
     * @param array  $manifest
     * @return bool
     */
    public static function write_manifest($dir, array $manifest)
    {
        if (!self::ensure_dir_protected($dir)) {
            return false;
        }
        $target = rtrim($dir, '/\\') . '/manifest.json';
        $tmp = $target . '.tmp';
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
        $json = function_exists('wp_json_encode') ? wp_json_encode($manifest, $flags) : json_encode($manifest, $flags);
        if (!is_string($json) || $json === '') {
            return false;
        }
        if (@file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return false;
        }
        @chmod($target, 0600);
        return true;
    }

    /**
     * Read and verify a completed backup's manifest against files on disk.
     *
     * @param string $backup_id
     * @return array|null
     */
    public static function read_manifest($backup_id)
    {
        $dir = self::backup_dir($backup_id);
        if ($dir === '' || !is_dir($dir)) {
            return null;
        }
        $manifest_path = $dir . '/manifest.json';
        if (!is_file($manifest_path) || !is_readable($manifest_path)) {
            return null;
        }
        $raw = @file_get_contents($manifest_path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['status']) || $data['status'] !== 'complete') {
            return null;
        }

        $verified_parts = array();
        $total_size = 0;
        $raw_parts = isset($data['parts']) && is_array($data['parts']) ? $data['parts'] : array();
        foreach (self::PARTS as $part) {
            if (!isset($raw_parts[$part]) || !is_array($raw_parts[$part])) {
                continue;
            }
            $zip_path = $dir . '/' . $part . '.zip';
            if (!is_file($zip_path) || !is_readable($zip_path)) {
                continue;
            }
            $disk_size = (int) @filesize($zip_path);
            $part_meta = $raw_parts[$part];
            $part_meta['file'] = $part . '.zip';
            $part_meta['size'] = $disk_size;
            if (!isset($part_meta['uncompressed'])) {
                $part_meta['uncompressed'] = $disk_size;
            }
            $verified_parts[$part] = $part_meta;
            $total_size += $disk_size;
        }

        if (empty($verified_parts)) {
            return null;
        }

        $data['id'] = $backup_id;
        $data['parts'] = $verified_parts;
        $data['total_size'] = $total_size;
        $data['total_readable'] = OCM_Backup::readable_bytes($total_size);
        $data['has_log'] = is_file($dir . '/backup.log');
        $data['trigger'] = isset($data['trigger']) && $data['trigger'] === 'scheduled' ? 'scheduled' : 'manual';
        $data['label'] = isset($data['label']) ? (string) $data['label'] : '';
        $data['purpose'] = isset($data['purpose']) && $data['purpose'] === self::PURPOSE_UNDO ? self::PURPOSE_UNDO : '';
        $data['created_at'] = isset($data['created_at']) ? (string) $data['created_at'] : '';
        $data['created_gmt'] = isset($data['created_gmt']) ? (int) $data['created_gmt'] : 0;
        $data['created_us'] = isset($data['created_us']) ? (float) $data['created_us'] : 0.0;

        return $data;
    }

    /**
     * List all completed backups on disk, newest first.
     *
     * @return array[]
     */
    public static function list_backups()
    {
        self::move_legacy_backups();
        $base = self::base_dir();
        if (!is_dir($base)) {
            return array();
        }
        $entries = @scandir($base);
        if (!is_array($entries)) {
            return array();
        }

        $backups = array();
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (!self::is_valid_backup_id($entry)) {
                continue;
            }
            $manifest = self::read_manifest($entry);
            if ($manifest !== null) {
                $backups[] = $manifest;
            }
        }

        usort($backups, static function ($a, $b) {
            $ta = isset($a['created_gmt']) ? (int) $a['created_gmt'] : 0;
            $tb = isset($b['created_gmt']) ? (int) $b['created_gmt'] : 0;
            if ($ta !== $tb) {
                return $tb - $ta;
            }
            $ua = isset($a['created_us']) ? (float) $a['created_us'] : 0.0;
            $ub = isset($b['created_us']) ? (float) $b['created_us'] : 0.0;
            if ($ua !== $ub) {
                return ($ub > $ua) ? 1 : -1;
            }
            return strcmp((string) $b['id'], (string) $a['id']);
        });

        return $backups;
    }

    /**
     * Delete a single local backup directory. Refuses if it is currently being restored.
     *
     * @param string $backup_id
     * @param string $active_restore_backup_id
     * @return true|WP_Error
     */
    public static function delete_backup($backup_id, $active_restore_backup_id = '')
    {
        if (!self::is_valid_backup_id($backup_id)) {
            return new WP_Error('invalid_backup_id', __('Invalid backup ID.', '1-click-migration'));
        }
        if ($active_restore_backup_id !== '' && hash_equals((string) $active_restore_backup_id, $backup_id)) {
            return new WP_Error('backup_in_use', __('Cannot delete a backup while it is being restored.', '1-click-migration'));
        }
        $dir = self::backup_dir($backup_id);
        if ($dir === '' || !is_dir($dir)) {
            return new WP_Error('backup_not_found', __('Local backup not found.', '1-click-migration'));
        }
        if (!OCM_Backup::deleteDirectoryBestEffort($dir) && is_dir($dir)) {
            return new WP_Error('delete_failed', __('Could not delete the backup directory.', '1-click-migration'));
        }
        return true;
    }

    /**
     * Enforce count-based retention ("keep last N"), deleting the oldest completed
     * backups beyond $keep_last.
     *
     * Undo backups (PURPOSE_UNDO) are counted on their own: the newest
     * $keep_last of them stay, and they never push out the site's own manual
     * and scheduled backups, however often a tool that takes one is run.
     *
     * @param int|null $keep_last
     * @param string   $active_restore_backup_id
     * @return string[] Deleted backup IDs.
     */
    public static function prune_backups($keep_last = null, $active_restore_backup_id = '')
    {
        if (null === $keep_last) {
            $settings = self::get_settings();
            $keep_last = (int) $settings['keep_last'];
        } else {
            $keep_last = (int) $keep_last;
        }

        if ($keep_last <= 0) {
            return array();
        }

        // Newest first within each group.
        $groups = array('' => array(), self::PURPOSE_UNDO => array());
        foreach (self::list_backups() as $backup) {
            $groups[isset($backup['purpose']) && $backup['purpose'] === self::PURPOSE_UNDO ? self::PURPOSE_UNDO : ''][] = $backup;
        }
        $to_remove = array();
        foreach ($groups as $group) {
            if (count($group) > $keep_last) {
                $to_remove = array_merge($to_remove, array_slice($group, $keep_last));
            }
        }

        $pruned = array();
        foreach ($to_remove as $backup) {
            $id = (string) $backup['id'];
            if ($active_restore_backup_id !== '' && hash_equals((string) $active_restore_backup_id, $id)) {
                continue;
            }
            $res = self::delete_backup($id, $active_restore_backup_id);
            if ($res === true) {
                $pruned[] = $id;
                One_Click_Migration::write_to_local_log(sprintf(
                    'SYSLOG: Pruned local backup "%s" (keep_last=%d).',
                    $id,
                    $keep_last
                ));
            }
        }

        return $pruned;
    }

    /**
     * Remove leftover .in-progress-<id> directories from failed/cancelled runs.
     *
     * @param string $active_backup_id Optional backup ID currently in progress.
     * @return void
     */
    public static function cleanup_stale_staging_dirs($active_backup_id = '')
    {
        $base = self::base_dir();
        if (!is_dir($base)) {
            return;
        }
        $entries = @scandir($base);
        if (!is_array($entries)) {
            return;
        }
        $keep_name = $active_backup_id !== '' ? (self::STAGING_PREFIX . $active_backup_id) : '';
        foreach ($entries as $entry) {
            if (strpos($entry, self::STAGING_PREFIX) !== 0) {
                continue;
            }
            if ($keep_name !== '' && $entry === $keep_name) {
                continue;
            }
            $path = $base . '/' . $entry;
            if (is_dir($path) && !is_link($path)) {
                OCM_Backup::deleteDirectoryBestEffort($path);
            }
        }
    }

    /**
     * Available disk space on the filesystem holding local backups.
     *
     * @return int Bytes (0 when host hides disk stats).
     */
    public static function free_disk_space()
    {
        $path = self::base_dir();
        while ($path !== '' && !is_dir($path)) {
            $parent = dirname($path);
            if ($parent === $path) {
                break;
            }
            $path = $parent;
        }
        $free = OCM_Backup::get_free_disk_space($path);
        if (function_exists('apply_filters')) {
            $free = (int) apply_filters('ocm_local_free_disk_space', $free, self::base_dir());
        }
        return max(0, (int) $free);
    }

    /**
     * Minimum free disk space guard check before starting a local backup.
     *
     * @param string[]   $excluded_parts
     * @param array|null $settings
     * @return array{ok:bool,free:int,required:int,estimated:int,min_free:int,sizes:array<string,int>,free_unknown:bool,reason:string}
     */
    public static function check_disk_space_guard(array $excluded_parts, $settings = null)
    {
        $settings = is_array($settings) ? self::sanitize_settings($settings) : self::get_settings();
        $min_free_bytes = (int) $settings['min_free_space_mb'] * 1024 * 1024;

        $sizes = array();
        $estimated_archive_bytes = 0;
        $max_folder_bytes = 0;

        if (!in_array('db', $excluded_parts, true)) {
            $db_bytes = (int) ((float) OCM_Backup::DBSize() * 1024 * 1024);
            $sizes['db'] = max(0, $db_bytes);
            $estimated_archive_bytes += $sizes['db'];
        }

        foreach (array('themes', 'plugins', 'uploads') as $folder) {
            if (in_array($folder, $excluded_parts, true)) {
                continue;
            }
            $folder_path = WP_CONTENT_DIR . '/' . $folder;
            $folder_bytes = is_dir($folder_path) ? (int) OCM_Backup::folderSize($folder_path) : 0;
            $sizes[$folder] = max(0, $folder_bytes);
            $estimated_archive_bytes += $sizes[$folder];
            if ($sizes[$folder] > $max_folder_bytes) {
                $max_folder_bytes = $sizes[$folder];
            }
        }

        // Peak temp working space: raw SQL dump dir alongside db.zip, or temp zip buffer for largest folder.
        $db_overhead = isset($sizes['db']) ? $sizes['db'] : 0;
        $folder_overhead = (int) ($max_folder_bytes * 0.25);
        $estimated_peak = $estimated_archive_bytes + max($db_overhead, $folder_overhead);

        $free = self::free_disk_space();
        $required = $estimated_peak + $min_free_bytes;

        if ($free <= 0) {
            return array(
                'ok' => true,
                'free' => 0,
                'required' => $required,
                'estimated' => $estimated_peak,
                'min_free' => $min_free_bytes,
                'sizes' => $sizes,
                'free_unknown' => true,
                'reason' => '',
            );
        }

        if ($min_free_bytes > 0 && $free < $min_free_bytes) {
            return array(
                'ok' => false,
                'free' => $free,
                'required' => $required,
                'estimated' => $estimated_peak,
                'min_free' => $min_free_bytes,
                'sizes' => $sizes,
                'free_unknown' => false,
                'reason' => 'below_min_free_floor',
            );
        }

        if ($free < $required) {
            return array(
                'ok' => false,
                'free' => $free,
                'required' => $required,
                'estimated' => $estimated_peak,
                'min_free' => $min_free_bytes,
                'sizes' => $sizes,
                'free_unknown' => false,
                'reason' => 'insufficient_space_with_guard',
            );
        }

        return array(
            'ok' => true,
            'free' => $free,
            'required' => $required,
            'estimated' => $estimated_peak,
            'min_free' => $min_free_bytes,
            'sizes' => $sizes,
            'free_unknown' => false,
            'reason' => '',
        );
    }

    /**
     * Summary metrics for the Local Backups admin UI.
     *
     * @return array
     */
    public static function storage_summary()
    {
        $settings = self::get_settings();
        $backups = self::list_backups();
        $total_bytes = 0;
        foreach ($backups as $backup) {
            $total_bytes += isset($backup['total_size']) ? (int) $backup['total_size'] : 0;
        }
        $free_bytes = self::free_disk_space();
        $min_free_bytes = (int) $settings['min_free_space_mb'] * 1024 * 1024;
        $low_space = ($free_bytes > 0 && $min_free_bytes > 0 && $free_bytes < $min_free_bytes);

        return array(
            'count' => count($backups),
            'total_bytes' => $total_bytes,
            'total_readable' => OCM_Backup::readable_bytes($total_bytes),
            'free_bytes' => $free_bytes,
            'free_readable' => $free_bytes > 0 ? OCM_Backup::readable_bytes($free_bytes) : __('Unknown', '1-click-migration'),
            'keep_last' => $settings['keep_last'],
            'min_free_space_mb' => $settings['min_free_space_mb'],
            'base_dir' => self::base_dir(),
            'low_space' => $low_space,
        );
    }
}
