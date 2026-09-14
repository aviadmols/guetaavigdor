<?php
/**
 * Back in stock: a product that has sold out takes an email address, and the
 * address gets a message once the product can be bought again.
 *
 * Requests live in their own table, one row per product and address. A row is
 * kept after its message goes out, stamped with the time, so the list in the
 * admin shows who was told as well as who is still waiting.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema version; bump it with any change to the table below.
 */
const GUETA_NOTIFY_DB_VERSION = '1';

/**
 * The job that sends one product's messages.
 */
const GUETA_NOTIFY_HOOK = 'gueta_stock_notify_send';

/**
 * Messages sent per run. What is left over goes out in the next one.
 */
const GUETA_NOTIFY_BATCH = 100;

/**
 * Requests one address may make in an hour.
 */
const GUETA_NOTIFY_RATE = 30;

/**
 * The table name.
 *
 * @return string
 */
function gueta_notify_table() {
	global $wpdb;

	return $wpdb->prefix . 'gueta_stock_notify';
}

/**
 * Create or update the table.
 *
 * @return void
 */
function gueta_notify_install() {
	if ( GUETA_NOTIFY_DB_VERSION === get_option( 'gueta_notify_db_version' ) ) {
		return;
	}

	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = gueta_notify_table();
	$charset = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			email varchar(190) NOT NULL,
			created_at datetime NOT NULL,
			notified_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY product_email (product_id,email),
			KEY waiting (product_id,notified_at)
		) {$charset};"
	);

	update_option( 'gueta_notify_db_version', GUETA_NOTIFY_DB_VERSION );
}
add_action( 'admin_init', 'gueta_notify_install' );

/* -------------------------------------------------------------------------
 * The form
 * ---------------------------------------------------------------------- */

/**
 * What a request can come back with.
 *
 * @return array Code => [ message, succeeded ].
 */
function gueta_notify_messages() {
	return [
		'ok'      => [ 'נרשמתם. נשלח לכם מייל ברגע שהמוצר יחזור למלאי.', true ],
		'invalid' => [ 'כתובת המייל לא נראית תקינה. בדקו אותה ונסו שוב.', false ],
		'instock' => [ 'המוצר כבר חזר למלאי, אפשר להזמין אותו עכשיו.', false ],
		'busy'    => [ 'נשלחו מכאן יותר מדי בקשות. נסו שוב בעוד שעה.', false ],
		'error'   => [ 'משהו השתבש. נסו שוב בעוד רגע.', false ],
	];
}

/**
 * The form a sold out product offers in place of the button.
 *
 * It posts to admin-ajax.php, and the product script sends it from there
 * without leaving the page. Without the script the post still lands, and the
 * handler sends the visitor back with the outcome in the address.
 *
 * @param WC_Product $product Product.
 * @return string
 */
function gueta_notify_form_html( $product ) {
	$id     = 'gueta-notify-' . $product->get_id() . '-' . wp_unique_id();
	$code   = isset( $_GET['gueta_notify'] ) ? sanitize_key( wp_unslash( $_GET['gueta_notify'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$known  = gueta_notify_messages();
	$result = $known[ $code ] ?? null;

	ob_start();
	?>
	<form
		class="gueta-notify<?php echo $result && $result[1] ? ' is-done' : ''; ?>"
		id="<?php echo esc_attr( $id ); ?>"
		action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
		method="post"
		data-notify
	>
		<p class="gueta-notify__title">אזל מהמלאי</p>
		<p class="gueta-notify__text">השאירו כתובת מייל ונעדכן אתכם כשהמוצר יחזור.</p>

		<div class="gueta-notify__row">
			<label class="gueta-notify__label" for="<?php echo esc_attr( $id ); ?>-email">כתובת מייל</label>
			<input
				class="gueta-notify__email"
				id="<?php echo esc_attr( $id ); ?>-email"
				type="email"
				name="email"
				required
				autocomplete="email"
				inputmode="email"
				placeholder="כתובת המייל שלכם"
			>
			<button class="gueta-notify__submit" type="submit">עדכנו אותי</button>
		</div>

		<?php // Left empty by people, who never see it; filled in by form bots. ?>
		<div class="gueta-notify__trap" aria-hidden="true">
			<input type="text" name="gueta_trap" tabindex="-1" autocomplete="off">
		</div>

		<input type="hidden" name="action" value="gueta_stock_notify">
		<input type="hidden" name="product_id" value="<?php echo (int) $product->get_id(); ?>">

		<p
			class="gueta-notify__message<?php echo $result && ! $result[1] ? ' is-error' : ''; ?>"
			data-notify-message
			role="status"
			aria-live="polite"
		><?php echo $result ? esc_html( $result[0] ) : ''; ?></p>
	</form>
	<?php

	return (string) ob_get_clean();
}

/**
 * Take a request.
 *
 * No nonce: the product pages are cached, and a nonce baked into a cached page
 * goes stale within a day. All a forged request can do is put an address on a
 * list that sends one message, so a honeypot and a rate limit stand guard.
 *
 * @return void
 */
function gueta_notify_subscribe() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
	$email      = isset( $_POST['email'] ) ? strtolower( sanitize_email( wp_unslash( $_POST['email'] ) ) ) : '';
	$trapped    = ! empty( $_POST['gueta_trap'] );
	// phpcs:enable

	$product = $product_id && gueta_has_woocommerce() ? wc_get_product( $product_id ) : null;

	if ( $product instanceof WC_Product && $product->get_parent_id() ) {
		$product = wc_get_product( $product->get_parent_id() );
	}

	if ( $trapped ) {
		// A bot is told it worked, so it has nothing to learn from trying again.
		gueta_notify_respond( 'ok', $product );
	}

	if ( ! $product instanceof WC_Product || ! is_email( $email ) || strlen( $email ) > 190 ) {
		gueta_notify_respond( 'invalid', $product );
	}

	if ( $product->is_in_stock() ) {
		gueta_notify_respond( 'instock', $product );
	}

	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key   = 'gueta_notify_rate_' . md5( $ip );
	$count = (int) get_transient( $key );

	if ( $count >= GUETA_NOTIFY_RATE ) {
		gueta_notify_respond( 'busy', $product );
	}

	set_transient( $key, $count + 1, HOUR_IN_SECONDS );

	gueta_notify_install();

	global $wpdb;

	$table = gueta_notify_table();

	/*
	 * Asking again for a product already waited on changes nothing; asking
	 * again after being told puts the address back in line. MySQL applies the
	 * assignments in order, so the first still reads the old notified_at.
	 */
	$saved = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"INSERT INTO {$table} (product_id, email, created_at, notified_at) VALUES (%d, %s, %s, NULL)
			ON DUPLICATE KEY UPDATE created_at = IF(notified_at IS NULL, created_at, VALUES(created_at)), notified_at = NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$product->get_id(),
			$email,
			current_time( 'mysql', true )
		)
	);

	gueta_notify_respond( false === $saved ? 'error' : 'ok', $product );
}
add_action( 'wp_ajax_gueta_stock_notify', 'gueta_notify_subscribe' );
add_action( 'wp_ajax_nopriv_gueta_stock_notify', 'gueta_notify_subscribe' );

/**
 * Answer the script with JSON, or send a plain post back where it came from.
 *
 * @param string          $code    One of gueta_notify_messages().
 * @param WC_Product|null $product Product asked about.
 * @return never
 */
function gueta_notify_respond( $code, $product ) {
	$known  = gueta_notify_messages();
	$result = $known[ $code ] ?? $known['error'];

	if ( ! empty( $_POST['gueta_js'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
		wp_send_json(
			[
				'success' => $result[1],
				'data'    => [ 'message' => $result[0] ],
			]
		);
	}

	$back = wp_get_referer();

	if ( ! $back ) {
		$back = $product instanceof WC_Product ? $product->get_permalink() : home_url( '/' );
	}

	wp_safe_redirect( add_query_arg( 'gueta_notify', $code, $back ) );
	exit;
}

/* -------------------------------------------------------------------------
 * Telling them
 * ---------------------------------------------------------------------- */

/**
 * Queue the messages when a product, or one of its variations, is back.
 *
 * WooCommerce raises these only when the status actually changes, whether the
 * stock was edited by hand, imported, or restored by a cancelled order.
 *
 * @param int             $product_id Product or variation.
 * @param string          $status     New stock status.
 * @param WC_Product|null $product    Product or variation.
 * @return void
 */
function gueta_notify_stock_changed( $product_id, $status, $product = null ) {
	if ( 'instock' !== $status ) {
		return;
	}

	$parent = $product instanceof WC_Product ? $product->get_parent_id() : wp_get_post_parent_id( $product_id );
	$target = $parent ? (int) $parent : (int) $product_id;

	if ( gueta_notify_waiting( $target ) ) {
		gueta_notify_queue( $target );
	}
}
add_action( 'woocommerce_product_set_stock_status', 'gueta_notify_stock_changed', 10, 3 );
add_action( 'woocommerce_variation_set_stock_status', 'gueta_notify_stock_changed', 10, 3 );

/**
 * How many are waiting on a product.
 *
 * @param int $product_id Product.
 * @return int
 */
function gueta_notify_waiting( $product_id ) {
	global $wpdb;

	if ( GUETA_NOTIFY_DB_VERSION !== get_option( 'gueta_notify_db_version' ) ) {
		return 0;
	}

	$table = gueta_notify_table();

	return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE product_id = %d AND notified_at IS NULL", $product_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);
}

/**
 * Queue a product's messages, once.
 *
 * A minute's grace lets a variable product's own status catch up with its
 * variations, and lets a mistaken edit be put right before anyone hears of it.
 *
 * @param int $product_id Product.
 * @param int $delay      Seconds to wait.
 * @return void
 */
function gueta_notify_queue( $product_id, $delay = MINUTE_IN_SECONDS ) {
	$args = [ (int) $product_id ];

	if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
		if ( ! as_has_scheduled_action( GUETA_NOTIFY_HOOK, $args, 'gueta' ) ) {
			as_schedule_single_action( time() + $delay, GUETA_NOTIFY_HOOK, $args, 'gueta' );
		}

		return;
	}

	if ( ! wp_next_scheduled( GUETA_NOTIFY_HOOK, $args ) ) {
		wp_schedule_single_event( time() + $delay, GUETA_NOTIFY_HOOK, $args );
	}
}

/**
 * Send one batch of a product's messages.
 *
 * @param int $product_id Product.
 * @return void
 */
function gueta_notify_send( $product_id ) {
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $product_id ) : null;

	// Sold out again in the meantime, or taken down: the list waits.
	if ( ! $product instanceof WC_Product || ! $product->is_in_stock() || 'publish' !== $product->get_status() ) {
		return;
	}

	global $wpdb;

	$table = gueta_notify_table();
	$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT id, email FROM {$table} WHERE product_id = %d AND notified_at IS NULL ORDER BY id LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$product->get_id(),
			GUETA_NOTIFY_BATCH
		)
	);

	if ( ! $rows ) {
		return;
	}

	$mailer  = WC()->mailer();
	$subject = sprintf( 'חזר למלאי: %s', $product->get_name() );
	$message = $mailer->wrap_message( 'חזר למלאי', gueta_notify_email_body( $product ) );
	$sent    = 0;

	foreach ( $rows as $row ) {
		// A message that did not go out stays waiting, for the next change or
		// for the send button in the admin.
		if ( ! $mailer->send( $row->email, $subject, $message ) ) {
			continue;
		}

		++$sent;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$table,
			[ 'notified_at' => current_time( 'mysql', true ) ],
			[ 'id' => (int) $row->id ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	// A full batch may have more behind it. If none of it went out, the mail
	// itself is broken, and trying again at once would only loop.
	if ( $sent && count( $rows ) === GUETA_NOTIFY_BATCH ) {
		gueta_notify_queue( $product->get_id(), 5 );
	}
}
add_action( GUETA_NOTIFY_HOOK, 'gueta_notify_send' );

/**
 * The message, inside WooCommerce's own email frame.
 *
 * @param WC_Product $product Product.
 * @return string
 */
function gueta_notify_email_body( $product ) {
	$name  = $product->get_name();
	$url   = $product->get_permalink();
	$image = $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) : '';

	ob_start();
	?>
	<p>שלום,</p>
	<p>ביקשתם שנעדכן אתכם כשהמוצר <strong><?php echo esc_html( $name ); ?></strong> יחזור למלאי. הוא חזר, ואפשר להזמין אותו עכשיו.</p>
	<?php if ( $image ) : ?>
		<p><a href="<?php echo esc_url( $url ); ?>"><img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $name ); ?>" width="240" style="display:block;max-width:240px;height:auto;border-radius:7px;"></a></p>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;padding:12px 28px;background:#161616;color:#ffffff;text-decoration:none;font-weight:700;border-radius:8px;">למוצר באתר</a></p>
	<p>המלאי מוגבל, כך שכדאי לא לחכות יותר מדי.</p>
	<?php

	return (string) ob_get_clean();
}

/**
 * Drop the fallback schedule when the theme is switched away.
 *
 * @return void
 */
function gueta_notify_unschedule() {
	wp_unschedule_hook( GUETA_NOTIFY_HOOK );

	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( GUETA_NOTIFY_HOOK );
	}
}
add_action( 'switch_theme', 'gueta_notify_unschedule' );

/* -------------------------------------------------------------------------
 * The admin list
 * ---------------------------------------------------------------------- */

/**
 * Register the list under the Gueta Theme menu.
 *
 * @return void
 */
function gueta_notify_menu() {
	add_submenu_page(
		'gueta-theme',
		'עדכוני חזרה למלאי',
		'עדכוני חזרה למלאי',
		'manage_options',
		'gueta-stock-notify',
		'gueta_notify_admin_page'
	);
}
add_action( 'admin_menu', 'gueta_notify_menu', 12 );

/**
 * Render the list: every product asked about, who waits and who was told.
 *
 * @return void
 */
function gueta_notify_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! gueta_has_woocommerce() ) {
		echo '<div class="wrap"><h1>עדכוני חזרה למלאי</h1><p>הרשימה זמינה כש-WooCommerce פעיל.</p></div>';
		return;
	}

	global $wpdb;

	$notice = '';

	if ( isset( $_POST['gueta_notify_send'] ) && check_admin_referer( 'gueta_notify_send' ) ) {
		$product = wc_get_product( absint( wp_unslash( $_POST['gueta_notify_send'] ) ) );

		if ( $product instanceof WC_Product && $product->is_in_stock() ) {
			gueta_notify_queue( $product->get_id(), 0 );
			$notice = 'ההודעות נכנסו לתור ויישלחו בדקות הקרובות.';
		} else {
			$notice = 'המוצר עדיין לא במלאי, לכן לא נשלח דבר.';
		}
	}

	$table   = gueta_notify_table();
	$ready   = GUETA_NOTIFY_DB_VERSION === get_option( 'gueta_notify_db_version' );
	$groups  = [];
	$waiting = [];

	if ( $ready ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$groups = $wpdb->get_results(
			"SELECT product_id,
				SUM(notified_at IS NULL) AS waiting,
				SUM(notified_at IS NOT NULL) AS told,
				MAX(created_at) AS latest
			FROM {$table}
			GROUP BY product_id
			ORDER BY waiting DESC, latest DESC
			LIMIT 300"
		);

		foreach ( $wpdb->get_results( "SELECT product_id, email FROM {$table} WHERE notified_at IS NULL ORDER BY created_at DESC LIMIT 5000" ) as $row ) {
			$waiting[ (int) $row->product_id ][] = $row->email;
		}
		// phpcs:enable
	}
	?>
	<div class="wrap">
		<h1>עדכוני חזרה למלאי</h1>
		<p>לקוחות שהשאירו מייל במוצר שאזל. כשהמוצר, או אחת הווריאציות שלו, חוזר למלאי, נשלח להם מייל אוטומטית והם עוברים לעמודת "עודכנו".</p>

		<?php if ( $notice ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! $groups ) : ?>
			<p>עדיין אין בקשות.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th>מוצר</th>
						<th style="width:90px;">מלאי</th>
						<th style="width:80px;">ממתינים</th>
						<th style="width:80px;">עודכנו</th>
						<th style="width:150px;">בקשה אחרונה</th>
						<th>כתובות ממתינות</th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $groups as $group ) :
						$id      = (int) $group->product_id;
						$product = wc_get_product( $id );
						$emails  = $waiting[ $id ] ?? [];
						?>
						<tr>
							<td>
								<?php if ( $product instanceof WC_Product ) : ?>
									<strong><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( $product->get_name() ); ?></a></strong>
								<?php else : ?>
									<span style="color:#787c82;">מוצר שנמחק (#<?php echo $id; ?>)</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( $product instanceof WC_Product && $product->is_in_stock() ) : ?>
									<span style="color:#008a20;">במלאי</span>
								<?php elseif ( $product instanceof WC_Product ) : ?>
									<span style="color:#b32d2e;">אזל</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( number_format_i18n( (int) $group->waiting ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( (int) $group->told ) ); ?></td>
							<td><?php echo esc_html( get_date_from_gmt( $group->latest, 'd.m.Y H:i' ) ); ?></td>
							<td>
								<?php if ( $emails ) : ?>
									<details>
										<summary><?php echo esc_html( sprintf( 'הצגת %s כתובות', number_format_i18n( count( $emails ) ) ) ); ?></summary>
										<p style="direction:ltr;text-align:left;user-select:all;"><?php echo esc_html( implode( ', ', $emails ) ); ?></p>
									</details>
									<?php if ( $product instanceof WC_Product && $product->is_in_stock() ) : ?>
										<form method="post" style="margin-top:6px;">
											<?php wp_nonce_field( 'gueta_notify_send' ); ?>
											<button type="submit" class="button button-small" name="gueta_notify_send" value="<?php echo $id; ?>">שליחה עכשיו</button>
										</form>
									<?php endif; ?>
								<?php else : ?>
									<span style="color:#787c82;">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
