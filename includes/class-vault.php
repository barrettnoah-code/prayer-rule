<?php
/**
 * Each member's private rule and record, stored only as ciphertext.
 *
 * Everything is encrypted in the member's browser (assets/reader.js) with
 * a key that never reaches the site: the key is itself kept here only
 * wrapped twice, once under the member's passphrase and once under their
 * recovery code. This class just stores and returns the sealed envelope:
 *
 *   v       format version
 *   iter    PBKDF2 iterations used for both wrappings
 *   salt_p, key_p   salt and wrapped key for the passphrase
 *   salt_r, key_r   salt and wrapped key for the recovery code
 *   rule    the encrypted rule
 *   log     the encrypted record
 *
 * All but v and iter are base64 strings. The site (and its administrators)
 * can see only that a member has an envelope, its size and when it changed.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Vault {

	const DB_VERSION = '1';
	const OPT_DB     = 'prayer_rule_vault_db';
	const MAX_BYTES  = 4194304;   // 4 MB per member, far more than years of record need

	const FIELDS = array( 'salt_p', 'key_p', 'salt_r', 'key_r', 'rule', 'log' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'prayer_rule_vaults';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				user_id bigint(20) unsigned NOT NULL,
				version int(10) unsigned NOT NULL DEFAULT 1,
				envelope longtext NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (user_id)
			) {$charset};"
		);
		update_option( self::OPT_DB, self::DB_VERSION );
	}

	public static function maybe_install() {
		if ( get_option( self::OPT_DB ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function register_rest() {
		$member = array( 'PrayerRule\\Access', 'rest_permission' );
		register_rest_route(
			Rest::NS,
			'/vault',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get' ),
					'permission_callback' => $member,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create' ),
					'permission_callback' => $member,
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update' ),
					'permission_callback' => $member,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'erase' ),
					'permission_callback' => $member,
				),
			)
		);
	}

	/** The current member's row, or null. */
	private static function row() {
		global $wpdb;
		self::maybe_install();
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT version, envelope, updated_at FROM {$table} WHERE user_id = %d", get_current_user_id() ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	private static function out( $row ) {
		if ( ! $row ) {
			return array( 'exists' => false );
		}
		return array(
			'exists'   => true,
			'version'  => (int) $row['version'],
			'envelope' => json_decode( $row['envelope'], true ),
		);
	}

	public static function get() {
		$response = rest_ensure_response( self::out( self::row() ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** Check an envelope: only the known fields, base64 only, within size. */
	private static function clean( $envelope ) {
		if ( ! is_array( $envelope ) ) {
			return new \WP_Error( 'prayer_rule_bad_vault', 'Bad envelope.', array( 'status' => 400 ) );
		}
		$clean = array(
			'v'    => (int) ( $envelope['v'] ?? 1 ),
			'iter' => (int) ( $envelope['iter'] ?? 0 ),
		);
		if ( $clean['iter'] < 100000 || $clean['iter'] > 10000000 ) {
			return new \WP_Error( 'prayer_rule_bad_vault', 'Bad key settings.', array( 'status' => 400 ) );
		}
		foreach ( self::FIELDS as $field ) {
			$value = (string) ( $envelope[ $field ] ?? '' );
			if ( '' === $value || ! preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $value ) ) {
				return new \WP_Error( 'prayer_rule_bad_vault', 'Bad envelope field: ' . $field, array( 'status' => 400 ) );
			}
			$clean[ $field ] = $value;
		}
		$json = wp_json_encode( $clean );
		if ( strlen( $json ) > self::MAX_BYTES ) {
			return new \WP_Error( 'prayer_rule_vault_full', 'The record is too large.', array( 'status' => 413 ) );
		}
		return $json;
	}

	/** First setup. */
	public static function create( \WP_REST_Request $request ) {
		global $wpdb;
		if ( self::row() ) {
			return new \WP_Error( 'prayer_rule_vault_exists', 'Your rule is already set up.', array( 'status' => 409 ) );
		}
		$json = self::clean( $request->get_param( 'envelope' ) );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB
			self::table(),
			array(
				'user_id'    => get_current_user_id(),
				'version'    => 1,
				'envelope'   => $json,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
		return array( 'version' => 1 );
	}

	/** Save, only if nobody saved in between (two devices): else 409 with the newer one. */
	public static function update( \WP_REST_Request $request ) {
		global $wpdb;
		$json = self::clean( $request->get_param( 'envelope' ) );
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$version = (int) $request->get_param( 'version' );
		$table   = self::table();
		$done    = $wpdb->query( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"UPDATE {$table} SET envelope = %s, version = version + 1, updated_at = %s WHERE user_id = %d AND version = %d", // phpcs:ignore WordPress.DB
				$json,
				current_time( 'mysql', true ),
				get_current_user_id(),
				$version
			)
		);
		if ( 1 !== (int) $done ) {
			$current = self::out( self::row() );
			return new \WP_REST_Response(
				array_merge(
					array(
						'code'    => 'prayer_rule_vault_changed',
						'message' => 'Saved elsewhere in the meantime.',
					),
					$current
				),
				$current['exists'] ? 409 : 404
			);
		}
		return array( 'version' => $version + 1 );
	}

	/** Erase the member's rule and record for good. */
	public static function erase() {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => get_current_user_id() ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		return array( 'erased' => true );
	}

	/** How many members have set up a rule (for the Overview; nothing else is visible). */
	public static function count() {
		global $wpdb;
		self::maybe_install();
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore WordPress.DB
	}

	/** A member's account is deleted: delete their envelope too. */
	public static function delete_user( $user_id ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'user_id' => (int) $user_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}
}
