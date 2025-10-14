===1-Click Migration – Free WordPress Backup & Migration Plugin===
Contributors: 1clickmigration
Tags: backup, migration, clone, restore, transfer
Requires at least: 4.0
Tested up to: 6.8.3
Requires PHP: 7.4
Stable tag: 2.3.6
Author URI: https://1clickmigration.com
License: GPLv3 or later
License URI: http://www.gnu.org/licenses/gpl-3.0.html

WordPress backup and migration plugin. Migrate, backup, clone, and restore your WordPress site with zero downtime. No size limits, 100% free.

== Description ==
### 1-Click Migration – WordPress Backup & Migration Plugin ###

Looking for a **reliable WordPress backup and migration plugin**? 1-Click Migration lets you **backup, migrate, clone, or transfer your WordPress site** between hosts or domains with **zero downtime**. Perfect for **WordPress site migration**, **staging sites**, and **website backups**.

**Why Choose 1-Click Migration for WordPress Backup & Migration?**

✅ **One-Click Backup & Migration** – Backup and restore your WordPress site with a single click
✅ **WordPress Site Migration** – Migrate your site to a new host or domain safely and easily
✅ **Clone WordPress Sites** – Create staging sites or development copies instantly
✅ **Zero Downtime Migration** – Your live site stays online during the entire backup and migration process
✅ **Selective Backup & Restore** – Choose what to backup: database, plugins, uploads, themes
✅ **Automatic URL Replacement** – All URLs and serialized data update automatically for new domains
✅ **Secure Encrypted Backups** – Password-protected WordPress backups with military-grade encryption
✅ **Server-to-Server Transfer** – Direct site transfer without large downloads or uploads
✅ **No Size Limits** – Backup and migrate WordPress sites of any size, 100% free
✅ **No Technical Skills Required** – Easy WordPress migration for beginners and professionals

Perfect for: WordPress site migration, website backup, staging site setup, clone WordPress, change hosting provider, domain name change, WordPress site transfer, development environment setup.

Learn more: [1ClickMigration.com](https://1clickmigration.com)

== How WordPress Backup & Migration Works ==

**Backup Your WordPress Site:**
1. Remove unnecessary themes, plugins, and media files to optimize backup size
2. Optional: Clean your database with [Advanced Database Cleaner](https://wordpress.org/plugins/advanced-database-cleaner/)
3. Deactivate all plugins except 1-Click Migration
4. Enter your email and a strong password for backup encryption
5. Click **Backup Site** – typically completes in 10-30 minutes

**Migrate & Restore Your WordPress Site:**
1. Install WordPress and 1-Click Migration on your new hosting provider
2. Delete any pre-installed plugins from the new site
3. Enter your email and backup password
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
* Data encrypted on your server using your password before backup
* Secure Amazon S3 cloud storage for WordPress backups
* Backups automatically deleted after 24 hours
* We never have access to your password or unencrypted files
* GDPR compliant WordPress backup solution

== Installation ==
1. Upload the `1-click-migration` folder to `/wp-content/plugins/` or install via WordPress plugin directory
2. Activate 1-Click Migration through the WordPress Plugins menu
3. Navigate to Tools → 1-Click Migration to start your WordPress backup or migration

== Frequently Asked Questions ==

= How long does WordPress backup and migration take? =
Most WordPress site backups and migrations complete in 10-30 minutes, depending on site size and hosting server resources.

= Can I backup only specific parts of my WordPress site? =
Yes! Use the advanced options to selectively backup and migrate your database, plugins, uploads, or themes. Perfect for large WordPress sites.

= Does 1-Click Migration work with WordPress Multisite? =
Not currently. The plugin is optimized for single WordPress site backup and migration.

= Will my URLs update automatically during WordPress migration? =
Yes! 1-Click Migration automatically updates all database entries including serialized data when you migrate your WordPress site to a new domain.

= How long do I have to restore my WordPress backup? =
WordPress backups are available for 24 hours after creation. You can create a new backup anytime if you miss the restoration window.

= Is there a limit on WordPress site size for backup and migration? =
No! Unlike other WordPress migration plugins, 1-Click Migration handles sites of any size completely free. Large sites over 1GB can use selective restore options.

= Can I use this for WordPress staging site setup? =
Absolutely! Clone your live WordPress site to create staging environments for testing updates, themes, or plugins safely.

= Does WordPress backup and migration cause downtime? =
No. Your live WordPress site remains fully operational during the entire backup and migration process (zero downtime migration).

= Which hosting providers work with 1-Click Migration? =
All of them! Migrate WordPress sites between any hosting providers: Bluehost, SiteGround, WP Engine, Kinsta, GoDaddy, HostGator, DreamHost, and all others.

== Screenshots ==
1. One-click WordPress backup & migration dashboard

== Changelog ==
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

== Reviews ==
If you love 1-Click Migration, please leave a **5-star review** on WordPress.org! Your feedback helps us improve this WordPress migration plugin.
