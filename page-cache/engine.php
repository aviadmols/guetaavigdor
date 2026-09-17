<?php
/**
 * The page cache engine.
 *
 * Every page here takes about two seconds before its first byte, and nearly
 * all of that is WordPress loading its plugins; the theme's own work is a
 * tenth of it. A cache inside the theme would still wait for the plugins, so
 * this runs before them: a must-use plugin, written by the theme's page cache
 * screen, requires this file with its settings in $gueta_page_cache_config.
 *
 * A visitor who is not signed in and has nothing in the cart gets the page as
 * it was drawn for the last such visitor, straight from a file. Anyone else,
 * and any request that is not a plain page view, goes through WordPress as
 * before. A page drawn for a visitor who could have had it cached is stored on
 * the way out, if nothing in the drawing made it personal.
 *
 * Nothing here may call WordPress functions on the way in, since the plugins
 * are not loaded yet. The store at the end runs when WordPress has finished,
 * and checks what it can.
 *
 * Responses carry X-Gueta-Cache: HIT, MISS or BYPASS, with the reason for a
 * bypass, so the behaviour can be read from the headers.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) || defined( 'GUETA_PAGE_CACHE_ENGINE' ) ) {
	return;
}

define( 'GUETA_PAGE_CACHE_ENGINE', true );

/**
 * The settings the loader passed in, with defaults.
 *
 * @return array{dir:string,ttl:int,exclude:string[],query:string[]}
 */
function gueta_page_cache_config() {
	global $gueta_page_cache_config;

	$config = is_array( $gueta_page_cache_config ) ? $gueta_page_cache_config : array();

	return array(
		'dir'     => isset( $config['dir'] ) ? rtrim( (string) $config['dir'], '/\\' ) : WP_CONTENT_DIR . '/cache/gueta-pages',
		// Ten hours at most: a page carries nonces, and a nonce can stop working after twelve.
		'ttl'     => isset( $config['ttl'] ) ? max( 60, min( 36000, (int) $config['ttl'] ) ) : 28800,
		'exclude' => isset( $config['exclude'] ) ? array_values( array_filter( (array) $config['exclude'], 'strlen' ) ) : array(),
		'query'   => isset( $config['query'] ) ? array_values( (array) $config['query'] ) : array(),
	);
}

/**
 * Why this request cannot be served from or stored in the cache, if it cannot.
 *
 * @return string Reason, or '' when it can.
 */
function gueta_page_cache_bypass_reason() {
	if ( 'cli' === PHP_SAPI ) {
		return 'cli';
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET';

	if ( 'GET' !== $method && 'HEAD' !== $method ) {
		return 'method';
	}

	foreach ( array( 'WP_ADMIN', 'DOING_AJAX', 'DOING_CRON', 'REST_REQUEST', 'XMLRPC_REQUEST', 'WP_INSTALLING', 'DONOTCACHEPAGE' ) as $constant ) {
		if ( defined( $constant ) && constant( $constant ) ) {
			return strtolower( $constant );
		}
	}

	/*
	 * A signed in shopper, one with a cart or a WooCommerce session, a
	 * password protected post, a comment author: each sees a page of their
	 * own.
	 */
	foreach ( array_keys( $_COOKIE ) as $name ) {
		$name = (string) $name;

		foreach ( array( 'wordpress_logged_in_', 'wordpress_sec_', 'wp-postpass_', 'woocommerce_items_in_cart', 'woocommerce_cart_hash', 'wp_woocommerce_session_', 'comment_author_', 'gueta_no_cache' ) as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return 'cookie';
			}
		}
	}

	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
	$path = (string) parse_url( $uri, PHP_URL_PATH );
	$path = strtolower( rawurldecode( $path ) );

	foreach ( array( '/wp-admin', '/wp-login.php', '/wp-json', '/xmlrpc.php', '/wp-cron.php', '/wp-content/', '/wp-includes/', '/feed', '/comments/feed' ) as $prefix ) {
		if ( 0 === strpos( $path, $prefix ) ) {
			return 'path';
		}
	}

	if ( preg_match( '#(?:^|/)(?:feed|embed|trackback)/?$|\.(?:xml|xsl|txt|php)$#', $path ) ) {
		return 'path';
	}

	$config = gueta_page_cache_config();

	foreach ( $config['exclude'] as $prefix ) {
		$prefix = strtolower( rawurldecode( (string) $prefix ) );

		if ( '' !== $prefix && '/' !== $prefix && 0 === strpos( $path, $prefix ) ) {
			return 'excluded';
		}
	}

	if ( false === gueta_page_cache_query() ) {
		return 'query';
	}

	return '';
}

/**
 * The query string that makes a page different, sorted, or false when the
 * query cannot be cached.
 *
 * Campaign tags are dropped, since they change nothing on the page. The
 * archive's own filters are kept, each set of them its own page. Anything
 * else, a search, an add to cart, a preview, is not cached at all.
 *
 * @return string|false
 */
function gueta_page_cache_query() {
	$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/';
	$query = (string) parse_url( $uri, PHP_URL_QUERY );

	if ( '' === $query ) {
		return '';
	}

	parse_str( $query, $params );

	$config = gueta_page_cache_config();
	$kept   = array();

	foreach ( $params as $key => $value ) {
		$key = (string) $key;

		if ( preg_match( '/^(?:utm_[a-z]+|fbclid|gclid|gbraid|wbraid|msclkid|dclid|_ga|_gl|mc_cid|mc_eid|srsltid)$/', $key ) ) {
			continue;
		}

		if ( ! in_array( $key, $config['query'], true ) && 0 !== strpos( $key, 'pa_' ) ) {
			return false;
		}

		if ( is_array( $value ) ) {
			return false;
		}

		$kept[ $key ] = (string) $value;
	}

	ksort( $kept );

	return http_build_query( $kept );
}

/**
 * Where this request's page is kept.
 *
 * @return string
 */
function gueta_page_cache_file() {
	$config = gueta_page_cache_config();
	$https  = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== strtolower( (string) $_SERVER['HTTPS'] ) )
		|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $_SERVER['HTTP_X_FORWARDED_PROTO'] ) )
		|| ( isset( $_SERVER['SERVER_PORT'] ) && 443 === (int) $_SERVER['SERVER_PORT'] );
	$host   = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
	$path   = (string) parse_url( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/', PHP_URL_PATH );
	$key    = sha1( ( $https ? 'https' : 'http' ) . '|' . $host . '|' . rawurldecode( $path ) . '|' . gueta_page_cache_query() );

	return $config['dir'] . '/' . substr( $key, 0, 2 ) . '/' . $key . '.html';
}

/**
 * Serve the page from the cache, or start keeping it.
 *
 * @return void
 */
function gueta_page_cache_start() {
	$reason = gueta_page_cache_bypass_reason();

	if ( '' !== $reason ) {
		if ( ! headers_sent() && 'cli' !== $reason ) {
			header( 'X-Gueta-Cache: BYPASS ' . $reason );
		}
		return;
	}

	$config = gueta_page_cache_config();
	$file   = gueta_page_cache_file();
	$stored = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	/*
	 * Fresh enough, and newer than the last purge. The purge deletes the
	 * files, and the stamp covers a delete that has not reached them all yet.
	 */
	if ( $stored && time() - $stored < $config['ttl'] ) {
		$purged = @filemtime( $config['dir'] . '/purged' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! $purged || $stored > $purged ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
			// Browsers ask again every time, so a purge reaches them on the next view.
			header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
			header( 'X-Gueta-Cache: HIT' );
			header( 'Age: ' . max( 0, time() - $stored ) );

			if ( 'HEAD' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
				readfile( $file );
			}
			exit;
		}
	}

	header( 'X-Gueta-Cache: MISS' );
	ob_start( 'gueta_page_cache_capture' );
}

/**
 * Keep the page as it goes out, when it can be kept.
 *
 * The buffer can arrive in pieces if something flushes along the way, so it is
 * gathered until the last one. The page is only stored when it is a whole
 * HTML page, it went out with 200, nothing set a cookie, no one is signed in,
 * no WooCommerce session was started, and it is not the cart, the checkout,
 * the account, a search, a preview or a password protected post. The theme can
 * veto it through the gueta_page_cache_store filter.
 *
 * @param string $chunk Output.
 * @param int    $phase Output handler phase.
 * @return string The output, unchanged.
 */
function gueta_page_cache_capture( $chunk, $phase ) {
	static $page = '';

	$page .= $chunk;

	if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) ) {
		return $chunk;
	}

	if ( gueta_page_cache_can_store( $page ) ) {
		gueta_page_cache_write( gueta_page_cache_file(), $page );
	}

	return $chunk;
}

/**
 * Whether a finished page may be stored.
 *
 * @param string $page The whole page.
 * @return bool
 */
function gueta_page_cache_can_store( $page ) {
	if ( 200 !== http_response_code() || strlen( $page ) < 512 || false === stripos( $page, '</html>' ) ) {
		return false;
	}

	if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
		return false;
	}

	foreach ( headers_list() as $header ) {
		$header = strtolower( $header );

		if ( 0 === strpos( $header, 'set-cookie:' ) ) {
			return false;
		}

		if ( 0 === strpos( $header, 'content-type:' ) && false === strpos( $header, 'text/html' ) ) {
			return false;
		}
	}

	if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
		return false;
	}

	if ( function_exists( 'WC' ) && WC()->session && method_exists( WC()->session, 'has_session' ) && WC()->session->has_session() ) {
		return false;
	}

	foreach ( array( 'is_search', 'is_preview', 'is_feed', 'is_404', 'is_customize_preview', 'is_cart', 'is_checkout', 'is_account_page' ) as $conditional ) {
		if ( function_exists( $conditional ) && call_user_func( $conditional ) ) {
			return false;
		}
	}

	if ( function_exists( 'is_singular' ) && is_singular() && function_exists( 'post_password_required' ) && post_password_required() ) {
		return false;
	}

	if ( function_exists( 'wc_notice_count' ) && wc_notice_count() > 0 ) {
		return false;
	}

	return ! function_exists( 'apply_filters' ) || (bool) apply_filters( 'gueta_page_cache_store', true, $page );
}

/**
 * Write a page to its file, whole or not at all.
 *
 * @param string $file Target file.
 * @param string $page Page.
 * @return void
 */
function gueta_page_cache_write( $file, $page ) {
	$dir = dirname( $file );

	if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return;
	}

	$temp = $file . '.' . uniqid( '', true ) . '.tmp';

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( false === @file_put_contents( $temp, $page . "\n<!-- gueta page cache " . gmdate( 'Y-m-d H:i:s' ) . " UTC -->\n" ) ) {
		return;
	}

	if ( ! @rename( $temp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}

gueta_page_cache_start();
