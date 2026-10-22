=== 1-Click WordPress Migration Plugin – Backup, Migrate & Clone ===
Contributors: 1clickmigration
Tags: backup, migrate, migration, restore, clone
Requires at least: 5.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.2.3
Author URI: https://1clickmigration.com
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Free one-click WordPress migration plugin with no 4GB cap. Clone, transfer or move a site to a new host or domain with an emailed restore code.

== Description ==

### Back up, migrate and clone a WordPress site ###

1-Click Migration backs up your WordPress website and emails you an 8-character restore code. Paste that code into a new host, a new domain or a second install, and the site is restored there. Site addresses are updated throughout the database, serialized data included. Your live site stays online while the backup runs. It's free, with no account or license key.

https://vimeo.com/1236211963

**What you can do with it**

* **Move to a new host.** [Transfer a website between any two hosts](https://1clickmigration.com/migrate-wordpress-to-new-host/) without downloading or uploading backup files yourself.
* **Change your domain name.** [Copy a WordPress site to a new domain](https://1clickmigration.com/change-wordpress-domain-name/), and the old address is replaced everywhere in the database, including inside serialized options and widgets.
* **Hand a site over to a client.** Move WordPress to the client's own hosting account. The site transfer takes one backup and one restore.
* **Go from localhost or staging to live.** [Push staging to live, or move WordPress from localhost to a server](https://1clickmigration.com/move-wordpress-localhost-to-live/). Back up the staging or local site and restore it on the live server.
* **Clone or duplicate a site.** Duplicate a WordPress site for staging or testing by restoring its backup on a second install.
* **Move large sites.** There is no 4GB zip cap. Back up and restore the database, plugins, themes and uploads together, or one part at a time.

Use it for any WordPress site migration: a hosting migration to a new provider, a server migration within the same host, a domain migration, or a move from localhost to live. You can move WordPress to another server, host or domain, and the migration itself needs no FTP, cPanel or phpMyAdmin, just a WordPress login on each site. It's a free, automatic migration tool for a complete website migration, from the database to the uploads folder.

**Built to fail safely**

* The database is imported into temporary tables and checked before it replaces anything. If a check fails, the restore stops, says why, and leaves your site as it was.
* Before the restore starts, the plugin checks your code and lists other domains found in the backup, such as an old domain or a CDN, so you choose which ones to rewrite.
* Free disk space is checked before files are unpacked.
* Long backups and restores run in short steps and pick up where they left off, so hosts with strict PHP time limits are fine.

**Private by default**

* Every part of the backup is AES-256 encrypted on your server before it's uploaded.
* Backups are stored on Amazon S3 and deleted after 48 hours.
* Only someone with the restore code can restore the backup, and we email the code only to the address you enter.

Learn more at [1clickmigration.com](https://1clickmigration.com).

== How it works ==

**On the site you're moving from**

1. Remove themes, plugins and media you don't need. A smaller site backs up faster.
2. Deactivate every plugin except 1-Click Migration.
3. Go to **Tools → One Click Migration**, enter your email and click **Start backup**. Most backups finish in 10–30 minutes.
4. Keep the email with your 8-character restore code. It's the only way to restore the backup.

**On the site you're moving to**

1. Install WordPress and 1-Click Migration on the new host or domain.
2. Delete any other plugins the host pre-installed.
3. Paste the restore code, click **Check backup & restore**, review any extra site addresses, then start the restore.
4. Log in with the usernames and passwords from the original site.

If a large site fails to back up or restore in one go, click **Stop & Reset** and use **Advanced options** to move one part at a time, with the database last. Each partial backup gets its own restore code.

== Installation ==
1. Install 1-Click Migration from Plugins → Add New, or upload the `1-click-migration` folder to `/wp-content/plugins/`.
2. Activate it on the Plugins screen.
3. Go to Tools → One Click Migration to start a backup or a restore.

== Frequently Asked Questions ==

= How long does a migration take? =
Most backups and restores finish in 10–30 minutes. Large sites and slow hosts take longer.

= How long is my backup available? =
48 hours. After that the backup is deleted from storage and the restore code stops working, so run a new backup.

= I lost the restore code email. Can you resend it? =
No. We can't retrieve a lost code. Check your spam folder; if it isn't there, run the backup again to get a new code.

= Can I move only part of a site? =
Yes. Use Advanced options to back up or restore the database, plugins, themes and uploads separately. Each partial backup gets its own restore code.

= Will links and images work on the new domain? =
Yes. The restore replaces the old site address throughout the database, including inside serialized data. You choose whether other domains found in the backup are rewritten too.

= Is there a size limit? =
There's no 4GB zip cap, and [no 512MB import limit to fix or upload limit to bypass](https://1clickmigration.com/wordpress-migration-upload-limit/). You never upload a backup file through your browser, so there's no file size limit to increase in php.ini. Very large databases may need a higher PHP memory_limit on the source host. If a big site fails in one go, move it one part at a time.

= Does my site go down during a migration? =
The site you back up stays online, so the migration causes no downtime there. The site you restore onto shows a maintenance page while the restore runs.

= Can I use it to create a staging site? =
Yes. Restore the backup on a separate WordPress install, such as a staging subdomain. The copy uses the staging site's address.

= Can I migrate a WooCommerce store? =
Yes. Products, orders and customers are in the database, so [a WooCommerce store moves like any other site](https://1clickmigration.com/migrate-woocommerce-store/). Orders placed on the old store after the backup starts may not be in it, so move the store at a quiet time or pause orders until the new site is live.

= Does it work with WordPress Multisite? =
Not yet. It supports single-site installs.

= Can I move a local site to a live server? =
Yes. Back up the local site, then restore it on the live server with the restore code. The local site needs an internet connection to upload the backup.

= Which hosts does it work with? =
Any host that runs WordPress on PHP 7.4 or later, including Bluehost, SiteGround, Hostinger, GoDaddy, HostGator, DreamHost, Namecheap, IONOS, A2 Hosting, InMotion Hosting, GreenGeeks, ScalaHosting, Liquid Web, Cloudways, WP Engine, Flywheel, Kinsta, Pressable and Pantheon, plus cloud servers on DigitalOcean, AWS Lightsail, Vultr and Linode, and local development sites. It works with cPanel, Plesk or no control panel at all. It also works on hosts that block background requests or cut long PHP requests short. On Pantheon, restore onto the Dev environment in SFTP mode, because code folders on Test and Live are read-only.

== Screenshots ==
1. Free one-click WordPress migration plugin. Back up a live site; it stays up while you migrate.
2. Selective WordPress migrate — choose database, plugins, themes, or uploads to back up.
3. Restore finished on the destination site. WordPress migration complete.
4. Enter the restore code on the destination site to finish your WordPress migration.

== Changelog ==
= 3.2.3 =
* **Stop & Reset**: Clicking Stop & Reset right after starting a backup could leave the backup running in the background, and it then reported "Backup failed". Stop & Reset now stops the backup straight away.

= 3.2.2 =
* **Interrupted restores**: When a restore picks up again after being interrupted just as a part of your backup finished downloading, it now keeps that download instead of fetching the same part again, so the restore finishes sooner.

= 3.2.1 =
* **Short service hiccups**: Starting a backup, uploading it, emailing the restore code and checking a restore code now try again on their own when the 1-Click Migration service is briefly busy or a connection drops, instead of stopping.
* **Large uploads**: If uploading a large backup in parts runs into a problem, the backup now picks up the upload where it left off instead of trying to send the whole file again in one go, which could never work for files over 5 GB.
* **Clearer restore message**: When the restore-code service is busy or can't be reached, the Restore tab now says so and that your backup is safe, instead of saying no backup was found for your code.

= 3.2.0 =
* **PHP 8.4 and 8.5**: The plugin no longer adds "Deprecated" notices to your error log, or to your pages when your site shows PHP errors. Its built-in libraries are updated to current versions, including security fixes in the library that encrypts your backups. Backups made with earlier versions still restore.

= 3.1.35 =
* **Slow connections**: A restore on a slow connection could sit on one download for up to 20 minutes until the host stopped it, or stop with "Restore failed while downloading" after a short pause in the connection. Downloads now pass the work on in short steps, a pause is simply tried again, and a slow download that keeps moving is no longer given up on after a set number of attempts.

= 3.1.34 =
* **Restores from a shared database**: If the old site's database also holds the tables of another WordPress site, such as a staging copy or a second install, the restore now brings over only your site's tables instead of stopping. If it can't tell which tables are yours, it stops before changing anything and says what to do.
* **Very large folders**: When the host keeps stopping a backup while it packs a big folder, the backup now waits for the packing already under way instead of starting it over, which could fill the disk. If it still can't finish, it explains how to make the folder smaller. Folders larger than 80 GB can now be uploaded.

= 3.1.33 =
* **Clearer guidance**: Starting a backup while another one runs now says so instead of waiting silently. If you get logged out during a restore, the page tells you before it reloads, and the logout tip now appears at the step where it happens. The restore-code reminder stays on the page after you reload it. If restored files can't be put in place, the page explains that the site stays in maintenance mode on purpose and how to leave it. The downloaded log starts with a short note on sharing it safely.

= 3.1.32 =
* **Backups recover on their own too**: If an unexpected error stops a backup partway, it now picks up again from where it stopped, up to two times, even with the plugin page closed. A backup that crashed no longer blocks Start backup until you click Stop & Reset.
* **Clearer messages**: The progress card explains problems in plain language instead of showing server errors, and a failed backup or restore still shows what happened and how to fix it after you reload the page. Hosts that need the page kept open now say so.
* **Fix**: A backup or restore could stall after handing its work to a new background request and wait forever. It now continues, and a run that truly can't continue ends with a clear message. Each backup's log also no longer includes the previous backup's log.

= 3.1.31 =
* **Restores recover on their own**: If an unexpected error stops a restore partway, it now picks up again from where it stopped, up to two times, and the site stays in maintenance mode while it does. It can also recover with the plugin page closed, and a download that had already finished before the interruption no longer makes it fail. In 3.1.30, a PHP time-limit error could take the site out of maintenance mode while the restore was still running.

= 3.1.30 =
* Fix: restores no longer crash when another plugin (such as WooCommerce) bundles an older background-processing library; a crashed restore no longer leaves the site in maintenance mode.
* Fix: a restore started right after one that crashed no longer waits forever with no progress. It clears what the crashed run left behind and starts, or tells you to click Stop & Reset if an earlier restore may still be running.

= 3.1.29 =
* **Minimum WordPress 5.3**: The plugin now lists WordPress 5.3 as its minimum. It's the first version that officially supports PHP 7.4, which the plugin already requires. Backup and restore work exactly as in 3.1.28.

= 3.1.28 =
* **Ready for translation**: The plugin page, its notices and the help shown with backup and restore progress can now be translated on translate.wordpress.org. Once a translation for your language is ready, WordPress installs it with the plugin. Progress steps come from the migration log and stay in English. The minimum WordPress version is now listed as 4.9, which the plugin already needed.

= 3.1.27 =
* **Listing update**: The description and FAQ now cover WooCommerce stores, more hosts and cloud servers, staging to live and domain name changes, and link to step-by-step guides on 1clickmigration.com. Backup and restore work exactly as in 3.1.26.

= 3.1.26 =
* **Listing update**: More hosts and a local-to-live question in the FAQ. Changelog entries before 3.1.20 moved to changelog.txt. Backup and restore work exactly as in 3.1.25.

= 3.1.25 =
* **Listing update**: New plugin title and short description on WordPress.org. Backup and restore work exactly as in 3.1.24.

= 3.1.24 =
* **Help links go to the support forum**: When a backup or restore fails, stops or stalls, the progress card now links to the WordPress.org support forum instead of the 1clickmigration.com contact form. It asks you to share your site's domain and never your restore code, because anyone who has the code can restore your backup. The help sections on the plugin page and the summary of an unfinished restore link there too.
* **Updated plugin listing**: New title, short description and description on WordPress.org. Two corrections: a restore needs only the restore code, and the plugin page is under Tools → One Click Migration.

= 3.1.23 =
* **Clearer progress card**: The progress card no longer shows internal log lines (such as "SYSLOG: Dispatching backup worker") or full server paths for routine steps. It now shows plain step names such as "Starting the backup worker" or "Archiving themes". Error messages still include the path, so you can fix permissions or quota.
* **No more false "taking longer than expected" notice**: Workers hand over to the next worker every 20 seconds. These planned hand-overs no longer show "Process is Restarting" or the "taking longer than expected" notice. The notice now appears only when a worker has actually stalled. It goes away once the run is moving again and is cleared when the run finishes. A new run no longer picks up the previous run's stall state.
* **The progress bar no longer jumps backwards**: During a restore the bar could drop back to 1% ("Starting restore…") on log lines it didn't recognize. After the database import it went 84% → 40% → 83% → 62%. It now keeps the current step and moves forward in the order the restore actually runs.

= 3.1.22 =
* **Quieter restore log**: Restores no longer log a warning for each temporary copy of a database file that was already removed.
* **No empty folders after backups**: A finished or failed backup now removes its empty work folder instead of leaving one behind for every run.

= 3.1.21 =
* **Restores survive strict server time limits**: Some hosts end a PHP request after 30 seconds, sooner than the plugin expected. Backup and restore workers now hand over to the next worker before that limit (20 seconds by default) without losing finished steps. If a worker is cut off during the database import anyway, the next one resumes the import where it stopped, instead of stopping with "the backup's main options table didn't pass the check".
* **Restore files kept out of the web root**: The restore used to unpack the database dump and the other parts into `wp-content/ocm_restore`. Servers that ignore `.htaccess` (such as nginx) could serve those files to anyone during a restore and after a failed one. Restore and backup work files now go to the private folder outside the web root. Where that isn't possible, they go to a randomly named, access-protected folder. A leftover `wp-content/ocm_restore` from an older version is removed.
* **Separate logs for sites in the same folder**: Sites installed side by side in one folder no longer share the migration log and the restore lock. Each site now has its own folder inside `.ocm-private`.
* **Every stopped restore cleans up**: Each restore that stops with "No changes were made" now clears the success and review prompts, the restore queue and any temporary tables. This includes a database part that can't be downloaded.
* **Restore right after a stopped one**: A restore started from the admin page soon after a failed or stopped run could stop at once with "the database could not be imported". The progress check no longer clears anything while a new restore starts.
* **No leftover restore workers**: After a restore fails or stops, no worker from that run keeps downloading, decrypting or logging, and a new restore no longer overlaps a worker from the previous run.
* **Source site's scheduled events stay behind**: A restore no longer copies the source site's scheduled 1 Click Migration events to the destination.
* **Faster logging**: The migration log is now sent to storage at most every 10 seconds and immediately when a run ends, instead of after every line. This made backups and restores slower.

= 3.1.20 =
* **Site addresses updated before the swap**: The restore now updates site addresses on the imported copy of the database, before it replaces the live database and files. If that step can't finish safely (for example a table with no primary key, a row too large for the PHP memory limit, or the memory limit itself being reached), the restore stops with no changes made to the site, instead of leaving it half-restored.
* **Restore never resumes the source's backup**: A backup's database is dumped while that backup is still running. After a restore, the destination could pick up the source site's unfinished backup and upload into the source's storage. The destination now keeps its own plugin state when the database is swapped in, and a backup can't run while a restore is in progress.
* **One restore worker at a time**: Long downloads and decryption now report progress, so the restore is no longer mistaken for stalled and started twice. A process lock also keeps a second restore worker from running alongside the first. A missing temporary file is no longer reported as a disk-quota problem.
* **Progress check after backup**: Checking progress the moment a backup finished could log a fatal `count()` error. It now exits quietly and never finishes the backup twice.
* **Crashes are always logged**: A fatal PHP error during a backup or restore is now always logged, including parse and compile errors and a repeat of an earlier crash. A crashed run no longer looks like it is still in progress.
* **Clearer storage and disk errors**: Running out of disk space while decrypting a part is reported at that step, not later as a corrupt archive. A failed upload of a large part no longer leaks the storage error into the progress screen, and an upload without a storage receipt is now logged.
* **No address prompt after a stopped restore**: A restore that stops with no changes made no longer offers to update extra site addresses.
* **Steadier progress bar**: Some internal log lines, such as low-memory notices and duplicate-worker exits, could send the progress bar back to 1% or show as the current step. They no longer do.

Older versions are listed in [changelog.txt](https://plugins.trac.wordpress.org/browser/1-click-migration/trunk/changelog.txt).

== Upgrade Notice ==

= 3.2.3 =
Stop & Reset now stops a backup that has just started, instead of letting it run on and report a failure. Includes all 3.2.2 changes.

= 3.2.2 =
An interrupted restore keeps a part that had already finished downloading instead of downloading it again. Includes all 3.2.1 changes.

= 3.2.1 =
Backups and restores ride out short service hiccups, large uploads resume in parts, and a busy service is no longer reported as a wrong restore code. Includes all 3.2.0 changes.

= 3.2.0 =
No more "Deprecated" notices on PHP 8.4 and 8.5, and security fixes in the library that encrypts your backups. Includes all 3.1.35 changes.

= 3.1.35 =
Restores on slow connections no longer stall on a single download for up to 20 minutes or stop after a short pause in the connection. Includes all 3.1.34 changes.

= 3.1.34 =
Restores now handle a database shared with another WordPress site, and backups of very large folders no longer start over each time the host stops them. Includes all 3.1.33 changes.

= 3.1.33 =
Clearer messages when a backup can't start, when you're logged out during a restore, and after a folder permission problem. Includes all 3.1.32 changes.

= 3.1.32 =
Backups now resume on their own after an unexpected error, messages are in plain language, and a stall after a background hand-off is fixed. Includes all 3.1.31 changes.

= 3.1.31 =
A restore interrupted by an unexpected error now resumes on its own and keeps the site in maintenance mode. Includes all 3.1.30 changes.

= 3.1.30 =
Fixes restores crashing on sites with WooCommerce active and leaving the site in maintenance mode. Includes all 3.1.29 changes.

= 3.1.29 =
Requires WordPress 5.3 or later. No changes to backup or restore. Includes all 3.1.28 changes.

= 3.1.28 =
The plugin's screens can now be translated on translate.wordpress.org. The minimum WordPress version is now 4.9. Includes all 3.1.27 changes.

= 3.1.27 =
Listing-only update: a WooCommerce FAQ, more hosts and links to migration guides. No changes to backup or restore. Includes all 3.1.26 changes.

= 3.1.26 =
Listing-only update: more hosts in the FAQ and a shorter changelog. No changes to backup or restore. Includes all 3.1.25 changes.

= 3.1.25 =
Listing-only update: a new title and short description on WordPress.org. No changes to backup or restore. Includes all 3.1.24 changes.

= 3.1.24 =
Help links now go to the WordPress.org support forum instead of a contact form, and the plugin listing has new copy. Includes all 3.1.23 changes.

= 3.1.23 =
A clearer progress card: no internal log lines or server paths, no false "taking longer than expected" notice, and a bar that no longer jumps backwards during a restore. Includes all 3.1.22 changes.

= 3.1.22 =
Maintenance release: a quieter restore log, and backups no longer leave empty work folders behind. Includes all 3.1.21 changes.

= 3.1.21 =
Backups and restores now work on hosts that end PHP requests after 30 seconds, and a cut-off database import resumes. Restore files no longer sit in the web root, sites in the same folder get separate logs, and every stopped restore cleans up after itself.

= 3.1.20 =
Includes all 3.1.19 changes. Restores now update site addresses before swapping in the database and files, and stop cleanly with no changes made if that step fails. A restore can no longer resume the source site's backup, and only one restore worker runs at a time.

= 3.1.19 =
New backups hex-encode BINARY/BLOB columns. Restores refuse broken binary data and stop early if the database part can't be unpacked. Empty uploads/themes/plugins folders back up cleanly. URL rewrites for .local/temp domains are more reliable.

= 3.1.6 =
Partial backups are now clearly flagged in the UI and restore-code email, stalled background workers auto-recover on hosts that block loopback requests, and disk usage during backup/restore is significantly reduced.

= 3.1.5 =
Improves large backup/restore reliability with multipart uploads, resumable downloads, and removes pre-3.0 legacy endpoint handling.

= 3.1.4 =
Reduces duplicate monitor emails by preventing old migration logs from collecting unrelated site PHP fatals after backup or restore completion.

= 3.1.3 =
Adds clearer recovery guidance when backup or restore fails on host configuration, quota, permissions, or database import issues.

= 3.1.2 =
Improves restore failure handling for disk quota/write errors and hardens restore diagnostics.

= 3.1.1 =
Adds backup worker diagnostics for hosts that block WordPress background loopback requests.

= 3.1 =
Restore reliability update for hosts without native MySQL CLI access. Please update now to keep backup and restore working.

== Reviews ==
If 1-Click Migration moved your site, please [leave a review](https://wordpress.org/support/plugin/1-click-migration/reviews/#new-post). It helps other people find the plugin and helps us improve it.
