<?php
/**
 * Plugin Name:       Prayer Rule
 * Description:       A private prayer rule for members: the daily cycle kept by kathismas and Jesus Prayers, a built-in Psalter, and an encrypted record of what was kept.
 * Version:           0.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            The Old Believers
 * Author URI:        https://theoldbelievers.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       prayer-rule
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRAYER_RULE_VERSION', '0.3.0' );
define( 'PRAYER_RULE_FILE', __FILE__ );
define( 'PRAYER_RULE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRAYER_RULE_URL', plugin_dir_url( __FILE__ ) );

require_once PRAYER_RULE_DIR . 'includes/class-access.php';
require_once PRAYER_RULE_DIR . 'includes/class-psalter.php';
require_once PRAYER_RULE_DIR . 'includes/class-kathismas.php';
require_once PRAYER_RULE_DIR . 'includes/class-texts.php';
require_once PRAYER_RULE_DIR . 'includes/class-services.php';
require_once PRAYER_RULE_DIR . 'includes/class-orders.php';
require_once PRAYER_RULE_DIR . 'includes/class-render.php';
require_once PRAYER_RULE_DIR . 'includes/class-rest.php';
require_once PRAYER_RULE_DIR . 'includes/class-admin.php';
require_once PRAYER_RULE_DIR . 'includes/class-editor.php';

register_activation_hook( __FILE__, array( 'PrayerRule\\Psalter', 'install' ) );

add_action( 'init', array( 'PrayerRule\\Texts', 'register' ) );
add_action( 'init', array( 'PrayerRule\\Orders', 'register' ) );
add_action( 'rest_api_init', array( 'PrayerRule\\Rest', 'register' ) );
add_action( 'admin_init', array( 'PrayerRule\\Psalter', 'maybe_update' ) );
// Every save (editor, REST, or code) gives a prayer its short name.
add_action( 'save_post_pr_text', array( 'PrayerRule\\Texts', 'save' ), 10, 2 );
add_action(
	'init',
	function () {
		add_shortcode( 'psalm', array( 'PrayerRule\\Render', 'shortcode' ) );
	}
);

if ( is_admin() ) {
	PrayerRule\Admin::boot();
	PrayerRule\Orders::boot_admin();
	PrayerRule\Texts::boot_admin();
	PrayerRule\Editor::boot();
}
