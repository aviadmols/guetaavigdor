<?php
/**
 * The checkout, arranged the way an address is written in Israel.
 *
 * WooCommerce ships an American address form: street first, then the postcode,
 * then the town, with the house number folded into the street line and a state
 * field nobody here fills in. Written out in Hebrew that reads backwards. This
 * puts the settlement first, gives the house number a box of its own, and drops
 * the postcode, which almost nobody here knows and no courier needs.
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
		$fields['address_1']['priority']    = 60;
		$fields['address_1']['class']       = [ 'form-row-first' ];
		$fields['address_1']['autocomplete'] = 'address-line1';
	}

	// WooCommerce has no house number, so the street line carries it. Splitting
	// the box is the honest way to ask, and the two are joined again on submit.
	$fields['house_number'] = [
		'label'        => 'מספר בית',
		'required'     => true,
		'priority'     => 65,
		'class'        => [ 'form-row-last' ],
		'autocomplete' => 'address-line2',
	];

	if ( isset( $fields['address_2'] ) ) {
		$fields['address_2']['label']       = 'דירה, כניסה או קומה';
		$fields['address_2']['priority']    = 70;
		$fields['address_2']['required']    = false;
		$fields['address_2']['class']       = [ 'form-row-wide' ];
		$fields['address_2']['label_class'] = [];
	}

	/*
	 * The postcode is gone. Israel Post has a code for every address and
	 * almost nobody knows their own, so it was a box that got skipped, and a
	 * courier here works from the street and the number anyway. It is unset
	 * rather than hidden, so nothing is asked for and nothing is stored.
	 */
	unset( $fields['postcode'] );

	if ( isset( $fields['company'] ) ) {
		$fields['company']['label']    = 'שם חברה';
		$fields['company']['priority'] = 90;
		$fields['company']['class']    = [ 'form-row-wide' ];
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
		$fields['billing']['billing_phone']['priority']    = 30;
		$fields['billing']['billing_phone']['class']       = [ 'form-row-first' ];
	}

	if ( isset( $fields['billing']['billing_email'] ) ) {
		$fields['billing']['billing_email']['label']       = 'אימייל';
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

	return gueta_checkout_placeholders( $fields );
}
add_filter( 'woocommerce_checkout_fields', 'gueta_checkout_fields', 20 );

/**
 * Give every field a placeholder, even an empty one.
 *
 * The labels sit inside the fields and rise out of the way once there is
 * something to read, which the stylesheet works out from :placeholder-shown.
 * A field with no placeholder attribute never matches that, so its label
 * would stay put and sit on top of whatever was typed. One space is enough
 * to make the browser answer the question.
 *
 * Where a field carries a real hint it is kept, and shows on focus under the
 * label that has just moved up.
 *
 * @param array $fields Checkout fields.
 * @return array
 */
function gueta_checkout_placeholders( $fields ) {
	foreach ( $fields as $section => $rows ) {
		if ( ! is_array( $rows ) ) {
			continue;
		}

		foreach ( $rows as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$type = isset( $field['type'] ) ? $field['type'] : 'text';

			// A select is never empty, so it has no label to float.
			if ( in_array( $type, [ 'hidden', 'checkbox', 'radio', 'select', 'country', 'state' ], true ) ) {
				continue;
			}

			if ( empty( $field['placeholder'] ) ) {
				$fields[ $section ][ $key ]['placeholder'] = ' ';
			}
		}
	}

	return $fields;
}

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

/* -------------------------------------------------------------------------
 * The delivery choice, across the whole column
 * ---------------------------------------------------------------------- */

/**
 * Let the delivery options use the full width of the summary.
 *
 * WooCommerce prints them in the right hand cell of a two column table, which
 * on a narrow summary leaves them about a hundred and thirty pixels wide, and
 * a Hebrew line like "משלוח עד הבית (עד 7 ימי עסקים)" breaks a word to a line.
 * No amount of CSS fixes it: a table cell cannot outgrow its column, and
 * taking the cells out of the table layout collapses them further.
 *
 * So the markup is changed rather than the styling. The row's two cells are
 * merged into one that spans both columns, with the package name kept above
 * the options as a heading. This rewrites WooCommerce's own output between the
 * two actions that bracket it, which is lighter than copying a core template
 * and going stale the next time it changes.
 *
 * @return void
 */
function gueta_shipping_row_open() {
	if ( gueta_checkout_active() ) {
		ob_start();
	}
}
add_action( 'woocommerce_review_order_before_shipping', 'gueta_shipping_row_open', 5 );

/**
 * Close the buffer and merge the row's cells.
 *
 * @return void
 */
function gueta_shipping_row_close() {
	if ( ! gueta_checkout_active() ) {
		return;
	}

	$html = (string) ob_get_clean();

	// One cell across both columns, the package name promoted to a heading.
	$merged = preg_replace(
		'#<th[^>]*>(.*?)</th>\s*<td([^>]*)>#is',
		'<td colspan="2"$2><span class="gueta-shipping__title">$1</span>',
		$html,
		1,
		$count
	);

	// If WooCommerce ever changes that shape, print what it gave us.
	echo ( $count && $merged ) ? $merged : $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'woocommerce_review_order_after_shipping', 'gueta_shipping_row_close', 50 );

/**
 * A placeholder that only repeats the label is replaced with a space.
 *
 * Elementor's checkout widget writes the label into the placeholder. With the
 * label sitting inside the box, that shows the same words twice the moment the
 * field is focused: once floated up, once behind the cursor.
 *
 * @param array $fields Checkout fields.
 * @return array
 */
function gueta_drop_echoed_placeholders( $fields ) {
	foreach ( $fields as $section => $rows ) {
		if ( ! is_array( $rows ) ) {
			continue;
		}

		foreach ( $rows as $key => $field ) {
			if ( ! is_array( $field ) || empty( $field['placeholder'] ) || empty( $field['label'] ) ) {
				continue;
			}

			if ( trim( (string) $field['placeholder'] ) === trim( (string) $field['label'] ) ) {
				$fields[ $section ][ $key ]['placeholder'] = ' ';
			}
		}
	}

	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'gueta_drop_echoed_placeholders', 100 );
