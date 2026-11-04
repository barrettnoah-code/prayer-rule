<?php
/**
 * The Home Ustav texts bundled in data/ustav.json: prayers, the six
 * service orders and the weekly dismissals.
 *
 * They are added to the site as ordinary Prayers and Orders, which you
 * then edit freely. Adding only creates what is missing (by short name,
 * or by service for orders); nothing already on the site is overwritten,
 * except a prayer or service order that is still exactly as an earlier
 * version of the plugin made it: that one is brought up to date.
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
			'updated' => 0,
		);
		foreach ( $data['prayers'] ?? array() as $prayer ) {
			$slug = sanitize_title( $prayer['slug'] );
			if ( '' === $slug ) {
				continue;
			}
			if ( Texts::exists( $slug ) ) {
				// Never edited since an earlier version made it: bring it up to date.
				$post = Texts::find( $slug );
				if ( $post && ! empty( $prayer['previous'] ) && 'home-ustav' === get_post_meta( $post->ID, self::META_SOURCE, true )
					&& in_array( self::items_hash( $post->post_content ), $prayer['previous'], true ) ) {
					wp_update_post(
						array(
							'ID'           => $post->ID,
							'post_content' => wp_slash( $prayer['content'] ),
						)
					);
					++$count['updated'];
				}
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
			$service  = sanitize_key( $order['service'] );
			$seasonal = ! empty( $order['season'] );      // used in place of a service's order on some days
			if ( ! $seasonal && ! Services::get( $service ) ) {
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
			} elseif ( in_array( self::items_hash( (string) get_post_meta( $id, Orders::META_ITEMS, true ) ), $order['previous'] ?? array(), true ) ) {
				update_post_meta( $id, Orders::META_ITEMS, wp_slash( $order['items'] ) );   // never edited: bring up to date
				++$count['updated'];
			}
			if ( ! $seasonal && ( $assign || ! Services::get( $service )['order'] ) ) {
				Services::set_order( $service, $id );
			}
		}
		return $count;
	}

	/** md5 of an order's lines, ignoring line-ending differences. */
	private static function items_hash( $items ) {
		return md5( trim( str_replace( array( "\r\n", "\r" ), "\n", $items ) ) );
	}

	/**
	 * The order to use for a service on a day (Calendar::day()) instead of
	 * its own, from data "seasons" (Pascha and Bright Week), or 0.
	 */
	public static function seasonal_order( $service, $info ) {
		$key = self::seasonal_key( $service, $info );
		if ( '' === $key ) {
			return 0;
		}
		$id = self::order_for( $key );
		return $id && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	/** The key of the seasonal order for a service on a day ("vespers-bright"), or ''. */
	public static function seasonal_key( $service, $info ) {
		$after = (int) $info['after_pascha'];
		foreach ( self::data()['seasons'] ?? array() as $season ) {
			if ( $after >= (int) $season['from'] && $after <= (int) $season['to'] && ! empty( $season['orders'][ $service ] ) ) {
				return (string) $season['orders'][ $service ];
			}
		}
		return '';
	}

	/**
	 * The steps of the order for a service on a day: the seasonal order on the
	 * site, or, until the Home Ustav import has made it, the bundled one.
	 * Null when the service's own order applies.
	 */
	public static function seasonal_steps( $service, $info ) {
		$key = self::seasonal_key( $service, $info );
		if ( '' === $key ) {
			return null;
		}
		$id = self::seasonal_order( $service, $info );
		if ( $id ) {
			return Orders::steps( $id );
		}
		foreach ( self::data()['orders'] ?? array() as $order ) {
			if ( $key === $order['service'] ) {
				return Orders::parse_steps( (string) $order['items'] );
			}
		}
		return null;
	}

	/**
	 * The steps of an order that belongs to no service (the prayers before
	 * sleep, "bedtime"): the one on the site, or, until the import has made
	 * it, the bundled one. Empty when there is neither.
	 */
	public static function extra_steps( $key ) {
		$id = self::order_for( $key );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return Orders::steps( $id );
		}
		foreach ( self::data()['orders'] ?? array() as $order ) {
			if ( $key === $order['service'] ) {
				return Orders::parse_steps( (string) $order['items'] );
			}
		}
		return array();
	}

	/** A bundled prayer's content by short name, or '' (for one not yet imported). */
	public static function bundled_prayer( $slug ) {
		foreach ( self::data()['prayers'] ?? array() as $prayer ) {
			if ( $slug === $prayer['slug'] ) {
				return (string) $prayer['content'];
			}
		}
		return '';
	}

	/** The prayer said in place of another on a day (data "substitutions"), or the same slug. */
	public static function substitute( $slug, $info ) {
		$after = (int) $info['after_pascha'];
		foreach ( self::data()['substitutions'] ?? array() as $sub ) {
			if ( $slug === $sub['replace'] && $after >= (int) $sub['from'] && $after <= (int) $sub['to'] ) {
				return (string) $sub['with'];
			}
		}
		return $slug;
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
