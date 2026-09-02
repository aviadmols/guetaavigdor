<?php
/**
 * Markup helpers used by header.php.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Inline SVG icons, so the header needs no icon font.
 *
 * @param string $name Icon name.
 * @return string
 */
function gueta_icon( $name ) {
	$icons = [
		'search'  => '<circle cx="10.5" cy="10.5" r="6.75"></circle><path d="m15.6 15.6 4.9 4.9"></path>',
		'cart'    => '<path d="M3.5 4.5h2.2l2.2 10.6a1.9 1.9 0 0 0 1.9 1.5h8a1.9 1.9 0 0 0 1.9-1.5l1.4-6.6H6.2"></path><circle cx="9.6" cy="20" r="1.2"></circle><circle cx="18.2" cy="20" r="1.2"></circle>',
		'user'    => '<circle cx="12" cy="8" r="3.8"></circle><path d="M4.8 20.2a7.6 7.6 0 0 1 14.4 0"></path>',
		'close'   => '<path d="m6 6 12 12M18 6 6 18"></path>',
		'chevron' => '<path d="m6 9 6 6 6-6"></path>',
		'back'    => '<path d="M14 5 7 12l7 7"></path>',
		'menu'    => '<path d="M3.5 6.5h17M3.5 12h17M3.5 17.5h17"></path>',
	];

	if ( empty( $icons[ $name ] ) ) {
		return '';
	}

	return '<svg class="gueta-icon gueta-icon--' . esc_attr( $name ) . '" aria-hidden="true" viewBox="0 0 24 24">' . $icons[ $name ] . '</svg>';
}

/**
 * The logo, falling back to the site name as a wordmark.
 *
 * @param string $variant Extra class suffix, used by the mobile bar.
 * @return void
 */
function gueta_render_logo( $variant = '' ) {
	$class = 'gueta-brand' . ( $variant ? ' gueta-brand--' . $variant : '' );
	?>
	<a class="<?php echo esc_attr( $class ); ?>" href="<?php echo esc_url( home_url( '/' ) ); ?>" rel="home">
		<?php
		$logo_id = (int) get_theme_mod( 'custom_logo' );

		if ( $logo_id ) {
			echo wp_get_attachment_image(
				$logo_id,
				'full',
				false,
				[
					'class' => 'gueta-brand__image',
					'alt'   => get_bloginfo( 'name' ),
				]
			);
		} else {
			?>
			<span class="gueta-brand__text"><?php bloginfo( 'name' ); ?></span>
			<?php
		}
		?>
	</a>
	<?php
}

/**
 * The search field plus the container the suggestion panel renders into.
 *
 * @param string $context Either "bar" (always visible) or "overlay" (mobile).
 * @return void
 */
function gueta_render_search( $context = 'bar' ) {
	$id = 'gueta-search-' . $context;
	?>
	<div class="gueta-search gueta-search--<?php echo esc_attr( $context ); ?>" data-search>
		<form class="gueta-search__form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php if ( gueta_has_woocommerce() ) : ?>
				<input type="hidden" name="post_type" value="product">
			<?php endif; ?>
			<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>">חיפוש באתר</label>
			<div class="gueta-search__field">
				<button type="submit" class="gueta-search__submit" aria-label="חיפוש"><?php echo gueta_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				<input
					id="<?php echo esc_attr( $id ); ?>"
					class="gueta-search__input"
					type="search"
					name="s"
					value="<?php echo esc_attr( get_search_query() ); ?>"
					placeholder="חיפוש"
					autocomplete="off"
					autocapitalize="off"
					spellcheck="false"
					role="combobox"
					aria-expanded="false"
					aria-autocomplete="list"
					aria-controls="<?php echo esc_attr( $id ); ?>-results"
					data-search-input
				>
				<button type="button" class="gueta-search__reset" aria-label="ניקוי החיפוש" data-search-reset hidden><?php echo gueta_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				<span class="gueta-search__spinner" aria-hidden="true" data-search-spinner hidden></span>
			</div>
		</form>
		<div
			class="gueta-suggest"
			id="<?php echo esc_attr( $id ); ?>-results"
			role="listbox"
			aria-live="polite"
			data-search-results
			hidden
		></div>
	</div>
	<?php
}

/**
 * The desktop navigation row with its mega menu panels.
 *
 * @return void
 */
function gueta_render_desktop_nav() {
	$items = gueta_header_nav();

	if ( ! $items ) {
		return;
	}
	?>
	<nav class="gueta-nav" aria-label="ניווט ראשי" data-nav>
		<ul class="gueta-nav__list">
			<?php foreach ( $items as $index => $item ) : ?>
				<?php
				$has_mega  = ! empty( $item['mega']['columns'] );
				$panel_id  = 'gueta-mega-' . $index;
				$li_class  = 'gueta-nav__item';
				$li_class .= $has_mega ? ' has-mega' : '';
				$li_class .= ! empty( $item['highlight'] ) ? ' is-highlight' : '';
				?>
				<li class="<?php echo esc_attr( $li_class ); ?>" data-nav-item>
					<a
						class="gueta-nav__link"
						href="<?php echo esc_url( $item['url'] ); ?>"
						<?php if ( $has_mega ) : ?>
							aria-expanded="false"
							aria-controls="<?php echo esc_attr( $panel_id ); ?>"
							data-nav-trigger
						<?php endif; ?>
					>
						<span><?php echo esc_html( $item['title'] ); ?></span>
						<?php echo $has_mega ? gueta_icon( 'chevron' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</a>
					<?php if ( $has_mega ) : ?>
						<div class="gueta-mega" id="<?php echo esc_attr( $panel_id ); ?>" data-nav-panel>
							<div class="gueta-mega__inner">
								<div class="gueta-mega__columns">
									<?php foreach ( $item['mega']['columns'] as $column ) : ?>
										<div class="gueta-mega__column">
											<?php if ( ! empty( $column['title'] ) ) : ?>
												<?php if ( ! empty( $column['url'] ) ) : ?>
													<a class="gueta-mega__heading" href="<?php echo esc_url( $column['url'] ); ?>"><?php echo esc_html( $column['title'] ); ?></a>
												<?php else : ?>
													<p class="gueta-mega__heading"><?php echo esc_html( $column['title'] ); ?></p>
												<?php endif; ?>
											<?php else : ?>
												<span class="gueta-mega__heading gueta-mega__heading--spacer" aria-hidden="true"></span>
											<?php endif; ?>
											<?php if ( ! empty( $column['items'] ) ) : ?>
												<ul class="gueta-mega__links">
													<?php foreach ( $column['items'] as $link ) : ?>
														<li><a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['title'] ); ?></a></li>
													<?php endforeach; ?>
												</ul>
											<?php endif; ?>
										</div>
									<?php endforeach; ?>
								</div>
								<?php if ( ! empty( $item['mega']['banner']['image'] ) ) : ?>
									<a class="gueta-mega__banner" href="<?php echo esc_url( $item['mega']['banner']['url'] ); ?>">
										<img src="<?php echo esc_url( $item['mega']['banner']['image'] ); ?>" alt="" loading="lazy">
										<span><?php echo esc_html( $item['mega']['banner']['title'] ); ?></span>
									</a>
								<?php endif; ?>
							</div>
						</div>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
	</nav>
	<?php
}

/**
 * The mobile menu drawer, an accordion over the same navigation model.
 *
 * @return void
 */
function gueta_render_mobile_nav() {
	$items = gueta_header_nav();
	?>
	<div class="gueta-drawer gueta-drawer--menu" data-menu-drawer>
		<div class="gueta-drawer__backdrop" data-drawer-close></div>
		<div class="gueta-drawer__panel" role="dialog" aria-modal="true" aria-label="תפריט">
			<div class="gueta-drawer__head">
				<h2 class="gueta-drawer__title">תפריט</h2>
				<button type="button" class="gueta-icon-button" data-drawer-close aria-label="סגירת התפריט">
					<?php echo gueta_icon( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</button>
			</div>
			<div class="gueta-drawer__body">
				<ul class="gueta-mobile-nav">
					<?php foreach ( $items as $item ) : ?>
						<?php $has_mega = ! empty( $item['mega']['columns'] ); ?>
						<li class="gueta-mobile-nav__item">
							<?php if ( $has_mega ) : ?>
								<button type="button" class="gueta-mobile-nav__toggle" aria-expanded="false" data-accordion>
									<span><?php echo esc_html( $item['title'] ); ?></span>
									<?php echo gueta_icon( 'chevron' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</button>
								<div class="gueta-mobile-nav__panel" hidden>
									<a class="gueta-mobile-nav__all" href="<?php echo esc_url( $item['url'] ); ?>">הצג את כל המוצרים</a>
									<?php foreach ( $item['mega']['columns'] as $column ) : ?>
										<?php if ( ! empty( $column['title'] ) && ! empty( $column['items'] ) ) : ?>
											<p class="gueta-mobile-nav__group"><?php echo esc_html( $column['title'] ); ?></p>
										<?php endif; ?>
										<ul class="gueta-mobile-nav__links">
											<?php if ( ! empty( $column['title'] ) && empty( $column['items'] ) && ! empty( $column['url'] ) ) : ?>
												<li><a href="<?php echo esc_url( $column['url'] ); ?>"><?php echo esc_html( $column['title'] ); ?></a></li>
											<?php endif; ?>
											<?php foreach ( $column['items'] as $link ) : ?>
												<li><a href="<?php echo esc_url( $link['url'] ); ?>"><?php echo esc_html( $link['title'] ); ?></a></li>
											<?php endforeach; ?>
										</ul>
									<?php endforeach; ?>
								</div>
							<?php else : ?>
								<a class="gueta-mobile-nav__link" href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( gueta_has_woocommerce() ) : ?>
					<div class="gueta-mobile-nav__footer">
						<a href="<?php echo esc_url( (string) wc_get_page_permalink( 'myaccount' ) ); ?>">
							<?php echo gueta_icon( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							<span><?php echo is_user_logged_in() ? 'החשבון שלי' : 'התחברות'; ?></span>
						</a>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php
}

/**
 * The cart drawer shell. Its panel is replaced by WooCommerce cart fragments.
 *
 * @return void
 */
function gueta_render_cart_drawer() {
	if ( ! gueta_has_woocommerce() ) {
		return;
	}
	?>
	<div class="gueta-drawer gueta-drawer--cart" id="gueta-cart-drawer" data-cart-drawer>
		<div class="gueta-drawer__backdrop" data-drawer-close></div>
		<?php echo gueta_cart_panel_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<?php
}

/**
 * The announcement strip above the header.
 *
 * @return void
 */
function gueta_render_announcement() {
	$messages = apply_filters(
		'gueta_announcement_messages',
		[
			'משלוח מהיר לכל הארץ',
			'ייעוץ מקצועי בטלפון לפני כל הזמנה',
			'מאות מוצרים במלאי מיידי',
		]
	);

	$messages = array_values( array_filter( (array) $messages ) );

	if ( ! $messages ) {
		return;
	}
	?>
	<div class="gueta-announce" data-announce>
		<button type="button" class="gueta-announce__arrow" data-announce-prev aria-label="הודעה קודמת"><?php echo gueta_icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
		<div class="gueta-announce__viewport">
			<?php foreach ( $messages as $index => $message ) : ?>
				<p class="gueta-announce__item<?php echo 0 === $index ? ' is-active' : ''; ?>" data-announce-item><?php echo esc_html( $message ); ?></p>
			<?php endforeach; ?>
		</div>
		<button type="button" class="gueta-announce__arrow gueta-announce__arrow--next" data-announce-next aria-label="הודעה הבאה"><?php echo gueta_icon( 'back' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
	</div>
	<?php
}

/**
 * The account and cart cluster shared by the desktop and mobile bars.
 *
 * @return void
 */
function gueta_render_actions() {
	?>
	<div class="gueta-header__actions">
		<?php if ( gueta_has_woocommerce() ) : ?>
			<a class="gueta-icon-button gueta-actions__account" href="<?php echo esc_url( (string) wc_get_page_permalink( 'myaccount' ) ); ?>" aria-label="<?php echo is_user_logged_in() ? 'החשבון שלי' : 'התחברות'; ?>">
				<?php echo gueta_icon( 'user' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
			<button
				type="button"
				class="gueta-icon-button gueta-cart-button"
				aria-label="עגלת קניות"
				aria-expanded="false"
				aria-controls="gueta-cart-drawer"
				data-cart-open
			>
				<?php echo gueta_icon( 'cart' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo gueta_cart_count_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		<?php endif; ?>
	</div>
	<?php
}
