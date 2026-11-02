<?php
/**
 * Sharing a private rule with a nastavnik.
 *
 * Everything shared is encrypted in the browsers (assets/reader.js) with a
 * key the member and the nastavnik derive from their own key pairs; the
 * site keeps:
 *   - each member's public key (user meta), so others can share with them;
 *   - shares: who shares with whom, and the latest encrypted copy of the
 *     member's rule and record;
 *   - messages: encrypted, with author and time.
 * The site can see who shares with whom and when they write, never what.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sharing {

	const META_KEY = 'prayer_rule_public_key';
	const MAX_DATA = 4194304;
	const MAX_MSG  = 65536;

	public static function shares_table() {
		global $wpdb;
		return $wpdb->prefix . 'prayer_rule_shares';
	}

	public static function messages_table() {
		global $wpdb;
		return $wpdb->prefix . 'prayer_rule_messages';
	}

	/** Table definitions, created with the vault table (Vault::install). */
	public static function schema( $charset ) {
		$shares   = self::shares_table();
		$messages = self::messages_table();
		return array(
			"CREATE TABLE {$shares} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				owner_id bigint(20) unsigned NOT NULL,
				viewer_id bigint(20) unsigned NOT NULL,
				data longtext NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY pair (owner_id,viewer_id),
				KEY viewer (viewer_id)
			) {$charset};",
			"CREATE TABLE {$messages} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				share_id bigint(20) unsigned NOT NULL,
				author_id bigint(20) unsigned NOT NULL,
				body longtext NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY share (share_id)
			) {$charset};",
		);
	}

	public static function register_rest() {
		$member = array( 'PrayerRule\\Access', 'rest_permission' );
		$routes = array(
			'/sharing/key'                            => array( 'PUT', 'set_key' ),
			'/sharing/people'                         => array( 'GET', 'find' ),
			'/sharing/shares'                         => array( 'GET', 'list_shares' ),
			'/sharing/shares '                        => array( 'POST', 'create' ),
			'/sharing/shares/(?P<id>\d+)'             => array( 'GET', 'get_share' ),
			'/sharing/shares/(?P<id>\d+) '            => array( 'PUT', 'update' ),
			'/sharing/shares/(?P<id>\d+)  '           => array( 'DELETE', 'remove' ),
			'/sharing/shares/(?P<id>\d+)/messages'    => array( 'GET', 'messages' ),
			'/sharing/shares/(?P<id>\d+)/messages '   => array( 'POST', 'post_message' ),
		);
		foreach ( $routes as $route => $def ) {
			register_rest_route(
				Rest::NS,
				trim( $route ),
				array(
					'methods'             => $def[0],
					'callback'            => array( __CLASS__, $def[1] ),
					'permission_callback' => $member,
				)
			);
		}
	}

	private static function b64( $value, $max ) {
		$value = (string) $value;
		return ( '' !== $value && strlen( $value ) <= $max && preg_match( '#^[A-Za-z0-9+/]+={0,2}$#', $value ) ) ? $value : null;
	}

	private static function person( $user_id ) {
		$user = get_userdata( (int) $user_id );
		return array(
			'id'   => (int) $user_id,
			'name' => $user ? $user->display_name : __( '(deleted account)', 'prayer-rule' ),
			'key'  => (string) get_user_meta( (int) $user_id, self::META_KEY, true ),
		);
	}

	/** The member's own public key (raw P-256 point, base64). */
	public static function set_key( \WP_REST_Request $request ) {
		$key = self::b64( $request->get_param( 'key' ), 200 );
		if ( ! $key || 65 !== strlen( base64_decode( $key ) ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			return new \WP_Error( 'prayer_rule_bad_key', 'Bad key.', array( 'status' => 400 ) );
		}
		update_user_meta( get_current_user_id(), self::META_KEY, $key );
		return array( 'saved' => true );
	}

	/** Find someone to share with, by exact username or email. */
	public static function find( \WP_REST_Request $request ) {
		$who = trim( (string) $request->get_param( 'who' ) );
		if ( '' === $who ) {
			return new \WP_Error( 'prayer_rule_no_one', __( 'Type a username or email address.', 'prayer-rule' ), array( 'status' => 400 ) );
		}
		$user = is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who );
		if ( ! $user || (int) $user->ID === get_current_user_id() || ! Access::can_use( (int) $user->ID ) ) {
			return new \WP_Error( 'prayer_rule_no_one', __( 'No member with that username or email.', 'prayer-rule' ), array( 'status' => 404 ) );
		}
		return self::person( $user->ID );
	}

	private static function last_message( $share_id ) {
		global $wpdb;
		$table = self::messages_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT author_id, created_at FROM {$table} WHERE share_id = %d ORDER BY id DESC LIMIT 1", $share_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	public static function list_shares() {
		global $wpdb;
		Vault::maybe_install();
		$me    = get_current_user_id();
		$table = self::shares_table();
		$out   = array(
			'mine'    => array(),
			'with_me' => array(),
		);
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, owner_id, viewer_id, updated_at FROM {$table} WHERE owner_id = %d OR viewer_id = %d ORDER BY id", $me, $me ), ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB
			$last = self::last_message( (int) $row['id'] );
			$item = array(
				'id'           => (int) $row['id'],
				'updated'      => $row['updated_at'],
				'last_message' => $last ? $last['created_at'] : '',
				'last_author'  => $last ? (int) $last['author_id'] : 0,
			);
			if ( (int) $row['owner_id'] === $me ) {
				$item['viewer'] = self::person( $row['viewer_id'] );
				$out['mine'][]  = $item;
			} else {
				$item['owner']    = self::person( $row['owner_id'] );
				$out['with_me'][] = $item;
			}
		}
		$response = rest_ensure_response( $out );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** A share the current member is part of, or null. */
	private static function share( $id ) {
		global $wpdb;
		$table = self::shares_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$me    = get_current_user_id();
		return ( $row && ( (int) $row['owner_id'] === $me || (int) $row['viewer_id'] === $me ) ) ? $row : null;
	}

	private static function not_found() {
		return new \WP_Error( 'prayer_rule_no_share', 'No such share.', array( 'status' => 404 ) );
	}

	public static function create( \WP_REST_Request $request ) {
		global $wpdb;
		Vault::maybe_install();
		$viewer = (int) $request->get_param( 'viewer' );
		$data   = self::b64( $request->get_param( 'data' ), self::MAX_DATA );
		if ( ! $viewer || get_current_user_id() === $viewer || ! Access::can_use( $viewer ) || ! $data ) {
			return new \WP_Error( 'prayer_rule_bad_share', __( 'Could not share with this person.', 'prayer-rule' ), array( 'status' => 400 ) );
		}
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert( // phpcs:ignore WordPress.DB
			self::shares_table(),
			array(
				'owner_id'   => get_current_user_id(),
				'viewer_id'  => $viewer,
				'data'       => $data,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
		if ( ! $ok ) {
			return new \WP_Error( 'prayer_rule_share_exists', __( 'You already share with this person.', 'prayer-rule' ), array( 'status' => 409 ) );
		}
		return array( 'id' => (int) $wpdb->insert_id );
	}

	/** The nastavnik fetches the shared copy. */
	public static function get_share( \WP_REST_Request $request ) {
		$row = self::share( (int) $request['id'] );
		if ( ! $row ) {
			return self::not_found();
		}
		$response = rest_ensure_response(
			array(
				'id'      => (int) $row['id'],
				'data'    => $row['data'],
				'updated' => $row['updated_at'],
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** The member sends a fresh copy (only the member may). */
	public static function update( \WP_REST_Request $request ) {
		global $wpdb;
		$row  = self::share( (int) $request['id'] );
		$data = self::b64( $request->get_param( 'data' ), self::MAX_DATA );
		if ( ! $row || (int) $row['owner_id'] !== get_current_user_id() ) {
			return self::not_found();
		}
		if ( ! $data ) {
			return new \WP_Error( 'prayer_rule_bad_share', 'Bad data.', array( 'status' => 400 ) );
		}
		$wpdb->update( self::shares_table(), array( 'data' => $data, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => (int) $row['id'] ), array( '%s', '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB
		return array( 'saved' => true );
	}

	/** Either side ends the share; its copy and messages go with it. */
	public static function remove( \WP_REST_Request $request ) {
		$row = self::share( (int) $request['id'] );
		if ( ! $row ) {
			return self::not_found();
		}
		self::delete_shares( array( (int) $row['id'] ) );
		return array( 'removed' => true );
	}

	public static function messages( \WP_REST_Request $request ) {
		global $wpdb;
		$row = self::share( (int) $request['id'] );
		if ( ! $row ) {
			return self::not_found();
		}
		$table = self::messages_table();
		$list  = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, author_id, body, created_at FROM {$table} WHERE share_id = %d ORDER BY id", $row['id'] ), ARRAY_A ) as $m ) { // phpcs:ignore WordPress.DB
			$list[] = array(
				'id'      => (int) $m['id'],
				'author'  => (int) $m['author_id'],
				'body'    => $m['body'],
				'created' => $m['created_at'],
			);
		}
		$response = rest_ensure_response( array( 'messages' => $list ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function post_message( \WP_REST_Request $request ) {
		global $wpdb;
		$row  = self::share( (int) $request['id'] );
		$body = self::b64( $request->get_param( 'body' ), self::MAX_MSG );
		if ( ! $row ) {
			return self::not_found();
		}
		if ( ! $body ) {
			return new \WP_Error( 'prayer_rule_bad_message', __( 'The message is empty or too long.', 'prayer-rule' ), array( 'status' => 400 ) );
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB
			self::messages_table(),
			array(
				'share_id'   => (int) $row['id'],
				'author_id'  => get_current_user_id(),
				'body'       => $body,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s' )
		);
		return array( 'id' => (int) $wpdb->insert_id );
	}

	private static function delete_shares( $ids ) {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return;
		}
		$in = implode( ',', $ids );
		$wpdb->query( 'DELETE FROM ' . self::messages_table() . " WHERE share_id IN ({$in})" ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . self::shares_table() . " WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB
	}

	/** A member erases their rule, or their account is deleted: all their shares and their key go. */
	public static function forget_user( $user_id ) {
		global $wpdb;
		$user_id = (int) $user_id;
		$table   = self::shares_table();
		$ids     = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE owner_id = %d OR viewer_id = %d", $user_id, $user_id ) ); // phpcs:ignore WordPress.DB
		self::delete_shares( $ids );
		delete_user_meta( $user_id, self::META_KEY );
	}
}
