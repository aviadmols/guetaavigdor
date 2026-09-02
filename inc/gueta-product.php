<?php
/**
 * Single product page: accordion sections, zoomable gallery, reviews and the
 * sticky add to cart bar.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Take over the gallery from WooCommerce.
 *
 * The core gallery ships flexslider, zoom and PhotoSwipe. This theme renders
 * its own gallery and lightbox, so those would only fight over the same nodes.
 *
 * @return void
 */
function gueta_product_setup() {
	remove_theme_support( 'wc-product-gallery-zoom' );
	remove_theme_support( 'wc-product-gallery-lightbox' );
	remove_theme_support( 'wc-product-gallery-slider' );
}
add_action( 'after_setup_theme', 'gueta_product_setup', 20 );

/**
 * Product page assets.
 *
 * @return void
 */
function gueta_product_assets() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	$uri = get_stylesheet_directory_uri();

	wp_enqueue_style(
		'gueta-product',
		$uri . '/assets/css/gueta-product.css',
		[ 'gueta-header' ],
		gueta_asset_version( '/assets/css/gueta-product.css' )
	);

	wp_enqueue_script(
		'gueta-product',
		$uri . '/assets/js/gueta-product.js',
		[],
		gueta_asset_version( '/assets/js/gueta-product.js' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'gueta_product_assets', 26 );

/* -------------------------------------------------------------------------
 * Gallery
 * ---------------------------------------------------------------------- */

/**
 * Replace the core gallery with one that drives the lightbox.
 *
 * @return void
 */
function gueta_swap_product_gallery() {
	remove_action( 'woocommerce_before_single_product_summary', 'woocommerce_show_product_images', 20 );
	add_action( 'woocommerce_before_single_product_summary', 'gueta_render_product_gallery', 20 );
}
add_action( 'wp', 'gueta_swap_product_gallery' );

/**
 * Every image the gallery shows, largest first.
 *
 * @param WC_Product $product Product.
 * @return int[]
 */
function gueta_gallery_image_ids( $product ) {
	$ids = [];

	if ( $product->get_image_id() ) {
		$ids[] = (int) $product->get_image_id();
	}

	foreach ( $product->get_gallery_image_ids() as $id ) {
		$ids[] = (int) $id;
	}

	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Render the gallery: one large frame plus a thumbnail rail.
 *
 * @return void
 */
function gueta_render_product_gallery() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$ids = gueta_gallery_image_ids( $product );
	?>
	<div class="gueta-gallery" data-gallery>
		<div class="gueta-gallery__stage">
			<?php if ( ! $ids ) : ?>
				<div class="gueta-gallery__placeholder">
					<?php echo wp_kses_post( wc_placeholder_img( 'woocommerce_single' ) ); ?>
				</div>
			<?php else : ?>
				<?php foreach ( $ids as $index => $id ) : ?>
					<button
						type="button"
						class="gueta-gallery__slide<?php echo 0 === $index ? ' is-active' : ''; ?>"
						data-gallery-slide="<?php echo (int) $index; ?>"
						data-full="<?php echo esc_url( (string) wp_get_attachment_image_url( $id, 'full' ) ); ?>"
						aria-label="הגדלת התמונה"
					>
						<?php
						echo wp_get_attachment_image(
							$id,
							'woocommerce_single',
							false,
							[
								'class'   => 'gueta-gallery__image',
								'alt'     => esc_attr( $product->get_name() ),
								'loading' => 0 === $index ? 'eager' : 'lazy',
							]
						);
						?>
						<span class="gueta-gallery__zoom" aria-hidden="true">
							<svg viewBox="0 0 24 24"><circle cx="10.5" cy="10.5" r="6.75"></circle><path d="m15.6 15.6 4.9 4.9M8 10.5h5M10.5 8v5"></path></svg>
						</span>
					</button>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>

		<?php if ( count( $ids ) > 1 ) : ?>
			<div class="gueta-gallery__thumbs" role="tablist" aria-label="תמונות המוצר">
				<?php foreach ( $ids as $index => $id ) : ?>
					<button
						type="button"
						role="tab"
						class="gueta-gallery__thumb<?php echo 0 === $index ? ' is-active' : ''; ?>"
						data-gallery-thumb="<?php echo (int) $index; ?>"
						aria-selected="<?php echo 0 === $index ? 'true' : 'false'; ?>"
						aria-label="<?php printf( 'תמונה %d', (int) $index + 1 ); ?>"
					>
						<?php echo wp_get_attachment_image( $id, 'woocommerce_gallery_thumbnail', false, [ 'alt' => '', 'loading' => 'lazy' ] ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * The lightbox shell, printed once per product page.
 *
 * @return void
 */
function gueta_render_lightbox() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}
	?>
	<div class="gueta-lightbox" data-lightbox hidden>
		<div class="gueta-lightbox__stage" data-lightbox-stage>
			<img class="gueta-lightbox__image" src="" alt="" data-lightbox-image>
		</div>
		<div class="gueta-lightbox__bar">
			<button type="button" class="gueta-lightbox__button" data-lightbox-prev aria-label="התמונה הקודמת">
				<svg viewBox="0 0 24 24"><path d="M14 5 7 12l7 7"></path></svg>
			</button>
			<button type="button" class="gueta-lightbox__button" data-lightbox-close aria-label="סגירה">
				<svg viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"></path></svg>
			</button>
			<button type="button" class="gueta-lightbox__button" data-lightbox-next aria-label="התמונה הבאה">
				<svg viewBox="0 0 24 24"><path d="m10 5 7 7-7 7"></path></svg>
			</button>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'gueta_render_lightbox' );

/* -------------------------------------------------------------------------
 * Accordion
 * ---------------------------------------------------------------------- */

/**
 * Split the product description into accordion sections on its own headings.
 *
 * Descriptions on this shop are written as an intro followed by headings such
 * as "מפרט טכני" and "שאלות נפוצות", so the panels come straight from the
 * content already entered.
 *
 * @param string $html Product description.
 * @return array<int,array{title:string,content:string}>
 */
function gueta_split_description( $html ) {
	$html = trim( (string) $html );

	if ( '' === $html ) {
		return [];
	}

	if ( ! class_exists( 'DOMDocument' ) ) {
		return [ [ 'title' => 'תיאור', 'content' => $html ] ];
	}

	$dom = new DOMDocument();
	libxml_use_internal_errors( true );
	$dom->loadHTML( '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html );
	libxml_clear_errors();

	$body = $dom->getElementsByTagName( 'body' )->item( 0 );

	if ( ! $body ) {
		return [ [ 'title' => 'תיאור', 'content' => $html ] ];
	}

	$sections = [];
	$intro    = '';
	$current  = null;

	foreach ( iterator_to_array( $body->childNodes ) as $node ) {
		if ( XML_ELEMENT_NODE === $node->nodeType && preg_match( '/^h[1-4]$/i', $node->nodeName ) ) {
			if ( $current ) {
				$sections[] = $current;
			}

			$current = [
				'title'   => trim( $node->textContent ),
				'content' => '',
			];
			continue;
		}

		// Rules are section separators in this content; the panels replace them.
		if ( XML_ELEMENT_NODE === $node->nodeType && 'hr' === strtolower( $node->nodeName ) ) {
			continue;
		}

		$fragment = $dom->saveHTML( $node );

		if ( ! trim( wp_strip_all_tags( $fragment ) ) && ! preg_match( '/<(img|iframe|table|ul|ol)/i', $fragment ) ) {
			continue;
		}

		if ( $current ) {
			$current['content'] .= $fragment;
		} else {
			$intro .= $fragment;
		}
	}

	if ( $current ) {
		$sections[] = $current;
	}

	if ( trim( wp_strip_all_tags( $intro ) ) ) {
		array_unshift(
			$sections,
			[
				'title'   => 'תיאור',
				'content' => $intro,
			]
		);
	}

	return array_values(
		array_filter(
			$sections,
			static function ( $section ) {
				return '' !== trim( $section['title'] ) && '' !== trim( wp_strip_all_tags( $section['content'] ) );
			}
		)
	);
}

/**
 * A specification panel built from the product's attributes.
 *
 * @param WC_Product $product Product.
 * @return string
 */
function gueta_attribute_table( $product ) {
	$attributes = array_filter(
		$product->get_attributes(),
		static function ( $attribute ) {
			return $attribute->get_visible();
		}
	);

	if ( ! $attributes ) {
		return '';
	}

	ob_start();
	echo '<table class="gueta-specs"><tbody>';

	foreach ( $attributes as $attribute ) {
		$name = wc_attribute_label( $attribute->get_name() );

		if ( $attribute->is_taxonomy() ) {
			$values = wc_get_product_terms( $product->get_id(), $attribute->get_name(), [ 'fields' => 'names' ] );
		} else {
			$values = $attribute->get_options();
		}

		if ( ! $values ) {
			continue;
		}

		printf(
			'<tr><th>%s</th><td>%s</td></tr>',
			esc_html( $name ),
			esc_html( implode( ', ', $values ) )
		);
	}

	echo '</tbody></table>';

	return (string) ob_get_clean();
}

/**
 * All accordion panels for the current product.
 *
 * @param WC_Product $product Product.
 * @return array
 */
function gueta_product_panels( $product ) {
	$panels = gueta_split_description( $product->get_description() );
	$titles = wp_list_pluck( $panels, 'title' );
	$specs  = gueta_attribute_table( $product );

	// Only add a spec panel when the description does not already carry one.
	if ( $specs && ! in_array( 'מפרט טכני', $titles, true ) ) {
		$panels[] = [
			'title'   => 'מפרט טכני',
			'content' => $specs,
		];
	}

	$shipping = (string) apply_filters( 'gueta_product_shipping_panel', '' );

	if ( $shipping ) {
		$panels[] = [
			'title'   => 'משלוחים וזמני אספקה',
			'content' => $shipping,
		];
	}

	return (array) apply_filters( 'gueta_product_panels', $panels, $product );
}

/**
 * Swap WooCommerce's tab strip for the accordion inside the summary column.
 *
 * @return void
 */
function gueta_swap_product_tabs() {
	remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_product_data_tabs', 10 );
	add_action( 'woocommerce_single_product_summary', 'gueta_render_product_accordion', 45 );
}
add_action( 'wp', 'gueta_swap_product_tabs' );

/**
 * Render the accordion.
 *
 * @return void
 */
function gueta_render_product_accordion() {
	global $product;

	if ( ! $product instanceof WC_Product ) {
		return;
	}

	$panels = gueta_product_panels( $product );

	if ( ! $panels ) {
		return;
	}
	?>
	<div class="gueta-accordion" data-accordion-group>
		<?php foreach ( $panels as $index => $panel ) : ?>
			<details class="gueta-accordion__item"<?php echo 0 === $index ? ' open' : ''; ?>>
				<summary class="gueta-accordion__summary">
					<span class="gueta-accordion__title"><?php echo esc_html( $panel['title'] ); ?></span>
					<span class="gueta-accordion__sign" aria-hidden="true"></span>
				</summary>
				<div class="gueta-accordion__content">
					<?php echo wp_kses_post( $panel['content'] ); ?>
				</div>
			</details>
		<?php endforeach; ?>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Reviews
 * ---------------------------------------------------------------------- */

/**
 * Render reviews and the review form in their own section under the product.
 *
 * WooCommerce normally hides these inside the tab strip that this theme
 * removes, so they are rendered directly instead.
 *
 * @return void
 */
function gueta_render_reviews() {
	static $rendered = false;

	if ( $rendered || ! function_exists( 'wc_reviews_enabled' ) || ! wc_reviews_enabled() ) {
		return;
	}

	$rendered = true;
	?>
	<section class="gueta-reviews" id="reviews">
		<div class="gueta-reviews__inner">
			<h2 class="gueta-reviews__heading">חוות דעת של לקוחות</h2>
			<?php comments_template(); ?>
		</div>
	</section>
	<?php
}
add_action( 'woocommerce_after_single_product_summary', 'gueta_render_reviews', 20 );

/**
 * Prompt for a review from the summary column, linking down to the form.
 *
 * @return void
 */
function gueta_render_rating_link() {
	global $product;

	if ( ! $product instanceof WC_Product || ! function_exists( 'wc_reviews_enabled' ) || ! wc_reviews_enabled() ) {
		return;
	}

	$count = $product->get_review_count();
	?>
	<a class="gueta-rating-link" href="#reviews">
		<?php echo wp_kses_post( wc_get_rating_html( (float) $product->get_average_rating() ) ); ?>
		<span>
			<?php
			echo $count
				? esc_html( sprintf( _n( 'חוות דעת אחת', '%s חוות דעת', $count, 'hello-elementor-child' ), number_format_i18n( $count ) ) )
				: 'היו הראשונים לכתוב חוות דעת';
			?>
		</span>
	</a>
	<?php
}
add_action( 'woocommerce_single_product_summary', 'gueta_render_rating_link', 11 );

/* -------------------------------------------------------------------------
 * Sticky add to cart bar
 * ---------------------------------------------------------------------- */

/**
 * Render the bar that pins to the bottom once the add to cart form scrolls away.
 *
 * @return void
 */
function gueta_render_sticky_atc() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	global $product;

	if ( ! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
		return;
	}
	?>
	<div class="gueta-sticky-atc" data-sticky-atc hidden>
		<div class="gueta-sticky-atc__inner">
			<div class="gueta-sticky-atc__info">
				<span class="gueta-sticky-atc__media">
					<?php echo wp_kses_post( $product->get_image( 'woocommerce_gallery_thumbnail' ) ); ?>
				</span>
				<span class="gueta-sticky-atc__text">
					<span class="gueta-sticky-atc__title"><?php echo esc_html( $product->get_name() ); ?></span>
					<span class="gueta-sticky-atc__price" data-sticky-price><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
				</span>
			</div>
			<button type="button" class="gueta-sticky-atc__cta" data-sticky-add>
				<?php echo esc_html( $product->single_add_to_cart_text() ); ?>
			</button>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'gueta_render_sticky_atc' );

/* -------------------------------------------------------------------------
 * Elementor Pro theme builder
 *
 * The shop renders its product page from an Elementor single template, so
 * none of the WooCommerce summary hooks above ever fire there. The same
 * pieces are wired into Elementor's own render pipeline instead.
 * ---------------------------------------------------------------------- */

/**
 * Resolve the product being rendered, falling back to the queried post.
 *
 * @return WC_Product|null
 */
function gueta_current_product() {
	global $product;

	if ( $product instanceof WC_Product ) {
		return $product;
	}

	if ( ! function_exists( 'wc_get_product' ) ) {
		return null;
	}

	$resolved = wc_get_product( get_the_ID() );

	if ( $resolved instanceof WC_Product ) {
		$product = $resolved;

		return $resolved;
	}

	return null;
}

/**
 * Replace the Product Content widget's wall of text with the accordion.
 *
 * @param string              $content Rendered widget HTML.
 * @param \Elementor\Widget_Base $widget  Widget instance.
 * @return string
 */
function gueta_elementor_product_content( $content, $widget ) {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return $content;
	}

	if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'woocommerce-product-content' !== $widget->get_name() ) {
		return $content;
	}

	$product = gueta_current_product();

	if ( ! $product || ! gueta_product_panels( $product ) ) {
		return $content;
	}

	ob_start();
	gueta_render_rating_link();
	gueta_render_product_accordion();

	return (string) ob_get_clean();
}
add_filter( 'elementor/widget/render_content', 'gueta_elementor_product_content', 10, 2 );

/**
 * Swap the site's gallery shortcode for the theme's own gallery.
 *
 * The shortcode renders a fixed size <img> with its own thumbnail script and
 * no zoom. Replacing it gives responsive images, lazy loading and the
 * lightbox, and keeps the gallery in one place.
 *
 * @param string                 $content Rendered widget HTML.
 * @param \Elementor\Widget_Base $widget  Widget instance.
 * @return string
 */
function gueta_replace_gallery_shortcode( $content, $widget ) {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return $content;
	}

	if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'shortcode' !== $widget->get_name() ) {
		return $content;
	}

	if ( false === strpos( $content, 'smart-product-gallery-wrapper' ) ) {
		return $content;
	}

	if ( ! gueta_current_product() ) {
		return $content;
	}

	ob_start();
	gueta_render_product_gallery();
	$gallery = trim( (string) ob_get_clean() );

	return $gallery ? $gallery : $content;
}
add_filter( 'elementor/widget/render_content', 'gueta_replace_gallery_shortcode', 10, 2 );

/**
 * Point Elementor's generic Add to Cart widget at the product being viewed.
 *
 * The single template uses the widget that takes a fixed product id, so every
 * product page was adding that one product to the cart. Rewriting the setting
 * before the widget renders keeps its styling intact.
 *
 * @param \Elementor\Element_Base $widget Widget about to render.
 * @return void
 */
function gueta_retarget_add_to_cart_widget( $widget ) {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return;
	}

	if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'wc-add-to-cart' !== $widget->get_name() ) {
		return;
	}

	$product = gueta_current_product();

	if ( ! $product || ! method_exists( $widget, 'set_settings' ) ) {
		return;
	}

	$widget->set_settings( 'product_id', $product->get_id() );
}
add_action( 'elementor/frontend/widget/before_render', 'gueta_retarget_add_to_cart_widget' );

/**
 * Safety net for the above: if the rendered form still names another product,
 * replace it with the real add to cart form for this one.
 *
 * @param string                 $content Rendered widget HTML.
 * @param \Elementor\Widget_Base $widget  Widget instance.
 * @return string
 */
function gueta_correct_add_to_cart_markup( $content, $widget ) {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return $content;
	}

	if ( ! is_object( $widget ) || ! method_exists( $widget, 'get_name' ) || 'wc-add-to-cart' !== $widget->get_name() ) {
		return $content;
	}

	$product = gueta_current_product();

	if ( ! $product || ! preg_match( '/name=["\']add-to-cart["\'][^>]*?value=["\'](\d+)["\']/', $content, $matches ) ) {
		return $content;
	}

	if ( (int) $matches[1] === $product->get_id() ) {
		return $content;
	}

	ob_start();
	woocommerce_template_single_add_to_cart();
	$correct = trim( (string) ob_get_clean() );

	return $correct ? '<div class="gueta-atc">' . $correct . '</div>' : $content;
}
add_filter( 'elementor/widget/render_content', 'gueta_correct_add_to_cart_markup', 10, 2 );

/**
 * Reviews go straight after the single template, ahead of the footer.
 *
 * @return void
 */
function gueta_elementor_reviews() {
	if ( function_exists( 'is_product' ) && is_product() ) {
		gueta_current_product();
		gueta_render_reviews();
	}
}
add_action( 'elementor/theme/after_do_single', 'gueta_elementor_reviews' );

/**
 * Shortcodes for placing the pieces anywhere inside an Elementor template.
 *
 * @return void
 */
function gueta_product_shortcodes() {
	$capture = static function ( $callback ) {
		return static function () use ( $callback ) {
			if ( ! gueta_current_product() ) {
				return '';
			}

			ob_start();
			$callback();

			return (string) ob_get_clean();
		};
	};

	add_shortcode( 'gueta_gallery', $capture( 'gueta_render_product_gallery' ) );
	add_shortcode( 'gueta_accordion', $capture( 'gueta_render_product_accordion' ) );
	add_shortcode( 'gueta_reviews', $capture( 'gueta_render_reviews' ) );
}
add_action( 'init', 'gueta_product_shortcodes' );
