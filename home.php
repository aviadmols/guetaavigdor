<?php
/**
 * The magazine: every post, with the newest held out as the lead.
 *
 * Elementor gets first refusal, the same as the post template, so an archive
 * built in the editor still wins.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$handled = function_exists( 'elementor_theme_do_location' ) && elementor_theme_do_location( 'archive' );

if ( ! $handled ) :
	$paged = max( 1, (int) get_query_var( 'paged' ) );
	$lead  = null;

	// The newest post leads the page, but only on the first page of it.
	if ( 1 === $paged && have_posts() ) {
		the_post();
		$lead = get_post();
	}
	?>

	<div class="gueta-magazine">

		<?php if ( $lead ) : ?>
			<section class="gueta-lead">
				<div class="gueta-lead__inner">
					<div class="gueta-lead__text">
						<?php gueta_blog_breadcrumb(); ?>
						<h1 class="gueta-lead__title">
							<a href="<?php echo esc_url( (string) get_permalink( $lead ) ); ?>"><?php echo esc_html( get_the_title( $lead ) ); ?></a>
						</h1>
						<p class="gueta-lead__meta">
							<span><?php echo esc_html( get_the_author_meta( 'display_name', (int) $lead->post_author ) ); ?></span>
							<span aria-hidden="true">•</span>
							<time datetime="<?php echo esc_attr( get_the_date( 'c', $lead ) ); ?>"><?php echo esc_html( get_the_date( '', $lead ) ); ?></time>
						</p>
						<p class="gueta-lead__excerpt"><?php echo esc_html( wp_trim_words( (string) get_the_excerpt( $lead ), 34 ) ); ?></p>
						<a class="gueta-lead__more" href="<?php echo esc_url( (string) get_permalink( $lead ) ); ?>">המשך קריאה</a>
					</div>

					<a class="gueta-lead__media" href="<?php echo esc_url( (string) get_permalink( $lead ) ); ?>" tabindex="-1" aria-hidden="true">
						<?php if ( has_post_thumbnail( $lead ) ) : ?>
							<?php echo get_the_post_thumbnail( $lead, 'full', [ 'alt' => '' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php else : ?>
							<span class="gueta-lead__blank"></span>
						<?php endif; ?>
					</a>
				</div>
			</section>
		<?php endif; ?>

		<section class="gueta-magazine__list">
			<div class="gueta-magazine__inner">

				<header class="gueta-magazine__head">
					<h2 class="gueta-magazine__title"><?php echo $lead ? 'עוד מהמגזין' : 'מגזין'; ?></h2>
				</header>

				<?php if ( have_posts() ) : ?>
					<div class="gueta-magazine__grid">
						<?php
						while ( have_posts() ) :
							the_post();
							gueta_blog_card();
						endwhile;
						?>
					</div>

					<?php
					$links = paginate_links(
						[
							'prev_text' => 'הקודם',
							'next_text' => 'הבא',
							'type'      => 'list',
						]
					);

					if ( $links ) :
						?>
						<nav class="gueta-magazine__pages" aria-label="עמודי המגזין">
							<?php echo $links; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						</nav>
						<?php
					endif;
					?>
				<?php elseif ( ! $lead ) : ?>
					<p class="gueta-magazine__empty">עוד לא פרסמנו כתבות. בקרוב.</p>
				<?php endif; ?>

			</div>
		</section>

	</div>

	<?php
endif;

get_footer();
