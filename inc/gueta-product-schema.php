<?php
/**
 * The price Google reads on a product page.
 *
 * Three things keep Google's price the one a customer can actually pay:
 *
 * - WooCommerce gathers a product's structured data on
 *   woocommerce_single_product_summary, which an Elementor Pro single product
 *   template never runs, so the page is given it here.
 * - Timber is priced per metre and bought by a length whose shortest option
 *   still adds to the price: 71 a metre, but 1.50 metres is the least on sale.
 *   The offer carries that first length, worked out as the Merchant feed does
 *   it, so the page and the feed agree.
 * - The feed links each variation as ?variant_id=, as the old theme did, and
 *   the page opens with that variation chosen, so its price is the one shown.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The variation a feed link asks for, when it belongs to this product.
 *
 * @param WC_Product $product Variable product.
 * @return WC_Product_Variation|null
 */
function gueta_linked_variation( $product ) {
	$id = isset( $_GET['variant_id'] ) ? absint( $_GET['variant_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( ! $id || ! $product instanceof WC_Product ) {
		return null;
	}

	$variation = wc_get_product( $id );

	if ( ! $variation instanceof WC_Product_Variation || $variation->get_parent_id() !== $product->get_id() ) {
		return null;
	}

	return $variation;
}

/**
 * Open the product page with the linked variation chosen.
 *
 * WooCommerce already reads the choice from attribute_* in the address; the
 * feed's links carry only the variation's id, so it becomes the default.
 *
 * @param array      $defaults Default attributes, by attribute name.
 * @param WC_Product $product  Product.
 * @return array
 */
function gueta_linked_variation_defaults( $defaults, $product ) {
	if ( ! function_exists( 'is_product' ) || ! is_product() || get_queried_object_id() !== $product->get_id() ) {
		return $defaults;
	}

	$variation = gueta_linked_variation( $product );

	if ( ! $variation ) {
		return $defaults;
	}

	foreach ( $variation->get_variation_attributes() as $name => $value ) {
		// An empty value is "any": the visitor still picks that one.
		if ( '' !== $value ) {
			$defaults[ substr( $name, strlen( 'attribute_' ) ) ] = $value;
		}
	}

	return $defaults;
}
add_filter( 'woocommerce_product_get_default_attributes', 'gueta_linked_variation_defaults', 10, 2 );

/**
 * The first option of a product's length add-on, as a price rule.
 *
 * The same reading as the Merchant feed's: the first add-on whose name holds
 * "אורך", and its first option that has a label and a price.
 *
 * @param WC_Product $product Product or variation.
 * @return array|null [ price, price_type ]
 */
function gueta_length_addon_rule( $product ) {
	$owner  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	$addons = get_post_meta( $owner, '_product_addons', true );

	if ( ! is_array( $addons ) ) {
		return null;
	}

	foreach ( $addons as $addon ) {
		$name = is_array( $addon ) && isset( $addon['name'] ) ? wp_strip_all_tags( html_entity_decode( (string) $addon['name'], ENT_QUOTES, 'UTF-8' ) ) : '';

		if ( false === mb_strpos( $name, 'אורך' ) || empty( $addon['options'] ) || ! is_array( $addon['options'] ) ) {
			continue;
		}

		foreach ( $addon['options'] as $option ) {
			if ( ! is_array( $option ) || empty( $option['label'] ) || ! isset( $option['price'] ) ) {
				continue;
			}

			$price = str_replace( ',', '.', (string) $option['price'] );

			if ( '' === $price || ! is_numeric( $price ) ) {
				continue;
			}

			return [
				'price'      => (float) $price,
				'price_type' => empty( $option['price_type'] ) ? 'flat_fee' : sanitize_key( $option['price_type'] ),
			];
		}
	}

	return null;
}

/**
 * The least a product can be bought for.
 *
 * @param WC_Product $product Product or variation.
 * @return float
 */
function gueta_purchasable_price( $product ) {
	$price = (float) $product->get_price();
	$rule  = gueta_length_addon_rule( $product );

	if ( $rule && $rule['price'] > 0 ) {
		$price += 'percentage_based' === $rule['price_type'] ? $price * $rule['price'] / 100 : $rule['price'];
	}

	// Two places, as the feed writes it, since Merchant Center holds the two
	// side by side; the shop shows one.
	return round( $price, 2 );
}

/**
 * The variation a variable product's offer speaks for: the linked one, the
 * default one, or the cheapest.
 *
 * @param WC_Product_Variable $product Product.
 * @return WC_Product_Variation|null
 */
function gueta_offer_variation( $product ) {
	$variation = gueta_linked_variation( $product );

	if ( $variation ) {
		return $variation;
	}

	$defaults = $product->get_default_attributes();

	if ( $defaults ) {
		$id        = WC_Data_Store::load( 'product' )->find_matching_product_variation( $product, $defaults );
		$variation = $id ? wc_get_product( $id ) : null;

		if ( $variation instanceof WC_Product_Variation ) {
			return $variation;
		}
	}

	$cheapest = null;

	foreach ( $product->get_children() as $child_id ) {
		$child = wc_get_product( $child_id );

		if ( $child instanceof WC_Product_Variation && $child->exists() && ( ! $cheapest || (float) $child->get_price() < (float) $cheapest->get_price() ) ) {
			$cheapest = $child;
		}
	}

	return $cheapest;
}

/**
 * One offer, at the price that can be paid.
 *
 * @param array      $offer   Offer WooCommerce built.
 * @param WC_Product $product Product.
 * @return array
 */
function gueta_structured_data_offer( $offer, $product ) {
	if ( ! $product instanceof WC_Product ) {
		return $offer;
	}

	$priced = $product;
	$url    = get_permalink( $product->get_id() );

	if ( $product->is_type( 'variable' ) ) {
		$priced = gueta_offer_variation( $product );

		if ( ! $priced ) {
			return $offer;
		}

		$url = add_query_arg( 'variant_id', $priced->get_id(), $url );
	}

	return [
		'@type'                 => 'Offer',
		'price'                 => gueta_purchasable_price( $priced ),
		'priceCurrency'         => get_woocommerce_currency(),
		'availability'          => $priced->is_in_stock() ? 'http://schema.org/InStock' : 'http://schema.org/OutOfStock',
		'url'                   => $url,
		'sku'                   => $priced->get_sku(),
		'valueAddedTaxIncluded' => 'http://schema.org/True',
	];
}
add_filter( 'woocommerce_structured_data_product_offer', 'gueta_structured_data_offer', 20, 2 );

/**
 * Gather the product's structured data on a page WooCommerce did not build.
 *
 * Runs ahead of WooCommerce printing it in the footer, and stands down when a
 * template did run the summary hook, which gathers it already.
 *
 * @return void
 */
function gueta_product_structured_data() {
	if ( ! function_exists( 'is_product' ) || ! is_product() || did_action( 'woocommerce_single_product_summary' ) ) {
		return;
	}

	$product = gueta_queried_product();

	if ( $product && WC()->structured_data instanceof WC_Structured_Data ) {
		WC()->structured_data->generate_product_data( $product );
	}
}
add_action( 'wp_footer', 'gueta_product_structured_data', 5 );
