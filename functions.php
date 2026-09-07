<?php
/**
 * Theme functions and definitions.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * https://developers.elementor.com/docs/hello-elementor-theme/
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'HELLO_ELEMENTOR_CHILD_VERSION', '2.1.0' );

/**
 * The site's typeface.
 *
 * Google Sans covers Hebrew and Latin in one family. The weights asked for
 * are the ones the theme actually uses: 600 does most of the work, with 400,
 * 500 and 700 around it. Italics are left out, as the design has none and
 * Hebrew has no italic tradition to draw on.
 */
const GUETA_FONT_URL = 'https://fonts.googleapis.com/css2?family=Google+Sans:wght@400;500;600;700&display=swap';

require_once get_stylesheet_directory() . '/inc/gueta-header.php';
require_once get_stylesheet_directory() . '/inc/gueta-search.php';
require_once get_stylesheet_directory() . '/inc/gueta-search-index.php';
require_once get_stylesheet_directory() . '/inc/gueta-cart.php';
require_once get_stylesheet_directory() . '/inc/gueta-categories.php';
require_once get_stylesheet_directory() . '/inc/gueta-product.php';
require_once get_stylesheet_directory() . '/inc/gueta-buy.php';
require_once get_stylesheet_directory() . '/inc/gueta-compare.php';
require_once get_stylesheet_directory() . '/inc/gueta-archive.php';
require_once get_stylesheet_directory() . '/inc/gueta-settings.php';
require_once get_stylesheet_directory() . '/inc/gueta-font-migrate.php';
require_once get_stylesheet_directory() . '/inc/gueta-cities.php';
require_once get_stylesheet_directory() . '/inc/gueta-checkout.php';
require_once get_stylesheet_directory() . '/inc/gueta-blog.php';
require_once get_stylesheet_directory() . '/inc/gueta-template.php';

/**
 * Load child theme scripts & styles.
 *
 * The header adds its own assets on top of this in gueta_header_assets().
 *
 * @return void
 */
function hello_elementor_child_scripts_styles() {

	// No version string: the URL already names every weight it serves.
	wp_enqueue_style( 'gueta-font', GUETA_FONT_URL, [], null );

	wp_enqueue_style(
		'hello-elementor-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		[
			'hello-elementor-theme-style',
			'gueta-font',
		],
		HELLO_ELEMENTOR_CHILD_VERSION
	);

}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20 );

/**
 * Register the authenticated bridge used by external development tools.
 *
 * @return void
 */
function gueta_register_development_bridge() {
	register_rest_route(
		'gueta/v1',
		'/context',
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'gueta_development_context',
			'permission_callback' => 'gueta_development_bridge_permission',
		]
	);

	register_rest_route(
		'gueta/v1',
		'/posts',
		[
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'gueta_development_list_posts',
				'permission_callback' => 'gueta_development_bridge_permission',
			],
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => 'gueta_development_create_post',
				'permission_callback' => 'gueta_development_bridge_write_permission',
			],
		]
	);
}
add_action( 'rest_api_init', 'gueta_register_development_bridge' );

/**
 * Authenticate requests with the token generated in the admin area.
 *
 * @return bool|WP_Error
 */
function gueta_development_bridge_permission() {
	$token_hash = get_option( 'gueta_development_token_hash' );
	$authorization = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ) : '';

	if ( ! $token_hash || ! preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ) {
		return new WP_Error( 'gueta_bridge_unauthorized', 'A valid bearer token is required.', [ 'status' => 401 ] );
	}

	if ( ! hash_equals( $token_hash, hash( 'sha256', $matches[1] ) ) ) {
		return new WP_Error( 'gueta_bridge_unauthorized', 'A valid bearer token is required.', [ 'status' => 401 ] );
	}

	return true;
}

/**
 * Require both the bridge token and an explicit write opt-in.
 *
 * @return bool|WP_Error
 */
function gueta_development_bridge_write_permission() {
	$permission = gueta_development_bridge_permission();

	if ( is_wp_error( $permission ) ) {
		return $permission;
	}

	if ( '1' !== get_option( 'gueta_development_write_enabled', '0' ) ) {
		return new WP_Error( 'gueta_bridge_writes_disabled', 'Write access is disabled in the site settings.', [ 'status' => 403 ] );
	}

	return true;
}

/**
 * Return non-sensitive site information for an authenticated tool.
 *
 * @return WP_REST_Response
 */
function gueta_development_context() {
	$post_types = [];

	foreach ( get_post_types( [ 'show_in_rest' => true ], 'objects' ) as $post_type ) {
		$post_types[] = [
			'name'  => $post_type->name,
			'label' => $post_type->label,
		];
	}

	return rest_ensure_response(
		[
			'site_url'       => home_url( '/' ),
			'wordpress'      => get_bloginfo( 'version' ),
			'active_theme'   => wp_get_theme()->get( 'Name' ),
			'post_types'     => $post_types,
			'elementor'      => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null,
		]
	);
}

/**
 * List published content through the normal WordPress API.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function gueta_development_list_posts( WP_REST_Request $request ) {
	$post_type = sanitize_key( $request->get_param( 'post_type' ) ?: 'post' );
	$per_page  = min( 50, max( 1, absint( $request->get_param( 'per_page' ) ?: 20 ) ) );

	$query = new WP_Query(
		[
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
		]
	);

	return rest_ensure_response(
		array_map(
			static function ( $post ) {
				return [
					'id'      => $post->ID,
					'title'   => get_the_title( $post ),
					'content' => apply_filters( 'the_content', $post->post_content ),
					'url'     => get_permalink( $post ),
				];
			},
			$query->posts
		)
	);
}

/**
 * Create a post using an allowlisted set of fields.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function gueta_development_create_post( WP_REST_Request $request ) {
	$params = $request->get_json_params();
	$title  = isset( $params['title'] ) ? sanitize_text_field( $params['title'] ) : '';
	$content = isset( $params['content'] ) ? wp_kses_post( $params['content'] ) : '';
	$type   = isset( $params['post_type'] ) ? sanitize_key( $params['post_type'] ) : 'post';

	if ( ! $title || ! post_type_exists( $type ) || ! post_type_supports( $type, 'editor' ) ) {
		return new WP_Error( 'gueta_bridge_invalid_post', 'A valid title and editable post type are required.', [ 'status' => 400 ] );
	}

	$post_id = wp_insert_post(
		[
			'post_title'   => $title,
			'post_content' => $content,
			'post_type'    => $type,
			'post_status'  => 'draft',
		],
		true
	);

	if ( is_wp_error( $post_id ) ) {
		return $post_id;
	}

	return new WP_REST_Response( [ 'id' => $post_id, 'status' => 'draft' ], 201 );
}

/**
 * Add the theme management area to the WordPress admin menu.
 *
 * @return void
 */
function gueta_theme_admin_menu() {
	add_menu_page(
		'Gueta Theme',
		'Gueta Theme',
		'manage_options',
		'gueta-theme',
		'gueta_development_bridge_page',
		'dashicons-admin-customizer',
		58
	);
	add_submenu_page( 'gueta-theme', 'Connect Site', 'Connect Site', 'manage_options', 'gueta-development-bridge', 'gueta_development_bridge_page' );
}
add_action( 'admin_menu', 'gueta_theme_admin_menu' );

/**
 * Render settings and generate a token once requested.
 *
 * @return void
 */
function gueta_development_bridge_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$new_token = '';
	if ( isset( $_POST['gueta_generate_token'] ) && check_admin_referer( 'gueta_generate_token' ) ) {
		$new_token = 'gueta_' . bin2hex( random_bytes( 32 ) );
		update_option( 'gueta_development_token_hash', hash( 'sha256', $new_token ), false );
	}

	if ( isset( $_POST['gueta_revoke_token'] ) && check_admin_referer( 'gueta_revoke_token' ) ) {
		delete_option( 'gueta_development_token_hash' );
	}

	if ( isset( $_POST['gueta_write_enabled'] ) && check_admin_referer( 'gueta_write_settings' ) ) {
		update_option( 'gueta_development_write_enabled', '1', false );
	} elseif ( isset( $_POST['gueta_write_settings'] ) && check_admin_referer( 'gueta_write_settings' ) ) {
		update_option( 'gueta_development_write_enabled', '0', false );
	}

	$connection_enabled = (bool) get_option( 'gueta_development_token_hash' );
	$write_enabled      = '1' === get_option( 'gueta_development_write_enabled', '0' );
	?>
	<div class="wrap">
		<h1>Gueta Theme</h1>
		<p>Manage the theme connection and the development tools that can work with this site.</p>
		<hr>
		<h2>Connect this site</h2>
		<p>Generate a private connection token for an approved development tool. The token is shown only once.</p>
		<?php if ( $new_token ) : ?>
			<div class="notice notice-success inline">
				<p><strong>Connection created.</strong> Copy this token now; it will not be shown again.</p>
				<p><code style="user-select:all;display:block;padding:12px;"><?php echo esc_html( $new_token ); ?></code></p>
			</div>
		<?php endif; ?>
		<table class="widefat striped" style="max-width:760px;">
			<tbody>
				<tr><td><strong>Status</strong></td><td><?php echo $connection_enabled ? '<span class="dashicons dashicons-yes-alt" style="color:#008a20;"></span> Connected' : '<span class="dashicons dashicons-minus"></span> Not connected'; ?></td></tr>
				<tr><td><strong>Connection endpoint</strong></td><td><code><?php echo esc_html( rest_url( 'gueta/v1/context' ) ); ?></code></td></tr>
			</tbody>
		</table>
		<form method="post" style="margin-top:16px;">
			<?php wp_nonce_field( 'gueta_generate_token' ); ?>
			<?php submit_button( $connection_enabled ? 'Reconnect site' : 'Connect site', 'primary', 'gueta_generate_token', false ); ?>
		</form>
		<form method="post">
			<?php wp_nonce_field( 'gueta_revoke_token' ); ?>
			<?php submit_button( 'Disconnect site', 'delete', 'gueta_revoke_token', false ); ?>
		</form>
		<hr>
		<h2>Permissions</h2>
		<p>Read access is available to an authenticated tool. Write access creates drafts only and is disabled by default.</p>
		<form method="post">
			<?php wp_nonce_field( 'gueta_write_settings' ); ?>
			<input type="hidden" name="gueta_write_settings" value="1">
			<label><input type="checkbox" name="gueta_write_enabled" value="1" <?php checked( $write_enabled ); ?>> Enable draft creation through the connection</label>
			<?php submit_button( 'Save permissions', 'secondary', 'submit', false ); ?>
		</form>
	</div>
	<?php
}

/**
 * Open the connection to the font host while the page is still parsing, so
 * the first paint does not wait on a fresh TLS handshake.
 *
 * @param array  $hints    URLs for this relation.
 * @param string $relation Link relation.
 * @return array
 */
function gueta_font_resource_hints( $hints, $relation ) {
	if ( 'preconnect' === $relation ) {
		$hints[] = [
			'href'        => 'https://fonts.gstatic.com',
			'crossorigin' => 'anonymous',
		];
	}

	return $hints;
}
add_filter( 'wp_resource_hints', 'gueta_font_resource_hints', 10, 2 );

/**
 * Hold the typeface across pages built in Elementor.
 *
 * Elementor writes its global typography as `.elementor-kit-123 { font-family }`
 * with that class sitting on the body element. A plain `body` rule loses to it
 * on specificity no matter which stylesheet loads last, so every selector here
 * is prefixed with `body[class]`, which outranks a single class by one and
 * settles it.
 *
 * Specificity alone was not enough. This site already carries a global font
 * declared with !important, which outranks any selector however specific, so
 * the declaration below is marked the same way. That is blunt: a font chosen
 * for one particular widget in the editor is overridden too. Removing the
 * !important on the other side, by pointing Elementor's global font at Google
 * Sans in Site Settings, is the tidier home for this and lets this rule go
 * back to plain specificity.
 *
 * Icon elements are left out entirely: they are fonts too, and renaming their
 * family would turn the glyphs into letters.
 *
 * @return void
 */
function gueta_typography_override() {
	wp_register_style( 'gueta-typography', false, [], HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_style( 'gueta-typography' );

	$targets = [
		'body[class]',
		'body[class] button',
		'body[class] input',
		'body[class] select',
		'body[class] optgroup',
		'body[class] textarea',
		'body[class] h1',
		'body[class] h2',
		'body[class] h3',
		'body[class] h4',
		'body[class] h5',
		'body[class] h6',
		'body[class] .elementor-heading-title',
		'body[class] .elementor-button',
		'body[class] .elementor-widget-text-editor',
	];

	wp_add_inline_style(
		'gueta-typography',
		implode( ',', $targets ) . '{font-family:var(--gueta-font,"Google Sans",Heebo,Arial,sans-serif)!important}'
	);
}
add_action( 'wp_enqueue_scripts', 'gueta_typography_override', 100 );
