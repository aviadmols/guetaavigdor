<?php
/**
 * Choosing a length: timber, rods and rolls sold by the metre.
 *
 * The old shop did this with WooCommerce Product Add-Ons, and every product
 * still carries that plugin's settings in _product_addons: one list per
 * product, named "אורך" or close to it, each option a percentage on the
 * product's price. This reads them as they are, so the shop needs no plugin and
 * the lengths the old shop set keep working.
 *
 * It does what the plugin did, the way the old orders show it was done:
 *
 * - The field sits in the add to cart form under the plugin's own field and
 *   option names, so the quick add to cart script, the cutting calculator and
 *   the Merchant feed all read it as before.
 * - A required length has to be chosen before the product goes in the cart.
 * - A line's price is the product's stored price plus the option's percentage
 *   of it, and then the shop's pack and rounding rules, as gueta-pricing.php
 *   applies them to any price: 31.26 with 200% is 93.79, charged as 94.
 * - The order line keeps the choice under the plugin's key, the field's name
 *   with the addition in brackets, "אורך (64.0 ₪)" => "3.00 מטר", so new orders
 *   read like the old ones.
 *
 * A line the cutting calculator added carries its own length and price, and is
 * left to it. If Product Add-Ons is ever switched back on, this stands down.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether Product Add-Ons itself is running, which then does all of this.
 *
 * @return bool
 */
function gueta_lengths_plugin_active() {
	return class_exists( 'WC_Product_Addons' ) || defined( 'WC_PRODUCT_ADDONS_VERSION' );
}

/**
 * A product's length field, from its Add-Ons settings.
 *
 * @param WC_Product|int $product Product or variation.
 * @return array|null name, required, field (the form field's name), options
 *                    [ label, percent, value ], or null when it has none.
 */
function gueta_length_field( $product ) {
	$product = $product instanceof WC_Product ? $product : wc_get_product( $product );

	if ( ! $product ) {
		return null;
	}

	$owner  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
	$addons = get_post_meta( $owner, '_product_addons', true );

	if ( ! is_array( $addons ) ) {
		return null;
	}

	foreach ( $addons as $index => $addon ) {
		if ( ! is_array( $addon ) || 'multiple_choice' !== ( $addon['type'] ?? '' ) || empty( $addon['options'] ) || ! is_array( $addon['options'] ) ) {
			continue;
		}

		$name    = (string) ( $addon['name'] ?? '' );
		$options = [];

		foreach ( array_values( $addon['options'] ) as $position => $option ) {
			$label = trim( (string) ( $option['label'] ?? '' ) );

			if ( '' === $label ) {
				continue;
			}

			$options[] = [
				'label'   => $label,
				'percent' => (float) str_replace( ',', '.', (string) ( $option['price'] ?? 0 ) ),
				// The plugin's option value: the label as a slug, counted from one.
				'value'   => sanitize_title( $label ) . '-' . ( $position + 1 ),
			];
		}

		if ( ! $options ) {
			continue;
		}

		return [
			'name'     => $name,
			'required' => ! empty( $addon['required'] ),
			'field'    => 'addon-' . $owner . '-' . sanitize_title( $name ) . '-' . $index,
			'options'  => $options,
		];
	}

	return null;
}

/**
 * The option a request chose, if any.
 *
 * @param array $field Length field.
 * @return array|null
 */
function gueta_length_chosen( $field ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
	$value = isset( $_REQUEST[ $field['field'] ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $field['field'] ] ) ) : '';

	foreach ( $field['options'] as $option ) {
		if ( '' !== $value && $option['value'] === $value ) {
			return $option;
		}
	}

	return null;
}

/**
 * What one unit costs at a length: the stored price plus the percentage, then
 * the shop's pack and rounding rules, as the customer will see it.
 *
 * @param WC_Product $product Product or variation.
 * @param float      $percent Option's percentage.
 * @return float
 */
function gueta_length_unit_price( $product, $percent ) {
	$raw   = (float) $product->get_price( 'edit' );
	$price = $raw * ( 1 + $percent / 100 );

	if ( function_exists( 'gueta_pack_price' ) ) {
		$price = (float) gueta_pack_price( $price, $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id() );
	}

	return (float) wc_get_price_to_display( $product, [ 'price' => $price ] );
}

/**
 * Money as plain text, as an order line's key shows it.
 *
 * @param float $amount Amount.
 * @return string
 */
function gueta_length_money_text( $amount ) {
	return trim( str_replace( "\xC2\xA0", ' ', html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' ) ) );
}

/* -------------------------------------------------------------------------
 * The field
 * ---------------------------------------------------------------------- */

/**
 * Print the field in the add to cart form.
 *
 * Each option carries the unit price it comes to, worked out here so the page
 * shows what the cart will charge. A variable product has no single price
 * until a variation is chosen, so there the script works it out from the
 * variation's price instead.
 *
 * @return void
 */
function gueta_length_render() {
	global $product;

	if ( gueta_lengths_plugin_active() || ! $product instanceof WC_Product ) {
		return;
	}

	$field = gueta_length_field( $product );

	if ( ! $field ) {
		return;
	}

	$variable = $product->is_type( 'variable' );
	$id       = 'gueta-length-' . $product->get_id();
	$label    = rtrim( trim( $field['name'] ), ':' );
	?>
	<div class="gueta-length" data-length data-decimals="<?php echo esc_attr( (string) wc_get_price_decimals() ); ?>" data-format="<?php echo esc_attr( get_woocommerce_price_format() ); ?>" data-symbol="<?php echo esc_attr( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?>">
		<label class="gueta-length__label" for="<?php echo esc_attr( $id ); ?>">
			<?php echo esc_html( $label ); ?>
			<?php if ( $field['required'] ) : ?>
				<abbr class="gueta-length__required" title="שדה חובה">*</abbr>
			<?php endif; ?>
		</label>

		<select class="gueta-length__select" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $field['field'] ); ?>"<?php echo $field['required'] ? ' required' : ''; ?>>
			<option value="">בחירת <?php echo esc_html( $label ); ?></option>
			<?php foreach ( $field['options'] as $option ) : ?>
				<option value="<?php echo esc_attr( $option['value'] ); ?>" data-percent="<?php echo esc_attr( (string) $option['percent'] ); ?>"<?php echo $variable ? '' : ' data-price="' . esc_attr( (string) gueta_length_unit_price( $product, $option['percent'] ) ) . '"'; ?>>
					<?php echo esc_html( $option['label'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>

		<p class="gueta-length__total" data-length-total hidden></p>
	</div>
	<?php
}
add_action( 'woocommerce_before_add_to_cart_button', 'gueta_length_render', 5 );

/* -------------------------------------------------------------------------
 * The cart
 * ---------------------------------------------------------------------- */

/**
 * Turn away a product whose required length was not chosen.
 *
 * Only a request that goes through WooCommerce's add to cart validation
 * reaches this: the product form, the quick add, the Store API. The cutting
 * calculator adds its lines straight to the cart with its own length.
 *
 * @param bool $passed       Passed so far.
 * @param int  $product_id   Product, or the variation on the quick add.
 * @param int  $quantity     Quantity.
 * @param int  $variation_id Variation.
 * @return bool
 */
function gueta_length_validate( $passed, $product_id, $quantity = 1, $variation_id = 0 ) {
	if ( ! $passed || gueta_lengths_plugin_active() ) {
		return $passed;
	}

	$field = gueta_length_field( $variation_id ? $variation_id : $product_id );

	if ( ! $field || ! $field['required'] || gueta_length_chosen( $field ) ) {
		return $passed;
	}

	wc_add_notice( sprintf( 'יש לבחור %s לפני ההוספה לסל.', rtrim( trim( $field['name'] ), ':' ) ), 'error' );

	return false;
}
add_filter( 'woocommerce_add_to_cart_validation', 'gueta_length_validate', 10, 4 );

/**
 * Keep the chosen length on the cart line.
 *
 * @param array $data         Cart item data.
 * @param int   $product_id   Product.
 * @param int   $variation_id Variation.
 * @return array
 */
function gueta_length_cart_item_data( $data, $product_id, $variation_id = 0 ) {
	if ( gueta_lengths_plugin_active() || ! empty( $data['gac_cutting'] ) ) {
		return $data;
	}

	$field  = gueta_length_field( $variation_id ? $variation_id : $product_id );
	$chosen = $field ? gueta_length_chosen( $field ) : null;

	if ( $chosen ) {
		$data['gueta_length'] = [
			'name'    => $field['name'],
			'label'   => $chosen['label'],
			'percent' => $chosen['percent'],
		];
	}

	return $data;
}
add_filter( 'woocommerce_add_cart_item_data', 'gueta_length_cart_item_data', 10, 3 );

/**
 * Bring the length back with the cart from the session.
 *
 * @param array $item   Cart item.
 * @param array $values Stored values.
 * @return array
 */
function gueta_length_from_session( $item, $values ) {
	if ( isset( $values['gueta_length'] ) ) {
		$item['gueta_length'] = $values['gueta_length'];
	}

	return $item;
}
add_filter( 'woocommerce_get_cart_item_from_session', 'gueta_length_from_session', 10, 2 );

/**
 * Price each line at its length.
 *
 * The line's own product object is priced from a fresh read of the product,
 * so working the totals out again, as a cart does several times a request,
 * never adds the percentage twice.
 *
 * @param WC_Cart $cart Cart.
 * @return void
 */
function gueta_length_prices( $cart ) {
	if ( gueta_lengths_plugin_active() || ! $cart instanceof WC_Cart ) {
		return;
	}

	foreach ( $cart->get_cart() as $item ) {
		if ( empty( $item['gueta_length'] ) || ! empty( $item['gac_cutting'] ) || empty( $item['data'] ) ) {
			continue;
		}

		$source = wc_get_product( ! empty( $item['variation_id'] ) ? $item['variation_id'] : $item['product_id'] );

		if ( $source ) {
			$item['data']->set_price( (float) $source->get_price( 'edit' ) * ( 1 + (float) $item['gueta_length']['percent'] / 100 ) );
		}
	}
}
add_action( 'woocommerce_before_calculate_totals', 'gueta_length_prices', 5 );

/**
 * Show the length under the product's name in the cart and the checkout.
 *
 * @param array $data Item data.
 * @param array $item Cart item.
 * @return array
 */
function gueta_length_item_data( $data, $item ) {
	if ( ! empty( $item['gueta_length'] ) ) {
		$data[] = [
			'key'   => rtrim( trim( $item['gueta_length']['name'] ), ':' ),
			'value' => $item['gueta_length']['label'],
		];
	}

	return $data;
}
add_filter( 'woocommerce_get_item_data', 'gueta_length_item_data', 10, 2 );

/**
 * Keep the length on the order line, under the key the old orders use.
 *
 * @param WC_Order_Item_Product $line   Order line.
 * @param string                $key    Cart item key.
 * @param array                 $values Cart item.
 * @return void
 */
function gueta_length_order_line( $line, $key, $values ) {
	if ( empty( $values['gueta_length'] ) ) {
		return;
	}

	$length  = $values['gueta_length'];
	$product = wc_get_product( ! empty( $values['variation_id'] ) ? $values['variation_id'] : $values['product_id'] );
	$base    = $product ? (float) wc_get_price_to_display( $product ) : 0;

	$line->add_meta_data( sprintf( '%s (%s)', trim( $length['name'] ), gueta_length_money_text( $base * (float) $length['percent'] / 100 ) ), $length['label'] );
}
add_action( 'woocommerce_checkout_create_order_line_item', 'gueta_length_order_line', 10, 3 );

/* -------------------------------------------------------------------------
 * Listings
 * ---------------------------------------------------------------------- */

/**
 * A product that needs a length is bought from its own page, so its button in a
 * listing leads there instead of adding it.
 *
 * @param bool       $supports Whether it supports the feature.
 * @param string     $feature  Feature.
 * @param WC_Product $product  Product.
 * @return bool
 */
function gueta_length_no_ajax_add( $supports, $feature, $product ) {
	if ( 'ajax_add_to_cart' === $feature && ! gueta_lengths_plugin_active() && gueta_length_needed( $product ) ) {
		return false;
	}

	return $supports;
}
add_filter( 'woocommerce_product_supports', 'gueta_length_no_ajax_add', 10, 3 );

/**
 * Whether a product can only be bought with a length chosen.
 *
 * @param WC_Product $product Product.
 * @return bool
 */
function gueta_length_needed( $product ) {
	if ( ! $product instanceof WC_Product || $product->is_type( 'variable' ) ) {
		return false;
	}

	$field = gueta_length_field( $product );

	return $field && $field['required'];
}

/**
 * The listing button's address: the product page.
 *
 * @param string     $url     Address.
 * @param WC_Product $product Product.
 * @return string
 */
function gueta_length_add_to_cart_url( $url, $product ) {
	return ! gueta_lengths_plugin_active() && gueta_length_needed( $product ) ? $product->get_permalink() : $url;
}
add_filter( 'woocommerce_product_add_to_cart_url', 'gueta_length_add_to_cart_url', 10, 2 );

/**
 * The listing button's words.
 *
 * @param string     $text    Text.
 * @param WC_Product $product Product.
 * @return string
 */
function gueta_length_add_to_cart_text( $text, $product ) {
	return ! gueta_lengths_plugin_active() && gueta_length_needed( $product ) ? 'בחירת אורך' : $text;
}
add_filter( 'woocommerce_product_add_to_cart_text', 'gueta_length_add_to_cart_text', 10, 2 );

/* -------------------------------------------------------------------------
 * Editing the lengths
 * ---------------------------------------------------------------------- */

/**
 * The lengths tab among the product's data tabs.
 *
 * @param array $tabs Tabs.
 * @return array
 */
function gueta_lengths_admin_tab( $tabs ) {
	if ( gueta_lengths_plugin_active() ) {
		return $tabs;
	}

	$tabs['gueta_lengths'] = [
		'label'    => 'אורכים',
		'target'   => 'gueta_lengths_data',
		'class'    => [],
		'priority' => 65,
	];

	return $tabs;
}
add_filter( 'woocommerce_product_data_tabs', 'gueta_lengths_admin_tab' );

/**
 * One row of the lengths table.
 *
 * @param string $label   Length as the customer sees it.
 * @param string $percent Percentage on the product's price.
 * @return void
 */
function gueta_lengths_admin_row( $label = '', $percent = '' ) {
	?>
	<tr class="gueta-lengths-admin__row">
		<td class="gueta-lengths-admin__handle" title="גררו לשינוי הסדר" aria-hidden="true">&#8942;&#8942;</td>
		<td><input type="text" name="gueta_length[labels][]" value="<?php echo esc_attr( $label ); ?>" placeholder="1.50 מטר"></td>
		<td><input type="number" name="gueta_length[percents][]" value="<?php echo esc_attr( $percent ); ?>" step="any" min="0" placeholder="50" data-length-percent></td>
		<td class="gueta-lengths-admin__price" data-length-price></td>
		<td><button type="button" class="button-link button-link-delete" data-length-remove>הסרה</button></td>
	</tr>
	<?php
}

/**
 * The lengths panel.
 *
 * @return void
 */
function gueta_lengths_admin_panel() {
	global $post;

	if ( gueta_lengths_plugin_active() || ! $post ) {
		return;
	}

	$product = wc_get_product( $post->ID );
	$field   = $product ? gueta_length_field( $product ) : null;
	$name    = $field ? $field['name'] : 'אורך';
	?>
	<div id="gueta_lengths_data" class="panel woocommerce_options_panel hidden gueta-lengths-admin" data-length-admin data-decimals="<?php echo esc_attr( (string) wc_get_price_decimals() ); ?>">
		<input type="hidden" name="gueta_length[present]" value="1">

		<div class="options_group">
			<p class="form-field">
				<label for="gueta_length_name">שם השדה</label>
				<input type="text" class="short" id="gueta_length_name" name="gueta_length[name]" value="<?php echo esc_attr( $name ); ?>">
			</p>
			<p class="form-field">
				<label for="gueta_length_required">חובה לבחור</label>
				<input type="checkbox" class="checkbox" id="gueta_length_required" name="gueta_length[required]" value="1" <?php checked( ! $field || $field['required'] ); ?>>
				<span class="description">בלי בחירה אי אפשר להוסיף את המוצר לסל.</span>
			</p>
		</div>

		<div class="options_group gueta-lengths-admin__body">
			<p class="gueta-lengths-admin__intro">
				כל אורך מוסיף למחיר המוצר אחוז ממנו. במוצר שמחירו למטר, 1.50 מטר הוא 50% ו-4.20 מטר הוא 320%.
				השאירו את הטבלה ריקה כדי לבטל את בחירת האורך במוצר.
			</p>

			<table class="widefat striped gueta-lengths-admin__table">
				<thead>
					<tr>
						<th class="gueta-lengths-admin__handle"><span class="screen-reader-text">סדר</span></th>
						<th>אורך, כפי שיוצג ללקוח</th>
						<th>תוספת באחוזים</th>
						<th>מחיר ליחידה</th>
						<th><span class="screen-reader-text">הסרה</span></th>
					</tr>
				</thead>
				<tbody data-length-rows>
					<?php
					foreach ( $field ? $field['options'] : [] as $option ) {
						gueta_lengths_admin_row( $option['label'], (string) $option['percent'] );
					}
					?>
				</tbody>
			</table>

			<p class="gueta-lengths-admin__actions">
				<button type="button" class="button" data-length-add>הוספת אורך</button>
				<button type="button" class="button" data-length-fill title="לכל שורה: האורך במטרים פחות 1, כפול 100. 2X3 מ' נחשב כ-6.">חישוב האחוזים מהאורכים</button>
			</p>
			<p class="description">המחיר ליחידה מחושב ממחיר המוצר שבלשונית "כללי", לפני עיגול ומחיר אריזה.</p>
		</div>

		<template id="tmpl-gueta-length-row"><?php gueta_lengths_admin_row(); ?></template>
	</div>
	<?php
}
add_action( 'woocommerce_product_data_panels', 'gueta_lengths_admin_panel' );

/**
 * Save the lengths into the product's Add-Ons settings.
 *
 * The first choice list is replaced and anything else the settings hold is
 * kept, so the product reads as before to everything that reads them. It runs
 * inside WooCommerce's own product save, after its nonce and capability check.
 *
 * @param WC_Product $product Product being saved.
 * @return void
 */
function gueta_lengths_admin_save( $product ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked the product form's nonce.
	$posted = isset( $_POST['gueta_length'] ) && is_array( $_POST['gueta_length'] ) ? wp_unslash( $_POST['gueta_length'] ) : null;

	if ( gueta_lengths_plugin_active() || ! $posted || empty( $posted['present'] ) ) {
		return;
	}

	$labels   = array_values( (array) ( $posted['labels'] ?? [] ) );
	$percents = array_values( (array) ( $posted['percents'] ?? [] ) );
	$options  = [];

	foreach ( $labels as $i => $label ) {
		$label   = sanitize_text_field( (string) $label );
		$percent = str_replace( ',', '.', trim( (string) ( $percents[ $i ] ?? '' ) ) );

		if ( '' === $label ) {
			continue;
		}

		$options[] = [
			'label'      => $label,
			'price'      => is_numeric( $percent ) ? (string) (float) $percent : '0',
			'image'      => '',
			'price_type' => 'percentage_based',
		];
	}

	$addons = $product->get_meta( '_product_addons', true );
	$addons = is_array( $addons ) ? $addons : [];
	$index  = null;

	foreach ( $addons as $key => $addon ) {
		if ( is_array( $addon ) && 'multiple_choice' === ( $addon['type'] ?? '' ) ) {
			$index = $key;
			break;
		}
	}

	if ( ! $options ) {
		if ( null !== $index ) {
			unset( $addons[ $index ] );
			$addons = array_values( $addons );
		}
	} else {
		// The shape Product Add-Ons stores, which the feed and the cutting calculator read too.
		$addon = null !== $index ? $addons[ $index ] : [
			'title_format'       => 'label',
			'description_enable' => 0,
			'description'        => '',
			'type'               => 'multiple_choice',
			'display'            => 'select',
			'position'           => 0,
			'restrictions'       => 0,
			'restrictions_type'  => 'any_text',
			'adjust_price'       => 0,
			'price_type'         => 'flat_fee',
			'price'              => '',
			'min'                => 0,
			'max'                => 0,
		];

		$name              = sanitize_text_field( (string) ( $posted['name'] ?? '' ) );
		$addon['name']     = '' !== $name ? $name : 'אורך';
		$addon['required'] = empty( $posted['required'] ) ? 0 : 1;
		$addon['options']  = $options;

		if ( null !== $index ) {
			$addons[ $index ] = $addon;
		} else {
			$addons[] = $addon;
		}
	}

	if ( $addons ) {
		$product->update_meta_data( '_product_addons', $addons );
	} else {
		$product->delete_meta_data( '_product_addons' );
	}
}
add_action( 'woocommerce_admin_process_product_object', 'gueta_lengths_admin_save' );

/**
 * The tab's script and styles, on the product screen only.
 *
 * @return void
 */
function gueta_lengths_admin_assets() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'product' !== $screen->id || gueta_lengths_plugin_active() ) {
		return;
	}

	wp_enqueue_style(
		'gueta-lengths-admin',
		get_stylesheet_directory_uri() . '/assets/css/gueta-lengths-admin.css',
		[],
		gueta_asset_version( '/assets/css/gueta-lengths-admin.css' )
	);

	wp_enqueue_script(
		'gueta-lengths-admin',
		get_stylesheet_directory_uri() . '/assets/js/gueta-lengths-admin.js',
		[ 'jquery', 'jquery-ui-sortable' ],
		gueta_asset_version( '/assets/js/gueta-lengths-admin.js' ),
		true
	);
}
add_action( 'admin_enqueue_scripts', 'gueta_lengths_admin_assets' );
