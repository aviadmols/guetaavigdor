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
	$only_country = gueta_only_country();

	if ( '' !== $only_country ) {
		foreach ( [ 'billing', 'shipping' ] as $section ) {
			if ( isset( $fields[ $section ][ $section . '_country' ] ) ) {
				$fields[ $section ][ $section . '_country' ]['type']    = 'hidden';
				$fields[ $section ][ $section . '_country' ]['label']   = '';
				$fields[ $section ][ $section . '_country' ]['class']   = [ 'gueta-hidden-row' ];
				$fields[ $section ][ $section . '_country' ]['default'] = $only_country;
			}
		}
	}

	return gueta_checkout_placeholders( $fields );
}
add_filter( 'woocommerce_checkout_fields', 'gueta_checkout_fields', 20 );

/**
 * The one country the shop sells to, or an empty string when it sells to more.
 *
 * @return string
 */
function gueta_only_country() {
	if ( ! gueta_has_woocommerce() || ! WC()->countries ) {
		return '';
	}

	$countries = WC()->countries->get_allowed_countries();

	return is_array( $countries ) && 1 === count( $countries ) ? (string) key( $countries ) : '';
}

/**
 * Where a shopper has no country yet, it is the one the shop sells to.
 *
 * The country field is hidden, since there is only one answer, and the shop
 * has no default customer location, so a first visit reached the checkout
 * with no country at all. The hidden field went out empty, WooCommerce does
 * not price delivery to an address with no country, so no delivery choice
 * appeared, and pressing order came back with "חיוב הוא שדה חובה": the hidden
 * field's own error, with no label to name it, which reads as though the
 * payment were missing. Returning customers had a country saved from an
 * earlier order, which is why only some sessions went wrong.
 *
 * So the customer's country is read through here, and delivery is priced from
 * the first render and after every refresh, which posts the hidden field. The
 * field starts with the country, and what the form sends is filled in too, for
 * a checkout left open from before this change.
 *
 * @param string|null $country The country held or posted, empty when none.
 * @return string|null
 */
function gueta_customer_country( $country ) {
	if ( '' !== (string) $country ) {
		return $country;
	}

	$only_country = gueta_only_country();

	return '' !== $only_country ? $only_country : $country;
}
add_filter( 'woocommerce_customer_get_billing_country', 'gueta_customer_country' );
add_filter( 'woocommerce_customer_get_shipping_country', 'gueta_customer_country' );
add_filter( 'default_checkout_billing_country', 'gueta_customer_country' );
add_filter( 'default_checkout_shipping_country', 'gueta_customer_country' );

/**
 * Fill in the country on an order being placed, if the form sent it empty.
 *
 * @param array $data Posted checkout data.
 * @return array
 */
function gueta_checkout_posted_country( $data ) {
	foreach ( [ 'billing_country', 'shipping_country' ] as $key ) {
		if ( array_key_exists( $key, $data ) ) {
			$data[ $key ] = gueta_customer_country( $data[ $key ] );
		}
	}

	return $data;
}
add_filter( 'woocommerce_checkout_posted_data', 'gueta_checkout_posted_country' );

/**
 * Name a missing field the way the form does: "טלפון הוא שדה חובה".
 *
 * WooCommerce puts the section in front of the field's name, and the billing
 * section is "חיוב", a charge, so a shopper told "חיוב טלפון הוא שדה חובה"
 * goes looking for something wrong with the payment. The billing fields are
 * the address almost everyone fills in, so the field's own name is enough.
 *
 * @param string $translation Translated text.
 * @param string $text        Original text.
 * @param string $context     Context.
 * @return string
 */
function gueta_checkout_validation_label( $translation, $text, $context ) {
	if ( 'checkout-validation' === $context && 'Billing %s' === $text && gueta_checkout_active() ) {
		return '%s';
	}

	return $translation;
}
add_filter( 'gettext_with_context_woocommerce', 'gueta_checkout_validation_label', 10, 3 );

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
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'wcAjaxUrl' => class_exists( 'WC_AJAX' ) ? WC_AJAX::get_endpoint( '%%endpoint%%' ) : '',
			// The drawer's nonce, since taking out the last line goes through the drawer's own handler.
			'nonce'   => wp_create_nonce( 'gueta_header' ),
			'strings' => [
				'noMatch'    => 'לא נמצא יישוב בשם הזה',
				'pick'       => 'בחרו יישוב מהרשימה',
				'removeError' => 'לא הצלחנו להסיר את המוצר. נסו שוב.',
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

/* -------------------------------------------------------------------------
 * Each product in the order, drawn the way a cart draws a line
 *
 * The picture with the quantity on its corner, then the name, what was chosen
 * and the price. WooCommerce gives the product a table row of two cells, the
 * name in one and the price in the other, and in a summary about three hundred
 * pixels wide that left the name a column of three words to a line beside a
 * price column that was mostly empty.
 * ---------------------------------------------------------------------- */

/**
 * The picture and the quantity, in front of the name.
 *
 * Only the name passes through a filter, so the picture rides in with it. The
 * stylesheet lifts it into the cell's padding, clear of the text. The button
 * that takes the line out of the order rides in the same way, and the
 * stylesheet puts it in the cell's far corner, as the cart drawer has it.
 *
 * @param string $name          Product name markup.
 * @param array  $cart_item     Cart item.
 * @param string $cart_item_key Cart item key.
 * @return string
 */
function gueta_review_item_picture( $name, $cart_item, $cart_item_key = '' ) {
	if ( ! gueta_checkout_active() || ! is_checkout() || empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
		return $name;
	}

	$item_name = gueta_review_item_name( $name, $cart_item );
	$remove    = '';

	if ( $cart_item_key ) {
		$remove = sprintf(
			'<button type="button" class="gueta-review-item__remove" data-review-remove="%1$s" aria-label="%2$s"><span class="gueta-review-item__remove-icon" aria-hidden="true"></span></button>',
			esc_attr( $cart_item_key ),
			esc_attr( sprintf( 'הסרת %s מההזמנה', wp_strip_all_tags( $item_name ) ) )
		);
	}

	return sprintf(
		'<span class="gueta-review-item__media">%1$s<span class="gueta-review-item__qty">%2$s</span></span><span class="gueta-review-item__name">%3$s</span>%4$s',
		$cart_item['data']->get_image( 'woocommerce_thumbnail', [ 'class' => 'gueta-review-item__image' ] ),
		esc_html( number_format_i18n( absint( $cart_item['quantity'] ?? 0 ) ) ),
		$item_name,
		$remove
	);
}
add_filter( 'woocommerce_cart_item_name', 'gueta_review_item_picture', 20, 3 );

/**
 * Take a line out inside WooCommerce's own refresh of the summary.
 *
 * Removing a line used to be a request of its own, followed by the summary's
 * refresh: two full requests, each well over a second and a half here. The
 * checkout script now puts the line's key in the form as
 * gueta_remove_cart_item[] and asks for the refresh, which posts the form and
 * runs this before working out the totals, so one request does both. The
 * refresh checks its own nonce, and a key that is not in this shopper's cart
 * is ignored.
 *
 * @param string $posted The checkout form, URL encoded.
 * @return void
 */
function gueta_review_remove_lines( $posted ) {
	if ( ! gueta_has_woocommerce() || ! WC()->cart ) {
		return;
	}

	parse_str( (string) $posted, $form );

	if ( empty( $form['gueta_remove_cart_item'] ) ) {
		return;
	}

	foreach ( (array) $form['gueta_remove_cart_item'] as $key ) {
		$key = sanitize_key( (string) $key );

		if ( $key && WC()->cart->get_cart_item( $key ) && WC()->cart->remove_cart_item( $key ) ) {
			gueta_review_removed( true );
		}
	}
}
add_action( 'woocommerce_checkout_update_order_review', 'gueta_review_remove_lines', 5 );

/**
 * Whether this request took a line out of the summary.
 *
 * @param bool|null $removed True to record a removal, or null to read.
 * @return bool
 */
function gueta_review_removed( $removed = null ) {
	static $state = false;

	if ( null !== $removed ) {
		$state = (bool) $removed;
	}

	return $state;
}

/**
 * Send the drawer and the badge back with the refreshed summary.
 *
 * WooCommerce's checkout script replaces every fragment it is given, so a
 * line taken out of the summary leaves the drawer and the count in the header
 * right in the same answer. The totals were just worked out for the summary,
 * so drawing the drawer does not work them out again.
 *
 * @param array $fragments Checkout fragments.
 * @return array
 */
function gueta_review_removal_fragments( $fragments ) {
	if ( gueta_review_removed() && function_exists( 'gueta_cart_panel_html' ) ) {
		$fragments['div.gueta-cart-panel']  = gueta_cart_panel_html();
		$fragments['span.gueta-cart-count'] = gueta_cart_count_html();
	}

	return $fragments;
}
add_filter( 'woocommerce_update_order_review_fragments', 'gueta_review_removal_fragments' );

/**
 * A variation by its product's name, when the list under it names every choice.
 *
 * A variation's name is its product's with the options tacked on, "לביד
 * סנדוויץ' ... - 10-ממ", and WooCommerce then lists the options again under
 * it, "עובי: 10 מ"מ", unless it finds them in the name. Imported names spell
 * them differently, so it rarely does, and the line said the thickness twice.
 *
 * WooCommerce's own test is repeated here: only when it would list every
 * option does the name drop them. Otherwise the variation's name stays, so an
 * option is never lost from the line.
 *
 * @param string $name      Product name.
 * @param array  $cart_item Cart item.
 * @return string
 */
function gueta_review_item_name( $name, $cart_item ) {
	$product = $cart_item['data'];

	if ( ! $product->is_type( 'variation' ) || empty( $cart_item['variation'] ) || ! is_array( $cart_item['variation'] ) ) {
		return $name;
	}

	foreach ( $cart_item['variation'] as $key => $value ) {
		$taxonomy = wc_attribute_taxonomy_name( str_replace( 'attribute_pa_', '', urldecode( $key ) ) );

		if ( taxonomy_exists( $taxonomy ) ) {
			$term  = get_term_by( 'slug', $value, $taxonomy );
			$value = ( $term && ! is_wp_error( $term ) ) ? $term->name : $value;
		}

		if ( '' === $value || wc_is_attribute_in_product_name( $value, $product->get_name() ) ) {
			return $name;
		}
	}

	$parent = wc_get_product( $product->get_parent_id() );

	return $parent ? $parent->get_name() : $name;
}

/**
 * Hold the product rows while WooCommerce prints them.
 *
 * @return void
 */
function gueta_review_items_open() {
	if ( gueta_checkout_active() ) {
		ob_start();
	}
}
add_action( 'woocommerce_review_order_before_cart_contents', 'gueta_review_items_open', 5 );

/**
 * Fold each row's price cell into its name cell, which then spans the table.
 *
 * The name gets the whole width beside the picture, and the price goes into
 * the same cell after the name and the chosen options, where the stylesheet
 * puts it under them, or across from them when the summary is wide. The same
 * rewrite of WooCommerce's own output the delivery row gets below, for the
 * same reason: no copied template to go stale.
 *
 * @return void
 */
function gueta_review_items_close() {
	if ( ! gueta_checkout_active() ) {
		return;
	}

	$html = (string) ob_get_clean();

	$merged = preg_replace(
		'#<td class="product-name"([^>]*)>(.*?)</td>\s*<td class="product-total"[^>]*>(.*?)</td>#s',
		'<td class="product-name" colspan="2"$1>$2<span class="gueta-review-item__price">$3</span></td>',
		$html,
		-1,
		$count
	);

	// If WooCommerce ever changes that shape, print what it gave us.
	echo ( $count && $merged ) ? $merged : $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'woocommerce_review_order_after_cart_contents', 'gueta_review_items_close', 50 );

/**
 * Drop the "× 2" after the name, which the badge on the picture now says.
 *
 * @param string $quantity Quantity markup.
 * @return string
 */
function gueta_review_item_quantity( $quantity ) {
	return gueta_checkout_active() ? '' : $quantity;
}
add_filter( 'woocommerce_checkout_cart_item_quantity', 'gueta_review_item_quantity', 20 );

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

/**
 * Start with "ship to a different address" unticked.
 *
 * The shop is set to ship to the shipping address, which has WooCommerce tick
 * the box on arrival, and a second address a shopper never meant to fill in
 * then decides the delivery price: the city typed above says Eilat, the one
 * below still says Tel Aviv, and the truck is priced for Tel Aviv. The old shop
 * started with the box unticked too.
 *
 * @return bool
 */
add_filter( 'woocommerce_ship_to_different_address_checked', '__return_false' );
