<?php
/**
 * Assembling and showing a service: its order, with the member's rule
 * (kathismas and/or ladders) put in place of each `rule` line.
 * The same data feeds the admin preview now and the members' reader later.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Render {

	const OPT_COLONS = 'prayer_rule_show_colons';
	const OPT_VERSES = 'prayer_rule_show_verse_numbers';
	const RED        = '#921a0a';

	/** Translation of the reading being assembled (for [psalm] in prayers). */
	private static $translation = null;

	/** The saint of the day as named in a dismissal, when known (calendar). */
	private static $saint = null;

	/** The day (Calendar::day()) while a service is assembled, for [zapev] and [bow]. */
	private static $info = null;

	/** The service being assembled, and where in it: before | rule | after (for [bow]). */
	private static $service = '';
	private static $phase   = 'before';

	/**
	 * "God-bearer", never "Theotokos": applied to every prayer and calendar
	 * line the plugin shows, whatever the source text says.
	 */
	public static function god_bearer( $text ) {
		return preg_replace( '/\btheotokos\b/iu', 'God-bearer', (string) $text );
	}

	/**
	 * A Coverdale verse as shown. The stored text stays as printed in the 1662 Prayer Book.
	 * - "Name" is printed with a small letter, like every other noun.
	 * - With the pointing hidden, the half-verse colon becomes a comma: the mark the
	 *   Great Bible itself mostly has at that place (Ps 1:1, 23:1, 51:2, 91:1).
	 */
	public static function coverdale( $verse ) {
		$verse = preg_replace( '/\bName\b/', 'name', (string) $verse );
		if ( ! self::show_colons() ) {
			$verse = str_replace( ' : ', ', ', $verse );
		}
		return $verse;
	}

	/** Show Coverdale's half-verse colons ( : )? Default yes. */
	public static function show_colons() {
		return '0' !== (string) get_option( self::OPT_COLONS, '1' );
	}

	/** The reader's own choice, for this request (null: the site setting). */
	private static $verses = null;

	public static function set_verse_numbers( $show ) {
		self::$verses = null === $show ? null : (bool) $show;
	}

	/** Show verse numbers in psalms? Default no. */
	public static function show_verse_numbers() {
		if ( null !== self::$verses ) {
			return self::$verses;
		}
		return '1' === (string) get_option( self::OPT_VERSES, '0' );
	}

	/**
	 * A service as data. $context:
	 *   'translation' => id
	 *   'lent'        => bool (Great Lent: troparia after kathismas, bows to the ground)
	 *   'portions'    => array( 'main' => array( 'kathismas' => 2, 'start' => 7, 'ladders' => 0 ), … )
	 *                    a portion without 'start' continues where the one before it stopped
	 *   'date'        => the civil date the service belongs to (YYYY-MM-DD); gives
	 *                    the day of the week, Great Lent and the saint (Calendar::day)
	 *   'day'         => without a date: liturgical day, 0 (Sunday) … 6 or a day name
	 *   'saint'       => the saint as named in the dismissal (overrides the date's)
	 *   'lent'        => overrides the date's Great Lent
	 *   'bedtime'     => true: the Cell Rule followed by the prayers before sleep
	 *   'troparia'    => true: the troparia and prayer after each kathisma all year
	 *                    (in Great Lent they are always said)
	 *
	 * Steps: text, psalm, say, rubric, kathisma (heading), stasis, ladders,
	 * missing, unknown, empty-rule.
	 */
	public static function assemble_service( $service_key, $context ) {
		$service = Services::get( $service_key );
		if ( ! $service || ! $service['order'] ) {
			return array();
		}
		$translation       = Psalter::translation( $context['translation'] ?? '' );
		$previous          = array( self::$translation, self::$saint, self::$info, self::$service, self::$phase );
		self::$service     = (string) $service_key;
		self::$phase       = 'before';
		self::$translation = $translation;
		$info              = null;
		if ( ! empty( $context['date'] ) ) {
			$info                = Calendar::day( $context['date'] );
			$context['day']      = $info['day'];
			$context['lent']     = $context['lent'] ?? $info['lent'];
			$context['saint']    = $context['saint'] ?? $info['saint'];
		}
		self::$info        = $info;
		self::$saint       = isset( $context['saint'] ) && '' !== $context['saint'] ? (string) $context['saint'] : null;
		$day               = Dismissals::day_key( $context['day'] ?? '' );
		// The feast of the day for this service, and for the day as a whole
		// (for the daily additions at Compline and the Midnight Office).
		$feast             = $info ? Feasts::find( $info, $service_key ) : null;
		$day_feast         = $info ? ( Feasts::find( $info, 'matins' ) ?? Feasts::find( $info, 'vespers' ) ) : null;
		$out               = array();
		$bookmark          = null;   // next kathisma, carried from one portion to the next
		$order_steps       = $info ? Ustav::seasonal_steps( $service_key, $info ) : null;
		$order_steps       = null !== $order_steps ? $order_steps : Orders::steps( $service['order'] );
		if ( 'cell-rule' === $service_key && ! empty( $context['bedtime'] ) ) {
			$bedtime = Ustav::extra_steps( 'bedtime' );
			if ( $bedtime ) {
				$order_steps = array_merge(
					$order_steps,
					array(
						array(
							'type'  => 'heading',
							'title' => __( 'Prayers before sleep', 'prayer-rule' ),
						),
					),
					$bedtime
				);
			}
		}
		foreach ( $order_steps as $step ) {
			if ( 'rule' === $step['type'] ) {
				$portion = $context['portions'][ $step['portion'] ] ?? array();
				if ( empty( $portion['start'] ) && null !== $bookmark ) {
					$portion['start'] = $bookmark;
				}
				$first    = min( 20, max( 1, (int) ( $portion['start'] ?? 1 ) ) );
				$bookmark = ( ( $first - 1 + max( 0, (int) ( $portion['kathismas'] ?? 0 ) ) ) % 20 ) + 1;
				$name    = $service['portions'][ $step['portion'] ]['name'] ?? $step['portion'];
				self::$phase = 'rule';
				$out     = array_merge( $out, self::rule_steps( $portion, $name, $translation, ! empty( $context['lent'] ), ! empty( $context['lent'] ) || ! empty( $context['troparia'] ) ) );
				self::$phase = 'after';
			} elseif ( 'psalm' === $step['type'] ) {
				$out[] = array(
					'type'   => 'psalm',
					'psalms' => self::psalms( $step['pieces'], $translation ),
				);
			} elseif ( 'text' === $step['type'] ) {
				$out[] = self::text_step( $info ? Ustav::substitute( $step['slug'], $info ) : $step['slug'] );
			} elseif ( 'dismissal' === $step['type'] ) {
				$slug = $feast ? $feast['slug'] : Dismissals::slug( $service_key, $day );
				if ( '' === $slug ) {
					$out[] = array(
						'type' => 'missing',
						'slug' => 'dismissal',
					);
					continue;
				}
				$text = self::text_step( $slug );
				if ( ! $feast && $info && 'text' === $text['type'] ) {
					$text['html'] = Feasts::add_prefix( $text['html'], Feasts::paschal_prefix( $info, $service_key ) );
				}
				$out[] = $text;
			} elseif ( 'additions' === $step['type'] ) {
				$slug = $info ? Dismissals::additions( $service_key, $info, 'compline' === $service_key || 'midnight' === $service_key ? $day_feast : $feast ) : Dismissals::slug( $service_key, $day, 'additions' );
				if ( '' !== $slug ) {
					$out[] = self::text_step( $slug );
				}
			} else {
				$out[] = $step;
			}
		}
		list( self::$translation, self::$saint, self::$info, self::$service, self::$phase ) = $previous;
		return $out;
	}

	/**
	 * The member's portion: kathismas from the bookmark, then ladders. With
	 * $troparia, after each kathisma the Trisagion through the Our Father, the
	 * kathisma's troparia and prayer, and, before the next kathisma, "O come".
	 */
	private static function rule_steps( $portion, $name, $translation, $lent, $troparia = false ) {
		$kathismas = max( 0, (int) ( $portion['kathismas'] ?? 0 ) );
		$start     = min( 20, max( 1, (int) ( $portion['start'] ?? 1 ) ) );
		$ladders   = max( 0, round( (float) ( $portion['ladders'] ?? 0 ) * 2 ) / 2 );
		$texts     = Services::rule_texts();
		$steps     = array();
		for ( $i = 0; $i < $kathismas; $i++ ) {
			$number   = ( ( $start - 1 + $i ) % 20 ) + 1;
			$division = Kathismas::get( $number );
			$steps[]  = array(
				'type'   => 'kathisma',
				'number' => $number,
			);
			foreach ( $division as $s => $pieces ) {
				$steps[] = array(
					'type'     => 'stasis',
					'kathisma' => $number,
					'stasis'   => $s + 1,
					'label'    => Kathismas::label( $pieces ),
					'psalms'   => self::psalms( $pieces, $translation ),
				);
				if ( '' !== $texts['after_stasis'] ) {
					$steps[] = self::text_step( $texts['after_stasis'] );
				}
			}
			if ( $troparia && '' !== $texts['after_kathisma'] ) {
				$after = self::text_step( str_replace( '{n}', (string) $number, $texts['after_kathisma'] ) );
				if ( 'text' === $after['type'] ) {
					$steps[] = self::text_step( 'trisagion-our-father' );
					$steps[] = $after;
					if ( $i < $kathismas - 1 ) {
						$steps[] = self::text_step( 'o-come' );
					}
				} else {
					$steps[] = $after;             // shown as missing in the admin preview
				}
			}
		}
		if ( $ladders > 0 ) {
			$prayer  = '' !== $texts['jesus_prayer'] ? Texts::find( $texts['jesus_prayer'] ) : null;
			$after   = '' !== $texts['after_stasis'] ? Texts::find( $texts['after_stasis'] ) : null;
			$steps[] = array(
				'type'    => 'ladders',
				'ladders' => $ladders,
				'label'   => Services::ladders_label( $ladders ),
				'html'    => $prayer ? Texts::html( $prayer ) : '',
				'after'   => $after ? Texts::html( $after ) : '',
				'lent'    => $lent,
			);
		}
		if ( ! $steps ) {
			$steps[] = array(
				'type' => 'empty-rule',
				'name' => $name,
			);
		}
		return $steps;
	}

	private static function text_step( $slug ) {
		$post = Texts::find( $slug );
		Zapevy::reset();
		if ( ! $post && '' !== Ustav::bundled_prayer( $slug ) ) {
			return array(                   // a Home Ustav prayer not yet added to the site
				'type'  => 'text',
				'slug'  => $slug,
				'title' => $slug,
				'html'  => Texts::content_html( Ustav::bundled_prayer( $slug ) ),
			);
		}
		return $post ? array(
			'type'  => 'text',
			'slug'  => $slug,
			'title' => get_the_title( $post ),
			'html'  => Texts::html( $post ),
		) : array(
			'type' => 'missing',
			'slug' => $slug,
		);
	}

	/**
	 * [psalm 50], [psalm 90-91] or [psalm 118:1-72] inside a prayer: the
	 * psalm in the translation of the reading being shown (or the default).
	 */
	public static function shortcode( $atts ) {
		$typed  = trim( implode( ' ', array_map( 'strval', (array) $atts ) ) );
		$pieces = Kathismas::pieces( $typed );
		if ( ! $pieces ) {
			return current_user_can( 'edit_pages' ) ? '<p class="pr-problem">' . esc_html( sprintf( 'Not understood: [psalm %s]', $typed ) ) . '</p>' : '';
		}
		$translation = self::$translation ? self::$translation : Psalter::default_translation();
		return self::psalms_html( self::psalms( $pieces, $translation ) );
	}

	/**
	 * [saint] inside a dismissal: the saint of the day as the calendar names
	 * him, or "Saint N." until it is known.
	 */
	public static function saint_shortcode() {
		return null !== self::$saint ? self::text( self::$saint ) : esc_html__( 'Saint', 'prayer-rule' ) . ' <em>N.</em>';
	}

	/** The day being assembled (Calendar::day()), or null without a date: for [zapev]. */
	public static function day_info() {
		return self::$info;
	}

	/** The service being assembled ('' outside a service). */
	public static function service_key() {
		return self::$service;
	}

	/** before | rule | after: where the service being assembled has got to. */
	public static function phase() {
		return self::$phase;
	}

	/**
	 * Typographer's quotes, apostrophes and dashes for display (WordPress's
	 * wptexturize): "Morning Prayers" becomes “Morning Prayers”, saint's
	 * becomes saint’s. HTML in, HTML out: tags, attributes, shortcodes and
	 * <code>/<pre> are left alone. It runs even where the site has turned
	 * texturizing off, since the theme's font draws a plain " as a closing
	 * quote. Only for what is shown: never for form values, URLs or keys,
	 * and never for the stored text, which stays as typed.
	 */
	public static function curly( $html ) {
		$html = (string) $html;
		if ( '' === $html ) {
			return '';
		}
		static $site = null;
		if ( null === $site ) {
			$site = false !== apply_filters( 'run_wptexturize', true );
		}
		// Where a theme or plugin has turned wptexturize off, it cannot be turned back on
		// for one call, so the quotes are curled here instead (dashes and the rest left as typed).
		if ( ! $site ) {
			return self::quotes( $html );
		}
		// wptexturize takes an apostrophe straight after an inline tag (<em>God</em>'s) for an opening quote.
		return preg_replace( '#(</(?:em|i|strong|b|span|a|small)>)&\#8216;(?=\w)#u', '$1&#8217;', wptexturize( $html ) );
	}

	/** Curled quotes and apostrophes in the text of some HTML (not in tags, shortcodes, code or scripts). */
	private static function quotes( $html ) {
		$parts = preg_split( '/(<[^>]*>|\[[^\]]*\])/', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		$prev  = ' ';                                       // the character before: what opens a quote
		$skip  = '';
		foreach ( $parts as $i => $part ) {
			if ( 1 === $i % 2 ) {                              // a tag or a shortcode
				if ( preg_match( '#^<(/?)(code|pre|kbd|script|style|tt)\b#i', $part, $m ) ) {
					$skip = '' === $m[1] ? strtolower( $m[2] ) : '';
				}
				if ( preg_match( '#^</?(p|br|div|h[1-6]|li|ul|ol|section|blockquote|td|th|tr)\b#i', $part ) ) {
					$prev = ' ';
				}
				$out .= $part;
				continue;
			}
			if ( '' === $part || '' !== $skip ) {
				$out .= $part;
				continue;
			}
			$text = preg_replace( '/(^|[\s(\[{\x{2014}\x{2013}-])"/u', '$1' . "\u{201C}", ( preg_match( '/[\s(\[{\x{2014}\x{2013}-]$/u', $prev ) ? ' ' : 'x' ) . $part );
			$text = str_replace( '"', "\u{201D}", $text );
			$text = preg_replace( '/(^|[\s(\[{\x{2014}\x{2013}-])\'/u', '$1' . "\u{2018}", $text );
			$text = str_replace( "'", "\u{2019}", $text );
			$out .= mb_substr( $text, 1 );
			$prev  = mb_substr( $part, -1 );
		}
		return $out;
	}

	/** Plain text, curled and escaped for HTML. */
	public static function text( $text ) {
		return esc_html( self::curly( $text ) );
	}

	/** Plain text, curled, as plain characters (for text the page's script shows, never for values it saves). */
	public static function plain( $text ) {
		return html_entity_decode( self::curly( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** A short line said as written; (parentheses) in red. */
	public static function say_html( $line ) {
		return preg_replace( '/\([^()]*\)/', '<span class="pr-red">$0</span>', self::text( $line ) );
	}

	/** Verses of the pieces: array( array( 'psalm' => 50, 'verses' => array( n => text ) ) ). */
	private static function psalms( $pieces, $translation ) {
		$psalms = array();
		foreach ( $pieces as $piece ) {
			list( $psalm, $from, $to ) = $piece;
			$verses = Psalter::psalm( $translation, $psalm, $from ? $from : 1, $to );
			if ( 'coverdale' === $translation ) {
				$verses = array_map( array( self::class, 'coverdale' ), $verses );
			}
			if ( $verses ) {
				$psalms[] = array(
					'psalm'  => $psalm,
					'whole'  => ! $from,
					'verses' => $verses,
				);
			}
		}
		return $psalms;
	}

	/** Psalm headings and verses; the first letter of a psalm in red. */
	private static function psalms_html( $psalms ) {
		$numbers = self::show_verse_numbers();
		$html    = '';
		foreach ( $psalms as $psalm ) {
			/* translators: %d: psalm number */
			$html .= '<h3 class="pr-psalm">' . esc_html( sprintf( __( 'Psalm %d', 'prayer-rule' ), $psalm['psalm'] ) ) . '</h3>';
			$first = true;
			foreach ( $psalm['verses'] as $number => $text ) {
				$words = esc_html( $text );
				if ( $first && $psalm['whole'] ) {
					$words = self::initial( $text );
				}
				$first = false;
				$html .= '<p class="pr-verse">' . ( $numbers ? '<span class="pr-vn">' . (int) $number . '</span> ' : '' ) . $words . '</p>';
			}
		}
		return $html;
	}

	/** Escaped text with its first letter wrapped as a red initial. */
	private static function initial( $text ) {
		if ( ! preg_match( '/^(\W*)(\w)(.*)$/us', $text, $m ) ) {
			return esc_html( $text );
		}
		return esc_html( $m[1] ) . '<span class="pr-initial">' . esc_html( $m[2] ) . '</span>' . esc_html( $m[3] );
	}

	/** HTML for assembled steps. $admin shows problems instead of hiding them. */
	public static function html( $steps, $admin = false ) {
		$html = '<div class="prayer-rule-reading">';
		foreach ( $steps as $step ) {
			switch ( $step['type'] ) {
				case 'text':
					$html .= '<section class="pr-text">' . $step['html'] . '</section>';
					break;
				case 'say':
					$html .= '<p class="pr-say">' . self::say_html( $step['line'] ) . '</p>';
					break;
				case 'rubric':
					$html .= '<p class="pr-rubric">' . self::text( $step['line'] ) . '</p>';
					break;
				case 'psalm':
					$html .= '<section class="pr-psalms">' . self::psalms_html( $step['psalms'] ) . '</section>';
					break;
				case 'heading':
					$html .= '<h2 class="pr-kathisma pr-section-head">' . self::text( $step['title'] ) . '</h2>';
					break;
				case 'kathisma':
					/* translators: %d: kathisma number */
					$html .= '<h2 class="pr-kathisma">' . esc_html( sprintf( __( 'Kathisma %d', 'prayer-rule' ), $step['number'] ) ) . '</h2>';
					break;
				case 'stasis':
					/* translators: %d: stasis number */
					$html .= '<section class="pr-stasis"><p class="pr-stasis-label">' . esc_html( sprintf( __( 'Stasis %d', 'prayer-rule' ), $step['stasis'] ) ) . '</p>' . self::psalms_html( $step['psalms'] ) . '</section>';
					break;
				case 'ladders':
					$html .= '<section class="pr-ladders"><p class="pr-rubric">' . self::text( $step['label'] ) . '</p>' . $step['html'];
					if ( '' !== $step['after'] ) {
						$html .= '<p class="pr-rubric">' . esc_html__( 'After each ladder:', 'prayer-rule' ) . '</p>' . $step['after'];
					}
					if ( $step['lent'] ) {
						$html .= '<p class="pr-rubric">' . esc_html__( 'In Great Lent, make 17 prostrations to the ground for each ladder.', 'prayer-rule' ) . '</p>';
					}
					$html .= '</section>';
					break;
				case 'empty-rule':
					if ( $admin ) {
						/* translators: %s: portion name */
						$html .= '<p class="pr-problem">' . esc_html( sprintf( __( '%s: no kathismas or ladders chosen.', 'prayer-rule' ), $step['name'] ) ) . '</p>';
					}
					break;
				case 'missing':
					if ( $admin ) {
						/* translators: %s: a prayer's short name */
						$html .= '<p class="pr-problem">' . esc_html( sprintf( __( 'Missing prayer: %s', 'prayer-rule' ), $step['slug'] ) ) . '</p>';
					}
					break;
				case 'unknown':
					if ( $admin ) {
						/* translators: %s: the line as typed */
						$html .= '<p class="pr-problem">' . esc_html( sprintf( __( 'Not understood: %s', 'prayer-rule' ), $step['line'] ) ) . '</p>';
					}
					break;
			}
		}
		return $html . '</div>';
	}

	/**
	 * The five Typikon signs (U+1F540-1F544: great feast, vigil, polyeleos,
	 * doxology, six stichera) as an embedded font, so they show on phones
	 * that have no font for them. A 2 KB subset of GNU FreeSerif (GPLv3
	 * with font exception), the same as the calendar plugin's, inlined so
	 * that caching plugins cannot break its path.
	 */
	public static function sign_css() {
		static $css = null;
		if ( null === $css ) {
			$path = PRAYER_RULE_DIR . 'assets/fonts/typikon-signs.woff2';
			$data = is_readable( $path ) ? base64_encode( (string) file_get_contents( $path ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
			$css  = ( '' === $data ? '' : '@font-face{font-family:"Prayer Rule Typikon";src:url(data:font/woff2;base64,' . $data . ') format("woff2");unicode-range:U+1F540-1F544;font-display:block}' )
				. '.pr-sign{font-family:"Prayer Rule Typikon","Segoe UI Symbol","Noto Sans Symbols",Symbola,serif;font-style:normal;font-weight:normal}';
		}
		return $css;
	}

	/** Escaped text with each Typikon sign wrapped to use the embedded font. */
	public static function signs( $escaped ) {
		return preg_replace( '/[\x{1F540}-\x{1F544}]/u', '<span class="pr-sign">$0</span>', $escaped );
	}

	/** The reading styles (preview, members' page, prayer editor). */
	public static function css() {
		$red = self::RED;
		return self::sign_css() . ".prayer-rule-reading{--pr-red:{$red};max-width:42em}
.prayer-rule-reading .pr-kathisma{color:var(--pr-red);text-align:center;font-size:1.25em;margin:2em 0 .5em}
.prayer-rule-reading .pr-stasis-label{color:var(--pr-red);font-size:.8em;text-transform:uppercase;letter-spacing:.06em;margin:1.6em 0 0}
.prayer-rule-reading .pr-psalm{color:var(--pr-red);font-size:1.05em;margin:1.2em 0 .4em}
.prayer-rule-reading .pr-verse{margin:0 0 .35em}
.prayer-rule-reading .pr-comm-links{font-size:.72em;font-weight:normal;margin-left:.7em;white-space:nowrap}
.prayer-rule-reading .pr-comm{color:#8c8f94;font-style:italic;text-decoration:underline dotted;text-underline-offset:.2em}
.prayer-rule-reading .pr-comm:hover{color:var(--pr-red)}
.prayer-rule-reading .pr-say::first-letter{color:var(--pr-red)}
.prayer-rule-reading .pr-vn{font-size:.7em;color:#8c8f94;vertical-align:super;margin-right:.2em}
.pr-initial,.prayer-rule-reading .is-style-prayer::first-letter,.editor-styles-wrapper .is-style-prayer::first-letter{color:{$red}}
.pr-red,.pr-rubric,.is-style-rubric{color:{$red}}
.pr-fasting{border-left:4px solid var(--pr-red,#921a0a);background:rgba(146,26,10,.06);padding:.6em 1em;margin:.8em 0 1.4em}
.pr-fasting.is-free{background:transparent;padding:.2em 0 .2em 1em}
.pr-fasting p{margin:.15em 0}
.pr-fasting .pr-fasting-label{color:var(--pr-red,#921a0a);font-size:.8em;text-transform:uppercase;letter-spacing:.06em}
.pr-fasting summary{cursor:pointer;color:var(--pr-red,#921a0a)}
.pr-fasting details details{margin-top:.6em}
.prayer-rule-reading .pr-problem{background:#fcf0f1;border-left:4px solid #d63638;padding:.4em .8em;color:#1d2327}";
	}
}
