<?php
/**
 * Banners at the top of the shop and its category pages.
 *
 * A slider of images, each with a link and an optional second picture for a
 * phone. They are kept in one option and managed on a screen next to the
 * upsell popups.
 *
 * On sellameir the slider was printed into the footer and moved into place by
 * a script, because the grid there came out of Elementor with no hook to print
 * into. Here the grid is the [gueta_archive] shortcode, so the archive prints
 * the banners itself, in the right place from the first paint.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const GUETA_BANNERS_OPTION   = 'gueta_shop_banners';
const GUETA_BANNERS_SETTINGS = 'gueta_shop_banners_settings';

/**
 * The stored banners, cleaned.
 *
 * @param bool $only_enabled Leave out the ones switched off.
 * @return array[]
 */
function gueta_banners( $only_enabled = false ) {
	$stored  = get_option( GUETA_BANNERS_OPTION, [] );
	$banners = [];

	foreach ( is_array( $stored ) ? $stored : [] as $banner ) {
		$banner = gueta_banners_clean( $banner );

		if ( $banner && ( ! $only_enabled || $banner['enabled'] ) ) {
			$banners[] = $banner;
		}
	}

	return $banners;
}

/**
 * One banner, cleaned, or null when it has no desktop picture.
 *
 * @param mixed $banner Raw banner.
 * @return array|null
 */
function gueta_banners_clean( $banner ) {
	$image_id = is_array( $banner ) ? absint( $banner['image_id'] ?? 0 ) : 0;

	if ( ! $image_id ) {
		return null;
	}

	return [
		'image_id'  => $image_id,
		'mobile_id' => absint( $banner['mobile_id'] ?? 0 ),
		'link'      => esc_url_raw( (string) ( $banner['link'] ?? '' ) ),
		'alt'       => sanitize_text_field( (string) ( $banner['alt'] ?? '' ) ),
		'enabled'   => ! empty( $banner['enabled'] ),
	];
}

/**
 * Seconds between slides, or 0 to stay put.
 *
 * @return int
 */
function gueta_banners_autoplay() {
	$settings = get_option( GUETA_BANNERS_SETTINGS, [] );

	return isset( $settings['autoplay'] ) ? max( 0, min( 30, absint( $settings['autoplay'] ) ) ) : 6;
}

/* -------------------------------------------------------------------------
 * Admin screen
 * ---------------------------------------------------------------------- */

/**
 * The banners screen, under the upsell popups.
 *
 * @return void
 */
function gueta_banners_admin_menu() {
	add_submenu_page(
		'gueta-upsell-popups',
		'באנרים בדף החנות',
		'באנרים בדף החנות',
		'manage_woocommerce',
		'gueta-shop-banners',
		'gueta_banners_render_admin_page'
	);
}
add_action( 'admin_menu', 'gueta_banners_admin_menu', 20 );

/**
 * Save the list, then send the browser back to it.
 *
 * @return void
 */
function gueta_banners_handle_save() {
	if ( empty( $_POST['gueta_banners_save'] ) || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	check_admin_referer( 'gueta_banners_manage' );

	$rows    = isset( $_POST['gueta_banner'] ) && is_array( $_POST['gueta_banner'] ) ? wp_unslash( $_POST['gueta_banner'] ) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned per field below.
	$banners = array_values( array_filter( array_map( 'gueta_banners_clean', $rows ) ) );

	update_option( GUETA_BANNERS_OPTION, $banners, false );
	update_option(
		GUETA_BANNERS_SETTINGS,
		[ 'autoplay' => max( 0, min( 30, absint( $_POST['gueta_banners_autoplay'] ?? 6 ) ) ) ],
		false
	);

	wp_safe_redirect( admin_url( 'admin.php?page=gueta-shop-banners&saved=1' ) );
	exit;
}
add_action( 'admin_init', 'gueta_banners_handle_save' );

/**
 * The media library and the repeater, on this screen only.
 *
 * @param string $hook Current admin page.
 * @return void
 */
function gueta_banners_admin_assets( $hook ) {
	if ( false === strpos( (string) $hook, 'gueta-shop-banners' ) ) {
		return;
	}

	wp_enqueue_media();

	wp_enqueue_style(
		'gueta-banners-admin',
		get_stylesheet_directory_uri() . '/assets/css/gueta-banners-admin.css',
		[],
		gueta_asset_version( '/assets/css/gueta-banners-admin.css' )
	);

	wp_enqueue_script(
		'gueta-banners-admin',
		get_stylesheet_directory_uri() . '/assets/js/gueta-banners-admin.js',
		[ 'jquery', 'jquery-ui-sortable' ],
		gueta_asset_version( '/assets/js/gueta-banners-admin.js' ),
		true
	);
}
add_action( 'admin_enqueue_scripts', 'gueta_banners_admin_assets' );

/**
 * One picture chooser.
 *
 * @param string $index    Row index.
 * @param string $field    image_id or mobile_id.
 * @param int    $image_id Chosen attachment.
 * @param string $title    Label.
 * @return void
 */
function gueta_banners_render_picker( $index, $field, $image_id, $title ) {
	$preview = $image_id ? wp_get_attachment_image_url( $image_id, 'medium_large' ) : '';
	?>
	<div class="gueta-banner-pick">
		<span class="gueta-banner-pick__label"><?php echo esc_html( $title ); ?></span>
		<div class="gueta-banner-pick__preview<?php echo $preview ? '' : ' is-empty'; ?>">
			<?php if ( $preview ) : ?>
				<img src="<?php echo esc_url( $preview ); ?>" alt="">
			<?php endif; ?>
		</div>
		<input type="hidden" name="gueta_banner[<?php echo esc_attr( $index ); ?>][<?php echo esc_attr( $field ); ?>]" value="<?php echo $image_id ? (int) $image_id : ''; ?>">
		<button type="button" class="button gueta-banner-pick__choose">בחירת תמונה</button>
		<button type="button" class="button-link gueta-banner-pick__clear" <?php echo $preview ? '' : 'hidden'; ?>>הסרה</button>
	</div>
	<?php
}

/**
 * One editable banner.
 *
 * @param array  $banner Banner.
 * @param string $index  Row index, or __i__ in the template for new rows.
 * @return void
 */
function gueta_banners_render_row( $banner, $index ) {
	?>
	<div class="gueta-banner-row" data-banner-row>
		<span class="gueta-banner-row__handle" title="גררו כדי לשנות את הסדר" aria-hidden="true">⠿</span>

		<div class="gueta-banner-row__media">
			<?php
			gueta_banners_render_picker( $index, 'image_id', absint( $banner['image_id'] ?? 0 ), 'באנר לדסקטופ' );
			gueta_banners_render_picker( $index, 'mobile_id', absint( $banner['mobile_id'] ?? 0 ), 'באנר לטלפון (לא חובה)' );
			?>
		</div>

		<div class="gueta-banner-row__fields">
			<label>
				קישור
				<input type="url" name="gueta_banner[<?php echo esc_attr( $index ); ?>][link]" value="<?php echo esc_attr( $banner['link'] ?? '' ); ?>" placeholder="<?php echo esc_attr( home_url( '/product-category/...' ) ); ?>">
			</label>
			<label>
				תיאור התמונה (לנגישות)
				<input type="text" name="gueta_banner[<?php echo esc_attr( $index ); ?>][alt]" value="<?php echo esc_attr( $banner['alt'] ?? '' ); ?>" placeholder="למשל: 20% הנחה על דקים">
			</label>
			<label class="gueta-banner-row__toggle">
				<input type="checkbox" name="gueta_banner[<?php echo esc_attr( $index ); ?>][enabled]" value="1" <?php checked( ! isset( $banner['enabled'] ) || ! empty( $banner['enabled'] ) ); ?>>
				באנר פעיל
			</label>
			<button type="button" class="button-link button-link-delete gueta-banner-row__remove">מחיקת הבאנר</button>
		</div>
	</div>
	<?php
}

/**
 * The banners screen.
 *
 * @return void
 */
function gueta_banners_render_admin_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	?>
	<div class="wrap gueta-banners-admin">
		<h1>באנרים בדף החנות</h1>
		<p>הבאנרים מוצגים כסליידר בראש דף החנות ודפי הקטגוריות, מעל המוצרים. גודל מומלץ לדסקטופ: 2000×352 פיקסלים. בטלפון באנר כזה יורד לגובה של כששים פיקסלים ואי אפשר לקרוא אותו, ולכן כדאי להעלות לכל באנר גם גרסה לטלפון, למשל 1000×700.</p>

		<?php if ( ! empty( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p>הבאנרים נשמרו.</p></div>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'gueta_banners_manage' ); ?>
			<input type="hidden" name="gueta_banners_save" value="1">

			<div class="gueta-banner-rows" data-banner-rows>
				<?php
				foreach ( gueta_banners() as $index => $banner ) {
					gueta_banners_render_row( $banner, (string) $index );
				}
				?>
			</div>

			<p><button type="button" class="button" data-banner-add>הוספת באנר</button></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="gueta_banners_autoplay">מעבר אוטומטי</label></th>
					<td>
						כל <input type="number" min="0" max="30" id="gueta_banners_autoplay" name="gueta_banners_autoplay" value="<?php echo (int) gueta_banners_autoplay(); ?>" class="small-text"> שניות
						<p class="description">0 בלי מעבר אוטומטי. אפשר תמיד להחליק ביד או בחיצים, ואחרי זה הסליידר נשאר במקום.</p>
					</td>
				</tr>
			</table>

			<?php submit_button( 'שמירת הבאנרים' ); ?>
		</form>

		<script type="text/html" id="tmpl-gueta-banner-row">
			<?php gueta_banners_render_row( [], '__i__' ); ?>
		</script>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Front end
 * ---------------------------------------------------------------------- */

/**
 * Whether this page shows the banners: the shop and its category and tag
 * pages, not search results.
 *
 * @return bool
 */
function gueta_banners_here() {
	return gueta_has_woocommerce() && ( is_shop() || is_product_taxonomy() ) && ! is_search();
}

/**
 * The slider's stylesheet and script, early enough to style the first paint.
 *
 * @return void
 */
function gueta_banners_assets() {
	if ( ! gueta_banners_here() || ! gueta_banners( true ) ) {
		return;
	}

	$uri = get_stylesheet_directory_uri();

	wp_enqueue_style(
		'gueta-banners',
		$uri . '/assets/css/gueta-banners.css',
		[ 'gueta-header' ],
		gueta_asset_version( '/assets/css/gueta-banners.css' )
	);

	wp_enqueue_script(
		'gueta-banners',
		$uri . '/assets/js/gueta-banners.js',
		[],
		gueta_asset_version( '/assets/js/gueta-banners.js' ),
		true
	);
}
add_action( 'wp_enqueue_scripts', 'gueta_banners_assets', 30 );

/**
 * Print the slider. Called by the archive, above the category chips.
 *
 * @return void
 */
function gueta_render_shop_banners() {
	if ( ! gueta_banners_here() ) {
		return;
	}

	// A picture deleted from the media library leaves its banner behind; the
	// dots are counted from what is left, so they stay in step with the slides.
	$banners = array_values(
		array_filter(
			gueta_banners( true ),
			static function ( $banner ) {
				return (bool) wp_get_attachment_image_src( $banner['image_id'], 'full' );
			}
		)
	);

	if ( ! $banners ) {
		return;
	}

	// Already queued on these pages; this covers a template that got here another way.
	gueta_banners_assets();

	$count = count( $banners );
	?>
	<section class="gueta-banners" data-banners data-autoplay="<?php echo (int) gueta_banners_autoplay(); ?>" aria-roledescription="carousel" aria-label="מבצעים">
		<div class="gueta-banners__track" data-banners-track>
			<?php
			foreach ( $banners as $position => $banner ) :
				$alt   = $banner['alt'] ? $banner['alt'] : (string) get_post_meta( $banner['image_id'], '_wp_attachment_image_alt', true );
				$first = 0 === $position;
				$tag   = $banner['link'] ? 'a' : 'div';

				$image = wp_get_attachment_image(
					$banner['image_id'],
					'full',
					false,
					[
						'class'         => 'gueta-banners__image',
						'alt'           => $alt,
						'sizes'         => '(min-width: 1980px) 1780px, 100vw',
						'loading'       => $first ? 'eager' : 'lazy',
						'fetchpriority' => $first ? 'high' : 'auto',
					]
				);

				$mobile =$banner['mobile_id'] ? wp_get_attachment_image_src( $banner['mobile_id'], 'full' ) : null;
				?>
				<<?php echo tag_escape( $tag ); ?>
					class="gueta-banners__slide"
					<?php echo $banner['link'] ? 'href="' . esc_url( $banner['link'] ) . '"' : ''; ?>
					role="group"
					aria-roledescription="slide"
					aria-label="<?php echo esc_attr( sprintf( '%d מתוך %d', $position + 1, $count ) ); ?>"
				>
					<picture>
						<?php if ( $mobile ) : ?>
							<source
								media="(max-width: 767px)"
								srcset="<?php echo esc_attr( wp_get_attachment_image_srcset( $banner['mobile_id'], 'full' ) ?: $mobile[0] ); ?>"
								sizes="100vw"
								width="<?php echo (int) $mobile[1]; ?>"
								height="<?php echo (int) $mobile[2]; ?>"
							>
						<?php endif; ?>
						<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</picture>
				</<?php echo tag_escape( $tag ); ?>>
			<?php endforeach; ?>
		</div>

		<?php if ( $count > 1 ) : ?>
			<button type="button" class="gueta-banners__arrow gueta-banners__arrow--prev" data-banners-step="-1" aria-label="הבאנר הקודם">
				<?php echo gueta_icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
			<button type="button" class="gueta-banners__arrow gueta-banners__arrow--next" data-banners-step="1" aria-label="הבאנר הבא">
				<?php echo gueta_icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
			<div class="gueta-banners__dots">
				<?php for ( $i = 0; $i < $count; $i++ ) : ?>
					<button type="button" class="gueta-banners__dot<?php echo 0 === $i ? ' is-active' : ''; ?>" data-banners-dot="<?php echo (int) $i; ?>" aria-label="<?php echo esc_attr( sprintf( 'באנר %d', $i + 1 ) ); ?>"<?php echo 0 === $i ? ' aria-current="true"' : ''; ?>></button>
				<?php endfor; ?>
			</div>
		<?php endif; ?>
	</section>
	<?php
}
