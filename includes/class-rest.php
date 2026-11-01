<?php
/**
 * Read-only REST routes for the members' reader (phase 4 uses them).
 * Everything here needs a user who may use the prayer rule.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest {

	const NS = 'prayer-rule/v1';

	public static function register() {
		$member = array( 'PrayerRule\\Access', 'rest_permission' );
		register_rest_route(
			self::NS,
			'/translations',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'translations' ),
				'permission_callback' => $member,
			)
		);
		register_rest_route(
			self::NS,
			'/kathismas',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'kathismas' ),
				'permission_callback' => $member,
			)
		);
		register_rest_route(
			self::NS,
			'/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'orders' ),
				'permission_callback' => $member,
			)
		);
		register_rest_route(
			self::NS,
			'/orders/(?P<id>\d+)/reading',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'reading' ),
				'permission_callback' => $member,
				'args'                => array(
					'kathisma'    => array(
						'required'          => true,
						'validate_callback' => function ( $value ) {
							return is_numeric( $value ) && $value >= 1 && $value <= 20;
						},
					),
					'translation' => array( 'required' => false ),
				),
			)
		);
	}

	public static function translations() {
		$list = array();
		foreach ( Psalter::translations() as $id => $name ) {
			$list[] = array(
				'id'   => $id,
				'name' => $name,
			);
		}
		return array(
			'default'      => Psalter::default_translation(),
			'translations' => $list,
		);
	}

	public static function kathismas() {
		$list = array();
		foreach ( Kathismas::all() as $number => $stases ) {
			$list[] = array(
				'number' => $number,
				'stases' => array_map( array( 'PrayerRule\\Kathismas', 'label' ), $stases ),
			);
		}
		return $list;
	}

	public static function orders() {
		$list = array();
		foreach ( Orders::index() as $id => $title ) {
			$list[] = array(
				'id'    => $id,
				'title' => $title,
			);
		}
		return $list;
	}

	public static function reading( \WP_REST_Request $request ) {
		$id = (int) $request['id'];
		if ( 'publish' !== get_post_status( $id ) || Orders::TYPE !== get_post_type( $id ) ) {
			return new \WP_Error( 'prayer_rule_no_order', 'No such order.', array( 'status' => 404 ) );
		}
		$steps = Render::assemble( $id, (int) $request['kathisma'], $request['translation'] );
		// Members see only what exists; problems are for the admin preview.
		$steps = array_values(
			array_filter(
				$steps,
				function ( $step ) {
					return in_array( $step['type'], array( 'text', 'stasis' ), true );
				}
			)
		);
		return array(
			'order'       => $id,
			'kathisma'    => (int) $request['kathisma'],
			'translation' => Psalter::translation( $request['translation'] ),
			'steps'       => $steps,
		);
	}
}
