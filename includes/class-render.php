<?php
/**
 * Assembling and showing an order for one kathisma in one translation.
 * The same data feeds the admin preview now and the members' reader later.
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Render {

	const OPT_COLONS = 'prayer_rule_show_colons';

	/** Show Coverdale's half-verse colons ( : )? Default yes. */
	public static function show_colons() {
		return '0' !== (string) get_option( self::OPT_COLONS, '1' );
	}

	/**
	 * The order as data:
	 *   array( 'type' => 'text', 'slug', 'title', 'html' )
	 *   array( 'type' => 'stasis', 'stasis', 'label', 'psalms' =>
	 *          array( array( 'psalm' => 50, 'verses' => array( n => text ) ) ) )
	 *   array( 'type' => 'missing', 'slug' ) / array( 'type' => 'unknown', 'line' )
	 */
	public static function assemble( $order_id, $kathisma, $translation ) {
		$kathisma    = (int) $kathisma;
		$division    = Kathismas::get( $kathisma );
		$translation = Psalter::translation( $translation );
		$out         = array();
		if ( ! $division ) {
			return $out;
		}
		foreach ( Orders::steps( $order_id, $kathisma ) as $step ) {
			if ( 'stasis' === $step['type'] ) {
				$pieces = $division[ $step['stasis'] - 1 ];
				$out[]  = array(
					'type'   => 'stasis',
					'stasis' => $step['stasis'],
					'label'  => Kathismas::label( $pieces ),
					'psalms' => self::stasis_psalms( $pieces, $translation ),
				);
			} elseif ( 'text' === $step['type'] ) {
				$post = Texts::find( $step['slug'] );
				$out[] = $post ? array(
					'type'  => 'text',
					'slug'  => $step['slug'],
					'title' => get_the_title( $post ),
					'html'  => Texts::html( $post ),
				) : array(
					'type' => 'missing',
					'slug' => $step['slug'],
				);
			} else {
				$out[] = $step;
			}
		}
		return $out;
	}

	private static function stasis_psalms( $pieces, $translation ) {
		$psalms = array();
		foreach ( $pieces as $piece ) {
			list( $psalm, $from, $to ) = $piece;
			$verses = Psalter::psalm( $translation, $psalm, $from ? $from : 1, $to );
			if ( ! self::show_colons() ) {
				$verses = array_map(
					function ( $verse ) {
						return str_replace( ' : ', ' ', $verse );
					},
					$verses
				);
			}
			if ( $verses ) {
				$psalms[] = array(
					'psalm'  => $psalm,
					'verses' => $verses,
				);
			}
		}
		return $psalms;
	}

	/** HTML for assembled steps. $admin shows problems instead of hiding them. */
	public static function html( $steps, $kathisma, $admin = false ) {
		$html = '<div class="prayer-rule-reading">';
		foreach ( $steps as $step ) {
			switch ( $step['type'] ) {
				case 'text':
					$html .= '<section class="pr-text">' . $step['html'] . '</section>';
					break;
				case 'stasis':
					/* translators: 1: kathisma number, 2: stasis number */
					$html .= '<section class="pr-stasis"><p class="pr-stasis-label">' . esc_html( sprintf( __( 'Kathisma %1$d, stasis %2$d', 'prayer-rule' ), $kathisma, $step['stasis'] ) ) . '</p>';
					foreach ( $step['psalms'] as $psalm ) {
						/* translators: %d: psalm number */
						$html .= '<h3 class="pr-psalm">' . esc_html( sprintf( __( 'Psalm %d', 'prayer-rule' ), $psalm['psalm'] ) ) . '</h3>';
						foreach ( $psalm['verses'] as $number => $text ) {
							$html .= '<p class="pr-verse"><span class="pr-vn">' . (int) $number . '</span> ' . esc_html( $text ) . '</p>';
						}
					}
					$html .= '</section>';
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
}
