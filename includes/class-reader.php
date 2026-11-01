<?php
/**
 * The members' reading page: put [prayer_rule] on any page.
 *
 * A member chooses the day and the service; the page shows the day (church
 * date, commemorations, fasting) and the service with the member's portion
 * in place. Until the private rule is built (a later phase), the portion is
 * chosen on the page and remembered only in the member's own browser:
 * nothing about it is stored on the site.
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
			'text'         => array(
				'day'         => __( 'Day', 'prayer-rule' ),
				'today'       => __( 'Today', 'prayer-rule' ),
				'settings'    => __( 'My portion and settings', 'prayer-rule' ),
				'translation' => __( 'Psalter', 'prayer-rule' ),
				'verses'      => __( 'Verse numbers', 'prayer-rule' ),
				'next'        => __( 'Next kathisma', 'prayer-rule' ),
				'kathismas'   => __( 'Kathismas', 'prayer-rule' ),
				'ladders'     => __( 'Ladders of the Jesus Prayer', 'prayer-rule' ),
				'none'        => __( 'none', 'prayer-rule' ),
				'loading'     => __( 'Loading…', 'prayer-rule' ),
				'error'       => __( 'The service could not be loaded. Please try again.', 'prayer-rule' ),
				/* translators: %d: kathisma number */
				'done'        => __( 'Finished: next time begin with kathisma %d', 'prayer-rule' ),
				'moved'       => __( 'The next kathisma is now %d.', 'prayer-rule' ),
				'private'     => __( 'Your portion is kept only in this browser.', 'prayer-rule' ),
			),
		);
		wp_register_script( 'prayer-rule-reader', false, array(), PRAYER_RULE_VERSION, true );
		wp_enqueue_script( 'prayer-rule-reader' );
		wp_add_inline_script( 'prayer-rule-reader', 'window.prayerRuleReader = ' . wp_json_encode( $config ) . ';' . "\n" . self::script() );
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
.prayer-rule-reader .pr-done button{cursor:pointer;padding:.45em 1em;font:inherit}';
	}

	/** The page's script: controls, remembered settings, loading the service. */
	private static function script() {
		return <<<'JS'
(function () {
	var C = window.prayerRuleReader, root = document.getElementById('prayer-rule-reader');
	if (!C || !root) { return; }
	var T = C.text, KEY = 'prayerRule.reader';
	function load() { try { return JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { return {}; } }
	function save() { try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) {} }
	var S = load();
	S.portions = S.portions || {};
	S.next = S.next || 1;
	if (!S.translation || !C.translations[S.translation]) { S.translation = C.translation; }
	if (typeof S.verses !== 'boolean') { S.verses = !!C.verses; }
	if (!S.service || !C.services.some(function (s) { return s.key === S.service; })) { S.service = C.services.length ? C.services[0].key : ''; }

	function iso(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
	function defaultDay() { var d = new Date(); if (d.getHours() >= C.evening) { d.setDate(d.getDate() + 1); } return iso(d); }
	var day = defaultDay();

	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') { e.textContent = attrs[k]; } else { e.setAttribute(k, attrs[k]); } });
		(kids || []).forEach(function (k) { e.appendChild(typeof k === 'string' ? document.createTextNode(k) : k); });
		return e;
	}
	function select(options, value, onchange) {
		var s = el('select');
		options.forEach(function (o) { var opt = el('option', { value: String(o[0]), text: String(o[1]) }); if (String(o[0]) === String(value)) { opt.selected = true; } s.appendChild(opt); });
		s.addEventListener('change', function () { onchange(s.value); });
		return s;
	}
	function service() { return C.services.filter(function (s) { return s.key === S.service; })[0]; }
	function portion(svc, p) {
		var key = svc.key + '/' + p.key;
		if (!S.portions[key]) { S.portions[key] = { kathismas: p.kathismas.length ? p.kathismas[0] : 0, ladders: 0 }; }
		return S.portions[key];
	}
	function half(n) { return String(n).replace('.5', '½').replace(/^0½/, '½'); }

	var dateInput = el('input', { type: 'date', value: day });
	dateInput.addEventListener('change', function () { if (dateInput.value) { day = dateInput.value; show(); } });
	var todayBtn = el('button', { type: 'button', text: T.today });
	todayBtn.addEventListener('click', function () { day = defaultDay(); dateInput.value = day; show(); });
	var tabs = el('div', { class: 'pr-tabs', role: 'group' });
	var settings = el('details', { class: 'pr-settings' });
	var out = el('div', { class: 'pr-out', 'aria-live': 'polite' });
	root.appendChild(el('div', { class: 'pr-controls' }, [el('label', {}, [T.day + ' ', dateInput]), todayBtn]));
	root.appendChild(tabs);
	root.appendChild(settings);
	root.appendChild(out);

	function drawTabs() {
		tabs.innerHTML = '';
		C.services.forEach(function (s) {
			var b = el('button', { type: 'button', 'aria-pressed': s.key === S.service ? 'true' : 'false', text: s.name });
			b.addEventListener('click', function () { S.service = s.key; save(); drawTabs(); drawSettings(); show(); });
			tabs.appendChild(b);
		});
	}
	function drawSettings() {
		var open = settings.open, svc = service();
		settings.innerHTML = '';
		settings.appendChild(el('summary', { text: T.settings }));
		var t = el('table');
		svc.portions.forEach(function (p) {
			var v = portion(svc, p);
			var k = [[0, T.none]].concat(p.kathismas.map(function (n) { return [n, n]; }));
			var l = [[0, T.none]].concat(p.ladders.map(function (n) { return [n, half(n)]; }));
			t.appendChild(el('tr', {}, [el('th', { colspan: '2', text: p.name })]));
			t.appendChild(el('tr', {}, [el('td', { text: T.kathismas }), el('td', {}, [select(k, v.kathismas, function (x) { v.kathismas = +x; save(); show(); })])]));
			t.appendChild(el('tr', {}, [el('td', { text: T.ladders }), el('td', {}, [select(l, v.ladders, function (x) { v.ladders = +x; save(); show(); })])]));
		});
		var nums = []; for (var i = 1; i <= 20; i++) { nums.push([i, i]); }
		t.appendChild(el('tr', {}, [el('td', { text: T.next }), el('td', {}, [select(nums, S.next, function (x) { S.next = +x; save(); show(); })])]));
		var tr = Object.keys(C.translations).map(function (k) { return [k, C.translations[k]]; });
		t.appendChild(el('tr', {}, [el('td', { text: T.translation }), el('td', {}, [select(tr, S.translation, function (x) { S.translation = x; save(); show(); })])]));
		var vb = el('input', { type: 'checkbox' }); vb.checked = S.verses;
		vb.addEventListener('change', function () { S.verses = vb.checked; save(); show(); });
		t.appendChild(el('tr', {}, [el('td', { text: T.verses }), el('td', {}, [vb])]));
		settings.appendChild(t);
		settings.appendChild(el('p', { class: 'pr-note', text: T['private'] }));
		settings.open = open;
	}

	var req = 0;
	function show() {
		var svc = service(); if (!svc) { return; }
		var mine = ++req, p = {}, start = S.next, total = 0;
		svc.portions.forEach(function (x, i) {
			var v = portion(svc, x);
			p[x.key] = { kathismas: v.kathismas, ladders: v.ladders };
			if (i === 0) { p[x.key].start = start; }
			total += v.kathismas;
		});
		out.innerHTML = ''; out.appendChild(el('p', { class: 'pr-note', text: T.loading }));
		fetch(C.rest + svc.key + '/reading', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce },
			body: JSON.stringify({ date: day, translation: S.translation, verses: S.verses ? 1 : 0, p: p })
		}).then(function (r) { if (!r.ok) { throw r; } return r.json(); }).then(function (data) {
			if (mine !== req) { return; }
			out.innerHTML = data.day_html + data.html;
			if (total > 0) {
				var next = ((start - 1 + total) % 20) + 1;
				var b = el('button', { type: 'button', text: T.done.replace('%d', next) });
				b.addEventListener('click', function () { S.next = next; save(); drawSettings(); b.parentNode.replaceChild(el('p', { text: T.moved.replace('%d', next) }), b); });
				out.appendChild(el('div', { class: 'pr-done' }, [b]));
			}
		}).catch(function () { if (mine === req) { out.innerHTML = ''; out.appendChild(el('p', { text: T.error })); } });
	}

	drawTabs(); drawSettings(); show();
})();
JS;
	}
}
