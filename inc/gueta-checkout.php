<?php
/**
 * The checkout, arranged the way an address is written in Israel.
 *
 * WooCommerce ships an American address form: street first, then the postcode,
 * then the town, with the house number folded into the street line and a state
 * field nobody here fills in. Written out in Hebrew that reads backwards. This
 * puts the settlement first, gives the house number its own box, and lets the
 * postcode be optional, which it is, because most people do not know theirs.
 *
 * The settlement is chosen from the government's list rather than typed. The
 * browser completes it from a local file so it answers instantly, and PHP
 * checks the submission against the same list, because a field that only
 * validates in the browser does not validate.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the checkout customisations should run at all.
 *
 * @return bool
 */
function gueta_checkout_active() {
	return gueta_has_woocommerce() && (bool) apply_filters( 'gueta_checkout_active', true );
}

/* -------------------------------------------------------------------------
 * The shape of an address
 * ---------------------------------------------------------------------- */

/**
 * Reorder and relabel the fields shared by the billing and shipping forms.
 *
 * @param array $fields Address fields.
 * @return array
 */
function gueta_address_fields( $fields ) {
	if ( ! gueta_checkout_active() ) {
		return $fields;
	}

	// Where you are comes before which street, which is how it is said aloud.
	if ( isset( $fields['city'] ) ) {
		$fields['city']['label']       = 'עיר או יישוב';
		$fields['city']['placeholder'] = 'התחילו להקליד ובחרו מהרשימה';
		$fields['city']['priority']    = 50;
		$fields['city']['required']    = true;
		$fields['city']['class']       = [ 'form-row-wide', 'gueta-city-field' ];
		$fields['city']['autocomplete'] = 'address-level2';
		$fields['city']['custom_attributes']['data-gueta-city'] = '1';
		$fields['city']['custom_attributes']['autocorrect']      = 'off';
		$fields['city']['custom_attributes']['spellcheck']       = 'false';
	}

	if ( isset( $fields['address_1'] ) ) {
		$fields['address_1']['label']       = 'רחוב';
		$fields['address_1']['placeholder'] = 'שם הרחוב';
		$fields['address_1']['priority']    = 60;
		$fields['address_1']['class']       = [ 'form-row-first' ];
		$fields['address_1']['autocomplete'] = 'address-line1';
	}

	// WooCommerce has no house number, so the street line carries it. Splitting
	// the box is the honest way to ask, and the two are joined again on submit.
	$fields['house_number'] = [
		'label'        => 'מספר בית',
		'placeholder'  => 'מספר',
		'required'     => true,
		'priority'     => 65,
		'class'        => [ 'form-row-last' ],
		'autocomplete' => 'address-line2',
	];

	if ( isset( $fields['address_2'] ) ) {
		$fields['address_2']['label']       = 'דירה, כניסה או קומה';
		$fields['address_2']['placeholder'] = 'לא חובה';
		$fields['address_2']['priority']    = 70;
		$fields['address_2']['required']    = false;
		$fields['address_2']['class']       = [ 'form-row-wide' ];
		$fields['address_2']['label_class'] = [];
	}

	// Israel Post has codes for everything and almost nobody knows theirs.
	if ( isset( $fields['postcode'] ) ) {
		$fields['postcode']['label']       = 'מיקוד';
		$fields['postcode']['placeholder'] = 'לא חובה';
		$fields['postcode']['priority']    = 80;
		$fields['postcode']['required']    = false;
		$fields['postcode']['class']       = [ 'form-row-first' ];
	}

	if ( isset( $fields['company'] ) ) {
		$fields['company']['label']       = 'שם חברה';
		$fields['company']['placeholder'] = 'לא חובה';
		$fields['company']['priority']    = 90;
		$fields['company']['class']       = [ 'form-row-last' ];
	}

	if ( isset( $fields['country'] ) ) {
		$fields['country']['priority'] = 100;
	}

	if ( isset( $fields['state'] ) ) {
		$fields['state']['priority'] = 110;
		$fields['state']['required'] = false;
	}

	return $fields;
}
add_filter( 'woocommerce_default_address_fields', 'gueta_address_fields' );

/**
 * Arrange the rest of the checkout: how to reach you first, where to bring it
 * second.
 *
 * @param array $fields Checkout fields.
 * @return array
 */
function gueta_checkout_fields( $fields ) {
	if ( ! gueta_checkout_active() ) {
		return $fields;
	}

	if ( isset( $fields['billing']['billing_first_name'] ) ) {
		$fields['billing']['billing_first_name']['label']    = 'שם פרטי';
		$fields['billing']['billing_first_name']['priority'] = 10;
		$fields['billing']['billing_first_name']['class']    = [ 'form-row-first' ];
	}

	if ( isset( $fields['billing']['billing_last_name'] ) ) {
		$fields['billing']['billing_last_name']['label']    = 'שם משפחה';
		$fields['billing']['billing_last_name']['priority'] = 20;
		$fields['billing']['billing_last_name']['class']    = [ 'form-row-last' ];
	}

	if ( isset( $fields['billing']['billing_phone'] ) ) {
		$fields['billing']['billing_phone']['label']       = 'טלפון';
		$fields['billing']['billing_phone']['placeholder'] = '050-0000000';
		$fields['billing']['billing_phone']['priority']    = 30;
		$fields['billing']['billing_phone']['class']       = [ 'form-row-first' ];
	}

	if ( isset( $fields['billing']['billing_email'] ) ) {
		$fields['billing']['billing_email']['label']       = 'אימייל';
		$fields['billing']['billing_email']['placeholder'] = 'לשליחת אישור ההזמנה';
		$fields['billing']['billing_email']['priority']    = 40;
		$fields['billing']['billing_email']['class']       = [ 'form-row-last' ];
	}

	if ( isset( $fields['order']['order_comments'] ) ) {
		$fields['order']['order_comments']['label']       = 'הערות להזמנה';
		$fields['order']['order_comments']['placeholder'] = 'שעות מסירה מועדפות, קומה, גישה למשאית וכל דבר שיעזור לנו.';
	}

	// A shop that ships to one country should not ask which one.
	$countries = WC()->countries ? WC()->countries->get_allowed_countries() : [];

	if ( is_array( $countries ) && 1 === count( $countries ) ) {
		foreach ( [ 'billing', 'shipping' ] as $section ) {
			if ( isset( $fields[ $section ][ $section . '_country' ] ) ) {
				$fields[ $section ][ $section . '_country' ]['type']  = 'hidden';
				$fields[ $section ][ $section . '_country' ]['label'] = '';
				$fields[ $section ][ $section . '_country' ]['class'] = [ 'gueta-hidden-row' ];
			}
		}
	}

	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'gueta_checkout_fields', 20 );

/* -------------------------------------------------------------------------
 * What arrives when the form is sent
 * ---------------------------------------------------------------------- */

/**
 * Join the house number back onto the street, so everything downstream, the
 * gateway, the invoice, the delivery note, reads one complete line.
 *
 * @param array $data Posted checkout data.
 * @return array
 */
function gueta_merge_house_number( $data ) {
	if ( ! gueta_checkout_active() ) {
		return $data;
	}

	foreach ( [ 'billing', 'shipping' ] as $section ) {
		$street = isset( $data[ $section . '_address_1' ] ) ? trim( (string) $data[ $section . '_address_1' ] ) : '';
		$number = isset( $data[ $section . '_house_number' ] ) ? trim( (string) $data[ $section . '_house_number' ] ) : '';

		if ( $street && $number ) {
			$data[ $section . '_address_1' ] = $street . ' ' . $number;
		}
	}

	return $data;
}
add_filter( 'woocommerce_checkout_posted_data', 'gueta_merge_house_number' );

/**
 * Hold the shopper to the list.
 *
 * The browser offers the settlements and refuses anything else, but the browser
 * is not where this is decided. A pasted value, a stale autofill or a request
 * that never met the form all arrive here.
 *
 * @param array    $data   Posted data.
 * @param WP_Error $errors Errors so far.
 * @return void
 */
function gueta_validate_city( $data, $errors ) {
	if ( ! gueta_checkout_active() || ! function_exists( 'gueta_city_exists' ) ) {
		return;
	}

	// With nothing downloaded yet, a wrong answer is worse than no answer.
	if ( ! gueta_cities_rows() ) {
		return;
	}

	$sections = [ 'billing' => 'לחיוב' ];

	if ( ! empty( $data['ship_to_different_address'] ) ) {
		$sections['shipping'] = 'למשלוח';
	}

	foreach ( $sections as $section => $label ) {
		$city = isset( $data[ $section . '_city' ] ) ? trim( (string) $data[ $section . '_city' ] ) : '';

		if ( ! $city ) {
			continue;
		}

		if ( ! gueta_city_exists( $city ) ) {
			$errors->add(
				$section . '_city',
				sprintf( 'לא מצאנו את היישוב &quot;%1$s&quot; בכתובת %2$s. התחילו להקליד ובחרו מהרשימה.', esc_html( $city ), $label )
			);
		}
	}
}
add_action( 'woocommerce_after_checkout_validation', 'gueta_validate_city', 10, 2 );

/**
 * Store the settlement the way the government spells it, whatever was typed.
 *
 * @param array $data Posted checkout data.
 * @return array
 */
function gueta_canonical_city( $data ) {
	if ( ! gueta_checkout_active() || ! function_exists( 'gueta_city_canonical' ) ) {
		return $data;
	}

	foreach ( [ 'billing', 'shipping' ] as $section ) {
		$key = $section . '_city';

		if ( empty( $data[ $key ] ) ) {
			continue;
		}

		$canonical = gueta_city_canonical( $data[ $key ] );

		if ( $canonical ) {
			$data[ $key ] = $canonical;
		}
	}

	return $data;
}
add_filter( 'woocommerce_checkout_posted_data', 'gueta_canonical_city', 20 );

/* -------------------------------------------------------------------------
 * Assets
 * ---------------------------------------------------------------------- */

/**
 * The checkout's own stylesheet and the city completion, on the checkout only.
 *
 * @return void
 */
function gueta_checkout_assets() {
	if ( ! gueta_checkout_active() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return;
	}

	$uri = get_stylesheet_directory_uri();

	wp_enqueue_style(
		'gueta-checkout',
		$uri . '/assets/css/gueta-checkout.css',
		[ 'gueta-header' ],
		gueta_asset_version( '/assets/css/gueta-checkout.css' )
	);

	wp_enqueue_script(
		'gueta-checkout',
		$uri . '/assets/js/gueta-checkout.js',
		[],
		gueta_asset_version( '/assets/js/gueta-checkout.js' ),
		true
	);

	wp_localize_script(
		'gueta-checkout',
		'guetaCheckout',
		[
			'cities'  => function_exists( 'gueta_cities_url' ) ? gueta_cities_url() : '',
			'strings' => [
				'noMatch' => 'לא נמצא יישוב בשם הזה',
				'pick'    => 'בחרו יישוב מהרשימה',
			],
		]
	);
}
add_action( 'wp_enqueue_scripts', 'gueta_checkout_assets', 29 );
