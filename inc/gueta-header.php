<?php
/**
 * Storefront header: logo, mega navigation, AJAX search and the cart drawer.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Number of top level product categories used when no menu is assigned.
 */
const GUETA_NAV_MAX_ITEMS = 8;

/**
 * Maximum number of columns rendered inside a single mega menu panel.
 */
const GUETA_NAV_MAX_COLUMNS = 7;

/**
 * Bump to invalidate every cached navigation structure on deploy.
 */
const GUETA_CACHE_VERSION = '2';

/* -------------------------------------------------------------------------
 * Theme setup
 * ---------------------------------------------------------------------- */

/**
 * Register the navigation locations and the logo the header renders.
 *
 * @return void
 */
function gueta_header_setup() {
	register_nav_menus(
		[
			'primary' => 'Primary navigation',
			'topbar'  => 'Top bar links',
		]
	);

	add_theme_support(
		'custom-logo',
		[
			'height'      => 160,
			'width'       => 600,
			'flex-height' => true,
			'flex-width'  => true,
		]
	);
}
add_action( 'after_setup_theme', 'gueta_header_setup' );

/**
 * Whether WooCommerce is active and booted.
 *
 * @return bool
 */
function gueta_has_woocommerce() {
	return function_exists( 'WC' ) && class_exists( 'WooCommerce' );
}

/* -------------------------------------------------------------------------
 * Category counts and ordering
 * ---------------------------------------------------------------------- */

/**
 * The product count WooCommerce itself shows for a term.
 *
 * WooCommerce keeps its real count in product_count_* term meta and only swaps
 * it into $term->count on non-AJAX front end queries, while the term_taxonomy
 * column that ORDER BY count reads is stale on this shop. Read the meta.
 *
 * @param WP_Term $term Term.
 * @return int
 */
function gueta_term_product_count( $term ) {
	$meta = get_term_meta( $term->term_id, 'product_count_' . $term->taxonomy, true );

	return ( '' !== $meta && null !== $meta && false !== $meta ) ? (int) $meta : (int) $term->count;
}

/**
 * Sort terms busiest first, in PHP rather than SQL, for the reason above.
 *
 * @param WP_Term[] $terms Terms.
 * @param int       $limit Keep this many, or all when zero.
 * @return WP_Term[]
 */
function gueta_sort_terms_by_count( $terms, $limit = 0 ) {
	usort(
		$terms,
		static function ( $a, $b ) {
			return gueta_term_product_count( $b ) <=> gueta_term_product_count( $a );
		}
	);

	return $limit > 0 ? array_slice( $terms, 0, $limit ) : array_values( $terms );
}

/**
 * Top level product categories, busiest first.
 *
 * @param int $limit Keep this many, or all when zero.
 * @return WP_Term[]
 */
function gueta_top_categories( $limit = 0 ) {
	if ( ! taxonomy_exists( 'product_cat' ) ) {
		return [];
	}

	$terms = get_terms(
		[
			'taxonomy'   => 'product_cat',
			'parent'     => 0,
			'hide_empty' => true,
		]
	);

	if ( is_wp_error( $terms ) || ! $terms ) {
		return [];
	}

	return gueta_sort_terms_by_count( $terms, $limit );
}

/* -------------------------------------------------------------------------
 * Assets
 * ---------------------------------------------------------------------- */

/**
 * Version an asset by its modification time so deployments never serve a stale file.
 *
 * @param string $relative_path Path relative to the child theme root.
 * @return string
 */
function gueta_asset_version( $relative_path ) {
	$file = get_stylesheet_directory() . $relative_path;

	return file_exists( $file ) ? (string) filemtime( $file ) : HELLO_ELEMENTOR_CHILD_VERSION;
}

/**
 * Load the header stylesheet, script and the WooCommerce cart fragments it relies on.
 *
 * @return void
 */
function gueta_header_assets() {
	$uri = get_stylesheet_directory_uri();

	wp_enqueue_style(
		'gueta-header',
		$uri . '/assets/css/gueta-header.css',
		[ 'hello-elementor-child-style' ],
		gueta_asset_version( '/assets/css/gueta-header.css' )
	);

	wp_enqueue_script(
		'gueta-header',
		$uri . '/assets/js/gueta-header.js',
		[],
		gueta_asset_version( '/assets/js/gueta-header.js' ),
		true
	);

	if ( gueta_has_woocommerce() ) {
		// Keeps the badge and the drawer accurate behind full page caching.
		wp_enqueue_script( 'wc-cart-fragments' );
	}

	wp_localize_script(
		'gueta-header',
		'guetaHeader',
		[
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'gueta_header' ),
			'hasWoo'   => gueta_has_woocommerce(),
			'openCart' => gueta_should_open_cart(),
			'minChars' => 2,
			'strings'  => [
				'searching' => 'מחפשים…',
				'error'     => 'משהו השתבש, נסו שוב.',
			],
		]
	);
}
add_action( 'wp_enqueue_scripts', 'gueta_header_assets', 25 );

/* -------------------------------------------------------------------------
 * Navigation model
 * ---------------------------------------------------------------------- */

/**
 * Build the navigation tree, preferring an assigned menu and falling back to
 * the WooCommerce category tree so the header is useful without configuration.
 *
 * @return array
 */
function gueta_header_nav() {
	$cache_key = 'gueta_header_nav_' . GUETA_CACHE_VERSION . '_' . get_locale();
	$cached    = get_transient( $cache_key );

	if ( is_array( $cached ) ) {
		return $cached;
	}

	$items = gueta_nav_from_menu();

	if ( ! $items ) {
		$items = gueta_nav_from_product_categories();
	}

	set_transient( $cache_key, $items, DAY_IN_SECONDS );

	return $items;
}

/**
 * Drop the cached navigation whenever a menu or a product category changes.
 *
 * @return void
 */
function gueta_flush_nav_cache() {
	delete_transient( 'gueta_header_nav_' . GUETA_CACHE_VERSION . '_' . get_locale() );
}
add_action( 'wp_update_nav_menu', 'gueta_flush_nav_cache' );
add_action( 'switch_theme', 'gueta_flush_nav_cache' );
add_action( 'created_product_cat', 'gueta_flush_nav_cache' );
add_action( 'edited_product_cat', 'gueta_flush_nav_cache' );
add_action( 'delete_product_cat', 'gueta_flush_nav_cache' );

/**
 * Convert the assigned primary menu into the navigation model.
 *
 * @return array
 */
function gueta_nav_from_menu() {
	$locations = get_nav_menu_locations();

	if ( empty( $locations['primary'] ) ) {
		return [];
	}

	$menu_items = wp_get_nav_menu_items( $locations['primary'] );

	if ( ! $menu_items ) {
		return [];
	}

	$by_parent = [];

	foreach ( $menu_items as $menu_item ) {
		$by_parent[ (int) $menu_item->menu_item_parent ][] = $menu_item;
	}

	if ( empty( $by_parent[0] ) ) {
		return [];
	}

	$items = [];

	foreach ( $by_parent[0] as $top ) {
		$columns = [];

		foreach ( $by_parent[ $top->ID ] ?? [] as $child ) {
			$columns[] = [
				'title' => $child->title,
				'url'   => $child->url,
				'items' => array_map(
					static function ( $grandchild ) {
						return [
							'title' => $grandchild->title,
							'url'   => $grandchild->url,
						];
					},
					$by_parent[ $child->ID ] ?? []
				),
			];
		}

		$term = gueta_menu_item_term( $top );
		$mega = [];

		if ( $columns ) {
			$mega = [
				'columns' => array_slice( $columns, 0, GUETA_NAV_MAX_COLUMNS ),
				'banner'  => $term ? gueta_category_banner( $term ) : [],
			];
		} elseif ( $term ) {
			// A menu entry pointing at a category still gets its sub categories.
			$mega = gueta_category_mega( $term );
		}

		$items[] = [
			'title'     => $top->title,
			'url'       => $top->url,
			'highlight' => in_array( 'gueta-highlight', (array) $top->classes, true ),
			'mega'      => $mega,
		];
	}

	return $items;
}

/**
 * Resolve the product category a menu item points at, when there is one.
 *
 * @param WP_Post $menu_item Menu item.
 * @return WP_Term|null
 */
function gueta_menu_item_term( $menu_item ) {
	if ( 'taxonomy' !== $menu_item->type || 'product_cat' !== $menu_item->object ) {
		return null;
	}

	$term = get_term( (int) $menu_item->object_id, 'product_cat' );

	return ( $term && ! is_wp_error( $term ) ) ? $term : null;
}

/**
 * Build the navigation from the busiest top level product categories.
 *
 * @return array
 */
function gueta_nav_from_product_categories() {
	$terms = gueta_top_categories( GUETA_NAV_MAX_ITEMS );

	if ( ! $terms ) {
		return [];
	}

	$items = [];

	foreach ( $terms as $term ) {
		$link = get_term_link( $term );

		if ( is_wp_error( $link ) ) {
			continue;
		}

		$items[] = [
			'title'     => $term->name,
			'url'       => $link,
			'highlight' => false,
			'mega'      => gueta_category_mega( $term ),
		];
	}

	if ( $items && function_exists( 'wc_get_page_permalink' ) ) {
		$items[] = [
			'title'     => 'כל הקטגוריות',
			'url'       => wc_get_page_permalink( 'shop' ),
			'highlight' => false,
			'mega'      => [],
		];
	}

	return $items;
}

/**
 * Turn a product category into mega menu columns plus a banner.
 *
 * Sub categories that have children of their own become a titled column; the
 * remaining leaves are chunked so the panel stays balanced.
 *
 * @param WP_Term $term Product category.
 * @return array
 */
function gueta_category_mega( $term ) {
	$children = get_terms(
		[
			'taxonomy'   => 'product_cat',
			'parent'     => $term->term_id,
			'hide_empty' => true,
			'orderby'    => 'name',
		]
	);

	if ( is_wp_error( $children ) || ! $children ) {
		return [];
	}

	$branches = [];
	$leaves   = [];

	foreach ( $children as $child ) {
		$child_link = get_term_link( $child );

		if ( is_wp_error( $child_link ) ) {
			continue;
		}

		$grandchildren = get_terms(
			[
				'taxonomy'   => 'product_cat',
				'parent'     => $child->term_id,
				'hide_empty' => true,
				'orderby'    => 'name',
				'number'     => 8,
			]
		);

		if ( ! is_wp_error( $grandchildren ) && $grandchildren ) {
			$branches[] = [
				'title' => $child->name,
				'url'   => $child_link,
				'items' => gueta_terms_to_links( $grandchildren ),
			];
			continue;
		}

		$leaves[] = [
			'title' => $child->name,
			'url'   => $child_link,
		];
	}

	$columns    = $branches;
	$parent_url = get_term_link( $term );
	$parent_url = is_wp_error( $parent_url ) ? '' : $parent_url;

	foreach ( array_chunk( $leaves, 8 ) as $index => $chunk ) {
		$columns[] = [
			'title' => 0 === $index ? $term->name : '',
			'url'   => 0 === $index ? $parent_url : '',
			'items' => $chunk,
		];
	}

	if ( ! $columns ) {
		return [];
	}

	return [
		'columns' => array_slice( $columns, 0, GUETA_NAV_MAX_COLUMNS ),
		'banner'  => gueta_category_banner( $term ),
	];
}

/**
 * Map terms onto simple title and url pairs.
 *
 * @param WP_Term[] $terms Terms.
 * @return array
 */
function gueta_terms_to_links( $terms ) {
	$links = [];

	foreach ( $terms as $term ) {
		$link = get_term_link( $term );

		if ( is_wp_error( $link ) ) {
			continue;
		}

		$links[] = [
			'title' => $term->name,
			'url'   => $link,
		];
	}

	return $links;
}

/**
 * The promotional image shown at the far edge of a mega menu.
 *
 * @param WP_Term $term Product category.
 * @return array
 */
function gueta_category_banner( $term ) {
	$thumbnail_id = (int) get_term_meta( $term->term_id, 'thumbnail_id', true );

	if ( ! $thumbnail_id ) {
		return [];
	}

	$image = wp_get_attachment_image_url( $thumbnail_id, 'medium_large' );

	if ( ! $image ) {
		return [];
	}

	$link = get_term_link( $term );

	return [
		'image' => $image,
		'title' => $term->name,
		'url'   => is_wp_error( $link ) ? '' : $link,
	];
}
