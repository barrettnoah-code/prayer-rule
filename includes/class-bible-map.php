<?php
/**
 * The Bible pages' bookkeeping: book addresses, and where the same place is in another
 * translation (for the translation switch and the side-by-side view).
 *
 * Addresses use one name per book whatever the translation (/bible/dra/1-samuel/ is the
 * Douay-Rheims "1 Kings"), so switching translation keeps the address and never confuses the
 * Douay's "1 Esdras" (Ezra) with the KJV's 1 Esdras. Places are matched through the same
 * chapter mapping as the commentary (Commentary::places), so the KJV's Psalm 51 is the
 * Douay's and Brenton's Psalm 50, Brenton's Esdras II 15 is Nehemiah 5, and so on.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BibleMap {

	/** Short names for the switch. */
	const SHORT = array(
		'kjv'     => 'KJV',
		'dra'     => 'Douay-Rheims',
		'wyc'     => 'Wycliffe',
		'brenton' => 'Brenton (LXX)',
	);

	/**
	 * A heading for books grouped "deutero" that come after the Old Testament (the KJV's
	 * Apocrypha). Translations not listed show them under the Old Testament in their order.
	 */
	const DEUTERO_HEADING = array(
		'kjv' => 'Apocrypha',
	);

	/** Translations whose Psalms count the title as verse 1 (as in the Greek). */
	const TITLED_PSALMS = array( 'dra', 'brenton' );

	/** Where nothing else matches: code, chapter => code, chapter (one way). */
	const NEAR = array(
		'S3Y 1' => array( array( 'DAN', 3 ), array( 'DAG', 3 ) ),   // the Song of the Three is in Daniel 3
		'BAR 6' => array( array( 'LJE', 1 ) ),                      // Baruch 6 is the Letter of Jeremiah
		'LJE 1' => array( array( 'BAR', 6 ) ),
	);

	/** The same book under another code. */
	const SAME_BOOK = array(
		'DAN' => 'DAG',
		'DAG' => 'DAN',
		'EST' => 'ESG',
		'ESG' => 'EST',
	);

	private static $reverse = array();

	/** Short forms for "Go to", besides each Bible's own book names and the standard names. */
	const ABBR = array(
		'GEN' => 'gen ge gn',            'EXO' => 'ex exo exod',          'LEV' => 'lev le lv',
		'NUM' => 'num nu nm nb',         'DEU' => 'deut deu de dt',       'JOS' => 'josh jos jsh',
		'JDG' => 'judg jdg jg',          'RUT' => 'ruth ru rt',
		'1SA' => '1sam 1sa 1sm 1kingdoms 1kgdms 1reigns',                  '2SA' => '2sam 2sa 2sm 2kingdoms 2kgdms 2reigns',
		'1KI' => '1kgs 1ki 3kings 3kgs 3kingdoms 3kgdms 3reigns',          '2KI' => '2kgs 2ki 4kings 4kgs 4kingdoms 4kgdms 4reigns',
		'1CH' => '1chr 1ch 1chron 1par 1paralipomenon',                    '2CH' => '2chr 2ch 2chron 2par 2paralipomenon',
		'EZR' => 'ezra ezr',             'NEH' => 'neh ne nehemias',      'EST' => 'esth est es',
		'JOB' => 'job jb',               'PSA' => 'ps psa pss psalm',     'PRO' => 'prov pro pr prv',
		'ECC' => 'eccl ecc eccles qoh qoheleth', 'SNG' => 'song sng sos canticles cant songofsongs canticleofcanticles',
		'ISA' => 'isa is',               'JER' => 'jer je jr',            'LAM' => 'lam la',
		'EZK' => 'ezek eze ezk',         'DAN' => 'dan da dn',            'DAG' => 'dan da dn daniel',
		'HOS' => 'hos ho osee',          'JOL' => 'joel jl',              'AMO' => 'amos am',
		'OBA' => 'obad ob abdias',       'JON' => 'jonah jon jonas',      'MIC' => 'mic mi micheas',
		'NAM' => 'nah na',               'HAB' => 'hab hb',               'ZEP' => 'zeph zep sophonias',
		'HAG' => 'hag hg aggeus',        'ZEC' => 'zech zec zacharias',   'MAL' => 'mal ml malachias',
		'1ES' => '1esd 1es 3ezra 3esdras',                                  '2ES' => '2esd 2es 4ezra 4esdras',
		'TOB' => 'tob tb tobias',        'JDT' => 'jdt jth',              'ESG' => 'addesth gkesth',
		'WIS' => 'wis ws',               'SIR' => 'sir ecclus ecclesiasticus', 'BAR' => 'bar',
		'LJE' => 'epjer letjer lje',     'S3Y' => 'songofthree azariah prazar', 'SUS' => 'sus',
		'BEL' => 'bel',                  'MAN' => 'prman man manasseh manasses',
		'1MA' => '1macc 1ma 1mac',       '2MA' => '2macc 2ma 2mac',       '3MA' => '3macc 3ma',      '4MA' => '4macc 4ma',
		'MAT' => 'matt mat mt',          'MRK' => 'mark mrk mk mr',       'LUK' => 'luke luk lk lu', 'JHN' => 'john jhn jn joh',
		'ACT' => 'acts act ac',          'ROM' => 'rom ro rm',            '1CO' => '1cor 1co',       '2CO' => '2cor 2co',
		'GAL' => 'gal ga',               'EPH' => 'eph',                  'PHP' => 'phil php pp',    'COL' => 'col',
		'1TH' => '1thess 1th',           '2TH' => '2thess 2th',           '1TI' => '1tim 1ti',       '2TI' => '2tim 2ti',
		'TIT' => 'titus tit',            'PHM' => 'philem phlm phm',      'HEB' => 'heb',            'JAS' => 'jas jm james',
		'1PE' => '1pet 1pe 1pt',         '2PE' => '2pet 2pe 2pt',         '1JN' => '1john 1jn 1jo',  '2JN' => '2john 2jn 2jo',
		'3JN' => '3john 3jn 3jo',        'JUD' => 'jude jud',             'REV' => 'rev re rv apoc apocalypse revelation revelations',
	);

	/** A name or what someone typed, made comparable: "1st Kings", "I Kings", "Kings I" → "1kings". */
	public static function name_key( $s ) {
		$s = strtolower( remove_accents( trim( (string) $s ) ) );
		$s = str_replace( array( '.', '&', ',' ), array( '', ' and ', ' ' ), $s );
		$s = preg_replace( '/\s+/', ' ', $s );
		$s = preg_replace( '/^(\d)(st|nd|rd|th)\b/', '$1', $s );
		$s = preg_replace_callback( '/^(first|second|third|fourth) /', function ( $m ) {
			return array_search( $m[1], array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth' ), true ) . ' ';
		}, $s );
		$s = preg_replace_callback( '/^(iv|iii|ii|i) (?=[a-z])/', function ( $m ) {
			return array( 'i' => '1', 'ii' => '2', 'iii' => '3', 'iv' => '4' )[ $m[1] ] . ' ';
		}, $s );
		$s = preg_replace_callback( '/^([a-z].*?) (iv|iii|ii|i)$/', function ( $m ) {
			return array( 'i' => '1', 'ii' => '2', 'iii' => '3', 'iv' => '4' )[ $m[2] ] . $m[1];
		}, $s );
		$s = preg_replace( '/^(the|book of|the book of|the gospel according to|gospel of|st|saint) /', '', $s );
		return str_replace( ' ', '', $s );
	}

	/** Every name a book of translation $t answers to: list of array( key, code, rank ) (lower rank = preferred). */
	private static function names( $t ) {
		static $cache = array();
		if ( isset( $cache[ $t ] ) ) {
			return $cache[ $t ];
		}
		$out = array();
		$add = function ( $name, $code, $rank ) use ( &$out ) {
			$name = (string) $name;
			$keys = array( $name, preg_replace( '/\s*\(.*?\)\s*/', ' ', $name ) );
			if ( preg_match( '/\((.*?)\)/', $name, $m ) ) {
				$keys[] = $m[1];
			}
			foreach ( $keys as $k ) {
				$k = self::name_key( $k );
				if ( '' !== $k ) {
					$out[] = array( $k, $code, $rank );
				}
			}
		};
		$here = self::translation( $t );
		foreach ( $here ? $here['books'] : array() as $b ) {
			$add( $b['name'], $b['code'], 0 );                                  // this Bible's own names first
		}
		foreach ( self::translations() as $tr ) {
			foreach ( $tr['books'] as $b ) {
				$add( $b['name'], $b['code'], 2 );                              // then the other Bibles' names
			}
		}
		foreach ( Commentary::BOOKS as $code => $name ) {
			$add( $name, $code, 1 );
		}
		foreach ( self::ABBR as $code => $list ) {
			foreach ( explode( ' ', $list ) as $a ) {
				$out[] = array( $a, $code, 1 );
			}
		}
		$cache[ $t ] = $out;
		return $out;
	}

	/**
	 * Read a reference typed into "Go to" ("Rom 8:28", "1 Cor 13:4-7", "Ps 50", "jn 3", or just
	 * "8:28" / "12" in the book being read) for translation $t.
	 *
	 * @return array ok: code, c, v1, v2 (0 when none), label; else: error (and code/t_other when
	 *               the book is only in another Bible).
	 */
	public static function parse_ref( $q, $t, $cur_code = '', $cur_c = 0 ) {
		$q = trim( preg_replace( '/\s+/u', ' ', (string) $q ) );
		if ( '' === $q ) {
			return array( 'error' => __( 'Type a book and chapter, e.g. Rom 8 or Rom 8:28.', 'prayer-rule' ) );
		}
		if ( ! preg_match( '/^((?:[1-4]|i{1,3}|iv)?\s*[^\d\s:.,–-][^\d:]*?)?\s*(\d+)?\s*(?:[:.,]\s*(\d+)\s*(?:[-–]\s*(\d+))?)?\s*$/iu', $q, $m ) ) {
			return array( 'error' => __( 'That is not a reference I can read; try e.g. Rom 8:28.', 'prayer-rule' ) );
		}
		$book_q = trim( $m[1] ?? '' );
		$c      = isset( $m[2] ) && '' !== $m[2] ? (int) $m[2] : 0;
		$v1     = isset( $m[3] ) && '' !== $m[3] ? (int) $m[3] : 0;
		$v2     = isset( $m[4] ) && '' !== $m[4] ? (int) $m[4] : 0;
		if ( '' === $book_q ) {                              // "8:28": the book being read
			if ( ! $cur_code || ! $c ) {
				return array( 'error' => __( 'Type a book too, e.g. Rom 8:28.', 'prayer-rule' ) );
			}
			$code = $cur_code;
		} else {
			$key   = self::name_key( $book_q );
			$best  = null;
			$order = array_flip( array_map( function ( $b ) {
				return $b['code'];
			}, self::translation( $t )['books'] ?? array() ) );
			foreach ( self::names( $t ) as $n ) {
				$exact = $n[0] === $key;
				if ( ! $exact && 0 !== strpos( $n[0], $key ) ) {
					continue;
				}
				$here  = isset( $order[ $n[1] ] );
				$score = array( $here ? 0 : 1, $exact ? 0 : 1, $n[2], $order[ $n[1] ] ?? 999 );
				if ( null === $best || $score < $best[0] ) {
					$best = array( $score, $n[1] );
				}
			}
			if ( ! $best || strlen( $key ) < 2 && ! ctype_digit( substr( $key, 0, 1 ) ) ) {
				/* translators: %s: what was typed */
				return array( 'error' => sprintf( __( 'No book called “%s”.', 'prayer-rule' ), $book_q ) );
			}
			$code = $best[1];
			if ( ! self::book( $t, $code ) ) {
				$same = self::SAME_BOOK[ $code ] ?? '';
				if ( $same && self::book( $t, $same ) ) {
					$code = $same;
				} else {
					foreach ( self::translations() as $tr ) {
						if ( self::book( $tr['id'], $code ) ) {
							$b = self::book( $tr['id'], $code );
							// Kept elsewhere in this Bible (Brenton's Esdras II holds Nehemiah)?
							$f = self::find( $tr['id'], $code, $c ? $c : self::chapters( $tr['id'], $b )[0], $t );
							if ( isset( $f['c'] ) && $f['code'] !== $code ) {
								$here = self::book( $t, $f['code'] );
								return array(
									'code'  => $f['code'],
									'c'     => $f['c'],
									'v1'    => $v1,
									'v2'    => $v2 >= $v1 ? $v2 : 0,
									'label' => self::label( $here, $f['c'] ) . ( $v1 ? ':' . $v1 . ( $v2 ? '–' . $v2 : '' ) : '' ),
									/* translators: 1: e.g. Nehemiah, 2: e.g. Esdras II 15 */
									'note'  => sprintf( __( '%1$s is in %2$s here.', 'prayer-rule' ), $b['name'], self::label( $here, $f['c'] ) ),
								);
							}
							return array(
								/* translators: 1: book, 2: translation, 3: another translation */
								'error'   => sprintf( __( '%1$s is not in the %2$s; it is in the %3$s.', 'prayer-rule' ), $b['name'], self::translation( $t )['name'], $tr['name'] ),
								'code'    => $code,
								't_other' => $tr['id'],
							);
						}
					}
					return array( 'error' => __( 'That book is not in these Bibles.', 'prayer-rule' ) );
				}
			}
		}
		$book = self::book( $t, $code );
		$chs  = self::chapters( $t, $book );
		if ( ! $c ) {
			$c = $chs[0];
		}
		$same = self::SAME_BOOK[ $code ] ?? '';
		if ( ! in_array( $c, $chs, true ) && $same && self::has_chapter( $t, $same, $c ) ) {
			$code = $same;                                   // "Esther 11" in the KJV: the Rest of Esther
			$book = self::book( $t, $code );
			$chs  = self::chapters( $t, $book );
		}
		if ( ! in_array( $c, $chs, true ) ) {
			/* translators: 1: book, 2: number of chapters */
			return array( 'error' => sprintf( _n( '%1$s has %2$d chapter.', '%1$s has %2$d chapters.', count( $chs ), 'prayer-rule' ), $book['name'], count( $chs ) ) );
		}
		if ( $v2 && $v2 < $v1 ) {
			$v2 = 0;
		}
		$label = self::label( $book, $c ) . ( $v1 ? ':' . $v1 . ( $v2 ? '–' . $v2 : '' ) : '' );
		return array( 'code' => $code, 'c' => $c, 'v1' => $v1, 'v2' => $v2, 'label' => $label );
	}

	/**
	 * Show the KJV's and Wycliffe's Psalms in their own (Hebrew) numbering? Off by default:
	 * the Bible pages number every Psalter as in the Septuagint, as the Church reads it
	 * (?numbering=hebrew turns it on for one view; BiblePages sets it).
	 */
	public static $hebrew = false;

	/** Are this translation's Psalms shown renumbered as in the Greek? */
	public static function greek_view( $t ) {
		return ! self::$hebrew && in_array( $t, Commentary::HEBREW_PSALMS, true );
	}

	/** Does this translation number the Psalms as in the Hebrew (KJV, Wycliffe)? */
	public static function hebrew_psalter( $t ) {
		return in_array( $t, Commentary::HEBREW_PSALMS, true );
	}

	/**
	 * A Greek psalm as parts of the Hebrew-numbered Bible: list of array( psalm, first
	 * verse, last verse ) (0, 0: the whole psalm). Split where the Prayer Rule's Psalter
	 * splits: Psalm 116 after verse 9, Psalm 147 after verse 11.
	 */
	public static function hebrew_parts( $g ) {
		$g = (int) $g;
		if ( $g < 1 || $g > 150 ) {
			return array();
		}
		if ( $g <= 8 || $g >= 148 ) {
			return array( array( $g, 0, 0 ) );
		}
		$fixed = array(
			9   => array( array( 9, 0, 0 ), array( 10, 0, 0 ) ),
			113 => array( array( 114, 0, 0 ), array( 115, 0, 0 ) ),
			114 => array( array( 116, 1, 9 ) ),
			115 => array( array( 116, 10, 999 ) ),
			146 => array( array( 147, 1, 11 ) ),
			147 => array( array( 147, 12, 999 ) ),
		);
		return $fixed[ $g ] ?? array( array( $g + 1, 0, 0 ) );
	}

	/** "Psalm 51", "Psalms 9–10", "Psalm 116:10–19": where a Greek psalm is in the Hebrew numbering. */
	public static function hebrew_label( $g, $t = '' ) {
		$parts = self::hebrew_parts( $g );
		if ( ! $parts ) {
			return '';
		}
		if ( count( $parts ) > 1 ) {
			return sprintf( /* translators: 1, 2: psalm numbers */ __( 'Psalms %1$d–%2$d', 'prayer-rule' ), $parts[0][0], $parts[1][0] );
		}
		$p = $parts[0];
		if ( ! $p[1] ) {
			return sprintf( /* translators: %d: psalm number */ __( 'Psalm %d', 'prayer-rule' ), $p[0] );
		}
		$last = $p[2];
		if ( 999 === $last && $t ) {
			$c    = Bible::chapter( $t, 'PSA', $p[0] );
			$last = $c ? count( $c['verses'] ) : $last;
		}
		return sprintf( /* translators: 1: psalm, 2: first verse, 3: last verse */ __( 'Psalm %1$d:%2$d–%3$d', 'prayer-rule' ), $p[0], $p[1], $last );
	}

	/** A book's chapter numbers as shown (the Psalms of the KJV and Wycliffe: 1–150 as in the Greek). */
	public static function chapters( $t, array $book ) {
		if ( 'PSA' === $book['code'] && self::greek_view( $t ) ) {
			return range( 1, 150 );
		}
		return array_map( 'intval', $book['chapters'] );
	}

	/** Where the commentary on a chapter as shown is kept (Commentary::places, with the Greek view). */
	public static function places( $t, $code, $c ) {
		if ( 'PSA' === $code && self::greek_view( $t ) ) {
			return array( array( 'Psalms', (int) $c ) );
		}
		return Commentary::places( $t, $code, $c );
	}

	/**
	 * A chapter's text as shown: array( 'title', 'verses' => list of array( label, text ),
	 * 'marks' => verse index => a line to show before it ), or null. A Greek psalm made of
	 * Hebrew ones is put together and its verses counted from 1, as in the Psalter.
	 */
	public static function text( $t, $code, $c ) {
		if ( 'PSA' !== $code || ! self::greek_view( $t ) ) {
			$ch = Bible::chapter( $t, $code, $c );
			return $ch ? $ch + array( 'marks' => array() ) : null;
		}
		$parts = self::hebrew_parts( $c );
		$out   = array(
			'title'  => '',
			'verses' => array(),
			'marks'  => array(),
		);
		foreach ( $parts as $i => $p ) {
			$h = Bible::chapter( $t, 'PSA', $p[0] );
			if ( ! $h ) {
				return null;
			}
			$verses = $p[1] ? array_slice( $h['verses'], $p[1] - 1, $p[2] - $p[1] + 1 ) : $h['verses'];
			if ( 0 === $i ) {
				$out['title'] = ( ! $p[1] || 1 === $p[1] ) ? $h['title'] : '';
			} else {
				/* translators: %d: psalm number in the Hebrew numbering */
				$out['marks'][ count( $out['verses'] ) ] = sprintf( __( 'Psalm %d in the Hebrew numbering', 'prayer-rule' ), $p[0] ) . ( '' !== $h['title'] ? ': ' . $h['title'] : '' );
			}
			foreach ( $verses as $v ) {
				$out['verses'][] = array( (string) ( count( $out['verses'] ) + 1 ), $v[1] );
			}
		}
		return $out;
	}

	public static function short( $t ) {
		if ( isset( self::SHORT[ $t ] ) ) {
			return self::SHORT[ $t ];
		}
		$tr = Bible::translation( $t );
		return $tr ? $tr['name'] : $t;
	}

	/** The loaded translations, in the order of SHORT (then any others): list of index entries. */
	public static function translations() {
		$loaded = Bible::translations();
		$list   = array_values(
			array_filter(
				Bible::index(),
				function ( $t ) use ( $loaded ) {
					return isset( $loaded[ $t['id'] ] );
				}
			)
		);
		$order = array_flip( array_keys( self::SHORT ) );
		usort(
			$list,
			function ( $a, $b ) use ( $order ) {
				return ( $order[ $a['id'] ] ?? 99 ) <=> ( $order[ $b['id'] ] ?? 99 );
			}
		);
		return $list;
	}

	public static function translation( $t ) {
		return isset( Bible::translations()[ $t ] ) ? Bible::translation( $t ) : null;
	}

	/** A book's address part ("romans", "1-samuel", "additions-to-esther"). */
	public static function slug( $t, $code ) {
		if ( 'ESG' === $code ) {
			return 'brenton' === $t ? 'esther' : 'additions-to-esther';   // Brenton's is the whole Greek Esther
		}
		$name = Commentary::BOOKS[ $code ] ?? $code;
		return sanitize_title( $name );
	}

	/** A book of a translation: its index entry, or null. */
	public static function book( $t, $code ) {
		$tr = self::translation( $t );
		foreach ( $tr ? $tr['books'] : array() as $b ) {
			if ( $b['code'] === $code ) {
				return $b;
			}
		}
		return null;
	}

	public static function book_by_slug( $t, $slug ) {
		$tr = self::translation( $t );
		foreach ( $tr ? $tr['books'] : array() as $b ) {
			if ( self::slug( $t, $b['code'] ) === $slug ) {
				return $b;
			}
		}
		return null;
	}

	public static function has_chapter( $t, $code, $c ) {
		$b = self::book( $t, $code );
		return $b && in_array( (int) $c, self::chapters( $t, $b ), true );
	}

	/** "Psalm 50", "Romans 8". */
	public static function label( $book, $c ) {
		return 'PSA' === $book['code'] ? sprintf( /* translators: %d: psalm number */ __( 'Psalm %d', 'prayer-rule' ), $c ) : $book['name'] . ' ' . (int) $c;
	}

	/** The chapter before and after, across books, in the translation's order: array( prev, next ) of array( book, c ) or null. */
	public static function neighbours( $t, $code, $c ) {
		$flat = array();
		$tr   = self::translation( $t );
		foreach ( $tr ? $tr['books'] : array() as $b ) {
			foreach ( self::chapters( $t, $b ) as $n ) {
				$flat[] = array( $b, (int) $n );
			}
		}
		foreach ( $flat as $i => $one ) {
			if ( $one[0]['code'] === $code && $one[1] === (int) $c ) {
				return array( $flat[ $i - 1 ] ?? null, $flat[ $i + 1 ] ?? null );
			}
		}
		return array( null, null );
	}

	/** Place key => first array( code, chapter ) of the translation with that place. */
	private static function reverse( $t ) {
		$key_t = $t . ( self::greek_view( $t ) ? '|greek' : '' );
		if ( ! isset( self::$reverse[ $key_t ] ) ) {
			$map = array();
			$tr  = self::translation( $t );
			foreach ( $tr ? $tr['books'] : array() as $b ) {
				foreach ( self::chapters( $t, $b ) as $n ) {
					foreach ( self::places( $t, $b['code'], $n ) as $p ) {
						$key = $p[0] . '|' . $p[1];
						if ( ! isset( $map[ $key ] ) ) {
							$map[ $key ] = array( $b['code'], (int) $n );
						}
					}
				}
			}
			self::$reverse[ $key_t ] = $map;
		}
		return self::$reverse[ $key_t ];
	}

	/** The first chapter of translation $t at one of these places, as array( code, c ), or null. */
	public static function locate( $t, array $places ) {
		$rev = self::reverse( $t );
		foreach ( $places as $p ) {
			if ( isset( $rev[ $p[0] . '|' . $p[1] ] ) ) {
				return $rev[ $p[0] . '|' . $p[1] ];
			}
		}
		return null;
	}

	/**
	 * Where chapter $c of book $code in translation $from is in translation $to:
	 * array( 'code', 'c' ) for a chapter, array( 'code' ) for the book only (no such chapter),
	 * or array() when the book is not in that translation.
	 */
	public static function find( $from, $code, $c, $to ) {
		$c = (int) $c;
		if ( $from === $to ) {
			return array( 'code' => $code, 'c' => $c );
		}
		$places = self::places( $from, $code, $c );
		if ( self::has_chapter( $to, $code, $c ) && self::places( $to, $code, $c ) === $places ) {
			return array( 'code' => $code, 'c' => $c );             // numbered alike (KJV and Wycliffe Psalm 10)
		}
		$rev = self::reverse( $to );
		foreach ( $places as $p ) {
			$key = $p[0] . '|' . $p[1];
			if ( isset( $rev[ $key ] ) ) {
				return array( 'code' => $rev[ $key ][0], 'c' => $rev[ $key ][1] );
			}
		}
		if ( self::has_chapter( $to, $code, $c ) ) {
			return array( 'code' => $code, 'c' => $c );
		}
		foreach ( self::NEAR[ $code . ' ' . $c ] ?? array() as $near ) {
			if ( self::has_chapter( $to, $near[0], $near[1] ) ) {
				return array( 'code' => $near[0], 'c' => $near[1] );
			}
		}
		$same = self::SAME_BOOK[ $code ] ?? '';
		if ( $same && self::has_chapter( $to, $same, $c ) ) {
			return array( 'code' => $same, 'c' => $c );
		}
		if ( self::book( $to, $code ) ) {
			return array( 'code' => $code );
		}
		if ( $same && self::book( $to, $same ) ) {
			return array( 'code' => $same );
		}
		return array();
	}

	/**
	 * Can two chapters be shown verse beside verse? Only when they are the same place in
	 * the commentary's mapping, the Psalms count their titles alike, and the verse counts
	 * are close (Daniel 3 with and without the Song of the Three is not).
	 */
	public static function aligned( $ta, $code_a, $ca, array $va, $tb, $code_b, $cb, array $vb ) {
		$pa = self::places( $ta, $code_a, $ca );
		if ( ! $pa || self::places( $tb, $code_b, $cb ) !== $pa ) {
			return false;
		}
		if ( 'PSA' === $code_a && self::greek_view( $ta ) !== self::greek_view( $tb ) ) {
			return false;                    // one renumbered as in the Greek, the other not
		}
		if ( 'PSA' === $code_a && in_array( $ta, self::TITLED_PSALMS, true ) !== in_array( $tb, self::TITLED_PSALMS, true ) ) {
			return false;
		}
		$count = function ( $verses ) {
			return count(
				array_filter(
					$verses,
					function ( $v ) {
						return ctype_digit( (string) $v[0] );
					}
				)
			);
		};
		return abs( $count( $va ) - $count( $vb ) ) <= 2;
	}

	/** The psalm's number in the other numbering, as words: "51 in the Hebrew numbering", or ''. */
	public static function psalm_other( $t, $n ) {
		$n = (int) $n;
		if ( self::hebrew_psalter( $t ) ) {
			return '';                                   // said in the numbering note instead
		}
		if ( in_array( $t, Commentary::HEBREW_PSALMS, true ) ) {
			$other = Commentary::greek_psalms( $n );
			/* translators: %s: psalm number(s) */
			return $other !== array( $n ) ? sprintf( __( '%s in the Greek numbering', 'prayer-rule' ), implode( '–', $other ) ) : '';
		}
		if ( $n > 150 ) {
			return '';
		}
		$other = array();
		for ( $h = 1; $h <= 150; $h++ ) {
			if ( in_array( $n, Commentary::greek_psalms( $h ), true ) ) {
				$other[] = $h;
			}
		}
		/* translators: %s: psalm number(s) */
		return $other !== array( $n ) ? sprintf( __( '%s in the Hebrew numbering', 'prayer-rule' ), implode( '–', $other ) ) : '';
	}

	/** Brenton's chapters kept in another order than the other Bibles'. */
	public static function reordered( $t, $code, $c ) {
		return 'brenton' === $t && ( ( 'JER' === $code && $c >= 25 ) || ( 'PRO' === $code && $c >= 24 ) );
	}
}
