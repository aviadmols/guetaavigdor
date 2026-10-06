<?php
/**
 * Finding a product however it is spelled, for the search results page.
 *
 * The header's suggestions search the index file in the browser
 * (assets/js/gueta-header.js); this does the same on the server, from the
 * same file, so "show all results" lists what the suggestions promised. The
 * two are kept in step.
 *
 * Every word in the catalogue is a vector of its three letter pieces, " מק",
 * "מקי", "קיט"..., together with the pieces of how it sounds: plural ending
 * off, ו and י after the first letter off, and letters that sound alike
 * folded (ט ת, ק כ ח, ע א ה, ס ש). A word typed is compared with every word in
 * the catalogue by the angle between their vectors, so "מקיטא" finds "מקיטה"
 * and "איסקורית" finds "איסכורית". A query typed with the keyboard left in
 * English, "ncrdv", is also read as the Hebrew keys it was meant for.
 *
 * No outside service is asked and nothing is paid for: the vectors come from
 * the index file, which is built once a day or from the button.
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lower case, no niqqud or quotes, final letters in their ordinary form, and
 * anything but letters and digits a space.
 *
 * @param string $text Text.
 * @return string
 */
function gueta_search_normalize( $text ) {
	$text = mb_strtolower( (string) $text, 'UTF-8' );
	$text = preg_replace( '/[\x{0591}-\x{05C7}]/u', '', $text );
	$text = preg_replace( '/[\x{05F3}\x{05F4}\'"`]/u', '', $text );
	$text = strtr(
		$text,
		[
			'ך' => 'כ',
			'ם' => 'מ',
			'ן' => 'נ',
			'ף' => 'פ',
			'ץ' => 'צ',
		]
	);

	return trim( (string) preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $text ) );
}

/**
 * A word however it is spelled: ברגים and בורג are both ברג.
 *
 * @param string $word Normalised word.
 * @return string
 */
function gueta_search_stem( $word ) {
	if ( ! preg_match( '/^[א-ת]+$/u', $word ) || mb_strlen( $word ) < 3 ) {
		return $word;
	}

	$base = mb_strlen( $word ) > 4 ? preg_replace( '/(ימ|ות)$/u', '', $word ) : $word;

	return mb_substr( $base, 0, 1 ) . preg_replace( '/[וי]/u', '', mb_substr( $base, 1 ) );
}

/**
 * And how it sounds.
 *
 * @param string $word Normalised word.
 * @return string
 */
function gueta_search_sound( $word ) {
	return strtr(
		gueta_search_stem( $word ),
		[
			'ט' => 'ת',
			'ק' => 'כ',
			'ח' => 'כ',
			'ע' => 'א',
			'ס' => 'ש',
			'ה' => 'א',
		]
	);
}

/**
 * A word's vector: its three letter pieces and those of its sound, of length one.
 *
 * @param string $word Normalised word.
 * @return array Weight by piece.
 */
function gueta_search_word_vector( $word ) {
	$vector = [];
	$heard  = gueta_search_sound( $word );
	$sides  = [ $word ];

	if ( $heard !== $word ) {
		$sides[] = '~' . $heard;
	}

	foreach ( $sides as $side ) {
		$letters = preg_split( '//u', ' ' . $side . ' ', -1, PREG_SPLIT_NO_EMPTY );

		for ( $i = 0, $n = count( $letters ) - 2; $i < $n; $i++ ) {
			$piece            = $letters[ $i ] . $letters[ $i + 1 ] . $letters[ $i + 2 ];
			$vector[ $piece ] = ( $vector[ $piece ] ?? 0 ) + 1;
		}
	}

	$length = 0.0;

	foreach ( $vector as $weight ) {
		$length += $weight * $weight;
	}

	$length = sqrt( $length ) ? sqrt( $length ) : 1.0;

	foreach ( $vector as $piece => $weight ) {
		$vector[ $piece ] = $weight / $length;
	}

	return $vector;
}

/**
 * A query typed with the keyboard in English, read as the Hebrew keys.
 *
 * @param string $query Normalised query.
 * @return string Empty when it is not one.
 */
function gueta_search_from_keyboard( $query ) {
	if ( ! preg_match( '/^[a-z;,. ]+$/', $query ) || ! preg_match( '/[a-z]/', $query ) ) {
		return '';
	}

	return strtr(
		$query,
		[
			'e' => 'ק', 'r' => 'ר', 't' => 'א', 'y' => 'ט', 'u' => 'ו', 'i' => 'נ', 'o' => 'מ', 'p' => 'פ', // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine
			'a' => 'ש', 's' => 'ד', 'd' => 'ג', 'f' => 'כ', 'g' => 'ע', 'h' => 'י', 'j' => 'ח', 'k' => 'ל', 'l' => 'כ', ';' => 'פ', // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine
			'z' => 'ז', 'x' => 'ס', 'c' => 'ב', 'v' => 'ה', 'b' => 'נ', 'n' => 'מ', 'm' => 'צ', ',' => 'ת', '.' => 'צ', // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.ArrayItemNoNewLine
		]
	);
}

/**
 * The index's products, their words, and a list from each piece to the
 * words that have it. Read once per request.
 *
 * @return array|null Null when there is no index with product ids yet.
 */
function gueta_search_vector_catalogue() {
	static $catalogue = false;

	if ( false !== $catalogue ) {
		return $catalogue;
	}

	$catalogue = null;
	$meta      = function_exists( 'gueta_search_index_meta' ) ? gueta_search_index_meta() : [];
	$path      = ! empty( $meta['file'] ) ? gueta_search_index_dir() . '/' . $meta['file'] : '';

	if ( ! $path || ! is_readable( $path ) ) {
		return null;
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$raw = json_decode( (string) file_get_contents( $path ), true );

	if ( empty( $raw['p'] ) || ! is_array( $raw['p'] ) || empty( $raw['p'][0][8] ) ) {
		return null;
	}

	$products = [];
	$holders  = [];
	$list     = [];
	$postings = [];

	foreach ( $raw['p'] as $at => $row ) {
		$name = gueta_search_normalize( $row[0] );
		$hay  = gueta_search_normalize( $row[0] . ' ' . ( $row[6] ?? '' ) . ' ' . ( $row[7] ?? '' ) );

		$products[] = [
			'id'    => (int) ( $row[8] ?? 0 ),
			'title' => (string) $row[0],
			'name'  => $name,
			'named' => ' ' . $name . ' ',
			'hay'   => $hay,
		];

		foreach ( explode( ' ', $hay ) as $word ) {
			if ( '' === $word ) {
				continue;
			}

			if ( ! isset( $holders[ $word ] ) ) {
				$holders[ $word ] = [];
				$list[]           = (string) $word;
			}

			if ( end( $holders[ $word ] ) !== $at ) {
				$holders[ $word ][] = $at;
			}
		}
	}

	foreach ( $list as $index => $word ) {
		foreach ( gueta_search_word_vector( $word ) as $piece => $weight ) {
			$postings[ $piece ][] = [ $index, $weight ];
		}
	}

	$catalogue = [
		'products' => $products,
		'list'     => $list,
		'holders'  => $holders,
		'postings' => $postings,
	];

	return $catalogue;
}

/**
 * The catalogue's words close to one typed word, each with how close, 1 for
 * the word itself. Only those near the best are kept, so a word found as
 * typed does not drag in its look-alikes.
 *
 * @param array  $catalogue From gueta_search_vector_catalogue().
 * @param string $typed     Normalised word.
 * @return array Closeness by word.
 */
function gueta_search_close_words( $catalogue, $typed ) {
	$found    = [];
	$variants = [ $typed ];

	// לעץ is עץ with "to" in front.
	if ( preg_match( '/^[והבלמשכ]/u', $typed ) && mb_strlen( $typed ) > 3 ) {
		$variants[] = mb_substr( $typed, 1 );
	}

	foreach ( $variants as $position => $variant ) {
		$scores = [];
		$base   = gueta_search_stem( $variant );
		$length = mb_strlen( $variant );

		foreach ( gueta_search_word_vector( $variant ) as $piece => $weight ) {
			foreach ( $catalogue['postings'][ $piece ] ?? [] as $posting ) {
				$scores[ $posting[0] ] = ( $scores[ $posting[0] ] ?? 0 ) + $weight * $posting[1];
			}
		}

		foreach ( $catalogue['list'] as $index => $candidate ) {
			$score = $scores[ $index ] ?? 0;

			if ( $candidate === $variant ) {
				$score = 1;
			} elseif ( $length >= 2 && 0 === strpos( $candidate, $variant ) ) {
				// Still being typed.
				$score = max( $score, 0.9 - 0.02 * ( mb_strlen( $candidate ) - $length ) );
			} elseif ( $length >= 3 && gueta_search_stem( $candidate ) === $base ) {
				$score = max( $score, 0.85 );
			} elseif ( mb_strlen( $candidate ) > 3 && preg_match( '/^[והבלמשכ]/u', $candidate ) && mb_substr( $candidate, 1 ) === $variant ) {
				$score = max( $score, 0.85 );
			}

			if ( $position ) {
				$score *= 0.95;
			}

			if ( $score >= 0.45 && ( $found[ $candidate ] ?? 0 ) < $score ) {
				$found[ $candidate ] = $score;
			}
		}
	}

	$floor = $found ? max( 0.45, max( $found ) * 0.8 ) : 0.45;

	return array_filter(
		$found,
		static function ( $score ) use ( $floor ) {
			return $score >= $floor;
		}
	);
}

/**
 * How well a product holds the query as typed, lower is better, -1 when it
 * does not hold every word. The header script's rank().
 *
 * @param array  $product Catalogue product.
 * @param string $query   Normalised query.
 * @param array  $tokens  Its words.
 * @return int
 */
function gueta_search_exact_tier( $product, $query, $tokens ) {
	foreach ( $tokens as $token ) {
		if ( false === strpos( $product['hay'], $token ) ) {
			return -1;
		}
	}

	if ( $product['name'] === $query ) {
		return 0;
	}

	if ( 0 === strpos( $product['name'], $query ) ) {
		return 1;
	}

	if ( false !== strpos( ' ' . $product['name'], ' ' . $query ) ) {
		return 2;
	}

	if ( false !== strpos( $product['name'], $query ) ) {
		return 3;
	}

	foreach ( $tokens as $token ) {
		if ( false === strpos( ' ' . $product['name'], ' ' . $token ) ) {
			return 5;
		}
	}

	return 4;
}

/**
 * Products for a normalised query, best first, as [ id, score ].
 *
 * @param array  $catalogue From gueta_search_vector_catalogue().
 * @param string $query     Normalised query.
 * @return array
 */
function gueta_search_vector_rank( $catalogue, $query ) {
	$tokens = explode( ' ', $query );
	$score  = [];
	$hits   = [];
	$found  = [];
	$needed = count( $tokens ) <= 2 ? count( $tokens ) : count( $tokens ) - 1;

	foreach ( $tokens as $token ) {
		$best = [];

		foreach ( gueta_search_close_words( $catalogue, $token ) as $word => $closeness ) {
			foreach ( $catalogue['holders'][ $word ] ?? [] as $at ) {
				// A word in the name counts for more than one in a category or tag.
				$value = $closeness * ( false !== strpos( $catalogue['products'][ $at ]['named'], ' ' . $word . ' ' ) ? 1 : 0.7 );

				if ( ( $best[ $at ] ?? 0 ) < $value ) {
					$best[ $at ] = $value;
				}
			}
		}

		foreach ( $best as $at => $value ) {
			$score[ $at ] = ( $score[ $at ] ?? 0 ) + $value;
			$hits[ $at ]  = ( $hits[ $at ] ?? 0 ) + 1;
		}
	}

	foreach ( $catalogue['products'] as $at => $product ) {
		$tier = gueta_search_exact_tier( $product, $query, $tokens );

		if ( $tier < 0 && ( $hits[ $at ] ?? 0 ) < $needed ) {
			continue;
		}

		$found[] = [
			'id'    => $product['id'],
			'score' => ( $tier >= 0 ? 10 - $tier : 0 ) + ( $score[ $at ] ?? 0 ) / count( $tokens ),
			'title' => $product['title'],
		];
	}

	usort(
		$found,
		static function ( $a, $b ) {
			if ( $a['score'] !== $b['score'] ) {
				return $b['score'] <=> $a['score'];
			}

			return mb_strlen( $a['title'] ) <=> mb_strlen( $b['title'] );
		}
	);

	return $found;
}

/**
 * The ids of the products a search finds, best first.
 *
 * @param string $term Search as typed.
 * @return int[]|null Null when there is no index to search, so the caller
 *                    falls back to WordPress's own search.
 */
function gueta_search_vector_ids( $term ) {
	$catalogue = gueta_search_vector_catalogue();
	$query     = gueta_search_normalize( $term );

	if ( ! $catalogue || '' === $query ) {
		return null;
	}

	$found = gueta_search_vector_rank( $catalogue, $query );
	$typed = gueta_search_from_keyboard( $query );

	// Typed with the keyboard in English: keep whichever reading finds better.
	if ( $typed ) {
		$hebrew = gueta_search_vector_rank( $catalogue, $typed );

		if ( $hebrew && ( ! $found || $hebrew[0]['score'] > $found[0]['score'] ) ) {
			$found = $hebrew;
		}
	}

	return array_values( array_filter( array_map( 'intval', array_column( $found, 'id' ) ) ) );
}

/**
 * The search results page's own query finds what the grid shows.
 *
 * The page's grid is the [gueta_archive] shortcode, which searches through
 * gueta_search_vector_ids(), but WordPress still runs its own search for the
 * page, and the template reads it: with the words not as written, it would
 * find nothing and say so above a full grid.
 *
 * @param WP_Query $query Query.
 * @return void
 */
function gueta_search_vector_main_query( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_search() ) {
		return;
	}

	$type = (array) $query->get( 'post_type' );

	if ( ! in_array( 'product', $type, true ) ) {
		return;
	}

	$found = gueta_search_vector_ids( (string) $query->get( 's' ) );

	if ( null === $found ) {
		return;
	}

	$query->set( 'post__in', $found ? $found : [ 0 ] );
	$query->set( 'orderby', 'post__in' );
	$query->set( 'gueta_vector_search', 1 );
}
add_action( 'pre_get_posts', 'gueta_search_vector_main_query' );

/**
 * No words-as-written condition on a query that already holds its products.
 *
 * @param string   $search SQL search clause.
 * @param WP_Query $query  Query.
 * @return string
 */
function gueta_search_vector_clause( $search, $query ) {
	return $query->get( 'gueta_vector_search' ) ? '' : $search;
}
add_filter( 'posts_search', 'gueta_search_vector_clause', 10, 2 );
