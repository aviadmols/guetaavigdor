<?php
/**
 * Swapping one font name for another across the site.
 *
 * A stylesheet can only argue with Elementor's typography; it cannot change it.
 * The family name is stored in the database, in the page data Elementor writes
 * per post, in the kit that holds the global typography, in page level
 * settings, in options, and in whatever custom CSS somebody typed. This finds
 * every one of them and rewrites the name.
 *
 * It is deliberately two steps. The scan reads and reports; only an explicit
 * confirmation writes anything, because there is no undo short of a backup.
 *
 * Serialisation is respected throughout. Elementor's page data is JSON and is
 * rewritten as a string, everything else goes through get_option and
 * get_post_meta, so PHP's own arrays are walked and rebuilt rather than having
 * their byte lengths broken by a blind replace.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The name this site is moving away from.
 */
const GUETA_FONT_MIGRATE_FROM = 'ArbelHagilda';

/**
 * The name it is moving to.
 */
const GUETA_FONT_MIGRATE_TO = 'Google Sans';

/**
 * Replace a string everywhere inside a value, however deeply it is nested,
 * counting the hits as it goes.
 *
 * @param mixed  $value Value from the database.
 * @param string $from  Name to find.
 * @param string $to    Name to write.
 * @param int    $count Running total, by reference.
 * @return mixed The value with every occurrence rewritten.
 */
function gueta_font_replace_deep( $value, $from, $to, &$count ) {
	if ( is_string( $value ) ) {
		$hits = substr_count( $value, $from );

		if ( ! $hits ) {
			return $value;
		}

		$count += $hits;

		return str_replace( $from, $to, $value );
	}

	if ( is_array( $value ) ) {
		foreach ( $value as $key => $item ) {
			$value[ $key ] = gueta_font_replace_deep( $item, $from, $to, $count );
		}

		return $value;
	}

	if ( is_object( $value ) ) {
		foreach ( get_object_vars( $value ) as $key => $item ) {
			$value->$key = gueta_font_replace_deep( $item, $from, $to, $count );
		}

		return $value;
	}

	return $value;
}

/**
 * Posts whose Elementor data or page settings mention the name.
 *
 * The custom font definition itself is left out. That post is what loads the
 * old typeface's files, and renaming it would break the very thing somebody
 * would need in order to change their mind.
 *
 * @param string $meta_key Meta key to search.
 * @param string $from     Name to find.
 * @return int[]
 */
function gueta_font_migrate_post_ids( $meta_key, $from ) {
	global $wpdb;

	return array_map(
		'absint',
		(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT pm.post_id
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND pm.meta_value LIKE %s
				   AND p.post_type != 'elementor_font'",
				$meta_key,
				'%' . $wpdb->esc_like( $from ) . '%'
			)
		)
	);
}

/**
 * Options whose value mentions the name, skipping the caches that will be
 * rebuilt anyway.
 *
 * @param string $from Name to find.
 * @return string[]
 */
function gueta_font_migrate_option_names( $from ) {
	global $wpdb;

	$names = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_value LIKE %s
			   AND option_name NOT LIKE %s
			   AND option_name NOT LIKE %s",
			'%' . $wpdb->esc_like( $from ) . '%',
			$wpdb->esc_like( '_transient_' ) . '%',
			$wpdb->esc_like( '_site_transient_' ) . '%'
		)
	);

	return array_values( array_filter( $names ) );
}

/**
 * Find every mention of a font name, and rewrite them when asked to.
 *
 * @param string $from  Name to find.
 * @param string $to    Name to write.
 * @param bool   $apply False to only report, true to write.
 * @return array The tally, by where it was found.
 */
function gueta_font_migrate( $from, $to, $apply = false ) {
	$report = [
		'data_posts'     => 0,
		'data_hits'      => 0,
		'settings_posts' => 0,
		'settings_hits'  => 0,
		'options'        => 0,
		'option_hits'    => 0,
		'css_hits'       => 0,
		'applied'        => (bool) $apply,
	];

	if ( ! $from || $from === $to ) {
		return $report;
	}

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	// Elementor's page data is JSON held as one string, so it is rewritten whole.
	foreach ( gueta_font_migrate_post_ids( '_elementor_data', $from ) as $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( ! is_string( $raw ) || ! $raw ) {
			continue;
		}

		$hits = substr_count( $raw, $from );

		if ( ! $hits ) {
			continue;
		}

		$report['data_posts']++;
		$report['data_hits'] += $hits;

		if ( $apply ) {
			// Slashed on the way in, exactly as Elementor writes it itself.
			update_post_meta( $post_id, '_elementor_data', wp_slash( str_replace( $from, $to, $raw ) ) );
		}
	}

	// Page level settings, and the kit that carries the global typography.
	foreach ( gueta_font_migrate_post_ids( '_elementor_page_settings', $from ) as $post_id ) {
		$settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		$hits     = 0;
		$updated  = gueta_font_replace_deep( $settings, $from, $to, $hits );

		if ( ! $hits ) {
			continue;
		}

		$report['settings_posts']++;
		$report['settings_hits'] += $hits;

		if ( $apply ) {
			update_post_meta( $post_id, '_elementor_page_settings', wp_slash( $updated ) );
		}
	}

	foreach ( gueta_font_migrate_option_names( $from ) as $name ) {
		$value   = get_option( $name );
		$hits    = 0;
		$updated = gueta_font_replace_deep( $value, $from, $to, $hits );

		if ( ! $hits ) {
			continue;
		}

		$report['options']++;
		$report['option_hits'] += $hits;

		if ( $apply ) {
			update_option( $name, $updated );
		}
	}

	// The Additional CSS box in the customiser is a post, not an option.
	$css_post = function_exists( 'wp_get_custom_css_post' ) ? wp_get_custom_css_post() : null;

	if ( $css_post && false !== strpos( (string) $css_post->post_content, $from ) ) {
		$report['css_hits'] = substr_count( (string) $css_post->post_content, $from );

		if ( $apply ) {
			wp_update_post(
				[
					'ID'           => $css_post->ID,
					'post_content' => str_replace( $from, $to, $css_post->post_content ),
				]
			);
		}
	}

	if ( $apply ) {
		gueta_font_migrate_clear_caches();
	}

	return $report;
}

/**
 * Throw away the CSS Elementor generated from the old settings, so the next
 * page view builds it again from what is now stored.
 *
 * @return void
 */
function gueta_font_migrate_clear_caches() {
	if ( class_exists( '\Elementor\Plugin' ) ) {
		$elementor = \Elementor\Plugin::$instance;

		if ( isset( $elementor->files_manager ) ) {
			$elementor->files_manager->clear_cache();
		}
	}

	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}

/* -------------------------------------------------------------------------
 * The admin section
 * ---------------------------------------------------------------------- */

/**
 * The font replacement panel on the theme settings screen.
 *
 * @return void
 */
function gueta_font_migrate_admin_section() {
	$from   = GUETA_FONT_MIGRATE_FROM;
	$to     = GUETA_FONT_MIGRATE_TO;
	$report = null;
	$did    = false;

	if ( isset( $_POST['gueta_font_scan'] ) && check_admin_referer( 'gueta_font_migrate' ) ) {
		$from   = sanitize_text_field( wp_unslash( $_POST['gueta_font_from'] ?? $from ) );
		$to     = sanitize_text_field( wp_unslash( $_POST['gueta_font_to'] ?? $to ) );
		$report = gueta_font_migrate( $from, $to, false );
	}

	if ( isset( $_POST['gueta_font_apply'] ) && check_admin_referer( 'gueta_font_migrate' ) ) {
		$from = sanitize_text_field( wp_unslash( $_POST['gueta_font_from'] ?? $from ) );
		$to   = sanitize_text_field( wp_unslash( $_POST['gueta_font_to'] ?? $to ) );

		if ( empty( $_POST['gueta_font_confirm'] ) ) {
			$report = gueta_font_migrate( $from, $to, false );
		} else {
			$report = gueta_font_migrate( $from, $to, true );
			$did    = true;
		}
	}

	$total = $report
		? $report['data_hits'] + $report['settings_hits'] + $report['option_hits'] + $report['css_hits']
		: null;
	?>
	<hr>

	<h2>החלפת פונט באתר</h2>
	<p>
		שם הפונט שמור במסד הנתונים, בתוך הנתונים ש-Elementor כותב לכל עמוד, ב-Kit שמחזיק את הטיפוגרפיה הגלובלית,
		בהגדרות עמוד, באפשרויות ובכל CSS מותאם שנכתב. גיליון סגנונות יכול רק להתווכח עם זה. כאן מחליפים את השם עצמו.
	</p>
	<p>
		<strong>גבו את מסד הנתונים לפני ההחלפה.</strong> אין כאן ביטול.
		הסריקה בטוחה לגמרי והיא רק קוראת ומדווחת, אז כדאי להתחיל ממנה.
	</p>

	<form method="post">
		<?php wp_nonce_field( 'gueta_font_migrate' ); ?>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="gueta-font-from">להחליף את</label></th>
				<td>
					<input type="text" id="gueta-font-from" name="gueta_font_from" class="regular-text" value="<?php echo esc_attr( $from ); ?>">
					<p class="description">שם המשפחה כפי שהוא מופיע היום.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="gueta-font-to">בשם</label></th>
				<td>
					<input type="text" id="gueta-font-to" name="gueta_font_to" class="regular-text" value="<?php echo esc_attr( $to ); ?>">
					<p class="description">התבנית כבר טוענת את Google Sans, כך שהשם הזה יעבוד בלי הגדרה נוספת.</p>
				</td>
			</tr>
		</table>

		<?php if ( null !== $report ) : ?>
			<div class="notice notice-<?php echo $did ? 'success' : 'info'; ?> inline">
				<p>
					<strong>
						<?php
						echo $did
							? esc_html( sprintf( 'הוחלפו %s מופעים.', number_format_i18n( $total ) ) )
							: esc_html( sprintf( 'נמצאו %s מופעים. שום דבר לא שונה.', number_format_i18n( $total ) ) );
						?>
					</strong>
				</p>
			</div>

			<table class="widefat striped" style="max-width:760px;margin-bottom:16px;">
				<thead>
					<tr><th>היכן</th><th style="width:120px;">מופעים</th><th style="width:140px;">פריטים</th></tr>
				</thead>
				<tbody>
					<tr>
						<td>נתוני Elementor של עמודים</td>
						<td><?php echo esc_html( number_format_i18n( $report['data_hits'] ) ); ?></td>
						<td><?php echo esc_html( sprintf( '%s עמודים', number_format_i18n( $report['data_posts'] ) ) ); ?></td>
					</tr>
					<tr>
						<td>הגדרות עמוד ו-Kit גלובלי</td>
						<td><?php echo esc_html( number_format_i18n( $report['settings_hits'] ) ); ?></td>
						<td><?php echo esc_html( sprintf( '%s פריטים', number_format_i18n( $report['settings_posts'] ) ) ); ?></td>
					</tr>
					<tr>
						<td>אפשרויות</td>
						<td><?php echo esc_html( number_format_i18n( $report['option_hits'] ) ); ?></td>
						<td><?php echo esc_html( sprintf( '%s אפשרויות', number_format_i18n( $report['options'] ) ) ); ?></td>
					</tr>
					<tr>
						<td>CSS מותאם בהתאמה אישית</td>
						<td><?php echo esc_html( number_format_i18n( $report['css_hits'] ) ); ?></td>
						<td>&mdash;</td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>

		<p>
			<?php submit_button( 'סריקה בלבד', 'secondary', 'gueta_font_scan', false ); ?>
		</p>

		<p style="margin-top:20px;padding:14px;border:1px solid #dcdcde;background:#fff;max-width:760px;">
			<label>
				<input type="checkbox" name="gueta_font_confirm" value="1">
				גיביתי את מסד הנתונים ואני מבין שאי אפשר לבטל את הפעולה.
			</label>
			<br><br>
			<?php submit_button( 'החלפה עכשיו', 'delete', 'gueta_font_apply', false ); ?>
		</p>
	</form>

	<p class="description" style="max-width:760px;">
		הפונט המותאם הישן עצמו לא נמחק. ההגדרה שטוענת את קבצי הגופן נשארת במקומה, כדי שתהיה דרך חזרה אם תרצו.
		אחרי ההחלפה, קובצי ה-CSS ש-Elementor ייצר נמחקים והוא בונה אותם מחדש בכניסה הבאה לאתר.
	</p>
	<?php
}
