<?php
/**
 * Log in before paying: a code sent by email, or Google, or go on as a guest.
 *
 * A guest who reaches the checkout sees a short login step in its place. There
 * is no password to make up or forget: the shop emails a six digit code, or
 * Google vouches for the address. An address the shop has not seen before
 * becomes a customer account on the spot, so a first order is one step longer
 * and every later one is shorter.
 *
 * The step is the whole page rather than a box inside the checkout, because
 * the checkout here is Elementor's widget and its markup is not ours to edit.
 * The server refuses an order from a guest who went round the step, so it
 * cannot be skipped by posting the form directly.
 *
 * Either way, a shopper may instead go on as a guest with just an address,
 * which fills the checkout's email field and goes on the order. The theme
 * settings choose whether the step sends a code at all: set to the address
 * alone, or with no SMTP to send a code through, it asks for the address and
 * goes on, as it did before codes.
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
 * Whether the shop can send the code by email: SMTP set in the theme settings.
 * Without it the code would leave through the host's mail(), which mostly
 * lands in spam or nowhere.
 *
 * @return bool
 */
function gueta_login_mail_ready() {
	if ( ! function_exists( 'gueta_smtp_settings' ) ) {
		return false;
	}

	$smtp = gueta_smtp_settings();

	return ! empty( $smtp['enabled'] ) && '' !== trim( (string) $smtp['host'] );
}

/**
 * Whether the step asks for a code sent by email, or for the address alone.
 *
 * The shop chooses in the theme settings. A code also needs SMTP, so without
 * it the step asks for the address alone whatever the setting says.
 *
 * @return bool
 */
function gueta_login_use_code() {
	return 'code' === gueta_setting( 'login_method', 'code' ) && gueta_login_mail_ready();
}

/**
 * The address a guest gave at the login step, kept in their cart session.
 *
 * @return string
 */
function gueta_login_guest_email() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return '';
	}

	return (string) WC()->session->get( 'gueta_guest_email', '' );
}

/**
 * The cookie that remembers a guest's choice beside the cart session.
 */
const GUETA_LOGIN_GUEST_COOKIE = 'gueta_guest';

/**
 * The cookie's value for an address: the address and a signature, so it
 * cannot be made up. An empty address is a guest who gave none.
 *
 * @param string $email Address, or an empty string.
 * @return string
 */
function gueta_login_guest_cookie_value( $email ) {
	return rawurlencode( $email ) . '|' . gueta_login_guest_signature( $email );
}

/**
 * The signature on a guest cookie for an address.
 *
 * @param string $email Address, or an empty string.
 * @return string
 */
function gueta_login_guest_signature( $email ) {
	return substr( hash_hmac( 'sha256', 'guest|' . $email, wp_salt( 'auth' ) ), 0, 32 );
}

/**
 * Remember a guest's choice in the browser as well as in the session.
 *
 * @param string $email Address, or an empty string.
 * @return void
 */
function gueta_login_remember_guest( $email ) {
	if ( headers_sent() ) {
		return;
	}

	// Until the browser closes, like the cart session itself.
	wc_setcookie( GUETA_LOGIN_GUEST_COOKIE, gueta_login_guest_cookie_value( $email ), 0, is_ssl(), true );
}

/**
 * The address a guest cookie vouches for, or null when there is none or it
 * does not check out.
 *
 * @return string|null
 */
function gueta_login_guest_cookie() {
	if ( empty( $_COOKIE[ GUETA_LOGIN_GUEST_COOKIE ] ) ) {
		return null;
	}

	$parts = explode( '|', (string) wp_unslash( $_COOKIE[ GUETA_LOGIN_GUEST_COOKIE ] ), 2 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked against its signature below.

	if ( 2 !== count( $parts ) ) {
		return null;
	}

	// PHP hands the cookie over already decoded, so the address is checked by its signature alone.
	$email = rawurldecode( $parts[0] );

	if ( ( '' !== $email && ! is_email( $email ) ) || ! hash_equals( gueta_login_guest_signature( $email ), $parts[1] ) ) {
		return null;
	}

	return $email;
}

/**
 * Whether the shopper chose to go on as a guest, with an address or without.
 *
 * The session says so. The cookie set beside it says so too, for when the
 * session lost it: two requests at once each write the whole session, and
 * the one that started earlier but finished later wrote it back without the
 * choice, so the shopper who had just given an address met the step again
 * at the checkout. The cookie puts the choice back into the session.
 *
 * @return bool
 */
function gueta_login_is_guest() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return false;
	}

	if ( (bool) WC()->session->get( 'gueta_guest', false ) || '' !== gueta_login_guest_email() ) {
		return true;
	}

	$email = gueta_login_guest_cookie();

	if ( null === $email ) {
		return false;
	}

	if ( ! WC()->session->has_session() && ! headers_sent() ) {
		WC()->session->set_customer_session_cookie( true );
	}

	WC()->session->set( 'gueta_guest', true );

	if ( '' !== $email ) {
		WC()->session->set( 'gueta_guest_email', $email );
	}

	return true;
}

/**
 * Whether this request is the checkout a guest must log in for. The thank you
 * page and the pay-for-order page are reached from an order already placed,
 * so they stay open.
 *
 * @return bool
 */
function gueta_login_gate_applies() {
	if ( ! gueta_login_required() || is_user_logged_in() || gueta_login_is_guest() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
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
 * Whether this page carries the step as a popup, to open when a guest heads
 * for the checkout. Everywhere but the checkout itself, which has the step
 * as its page.
 *
 * A page the page cache stores is one with no cart, no session and nobody
 * signed in, so a stored copy always belongs to a guest who would need it.
 *
 * @return bool
 */
function gueta_login_popup_applies() {
	if ( ! gueta_login_required() || is_user_logged_in() || gueta_login_is_guest() || is_admin() ) {
		return false;
	}

	return ! function_exists( 'is_checkout' ) || ! is_checkout();
}

/**
 * The step's card: Google, the address, the code and the message.
 *
 * @param bool $in_popup Whether the card sits in the popup, where the way
 *                       back is the close button rather than a link.
 * @return void
 */
function gueta_render_login_card( $in_popup = false ) {
	$google = gueta_login_google_client_id();
	$mail   = gueta_login_use_code();
	$verify = $mail || $google;
	?>
	<div class="gueta-login__card" data-gueta-login>
		<?php if ( $verify ) : ?>
			<h2 class="gueta-login__title" id="gueta-login-title">התחברות לפני התשלום</h2>
			<p class="gueta-login__lead">כדי שנוכל לעדכן אתכם על ההזמנה ולשמור את הפרטים לפעם הבאה. בלי סיסמה.</p>
		<?php else : ?>
			<h2 class="gueta-login__title" id="gueta-login-title">רגע לפני התשלום</h2>
			<p class="gueta-login__lead">לאיזו כתובת מייל לשלוח את אישור ההזמנה והעדכונים עליה?</p>
		<?php endif; ?>

		<?php if ( $google ) : ?>
			<div class="gueta-login__google" data-login-google></div>
			<p class="gueta-login__or" data-login-or><span><?php echo $mail ? 'או עם קוד במייל' : 'או'; ?></span></p>
		<?php endif; ?>

		<form class="gueta-login__step" data-login-step="email" data-login-mode="<?php echo $mail ? 'code' : 'guest'; ?>" novalidate>
			<label class="gueta-login__label" for="gueta-login-email">כתובת מייל</label>
			<input class="gueta-login__input" type="email" id="gueta-login-email" name="email" autocomplete="email" inputmode="email" dir="ltr" required>
			<button type="submit" class="gueta-button gueta-button--solid"><?php echo $mail ? 'שליחת קוד אימות' : 'המשך'; ?></button>
			<button type="button" class="gueta-login__guest" data-login-guest>המשך כאורח</button>
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

		<?php if ( ! $in_popup ) : ?>
			<p class="gueta-login__back"><a href="<?php echo esc_url( wc_get_cart_url() ); ?>">חזרה לסל</a></p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * The login step as a page, for the checkout reached directly.
 *
 * @return void
 */
function gueta_render_login_gate() {
	?>
	<main class="gueta-login" id="content">
		<?php gueta_render_login_card(); ?>
	</main>
	<?php
}

/**
 * The login step as a popup, waiting at the bottom of the page.
 *
 * @return void
 */
function gueta_render_login_popup() {
	if ( ! gueta_login_popup_applies() ) {
		return;
	}
	?>
	<div class="gueta-login-modal" data-login-modal hidden>
		<div class="gueta-login-modal__backdrop" data-login-close></div>
		<div class="gueta-login-modal__panel" role="dialog" aria-modal="true" aria-labelledby="gueta-login-title">
			<button type="button" class="gueta-icon-button gueta-login-modal__close" data-login-close aria-label="סגירה">
				<?php echo gueta_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
			<?php gueta_render_login_card( true ); ?>
		</div>
	</div>
	<?php
}
add_action( 'wp_footer', 'gueta_render_login_popup' );

/**
 * The step's stylesheet and script, on the checkout's step and wherever the
 * popup waits. Google's library is fetched by the script when the step is
 * shown, so pages where nobody heads for the checkout never load it.
 *
 * @return void
 */
function gueta_login_assets() {
	if ( ! gueta_login_gate_applies() && ! gueta_login_popup_applies() ) {
		return;
	}

	$uri = get_stylesheet_directory_uri();

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

	wp_localize_script(
		'gueta-login',
		'guetaLogin',
		[
			'endpoint' => WC_AJAX::get_endpoint( '%%endpoint%%' ),
			'nonce'    => wp_create_nonce( 'gueta_login' ),
			'google'   => gueta_login_google_client_id(),
			'redirect' => wc_get_checkout_url(),
			'popup'    => gueta_login_popup_applies(),
			'strings'  => [
				'email'      => 'נא להזין כתובת מייל תקינה',
				'code'       => 'נא להזין את 6 הספרות שקיבלתם',
				'sending'    => 'שולחים...',
				'continuing' => 'ממשיכים לתשלום...',
				'resent'     => 'שלחנו קוד חדש',
				'error'      => 'משהו השתבש. נסו שוב בעוד רגע.',
			],
		]
	);
}
add_action( 'wp_enqueue_scripts', 'gueta_login_assets', 30 );

/**
 * A fresh nonce for the popup, and whether the step is still needed.
 *
 * The page the popup sits on may be a stored copy, or older than the cart:
 * WooCommerce ties a guest's nonce to their cart session, so one printed
 * before the first item went in no longer passes once it has.
 *
 * @return void
 */
function gueta_ajax_login_state() {
	nocache_headers();

	wp_send_json_success(
		[
			'nonce'  => wp_create_nonce( 'gueta_login' ),
			'needed' => gueta_login_required() && ! is_user_logged_in() && ! gueta_login_is_guest(),
		]
	);
}
add_action( 'wc_ajax_gueta_login_state', 'gueta_ajax_login_state' );

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

	if ( ! gueta_login_use_code() ) {
		wp_send_json_error( [ 'message' => 'ההתחברות בקוד כבויה. אפשר להמשיך עם כתובת המייל בלבד.' ], 400 );
	}

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
 * Going on as a guest
 * ---------------------------------------------------------------------- */

/**
 * Send the shopper to the checkout as a guest, with an address or without.
 *
 * With an address, it fills the checkout's email field and an order is opened
 * at once, pending payment, so a shopper who leaves before paying is still on
 * record with their cart and their address. WooCommerce takes that order up
 * again when they pay; see gueta_login_pending_order().
 *
 * Nothing is verified here, so no account is opened or logged into: an
 * address typed in proves nothing about who typed it.
 *
 * @return void
 */
function gueta_ajax_login_guest() {
	gueta_login_check_request();

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in gueta_login_check_request().
	$email = isset( $_POST['email'] ) && '' !== trim( (string) wp_unslash( $_POST['email'] ) ) ? gueta_login_posted_email() : '';

	if ( ! WC()->session ) {
		wp_send_json_error( [ 'message' => 'משהו השתבש. נסו שוב בעוד רגע.' ], 500 );
	}

	// A guest's session lives only in memory until it is given a cookie.
	WC()->session->set_customer_session_cookie( true );
	WC()->session->set( 'gueta_guest', true );
	gueta_login_remember_guest( $email );

	if ( $email ) {
		WC()->session->set( 'gueta_guest_email', $email );

		if ( WC()->customer ) {
			WC()->customer->set_billing_email( $email );
			WC()->customer->save();
		}

		gueta_login_pending_order( $email );
	}

	/*
	 * WooCommerce writes the session when the request shuts down, which can be
	 * after the browser has the answer and has gone on to the checkout; that
	 * page then still saw a stranger and showed the step again. Write it now.
	 */
	WC()->session->save_data();

	wp_send_json_success( [ 'redirect' => wc_get_checkout_url() ] );
}

/* -------------------------------------------------------------------------
 * The order kept for the session
 *
 * A guest who goes on with an address gets an order at once, pending
 * payment, so a shopper who leaves before paying is still on record with
 * their cart and their address. It is one order for the whole visit: as the
 * cart changes it is brought up to date, and placing the order at the
 * checkout fills in this same order rather than opening a second.
 *
 * WooCommerce takes up an order awaiting payment only while the cart's hash
 * matches the one stored on it, and opens a new order otherwise. So before
 * each update, and before the checkout places the order, the stored hash is
 * set to the cart's, and WooCommerce rebuilds the same order from the cart.
 * ---------------------------------------------------------------------- */

/**
 * The order kept for this session, while it can still be paid and changed.
 *
 * @return WC_Order|null
 */
function gueta_login_session_order() {
	if ( ! function_exists( 'WC' ) || ! WC()->session ) {
		return null;
	}

	$order = wc_get_order( (int) WC()->session->get( 'gueta_pending_order', 0 ) );

	if ( ! $order || ! $order->get_meta( '_gueta_pending_from_login' ) || ! $order->has_status( [ 'pending', 'failed' ] ) ) {
		return null;
	}

	return $order;
}

/**
 * Write the cart into an order: WooCommerce's own checkout, run on the order
 * awaiting payment, with the hash set so it is taken up rather than replaced.
 *
 * A failure here costs only the record, so it never stops the shopper.
 *
 * @param array         $data  Fields for the order.
 * @param WC_Order|null $order The order to bring up to date, or null for a new one.
 * @return int Order ID, or 0.
 */
function gueta_login_write_order( $data, $order = null ) {
	if ( ! WC()->cart || WC()->cart->is_empty() ) {
		return 0;
	}

	try {
		WC()->cart->calculate_totals();

		if ( $order ) {
			$order->set_cart_hash( WC()->cart->get_cart_hash() );
			$order->save();
			WC()->session->set( 'order_awaiting_payment', $order->get_id() );
		}

		$order_id = WC()->checkout()->create_order( $data + [ 'payment_method' => '' ] );
	} catch ( Throwable $e ) {
		return 0;
	}

	return is_wp_error( $order_id ) ? 0 : (int) $order_id;
}

/**
 * Open the session's order under the shopper's address, or bring the one
 * already open up to date with it.
 *
 * @param string $email Address.
 * @return int Order ID, or 0.
 */
function gueta_login_pending_order( $email ) {
	$existing = gueta_login_session_order();

	$order_id = gueta_login_write_order(
		[
			'billing_email'  => $email,
			'payment_method' => $existing ? $existing->get_payment_method() : '',
		],
		$existing
	);

	$order = $order_id ? wc_get_order( $order_id ) : null;

	if ( ! $order ) {
		return 0;
	}

	if ( ! $existing ) {
		$order->update_meta_data( '_gueta_pending_from_login', 1 );
		$order->add_order_note( 'נפתחה כשהלקוח המשיך מחלון ההתחברות לתשלום. ממתינה לתשלום: אם לא תושלם, הלקוח נטש את הקנייה.' );
		$order->save();
	}

	WC()->session->set( 'order_awaiting_payment', $order->get_id() );
	WC()->session->set( 'gueta_pending_order', $order->get_id() );

	return $order->get_id();
}

/**
 * Note that the cart changed, for the session's order to follow once the
 * request is done. Adding three items is one update, not three.
 *
 * @return void
 */
function gueta_login_cart_changed() {
	$GLOBALS['gueta_login_cart_changed'] = true;
}
add_action( 'woocommerce_add_to_cart', 'gueta_login_cart_changed' );
add_action( 'woocommerce_cart_item_removed', 'gueta_login_cart_changed' );
add_action( 'woocommerce_cart_item_restored', 'gueta_login_cart_changed' );
add_action( 'woocommerce_after_cart_item_quantity_update', 'gueta_login_cart_changed' );
add_action( 'woocommerce_applied_coupon', 'gueta_login_cart_changed' );
add_action( 'woocommerce_removed_coupon', 'gueta_login_cart_changed' );

/**
 * Bring the session's order up to date with the cart.
 *
 * At shutdown, ahead of WooCommerce writing the session. An emptied cart
 * leaves the order as it was, the record of what was nearly bought.
 *
 * @return void
 */
function gueta_login_sync_order() {
	if ( empty( $GLOBALS['gueta_login_cart_changed'] ) || is_user_logged_in() ) {
		return;
	}

	$GLOBALS['gueta_login_cart_changed'] = false;

	$order = gueta_login_session_order();

	if ( $order ) {
		gueta_login_write_order(
			[
				'billing_email'  => $order->get_billing_email(),
				'payment_method' => $order->get_payment_method(),
			],
			$order
		);
	}
}
add_action( 'shutdown', 'gueta_login_sync_order', 5 );

/**
 * Before the checkout places the order, point it at the session's order, so
 * whatever changed since, WooCommerce fills in that order and opens no other.
 *
 * The cart's hash carries its total, which the checkout works out again from
 * the posted delivery choice. Validation runs after that and just before the
 * order is placed, so the hash taken here is the one WooCommerce compares.
 *
 * @return void
 */
function gueta_login_resume_order() {
	$order = gueta_login_session_order();

	if ( ! $order || ! WC()->cart ) {
		return;
	}

	$order->set_cart_hash( WC()->cart->get_cart_hash() );
	$order->save();

	WC()->session->set( 'order_awaiting_payment', $order->get_id() );
}
add_action( 'woocommerce_after_checkout_validation', 'gueta_login_resume_order', 999 );

/**
 * Should the checkout have opened another order all the same, bin the
 * session's one, so the shop does not see the same purchase twice.
 *
 * @param int $order_id The order the checkout placed.
 * @return void
 */
function gueta_login_drop_pending_order( $order_id ) {
	$pending = gueta_login_session_order();

	if ( ! $pending || (int) $order_id === $pending->get_id() ) {
		return;
	}

	WC()->session->set( 'gueta_pending_order', null );
	$pending->delete( false );
}
add_action( 'woocommerce_checkout_order_processed', 'gueta_login_drop_pending_order' );
add_action( 'wc_ajax_gueta_login_guest', 'gueta_ajax_login_guest' );

/**
 * The guest's address in the checkout's email field, if it is still empty.
 *
 * @param mixed  $value Field value.
 * @param string $input Field name.
 * @return mixed
 */
function gueta_login_guest_checkout_value( $value, $input ) {
	if ( 'billing_email' === $input && ! $value && ! is_user_logged_in() ) {
		$guest = gueta_login_guest_email();

		return $guest ? $guest : $value;
	}

	return $value;
}
add_filter( 'woocommerce_checkout_get_value', 'gueta_login_guest_checkout_value', 10, 2 );

/**
 * The order goes out with the guest's address if the field came back empty.
 *
 * @param array $data Posted data.
 * @return array
 */
function gueta_login_guest_posted_email( $data ) {
	if ( ! is_user_logged_in() && empty( $data['billing_email'] ) && gueta_login_guest_email() ) {
		$data['billing_email'] = gueta_login_guest_email();
	}

	return $data;
}
add_filter( 'woocommerce_checkout_posted_data', 'gueta_login_guest_posted_email' );

/**
 * A guest who gave an address may order without an account, whatever the
 * WooCommerce setting for guest checkout says.
 *
 * @param bool $required Whether an account is required.
 * @return bool
 */
function gueta_login_guest_no_account( $required ) {
	return ! is_user_logged_in() && gueta_login_is_guest() ? false : $required;
}
add_filter( 'woocommerce_checkout_registration_required', 'gueta_login_guest_no_account' );

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
	if ( gueta_login_required() && ! is_user_logged_in() && ! gueta_login_is_guest() ) {
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
