<?php
/**
 * Who may use the prayer rule: logged-in users, optionally limited to
 * chosen Paid Memberships Pro levels. Administrators always may.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Access {

	const OPTION = 'prayer_rule_access';

	/** Settings with defaults: mode 'logged_in' | 'levels', levels = ids. */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'mode'   => 'logged_in',
				'levels' => array(),
			)
		);
	}

	public static function save( $mode, $levels ) {
		$mode   = ( 'levels' === $mode ) ? 'levels' : 'logged_in';
		$levels = array_values( array_filter( array_map( 'absint', (array) $levels ) ) );
		update_option(
			self::OPTION,
			array(
				'mode'   => $mode,
				'levels' => $levels,
			)
		);
	}

	/** True when Paid Memberships Pro is active. */
	public static function pmpro_active() {
		return function_exists( 'pmpro_hasMembershipLevel' );
	}

	/** All PMPro levels as id => name (empty without PMPro). */
	public static function levels() {
		if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
			return array();
		}
		$levels = array();
		foreach ( pmpro_getAllLevels( true ) as $level ) {
			$levels[ (int) $level->id ] = $level->name;
		}
		return $levels;
	}

	/**
	 * REST permission check. (WordPress passes the request as the first
	 * argument, so this must never be can_use() itself.)
	 */
	public static function rest_permission() {
		return self::can_use( get_current_user_id() );
	}

	/** May the user (default: current) use the prayer rule? */
	public static function can_use( $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		} elseif ( ! is_numeric( $user_id ) ) {
			return false;                   // never guess a user from an object
		}
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		$settings = self::settings();
		if ( 'levels' !== $settings['mode'] || ! $settings['levels'] ) {
			return true;
		}
		if ( ! self::pmpro_active() ) {
			// Levels chosen but PMPro switched off: do not lock everyone out.
			return true;
		}
		return (bool) pmpro_hasMembershipLevel( $settings['levels'], $user_id );
	}
}
