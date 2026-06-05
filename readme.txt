=== Oversized Image Finder ===
Contributors: DP
Tags: images, media, performance, optimization, scan
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find oversized images that slow down your WordPress site. Scan the Media Library, uploads folder, and theme/plugin directories.

== Description ==

Oversized Image Finder helps you identify images that may be hurting site performance. Run a scan and review:

* Filename and file size
* Image dimensions and format
* File location within wp-content
* Whether the image is in the Media Library
* How many posts/pages use the image (Media Library items)

Configure thresholds for file size and dimensions, or view all images sorted by size.

== Installation ==

1. Upload the `oversized-image-finder` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Tools → Oversized Images to run a scan

== Usage ==

1. Open **Tools → Oversized Images**
2. Choose scan scope: Media Library, uploads folder, and/or theme & plugins
3. Click **Start Scan**
4. Use the filter dropdown to show oversized images only, by size, by dimensions, or all images

== Frequently Asked Questions ==

= What counts as oversized? =

By default, images over 500 KB or larger than 2000×2000 pixels are flagged. Change thresholds under the Settings tab.

= Will this modify or delete my images? =

No. This plugin only scans and reports. It does not compress, resize, or delete files.

== Changelog ==

= 1.0.0 =
* Initial release
