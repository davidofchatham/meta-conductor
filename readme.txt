=== Meta Conductor ===
Contributors: david-mitchell
Tags: taxonomy, meta, acf, automation, hierarchical
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.11.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Rule-based automation for WordPress taxonomies, meta fields, post titles/slugs, and computed field values.

== Description ==

Meta Conductor adds rule-driven automation to WordPress taxonomies and meta fields:

* Auto-set terms based on hierarchy, related terms, or date windows
* Set a post's terms from a related post: its parent, or the posts an ACF relationship field names
* Generate titles and slugs from token patterns with collision avoidance
* Store a composed value (a display name, a roster line) in an ACF field, or in one subfield of every row of a repeater, from the same token patterns
* Restrict which depths of a hierarchical taxonomy a post may carry
* Cap how many terms a post may hold in a taxonomy

Rules are authored as two ordered lists — one for rules that write terms, one for rules that format titles and slugs or compute field values — and they run in the order you put them in. The *Apply to Existing Posts* page runs them over posts that already exist.

== Installation ==

1. Upload the `meta-conductor` folder to `/wp-content/plugins/`.
2. Activate via the Plugins screen.
3. Configure rules under the new "Meta Conductor" top-level admin menu.

== Requirements ==

* WordPress 6.5 or higher
* PHP 8.1 or higher (strictly enforced — plugin deactivates on older PHP)
* Advanced Custom Fields Pro is required for ACF-driven rules

== Upgrade Notes ==

= 0.11.0 =

**Rule types have new names in the *Rule type* dropdown.** *Hierarchy* is now *Set terms from the term hierarchy*, *Related term* is *Set terms from a trigger term*, *Date window* is *Set terms from a date window*, *Level restriction* is *Restrict terms by tree level*, *Title & slug pattern* is *Set title and slug from a pattern*, and *Computed field* is *Set a field from a pattern*. Only the names changed; your rules behave exactly as before.

**Stored rule type ids are renamed, and your existing rules are converted on update.** Each type's internal id now reads like its label and no longer ends in `_rules` (`hierarchical_rules` is now `terms_from_term_hierarchy`, `time_based_rules` is `terms_from_date_window`, and so on for the six types above). The first admin page load after updating rewrites each stored rule's type once; its order and every other setting are untouched. Until that load runs, the old rules read as an unknown type and are skipped. An Apply run in progress on one of those types starts over once.

**The parent-post and ACF-reference term rules are now one: *Set terms from a related post*.** A *Relationship* option picks *Parent post* or *ACF field*; the ACF choice is offered only while ACF is active, and a stored ACF row simply runs nothing while it is off. The first admin page load after updating rewrites each stored *From parent post* and *From referenced post (ACF)* rule once, in place and in the same order. Until that load runs, those rules read as an unknown type and are skipped. An Apply run in progress on one of them starts over once.

**ACF rules lose *Keep in sync* and gain the same *Claim on terms* control as parent rules.** Your existing setting converts automatically: *Keep in sync* on becomes *Owning*, off becomes *Contributing*, so each rule behaves as before. *Deferring* (fill only a post with no terms yet) is now available on ACF rules too. A *Contributing* ACF rule still only adds terms; *Owning* is how an ACF rule removes a term its source lost.

**ACF rules now also read and write ACF taxonomy fields.** Until now they handled native terms only. An ACF taxonomy field attached to a post for the rule's taxonomy is now read as part of the source's terms and written along with the native terms, including a field that is attached but still empty. **If such a field has been left empty or out of step on purpose, review your ACF rules before updating:** the next pass of each affected post fills it.

**New: *Restrict how many terms a post holds* rule.** It caps how many terms a post may hold in a taxonomy, on flat and hierarchical taxonomies alike. Where a post is over the cap, the terms you just added are kept first, then the deepest terms, then the lowest term ID.

**Restrict terms by tree level no longer has *One term per level*.** It offers *Deepest only* and *Shallowest only*; use a Restrict how many terms a post holds rule for the old behavior. A stored row still holding the removed mode runs as *Deepest only*, so check any such rule. The old mode kept the alphabetically last term at a level; the count rule keeps the deepest, then the lowest term ID.

**One rule can name several taxonomies.** On Restrict how many terms a post holds, Set terms from a related post, Set terms from the term hierarchy and Restrict terms by tree level, *Taxonomies* is now a multi-select, and a row naming several behaves as if it were repeated once per taxonomy. Your existing rules are unchanged: the first admin load of the rules tab rewrites each stored single taxonomy as a one-element list. An Apply to Existing Posts run in progress on one of these rules starts over once.

= 0.10.0 =

**Title & slug collision avoidance is now an ordered ladder, and your existing rules are converted on update.** The old *Date-based collision* toggle and its date field are replaced by one *Collision avoidance — tokens to add* field. The first admin page load after updating rewrites each stored rule once: toggle on with no date field becomes `{pub_year}`, on with a date field becomes `{date_year:field}`, off becomes blank. **A rule that had the toggle on but no date token in its slug pattern used to fall back to `-2` numbering on a collision; it now appends the publication year first.** Check those rules and the slugs they produce on your next collision. Rules with a date field behave as before.

**New: *Flat URLs* on title & slug rules.** Off by default, so nothing changes until you turn it on. On a hierarchical post type it serves children at `/base/child/` and 301-redirects the old `/base/parent/child/` URL to it (only when the nested path exactly matches the post's real ancestors). **Turning it off reverts URLs to the nested form** and the flat links stop resolving, so update any links you shared in the meantime. In a flat type names are made unique across the whole post type (collision ladder first, then `-2` numbering), and a child named `page`, `feed`, `embed`, `attachment` or a bare number is numbered. Posts that already share a name keep it until they are saved again; until then two of them resolve to the published one, then the lowest ID. A redirect rule from another plugin that sends a flat URL to its own nested form (for example one recorded when the post was given a parent) is ignored, so it no longer overrides the flat URL.

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

= 0.11.1 =
Fixes the 0.11.0 upgrade showing "unknown rule type" on the first admin load. The rule-type conversion now runs on every request, before the rules tab loads.

= 0.11.0 =
Rule types are renamed and their stored ids convert automatically on the first admin load. Parent-post and ACF-reference rules merge into Set terms from a related post. ACF rules now also read and write ACF taxonomy fields, so a field left empty on purpose gets filled: review your ACF rules before updating. Level restriction loses One term per level; the new Restrict how many terms a post holds rule replaces it.

= 0.10.0 =
Old collision settings convert automatically; a rule with the toggle on and no date field now adds the year before -2 numbering. Sites older than 0.8.0 must install 0.9.x first.

= 0.9.0 =
Data Conversion and `wp bws-conversion` are removed. The new Apply to Existing Posts page replaces them; Copy / Map return later as rule types.

= 0.8.1 =
Fixes a title/slug pattern built entirely from fields dropping tokens that matched the existing title. Posts saved under the broken rule need one re-save to correct, which also changes their slug.

= 0.8.0 =
⚠️ Behavior changes: Bidirectional related rules remove their target whenever no trigger term is present. Two ACF-reference rules in one taxonomy now compose by list order, not merged. Title/slug rules run after save and also on term changes — a published slug can be renamed, with no redirect.
