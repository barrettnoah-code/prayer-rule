<?php
/**
 * Orders: the opening and ending of a service, with the member's rule in
 * the middle. Typed one item per line:
 *
 *   usual-beginning        a prayer, by its short name
 *   psalm 50               psalms in the reader's translation
 *                          (also: psalm 90-91, psalms 142, 50, psalm 118:1-72)
 *   rule                   the member's kathismas and/or ladders
 *   rule first-hour        a second portion (the 1st Hour at the end of Matins)
 *   # a note               ignored
 *
 * Which order each service uses is chosen on the Services screen.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orders {

	const TYPE       = 'pr_order';
	const META_ITEMS = '_prayer_rule_items';

	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Orders', 'prayer-rule' ),
					'singular_name' => __( 'Order', 'prayer-rule' ),
					'add_new'       => __( 'Add order', 'prayer-rule' ),
					'add_new_item'  => __( 'Add order', 'prayer-rule' ),
					'edit_item'     => __( 'Edit order', 'prayer-rule' ),
					'all_items'     => __( 'Orders', 'prayer-rule' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'prayer-rule',
				'show_in_rest'    => false,
				'capability_type' => 'page',
				'map_meta_cap'    => true,
				'supports'        => array( 'title', 'revisions' ),
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	public static function boot_admin() {
		add_action( 'add_meta_boxes_' . self::TYPE, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function meta_boxes() {
		add_meta_box( 'prayer-rule-items', __( 'Order of the service', 'prayer-rule' ), array( __CLASS__, 'box_items' ), self::TYPE, 'normal', 'high' );
		add_meta_box( 'prayer-rule-prayers', __( 'Prayers you can use', 'prayer-rule' ), array( __CLASS__, 'box_prayers' ), self::TYPE, 'side' );
	}

	public static function box_items( $post ) {
		wp_nonce_field( 'prayer_rule_order', 'prayer_rule_order_nonce' );
		$items = get_post_meta( $post->ID, self::META_ITEMS, true );
		$used  = self::used_by( $post->ID );
		?>
		<p><?php esc_html_e( 'One item per line, read from top to bottom:', 'prayer-rule' ); ?></p>
		<ul style="list-style:disc;margin-left:20px">
			<li><code>usual-beginning</code> — <?php esc_html_e( 'a prayer, by its short name', 'prayer-rule' ); ?></li>
			<li><code>psalm 50</code>, <code>psalm 90-91</code>, <code>psalm 118:1-72</code> — <?php esc_html_e( 'psalms in the reader\'s own translation', 'prayer-rule' ); ?></li>
			<li><code>rule</code> — <?php esc_html_e( 'where the member\'s kathismas or ladders are read', 'prayer-rule' ); ?></li>
			<li><code>rule first-hour</code> — <?php esc_html_e( 'the 1st Hour\'s portion, in the Matins order', 'prayer-rule' ); ?></li>
			<li><code># …</code> — <?php esc_html_e( 'a note, ignored', 'prayer-rule' ); ?></li>
		</ul>
		<p class="description"><?php esc_html_e( 'Inside a prayer\'s own text, write [psalm 50] where a psalm belongs; it too appears in the reader\'s translation.', 'prayer-rule' ); ?></p>
		<textarea name="prayer_rule_items" rows="16" style="width:100%;font-family:monospace"><?php echo esc_textarea( $items ); ?></textarea>
		<?php
		echo '<p>' . ( $used
			/* translators: %s: service names */
			? esc_html( sprintf( __( 'Used for: %s.', 'prayer-rule' ), implode( ', ', $used ) ) )
			: esc_html__( 'Not yet chosen for any service (Prayer Rule → Services).', 'prayer-rule' ) ) . '</p>';
		$problems = self::problems( $post->ID );
		if ( $problems ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'To fix:', 'prayer-rule' ) . '</strong></p><ul style="list-style:disc;margin-left:20px">';
			foreach ( $problems as $problem ) {
				echo '<li>' . esc_html( $problem ) . '</li>';
			}
			echo '</ul></div>';
		}
	}

	public static function box_prayers() {
		$index = Texts::index();
		if ( ! $index ) {
			echo '<p>' . esc_html__( 'No published prayers yet.', 'prayer-rule' ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( $index as $slug => $title ) {
			echo '<li><code>' . esc_html( $slug ) . '</code><br><span class="description">' . esc_html( $title ) . '</span></li>';
		}
		echo '</ul>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['prayer_rule_order_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['prayer_rule_order_nonce'] ) ), 'prayer_rule_order' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$items = isset( $_POST['prayer_rule_items'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prayer_rule_items'] ) ) : '';
		update_post_meta( $post_id, self::META_ITEMS, $items );
	}

	/** Names of the services that use this order. */
	public static function used_by( $order_id ) {
		$names = array();
		foreach ( Services::all() as $service ) {
			if ( (int) $order_id === $service['order'] ) {
				$names[] = $service['name'];
			}
		}
		return $names;
	}

	public static function steps( $order_id ) {
		return self::parse_steps( (string) get_post_meta( $order_id, self::META_ITEMS, true ) );
	}

	/**
	 * Steps from typed lines:
	 *   array( 'type' => 'text', 'slug' => … )
	 *   array( 'type' => 'psalm', 'pieces' => … )
	 *   array( 'type' => 'rule', 'portion' => 'main' | 'first-hour' )
	 *   array( 'type' => 'unknown', 'line' => … )
	 */
	public static function parse_steps( $items ) {
		$steps = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $items ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$lower = strtolower( preg_replace( '/\s+/', ' ', $line ) );
			if ( preg_match( '/^rule(?: ([a-z-]+))?$/', $lower, $m ) && in_array( $m[1] ?? 'main', array( 'main', 'first-hour' ), true ) ) {
				$steps[] = array(
					'type'    => 'rule',
					'portion' => $m[1] ?? 'main',
				);
			} elseif ( preg_match( '/^psalms? (.+)$/', $lower, $m ) && Kathismas::pieces( $m[1] ) ) {
				$steps[] = array(
					'type'   => 'psalm',
					'pieces' => Kathismas::pieces( $m[1] ),
				);
			} elseif ( preg_match( '/^[a-z0-9_-]+$/', $lower ) ) {
				$steps[] = array(
					'type' => 'text',
					'slug' => $lower,
				);
			} else {
				$steps[] = array(
					'type' => 'unknown',
					'line' => $line,
				);
			}
		}
		return $steps;
	}

	/** Lines not understood, and prayers not found. */
	public static function problems( $order_id ) {
		$problems = array();
		$missing  = array();
		$rules    = 0;
		foreach ( self::steps( $order_id ) as $step ) {
			if ( 'unknown' === $step['type'] ) {
				/* translators: %s: the line as typed */
				$problems[] = sprintf( __( 'Not understood: "%s"', 'prayer-rule' ), $step['line'] );
			} elseif ( 'text' === $step['type'] && ! Texts::find( $step['slug'] ) ) {
				$missing[ $step['slug'] ] = true;
			} elseif ( 'rule' === $step['type'] ) {
				++$rules;
			}
		}
		foreach ( array_keys( $missing ) as $slug ) {
			/* translators: %s: a prayer's short name */
			$problems[] = sprintf( __( 'No published prayer with the short name "%s"', 'prayer-rule' ), $slug );
		}
		if ( ! $rules ) {
			$problems[] = __( 'There is no "rule" line, so the member\'s kathismas or ladders have nowhere to go.', 'prayer-rule' );
		}
		return $problems;
	}

	/** Orders as id => title. */
	public static function index( $statuses = array( 'publish' ) ) {
		$posts = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => $statuses,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$index = array();
		foreach ( $posts as $post ) {
			$index[ $post->ID ] = $post->post_title;
		}
		return $index;
	}
}
