=== 1-Click Migration – WordPress Backup, Migrate & Clone ===
Contributors: 1clickmigration
Tags: backup, migrate, migration, restore, clone
Requires at least: 4.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.1.24
Author URI: https://1clickmigration.com
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Free one-click migration with no 4GB cap. Clone, transfer or move a WordPress site to a new host or domain with an emailed restore code.

== Description ==

### Back up, migrate and clone a WordPress site ###

1-Click Migration backs up your site and emails you an 8-character restore code. Paste that code into a new host, a new domain or a second install, and the site is restored there. Site addresses are updated throughout the database, serialized data included. Your live site stays online while the backup runs. It's free, with no account or license key.

https://vimeo.com/1236211963

**What you can do with it**

* **Move to a new host.** Transfer a site between any two hosts without downloading or uploading backup files yourself.
* **Change your domain.** Restore on the new domain and the old address is replaced everywhere in the database, including inside serialized options and widgets.
* **Go from localhost or staging to live.** Back up a local or staging site and restore it on the live server.
* **Clone a site.** Restore a backup on a second WordPress install to get a staging or test copy.
* **Move large sites.** There is no 4GB zip cap. Back up and restore the database, plugins, themes and uploads together, or one part at a time.

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
There's no 4GB zip cap. Very large databases may need a higher PHP memory_limit on the source host. If a big site fails in one go, move it one part at a time.

= Does my site go down during a migration? =
The site you back up stays online. The site you restore onto shows a maintenance page while the restore runs.

= Can I use it to create a staging site? =
Yes. Restore the backup on a separate WordPress install, such as a staging subdomain. The copy uses the staging site's address.

= Does it work with WordPress Multisite? =
Not yet. It supports single-site installs.

= Which hosts does it work with? =
Any host that runs WordPress on PHP 7.4 or later, including Bluehost, SiteGround, WP Engine, Kinsta, GoDaddy, HostGator and DreamHost, plus local development sites. It also works on hosts that block background requests or cut long PHP requests short.

== Screenshots ==
1. Free one-click WordPress migration plugin. Back up a live site; it stays up while you migrate.
2. Selective WordPress migrate — choose database, plugins, themes, or uploads to back up.
3. Restore finished on the destination site. WordPress migration complete.
4. Enter the restore code on the destination site to finish your WordPress migration.

== Changelog ==
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

= 3.1.19 =
* **Binary database backup**: New backups hex-encode BINARY/BLOB columns (for example Wordfence file-hash tables) so those dumps restore cleanly. Older backups that still contain broken (non-hex) binary values need a fresh backup from a 3.1.19+ source; plain-text UTF-8 values stored in BLOB columns restore normally. BIT columns are no longer exported as quoted text, so their values restore exactly on hosts without mysqldump.
* **Broken binary restore guard**: Restores that detect broken binary data stop before anything on the site is changed and ask for a fresh backup.
* **Database part restore guard**: If the database was meant to be included but can't be downloaded or unpacked, the restore stops before any site files are replaced. A backup that intentionally left the database out (files-only) still restores its files without stopping.
* **Empty uploads backup**: An empty uploads, themes, or plugins folder no longer fails the backup; the plugin writes a valid empty archive and restore accepts it. An empty database dump still fails as before.
* **URL rewrite reliability**: Source addresses from `.local`/localhost and temp/cPanel domains expand scheme/www variants for the primary site path only (and checklist-approved domains). Other URLs such as CDN, embeds, and affiliate links stay unchanged.

= 3.1.18 =
* **Database archive failover**: If `ZipArchive::close()` fails while packing `db.zip`, backup no longer dead-ends when the host has no system `zip` binary. It now fails over to the existing in-process streaming zip, logs close diagnostics (libzip status, disk space), and only fails after ZipArchive, system zip, and in-process zip have all been tried (#69).

= 3.1.17 =
* **Review addresses before restore**: New backups collect an address inventory during database export. After checking the restore code, administrators can review old-to-new URL previews and select extra domains before restore starts. All choices start unchecked. Approved addresses are updated with the primary site URL in one serialized-safe pass. Older backups keep the post-restore address review.

= 3.1.16 =
* **Concurrent Backup Safety**: Added an atomic single-flight owner and per-run scratch directory so overlapping starts cannot share SQL dumps, archives, queue items, retry state, or bucket attribution.
* **Backup Part Accuracy**: Successful uploads are committed before timeout handling and cannot later be marked skipped. Part manifests are bound to the run's registered bucket, and stale workers cannot update a newer run.
* **ZIP Retry Accuracy**: A retry now accepts a valid archive left by a completed `ZipArchive::close()` before invoking monitored system-zip failover, avoiding false backup failures.

= 3.1.15 =
* **Restore Reliability**: Hardened options SQL import so large remapped `INSERT`s are no longer rejected as WordPress "invalid data" (`$wpdb->query()` `strip_invalid_text`, including the Turkish core string "Sorgu, içinde geçersiz veriler bulunduğundan işlenemedi"). Dump statements now prefer the raw mysqli handle when available; utf8/utf8mb3 connections are promoted to utf8mb4. Destination-prefix remap from 3.1.14 is unchanged.
* **Backup Integrity**: S3 uploads now fail closed: failed or missing encrypted archives are recorded as missing, remain on disk for diagnosis/retry, never receive an `ok` manifest status, and cannot trigger a restore-code email. Streamed SQL exports retry short writes and abort the backup if any dump byte cannot be written.
* **Safer Prefix Restore**: Prefix-remap temp files use checked full writes and atomic replacement, source-prefix detection requires exactly one canonical `*usermeta.sql` dump, and every SQL dump is validated/remapped before destination tables are dropped. Destination review-dismiss/count preferences survive the database replacement.
* **Large Archive Reliability**: Normal sites continue to use `ZipArchive`. If `ZipArchive::close()` fails or a persisted marker shows that it hung/timed out on a huge tree, the retry switches to a slower monitored system-zip streaming failover with worker heartbeats.
* **Log Security and Monitoring**: The migration log moved from the public plugin directory to a deny-protected private directory and is downloadable only by a nonce-verified administrator. Monitor classification now treats errors appended after a terminal marker as the current state while allowing a later terminal marker to resolve earlier transient errors.

= 3.1.14 =
* **Review Notice Accuracy**: The destination-site review notice is now armed only by restores that completed cleanly. Restores that finished with parts skipped after repeated failures (or parts missing from storage) no longer count as clean, so the notice cannot appear after a partial restore. Failed restores never counted. Same permanent dismiss flag as before.
* **Restore Reliability**: Fixed a cross-prefix restore failure where the database import could run `CREATE TABLE` against the source prefix while the `INSERT`s targeted the destination prefix (e.g. `Table '….eol_options' doesn't exist` when restoring a `wp_` backup onto a site using a different prefix). The prefix swap is now verified and repaired at statement level before import, the importer remaps any `CREATE`/`INSERT`/`DROP`/`ALTER` statement still carrying the source prefix to the destination prefix at execution time, a failed prefix rewrite aborts the restore instead of importing wrong-prefix tables, and leftover temp files in the restore DB folder are no longer imported. The destination-prefix table (e.g. `eol_options`) is always created before rows are inserted into it. Same-prefix (`wp_` → `wp_`) restores behave exactly as before.

= 3.1.13 =
* **Review Notice**: After the first clean restore, the destination site's wp-admin now shows a persistent review notice with a Leave a review button and a permanent "Don't ask again" dismiss (same dismiss flag as the 3.1.11 restore-complete popup).
* **Large-archive Reliability**: The native zip step and the encryption step now refresh the PHP time limit and the worker heartbeat while processing multi-GB archives, so they no longer die against the 300-second execution timeout. Encryption progress is logged every 512MB (offsets only).

= 3.1.12 =
* **Directory Listing Refresh**: Updated the WordPress.org listing title and short description (Free 1-Click WordPress Migration). Plugin behavior is identical to 3.1.11 — no code changes.

= 3.1.11 =
* **Log Sanitization**: The plugin log (`ocm_debug.log`) no longer records encrypted/binary payload data; I/O errors are logged as errno codes only, and every backup now ends with a terminal `Backup completed` or `Backup failed` line so log consumers can reliably detect the outcome.
* **Restore Success UX**: The Leave a review button now appears on the first clean restore-complete dialog (previously 2nd+). It is still hidden on warned, missing-parts, partial, or failed restores, and remains permanently dismissible.

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
