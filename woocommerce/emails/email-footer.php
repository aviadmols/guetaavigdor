<?php
/**
 * Email Footer
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/email-footer.php.
 *
 * Gueta: the theme's copy. The foot of every email names the shop and says how
 * to reach it: the address, the phone and WhatsApp, the email address and the
 * opening hours, with a link to the site. The footer text from WooCommerce's
 * email settings follows in small print, so that setting still shows. The
 * details are in gueta_email_store_details().
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates\Emails
 * @version 10.4.0
 */

defined( 'ABSPATH' ) || exit;

$email = $email ?? null;
$store = function_exists( 'gueta_email_store_details' ) ? gueta_email_store_details() : [];

?>
																		</div>
																	</td>
																</tr>
															</table>
															<!-- End Content -->
														</td>
													</tr>
												</table>
												<!-- End Body -->
											</td>
										</tr>
									</table>
								</td>
							</tr>
							<tr>
								<td align="center" valign="top">
									<!-- Footer -->
									<table border="0" cellpadding="10" cellspacing="0" width="100%" id="template_footer" role="presentation">
										<tr>
											<td valign="top">
												<table border="0" cellpadding="10" cellspacing="0" width="100%" role="presentation">
													<tr>
														<td colspan="2" valign="middle" id="credit">
															<?php if ( $store ) : ?>
																<p class="gueta-email-store"><a href="<?php echo esc_url( $store['url'] ); ?>" style="color: inherit; text-decoration: none;"><?php echo esc_html( $store['name'] ); ?></a></p>
																<p><?php echo esc_html( $store['address'] ); ?></p>
																<p>
																	<a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $store['phone'] ) ); ?>"><?php echo esc_html( $store['phone'] ); ?></a>
																	<?php if ( $store['whatsapp'] ) : ?>
																		&nbsp;·&nbsp; <a href="<?php echo esc_url( 'https://wa.me/' . $store['whatsapp'] ); ?>">וואטסאפ</a>
																	<?php endif; ?>
																	&nbsp;·&nbsp; <a href="<?php echo esc_url( 'mailto:' . $store['email'] ); ?>"><?php echo esc_html( $store['email'] ); ?></a>
																</p>
																<p><?php echo esc_html( $store['hours'] ); ?></p>
																<div class="gueta-email-legal">
															<?php endif; ?>
															<?php
															$email_footer_text = get_option( 'woocommerce_email_footer_text' );
															/**
															 * This filter is documented in templates/emails/email-styles.php
															 *
															 * @since 9.6.0
															 */
															if ( apply_filters( 'woocommerce_is_email_preview', false ) ) {
																$text_transient    = get_transient( 'woocommerce_email_footer_text' );
																$email_footer_text = false !== $text_transient ? $text_transient : $email_footer_text;
															}
															echo wp_kses_post(
																wpautop(
																	wptexturize(
																		/**
																		 * Provides control over the email footer text used for most order emails.
																		 *
																		 * @since 4.0.0
																		 *
																		 * @param string $email_footer_text
																		 */
																		apply_filters( 'woocommerce_email_footer_text', $email_footer_text, $email )
																	)
																)
															);
															?>
															<?php if ( $store ) : ?>
																</div>
															<?php endif; ?>
														</td>
													</tr>
												</table>
											</td>
										</tr>
									</table>
									<!-- End Footer -->
								</td>
							</tr>
						</table>
					</div>
				</td>
				<td><!-- Deliberately empty to support consistent sizing and layout across multiple email clients. --></td>
			</tr>
		</table>
	</body>
</html>
