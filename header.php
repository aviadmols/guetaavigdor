<?php
/**
 * Header layout for the Gueta child theme.
 *
 * The parent theme's templates open <main id="content"> immediately after this
 * file, so nothing here may be left unclosed or reuse that id.
 *
 * @package HelloElementorChild
 */

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#content">דילוג לתוכן</a>

<?php gueta_render_announcement(); ?>

<header class="gueta-header" data-header>
	<div class="gueta-header__bar">
		<div class="gueta-header__lead">
			<button type="button" class="gueta-icon-button gueta-header__menu" aria-label="פתיחת תפריט" aria-expanded="false" data-menu-open>
				<?php echo gueta_icon( 'menu' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
			<button type="button" class="gueta-icon-button gueta-header__search-toggle" aria-label="חיפוש" aria-expanded="false" data-search-open>
				<?php echo gueta_icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		</div>

		<div class="gueta-header__brand">
			<?php gueta_render_logo(); ?>
		</div>

		<div class="gueta-header__search">
			<?php gueta_render_search( 'bar' ); ?>
		</div>

		<?php gueta_render_actions(); ?>
	</div>

	<div class="gueta-header__nav">
		<?php gueta_render_desktop_nav(); ?>
	</div>

	<div class="gueta-header__scrim" data-nav-scrim></div>
</header>

<?php gueta_render_mobile_nav(); ?>
<?php gueta_render_cart_drawer(); ?>
