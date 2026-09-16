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
 * Whether Elementor has printed a product price widget on this page yet.
 *
 * The single product template puts one above the buy box. When it has, the
 * box's own price would only repeat it, and the script used to hide that
 * second price once the page had loaded, lifting everything under it.
 *
 * @param bool|null $state New state, or null to read it.
 * @return bool
 */
function gueta_price_widget_rendered( $state = null ) {
	static $rendered = false;

	if ( null !== $state ) {
		$rendered = (bool) $state;
	}

	return $rendered;
}

/**
 * Note a price widget as Elementor prints it.
 *
 * @param string                 $content Rendered widget HTML.
 * @param \Elementor\Widget_Base $widget  Widget.
 * @return string
 */
function gueta_note_price_widget( $content, $widget ) {
	if ( is_object( $widget ) && method_exists( $widget, 'get_name' ) && 'woocommerce-product-price' === $widget->get_name() && '' !== trim( (string) $content ) ) {
		gueta_price_widget_rendered( true );
	}

	return $content;
}
add_filter( 'elementor/widget/render_content', 'gueta_note_price_widget', 5, 2 );

/**
 * The add to cart button as the script would leave it: its words in a label,
 * and beside them the sum of one unit when that is known before anything is
 * chosen, a simple product with no length to pick.
 *
 * @param string     $form    Add to cart form HTML.
 * @param WC_Product $product Product.
 * @return string
 */
function gueta_buy_button_html( $form, $product ) {
	return (string) preg_replace_callback(
		'#(<button\b[^>]*\bclass="[^"]*\bsingle_add_to_cart_button\b[^"]*"[^>]*)>(.*?)</button>#s',
		static function ( $match ) use ( $product ) {
			$label = trim( wp_strip_all_tags( $match[2] ) );

			if ( '' === $label ) {
				return $match[0];
			}

			$sum    = '';
			$length = function_exists( 'gueta_length_field' ) ? gueta_length_field( $product ) : null;

			if ( $product->is_type( 'simple' ) && ( ! $length || ! $length['required'] ) ) {
				$unit = (float) wc_get_price_to_display( $product );

				if ( $unit > 0 ) {
					$money = html_entity_decode( wp_strip_all_tags( wc_price( $unit ) ), ENT_QUOTES, 'UTF-8' );
					$sum   = '<span class="gueta-buy__sum">' . esc_html( str_replace( "\xC2\xA0", ' ', $money ) ) . '</span>';
				}
			}

			return sprintf(
				'%s data-buy-label="%s"><span class="gueta-buy__label">%s</span>%s</button>',
				$match[1],
				esc_attr( $label ),
				esc_html( $label ),
				$sum
			);
		},
		$form,
		1
	);
}

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

	/*
	 * Nothing left to buy: a simple product out of stock, or a variable one
	 * with every variation gone. Its options stay on show, all disabled, and
	 * the form that would buy it gives way to one that waits for it.
	 */
	$sold_out = ! $product->is_in_stock();

	/*
	 * Everything the script would change on arrival is printed already the way
	 * it would leave it, so the box does not jump as the page settles: its own
	 * price stays hidden when the page has shown a price above it, and the
	 * button carries its label, and its sum where one is known before anything
	 * is chosen.
	 */
	$own_hidden = 'off' === $args['price'] || ( 'auto' === $args['price'] && gueta_price_widget_rendered() );
	$form       = gueta_buy_button_html( $form, $product );

	ob_start();
	?>
	<?php
	/*
	 * What one unit costs, and how the shop writes money, for the sum the
	 * button shows. A variable product has no unit price until a variation is
	 * chosen, and the script takes it from that.
	 */
	?>
	<?php // The name and picture the cart drawer shows for the product while it is on its way in. ?>
	<div class="gueta-buy<?php echo $sold_out ? ' is-soldout' : ''; ?>" data-buy data-price="<?php echo esc_attr( $args['price'] ); ?>"<?php echo $args['ajax'] ? ' data-buy-ajax' : ''; ?> data-unit="<?php echo esc_attr( $product->is_type( 'simple' ) ? (string) wc_get_price_to_display( $product ) : '' ); ?>" data-decimals="<?php echo esc_attr( (string) wc_get_price_decimals() ); ?>" data-format="<?php echo esc_attr( get_woocommerce_price_format() ); ?>" data-symbol="<?php echo esc_attr( html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) ); ?>" data-name="<?php echo esc_attr( $product->get_name() ); ?>" data-image="<?php echo esc_url( (string) wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) ); ?>">
		<p class="gueta-buy__price" data-buy-price<?php echo $own_hidden ? ' hidden' : ''; ?>><?php echo wp_kses_post( $product->get_price_html() ); ?></p>

		<?php // A sold out simple product's template prints only its stock line, which the form below repeats. ?>
		<?php if ( ! $sold_out || false !== strpos( $form, '<form' ) ) : ?>
			<div class="gueta-buy__form">
				<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		<?php endif; ?>

		<?php if ( $sold_out ) : ?>
			<?php echo gueta_notify_form_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php else : ?>
			<?php // Shown by the script while a variable product has nothing chosen. ?>
			<p class="gueta-buy__hint" data-buy-hint hidden>בחרו אפשרות כדי להמשיך</p>
		<?php endif; ?>

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
			// Add without leaving the page. Pass ajax="no" for the plain post.
			'ajax'    => 'yes',
		],
		$atts,
		'gueta_buy'
	);

	$product = null;

	if ( $atts['product'] && function_exists( 'wc_get_product' ) ) {
		$candidate = wc_get_product( (int) $atts['product'] );
		$product   = $candidate instanceof WC_Product ? $candidate : null;
	}

	$plain = [ 'no', 'false', '0', 'off' ];

	return gueta_buy_box_html(
		$product,
		[
			'price' => $atts['price'],
			'ajax'  => ! in_array( strtolower( trim( (string) $atts['ajax'] ) ), $plain, true ),
		]
	);
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

	/*
	 * Added without leaving the page: the drawer opens on the press and fills
	 * in as the cart answers, rather than after a whole page has reloaded.
	 * Without the script the form still posts in the ordinary way.
	 */
	$html = gueta_buy_box_html( $product, [ 'ajax' => true ] );

	gueta_diag( 'buy_box', [ 'product' => $product->get_id(), 'rendered' => (int) (bool) $html ] );

	return $html ? $html : $content;
}
add_filter( 'elementor/widget/render_content', 'gueta_replace_add_to_cart_widget', 10, 2 );
