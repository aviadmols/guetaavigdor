<?php
/**
 * An order with cuts, priced the way it is keyed into the office's ERP.
 *
 * The cutting plugin charges a board as one line: its length and its cuts
 * together, "40.70" for a 3.90 metre board and twelve cuts. The ERP takes the
 * same board as two lines, the wood by the metre under the product's code and
 * the cuts under the cutting code. Each order with cuts gets a table that
 * splits every line that way, in the ERP's own columns and order: the code, the
 * description, the length, the count, the quantity sold, the unit price and the
 * line total.
 *
 * The table is shown in a box on the admin order screen, with a button that
 * copies it for Excel, and under the order in the emails sent to the shop. The
 * customer sees neither. It is worked out from each line's saved amount, so it
 * also splits orders placed before it was written, and its total is the
 * order's items before VAT.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The cutting line's code and description in the ERP.
 *
 * @return array{code:string,name:string}
 */
function gueta_breakdown_cut_item() {
	return apply_filters(
		'gueta_breakdown_cut_item',
		[
			'code' => '10130',
			'name' => 'חיתוך משור למידה - עץ',
		]
	);
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
 * Whether any line of the order was cut.
 *
 * @param WC_Order $order Order.
 * @return bool
 */
function gueta_breakdown_has_cuts( $order ) {
	foreach ( $order->get_items() as $item ) {
		if ( $item instanceof WC_Order_Item_Product && gueta_breakdown_cutting( $item ) ) {
			return true;
		}
	}

	return false;
}

/**
 * The order's lines, a cut line split into its wood and its cuts.
 *
 * The wood is what the line cost less what its cuts added, which is how the
 * plugin built the price. A board's wood is counted in metres, its unit price
 * per metre, as the ERP takes it; a panel's by the panel. Amounts are before
 * VAT and before any coupon, as the line's subtotal is.
 *
 * @param WC_Order $order Order.
 * @return array<int,array{kind:string,code:string,name:string,note:string,length:string,count:float,quantity:float,unit:float,total:float}>
 */
function gueta_breakdown_rows( $order ) {
	$rows = [];
	$cut  = gueta_breakdown_cut_item();

	foreach ( $order->get_items() as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}

		$count    = max( 1, (float) $item->get_quantity() );
		$subtotal = (float) $item->get_subtotal();
		$product  = $item->get_product();
		$code     = $product ? (string) $product->get_sku() : '';
		$name     = wp_strip_all_tags( $item->get_name() );
		$gac      = gueta_breakdown_cutting( $item );

		if ( ! $gac ) {
			$rows[] = [
				'kind'     => 'item',
				'code'     => $code,
				'name'     => $name,
				'note'     => '',
				'length'   => '',
				'count'    => $count,
				'quantity' => $count,
				'unit'     => $subtotal / $count,
				'total'    => $subtotal,
			];
			continue;
		}

		if ( 'board' === $gac['mode'] ) {
			$cuts      = isset( $gac['cuts_count'] ) ? (int) $gac['cuts_count'] : 0;
			$cut_price = isset( $gac['cut_price'] ) ? (float) $gac['cut_price'] : 1.5;
			$metres    = ! empty( $gac['length_cm'] ) ? (float) $gac['length_cm'] / 100 : 0;
			$note      = ! empty( $gac['cuts_text'] ) && class_exists( 'GAC_Sketch' ) ? GAC_Sketch::group_cuts_text( $gac['cuts_text'] ) : ( isset( $gac['cuts_text'] ) ? (string) $gac['cuts_text'] : '' );
		} else {
			$cuts      = isset( $gac['pieces_count'] ) ? (int) $gac['pieces_count'] : ( ! empty( $gac['pieces'] ) && is_array( $gac['pieces'] ) ? count( $gac['pieces'] ) : 0 );
			$cut_price = isset( $gac['piece_price'] ) ? (float) $gac['piece_price'] : 25.0;
			$metres    = 0;
			$note      = isset( $gac['cuts_text'] ) ? (string) $gac['cuts_text'] : '';
		}

		$fee  = $cuts * $cut_price;
		$wood = max( 0, $subtotal / $count - $fee );

		$rows[] = [
			'kind'     => 'wood',
			'code'     => $code,
			'name'     => $name,
			'note'     => '',
			'length'   => $metres ? wc_format_decimal( $metres, 2 ) : ( isset( $gac['label'] ) ? (string) $gac['label'] : '' ),
			'count'    => $count,
			'quantity' => $metres ? $metres * $count : $count,
			'unit'     => $metres ? $wood / $metres : $wood,
			'total'    => $wood * $count,
		];

		if ( $cuts > 0 ) {
			$rows[] = [
				'kind'     => 'cut',
				'code'     => $cut['code'],
				'name'     => $cut['name'],
				'note'     => $note,
				'length'   => '',
				'count'    => $cuts * $count,
				'quantity' => $cuts * $count,
				'unit'     => $cut_price,
				'total'    => $fee * $count,
			];
		}
	}

	return $rows;
}

/**
 * The table's column headings, in the ERP's order.
 *
 * @return string[]
 */
function gueta_breakdown_headings() {
	return [ '#', 'קוד פריט', 'תיאור פריט', 'אורך', 'כמות', 'כמות למכירה', 'מחיר יחידה', 'סה״כ' ];
}

/**
 * A number as the table shows it: no trailing zeros on a count, four places
 * on a unit price as the ERP keeps it, two on a total.
 *
 * @param float $number Number.
 * @param int   $places Decimal places, or 0 for a count.
 * @return string
 */
function gueta_breakdown_number( $number, $places ) {
	if ( 0 === $places ) {
		return (string) ( 0 + round( $number, 4 ) );
	}

	return number_format( (float) $number, $places, '.', '' );
}

/**
 * The table as tab separated text, which Excel pastes into its own cells.
 *
 * @param array $rows Rows from gueta_breakdown_rows().
 * @return string
 */
function gueta_breakdown_tsv( $rows ) {
	$lines = [ implode( "\t", gueta_breakdown_headings() ) ];

	foreach ( array_values( $rows ) as $index => $row ) {
		$lines[] = implode(
			"\t",
			array_map(
				static function ( $cell ) {
					return str_replace( [ "\t", "\r", "\n" ], ' ', (string) $cell );
				},
				[
					$index + 1,
					$row['code'],
					$row['name'],
					$row['length'],
					gueta_breakdown_number( $row['count'], 0 ),
					gueta_breakdown_number( $row['quantity'], 0 ),
					gueta_breakdown_number( $row['unit'], 4 ),
					gueta_breakdown_number( $row['total'], 2 ),
				]
			)
		);
	}

	return implode( "\n", $lines );
}

/**
 * The table as HTML, with its styles written on each cell so an email keeps them.
 *
 * @param WC_Order $order Order.
 * @param array    $rows  Rows from gueta_breakdown_rows().
 * @return string
 */
function gueta_breakdown_html( $order, $rows ) {
	$currency = [ 'currency' => $order->get_currency() ];
	$cell     = 'border-bottom:1px solid #e5e5e5;padding:8px 10px;text-align:start;vertical-align:top;font-size:13px;';
	$number   = $cell . 'direction:ltr;text-align:end;white-space:nowrap;';
	$sum      = 0;

	ob_start();
	?>
	<table class="gueta-breakdown" cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;margin:0 0 8px;color:#161616;">
		<thead>
			<tr>
				<?php foreach ( gueta_breakdown_headings() as $heading ) : ?>
					<th style="<?php echo esc_attr( $cell ); ?>background:#f6f6f6;color:#6d6a65;font-weight:600;"><?php echo esc_html( $heading ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( array_values( $rows ) as $index => $row ) : ?>
				<?php $sum += $row['total']; ?>
				<tr<?php echo 'cut' === $row['kind'] ? ' style="background:#fff8f4;"' : ''; ?>>
					<td style="<?php echo esc_attr( $number ); ?>color:#6d6a65;"><?php echo esc_html( $index + 1 ); ?></td>
					<td style="<?php echo esc_attr( $number ); ?>"><?php echo esc_html( $row['code'] ); ?></td>
					<td style="<?php echo esc_attr( $cell ); ?>">
						<?php echo esc_html( $row['name'] ); ?>
						<?php if ( '' !== $row['note'] ) : ?>
							<div style="color:#6d6a65;font-size:12px;margin-top:2px;"><?php echo esc_html( $row['note'] ); ?></div>
						<?php endif; ?>
					</td>
					<td style="<?php echo esc_attr( $number ); ?>"><?php echo esc_html( $row['length'] ); ?></td>
					<td style="<?php echo esc_attr( $number ); ?>"><?php echo esc_html( gueta_breakdown_number( $row['count'], 0 ) ); ?></td>
					<td style="<?php echo esc_attr( $number ); ?>font-weight:600;"><?php echo esc_html( gueta_breakdown_number( $row['quantity'], 0 ) ); ?></td>
					<td style="<?php echo esc_attr( $number ); ?>"><?php echo wp_kses_post( wc_price( $row['unit'], $currency + [ 'decimals' => 4 ] ) ); ?></td>
					<td style="<?php echo esc_attr( $number ); ?>"><?php echo wp_kses_post( wc_price( $row['total'], $currency ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
		<tfoot>
			<tr>
				<th colspan="7" style="<?php echo esc_attr( $cell ); ?>border-bottom:0;font-weight:600;">סה״כ לפני מע״מ</th>
				<td style="<?php echo esc_attr( $number ); ?>border-bottom:0;font-weight:700;"><?php echo wp_kses_post( wc_price( $sum, $currency ) ); ?></td>
			</tr>
		</tfoot>
	</table>
	<p style="color:#6d6a65;font-size:12px;margin:0 0 16px;">מחירי החומר למטר אורך (קרש) או ליחידה (פלטה), והחיתוכים לפי מספרם. לפני מע״מ, משלוח והנחות.</p>
	<?php
	return (string) ob_get_clean();
}

/**
 * The box on the admin order screen, beside the order's own lines.
 *
 * @return void
 */
function gueta_breakdown_meta_box() {
	$screens = [ 'shop_order' ];

	if ( function_exists( 'wc_get_page_screen_id' ) ) {
		$screens[] = wc_get_page_screen_id( 'shop-order' );
	}

	foreach ( array_unique( $screens ) as $screen ) {
		add_meta_box( 'gueta-order-breakdown', 'פירוט מחיר: חומר וחיתוכים', 'gueta_breakdown_meta_box_html', $screen, 'normal', 'default' );
	}
}
add_action( 'add_meta_boxes', 'gueta_breakdown_meta_box', 40 );

/**
 * Print the box: the table and a button that copies it for Excel.
 *
 * @param WP_Post|WC_Order $object The order, or its post on the older order screen.
 * @return void
 */
function gueta_breakdown_meta_box_html( $object ) {
	$order = $object instanceof WC_Order ? $object : wc_get_order( $object instanceof WP_Post ? $object->ID : 0 );

	if ( ! $order ) {
		return;
	}

	if ( ! gueta_breakdown_has_cuts( $order ) ) {
		echo '<p style="color:#6d6a65;margin:0;">אין בהזמנה הזו חיתוכים.</p>';
		return;
	}

	$rows = gueta_breakdown_rows( $order );

	echo gueta_breakdown_html( $order, $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as built.
	?>
	<textarea class="gueta-breakdown-tsv" readonly hidden><?php echo esc_textarea( gueta_breakdown_tsv( $rows ) ); ?></textarea>
	<button type="button" class="button gueta-breakdown-copy">העתקה לאקסל</button>
	<span class="gueta-breakdown-copied" style="color:#1a7f37;margin-inline-start:8px;" hidden>הועתק. אפשר להדביק באקסל.</span>
	<script>
	( function () {
		var box = document.getElementById( 'gueta-order-breakdown' );
		var button = box && box.querySelector( '.gueta-breakdown-copy' );

		if ( ! button ) {
			return;
		}

		button.addEventListener( 'click', function () {
			var source = box.querySelector( '.gueta-breakdown-tsv' );
			var done = box.querySelector( '.gueta-breakdown-copied' );
			var shown = function () {
				done.hidden = false;
				setTimeout( function () { done.hidden = true; }, 3000 );
			};

			if ( navigator.clipboard && window.isSecureContext ) {
				navigator.clipboard.writeText( source.value ).then( shown );
				return;
			}

			source.hidden = false;
			source.select();
			document.execCommand( 'copy' );
			source.hidden = true;
			shown();
		} );
	}() );
	</script>
	<?php
}

/**
 * The table under the order in the emails sent to the shop.
 *
 * A new order, a cancelled or a failed one: every email WooCommerce sends to
 * the shop rather than to the customer.
 *
 * @param WC_Order $order         Order.
 * @param bool     $sent_to_admin Whether the email goes to the shop.
 * @param bool     $plain_text    Whether the email is plain text.
 * @return void
 */
function gueta_breakdown_email( $order, $sent_to_admin, $plain_text = false ) {
	if ( ! $sent_to_admin || ! $order instanceof WC_Order || ! gueta_breakdown_has_cuts( $order ) ) {
		return;
	}

	$rows = gueta_breakdown_rows( $order );

	if ( $plain_text ) {
		// Plain text is not HTML, so it is not escaped: a name's "מ"מ" would read &quot;.
		echo "\nפירוט מחיר: חומר וחיתוכים\n\n" . wp_strip_all_tags( gueta_breakdown_tsv( $rows ) ) . "\n\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return;
	}

	echo '<h2 style="margin-top:24px;">פירוט מחיר: חומר וחיתוכים</h2>';
	echo gueta_breakdown_html( $order, $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped as built.
}
add_action( 'woocommerce_email_after_order_table', 'gueta_breakdown_email', 15, 3 );
