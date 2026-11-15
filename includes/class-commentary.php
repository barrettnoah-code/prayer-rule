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
		if ( 'ESG' === $code ) {
			// Brenton: the whole Greek Esther. The KJV's and Wycliffe's "Rest of Esther" is
			// numbered 10:4–16:24 as in the Latin, which is where bibletr's Esther has it too.
			return array( array( 'Esther', $c ) );
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

	/**
	 * For the Bible pages: the commentary at these places, list of pcomm_chapter() results
	 * (label, url, works with author, title, url), each place once.
	 */
	public static function at( array $places ) {
		if ( ! self::available() ) {
			return array();
		}
		$out  = array();
		$seen = array();
		foreach ( $places as $p ) {
			$key = $p[0] . '|' . $p[1];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$info         = pcomm_chapter( $p[0], $p[1] );
			if ( $info && ! empty( $info['works'] ) ) {
				$out[] = $info;
			}
		}
		return $out;
	}

	/**
	 * The verses of a chapter that have commentary of their own: label => array( book,
	 * chapter, verse, url ), for the verse numbers to open it (Bible pages and the Scripture
	 * reading; never the services). bibletr numbers verses as the KJV does outside the Psalms,
	 * so a chapter gets them when it is the KJV's, or numbered exactly like the KJV's at the
	 * same place. The Psalms (bibletr follows the Slavonic verse division there) and chapters
	 * that are two places at once keep the chapter link only.
	 *
	 * @param array $verses The chapter's verses as shown: list of array( label, text ).
	 * @param array $places Where its commentary is kept (Commentary::places / BibleMap::places).
	 */
	public static function verse_links( $translation, $code, $chapter, array $verses, array $places ) {
		if ( 'PSA' === $code || 1 !== count( $places ) || ! self::available() ) {
			return array();
		}
		$info = pcomm_chapter( $places[0][0], $places[0][1] );
		if ( ! $info || empty( $info['verses'] ) ) {          // none, or Patristic Commentary before 0.4.0
			return array();
		}
		$labels = array_map(
			function ( $v ) {
				return (string) $v[0];
			},
			$verses
		);
		if ( 'kjv' !== $translation ) {
			if ( BibleMap::has_chapter( 'kjv', $code, $chapter ) && self::places( 'kjv', $code, $chapter ) === $places ) {
				$k = array( $code, (int) $chapter );
			} else {
				$k = BibleMap::locate( 'kjv', $places );
			}
			$kjv = $k ? Bible::chapter( 'kjv', $k[0], $k[1] ) : null;
			if ( ! $kjv || array_map( 'strval', wp_list_pluck( $kjv['verses'], 0 ) ) !== $labels ) {
				return array();
			}
		}
		$has = array_flip( array_map( 'intval', $info['verses'] ) );
		$out = array();
		foreach ( $labels as $l ) {
			if ( ctype_digit( $l ) && isset( $has[ (int) $l ] ) ) {
				$out[ $l ] = array(
					'book'    => $places[0][0],
					'chapter' => (int) $places[0][1],
					'verse'   => (int) $l,
					'url'     => $info['url'],
				);
			}
		}
		return $out;
	}

	/** A verse number: a link that opens its commentary when it has some, else plain. */
	public static function verse_number( $label, array $links ) {
		$label = (string) $label;
		if ( ! isset( $links[ $label ] ) ) {
			return esc_html( $label );
		}
		$l = $links[ $label ];
		return '<a class="pr-vn-comm" href="' . esc_url( $l['url'] ) . '" data-pcomm-book="' . esc_attr( $l['book'] ) . '" data-pcomm-chapter="' . (int) $l['chapter'] . '" data-pcomm-verse="' . (int) $l['verse'] . '" title="'
			/* translators: %s: verse number */
			. esc_attr( sprintf( __( 'Commentary on verse %s', 'prayer-rule' ), $label ) ) . '">' . esc_html( $label ) . '</a>';
	}

	/** For the Bible pages: the bibletr books a book is kept under (Brenton's Esdras II: Ezra and Nehemiah). */
	public static function book_names( $translation, array $book ) {
		$names = array();
		foreach ( BibleMap::chapters( $translation, $book ) as $n ) {
			foreach ( BibleMap::places( $translation, $book['code'], $n ) as $p ) {
				$names[ $p[0] ] = true;
			}
		}
		return array_keys( $names );
	}

	/** The introductions to a book in the commentary (chapter 0), as for at(). */
	public static function intros( $translation, array $book ) {
		$places = array();
		foreach ( self::book_names( $translation, $book ) as $name ) {
			$places[] = array( $name, 0 );
		}
		return self::at( $places );
	}

	/**
	 * For the Bible pages: the chapters of a book (as shown) with commentary: chapter => true. Read from the Patristic
	 * Commentary's own list in one query; empty if that plugin cannot give it.
	 */
	public static function chapters_with( $translation, array $book ) {
		if ( ! self::available() || ! is_callable( array( '\\PatComm\\Store', 'places' ) ) || ! is_callable( array( '\\PatComm\\Store', 'book' ) ) ) {
			return array();
		}
		$all  = \PatComm\Store::places();
		$keys = array();
		$out  = array();
		foreach ( BibleMap::chapters( $translation, $book ) as $n ) {
			foreach ( BibleMap::places( $translation, $book['code'], $n ) as $p ) {
				if ( ! array_key_exists( $p[0], $keys ) ) {
					$b             = \PatComm\Store::book( $p[0] );
					$keys[ $p[0] ] = $b ? $b['key'] : null;
				}
				if ( $keys[ $p[0] ] && ! empty( $all[ $keys[ $p[0] ] ][ (int) $p[1] ] ) ) {
					$out[ (int) $n ] = true;
				}
			}
		}
		return $out;
	}
}
