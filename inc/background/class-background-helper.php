<?php

namespace OCM;

class OCM_BackgroundHelper
{
    /**
     * WP_Background_Process keys its queue rows as "{prefix}_{action}_batch_*"
     * with the library's fixed "wp" prefix, not the site's table prefix: a site
     * on db_ or rt_ still stores wp_ocm_background_backup_batch_*.
     */
    const QUEUE_KEY_PREFIX = 'wp_ocm_background_';

    public static function delete_all_batch_process()
    {
        global $wpdb;

        $table  = $wpdb->options;
        $column = 'option_name';

        if ( is_multisite() ) {
            $table  = $wpdb->sitemeta;
            $column = 'meta_key';
        }

        foreach (self::queue_batch_like_patterns($wpdb, '%') as $like) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE {$column} LIKE %s", $like));
        }
        $wpdb->get_results($wpdb->prepare("DELETE FROM {$table} where {$column} LIKE %s", '_transient_ocm_%'));
        $wpdb->get_results($wpdb->prepare("DELETE FROM {$table} where {$column} LIKE %s", '_site_transient_ocm_%'));
        $wpdb->get_results("DELETE FROM {$table} where {$column} = 'backup_steps'");
        $wpdb->get_results("DELETE FROM {$table} where {$column} = 'restore_steps'");
        self::forget_cached_options(array('backup_steps', 'restore_steps'));
    }

    /**
     * The step rows above go by raw SQL, so this request's option cache still
     * holds them. update_option() then compares against the deleted value,
     * finds no row to UPDATE and drops every later write in this request; a
     * restore started from the admin page runs its first worker right here.
     *
     * @param string[] $names Autoloaded options deleted behind the cache.
     * @return void
     */
    private static function forget_cached_options(array $names)
    {
        if (!function_exists('wp_cache_delete')) {
            return;
        }
        foreach ($names as $name) {
            wp_cache_delete($name, 'options');
        }
        if (!function_exists('wp_load_alloptions') || !function_exists('wp_cache_set')) {
            return;
        }
        $alloptions = wp_load_alloptions(true);
        $changed = false;
        foreach ($names as $name) {
            if (is_array($alloptions) && array_key_exists($name, $alloptions)) {
                unset($alloptions[$name]);
                $changed = true;
            }
        }
        if ($changed) {
            wp_cache_set('alloptions', $alloptions, 'options');
        }
    }

    public static function delete_deprecated_batch_process()
    {
        global $wpdb;

        $table  = $wpdb->options;
        $column = 'option_name';

        if ( is_multisite() ) {
            $table  = $wpdb->sitemeta;
            $column = 'meta_key';
        }

        $likes = array();
        foreach (array('upload', 'backup', 'download') as $action) {
            $likes = array_merge($likes, self::queue_batch_like_patterns($wpdb, $action));
        }
        $where = implode(' OR ', array_fill(0, count($likes), "{$column} LIKE %s"));
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE {$where}", $likes));
    }

    /**
     * Drop only the backup queue, leaving a running restore's queue and
     * status untouched.
     */
    public static function delete_backup_batch_process()
    {
        global $wpdb;

        $table  = $wpdb->options;
        $column = 'option_name';

        if ( is_multisite() ) {
            $table  = $wpdb->sitemeta;
            $column = 'meta_key';
        }

        foreach (self::queue_batch_like_patterns($wpdb, 'backup') as $like) {
            $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE {$column} LIKE %s", $like));
        }
    }

    /**
     * LIKE patterns for an action's queue rows: the library's real key, plus
     * the legacy table-prefix key older cleanup code targeted.
     *
     * @param \wpdb  $wpdb
     * @param string $action 'backup', 'restore', ... or '%' for any action.
     * @return string[]
     */
    private static function queue_batch_like_patterns($wpdb, $action)
    {
        $action_like = $action === '%' ? '%' : $wpdb->esc_like($action);
        $prefixes = array(self::QUEUE_KEY_PREFIX);
        $legacy = $wpdb->base_prefix . 'ocm_background_';
        if ($legacy !== self::QUEUE_KEY_PREFIX) {
            $prefixes[] = $legacy;
        }

        $patterns = array();
        foreach ($prefixes as $prefix) {
            $patterns[] = $wpdb->esc_like($prefix) . $action_like . $wpdb->esc_like('_batch_') . '%';
        }
        return $patterns;
    }
}
