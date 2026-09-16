<?php
/**
 * Prices as the old shop set them.
 *
 * The catalogue was built around three ACF fields that the WoodMart child
 * theme turned into prices, and without them the new shop sold a pack of a
 * hundred screws for the price of one:
 *
 * - pack_number: the product is priced per unit and sold in packs, so the
 *   price shown and charged is the unit price times the pack.
 * - rounding: off on almost every product, and while it is off the price is
 *   rounded up to a whole shekel. On, the stored price goes out untouched,
 *   pack and all.
 * - price_text: what the price buys, such as "מחיר ל10 ברגים".
 *
 * The fields are read straight from the product's meta, so this works with or
 * without ACF. The old theme fell back to the current page's fields when a
 * product had none, which gave the related products on a pack's page that
 * pack's multiplier; here a product answers only for itself.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How many units the price covers.
 *
 * @param int $product_id Product, the parent for a variation.
 * @return float
 */
function gueta_pack_multiplier( $product_id ) {
	$pack = (float) str_replace( ',', '.', (string) get_post_meta( $product_id, 'pack_number', true ) );

	return $pack > 0 ? $pack : 1.0;
}

/**
 * The price a product goes out at, from the one stored on it.
 *
 * @param mixed $price      Stored price.
 * @param int   $product_id Product, the parent for a variation.
 * @return mixed
 */
function gueta_pack_price( $price, $product_id ) {
	if ( '' === $price || null === $price || get_post_meta( $product_id, 'rounding', true ) ) {
		return $price;
	}

	$price = (float) $price;

	return $price > 0 ? ceil( $price * gueta_pack_multiplier( $product_id ) ) : $price;
}

/**
 * A simple, grouped or external product's prices.
 *
 * @param mixed      $price   Price.
 * @param WC_Product $product Product.
 * @return mixed
 */
function gueta_product_pack_price( $price, $product ) {
	return gueta_pack_price( $price, $product->get_id() );
}
add_filter( 'woocommerce_product_get_price', 'gueta_product_pack_price', 20, 2 );
add_filter( 'woocommerce_product_get_regular_price', 'gueta_product_pack_price', 20, 2 );
add_filter( 'woocommerce_product_get_sale_price', 'gueta_product_pack_price', 20, 2 );

/**
 * A variation's prices, by its parent's fields.
 *
 * @param mixed                $price     Price.
 * @param WC_Product_Variation $variation Variation.
 * @return mixed
 */
function gueta_variation_pack_price( $price, $variation ) {
	return gueta_pack_price( $price, $variation->get_parent_id() );
}
add_filter( 'woocommerce_product_variation_get_price', 'gueta_variation_pack_price', 20, 2 );
add_filter( 'woocommerce_product_variation_get_regular_price', 'gueta_variation_pack_price', 20, 2 );
add_filter( 'woocommerce_product_variation_get_sale_price', 'gueta_variation_pack_price', 20, 2 );

/**
 * The prices a variable product lists for its variations, and its range.
 *
 * @param mixed                $price     Price.
 * @param WC_Product_Variation $variation Variation.
 * @param WC_Product_Variable  $product   Parent.
 * @return mixed
 */
function gueta_variation_prices_pack_price( $price, $variation, $product ) {
	return gueta_pack_price( $price, $product->get_id() );
}
add_filter( 'woocommerce_variation_prices_price', 'gueta_variation_prices_pack_price', 20, 3 );
add_filter( 'woocommerce_variation_prices_regular_price', 'gueta_variation_prices_pack_price', 20, 3 );
add_filter( 'woocommerce_variation_prices_sale_price', 'gueta_variation_prices_pack_price', 20, 3 );

/**
 * Keep the cached variation prices apart for each pack and rounding setting.
 *
 * @param array               $hash    Hash parts.
 * @param WC_Product_Variable $product Product.
 * @return array
 */
function gueta_variation_prices_pack_hash( $hash, $product ) {
	$hash[] = gueta_pack_multiplier( $product->get_id() );
	$hash[] = (int) (bool) get_post_meta( $product->get_id(), 'rounding', true );

	return $hash;
}
add_filter( 'woocommerce_get_variation_prices_hash', 'gueta_variation_prices_pack_hash', 20, 2 );

/**
 * Say what the price buys, and show no price at all where there is none.
 *
 * @param string     $html    Price HTML.
 * @param WC_Product $product Product.
 * @return string
 */
function gueta_price_html( $html, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $html;
	}

	// A product with no price of its own is one to ask about, not a free one.
	if ( (float) $product->get_price() <= 0 ) {
		return '';
	}

	$owner = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	$text  = trim( (string) get_post_meta( $owner, 'price_text', true ) );

	if ( '' === $text || '' === $html ) {
		return $html;
	}

	return $html . ' <small class="gueta-price-for">(' . esc_html( $text ) . ')</small>';
}
add_filter( 'woocommerce_get_price_html', 'gueta_price_html', 10, 2 );

/**
 * No strike through when the sale price is the regular one.
 *
 * @param string $html    Sale price HTML.
 * @param mixed  $regular Regular price.
 * @param mixed  $sale    Sale price.
 * @return string
 */
function gueta_sale_price_html( $html, $regular, $sale ) {
	return (float) $sale === (float) $regular ? wc_price( $sale ) : $html;
}
add_filter( 'woocommerce_format_sale_price', 'gueta_sale_price_html', 20, 3 );
