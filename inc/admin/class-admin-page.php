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

        // show error/update messages
        settings_errors('ocm_messages');

        $progress = OCM_Backup::get_progress();
        $hasNotice = isset($progress['customNotice']) ? $progress['customNotice'] : false;
        $hasError = false !== strpos($progress['text'], 'Error') && false === strpos($progress['text'], 'SYSLOG: "[PHP ERR][FATAL]');
        $ocm_action_start_backup = get_option('ocm_action_start_backup');
        $ocm_action_start_restore = get_option('ocm_action_start_restore');
        $ocm_actions_class = $ocm_action_start_backup || $ocm_action_start_restore ? 'one-click-disabled' : '';

        $buttonBackupLabel = 'Backup';
        $buttonRestoreLabel = 'Restore';

        if (isset($_GET['message'])) { ?>
            <?php
            $messages = [
                'endpoint_failure' => 'We could not reach the backup/restore endpoints. Make sure you have the latest version of the plugin and try again.',
                'no_bucket' => 'We could not find a backup for this email and password combination. Please try again or if your backup was created more than 24 hours ago, you need to re-create it as it\'s been deleted.',
                'missing_username' => 'Username parameter is missing. Please try again.',
                'missing_password' => 'Password parameter is missing. Please try again.',
                'invalid_password' => 'Password is too short. Please use at least 4 characters.'
            ];

            $message_key = sanitize_text_field($_GET['message']);
            ?>

            <div class="notice error my-acf-notice is-dismissible">
                <p><?php echo esc_html($messages[$message_key] ?? '')?></p>
            </div>
        <?php }
        ?>
        <div class="wrap ocm-settings-page">
          <img class="ocm-settings-logo" src="<?php echo plugins_url('/images/one-click.png', dirname(dirname(__FILE__)))?>"/>

            <form action="options.php" method="post" class="ocm-settings-form">
                <?php settings_fields('one-click-migration');?>
                <div class="ocm-settings-section">
                  <?php do_settings_sections('one-click-migration'); ?>
                </div>

                <select id='selective-backup' multiple>
                  <span class="ocm-blue-label">Advanced Options</span>
                  <option value='plugins'>Exclude Plugins</option>
                  <option value='uploads'>Exclude Uploads</option>
                  <option value='themes'>Exclude Themes</option>
                  <option value='db'>Exclude Database</option>
                </select>
                <div>

                  <a href="<?php echo esc_url(admin_url('admin-post.php?action=start_backup')); ?>"
                     class="button button-primary button-large ocm-button backup-button <?php echo esc_html($ocm_actions_class) ?>"><?php echo esc_html($buttonBackupLabel) ?></a>

                  <a href="<?php echo esc_url(admin_url('admin-post.php?action=start_restore')); ?>"
                     class="button button-primary button-large ocm-button restore-button <?php echo esc_html($ocm_actions_class) ?>"><?php echo esc_html($buttonRestoreLabel) ?></a>

                   <a href="<?php echo esc_url(admin_url('admin-post.php?action=restart'));?>" style= "display:none;"
                                class="button button-primary button-large ocm-button restart-button">Restart</a>
                </div>
                
                <p class="ocm-exclusion-warning" style="display:none; color: #dc3232; margin-top: 10px; text-align: center;">
                    <em>Please select at least one component to backup / restore.</em>
                </p>

            </form>

            <?php if (file_exists(OCM_DEBUG_LOG_FILE)):
              $log_url = OCM_DEBUG_LOG_FILE_URL;
              $arr = [
                'br' => [],
                'p' => [],
                'strong' => [],
                'a' => [
                  'href' => [],
                  'target'=>[],
                  'class'=>[]
                  ]
                ];
            ?>
                <h2 class="progress-indicator">Progress</h2>
                <p class="ocm-restart-message"><strong>Timeout approached. The process will restart automatically in a few minutes. Click Resume button to restart the process manually</strong></p>
                <ul class="progress-row">
                  <li class="progress-col-left">
                    <div>
                      <div class="progress-bar-inner" style="width:<?php echo wp_kses( $progress['value'], $arr)?>"><?php echo wp_kses( $progress['value'], $arr ) ?></div>
                      <div id="ocmProgressBar" class="ocm-progress-bar">
                        <div class="progress-bar-color" style="width:0"></div>
                      </div>
                      <div id="ocmProgressBarUploadFile" class="ocm-progress-bar upload-file">
                          <div class="progress-bar-inner" style="width:0">0%</div>
                      </div>
                      <h2 class="ocm-progress-text-sec">
                        <span class="ocm-pt-icon"></span>
                        <span class="ocm-progress-text">

                              <?php
                              echo wp_kses($progress['text'], $arr); ?>

                        </span>
                      </h2>

                      <!-- Maintenance Mode Indicator -->
                      <div class="maintenance-mode-indicator" style="display: none; background: #ff9800; color: white; padding: 10px; margin: 10px 0; border-radius: 4px; text-align: center;">
                        <strong>🛠️ Site in Maintenance Mode</strong><br>
                        Your website is currently being restored and is not accessible to visitors.
                      </div>


                      <p class="ocm-info notice <?php if ($hasNotice) { echo esc_attr( 'show' ); } ?>">

                      </p>
                      <p class="ocm-info error <?php if ($hasError) { echo esc_attr( 'show' ); } ?>">
                          <span class="ocm-progress-notice"></span><span><?php if ($hasError) { echo wp_kses( $progress['text'], $arr ); } ?></span>
                      </p>
                      <div class="ocm-timer">
                        <h2 class="ocm-timer-text"><span class="ocm-timer-icon"></span><span class="ocm-decreasing-timer"></span><span class="ocm-sec-left"> Seconds left</span></h2>
                      </div>
                      <p class="ocm-db-skipped ocm-info <?php if (OCM_Admin_Page::is_skipped_notice($progress['text'], 'db')) { echo esc_attr( 'show' ); } ?>"><span class="ocm-progress-notice"></span>Notice: File db was skipped due to timeout</p>
                      <p class="ocm-themes-skipped ocm-info <?php if (OCM_Admin_Page::is_skipped_notice($progress['text'], 'themes')) { echo esc_attr( 'show' ); } ?>"><span class="ocm-progress-notice"></span>Notice: File themes was skipped due to timeout</p>
                      <p class="ocm-plugins-skipped ocm-info <?php if (OCM_Admin_Page::is_skipped_notice($progress['text'], 'plugins')) { echo esc_attr( 'show' ); } ?>"><span class="ocm-progress-notice"></span>Notice: File plugins was skipped due to timeout</p>
                      <p class="ocm-uploads-skipped ocm-info <?php if (OCM_Admin_Page::is_skipped_notice($progress['text'], 'uploads')) { echo esc_attr( 'show' ); } ?>"><span class="ocm-progress-notice"></span>Notice: File uploads was skipped due to timeout</p>
                      <p class="ocm-db-not-found ocm-info <?php if (OCM_Admin_Page::is_not_found_notice($progress['text'], 'db')) { echo esc_attr( 'show' ); } ?>"><span class="ocm-progress-notice"></span>Notice: File db.zip.crypt was not found on the remote server. Please try to back it up again.</p>
                      <p class="ocm-themes-not-found ocm-info <?php if (OCM_Admin_Page::is_not_found_notice($progress['text'], 'themes')) { echo esc_attr( 'show' ); } ?>"><span class="ocm-progress-notice"></span>Notice: File themes.zip.crypt was not found on the remote server. Please try to back it up again.</p>
                      <p class="ocm-plugins-not-found ocm-info <?php if (OCM_Admin_Page::is_not_found_notice($progress['text'], 'plugins')) { echo esc_attr( 'show' ); } ?>"><span class="ocm-progress-notice"></span>Notice: File plugins.zip.crypt was not found on the remote server. Please try to back it up again.</p>
                      <p class="ocm-uploads-not-found ocm-info <?php if (OCM_Admin_Page::is_not_found_notice($progress['text'], 'uploads')) { echo esc_attr( 'show' ); } ?>"><span class="ocm-progress-notice"></span>Notice: File uploads.zip.crypt was not found on the remote server. Please try to back it up again.</p>
                    </div>
                  </li>
                  <li class="progress-col-right">
                    <div>
                      <span class="ocm-rec-icon"></span>                      
                      <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=cancel_actions'), 'one_click_migration_cancel_action'); ?>" class="cancel-actions-button"></a>
                      <p><a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=cancel_actions'), 'one_click_migration_cancel_action'); ?>" class="stop-reset-text">Stop & Reset</a></p>
                      
                      
                    </div>
                  </li>
                </ul>

            <?php else: ?>
                <h2 class="progress-indicator">Progress:</h2>
                <div class="progress-bar-inner" style="width:0">0%</div>
                <div id="ocmProgressBar" class="ocm-progress-bar">
                <div class="progress-bar-color" style="width:0"></div>

                </div>

                <div id="ocmProgressBarUploadFile" class="ocm-progress-bar upload-file">
                    <div class="progress-bar-color" style="width:0"></div>
                </div>
                <h2>
                  <span class="ocm-pt-icon"></span>
                  <span class="ocm-progress-text">
                    Start a backup or a restore to see current progress here.Entire process runs in the background, independent of your browser activity.If you get logged
                    out during restore, log back in using your backup old WordPress credentials and refresh this page
                    for progress.
                  </span>
                </h2>

                <!-- Maintenance Mode Indicator -->
                <div class="maintenance-mode-indicator" style="display: none; background: #ff9800; color: white; padding: 10px; margin: 10px 0; border-radius: 4px; text-align: center;">
                  <strong>🛠️ Site in Maintenance Mode</strong><br>
                  Your website is currently being restored and is not accessible to visitors.
                </div>
                <h2 class="ocm-info notice"><p></p></h2>
                <h2 class="ocm-info error"><p></p></h2>
                
                <!-- Always show Stop & Reset button -->
                <div style="margin-top: 20px; text-align: center;">
                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=cancel_actions'), 'one_click_migration_cancel_action'); ?>" class="button button-secondary">Stop & Reset</a>
                </div>
            <?php endif; ?>
            <div class="ocm-settings-log-section">
              <span class="ocm-settings-log-icon"></span>
              <a target="_blank" href="<?php echo $log_url ?>" class="download-log-file alignright  button button-primary button-large">Download Full Log File</a>
            </div>
            <?php require_once dirname(dirname(__DIR__)) . '/templates/admin/payments-table.php' ;?>
        </div>

        <?php
    }

    /**
	 * Handle PayPal payment callback  to resume the restoration process
	 */
	public static function submit_paypal_payment(){
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to perform this action.');
        }
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'ocm_make_payment')) {
            wp_die('Security check failed. Please try again.');
        }

		$option_name = 'ocm_payment_status';
        update_option($option_name, 'payment_completed');
		OCM_Backup::complete_restore_after_payment();
		wp_send_json("Payment completed successfully..");
	}

  public static function is_skipped_notice($text, $key){
    $is_skipped_notice = false;
    $notice = 'Notice: File ' . $key .' was skipped due to timeout';

    if(strpos($text, $notice) !== false){
      $is_skipped_notice = true;
    }
    return $is_skipped_notice;
  }

  public static function is_not_found_notice($text, $key){
    $is_not_found_notice = false;
    $notice = 'Notice: file ' . $key . '.zip.crypt not found';
    if(strpos($text, $notice) !== false){
      $is_not_found_notice = true;
    }

    return $is_not_found_notice;
  }

}
