<?php
/**
 * The six services of the daily cycle and their rule portions.
 *
 * Each service has one order (opening, `rule`, ending). A portion is a
 * place in the order where the member's own rule goes: kathismas, ladders
 * of the Jesus Prayer, or both. Matins has two: Matins itself and the
 * 1st Hour read at its end (`rule first-hour` in the order).
 *
 * The amounts offered for each portion are the suggestions members pick
 * from; they are editable on the Services screen.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Services {

	const OPTION = 'prayer_rule_services';
	const OPT_RULE = 'prayer_rule_rule_texts';

	/** Fixed list: key => name, portions (key => name), suggested amounts. */
	public static function definitions() {
		return array(
			'vespers'   => array(
				'name'     => __( 'Vespers', 'prayer-rule' ),
				'portions' => array(
					'main' => array( __( 'Vespers', 'prayer-rule' ), '2, 1', '3, 2, 1' ),
				),
			),
			'compline'  => array(
				'name'     => __( 'Compline', 'prayer-rule' ),
				'portions' => array(
					'main' => array( __( 'Compline', 'prayer-rule' ), '2, 1', '3, 2.5, 2, 1, 0.5' ),
				),
			),
			'midnight'  => array(
				'name'     => __( 'Midnight Office', 'prayer-rule' ),
				'portions' => array(
					'main' => array( __( 'Midnight Office', 'prayer-rule' ), '2, 1', '3, 2, 1' ),
				),
			),
			'matins'    => array(
				'name'     => __( 'Matins', 'prayer-rule' ),
				'portions' => array(
					'main'       => array( __( 'Matins', 'prayer-rule' ), '5, 3, 2', '7, 4, 2' ),
					'first-hour' => array( __( '1st Hour', 'prayer-rule' ), '1', '1.5, 1, 0.5' ),
				),
			),
			'hours'     => array(
				'name'     => __( 'Hours', 'prayer-rule' ),
				'portions' => array(
					'main' => array( __( 'Hours (3rd, 6th, 9th)', 'prayer-rule' ), '3, 2', '5, 3, 2' ),
				),
			),
			'cell-rule' => array(
				'name'     => __( 'Cell Rule', 'prayer-rule' ),
				'portions' => array(
					'main' => array( __( 'Cell Rule', 'prayer-rule' ), '2, 1', '3, 2, 1' ),
				),
			),
		);
	}

	/** Saved settings merged over the defaults. */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( self::definitions() as $key => $def ) {
			$row = array(
				'name'     => $def['name'],
				'order'    => (int) ( $saved[ $key ]['order'] ?? 0 ),
				'portions' => array(),
			);
			foreach ( $def['portions'] as $portion => $p ) {
				$kathismas = self::amounts( $saved[ $key ]['portions'][ $portion ]['kathismas'] ?? '' );
				$ladders   = self::amounts( $saved[ $key ]['portions'][ $portion ]['ladders'] ?? '' );
				$row['portions'][ $portion ] = array(
					'name'      => $p[0],
					'kathismas' => $kathismas ? $kathismas : self::amounts( $p[1] ), // empty = suggested
					'ladders'   => $ladders ? $ladders : self::amounts( $p[2] ),
				);
			}
			$out[ $key ] = $row;
		}
		return $out;
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/** "3, 2.5, 1" => array( 3, 2.5, 1 ) — positive numbers, halves allowed. */
	public static function amounts( $typed ) {
		$list = array();
		foreach ( preg_split( '/[\s,]+/', is_array( $typed ) ? implode( ',', $typed ) : (string) $typed ) as $part ) {
			if ( is_numeric( $part ) && $part > 0 ) {
				$list[] = round( $part * 2 ) / 2;
			}
		}
		return array_values( array_unique( $list ) );
	}

	/** Save from the Services screen. */
	public static function save( $posted ) {
		$out = array();
		foreach ( self::definitions() as $key => $def ) {
			$out[ $key ]['order'] = absint( $posted[ $key ]['order'] ?? 0 );
			foreach ( array_keys( $def['portions'] ) as $portion ) {
				foreach ( array( 'kathismas', 'ladders' ) as $kind ) {
					$out[ $key ]['portions'][ $portion ][ $kind ] = implode( ', ', self::amounts( $posted[ $key ]['portions'][ $portion ][ $kind ] ?? '' ) );
				}
			}
		}
		update_option( self::OPTION, $out );
	}

	/**
	 * Prayers used inside the rule, by short name:
	 *   after_stasis   said after each stasis (the Glory and Alleluia)
	 *   after_kathisma said after each kathisma in Great Lent ({n} allowed)
	 *   jesus_prayer   shown with the ladders
	 */
	public static function rule_texts() {
		$saved = get_option( self::OPT_RULE, array() );
		return wp_parse_args(
			is_array( $saved ) ? $saved : array(),
			array(
				'after_stasis'   => 'glory-alleluia',
				'after_kathisma' => 'troparia-after-kathisma-{n}',
				'jesus_prayer'   => 'jesus-prayer',
			)
		);
	}

	public static function save_rule_texts( $posted ) {
		$clean = array();
		foreach ( array( 'after_stasis', 'after_kathisma', 'jesus_prayer' ) as $key ) {
			$value         = strtolower( trim( (string) ( $posted[ $key ] ?? '' ) ) );
			$clean[ $key ] = preg_replace( '/[^a-z0-9{}_-]/', '', $value );
		}
		update_option( self::OPT_RULE, $clean );
	}

	/** Ladders as words: 2.5 => "2½ ladders (250 Jesus Prayers)". */
	public static function ladders_label( $ladders ) {
		$whole = floor( $ladders );
		$half  = ( $ladders - $whole ) >= 0.5;
		$count = ( $whole ? (string) (int) $whole : '' ) . ( $half ? '½' : '' );
		/* translators: 1: number of ladders, e.g. 2½, 2: number of prayers */
		return sprintf( _n( '%1$s ladder (%2$d Jesus Prayers)', '%1$s ladders (%2$d Jesus Prayers)', $ladders > 1 ? 2 : 1, 'prayer-rule' ), $count, (int) round( $ladders * 100 ) );
	}
}
