<?php
/**
 * Whole Bibles for the Scripture reading: data/bible-<id>.json.gz loaded into
 * one table, a row per chapter, and data/bibles.json listing the books.
 *
 * Built by tools/build_bibles.py from the eBible.org archives (KJV with the
 * Apocrypha, Douay-Rheims 1899, Brenton's Septuagint). Each translation keeps
 * its own book order, names and numbering. A translation is (re)loaded when
 * its file changes (checked in the admin).
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bible {

	const FORMAT     = 'prayer-rule-bible/1';
	const OPT_HASHES = 'prayer_rule_bible_hashes';   // id => md5 of the loaded file

	private static $index = null;

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'prayer_rule_bible';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				translation varchar(32) NOT NULL,
				book varchar(8) NOT NULL,
				chapter smallint(5) unsigned NOT NULL,
				ord smallint(5) unsigned NOT NULL,
				title text NOT NULL,
				verses longtext NOT NULL,
				PRIMARY KEY  (translation,book,chapter)
			) {$charset};"
		);
	}

	/** The list of translations and their books (data/bibles.json), or array(). */
	public static function index() {
		if ( null === self::$index ) {
			$file        = PRAYER_RULE_DIR . 'data/bibles.json';
			$data        = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::$index = is_array( $data ) && isset( $data['translations'] ) ? $data['translations'] : array();
		}
		return self::$index;
	}

	/** Translations as id => name (only those loaded into the database). */
	public static function translations() {
		$loaded = get_option( self::OPT_HASHES, array() );
		$out    = array();
		foreach ( self::index() as $t ) {
			if ( isset( $loaded[ $t['id'] ] ) ) {
				$out[ $t['id'] ] = $t['name'];
			}
		}
		return $out;
	}

	/**
	 * The loaded translations for the reading page, compact:
	 * array( id, name, testaments, books: array( array( code, name, group, first, last[, chapters] ) ) ).
	 */
	public static function for_reader() {
		$out = array();
		foreach ( self::index() as $t ) {
			if ( ! isset( self::translations()[ $t['id'] ] ) ) {
				continue;
			}
			$books = array();
			foreach ( $t['books'] as $b ) {
				$ch    = array_map( 'intval', $b['chapters'] );
				$row   = array( $b['code'], $b['name'], $b['group'], $ch[0], end( $ch ) );
				if ( range( $ch[0], end( $ch ) ) !== $ch ) {
					$row[] = $ch;                         // a chapter missing in the middle (Brenton's Proverbs)
				}
				$books[] = $row;
			}
			$out[] = array(
				'id'         => $t['id'],
				'name'       => $t['name'],
				'testaments' => $t['testaments'],
				'books'      => $books,
			);
		}
		return $out;
	}

	/** One translation's entry in the index, or null. */
	public static function translation( $id ) {
		foreach ( self::index() as $t ) {
			if ( $t['id'] === $id ) {
				return $t;
			}
		}
		return null;
	}

	/** Load translations whose files have changed (admin, after an update). */
	public static function maybe_update() {
		if ( ! current_user_can( 'manage_options' ) || ! self::index() ) {
			return;
		}
		$loaded = get_option( self::OPT_HASHES, array() );
		$loaded = is_array( $loaded ) ? $loaded : array();
		foreach ( self::index() as $t ) {
			if ( ( $loaded[ $t['id'] ] ?? '' ) !== $t['md5'] ) {
				self::load( $t );
				return;                       // one translation per page load keeps each load short
			}
		}
	}

	/** Replace one translation's rows with its file's. Returns chapters loaded or a WP_Error. */
	public static function load( $t ) {
		global $wpdb;
		self::install();
		$file = PRAYER_RULE_DIR . 'data/' . basename( $t['file'] );
		$raw  = is_readable( $file ) ? gzdecode( (string) file_get_contents( $file ) ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$data = false !== $raw ? json_decode( $raw, true ) : null;
		unset( $raw );
		if ( ! is_array( $data ) || ( $data['format'] ?? '' ) !== self::FORMAT ) {
			return new \WP_Error( 'prayer_rule_bad_bible', 'Not a Bible file: ' . basename( $file ) );
		}
		$table = self::table();
		$id    = sanitize_key( $data['id'] );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE translation = %s", $id ) ); // phpcs:ignore WordPress.DB
		$rows  = array();
		$count = 0;
		foreach ( $data['books'] as $ord => $book ) {
			foreach ( $book['chapters'] as $chapter ) {
				$rows[] = $wpdb->prepare( '(%s,%s,%d,%d,%s,%s)', $id, $book['code'], (int) $chapter['n'], $ord + 1, (string) $chapter['title'], wp_json_encode( $chapter['verses'], JSON_UNESCAPED_UNICODE ) );
				if ( count( $rows ) >= 25 ) {
					$count += self::insert( $rows );
					$rows   = array();
				}
			}
		}
		$count += self::insert( $rows );
		$loaded        = get_option( self::OPT_HASHES, array() );
		$loaded        = is_array( $loaded ) ? $loaded : array();
		$loaded[ $id ] = $t['md5'];
		update_option( self::OPT_HASHES, $loaded, false );
		return $count;
	}

	private static function insert( $rows ) {
		global $wpdb;
		if ( ! $rows ) {
			return 0;
		}
		$table = self::table();
		$wpdb->query( "INSERT INTO {$table} (translation,book,chapter,ord,title,verses) VALUES " . implode( ',', $rows ) ); // phpcs:ignore WordPress.DB
		return count( $rows );
	}

	/** One chapter: array( 'title' => …, 'verses' => array( array( label, text ) ) ), or null. */
	public static function chapter( $translation, $book, $chapter ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT title, verses FROM {$table} WHERE translation = %s AND book = %s AND chapter = %d", $translation, $book, (int) $chapter ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}
		$verses = json_decode( $row['verses'], true );
		return array(
			'title'  => (string) $row['title'],
			'verses' => is_array( $verses ) ? $verses : array(),
		);
	}

	/** Chapters and verses in the database per translation: id => array( chapters, verses ). */
	public static function counts() {
		global $wpdb;
		$table = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB
			return array();
		}
		$out = array();
		foreach ( (array) $wpdb->get_results( "SELECT translation, COUNT(*) AS chapters, COUNT(DISTINCT book) AS books FROM {$table} GROUP BY translation" ) as $row ) { // phpcs:ignore WordPress.DB
			$out[ $row->translation ] = array(
				'chapters' => (int) $row->chapters,
				'books'    => (int) $row->books,
			);
		}
		return $out;
	}

	/** A chapter as HTML: the title, then numbered verses. */
	public static function chapter_html( $translation, $book, $chapter, $numbers = true ) {
		$c = self::chapter( $translation, $book, $chapter );
		if ( ! $c ) {
			return '';
		}
		$html = '' !== $c['title'] ? '<p class="pr-chapter-title">' . esc_html( $c['title'] ) . '</p>' : '';
		foreach ( $c['verses'] as $verse ) {
			$html .= '<p class="pr-verse">' . ( $numbers ? '<span class="pr-vn">' . esc_html( $verse[0] ) . '</span> ' : '' ) . esc_html( $verse[1] ) . '</p>';
		}
		return $html;
	}
}
