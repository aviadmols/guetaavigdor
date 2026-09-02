<?php
/**
 * Category slider that sits directly under the header.
 *
 * Card design follows the site's existing custom-cat-grid; the sliding
 * behaviour follows the reference storefront's collection list slider.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Top level product categories that have an image, busiest first.
 *
 * Requiring an image also drops WooCommerce's "uncategorized" bucket, which is
 * never something a shopper wants to browse.
 *
 * @return array
 */
function gueta_category_cards() {
	$cache_key = 'gueta_category_cards_' . GUETA_CACHE_VERSION . '_' . get_locale();
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$cards = [];

	foreach ( gueta_top_categories() as $term ) {
		$thumbnail_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );
		$link         = get_term_link( $term );

		if ( ! $thumbnail_id || is_wp_error( $link ) ) {
			continue;
		}

		$cards[] = [
			'id'    => $term->term_id,
			'name'  => $term->name,
			'url'   => $link,
			'image' => $thumbnail_id,
			'text'  => gueta_category_card_text( $term ),
		];
	}

	set_transient( $cache_key, $cards, DAY_IN_SECONDS );

	return $cards;
}

/**
 * The line revealed on hover: the category description, or its size.
 *
 * @param WP_Term $term Product category.
 * @return string
 */
function gueta_category_card_text( $term ) {
	$description = trim( wp_strip_all_tags( $term->description ) );

	if ( $description ) {
		return wp_html_excerpt( $description, 130, '…' );
	}

	$count = gueta_term_product_count( $term );

	return sprintf(
		/* translators: %s: number of products. */
		_n( '%s מוצר בקטגוריה', '%s מוצרים בקטגוריה', $count, 'hello-elementor-child' ),
		number_format_i18n( $count )
	);
}

/**
 * Drop the cached cards whenever a product category changes.
 *
 * @return void
 */
function gueta_flush_category_cards() {
	delete_transient( 'gueta_category_cards_' . GUETA_CACHE_VERSION . '_' . get_locale() );
}
add_action( 'created_product_cat', 'gueta_flush_category_cards' );
add_action( 'edited_product_cat', 'gueta_flush_category_cards' );
add_action( 'delete_product_cat', 'gueta_flush_category_cards' );
add_action( 'switch_theme', 'gueta_flush_category_cards' );

/**
 * Whether the strip should render on the current request: the home page only.
 *
 * @return bool
 */
function gueta_show_category_slider() {
	return (bool) apply_filters( 'gueta_show_category_slider', is_front_page() );
}

/**
 * Flag the body when the strip renders, so the page below can adapt to it.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function gueta_category_slider_body_class( $classes ) {
	if ( gueta_has_woocommerce() && gueta_show_category_slider() && count( gueta_category_cards() ) > 1 ) {
		$classes[] = 'gueta-has-cats';
	}

	return $classes;
}
add_filter( 'body_class', 'gueta_category_slider_body_class' );

/**
 * Render the slider.
 *
 * @return void
 */
function gueta_render_category_slider() {
	if ( ! gueta_has_woocommerce() || ! gueta_show_category_slider() ) {
		return;
	}

	$cards = gueta_category_cards();

	if ( count( $cards ) < 2 ) {
		return;
	}
	?>
	<section class="gueta-cats" aria-label="קטגוריות" data-cats>
		<div class="gueta-cats__inner">
			<button type="button" class="gueta-cats__nav gueta-cats__nav--prev" data-cats-prev aria-label="הקטגוריות הקודמות">
				<?php echo gueta_icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>

			<ul class="gueta-cats__track" data-cats-track>
				<?php foreach ( $cards as $card ) : ?>
					<li class="gueta-cats__item">
						<a class="gueta-cat" href="<?php echo esc_url( $card['url'] ); ?>">
							<span class="gueta-cat__media">
								<?php
								echo wp_get_attachment_image(
									$card['image'],
									'medium_large',
									false,
									[
										'class'   => 'gueta-cat__image',
										'alt'     => '',
										'loading' => 'lazy',
									]
								);
								?>
							</span>
							<span class="gueta-cat__title"><?php echo esc_html( $card['name'] ); ?></span>
							<span class="gueta-cat__arrow" aria-hidden="true">
								<svg viewBox="0 0 24 24"><path d="M14 5 7 12l7 7"></path></svg>
							</span>
							<?php if ( $card['text'] ) : ?>
								<span class="gueta-cat__text"><?php echo esc_html( $card['text'] ); ?></span>
							<?php endif; ?>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>

			<button type="button" class="gueta-cats__nav gueta-cats__nav--next" data-cats-next aria-label="הקטגוריות הבאות">
				<?php echo gueta_icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</div>
	</section>
	<?php
}
