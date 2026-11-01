<?php
/**
 * Orders: the reading order for a kathisma, built from prayers and the
 * kathisma's stases. Typed one item per line:
 *
 *   usual-beginning        a prayer, by its short name
 *   stasis 1               the psalms of the first stasis (also 2, 3)
 *   kathisma               all three stases, each followed by the
 *                          "after each stasis" prayer
 *   troparia-{n}           {n} becomes the kathisma's number
 *   # a note               ignored
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Orders {

	const TYPE        = 'pr_order';
	const META_ITEMS  = '_prayer_rule_items';
	const META_STASIS = '_prayer_rule_after_stasis';

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
		add_meta_box( 'prayer-rule-items', __( 'Reading order', 'prayer-rule' ), array( __CLASS__, 'box_items' ), self::TYPE, 'normal', 'high' );
		add_meta_box( 'prayer-rule-prayers', __( 'Prayers you can use', 'prayer-rule' ), array( __CLASS__, 'box_prayers' ), self::TYPE, 'side' );
	}

	public static function box_items( $post ) {
		wp_nonce_field( 'prayer_rule_order', 'prayer_rule_order_nonce' );
		$items  = get_post_meta( $post->ID, self::META_ITEMS, true );
		$stasis = get_post_meta( $post->ID, self::META_STASIS, true );
		?>
		<p><?php esc_html_e( 'One item per line, read from top to bottom:', 'prayer-rule' ); ?></p>
		<ul style="list-style:disc;margin-left:20px">
			<li><code>usual-beginning</code> — <?php esc_html_e( 'a prayer, by its short name', 'prayer-rule' ); ?></li>
			<li><code>stasis 1</code>, <code>stasis 2</code>, <code>stasis 3</code> — <?php esc_html_e( 'the psalms of that stasis', 'prayer-rule' ); ?></li>
			<li><code>kathisma</code> — <?php esc_html_e( 'all three stases, each followed by the prayer below', 'prayer-rule' ); ?></li>
			<li><code>psalm 50</code>, <code>psalm 90-91</code>, <code>psalm 118:1-72</code> — <?php esc_html_e( 'psalms in the reader\'s own translation', 'prayer-rule' ); ?></li>
			<li><code>troparia-{n}</code> — <?php esc_html_e( '{n} becomes the kathisma number (troparia-7)', 'prayer-rule' ); ?></li>
			<li><code># …</code> — <?php esc_html_e( 'a note, ignored', 'prayer-rule' ); ?></li>
		</ul>
		<p class="description"><?php esc_html_e( 'Inside a prayer\'s own text, write [psalm 50] where a psalm belongs; it too appears in the reader\'s translation.', 'prayer-rule' ); ?></p>
		<textarea name="prayer_rule_items" rows="14" style="width:100%;font-family:monospace"><?php echo esc_textarea( $items ); ?></textarea>
		<p>
			<label for="prayer_rule_after_stasis"><strong><?php esc_html_e( 'After each stasis (used by "kathisma")', 'prayer-rule' ); ?></strong></label><br>
			<input type="text" id="prayer_rule_after_stasis" name="prayer_rule_after_stasis" value="<?php echo esc_attr( $stasis ); ?>" class="regular-text" placeholder="glory-between-stases">
		</p>
		<?php
		$problems = self::problems( $post->ID );
		if ( $problems ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'To fix:', 'prayer-rule' ) . '</strong></p><ul style="list-style:disc;margin-left:20px">';
			foreach ( $problems as $problem ) {
				echo '<li>' . esc_html( $problem ) . '</li>';
			}
			echo '</ul></div>';
		}
		if ( 'publish' === $post->post_status || 'draft' === $post->post_status ) {
			$url = add_query_arg(
				array(
					'page'  => 'prayer-rule-preview',
					'order' => $post->ID,
				),
				admin_url( 'admin.php' )
			);
			echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Preview with a kathisma', 'prayer-rule' ) . '</a></p>';
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
		$items  = isset( $_POST['prayer_rule_items'] ) ? sanitize_textarea_field( wp_unslash( $_POST['prayer_rule_items'] ) ) : '';
		$stasis = isset( $_POST['prayer_rule_after_stasis'] ) ? sanitize_title( wp_unslash( $_POST['prayer_rule_after_stasis'] ) ) : '';
		update_post_meta( $post_id, self::META_ITEMS, $items );
		update_post_meta( $post_id, self::META_STASIS, $stasis );
	}

	/**
	 * The order as steps for one kathisma: each step is
	 *   array( 'type' => 'text', 'slug' => … )
	 *   array( 'type' => 'stasis', 'stasis' => 1-3 )
	 *   array( 'type' => 'unknown', 'line' => … )
	 */
	public static function steps( $order_id, $kathisma ) {
		return self::parse_steps(
			(string) get_post_meta( $order_id, self::META_ITEMS, true ),
			(string) get_post_meta( $order_id, self::META_STASIS, true ),
			$kathisma
		);
	}

	/** Steps from typed lines; $kathisma = null keeps {n} as typed. */
	public static function parse_steps( $items, $after, $kathisma ) {
		$steps  = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $items ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$lower = strtolower( $line );
			if ( preg_match( '/^stasis\s+([123])$/', $lower, $m ) ) {
				$steps[] = array(
					'type'   => 'stasis',
					'stasis' => (int) $m[1],
				);
			} elseif ( preg_match( '/^psalms?\s+(.+)$/', $lower, $m ) && Kathismas::pieces( $m[1] ) ) {
				$steps[] = array(
					'type'   => 'psalm',
					'pieces' => Kathismas::pieces( $m[1] ),
				);
			} elseif ( 'kathisma' === $lower ) {
				for ( $s = 1; $s <= 3; $s++ ) {
					$steps[] = array(
						'type'   => 'stasis',
						'stasis' => $s,
					);
					if ( '' !== $after ) {
						$steps[] = array(
							'type' => 'text',
							'slug' => $after,
						);
					}
				}
			} elseif ( preg_match( '/^[a-z0-9{}_-]+$/', $lower ) ) {
				$steps[] = array(
					'type' => 'text',
					'slug' => null === $kathisma ? $lower : str_replace( '{n}', (string) (int) $kathisma, $lower ),
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

	/**
	 * Problems with an order: lines not understood, and prayers not found
	 * (a {n} name is checked for every kathisma, 1-20).
	 */
	public static function problems( $order_id ) {
		$problems = array();
		$missing  = array();                   // typed name => kathismas
		$base     = self::steps( $order_id, 1 );
		$count    = count( $base );
		$typed    = self::typed_names( $order_id );
		for ( $n = 1; $n <= 20; $n++ ) {
			$steps = ( 1 === $n ) ? $base : self::steps( $order_id, $n );
			for ( $i = 0; $i < $count; $i++ ) {
				$step = $steps[ $i ];
				if ( 'unknown' === $step['type'] && 1 === $n ) {
					/* translators: %s: the line as typed */
					$problems[] = sprintf( __( 'Not understood: "%s"', 'prayer-rule' ), $step['line'] );
				} elseif ( 'text' === $step['type'] && ! Texts::find( $step['slug'] ) ) {
					$name               = $typed[ $i ] ?? $step['slug'];
					$missing[ $name ][] = $n;
				}
			}
		}
		foreach ( $missing as $name => $numbers ) {
			$numbers = array_unique( $numbers );
			if ( false === strpos( $name, '{n}' ) ) {
				/* translators: %s: a prayer's short name */
				$problems[] = sprintf( __( 'No published prayer with the short name "%s"', 'prayer-rule' ), $name );
			} else {
				/* translators: 1: a prayer's short name with {n}, 2: kathisma numbers */
				$problems[] = sprintf( __( 'No published prayer "%1$s" for kathisma %2$s', 'prayer-rule' ), $name, self::ranges( $numbers ) );
			}
		}
		return $problems;
	}

	/** The name as typed for each step (keeps {n}), in step order. */
	private static function typed_names( $order_id ) {
		$steps = self::parse_steps(
			(string) get_post_meta( $order_id, self::META_ITEMS, true ),
			(string) get_post_meta( $order_id, self::META_STASIS, true ),
			null
		);
		return array_map(
			function ( $step ) {
				return 'text' === $step['type'] ? $step['slug'] : null;
			},
			$steps
		);
	}

	/** array(1,2,3,5) => "1-3, 5" */
	private static function ranges( $numbers ) {
		sort( $numbers );
		$parts = array();
		$start = null;
		$prev  = null;
		foreach ( $numbers as $n ) {
			if ( null !== $prev && $n === $prev + 1 ) {
				$prev = $n;
				continue;
			}
			if ( null !== $start ) {
				$parts[] = $start === $prev ? (string) $start : "$start-$prev";
			}
			$start = $n;
			$prev  = $n;
		}
		if ( null !== $start ) {
			$parts[] = $start === $prev ? (string) $start : "$start-$prev";
		}
		return implode( ', ', $parts );
	}

	/** Published orders as id => title. */
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
