<?php
/**
 * Plugin Name:       Prayer Rule
 * Description:       A private prayer rule for members: the daily cycle kept by kathismas and Jesus Prayers, a built-in Psalter, and an encrypted record of what was kept.
 * Version:           0.1.0
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

define( 'PRAYER_RULE_VERSION', '0.1.0' );
define( 'PRAYER_RULE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRAYER_RULE_URL', plugin_dir_url( __FILE__ ) );

// The plugin is being built in stages; nothing runs yet.
