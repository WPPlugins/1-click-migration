<?php
/**
 * Standalone regression for upload failure bookkeeping.
 * Run: php tests/repro-fail-closed-backup.php
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
    $GLOBALS['ocm_options'] = array();

    function get_option($name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['ocm_options'])
            ? $GLOBALS['ocm_options'][$name]
            : $default;
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

    function add_option($name, $value, $deprecated = '', $autoload = null)
    {
        if (array_key_exists($name, $GLOBALS['ocm_options'])) {
            return false;
        }
        $GLOBALS['ocm_options'][$name] = $value;
        return true;
    }

    function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false)
    {
        return str_pad('failclosed', $length, 'x');
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

    echo "fail-closed upload bookkeeping:\n";
    update_option('ocm_bucket_id', 'test-bucket');
    update_option(\OCM\OCM_Backup::BACKUP_RUN_OPTION, array(
        'id' => 'test-run',
        'bucket_id' => 'test-bucket',
        'started_at' => time(),
    ));
    \OCM\OCM_Backup::set_backup_run_context('test-run');
    $result = \OCM\OCM_Backup::fail_part_upload('uploads', 'encrypted archive is missing');

    check('worker receives terminal end-process result', $result === 'end_process');
    check('missing part is recorded', get_option('ocm_skipped_folders') === array('uploads'));
    check('backup is stopped', get_option('ocm_is_stopped') === true);
    check('failed backup releases its single-flight owner', get_option(\OCM\OCM_Backup::BACKUP_RUN_OPTION, false) === false);
    check(
        'terminal failure marker is logged',
        1 === count(preg_grep('/^Backup failed: uploads upload failed:/', \OCM\One_Click_Migration::$log))
    );
    $missing = \OCM\OCM_Backup::get_backup_missing_parts();
    $manifests = preg_grep('/BACKUP_PARTS bucket=test-bucket/', \OCM\One_Click_Migration::$log);
    $manifest = $manifests ? end($manifests) : '';
    check('hard failure writes the missing-part manifest', count($manifests) === 1);
    check('incomplete steps are all classified missing', $missing === array('db', 'plugins', 'themes', 'uploads'));
    check('incomplete manifest never reports part=ok', strpos($manifest, '=ok') === false);

    echo "fail_backup without request context:\n";
    $run_id_prop = (new ReflectionClass(\OCM\OCM_Backup::class))->getProperty('backup_run_id');
    $run_id_prop->setAccessible(true);
    $claimed = \OCM\OCM_Backup::claim_backup_run();
    $run_id_prop->setValue(null, '');
    update_option('ocm_action_start_backup', true);
    \OCM\OCM_Backup::fail_backup('backup is incomplete; missing parts: db');
    check('stored owner is released even with empty request context', get_option(\OCM\OCM_Backup::BACKUP_RUN_OPTION, false) === false);
    check('start flag is cleared even with empty request context', get_option('ocm_action_start_backup') === false);
    check('claimed run existed before the empty-context fail', is_string($claimed));

    echo "ZipArchive primary path:\n";
    if (class_exists('ZipArchive')) {
        $work = sys_get_temp_dir() . '/ocm-zip-primary-' . getmypid();
        @mkdir($work . '/source', 0777, true);
        file_put_contents($work . '/source/example.txt', 'primary path');
        $archive = $work . '/archive.zip';
        \OCM\One_Click_Migration::$log = array();

        $method = new ReflectionMethod(\OCM\OCM_Backup::class, 'create_native_zip_from_directory');
        $method->setAccessible(true);
        $created = $method->invoke(null, $work . '/source', $archive);

        check('normal archive uses the primary path successfully', $created === true && is_file($archive));
        check(
            'normal archive did not use slower system-zip failover',
            0 === count(preg_grep('/slower monitored zip failover|Created .* monitored system zip/', \OCM\One_Click_Migration::$log))
        );

        @unlink($archive);
        @unlink($work . '/source/example.txt');
        update_option('ocm_zip_close_in_progress_' . md5($archive), time());
        $created = $method->invoke(null, $work . '/source', $archive);
        $empty_zip = new ZipArchive();
        $opened = $empty_zip->open($archive);
        $empty_count = ($opened === true) ? $empty_zip->numFiles : -1;
        if ($opened === true) {
            $empty_zip->close();
        }
        check('empty-tree failover creates a valid empty ZIP', $created === true && $empty_count === 0);
        check('empty-tree failover clears the close marker', get_option('ocm_zip_close_in_progress_' . md5($archive), false) === false);

        @unlink($archive);
        @rmdir($work . '/source');
        @rmdir($work);
    } else {
        echo "  skip - ZipArchive extension unavailable\n";
    }

    echo $failures === 0 ? "\nALL TESTS PASSED\n" : "\n$failures TEST(S) FAILED\n";
    exit($failures === 0 ? 0 : 1);
}
