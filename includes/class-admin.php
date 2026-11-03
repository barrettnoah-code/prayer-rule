<?php
/**
 * Admin screens: Overview, Services, Kathismas, Preview, Settings.
 * (Prayers and Orders are post types listed in the same menu.)
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	const SLUG = 'prayer-rule';

	public static function boot() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu_order' ), 99 );
		add_action( 'admin_post_prayer_rule_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_prayer_rule_kathismas', array( __CLASS__, 'save_kathismas' ) );
		add_action( 'admin_post_prayer_rule_services', array( __CLASS__, 'save_services' ) );
		add_action( 'admin_post_prayer_rule_dismissals', array( __CLASS__, 'save_dismissals' ) );
		add_action( 'admin_post_prayer_rule_ustav', array( __CLASS__, 'import_ustav' ) );
		add_action( 'admin_post_prayer_rule_saints', array( __CLASS__, 'save_saints' ) );
		add_action( 'admin_post_prayer_rule_fasting', array( __CLASS__, 'save_fasting' ) );
		add_filter( 'manage_' . Texts::TYPE . '_posts_columns', array( __CLASS__, 'text_columns' ) );
		add_action( 'manage_' . Texts::TYPE . '_posts_custom_column', array( __CLASS__, 'text_column' ), 10, 2 );
	}

	public static function menu() {
		add_menu_page( __( 'Prayer Rule', 'prayer-rule' ), __( 'Prayer Rule', 'prayer-rule' ), 'edit_pages', self::SLUG, array( __CLASS__, 'page_overview' ), 'dashicons-book-alt', 58 );
		add_submenu_page( self::SLUG, __( 'Overview', 'prayer-rule' ), __( 'Overview', 'prayer-rule' ), 'edit_pages', self::SLUG, array( __CLASS__, 'page_overview' ) );
		add_submenu_page( self::SLUG, __( 'Services', 'prayer-rule' ), __( 'Services', 'prayer-rule' ), 'manage_options', 'prayer-rule-services', array( __CLASS__, 'page_services' ) );
		add_submenu_page( self::SLUG, __( 'Dismissals and daily additions', 'prayer-rule' ), __( 'Dismissals', 'prayer-rule' ), 'manage_options', 'prayer-rule-dismissals', array( __CLASS__, 'page_dismissals' ) );
		add_submenu_page( self::SLUG, __( 'Fasting', 'prayer-rule' ), __( 'Fasting', 'prayer-rule' ), 'manage_options', 'prayer-rule-fasting', array( __CLASS__, 'page_fasting' ) );
		add_submenu_page( self::SLUG, __( 'Saints', 'prayer-rule' ), __( 'Saints', 'prayer-rule' ), 'manage_options', 'prayer-rule-saints', array( __CLASS__, 'page_saints' ) );
		add_submenu_page( self::SLUG, __( 'Kathismas', 'prayer-rule' ), __( 'Kathismas', 'prayer-rule' ), 'manage_options', 'prayer-rule-kathismas', array( __CLASS__, 'page_kathismas' ) );
		add_submenu_page( self::SLUG, __( 'Preview', 'prayer-rule' ), __( 'Preview', 'prayer-rule' ), 'edit_pages', 'prayer-rule-preview', array( __CLASS__, 'page_preview' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'prayer-rule' ), __( 'Settings', 'prayer-rule' ), 'manage_options', 'prayer-rule-settings', array( __CLASS__, 'page_settings' ) );
	}

	/** Overview, Prayers, Orders, Services, Dismissals, Fasting, Saints, Kathismas, Preview, Settings. */
	public static function menu_order() {
		global $submenu;
		if ( empty( $submenu[ self::SLUG ] ) ) {
			return;
		}
		$want = array( self::SLUG, 'edit.php?post_type=' . Texts::TYPE, 'edit.php?post_type=' . Orders::TYPE, 'prayer-rule-services', 'prayer-rule-dismissals', 'prayer-rule-fasting', 'prayer-rule-saints', 'prayer-rule-kathismas', 'prayer-rule-preview', 'prayer-rule-settings' );
		usort(
			$submenu[ self::SLUG ],
			function ( $a, $b ) use ( $want ) {
				$x = array_search( $a[2], $want, true );
				$y = array_search( $b[2], $want, true );
				return ( false === $x ? 99 : $x ) - ( false === $y ? 99 : $y );
			}
		);
	}

	/** Prayers list: show each prayer's short name. */
	public static function text_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['prayer_rule_slug'] = __( 'Short name', 'prayer-rule' );
			}
		}
		return $new;
	}

	public static function text_column( $column, $post_id ) {
		if ( 'prayer_rule_slug' === $column ) {
			$name = (string) get_post_meta( $post_id, Texts::META_NAME, true );
			echo $name ? '<code>' . esc_html( $name ) . '</code>' : '<span class="description">' . esc_html__( '(none yet)', 'prayer-rule' ) . '</span>';
		}
	}

	private static function notice() {
		if ( empty( $_GET['pr_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$msg   = sanitize_text_field( wp_unslash( $_GET['pr_msg'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$error = ! empty( $_GET['pr_error'] ); // phpcs:ignore WordPress.Security.NonceVerification
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $error ? 'error' : 'success', esc_html( $msg ) );
	}

	private static function back( $page, $msg, $error = false, $extra = array() ) {
		$args = array_merge(
			array(
				'page'   => $page,
				'pr_msg' => rawurlencode( $msg ),
			),
			$error ? array( 'pr_error' => 1 ) : array(),
			$extra
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	// ------------------------------------------------------------ Overview

	public static function page_overview() {
		$counts = Psalter::counts();
		$names  = Psalter::translations();
		$ustav  = Ustav::status();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Prayer Rule', 'prayer-rule' ); ?></h1>
			<?php self::notice(); ?>
			<h2><?php esc_html_e( 'Home Ustav texts', 'prayer-rule' ); ?></h2>
			<p>
				<?php
				/* translators: 1, 2: prayers on the site / bundled, 3, 4: orders on the site / bundled */
				echo esc_html( sprintf( __( 'On the site: %1$d of %2$d prayers and %3$d of %4$d service orders from the Home Ustav. They are ordinary Prayers and Orders: edit them freely; nothing you change is overwritten.', 'prayer-rule' ), $ustav['prayers'], $ustav['prayers_total'], $ustav['orders'], $ustav['orders_total'] ) );
				?>
			</p>
			<?php if ( current_user_can( 'manage_options' ) && ( $ustav['prayers'] < $ustav['prayers_total'] || $ustav['orders'] < $ustav['orders_total'] ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="prayer_rule_ustav">
					<?php wp_nonce_field( 'prayer_rule_ustav' ); ?>
					<?php submit_button( __( 'Add the missing ones again', 'prayer-rule' ), 'secondary', '', false ); ?>
				</form>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Members', 'prayer-rule' ); ?></h2>
			<p>
				<?php
				/* translators: %d: number of members */
				echo esc_html( sprintf( _n( '%d member has set up a private rule.', '%d members have set up a private rule.', Vault::count(), 'prayer-rule' ), Vault::count() ) );
				?>
				<?php esc_html_e( 'Their rules and records are encrypted in their browsers; the site keeps only ciphertext, which no one here can read.', 'prayer-rule' ); ?>
			</p>
			<h2><?php esc_html_e( 'Psalter', 'prayer-rule' ); ?></h2>
			<?php if ( ! $counts ) : ?>
				<p><?php esc_html_e( 'The Psalter is not loaded. Deactivate and activate the plugin, or use Settings → Reload the Psalter.', 'prayer-rule' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:640px">
					<thead><tr><th><?php esc_html_e( 'Translation', 'prayer-rule' ); ?></th><th><?php esc_html_e( 'Psalms', 'prayer-rule' ); ?></th><th><?php esc_html_e( 'Verses', 'prayer-rule' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $names as $id => $name ) : ?>
						<tr>
							<td><?php echo esc_html( $name ); ?><?php echo Psalter::default_translation() === $id ? ' <span class="description">(' . esc_html__( 'default', 'prayer-rule' ) . ')</span>' : ''; ?></td>
							<td><?php echo (int) ( $counts[ $id ]['psalms'] ?? 0 ); ?></td>
							<td><?php echo (int) ( $counts[ $id ]['verses'] ?? 0 ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<h2><?php esc_html_e( 'Getting started', 'prayer-rule' ); ?></h2>
			<ol>
				<li><?php esc_html_e( 'The Home Ustav prayers and the six service orders are already added. Edit them under Prayers and Orders, or add your own; a prayer\'s short name (in the side box) is how orders refer to it.', 'prayer-rule' ); ?></li>
				<li><?php esc_html_e( 'Under Services, check the order for each service and the suggested amounts.', 'prayer-rule' ); ?></li>
				<li><?php esc_html_e( 'Under Dismissals, check which dismissal ends each service on each day.', 'prayer-rule' ); ?></li>
				<li><?php esc_html_e( 'Put [prayer_rule] on a page: that is the members\' reading page.', 'prayer-rule' ); ?></li>
				<li><?php esc_html_e( 'Check the result under Preview, with any portion and translation.', 'prayer-rule' ); ?></li>
			</ol>
		</div>
		<?php
	}

	// ------------------------------------------------------------ Kathismas

	public static function page_kathismas() {
		$typed = get_transient( 'prayer_rule_kathismas_draft_' . get_current_user_id() );
		if ( false === $typed ) {
			$typed = Kathismas::text();
		} else {
			delete_transient( 'prayer_rule_kathismas_draft_' . get_current_user_id() );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kathismas', 'prayer-rule' ); ?></h1>
			<?php self::notice(); ?>
			<p><?php esc_html_e( 'One kathisma per line: its number, a colon, then its three stases separated by |. Psalms use the Greek and Slavonic numbering. Use a-b for psalms in a row, commas between pieces, and psalm:a-b for verses of one psalm.', 'prayer-rule' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="prayer_rule_kathismas">
				<?php wp_nonce_field( 'prayer_rule_kathismas' ); ?>
				<textarea name="kathismas" rows="22" style="width:100%;max-width:640px;font-family:monospace"><?php echo esc_textarea( $typed ); ?></textarea>
				<p>
					<?php submit_button( __( 'Save kathismas', 'prayer-rule' ), 'primary', 'save', false ); ?>
					<?php submit_button( __( 'Reset to the standard division', 'prayer-rule' ), 'secondary', 'reset', false, array( 'onclick' => "return confirm('Replace your division with the standard one?');" ) ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	public static function save_kathismas() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'prayer-rule' ) );
		}
		check_admin_referer( 'prayer_rule_kathismas' );
		if ( isset( $_POST['reset'] ) ) {
			Kathismas::reset();
			self::back( 'prayer-rule-kathismas', __( 'Restored the standard division.', 'prayer-rule' ) );
		}
		$typed  = isset( $_POST['kathismas'] ) ? sanitize_textarea_field( wp_unslash( $_POST['kathismas'] ) ) : '';
		$result = Kathismas::save( $typed );
		if ( is_wp_error( $result ) ) {
			// Keep what was typed so it can be corrected.
			set_transient( 'prayer_rule_kathismas_draft_' . get_current_user_id(), $typed, 600 );
			self::back( 'prayer-rule-kathismas', $result->get_error_message() . ' ' . __( 'Nothing was saved.', 'prayer-rule' ), true );
		}
		self::back( 'prayer-rule-kathismas', __( 'Kathismas saved.', 'prayer-rule' ) );
	}

	// ------------------------------------------------------------ Services

	public static function page_services() {
		$services = Services::all();
		$orders   = Orders::index( array( 'publish', 'draft', 'pending', 'private' ) );
		$texts    = Services::rule_texts();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Services', 'prayer-rule' ); ?></h1>
			<?php self::notice(); ?>
			<p><?php esc_html_e( 'Each service uses one order (its opening and ending, with "rule" where the member\'s portion goes). The amounts are the suggestions members choose from: kathismas, and ladders of 100 Jesus Prayers (halves allowed, e.g. 2.5).', 'prayer-rule' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="prayer_rule_services">
				<?php wp_nonce_field( 'prayer_rule_services' ); ?>
				<table class="widefat striped" style="max-width:960px">
					<thead><tr>
						<th><?php esc_html_e( 'Service', 'prayer-rule' ); ?></th>
						<th><?php esc_html_e( 'Order', 'prayer-rule' ); ?></th>
						<th><?php esc_html_e( 'Portion', 'prayer-rule' ); ?></th>
						<th><?php esc_html_e( 'Kathismas', 'prayer-rule' ); ?></th>
						<th><?php esc_html_e( 'Ladders', 'prayer-rule' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $services as $key => $service ) : ?>
						<?php $first = true; ?>
						<?php foreach ( $service['portions'] as $portion => $p ) : ?>
							<tr>
								<?php if ( $first ) : ?>
									<td rowspan="<?php echo count( $service['portions'] ); ?>"><strong><?php echo esc_html( $service['name'] ); ?></strong></td>
									<td rowspan="<?php echo count( $service['portions'] ); ?>">
										<select name="services[<?php echo esc_attr( $key ); ?>][order]">
											<option value="0"><?php esc_html_e( '— choose —', 'prayer-rule' ); ?></option>
											<?php foreach ( $orders as $id => $title ) : ?>
												<option value="<?php echo (int) $id; ?>" <?php selected( $service['order'], $id ); ?>><?php echo esc_html( $title ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								<?php endif; ?>
								<td><?php echo esc_html( $p['name'] ); ?><?php echo 'main' !== $portion ? ' <code>rule ' . esc_html( $portion ) . '</code>' : ''; ?></td>
								<td><input type="text" class="small-text" style="width:8em" name="services[<?php echo esc_attr( $key ); ?>][portions][<?php echo esc_attr( $portion ); ?>][kathismas]" value="<?php echo esc_attr( implode( ', ', $p['kathismas'] ) ); ?>"></td>
								<td><input type="text" class="small-text" style="width:10em" name="services[<?php echo esc_attr( $key ); ?>][portions][<?php echo esc_attr( $portion ); ?>][ladders]" value="<?php echo esc_attr( implode( ', ', $p['ladders'] ) ); ?>"></td>
							</tr>
							<?php $first = false; ?>
						<?php endforeach; ?>
					<?php endforeach; ?>
					</tbody>
				</table>
				<h2><?php esc_html_e( 'Prayers inside the rule', 'prayer-rule' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pr-as"><?php esc_html_e( 'After each stasis', 'prayer-rule' ); ?></label></th>
						<td><input id="pr-as" type="text" class="regular-text code" name="rule_texts[after_stasis]" value="<?php echo esc_attr( $texts['after_stasis'] ); ?>"><p class="description"><?php esc_html_e( 'The prayer said at each "Glory" (the Alleluia). Leave empty for none.', 'prayer-rule' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="pr-ak"><?php esc_html_e( 'After each kathisma, in Great Lent', 'prayer-rule' ); ?></label></th>
						<td><input id="pr-ak" type="text" class="regular-text code" name="rule_texts[after_kathisma]" value="<?php echo esc_attr( $texts['after_kathisma'] ); ?>"><p class="description"><?php esc_html_e( 'The troparia and prayer of each kathisma; {n} becomes the kathisma number.', 'prayer-rule' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="pr-jp"><?php esc_html_e( 'With the ladders', 'prayer-rule' ); ?></label></th>
						<td><input id="pr-jp" type="text" class="regular-text code" name="rule_texts[jesus_prayer]" value="<?php echo esc_attr( $texts['jesus_prayer'] ); ?>"><p class="description"><?php esc_html_e( 'The Jesus Prayer as it is said, shown under the number of ladders.', 'prayer-rule' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save services', 'prayer-rule' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function save_services() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'prayer-rule' ) );
		}
		check_admin_referer( 'prayer_rule_services' );
		$posted = isset( $_POST['services'] ) ? map_deep( wp_unslash( (array) $_POST['services'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Services::save( $posted );
		$texts = isset( $_POST['rule_texts'] ) ? map_deep( wp_unslash( (array) $_POST['rule_texts'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Services::save_rule_texts( $texts );
		self::back( 'prayer-rule-services', __( 'Services saved.', 'prayer-rule' ) );
	}

	// ------------------------------------------------------------ Dismissals

	public static function page_dismissals() {
		$services = Services::all();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Dismissals and daily additions', 'prayer-rule' ); ?></h1>
			<?php self::notice(); ?>
			<p><?php esc_html_e( 'The day begins at sundown, so Vespers and Compline belong to the next day: Sunday Vespers is read on Saturday evening.', 'prayer-rule' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="prayer_rule_dismissals">
				<?php wp_nonce_field( 'prayer_rule_dismissals' ); ?>
				<h2><?php esc_html_e( 'Weekday dismissals', 'prayer-rule' ); ?></h2>
				<p><?php esc_html_e( 'What a "dismissal" line in an order shows on an ordinary day. On a feast, the feast\'s dismissal (listed below) takes its place. The lists offer the prayers whose short name begins with "dismissal".', 'prayer-rule' ); ?></p>
				<p class="description"><?php esc_html_e( 'In a dismissal\'s text, [saint] stands for the saint of the day, from the calendar (see Saints); "Saint N." when there is none.', 'prayer-rule' ); ?></p>
				<?php self::grid( 'dismissals', $services ); ?>
				<h2><?php esc_html_e( 'Daily additions', 'prayer-rule' ); ?></h2>
				<p><?php esc_html_e( 'What an "additions" line in an order shows: the Home Ustav\'s prayers after the kathismas for those deprived of the common service. On a feast: at Vespers, prayer to the feast; at Compline, no prostrations for the departed; at the Midnight Office, none; when a great feast of the Lord falls on a Sunday, prayer to the feast at Matins and the Hours instead of to the Resurrection.', 'prayer-rule' ); ?></p>
				<?php self::grid( 'additions', $services ); ?>
				<?php submit_button( __( 'Save', 'prayer-rule' ) ); ?>
			</form>
			<h2><?php esc_html_e( 'Feast dismissals', 'prayer-rule' ); ?></h2>
			<p><?php esc_html_e( 'From the Home Ustav. They take the place of the weekday dismissal at Vespers (read the evening before), Matins and the Hours. On a Sunday the Sunday dismissal stays, except where marked. Edit a text under Prayers by its short name; the dates are set in tools/ustav/feasts.txt.', 'prayer-rule' ); ?></p>
			<p><?php esc_html_e( 'From Thomas Sunday to the leave-taking of Pascha, the weekday dismissal begins "O Thou who didst rise from the dead".', 'prayer-rule' ); ?></p>
			<table class="widefat striped" style="max-width:1200px">
				<thead><tr>
					<th><?php esc_html_e( 'When (church calendar)', 'prayer-rule' ); ?></th>
					<th><?php esc_html_e( 'Feast', 'prayer-rule' ); ?></th>
					<th><?php esc_html_e( 'On a Sunday', 'prayer-rule' ); ?></th>
					<th><?php esc_html_e( 'Prayer', 'prayer-rule' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( Feasts::listing() as $row ) : ?>
					<?php $post = Texts::find( $row['slug'] ); ?>
					<tr>
						<td><?php echo esc_html( $row['when'] ); ?></td>
						<td><?php echo esc_html( $row['title'] ); ?></td>
						<td>
							<?php
							if ( 'feast' === $row['on_sunday'] ) {
								esc_html_e( 'this dismissal', 'prayer-rule' );
							} elseif ( 'weekly' === $row['on_sunday'] ) {
								esc_html_e( 'the Sunday dismissal', 'prayer-rule' );
							} else {
								echo '<code>' . esc_html( $row['on_sunday'] ) . '</code>';
							}
							?>
						</td>
						<td>
							<?php if ( $post ) : ?>
								<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><code><?php echo esc_html( $row['slug'] ); ?></code></a>
							<?php else : ?>
								<code><?php echo esc_html( $row['slug'] ); ?></code> <span style="color:#b32d2e"><?php esc_html_e( 'not on the site', 'prayer-rule' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** A service × day table of prayer choices. $kind: dismissals | additions */
	private static function grid( $kind, $services ) {
		$table   = Dismissals::table( $kind );
		$saved   = Dismissals::saved( $kind );
		$index   = Texts::index();
		$prefix  = 'additions' === $kind ? 'additions' : 'dismissal';
		$choices = array_filter(
			$index,
			function ( $slug ) use ( $prefix ) {
				return 0 === strpos( $slug, $prefix );
			},
			ARRAY_FILTER_USE_KEY
		);
		$label   = function ( $slug ) use ( $index ) {
			if ( '' === $slug ) {
				return __( 'none', 'prayer-rule' );
			}
			return isset( $index[ $slug ] ) ? preg_replace( '/^(Dismissal|Daily additions):\s*/', '', $index[ $slug ] ) : $slug;
		};
		?>
		<table class="widefat striped" style="max-width:1200px">
			<thead><tr>
				<th><?php esc_html_e( 'Day', 'prayer-rule' ); ?></th>
				<?php foreach ( $services as $service ) : ?>
					<th><?php echo esc_html( $service['name'] ); ?></th>
				<?php endforeach; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( Dismissals::days() as $day => $day_name ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $day_name ); ?></th>
					<?php foreach ( array_keys( $services ) as $key ) : ?>
						<?php
						$raw  = (string) ( $saved[ $key ][ $day ] ?? '' );
						$slug = $table[ $key ][ $day ];
						?>
						<td>
							<select style="width:100%;max-width:14em" name="<?php echo esc_attr( $kind ); ?>[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $day ); ?>]">
								<option value="" <?php selected( $raw, '' ); ?>>
									<?php
									/* translators: %s: the Home Ustav's choice */
									echo esc_html( sprintf( __( 'Ustav: %s', 'prayer-rule' ), $label( (string) ( Ustav::data()[ $kind ][ $key ][ $day ] ?? '' ) ) ) );
									?>
								</option>
								<option value="<?php echo esc_attr( Dismissals::NONE ); ?>" <?php selected( $raw, Dismissals::NONE ); ?>><?php esc_html_e( '(none)', 'prayer-rule' ); ?></option>
								<?php foreach ( $choices as $value => $title ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $raw, $value ); ?>><?php echo esc_html( $label( $value ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<?php if ( '' !== $slug && ! isset( $index[ $slug ] ) ) : ?>
								<br><span style="color:#b32d2e">
								<?php
								/* translators: %s: a prayer's short name */
								echo esc_html( sprintf( __( 'No published prayer "%s"', 'prayer-rule' ), $slug ) );
								?>
								</span>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function save_dismissals() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'prayer-rule' ) );
		}
		check_admin_referer( 'prayer_rule_dismissals' );
		foreach ( array( 'dismissals', 'additions' ) as $kind ) {
			$posted = isset( $_POST[ $kind ] ) ? map_deep( wp_unslash( (array) $_POST[ $kind ] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			Dismissals::save( $posted, $kind );
		}
		self::back( 'prayer-rule-dismissals', __( 'Saved.', 'prayer-rule' ) );
	}

	public static function import_ustav() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'prayer-rule' ) );
		}
		check_admin_referer( 'prayer_rule_ustav' );
		$count = Ustav::import( false );
		/* translators: 1: prayers added, 2: orders added, 3: prayers and orders brought up to date */
		self::back( self::SLUG, sprintf( __( 'Added %1$d prayers and %2$d orders; brought %3$d unedited prayers and orders up to date.', 'prayer-rule' ), $count['prayers'], $count['orders'], $count['updated'] ) );
	}

	// ------------------------------------------------------------ Fasting

	public static function page_fasting() {
		$saved = Fasting::saved();
		$names = array(
			'sun' => __( 'Sunday', 'prayer-rule' ),
			'mon' => __( 'Monday', 'prayer-rule' ),
			'tue' => __( 'Tuesday', 'prayer-rule' ),
			'wed' => __( 'Wednesday', 'prayer-rule' ),
			'thu' => __( 'Thursday', 'prayer-rule' ),
			'fri' => __( 'Friday', 'prayer-rule' ),
			'sat' => __( 'Saturday', 'prayer-rule' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Fasting', 'prayer-rule' ); ?></h1>
			<?php self::notice(); ?>
			<p><?php esc_html_e( 'From the Home Ustav, chapter 1. Each day shows its period, a short line for the day, the general rule in full and, folded beneath it, the other typika.', 'prayer-rule' ); ?></p>
			<p><?php esc_html_e( 'The rule texts are Prayers: edit them there (the links below). The day lines are short summaries of the general rule; change any of them here. An empty box goes back to the Home Ustav line shown in grey. Special days, listed under each period, come first.', 'prayer-rule' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="prayer_rule_fasting">
				<?php wp_nonce_field( 'prayer_rule_fasting' ); ?>
				<?php foreach ( Fasting::periods() as $key => $period ) : ?>
					<h2 style="margin-top:2em"><?php echo esc_html( $period['title'] ); ?></h2>
					<p>
						<?php
						foreach ( array( '' => __( 'the rule', 'prayer-rule' ), '-other' => __( 'other typika', 'prayer-rule' ) ) as $suffix => $label ) {
							$post = Texts::find( 'fasting-' . $period['text'] . $suffix );
							if ( $post ) {
								echo '<a href="' . esc_url( get_edit_post_link( $post->ID ) ) . '">' . esc_html( $label ) . '</a> &nbsp; ';
							}
						}
						?>
					</p>
					<table class="widefat striped" style="max-width:1000px">
						<tbody>
						<?php foreach ( $names as $wd => $day_name ) : ?>
							<tr>
								<th scope="row" style="width:8em"><?php echo esc_html( $day_name ); ?></th>
								<td><input type="text" style="width:100%" name="fasting[<?php echo esc_attr( $key ); ?>][<?php echo esc_attr( $wd ); ?>]" value="<?php echo esc_attr( $saved[ $key ][ $wd ] ?? '' ); ?>" placeholder="<?php echo esc_attr( $period['day'][ $wd ] ?? __( 'Not a fast day.', 'prayer-rule' ) ); ?>"></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( $period['on'] || $period['feast'] ) : ?>
						<ul style="list-style:disc;margin-left:20px">
							<?php foreach ( $period['on'] as $on ) : ?>
								<li><?php echo esc_html( $on['text'] ); ?></li>
							<?php endforeach; ?>
							<?php if ( $period['feast'] ) : ?>
								<li><?php echo esc_html( $period['feast']['text'] ); ?></li>
							<?php endif; ?>
						</ul>
					<?php endif; ?>
				<?php endforeach; ?>
				<?php submit_button( __( 'Save day lines', 'prayer-rule' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function save_fasting() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'prayer-rule' ) );
		}
		check_admin_referer( 'prayer_rule_fasting' );
		$posted = isset( $_POST['fasting'] ) ? map_deep( wp_unslash( (array) $_POST['fasting'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Fasting::save( $posted );
		self::back( 'prayer-rule-fasting', __( 'Saved.', 'prayer-rule' ) );
	}

	// ------------------------------------------------------------ Saints

	public static function page_saints() {
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only choice of month
		$jm    = isset( $_GET['month'] ) ? min( 12, max( 1, absint( $_GET['month'] ) ) ) : Calendar::day( '' )['julian']['m'];
		$days  = Calendar::month( $jm );
		$names  = Calendar::names();
		$zapevy = Zapevy::overrides();
		global $wp_locale;
		?>
		<div class="wrap">
			<style><?php echo Render::sign_css(); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed CSS ?></style>
			<h1><?php esc_html_e( 'Saints in the dismissals and zapevy', 'prayer-rule' ); ?></h1>
			<?php self::notice(); ?>
			<?php if ( ! Calendar::available() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'The Orthodox Liturgical Calendar plugin is not active, so there are no saints to show. Dismissals say "Saint N." until it is.', 'prayer-rule' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'The saint of each day comes from the calendar: its highest-ranked commemoration for the date, worded for the dismissal ("and of …, whose day it is"). Where the wording is not right, type it as it should be said. An empty box goes back to the calendar\'s wording.', 'prayer-rule' ); ?></p>
			<p><?php esc_html_e( 'The zapev for the saint (said with the prostrations in the daily additions) is the Home Ustav\'s for that date where it gives one; otherwise it is made from the name in the dismissal, after the General Menaion ("O holy martyr N., pray to God for us"). Type it here where it is not right. On a great feast no zapev is made for a saint.', 'prayer-rule' ); ?></p>
			<p class="description"><?php esc_html_e( 'Church (Julian) dates. The calendar itself is not changed.', 'prayer-rule' ); ?></p>
			<form method="get" style="margin:1em 0">
				<input type="hidden" name="page" value="prayer-rule-saints">
				<select name="month" onchange="this.form.submit()">
					<?php for ( $m = 1; $m <= 12; $m++ ) : ?>
						<option value="<?php echo (int) $m; ?>" <?php selected( $jm, $m ); ?>><?php echo esc_html( $wp_locale->get_month( $m ) ); ?></option>
					<?php endfor; ?>
				</select>
				<noscript><?php submit_button( __( 'Show', 'prayer-rule' ), 'secondary', '', false ); ?></noscript>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="prayer_rule_saints">
				<input type="hidden" name="month" value="<?php echo (int) $jm; ?>">
				<?php wp_nonce_field( 'prayer_rule_saints' ); ?>
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr>
						<th style="width:4em"><?php esc_html_e( 'Day', 'prayer-rule' ); ?></th>
						<th><?php esc_html_e( 'In the calendar', 'prayer-rule' ); ?></th>
						<th style="width:28%"><?php esc_html_e( 'Named in the dismissal', 'prayer-rule' ); ?></th>
						<th style="width:30%"><?php esc_html_e( 'Zapev', 'prayer-rule' ); ?></th>
					</tr></thead>
					<tbody>
					<?php for ( $d = 1; $d <= ( 2 === $jm ? 29 : ( in_array( $jm, array( 4, 6, 9, 11 ), true ) ? 30 : 31 ) ); $d++ ) : ?>
						<?php
						$key  = $jm . '-' . $d;
						$auto = $days[ $d ]['name'] ?? '';
						$made = Zapevy::saint_lines(
							array(
								'julian'        => array( 'm' => $jm, 'd' => $d ),
								'weekday'       => -1,
								'before_pascha' => -1000,
								'after_pascha'  => 1000,
								'great'         => ! empty( $days[ $d ]['great'] ),
								'saint'         => '' !== ( $names[ $key ] ?? '' ) ? $names[ $key ] : $auto,
							),
							false
						)[0];
						?>
						<tr>
							<td><?php echo (int) $d; ?></td>
							<td><?php echo Render::signs( esc_html( implode( ' · ', $days[ $d ]['lines'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped, then signs wrapped ?></td>
							<td>
								<input type="text" style="width:100%" name="saints[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $names[ $key ] ?? '' ); ?>" placeholder="<?php echo esc_attr( $auto ); ?>">
							</td>
							<td>
								<input type="text" style="width:100%" name="zapevy[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $zapevy[ $key ] ?? '' ); ?>" placeholder="<?php echo esc_attr( Render::god_bearer( implode( ' / ', $made ) ) ); ?>">
							</td>
						</tr>
					<?php endfor; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save', 'prayer-rule' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function save_saints() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'prayer-rule' ) );
		}
		check_admin_referer( 'prayer_rule_saints' );
		$posted = isset( $_POST['saints'] ) ? map_deep( wp_unslash( (array) $_POST['saints'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Calendar::save_names( $posted );
		Zapevy::save_overrides( isset( $_POST['zapevy'] ) ? map_deep( wp_unslash( (array) $_POST['zapevy'] ), 'sanitize_text_field' ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		self::back( 'prayer-rule-saints', __( 'Saved.', 'prayer-rule' ), false, array( 'month' => isset( $_POST['month'] ) ? absint( $_POST['month'] ) : 1 ) );
	}

	/** The line above the preview: the day, its saint, Great Lent, Vespers on the eve. */
	private static function day_box( $service, $info ) {
		$parts = array( wp_date( 'l, F j, Y', strtotime( $info['civil'] . ' 12:00' ) ) );
		/* translators: %s: Julian date, e.g. October 20 */
		$parts[] = sprintf( __( 'church calendar %s', 'prayer-rule' ), Calendar::julian_label( $info['julian'] ) );
		if ( '' !== $info['label'] ) {
			$parts[] = $info['label'];
		}
		if ( $info['lent'] ) {
			$parts[] = __( 'Great Lent', 'prayer-rule' );
		}
		echo '<div style="max-width:52em;margin:0 0 1em">';
		echo '<p><strong>' . esc_html( implode( ' · ', $parts ) ) . '</strong></p>';
		if ( $info['lines'] ) {
			echo '<style>' . Render::sign_css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed CSS
			echo '<p>' . Render::signs( esc_html( implode( ' · ', $info['lines'] ) ) ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped, then signs wrapped
		}
		$feast = Feasts::find( $info, $service );
		if ( $feast ) {
			/* translators: %s: feast */
			echo '<p>' . esc_html( sprintf( __( 'Feast dismissal: %s.', 'prayer-rule' ), $feast['title'] ) ) . '</p>';
		} elseif ( '' !== Feasts::paschal_prefix( $info, $service ) ) {
			echo '<p>' . esc_html__( 'Paschal season: the dismissal begins "O Thou who didst rise from the dead".', 'prayer-rule' ) . '</p>';
		}
		echo '<p>' . esc_html( Bows::summary( $info ) ) . '</p>';
		$fast = Fasting::for_day( $info, Feasts::find( $info, 'matins' ) );
		if ( $fast ) {
			echo '<style>' . Render::css() . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed CSS
			echo Fasting::html( $fast ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Fasting::html
			if ( '' !== $info['fast_label'] ) {
				/* translators: %s: the calendar's name for the fast */
				echo '<p class="description">' . esc_html( sprintf( __( 'In the calendar: %s.', 'prayer-rule' ), $info['fast_label'] ) ) . '</p>';
			}
		}
		if ( '' !== $info['saint'] ) {
			$link = add_query_arg(
				array(
					'page'  => 'prayer-rule-saints',
					'month' => $info['julian']['m'],
				),
				admin_url( 'admin.php' )
			);
			/* translators: %s: the saint as named in the dismissal */
			echo '<p>' . esc_html( sprintf( __( 'In the dismissal: "and of %s, whose day it is".', 'prayer-rule' ), $info['saint'] ) ) . ' <a href="' . esc_url( $link ) . '">' . esc_html__( 'Change the wording', 'prayer-rule' ) . '</a></p>';
		} elseif ( ! Calendar::available() ) {
			echo '<p class="description">' . esc_html__( 'The calendar plugin is not active: the dismissal says "Saint N.".', 'prayer-rule' ) . '</p>';
		}
		if ( in_array( $service, array( 'vespers', 'compline' ), true ) ) {
			/* translators: %s: the evening before, e.g. Saturday */
			echo '<p class="description">' . esc_html( sprintf( __( 'This day\'s Vespers and Compline are read on the evening before: %s.', 'prayer-rule' ), wp_date( 'l, F j', strtotime( Calendar::eve( $info['civil'] ) . ' 12:00' ) ) ) ) . '</p>';
		}
		echo '</div>';
	}

	// ------------------------------------------------------------ Preview

	public static function page_preview() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only view
		$services    = Services::all();
		$key         = isset( $_GET['service'], $services[ sanitize_key( $_GET['service'] ) ] ) ? sanitize_key( $_GET['service'] ) : 'vespers';
		$service     = $services[ $key ];
		$translation = Psalter::translation( isset( $_GET['translation'] ) ? sanitize_key( $_GET['translation'] ) : '' );
		$date        = Calendar::civil( isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '' );
		$info        = Calendar::day( $date );
		$given       = isset( $_GET['p'] ) && is_array( $_GET['p'] ) ? wp_unslash( $_GET['p'] ) : array();
		$portions    = array();
		foreach ( $service['portions'] as $portion => $p ) {
			$portions[ $portion ] = array(
				'kathismas' => isset( $given[ $portion ]['kathismas'] ) ? absint( $given[ $portion ]['kathismas'] ) : (int) ( $p['kathismas'][0] ?? 0 ),
				'start'     => 'main' !== $portion ? 0 : ( isset( $given[ $portion ]['start'] ) ? min( 20, max( 1, absint( $given[ $portion ]['start'] ) ) ) : 1 ),
				'ladders'   => isset( $given[ $portion ]['ladders'] ) ? (float) $given[ $portion ]['ladders'] : 0,
			);
		}
		// phpcs:enable
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Preview', 'prayer-rule' ); ?></h1>
			<form method="get" style="margin:1em 0;line-height:2.4">
				<input type="hidden" name="page" value="prayer-rule-preview">
				<label><?php esc_html_e( 'Service', 'prayer-rule' ); ?>
					<select name="service" onchange="this.form.submit()">
						<?php foreach ( $services as $k => $s ) : ?>
							<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $key, $k ); ?>><?php echo esc_html( $s['name'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Translation', 'prayer-rule' ); ?>
					<select name="translation">
						<?php foreach ( Psalter::translations() as $id => $name ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $translation, $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Date', 'prayer-rule' ); ?>
					<input type="date" name="date" value="<?php echo esc_attr( $date ); ?>">
				</label>
				<?php foreach ( $service['portions'] as $portion => $p ) : ?>
					<br><strong><?php echo esc_html( $p['name'] ); ?>:</strong>
					<label><?php esc_html_e( 'kathismas', 'prayer-rule' ); ?>
						<select name="p[<?php echo esc_attr( $portion ); ?>][kathismas]">
							<?php for ( $n = 0; $n <= 5; $n++ ) : ?>
								<option value="<?php echo (int) $n; ?>" <?php selected( $portions[ $portion ]['kathismas'], $n ); ?>><?php echo (int) $n; ?></option>
							<?php endfor; ?>
						</select>
					</label>
					<?php if ( 'main' === $portion ) : ?>
						<label><?php esc_html_e( 'from kathisma', 'prayer-rule' ); ?>
							<select name="p[<?php echo esc_attr( $portion ); ?>][start]">
								<?php for ( $n = 1; $n <= 20; $n++ ) : ?>
									<option value="<?php echo (int) $n; ?>" <?php selected( $portions[ $portion ]['start'], $n ); ?>><?php echo (int) $n; ?></option>
								<?php endfor; ?>
							</select>
						</label>
					<?php else : ?>
						<span class="description"><?php esc_html_e( '(continues from the kathisma after the one before)', 'prayer-rule' ); ?></span>
					<?php endif; ?>
					<label><?php esc_html_e( 'ladders', 'prayer-rule' ); ?>
						<select name="p[<?php echo esc_attr( $portion ); ?>][ladders]">
							<?php for ( $n = 0; $n <= 14; $n++ ) : ?>
								<option value="<?php echo esc_attr( $n / 2 ); ?>" <?php selected( (string) $portions[ $portion ]['ladders'], (string) ( $n / 2 ) ); ?>><?php echo esc_html( $n / 2 ); ?></option>
							<?php endfor; ?>
						</select>
					</label>
				<?php endforeach; ?>
				<br><?php submit_button( __( 'Show', 'prayer-rule' ), 'secondary', '', false ); ?>
				<?php if ( $service['order'] ) : ?>
					<a href="<?php echo esc_url( get_edit_post_link( $service['order'] ) ); ?>"><?php esc_html_e( 'Edit this service\'s order', 'prayer-rule' ); ?></a>
				<?php endif; ?>
			</form>
			<?php if ( ! $service['order'] ) : ?>
				<p><?php esc_html_e( 'This service has no order yet. Choose one under Prayer Rule → Services.', 'prayer-rule' ); ?></p>
			</div>
				<?php
				return;
			endif;
			?>
			<style>
				<?php echo Render::css(); // phpcs:ignore WordPress.Security.EscapeOutput -- fixed CSS ?>
				.wrap .prayer-rule-reading{background:#fff;padding:1.5em 2em;border:1px solid #dcdcde;font-size:16px}
			</style>
			<?php
			$steps = Render::assemble_service(
				$key,
				array(
					'translation' => $translation,
					'portions'    => $portions,
					'date'        => $date,
				)
			);
			self::day_box( $key, $info );
			echo Render::html( $steps, true ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Render::html
			?>
		</div>
		<?php
	}

	// ------------------------------------------------------------ Settings

	public static function page_settings() {
		$access = Access::settings();
		$levels = Access::levels();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Prayer Rule settings', 'prayer-rule' ); ?></h1>
			<?php self::notice(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="prayer_rule_settings">
				<?php wp_nonce_field( 'prayer_rule_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Who may use it', 'prayer-rule' ); ?></th>
						<td>
							<label><input type="radio" name="mode" value="logged_in" <?php checked( 'logged_in', $access['mode'] ); ?>> <?php esc_html_e( 'Anyone logged in', 'prayer-rule' ); ?></label><br>
							<label><input type="radio" name="mode" value="levels" <?php checked( 'levels', $access['mode'] ); ?> <?php disabled( ! $levels ); ?>> <?php esc_html_e( 'Members with one of these levels:', 'prayer-rule' ); ?></label>
							<?php if ( $levels ) : ?>
								<fieldset style="margin:.5em 0 0 1.8em">
									<?php foreach ( $levels as $id => $name ) : ?>
										<label><input type="checkbox" name="levels[]" value="<?php echo (int) $id; ?>" <?php checked( in_array( $id, $access['levels'], true ) ); ?>> <?php echo esc_html( $name ); ?></label><br>
									<?php endforeach; ?>
								</fieldset>
							<?php else : ?>
								<p class="description" style="margin-left:1.8em"><?php esc_html_e( 'Paid Memberships Pro is not active, or has no levels.', 'prayer-rule' ); ?></p>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'Administrators can always use it.', 'prayer-rule' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pr-default"><?php esc_html_e( 'Default translation', 'prayer-rule' ); ?></label></th>
						<td>
							<select id="pr-default" name="default_translation">
								<?php foreach ( Psalter::translations() as $id => $name ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( Psalter::default_translation(), $id ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Coverdale', 'prayer-rule' ); ?></th>
						<td><label><input type="checkbox" name="show_colons" value="1" <?php checked( Render::show_colons() ); ?>> <?php esc_html_e( 'Show the colon that marks each half-verse ( : )', 'prayer-rule' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Psalms', 'prayer-rule' ); ?></th>
						<td><label><input type="checkbox" name="show_verse_numbers" value="1" <?php checked( Render::show_verse_numbers() ); ?>> <?php esc_html_e( 'Show verse numbers', 'prayer-rule' ); ?></label>
							<p class="description"><?php esc_html_e( 'The default; each member can change it on the reading page.', 'prayer-rule' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="pr-evening"><?php esc_html_e( 'Reading page', 'prayer-rule' ); ?></label></th>
						<td>
							<?php esc_html_e( 'The next day begins at', 'prayer-rule' ); ?>
							<select id="pr-evening" name="evening">
								<?php for ( $h = 12; $h <= 23; $h++ ) : ?>
									<option value="<?php echo (int) $h; ?>" <?php selected( Reader::evening_hour(), $h ); ?>><?php echo esc_html( sprintf( '%02d:00', $h ) ); ?></option>
								<?php endfor; ?>
							</select>
							<p class="description"><?php esc_html_e( 'From this hour (by the member\'s own clock) the reading page opens on the next day, since the day begins at sundown. Put [prayer_rule] on a page to make the reading page.', 'prayer-rule' ); ?></p>
						</td>
					</tr>
				</table>
				<p>
					<?php submit_button( __( 'Save settings', 'prayer-rule' ), 'primary', 'save', false ); ?>
					<?php submit_button( __( 'Reload the Psalter', 'prayer-rule' ), 'secondary', 'reload', false ); ?>
				</p>
			</form>
		</div>
		<?php
	}

	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'prayer-rule' ) );
		}
		check_admin_referer( 'prayer_rule_settings' );
		if ( isset( $_POST['reload'] ) ) {
			$result = Psalter::load();
			if ( is_wp_error( $result ) ) {
				self::back( 'prayer-rule-settings', $result->get_error_message(), true );
			}
			/* translators: %d: number of verses */
			self::back( 'prayer-rule-settings', sprintf( __( 'Psalter reloaded: %d verses.', 'prayer-rule' ), $result ) );
		}
		$mode   = isset( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : 'logged_in';
		$levels = isset( $_POST['levels'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['levels'] ) ) : array();
		if ( 'levels' === $mode && ! $levels ) {
			self::back( 'prayer-rule-settings', __( 'Choose at least one level, or allow anyone logged in. Nothing was saved.', 'prayer-rule' ), true );
		}
		Access::save( $mode, $levels );
		$default = isset( $_POST['default_translation'] ) ? sanitize_key( $_POST['default_translation'] ) : '';
		if ( isset( Psalter::translations()[ $default ] ) ) {
			update_option( Psalter::OPT_DEFAULT, $default );
		}
		update_option( Render::OPT_COLONS, isset( $_POST['show_colons'] ) ? '1' : '0' );
		update_option( Render::OPT_VERSES, isset( $_POST['show_verse_numbers'] ) ? '1' : '0' );
		update_option( Reader::OPT_EVENING, isset( $_POST['evening'] ) ? min( 23, max( 0, absint( $_POST['evening'] ) ) ) : 18 );
		self::back( 'prayer-rule-settings', __( 'Settings saved.', 'prayer-rule' ) );
	}
}
