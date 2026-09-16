<?php
/**
 * Yoast fields from a product CSV.
 *
 * WooCommerce's CSV importer maps a column to "meta data" only by a key without a leading
 * underscore, and Yoast keeps its fields under protected keys that start with one. So the
 * sheet carries them as yoast_import_focuskw, yoast_import_title and yoast_import_metadesc,
 * and they are moved into Yoast's own keys as each product comes in, and again whenever a
 * product is saved, which catches any that arrived some other way.
 *
 * Carried over from the old shop's "Yoast Import Bridge" WPCode snippet.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Move a product's staged Yoast fields into Yoast's keys, and drop the staged ones.
 *
 * @param WC_Product $product Product.
 * @return void
 */
function gueta_yoast_import_bridge( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$map = [
		'yoast_import_focuskw'  => '_yoast_wpseo_focuskw',
		'yoast_import_title'    => '_yoast_wpseo_title',
		'yoast_import_metadesc' => '_yoast_wpseo_metadesc',
	];

	$changed = false;

	foreach ( $map as $staged => $key ) {
		$value = $product->get_meta( $staged, true );

		if ( '' !== $value && null !== $value ) {
			update_post_meta( $product->get_id(), $key, $value );
			$product->delete_meta_data( $staged );
			$changed = true;
		}
	}

	if ( $changed ) {
		$product->save_meta_data();
	}
}
add_action( 'woocommerce_product_import_inserted_product_object', 'gueta_yoast_import_bridge', 20, 1 );

/**
 * The same on every product save.
 *
 * @param int $post_id Product.
 * @return void
 */
function gueta_yoast_import_bridge_on_save( $post_id ) {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! function_exists( 'wc_get_product' ) ) {
		return;
	}

	gueta_yoast_import_bridge( wc_get_product( $post_id ) );
}
add_action( 'save_post_product', 'gueta_yoast_import_bridge_on_save', 20, 1 );
