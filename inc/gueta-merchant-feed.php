<?php
/**
 * The Google Merchant feed.
 *
 * Merchant Center reads the shop's products from one XML file in the uploads
 * folder. It was written by a plugin on the old shop, which stopped with the
 * move, and Google went on comparing the prices it held from May with the
 * pages, and turned every product down. The theme writes it now, once a day,
 * at the same address and with the same ids, so Google takes the new file as
 * an update to the products it knows:
 *
 * - A simple product is one item under its own id. A variable product gives
 *   one item for each variation, under the variation's id, grouped under the
 *   parent's, linked as ?variant_id= so the page opens with it chosen.
 * - The price is what gueta_offer_prices() says, the figure the page leads
 *   with and the structured data carries. For timber sold by the metre that
 *   is the shortest length, regular price and sale price, with the metres it
 *   buys as the unit pricing measure.
 * - A variation's title is the product's name and the values of its
 *   attributes, decoded: the old feed sent "pa_%d7%90%d7%95%d7%a8%d7%9a".
 * - Shipping follows the cart's rules in gueta-shipping.php: the truck for the
 *   "delivery" class, over 14.95kg, or 101cm along a side, at its base price;
 *   a special delivery over 40cm; home delivery otherwise, free once the
 *   price reaches the free shipping minimum. The prices come from the
 *   shipping methods themselves, so a change there reaches the feed.
 *
 * The file is written beside itself and moved into place, so Google never
 * fetches half of one. Merchant Center should fetch it on a schedule a while
 * after it is written, which is 03:30 every night.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The file's name in the uploads folder. The old plugin's, which Merchant
 * Center already points at.
 */
const GUETA_MERCHANT_FEED_FILE = 'gueta-google-merchant-feed.xml';

/**
 * Option holding what the last build did.
 */
const GUETA_MERCHANT_FEED_OPTION = 'gueta_merchant_feed';

/**
 * Cron hook for the nightly build.
 */
const GUETA_MERCHANT_FEED_DAILY = 'gueta_merchant_feed_daily';

/**
 * Cron hook for a build soon, the first one after the theme brings this in.
 */
const GUETA_MERCHANT_FEED_SOON = 'gueta_merchant_feed_soon';

/**
 * The feed's path and address.
 *
 * @return array|null path and url, or null when the uploads folder is not there.
 */
function gueta_merchant_feed_location() {
	$upload = wp_upload_dir( null, false );

	if ( ! empty( $upload['error'] ) || empty( $upload['basedir'] ) ) {
		return null;
	}

	return [
		'path' => trailingslashit( $upload['basedir'] ) . GUETA_MERCHANT_FEED_FILE,
		'url'  => trailingslashit( $upload['baseurl'] ) . GUETA_MERCHANT_FEED_FILE,
	];
}

/**
 * What the last build did.
 *
 * @return array
 */
function gueta_merchant_feed_meta() {
	$meta = get_option( GUETA_MERCHANT_FEED_OPTION, [] );

	return is_array( $meta ) ? $meta : [];
}

/* -------------------------------------------------------------------------
 * Shipping
 * ---------------------------------------------------------------------- */

/**
 * The prices of the ways a product can be sent, from the shipping methods.
 *
 * @return array home, truck, special, and free_min (null when nothing ships
 *               free on its own price).
 */
function gueta_merchant_feed_shipping_prices() {
	$prices = [
		'home'     => 45.0,
		'truck'    => function_exists( 'gueta_delivery_settings' ) ? (float) gueta_delivery_settings()['base_price'] : 350.0,
		'special'  => defined( 'GUETA_SPECIAL_PRICE' ) ? (float) GUETA_SPECIAL_PRICE : 120.0,
		'free_min' => null,
	];

	if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
		return $prices;
	}

	foreach ( WC_Shipping_Zones::get_zones() as $zone ) {
		foreach ( (array) ( $zone['shipping_methods'] ?? [] ) as $method ) {
			if ( ! $method instanceof WC_Shipping_Method || 'yes' !== $method->enabled ) {
				continue;
			}

			$title = (string) $method->get_title();

			if ( 'flat_rate' === $method->id ) {
				// The cost may be a sum such as "45 * [qty]"; its first figure is the price of one.
				$cost = preg_match( '/\d+(?:\.\d+)?/', (string) $method->get_option( 'cost' ), $match ) ? (float) $match[0] : null;

				if ( null === $cost ) {
					continue;
				}

				if ( false !== strpos( $title, 'משלוח עד הבית' ) ) {
					$prices['home'] = $cost;
				} elseif ( false !== strpos( $title, 'הובלה' ) ) {
					$prices['truck'] = $cost;
				}
			}

			// Free shipping on the order's total alone; one that needs a coupon is no promise.
			if ( 'free_shipping' === $method->id && in_array( $method->get_option( 'requires' ), [ 'min_amount', 'either' ], true ) ) {
				$min = (float) $method->get_option( 'min_amount' );

				if ( $min > 0 && ( null === $prices['free_min'] || $min < $prices['free_min'] ) ) {
					$prices['free_min'] = $min;
				}
			}
		}
	}

	return $prices;
}

/**
 * How one of a product is sent, by the cart's rules.
 *
 * @param WC_Product $product Product or variation.
 * @param float      $price   What it is offered at.
 * @param array      $prices  From gueta_merchant_feed_shipping_prices().
 * @return array label, service and price.
 */
function gueta_merchant_feed_shipping( $product, $price, $prices ) {
	$weight = (float) $product->get_weight();
	$weight = $weight > 0 ? $weight : 1.0;
	$side   = max( (float) $product->get_length(), (float) $product->get_width(), (float) $product->get_height() );
	$parcel = defined( 'GUETA_PARCEL_KG' ) ? GUETA_PARCEL_KG : 14.95;

	if ( 'delivery' === $product->get_shipping_class() || $weight > $parcel || $side >= ( defined( 'GUETA_TRUCK_CM' ) ? GUETA_TRUCK_CM : 101 ) ) {
		return [
			'label'   => 'הובלה',
			'service' => 'הובלה',
			'price'   => $prices['truck'],
		];
	}

	$free = null !== $prices['free_min'] && $price >= $prices['free_min'];

	if ( $side > ( defined( 'GUETA_SPECIAL_CM' ) ? GUETA_SPECIAL_CM : 40 ) ) {
		return [
			'label'   => 'משלוח מיוחד',
			'service' => 'משלוח מיוחד',
			'price'   => $free ? 0.0 : $prices['special'],
		];
	}

	return [
		'label'   => 'משלוח עד הבית',
		'service' => 'משלוח עד הבית',
		'price'   => $free ? 0.0 : $prices['home'],
	];
}

/* -------------------------------------------------------------------------
 * Items
 * ---------------------------------------------------------------------- */

/**
 * Text as Google wants it: no tags, no shortcodes, one line.
 *
 * @param string $text  Text.
 * @param int    $limit Characters at most.
 * @return string
 */
function gueta_merchant_feed_text( $text, $limit ) {
	$text = wp_strip_all_tags( strip_shortcodes( (string) $text ) );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = trim( preg_replace( '/\s+/u', ' ', $text ) );

	return mb_strlen( $text ) > $limit ? rtrim( mb_substr( $text, 0, $limit - 1 ) ) . '…' : $text;
}

/**
 * A price as the feed writes it: "54.60 ILS".
 *
 * @param float $amount Amount.
 * @return string
 */
function gueta_merchant_feed_money( $amount ) {
	return number_format( (float) $amount, 2, '.', '' ) . ' ' . get_woocommerce_currency();
}

/**
 * A variation's title: the product's name, then what sets the variation apart.
 *
 * @param WC_Product_Variation $variation Variation.
 * @param WC_Product           $parent    Parent.
 * @return string
 */
function gueta_merchant_feed_variation_title( $variation, $parent ) {
	$values = [];

	foreach ( $variation->get_attributes() as $name => $value ) {
		// Any value: the shopper still picks it, so it says nothing about this item.
		if ( '' === (string) $value ) {
			continue;
		}

		// A term's slug is stored percent-encoded, so it is looked up as stored.
		$term = taxonomy_exists( $name ) ? get_term_by( 'slug', (string) $value, $name ) : false;

		$values[] = $term && ! is_wp_error( $term ) ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : rawurldecode( (string) $value );
	}

	$title = $parent->get_name();

	return $values ? $title . ' - ' . implode( ', ', $values ) : $title;
}

/**
 * The product's category, as a path: "עצים > עץ אורן".
 *
 * @param WC_Product $product Product, the parent for a variation.
 * @return string
 */
function gueta_merchant_feed_product_type( $product ) {
	$ids = $product->get_category_ids();

	if ( ! $ids ) {
		return '';
	}

	$path = [];

	foreach ( array_reverse( get_ancestors( (int) $ids[0], 'product_cat', 'taxonomy' ) ) as $ancestor ) {
		$term = get_term( $ancestor, 'product_cat' );

		if ( $term && ! is_wp_error( $term ) ) {
			$path[] = $term->name;
		}
	}

	$term = get_term( (int) $ids[0], 'product_cat' );

	if ( $term && ! is_wp_error( $term ) ) {
		$path[] = $term->name;
	}

	return html_entity_decode( implode( ' > ', $path ), ENT_QUOTES, 'UTF-8' );
}

/**
 * One item's fields, or null when it has nothing to sell.
 *
 * @param WC_Product $item     Simple product or variation.
 * @param WC_Product $parent   Itself, or the variation's parent.
 * @param array      $shipping From gueta_merchant_feed_shipping_prices().
 * @return array|null Fields by tag, in order. A value may be a list, for a tag
 *                    that repeats, or an array of sub-tags.
 */
function gueta_merchant_feed_item( $item, $parent, $shipping ) {
	if ( '' === (string) $item->get_price() || (float) $item->get_price() <= 0 ) {
		return null;
	}

	$prices = gueta_offer_prices( $item );

	if ( $prices['price'] <= 0 ) {
		return null;
	}

	$variation = $item->get_id() !== $parent->get_id();
	$link      = get_permalink( $parent->get_id() );
	$link      = $variation ? add_query_arg( 'variant_id', $item->get_id(), $link ) : $link;

	$image_id = $item->get_image_id() ? $item->get_image_id() : $parent->get_image_id();
	$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';
	$gallery  = [];

	foreach ( array_slice( $parent->get_gallery_image_ids(), 0, 10 ) as $gallery_id ) {
		$url = wp_get_attachment_image_url( $gallery_id, 'full' );

		if ( $url && $url !== $image ) {
			$gallery[] = $url;
		}
	}

	$description = $variation && $item->get_description() ? $item->get_description() : $parent->get_description();
	$description = '' !== trim( wp_strip_all_tags( (string) $description ) ) ? $description : $parent->get_short_description();
	$title       = $variation ? gueta_merchant_feed_variation_title( $item, $parent ) : $parent->get_name();

	$status       = $item->get_stock_status();
	$availability = 'outofstock' === $status ? 'out_of_stock' : ( 'onbackorder' === $status ? 'backorder' : 'in_stock' );

	$fields = [
		'g:id'                        => (string) $item->get_id(),
		'g:item_group_id'             => $variation ? (string) $parent->get_id() : '',
		'title'                       => gueta_merchant_feed_text( $title, 150 ),
		'link'                        => $link,
		'description'                 => gueta_merchant_feed_text( $description ? $description : $title, 5000 ),
		'g:image_link'                => $image,
		'g:additional_image_link'     => $gallery,
		'g:availability'              => $availability,
		'g:price'                     => gueta_merchant_feed_money( $prices['regular'] ),
		'g:sale_price'                => $prices['regular'] > $prices['price'] ? gueta_merchant_feed_money( $prices['price'] ) : '',
		'g:unit_pricing_measure'      => $prices['metres'] ? rtrim( rtrim( number_format( $prices['metres'], 2, '.', '' ), '0' ), '.' ) . ' m' : '',
		'g:unit_pricing_base_measure' => $prices['metres'] ? '1 m' : '',
		'g:condition'                 => 'new',
		'g:product_type'              => gueta_merchant_feed_product_type( $parent ),
	];

	$gtin = method_exists( $item, 'get_global_unique_id' ) ? (string) $item->get_global_unique_id() : '';

	if ( '' !== $gtin ) {
		$fields['g:gtin'] = $gtin;
	} else {
		$fields['g:mpn']               = (string) $item->get_sku();
		$fields['g:identifier_exists'] = 'no';
	}

	$weight = (float) $item->get_weight();

	if ( $weight > 0 ) {
		$fields['g:shipping_weight'] = wc_format_decimal( $weight ) . ' ' . get_option( 'woocommerce_weight_unit', 'kg' );
	}

	$send = gueta_merchant_feed_shipping( $item, $prices['price'], $shipping );

	$fields['g:shipping_label'] = $send['label'];
	$fields['g:shipping']       = [
		'g:country' => 'IL',
		'g:service' => $send['service'],
		'g:price'   => gueta_merchant_feed_money( $send['price'] ),
	];

	/**
	 * One item of the Merchant feed, before it is written.
	 *
	 * @param array      $fields Fields by tag.
	 * @param WC_Product $item   Simple product or variation.
	 * @param WC_Product $parent Itself, or the variation's parent.
	 */
	return apply_filters( 'gueta_merchant_feed_item', $fields, $item, $parent );
}

/**
 * Write one item.
 *
 * @param XMLWriter $xml    Writer.
 * @param array     $fields Fields by tag.
 * @return void
 */
function gueta_merchant_feed_write_item( $xml, $fields ) {
	$xml->startElement( 'item' );

	foreach ( $fields as $tag => $value ) {
		if ( is_array( $value ) && $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			$xml->startElement( $tag );

			foreach ( $value as $sub => $text ) {
				$xml->writeElement( $sub, (string) $text );
			}

			$xml->endElement();
			continue;
		}

		foreach ( (array) $value as $text ) {
			if ( '' !== (string) $text ) {
				$xml->writeElement( $tag, (string) $text );
			}
		}
	}

	$xml->endElement();
}

/* -------------------------------------------------------------------------
 * The build
 * ---------------------------------------------------------------------- */

/**
 * Build the feed and put it in place.
 *
 * @return array ok and message.
 */
function gueta_build_merchant_feed() {
	$location = gueta_merchant_feed_location();

	if ( ! $location || ! function_exists( 'wc_get_products' ) ) {
		return [
			'ok'      => false,
			'message' => 'WooCommerce או תיקיית ההעלאות אינם זמינים, ולכן הפיד לא נבנה.',
		];
	}

	if ( ! class_exists( 'XMLWriter' ) ) {
		return [
			'ok'      => false,
			'message' => 'בשרת חסרה הרחבת XMLWriter של PHP, ולכן הפיד לא נבנה.',
		];
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	wp_raise_memory_limit( 'admin' );

	$started  = microtime( true );
	$temp     = $location['path'] . '.tmp';
	$shipping = gueta_merchant_feed_shipping_prices();
	$xml      = new XMLWriter();

	if ( ! $xml->openUri( $temp ) ) {
		return [
			'ok'      => false,
			'message' => 'לא ניתן לכתוב בתיקיית ההעלאות. בדקו את הרשאות הכתיבה.',
		];
	}

	$xml->setIndent( true );
	$xml->startDocument( '1.0', 'UTF-8' );
	$xml->startElement( 'rss' );
	$xml->writeAttribute( 'version', '2.0' );
	$xml->writeAttribute( 'xmlns:g', 'http://base.google.com/ns/1.0' );
	$xml->startElement( 'channel' );
	$xml->writeElement( 'title', html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );
	$xml->writeElement( 'link', home_url( '/' ) );
	$xml->writeElement( 'description', 'Google Merchant product feed' );

	$items   = 0;
	$skipped = 0;
	$page    = 1;

	do {
		$products = wc_get_products(
			[
				'status'     => 'publish',
				'type'    => [ 'simple', 'variable' ],
				'limit'   => 50,
				'page'    => $page,
				'orderby' => 'ID',
				'order'   => 'ASC',
			]
		);

		foreach ( $products as $product ) {
			if ( 'hidden' === $product->get_catalog_visibility() ) {
				continue;
			}

			$children = $product->is_type( 'variable' ) ? $product->get_children() : [ $product ];

			foreach ( $children as $child ) {
				$child = $child instanceof WC_Product ? $child : wc_get_product( $child );

				if ( ! $child instanceof WC_Product || ( $child !== $product && 'publish' !== $child->get_status() ) ) {
					continue;
				}

				$fields = gueta_merchant_feed_item( $child, $product, $shipping );

				if ( ! $fields ) {
					++$skipped;
					continue;
				}

				gueta_merchant_feed_write_item( $xml, $fields );
				++$items;
			}
		}

		$xml->flush();

		// Products already written need not stay in memory.
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}

		++$page;
	} while ( count( $products ) === 50 );

	$xml->endElement();
	$xml->endElement();
	$xml->endDocument();
	$xml->flush();
	unset( $xml );

	// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	if ( ! $items || ! rename( $temp, $location['path'] ) ) {
		wp_delete_file( $temp );

		$result = [
			'ok'      => false,
			'message' => $items ? 'הפיד נבנה אבל לא ניתן היה להעביר אותו למקומו.' : 'לא נמצאו מוצרים לפיד, ולכן הקובץ הקודם נשאר.',
		];
	} else {
		$result = [
			'ok'      => true,
			'message' => sprintf( 'הפיד נבנה: %1$s פריטים, %2$s.', number_format_i18n( $items ), size_format( (int) filesize( $location['path'] ) ) ),
		];
	}

	update_option(
		GUETA_MERCHANT_FEED_OPTION,
		[
			'built'   => time(),
			'ok'      => $result['ok'],
			'message' => $result['message'],
			'items'   => $items,
			'skipped' => $skipped,
			'seconds' => round( microtime( true ) - $started, 1 ),
		],
		false
	);

	return $result;
}

add_action( GUETA_MERCHANT_FEED_DAILY, 'gueta_build_merchant_feed' );
add_action( GUETA_MERCHANT_FEED_SOON, 'gueta_build_merchant_feed' );

/**
 * Build every night at 03:30, and once soon after this first arrives.
 *
 * @return void
 */
function gueta_merchant_feed_schedule() {
	if ( ! wp_next_scheduled( GUETA_MERCHANT_FEED_DAILY ) ) {
		$next = new DateTime( 'today 03:30', wp_timezone() );

		if ( $next->getTimestamp() <= time() ) {
			$next->modify( '+1 day' );
		}

		wp_schedule_event( $next->getTimestamp(), 'daily', GUETA_MERCHANT_FEED_DAILY );
	}

	if ( ! gueta_merchant_feed_meta() && ! wp_next_scheduled( GUETA_MERCHANT_FEED_SOON ) ) {
		wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, GUETA_MERCHANT_FEED_SOON );
	}
}
add_action( 'init', 'gueta_merchant_feed_schedule' );

/**
 * Drop the schedule when the theme is switched away.
 *
 * @return void
 */
function gueta_merchant_feed_unschedule() {
	wp_clear_scheduled_hook( GUETA_MERCHANT_FEED_DAILY );
	wp_clear_scheduled_hook( GUETA_MERCHANT_FEED_SOON );
}
add_action( 'switch_theme', 'gueta_merchant_feed_unschedule' );

/* -------------------------------------------------------------------------
 * The admin section
 * ---------------------------------------------------------------------- */

/**
 * The feed's panel on the theme settings' tools tab: when it was built, what
 * it holds, and a button to build it now.
 *
 * @return void
 */
function gueta_merchant_feed_admin_section() {
	$notice = null;

	if ( isset( $_POST['gueta_build_merchant_feed'] ) && check_admin_referer( 'gueta_build_merchant_feed' ) ) {
		$notice = gueta_build_merchant_feed();
	}

	$meta     = gueta_merchant_feed_meta();
	$location = gueta_merchant_feed_location();
	$next     = wp_next_scheduled( GUETA_MERCHANT_FEED_DAILY );
	$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
	?>
	<hr>

	<h2>פיד Google Merchant</h2>
	<p>
		קובץ המוצרים ש-Merchant Center קורא. הוא נבנה מחדש כל לילה ב-03:30, עם המחיר שמופיע בדף המוצר ובנתונים המובנים,
		ועלות המשלוח לפי אותם כללים של הקופה. כדאי להגדיר ב-Merchant Center אחזור מתוזמן של הכתובת הזו פעם ביום, אחרי 04:00.
	</p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo $notice['ok'] ? 'success' : 'error'; ?> inline"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
	<?php endif; ?>

	<table class="widefat striped" style="max-width:760px;">
		<tbody>
			<tr>
				<td style="width:200px;"><strong>כתובת הפיד</strong></td>
				<td><?php echo $location ? '<a href="' . esc_url( $location['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $location['url'] ) . '</a>' : '—'; ?></td>
			</tr>
			<tr>
				<td><strong>נבנה לאחרונה</strong></td>
				<td><?php echo ! empty( $meta['built'] ) ? esc_html( wp_date( $format, (int) $meta['built'] ) . ' · ' . $meta['message'] ) : 'טרם נבנה'; ?></td>
			</tr>
			<?php if ( ! empty( $meta['built'] ) ) : ?>
				<tr>
					<td><strong>פריטים</strong></td>
					<td>
						<?php
						echo esc_html(
							sprintf(
								'%1$s בפיד, %2$s בלי מחיר שלא נכנסו, %3$s שניות',
								number_format_i18n( (int) $meta['items'] ),
								number_format_i18n( (int) $meta['skipped'] ),
								number_format_i18n( (float) $meta['seconds'], 1 )
							)
						);
						?>
					</td>
				</tr>
			<?php endif; ?>
			<tr>
				<td><strong>הבנייה הבאה</strong></td>
				<td><?php echo $next ? esc_html( wp_date( $format, $next ) ) : '—'; ?></td>
			</tr>
		</tbody>
	</table>

	<form method="post" style="margin-top:12px;">
		<?php wp_nonce_field( 'gueta_build_merchant_feed' ); ?>
		<?php submit_button( 'לבנות את הפיד עכשיו', 'secondary', 'gueta_build_merchant_feed', false ); ?>
	</form>
	<?php
}
