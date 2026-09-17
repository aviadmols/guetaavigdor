<?php
/**
 * Page cache: the screen that switches it on, and everything that clears it.
 *
 * The cache itself is page-cache/engine.php, which has to run before the
 * plugins load to be worth having; see the notes there. This file writes the
 * small must-use plugin that loads it, keeps that loader's settings up to
 * date, and clears the stored pages whenever something a visitor could see
 * has changed: a product, its price or stock, a page, a menu, a category, the
 * theme's settings, Elementor's designs, or the theme's own code.
 *
 * It starts switched off. Gueta Theme → מטמון עמודים switches it on.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option holding the page cache settings.
 */
const GUETA_PAGE_CACHE_OPTION = 'gueta_page_cache';

/**
 * Marks the loader as this theme's, so it is never mistaken for another file.
 */
const GUETA_PAGE_CACHE_MARKER = 'Written by the Gueta theme page cache';

/**
 * The settings, with defaults.
 *
 * @return array{enabled:bool,ttl:int,exclude:string}
 */
function gueta_page_cache_settings() {
	$stored = get_option( GUETA_PAGE_CACHE_OPTION, [] );
	$stored = is_array( $stored ) ? $stored : [];

	return [
		'enabled' => ! empty( $stored['enabled'] ),
		'ttl'     => isset( $stored['ttl'] ) ? max( 3600, min( 36000, (int) $stored['ttl'] ) ) : 28800,
		'exclude' => isset( $stored['exclude'] ) ? (string) $stored['exclude'] : '',
	];
}

/**
 * Where the stored pages live.
 *
 * @return string
 */
function gueta_page_cache_dir() {
	return WP_CONTENT_DIR . '/cache/gueta-pages';
}

/**
 * The must-use plugin that loads the engine.
 *
 * @return string
 */
function gueta_page_cache_loader_path() {
	return WPMU_PLUGIN_DIR . '/gueta-page-cache.php';
}

/**
 * Paths that are never cached: the cart, the checkout and the account, which
 * are personal, and whatever else the screen lists.
 *
 * @return string[]
 */
function gueta_page_cache_excluded_paths() {
	$paths = [];

	if ( function_exists( 'wc_get_page_id' ) ) {
		foreach ( [ 'cart', 'checkout', 'myaccount' ] as $page ) {
			$id = wc_get_page_id( $page );

			if ( $id > 0 ) {
				$paths[] = (string) wp_parse_url( get_permalink( $id ), PHP_URL_PATH );
			}
		}
	}

	foreach ( preg_split( '/\R/', gueta_page_cache_settings()['exclude'] ) as $line ) {
		$line = trim( $line );

		if ( '' !== $line ) {
			$paths[] = '/' . ltrim( (string) wp_parse_url( $line, PHP_URL_PATH ), '/' );
		}
	}

	return array_values( array_unique( array_filter( $paths, static fn( $path ) => '' !== $path && '/' !== $path ) ) );
}

/**
 * Query parameters a cached page may carry: paging, sorting and the archive's
 * filters. Filters on attributes, pa_*, are allowed by the engine itself.
 *
 * @return string[]
 */
function gueta_page_cache_query_keys() {
	return (array) apply_filters( 'gueta_page_cache_query_keys', [ 'paged', 'page', 'product-page', 'orderby', 'sort', 'min_price', 'max_price', 'stock', 'cat', 'category' ] );
}

/**
 * The loader's source, with the settings written into it.
 *
 * The settings travel in the file so that serving a page needs no database
 * query of its own. The loader stops if another theme becomes active, so a
 * switch of theme never serves this theme's pages.
 *
 * @return string
 */
function gueta_page_cache_loader_source() {
	$settings = gueta_page_cache_settings();
	$config   = [
		'dir'     => gueta_page_cache_dir(),
		'ttl'     => $settings['ttl'],
		'exclude' => gueta_page_cache_excluded_paths(),
		'query'   => gueta_page_cache_query_keys(),
	];

	// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_export
	return "<?php\n"
		. "/**\n"
		. " * Plugin Name: Gueta page cache\n"
		. " * Description: Serves cached pages to visitors with no login and no cart, before the other plugins load.\n"
		. " *\n"
		. ' * ' . GUETA_PAGE_CACHE_MARKER . ". Switch it off under Gueta Theme, or delete this file.\n"
		. " */\n\n"
		. "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n\n"
		. 'if ( ' . var_export( get_stylesheet(), true ) . " !== get_option( 'stylesheet' ) ) {\n\treturn;\n}\n\n"
		. '$gueta_page_cache_config = ' . var_export( $config, true ) . ";\n\n"
		. '$gueta_page_cache_engine = ' . var_export( get_stylesheet_directory() . '/page-cache/engine.php', true ) . ";\n\n"
		. "if ( is_readable( \$gueta_page_cache_engine ) ) {\n\trequire \$gueta_page_cache_engine;\n}\n";
	// phpcs:enable
}

/**
 * Whether the loader on disk is this theme's.
 *
 * @return bool
 */
function gueta_page_cache_loader_is_ours() {
	$path = gueta_page_cache_loader_path();

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	return is_readable( $path ) && false !== strpos( (string) file_get_contents( $path ), GUETA_PAGE_CACHE_MARKER );
}

/**
 * Write the loader, or rewrite it with current settings.
 *
 * @return true|WP_Error
 */
function gueta_page_cache_install() {
	$path = gueta_page_cache_loader_path();

	if ( file_exists( $path ) && ! gueta_page_cache_loader_is_ours() ) {
		return new WP_Error( 'gueta_page_cache_foreign', 'בתיקיית mu-plugins כבר יש קובץ בשם gueta-page-cache.php שלא נכתב על ידי התבנית.' );
	}

	if ( ! wp_mkdir_p( WPMU_PLUGIN_DIR ) || ! wp_mkdir_p( gueta_page_cache_dir() ) ) {
		return new WP_Error( 'gueta_page_cache_dirs', 'לא ניתן ליצור את התיקיות של המטמון בתוך wp-content.' );
	}

	$source = gueta_page_cache_loader_source();

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( is_readable( $path ) && file_get_contents( $path ) === $source ) {
		return true;
	}

	// Written beside it and moved into place, so a request never reads half a file.
	$temp = $path . '.' . wp_generate_password( 8, false ) . '.tmp';

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( false === file_put_contents( $temp, $source ) || ! rename( $temp, $path ) ) {
		wp_delete_file( $temp );
		return new WP_Error( 'gueta_page_cache_write', 'לא ניתן לכתוב את הקובץ לתיקיית mu-plugins.' );
	}

	if ( function_exists( 'opcache_invalidate' ) ) {
		opcache_invalidate( $path, true );
	}

	gueta_page_cache_purge();

	return true;
}

/**
 * Remove the loader and every stored page.
 *
 * @return void
 */
function gueta_page_cache_uninstall() {
	if ( gueta_page_cache_loader_is_ours() ) {
		wp_delete_file( gueta_page_cache_loader_path() );
	}

	gueta_page_cache_purge();
}

/**
 * Clear every stored page.
 *
 * The purged stamp goes first: the engine will not serve a page older than it,
 * so a page still on disk while the delete runs is already out of use.
 *
 * @return void
 */
function gueta_page_cache_purge() {
	$dir = gueta_page_cache_dir();

	if ( ! is_dir( $dir ) ) {
		return;
	}

	touch( $dir . '/purged' );

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} elseif ( ! in_array( $item->getFilename(), [ 'purged', 'build' ], true ) ) {
			@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
	}

	update_option( 'gueta_page_cache_purged_at', time(), false );
}

/**
 * How many pages are stored, and how much they take.
 *
 * @return array{pages:int,bytes:int}
 */
function gueta_page_cache_stats() {
	$stats = [
		'pages' => 0,
		'bytes' => 0,
	];

	if ( ! is_dir( gueta_page_cache_dir() ) ) {
		return $stats;
	}

	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( gueta_page_cache_dir(), FilesystemIterator::SKIP_DOTS ) ) as $item ) {
		if ( $item->isFile() && '.html' === substr( $item->getFilename(), -5 ) ) {
			++$stats['pages'];
			$stats['bytes'] += $item->getSize();
		}
	}

	return $stats;
}

/* -------------------------------------------------------------------------
 * Clearing when something changes
 * ---------------------------------------------------------------------- */

/**
 * Clear the cache once, at the end of this request, however many changes it
 * made. A product import saves hundreds of products in one go.
 *
 * @return void
 */
function gueta_page_cache_purge_later() {
	static $queued = false;

	if ( $queued || ! gueta_page_cache_settings()['enabled'] ) {
		return;
	}

	$queued = true;
	add_action( 'shutdown', 'gueta_page_cache_purge', 0 );
}

/**
 * A post changed. Revisions, autosaves and the theme's own private types
 * change nothing a visitor sees.
 *
 * @param int $post_id Post ID.
 * @return void
 */
function gueta_page_cache_post_changed( $post_id ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$type = get_post_type( $post_id );

	if ( $type && in_array( $type, [ 'shop_order', 'shop_order_refund', 'shop_order_placehold', 'shop_coupon', 'customize_changeset', 'oembed_cache', 'wp_global_styles' ], true ) ) {
		return;
	}

	gueta_page_cache_purge_later();
}
add_action( 'save_post', 'gueta_page_cache_post_changed' );
add_action( 'deleted_post', 'gueta_page_cache_post_changed' );
add_action( 'trashed_post', 'gueta_page_cache_post_changed' );
add_action( 'untrashed_post', 'gueta_page_cache_post_changed' );

// Prices, stock, categories, menus, designs, reviews: anything shown on a page.
foreach ( [
	'woocommerce_update_product',
	'woocommerce_update_product_variation',
	'woocommerce_product_set_stock',
	'woocommerce_variation_set_stock',
	'woocommerce_product_set_stock_status',
	'woocommerce_variation_set_stock_status',
	'woocommerce_scheduled_sales',
	'created_term',
	'edited_term',
	'delete_term',
	'wp_update_nav_menu',
	'customize_save_after',
	'elementor/editor/after_save',
	'elementor/core/files/clear_cache',
	'comment_post',
	'wp_set_comment_status',
	'update_option_' . GUETA_SETTINGS_OPTION,
	'update_option_sidebars_widgets',
	// A plugin updated, uploaded, switched on or off can change what a page prints.
	'upgrader_process_complete',
	'activated_plugin',
	'deactivated_plugin',
] as $gueta_page_cache_hook ) {
	add_action( $gueta_page_cache_hook, 'gueta_page_cache_purge_later', 99, 0 );
}
unset( $gueta_page_cache_hook );

/**
 * A setting changed that shows on the shop's pages.
 *
 * @param string $option Option name.
 * @return void
 */
function gueta_page_cache_option_changed( $option ) {
	if ( preg_match( '/^(?:woocommerce_(?:currency|price_|tax_|calc_taxes|prices_include_tax|shop_page|hide_out_of_stock|catalog|placeholder)|blogname|blogdescription|site_icon|page_on_front|show_on_front|permalink_structure|elementor_active_kit|widget_)/', (string) $option ) ) {
		gueta_page_cache_purge_later();
	}
}
add_action( 'updated_option', 'gueta_page_cache_option_changed' );

/**
 * Leaving this theme takes the cache with it.
 *
 * @return void
 */
function gueta_page_cache_theme_switched() {
	gueta_page_cache_uninstall();
}
add_action( 'switch_theme', 'gueta_page_cache_theme_switched' );

/**
 * New theme code clears the cache.
 *
 * A deploy uploads files and nothing else happens, so a page stored before it
 * would keep its old markup against the new stylesheets and scripts. At most
 * once a minute, on a request that was not served from the cache anyway, the
 * newest modification time among the theme's files is compared with the last
 * one seen, kept in a small file beside the pages.
 *
 * @return void
 */
function gueta_page_cache_watch_code() {
	if ( ! gueta_page_cache_settings()['enabled'] ) {
		return;
	}

	$stamp = gueta_page_cache_dir() . '/build';
	$seen  = @filemtime( $stamp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( $seen && time() - $seen < MINUTE_IN_SECONDS ) {
		return;
	}

	$root   = get_stylesheet_directory();
	$newest = 0;

	foreach ( [ '/*.php', '/style.css', '/inc/*.php', '/page-cache/*.php', '/woocommerce/*/*.php', '/assets/css/*.css', '/assets/js/*.js' ] as $pattern ) {
		foreach ( (array) glob( $root . $pattern ) as $file ) {
			$newest = max( $newest, (int) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$last = is_readable( $stamp ) ? (int) file_get_contents( $stamp ) : 0;

	if ( ! wp_mkdir_p( gueta_page_cache_dir() ) ) {
		return;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $stamp, (string) $newest );

	if ( $last && $newest !== $last ) {
		gueta_page_cache_purge();
	}
}
add_action( 'init', 'gueta_page_cache_watch_code', 20 );

/**
 * Keep the loader's settings in step: the cart, checkout and account paths
 * can change, and so can the screen's settings.
 *
 * @return void
 */
function gueta_page_cache_sync_loader() {
	if ( ! current_user_can( 'manage_options' ) || ! gueta_page_cache_settings()['enabled'] || ! gueta_page_cache_loader_is_ours() ) {
		return;
	}

	gueta_page_cache_install();
}
add_action( 'admin_init', 'gueta_page_cache_sync_loader' );

/* -------------------------------------------------------------------------
 * The screen, and a button in the admin bar
 * ---------------------------------------------------------------------- */

/**
 * Register the screen under Gueta Theme.
 *
 * @return void
 */
function gueta_page_cache_menu() {
	add_submenu_page( 'gueta-theme', 'מטמון עמודים', 'מטמון עמודים', 'manage_options', 'gueta-page-cache', 'gueta_page_cache_render_page' );
}
add_action( 'admin_menu', 'gueta_page_cache_menu', 12 );

/**
 * Clear the cache from the admin bar button.
 *
 * @return void
 */
function gueta_page_cache_admin_purge() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.' ) );
	}

	check_admin_referer( 'gueta_page_cache_purge' );
	gueta_page_cache_purge();

	wp_safe_redirect( add_query_arg( 'gueta-cache-purged', '1', wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=gueta-page-cache' ) ) );
	exit;
}
add_action( 'admin_post_gueta_page_cache_purge', 'gueta_page_cache_admin_purge' );

/**
 * "ניקוי מטמון" in the admin bar, while the cache is on.
 *
 * @param WP_Admin_Bar $bar Admin bar.
 * @return void
 */
function gueta_page_cache_admin_bar( $bar ) {
	if ( ! current_user_can( 'manage_options' ) || ! gueta_page_cache_settings()['enabled'] ) {
		return;
	}

	$bar->add_node(
		[
			'id'    => 'gueta-page-cache-purge',
			'title' => 'ניקוי מטמון',
			'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=gueta_page_cache_purge' ), 'gueta_page_cache_purge' ),
		]
	);
}
add_action( 'admin_bar_menu', 'gueta_page_cache_admin_bar', 100 );

/**
 * Save and render the screen.
 *
 * @return void
 */
function gueta_page_cache_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice = '';
	$error  = '';

	if ( isset( $_POST['gueta_page_cache_save'] ) && check_admin_referer( 'gueta_page_cache_save' ) ) {
		$enabled = ! empty( $_POST['enabled'] );

		update_option(
			GUETA_PAGE_CACHE_OPTION,
			[
				'enabled' => $enabled,
				'ttl'     => isset( $_POST['ttl'] ) ? max( 3600, min( 36000, (int) $_POST['ttl'] ) ) : 28800,
				'exclude' => isset( $_POST['exclude'] ) ? sanitize_textarea_field( wp_unslash( $_POST['exclude'] ) ) : '',
			],
			true
		);

		if ( $enabled ) {
			$result = gueta_page_cache_install();

			if ( is_wp_error( $result ) ) {
				$error = $result->get_error_message();
				update_option( GUETA_PAGE_CACHE_OPTION, array_merge( gueta_page_cache_settings(), [ 'enabled' => false ] ), true );
			} else {
				$notice = 'המטמון פעיל. העמודים יישמרו עם הביקור הראשון בכל אחד מהם.';
			}
		} else {
			gueta_page_cache_uninstall();
			$notice = 'המטמון כבוי.';
		}
	}

	if ( isset( $_POST['gueta_page_cache_purge'] ) && check_admin_referer( 'gueta_page_cache_save' ) ) {
		gueta_page_cache_purge();
		$notice = 'המטמון נוקה.';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['gueta-cache-purged'] ) ) {
		$notice = 'המטמון נוקה.';
	}

	$settings  = gueta_page_cache_settings();
	$installed = gueta_page_cache_loader_is_ours();
	$stats     = gueta_page_cache_stats();
	$purged_at = (int) get_option( 'gueta_page_cache_purged_at', 0 );
	?>
	<div class="wrap">
		<h1>מטמון עמודים</h1>

		<?php if ( $notice ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>
		<?php if ( $error ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
		<?php endif; ?>
		<?php if ( $settings['enabled'] && ! $installed ) : ?>
			<div class="notice notice-warning"><p>המטמון מסומן כפעיל, אבל הקובץ שלו בתיקיית mu-plugins חסר. שמירה של ההגדרות תכתוב אותו מחדש.</p></div>
		<?php endif; ?>

		<p style="max-width:720px">
			כל עמוד באתר לוקח כשתי שניות עד שהשרת מתחיל לענות, ורובן הולכות על טעינת התוספים.
			המטמון שומר כל עמוד פעם אחת, ומגיש אותו לגולשים שאינם מחוברים ושאין להם מוצרים בסל לפני שהתוספים נטענים בכלל.
			גולש מחובר, גולש עם סל, העגלה, הקופה והחשבון נטענים תמיד מחדש.
			המטמון מתנקה לבד כשמשתנה מוצר, מחיר, מלאי, עמוד, תפריט, קטגוריה, עיצוב באלמנטור או קוד התבנית.
		</p>

		<form method="post">
			<?php wp_nonce_field( 'gueta_page_cache_save' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">מצב</th>
					<td>
						<label>
							<input type="checkbox" name="enabled" value="1" <?php checked( $settings['enabled'] ); ?>>
							הפעלת מטמון העמודים
						</label>
						<p class="description">
							<?php
							echo esc_html(
								$installed
									? sprintf( 'פעיל · %1$s עמודים שמורים · %2$s', number_format_i18n( $stats['pages'] ), size_format( $stats['bytes'] ) )
									: 'כבוי'
							);
							if ( $purged_at ) {
								echo esc_html( ' · ניקוי אחרון: ' . wp_date( 'j.n.Y H:i', $purged_at ) );
							}
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gueta-page-cache-ttl">תוקף עמוד שמור</label></th>
					<td>
						<select id="gueta-page-cache-ttl" name="ttl">
							<?php foreach ( [ 3600 => 'שעה', 14400 => '4 שעות', 28800 => '8 שעות', 36000 => '10 שעות' ] as $seconds => $label ) : ?>
								<option value="<?php echo (int) $seconds; ?>" <?php selected( $settings['ttl'], $seconds ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description">לא יותר מ־10 שעות: קוד האבטחה של טפסים והעגלה בעמוד מפסיק לעבוד אחרי 12 שעות.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="gueta-page-cache-exclude">עמודים שלא נשמרים</label></th>
					<td>
						<textarea id="gueta-page-cache-exclude" name="exclude" rows="4" class="large-text code" dir="ltr"><?php echo esc_textarea( $settings['exclude'] ); ?></textarea>
						<p class="description">כתובת או נתיב בכל שורה. העגלה, הקופה והחשבון לא נשמרים ממילא: <?php echo esc_html( implode( ', ', array_map( 'rawurldecode', gueta_page_cache_excluded_paths() ) ) ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" name="gueta_page_cache_save" value="1" class="button button-primary">שמירה</button>
				<button type="submit" name="gueta_page_cache_purge" value="1" class="button">ניקוי המטמון עכשיו</button>
			</p>
		</form>
	</div>
	<?php
}
