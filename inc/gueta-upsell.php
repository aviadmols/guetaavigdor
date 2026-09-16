<?php
/**
 * Upsell popups: a small card in the corner that offers a few products and
 * adds one to the cart without leaving the page.
 *
 * Each popup is a row in a private post type, edited on a screen of its own
 * rather than WordPress's post editor. It says which products to offer, when
 * to appear, on which pages, to whom, and what the cart has to hold first.
 * Every rule is decided here, on the server, and the browser only receives the
 * popups that passed, with their products ready to print.
 *
 * Ported from the same module on sellameir, reworked for this shop: there is
 * no cart page here, the cart is the drawer, and a shopper who adds from the
 * popup is left where they are rather than having the drawer thrown over them.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GUETA_UPSELL_CPT = 'gueta_upsell';

/**
 * The most products one popup sends to the browser.
 *
 * The list is printed into every page the popup can show on. A popup fed a
 * whole category would otherwise load every product in it, on every page view.
 */
const GUETA_UPSELL_MAX_ITEMS = 30;

/**
 * Meta keys, one per setting.
 */
const GUETA_UPSELL_META = [
	'enabled'            => '_gueta_upsell_enabled',
	'heading'            => '_gueta_upsell_heading',
	'products'           => '_gueta_upsell_products',
	'product_categories' => '_gueta_upsell_product_categories',
	'product_tags'       => '_gueta_upsell_product_tags',
	'trigger'            => '_gueta_upsell_trigger',
	'delay'              => '_gueta_upsell_delay',
	'frequency'          => '_gueta_upsell_frequency',
	'scope'              => '_gueta_upsell_scope',
	'scope_products'     => '_gueta_upsell_scope_products',
	'scope_pages'        => '_gueta_upsell_scope_pages',
	'audience'           => '_gueta_upsell_audience',
	'cart_rule'          => '_gueta_upsell_cart_rule',
	'cart_products'      => '_gueta_upsell_cart_products',
	'cart_categories'    => '_gueta_upsell_cart_categories',
	'cart_min_total'     => '_gueta_upsell_cart_min_total',
	'cart_max_total'     => '_gueta_upsell_cart_max_total',
	'cart_cross_sells'   => '_gueta_upsell_cart_cross_sells',
];

/**
 * Where a product added from a popup is remembered until the order is placed,
 * and the keys it is written under on the order.
 */
const GUETA_UPSELL_SESSION_SOURCE = 'gueta_upsell_source';
const GUETA_UPSELL_ITEM_META      = '_gueta_upsell_source';
const GUETA_UPSELL_ITEM_META_ID   = '_gueta_upsell_popup_id';
const GUETA_UPSELL_ORDER_META     = '_gueta_upsell_used';

/**
 * One setting of one popup.
 *
 * @param int    $popup_id Popup id.
 * @param string $key      Key in GUETA_UPSELL_META.
 * @return mixed
 */
function gueta_upsell_meta( $popup_id, $key ) {
	return get_post_meta( $popup_id, GUETA_UPSELL_META[ $key ], true );
}

/**
 * A list setting of one popup, as whole numbers.
 *
 * @param int    $popup_id Popup id.
 * @param string $key      Key in GUETA_UPSELL_META.
 * @return int[]
 */
function gueta_upsell_ids( $popup_id, $key ) {
	$value = $popup_id ? gueta_upsell_meta( $popup_id, $key ) : [];

	return is_array( $value ) ? array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) ) : [];
}

/* -------------------------------------------------------------------------
 * Storage
 * ---------------------------------------------------------------------- */

/**
 * Register the private post type the popups are kept in.
 *
 * @return void
 */
function gueta_upsell_register_post_type() {
	register_post_type(
		GUETA_UPSELL_CPT,
		[
			'labels'          => [
				'name'          => 'פופאפים של Upsell',
				'singular_name' => 'פופאפ Upsell',
			],
			'public'          => false,
			'show_ui'         => false,
			'show_in_menu'    => false,
			'show_in_rest'    => false,
			'has_archive'     => false,
			'rewrite'         => false,
			'query_var'       => false,
			'supports'        => [ 'title' ],
			'map_meta_cap'    => false,
			'capability_type' => 'post',
			// Nothing reaches these through WordPress's own screens, but if
			// anything ever asks, the answer is the shop manager's capability.
			'capabilities'    => array_fill_keys(
				[
					'edit_post',
					'read_post',
					'delete_post',
					'edit_posts',
					'edit_others_posts',
					'publish_posts',
					'read_private_posts',
					'delete_posts',
					'delete_others_posts',
					'delete_private_posts',
					'delete_published_posts',
					'edit_private_posts',
					'edit_published_posts',
					'create_posts',
				],
				'manage_woocommerce'
			),
		]
	);
}
add_action( 'init', 'gueta_upsell_register_post_type' );

/**
 * The choices each setting accepts, with the words the admin screen shows.
 *
 * @return array<string,array<string,string>>
 */
function gueta_upsell_options() {
	return [
		'triggers'    => [
			'immediate'   => 'מיד עם הכניסה לעמוד',
			'delay'       => 'אחרי השהיה',
			'scroll'      => 'אחרי גלילה של יותר מחצי עמוד',
			'exit_intent' => 'כשהעכבר יוצא מהחלון (דסקטופ בלבד)',
			'add_to_cart' => 'אחרי הוספה לעגלה',
		],
		'frequencies' => [
			'every_visit' => 'בכל טעינת עמוד',
			'session'     => 'פעם אחת בכל ביקור באתר',
			'day'         => 'פעם אחת ביום',
			'once'        => 'פעם אחת בלבד',
		],
		'cart_rules'  => [
			'any'          => 'בלי תנאי, בכל מצב של העגלה',
			'contains'     => 'רק אם בעגלה יש אחד מהמוצרים או מהקטגוריות שנבחרו',
			'not_contains' => 'רק אם בעגלה אין אף אחד מהמוצרים או מהקטגוריות שנבחרו',
			'not_empty'    => 'רק אם יש משהו בעגלה',
			'empty'        => 'רק אם העגלה ריקה',
		],
		'audiences'   => [
			'all'        => 'כל הגולשים',
			'logged_in'  => 'רק משתמשים מחוברים',
			'logged_out' => 'רק גולשים שאינם מחוברים',
		],
		// No cart page: this shop's cart is the drawer, and the page redirects.
		'scopes'      => [
			'all'      => 'בכל האתר',
			'product'  => 'בכל דפי המוצר',
			'shop'     => 'בחנות ובדפי הקטגוריות',
			'checkout' => 'בדף התשלום',
			'products' => 'רק בדפי מוצר מסוימים',
			'pages'    => 'רק בעמודים מסוימים',
		],
	];
}

/**
 * Whole numbers from a posted list field.
 *
 * @param array  $source Request data.
 * @param string $field  Field name.
 * @return int[]
 */
function gueta_upsell_posted_ids( $source, $field ) {
	if ( empty( $source[ $field ] ) || ! is_array( $source[ $field ] ) ) {
		return [];
	}

	return array_values( array_unique( array_filter( array_map( 'absint', wp_unslash( $source[ $field ] ) ) ) ) );
}

/**
 * A posted choice, held to the values the setting accepts.
 *
 * @param array  $source   Request data.
 * @param string $field    Field name.
 * @param string $group    Group in gueta_upsell_options().
 * @param string $fallback Value when missing or unknown.
 * @return string
 */
function gueta_upsell_posted_choice( $source, $field, $group, $fallback ) {
	$value   = isset( $source[ $field ] ) ? sanitize_key( wp_unslash( $source[ $field ] ) ) : $fallback;
	$options = gueta_upsell_options();

	return isset( $options[ $group ][ $value ] ) ? $value : $fallback;
}

/**
 * Clean and store one popup's settings.
 *
 * @param int   $popup_id Popup id.
 * @param array $source   Request data.
 * @return void
 */
function gueta_upsell_save_meta( $popup_id, $source ) {
	// Manual picks keep their order, which is the order of the slider.
	$products = [];

	foreach ( gueta_upsell_posted_ids( $source, 'gueta_upsell_products' ) as $product_id ) {
		$product = wc_get_product( $product_id );

		if ( $product && $product->is_purchasable() ) {
			$products[] = $product_id;
		}
	}

	$values = [
		'enabled'            => ! empty( $source['gueta_upsell_enabled'] ) ? '1' : '0',
		'heading'            => isset( $source['gueta_upsell_heading'] ) ? sanitize_text_field( wp_unslash( $source['gueta_upsell_heading'] ) ) : '',
		'products'           => $products,
		'product_categories' => gueta_upsell_posted_ids( $source, 'gueta_upsell_product_categories' ),
		'product_tags'       => gueta_upsell_posted_ids( $source, 'gueta_upsell_product_tags' ),
		'trigger'            => gueta_upsell_posted_choice( $source, 'gueta_upsell_trigger', 'triggers', 'delay' ),
		'delay'              => max( 0, min( 120, absint( $source['gueta_upsell_delay'] ?? 5 ) ) ),
		'frequency'          => gueta_upsell_posted_choice( $source, 'gueta_upsell_frequency', 'frequencies', 'session' ),
		'scope'              => gueta_upsell_posted_choice( $source, 'gueta_upsell_scope', 'scopes', 'all' ),
		'scope_products'     => gueta_upsell_posted_ids( $source, 'gueta_upsell_scope_products' ),
		'scope_pages'        => gueta_upsell_posted_ids( $source, 'gueta_upsell_scope_pages' ),
		'audience'           => gueta_upsell_posted_choice( $source, 'gueta_upsell_audience', 'audiences', 'all' ),
		'cart_rule'          => gueta_upsell_posted_choice( $source, 'gueta_upsell_cart_rule', 'cart_rules', 'any' ),
		'cart_products'      => gueta_upsell_posted_ids( $source, 'gueta_upsell_cart_products' ),
		'cart_categories'    => gueta_upsell_posted_ids( $source, 'gueta_upsell_cart_categories' ),
		'cart_min_total'     => max( 0.0, (float) ( $source['gueta_upsell_cart_min_total'] ?? 0 ) ),
		'cart_max_total'     => max( 0.0, (float) ( $source['gueta_upsell_cart_max_total'] ?? 0 ) ),
		'cart_cross_sells'   => ! empty( $source['gueta_upsell_cart_cross_sells'] ) ? '1' : '0',
	];

	foreach ( $values as $key => $value ) {
		update_post_meta( $popup_id, GUETA_UPSELL_META[ $key ], $value );
	}
}

/* -------------------------------------------------------------------------
 * Admin screen
 * ---------------------------------------------------------------------- */

/**
 * The popups screen, where a shop manager can reach it.
 *
 * It sits in a menu of its own rather than under Gueta Theme, which asks for
 * the administrator's capability; running a promotion is shop work.
 *
 * @return void
 */
function gueta_upsell_admin_menu() {
	add_menu_page(
		'פופאפים של Upsell',
		'פופאפים של Upsell',
		'manage_woocommerce',
		'gueta-upsell-popups',
		'gueta_upsell_render_admin_page',
		'dashicons-megaphone',
		58.1
	);
}
add_action( 'admin_menu', 'gueta_upsell_admin_menu' );

/**
 * Save or delete a popup, then send the browser back to the list.
 *
 * Runs on admin_init so the redirect happens before anything is printed.
 *
 * @return void
 */
function gueta_upsell_handle_admin_actions() {
	if ( empty( $_POST['gueta_upsell_action'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	check_admin_referer( 'gueta_upsell_manage' );

	$screen  = admin_url( 'admin.php?page=gueta-upsell-popups' );
	$action  = sanitize_key( wp_unslash( $_POST['gueta_upsell_action'] ) );
	$popup_id = isset( $_POST['upsell_id'] ) ? absint( $_POST['upsell_id'] ) : 0;
	$is_popup = $popup_id && GUETA_UPSELL_CPT === get_post_type( $popup_id );

	if ( 'delete' === $action ) {
		// There is no trash screen for these, so a trashed popup could never
		// be brought back. It is deleted outright, after the browser confirms.
		if ( $is_popup ) {
			wp_delete_post( $popup_id, true );
		}

		wp_safe_redirect( add_query_arg( 'deleted', 1, $screen ) );
		exit;
	}

	if ( 'save' !== $action ) {
		return;
	}

	$title = isset( $_POST['gueta_upsell_title'] ) ? sanitize_text_field( wp_unslash( $_POST['gueta_upsell_title'] ) ) : '';

	if ( '' === $title ) {
		wp_safe_redirect( add_query_arg( 'error', 'title', $screen ) );
		exit;
	}

	$post = [
		'post_type'   => GUETA_UPSELL_CPT,
		'post_title'  => $title,
		'post_status' => 'publish',
	];

	if ( $is_popup ) {
		$post['ID'] = $popup_id;
		$popup_id   = wp_update_post( $post, true );
	} else {
		$popup_id = wp_insert_post( $post, true );
	}

	if ( is_wp_error( $popup_id ) ) {
		wp_safe_redirect( add_query_arg( 'error', 'save', $screen ) );
		exit;
	}

	gueta_upsell_save_meta( $popup_id, $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

	wp_safe_redirect( add_query_arg( [ 'saved' => 1, 'edit' => $popup_id ], $screen ) );
	exit;
}
add_action( 'admin_init', 'gueta_upsell_handle_admin_actions' );

/**
 * WooCommerce's product search and the drag to reorder, on this screen only.
 *
 * @param string $hook Current admin page.
 * @return void
 */
function gueta_upsell_admin_assets( $hook ) {
	if ( 'toplevel_page_gueta-upsell-popups' !== $hook ) {
		return;
	}

	wp_enqueue_style( 'woocommerce_admin_styles' );

	wp_enqueue_style(
		'gueta-upsell-admin',
		get_stylesheet_directory_uri() . '/assets/css/gueta-upsell-admin.css',
		[ 'woocommerce_admin_styles' ],
		gueta_asset_version( '/assets/css/gueta-upsell-admin.css' )
	);

	wp_enqueue_script(
		'gueta-upsell-admin',
		get_stylesheet_directory_uri() . '/assets/js/gueta-upsell-admin.js',
		[ 'jquery', 'wc-enhanced-select', 'jquery-ui-sortable' ],
		gueta_asset_version( '/assets/js/gueta-upsell-admin.js' ),
		true
	);
}
add_action( 'admin_enqueue_scripts', 'gueta_upsell_admin_assets' );

/**
 * A product search box with its chosen products already in it.
 *
 * @param string $name        Field name, without the brackets.
 * @param int[]  $product_ids Chosen products, in order.
 * @param string $placeholder Placeholder.
 * @param string $id          Element id.
 * @return void
 */
function gueta_upsell_product_select( $name, $product_ids, $placeholder, $id = '' ) {
	// The width is inline because select2 reads it from the attribute, and
	// some of these start inside a hidden panel that has no width to measure.
	?>
	<select
		class="wc-product-search"
		style="width:100%"
		multiple="multiple"
		<?php echo $id ? 'id="' . esc_attr( $id ) . '"' : ''; ?>
		name="<?php echo esc_attr( $name ); ?>[]"
		data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
		data-action="woocommerce_json_search_products"
	>
		<?php
		foreach ( $product_ids as $product_id ) :
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}
			?>
			<option value="<?php echo (int) $product_id; ?>" selected="selected"><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * A plain multiple choice list of terms or pages.
 *
 * @param string $name     Field name, without the brackets.
 * @param array  $choices  Value => label.
 * @param int[]  $selected Chosen values.
 * @param string $id       Element id.
 * @return void
 */
function gueta_upsell_list_select( $name, $choices, $selected, $id ) {
	?>
	<select class="gueta-upsell-admin__list" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>[]" multiple="multiple">
		<?php foreach ( $choices as $value => $label ) : ?>
			<option value="<?php echo (int) $value; ?>" <?php selected( in_array( (int) $value, $selected, true ) ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * A single choice from one of the option groups.
 *
 * @param string $name    Field name.
 * @param string $group   Group in gueta_upsell_options().
 * @param string $current Current value.
 * @return void
 */
function gueta_upsell_choice_select( $name, $group, $current ) {
	$options = gueta_upsell_options();
	?>
	<select id="<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
		<?php foreach ( $options[ $group ] as $value => $label ) : ?>
			<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}

/**
 * Term names keyed by id.
 *
 * @param string $taxonomy Taxonomy.
 * @return array<int,string>
 */
function gueta_upsell_term_choices( $taxonomy ) {
	$terms = get_terms(
		[
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'orderby'    => 'name',
		]
	);

	return is_wp_error( $terms ) ? [] : wp_list_pluck( $terms, 'name', 'term_id' );
}

/**
 * The form for one popup, new or existing.
 *
 * @param int $popup_id Popup id, or 0 for a new one.
 * @return void
 */
function gueta_upsell_render_form( $popup_id ) {
	$get = static function ( $key, $fallback ) use ( $popup_id ) {
		$value = $popup_id ? gueta_upsell_meta( $popup_id, $key ) : '';

		return '' === $value ? $fallback : $value;
	};

	$categories = gueta_upsell_term_choices( 'product_cat' );
	$tags       = gueta_upsell_term_choices( 'product_tag' );
	$pages      = wp_list_pluck( get_pages( [ 'sort_column' => 'post_title' ] ), 'post_title', 'ID' );
	$cart_rule  = $get( 'cart_rule', 'any' );
	$scope      = $get( 'scope', 'all' );
	$cart_min   = (float) $get( 'cart_min_total', 0 );
	$cart_max   = (float) $get( 'cart_max_total', 0 );
	?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="gueta_upsell_title">שם פנימי</label></th>
			<td>
				<input class="regular-text" type="text" id="gueta_upsell_title" name="gueta_upsell_title" value="<?php echo esc_attr( $popup_id ? get_the_title( $popup_id ) : '' ); ?>" required placeholder="למשל: אחרי הוספה של דק">
				<p class="description">לא מוצג לגולשים. זה השם שיופיע בהזמנה ליד מוצר שנמכר דרך הפופאפ.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="gueta_upsell_heading">כותרת בפופאפ</label></th>
			<td>
				<input class="regular-text" type="text" id="gueta_upsell_heading" name="gueta_upsell_heading" value="<?php echo esc_attr( $get( 'heading', '' ) ); ?>" placeholder="למשל: אולי תצטרכו גם">
				<p class="description">שורה קטנה מעל שם המוצר. אפשר להשאיר ריק.</p>
			</td>
		</tr>
		<tr>
			<th scope="row">סטטוס</th>
			<td><label><input type="checkbox" name="gueta_upsell_enabled" value="1" <?php checked( $get( 'enabled', '1' ), '1' ); ?>> הפופאפ פעיל</label></td>
		</tr>
		<tr>
			<th scope="row"><label for="gueta_upsell_products">מוצרים</label></th>
			<td>
				<?php gueta_upsell_product_select( 'gueta_upsell_products', gueta_upsell_ids( $popup_id, 'products' ), 'חפשו מוצר לפי שם או מק"ט', 'gueta_upsell_products' ); ?>
				<p class="description">הסדר כאן הוא סדר ההצגה. גררו כדי לשנות אותו.</p>

				<p class="gueta-upsell-admin__sub"><label for="gueta_upsell_product_categories">ועוד כל המוצרים מהקטגוריות</label></p>
				<?php gueta_upsell_list_select( 'gueta_upsell_product_categories', $categories, gueta_upsell_ids( $popup_id, 'product_categories' ), 'gueta_upsell_product_categories' ); ?>

				<p class="gueta-upsell-admin__sub"><label for="gueta_upsell_product_tags">ומהתגיות</label></p>
				<?php gueta_upsell_list_select( 'gueta_upsell_product_tags', $tags, gueta_upsell_ids( $popup_id, 'product_tags' ), 'gueta_upsell_product_tags' ); ?>

				<p class="description">מוצרים מקטגוריות ותגיות מצטרפים אחרי אלה שנבחרו ידנית, בלי כפילויות. Ctrl או Cmd לבחירה של יותר מאחת.</p>

				<?php if ( $popup_id ) : ?>
					<?php $count = count( gueta_upsell_collect_items( $popup_id ) ); ?>
					<p class="gueta-upsell-admin__count">
						<strong>מוצרים שיוצגו כרגע: <?php echo esc_html( number_format_i18n( $count ) ); ?></strong>
						<?php if ( $count > GUETA_UPSELL_MAX_ITEMS ) : ?>
							<br><span class="description">בפופאפ עצמו יוצגו <?php echo (int) GUETA_UPSELL_MAX_ITEMS; ?> הראשונים, כדי לא להכביד על טעינת העמודים.</span>
						<?php endif; ?>
						<br><span class="description">נספרים רק מוצרים פשוטים שבמלאי. מוצר עם וריאציות צריך בחירה של אפשרות, ולכן לא נוסף לעגלה מפופאפ.</span>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="gueta_upsell_cart_rule">תנאי לפי העגלה</label></th>
			<td>
				<?php gueta_upsell_choice_select( 'gueta_upsell_cart_rule', 'cart_rules', $cart_rule ); ?>

				<div class="gueta-upsell-admin__cart-match" <?php echo in_array( $cart_rule, [ 'contains', 'not_contains' ], true ) ? '' : 'hidden'; ?>>
					<p class="gueta-upsell-admin__sub"><label for="gueta_upsell_cart_products">מוצרים בעגלה</label></p>
					<?php gueta_upsell_product_select( 'gueta_upsell_cart_products', gueta_upsell_ids( $popup_id, 'cart_products' ), 'המוצרים שהימצאותם בעגלה קובעת', 'gueta_upsell_cart_products' ); ?>

					<p class="gueta-upsell-admin__sub"><label for="gueta_upsell_cart_categories">קטגוריות בעגלה</label></p>
					<?php gueta_upsell_list_select( 'gueta_upsell_cart_categories', $categories, gueta_upsell_ids( $popup_id, 'cart_categories' ), 'gueta_upsell_cart_categories' ); ?>

					<p class="description">מספיק שאחד מהם בעגלה כדי שהתנאי יתקיים.</p>
				</div>

				<p class="gueta-upsell-admin__totals">
					<label>סכום מינימלי בעגלה <input type="number" min="0" step="1" name="gueta_upsell_cart_min_total" value="<?php echo esc_attr( $cart_min ? (string) $cart_min : '' ); ?>" placeholder="ללא"></label>
					<label>סכום מקסימלי בעגלה <input type="number" min="0" step="1" name="gueta_upsell_cart_max_total" value="<?php echo esc_attr( $cart_max ? (string) $cart_max : '' ); ?>" placeholder="ללא"></label>
				</p>

				<p><label><input type="checkbox" name="gueta_upsell_cart_cross_sells" value="1" <?php checked( $get( 'cart_cross_sells', '0' ), '1' ); ?>> להוסיף את המוצרים המשלימים (Cross-sells) של מה שכבר בעגלה</label></p>

				<p class="description">התנאים נבדקים בשרת בכל טעינת עמוד, ושוב אחרי כל שינוי בעגלה.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="gueta_upsell_audience">למי להציג</label></th>
			<td><?php gueta_upsell_choice_select( 'gueta_upsell_audience', 'audiences', $get( 'audience', 'all' ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><label for="gueta_upsell_trigger">מתי להציג</label></th>
			<td>
				<?php gueta_upsell_choice_select( 'gueta_upsell_trigger', 'triggers', $get( 'trigger', 'delay' ) ); ?>
				<label class="gueta-upsell-admin__delay">השהיה של <input type="number" min="0" max="120" name="gueta_upsell_delay" value="<?php echo esc_attr( (string) $get( 'delay', 5 ) ); ?>"> שניות</label>
				<p class="description">הפופאפ מחכה בצד כל עוד העגלה, התפריט או הצצה מהירה פתוחים, ומופיע כשהם נסגרים.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="gueta_upsell_frequency">באיזו תדירות</label></th>
			<td><?php gueta_upsell_choice_select( 'gueta_upsell_frequency', 'frequencies', $get( 'frequency', 'session' ) ); ?></td>
		</tr>
		<tr>
			<th scope="row"><label for="gueta_upsell_scope">איפה להציג</label></th>
			<td>
				<?php gueta_upsell_choice_select( 'gueta_upsell_scope', 'scopes', $scope ); ?>

				<div class="gueta-upsell-admin__scope-products" <?php echo 'products' === $scope ? '' : 'hidden'; ?>>
					<?php gueta_upsell_product_select( 'gueta_upsell_scope_products', gueta_upsell_ids( $popup_id, 'scope_products' ), 'דפי המוצר שבהם להציג' ); ?>
				</div>

				<div class="gueta-upsell-admin__scope-pages" <?php echo 'pages' === $scope ? '' : 'hidden'; ?>>
					<?php gueta_upsell_list_select( 'gueta_upsell_scope_pages', $pages, gueta_upsell_ids( $popup_id, 'scope_pages' ), 'gueta_upsell_scope_pages' ); ?>
				</div>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * The popups screen: the form on one side, the list on the other.
 *
 * @return void
 */
function gueta_upsell_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display flags only.
	$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
	$edit_id = $edit_id && GUETA_UPSELL_CPT === get_post_type( $edit_id ) ? $edit_id : 0;
	$screen  = admin_url( 'admin.php?page=gueta-upsell-popups' );
	$options = gueta_upsell_options();
	$popups  = get_posts(
		[
			'post_type'      => GUETA_UPSELL_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
		]
	);
	?>
	<div class="wrap gueta-upsell-admin">
		<h1>פופאפים של Upsell</h1>
		<p>כרטיס קטן בפינת המסך שמציע מוצרים ומוסיף אותם לעגלה בלי לעזוב את העמוד.</p>

		<?php if ( ! empty( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>הפופאפ נשמר.</p></div>
		<?php endif; ?>
		<?php if ( ! empty( $_GET['deleted'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>הפופאפ נמחק.</p></div>
		<?php endif; ?>
		<?php if ( ! empty( $_GET['error'] ) ) : ?>
			<div class="notice notice-error"><p>הפופאפ לא נשמר. צריך למלא שם פנימי.</p></div>
		<?php endif; ?>
		<?php // phpcs:enable ?>

		<div class="gueta-upsell-admin__grid">
			<div class="gueta-upsell-admin__panel">
				<h2><?php echo $edit_id ? 'עריכת פופאפ' : 'פופאפ חדש'; ?></h2>
				<form method="post" action="<?php echo esc_url( $screen ); ?>">
					<?php wp_nonce_field( 'gueta_upsell_manage' ); ?>
					<input type="hidden" name="gueta_upsell_action" value="save">
					<input type="hidden" name="upsell_id" value="<?php echo (int) $edit_id; ?>">
					<?php gueta_upsell_render_form( $edit_id ); ?>
					<p class="submit">
						<?php submit_button( $edit_id ? 'שמירת השינויים' : 'יצירת הפופאפ', 'primary', 'submit', false ); ?>
						<?php if ( $edit_id ) : ?>
							<a class="button" href="<?php echo esc_url( $screen ); ?>">פופאפ חדש</a>
						<?php endif; ?>
					</p>
				</form>
			</div>

			<div class="gueta-upsell-admin__panel">
				<h2>הפופאפים</h2>
				<?php if ( ! $popups ) : ?>
					<p>עדיין אין פופאפים.</p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>שם</th>
								<th>מוצרים</th>
								<th>מתי</th>
								<th>סטטוס</th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $popups as $popup ) :
								$count   = count( gueta_upsell_collect_items( $popup->ID ) );
								$trigger = gueta_upsell_meta( $popup->ID, 'trigger' );
								?>
								<tr<?php echo $popup->ID === $edit_id ? ' class="is-editing"' : ''; ?>>
									<td><strong><a href="<?php echo esc_url( add_query_arg( 'edit', $popup->ID, $screen ) ); ?>"><?php echo esc_html( get_the_title( $popup ) ); ?></a></strong></td>
									<td><?php echo esc_html( number_format_i18n( min( $count, GUETA_UPSELL_MAX_ITEMS ) ) ); ?></td>
									<td><?php echo esc_html( $options['triggers'][ $trigger ] ?? $options['triggers']['delay'] ); ?></td>
									<td><?php echo '0' === gueta_upsell_meta( $popup->ID, 'enabled' ) ? 'כבוי' : 'פעיל'; ?></td>
									<td class="gueta-upsell-admin__row-actions">
										<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'edit', $popup->ID, $screen ) ); ?>">עריכה</a>
										<form method="post" action="<?php echo esc_url( $screen ); ?>" onsubmit="return window.confirm('למחוק את הפופאפ? אי אפשר לשחזר.');">
											<?php wp_nonce_field( 'gueta_upsell_manage' ); ?>
											<input type="hidden" name="gueta_upsell_action" value="delete">
											<input type="hidden" name="upsell_id" value="<?php echo (int) $popup->ID; ?>">
											<button type="submit" class="button-link button-link-delete">מחיקה</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * What a popup offers
 * ---------------------------------------------------------------------- */

/**
 * Every product id a popup draws on, in order: manual picks, then cross-sells
 * of what is in the cart when asked for, then its categories and tags.
 *
 * @param int   $popup_id      Popup id.
 * @param int[] $cart_products Product ids in the cart.
 * @return int[]
 */
function gueta_upsell_product_ids( $popup_id, $cart_products = [] ) {
	$ids = gueta_upsell_ids( $popup_id, 'products' );

	if ( $cart_products && '1' === gueta_upsell_meta( $popup_id, 'cart_cross_sells' ) ) {
		foreach ( $cart_products as $cart_product_id ) {
			$cart_product = wc_get_product( $cart_product_id );

			if ( $cart_product ) {
				$ids = array_merge( $ids, array_map( 'absint', $cart_product->get_cross_sell_ids() ) );
			}
		}
	}

	$tax_query = [ 'relation' => 'OR' ];

	foreach ( [ 'product_categories' => 'product_cat', 'product_tags' => 'product_tag' ] as $key => $taxonomy ) {
		$terms = gueta_upsell_ids( $popup_id, $key );

		if ( $terms ) {
			$tax_query[] = [
				'taxonomy' => $taxonomy,
				'field'    => 'term_id',
				'terms'    => $terms,
			];
		}
	}

	if ( count( $tax_query ) > 1 ) {
		$ids = array_merge(
			$ids,
			get_posts(
				[
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'posts_per_page'         => -1,
					'fields'                 => 'ids',
					'orderby'                => 'menu_order title',
					'order'                  => 'ASC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
					'tax_query'              => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				]
			)
		);
	}

	return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
}

/**
 * Plain text to print with textContent.
 *
 * WooCommerce's price markup carries a screen reader sentence and the shekel
 * sign as an entity, and product names on this shop carry entities of their
 * own. Stripped and decoded here, the browser prints exactly what it is given.
 *
 * @param string $html Markup.
 * @return string
 */
function gueta_upsell_text( $html ) {
	return trim( html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' ) );
}

/**
 * The products a popup would show, ready for the browser.
 *
 * Only simple products that can be bought right now. A variable product needs
 * a choice made before it can go in the cart, which a card this size cannot
 * ask for.
 *
 * @param int   $popup_id      Popup id.
 * @param int[] $exclude       Product ids to leave out, usually the cart's.
 * @param int   $limit         Most items, or 0 for all.
 * @param int[] $cart_products Product ids in the cart, for cross-sells.
 * @return array[]
 */
function gueta_upsell_collect_items( $popup_id, $exclude = [], $limit = 0, $cart_products = [] ) {
	$items = [];

	foreach ( gueta_upsell_product_ids( $popup_id, $cart_products ) as $product_id ) {
		if ( in_array( $product_id, $exclude, true ) ) {
			continue;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_type( 'simple' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
			continue;
		}

		$price   = (float) wc_get_price_to_display( $product );
		$regular = (float) wc_get_price_to_display( $product, [ 'price' => $product->get_regular_price() ] );

		$items[] = [
			'id'      => $product_id,
			'name'    => gueta_upsell_text( $product->get_name() ),
			'url'     => $product->get_permalink(),
			'price'   => gueta_upsell_text( wc_price( $price ) ),
			'regular' => $product->is_on_sale() && $regular > $price ? gueta_upsell_text( wc_price( $regular ) ) : '',
			// The square crop the cards and the drawer use, so the three match.
			'image'   => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src( 'woocommerce_thumbnail' ),
		];

		if ( $limit && count( $items ) >= $limit ) {
			break;
		}
	}

	return $items;
}

/* -------------------------------------------------------------------------
 * Who is looking, where, with what in the cart
 * ---------------------------------------------------------------------- */

/**
 * What the cart holds: product ids, their categories and the subtotal.
 *
 * @return array{products:int[],terms:int[],total:float,hash:string}
 */
function gueta_upsell_cart_snapshot() {
	$snapshot = [
		'products' => [],
		'terms'    => [],
		'total'    => 0.0,
		'hash'     => '',
	];

	if ( ! gueta_has_woocommerce() || ! WC()->cart ) {
		return $snapshot;
	}

	foreach ( WC()->cart->get_cart() as $item ) {
		$product_id = absint( $item['product_id'] ?? 0 );

		if ( ! $product_id ) {
			continue;
		}

		$snapshot['products'][] = $product_id;

		if ( ! empty( $item['variation_id'] ) ) {
			$snapshot['products'][] = absint( $item['variation_id'] );
		}

		$snapshot['terms'] = array_merge( $snapshot['terms'], wc_get_product_term_ids( $product_id, 'product_cat' ) );
	}

	$snapshot['products'] = array_values( array_unique( array_filter( $snapshot['products'] ) ) );
	$snapshot['terms']    = array_values( array_unique( array_filter( array_map( 'absint', $snapshot['terms'] ) ) ) );
	// What the shopper sees as the subtotal, tax included when prices show it.
	$snapshot['total']    = (float) WC()->cart->get_displayed_subtotal();
	$snapshot['hash']     = (string) WC()->cart->get_cart_hash();

	return $snapshot;
}

/**
 * Whether the cart meets a popup's rule.
 *
 * @param int   $popup_id Popup id.
 * @param array $snapshot From gueta_upsell_cart_snapshot().
 * @return bool
 */
function gueta_upsell_cart_rule_passes( $popup_id, $snapshot ) {
	$min = (float) gueta_upsell_meta( $popup_id, 'cart_min_total' );
	$max = (float) gueta_upsell_meta( $popup_id, 'cart_max_total' );

	if ( ( $min > 0 && $snapshot['total'] < $min ) || ( $max > 0 && $snapshot['total'] > $max ) ) {
		return false;
	}

	$rule = gueta_upsell_meta( $popup_id, 'cart_rule' ) ?: 'any';

	if ( 'empty' === $rule ) {
		return ! $snapshot['products'];
	}

	if ( 'not_empty' === $rule ) {
		return (bool) $snapshot['products'];
	}

	if ( 'contains' !== $rule && 'not_contains' !== $rule ) {
		return true;
	}

	$products = gueta_upsell_ids( $popup_id, 'cart_products' );
	$terms    = gueta_upsell_ids( $popup_id, 'cart_categories' );

	// A rule about nothing in particular is no rule.
	if ( ! $products && ! $terms ) {
		return true;
	}

	$matched = array_intersect( $products, $snapshot['products'] ) || array_intersect( $terms, $snapshot['terms'] );

	return 'contains' === $rule ? $matched : ! $matched;
}

/**
 * Where the shopper is, in the words the scope setting uses.
 *
 * @return array{scope:string,object_id:int}
 */
function gueta_upsell_page_context() {
	$scope = 'all';

	if ( is_product() ) {
		$scope = 'product';
	} elseif ( is_shop() || is_product_taxonomy() ) {
		$scope = 'shop';
	} elseif ( is_checkout() && ! is_order_received_page() ) {
		$scope = 'checkout';
	}

	return [
		'scope'     => $scope,
		'object_id' => (int) get_queried_object_id(),
	];
}

/**
 * Popups that are switched on and meant for this shopper on this page,
 * before the cart is looked at.
 *
 * @param array $context From gueta_upsell_page_context().
 * @return WP_Post[]
 */
function gueta_upsell_candidates( $context ) {
	$popups = get_posts(
		[
			'post_type'              => GUETA_UPSELL_CPT,
			'post_status'            => 'publish',
			'posts_per_page'         => 20,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		]
	);

	return array_values(
		array_filter(
			$popups,
			static function ( $popup ) use ( $context ) {
				if ( '0' === gueta_upsell_meta( $popup->ID, 'enabled' ) ) {
					return false;
				}

				$audience = gueta_upsell_meta( $popup->ID, 'audience' ) ?: 'all';

				if ( ( 'logged_in' === $audience && ! is_user_logged_in() ) || ( 'logged_out' === $audience && is_user_logged_in() ) ) {
					return false;
				}

				$scope = gueta_upsell_meta( $popup->ID, 'scope' ) ?: 'all';

				switch ( $scope ) {
					case 'all':
						return true;
					case 'products':
						return 'product' === $context['scope'] && in_array( $context['object_id'], gueta_upsell_ids( $popup->ID, 'scope_products' ), true );
					case 'pages':
						return in_array( $context['object_id'], gueta_upsell_ids( $popup->ID, 'scope_pages' ), true );
					default:
						return $scope === $context['scope'];
				}
			}
		)
	);
}

/**
 * Popups ready for the browser: every rule applied, products collected, and
 * whatever is already in the cart left out.
 *
 * @param array $context From gueta_upsell_page_context(). The refresh request
 *                       has no page of its own, so the browser sends it back.
 * @return array[]
 */
function gueta_upsell_active( $context ) {
	$snapshot = gueta_upsell_cart_snapshot();
	$active   = [];

	foreach ( gueta_upsell_candidates( $context ) as $popup ) {
		if ( ! gueta_upsell_cart_rule_passes( $popup->ID, $snapshot ) ) {
			continue;
		}

		$items = gueta_upsell_collect_items( $popup->ID, $snapshot['products'], GUETA_UPSELL_MAX_ITEMS, $snapshot['products'] );

		if ( ! $items ) {
			continue;
		}

		$active[] = [
			'id'        => $popup->ID,
			'heading'   => (string) gueta_upsell_meta( $popup->ID, 'heading' ),
			'items'     => $items,
			'trigger'   => gueta_upsell_meta( $popup->ID, 'trigger' ) ?: 'delay',
			'delay'     => absint( gueta_upsell_meta( $popup->ID, 'delay' ) ),
			'frequency' => gueta_upsell_meta( $popup->ID, 'frequency' ) ?: 'session',
		];
	}

	return $active;
}

/* -------------------------------------------------------------------------
 * Front end
 * ---------------------------------------------------------------------- */

/**
 * The popup script, with the popups this page view qualifies for.
 *
 * The script loads wherever a popup could apply to this page and shopper, even
 * when the cart rules rule them all out right now. A page served from a cache
 * was drawn for somebody else's cart; the script notices that the cart cookie
 * has changed and asks again, which it cannot do if it never loaded.
 *
 * @return void
 */
function gueta_upsell_frontend_assets() {
	if ( ! gueta_has_woocommerce() || is_admin() || gueta_in_elementor_editor() ) {
		return;
	}

	$context = gueta_upsell_page_context();

	if ( ! gueta_upsell_candidates( $context ) ) {
		return;
	}

	$uri = get_stylesheet_directory_uri();

	wp_enqueue_style(
		'gueta-upsell',
		$uri . '/assets/css/gueta-upsell.css',
		[ 'gueta-header' ],
		gueta_asset_version( '/assets/css/gueta-upsell.css' )
	);

	wp_enqueue_script(
		'gueta-upsell',
		$uri . '/assets/js/gueta-upsell.js',
		[],
		gueta_asset_version( '/assets/js/gueta-upsell.js' ),
		true
	);

	wp_localize_script(
		'gueta-upsell',
		'guetaUpsell',
		[
			'popups'     => gueta_upsell_active( $context ),
			'context'    => $context,
			'cartHash'   => gueta_upsell_cart_snapshot()['hash'],
			'addUrl'     => WC_AJAX::get_endpoint( 'gueta_upsell_add' ),
			'refreshUrl' => WC_AJAX::get_endpoint( 'gueta_upsell_refresh' ),
			'isCheckout' => 'checkout' === $context['scope'],
			'strings'    => [
				'dialog'   => 'הצעה בשבילך',
				'add'      => 'הוספה לעגלה',
				'adding'   => 'מוסיפים…',
				'added'    => 'נוסף לעגלה',
				'error'    => 'לא הצלחנו להוסיף, נסו שוב',
				'next'     => 'המוצר הבא',
				'previous' => 'המוצר הקודם',
				'close'    => 'סגירה',
			],
		]
	);
}
add_action( 'wp_enqueue_scripts', 'gueta_upsell_frontend_assets', 30 );

/**
 * Add a popup's product to the cart and send back the refreshed fragments.
 *
 * WooCommerce's own AJAX endpoint rather than admin-ajax, so the request runs
 * as a front end one with the cart and the session loaded. No nonce, the same
 * as WooCommerce's own add to cart: putting a product in a shopper's cart is
 * not worth forging, and a nonce printed into a cached page goes stale.
 *
 * @return void
 */
function gueta_upsell_ajax_add() {
	if ( ! WC()->cart ) {
		wp_send_json_error( [ 'message' => 'החנות אינה זמינה כרגע.' ], 400 );
	}

	$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$popup_id   = isset( $_POST['popup_id'] ) ? absint( $_POST['popup_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	$product    = $product_id ? wc_get_product( $product_id ) : null;

	if ( ! $product || ! $product->is_type( 'simple' ) || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		wp_send_json_error( [ 'message' => 'המוצר אינו זמין כרגע.' ], 400 );
	}

	wc_clear_notices();

	if ( ! WC()->cart->add_to_cart( $product_id, 1 ) ) {
		$errors = wc_get_notices( 'error' );
		wc_clear_notices();

		wp_send_json_error(
			[ 'message' => $errors ? wp_strip_all_tags( $errors[0]['notice'] ) : 'לא הצלחנו להוסיף את המוצר.' ],
			400
		);
	}

	// The added to cart notice would otherwise wait for the next page load.
	wc_clear_notices();

	$popup_title = $popup_id && GUETA_UPSELL_CPT === get_post_type( $popup_id ) ? get_the_title( $popup_id ) : '';

	gueta_upsell_remember_source( $product_id, $popup_id, $popup_title );

	WC()->cart->calculate_totals();

	wp_send_json_success(
		[
			'productId' => $product_id,
			'cartHash'  => WC()->cart->get_cart_hash(),
			// The header badge and the drawer, among whatever else is listening.
			'fragments' => apply_filters( 'woocommerce_add_to_cart_fragments', [] ),
		]
	);
}
add_action( 'wc_ajax_gueta_upsell_add', 'gueta_upsell_ajax_add' );

/**
 * Work the popups out again for the cart as it is now.
 *
 * @return void
 */
function gueta_upsell_ajax_refresh() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- read only.
	$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'all';

	$context = [
		'scope'     => in_array( $scope, [ 'product', 'shop', 'checkout' ], true ) ? $scope : 'all',
		'object_id' => isset( $_POST['object_id'] ) ? absint( $_POST['object_id'] ) : 0,
	];
	// phpcs:enable

	wp_send_json_success(
		[
			'popups'   => gueta_upsell_active( $context ),
			'cartHash' => gueta_upsell_cart_snapshot()['hash'],
		]
	);
}
add_action( 'wc_ajax_gueta_upsell_refresh', 'gueta_upsell_ajax_refresh' );

/* -------------------------------------------------------------------------
 * Which order lines a popup sold
 *
 * Remembered in the shopper's session while they shop, then written onto the
 * order line under a key that starts with an underscore, which WooCommerce
 * keeps from the customer. The shop sees a tag on the order screen instead.
 * ---------------------------------------------------------------------- */

/**
 * Remember that a product came from a popup.
 *
 * @param int    $product_id  Product id.
 * @param int    $popup_id    Popup id.
 * @param string $popup_title Popup name.
 * @return void
 */
function gueta_upsell_remember_source( $product_id, $popup_id, $popup_title ) {
	if ( ! WC()->session ) {
		return;
	}

	$map = WC()->session->get( GUETA_UPSELL_SESSION_SOURCE );
	$map = is_array( $map ) ? $map : [];

	$map[ $product_id ] = [
		'popup_id' => $popup_id,
		'title'    => $popup_title,
	];

	WC()->session->set( GUETA_UPSELL_SESSION_SOURCE, $map );
}

/**
 * Tag the order line a popup's product ended up on.
 *
 * @param WC_Order_Item_Product $item          Order line.
 * @param string                $cart_item_key Cart key.
 * @param array                 $values        Cart item.
 * @return void
 */
function gueta_upsell_tag_order_item( $item, $cart_item_key, $values ) {
	$map = WC()->session ? WC()->session->get( GUETA_UPSELL_SESSION_SOURCE ) : null;
	$id  = absint( $values['product_id'] ?? 0 );

	if ( ! is_array( $map ) || ! $id || empty( $map[ $id ] ) ) {
		return;
	}

	$item->add_meta_data( GUETA_UPSELL_ITEM_META, $map[ $id ]['title'] ?: 'פופאפ Upsell', true );

	if ( ! empty( $map[ $id ]['popup_id'] ) ) {
		$item->add_meta_data( GUETA_UPSELL_ITEM_META_ID, $map[ $id ]['popup_id'], true );
	}
}
add_action( 'woocommerce_checkout_create_order_line_item', 'gueta_upsell_tag_order_item', 10, 3 );

/**
 * Flag the order, note what the popups sold, and forget the session's list.
 *
 * @param int      $order_id    Order id.
 * @param array    $posted_data Posted checkout data.
 * @param WC_Order $order       Order.
 * @return void
 */
function gueta_upsell_tag_order( $order_id, $posted_data, $order ) {
	$sold = [];

	foreach ( $order->get_items() as $item ) {
		$source = $item->get_meta( GUETA_UPSELL_ITEM_META );

		if ( $source ) {
			$sold[] = sprintf( '%s (%s)', $item->get_name(), $source );
		}
	}

	if ( WC()->session ) {
		WC()->session->set( GUETA_UPSELL_SESSION_SOURCE, [] );
	}

	if ( ! $sold ) {
		return;
	}

	$order->update_meta_data( GUETA_UPSELL_ORDER_META, 'yes' );
	$order->add_order_note( 'נמכר דרך פופאפ Upsell: ' . implode( ', ', $sold ) );
	$order->save();
}
add_action( 'woocommerce_checkout_order_processed', 'gueta_upsell_tag_order', 20, 3 );

/**
 * Keep the raw keys out of the order screen, where the tag below says it better.
 *
 * @param string[] $keys Hidden meta keys.
 * @return string[]
 */
function gueta_upsell_hide_item_meta( $keys ) {
	$keys[] = GUETA_UPSELL_ITEM_META;
	$keys[] = GUETA_UPSELL_ITEM_META_ID;

	return $keys;
}
add_filter( 'woocommerce_hidden_order_itemmeta', 'gueta_upsell_hide_item_meta' );

/**
 * A tag under the line on the admin order screen.
 *
 * @param int           $item_id Item id.
 * @param WC_Order_Item $item    Order line.
 * @return void
 */
function gueta_upsell_order_item_tag( $item_id, $item ) {
	if ( ! is_admin() || ! $item instanceof WC_Order_Item_Product ) {
		return;
	}

	$source = $item->get_meta( GUETA_UPSELL_ITEM_META );

	if ( $source ) {
		printf(
			'<div style="background:#fff3ec;border-radius:3px;color:#a33c0f;display:inline-block;font-size:12px;font-weight:600;margin-top:6px;padding:3px 8px;">נוסף מפופאפ: %s</div>',
			esc_html( $source )
		);
	}
}
add_action( 'woocommerce_after_order_itemmeta', 'gueta_upsell_order_item_tag', 10, 2 );
