<?php
/**
 * The Psalter: data/psalter.json loaded into one table, verse by verse.
 *
 * The file is built by tools/build_psalter.py. It is (re)loaded on
 * activation and whenever the file changes (checked in the admin).
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Psalter {

	const FORMAT      = 'prayer-rule-psalter/1';
	const OPT_HASH    = 'prayer_rule_psalter_hash';
	const OPT_TRANS   = 'prayer_rule_translations';
	const OPT_DEFAULT = 'prayer_rule_default_translation';
	const OPT_NOTICES = 'prayer_rule_translation_notices';   // id => copyright notice to show with the text

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'prayer_rule_verses';
	}

	public static function data_file() {
		return PRAYER_RULE_DIR . 'data/psalter.json';
	}

	/** Activation: create the table and load the Psalter. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				translation varchar(32) NOT NULL,
				psalm smallint(5) unsigned NOT NULL,
				verse smallint(5) unsigned NOT NULL,
				text text NOT NULL,
				PRIMARY KEY  (translation,psalm,verse)
			) {$charset};"
		);
		self::load();
	}

	/** Reload when the bundled file has changed (after a plugin update). */
	public static function maybe_update() {
		$file = self::data_file();
		if ( is_readable( $file ) && get_option( self::OPT_HASH ) !== md5_file( $file ) ) {
			self::install();
		}
	}

	/**
	 * Replace the table's contents with the file's. Returns the number of
	 * verses loaded, or a WP_Error.
	 */
	public static function load() {
		global $wpdb;
		$file = self::data_file();
		if ( ! is_readable( $file ) ) {
			return new \WP_Error( 'prayer_rule_no_psalter', 'data/psalter.json is missing.' );
		}
		$data = json_decode( file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $data ) || ( $data['format'] ?? '' ) !== self::FORMAT ) {
			return new \WP_Error( 'prayer_rule_bad_psalter', 'data/psalter.json is not a Psalter file.' );
		}

		$table = self::table();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
		$names   = array();
		$notices = array();
		$count   = 0;
		foreach ( $data['translations'] as $translation ) {
			$id           = sanitize_key( $translation['id'] );
			$names[ $id ] = sanitize_text_field( $translation['name'] );
			if ( ! empty( $translation['notice'] ) ) {
				$notices[ $id ] = sanitize_text_field( $translation['notice'] );
			}
			$rows         = array();
			foreach ( $translation['psalms'] as $psalm => $verses ) {
				foreach ( array_values( $verses ) as $index => $text ) {
					$rows[] = $wpdb->prepare( '(%s,%d,%d,%s)', $id, (int) $psalm, $index + 1, $text );
					if ( count( $rows ) >= 400 ) {
						$count += self::insert( $rows );
						$rows   = array();
					}
				}
			}
			$count += self::insert( $rows );
		}
		update_option( self::OPT_TRANS, $names );
		update_option( self::OPT_NOTICES, $notices );
		if ( ! isset( $names[ get_option( self::OPT_DEFAULT ) ] ) ) {
			update_option( self::OPT_DEFAULT, sanitize_key( $data['default'] ?? key( $names ) ) );
		}
		update_option( self::OPT_HASH, md5_file( $file ) );
		return $count;
	}

	private static function insert( $rows ) {
		global $wpdb;
		if ( ! $rows ) {
			return 0;
		}
		$table = self::table();
		$wpdb->query( "INSERT INTO {$table} (translation,psalm,verse,text) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB
		return count( $rows );
	}

	/** Translations as id => name. */
	public static function translations() {
		$names = get_option( self::OPT_TRANS, array() );
		return is_array( $names ) ? $names : array();
	}

	/** The copyright notice to show with a translation's psalms, or ''. */
	public static function notice( $id ) {
		$notices = get_option( self::OPT_NOTICES, array() );
		return is_array( $notices ) ? (string) ( $notices[ $id ] ?? '' ) : '';
	}

	public static function default_translation() {
		$id = get_option( self::OPT_DEFAULT );
		$names = self::translations();
		return isset( $names[ $id ] ) ? $id : (string) key( $names );
	}

	/** A known translation id, falling back to the default. */
	public static function translation( $id ) {
		$id = sanitize_key( (string) $id );
		return isset( self::translations()[ $id ] ) ? $id : self::default_translation();
	}

	/**
	 * Verses of one psalm as number => text, optionally limited to a range
	 * (to = 0 means to the end). Empty when the translation lacks the psalm.
	 */
	public static function psalm( $translation, $psalm, $from = 1, $to = 0 ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT verse, text FROM {$table} WHERE translation = %s AND psalm = %d AND verse >= %d AND (%d = 0 OR verse <= %d) ORDER BY verse", // phpcs:ignore WordPress.DB
				$translation,
				$psalm,
				max( 1, (int) $from ),
				(int) $to,
				(int) $to
			)
		);
		$verses = array();
		foreach ( $rows as $row ) {
			$verses[ (int) $row->verse ] = $row->text;
		}
		return $verses;
	}

	/** Verse totals per translation (for the settings screen). */
	public static function counts() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT translation, COUNT(DISTINCT psalm) AS psalms, COUNT(*) AS verses FROM {$table} GROUP BY translation" ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( (array) $rows as $row ) {
			$out[ $row->translation ] = array(
				'psalms' => (int) $row->psalms,
				'verses' => (int) $row->verses,
			);
		}
		return $out;
	}
}
