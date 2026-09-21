<?php
namespace OCM;

/**
 * Versioned URL inventory carried beside the existing BACKUP_PARTS log manifest.
 * No credentials or query strings are included. Missing/incomplete inventories
 * are treated as legacy backups, never as an empty inventory.
 */
class OCM_URL_Manifest {
    const MAX_BYTES = 65536;

    public static function lines($found, $source, $bucket) {
        $report = OCM_Extra_Domains::finalize($found, $source, '');
        $inventory = array();
        foreach ($report['candidates'] as $row) {
            $parts = parse_url($row['example']);
            $example = 'https://' . $row['search_host'];
            if (is_array($parts) && !empty($parts['path'])) {
                $example .= substr($parts['path'], 0, 160);
            }
            $inventory[$row['search_host']] = array(
                'path_prefixes' => $row['path_prefixes'],
                'example' => $example,
                'count' => $row['count'],
            );
        }
        $json = json_encode(array('version' => 1, 'source' => $source, 'found' => $inventory, 'omitted' => $report['omitted']));
        if (!is_string($json) || strlen($json) > self::MAX_BYTES) {
            return array();
        }
        $digest = hash('sha256', $json);
        $chunks = str_split(base64_encode($json), 1400);
        $lines = array();
        foreach ($chunks as $index => $chunk) {
            $lines[] = 'SYSLOG: URL_MANIFEST_V1 bucket=' . $bucket . ' hash=' . $digest
                . ' part=' . ($index + 1) . '/' . count($chunks) . ' data=' . $chunk;
        }
        return $lines;
    }

    public static function from_log($log, $bucket) {
        $pattern = '/(?:^|\n)[^\r\n]* - ? ?SYSLOG: URL_MANIFEST_V1 bucket=' . preg_quote($bucket, '/')
            . ' hash=([a-f0-9]{64}) part=(\d+)\/(\d+) data=([A-Za-z0-9+\/=]+)\r?(?=\n|$)/';
        if (!preg_match_all($pattern, $log, $matches, PREG_SET_ORDER)) {
            return null;
        }
        // Use only the most recently published inventory; never mix retries.
        $last = end($matches);
        $digest = $last[1];
        $total = (int) $last[3];
        if ($total < 1 || $total > 64) {
            return null;
        }
        $chunks = array();
        foreach ($matches as $match) {
            if ($match[1] === $digest && (int) $match[3] === $total) {
                $chunks[(int) $match[2]] = $match[4];
            }
        }
        $encoded = '';
        for ($i = 1; $i <= $total; $i++) {
            if (!isset($chunks[$i]) || strlen($chunks[$i]) > 1400) {
                return null;
            }
            $encoded .= $chunks[$i];
        }
        $json = base64_decode($encoded, true);
        if (!is_string($json) || strlen($json) > self::MAX_BYTES || !hash_equals($digest, hash('sha256', $json))) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_string($data['source'] ?? null)
            || !preg_match('#^https?://#i', $data['source']) || !is_array($data['found'] ?? null)
            || count($data['found']) > OCM_Extra_Domains::MAX_CANDIDATES) {
            return null;
        }
        foreach ($data['found'] as $host => $row) {
            if (!is_string($host) || strlen($host) > 259 || !preg_match('/^[a-z0-9.-]+(?::[0-9]{1,5})?$/', $host)
                || !is_array($row) || !is_array($row['path_prefixes'] ?? null) || count($row['path_prefixes']) > 5
                || !is_string($row['example'] ?? null) || strlen($row['example']) > 512
                || !is_int($row['count'] ?? null) || $row['count'] < 0) {
                return null;
            }
            foreach ($row['path_prefixes'] as $prefix) {
                if (!is_string($prefix) || strlen($prefix) > 128 || !preg_match('#^/~[A-Za-z0-9._-]+$#', $prefix)) {
                    return null;
                }
            }
        }
        return $data;
    }

    /** Read CLI dump output with bounded memory, including URLs crossing chunks. */
    public static function collect_dump($path, $collect) {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $carry = '';
        $skip_long_url = false;
        $last_heartbeat = 0;
        while (!feof($handle)) {
            if (time() - $last_heartbeat >= 5 && method_exists(One_Click_Migration::class, 'worker_heartbeat')) {
                One_Click_Migration::worker_heartbeat();
                @set_time_limit(One_Click_Migration::get_timeout());
                $last_heartbeat = time();
            }
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                fclose($handle);
                return false;
            }
            $text = $carry . $chunk;
            $carry = '';
            if ($skip_long_url) {
                if (!preg_match('/[\s"\'<>\\\\]/', $text, $delimiter, PREG_OFFSET_CAPTURE)) {
                    continue;
                }
                $text = substr($text, $delimiter[0][1]);
                $skip_long_url = false;
            }
            preg_match_all('#https?://[^\s"\'<>\\\\]+#i', $text, $urls, PREG_OFFSET_CAPTURE);
            foreach ($urls[0] as $url) {
                if ($url[1] + strlen($url[0]) === strlen($text) && !feof($handle)) {
                    if (strlen($url[0]) <= 4096) {
                        $carry = $url[0];
                    } else {
                        call_user_func($collect, substr($url[0], 0, 4096));
                        $skip_long_url = true;
                    }
                } else {
                    call_user_func($collect, $url[0]);
                }
            }
            // A scheme itself may straddle chunks.
            if ($carry === '' && !$skip_long_url) {
                foreach (array('https://', 'http://', 'https:/', 'http:/', 'https:', 'http:', 'https', 'http', 'htt', 'ht', 'h') as $partial) {
                    if (strtolower(substr($text, -strlen($partial))) === $partial) {
                        $carry = substr($text, -strlen($partial));
                        break;
                    }
                }
            }
        }
        fclose($handle);
        return true;
    }
}
