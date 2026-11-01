<?php
/**
 * The Home Ustav texts bundled in data/ustav.json: prayers, the six
 * service orders and the weekly dismissals.
 *
 * They are added to the site as ordinary Prayers and Orders, which you
 * then edit freely. Adding only creates what is missing (by short name,
 * or by service for orders); nothing already on the site is overwritten.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ustav {

	const FORMAT       = 'prayer-rule-ustav/1';
	const OPT_HASH     = 'prayer_rule_ustav_hash';
	const META_SOURCE  = '_prayer_rule_source';
	const META_SERVICE = '_prayer_rule_ustav_service';

	private static $data = null;

	public static function data_file() {
		return PRAYER_RULE_DIR . 'data/ustav.json';
	}

	public static function data() {
		if ( null === self::$data ) {
			$file = self::data_file();
			$data = is_readable( $file ) ? json_decode( file_get_contents( $file ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::$data = is_array( $data ) && ( $data['format'] ?? '' ) === self::FORMAT ? $data : array();
		}
		return self::$data;
	}

	/** Admin load: add the texts the first time, and again when the file changes. */
	public static function maybe_import() {
		$file = self::data_file();
		if ( ! is_readable( $file ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$hash = md5_file( $file );
		if ( get_option( self::OPT_HASH ) === $hash ) {
			return;
		}
		$first = ! get_option( self::OPT_HASH );
		self::import( $first );
		update_option( self::OPT_HASH, $hash );
	}

	/**
	 * Create missing prayers and orders. $assign: choose the Home Ustav order
	 * for every service (first import); otherwise only for services that
	 * have none. Returns array( 'prayers' => n, 'orders' => n ).
	 */
	public static function import( $assign = false ) {
		$data  = self::data();
		$count = array(
			'prayers' => 0,
			'orders'  => 0,
		);
		foreach ( $data['prayers'] ?? array() as $prayer ) {
			$slug = sanitize_title( $prayer['slug'] );
			if ( '' === $slug || Texts::exists( $slug ) ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'    => Texts::TYPE,
					'post_status'  => 'publish',
					'post_title'   => wp_slash( $prayer['title'] ),
					'post_content' => wp_slash( $prayer['content'] ),
					'meta_input'   => array(
						Texts::META_NAME  => $slug,
						self::META_SOURCE => 'home-ustav',
					),
				),
				true
			);
			if ( ! is_wp_error( $id ) ) {
				++$count['prayers'];
			}
		}
		foreach ( $data['orders'] ?? array() as $order ) {
			$service = sanitize_key( $order['service'] );
			if ( ! Services::get( $service ) ) {
				continue;
			}
			$id = self::order_for( $service );
			if ( ! $id ) {
				$id = wp_insert_post(
					array(
						'post_type'   => Orders::TYPE,
						'post_status' => 'publish',
						'post_title'  => wp_slash( $order['title'] ),
						'meta_input'  => array(
							Orders::META_ITEMS => wp_slash( $order['items'] ),
							self::META_SOURCE  => 'home-ustav',
							self::META_SERVICE => $service,
						),
					),
					true
				);
				if ( is_wp_error( $id ) ) {
					continue;
				}
				++$count['orders'];
			}
			if ( $assign || ! Services::get( $service )['order'] ) {
				Services::set_order( $service, $id );
			}
		}
		return $count;
	}

	/** The Home Ustav order already made for a service (not in the trash), or 0. */
	private static function order_for( $service ) {
		$ids = get_posts(
			array(
				'post_type'      => Orders::TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'meta_key'       => self::META_SERVICE, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $service, // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/** How many of the bundled prayers and orders are on the site. */
	public static function status() {
		$data    = self::data();
		$prayers = 0;
		foreach ( $data['prayers'] ?? array() as $prayer ) {
			$prayers += Texts::exists( $prayer['slug'] ) ? 1 : 0;
		}
		$orders = 0;
		foreach ( $data['orders'] ?? array() as $order ) {
			$orders += self::order_for( $order['service'] ) ? 1 : 0;
		}
		return array(
			'prayers'       => $prayers,
			'prayers_total' => count( $data['prayers'] ?? array() ),
			'orders'        => $orders,
			'orders_total'  => count( $data['orders'] ?? array() ),
		);
	}
}
