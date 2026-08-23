<?php

namespace OCM;

class OCM_Admin_Page
{
    public static function add_admin_page()
    {
        add_management_page('One Click Migration', 'One Click Migration', 'manage_options', 'one-click-migration', array(__CLASS__, 'display_admin_menu'));
    }

    public static function display_admin_menu()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('You do not have sufficient permissions to access this page.'));
        }

        settings_errors('ocm_messages');

        $progress = OCM_Backup::get_progress();
        $hasError = false !== strpos($progress['text'], 'Error') && false === strpos($progress['text'], 'SYSLOG: "[PHP ERR][FATAL]');
        $ocm_action_start_backup  = get_option('ocm_action_start_backup');
        $ocm_action_start_restore = get_option('ocm_action_start_restore');
        $action_active            = $ocm_action_start_backup || $ocm_action_start_restore;
        $log_file_exists          = file_exists(OCM_DEBUG_LOG_FILE);
        $log_url                  = OCM_DEBUG_LOG_FILE_URL;
        $ocm_user_email_value     = (string) get_option('ocm_user_email', '');
        $start_action_value       = $ocm_action_start_backup ? 'backup' : ($ocm_action_start_restore ? 'restore' : '');
        $progress_text_safe       = wp_kses_post($progress['text']);
        $progress_completed       = isset($progress['value']) && $progress['value'] === '100%';
        $show_progress_text       = $log_file_exists && ($action_active || $progress_completed);

        $messages = [
            'endpoint_failure'      => 'We could not reach the backup/restore endpoints. Make sure you have the latest version of the plugin and try again.',
            'no_bucket'             => 'We could not find a backup for this restore code. If your backup was created more than 48 hours ago, you need to re-create it as it has been deleted.',
            'missing_username'      => 'Email parameter is missing. Please try again.',
            'missing_restore_code'  => 'Restore code is missing. Paste the 8-character code we emailed you after the backup.',
            'invalid_restore_code'  => 'Restore code is invalid or expired. Check the email we sent after your backup, or run a new backup.',
            'backup_in_progress'    => 'A backup is already in progress. Please wait for it to complete before starting a new one.',
            'insufficient_disk_space' => 'Not enough writable space to complete this operation. Free space or increase the hosting account disk quota, then try again. If your hosting panel shows enough space, ask the host to check account quota or inode limits.',
            'restore_write_failed'  => 'The restore workspace is not writable. Check permissions for wp-content and make sure the hosting account has available disk quota.',
            'restore_extract_failed' => 'A restore archive could not be extracted. Free disk quota, check wp-content permissions, then run the restore again.',
            'restore_db_failed'      => 'The database import failed. Ask the host to enable proc_open/MySQL CLI support, or create a fresh backup after cleaning invalid option data from third-party plugins.',
        ];
        $message_key  = isset($_GET['message']) ? sanitize_text_field($_GET['message']) : '';
        $top_message  = $message_key && isset($messages[$message_key]) ? $messages[$message_key] : '';

        $cancel_url  = wp_nonce_url(admin_url('admin-post.php?action=cancel_actions'), 'one_click_migration_cancel_action');
        $logo_url    = plugins_url('/images/one-click.png', dirname(dirname(__FILE__)));
        $version     = One_Click_Migration::get_version();
        ?>
        <div class="ocm-app">

            <header class="ocm-topbar">
                <div class="ocm-brand">
                    <img class="ocm-brand-logo" src="<?php echo esc_url($logo_url); ?>" alt="OneClick Migration"/>
                </div>
                <div class="ocm-topbar-right">
                    <span class="ocm-badge"><span class="ocm-dot"></span> Service ready</span>
                    <span>v<?php echo esc_html($version); ?></span>
                </div>
            </header>

            <main class="ocm-main">
                <div class="ocm-banner ocm-banner-warning" id="ocm-top-banner" style="display:none;"></div>

                <h1 class="ocm-h1">Backup &amp; Restore</h1>
                <p class="ocm-lede">Create a backup of your site or restore from a previous backup using a recovery code.</p>

                <?php if ($top_message): ?>
                    <div class="ocm-banner ocm-banner-error"><?php echo esc_html($top_message); ?></div>
                <?php endif; ?>

                <div class="ocm-grid">
                    <!-- LEFT: Help -->
                    <section class="ocm-card ocm-card-pad" aria-label="How it works">
                        <div class="ocm-h-stack ocm-card-headstrip">
                            <span class="ocm-card-title">How it works</span>
                            <span class="ocm-mini-hint">Tap to expand</span>
                        </div>
                        <div class="ocm-help-list">
                            <div class="ocm-help-item" data-ocm-help>
                                <button class="ocm-help-toggle" type="button">
                                    <span class="ocm-h-stack"><span class="ocm-num">B</span> How to back up?</span>
                                    <svg class="ocm-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                                <div class="ocm-help-body">
                                    <div class="ocm-step">
                                        <div class="ocm-step-title">1. Prepare your site</div>
                                        <p>Remove unwanted themes, plugins, and media. A database cleanup is recommended &mdash; many cleaner plugins can do this for you.</p>
                                        <p class="ocm-callout"><strong>Important:</strong> deactivate all plugins except 1 Click Migration before starting. Other active plugins may cause issues.</p>
                                    </div>
                                    <div class="ocm-step">
                                        <div class="ocm-step-title">2. Start the backup</div>
                                        <p>Enter your email and click <strong>Backup</strong>. The backup can take up to 30 minutes depending on site size.</p>
                                        <p>When it completes, you'll receive an email with an 8-character <strong>restore code</strong>. Save it &mdash; you'll need it on the destination site.</p>
                                    </div>
                                    <div class="ocm-step">
                                        <div class="ocm-step-title">3. Troubleshooting</div>
                                        <p>If the backup fails repeatedly, press <strong>Stop &amp; Reset</strong> and use <strong>Advanced options</strong> to back up one section at a time (e.g. exclude everything except the database for one run, then exclude everything except plugins for the next, and so on).</p>
                                        <p>Each run creates a <strong>separate backup with its own restore code</strong>. Save every email &mdash; on the destination site you'll need to run Restore once per code to put all the pieces back together.</p>
                                    </div>
                                    <div class="ocm-step">
                                        <div class="ocm-step-title">4. Important info</div>
                                        <p>The restore code is the only way to download your backup. <strong>Save the email we send you</strong> &mdash; we cannot retrieve a lost code.</p>
                                        <p>Backups expire after <strong>48 hours</strong>, after which they are deleted from our servers and the code stops working.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="ocm-help-item" data-ocm-help>
                                <button class="ocm-help-toggle" type="button">
                                    <span class="ocm-h-stack"><span class="ocm-num">R</span> How to restore?</span>
                                    <svg class="ocm-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
                                </button>
                                <div class="ocm-help-body">
                                    <div class="ocm-step">
                                        <div class="ocm-step-title">1. Prepare the new site</div>
                                        <p>Install WordPress and 1 Click Migration on your new host. Delete all other pre-installed plugins before starting the restore.</p>
                                    </div>
                                    <div class="ocm-step">
                                        <div class="ocm-step-title">2. Start the restore</div>
                                        <p>Paste the 8-character restore code from the email we sent you, then click <strong>Restore</strong>. The restore can take up to 30 minutes depending on size.</p>
                                    </div>
                                    <div class="ocm-step">
                                        <div class="ocm-step-title">3. Troubleshooting</div>
                                        <p>If your backup is large and restoring fails, press <strong>Stop &amp; Reset</strong>, then use <strong>Advanced options</strong> to restore one section at a time. Restore the database last.</p>
                                        <p>If you took multiple partial backups (one per section), you'll have a separate restore code for each. Run Restore once per code &mdash; suggested order: <strong>themes, plugins, uploads, database last</strong>.</p>
                                    </div>
                                    <div class="ocm-step">
                                        <div class="ocm-step-title">4. Important info</div>
                                        <p>Upon restore, this site's URL becomes the new primary domain and is updated throughout the database. Original WordPress users and passwords are also restored.</p>
                                        <p>Backup files are deleted after <strong>48 hours</strong>. If migration isn't finished by then, run the backup again.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- RIGHT: Action panel -->
                    <section class="ocm-card ocm-action-card" aria-label="Backup or restore">
                        <div class="ocm-action-head">
                            <div class="ocm-tabs" role="tablist">
                                <button class="ocm-tab <?php echo $start_action_value === 'restore' ? '' : 'active'; ?>" type="button" data-ocm-tab="backup" role="tab"
                                    aria-selected="<?php echo $start_action_value === 'restore' ? 'false' : 'true'; ?>">Backup</button>
                                <button class="ocm-tab <?php echo $start_action_value === 'restore' ? 'active' : ''; ?>" type="button" data-ocm-tab="restore" role="tab"
                                    aria-selected="<?php echo $start_action_value === 'restore' ? 'true' : 'false'; ?>">Restore</button>
                            </div>
                            <?php if ($log_file_exists): ?>
                                <a class="ocm-btn-link" href="<?php echo esc_url($log_url); ?>" target="_blank" title="Download full log file">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                                    Download log
                                </a>
                            <?php endif; ?>
                        </div>

                        <div class="ocm-action-body">
                            <label class="ocm-warn" id="ocm-plugins-warning" style="display:none;">
                                <input type="checkbox" id="ocm-plugins-ack-checkbox"/>
                                <span><strong>Warning:</strong> You have <strong><span id="ocm-other-plugins-count">0</span> other plugin(s)</strong> enabled. Running backup/restore with other plugins active is not advised. Check this box to proceed anyway.</span>
                            </label>

                            <!-- BACKUP PANE -->
                            <div class="ocm-pane <?php echo $start_action_value === 'restore' ? '' : 'active'; ?>" data-ocm-pane="backup">
                                <div class="ocm-field">
                                    <label class="ocm-label" for="ocm_user_email">Email <span class="ocm-hint">&mdash; for the restore code</span></label>
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
                                            Start backup
                                        </button>
                                    </div>
                                    <div class="ocm-field-help">We'll email you a restore code when the backup completes.</div>
                                </div>
                            </div>

                            <!-- RESTORE PANE -->
                            <div class="ocm-pane <?php echo $start_action_value === 'restore' ? 'active' : ''; ?>" data-ocm-pane="restore">
                                <div class="ocm-field">
                                    <label class="ocm-label" for="ocm_restore_code">Restore code <span class="ocm-hint">&mdash; sent to your email</span></label>
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
                                            Restore
                                        </button>
                                    </div>
                                    <div class="ocm-field-help">Format: 8 characters separated by a dash.</div>
                                </div>
                            </div>

                            <!-- ADVANCED -->
                            <div class="ocm-advanced">
                                <button class="ocm-adv-toggle" id="ocm-adv-toggle" type="button" aria-expanded="false">
                                    <span class="ocm-h-stack">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                                        Advanced options
                                    </span>
                                    <svg class="ocm-chev" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                                </button>
                                <div class="ocm-adv-grid" id="ocm-adv-grid">
                                    <label class="ocm-check"><input type="checkbox" data-ocm-exclude="plugins"><span>Exclude Plugins<small>Skip plugin files</small></span></label>
                                    <label class="ocm-check"><input type="checkbox" data-ocm-exclude="uploads"><span>Exclude Uploads<small>Skip media library</small></span></label>
                                    <label class="ocm-check"><input type="checkbox" data-ocm-exclude="themes"><span>Exclude Themes<small>Skip theme files</small></span></label>
                                    <label class="ocm-check"><input type="checkbox" data-ocm-exclude="db"><span>Exclude Database<small>Skip DB tables</small></span></label>
                                </div>
                                <p class="ocm-exclusion-warning" style="display:none;">Please leave at least one component selected.</p>
                            </div>
                        </div>
                    </section>
                </div>

                <!-- PROGRESS STRIP -->
                <section class="ocm-card ocm-progress-card" aria-label="Progress">
                    <div class="ocm-pg-label">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                        <span>Progress</span>
                        <span class="ocm-pg-pct" id="ocm-pg-pct"><?php echo esc_html($progress['value']); ?></span>
                    </div>
                    <div>
                        <div class="ocm-pg-bar"><div class="ocm-pg-fill <?php echo $action_active ? 'running' : ''; ?>" id="ocm-pg-fill" style="width:<?php echo esc_attr($progress['value']); ?>"></div></div>
                        <div class="ocm-pg-status" id="ocm-pg-status">
                            <?php echo $show_progress_text ? $progress_text_safe : 'Idle &mdash; waiting to start.'; ?>
                        </div>
                        <div class="ocm-pg-extras">
                            <span class="ocm-info notice"></span>
                            <span class="ocm-info error<?php echo $hasError ? ' show' : ''; ?>"><?php if ($hasError) echo $progress_text_safe; ?></span>
                        </div>
                    </div>
                    <div class="ocm-pg-actions">
                        <a class="ocm-btn ocm-btn-ghost" href="<?php echo esc_url($cancel_url); ?>" id="ocm-btn-stop">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"/><polyline points="3 4 3 10 9 10"/></svg>
                            Stop &amp; Reset
                        </a>
                    </div>
                </section>

                <div class="ocm-maintenance-mode-indicator" style="display:none;">
                    <strong>Site in maintenance mode</strong> &mdash; your website is currently being restored and is not accessible to visitors.
                </div>

                <div class="ocm-footnote">
                    <span>AES-256 encrypted before upload &middot; Backups expire 48 hours after creation</span>
                </div>
            </main>

            <!-- Hidden values consumed by admin-script.js -->
            <input type="hidden" id="ocmStartAction" value="<?php echo esc_attr($start_action_value); ?>"/>
            <a href="<?php echo esc_url(admin_url('admin-post.php?action=start_backup')); ?>" id="ocm-backup-href" hidden></a>
            <a href="<?php echo esc_url(admin_url('admin-post.php?action=start_restore')); ?>" id="ocm-restore-href" hidden></a>
            <a href="<?php echo esc_url(admin_url('admin-post.php?action=restart')); ?>" id="ocm-restart-href" class="restart-button" hidden></a>
        </div>
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
