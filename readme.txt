===1-Click Migration – Free WordPress Migration Plugin===
Contributors: 1clickmigration
Tags: migration plugin, wordpress migration, site transfer, clone, move, backup, staging
Requires at least: 4.0
Tested up to: 6.8.3
Requires PHP: 7.4
Stable tag: 2.3.2
Author URI: https://1clickmigration.com
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

100% FREE WordPress migration plugin for a limited time! No size limits, no premium add-ons, no gimmicks.

== Description ==
### 1-Click Migration – The Easiest WordPress Migration Plugin ###

Looking for a **reliable WordPress migration plugin**? 1-Click Migration lets you **migrate, clone, or transfer your WordPress site** between hosts or domains with zero downtime. Perfect for beginners and professionals.  

**Why choose 1-Click Migration Plugin?**
* One-click migration and restore—no technical knowledge needed  
* Migrate your site to a new host or domain safely  
* Clone live sites for staging or development  
* Selective backup & restore (DB, plugins, uploads, themes)  
* Automatic URL updates for new domains  
* Secure password-protected backups  
* Server-to-server streaming—no large downloads/uploads  
* No size limits, fully free for a limited time  

Learn more: [1ClickMigration.com](https://1clickmigration.com)

== Installation ==
1. Upload the `1-click-migration` folder to `/wp-content/plugins/`.
2. Activate the plugin in the WordPress Plugins menu.
3. Go to Tools → 1-Click Migration to start your migration.

== How to Use the Migration Plugin ==
= Backup Site =
* Remove unnecessary themes, plugins, and media files.
* Clean your database (optional: use [Advanced Database Cleaner](https://wordpress.org/plugins/advanced-database-cleaner/)).
* Deactivate all plugins except 1-Click Migration.
* Enter your email and a strong password for encryption, then click **Backup Site**.  
* Backup may take 10–30 minutes depending on site size.

= Restore Site =
* Install WordPress and 1-Click Migration on the new host.
* Delete any pre-installed plugins.
* Enter your email and backup password, then click **Restore Site**.  
* Large sites can be restored selectively using advanced options.

== Advanced Options ==
* **Selective Backup & Restore:** Exclude DB, plugins, uploads, or themes.  
* **Retries & Stop/Reset:** Resume or reset failed backups.  
* **Large Sites:** Restore sections individually for sites over 1GB. Restore Database last.

== Security & Privacy ==
* Data is encrypted on your server using your password.
* Stored on Amazon S3 for 24 hours; deleted forever automatically after.
* We never have access to your password or unencrypted files.

== Frequently Asked Questions ==
= How long does migration take? =
Typically 10–30 minutes depending on site size and server resources.

= Can I skip some files during migration? =
Yes, use the advanced dropdown to select exclusions.

= Does it work with Multisite? =
Not currently.

= Will URLs update automatically? =
Yes, the plugin updates all database entries including serialized data for the new domain.

= How long do I have to restore a backup? =
24 hours after backup creation. You can repeat the backup if missed.

== Screenshots ==
1. Dashboard: One-click backup & restore  
2. Migration progress screen  
3. Completed migration confirmation  

== Changelog ==
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

== Reviews ==
If you love 1-Click Migration, please leave a **5-star review** on WordPress.org! Your feedback helps us improve this WordPress migration plugin.
