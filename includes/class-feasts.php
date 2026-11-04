<?php
/**
 * The Home Ustav's dismissals for feasts and for the Triodion and
 * Pentecostarion (data/ustav.json "feasts", from tools/ustav/feasts.txt).
 *
 * A feast's dismissal takes the place of the weekday one at Vespers (of
 * the feast's day, read the evening before), Matins and the Hours. On a
 * Sunday the Sunday dismissal stays, unless the feast is one that takes
 * the Sunday's place (the great feasts of the Lord and the Sundays of the
 * Triodion and of the holy fathers).
 *
 * From Thomas Sunday to the leave-taking of Pascha, the weekday dismissal
 * begins "O Thou who didst rise from the dead".
 *
 * The dismissals themselves are ordinary Prayers, editable on the site.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Feasts {

	/** Feast rules from the data file. */
	public static function rules() {
		return Ustav::data()['feasts'] ?? array();
	}

	/**
	 * The feast for a service on a day (Calendar::day()), or null:
	 *   array( 'slug' => short name of the dismissal, 'title' => …,
	 *          'sunday' => true when it takes the Sunday's place,
	 *          'lordly' => a great feast of the Lord,
	 *          'additions' => 'feast' | 'weekly' (daily additions as on an ordinary day) )
	 * Order of precedence: Triodion/Pentecostarion, the movable Sundays
	 * (holy fathers, forefathers …), then the fixed date.
	 */
	public static function find( $info, $service ) {
		$sunday = 0 === (int) $info['weekday'];
		foreach ( self::candidates( $info, $service ) as $rule ) {
			if ( $sunday && 'feast' !== $rule['on_sunday'] ) {
				if ( 'weekly' === $rule['on_sunday'] ) {
					continue;                                  // the Sunday dismissal wins
				}
				return self::found( $rule, $rule['on_sunday'], true );   // a dismissal for the feast on a Sunday
			}
			return self::found( $rule, $rule['slug'], $sunday );
		}
		return null;
	}

	private static function found( $rule, $slug, $sunday ) {
		return array(
			'slug'      => $slug,
			'title'     => $rule['title'],
			'sunday'    => $sunday,
			'lordly'    => ! empty( $rule['lordly'] ),
			'additions' => $rule['additions'] ?? 'feast',
			'fast'      => $rule['fast'] ?? '',                  // lord, god-bearer, icon or saint (Fasting)
		);
	}

	/** Matching rules, highest precedence first. */
	private static function candidates( $info, $service ) {
		$pascha  = array();
		$sundays = array();
		$fixed   = array();
		$jm      = (int) $info['julian']['m'];
		$jd      = (int) $info['julian']['d'];
		foreach ( self::rules() as $rule ) {
			foreach ( $rule['pascha'] as $p ) {
				list( $from, $to, $limit ) = $p;
				$offset = $from < 0 ? (int) $info['before_pascha'] : (int) $info['after_pascha'];
				$allow  = $limit ? $limit : $rule['services'];
				if ( $offset >= $from && $offset <= $to && in_array( $service, $allow, true ) ) {
					$pascha[] = $rule;
					continue 2;
				}
			}
			if ( ! in_array( $service, $rule['services'], true ) ) {
				continue;
			}
			foreach ( $rule['sunday'] as $s ) {
				list( $m1, $d1, $m2, $d2 ) = $s;
				if ( 0 === (int) $info['weekday'] && self::between( $jm, $jd, $m1, $d1, $m2, $d2 ) ) {
					$sundays[] = $rule;
					continue 2;
				}
			}
			foreach ( $rule['menaion'] as $md ) {
				if ( $jm === (int) $md[0] && $jd === (int) $md[1] ) {
					$fixed[] = $rule;
					continue 2;
				}
			}
		}
		return array_merge( $pascha, $sundays, $fixed );
	}

	private static function between( $m, $d, $m1, $d1, $m2, $d2 ) {
		$x = $m * 100 + $d;
		$a = $m1 * 100 + $d1;
		$b = $m2 * 100 + $d2;
		return $a <= $b ? ( $x >= $a && $x <= $b ) : ( $x >= $a || $x <= $b );
	}

	/** "O Thou who didst rise from the dead: " when it begins the weekday dismissal today, else ''. */
	public static function paschal_prefix( $info, $service ) {
		$p = Ustav::data()['paschal_prefix'] ?? null;
		if ( ! $p || ! in_array( $service, $p['services'], true ) ) {
			return '';
		}
		$after = (int) $info['after_pascha'];
		return $after >= (int) $p['from'] && $after <= (int) $p['to'] ? (string) $p['text'] : '';
	}

	/** Put the prefix before "O Lord Jesus Christ" at the start of a dismissal's HTML. */
	public static function add_prefix( $html, $prefix ) {
		if ( '' === $prefix || false !== stripos( wp_strip_all_tags( $html ), trim( $prefix, ': ' ) ) ) {
			return $html;
		}
		return preg_replace( '/(<p[^>]*>)(\s*)O Lord Jesus Christ/u', '$1$2' . esc_html( $prefix ) . 'O Lord Jesus Christ', $html, 1 );
	}

	/** All rules as rows for the admin list: when, title, short name. */
	public static function listing() {
		global $wp_locale;
		$rows = array();
		foreach ( self::rules() as $rule ) {
			$when = array();
			foreach ( $rule['menaion'] as $md ) {
				$when[] = $wp_locale->get_month( $md[0] ) . ' ' . $md[1];
			}
			foreach ( $rule['sunday'] as $s ) {
				/* translators: 1, 2: church dates */
				$when[] = sprintf( __( 'Sunday between %1$s and %2$s', 'prayer-rule' ), $wp_locale->get_month( $s[0] ) . ' ' . $s[1], $wp_locale->get_month( $s[2] ) . ' ' . $s[3] );
			}
			foreach ( $rule['pascha'] as $p ) {
				$range  = $p[0] === $p[1] ? self::offset( $p[0] ) : self::offset( $p[0] ) . ' – ' . self::offset( $p[1] );
				$when[] = $range . ( $p[2] ? ' (' . implode( ', ', $p[2] ) . ')' : '' );
			}
			$rows[] = array(
				'when'      => implode( '; ', $when ),
				'title'     => ucfirst( preg_replace( '/^[A-Z][a-z]{2} \d+, /', '', $rule['title'] ) ),
				'slug'      => $rule['slug'],
				'on_sunday' => $rule['on_sunday'],
			);
		}
		return $rows;
	}

	private static function offset( $n ) {
		/* translators: %d: days */
		return 0 === $n ? __( 'Pascha', 'prayer-rule' ) : sprintf( $n < 0 ? __( 'Pascha −%d', 'prayer-rule' ) : __( 'Pascha +%d', 'prayer-rule' ), abs( $n ) );
	}
}
