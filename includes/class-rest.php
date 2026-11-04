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
			'/services',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'services' ),
				'permission_callback' => $member,
			)
		);
		register_rest_route(
			self::NS,
			'/scripture',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'scripture' ),
				'permission_callback' => $member,
			)
		);
		register_rest_route(
			self::NS,
			'/services/(?P<service>[a-z-]+)/reading',
			array(
				'methods'             => array( 'GET', 'POST' ),   // the reading page posts, so the portion stays out of URLs and logs
				'callback'            => array( __CLASS__, 'reading' ),
				'permission_callback' => $member,
			)
		);
	}

	/**
	 * Chapters for the daily reading: items [{ s: section, t: translation, b: book, c: chapter }].
	 * The member's bookmarks stay in their (encrypted) rule; only the chapters asked for come here.
	 */
	public static function scripture( \WP_REST_Request $request ) {
		$labels  = array(
			'gospel'  => __( 'The Gospel', 'prayer-rule' ),
			'apostle' => __( 'The Apostle', 'prayer-rule' ),
			'old'     => __( 'The Old Testament', 'prayer-rule' ),
		);
		$numbers = (bool) $request['verses'];
		$loaded  = Bible::translations();
		$html    = '';
		$section = '';
		foreach ( array_slice( (array) $request->get_param( 'items' ), 0, 60 ) as $item ) {
			$t = sanitize_key( (string) ( $item['t'] ?? '' ) );
			$b = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $item['b'] ?? '' ) ) );
			$c = absint( $item['c'] ?? 0 );
			$s = sanitize_key( (string) ( $item['s'] ?? '' ) );
			if ( ! isset( $loaded[ $t ] ) || '' === $b || ! $c ) {
				continue;
			}
			if ( $s !== $section && isset( $labels[ $s ] ) ) {
				$html   .= '<h3 class="pr-reading-section">' . esc_html( $labels[ $s ] ) . '</h3>';
				$section = $s;
			}
			$name = $b;
			foreach ( Bible::translation( $t )['books'] as $book ) {
				if ( $book['code'] === $b ) {
					$name = $book['name'];
				}
			}
			$html .= '<section class="pr-chapter"><h4 class="pr-psalm">' . esc_html( $name . ' ' . $c ) . '</h4>' . Bible::chapter_html( $t, $b, $c, $numbers ) . '</section>';
		}
		return array(
			'html'     => '<div class="prayer-rule-reading pr-scripture">' . $html . '</div>',
			'day_html' => $request['date'] ? Reader::day_html( Calendar::day( (string) $request['date'] ), 'reading' ) : '',
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

	/** Services with their portions and suggested amounts. */
	public static function services() {
		$list = array();
		foreach ( Services::all() as $key => $service ) {
			$portions = array();
			foreach ( $service['portions'] as $portion => $p ) {
				$portions[] = array(
					'key'       => $portion,
					'name'      => $p['name'],
					'kathismas' => $p['kathismas'],
					'ladders'   => $p['ladders'],
				);
			}
			$list[] = array(
				'key'      => $key,
				'name'     => $service['name'],
				'ready'    => (bool) $service['order'],
				'portions' => $portions,
			);
		}
		return $list;
	}

	/**
	 * A service with a portion given in the request, e.g.
	 * ?translation=kjv&date=2026-11-01&verses=0&p[main][kathismas]=2&p[main][start]=7&p[main][ladders]=0
	 * (Phase 9 replaces the given portion with the member's own rule.)
	 */
	public static function reading( \WP_REST_Request $request ) {
		$key = sanitize_key( $request['service'] );
		if ( ! Services::get( $key ) || ! Services::get( $key )['order'] ) {
			return new \WP_Error( 'prayer_rule_no_service', 'No such service.', array( 'status' => 404 ) );
		}
		$given    = (array) $request->get_param( 'p' );
		$portions = array();
		foreach ( $given as $portion => $values ) {
			$portions[ sanitize_key( $portion ) ] = array(
				'kathismas' => min( 20, absint( $values['kathismas'] ?? 0 ) ),
				'start'     => absint( $values['start'] ?? 0 ),   // 0: continue from the portion before
				'ladders'   => min( 50, (float) ( $values['ladders'] ?? 0 ) ),
			);
		}
		if ( null !== $request['verses'] ) {
			Render::set_verse_numbers( (bool) $request['verses'] );
		}
		$steps = Render::assemble_service(
			$key,
			array(
				'translation' => $request['translation'],
				'lent'        => null === $request['lent'] ? null : (bool) $request['lent'],
				'portions'    => $portions,
				'day'         => $request['day'],
				'date'        => $request['date'] ? Calendar::civil( (string) $request['date'] ) : '',
			)
		);
		// Members see only what exists; problems are for the admin preview.
		$steps = array_values(
			array_filter(
				$steps,
				function ( $step ) {
					return in_array( $step['type'], array( 'text', 'psalm', 'say', 'rubric', 'kathisma', 'stasis', 'ladders' ), true );
				}
			)
		);
		$day = null;
		if ( $request['date'] ) {
			$info = Calendar::day( (string) $request['date'] );
			$day  = array(
				'date'   => $info['civil'],
				'julian' => $info['julian']['iso'],
				'label'  => $info['label'],
				'lines'  => $info['lines'],
				'saint'  => $info['saint'],
				'lent'   => $info['lent'],
				'fast'   => Fasting::for_day( $info, Feasts::find( $info, 'matins' ) ),
			);
		}
		return array(
			'service'     => $key,
			'day'         => $day,
			'translation' => Psalter::translation( $request['translation'] ),
			'steps'       => $steps,
			'html'        => Render::html( $steps ),
			'day_html'    => $request['date'] ? Reader::day_html( Calendar::day( (string) $request['date'] ), $key ) : '',
		);
	}
}
