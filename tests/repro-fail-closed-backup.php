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
    $result = \OCM\OCM_Backup::fail_part_upload('uploads', 'encrypted archive is missing');

    check('worker receives terminal end-process result', $result === 'end_process');
    check('missing part is recorded', get_option('ocm_skipped_folders') === array('uploads'));
    check('backup is stopped', get_option('ocm_is_stopped') === true);
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
