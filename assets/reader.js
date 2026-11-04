/*
 * Prayer Rule: the members' reading page ([prayer_rule]).
 *
 * The member's rule and record are encrypted here, in the browser, before
 * they are sent to the site. The site keeps only ciphertext:
 *   - a random data key (AES-GCM 256) encrypts the rule and the record;
 *   - the data key is stored wrapped twice: under a key derived from the
 *     passphrase, and under one derived from the recovery code
 *     (PBKDF2-SHA-256, many iterations).
 * Neither the passphrase, the recovery code nor the data key ever leaves
 * the browser. While unlocked, the data key is kept in this tab's
 * sessionStorage, and is gone when the tab closes or "Lock" is pressed.
 *
 * Sharing with a nastavnik: each member with a private rule also has an
 * ECDH (P-256) key pair; the public half is on the site, the private half
 * sealed with the data key. A member and a nastavnik derive the same pair
 * key from their two key pairs (ECDH + HKDF), and with it encrypt the copy
 * of the rule and record the member shares, and every message between
 * them. The site relays only ciphertext.
 */
(function () {
	'use strict';
	var C = window.prayerRuleReader, root = document.getElementById('prayer-rule-reader');
	if (!C || !root || !window.crypto || !crypto.subtle) {
		if (root) { root.textContent = 'This browser cannot run the prayer rule. Please use a current browser.'; }
		return;
	}

	var ITER = 600000, LOCAL = 'prayerRule.reader', SESSION = 'prayerRule.key.' + C.user;
	var enc = new TextEncoder(), dec = new TextDecoder();

	// ------------------------------------------------------------ small helpers

	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') { e.textContent = attrs[k]; } else if (k === 'on') { Object.keys(attrs.on).forEach(function (ev) { e.addEventListener(ev, attrs.on[ev]); }); } else { e.setAttribute(k, attrs[k]); }
		});
		(kids || []).forEach(function (k) { if (k) { e.appendChild(typeof k === 'string' ? document.createTextNode(k) : k); } });
		return e;
	}
	function select(options, value, onchange) {
		var s = el('select');
		options.forEach(function (o) { var opt = el('option', { value: String(o[0]), text: String(o[1]) }); if (String(o[0]) === String(value)) { opt.selected = true; } s.appendChild(opt); });
		s.addEventListener('change', function () { onchange(s.value); });
		return s;
	}
	function iso(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
	function parseIso(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2], 12); }
	function addDays(s, n) { var d = parseIso(s); d.setDate(d.getDate() + n); return iso(d); }
	function defaultDay() { var d = new Date(); if (d.getHours() >= C.evening) { d.setDate(d.getDate() + 1); } return iso(d); }
	function daysAgo(s) { return Math.round((parseIso(defaultDay()) - parseIso(s)) / 86400000); }
	function half(n) { return String(n).replace('.5', '½').replace(/^0½/, '½'); }
	function b64(bytes) { var s = ''; bytes = new Uint8Array(bytes); for (var i = 0; i < bytes.length; i++) { s += String.fromCharCode(bytes[i]); } return btoa(s); }
	function unb64(str) { var s = atob(str), b = new Uint8Array(s.length); for (var i = 0; i < s.length; i++) { b[i] = s.charCodeAt(i); } return b; }
	function rand(n) { return crypto.getRandomValues(new Uint8Array(n)); }
	function now() { return new Date().toISOString(); }
	/** JSON headers for the site; the nonce only for a logged-in reader (a visitor needs none, and a cached one would be stale). */
	function jsonHeaders() { var h = { 'Content-Type': 'application/json' }; if (C.nonce) { h['X-WP-Nonce'] = C.nonce; } return h; }
	function serviceByKey(k) { return C.services.filter(function (s) { return s.key === k; })[0]; }

	// ------------------------------------------------------------ crypto

	async function derive(secret, salt, iter) {
		var base = await crypto.subtle.importKey('raw', enc.encode(secret.normalize('NFKC')), 'PBKDF2', false, ['deriveKey']);
		return crypto.subtle.deriveKey({ name: 'PBKDF2', salt: salt, iterations: iter, hash: 'SHA-256' }, base, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
	}
	function aad(field) { return enc.encode('prayer-rule/1/' + C.user + '/' + field); }
	async function seal(key, bytes, field) {
		var iv = rand(12), ct = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: aad(field) }, key, bytes));
		var out = new Uint8Array(12 + ct.length); out.set(iv); out.set(ct, 12);
		return b64(out);
	}
	async function unseal(key, text, field) {
		var all = unb64(text);
		return new Uint8Array(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: all.slice(0, 12), additionalData: aad(field) }, key, all.slice(12)));
	}
	async function dataKey(raw) { return crypto.subtle.importKey('raw', raw, { name: 'AES-GCM' }, true, ['encrypt', 'decrypt']); }
	function recoveryCode() {
		var abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', b = rand(20), s = '';
		for (var i = 0; i < 20; i++) { s += abc[b[i] % 32] + ((i % 4 === 3 && i < 19) ? '-' : ''); }
		return s;
	}
	function normCode(s) { return s.toUpperCase().replace(/[^A-Z0-9]/g, ''); }

	// ------------------------------------------------------------ the vault (server holds ciphertext only)

	var V = { exists: false, version: 0, envelope: null, key: null, raw: null, rule: null, log: null, priv: null, pub: '' };

	async function api(method, body) {
		var r = await fetch(C.vault, { method: method, credentials: 'same-origin', cache: 'no-store', headers: jsonHeaders(), body: body ? JSON.stringify(body) : undefined });
		var data = await r.json().catch(function () { return {}; });
		return { status: r.status, data: data };
	}
	async function loadVault() {
		if (!C.private) { return; }                // a visitor, or a reader who may not keep a private rule
		var r = await api('GET');
		V.exists = !!r.data.exists; V.version = r.data.version || 0; V.envelope = r.data.envelope || null;
		if (!V.exists) { V.key = null; V.rule = null; V.log = null; try { sessionStorage.removeItem(SESSION); } catch (e) {} return; }
		var saved = null; try { saved = sessionStorage.getItem(SESSION); } catch (e) {}
		if (saved && !V.key) { try { await openWith(unb64(saved)); } catch (e) { lock(); } }
		else if (V.key) { await readEnvelope(V.envelope); }
	}
	async function readEnvelope(env) {
		V.rule = JSON.parse(dec.decode(await unseal(V.key, env.rule, 'rule')));
		V.log = JSON.parse(dec.decode(await unseal(V.key, env.log, 'log')));
		if (env.priv) { await usePrivate(await unseal(V.key, env.priv, 'priv')); }
	}

	// ---- the sharing key pair

	async function usePrivate(pkcs8) {
		V.priv = await crypto.subtle.importKey('pkcs8', pkcs8, { name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
		var jwk = await crypto.subtle.exportKey('jwk', V.priv);
		var pub = await crypto.subtle.importKey('jwk', { kty: jwk.kty, crv: jwk.crv, x: jwk.x, y: jwk.y, ext: true }, { name: 'ECDH', namedCurve: 'P-256' }, true, []);
		V.pub = b64(await crypto.subtle.exportKey('raw', pub));
	}
	/** Make the key pair if this rule has none yet, and make sure the site has the public half. */
	async function ensureKeys(env) {
		if (!env.priv) {
			var pair = await crypto.subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveBits']);
			var pkcs8 = new Uint8Array(await crypto.subtle.exportKey('pkcs8', pair.privateKey));
			env.priv = await seal(V.key, pkcs8, 'priv');
			await usePrivate(pkcs8);
			return true;   // the envelope changed: save it
		}
		return false;
	}
	async function publishKey() {
		if (V.pub && V.pub !== C.mykey) {
			var r = await fetch(C.sharing + 'key', { method: 'PUT', credentials: 'same-origin', headers: jsonHeaders(), body: JSON.stringify({ key: V.pub }) });
			if (r.ok) { C.mykey = V.pub; }
		}
	}
	async function openWith(raw) {
		V.raw = raw; V.key = await dataKey(raw);
		await readEnvelope(V.envelope);
		try { sessionStorage.setItem(SESSION, b64(raw)); } catch (e) {}
		if (await ensureKeys(V.envelope)) { await saveVault(); }
		await publishKey();
		loadShares().then(function () { pushShares(); drawSharing(); drawAccount(); });
	}
	async function unwrap(secret, which) {
		var env = V.envelope, k = await derive(secret, unb64(env['salt_' + which]), env.iter);
		return unseal(k, env['key_' + which], 'key_' + which);   // throws when the secret is wrong
	}
	async function wrap(secret, which, env) {
		var salt = rand(16), k = await derive(secret, salt, ITER);
		env['salt_' + which] = b64(salt); env['key_' + which] = await seal(k, V.raw, 'key_' + which); env.iter = ITER;
	}
	function lock() { V.key = null; V.raw = null; V.rule = null; V.log = null; V.priv = null; V.pub = ''; SH.mine = []; SH.withMe = []; SH.open = null; try { sessionStorage.removeItem(SESSION); } catch (e) {} }

	/** Merge two records: every entry, the most recently edited one winning. */
	function mergeLog(a, b) {
		var out = { entries: {} };
		[a, b].forEach(function (log) { Object.keys((log && log.entries) || {}).forEach(function (k) { var e = log.entries[k], have = out.entries[k]; if (!have || (e.edited || '') > (have.edited || '')) { out.entries[k] = e; } }); });
		return out;
	}
	/** Encrypt and save the rule and record; if another device saved meanwhile, merge and try again. */
	async function saveVault() {
		for (var attempt = 0; attempt < 3; attempt++) {
			var env = Object.assign({}, V.envelope);
			env.rule = await seal(V.key, enc.encode(JSON.stringify(V.rule)), 'rule');
			env.log = await seal(V.key, enc.encode(JSON.stringify(V.log)), 'log');
			var r = await api('PUT', { version: V.version, envelope: env });
			if (r.status === 200) { V.version = r.data.version; V.envelope = env; status(''); pushShares(); return true; }
			if (r.status === 409 && r.data.envelope) {
				var theirs = { rule: JSON.parse(dec.decode(await unseal(V.key, r.data.envelope.rule, 'rule'))), log: JSON.parse(dec.decode(await unseal(V.key, r.data.envelope.log, 'log'))) };
				V.log = mergeLog(theirs.log, V.log);
				if ((theirs.rule.updated || '') > (V.rule.updated || '')) { V.rule.services = theirs.rule.services; V.rule.translation = theirs.rule.translation; V.rule.verses = theirs.rule.verses; }
				V.version = r.data.version; V.envelope = r.data.envelope;
				continue;
			}
			break;
		}
		status('Could not save. Please check your connection and try again.', true);
		return false;
	}

	// ------------------------------------------------------------ settings: the private rule when unlocked, else this browser's

	function blankRule() {
		var rule = { services: {}, next: 1, translation: C.translation, verses: !!C.verses, updated: now() };
		C.services.forEach(function (s) {
			var svc = { keep: true, portions: {} };
			s.portions.forEach(function (p) { svc.portions[p.key] = { kathismas: p.kathismas.length ? p.kathismas[0] : 0, ladders: 0 }; });
			rule.services[s.key] = svc;
		});
		return rule;
	}
	function loadLocal() {
		var s; try { s = JSON.parse(localStorage.getItem(LOCAL)) || {}; } catch (e) { s = {}; }
		var rule = blankRule();
		if (s.services) { rule = Object.assign(rule, s); }
		else if (s.portions) {   // the page's first version kept "service/portion" keys
			Object.keys(s.portions).forEach(function (k) { var a = k.split('/'); if (rule.services[a[0]] && rule.services[a[0]].portions[a[1]]) { rule.services[a[0]].portions[a[1]] = s.portions[k]; } });
			['next', 'translation', 'verses'].forEach(function (k) { if (s[k] !== undefined) { rule[k] = s[k]; } });
		}
		return rule;
	}
	var L = loadLocal();
	var UI = { service: (function () { try { return localStorage.getItem(LOCAL + '.service'); } catch (e) { return null; } })(), day: defaultDay(), panel: '' };
	function R() { return V.key ? V.rule : L; }
	function saveSettings() {
		if (V.key) { V.rule.updated = now(); saveVault(); } else { try { localStorage.setItem(LOCAL, JSON.stringify(L)); } catch (e) {} }
	}
	function portionOf(svcKey, pKey) {
		var r = R(), s = serviceByKey(svcKey), p = s.portions.filter(function (x) { return x.key === pKey; })[0];
		r.services[svcKey] = r.services[svcKey] || { keep: true, portions: {} };
		r.services[svcKey].portions[pKey] = r.services[svcKey].portions[pKey] || { kathismas: p.kathismas.length ? p.kathismas[0] : 0, ladders: 0 };
		return r.services[svcKey].portions[pKey];
	}
	if (!UI.service || (!serviceByKey(UI.service) && !(UI.service === 'reading' && (C.bibles || []).length))) { UI.service = C.services.length ? C.services[0].key : ''; }

	// ---- the daily Scripture reading: settings kept in the rule as rule.reading =
	// { nt: translation, ot: translation, gospel|apostle|old: { per: chapters a day, b: book, c: chapter }, old.psalms }

	var SECTIONS = [
		{ key: 'gospel', name: 'The Gospel', groups: ['gospel'], testament: 'nt', start: 'MAT' },
		{ key: 'apostle', name: 'The Apostle', groups: ['apostle'], testament: 'nt', start: 'ACT' },
		{ key: 'old', name: 'The Old Testament', groups: ['ot', 'deutero'], testament: 'ot', start: 'GEN' }
	];
	// How often a section is read: every day, every other day (counted from x.anchor),
	// once a week on a set day (x.wd), or once a week on any day.
	var FREQS = ['daily', 'alternate', 'weekday', 'weekly'];
	var WEEKDAYS = []; (function () { for (var i = 0; i < 7; i++) { WEEKDAYS.push(new Date(2026, 10, 1 + i).toLocaleDateString(undefined, { weekday: 'long' })); } })();   // Nov 1, 2026 is a Sunday
	function dayNum(d) { return Math.round(parseIso(d) / 86400000); }
	function freqText(x) {
		var n = x.per + (x.per === 1 ? ' chapter' : ' chapters');
		if (x.freq === 'alternate') { return n + ' every other day'; }
		if (x.freq === 'weekday') { return n + ' every ' + WEEKDAYS[x.wd]; }
		if (x.freq === 'weekly') { return n + ' once a week'; }
		return n + ' a day';
	}
	/** Is a section due on a day by its frequency alone (once-a-week-any-day sections always are)? */
	function dueOn(x, day) {
		if (x.freq === 'alternate') { return ((dayNum(day) - dayNum(x.anchor || day)) % 2 + 2) % 2 === 0; }
		if (x.freq === 'weekday') { return parseIso(day).getDay() === x.wd; }
		return true;
	}
	function nextDue(x, day) { for (var i = 1; i <= 7; i++) { if (dueOn(x, addDays(day, i))) { return addDays(day, i); } } return addDays(day, 1); }
	function readOn(log, key, day) { var e = log && log.entries[entryKey(day, 'reading')]; return !!(e && e.kept && e.read && e.read[key]); }
	/**
	 * A section read ahead counts for its next day due: the day before it was
	 * read on, from the day after the one it was last due (every other day: the
	 * day before; once a week on a set day: the six days before; once a week on
	 * any day: the other days of the week). Returns that day, or ''.
	 */
	function readAhead(x, key, day, log) {
		if (!log) { return ''; }
		var from, i;
		if (x.freq === 'weekly') { from = weekStart(day); } else {
			for (i = 1; i <= 7 && !dueOn(x, addDays(day, -i)); i++) {}
			from = addDays(day, -i + 1);
		}
		for (var d = from; d < day; d = addDays(d, 1)) { if (readOn(log, key, d)) { return d; } }
		if (x.freq === 'weekly') { for (d = addDays(day, 1); d < addDays(weekStart(day), 7); d = addDays(d, 1)) { if (readOn(log, key, d)) { return d; } } }
		return '';
	}
	function shortDay(d) { return parseIso(d).toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' }); }
	function bibles(testament) { return (C.bibles || []).filter(function (b) { return b.testaments.indexOf(testament) >= 0; }); }
	function bible(id) { return (C.bibles || []).filter(function (b) { return b.id === id; })[0]; }
	function readingOf(r) {
		var rd = r.reading || (r.reading = {});
		if (!bible(rd.nt) && bibles('nt').length) { rd.nt = bibles('nt')[0].id; }
		if (!bible(rd.ot) && bibles('ot').length) { rd.ot = bibles('ot')[0].id; }
		SECTIONS.forEach(function (sec) {
			var x = rd[sec.key] || (rd[sec.key] = {});
			if (typeof x.per !== 'number') { x.per = 0; }
			if (FREQS.indexOf(x.freq) < 0) { x.freq = 'daily'; }
			if (typeof x.wd !== 'number') { x.wd = 6; }
			if (!x.b) { x.b = sec.start; x.c = 1; }
			if (sec.key === 'old' && typeof x.psalms !== 'boolean') { x.psalms = true; }
		});
		return rd;
	}
	function sectionBible(sec, rd) { return bible(sec.testament === 'nt' ? rd.nt : rd.ot); }
	/** A book's chapters: [code, name, group, first, last, list-if-not-continuous]. */
	function chaptersOf(book) { if (book[5]) { return book[5]; } var a = []; for (var n = book[3]; n <= book[4]; n++) { a.push(n); } return a; }
	/** The books a section reads, in the translation's order. */
	function sectionBooks(sec, rd) {
		var b = sectionBible(sec, rd);
		if (!b) { return []; }
		return b.books.filter(function (bk) { return sec.groups.indexOf(bk[2]) >= 0 && !(sec.key === 'old' && rd.old.psalms === false && bk[0] === 'PSA'); });
	}
	/** The next n chapters of a section from its bookmark, and the bookmark after them (the reading wraps round). */
	function sectionChapters(sec, rd, n) {
		var x = rd[sec.key], books = sectionBooks(sec, rd), list = [], all = [];
		books.forEach(function (bk) { chaptersOf(bk).forEach(function (c) { all.push([bk[0], c]); }); });
		if (!all.length || n <= 0) { return { list: [], next: { b: x.b, c: x.c } }; }
		var i = -1, order = books.map(function (bk) { return bk[0]; }), at = order.indexOf(x.b), k;
		for (k = 0; k < all.length && i < 0; k++) { if (all[k][0] === x.b && all[k][1] >= x.c) { i = k; } }
		if (i < 0 && at >= 0) { for (k = 0; k < all.length && i < 0; k++) { if (order.indexOf(all[k][0]) > at) { i = k; } } }   // past the book's end: the next book
		if (i < 0) { i = 0; }
		for (k = 0; k < n; k++) { list.push(all[(i + k) % all.length]); }
		var after = all[(i + n) % all.length];
		return { list: list, next: { b: after[0], c: after[1] } };
	}
	/**
	 * A day's reading, section by section in order: state 'read' (marked read
	 * that day: the chapters read), 'due' (the next chapters from the bookmark),
	 * or 'later' (not due that day, with why). Without a day: every section, due.
	 */
	function readingDay(r, day, log) {
		var rd = readingOf(r), items = [], e = day && log && log.entries[entryKey(day, 'reading')];
		SECTIONS.forEach(function (sec) {
			var x = rd[sec.key];
			if (e && e.kept && e.read && e.read[sec.key]) {
				items.push({ sec: sec, state: 'read', t: e.read[sec.key].t, list: e.read[sec.key].list, next: null });
				return;
			}
			if (!(x.per > 0) || !sectionBible(sec, rd)) { return; }
			var got = sectionChapters(sec, rd, x.per), item = { sec: sec, state: 'due', t: sectionBible(sec, rd).id, list: got.list, next: got.next };
			if (day) {
				var ahead = readAhead(x, sec.key, day, log);
				if (ahead) { item.state = 'later'; item.why = 'already read on ' + shortDay(ahead); }
				else if (!dueOn(x, day)) { item.state = 'later'; item.why = 'next due on ' + shortDay(nextDue(x, day)); }
			}
			items.push(item);
		});
		return items;
	}
	function readingParts(r, day, log) { return readingDay(r, day, log).filter(function (it) { return it.state === 'due'; }); }
	function bookLabel(sec, rd, code) {
		var b = sectionBible(sec, rd), bk = b && b.books.filter(function (x) { return x[0] === code; })[0];
		return bk ? bk[1] : code;
	}
	if (!C.translations[L.translation]) { L.translation = C.translation; }

	// ------------------------------------------------------------ the page

	var dateInput = el('input', { type: 'date', value: UI.day, 'aria-label': 'Day' });
	function setDay(d) { UI.day = d; dateInput.value = d; drawTabs(); show(); }
	dateInput.addEventListener('change', function () { if (dateInput.value) { setDay(dateInput.value); } });
	var bar = el('div', { class: 'pr-controls' }, [
		el('button', { type: 'button', class: 'pr-nav', 'aria-label': 'The day before', text: '‹', on: { click: function () { setDay(addDays(UI.day, -1)); } } }),
		dateInput,
		el('button', { type: 'button', class: 'pr-nav', 'aria-label': 'The day after', text: '›', on: { click: function () { setDay(addDays(UI.day, 1)); } } }),
		el('button', { type: 'button', class: 'pr-today', text: 'Today', on: { click: function () { setDay(defaultDay()); } } })
	]);
	var account = el('div', { class: 'pr-account' });
	var tabs = el('div', { class: 'pr-tabs', role: 'group' });
	var tools = el('div', { class: 'pr-tools' });
	var head = el('div', { class: 'pr-head' }, [bar, tabs, tools]);

	/**
	 * A window over the page (like the calendar's) for My rule, My record and
	 * Nastavnik. Returns its body; the button that opens it sits in the head.
	 */
	function panel(cls) {
		var body = el('div', { class: 'pr-dialog-body ' + cls });
		var title = el('h2', { class: 'pr-dialog-title' });
		var dlg = el('dialog', { class: 'pr-dialog' }, [
			el('button', { type: 'button', class: 'pr-dialog-close', 'aria-label': 'Close', text: '×', on: { click: function () { dlg.close(); } } }),
			title, body
		]);
		dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });   // a click outside the box
		dlg.addEventListener('close', function () { document.documentElement.classList.remove('pr-modal-open'); });
		var btn = el('button', { type: 'button', class: 'pr-tool', on: { click: function () { openPanel(body); } } });
		body.dlg = dlg; body.titleEl = title; body.btn = btn;
		tools.appendChild(btn);
		root.appendChild(dlg);
		return body;
	}
	function openPanel(body) {
		if (!body.dlg.open) { if (body.dlg.showModal) { body.dlg.showModal(); } else { body.dlg.setAttribute('open', ''); } }
		document.documentElement.classList.add('pr-modal-open');
	}
	function panelTitle(body, text, badge) {
		body.titleEl.textContent = text;
		body.btn.innerHTML = '';
		body.btn.appendChild(document.createTextNode(text));
		if (badge) { body.btn.appendChild(el('span', { class: 'pr-badge', text: String(badge) })); }
	}
	function panelShown(body, yes) { body.btn.style.display = yes ? '' : 'none'; if (!yes && body.dlg.open) { body.dlg.close(); } }

	var settings = panel('pr-settings');
	var readingPanel = panel('pr-settings pr-reading-panel');
	var record = panel('pr-record');
	var note = el('p', { class: 'pr-status', role: 'status' });
	var out = el('div', { class: 'pr-out', 'aria-live': 'polite' });
	var footer = el('div', { class: 'pr-footer' });
	[account, head, note, out, footer].forEach(function (x) { root.insertBefore(x, settings.dlg); });

	function status(text, error) { note.textContent = text; note.className = 'pr-status' + (error ? ' is-error' : ''); }
	function entryKey(day, svc) { return day + '|' + svc; }
	function entry(day, svc) { return V.key && V.log && V.log.entries[entryKey(day, svc)]; }

	// ---- account: set up / unlock / locked

	function field(label, attrs) { var i = el('input', Object.assign({ type: 'password', autocomplete: 'off' }, attrs || {})); return [el('label', { class: 'pr-field' }, [label, i]), i]; }

	function drawAccount() {
		account.innerHTML = '';
		if (V.key) {
			drawNotice();
			return;
		}
		if (!C.private) {
			// The reading is open to them; the private rule is for those logged in (and, if so set, members).
			var why = C.login ? 'Log in to keep a private rule, mark each service as kept, and share your rule with a nastavnik. ' : 'Members can keep a private rule, mark each service as kept, and share their rule with a nastavnik. ';
			var links = [];
			if (C.login) { links.push(el('a', { href: C.login, text: 'Log in' })); }
			if (C.register) { links.push(' or ', el('a', { href: C.register, text: 'create an account' })); }
			if (!C.login && C.join) { links.push(el('a', { href: C.join, text: 'Become a member' })); }
			account.appendChild(el('p', { class: 'pr-lockline' }, [why].concat(links).concat(links.length ? ['.'] : [])));
			return;
		}
		if (!V.exists) {
			if (UI.panel !== 'setup') {
				account.appendChild(el('p', { class: 'pr-lockline' }, ['Keep your rule privately and mark each service as kept. ', el('button', { type: 'button', class: 'pr-link', text: 'Set up my private rule', on: { click: function () { UI.panel = 'setup'; drawAccount(); } } })]));
				return;
			}
			drawSetup();
			return;
		}
		if (UI.panel === 'recover') { drawRecover(); return; }
		drawUnlock();
	}

	/** New messages from a nastavnik (or, for a nastavnik, from those who share with them). */
	function drawNotice() {
		var fresh = SH.mine.map(function (sh) { return { share: sh, mine: true, who: sh.viewer.name }; })
			.concat(SH.withMe.map(function (sh) { return { share: sh, mine: false, who: sh.owner.name }; }))
			.filter(function (x) { var sh = x.share; return sh.last_message && sh.last_author !== C.user && sh.last_message > seen(sh.id); });
		if (!fresh.length) { return; }
		var box = el('div', { class: 'pr-notice', role: 'status' });
		fresh.forEach(function (x) {
			box.appendChild(el('p', {}, ['New message from ' + x.who + '. ', el('button', { type: 'button', class: 'pr-primary', text: 'Read it', on: { click: function () {
				SH.open = { share: x.share, mine: x.mine }; drawSharing(); openPanel(sharing);
			} } })]));
		});
		account.appendChild(box);
	}

	/** At the foot of the page: passphrase, recovery code, erasing, and locking. */
	function drawFooter() {
		var open = footer.querySelector('details') ? footer.querySelector('details').open : false;
		footer.innerHTML = '';
		if (!V.key) { return; }
		var d = el('details', { class: 'pr-keys-box' }, [el('summary', { text: 'Passphrase, recovery code and erasing' }), drawKeys()]);
		d.open = open;
		footer.appendChild(d);
		footer.appendChild(el('p', { class: 'pr-lockline' }, [
			'Your private rule is open in this browser tab, and closes by itself when the tab is closed. On a shared computer, ',
			el('button', { type: 'button', class: 'pr-link', text: 'lock it now', on: { click: function () { lock(); redraw(); window.scrollTo(0, 0); } } }), '.'
		]));
	}

	function drawSetup() {
		var code = recoveryCode();
		var p1 = field('Passphrase', { autocomplete: 'new-password' }), p2 = field('The passphrase again', { autocomplete: 'new-password' });
		var ok = el('input', { type: 'checkbox' }), msg = el('p', { class: 'pr-status' });
		var go = el('button', { type: 'button', class: 'pr-primary', text: 'Set up' });
		go.addEventListener('click', async function () {
			if (p1[1].value.length < 8) { msg.textContent = 'Use at least 8 characters.'; return; }
			if (p1[1].value !== p2[1].value) { msg.textContent = 'The two passphrases differ.'; return; }
			if (!ok.checked) { msg.textContent = 'Please write down your recovery code first.'; return; }
			go.disabled = true; msg.textContent = 'Setting up…';
			try {
				var raw = rand(32); V.raw = raw; V.key = await dataKey(raw);
				var env = { v: 1, iter: ITER };
				await ensureKeys(env);
				await wrap(p1[1].value, 'p', env);
				await wrap(normCode(code), 'r', env);
				V.rule = JSON.parse(JSON.stringify(L)); V.rule.updated = now();
				V.log = { entries: {} };
				env.rule = await seal(V.key, enc.encode(JSON.stringify(V.rule)), 'rule');
				env.log = await seal(V.key, enc.encode(JSON.stringify(V.log)), 'log');
				var r = await api('POST', { envelope: env });
				if (r.status !== 200) { throw new Error(r.data.message || 'failed'); }
				V.exists = true; V.version = r.data.version; V.envelope = env;
				try { sessionStorage.setItem(SESSION, b64(raw)); } catch (e) {}
				await publishKey(); loadShares().then(drawSharing);
				UI.panel = ''; redraw();
				status('Your private rule is set up.');
			} catch (e) { lock(); go.disabled = false; msg.textContent = 'Setup failed: ' + e.message; }
		});
		account.appendChild(el('div', { class: 'pr-box' }, [
			el('h3', { text: 'Set up your private rule' }),
			el('p', { text: 'Your rule and your record are encrypted in this browser with your passphrase before they are saved. The site, its administrators and its host cannot read them.' }),
			el('p', { text: 'If you forget your passphrase, only the recovery code below can open your rule. If you lose both, no one can recover it.' }),
			p1[0], p2[0],
			el('p', {}, ['Your recovery code: ', el('code', { class: 'pr-code', text: code })]),
			el('label', { class: 'pr-check' }, [ok, ' I have written down my recovery code and will keep it safe.']),
			el('p', {}, [go, ' ', el('button', { type: 'button', class: 'pr-link', text: 'Cancel', on: { click: function () { UI.panel = ''; drawAccount(); } } })]), msg
		]));
	}

	function drawUnlock() {
		var p = field('Passphrase', { autocomplete: 'current-password' }), msg = el('p', { class: 'pr-status' });
		var go = el('button', { type: 'button', class: 'pr-primary', text: 'Open my rule' });
		async function run() {
			go.disabled = true; msg.textContent = 'Opening…';
			try { await openWith(await unwrap(p[1].value, 'p')); UI.panel = ''; redraw(); }
			catch (e) { go.disabled = false; msg.textContent = 'That passphrase did not open your rule.'; }
		}
		go.addEventListener('click', run);
		p[1].addEventListener('keydown', function (e) { if (e.key === 'Enter') { run(); } });
		account.appendChild(el('div', { class: 'pr-box' }, [
			el('p', { text: 'Your private rule is locked.' }), p[0],
			el('p', {}, [go, ' ', el('button', { type: 'button', class: 'pr-link', text: 'Forgot your passphrase?', on: { click: function () { UI.panel = 'recover'; drawAccount(); } } })]), msg
		]));
	}

	function drawRecover() {
		var c = field('Recovery code', { type: 'text' }), p1 = field('New passphrase', { autocomplete: 'new-password' }), p2 = field('The new passphrase again', { autocomplete: 'new-password' });
		var msg = el('p', { class: 'pr-status' }), go = el('button', { type: 'button', class: 'pr-primary', text: 'Open and set the new passphrase' });
		go.addEventListener('click', async function () {
			if (p1[1].value.length < 8) { msg.textContent = 'Use at least 8 characters.'; return; }
			if (p1[1].value !== p2[1].value) { msg.textContent = 'The two passphrases differ.'; return; }
			go.disabled = true; msg.textContent = 'Opening…';
			try { await openWith(await unwrap(normCode(c[1].value), 'r')); }
			catch (e) { go.disabled = false; msg.textContent = 'That recovery code did not open your rule.'; return; }
			await wrap(p1[1].value, 'p', V.envelope);
			if (await saveVault()) { UI.panel = ''; redraw(); status('Your new passphrase is set.'); }
		});
		account.appendChild(el('div', { class: 'pr-box' }, [
			el('p', { text: 'Enter your recovery code and choose a new passphrase.' }), c[0], p1[0], p2[0],
			el('p', {}, [go, ' ', el('button', { type: 'button', class: 'pr-link', text: 'Back', on: { click: function () { UI.panel = ''; drawAccount(); } } })]), msg
		]));
	}

	// ---- service buttons, with a mark for each service kept that day

	function drawTabs() {
		tabs.innerHTML = '';
		C.services.forEach(function (s) {
			var e = entry(UI.day, s.key), kept = e && e.kept;
			var keepIt = !V.key || !V.rule.services[s.key] || V.rule.services[s.key].keep !== false;
			var b = el('button', { type: 'button', 'aria-pressed': s.key === UI.service ? 'true' : 'false', class: (kept ? 'is-kept ' : '') + (keepIt ? '' : 'is-not-kept'), text: (kept ? '✓ ' : '') + s.name });
			b.addEventListener('click', function () { UI.service = s.key; try { localStorage.setItem(LOCAL + '.service', s.key); } catch (x) {} drawTabs(); drawSettings(); show(); });
			tabs.appendChild(b);
		});
		if ((C.bibles || []).length) {
			var ri = readingDay(R(), UI.day, V.key ? V.log : null), done = V.key && ri.some(function (it) { return it.state === 'read'; }) && !ri.some(function (it) { return it.state === 'due'; });
			var rb = el('button', { type: 'button', 'aria-pressed': UI.service === 'reading' ? 'true' : 'false', class: 'pr-tab-reading' + (done ? ' is-kept' : ''), text: (done ? '✓ ' : '') + 'Scripture' });
			rb.addEventListener('click', function () { UI.service = 'reading'; try { localStorage.setItem(LOCAL + '.service', 'reading'); } catch (x) {} drawTabs(); show(); });
			tabs.appendChild(rb);
		}
	}

	// ---- my rule (or this browser's settings), and the Scripture reading

	function fld(label, control) { return el('label', { class: 'pr-fld' }, [el('span', { class: 'pr-fld-label', text: label }), control]); }
	function toggle(checked, onchange, text, title) {
		var cb = el('input', { type: 'checkbox', role: 'switch' }); cb.checked = !!checked;
		if (title) { cb.setAttribute('aria-label', title); }
		cb.addEventListener('change', function () { onchange(cb.checked); });
		return el('label', { class: 'pr-switch' }, [cb, el('span', { class: 'pr-switch-track', 'aria-hidden': 'true' }), text ? el('span', { text: text }) : null]);
	}
	function setHead(text) { return el('h3', { class: 'pr-set-head', text: text }); }

	function drawSettings() {
		var r = R();
		settings.innerHTML = '';
		panelTitle(settings, V.key ? 'My rule' : 'My portion and settings');
		settings.appendChild(el('p', { class: 'pr-note pr-panel-intro', text: V.key
			? 'The services you keep, and the kathismas and ladders of the Psalter you read at each. A service switched off can still be read, but is left out of your record.'
			: 'Your portion of the Psalter at each service. These settings are kept only in this browser.' }));
		settings.appendChild(setHead('Services'));
		var cards = el('div', { class: 'pr-cards' });
		C.services.forEach(function (s) {
			var svc = r.services[s.key] || (r.services[s.key] = { keep: true, portions: {} });
			var card = el('div', { class: 'pr-card' + (V.key && svc.keep === false ? ' is-off' : '') });
			var head = el('div', { class: 'pr-card-head' }, [el('span', { class: 'pr-card-name', text: s.name })]);
			if (V.key) {
				head.appendChild(toggle(svc.keep !== false, function (on) { svc.keep = on; card.classList.toggle('is-off', !on); saveSettings(); drawTabs(); drawRecord(); }, '', 'Keep ' + s.name));
			}
			card.appendChild(head);
			var body = el('div', { class: 'pr-card-body' });
			s.portions.forEach(function (p) {
				var v = portionOf(s.key, p.key);
				var k = [[0, 'none']].concat(p.kathismas.map(function (n) { return [n, n]; }));
				var l = [[0, 'none']].concat(p.ladders.map(function (n) { return [n, half(n)]; }));
				body.appendChild(el('div', { class: 'pr-card-row' }, [
					s.portions.length > 1 ? el('span', { class: 'pr-row-label', text: p.name }) : null,
					fld('Kathismas', select(k, v.kathismas, function (x) { v.kathismas = +x; saveSettings(); if (s.key === UI.service) { show(); } })),
					fld('Ladders', select(l, v.ladders, function (x) { v.ladders = +x; saveSettings(); if (s.key === UI.service) { show(); } }))
				]));
			});
			if (s.key === 'cell-rule') {
				// The Home Ustav gives the prayers before sleep for the more ascetic; kept with the Cell Rule, never counted apart.
				body.appendChild(el('div', { class: 'pr-card-row' }, [
					toggle(svc.bedtime, function (on) { svc.bedtime = on; saveSettings(); if (UI.service === s.key) { show(); } }, 'Prayers before sleep', 'Add the prayers before sleep'),
					el('span', { class: 'pr-note', text: 'Said after the Cell Rule, for those who wish to keep it more strictly. Part of the Cell Rule; your record is the same with or without them.' })
				]));
			}
			card.appendChild(body);
			cards.appendChild(card);
		});
		settings.appendChild(cards);

		settings.appendChild(setHead('The Psalter'));
		var nums = []; for (var i = 1; i <= 20; i++) { nums.push([i, i]); }
		var tr = Object.keys(C.translations).map(function (k) { return [k, C.translations[k]]; });
		settings.appendChild(el('div', { class: 'pr-card pr-card-wide' }, [el('div', { class: 'pr-card-row' }, [
			fld('Next kathisma', select(nums, r.next, function (x) { r.next = +x; saveSettings(); show(); })),
			fld('Translation', select(tr, r.translation, function (x) { r.translation = x; saveSettings(); show(); })),
			el('div', { class: 'pr-fld' }, [el('span', { class: 'pr-fld-label', text: 'Verse numbers' }), toggle(r.verses, function (on) { r.verses = on; saveSettings(); show(); }, 'show', 'Show verse numbers')])
		]), el('div', { class: 'pr-card-row' }, [
			toggle(r.troparia, function (on) { r.troparia = on; saveSettings(); show(); }, 'Troparia and prayer after each kathisma, all year', 'Troparia and prayer after each kathisma, all year'),
			el('span', { class: 'pr-note', text: 'The penitential troparia and prayer of each kathisma are always said in Great Lent. Switch this on to say them after every kathisma through the year as well.' })
		])]));
		drawReadingPanel();
	}

	/** Scripture reading: the translations, and for each section chapters, how often, and where it has got to. */
	function drawReadingPanel() {
		var has = (C.bibles || []).length > 0;
		panelShown(readingPanel, has);
		readingPanel.innerHTML = '';
		panelTitle(readingPanel, 'Scripture reading');
		if (!has) { return; }
		var r = R(), rd = readingOf(r);
		function changed() { saveSettings(); drawSettings(); drawTabs(); if (UI.service === 'reading') { show(); } }
		readingPanel.appendChild(el('p', { class: 'pr-note pr-panel-intro', text: 'Read the Gospel, the Apostle and the Old Testament in order, as many chapters as you choose and as often as suits you. Each section goes on from where it stopped, and begins again at the end.' + (V.key ? '' : ' These settings are kept only in this browser.') }));
		readingPanel.appendChild(setHead('Translations'));
		var trow = el('div', { class: 'pr-card-row' });
		if (bibles('nt').length) { trow.appendChild(fld('New Testament', select(bibles('nt').map(function (b) { return [b.id, b.name]; }), rd.nt, function (x) { rd.nt = x; changed(); }))); }
		if (bibles('ot').length) { trow.appendChild(fld('Old Testament', select(bibles('ot').map(function (b) { return [b.id, b.name]; }), rd.ot, function (x) { rd.ot = x; changed(); }))); }
		readingPanel.appendChild(el('div', { class: 'pr-card pr-card-wide' }, [trow]));
		readingPanel.appendChild(setHead('Sections'));
		var per = [[0, 'none']]; for (var n = 1; n <= 10; n++) { per.push([n, n]); }
		var list = el('div', { class: 'pr-cards is-list' });
		SECTIONS.forEach(function (sec) {
			var x = rd[sec.key], books = sectionBooks(sec, rd);
			if (!books.length) { return; }
			var bk = books.filter(function (b) { return b[0] === x.b; })[0] || books[0];
			var card = el('div', { class: 'pr-card' + (x.per > 0 ? '' : ' is-off') });
			card.appendChild(el('div', { class: 'pr-card-head' }, [
				el('span', { class: 'pr-card-name', text: sec.name }),
				el('span', { class: 'pr-card-sub', text: x.per > 0 ? freqText(x) : 'not read' })
			]));
			var row = el('div', { class: 'pr-card-row' }, [
				fld('Chapters', select(per, x.per, function (v) { if (!(x.per > 0) && +v > 0) { x.since = defaultDay(); } x.per = +v; changed(); })),
				fld('How often', select([['daily', 'every day'], ['alternate', 'every other day'], ['weekday', 'once a week, on a set day'], ['weekly', 'once a week, any day']], x.freq, function (v) {
					x.freq = v; if (v === 'alternate') { x.anchor = UI.day; } changed();
				}))
			]);
			if (x.freq === 'weekday') { row.appendChild(fld('On', select(WEEKDAYS.map(function (nm, i) { return [i, nm]; }), x.wd, function (v) { x.wd = +v; changed(); }))); }
			var body = el('div', { class: 'pr-card-body' }, [row, el('div', { class: 'pr-card-row' }, [
				fld('Next book', select(books.map(function (b) { return [b[0], b[1]]; }), bk[0], function (code) { x.b = code; x.c = chaptersOf(books.filter(function (b) { return b[0] === code; })[0])[0]; changed(); })),
				fld('Chapter', select(chaptersOf(bk).map(function (c) { return [c, c]; }), x.c, function (c) { x.b = bk[0]; x.c = +c; changed(); }))
			])]);
			if (sec.key === 'old') {
				body.appendChild(el('div', { class: 'pr-card-row' }, [toggle(x.psalms !== false, function (on) { x.psalms = on; changed(); }, 'Include the Psalms', 'Include the Psalms'),
					el('span', { class: 'pr-note', text: 'Leave this off if you read them in the kathismas.' })]));
			}
			card.appendChild(body);
			list.appendChild(card);
		});
		readingPanel.appendChild(list);
		readingPanel.appendChild(el('p', { class: 'pr-note', text: '"Every other day" counts from the day it is chosen. "Once a week, any day" can be read on any day from Sunday to Saturday. A section not due can still be read ahead from the Scripture page, and then counts for its next day.' }));
	}

	function drawKeys() {
		var box = el('div', { class: 'pr-keys' });
		var msg = el('p', { class: 'pr-status' });
		var p1 = field('New passphrase', { autocomplete: 'new-password' }), p2 = field('Again', { autocomplete: 'new-password' });
		var change = el('button', { type: 'button', text: 'Change passphrase' });
		change.addEventListener('click', async function () {
			if (p1[1].value.length < 8) { msg.textContent = 'Use at least 8 characters.'; return; }
			if (p1[1].value !== p2[1].value) { msg.textContent = 'The two passphrases differ.'; return; }
			change.disabled = true; msg.textContent = 'Saving…';
			await wrap(p1[1].value, 'p', V.envelope);
			msg.textContent = (await saveVault()) ? 'Your passphrase is changed.' : 'Not saved.'; change.disabled = false; p1[1].value = p2[1].value = '';
		});
		var newCode = el('button', { type: 'button', text: 'Make a new recovery code' });
		newCode.addEventListener('click', async function () {
			var code = recoveryCode(); newCode.disabled = true;
			await wrap(normCode(code), 'r', V.envelope);
			if (await saveVault()) { msg.innerHTML = ''; msg.appendChild(document.createTextNode('Your new recovery code (the old one no longer works): ')); msg.appendChild(el('code', { class: 'pr-code', text: code })); }
			newCode.disabled = false;
		});
		var erase = el('input', { type: 'text', placeholder: 'type ERASE' });
		var eraseBtn = el('button', { type: 'button', text: 'Erase my rule and record' });
		eraseBtn.addEventListener('click', async function () {
			if (erase.value !== 'ERASE') { msg.textContent = 'Type ERASE in the box to confirm.'; return; }
			var r = await api('DELETE'); if (r.status === 200) { lock(); V.exists = false; V.envelope = null; redraw(); status('Your private rule and record are erased.'); }
		});
		box.appendChild(el('p', {}, [p1[0], ' ', p2[0], ' ', change]));
		box.appendChild(el('p', {}, [newCode]));
		box.appendChild(el('p', {}, [erase, ' ', eraseBtn]));
		box.appendChild(msg);
		return box;
	}

	// ---- the record: reports by week or month, worked out here from a decrypted record
	// (the member's own, or one shared with them as nastavnik)

	UI.report = { mode: 'week', anchor: defaultDay() };

	function weekStart(day) { return addDays(day, -parseIso(day).getDay()); }              // weeks run Sunday to Saturday
	function reportRange(st8) {
		var a = st8.anchor, start, end;
		if (st8.mode === 'week') { start = weekStart(a); end = addDays(start, 6); }
		else { var d = parseIso(a); start = iso(new Date(d.getFullYear(), d.getMonth(), 1)); end = iso(new Date(d.getFullYear(), d.getMonth() + 1, 0)); }
		return { start: start, end: end };
	}
	function keptIn(rule) { return C.services.filter(function (s) { return !rule.services[s.key] || rule.services[s.key].keep !== false; }); }
	function entryIn(log, day, svc) { return log && log.entries[entryKey(day, svc)]; }

	/** Counts for the days from start to end (days after today are left out). */
	function stats(ctx, start, end) {
		var today = defaultDay(), keep = keptIn(ctx.rule), out = { days: 0, full: 0, kathismas: 0, ladders: 0, corrected: 0, per: {}, byDay: {} };
		C.services.forEach(function (s) { out.per[s.key] = 0; });
		for (var day = start; day <= end && day <= today; day = addDays(day, 1)) {
			var n = 0;
			out.days++;
			C.services.forEach(function (s) {
				var e = entryIn(ctx.log, day, s.key);
				if (e && e.corrected) { out.corrected++; }
				if (!e || !e.kept) { return; }
				out.per[s.key]++;
				out.kathismas += (e.k || []).length;
				out.ladders += e.ladders || 0;
				if (keep.indexOf(s) >= 0) { n++; }
			});
			out.byDay[day] = n;
			if (keep.length && n >= keep.length) { out.full++; }
		}
		return out;
	}

	/**
	 * The Scripture reading over the days from start to end, apart from the prayer counts:
	 * for each section set in the rule, how many times it was due and how many of those it was read.
	 * A once-a-week-any-day section counts by weeks.
	 */
	function readingStats(ctx, start, end) {
		var rd = ctx.rule.reading, today = defaultDay(), out = { sections: [], chapters: 0, byDay: {}, bySec: {} };
		if (!rd || !(C.bibles || []).length) { return out; }
		for (var day = start; day <= end && day <= today; day = addDays(day, 1)) {
			var e = entryIn(ctx.log, day, 'reading');
			if (e && e.kept && e.read) {
				out.byDay[day] = e.read;
				Object.keys(e.read).forEach(function (k) { out.chapters += e.read[k].list.length; out.bySec[k] = (out.bySec[k] || 0) + e.read[k].list.length; });
			}
		}
		// count from the day each section was set (or, for a rule set before that was kept, the first reading marked)
		var first = Object.keys(ctx.log ? ctx.log.entries : {}).filter(function (k) { return /\|reading$/.test(k); }).map(function (k) { return k.split('|')[0]; }).sort()[0] || today;
		SECTIONS.forEach(function (sec) {
			var x = rd[sec.key];
			if (!x || !(x.per > 0)) { return; }
			var due = 0, done = 0, d, since = x.since || first, from = start < since ? since : start;
			if (x.freq === 'weekly') {
				for (var w = weekStart(from); w <= end && w <= today; w = addDays(w, 7)) {
					due++;
					for (var i = 0; i < 7; i++) { var ee = entryIn(ctx.log, addDays(w, i), 'reading'); if (ee && ee.kept && ee.read && ee.read[sec.key]) { done++; break; } }
				}
			} else {
				for (d = from; d <= end && d <= today; d = addDays(d, 1)) {
					if (!dueOn(x, d)) { continue; }
					due++;
					if ((out.byDay[d] && out.byDay[d][sec.key]) || readAhead(x, sec.key, d, ctx.log)) { done++; }
				}
			}
			out.sections.push({ sec: sec, x: x, due: due, done: done, since: since });
		});
		return out;
	}

	function rangeLabel(st8, r) {
		var a = parseIso(r.start), b = parseIso(r.end);
		if (st8.mode === 'month') { return a.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }); }
		return a.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' – ' + b.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
	}

	function drawRecord() {
		var open = record.open;
		record.innerHTML = '';
		if (!V.key) { panelShown(record, false); return; }
		panelShown(record, true);
		panelTitle(record, 'My record');
		var body = el('div');
		record.appendChild(body);
		renderReport(body, { rule: V.rule, log: V.log, own: true }, UI.report);
		record.open = open;
	}

	/** A report into box. ctx: { rule, log, own, name }; st8: { mode, anchor } (kept between redraws). */
	function renderReport(box, ctx, st8) {
		box.innerHTML = '';
		var r = reportRange(st8), st = stats(ctx, r.start, r.end), keep = keptIn(ctx.rule);
		function again() { renderReport(box, ctx, st8); }
		function go(step) {
			var d = parseIso(st8.anchor);
			if (st8.mode === 'week') { d.setDate(d.getDate() + 7 * step); } else { d = new Date(d.getFullYear(), d.getMonth() + step, 1, 12); }
			st8.anchor = iso(d); again();
		}
		function mode(m) { var b = el('button', { type: 'button', 'aria-pressed': st8.mode === m ? 'true' : 'false', text: m === 'week' ? 'Week' : 'Month' }); b.addEventListener('click', function () { st8.mode = m; again(); }); return b; }
		var later = r.end >= defaultDay();
		box.appendChild(el('div', { class: 'pr-report-bar' }, [
			el('span', { class: 'pr-tabs' }, [mode('week'), mode('month')]),
			el('button', { type: 'button', text: '‹', title: 'Earlier', on: { click: function () { go(-1); } } }),
			el('strong', { text: ' ' + rangeLabel(st8, r) + ' ' }),
			later ? null : el('button', { type: 'button', text: '›', title: 'Later', on: { click: function () { go(1); } } })
		]));

		var t = el('table', { class: 'pr-grid pr-summary' });
		t.appendChild(el('tr', {}, [el('th', { text: '' }), el('th', { text: 'Kept' }), el('th', { text: '' })]));
		keep.forEach(function (s) {
			var n = st.per[s.key], pct = st.days ? Math.round(100 * n / st.days) : 0;
			t.appendChild(el('tr', {}, [el('th', { text: s.name }), el('td', { text: n + ' of ' + st.days }), el('td', {}, [el('span', { class: 'pr-bar', style: 'width:' + (pct * 0.08).toFixed(2) + 'em' }), ' ' + pct + '%'])]));
		});
		box.appendChild(t);

		var rs = readingStats(ctx, r.start, r.end);
		if (rs.sections.length) {
			var t2 = el('table', { class: 'pr-grid pr-summary pr-reading-summary' });
			t2.appendChild(el('tr', {}, [el('th', { text: 'Scripture' }), el('th', { text: 'Read' }), el('th', { text: '' })]));
			rs.sections.forEach(function (x) {
				var pct = x.due ? Math.round(100 * x.done / x.due) : 0;
				t2.appendChild(el('tr', {}, [
					el('th', {}, [x.sec.name, el('span', { class: 'pr-note', text: ' (' + freqText(x.x) + ')' })]),
					el('td', { text: x.due ? x.done + ' of ' + x.due + (x.x.freq === 'weekly' ? (x.due === 1 ? ' week' : ' weeks') : '') : '—' }),
					el('td', {}, x.due ? [el('span', { class: 'pr-bar', style: 'width:' + (pct * 0.08).toFixed(2) + 'em' }), ' ' + pct + '%'] : [el('span', { class: 'pr-note', text: 'none due yet' })])
				]));
			});
			box.appendChild(t2);
		}

		var lines = [];
		lines.push(st.full + ' of ' + st.days + ' days with every service of ' + (ctx.own ? 'your' : 'the') + ' rule kept.');
		if (st.kathismas) { lines.push(st.kathismas + ' kathismas read (' + (Math.round(st.kathismas / 2) / 10) + ' times the whole Psalter).'); }
		if (st.ladders) { lines.push(half(st.ladders) + ' ladders of the Jesus Prayer (' + Math.round(st.ladders * 100).toLocaleString() + ' prayers).'); }
		if (rs.chapters) {
			lines.push(rs.chapters + (rs.chapters === 1 ? ' chapter' : ' chapters') + ' of Scripture read (' + SECTIONS.filter(function (sec) { return rs.bySec[sec.key]; }).map(function (sec) { return sec.name.replace(/^The /, '') + ' ' + rs.bySec[sec.key]; }).join(', ') + ').');
		}
		if (st.corrected) { lines.push(st.corrected + ' marked "corrected later".'); }
		box.appendChild(el('ul', { class: 'pr-report-lines' }, lines.map(function (x) { return el('li', { text: x }); })));

		box.appendChild(st8.mode === 'week' ? weekGrid(ctx, r, keep, rs) : monthGrid(ctx, r, st, keep, rs));
		box.appendChild(el('p', { class: 'pr-note', text: '✓* marks a service or reading checked or unchecked more than 7 days after its day. Days are church days: Vespers and Compline count for the next day. In the Scripture columns, · is a reading due and not done; a blank day had none due.' }));
		if (ctx.own) {
			box.appendChild(el('p', {}, [
				el('button', { type: 'button', text: 'Print this report', on: { click: function () { record.open = true; document.body.classList.add('pr-printing-own'); window.print(); document.body.classList.remove('pr-printing-own'); } } }), ' ',
				el('button', { type: 'button', text: 'Download my whole record (CSV)', on: { click: downloadCsv } })
			]));
		}
	}

	function weekGrid(ctx, r, keep, rs) {
		var t = el('table', { class: 'pr-grid' }), today = defaultDay(), secs = rs.sections;
		t.appendChild(el('tr', {}, [el('th', { text: '' })].concat(keep.map(function (s) { return el('th', { text: s.name }); })).concat(secs.map(function (x, i) { return el('th', { class: i === 0 ? 'pr-grid-reading' : '', title: x.sec.name, text: { gospel: 'Gospel', apostle: 'Apostle', old: 'O.T.' }[x.sec.key] || x.sec.name }); }))));
		for (var day = r.start; day <= r.end; day = addDays(day, 1)) {
			var row = [el('th', { text: parseIso(day).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) })];
			keep.forEach(function (s) {
				var e = entryIn(ctx.log, day, s.key);
				row.push(el('td', { text: day > today ? '' : (e && e.kept ? (e.corrected ? '✓*' : '✓') : '·') }));
			});
			secs.forEach(function (x, i) {
				var read = rs.byDay[day] && rs.byDay[day][x.sec.key], er = entryIn(ctx.log, day, 'reading');
				var mark = day > today ? '' : read ? (er && er.corrected ? '✓*' : '✓') : (x.x.freq !== 'weekly' && day >= x.since && dueOn(x.x, day) && !readAhead(x.x, x.sec.key, day, ctx.log) ? '·' : '');
				row.push(el('td', { class: i === 0 ? 'pr-grid-reading' : '', text: mark }));
			});
			t.appendChild(el('tr', { class: day === today ? 'is-today' : '' }, row));
		}
		return t;
	}

	function monthGrid(ctx, r, st, keep, rs) {
		var t = el('table', { class: 'pr-month' }), today = defaultDay(), m = keep.length || 1;
		var names = []; for (var i = 0; i < 7; i++) { names.push(el('th', { text: new Date(2026, 10, 1 + i).toLocaleDateString(undefined, { weekday: 'short' }) })); }   // Nov 1, 2026 is a Sunday
		t.appendChild(el('tr', {}, names));
		var row = el('tr'), lead = parseIso(r.start).getDay();
		for (var j = 0; j < lead; j++) { row.appendChild(el('td')); }
		for (var day = r.start; day <= r.end; day = addDays(day, 1)) {
			if (parseIso(day).getDay() === 0 && row.children.length) { t.appendChild(row); row = el('tr'); }
			var n = st.byDay[day], future = day > today;
			var cell = el('td', { class: (future ? 'is-future' : '') + (day === today ? ' is-today' : ''), title: future ? '' : n + ' of ' + m + ' kept' }, [
				el('span', { class: 'pr-month-day', text: String(parseIso(day).getDate()) }),
				future ? null : el('span', { class: 'pr-month-n', text: n + '/' + m }),
				!future && rs.byDay[day] ? el('span', { class: 'pr-month-read', title: 'Scripture read', text: '✦ Scripture' }) : null
			]);
			if (!future && n) { cell.style.background = 'rgba(146,26,10,' + (0.12 + 0.6 * Math.min(1, n / m)).toFixed(2) + ')'; if (n / m > 0.6) { cell.style.color = '#fff'; } }
			if (ctx.own) { cell.addEventListener('click', (function (d) { return function () { UI.day = d; dateInput.value = d; drawTabs(); show(); }; })(day)); }
			row.appendChild(cell);
		}
		t.appendChild(row);
		return t;
	}

	function downloadCsv() {
		var rows = [['date', 'service', 'kept', 'kathismas or chapters', 'ladders', 'corrected later', 'first marked', 'last changed']];
		Object.keys(V.log.entries).sort().forEach(function (k) {
			var e = V.log.entries[k], a = k.split('|'), s = serviceByKey(a[1]);
			if (a[1] === 'reading') {
				var ch = Object.keys(e.read || {}).map(function (sk) { return e.read[sk].list.map(function (bc) { return bc[0] + ' ' + bc[1]; }).join(' '); }).join('; ');
				rows.push([a[0], 'Scripture', e.kept ? 'yes' : 'no', ch, '', e.corrected ? 'yes' : '', e.first || '', e.edited || '']);
				return;
			}
			rows.push([a[0], s ? s.name : a[1], e.kept ? 'yes' : 'no', (e.k || []).join(' '), e.ladders || 0, e.corrected ? 'yes' : '', e.first || '', e.edited || '']);
		});
		var csv = rows.map(function (r) { return r.map(function (x) { x = String(x); return /[",\n]/.test(x) ? '"' + x.replace(/"/g, '""') + '"' : x; }).join(','); }).join('\n');
		var url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
		var a = el('a', { href: url, download: 'prayer-rule-record-' + defaultDay() + '.csv' });
		document.body.appendChild(a); a.click(); a.remove();
		setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
	}

	// ---- sharing with a nastavnik

	var SH = { mine: [], withMe: [], keys: {}, open: null, report: { mode: 'week', anchor: defaultDay() } };

	/** base + path, with any ?query joined right whether or not the site uses pretty permalinks. */
	function restUrl(base, path) {
		var i = path.indexOf('?'), q = i >= 0 ? path.slice(i + 1) : '', u = base + (i >= 0 ? path.slice(0, i) : path);
		return q ? u + (u.indexOf('?') >= 0 ? '&' : '?') + q : u;
	}
	async function shareApi(method, path, body) {
		var r = await fetch(restUrl(C.sharing, path), { method: method, credentials: 'same-origin', cache: 'no-store', headers: jsonHeaders(), body: body ? JSON.stringify(body) : undefined });
		var data = await r.json().catch(function () { return {}; });
		return { status: r.status, data: data };
	}
	/** The AES key this member shares with another (the same on both sides). */
	async function pairKey(otherId, otherPub) {
		var id = otherId + ':' + otherPub;
		if (SH.keys[id]) { return SH.keys[id]; }
		var pub = await crypto.subtle.importKey('raw', unb64(otherPub), { name: 'ECDH', namedCurve: 'P-256' }, false, []);
		var bits = await crypto.subtle.deriveBits({ name: 'ECDH', public: pub }, V.priv, 256);
		var hk = await crypto.subtle.importKey('raw', bits, 'HKDF', false, ['deriveKey']);
		var ids = [C.user, otherId].map(Number).sort(function (a, b) { return a - b; }).join('-');
		SH.keys[id] = await crypto.subtle.deriveKey({ name: 'HKDF', hash: 'SHA-256', salt: enc.encode('prayer-rule/share/1'), info: enc.encode(ids) }, hk, { name: 'AES-GCM', length: 256 }, false, ['encrypt', 'decrypt']);
		return SH.keys[id];
	}
	/** A short code both people see the same, to check no one has swapped a key. */
	async function safetyCode(otherPub) {
		var a = [V.pub, otherPub].sort(), h = new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(a[0] + '|' + a[1])));
		var abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789', s = '';
		for (var i = 0; i < 12; i++) { s += abc[h[i] % 32] + (i % 4 === 3 && i < 11 ? '-' : ''); }
		return s;
	}
	function shareAad(owner, viewer, what) { return enc.encode('prayer-rule/share/' + owner + '/' + viewer + '/' + what); }
	async function sealFor(key, obj, aadBytes) {
		var iv = rand(12), ct = new Uint8Array(await crypto.subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: aadBytes }, key, enc.encode(JSON.stringify(obj))));
		var all = new Uint8Array(12 + ct.length); all.set(iv); all.set(ct, 12); return b64(all);
	}
	async function openFrom(key, text, aadBytes) {
		var all = unb64(text);
		return JSON.parse(dec.decode(await crypto.subtle.decrypt({ name: 'AES-GCM', iv: all.slice(0, 12), additionalData: aadBytes }, key, all.slice(12))));
	}

	async function loadShares() {
		if (!V.priv) { return; }
		var r = await shareApi('GET', 'shares');
		if (r.status !== 200) { return; }
		SH.mine = r.data.mine || []; SH.withMe = r.data.with_me || [];
	}
	/** Send the nastavniks a fresh encrypted copy of the rule and record. */
	async function pushShares() {
		if (!V.priv || !SH.mine.length) { return; }
		for (var i = 0; i < SH.mine.length; i++) {
			var sh = SH.mine[i];
			if (!sh.viewer.key) { continue; }
			var k = await pairKey(sh.viewer.id, sh.viewer.key);
			var data = await sealFor(k, { rule: V.rule, log: V.log, at: now() }, shareAad(C.user, sh.viewer.id, 'data'));
			await shareApi('PUT', 'shares/' + sh.id, { data: data });
		}
	}

	var sharing = panel('pr-sharing');

	function unreadCount() {
		var n = 0;
		SH.mine.concat(SH.withMe).forEach(function (sh) { if (sh.last_message && sh.last_author !== C.user && sh.last_message > seen(sh.id)) { n++; } });
		return n;
	}
	function seen(id, set) { var k = 'prayerRule.seen.' + C.user + '.' + id; try { if (set) { localStorage.setItem(k, set); } return localStorage.getItem(k) || ''; } catch (e) { return ''; } }

	function drawSharing() {
		var open = sharing.open;
		sharing.innerHTML = '';
		if (!V.key || !V.priv) { panelShown(sharing, false); return; }
		panelShown(sharing, true);
		var unread = unreadCount();
		panelTitle(sharing, 'Nastavnik', unread);
		var body = el('div');
		sharing.appendChild(body);
		if (SH.open) { drawThread(body, SH.open); sharing.open = true; return; }

		body.appendChild(el('h4', { text: 'Sharing my rule and record' }));
		if (!SH.mine.length) { body.appendChild(el('p', { class: 'pr-note', text: 'You are not sharing with anyone.' })); }
		SH.mine.forEach(function (sh) {
			var code = el('code', { class: 'pr-code' }), row = el('p', {}, [
				el('strong', { text: sh.viewer.name }), '   safety code ', code, '   ',
				el('button', { type: 'button', text: 'Messages' + (sh.last_message && sh.last_author !== C.user && sh.last_message > seen(sh.id) ? ' (new)' : ''), on: { click: function () { SH.open = { share: sh, mine: true }; drawSharing(); } } }), ' ',
				el('button', { type: 'button', class: 'pr-link', text: 'Stop sharing', on: { click: async function () { if (row.dataset.sure !== '1') { row.dataset.sure = '1'; this.textContent = 'Click again to stop sharing'; return; } await shareApi('DELETE', 'shares/' + sh.id); await loadShares(); drawSharing(); } } })
			]);
			if (sh.viewer.key) { safetyCode(sh.viewer.key).then(function (c) { code.textContent = c; }); } else { code.textContent = '—'; }
			body.appendChild(row);
		});
		var who = el('input', { type: 'text', placeholder: 'their username or email' }), msg = el('p', { class: 'pr-status' });
		var find = el('button', { type: 'button', text: 'Find' });
		find.addEventListener('click', async function () {
			msg.textContent = 'Looking…';
			var r = await shareApi('GET', 'people?who=' + encodeURIComponent(who.value.trim()));
			if (r.status !== 200) { msg.textContent = r.data.message || 'No one found.'; return; }
			var person = r.data;
			if (!person.key) { msg.textContent = person.name + ' has not set up a private rule yet. They need one (even an empty one) before you can share with them.'; return; }
			var code = await safetyCode(person.key);
			msg.innerHTML = '';
			msg.appendChild(document.createTextNode('Share your rule and record with ' + person.name + '? Safety code: '));
			msg.appendChild(el('code', { class: 'pr-code', text: code }));
			msg.appendChild(document.createTextNode(' '));
			msg.appendChild(el('button', { type: 'button', class: 'pr-primary', text: 'Share', on: { click: async function () {
				var k = await pairKey(person.id, person.key);
				var data = await sealFor(k, { rule: V.rule, log: V.log, at: now() }, shareAad(C.user, person.id, 'data'));
				var c = await shareApi('POST', 'shares', { viewer: person.id, data: data });
				if (c.status !== 200) { msg.textContent = c.data.message || 'Could not share.'; return; }
				await loadShares(); drawSharing(); status('You are now sharing with ' + person.name + '.');
			} } }));
		});
		body.appendChild(el('p', {}, ['Share with: ', who, ' ', find]));
		body.appendChild(msg);
		body.appendChild(el('p', { class: 'pr-note', text: 'Your nastavnik sees your rule and record and can send you messages and suggested changes; you decide whether to accept them. Everything is encrypted between the two of you. The safety code is the same on both sides: compare it in person or by phone to be sure no one is in between.' }));

		body.appendChild(el('h4', { text: 'Shared with me' }));
		if (!SH.withMe.length) { body.appendChild(el('p', { class: 'pr-note', text: 'No one is sharing with you.' })); }
		SH.withMe.forEach(function (sh) {
			var code = el('code', { class: 'pr-code' });
			body.appendChild(el('p', {}, [
				el('strong', { text: sh.owner.name }), '   safety code ', code, '   ',
				el('button', { type: 'button', text: 'Open' + (sh.last_message && sh.last_author !== C.user && sh.last_message > seen(sh.id) ? ' (new)' : ''), on: { click: function () { SH.open = { share: sh, mine: false }; drawSharing(); } } })
			]));
			if (sh.owner.key) { safetyCode(sh.owner.key).then(function (c) { code.textContent = c; }); }
		});
		sharing.open = open;
	}

	/** One share: as the member (messages, suggestions to accept) or as the nastavnik (their record, messages, suggest). */
	async function drawThread(box, o) {
		var sh = o.share, other = o.mine ? sh.viewer : sh.owner, owner = o.mine ? C.user : sh.owner.id, viewer = o.mine ? sh.viewer.id : C.user;
		box.appendChild(el('p', {}, [el('button', { type: 'button', text: '‹ Back', on: { click: function () { SH.open = null; drawSharing(); } } }), ' ', el('strong', { text: o.mine ? 'Messages with ' + other.name : other.name })]));
		if (!other.key) { box.appendChild(el('p', { text: 'This person has no key yet.' })); return; }
		var k = await pairKey(other.id, other.key), theirs = null;
		if (!o.mine) {
			var r = await shareApi('GET', 'shares/' + sh.id);
			try { theirs = await openFrom(k, r.data.data, shareAad(owner, viewer, 'data')); } catch (e) { theirs = null; }
			if (!theirs) { box.appendChild(el('p', { class: 'pr-status is-error', text: 'Their record could not be opened.' })); }
			else {
				box.appendChild(el('p', { class: 'pr-note', text: 'Last updated ' + new Date(theirs.at).toLocaleString() + '. Next kathisma: ' + theirs.rule.next + '.' }));
				box.appendChild(ruleSummary(theirs.rule));
				var rep = el('div'); box.appendChild(rep);
				renderReport(rep, { rule: theirs.rule, log: theirs.log, own: false, name: other.name }, SH.report);
			}
		}
		// Messages.
		var list = el('div', { class: 'pr-thread' }); box.appendChild(el('h4', { text: 'Messages' })); box.appendChild(list);
		var mr = await shareApi('GET', 'shares/' + sh.id + '/messages'), msgs = [];
		for (var i = 0; i < (mr.data.messages || []).length; i++) {
			var m = mr.data.messages[i];
			try { msgs.push({ id: m.id, author: m.author, at: m.created, body: await openFrom(k, m.body, shareAad(owner, viewer, 'msg/' + m.author)) }); } catch (e) { msgs.push({ id: m.id, author: m.author, at: m.created, body: { type: 'text', text: '(could not be opened)' } }); }
		}
		var answered = {};
		msgs.forEach(function (m) { if (m.body.type === 'answer') { answered[m.body.to] = m.body.accepted; } });
		if (!msgs.length) { list.appendChild(el('p', { class: 'pr-note', text: 'No messages yet.' })); }
		msgs.forEach(function (m) {
			var who = m.author === C.user ? 'You' : other.name, when = new Date(m.at.replace(' ', 'T') + 'Z').toLocaleString();
			var item = el('div', { class: 'pr-msg' + (m.author === C.user ? ' is-mine' : '') }, [el('p', { class: 'pr-msg-head', text: who + ' · ' + when })]);
			if (m.body.text) { item.appendChild(el('p', { text: m.body.text })); }
			if (m.body.type === 'suggest') {
				item.appendChild(el('p', { class: 'pr-msg-head', text: 'Suggested changes to the rule:' }));
				item.appendChild(el('ul', {}, describeChanges(m.body.changes, m.id in answered ? null : (o.mine ? V.rule : (theirs && theirs.rule))).map(function (x) { return el('li', { text: x }); })));
				if (m.id in answered) { item.appendChild(el('p', { class: 'pr-note', text: answered[m.id] ? 'Accepted.' : 'Declined.' })); }
				else if (o.mine) {
					item.appendChild(el('p', {}, [
						el('button', { type: 'button', class: 'pr-primary', text: 'Accept', on: { click: function () { answer(m, true); } } }), ' ',
						el('button', { type: 'button', text: 'Decline', on: { click: function () { answer(m, false); } } })
					]));
				} else { item.appendChild(el('p', { class: 'pr-note', text: 'Waiting for an answer.' })); }
			}
			if (m.body.type === 'answer') { item.appendChild(el('p', { class: 'pr-note', text: m.body.accepted ? 'Accepted the suggested changes.' : 'Declined the suggested changes.' })); }
			list.appendChild(item);
		});
		if (msgs.length) { seen(sh.id, msgs[msgs.length - 1].at); drawAccount(); }

		async function send(body) {
			var sealed = await sealFor(k, body, shareAad(owner, viewer, 'msg/' + C.user));
			var r = await shareApi('POST', 'shares/' + sh.id + '/messages', { body: sealed });
			if (r.status !== 200) { status(r.data.message || 'Not sent.', true); return; }
			await loadShares(); SH.open.share = (o.mine ? SH.mine : SH.withMe).filter(function (x) { return x.id === sh.id; })[0] || sh; drawSharing();
		}
		async function answer(m, accepted) {
			if (accepted) { applyChanges(V.rule, m.body.changes); V.rule.updated = now(); await saveVault(); drawSettings(); drawTabs(); show(); }
			await send({ type: 'answer', to: m.id, accepted: accepted });
		}
		var text = el('textarea', { rows: '3', style: 'width:100%' });
		box.appendChild(el('p', {}, [text]));
		box.appendChild(el('p', {}, [el('button', { type: 'button', text: 'Send', on: { click: function () { if (text.value.trim()) { send({ type: 'text', text: text.value.trim() }); } } } })]));
		if (!o.mine && theirs) { box.appendChild(suggestForm(theirs.rule, send)); }
	}

	function ruleSummary(rule) {
		var ul = el('ul', { class: 'pr-report-lines' });
		C.services.forEach(function (s) {
			var svc = rule.services[s.key];
			if (svc && svc.keep === false) { ul.appendChild(el('li', { text: s.name + ': not kept' })); return; }
			var parts = s.portions.map(function (p) { var v = (svc && svc.portions[p.key]) || { kathismas: 0, ladders: 0 }; return (s.portions.length > 1 && p.name !== s.name ? p.name + ' ' : '') + portionText(v); });
			ul.appendChild(el('li', { text: s.name + ': ' + parts.join('; ') }));
		});
		return ul;
	}
	function portionText(v) {
		var a = [];
		if (v.kathismas) { a.push(v.kathismas + (v.kathismas === 1 ? ' kathisma' : ' kathismas')); }
		if (v.ladders) { a.push(half(v.ladders) + (v.ladders > 1 ? ' ladders' : ' ladder')); }
		return a.length ? a.join(' and ') : 'nothing chosen';
	}
	/** changes: { services: { key: { keep, portions: { pkey: { kathismas, ladders } } } }, reading: { section: { per, freq, wd } } } */
	function describeChanges(changes, rule) {
		var out = [];
		Object.keys((changes && changes.reading) || {}).forEach(function (key) {
			var sec = SECTIONS.filter(function (x) { return x.key === key; })[0], c = changes.reading[key], cur = rule && rule.reading && rule.reading[key];
			if (!sec) { return; }
			var text = c.per > 0 ? freqText(c) : 'no reading';
			out.push('Scripture, ' + sec.name.replace(/^The /, '') + ': ' + text + (cur ? ' (now ' + (cur.per > 0 ? freqText(Object.assign({ freq: 'daily', wd: 6 }, cur)) : 'no reading') + ')' : ''));
		});
		Object.keys((changes && changes.services) || {}).forEach(function (key) {
			var s = serviceByKey(key), c = changes.services[key], cur = rule && rule.services[key];
			if (!s) { return; }
			if (c.keep === false) { out.push(s.name + ': no longer kept'); return; }
			if (c.keep === true && cur && cur.keep === false) { out.push(s.name + ': to be kept'); }
			Object.keys(c.portions || {}).forEach(function (pk) {
				var p = s.portions.filter(function (x) { return x.key === pk; })[0], was = cur && cur.portions[pk];
				out.push(s.name + (s.portions.length > 1 && p && p.name !== s.name ? ' (' + p.name + ')' : '') + ': ' + portionText(c.portions[pk]) + (was ? ' (now ' + portionText(was) + ')' : ''));
			});
		});
		return out.length ? out : ['(no changes)'];
	}
	function applyChanges(rule, changes) {
		if (changes && changes.reading) {
			var rd = readingOf(rule);
			Object.keys(changes.reading).forEach(function (key) {
				var x = rd[key], c = changes.reading[key];
				if (!x) { return; }
				if (!(x.per > 0) && +c.per > 0) { x.since = defaultDay(); }
				x.per = +c.per || 0;
				if (FREQS.indexOf(c.freq) >= 0) { if (c.freq === 'alternate' && x.freq !== 'alternate') { x.anchor = defaultDay(); } x.freq = c.freq; }
				if (typeof c.wd === 'number') { x.wd = c.wd; }
			});
		}
		Object.keys((changes && changes.services) || {}).forEach(function (key) {
			var c = changes.services[key];
			rule.services[key] = rule.services[key] || { keep: true, portions: {} };
			if (typeof c.keep === 'boolean') { rule.services[key].keep = c.keep; }
			Object.keys(c.portions || {}).forEach(function (pk) { rule.services[key].portions[pk] = { kathismas: +c.portions[pk].kathismas || 0, ladders: +c.portions[pk].ladders || 0 }; });
		});
	}

	/** The nastavnik's form: the member's rule, to change and send as a suggestion. */
	function suggestForm(rule, send) {
		var draft = JSON.parse(JSON.stringify(rule.services)), box = el('details', { class: 'pr-suggest' });
		box.appendChild(el('summary', { text: 'Suggest a change to the rule' }));
		var t = el('table');
		C.services.forEach(function (s) {
			var svc = draft[s.key] || (draft[s.key] = { keep: true, portions: {} });
			var cb = el('input', { type: 'checkbox' }); cb.checked = svc.keep !== false;
			cb.addEventListener('change', function () { svc.keep = cb.checked; });
			t.appendChild(el('tr', {}, [el('th', { colspan: '3' }, [el('label', {}, [cb, ' ', el('strong', { text: s.name })])])]));
			s.portions.forEach(function (p) {
				var v = svc.portions[p.key] || (svc.portions[p.key] = { kathismas: 0, ladders: 0 });
				var k = [[0, 'none']].concat(p.kathismas.map(function (n) { return [n, n]; })), l = [[0, 'none']].concat(p.ladders.map(function (n) { return [n, half(n)]; }));
				if (v.kathismas && !p.kathismas.some(function (n) { return n === v.kathismas; })) { k.push([v.kathismas, v.kathismas]); }
				if (v.ladders && !p.ladders.some(function (n) { return n === v.ladders; })) { l.push([v.ladders, half(v.ladders)]); }
				t.appendChild(el('tr', {}, [
					el('td', { text: s.portions.length > 1 ? p.name : '' }),
					el('td', {}, ['kathismas ', select(k, v.kathismas, function (x) { v.kathismas = +x; })]),
					el('td', {}, ['ladders ', select(l, v.ladders, function (x) { v.ladders = +x; })])
				]));
			});
		});
		box.appendChild(t);
		var rdraft = null;
		if ((C.bibles || []).length) {
			rdraft = JSON.parse(JSON.stringify(readingOf(JSON.parse(JSON.stringify(rule)))));
			var t3 = el('table');
			t3.appendChild(el('tr', {}, [el('th', { colspan: '2', text: 'Scripture reading' })]));
			var per = [[0, 'none']]; for (var n = 1; n <= 10; n++) { per.push([n, n]); }
			SECTIONS.forEach(function (sec) {
				var x = rdraft[sec.key], wd = select(WEEKDAYS.map(function (nm, i) { return [i, nm]; }), x.wd, function (v) { x.wd = +v; });
				wd.style.display = x.freq === 'weekday' ? '' : 'none';
				t3.appendChild(el('tr', {}, [el('td', { text: sec.name }), el('td', {}, [
					select(per, x.per, function (v) { x.per = +v; }), ' chapters ',
					select([['daily', 'every day'], ['alternate', 'every other day'], ['weekday', 'once a week, on'], ['weekly', 'once a week, any day']], x.freq, function (v) { x.freq = v; wd.style.display = v === 'weekday' ? '' : 'none'; }), ' ', wd
				])]));
			});
			box.appendChild(t3);
		}
		var why = el('textarea', { rows: '2', style: 'width:100%', placeholder: 'A word of explanation (optional)' });
		box.appendChild(why);
		box.appendChild(el('p', {}, [el('button', { type: 'button', class: 'pr-primary', text: 'Send the suggestion', on: { click: function () {
			var changes = { services: {} };
			C.services.forEach(function (s) {
				var a = rule.services[s.key] || { keep: true, portions: {} }, b = draft[s.key], c = {};
				if ((a.keep !== false) !== (b.keep !== false)) { c.keep = b.keep !== false; }
				s.portions.forEach(function (p) {
					var x = a.portions[p.key] || { kathismas: 0, ladders: 0 }, y = b.portions[p.key];
					if (y && (x.kathismas !== y.kathismas || x.ladders !== y.ladders)) { c.portions = c.portions || {}; c.portions[p.key] = { kathismas: y.kathismas, ladders: y.ladders }; }
				});
				if (Object.keys(c).length) { changes.services[s.key] = c; }
			});
			if (rdraft) {
				var cur = readingOf(JSON.parse(JSON.stringify(rule)));
				SECTIONS.forEach(function (sec) {
					var a = cur[sec.key], b = rdraft[sec.key];
					if (a.per !== b.per || a.freq !== b.freq || (b.freq === 'weekday' && a.wd !== b.wd)) { changes.reading = changes.reading || {}; changes.reading[sec.key] = { per: b.per, freq: b.freq, wd: b.wd }; }
				});
			}
			if (!Object.keys(changes.services).length && !changes.reading) { status('Nothing has been changed.', true); return; }
			send({ type: 'suggest', changes: changes, text: why.value.trim() });
		} } })]));
		return box;
	}

	// ---- loading and showing a service

	var req = 0, shown = null;
	function show() {
		if (UI.service === 'reading') { showReading(); return; }
		var svc = serviceByKey(UI.service); if (!svc) { return; }
		var r = R(), mine = ++req, p = {}, start = r.next, total = 0, ladders = 0;
		svc.portions.forEach(function (x, i) {
			var v = portionOf(svc.key, x.key);
			p[x.key] = { kathismas: v.kathismas, ladders: v.ladders };
			if (i === 0) { p[x.key].start = start; }
			total += v.kathismas; ladders += v.ladders;
		});
		out.innerHTML = ''; out.appendChild(el('p', { class: 'pr-note', text: 'Loading…' }));
		fetch(C.rest + svc.key + '/reading', {
			method: 'POST', credentials: 'same-origin',
			headers: jsonHeaders(),
			body: JSON.stringify({ date: UI.day, translation: r.translation, verses: r.verses ? 1 : 0, p: p, troparia: r.troparia ? 1 : 0, bedtime: svc.key === 'cell-rule' && r.services[svc.key] && r.services[svc.key].bedtime ? 1 : 0 })
		}).then(function (res) { if (!res.ok) { throw res; } return res.json(); }).then(function (data) {
			if (mine !== req) { return; }
			out.innerHTML = data.day_html;
			out.appendChild(el('h2', { class: 'pr-service-title', text: svc.name }));
			out.insertAdjacentHTML('beforeend', data.html);
			shown = { day: UI.day, service: svc.key, start: start, total: total, ladders: ladders };
			out.appendChild(el('div', { class: 'pr-done' }, finishControls()));
		}).catch(function () { if (mine === req) { out.innerHTML = ''; out.appendChild(el('p', { text: 'The service could not be loaded. Please try again.' })); } });
	}

	// ---- the Scripture reading of the day

	function showReading() {
		var r = R(), mine = ++req, rd = readingOf(r), day = UI.day;
		var items = readingDay(r, day, V.key ? V.log : null);
		var open = items.filter(function (it) { return it.state !== 'later'; }), later = items.filter(function (it) { return it.state === 'later'; });
		shown = { day: day, service: 'reading' };
		out.innerHTML = '';
		out.appendChild(el('p', { class: 'pr-note', text: 'Loading…' }));
		fetchSections(open, r).then(function (data) {
			if (mine !== req) { return; }
			out.innerHTML = data.day_html;
			out.appendChild(el('h2', { class: 'pr-service-title', text: 'Scripture' }));
			if (!items.length) {
				out.appendChild(el('p', { class: 'pr-reading-none' }, ['No Scripture reading is set. ', el('button', { type: 'button', class: 'pr-primary', text: 'Set it in Scripture reading', on: { click: function () { openPanel(readingPanel); } } })]));
				return;
			}
			if (!open.length) { out.appendChild(el('p', { class: 'pr-reading-none', text: 'No reading is due today.' })); }
			open.forEach(function (it) { out.appendChild(readingPart(it, data.sections[it.sec.key] || '', day, rd)); });
			if (later.length) {
				var box = el('div', { class: 'pr-reading-later' }, [el('p', { class: 'pr-reading-later-head', text: open.length ? 'Not due today' : 'Coming next' })]);
				later.forEach(function (it) { box.appendChild(laterCard(it, day, r, rd)); });
				out.appendChild(box);
			}
		}).catch(function () { if (mine === req) { out.innerHTML = ''; out.appendChild(el('p', { text: 'The reading could not be loaded. Please try again.' })); } });
	}
	/** The chapters of some sections, and the day's heading, from the site. */
	function fetchSections(items, r) {
		var list = [];
		items.forEach(function (it) { it.list.forEach(function (bc) { list.push({ s: it.sec.key, t: it.t, b: bc[0], c: bc[1] }); }); });
		return fetch(C.scripture, {
			method: 'POST', credentials: 'same-origin',
			headers: jsonHeaders(),
			body: JSON.stringify({ date: UI.day, verses: r.verses ? 1 : 0, items: list })
		}).then(function (res) { if (!res.ok) { throw res; } return res.json(); });
	}
	/** One section of the day: its heading, its chapters, and its own button. */
	function readingPart(it, html, day, rd) {
		var x = rd[it.sec.key], box = el('div', { class: 'pr-reading-part' + (it.state === 'read' ? ' is-read' : '') });
		box.appendChild(el('h3', { class: 'pr-reading-section', text: it.sec.name }));
		box.appendChild(el('p', { class: 'pr-reading-how', text: readingSummary([it], rd) + (x && x.per > 0 ? ' · ' + freqText(x) : '') + (it.early ? ' · read ahead' : '') }));
		var body = el('div', { class: 'prayer-rule-reading pr-scripture' }); body.innerHTML = html;
		box.appendChild(body);
		box.appendChild(el('div', { class: 'pr-done' }, readingControls(it, day, rd)));
		return box;
	}
	/** A section not due today: when it comes, and a button to read it now. */
	function laterCard(it, day, r, rd) {
		var card = el('div', { class: 'pr-later-card' });
		var go = el('button', { type: 'button', class: 'pr-tool', text: 'Read it now' });
		go.addEventListener('click', function () {
			go.disabled = true; go.textContent = 'Loading…';
			fetchSections([it], r).then(function (data) {
				it.state = 'due'; it.early = true;
				card.replaceWith(readingPart(it, data.sections[it.sec.key] || '', day, rd));
			}).catch(function () { go.disabled = false; go.textContent = 'Read it now'; });
		});
		card.appendChild(el('div', { class: 'pr-later-text' }, [
			el('strong', { text: it.sec.name }),
			el('span', { text: freqText(rd[it.sec.key]) + ' · ' + it.why }),
			el('span', { class: 'pr-note', text: 'Next: ' + readingSummary([it], rd) })
		]));
		card.appendChild(go);
		return card;
	}
	function readingSummary(parts, rd) {
		return parts.map(function (pt) {
			var first = pt.list[0], last = pt.list[pt.list.length - 1];
			var a = bookLabel(pt.sec, rd, first[0]) + ' ' + first[1];
			if (pt.list.length > 1) { a += ' – ' + (last[0] === first[0] ? '' : bookLabel(pt.sec, rd, last[0]) + ' ') + last[1]; }
			return a;
		}).join('; ');
	}
	function readingControls(it, day, rd) {
		var name = it.sec.name.replace(/^The /, 'the '), late = daysAgo(day) > 7;
		if (!V.key) {
			var b = el('button', { type: 'button', class: 'pr-primary', text: 'Finished ' + name + ': go on from here next time' });
			b.addEventListener('click', function () {
				var x = rd[it.sec.key]; x.b = it.next.b; x.c = it.next.c;
				saveSettings(); drawSettings();
				b.replaceWith(el('p', { text: 'Next time: ' + readingSummary(readingParts(R()).filter(function (pt) { return pt.sec.key === it.sec.key; }).map(function (pt) { return { sec: pt.sec, list: [pt.list[0]] }; }), rd) + '.' }));
			});
			return [el('p', {}, [b])];
		}
		var e = entry(day, 'reading');
		if (it.state === 'read') {
			var undo = el('button', { type: 'button', class: 'pr-link', text: 'Undo' });
			undo.addEventListener('click', function () { setRead(it, day, false); });
			return [el('p', { class: 'pr-kept' }, ['✓ Read: ' + readingSummary([it], rd) + (e && e.corrected ? ' (corrected later)' : '') + '. ', undo])];
		}
		var mark = el('button', { type: 'button', class: 'pr-primary', text: 'Mark ' + name + ' as read' });
		mark.addEventListener('click', function () { mark.disabled = true; setRead(it, day, true); });
		var kids = [el('p', {}, [mark])];
		if (late) { kids.push(el('p', { class: 'pr-note', text: 'This day is more than 7 days ago: it will be marked "corrected later".' })); }
		return kids;
	}
	/** Mark one section of a day read (moving its bookmark on), or undo it. */
	async function setRead(it, day, read) {
		var rd = readingOf(V.rule), key = entryKey(day, 'reading'), e = V.log.entries[key] || { first: now() }, late = daysAgo(day) > 7, k = it.sec.key, x = rd[k];
		e.read = e.read || {}; e.from = e.from || {}; e.to = e.to || {};
		if (read) {
			e.read[k] = { t: it.t, list: it.list };
			e.from[k] = { b: x.b, c: x.c };
			e.to[k] = { b: it.next.b, c: it.next.c };
			x.b = it.next.b; x.c = it.next.c;
		} else {
			// Put the bookmark back, unless it has moved on since.
			if (e.to[k] && e.from[k] && x.b === e.to[k].b && x.c === e.to[k].c) { x.b = e.from[k].b; x.c = e.from[k].c; }
			delete e.read[k]; delete e.from[k]; delete e.to[k];
		}
		e.kept = Object.keys(e.read).length > 0;
		e.edited = now(); if (late) { e.corrected = true; }
		V.log.entries[key] = e;
		status('Saving…');
		await saveVault();
		drawTabs(); drawSettings(); drawRecord();
		show();
	}

	function kathismaList(start, total) { var a = []; for (var i = 0; i < total; i++) { a.push(((start - 1 + i) % 20) + 1); } return a; }

	/** The service after this one in the member's rule: later the same day, or the first one of the next day. */
	function nextService(day, svcKey) {
		var list = V.key ? keptIn(V.rule) : C.services, i = list.map(function (x) { return x.key; }).indexOf(svcKey);
		if (!list.length) { return null; }
		if (i >= 0 && i + 1 < list.length) { return { service: list[i + 1], day: day, label: list[i + 1].name }; }
		var d = addDays(day, 1);
		return { service: list[0], day: d, label: list[0].name + ' (' + parseIso(d).toLocaleDateString(undefined, { weekday: 'long' }) + ')' };
	}
	function goTo(n) {
		UI.service = n.service.key; UI.day = n.day; dateInput.value = n.day;
		try { localStorage.setItem(LOCAL + '.service', n.service.key); } catch (x) {}
		drawTabs(); drawSettings(); show();
		tabs.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	function finishControls() {
		var s = shown, name = serviceByKey(s.service).name, next = ((s.start - 1 + s.total) % 20) + 1, after = nextService(s.day, s.service);
		if (!V.key) {
			var kids0 = [];
			if (s.total) {
				var b = el('button', { type: 'button', text: 'Finished: next time begin with kathisma ' + next });
				b.addEventListener('click', function () { L.next = next; saveSettings(); drawSettings(); b.replaceWith(el('p', { text: 'The next kathisma is now ' + next + '.' })); });
				kids0.push(b, ' ');
			}
			if (after) {
				kids0.push(el('button', { type: 'button', class: 'pr-primary', text: (s.total ? 'Finished: continue to ' : 'Continue to ') + after.label + ' ›', on: { click: function () { if (s.total && L.next === s.start) { L.next = next; saveSettings(); } goTo(after); } } }));
			}
			return kids0.length ? [el('p', {}, kids0)] : [];
		}
		var e = entry(s.day, s.service), late = daysAgo(s.day) > 7;
		if (e && e.kept) {
			var undo = el('button', { type: 'button', class: 'pr-link', text: 'Undo' });
			undo.addEventListener('click', function () { setKept(false); });
			var keptKids = [el('p', { class: 'pr-kept' }, ['✓ ' + name + ' kept' + (e.k && e.k.length ? ' (kathismas ' + e.k.join(', ') + ')' : '') + (e.ladders ? (e.k && e.k.length ? ' and ' : ' (') + half(e.ladders) + ' ladders' + (e.k && e.k.length ? '' : ')') : '') + (e.corrected ? ', corrected later' : '') + '. ', undo])];
			if (after) { keptKids.push(el('p', {}, [el('button', { type: 'button', class: 'pr-primary', text: 'Continue to ' + after.label + ' ›', on: { click: function () { goTo(after); } } })])); }
			return keptKids;
		}
		var mark = el('button', { type: 'button', class: 'pr-primary', text: 'Mark ' + name + ' as kept' });
		mark.addEventListener('click', function () { setKept(true); });
		var kids = [el('p', {}, after ? [mark, ' ', el('button', { type: 'button', text: 'Mark as kept and continue to ' + after.label + ' ›', on: { click: async function () { this.disabled = true; await setKept(true); goTo(after); } } })] : [mark])];
		if (s.total) { kids.push(el('p', { class: 'pr-note', text: 'Your next kathisma will then be ' + next + '.' })); }
		if (late) { kids.push(el('p', { class: 'pr-note', text: 'This day is more than 7 days ago: it will be marked "corrected later".' })); }
		return kids;
	}

	async function setKept(kept) {
		var s = shown, key = entryKey(s.day, s.service), e = V.log.entries[key] || { first: now() }, late = daysAgo(s.day) > 7;
		if (kept) {
			e.kept = true; e.start = s.start; e.k = kathismaList(s.start, s.total); e.ladders = s.ladders;
			if (s.total) { V.rule.next = ((s.start - 1 + s.total) % 20) + 1; }
		} else {
			e.kept = false;
			// Undo the bookmark only if nothing has moved it since.
			if (e.k && e.k.length && V.rule.next === ((e.start - 1 + e.k.length) % 20) + 1) { V.rule.next = e.start; }
		}
		e.edited = now(); if (late) { e.corrected = true; }
		V.log.entries[key] = e;
		status('Saving…');
		await saveVault();
		drawTabs(); drawSettings(); drawRecord();
		var done = out.querySelector('.pr-done'); if (done) { done.innerHTML = ''; finishControls().forEach(function (k) { done.appendChild(k); }); }
	}

	function redraw() { drawAccount(); drawTabs(); drawSettings(); drawRecord(); drawSharing(); drawFooter(); show(); }

	// Look for new messages every two minutes while the page is open and visible.
	setInterval(async function () {
		if (!V.key || !V.priv || document.hidden) { return; }
		await loadShares();
		drawAccount();
		if (!SH.open && !sharing.contains(document.activeElement)) { drawSharing(); }
	}, 120000);

	loadVault().then(redraw, function () { redraw(); status('Your private rule could not be loaded.', true); });
})();
