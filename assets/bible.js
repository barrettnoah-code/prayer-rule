/* The Bible reader (Prayer Rule): turns the Bible page's links into in-place reading.
   Every link inside the reader that leads to another Bible address is fetched from the REST
   route bible/view and swapped in (toolbar and content), with the address and the browser's
   Back button kept right. The picker opens a book's chapter numbers in place, "Go to" reads a
   typed reference (bible/goto) and goes to the verse, ← and → turn the chapter, and /bible/
   reopens where the reader last was. Without this script every control is a plain link. */
( function () {
	'use strict';
	var cfg = window.prbBible;
	var root = document.querySelector( '.pr-bible[data-prb]' );
	if ( ! cfg || ! root || ! window.fetch || ! window.history.pushState ) return;
	var bar = root.querySelector( '.prb-bar' );
	var content = root.querySelector( '.prb-content' );
	var LAST = 'prbBibleLast';
	var busy = null;

	/* The toolbar's height, so a verse scrolled to is not hidden under it. */
	function barHeight() { root.style.setProperty( '--prb-bar-h', bar.offsetHeight + 'px' ); }
	barHeight();
	if ( window.ResizeObserver ) new ResizeObserver( barHeight ).observe( bar );
	else window.addEventListener( 'resize', barHeight );

	function store( key, val ) { try { if ( val === null ) localStorage.removeItem( key ); else localStorage.setItem( key, val ); } catch ( e ) {} }
	function stored( key ) { try { return localStorage.getItem( key ); } catch ( e ) { return null; } }

	function api( path ) {
		return fetch( cfg.rest + path, { credentials: 'same-origin', headers: { 'X-WP-Nonce': cfg.nonce } } )
			.then( function ( r ) { if ( ! r.ok ) throw new Error( r.status ); return r.json(); } );
	}

	/** Is this link a Bible address the reader can open in place? */
	function isBible( a ) {
		if ( ! a || ! a.href || a.target || a.hasAttribute( 'download' ) || a.closest( '[data-pcomm-book]' ) ) return false;
		var u = new URL( a.href, location.href );
		if ( u.origin !== location.origin ) return false;
		if ( u.pathname === location.pathname && u.search === location.search && u.hash ) return false;   // a verse on this page
		return u.pathname.indexOf( cfg.base ) === 0 || ( u.searchParams.has( 'prb_t' ) );
	}

	/* ── verses ───────────────────────────────────────────────────────────── */

	function highlight( v1, v2 ) {
		root.querySelectorAll( '.is-hl' ).forEach( function ( el ) { el.classList.remove( 'is-hl' ); } );
		if ( ! v1 ) return null;
		var first = null;
		for ( var v = v1; v <= ( v2 || v1 ); v++ ) {
			var el = content.querySelector( '[id="v' + v + '"]' );
			if ( el ) { el.classList.add( 'is-hl' ); first = first || el; }
		}
		return first;
	}

	function verseFromHash( hash ) {
		var m = /^#v(\d+)(?:-(\d+))?$/.exec( hash || '' );
		return m ? [ parseInt( m[ 1 ], 10 ), m[ 2 ] ? parseInt( m[ 2 ], 10 ) : 0 ] : null;
	}

	function toTop() {
		var top = root.getBoundingClientRect().top + window.pageYOffset - ( parseInt( getComputedStyle( document.documentElement ).getPropertyValue( '--wp-admin--admin-bar--height' ), 10 ) || 0 ) - 8;
		if ( window.pageYOffset > top ) window.scrollTo( { top: top } );
	}

	/* ── loading a page in place ──────────────────────────────────────────── */

	function load( url, opts ) {
		opts = opts || {};
		var u = new URL( url, location.href );
		var verse = opts.verse || verseFromHash( u.hash );
		closeMenus();
		root.setAttribute( 'aria-busy', 'true' );
		root.classList.add( 'is-loading' );
		var mine = busy = {};
		return api( 'view?path=' + encodeURIComponent( u.pathname + u.search ) ).then( function ( d ) {
			if ( mine !== busy ) return;                                  // a later click won
			if ( d.locked ) { location.href = u.href; return; }
			bar.innerHTML = d.toolbar;
			content.innerHTML = d.content;
			root.classList.toggle( 'is-two', !! d.two );
			if ( d.landing ) root.setAttribute( 'data-prb-landing', '' ); else root.removeAttribute( 'data-prb-landing' );
			document.title = d.doc_title;
			var addr = u.origin + u.pathname + u.search;                   // as asked: keeps ?with= and ?numbering=
			var shown = addr + ( verse ? '#v' + verse[ 0 ] + ( verse[ 1 ] ? '-' + verse[ 1 ] : '' ) : '' );
			if ( opts.replace ) history.replaceState( { prb: 1 }, '', shown );
			else if ( opts.push !== false ) history.pushState( { prb: 1 }, '', shown );
			if ( d.type === 'chapter' && ! d.landing ) store( LAST, addr );
			var el = verse && highlight( verse[ 0 ], verse[ 1 ] );
			if ( el ) el.scrollIntoView( { block: 'start' } );
			else if ( opts.scroll !== false ) toTop();
			if ( opts.focus ) {
				var h = content.querySelector( '.prb-heading' );
				if ( h ) { h.setAttribute( 'tabindex', '-1' ); h.focus( { preventScroll: true } ); }
			}
		} ).catch( function () {
			if ( mine === busy ) location.href = u.href;                 // let the server show it
		} ).then( function () {
			if ( mine === busy ) { root.removeAttribute( 'aria-busy' ); root.classList.remove( 'is-loading' ); }
		} );
	}

	/* ── menus (picker, translation, compare) ─────────────────────────────── */

	function closeMenus( except ) {
		root.querySelectorAll( 'details.prb-menu[open]' ).forEach( function ( d ) { if ( d !== except ) d.open = false; } );
	}

	root.addEventListener( 'toggle', function ( e ) {
		var d = e.target;
		if ( ! d.classList || ! d.classList.contains( 'prb-menu' ) || ! d.open ) return;
		closeMenus( d );
		if ( d.classList.contains( 'prb-picker' ) ) {
			showBooks( d );
			var cur = d.querySelector( '.prb-pick-book.is-current' );
			var panel = d.querySelector( '.prb-panel' );
			if ( cur && panel ) panel.scrollTop = Math.max( 0, cur.offsetTop - panel.clientHeight / 2 );   // the panel only, not the page
		}
	}, true );

	document.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest( '.prb-menu' ) ) closeMenus();
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			var open = root.querySelector( 'details.prb-menu[open]' );
			if ( open ) { open.open = false; open.querySelector( 'summary' ).focus(); }
		}
	} );

	function showBooks( picker ) {
		picker.querySelector( '.prb-pick-books' ).hidden = false;
		picker.querySelector( '.prb-pick-chapters' ).hidden = true;
	}

	/* The picker: a book opens its chapter numbers in place (a one-chapter book opens at once). */
	function showChapters( a, byKeyboard ) {
		var picker = a.closest( '.prb-picker' );
		var list = a.dataset.list ? a.dataset.list.split( ',' ).map( Number ) : [];
		if ( ! list.length ) {
			for ( var i = 0, f = parseInt( a.dataset.first, 10 ); i < parseInt( a.dataset.n, 10 ); i++ ) list.push( f + i );
		}
		var url = function ( n ) { return a.dataset.pattern.replace( '99999', n ); };
		if ( list.length === 1 ) { load( url( list[ 0 ] ), { focus: true } ); return; }
		var box = picker.querySelector( '.prb-pick-chapters' );
		var cur = ( root.querySelector( '.prb-here' ) || {} ).textContent || '';
		box.innerHTML = '';
		var head = document.createElement( 'div' );
		head.className = 'prb-pick-chead';
		var back = document.createElement( 'button' );
		back.type = 'button';
		back.className = 'prb-pick-back';
		back.textContent = '‹ ' + ( picker.dataset.booksLabel || 'Books' );
		back.addEventListener( 'click', function () { showBooks( picker ); } );
		var name = document.createElement( 'a' );
		name.className = 'prb-pick-name';
		name.href = a.href;
		name.textContent = a.dataset.name;
		head.appendChild( back );
		head.appendChild( name );
		box.appendChild( head );
		var ul = document.createElement( 'ul' );
		ul.className = 'prb-pick-nums';
		list.forEach( function ( n ) {
			var li = document.createElement( 'li' );
			var link = document.createElement( 'a' );
			link.href = url( n );
			link.textContent = n;
			if ( a.classList.contains( 'is-current' ) && cur === cur.replace( /\d+$/, '' ) + n ) link.className = 'is-current';
			li.appendChild( link );
			ul.appendChild( li );
		} );
		box.appendChild( ul );
		picker.querySelector( '.prb-pick-books' ).hidden = true;
		box.hidden = false;
		box.closest( '.prb-panel' ).scrollTop = 0;
		var first = box.querySelector( '.is-current' ) || box.querySelector( '.prb-pick-nums a' );
		if ( first && byKeyboard ) first.focus( { preventScroll: true } );
	}

	/* ── clicks ───────────────────────────────────────────────────────────── */

	root.addEventListener( 'click', function ( e ) {
		if ( e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey ) return;
		var a = e.target.closest( 'a' );
		if ( ! a || ! root.contains( a ) ) return;
		if ( a.classList.contains( 'prb-pick-book' ) ) { e.preventDefault(); showChapters( a, e.detail === 0 ); return; }
		if ( ! isBible( a ) ) return;
		e.preventDefault();
		load( a.href, { focus: !! a.closest( '.prb-menu' ) } );
	} );

	/* ← and → turn the chapter (not while typing or with a menu open). */
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey || e.shiftKey ) return;
		if ( e.key !== 'ArrowLeft' && e.key !== 'ArrowRight' ) return;
		var t = e.target;
		if ( t && ( t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( t.tagName ) ) ) return;
		if ( root.querySelector( 'details.prb-menu[open]' ) || document.querySelector( 'dialog[open]' ) ) return;
		var a = bar.querySelector( e.key === 'ArrowLeft' ? '[data-prb-prev]' : '[data-prb-next]' );
		if ( a ) { e.preventDefault(); load( a.href ); }
	} );

	window.addEventListener( 'popstate', function () {
		load( location.href, { push: false, scroll: ! verseFromHash( location.hash ) } );
	} );

	/* ── Go to ────────────────────────────────────────────────────────────── */

	var hintTimer = null;
	var asked = 0;

	function goParams( form, q ) {
		var p = new URLSearchParams();
		p.set( 'q', q );
		[ 't', 'at', 'with', 'numbering' ].forEach( function ( k ) {
			var f = form.querySelector( '[name="' + k + '"]' );
			if ( f && f.value ) p.set( k, f.value );
		} );
		return p.toString();
	}

	function hint( form, text, kind, other ) {
		var p = form.querySelector( '.prb-go-hint' );
		p.className = 'prb-go-hint' + ( kind ? ' is-' + kind : '' );
		p.textContent = text || '';
		if ( other ) {
			var a = document.createElement( 'a' );
			a.href = other;
			a.textContent = ' ' + ( cfg.openThere || 'Open it there' ) + ' ›';
			p.appendChild( a );
		}
	}

	root.addEventListener( 'input', function ( e ) {
		if ( e.target.name !== 'go' ) return;
		var form = e.target.form;
		var q = e.target.value.trim();
		clearTimeout( hintTimer );
		if ( q.length < 2 ) { hint( form, '' ); return; }
		hintTimer = setTimeout( function () {
			var n = ++asked;
			api( 'goto?' + goParams( form, q ) ).then( function ( r ) {
				if ( n !== asked ) return;
				if ( r.ok ) hint( form, '↵ ' + r.label + ( r.note ? ' — ' + r.note : '' ), 'ok' );
				else hint( form, r.error, 'error', r.other );
			} ).catch( function () {} );
		}, 250 );
	} );

	root.addEventListener( 'submit', function ( e ) {
		var form = e.target;
		if ( ! form.classList.contains( 'prb-goto' ) ) return;
		e.preventDefault();
		var input = form.querySelector( '[name="go"]' );
		var q = input.value.trim();
		if ( ! q ) { input.focus(); return; }
		clearTimeout( hintTimer );
		api( 'goto?' + goParams( form, q ) ).then( function ( r ) {
			if ( ! r.ok ) { hint( form, r.error, 'error', r.other ); input.focus(); return; }
			input.value = '';
			load( r.url, { verse: r.v1 ? [ r.v1, r.v2 ] : null } );
		} ).catch( function () { form.submit(); } );
	} );

	/* "/" puts the cursor in Go to, as on many reading sites. */
	document.addEventListener( 'keydown', function ( e ) {
		if ( e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey ) return;
		var t = e.target;
		if ( t && ( t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test( t.tagName ) ) ) return;
		var input = bar.querySelector( '[name="go"]' );
		if ( input ) { e.preventDefault(); input.focus(); }
	} );

	/* ── on arrival ───────────────────────────────────────────────────────── */

	history.replaceState( { prb: 1 }, '', location.href );
	var v = verseFromHash( location.hash );
	if ( v ) {
		var el = highlight( v[ 0 ], v[ 1 ] );
		if ( el ) setTimeout( function () { el.scrollIntoView( { block: 'start' } ); }, 0 );
	}
	var last = stored( LAST );
	if ( root.hasAttribute( 'data-prb-landing' ) && ! location.search && ! location.hash && last && last.indexOf( cfg.home ) === 0 ) {
		load( last, { replace: true, scroll: false } );          // /bible/ reopens where the reader was
	} else if ( ! root.hasAttribute( 'data-prb-landing' ) && content.querySelector( '.prb-text, .prb-rows, .prb-cols' ) ) {
		store( LAST, location.origin + location.pathname + location.search );
	}
} )();
