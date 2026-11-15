<?php
/**
 * The Bible on the site: the four Bibles of the Scripture reading, as public pages that live
 * on one ordinary WordPress page ("Bible", holding [prayer_rule_bible], made when an
 * administrator first opens the admin), so any theme shows them:
 *
 *   /bible/                          the translations
 *   /bible/<t>/                      one translation's books, by testament
 *   /bible/<t>/<book>/               a book's chapters (and any introductions in the commentary)
 *   /bible/<t>/<book>/<chapter>/     a chapter; ?with=<t2> puts a second translation beside it
 *
 * Every Psalter is numbered as in the Septuagint, the KJV's and Wycliffe's too (put together
 * from their Hebrew-numbered psalms); ?numbering=hebrew shows those two in their own numbering.
 *
 * The addresses work even when WordPress's stored rewrite rules lack ours (as on the live
 * site with the commentary): a request under the page that WordPress could not match is
 * read on the 'request' filter, as the Patristic Commentary plugin does since 0.1.1.
 * Who may read follows Prayer Rule → Settings → Who may read.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BiblePages {

	const OPT_PAGE  = 'prayer_rule_bible_page';
	const OPT_RULES = 'prayer_rule_bible_rules_for';
	const SHORTCODE = 'prayer_rule_bible';

	/** Where /bible/ opens on a first visit (after that, where the reader last was). */
	const START = array( 'kjv', 'MAT', 1 );

	private static $view     = null;
	private static $go_error = null;

	public static function boot() {
		add_action( 'init', array( __CLASS__, 'init' ) );
		add_action( 'admin_init', array( __CLASS__, 'page_id' ) );     // makes the page once, after an update too
		add_action( 'rest_api_init', array( __CLASS__, 'rest_routes' ) );
	}

	/** The page that shows the Bible (made if missing, by an administrator). */
	public static function page_id() {
		$id = (int) get_option( self::OPT_PAGE );
		if ( $id && get_post_status( $id ) && 'trash' !== get_post_status( $id ) ) {
			return $id;
		}
		$found = get_posts(
			array(
				'post_type'   => 'page',
				'post_status' => array( 'publish', 'draft', 'private' ),
				's'           => '[' . self::SHORTCODE,
				'numberposts' => 1,
				'fields'      => 'ids',
			)
		);
		$id = $found ? (int) $found[0] : 0;
		if ( ! $id && ( current_user_can( 'publish_pages' ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
			$id = (int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => 'Bible',
					'post_name'    => 'bible',
					'post_content' => "<!-- wp:shortcode -->\n[" . self::SHORTCODE . "]\n<!-- /wp:shortcode -->",
				)
			);
		}
		if ( $id ) {
			update_option( self::OPT_PAGE, $id );
		}
		return $id;
	}

	/** "bible" (or wherever the page is). */
	public static function base() {
		$id = (int) get_option( self::OPT_PAGE );
		return $id ? trim( (string) get_page_uri( $id ), '/' ) : 'bible';
	}

	public static function init() {
		// The KJV's and Wycliffe's Psalms in their own numbering for this view (else as in the Greek).
		BibleMap::$hebrew = isset( $_GET['numbering'] ) && 'hebrew' === $_GET['numbering'];  // phpcs:ignore WordPress.Security.NonceVerification
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		self::add_rules();
		if ( get_option( self::OPT_PAGE ) && get_option( self::OPT_RULES ) !== self::rules_key() ) {   // moved, renamed or updated
			flush_rewrite_rules( false );
			update_option( self::OPT_RULES, self::rules_key() );
		}
		add_filter( 'request', array( __CLASS__, 'route' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'no_redirect' ) );
		add_action( 'template_redirect', array( __CLASS__, 'go' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'not_found' ) );
		add_filter( 'document_title_parts', array( __CLASS__, 'document_title' ) );
		add_filter( 'get_canonical_url', array( __CLASS__, 'canonical' ) );
		add_filter( 'wpseo_title', array( __CLASS__, 'seo_title' ) );
		add_filter( 'wpseo_opengraph_title', array( __CLASS__, 'seo_title' ) );
		add_filter( 'wpseo_metadesc', array( __CLASS__, 'seo_description' ) );
		add_filter( 'wpseo_opengraph_desc', array( __CLASS__, 'seo_description' ) );
		add_filter( 'wpseo_canonical', array( __CLASS__, 'canonical' ) );
		add_filter( 'wpseo_opengraph_url', array( __CLASS__, 'canonical' ) );
		add_filter( 'rank_math/frontend/title', array( __CLASS__, 'seo_title' ) );
		add_filter( 'rank_math/frontend/description', array( __CLASS__, 'seo_description' ) );
		add_filter( 'rank_math/frontend/canonical', array( __CLASS__, 'canonical' ) );
		add_action( 'wp_head', array( __CLASS__, 'meta_description' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function add_rules() {
		$id = (int) get_option( self::OPT_PAGE );
		if ( ! $id ) {
			return;
		}
		$base = preg_quote( self::base(), '#' );
		$to   = 'index.php?page_id=' . $id;
		add_rewrite_rule( "^$base/([a-z0-9-]+)/([a-z0-9-]+)/([0-9]+)/?$", $to . '&prb_t=$matches[1]&prb_b=$matches[2]&prb_c=$matches[3]', 'top' );
		add_rewrite_rule( "^$base/([a-z0-9-]+)/([a-z0-9-]+)/?$", $to . '&prb_t=$matches[1]&prb_b=$matches[2]', 'top' );
		add_rewrite_rule( "^$base/([a-z0-9-]+)/?$", $to . '&prb_t=$matches[1]', 'top' );
	}

	/** What the stored rules were made for: the page's place and this version. */
	public static function rules_key() {
		return self::base() . '|' . PRAYER_RULE_VERSION;
	}

	/** A request under the page that WordPress could not match is read here. */
	public static function route( $vars ) {
		global $wp;
		if ( isset( $vars['prb_t'] ) || empty( $wp->request ) ) {
			return $vars;
		}
		$id   = (int) get_option( self::OPT_PAGE );
		$base = self::base();
		$path = trim( (string) $wp->request, '/' );
		if ( ! $id || 0 !== strpos( $path, $base . '/' ) ) {
			return $vars;
		}
		$rest = substr( $path, strlen( $base ) + 1 );
		if ( preg_match( '#^([a-z0-9-]+)/([a-z0-9-]+)/([0-9]+)$#', $rest, $m ) ) {
			return array(
				'page_id' => $id,
				'prb_t'   => $m[1],
				'prb_b'   => $m[2],
				'prb_c'   => $m[3],
			);
		}
		if ( preg_match( '#^([a-z0-9-]+)(?:/([a-z0-9-]+))?$#', $rest, $m ) && ! get_page_by_path( $path ) ) {
			$out = array(
				'page_id' => $id,
				'prb_t'   => $m[1],
			);
			if ( ! empty( $m[2] ) ) {
				$out['prb_b'] = $m[2];
			}
			return $out;
		}
		return $vars;
	}

	public static function query_vars( $vars ) {
		return array_merge( $vars, array( 'prb_t', 'prb_b', 'prb_c' ) );
	}

	// ---------------------------------------------------------------- addresses

	/** The address of the index, a translation, a book or a chapter; $with: a second translation beside it. */
	public static function url( $t = null, $code = null, $c = null, $with = '' ) {
		$id = (int) get_option( self::OPT_PAGE );
		if ( ! $id ) {
			return home_url( '/' );
		}
		$plain = ! get_option( 'permalink_structure' );
		$url   = $plain ? get_permalink( $id ) : trailingslashit( get_permalink( $id ) );
		if ( null === $t ) {
			return $url;
		}
		$book = null !== $code ? BibleMap::slug( $t, $code ) : null;
		if ( $plain ) {                                           // plain addresses
			$args = array( 'prb_t' => $t );
			if ( null !== $book ) {
				$args['prb_b'] = $book;
			}
			if ( null !== $c ) {
				$args['prb_c'] = (int) $c;
			}
			$url = add_query_arg( $args, $url );
		} else {
			$url .= $t . '/' . ( null !== $book ? $book . '/' . ( null !== $c ? (int) $c . '/' : '' ) : '' );
		}
		if ( '' !== $with ) {
			$url = add_query_arg( 'with', $with, $url );
		}
		if ( BibleMap::$hebrew && 'PSA' === $code && ( BibleMap::hebrew_psalter( $t ) || BibleMap::hebrew_psalter( $with ) ) ) {
			$url = add_query_arg( 'numbering', 'hebrew', $url );   // kept while reading the Psalms
		}
		return $url;
	}

	/** The address for a BibleMap::find() result in translation $t ('' for none). */
	public static function found_url( $t, array $found, $with = '' ) {
		if ( isset( $found['c'] ) ) {
			return self::url( $t, $found['code'], $found['c'], $with );
		}
		return isset( $found['code'] ) ? self::url( $t, $found['code'] ) : '';
	}

	// ---------------------------------------------------------------- what is shown

	public static function is_ours() {
		$id = (int) get_option( self::OPT_PAGE );
		return $id && is_page( $id );
	}

	/** What this request shows (see view_for). */
	public static function view() {
		if ( null === self::$view ) {
			$with       = isset( $_GET['with'] ) ? sanitize_key( wp_unslash( $_GET['with'] ) ) : '';  // phpcs:ignore WordPress.Security.NonceVerification
			self::$view = self::view_for( (string) get_query_var( 'prb_t' ), (string) get_query_var( 'prb_b' ), (string) get_query_var( 'prb_c' ), $with );
		}
		return self::$view;
	}

	/** The chapter /bible/ opens on: array( t, code, c ) of a loaded Bible. */
	public static function start() {
		list( $t, $code, $c ) = self::START;
		if ( ! BibleMap::has_chapter( $t, $code, $c ) ) {
			$first = BibleMap::translations()[0] ?? null;
			if ( ! $first ) {
				return null;
			}
			$t    = $first['id'];
			$b    = $first['books'][0];
			$code = $b['code'];
			$c    = BibleMap::chapters( $t, $b )[0];
		}
		return array( $t, $code, $c );
	}

	/**
	 * What an address shows: array( 'type' => translation | book | chapter | missing,
	 * 't', 'book', 'c', 'with', 'landing', 'doc_title', 'description', 'url' ).
	 * /bible/ itself is the start chapter, as a "landing" view with its own address.
	 */
	public static function view_for( $t, $slug, $c, $with = '' ) {
		$t       = sanitize_key( $t );
		$slug    = sanitize_title( $slug );
		$c       = (string) $c;
		$landing = false;
		if ( '' === $t ) {
			$start = self::start();
			if ( ! $start ) {
				return array( 'type' => 'missing' );
			}
			$landing = true;
			list( $t, $code, $c ) = $start;
			$c    = (string) $c;
			$slug = BibleMap::slug( $t, $code );
			$with = '';
		}
		$tr = BibleMap::translation( $t );
		if ( ! $tr ) {
			return array( 'type' => 'missing' );
		}
		if ( '' === $slug ) {
			return array(
				'type'        => 'translation',
				't'           => $t,
				'doc_title'   => $tr['name'],
				'description' => sprintf( /* translators: %s: translation */ __( 'The books of the %s.', 'prayer-rule' ), $tr['name'] ),
				'url'         => self::url( $t ),
			);
		}
		$book = BibleMap::book_by_slug( $t, $slug );
		if ( $book && '' === $c ) {
			return array(
				'type'        => 'book',
				't'           => $t,
				'book'        => $book,
				'doc_title'   => $book['name'] . ' (' . BibleMap::short( $t ) . ')',
				'description' => sprintf( /* translators: 1: book, 2: translation */ __( '%1$s in the %2$s, chapter by chapter.', 'prayer-rule' ), $book['name'], $tr['name'] ),
				'url'         => self::url( $t, $book['code'] ),
			);
		}
		if ( $book && ctype_digit( $c ) && BibleMap::has_chapter( $t, $book['code'], (int) $c ) ) {
			$label = BibleMap::label( $book, (int) $c );
			return array(
				'type'        => 'chapter',
				't'           => $t,
				'book'        => $book,
				'c'           => (int) $c,
				'with'        => ( $with !== $t && BibleMap::translation( $with ) ) ? $with : '',
				'landing'     => $landing,
				'doc_title'   => $landing ? __( 'The Holy Bible', 'prayer-rule' ) : $label . ' (' . BibleMap::short( $t ) . ')',
				'description' => $landing
					? __( 'The Holy Scriptures to read: the King James Version with the Apocrypha, the Douay-Rheims, Wycliffe\'s Bible and Brenton\'s Septuagint, with the Fathers\' commentary.', 'prayer-rule' )
					: sprintf( /* translators: 1: e.g. Romans 8, 2: translation */ __( '%1$s in the %2$s.', 'prayer-rule' ), $label, $tr['name'] ),
				'url'         => $landing ? self::url() : self::url( $t, $book['code'], (int) $c ),   // the side-by-side view has the single one as canonical
			);
		}
		return array( 'type' => 'missing' );
	}

	// ---------------------------------------------------------------- "Go to"

	/**
	 * Read a "Go to" box: array( ok, url, v1, v2, label, note ) or array( ok false, error,
	 * other => url of the book in another Bible ). $t: the Bible being read; $at: "ROM 8".
	 */
	public static function go_to( $q, $t, $at = '', $with = '' ) {
		if ( ! BibleMap::translation( $t ) ) {
			$t = self::start() ? self::start()[0] : '';
		}
		$cur = preg_split( '/\s+/', trim( (string) $at ) );
		$r   = BibleMap::parse_ref( $q, $t, sanitize_text_field( $cur[0] ?? '' ), (int) ( $cur[1] ?? 0 ) );
		if ( isset( $r['error'] ) ) {
			$out = array( 'ok' => false, 'error' => $r['error'] );
			if ( ! empty( $r['t_other'] ) ) {
				$o            = BibleMap::parse_ref( $q, $r['t_other'] );
				$out['other'] = isset( $o['code'] ) ? self::url( $r['t_other'], $o['code'], $o['c'] ) . ( $o['v1'] ? '#v' . $o['v1'] : '' ) : self::url( $r['t_other'] );
			}
			return $out;
		}
		$with = ( $with !== $t && BibleMap::translation( $with ) && BibleMap::find( $t, $r['code'], $r['c'], $with ) ) ? $with : '';
		return array(
			'ok'    => true,
			'url'   => self::url( $t, $r['code'], $r['c'], $with ),
			'v1'    => $r['v1'],
			'v2'    => $r['v2'],
			'label' => $r['label'],
			'note'  => $r['note'] ?? '',
		);
	}

	/** Without the script, the "Go to" form arrives as ?go=…: go there, or keep the error for the box. */
	public static function go() {
		if ( ! self::is_ours() || ! isset( $_GET['go'] ) ) {  // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$get = wp_unslash( $_GET );                         // phpcs:ignore WordPress.Security.NonceVerification
		$q   = sanitize_text_field( (string) $get['go'] );
		$r   = self::go_to( $q, sanitize_key( (string) ( $get['t'] ?? '' ) ), (string) ( $get['at'] ?? '' ), sanitize_key( (string) ( $get['with'] ?? '' ) ) );
		if ( $r['ok'] ) {
			wp_safe_redirect( $r['url'] . ( $r['v1'] ? '#v' . $r['v1'] : '' ) );
			exit;
		}
		self::$go_error = array( $q, $r['error'] );
	}

	/** What the box should show after a failed ?go=: array( typed, message ) or null. */
	public static function go_error() {
		return self::$go_error;
	}

	// ---------------------------------------------------------------- REST (for assets/bible.js)

	public static function rest_routes() {
		register_rest_route(
			'prayer-rule/v1',
			'/bible/view',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_view' ),
				'permission_callback' => '__return_true',
				'args'                => array( 'path' => array( 'type' => 'string', 'required' => true ) ),
			)
		);
		register_rest_route(
			'prayer-rule/v1',
			'/bible/goto',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_goto' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/** A Bible address as the reader's toolbar and content, so the script can swap them in place. */
	public static function rest_view( \WP_REST_Request $req ) {
		if ( ! Access::can_read() ) {
			return array( 'locked' => true );               // the script then loads the page itself
		}
		$parts = wp_parse_url( (string) $req['path'] );
		$path  = trim( (string) ( $parts['path'] ?? '' ), '/' );
		$home  = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && 0 === strpos( $path . '/', $home . '/' ) ) {
			$path = trim( substr( $path, strlen( $home ) ), '/' );
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $q );
		BibleMap::$hebrew = 'hebrew' === ( $q['numbering'] ?? '' );
		$base = self::base();
		if ( ! empty( $q['prb_t'] ) ) {                     // plain addresses
			$seg = array( (string) $q['prb_t'], (string) ( $q['prb_b'] ?? '' ), (string) ( $q['prb_c'] ?? '' ) );
		} elseif ( $path === $base || ( isset( $q['page_id'] ) && ! isset( $q['prb_t'] ) ) ) {
			$seg = array( '', '', '' );
		} elseif ( 0 === strpos( $path, $base . '/' ) ) {
			$seg = array_pad( explode( '/', substr( $path, strlen( $base ) + 1 ) ), 3, '' );
		} else {
			return new \WP_Error( 'prb_not_bible', 'Not a Bible address.', array( 'status' => 404 ) );
		}
		$v = self::view_for( $seg[0], $seg[1], $seg[2], sanitize_key( (string) ( $q['with'] ?? '' ) ) );
		return array(
			'type'      => $v['type'],
			'url'       => $v['url'] ?? '',
			'landing'   => ! empty( $v['landing'] ),
			'doc_title' => ( $v['doc_title'] ?? __( 'Not found', 'prayer-rule' ) ) . ' – ' . get_bloginfo( 'name' ),
			'two'       => ! empty( $v['with'] ),
			'toolbar'   => BibleRender::toolbar( $v ),
			'content'   => BibleRender::content( $v ),
		);
	}

	public static function rest_goto( \WP_REST_Request $req ) {
		BibleMap::$hebrew = 'hebrew' === $req['numbering'];
		return self::go_to( (string) $req['q'], sanitize_key( (string) $req['t'] ), (string) $req['at'], sanitize_key( (string) $req['with'] ) );
	}

	public static function not_found() {
		if ( self::is_ours() && 'missing' === self::view()['type'] ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	public static function no_redirect( $redirect ) {
		return get_query_var( 'prb_t' ) ? false : $redirect;
	}

	public static function document_title( $parts ) {
		if ( self::is_ours() && ! empty( self::view()['doc_title'] ) ) {
			$parts['title'] = self::view()['doc_title'];
		}
		return $parts;
	}

	public static function canonical( $url ) {
		if ( self::is_ours() && ! empty( self::view()['url'] ) ) {
			return self::view()['url'];
		}
		return $url;
	}

	public static function seo_title( $title ) {
		if ( self::is_ours() && ! empty( self::view()['doc_title'] ) ) {
			return self::view()['doc_title'] . ' – ' . get_bloginfo( 'name' );
		}
		return $title;
	}

	public static function seo_description( $text ) {
		if ( self::is_ours() && ! empty( self::view()['description'] ) ) {
			return self::view()['description'];
		}
		return $text;
	}

	/** Without Yoast or Rank Math, the page still gets a description. */
	public static function meta_description() {
		if ( ! self::is_ours() || defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
			return;
		}
		$d = self::view()['description'] ?? '';
		if ( $d ) {
			echo '<meta name="description" content="' . esc_attr( $d ) . '">' . "\n";
		}
	}

	public static function assets() {
		if ( ! self::is_ours() ) {
			return;
		}
		wp_register_style( 'prayer-rule-bible', false, array(), PRAYER_RULE_VERSION );
		wp_enqueue_style( 'prayer-rule-bible' );
		wp_add_inline_style( 'prayer-rule-bible', BibleRender::css() );
		Commentary::enqueue();                          // the pop-up, on any page the reader may turn to
		wp_enqueue_script( 'prayer-rule-bible', PRAYER_RULE_URL . 'assets/bible.js', array(), PRAYER_RULE_VERSION, true );
		wp_localize_script(
			'prayer-rule-bible',
			'prbBible',
			array(
				'rest'  => esc_url_raw( rest_url( 'prayer-rule/v1/bible/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'base'  => wp_parse_url( self::url(), PHP_URL_PATH ),
				'home'  => self::url(),
			)
		);
	}

	public static function shortcode() {
		if ( ! self::is_ours() ) {
			return '';                 // only on the Bible page itself
		}
		if ( ! Access::can_read() ) {                 // Prayer Rule → Settings → Who may read
			if ( is_user_logged_in() ) {
				return '<div class="pr-bible"><p>' . esc_html__( 'The Bible here is for members.', 'prayer-rule' ) . '</p></div>';
			}
			$login = '<a href="' . esc_url( wp_login_url( self::view()['url'] ?? self::url() ) ) . '">' . esc_html__( 'Log in', 'prayer-rule' ) . '</a>';
			return '<div class="pr-bible"><p>' . esc_html__( 'Please log in to read the Bible here.', 'prayer-rule' ) . ' ' . $login . '</p></div>';
		}
		return BibleRender::view( self::view() );
	}
}
