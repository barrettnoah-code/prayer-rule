<?php
/**
 * The Bible reader's HTML (see BiblePages). The page is a reader: a toolbar that stays at the
 * top (previous/next, the book-and-chapter picker, "Go to", translation, compare) over the
 * content (a chapter alone or beside a second translation, a book's chapters, or a
 * translation's books). assets/bible.js swaps both in place through the REST route
 * bible/view, so reading on does not reload the page; without it every control is a plain link.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BibleRender {

	/** The whole reader for a view of BiblePages::view_for(). */
	public static function view( array $v ) {
		return '<div class="pr-bible' . ( ! empty( $v['with'] ) ? ' is-two' : '' ) . '" data-prb' . ( ! empty( $v['landing'] ) ? ' data-prb-landing' : '' ) . '>'
			. '<div class="prb-bar">' . self::toolbar( $v ) . '</div>'
			. '<div class="prb-content">' . self::content( $v ) . '</div></div>';
	}

	/** What is under the toolbar. */
	public static function content( array $v ) {
		switch ( $v['type'] ) {
			case 'translation':
				return self::translation( $v['t'] );
			case 'book':
				return self::book( $v['t'], $v['book'] );
			case 'chapter':
				return self::chapter( $v['t'], $v['book'], $v['c'], $v['with'] );
		}
		if ( ! BibleMap::translations() ) {
			return '<p>' . esc_html__( 'The Bibles are not loaded yet: an administrator opens the admin once to load them.', 'prayer-rule' ) . '</p>';
		}
		return '<p class="prb-missing">' . esc_html__( 'There is no such page in the Bible here.', 'prayer-rule' ) . ' ' . self::link( BiblePages::url(), __( 'Open the Bible', 'prayer-rule' ) ) . '</p>';
	}

	private static function link( $url, $text, $class = '' ) {
		return '<a' . ( $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ' href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
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

	/** A translation's books by section (the KJV's Apocrypha apart): section => array( heading, books ). */
	private static function sections( $t ) {
		$sections = array(
			'ot'      => array( __( 'Old Testament', 'prayer-rule' ), array() ),
			'deutero' => array( BibleMap::DEUTERO_HEADING[ $t ] ?? '', array() ),
			'nt'      => array( __( 'New Testament', 'prayer-rule' ), array() ),
		);
		foreach ( BibleMap::translation( $t )['books'] as $b ) {
			$s                     = in_array( $b['group'], array( 'gospel', 'apostle' ), true ) ? 'nt' : ( ( 'deutero' === $b['group'] && isset( BibleMap::DEUTERO_HEADING[ $t ] ) ) ? 'deutero' : 'ot' );
			$sections[ $s ][1][] = $b;
		}
		return array_filter(
			$sections,
			function ( $s ) {
				return (bool) $s[1];
			}
		);
	}

	// ---------------------------------------------------------------- the toolbar

	/** One of the toolbar's drop-downs: a <details> that works as a menu even without the script. */
	private static function menu( $class, $summary, $label, $body ) {
		return '<details class="prb-menu ' . esc_attr( $class ) . '"><summary aria-label="' . esc_attr( $label ) . '">' . $summary . '<span class="prb-caret" aria-hidden="true"></span></summary>'
			. '<div class="prb-panel">' . $body . '</div></details>';
	}

	/** A menu item: a link, the current one, or one that cannot be chosen (with why). */
	private static function item( $url, $text, $sub = '', $current = false, $why = '' ) {
		$inner = '<span class="prb-item-text">' . esc_html( $text ) . '</span>' . ( '' !== $sub ? '<span class="prb-item-sub">' . esc_html( $sub ) . '</span>' : '' );
		if ( $current ) {
			return '<li><span class="prb-item is-current" aria-current="true">' . $inner . '</span></li>';
		}
		if ( '' === $url ) {
			return '<li><span class="prb-item is-off" title="' . esc_attr( $why ) . '">' . $inner . '</span></li>';
		}
		return '<li><a class="prb-item" href="' . esc_url( $url ) . '">' . $inner . '</a></li>';
	}

	public static function toolbar( array $v ) {
		$t    = $v['t'] ?? '';
		$book = $v['book'] ?? null;
		$c    = $v['c'] ?? 0;
		$with = $v['with'] ?? '';
		if ( ! $t || ! BibleMap::translation( $t ) ) {
			return '';
		}
		$code = $book ? $book['code'] : '';
		$out  = '';

		// Previous, the picker, next.
		$nav = array( 'prev' => null, 'next' => null );
		if ( 'chapter' === $v['type'] ) {
			list( $nav['prev'], $nav['next'] ) = BibleMap::neighbours( $t, $code, $c );
		}
		$arrow = function ( $dir ) use ( $nav, $t, $with ) {
			$to    = $nav[ $dir ];
			$sign  = 'prev' === $dir ? '‹' : '›';
			$label = 'prev' === $dir ? __( 'Previous chapter', 'prayer-rule' ) : __( 'Next chapter', 'prayer-rule' );
			if ( ! $to ) {
				return '<span class="prb-arrow is-off" aria-hidden="true">' . $sign . '</span>';
			}
			$name = BibleMap::label( $to[0], $to[1] );
			return '<a class="prb-arrow" data-prb-' . $dir . ' href="' . esc_url( BiblePages::url( $t, $to[0]['code'], $to[1], $with ) ) . '" title="' . esc_attr( $label . ': ' . $name ) . '" aria-label="' . esc_attr( $label . ': ' . $name ) . '">' . $sign . '</a>';
		};
		$here = 'chapter' === $v['type'] ? BibleMap::label( $book, $c ) : ( $book ? $book['name'] : __( 'Books', 'prayer-rule' ) );
		$out .= '<div class="prb-bar-group prb-where">' . $arrow( 'prev' ) . self::menu( 'prb-picker', '<span class="prb-here">' . esc_html( $here ) . '</span>', __( 'Choose a book and chapter', 'prayer-rule' ), self::picker( $t, $with, $code ) ) . $arrow( 'next' ) . '</div>';

		// Translation and compare.
		$items = '';
		foreach ( BibleMap::translations() as $other ) {
			$o = $other['id'];
			if ( $o === $t ) {
				$items .= self::item( '', BibleMap::short( $o ), $other['name'], true );
				continue;
			}
			$url = '';
			$why = sprintf( /* translators: %s: translation */ __( 'Not in the %s', 'prayer-rule' ), $other['name'] );
			if ( 'chapter' === $v['type'] ) {
				$url = BiblePages::found_url( $o, BibleMap::find( $t, $code, $c, $o ), $o === $with ? $t : $with );
			} elseif ( 'book' === $v['type'] ) {
				$found = BibleMap::find( $t, $code, BibleMap::chapters( $t, $book )[0], $o );
				$url   = isset( $found['code'] ) ? BiblePages::url( $o, $found['code'] ) : '';
			} else {
				$url = BiblePages::url( $o );
			}
			$items .= self::item( $url, BibleMap::short( $o ), $other['name'], false, $why );
		}
		$right = self::menu( 'prb-trans', '<span class="prb-menu-key">' . esc_html__( 'Bible', 'prayer-rule' ) . '</span> ' . esc_html( BibleMap::short( $t ) ), __( 'Choose the translation', 'prayer-rule' ), '<ul class="prb-items">' . $items . '</ul>' );
		if ( 'chapter' === $v['type'] ) {
			$items = self::item( BiblePages::url( $t, $code, $c ), __( 'None', 'prayer-rule' ), __( 'one translation', 'prayer-rule' ), '' === $with );
			foreach ( BibleMap::translations() as $other ) {
				$o = $other['id'];
				if ( $o === $t ) {
					continue;
				}
				$ok     = (bool) BibleMap::find( $t, $code, $c, $o );
				$items .= self::item( $ok ? BiblePages::url( $t, $code, $c, $o ) : '', BibleMap::short( $o ), $other['name'], $o === $with, sprintf( /* translators: %s: translation */ __( 'Not in the %s', 'prayer-rule' ), $other['name'] ) );
			}
			$right .= self::menu( 'prb-compare' . ( $with ? ' is-on' : '' ), '<span class="prb-menu-key">' . esc_html__( 'Compare', 'prayer-rule' ) . '</span>' . ( $with ? ' ' . esc_html( BibleMap::short( $with ) ) : '' ), __( 'Show a second translation beside it', 'prayer-rule' ), '<ul class="prb-items">' . $items . '</ul>' );
		}
		$out .= '<div class="prb-bar-group prb-right">' . $right . '</div>';

		// Go to.
		$err  = BiblePages::go_error();
		$out .= '<form class="prb-goto" role="search" method="get" action="' . esc_url( BiblePages::url() ) . '">'
			. '<label class="screen-reader-text" for="prb-go">' . esc_html__( 'Go to a passage', 'prayer-rule' ) . '</label>'
			. '<input id="prb-go" name="go" type="search" autocomplete="off" spellcheck="false" placeholder="' . esc_attr__( 'Go to… e.g. Rom 8:28', 'prayer-rule' ) . '" value="' . esc_attr( $err ? $err[0] : '' ) . '">'
			. '<input type="hidden" name="t" value="' . esc_attr( $t ) . '">'
			. ( $code ? '<input type="hidden" name="at" value="' . esc_attr( $code . ' ' . (int) $c ) . '">' : '' )
			. ( $with ? '<input type="hidden" name="with" value="' . esc_attr( $with ) . '">' : '' )
			. ( BibleMap::$hebrew ? '<input type="hidden" name="numbering" value="hebrew">' : '' )
			. '<button type="submit" class="prb-go-btn" aria-label="' . esc_attr__( 'Go', 'prayer-rule' ) . '">↵</button>'
			. '<p class="prb-go-hint' . ( $err ? ' is-error' : '' ) . '" role="status" aria-live="polite">' . ( $err ? esc_html( $err[1] ) : '' ) . '</p>'
			. '</form>';

		return $out;
	}

	/**
	 * The picker: the translation's books; with the script a book opens its chapter numbers
	 * in place (data-pattern is a chapter address with 99999 for the number), without it the
	 * link opens the book's page.
	 */
	private static function picker( $t, $with, $code ) {
		$html = '<div class="prb-pick-books">';
		foreach ( self::sections( $t ) as $s ) {
			$html .= '<p class="prb-pick-head">' . esc_html( $s[0] ) . '</p><ul class="prb-pick-list">';
			foreach ( $s[1] as $b ) {
				$chs   = BibleMap::chapters( $t, $b );
				$list  = range( $chs[0], $chs[0] + count( $chs ) - 1 ) === $chs ? array( 'data-first' => $chs[0], 'data-n' => count( $chs ) ) : array( 'data-list' => implode( ',', $chs ) );
				$attrs = '';
				foreach ( $list as $k => $val ) {
					$attrs .= ' ' . $k . '="' . esc_attr( $val ) . '"';
				}
				$html .= '<li><a class="prb-pick-book' . ( $b['code'] === $code ? ' is-current' : '' ) . '" href="' . esc_url( BiblePages::url( $t, $b['code'] ) ) . '"'
					. ' data-name="' . esc_attr( $b['name'] ) . '" data-pattern="' . esc_url( BiblePages::url( $t, $b['code'], 99999, $with ) ) . '"' . $attrs . '>' . esc_html( $b['name'] ) . '</a></li>';
			}
			$html .= '</ul>';
		}
		return $html . '</div><div class="prb-pick-chapters" hidden></div>';
	}

	// ---------------------------------------------------------------- a translation's books

	private static function translation( $t ) {
		$tr   = BibleMap::translation( $t );
		$html = '<header class="prb-headline"><h2 class="prb-heading">' . esc_html( $tr['name'] ) . '</h2></header>';
		foreach ( self::sections( $t ) as $s ) {
			$html .= '<h3 class="prb-testament">' . esc_html( $s[0] ) . '</h3><ul class="prb-books">';
			foreach ( $s[1] as $b ) {
				$chs   = BibleMap::chapters( $t, $b );
				$one   = 1 === count( $chs );   // a book of one chapter opens at its text
				$html .= '<li>' . self::link( $one ? BiblePages::url( $t, $b['code'], $chs[0] ) : BiblePages::url( $t, $b['code'] ), $b['name'] ) . '</li>';
			}
			$html .= '</ul>';
		}
		return $html . self::notice( array( $t ) );
	}

	// ---------------------------------------------------------------- a book's chapters

	private static function book( $t, array $book ) {
		$marks = Commentary::chapters_with( $t, $book );
		$html  = '<header class="prb-headline"><h2 class="prb-heading">' . esc_html( $book['name'] ) . '</h2><p class="prb-sub">' . esc_html( BibleMap::translation( $t )['name'] ) . '</p></header>';
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

		$html = '';

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
		$html .= '<header class="prb-headline"><h2 class="prb-heading">' . esc_html( BibleMap::label( $book, $c ) ) . '</h2>'
			. '<p class="prb-sub">' . esc_html( BibleMap::translation( $t )['name'] ) . ( $line ? ' <span class="prb-sep">·</span> ' . implode( ' ', $line ) : '' ) . '</p></header>';
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
.pr-bible{--prb-red:#921a0a;--prb-line:rgba(127,127,127,.28);--prb-tint:rgba(146,26,10,.06);--prb-bg:var(--wp--preset--color--base,var(--theme-palette-color-8,#fff));position:relative}
.pr-bible a{text-decoration:none}
.pr-bible a:hover{text-decoration:underline}
.pr-bible .screen-reader-text{position:absolute!important;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px);white-space:nowrap}
/* the toolbar */
.pr-bible .prb-bar{position:sticky;top:var(--wp-admin--admin-bar--height,0px);z-index:20;display:flex;flex-wrap:wrap;align-items:center;gap:.5em .75em;padding:.55em .6em;margin:0 0 1.4em;background:var(--prb-bg);border:1px solid var(--prb-line);border-radius:10px;box-shadow:0 4px 14px -10px rgba(0,0,0,.35)}
.pr-bible .prb-bar-group{display:flex;align-items:center;gap:.35em}
.pr-bible .prb-right{margin-left:0}
.pr-bible .prb-arrow{display:inline-flex;align-items:center;justify-content:center;width:2.1em;height:2.1em;border:1px solid var(--prb-line);border-radius:8px;color:inherit;font-size:1.15em;line-height:1}
.pr-bible a.prb-arrow:hover{border-color:var(--prb-red);color:var(--prb-red);text-decoration:none}
.pr-bible .prb-arrow.is-off{opacity:.3}
.pr-bible .prb-menu{position:static}
.pr-bible .prb-menu>summary{list-style:none;cursor:pointer;display:inline-flex;align-items:center;gap:.4em;padding:.38em .75em;border:1px solid var(--prb-line);border-radius:8px;white-space:nowrap;user-select:none}
.pr-bible .prb-menu>summary::-webkit-details-marker{display:none}
.pr-bible .prb-menu>summary:hover,.pr-bible .prb-menu[open]>summary{border-color:var(--prb-red)}
.pr-bible .prb-menu[open]>summary{background:var(--prb-tint)}
.pr-bible .prb-caret{width:.45em;height:.45em;border-right:1.5px solid currentColor;border-bottom:1.5px solid currentColor;transform:rotate(45deg) translate(-2px,-2px);opacity:.6}
.pr-bible .prb-picker{min-width:0}
.pr-bible .prb-picker>summary{font-weight:600;min-width:9em;max-width:100%;justify-content:space-between}
.pr-bible .prb-here{overflow:hidden;text-overflow:ellipsis}
.pr-bible .prb-menu-key{font-size:.75em;letter-spacing:.06em;text-transform:uppercase;opacity:.6}
.pr-bible .prb-compare.is-on>summary{border-color:var(--prb-red);color:var(--prb-red)}
.pr-bible .prb-panel{position:absolute;left:0;right:0;top:calc(100% + 6px);z-index:30;background:var(--prb-bg);border:1px solid var(--prb-line);border-radius:10px;box-shadow:0 12px 32px -12px rgba(0,0,0,.4);padding:.8em 1em;max-height:min(70vh,34em);overflow:auto}
.pr-bible .prb-trans .prb-panel,.pr-bible .prb-compare .prb-panel{left:auto;width:min(22em,92vw)}
.pr-bible .prb-items{list-style:none;margin:0;padding:0}
.pr-bible .prb-item{display:flex;flex-direction:column;padding:.45em .6em;border-radius:6px;color:inherit}
.pr-bible a.prb-item:hover{background:var(--prb-tint);text-decoration:none}
.pr-bible .prb-item.is-current{background:var(--prb-red);color:#fff}
.pr-bible .prb-item.is-off{opacity:.4}
.pr-bible .prb-item-text{font-weight:600}
.pr-bible .prb-item-sub{font-size:.82em;opacity:.75}
/* the picker */
.pr-bible .prb-pick-head{margin:.6em 0 .35em;font-size:.75em;letter-spacing:.08em;text-transform:uppercase;color:var(--prb-red)}
.pr-bible .prb-pick-head:first-child{margin-top:0}
.pr-bible .prb-pick-list{list-style:none;margin:0 0 .4em;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(10.5em,1fr));gap:.15em .6em}
.pr-bible .prb-pick-book{display:block;padding:.28em .5em;border-radius:6px;color:inherit;font-size:.95em}
.pr-bible .prb-pick-book:hover{background:var(--prb-tint);text-decoration:none}
.pr-bible .prb-pick-book.is-current{background:var(--prb-tint);color:var(--prb-red);font-weight:600}
.pr-bible .prb-pick-chead{display:flex;align-items:center;gap:1em;margin:0 0 .7em}
.pr-bible .prb-pick-back{background:none;border:1px solid var(--prb-line);border-radius:6px;padding:.25em .7em;cursor:pointer;color:inherit;font:inherit;font-size:.9em}
.pr-bible .prb-pick-back:hover{border-color:var(--prb-red)}
.pr-bible .prb-pick-name{font-weight:600;color:var(--prb-red)}
.pr-bible .prb-pick-nums{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(2.9em,1fr));gap:.35em}
.pr-bible .prb-pick-nums a{display:block;text-align:center;padding:.4em 0;border:1px solid var(--prb-line);border-radius:6px;color:inherit}
.pr-bible .prb-pick-nums a:hover,.pr-bible .prb-pick-nums a:focus{border-color:var(--prb-red);text-decoration:none}
.pr-bible .prb-pick-nums a.is-current{background:var(--prb-red);border-color:var(--prb-red);color:#fff}
/* Go to */
.pr-bible .prb-goto{position:relative;flex:1 1 14em;display:flex;align-items:center;margin:0}
.pr-bible .prb-goto input[type=search]{width:100%;margin:0;padding:.42em 2.2em .42em .75em;border:1px solid var(--prb-line);border-radius:8px;background:transparent;color:inherit;font:inherit;font-size:.95em;line-height:1.4;box-shadow:none}
.pr-bible .prb-goto input[type=search]:focus{outline:2px solid rgba(146,26,10,.35);outline-offset:1px;border-color:var(--prb-red)}
.pr-bible .prb-go-btn{position:absolute;right:.3em;background:none;border:0;color:var(--prb-red);font-size:1.05em;cursor:pointer;padding:.2em .4em}
.pr-bible .prb-go-hint{position:absolute;left:0;top:calc(100% + 4px);margin:0;font-size:.82em;white-space:nowrap;background:var(--prb-bg);padding:.15em .5em;border-radius:4px;z-index:25}
.pr-bible .prb-go-hint:empty{display:none}
.pr-bible .prb-go-hint.is-ok{color:var(--prb-red)}
.pr-bible .prb-go-hint.is-error{color:#b32d2e;white-space:normal;width:max-content;max-width:min(26em,90vw);box-shadow:0 4px 12px -6px rgba(0,0,0,.3)}
.pr-bible .prb-go-hint a{text-decoration:underline}
/* the content */
.pr-bible .prb-content{transition:opacity .15s}
.pr-bible.is-loading .prb-content{opacity:.45;pointer-events:none}
.pr-bible .prb-headline{margin:0 0 1.1em}
.pr-bible .prb-heading{margin:0;font-size:1.7em;line-height:1.2;font-weight:600}
.pr-bible .prb-heading:focus{outline:none}
.pr-bible .prb-sub{margin:.25em 0 0;font-size:.92em;opacity:.8}
.pr-bible .prb-sep{opacity:.5;margin:0 .25em}
.pr-bible .prb-testament{color:var(--prb-red);font-weight:normal;font-size:1.05em;letter-spacing:.08em;text-transform:uppercase;margin:1.6em 0 .6em;padding-bottom:.3em;border-bottom:1px solid var(--prb-line)}
.pr-bible .prb-books{list-style:none;margin:0;padding:0;columns:13em auto;column-gap:2em}
.pr-bible .prb-books li{margin:0 0 .3em;break-inside:avoid}
.pr-bible .prb-chapters{list-style:none;margin:0 0 1em;padding:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(3em,1fr));gap:.4em}
.pr-bible .prb-chapters a{display:block;text-align:center;padding:.35em 0;border:1px solid var(--prb-line);border-radius:6px;color:inherit;position:relative}
.pr-bible .prb-chapters a:hover{border-color:var(--prb-red);text-decoration:none}
.pr-bible .prb-chapters a.has-comm::after,.pr-bible .prb-dot{content:"";display:inline-block;width:.38em;height:.38em;border-radius:50%;background:var(--prb-red)}
.pr-bible .prb-chapters a.has-comm::after{position:absolute;top:.3em;right:.3em}
.pr-bible .prb-key{font-size:.85em;opacity:.75;margin:.2em 0 1em}
.pr-bible .prb-other{opacity:.75}
.pr-bible .pr-comm-links{white-space:nowrap}
.pr-bible .pr-comm{color:#8c8f94;font-style:italic;text-decoration:underline dotted;text-underline-offset:.2em;margin-right:.6em}
.pr-bible .pr-comm:hover{color:var(--prb-red)}
.pr-bible .prb-note{font-size:.9em;opacity:.75;font-style:italic}
.pr-bible .prb-title{font-style:italic;opacity:.8;margin:0 0 .6em}
.pr-bible .prb-mark{font-size:.82em;letter-spacing:.04em;opacity:.65;margin:1em 0 .4em;padding-top:.5em;border-top:1px dotted var(--prb-line)}
.pr-bible .prb-numbering{font-size:.88em;opacity:.85;margin:-.4em 0 1em;padding-left:.7em;border-left:3px solid rgba(146,26,10,.3)}
.pr-bible .prb-numbering a{white-space:nowrap;text-decoration:underline;text-underline-offset:.2em}
.pr-bible .prb-v{margin:0 0 .45em;line-height:1.7;scroll-margin-top:calc(var(--wp-admin--admin-bar--height,0px) + var(--prb-bar-h,5.5em) + .9em);border-radius:4px;transition:background-color .4s}
.pr-bible .prb-vn{color:var(--prb-red);font-size:.72em;font-weight:600;vertical-align:.25em;margin-right:.15em}
.pr-bible .prb-vn a.pr-vn-comm{color:inherit;background:rgba(146,26,10,.12);border-radius:3px;padding:.05em .3em;text-decoration:none}
.pr-bible .prb-vn a.pr-vn-comm:hover{background:var(--prb-red);color:#fff}
.pr-bible .prb-v.is-none{opacity:.35}
.pr-bible .prb-v.is-hl,.pr-bible .prb-row.is-hl,.pr-bible .prb-v:target,.pr-bible .prb-row:target{background:rgba(214,170,40,.22);box-shadow:0 0 0 .25em rgba(214,170,40,.22)}
.pr-bible .prb-heads,.pr-bible .prb-row,.pr-bible .prb-cols{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);column-gap:1.6em}
.pr-bible .prb-row{border-bottom:1px solid rgba(127,127,127,.12);padding:.15em 0;scroll-margin-top:calc(var(--wp-admin--admin-bar--height,0px) + var(--prb-bar-h,5.5em) + .9em)}
.pr-bible .prb-row .prb-v{margin:.2em 0}
.pr-bible .prb-heads{border-bottom:1px solid var(--prb-line);margin:0 0 .8em;padding-bottom:.3em;font-weight:600}
.pr-bible .prb-head a{color:var(--prb-red)}
.pr-bible .prb-swap{margin-left:.4em;font-weight:normal;color:inherit;opacity:.7}
.pr-bible .prb-col-head{display:none;font-weight:600;color:var(--prb-red);margin:0 0 .5em}
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
.pr-bible .prb-bar{padding:.45em;gap:.45em;border-radius:0;margin-left:-.5em;margin-right:-.5em;border-left:0;border-right:0}
.pr-bible .prb-where{flex:1 1 100%}
.pr-bible .prb-picker{flex:1 1 auto}
.pr-bible .prb-picker>summary{width:100%;min-width:0;box-sizing:border-box}
.pr-bible .prb-goto{flex:1 1 100%}
.pr-bible .prb-right{margin-left:0}
.pr-bible .prb-menu-key{display:none}
.pr-bible .prb-trans .prb-panel,.pr-bible .prb-compare .prb-panel{left:0;width:auto}
.pr-bible .prb-pick-list{grid-template-columns:repeat(auto-fill,minmax(8.5em,1fr))}
.pr-bible .prb-heads{column-gap:.8em;font-size:.9em}
.pr-bible .prb-row{display:block;padding:.3em 0}
.pr-bible .prb-row .prb-cell+.prb-cell{border-left:3px solid rgba(146,26,10,.25);padding-left:.6em}
.pr-bible .prb-cols{display:block}
.pr-bible .prb-cols .prb-col+.prb-col{margin-top:1.5em;padding-top:1em;border-top:1px solid var(--prb-line)}
.pr-bible .prb-col-head{display:block}
}';
	}
}
