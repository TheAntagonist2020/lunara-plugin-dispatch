<?php
/**
 * Lunara_Dispatch_Image_Handler
 *
 * Sideloads remote image URLs into the WP media library and matches each
 * generated Journal section back to the most likely source item by keyword
 * overlap, producing a slug -> attachment_id map.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lunara_Dispatch_Image_Handler {

	/**
	 * Sideload a remote image URL into the media library.
	 *
	 * @param string $image_url Remote image URL.
	 * @param int    $parent_post_id Parent post ID.
	 * @param string $title Attachment title.
	 * @return int
	 */
	public function sideload( $image_url, $parent_post_id = 0, $title = '' ) {
		if ( empty( $image_url ) ) {
			return 0;
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $image_url, (int) $parent_post_id, $title, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			error_log( 'Lunara Dispatch: Image sideload failed: ' . $attachment_id->get_error_message() . ' | URL: ' . $image_url );
			return 0;
		}

		return (int) $attachment_id;
	}

	/**
	 * Sideload one image per source item. Returns [ item_index => attachment_id ].
	 *
	 * @param array $items Source items.
	 * @return array
	 */
	public function sideload_for_items( array $items ) {
		$out = array();

		foreach ( $items as $idx => $item ) {
			$out[ $idx ] = 0;
			if ( empty( $item['image_url'] ) ) {
				continue;
			}

			$attachment = $this->sideload( $item['image_url'], 0, $item['title'] ?? '' );
			if ( $attachment > 0 ) {
				$out[ $idx ] = $attachment;
			}
		}

		return $out;
	}

	/**
	 * Build slug -> attachment_id map by keyword-matching each generated
	 * Journal section back to its most likely source item. Each item can
	 * match at most one section.
	 *
	 * @param string $html Generated HTML.
	 * @param array  $items Original source items.
	 * @param array  $item_image_ids Item index => attachment ID.
	 * @return array
	 */
	public function match_sections_to_items( $html, array $items, array $item_image_ids ) {
		$map = array();
		if ( empty( $html ) || empty( $items ) ) {
			return $map;
		}

		$section_titles = $this->extract_section_titles( $html );
		if ( empty( $section_titles ) ) {
			return $map;
		}

		$item_keywords = array();
		foreach ( $items as $idx => $item ) {
			if ( empty( $item_image_ids[ $idx ] ) ) {
				continue;
			}

			$combined            = ( $item['title'] ?? '' ) . ' ' . ( $item['description'] ?? '' );
			$item_keywords[ $idx ] = $this->extract_keywords( $combined );
		}

		$used_items = array();
		$seen_slugs = array();

		foreach ( $section_titles as $section_title ) {
			$section_words = $this->extract_keywords( $section_title );
			if ( empty( $section_words ) ) {
				continue;
			}

			$best_idx   = -1;
			$best_score = 0;

			foreach ( $item_keywords as $idx => $words ) {
				if ( isset( $used_items[ $idx ] ) ) {
					continue;
				}

				$score = count( array_intersect( $section_words, $words ) );
				if ( $score > $best_score ) {
					$best_score = $score;
					$best_idx   = $idx;
				}
			}

			if ( $best_idx < 0 ) {
				foreach ( $item_image_ids as $idx => $attachment_id ) {
					if ( empty( $attachment_id ) || isset( $used_items[ $idx ] ) ) {
						continue;
					}

					$best_idx = (int) $idx;
					break;
				}
			}

			if ( $best_idx >= 0 ) {
				$slug = sanitize_title( $section_title );
				if ( '' === $slug ) {
					continue;
				}

				$base = $slug;
				$i    = 2;
				while ( isset( $seen_slugs[ $slug ] ) ) {
					$slug = $base . '-' . $i;
					$i++;
				}

				$seen_slugs[ $slug ] = true;
				$map[ $slug ]        = (int) $item_image_ids[ $best_idx ];
				$used_items[ $best_idx ] = true;
			}
		}

		return $map;
	}

	/**
	 * Extract section titles from either legacy <h2> markup or current
	 * <hr>-delimited Journal markup with optional per-story <h3> headlines.
	 *
	 * @param string $html Generated HTML.
	 * @return array
	 */
	private function extract_section_titles( $html ) {
		$titles = array();

		if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', $html, $matches ) ) {
			$titles = array_filter(
				array_map(
					function ( $title ) {
						return trim( wp_strip_all_tags( $title ) );
					},
					$matches[1]
				)
			);
		}

		if ( ! empty( $titles ) ) {
			return array_values( $titles );
		}

		if ( false === stripos( $html, '<hr' ) ) {
			return array();
		}

		$parts = preg_split( '/<hr\b[^>]*\/?>/i', $html );
		if ( empty( $parts ) || ! is_array( $parts ) ) {
			return array();
		}

		$derived_titles = array();
		foreach ( $parts as $part ) {
			$title = $this->derive_section_title_from_html( $part );
			if ( '' !== $title ) {
				$derived_titles[] = $title;
			}
		}

		return $derived_titles;
	}

	/**
	 * Derive a title from an explicit <h3> story heading when present, then
	 * fall back to the opening paragraph text.
	 *
	 * @param string $html Section HTML.
	 * @return string
	 */
	private function derive_section_title_from_html( $html ) {
		if ( preg_match( '/<h3\b[^>]*>(.*?)<\/h3>/is', (string) $html, $matches ) ) {
			$title = html_entity_decode( wp_strip_all_tags( $matches[1] ), ENT_QUOTES, get_bloginfo( 'charset' ) );
			$title = preg_replace( '/\s+/u', ' ', trim( (string) $title ) );
			if ( '' !== $title ) {
				return $title;
			}
		}

		$title_html = (string) $html;
		if ( preg_match( '/<p\b[^>]*>(.*?)<\/p>/is', $title_html, $matches ) ) {
			$title_html = $matches[1];
		}

		$title = html_entity_decode( wp_strip_all_tags( $title_html ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$title = preg_replace( '/\s+/u', ' ', trim( (string) $title ) );

		if ( '' === $title ) {
			return '';
		}

		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) && mb_strlen( $title ) > 180 ) {
			$title = rtrim( mb_substr( $title, 0, 177 ) ) . '...';
		} elseif ( strlen( $title ) > 180 ) {
			$title = rtrim( substr( $title, 0, 177 ) ) . '...';
		}

		return $title;
	}

	/**
	 * Reduce a string to deduped meaningful keyword tokens.
	 *
	 * @param string $text Input text.
	 * @return array
	 */
	private function extract_keywords( $text ) {
		$text = strtolower( wp_strip_all_tags( (string) $text ) );
		$stopwords = array(
			'the', 'a', 'an', 'and', 'or', 'but', 'of', 'to', 'in', 'on', 'at', 'for', 'with', 'from', 'by',
			'as', 'is', 'are', 'was', 'were', 'be', 'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did',
			'will', 'would', 'could', 'should', 'may', 'might', 'must', 'this', 'that', 'these', 'those',
			'it', 'its', 'they', 'them', 'their', 'his', 'her', 'he', 'she', 'we', 'us', 'our', 'you', 'your',
			'who', 'what', 'when', 'where', 'why', 'how', 'if', 'than', 'then', 'about', 'into', 'out', 'over',
		);

		$words = preg_split( '/[^a-z0-9]+/i', $text );
		if ( ! is_array( $words ) ) {
			return array();
		}

		$words = array_filter(
			$words,
			function ( $word ) use ( $stopwords ) {
				return strlen( $word ) >= 3 && ! in_array( $word, $stopwords, true );
			}
		);

		return array_values( array_unique( $words ) );
	}
}
