<?php
/**
 * The official list of Israeli settlements.
 *
 * Pulled once from data.gov.il and written to a JSON file in the uploads
 * folder, the same arrangement the predictive search uses. The checkout reads
 * it in the browser so the city field can complete as somebody types without
 * asking the server, and PHP reads it too, because a field that only validates
 * in the browser does not validate at all.
 *
 * Each row carries the settlement's coordinates on the Israeli grid. That grid
 * is measured in metres, so the distance between two settlements is the plain
 * Pythagorean distance between their points divided by a thousand, with no
 * projection to undo and nothing to approximate. Tel Aviv to Jerusalem comes
 * out at 52.1km against a real straight line of about 52. The shipping tiers
 * will be built on this.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Option describing the list that is live right now.
 */
const GUETA_CITIES_OPTION = 'gueta_cities_index';

/**
 * Folder inside wp-content/uploads holding the generated file.
 */
const GUETA_CITIES_FOLDER = 'gueta-cities';

/**
 * Cron hook for the monthly refresh.
 */
const GUETA_CITIES_HOOK = 'gueta_cities_refresh';

/**
 * The government's settlement list, with names in four languages and a point
 * on the Israeli grid for each.
 */
const GUETA_CITIES_RESOURCE = 'e9701dcb-9f1c-43bb-bd44-eb380ade542f';

/**
 * Rows to ask for. The list holds a little over twelve hundred.
 */
const GUETA_CITIES_LIMIT = 2000;

/* -------------------------------------------------------------------------
 * Where the list lives
 * ---------------------------------------------------------------------- */

/**
 * The stored description of the current list.
 *
 * @return array
 */
function gueta_cities_meta() {
	$meta = get_option( GUETA_CITIES_OPTION, [] );

	return is_array( $meta ) ? $meta : [];
}

/**
 * Absolute path of the folder holding the generated file.
 *
 * @return string Empty when the uploads folder is unavailable.
 */
function gueta_cities_dir() {
	$upload = wp_upload_dir();

	if ( ! empty( $upload['error'] ) ) {
		return '';
	}

	return trailingslashit( $upload['basedir'] ) . GUETA_CITIES_FOLDER;
}

/**
 * Public URL of the current list.
 *
 * @return string Empty when nothing has been built yet.
 */
function gueta_cities_url() {
	$meta = gueta_cities_meta();

	if ( empty( $meta['file'] ) ) {
		return '';
	}

	$upload = wp_upload_dir();

	if ( ! empty( $upload['error'] ) ) {
		return '';
	}

	return trailingslashit( $upload['baseurl'] ) . GUETA_CITIES_FOLDER . '/' . $meta['file'];
}

/**
 * Absolute path of the current list.
 *
 * @return string
 */
function gueta_cities_path() {
	$meta = gueta_cities_meta();
	$dir  = gueta_cities_dir();

	if ( ! $dir || empty( $meta['file'] ) ) {
		return '';
	}

	return $dir . '/' . $meta['file'];
}

/* -------------------------------------------------------------------------
 * Reading it from PHP
 * ---------------------------------------------------------------------- */

/**
 * The settlements, as rows of [ hebrew, english, x, y ].
 *
 * Held for the rest of the request once read, because the checkout asks for
 * it more than once while validating a submission.
 *
 * @return array
 */
function gueta_cities_rows() {
	static $rows = null;

	if ( null !== $rows ) {
		return $rows;
	}

	$rows = [];
	$path = gueta_cities_path();

	if ( ! $path || ! file_exists( $path ) ) {
		return $rows;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$json = file_get_contents( $path );
	$data = $json ? json_decode( $json, true ) : null;

	if ( is_array( $data ) && isset( $data['c'] ) && is_array( $data['c'] ) ) {
		$rows = $data['c'];
	}

	return $rows;
}

/**
 * Fold a name down to something two spellings of the same place agree on.
 *
 * Whitespace collapses, the five final letters become their ordinary forms and
 * quotation marks go, so "תל אביב-יפו" and "תל אביב - יפו" match, and so do
 * "מודיעין" and "מודיעים" typed by somebody in a hurry.
 *
 * @param string $name Name as written.
 * @return string
 */
function gueta_city_key( $name ) {
	$name = (string) $name;
	$name = preg_replace( '/[\x{0591}-\x{05C7}]/u', '', $name );
	$name = str_replace( [ '"', "'", '״', '׳', '`' ], '', $name );
	$name = strtr( $name, [ 'ך' => 'כ', 'ם' => 'מ', 'ן' => 'נ', 'ף' => 'פ', 'ץ' => 'צ' ] );
	$name = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $name );

	return trim( (string) $name );
}

/**
 * Every settlement name, keyed by its folded form.
 *
 * @return array Folded name to the name as the government writes it.
 */
function gueta_cities_by_key() {
	static $map = null;

	if ( null !== $map ) {
		return $map;
	}

	$map = [];

	foreach ( gueta_cities_rows() as $row ) {
		if ( ! isset( $row[0] ) ) {
			continue;
		}

		$map[ gueta_city_key( $row[0] ) ] = $row[0];

		if ( ! empty( $row[1] ) ) {
			$map[ gueta_city_key( $row[1] ) ] = $row[0];
		}
	}

	return $map;
}

/**
 * Whether a name is a settlement, however it was spelled.
 *
 * @param string $name Name as typed.
 * @return bool
 */
function gueta_city_exists( $name ) {
	$map = gueta_cities_by_key();

	return $map && isset( $map[ gueta_city_key( $name ) ] );
}

/**
 * The settlement's own spelling of a name that was typed loosely.
 *
 * @param string $name Name as typed.
 * @return string Empty when it is not a settlement.
 */
function gueta_city_canonical( $name ) {
	$map = gueta_cities_by_key();
	$key = gueta_city_key( $name );

	return isset( $map[ $key ] ) ? $map[ $key ] : '';
}

/**
 * A settlement's point on the Israeli grid, in metres.
 *
 * @param string $name Name as typed.
 * @return array Two floats, or empty when the place is unknown.
 */
function gueta_city_point( $name ) {
	$canonical = gueta_city_canonical( $name );

	if ( ! $canonical ) {
		return [];
	}

	foreach ( gueta_cities_rows() as $row ) {
		if ( isset( $row[0] ) && $row[0] === $canonical && isset( $row[2], $row[3] ) ) {
			return [ (float) $row[2], (float) $row[3] ];
		}
	}

	return [];
}

/**
 * Kilometres between two settlements, straight line.
 *
 * The list holds coordinates on the Israeli grid, which is measured in metres,
 * so this is Pythagoras and a division. No projection, no earth curvature
 * worth the arithmetic over a country this size.
 *
 * @param string $from Settlement name.
 * @param string $to   Settlement name.
 * @return float Kilometres, or -1 when either place is unknown.
 */
function gueta_city_distance( $from, $to ) {
	$a = gueta_city_point( $from );
	$b = gueta_city_point( $to );

	if ( ! $a || ! $b ) {
		return -1.0;
	}

	return sqrt( pow( $a[0] - $b[0], 2 ) + pow( $a[1] - $b[1], 2 ) ) / 1000;
}

/* -------------------------------------------------------------------------
 * Building it
 * ---------------------------------------------------------------------- */

/**
 * Fetch the list from data.gov.il and write it out.
 *
 * @return array The outcome and a message for the admin screen.
 */
function gueta_build_cities() {
	$dir = gueta_cities_dir();

	if ( ! $dir || ! wp_mkdir_p( $dir ) ) {
		return [
			'ok'      => false,
			'message' => 'לא ניתן ליצור את התיקייה בתוך תיקיית ההעלאות.',
		];
	}

	$url = add_query_arg(
		[
			'resource_id' => GUETA_CITIES_RESOURCE,
			'limit'       => GUETA_CITIES_LIMIT,
		],
		'https://data.gov.il/api/3/action/datastore_search'
	);

	$response = wp_remote_get( $url, [ 'timeout' => 60 ] );

	if ( is_wp_error( $response ) ) {
		return [
			'ok'      => false,
			'message' => 'ההורדה מ-data.gov.il נכשלה: ' . $response->get_error_message(),
		];
	}

	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

	if ( empty( $body['success'] ) || empty( $body['result']['records'] ) ) {
		return [
			'ok'      => false,
			'message' => 'התשובה מ-data.gov.il לא הכילה רשומות.',
		];
	}

	$rows = [];

	foreach ( $body['result']['records'] as $record ) {
		$hebrew = gueta_cities_tidy( $record['name_in_hebrew'] ?? '' );

		if ( ! $hebrew ) {
			continue;
		}

		$rows[] = [
			$hebrew,
			gueta_cities_tidy( $record['name_in_english'] ?? '' ),
			isset( $record['X'] ) ? round( (float) $record['X'] ) : 0,
			isset( $record['Y'] ) ? round( (float) $record['Y'] ) : 0,
		];
	}

	if ( count( $rows ) < 100 ) {
		return [
			'ok'      => false,
			'message' => 'התקבלו פחות מדי יישובים; הרשימה הקיימת נשארה במקומה.',
		];
	}

	usort(
		$rows,
		static function ( $a, $b ) {
			return strcmp( $a[0], $b[0] );
		}
	);

	$json = wp_json_encode( [ 'c' => $rows ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

	if ( ! $json ) {
		return [
			'ok'      => false,
			'message' => 'קידוד הרשימה נכשל.',
		];
	}

	$version = substr( md5( $json ), 0, 12 );
	$file    = 'cities-' . $version . '.json';
	$path    = $dir . '/' . $file;
	$meta    = gueta_cities_meta();

	if ( ! empty( $meta['version'] ) && $meta['version'] === $version && file_exists( $path ) ) {
		$meta['checked'] = time();
		update_option( GUETA_CITIES_OPTION, $meta, false );

		return [
			'ok'      => true,
			'message' => sprintf( 'הרשימה נבדקה ונמצאה מעודכנת. %s יישובים.', number_format_i18n( count( $rows ) ) ),
		];
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( false === file_put_contents( $path, $json, LOCK_EX ) ) {
		return [
			'ok'      => false,
			'message' => 'כתיבת הקובץ נכשלה. בדקו הרשאות בתיקיית ההעלאות.',
		];
	}

	$existing = glob( $dir . '/cities-*.json' );

	if ( is_array( $existing ) ) {
		foreach ( $existing as $old ) {
			if ( basename( $old ) !== $file ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $old );
			}
		}
	}

	$located = 0;

	foreach ( $rows as $row ) {
		if ( $row[2] && $row[3] ) {
			$located++;
		}
	}

	update_option(
		GUETA_CITIES_OPTION,
		[
			'file'    => $file,
			'version' => $version,
			'built'   => time(),
			'checked' => time(),
			'count'   => count( $rows ),
			'located' => $located,
			'bytes'   => strlen( $json ),
		],
		false
	);

	return [
		'ok'      => true,
		'message' => sprintf(
			'הרשימה עודכנה: %1$s יישובים, %2$s מהם עם קואורדינטות, %3$s.',
			number_format_i18n( count( $rows ) ),
			number_format_i18n( $located ),
			size_format( strlen( $json ) )
		),
	];
}

/**
 * The government's rows arrive padded and doubly spaced.
 *
 * @param string $value Raw value.
 * @return string
 */
function gueta_cities_tidy( $value ) {
	return trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) );
}

/* -------------------------------------------------------------------------
 * Keeping it current
 * ---------------------------------------------------------------------- */

add_action( GUETA_CITIES_HOOK, 'gueta_build_cities' );

/**
 * The list of settlements changes a few times a year, so once a month is
 * generous.
 *
 * @return void
 */
function gueta_cities_schedule() {
	if ( ! wp_next_scheduled( GUETA_CITIES_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', GUETA_CITIES_HOOK );
	}
}
add_action( 'init', 'gueta_cities_schedule' );

/**
 * Drop the schedule when the theme is switched away.
 *
 * @return void
 */
function gueta_cities_unschedule() {
	wp_clear_scheduled_hook( GUETA_CITIES_HOOK );
}
add_action( 'switch_theme', 'gueta_cities_unschedule' );

/**
 * Fetch the list the first time an administrator looks at the site, so the
 * checkout is never left without one.
 *
 * @return void
 */
function gueta_cities_bootstrap() {
	$meta = gueta_cities_meta();

	if ( empty( $meta['file'] ) && ! wp_next_scheduled( GUETA_CITIES_HOOK ) ) {
		wp_schedule_single_event( time() + 30, GUETA_CITIES_HOOK );
	}
}
add_action( 'admin_init', 'gueta_cities_bootstrap' );

/* -------------------------------------------------------------------------
 * The admin section
 * ---------------------------------------------------------------------- */

/**
 * The settlement list panel on the theme settings screen.
 *
 * @return void
 */
function gueta_cities_admin_section() {
	$notice = '';
	$failed = false;

	if ( isset( $_POST['gueta_cities_rebuild'] ) && check_admin_referer( 'gueta_cities_rebuild' ) ) {
		$result = gueta_build_cities();
		$notice = $result['message'];
		$failed = empty( $result['ok'] );
	}

	$meta    = gueta_cities_meta();
	$url     = gueta_cities_url();
	$built   = ! empty( $meta['built'] ) ? (int) $meta['built'] : 0;
	$when    = $built ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $built ) : '';
	$count   = isset( $meta['count'] ) ? (int) $meta['count'] : 0;
	$located = isset( $meta['located'] ) ? (int) $meta['located'] : 0;
	?>
	<hr>

	<h2>רשימת היישובים</h2>
	<p>
		הרשימה הרשמית מ-data.gov.il, עם שמות בעברית ובאנגלית ונקודה על רשת ישראל לכל יישוב.
		שדה העיר בקופה משלים מתוכה, והיא נשמרת כקובץ אצלנו כדי שההשלמה תהיה מיידית ולא תפנה לשרת בכל הקשה.
		הנקודות ישמשו גם לחישוב מרחק המשלוח מהמחסן.
	</p>

	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo $failed ? 'error' : 'success'; ?> inline">
			<p><?php echo esc_html( $notice ); ?></p>
		</div>
	<?php endif; ?>

	<table class="widefat striped" style="max-width:760px;">
		<tbody>
			<tr>
				<td style="width:200px;"><strong>מצב</strong></td>
				<td>
					<?php if ( $url ) : ?>
						<span class="dashicons dashicons-yes-alt" style="color:#008a20;"></span> פעילה
					<?php else : ?>
						<span class="dashicons dashicons-minus"></span> טרם הורדה
					<?php endif; ?>
				</td>
			</tr>
			<?php if ( $url ) : ?>
				<tr><td><strong>עודכנה</strong></td><td><?php echo esc_html( $when ); ?></td></tr>
				<tr><td><strong>יישובים</strong></td><td><?php echo esc_html( number_format_i18n( $count ) ); ?></td></tr>
				<tr><td><strong>עם קואורדינטות</strong></td><td><?php echo esc_html( number_format_i18n( $located ) ); ?></td></tr>
				<tr>
					<td><strong>הקובץ</strong></td>
					<td><a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( isset( $meta['file'] ) ? $meta['file'] : '' ); ?></a></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<form method="post" style="margin-top:16px;">
		<?php wp_nonce_field( 'gueta_cities_rebuild' ); ?>
		<?php submit_button( 'עדכון הרשימה עכשיו', 'secondary', 'gueta_cities_rebuild', false ); ?>
		<p class="description" style="margin-top:8px;">
			הרשימה מתעדכנת אוטומטית פעם ביום. אם התוכן לא השתנה, הקובץ הקיים נשאר והדפדפנים לא מורידים אותו שוב.
		</p>
	</form>
	<?php
}
