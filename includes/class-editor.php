<?php
/**
 * Red lettering in the prayer editor (Prayer Rule → Prayers):
 *   - paragraph style "Prayer": the first letter in red
 *   - paragraph style "Rubric": the whole paragraph in red
 *   - toolbar button "Red": selected words in red (speaker, bows …)
 * The colour is Render::RED (#921a0a).
 */

namespace PrayerRule;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Editor {

	public static function boot() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'script' ) );
		add_action( 'enqueue_block_assets', array( __CLASS__, 'styles' ) );
	}

	private static function editing_prayer() {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		return $screen && Texts::TYPE === $screen->post_type;
	}

	public static function script() {
		if ( ! self::editing_prayer() ) {
			return;
		}
		wp_register_script( 'prayer-rule-editor', false, array( 'wp-blocks', 'wp-element', 'wp-rich-text', 'wp-block-editor', 'wp-dom-ready' ), PRAYER_RULE_VERSION, true );
		wp_enqueue_script( 'prayer-rule-editor' );
		wp_add_inline_script(
			'prayer-rule-editor',
			"(function (wp) {
	var el = wp.element.createElement;
	wp.domReady(function () {
		wp.blocks.registerBlockStyle('core/paragraph', { name: 'prayer', label: 'Prayer (red initial)' });
		wp.blocks.registerBlockStyle('core/paragraph', { name: 'rubric', label: 'Rubric (red)' });
	});
	wp.richText.registerFormatType('prayer-rule/red', {
		title: 'Red',
		tagName: 'span',
		className: 'pr-red',
		edit: function (props) {
			return el(wp.blockEditor.RichTextToolbarButton, {
				icon: 'art',
				title: 'Red',
				isActive: props.isActive,
				onClick: function () {
					props.onChange(wp.richText.toggleFormat(props.value, { type: 'prayer-rule/red' }));
				}
			});
		}
	});
})(window.wp);"
		);
	}

	/** Loaded inside the editor canvas too (enqueue_block_assets). */
	public static function styles() {
		if ( ! self::editing_prayer() ) {
			return;
		}
		wp_register_style( 'prayer-rule-editor', false, array(), PRAYER_RULE_VERSION );
		wp_enqueue_style( 'prayer-rule-editor' );
		wp_add_inline_style( 'prayer-rule-editor', Render::css() );
	}
}
