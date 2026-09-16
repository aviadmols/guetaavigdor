<?php
/**
 * Which ways a cart can be sent, by what is in it.
 *
 * The old shop's rules, carried over from its WoodMart child theme, and the
 * same ones its Merchant feed tells Google:
 *
 * - A cart goes by truck when it holds anything in the "delivery" shipping
 *   class, anything heavier than 14.95kg, or anything 101cm or more along a
 *   side. Home delivery is not offered for it.
 * - Otherwise it goes home in parcels of up to 14.95kg each, at the home
 *   delivery price per parcel, and the method's name says how many.
 * - Anything over 40cm along a side, and under 101cm along every side, makes
 *   it a special delivery at 120, however many parcels.
 * - An ordinary cart is offered the truck too once it would take more than
 *   ten parcels.
 * - Collecting from the warehouse is always offered.
 *
 * A product with no weight counts as one kilogram, as before.
 *
 * The old shop charged the truck a flat 350, and 1,000 beyond thirty
 * kilometres when the shopper ticked a box, which the shop read as 650 on
 * top while the box itself promised 750. There is no box here: the truck is
 * the flat rate named "הובלה", which gueta-delivery.php prices afterwards by
 * the distance to the settlement chosen at checkout.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The heaviest parcel, in kilograms. A single item over it needs the truck.
 */
const GUETA_PARCEL_KG = 14.95;

/**
 * Parcels past which an ordinary cart is offered the truck as well.
 */
const GUETA_PARCELS_BEFORE_TRUCK = 10;

/**
 * A side longer than this, in centimetres, makes a special delivery.
 */
const GUETA_SPECIAL_CM = 40;

/**
 * A side this long or longer, in centimetres, needs the truck.
 */
const GUETA_TRUCK_CM = 101;

/**
 * The price of a special delivery.
 */
const GUETA_SPECIAL_PRICE = 120;

/**
 * What a package holds, as far as sending it goes.
 *
 * @param array $package Package.
 * @return array truck, special, weight and parcels.
 */
function gueta_shipping_cart( $package ) {
	$cart = [
		'truck'   => false,
		'special' => false,
		'weight'  => 0.0,
	];

	foreach ( (array) ( $package['contents'] ?? [] ) as $item ) {
		$product = $item['data'] ?? null;

		if ( ! $product instanceof WC_Product || ! $product->needs_shipping() ) {
			continue;
		}

		$weight = (float) $product->get_weight();
		$weight = $weight > 0 ? $weight : 1.0;
		$side   = max( (float) $product->get_length(), (float) $product->get_width(), (float) $product->get_height() );

		if ( 'delivery' === $product->get_shipping_class() || $weight > GUETA_PARCEL_KG || $side >= GUETA_TRUCK_CM ) {
			$cart['truck'] = true;
		} elseif ( $side > GUETA_SPECIAL_CM ) {
			$cart['special'] = true;
		}

		$cart['weight'] += $weight * max( 1, (float) ( $item['quantity'] ?? 1 ) );
	}

	$cart['parcels'] = $cart['weight'] > GUETA_PARCEL_KG ? (int) ceil( $cart['weight'] / GUETA_PARCEL_KG ) : 1;

	return $cart;
}

/**
 * Whether a shipping rate is home delivery.
 *
 * The flat rate titled "משלוח עד הבית" on this shop. Filter
 * gueta_shipping_is_home to point at another method.
 *
 * @param WC_Shipping_Rate $rate Rate.
 * @return bool
 */
function gueta_shipping_is_home( $rate ) {
	$is_home = 'flat_rate' === $rate->get_method_id() && false !== strpos( (string) $rate->get_label(), 'משלוח עד הבית' );

	return (bool) apply_filters( 'gueta_shipping_is_home', $is_home, $rate );
}

/**
 * Set a rate's cost, and its taxes in proportion.
 *
 * @param WC_Shipping_Rate $rate Rate.
 * @param float            $cost New cost.
 * @return void
 */
function gueta_shipping_set_cost( $rate, $cost ) {
	$before = (float) $rate->get_cost();
	$taxes  = $rate->get_taxes();

	$rate->set_cost( wc_format_decimal( $cost, wc_get_price_decimals() ) );

	if ( is_array( $taxes ) && $taxes && $before > 0 ) {
		foreach ( $taxes as $key => $tax ) {
			$taxes[ $key ] = (float) $tax * $cost / $before;
		}

		$rate->set_taxes( $taxes );
	}
}

/**
 * Offer the ways the package can be sent, at their prices.
 *
 * @param WC_Shipping_Rate[] $rates   Rates for the package.
 * @param array              $package Package.
 * @return WC_Shipping_Rate[]
 */
function gueta_shipping_rules( $rates, $package ) {
	$cart = gueta_shipping_cart( $package );

	foreach ( $rates as $key => $rate ) {
		if ( ! $rate instanceof WC_Shipping_Rate || in_array( $rate->get_method_id(), [ 'local_pickup', 'pickup_location' ], true ) ) {
			continue;
		}

		if ( function_exists( 'gueta_delivery_is_truck' ) && gueta_delivery_is_truck( $rate ) ) {
			if ( ! $cart['truck'] && $cart['parcels'] <= GUETA_PARCELS_BEFORE_TRUCK ) {
				unset( $rates[ $key ] );
			}

			continue;
		}

		// A truck load goes by truck or is collected, free shipping coupon or not.
		if ( $cart['truck'] ) {
			unset( $rates[ $key ] );
			continue;
		}

		if ( ! gueta_shipping_is_home( $rate ) ) {
			continue;
		}

		if ( $cart['parcels'] > 1 ) {
			gueta_shipping_set_cost( $rate, (float) $rate->get_cost() * $cart['parcels'] );
			$rate->set_label( sprintf( '%s (המוצרים ישלחו ב %d חבילות נפרדות)', $rate->get_label(), $cart['parcels'] ) );
		}

		if ( $cart['special'] ) {
			gueta_shipping_set_cost( $rate, GUETA_SPECIAL_PRICE );
			$rate->set_label( $rate->get_label() . ' משלוח מיוחד' );
		}
	}

	return $rates;
}
add_filter( 'woocommerce_package_rates', 'gueta_shipping_rules', 10, 2 );
