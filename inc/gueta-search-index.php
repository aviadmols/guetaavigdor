<?php
/**
 * The static index behind the predictive search.
 *
 * Typing in the header used to run a LIKE query, a count query and three more
 * queries per keystroke, none of which a page cache can hold. Instead the
 * catalogue is written to a JSON file in the uploads folder; the browser
 * fetches it once and searches it in memory, so the database is never touched
 * while somebody types.
 *
 * The file name carries a hash of its own contents, so a rebuild that changes
 * nothing keeps the same URL and the browser keeps its copy.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option describing the index that is live right now.
 */
const GUETA_SEARCH_INDEX_OPTION = 'gueta_search_index';

/**
 * Folder inside wp-content/uploads holding the generated files.
 */
const GUETA_SEARCH_INDEX_FOLDER = 'gueta-search';

/**
 * Cron hook that rebuilds the index after a catalogue change.
 */
const GUETA_SEARCH_INDEX_HOOK = 'gueta_search_index_rebuild';

/**
 * Cron hook for the daily safety rebuild.
 */
const GUETA_SEARCH_INDEX_DAILY = 'gueta_search_index_daily';

/**
 * Articles kept in the index.
 */
const GUETA_SEARCH_INDEX_ARTICLES = 300;

/**
 * Products read from the database per batch while building.
 */
const GUETA_SEARCH_INDEX_BATCH = 200;

/* -------------------------------------------------------------------------
 * Where the index lives
 * ---------------------------------------------------------------------- */

/**
 * The stored description of the current index.
 *
 * @return array
 */
function gueta_search_index_meta() {
	$meta = get_option( GUETA_SEARCH_INDEX_OPTION, [] );

	return is_array( $meta ) ? $meta : [];
}

/**
 * Absolute path of the folder holding the generated files.
 *
 * @return string Empty when the uploads folder is unavailable.
 */
function gueta_search_index_dir() {
	$upload = wp_upload_dir();

	if ( ! empty( $upload['error'] ) ) {
		return '';
	}

	return trailingslashit( $upload['basedir'] ) . GUETA_SEARCH_INDEX_FOLDER;
}

/**
 * Public URL of the current index file.
 *
 * @return string Empty when no index has been built yet.
 */
function gueta_search_index_url() {
	$meta = gueta_search_index_meta();

	if ( empty( $meta['file'] ) ) {
		return '';
	}

	$upload = wp_upload_dir();

	if ( ! empty( $upload['error'] ) ) {
		return '';
	}

	return trailingslashit( $upload['baseurl'] ) . GUETA_SEARCH_INDEX_FOLDER . '/' . $meta['file'];
}

/**
 * Turn an absolute URL into a path relative to a base, so the file does not
 * repeat the site address a few thousand times.
 *
 * @param string $url  Absolute URL.
 * @param string $base Base URL ending in a slash.
 * @return string
 */
function gueta_search_index_relative( $url, $base ) {
	$url = (string) $url;

	if ( $url && 0 === strpos( $url, $base ) ) {
		return substr( $url, strlen( $base ) );
	}

	return $url;
}

/* -------------------------------------------------------------------------
 * Building
 * ---------------------------------------------------------------------- */

/**
 * The currency settings the browser needs to format a price itself.
 *
 * @return array
 */
function gueta_search_index_currency() {
	if ( ! gueta_has_woocommerce() ) {
		return [];
	}

	return [
		's'    => gueta_plain_text( get_woocommerce_currency_symbol() ),
		'd'    => wc_get_price_decimals(),
		'ds'   => wc_get_price_decimal_separator(),
		'ts'   => wc_get_price_thousand_separator(),
		'f'    => get_woocommerce_price_format(),
		'trim' => (bool) apply_filters( 'woocommerce_price_trim_zeros', false ),
	];
}

/**
 * The prices shown in a suggestion, already adjusted for the store's tax
 * display setting.
 *
 * @param WC_Product $product Product.
 * @return array Price, the regular price when on sale, and the high end of a range.
 */
function gueta_search_index_prices( $product ) {
	$price   = '';
	$regular = '';
	$max     = '';

	if ( $product->is_type( 'variable' ) && method_exists( $product, 'get_variation_price' ) ) {
		$low  = $product->get_variation_price( 'min', true );
		$high = $product->get_variation_price( 'max', true );

		if ( '' !== $low ) {
			$price = (string) round( (float) $low, 4 );
		}

		if ( '' !== $high && (float) $high > (float) $low ) {
			$max = (string) round( (float) $high, 4 );
		}

		if ( $product->is_on_sale() && method_exists( $product, 'get_variation_regular_price' ) ) {
			$was = $product->get_variation_regular_price( 'min', true );

			if ( '' !== $was && (float) $was > (float) $low ) {
				$regular = (string) round( (float) $was, 4 );
			}
		}

		return [ $price, $regular, $max ];
	}

	if ( '' !== $product->get_price() ) {
		$price = (string) round( (float) wc_get_price_to_display( $product ), 4 );
	}

	if ( $product->is_on_sale() && '' !== $product->get_regular_price() ) {
		$was = wc_get_price_to_display( $product, [ 'price' => $product->get_regular_price() ] );

		if ( (float) $was > (float) $price ) {
			$regular = (string) round( (float) $was, 4 );
		}
	}

	return [ $price, $regular, $max ];
}

/**
 * Every searchable product, as compact rows.
 *
 * A row is [ title, url, thumbnail, price, was, max, sku, keywords ].
 *
 * @param string $home    Site URL ending in a slash.
 * @param string $uploads Uploads URL ending in a slash.
 * @return array
 */
function gueta_search_index_products( $home, $uploads ) {
	$has_woo = gueta_has_woocommerce();
	$rows    = [];
	$page    = 1;
	$ids     = [];

	do {
		$args = [
			'post_type'              => $has_woo ? 'product' : [ 'post', 'page' ],
			'post_status'            => 'publish',
			'posts_per_page'         => GUETA_SEARCH_INDEX_BATCH,
			'paged'                  => $page,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		if ( $has_woo ) {
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
		$ids   = $query->posts;

		if ( ! $ids ) {
			break;
		}

		// One trip for the meta and the terms of the whole batch.
		_prime_post_caches( $ids, true, true );

		foreach ( $ids as $post_id ) {
			$rows[] = gueta_search_index_row( $post_id, $home, $uploads );
		}

		/*
		 * The batch is written out, so let its objects go before reading the
		 * next one. These are plain cache deletes rather than
		 * clean_post_cache(), which fires invalidation hooks that other
		 * plugins listen to; this loop only reads, so it should not ask
		 * anybody to do work.
		 */
		foreach ( $ids as $post_id ) {
			wp_cache_delete( $post_id, 'posts' );
			wp_cache_delete( $post_id, 'post_meta' );
		}

		$page++;
	} while ( count( $ids ) === GUETA_SEARCH_INDEX_BATCH );

	return $rows;
}

/**
 * One product row.
 *
 * @param int    $post_id Product or post id.
 * @param string $home    Site URL ending in a slash.
 * @param string $uploads Uploads URL ending in a slash.
 * @return array
 */
function gueta_search_index_row( $post_id, $home, $uploads ) {
	$product  = gueta_has_woocommerce() && function_exists( 'wc_get_product' ) ? wc_get_product( $post_id ) : null;
	$prices   = $product ? gueta_search_index_prices( $product ) : [ '', '', '' ];
	$keywords = [];

	if ( $product ) {
		foreach ( [ 'product_cat', 'product_tag' ] as $taxonomy ) {
			$names = wp_get_post_terms( $post_id, $taxonomy, [ 'fields' => 'names' ] );

			if ( ! is_wp_error( $names ) ) {
				$keywords = array_merge( $keywords, $names );
			}
		}
	}

	$size = $product ? 'woocommerce_thumbnail' : 'thumbnail';

	return [
		gueta_plain_text( get_the_title( $post_id ) ),
		gueta_search_index_relative( (string) get_permalink( $post_id ), $home ),
		gueta_search_index_relative( (string) get_the_post_thumbnail_url( $post_id, $size ), $uploads ),
		$prices[0],
		$prices[1],
		$prices[2],
		$product ? gueta_plain_text( $product->get_sku() ) : '',
		gueta_plain_text( implode( ' ', $keywords ) ),
	];
}

/**
 * Every term of a taxonomy, as [ name, url, count ] plus the breadcrumb for
 * categories.
 *
 * @param string $taxonomy  Taxonomy name.
 * @param string $home      Site URL ending in a slash.
 * @param bool   $with_path Whether to include the ancestor breadcrumb.
 * @return array
 */
function gueta_search_index_terms( $taxonomy, $home, $with_path ) {
	if ( ! taxonomy_exists( $taxonomy ) ) {
		return [];
	}

	$terms = get_terms(
		[
			'taxonomy'   => $taxonomy,
			'hide_empty' => true,
		]
	);

	if ( is_wp_error( $terms ) ) {
		return [];
	}

	$rows = [];

	foreach ( $terms as $term ) {
		$link = get_term_link( $term );

		if ( is_wp_error( $link ) ) {
			continue;
		}

		$row = [
			gueta_plain_text( $term->name ),
			gueta_search_index_relative( $link, $home ),
			gueta_term_product_count( $term ),
		];

		if ( $with_path ) {
			$row[] = gueta_plain_text( gueta_term_path( $term ) );
		}

		$rows[] = $row;
	}

	return $rows;
}

/**
 * Recent articles and pages, as [ title, url ].
 *
 * @param string $home Site URL ending in a slash.
 * @return array
 */
function gueta_search_index_articles( $home ) {
	$query = new WP_Query(
		[
			'post_type'              => [ 'post', 'page' ],
			'post_status'            => 'publish',
			'posts_per_page'         => GUETA_SEARCH_INDEX_ARTICLES,
			'fields'                 => 'ids',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		]
	);

	$rows = [];

	foreach ( $query->posts as $post_id ) {
		$rows[] = [
			gueta_plain_text( get_the_title( $post_id ) ),
			gueta_search_index_relative( (string) get_permalink( $post_id ), $home ),
		];
	}

	return $rows;
}

/**
 * Build the index and write it out.
 *
 * @return array The outcome, its message, and whether the file changed.
 */
function gueta_build_search_index() {
	$dir = gueta_search_index_dir();

	if ( ! $dir ) {
		return [
			'ok'      => false,
			'message' => 'תיקיית ההעלאות אינה זמינה, ולכן לא ניתן לכתוב את קובץ האינדקס.',
			'changed' => false,
		];
	}

	if ( ! wp_mkdir_p( $dir ) ) {
		return [
			'ok'      => false,
			'message' => 'לא ניתן ליצור את תיקיית האינדקס בתוך תיקיית ההעלאות.',
			'changed' => false,
		];
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	$upload  = wp_upload_dir();
	$home    = trailingslashit( home_url( '/' ) );
	$uploads = trailingslashit( $upload['baseurl'] );
	$has_woo = gueta_has_woocommerce();

	$payload = [
		'home' => $home,
		'up'   => $uploads,
		'cur'  => gueta_search_index_currency(),
		'woo'  => $has_woo,
		'lim'  => [
			'p' => GUETA_SEARCH_PRODUCT_LIMIT,
			't' => GUETA_SEARCH_TERM_LIMIT,
			'a' => 3,
		],
		'p'    => gueta_search_index_products( $home, $uploads ),
		'c'    => $has_woo ? gueta_search_index_terms( 'product_cat', $home, true ) : [],
		't'    => $has_woo ? gueta_search_index_terms( 'product_tag', $home, false ) : [],
		'a'    => $has_woo ? gueta_search_index_articles( $home ) : [],
	];

	// Hebrew stays Hebrew: escaping it would triple the size of the file.
	$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	if ( ! $json ) {
		return [
			'ok'      => false,
			'message' => 'קידוד האינדקס נכשל.',
			'changed' => false,
		];
	}

	$version = substr( md5( $json ), 0, 12 );
	$file    = 'index-' . $version . '.json';
	$path    = $dir . '/' . $file;
	$meta    = gueta_search_index_meta();
	$count   = count( $payload['p'] );

	// Nothing changed: leave the file, and every browser cache, alone.
	if ( ! empty( $meta['version'] ) && $meta['version'] === $version && file_exists( $path ) ) {
		$meta['checked'] = time();
		update_option( GUETA_SEARCH_INDEX_OPTION, $meta, false );

		return [
			'ok'      => true,
			'message' => sprintf( 'האינדקס נבדק ונמצא מעודכן. %s מוצרים.', number_format_i18n( $count ) ),
			'changed' => false,
		];
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( false === file_put_contents( $path, $json, LOCK_EX ) ) {
		return [
			'ok'      => false,
			'message' => 'כתיבת קובץ האינדקס נכשלה. בדקו את הרשאות הכתיבה בתיקיית ההעלאות.',
			'changed' => false,
		];
	}

	gueta_search_index_write_htaccess( $dir );
	gueta_search_index_prune( $dir, $file );

	update_option(
		GUETA_SEARCH_INDEX_OPTION,
		[
			'file'     => $file,
			'version'  => $version,
			'built'    => time(),
			'checked'  => time(),
			'products' => $count,
			'terms'    => count( $payload['c'] ) + count( $payload['t'] ),
			'bytes'    => strlen( $json ),
		],
		false
	);

	return [
		'ok'      => true,
		'message' => sprintf(
			'האינדקס נבנה מחדש: %1$s מוצרים, %2$s.',
			number_format_i18n( $count ),
			size_format( strlen( $json ) )
		),
		'changed' => true,
	];
}

/**
 * Ask the web server to cache the file hard. Its name changes whenever its
 * contents do, so there is nothing stale left to serve.
 *
 * @param string $dir Index folder.
 * @return void
 */
function gueta_search_index_write_htaccess( $dir ) {
	$path = $dir . '/.htaccess';

	if ( file_exists( $path ) ) {
		return;
	}

	$rules = "<IfModule mod_headers.c>\n"
		. "\tHeader set Cache-Control \"public, max-age=31536000, immutable\"\n"
		. "</IfModule>\n";

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
	@file_put_contents( $path, $rules );
}

/**
 * Delete index files left over from earlier builds.
 *
 * @param string $dir  Index folder.
 * @param string $keep File name to keep.
 * @return void
 */
function gueta_search_index_prune( $dir, $keep ) {
	$files = glob( $dir . '/index-*.json' );

	if ( ! is_array( $files ) ) {
		return;
	}

	foreach ( $files as $file ) {
		if ( basename( $file ) !== $keep ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $file );
		}
	}
}

/* -------------------------------------------------------------------------
 * Keeping it current
 * ---------------------------------------------------------------------- */

/**
 * Queue a rebuild a few minutes out, so a burst of edits costs one build.
 *
 * @return void
 */
function gueta_queue_search_index_rebuild() {
	if ( wp_next_scheduled( GUETA_SEARCH_INDEX_HOOK ) ) {
		return;
	}

	/**
	 * How long to wait before rebuilding after a catalogue change.
	 *
	 * @param int $delay Seconds.
	 */
	$delay = (int) apply_filters( 'gueta_search_index_delay', 3 * MINUTE_IN_SECONDS );

	wp_schedule_single_event( time() + max( 30, $delay ), GUETA_SEARCH_INDEX_HOOK );
}

/**
 * Queue a rebuild after a post is saved, ignoring revisions and autosaves.
 *
 * @param int $post_id Post id.
 * @return void
 */
function gueta_queue_search_index_rebuild_on_save( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	gueta_queue_search_index_rebuild();
}

/**
 * Queue a rebuild only when the post that changed is one the index holds.
 * Orders, revisions and everything else leave it alone.
 *
 * @param int          $post_id Post id.
 * @param WP_Post|null $post    The post, when the hook passes one.
 * @return void
 */
function gueta_queue_search_index_rebuild_for_post( $post_id, $post = null ) {
	$type = $post instanceof WP_Post ? $post->post_type : get_post_type( $post_id );

	if ( in_array( $type, [ 'product', 'post', 'page' ], true ) ) {
		gueta_queue_search_index_rebuild();
	}
}

add_action( 'save_post_product', 'gueta_queue_search_index_rebuild_on_save' );
add_action( 'save_post_post', 'gueta_queue_search_index_rebuild_on_save' );
add_action( 'save_post_page', 'gueta_queue_search_index_rebuild_on_save' );
add_action( 'woocommerce_new_product', 'gueta_queue_search_index_rebuild' );
add_action( 'trashed_post', 'gueta_queue_search_index_rebuild_for_post' );
add_action( 'untrashed_post', 'gueta_queue_search_index_rebuild_for_post' );
add_action( 'deleted_post', 'gueta_queue_search_index_rebuild_for_post', 10, 2 );
add_action( 'created_product_cat', 'gueta_queue_search_index_rebuild' );
add_action( 'edited_product_cat', 'gueta_queue_search_index_rebuild' );
add_action( 'delete_product_cat', 'gueta_queue_search_index_rebuild' );
add_action( 'created_product_tag', 'gueta_queue_search_index_rebuild' );
add_action( 'edited_product_tag', 'gueta_queue_search_index_rebuild' );
add_action( 'delete_product_tag', 'gueta_queue_search_index_rebuild' );

add_action( GUETA_SEARCH_INDEX_HOOK, 'gueta_build_search_index' );
add_action( GUETA_SEARCH_INDEX_DAILY, 'gueta_build_search_index' );

/**
 * Keep the daily safety rebuild on the schedule.
 *
 * @return void
 */
function gueta_search_index_schedule_daily() {
	if ( ! wp_next_scheduled( GUETA_SEARCH_INDEX_DAILY ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', GUETA_SEARCH_INDEX_DAILY );
	}
}
add_action( 'init', 'gueta_search_index_schedule_daily' );

/**
 * Drop the schedule when the theme is switched away.
 *
 * @return void
 */
function gueta_search_index_unschedule() {
	wp_clear_scheduled_hook( GUETA_SEARCH_INDEX_DAILY );
	wp_clear_scheduled_hook( GUETA_SEARCH_INDEX_HOOK );
}
add_action( 'switch_theme', 'gueta_search_index_unschedule' );

/**
 * Build the first index without anybody having to press the button.
 *
 * @return void
 */
function gueta_search_index_bootstrap() {
	$meta = gueta_search_index_meta();

	if ( empty( $meta['file'] ) ) {
		gueta_queue_search_index_rebuild();
	}
}
add_action( 'admin_init', 'gueta_search_index_bootstrap' );

/* -------------------------------------------------------------------------
 * The admin section
 * ---------------------------------------------------------------------- */

/**
 * The index panel on the theme settings screen, with its own rebuild button.
 * It sits outside the settings form so the two never submit together.
 *
 * @return void
 */
function gueta_search_index_admin_section() {
	$notice = '';
	$failed = false;

	if ( isset( $_POST['gueta_rebuild_index'] ) && check_admin_referer( 'gueta_rebuild_index' ) ) {
		$result = gueta_build_search_index();
		$notice = $result['message'];
		$failed = empty( $result['ok'] );
	}

	$meta     = gueta_search_index_meta();
	$url      = gueta_search_index_url();
	$built    = ! empty( $meta['built'] ) ? (int) $meta['built'] : 0;
	$when     = $built ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $built ) : '';
	$queued   = wp_next_scheduled( GUETA_SEARCH_INDEX_HOOK );
	$products = isset( $meta['products'] ) ? (int) $meta['products'] : 0;
	$bytes    = isset( $meta['bytes'] ) ? (int) $meta['bytes'] : 0;
	?>
	<hr>

	<h2>אינדקס החיפוש</h2>
	<p>
		החיפוש בהדר קורא קובץ JSON אחד במקום לפנות למסד הנתונים בכל הקשה.
		הקובץ נבנה מחדש אוטומטית כמה דקות אחרי שמירת מוצר, קטגוריה או מאמר, ופעם ביום כרשת ביטחון.
		אחרי ייבוא מוצרים או עדכון מחירים בכמות גדולה, אפשר לדחוף בנייה מיידית מכאן.
	</p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo $failed ? 'error' : 'success'; ?> inline">
			<p><?php echo esc_html( $notice ); ?></p>
		</div>
	<?php endif; ?>

	<table class="widefat striped" style="max-width:760px;">
		<tbody>
			<tr>
				<td style="width:200px;"><strong>מצב</strong></td>
				<td>
					<?php if ( $url ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color:#008a20;"></span> פעיל
					<?php else : ?>
						<span class="dashicons dashicons-minus"></span> טרם נבנה. עד אז החיפוש עובד מול מסד הנתונים כמו קודם.
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $url ) : ?>
				<tr>
					<td><strong>נבנה לאחרונה</strong></td>
					<td><?php echo esc_html( $when ); ?></td>
				</tr>
				<tr>
					<td><strong>מוצרים באינדקס</strong></td>
					<td><?php echo esc_html( number_format_i18n( $products ) ); ?></td>
				</tr>
				<tr>
					<td><strong>גודל הקובץ</strong></td>
					<td><?php echo esc_html( size_format( $bytes ) ); ?></td>
				</tr>
				<tr>
					<td><strong>הקובץ</strong></td>
					<td><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( isset( $meta['file'] ) ? $meta['file'] : '' ); ?></a></td>
				</tr>
			<?php endif; ?>
			<?php if ( $queued ) : ?>
				<tr>
					<td><strong>בנייה מתוזמנת</strong></td>
					<td><?php echo esc_html( wp_date( get_option( 'time_format' ), $queued ) ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<form method="post" style="margin-top:16px;">
		<?php wp_nonce_field( 'gueta_rebuild_index' ); ?>
		<?php submit_button( 'בניית האינדקס עכשיו', 'secondary', 'gueta_rebuild_index', false ); ?>
		<p class="description" style="margin-top:8px;">
			הבנייה קוראת את כל המוצרים ואורכת כמה שניות. אם התוכן לא השתנה, הקובץ הקיים נשאר במקומו והדפדפנים לא מורידים אותו שוב.
		</p>
	</form>
	<?php
}
