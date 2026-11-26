<?php
/**
 * Free Site Cleanup: remove database leftovers (old revisions, spam, expired
 * temporary data, orphaned rows, optionally the trash) and show what takes up
 * space.
 *
 * Scan counts each item and estimates its size; nothing changes. Clean takes
 * a database-only local backup first (OCM_Guarded_Run), then deletes in
 * batches of up to BATCH rows. Every batch selects again with the item's
 * rule, so a step that dies is simply repeated: there is no position to
 * lose. Children go before their rows (meta, comments, term relationships,
 * then the post), so an interrupted batch never leaves orphans behind.
 *
 * Sizes are the bytes in the removed rows: what a backup and a migration no
 * longer carry. MySQL keeps the freed space inside the table files until a
 * table is rebuilt, which is what the optional "Reclaim space" item does.
 */

namespace OCM;

use WP_Error;

require_once __DIR__ . '/class-ocm-guarded-run.php';

class OCM_Site_Cleanup extends OCM_Guarded_Run
{
    const OPTION_RUN = 'ocm_cleanup_run';
    /** The last scan, for the checklist and the hint on the Cloud Migration tab. */
    const OPTION_SCAN = 'ocm_cleanup_scan';
    const STEP_LOCK = 'ocm_cleanup_step';
    const STATE_CLEANING = 'cleaning';
    const STATE_WORKING = 'cleaning';
    const ACTIVITY_TYPE = 'site_cleanup';
    const RUN_ID_PREFIX = 'cl_';
    const LOG_NAME = 'Site Cleanup';

    const BATCH = 1000;
    const REVISION_DAYS = 30;
    const AUTO_DRAFT_DAYS = 7;
    const SCAN_SECONDS = 8;
    /** A scan older than this no longer drives the Cloud Migration hint. */
    const SCAN_FRESH_SECONDS = 604800;
    /** The Cloud Migration hint shows from this many removable bytes. */
    const HINT_MIN_BYTES = 5242880;
    /** "Reclaim space" rebuilds tables with at least this much unused space. */
    const RECLAIM_MIN_FREE = 1048576;

    /** Rough bytes per row besides its text columns (keys, dates, indexes). */
    const ROW_BYTES_POST = 300;
    const ROW_BYTES_META = 40;
    const ROW_BYTES_COMMENT = 150;
    const ROW_BYTES_OPTION = 40;

    /** Items in the order they run. Reclaim goes last, after the deletes. */
    const ITEM_IDS = array(
        'revisions',
        'auto_drafts',
        'spam_comments',
        'expired_transients',
        'orphan_postmeta',
        'orphan_commentmeta',
        'trash',
        'reclaim',
    );

    /** Folders other plugins keep backups in, matched on the folder name. */
    const BACKUP_DIR_PATTERNS = array(
        '/^ai1wm-backups$/i',
        '/^updraft$/i',
        '/^backups-dup-(lite|pro)$/i',
        '/^wpvividbackups$/i',
        '/^backwpup-[a-z0-9]+-(backups|logs|temp)$/i',
        '/^backupbuddy_backups$/i',
        '/^pb_backupbuddy$/i',
        '/^backupwordpress-[a-z0-9-]+-backups$/i',
        '/^backup-db$/i',
        '/^ShortPixelBackups$/i',
        '/^wp-clone$/i',
        '/^wpvivid_?(uploads|backups)?$/i',
    );

    /** @var callable|null Tests set the clock. */
    public static $now = null;

    // ------------------------------------------------------------------
    // Items
    // ------------------------------------------------------------------

    /**
     * Every item with its label, explanation and whether it is ticked.
     *
     * @return array<string, array{label: string, help: string, default: bool}>
     */
    public static function items()
    {
        return array(
            'revisions' => array(
                'label' => __('Old revisions', '1-click-migration'),
                'help' => sprintf(
                    /* translators: %d: number of days */
                    __('Earlier versions of posts and pages saved more than %d days ago. The current version of every post stays.', '1-click-migration'),
                    self::REVISION_DAYS
                ),
                'default' => true,
            ),
            'auto_drafts' => array(
                'label' => __('Old auto-drafts', '1-click-migration'),
                'help' => sprintf(
                    /* translators: %d: number of days */
                    __('Empty drafts WordPress starts each time the editor opens, older than %d days.', '1-click-migration'),
                    self::AUTO_DRAFT_DAYS
                ),
                'default' => true,
            ),
            'spam_comments' => array(
                'label' => __('Spam comments', '1-click-migration'),
                'help' => __('Comments marked as spam.', '1-click-migration'),
                'default' => true,
            ),
            'expired_transients' => array(
                'label' => __('Expired temporary data', '1-click-migration'),
                'help' => __('Cached data past its expiry date. Plugins create it again when they need it.', '1-click-migration'),
                'default' => true,
            ),
            'orphan_postmeta' => array(
                'label' => __('Leftover post data', '1-click-migration'),
                'help' => __('Extra data that belonged to posts that no longer exist.', '1-click-migration'),
                'default' => true,
            ),
            'orphan_commentmeta' => array(
                'label' => __('Leftover comment data', '1-click-migration'),
                'help' => __('Extra data that belonged to comments that no longer exist.', '1-click-migration'),
                'default' => true,
            ),
            'trash' => array(
                'label' => __('Trash', '1-click-migration'),
                'help' => __('Posts, pages and comments in the trash. Media and store orders in the trash are left alone.', '1-click-migration'),
                'default' => false,
            ),
            'reclaim' => array(
                'label' => __('Reclaim space', '1-click-migration'),
                'help' => __('Rebuild tables with unused space so your host gets it back. Runs after the other items and can take a while on large sites.', '1-click-migration'),
                'default' => false,
            ),
        );
    }

    private static function now()
    {
        return self::$now !== null ? (int) call_user_func(self::$now) : time();
    }

    /** 'Y-m-d H:i:s' in UTC, $days ago. */
    private static function gmt_cutoff($days)
    {
        return gmdate('Y-m-d H:i:s', self::now() - $days * 86400);
    }

    /** 'Y-m-d H:i:s' in the site's time zone, $days ago (post_date is local). */
    private static function local_cutoff($days)
    {
        $offset = (int) round((float) get_option('gmt_offset', 0) * 3600);
        return gmdate('Y-m-d H:i:s', self::now() + $offset - $days * 86400);
    }

    private static function db()
    {
        global $wpdb;
        return $wpdb;
    }

    /** A comma-separated list of integers for IN (...). */
    private static function int_list(array $ids)
    {
        return implode(',', array_map('intval', $ids));
    }

    /** A comma-separated list of quoted strings for IN (...). */
    private static function str_list(array $values)
    {
        $db = self::db();
        $out = array();
        foreach ($values as $v) {
            $out[] = $db->prepare('%s', (string) $v);
        }
        return implode(',', $out);
    }

    /** The WHERE part that picks an item's posts (alias p). */
    private static function post_rule($item)
    {
        $db = self::db();
        switch ($item) {
            case 'revisions':
                return $db->prepare("p.post_type = 'revision' AND p.post_modified_gmt < %s", self::gmt_cutoff(self::REVISION_DAYS));
            case 'auto_drafts':
                return $db->prepare("p.post_status = 'auto-draft' AND p.post_date < %s", self::local_cutoff(self::AUTO_DRAFT_DAYS));
            case 'trash':
                // Never media (its files are not in a database backup) and
                // never WooCommerce orders, products or coupons.
                return "p.post_status = 'trash' AND p.post_type NOT IN ('attachment', 'product', 'product_variation')"
                    . " AND p.post_type NOT LIKE 'shop!_%' ESCAPE '!' AND p.post_type NOT LIKE 'wc!_%' ESCAPE '!'";
        }
        return '1 = 0';
    }

    /** The WHERE part that picks an item's comments (alias c). */
    private static function comment_rule($item)
    {
        return $item === 'spam_comments' ? "c.comment_approved = 'spam'" : "c.comment_approved = 'trash'";
    }

    private static function transient_rule()
    {
        return "(o.option_name LIKE '!_transient!_timeout!_%' ESCAPE '!' OR o.option_name LIKE '!_site!_transient!_timeout!_%' ESCAPE '!')"
            . ' AND (o.option_value + 0) < ' . (int) self::now();
    }

    // ------------------------------------------------------------------
    // Scan
    // ------------------------------------------------------------------

    /**
     * Count one item and estimate the bytes it would remove.
     *
     * @return array{count: int, bytes: int}
     */
    public static function measure($item)
    {
        $db = self::db();
        $count = 0;
        $bytes = 0;
        $add = static function ($row, $per_row) use (&$count, &$bytes) {
            $row = is_array($row) ? array_values($row) : array(0, 0);
            $n = isset($row[0]) ? (int) $row[0] : 0;
            $count += $n;
            $bytes += (isset($row[1]) ? (int) $row[1] : 0) + $n * $per_row;
        };
        $add_bytes_only = static function ($row, $per_row) use (&$bytes) {
            $row = is_array($row) ? array_values($row) : array(0, 0);
            $n = isset($row[0]) ? (int) $row[0] : 0;
            $bytes += (isset($row[1]) ? (int) $row[1] : 0) + $n * $per_row;
        };
        $post_text = 'COALESCE(LENGTH(p.post_content), 0) + COALESCE(LENGTH(p.post_title), 0) + COALESCE(LENGTH(p.post_excerpt), 0)';

        switch ($item) {
            case 'revisions':
            case 'auto_drafts':
            case 'trash':
                $rule = self::post_rule($item);
                $add($db->get_row("SELECT COUNT(*), SUM($post_text) FROM {$db->posts} p WHERE $rule", 'ARRAY_N'), self::ROW_BYTES_POST);
                $add_bytes_only($db->get_row("SELECT COUNT(*), SUM(COALESCE(LENGTH(m.meta_value), 0) + LENGTH(m.meta_key)) FROM {$db->postmeta} m INNER JOIN {$db->posts} p ON p.ID = m.post_id WHERE $rule", 'ARRAY_N'), self::ROW_BYTES_META);
                if ($item === 'trash') {
                    // The trashed posts' own revisions go with them.
                    $add_bytes_only($db->get_row("SELECT COUNT(*), SUM(COALESCE(LENGTH(r.post_content), 0)) FROM {$db->posts} r INNER JOIN {$db->posts} p ON p.ID = r.post_parent WHERE r.post_type = 'revision' AND $rule", 'ARRAY_N'), self::ROW_BYTES_POST);
                    $add($db->get_row("SELECT COUNT(*), SUM(COALESCE(LENGTH(c.comment_content), 0)) FROM {$db->comments} c WHERE " . self::comment_rule('trash'), 'ARRAY_N'), self::ROW_BYTES_COMMENT);
                }
                break;
            case 'spam_comments':
                $add($db->get_row("SELECT COUNT(*), SUM(COALESCE(LENGTH(c.comment_content), 0)) FROM {$db->comments} c WHERE " . self::comment_rule($item), 'ARRAY_N'), self::ROW_BYTES_COMMENT);
                $add_bytes_only($db->get_row("SELECT COUNT(*), SUM(COALESCE(LENGTH(m.meta_value), 0)) FROM {$db->commentmeta} m INNER JOIN {$db->comments} c ON c.comment_ID = m.comment_id WHERE " . self::comment_rule($item), 'ARRAY_N'), self::ROW_BYTES_META);
                break;
            case 'expired_transients':
                // Each expired timeout row has a value row next to it.
                $row = $db->get_row('SELECT COUNT(*), SUM(LENGTH(o.option_name)) FROM ' . $db->options . ' o WHERE ' . self::transient_rule(), 'ARRAY_N');
                $add($row, self::ROW_BYTES_OPTION);
                $values = $db->get_var('SELECT SUM(COALESCE(LENGTH(v.option_value), 0) + LENGTH(v.option_name)) FROM ' . $db->options . ' v INNER JOIN ' . $db->options
                    . " o ON v.option_name = REPLACE(o.option_name, '_timeout_', '_') WHERE " . self::transient_rule());
                $bytes += (int) $values + $count * self::ROW_BYTES_OPTION;
                break;
            case 'orphan_postmeta':
                $add($db->get_row("SELECT COUNT(*), SUM(COALESCE(LENGTH(m.meta_value), 0) + LENGTH(m.meta_key)) FROM {$db->postmeta} m LEFT JOIN {$db->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL", 'ARRAY_N'), self::ROW_BYTES_META);
                break;
            case 'orphan_commentmeta':
                $add($db->get_row("SELECT COUNT(*), SUM(COALESCE(LENGTH(m.meta_value), 0) + LENGTH(m.meta_key)) FROM {$db->commentmeta} m LEFT JOIN {$db->comments} c ON c.comment_ID = m.comment_id WHERE c.comment_ID IS NULL", 'ARRAY_N'), self::ROW_BYTES_META);
                break;
            case 'reclaim':
                foreach (self::tables_with_free_space() as $t) {
                    $count++;
                    $bytes += $t['free'];
                }
                break;
        }
        return array('count' => $count, 'bytes' => $bytes);
    }

    /**
     * This site's tables with at least RECLAIM_MIN_FREE unused bytes.
     *
     * @return array<int, array{name: string, free: int}>
     */
    public static function tables_with_free_space()
    {
        $out = array();
        foreach (self::table_status() as $t) {
            if ($t['free'] >= self::RECLAIM_MIN_FREE) {
                $out[] = array('name' => $t['name'], 'free' => $t['free']);
            }
        }
        return $out;
    }

    /**
     * Name, rows, size and unused space of this site's tables.
     *
     * @return array<int, array{name: string, rows: int, size: int, free: int}>
     */
    public static function table_status()
    {
        $db = self::db();
        if (!is_object($db) || !method_exists($db, 'get_results')) {
            return array();
        }
        $prefix = isset($db->base_prefix) ? (string) $db->base_prefix : 'wp_';
        $rows = $db->get_results($db->prepare('SHOW TABLE STATUS LIKE %s', $db->esc_like($prefix) . '%'), 'ARRAY_A');
        $out = array();
        foreach ((array) $rows as $row) {
            $name = isset($row['Name']) ? (string) $row['Name'] : '';
            // Never restore staging tables (ocm + 4 hex + _).
            if ($name === '' || strpos($name, $prefix) !== 0 || preg_match('/^ocm[0-9a-f]{4}_/', $name)) {
                continue;
            }
            $out[] = array(
                'name' => $name,
                'rows' => isset($row['Rows']) ? (int) $row['Rows'] : 0,
                'size' => (isset($row['Data_length']) ? (int) $row['Data_length'] : 0) + (isset($row['Index_length']) ? (int) $row['Index_length'] : 0),
                'free' => isset($row['Data_free']) ? (int) $row['Data_free'] : 0,
            );
        }
        return $out;
    }

    /**
     * Measure items for up to SCAN_SECONDS, continuing an unfinished scan.
     * The finished scan is stored for the checklist and the migration hint.
     *
     * @param bool $restart Start over instead of continuing.
     * @return array|WP_Error The scan as the screen shows it, with 'done'.
     */
    public static function scan_step($restart)
    {
        if (function_exists('is_multisite') && is_multisite()) {
            return new WP_Error('cleanup_multisite', __('Site Cleanup isn\'t available on multisite networks yet.', '1-click-migration'));
        }
        if (self::is_working()) {
            return new WP_Error('operation_in_progress', __('A cleanup is running. Scan again when it has finished.', '1-click-migration'));
        }
        $scan = $restart ? null : get_option(self::OPTION_SCAN, null);
        if (!is_array($scan) || !isset($scan['items']) || !empty($scan['done']) && $restart) {
            $scan = array('items' => array(), 'next' => 0, 'done' => false, 'started_at' => self::now(), 'scanned_at' => 0);
        }
        if (!empty($scan['done'])) {
            return self::public_scan($scan);
        }
        $deadline = microtime(true) + self::SCAN_SECONDS;
        $ids = self::ITEM_IDS;
        while ($scan['next'] < count($ids)) {
            $item = $ids[$scan['next']];
            $scan['items'][$item] = self::measure($item);
            $scan['next']++;
            if (microtime(true) >= $deadline) {
                break;
            }
        }
        if ($scan['next'] >= count($ids)) {
            $scan['done'] = true;
            $scan['scanned_at'] = self::now();
        }
        update_option(self::OPTION_SCAN, $scan, false);
        return self::public_scan($scan);
    }

    /**
     * The stored scan with labels, for the screen.
     *
     * @param array|null $scan
     * @return array|null
     */
    public static function public_scan($scan = null)
    {
        if ($scan === null) {
            $scan = get_option(self::OPTION_SCAN, null);
        }
        if (!is_array($scan) || !isset($scan['items'])) {
            return null;
        }
        $items = array();
        $total = 0;
        foreach (self::items() as $id => $def) {
            $m = isset($scan['items'][$id]) ? $scan['items'][$id] : null;
            $items[] = array(
                'id' => $id,
                'label' => $def['label'],
                'help' => $def['help'],
                'default' => $def['default'],
                'measured' => $m !== null,
                'count' => $m ? (int) $m['count'] : 0,
                'bytes' => $m ? (int) $m['bytes'] : 0,
                'size' => $m ? self::readable((int) $m['bytes']) : '',
            );
            if ($m && $def['default'] && $id !== 'reclaim') {
                $total += (int) $m['bytes'];
            }
        }
        return array(
            'done' => !empty($scan['done']),
            'progress' => (int) floor(100 * min(count(self::ITEM_IDS), (int) $scan['next']) / count(self::ITEM_IDS)),
            'items' => $items,
            'total_bytes' => $total,
            'total' => self::readable($total),
            'scanned_at' => (int) $scan['scanned_at'],
        );
    }

    /**
     * The line for the Cloud Migration tab: '' unless a recent scan found
     * enough to remove.
     */
    public static function migration_hint()
    {
        $scan = self::public_scan();
        if (!$scan || !$scan['done'] || self::now() - $scan['scanned_at'] > self::SCAN_FRESH_SECONDS || $scan['total_bytes'] < self::HINT_MIN_BYTES) {
            return '';
        }
        return sprintf(
            /* translators: %s: size, e.g. 180 MB */
            __('Clean up first: about %s can be removed, so your backup is smaller and faster.', '1-click-migration'),
            $scan['total']
        );
    }

    private static function readable($bytes)
    {
        return method_exists(OCM_Backup::class, 'readable_bytes') ? OCM_Backup::readable_bytes((int) $bytes) : ((int) $bytes . ' B');
    }

    // ------------------------------------------------------------------
    // Deleting
    // ------------------------------------------------------------------

    /**
     * Delete up to BATCH rows of one item.
     *
     * @return array{rows: int, bytes: int, more: bool}|WP_Error
     */
    public static function delete_batch($item)
    {
        switch ($item) {
            case 'revisions':
            case 'auto_drafts':
                return self::delete_posts_batch($item);
            case 'trash':
                $posts = self::delete_posts_batch('trash');
                if (is_wp_error($posts) || $posts['rows'] > 0) {
                    // Comments in the trash once the trashed posts are gone.
                    if (!is_wp_error($posts)) {
                        $posts['more'] = true;
                    }
                    return $posts;
                }
                return self::delete_comments_batch('trash');
            case 'spam_comments':
                return self::delete_comments_batch($item);
            case 'expired_transients':
                return self::delete_transients_batch();
            case 'orphan_postmeta':
                return self::delete_orphan_meta_batch('post');
            case 'orphan_commentmeta':
                return self::delete_orphan_meta_batch('comment');
        }
        return array('rows' => 0, 'bytes' => 0, 'more' => false);
    }

    /** Run one statement; false and the database's error when it failed. */
    private static function exec($sql)
    {
        $db = self::db();
        $db->last_error = '';
        $res = $db->query($sql);
        if ($res === false) {
            return new WP_Error('db_error', (string) $db->last_error);
        }
        return true;
    }

    private static function delete_posts_batch($item)
    {
        $db = self::db();
        $ids = array_map('intval', (array) $db->get_col("SELECT p.ID FROM {$db->posts} p WHERE " . self::post_rule($item) . ' ORDER BY p.ID LIMIT ' . self::BATCH));
        if (!$ids) {
            return array('rows' => 0, 'bytes' => 0, 'more' => false);
        }
        $all = $ids;
        if ($item === 'trash') {
            // The trashed posts' revisions go with them, as in wp_delete_post().
            $revisions = array_map('intval', (array) $db->get_col("SELECT ID FROM {$db->posts} WHERE post_type = 'revision' AND post_parent IN (" . self::int_list($ids) . ')'));
            $all = array_values(array_unique(array_merge($ids, $revisions)));
        }
        $list = self::int_list($all);
        $bytes = self::posts_bytes($list);

        if ($item === 'trash') {
            $comment_ids = array_map('intval', (array) $db->get_col("SELECT comment_ID FROM {$db->comments} WHERE comment_post_ID IN ($list)"));
            if ($comment_ids) {
                $res = self::remove_comments($comment_ids);
                if (is_wp_error($res)) {
                    return $res;
                }
                $bytes += $res;
            }
        }
        $steps = array(
            "DELETE FROM {$db->postmeta} WHERE post_id IN ($list)",
            // Only post taxonomies: link_category relationships use link ids.
            "DELETE FROM {$db->term_relationships} WHERE object_id IN ($list) AND term_taxonomy_id IN (SELECT term_taxonomy_id FROM {$db->term_taxonomy} WHERE taxonomy <> 'link_category')",
            "DELETE FROM {$db->posts} WHERE ID IN ($list)",
        );
        foreach ($steps as $sql) {
            $res = self::exec($sql);
            if (is_wp_error($res)) {
                return $res;
            }
        }
        return array('rows' => count($ids), 'bytes' => $bytes, 'more' => count($ids) >= self::BATCH);
    }

    private static function posts_bytes($list)
    {
        $db = self::db();
        $posts = (array) $db->get_row("SELECT COUNT(*), SUM(COALESCE(LENGTH(post_content), 0) + COALESCE(LENGTH(post_title), 0) + COALESCE(LENGTH(post_excerpt), 0)) FROM {$db->posts} WHERE ID IN ($list)", 'ARRAY_N');
        $meta = (array) $db->get_row("SELECT COUNT(*), SUM(COALESCE(LENGTH(meta_value), 0) + LENGTH(meta_key)) FROM {$db->postmeta} WHERE post_id IN ($list)", 'ARRAY_N');
        return (int) (isset($posts[1]) ? $posts[1] : 0) + (int) (isset($posts[0]) ? $posts[0] : 0) * self::ROW_BYTES_POST
            + (int) (isset($meta[1]) ? $meta[1] : 0) + (int) (isset($meta[0]) ? $meta[0] : 0) * self::ROW_BYTES_META;
    }

    private static function delete_comments_batch($item)
    {
        $db = self::db();
        $ids = array_map('intval', (array) $db->get_col("SELECT c.comment_ID FROM {$db->comments} c WHERE " . self::comment_rule($item) . ' ORDER BY c.comment_ID LIMIT ' . self::BATCH));
        if (!$ids) {
            return array('rows' => 0, 'bytes' => 0, 'more' => false);
        }
        $bytes = self::remove_comments($ids);
        if (is_wp_error($bytes)) {
            return $bytes;
        }
        return array('rows' => count($ids), 'bytes' => $bytes, 'more' => count($ids) >= self::BATCH);
    }

    /**
     * Delete comments with their meta; replies to them move up to the top
     * level instead of pointing at a comment that is gone.
     *
     * @param int[] $ids
     * @return int|WP_Error Bytes removed.
     */
    private static function remove_comments(array $ids)
    {
        $db = self::db();
        $list = self::int_list($ids);
        $c = (array) $db->get_row("SELECT COUNT(*), SUM(COALESCE(LENGTH(comment_content), 0)) FROM {$db->comments} WHERE comment_ID IN ($list)", 'ARRAY_N');
        $m = (array) $db->get_row("SELECT COUNT(*), SUM(COALESCE(LENGTH(meta_value), 0) + LENGTH(meta_key)) FROM {$db->commentmeta} WHERE comment_id IN ($list)", 'ARRAY_N');
        $bytes = (int) (isset($c[1]) ? $c[1] : 0) + (int) (isset($c[0]) ? $c[0] : 0) * self::ROW_BYTES_COMMENT
            + (int) (isset($m[1]) ? $m[1] : 0) + (int) (isset($m[0]) ? $m[0] : 0) * self::ROW_BYTES_META;
        foreach (array(
            "UPDATE {$db->comments} SET comment_parent = 0 WHERE comment_parent IN ($list) AND comment_ID NOT IN ($list)",
            "DELETE FROM {$db->commentmeta} WHERE comment_id IN ($list)",
            "DELETE FROM {$db->comments} WHERE comment_ID IN ($list)",
        ) as $sql) {
            $res = self::exec($sql);
            if (is_wp_error($res)) {
                return $res;
            }
        }
        return $bytes;
    }

    private static function delete_transients_batch()
    {
        $db = self::db();
        $timeouts = (array) $db->get_col('SELECT o.option_name FROM ' . $db->options . ' o WHERE ' . self::transient_rule() . ' ORDER BY o.option_name LIMIT ' . self::BATCH);
        if (!$timeouts) {
            return array('rows' => 0, 'bytes' => 0, 'more' => false);
        }
        $names = array();
        foreach ($timeouts as $timeout) {
            $names[] = (string) $timeout;
            // _transient_timeout_x -> _transient_x, _site_transient_timeout_x -> _site_transient_x
            $names[] = preg_replace('/_timeout_/', '_', (string) $timeout, 1);
        }
        $list = self::str_list($names);
        $b = (array) $db->get_row("SELECT COUNT(*), SUM(COALESCE(LENGTH(option_value), 0) + LENGTH(option_name)) FROM {$db->options} WHERE option_name IN ($list)", 'ARRAY_N');
        $res = self::exec("DELETE FROM {$db->options} WHERE option_name IN ($list)");
        if (is_wp_error($res)) {
            return $res;
        }
        return array(
            'rows' => count($timeouts),
            'bytes' => (int) (isset($b[1]) ? $b[1] : 0) + (int) (isset($b[0]) ? $b[0] : 0) * self::ROW_BYTES_OPTION,
            'more' => count($timeouts) >= self::BATCH,
        );
    }

    private static function delete_orphan_meta_batch($kind)
    {
        $db = self::db();
        if ($kind === 'post') {
            $meta = $db->postmeta;
            $sql = "SELECT m.meta_id FROM {$db->postmeta} m LEFT JOIN {$db->posts} p ON p.ID = m.post_id WHERE p.ID IS NULL ORDER BY m.meta_id LIMIT " . self::BATCH;
        } else {
            $meta = $db->commentmeta;
            $sql = "SELECT m.meta_id FROM {$db->commentmeta} m LEFT JOIN {$db->comments} c ON c.comment_ID = m.comment_id WHERE c.comment_ID IS NULL ORDER BY m.meta_id LIMIT " . self::BATCH;
        }
        $ids = array_map('intval', (array) $db->get_col($sql));
        if (!$ids) {
            return array('rows' => 0, 'bytes' => 0, 'more' => false);
        }
        $list = self::int_list($ids);
        $b = (array) $db->get_row("SELECT COUNT(*), SUM(COALESCE(LENGTH(meta_value), 0) + LENGTH(meta_key)) FROM $meta WHERE meta_id IN ($list)", 'ARRAY_N');
        $res = self::exec("DELETE FROM $meta WHERE meta_id IN ($list)");
        if (is_wp_error($res)) {
            return $res;
        }
        return array(
            'rows' => count($ids),
            'bytes' => (int) (isset($b[1]) ? $b[1] : 0) + (int) (isset($b[0]) ? $b[0] : 0) * self::ROW_BYTES_META,
            'more' => count($ids) >= self::BATCH,
        );
    }

    // ------------------------------------------------------------------
    // Cleanup run (the shared part is OCM_Guarded_Run)
    // ------------------------------------------------------------------

    /**
     * Start a cleanup of the chosen items: refuse while anything else runs,
     * then take the database backup that Undo restores.
     *
     * @param array $input items[]
     * @return array|WP_Error
     */
    public static function start(array $input)
    {
        if (function_exists('is_multisite') && is_multisite()) {
            return new WP_Error('cleanup_multisite', __('Site Cleanup isn\'t available on multisite networks yet.', '1-click-migration'));
        }
        $chosen = isset($input['items']) && is_array($input['items']) ? array_map('strval', $input['items']) : array();
        $items = array_values(array_intersect(self::ITEM_IDS, $chosen)); // known ids, in run order
        if (!$items) {
            return new WP_Error('cleanup_no_items', __('Choose at least one thing to remove.', '1-click-migration'));
        }
        return static::begin(array(
            'items' => $items,
            'item_index' => 0,
            'counts' => array(),
            'reclaim_tables' => null,
        ));
    }

    protected static function run_label(array $run)
    {
        return __('Before cleanup', '1-click-migration');
    }

    protected static function messages()
    {
        return array(
            'backup_failed' => __('The database backup didn\'t finish, so nothing was removed. See the Local Backups log for why.', '1-click-migration'),
            'stopped_before' => __('Stopped before anything was removed.', '1-click-migration'),
            'stopped_during' => __('Stopped. What was removed so far stays removed. Click Undo to put the database back as it was before.', '1-click-migration'),
        );
    }

    /** @return array{rows: int, bytes: int, tables: int} */
    private static function totals(array $run)
    {
        $rows = 0;
        $bytes = 0;
        $tables = 0;
        foreach ((array) (isset($run['counts']) ? $run['counts'] : array()) as $item => $c) {
            if ($item === 'reclaim') {
                $tables += (int) $c['rows'];
                continue;
            }
            $rows += (int) $c['rows'];
            $bytes += (int) $c['bytes'];
        }
        return array('rows' => $rows, 'bytes' => $bytes, 'tables' => $tables);
    }

    protected static function done_message(array $run)
    {
        $t = self::totals($run);
        if ($t['rows'] === 0 && $t['tables'] === 0) {
            return __('Nothing needed removing.', '1-click-migration');
        }
        $rebuilt = $t['tables'] > 0 ? sprintf(
            /* translators: %s: number of tables */
            _n('Rebuilt %s table.', 'Rebuilt %s tables.', $t['tables'], '1-click-migration'),
            number_format_i18n($t['tables'])
        ) : '';
        if ($t['rows'] === 0) {
            return $rebuilt;
        }
        $message = sprintf(
            /* translators: 1: number of items, 2: size, e.g. 180 MB */
            _n('Removed %1$s item, about %2$s less to back up and move.', 'Removed %1$s items, about %2$s less to back up and move.', $t['rows'], '1-click-migration'),
            number_format_i18n($t['rows']),
            self::readable($t['bytes'])
        );
        return $rebuilt !== '' ? $message . ' ' . $rebuilt : $message;
    }

    protected static function size_bytes(array $run)
    {
        $t = self::totals($run);
        return $t['bytes'];
    }

    /**
     * Delete batches until step_seconds() runs out; at most one table
     * rebuild per step, since one can take long.
     */
    protected static function work_step(array $run)
    {
        $items = isset($run['items']) && is_array($run['items']) ? array_values($run['items']) : array();
        $deadline = microtime(true) + static::step_seconds();
        $counts = isset($run['counts']) && is_array($run['counts']) ? $run['counts'] : array();

        do {
            $index = (int) $run['item_index'];
            if ($index >= count($items)) {
                break;
            }
            $item = $items[$index];
            if (!isset($counts[$item])) {
                $counts[$item] = array('rows' => 0, 'bytes' => 0);
            }

            if ($item === 'reclaim') {
                if (!is_array($run['reclaim_tables'])) {
                    $run['reclaim_tables'] = array_column(self::tables_with_free_space(), 'name');
                }
                $table = array_shift($run['reclaim_tables']);
                if ($table === null) {
                    $run['item_index']++;
                    continue;
                }
                $res = self::exec('OPTIMIZE TABLE `' . str_replace('`', '', (string) $table) . '`');
                if (is_wp_error($res)) {
                    // A table that can't be rebuilt is left as it is.
                    $run['warnings'][] = sprintf(
                        /* translators: %s: table name */
                        __('Table %s could not be rebuilt.', '1-click-migration'),
                        $table
                    );
                } else {
                    $counts[$item]['rows']++;
                }
                break;
            }

            $batch = self::delete_batch($item);
            if (is_wp_error($batch)) {
                $run['counts'] = $counts;
                return static::finish($run, self::STATE_FAILED, sprintf(
                    /* translators: %s: database error */
                    __('The database refused a change: %s What was removed so far stays removed. Click Undo to put the database back as it was before.', '1-click-migration'),
                    trim($batch->get_error_message()) !== '' ? rtrim($batch->get_error_message(), '.') . '.' : ''
                ), 'db_error');
            }
            $counts[$item]['rows'] += $batch['rows'];
            $counts[$item]['bytes'] += $batch['bytes'];
            if (!$batch['more']) {
                $run['item_index']++;
            }
        } while (microtime(true) < $deadline);

        $run['counts'] = $counts;
        $stopped = static::stopped_meanwhile($run, array('counts' => $counts));
        if ($stopped !== null) {
            return $stopped;
        }
        if ((int) $run['item_index'] >= count($items)) {
            // The stored scan no longer says what is left.
            delete_option(self::OPTION_SCAN);
            return static::finish($run, self::STATE_DONE, '', '');
        }
        return static::save_run($run);
    }

    protected static function progress_percent(array $run)
    {
        $items = isset($run['items']) && is_array($run['items']) ? $run['items'] : array();
        return (int) floor(100 * min((int) $run['item_index'], count($items)) / max(1, count($items)));
    }

    protected static function public_extra(array $run)
    {
        $defs = self::items();
        $items = isset($run['items']) && is_array($run['items']) ? array_values($run['items']) : array();
        $index = isset($run['item_index']) ? (int) $run['item_index'] : 0;
        $counts = array();
        foreach ($items as $item) {
            $c = isset($run['counts'][$item]) ? $run['counts'][$item] : array('rows' => 0, 'bytes' => 0);
            $counts[] = array(
                'id' => $item,
                'label' => isset($defs[$item]) ? $defs[$item]['label'] : $item,
                'rows' => (int) $c['rows'],
                'size' => $item === 'reclaim' ? '' : self::readable((int) $c['bytes']),
            );
        }
        $t = self::totals($run);
        return array(
            'items_total' => count($items),
            'items_done' => min($index, count($items)),
            'current_item' => isset($items[$index], $defs[$items[$index]]) ? $defs[$items[$index]]['label'] : '',
            'counts' => $counts,
            'removed' => $t['rows'],
            'removed_size' => self::readable($t['bytes']),
        );
    }

    // ------------------------------------------------------------------
    // What's taking up space (read-only)
    // ------------------------------------------------------------------

    /**
     * The largest tables, the largest uploads folders, other plugins' backup
     * folders and inactive themes and plugins. Changes nothing.
     *
     * @return array
     */
    public static function space_report()
    {
        $tables = self::table_status();
        usort($tables, static function ($a, $b) {
            return $b['size'] - $a['size'];
        });
        $tables = array_slice($tables, 0, 8);
        foreach ($tables as &$t) {
            $t['size_readable'] = self::readable($t['size']);
            $t['free_readable'] = $t['free'] >= self::RECLAIM_MIN_FREE ? self::readable($t['free']) : '';
        }
        unset($t);

        $deadline = microtime(true) + 6;
        $files_left = 200000;
        $partial = false;
        $folders = array();
        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir(null, false) : array();
        $uploads_dir = is_array($uploads) && !empty($uploads['basedir']) ? (string) $uploads['basedir'] : '';
        foreach (self::subfolders($uploads_dir) as $name => $path) {
            list($size, $cut) = self::folder_size($path, $deadline, $files_left);
            $partial = $partial || $cut;
            $folders[] = array('name' => 'uploads/' . $name, 'size' => $size, 'backup' => self::is_backup_folder($name), 'moved' => true);
        }
        $content_dir = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '';
        foreach (self::subfolders($content_dir) as $name => $path) {
            if (!self::is_backup_folder($name)) {
                continue; // only other plugins' backups; themes, plugins and uploads have their own lines
            }
            list($size, $cut) = self::folder_size($path, $deadline, $files_left);
            $partial = $partial || $cut;
            $folders[] = array('name' => basename($content_dir) . '/' . $name, 'size' => $size, 'backup' => true, 'moved' => false);
        }
        usort($folders, static function ($a, $b) {
            return $b['size'] - $a['size'];
        });
        $folders = array_slice($folders, 0, 10);
        foreach ($folders as &$f) {
            $f['size_readable'] = self::readable($f['size']);
        }
        unset($f);

        return array(
            'tables' => $tables,
            'folders' => $folders,
            'partial' => $partial,
            'inactive_themes' => self::inactive_themes(),
            'inactive_plugins' => self::inactive_plugins(),
            'themes_url' => function_exists('admin_url') ? admin_url('themes.php') : '',
            'plugins_url' => function_exists('admin_url') ? admin_url('plugins.php?plugin_status=inactive') : '',
        );
    }

    public static function is_backup_folder($name)
    {
        foreach (self::BACKUP_DIR_PATTERNS as $pattern) {
            if (preg_match($pattern, (string) $name)) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, string> name => path of the folders directly in $dir */
    private static function subfolders($dir)
    {
        $out = array();
        if ($dir === '' || !is_dir($dir)) {
            return $out;
        }
        $entries = @scandir($dir);
        foreach ((array) $entries as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = rtrim($dir, '/\\') . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $out[$name] = $path;
            }
        }
        return $out;
    }

    /**
     * Bytes in a folder, without following links, until the deadline or the
     * file budget runs out.
     *
     * @return array{0: int, 1: bool} Size, and whether it was cut short.
     */
    private static function folder_size($dir, $deadline, &$files_left)
    {
        $size = 0;
        try {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ($it as $file) {
                if ($files_left-- <= 0 || microtime(true) >= $deadline) {
                    return array($size, true);
                }
                if ($file->isFile() && !$file->isLink()) {
                    $size += (int) $file->getSize();
                }
            }
        } catch (\Exception $e) {
            return array($size, true);
        }
        return array($size, false);
    }

    /** @return string[] Names of installed themes that are not in use. */
    private static function inactive_themes()
    {
        if (!function_exists('wp_get_themes')) {
            return array();
        }
        $in_use = array(get_stylesheet(), get_template());
        $out = array();
        foreach (wp_get_themes() as $slug => $theme) {
            if (!in_array($slug, $in_use, true)) {
                $out[] = (string) $theme->get('Name');
            }
        }
        return $out;
    }

    /** @return string[] Names of installed plugins that are switched off. */
    private static function inactive_plugins()
    {
        if (!function_exists('get_plugins') && defined('ABSPATH') && is_file(ABSPATH . 'wp-admin/includes/plugin.php')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('get_plugins')) {
            return array();
        }
        $active = (array) get_option('active_plugins', array());
        $out = array();
        foreach (get_plugins() as $file => $data) {
            if (!in_array($file, $active, true)) {
                $out[] = isset($data['Name']) ? (string) $data['Name'] : (string) $file;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // AJAX
    // ------------------------------------------------------------------

    public static function ajax_scan()
    {
        static::ajax_guard('ocm_cleanup');
        static::send(self::scan_step(!empty($_POST['restart']))); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_guard()
    }

    public static function ajax_start()
    {
        static::ajax_guard('ocm_cleanup');
        $items = isset($_POST['items']) && is_array($_POST['items']) ? array_map('sanitize_key', wp_unslash($_POST['items'])) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        static::send(self::start(array('items' => $items)));
    }

    public static function ajax_step()
    {
        static::ajax_guard('ocm_cleanup');
        static::send(static::step());
    }

    public static function ajax_stop()
    {
        static::ajax_guard('ocm_cleanup');
        static::send(static::stop());
    }

    public static function ajax_undo()
    {
        static::ajax_guard('ocm_cleanup');
        static::send(static::undo());
    }

    public static function ajax_dismiss()
    {
        static::ajax_guard('ocm_cleanup');
        static::send(static::dismiss());
    }

    public static function ajax_space()
    {
        static::ajax_guard('ocm_cleanup');
        static::send(self::space_report());
    }
}
