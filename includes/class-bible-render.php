<?php
/**
 * The Bible pages' HTML (see BiblePages): the translations, a translation's books, a book's
 * chapters, and a chapter alone or beside a second translation, with the commentary.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BibleRender {

	public static function view( array $v ) {
		switch ( $v['type'] ) {
			case 'index':
				return self::wrap( self::index() );
			case 'translation':
				return self::wrap( self::translation( $v['t'] ) );
			case 'book':
				return self::wrap( self::book( $v['t'], $v['book'] ) );
			case 'chapter':
				return self::wrap( self::chapter( $v['t'], $v['book'], $v['c'], $v['with'] ), $v['with'] ? 'is-two' : '' );
		}
		return self::wrap( '<p>' . esc_html__( 'There is no such page in the Bible here.', 'prayer-rule' ) . ' <a href="' . esc_url( BiblePages::url() ) . '">' . esc_html__( 'All the Bibles', 'prayer-rule' ) . '</a></p>' );
	}

	private static function wrap( $html, $class = '' ) {
		return '<div class="pr-bible' . ( $class ? ' ' . $class : '' ) . '">' . $html . '</div>';
	}

	private static function link( $url, $text, $class = '' ) {
		return '<a' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ' href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
	}

	/** Bible › KJV › Romans › 8 */
	private static function crumbs( array $items ) {
		$out = array( self::link( BiblePages::url(), __( 'Bible', 'prayer-rule' ) ) );
		foreach ( $items as $i => $item ) {
			$out[] = ( $i === count( $items ) - 1 ) ? '<span aria-current="page">' . esc_html( $item[1] ) . '</span>' : self::link( $item[0], $item[1] );
		}
		return '<nav class="prb-crumbs" aria-label="' . esc_attr__( 'Where you are', 'prayer-rule' ) . '">' . implode( ' <span class="prb-sep">›</span> ', $out ) . '</nav>';
	}

	/** One button of a row of choices: a link, the current one, or one not available. */
	private static function pill( $url, $text, $current = false, $title = '' ) {
		$t = $title ? ' title="' . esc_attr( $title ) . '"' : '';
		if ( $current ) {
			return '<span class="prb-pill is-current" aria-current="true">' . esc_html( $text ) . '</span>';
		}
		if ( '' === $url ) {
			return '<span class="prb-pill is-off"' . $t . '>' . esc_html( $text ) . '</span>';
		}
		return '<a class="prb-pill" href="' . esc_url( $url ) . '"' . $t . '>' . esc_html( $text ) . '</a>';
	}

	private static function row( $label, array $pills ) {
		return '<div class="prb-choice"><span class="prb-choice-label">' . esc_html( $label ) . '</span> ' . implode( ' ', $pills ) . '</div>';
	}

	private static function notice( array $ids ) {
		$html = '';
		foreach ( array_unique( $ids ) as $t ) {
			$n = Bible::notice( $t );
			if ( '' !== $n ) {
				$html .= '<p class="prb-notice">' . Render::text( $n ) . '</p>';
			}
		}
		return $html;
	}

	// ---------------------------------------------------------------- the translations

	private static function index() {
		$html = '<ul class="prb-translations">';
		foreach ( BibleMap::translations() as $t ) {
			$groups = array_count_values( wp_list_pluck( $t['books'], 'group' ) );
			$parts  = array();
			if ( ! empty( $groups['ot'] ) ) {
				$parts[] = __( 'Old Testament', 'prayer-rule' );
			}
			if ( ! empty( $groups['deutero'] ) ) {
				$parts[] = isset( BibleMap::DEUTERO_HEADING[ $t['id'] ] ) ? BibleMap::DEUTERO_HEADING[ $t['id'] ] : __( 'the deuterocanonical books', 'prayer-rule' );
			}
			if ( ! empty( $groups['gospel'] ) || ! empty( $groups['apostle'] ) ) {
				$parts[] = __( 'New Testament', 'prayer-rule' );
			}
			$html .= '<li><a class="prb-translation" href="' . esc_url( BiblePages::url( $t['id'] ) ) . '"><span class="prb-translation-name">' . esc_html( $t['name'] ) . '</span>'
				. '<span class="prb-translation-has">' . esc_html( implode( ' · ', $parts ) ) . ' · ' . esc_html( sprintf( /* translators: %d: number of books */ _n( '%d book', '%d books', count( $t['books'] ), 'prayer-rule' ), count( $t['books'] ) ) ) . '</span></a></li>';
		}
		$html .= '</ul>';
		if ( ! BibleMap::translations() ) {
			$html = '<p>' . esc_html__( 'The Bibles are not loaded yet: an administrator opens the admin once to load them.', 'prayer-rule' ) . '</p>';
		}
		return $html . self::notice( wp_list_pluck( BibleMap::translations(), 'id' ) );
	}

	// ---------------------------------------------------------------- a translation's books

	private static function translation( $t ) {
		$pills = array();
		foreach ( BibleMap::translations() as $other ) {
			$pills[] = self::pill( BiblePages::url( $other['id'] ), BibleMap::short( $other['id'] ), $other['id'] === $t, $other['name'] );
		}
		$sections = array(
			'ot'      => array( __( 'Old Testament', 'prayer-rule' ), array() ),
			'deutero' => array( BibleMap::DEUTERO_HEADING[ $t ] ?? '', array() ),
			'nt'      => array( __( 'New Testament', 'prayer-rule' ), array() ),
		);
		foreach ( BibleMap::translation( $t )['books'] as $b ) {
			$s = in_array( $b['group'], array( 'gospel', 'apostle' ), true ) ? 'nt' : ( ( 'deutero' === $b['group'] && isset( BibleMap::DEUTERO_HEADING[ $t ] ) ) ? 'deutero' : 'ot' );
			$chs                 = BibleMap::chapters( $t, $b );
			$one                 = 1 === count( $chs );   // a book of one chapter opens at its text
			$sections[ $s ][1][] = '<li>' . self::link( $one ? BiblePages::url( $t, $b['code'], $chs[0] ) : BiblePages::url( $t, $b['code'] ), $b['name'] ) . '</li>';
		}
		$html = self::crumbs( array( array( '', BibleMap::short( $t ) ) ) ) . self::row( __( 'Translation:', 'prayer-rule' ), $pills );
		foreach ( $sections as $s ) {
			if ( $s[1] ) {
				$html .= '<h2 class="prb-testament">' . esc_html( $s[0] ) . '</h2><ul class="prb-books">' . implode( '', $s[1] ) . '</ul>';
			}
		}
		return $html . self::notice( array( $t ) );
	}

	// ---------------------------------------------------------------- a book's chapters

	private static function book( $t, array $book ) {
		$pills = array();
		foreach ( BibleMap::translations() as $other ) {
			$o = $other['id'];
			if ( $o === $t ) {
				$pills[] = self::pill( '', BibleMap::short( $o ), true );
				continue;
			}
			$found   = BibleMap::find( $t, $book['code'], BibleMap::chapters( $t, $book )[0], $o );
			$url     = isset( $found['code'] ) ? BiblePages::url( $o, $found['code'] ) : '';
			$pills[] = self::pill( $url, BibleMap::short( $o ), false, $url ? $other['name'] : sprintf( /* translators: %s: translation */ __( 'Not in the %s', 'prayer-rule' ), $other['name'] ) );
		}
		$marks = Commentary::chapters_with( $t, $book );
		$html  = self::crumbs( array( array( BiblePages::url( $t ), BibleMap::short( $t ) ), array( '', $book['name'] ) ) ) . self::row( __( 'Translation:', 'prayer-rule' ), $pills );
		$html .= self::numbering_note( $t, '', $book['code'], 0 );
		$html .= '<ul class="prb-chapters' . ( 'PSA' === $book['code'] ? ' is-psalms' : '' ) . '">';
		foreach ( BibleMap::chapters( $t, $book ) as $n ) {
			$has   = isset( $marks[ (int) $n ] );
			$html .= '<li><a href="' . esc_url( BiblePages::url( $t, $book['code'], (int) $n ) ) . '"' . ( $has ? ' class="has-comm" title="' . esc_attr__( 'With commentary', 'prayer-rule' ) . '"' : '' ) . '>' . (int) $n . '</a></li>';
		}
		$html .= '</ul>';
		if ( $marks ) {
			$html .= '<p class="prb-key"><span class="prb-dot"></span> ' . esc_html__( 'with the Fathers\' commentary', 'prayer-rule' ) . '</p>';
		}
		$intros = Commentary::intros( $t, $book );
		if ( $intros ) {
			$items = array();
			foreach ( $intros as $info ) {
				foreach ( $info['works'] as $w ) {
					$items[] = '<li>' . self::link( $w['url'], $w['author'] ) . ', <span class="prb-work">' . esc_html( $w['title'] ) . '</span></li>';
				}
			}
			$html .= '<section class="prb-comm"><h2>' . esc_html( $intros[0]['label'] ) . '</h2><ul>' . implode( '', $items ) . '</ul></section>';
		}
		return $html . self::notice( array( $t ) );
	}

	// ---------------------------------------------------------------- a chapter

	/** A chapter's verses as paragraphs; $ids: give each an anchor (#v5); $links: Commentary::verse_links(). */
	private static function verses( array $chapter, $ids = true, array $links = array() ) {
		$html = '' !== $chapter['title'] ? '<p class="prb-title">' . esc_html( $chapter['title'] ) . '</p>' : '';
		foreach ( $chapter['verses'] as $i => $v ) {
			$html .= self::mark( $chapter, $i ) . self::verse( $v, $ids, $links );
		}
		return $html;
	}

	/** The line before a verse where a Greek psalm joins two Hebrew ones ("Psalm 10 in the Hebrew numbering"). */
	private static function mark( array $chapter, $i ) {
		return isset( $chapter['marks'][ $i ] ) ? '<p class="prb-mark">' . esc_html( $chapter['marks'][ $i ] ) . '</p>' : '';
	}

	private static function verse( array $v, $ids = true, array $links = array() ) {
		$id = $ids ? ' id="v' . esc_attr( $v[0] ) . '"' : '';
		return '<p class="prb-v"' . $id . '><span class="prb-vn">' . Commentary::verse_number( $v[0], $links ) . '</span> ' . esc_html( $v[1] ) . '</p>';
	}

	/** Verse labels in order: 2 < 2a < 2b < 3. */
	private static function verse_key( $label ) {
		preg_match( '/^(\d+)(.*)$/', (string) $label, $m );
		return $m ? sprintf( '%06d%s', (int) $m[1], $m[2] ) : (string) $label;
	}

	/** Two chapters verse beside verse: rows by verse label, "—" where one has none. */
	private static function rows( array $a, array $b, array $links_a = array(), array $links_b = array() ) {
		$by = array();
		foreach ( array( 0 => $a, 1 => $b ) as $side => $ch ) {
			foreach ( $ch['verses'] as $i => $v ) {
				$v[2]                                     = self::mark( $ch, $i );
				$by[ self::verse_key( $v[0] ) ][ $side ] = $v;
			}
		}
		ksort( $by, SORT_STRING );
		$html = '';
		if ( '' !== $a['title'] || '' !== $b['title'] ) {
			$html .= '<div class="prb-row"><div class="prb-cell">' . ( '' !== $a['title'] ? '<p class="prb-title">' . esc_html( $a['title'] ) . '</p>' : '' ) . '</div><div class="prb-cell">' . ( '' !== $b['title'] ? '<p class="prb-title">' . esc_html( $b['title'] ) . '</p>' : '' ) . '</div></div>';
		}
		foreach ( $by as $pair ) {
			$id    = isset( $pair[0] ) ? ' id="v' . esc_attr( $pair[0][0] ) . '"' : '';
			$html .= '<div class="prb-row"' . $id . '>';
			foreach ( array( 0, 1 ) as $side ) {
				$html .= '<div class="prb-cell">' . ( isset( $pair[ $side ] ) ? $pair[ $side ][2] . self::verse( $pair[ $side ], false, $side ? $links_b : $links_a ) : '<p class="prb-v is-none">—</p>' ) . '</div>';
			}
			$html .= '</div>';
		}
		return '<div class="prb-rows">' . $html . '</div>';
	}

	private static function chapter( $t, array $book, $c, $with ) {
		$code    = $book['code'];
		$text    = BibleMap::text( $t, $code, $c );
		$found_w = $with ? BibleMap::find( $t, $code, $c, $with ) : array();

		// Translation: the same place in each Bible (a second one beside it stays, or swaps).
		$pills = array();
		foreach ( BibleMap::translations() as $other ) {
			$o = $other['id'];
			if ( $o === $t ) {
				$pills[] = self::pill( '', BibleMap::short( $o ), true );
				continue;
			}
			$found   = BibleMap::find( $t, $code, $c, $o );
			$url     = BiblePages::found_url( $o, $found, $o === $with ? $t : $with );
			$title   = isset( $found['c'] ) ? $other['name'] : ( $url ? sprintf( /* translators: 1: e.g. Psalm 151, 2: translation */ __( 'No %1$s in the %2$s: its book', 'prayer-rule' ), BibleMap::label( $book, $c ), $other['name'] ) : sprintf( /* translators: %s: translation */ __( 'Not in the %s', 'prayer-rule' ), $other['name'] ) );
			$pills[] = self::pill( $url, BibleMap::short( $o ), false, $title );
		}
		$html = self::crumbs(
			array(
				array( BiblePages::url( $t ), BibleMap::short( $t ) ),
				array( BiblePages::url( $t, $code ), $book['name'] ),
				array( '', (string) $c ),
			)
		);
		$html .= '<div class="prb-tools">' . self::row( __( 'Translation:', 'prayer-rule' ), $pills );

		// Side by side with: none, or one of the others.
		$pills = array( self::pill( BiblePages::url( $t, $code, $c ), __( 'None', 'prayer-rule' ), '' === $with ) );
		foreach ( BibleMap::translations() as $other ) {
			$o = $other['id'];
			if ( $o === $t ) {
				continue;
			}
			$ok      = (bool) BibleMap::find( $t, $code, $c, $o );
			$pills[] = self::pill( $ok ? BiblePages::url( $t, $code, $c, $o ) : '', BibleMap::short( $o ), $o === $with, $ok ? '' : sprintf( /* translators: %s: translation */ __( 'Not in the %s', 'prayer-rule' ), $other['name'] ) );
		}
		$html .= self::row( __( 'Side by side:', 'prayer-rule' ), $pills ) . '</div>';

		// The heading's line: the other psalm numbering, and the commentary pop-up.
		$line = array();
		if ( 'PSA' === $code ) {
			$other = BibleMap::psalm_other( $t, $c );
			if ( '' !== $other ) {
				$line[] = '<span class="prb-other">(' . esc_html( $other ) . ')</span>';
			}
		}
		$pop = Commentary::links( BibleMap::places( $t, $code, $c ), array( Commentary::BOOKS[ $code ] ?? '', (int) $c ) );
		if ( '' !== $pop ) {
			$line[] = trim( $pop );
		}
		if ( $line ) {
			$html .= '<p class="prb-line">' . implode( ' ', $line ) . '</p>';
		}
		$html .= self::numbering_note( $t, $with, $code, $c );

		$notes = array();
		foreach ( array( array( $t, $code, $c ), $found_w && isset( $found_w['c'] ) ? array( $with, $found_w['code'], $found_w['c'] ) : null ) as $one ) {
			if ( $one && BibleMap::reordered( $one[0], $one[1], $one[2] ) ) {
				$notes[] = '<p class="prb-note">' . esc_html( sprintf( /* translators: %s: translation */ __( 'In the %s (the Septuagint) the chapters from here on are in another order than in the other Bibles, so the same number may not be the same text.', 'prayer-rule' ), BibleMap::short( $one[0] ) ) ) . '</p>';
			}
		}
		$html .= implode( '', array_unique( $notes ) );

		$places = BibleMap::places( $t, $code, $c );
		$links  = $text ? Commentary::verse_links( $t, $code, $c, $text['verses'], $places ) : array();
		if ( ! $text ) {
			$html .= '<p>' . esc_html__( 'This chapter is not loaded yet.', 'prayer-rule' ) . '</p>';
		} elseif ( ! $with ) {
			$html .= '<div class="prb-text">' . self::verses( $text, true, $links ) . '</div>';
		} else {
			$tr_w   = BibleMap::translation( $with );
			$book_w = isset( $found_w['code'] ) ? BibleMap::book( $with, $found_w['code'] ) : null;
			$text_w = isset( $found_w['c'] ) ? BibleMap::text( $with, $found_w['code'], $found_w['c'] ) : null;
			$head_a = self::link( BiblePages::url( $t, $code, $c ), BibleMap::short( $t ) . ' · ' . BibleMap::label( $book, $c ) );
			if ( $text_w ) {
				$head_b = self::link( BiblePages::url( $with, $found_w['code'], $found_w['c'] ), BibleMap::short( $with ) . ' · ' . BibleMap::label( $book_w, $found_w['c'] ) );
				$swap   = '<a class="prb-swap" href="' . esc_url( BiblePages::url( $with, $found_w['code'], $found_w['c'], $t ) ) . '" title="' . esc_attr__( 'Swap the two sides', 'prayer-rule' ) . '">⇄</a>';
				$places_w = BibleMap::places( $with, $found_w['code'], $found_w['c'] );
				$links_w  = Commentary::verse_links( $with, $found_w['code'], $found_w['c'], $text_w['verses'], $places_w );
				$places   = array_merge( $places, $places_w );
			} else {
				$head_b = esc_html( BibleMap::short( $with ) );
				$swap   = '';
			}
			$html .= '<div class="prb-heads"><div class="prb-head">' . $head_a . '</div><div class="prb-head">' . $head_b . ' ' . $swap . '</div></div>';
			if ( ! $text_w ) {
				$why   = $book_w
					/* translators: 1: e.g. Psalm 151, 2: translation, 3: link to the book */
					? sprintf( __( 'The %2$s has no %1$s. %3$s', 'prayer-rule' ), esc_html( BibleMap::label( $book, $c ) ), esc_html( $tr_w['name'] ), self::link( BiblePages::url( $with, $book_w['code'] ), $book_w['name'] ) )
					/* translators: 1: book, 2: translation */
					: sprintf( __( '%1$s is not in the %2$s.', 'prayer-rule' ), esc_html( $book['name'] ), esc_html( $tr_w['name'] ) );
				$html .= '<div class="prb-cols"><div class="prb-col">' . self::verses( $text, true, $links ) . '</div><div class="prb-col"><p class="prb-note">' . $why . '</p></div></div>';
			} elseif ( BibleMap::aligned( $t, $code, $c, $text['verses'], $with, $found_w['code'], $found_w['c'], $text_w['verses'] ) ) {
				$html .= self::rows( $text, $text_w, $links, $links_w );
			} else {
				$html .= '<p class="prb-note">' . esc_html__( 'The two number the verses differently here, so each runs on by itself.', 'prayer-rule' ) . '</p>';
				$html .= '<div class="prb-cols"><div class="prb-col">' . self::verses( $text, true, $links ) . '</div><div class="prb-col"><span class="prb-col-head">' . $head_b . '</span>' . self::verses( $text_w, false, $links_w ) . '</div></div>';
			}
		}

		// Before and after, in this translation's order.
		list( $prev, $next ) = BibleMap::neighbours( $t, $code, $c );
		$nav = array();
		foreach ( array( 'prev' => $prev, 'next' => $next ) as $dir => $to ) {
			if ( $to ) {
				$nav[] = '<a class="prb-' . $dir . '" rel="' . $dir . '" href="' . esc_url( BiblePages::url( $t, $to[0]['code'], $to[1], $with ) ) . '">' . ( 'prev' === $dir ? '← ' : '' ) . esc_html( BibleMap::label( $to[0], $to[1] ) ) . ( 'next' === $dir ? ' →' : '' ) . '</a>';
			} else {
				$nav[] = '<span></span>';
			}
		}
		$html .= '<nav class="prb-nav">' . implode( '', $nav ) . '</nav>';

		// The works with commentary on this chapter, each to its page.
		foreach ( Commentary::at( $places ) as $info ) {
			$items = array();
			foreach ( $info['works'] as $w ) {
				$items[] = '<li>' . self::link( $w['url'], $w['author'] ) . ', <span class="prb-work">' . esc_html( $w['title'] ) . '</span></li>';
			}
			$all   = count( $items ) > 1 ? '<p class="prb-all">' . self::link( $info['url'], __( 'All of them on one page', 'prayer-rule' ) ) . '</p>' : '';
			/* translators: %s: e.g. Psalm 50 */
			$html .= '<section class="prb-comm"><h2>' . esc_html( sprintf( __( 'Commentary on %s', 'prayer-rule' ), $info['label'] ) ) . '</h2><ul>' . implode( '', $items ) . '</ul>' . $all . '</section>';
		}
		return $html . self::notice( array_filter( array( $t, $with ) ) );
	}

	/**
	 * Under the Psalms of the KJV or Wycliffe (alone or beside another): how they are numbered
	 * here, and a link to the other numbering. $c: the psalm shown (0 on the book page).
	 */
	private static function numbering_note( $t, $with, $code, $c ) {
		if ( 'PSA' !== $code ) {
			return '';
		}
		$which = array_values( array_filter( array( $t, $with ), array( BibleMap::class, 'hebrew_psalter' ) ) );
		if ( ! $which ) {
			return '';
		}
		$names = implode( __( ' and ', 'prayer-rule' ), array_map( array( BibleMap::class, 'short' ), $which ) );

		// The same place in the other numbering, for the link.
		$here           = $c ? BibleMap::places( $t, $code, $c ) : array();
		$was            = BibleMap::$hebrew;
		BibleMap::$hebrew = ! $was;
		if ( ! $c ) {
			$url = BiblePages::url( $t, $code );
		} elseif ( BibleMap::hebrew_psalter( $t ) ) {
			$to  = BibleMap::locate( $t, $here );
			$url = $to ? BiblePages::url( $t, $to[0], $to[1], $with ) : BiblePages::url( $t, $code );
		} else {
			$url = BiblePages::url( $t, $code, $c, $with );
		}
		BibleMap::$hebrew = $was;

		$own = '';
		if ( $c && ! $was && BibleMap::hebrew_psalter( $t ) ) {
			$own = BibleMap::hebrew_label( $c, $t );
		} elseif ( $c && $was && BibleMap::hebrew_psalter( $t ) ) {
			$g   = Commentary::greek_psalms( $c );
			$own = 1 === count( $g )
				? sprintf( /* translators: %d: psalm number */ __( 'Psalm %d', 'prayer-rule' ), $g[0] )
				: sprintf( /* translators: 1, 2: psalm numbers */ __( 'Psalms %1$d and %2$d', 'prayer-rule' ), $g[0], $g[1] );
		}
		if ( ! $was ) {
			$text = $own
				/* translators: 1: KJV (and Wycliffe), 2: e.g. Psalm 51 */
				? sprintf( __( 'The Psalms of the %1$s are numbered here as in the Septuagint, as the Church reads them; in the %1$s itself this is %2$s.', 'prayer-rule' ), $names, $own )
				/* translators: %s: KJV (and Wycliffe) */
				: sprintf( __( 'The Psalms of the %s are numbered here as in the Septuagint, as the Church reads them.', 'prayer-rule' ), $names );
			/* translators: %s: KJV (and Wycliffe) */
			$link = sprintf( __( 'Show the %s\'s own (Hebrew) numbering', 'prayer-rule' ), $names );
		} else {
			$text = $own
				/* translators: 1: KJV (and Wycliffe), 2: e.g. Psalm 50 */
				? sprintf( __( 'The %1$s\'s own numbering, as in the Hebrew; in the Septuagint this is %2$s.', 'prayer-rule' ), $names, $own )
				/* translators: %s: KJV (and Wycliffe) */
				: sprintf( __( 'The %s\'s own numbering, as in the Hebrew.', 'prayer-rule' ), $names );
			$link = __( 'Number as in the Septuagint', 'prayer-rule' );
		}
		return '<p class="prb-numbering">' . esc_html( $text ) . ' ' . self::link( $url, $link ) . '</p>';
	}

	// ---------------------------------------------------------------- style

	public static function css() {
		return '
.pr-bible{--prb-red:#921a0a;--prb-line:rgba(127,127,127,.28);--prb-tint:rgba(146,26,10,.045)}
.pr-bible a{text-decoration:none}
.pr-bible a:hover{text-decoration:underline}
.pr-bible .prb-crumbs{font-size:.88em;opacity:.8;margin:0 0 1em}
.pr-bible .prb-sep{opacity:.5;margin:0 .2em}
.pr-bible .prb-tools{border:1px solid var(--prb-line);border-radius:8px;background:var(--prb-tint);padding:.6em .8em;margin:0 0 1.2em;display:flex;flex-direction:column;gap:.45em}
.pr-bible .prb-choice{display:flex;flex-wrap:wrap;align-items:center;gap:.35em}
.pr-bible > .prb-choice{margin:0 0 1.2em}
.pr-bible .prb-choice-label{font-size:.78em;letter-spacing:.07em;text-transform:uppercase;opacity:.65;margin-right:.3em;min-width:7.5em}
.pr-bible .prb-pill{display:inline-block;padding:.22em .8em;border:1px solid var(--prb-line);border-radius:999px;color:inherit;font-size:.92em;line-height:1.5}
.pr-bible a.prb-pill:hover{border-color:var(--prb-red);text-decoration:none}
.pr-bible .prb-pill.is-current{background:var(--prb-red);border-color:var(--prb-red);color:#fff}
.pr-bible .prb-pill.is-off{opacity:.4;cursor:default}
.pr-bible .prb-translations{list-style:none;margin:0 0 1.5em;padding:0;display:grid;gap:.7em}
.pr-bible .prb-translation{display:flex;flex-direction:column;gap:.2em;border:1px solid var(--prb-line);border-radius:8px;padding:.8em 1em;color:inherit}
.pr-bible .prb-translation:hover{border-color:var(--prb-red);text-decoration:none}
.pr-bible .prb-translation-name{font-weight:600;color:var(--prb-red)}
.pr-bible .prb-translation-has{font-size:.88em;opacity:.75}
.pr-bible .prb-testament{color:var(--prb-red);font-weight:normal;font-size:1.05em;letter-spacing:.08em;text-transform:uppercase;margin:1.6em 0 .6em;padding-bottom:.3em;border-bottom:1px solid var(--prb-line)}
.pr-bible .prb-books{list-style:none;margin:0;padding:0;columns:13em auto;column-gap:2em}
.pr-bible .prb-books li{margin:0 0 .3em;break-inside:avoid}
.pr-bible .prb-chapters{list-style:none;margin:0 0 1em;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(3em,1fr));gap:.4em}
.pr-bible .prb-chapters a{display:block;text-align:center;padding:.35em 0;border:1px solid var(--prb-line);border-radius:6px;color:inherit;position:relative}
.pr-bible .prb-chapters a:hover{border-color:var(--prb-red);text-decoration:none}
.pr-bible .prb-chapters a.has-comm::after,.pr-bible .prb-dot{content:"";display:inline-block;width:.38em;height:.38em;border-radius:50%;background:var(--prb-red)}
.pr-bible .prb-chapters a.has-comm::after{position:absolute;top:.3em;right:.3em}
.pr-bible .prb-key{font-size:.85em;opacity:.75;margin:.2em 0 1em}
.pr-bible .prb-line{margin:-.4em 0 1em;font-size:.95em}
.pr-bible .prb-other{opacity:.7;margin-right:.6em}
.pr-bible .pr-comm-links{white-space:nowrap}
.pr-bible .pr-comm{color:#8c8f94;font-style:italic;text-decoration:underline dotted;text-underline-offset:.2em;margin-right:.6em}
.pr-bible .pr-comm:hover{color:var(--prb-red)}
.pr-bible .prb-note{font-size:.9em;opacity:.75;font-style:italic}
.pr-bible .prb-title{font-style:italic;opacity:.8;margin:0 0 .6em}
.pr-bible .prb-mark{font-size:.82em;letter-spacing:.04em;opacity:.65;margin:1em 0 .4em;padding-top:.5em;border-top:1px dotted var(--prb-line)}
.pr-bible .prb-numbering{font-size:.88em;opacity:.85;margin:-.4em 0 1em;padding-left:.7em;border-left:3px solid rgba(146,26,10,.3)}
.pr-bible .prb-numbering a{white-space:nowrap;text-decoration:underline;text-underline-offset:.2em}
.pr-bible .prb-v{margin:0 0 .45em;line-height:1.65}
.pr-bible .prb-vn{color:var(--prb-red);font-size:.72em;font-weight:600;vertical-align:.25em;margin-right:.15em}
.pr-bible .prb-v.is-none{opacity:.35}
.pr-bible .prb-vn a.pr-vn-comm{color:inherit;background:rgba(146,26,10,.12);border-radius:3px;padding:.05em .3em;text-decoration:none}
.pr-bible .prb-vn a.pr-vn-comm:hover{background:var(--prb-red);color:#fff}
.pr-bible .prb-v:target,.pr-bible .prb-row:target{background:rgba(146,26,10,.08)}
.pr-bible .prb-heads,.pr-bible .prb-row,.pr-bible .prb-cols{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);column-gap:1.6em}
.pr-bible .prb-heads{border-bottom:1px solid var(--prb-line);margin:0 0 .8em;padding-bottom:.3em;font-weight:600}
.pr-bible .prb-head a{color:var(--prb-red)}
.pr-bible .prb-swap{margin-left:.4em;font-weight:normal;color:inherit;opacity:.7}
.pr-bible .prb-row{border-bottom:1px solid rgba(127,127,127,.12);padding:.15em 0}
.pr-bible .prb-row .prb-v{margin:.2em 0}
.pr-bible .prb-nav{display:flex;justify-content:space-between;gap:1em;margin:2em 0 1em;padding-top:.8em;border-top:1px solid var(--prb-line)}
.pr-bible .prb-comm{margin:1.6em 0;padding:.8em 1em;border:1px solid var(--prb-line);border-radius:8px}
.pr-bible .prb-comm h2{font-size:1em;font-weight:600;color:var(--prb-red);margin:0 0 .4em}
.pr-bible .prb-comm ul{margin:0;padding-left:1.2em}
.pr-bible .prb-comm li{margin:.2em 0}
.pr-bible .prb-comm a,.pr-bible .prb-note a{text-decoration:underline;text-underline-offset:.2em}
.pr-bible .prb-work{font-style:italic}
.pr-bible .prb-all{margin:.5em 0 0;font-size:.9em}
.pr-bible .prb-notice{font-size:.78em;opacity:.65;margin:1.6em 0 0;line-height:1.45}
@media (max-width:640px){
.pr-bible .prb-choice-label{min-width:0;flex-basis:100%}
.pr-bible .prb-heads{column-gap:.8em;font-size:.9em}
.pr-bible .prb-row{display:block;padding:.3em 0}
.pr-bible .prb-row .prb-cell+.prb-cell{border-left:3px solid rgba(146,26,10,.25);padding-left:.6em}
.pr-bible .prb-cols{display:block}
.pr-bible .prb-cols .prb-col+.prb-col{margin-top:1.5em;padding-top:1em;border-top:1px solid var(--prb-line)}
.pr-bible .prb-col-head{display:block}
}
.pr-bible .prb-col-head{display:none;font-weight:600;color:var(--prb-red);margin:0 0 .5em}';
	}
}
