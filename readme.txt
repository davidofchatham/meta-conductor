=== Meta Conductor ===
Contributors: bridgewebsolutions
Tags: taxonomy, meta, acf, automation, hierarchical
Requires at least: 6.5
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.4.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Rule-based automation for WordPress taxonomies, meta fields, and post titles/slugs.

== Description ==

Meta Conductor adds rule-driven automation to WordPress taxonomies and meta fields:

* Auto-set terms based on hierarchy, related posts (ACF), related terms, or date windows
* Cascade terms from parent post to children
* Generate titles and slugs from token patterns with collision avoidance
* Restrict which depths of a hierarchical taxonomy a post may carry
* One-shot ACF → taxonomy data conversion wizard

Each rule type has its own settings tab. Rules apply automatically on `save_post`, plus a bulk-apply tool for existing posts.

Formerly distributed as BWS Meta Manager / BWS Taxonomy Manager.

== Installation ==

1. Upload the `meta-conductor` folder to `/wp-content/plugins/`.
2. Activate via the Plugins screen.
3. Configure rules under the new "Meta Conductor" top-level admin menu.

== Requirements ==

* WordPress 6.5 or higher
* PHP 8.1 or higher (strictly enforced — plugin deactivates on older PHP)
* Advanced Custom Fields Pro is required for ACF-driven rules and the Conversion tool

== Changelog ==

See CHANGELOG.md in the plugin directory for the full release log.
