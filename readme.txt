=== Meta Conductor ===
Contributors: david-mitchell
Tags: taxonomy, meta, acf, automation, hierarchical
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.9.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Rule-based automation for WordPress taxonomies, meta fields, and post titles/slugs.

== Description ==

Meta Conductor adds rule-driven automation to WordPress taxonomies and meta fields:

* Auto-set terms based on hierarchy, related posts (ACF), related terms, or date windows
* Cascade terms from parent post to children
* Generate titles and slugs from token patterns with collision avoidance
* Restrict which depths of a hierarchical taxonomy a post may carry

Rules are authored as two ordered lists — one for rules that write terms, one for rules that format titles and slugs — and they run in the order you put them in. The *Apply to Existing Posts* page runs them over posts that already exist.

== Installation ==

1. Upload the `meta-conductor` folder to `/wp-content/plugins/`.
2. Activate via the Plugins screen.
3. Configure rules under the new "Meta Conductor" top-level admin menu.

== Requirements ==

* WordPress 6.5 or higher
* PHP 8.1 or higher (strictly enforced — plugin deactivates on older PHP)
* Advanced Custom Fields Pro is required for ACF-driven rules

== Upgrade Notes ==

= Unreleased =

**Updating from a version older than 0.8.0 now needs a stop at 0.9.x first.** The one-time migrations that converted pre-0.8.0 rules to the ordered rule lists were removed. A site that skips 0.9.x keeps its old rules in storage but runs none of them, and every admin page shows an error notice saying so. Install a 0.9.x release, open the Meta Conductor settings page once, then update. Sites already on 0.8.0 or later, and fresh installs, are unaffected.

= 0.9.0 =

**The Data Conversion page and the `wp bws-conversion` WP-CLI command are gone.** The new *Apply to Existing Posts* page under the Meta Conductor menu replaces it: pick a rule, preview, apply. The Copy and Map jobs come back later as rule types applied through that page; until then, a conversion you still need has no in-plugin replacement. The update removes the page's scheduled cleanup and its two scratch tables automatically.

= 0.8.1 =

Fixes a title/slug rule whose pattern builds the whole title out of fields and never names `{default_title}`. Such a rule was dropping any token whose value already appeared in the post's current title, so the result alternated between the composed title and its own leftovers on each save.

**Posts saved while such a rule was active hold a mangled title and slug.** Re-saving each one corrects both — the `post_name` included, so a published post's permalink changes and WordPress leaves no redirect behind. Review the affected post type before and after.

= 0.8.0 =

Rules now run as two ordered lists driven by a central dispatcher, instead of each rule type acting on its own hooks. Nothing you have authored needs re-saving, and the migration is automatic. Four behavior changes are worth checking before you update.

**Bidirectional related-term rules remove their target on current state.** A rule with *Bidirectional* on used to remove its target term only at the moment a trigger term was taken off the post. It now removes the target whenever no trigger term is present — so a post carrying the target that never carried a trigger loses it on the next save. *Rules with Bidirectional off are unaffected, and it is off by default.* If a bidirectional rule's target is also applied by hand or by another rule, turn the toggle off or give that rule its own target.

**Two "terms from a referenced post (ACF)" rules in one taxonomy compose by list order.** They used to be merged into one result. If both have *Keep in sync* on, the lower one in the list now wins. Turn *Keep in sync* off on the second rule, or reorder them. One rule with several source posts is unaffected — a rule still unions its own sources.

**Title and slug rules are applied after the post is saved, always.** Patterns that read no custom fields used to resolve before the row was written, which made a `{term:}` pattern there read the previous save's terms. A save that changes a title or slug now costs one extra row update; the extra revision is suppressed.

**Title and slug rules also run when a post's terms change without the post being saved** — a parent propagating down, a relationship severed, a field written by code. **A term edit on a parent can therefore rename a published child's slug, and WordPress leaves no redirect behind.** If your title or slug patterns read terms and your posts are public, review those rules before updating. The filter `meta_conductor_format_pass_enabled` stands the format pass down.

Full detail, including the two post-status corrections and the storage migration, is in CHANGELOG.md.

== Changelog ==

See CHANGELOG.md in the plugin directory for the full release log.

== Upgrade Notice ==

= Unreleased =
Coming from before 0.8.0? Update to 0.9.x and open the settings page first — this version no longer migrates pre-0.8.0 rules.

= 0.9.0 =
Data Conversion and `wp bws-conversion` are removed. The new Apply to Existing Posts page replaces them; Copy / Map return later as rule types.

= 0.8.1 =
Fixes a title/slug pattern built entirely from fields dropping tokens that matched the existing title. Posts saved under the broken rule need one re-save to correct, which also changes their slug.

= 0.8.0 =
⚠️ Behavior changes: Bidirectional related rules remove their target whenever no trigger term is present. Two ACF-reference rules in one taxonomy now compose by list order, not merged. Title/slug rules run after save and also on term changes — a published slug can be renamed, with no redirect.
