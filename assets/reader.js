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

	var V = { exists: false, version: 0, envelope: null, key: null, raw: null, rule: null, log: null };

	async function api(method, body) {
		var r = await fetch(C.vault, { method: method, credentials: 'same-origin', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce }, body: body ? JSON.stringify(body) : undefined });
		var data = await r.json().catch(function () { return {}; });
		return { status: r.status, data: data };
	}
	async function loadVault() {
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
	}
	async function openWith(raw) {
		V.raw = raw; V.key = await dataKey(raw);
		await readEnvelope(V.envelope);
		try { sessionStorage.setItem(SESSION, b64(raw)); } catch (e) {}
	}
	async function unwrap(secret, which) {
		var env = V.envelope, k = await derive(secret, unb64(env['salt_' + which]), env.iter);
		return unseal(k, env['key_' + which], 'key_' + which);   // throws when the secret is wrong
	}
	async function wrap(secret, which, env) {
		var salt = rand(16), k = await derive(secret, salt, ITER);
		env['salt_' + which] = b64(salt); env['key_' + which] = await seal(k, V.raw, 'key_' + which); env.iter = ITER;
	}
	function lock() { V.key = null; V.raw = null; V.rule = null; V.log = null; try { sessionStorage.removeItem(SESSION); } catch (e) {} }

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
			if (r.status === 200) { V.version = r.data.version; V.envelope = env; status(''); return true; }
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
	if (!UI.service || !serviceByKey(UI.service)) { UI.service = C.services.length ? C.services[0].key : ''; }
	if (!C.translations[L.translation]) { L.translation = C.translation; }

	// ------------------------------------------------------------ the page

	var dateInput = el('input', { type: 'date', value: UI.day });
	dateInput.addEventListener('change', function () { if (dateInput.value) { UI.day = dateInput.value; drawTabs(); show(); } });
	var bar = el('div', { class: 'pr-controls' }, [el('label', {}, ['Day ', dateInput]), el('button', { type: 'button', text: 'Today', on: { click: function () { UI.day = defaultDay(); dateInput.value = UI.day; drawTabs(); show(); } } })]);
	var account = el('div', { class: 'pr-account' });
	var tabs = el('div', { class: 'pr-tabs', role: 'group' });
	var settings = el('details', { class: 'pr-settings' });
	var record = el('details', { class: 'pr-record' });
	var note = el('p', { class: 'pr-status', role: 'status' });
	var out = el('div', { class: 'pr-out', 'aria-live': 'polite' });
	[account, bar, tabs, settings, record, note, out].forEach(function (x) { root.appendChild(x); });

	function status(text, error) { note.textContent = text; note.className = 'pr-status' + (error ? ' is-error' : ''); }
	function entryKey(day, svc) { return day + '|' + svc; }
	function entry(day, svc) { return V.key && V.log && V.log.entries[entryKey(day, svc)]; }

	// ---- account: set up / unlock / locked

	function field(label, attrs) { var i = el('input', Object.assign({ type: 'password', autocomplete: 'off' }, attrs || {})); return [el('label', { class: 'pr-field' }, [label, i]), i]; }

	function drawAccount() {
		account.innerHTML = '';
		if (V.key) {
			account.appendChild(el('p', { class: 'pr-lockline' }, ['Your private rule is open. ', el('button', { type: 'button', class: 'pr-link', text: 'Lock', on: { click: function () { lock(); redraw(); } } })]));
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
	}

	// ---- my rule (or this browser's settings)

	function drawSettings() {
		var open = settings.open, r = R();
		settings.innerHTML = '';
		settings.appendChild(el('summary', { text: V.key ? 'My rule' : 'My portion and settings' }));
		var t = el('table');
		C.services.forEach(function (s) {
			var svc = r.services[s.key] || (r.services[s.key] = { keep: true, portions: {} });
			var head = [el('strong', { text: s.name })];
			if (V.key) {
				var cb = el('input', { type: 'checkbox' }); cb.checked = svc.keep !== false;
				cb.addEventListener('change', function () { svc.keep = cb.checked; saveSettings(); drawTabs(); drawRecord(); });
				head = [el('label', {}, [cb, ' ', el('strong', { text: s.name })])];
			}
			t.appendChild(el('tr', {}, [el('th', { colspan: '3' }, head)]));
			s.portions.forEach(function (p) {
				var v = portionOf(s.key, p.key);
				var k = [[0, 'none']].concat(p.kathismas.map(function (n) { return [n, n]; }));
				var l = [[0, 'none']].concat(p.ladders.map(function (n) { return [n, half(n)]; }));
				t.appendChild(el('tr', {}, [
					el('td', { text: s.portions.length > 1 ? p.name : '' }),
					el('td', {}, ['kathismas ', select(k, v.kathismas, function (x) { v.kathismas = +x; saveSettings(); if (s.key === UI.service) { show(); } })]),
					el('td', {}, ['ladders ', select(l, v.ladders, function (x) { v.ladders = +x; saveSettings(); if (s.key === UI.service) { show(); } })])
				]));
			});
		});
		settings.appendChild(t);
		var nums = []; for (var i = 1; i <= 20; i++) { nums.push([i, i]); }
		var tr = Object.keys(C.translations).map(function (k) { return [k, C.translations[k]]; });
		var vb = el('input', { type: 'checkbox' }); vb.checked = !!r.verses;
		vb.addEventListener('change', function () { r.verses = vb.checked; saveSettings(); show(); });
		settings.appendChild(el('p', {}, [
			el('label', {}, ['Next kathisma ', select(nums, r.next, function (x) { r.next = +x; saveSettings(); show(); })]), '   ',
			el('label', {}, ['Psalter ', select(tr, r.translation, function (x) { r.translation = x; saveSettings(); show(); })]), '   ',
			el('label', {}, [vb, ' verse numbers'])
		]));
		if (V.key) { settings.appendChild(drawKeys()); }
		else { settings.appendChild(el('p', { class: 'pr-note', text: 'These settings are kept only in this browser.' })); }
		settings.open = open;
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
		box.appendChild(el('h4', { text: 'Passphrase and recovery code' }));
		box.appendChild(el('p', {}, [p1[0], ' ', p2[0], ' ', change]));
		box.appendChild(el('p', {}, [newCode]));
		box.appendChild(el('p', {}, [erase, ' ', eraseBtn]));
		box.appendChild(msg);
		return box;
	}

	// ---- the record: reports by week or month, worked out here from the decrypted record

	UI.report = { mode: 'week', anchor: defaultDay() };

	function weekStart(day) { return addDays(day, -parseIso(day).getDay()); }              // weeks run Sunday to Saturday
	function reportRange() {
		var a = UI.report.anchor, start, end;
		if (UI.report.mode === 'week') { start = weekStart(a); end = addDays(start, 6); }
		else { var d = parseIso(a); start = iso(new Date(d.getFullYear(), d.getMonth(), 1)); end = iso(new Date(d.getFullYear(), d.getMonth() + 1, 0)); }
		return { start: start, end: end };
	}
	function keptServices() { return C.services.filter(function (s) { return !V.rule.services[s.key] || V.rule.services[s.key].keep !== false; }); }

	/** Counts for the days from start to end (days after today are left out). */
	function stats(start, end) {
		var today = defaultDay(), keep = keptServices(), out = { days: 0, full: 0, kathismas: 0, ladders: 0, corrected: 0, per: {}, byDay: {} };
		C.services.forEach(function (s) { out.per[s.key] = 0; });
		for (var day = start; day <= end && day <= today; day = addDays(day, 1)) {
			var n = 0;
			out.days++;
			C.services.forEach(function (s) {
				var e = entry(day, s.key);
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

	function rangeLabel(r) {
		var a = parseIso(r.start), b = parseIso(r.end);
		if (UI.report.mode === 'month') { return a.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }); }
		return a.toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ' – ' + b.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
	}

	function drawRecord() {
		var open = record.open;
		record.innerHTML = '';
		if (!V.key) { record.style.display = 'none'; return; }
		record.style.display = '';
		record.appendChild(el('summary', { text: 'My record' }));
		var r = reportRange(), st = stats(r.start, r.end), keep = keptServices();

		function go(step) {
			var d = parseIso(UI.report.anchor);
			if (UI.report.mode === 'week') { d.setDate(d.getDate() + 7 * step); } else { d = new Date(d.getFullYear(), d.getMonth() + step, 1, 12); }
			UI.report.anchor = iso(d); drawRecord();
		}
		function mode(m) { var b = el('button', { type: 'button', 'aria-pressed': UI.report.mode === m ? 'true' : 'false', text: m === 'week' ? 'Week' : 'Month' }); b.addEventListener('click', function () { UI.report.mode = m; drawRecord(); }); return b; }
		var later = r.end >= defaultDay();
		record.appendChild(el('div', { class: 'pr-report-bar' }, [
			el('span', { class: 'pr-tabs' }, [mode('week'), mode('month')]),
			el('button', { type: 'button', text: '‹', title: 'Earlier', on: { click: function () { go(-1); } } }),
			el('strong', { text: ' ' + rangeLabel(r) + ' ' }),
			later ? null : el('button', { type: 'button', text: '›', title: 'Later', on: { click: function () { go(1); } } })
		]));

		// Per service: kept on how many of the days.
		var t = el('table', { class: 'pr-grid pr-summary' });
		t.appendChild(el('tr', {}, [el('th', { text: '' }), el('th', { text: 'Kept' }), el('th', { text: '' })]));
		keep.forEach(function (s) {
			var n = st.per[s.key], pct = st.days ? Math.round(100 * n / st.days) : 0;
			t.appendChild(el('tr', {}, [el('th', { text: s.name }), el('td', { text: n + ' of ' + st.days }), el('td', {}, [el('span', { class: 'pr-bar', style: 'width:' + (pct * 0.08).toFixed(2) + 'em' }), ' ' + pct + '%'])]));
		});
		record.appendChild(t);

		var lines = [];
		lines.push(st.full + ' of ' + st.days + ' days with every service of your rule kept.');
		if (st.kathismas) { lines.push(st.kathismas + ' kathismas read (' + (Math.round(st.kathismas / 2) / 10).toString().replace('.', '.') + ' times the whole Psalter).'); }
		if (st.ladders) { lines.push(half(st.ladders) + ' ladders of the Jesus Prayer (' + Math.round(st.ladders * 100).toLocaleString() + ' prayers).'); }
		if (st.corrected) { lines.push(st.corrected + ' marked "corrected later".'); }
		record.appendChild(el('ul', { class: 'pr-report-lines' }, lines.map(function (x) { return el('li', { text: x }); })));

		record.appendChild(UI.report.mode === 'week' ? weekGrid(r, keep) : monthGrid(r, st, keep));
		record.appendChild(el('p', { class: 'pr-note', text: '✓* marks a service checked or unchecked more than 7 days after its day. Days are church days: Vespers and Compline count for the next day.' }));
		record.appendChild(el('p', {}, [
			el('button', { type: 'button', text: 'Print this report', on: { click: function () { record.open = true; window.print(); } } }), ' ',
			el('button', { type: 'button', text: 'Download my whole record (CSV)', on: { click: downloadCsv } })
		]));
		record.open = open;
	}

	function weekGrid(r, keep) {
		var t = el('table', { class: 'pr-grid' }), today = defaultDay();
		t.appendChild(el('tr', {}, [el('th', { text: '' })].concat(keep.map(function (s) { return el('th', { text: s.name }); }))));
		for (var day = r.start; day <= r.end; day = addDays(day, 1)) {
			var row = [el('th', { text: parseIso(day).toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' }) })];
			keep.forEach(function (s) {
				var e = entry(day, s.key);
				row.push(el('td', { text: day > today ? '' : (e && e.kept ? (e.corrected ? '✓*' : '✓') : '·') }));
			});
			t.appendChild(el('tr', { class: day === today ? 'is-today' : '' }, row));
		}
		return t;
	}

	function monthGrid(r, st, keep) {
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
				future ? null : el('span', { class: 'pr-month-n', text: n + '/' + m })
			]);
			if (!future && n) { cell.style.background = 'rgba(146,26,10,' + (0.12 + 0.6 * Math.min(1, n / m)).toFixed(2) + ')'; if (n / m > 0.6) { cell.style.color = '#fff'; } }
			cell.addEventListener('click', (function (d) { return function () { UI.day = d; dateInput.value = d; drawTabs(); show(); }; })(day));
			row.appendChild(cell);
		}
		t.appendChild(row);
		return t;
	}

	function downloadCsv() {
		var rows = [['date', 'service', 'kept', 'kathismas', 'ladders', 'corrected later', 'first marked', 'last changed']];
		Object.keys(V.log.entries).sort().forEach(function (k) {
			var e = V.log.entries[k], a = k.split('|'), s = serviceByKey(a[1]);
			rows.push([a[0], s ? s.name : a[1], e.kept ? 'yes' : 'no', (e.k || []).join(' '), e.ladders || 0, e.corrected ? 'yes' : '', e.first || '', e.edited || '']);
		});
		var csv = rows.map(function (r) { return r.map(function (x) { x = String(x); return /[",\n]/.test(x) ? '"' + x.replace(/"/g, '""') + '"' : x; }).join(','); }).join('\n');
		var url = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
		var a = el('a', { href: url, download: 'prayer-rule-record-' + defaultDay() + '.csv' });
		document.body.appendChild(a); a.click(); a.remove();
		setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
	}

	// ---- loading and showing a service

	var req = 0, shown = null;
	function show() {
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
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.nonce },
			body: JSON.stringify({ date: UI.day, translation: r.translation, verses: r.verses ? 1 : 0, p: p })
		}).then(function (res) { if (!res.ok) { throw res; } return res.json(); }).then(function (data) {
			if (mine !== req) { return; }
			out.innerHTML = data.day_html + data.html;
			shown = { day: UI.day, service: svc.key, start: start, total: total, ladders: ladders };
			out.appendChild(el('div', { class: 'pr-done' }, finishControls()));
		}).catch(function () { if (mine === req) { out.innerHTML = ''; out.appendChild(el('p', { text: 'The service could not be loaded. Please try again.' })); } });
	}

	function kathismaList(start, total) { var a = []; for (var i = 0; i < total; i++) { a.push(((start - 1 + i) % 20) + 1); } return a; }

	function finishControls() {
		var s = shown, name = serviceByKey(s.service).name, next = ((s.start - 1 + s.total) % 20) + 1;
		if (!V.key) {
			if (!s.total) { return []; }
			var b = el('button', { type: 'button', text: 'Finished: next time begin with kathisma ' + next });
			b.addEventListener('click', function () { L.next = next; saveSettings(); drawSettings(); b.replaceWith(el('p', { text: 'The next kathisma is now ' + next + '.' })); });
			return [b];
		}
		var e = entry(s.day, s.service), late = daysAgo(s.day) > 7;
		if (e && e.kept) {
			var undo = el('button', { type: 'button', class: 'pr-link', text: 'Undo' });
			undo.addEventListener('click', function () { setKept(false); });
			return [el('p', { class: 'pr-kept' }, ['✓ ' + name + ' kept' + (e.k && e.k.length ? ' (kathismas ' + e.k.join(', ') + ')' : '') + (e.ladders ? (e.k && e.k.length ? ' and ' : ' (') + half(e.ladders) + ' ladders' + (e.k && e.k.length ? '' : ')') : '') + (e.corrected ? ', corrected later' : '') + '. ', undo])];
		}
		var mark = el('button', { type: 'button', class: 'pr-primary', text: 'Mark ' + name + ' as kept' });
		mark.addEventListener('click', function () { setKept(true); });
		var kids = [mark];
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

	function redraw() { drawAccount(); drawTabs(); drawSettings(); drawRecord(); show(); }

	loadVault().then(redraw, function () { redraw(); status('Your private rule could not be loaded.', true); });
})();
