<?php
/**
 * Standalone regression for backup ownership, status attribution, and ZIP retry.
 * Run: php tests/repro-concurrent-backup.php
 */

namespace OCM {
    class One_Click_Migration
    {
        public static $log = array();

        public static function write_to_log($message)
        {
            self::$log[] = (string) $message;
        }

        public static function reset_actions_start_mark()
        {
            update_option('ocm_action_start_backup', false);
            update_option('ocm_action_start_restore', false);
        }

        public static function worker_heartbeat()
        {
        }

        public static function get_timeout()
        {
            return 30;
        }
    }

    class OCM_BackgroundBackup
    {
        const RETURN_TYPE_END_PROCESSS = 'end_process';
    }
}

namespace {
    define('OCM_PLUGIN_WRITABLE_PATH', sys_get_temp_dir() . '/ocm-concurrency-repro/');
    $GLOBALS['ocm_options'] = array();
    $GLOBALS['ocm_password_counter'] = 0;

    function get_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['ocm_options'])
            ? $GLOBALS['ocm_options'][$name]
            : $default;
    }

    function add_option($name, $value, $deprecated = '', $autoload = null)
    {
        if (array_key_exists($name, $GLOBALS['ocm_options'])) {
            return false;
        }
        $GLOBALS['ocm_options'][$name] = $value;
        return true;
    }

    function update_option($name, $value, $autoload = null)
    {
        $GLOBALS['ocm_options'][$name] = $value;
        return true;
    }

    function delete_option($name)
    {
        unset($GLOBALS['ocm_options'][$name]);
        return true;
    }

    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false)
    {
        $GLOBALS['ocm_password_counter']++;
        return str_pad('run' . $GLOBALS['ocm_password_counter'], $length, 'x');
    }

    function maybe_serialize($value)
    {
        if (is_array($value) || is_object($value)) {
            return serialize($value);
        }
        return $value;
    }

    require __DIR__ . '/../inc/backup/class-ocm-backup.php';

    $failures = 0;
    function check($label, $ok)
    {
        global $failures;
        if ($ok) {
            echo "  ok  - $label\n";
        } else {
            $failures++;
            echo "  FAIL - $label\n";
        }
    }

    /**
     * Model two overlapping dump writers. Pre-3.1.16 both would fopen the same
     * wp-content/tmp/db/*.sql path (buckets 832d317d / 8c5d8cd9). Isolation must
     * put each run under its own backup-<id>/db/ directory instead.
     */
    function overlapping_dump_paths($run_id)
    {
        return array(
            'isolated' => \OCM\OCM_Backup::get_backup_work_path($run_id) . 'db/wp_options.sql',
            'shared' => \OCM\OCM_Backup::get_shared_tmp_db_path() . 'wp_options.sql',
        );
    }

    echo "overlapping concurrent dumps (832d317d / 8c5d8cd9):\n";
    $run_a = '832d317dc4cb38f9bc42f6e59952d9e9';
    $run_b = '8c5d8cd93758f497688aac90c6903ef6';
    $paths_a = overlapping_dump_paths($run_a);
    $paths_b = overlapping_dump_paths($run_b);
    check('overlapping dumps no longer share tmp/db/wp_options.sql', $paths_a['isolated'] !== $paths_b['isolated']);
    check('owner dump is not the shared tmp/db path', $paths_a['isolated'] !== $paths_a['shared']);
    check('second dump is not the shared tmp/db path', $paths_b['isolated'] !== $paths_b['shared']);
    check('isolated dump lives under backup-<run>/db/', false !== strpos($paths_a['isolated'], 'backup-' . $run_a . '/db/'));
    check('second isolated dump lives under its own backup-<run>/db/', false !== strpos($paths_b['isolated'], 'backup-' . $run_b . '/db/'));

    @mkdir(dirname($paths_a['isolated']), 0700, true);
    @mkdir(dirname($paths_b['isolated']), 0700, true);
    @mkdir(dirname($paths_a['shared']), 0700, true);
    file_put_contents($paths_a['isolated'], 'owner-dump');
    file_put_contents($paths_b['isolated'], 'intruder-dump');
    file_put_contents($paths_a['shared'], 'would-have-been-raced');
    unlink($paths_a['shared']);
    check('owner SQL dump survives deletion of the shared tmp/db file', is_file($paths_a['isolated']) && file_get_contents($paths_a['isolated']) === 'owner-dump');
    check('intruder SQL dump did not overwrite the owner dump', file_get_contents($paths_b['isolated']) === 'intruder-dump');

    echo "single-flight ownership and workspace isolation:\n";
    $run_one = \OCM\OCM_Backup::claim_backup_run();
    $path_one = \OCM\OCM_Backup::get_backup_work_path();
    check('first request atomically claims the backup', is_string($run_one));
    check('overlapping request cannot claim the backup', \OCM\OCM_Backup::claim_backup_run() === false);
    check('workspace contains the owning run id', strpos($path_one, $run_one) !== false);
    check('wrong owner cannot release the lock', \OCM\OCM_Backup::release_backup_run('another-run') === false);

    $run_state = get_option(\OCM\OCM_Backup::BACKUP_RUN_OPTION);
    $run_state['bucket_id'] = 'bucket-one';
    update_option(\OCM\OCM_Backup::BACKUP_RUN_OPTION, $run_state);
    update_option('ocm_bucket_id', 'wrong-global-bucket');

    echo "uploaded-part attribution (832d317d / 8c5d8cd9 skip-lie):\n";
    \OCM\OCM_Backup::mark_backup_part_uploaded('uploads');
    \OCM\OCM_Backup::capture_failed_folder(array(), 'uploads', 'ocm_skipped_folders');
    check('successful upload cannot subsequently be marked skipped', get_option('ocm_skipped_folders', array()) === array());

    \OCM\OCM_Backup::capture_failed_folder(array(), 'plugins', 'ocm_skipped_folders');
    $missing = \OCM\OCM_Backup::get_backup_missing_parts();
    \OCM\OCM_Backup::log_backup_parts_manifest($missing);
    $manifests = preg_grep('/BACKUP_PARTS bucket=/', \OCM\One_Click_Migration::$log);
    $manifest = $manifests ? end($manifests) : '';
    check('failed part remains skipped', in_array('plugins', $missing, true));
    check('confirmed upload remains present', !in_array('uploads', $missing, true));
    check('manifest is attributed to the owning bucket', strpos($manifest, 'bucket=bucket-one') !== false);
    check('manifest ignores a changed global bucket', strpos($manifest, 'wrong-global-bucket') === false);
    check('manifest reports confirmed upload as ok', strpos($manifest, 'uploads=ok') !== false);

    $owner_log_count = count(\OCM\One_Click_Migration::$log);
    $intruder = new ReflectionClass(\OCM\OCM_Backup::class);
    $run_id_prop = $intruder->getProperty('backup_run_id');
    $run_id_prop->setAccessible(true);
    $run_id_prop->setValue(null, $run_b);
    \OCM\OCM_Backup::capture_failed_folder(array(), 'themes', 'ocm_skipped_folders');
    \OCM\OCM_Backup::log_backup_parts_manifest(array('themes'));
    check('stale overlapping run cannot skip another owner part', get_option('ocm_skipped_folders', array()) === array('plugins'));
    check('stale overlapping run cannot stamp BACKUP_PARTS on another bucket', count(\OCM\One_Click_Migration::$log) === $owner_log_count);
    $run_id_prop->setValue(null, $run_one);

    check('owner releases the lock', \OCM\OCM_Backup::release_backup_run($run_one) === true);
    $run_two = \OCM\OCM_Backup::claim_backup_run();
    $path_two = \OCM\OCM_Backup::get_backup_work_path();
    check('next backup can claim after release', is_string($run_two));
    check('next backup gets a different workspace', $path_two !== $path_one);

    echo "zip failover under overlap (42b8ff59):\n";
    if (class_exists('ZipArchive')) {
        @mkdir($path_two . 'source', 0700, true);
        file_put_contents($path_two . 'source/example.txt', 'complete');
        $archive = $path_two . 'uploads.zip';
        $zip = new ZipArchive();
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($path_two . 'source/example.txt', 'example.txt');
        $zip->close();

        $marker = 'ocm_zip_close_in_progress_' . md5($archive);
        update_option($marker, time());
        \OCM\One_Click_Migration::$log = array();
        $method = new ReflectionMethod(\OCM\OCM_Backup::class, 'create_native_zip_from_directory');
        $method->setAccessible(true);
        $created = $method->invoke(null, $path_two . 'source', $archive);

        check('valid primary archive wins over stale close marker', $created === true);
        check('stale close marker is cleared', get_option($marker, false) === false);
        check(
            'completed primary archive does not enter monitored failover',
            0 === count(preg_grep('/slower monitored zip failover/', \OCM\One_Click_Migration::$log))
        );

        $zip = new ZipArchive();
        $zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFile($path_two . 'source/example.txt', 'example.txt');
        $zip->close();
        $valid_before_failover = filesize($archive);
        \OCM\One_Click_Migration::$log = array();
        $cli = new ReflectionMethod(\OCM\OCM_Backup::class, 'create_cli_zip_from_directory');
        $cli->setAccessible(true);
        $kept = $cli->invoke(null, $path_two . 'source', $archive);
        check('failover keeps a valid primary archive instead of unlinking it', $kept === true && filesize($archive) === $valid_before_failover);
    } else {
        echo "  skip - ZipArchive extension unavailable\n";
    }

    echo "incomplete complete without request context:\n";
    $run_id_prop = (new ReflectionClass(\OCM\OCM_Backup::class))->getProperty('backup_run_id');
    $run_id_prop->setAccessible(true);
    $run_id_prop->setValue(null, '');
    $orphaned = \OCM\OCM_Backup::claim_backup_run();
    $run_id_prop->setValue(null, '');
    update_option('ocm_action_start_backup', true);
    \OCM\OCM_Backup::fail_backup('backup is incomplete; missing parts: db');
    check('fail_backup without request context still releases the owner', get_option(\OCM\OCM_Backup::BACKUP_RUN_OPTION, false) === false);
    check('fail_backup without request context clears the start flag', get_option('ocm_action_start_backup') === false);
    check('a later start can claim after incomplete complete', is_string(\OCM\OCM_Backup::claim_backup_run()));
    \OCM\OCM_Backup::release_backup_run(\OCM\OCM_Backup::get_stored_backup_run_id());

    echo "release compare-and-delete:\n";
    $first = \OCM\OCM_Backup::claim_backup_run();
    $stale_id = $first;
    \OCM\OCM_Backup::release_backup_run($first);
    $second = \OCM\OCM_Backup::claim_backup_run();
    check('delayed releaser cannot drop a newly claimed owner', \OCM\OCM_Backup::release_backup_run($stale_id) === false);
    check('new owner remains after stale release', \OCM\OCM_Backup::is_current_backup_run($second));
    \OCM\OCM_Backup::release_backup_run($second);

    echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n$failures TEST(S) FAILED\n";
    exit($failures === 0 ? 0 : 1);
}
