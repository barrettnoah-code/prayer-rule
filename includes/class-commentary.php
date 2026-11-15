<?php
/**
 * Links to the Fathers' commentary (the Patristic Commentary plugin), beside a chapter's or a
 * psalm's heading in the Scripture reading only: never in the services, so nothing distracts
 * at prayer. Without that plugin, or where there is no commentary, nothing is shown.
 *
 * The commentary is kept by bibletr's books and numbering: the Psalms numbered as in the
 * Greek and Slavonic, the other books chapter for chapter as in the KJV. The Psalter here is
 * numbered as in the Greek already; the Scripture reading keeps each Bible's own numbering,
 * so its chapters are matched here (the KJV and Wycliffe Psalms are numbered as in the
 * Hebrew; Brenton joins Ezra and Nehemiah; and so on).
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Commentary {

	/** The book codes of the Bibles here => bibletr's name of the book. */
	const BOOKS = array(
		'GEN' => 'Genesis', 'EXO' => 'Exodus', 'LEV' => 'Leviticus', 'NUM' => 'Numbers',
		'DEU' => 'Deuteronomy', 'JOS' => 'Joshua', 'JDG' => 'Judges', 'RUT' => 'Ruth',
		'1SA' => '1 Samuel', '2SA' => '2 Samuel', '1KI' => '1 Kings', '2KI' => '2 Kings',
		'1CH' => '1 Chronicles', '2CH' => '2 Chronicles', 'EZR' => 'Ezra', 'NEH' => 'Nehemiah',
		'EST' => 'Esther', 'JOB' => 'Job', 'PSA' => 'Psalms', 'PRO' => 'Proverbs',
		'ECC' => 'Ecclesiastes', 'SNG' => 'Song of Solomon', 'ISA' => 'Isaiah', 'JER' => 'Jeremiah',
		'LAM' => 'Lamentations', 'EZK' => 'Ezekiel', 'DAN' => 'Daniel', 'DAG' => 'Daniel',
		'HOS' => 'Hosea', 'JOL' => 'Joel', 'AMO' => 'Amos', 'OBA' => 'Obadiah', 'JON' => 'Jonah',
		'MIC' => 'Micah', 'NAM' => 'Nahum', 'HAB' => 'Habakkuk', 'ZEP' => 'Zephaniah',
		'HAG' => 'Haggai', 'ZEC' => 'Zechariah', 'MAL' => 'Malachi', 'TOB' => 'Tobit',
		'JDT' => 'Judith', 'WIS' => 'Wisdom of Solomon', 'SIR' => 'Sirach', 'BAR' => 'Baruch',
		'LJE' => 'Letter of Jeremiah', 'S3Y' => 'Prayer of Azariah', 'SUS' => 'Susanna',
		'BEL' => 'Bel and the Dragon', '1MA' => '1 Maccabees', '2MA' => '2 Maccabees',
		'3MA' => '3 Maccabees', '4MA' => '4 Maccabees', '1ES' => '1 Esdras', '2ES' => '2 Esdras',
		'MAN' => 'Prayer of Manasseh',
		'MAT' => 'Matthew', 'MRK' => 'Mark', 'LUK' => 'Luke', 'JHN' => 'John', 'ACT' => 'Acts',
		'ROM' => 'Romans', '1CO' => '1 Corinthians', '2CO' => '2 Corinthians', 'GAL' => 'Galatians',
		'EPH' => 'Ephesians', 'PHP' => 'Philippians', 'COL' => 'Colossians',
		'1TH' => '1 Thessalonians', '2TH' => '2 Thessalonians', '1TI' => '1 Timothy',
		'2TI' => '2 Timothy', 'TIT' => 'Titus', 'PHM' => 'Philemon', 'HEB' => 'Hebrews',
		'JAS' => 'James', '1PE' => '1 Peter', '2PE' => '2 Peter', '1JN' => '1 John',
		'2JN' => '2 John', '3JN' => '3 John', 'JUD' => 'Jude', 'REV' => 'Revelation',
	);

	/** Bibles whose Psalms are numbered as in the Hebrew (in the Scripture reading). */
	const HEBREW_PSALMS = array( 'kjv', 'wyc' );

	public static function available() {
		return function_exists( 'pcomm_chapter' );
	}

	/** Load the pop-up's script and style on the reading page. */
	public static function enqueue() {
		if ( function_exists( 'pcomm_enqueue_links' ) ) {
			pcomm_enqueue_links();
		}
	}

	/** Greek and Slavonic numbers of a psalm numbered as in the Hebrew. */
	public static function greek_psalms( $n ) {
		$n = (int) $n;
		if ( $n <= 8 || $n >= 148 ) {
			return array( $n );
		}
		if ( 9 === $n || 10 === $n ) {
			return array( 9 );
		}
		if ( $n <= 113 ) {
			return array( $n - 1 );
		}
		if ( 114 === $n || 115 === $n ) {
			return array( 113 );
		}
		if ( 116 === $n ) {
			return array( 114, 115 );
		}
		if ( $n <= 146 ) {
			return array( $n - 1 );
		}
		return array( 146, 147 );             // 147
	}

	/**
	 * Where commentary on a chapter of the Scripture reading is kept: list of array( bibletr
	 * book name, chapter ). Empty where the numbering differs too much to be sure.
	 */
	public static function places( $translation, $code, $chapter ) {
		$c = (int) $chapter;
		if ( 'PSA' === $code ) {
			$list = in_array( $translation, self::HEBREW_PSALMS, true ) ? self::greek_psalms( $c ) : array( $c );
			return array_map(
				function ( $n ) {
					return array( 'Psalms', $n );
				},
				$list
			);
		}
		if ( 'brenton' === $translation ) {
			if ( 'EZR' === $code && $c > 10 ) {             // Esdras II: Ezra 1–10, then Nehemiah
				return array( array( 'Nehemiah', $c - 10 ) );
			}
			if ( 'JOL' === $code && $c >= 3 ) {             // the Greek Joel 3 is Joel 2:28–32
				return array( array( 'Joel', $c - 1 ) );
			}
			if ( 'MAL' === $code && 3 === $c ) {            // the Greek Malachi 3 is Malachi 3 and 4
				return array( array( 'Malachi', 3 ), array( 'Malachi', 4 ) );
			}
			if ( ( 'JER' === $code && $c >= 25 ) || ( 'PRO' === $code && $c >= 24 ) ) {
				return array();                             // chapters in another order
			}
		}
		if ( 'ESG' === $code ) {                            // the additions to Esther
			return 'brenton' === $translation ? array( array( 'Esther', $c ) ) : array( array( 'Esther', $c + 9 ) );
		}
		if ( 'DAN' === $code && 'dra' === $translation && $c >= 13 ) {
			return array( array( 13 === $c ? 'Susanna' : 'Bel and the Dragon', 1 ) );
		}
		return isset( self::BOOKS[ $code ] ) ? array( array( self::BOOKS[ $code ], $c ) ) : array();
	}

	/**
	 * The link (or links) for these places, or '' when none has commentary. A place the same
	 * as the one shown is called just "Commentary"; another, "Commentary on Psalm 50".
	 */
	public static function links( array $places, array $shown ) {
		if ( ! self::available() ) {
			return '';
		}
		$out = array();
		foreach ( $places as $p ) {
			$info = pcomm_chapter( $p[0], $p[1] );
			if ( ! $info ) {
				continue;
			}
			/* translators: %s: a chapter or psalm, e.g. Psalm 50 */
			$text  = $p === $shown ? __( 'Commentary', 'prayer-rule' ) : sprintf( __( 'Commentary on %s', 'prayer-rule' ), $info['label'] );
			$title = implode( '; ', wp_list_pluck( $info['works'], 'author' ) );
			$out[] = '<a class="pr-comm" href="' . esc_url( $info['url'] ) . '" data-pcomm-book="' . esc_attr( $info['book'] ) . '" data-pcomm-chapter="' . (int) $info['chapter'] . '" title="' . esc_attr( $title ) . '">' . esc_html( $text ) . '</a>';
		}
		return $out ? ' <span class="pr-comm-links">' . implode( ' ', $out ) . '</span>' : '';
	}

	/** For a chapter of the Scripture reading. */
	public static function chapter( $translation, $code, $chapter ) {
		$shown = array( self::BOOKS[ $code ] ?? '', (int) $chapter );
		return self::links( self::places( $translation, $code, $chapter ), $shown );
	}
}
