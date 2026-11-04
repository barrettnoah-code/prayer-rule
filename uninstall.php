<?php
/**
 * Removing the plugin (Delete, not just Deactivate): drop the Psalter table
 * and settings. Prayers and Orders you wrote are kept as posts, so nothing
 * you typed is lost if the plugin is reinstalled.
 *
 * Members' private rules and records (table prayer_rule_vaults), their
 * shares and messages (prayer_rule_shares, prayer_rule_messages) and their
 * public keys are kept too: they are only ciphertext, and deleting them
 * would destroy members' records with no way back. To remove them as well,
 * drop those tables by hand.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}prayer_rule_verses" ); // phpcs:ignore WordPress.DB
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}prayer_rule_bible" ); // phpcs:ignore WordPress.DB

foreach ( array(
	'prayer_rule_access',
	'prayer_rule_kathismas',
	'prayer_rule_psalter_hash',
	'prayer_rule_translations',
	'prayer_rule_default_translation',
	'prayer_rule_show_colons',
	'prayer_rule_show_verse_numbers',
	'prayer_rule_services',
	'prayer_rule_rule_texts',
	'prayer_rule_dismissals',
	'prayer_rule_ustav_hash',
	'prayer_rule_saints',
	'prayer_rule_zapevy',
	'prayer_rule_bible_hashes',
	'prayer_rule_additions',
	'prayer_rule_fasting',
	'prayer_rule_evening',
	'prayer_rule_vault_db',
) as $option ) {
	delete_option( $option );
}
