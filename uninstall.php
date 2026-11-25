<?php
/**
 * Runs when the plugin is deleted from the Plugins screen (not on deactivation).
 *
 * The choice to share anonymous Local Backups reports must not outlive the
 * plugin: without this, installing it again later would start sending reports
 * with no new question asked. Deleting the option also drops the random value
 * the anonymous install ID is made from, so a later install gets a new ID.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Forget the reporting choice, and the report throttles, on the current site.
 *
 * @return void
 */
function ocm_uninstall_forget_local_telemetry()
{
    delete_option('ocm_local_telemetry');
    delete_transient('ocm_ltel_rate');
}

if (function_exists('is_multisite') && is_multisite() && function_exists('get_sites')) {
    $ocm_uninstall_site_ids = get_sites(array('fields' => 'ids', 'number' => 1000));
    foreach ((array) $ocm_uninstall_site_ids as $ocm_uninstall_site_id) {
        switch_to_blog((int) $ocm_uninstall_site_id);
        ocm_uninstall_forget_local_telemetry();
        restore_current_blog();
    }
} else {
    ocm_uninstall_forget_local_telemetry();
}
