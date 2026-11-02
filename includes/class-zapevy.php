<?php
/**
 * The zapevy: the refrains said with each prostration, or with each prayer
 * on the lestovka, in the daily additions (data/ustav.json "zapevy", from
 * tools/ustav/zapevy.txt; Home Ustav fol. 209-225v).
 *
 * In a prayer, a line [zapev key] shows one zapev:
 *   [zapev god-bearer], [zapev angels], [zapev cross] … the days of the week
 *   [zapev feast]  the feast (or forefeast, afterfeast) of the day
 *   [zapev saint]  the saint of the day: the book's zapev for the date, or
 *                  one made from the calendar's saint after the forms of the
 *                  General Menaion, or the one typed on the Saints screen.
 * A zapev already shown in the same prayer is not shown again.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Zapevy {

	const OPTION = 'prayer_rule_zapevy';   // "m-d" => zapev typed on the Saints screen

	private static $seen = array();

	/** Forget what was shown (a new prayer begins). */
	public static function reset() {
		self::$seen = array();
	}

	private static function data() {
		$z = Ustav::data()['zapevy'] ?? array();
		return array(
			'fixed' => $z['fixed'] ?? array(),
			'dated' => $z['dated'] ?? array(),
		);
	}

	/** Zapevy typed on the Saints screen. */
	public static function overrides() {
		$saved = get_option( self::OPTION, array() );
		return is_array( $saved ) ? $saved : array();
	}

	/** $posted: "m-d" => text; empty removes it. */
	public static function save_overrides( $posted ) {
		$all = self::overrides();
		foreach ( (array) $posted as $key => $text ) {
			if ( ! preg_match( '/^(1[0-2]|[1-9])-([1-9]|[12]\d|3[01])$/', (string) $key ) ) {
				continue;
			}
			$text = trim( sanitize_text_field( $text ) );
			if ( '' === $text ) {
				unset( $all[ $key ] );
			} else {
				$all[ $key ] = $text;
			}
		}
		update_option( self::OPTION, $all );
	}

	/** The book's zapevy for a day, by kind: feast, season, saint. */
	public static function dated( $info ) {
		$out = array(
			'feast'  => array(),
			'season' => array(),
			'saint'  => array(),
		);
		$jm  = (int) $info['julian']['m'];
		$jd  = (int) $info['julian']['d'];
		foreach ( self::data()['dated'] as $item ) {
			$hit = false;
			if ( isset( $item['menaion'] ) ) {
				list( $m1, $d1, $m2, $d2 ) = $item['menaion'];
				$hit = self::between( $jm, $jd, $m1, $d1, $m2, $d2 );
			} elseif ( isset( $item['sunday'] ) ) {
				list( $m1, $d1, $m2, $d2 ) = $item['sunday'];
				$hit = 0 === (int) $info['weekday'] && self::between( $jm, $jd, $m1, $d1, $m2, $d2 );
			} elseif ( isset( $item['pascha'] ) ) {
				list( $from, $to ) = $item['pascha'];
				$offset = $from < 0 ? (int) $info['before_pascha'] : (int) $info['after_pascha'];
				$hit    = $offset >= $from && $offset <= $to;
			}
			if ( $hit && isset( $out[ $item['kind'] ] ) ) {
				$out[ $item['kind'] ][] = (string) $item['text'];
			}
		}
		return $out;
	}

	private static function between( $m, $d, $m1, $d1, $m2, $d2 ) {
		$x = $m * 100 + $d;
		$a = $m1 * 100 + $d1;
		$b = $m2 * 100 + $d2;
		return $a <= $b ? ( $x >= $a && $x <= $b ) : ( $x >= $a || $x <= $b );
	}

	/**
	 * The saint's zapevy for a day: typed on the Saints screen, else the
	 * book's, else made from the calendar's saint (not on a feast day).
	 * Returns array( lines, source ) with source 'set' | 'book' | 'made' | ''.
	 */
	public static function saint_lines( $info, $use_set = true ) {
		$key  = (int) $info['julian']['m'] . '-' . (int) $info['julian']['d'];
		$set  = $use_set ? ( self::overrides()[ $key ] ?? '' ) : '';
		$book = self::dated( $info );
		if ( '' !== $set ) {
			return array( array( $set ), 'set' );
		}
		if ( $book['saint'] ) {
			return array( $book['saint'], 'book' );
		}
		if ( $book['feast'] || ! empty( $info['great'] ) ) {
			return array( array(), '' );
		}
		$made = self::from_name( (string) ( $info['saint'] ?? '' ) );
		return '' !== $made ? array( array( $made ), 'made' ) : array( array(), '' );
	}

	/** The feast's zapevy for a day; the general one when the book names none. */
	public static function feast_lines( $info ) {
		$book  = self::dated( $info );
		$lines = array_merge( $book['feast'], $book['season'] );
		return $lines ? $lines : array( (string) ( self::data()['fixed']['feast'] ?? '' ) );
	}

	/**
	 * A zapev made from a saint as named in the dismissal, after the forms
	 * of the General Menaion: "the holy great-martyr Demetrius the
	 * Myrrh-streaming" => "O holy great-martyr Demetrius the Myrrh-streaming,
	 * pray to God for us." Returns '' when the name is not a saint's.
	 */
	public static function from_name( $name ) {
		$s = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $name ) ) );
		$s = preg_replace( '/^(the|our)\s+/iu', '', $s );
		$s = preg_replace( '/^saint\s+/iu', '', $s );
		if ( '' === $s ) {
			return '';
		}
		// Feasts, icons and the like are not saints.
		if ( preg_match( '/^(synaxis|icon|translation|transfer|finding|placing|dedication|consecration|renewal|leave-taking|afterfeast|forefeast|feast|nativity|conception|dormition|beheading|procession|exaltation|entry|meeting|transfiguration|circumcision|theophany|annunciation|protection|appearance|sunday|saturday|week|great|holy\s+(and\s+)?great\s+(monday|tuesday|wednesday|thursday|friday|saturday))\b/iu', $s ) ) {
			return '';
		}
		$bishop = (bool) preg_match( '/\b(arch)?bishop|\bpatriarch|\bmetropolitan|\bpope\b/iu', $s );
		$parts  = explode( ',', $s, 2 );
		$head   = trim( $parts[0] );
		$head   = preg_replace( '/\s+and\s+(with\s+(him|her|them)|those\s+with\s+(him|her|them))\b.*$/iu', '', $head );
		$head   = preg_replace( '/[\s.;:]+$/u', '', $head );

		// pattern at the start of the name => title (singular, plural)
		$forms = array(
			'/^holy\s+(and\s+)?great[- ]martyrs\s+/iu'                         => 'holy great-martyrs',
			'/^holy\s+(and\s+)?great[- ]martyr\s+/iu'                          => 'holy great-martyr',
			'/^holy\s+hieromartyrs\s+/iu'                                      => 'holy hieromartyrs',
			'/^holy\s+hieromartyr\s+/iu'                                       => 'holy hieromartyr',
			'/^(holy\s+)?(venerable|monastic)[- ]martyrs\s+/iu'                => 'holy monastic martyrs',
			'/^(holy\s+)?(venerable|monastic)[- ]martyr\s+/iu'                 => 'holy monastic martyr',
			'/^holy\s+martyrs\s+and\s+confessors\s+/iu'                        => 'holy martyrs and confessors',
			'/^holy\s+martyrs\s+/iu'                                           => 'holy martyrs',
			'/^holy\s+martyr\s+/iu'                                            => $bishop ? 'holy hieromartyr' : 'holy martyr',
			'/^holy\s+(and\s+)?(glorious\s+)?(all-praised\s+)?apostles\s+/iu'  => 'holy apostles',
			'/^holy\s+(and\s+)?(glorious\s+)?(all-praised\s+)?apostle\s+and\s+evangelist\s+/iu' => 'holy apostle and evangelist',
			'/^holy\s+(and\s+)?(glorious\s+)?(all-praised\s+)?apostle\s+/iu'   => 'holy apostle',
			'/^holy\s+(and\s+glorious\s+)?prophetess\s+/iu'                    => 'holy prophetess',
			'/^holy\s+(and\s+glorious\s+)?prophets\s+/iu'                      => 'holy prophets',
			'/^holy\s+(and\s+glorious\s+)?prophet\s+/iu'                       => 'holy prophet',
			'/^(holy\s+)?fathers?\s+among\s+the\s+saints\s+/iu'                => 'hierarch of Christ',
			'/^(holy\s+)?hierarchs\s+/iu'                                      => 'holy hierarchs',
			'/^(holy\s+)?hierarch\s+(of\s+christ\s+)?/iu'                      => 'hierarch of Christ',
			'/^(holy\s+and\s+)?venerable\s+fathers\s+/iu'                      => 'venerable fathers',
			'/^(holy\s+and\s+)?venerable\s+father\s+and\s+confessor\s+/iu'     => $bishop ? 'hierarch of Christ and confessor' : 'venerable father and confessor',
			'/^(holy\s+and\s+)?venerable\s+father\s+/iu'                       => $bishop ? 'hierarch of Christ' : 'venerable father',
			'/^(holy\s+and\s+)?venerable\s+mothers\s+/iu'                      => 'holy and venerable mothers',
			'/^(holy\s+and\s+)?venerable\s+mother\s+/iu'                       => 'venerable mother',
			'/^(holy\s+and\s+)?venerable\s+/iu'                                => $bishop ? 'hierarch of Christ' : 'venerable',
			'/^holy\s+(and\s+)?right-believing\s+(great\s+)?princes\s+/iu'     => 'holy right-believing princes',
			'/^holy\s+(and\s+)?right-believing\s+(great\s+)?princess\s+/iu'    => 'holy right-believing princess',
			'/^holy\s+(and\s+)?right-believing\s+(great\s+)?prince\s+/iu'      => 'holy right-believing prince',
			'/^holy\s+passion-bearers\s+/iu'                                   => 'holy passion-bearers',
			'/^holy\s+passion-bearer\s+/iu'                                    => 'holy passion-bearer',
			'/^holy\s+(wonderworkers\s+and\s+)?unmercenaries\s+/iu'            => 'holy wonderworkers and unmercenaries',
			'/^holy\s+equals?-to-the-apostles\s+/iu'                           => 'holy equal-to-the-apostles',
			'/^holy\s+confessor\s+/iu'                                         => $bishop ? 'hierarch of Christ and confessor' : 'holy confessor',
			'/^(holy\s+(and\s+)?)?righteous\s+/iu'                             => 'holy and righteous',
		);
		$title = '';
		$rest  = $head;
		foreach ( $forms as $pattern => $form ) {
			if ( preg_match( $pattern, $head, $m ) ) {
				$title = $form;
				$rest  = trim( mb_substr( $head, mb_strlen( $m[0] ) ) );
				break;
			}
		}
		if ( '' === $title ) {
			if ( $bishop ) {
				$title = 'hierarch of Christ';
			} elseif ( preg_match( '/^holy\s+/iu', $head, $m ) ) {
				$title = 'holy';
				$rest  = trim( mb_substr( $head, mb_strlen( $m[0] ) ) );
			} else {
				$title = 'holy';
			}
		}
		if ( '' === $rest ) {
			return '';
		}
		return 'O ' . $title . ' ' . $rest . ', pray to God for us.';
	}

	/** [zapev key] */
	public static function shortcode( $atts ) {
		$key  = sanitize_key( (string) ( ( (array) $atts )[0] ?? '' ) );
		$info = Render::day_info();
		if ( 'saint' === $key ) {
			$lines = array();
			if ( $info ) {
				$book  = self::dated( $info );
				$lines = array_merge( $book['feast'], $book['season'], self::saint_lines( $info )[0] );
			}
			if ( ! $info || ( ! $lines && '' === (string) ( $info['saint'] ?? '' ) && empty( $info['great'] ) ) ) {
				return self::line( __( 'O holy', 'prayer-rule' ) . ' <em>N.</em>, ' . __( 'pray to God for us.', 'prayer-rule' ), true );
			}
		} elseif ( 'feast' === $key ) {
			$lines = $info ? self::feast_lines( $info ) : array( (string) ( self::data()['fixed']['feast'] ?? '' ) );
		} else {
			$fixed = self::data()['fixed'];
			if ( ! isset( $fixed[ $key ] ) ) {
				return current_user_can( 'edit_pages' ) ? '<p class="pr-problem">' . esc_html( sprintf( 'No such zapev: [zapev %s]', $key ) ) . '</p>' : '';
			}
			$lines = array( (string) $fixed[ $key ] );
		}
		$html = '';
		foreach ( $lines as $text ) {
			$html .= self::line( $text );
		}
		return $html;
	}

	/** One zapev as a paragraph, unless already shown in this prayer. */
	private static function line( $text, $is_html = false ) {
		$plain = trim( wp_strip_all_tags( $text ) );
		$seen  = mb_strtolower( preg_replace( '/[^\p{L}\p{N}]+/u', '', $plain ) );
		if ( '' === $plain || isset( self::$seen[ $seen ] ) ) {
			return '';
		}
		self::$seen[ $seen ] = true;
		return '<p class="is-style-prayer pr-zapev">' . ( $is_html ? $text : esc_html( $text ) ) . ' ' . Bows::tag( 'rule' ) . '</p>';
	}
}
