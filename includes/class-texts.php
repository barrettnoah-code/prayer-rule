<?php
/**
 * Prayers (texts) written on the site: the usual beginning, troparia,
 * the prayers between stases, prayers for the departed, and so on.
 *
 * Each prayer's short name (e.g. usual-beginning) is how orders refer
 * to it. It is kept in post meta, filled from the title when left empty.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Texts {

	const TYPE      = 'pr_text';
	const META_NAME = '_prayer_rule_name';

	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Prayers', 'prayer-rule' ),
					'singular_name' => __( 'Prayer', 'prayer-rule' ),
					'add_new'       => __( 'Add prayer', 'prayer-rule' ),
					'add_new_item'  => __( 'Add prayer', 'prayer-rule' ),
					'edit_item'     => __( 'Edit prayer', 'prayer-rule' ),
					'all_items'     => __( 'Prayers', 'prayer-rule' ),
					'search_items'  => __( 'Search prayers', 'prayer-rule' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'prayer-rule',
				'show_in_rest'        => true,
				'exclude_from_search' => true,
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'rewrite'             => false,
				'query_var'           => false,
			)
		);
	}

	public static function boot_admin() {
		add_action( 'add_meta_boxes_' . self::TYPE, array( __CLASS__, 'meta_box' ) );
	}

	public static function meta_box() {
		add_meta_box( 'prayer-rule-name', __( 'Short name', 'prayer-rule' ), array( __CLASS__, 'box' ), self::TYPE, 'side', 'high' );
	}

	public static function box( $post ) {
		wp_nonce_field( 'prayer_rule_text', 'prayer_rule_text_nonce' );
		$name = (string) get_post_meta( $post->ID, self::META_NAME, true );
		echo '<input type="text" name="prayer_rule_name" value="' . esc_attr( $name ) . '" style="width:100%;font-family:monospace" placeholder="usual-beginning">';
		echo '<p class="description">' . esc_html__( 'How orders refer to this prayer. Lowercase letters, numbers and hyphens; left empty, it is made from the title.', 'prayer-rule' ) . '</p>';
	}

	public static function save( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		$typed = null;
		if ( isset( $_POST['prayer_rule_text_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prayer_rule_text_nonce'] ) ), 'prayer_rule_text' ) && current_user_can( 'edit_post', $post_id ) ) {
			$typed = isset( $_POST['prayer_rule_name'] ) ? sanitize_title( wp_unslash( $_POST['prayer_rule_name'] ) ) : '';
		}
		$current = (string) get_post_meta( $post_id, self::META_NAME, true );
		if ( null === $typed ) {
			if ( '' !== $current ) {
				return;                         // saved elsewhere; keep it
			}
			$typed = '';
		}
		$name = '' !== $typed ? $typed : sanitize_title( $post->post_title );
		if ( '' === $name ) {
			return;
		}
		update_post_meta( $post_id, self::META_NAME, self::unique_name( $name, $post_id ) );
	}

	/** $name, or $name-2, -3 … if another prayer already uses it. */
	public static function unique_name( $name, $post_id ) {
		$candidate = $name;
		for ( $i = 2; self::name_taken( $candidate, $post_id ); $i++ ) {
			$candidate = $name . '-' . $i;
		}
		return $candidate;
	}

	private static function name_taken( $name, $post_id ) {
		$posts = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'any',
				'meta_key'       => self::META_NAME, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $name, // phpcs:ignore WordPress.DB.SlowDBQuery
				'post__not_in'   => array( (int) $post_id ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return (bool) $posts;
	}

	/** Is this short name used by any prayer not in the trash (drafts too)? */
	public static function exists( $name ) {
		$name = sanitize_title( $name );
		return '' !== $name && self::name_taken( $name, 0 );
	}

	/** Published prayer with this short name, or null. */
	public static function find( $name ) {
		$name = sanitize_title( $name );
		if ( '' === $name ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'meta_key'       => self::META_NAME, // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $name, // phpcs:ignore WordPress.DB.SlowDBQuery
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);
		return $posts ? $posts[0] : null;
	}

	/** The prayer's text as HTML. */
	public static function html( $post ) {
		return self::content_html( $post->post_content );
	}

	/** Prayer content (blocks or plain) as HTML. */
	public static function content_html( $content ) {
		$html    = has_blocks( $content ) ? do_blocks( $content ) : wpautop( $content );
		$html    = shortcode_unautop( wptexturize( $html ) );
		return Render::god_bearer( do_shortcode( $html ) );
	}

	/** All published short names => titles (for the order screen's list). */
	public static function index() {
		$posts = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$index = array();
		foreach ( $posts as $post ) {
			$name = (string) get_post_meta( $post->ID, self::META_NAME, true );
			if ( '' !== $name ) {
				$index[ $name ] = $post->post_title;
			}
		}
		ksort( $index );
		return $index;
	}
}
