<?php
/**
 * Free Search & Replace: find text anywhere in this site's database and
 * replace it, serialized data included.
 *
 * Preview is a dry run in short AJAX steps; nothing is stored. Replace takes
 * a database-only local backup first, then rewrites in short AJAX steps that
 * save their position, so a closed tab or a timed-out request continues from
 * where it stopped. Undo restores the database from that backup through the
 * normal local restore.
 */

namespace OCM;

use WP_Error;

require_once __DIR__ . '/class-ocm-guarded-run.php';

class OCM_Search_Replace extends OCM_Guarded_Run
{
    /** The current or last run. An ocm_ option, so the run never rewrites itself. */
    const OPTION_RUN = 'ocm_sr_run';

    /** MySQL named lock held while one step rewrites, so two tabs never overlap. */
    const STEP_LOCK = 'ocm_sr_step';

    const STATE_REPLACING = 'replacing';
    const STATE_WORKING = 'replacing';
    const ACTIVITY_TYPE = 'search_replace';
    const RUN_ID_PREFIX = 'sr_';
    const LOG_NAME = 'Search & Replace';

    const MIN_SEARCH_CHARS = 3;
    const MAX_TEXT_BYTES = 2000;
    const SAMPLE_LIMIT = 20;

    // ------------------------------------------------------------------
    // Tables and input
    // ------------------------------------------------------------------

    /**
     * This site's tables (its own prefix, never restore staging tables), with
     * rows and size for the table picker.
     *
     * @return array<int, array{name: string, rows: int, size: int}>
     */
    public static function list_tables()
    {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return array();
        }
        $prefix = isset($wpdb->base_prefix) ? (string) $wpdb->base_prefix : 'wp_';
        $like = $wpdb->esc_like($prefix) . '%';
        // 'ARRAY_A' is the value of WordPress's ARRAY_A constant.
        $rows = $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $like), 'ARRAY_A');
        $tables = array();
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $name = isset($row['Name']) ? (string) $row['Name'] : '';
                if ($name === '' || strpos($name, $prefix) !== 0 || preg_match('/^ocm[0-9a-f]{4}_/', $name)) {
                    continue;
                }
                if (isset($row['Engine']) && $row['Engine'] === null && isset($row['Comment']) && stripos((string) $row['Comment'], 'view') !== false) {
                    continue; // views can't be updated row by row
                }
                $tables[] = array(
                    'name' => $name,
                    'rows' => isset($row['Rows']) ? (int) $row['Rows'] : 0,
                    'size' => (isset($row['Data_length']) ? (int) $row['Data_length'] : 0)
                        + (isset($row['Index_length']) ? (int) $row['Index_length'] : 0),
                );
            }
        }
        usort($tables, static function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        return $tables;
    }

    /**
     * Check what the form sent. Search and replace text are kept exactly as
     * typed (no trimming or sanitizing: that would change what is matched).
     *
     * @param array $input search, replace, tables[], match_case, skip_guid
     * @return array|WP_Error
     */
    public static function validate_input(array $input)
    {
        $search = isset($input['search']) && is_string($input['search']) ? $input['search'] : '';
        $replace = isset($input['replace']) && is_string($input['replace']) ? $input['replace'] : '';
        $match_case = !empty($input['match_case']) && $input['match_case'] !== 'false' && $input['match_case'] !== '0';
        $skip_guid = !isset($input['skip_guid']) || ($input['skip_guid'] !== 'false' && $input['skip_guid'] !== '0' && $input['skip_guid'] !== false);

        if (function_exists('is_multisite') && is_multisite()) {
            return new WP_Error('sr_multisite', __('Search & Replace isn\'t available on multisite networks yet.', '1-click-migration'));
        }
        if (self::text_length($search) < self::MIN_SEARCH_CHARS) {
            return new WP_Error('sr_search_short', sprintf(
                /* translators: %d: minimum number of characters */
                __('Type at least %d characters to search for.', '1-click-migration'),
                self::MIN_SEARCH_CHARS
            ));
        }
        if (strlen($search) > self::MAX_TEXT_BYTES || strlen($replace) > self::MAX_TEXT_BYTES) {
            return new WP_Error('sr_text_long', __('Search and replace text can be at most 2,000 characters each.', '1-click-migration'));
        }
        if ($search === $replace || (!$match_case && strtolower($search) === strtolower($replace))) {
            return new WP_Error('sr_same_text', __('The replacement is the same as the search text, so there is nothing to change.', '1-click-migration'));
        }

        $known = array();
        foreach (self::list_tables() as $t) {
            $known[$t['name']] = true;
        }
        $tables = array();
        if (isset($input['tables']) && is_array($input['tables'])) {
            foreach ($input['tables'] as $table) {
                $table = (string) $table;
                // Only tables from the list: table names go into SQL as they are.
                if (isset($known[$table]) && !in_array($table, $tables, true)) {
                    $tables[] = $table;
                }
            }
        }
        if (!$tables) {
            return new WP_Error('sr_no_tables', __('Choose at least one table.', '1-click-migration'));
        }
        sort($tables);

        return array(
            'search' => $search,
            'replace' => $replace,
            'tables' => $tables,
            'match_case' => $match_case,
            'skip_guid' => $skip_guid,
        );
    }

    /** Characters, not bytes, where the server can count them. */
    private static function text_length($text)
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    /**
     * Whether the search would change this site's own address (siteurl or
     * home), which signs the admin out of this address.
     */
    public static function touches_site_address($search, $match_case)
    {
        foreach (array('siteurl', 'home') as $name) {
            $value = (string) get_option($name, '');
            if ($value === '') {
                continue;
            }
            if ($match_case ? strpos($value, $search) !== false : stripos($value, $search) !== false) {
                return true;
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Engine
    // ------------------------------------------------------------------

    /**
     * One time-boxed pass of the engine over $tables from $cursor.
     *
     * @param array      $input  Validated input.
     * @param array      $tables Tables still to do, in order.
     * @param array|null $last_key Keyset position inside $tables[0].
     * @param bool       $dry_run
     * @param int        $sample_limit
     * @param float      $seconds
     * @return array The engine report.
     */
    public static function run_engine(array $input, array $tables, $last_key, $dry_run, $sample_limit, $seconds)
    {
        $started = microtime(true);
        $db = new OCM_DB();
        $args = array(
            'pairs' => array(array('search' => $input['search'], 'replace' => $input['replace'])),
            'needles' => array($input['search']),
            'match_case' => !empty($input['match_case']),
            'skip_columns' => !empty($input['skip_guid']) ? array('guid') : array(),
            'skip_row' => array(__CLASS__, 'is_protected_row'),
            // A live site's database holds text visitors wrote: never create
            // the objects found in it. Text inside them stays as it is.
            'allow_objects' => false,
            'dry_run' => (bool) $dry_run,
            'sample_limit' => (int) $sample_limit,
            'log' => array(__CLASS__, 'log_line'),
            'start_line' => '',
            'log_summary' => false,
            'should_yield' => static function () use ($started, $seconds) {
                return (microtime(true) - $started) >= $seconds;
            },
        );
        if (is_array($last_key) && $last_key) {
            $args['resume_key'] = $last_key;
        }
        return $db->search_replace_db(array_values($tables), $args);
    }

    /**
     * Rows the tool never changes: this plugin's own settings (its run state,
     * schedules and activity history hold the search text and backup ids).
     *
     * @param string $table
     * @param array  $row
     * @return bool
     */
    public static function is_protected_row($table, $row)
    {
        global $wpdb;
        $options = isset($wpdb->options) ? (string) $wpdb->options : ((isset($wpdb->base_prefix) ? $wpdb->base_prefix : 'wp_') . 'options');
        if ((string) $table !== $options || !is_array($row) || !isset($row['option_name'])) {
            return false;
        }
        $name = (string) $row['option_name'];
        return strpos($name, 'ocm_') === 0
            || strpos($name, '_transient_ocm_') === 0
            || strpos($name, '_transient_timeout_ocm_') === 0;
    }

    /**
     * A report that stopped on a table the engine could not page through.
     *
     * @return string '' when the report has no such stop.
     */
    private static function engine_stop_message(array $report)
    {
        $table = '';
        $why = '';
        if (!empty($report['unpageable_table'])) {
            $table = (string) $report['unpageable_table'];
            $why = __('it has no primary key and is too large to change in one pass', '1-click-migration');
        } elseif (!empty($report['oversized_table'])) {
            $table = (string) $report['oversized_table'];
            $why = __('one of its rows is too large for this site\'s PHP memory limit', '1-click-migration');
        } elseif (!empty($report['paging_stuck_table'])) {
            $table = (string) $report['paging_stuck_table'];
            $why = __('reading it stopped making progress', '1-click-migration');
        } elseif (!empty($report['errors'])) {
            return sprintf(
                /* translators: %s: database error */
                __('The database refused a change: %s', '1-click-migration'),
                (string) reset($report['errors'])
            );
        }
        if ($table === '') {
            return '';
        }
        return sprintf(
            /* translators: 1: table name, 2: reason */
            __('Table %1$s was skipped because %2$s.', '1-click-migration'),
            $table,
            $why
        );
    }

    /** Merge one report's per-table counts into a running total. */
    private static function merge_counts(array $totals, array $report)
    {
        if (empty($report['tables']) || !is_array($report['tables'])) {
            return $totals;
        }
        foreach ($report['tables'] as $table => $c) {
            if (!isset($totals[$table])) {
                $totals[$table] = array('rows' => 0, 'cells' => 0);
            }
            $totals[$table]['rows'] += isset($c['rows']) ? (int) $c['rows'] : 0;
            $totals[$table]['cells'] += isset($c['cells']) ? (int) $c['cells'] : 0;
        }
        return $totals;
    }

    // ------------------------------------------------------------------
    // Preview
    // ------------------------------------------------------------------

    /**
     * One preview step. The browser keeps the totals and sends the cursor back.
     *
     * @param array      $input  Raw form input.
     * @param int        $table_index First table not finished.
     * @param array|null $last_key Position inside that table.
     * @param int        $samples_left
     * @return array|WP_Error {done, table_index, last_key, counts, samples, progress, site_address}
     */
    public static function preview_step(array $input, $table_index, $last_key, $samples_left)
    {
        $valid = self::validate_input($input);
        if (is_wp_error($valid)) {
            return $valid;
        }
        $table_index = max(0, (int) $table_index);
        $tables = $valid['tables'];
        if ($table_index >= count($tables)) {
            return array('done' => true, 'table_index' => count($tables), 'last_key' => null, 'counts' => array(), 'samples' => array(), 'objects_skipped' => 0, 'progress' => 100);
        }

        $remaining = array_slice($tables, $table_index);
        $report = self::run_engine($valid, $remaining, $last_key, true, max(0, min(self::SAMPLE_LIMIT, (int) $samples_left)), self::step_seconds());

        $result = array(
            'counts' => self::merge_counts(array(), $report),
            'samples' => isset($report['samples']) ? $report['samples'] : array(),
            'objects_skipped' => isset($report['objects_skipped']) ? (int) $report['objects_skipped'] : 0,
            'site_address' => self::touches_site_address($valid['search'], $valid['match_case']),
            'warning' => self::engine_stop_message($report),
        );
        if (!empty($report['yielded']) && is_array($report['cursor'])) {
            $at = array_search((string) $report['cursor']['table'], $tables, true);
            $result['done'] = false;
            $result['table_index'] = false === $at ? $table_index : (int) $at;
            $result['last_key'] = $report['cursor']['last_key'];
        } elseif ($result['warning'] !== '' && empty($report['errors'])) {
            // Skip the table the engine stopped on and carry on with the next.
            $stopped = !empty($report['unpageable_table']) ? $report['unpageable_table']
                : (!empty($report['oversized_table']) ? $report['oversized_table'] : $report['paging_stuck_table']);
            $at = array_search((string) $stopped, $tables, true);
            $result['done'] = false === $at || $at + 1 >= count($tables);
            $result['table_index'] = false === $at ? count($tables) : (int) $at + 1;
            $result['last_key'] = null;
        } else {
            $result['done'] = true;
            $result['table_index'] = count($tables);
            $result['last_key'] = null;
        }
        $result['progress'] = (int) floor(100 * $result['table_index'] / max(1, count($tables)));
        return $result;
    }

    // ------------------------------------------------------------------
    // Replace run (the shared part is OCM_Guarded_Run)
    // ------------------------------------------------------------------

    /** Whether a run is rewriting rows right now (past its backup). */
    public static function is_replacing()
    {
        return static::is_working();
    }

    /**
     * Start a replace: refuse while anything else runs, then take the
     * database backup that Undo restores.
     *
     * @param array $input Raw form input.
     * @return array|WP_Error The run as the screen shows it.
     */
    public static function start(array $input)
    {
        $valid = self::validate_input($input);
        if (is_wp_error($valid)) {
            return $valid;
        }
        return static::begin(array(
            'search' => $valid['search'],
            'replace' => $valid['replace'],
            'tables' => $valid['tables'],
            'match_case' => $valid['match_case'],
            'skip_guid' => $valid['skip_guid'],
            'table_index' => 0,
            'last_key' => null,
            'clean' => true,
            'counts' => array(),
            'objects_skipped' => 0,
        ));
    }

    /**
     * The label the backup gets: says what it is for, cut to fit the list.
     */
    public static function backup_label($search, $replace)
    {
        $short = static function ($text) {
            $text = preg_replace('/\s+/', ' ', (string) $text);
            if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > 24) {
                return mb_substr($text, 0, 23, 'UTF-8') . '…';
            }
            return strlen($text) > 24 ? substr($text, 0, 23) . '…' : $text;
        };
        return sprintf(
            /* translators: 1: search text, 2: replacement */
            __('Before replace: %1$s → %2$s', '1-click-migration'),
            $short($search),
            $short($replace)
        );
    }

    protected static function run_label(array $run)
    {
        return self::backup_label($run['search'], $run['replace']);
    }

    protected static function messages()
    {
        return array(
            'backup_failed' => __('The database backup didn\'t finish, so nothing was replaced. See the Local Backups log for why.', '1-click-migration'),
            'stopped_before' => __('Stopped before anything was replaced.', '1-click-migration'),
            'stopped_during' => __('Stopped. Tables already done keep the new text. Click Undo to put the database back as it was before.', '1-click-migration'),
        );
    }

    protected static function done_message(array $run)
    {
        $rows = self::total_rows($run);
        return sprintf(
            /* translators: %s: number of rows */
            _n('Replaced in %s row.', 'Replaced in %s rows.', $rows, '1-click-migration'),
            number_format_i18n($rows)
        );
    }

    private static function total_rows(array $run)
    {
        $rows = 0;
        foreach ((array) (isset($run['counts']) ? $run['counts'] : array()) as $c) {
            $rows += (int) $c['rows'];
        }
        return $rows;
    }

    /**
     * Rewrite for up to step_seconds(), then save where it got to.
     */
    protected static function work_step(array $run)
    {
        $tables = isset($run['tables']) && is_array($run['tables']) ? array_values($run['tables']) : array();
        $index = isset($run['table_index']) ? (int) $run['table_index'] : 0;

        if (empty($run['clean'])) {
            // The last step died between saving its position and finishing.
            // Rows after that position may already be rewritten. Doing them
            // again is harmless unless the replacement contains the search
            // text, which a second pass would replace again.
            if (self::replacement_contains_search($run)) {
                return static::finish($run, self::STATE_FAILED, __('Replacing stopped partway through a table and can\'t safely continue, because the replacement contains the search text. Click Undo to put the database back as it was before.', '1-click-migration'), 'interrupted');
            }
            static::log_line(' SYSLOG: Search & Replace: the last step was interrupted; redoing from the saved position');
        }

        if ($index >= count($tables)) {
            return static::finish($run, self::STATE_DONE, '', '');
        }

        $run['clean'] = false;
        $run = static::save_run($run);

        $report = self::run_engine($run, array_slice($tables, $index), $run['last_key'], false, 0, static::step_seconds());

        // Stop pressed while this step ran: keep its counts, stay stopped.
        $run['counts'] = self::merge_counts(isset($run['counts']) ? $run['counts'] : array(), $report);
        $run['objects_skipped'] = (isset($run['objects_skipped']) ? (int) $run['objects_skipped'] : 0)
            + (isset($report['objects_skipped']) ? (int) $report['objects_skipped'] : 0);
        $stopped = static::stopped_meanwhile($run, array('counts' => $run['counts'], 'objects_skipped' => $run['objects_skipped'], 'clean' => true));
        if ($stopped !== null) {
            return $stopped;
        }

        if (!empty($report['errors'])) {
            return static::finish($run, self::STATE_FAILED, self::engine_stop_message($report) . ' ' . __('Some rows may already be changed. Click Undo to put the database back as it was before.', '1-click-migration'), 'db_error');
        }

        if (!empty($report['yielded']) && is_array($report['cursor'])) {
            $at = array_search((string) $report['cursor']['table'], $tables, true);
            $run['table_index'] = false === $at ? $index : (int) $at;
            $run['last_key'] = $report['cursor']['last_key'];
            $run['clean'] = true;
            return static::save_run($run);
        }

        $warning = self::engine_stop_message($report);
        if ($warning !== '') {
            // A table the engine can't page through: leave it, do the rest.
            $run['warnings'][] = $warning;
            $stopped_on = !empty($report['unpageable_table']) ? $report['unpageable_table']
                : (!empty($report['oversized_table']) ? $report['oversized_table'] : $report['paging_stuck_table']);
            $at = array_search((string) $stopped_on, $tables, true);
            $run['table_index'] = false === $at ? count($tables) : (int) $at + 1;
            $run['last_key'] = null;
            $run['clean'] = true;
            if ($run['table_index'] < count($tables)) {
                return static::save_run($run);
            }
            return static::finish($run, self::STATE_DONE, '', '');
        }

        $run['table_index'] = count($tables);
        $run['last_key'] = null;
        $run['clean'] = true;
        return static::finish($run, self::STATE_DONE, '', '');
    }

    /** Whether a second pass over a rewritten row would change it again. */
    public static function replacement_contains_search(array $run)
    {
        $search = (string) $run['search'];
        $replace = (string) $run['replace'];
        return !empty($run['match_case'])
            ? strpos($replace, $search) !== false
            : stripos($replace, $search) !== false;
    }

    /**
     * What the screen says about values left alone because the text sits
     * inside a stored object.
     *
     * @param int $cells
     * @return string '' for none.
     */
    public static function objects_skipped_message($cells)
    {
        $cells = (int) $cells;
        if ($cells < 1) {
            return '';
        }
        return sprintf(
            /* translators: %s: number of values */
            _n(
                '%s value keeps the old text: it sits inside plugin data that can\'t be changed safely.',
                '%s values keep the old text: it sits inside plugin data that can\'t be changed safely.',
                $cells,
                '1-click-migration'
            ),
            number_format_i18n($cells)
        );
    }

    /** The run's warnings, with the line about values left alone last. */
    private static function warnings_with_objects(array $run)
    {
        $warnings = isset($run['warnings']) && is_array($run['warnings']) ? array_values($run['warnings']) : array();
        $objects = self::objects_skipped_message(isset($run['objects_skipped']) ? $run['objects_skipped'] : 0);
        if ($objects !== '') {
            $warnings[] = $objects;
        }
        return $warnings;
    }

    protected static function progress_percent(array $run)
    {
        $tables = isset($run['tables']) && is_array($run['tables']) ? $run['tables'] : array();
        $index = isset($run['table_index']) ? (int) $run['table_index'] : 0;
        return (int) floor(100 * min($index, count($tables)) / max(1, count($tables)));
    }

    protected static function public_extra(array $run)
    {
        $tables = isset($run['tables']) && is_array($run['tables']) ? $run['tables'] : array();
        $index = isset($run['table_index']) ? (int) $run['table_index'] : 0;
        return array(
            'search' => $run['search'],
            'replace' => $run['replace'],
            'match_case' => !empty($run['match_case']),
            'skip_guid' => !empty($run['skip_guid']),
            'tables_total' => count($tables),
            'tables_done' => min($index, count($tables)),
            'current_table' => isset($tables[$index]) ? $tables[$index] : '',
            'rows' => self::total_rows($run),
            'counts' => isset($run['counts']) ? $run['counts'] : array(),
            'warnings' => self::warnings_with_objects($run),
        );
    }

    // ------------------------------------------------------------------
    // AJAX
    // ------------------------------------------------------------------

    /** Form fields exactly as typed (wp_unslash undoes WordPress's added slashes only). */
    private static function posted_input()
    {
        $post = wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_guard()
        return array(
            'search' => isset($post['search']) ? (string) $post['search'] : '',
            'replace' => isset($post['replace']) ? (string) $post['replace'] : '',
            'tables' => isset($post['tables']) && is_array($post['tables']) ? array_map('strval', $post['tables']) : array(),
            'match_case' => isset($post['match_case']) ? (string) $post['match_case'] : '0',
            'skip_guid' => isset($post['skip_guid']) ? (string) $post['skip_guid'] : '1',
        );
    }

    public static function ajax_preview()
    {
        static::ajax_guard('ocm_sr');
        $post = wp_unslash($_POST); // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $last_key = null;
        if (isset($post['last_key']) && $post['last_key'] !== '') {
            $decoded = json_decode((string) $post['last_key'], true);
            $last_key = is_array($decoded) ? array_values(array_map(static function ($v) {
                return $v === null ? null : (string) $v;
            }, $decoded)) : null;
        }
        static::send(self::preview_step(
            self::posted_input(),
            isset($post['table_index']) ? (int) $post['table_index'] : 0,
            $last_key,
            isset($post['samples_left']) ? (int) $post['samples_left'] : self::SAMPLE_LIMIT
        ));
    }

    public static function ajax_start()
    {
        static::ajax_guard('ocm_sr');
        static::send(self::start(self::posted_input()));
    }

    public static function ajax_step()
    {
        static::ajax_guard('ocm_sr');
        static::send(static::step());
    }

    public static function ajax_stop()
    {
        static::ajax_guard('ocm_sr');
        static::send(static::stop());
    }

    public static function ajax_undo()
    {
        static::ajax_guard('ocm_sr');
        static::send(static::undo());
    }

    public static function ajax_dismiss()
    {
        static::ajax_guard('ocm_sr');
        static::send(static::dismiss());
    }

    public static function ajax_tables()
    {
        static::ajax_guard('ocm_sr');
        static::send(array('tables' => self::list_tables()));
    }
}
