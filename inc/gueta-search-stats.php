<?php
/**
 * What shoppers search for, and what they click.
 *
 * The browser reports two kinds of event to admin-ajax:
 *
 * - a search: the query and how many products it found, once the shopper
 *   stops typing for two seconds or clicks a result in the suggestions, and
 *   when the results page opens;
 * - a click: what was clicked from the suggestions or the results page (a
 *   product, a category, a tag, an article, or "show all"), and at which
 *   place in the list.
 *
 * Each tab carries a random session id, so the same query in the suggestions
 * and on the results page counts as one search, and a click is matched to
 * the search it came from. No IP address, user or cookie is stored. Visits by
 * staff who manage the shop and by bots are not counted, and rows older than
 * a year are dropped.
 *
 * The page under the Gueta Theme menu sums it up: searches, how many found
 * nothing, how many led to a click, the top searches, the searches that found
 * nothing (the words to add to products or a synonym list), and the products
 * most clicked from search.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Raised when the table changes.
 */
const GUETA_SEARCH_STATS_DB_VERSION = '1';

/**
 * Cron hook for dropping old rows.
 */
const GUETA_SEARCH_STATS_PRUNE = 'gueta_search_stats_prune';

/**
 * The table's name.
 *
 * @return string
 */
function gueta_search_stats_table() {
	global $wpdb;

	return $wpdb->prefix . 'gueta_search_log';
}

/**
 * Whether the table is there.
 *
 * @return bool
 */
function gueta_search_stats_ready() {
	return GUETA_SEARCH_STATS_DB_VERSION === get_option( 'gueta_search_stats_db_version' );
}

/**
 * Create or update the table.
 *
 * @return void
 */
function gueta_search_stats_install() {
	if ( gueta_search_stats_ready() ) {
		return;
	}

	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = gueta_search_stats_table();
	$charset = $wpdb->get_charset_collate();

	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			event varchar(8) NOT NULL,
			source varchar(8) NOT NULL,
			query varchar(120) NOT NULL,
			norm varchar(120) NOT NULL,
			results int(11) DEFAULT NULL,
			target_type varchar(10) NOT NULL DEFAULT '',
			target_id bigint(20) unsigned NOT NULL DEFAULT 0,
			target_label varchar(190) NOT NULL DEFAULT '',
			position smallint(5) unsigned NOT NULL DEFAULT 0,
			session char(16) NOT NULL,
			PRIMARY KEY  (id),
			KEY event_time (event,created_at),
			KEY norm (norm)
		) {$charset};"
	);

	update_option( 'gueta_search_stats_db_version', GUETA_SEARCH_STATS_DB_VERSION );
}
add_action( 'admin_init', 'gueta_search_stats_install' );

/* -------------------------------------------------------------------------
 * Recording
 * ---------------------------------------------------------------------- */

/**
 * Whether this request should not be counted: staff testing the search, a
 * bot, or a browser sending too much.
 *
 * @return bool
 */
function gueta_search_stats_skip() {
	if ( current_user_can( 'edit_products' ) || current_user_can( 'manage_options' ) ) {
		return true;
	}

	$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

	if ( '' === $agent || preg_match( '/bot|crawl|spider|slurp|headless|lighthouse|preview|curl|wget|python/i', $agent ) ) {
		return true;
	}

	// At most 120 events in ten minutes from one address. The address is hashed and kept only for the count.
	$address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$key     = 'gueta_ss_' . substr( md5( $address . wp_salt( 'nonce' ) ), 0, 16 );
	$count   = (int) get_transient( $key );

	if ( $count >= 120 ) {
		return true;
	}

	set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

	return false;
}

/**
 * Record one event from the browser.
 *
 * No nonce: the pages that send it are cached for hours, and an event is
 * worth no more than a page view. Every field is checked and bounded instead.
 *
 * @return void
 */
function gueta_search_stats_record() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing
	$field = static function ( $key, $length ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? mb_substr( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ), 0, $length ) : '';
	};

	$event   = $field( 'e', 8 );
	$source  = $field( 'src', 8 );
	$query   = trim( $field( 'q', 120 ) );
	$session = preg_replace( '/[^a-z0-9]/', '', strtolower( $field( 's', 16 ) ) );
	$type    = $field( 't', 10 );
	$results = isset( $_POST['n'] ) && is_numeric( $_POST['n'] ) ? max( 0, min( 100000, (int) $_POST['n'] ) ) : null;
	$target  = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
	$place   = isset( $_POST['pos'] ) ? min( 65535, absint( $_POST['pos'] ) ) : 0;
	$label   = $field( 'label', 190 );
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	$norm = function_exists( 'gueta_search_normalize' ) ? gueta_search_normalize( $query ) : mb_strtolower( $query );

	if (
		! gueta_search_stats_ready()
		|| ! in_array( $event, [ 'search', 'click' ], true )
		|| ! in_array( $source, [ 'suggest', 'page' ], true )
		|| '' === $norm
		|| 16 !== strlen( $session )
		|| ( 'click' === $event && ! in_array( $type, [ 'product', 'category', 'tag', 'article', 'all' ], true ) )
		|| gueta_search_stats_skip()
	) {
		wp_send_json_success();
	}

	// A product's own name, not what the browser says it is.
	if ( 'product' === $type && $target ) {
		$label = get_the_title( $target );
	}

	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
	$wpdb->insert(
		gueta_search_stats_table(),
		[
			'created_at'   => current_time( 'mysql', true ),
			'event'        => $event,
			'source'       => $source,
			'query'        => $query,
			'norm'         => mb_substr( $norm, 0, 120 ),
			'results'      => 'search' === $event ? $results : null,
			'target_type'  => 'click' === $event ? $type : '',
			'target_id'    => 'click' === $event ? $target : 0,
			'target_label' => 'click' === $event ? mb_substr( (string) $label, 0, 190 ) : '',
			'position'     => 'click' === $event ? $place : 0,
			'session'      => $session,
		],
		// A null value goes in as NULL whatever its format says.
		[ '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%s' ]
	);

	wp_send_json_success();
}
add_action( 'wp_ajax_gueta_search_log', 'gueta_search_stats_record' );
add_action( 'wp_ajax_nopriv_gueta_search_log', 'gueta_search_stats_record' );

/**
 * Drop rows older than a year, once a day.
 *
 * @return void
 */
function gueta_search_stats_prune() {
	if ( ! gueta_search_stats_ready() ) {
		return;
	}

	global $wpdb;

	$table = gueta_search_stats_table();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", gmdate( 'Y-m-d H:i:s', time() - YEAR_IN_SECONDS ) ) );
}
add_action( GUETA_SEARCH_STATS_PRUNE, 'gueta_search_stats_prune' );

/**
 * Keep the pruning on the schedule.
 *
 * @return void
 */
function gueta_search_stats_schedule() {
	if ( ! wp_next_scheduled( GUETA_SEARCH_STATS_PRUNE ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', GUETA_SEARCH_STATS_PRUNE );
	}
}
add_action( 'init', 'gueta_search_stats_schedule' );

/* -------------------------------------------------------------------------
 * The report
 * ---------------------------------------------------------------------- */

/**
 * Register the page under the Gueta Theme menu.
 *
 * @return void
 */
function gueta_search_stats_menu() {
	add_submenu_page(
		'gueta-theme',
		'סטטיסטיקת חיפוש',
		'סטטיסטיקת חיפוש',
		'manage_options',
		'gueta-search-stats',
		'gueta_search_stats_page'
	);
}
add_action( 'admin_menu', 'gueta_search_stats_menu', 12 );

/**
 * The figures for a period.
 *
 * A search is one query in one tab, wherever it was typed, so a query seen in
 * the suggestions and then on the results page counts once.
 *
 * @param int $days Days back from now.
 * @return array
 */
function gueta_search_stats_report( $days ) {
	global $wpdb;

	$table = gueta_search_stats_table();
	$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$totals = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT
				COUNT(DISTINCT CASE WHEN event = 'search' THEN CONCAT(session, '|', norm) END) AS searches,
				COUNT(DISTINCT CASE WHEN event = 'search' AND results = 0 THEN CONCAT(session, '|', norm) END) AS empty,
				COUNT(DISTINCT CASE WHEN event = 'click' THEN CONCAT(session, '|', norm) END) AS clicked,
				SUM(event = 'click') AS clicks,
				COUNT(DISTINCT CASE WHEN event = 'search' THEN norm END) AS queries
			FROM {$table} WHERE created_at >= %s",
			$since
		),
		ARRAY_A
	);

	$top = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT norm, MAX(query) AS query,
				COUNT(DISTINCT CASE WHEN event = 'search' THEN session END) AS searches,
				COUNT(DISTINCT CASE WHEN event = 'click' THEN session END) AS clicked,
				ROUND(AVG(CASE WHEN event = 'search' THEN results END)) AS results
			FROM {$table} WHERE created_at >= %s
			GROUP BY norm HAVING searches > 0
			ORDER BY searches DESC, clicked DESC LIMIT 30",
			$since
		),
		ARRAY_A
	);

	$empty = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT norm, MAX(query) AS query, COUNT(DISTINCT session) AS searches, MAX(created_at) AS last_seen
			FROM {$table} WHERE event = 'search' AND results = 0 AND created_at >= %s
			GROUP BY norm ORDER BY searches DESC, last_seen DESC LIMIT 30",
			$since
		),
		ARRAY_A
	);

	$products = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT target_id, MAX(target_label) AS label, COUNT(*) AS clicks, ROUND(AVG(position), 1) AS position,
				COUNT(DISTINCT norm) AS queries
			FROM {$table} WHERE event = 'click' AND target_type = 'product' AND created_at >= %s
			GROUP BY target_id ORDER BY clicks DESC LIMIT 20",
			$since
		),
		ARRAY_A
	);

	$kinds = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT target_type, source, COUNT(*) AS clicks
			FROM {$table} WHERE event = 'click' AND created_at >= %s
			GROUP BY target_type, source ORDER BY clicks DESC",
			$since
		),
		ARRAY_A
	);

	$recent = $wpdb->get_results(
		"SELECT created_at, event, source, query, results, target_type, target_label, position
		FROM {$table} ORDER BY id DESC LIMIT 40",
		ARRAY_A
	);
	// phpcs:enable

	return [
		'totals'   => array_map( 'intval', (array) $totals ),
		'top'      => (array) $top,
		'empty'    => (array) $empty,
		'products' => (array) $products,
		'kinds'    => (array) $kinds,
		'recent'   => (array) $recent,
	];
}

/**
 * A share as a whole percentage.
 *
 * @param int $part  Part.
 * @param int $whole Whole.
 * @return string
 */
function gueta_search_stats_percent( $part, $whole ) {
	return $whole > 0 ? number_format_i18n( 100 * $part / $whole ) . '%' : '—';
}

/**
 * Render the page.
 *
 * @return void
 */
function gueta_search_stats_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$periods = [
		7   => '7 ימים',
		30  => '30 ימים',
		90  => '90 ימים',
		365 => 'שנה',
	];
	$days    = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$days    = isset( $periods[ $days ] ) ? $days : 30;
	$base    = admin_url( 'admin.php?page=gueta-search-stats' );
	$kinds   = [
		'product'  => 'מוצר',
		'category' => 'קטגוריה',
		'tag'      => 'תגית',
		'article'  => 'מאמר',
		'all'      => '"הצג את כל התוצאות"',
	];
	$sources = [
		'suggest' => 'הצעות בזמן הקלדה',
		'page'    => 'דף התוצאות',
	];
	?>
	<div class="wrap gueta-ss">
		<h1>סטטיסטיקת חיפוש</h1>
		<p class="description">
			חיפוש נספר אחרי שהלקוח מפסיק להקליד לשתי שניות, לוחץ על תוצאה או פותח את דף התוצאות. אותה מילה באותה לשונית נספרת פעם אחת.
			ביקורים של מנהלי החנות ושל רובוטים לא נספרים.
		</p>

		<nav class="nav-tab-wrapper">
			<?php foreach ( $periods as $value => $label ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'days', $value, $base ) ); ?>" class="nav-tab<?php echo $value === $days ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>

		<?php if ( ! gueta_search_stats_ready() ) : ?>
			<div class="notice notice-warning inline"><p>טבלת הסטטיסטיקה עוד לא נוצרה. רעננו את העמוד.</p></div>
			<?php
			echo '</div>';
			return;
		endif;

		$report = gueta_search_stats_report( $days );
		$totals = $report['totals'];
		?>

		<div class="gueta-ss__tiles">
			<div class="gueta-ss__tile">
				<span class="gueta-ss__label">חיפושים</span>
				<strong><?php echo esc_html( number_format_i18n( $totals['searches'] ) ); ?></strong>
				<span><?php echo esc_html( number_format_i18n( $totals['queries'] ) ); ?> מילים שונות</span>
			</div>
			<div class="gueta-ss__tile">
				<span class="gueta-ss__label">הובילו ללחיצה</span>
				<strong><?php echo esc_html( gueta_search_stats_percent( $totals['clicked'], $totals['searches'] ) ); ?></strong>
				<span><?php echo esc_html( number_format_i18n( $totals['clicks'] ) ); ?> לחיצות בסך הכול</span>
			</div>
			<div class="gueta-ss__tile<?php echo $totals['empty'] ? ' is-warning' : ''; ?>">
				<span class="gueta-ss__label">בלי תוצאות</span>
				<strong><?php echo esc_html( gueta_search_stats_percent( $totals['empty'], $totals['searches'] ) ); ?></strong>
				<span><?php echo esc_html( number_format_i18n( $totals['empty'] ) ); ?> חיפושים</span>
			</div>
		</div>

		<?php if ( ! $totals['searches'] && ! $totals['clicks'] ) : ?>
			<p>עוד אין חיפושים בתקופה הזו. הנתונים מתחילים להיאסף מרגע שהתבנית עלתה.</p>
		<?php endif; ?>

		<div class="gueta-ss__grid">
			<section>
				<h2>החיפושים הנפוצים</h2>
				<table class="widefat striped">
					<thead><tr><th>מילת חיפוש</th><th class="num">חיפושים</th><th class="num">תוצאות בממוצע</th><th class="num">הובילו ללחיצה</th></tr></thead>
					<tbody>
						<?php foreach ( $report['top'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['query'] ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['searches'] ) ); ?></td>
								<td class="num"><?php echo null === $row['results'] ? '—' : esc_html( number_format_i18n( (int) $row['results'] ) ); ?></td>
								<td class="num"><?php echo esc_html( gueta_search_stats_percent( (int) $row['clicked'], (int) $row['searches'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( ! $report['top'] ) : ?>
							<tr><td colspan="4">אין עדיין.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</section>

			<section>
				<h2>חיפושים בלי תוצאות</h2>
				<p class="description">מה לקוחות חיפשו ולא מצאו: מוצר חסר, או מילה שכדאי להוסיף לשם המוצר, לתגית או לרשימת מילים נרדפות.</p>
				<table class="widefat striped">
					<thead><tr><th>מילת חיפוש</th><th class="num">חיפושים</th><th>לאחרונה</th></tr></thead>
					<tbody>
						<?php foreach ( $report['empty'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['query'] ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['searches'] ) ); ?></td>
								<td><?php echo esc_html( wp_date( 'j.n.Y H:i', strtotime( $row['last_seen'] . ' UTC' ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( ! $report['empty'] ) : ?>
							<tr><td colspan="3">אין חיפושים בלי תוצאות.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</section>

			<section>
				<h2>המוצרים שנלחצו הכי הרבה מתוך החיפוש</h2>
				<table class="widefat striped">
					<thead><tr><th>מוצר</th><th class="num">לחיצות</th><th class="num">מקום ממוצע ברשימה</th><th class="num">ממילים שונות</th></tr></thead>
					<tbody>
						<?php foreach ( $report['products'] as $row ) : ?>
							<tr>
								<td>
									<?php if ( get_post( (int) $row['target_id'] ) ) : ?>
										<a href="<?php echo esc_url( (string) get_edit_post_link( (int) $row['target_id'] ) ); ?>"><?php echo esc_html( $row['label'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $row['label'] ); ?>
									<?php endif; ?>
								</td>
								<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['clicks'] ) ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( (float) $row['position'], 1 ) ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['queries'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( ! $report['products'] ) : ?>
							<tr><td colspan="4">אין עדיין.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</section>

			<section>
				<h2>על מה לחצו</h2>
				<table class="widefat striped">
					<thead><tr><th>סוג</th><th>מאיפה</th><th class="num">לחיצות</th></tr></thead>
					<tbody>
						<?php foreach ( $report['kinds'] as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $kinds[ $row['target_type'] ] ?? $row['target_type'] ); ?></td>
								<td><?php echo esc_html( $sources[ $row['source'] ] ?? $row['source'] ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['clicks'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						<?php if ( ! $report['kinds'] ) : ?>
							<tr><td colspan="3">אין עדיין.</td></tr>
						<?php endif; ?>
					</tbody>
				</table>
			</section>
		</div>

		<h2>אחרונים</h2>
		<table class="widefat striped">
			<thead><tr><th>מתי</th><th>מה</th><th>מילת חיפוש</th><th>תוצאות או לחיצה</th></tr></thead>
			<tbody>
				<?php foreach ( $report['recent'] as $row ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'j.n H:i', strtotime( $row['created_at'] . ' UTC' ) ) ); ?></td>
						<td><?php echo esc_html( ( 'click' === $row['event'] ? 'לחיצה' : 'חיפוש' ) . ' · ' . ( $sources[ $row['source'] ] ?? $row['source'] ) ); ?></td>
						<td><?php echo esc_html( $row['query'] ); ?></td>
						<td>
							<?php
							if ( 'click' === $row['event'] ) {
								echo esc_html( ( $kinds[ $row['target_type'] ] ?? '' ) . ( $row['target_label'] ? ': ' . $row['target_label'] : '' ) . ( $row['position'] ? ' (מקום ' . $row['position'] . ')' : '' ) );
							} else {
								echo null === $row['results'] ? '—' : esc_html( number_format_i18n( (int) $row['results'] ) . ' תוצאות' );
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $report['recent'] ) : ?>
					<tr><td colspan="4">אין עדיין.</td></tr>
				<?php endif; ?>
			</tbody>
		</table>
	</div>

	<style>
		.gueta-ss .nav-tab-wrapper { margin: 16px 0 20px; }
		.gueta-ss__tiles { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
		.gueta-ss__tile { background: #fff; border: 1px solid #dcdcde; border-radius: 6px; display: flex; flex-direction: column; gap: 4px; min-width: 200px; padding: 16px 20px; }
		.gueta-ss__tile strong { font-size: 32px; font-variant-numeric: tabular-nums; line-height: 1.2; }
		.gueta-ss__tile.is-warning { border-inline-start: 4px solid #dba617; }
		.gueta-ss__label { color: #50575e; font-size: 13px; font-weight: 600; }
		.gueta-ss__grid { display: grid; gap: 24px 32px; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); margin-bottom: 24px; }
		.gueta-ss__grid section { min-width: 0; }
		.gueta-ss .num { font-variant-numeric: tabular-nums; text-align: center; width: 110px; }
	</style>
	<?php
}
