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

    /**
     * Did a restore-code lookup fail because the 1-Click Migration service was
     * busy or out of reach, rather than because of the code? OCM_S3 has already
     * retried no answer, 429 and 5xx by then, so this is a rate limit or an
     * outage that outlasted the retries. Only a 4xx other than 429 is an answer
     * about the code itself: wrong, or expired.
     *
     * @param int   $status HTTP status of the last answer, 0 when none came.
     * @param mixed $error  The answer's error field; 'network' when none came.
     * @return bool
     */
    public static function lookup_unavailable($status, $error) {
        $status = (int) $status;
        return 'network' === $error || 429 === $status || $status < 400 || $status >= 500;
    }

    /** @return string For the admin when the lookup failed but the backup is fine. */
    public static function service_busy_message() {
        return __('The 1-Click Migration service is busy or could not be reached right now. Your backup is safe. Please try again in a minute.', '1-click-migration');
    }

    /** @return string For the admin when the service says the code is wrong or expired. */
    public static function code_not_found_message() {
        return __('No backup was found for this restore code. Check the code in your email. Restore codes work for 48 hours after the backup; if yours is older, make a new backup on the old site.', '1-click-migration');
    }

    /** Our own site: the only place a "Pay now" link may point. */
    const PAY_URL_PREFIX = 'https://1clickmigration.com/';

    /**
     * Did the service answer that this backup exists but has not been paid for
     * yet (HTTP 402, error payment_required)? Returns what the admin sees: the
     * price ('' when the service named none) and the pay link ('' unless it
     * points at our own site). Null for every other answer.
     *
     * @param mixed $redeem What OCM_S3::redeem_restore_code() returned.
     * @return array|null
     */
    public static function payment_required($redeem) {
        if (!is_object($redeem) || 402 !== (int) ($redeem->_status ?? 0) || 'payment_required' !== ($redeem->error ?? '')) {
            return null;
        }
        return array(
            'price'   => self::price_label($redeem->amount ?? '', $redeem->currency ?? ''),
            'pay_url' => self::pay_url($redeem->pay_url ?? ''),
        );
    }

    /**
     * "9.99", "USD" → "$9.99"; other currencies read "9.99 EUR". '' unless both
     * are exactly an amount with two decimals and a three-letter code, so the
     * service's answer can go into a message as it is.
     */
    public static function price_label($amount, $currency) {
        if (!is_string($amount) || !is_string($currency)
            || !preg_match('/^[0-9]{1,6}\.[0-9]{2}$/D', $amount) || !preg_match('/^[A-Z]{3}$/D', $currency)) {
            return '';
        }
        return 'USD' === $currency ? '$' . $amount : $amount . ' ' . $currency;
    }

    /** The pay link, or '' unless it is a plain https address on our own site. */
    public static function pay_url($url) {
        return is_string($url) && strlen($url) <= 300 && 0 === strpos($url, self::PAY_URL_PREFIX)
            && preg_match('/^[A-Za-z0-9:\/?&=._~%-]+$/D', $url) ? $url : '';
    }

    /** @return string For the admin when the backup has to be paid for before it can be restored. */
    public static function payment_required_message($price = '') {
        if ('' !== $price) {
            return sprintf(
                /* translators: %s: price of the migration, e.g. $9.99 */
                __('This backup hasn\'t been paid for yet. Restoring it costs %s, one time. Pay from the link in your restore-code email, then click Check backup & restore again. If the migration doesn\'t work, reply to that email and we\'ll fix it or refund you in full.', '1-click-migration'),
                $price
            );
        }
        return __('This backup hasn\'t been paid for yet. Pay from the link in your restore-code email, then click Check backup & restore again. If the migration doesn\'t work, reply to that email and we\'ll fix it or refund you in full.', '1-click-migration');
    }

    public static function prepare() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Only a site administrator can do this.', '1-click-migration')), 403);
        }
        check_ajax_referer('ocm_prepare_restore');
        if (get_option('ocm_action_start_backup') || get_option('ocm_action_start_restore')) {
            wp_send_json_error(array('message' => __('Wait for the current operation to finish, or stop it before preparing a restore.', '1-click-migration')), 409);
        }
        $code = isset($_POST['restore_code']) && is_string($_POST['restore_code'])
            ? strtoupper(preg_replace('/[\s-]+/', '', wp_unslash($_POST['restore_code']))) : '';
        if (!preg_match('/^[A-Z0-9]{6,32}$/', $code)) {
            wp_send_json_error(array('message' => __('Enter the restore code from your backup email.', '1-click-migration')), 400);
        }
        $excluded = self::exclusions($_POST['selected'] ?? '');
        if (count($excluded) === 4) {
            wp_send_json_error(array('message' => __('Select at least one section to restore.', '1-click-migration')), 400);
        }
        $redeem = OCM_S3::redeem_restore_code($code);
        if (!is_object($redeem) || ($redeem->_status ?? 0) !== 200 || !empty($redeem->error)
            || empty($redeem->bucket_id) || empty($redeem->aes_key_b64) || empty($redeem->urls)) {
            // A busy or unreachable service must not read as a lost backup.
            $redeem = is_object($redeem) ? $redeem : (object) array();
            // Neither must a backup that is simply waiting to be paid for.
            $payment = self::payment_required($redeem);
            if (null !== $payment) {
                wp_send_json_error(array(
                    'code'    => 'payment_required',
                    'message' => self::payment_required_message($payment['price']),
                    'pay_url' => $payment['pay_url'],
                ), 400);
            }
            if (self::lookup_unavailable($redeem->_status ?? 0, $redeem->error ?? '')) {
                wp_send_json_error(array('message' => self::service_busy_message()), 503);
            }
            wp_send_json_error(array('message' => self::code_not_found_message()), 400);
        }
        $manifest = null;
        if (!in_array('db', $excluded, true) && !empty($redeem->urls->log_download)) {
            $response = wp_remote_get($redeem->urls->log_download, array(
                'timeout' => 30, 'limit_response_size' => 4 * 1024 * 1024,
            ));
            if (is_wp_error($response) || !in_array((int) wp_remote_retrieve_response_code($response), array(200, 404), true)) {
                wp_send_json_error(array('message' => __('The backup address check could not be downloaded. Please try again.', '1-click-migration')), 502);
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
            wp_send_json_error(array('message' => __('The restore preparation could not be saved. Please try again.', '1-click-migration')), 500);
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
            return new \WP_Error('precheck_missing', __('Check the backup again before starting restore.', '1-click-migration'));
        }
        $key = 'ocm_restore_precheck_' . $token;
        $plan = get_transient($key);
        if (!is_array($plan) || $plan['user'] !== get_current_user_id()
            || !hash_equals($plan['code_hash'], hash('sha256', $code))
            || $plan['destination'] !== site_url() || $plan['excluded'] !== self::exclusions($excluded)) {
            return new \WP_Error('precheck_expired', __('The restore details changed or the check expired. Check the backup again.', '1-click-migration'));
        }
        if (!is_array($ids) || count($ids) > OCM_Extra_Domains::MAX_CANDIDATES) {
            return new \WP_Error('precheck_selection', __('The address selection is invalid. Check the backup again.', '1-click-migration'));
        }
        $selected = array();
        $available = array_column($plan['candidates'], null, 'id');
        foreach ($ids as $id) {
            if (!is_string($id) || !isset($available[$id])) {
                return new \WP_Error('precheck_selection', __('The address selection is invalid. Check the backup again.', '1-click-migration'));
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
