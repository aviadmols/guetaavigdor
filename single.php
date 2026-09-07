<?php
/**
 * A single post.
 *
 * Elementor gets first refusal, so a single template built in the editor still
 * wins and this file steps aside. Anything else, and the article is rendered
 * here: a trail back, the title, who wrote it and when, the picture, the words
 * held to a readable measure, and something to read next.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$handled = function_exists( 'elementor_theme_do_location' ) && elementor_theme_do_location( 'single' );

if ( ! $handled ) :
	while ( have_posts() ) :
		the_post();
		?>
		<article <?php post_class( 'gueta-post' ); ?>>

			<header class="gueta-post__head">
				<div class="gueta-post__measure">
					<?php gueta_blog_breadcrumb(); ?>
					<h1 class="gueta-post__title"><?php the_title(); ?></h1>
					<?php gueta_blog_meta(); ?>
				</div>
			</header>

			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="gueta-post__cover">
					<?php the_post_thumbnail( 'full', [ 'alt' => '' ] ); ?>
				</figure>
			<?php endif; ?>

			<div class="gueta-post__body">
				<div class="gueta-post__measure">
					<?php
					the_content();

					wp_link_pages(
						[
							'before' => '<nav class="gueta-post__pages">',
							'after'  => '</nav>',
						]
					);
					?>
				</div>
			</div>

		</article>

		<?php gueta_blog_render_related( get_the_ID() ); ?>
		<?php
	endwhile;
endif;

get_footer();
