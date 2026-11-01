<?php
/**
 * Which dismissal ends each service on each day of the week, and which
 * daily additions (an "additions" line) it has.
 *
 * Days are liturgical days: the day begins at sundown, so Vespers belongs
 * to the next day ("Sunday Vespers" is read on Saturday evening).
 * The table holds prayers' short names; it starts from the Home Ustav
 * (data/ustav.json) and is editable on the Dismissals screen.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Dismissals {

	const OPTION = 'prayer_rule_dismissals';
	const OPT_ADD = 'prayer_rule_additions';
	const NONE    = '-';     // saved for "none" (an empty box means the Home Ustav's choice)

	private static function option( $kind ) {
		return 'additions' === $kind ? self::OPT_ADD : self::OPTION;
	}

	public static function days() {
		return array(
			'sunday'    => __( 'Sunday', 'prayer-rule' ),
			'monday'    => __( 'Monday', 'prayer-rule' ),
			'tuesday'   => __( 'Tuesday', 'prayer-rule' ),
			'wednesday' => __( 'Wednesday', 'prayer-rule' ),
			'thursday'  => __( 'Thursday', 'prayer-rule' ),
			'friday'    => __( 'Friday', 'prayer-rule' ),
			'saturday'  => __( 'Saturday', 'prayer-rule' ),
		);
	}

	/** 0 (Sunday) … 6, or a day name => day name. */
	public static function day_key( $day ) {
		$keys = array_keys( self::days() );
		if ( is_numeric( $day ) ) {
			return $keys[ ( (int) $day % 7 + 7 ) % 7 ];
		}
		$day = sanitize_key( (string) $day );
		return in_array( $day, $keys, true ) ? $day : $keys[ (int) wp_date( 'w' ) ];
	}

	/** service => day => short name ('' = none); saved values over the Home Ustav's. $kind: dismissals | additions */
	public static function table( $kind = 'dismissals' ) {
		$defaults = Ustav::data()[ 'additions' === $kind ? 'additions' : 'dismissals' ] ?? array();
		$saved    = get_option( self::option( $kind ), array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$table    = array();
		foreach ( array_keys( Services::definitions() ) as $service ) {
			foreach ( array_keys( self::days() ) as $day ) {
				$value                      = $saved[ $service ][ $day ] ?? '';
				$value                     = '' !== $value ? $value : (string) ( $defaults[ $service ][ $day ] ?? '' );
				$table[ $service ][ $day ] = self::NONE === $value ? '' : $value;
			}
		}
		return $table;
	}

	public static function slug( $service, $day, $kind = 'dismissals' ) {
		$table = self::table( $kind );
		return $table[ $service ][ self::day_key( $day ) ] ?? '';
	}

	/** What the box shows: the saved value ('-' for none), or '' for the Home Ustav's choice. */
	public static function saved( $kind = 'dismissals' ) {
		$saved = get_option( self::option( $kind ), array() );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Daily additions for a service on a day (Calendar::day()), with the
	 * feast found for that service (Feasts::find()) or null.
	 */
	public static function additions( $service, $info, $feast ) {
		$on_feast = Ustav::data()['additions_feast'] ?? array();
		if ( $feast && 'weekly' === $feast['additions'] ) {
			$feast = null;                                // the Sundays of the fathers and the like
		}
		if ( $feast && array_key_exists( $service, $on_feast['any'] ?? array() ) ) {
			return (string) $on_feast['any'][ $service ];
		}
		if ( $feast && $feast['sunday'] && $feast['lordly'] && array_key_exists( $service, $on_feast['sunday'] ?? array() ) ) {
			return (string) $on_feast['sunday'][ $service ];
		}
		return self::slug( $service, $info['day'], 'additions' );
	}

	public static function save( $posted, $kind = 'dismissals' ) {
		$clean = array();
		foreach ( array_keys( Services::definitions() ) as $service ) {
			foreach ( array_keys( self::days() ) as $day ) {
				$value                     = (string) ( $posted[ $service ][ $day ] ?? '' );
				$clean[ $service ][ $day ] = self::NONE === $value ? self::NONE : sanitize_title( $value );
			}
		}
		update_option( self::option( $kind ), $clean );
	}
}
