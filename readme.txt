=== Free 1-Click WordPress Migration ===
Contributors: 1clickmigration
Tags: backup, migrate, migration, restore, clone
Requires at least: 4.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.1.14
Author URI: https://1clickmigration.com
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Free WordPress migration plugin. Move to a new host or domain with a restore code. No 4GB zip cap.

== Description ==

### Backup, restore, and migrate in one click ###

This free WordPress migration plugin backs up a WordPress site, emails you an 8-character restore code, and lets you move to a new host or domain. URLs and serialized data update automatically. The live site stays up during backup.

https://vimeo.com/1220910737

**Why Choose 1-Click Migration for WordPress Backup & Migration?**

✅ **One-Click WordPress Backup & Migration** – Effortlessly backup and restore your WordPress site with a single click
✅ **Seamless WordPress Site Migration** – Easily migrate WordPress site to a new host or domain safely and quickly, making any WordPress site transfer a breeze.
✅ **Clone WordPress Site for Staging** – Instantly clone WordPress site to create WordPress staging plugin environments or development copies.
✅ **Zero Downtime WordPress Migration** – Ensure your live site remains fully operational throughout the entire WordPress backup and migration process for true zero downtime WordPress migration.
✅ **Selective WordPress Backup & Restore** – Granularly choose what to backup and restore for your WordPress site: database, plugins, uploads, themes, and more.
✅ **Automatic URL & Data Replacement** – All URLs and serialized data update automatically when you move WordPress site to a new domain.
✅ **Secure Encrypted WordPress Backups** – Keep your WordPress backups safe with password protection and military-grade encryption.
✅ **Direct WordPress Site Transfer** – Perform a swift WordPress site transfer directly server-to-server, avoiding large downloads or uploads.
✅ **No 4GB zip cap** – Free backups are not limited to a 4GB download. Very large databases may need a higher PHP memory_limit; use selective backup (database / plugins / uploads / themes) when a host is tight on memory.
✅ **Easy WordPress Migration for All** – Enjoy an intuitive and easy WordPress migration plugin experience for beginners and professionals alike.

Perfect for: Migrate WordPress site, complete WordPress backup and restore, create WordPress staging plugin environments, clone WordPress site for development, seamless WordPress site transfer between hosts, or to move WordPress site to a new domain. Your all-in-one free WordPress migration plugin!

Learn more: [1ClickMigration.com](https://1clickmigration.com)

== How WordPress Backup & Migration Works ==

**Backup Your WordPress Site:**
1. Remove unnecessary themes, plugins, and media files to optimize backup size
2. Optional: Clean your database with [Advanced Database Cleaner](https://wordpress.org/plugins/advanced-database-cleaner/)
3. Deactivate all plugins except 1-Click Migration
4. Enter your email and click **Backup Site** – typically completes in 10-30 minutes
5. Save the 8-character restore code we email you when the backup completes

**Migrate & Restore Your WordPress Site:**
1. Install WordPress and 1-Click Migration on your new hosting provider
2. Delete any pre-installed plugins from the new site
3. Enter the same email you used for the backup, paste the restore code from your email
4. Click **Restore Site** to complete your WordPress migration
5. Large sites can use selective restore options for files over 1GB

== Advanced WordPress Backup & Migration Features ==

**Selective Backup & Restore Options:**
Choose exactly what to backup and migrate:
* WordPress Database backup
* Plugins backup and migration
* Media uploads backup (images, videos, files)
* Theme files backup and transfer
* Selective restore for large WordPress sites

**Migration & Backup Management:**
* **Retry Failed Backups** – Automatic and manual retry options for WordPress backup
* **Stop & Reset** – Cancel and restart backup or migration process anytime
* **Large Site Support** – Restore WordPress sites over 1GB in sections for better stability
* **Progress Tracking** – Real-time backup and migration progress monitoring

**WordPress Backup Security & Privacy:**
* AES-256 encryption applied locally before files leave your server
* Secure Amazon S3 cloud storage for WordPress backups
* Backups automatically deleted after 48 hours
* Restore requires both your email and the 8-character code we send you
* GDPR compliant WordPress backup solution

== Installation ==
1. Upload the `1-click-migration` folder to `/wp-content/plugins/` or install via WordPress plugin directory
2. Activate 1-Click Migration through the WordPress Plugins menu
3. Navigate to Tools → 1-Click Migration to start your WordPress backup or migration

== Frequently Asked Questions ==

= How long does WordPress backup and migration take? =
Most WordPress site backups and migrations complete in 10-30 minutes, depending on site size and hosting server resources.

= How long do I have to restore my backup? =
Backups and their restore codes are valid for 48 hours after creation. After that the backup is deleted from our servers and you'll need to run a fresh backup.

= What if I lose the restore code email? =
The restore code is the only way to download your backup, and we cannot retrieve a lost code. If you don't see the email, check spam — otherwise just run the backup again to get a new code.

= Can I backup only specific parts of my WordPress site? =
Yes! Use the advanced options to selectively backup and migrate your database, plugins, uploads, or themes. Perfect for large WordPress sites.

= Does 1-Click Migration work with WordPress Multisite? =
Not currently. The plugin is optimized for single WordPress site backup and migration.

= Will my URLs update automatically during WordPress migration? =
Yes! 1-Click Migration automatically updates all database entries including serialized data when you migrate your WordPress site to a new domain.

= How long do I have to restore my WordPress backup? =
WordPress backups are available for 48 hours after creation. You can create a new backup anytime if you miss the restoration window.

= Is there a limit on WordPress site size for backup and migration? =
There is no 4GB zip-download cap. Large sites can use selective backup and restore. Very large databases may need a higher PHP memory_limit on the source host.

= Can I use this for WordPress staging site setup? =
Absolutely! Clone your live WordPress site to create staging environments for testing updates, themes, or plugins safely.

= Does WordPress backup and migration cause downtime? =
No. Your live WordPress site remains fully operational during the entire backup and migration process (zero downtime migration).

= Which hosting providers work with 1-Click Migration? =
All of them! Migrate WordPress sites between any hosting providers: Bluehost, SiteGround, WP Engine, Kinsta, GoDaddy, HostGator, DreamHost, and all others.

== Screenshots ==
1. Back up a live WordPress site in one click. The live site stays up.
2. Choose database, plugins, themes, or uploads.
3. Restore finished on the destination site.
4. Enter the restore code on the destination site.

== Changelog ==
= 3.1.14 =
* **Review Notice Accuracy**: The destination-site review notice is now armed only by restores that completed cleanly. Restores that finished with parts skipped after repeated failures (or parts missing from storage) no longer count as clean, so the notice cannot appear after a partial restore. Failed restores never counted. Same permanent dismiss flag as before.
* **Restore Reliability**: Fixed a cross-prefix restore failure where the database import could run `CREATE TABLE` against the source prefix while the `INSERT`s targeted the destination prefix (e.g. `Table '….eol_options' doesn't exist` when restoring a `wp_` backup onto a site using a different prefix). The prefix swap is now verified and repaired at statement level before import, the importer remaps any `CREATE`/`INSERT`/`DROP`/`ALTER` statement still carrying the source prefix to the destination prefix at execution time, a failed prefix rewrite aborts the restore instead of importing wrong-prefix tables, and leftover temp files in the restore DB folder are no longer imported. The destination-prefix table (e.g. `eol_options`) is always created before rows are inserted into it. Same-prefix (`wp_` → `wp_`) restores behave exactly as before.

= 3.1.13 =
* **Review Notice**: After the first clean restore, the destination site's wp-admin now shows a persistent review notice with a Leave a review button and a permanent "Don't ask again" dismiss (same dismiss flag as the 3.1.11 restore-complete popup).
* **Large-archive Reliability**: The native zip step and the encryption step now refresh the PHP time limit and the worker heartbeat while processing multi-GB archives, so they no longer die against the 300-second execution timeout. Encryption progress is logged every 512MB (offsets only).

= 3.1.12 =
* Directory listing title and short description refresh (Free 1-Click WordPress Migration). Plugin behavior identical to 3.1.11.

= 3.1.11 =
* Sanitize backup logs (no ciphertext on encrypt failure) and write terminal Backup completed / Backup failed lines.
* Show Leave a review on the first clean restore-complete dialog. Hidden on warned, missing-parts, partial, or failed restores. Permanently dismissible.

= 3.1.10 =
* **Large-database dumps**: SQL dumps now stream to disk in chunks instead of building the dump in PHP memory, with a preflight that logs DB size vs `memory_limit` and forces the streamed path when an in-memory dump would OOM. Low-memory warnings are written to the plugin log.
* **Partial-backup honesty**: Skipping a part after the upload retry cap (e.g. `Skipping uploads Uploading`) now records that part as skipped in `BACKUP_PARTS` and finishes as completed-with-missing-parts — never `uploads=ok`.

= 3.1.9 =
* **Restore Reliability**: Restore now swaps the destination directory aside (`dest.ocm_bak`) and only deletes it after the new tree is in place, so a kill mid-replace leaves the old or new tree, never an empty folder. Restore logs the actual destination `$directory` path.

= 3.1.8 =
* **Restore Reliability**: Fixed a false siteurl/home failure during database restore when leftover foreign-prefix `*_options` tables (or custom table prefixes) were present on the destination. The gate now verifies `siteurl` and `home` only on the just-imported `{base_prefix}options` table, using the exact `{source_prefix}options.sql` dump — not other `*options.sql` files.

= 3.1.7 =
* **Restore Success UX**: When a restore finishes, you are taken back to the 1-Click Migration admin page and shown a clear restore-complete success dialog. A large Review button (WordPress.org reviews/#new-post, no rating filter, no incentive copy) appears only after a 2nd+ clean restore on that site — never on warned/missing-parts runs — and is permanently dismissible.

= 3.1.6 =
* **Partial Backup Honesty**: Backups that skip a part (e.g. uploads after repeated timeouts) now finish with a clear "completed with missing parts" status, an incomplete-backup warning banner, and a restore-code email that lists what's missing instead of reporting a normal completion.
* **Restore Awareness**: Restores now read the backup's part manifest up front, warn which parts the backup does not contain, and fail clearly when a part that should exist is missing from storage.
* **Background Worker Watchdog**: Backup and restore workers now write a heartbeat, and the progress poller automatically resumes a stalled worker — fixing silent hangs on hosts that block admin-ajax loopback requests (basic auth, WAF, firewalls).
* **Loopback Handling**: When the pre-dispatch loopback check fails, the backup now runs in direct processing mode immediately instead of dispatching a background worker that never starts.
* **Disk Space**: Restores delete encrypted and intermediate archives as soon as each step completes, check free space before every extraction, and use real uncompressed sizes from the backup for the up-front disk check. Backups likewise clean up each part's temp files right after upload, so peak disk usage is much lower on both sides.
* **Clearer Failures**: Running out of disk space during a download or extraction now stops the restore immediately with an actionable message instead of retrying into a full disk.

= 3.1.5 =
* **Backup Reliability**: Added multipart uploads for large backup archives to reduce S3 timeout failures on slower hosts.
* **Restore Reliability**: Added resumable downloads and stronger ZIP state validation so large restores can recover from interrupted transfers.
* **Database Restore**: Hardened SQL import fallback for hosts without MySQL CLI access.
* **Compatibility**: Removed pre-3.0 legacy backup endpoint handling.

= 3.1.4 =
* **Log Noise Reduction**: Suppressed unrelated theme/plugin PHP errors after migrations finish and deduplicated identical PHP errors across requests while migrations are active.

= 3.1.3 =
* **User Guidance**: Added contextual "How to fix" messages for quota, write permission, loopback, download/decrypt/extract, and database import failures.

= 3.1.2 =
* **Restore Reliability**: Restore now fails clearly when disk quota or write permissions prevent downloads, decryption, or ZIP extraction instead of reporting partial files as restored.
* **Restore Diagnostics**: Added a restore workspace write probe and hardened DB failure-context logging for malformed SQL payloads.

= 3.1.1 =
* **Backup Diagnostics**: Added admin-ajax loopback and worker dispatch logging to help identify hosts that block background backup workers.

= 3.1 =
* **Restore Reliability**: Fixed PHP fallback database imports on hosts where `proc_open` is disabled so comment-prefixed SQL table definitions are imported correctly.
* **Restore Safety**: Database import errors now stop the restore and surface a clear failure instead of logging `Restore completed.`
* **Restore Validation**: Restore now verifies the imported `siteurl` option before running URL replacement.

= 3.0 =
* **Redesigned Admin UI**: New tabbed Backup / Restore interface with clearer steps, inline help, progress card, and a yellow notice banner that surfaces the email/restore-code reminder after a backup completes.
* **Simpler Restore Access**: Backup now uses your email and sends an 8-character restore code when the backup completes. Restore takes only that code — no separate email field needed.
* **Pre-Flight Disk Space Check**: Backup and restore exit early with a clear message if there's not enough free disk space, instead of failing midway.
* **Backup Security**: Files continue to be encrypted (AES-256) before upload, with improved key handling behind the scenes.
* **Retention**: Backup availability is now consistently 48 hours.
* **Backup Reliability**: Improved backup stability on sites with plugins that interfere with long-running background tasks.
* **Restore Reliability**: Improved restore stability when plugin code, locked folders, or stubborn file permissions would previously interrupt a migration.
* **Security**: Removed the old legacy access path and tightened the restore flow.
* **Compatibility**: Existing backups from the previous version remain restorable during the short upgrade window only.

= 2.6.1 =
* **Critical Fix**: Fixed fatal `Call to undefined function OCM\ini_set()` crash on shared hosts that disable `ini_set`/`ini_get` via `disable_functions` — this was a 100% restore blocker on affected cPanel hosts
* **Critical Fix**: Fixed "OS: Windows" log message being written 250+ times per backup on Windows/XAMPP hosts — now logs only once per session
* **Bug Fix**: Fixed fatal crash when Stop & Reset is clicked mid-backup — background process now re-creates the temporary directory if it was deleted

= 2.6.0 =
* **Critical Fix**: Fixed DivisionByZeroError crash during database backup when tables report 0 MB in information_schema (affected Gravity Forms and other small tables)
* **Bug Fix**: Changed error catch from `Exception` to `Throwable` so PHP 8 DivisionByZeroError is properly caught by the fallback handler
* **Log Noise Reduction**: Added error deduplication to suppress identical PHP errors from being logged 30+ times per session (e.g. theme/plugin bugs)
* **Log Noise Reduction**: Suppressed per-table "Table being backed up" log messages that generated 50-80+ lines per backup on large sites
* **Log Fix**: Fixed misleading "Bucket created" log message that appeared after AWS 502 errors — now correctly says "Retrying bucket creation"

= 2.5.9 =
* **Maintenance Mode**: Automatically enables maintenance mode during restore to prevent visitors from seeing broken pages, white screens, or database errors
* **Admin & API Bypass**: Admin users and REST API requests (e.g. progress polling) bypass maintenance mode so the dashboard stays functional
* **Progress Indicator**: The admin progress endpoint now reports maintenance mode status so the dashboard indicator works
* **Auto Cleanup**: Maintenance mode is automatically disabled when the restore completes or is cancelled via Stop & Reset

= 2.5.8 =
* **Timeout Fix**: Reset PHP time limit before each major operation (archive, encrypt, upload, download, decrypt, extract) to prevent timeouts on large sites
* **Upload Fix**: Upload timeout now uses the full execution budget instead of the exhausted background process remaining time
* **Download Fix**: Restore file downloads now use the full timeout budget instead of remaining background process time
* **Bug Fix**: Fixed `Undefined array key "tables"` crash when mysqldump fails and falls back to PHP-based export
* **Bug Fix**: Fixed `error_reporting` never being restored on success paths in zip creation and folder backup
* **Resilience**: Error handler no longer kills the process for fatal errors from external themes/plugins (e.g. WooCommerce dependency in child themes)
* **Reliability**: Added pre-flight disk space check before DB and folder backups to fail early with a clear message
* **Cleanup**: Removed dead `retry` parameter from `wp_remote_post` calls

= 2.5.7 =
* **Bug Fix**: Fixed fatal error during restore where `$presigned_urls` (stdClass) was passed to `sprintf` instead of the directory path string
* **Bug Fix**: Made `mkdir` recursive when creating `tmp/db/` directory so it succeeds even if the parent `tmp/` directory was cleaned up
* **Restore Fix**: Clear stale background process queues after DB import to prevent source site's backup tasks from firing on the destination

= 2.5.6 =
* **Concurrency Fix**: Added MySQL advisory locks to prevent concurrent background handlers from processing the same batch simultaneously
* **Reliability**: Fixed race condition where multiple handlers could duplicate compress, encrypt, and upload operations
* **Upload Fallback**: Improved upload step to construct expected encrypted file path when step metadata is missing, preventing silent upload skips

= 2.3.7 =
* **Progress Bar Fix**: Resolved progress bar jumping back to 1% during database backup operations
* **Memory Management**: Implemented dynamic memory management with automatic page sizing based on available system resources
* **Error Handling**: Added robust validation and error handling for S3 file uploads with proper file path checking
* **Logging Improvements**: Enhanced logging system with better filtering to prevent progress bar interference from verbose messages
* **Debug Tools**: Added conditional debug logging (WP_DEBUG only) to help identify unmapped progress messages
* **Database Optimization**: Improved database export operations with dynamic row increment sizing and better memory monitoring

= 2.3.6 =
* **Reliability Fix**: Disabled async dispatch in favor of direct processing for guaranteed execution on all hosting environments
* **Compatibility**: Resolved backup hanging issues on AWS and other servers where async HTTP requests may fail silently
* **Stability**: Reverted to proven direct processing approach for maximum reliability across all hosting providers

= 2.3.5 =
* **UX Improvement**: Disable Backup/Restore buttons when all components are excluded to prevent empty backups
* **UX Improvement**: Fixed progress bar jump from 1% to 0% at the start of backup/restore operations
* **Bug Fix**: Added validation to prevent silent failures when attempting to backup with all components excluded

= 2.3.3 =
* **Bug Fix**: Fixed `is_processing()` method compatibility issue with different WP Background Processing library versions
* **Compatibility**: Replaced method call with direct transient check for maximum compatibility

= 2.3.2 =
* **Security Fix**: Added nonce verification and capability checks to prevent unauthorized access (CVE-2025-3455)
* **Reliability**: Simplified background processing for maximum compatibility across all hosting environments
* **UX Improvement**: Fixed progress bar freeze after restore completion and login
* **Performance**: Removed async complexity that caused race conditions on various hosts
* **Compatibility**: Enhanced stop & reset functionality to prevent stuck states

= 2.3.1 =
* Fixed white screen on deactivation  
* Improved retry logic for presigned URLs  
* Enhanced cleanup & error handling  

= 2.3 =
* Stability improvements  

= 2.2 =
* CSRF fix & updates  

= 2.1 =
* Bug fixes  

= 2.0 =
* Full redesign for easier use  
* Multiple bug fixes  

= 1.6 =
* Manual & automated retries  
* Improved hosting compatibility  

= 1.4 =
* Selective backup/restore added  
* Stop & Reset button implemented  

= 1.3 =
* Support for serialized DB entries  
* PayPal integration  

= 1.0 – 1.2 =
* Initial release & dependency updates

== Upgrade Notice ==

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
If you love 1-Click Migration, please leave a **5-star review** on WordPress.org! Your feedback helps us improve this WordPress migration plugin.
