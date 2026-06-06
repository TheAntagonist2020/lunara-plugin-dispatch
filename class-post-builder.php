<?php
/**
 * Lunara_Dispatch_Post_Builder
 *
 * Walks generated HTML, splits it into Journal sections, and creates one
 * draft post per section with the matched featured image. Supports both the
 * legacy <h2>-delimited format and the current <hr>-delimited Journal format.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lunara_Dispatch_Post_Builder {

	public function get_target_post_type() {
		$pt = sanitize_key( get_option( 'lunara_dispatch_post_type', 'journal' ) );
		if ( empty( $pt ) || ! post_type_exists( $pt ) ) {
			return 'post';
		}
		return $pt;
	}

	/**
	 * Walk HTML and return every legacy <h2> section paired with its body.
	 *
	 * @param string $html Generated HTML.
	 * @return array
	 */
	public function extract_h2_sections_with_body( $html ) {
		$sections = array();
		if ( empty( $html ) ) {
			return $sections;
		}

		$parts = preg_split(
			'/(<h2[^>]*>.*?<\/h2>)/is',
			$html,
			-1,
			PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
		);

		if ( empty( $parts ) || ! is_array( $parts ) ) {
			return $sections;
		}

		$current_title = '';
		$current_body  = '';

		foreach ( $parts as $part ) {
			if ( preg_match( '/<h2[^>]*>(.*?)<\/h2>/is', $part, $matches ) ) {
				if ( '' !== $current_title ) {
					$sections[] = array(
						'title' => $current_title,
						'body'  => trim( $current_body ),
					);
				}

				$current_title = trim( wp_strip_all_tags( $matches[1] ) );
				$current_body  = '';
			} else {
				$current_body .= $part;
			}
		}

		if ( '' !== $current_title ) {
			$sections[] = array(
				'title' => $current_title,
				'body'  => trim( $current_body ),
			);
		}

		return $sections;
	}

	/**
	 * Walk HTML and return every <hr>-delimited Journal section paired with a
	 * derived post title and its body.
	 *
	 * The current prompt tells the model to separate stories with <hr> and lead
	 * each one with an AI-written <h3> headline. We use that headline as the
	 * post title when present and fall back to deriving a title from the
	 * opening paragraph only when needed.
	 *
	 * @param string $html Generated HTML.
	 * @return array
	 */
	public function extract_hr_sections_with_body( $html ) {
		$sections = array();
		if ( empty( $html ) || false === stripos( $html, '<hr' ) ) {
			return $sections;
		}

		$parts = preg_split( '/<hr\b[^>]*\/?>/i', $html );
		if ( empty( $parts ) || ! is_array( $parts ) ) {
			return $sections;
		}

		foreach ( $parts as $part ) {
			$body = trim( (string) $part );
			if ( '' === $body ) {
				continue;
			}

			$title = $this->derive_section_title_from_html( $body );
			if ( '' === $title ) {
				continue;
			}

			$body = $this->strip_leading_story_heading( $body );

			$sections[] = array(
				'title' => $title,
				'body'  => $body,
			);
		}

		return $sections;
	}

	/**
	 * Resolve Journal sections using the current format first, then the older
	 * <h2>-based splitter.
	 *
	 * @param string $html Generated HTML.
	 * @return array
	 */
	public function extract_sections_with_body( $html ) {
		$sections = $this->extract_h2_sections_with_body( $html );
		if ( ! empty( $sections ) ) {
			return $sections;
		}

		return $this->extract_hr_sections_with_body( $html );
	}

	/**
	 * Derive a post title from the opening sentence of a Journal section.
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
	 * Remove a leading <h3> heading from a Journal section body so the split
	 * post does not repeat its title inside the content.
	 *
	 * @param string $html Section HTML.
	 * @return string
	 */
	private function strip_leading_story_heading( $html ) {
		$html = preg_replace( '/^\s*<h3\b[^>]*>.*?<\/h3>\s*/is', '', (string) $html, 1 );
		return trim( (string) $html );
	}

	/**
	 * Split HTML into individual posts, each with featured image set.
	 *
	 * @param string $html Generated HTML.
	 * @param array  $section_image_map Slug => attachment ID.
	 * @param string $post_type Target post type.
	 * @param string $post_status Post status.
	 * @return array
	 */
	public function split_into_individual_posts( $html, array $section_image_map, $post_type, $post_status = 'draft' ) {
		$created  = array();
		$sections = $this->extract_sections_with_body( $html );
		if ( empty( $sections ) ) {
			return $created;
		}

		$author = get_current_user_id() ?: 1;

		foreach ( $sections as $section ) {
			$title = trim( $section['title'] );
			$body  = trim( $section['body'] );
			if ( '' === $title ) {
				continue;
			}

			$slug     = sanitize_title( $title );
			$featured = isset( $section_image_map[ $slug ] ) ? (int) $section_image_map[ $slug ] : 0;

			// Inject image inline at top so the single-post page reads:
			// headline -> image -> prose. If the section body already contains
			// an inline image, keep that instead of doubling it.
			$body_has_inline_image = false !== stripos( $body, '<img' );
			if ( $featured > 0 && ! $body_has_inline_image && false === stripos( $body, 'wp-image-' . $featured ) ) {
				$img = wp_get_attachment_image(
					$featured,
					'large',
					false,
					array(
						'class'    => 'lunara-section-image wp-image-' . $featured,
						'loading'  => 'lazy',
						'decoding' => 'async',
					)
				);

				if ( ! empty( $img ) ) {
					$body = $img . "\n\n" . $body;
				}
			}

			$new_id = wp_insert_post(
				array(
					'post_title'   => $title,
					'post_content' => $body,
					'post_status'  => $post_status,
					'post_type'    => $post_type,
					'post_author'  => $author,
				),
				true
			);

			if ( is_wp_error( $new_id ) || ! $new_id ) {
				error_log(
					'Lunara Dispatch: failed to create post "' . $title . '": ' .
					( is_wp_error( $new_id ) ? $new_id->get_error_message() : 'unknown error' )
				);
				continue;
			}

			if ( $featured > 0 ) {
				set_post_thumbnail( $new_id, $featured );
			}

			$created[] = (int) $new_id;
		}

		return $created;
	}

	/**
	 * Roundup -> individual posts migration helper. Splits existing posts with
	 * multiple Journal sections into separate entries; archives the original
	 * under "Lunara Journal Archive" and tags it with the Archive term so the
	 * homepage Journal lane can exclude it.
	 *
	 * @param bool $dry_run Preview only.
	 * @param int  $limit Number of posts to scan.
	 * @return array
	 */
	public function migrate_roundups( $dry_run = true, $limit = 50 ) {
		$post_type = $this->get_target_post_type();
		$query     = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => max( 1, (int) $limit ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);

		$scanned  = 0;
		$migrated = 0;
		$created  = 0;
		$skipped  = 0;
		$preview  = array();

		if ( ! $query->have_posts() ) {
			return compact( 'scanned', 'migrated', 'created', 'skipped', 'preview' );
		}

		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id = get_the_ID();
			$content = get_the_content();
			$title   = get_the_title();
			$scanned++;

			$sections = $this->extract_sections_with_body( $content );
			if ( count( $sections ) < 2 ) {
				$skipped++;
				continue;
			}

			$section_image_map = get_post_meta( $post_id, '_lunara_journal_section_images', true );
			if ( ! is_array( $section_image_map ) ) {
				$section_image_map = array();
			}
			$fallback_image = (int) get_post_thumbnail_id( $post_id );

			$preview[] = array(
				'id'       => $post_id,
				'title'    => $title,
				'sections' => array_map(
					function( $section ) {
						return $section['title'];
					},
					$sections
				),
			);

			if ( $dry_run ) {
				continue;
			}

			$original_status = get_post_status( $post_id );
			$original_date   = get_the_date( 'Y-m-d H:i:s', $post_id );
			$original_terms  = wp_get_object_terms( $post_id, 'journal_type', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $original_terms ) ) {
				$original_terms = array();
			}

			$new_ids = array();
			foreach ( $sections as $section ) {
				$sec_title = trim( $section['title'] );
				$sec_body  = trim( $section['body'] );
				if ( '' === $sec_title ) {
					continue;
				}

				$sec_slug = sanitize_title( $sec_title );
				$sec_img  = isset( $section_image_map[ $sec_slug ] ) ? (int) $section_image_map[ $sec_slug ] : $fallback_image;

				$sec_body_has_inline_image = false !== stripos( $sec_body, '<img' );
				if ( $sec_img > 0 && ! $sec_body_has_inline_image && false === stripos( $sec_body, 'wp-image-' . $sec_img ) ) {
					$img_html = wp_get_attachment_image(
						$sec_img,
						'large',
						false,
						array(
							'class'    => 'lunara-section-image wp-image-' . $sec_img,
							'loading'  => 'lazy',
							'decoding' => 'async',
						)
					);

					if ( ! empty( $img_html ) ) {
						$sec_body = $img_html . "\n\n" . $sec_body;
					}
				}

				$new_id = wp_insert_post(
					array(
						'post_title'    => $sec_title,
						'post_content'  => $sec_body,
						'post_status'   => $original_status,
						'post_type'     => $post_type,
						'post_date'     => $original_date,
						'post_date_gmt' => get_gmt_from_date( $original_date ),
						'post_author'   => get_current_user_id() ?: 1,
					),
					true
				);

				if ( is_wp_error( $new_id ) || ! $new_id ) {
					continue;
				}

				if ( $sec_img > 0 ) {
					set_post_thumbnail( $new_id, $sec_img );
				}

				if ( ! empty( $original_terms ) && taxonomy_exists( 'journal_type' ) ) {
					wp_set_object_terms( $new_id, $original_terms, 'journal_type' );
				}

				$new_ids[] = (int) $new_id;
				$created++;
			}

			if ( ! empty( $new_ids ) ) {
				$archive_title = 'Lunara Journal Archive - ' . get_the_date( 'F j, Y', $post_id );
				wp_update_post(
					array(
						'ID'         => $post_id,
						'post_title' => $archive_title,
					)
				);

				if ( taxonomy_exists( 'journal_type' ) ) {
					$archive_term = term_exists( 'archive', 'journal_type' );
					if ( ! $archive_term ) {
						$archive_term = wp_insert_term( 'Archive', 'journal_type', array( 'slug' => 'archive' ) );
					}

					if ( ! is_wp_error( $archive_term ) ) {
						$term_id = is_array( $archive_term ) ? (int) $archive_term['term_id'] : (int) $archive_term;
						if ( $term_id > 0 ) {
							wp_set_object_terms( $post_id, array( $term_id ), 'journal_type', false );
						}
					}
				}

				update_post_meta( $post_id, '_lunara_journal_is_archive', '1' );
				$migrated++;
			}
		}

		wp_reset_postdata();

		return compact( 'scanned', 'migrated', 'created', 'skipped', 'preview' );
	}
}
