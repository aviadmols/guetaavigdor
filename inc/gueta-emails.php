<?php
/**
 * The shop's emails, in the shop's own quiet style.
 *
 * WooCommerce drew them with a red bar and a logo from 2016, left over from the
 * previous theme's settings. Every email the site sends now looks like the
 * site: the logo centred on white, a thin orange line along the top, the
 * heading in ink, hairlines instead of boxes, and the shop's details at the
 * foot. The header and footer are the theme's own copies of WooCommerce's
 * templates, in woocommerce/emails/, and the colours and type are added to
 * WooCommerce's stylesheet here, so they are inlined with everything else.
 *
 * WordPress's own emails and those of other plugins, a password change or a
 * contact form, arrive as bare text. They are put into the same frame.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The shop's details for the foot of every email.
 *
 * @return array{name:string,address:string,phone:string,whatsapp:string,email:string,hours:string,url:string}
 */
function gueta_email_store_details() {
	return apply_filters(
		'gueta_email_store_details',
		[
			'name'     => 'גואטה אביגדור · מחסן עצים',
			'address'  => 'רח׳ זאב שלנג 3, א.ת.ח ראשון לציון',
			'phone'    => '050-9512625',
			'whatsapp' => '972509512625',
			'email'    => 'info@guetaavigdor.co.il',
			// In words, since a range like 07:30–16:30 is shown back to front in right to left text.
			'hours'    => 'א׳–ה׳ 07:30 עד 16:30 · ו׳ 07:30 עד 13:00',
			'url'      => home_url( '/' ),
		]
	);
}

/**
 * The logo at the top of every email.
 *
 * A PNG, since Gmail and Outlook do not show the site's SVG. It is drawn at
 * twice the width it is shown at, so it stays sharp on a dense screen.
 *
 * @return string
 */
function gueta_email_logo_url() {
	return (string) apply_filters( 'gueta_email_logo_url', get_stylesheet_directory_uri() . '/assets/img/email-logo.png' );
}

/**
 * WooCommerce's newer email layout: a product's picture beside it, totals
 * without borders, and room to breathe. The styles below are written for it,
 * so it is kept on whatever the setting says.
 *
 * @return string
 */
function gueta_email_improvements_on() {
	return 'yes';
}
add_filter( 'pre_option_woocommerce_feature_email_improvements_enabled', 'gueta_email_improvements_on' );

/**
 * The colours and type, after WooCommerce's own email styles.
 *
 * The selectors repeat WooCommerce's, so the inliner, which orders rules by
 * specificity and then by position, lets these win. Where WooCommerce writes a
 * style straight onto an element, as on the dividers and on a product's name,
 * only !important gets past it.
 *
 * @param string $css Email CSS.
 * @return string
 */
function gueta_email_styles( $css ) {
	return $css . "\n" . gueta_email_css();
}
add_filter( 'woocommerce_email_styles', 'gueta_email_styles', 20 );

/**
 * The email stylesheet.
 *
 * @return string
 */
function gueta_email_css() {
	$font   = '"Google Sans", Arial, Helvetica, sans-serif';
	$ink    = '#161616';
	$text   = '#3d3a36';
	$muted  = '#6d6a65';
	$rule   = '#e5e5e5';
	$accent = '#f0591d';
	$start  = is_rtl() ? 'right' : 'left';
	$end    = is_rtl() ? 'left' : 'right';

	return <<<CSS
body,
#outer_wrapper {
	background-color: #f4f4f4;
}

#wrapper {
	padding: 32px 0;
}

#inner_wrapper {
	background-color: #ffffff;
	border: 1px solid {$rule};
	border-radius: 12px;
}

#template_container,
#template_header,
#body_content {
	background-color: #ffffff;
}

.gueta-email-accent {
	background-color: {$accent};
	border-radius: 12px 12px 0 0;
	font-size: 0;
	height: 4px;
	line-height: 4px;
	padding: 0;
}

#template_header_image {
	padding: 36px 40px 0;
}

#template_header_image p {
	margin: 0;
	text-align: center;
}

#template_header_image img {
	height: auto;
	margin: 0;
	width: 220px;
}

#template_header {
	color: {$ink};
	font-family: {$font};
}

#header_wrapper {
	padding: 30px 40px 0;
}

#template_header h1,
#header_wrapper h1 {
	color: {$ink};
	font-family: {$font};
	font-size: 26px;
	font-weight: 700;
	letter-spacing: 0;
	line-height: 130%;
	text-align: center;
}

#body_content table td#body_content_inner_cell {
	padding: 24px 40px 36px;
}

#body_content_inner {
	color: {$text};
	font-family: {$font};
	font-size: 15px;
	line-height: 165%;
	text-align: {$start};
}

#body_content p {
	margin: 0 0 14px;
}

.email-introduction {
	padding-bottom: 12px;
}

h2 {
	color: {$ink};
	font-family: {$font};
	font-size: 18px;
	line-height: 140%;
	margin: 8px 0 14px;
}

h2.email-order-detail-heading span {
	color: {$muted};
	font-size: 13px;
	font-weight: normal;
}

h3 {
	color: {$ink};
	font-family: {$font};
}

a,
.link {
	color: {$ink};
	text-decoration: underline;
}

.td,
.text,
.order-item-data {
	color: {$text};
	font-family: {$font};
}

.font-family {
	font-family: {$font};
}

hr {
	border-top-color: {$rule} !important;
}

#body_content .email-order-details th {
	color: {$muted};
	font-size: 13px;
	font-weight: normal;
}

#body_content .email-order-details .order_item td {
	border-bottom: 1px solid #efefef;
	color: {$ink};
	padding-bottom: 14px;
	padding-top: 14px;
}

#body_content .email-order-details tbody tr:last-child td {
	border-bottom: 0;
	padding-bottom: 8px;
}

.order-item-data h3 {
	color: {$ink} !important;
	font-size: 15px !important;
	font-weight: 700 !important;
	line-height: 140%;
	margin: 0 0 4px;
}

.order-item-data img {
	border: 1px solid {$rule};
	border-radius: 8px;
	height: 56px;
	margin-{$end}: 14px;
	width: 56px;
}

.email-order-item-meta {
	color: {$muted};
	font-size: 13px;
	line-height: 150%;
}

#body_content .email-order-details .order-totals th {
	color: {$muted};
	font-size: 14px;
}

#body_content .email-order-details .order-totals td {
	color: {$ink};
	font-size: 14px;
}

#body_content .email-order-details .order-totals-total th,
#body_content .email-order-details .order-totals-total td {
	color: {$ink};
	font-size: 18px;
	font-weight: 700;
}

/* A divider follows each of these already; a border of their own drew it twice. */
#body_content .email-order-details .order-totals-last td,
#body_content .email-order-details .order-totals-last th {
	border-bottom: 0;
	padding-bottom: 8px;
}

#body_content .email-order-details .order-customer-note td {
	border-bottom: 0;
	padding-bottom: 8px;
	padding-top: 4px;
}

#body_content .email-order-details .order-totals .includes_tax,
#body_content .email-order-details .order-totals small {
	color: {$muted};
	font-size: 12px;
	font-weight: normal;
}

.address-title {
	color: {$ink};
	font-size: 14px;
}

.address {
	color: {$text};
	font-size: 14px;
	line-height: 160%;
	padding: 6px 0 0;
}

#body_content table td td.email-additional-content {
	color: {$muted};
	font-family: {$font};
	font-size: 14px;
	padding: 24px 0 0;
}

#template_footer #credit {
	border-top: 1px solid {$rule};
	color: {$muted};
	font-family: {$font};
	font-size: 13px;
	line-height: 170%;
	padding: 28px 40px 32px;
	text-align: center;
}

#template_footer #credit a {
	color: {$ink};
	text-decoration: none;
}

.gueta-email-store {
	color: {$ink};
	font-size: 14px;
	font-weight: 700;
	margin: 0 0 6px;
}

.gueta-email-legal {
	color: #8a8781;
	font-size: 12px;
	margin-top: 14px;
}

@media screen and (max-width: 600px) {
	#wrapper {
		padding: 0 !important;
	}

	#inner_wrapper {
		border-radius: 0 !important;
	}

	.gueta-email-accent {
		border-radius: 0 !important;
	}

	#template_header_image {
		padding: 28px 20px 0 !important;
	}

	#template_header_image img {
		width: 190px !important;
	}

	#header_wrapper {
		padding: 22px 20px 0 !important;
	}

	#header_wrapper h1 {
		font-size: 22px !important;
	}

	#body_content table td#body_content_inner_cell {
		padding: 16px 20px 28px !important;
	}

	#body_content_inner {
		font-size: 15px !important;
	}

	.email-order-item-meta {
		font-size: 13px !important;
	}

	.order-item-data img {
		height: 48px !important;
		width: 48px !important;
	}

	#body_content .email-order-details .order-totals-total td {
		font-size: 16px !important;
	}

	#template_footer #credit {
		padding: 24px 20px 28px !important;
	}
}
CSS;
}

/**
 * Put WordPress's and other plugins' emails into the shop's frame.
 *
 * A password change, a new user, a contact form: each arrives as bare text, or
 * as a scrap of HTML with no page around it. It is wrapped in the same header
 * and footer WooCommerce's emails use, under a heading taken from the subject,
 * and its styles are inlined the same way. Plain text is escaped first, and a
 * link WordPress wrote between angle brackets loses them, so it is not taken
 * for a tag. An email that is already a whole page, which every WooCommerce
 * email is, is left alone, and so is one sent in several parts.
 *
 * @param array $args wp_mail arguments.
 * @return array
 */
function gueta_frame_plain_emails( $args ) {
	if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Email' ) || empty( $args['message'] ) || ! is_string( $args['message'] ) ) {
		return $args;
	}

	$message = $args['message'];

	if ( false !== stripos( $message, '<html' ) || false !== stripos( $message, '<body' ) ) {
		return $args;
	}

	$headers = isset( $args['headers'] ) ? $args['headers'] : '';
	$lines   = is_array( $headers ) ? $headers : preg_split( "/\r\n|\n|\r/", (string) $headers );
	$lines   = array_values( array_filter( array_map( 'trim', (array) $lines ), 'strlen' ) );
	$is_html = false;

	foreach ( $lines as $index => $line ) {
		if ( 0 !== stripos( $line, 'content-type:' ) ) {
			continue;
		}

		if ( false !== stripos( $line, 'multipart' ) ) {
			return $args;
		}

		$is_html = false !== stripos( $line, 'text/html' );
		unset( $lines[ $index ] );
	}

	if ( ! $is_html && 'text/html' === apply_filters( 'wp_mail_content_type', 'text/plain' ) ) {
		$is_html = true;
	}

	if ( ! $is_html ) {
		$message = preg_replace( '/<((?:https?|mailto):[^>\s]+)>/i', '$1', $message );
		// Line breaks become paragraphs and <br> in wrap_message(), through wpautop().
		$message = make_clickable( esc_html( $message ) );

		// An address reads left to right, or its closing slash jumps to the front in Hebrew text.
		$message = str_replace( '<a href=', '<a dir="ltr" href=', $message );
	}

	$subject = isset( $args['subject'] ) ? wp_strip_all_tags( (string) $args['subject'] ) : '';
	$heading = trim( preg_replace( '/^\[[^\]]*\]\s*/', '', $subject ) );
	$email   = new WC_Email();

	$args['message'] = $email->style_inline( WC()->mailer()->wrap_message( $heading ? $heading : get_bloginfo( 'name', 'display' ), $message ) );
	$lines[]         = 'Content-Type: text/html; charset=UTF-8';
	$args['headers'] = array_values( $lines );

	return $args;
}
add_filter( 'wp_mail', 'gueta_frame_plain_emails', 20 );
