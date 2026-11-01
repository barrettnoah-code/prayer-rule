<?php
/**
 * Fasting for a day, from the Home Ustav (data/ustav.json "fasting", from
 * tools/ustav/fasting.txt): the period, a short line for the day, the
 * general rule in full, and the other typika.
 *
 * The rule texts are ordinary Prayers (fasting-<period>, and
 * fasting-<period>-other), editable on the site. The day lines can be
 * changed on the Fasting screen.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fasting {

	const OPTION = 'prayer_rule_fasting';   // period key => weekday (sun … sat) => line

	const WEEKDAYS = array( 'sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat' );

	/** Periods from the data file, by key. */
	public static function periods() {
		$out = array();
		foreach ( Ustav::data()['fasting'] ?? array() as $period ) {
			$out[ $period['key'] ] = $period;
		}
		return $out;
	}

	/** Which period a day (Calendar::day()) falls in. */
	public static function when( $info ) {
		$after  = (int) $info['after_pascha'];
		$before = (int) $info['before_pascha'];
		$md     = (int) $info['julian']['m'] * 100 + (int) $info['julian']['d'];
		if ( $after <= 6 ) {
			return 'bright-week';
		}
		if ( $after <= 48 ) {
			return 'pentecost-season';           // Thomas Sunday to the eve of Pentecost
		}
		if ( $after <= 55 ) {
			return 'trinity-week';               // Pentecost to the eve of All Saints
		}
		$movable = array(
			array( -70, -64, 'publican-week' ),
			array( -63, -56, 'prodigal-week' ),    // to Meatfare Sunday
			array( -55, -49, 'cheesefare' ),
			array( -48, -7, 'lent' ),              // Clean Monday to Palm Sunday
			array( -6, -1, 'holy-week' ),
		);
		foreach ( $movable as $m ) {
			if ( $before >= $m[0] && $before <= $m[1] ) {
				return $m[2];
			}
		}
		if ( $after >= 57 && $md >= 501 && $md <= 628 ) {
			return 'apostles';                   // Monday after All Saints to June 28
		}
		if ( $md >= 801 && $md <= 814 ) {
			return 'dormition';
		}
		if ( $md >= 1115 && $md <= 1205 ) {
			return 'nativity-1';
		}
		if ( $md >= 1206 && $md <= 1224 ) {
			return 'nativity-2';
		}
		if ( $md >= 1225 || $md <= 104 ) {
			return 'twelve-days';
		}
		if ( $md >= 107 && $md <= 331 && $before < -70 ) {
			return 'after-theophany';            // to the Publican and Pharisee
		}
		return 'ordinary';
	}

	/**
	 * Fasting for a day: array( key, title, today, general (HTML), other (HTML) ),
	 * or null when there is no data. $feast: the day's feast (Feasts::find()).
	 */
	public static function for_day( $info, $feast = null ) {
		$when   = self::when( $info );
		$period = null;
		foreach ( self::periods() as $p ) {
			if ( $p['when'] === $when ) {
				$period = $p;
				break;
			}
		}
		if ( ! $period ) {
			return null;
		}
		$general = Texts::find( 'fasting-' . $period['text'] );
		$other   = Texts::find( 'fasting-' . $period['text'] . '-other' );
		return array(
			'key'     => $period['key'],
			'title'   => $period['title'],
			'today'   => self::today( $period, $info, $feast ),
			'general' => $general ? Texts::html( $general ) : '',
			'other'   => $other ? Texts::html( $other ) : '',
		);
	}

	/** The line for the day: special days first, then a feast, then the weekday. */
	private static function today( $period, $info, $feast ) {
		$wd = self::WEEKDAYS[ (int) $info['weekday'] ];
		foreach ( $period['on'] as $on ) {
			if ( isset( $on['pascha'] ) && (int) $info['before_pascha'] === (int) $on['pascha'] ) {
				return $on['text'];
			}
			if ( isset( $on['date'] ) && (int) $info['julian']['m'] === (int) $on['date'][0] && (int) $info['julian']['d'] === (int) $on['date'][1] ) {
				return $on['text'];
			}
		}
		foreach ( $period['on'] as $on ) {
			if ( isset( $on['week1'] ) && (int) $info['before_pascha'] <= -42 && in_array( $wd, $on['week1'], true ) ) {
				return $on['text'];
			}
		}
		if ( $feast && ! empty( $period['feast'] ) && in_array( $wd, $period['feast']['days'], true ) ) {
			return $period['feast']['text'];
		}
		$saved = self::saved()[ $period['key'] ][ $wd ] ?? '';
		if ( '' !== $saved ) {
			return $saved;
		}
		return $period['day'][ $wd ] ?? __( 'Not a fast day.', 'prayer-rule' );
	}

	public static function saved() {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/** $posted: key => weekday => line; empty goes back to the Home Ustav's. */
	public static function save( $posted ) {
		$clean = array();
		foreach ( array_keys( self::periods() ) as $key ) {
			foreach ( self::WEEKDAYS as $wd ) {
				$line = trim( sanitize_text_field( (string) ( $posted[ $key ][ $wd ] ?? '' ) ) );
				if ( '' !== $line ) {
					$clean[ $key ][ $wd ] = $line;
				}
			}
		}
		update_option( self::OPTION, $clean );
	}

	/** HTML block: the day's line, the rule, the other typika (folded). */
	public static function html( $fast ) {
		if ( ! $fast ) {
			return '';
		}
		$html  = '<div class="pr-fasting">';
		$html .= '<p class="pr-fasting-today"><strong>' . esc_html( $fast['title'] ) . ':</strong> ' . esc_html( $fast['today'] ) . '</p>';
		if ( '' !== $fast['general'] ) {
			$html .= '<details class="pr-fasting-rule"><summary>' . esc_html__( 'The rule', 'prayer-rule' ) . '</summary>' . $fast['general'];
			if ( '' !== $fast['other'] ) {
				$html .= '<details class="pr-fasting-other"><summary>' . esc_html__( 'Other typika', 'prayer-rule' ) . '</summary>' . $fast['other'] . '</details>';
			}
			$html .= '</details>';
		}
		return $html . '</div>';
	}
}
