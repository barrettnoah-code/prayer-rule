<?php
/**
 * Bows: whether each bow in a prayer is a prostration to the ground or a
 * waist bow, worked out for the day and the service.
 *
 * The Home Ustav writes simply "a bow" (поклонъ) after each prayer, and says
 * once (fol. 149) that all of them are to the ground "except on Saturdays,
 * Sundays and feast days … throughout Pentecost and during the twelve days
 * after Christ's Nativity", when they are waist bows. Chapter 2 (fol. 30v-38v)
 * adds Holy Week, Bright Week and the evening after a Sunday or feast.
 *
 * In a prayer, [bow] after a line shows "(prostration)" or "(bow)":
 *   [bow]            a bow in the service
 *   [bow entrance]   a bow of the entrance opening
 *   [bow departing]  a bow of the departing opening
 *   [bow rule]       a bow of the rule itself (the cell rule, the zapevy)
 *   [bow great]      the great prostration after "It is truly meet": always
 *   [bow 3]          "(three prostrations)" / "(three bows)"
 *   [bow thrice]     a short prayer said three times: "(thrice, with prostrations)"
 *   [bow word]       the bare word, for a rubric: "Make a [bow word] and say …"
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bows {

	private static $days = array();

	/** Calendar::day(), kept for the request. */
	private static function day( $civil ) {
		if ( ! isset( self::$days[ $civil ] ) ) {
			self::$days[ $civil ] = Calendar::day( $civil );
		}
		return self::$days[ $civil ];
	}

	/**
	 * Why the day has waist bows instead of prostrations, or '' when its
	 * bows are to the ground.
	 */
	public static function waist_reason( $info ) {
		$after  = (int) $info['after_pascha'];
		$before = (int) $info['before_pascha'];
		$jm     = (int) $info['julian']['m'];
		$jd     = (int) $info['julian']['d'];
		if ( -1 === $before ) {
			return '';                                                     // Great Saturday: to the ground
		}
		if ( $after >= 0 && $after <= 6 ) {
			return __( 'Bright Week', 'prayer-rule' );
		}
		if ( $after >= 7 && $after <= 56 ) {
			return __( 'from Pascha to All Saints', 'prayer-rule' );
		}
		// Great Lent and Holy Week (Clean Monday to Great Friday) go by their own rule,
		// not the calendar's: prostrations except on Saturdays and Sundays, and,
		// before Holy Week, on a great feast such as the Annunciation.
		if ( $before >= -48 && $before <= -2 ) {
			if ( 0 === (int) $info['weekday'] ) {
				return __( 'Sunday', 'prayer-rule' );
			}
			if ( 6 === (int) $info['weekday'] ) {
				return __( 'Saturday', 'prayer-rule' );
			}
			if ( $before <= -8 && 1 === (int) ( $info['rank'] ?? 9 ) ) {
				return __( 'a great feast', 'prayer-rule' );
			}
			return '';
		}
		if ( ( 12 === $jm && $jd >= 25 ) || ( 1 === $jm && $jd <= 5 ) ) {
			return __( 'the twelve days after the Nativity of Christ', 'prayer-rule' );
		}
		$rank = (int) ( $info['rank'] ?? 9 );
		if ( 1 === $rank ) {
			return __( 'a great feast', 'prayer-rule' );
		}
		if ( 2 === $rank || 3 === $rank ) {
			return __( 'a feast with a vigil or polyeleos', 'prayer-rule' );
		}
		if ( ! Calendar::available() && Zapevy::dated( $info )['feast'] ) {
			return __( 'a great feast', 'prayer-rule' );
		}
		if ( 0 === (int) $info['weekday'] ) {
			return __( 'Sunday', 'prayer-rule' );
		}
		if ( 6 === (int) $info['weekday'] ) {
			return __( 'Saturday', 'prayer-rule' );
		}
		return '';
	}

	/**
	 * Holy Week from the Hours of Great Wednesday to Great Saturday: the
	 * service has waist bows; the openings and the rule stay to the ground.
	 */
	private static function holy_week( $info, $service ) {
		$before = (int) $info['before_pascha'];
		return ( $before >= -3 && $before <= -1 ) || ( -4 === $before && 'hours' === $service );
	}

	/**
	 * Vespers or Compline read on the evening of a Sunday or feast, when the
	 * next day is an ordinary one: the entrance and the beginning have waist
	 * bows, the rest prostrations.
	 */
	private static function after_feast( $info, $service ) {
		if ( ! in_array( $service, array( 'vespers', 'compline' ), true ) ) {
			return false;
		}
		return '' !== self::waist_reason( self::day( Calendar::eve( $info['civil'] ) ) );
	}

	/**
	 * 'prostration' | 'bow' | 'great' for a bow of a class, in the service
	 * being assembled (Render). Without a date: an ordinary day.
	 */
	public static function kind( $class = '' ) {
		if ( 'great' === $class ) {
			return 'great';
		}
		$info = Render::day_info();
		if ( ! $info ) {
			return 'prostration';
		}
		$service = Render::service_key();
		if ( '' !== self::waist_reason( $info ) ) {
			return 'bow';
		}
		if ( 'entrance' === $class ) {
			return self::after_feast( $info, $service ) ? 'bow' : 'prostration';
		}
		if ( 'departing' === $class || 'rule' === $class ) {
			return 'prostration';
		}
		if ( self::holy_week( $info, $service ) ) {
			return 'bow';
		}
		if ( 'before' === Render::phase() && self::after_feast( $info, $service ) ) {
			return 'bow';
		}
		return 'prostration';
	}

	/** The red mark after a line, for a class. */
	public static function tag( $class = '' ) {
		return self::render( self::kind( $class ), 1, false );
	}

	private static function render( $kind, $count, $word, $thrice = false ) {
		if ( $thrice ) {
			$text = 'bow' === $kind ? __( 'thrice, with bows', 'prayer-rule' ) : __( 'thrice, with prostrations', 'prayer-rule' );
			return '<span class="pr-red pr-bow">(' . esc_html( $text ) . ')</span>';
		}
		$numbers = array( 2 => __( 'two', 'prayer-rule' ), 3 => __( 'three', 'prayer-rule' ), 4 => __( 'four', 'prayer-rule' ), 12 => __( 'twelve', 'prayer-rule' ) );
		if ( 'great' === $kind ) {
			$text = __( 'great prostration', 'prayer-rule' );
		} elseif ( $count > 1 ) {
			$n    = $numbers[ $count ] ?? (string) $count;
			$text = $n . ' ' . ( 'bow' === $kind ? __( 'bows', 'prayer-rule' ) : __( 'prostrations', 'prayer-rule' ) );
		} else {
			$text = 'bow' === $kind ? __( 'bow', 'prayer-rule' ) : __( 'prostration', 'prayer-rule' );
		}
		return $word ? esc_html( $text ) : '<span class="pr-red pr-bow">(' . esc_html( $text ) . ')</span>';
	}

	/** [bow], [bow entrance], [bow rule 3], [bow word] … */
	public static function shortcode( $atts ) {
		$class = '';
		$count = 1;
		$word  = false;
		$three = false;
		foreach ( (array) $atts as $att ) {
			$att = strtolower( trim( (string) $att ) );
			if ( ctype_digit( $att ) ) {
				$count = max( 1, (int) $att );
			} elseif ( 'thrice' === $att ) {
				$three = true;
			} elseif ( 'word' === $att ) {
				$word = true;
			} elseif ( in_array( $att, array( 'entrance', 'departing', 'rule', 'great' ), true ) ) {
				$class = $att;
			}
		}
		return self::render( self::kind( $class ), $count, $word, $three );
	}

	/** A line for the top of the day: what the bows are today, and why. */
	public static function summary( $info ) {
		$why = self::waist_reason( $info );
		if ( '' !== $why ) {
			/* translators: %s: the reason, e.g. Sunday */
			return sprintf( __( 'Bows today: waist bows, not to the ground (%s). The great prostration after "It is truly meet" is always made.', 'prayer-rule' ), $why );
		}
		$before = (int) $info['before_pascha'];
		if ( $before >= -4 && $before <= -1 ) {
			return __( 'Bows today: waist bows in the services (from the Hours of Great Wednesday); the entrance and departing bows and the bows of the rule stay to the ground (Holy Week).', 'prayer-rule' );
		}
		$eve = self::day( Calendar::eve( $info['civil'] ) );
		if ( '' !== self::waist_reason( $eve ) ) {
			return __( 'Bows today: prostrations to the ground. At Vespers and Compline, read on the evening before, the entrance and the beginning are waist bows.', 'prayer-rule' );
		}
		return __( 'Bows today: prostrations to the ground.', 'prayer-rule' );
	}
}
