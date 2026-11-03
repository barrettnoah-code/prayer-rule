<?php
/**
 * Plugin Name:       Prayer Rule
 * Description:       A private prayer rule for members: the daily cycle kept by kathismas and Jesus Prayers, a built-in Psalter, and an encrypted record of what was kept.
 * Version:           0.17.0
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

define( 'PRAYER_RULE_VERSION', '0.17.0' );
define( 'PRAYER_RULE_FILE', __FILE__ );
define( 'PRAYER_RULE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRAYER_RULE_URL', plugin_dir_url( __FILE__ ) );

require_once PRAYER_RULE_DIR . 'includes/class-access.php';
require_once PRAYER_RULE_DIR . 'includes/class-psalter.php';
require_once PRAYER_RULE_DIR . 'includes/class-kathismas.php';
require_once PRAYER_RULE_DIR . 'includes/class-texts.php';
require_once PRAYER_RULE_DIR . 'includes/class-services.php';
require_once PRAYER_RULE_DIR . 'includes/class-dismissals.php';
require_once PRAYER_RULE_DIR . 'includes/class-calendar.php';
require_once PRAYER_RULE_DIR . 'includes/class-feasts.php';
require_once PRAYER_RULE_DIR . 'includes/class-fasting.php';
require_once PRAYER_RULE_DIR . 'includes/class-zapevy.php';
require_once PRAYER_RULE_DIR . 'includes/class-bows.php';
require_once PRAYER_RULE_DIR . 'includes/class-ustav.php';
require_once PRAYER_RULE_DIR . 'includes/class-orders.php';
require_once PRAYER_RULE_DIR . 'includes/class-render.php';
require_once PRAYER_RULE_DIR . 'includes/class-rest.php';
require_once PRAYER_RULE_DIR . 'includes/class-reader.php';
require_once PRAYER_RULE_DIR . 'includes/class-vault.php';
require_once PRAYER_RULE_DIR . 'includes/class-sharing.php';
require_once PRAYER_RULE_DIR . 'includes/class-admin.php';
require_once PRAYER_RULE_DIR . 'includes/class-editor.php';

register_activation_hook( __FILE__, array( 'PrayerRule\\Psalter', 'install' ) );
register_activation_hook( __FILE__, array( 'PrayerRule\\Vault', 'install' ) );

add_action( 'init', array( 'PrayerRule\\Texts', 'register' ) );
add_action( 'init', array( 'PrayerRule\\Orders', 'register' ) );
add_action( 'rest_api_init', array( 'PrayerRule\\Rest', 'register' ) );
add_action( 'rest_api_init', array( 'PrayerRule\\Vault', 'register_rest' ) );
add_action( 'rest_api_init', array( 'PrayerRule\\Sharing', 'register_rest' ) );
add_action( 'admin_init', array( 'PrayerRule\\Vault', 'maybe_install' ) );
// A deleted account takes its private rule with it.
add_action( 'delete_user', array( 'PrayerRule\\Vault', 'delete_user' ) );
add_action( 'admin_init', array( 'PrayerRule\\Psalter', 'maybe_update' ) );
// The Home Ustav prayers and orders: added once, and again when the file changes.
add_action( 'admin_init', array( 'PrayerRule\\Ustav', 'maybe_import' ) );
// Every save (editor, REST, or code) gives a prayer its short name.
add_action( 'save_post_pr_text', array( 'PrayerRule\\Texts', 'save' ), 10, 2 );
add_action(
	'init',
	function () {
		add_shortcode( 'psalm', array( 'PrayerRule\\Render', 'shortcode' ) );
		add_shortcode( 'saint', array( 'PrayerRule\\Render', 'saint_shortcode' ) );
		add_shortcode( 'zapev', array( 'PrayerRule\\Zapevy', 'shortcode' ) );
		add_shortcode( 'bow', array( 'PrayerRule\\Bows', 'shortcode' ) );
		PrayerRule\Reader::register();
	}
);

if ( is_admin() ) {
	PrayerRule\Admin::boot();
	PrayerRule\Orders::boot_admin();
	PrayerRule\Texts::boot_admin();
	PrayerRule\Editor::boot();
}
