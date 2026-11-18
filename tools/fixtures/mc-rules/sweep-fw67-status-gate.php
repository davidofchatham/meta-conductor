<?php
/**
 * mc-rules — FW-67 end-to-end: the source status gate for cross-post tokens.
 *
 * A `{parent:…}` or `{refs:…}` token reads only a PUBLISHED related post. A
 * pattern whose every token is held back is skipped (the title, slug or field
 * keeps what it has); one that still has a usable token renders, dropping the
 * held-back ones. Five acts, each on its own posts and rules:
 *
 *   1. mixed  — `{default_title} - {parent:meta:…}` over a DRAFT parent still
 *               writes: the held-back token drops out, the title is untouched.
 *   2. title  — `{parent:meta:…}` alone: a draft parent leaves the child's title
 *               alone; publishing the parent fans out and fills it; a later
 *               private and then trashed parent leaves it as it was (the value
 *               it read while public stays, by design).
 *   3. slug   — `{parent:slug}` alone as a slug pattern, same arc, on post_name.
 *   4. refs   — a draft-only field leaves the receiver alone; one published post
 *               among them wins; when the published one is trashed it holds again.
 *   5. field  — a field-from-pattern row over a draft parent keeps its value
 *               and fills once the parent publishes.
 *
 * Posts are created and deleted here; the seeded items are untouched. The
 * fixture's rules are restored afterward, pass or fail. Check
 * `wp post list --post_type=mc_item --fields=ID,post_name` after.
 *
 * Usage:  wp eval-file .../sweep-fw67-status-gate.php run
 * Prereq: mc-rules seeded (schema.php registers the ACF fields).
 *
 * @package Meta_Conductor
 */
require_once __DIR__ . '/sweep-lib.php';
use BWS\MetaConductor\Core\TermDispatcher;
$step = $args[0] ?? 'run';
if ( 'run' !== $step ) {
	WP_CLI::error( 'Only the "run" step exists.' );
}
const FW67_TEXT = 'field_mc_cf_display';
mc_isolate( array( 'title_slug_from_pattern', 'field_from_pattern' ) );
$ids  = array();
$pass = true;
/** The pass an editor's Update triggers, then the stored row. */
$save = static function ( $id ) {
	wp_update_post( array( 'ID' => $id ) );
	TermDispatcher::instance()->drain();
	clean_post_cache( $id );
	return get_post( $id );
};
/** A parent's status change, with the drain that fans it out to the children. */
$set_status = static function ( $id, $status ) {
	wp_update_post( array( 'ID' => $id, 'post_status' => $status ) );
	TermDispatcher::instance()->drain();
	clean_post_cache( $id );
};
$title_rule = static function ( $name, $title_pattern, $slug_pattern = '' ) {
	mc_write_rule_types( array( 'title_slug_from_pattern' => array( array(
		'enabled'       => true,
		'name'          => $name,
		'post_type'     => 'mc_section',
		'title_pattern' => $title_pattern,
		'slug_pattern'  => $slug_pattern,
		'slug_mode'     => 'replace',
	) ) ) );
};
$make_pair = static function ( $slug, $parent_status ) use ( &$ids ) {
	$parent = wp_insert_post( array( 'post_type' => 'mc_section', 'post_status' => $parent_status, 'post_title' => "FW67 $slug Parent", 'post_name' => "fw67-$slug-parent" ) );
	update_post_meta( $parent, 'fw67_addr', '12 Quay St' );
	$child  = wp_insert_post( array( 'post_type' => 'mc_section', 'post_status' => 'publish', 'post_title' => "FW67 $slug Child", 'post_name' => "fw67-$slug-child", 'post_parent' => $parent ) );
	$ids[]  = $parent;
	$ids[]  = $child;
	TermDispatcher::instance()->drain();
	clean_post_cache( $child );
	return array( $parent, $child );
};
try {
	// 1. Mixed: a usable token keeps the pattern writing.
	$title_rule( 'FW-67 mixed', '{default_title} - {parent:meta:fw67_addr}' );
	list( $parent, $child ) = $make_pair( 'mixed', 'draft' );
	$pass = mc_assert( 'mixed: a draft parent token drops out, the title still renders', get_post( $child )->post_title, 'FW67 mixed Child' ) && $pass;
	$set_status( $parent, 'publish' );
	$pass = mc_assert( 'mixed: the parent publishing fills the token', $save( $child )->post_title, 'FW67 mixed Child - 12 Quay St' ) && $pass;

	// 2. Title: every token held back, so the rule leaves the title alone.
	$title_rule( 'FW-67 title', '{parent:meta:fw67_addr}' );
	list( $parent, $child ) = $make_pair( 'title', 'draft' );
	$pass = mc_assert( 'title: a draft parent leaves the child title alone', get_post( $child )->post_title, 'FW67 title Child' ) && $pass;
	$pass = mc_assert( 'title: a save of the child leaves it alone too', $save( $child )->post_title, 'FW67 title Child' ) && $pass;
	$pass = mc_assert( 'title: the held title pattern does not derive a slug either', get_post( $child )->post_name, 'fw67-title-child' ) && $pass;
	$set_status( $parent, 'publish' );
	$pass = mc_assert( 'title: publishing the parent fans out and fills the title', get_post( $child )->post_title, '12 Quay St' ) && $pass;
	update_post_meta( $parent, 'fw67_addr', '99 New Rd' );
	$set_status( $parent, 'private' );
	$pass = mc_assert( 'title: a private parent does not reach the child, the old value stays', $save( $child )->post_title, '12 Quay St' ) && $pass;
	$set_status( $parent, 'pending' );
	$pass = mc_assert( 'title: a pending parent holds it too', $save( $child )->post_title, '12 Quay St' ) && $pass;
	wp_trash_post( $parent );
	TermDispatcher::instance()->drain();
	$pass = mc_assert( 'title: a trashed parent holds it too', $save( $child )->post_title, '12 Quay St' ) && $pass;
	wp_untrash_post( $parent );
	$set_status( $parent, 'publish' );
	$pass = mc_assert( 'title: a restored, published parent is read again', $save( $child )->post_title, '99 New Rd' ) && $pass;

	// 3. Slug: the slug pattern alone, post_name unchanged while held back.
	$title_rule( 'FW-67 slug', '', '{parent:slug}' );
	list( $parent, $child ) = $make_pair( 'slug', 'draft' );
	$pass = mc_assert( 'slug: a draft parent leaves post_name alone', get_post( $child )->post_name, 'fw67-slug-child' ) && $pass;
	$set_status( $parent, 'publish' );
	$pass = mc_assert( 'slug: publishing the parent writes its slug', get_post( $child )->post_name, 'fw67-slug-parent' ) && $pass;
	wp_trash_post( $parent );
	$pass = mc_assert( 'slug: trashing the parent leaves the child post_name as it was', $save( $child )->post_name, 'fw67-slug-parent' ) && $pass;
	wp_untrash_post( $parent );

	// 3b. Per pattern: one held, one usable. The usable one still writes.
	$title_rule( 'FW-67 held title', '{parent:meta:fw67_addr}', '{default_slug}-kept' );
	list( $parent, $child ) = $make_pair( 'heldtitle', 'draft' );
	$pass = mc_assert( 'per pattern: a held title is left alone', get_post( $child )->post_title, 'FW67 heldtitle Child' ) && $pass;
	$pass = mc_assert( 'per pattern: a usable slug pattern still writes', get_post( $child )->post_name, 'fw67-heldtitle-child-kept' ) && $pass;
	$title_rule( 'FW-67 held slug', '{default_title} - ok', '{parent:slug}' );
	list( $parent, $child ) = $make_pair( 'heldslug', 'draft' );
	$pass = mc_assert( 'per pattern: a usable title pattern still writes', get_post( $child )->post_title, 'FW67 heldslug Child - ok' ) && $pass;
	$pass = mc_assert( 'per pattern: a held slug is left alone', get_post( $child )->post_name, 'fw67-heldslug-child' ) && $pass;

	// 4. refs: draft-only holds, a published member wins, then trashed holds again.
	$f = 'mc_related_items';
	$title_rule( 'FW-67 refs', "{refs:$f:meta:fw67_addr}" );
	$a = wp_insert_post( array( 'post_type' => 'mc_item', 'post_status' => 'draft', 'post_title' => 'FW67 Item A', 'post_name' => 'fw67-item-a' ) );
	$b = wp_insert_post( array( 'post_type' => 'mc_item', 'post_status' => 'publish', 'post_title' => 'FW67 Item B', 'post_name' => 'fw67-item-b' ) );
	$r = wp_insert_post( array( 'post_type' => 'mc_section', 'post_status' => 'publish', 'post_title' => 'FW67 Receiver', 'post_name' => 'fw67-receiver' ) );
	$ids = array_merge( $ids, array( $a, $b, $r ) );
	update_post_meta( $a, 'fw67_addr', 'Draft Rd' );
	update_post_meta( $b, 'fw67_addr', '9 Quay St' );
	update_field( $f, array( $a ), $r );
	$pass = mc_assert( 'refs: a draft-only field leaves the receiver alone', $save( $r )->post_title, 'FW67 Receiver' ) && $pass;
	update_field( $f, array( $a, $b ), $r );
	$pass = mc_assert( 'refs: a published member beside a draft one is read', $save( $r )->post_title, '9 Quay St' ) && $pass;
	wp_trash_post( $b );
	update_post_meta( $b, 'fw67_addr', 'Trash Ln' );
	$pass = mc_assert( 'refs: with the published one trashed it holds again, the title stays', $save( $r )->post_title, '9 Quay St' ) && $pass;

	// 5. Field: a field-from-pattern row keeps its value while the parent is held back.
	mc_write_rule_types( array( 'field_from_pattern' => array( array(
		'type'         => 'field_from_pattern',
		'enabled'      => true,
		'name'         => 'FW-67 field',
		'post_types'   => array( 'mc_item' ),
		'target_field' => FW67_TEXT,
		'pattern'      => '{parent:meta:fw67_addr}',
		'output'       => 'text',
	) ) ) );
	$fp = wp_insert_post( array( 'post_type' => 'mc_item', 'post_status' => 'draft', 'post_title' => 'FW67 Field Parent', 'post_name' => 'fw67-field-parent' ) );
	update_post_meta( $fp, 'fw67_addr', '12 Quay St' );
	$fc = wp_insert_post( array( 'post_type' => 'mc_item', 'post_status' => 'publish', 'post_title' => 'FW67 Field Child', 'post_name' => 'fw67-field-child', 'post_parent' => $fp ) );
	$ids = array_merge( $ids, array( $fp, $fc ) );
	update_field( FW67_TEXT, 'KEEP', $fc );
	$save( $fc );
	$pass = mc_assert( 'field: a draft parent leaves the field value alone', get_field( FW67_TEXT, $fc, false ), 'KEEP' ) && $pass;
	$set_status( $fp, 'publish' );
	$save( $fc );
	$pass = mc_assert( 'field: the published parent fills it', get_field( FW67_TEXT, $fc, false ), '12 Quay St' ) && $pass;
} finally {
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	mc_restore();
}
WP_CLI::log( $pass ? 'FW67-STATUS-GATE OK' : 'FW67-STATUS-GATE FAIL' );
exit( $pass ? 0 : 1 );
