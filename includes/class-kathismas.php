<?php
/**
 * The twenty kathismas and their three stases, editable in the admin.
 *
 * Each stasis is a list of pieces: array( psalm, from, to ), where from/to
 * are verses (0 = the whole psalm). Typed form, one kathisma per line:
 *   17: 118:1-72 | 118:73-131 | 118:132-176
 *   1: 1-3 | 4-6 | 7-8
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kathismas {

	const OPTION = 'prayer_rule_kathismas';

	/** The standard division, as typed. */
	const STANDARD = "1: 1-3 | 4-6 | 7-8
2: 9-10 | 11-13 | 14-16
3: 17 | 18-20 | 21-23
4: 24-26 | 27-29 | 30-31
5: 32-33 | 34-35 | 36
6: 37-39 | 40-42 | 43-45
7: 46-48 | 49-50 | 51-54
8: 55-57 | 58-60 | 61-63
9: 64-66 | 67 | 68-69
10: 70-71 | 72-73 | 74-76
11: 77 | 78-80 | 81-84
12: 85-87 | 88 | 89-90
13: 91-93 | 94-96 | 97-100
14: 101-102 | 103 | 104
15: 105 | 106 | 107-108
16: 109-111 | 112-114 | 115-117
17: 118:1-72 | 118:73-131 | 118:132-176
18: 119-123 | 124-128 | 129-133
19: 134-136 | 137-139 | 140-142
20: 143-144 | 145-147 | 148-150";

	/** The typed division in use. */
	public static function text() {
		$saved = get_option( self::OPTION );
		return is_string( $saved ) && '' !== trim( $saved ) ? $saved : self::STANDARD;
	}

	/** All kathismas: number => array of 3 stases, each a list of pieces. */
	public static function all() {
		$parsed = self::parse( self::text() );
		return is_wp_error( $parsed ) ? self::parse( self::STANDARD ) : $parsed;
	}

	/** One kathisma (1-20) or null. */
	public static function get( $number ) {
		$all = self::all();
		return $all[ (int) $number ] ?? null;
	}

	/** Save typed text; returns true or a WP_Error describing the problem. */
	public static function save( $text ) {
		$parsed = self::parse( $text );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}
		update_option( self::OPTION, self::format( $parsed ) );
		return true;
	}

	public static function reset() {
		delete_option( self::OPTION );
	}

	/**
	 * Parse the typed form. Every kathisma 1-20 must appear once, with
	 * three stases; psalms 1-151, verse ranges only within one psalm.
	 */
	public static function parse( $text ) {
		$result = array();
		$lines  = preg_split( '/\r\n|\r|\n/', (string) $text );
		foreach ( $lines as $index => $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$where = sprintf( 'Line %d', $index + 1 );
			if ( ! preg_match( '/^(\d+)\s*:\s*(.+)$/', $line, $m ) ) {
				return new \WP_Error( 'prayer_rule_kathisma', "$where: start with the kathisma number and a colon, e.g. \"1: 1-3 | 4-6 | 7-8\"." );
			}
			$number = (int) $m[1];
			if ( $number < 1 || $number > 20 ) {
				return new \WP_Error( 'prayer_rule_kathisma', "$where: kathisma $number does not exist (1-20)." );
			}
			if ( isset( $result[ $number ] ) ) {
				return new \WP_Error( 'prayer_rule_kathisma', "$where: kathisma $number is listed twice." );
			}
			$stases = array_map( 'trim', explode( '|', $m[2] ) );
			if ( 3 !== count( $stases ) ) {
				return new \WP_Error( 'prayer_rule_kathisma', "$where: kathisma $number needs three stases separated by |." );
			}
			foreach ( $stases as $s => $stasis ) {
				$pieces = array();
				foreach ( array_filter( array_map( 'trim', explode( ',', $stasis ) ) ) as $part ) {
					$piece = self::parse_piece( $part );
					if ( ! $piece ) {
						return new \WP_Error( 'prayer_rule_kathisma', "$where: \"$part\" is not a psalm or range (e.g. 9-10, 17, 118:1-72)." );
					}
					$pieces = array_merge( $pieces, $piece );
				}
				if ( ! $pieces ) {
					return new \WP_Error( 'prayer_rule_kathisma', sprintf( '%s: stasis %d of kathisma %d is empty.', $where, $s + 1, $number ) );
				}
				$result[ $number ][ $s ] = $pieces;
			}
		}
		for ( $n = 1; $n <= 20; $n++ ) {
			if ( ! isset( $result[ $n ] ) ) {
				return new \WP_Error( 'prayer_rule_kathisma', "Kathisma $n is missing." );
			}
		}
		ksort( $result );
		return $result;
	}

	/** "9-10" => two whole psalms; "118:1-72" => one psalm, verses 1-72. */
	private static function parse_piece( $part ) {
		if ( preg_match( '/^(\d+):(\d+)\s*-\s*(\d+)$/', $part, $m ) ) {
			list( , $psalm, $from, $to ) = array_map( 'intval', $m );
			return ( self::valid_psalm( $psalm ) && $from >= 1 && $to >= $from )
				? array( array( $psalm, $from, $to ) ) : null;
		}
		if ( preg_match( '/^(\d+)(?:\s*-\s*(\d+))?$/', $part, $m ) ) {
			$first = (int) $m[1];
			$last  = isset( $m[2] ) ? (int) $m[2] : $first;
			if ( ! self::valid_psalm( $first ) || ! self::valid_psalm( $last ) || $last < $first ) {
				return null;
			}
			$pieces = array();
			for ( $p = $first; $p <= $last; $p++ ) {
				$pieces[] = array( $p, 0, 0 );
			}
			return $pieces;
		}
		return null;
	}

	private static function valid_psalm( $psalm ) {
		return $psalm >= 1 && $psalm <= 151;
	}

	/** Back to the typed form (whole psalms in a row are joined as a-b). */
	public static function format( $kathismas ) {
		$lines = array();
		foreach ( $kathismas as $number => $stases ) {
			$typed = array();
			foreach ( $stases as $pieces ) {
				$typed[] = self::format_stasis( $pieces );
			}
			$lines[] = $number . ': ' . implode( ' | ', $typed );
		}
		return implode( "\n", $lines );
	}

	private static function format_stasis( $pieces ) {
		$parts = array();
		$run   = null;
		foreach ( $pieces as $piece ) {
			list( $psalm, $from, $to ) = $piece;
			if ( $from ) {
				if ( $run ) {
					$parts[] = self::run_text( $run );
					$run     = null;
				}
				$parts[] = "$psalm:$from-$to";
			} elseif ( $run && $psalm === $run[1] + 1 ) {
				$run[1] = $psalm;
			} else {
				if ( $run ) {
					$parts[] = self::run_text( $run );
				}
				$run = array( $psalm, $psalm );
			}
		}
		if ( $run ) {
			$parts[] = self::run_text( $run );
		}
		return implode( ', ', $parts );
	}

	private static function run_text( $run ) {
		return $run[0] === $run[1] ? (string) $run[0] : $run[0] . '-' . $run[1];
	}

	/** Short label of a stasis, e.g. "Psalms 1-3" or "Psalm 118:1-72". */
	public static function label( $pieces ) {
		$text = self::format_stasis( $pieces );
		$many = count( $pieces ) > 1;
		return ( $many ? 'Psalms ' : 'Psalm ' ) . $text;
	}
}
