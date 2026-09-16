<?php
/**
 * Buttons keep the colours their own component gives them.
 *
 * Three rules fought over every button's hover and focus. Hello's reset.css
 * paints a hovered or focused button #c36 with white text, and to be rid of
 * the pink, two more went into Elementor's Site Settings: black text for every
 * button on hover and focus, and, in the kit's custom CSS, a transparent
 * background. Each is more specific than a class, so they beat the theme's
 * own colours: a black button lost its background and its text went black on
 * black, the sticky add to cart among them, and an icon or a pressed button
 * kept the black until something else took the focus.
 *
 * The reset now loads inside a cascade layer. A rule in a layer loses to any
 * rule outside one, whatever its specificity, so the reset still sets the
 * defaults nothing else sets but can no longer repaint a button that has
 * colours of its own. With that, the two workarounds have nothing left to fix
 * and are taken out of the kit, once.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Print Hello's reset.css as an import into a cascade layer instead of a link.
 *
 * The stylesheet stays registered and in its place in the head, so its
 * dependants and the order are unchanged. Only the tag differs.
 *
 * @param string $tag    The link tag.
 * @param string $handle Style handle.
 * @param string $href   Stylesheet URL, escaped for an attribute.
 * @param string $media  Media attribute.
 * @return string
 */
function gueta_layer_parent_reset( $tag, $handle, $href, $media ) {
	if ( 'hello-elementor' !== $handle || ! $href ) {
		return $tag;
	}

	// Inside a style element entities are not decoded, so the URL goes in raw.
	$url = esc_url_raw( wp_specialchars_decode( $href, ENT_QUOTES ) );

	if ( ! $url ) {
		return $tag;
	}

	return sprintf(
		"<style id='%s-css' media='%s'>@import url(\"%s\") layer(hello-reset);</style>\n",
		esc_attr( $handle ),
		esc_attr( $media ? $media : 'all' ),
		$url
	);
}
add_filter( 'style_loader_tag', 'gueta_layer_parent_reset', 10, 4 );

/**
 * Take the two button workarounds out of Elementor's kit, once.
 *
 * Only the exact values put there against the pink are removed: a hover text
 * colour of #000000, and the rule in the custom CSS that makes a hovered or
 * focused button transparent. Anything else in the kit is left as it is. What
 * was removed is kept in the gueta_kit_button_workarounds option, so it can be
 * put back in Site Settings by hand, and the option also marks the job done.
 *
 * @return void
 */
function gueta_remove_kit_button_workarounds() {
	if ( false !== get_option( 'gueta_kit_button_workarounds' ) || ! did_action( 'elementor/loaded' ) ) {
		return;
	}

	// add_option fails when the option exists, so a second request at the same moment stops here.
	if ( ! add_option( 'gueta_kit_button_workarounds', [ 'status' => 'running' ], '', true ) ) {
		return;
	}

	$kit_id   = (int) get_option( 'elementor_active_kit' );
	$settings = $kit_id ? get_post_meta( $kit_id, '_elementor_page_settings', true ) : null;
	$removed  = [];

	if ( is_array( $settings ) ) {
		if ( isset( $settings['button_hover_text_color'] ) && '#000000' === strtolower( (string) $settings['button_hover_text_color'] ) ) {
			$removed['button_hover_text_color'] = $settings['button_hover_text_color'];
			unset( $settings['button_hover_text_color'] );
		}

		if ( ! empty( $settings['custom_css'] ) && is_string( $settings['custom_css'] ) ) {
			$pattern = '/\[type=button\]:focus,\s*\[type=button\]:hover,\s*\[type=submit\]:focus,\s*\[type=submit\]:hover,\s*button:focus,\s*button:hover\s*\{\s*background-color:\s*transparent;?\s*\}[ \t]*\R?/';

			if ( preg_match( $pattern, $settings['custom_css'], $match ) ) {
				$removed['custom_css'] = $match[0];
				$settings['custom_css'] = preg_replace( $pattern, '', $settings['custom_css'], 1 );
			}
		}

		if ( $removed ) {
			// update_post_meta unslashes what it is given, which would eat any backslash in the CSS.
			update_post_meta( $kit_id, '_elementor_page_settings', wp_slash( $settings ) );

			// The kit's stylesheet is a file built from these settings; drop it so it is built again.
			$elementor = \Elementor\Plugin::$instance;

			if ( isset( $elementor->files_manager ) && method_exists( $elementor->files_manager, 'clear_cache' ) ) {
				$elementor->files_manager->clear_cache();
			}
		}
	}

	update_option(
		'gueta_kit_button_workarounds',
		[
			'status'  => 'done',
			'kit'     => $kit_id,
			'removed' => $removed,
			'at'      => gmdate( 'c' ),
		],
		true
	);
}
add_action( 'wp_loaded', 'gueta_remove_kit_button_workarounds' );
