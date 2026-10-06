<?php
/**
 * The customer's account pages.
 *
 * WooCommerce's account is a bulleted list of links in the theme's pink beside
 * a paragraph of text. Here it is the shop's: a menu with an icon for each
 * page, an overview that answers what a customer comes for (where is my
 * order, what address will it go to), and the orders, addresses and forms on
 * the same tokens as the checkout. The templates are in woocommerce/myaccount/,
 * the styles in assets/css/gueta-account.css.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The account's stylesheet, on the account pages only.
 *
 * @return void
 */
function gueta_account_assets() {
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}

	wp_enqueue_style(
		'gueta-account',
		get_stylesheet_directory_uri() . '/assets/css/gueta-account.css',
		[ 'gueta-header' ],
		gueta_asset_version( '/assets/css/gueta-account.css' )
	);
}
add_action( 'wp_enqueue_scripts', 'gueta_account_assets', 30 );

/**
 * The menu: an overview rather than a "dashboard", and no downloads page in a
 * shop that sells nothing to download.
 *
 * @param array $items Label by endpoint.
 * @return array
 */
function gueta_account_menu_items( $items ) {
	if ( isset( $items['dashboard'] ) ) {
		$items['dashboard'] = 'סקירה';
	}

	if ( isset( $items['downloads'] ) && ! gueta_account_sells_downloads() ) {
		unset( $items['downloads'] );
	}

	if ( isset( $items['edit-account'] ) ) {
		$items['edit-account'] = 'פרטי חשבון';
	}

	return $items;
}
add_filter( 'woocommerce_account_menu_items', 'gueta_account_menu_items', 20 );

/**
 * Whether any product for sale is downloadable. Asked once a day.
 *
 * @return bool
 */
function gueta_account_sells_downloads() {
	$cached = get_transient( 'gueta_sells_downloads' );

	if ( false !== $cached ) {
		return 'yes' === $cached;
	}

	$found = function_exists( 'wc_get_products' ) && wc_get_products(
		[
			'downloadable' => true,
			'status'       => 'publish',
			'limit'        => 1,
			'return'       => 'ids',
		]
	);

	set_transient( 'gueta_sells_downloads', $found ? 'yes' : 'no', DAY_IN_SECONDS );

	return (bool) $found;
}

/**
 * An account page's icon.
 *
 * @param string $endpoint Endpoint.
 * @return string SVG.
 */
function gueta_account_icon( $endpoint ) {
	$paths = [
		'dashboard'       => '<rect x="4" y="4" width="6.5" height="6.5" rx="1.5"></rect><rect x="13.5" y="4" width="6.5" height="6.5" rx="1.5"></rect><rect x="4" y="13.5" width="6.5" height="6.5" rx="1.5"></rect><rect x="13.5" y="13.5" width="6.5" height="6.5" rx="1.5"></rect>',
		'orders'          => '<path d="M4 7.5 12 3.5l8 4v9l-8 4-8-4z"></path><path d="M4 7.5l8 4 8-4M12 11.5v9"></path>',
		'downloads'       => '<path d="M12 4v11m-4.5-4.5L12 15l4.5-4.5M5 19.5h14"></path>',
		'edit-address'    => '<path d="M12 20.5s-6.5-5.6-6.5-10.6a6.5 6.5 0 0 1 13 0c0 5-6.5 10.6-6.5 10.6z"></path><circle cx="12" cy="9.8" r="2.4"></circle>',
		'payment-methods' => '<rect x="3.5" y="6" width="17" height="12" rx="2"></rect><path d="M3.5 10h17M7 14.5h3"></path>',
		'edit-account'    => '<circle cx="12" cy="8" r="3.8"></circle><path d="M4.8 20.2a7.6 7.6 0 0 1 14.4 0"></path>',
		'customer-logout' => '<path d="M14 4.5h3.5a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H14"></path><path d="M10 8.5 6.5 12l3.5 3.5M6.5 12h8.5"></path>',
	];

	$path = $paths[ $endpoint ] ?? '<circle cx="12" cy="12" r="7.5"></circle>';

	return '<svg class="gueta-account-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $path . '</svg>';
}

/**
 * A status as a short label with its colour, for the overview and the orders.
 *
 * @param WC_Order $order Order.
 * @return string HTML.
 */
function gueta_account_status_pill( $order ) {
	$status = $order->get_status();

	return sprintf(
		'<span class="gueta-status gueta-status--%1$s">%2$s</span>',
		esc_attr( sanitize_html_class( $status ) ),
		esc_html( wc_get_order_status_name( $status ) )
	);
}
