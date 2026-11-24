=== 1-Click WordPress Migration Plugin – Backup & Restore ===
Contributors: 1clickmigration
Tags: wordpress migration, migration plugin, wordpress backup, backup restore, scheduled backup
Requires at least: 5.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 4.0.1
Author URI: https://1clickmigration.com
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

WordPress migration plugin with FREE scheduled local backup & restore. One-click site migrations, success guaranteed, no size cap.

== Description ==

### 1-Click WordPress Migration Plugin & Free Local Backup ###

1-Click Migration is a WordPress migration plugin with free local backup & restore built in. Schedule automatic local backups (hourly, daily, weekly or monthly) and restore on the same server for free — no account, email or cloud upload required. When you're ready to move your WordPress website to a new host, a new domain or a second install, use Cloud Migration to transfer everything with an 8-character restore code while your live site stays online ($9.99, success guaranteed or a refund).

https://vimeo.com/1236211963

**Free Local Backup & Restore**

* **Automatic scheduled backups.** Run hourly, daily, weekly or monthly WordPress backups automatically in your site's timezone.
* **Manual on-demand backups.** Create a quick WordPress database backup or full site backup before updating plugins, themes or WordPress core.
* **Selective backup & restore.** Back up or restore any combination of your database, plugins, themes and uploads.
* **Safe 1-click local restore.** Imports the database into temporary staging tables and verifies core options before replacing your live site.
* **Automatic retention & disk-space guard.** Keep your last N backups (default 5, or unlimited) with automatic cleanup of old backups, plus a configurable minimum free-space guard so scheduled backups never fill your hosting disk.
* **Private local storage & activity log.** Stored as plain ZIP archives in a private folder outside your website's public files where the server allows it, otherwise in a folder with an unguessable name, both protected by `.htaccess`, `web.config` and `index.php`. Includes a full activity history and log viewer.

**Cloud Migration – Move, Clone & Transfer WordPress**

* **Move to a new host.** [Transfer a website between any two hosts](https://1clickmigration.com/migrate-wordpress-to-new-host/) without downloading or uploading backup files yourself.
* **Change your domain name.** [Copy a WordPress site to a new domain](https://1clickmigration.com/change-wordpress-domain-name/), and the old address is replaced everywhere in the database, including inside serialized options and widgets.
* **Hand a site over to a client.** Move WordPress to the client's own hosting account. The site transfer takes one backup and one restore.
* **Go from localhost or staging to live.** [Push staging to live, or move WordPress from localhost to a server](https://1clickmigration.com/move-wordpress-localhost-to-live/). Back up the staging or local site and restore it on the live server.
* **Clone or duplicate a site.** Duplicate a WordPress site for staging or testing by restoring its backup on a second install.
* **Move large sites.** There is no size cap. Back up and restore the database, plugins, themes and uploads together, or one part at a time.

Use this WordPress migration plugin for any WordPress migration, WP migration or WordPress site migration: a hosting migration to a new provider, a server migration within the same host, a domain migration, or a move from localhost to live. Move WordPress to another server, host or domain with no FTP, cPanel or phpMyAdmin — just a WordPress login on each site. It's an automatic migration tool and WP migration plugin for a complete website migration, from the database to the uploads folder.

**Pricing**

* **Local Backup & Restore — Free.** Unlimited manual and scheduled local backups, retention controls and same-site restores.
* **Cloud Migration — $9.99, success guaranteed or a refund.** One PayPal payment per migration before you restore on the new host or domain — covers retries and restoring on a second site.
* **Unlimited Cloud Migrations — $15 a month.** [Subscribe](https://1clickmigration.com/subscriptions/) with the email you use in the plugin; cancel anytime.
* **Fix or refund guarantee.** If a migration doesn't work, we'll fix it or refund you.

**Built to fail safely**

* The database imports into temporary tables and is checked before anything is replaced. If a check fails, the migration plugin stops, explains why, and leaves your site untouched.
* Before the restore starts, the plugin checks your code and lists other domains found in the backup (such as an old domain or CDN) so you choose which ones to rewrite.
* Available disk space is checked before files are unpacked.
* Long backups and restores run in short steps and pick up where they left off, so hosts with strict PHP time limits are fine.

**Private by default**

* Cloud backup parts are AES-256 encrypted on your server before upload, stored on Amazon S3, and deleted after 48 hours.
* Only someone with the restore code can restore a cloud backup, and we email the code only to the address you enter.
* Payments happen on PayPal's own page; the plugin never asks for card or payment details.

Questions before backing up, or running into a plugin issue? [Ask on the WordPress.org support forum](https://wordpress.org/support/plugin/1-click-migration/) and share your site's domain (never your restore code). Learn more at [1clickmigration.com](https://1clickmigration.com).

== How it works ==

**Local Backups (Free)**

1. Go to **Tools → One Click Migration** and switch to **Local Backups**.
2. Click **Create Local Backup** for an immediate backup, or enable a schedule (hourly, daily, weekly or monthly) with retention and free-space guard settings.
3. Click **Restore** next to any saved backup to restore the selected parts safely on the same site.

**Cloud Migration – On the site you're moving from**

1. Remove themes, plugins and media you don't need. A smaller site backs up faster.
2. Deactivate every plugin except 1-Click Migration.
3. Go to **Tools → One Click Migration**, enter your email and click **Start backup**. Most backups finish in 10–30 minutes.
4. Keep the email with your 8-character restore code — it's the only way to restore the backup.

**Cloud Migration – On the site you're moving to**

1. Install WordPress and the 1-Click Migration plugin on the new host or domain.
2. Delete any other plugins the host pre-installed.
3. Paste the restore code, click **Check backup & restore**, complete payment (subscribers skip this), review any extra site addresses, and start the restore.
4. Log in with the usernames and passwords from the original site.

If a large site fails in one go, click **Stop & Reset** and use **Advanced options** to move one part at a time, database last.

== Installation ==
1. Install 1-Click Migration from Plugins → Add New, or upload the `1-click-migration` folder to `/wp-content/plugins/`.
2. Activate the migration plugin on the Plugins screen.
3. Go to Tools → One Click Migration for Cloud Migration, or switch to Local Backups for free backups on this server.

== Frequently Asked Questions ==

= Are local backups and restores free? =
Yes. Manual and scheduled local backups (hourly, daily, weekly or monthly) and same-site restores are completely free, with no account or cloud upload required. You only pay for Cloud Migration to move a site to another host or domain ($9.99, or $15 a month unlimited).

= Can I schedule automatic daily or weekly WordPress backups? =
Yes. Under Local Backups, enable the schedule and choose hourly, daily, weekly or monthly backups, the time in your site's timezone, and which parts (database, plugins, themes, uploads) to include.

= Where are local backups stored, and can they fill up my disk? =
Local backups are saved as plain ZIP archives in a private folder outside your website's public files where the server allows it, otherwise in a folder with an unguessable name, both protected by `.htaccess`, `web.config` and `index.php`. Automatic retention pruning removes older backups past your "Keep last" limit, and a configurable free-space guard skips scheduled runs if hosting disk space gets low.

= How much does a WordPress migration cost, and how do I pay? =
Local backups and same-site restores are free. A Cloud Migration costs $9.99, success guaranteed or a refund. Pay once via PayPal or card on PayPal's own page (from the plugin or restore-code email) before you restore; one payment covers retries and restoring the same backup on a second site for 48 hours. If you move many sites, [$15 a month covers unlimited migrations](https://1clickmigration.com/subscriptions/), cancel anytime.

= What if my WordPress migration doesn't work? =
We'll fix it or refund you in full, no questions asked. For general plugin questions or backup errors, [ask on the support forum](https://wordpress.org/support/plugin/1-click-migration/) with your site's domain (never post your restore code).

= How long does a WordPress migration take? =
Most backups and restores finish in 10–30 minutes. Large sites and slow hosts take longer.

= How long is my backup available, and what if I lose the restore code? =
Backups are kept for 48 hours, then deleted. We can't retrieve a lost restore code — check spam, or run a new backup.

= Can I move only part of a site? =
Yes. Use Advanced options to back up or restore the database, plugins, themes and uploads separately. Each partial backup gets its own restore code.

= Will links and images work on the new domain? =
Yes. The migration plugin replaces the old site address throughout the database, including inside serialized data, and lets you choose whether other domains found in the backup are rewritten too.

= Is there a size limit? =
There's no size cap, and [no 512MB import limit to fix or upload limit to bypass](https://1clickmigration.com/wordpress-migration-upload-limit/). You never upload a backup file through your browser, so there's no file size limit to increase in php.ini. Very large databases may need a higher PHP memory_limit on the source host. If a big site fails in one go, move it one part at a time.

= Does my site go down during a migration? =
The site you back up stays online, so the migration causes no downtime there. The site you restore onto shows a maintenance page while the restore runs.

= Can I use this migration plugin to create a staging site? =
Yes. Restore the backup on a separate WordPress install, such as a staging subdomain. The copy uses the staging site's address.

= Can I migrate a WooCommerce store? =
Yes. Products, orders and customers are in the database, so [a WooCommerce store moves like any other site](https://1clickmigration.com/migrate-woocommerce-store/). Move the store at a quiet time or pause orders until the new site is live.

= Does it work with WordPress Multisite? =
Not yet. This WordPress migration plugin supports single-site installs.

= Can I move a local site to a live server? =
Yes. Back up the local site, then restore it on the live server with the restore code. The local site needs an internet connection to upload the backup.

= Which hosts does this WordPress migration plugin work with? =
Any host that runs WordPress on PHP 7.4 or later, including Bluehost, SiteGround, Hostinger, GoDaddy, HostGator, DreamHost, Namecheap, IONOS, A2 Hosting, InMotion Hosting, GreenGeeks, ScalaHosting, Liquid Web, Cloudways, WP Engine, Flywheel, Kinsta, Pressable and Pantheon, plus cloud servers on DigitalOcean, AWS Lightsail, Vultr and Linode, and local development sites. It works with cPanel, Plesk or no control panel at all. It also works on hosts that block background requests or cut long PHP requests short. On Pantheon, restore onto the Dev environment in SFTP mode, because code folders on Test and Live are read-only.

== Screenshots ==
1. Free local WordPress backups. Back up now, or schedule hourly, daily, weekly or monthly backups with retention and a free-space guard.
2. Saved local backups and activity history. Restore, view the log of, or delete any backup.
3. Restore a local backup in full, or choose the database, themes, plugins or uploads.
4. One-click WordPress migration plugin. Back up a live site and get a restore code by email; the site stays up while you migrate.
5. Enter the restore code on the destination site to finish your WordPress migration.

== Changelog ==
= 4.0.1 =
* **Local backup fixes**: Starting a second backup or a restore no longer shows the previous run's result and stops updating. A restore that keeps getting cut off at the same step now stops and says so, instead of leaving the site in maintenance mode. Scheduled backups start again after the plugin is deactivated and reactivated.
* **More reliable runs**: A backup that takes a while to finish is no longer reported as failed. Stopping a backup and starting a new one right away no longer cancels the new one. A start that was cut off no longer blocks later backups.
* **Plugins stay active**: Stopping a local restore from the Cloud Migration tab, or deactivating the plugin during one, no longer leaves your other plugins switched off. A restore that resumes while it is putting plugins back no longer removes plugins it already restored.
* **Private storage**: When WordPress is installed in a subfolder of the website, local backups now go to a folder with an unguessable name instead of one inside the website's public files. Backups saved by 4.0.0 are moved there automatically.

= 4.0.0 =
* **Free Local Backup & Restore**: Added free manual and scheduled local backups (hourly, daily, weekly or monthly in your site's timezone) with selective components (database, plugins, themes, uploads), automatic keep-last-N retention pruning, a configurable free-space guard, web-blocked private ZIP storage, safe staging-table restores, and an activity log viewer.
* **Listing**: Updated plugin title, short description, tags, description and FAQ to cover Free Local Backup & Restore alongside Cloud Migration.

= 3.3.12 =
* **Clearer guarantee wording**: The payment messages in the plugin now say it plainly: if the migration doesn't work, we'll fix it or refund you in full. For help, post in the support forum and we'll answer you there.

= 3.3.11 =
* **Faster help when a backup or restore stops**: The progress card now has a "Copy details for the forum" button. It copies your site's domain, the plugin, WordPress and PHP versions, and what the card says happened, so a support forum post starts with what we need to answer it. It never copies your restore code or email address.
* **Listing**: Shorter plugin title on WordPress.org.

= 3.3.10 =
* **Easier to reach support**: Added a visible Support forum link in the plugin top bar, beneath the How it works card, and on the WordPress Plugins list.
* **Listing**: Updated plugin tags, description and FAQ for clearer WordPress migration search discovery, and moved pre-3.3.0 changelog entries to changelog.txt.

= 3.3.9 =
* New look for the plugin screen: a dark neon panel that matches the new icon. Backup lights up yellow and Restore lights up cyan, and the progress bar turns green when a run finishes and red when it stops. Nothing about how backups and restores work has changed.

= 3.3.8 =
* **Listing**: The plugin description now says up front that success is guaranteed.

= 3.3.7 =
* **Pay inside the restore**: When a backup hasn't been paid for yet, the restore check no longer stops with a message. It shows the backup it found and the price, and PayPal opens in a small window. As soon as the payment goes through, the restore carries on by itself.
* **Pay from the old site**: The notice you see when a backup finishes now has a Pay button, so you can pay right away instead of from the email.
* **Easier to read log**: The log now lists the size of each part of the backup (database, plugins, themes, uploads) and the total, in MB and GB.

= 3.3.6 =
* **Restores of very large tables on servers with a 60-second limit**: Before a restore loads your database, it prepares each table's file. For one very large table, such as 1 GB of posts, that step took longer than some servers allow a request to run. The restore then started the same table over and over, gave up, and left the site as it was. That step is now about ten times faster, so it finishes within the limit.

= 3.3.5 =
* **Servers where the plugin could not save its log**: The plugin keeps its log in a private folder next to your WordPress folder. On some servers WordPress is not allowed to create that folder. A backup or restore there showed no progress, had no "Download log" link, and gave no reason when it stopped. The plugin now keeps the log in a private folder inside wp-content on those servers, and the plugin page tells you if it can't save a log at all.

= 3.3.4 =
* **A reminder to turn your plugins back on**: A restore turns your other plugins off, and they stay off until you turn them back on. Nothing said so, and a site could run for days with its plugins not working. After a restore, your dashboard now lists the plugins that are still off, with a button that takes you to them.

= 3.3.3 =
* **"Out of disk space" now shows on the page**: When a hosting account ran out of space during a backup, the plugin's own progress notes could get cut short. The page then stayed on the last step, such as "Archiving plugins", without saying the backup had stopped or why. It now shows that the account is out of disk space and how to fix it.

= 3.3.2 =
* **Backups that take a while to get ready**: Before a backup starts, the plugin measures your folders and registers the backup, which takes more than 20 seconds on some sites. With the plugin page open, it could take that pause for a stalled backup and stop it with "Backup failed: it stopped unexpectedly", again on every try. A backup now gets up to 10 minutes to get ready before the page checks on it.

= 3.3.1 =
* **Listing**: A shorter description. No change to the plugin itself.

= 3.3.0 =
* **Pricing**: A migration now costs $9.99, with success guaranteed or a refund. You pay through PayPal from the link in the restore-code email, before you restore. $15 a month covers unlimited migrations. If a migration doesn't work, we'll fix it or refund you in full.
* **Paying is part of the restore check**: When a backup hasn't been paid for yet, the restore check says so and shows the payment link, and the backup-completed notice names the price.

Older versions are listed in [changelog.txt](https://plugins.trac.wordpress.org/browser/1-click-migration/trunk/changelog.txt).

== Upgrade Notice ==

= 4.0.1 =
Fixes for free Local Backups: reliable progress, restores that can't loop, schedules that survive reactivation, plugins kept active, and safer storage.

= 4.0.0 =
Adds free manual and scheduled Local Backups & Restores (hourly, daily, weekly or monthly) with retention pruning, disk-space guard, and safe staging-table restore. Cloud migrations remain $9.99 or $15/mo unlimited.

= 3.3.12 =
Clearer wording for the fix-it-or-refund guarantee. Backups and restores work the same.

= 3.3.11 =
Adds a "Copy details for the forum" button when a backup or restore stops. Backups and restores work the same.

= 3.3.10 =
Easier-to-find support forum links in the plugin screen and updated WordPress.org listing copy. Backups and restores work the same.

= 3.3.9 =
New look for the plugin screen. Backups and restores work the same.

= 3.3.8 =
Listing text only: success guaranteed, said up front.

= 3.3.7 =
Paying for a restore is now a step inside the restore, which carries on by itself once paid. Includes all 3.3.6 changes.

= 3.3.6 =
Restores with a very large database table now finish on servers that limit each request to 60 seconds. Includes all 3.3.5 changes.

= 3.3.5 =
Backups and restores now show progress on servers where the plugin could not save its log. Includes all 3.3.4 changes.

= 3.3.4 =
After a restore, your dashboard now reminds you to turn your other plugins back on. Includes all 3.3.3 changes.

= 3.3.3 =
A backup that runs out of disk space now says so on the page instead of stopping silently. Includes all 3.3.2 changes.

= 3.3.2 =
Backups that take a while to get ready no longer stop with "it stopped unexpectedly". Includes all 3.3.1 changes.

= 3.3.0 =
A migration now costs $9.99 (or $15 a month for unlimited), with success guaranteed or a refund. The restore check shows the payment link when a backup hasn't been paid for.

= 3.2.8 =
A restore on a site that can't reach itself now asks you to keep the plugin page open instead of stopping. Includes all 3.2.7 changes.

= 3.2.7 =
Large restores no longer stop while decrypting or unzipping on hosts that end long requests. Includes all 3.2.6 changes.

= 3.2.6 =
The restore complete window asks whether everything came across, and restores on servers whose MySQL command line imports out of WordPress's sight no longer stop at the options check. Includes all 3.2.5 changes.

= 3.2.5 =
A backup that runs out of disk space now says so and removes its unfinished files right away. Includes all 3.2.4 changes.

= 3.2.4 =
A restore no longer stops when a plugin's database tables are linked to each other. Includes all 3.2.3 changes.

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
