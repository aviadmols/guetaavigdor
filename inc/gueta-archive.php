<?php
/**
 * Category archive: filter sidebar, product grid, quick view.
 *
 * Rendered through the [gueta_archive] shortcode so it can be dropped into the
 * Elementor product archive template in place of the shop's own shortcode.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Products per page before "load more".
 */
const GUETA_ARCHIVE_PER_PAGE = 12;

/**
 * Assets for the archive.
 *
 * @return void
 */
function gueta_archive_assets() {
	if ( ! gueta_has_woocommerce() ) {
		return;
	}

	$uri = get_stylesheet_directory_uri();

	wp_register_style(
		'gueta-archive',
		$uri . '/assets/css/gueta-archive.css',
		[ 'gueta-header' ],
		gueta_asset_version( '/assets/css/gueta-archive.css' )
	);

	wp_register_script(
		'gueta-archive',
		$uri . '/assets/js/gueta-archive.js',
		[],
		gueta_asset_version( '/assets/js/gueta-archive.js' ),
		true
	);

	wp_localize_script(
		'gueta-archive',
		'guetaArchive',
		[
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'gueta_header' ),
		]
	);

	// The archive only exists where the shortcode runs, so enqueue on listings.
	if ( is_shop() || is_product_taxonomy() || is_search() ) {
		wp_enqueue_style( 'gueta-archive' );
		wp_enqueue_script( 'gueta-archive' );
	}
}
add_action( 'wp_enqueue_scripts', 'gueta_archive_assets', 28 );

/* -------------------------------------------------------------------------
 * Query
 * ---------------------------------------------------------------------- */

/**
 * Read the filter state from the request.
 *
 * @param array $overrides Values from the shortcode or an AJAX payload.
 * @return array
 */
function gueta_archive_state( $overrides = [] ) {
	$source = $overrides ? $overrides : $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	$list = static function ( $key ) use ( $source ) {
		if ( empty( $source[ $key ] ) ) {
			return [];
		}

		$raw = is_array( $source[ $key ] ) ? $source[ $key ] : explode( ',', (string) $source[ $key ] );

		return array_values( array_filter( array_map( 'sanitize_title', $raw ) ) );
	};

	$number = static function ( $key ) use ( $source ) {
		return isset( $source[ $key ] ) && '' !== $source[ $key ] ? (float) $source[ $key ] : null;
	};

	$allowed_sort = [ 'menu_order', 'popularity', 'rating', 'date', 'price', 'price-desc' ];
	$sort         = isset( $source['sort'] ) ? sanitize_key( $source['sort'] ) : 'menu_order';

	return [
		'category'   => isset( $source['category'] ) ? sanitize_title( $source['category'] ) : '',
		'categories' => $list( 'cat' ),
		'attributes' => gueta_archive_attribute_filters( $source ),
		'stock'      => $list( 'stock' ),
		'min_price'  => $number( 'min_price' ),
		'max_price'  => $number( 'max_price' ),
		'sort'       => in_array( $sort, $allowed_sort, true ) ? $sort : 'menu_order',
		'page'       => isset( $source['paged'] ) ? max( 1, (int) $source['paged'] ) : 1,
		'search'     => isset( $source['q'] ) ? sanitize_text_field( wp_unslash( $source['q'] ) ) : '',
	];
}

/**
 * Attribute selections, keyed by taxonomy.
 *
 * @param array $source Request data.
 * @return array<string,string[]>
 */
function gueta_archive_attribute_filters( $source ) {
	$filters = [];

	foreach ( $source as $key => $value ) {
		if ( 0 !== strpos( (string) $key, 'pa_' ) || '' === $value ) {
			continue;
		}

		$taxonomy = sanitize_key( $key );

		if ( ! taxonomy_exists( $taxonomy ) ) {
			continue;
		}

		$raw = is_array( $value ) ? $value : explode( ',', (string) $value );
		$terms = array_values( array_filter( array_map( 'sanitize_title', $raw ) ) );

		if ( $terms ) {
			$filters[ $taxonomy ] = $terms;
		}
	}

	return $filters;
}

/**
 * Build the product query for a filter state.
 *
 * @param array $state Filter state.
 * @return WP_Query
 */
function gueta_archive_query( $state ) {
	$args = [
		'post_type'           => 'product',
		'post_status'         => 'publish',
		'posts_per_page'      => GUETA_ARCHIVE_PER_PAGE,
		'paged'               => $state['page'],
		'ignore_sticky_posts' => true,
		'tax_query'           => [ 'relation' => 'AND' ], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_tax_query
		'meta_query'          => [], // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_meta_query
	];

	if ( $state['search'] ) {
		$args['s'] = $state['search'];
	}

	$args['tax_query'][] = [
		'taxonomy' => 'product_visibility',
		'field'    => 'name',
		'terms'    => [ 'exclude-from-catalog' ],
		'operator' => 'NOT IN',
	];

	// The category the archive is scoped to, narrowed by any chosen children.
	$categories = $state['categories'];

	if ( ! $categories && $state['category'] ) {
		$categories = [ $state['category'] ];
	}

	if ( $categories ) {
		$args['tax_query'][] = [
			'taxonomy' => 'product_cat',
			'field'    => 'slug',
			'terms'    => $categories,
		];
	}

	foreach ( $state['attributes'] as $taxonomy => $terms ) {
		$args['tax_query'][] = [
			'taxonomy' => $taxonomy,
			'field'    => 'slug',
			'terms'    => $terms,
		];
	}

	if ( in_array( 'onsale', $state['stock'], true ) ) {
		$args['post__in'] = array_merge( [ 0 ], wc_get_product_ids_on_sale() );
	}

	if ( in_array( 'instock', $state['stock'], true ) ) {
		$args['tax_query'][] = [
			'taxonomy' => 'product_visibility',
			'field'    => 'name',
			'terms'    => [ 'outofstock' ],
			'operator' => 'NOT IN',
		];
	}

	if ( null !== $state['min_price'] || null !== $state['max_price'] ) {
		$args['meta_query'][] = [
			'key'     => '_price',
			'value'   => [ $state['min_price'] ?? 0, $state['max_price'] ?? PHP_INT_MAX ],
			'type'    => 'DECIMAL(10,2)',
			'compare' => 'BETWEEN',
		];
	}

	switch ( $state['sort'] ) {
		case 'price':
			$args['orderby']  = 'meta_value_num';
			$args['meta_key'] = '_price'; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_meta_key
			$args['order']    = 'ASC';
			break;
		case 'price-desc':
			$args['orderby']  = 'meta_value_num';
			$args['meta_key'] = '_price'; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_meta_key
			$args['order']    = 'DESC';
			break;
		case 'date':
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
			break;
		case 'popularity':
			$args['orderby']  = 'meta_value_num';
			$args['meta_key'] = 'total_sales'; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_meta_key
			$args['order']    = 'DESC';
			break;
		case 'rating':
			$args['orderby']  = 'meta_value_num';
			$args['meta_key'] = '_wc_average_rating'; // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_meta_key
			$args['order']    = 'DESC';
			break;
		default:
			$args['orderby'] = 'menu_order title';
			$args['order']   = 'ASC';
	}

	return new WP_Query( $args );
}

/* -------------------------------------------------------------------------
 * Shortcode
 * ---------------------------------------------------------------------- */

/**
 * Register the archive shortcode.
 *
 * @return void
 */
function gueta_register_archive_shortcode() {
	add_shortcode( 'gueta_archive', 'gueta_render_archive' );
}
add_action( 'init', 'gueta_register_archive_shortcode' );

/**
 * Render the whole archive.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function gueta_render_archive( $atts = [] ) {
	if ( ! gueta_has_woocommerce() ) {
		return '';
	}

	// Inside a shortcode the assets may not have been enqueued by the page check.
	wp_enqueue_style( 'gueta-archive' );
	wp_enqueue_script( 'gueta-archive' );

	$atts = shortcode_atts(
		[
			'category' => '',
			'columns'  => 3,
		],
		$atts,
		'gueta_archive'
	);

	$term = gueta_archive_term( $atts['category'] );

	$state             = gueta_archive_state();
	$state['category'] = $term ? $term->slug : '';

	$query = gueta_archive_query( $state );

	ob_start();
	?>
	<div
		class="gueta-archive"
		data-archive
		data-columns="<?php echo (int) $atts['columns']; ?>"
		data-category="<?php echo esc_attr( $state['category'] ); ?>"
	>
		<?php gueta_render_archive_chips( $term ); ?>

		<div class="gueta-archive__toolbar">
			<button type="button" class="gueta-archive__filter-button" data-archive-filters-open>
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M6 12h12M10 18h4"></path></svg>
				<span>סינון</span>
				<span class="gueta-archive__filter-count" data-archive-active-count hidden>0</span>
			</button>

			<button type="button" class="gueta-archive__clear" data-archive-reset hidden>
				<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"></path></svg>
				<span>ניקוי הסינון</span>
			</button>

			<label class="gueta-archive__compare-toggle">
				<input type="checkbox" data-archive-compare-toggle>
				<span class="gueta-archive__compare-text">השוואה</span>
				<span class="gueta-archive__compare-track" aria-hidden="true"><span class="gueta-archive__compare-thumb"></span></span>
			</label>

			<span class="gueta-archive__count" data-archive-count>
				<?php echo esc_html( sprintf( '%s פריטים', number_format_i18n( (int) $query->found_posts ) ) ); ?>
			</span>

			<label class="gueta-archive__sort">
				<span class="screen-reader-text">מיון</span>
				<select data-archive-sort>
					<?php
					$sorts = [
						'menu_order' => 'מיון ברירת מחדל',
						'popularity' => 'הנמכרים ביותר',
						'date'       => 'החדשים ביותר',
						'price'      => 'מחיר: מהנמוך לגבוה',
						'price-desc' => 'מחיר: מהגבוה לנמוך',
						'rating'     => 'הדירוג הגבוה ביותר',
					];

					foreach ( $sorts as $value => $label ) :
						?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $state['sort'], $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
		</div>

		<div class="gueta-archive__layout">
			<aside class="gueta-archive__sidebar" data-archive-sidebar>
				<div class="gueta-archive__sidebar-head">
					<h2>סינון</h2>
					<button type="button" class="gueta-icon-button" data-archive-filters-close aria-label="סגירת הסינון">
						<?php echo gueta_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</button>
				</div>

				<form class="gueta-archive__filters" data-archive-form>
					<?php gueta_render_archive_filters( $term, $state ); ?>
				</form>

				<div class="gueta-archive__sidebar-foot">
					<button type="button" class="gueta-archive__reset" data-archive-reset>ניקוי הסינון</button>
					<button type="button" class="gueta-archive__apply" data-archive-filters-close>הצגת התוצאות</button>
				</div>
			</aside>

			<div class="gueta-archive__main">
				<div class="gueta-archive__grid" data-archive-grid style="--gueta-archive-columns:<?php echo (int) $atts['columns']; ?>">
					<?php gueta_render_archive_cards( $query ); ?>
				</div>

				<?php if ( $query->max_num_pages > 1 ) : ?>
					<div class="gueta-archive__more">
						<button type="button" class="gueta-archive__more-button" data-archive-more data-page="1" data-max="<?php echo (int) $query->max_num_pages; ?>">
							הצגת מוצרים נוספים
						</button>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<div class="gueta-archive__backdrop" data-archive-filters-close></div>
	</div>
	<?php

	wp_reset_postdata();

	return (string) ob_get_clean();
}

/**
 * The category the archive is showing.
 *
 * @param string $slug Explicit slug from the shortcode.
 * @return WP_Term|null
 */
function gueta_archive_term( $slug = '' ) {
	if ( $slug ) {
		$term = get_term_by( 'slug', $slug, 'product_cat' );

		return ( $term && ! is_wp_error( $term ) ) ? $term : null;
	}

	if ( is_product_taxonomy() ) {
		$term = get_queried_object();

		if ( $term instanceof WP_Term ) {
			return $term;
		}
	}

	return null;
}

/* -------------------------------------------------------------------------
 * Sub category chips
 * ---------------------------------------------------------------------- */

/**
 * The row of sub categories above the toolbar.
 *
 * @param WP_Term|null $term Current category.
 * @return void
 */
function gueta_render_archive_chips( $term ) {
	$children = get_terms(
		[
			'taxonomy'   => 'product_cat',
			'parent'     => $term ? $term->term_id : 0,
			'hide_empty' => true,
		]
	);

	if ( is_wp_error( $children ) || count( $children ) < 2 ) {
		return;
	}

	$children = gueta_sort_terms_by_count( $children, 12 );
	?>
	<ul class="gueta-archive__chips">
		<?php
		foreach ( $children as $child ) :
			$link = get_term_link( $child );

			if ( is_wp_error( $link ) ) {
				continue;
			}

			$thumbnail_id = (int) get_term_meta( $child->term_id, 'thumbnail_id', true );
			?>
			<li>
				<a class="gueta-archive__chip" href="<?php echo esc_url( $link ); ?>">
					<span class="gueta-archive__chip-media">
						<?php if ( $thumbnail_id ) : ?>
							<?php echo wp_get_attachment_image( $thumbnail_id, 'woocommerce_thumbnail', false, [ 'alt' => '', 'loading' => 'lazy' ] ); ?>
						<?php endif; ?>
					</span>
					<span class="gueta-archive__chip-name"><?php echo esc_html( $child->name ); ?></span>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
	<?php
}

/* -------------------------------------------------------------------------
 * Filters
 * ---------------------------------------------------------------------- */

/**
 * Render every filter group.
 *
 * @param WP_Term|null $term  Current category.
 * @param array        $state Filter state.
 * @return void
 */
function gueta_render_archive_filters( $term, $state ) {
	$children = get_terms(
		[
			'taxonomy'   => 'product_cat',
			'parent'     => $term ? $term->term_id : 0,
			'hide_empty' => true,
		]
	);

	if ( ! is_wp_error( $children ) && $children ) {
		gueta_render_filter_group(
			'קטגוריות',
			'cat',
			array_map(
				static function ( $child ) {
					return [
						'value' => $child->slug,
						'label' => $child->name,
						'count' => gueta_term_product_count( $child ),
					];
				},
				gueta_sort_terms_by_count( $children )
			),
			$state['categories'],
			true
		);
	}

	gueta_render_price_filter( $state );

	gueta_render_filter_group(
		'זמינות',
		'stock',
		[
			[
				'value' => 'instock',
				'label' => 'במלאי בלבד',
				'count' => null,
			],
			[
				'value' => 'onsale',
				'label' => 'במבצע',
				'count' => null,
			],
		],
		$state['stock'],
		true
	);

	$labels = gueta_archive_attribute_taxonomies();

	// Only the attributes the products in this category actually carry.
	foreach ( gueta_archive_facets( $term ) as $taxonomy => $options ) {
		if ( empty( $labels[ $taxonomy ] ) || count( $options ) < 2 ) {
			continue;
		}

		gueta_render_filter_group(
			$labels[ $taxonomy ],
			$taxonomy,
			array_values( $options ),
			$state['attributes'][ $taxonomy ] ?? [],
			false
		);
	}
}

/**
 * Which attribute terms the products in a category actually use, with counts.
 *
 * Offering every attribute taxonomy in the shop meant a category of two hand
 * tools listed filters for volume and thickness, so the facets are derived
 * from the products on show instead.
 *
 * @param WP_Term|null $term Current category.
 * @return array<string,array>
 */
function gueta_archive_facets( $term ) {
	$cache_key = 'gueta_facets_' . GUETA_CACHE_VERSION . '_' . ( $term ? (int) $term->term_id : 0 );
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$taxonomies = array_keys( gueta_archive_attribute_taxonomies() );
	$facets     = [];

	if ( ! $taxonomies ) {
		set_transient( $cache_key, $facets, 6 * HOUR_IN_SECONDS );

		return $facets;
	}

	$args = [
		'post_type'              => 'product',
		'post_status'            => 'publish',
		'posts_per_page'         => -1,
		'fields'                 => 'ids',
		'no_found_rows'          => true,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	];

	if ( $term ) {
		$args['tax_query'] = [ // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_tax_query
			[
				'taxonomy' => 'product_cat',
				'field'    => 'term_id',
				'terms'    => $term->term_id,
			],
		];
	}

	$ids = get_posts( $args );

	if ( $ids ) {
		$objects = wp_get_object_terms( $ids, $taxonomies, [ 'fields' => 'all_with_object_id' ] );

		if ( ! is_wp_error( $objects ) ) {
			foreach ( $objects as $object ) {
				if ( ! isset( $facets[ $object->taxonomy ][ $object->slug ] ) ) {
					$facets[ $object->taxonomy ][ $object->slug ] = [
						'value' => $object->slug,
						'label' => $object->name,
						'count' => 0,
					];
				}

				++$facets[ $object->taxonomy ][ $object->slug ]['count'];
			}
		}
	}

	foreach ( $facets as $taxonomy => $options ) {
		uasort(
			$options,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		$facets[ $taxonomy ] = $options;
	}

	set_transient( $cache_key, $facets, 6 * HOUR_IN_SECONDS );

	return $facets;
}

/**
 * Drop the facet caches a saved product could have changed.
 *
 * Keyed per category, so only that product's categories and the shop wide
 * entry need clearing.
 *
 * @param int $post_id Product id.
 * @return void
 */
function gueta_flush_facets_for_product( $post_id ) {
	if ( 'product' !== get_post_type( $post_id ) ) {
		return;
	}

	delete_transient( 'gueta_facets_' . GUETA_CACHE_VERSION . '_0' );

	foreach ( wp_get_post_terms( $post_id, 'product_cat', [ 'fields' => 'ids' ] ) as $term_id ) {
		foreach ( array_merge( [ $term_id ], get_ancestors( $term_id, 'product_cat', 'taxonomy' ) ) as $id ) {
			delete_transient( 'gueta_facets_' . GUETA_CACHE_VERSION . '_' . (int) $id );
		}
	}
}
add_action( 'save_post_product', 'gueta_flush_facets_for_product' );
add_action( 'woocommerce_update_product', 'gueta_flush_facets_for_product' );

/**
 * Attribute taxonomies worth offering as filters.
 *
 * @return array<string,string>
 */
function gueta_archive_attribute_taxonomies() {
	if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
		return [];
	}

	$taxonomies = [];

	foreach ( wc_get_attribute_taxonomies() as $attribute ) {
		$taxonomy = wc_attribute_taxonomy_name( $attribute->attribute_name );

		if ( taxonomy_exists( $taxonomy ) ) {
			$taxonomies[ $taxonomy ] = $attribute->attribute_label ?: $attribute->attribute_name;
		}
	}

	return $taxonomies;
}

/**
 * One collapsible group of checkboxes.
 *
 * @param string $title    Group title.
 * @param string $key      Query key.
 * @param array  $options  Options with value, label and count.
 * @param array  $selected Selected values.
 * @param bool   $open     Whether the group starts open.
 * @return void
 */
function gueta_render_filter_group( $title, $key, $options, $selected, $open = false ) {
	if ( ! $options ) {
		return;
	}
	?>
	<details class="gueta-filter" <?php echo $open || $selected ? 'open' : ''; ?>>
		<summary class="gueta-filter__summary">
			<span class="gueta-filter__title"><?php echo esc_html( $title ); ?></span>
			<span class="gueta-filter__chevron" aria-hidden="true">
				<svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"></path></svg>
			</span>
		</summary>
		<div class="gueta-filter__body">
			<?php foreach ( $options as $option ) : ?>
				<label class="gueta-filter__option">
					<input
						type="checkbox"
						name="<?php echo esc_attr( $key ); ?>"
						value="<?php echo esc_attr( $option['value'] ); ?>"
						<?php checked( in_array( $option['value'], $selected, true ) ); ?>
					>
					<span class="gueta-filter__check" aria-hidden="true">
						<svg viewBox="0 0 24 24"><path d="m5 12.5 4.5 4.5L19 7.5"></path></svg>
					</span>
					<span class="gueta-filter__label"><?php echo esc_html( $option['label'] ); ?></span>
					<?php if ( null !== $option['count'] ) : ?>
						<span class="gueta-filter__count"><?php echo esc_html( number_format_i18n( $option['count'] ) ); ?></span>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</div>
	</details>
	<?php
}

/**
 * The price range inputs.
 *
 * @param array $state Filter state.
 * @return void
 */
function gueta_render_price_filter( $state ) {
	?>
	<details class="gueta-filter" open>
		<summary class="gueta-filter__summary">
			<span class="gueta-filter__title">מחיר</span>
			<span class="gueta-filter__chevron" aria-hidden="true">
				<svg viewBox="0 0 24 24"><path d="m6 9 6 6 6-6"></path></svg>
			</span>
		</summary>
		<div class="gueta-filter__body gueta-filter__body--price">
			<label>
				<span>מ־</span>
				<input type="number" name="min_price" inputmode="numeric" min="0" placeholder="0" value="<?php echo null !== $state['min_price'] ? esc_attr( (string) $state['min_price'] ) : ''; ?>">
			</label>
			<label>
				<span>עד</span>
				<input type="number" name="max_price" inputmode="numeric" min="0" placeholder="∞" value="<?php echo null !== $state['max_price'] ? esc_attr( (string) $state['max_price'] ) : ''; ?>">
			</label>
		</div>
	</details>
	<?php
}

/* -------------------------------------------------------------------------
 * Cards
 * ---------------------------------------------------------------------- */

/**
 * Render the cards for a query.
 *
 * @param WP_Query $query Product query.
 * @return void
 */
function gueta_render_archive_cards( $query ) {
	if ( ! $query->have_posts() ) {
		?>
		<p class="gueta-archive__empty">לא נמצאו מוצרים שמתאימים לסינון.</p>
		<?php
		return;
	}

	while ( $query->have_posts() ) {
		$query->the_post();

		$product = wc_get_product( get_the_ID() );

		if ( $product ) {
			gueta_render_archive_card( $product );
		}
	}

	wp_reset_postdata();
}

/**
 * One product card.
 *
 * @param WC_Product $product Product.
 * @return void
 */
function gueta_render_archive_card( $product ) {
	$id      = $product->get_id();
	$link    = $product->get_permalink();
	$on_sale = $product->is_on_sale();
	?>
	<article class="gueta-card" data-compare-card="<?php echo (int) $id; ?>" data-product="<?php echo (int) $id; ?>">
		<div class="gueta-card__media">
			<a class="gueta-card__link" href="<?php echo esc_url( $link ); ?>" aria-label="<?php echo esc_attr( $product->get_name() ); ?>">
				<?php
				echo $product->get_image(
					'woocommerce_thumbnail',
					[
						'class'   => 'gueta-card__image',
						'loading' => 'lazy',
					]
				); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</a>

			<?php if ( $on_sale || ! $product->is_in_stock() ) : ?>
				<div class="gueta-card__badges">
					<?php if ( ! $product->is_in_stock() ) : ?>
						<span class="gueta-card__badge gueta-card__badge--out">אזל מהמלאי</span>
					<?php endif; ?>
					<?php if ( $on_sale ) : ?>
						<span class="gueta-card__badge"><?php echo esc_html( gueta_card_discount_label( $product ) ); ?></span>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<label class="gueta-compare-switch gueta-card__compare" data-compare-switch>
				<input type="checkbox" class="gueta-compare-switch__input" value="<?php echo (int) $id; ?>" data-compare-toggle>
				<span class="gueta-compare-switch__text">השוואה</span>
				<span class="gueta-compare-switch__track" aria-hidden="true"><span class="gueta-compare-switch__thumb"></span></span>
			</label>
		</div>

		<div class="gueta-card__info">
			<h3 class="gueta-card__title">
				<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
			</h3>
			<div class="gueta-card__price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>

		</div>

		<button type="button" class="gueta-card__cta" data-quickview="<?php echo (int) $id; ?>">
			<span>לפרטים ורכישה</span>
			<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 8 8 16m0 0h6m-6 0v-6"></path></svg>
		</button>
	</article>
	<?php
}

/**
 * "25% הנחה" when the saving is a clean percentage, otherwise just "מבצע".
 *
 * @param WC_Product $product Product.
 * @return string
 */
function gueta_card_discount_label( $product ) {
	$regular = (float) $product->get_regular_price();
	$sale    = (float) $product->get_price();

	if ( $regular > 0 && $sale > 0 && $sale < $regular ) {
		return sprintf( '%d%% הנחה', (int) round( ( ( $regular - $sale ) / $regular ) * 100 ) );
	}

	return 'מבצע';
}

/* -------------------------------------------------------------------------
 * AJAX
 * ---------------------------------------------------------------------- */

/**
 * Return a filtered grid.
 *
 * @return void
 */
function gueta_ajax_archive() {
	check_ajax_referer( 'gueta_header', 'nonce' );

	if ( ! gueta_has_woocommerce() ) {
		wp_send_json_error( [ 'message' => 'החנות אינה זמינה כרגע.' ], 400 );
	}

	$payload = isset( $_POST['state'] ) ? json_decode( sanitize_textarea_field( wp_unslash( $_POST['state'] ) ), true ) : [];
	$state   = gueta_archive_state( is_array( $payload ) ? $payload : [] );
	$query   = gueta_archive_query( $state );

	ob_start();
	gueta_render_archive_cards( $query );
	$html = (string) ob_get_clean();

	wp_send_json_success(
		[
			'html'    => $html,
			'total'   => (int) $query->found_posts,
			'label'   => sprintf( '%s פריטים', number_format_i18n( (int) $query->found_posts ) ),
			'pages'   => (int) $query->max_num_pages,
			'append'  => $state['page'] > 1,
		]
	);
}
add_action( 'wp_ajax_gueta_archive', 'gueta_ajax_archive' );
add_action( 'wp_ajax_nopriv_gueta_archive', 'gueta_ajax_archive' );

/**
 * Return the quick view panel for a product.
 *
 * @return void
 */
function gueta_ajax_quickview() {
	check_ajax_referer( 'gueta_header', 'nonce' );

	$id      = isset( $_POST['product'] ) ? absint( wp_unslash( $_POST['product'] ) ) : 0;
	$product = $id && gueta_has_woocommerce() ? wc_get_product( $id ) : null;

	if ( ! $product || ! $product->is_visible() ) {
		wp_send_json_error( [ 'message' => 'המוצר אינו זמין.' ], 404 );
	}

	wp_send_json_success( [ 'html' => gueta_render_quickview( $product ) ] );
}
add_action( 'wp_ajax_gueta_quickview', 'gueta_ajax_quickview' );
add_action( 'wp_ajax_nopriv_gueta_quickview', 'gueta_ajax_quickview' );

/**
 * The quick view body: gallery, price and the real add to cart form.
 *
 * @param WC_Product $product Product.
 * @return string
 */
function gueta_render_quickview( $product ) {
	global $post;

	$previous = $post;
	$post     = get_post( $product->get_id() ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $post );

	$GLOBALS['product'] = $product; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

	ob_start();
	?>
	<div class="gueta-quickview">
		<div class="gueta-quickview__media">
			<?php gueta_render_product_gallery(); ?>
		</div>

		<div class="gueta-quickview__info">
			<h2 class="gueta-quickview__title"><?php echo esc_html( $product->get_name() ); ?></h2>

			<?php gueta_render_rating_link(); ?>

			<?php
			// Adding from here stays in the modal, which is the whole point of
			// a quick view; the product page keeps its ordinary post.
			gueta_render_buy_box( $product, [ 'price' => 'on', 'ajax' => true ] );
			?>

			<?php
			// Every panel closed: the description already leads the panel list,
			// so nothing here needs to be expanded on open.
			gueta_render_product_accordion( false );
			?>

			<a class="gueta-quickview__full" href="<?php echo esc_url( $product->get_permalink() ); ?>">
				לעמוד המוצר המלא
			</a>
		</div>
	</div>
	<?php

	$post = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	wp_reset_postdata();

	return (string) ob_get_clean();
}

/**
 * The quick view shell.
 *
 * @return void
 */
function gueta_render_quickview_shell() {
	if ( ! gueta_has_woocommerce() ) {
		return;
	}
	?>
	<div class="gueta-quickview-modal" data-quickview-modal hidden>
		<div class="gueta-quickview-modal__backdrop" data-quickview-close></div>
		<div class="gueta-quickview-modal__panel" role="dialog" aria-modal="true" aria-label="הצצה מהירה">
			<button type="button" class="gueta-icon-button gueta-quickview-modal__close" data-quickview-close aria-label="סגירה">
				<?php echo gueta_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
			<div class="gueta-quickview-modal__body" data-quickview-body></div>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'gueta_render_quickview_shell' );
