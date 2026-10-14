<?php
namespace OCM;

/** Read-only restore preparation. Starting a restore consumes a user-bound plan. */
class OCM_Restore_Precheck {
    const OPTION = 'ocm_restore_url_plan';
    const TTL = 900;

    public static function exclusions($value) {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $values = array_values(array_intersect(array('db', 'themes', 'plugins', 'uploads'), $values));
        sort($values);
        return $values;
    }

    public static function prepare() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Forbidden'), 403);
        }
        check_ajax_referer('ocm_prepare_restore');
        if (get_option('ocm_action_start_backup') || get_option('ocm_action_start_restore')) {
            wp_send_json_error(array('message' => 'Wait for the current operation to finish, or stop it before preparing a restore.'), 409);
        }
        $code = isset($_POST['restore_code']) && is_string($_POST['restore_code'])
            ? strtoupper(preg_replace('/[\s-]+/', '', wp_unslash($_POST['restore_code']))) : '';
        if (!preg_match('/^[A-Z0-9]{6,32}$/', $code)) {
            wp_send_json_error(array('message' => 'Enter the restore code from your backup email.'), 400);
        }
        $excluded = self::exclusions($_POST['selected'] ?? '');
        if (count($excluded) === 4) {
            wp_send_json_error(array('message' => 'Select at least one section to restore.'), 400);
        }
        $redeem = OCM_S3::redeem_restore_code($code);
        if (!is_object($redeem) || ($redeem->_status ?? 0) !== 200 || !empty($redeem->error)
            || empty($redeem->bucket_id) || empty($redeem->aes_key_b64) || empty($redeem->urls)) {
            wp_send_json_error(array('message' => 'The backup could not be opened. Check your restore code and try again.'), 400);
        }
        $manifest = null;
        if (!in_array('db', $excluded, true) && !empty($redeem->urls->log_download)) {
            $response = wp_remote_get($redeem->urls->log_download, array(
                'timeout' => 30, 'limit_response_size' => 4 * 1024 * 1024,
            ));
            if (is_wp_error($response) || !in_array((int) wp_remote_retrieve_response_code($response), array(200, 404), true)) {
                wp_send_json_error(array('message' => 'The backup address check could not be downloaded. Please try again.'), 502);
            }
            if ((int) wp_remote_retrieve_response_code($response) === 200) {
                $manifest = OCM_URL_Manifest::from_log(wp_remote_retrieve_body($response), $redeem->bucket_id);
            }
        }
        $report = $manifest === null ? array('candidates' => array(), 'omitted' => 0)
            : OCM_Extra_Domains::finalize($manifest['found'], $manifest['source'], site_url());
        $token = wp_generate_password(32, false, false);
        $plan = array(
            'user' => get_current_user_id(),
            'code_hash' => hash('sha256', $code),
            'destination' => site_url(),
            'excluded' => $excluded,
            'bucket_id' => $redeem->bucket_id,
            'reviewed' => $manifest !== null,
            'candidates' => $report['candidates'],
        );
        if (!set_transient('ocm_restore_precheck_' . $token, $plan, self::TTL)) {
            wp_send_json_error(array('message' => 'The restore preparation could not be saved. Please try again.'), 500);
        }
        $rows = OCM_Extra_Domains::public_rows($report['candidates']);
        $db = new OCM_DB();
        foreach ($rows as &$row) {
            $row['checked'] = 0;
            $candidate = null;
            foreach ($report['candidates'] as $item) {
                if ($item['id'] === $row['id']) {
                    $candidate = $item;
                    break;
                }
            }
            $row['preview'] = $row['example'];
            foreach (OCM_Extra_Domains::replacement_pairs($candidate, site_url()) as $pair) {
                $row['preview'] = $db->str_replace($pair['search'], $pair['replace'], $row['preview'], true);
            }
        }
        unset($row);
        wp_send_json_success(array(
            'token' => $token,
            'candidates' => $rows,
            'destination' => site_url(),
            'legacy' => $manifest === null && !in_array('db', $excluded, true),
            'omitted' => (int) ($manifest['omitted'] ?? 0) + $report['omitted'],
        ));
    }

    /** Selection IDs are resolved exclusively against the server-side inventory. */
    public static function consume($token, $code, $excluded, $ids) {
        if (!is_string($token) || !preg_match('/^[a-zA-Z0-9]{32}$/', $token)) {
            return new \WP_Error('precheck_missing', 'Check the backup again before starting restore.');
        }
        $key = 'ocm_restore_precheck_' . $token;
        $plan = get_transient($key);
        if (!is_array($plan) || $plan['user'] !== get_current_user_id()
            || !hash_equals($plan['code_hash'], hash('sha256', $code))
            || $plan['destination'] !== site_url() || $plan['excluded'] !== self::exclusions($excluded)) {
            return new \WP_Error('precheck_expired', 'The restore details changed or the check expired. Check the backup again.');
        }
        if (!is_array($ids) || count($ids) > OCM_Extra_Domains::MAX_CANDIDATES) {
            return new \WP_Error('precheck_selection', 'The address selection is invalid. Check the backup again.');
        }
        $selected = array();
        $available = array_column($plan['candidates'], null, 'id');
        foreach ($ids as $id) {
            if (!is_string($id) || !isset($available[$id])) {
                return new \WP_Error('precheck_selection', 'The address selection is invalid. Check the backup again.');
            }
            $selected[$id] = $available[$id];
        }
        delete_transient($key);
        return array(
            'bucket_id' => $plan['bucket_id'],
            'destination' => $plan['destination'],
            'reviewed' => $plan['reviewed'],
            'selected' => array_values($selected),
        );
    }

    public static function replacement_args($plan, $source, $destination) {
        $pairs = OCM_Extra_Domains::primary_replacement_pairs($source, $destination);
        if (is_array($plan) && !empty($plan['reviewed']) && ($plan['destination'] ?? '') === $destination) {
            foreach ($plan['selected'] as $row) {
                $pairs = array_merge(
                    $pairs,
                    OCM_Extra_Domains::with_json_escaped_variants(
                        OCM_Extra_Domains::replacement_pairs($row, $destination)
                    )
                );
            }
        }
        return array('pairs' => $pairs);
    }
}
