<?php
/**
 * The members' reading page: put [prayer_rule] on any page.
 *
 * A member chooses the day and the service; the page shows the day (church
 * date, commemorations, fasting) and the service with the member's portion
 * in place. A member who sets up a private rule has it encrypted in the
 * browser (assets/reader.js, stored by the Vault class as ciphertext) and
 * can mark each service as kept. Without one, the portion is chosen on
 * the page and remembered only in that browser.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Reader {

	const OPT_EVENING = 'prayer_rule_evening';   // hour (0-23) when the next day begins, for the default day

	public static function register() {
		add_shortcode( 'prayer_rule', array( __CLASS__, 'shortcode' ) );
	}

	public static function evening_hour() {
		$hour = (int) get_option( self::OPT_EVENING, 18 );
		return min( 23, max( 0, $hour ) );
	}

	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return '<div class="prayer-rule-reader"><p>' . esc_html__( 'Please log in to use the prayer rule.', 'prayer-rule' ) . ' <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', 'prayer-rule' ) . '</a></p></div>';
		}
		if ( ! Access::can_use() ) {
			$link = function_exists( 'pmpro_url' ) ? pmpro_url( 'levels' ) : '';
			return '<div class="prayer-rule-reader"><p>' . esc_html__( 'The prayer rule is for members.', 'prayer-rule' ) . ( $link ? ' <a href="' . esc_url( $link ) . '">' . esc_html__( 'Become a member', 'prayer-rule' ) . '</a>' : '' ) . '</p></div>';
		}
		$services = array();
		foreach ( Services::all() as $key => $service ) {
			if ( ! $service['order'] ) {
				continue;
			}
			$portions = array();
			foreach ( $service['portions'] as $pkey => $p ) {
				$portions[] = array(
					'key'       => $pkey,
					'name'      => $p['name'],
					'kathismas' => $p['kathismas'],
					'ladders'   => $p['ladders'],
				);
			}
			$services[] = array(
				'key'      => $key,
				'name'     => $service['name'],
				'portions' => $portions,
			);
		}
		$config = array(
			'rest'         => esc_url_raw( rest_url( Rest::NS . '/services/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'services'     => $services,
			'translations' => Psalter::translations(),
			'translation'  => Psalter::default_translation(),
			'verses'       => Render::show_verse_numbers(),
			'evening'      => self::evening_hour(),
			'user'         => get_current_user_id(),
			'vault'        => esc_url_raw( rest_url( Rest::NS . '/vault' ) ),
		);
		wp_enqueue_script( 'prayer-rule-reader', PRAYER_RULE_URL . 'assets/reader.js', array(), PRAYER_RULE_VERSION, true );
		wp_add_inline_script( 'prayer-rule-reader', 'window.prayerRuleReader = ' . wp_json_encode( $config ) . ';', 'before' );
		wp_register_style( 'prayer-rule-reader', false, array(), PRAYER_RULE_VERSION );
		wp_enqueue_style( 'prayer-rule-reader' );
		wp_add_inline_style( 'prayer-rule-reader', Render::css() . self::css() );
		return '<div class="prayer-rule-reader" id="prayer-rule-reader"><noscript>' . esc_html__( 'The prayer rule needs JavaScript.', 'prayer-rule' ) . '</noscript></div>';
	}

	/** The day above the service: dates, commemorations, fasting, the eve. */
	public static function day_html( $info, $service ) {
		$parts = array( wp_date( 'l, F j, Y', strtotime( $info['civil'] . ' 12:00' ) ) );
		/* translators: %s: Julian date, e.g. October 20 */
		$parts[] = sprintf( __( 'church calendar %s', 'prayer-rule' ), Calendar::julian_label( $info['julian'] ) );
		if ( '' !== $info['label'] ) {
			$parts[] = $info['label'];
		}
		$html = '<div class="pr-day"><p class="pr-day-date">' . esc_html( implode( ' · ', $parts ) ) . '</p>';
		if ( $info['lines'] ) {
			$html .= '<p class="pr-day-lines">' . esc_html( implode( ' · ', $info['lines'] ) ) . '</p>';
		}
		if ( in_array( $service, array( 'vespers', 'compline' ), true ) ) {
			/* translators: %s: the evening before, e.g. Saturday, October 31 */
			$html .= '<p class="pr-day-eve">' . esc_html( sprintf( __( 'Read on the evening before: %s.', 'prayer-rule' ), wp_date( 'l, F j', strtotime( Calendar::eve( $info['civil'] ) . ' 12:00' ) ) ) ) . '</p>';
		}
		$html .= Fasting::html( Fasting::for_day( $info, Feasts::find( $info, 'matins' ) ) );
		return $html . '</div>';
	}

	private static function css() {
		return '
.prayer-rule-reader select,.prayer-rule-reader input,.prayer-rule-reader button{font:inherit}
.prayer-rule-reader .pr-controls{display:flex;flex-wrap:wrap;gap:.6em 1em;align-items:center;margin:0 0 1em}
.prayer-rule-reader .pr-tabs{display:flex;flex-wrap:wrap;gap:.4em;margin:0 0 1em}
.prayer-rule-reader .pr-tabs button{cursor:pointer;padding:.35em .8em;border:1px solid currentColor;background:transparent;color:inherit;border-radius:3px;font:inherit}
.prayer-rule-reader .pr-tabs button[aria-pressed=true]{background:#921a0a;border-color:#921a0a;color:#fff}
.prayer-rule-reader details.pr-settings{margin:0 0 1.2em}
.prayer-rule-reader details.pr-settings summary{cursor:pointer}
.prayer-rule-reader .pr-settings table{border-collapse:collapse;margin:.6em 0}
.prayer-rule-reader .pr-settings td,.prayer-rule-reader .pr-settings th{padding:.25em .6em .25em 0;text-align:left;vertical-align:middle}
.prayer-rule-reader .pr-day{margin:0 0 1.2em}
.prayer-rule-reader .pr-day p{margin:.2em 0}
.prayer-rule-reader .pr-day-date{font-weight:600}
.prayer-rule-reader .pr-day-eve,.prayer-rule-reader .pr-note{opacity:.75;font-size:.9em}
.prayer-rule-reader .pr-done{margin:2em 0 1em}
.prayer-rule-reader .pr-done button{cursor:pointer;padding:.45em 1em;font:inherit}
.prayer-rule-reader .pr-tabs button.is-not-kept{opacity:.55}
.prayer-rule-reader .pr-box{border:1px solid currentColor;border-radius:4px;padding:.8em 1em;margin:0 0 1.2em}
.prayer-rule-reader .pr-box h3{margin-top:0}
.prayer-rule-reader .pr-field{display:block;margin:.4em 0}
.prayer-rule-reader .pr-field input{display:block;width:100%;max-width:24em;margin-top:.2em}
.prayer-rule-reader .pr-keys .pr-field{display:inline-block;margin-right:.6em}
.prayer-rule-reader .pr-keys .pr-field input{max-width:14em}
.prayer-rule-reader .pr-check{display:block;margin:.6em 0}
.prayer-rule-reader .pr-code{font-size:1.1em;letter-spacing:.06em;user-select:all}
.prayer-rule-reader .pr-primary{background:#921a0a;border:1px solid #921a0a;color:#fff;border-radius:3px;cursor:pointer;padding:.4em 1em}
.prayer-rule-reader .pr-link{background:none;border:0;padding:0;color:inherit;text-decoration:underline;cursor:pointer}
.prayer-rule-reader .pr-status.is-error{color:#b32d2e}
.prayer-rule-reader .pr-lockline{margin:0 0 1em;opacity:.85}
.prayer-rule-reader details.pr-record{margin:0 0 1.2em}
.prayer-rule-reader details.pr-record summary{cursor:pointer}
.prayer-rule-reader .pr-grid{border-collapse:collapse;margin:.6em 0;font-size:.92em}
.prayer-rule-reader .pr-grid th,.prayer-rule-reader .pr-grid td{padding:.2em .5em;text-align:center;border-bottom:1px solid rgba(127,127,127,.25)}
.prayer-rule-reader .pr-grid tr th:first-child{text-align:left;white-space:nowrap}
.prayer-rule-reader .pr-kept{font-weight:600}';
	}
}
