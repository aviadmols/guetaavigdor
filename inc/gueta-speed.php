<?php
/**
 * The home page's two heaviest pieces, made lighter.
 *
 * The category grid on the home page is a shortcode kept in the database, and
 * it painted every card with the photo's original file as a CSS background:
 * 24 cards, up to 870 KB each, over 4 MB before a phone had scrolled at all,
 * and a background can be neither resized nor put off until it is needed.
 * The hero picture, the largest thing on the first screen, is named only in
 * Elementor's stylesheet, so the browser found it after two dozen other files.
 * And the tracking code in the head (Google, Facebook, Yandex, CreditGuard)
 * spent a second or more of a phone's processor before the shopper could
 * touch anything, so it now waits for the shopper's first touch.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Give each category card a real picture sized to the card.
 *
 * The background div stays, so its hover zoom and the card's layout carry on,
 * and a picture inside it fills it the way `background-size: cover` did. The
 * first row loads at once; the rest waits until the shopper scrolls near it.
 * A card whose photo is not in the media library keeps its background.
 *
 * @param string $output Shortcode output.
 * @return string
 */
function gueta_category_grid_images( $output ) {
	if ( ! is_string( $output ) || false === strpos( $output, 'custom-cat-bg' ) ) {
		return $output;
	}

	$index = 0;

	return preg_replace_callback(
		'#<div class="custom-cat-bg" style="background-image:\s*url\([\'"]?([^\'")]+)[\'"]?\);?\s*"></div>#',
		static function ( $match ) use ( &$index ) {
			$id = attachment_url_to_postid( $match[1] );

			if ( ! $id ) {
				return $match[0];
			}

			$image = wp_get_attachment_image(
				$id,
				'medium_large',
				false,
				[
					'alt'      => '',
					'loading'  => $index++ < 4 ? 'eager' : 'lazy',
					'decoding' => 'async',
					'sizes'    => '(max-width: 1024px) 50vw, 25vw',
					'style'    => 'display:block;width:100%;height:100%;object-fit:cover;',
				]
			);

			return $image ? '<div class="custom-cat-bg">' . $image . '</div>' : $match[0];
		},
		$output
	);
}
add_filter( 'do_shortcode_tag', 'gueta_category_grid_images' );

/**
 * Ask for the home page's hero picture before the stylesheets are read.
 *
 * Reads the picture from the page's first Elementor section, per screen size,
 * so a picture changed in Elementor is the one asked for, and each size asks
 * only for the file its own stylesheet rule will use.
 *
 * @return void
 */
function gueta_preload_hero_image() {
	if ( ! is_front_page() || ! did_action( 'elementor/loaded' ) ) {
		return;
	}

	$data     = json_decode( (string) get_post_meta( get_queried_object_id(), '_elementor_data', true ), true );
	$settings = isset( $data[0]['settings'] ) && is_array( $data[0]['settings'] ) ? $data[0]['settings'] : [];

	if ( ! $settings || 'classic' !== ( $settings['background_background'] ?? '' ) ) {
		return;
	}

	$url = static function ( $key ) use ( $settings ) {
		return empty( $settings[ $key ]['url'] ) ? '' : $settings[ $key ]['url'];
	};

	$desktop = $url( 'background_image' );
	$tablet  = $url( 'background_image_tablet' ) ? $url( 'background_image_tablet' ) : $desktop;
	$mobile  = $url( 'background_image_mobile' ) ? $url( 'background_image_mobile' ) : $tablet;

	// Elementor's own breakpoints: a phone up to 767 pixels, a tablet up to 1024.
	$screens = [
		[ $mobile, '(max-width: 767px)' ],
		[ $tablet, '(min-width: 768px) and (max-width: 1024px)' ],
		[ $desktop, '(min-width: 1025px)' ],
	];

	if ( $mobile === $desktop && $tablet === $desktop ) {
		$screens = [ [ $desktop, '' ] ];
	}

	foreach ( $screens as $screen ) {
		if ( ! $screen[0] ) {
			continue;
		}

		printf(
			'<link rel="preload" as="image" href="%s" fetchpriority="high"%s>' . "\n",
			esc_url( $screen[0] ),
			$screen[1] ? ' media="' . esc_attr( $screen[1] ) . '"' : ''
		);
	}
}
add_action( 'wp_head', 'gueta_preload_hero_image', 1 );

/**
 * Whether this request's page may have its tracking code held back.
 *
 * Not the cart, the checkout or the account: those carry the payment and the
 * purchase events, which must not wait for a touch that may never come.
 *
 * @return bool
 */
function gueta_delay_tracking_applies() {
	if ( is_admin() || wp_doing_ajax() || is_feed() || is_preview() || is_customize_preview() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['elementor-preview'] ) ) {
		return false;
	}

	if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
		return false;
	}

	return (bool) apply_filters( 'gueta_delay_tracking', true );
}

/**
 * Start holding the page, to rewrite its tracking code once it is whole.
 *
 * The page cache's own buffer was opened before WordPress loaded, so this one
 * sits inside it and the cache stores the rewritten page.
 *
 * @return void
 */
function gueta_delay_tracking_start() {
	if ( gueta_delay_tracking_applies() ) {
		ob_start( 'gueta_delay_tracking_rewrite' );
	}
}
add_action( 'template_redirect', 'gueta_delay_tracking_start', 1 );

/**
 * Turn each tracking script into one the browser neither runs nor fetches.
 *
 * A script of an unknown type is left alone by the browser, src and all. The
 * small script added before </body> gives each one back its type on the first
 * scroll, touch, key or mouse move, in the order they stood in the page.
 *
 * @param string $html The whole page.
 * @return string
 */
function gueta_delay_tracking_rewrite( $html ) {
	if ( ! is_string( $html ) || false === stripos( $html, '</body>' ) ) {
		return $html;
	}

	$hosts   = '#(googletagmanager\.com|google-analytics\.com|connect\.facebook\.net|mc\.yandex\.|pps\.creditguard\.co\.il)#i';
	$markers = '#\b(fbq|gtag|ym|ga)\s*\(|GoogleAnalyticsObject|facebook-jssdk|fbAsyncInit#';
	$delayed = 0;

	$html = preg_replace_callback(
		'#<script\b([^>]*)>(.*?)</script>#is',
		static function ( $match ) use ( $hosts, $markers, &$delayed ) {
			$attributes = $match[1];

			// Only plain JavaScript: leave JSON-LD, speculation rules, modules and templates.
			if ( preg_match( '#\btype\s*=\s*["\']?([^"\'\s>]+)#i', $attributes, $type ) && ! in_array( strtolower( $type[1] ), [ 'text/javascript', 'application/javascript' ], true ) ) {
				return $match[0];
			}

			$src = preg_match( '#\bsrc\s*=\s*["\']?([^"\'\s>]+)#i', $attributes, $found ) ? $found[1] : '';

			if ( $src ? ! preg_match( $hosts, $src ) : ! preg_match( $markers, $match[2] ) ) {
				return $match[0];
			}

			++$delayed;
			$attributes = preg_replace( '#\s\btype\s*=\s*["\']?[^"\'\s>]+["\']?#i', '', $attributes );

			return '<script type="gueta/delay"' . $attributes . '>' . $match[2] . '</script>';
		},
		$html
	);

	if ( ! $delayed ) {
		return $html;
	}

	$loader = '<script>(function(){var done=false,events=["pointerdown","keydown","touchstart","scroll","wheel","mousemove"];'
		. 'function run(){if(done)return;done=true;events.forEach(function(e){removeEventListener(e,run,{passive:true})});'
		. 'document.querySelectorAll(\'script[type="gueta/delay"]\').forEach(function(old){var s=document.createElement("script");'
		. 'for(var i=0;i<old.attributes.length;i++){var a=old.attributes[i];if(a.name!=="type")s.setAttribute(a.name,a.value)}'
		. 's.text=old.text;old.parentNode.replaceChild(s,old)})}'
		. 'events.forEach(function(e){addEventListener(e,run,{passive:true})})})();</script>';

	$at = strripos( $html, '</body>' );

	return substr( $html, 0, $at ) . $loader . substr( $html, $at );
}
