<?php

namespace OCM;

class OCM_Admin_Page
{
    /** Inline markup a translated admin string may keep (wp_kses() allowlist). */
    const INLINE_HTML = array('strong' => array(), 'code' => array(), 'em' => array());

    public static function add_admin_page()
    {
        add_management_page(
            __('One Click Migration', '1-click-migration'),
            __('One Click Migration', '1-click-migration'),
            'manage_options',
            'one-click-migration',
            array(__CLASS__, 'display_admin_menu')
        );
    }

    /**
     * Echo a translated string that may contain the inline tags in INLINE_HTML.
     *
     * @param string $text Translated text.
     */
    private static function echo_inline($text)
    {
        echo wp_kses($text, self::INLINE_HTML);
    }

    /**
     * "Ask on the support forum" sentence as a format string: %1$s and %2$s
     * wrap the link text. The forum is public, so it asks for the site's
     * domain only: anyone with the restore code can restore the backup.
     * admin-script.js gets it through script_strings().
     *
     * @return string Translated, unescaped.
     */
    public static function support_forum_sentence()
    {
        /* translators: 1: opening link tag to the WordPress.org support forum, 2: closing link tag. */
        return __('%1$sAsk on the support forum%2$s and share your site\'s domain. Never post your restore code: anyone who has it can restore your backup.', '1-click-migration');
    }

    /**
     * Echo the support forum sentence after a short lead.
     *
     * @param string $lead Translated question in front of the link, e.g. "Still stuck?".
     * @param string $url  Support forum URL.
     */
    private static function echo_support_forum($lead, $url)
    {
        echo esc_html($lead) . ' ';
        printf(
            wp_kses(self::support_forum_sentence(), self::INLINE_HTML),
            '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">',
            '</a>'
        );
    }

    /**
     * The log line at the start of a progress text, before the help that
     * get_progress() appends after a line break. Error and notice detection
     * reads only this part (admin-script.js does the same): the log line is
     * English protocol, the help after it is translated and may contain
     * "Error" in another language.
     *
     * @param string $text Progress text (HTML).
     * @return string
     */
    public static function status_head($text)
    {
        $parts = preg_split('#<\s*/?\s*br\s*/?\s*>#i', (string) $text, 2);
        return (string) $parts[0];
    }

    /** @return int Active plugins other than this one. */
    public static function other_active_plugins_count()
    {
        $active_plugins = (array) get_option('active_plugins', array());
        $this_plugin_basename = plugin_basename(OCM_MAIN_FILE);
        $count = 0;
        foreach ($active_plugins as $plugin) {
            if ($plugin !== $this_plugin_basename) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Strings admin-script.js shows, passed as siteData.i18n. Placeholders:
     * %s / %d, or %1$s and %2$s around link text. The script escapes them
     * before inserting them as HTML.
     *
     * @return array<string,string>
     */
    public static function script_strings()
    {
        return array(
            'invalidEmail'          => __('Please enter a valid email.', '1-click-migration'),
            'backupStarted'         => __('Backup started', '1-click-migration'),
            'restoreStarted'        => __('Restore started', '1-click-migration'),
            'restoreStartedLegacy'  => __('Restore started. This older backup will be checked for extra addresses after restore.', '1-click-migration'),
            'checkBackupAgain'      => __('Check the backup again before starting restore.', '1-click-migration'),
            'possibleOldAddress'    => __('Possible old site address', '1-click-migration'),
            'externalAddress'       => __('External or other address', '1-click-migration'),
            /* translators: %s: example link found in the backup */
            'fromExample'           => __('From: %s', '1-click-migration'),
            /* translators: %s: the same link after the restore rewrites it */
            'toExample'             => __('To: %s', '1-click-migration'),
            'reviewAddressesTitle'  => __('Review remaining site addresses', '1-click-migration'),
            'reviewAddressesIntro'  => __('Some links point to other domains. Select only addresses that belonged to the site you are restoring. Your primary site address updates automatically.', '1-click-migration'),
            'otherDomains'          => __('Other domains', '1-click-migration'),
            'otherDomainsNote'      => __('External services and links usually stay unchanged.', '1-click-migration'),
            'addressesOmitted'      => __('Some additional addresses are not listed. You can review remaining links on your restored site.', '1-click-migration'),
            'noAddressesSelected'   => __('No extra addresses selected.', '1-click-migration'),
            /* translators: %d: number of extra addresses the admin checked */
            'addressesSelected'     => __('Extra addresses to update: %d', '1-click-migration'),
            'restoreReplaces'       => __('Starting restore replaces the selected sections of this site with the backup.', '1-click-migration'),
            'startRestore'          => __('Start restore', '1-click-migration'),
            'back'                  => __('Back', '1-click-migration'),
            'pasteRestoreCode'      => __('Paste the restore code from your backup email.', '1-click-migration'),
            'checkingAddresses'     => __('Checking backup addresses…', '1-click-migration'),
            'checkFailed'           => __('Could not check the backup. Please try again.', '1-click-migration'),
            'payReadyLine'          => __('This backup is ready. Pay to restore it.', '1-click-migration'),
            'payFoundTitle'         => __('We found your backup', '1-click-migration'),
            'payReadyTitle'         => __('Your backup is ready to restore', '1-click-migration'),
            'paySite'               => __('Site', '1-click-migration'),
            'payBackedUp'           => __('Backed up', '1-click-migration'),
            'payRestoreThis'        => __('Restore this backup', '1-click-migration'),
            'payOneTime'            => __('One time. Success guaranteed or a refund.', '1-click-migration'),
            /* translators: %s: price of the migration, e.g. $9.99 */
            'payAndRestorePrice'    => __('Pay %s and restore', '1-click-migration'),
            'payAndRestore'         => __('Pay and restore', '1-click-migration'),
            'payPayPalNote'         => __('You pay on PayPal\'s own page, with a card or a PayPal account. It opens in a small window.', '1-click-migration'),
            'payGuarantee'          => __('If the migration doesn\'t work, we\'ll fix it or refund you in full.', '1-click-migration'),
            'payWaitingTitle'       => __('Waiting for your payment…', '1-click-migration'),
            'payWaitingBody'        => __('Finish paying in the PayPal window. Your restore starts here by itself as soon as it goes through.', '1-click-migration'),
            'payPendingBody'        => __('PayPal is still processing your payment. Your restore starts here by itself when it clears. You don\'t need to pay again.', '1-click-migration'),
            'payReopenWindow'       => __('Reopen payment window', '1-click-migration'),
            'payIvePaid'            => __('I\'ve paid', '1-click-migration'),
            'payClosedNote'         => __('Closed the window without paying? Nothing was charged, and nothing on this site has changed.', '1-click-migration'),
            'payGone'               => __('This backup is no longer available. Make a new backup on the old site.', '1-click-migration'),
            'payPaidTitle'          => __('Paid. Starting your restore…', '1-click-migration'),
            'payNotReceived'        => __('We haven\'t received the payment yet. If you just paid, give it a few seconds and try again.', '1-click-migration'),
            'payBannerThanks'       => __('Paid. Thank you! Now use your restore code on the new site.', '1-click-migration'),
            'otherAddressesFound'   => __('Other addresses found', '1-click-migration'),
            'otherAddressesIntro'   => __('Checked addresses will be rewritten to this site. Close to leave them unchanged.', '1-click-migration'),
            'updateAddresses'       => __('Update checked addresses', '1-click-migration'),
            'updatingAddresses'     => __('Updating addresses…', '1-click-migration'),
            'addressesRetry'        => __('Some addresses may not have been updated. Retry to finish updating.', '1-click-migration'),
            /* translators: %d: number of database rows the address update changed */
            'rowsUpdated'           => __('Database rows updated: %d', '1-click-migration'),
            'noAddressesRewritten'  => __('No addresses were rewritten.', '1-click-migration'),
            'restorePartialTitle'   => __('Restore completed with partial updates', '1-click-migration'),
            'restoreWarnedTitle'    => __('Restore completed with warnings', '1-click-migration'),
            'restoreDoneTitle'      => __('Restore complete', '1-click-migration'),
            'restorePartialBody'    => __('Your site was restored, but some address updates did not apply. Review remaining links on your site.', '1-click-migration'),
            'restoreWarnedBody'     => __('Your site was restored, but some parts were skipped or incomplete. Check your site, and if something is missing, download the log and ask in the support forum.', '1-click-migration'),
            'loggedOut'             => __('You were logged out, which is normal during a restore. Log back in with the username and password of the site you backed up; the restore keeps going.', '1-click-migration'),
            'restoreCheckBody'      => __('Your site was restored successfully. Did everything come across?', '1-click-migration'),
            'looksGood'             => __('Yes, looks good', '1-click-migration'),
            'somethingOff'          => __('Something\'s off', '1-click-migration'),
            'restoreReviewBody'     => __('Great. If this plugin helped, would you leave a quick review on WordPress.org? One line is plenty. You need a free WordPress.org account.', '1-click-migration'),
            'restoreTroubleBody'    => __('Sorry about that. We\'ll help you sort it out.', '1-click-migration'),
            'openForum'             => __('Open the support forum', '1-click-migration'),
            'restoreDoneBody'       => __('Your site was restored successfully.', '1-click-migration'),
            'needHelp'              => __('Need help?', '1-click-migration'),
            'supportForum'          => self::support_forum_sentence(),
            'leaveReview'           => __('Leave a review', '1-click-migration'),
            'dontAskAgain'          => __('Don\'t ask again', '1-click-migration'),
            'close'                 => __('Close', '1-click-migration'),
            'localSelectPart'       => __('Please select at least one component.', '1-click-migration'),
            'localBackupStarted'    => __('Local backup started', '1-click-migration'),
            'localRestoreStarted'   => __('Local restore started', '1-click-migration'),
            'localSettingsSaved'    => __('Schedule and storage settings saved.', '1-click-migration'),
            'localSettingsFailed'   => __('Could not save settings. Please try again.', '1-click-migration'),
            'localDeleteConfirm'    => __('Delete this local backup permanently? This cannot be undone.', '1-click-migration'),
            'localCancelConfirm'    => __('Stop and reset the running local operation?', '1-click-migration'),
            'localCancelRestoreConfirm' => __('Stop the running restore? A restore can only be stopped before the site\'s files or database have been replaced.', '1-click-migration'),
            'localCancelFailed'     => __('Could not stop the operation. Reload the page and try again.', '1-click-migration'),
            'localStartExpired'     => __('Nothing was started: this page has expired or you were logged out. Reload the page and try again.', '1-click-migration'),
            'localStartFailed'      => __('Nothing was started: the server answered with an error. Please try again.', '1-click-migration'),
            'localStartUnconfirmed' => __('The server did not confirm the start, and nothing is running now. Check Activity History below, then try again.', '1-click-migration'),
            'localLoggedOut'        => __('You were logged out, which is normal when a restore replaces the database. Log back in with the username and password this site had when the backup was made; the restore keeps going.', '1-click-migration'),
            'localProgressLost'     => __('Lost contact with the server, so progress stopped updating. The operation may still be running. Reload the page to check.', '1-click-migration'),
            /* translators: 1: number of newest backups that are kept, 2: number of older backups that will be deleted */
            'localPruneConfirm'     => __('Only the newest %1$d backups will be kept. Older backups deleted permanently when you save: %2$d. This cannot be undone. Save anyway?', '1-click-migration'),
            'localDeleteFailed'     => __('Could not delete this backup. Reload the page and try again.', '1-click-migration'),
            'localClearLogFailed'   => __('Could not clear the log. Reload the page and try again.', '1-click-migration'),
            'localClearActivityFailed' => __('Could not clear the history. Reload the page and try again.', '1-click-migration'),
            /* translators: %d: free disk space, in megabytes, below which local backups are not made */
            'localMinGuard'         => __('Min guard: %d MB', '1-click-migration'),
            'localGuardOff'         => __('Space guard off', '1-click-migration'),
            'localPartDb'           => __('Database', '1-click-migration'),
            'localPartThemes'       => __('Themes', '1-click-migration'),
            'localPartPlugins'      => __('Plugins', '1-click-migration'),
            'localPartUploads'      => __('Uploads', '1-click-migration'),
            'localNoBackups'        => __('No local backups saved yet. Create one above or enable the schedule.', '1-click-migration'),
            'localNextRun'          => __('Next run:', '1-click-migration'),
            'localScheduleDisabled' => __('Schedule is disabled.', '1-click-migration'),
            'localUnlimited'        => __('Unlimited', '1-click-migration'),
            /* translators: %d: retention count */
            'localKeepLastLabel'    => __('Keep last %d', '1-click-migration'),
            'localLowSpace'         => __('Low space', '1-click-migration'),
            'localManual'           => __('Manual', '1-click-migration'),
            'localScheduled'        => __('Scheduled', '1-click-migration'),
            'localRestoreBtn'       => __('Restore', '1-click-migration'),
            'localDeleteBtn'        => __('Delete', '1-click-migration'),
            'localDateCol'          => __('Date & Time', '1-click-migration'),
            'localTypeCol'          => __('Type / Label', '1-click-migration'),
            'localPartsCol'         => __('Included Parts', '1-click-migration'),
            'localSizeCol'          => __('Size', '1-click-migration'),
            'localActionsCol'       => __('Actions', '1-click-migration'),
            'localViewLogBtn'       => __('Log', '1-click-migration'),
            'localNoActivity'       => __('No local backup or restore activity recorded yet.', '1-click-migration'),
            'localClearActivityConfirm' => __('Clear the visible local activity history? Saved backup archives will not be deleted.', '1-click-migration'),
            'localClearLogConfirm'  => __('Clear the local backup and restore log file?', '1-click-migration'),
            'localLogEmpty'         => __('No local log entries yet.', '1-click-migration'),
            'localLogLoading'       => __('Loading log…', '1-click-migration'),
            /* translators: %d: number of lines shown */
            'localLogTruncated'     => __('Showing the most recent %d lines. Download the full log to view earlier entries.', '1-click-migration'),
            'localLogCopied'        => __('Copied', '1-click-migration'),
            'localLogCopyBtn'       => __('Copy', '1-click-migration'),
            'localLogLoadFailed'    => __('Could not load local log.', '1-click-migration'),
            'localOpBackup'         => __('Backup', '1-click-migration'),
            'localOpRestore'        => __('Restore', '1-click-migration'),
            'localStatusCompleted'  => __('Completed', '1-click-migration'),
            'localStatusFailed'     => __('Failed', '1-click-migration'),
            'localStatusSkipped'    => __('Skipped', '1-click-migration'),
            'localStatusCancelled'  => __('Cancelled', '1-click-migration'),
            'localActivityDateCol'  => __('Date & Time', '1-click-migration'),
            'localActivityOpCol'    => __('Operation', '1-click-migration'),
            'localActivityPartsCol' => __('Parts', '1-click-migration'),
            'localActivityStatusCol' => __('Status', '1-click-migration'),
            'localActivityDetailsCol' => __('Details', '1-click-migration'),
            'localBackupLogTitle'   => __('Backup log:', '1-click-migration'),
            'localIdleText'         => __('Idle — ready to run a local backup or restore.', '1-click-migration'),
        );
    }

    public static function display_admin_menu()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', '1-click-migration'));
        }

        settings_errors('ocm_messages');

        $progress = OCM_Backup::get_progress();
        $progress_head = self::status_head($progress['text']);
        $hasError = false !== strpos($progress_head, 'Error') && false === strpos($progress_head, 'SYSLOG: "[PHP ERR][FATAL]');
        $ocm_action_start_backup  = get_option('ocm_action_start_backup');
        $ocm_action_start_restore = get_option('ocm_action_start_restore');
        $action_active            = $ocm_action_start_backup || $ocm_action_start_restore;
        $log_file_exists          = file_exists(OCM_DEBUG_LOG_FILE);
        $log_url                  = wp_nonce_url(
            admin_url('admin-post.php?action=ocm_download_log'),
            'ocm_download_log'
        );
        $ocm_user_email_value     = (string) get_option('ocm_user_email', '');
        $start_action_value       = $ocm_action_start_backup ? 'backup' : ($ocm_action_start_restore ? 'restore' : '');
        $progress_text_safe       = wp_kses_post($progress['text']);
        $progress_completed       = isset($progress['value']) && $progress['value'] === '100%';
        // A failed run's outcome, fix and forum link stay on the page after a
        // reload; before, it showed "Idle" and the user had nothing to go on.
        $show_progress_text       = $log_file_exists && ($action_active || $progress_completed || !empty($progress['failed']));
        $other_plugins            = self::other_active_plugins_count();

        $messages = [
            'endpoint_failure'      => __('The plugin could not reach the 1-Click Migration service. Make sure the plugin is up to date, then try again.', '1-click-migration'),
            'no_bucket'             => __('We could not find a backup for this restore code. If your backup was created more than 48 hours ago, you need to re-create it as it has been deleted.', '1-click-migration'),
            'missing_username'      => __('Enter the email address the restore code should go to, then try again.', '1-click-migration'),
            'missing_restore_code'  => __('Restore code is missing. Paste the 8-character code we emailed you after the backup.', '1-click-migration'),
            'invalid_restore_code'  => __('Restore code is invalid or expired. Check the email we sent after your backup, or run a new backup.', '1-click-migration'),
            'service_busy'          => OCM_Restore_Precheck::service_busy_message(),
            'payment_required'      => OCM_Restore_Precheck::payment_required_message(),
            'backup_in_progress'    => __('A backup or restore is already running on this site. Wait for it to finish, or click Stop & Reset to cancel it, then start again.', '1-click-migration'),
            'insufficient_disk_space' => __('The hosting account is out of disk space. Delete files you no longer need or ask your host for more space, then try again.', '1-click-migration'),
            'write_permission'      => __('WordPress isn\'t allowed to write to its wp-content folder. Ask your host to fix the folder permissions, then try again.', '1-click-migration'),
        ];
        $message_key  = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : '';
        $top_message  = $message_key && isset($messages[$message_key]) ? $messages[$message_key] : '';

        $cancel_url  = wp_nonce_url(admin_url('admin-post.php?action=cancel_actions'), 'one_click_migration_cancel_action');
        $version     = One_Click_Migration::get_version();
        $support_url = OCM_Backup::SUPPORT_FORUM_URL;
        $local_active = class_exists(__NAMESPACE__ . '\\OCM_Local_Backup') && OCM_Local_Backup::is_running();
        $requested_mode = isset($_GET['mode']) ? sanitize_text_field($_GET['mode']) : '';
        $active_mode = ($requested_mode === 'local' || ($requested_mode !== 'cloud' && $local_active && !$action_active)) ? 'local' : 'cloud';
        ?>
        <div class="ocm-app">

            <header class="ocm-topbar">
                <div class="ocm-brand">
                    <svg class="ocm-brand-icon" viewBox="0 0 256 256" role="img" aria-label="1 Click Migration">
                        <defs>
                            <filter id="ocm-neon-halo" filterUnits="userSpaceOnUse" x="-40" y="-40" width="336" height="336"><feGaussianBlur stdDeviation="7" result="b"/><feGaussianBlur in="SourceGraphic" stdDeviation="16" result="c"/><feMerge><feMergeNode in="c"/><feMergeNode in="b"/><feMergeNode in="b"/></feMerge></filter>
                            <filter id="ocm-neon-glow" filterUnits="userSpaceOnUse" x="-40" y="-40" width="336" height="336"><feGaussianBlur stdDeviation="5" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
                        </defs>
                        <circle cx="128" cy="128" r="110" fill="#0A0C1F"/>
                        <g fill="none" stroke-linecap="round" stroke-linejoin="round">
                            <circle class="ocm-brand-halo" cx="128" cy="128" r="110" stroke="#FF1E3C" stroke-width="8" filter="url(#ocm-neon-halo)"/>
                            <circle cx="128" cy="128" r="110" stroke="#FF1E3C" stroke-width="7"/>
                            <g filter="url(#ocm-neon-glow)" stroke="#FFE45C" stroke-width="15"><path d="M92 88 L134 60 L134 194"/><path d="M102 196 L166 196"/></g>
                            <g stroke="#FFF7D0" stroke-width="5"><path d="M92 88 L134 60 L134 194"/><path d="M102 196 L166 196"/></g>
                            <path filter="url(#ocm-neon-glow)" stroke="#2F86FF" stroke-width="6" d="M177 128 L177 174.8 L187.9 163.9 L196.5 182.6 L204.3 178.7 L195.7 160.8 L210.5 160 Z"/>
                        </g>
                    </svg>
                    <span class="ocm-brand-name">1 Click Migration</span>
                </div>
                <div class="ocm-topbar-right">
                    <a class="ocm-topbar-link" href="<?php echo esc_url($support_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Support forum', '1-click-migration'); ?></a>
                    <span>v<?php echo esc_html($version); ?></span>
                </div>
            </header>

            <main class="ocm-main">
                <div class="ocm-mode-bar" role="tablist" aria-label="<?php esc_attr_e('Plugin mode', '1-click-migration'); ?>">
                    <button type="button" class="ocm-mode-btn<?php echo $active_mode === 'cloud' ? ' active' : ''; ?>" data-ocm-mode="cloud" role="tab" aria-selected="<?php echo $active_mode === 'cloud' ? 'true' : 'false'; ?>"><?php esc_html_e('Cloud Migration', '1-click-migration'); ?></button>
                    <button type="button" class="ocm-mode-btn<?php echo $active_mode === 'local' ? ' active' : ''; ?>" data-ocm-mode="local" role="tab" aria-selected="<?php echo $active_mode === 'local' ? 'true' : 'false'; ?>"><?php esc_html_e('Local Backups', '1-click-migration'); ?></button>
                </div>

                <div id="ocm-mode-cloud" class="ocm-mode-panel<?php echo $active_mode === 'cloud' ? ' active' : ''; ?>"<?php echo $active_mode === 'cloud' ? '' : ' hidden'; ?>>
                <?php // The restore-code banner after "Backup completed." stays after a reload. ?>
                <div class="ocm-banner ocm-banner-warning" id="ocm-top-banner"<?php echo empty($progress['topBanner']) ? ' style="display:none;"' : ''; ?>><?php if (!empty($progress['topBanner'])) { echo wp_kses_post($progress['topBanner']); } ?></div>

                <h1 class="ocm-h1"><?php esc_html_e('Backup & Restore', '1-click-migration'); ?></h1>
                <p class="ocm-lede"><?php esc_html_e('Create a backup of your site or restore from a previous backup using a restore code.', '1-click-migration'); ?></p>

                <?php if ($top_message): ?>
                    <div class="ocm-banner ocm-banner-error"><?php echo esc_html($top_message); ?></div>
                <?php endif; ?>

                <div class="ocm-grid">
                    <!-- LEFT: Help -->
                    <section class="ocm-card ocm-card-pad" aria-label="<?php esc_attr_e('How it works', '1-click-migration'); ?>">
                        <div class="ocm-h-stack ocm-card-headstrip">
                            <span class="ocm-card-title"><?php esc_html_e('How it works', '1-click-migration'); ?></span>
                            <span class="ocm-mini-hint"><?php esc_html_e('Tap to expand', '1-click-migration'); ?></span>
                        </div>
                        <div class="ocm-help-list">
                            <div class="ocm-help-item" data-ocm-help>
                                <button class="ocm-help-toggle" type="button">
                                    <span class="ocm-h-stack"><span class="ocm-num"><?php echo esc_html_x('B', 'one-letter badge for the backup help section', '1-click-migration'); ?></span> <?php esc_html_e('How to back up?', '1-click-migration'); ?></span>
                                    <svg class="ocm-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                                <div class="ocm-help-body">
                                    <div class="ocm-step">
                                        <div class="ocm-step-title"><?php esc_html_e('1. Prepare your site', '1-click-migration'); ?></div>
                                        <p><?php esc_html_e('Remove unwanted themes, plugins, and media. A database cleanup is recommended — many cleaner plugins can do this for you.', '1-click-migration'); ?></p>
                                        <p class="ocm-callout"><?php self::echo_inline(__('<strong>Important:</strong> deactivate all plugins except 1 Click Migration before starting. Other active plugins may cause issues.', '1-click-migration')); ?></p>
                                    </div>
                                    <div class="ocm-step">
                                        <div class="ocm-step-title"><?php esc_html_e('2. Start the backup', '1-click-migration'); ?></div>
                                        <p><?php self::echo_inline(__('Enter your email and click <strong>Backup</strong>. The backup can take up to 30 minutes depending on site size.', '1-click-migration')); ?></p>
                                        <p><?php self::echo_inline(__('When it completes, you\'ll receive an email with an 8-character <strong>restore code</strong>. Save it — you\'ll need it on the destination site.', '1-click-migration')); ?></p>
                                    </div>
                                    <div class="ocm-step">
                                        <div class="ocm-step-title"><?php esc_html_e('3. Troubleshooting', '1-click-migration'); ?></div>
                                        <p><?php self::echo_inline(__('If the backup fails repeatedly, press <strong>Stop & Reset</strong> and use <strong>Advanced options</strong> to back up one section at a time (e.g. exclude everything except the database for one run, then exclude everything except plugins for the next, and so on).', '1-click-migration')); ?></p>
                                        <p><?php self::echo_inline(__('Each run creates a <strong>separate backup with its own restore code</strong>. Save every email — on the destination site you\'ll need to run Restore once per code to put all the pieces back together.', '1-click-migration')); ?></p>
                                        <p><?php self::echo_support_forum(__('Still stuck?', '1-click-migration'), $support_url); ?></p>
                                    </div>
                                    <div class="ocm-step">
                                        <div class="ocm-step-title"><?php esc_html_e('4. Important info', '1-click-migration'); ?></div>
                                        <p><?php self::echo_inline(__('The restore code is the only way to download your backup. <strong>Save the email we send you</strong> — we cannot retrieve a lost code.', '1-click-migration')); ?></p>
                                        <p><?php self::echo_inline(__('Backups expire after <strong>48 hours</strong>, after which they are deleted from our servers and the code stops working.', '1-click-migration')); ?></p>
                                    </div>
                                </div>
                            </div>
                            <div class="ocm-help-item" data-ocm-help>
                                <button class="ocm-help-toggle" type="button">
                                    <span class="ocm-h-stack"><span class="ocm-num"><?php echo esc_html_x('R', 'one-letter badge for the restore help section', '1-click-migration'); ?></span> <?php esc_html_e('How to restore?', '1-click-migration'); ?></span>
                                    <svg class="ocm-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                                <div class="ocm-help-body">
                                    <div class="ocm-step">
                                        <div class="ocm-step-title"><?php esc_html_e('1. Prepare the new site', '1-click-migration'); ?></div>
                                        <p><?php esc_html_e('Install WordPress and 1 Click Migration on your new host. Delete all other pre-installed plugins before starting the restore.', '1-click-migration'); ?></p>
                                    </div>
                                    <div class="ocm-step">
                                        <div class="ocm-step-title"><?php esc_html_e('2. Start the restore', '1-click-migration'); ?></div>
                                        <p><?php self::echo_inline(__('Paste the 8-character restore code from the email we sent you, then click <strong>Check backup & restore</strong>. Review any extra site addresses before starting. The restore can take up to 30 minutes depending on size.', '1-click-migration')); ?></p>
                                    </div>
                                    <div class="ocm-step">
                                        <div class="ocm-step-title"><?php esc_html_e('3. Troubleshooting', '1-click-migration'); ?></div>
                                        <p><?php self::echo_inline(__('If your backup is large and restoring fails, press <strong>Stop & Reset</strong>, then use <strong>Advanced options</strong> to restore one section at a time. Restore the database last.', '1-click-migration')); ?></p>
                                        <p><?php self::echo_inline(__('If you took multiple partial backups (one per section), you\'ll have a separate restore code for each. Run Restore once per code — suggested order: <strong>themes, plugins, uploads, database last</strong>.', '1-click-migration')); ?></p>
                                        <p><?php self::echo_support_forum(__('Still stuck?', '1-click-migration'), $support_url); ?></p>
                                    </div>
                                    <div class="ocm-step">
                                        <div class="ocm-step-title"><?php esc_html_e('4. Important info', '1-click-migration'); ?></div>
                                        <p><?php esc_html_e('Upon restore, this site\'s URL becomes the new primary domain and is updated throughout the database. Original WordPress users and passwords are also restored.', '1-click-migration'); ?></p>
                                        <p><?php self::echo_inline(__('Backup files are deleted after <strong>48 hours</strong>. If migration isn\'t finished by then, run the backup again.', '1-click-migration')); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <p class="ocm-help-forum"><?php self::echo_support_forum(__('Questions or backup issues?', '1-click-migration'), $support_url); ?></p>
                    </section>

                    <!-- RIGHT: Action panel -->
                    <section class="ocm-card ocm-action-card" aria-label="<?php esc_attr_e('Backup or restore', '1-click-migration'); ?>">
                        <div class="ocm-action-head">
                            <div class="ocm-tabs" role="tablist">
                                <button class="ocm-tab <?php echo $start_action_value === 'restore' ? '' : 'active'; ?>" type="button" data-ocm-tab="backup" role="tab"
                                    aria-selected="<?php echo $start_action_value === 'restore' ? 'false' : 'true'; ?>"><?php esc_html_e('Backup', '1-click-migration'); ?></button>
                                <button class="ocm-tab <?php echo $start_action_value === 'restore' ? 'active' : ''; ?>" type="button" data-ocm-tab="restore" role="tab"
                                    aria-selected="<?php echo $start_action_value === 'restore' ? 'true' : 'false'; ?>"><?php esc_html_e('Restore', '1-click-migration'); ?></button>
                            </div>
                            <?php if ($log_file_exists): ?>
                                <a class="ocm-btn-link" href="<?php echo esc_url($log_url); ?>" target="_blank" title="<?php esc_attr_e('Download full log file', '1-click-migration'); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    <?php esc_html_e('Download log', '1-click-migration'); ?>
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="ocm-action-body">
                            <label class="ocm-warn" id="ocm-plugins-warning" style="display:none;">
                                <input type="checkbox" id="ocm-plugins-ack-checkbox"/>
                                <span><?php
                                    printf(
                                        wp_kses(
                                            /* translators: %s: number of other active plugins */
                                            _n(
                                                '<strong>Warning:</strong> You have <strong>%s other plugin</strong> enabled. Running backup/restore with other plugins active is not advised. Check this box to proceed anyway.',
                                                '<strong>Warning:</strong> You have <strong>%s other plugins</strong> enabled. Running backup/restore with other plugins active is not advised. Check this box to proceed anyway.',
                                                $other_plugins,
                                                '1-click-migration'
                                            ),
                                            self::INLINE_HTML
                                        ),
                                        '<span id="ocm-other-plugins-count">' . (int) $other_plugins . '</span>'
                                    );
                                ?></span>
                            </label>

                            <!-- BACKUP PANE -->
                            <div class="ocm-pane <?php echo $start_action_value === 'restore' ? '' : 'active'; ?>" data-ocm-pane="backup">
                                <div class="ocm-field">
                                    <label class="ocm-label" for="ocm_user_email"><?php esc_html_e('Email', '1-click-migration'); ?> <span class="ocm-hint">&mdash; <?php esc_html_e('for the restore code', '1-click-migration'); ?></span></label>
                                    <div class="ocm-input-line">
                                        <div class="ocm-input-wrap">
                                            <span class="ocm-input-icon" aria-hidden="true">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                                            </span>
                                            <input id="ocm_user_email" name="ocm_user_email" class="ocm-input" type="email" autocomplete="email"
                                                placeholder="you@company.com"
                                                value="<?php echo esc_attr($ocm_user_email_value); ?>"/>
                                        </div>
                                        <button class="ocm-btn ocm-btn-dark ocm-submit ocm-button backup-button" type="button" id="ocm-btn-backup">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                            <?php esc_html_e('Start backup', '1-click-migration'); ?>
                                        </button>
                                    </div>
                                    <div class="ocm-field-help"><?php esc_html_e('We\'ll email you a restore code when the backup completes.', '1-click-migration'); ?></div>
                                </div>
                            </div>

                            <!-- RESTORE PANE -->
                            <div class="ocm-pane <?php echo $start_action_value === 'restore' ? 'active' : ''; ?>" data-ocm-pane="restore">
                                <div class="ocm-field">
                                    <label class="ocm-label" for="ocm_restore_code"><?php esc_html_e('Restore code', '1-click-migration'); ?> <span class="ocm-hint">&mdash; <?php esc_html_e('sent to your email', '1-click-migration'); ?></span></label>
                                    <div class="ocm-input-line">
                                        <div class="ocm-input-wrap">
                                            <span class="ocm-input-icon" aria-hidden="true">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                                            </span>
                                            <input id="ocm_restore_code" name="ocm_restore_code" class="ocm-input ocm-input-code" type="text" autocomplete="off"
                                                placeholder="XXXX-XXXX" maxlength="9" spellcheck="false" autocapitalize="characters" value=""/>
                                        </div>
                                        <button class="ocm-btn ocm-btn-primary ocm-submit ocm-button restore-button" type="button" id="ocm-btn-restore">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                                            <?php esc_html_e('Check backup & restore', '1-click-migration'); ?>
                                        </button>
                                    </div>
                                    <div class="ocm-field-help"><?php esc_html_e('We check the backup first. If extra site addresses are found, you can review them before restore starts.', '1-click-migration'); ?></div>
                                </div>
                                <section id="ocm-restore-precheck" aria-label="<?php esc_attr_e('Backup address review', '1-click-migration'); ?>" hidden></section>
                            </div>

                            <!-- ADVANCED -->
                            <div class="ocm-advanced">
                                <button class="ocm-adv-toggle" id="ocm-adv-toggle" type="button" aria-expanded="false">
                                    <span class="ocm-h-stack">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                        <?php esc_html_e('Advanced options', '1-click-migration'); ?>
                                    </span>
                                    <svg class="ocm-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                                <div class="ocm-adv-grid" id="ocm-adv-grid">
                                    <label class="ocm-check"><input type="checkbox" data-ocm-exclude="plugins"><span><?php esc_html_e('Exclude Plugins', '1-click-migration'); ?><small><?php esc_html_e('Skip plugin files', '1-click-migration'); ?></small></span></label>
                                    <label class="ocm-check"><input type="checkbox" data-ocm-exclude="uploads"><span><?php esc_html_e('Exclude Uploads', '1-click-migration'); ?><small><?php esc_html_e('Skip media library', '1-click-migration'); ?></small></span></label>
                                    <label class="ocm-check"><input type="checkbox" data-ocm-exclude="themes"><span><?php esc_html_e('Exclude Themes', '1-click-migration'); ?><small><?php esc_html_e('Skip theme files', '1-click-migration'); ?></small></span></label>
                                    <label class="ocm-check"><input type="checkbox" data-ocm-exclude="db"><span><?php esc_html_e('Exclude Database', '1-click-migration'); ?><small><?php esc_html_e('Skip DB tables', '1-click-migration'); ?></small></span></label>
                                </div>
                                <p class="ocm-exclusion-warning" style="display:none;"><?php esc_html_e('Please leave at least one component selected.', '1-click-migration'); ?></p>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- PROGRESS STRIP -->
                <section class="ocm-card ocm-progress-card" aria-label="<?php esc_attr_e('Progress', '1-click-migration'); ?>">
                    <div class="ocm-pg-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                        <span><?php esc_html_e('Progress', '1-click-migration'); ?></span>
                        <span class="ocm-pg-pct" id="ocm-pg-pct"><?php echo esc_html($progress['value']); ?></span>
                    </div>
                    <div>
                        <div class="ocm-pg-bar"><div class="ocm-pg-fill <?php echo $action_active ? 'running' : ($progress_completed ? 'ocm-pg-done' : (!empty($progress['failed']) ? 'ocm-pg-failed' : '')); ?>" id="ocm-pg-fill" style="width:<?php echo esc_attr($progress['value']); ?>"></div></div>
                        <div class="ocm-pg-status" id="ocm-pg-status">
                            <?php echo $show_progress_text ? $progress_text_safe : esc_html__('Idle — waiting to start.', '1-click-migration'); ?>
                        </div>
                        <div class="ocm-pg-extras">
                            <span class="ocm-info notice"></span>
                            <span class="ocm-info error<?php echo $hasError ? ' show' : ''; ?>"><?php if ($hasError) echo $progress_text_safe; ?></span>
                        </div>
                    </div>
                    <div class="ocm-pg-actions">
                        <a class="ocm-btn ocm-btn-ghost" href="<?php echo esc_url($cancel_url); ?>" id="ocm-btn-stop">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 4 3 10 9 10"/></svg>
                            <?php esc_html_e('Stop & Reset', '1-click-migration'); ?>
                        </a>
                    </div>
                </section>

                <div class="ocm-maintenance-mode-indicator" style="display:none;">
                    <?php self::echo_inline(__('<strong>Site in maintenance mode</strong> — your website is currently being restored and is not accessible to visitors.', '1-click-migration')); ?>
                </div>

                <div class="ocm-footnote">
                    <span><?php esc_html_e('AES-256 encrypted before upload · Backups expire 48 hours after creation', '1-click-migration'); ?></span>
                </div>
                </div><!-- /#ocm-mode-cloud -->

                <?php self::render_local_backups_panel($active_mode); ?>
            </main>

            <!-- Hidden values consumed by admin-script.js -->
            <input type="hidden" id="ocmStartAction" value="<?php echo esc_attr($start_action_value); ?>"/>
            <a href="<?php echo esc_url(admin_url('admin-post.php?action=start_backup')); ?>" id="ocm-backup-href" hidden></a>
            <a href="<?php echo esc_url(admin_url('admin-post.php?action=start_restore')); ?>" id="ocm-restore-href" hidden></a>
        </div>
        <?php
    }

    /**
     * Render the Local Backups, Scheduler & Storage Management panel.
     *
     * @param string $active_mode 'cloud'|'local'
     * @return void
     */
    private static function render_local_backups_panel($active_mode)
    {
        $has_local = class_exists(__NAMESPACE__ . '\\OCM_Local_Storage')
            && class_exists(__NAMESPACE__ . '\\OCM_Local_Scheduler')
            && class_exists(__NAMESPACE__ . '\\OCM_Local_Backup');

        $storage = $has_local ? OCM_Local_Storage::storage_summary() : array(
            'count' => 0,
            'total_bytes' => 0,
            'total_readable' => '0 B',
            'free_bytes' => 0,
            'free_readable' => 'Unknown',
            'keep_last' => 5,
            'min_free_space_mb' => 500,
            'base_dir' => '',
            'low_space' => false,
        );
        $schedule = $has_local ? OCM_Local_Scheduler::get_schedule() : array(
            'enabled' => false,
            'frequency' => 'daily',
            'time' => '02:00',
            'day_of_week' => 0,
            'day_of_month' => 1,
            'parts' => array('db', 'themes', 'plugins', 'uploads'),
        );
        $status = $has_local ? OCM_Local_Scheduler::get_status() : array(
            'last_run_at' => 0,
            'last_outcome' => '',
            'last_message' => '',
            'last_backup_id' => '',
        );
        $backups = $has_local ? OCM_Local_Storage::list_backups() : array();
        $next_ts = $has_local ? OCM_Local_Scheduler::ensure_scheduled() : 0;
        $next_formatted = ($has_local && $next_ts > 0) ? OCM_Local_Scheduler::format_timestamp($next_ts) : '';
        $local_progress = $has_local ? OCM_Local_Backup::get_progress() : array(
            'text' => __('Idle — ready to run a local backup or restore.', '1-click-migration'),
            'value' => '0%',
            'active' => false,
            'isStopped' => false,
            'failed' => false,
            'completed' => false,
        );
        $local_log_exists = defined('OCM_LOCAL_LOG_FILE') && is_file(OCM_LOCAL_LOG_FILE);
        $local_log_size = ($local_log_exists && class_exists(__NAMESPACE__ . '\\OCM_Backup'))
            ? OCM_Backup::readable_bytes((int) @filesize(OCM_LOCAL_LOG_FILE))
            : '';
        $activity = $has_local ? OCM_Local_Backup::get_activity_log() : array();
        $has_telemetry = class_exists(__NAMESPACE__ . '\\OCM_Local_Telemetry');
        $telemetry_opted_in = $has_telemetry && OCM_Local_Telemetry::is_opted_in();
        // Shown in the consent wording, so it is the address reports really go to.
        $telemetry_url = $has_telemetry ? OCM_Local_Telemetry::endpoint_url() : '';
        if ($telemetry_url === '') {
            $telemetry_url = 'https://1clickmigration.com/api/local_telemetry.php';
        }
        $status_labels = array(
            'completed' => __('Completed', '1-click-migration'),
            'failed'    => __('Failed', '1-click-migration'),
            'skipped'   => __('Skipped', '1-click-migration'),
            'cancelled' => __('Cancelled', '1-click-migration'),
        );
        // What OCM_Local_Scheduler::record_status() stores for the last scheduled run.
        $outcome_labels = array(
            'success'   => $status_labels['completed'],
            'completed' => $status_labels['completed'],
            'failed'    => $status_labels['failed'],
            'skipped'   => $status_labels['skipped'],
            'cancelled' => $status_labels['cancelled'],
            'started'   => __('In progress', '1-click-migration'),
        );
        $local_log_url = wp_nonce_url(admin_url('admin-post.php?action=ocm_download_local_log'), 'ocm_download_local_log');
        $tz_label = $has_local ? OCM_Local_Scheduler::site_timezone()->getName() : 'UTC';
        $part_labels = array(
            'db' => __('Database', '1-click-migration'),
            'themes' => __('Themes', '1-click-migration'),
            'plugins' => __('Plugins', '1-click-migration'),
            'uploads' => __('Uploads', '1-click-migration'),
        );
        $weekdays = array(
            0 => __('Sunday', '1-click-migration'),
            1 => __('Monday', '1-click-migration'),
            2 => __('Tuesday', '1-click-migration'),
            3 => __('Wednesday', '1-click-migration'),
            4 => __('Thursday', '1-click-migration'),
            5 => __('Friday', '1-click-migration'),
            6 => __('Saturday', '1-click-migration'),
        );
        $keep_label = ((int) $storage['keep_last']) > 0
            ? sprintf(__('Keep last %d', '1-click-migration'), (int) $storage['keep_last'])
            : __('Unlimited', '1-click-migration');
        $show_local_progress = $local_log_exists && (!empty($local_progress['active']) || !empty($local_progress['completed']) || !empty($local_progress['failed']));
        ?>
        <div id="ocm-mode-local" class="ocm-mode-panel<?php echo $active_mode === 'local' ? ' active' : ''; ?>"<?php echo $active_mode === 'local' ? '' : ' hidden'; ?>>
            <div class="ocm-local-head">
                <div>
                    <h1 class="ocm-h1"><?php esc_html_e('Local Backups', '1-click-migration'); ?></h1>
                    <p class="ocm-lede"><?php esc_html_e('Create, schedule, and restore full or partial local backups directly on this server. No email or cloud upload required.', '1-click-migration'); ?></p>
                </div>
                <a class="ocm-btn-link" id="ocm-local-download-log" href="<?php echo esc_url($local_log_url); ?>" target="_blank"<?php echo $local_log_exists ? '' : ' style="display:none;"'; ?>>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    <?php esc_html_e('Download local log', '1-click-migration'); ?>
                </a>
            </div>

            <!-- Storage summary strip -->
            <section class="ocm-card ocm-card-pad ocm-local-stats" id="ocm-local-stats" aria-label="<?php esc_attr_e('Local storage summary', '1-click-migration'); ?>">
                <div class="ocm-local-stat">
                    <span class="ocm-local-stat-label"><?php esc_html_e('Stored Backups', '1-click-migration'); ?></span>
                    <strong class="ocm-local-stat-val" id="ocm-local-stat-count"><?php echo (int) $storage['count']; ?></strong>
                    <small class="ocm-local-stat-sub" id="ocm-local-stat-retention"><?php echo esc_html($keep_label); ?></small>
                </div>
                <div class="ocm-local-stat">
                    <span class="ocm-local-stat-label"><?php esc_html_e('Storage Used', '1-click-migration'); ?></span>
                    <strong class="ocm-local-stat-val" id="ocm-local-stat-used"><?php echo esc_html($storage['total_readable']); ?></strong>
                    <small class="ocm-local-stat-sub"><?php esc_html_e('Plain ZIP archives', '1-click-migration'); ?></small>
                </div>
                <div class="ocm-local-stat">
                    <span class="ocm-local-stat-label"><?php esc_html_e('Host Free Space', '1-click-migration'); ?></span>
                    <strong class="ocm-local-stat-val" id="ocm-local-stat-free"><?php echo esc_html($storage['free_readable']); ?></strong>
                    <small class="ocm-local-stat-sub">
                        <span id="ocm-local-stat-guard"><?php echo (int) $storage['min_free_space_mb'] > 0 ? esc_html(sprintf(__('Min guard: %d MB', '1-click-migration'), (int) $storage['min_free_space_mb'])) : esc_html__('Space guard off', '1-click-migration'); ?></span>
                        <span class="ocm-local-low-badge" id="ocm-local-stat-low"<?php echo !empty($storage['low_space']) ? '' : ' hidden'; ?>><?php esc_html_e('Low space', '1-click-migration'); ?></span>
                    </small>
                </div>
                <div class="ocm-local-stat ocm-local-stat-path">
                    <span class="ocm-local-stat-label"><?php esc_html_e('Private Folder', '1-click-migration'); ?></span>
                    <code class="ocm-local-path-code" id="ocm-local-stat-path"><?php echo esc_html($storage['base_dir']); ?></code>
                </div>
            </section>

            <div class="ocm-grid">
                <!-- Create Local Backup -->
                <section class="ocm-card ocm-card-pad" aria-label="<?php esc_attr_e('Create Local Backup', '1-click-migration'); ?>">
                    <div class="ocm-h-stack ocm-card-headstrip">
                        <span class="ocm-card-title"><?php esc_html_e('Create Local Backup', '1-click-migration'); ?></span>
                        <span class="ocm-mini-hint"><?php esc_html_e('On-demand', '1-click-migration'); ?></span>
                    </div>

                    <div class="ocm-field" style="margin-bottom:12px;">
                        <label class="ocm-label" for="ocm-local-label"><?php esc_html_e('Label', '1-click-migration'); ?> <span class="ocm-hint">&mdash; <?php esc_html_e('optional note', '1-click-migration'); ?></span></label>
                        <input id="ocm-local-label" class="ocm-input ocm-local-input-plain" type="text" maxlength="80" placeholder="<?php esc_attr_e('e.g. Before plugin update', '1-click-migration'); ?>"/>
                    </div>

                    <div class="ocm-label" style="margin-bottom:6px;"><?php esc_html_e('Include components', '1-click-migration'); ?></div>
                    <div class="ocm-adv-grid open ocm-local-parts-grid">
                        <label class="ocm-check"><input type="checkbox" data-ocm-local-part="db" checked><span><?php esc_html_e('Database', '1-click-migration'); ?><small><?php esc_html_e('All DB tables', '1-click-migration'); ?></small></span></label>
                        <label class="ocm-check"><input type="checkbox" data-ocm-local-part="themes" checked><span><?php esc_html_e('Themes', '1-click-migration'); ?><small><?php esc_html_e('Theme files', '1-click-migration'); ?></small></span></label>
                        <label class="ocm-check"><input type="checkbox" data-ocm-local-part="plugins" checked><span><?php esc_html_e('Plugins', '1-click-migration'); ?><small><?php esc_html_e('Plugin files', '1-click-migration'); ?></small></span></label>
                        <label class="ocm-check"><input type="checkbox" data-ocm-local-part="uploads" checked><span><?php esc_html_e('Uploads', '1-click-migration'); ?><small><?php esc_html_e('Media library', '1-click-migration'); ?></small></span></label>
                    </div>
                    <p class="ocm-exclusion-warning" id="ocm-local-part-warning" style="display:none;"><?php esc_html_e('Please select at least one component.', '1-click-migration'); ?></p>

                    <div class="ocm-local-form-actions">
                        <button type="button" class="ocm-btn ocm-btn-primary ocm-submit" id="ocm-local-btn-backup">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                            <?php esc_html_e('Create Local Backup', '1-click-migration'); ?>
                        </button>
                    </div>
                </section>

                <!-- Schedule & Storage Management -->
                <section class="ocm-card ocm-card-pad" aria-label="<?php esc_attr_e('Schedule & Storage', '1-click-migration'); ?>">
                    <div class="ocm-h-stack ocm-card-headstrip">
                        <span class="ocm-card-title"><?php esc_html_e('Schedule & Storage', '1-click-migration'); ?></span>
                        <span class="ocm-mini-hint"><?php echo esc_html($tz_label); ?></span>
                    </div>

                    <label class="ocm-local-toggle">
                        <input type="checkbox" id="ocm-local-sched-enabled"<?php echo !empty($schedule['enabled']) ? ' checked' : ''; ?>>
                        <span><?php esc_html_e('Enable automated scheduled local backups', '1-click-migration'); ?></span>
                    </label>

                    <div class="ocm-local-form-grid">
                        <div class="ocm-field">
                            <label class="ocm-label" for="ocm-local-sched-freq"><?php esc_html_e('Frequency', '1-click-migration'); ?></label>
                            <select id="ocm-local-sched-freq" class="ocm-local-select">
                                <option value="hourly"<?php echo $schedule['frequency'] === 'hourly' ? ' selected' : ''; ?>><?php esc_html_e('Hourly', '1-click-migration'); ?></option>
                                <option value="daily"<?php echo $schedule['frequency'] === 'daily' ? ' selected' : ''; ?>><?php esc_html_e('Daily', '1-click-migration'); ?></option>
                                <option value="weekly"<?php echo $schedule['frequency'] === 'weekly' ? ' selected' : ''; ?>><?php esc_html_e('Weekly', '1-click-migration'); ?></option>
                                <option value="monthly"<?php echo $schedule['frequency'] === 'monthly' ? ' selected' : ''; ?>><?php esc_html_e('Monthly', '1-click-migration'); ?></option>
                            </select>
                        </div>

                        <div class="ocm-field" id="ocm-local-sched-dow-wrap"<?php echo $schedule['frequency'] === 'weekly' ? '' : ' style="display:none;"'; ?>>
                            <label class="ocm-label" for="ocm-local-sched-dow"><?php esc_html_e('Day of week', '1-click-migration'); ?></label>
                            <select id="ocm-local-sched-dow" class="ocm-local-select">
                                <?php foreach ($weekdays as $num => $name): ?>
                                    <option value="<?php echo (int) $num; ?>"<?php echo (int) $schedule['day_of_week'] === (int) $num ? ' selected' : ''; ?>><?php echo esc_html($name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ocm-field" id="ocm-local-sched-dom-wrap"<?php echo $schedule['frequency'] === 'monthly' ? '' : ' style="display:none;"'; ?>>
                            <label class="ocm-label" for="ocm-local-sched-dom"><?php esc_html_e('Day of month', '1-click-migration'); ?></label>
                            <select id="ocm-local-sched-dom" class="ocm-local-select">
                                <?php for ($d = 1; $d <= 28; $d++): ?>
                                    <option value="<?php echo (int) $d; ?>"<?php echo (int) $schedule['day_of_month'] === $d ? ' selected' : ''; ?>><?php echo (int) $d; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="ocm-field" id="ocm-local-sched-time-wrap">
                            <label class="ocm-label" for="ocm-local-sched-time"><?php esc_html_e('Time', '1-click-migration'); ?> <span class="ocm-hint" id="ocm-local-sched-time-hint"<?php echo $schedule['frequency'] === 'hourly' ? '' : ' style="display:none;"'; ?>>&mdash; <?php esc_html_e('minute past each hour', '1-click-migration'); ?></span></label>
                            <input id="ocm-local-sched-time" class="ocm-input ocm-local-input-plain" type="time" value="<?php echo esc_attr($schedule['time']); ?>"/>
                        </div>
                    </div>

                    <div class="ocm-label" style="margin:10px 0 6px;"><?php esc_html_e('Scheduled backup parts', '1-click-migration'); ?></div>
                    <div class="ocm-local-inline-parts">
                        <?php foreach ($part_labels as $pkey => $plabel): ?>
                            <label class="ocm-check"><input type="checkbox" data-ocm-local-sched-part="<?php echo esc_attr($pkey); ?>"<?php echo in_array($pkey, $schedule['parts'], true) ? ' checked' : ''; ?>><span><?php echo esc_html($plabel); ?></span></label>
                        <?php endforeach; ?>
                    </div>

                    <div class="ocm-local-form-grid" style="margin-top:10px;">
                        <div class="ocm-field">
                            <label class="ocm-label" for="ocm-local-keep-last"><?php esc_html_e('Keep last N backups', '1-click-migration'); ?></label>
                            <input id="ocm-local-keep-last" class="ocm-input ocm-local-input-plain" type="number" min="0" max="500" value="<?php echo (int) $storage['keep_last']; ?>"/>
                            <div class="ocm-field-help"><?php esc_html_e('Keeps the newest N backups and deletes older ones automatically. Manual and scheduled backups both count. 0 keeps all of them.', '1-click-migration'); ?></div>
                        </div>
                        <div class="ocm-field">
                            <label class="ocm-label" for="ocm-local-min-free"><?php esc_html_e('Minimum free space guard (MB)', '1-click-migration'); ?></label>
                            <input id="ocm-local-min-free" class="ocm-input ocm-local-input-plain" type="number" min="0" max="1048576" value="<?php echo (int) $storage['min_free_space_mb']; ?>"/>
                            <div class="ocm-field-help"><?php esc_html_e('0 = disabled (blocks backup if disk falls below floor)', '1-click-migration'); ?></div>
                        </div>
                    </div>

                    <label class="ocm-local-toggle ocm-local-telemetry-toggle">
                        <input type="checkbox" id="ocm-local-telemetry-enabled"<?php echo $telemetry_opted_in ? ' checked' : ''; ?>>
                        <span><?php esc_html_e('Share anonymous Local Backups usage & crash reports to help improve the plugin', '1-click-migration'); ?></span>
                    </label>
                    <div class="ocm-field-help ocm-local-telemetry-note"><?php
                        /* translators: %s: the web address the reports are sent to */
                        echo esc_html(sprintf(__('Optional and off by default. When this is on, the plugin sends a short anonymous report to %s: once when you turn it on, each time a local backup or restore finishes or fails, when you change the schedule, and if the plugin crashes during a local backup or restore.', '1-click-migration'), $telemetry_url));
                        echo ' ';
                        esc_html_e('Sent: an anonymous, hashed install ID, whether the backup or restore worked, the selected parts, a size range, how long it took, whether a schedule is on and how often it runs, your WordPress, PHP and plugin versions, a short error code, and for crashes the plugin file and line number.', '1-click-migration');
                        echo ' ';
                        esc_html_e('Not sent: your site address, email addresses, error messages or backup contents. Your site\'s IP address reaches our server, as it does with any web request, and is not stored with the reports.', '1-click-migration');
                        echo ' ';
                        esc_html_e('To stop, untick this box and click Save settings.', '1-click-migration');
                    ?></div>

                    <div class="ocm-local-sched-meta" id="ocm-local-sched-meta">
                        <span id="ocm-local-next-run">
                            <?php echo !empty($schedule['enabled']) && $next_formatted !== ''
                                ? esc_html__('Next run:', '1-click-migration') . ' ' . esc_html($next_formatted)
                                : esc_html__('Schedule is disabled.', '1-click-migration'); ?>
                        </span>
                        <?php if (!empty($status['last_run_at'])):
                            $last_run_when = $has_local ? OCM_Local_Scheduler::format_timestamp($status['last_run_at']) : '';
                            $last_run_label = isset($outcome_labels[$status['last_outcome']]) ? $outcome_labels[$status['last_outcome']] : '';
                            ?>
                            <span id="ocm-local-last-run">
                                &middot; <?php
                                if ($last_run_label !== '') {
                                    /* translators: 1: how the last scheduled backup ended, e.g. Completed, 2: date and time */
                                    echo esc_html(sprintf(__('Last run (%1$s): %2$s', '1-click-migration'), $last_run_label, $last_run_when));
                                } else {
                                    /* translators: %s: date and time of the last scheduled backup */
                                    echo esc_html(sprintf(__('Last run: %s', '1-click-migration'), $last_run_when));
                                }
                                ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="ocm-local-form-actions ocm-local-settings-actions">
                        <button type="button" class="ocm-btn ocm-btn-dark" id="ocm-local-save-settings"><?php esc_html_e('Save settings', '1-click-migration'); ?></button>
                        <span class="ocm-local-settings-msg" id="ocm-local-settings-msg" role="status"></span>
                    </div>
                </section>
            </div>

            <!-- Local progress strip -->
            <section class="ocm-card ocm-progress-card" id="ocm-local-progress-card" aria-label="<?php esc_attr_e('Local Progress', '1-click-migration'); ?>">
                <div class="ocm-pg-label">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                    <span><?php esc_html_e('Local Progress', '1-click-migration'); ?></span>
                    <span class="ocm-pg-pct" id="ocm-local-pg-pct"><?php echo esc_html($local_progress['value']); ?></span>
                </div>
                <div>
                    <div class="ocm-pg-bar"><div class="ocm-pg-fill<?php echo !empty($local_progress['active']) ? ' running' : (!empty($local_progress['completed']) ? ' ocm-pg-done' : (!empty($local_progress['failed']) ? ' ocm-pg-failed' : '')); ?>" id="ocm-local-pg-fill" style="width:<?php echo esc_attr($local_progress['value']); ?>"></div></div>
                    <div class="ocm-pg-status" id="ocm-local-pg-status">
                        <?php echo $show_local_progress ? esc_html($local_progress['text']) : esc_html__('Idle — ready to run a local backup or restore.', '1-click-migration'); ?>
                    </div>
                    <div class="ocm-pg-extras">
                        <span class="ocm-info error<?php echo !empty($local_progress['failed']) ? ' show' : ''; ?>" id="ocm-local-pg-error"><?php if (!empty($local_progress['failed'])) { echo esc_html($local_progress['text']); } ?></span>
                        <span class="ocm-info ocm-local-pg-notice" id="ocm-local-pg-notice" role="status"></span>
                    </div>
                </div>
                <div class="ocm-pg-actions">
                    <button type="button" class="ocm-btn ocm-btn-ghost" id="ocm-local-btn-stop">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 4 3 10 9 10"/></svg>
                        <?php esc_html_e('Stop & Reset', '1-click-migration'); ?>
                    </button>
                </div>
            </section>

            <!-- Saved local backups -->
            <section class="ocm-card ocm-card-pad ocm-local-backups-card" id="ocm-local-backups-card" aria-label="<?php esc_attr_e('Saved Local Backups', '1-click-migration'); ?>">
                <div class="ocm-h-stack ocm-card-headstrip">
                    <span class="ocm-card-title"><?php esc_html_e('Saved Local Backups', '1-click-migration'); ?></span>
                    <span class="ocm-mini-hint"><?php esc_html_e('Restore full backup or choose specific parts', '1-click-migration'); ?></span>
                </div>
                <p class="ocm-local-action-msg" id="ocm-local-backups-msg" role="alert" hidden></p>
                <div id="ocm-local-backups-list">
                    <?php if (empty($backups)): ?>
                        <p class="ocm-local-empty"><?php esc_html_e('No local backups saved yet. Create one above or enable the schedule.', '1-click-migration'); ?></p>
                    <?php else: ?>
                        <div class="ocm-local-table-wrap">
                            <table class="ocm-local-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Date & Time', '1-click-migration'); ?></th>
                                        <th><?php esc_html_e('Type / Label', '1-click-migration'); ?></th>
                                        <th><?php esc_html_e('Included Parts', '1-click-migration'); ?></th>
                                        <th><?php esc_html_e('Size', '1-click-migration'); ?></th>
                                        <th><?php esc_html_e('Actions', '1-click-migration'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backups as $b):
                                        $b_parts = isset($b['parts']) && is_array($b['parts']) ? array_keys($b['parts']) : array();
                                        $b_json = function_exists('wp_json_encode') ? wp_json_encode($b_parts) : json_encode($b_parts);
                                        $b_date = !empty($b['created_gmt']) && $has_local ? OCM_Local_Scheduler::format_timestamp($b['created_gmt']) : (isset($b['created_at']) ? $b['created_at'] : $b['id']);
                                        ?>
                                        <tr data-backup-id="<?php echo esc_attr($b['id']); ?>">
                                            <td>
                                                <strong><?php echo esc_html($b_date); ?></strong>
                                                <div class="ocm-local-id-sub"><code><?php echo esc_html($b['id']); ?></code></div>
                                            </td>
                                            <td>
                                                <span class="ocm-trigger-badge ocm-trigger-<?php echo esc_attr($b['trigger']); ?>"><?php echo $b['trigger'] === 'scheduled' ? esc_html__('Scheduled', '1-click-migration') : esc_html__('Manual', '1-click-migration'); ?></span>
                                                <?php if (!empty($b['label'])): ?>
                                                    <span class="ocm-local-row-label"><?php echo esc_html($b['label']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="ocm-part-pills">
                                                    <?php foreach ($b_parts as $bp): ?>
                                                        <span class="ocm-part-pill"><?php echo esc_html(isset($part_labels[$bp]) ? $part_labels[$bp] : strtoupper($bp)); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td><?php echo esc_html($b['total_readable']); ?></td>
                                            <td class="ocm-local-row-actions">
                                                <button type="button" class="ocm-btn ocm-btn-primary ocm-btn-sm ocm-local-restore-open"
                                                    data-backup-id="<?php echo esc_attr($b['id']); ?>"
                                                    data-backup-date="<?php echo esc_attr($b_date); ?>"
                                                    data-backup-label="<?php echo esc_attr($b['label']); ?>"
                                                    data-backup-size="<?php echo esc_attr($b['total_readable']); ?>"
                                                    data-backup-parts="<?php echo esc_attr((string) $b_json); ?>"><?php esc_html_e('Restore', '1-click-migration'); ?></button>
                                                <?php if (!empty($b['has_log'])): ?>
                                                    <button type="button" class="ocm-btn ocm-btn-ghost ocm-btn-sm ocm-local-view-backup-log"
                                                        data-backup-id="<?php echo esc_attr($b['id']); ?>"><?php esc_html_e('Log', '1-click-migration'); ?></button>
                                                <?php endif; ?>
                                                <button type="button" class="ocm-btn ocm-btn-ghost ocm-btn-sm ocm-local-delete-btn"
                                                    data-backup-id="<?php echo esc_attr($b['id']); ?>"><?php esc_html_e('Delete', '1-click-migration'); ?></button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Activity History (backups + restores) -->
            <section class="ocm-card ocm-card-pad ocm-local-activity-card" id="ocm-local-activity-card" aria-label="<?php esc_attr_e('Activity History', '1-click-migration'); ?>">
                <div class="ocm-h-stack ocm-card-headstrip">
                    <span class="ocm-card-title"><?php esc_html_e('Activity History', '1-click-migration'); ?></span>
                    <button type="button" class="ocm-btn ocm-btn-ghost ocm-btn-sm" id="ocm-local-clear-activity"<?php echo empty($activity) ? ' hidden' : ''; ?>><?php esc_html_e('Clear history', '1-click-migration'); ?></button>
                </div>
                <p class="ocm-local-action-msg" id="ocm-local-activity-msg" role="alert" hidden></p>
                <div id="ocm-local-activity-list">
                    <?php if (empty($activity)): ?>
                        <p class="ocm-local-empty"><?php esc_html_e('No local backup or restore activity recorded yet.', '1-click-migration'); ?></p>
                    <?php else: ?>
                        <div class="ocm-local-table-wrap">
                            <table class="ocm-local-table ocm-local-activity-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e('Date & Time', '1-click-migration'); ?></th>
                                        <th><?php esc_html_e('Operation', '1-click-migration'); ?></th>
                                        <th><?php esc_html_e('Parts', '1-click-migration'); ?></th>
                                        <th><?php esc_html_e('Status', '1-click-migration'); ?></th>
                                        <th><?php esc_html_e('Details', '1-click-migration'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($activity as $act):
                                        $act_type = isset($act['type']) && $act['type'] === 'restore' ? 'restore' : 'backup';
                                        $act_trigger = isset($act['trigger']) && $act['trigger'] === 'scheduled' ? 'scheduled' : 'manual';
                                        $act_status = isset($act['status']) ? (string) $act['status'] : 'completed';
                                        $act_status_label = isset($status_labels[$act_status]) ? $status_labels[$act_status] : ucfirst($act_status);
                                        $act_parts = isset($act['parts']) && is_array($act['parts']) ? $act['parts'] : array();
                                        $act_meta_bits = array();
                                        if (!empty($act['size_readable'])) {
                                            $act_meta_bits[] = $act['size_readable'];
                                        }
                                        if (!empty($act['duration_readable'])) {
                                            $act_meta_bits[] = $act['duration_readable'];
                                        }
                                        if (!empty($act['backup_id'])) {
                                            $act_meta_bits[] = $act['backup_id'];
                                        }
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo esc_html(isset($act['time_formatted']) ? $act['time_formatted'] : ''); ?></strong>
                                                <?php if (!empty($act['duration_readable']) || !empty($act['size_readable'])): ?>
                                                    <div class="ocm-local-id-sub"><?php echo esc_html(implode(' · ', array_filter(array(isset($act['size_readable']) ? $act['size_readable'] : '', isset($act['duration_readable']) ? $act['duration_readable'] : '')))); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="ocm-op-badge ocm-op-<?php echo esc_attr($act_type); ?>"><?php echo $act_type === 'restore' ? esc_html__('Restore', '1-click-migration') : esc_html__('Backup', '1-click-migration'); ?></span>
                                                <?php if ($act_type === 'backup'): ?>
                                                    <span class="ocm-trigger-badge ocm-trigger-<?php echo esc_attr($act_trigger); ?>"><?php echo $act_trigger === 'scheduled' ? esc_html__('Scheduled', '1-click-migration') : esc_html__('Manual', '1-click-migration'); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($act['label'])): ?>
                                                    <span class="ocm-local-row-label"><?php echo esc_html($act['label']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="ocm-part-pills">
                                                    <?php foreach ($act_parts as $ap): ?>
                                                        <span class="ocm-part-pill"><?php echo esc_html(isset($part_labels[$ap]) ? $part_labels[$ap] : strtoupper($ap)); ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </td>
                                            <td><span class="ocm-status-badge ocm-status-<?php echo esc_attr($act_status); ?>"><?php echo esc_html($act_status_label); ?></span></td>
                                            <td class="ocm-activity-details">
                                                <?php if (!empty($act['message'])): ?>
                                                    <div><?php echo esc_html($act['message']); ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($act['backup_id'])): ?>
                                                    <div class="ocm-local-id-sub"><code><?php echo esc_html($act['backup_id']); ?></code></div>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Collapsible In-Page Local Log Viewer -->
            <section class="ocm-card ocm-card-pad ocm-local-log-card" id="ocm-local-log-card" aria-label="<?php esc_attr_e('Local Backup & Restore Log', '1-click-migration'); ?>">
                <button type="button" class="ocm-local-log-toggle" id="ocm-local-log-toggle" aria-expanded="false" aria-controls="ocm-local-log-body">
                    <span class="ocm-h-stack">
                        <span class="ocm-card-title"><?php esc_html_e('Local Backup & Restore Log', '1-click-migration'); ?></span>
                        <span class="ocm-mini-hint" id="ocm-local-log-meta"><?php echo $local_log_exists && $local_log_size !== '' ? esc_html($local_log_size) : esc_html__('Idle', '1-click-migration'); ?></span>
                    </span>
                    <svg class="ocm-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                </button>
                <div class="ocm-local-log-body" id="ocm-local-log-body" hidden>
                    <div class="ocm-local-log-toolbar">
                        <div class="ocm-local-log-actions">
                            <button type="button" class="ocm-btn ocm-btn-ghost ocm-btn-sm" id="ocm-local-log-refresh"><?php esc_html_e('Refresh', '1-click-migration'); ?></button>
                            <button type="button" class="ocm-btn ocm-btn-ghost ocm-btn-sm" id="ocm-local-log-copy"><?php esc_html_e('Copy', '1-click-migration'); ?></button>
                            <button type="button" class="ocm-btn ocm-btn-ghost ocm-btn-sm" id="ocm-local-log-clear"><?php esc_html_e('Clear log', '1-click-migration'); ?></button>
                            <a class="ocm-btn ocm-btn-ghost ocm-btn-sm" id="ocm-local-log-download-btn" href="<?php echo esc_url($local_log_url); ?>" target="_blank"<?php echo $local_log_exists ? '' : ' hidden'; ?>><?php esc_html_e('Download full log', '1-click-migration'); ?></a>
                        </div>
                        <span class="ocm-mini-hint" id="ocm-local-log-note"></span>
                    </div>
                    <p class="ocm-local-action-msg" id="ocm-local-log-msg" role="alert" hidden></p>
                    <pre class="ocm-local-log-pre" id="ocm-local-log-content"><?php esc_html_e('No local log entries yet.', '1-click-migration'); ?></pre>
                </div>
            </section>

            <!-- Local Restore Modal -->
            <div id="ocm-local-restore-modal" class="ocm-modal" role="dialog" aria-modal="true" aria-labelledby="ocm-local-restore-title" hidden>
                <div class="ocm-modal-backdrop" data-ocm-local-modal-close></div>
                <div class="ocm-modal-card ocm-local-modal-card">
                    <button type="button" class="ocm-modal-close" data-ocm-local-modal-close aria-label="<?php esc_attr_e('Close', '1-click-migration'); ?>">&times;</button>
                    <h2 id="ocm-local-restore-title" class="ocm-modal-title"><?php esc_html_e('Restore Local Backup', '1-click-migration'); ?></h2>
                    <p class="ocm-modal-body" id="ocm-local-restore-summary"></p>
                    <p class="ocm-modal-body"><?php esc_html_e('Select which components from this backup to restore. The database restores safely through temporary staging tables.', '1-click-migration'); ?></p>
                    <div class="ocm-local-restore-parts" id="ocm-local-restore-parts"></div>
                    <p class="ocm-modal-body ocm-local-restore-caution" id="ocm-local-restore-caution"><?php esc_html_e('The parts you select on your live site are replaced with the copy from this backup. Anything changed since the backup was made will be lost. If you restore the database, you may have to log in again.', '1-click-migration'); ?></p>
                    <p class="ocm-exclusion-warning" id="ocm-local-restore-warning" style="display:none;"><?php esc_html_e('Please select at least one component.', '1-click-migration'); ?></p>
                    <div class="ocm-modal-actions">
                        <button type="button" class="ocm-btn ocm-btn-primary" id="ocm-local-restore-confirm"><?php esc_html_e('Start restore', '1-click-migration'); ?></button>
                        <button type="button" class="ocm-btn ocm-btn-ghost" data-ocm-local-modal-close><?php esc_html_e('Cancel', '1-click-migration'); ?></button>
                    </div>
                </div>
            </div>

            <!-- Per-Backup Log Modal -->
            <div id="ocm-local-log-modal" class="ocm-modal" role="dialog" aria-modal="true" aria-labelledby="ocm-local-log-modal-title" hidden>
                <div class="ocm-modal-backdrop" data-ocm-local-log-modal-close></div>
                <div class="ocm-modal-card ocm-local-modal-card ocm-local-log-modal-card">
                    <button type="button" class="ocm-modal-close" data-ocm-local-log-modal-close aria-label="<?php esc_attr_e('Close', '1-click-migration'); ?>">&times;</button>
                    <h2 id="ocm-local-log-modal-title" class="ocm-modal-title"><?php esc_html_e('Backup Log', '1-click-migration'); ?></h2>
                    <pre class="ocm-local-log-pre ocm-local-log-pre-modal" id="ocm-local-log-modal-content"></pre>
                    <div class="ocm-modal-actions">
                        <button type="button" class="ocm-btn ocm-btn-primary" id="ocm-local-log-modal-copy"><?php esc_html_e('Copy', '1-click-migration'); ?></button>
                        <button type="button" class="ocm-btn ocm-btn-ghost" data-ocm-local-log-modal-close><?php esc_html_e('Close', '1-click-migration'); ?></button>
                    </div>
                </div>
            </div>
            <!-- One-Time Telemetry Opt-In Modal -->
            <div id="ocm-local-telemetry-modal" class="ocm-modal" role="dialog" aria-modal="true" aria-labelledby="ocm-local-telemetry-title" hidden>
                <div class="ocm-modal-backdrop" data-ocm-local-telemetry-decline></div>
                <div class="ocm-modal-card ocm-local-modal-card">
                    <button type="button" class="ocm-modal-close" data-ocm-local-telemetry-decline aria-label="<?php esc_attr_e('Close', '1-click-migration'); ?>">&times;</button>
                    <h2 id="ocm-local-telemetry-title" class="ocm-modal-title"><?php esc_html_e('Help improve Local Backups?', '1-click-migration'); ?></h2>
                    <p class="ocm-modal-body"><?php esc_html_e('Send an anonymous report when a local backup or restore finishes, fails or crashes. Never your site address, emails or backup contents. Off unless you allow it.', '1-click-migration'); ?></p>
                    <p class="ocm-modal-body"><?php esc_html_e('You can turn this off anytime under Schedule & Storage settings.', '1-click-migration'); ?></p>
                    <div class="ocm-modal-actions">
                        <button type="button" class="ocm-btn ocm-btn-primary" id="ocm-local-telemetry-allow"><?php esc_html_e('Allow & Continue', '1-click-migration'); ?></button>
                        <button type="button" class="ocm-btn ocm-btn-ghost" id="ocm-local-telemetry-decline"><?php esc_html_e('No thanks', '1-click-migration'); ?></button>
                    </div>
                    <details class="ocm-local-telemetry-details">
                        <summary><?php esc_html_e('What is sent', '1-click-migration'); ?></summary>
                        <ul class="ocm-local-telemetry-list">
                            <li><?php esc_html_e('When: once when you turn this on, each time a local backup or restore finishes or fails, when you change the backup schedule, and if the plugin crashes during a local backup or restore.', '1-click-migration'); ?></li>
                            <li><?php esc_html_e('What: a hashed install ID, whether the backup or restore worked, the parts, a size range, how long it took, whether a schedule is on and how often it runs, your WordPress, PHP and plugin versions, a short error code, and for crashes the plugin file and line.', '1-click-migration'); ?></li>
                            <li><?php
                                /* translators: %s: the web address the reports are sent to */
                                echo esc_html(sprintf(__('Where: %s. Your site\'s IP address reaches our server, as it does with any web request, and is not stored with the reports.', '1-click-migration'), $telemetry_url));
                            ?></li>
                        </ul>
                    </details>
                </div>
            </div>
        </div><!-- /#ocm-mode-local -->
        <?php
    }

    public static function is_skipped_notice($text, $key)
    {
        return strpos($text, 'Notice: File ' . $key . ' was skipped due to timeout') !== false;
    }

    public static function is_not_found_notice($text, $key)
    {
        return strpos($text, 'Notice: file ' . $key . '.zip.crypt not found') !== false;
    }
}
