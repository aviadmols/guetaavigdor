<?php
/**
 * Send the site's mail through an SMTP server.
 *
 * PHP's own mail() leaves from the web host with nothing to vouch for it, and
 * a login code that lands in spam is a customer who cannot pay. With SMTP set
 * here, every wp_mail() goes out through a real mailbox: the login codes, the
 * WooCommerce order emails, all of it.
 *
 * The password is kept encrypted with the site's own keys, so a copy of the
 * database alone does not give it away. It is never printed back into the form.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option holding the SMTP settings.
 */
const GUETA_SMTP_OPTION = 'gueta_smtp';

/**
 * The SMTP settings with their defaults.
 *
 * @return array
 */
function gueta_smtp_settings() {
	$stored = get_option( GUETA_SMTP_OPTION, [] );

	return wp_parse_args(
		is_array( $stored ) ? $stored : [],
		[
			'enabled'    => 0,
			'host'       => '',
			'port'       => 587,
			'encryption' => 'tls',
			'auth'       => 1,
			'username'   => '',
			'password'   => '',
			'from_email' => '',
			'from_name'  => '',
		]
	);
}

/* -------------------------------------------------------------------------
 * The password, encrypted at rest
 * ---------------------------------------------------------------------- */

/**
 * The key the password is encrypted with, from the site's salts.
 *
 * @return string
 */
function gueta_smtp_key() {
	return hash( 'sha256', wp_salt( 'secure_auth' ), true );
}

/**
 * Encrypt a password for storing.
 *
 * @param string $plain Password.
 * @return string
 */
function gueta_smtp_encrypt( $plain ) {
	if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
		return $plain;
	}

	$iv     = random_bytes( 16 );
	$cipher = openssl_encrypt( $plain, 'aes-256-cbc', gueta_smtp_key(), OPENSSL_RAW_DATA, $iv );

	return 'enc:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
}

/**
 * Decrypt a stored password.
 *
 * @param string $stored Stored value.
 * @return string
 */
function gueta_smtp_decrypt( $stored ) {
	if ( 0 !== strpos( (string) $stored, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
		return (string) $stored;
	}

	$raw   = base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	$plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', gueta_smtp_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );

	return false === $plain ? '' : $plain;
}

/* -------------------------------------------------------------------------
 * Sending
 * ---------------------------------------------------------------------- */

/**
 * Point PHPMailer at the SMTP server.
 *
 * @param PHPMailer\PHPMailer\PHPMailer $mailer Mailer.
 * @return void
 */
function gueta_smtp_configure( $mailer ) {
	$settings = gueta_smtp_settings();

	if ( empty( $settings['enabled'] ) || '' === $settings['host'] ) {
		return;
	}

	$mailer->isSMTP();
	$mailer->Host       = $settings['host']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	$mailer->Port       = (int) $settings['port']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	$mailer->SMTPSecure = in_array( $settings['encryption'], [ 'ssl', 'tls' ], true ) ? $settings['encryption'] : ''; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	$mailer->SMTPAutoTLS = 'tls' === $settings['encryption']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	$mailer->SMTPAuth   = ! empty( $settings['auth'] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	$mailer->Timeout    = 15; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

	if ( $mailer->SMTPAuth ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Username = $settings['username']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->Password = gueta_smtp_decrypt( $settings['password'] ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}

	// Most SMTP servers refuse a sender other than the mailbox itself.
	if ( is_email( $settings['from_email'] ) ) {
		$mailer->setFrom( $settings['from_email'], '' !== $settings['from_name'] ? $settings['from_name'] : $mailer->FromName, false ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	}
}
add_action( 'phpmailer_init', 'gueta_smtp_configure', 99 );

/* -------------------------------------------------------------------------
 * Admin section
 * ---------------------------------------------------------------------- */

/**
 * Save the submitted settings, keeping the stored password when the field is left empty.
 *
 * @param array $source Raw form data.
 * @return void
 */
function gueta_smtp_save( $source ) {
	$current    = gueta_smtp_settings();
	$encryption = isset( $source['smtp_encryption'] ) ? sanitize_key( wp_unslash( $source['smtp_encryption'] ) ) : 'tls';
	$password   = isset( $source['smtp_password'] ) ? (string) wp_unslash( $source['smtp_password'] ) : '';

	update_option(
		GUETA_SMTP_OPTION,
		[
			'enabled'    => ! empty( $source['smtp_enabled'] ) ? 1 : 0,
			'host'       => isset( $source['smtp_host'] ) ? sanitize_text_field( wp_unslash( $source['smtp_host'] ) ) : '',
			'port'       => isset( $source['smtp_port'] ) ? min( 65535, max( 1, (int) $source['smtp_port'] ) ) : 587,
			'encryption' => in_array( $encryption, [ 'none', 'ssl', 'tls' ], true ) ? $encryption : 'tls',
			'auth'       => ! empty( $source['smtp_auth'] ) ? 1 : 0,
			'username'   => isset( $source['smtp_username'] ) ? sanitize_text_field( wp_unslash( $source['smtp_username'] ) ) : '',
			'password'   => '' !== $password ? gueta_smtp_encrypt( $password ) : $current['password'],
			'from_email' => isset( $source['smtp_from_email'] ) ? sanitize_email( wp_unslash( $source['smtp_from_email'] ) ) : '',
			'from_name'  => isset( $source['smtp_from_name'] ) ? sanitize_text_field( wp_unslash( $source['smtp_from_name'] ) ) : '',
		],
		false
	);
}

/**
 * Send a test message and report what the server said.
 *
 * @param string $to Recipient.
 * @return array{ok:bool,message:string}
 */
function gueta_smtp_test( $to ) {
	$error = '';

	$catch = static function ( $wp_error ) use ( &$error ) {
		$error = $wp_error->get_error_message();
	};

	add_action( 'wp_mail_failed', $catch );
	$sent = wp_mail( $to, 'בדיקת שליחת מייל מהאתר', "זו הודעת בדיקה מהגדרות ה-SMTP באתר.\n\nאם היא הגיעה, המיילים יוצאים כמו שצריך." );
	remove_action( 'wp_mail_failed', $catch );

	return [
		'ok'      => $sent,
		'message' => $sent ? 'הודעת הבדיקה נשלחה אל ' . $to . '. בדקו שהיא הגיעה, גם בתיקיית הספאם.' : 'השליחה נכשלה: ' . ( $error ? $error : 'סיבה לא ידועה' ),
	];
}

/**
 * The SMTP section of the theme settings page.
 *
 * @return void
 */
function gueta_smtp_admin_section() {
	$notice = null;

	if ( isset( $_POST['gueta_smtp_save'] ) && check_admin_referer( 'gueta_smtp' ) ) {
		gueta_smtp_save( $_POST );
		$notice = [
			'ok'      => true,
			'message' => 'הגדרות ה-SMTP נשמרו.',
		];
	}

	if ( isset( $_POST['gueta_smtp_test'] ) && check_admin_referer( 'gueta_smtp' ) ) {
		gueta_smtp_save( $_POST );
		$to     = isset( $_POST['smtp_test_to'] ) ? sanitize_email( wp_unslash( $_POST['smtp_test_to'] ) ) : '';
		$notice = is_email( $to ) ? gueta_smtp_test( $to ) : [
			'ok'      => false,
			'message' => 'נא להזין כתובת תקינה לשליחת הבדיקה.',
		];
	}

	$settings = gueta_smtp_settings();
	$admin    = wp_get_current_user()->user_email;
	?>
	<hr>

	<h2>שליחת מיילים (SMTP)</h2>
	<p>
		כל המיילים של האתר יוצאים דרך שרת הדואר שמוגדר כאן: קודי ההתחברות לפני התשלום, מיילי ההזמנות ושאר המיילים.
		בלי SMTP הם נשלחים מהשרת של האתר ועלולים להגיע לספאם או לא להגיע בכלל.
	</p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo $notice['ok'] ? 'success' : 'error'; ?> inline">
			<p><?php echo esc_html( $notice['message'] ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field( 'gueta_smtp' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">הפעלה</th>
				<td>
					<label>
						<input type="checkbox" name="smtp_enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
						לשלוח את המיילים דרך SMTP
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gueta-smtp-host">שרת</label></th>
				<td>
					<input type="text" id="gueta-smtp-host" name="smtp_host" value="<?php echo esc_attr( $settings['host'] ); ?>" class="regular-text code" dir="ltr" placeholder="smtp.gmail.com">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gueta-smtp-port">פורט</label></th>
				<td>
					<input type="number" id="gueta-smtp-port" name="smtp_port" value="<?php echo (int) $settings['port']; ?>" class="small-text" dir="ltr" min="1" max="65535">
					<p class="description">בדרך כלל 587 עם TLS, או 465 עם SSL.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">הצפנה</th>
				<td>
					<?php foreach ( [ 'tls' => 'TLS', 'ssl' => 'SSL', 'none' => 'ללא' ] as $value => $label ) : ?>
						<label style="margin-inline-end:16px;">
							<input type="radio" name="smtp_encryption" value="<?php echo esc_attr( $value ); ?>" <?php checked( $settings['encryption'], $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">אימות</th>
				<td>
					<label>
						<input type="checkbox" name="smtp_auth" value="1" <?php checked( ! empty( $settings['auth'] ) ); ?>>
						השרת דורש שם משתמש וסיסמה
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gueta-smtp-username">שם משתמש</label></th>
				<td>
					<input type="text" id="gueta-smtp-username" name="smtp_username" value="<?php echo esc_attr( $settings['username'] ); ?>" class="regular-text code" dir="ltr" autocomplete="off">
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gueta-smtp-password">סיסמה</label></th>
				<td>
					<input type="password" id="gueta-smtp-password" name="smtp_password" value="" class="regular-text code" dir="ltr" autocomplete="new-password" placeholder="<?php echo $settings['password'] ? esc_attr( '••••••••' ) : ''; ?>">
					<p class="description">
						<?php echo $settings['password'] ? 'שמורה סיסמה. משאירים ריק כדי לא לשנות אותה.' : 'לא שמורה סיסמה.'; ?>
						ב-Gmail משתמשים ב<a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">סיסמת אפליקציה</a>, לא בסיסמה הרגילה.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gueta-smtp-from-email">כתובת השולח</label></th>
				<td>
					<input type="email" id="gueta-smtp-from-email" name="smtp_from_email" value="<?php echo esc_attr( $settings['from_email'] ); ?>" class="regular-text code" dir="ltr">
					<p class="description">רוב השרתים דורשים שזו תהיה אותה תיבה של שם המשתמש. אם ריק, נשארת הכתובת של WooCommerce.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gueta-smtp-from-name">שם השולח</label></th>
				<td>
					<input type="text" id="gueta-smtp-from-name" name="smtp_from_name" value="<?php echo esc_attr( $settings['from_name'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
				</td>
			</tr>
		</table>

		<?php submit_button( 'שמירת הגדרות SMTP', 'primary', 'gueta_smtp_save', false ); ?>

		<p style="margin-top:20px;">
			<label for="gueta-smtp-test-to">שליחת מייל בדיקה אל</label>
			<input type="email" id="gueta-smtp-test-to" name="smtp_test_to" value="<?php echo esc_attr( $admin ); ?>" class="regular-text code" dir="ltr">
			<?php submit_button( 'שמירה ושליחת בדיקה', 'secondary', 'gueta_smtp_test', false ); ?>
		</p>
	</form>
	<?php
}
