<?php
/**
 * The day a service belongs to: weekday, Julian date, days from Pascha,
 * Great Lent, and the saint named in the dismissal.
 *
 * The saints come from the Orthodox Liturgical Calendar plugin (OLCal)
 * when it is active; without it everything else still works and the
 * dismissal says "Saint N.". Nothing is written to the calendar.
 *
 * Days are liturgical days: Vespers belongs to the next day, so the
 * Vespers of Sunday's date is read on Saturday evening.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Calendar {

	const OPTION = 'prayer_rule_saints';   // "m-d" (Julian) => name as said in the dismissal

	/** Typikon signs, highest first (as the calendar ranks them). */
	const SIGNS = array( '🕀' => 1, '🕁' => 2, '🕂' => 3, '🕃' => 4, '🕄' => 5 );

	/** Is the calendar plugin there to ask? */
	public static function available() {
		return class_exists( '\\OLCal\\Engine' ) && class_exists( '\\OLCal\\Store' );
	}

	/** "YYYY-MM-DD" civil date => that date, or today (site time zone). */
	public static function civil( $iso ) {
		if ( is_string( $iso ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $iso;
		}
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Everything about one liturgical day (a civil date):
	 *   civil, weekday (0 = Sunday), day (weekday name key), julian (m, d, iso),
	 *   after_pascha, before_pascha, lent, label (movable-cycle name, with
	 *   the calendar), lines (the calendar's commemorations, plain text),
	 *   great (a great feast), saint (as named in the dismissal, or ''),
	 *   saint_source ('set' | 'calendar' | '').
	 */
	public static function day( $civil_iso ) {
		$civil = self::civil( $civil_iso );
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $civil ) );
		$jdn = self::gregorian_to_jdn( $y, $m, $d );
		list( $jy, $jm, $jd ) = self::jdn_to_julian( $jdn );
		$pascha = self::pascha_jdn( $jy );
		$prev   = $jdn >= $pascha ? $pascha : self::pascha_jdn( $jy - 1 );
		$next   = $jdn >= $pascha ? self::pascha_jdn( $jy + 1 ) : $pascha;
		$before = $jdn - $next;
		$wd     = ( $jdn + 1 ) % 7;
		$out    = array(
			'civil'         => $civil,
			'weekday'       => $wd,
			'day'           => Dismissals::day_key( $wd ),
			'julian'        => array(
				'm'   => $jm,
				'd'   => $jd,
				'iso' => sprintf( '%04d-%02d-%02d', $jy, $jm, $jd ),
			),
			'after_pascha'  => $jdn - $prev,
			'before_pascha' => $before,
			'lent'          => $before >= -48 && $before <= -8,   // Clean Monday to Lazarus Saturday
			'label'         => '',
			'lines'         => array(),
			'great'         => false,
			'saint'         => '',
			'saint_source'  => '',
			'rank'          => 9,    // highest Typikon sign of the day: 1 great feast, 2 vigil, 3 polyeleos, 4 doxology, 5 six stichera
			'fast_label'    => '',   // the calendar's own name for the fast, when it has one
		);
		$top = null;
		if ( self::available() ) {
			$day          = \OLCal\Engine::day( $jdn );
			$out['label']      = (string) ( $day['week']['label'] ?? '' );
			$out['fast_label'] = (string) ( $day['fast_label'] ?? '' );
			foreach ( \OLCal\Store::for_day( $day ) as $item ) {
				$out['lines'][] = trim( ( $item['sign'] ? $item['sign'] . ' ' : '' ) . self::plain( $item['body'] ) );
				$out['great']   = $out['great'] || '🕀' === $item['sign'];
				$out['rank']    = min( $out['rank'], self::rank( $item ) );
				if ( 'menaion' === $item['cycle'] && ( ! $top || self::rank( $item ) < self::rank( $top ) ) ) {
					$top = $item;
				}
			}
		}
		$set = self::names()[ $jm . '-' . $jd ] ?? '';
		if ( '' !== $set ) {
			$out['saint']        = Render::god_bearer( $set );
			$out['saint_source'] = 'set';
		} elseif ( $top ) {
			$out['saint']        = self::dismissal_name( $top['body'] );
			$out['saint_source'] = '' !== $out['saint'] ? 'calendar' : '';
		}
		return $out;
	}

	/** Lower is higher: great feast 1 … six stichera 5, no sign 9. */
	private static function rank( $item ) {
		return self::SIGNS[ $item['sign'] ] ?? 9;
	}

	/** Calendar text as plain text. */
	public static function plain( $html ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
		return Render::god_bearer( trim( preg_replace( '/\s+/u', ' ', $text ) ) );
	}

	/**
	 * A calendar line made into the words of a dismissal ("and of … , whose
	 * day it is"). "Holy martyr Mamas." => "the holy martyr Mamas";
	 * "Commemoration of our venerable father Symeon …" => "our venerable
	 * father Symeon …". Only the first sentence is used.
	 */
	public static function dismissal_name( $html ) {
		$text = self::plain( $html );
		$text = preg_replace( '/[\x{1F540}-\x{1F544}]\s*/u', '', $text );                     // Typikon signs
		$text = self::without_dates( $text );
		$text = preg_replace( '/^(the\s+)?(commemoration|memory)\s+of\s+/iu', '', $text );
		// First sentence: stop at ". " unless the word before is an abbreviation (St., Ven., Sts.).
		$parts = preg_split( '/(?<!\bSt)(?<!\bSts)(?<!\bSs)(?<!\bVen)(?<!\b[A-Z])[.;]\s/u', $text, 2 );
		$text  = preg_replace( '/[\s.;,…]+$/u', '', $parts[0] );
		// "…, and the commemoration of the great earthquake" is not a saint.
		$text = preg_replace( '/,?\s+and\s+(the\s+)?(commemoration|memory)\s+of\s.*$/iu', '', $text );
		if ( preg_match( '/^(our|the)\s/iu', $text ) ) {
			$text = mb_strtolower( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
		} elseif ( preg_match( '/^holy\s/iu', $text ) ) {
			$text = 'the h' . mb_substr( $text, 1 );
		} elseif ( preg_match( '/^St\.\s/u', $text ) ) {
			$text = 'Saint ' . mb_substr( $text, 4 );
		}
		return $text;
	}

	/**
	 * The calendar's dates in parentheses, "(1392)" or "(† c. 1380)", taken
	 * out: they are for the calendar, not for prayers.
	 */
	public static function without_dates( $text ) {
		$text = preg_replace( '/\s*\([^()]*\d[^()]*\)/u', '', $text );
		return trim( preg_replace( '/\s+([,.;:])/u', '$1', preg_replace( '/\s{2,}/u', ' ', $text ) ) );
	}

	/** Names set by hand on the Saints screen. */
	public static function names() {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/** $posted: "m-d" => name; empty removes it. */
	public static function save_names( $posted ) {
		$names = self::names();
		foreach ( (array) $posted as $key => $name ) {
			if ( ! preg_match( '/^(1[0-2]|[1-9])-([1-9]|[12]\d|3[01])$/', (string) $key ) ) {
				continue;
			}
			$name = trim( sanitize_text_field( $name ) );
			if ( '' === $name ) {
				unset( $names[ $key ] );
			} else {
				$names[ $key ] = $name;
			}
		}
		update_option( self::OPTION, $names );
	}

	/** Commemorations of a Julian month: day => list of plain lines, and the derived name. */
	public static function month( $jm ) {
		$days = array();
		if ( ! self::available() ) {
			return $days;
		}
		$tops = array();
		foreach ( \OLCal\Store::menaion( (int) $jm ) as $item ) {
			$d                     = (int) $item['jday'];
			$days[ $d ]['lines'][] = trim( ( $item['sign'] ? $item['sign'] . ' ' : '' ) . self::plain( $item['body'] ) );
			if ( ! isset( $tops[ $d ] ) || self::rank( $item ) < self::rank( $tops[ $d ] ) ) {
				$tops[ $d ] = $item;
			}
		}
		foreach ( $tops as $d => $item ) {
			$days[ $d ]['name']  = self::dismissal_name( $item['body'] );
			$days[ $d ]['great'] = '🕀' === $item['sign'];
		}
		ksort( $days );
		return $days;
	}

	// ------------------------------------------------------------ date arithmetic

	public static function gregorian_to_jdn( $y, $m, $d ) {
		$a  = intdiv( 14 - $m, 12 );
		$yy = $y + 4800 - $a;
		$mm = $m + 12 * $a - 3;
		return $d + intdiv( 153 * $mm + 2, 5 ) + 365 * $yy + intdiv( $yy, 4 ) - intdiv( $yy, 100 ) + intdiv( $yy, 400 ) - 32045;
	}

	public static function julian_to_jdn( $y, $m, $d ) {
		$a  = intdiv( 14 - $m, 12 );
		$yy = $y + 4800 - $a;
		$mm = $m + 12 * $a - 3;
		return $d + intdiv( 153 * $mm + 2, 5 ) + 365 * $yy + intdiv( $yy, 4 ) - 32083;
	}

	public static function jdn_to_julian( $jdn ) {
		$c = $jdn + 32082;
		$d = intdiv( 4 * $c + 3, 1461 );
		$e = $c - intdiv( 1461 * $d, 4 );
		$m = intdiv( 5 * $e + 2, 153 );
		return array( $d - 4800 + intdiv( $m, 10 ), $m + 3 - 12 * intdiv( $m, 10 ), $e - intdiv( 153 * $m + 2, 5 ) + 1 );
	}

	public static function jdn_to_gregorian( $jdn ) {
		$a = $jdn + 32044;
		$b = intdiv( 4 * $a + 3, 146097 );
		$c = $a - intdiv( 146097 * $b, 4 );
		$d = intdiv( 4 * $c + 3, 1461 );
		$e = $c - intdiv( 1461 * $d, 4 );
		$m = intdiv( 5 * $e + 2, 153 );
		return array( 100 * $b + $d - 4800 + intdiv( $m, 10 ), $m + 3 - 12 * intdiv( $m, 10 ), $e - intdiv( 153 * $m + 2, 5 ) + 1 );
	}

	/** Pascha of a Julian year, as a day number. */
	public static function pascha_jdn( $jy ) {
		$a = $jy % 4;
		$b = $jy % 7;
		$c = $jy % 19;
		$d = ( 19 * $c + 15 ) % 30;
		$e = ( 2 * $a + 4 * $b - $d + 34 ) % 7;
		$f = intdiv( $d + $e + 114, 31 );
		$g = ( $d + $e + 114 ) % 31 + 1;
		return self::julian_to_jdn( $jy, $f, $g );
	}

	/** The civil date before $civil (for "read on … evening"). */
	public static function eve( $civil ) {
		list( $y, $m, $d ) = array_map( 'intval', explode( '-', $civil ) );
		list( $py, $pm, $pd ) = self::jdn_to_gregorian( self::gregorian_to_jdn( $y, $m, $d ) - 1 );
		return sprintf( '%04d-%02d-%02d', $py, $pm, $pd );
	}

	public static function julian_label( $julian ) {
		global $wp_locale;
		return $wp_locale->get_month( $julian['m'] ) . ' ' . $julian['d'];
	}
}
