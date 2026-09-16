<?php
/**
 * Orders carried over from the old shop keep their numbers.
 *
 * The move gave each order placed on the old shop after the copy a new ID, since the new site
 * had used many of those IDs for its own posts. The number the customer was sent is kept on the
 * order, and it is the number shown everywhere WooCommerce shows one: the admin, the customer's
 * account, emails and invoices. The order screens find an order by it too.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meta holding an order's number on the old shop.
 */
const GUETA_OLD_ORDER_NUMBER = '_gueta_order_number';

/**
 * The old number, for an order that has one.
 *
 * @param string   $number Number.
 * @param WC_Order $order  Order.
 * @return string
 */
function gueta_old_order_number( $number, $order ) {
	if ( ! $order instanceof WC_Order ) {
		return $number;
	}

	$old = (string) $order->get_meta( GUETA_OLD_ORDER_NUMBER );

	return '' !== $old ? $old : $number;
}
add_filter( 'woocommerce_order_number', 'gueta_old_order_number', 10, 2 );

/**
 * Search orders by the old number: the posts storage, then WooCommerce's own tables.
 *
 * @param string[] $keys Meta keys searched.
 * @return string[]
 */
function gueta_search_old_order_number( $keys ) {
	$keys[] = GUETA_OLD_ORDER_NUMBER;

	return $keys;
}
add_filter( 'woocommerce_shop_order_search_fields', 'gueta_search_old_order_number' );
add_filter( 'woocommerce_order_table_search_query_meta_keys', 'gueta_search_old_order_number' );
