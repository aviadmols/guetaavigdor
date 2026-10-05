<?php
/**
 * The menu editor: the whole header menu as one tree, arranged by dragging.
 *
 * The tree has three levels, the way the header draws them: the items in the
 * bar, the columns of each item's panel, and the links in each column. Every
 * entry is either a product category, which keeps its link and, unless renamed,
 * its name from the category, or a link typed in by hand.
 *
 * An item in the bar that is a category starts out automatic: its panel is
 * built from the category's sub categories, as the header always did, so a new
 * sub category appears by itself. Arranging anything inside the panel makes it
 * the item's own, and from then on it shows what the editor holds.
 *
 * The tree is stored in the theme settings as `nav_tree`. Until it is first
 * saved the header goes on with the category table it had before, and the
 * editor opens on that same menu, so nothing has to be built from nothing.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * The tree
 * ---------------------------------------------------------------------- */

/**
 * A category's panel as columns of category ids, the way the header builds it
 * automatically: a sub category with sub categories of its own is a column
 * headed by it, and the rest go eight to a column under the category's name.
 *
 * @param WP_Term $term Product category.
 * @return array[] Columns: type, term, title, url, items.
 */
function gueta_nav_auto_columns( $term ) {
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
				'type'  => 'cat',
				'term'  => (int) $child->term_id,
				'title' => '',
				'url'   => '',
				'items' => array_map( 'gueta_nav_cat_entry', $grandchildren ),
			];
			continue;
		}

		$leaves[] = gueta_nav_cat_entry( $child );
	}

	$columns = $branches;

	foreach ( array_chunk( $leaves, 8 ) as $index => $chunk ) {
		$columns[] = [
			'type'  => 0 === $index ? 'cat' : 'none',
			'term'  => 0 === $index ? (int) $term->term_id : 0,
			'title' => '',
			'url'   => '',
			'items' => $chunk,
		];
	}

	return array_slice( $columns, 0, GUETA_NAV_MAX_COLUMNS );
}

/**
 * A link entry for a category.
 *
 * @param WP_Term $term Product category.
 * @return array
 */
function gueta_nav_cat_entry( $term ) {
	return [
		'type'  => 'cat',
		'term'  => (int) $term->term_id,
		'title' => '',
		'url'   => '',
	];
}

/**
 * The menu as it stands before the editor was ever saved: the categories
 * ticked in the old table, each with an automatic panel.
 *
 * @return array[]
 */
function gueta_nav_tree_initial() {
	$saved = gueta_setting( 'nav_items', [] );
	$tree  = [];

	foreach ( gueta_nav_category_terms() as $term ) {
		$tree[] = [
			'type'      => 'cat',
			'term'      => (int) $term->term_id,
			'title'     => (string) ( $saved[ $term->term_id ]['title'] ?? '' ),
			'url'       => '',
			'hidden'    => 0,
			'highlight' => 0,
			'mode'      => 'auto',
			'columns'   => [],
		];
	}

	return $tree;
}

/**
 * The saved tree, or an empty array when the editor has not been saved yet.
 *
 * @return array[]
 */
function gueta_nav_tree_saved() {
	$tree = gueta_setting( 'nav_tree', [] );

	return is_array( $tree ) ? $tree : [];
}

/* -------------------------------------------------------------------------
 * From the tree to the header
 * ---------------------------------------------------------------------- */

/**
 * The title and the address an entry shows, or null when it points at a
 * category that is gone.
 *
 * @param array $entry Entry.
 * @return array{title:string,url:string}|null
 */
function gueta_nav_resolve( $entry ) {
	$type  = $entry['type'] ?? 'link';
	$title = trim( (string) ( $entry['title'] ?? '' ) );

	if ( 'cat' === $type ) {
		$term = get_term( (int) ( $entry['term'] ?? 0 ), 'product_cat' );

		if ( ! $term || is_wp_error( $term ) ) {
			return null;
		}

		$url = get_term_link( $term );

		return [
			'title' => '' !== $title ? $title : $term->name,
			'url'   => is_wp_error( $url ) ? '' : $url,
		];
	}

	if ( 'none' === $type ) {
		return [ 'title' => $title, 'url' => '' ];
	}

	return [
		'title' => $title,
		'url'   => (string) ( $entry['url'] ?? '' ),
	];
}

/**
 * The header's navigation model from the saved tree.
 *
 * @param array[] $tree Saved tree.
 * @return array
 */
function gueta_nav_from_tree( $tree ) {
	$items = [];

	foreach ( $tree as $top ) {
		if ( ! is_array( $top ) || ! empty( $top['hidden'] ) ) {
			continue;
		}

		$link = gueta_nav_resolve( $top );

		if ( ! $link || '' === $link['title'] ) {
			continue;
		}

		$term = 'cat' === ( $top['type'] ?? '' ) ? get_term( (int) $top['term'], 'product_cat' ) : null;
		$term = $term && ! is_wp_error( $term ) ? $term : null;
		$mega = [];

		if ( $term && 'auto' === ( $top['mode'] ?? 'auto' ) ) {
			$mega = gueta_category_mega( $term );
		} elseif ( ! empty( $top['columns'] ) && is_array( $top['columns'] ) ) {
			$columns = [];

			foreach ( $top['columns'] as $column ) {
				$head  = gueta_nav_resolve( $column );
				$links = [];

				foreach ( (array) ( $column['items'] ?? [] ) as $entry ) {
					$resolved = gueta_nav_resolve( $entry );

					if ( $resolved && '' !== $resolved['title'] ) {
						$links[] = $resolved;
					}
				}

				if ( ! $links && ( ! $head || '' === $head['title'] ) ) {
					continue;
				}

				$columns[] = [
					'title' => $head ? $head['title'] : '',
					'url'   => $head ? $head['url'] : '',
					'items' => $links,
				];
			}

			if ( $columns ) {
				$mega = [
					'columns' => array_slice( $columns, 0, GUETA_NAV_MAX_COLUMNS ),
					'banner'  => $term ? gueta_category_banner( $term ) : [],
				];
			}
		}

		$items[] = [
			'title'     => $link['title'],
			'url'       => $link['url'],
			'highlight' => ! empty( $top['highlight'] ),
			'mega'      => $mega,
		];
	}

	return $items;
}

/* -------------------------------------------------------------------------
 * Saving
 * ---------------------------------------------------------------------- */

/**
 * One entry as it may be stored.
 *
 * @param mixed $raw   Entry from the editor.
 * @param array $types Types allowed at this level.
 * @return array|null
 */
function gueta_nav_clean_entry( $raw, $types ) {
	if ( ! is_array( $raw ) ) {
		return null;
	}

	$type = in_array( $raw['type'] ?? '', $types, true ) ? $raw['type'] : null;

	if ( ! $type ) {
		return null;
	}

	$entry = [
		'type'  => $type,
		'term'  => 'cat' === $type ? absint( $raw['term'] ?? 0 ) : 0,
		'title' => sanitize_text_field( (string) ( $raw['title'] ?? '' ) ),
		'url'   => 'link' === $type ? esc_url_raw( (string) ( $raw['url'] ?? '' ) ) : '',
	];

	if ( 'cat' === $type && ! $entry['term'] ) {
		return null;
	}

	if ( 'link' === $type && ( '' === $entry['url'] || '' === $entry['title'] ) ) {
		return null;
	}

	return $entry;
}

/**
 * The tree posted by the editor, as JSON, cleaned for storing.
 *
 * @param mixed $json Posted JSON.
 * @return array[]
 */
function gueta_sanitize_nav_tree( $json ) {
	$raw = json_decode( wp_unslash( (string) $json ), true );

	if ( ! is_array( $raw ) ) {
		return [];
	}

	$tree = [];

	foreach ( array_slice( $raw, 0, 30 ) as $raw_top ) {
		$top = gueta_nav_clean_entry( $raw_top, [ 'cat', 'link' ] );

		if ( ! $top ) {
			continue;
		}

		$top['hidden']    = ! empty( $raw_top['hidden'] ) ? 1 : 0;
		$top['highlight'] = ! empty( $raw_top['highlight'] ) ? 1 : 0;
		$top['mode']      = 'cat' === $top['type'] && 'auto' === ( $raw_top['mode'] ?? '' ) ? 'auto' : 'custom';
		$top['columns']   = [];

		if ( 'custom' === $top['mode'] ) {
			foreach ( array_slice( (array) ( $raw_top['columns'] ?? [] ), 0, GUETA_NAV_MAX_COLUMNS ) as $raw_column ) {
				$column = gueta_nav_clean_entry( $raw_column, [ 'cat', 'link', 'none' ] );

				if ( ! $column ) {
					continue;
				}

				$column['items'] = [];

				foreach ( array_slice( (array) ( $raw_column['items'] ?? [] ), 0, 40 ) as $raw_item ) {
					$item = gueta_nav_clean_entry( $raw_item, [ 'cat', 'link' ] );

					if ( $item ) {
						$column['items'][] = $item;
					}
				}

				$top['columns'][] = $column;
			}
		}

		$tree[] = $top;
	}

	return $tree;
}

/* -------------------------------------------------------------------------
 * The editor
 * ---------------------------------------------------------------------- */

/**
 * Every product category, for the pickers and for building panels.
 *
 * @return array[] id, name, parent, count.
 */
function gueta_nav_editor_categories() {
	$terms = get_terms(
		[
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'orderby'    => 'name',
		]
	);

	if ( is_wp_error( $terms ) ) {
		return [];
	}

	return array_map(
		static function ( $term ) {
			return [
				'id'     => (int) $term->term_id,
				'name'   => $term->name,
				'parent' => (int) $term->parent,
				'count'  => (int) gueta_term_product_count( $term ),
			];
		},
		$terms
	);
}

/**
 * The automatic panel of every category that has one, so the editor can show
 * it and turn it into the item's own when it is first changed.
 *
 * @return array<int,array[]>
 */
function gueta_nav_editor_auto_panels() {
	$parents = get_terms(
		[
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'childless'  => false,
		]
	);
	$panels  = [];

	if ( is_wp_error( $parents ) ) {
		return $panels;
	}

	$with_children = [];

	foreach ( $parents as $term ) {
		if ( $term->parent ) {
			$with_children[ $term->parent ] = true;
		}
	}

	foreach ( $parents as $term ) {
		if ( isset( $with_children[ $term->term_id ] ) ) {
			$panels[ $term->term_id ] = gueta_nav_auto_columns( $term );
		}
	}

	return $panels;
}

/**
 * The editor's script and styles, on the menu tab only.
 *
 * @param string $hook Current admin page.
 * @return void
 */
function gueta_nav_editor_assets( $hook ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( false === strpos( (string) $hook, 'gueta-theme-settings' ) || 'menu' !== ( isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '' ) ) {
		return;
	}

	$uri = get_stylesheet_directory_uri();

	wp_enqueue_style( 'gueta-nav-admin', $uri . '/assets/css/gueta-nav-admin.css', [], gueta_asset_version( '/assets/css/gueta-nav-admin.css' ) );
	wp_enqueue_script( 'gueta-nav-admin', $uri . '/assets/js/gueta-nav-admin.js', [ 'jquery', 'jquery-ui-sortable' ], gueta_asset_version( '/assets/js/gueta-nav-admin.js' ), true );
}
add_action( 'admin_enqueue_scripts', 'gueta_nav_editor_assets' );

/**
 * The editor's markup: its data, a field the script fills with the tree on
 * submit, and the place it draws into.
 *
 * @return void
 */
function gueta_nav_editor_render() {
	/*
	 * The screen saves while it is being drawn, after the scripts were queued,
	 * so the tree is read here, fresh from the option, and handed over inline.
	 */
	$stored = get_option( GUETA_SETTINGS_OPTION, [] );
	$saved  = is_array( $stored ) && ! empty( $stored['nav_tree'] ) && is_array( $stored['nav_tree'] ) ? $stored['nav_tree'] : [];
	$data   = [
		'tree'       => $saved ? $saved : gueta_nav_tree_initial(),
		'saved'      => (bool) $saved,
		'categories' => gueta_nav_editor_categories(),
		'panels'     => (object) gueta_nav_editor_auto_panels(),
		'maxColumns' => GUETA_NAV_MAX_COLUMNS,
	];
	?>
	<script>window.guetaNavEditor = <?php echo wp_json_encode( $data ); ?>;</script>
	<div class="gueta-nav-editor" data-nav-editor>
		<p class="gueta-nav-editor__help">
			גוררים בעזרת הידית <span class="dashicons dashicons-move"></span> כדי לסדר. אפשר להעביר עמודה בין פריטים וקישור בין עמודות.
			פריט שהוא קטגוריה מתחיל <strong>אוטומטי</strong>: התפריט הנפתח שלו נבנה מתתי הקטגוריות, וקטגוריה חדשה נכנסת אליו לבד.
			ברגע שמשנים משהו בתוכו, הוא עובר לסידור ידני.
		</p>
		<div class="gueta-nav-editor__tree" data-nav-tree></div>
		<div class="gueta-nav-editor__add">
			<button type="button" class="button" data-nav-add-top="cat">+ קטגוריה לתפריט</button>
			<button type="button" class="button" data-nav-add-top="link">+ קישור לתפריט</button>
			<button type="button" class="button-link gueta-nav-editor__reset" data-nav-reset>לחזור לתפריט ששמור עכשיו</button>
		</div>
		<input type="hidden" name="nav_tree" value="" data-nav-field>
		<noscript><p>העורך צריך JavaScript.</p></noscript>
	</div>
	<?php
}
