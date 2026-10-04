<?php
/**
 * Log in before paying: a code sent by email, or Google.
 *
 * A guest who reaches the checkout sees a short login step in its place. There
 * is no password to make up or forget: the shop emails a six digit code, or
 * Google vouches for the address. An address the shop has not seen before
 * becomes a customer account on the spot, so a first order is one step longer
 * and every later one is shorter.
 *
 * The step is the whole page rather than a box inside the checkout, because
 * the checkout here is Elementor's widget and its markup is not ours to edit.
 * The server refuses a guest's order as well, so the step cannot be skipped by
 * posting the form directly.
 *
 * Staff accounts are left to the ordinary login page. A code in an inbox is as
 * strong as a password reset, which is fine for a customer and not what should
 * open an account that can edit the shop.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How long an emailed code stays good, in seconds.
 */
const GUETA_LOGIN_CODE_TTL = 10 * MINUTE_IN_SECONDS;

/**
 * Seconds before the same address may be sent another code.
 */
const GUETA_LOGIN_RESEND_WAIT = 60;

/**
 * Wrong guesses allowed before a code is thrown away.
 */
const GUETA_LOGIN_MAX_TRIES = 5;

/**
 * Codes one IP address may ask for in an hour.
 */
const GUETA_LOGIN_IP_HOURLY = 10;

/**
 * Whether guests must log in before the checkout.
 *
 * @return bool
 */
function gueta_login_required() {
	return gueta_has_woocommerce() && (bool) gueta_setting( 'login_required', 1 );
}

/**
 * The Google client ID, or an empty string when Google login is off.
 *
 * @return string
 */
function gueta_login_google_client_id() {
	return trim( (string) gueta_setting( 'google_client_id', '' ) );
}

/**
 * Whether this request is the checkout a guest must log in for. The thank you
 * page and the pay-for-order page are reached from an order already placed,
 * so they stay open.
 *
 * @return bool
 */
function gueta_login_gate_applies() {
	if ( ! gueta_login_required() || is_user_logged_in() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
		return false;
	}

	return ! is_wc_endpoint_url( 'order-received' ) && ! is_wc_endpoint_url( 'order-pay' );
}

/* -------------------------------------------------------------------------
 * The step itself
 * ---------------------------------------------------------------------- */

/**
 * Show the login step in place of the checkout.
 *
 * Runs after WooCommerce has sent an empty cart back to the cart page.
 *
 * @return void
 */
function gueta_login_gate() {
	if ( ! gueta_login_gate_applies() ) {
		return;
	}

	nocache_headers();
	get_header();
	gueta_render_login_gate();
	get_footer();
	exit;
}
add_action( 'template_redirect', 'gueta_login_gate', 20 );

/**
 * The login step's markup.
 *
 * @return void
 */
function gueta_render_login_gate() {
	$google = gueta_login_google_client_id();
	?>
	<main class="gueta-login" id="content">
		<div class="gueta-login__card" data-gueta-login>
			<h1 class="gueta-login__title">התחברות לפני התשלום</h1>
			<p class="gueta-login__lead">כדי שנוכל לעדכן אתכם על ההזמנה ולשמור את הפרטים לפעם הבאה. בלי סיסמה.</p>

			<?php if ( $google ) : ?>
				<div class="gueta-login__google" data-login-google></div>
				<p class="gueta-login__or" data-login-or><span>או עם קוד במייל</span></p>
			<?php endif; ?>

			<form class="gueta-login__step" data-login-step="email" novalidate>
				<label class="gueta-login__label" for="gueta-login-email">כתובת מייל</label>
				<input class="gueta-login__input" type="email" id="gueta-login-email" name="email" autocomplete="email" inputmode="email" dir="ltr" required>
				<button type="submit" class="gueta-button gueta-button--solid">שליחת קוד אימות</button>
			</form>

			<form class="gueta-login__step" data-login-step="code" novalidate hidden>
				<p class="gueta-login__sent">שלחנו קוד בן 6 ספרות אל <strong dir="ltr" data-login-address></strong></p>
				<label class="gueta-login__label" for="gueta-login-code">קוד האימות</label>
				<input class="gueta-login__input gueta-login__input--code" type="text" id="gueta-login-code" name="code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" dir="ltr" required>
				<button type="submit" class="gueta-button gueta-button--solid">התחברות והמשך לתשלום</button>
				<div class="gueta-login__links">
					<button type="button" class="gueta-login__link" data-login-resend>שליחת קוד חדש</button>
					<button type="button" class="gueta-login__link" data-login-change>שינוי כתובת</button>
				</div>
			</form>

			<p class="gueta-login__message" data-login-message role="alert" aria-live="polite"></p>

			<p class="gueta-login__back"><a href="<?php echo esc_url( wc_get_cart_url() ); ?>">חזרה לסל</a></p>
		</div>
	</main>
	<?php
}

/**
 * The step's stylesheet and script, and Google's when it is on.
 *
 * @return void
 */
function gueta_login_assets() {
	if ( ! gueta_login_gate_applies() ) {
		return;
	}

	$uri    = get_stylesheet_directory_uri();
	$google = gueta_login_google_client_id();

	wp_enqueue_style(
		'gueta-login',
		$uri . '/assets/css/gueta-login.css',
		[ 'gueta-header' ],
		gueta_asset_version( '/assets/css/gueta-login.css' )
	);

	wp_enqueue_script(
		'gueta-login',
		$uri . '/assets/js/gueta-login.js',
		[],
		gueta_asset_version( '/assets/js/gueta-login.js' ),
		true
	);

	if ( $google ) {
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( 'gueta-google-gsi', 'https://accounts.google.com/gsi/client', [], null, true );
		wp_script_add_data( 'gueta-google-gsi', 'strategy', 'async' );
	}

	wp_localize_script(
		'gueta-login',
		'guetaLogin',
		[
			'endpoint' => WC_AJAX::get_endpoint( '%%endpoint%%' ),
			'nonce'    => wp_create_nonce( 'gueta_login' ),
			'google'   => $google,
			'redirect' => wc_get_checkout_url(),
			'strings'  => [
				'email'   => 'נא להזין כתובת מייל תקינה',
				'code'    => 'נא להזין את 6 הספרות שקיבלתם',
				'sending' => 'שולחים...',
				'resent'  => 'שלחנו קוד חדש',
				'error'   => 'משהו השתבש. נסו שוב בעוד רגע.',
			],
		]
	);
}
add_action( 'wp_enqueue_scripts', 'gueta_login_assets', 30 );

/* -------------------------------------------------------------------------
 * The code by email
 * ---------------------------------------------------------------------- */

/**
 * Where a code waits for the address it was sent to.
 *
 * @param string $email Email address.
 * @return string
 */
function gueta_login_code_key( $email ) {
	return 'gueta_login_code_' . md5( strtolower( $email ) );
}

/**
 * A code as it is stored: keyed, so a leaked row does not give the code away.
 *
 * @param string $email Email address.
 * @param string $code  Code.
 * @return string
 */
function gueta_login_code_hash( $email, $code ) {
	return hash_hmac( 'sha256', strtolower( $email ) . '|' . $code, wp_salt( 'auth' ) );
}

/**
 * The visitor's address, for the hourly limit. WooCommerce reads it through
 * the host's proxy headers, where REMOTE_ADDR would put every shopper behind
 * the proxy under one shared limit.
 *
 * @return string
 */
function gueta_login_ip() {
	return WC_Geolocation::get_ip_address();
}

/**
 * Stop a request that did not come from the login step.
 *
 * @return void
 */
function gueta_login_check_request() {
	if ( ! check_ajax_referer( 'gueta_login', 'nonce', false ) ) {
		wp_send_json_error( [ 'message' => 'פג תוקף הדף. רעננו אותו ונסו שוב.' ], 403 );
	}

	if ( is_user_logged_in() ) {
		wp_send_json_success( [ 'redirect' => wc_get_checkout_url() ] );
	}
}

/**
 * The posted email, or an error back to the browser.
 *
 * @return string
 */
function gueta_login_posted_email() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in gueta_login_check_request().
	$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

	if ( ! is_email( $email ) ) {
		wp_send_json_error( [ 'message' => 'נא להזין כתובת מייל תקינה' ], 400 );
	}

	return $email;
}

/**
 * Email a fresh code.
 *
 * @return void
 */
function gueta_ajax_login_send_code() {
	gueta_login_check_request();

	$email = gueta_login_posted_email();
	$user  = get_user_by( 'email', $email );

	if ( $user && gueta_login_is_staff( $user ) ) {
		gueta_login_staff_error();
	}

	$key     = gueta_login_code_key( $email );
	$pending = get_transient( $key );

	if ( is_array( $pending ) && time() - (int) $pending['sent'] < GUETA_LOGIN_RESEND_WAIT ) {
		$wait = GUETA_LOGIN_RESEND_WAIT - ( time() - (int) $pending['sent'] );
		wp_send_json_error( [ 'message' => sprintf( 'אפשר לבקש קוד חדש בעוד %d שניות', $wait ) ], 429 );
	}

	$ip_key = 'gueta_login_ip_' . md5( gueta_login_ip() );
	$sends  = (int) get_transient( $ip_key );

	if ( $sends >= GUETA_LOGIN_IP_HOURLY ) {
		wp_send_json_error( [ 'message' => 'נשלחו יותר מדי קודים. נסו שוב בעוד שעה.' ], 429 );
	}

	$code = (string) random_int( 100000, 999999 );

	$sent = wp_mail(
		$email,
		'קוד האימות שלך: ' . $code,
		"קוד האימות שלך להתחברות לאתר הוא:\n\n" . $code . "\n\nהקוד בתוקף ל-10 דקות. אם לא ביקשתם להתחבר, אפשר להתעלם מההודעה."
	);

	if ( ! $sent ) {
		wp_send_json_error( [ 'message' => 'לא הצלחנו לשלוח את המייל. נסו שוב בעוד רגע.' ], 500 );
	}

	set_transient(
		$key,
		[
			'hash'  => gueta_login_code_hash( $email, $code ),
			'tries' => 0,
			'sent'  => time(),
		],
		GUETA_LOGIN_CODE_TTL
	);
	set_transient( $ip_key, $sends + 1, HOUR_IN_SECONDS );

	wp_send_json_success( [ 'email' => $email ] );
}
add_action( 'wc_ajax_gueta_login_send_code', 'gueta_ajax_login_send_code' );

/**
 * Check a code and log the customer in.
 *
 * @return void
 */
function gueta_ajax_login_verify_code() {
	gueta_login_check_request();

	$email = gueta_login_posted_email();
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in gueta_login_check_request().
	$code    = isset( $_POST['code'] ) ? preg_replace( '/\D/', '', (string) wp_unslash( $_POST['code'] ) ) : '';
	$key     = gueta_login_code_key( $email );
	$pending = get_transient( $key );

	if ( ! is_array( $pending ) ) {
		wp_send_json_error( [ 'message' => 'הקוד פג תוקף. בקשו קוד חדש.' ], 400 );
	}

	if ( ! hash_equals( (string) $pending['hash'], gueta_login_code_hash( $email, $code ) ) ) {
		$pending['tries'] = (int) $pending['tries'] + 1;

		if ( $pending['tries'] >= GUETA_LOGIN_MAX_TRIES ) {
			delete_transient( $key );
			wp_send_json_error( [ 'message' => 'יותר מדי ניסיונות. בקשו קוד חדש.' ], 400 );
		}

		// Keep the original expiry rather than granting a fresh ten minutes.
		set_transient( $key, $pending, max( 1, GUETA_LOGIN_CODE_TTL - ( time() - (int) $pending['sent'] ) ) );
		wp_send_json_error( [ 'message' => 'הקוד לא נכון. נסו שוב.' ], 400 );
	}

	delete_transient( $key );

	gueta_login_finish( $email );
}
add_action( 'wc_ajax_gueta_login_verify_code', 'gueta_ajax_login_verify_code' );

/* -------------------------------------------------------------------------
 * Google
 * ---------------------------------------------------------------------- */

/**
 * Check Google's token with Google and log the customer in.
 *
 * The browser hands over a signed ID token. Google's tokeninfo endpoint checks
 * the signature and the expiry; this checks the token was issued to this shop
 * and that Google has verified the address.
 *
 * @return void
 */
function gueta_ajax_login_google() {
	gueta_login_check_request();

	$client_id = gueta_login_google_client_id();
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in gueta_login_check_request().
	$token = isset( $_POST['credential'] ) ? sanitize_text_field( wp_unslash( $_POST['credential'] ) ) : '';

	if ( ! $client_id || ! $token ) {
		wp_send_json_error( [ 'message' => 'ההתחברות עם Google לא זמינה כרגע.' ], 400 );
	}

	$response = wp_remote_get(
		add_query_arg( 'id_token', rawurlencode( $token ), 'https://oauth2.googleapis.com/tokeninfo' ),
		[ 'timeout' => 10 ]
	);

	$claims = is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response )
		? null
		: json_decode( wp_remote_retrieve_body( $response ), true );

	$valid = is_array( $claims )
		&& ( $claims['aud'] ?? '' ) === $client_id
		&& in_array( $claims['iss'] ?? '', [ 'accounts.google.com', 'https://accounts.google.com' ], true )
		&& (int) ( $claims['exp'] ?? 0 ) > time()
		&& in_array( $claims['email_verified'] ?? '', [ true, 'true' ], true )
		&& is_email( $claims['email'] ?? '' );

	if ( ! $valid ) {
		wp_send_json_error( [ 'message' => 'לא הצלחנו לאמת את החשבון ב-Google. נסו שוב או התחברו עם קוד במייל.' ], 400 );
	}

	gueta_login_finish(
		sanitize_email( $claims['email'] ),
		[
			'first_name' => sanitize_text_field( $claims['given_name'] ?? '' ),
			'last_name'  => sanitize_text_field( $claims['family_name'] ?? '' ),
		]
	);
}
add_action( 'wc_ajax_gueta_login_google', 'gueta_ajax_login_google' );

/* -------------------------------------------------------------------------
 * The account
 * ---------------------------------------------------------------------- */

/**
 * Whether an account belongs to someone who works on the shop.
 *
 * @param WP_User $user User.
 * @return bool
 */
function gueta_login_is_staff( $user ) {
	return user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_woocommerce' );
}

/**
 * Turn a staff account away, towards the ordinary login.
 *
 * @return void
 */
function gueta_login_staff_error() {
	wp_send_json_error(
		[
			'message' => sprintf(
				'לחשבון הזה יש להתחבר <a href="%s">בדף ההתחברות הרגיל</a>.',
				esc_url( wp_login_url( wc_get_checkout_url() ) )
			),
		],
		403
	);
}

/**
 * Log in the account for a verified address, opening one if there is none.
 *
 * Only the login cookie is set, not the wp_login action, which would have
 * WooCommerce merge whatever was left in this account's cart months ago into
 * the cart the customer is about to pay for. WooCommerce moves the guest's
 * cart onto the account by itself on the next page.
 *
 * @param string $email Verified email address.
 * @param array  $names First and last name, when known.
 * @return void
 */
function gueta_login_finish( $email, $names = [] ) {
	$user = get_user_by( 'email', $email );

	if ( $user && gueta_login_is_staff( $user ) ) {
		gueta_login_staff_error();
	}

	if ( ! $user ) {
		$names   = array_filter( $names );
		$user_id = wc_create_new_customer( $email, wc_create_new_customer_username( $email, $names ), wp_generate_password( 24 ), $names );

		if ( is_wp_error( $user_id ) ) {
			wp_send_json_error( [ 'message' => 'לא הצלחנו לפתוח חשבון. נסו שוב בעוד רגע.' ], 500 );
		}

		foreach ( $names as $field => $value ) {
			update_user_meta( $user_id, 'billing_' . $field, $value );
		}

		$user = get_user_by( 'id', $user_id );
	}

	wc_set_customer_auth_cookie( $user->ID );

	wp_send_json_success( [ 'redirect' => wc_get_checkout_url() ] );
}

/* -------------------------------------------------------------------------
 * The checkout refuses guests
 * ---------------------------------------------------------------------- */

/**
 * Refuse an order from a guest, whoever posted it.
 *
 * @param array    $data   Posted data.
 * @param WP_Error $errors Validation errors.
 * @return void
 */
function gueta_login_validate_checkout( $data, $errors ) {
	if ( gueta_login_required() && ! is_user_logged_in() ) {
		$errors->add( 'gueta_login', 'יש להתחבר לפני ביצוע ההזמנה. רעננו את הדף כדי להתחבר.' );
	}
}
add_action( 'woocommerce_after_checkout_validation', 'gueta_login_validate_checkout', 5, 2 );

/**
 * No "create an account" box at the checkout: by the time it shows, the
 * customer already has one.
 *
 * @param bool $enabled Whether registration is offered.
 * @return bool
 */
function gueta_login_no_checkout_registration( $enabled ) {
	return gueta_login_required() ? false : $enabled;
}
add_filter( 'woocommerce_checkout_registration_enabled', 'gueta_login_no_checkout_registration' );
