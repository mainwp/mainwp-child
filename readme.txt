=== MainWP Child ===
Contributors: mainwp
Donate link: 
Tags: WordPress management, management, manager, manage, WordPress controller, network, MainWP, updates, admin, administration, multiple, multisite, plugin updates, theme updates, login, remote, backups
Author: mainwp
Author URI: https://mainwp.com
Plugin URI: https://mainwp.com
Requires at least: 3.6
Tested up to: 4.6.1
Stable tag: 3.2.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html 

Provides a secure connection between your MainWP Dashboard and your WordPress sites.   MainWP allows you to manage WP sites from one central location.

== Description ==

This is the Child plugin for the [MainWP Dashboard](https://wordpress.org/plugins/mainwp/)

The MainWP Child plugin is used to securely manage multiple WordPress websites from your MainWP Dashboard.  This plugin is to be installed on every WordPress site you want to control from your Dashboard.

[MainWP](https://mainwp.com) is a self-hosted WordPress management system that allows you to manage an endless amount of WordPress blogs from one dashboard on your server.

**Features include:**
 
* Connect and control all your WordPress installs even those on different hosts!
* Update all WordPress installs, Plugins and Themes from one location
* Manage and Add all your Posts from one location
* Manage and Add all your Pages from one location
* Run everything from 1 Dashboard that you host!


== Installation ==

1. Upload the MainWP Child folder to the /wp-content/plugins/ directory
2. Activate the MainWP Child plugin through the 'Plugins' menu in WordPress

== Frequently Asked Questions ==

= What is the purpose of this plugin? =

It allows the connection between the [MainWP Dashboard](https://wordpress.org/plugins/mainwp/) plugin and the site it is installed on.

To see full documentation and FAQs please visit [MainWP Documentation](http://docs.mainwp.com/)

== Screenshots ==

1. Quick Setup Wizard
2. Add New Site Screen
3. Manage Sites Screen
4. Install Plugins Screen
5. Install Themes Screen
6. Add New User Screen
7. Manage Posts Screen
8. MainWP Settings Screen
9. Global Dashboard Screen

== Changelog ==

= 3.2.1 - 10-26-16 =
* Added: Support for PHP 5.4 and below

= 3.2 - 10-26-16 =
* Fixed: An issue with installing plugins and themes on HTTP Basic Authentication protected sites
* Fixed: An issue with Themes search on the Auto Update themes page
* Fixed: An issue with getting child site favicon
* Fixed: An issue where BackUpWordPress schedules couldn't be found
* Fixed: An issue with recording BackWPup, BackUpWordPress and BackupBuddy backups for Client Reports
* Fixed: An issue with dismissing warning message if the WordPress All Import plugin is installed
* Fixed: An issue with publishing Drafts from the Post Plus extension
* Added: Support for the new Edit User feature
* Added: Connection details tab
* Added: Support for deleting active plugins
* Updated: Number of categories pulled from child sites (from 50 to 300)

= 3.1.7 - 8-18-16 =
* Fixed: Issues with PHP 7 - The MainWP Child is now PHP 7 friendly! :-) 
* Added: Support for an upcoming extension (BacukpBuddy Extension)

= 3.1.6 - 8-2-16 =
* Fixed: an issue with loading too much data from the Broken Links Checker
* Fixed: an issue with saving UpdraftPlus extension settings
* Fixed: an issue with extracting URL for the MainWP URL Extractor Extension
* Fixed: an issue with including new tables in database backup for individual BackWPup Extension jobs
* Updated: support for new iThemes Security options

= 3.1.5 - 7-12-16 =
* Fixed: Incompatibility with the new version of the iThemes Security version
* Added: Support for the new iThemes Security features
* Added: Support for the new WP Rocket features
* Added: "Currently connected to" check in the Server Information
* Fixed: PHP Notice
* Removed: Unnecesary checks in the Server Information page

= 3.1.4 - 5-9-16 =
* Updated: function execute_snippet() extracted to a separate file

= 3.1.3 - 4-28-16 =
* Fixed: Issue with repeating the delete process of the readme.html fie
* Fixed: PHP Warning
* Fixed: Issue with replacing image source
* Fixed: Incorrect replacement of the href attribute for image external links
* Fixed: Issue with saving Wordfence option on Dreamhost hosting
* Fixed: Issue with saving PageSpeed Settings and syncing PageSpeed data
* Fixed: Secure login issue with some plugin/theme updates
* Fixed: Connection timeouts due to large sites
* Added: MU-Plugins support
* Added: Support for publishing Image Galleries in Posts and Pages
* Updated: MainWP Child plugin pages layout
* Updated: Support for the new version of the BackUpWordPress plugin
* Removed: Plugin and Theme Conflicts check feature

= 3.1.2 - 3-15-16 =
* Fixed: False connection issue warning
* Fixed: Smart Manager For WP eCommerce not updating
* Fixed: Multiple mixed content warnings
* Added: Support for Wordfence performance options

= 3.1.1 - 3-3-16 =
* Fixed: Checking abandoned plugins not in WP repository
* Fixed: Bug when running BackupWordPress backup
* Fixed: Bug adding cache settings for WordFence Extension
* Added: Feature to generate server information
* Added: Server Information items
* Added: New Subject text box to support email in Branding Extension
* Added: Support themes using invalid screen functions
* Tweaked: Support new version of BackupWordpress plugin version
* Updated: Added support in Client Reports Extension for BackWPup backups

= 3.1 - 2-17-16 =
* Fixed: PHP notices
* Fixed: Escape html error for the contact support feature of the Branding Extension
* Fixed: The issue with removing generator version
* Fixed: Update issue for the iThemes Security Pro and the Monarch plugin
* Fixed: Compatibility issue with the BackUpWordPress plugin
* Added: Auto detect manually removed script/style versions feature
* Added: Wordpress translation updates
* Added: New Branding option to disable theme switching
* Enhancement: Removed ctype_digit requirement
* Enhancement: Install plugin error message

= 3.0.2 - 1-22-16 =
* Fixed: Issue with scheduled BackupWordpress when run from dashboard
* Fixed: Issue with Heatmap tracker javascript
* Added: Support for hosts with PHP with disabled mb_regex
* Tweaked: Code snippet result message

= 3.0.1 - 1-18-16 =
* Fixed: HTML output of branding contact form
* Added: Auto retry install plugin/theme if installation fails
* Fixed: Issue with rendering CSS used in the Branding extension

= 3.0 - 1-12-16 =
* Fixed: Refactored code to meet WordPress Coding standards
* Fixed: Deprecated Function
* Fixed: Fatal Error caused by the MainWP Rocket Extension
* Fixed: Issue introduced with the new version of the iThemes Security plugin
* Fixed: Link Manager Extension bug with special characters in url
* Fixed: MainWP Client Reports Extension bug caused by high number of posts logged in database
* Fixed: Generator meta tag issue
* Fixed: Wordfence Extension issue with displaying incorrect last scan time
* Fixed: Broken Link Extension bug
* Fixed: MainWP Heatmaps Extension bug
* Fixed: Abandoned Plugins/Themes function bug with registering multiple cron jobs
* Fixed: CSS issue
* Fixed: Escaped html
* Fixed: PHP error reporting security alert
* Added: Support for the MainWP Rocket Extension to load existing WP Rocket settings
* Added: Support for Export/Import settings for the Wordfence Extension
* Added: Support new Wordfence settings options for the Wordfence Extension
* Added: Force Check Pages function for the MainWP PageSpeed Extensions
* Added: Allow to see MainWP child plugin in MainWP Dashboard plugins search
* Updated: MainWP URL Extractor Extension logic to extract urls by Post published date instead of last change date

= 2.0.29 - 9-22-15 =
* Fixed: 404 error that occurs in case Links Manger extension is in use when child plugin is hidden
* Fixed: Bug with detecting updates of hidden plugins (UpdraftPlus, BackUpWordPress, WP Rocket)
* Fixed: Bug with overwriting Amazon S3 settings in BackUpWordPress plugin
* Fixed: Bug with empty values for Text Link and Link Source options in Broken Links Checker Extension 
* Fixed: Bug with bulk repair action in Wordfence Extension
* Fixed: Bug with incorrect File System Method detection
* Added: Support for an upcoming Extension

= 2.0.28 - 9-7-15 =
* Fixed: Security Issue (MainWP White Hat Reward Program) 
* Fixed: Support for the Stream 3 plugin
* Fixed: Client Reports issue with recording auto saves for Posts and Pages
* Fixed: An issue with detection for Abandoned Plugins & Themes that are not hosted on WP.org

= 2.0.27 - 9-2-15 =
* Fixed: Security Issue (MainWP White Hat Reward Program) 

= 2.0.26 - 9-1-15 =
* Fixed: Conflict with Stream 3 (Thanks Luke Carbis of Stream) 

= 2.0.25 - 8-31-15 =
* Fixed: Issue with Client Reports extension where comments records were not displayed correctly
* Added: Support for missing Client Report records

= 2.0.24 - 8-20-15 =
* Fixed: Incorrect last update value for abandoned plugins & themes feature
* Fixed: Branding for Server Information page and Clone page title
* Fixed: Incorrect heatmap data and warnings
* Fixed: Can not add child site because get favicon timeout 
* Fixed: Hiding UpdraftPlus, WP Rocket toolbar and their notices when set to hide plugins

= 2.0.23 - 8-7-15 =
* Fixed: An issue with Heatmaps not loading
* Added: Support for the Establish New Connection feature
* Added: Support for the Abandoned plugins detection feature
* Added: Support for the Abandoned themes detection feature

= 2.0.22 - 7-22-15 =
* Fixed: Bug where the OptmizePress theme has not been updated properly
* Fixed: Bug where the Client Report extenison recored incorrect time 
* Added: Support for the upcomming extension

= 2.0.21 - 7-9-15 =
* Fixed: Bug with time schedule for the UpdraftPlus extension
* Added: Support for the upcomming extension

= 2.0.20 - 7-6-15 =
* Fixed: Bug with time schedule for the UpdraftPlus extension
* Fixed: Bug in the Scan for backups feature for the UpdraftPlus extension
* Fixed: Bug with saving email report option for free version of the UpdraftPuls plugin
* Fixed: Bug that was causing the BackupBuddy updates to fail
* Updated: Only users with the Administrator role can see the MainWP Child menu

= 2.0.19 - 6-10-15 =
* Added: Filesystem Check on the Server Information page
* Added: Support for the MainWP Child Report plugin
* Added: Support for the new UpdraftPlus Extension options
* Enhancement: Speed up directory listing by using less resources, reducing timeout issues
* Fixed: Plugin/theme upgrade issue when no file system method is specified
* Fixed: X-Frame-Options - ALLOWALL bug 
* Fixed: Timeout error for the stats child data function
* Fixed: An error with the Synchronous XMLHttpRequest for tracker.js
* Fixed: Expert settings options for the UpdraftPlus Extension
* Fixed: Calculation error for the PHP Memory Limit, PHP Max Upload Filesize and PHP Post Max Size checks

= 2.0.18 - 5-30-15 =
* Fixed: False malware alert

= 2.0.17 - 5-23-15 =
* Fixed: Bug where some premium plugin didn't update
* Fixed: Bug where some Favicons didn't display correctly
* Fixed: Bug where relative links didn't show correctly in posts

= 2.0.16 - 5-15-15 =
* Fixed: Issue with sites running PHP 5.2 and lower
* Fixed: Sync error on some sites with UpdraftPlus installed 
* Fixed: PHP Warning
* Changed: Server page to reflect requested mininum of PHP 5.3

= 2.0.15 - 5-14-15 =
* Added: Support for the upcoming extension
* Fixed: Post categories not showing on Dashboard
* Fixed: Potential malware false alert issue
* Fixed: Spelling error
* Updated: Required values on the Server Information page
* Updated: Layout of the Server Information page
* Removed: Unnecessary checks from the Server Information page
* Enhancement: Reduced page load time by autoloading common options

= 2.0.14 - 4-28-15 =
* Fixed: Handling of updates when plugins change folder structure or name

= 2.0.13 - 4-22-15 =
* Fixed: Security Issue with add_query_arg and remove_query_arg

= 2.0.12 - 4-16-15 =
* Fixed: Bug for the MainWP iThemes Security Extension 
* Fixed: Bug for the MainWP WordFence Extension 
* Fixed: Bug where the MainWP Child plugin was breaking cron jobs on child sites

= 2.0.11 - 4-12-15 =
* Fixed: Upcoming extension bug

= 2.0.10 - 4-06-15 =
* Added: Support for the display Favicon for child sites feature
* Added: Support for upcoming extension
* Fixed: Plugin conflicts with Wordpress SEO by Yoast and Backupbuddy

= 2.0.9.2 - 3-06-15 =
* Fixed: Bug where SEO values are not being set for Boilerplate Pages and Posts
* Added: Function for removing keywords and Links Manager extension settings
* Fixed: Security issue allowing some users to log on to the child site

= 2.0.9.1 - 3-05-15 =
* Added: Allow Extension to work with IThemes Security Pro

= 2.0.9 - 3-01-15 =
* Added: Support for Polish language
* Added: Support for Greek language
* Added: Support for the upcoming extension
* Fixed: Bug that was causing plugin bulk installation failing caused by disabled functions (eg. curl_multi_exec)
* Tweaked: Less PHP notices

= 2.0.8 - 2-11-15 =
* Fixed: Not all site references updated after clone
* Fixed: Fixed some PHP warnings

= 2.0.7.1 - 2-05-15 =
* Fixed: Hostgator detection caused issues on some hosts
* Fixed: Russian/arabic not shown properly
* Tweak: Heatmap tracker now consumes less memory when uploading the tracked clicks

= 2.0.7 - 2-01-15 =
* Fixed: Backup issues on Windows-hosts
* Fixed: PHP Warning message when cloning
* Added: Detect Hostgator-host to enhance settings while backing up

= 2.0.6 - 1-14-15 =
* Fixed: Uploading tar.bz2 to clone from is no longer blocked
* Fixed: Saving heatmap options process
* Fixed: Branding extension options - hiding child plugin pages
* Added: A new Branding extension option - hiding the child plugin server information page

= 2.0.5 - 1-07-15 =
* Fixed: Links Manager Extension: Now using the wordpress home option instead of siteurl for the links

= 2.0.4 - 12-26-14 =
* Fixed: Backups for hosts having issues with "compress.zlib://" stream wrappers from PHP causing corrupt backup archives
* Fixed: "Another backup is running" message displaying incorrectly 

= 2.0.3 - 12-15-14 =
* Fixed: Possible security issue

= 2.0.2 - 12-11-14 =
* Added: Support hosts with PHP Heap classes
* Fixed: Javascript issue disabling the popup menu on the admin menu

= 2.0.1 - 12-10-14 =
* Fixed: Restore/Clone from Tar via server upload

= 2.0 - 12-09-14 =
* Added: Tar GZip as a backup file format
* Added: Tar Bzip2 as a backup file format
* Added: Tar as a backup file format
* Added: Feature to resume unfinished or stalled backups
* Added: Feature to detect is backup is already running
* Added: New feature for the new Branding extension - Preserve branding option if child site gets disconnected
* Fixed: Bug where the Stream plugin update was showing if the plugin is hidden
* Fixed: MainWP Child Server Information page layout fixed
* Fixed: Restore issue in case the child plugin is hidden with the Branding Extension
* Tweak: New feature for the File Uploader extension - wp-content folder auto detected if renamed
* Tweak: Heatmap tracker script disabled by default
* Tweak: Updated the Warning message in case child site is disconnected
* Tweak: Updated the Warning message in case child site is disconnected
* Redesign: CSS updated to match the Dashboard style
* Redesign: MainWP Child Settings page layout updated
* Redesign: MainWP Child Clone/Restore layout updated
* Refactor: Added MainWP Child menu added in the WP Admin Menu 
* Refactor: MainWP Child Settings, MainWP Clone/Restore and MainWP Child Server Information pages removed from the WP Settings menu and added to MainWP Child

= 1.3.3 - 9-21-14 =
* Added new hooks for Wordfence Extension
* Fixed issue with WooCommerce Extension

= 1.3.2 - 9-16-14  =
* Fixed Permission denied issue when restoring from a backup on the dashboard

= 1.3.1 =
* Fixed Issue with new post that sometimes returned no child
* Fixed Removed duplicate restore link in Tools section

= 1.3 =
* Added: Better error reporting on backup fail
* Added: Future support for the auto detection of file descriptors
* Added: Support for new Client Report Extension features
* Added: Support for new Branding Extension features
* Added: Additional Hooks for new Extensions
* Fixed: Issue with some hosts not supporting garbage collection functions

= 1.2 =
* Added Additional tweaks for less Backup timeouts
* Added new option to enable more IO instead of memory approach for Backups
* Fixed Dropbox error when directory ends with space
* Fixed deprecated theme calls
* Fixed issues with self signed SSL certificates
* Fixed some user interface issues
* Removed incorrect "This site may to connect to your dashboard or may have other issues" when there are ssl warnings

= 1.0 =
* Added: Communication to Dashboard during backups to locate common backup locations 
* Added: Communication to Dashboard during backups to locate common cache locations
* Added: Communication to Dashboard during backups to locate non-WordPress folders
* Added: Communication to Dashboard during backups to locate Zip Archives
* Added: Several new subtasks to increase performance and reduce timeouts on Backups
* Added: New Hooks for Extensions
* Fixed: Restore on Child site not timing out
* Additional CSS and Cosmetic Tweaks

= 0.29.13 =
* Enhancement: Faster backups by using less file descriptors

= 0.29.12 =
* Added: Attempt to overwrite site time limit settings to help prevent timeouts
* Added: Attempt to reset site time out timer at intervals to help prevent timeouts

= 0.29.11 =
* Changes for update to Client Reports Extension
* Changes for update to Heat Map Extension
* Changes for update to Maintenance Extension
* Fixed verbiage for restore popup 

= 0.29.10 =
* Fixed: Admin not accessible with invalid upload directory
* Added new hooks for upcoming extensions

= 0.29.9 =
* Added new hooks for upcoming extensions

= 0.29.8 =
* Fix for uploads path outside the conventional path for backups
* Added new hooks for upcoming extensions

= 0.29.7 =
* Server Information page added for troubleshooting
* Added server warning messages when minimum requirements not met
* Added warning messages when child plugin detects possible conflict plugins
* Added new hooks for upcoming extensions

= 0.29.6 =
* Fixed plugin conflict with Maintenance plugin
* Added zip support to database backups

= 0.29.5 =
* Update for File Uploader Extension
* Added new hooks for upcoming extensions

= 0.29.4 =
* Fixed performance issue with autoloaded options
* File Uploader Extension Bug Fix
* Added new hooks for upcoming extensions

= 0.29.3 =
* Added new hooks for upcoming extensions

= 0.29.2 =
* Fix for ini_set warning when this has been disabled on the host

= 0.29.1 =
* Backups now use structure tags on child sites too
* Small fixes for the maintenance extension

= 0.29 =
* Added ability to view Child site error logs on MainWP Dashboard
* Added ability to view Child site Wp-Config on MainWP Dashboard
* Added new Hooks for Branding Extension
* Added tweak for Code Snippet Extension 

= 0.28.4 =
* More Extension Hooks to extend Code Snippet functionality 

= 0.28.3 =
* Fixed some issues with Code Snippets extension

= 0.28.2 =
* Fixed update conflict with child plugin installed on dashboard
* Fixed some warnings with debug enabled

= 0.28.1 =
* Fixed a bug on line 3269 when debug is on

= 0.28 =
* Hooks added for Code Snippets Extensions

= 0.27.2 =
* Additional hooks added

= 0.27.1 =
* Incorrect text fixed
* New Text re-coded for translation
* Tweaked writable directory checks
* Added more hooks for upcoming Extensions

= 0.27 =
* Code Changes for WP 3.9 Compatibility
* Added Select from Server option for larger Backups
* Added additional hooks for upcoming Extensions

= 0.26 =
* Minor fix for heatmap extension

= 0.25 =
* Fix for premium plugins

= 0.24 =
* Added support for premium plugins
* Fixed the restore functionality disappearing without Clone Extension
* Fixed some deprecated calls

= 0.23 =
* Fixed some deprecated warnings
* Fixed issues with Keyword Links extension

= 0.22 =
* Added extra functionality for keyword link extension

= 0.21 =
* Fixed clone issue for some hosts with FTP settings
* German translations added
* Support for 1.0.0 major version of main dashboard

= 0.20 =
* Fixed BPS-conflict where plugins were not upgrading

= 0.19 =
* Fixed issue for upgrading core/themes/plugins without FTP credentials

= 0.18 =
* Fixed issue for sending correct roles to the main dashboard
* Added htaccess file backup (backed up as mainwp-htaccess to prevent restore conflicts)

= 0.17 =
* Tuned for faster backup
* Added extension support for Maintenance extension

= 0.16 =
* Fixed some plugin conflicts preventing updates to themes/plugins/core

= 0.15 =
* Fixed issue with mismatching locale on core update

= 0.14 =
* Fixed redirection issue with wrongly encoded HTTP request

= 0.13 =
* Added restore function

= 0.12 =
* Fixed conflict with main dashboard on same site

= 0.11 =
* Plugin localisation
* Extra check for readme.html file
* Added child server information
* Fixed restore issue: not all previous plugins/themes were removed
* Fixed backup issue: not all files are being backed up

= 0.10 =
* Fixed plugin conflict
* Fixed backup issue with database names with dashes
* Fixed date formatting
* Tags are now being saved to new posts
* Fixed issue when posting an image with a link

= 0.9 =
* Fixed delete permanently bug
* Fixed plugin conflict

= 0.8 =
* Fixed issue with Content Extension
* Added feature to add sticky posts

= 0.7 =
* Fixed the message "This site already contains a link" even after reactivating the plugin

= 0.6 =
* Fixed plugin conflict with WooCommerce plugin for cloning
* Fixed backups having double the size

= 0.5 =
* Fixed issue with importing database with custom foreign key references
* Fixed issue with disabled functions from te "suhosin" extension
* Fixed issue with click-heatmap

= 0.4 =
Fixed cloning issue with custom prefix

= 0.3 =
* Fixed issues with cloning (not cloning the correct source if the source was cloned)

= 0.2 =
* Added unfix option for security issues

= 0.1 =
* Initial version
