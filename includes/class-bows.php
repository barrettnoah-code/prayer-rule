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
 * The feast days are those of Chapter 3, which sets the cell apart from the
 * church: on great feasts (the calendar's 🕀) there is no kneeling "either in
 * church or in the cells"; on middle feasts (vigil 🕁, polyeleos 🕂) there are
 * no prostrations in church, but "in private, in the cell, each does as he
 * chooses"; on lesser feasts prostrations are made in private. So at home a
 * middle feast keeps its prostrations, except the entrance and departing
 * bows, which the Psalter printed under Patriarch Joseph (in Chapter 2) makes
 * waist bows on a polyeleos saint and on the eves of saints with an
 * entrance, Old Testament readings and the Great Doxology.
 *
 * In a prayer, [bow] after a line shows "(prostration)" or "(bow)":
 *   [bow]            a bow in the service
 *   [bow entrance]   a bow of the entrance opening
 *   [bow departing]  a bow of the departing opening
 *   [bow rule]       a bow of the rule itself (the cell rule, the zapevy)
 *   [bow great]      the great prostration after "It is truly meet": always
 *   [bow waist]      always a waist bow ("Through the prayers of our holy fathers")
 *   [bow rule each]  a prayer said over and over: "(with each prostration)" / "(with each bow)"
 *   [bow 3]          "(three prostrations)" / "(three bows)"
 *   [bow thrice]     a short prayer said three times: "(thrice, with prostrations)"
 *   [bow word]       the bare word, for a rubric: "Make a [bow word] and say …"
 *   [bow rule words] the bare plural: "as many [bow rule words] as you wish"
 *   [bow rule word 50] a number with the word: "50 prostrations" / "50 bows"
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
	 * A middle feast (a saint with a vigil or polyeleos) on a day that is not
	 * already one of waist bows, outside Holy Week: the entrance and
	 * departing bows are waist bows, the rest prostrations. In Great Lent too:
	 * ch. 2 keeps Lent's prostrations "except on Saturdays, Sundays and feasts
	 * of saints".
	 */
	public static function middle_feast( $info ) {
		$rank   = (int) ( $info['rank'] ?? 9 );
		$before = (int) $info['before_pascha'];
		if ( 2 !== $rank && 3 !== $rank ) {
			return false;
		}
		if ( $before >= -6 && $before <= -1 ) {
			return false;                         // Holy Week: the entrance and departing bows are to the ground "until the Resurrection service"
		}
		return '' === self::waist_reason( $info );
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
		if ( 'waist' === $class ) {
			return 'bow';
		}
		$info = Render::day_info();
		if ( ! $info ) {
			return 'prostration';
		}
		$service = Render::service_key();
		if ( self::great_saturday_evening( $info, $service ) ) {
			// Vespers, Compline and the burial canon of Pascha's day, read on Great
			// Saturday evening: the service has waist bows; until the Resurrection
			// matins the entrance and departing bows and the rule are to the ground.
			return in_array( $class, array( 'entrance', 'departing', 'rule' ), true ) ? 'prostration' : 'bow';
		}
		if ( '' !== self::waist_reason( $info ) ) {
			return 'bow';
		}
		if ( ( 'entrance' === $class || 'departing' === $class ) && self::middle_feast( $info ) ) {
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

	private static function great_saturday_evening( $info, $service ) {
		return 0 === (int) $info['after_pascha'] && in_array( $service, array( 'vespers', 'compline', 'midnight' ), true );
	}

	/** The red mark after a line, for a class. */
	public static function tag( $class = '', $each = false ) {
		return self::render( self::kind( $class ), 1, false, false, false, $each );
	}

	private static function render( $kind, $count, $word, $thrice = false, $plural = false, $each = false ) {
		if ( $each ) {   // a zapev or petition repeated: once with every bow
			$text = 'bow' === $kind ? __( 'with each bow', 'prayer-rule' ) : __( 'with each prostration', 'prayer-rule' );
			return '<span class="pr-red pr-bow">(' . esc_html( $text ) . ')</span>';
		}
		if ( $thrice ) {
			$text = 'bow' === $kind ? __( 'thrice, with bows', 'prayer-rule' ) : __( 'thrice, with prostrations', 'prayer-rule' );
			return '<span class="pr-red pr-bow">(' . esc_html( $text ) . ')</span>';
		}
		$numbers = array( 2 => __( 'two', 'prayer-rule' ), 3 => __( 'three', 'prayer-rule' ), 4 => __( 'four', 'prayer-rule' ), 12 => __( 'twelve', 'prayer-rule' ) );
		if ( 'great' === $kind ) {
			$text = __( 'great prostration', 'prayer-rule' );
		} elseif ( $plural ) {
			$text = 'bow' === $kind ? __( 'bows', 'prayer-rule' ) : __( 'prostrations', 'prayer-rule' );
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
		$many  = false;
		$each  = false;
		foreach ( (array) $atts as $att ) {
			$att = strtolower( trim( (string) $att ) );
			if ( ctype_digit( $att ) ) {
				$count = max( 1, (int) $att );
			} elseif ( 'thrice' === $att ) {
				$three = true;
			} elseif ( 'word' === $att ) {
				$word = true;
			} elseif ( 'each' === $att ) {
				$each = true;
			} elseif ( 'words' === $att ) {
				$word = true;
				$many = true;
			} elseif ( in_array( $att, array( 'entrance', 'departing', 'rule', 'great', 'waist' ), true ) ) {
				$class = $att;
			}
		}
		return self::render( self::kind( $class ), $count, $word, $three, $many, $each );
	}

	/** A line for the top of the day: what the bows are today, and why. */
	/**
	 * "Bows today" as a box, with the Ustav's rule on bows behind "The rule",
	 * as the fasting box has the rule on fasting.
	 */
	public static function html( $info ) {
		$line = preg_replace( '/^' . preg_quote( __( 'Bows today:', 'prayer-rule' ), '/' ) . '\s*/u', '', self::summary( $info ) );
		$line = mb_strtoupper( mb_substr( $line, 0, 1 ) ) . mb_substr( $line, 1 );
		$html = '<div class="pr-fasting pr-bows"><p class="pr-fasting-label">' . esc_html__( 'Bows today', 'prayer-rule' ) . '</p>';
		$html .= '<p class="pr-fasting-today">' . Render::text( $line ) . '</p>';
		$rule = Texts::find( 'bows-rule' );
		if ( $rule ) {
			$html .= '<details class="pr-fasting-rule"><summary>' . esc_html__( 'The rule', 'prayer-rule' ) . '</summary>' . Render::signs( Texts::html( $rule ) ) . '</details>';
		}
		return $html . '</div>';
	}

	public static function summary( $info ) {
		$why = self::waist_reason( $info );
		if ( 0 === (int) $info['after_pascha'] ) {
			return __( 'Bows today: waist bows (Pascha). Vespers, Compline and the burial canon, read on the evening of Great Saturday, keep the entrance and departing bows and the bows of the rule to the ground. The great prostration after "Shine, shine" is always made.', 'prayer-rule' );
		}
		if ( '' !== $why ) {
			/* translators: %s: the reason, e.g. Sunday */
			return sprintf( __( 'Bows today: waist bows, not to the ground (%s). The great prostration after "It is truly meet" (in Bright Week, "Shine, shine") is always made.', 'prayer-rule' ), $why );
		}
		if ( self::middle_feast( $info ) ) {
			return __( 'Bows today: prostrations to the ground, except the entrance and departing bows, which are waist bows (a feast with a vigil or polyeleos). On such a day the Ustav leaves the other prostrations in private prayer to each one’s choice.', 'prayer-rule' );
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
