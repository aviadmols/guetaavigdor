<?php
/**
 * Header layout for the Gueta child theme.
 *
 * @package HelloElementorChild
 */
?><!doctype html>
<html <?php language_attributes(); ?> dir="rtl">
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<div class="gueta-site-shell">
	<div class="gueta-announcement">משלוח מהיר לכל הארץ <span>•</span> עיצוב שמרגיש כמו בבית</div>
	<header class="gueta-header" data-gueta-header>
		<div class="gueta-header__inner">
			<button class="gueta-icon-button gueta-menu-toggle" type="button" aria-label="פתיחת תפריט" aria-expanded="false" data-menu-toggle>
				<span></span><span></span>
			</button>
			<a class="gueta-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>" aria-label="<?php bloginfo( 'name' ); ?>">
				<?php if ( has_custom_logo() ) : ?>
					<?php the_custom_logo(); ?>
				<?php else : ?>
					<span><?php bloginfo( 'name' ); ?></span>
				<?php endif; ?>
			</a>
			<nav class="gueta-desktop-nav" aria-label="ניווט ראשי">
				<?php
				wp_nav_menu(
					[
						'theme_location' => 'primary',
						'container'      => false,
						'menu_class'     => 'gueta-menu',
						'fallback_cb'    => 'gueta_header_fallback_menu',
					]
				);
				?>
			</nav>
			<div class="gueta-header__actions">
				<button class="gueta-icon-button" type="button" aria-label="חיפוש" aria-expanded="false" data-search-toggle>
					<svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg>
				</button>
				<a class="gueta-cart-link" href="<?php echo esc_url( function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' ) ); ?>" aria-label="עגלת קניות">
					<svg aria-hidden="true" viewBox="0 0 24 24"><path d="M3 4h2l2.1 11.2a2 2 0 0 0 2 1.6h8.7a2 2 0 0 0 2-1.6L21 8H6"></path><circle cx="9" cy="20" r="1"></circle><circle cx="18" cy="20" r="1"></circle></svg>
					<span class="gueta-cart-count"><?php echo function_exists( 'WC' ) && WC()->cart ? esc_html( WC()->cart->get_cart_contents_count() ) : '0'; ?></span>
				</a>
			</div>
		</div>
		<div class="gueta-search-panel" data-search-panel>
			<form class="gueta-search-form" role="search" action="<?php echo esc_url( home_url( '/' ) ); ?>" method="get" data-search-form>
				<label class="screen-reader-text" for="gueta-header-search">חיפוש באתר</label>
				<input id="gueta-header-search" type="search" name="s" placeholder="מה תרצו למצוא?" autocomplete="off" data-search-input>
				<button type="submit" aria-label="ביצוע חיפוש"><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="10.8" cy="10.8" r="6.8"></circle><path d="m16 16 5 5"></path></svg></button>
			</form>
			<div class="gueta-search-results" data-search-results aria-live="polite"></div>
		</div>
	</header>
	<div class="gueta-mobile-menu" data-mobile-menu aria-hidden="true">
		<div class="gueta-mobile-menu__head"><span>תפריט</span><button class="gueta-icon-button" type="button" aria-label="סגירת תפריט" data-menu-close>×</button></div>
		<nav aria-label="ניווט מובייל">
			<?php
			wp_nav_menu(
				[
					'theme_location' => 'primary',
					'container'      => false,
					'menu_class'     => 'gueta-mobile-nav',
					'fallback_cb'    => 'gueta_header_fallback_menu',
				]
			);
			?>
		</nav>
	</div>
	<div class="gueta-menu-backdrop" data-menu-close></div>