<?php
/**
 * Pages made lighter: the home page's two heaviest pieces, and the head.
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

/* -------------------------------------------------------------------------
 * Less in the head
 *
 * Every stylesheet and script in the head holds the first paint until it has
 * arrived. On a product page over a phone's connection that was two and a
 * half seconds, so what the page does not use goes, and what it uses late
 * moves to the end of the page.
 * ---------------------------------------------------------------------- */

/**
 * No emoji script. Every browser this shop sees draws emoji by itself; the
 * script only checked that, on every page.
 *
 * @return void
 */
function gueta_no_emoji() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
}
add_action( 'init', 'gueta_no_emoji' );

/**
 * The block editor's stylesheets only where the content is made of blocks.
 *
 * The pages are built in Elementor and the products were written before the
 * block editor, so on most pages these were 25 KB of rules for nothing, read
 * before anything was drawn.
 *
 * @return void
 */
function gueta_block_styles_where_used() {
	if ( is_admin() ) {
		return;
	}

	$post = is_singular() ? get_queried_object() : null;

	if ( $post instanceof WP_Post && has_blocks( $post ) ) {
		return;
	}

	foreach ( [ 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles' ] as $handle ) {
		wp_dequeue_style( $handle );
	}
}
add_action( 'wp_enqueue_scripts', 'gueta_block_styles_where_used', 100 );

/**
 * Underscore and wp-util to the end of the page. Only the variation form uses
 * them, and it runs from the end of the page already; something asked for them
 * early, and the head waited for them on every product.
 *
 * WordPress puts them back in the head by itself if a script there needs them.
 *
 * @return void
 */
function gueta_late_util_scripts() {
	foreach ( [ 'underscore', 'wp-util' ] as $handle ) {
		wp_scripts()->add_data( $handle, 'group', 1 );
	}
}
add_action( 'wp_enqueue_scripts', 'gueta_late_util_scripts', 100 );

/**
 * Stylesheets the first screen does not need, loaded without holding it up.
 *
 * The login popup's styles are wanted only once a guest heads for the
 * checkout, and Font Awesome draws only the social icons in the footer. Each
 * is fetched as a print stylesheet, which never blocks drawing, and switched
 * to all screens once it arrives.
 *
 * @param string $tag    Link tag.
 * @param string $handle Style handle.
 * @return string
 */
function gueta_late_styles( $tag, $handle ) {
	if ( is_admin() || ! in_array( $handle, [ 'gueta-login', 'elementor-icons-shared-0', 'elementor-icons-fa-brands' ], true ) ) {
		return $tag;
	}

	$late = preg_replace( "/media=(['\"])[^'\"]*\1/", "media='print' onload=\"this.media='all'\"", $tag, 1, $count );

	if ( ! $count ) {
		return $tag;
	}

	return $late . '<noscript>' . $tag . '</noscript>';
}
add_filter( 'style_loader_tag', 'gueta_late_styles', 10, 2 );

/**
 * Hello Elementor's own header and footer styles. The theme draws its own
 * header and Elementor the footer, so nothing on the page uses them.
 *
 * @return void
 */
function gueta_no_hello_header_footer() {
	wp_dequeue_style( 'hello-elementor-header-footer' );
}
add_action( 'wp_enqueue_scripts', 'gueta_no_hello_header_footer', 100 );

/* -------------------------------------------------------------------------
 * The site's own addresses over https
 *
 * With the WordPress address still set to http while the site is served over
 * https, WordPress wrote some of the site's own addresses as http, and the
 * browser refused them on an https page: the Arbel Hagilda fonts, the arrow
 * icons, and the search index, without which the search box finds nothing.
 * The setting itself belongs in Settings → General; this keeps the pages
 * whole until it is changed, and after.
 * ---------------------------------------------------------------------- */

/**
 * Uploaded files over the scheme the page came in on.
 *
 * @param array $dirs Upload directory data.
 * @return array
 */
function gueta_https_uploads( $dirs ) {
	if ( is_ssl() ) {
		$dirs['url']     = set_url_scheme( $dirs['url'], 'https' );
		$dirs['baseurl'] = set_url_scheme( $dirs['baseurl'], 'https' );
	}

	return $dirs;
}
add_filter( 'upload_dir', 'gueta_https_uploads' );

/**
 * Rewrite the site's own http addresses in the page to https.
 *
 * @return void
 */
function gueta_https_own_links_start() {
	if ( is_ssl() && ! is_admin() && ! wp_doing_ajax() && ! wp_is_json_request() ) {
		ob_start( 'gueta_https_own_links' );
	}
}
add_action( 'template_redirect', 'gueta_https_own_links_start', 0 );

/**
 * Output buffer callback for gueta_https_own_links_start().
 *
 * @param string $html Page.
 * @return string
 */
function gueta_https_own_links( $html ) {
	$host = wp_parse_url( home_url(), PHP_URL_HOST );

	if ( ! $host ) {
		return $html;
	}

	return str_replace(
		[ 'http://' . $host, 'http:\/\/' . $host ],
		[ 'https://' . $host, 'https:\/\/' . $host ],
		$html
	);
}

/**
 * Never fetch the checkout, the cart or the account ahead of a click.
 *
 * WordPress asks the browser to fetch a link's page the moment it is pressed
 * ("conservative" speculative loading). Pressing "לתשלום" in the cart drawer
 * fetched the checkout before the login popup had taken the shopper's
 * address, and once the address was given the browser showed that early copy:
 * the login page, or an empty email field, until a reload. These pages change
 * with the shopper's session, so they are always fetched when they are opened.
 *
 * @param string[] $paths Paths excluded already.
 * @return string[]
 */
function gueta_no_speculative_session_pages( $paths ) {
	if ( ! function_exists( 'wc_get_page_id' ) ) {
		return $paths;
	}

	foreach ( [ 'checkout', 'cart', 'myaccount' ] as $page ) {
		$url = get_permalink( wc_get_page_id( $page ) );

		if ( ! $url ) {
			continue;
		}

		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		// A Hebrew slug may be written with its escapes in either case.
		foreach ( array_unique( [ $path, strtolower( $path ), strtoupper( $path ), rawurldecode( $path ) ] ) as $form ) {
			$paths[] = rtrim( $form, '/' ) . '/*';
		}
	}

	return array_values( array_unique( $paths ) );
}
add_filter( 'wp_speculation_rules_href_exclude_paths', 'gueta_no_speculative_session_pages' );
