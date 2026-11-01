<?php
/**
 * Admin screens: Overview, Kathismas, Preview, Settings.
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
		add_filter( 'manage_' . Texts::TYPE . '_posts_columns', array( __CLASS__, 'text_columns' ) );
		add_action( 'manage_' . Texts::TYPE . '_posts_custom_column', array( __CLASS__, 'text_column' ), 10, 2 );
	}

	public static function menu() {
		add_menu_page( __( 'Prayer Rule', 'prayer-rule' ), __( 'Prayer Rule', 'prayer-rule' ), 'edit_pages', self::SLUG, array( __CLASS__, 'page_overview' ), 'dashicons-book-alt', 58 );
		add_submenu_page( self::SLUG, __( 'Overview', 'prayer-rule' ), __( 'Overview', 'prayer-rule' ), 'edit_pages', self::SLUG, array( __CLASS__, 'page_overview' ) );
		add_submenu_page( self::SLUG, __( 'Kathismas', 'prayer-rule' ), __( 'Kathismas', 'prayer-rule' ), 'manage_options', 'prayer-rule-kathismas', array( __CLASS__, 'page_kathismas' ) );
		add_submenu_page( self::SLUG, __( 'Preview', 'prayer-rule' ), __( 'Preview', 'prayer-rule' ), 'edit_pages', 'prayer-rule-preview', array( __CLASS__, 'page_preview' ) );
		add_submenu_page( self::SLUG, __( 'Settings', 'prayer-rule' ), __( 'Settings', 'prayer-rule' ), 'manage_options', 'prayer-rule-settings', array( __CLASS__, 'page_settings' ) );
	}

	/** Overview, Prayers, Orders, Kathismas, Preview, Settings. */
	public static function menu_order() {
		global $submenu;
		if ( empty( $submenu[ self::SLUG ] ) ) {
			return;
		}
		$want = array( self::SLUG, 'edit.php?post_type=' . Texts::TYPE, 'edit.php?post_type=' . Orders::TYPE, 'prayer-rule-kathismas', 'prayer-rule-preview', 'prayer-rule-settings' );
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
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Prayer Rule', 'prayer-rule' ); ?></h1>
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
				<li><?php esc_html_e( 'Write each prayer under Prayers. Its short name (in the side box) is made from the title unless you type one.', 'prayer-rule' ); ?></li>
				<li><?php esc_html_e( 'Under Orders, list the prayers and stases in reading order.', 'prayer-rule' ); ?></li>
				<li><?php esc_html_e( 'Check the result under Preview, with any kathisma and translation.', 'prayer-rule' ); ?></li>
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

	// ------------------------------------------------------------ Preview

	public static function page_preview() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only view
		$orders      = Orders::index( array( 'publish', 'draft', 'pending', 'private' ) );
		$order       = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : (int) key( $orders );
		$kathisma    = isset( $_GET['kathisma'] ) ? min( 20, max( 1, absint( $_GET['kathisma'] ) ) ) : 1;
		$translation = Psalter::translation( isset( $_GET['translation'] ) ? sanitize_key( $_GET['translation'] ) : '' );
		// phpcs:enable
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Preview', 'prayer-rule' ); ?></h1>
			<?php if ( ! $orders ) : ?>
				<p><?php esc_html_e( 'Add an order first (Prayer Rule → Orders).', 'prayer-rule' ); ?></p>
			</div>
				<?php
				return;
			endif;
			?>
			<form method="get" style="margin:1em 0">
				<input type="hidden" name="page" value="prayer-rule-preview">
				<label><?php esc_html_e( 'Order', 'prayer-rule' ); ?>
					<select name="order">
						<?php foreach ( $orders as $id => $title ) : ?>
							<option value="<?php echo (int) $id; ?>" <?php selected( $order, $id ); ?>><?php echo esc_html( $title ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Kathisma', 'prayer-rule' ); ?>
					<select name="kathisma">
						<?php for ( $n = 1; $n <= 20; $n++ ) : ?>
							<option value="<?php echo (int) $n; ?>" <?php selected( $kathisma, $n ); ?>><?php echo (int) $n; ?></option>
						<?php endfor; ?>
					</select>
				</label>
				<label><?php esc_html_e( 'Translation', 'prayer-rule' ); ?>
					<select name="translation">
						<?php foreach ( Psalter::translations() as $id => $name ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $translation, $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<?php submit_button( __( 'Show', 'prayer-rule' ), 'secondary', '', false ); ?>
				<a href="<?php echo esc_url( get_edit_post_link( $order ) ); ?>"><?php esc_html_e( 'Edit this order', 'prayer-rule' ); ?></a>
			</form>
			<style>
				.prayer-rule-reading{max-width:42em;background:#fff;padding:1.5em 2em;border:1px solid #dcdcde;font-size:16px;line-height:1.6}
				.prayer-rule-reading .pr-stasis-label{font-size:13px;text-transform:uppercase;letter-spacing:.05em;color:#8a1c1c;margin:2em 0 0}
				.prayer-rule-reading .pr-psalm{font-size:1.1em;color:#8a1c1c;margin:1.2em 0 .4em}
				.prayer-rule-reading .pr-verse{margin:0 0 .35em}
				.prayer-rule-reading .pr-vn{font-size:.7em;color:#8c8f94;vertical-align:super;margin-right:.2em}
				.prayer-rule-reading .pr-problem{background:#fcf0f1;border-left:4px solid #d63638;padding:.4em .8em}
			</style>
			<?php
			echo Render::html( Render::assemble( $order, $kathisma, $translation ), $kathisma, true ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in Render::html
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
		self::back( 'prayer-rule-settings', __( 'Settings saved.', 'prayer-rule' ) );
	}
}
