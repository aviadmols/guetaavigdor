<?php
/**
 * Delivery by truck, priced by distance from the warehouse.
 *
 * A flat price covers the first thirty kilometres. Every kilometre from there
 * to sixty adds fourteen shekels, and every one beyond sixty adds ten. It takes
 * the place of the old shop's "far delivery" checkbox, which left the shopper
 * to decide what counted as far.
 *
 * The distance is the straight line from the warehouse's settlement to the one
 * the shopper picked, from the government's coordinates in gueta-cities.php,
 * multiplied by the average ratio of road to straight line. That keeps the
 * price close to the kilometres the truck actually drives without a paid
 * routing service. Both the ratio and the prices are settings.
 *
 * The shopper sees the two apart. The truck keeps its own price, 350, as the
 * shipping method, and whatever the distance adds past that is a separate line
 * in the order summary, "תוספת מרחק להובלה", with the kilometres in its name.
 * The method's name says what the addition comes to, so it is known before the
 * truck is picked. On the order, the shipping line and the fee stay apart too.
 *
 * The price is worked out as soon as the city is chosen: the city field asks
 * the checkout to recalculate, and the rate and the fee are recalculated with it.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option holding the pricing.
 */
const GUETA_DELIVERY_OPTION = 'gueta_delivery_pricing';

/**
 * The pricing with its defaults.
 *
 * road_factor was measured on 2026-09-16 with OSRM, from Rishon LeZion to 91
 * settlements spread from ten to 270 kilometres away. Over the 78 more than
 * thirty kilometres off, where the price starts to move, the road averages
 * 1.28 times the straight line, with a median of 1.24: Jerusalem is 1.15 and
 * Eilat 1.21, while villages reached by winding roads run past 1.5.
 *
 * @return array
 */
function gueta_delivery_settings() {
	$stored = get_option( GUETA_DELIVERY_OPTION, [] );

	return wp_parse_args(
		is_array( $stored ) ? $stored : [],
		[
			'enabled'     => 1,
			'origin'      => 'ראשון לציון',
			'base_price'  => 350,
			'base_km'     => 30,
			'mid_km'      => 60,
			'mid_rate'    => 14,
			'far_rate'    => 10,
			'road_factor' => 1.28,
		]
	);
}

/**
 * Kilometres the truck drives to a settlement: the straight line times the
 * road ratio.
 *
 * @param string $city Settlement, as typed or canonical.
 * @return float Kilometres, or -1 when the settlement or the warehouse's is unknown.
 */
function gueta_delivery_km( $city ) {
	if ( ! function_exists( 'gueta_city_distance' ) || '' === trim( (string) $city ) ) {
		return -1.0;
	}

	$settings = gueta_delivery_settings();
	$straight = gueta_city_distance( $settings['origin'], $city );

	if ( $straight < 0 ) {
		return -1.0;
	}

	return $straight * max( 1, (float) $settings['road_factor'] );
}

/**
 * The delivery price for a distance.
 *
 * A kilometre that has been started counts, so 30.2km is thirty one.
 *
 * @param float $km Kilometres.
 * @return float Shekels.
 */
function gueta_delivery_price( $km ) {
	$settings = gueta_delivery_settings();
	$km       = (int) ceil( max( 0, (float) $km ) );
	$base_km  = (int) $settings['base_km'];
	$mid_km   = max( $base_km, (int) $settings['mid_km'] );

	$price  = (float) $settings['base_price'];
	$price += max( 0, min( $km, $mid_km ) - $base_km ) * (float) $settings['mid_rate'];
	$price += max( 0, $km - $mid_km ) * (float) $settings['far_rate'];

	return round( $price );
}

/**
 * Whether a shipping rate is the truck.
 *
 * The flat rate titled "הובלה" on this shop. Filter gueta_delivery_is_truck to
 * point at another method.
 *
 * @param WC_Shipping_Rate $rate Rate.
 * @return bool
 */
function gueta_delivery_is_truck( $rate ) {
	$is_truck = 'flat_rate' === $rate->get_method_id() && false !== strpos( (string) $rate->get_label(), 'הובלה' );

	return (bool) apply_filters( 'gueta_delivery_is_truck', $is_truck, $rate );
}

/**
 * What the distance adds to the truck's base price for a package.
 *
 * @param array $package Package.
 * @return array|null [ km, amount ], or null with nothing to add: pricing off,
 *                    no settlement chosen yet, one not on the list, or one
 *                    within the base kilometres.
 */
function gueta_delivery_extra( $package ) {
	$settings = gueta_delivery_settings();
	$base     = (float) $settings['base_price'];

	if ( empty( $settings['enabled'] ) || $base <= 0 ) {
		return null;
	}

	$city = isset( $package['destination']['city'] ) ? (string) $package['destination']['city'] : '';
	$km   = gueta_delivery_km( $city );

	if ( $km < 0 ) {
		return null;
	}

	$extra = gueta_delivery_price( $km ) - $base;

	return $extra > 0 ? [ $km, $extra ] : null;
}

/**
 * Name the distance addition on the truck, so it is known before the truck is
 * picked. The method's own price stays as it is.
 *
 * @param WC_Shipping_Rate[] $rates   Rates for the package.
 * @param array              $package Package.
 * @return WC_Shipping_Rate[]
 */
function gueta_delivery_rates( $rates, $package ) {
	$extra = gueta_delivery_extra( $package );

	foreach ( $rates as $rate ) {
		if ( ! $rate instanceof WC_Shipping_Rate || ! gueta_delivery_is_truck( $rate ) ) {
			continue;
		}

		$city = isset( $package['destination']['city'] ) ? (string) $package['destination']['city'] : '';
		$km   = gueta_delivery_km( $city );

		if ( $km >= 0 ) {
			// Kept on the order's shipping line, where the shop can see it.
			$rate->add_meta_data( 'מרחק מהמחסן', sprintf( '%s ק"מ', number_format_i18n( ceil( $km ) ) ) );
		}

		if ( $extra ) {
			$rate->set_label( sprintf( '%s + תוספת מרחק ₪%s', $rate->get_label(), number_format_i18n( $extra[1] ) ) );
		}
	}

	return $rates;
}
add_filter( 'woocommerce_package_rates', 'gueta_delivery_rates', 20, 2 );

/**
 * Charge the distance addition as its own line, when the truck is the method
 * chosen for a package.
 *
 * It carries no tax of its own, like the truck's price, which is set with VAT.
 *
 * @param WC_Cart $cart Cart.
 * @return void
 */
function gueta_delivery_fee( $cart ) {
	if ( ! $cart instanceof WC_Cart || ! function_exists( 'WC' ) || ! WC()->shipping() ) {
		return;
	}

	$packages = WC()->shipping()->get_packages();

	foreach ( $cart->get_shipping_methods() as $key => $rate ) {
		if ( ! $rate instanceof WC_Shipping_Rate || ! gueta_delivery_is_truck( $rate ) || empty( $packages[ $key ] ) ) {
			continue;
		}

		$extra = gueta_delivery_extra( $packages[ $key ] );

		if ( $extra ) {
			$cart->add_fee( sprintf( 'תוספת מרחק להובלה (%s ק"מ)', number_format_i18n( ceil( $extra[0] ) ) ), $extra[1], false );
		}
	}
}
add_action( 'woocommerce_cart_calculate_fees', 'gueta_delivery_fee' );

/* -------------------------------------------------------------------------
 * The admin section
 * ---------------------------------------------------------------------- */

/**
 * Save the pricing from the settings screen.
 *
 * @param array $source Posted data.
 * @return void
 */
function gueta_delivery_save( $source ) {
	$number = static function ( $key, $min ) use ( $source ) {
		return isset( $source[ $key ] ) ? max( $min, (float) wp_unslash( $source[ $key ] ) ) : $min;
	};

	$origin = isset( $source['gueta_delivery_origin'] ) ? sanitize_text_field( wp_unslash( $source['gueta_delivery_origin'] ) ) : '';

	if ( function_exists( 'gueta_city_canonical' ) && gueta_city_canonical( $origin ) ) {
		$origin = gueta_city_canonical( $origin );
	}

	update_option(
		GUETA_DELIVERY_OPTION,
		[
			'enabled'     => empty( $source['gueta_delivery_enabled'] ) ? 0 : 1,
			'origin'      => $origin ? $origin : 'ראשון לציון',
			'base_price'  => $number( 'gueta_delivery_base_price', 0 ),
			'base_km'     => $number( 'gueta_delivery_base_km', 0 ),
			'mid_km'      => $number( 'gueta_delivery_mid_km', 0 ),
			'mid_rate'    => $number( 'gueta_delivery_mid_rate', 0 ),
			'far_rate'    => $number( 'gueta_delivery_far_rate', 0 ),
			'road_factor' => $number( 'gueta_delivery_road_factor', 1 ),
		],
		false
	);
}

/**
 * The delivery pricing panel on the theme settings screen, with the price to
 * a few places worked out so a change can be checked before a shopper sees it.
 *
 * @return void
 */
function gueta_delivery_admin_section() {
	$saved = false;

	if ( isset( $_POST['gueta_delivery_save'] ) && check_admin_referer( 'gueta_delivery_save' ) ) {
		gueta_delivery_save( $_POST );
		$saved = true;
	}

	$settings = gueta_delivery_settings();
	$fields   = [
		'base_price'  => [ 'מחיר בסיס', '₪', 1 ],
		'base_km'     => [ 'כלול במחיר הבסיס עד', 'ק"מ', 1 ],
		'mid_rate'    => [ 'מחיר לק"מ מעבר לזה', '₪', 0.5 ],
		'mid_km'      => [ 'עד', 'ק"מ', 1 ],
		'far_rate'    => [ 'מחיר לק"מ מעבר לזה', '₪', 0.5 ],
		'road_factor' => [ 'יחס כביש לקו אוויר', '×', 0.01 ],
	];
	$samples  = [ 'תל אביב - יפו', 'רחובות', 'ירושלים', 'נתניה', 'חדרה', 'חיפה', 'באר שבע', 'עפולה', 'קרית שמונה', 'אילת' ];
	?>
	<hr>

	<h2>הובלה לפי מרחק</h2>
	<p>
		מחיר ההובלה נקבע לפי המרחק מהמחסן ליישוב שהלקוח בוחר בקופה, ומתעדכן ברגע שהיישוב נבחר.
		המרחק הוא קו האוויר בין היישובים כפול היחס הממוצע בין מרחק בכביש לקו אוויר.
		זה חל על שיטת המשלוח שבשמה "הובלה". ההובלה נגבית במחיר השיטה, ומה שהמרחק מוסיף מעבר למחיר הבסיס מופיע בסיכום ההזמנה כשורה נפרדת, "תוספת מרחק להובלה".
		מחיר הבסיס כאן צריך להיות זהה למחיר של שיטת ההובלה.
	</p>

	<?php if ( $saved ) : ?>
		<div class="notice notice-success inline"><p>תמחור ההובלה נשמר.</p></div>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field( 'gueta_delivery_save' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">מצב</th>
				<td>
					<label>
						<input type="checkbox" name="gueta_delivery_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
						לתמחר את ההובלה לפי מרחק
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gueta_delivery_origin">יישוב המחסן</label></th>
				<td><input type="text" class="regular-text" id="gueta_delivery_origin" name="gueta_delivery_origin" value="<?php echo esc_attr( $settings['origin'] ); ?>"></td>
			</tr>
			<?php foreach ( $fields as $key => $field ) : ?>
				<tr>
					<th scope="row"><label for="gueta_delivery_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th>
					<td>
						<input type="number" class="small-text" min="0" step="<?php echo esc_attr( (string) $field[2] ); ?>" id="gueta_delivery_<?php echo esc_attr( $key ); ?>" name="gueta_delivery_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>">
						<?php echo esc_html( $field[1] ); ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>

		<?php submit_button( 'שמירת תמחור ההובלה', 'primary', 'gueta_delivery_save', false ); ?>
	</form>

	<h3>דוגמאות</h3>
	<?php if ( ! function_exists( 'gueta_city_distance' ) || ! gueta_cities_rows() ) : ?>
		<p>רשימת היישובים עדיין לא הורדה, ולכן אין ממה לחשב מרחק.</p>
	<?php elseif ( gueta_city_distance( $settings['origin'], $settings['origin'] ) < 0 ) : ?>
		<p>יישוב המחסן "<?php echo esc_html( $settings['origin'] ); ?>" לא נמצא ברשימת היישובים.</p>
	<?php else : ?>
		<table class="widefat striped" style="max-width:760px;">
			<thead>
				<tr>
					<th>יישוב</th>
					<th>קו אוויר</th>
					<th>מרחק מחושב</th>
					<th>הובלה</th>
					<th>תוספת מרחק</th>
					<th>סה"כ</th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $samples as $city ) :
					$straight = gueta_city_distance( $settings['origin'], $city );

					if ( $straight < 0 ) {
						continue;
					}

					$km    = gueta_delivery_km( $city );
					$total = gueta_delivery_price( $km );
					?>
					<tr>
						<td><?php echo esc_html( $city ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $straight, 1 ) ); ?> ק"מ</td>
						<td><?php echo esc_html( number_format_i18n( ceil( $km ) ) ); ?> ק"מ</td>
						<td>₪<?php echo esc_html( number_format_i18n( (float) $settings['base_price'] ) ); ?></td>
						<td>₪<?php echo esc_html( number_format_i18n( max( 0, $total - (float) $settings['base_price'] ) ) ); ?></td>
						<td>₪<?php echo esc_html( number_format_i18n( $total ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}
