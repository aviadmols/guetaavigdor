<?php
/**
 * The magazine: the post page and the page that lists them.
 *
 * The posts were running on the parent theme's default template, which sets no
 * measure, so a line of Hebrew ran eleven hundred pixels wide and the eye lost
 * its place returning to the start of the next one. There was no trail back to
 * the listing and nothing to read next.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Related posts shown under an article.
 */
const GUETA_BLOG_RELATED = 3;

/**
 * Posts on the listing page, past the one held out as the lead.
 */
const GUETA_BLOG_PER_PAGE = 9;

/* -------------------------------------------------------------------------
 * Assets
 * ---------------------------------------------------------------------- */

/**
 * The magazine stylesheet, on the posts and the page that lists them.
 *
 * @return void
 */
function gueta_blog_assets() {
	if ( ! gueta_is_magazine() ) {
		return;
	}

	wp_enqueue_style(
		'gueta-blog',
		get_stylesheet_directory_uri() . '/assets/css/gueta-blog.css',
		[ 'gueta-header' ],
		gueta_asset_version( '/assets/css/gueta-blog.css' )
	);
}
add_action( 'wp_enqueue_scripts', 'gueta_blog_assets', 29 );

/**
 * Whether this request is a post or the listing of them.
 *
 * @return bool
 */
function gueta_is_magazine() {
	return is_singular( 'post' ) || is_home() || is_category() || is_tag() || is_author() || is_date();
}

/* -------------------------------------------------------------------------
 * Pieces the templates use
 * ---------------------------------------------------------------------- */

/**
 * The trail back to where this was found.
 *
 * Yoast prints one when it is installed, and it carries the structured data
 * search engines read, so it wins. Otherwise a plain one is built here.
 *
 * @return void
 */
function gueta_blog_breadcrumb() {
	echo '<nav class="gueta-post__crumbs" aria-label="פירורי לחם">';

	if ( function_exists( 'yoast_breadcrumb' ) ) {
		yoast_breadcrumb( '<span>', '</span>' );
		echo '</nav>';

		return;
	}

	$home = home_url( '/' );
	$blog = get_option( 'page_for_posts' ) ? get_permalink( (int) get_option( 'page_for_posts' ) ) : '';

	printf( '<a href="%s">דף הבית</a>', esc_url( $home ) );

	if ( $blog ) {
		printf( '<span aria-hidden="true"> › </span><a href="%s">מגזין</a>', esc_url( $blog ) );
	}

	if ( is_singular( 'post' ) ) {
		printf(
			'<span aria-hidden="true"> › </span><span class="gueta-post__crumbs-here">%s</span>',
			esc_html( get_the_title() )
		);
	}

	echo '</nav>';
}

/**
 * When it was written. Who wrote it is deliberately left out: these are the
 * shop's articles, not anybody's column.
 *
 * @return void
 */
function gueta_blog_meta() {
	?>
	<p class="gueta-post__meta">
		<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
	</p>
	<?php
}

/**
 * One card in a listing.
 *
 * @param int|WP_Post $post_id Post.
 * @return void
 */
function gueta_blog_card( $post_id = null ) {
	$post_id = $post_id ? get_post( $post_id ) : get_post();

	if ( ! $post_id ) {
		return;
	}

	$id    = $post_id->ID;
	$link  = (string) get_permalink( $id );
	$image = get_the_post_thumbnail( $id, 'large', [ 'loading' => 'lazy', 'alt' => '' ] );
	?>
	<article class="gueta-card-post">
		<a class="gueta-card-post__media" href="<?php echo esc_url( $link ); ?>" tabindex="-1" aria-hidden="true">
			<?php if ( $image ) : ?>
				<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			<?php else : ?>
				<span class="gueta-card-post__blank"></span>
			<?php endif; ?>
		</a>
		<h2 class="gueta-card-post__title">
			<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
		</h2>
		<p class="gueta-card-post__meta">
			<time datetime="<?php echo esc_attr( get_the_date( 'c', $id ) ); ?>"><?php echo esc_html( get_the_date( '', $id ) ); ?></time>
		</p>
	</article>
	<?php
}

/**
 * Posts worth reading next.
 *
 * Anything sharing a category comes first, since that is the closest thing to
 * a subject this site records. If there are not enough, the most recent fill
 * the row, because an empty shelf is worse than a loose match.
 *
 * @param int $post_id Post being read.
 * @return WP_Post[]
 */
function gueta_blog_related( $post_id ) {
	$categories = wp_get_post_categories( $post_id );
	$found      = [];

	if ( $categories ) {
		$found = get_posts(
			[
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => GUETA_BLOG_RELATED,
				'post__not_in'        => [ $post_id ],
				'category__in'        => $categories,
				'ignore_sticky_posts' => true,
				'orderby'             => 'date',
				'order'               => 'DESC',
			]
		);
	}

	if ( count( $found ) >= GUETA_BLOG_RELATED ) {
		return $found;
	}

	$exclude = array_merge( [ $post_id ], wp_list_pluck( $found, 'ID' ) );

	$filler = get_posts(
		[
			'post_type'           => 'post',
			'post_status'         => 'publish',
			'posts_per_page'      => GUETA_BLOG_RELATED - count( $found ),
			'post__not_in'        => $exclude,
			'ignore_sticky_posts' => true,
			'orderby'             => 'date',
			'order'               => 'DESC',
		]
	);

	return array_merge( $found, $filler );
}

/**
 * Render the related row, or nothing when there is nothing to show.
 *
 * @param int $post_id Post being read.
 * @return void
 */
function gueta_blog_render_related( $post_id ) {
	$related = gueta_blog_related( $post_id );

	if ( ! $related ) {
		return;
	}
	?>
	<aside class="gueta-related">
		<div class="gueta-related__inner">
			<h2 class="gueta-related__title">כתבות מומלצות</h2>
			<div class="gueta-related__grid">
				<?php foreach ( $related as $item ) : ?>
					<?php gueta_blog_card( $item ); ?>
				<?php endforeach; ?>
			</div>
		</div>
	</aside>
	<?php
}

/**
 * How many posts the listing shows.
 *
 * The first page holds one back as the lead, so it asks for one more.
 *
 * @param WP_Query $query Query.
 * @return void
 */
function gueta_blog_per_page( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_home() ) {
		return;
	}

	$paged = max( 1, (int) $query->get( 'paged' ) );

	// The first page keeps one back as the lead, so it asks for one more.
	$query->set( 'posts_per_page', 1 === $paged ? GUETA_BLOG_PER_PAGE + 1 : GUETA_BLOG_PER_PAGE );
}
add_action( 'pre_get_posts', 'gueta_blog_per_page' );
