<?php
/**
 * Theme settings: the header logo and the category strip.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option holding every theme setting.
 */
const GUETA_SETTINGS_OPTION = 'gueta_theme_settings';

/**
 * Every setting's default.
 *
 * @return array
 */
function gueta_settings_defaults() {
	return [
		'logo_id'          => 0,
		'logo_height'      => 62,
		'cats_enabled'     => 1,
		'cats_scope'       => 'front',
		'cats_items'       => [],
		'nav_condense'     => 1,
		'nav_items'        => [],
		'nav_tree'         => [],
		'login_required'   => 1,
		'google_client_id' => '',
	];
}

/**
 * Settings with their defaults applied.
 *
 * @return array
 */
function gueta_settings() {
	static $settings = null;

	if ( null !== $settings ) {
		return $settings;
	}

	$stored   = get_option( GUETA_SETTINGS_OPTION, [] );
	$settings = wp_parse_args( is_array( $stored ) ? $stored : [], gueta_settings_defaults() );

	return $settings;
}

/**
 * One setting.
 *
 * @param string $key     Setting key.
 * @param mixed  $default Fallback.
 * @return mixed
 */
function gueta_setting( $key, $default = null ) {
	$settings = gueta_settings();

	return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
}

/**
 * Forget the cached settings after a save.
 *
 * @return void
 */
function gueta_flush_settings_cache() {
	gueta_flush_category_cards();
	gueta_flush_nav_cache();
}
add_action( 'update_option_' . GUETA_SETTINGS_OPTION, 'gueta_flush_settings_cache' );
add_action( 'add_option_' . GUETA_SETTINGS_OPTION, 'gueta_flush_settings_cache' );

/* -------------------------------------------------------------------------
 * Admin page
 * ---------------------------------------------------------------------- */

/**
 * Register the settings page under the existing Gueta Theme menu.
 *
 * @return void
 */
function gueta_settings_menu() {
	add_submenu_page(
		'gueta-theme',
		'הגדרות תבנית',
		'הגדרות תבנית',
		'manage_options',
		'gueta-theme-settings',
		'gueta_render_settings_page'
	);
}
add_action( 'admin_menu', 'gueta_settings_menu', 11 );

/**
 * The media library picker is only needed on this screen.
 *
 * @param string $hook Current admin page.
 * @return void
 */
function gueta_settings_assets( $hook ) {
	if ( false === strpos( (string) $hook, 'gueta-theme-settings' ) ) {
		return;
	}

	wp_enqueue_media();
}
add_action( 'admin_enqueue_scripts', 'gueta_settings_assets' );

/**
 * The tabs of the settings screen, each a page of its own.
 *
 * @return array<string,string> Slug => label.
 */
function gueta_settings_tabs() {
	return [
		'general'    => 'כללי',
		'menu'       => 'תפריט',
		'categories' => 'רצועת קטגוריות',
		'login'      => 'התחברות ומיילים',
		'delivery'   => 'משלוחים',
		'tools'      => 'כלים',
	];
}

/**
 * The tab being shown.
 *
 * @return string
 */
function gueta_settings_current_tab() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';

	return array_key_exists( $tab, gueta_settings_tabs() ) ? $tab : 'general';
}

/**
 * Save and render the settings screen.
 *
 * @return void
 */
function gueta_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$tab   = gueta_settings_current_tab();
	$saved = false;

	if ( isset( $_POST['gueta_settings_submit'] ) && check_admin_referer( 'gueta_save_settings_' . $tab ) ) {
		gueta_save_settings( $_POST, $tab ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$saved = true;
	}

	$stored   = get_option( GUETA_SETTINGS_OPTION, [] );
	$settings = wp_parse_args( is_array( $stored ) ? $stored : [], gueta_settings_defaults() );
	$base     = admin_url( 'admin.php?page=gueta-theme-settings' );
	?>
	<div class="wrap">
		<h1>הגדרות תבנית</h1>

		<nav class="nav-tab-wrapper" style="margin-bottom:16px;">
			<?php foreach ( gueta_settings_tabs() as $slug => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'tab', $slug, $base ) ); ?>" class="nav-tab<?php echo $slug === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php if ( $saved ) : ?>
			<div class="notice notice-success is-dismissible"><p>ההגדרות נשמרו.</p></div>
		<?php endif; ?>

		<div class="gueta-settings-tab">
			<?php
			switch ( $tab ) {
				case 'menu':
					gueta_settings_form( $tab, 'gueta_settings_tab_menu', $settings );
					break;
				case 'categories':
					gueta_settings_form( $tab, 'gueta_settings_tab_categories', $settings );
					break;
				case 'login':
					gueta_settings_form( $tab, 'gueta_settings_tab_login', $settings );
					gueta_smtp_admin_section();
					break;
				case 'delivery':
					gueta_delivery_admin_section();
					gueta_cities_admin_section();
					break;
				case 'tools':
					gueta_search_index_admin_section();
					gueta_merchant_feed_admin_section();
					gueta_font_migrate_admin_section();
					break;
				default:
					gueta_settings_form( $tab, 'gueta_settings_tab_general', $settings );
			}
			?>
		</div>
	</div>

	<style>
		/* Each section opens with a rule to part it from the one above; the first needs none. */
		.gueta-settings-tab > hr:first-child { display: none; }
	</style>
	<?php
}

/**
 * One tab's form around its fields.
 *
 * @param string   $tab      Tab slug.
 * @param callable $fields   Prints the fields.
 * @param array    $settings Current settings.
 * @return void
 */
function gueta_settings_form( $tab, $fields, $settings ) {
	?>
	<form method="post">
		<?php wp_nonce_field( 'gueta_save_settings_' . $tab ); ?>
		<?php call_user_func( $fields, $settings ); ?>
		<?php submit_button( 'שמירת ההגדרות', 'primary', 'gueta_settings_submit' ); ?>
	</form>
	<?php
}

/**
 * General: the logo.
 *
 * @param array $settings Current settings.
 * @return void
 */
function gueta_settings_tab_general( $settings ) {
	$logo_url = $settings['logo_id'] ? wp_get_attachment_image_url( (int) $settings['logo_id'], 'medium' ) : '';
	?>
	<h2>לוגו</h2>
	<p>הלוגו שמוצג בהדר. אם לא נבחר כאן, התבנית תשתמש בלוגו מ"מראה ← התאמה אישית ← זהות האתר", ואם גם הוא ריק יוצג שם האתר.</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label>תמונת הלוגו</label></th>
			<td>
				<div id="gueta-logo-preview" style="margin-bottom:10px;<?php echo $logo_url ? '' : 'display:none;'; ?>">
					<img src="<?php echo esc_url( $logo_url ); ?>" alt="" style="max-height:80px;width:auto;background:#f6f7f7;padding:8px;border:1px solid #dcdcde;">
				</div>
				<input type="hidden" name="logo_id" id="gueta-logo-id" value="<?php echo (int) $settings['logo_id']; ?>">
				<button type="button" class="button" id="gueta-logo-select">בחירת לוגו</button>
				<button type="button" class="button" id="gueta-logo-clear" <?php echo $settings['logo_id'] ? '' : 'style="display:none;"'; ?>>הסרה</button>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="gueta-logo-height">גובה מרבי בדסקטופ</label></th>
			<td>
				<input type="number" id="gueta-logo-height" name="logo_height" min="20" max="160" value="<?php echo (int) $settings['logo_height']; ?>" class="small-text"> פיקסלים
				<p class="description">במובייל הגובה מוקטן אוטומטית.</p>
			</td>
		</tr>
	</table>

	<script>
	jQuery(function ($) {
		var frame;

		$('#gueta-logo-select').on('click', function (event) {
			event.preventDefault();

			if (frame) {
				frame.open();
				return;
			}

			frame = wp.media({ title: 'בחירת לוגו', button: { text: 'בחירה' }, multiple: false });

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				var url = attachment.sizes && attachment.sizes.medium ? attachment.sizes.medium.url : attachment.url;

				$('#gueta-logo-id').val(attachment.id);
				$('#gueta-logo-preview').show().find('img').attr('src', url);
				$('#gueta-logo-clear').show();
			});

			frame.open();
		});

		$('#gueta-logo-clear').on('click', function (event) {
			event.preventDefault();
			$('#gueta-logo-id').val('');
			$('#gueta-logo-preview').hide();
			$(this).hide();
		});
	});
	</script>
	<?php
}

/**
 * Menu: how the bar behaves and which categories it holds.
 *
 * @param array $settings Current settings.
 * @return void
 */
function gueta_settings_tab_menu( $settings ) {
	?>
	<h2>תפריט הניווט</h2>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">גלילה</th>
			<td>
				<label>
					<input type="checkbox" name="nav_condense" value="1" <?php checked( ! empty( $settings['nav_condense'] ) ); ?>>
					לקפל את שורת התפריט כשגוללים למטה
				</label>
				<p class="description">כשמכובה, שורת התפריט נשארת גלויה בכל הגלילה.</p>
			</td>
		</tr>
	</table>

	<h3>פריטי התפריט</h3>

	<?php if ( ! empty( get_nav_menu_locations()['primary'] ) ) : ?>
		<div class="notice notice-warning inline"><p>
			לתפריט "Primary navigation" משויך גם תפריט וורדפרס (<a href="<?php echo esc_url( admin_url( 'nav-menus.php?action=locations' ) ); ?>">מראה ← תפריטים</a>).
			מרגע ששומרים כאן, האתר מציג את התפריט מהעורך הזה ולא אותו.
		</p></div>
	<?php endif; ?>

	<?php gueta_nav_editor_render(); ?>
	<?php
}

/**
 * Categories: the strip under the header.
 *
 * @param array $settings Current settings.
 * @return void
 */
function gueta_settings_tab_categories( $settings ) {
	$terms = gueta_has_woocommerce() ? gueta_top_categories() : [];
	$items = is_array( $settings['cats_items'] ) ? $settings['cats_items'] : [];
	?>
	<h2>רצועת הקטגוריות</h2>
	<p>הרצועה שמוצגת מתחת להדר.</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">תצוגה</th>
			<td>
				<label>
					<input type="checkbox" name="cats_enabled" value="1" <?php checked( ! empty( $settings['cats_enabled'] ) ); ?>>
					להציג את רצועת הקטגוריות
				</label>
			</td>
		</tr>
		<tr>
			<th scope="row">היכן להציג</th>
			<td>
				<label style="margin-inline-end:16px;">
					<input type="radio" name="cats_scope" value="front" <?php checked( $settings['cats_scope'], 'front' ); ?>>
					בדף הבית בלבד
				</label>
				<label>
					<input type="radio" name="cats_scope" value="all" <?php checked( $settings['cats_scope'], 'all' ); ?>>
					בכל האתר
				</label>
			</td>
		</tr>
	</table>

	<h3>הכרטיסיות</h3>

	<?php if ( ! $terms ) : ?>
		<p>לא נמצאו קטגוריות מוצרים.</p>
	<?php else : ?>
		<p>בחרו אילו קטגוריות יוצגו, באיזה סדר, ואם רוצים כותרת אחרת מזו של הקטגוריה. קטגוריה בלי תמונה לא תוצג ברצועה.</p>
		<table class="widefat striped" style="max-width:900px;">
			<thead>
				<tr>
					<th style="width:70px;">להציג</th>
					<th style="width:80px;">סדר</th>
					<th>קטגוריה</th>
					<th style="width:90px;">תמונה</th>
					<th>כותרת מותאמת</th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $terms as $index => $term ) :
					$id      = (int) $term->term_id;
					$item    = isset( $items[ $id ] ) && is_array( $items[ $id ] ) ? $items[ $id ] : [];
					$include = array_key_exists( 'include', $item ) ? ! empty( $item['include'] ) : true;
					$order   = isset( $item['order'] ) ? (int) $item['order'] : ( $index + 1 ) * 10;
					$title   = isset( $item['title'] ) ? (string) $item['title'] : '';
					$has_img = (bool) get_term_meta( $id, 'thumbnail_id', true );
					?>
					<tr>
						<td>
							<input type="checkbox" name="cats_items[<?php echo $id; ?>][include]" value="1" <?php checked( $include ); ?>>
						</td>
						<td>
							<input type="number" name="cats_items[<?php echo $id; ?>][order]" value="<?php echo $order; ?>" class="small-text" step="10">
						</td>
						<td>
							<strong><?php echo esc_html( $term->name ); ?></strong>
							<span style="color:#787c82;">(<?php echo esc_html( number_format_i18n( gueta_term_product_count( $term ) ) ); ?>)</span>
						</td>
						<td>
							<?php if ( $has_img ) : ?>
								<span style="color:#008a20;">יש</span>
							<?php else : ?>
								<a href="<?php echo esc_url( admin_url( 'term.php?taxonomy=product_cat&tag_ID=' . $id . '&post_type=product' ) ); ?>">להוסיף</a>
							<?php endif; ?>
						</td>
						<td>
							<input type="text" name="cats_items[<?php echo $id; ?>][title]" value="<?php echo esc_attr( $title ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $term->name ); ?>">
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}

/**
 * Login: the step before the checkout.
 *
 * @param array $settings Current settings.
 * @return void
 */
function gueta_settings_tab_login( $settings ) {
	?>
	<h2>התחברות לפני התשלום</h2>
	<p>לקוח שלא מחובר ועובר לתשלום יתבקש להתחבר קודם: בקוד בן 6 ספרות שנשלח למייל, או עם חשבון Google. כתובת חדשה פותחת חשבון לקוח אוטומטית. חשבונות של צוות האתר מתחברים בדף ההתחברות הרגיל.</p>

	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">חובת התחברות</th>
			<td>
				<label>
					<input type="checkbox" name="login_required" value="1" <?php checked( ! empty( $settings['login_required'] ) ); ?>>
					לבקש התחברות לפני התשלום
				</label>
				<p class="description">כשמכובה, אפשר לשלם גם בלי חשבון, כמו קודם.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="gueta-google-client-id">Google Client ID</label></th>
			<td>
				<input type="text" id="gueta-google-client-id" name="google_client_id" value="<?php echo esc_attr( $settings['google_client_id'] ); ?>" class="large-text code" dir="ltr" placeholder="1234567890-xxxx.apps.googleusercontent.com">
				<p class="description">
					בלי מזהה, הכפתור של Google לא יוצג ונשארת רק ההתחברות בקוד במייל.
					יוצרים אותו ב-<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>:
					Create credentials ← OAuth client ID ← Web application, וב-Authorized JavaScript origins מוסיפים את <code><?php echo esc_html( wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST ) ); ?></code>.
				</p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Sanitise a table of category rows: include, order and a custom title.
 *
 * @param mixed $rows Raw rows keyed by term id.
 * @return array
 */
function gueta_sanitize_category_rows( $rows ) {
	$items = [];

	if ( ! is_array( $rows ) ) {
		return $items;
	}

	foreach ( $rows as $term_id => $item ) {
		$term_id = absint( $term_id );

		if ( ! $term_id || ! is_array( $item ) ) {
			continue;
		}

		$items[ $term_id ] = [
			'include' => ! empty( $item['include'] ) ? 1 : 0,
			'order'   => isset( $item['order'] ) ? (int) $item['order'] : 0,
			'title'   => isset( $item['title'] ) ? sanitize_text_field( wp_unslash( $item['title'] ) ) : '',
		];
	}

	return $items;
}

/**
 * Sanitise and store one tab's settings, leaving the other tabs' as they were.
 *
 * @param array  $source Raw form data.
 * @param string $tab    The tab the form came from.
 * @return void
 */
function gueta_save_settings( $source, $tab ) {
	switch ( $tab ) {
		case 'general':
			$changes = [
				'logo_id'     => isset( $source['logo_id'] ) ? absint( $source['logo_id'] ) : 0,
				'logo_height' => isset( $source['logo_height'] ) ? min( 160, max( 20, (int) $source['logo_height'] ) ) : 62,
			];
			break;

		case 'menu':
			$changes = [
				'nav_condense' => ! empty( $source['nav_condense'] ) ? 1 : 0,
			];

			// Without the editor's script the field comes back empty; keep the saved menu then.
			if ( isset( $source['nav_tree'] ) && '' !== trim( (string) $source['nav_tree'] ) ) {
				$changes['nav_tree'] = gueta_sanitize_nav_tree( $source['nav_tree'] );
			}
			break;

		case 'categories':
			$scope   = isset( $source['cats_scope'] ) ? sanitize_key( wp_unslash( $source['cats_scope'] ) ) : 'front';
			$changes = [
				'cats_enabled' => ! empty( $source['cats_enabled'] ) ? 1 : 0,
				'cats_scope'   => in_array( $scope, [ 'front', 'all' ], true ) ? $scope : 'front',
				'cats_items'   => gueta_sanitize_category_rows( $source['cats_items'] ?? [] ),
			];
			break;

		case 'login':
			$changes = [
				'login_required'   => ! empty( $source['login_required'] ) ? 1 : 0,
				'google_client_id' => isset( $source['google_client_id'] ) ? sanitize_text_field( wp_unslash( $source['google_client_id'] ) ) : '',
			];
			break;

		default:
			return;
	}

	$stored = get_option( GUETA_SETTINGS_OPTION, [] );

	update_option( GUETA_SETTINGS_OPTION, array_merge( is_array( $stored ) ? $stored : [], $changes ) );
}

/* -------------------------------------------------------------------------
 * Applying the settings
 * ---------------------------------------------------------------------- */

/**
 * Expose the configured logo height to CSS.
 *
 * @return void
 */
function gueta_settings_inline_css() {
	$height = (int) gueta_setting( 'logo_height', 62 );

	if ( 62 === $height ) {
		return;
	}

	wp_add_inline_style(
		'gueta-header',
		sprintf( ':root{--gueta-logo-height:%dpx}', $height )
	);
}
add_action( 'wp_enqueue_scripts', 'gueta_settings_inline_css', 30 );
