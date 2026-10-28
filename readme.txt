=== Deep Search & Replace ===
Contributors: zeeshanm
Tags: search replace, elementor, gutenberg, acf, database
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Site-wide search & replace that finds text wherever it is actually stored: Elementor, Gutenberg/block themes, classic themes and ACF.

== Description ==

Deep Search & Replace understands where content really lives: all post types, Gutenberg blocks, ACF fields, options, widgets, Elementor data, serialized and JSON data, plus theme template files and a live output filter. Includes dry run, backups and one-click undo.

* Serialized data is rebuilt with correct lengths (objects are never instantiated).
* Elementor JSON is replaced JSON-aware and Elementor caches are cleared afterwards.
* Theme files are scanned, previewed and backed up before replacement.
* Every run can be undone, and individual pages can be restored.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate it from the Plugins screen.
3. Open Tools > Deep Search & Replace.

== Frequently Asked Questions ==

= Should I back up first? =

Yes. Take a full database backup before the first real run.

== Changelog ==

= 1.4.0 =
* New: URL map for bulk link replacement. Paste or load a CSV of old URL, new URL (any number of lines).
* URL map matches whole URLs only: /old/ never changes /old/child/ or /old-2/. Absolute, relative, www, http/https, missing trailing slash, query strings and Elementor/JSON escaped forms are all handled.
* URL map follows redirect chains to the final URL and skips loops, home page sources, regex sources and other domains, with a "Check map" report.
* Dry runs and runs list every link found or replaced, with a CSV download. History reports show which links changed per page.
* WP-CLI: --url-map, --domains and --links-csv.
* Safety: redirect data of Rank Math, Redirection, Yoast Premium, Simple 301 Redirects and Safe Redirect Manager is never changed.

= 1.3.5 =
* Initial public release.
