<?php
/**
 * Whether the shop has called the customer about an order.
 *
 * A box on each order's admin screen marks it with one button: "התקשרתי ללקוח".
 * The mark is saved at once, without updating the order, together with when and
 * by whom, and the same button takes it off. The orders list gets a narrow
 * column with a small green phone on every order already called, so the next
 * one to call is plain to see.
 *
 * Works on both of WooCommerce's order screens, the newer order tables and the
 * older one kept in posts.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GUETA_CONTACTED_META', '_gueta_contacted' );

/**
 * When and by whom an order was called, or null if it was not.
 *
 * @param WC_Order $order Order.
 * @return array{time:int,by:string}|null
 */
function gueta_contacted( $order ) {
	$mark = $order->get_meta( GUETA_CONTACTED_META, true );

	return is_array( $mark ) && ! empty( $mark['time'] ) ? $mark : null;
}

/**
 * How a mark reads: "23.11.2026 14:05 · אביעד".
 *
 * @param array $mark Mark from gueta_contacted().
 * @return string
 */
function gueta_contacted_text( $mark ) {
	return trim( wp_date( 'd.m.Y H:i', (int) $mark['time'] ) . ( '' !== (string) $mark['by'] ? ' · ' . $mark['by'] : '' ) );
}

/**
 * The order screen ids, newer and older.
 *
 * @return string[]
 */
function gueta_contacted_screens() {
	$screens = [ 'shop_order' ];

	if ( function_exists( 'wc_get_page_screen_id' ) ) {
		$screens[] = wc_get_page_screen_id( 'shop-order' );
	}

	return array_unique( $screens );
}

/**
 * The box at the top of the order screen's side column.
 *
 * @return void
 */
function gueta_contacted_meta_box() {
	foreach ( gueta_contacted_screens() as $screen ) {
		add_meta_box( 'gueta-order-contacted', 'שיחה עם הלקוח', 'gueta_contacted_meta_box_html', $screen, 'side', 'high' );
	}
}
add_action( 'add_meta_boxes', 'gueta_contacted_meta_box', 30 );

/**
 * Print the box: the button, and when it was marked.
 *
 * @param WP_Post|WC_Order $object The order, or its post on the older screen.
 * @return void
 */
function gueta_contacted_meta_box_html( $object ) {
	$order = $object instanceof WC_Order ? $object : wc_get_order( $object instanceof WP_Post ? $object->ID : 0 );

	if ( ! $order ) {
		return;
	}

	$mark = gueta_contacted( $order );
	?>
	<div class="gueta-contacted" data-order="<?php echo esc_attr( $order->get_id() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'gueta_contacted' ) ); ?>">
		<p class="gueta-contacted__state" style="margin:0 0 10px;<?php echo $mark ? 'color:#1a7f37;font-weight:600;' : 'color:#6d6a65;'; ?>">
			<?php echo $mark ? '✓ נוצר קשר עם הלקוח' : 'עוד לא התקשרו ללקוח'; ?>
		</p>
		<p class="gueta-contacted__when" style="margin:0 0 10px;color:#6d6a65;font-size:12px;"<?php echo $mark ? '' : ' hidden'; ?>><?php echo $mark ? esc_html( gueta_contacted_text( $mark ) ) : ''; ?></p>
		<button type="button" class="button <?php echo $mark ? '' : 'button-primary'; ?> gueta-contacted__toggle" data-contacted="<?php echo $mark ? '1' : '0'; ?>" style="width:100%;">
			<?php echo $mark ? 'ביטול הסימון' : 'התקשרתי ללקוח'; ?>
		</button>
	</div>
	<script>
	( function () {
		var box = document.querySelector( '#gueta-order-contacted .gueta-contacted' );
		var button = box && box.querySelector( '.gueta-contacted__toggle' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var on = '1' !== button.dataset.contacted;
			var body = new FormData();

			body.append( 'action', 'gueta_order_contacted' );
			body.append( 'order', box.dataset.order );
			body.append( 'contacted', on ? '1' : '0' );
			body.append( '_wpnonce', box.dataset.nonce );
			button.disabled = true;

			fetch( window.ajaxurl, { method: 'POST', credentials: 'same-origin', body: body } )
				.then( function ( response ) { return response.json(); } )
				.then( function ( reply ) {
					if ( ! reply || ! reply.success ) {
						throw new Error();
					}

					var state = box.querySelector( '.gueta-contacted__state' );
					var when = box.querySelector( '.gueta-contacted__when' );

					button.dataset.contacted = on ? '1' : '0';
					button.textContent = on ? 'ביטול הסימון' : 'התקשרתי ללקוח';
					button.classList.toggle( 'button-primary', ! on );
					state.textContent = on ? '✓ נוצר קשר עם הלקוח' : 'עוד לא התקשרו ללקוח';
					state.style.color = on ? '#1a7f37' : '#6d6a65';
					state.style.fontWeight = on ? '600' : '';
					when.textContent = reply.data.text || '';
					when.hidden = ! on;
				} )
				.catch( function () {
					window.alert( 'השמירה נכשלה. נסו שוב.' );
				} )
				.then( function () {
					button.disabled = false;
				} );
		} );
	}() );
	</script>
	<?php
}

/**
 * Save the mark, or take it off, as the button is pressed.
 *
 * @return void
 */
function gueta_contacted_ajax() {
	check_ajax_referer( 'gueta_contacted' );

	$order = wc_get_order( isset( $_POST['order'] ) ? absint( $_POST['order'] ) : 0 );

	if ( ! $order || ! current_user_can( 'edit_shop_orders' ) ) {
		wp_send_json_error( null, 403 );
	}

	if ( ! empty( $_POST['contacted'] ) ) {
		$user = wp_get_current_user();
		$mark = [
			'time' => time(),
			'by'   => $user->exists() ? $user->display_name : '',
		];

		$order->update_meta_data( GUETA_CONTACTED_META, $mark );
		$order->save();

		wp_send_json_success( [ 'text' => gueta_contacted_text( $mark ) ] );
	}

	$order->delete_meta_data( GUETA_CONTACTED_META );
	$order->save();

	wp_send_json_success( [ 'text' => '' ] );
}
add_action( 'wp_ajax_gueta_order_contacted', 'gueta_contacted_ajax' );

/**
 * A narrow column after the order's number in the orders list.
 *
 * @param array $columns Columns.
 * @return array
 */
function gueta_contacted_column( $columns ) {
	$placed = [];

	foreach ( $columns as $key => $label ) {
		$placed[ $key ] = $label;

		if ( 'order_number' === $key ) {
			$placed['gueta_contacted'] = 'קשר';
		}
	}

	if ( ! isset( $placed['gueta_contacted'] ) ) {
		$placed['gueta_contacted'] = 'קשר';
	}

	return $placed;
}
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'gueta_contacted_column', 20 );
add_filter( 'manage_edit-shop_order_columns', 'gueta_contacted_column', 20 );

/**
 * The phone on an order already called, with when and by whom on hover.
 *
 * @param string       $column   Column.
 * @param WC_Order|int $order_id The order, or its post id on the older screen.
 * @return void
 */
function gueta_contacted_column_html( $column, $order_id ) {
	if ( 'gueta_contacted' !== $column ) {
		return;
	}

	$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
	$mark  = $order ? gueta_contacted( $order ) : null;

	if ( $mark ) {
		printf(
			'<span class="gueta-contacted-mark" title="%1$s" aria-label="%1$s"><span class="dashicons dashicons-phone" aria-hidden="true"></span></span>',
			esc_attr( 'נוצר קשר · ' . gueta_contacted_text( $mark ) )
		);
	}
}
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'gueta_contacted_column_html', 10, 2 );
add_action( 'manage_shop_order_posts_custom_column', 'gueta_contacted_column_html', 10, 2 );

/**
 * Keep the column narrow, and the phone small and green.
 *
 * @return void
 */
function gueta_contacted_list_styles() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || ! in_array( $screen->id, [ 'edit-shop_order', 'woocommerce_page_wc-orders' ], true ) ) {
		return;
	}
	?>
	<style>
		.wp-list-table .column-gueta_contacted { width: 44px; text-align: center; }
		.gueta-contacted-mark { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; background: #e6f4ea; color: #1a7f37; }
		.gueta-contacted-mark .dashicons { font-size: 15px; width: 15px; height: 15px; }
	</style>
	<?php
}
add_action( 'admin_head', 'gueta_contacted_list_styles' );
