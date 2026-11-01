<?php
/**
 * Which dismissal ends each service on each day of the week.
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

	/** service => day => short name; saved values over the Home Ustav's. */
	public static function table() {
		$defaults = Ustav::data()['dismissals'] ?? array();
		$saved    = get_option( self::OPTION, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$table    = array();
		foreach ( array_keys( Services::definitions() ) as $service ) {
			foreach ( array_keys( self::days() ) as $day ) {
				$value                      = $saved[ $service ][ $day ] ?? '';
				$table[ $service ][ $day ] = '' !== $value ? $value : (string) ( $defaults[ $service ][ $day ] ?? '' );
			}
		}
		return $table;
	}

	public static function slug( $service, $day ) {
		$table = self::table();
		return $table[ $service ][ self::day_key( $day ) ] ?? '';
	}

	public static function save( $posted ) {
		$clean = array();
		foreach ( array_keys( Services::definitions() ) as $service ) {
			foreach ( array_keys( self::days() ) as $day ) {
				$clean[ $service ][ $day ] = sanitize_title( (string) ( $posted[ $service ][ $day ] ?? '' ) );
			}
		}
		update_option( self::OPTION, $clean );
	}
}
