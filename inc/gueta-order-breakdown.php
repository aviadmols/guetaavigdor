<?php
/**
 * A cut line's price, split into the wood and the cuts.
 *
 * The cutting plugin charges a board as one price: its length and its cuts
 * together, "40.70" for a 3.90 metre board and twelve cuts, with only what the
 * cuts added shown under it. The office keys the two into the ERP apart, the
 * wood by the metre and the cuts by their number. So under each cut line the
 * shop sees both: "מחיר חומר: ₪22.70 (3.90 מ׳ × ₪5.82 למטר)" and
 * "מחיר חיתוכים: ₪18.00 (12 × ₪1.50)", which add up to the line's price. They
 * take the place of the plugin's "תוספת חיתוכים".
 *
 * Both are worked out from the line's saved price, so orders placed before this
 * split too. They are shown on the admin order screen and in the emails sent to
 * the shop. The customer's emails and account keep the plugin's own rows, and
 * an email sent to the customer from the admin screen is still the customer's.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The cutting plugin's details saved on an order line.
 *
 * @param WC_Order_Item_Product $item Order line.
 * @return array|null
 */
function gueta_breakdown_cutting( $item ) {
	$raw = $item->get_meta( '_gac_cutting', true );

	if ( '' === $raw || false === $raw ) {
		$raw = $item->get_meta( 'gac_cutting', true );
	}

	if ( is_string( $raw ) && '' !== $raw ) {
		$raw = json_decode( $raw, true );
	}

	return is_array( $raw ) && ! empty( $raw['mode'] ) ? $raw : null;
}

/**
 * Who the email being written goes to: true for the shop, false for the
 * customer, null when no email is being written.
 *
 * @param bool|null|string $to_shop New state, or 'read' to read it.
 * @return bool|null
 */
function gueta_breakdown_email_to_shop( $to_shop = 'read' ) {
	static $state = null;

	if ( 'read' !== $to_shop ) {
		$state = null === $to_shop ? null : (bool) $to_shop;
	}

	return $state;
}

/**
 * Note who an email is for, as its order table begins.
 *
 * @param WC_Order $order         Order.
 * @param bool     $sent_to_admin Whether the email goes to the shop.
 * @return void
 */
function gueta_breakdown_email_starts( $order, $sent_to_admin ) {
	gueta_breakdown_email_to_shop( (bool) $sent_to_admin );
}
add_action( 'woocommerce_email_before_order_table', 'gueta_breakdown_email_starts', 1, 2 );

/**
 * Forget it once the table is done.
 *
 * @return void
 */
function gueta_breakdown_email_ends() {
	gueta_breakdown_email_to_shop( null );
}
add_action( 'woocommerce_email_after_order_table', 'gueta_breakdown_email_ends', 999, 0 );

/**
 * Whether what is being drawn is for the shop.
 *
 * An email says who it is for. Anything else is the shop's when it is drawn in
 * the admin, the order screen and its reloads of the lines included.
 *
 * @return bool
 */
function gueta_breakdown_for_shop() {
	$email = gueta_breakdown_email_to_shop();

	// is_admin() alone is also true for a visitor's admin-ajax request.
	return null === $email ? is_admin() && current_user_can( 'edit_shop_orders' ) : $email;
}

/**
 * Split a cut line's unit price into its wood and its cuts.
 *
 * The wood is what one unit cost less what its cuts added, which is how the
 * plugin built the price. A board's wood is also given per metre.
 *
 * @param WC_Order_Item_Product $item Order line.
 * @param array                 $gac  The plugin's saved details.
 * @return array{wood:float,metres:float,cuts:int,cut_price:float,board:bool}
 */
function gueta_breakdown_split( $item, $gac ) {
	$board = 'board' === $gac['mode'];

	if ( $board ) {
		$cuts      = isset( $gac['cuts_count'] ) ? (int) $gac['cuts_count'] : 0;
		$cut_price = isset( $gac['cut_price'] ) ? (float) $gac['cut_price'] : 1.5;
	} else {
		$cuts      = isset( $gac['pieces_count'] ) ? (int) $gac['pieces_count'] : ( ! empty( $gac['pieces'] ) && is_array( $gac['pieces'] ) ? count( $gac['pieces'] ) : 0 );
		$cut_price = isset( $gac['piece_price'] ) ? (float) $gac['piece_price'] : 25.0;
	}

	$unit = (float) $item->get_subtotal() / max( 1, (float) $item->get_quantity() );

	return [
		'wood'      => max( 0, $unit - $cuts * $cut_price ),
		'metres'    => $board && ! empty( $gac['length_cm'] ) ? (float) $gac['length_cm'] / 100 : 0,
		'cuts'      => $cuts,
		'cut_price' => $cut_price,
		'board'     => $board,
	];
}

/**
 * Show the wood and the cuts in place of "תוספת חיתוכים", for the shop.
 *
 * @param array                 $formatted_meta Formatted meta.
 * @param WC_Order_Item_Product $item           Order line.
 * @return array
 */
function gueta_breakdown_item_meta( $formatted_meta, $item ) {
	if ( ! $item instanceof WC_Order_Item_Product || ! gueta_breakdown_for_shop() ) {
		return $formatted_meta;
	}

	$gac = gueta_breakdown_cutting( $item );

	if ( ! $gac ) {
		return $formatted_meta;
	}

	$order    = $item->get_order();
	$currency = [ 'currency' => $order ? $order->get_currency() : get_woocommerce_currency() ];
	$split    = gueta_breakdown_split( $item, $gac );
	$money    = static function ( $amount ) use ( $currency ) {
		return wp_strip_all_tags( wc_price( $amount, $currency ) );
	};

	foreach ( $formatted_meta as $meta_id => $meta ) {
		if ( isset( $meta->key ) && 'תוספת חיתוכים' === $meta->key ) {
			unset( $formatted_meta[ $meta_id ] );
		}
	}

	$wood = $money( $split['wood'] );

	if ( $split['metres'] > 0 ) {
		$wood .= sprintf( ' (%s מ׳ × %s למטר)', wc_format_decimal( $split['metres'], 2 ), $money( $split['wood'] / $split['metres'] ) );
	}

	$rows = [ 'gueta_wood_price' => [ 'מחיר חומר', $wood ] ];

	if ( $split['cuts'] > 0 ) {
		$rows['gueta_cuts_price'] = [
			'מחיר חיתוכים',
			sprintf(
				'%s (%s%s × %s)',
				$money( $split['cuts'] * $split['cut_price'] ),
				number_format_i18n( $split['cuts'] ),
				$split['board'] ? '' : ' חתיכות',
				$money( $split['cut_price'] )
			),
		];
	}

	foreach ( $rows as $id => $row ) {
		$formatted_meta[ $id ] = (object) [
			'key'           => $row[0],
			'value'         => $row[1],
			'display_key'   => $row[0],
			'display_value' => esc_html( $row[1] ),
		];
	}

	return $formatted_meta;
}
add_filter( 'woocommerce_order_item_get_formatted_meta_data', 'gueta_breakdown_item_meta', 20, 2 );
