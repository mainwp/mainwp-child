=== MainWP Child ===
Contributors: mainwp
Donate link: 
Tags: WordPress Management, WordPress Controller, manage, multiple, updates, mainwp, mainwp child
Author: mainwp
Author URI: http://mainwp.com
Plugin URI: http://mainwp.com
Requires at least: 3.6
Tested up to: 3.8
Stable tag: 0.27.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Allows you to manage multiple blogs from one dashboard by providing a secure connection between your child site and your MainWP dashboard.

== Description ==

This is the Child plugin for the [MainWP Dashboard](http://wordpress.org/plugins/mainwp/)

The MainWP Child plugin is used so the installed blog can be securely managed remotely by your WordPress Network.

[MainWP](http://mainwp.com) is a self-hosted WordPress management system that allows you to manage an endless amount of WordPress blogs from one dashboard on your server.

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

It allows the connection between the MainWP main dashboard plugin and the site it is installed on.

To see full documentation and FAQs please visit [MainWP Documentation](http://docs.mainwp.com/)

== Screenshots ==

1. The Dashboard Screen
2. The Posts Screen
3. The Comments Screen
4. The Sites Screen
5. The Plugins Screen
6. The Themes Screen
7. The Groups Screen
8. The Offline Checks Screen
9. The Clone Screen
10. The Extension Screen

== Changelog ==
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
