
<div id="ocm-instruction-box-accordion">
  <h3>How to Back Up?</h3>
  <div>
    <ul>
        <li>
          <h4>1. Prepare Your Site</h4>
          <p>Remove any unwanted themes, plugins, and media files. A database cleanup is also recommended &mdash; if you don't know how to do this manually, there are many cleaner plugins available.</p>
          <p><strong>Important:</strong> Deactivate all plugins except 1 Click Migration before starting. Running the backup with other plugins active may cause issues.</p>
        </li>
        <li>
          <h4>2. Start the Backup</h4>
          <p>Enter your email and click <strong>Backup</strong>. The backup can take up to 30 minutes depending on the size of your site.</p>
          <p>When the backup completes you'll receive an email with an 8-character <strong>restore code</strong>. Save it &mdash; you'll need it on the destination site to start the restore.</p>
        </li>
        <li>
          <h4>3. Troubleshooting</h4>
          <p>If the backup fails repeatedly, press <strong>Stop &amp; Reset</strong> and use the <strong>Advanced Options</strong> dropdown to back up one section at a time. Re-running with the same email creates a fresh backup and a fresh restore code.</p>
          <p>The timer below the progress bar shows your server's max execution time. We attempt to increase it automatically, and if a process runs out of time we try to restart it. In some cases the site may be too large for the plugin to complete. If so, try cleaning up more files or increasing your server's memory and execution time. If that is not possible, you may need to perform a manual migration.</p>
        </li>
        <li>
          <h4>4. Important Info</h4>
          <p>The restore code is the only way to download your backup. <strong>Save the email we send you</strong> &mdash; we cannot retrieve a lost code; you would need to run a fresh backup.</p>
          <p>Backups expire after <strong>48 hours</strong>, after which they are deleted from our servers and the restore code stops working.</p>
        </li>
      </ul>
  </div>

  <h3>How to Restore?</h3>
  <div>
    <ul>
        <li>
          <h4>1. Prepare the New Site</h4>
          <p>Install WordPress and 1 Click Migration on your new host. Delete all other pre-installed plugins before starting the restore.</p>
        </li>
        <li>
          <h4>2. Start the Restore</h4>
          <p>Enter the same email you used during backup, paste the 8-character restore code from the email we sent you, then click <strong>Restore</strong>. The restore can take up to 30 minutes depending on the size of your site.</p>
        </li>
        <li>
          <h4>3. Troubleshooting</h4>
         <p>If your backup is large and restoring fails, press <strong>Stop &amp; Reset</strong>, then use the <strong>Advanced Options</strong> dropdown to restore one section at a time. Restore the database last.</p>
        <p>If the restore does not work, enable <a href="https://wordpress.org/support/article/debugging-in-wordpress/" target="_blank">WordPress debug mode</a>, purge the database, and try again.</p>
        </li>
        <li>
          <h4>4. Important Info</h4>
          <p>Upon restore, <strong><?php echo esc_url(get_site_url()) ?></strong> will become your new primary domain and will be updated throughout the database.</p>
          <p>Your original WordPress users and passwords are also restored in this process.</p>
          <p>Backup files are deleted from our servers after <strong>48 hours</strong>. If you have not finished the migration by then, you will need to run the backup again.</p>
          <p>The small fee we charge helps cover hosting and development costs. Migration is a complex task due to the variety of WordPress configurations and hosting environments.</p>
          <p>If you run into issues, please contact us via <a target="_blank" href="https://1clickmigration.com/contact-us/">our Contact Form</a>. Include the email you used with the plugin so we can locate your log files. If we are unable to resolve your issue, we will refund your charge &mdash; guaranteed.</p>
        </li>
     </ul>
  </div>

</div>
