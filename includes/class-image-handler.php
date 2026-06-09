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

	const HERO_TARGET_WIDTH = 1800;
	const HERO_MIN_WIDTH    = 1400;
	const UPSCALE_QUALITY   = 92;
	const MIN_SECTION_IMAGE_MATCH_SCORE = 2;
	const MIN_SECTION_IMAGE_MATCH_RATIO = 0.18;

	/**
	 * Sideload a remote image URL into the media library.
	 *
	 * @param string $image_url Remote image URL.
	 * @param int    $parent_post_id Parent post ID.
	 * @param string $title Attachment title.
	 * @param string $source_url Original source article URL.
	 * @param string $source_label Original source label.
	 * @param string $image_credit Image credit/copyright text.
	 * @return int
	 */
	public function sideload( $image_url, $parent_post_id = 0, $title = '', $source_url = '', $source_label = '', $image_credit = '' ) {
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

		$this->maybe_upscale_attachment( (int) $attachment_id );
		$this->store_source_context( (int) $attachment_id, $image_url, $source_url, $source_label, $image_credit );
		$this->maybe_update_attachment_alt( (int) $attachment_id, $title );

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
			if ( ! empty( $item['image_blocked'] ) || empty( $item['image_url'] ) ) {
				continue;
			}

			$attachment = $this->sideload(
				$item['image_url'],
				0,
				$item['title'] ?? '',
				$item['url'] ?? '',
				$item['source_label'] ?? '',
				$item['image_credit'] ?? ''
			);
			if ( $attachment > 0 ) {
				$out[ $idx ] = $attachment;
			}
		}

		return $out;
	}

	/**
	 * Store provenance so downstream featured-image guards can block risky
	 * source imagery even after the file has been copied into the Media Library.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $image_url     Remote image URL.
	 * @param string $source_url    Source article URL.
	 * @param string $source_label  Source label.
	 * @param string $image_credit  Image credit/copyright text.
	 * @return void
	 */
	private function store_source_context( $attachment_id, $image_url, $source_url = '', $source_label = '', $image_credit = '' ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}

		update_post_meta( $attachment_id, '_lunara_dispatch_image_url', esc_url_raw( (string) $image_url ) );

		if ( '' !== (string) $source_url ) {
			update_post_meta( $attachment_id, '_lunara_dispatch_source_url', esc_url_raw( (string) $source_url ) );
			update_post_meta( $attachment_id, '_lunara_image_source_url', esc_url_raw( (string) $source_url ) );
		}

		if ( '' !== (string) $source_label ) {
			update_post_meta( $attachment_id, '_lunara_dispatch_source_label', sanitize_text_field( (string) $source_label ) );
			update_post_meta( $attachment_id, '_lunara_image_source_name', sanitize_text_field( (string) $source_label ) );
		}

		if ( '' !== (string) $image_credit ) {
			update_post_meta( $attachment_id, '_lunara_dispatch_image_credit', sanitize_text_field( (string) $image_credit ) );
			update_post_meta( $attachment_id, '_lunara_image_credit', sanitize_text_field( (string) $image_credit ) );
		}

		$this->maybe_update_attachment_caption( $attachment_id, $source_label, $image_credit );
	}

	/**
	 * Add practical alt text when the source image did not provide one.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $title         Source item title.
	 * @return void
	 */
	private function maybe_update_attachment_alt( $attachment_id, $title = '' ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return;
		}

		$existing_alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		if ( '' !== $existing_alt ) {
			return;
		}

		$alt = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$alt = trim( preg_replace( '/\s+/u', ' ', (string) $alt ) );
		if ( '' === $alt ) {
			return;
		}

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) && mb_strlen( $alt ) > 150 ) {
			$alt = rtrim( mb_substr( $alt, 0, 147 ) ) . '...';
		} elseif ( strlen( $alt ) > 150 ) {
			$alt = rtrim( substr( $alt, 0, 147 ) ) . '...';
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
	}

	/**
	 * Add a source-aware caption so manual media-library inspection stays clear.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_label  Source label.
	 * @param string $image_credit  Image credit/copyright text.
	 * @return void
	 */
	private function maybe_update_attachment_caption( $attachment_id, $source_label = '', $image_credit = '' ) {
		$attachment_id = (int) $attachment_id;
		$source_label  = sanitize_text_field( (string) $source_label );
		$image_credit  = sanitize_text_field( (string) $image_credit );

		if ( $attachment_id <= 0 || ( '' === $source_label && '' === $image_credit ) ) {
			return;
		}

		$parts = array();
		if ( '' !== $image_credit ) {
			$parts[] = sprintf( 'Image: %s', $image_credit );
		}
		if ( '' !== $source_label ) {
			$parts[] = sprintf( 'Source: %s', $source_label );
		}

		$caption = implode( ' / ', $parts );
		if ( '' !== $caption && ! preg_match( '/[.!?]$/', $caption ) ) {
			$caption .= '.';
		}

		wp_update_post(
			array(
				'ID'           => $attachment_id,
				'post_excerpt' => $caption,
			)
		);
	}

	/**
	 * Upscale an undersized attachment so Journal hero images do not land on
	 * the site looking obviously tiny when promoted to the top of the page.
	 *
	 * This is not generative AI enhancement; it is a high-quality local resample
	 * stage that runs only when the best available source image is still below
	 * the Lunara hero threshold.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool
	 */
	public function maybe_upscale_attachment( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id <= 0 ) {
			return false;
		}

		$file = get_attached_file( $attachment_id );
		if ( empty( $file ) || ! file_exists( $file ) ) {
			return false;
		}

		$size = wp_getimagesize( $file );
		if ( empty( $size[0] ) || empty( $size[1] ) ) {
			return false;
		}

		$width  = (int) $size[0];
		$height = (int) $size[1];

		update_post_meta(
			$attachment_id,
			'_lunara_dispatch_source_dimensions',
			array(
				'width'  => $width,
				'height' => $height,
			)
		);

		if ( $width >= self::HERO_MIN_WIDTH ) {
			update_post_meta( $attachment_id, '_lunara_dispatch_image_quality_state', 'source_ok' );
			return false;
		}

		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			update_post_meta( $attachment_id, '_lunara_dispatch_image_quality_state', 'editor_unavailable' );
			error_log( 'Lunara Dispatch: Upscale skipped for attachment ' . $attachment_id . ' because no image editor was available.' );
			return false;
		}

		$scale      = self::HERO_TARGET_WIDTH / max( 1, $width );
		$target_w   = max( self::HERO_TARGET_WIDTH, (int) round( $width * $scale ) );
		$target_h   = max( 1, (int) round( $height * $scale ) );
		$resized    = $editor->resize( $target_w, $target_h, false );

		if ( is_wp_error( $resized ) ) {
			return $this->maybe_manual_gd_upscale_attachment( $attachment_id, $file, $width, $height, $target_w, $target_h, 'resize_failed:' . $resized->get_error_message() );
		}

		if ( method_exists( $editor, 'set_quality' ) ) {
			$editor->set_quality( self::UPSCALE_QUALITY );
		}

		$saved = $editor->save( $file );
		if ( is_wp_error( $saved ) ) {
			return $this->maybe_manual_gd_upscale_attachment( $attachment_id, $file, $width, $height, $target_w, $target_h, 'save_failed:' . $saved->get_error_message() );
		}

		clearstatcache( true, $file );

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		update_post_meta(
			$attachment_id,
			'_lunara_dispatch_image_quality_state',
			'upscaled'
		);
		update_post_meta(
			$attachment_id,
			'_lunara_dispatch_upscaled_dimensions',
			array(
				'width'  => $target_w,
				'height' => $target_h,
			)
		);

		return true;
	}

	/**
	 * Fallback manual GD upscale for hosts where the core editor refuses to
	 * enlarge an image but the GD extension is available.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $file          Absolute file path.
	 * @param int    $width         Source width.
	 * @param int    $height        Source height.
	 * @param int    $target_w      Target width.
	 * @param int    $target_h      Target height.
	 * @param string $context       Failure context from the primary editor path.
	 * @return bool
	 */
	private function maybe_manual_gd_upscale_attachment( $attachment_id, $file, $width, $height, $target_w, $target_h, $context = '' ) {
		if ( ! extension_loaded( 'gd' ) ) {
			update_post_meta( $attachment_id, '_lunara_dispatch_image_quality_state', 'gd_unavailable' );
			error_log( 'Lunara Dispatch: Upscale fallback skipped for attachment ' . $attachment_id . ' because GD is unavailable. Context: ' . $context );
			return false;
		}

		$image_info = wp_getimagesize( $file );
		$mime       = ! empty( $image_info['mime'] ) ? strtolower( (string) $image_info['mime'] ) : '';
		$source     = $this->gd_image_from_file( $file, $mime );
		if ( ! $source ) {
			update_post_meta( $attachment_id, '_lunara_dispatch_image_quality_state', 'gd_open_failed' );
			error_log( 'Lunara Dispatch: GD could not open attachment ' . $attachment_id . ' for upscale. Context: ' . $context );
			return false;
		}

		$canvas = imagecreatetruecolor( $target_w, $target_h );
		if ( ! $canvas ) {
			imagedestroy( $source );
			update_post_meta( $attachment_id, '_lunara_dispatch_image_quality_state', 'gd_canvas_failed' );
			error_log( 'Lunara Dispatch: GD could not allocate upscale canvas for attachment ' . $attachment_id . '. Context: ' . $context );
			return false;
		}

		if ( in_array( $mime, array( 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			imagealphablending( $canvas, false );
			imagesavealpha( $canvas, true );
			$transparent = imagecolorallocatealpha( $canvas, 0, 0, 0, 127 );
			imagefill( $canvas, 0, 0, $transparent );
		}

		$copied = imagecopyresampled( $canvas, $source, 0, 0, 0, 0, $target_w, $target_h, $width, $height );
		imagedestroy( $source );

		if ( ! $copied ) {
			imagedestroy( $canvas );
			update_post_meta( $attachment_id, '_lunara_dispatch_image_quality_state', 'gd_resample_failed' );
			error_log( 'Lunara Dispatch: GD resample failed for attachment ' . $attachment_id . '. Context: ' . $context );
			return false;
		}

		$saved = $this->gd_image_to_file( $canvas, $file, $mime );
		imagedestroy( $canvas );

		if ( ! $saved ) {
			update_post_meta( $attachment_id, '_lunara_dispatch_image_quality_state', 'gd_save_failed' );
			error_log( 'Lunara Dispatch: GD save failed for attachment ' . $attachment_id . '. Context: ' . $context );
			return false;
		}

		clearstatcache( true, $file );

		$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
		if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		update_post_meta( $attachment_id, '_lunara_dispatch_image_quality_state', 'upscaled_gd' );
		update_post_meta(
			$attachment_id,
			'_lunara_dispatch_upscaled_dimensions',
			array(
				'width'  => $target_w,
				'height' => $target_h,
			)
		);

		return true;
	}

	/**
	 * Create a GD image resource from a local file.
	 *
	 * @param string $file Local file path.
	 * @param string $mime Mime type.
	 * @return resource|false
	 */
	private function gd_image_from_file( $file, $mime ) {
		switch ( $mime ) {
			case 'image/jpeg':
			case 'image/jpg':
				return function_exists( 'imagecreatefromjpeg' ) ? @imagecreatefromjpeg( $file ) : false;
			case 'image/png':
				return function_exists( 'imagecreatefrompng' ) ? @imagecreatefrompng( $file ) : false;
			case 'image/gif':
				return function_exists( 'imagecreatefromgif' ) ? @imagecreatefromgif( $file ) : false;
			case 'image/webp':
				return function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $file ) : false;
			default:
				return false;
		}
	}

	/**
	 * Save a GD image resource back to disk.
	 *
	 * @param resource $image GD image resource.
	 * @param string   $file  Local file path.
	 * @param string   $mime  Mime type.
	 * @return bool
	 */
	private function gd_image_to_file( $image, $file, $mime ) {
		switch ( $mime ) {
			case 'image/jpeg':
			case 'image/jpg':
				return function_exists( 'imagejpeg' ) ? @imagejpeg( $image, $file, self::UPSCALE_QUALITY ) : false;
			case 'image/png':
				return function_exists( 'imagepng' ) ? @imagepng( $image, $file, 6 ) : false;
			case 'image/gif':
				return function_exists( 'imagegif' ) ? @imagegif( $image, $file ) : false;
			case 'image/webp':
				return function_exists( 'imagewebp' ) ? @imagewebp( $image, $file, self::UPSCALE_QUALITY ) : false;
			default:
				return false;
		}
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

		$sections = $this->extract_sections_for_matching( $html );
		if ( empty( $sections ) ) {
			return $map;
		}

		$item_keywords = array();
		foreach ( $items as $idx => $item ) {
			if ( empty( $item_image_ids[ $idx ] ) ) {
				continue;
			}

			$combined            = ( $item['title'] ?? '' ) . ' ' . ( $item['description'] ?? '' ) . ' ' . ( $item['source_label'] ?? '' ) . ' ' . ( $item['url'] ?? '' );
			$item_keywords[ $idx ] = $this->extract_keywords( $combined );
		}

		$used_items = array();
		$seen_slugs = array();

		foreach ( $sections as $section ) {
			$section_title = isset( $section['title'] ) ? (string) $section['title'] : '';
			$section_words = $this->extract_keywords( ( $section['title'] ?? '' ) . ' ' . ( $section['text'] ?? '' ) );
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

			$match_ratio = $best_score > 0 ? $best_score / max( 1, min( count( $section_words ), count( $item_keywords[ $best_idx ] ?? array() ) ) ) : 0;
			if ( $best_idx < 0 || $best_score < self::MIN_SECTION_IMAGE_MATCH_SCORE || $match_ratio < self::MIN_SECTION_IMAGE_MATCH_RATIO ) {
				error_log(
					sprintf(
						'Lunara Dispatch: skipped featured image for "%s" because the best source-image match was too weak (score %d, ratio %.2f).',
						$section_title,
						$best_score,
						$match_ratio
					)
				);
				continue;
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
	 * Extract section titles and enough local body text to match images safely.
	 *
	 * @param string $html Generated HTML.
	 * @return array
	 */
	private function extract_sections_for_matching( $html ) {
		$sections = array();

		if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>(.*?)(?=<h2\b|$)/is', (string) $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$title = trim( wp_strip_all_tags( $match[1] ?? '' ) );
				$text  = trim( wp_strip_all_tags( $match[2] ?? '' ) );
				if ( '' !== $title ) {
					$sections[] = array(
						'title' => $title,
						'text'  => $text,
					);
				}
			}
		}

		if ( ! empty( $sections ) ) {
			return $sections;
		}

		if ( false === stripos( (string) $html, '<hr' ) ) {
			return array();
		}

		$parts = preg_split( '/<hr\b[^>]*\/?>/i', (string) $html );
		if ( empty( $parts ) || ! is_array( $parts ) ) {
			return array();
		}

		foreach ( $parts as $part ) {
			$title = $this->derive_section_title_from_html( $part );
			$text  = trim( wp_strip_all_tags( $this->strip_leading_story_heading_for_matching( $part ) ) );
			if ( '' !== $title ) {
				$sections[] = array(
					'title' => $title,
					'text'  => $text,
				);
			}
		}

		return $sections;
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
	 * Remove a leading story heading for match text without changing content.
	 *
	 * @param string $html Section HTML.
	 * @return string
	 */
	private function strip_leading_story_heading_for_matching( $html ) {
		return trim( preg_replace( '/^\s*<h3\b[^>]*>.*?<\/h3>\s*/is', '', (string) $html, 1 ) );
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
