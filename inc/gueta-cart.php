<?php
/**
 * Slide out cart drawer backed by WooCommerce cart fragments.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Force WooCommerce's AJAX add to cart on, so archives add without a reload and
 * the drawer can open in place. Filter gueta_force_ajax_add_to_cart to false to
 * hand the decision back to the WooCommerce setting.
 *
 * @param mixed $value Stored option value.
 * @return mixed
 */
function gueta_force_ajax_add_to_cart( $value ) {
	return apply_filters( 'gueta_force_ajax_add_to_cart', true ) ? 'yes' : $value;
}
add_filter( 'option_woocommerce_enable_ajax_add_to_cart', 'gueta_force_ajax_add_to_cart' );
add_filter( 'default_option_woocommerce_enable_ajax_add_to_cart', 'gueta_force_ajax_add_to_cart' );

/**
 * Number of items currently in the cart.
 *
 * @return int
 */
function gueta_cart_count() {
	if ( ! gueta_has_woocommerce() || ! WC()->cart ) {
		return 0;
	}

	return (int) WC()->cart->get_cart_contents_count();
}

/**
 * Render the badge that sits on the cart button.
 *
 * @return string
 */
function gueta_cart_count_html() {
	$count = gueta_cart_count();

	return sprintf(
		'<span class="gueta-cart-count%s" data-cart-count>%s</span>',
		$count ? '' : ' is-empty',
		esc_html( number_format_i18n( $count ) )
	);
}

/**
 * Render everything inside the drawer body, both the lines and the totals.
 *
 * @return string
 */
function gueta_cart_drawer_html() {
	ob_start();
	?>
	<div class="gueta-drawer__body" data-cart-body>
		<?php
		if ( ! gueta_has_woocommerce() || ! WC()->cart || WC()->cart->is_empty() ) {
			gueta_render_empty_cart();
		} else {
			gueta_render_cart_lines();
		}
		?>
	</div>
	<?php
	if ( gueta_has_woocommerce() && WC()->cart && ! WC()->cart->is_empty() ) {
		gueta_render_cart_footer();
	}

	return (string) ob_get_clean();
}

/**
 * Empty state: a short message plus the categories worth browsing first.
 *
 * @return void
 */
function gueta_render_empty_cart() {
	$terms = [];

	if ( taxonomy_exists( 'product_cat' ) ) {
		$terms = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'parent'     => 0,
				'hide_empty' => true,
				'orderby'    => 'count',
				'order'      => 'DESC',
				'number'     => 4,
			]
		);
		$terms = is_wp_error( $terms ) ? [] : $terms;
	}
	?>
	<div class="gueta-cart-empty">
		<p class="gueta-cart-empty__title">העגלה שלך ריקה</p>
		<?php if ( $terms ) : ?>
			<p class="gueta-cart-empty__text">לא בטוחים מאיפה להתחיל? נסו את הקטגוריות הבאות:</p>
			<div class="gueta-cart-empty__grid">
				<?php
				foreach ( $terms as $term ) :
					$link = get_term_link( $term );

					if ( is_wp_error( $link ) ) {
						continue;
					}

					$thumbnail_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
					$image        = $thumbnail_id ? wp_get_attachment_image_url( $thumbnail_id, 'woocommerce_thumbnail' ) : '';
					?>
					<a class="gueta-cart-empty__card" href="<?php echo esc_url( $link ); ?>">
						<span class="gueta-cart-empty__media">
							<?php if ( $image ) : ?>
								<img src="<?php echo esc_url( $image ); ?>" alt="" loading="lazy">
							<?php endif; ?>
						</span>
						<span class="gueta-cart-empty__name">
							<?php echo esc_html( $term->name ); ?>
							<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M14 5 7 12l7 7"></path></svg>
						</span>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<a class="gueta-button gueta-button--solid" href="<?php echo esc_url( gueta_shop_url() ); ?>">המשך בקניות</a>
	</div>
	<?php
}

/**
 * Render the cart lines with quantity controls.
 *
 * @return void
 */
function gueta_render_cart_lines() {
	?>
	<ul class="gueta-cart-lines">
		<?php
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$product = $cart_item['data'];

			if ( ! $product || ! $product->exists() || $cart_item['quantity'] <= 0 ) {
				continue;
			}

			$permalink = $product->is_visible() ? $product->get_permalink( $cart_item ) : '';
			?>
			<li class="gueta-cart-line" data-cart-line="<?php echo esc_attr( $cart_item_key ); ?>">
				<span class="gueta-cart-line__media">
					<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?>
				</span>
				<div class="gueta-cart-line__body">
					<p class="gueta-cart-line__title">
						<?php if ( $permalink ) : ?>
							<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $product->get_name() ); ?>
						<?php endif; ?>
					</p>
					<?php
					$meta = wc_get_formatted_cart_item_data( $cart_item, true );
					if ( $meta ) :
						?>
						<p class="gueta-cart-line__meta"><?php echo wp_kses_post( $meta ); ?></p>
					<?php endif; ?>
					<div class="gueta-cart-line__row">
						<div class="gueta-cart-qty">
							<button type="button" class="gueta-cart-qty__button" data-cart-decrease aria-label="הפחתת כמות">&minus;</button>
							<input
								class="gueta-cart-qty__input"
								type="number"
								inputmode="numeric"
								min="0"
								value="<?php echo esc_attr( $cart_item['quantity'] ); ?>"
								aria-label="כמות"
								data-cart-qty
							>
							<button type="button" class="gueta-cart-qty__button" data-cart-increase aria-label="הוספת כמות">+</button>
						</div>
						<span class="gueta-cart-line__price"><?php echo wp_kses_post( WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] ) ); ?></span>
					</div>
				</div>
				<button type="button" class="gueta-cart-line__remove" data-cart-remove aria-label="הסרת המוצר">
					<svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"></path></svg>
				</button>
			</li>
			<?php
		}
		?>
	</ul>
	<?php
}

/**
 * Render the subtotal and the checkout actions.
 *
 * @return void
 */
function gueta_render_cart_footer() {
	?>
	<div class="gueta-drawer__footer">
		<div class="gueta-cart-total">
			<span>סה"כ ביניים</span>
			<strong><?php echo wp_kses_post( WC()->cart->get_cart_subtotal() ); ?></strong>
		</div>
		<p class="gueta-cart-note">המשלוח והמע"מ מחושבים בעמוד התשלום</p>
		<a class="gueta-button gueta-button--solid" href="<?php echo esc_url( wc_get_checkout_url() ); ?>">מעבר לתשלום</a>
		<a class="gueta-button gueta-button--ghost" href="<?php echo esc_url( wc_get_cart_url() ); ?>">צפייה בעגלה</a>
	</div>
	<?php
}

/**
 * Shop URL, falling back to the site root before WooCommerce pages exist.
 *
 * @return string
 */
function gueta_shop_url() {
	if ( function_exists( 'wc_get_page_permalink' ) ) {
		$shop = wc_get_page_permalink( 'shop' );

		if ( $shop ) {
			return $shop;
		}
	}

	return home_url( '/' );
}

/**
 * Wrapper element the cart fragment replaces.
 *
 * The menu drawer uses the same panel class, so the fragment is keyed on a
 * class only the cart carries.
 *
 * @return string
 */
function gueta_cart_panel_html() {
	return '<div class="gueta-drawer__panel gueta-cart-panel" role="dialog" aria-modal="true" aria-labelledby="gueta-cart-title">'
		. gueta_drawer_panel_inner()
		. '</div>';
}

/**
 * Keep the badge and the drawer in sync with every WooCommerce cart change.
 *
 * @param array $fragments Cart fragments.
 * @return array
 */
function gueta_cart_fragments( $fragments ) {
	$fragments['span.gueta-cart-count'] = gueta_cart_count_html();
	$fragments['div.gueta-cart-panel']  = gueta_cart_panel_html();

	return $fragments;
}
add_filter( 'woocommerce_add_to_cart_fragments', 'gueta_cart_fragments' );

/**
 * Contents of the drawer panel: header, body and footer.
 *
 * @return string
 */
function gueta_drawer_panel_inner() {
	$notice = gueta_shipping_notice();

	ob_start();
	?>
	<?php if ( $notice ) : ?>
		<p class="gueta-drawer__strip"><?php echo esc_html( $notice ); ?></p>
	<?php endif; ?>
	<div class="gueta-drawer__head">
		<h2 class="gueta-drawer__title" id="gueta-cart-title">העגלה שלך</h2>
		<button type="button" class="gueta-icon-button" data-drawer-close aria-label="סגירת העגלה">
			<svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"></path></svg>
		</button>
	</div>
	<?php echo gueta_cart_drawer_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	<?php

	return (string) ob_get_clean();
}

/**
 * Optional strip above the cart title.
 *
 * @return string
 */
function gueta_shipping_notice() {
	return (string) apply_filters( 'gueta_cart_shipping_notice', 'משלוח מהיר לכל הארץ' );
}

/**
 * Single product pages post the add to cart form and redirect, so remember that
 * it happened and let the next page load open the drawer.
 *
 * @return void
 */
function gueta_flag_cart_addition() {
	if ( wp_doing_ajax() || ! gueta_has_woocommerce() || ! WC()->session ) {
		return;
	}

	WC()->session->set( 'gueta_open_cart', true );
}
add_action( 'woocommerce_add_to_cart', 'gueta_flag_cart_addition', 20 );

/**
 * Read and clear that flag.
 *
 * @return bool
 */
function gueta_should_open_cart() {
	if ( ! gueta_has_woocommerce() || ! WC()->session ) {
		return false;
	}

	if ( ! WC()->session->get( 'gueta_open_cart' ) ) {
		return false;
	}

	WC()->session->set( 'gueta_open_cart', false );

	return true;
}

/**
 * Update a cart line from the drawer and return the refreshed markup.
 *
 * @return void
 */
function gueta_ajax_cart_update() {
	check_ajax_referer( 'gueta_header', 'nonce' );

	if ( ! gueta_has_woocommerce() || ! WC()->cart ) {
		wp_send_json_error( [ 'message' => 'החנות אינה זמינה כרגע.' ], 400 );
	}

	$key      = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	$quantity = isset( $_POST['quantity'] ) ? max( 0, (int) wp_unslash( $_POST['quantity'] ) ) : 0;

	if ( ! $key || ! WC()->cart->get_cart_item( $key ) ) {
		wp_send_json_error( [ 'message' => 'המוצר כבר לא נמצא בעגלה.' ], 404 );
	}

	if ( 0 === $quantity ) {
		WC()->cart->remove_cart_item( $key );
	} else {
		WC()->cart->set_quantity( $key, $quantity, true );
	}

	WC()->cart->calculate_totals();

	wp_send_json_success(
		[
			'panel' => gueta_drawer_panel_inner(),
			'count' => gueta_cart_count(),
			'badge' => gueta_cart_count_html(),
		]
	);
}
add_action( 'wp_ajax_gueta_cart_update', 'gueta_ajax_cart_update' );
add_action( 'wp_ajax_nopriv_gueta_cart_update', 'gueta_ajax_cart_update' );

/**
 * Return the drawer contents, used when the drawer opens on a cached page.
 *
 * @return void
 */
function gueta_ajax_cart_refresh() {
	check_ajax_referer( 'gueta_header', 'nonce' );

	wp_send_json_success(
		[
			'panel' => gueta_drawer_panel_inner(),
			'count' => gueta_cart_count(),
			'badge' => gueta_cart_count_html(),
		]
	);
}
add_action( 'wp_ajax_gueta_cart_refresh', 'gueta_ajax_cart_refresh' );
add_action( 'wp_ajax_nopriv_gueta_cart_refresh', 'gueta_ajax_cart_refresh' );
