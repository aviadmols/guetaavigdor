<?php
/**
 * My Account Dashboard
 *
 * Shows the first intro screen on the account dashboard.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/myaccount/dashboard.php.
 *
 * Gueta: the theme's copy. In place of WooCommerce's paragraph of links, an
 * overview of what a customer comes to the account for: the latest order and
 * where it stands, how many there have been, the address the next one goes to,
 * and the account's own details. WooCommerce's hooks still run below it.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 4.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$gueta_user    = wp_get_current_user();
$gueta_name    = $gueta_user->first_name ? $gueta_user->first_name : $gueta_user->display_name;
$gueta_last    = wc_get_customer_last_order( $gueta_user->ID );
$gueta_count   = wc_get_customer_order_count( $gueta_user->ID );
$gueta_address = wc_get_account_formatted_address( 'shipping' );
$gueta_address = $gueta_address ? $gueta_address : wc_get_account_formatted_address( 'billing' );
?>

<div class="gueta-account-hello">
	<h2>שלום <?php echo esc_html( $gueta_name ); ?></h2>
	<p>כאן אפשר לעקוב אחרי ההזמנות, לעדכן כתובת למשלוח ולערוך את פרטי החשבון.</p>
</div>

<div class="gueta-account-cards">
	<section class="gueta-account-card gueta-account-card--wide">
		<h3>ההזמנה האחרונה</h3>
		<?php if ( $gueta_last ) : ?>
			<div class="gueta-account-last">
				<div>
					<p class="gueta-account-last__number">הזמנה #<?php echo esc_html( $gueta_last->get_order_number() ); ?></p>
					<p class="gueta-account-muted">
						<?php echo esc_html( wc_format_datetime( $gueta_last->get_date_created() ) ); ?>
						· <?php echo esc_html( sprintf( '%d פריטים', $gueta_last->get_item_count() ) ); ?>
						· <?php echo wp_kses_post( $gueta_last->get_formatted_order_total() ); ?>
					</p>
				</div>
				<?php echo gueta_account_status_pill( $gueta_last ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			</div>
			<a class="gueta-account-link" href="<?php echo esc_url( $gueta_last->get_view_order_url() ); ?>">לפרטי ההזמנה</a>
		<?php else : ?>
			<p class="gueta-account-muted">עוד אין הזמנות בחשבון הזה.</p>
			<a class="gueta-account-link" href="<?php echo esc_url( wc_get_page_permalink( 'shop' ) ); ?>">למוצרים</a>
		<?php endif; ?>
	</section>

	<section class="gueta-account-card">
		<h3>הזמנות</h3>
		<p class="gueta-account-figure"><?php echo esc_html( number_format_i18n( $gueta_count ) ); ?></p>
		<a class="gueta-account-link" href="<?php echo esc_url( wc_get_account_endpoint_url( 'orders' ) ); ?>">לכל ההזמנות</a>
	</section>

	<section class="gueta-account-card">
		<h3>כתובת למשלוח</h3>
		<?php if ( $gueta_address ) : ?>
			<address><?php echo wp_kses_post( $gueta_address ); ?></address>
		<?php else : ?>
			<p class="gueta-account-muted">עוד לא נשמרה כתובת. היא נשמרת בהזמנה הראשונה.</p>
		<?php endif; ?>
		<a class="gueta-account-link" href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-address' ) ); ?>"><?php echo $gueta_address ? 'עריכת כתובת' : 'הוספת כתובת'; ?></a>
	</section>

	<section class="gueta-account-card">
		<h3>פרטי החשבון</h3>
		<p><?php echo esc_html( trim( $gueta_user->first_name . ' ' . $gueta_user->last_name ) ? trim( $gueta_user->first_name . ' ' . $gueta_user->last_name ) : $gueta_user->display_name ); ?></p>
		<p class="gueta-account-muted" dir="ltr"><?php echo esc_html( $gueta_user->user_email ); ?></p>
		<a class="gueta-account-link" href="<?php echo esc_url( wc_get_account_endpoint_url( 'edit-account' ) ); ?>">עריכת פרטים</a>
	</section>
</div>

<?php
	/**
	 * My Account dashboard.
	 *
	 * @since 2.6.0
	 */
	do_action( 'woocommerce_account_dashboard' );

	/**
	 * Deprecated woocommerce_before_my_account action.
	 *
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_before_my_account' );

	/**
	 * Deprecated woocommerce_after_my_account action.
	 *
	 * @deprecated 2.6.0
	 */
	do_action( 'woocommerce_after_my_account' );

/* Omit closing PHP tag at the end of PHP files to avoid "headers already sent" issues. */
