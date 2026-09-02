<?php
/**
 * Predictive header search: products in the main column, matching categories,
 * tags and articles in the side column.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Products listed inside the suggestion panel.
 */
const GUETA_SEARCH_PRODUCT_LIMIT = 6;

/**
 * Entries listed per side column section.
 */
const GUETA_SEARCH_TERM_LIMIT = 6;

/**
 * Answer a suggestion request with the rendered panel.
 *
 * @return void
 */
function gueta_ajax_search() {
	check_ajax_referer( 'gueta_header', 'nonce' );

	$term = isset( $_POST['term'] ) ? sanitize_text_field( wp_unslash( $_POST['term'] ) ) : '';

	if ( mb_strlen( $term ) < 2 ) {
		wp_send_json_success(
			[
				'html'  => '',
				'total' => 0,
			]
		);
	}

	$products = gueta_search_products( $term );

	wp_send_json_success(
		[
			'html'  => gueta_render_search_panel( $term, $products ),
			'total' => $products['total'],
		]
	);
}
add_action( 'wp_ajax_gueta_header_search', 'gueta_ajax_search' );
add_action( 'wp_ajax_nopriv_gueta_header_search', 'gueta_ajax_search' );

/**
 * Query the shop, falling back to posts and pages when WooCommerce is absent.
 *
 * @param string $term Search term.
 * @return array{ids:int[],total:int}
 */
function gueta_search_products( $term ) {
	$post_type = gueta_has_woocommerce() ? 'product' : [ 'post', 'page' ];

	$args = [
		'post_type'           => $post_type,
		'post_status'         => 'publish',
		's'                   => $term,
		'posts_per_page'      => GUETA_SEARCH_PRODUCT_LIMIT,
		'ignore_sticky_posts' => true,
		'fields'              => 'ids',
	];

	if ( gueta_has_woocommerce() ) {
		$args['tax_query'] = [
			[
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => [ 'exclude-from-search' ],
				'operator' => 'NOT IN',
			],
		];
	}

	$query = new WP_Query( $args );

	return [
		'ids'   => $query->posts,
		'total' => (int) $query->found_posts,
	];
}

/**
 * Terms of a taxonomy whose name matches the search term.
 *
 * @param string $taxonomy Taxonomy name.
 * @param string $term     Search term.
 * @return WP_Term[]
 */
function gueta_search_terms( $taxonomy, $term ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return [];
	}

	$terms = get_terms(
		[
			'taxonomy'   => $taxonomy,
			'name__like' => $term,
			'hide_empty' => true,
		]
	);

	return is_wp_error( $terms ) ? [] : gueta_sort_terms_by_count( $terms, GUETA_SEARCH_TERM_LIMIT );
}

/**
 * Articles and pages matching the search term.
 *
 * @param string $term Search term.
 * @return int[]
 */
function gueta_search_articles( $term ) {
	if ( ! gueta_has_woocommerce() ) {
		return [];
	}

	$query = new WP_Query(
		[
			'post_type'           => [ 'post', 'page' ],
			'post_status'         => 'publish',
			's'                   => $term,
			'posts_per_page'      => 3,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'fields'              => 'ids',
		]
	);

	return $query->posts;
}

/**
 * The breadcrumb of a category, so "עץ אורן" reads as "עצים › עץ אורן".
 *
 * @param WP_Term $term Product category.
 * @return string
 */
function gueta_term_path( $term ) {
	$ancestors = get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' );

	if ( ! $ancestors ) {
		return '';
	}

	$names = [];

	foreach ( array_reverse( $ancestors ) as $ancestor_id ) {
		$ancestor = get_term( $ancestor_id, $term->taxonomy );

		if ( $ancestor && ! is_wp_error( $ancestor ) ) {
			$names[] = $ancestor->name;
		}
	}

	return implode( ' › ', $names );
}

/**
 * Render the suggestion panel.
 *
 * @param string $term     Search term.
 * @param array  $products Product ids and the total match count.
 * @return string
 */
function gueta_render_search_panel( $term, $products ) {
	$categories = gueta_search_terms( 'product_cat', $term );
	$tags       = gueta_search_terms( 'product_tag', $term );
	$articles   = gueta_search_articles( $term );
	$has_side   = $categories || $tags || $articles;

	ob_start();
	?>
	<div class="gueta-suggest__layout<?php echo $has_side ? '' : ' is-single'; ?>">
		<?php if ( $has_side ) : ?>
			<aside class="gueta-suggest__side">
				<?php gueta_render_suggest_terms( 'קטגוריות מתאימות', $categories, true ); ?>
				<?php gueta_render_suggest_terms( 'תגיות', $tags, false ); ?>
				<?php gueta_render_suggest_articles( $articles ); ?>
			</aside>
		<?php endif; ?>

		<div class="gueta-suggest__main">
			<?php if ( $products['ids'] ) : ?>
				<p class="gueta-suggest__heading"><?php echo gueta_has_woocommerce() ? 'מוצרים' : 'תוצאות'; ?></p>
				<div class="gueta-suggest__products">
					<?php foreach ( $products['ids'] as $product_id ) : ?>
						<?php gueta_render_suggest_product( $product_id ); ?>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<div class="gueta-suggest__empty">
					<p class="gueta-suggest__empty-title">לא נמצאו תוצאות עבור &laquo;<?php echo esc_html( $term ); ?>&raquo;</p>
					<p class="gueta-suggest__empty-text">נסו מילה אחרת, או עיינו בקטגוריות שלנו.</p>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $products['total'] > 0 ) : ?>
		<a class="gueta-suggest__all" href="<?php echo esc_url( gueta_search_results_url( $term ) ); ?>">
			<span>הצג את כל <?php echo esc_html( number_format_i18n( $products['total'] ) ); ?> התוצאות</span>
			<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M14 5 7 12l7 7"></path></svg>
		</a>
	<?php endif; ?>
	<?php

	return (string) ob_get_clean();
}

/**
 * Render one side column section built from taxonomy terms.
 *
 * @param string    $title     Section title.
 * @param WP_Term[] $terms     Terms.
 * @param bool      $with_path Whether to append the category breadcrumb.
 * @return void
 */
function gueta_render_suggest_terms( $title, $terms, $with_path ) {
	if ( ! $terms ) {
		return;
	}
	?>
	<div class="gueta-suggest__group">
		<p class="gueta-suggest__heading"><?php echo esc_html( $title ); ?></p>
		<ul class="gueta-suggest__list">
			<?php
			foreach ( $terms as $term ) :
				$link = get_term_link( $term );

				if ( is_wp_error( $link ) ) {
					continue;
				}

				$path = $with_path ? gueta_term_path( $term ) : '';
				?>
				<li>
					<a href="<?php echo esc_url( $link ); ?>">
						<span class="gueta-suggest__term"><?php echo esc_html( $term->name ); ?></span>
						<?php if ( $path ) : ?>
							<span class="gueta-suggest__path"><?php echo esc_html( $path ); ?></span>
						<?php endif; ?>
						<span class="gueta-suggest__count"><?php echo esc_html( number_format_i18n( gueta_term_product_count( $term ) ) ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
}

/**
 * Render the articles section of the side column.
 *
 * @param int[] $post_ids Post ids.
 * @return void
 */
function gueta_render_suggest_articles( $post_ids ) {
	if ( ! $post_ids ) {
		return;
	}
	?>
	<div class="gueta-suggest__group">
		<p class="gueta-suggest__heading">מידע ומאמרים</p>
		<ul class="gueta-suggest__list">
			<?php foreach ( $post_ids as $post_id ) : ?>
				<li>
					<a href="<?php echo esc_url( (string) get_permalink( $post_id ) ); ?>">
						<span class="gueta-suggest__term"><?php echo esc_html( get_the_title( $post_id ) ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
	<?php
}

/**
 * Render one product suggestion.
 *
 * @param int $post_id Product or post id.
 * @return void
 */
function gueta_render_suggest_product( $post_id ) {
	$product = gueta_has_woocommerce() && function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
	$on_sale = $product && $product->is_on_sale();
	$size    = $product ? 'woocommerce_thumbnail' : 'thumbnail';
	?>
	<a class="gueta-suggest__product" href="<?php echo esc_url( (string) get_permalink( $post_id ) ); ?>">
		<span class="gueta-suggest__thumb">
			<?php if ( has_post_thumbnail( $post_id ) ) : ?>
				<?php echo get_the_post_thumbnail( $post_id, $size, [ 'loading' => 'lazy', 'alt' => '' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php else : ?>
				<span class="gueta-suggest__thumb-empty" aria-hidden="true"></span>
			<?php endif; ?>
			<?php if ( $on_sale ) : ?>
				<span class="gueta-suggest__badge">מבצע</span>
			<?php endif; ?>
		</span>
		<span class="gueta-suggest__body">
			<span class="gueta-suggest__title"><?php echo esc_html( get_the_title( $post_id ) ); ?></span>
			<?php if ( $product ) : ?>
				<span class="gueta-suggest__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></span>
			<?php endif; ?>
		</span>
	</a>
	<?php
}

/**
 * URL of the full results page for a term.
 *
 * @param string $term Search term.
 * @return string
 */
function gueta_search_results_url( $term ) {
	$url = add_query_arg( 's', rawurlencode( $term ), home_url( '/' ) );

	if ( gueta_has_woocommerce() ) {
		$url = add_query_arg( 'post_type', 'product', $url );
	}

	return $url;
}
