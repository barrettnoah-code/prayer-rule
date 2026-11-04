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
			'sharing'      => esc_url_raw( rest_url( Rest::NS . '/sharing/' ) ),
			'mykey'        => (string) get_user_meta( get_current_user_id(), Sharing::META_KEY, true ),
			'scripture'    => esc_url_raw( rest_url( Rest::NS . '/scripture' ) ),
			'bibles'       => Bible::for_reader(),
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
			$html .= '<p class="pr-day-lines">' . Render::signs( esc_html( implode( ' · ', $info['lines'] ) ) ) . '</p>';
		}
		if ( in_array( $service, array( 'vespers', 'compline' ), true ) ) {
			/* translators: %s: the evening before, e.g. Saturday, October 31 */
			$html .= '<p class="pr-day-eve">' . esc_html( sprintf( __( 'Read on the evening before: %s.', 'prayer-rule' ), wp_date( 'l, F j', strtotime( Calendar::eve( $info['civil'] ) . ' 12:00' ) ) ) ) . '</p>';
		}
		$html .= '<p class="pr-day-bows">' . esc_html( Bows::summary( $info ) ) . '</p>';
		$html .= Fasting::html( Fasting::for_day( $info, Feasts::find( $info, 'matins' ) ) );
		return $html . '</div>';
	}

	private static function css() {
		return '
.prayer-rule-reader{--pr-red:#921a0a;--pr-line:rgba(127,127,127,.28);--pr-tint:rgba(146,26,10,.045)}
.prayer-rule-reader select,.prayer-rule-reader input,.prayer-rule-reader button{font:inherit}
.prayer-rule-reader button{cursor:pointer}
.prayer-rule-reader .pr-head{border:1px solid var(--pr-line);border-radius:8px;background:var(--pr-tint);padding:.9em 1em;margin:0 0 1.8em;display:flex;flex-direction:column;gap:.8em}
.prayer-rule-reader .pr-controls{display:flex;flex-wrap:wrap;gap:.5em;align-items:center;justify-content:center}
.prayer-rule-reader .pr-controls input[type=date]{max-width:11em;padding:.3em .5em;border:1px solid var(--pr-line);border-radius:4px;background:transparent;color:inherit}
.prayer-rule-reader .pr-nav{background:transparent;border:1px solid var(--pr-line);border-radius:4px;color:var(--pr-red);font-size:1.3em;line-height:1;padding:.15em .6em .3em}
.prayer-rule-reader .pr-nav:hover,.prayer-rule-reader .pr-nav:focus-visible,.prayer-rule-reader .pr-today:hover,.prayer-rule-reader .pr-tool:hover{background:rgba(146,26,10,.1)}
.prayer-rule-reader .pr-today{background:transparent;border:1px solid var(--pr-line);border-radius:4px;color:inherit;padding:.3em .8em;font-size:.9em}
.prayer-rule-reader .pr-tabs{display:flex;flex-wrap:wrap;gap:.4em;justify-content:center;margin:0}
.prayer-rule-reader .pr-tabs button{padding:.4em .9em;border:1px solid var(--pr-line);background:transparent;color:inherit;border-radius:999px;font:inherit}
.prayer-rule-reader .pr-tabs button:hover{border-color:#921a0a}
.prayer-rule-reader .pr-tabs button[aria-pressed=true]{background:#921a0a;border-color:#921a0a;color:#fff}
.prayer-rule-reader .pr-tabs button.is-kept:not([aria-pressed=true]){border-color:#921a0a;color:#921a0a}
.prayer-rule-reader .pr-tools{display:flex;flex-wrap:wrap;gap:.5em;justify-content:center;border-top:1px solid var(--pr-line);padding-top:.8em}
.prayer-rule-reader .pr-tool{background:transparent;border:1px solid #921a0a;color:#921a0a;border-radius:4px;padding:.35em 1em;font-size:.85em;letter-spacing:.05em;text-transform:uppercase}
.prayer-rule-reader .pr-badge{display:inline-block;margin-left:.5em;min-width:1.5em;padding:0 .35em;border-radius:999px;background:#921a0a;color:#fff;font-size:.85em;text-align:center;letter-spacing:0}
.prayer-rule-reader .pr-dialog{border:0;border-radius:8px;padding:2.4em 2em 1.8em;width:calc(100% - 2em);max-width:760px;max-height:88vh;overflow-y:auto;overflow-x:hidden;box-sizing:border-box;box-shadow:0 8px 40px rgba(0,0,0,.35);background:#fff;color:#222}
.prayer-rule-reader .pr-dialog::backdrop{background:rgba(0,0,0,.55)}
.prayer-rule-reader .pr-dialog-close{position:absolute;top:.4em;right:.6em;background:none;border:0;font-size:1.9em;line-height:1;color:#999;padding:.1em .3em}
.prayer-rule-reader .pr-dialog-close:hover{color:#921a0a}
.prayer-rule-reader .pr-dialog-close:focus{outline:none;box-shadow:none}
.prayer-rule-reader .pr-dialog-close:focus-visible{outline:2px solid #921a0a}
.prayer-rule-reader .pr-dialog-body .pr-grid,.prayer-rule-reader .pr-dialog-body .pr-settings-table{display:block;overflow-x:auto;max-width:100%}
.prayer-rule-reader .pr-dialog-title{color:#921a0a;font-size:1.35em;margin:0 0 .8em;padding-bottom:.4em;border-bottom:1px solid var(--pr-line)}
.prayer-rule-reader .pr-dialog h4{margin:1.4em 0 .5em}
html.pr-modal-open{overflow:hidden}
@media (max-width:600px){.prayer-rule-reader .pr-dialog{padding:2.2em .9em 1.2em;width:calc(100% - .8em);max-height:92vh}.prayer-rule-reader .pr-controls{gap:.35em}.prayer-rule-reader .pr-head{padding:.8em .6em}}
/* On the members page the theme content column sets the width (the 42em limit is for the admin Preview). */
.prayer-rule-reader .prayer-rule-reading{max-width:none}
.prayer-rule-reader .pr-reading-section{text-align:center;color:#921a0a;font-weight:normal;font-size:1.1em;letter-spacing:.06em;text-transform:uppercase;margin:2em 0 .4em}
.prayer-rule-reader .pr-scripture .pr-chapter{margin:0 0 1.4em}
.prayer-rule-reader .pr-chapter-title{font-style:italic;opacity:.8}
.prayer-rule-reader .pr-tabs .pr-tab-reading{margin-left:.6em;border-style:dashed}
.prayer-rule-reader .pr-tabs .pr-tab-reading[aria-pressed=true]{border-style:solid}
.prayer-rule-reader .pr-reading-settings{border-top:1px solid var(--pr-line);margin-top:1.2em;padding-top:.4em}
.prayer-rule-reader .pr-reading-settings p label{display:inline-block;margin:.2em 1.2em .2em 0}
.prayer-rule-reader .pr-reading-settings th{text-align:left;padding-right:1em;white-space:nowrap}
.prayer-rule-reader .pr-service-title{text-align:center;color:#921a0a;font-weight:normal;letter-spacing:.04em;margin:1.4em 0 1em;display:flex;align-items:center;gap:.8em}
.prayer-rule-reader .pr-service-title::before,.prayer-rule-reader .pr-service-title::after{content:"";flex:1;border-top:1px solid var(--pr-line)}
.prayer-rule-reader .pr-settings table{border-collapse:collapse;margin:.6em 0}
.prayer-rule-reader .pr-settings td,.prayer-rule-reader .pr-settings th{padding:.25em .6em .25em 0;text-align:left;vertical-align:middle}
.prayer-rule-reader .pr-day{margin:0 0 1.2em;text-align:center}
.prayer-rule-reader .pr-day p{margin:.2em 0}
.prayer-rule-reader .pr-day-date{font-weight:600}
.prayer-rule-reader .pr-day-lines{font-size:.95em}
.prayer-rule-reader .pr-day-bows{font-size:.9em;opacity:.8}
.prayer-rule-reader .pr-day .pr-fasting{text-align:left}
.prayer-rule-reader .pr-day-eve,.prayer-rule-reader .pr-note{opacity:.75;font-size:.9em}
.prayer-rule-reader .pr-done{margin:2.4em 0 1em;padding:1em;border:1px solid var(--pr-line);border-radius:8px;background:var(--pr-tint);text-align:center}
.prayer-rule-reader .pr-done p{margin:.3em 0}
.prayer-rule-reader .pr-done button{cursor:pointer;padding:.45em 1em;font:inherit;margin:.25em 0}
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
.prayer-rule-reader .pr-footer{margin:3em 0 1em;padding-top:1em;border-top:1px solid rgba(127,127,127,.3);font-size:.9em}
.prayer-rule-reader .pr-footer .pr-lockline{opacity:.7}
.prayer-rule-reader .pr-footer details summary{cursor:pointer}
.prayer-rule-reader .pr-notice{border-left:4px solid #921a0a;padding:.5em 1em;margin:0 0 1.2em;background:rgba(146,26,10,.06)}
.prayer-rule-reader .pr-notice p{margin:.3em 0}
.prayer-rule-reader details.pr-record{margin:0 0 1.2em}
.prayer-rule-reader details.pr-record summary{cursor:pointer}
.prayer-rule-reader .pr-grid{border-collapse:collapse;margin:.6em 0;font-size:.92em}
.prayer-rule-reader .pr-grid th,.prayer-rule-reader .pr-grid td{padding:.2em .5em;text-align:center;border-bottom:1px solid rgba(127,127,127,.25)}
.prayer-rule-reader .pr-grid tr th:first-child{text-align:left;white-space:nowrap}
.prayer-rule-reader .pr-kept{font-weight:600}
.prayer-rule-reader details.pr-sharing{margin:0 0 1.2em}
.prayer-rule-reader details.pr-sharing summary,.prayer-rule-reader details.pr-suggest summary{cursor:pointer}
.prayer-rule-reader .pr-thread{margin:.4em 0 1em}
.prayer-rule-reader .pr-msg{border-left:3px solid rgba(127,127,127,.4);padding:.1em 0 .1em .8em;margin:.6em 0}
.prayer-rule-reader .pr-msg.is-mine{border-left-color:#921a0a}
.prayer-rule-reader .pr-msg p{margin:.2em 0}
.prayer-rule-reader .pr-msg-head{font-size:.85em;opacity:.75}
.prayer-rule-reader details.pr-suggest{margin:1em 0}
.prayer-rule-reader .pr-report-bar{display:flex;flex-wrap:wrap;gap:.5em;align-items:center;margin:.6em 0}
.prayer-rule-reader .pr-report-bar .pr-tabs{margin:0}
.prayer-rule-reader .pr-report-bar > button{cursor:pointer;padding:.2em .7em}
.prayer-rule-reader .pr-summary td{text-align:left}
.prayer-rule-reader .pr-bar{display:inline-block;height:.7em;background:#921a0a;vertical-align:middle;max-width:8em;min-width:1px}
.prayer-rule-reader .pr-summary td:last-child{min-width:12em}
.prayer-rule-reader .pr-report-lines{margin:.6em 0 1em 1.2em}
.prayer-rule-reader .pr-grid tr.is-today th{text-decoration:underline}
.prayer-rule-reader .pr-month{border-collapse:collapse;margin:.6em 0;table-layout:fixed;width:100%;max-width:32em}
.prayer-rule-reader .pr-month th{font-size:.8em;font-weight:normal;opacity:.7}
.prayer-rule-reader .pr-month td{border:1px solid rgba(127,127,127,.3);height:3.2em;vertical-align:top;padding:.2em .3em;cursor:pointer;font-size:.85em}
.prayer-rule-reader .pr-month td.is-future{opacity:.4;cursor:default}
.prayer-rule-reader .pr-month td.is-today{outline:2px solid #921a0a}
.prayer-rule-reader .pr-month-day{display:block;font-weight:600}
.prayer-rule-reader .pr-month-n{display:block;font-size:.85em}
@media print{.prayer-rule-reader .pr-dialog[open]{position:absolute;inset:0 auto auto 0;margin:0;padding:0;max-width:none;width:100%;max-height:none;overflow:visible;box-shadow:none}.prayer-rule-reader .pr-dialog::backdrop{background:none}body *{visibility:hidden}.prayer-rule-reader .pr-record,.prayer-rule-reader .pr-record *{visibility:visible}.prayer-rule-reader .pr-record{position:absolute;left:0;top:0;width:100%}.prayer-rule-reader .pr-record button{display:none}.prayer-rule-reader .pr-month td{-webkit-print-color-adjust:exact;print-color-adjust:exact}}';
	}
}
