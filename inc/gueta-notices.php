<?php
/**
 * What WooCommerce says, in this shop's words.
 *
 * The translation calls it a basket, the rest of this site calls it a cart,
 * and a shopper reading both in one sitting wonders whether they are two
 * different things. The add to cart notice also pointed at a cart page that no
 * longer exists here, so it now points at the only step that is left.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrite the added to cart notice.
 *
 * Built from the products rather than patched, so the wording is ours whatever
 * the translation says, and so the link goes where there is somewhere to go.
 *
 * @param string $message  Notice WooCommerce built.
 * @param array  $products Product ids mapped to quantities.
 * @return string
 */
function gueta_add_to_cart_message( $message, $products = [] ) {
	if ( ! gueta_has_woocommerce() || ! is_array( $products ) || ! $products ) {
		return $message;
	}

	$names = [];

	foreach ( array_keys( $products ) as $product_id ) {
		$product = wc_get_product( $product_id );

		if ( $product ) {
			$names[] = sprintf( '&ldquo;%s&rdquo;', wp_strip_all_tags( $product->get_name() ) );
		}
	}

	if ( ! $names ) {
		return $message;
	}

	$list = count( $names ) > 1 && function_exists( 'wc_format_list_of_items' )
		? wc_format_list_of_items( $names )
		: implode( ', ', $names );

	$text = sprintf( 'הוספנו את %s לעגלה.', $list );

	$link = sprintf(
		'<a href="%s" class="button wc-forward">מעבר לתשלום</a>',
		esc_url( (string) wc_get_checkout_url() )
	);

	return $text . ' ' . $link;
}
add_filter( 'wc_add_to_cart_message_html', 'gueta_add_to_cart_message', 20, 2 );

/**
 * The rest of the wording, where the translation says basket and this shop
 * says cart.
 *
 * Only strings this site actually shows are listed. Rewriting a translation
 * wholesale is how a shop ends up with sentences nobody wrote.
 *
 * @param string $translated Translated text.
 * @param string $original   Text as it appears in the source.
 * @param string $domain     Text domain.
 * @return string
 */
function gueta_cart_wording( $translated, $original, $domain ) {
	if ( 'woocommerce' !== $domain ) {
		return $translated;
	}

	$swaps = [
		'סל הקניות' => 'העגלה',
		'סל קניות'  => 'עגלה',
		'לסל הקניות' => 'לעגלה',
		'הסל שלך'   => 'העגלה שלך',
	];

	return strtr( $translated, $swaps );
}
add_filter( 'gettext', 'gueta_cart_wording', 20, 3 );

/**
 * The same, for strings the translation varies by count.
 *
 * @param string $translated Translated text.
 * @param string $single     Singular source.
 * @param string $plural     Plural source.
 * @param int    $number     Count.
 * @param string $domain     Text domain.
 * @return string
 */
function gueta_cart_wording_plural( $translated, $single, $plural, $number, $domain ) {
	return gueta_cart_wording( $translated, $single, $domain );
}
add_filter( 'ngettext', 'gueta_cart_wording_plural', 20, 5 );

/* -------------------------------------------------------------------------
 * Toasts
 *
 * The notices themselves are lifted into toasts in the browser, by
 * gueta-notices.js, so WooCommerce's own markup stays in the page for anyone
 * without the script.
 * ---------------------------------------------------------------------- */

/**
 * The toasts' script and styles, wherever WooCommerce may say something.
 *
 * @return void
 */
function gueta_notices_assets() {
	if ( ! gueta_has_woocommerce() ) {
		return;
	}

	$uri = get_stylesheet_directory_uri();

	wp_enqueue_style(
		'gueta-notices',
		$uri . '/assets/css/gueta-notices.css',
		[ 'gueta-header' ],
		gueta_asset_version( '/assets/css/gueta-notices.css' )
	);

	wp_enqueue_script(
		'gueta-notices',
		$uri . '/assets/js/gueta-notices.js',
		[],
		gueta_asset_version( '/assets/js/gueta-notices.js' ),
		true
	);

	wp_localize_script(
		'gueta-notices',
		'guetaNoticesSettings',
		[
			'endpoint' => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( 'gueta_notices' ) : '',
			'close'    => 'סגירת ההודעה',
			'titles'   => [
				'error'   => 'שגיאה',
				'success' => 'בוצע',
				'info'    => 'לתשומת לבך',
			],
		]
	);
}
add_action( 'wp_enqueue_scripts', 'gueta_notices_assets', 27 );

/**
 * Hand over, and clear, the notices a request left in the session.
 *
 * An add to cart made without leaving the page that WooCommerce turns down
 * keeps its reason in the session, where it waited for the next page to show
 * it, once for every attempt. The page asks for it at once instead, and it is
 * shown as a toast there and nowhere later.
 *
 * @return void
 */
function gueta_ajax_notices() {
	$list = [];

	if ( function_exists( 'wc_get_notices' ) && WC()->session ) {
		foreach ( wc_get_notices() as $type => $notices ) {
			foreach ( (array) $notices as $notice ) {
				$message = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : $notice;

				$list[] = [
					'type' => in_array( $type, [ 'error', 'success' ], true ) ? $type : 'info',
					'html' => wc_kses_notice( (string) $message ),
				];
			}
		}

		wc_clear_notices();
	}

	wp_send_json_success( $list );
}
add_action( 'wc_ajax_gueta_notices', 'gueta_ajax_notices' );
