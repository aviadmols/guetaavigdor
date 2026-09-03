<?php
/**
 * The buy box: price, variation swatches, quantity and the call to action.
 *
 * WooCommerce's own add to cart form stays inside it and keeps doing the work
 * — the variations, the validation and the post to the cart are all still its
 * — so one block can stand in for Elementor's Add to Cart widget on the
 * product page and for the cart section of quick view.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a buy box is being rendered right now.
 *
 * The quantity hooks below fire inside every WooCommerce quantity input, the
 * cart's own included, so they ask this before adding their steppers.
 *
 * @param bool|null $state New state, or null to read the current one.
 * @return bool
 */
function gueta_buying( $state = null ) {
	static $active = false;

	if ( null !== $state ) {
		$active = (bool) $state;
	}

	return $active;
}

/**
 * One side of the quantity stepper.
 *
 * Printed before and after the field, which in a right to left column puts the
 * minus on the right and the plus on the left, as the cart drawer has them.
 *
 * @param int $delta Direction, -1 or 1.
 * @return void
 */
function gueta_quantity_step( $delta ) {
	if ( ! gueta_buying() ) {
		return;
	}

	printf(
		'<button type="button" class="gueta-qty" data-qty-step="%1$d" aria-label="%2$s">%3$s</button>',
		(int) $delta,
		esc_attr( $delta > 0 ? 'הוספת יחידה' : 'הפחתת יחידה' ),
		$delta > 0 ? '+' : '&minus;'
	);
}

/**
 * The minus, before the field.
 *
 * @return void
 */
function gueta_quantity_minus() {
	gueta_quantity_step( -1 );
}
add_action( 'woocommerce_before_quantity_input_field', 'gueta_quantity_minus' );

/**
 * The plus, after the field.
 *
 * @return void
 */
function gueta_quantity_plus() {
	gueta_quantity_step( 1 );
}
add_action( 'woocommerce_after_quantity_input_field', 'gueta_quantity_plus' );

/**
 * Render the buy box for a product.
 *
 * @param WC_Product|null $product Product; defaults to the one being viewed.
 * @param array           $args    price: on, off, or auto to stand down when
 *                                 the page already carries a price widget.
 *                                 ajax: add to the cart without leaving.
 * @return string
 */
function gueta_buy_box_html( $product = null, $args = [] ) {
	if ( ! $product instanceof WC_Product ) {
		$product = gueta_queried_product();
	}

	if ( ! $product instanceof WC_Product ) {
		$product = gueta_current_product();
	}

	if ( ! $product instanceof WC_Product ) {
		return '';
	}

	$args = wp_parse_args(
		$args,
		[
			'price' => 'auto',
			'ajax'  => false,
		]
	);

	// The form is built against this product whatever the page left in the
	// global, which on an Elementor template is the widget's own product.
	$previous           = $GLOBALS['product'] ?? null;
	$GLOBALS['product'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

	gueta_buying( true );

	ob_start();
	woocommerce_template_single_add_to_cart();
	$form = trim( (string) ob_get_clean() );

	gueta_buying( false );

	$GLOBALS['product'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

	if ( '' === $form ) {
		return '';
	}

	ob_start();
	?>
	<div class="gueta-buy" data-buy data-price="<?php echo esc_attr( $args['price'] ); ?>"<?php echo $args['ajax'] ? ' data-buy-ajax' : ''; ?>>
		<p class="gueta-buy__price" data-buy-price><?php echo wp_kses_post( $product->get_price_html() ); ?></p>

		<div class="gueta-buy__form">
			<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<?php // Shown by the script while a variable product has nothing chosen. ?>
		<p class="gueta-buy__hint" data-buy-hint hidden>בחרו אפשרות כדי להמשיך</p>

		<?php
		/*
		 * Where the stock line of a chosen variation goes. A simple product's
		 * form prints its own, so this stays empty for one; it is printed
		 * tight because the stylesheet drops it while it holds nothing.
		 */
		?>
		<div class="gueta-buy__stock" data-buy-stock></div>
	</div>
	<?php

	return (string) ob_get_clean();
}

/**
 * Echo the buy box.
 *
 * @param WC_Product|null $product Product.
 * @param array           $args    See gueta_buy_box_html().
 * @return void
 */
function gueta_render_buy_box( $product = null, $args = [] ) {
	echo gueta_buy_box_html( $product, $args ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * [gueta_buy] — the buy box, for a Shortcode widget in an Elementor template.
 *
 * Drop it where the Add to Cart widget sits: it builds the form for whichever
 * product is being viewed, so a variable product gets its variations as
 * buttons, and it carries the quantity and the price with it.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function gueta_buy_shortcode( $atts ) {
	$atts = shortcode_atts(
		[
			'product' => 0,
			'price'   => 'auto',
		],
		$atts,
		'gueta_buy'
	);

	$product = null;

	if ( $atts['product'] && function_exists( 'wc_get_product' ) ) {
		$candidate = wc_get_product( (int) $atts['product'] );
		$product   = $candidate instanceof WC_Product ? $candidate : null;
	}

	return gueta_buy_box_html( $product, [ 'price' => $atts['price'] ] );
}

/**
 * Register the shortcode.
 *
 * @return void
 */
function gueta_buy_shortcodes() {
	add_shortcode( 'gueta_buy', 'gueta_buy_shortcode' );
	// The name this block was first published under.
	add_shortcode( 'gueta_add_to_cart', 'gueta_buy_shortcode' );
}
add_action( 'init', 'gueta_buy_shortcodes' );

/**
 * Stand in for Elementor's Add to Cart widget on a product page.
 *
 * The widget on this shop's single template is pinned to one product, so on
 * every other product it renders that product's form: the wrong name in the
 * form action and, for a variable product, no variations at all. The whole
 * widget is replaced with the buy box for the product being viewed.
 *
 * @param string                 $content Rendered widget HTML.
 * @param \Elementor\Widget_Base $widget  Widget instance.
 * @return string
 */
function gueta_replace_add_to_cart_widget( $content, $widget ) {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return $content;
	}

	$name = ( is_object( $widget ) && method_exists( $widget, 'get_name' ) ) ? $widget->get_name() : '';

	if ( 'wc-add-to-cart' !== $name ) {
		return $content;
	}

	$product = gueta_queried_product();

	if ( ! $product ) {
		return $content;
	}

	$html = gueta_buy_box_html( $product );

	gueta_diag( 'buy_box', [ 'product' => $product->get_id(), 'rendered' => (int) (bool) $html ] );

	return $html ? $html : $content;
}
add_filter( 'elementor/widget/render_content', 'gueta_replace_add_to_cart_widget', 10, 2 );
