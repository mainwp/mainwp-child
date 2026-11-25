=== MainWP Child - Securely Connects to the MainWP Dashboard to Manage Multiple Sites ===
Contributors: mainwp
Tags: update, backups, uptime monitoring, mcp, site management
Author: mainwp
Author URI: https://mainwp.com
Plugin URI: https://mainwp.com
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 6.5
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Securely connect your WordPress sites to your self-hosted MainWP Dashboard for free bulk updates, built-in backups, uptime monitoring, AI, and MCP.

== Description ==

**MainWP Child** securely connects a WordPress site to your self-hosted [MainWP Dashboard](https://wordpress.org/plugins/mainwp/). Install it on every site you maintain and run updates, backups, and monitoring for all of them from one place, with no per-site SaaS fees.

The free MainWP Core pairs two open-source plugins: install **MainWP Dashboard** on one dedicated WordPress site you control, and install **MainWP Child** (this plugin) on every site you want to manage. WordPress professionals and agencies run it with no site cap, and your site lists, client records, and activity logs stay in your own WordPress database.

MainWP Child runs on over 700,000 WordPress sites connected to over 20,000 MainWP Dashboards.

= What MainWP Child Enables on Each Connected Site =

* **Bulk updates and Safe Updates:** Runs WordPress core, plugin, theme, and translation updates from your MainWP Dashboard, premium plugins included. Safe Updates roll a plugin or theme back to its previous version automatically (WordPress 6.3 or later), and the pre-update backup check holds an update until the site has a recent backup.
* **Built-In Backups and restores:** Creates scheduled and on-demand full, database-only, files-only, and incremental backups with resumable transfers, all without a separate backup plugin. Each restore keeps a safety backup for **Roll Back**, and WP-CLI restores run on the child site server. Host-level API Backups and free backup add-ons such as BackWPup and UpdraftPlus work too.
* **Daily site management and Changes Logs:** Enables one-click WP Admin login from your Dashboard, bulk plugin, theme, user, post, and page management, password-change reminders, and **Changes Logs** so direct WP Admin actions appear as Non-MainWP Changes in Network Activity.
* **Monitoring and Site Health:** Reports WordPress Site Health status to your Dashboard alongside built-in HTTP(s), Ping, and Keyword uptime monitoring and post-update HTTP checks.

= Built-In AI Without Extra Child-Site Plugins =

AI tools connect to your MainWP Dashboard, so your client sites need no extra AI plugin. **MainWP Assistant**, **MainWP MCP** (over 100 core abilities), **MainWP Knowledge**, and **MainWP Control** work with every connected site through your Dashboard and MainWP Child. See [MainWP AI](https://mainwp.com/mainwp-ai/).

= Connection Security, Privacy, and Open Source =

Initial setup verifies each connection through administrator password authentication (used during setup and kept off your MainWP Dashboard and MainWP.com) or a Unique Security ID, or both. After connection, a per-site OpenSSL key pair digitally signs requests between your Dashboard and each child site (defaulting to SHA256), and HTTPS site URLs encrypt that traffic in transit.

The free MainWP Child and MainWP Dashboard plugins include no telemetry or tracking by default. Review [How Secure Is MainWP](https://mainwp.com/how-secure-is-mainwp/), the [MainWP Plugin Privacy Policy](https://mainwp.com/privacy-policy/mainwp-plugin-privacy-policy/), and [Privacy & Data Handling](https://docs.mainwp.com/getting-started/are-you-guys-watching-what-i-am-doing) for details.

[MainWP Child](https://github.com/mainwp/mainwp-child), [MainWP Dashboard](https://github.com/mainwp/mainwp), [MainWP MCP](https://github.com/mainwp/mainwp-mcp), and [MainWP Control](https://github.com/mainwp/mainwp-control) are open source under the GPL on GitHub so you can inspect the code.

= Core, Essentials, and MainWP Pro Add-ons =

The free MainWP Core stays free for unlimited WordPress sites. See every included feature on [MainWP Core Features](https://mainwp.com/mainwp-free-features/).

**MainWP Essentials** adds over 10 free add-ons, including Vulnerability Checker, Patchstack, BackWPup, and UpdraftPlus. All you need is a free MainWP.com account. **[MainWP Pro](https://mainwp.com/signup/)** is one flat-rate plan for unlimited sites with over 30 premium add-ons, such as MainWP White Label, MainWP Pro Reports (scheduled branded client reports), MainWP Team Control, and Wordfence, plus priority ticket support.

Compare plans on [MainWP Core vs Pro](https://mainwp.com/free-vs-pro/) or browse [MainWP Add-ons](https://mainwp.com/mainwp-add-ons/).

= Get Started =

New to MainWP? Install the free [MainWP Dashboard](https://wordpress.org/plugins/mainwp/) on a dedicated WordPress site first. Then add MainWP Child to each site you manage and connect it with the [Install MainWP Child](https://docs.mainwp.com/getting-started/get-started-with-mainwp#install-mainwp-child) guide.

= Developer Resources and Support =

* [MainWP Website](https://mainwp.com/)
* [MainWP Documentation](https://docs.mainwp.com/)
* [MainWP Developer Docs](https://mainwp.dev/)
* [MainWP Child Support Forum](https://wordpress.org/support/plugin/mainwp-child/)
* [MainWP on GitHub](https://github.com/mainwp/)

== Installation ==

1. **Set up your MainWP Dashboard:** Install and activate [MainWP Dashboard](https://wordpress.org/plugins/mainwp/) on a dedicated single-site WordPress installation.
2. **Install MainWP Child:** On each WordPress site you want to manage (requires WordPress 6.2 or later and PHP 7.4 or later), search for **MainWP Child** under **Plugins > Add New Plugin**, or upload the plugin folder to `/wp-content/plugins/`.
3. **Activate the plugin:** Activate **MainWP Child** through the **Plugins** menu. An unconnected install stays active for 20 minutes by default while you pair it.
4. **Connect to your MainWP Dashboard:** Add the site in your Dashboard using the site URL, administrator username, and administrator password or Unique Security ID ([Install MainWP Child](https://docs.mainwp.com/getting-started/get-started-with-mainwp#install-mainwp-child)).

== Frequently Asked Questions ==

= Is the MainWP Child plugin required to use MainWP? =

Yes. Install and activate MainWP Child on each WordPress site you want to connect to your MainWP Dashboard.

= I found MainWP Child on my site. What does it do? =

MainWP Child connects this site to a MainWP Dashboard, a self-hosted WordPress management tool run by the person or agency who maintains the site. They use it to run updates, backups, and monitoring from their own Dashboard. MainWP Child acts on requests signed by that one connected Dashboard, and the plugin includes no telemetry or tracking by default. Site administrators can review the connection under **Settings > MainWP Child > Settings** in WP Admin, where **Site Connection Management** disconnects the site.

= Why did MainWP Child deactivate itself? =

For security, an unconnected MainWP Child install switches itself off after 20 minutes by default. Reactivate it from the **Plugins** page to start a new connection window, or change the window under **Settings > MainWP Child > Settings** in WP Admin.

= How does MainWP Child secure the connection to my MainWP Dashboard? =

Each connection starts with administrator password authentication, a Unique Security ID, or both. From then on, every request from your MainWP Dashboard carries a signature from that site's own OpenSSL key pair, which MainWP Child checks before acting, and HTTPS site URLs encrypt that traffic in transit. See [How Secure Is MainWP](https://mainwp.com/how-secure-is-mainwp/) and [MainWP Connection Security](https://docs.mainwp.com/advanced/miscellaneous/mainwp-connection-security).

= Do I need another backup or AI plugin on my child sites? =

The free MainWP Core includes **Built-In Backups**, which run through MainWP Child 6.4 or later without a separate backup plugin ([Built-In Backups guide](https://docs.mainwp.com/sites/backups/manage-backups)). MainWP Assistant and MainWP MCP connect through your MainWP Dashboard, so no extra AI plugin goes on your child sites.

= Can I hide or white-label the MainWP Child plugin in WP Admin? =

Yes. The **MainWP White Label** add-on (part of MainWP Pro) lets you hide or rebrand MainWP Child in WP Admin on connected sites. See [MainWP White Label](https://mainwp.com/add-on/white-label/).

= How does MainWP compare to ManageWP? =

ManageWP is a hosted service that bills its premium add-ons per site each month. MainWP runs on your own WordPress install, and the free MainWP Core covers unlimited sites. Read the full comparison on [ManageWP vs MainWP](https://mainwp.com/managewp-vs-mainwp/).

= How does MainWP compare to WP Umbrella? =

WP Umbrella is a hosted SaaS platform, while MainWP runs on your own WordPress installation with open-source Core plugins and flat-rate Pro pricing for unlimited sites. Compare features side by side on [WP Umbrella vs MainWP](https://mainwp.com/wp-umbrella-vs-mainwp/).

= How does MainWP compare to InfiniteWP? =

Both MainWP and InfiniteWP are self-hosted WordPress management tools. MainWP Dashboard, MainWP Child, MainWP MCP, and MainWP Control are open source under the GPL on GitHub, and the free MainWP Core includes Built-In Backups, uptime monitoring, and built-in AI tools. Check the details on [InfiniteWP vs MainWP](https://mainwp.com/infinitewp-vs-mainwp/).

= How does MainWP compare to WP Remote? =

WP Remote is a cloud-hosted service, while your MainWP Dashboard runs on your own server so your site lists and client records stay in your own WordPress database. View the breakdown on [WP Remote vs MainWP](https://mainwp.com/wpremote-vs-mainwp/).

= How can I contribute or submit a feature idea? =

Contribute to the open-source MainWP Child codebase on [GitHub](https://github.com/mainwp/mainwp-child), or submit and vote on feature requests on [MainWP Voice](https://voice.mainwp.com/).

= Where can I read more pre-installation questions? =

Read our full [MainWP Pre-install FAQ](https://docs.mainwp.com/getting-started/pre-install-faq) for hosting, licensing, and setup details.

= Where do I report security bugs found in this plugin? =

Report security bugs found in the source code of the MainWP Child plugin through the [Patchstack Vulnerability Disclosure Program](https://patchstack.com/database/vdp/9e5fb39d-412a-44d4-8639-39c3db443381). The Patchstack team assists with verification, CVE assignment, and developer notification.

== Screenshots ==

1. Sites - Operations
2. Sites - Manage Sites
3. Sites - Add a New Site
4. Sites - Dark Mode - Manage Sites
5. Sites - Manage Plugins
6. Sites - Manage Posts
7. Sites - Manage Updates
8. Clients - Manage Clients
9. Cost Tracker - Cost Summary
10. Cost Tracker - Manage Costs
11. Dashboard Insights

== Changelog ==

= 6.5 - 11-17-2026 =

* Added: Enabled MainWP Dashboard to delete a restore’s safety backup from "Restore History".
* Fixed: Prevented the newer comment moderation interface from permanently deleting comments when users select "Trash" on a site where Trash is disabled.
* Fixed: Resolved a PHP 8 error that could interrupt scheduled jobs, including BackUpWordPress backups.
* Fixed: Corrected the timing of front-end "Password Policy" reminders to use the date the user last changed their password when available.
* Fixed: Corrected the last backup date so it refreshes after a successful UpdraftPlus backup when Child Reports is active.
* Updated: Backup error messages now identify the unexpected archive entry that stopped the backup.

= 6.4.2 - 11-10-2026 =

* Added: RunCache detection and cache-purge dispatch while preserving RunCloud Hub support.
* Added: Support for saving UpdraftPlus SFTP server fingerprints while preserving existing values when no replacement is supplied.
* Fixed: TAR restore extraction of filenames containing literal question marks, preserving original names and file contents.
* Fixed: Pre-restore safety backups failing when cache files change during TAR archive creation.
* Fixed: Known backup locations exclusions for All-in-One WP Migration, UpdraftPlus, and Duplicator folders.
* Fixed: Site Hardening Unfix reporting.
* Fixed: Prevented Changes Logs errors caused by empty timezone offsets and corrected dates across daylight-saving changes.
* Fixed: Reduced the risk of unintended plugin deactivation during overlapping premium update checks and plugin updates.
* Fixed: WP-CLI restores to a different domain retaining the source site’s URLs. Restores now preserve the destination’s Site Address (home) and WordPress Address (siteurl).
* Updated: Tracked password changes without temporarily storing password hashes.
* Updated: Corrected Password Policy documentation to describe password-change reminders.

= 6.4.1 - 11-3-2026 =

* Fixed: Restored recent Changes Logs entries and their associated details.
* Fixed: Prevented site errors when revoking Application Passwords during user profile updates.
* Fixed: Corrected Pro Reports New Posts totals to exclude pages and custom post types.
* Fixed: Corrected Network Activity entries for WordPress updates to display the previous and installed versions.
* Maintenance: Prepared backup file handling for future compatibility improvements.

= 6.4 - 10-29-2026 =

* Added: Support for MainWP Built-In Backups (beta), enabling Dashboard-managed full, database-only, files-only, and incremental backups, resumable transfers, and snapshot restores without an additional backup plugin.
* Fixed: Registered the System Monitor’s one-minute cron schedule before initialization to prevent scheduling errors.
* Fixed: Viewing the Menus page no longer captures unnecessary snapshots of every navigation menu. Menu and Customizer changes continue to be captured when saved.
* Fixed: Change logging now preserves the existing database error-suppression setting after checking for a table.
* Fixed: The PHP Error Reporting hardening check now correctly detects enabled error display, including during signed Dashboard requests. Both `display_errors` and `display_startup_errors` must be disabled for the check to pass.
* Fixed: Corrected guest comment attribution and user role change entries in Changes Logs.
* Fixed: Missing Cache Control sync data when Dashboard connections use the front-end fallback.
* Fixed: Child settings redirects and access to Tools pages hidden by branding.
* Fixed: Code Snippets output now preserves valid text when invalid characters are encountered.
* Fixed: Built-in Backups restores no longer time out after 15 minutes while backup data is still transferring to the child site.
* Updated: Strengthened Dashboard request verification and replay protection.
* Updated: Improved handling of folder names in Restore/Clone navigation.
* Updated: Strengthened post and page checks to prevent actions on the wrong content type.
* Updated: Strengthened download validation for imported post and page images while preserving imports from the connected Dashboard.
* Updated: Improved protection of database credentials and temporary files during cPanel database backups.

= 6.3 - 10-13-2026 =

* Fixed: WordPress core update activity logs now show the correct versions before and after an update.
* Fixed: Premium updates no longer remain listed after they have already been installed on the Child site and synchronized.
* Fixed: User role-change activity logs now record the previous and new roles correctly.
* Fixed: Prevented background asset checks and HTML Regression synchronization from failing before WordPress asset handling was initialized.
* Fixed: Comment moderation now reports only changes completed by WordPress and continues bulk actions when comments are missing or invalid.
* Fixed: Downloads opened through one-click login no longer fail as unauthorized, including UpdraftPlus backups and other protected links.
* Updated: Upgraded the bundled PHPSecLib library to address upstream security issues and improve compatibility.

= 6.2.2 - 10-5-2026 =

* Security: Hardened serialized data handling during Clone/Restore operations

= 6.2.1 - 9-30-2026 =

* Fixed: Resolved an issue where installed premium updates remained listed after synchronizing the child site with the MainWP Dashboard.
* Updated: Premium update requests now return results immediately for more timely status reporting.
* Updated: Expanded the Unique Security ID guidance to cover automatic generation, regeneration, connection methods, and updates in the MainWP Dashboard

= 6.2 - 9-9-2026 =

* Added: Version 2 capabilities and operations across backups, staging, security, vulnerability scanning, SEO, branding, maintenance, WooCommerce, caching, and other integrations.
* Added: Safer file deployment, plugin and theme installation, artifact delivery, page management, comment moderation, custom post importing, and database optimization.
* Added: Previews, status tracking, pagination, rollback, cancellation, and replay-safe operation handling for MainWP Abilities.
* Added: System Monitor for WordPress cron health, including scheduled checks, fallback execution, and manual triggering.
* Added: Support for pCloud remote storage settings in the UpdraftPlus integration.
* Added: Optional signed request payload authentication with expiration and one-time-use validation.
* Updated: Improved compatibility with older WordPress and plugin versions.

= 6.1.8 - 8-25-2026 =

* Fixed: "Download" and "Hide" buttons in the System Report are now clickable.
* Fixed: Theme updates performed through WP Admin are now correctly logged in Network Activity.
* Fixed: Improved BackWPup backup job handling across modern and legacy versions, including validation, progress reporting, and backup start and stop actions.
* Updated: Improved Clone session authentication and validation for stronger session security.

= 6.1.7 - 8-18-2026 =

* Updated: Improved backup report tracking for BackupBuddy, BackWPup, Time Capsule, and UpdraftPlus.
* Updated: WooCommerce Status add-on now includes sales from the previous seven days.

= 6.1.6 - 8-5-2026 =

* Fixed: Ensured consistent timezone handling for backup timestamps in the BackWPup integration.
* Updated: Improved error handling and response processing in the BackWPup integration for more reliable backup operations.

= 6.1.5 - 7-28-2026 =

* Fixed: Corrected Network Activity relationship tracking so previous field values are recorded only when the related post exists.
* Updated: Expanded support for Wordfence settings, including audit logging, scan controls, IPv4 scanning, remote IP detection, and trusted proxy configuration.

= 6.1.4 - 7-21-2026 =

* Added: Added support for the FastPixel Cache plugin in the "Cache Control" feature.
* Updated: Improved the handling of report limit selections so saved values are processed more consistently.

= 6.1.3 - 7-7-2026 =

* Fixed: Improved log filtering so the BackWPup backup job logs now include relevant default jobs more reliably.
* Fixed: Problems with detecting the WP Staging plugin status caused by recent plugin update.

= 6.1.2 - 6-16-2026 =

* Updated: Hardened MainWP Child connection verification and settings behavior for passwordless setups. If Password Authentication is disabled, adding or reconnecting a site now requires the Unique Security ID, including when using the MainWP REST API or other automations. Thanks to Khaled Alenazi (Nxploited).

= 6.1.1 - 6-2-2026 =

* Updated: Improved session cleanup when authentication failures occur to ensure proper logout behavior. [(Pull Request by Sixela)](https://github.com/sixela)
* Updated: Improved premium theme and plugin update detection to prevent system hangs caused by slow or unreachable servers. [(Pull Request by Contributolo)](https://github.com/Contributolo)

= 6.1 - 5-26-2026 =

* Fixed: Typos and inconsistencies in user login event log descriptions and action identifiers for improved clarity and consistency across Network Activity logs.
* Updated: Spammed comments are now properly excluded from being counted in reports and token metrics.
* Updated: Updated the PHPSecLib library to the latest version.
* Updated: Enhanced plugin and theme health check monitoring with improved background task scheduling and automatic recovery from missed scheduled events.

= 6.0.11 - 5-5-2026 =
* Fixed: Global `customize_register` hook exposes legacy Customizer UI issues.

= 6.0.10 - 4-28-2026 =
* Fixed: Improved namespace prefix handling to ensure more accurate module loading.
* Updated: Enhanced background synchronization for detecting and reporting outdated plugins and themes.

= 6.0.9 - 4-21-2026 =
* Updated: Improved Network Activity entries for WordPress auto-updates to avoid showing "Unknown User" and better reflect automated update actions.

= 6.0.8 - 4-14-2026 =
* Fixed: Added the `normalize_theme_slug()` method to better handle theme references and prevent PHP warnings when installing themes by uploading a ZIP file.
* Updated: Expanded Network Activity tracking to monitor core, plugin, and theme updates handled by WordPress auto-updates.

= 6.0.7 - 4-7-2026 =

* Fixed: Resolved an issue where the Network Activity feature continued logging events even when the feature was disabled.
* Fixed: Conflict with the SureFeedback plugin.
* Added: Added detailed sync monitoring with per-call timing and execution data to the Custom Event Monitor.
* Added: Added an option to set a custom Network Activity data retention period in child site databases.

= 6.0.6 - 3-31-2026 =

* Fixed: Improved timestamp strict typing compatibility on newer PHP versions.
* Fixed: Corrected an issue where the UpdraftPlus add-on option "Do not overwrite Remote Storage settings" was not respected in individual site settings.

= 6.0.5 - 3-24-2026 =

* Fixed: Corrected the timing of theme update data collection to ensure the latest information is used during updates.
* Fixed: Skipped logging meta creation events for auto-draft posts to prevent conflicts with the SureFeedback plugin.

= 6.0.4 - 3-20-2026 =

* Security: Updated the `phpseclib` library to address a recently [disclosed vulnerability](https://nvd.nist.gov/vuln/detail/CVE-2026-32935). The issue does not affect MainWP in normal usage.
* Fixed: Fatal error in when saving posts in Gutenberg if the sticky_posts option contains invalid data.

= 6.0.3 - 3-17-2026 =

* Fixed: Autoload settings for legacy data to optimize database performance.
* Fixed: UpdraftPlus remote storage settings management to properly clear backed-up remote settings when explicitly disabled
* Updated: Database upgrade tracking with automatic version detection and improved compatibility handling.
* Updated: Automatically migrate legacy BackWPup scheduled backup arguments during startup so existing scheduled backups continue running after upgrade.
* Updated: Adjusted the timing of cache purge verification to better coordinate with WordPress plugin initialization.
* Updated: Improved the UpdraftPlus backup schedule deletion functionality for enhanced reliability.

= 6.0.2 - 3-10-2026 =

* Fixed: Resolved a conflict with a third-party plugin caused by a legacy feature that hid script version numbers.
* Updated: Enhanced user role retrieval logic for Pro Reports related data.

= 6.0.1 - 3-3-2026 =

* Fixed: Updated internal asset handling to improve consistency across registration and enqueuing processes.
* Fixed: Replaced multibyte-aware string functions (`mb_strpos`, `mb_split`) with standard equivalents (`strops`, `explode`) in WordPress database table prefix detection logic.
* Fixed: Fixed a conflict with the SureFeedback plugin to improve compatibility.
* Fixed: Prevented conflicting output handling during certain database operations to improve stability in environments where multiple components manage output streams.
* Fixed: Improved escaping and translation-safe string handling to avoid edge-case issues in localized environments.
* Updated: Normalized plugin internationalization by standardizing the `mainwp-child` text domain and adding translator context for clearer, more accurate translations.
* Updated: Loaded the plugin text domain earlier by calling `localization()` from `init_frontend_only()` and `init_full()` to ensure translations are available sooner.
* Dev: Added PHPCS ignores for bundled translations, converted a heredoc SQL statement to a concatenated string, and made minor logic/formatting tweaks.
* Removed: Removed the White Label add-on feature that allowed hiding plugin and theme updates.
* Removed: Removed a stray `.htaccess` file.

= 6.0 - 2-24-2026 =

* Added: New maintenance options to selectively clean expired transients or remove all transients from the site.
* Added: Password policy management to set password-change reminders for users on child sites.
* Added: Extended post retrieval to support ID-based filtering for more targeted synchronization.
* Added: Caching for recently updated custom posts with automatic invalidation on post changes, and included cached data in sync payloads to improve performance.
* Added: Support for the upcoming Patchstack Integration for MainWP.
* Added: Support for the feature update that allows users to delete plugins that are active while respecting sequence of deactivating first and deleting after in background.
* Added: Helper method to detect MainWP-specific admin pages.
* Added: Created a dedicated MainWP_Child_Assets class to handle asset loading.
* Updated: Enhanced plugin update processing to include WordPress and PHP compatibility details for both standard and premium plugin updates when compatibility checks are available.
* Updated: Improved error message handling for better compatibility with the PostHog (MainWP Dashboard Telemetry) integration.
* Updated: Disabled cron event tracking as the default value for Sites Changes logging to reduce unnecessary log entries.
* Updated: Stopped parsing the `readme.txt` file for version requirements and switched to reading from the main plugin file for improved reliability.
* Updated: Restructuring the plugin initialization to only load necessary code based on the current request context.
* Updated: Delaying the loading of admin-specific code until it's actually needed.
* Updated: Implementing conditional loading of CSS and JavaScript files only on MainWP-specific pages.
* Updated: Separated asset registration from enqueuing for better performance.
* Updated: Optimized jQuery UI loading to only load when needed.
* Fixed: Fixed saving of Solid Security plugin settings.
* Fixed: Multiple PHP warnings and notices to ensure cleaner operation.
* Fixed: Missing user information for certain objects and events in the Sites Changes feature.
* Fixed: An issue with syncing Sites Changes that occurred on some server configurations.
* Fixed: Corrected Child plugin's inability to load jQuery libraries on the "Clone/Restore" page due to incorrect conditional logic.
* Fixed: Resolved Child plugin error when attempting to load non-existent assets.
* Dev: Updated PHPSecLib package to their latest versions to maintain security and compatibility standards.

[See Video Changelog](https://www.youtube.com/watch?v=5b50tjskwSg)

[See changelog for all versions.](https://mainwp.com/mainwp-child-changelog.txt)