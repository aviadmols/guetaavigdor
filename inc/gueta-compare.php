<?php
/**
 * Product comparison: a switch on every product card, a bar that collects the
 * selection and a drawer that lays the products out side by side.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How many products can be compared at once.
 */
const GUETA_COMPARE_MAX = 5;

/**
 * Whether comparison should be available on this request.
 *
 * @return bool
 */
function gueta_compare_enabled() {
	if ( ! gueta_has_woocommerce() ) {
		return false;
	}

	// Listings only: on a product page the bar would collide with the sticky
	// add to cart bar, and comparison belongs where you are still choosing.
	$enabled = is_shop() || is_product_taxonomy() || is_search();

	return (bool) apply_filters( 'gueta_compare_enabled', $enabled );
}

/**
 * Assets for the comparison UI.
 *
 * @return void
 */
function gueta_compare_assets() {
	if ( ! gueta_compare_enabled() ) {
		return;
	}

	$uri = get_stylesheet_directory_uri();

	wp_enqueue_style(
		'gueta-compare',
		$uri . '/assets/css/gueta-compare.css',
		[ 'gueta-header' ],
		gueta_asset_version( '/assets/css/gueta-compare.css' )
	);

	wp_enqueue_script(
		'gueta-compare',
		$uri . '/assets/js/gueta-compare.js',
		[],
		gueta_asset_version( '/assets/js/gueta-compare.js' ),
		true
	);

	wp_localize_script(
		'gueta-compare',
		'guetaCompare',
		[
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'gueta_header' ),
			'max'     => GUETA_COMPARE_MAX,
		]
	);
}
add_action( 'wp_enqueue_scripts', 'gueta_compare_assets', 27 );

/* -------------------------------------------------------------------------
 * The switch
 * ---------------------------------------------------------------------- */

/**
 * The toggle placed on a product card.
 *
 * @param int $product_id Product id.
 * @return string
 */
function gueta_compare_switch_html( $product_id ) {
	ob_start();
	?>
	<label class="gueta-compare-switch" data-compare-switch>
		<input
			type="checkbox"
			class="gueta-compare-switch__input"
			value="<?php echo (int) $product_id; ?>"
			data-compare-toggle
		>
		<span class="gueta-compare-switch__text">השוואה</span>
		<span class="gueta-compare-switch__track" aria-hidden="true">
			<span class="gueta-compare-switch__thumb"></span>
		</span>
	</label>
	<?php

	return (string) ob_get_clean();
}

/**
 * Add the switch to the shop's own product cards.
 *
 * The archive is drawn by a shortcode whose cards carry no product id, so the
 * id is resolved from each card's permalink.
 *
 * @param string                 $content Rendered widget HTML.
 * @param \Elementor\Widget_Base $widget  Widget instance.
 * @return string
 */
function gueta_inject_compare_switches( $content, $widget ) {
	if ( ! gueta_compare_enabled() ) {
		return $content;
	}

	if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'shortcode' !== $widget->get_name() ) {
		return $content;
	}

	if ( false === strpos( $content, 'smart-product-card' ) || ! class_exists( 'DOMDocument' ) ) {
		return $content;
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $content );
	libxml_clear_errors();

	$xpath = new DOMXPath( $dom );
	$cards = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " smart-product-card ")]' );

	if ( ! $cards || ! $cards->length ) {
		return $content;
	}

	$changed = false;

	foreach ( $cards as $card ) {
		$links = $xpath->query( './/a[@href]', $card );

		if ( ! $links || ! $links->length ) {
			continue;
		}

		$product_id = gueta_product_id_from_url( $links->item( 0 )->getAttribute( 'href' ) );

		if ( ! $product_id ) {
			continue;
		}

		$fragment = $dom->createDocumentFragment();

		if ( ! $fragment->appendXML( gueta_compare_switch_html( $product_id ) ) ) {
			continue;
		}

		$card->appendChild( $fragment );
		$card->setAttribute( 'data-compare-card', (string) $product_id );
		$changed = true;
	}

	if ( ! $changed ) {
		return $content;
	}

	$body = $dom->getElementsByTagName( 'body' )->item( 0 );

	if ( ! $body ) {
		return $content;
	}

	$html = '';

	foreach ( $body->childNodes as $node ) {
		$html .= $dom->saveHTML( $node );
	}

	return $html;
}
add_filter( 'elementor/widget/render_content', 'gueta_inject_compare_switches', 20, 2 );

/**
 * Resolve a product id from its permalink, cached for the request.
 *
 * @param string $url Permalink.
 * @return int
 */
function gueta_product_id_from_url( $url ) {
	static $cache = [];

	$url = trim( (string) $url );

	if ( '' === $url ) {
		return 0;
	}

	if ( isset( $cache[ $url ] ) ) {
		return $cache[ $url ];
	}

	$id = (int) url_to_postid( $url );

	if ( $id && 'product' !== get_post_type( $id ) ) {
		$id = 0;
	}

	$cache[ $url ] = $id;

	return $id;
}

/* -------------------------------------------------------------------------
 * Bar and drawer
 * ---------------------------------------------------------------------- */

/**
 * The bar that collects the selection, and the drawer it opens.
 *
 * @return void
 */
function gueta_render_compare_ui() {
	if ( ! gueta_compare_enabled() ) {
		return;
	}
	?>
	<div class="gueta-compare-bar" data-compare-bar hidden>
		<div class="gueta-compare-bar__inner">
			<div class="gueta-compare-bar__count">
				השוואה (<span data-compare-count>0</span>/<?php echo (int) GUETA_COMPARE_MAX; ?>)
			</div>

			<ul class="gueta-compare-bar__list" data-compare-list></ul>

			<div class="gueta-compare-bar__actions">
				<button type="button" class="gueta-compare-bar__open" data-compare-open>השוואה</button>
				<button type="button" class="gueta-compare-bar__clear" data-compare-clear>נקה הכל</button>
			</div>
		</div>
	</div>

	<div class="gueta-compare-drawer" data-compare-drawer hidden>
		<div class="gueta-compare-drawer__backdrop" data-compare-close></div>
		<div class="gueta-compare-drawer__panel" role="dialog" aria-modal="true" aria-label="השוואת מוצרים">
			<div class="gueta-compare-drawer__head">
				<h2 class="gueta-compare-drawer__title">השוואת מוצרים</h2>
				<button type="button" class="gueta-icon-button" data-compare-close aria-label="סגירה">
					<?php echo gueta_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>
			<div class="gueta-compare-drawer__body" data-compare-body></div>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'gueta_render_compare_ui' );

/* -------------------------------------------------------------------------
 * Data
 * ---------------------------------------------------------------------- */

/**
 * Return the comparison table for the requested products.
 *
 * @return void
 */
function gueta_ajax_compare() {
	check_ajax_referer( 'gueta_header', 'nonce' );

	$raw = isset( $_POST['ids'] ) ? sanitize_text_field( wp_unslash( $_POST['ids'] ) ) : '';
	$ids = array_slice( array_filter( array_map( 'absint', explode( ',', $raw ) ) ), 0, GUETA_COMPARE_MAX );

	if ( ! $ids || ! gueta_has_woocommerce() ) {
		wp_send_json_success(
			[
				'html'  => '',
				'items' => [],
			]
		);
	}

	$products = [];

	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );

		if ( $product && $product->is_visible() ) {
			$products[] = $product;
		}
	}

	wp_send_json_success(
		[
			'html'  => gueta_render_compare_table( $products ),
			'items' => array_map( 'gueta_compare_item_data', $products ),
		]
	);
}
add_action( 'wp_ajax_gueta_compare', 'gueta_ajax_compare' );
add_action( 'wp_ajax_nopriv_gueta_compare', 'gueta_ajax_compare' );

/**
 * The thumbnail and title the bar shows for a product.
 *
 * @param WC_Product $product Product.
 * @return array
 */
function gueta_compare_item_data( $product ) {
	return [
		'id'    => $product->get_id(),
		'title' => $product->get_name(),
		'image' => wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_gallery_thumbnail' ) ?: wc_placeholder_img_src( 'woocommerce_gallery_thumbnail' ),
	];
}

/**
 * Every attribute label used across the compared products.
 *
 * @param WC_Product[] $products Products.
 * @return array<string,string> Attribute key to label.
 */
function gueta_compare_attribute_labels( $products ) {
	$labels = [];

	foreach ( $products as $product ) {
		foreach ( $product->get_attributes() as $key => $attribute ) {
			if ( $attribute->get_visible() ) {
				$labels[ $key ] = wc_attribute_label( $attribute->get_name() );
			}
		}
	}

	return $labels;
}

/**
 * One product's value for an attribute.
 *
 * @param WC_Product $product Product.
 * @param string     $key     Attribute key.
 * @return string
 */
function gueta_compare_attribute_value( $product, $key ) {
	$attributes = $product->get_attributes();

	if ( empty( $attributes[ $key ] ) ) {
		return '—';
	}

	$attribute = $attributes[ $key ];

	if ( $attribute->is_taxonomy() ) {
		$values = wc_get_product_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] );
	} else {
		$values = $attribute->get_options();
	}

	return $values ? implode( ', ', $values ) : '—';
}

/**
 * Render the side by side table.
 *
 * @param WC_Product[] $products Products.
 * @return string
 */
function gueta_render_compare_table( $products ) {
	if ( ! $products ) {
		return '';
	}

	$attributes = gueta_compare_attribute_labels( $products );

	ob_start();
	?>
	<table class="gueta-compare-table">
		<thead>
			<tr>
				<th class="gueta-compare-table__label"><span class="screen-reader-text">מוצר</span></th>
				<?php foreach ( $products as $product ) : ?>
					<th class="gueta-compare-table__product">
						<button type="button" class="gueta-compare-table__remove" data-compare-remove="<?php echo (int) $product->get_id(); ?>" aria-label="הסרה מההשוואה">
							<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
						</button>
						<a class="gueta-compare-table__media" href="<?php echo esc_url( $product->get_permalink() ); ?>">
							<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?>
						</a>
						<a class="gueta-compare-table__name" href="<?php echo esc_url( $product->get_permalink() ); ?>">
							<?php echo esc_html( $product->get_name() ); ?>
						</a>
					</th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
			<tr>
				<th class="gueta-compare-table__label">מחיר</th>
				<?php foreach ( $products as $product ) : ?>
					<td class="gueta-compare-table__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></td>
				<?php endforeach; ?>
			</tr>
			<tr>
				<th class="gueta-compare-table__label">זמינות</th>
				<?php foreach ( $products as $product ) : ?>
					<td><?php echo esc_html( $product->is_in_stock() ? 'במלאי' : 'אזל מהמלאי' ); ?></td>
				<?php endforeach; ?>
			</tr>
			<?php if ( array_filter( array_map( static fn( $p ) => $p->get_sku(), $products ) ) ) : ?>
				<tr>
					<th class="gueta-compare-table__label">מק"ט</th>
					<?php foreach ( $products as $product ) : ?>
						<td><?php echo esc_html( $product->get_sku() ?: '—' ); ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endif; ?>
			<?php foreach ( $attributes as $key => $label ) : ?>
				<tr>
					<th class="gueta-compare-table__label"><?php echo esc_html( $label ); ?></th>
					<?php foreach ( $products as $product ) : ?>
						<td><?php echo esc_html( gueta_compare_attribute_value( $product, $key ) ); ?></td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th class="gueta-compare-table__label"></th>
				<?php foreach ( $products as $product ) : ?>
					<td>
						<a class="gueta-compare-table__cta" href="<?php echo esc_url( $product->get_permalink() ); ?>">
							לפרטים ורכישה
						</a>
					</td>
				<?php endforeach; ?>
			</tr>
		</tbody>
	</table>
	<?php

	return (string) ob_get_clean();
}
