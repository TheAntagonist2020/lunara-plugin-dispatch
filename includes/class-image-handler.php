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
	const MAX_IMAGE_BYTES  = 8388608;
	const MAX_IMAGE_DIMENSION = 10000;
	const MAX_IMAGE_PIXELS = 25000000;
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
	public function sideload( $image_url, $parent_post_id = 0, $title = '', $source_url = '', $source_label = '', $image_credit = '', $image_license = '', $image_rights_url = '', $image_origin = '' ) {
		if ( empty( $image_url ) || ! $this->is_public_https_url( $image_url ) ) {
			return 0;
		}
		if ( ! $this->is_public_https_url( $source_url ) || '' === trim( (string) $source_label ) || '' === trim( (string) $image_origin ) ) {
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

		$existing = get_posts( array(
			'post_type' => 'attachment',
			'post_status' => 'inherit',
			'fields' => 'ids',
			'posts_per_page' => 1,
			'no_found_rows' => true,
			'meta_query' => array(
				'relation' => 'OR',
				array(
					'key' => '_lunara_dispatch_image_url',
					'value' => esc_url_raw( (string) $image_url ),
				),
				array(
					'key' => '_lunara_dispatch_source_url',
					'value' => esc_url_raw( (string) $source_url ),
				),
			),
		) );
		if ( ! empty( $existing[0] ) ) {
			$this->store_source_context( (int) $existing[0], $image_url, $source_url, $source_label, $image_credit, $image_license, $image_rights_url, $image_origin );
			$this->maybe_update_attachment_alt( (int) $existing[0], $title );
			return (int) $existing[0];
		}

		$attachment_id = $this->download_and_sideload( $image_url, (int) $parent_post_id, $title );

		if ( is_wp_error( $attachment_id ) ) {
			error_log( 'Lunara Dispatch: Image sideload failed: ' . $attachment_id->get_error_message() . ' | URL: ' . $image_url );
			return 0;
		}

		$this->record_attachment_quality( (int) $attachment_id );
		$this->store_source_context( (int) $attachment_id, $image_url, $source_url, $source_label, $image_credit, $image_license, $image_rights_url, $image_origin );
		$this->maybe_update_attachment_alt( (int) $attachment_id, $title );

		return (int) $attachment_id;
	}

	private function download_and_sideload( $image_url, $parent_post_id, $title ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
		}
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$tmp = wp_tempnam( $image_url );
		if ( ! $tmp ) {
			return new WP_Error( 'lunara_dispatch_image_temp', 'Could not allocate a temporary image file.' );
		}

		$response = wp_safe_remote_get( $image_url, array(
			'timeout' => 20,
			'redirection' => 2,
			'reject_unsafe_urls' => true,
			'limit_response_size' => self::MAX_IMAGE_BYTES,
			'user-agent' => 'Mozilla/5.0 (compatible; LunaraDispatch/3.2.3; +https://lunarafilm.com)',
			'stream' => true,
			'filename' => $tmp,
		) );
		$status = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$size = file_exists( $tmp ) ? (int) filesize( $tmp ) : 0;
		if ( is_wp_error( $response ) || 200 !== $status || $size <= 0 || $size >= self::MAX_IMAGE_BYTES ) {
			@unlink( $tmp );
			return is_wp_error( $response ) ? $response : new WP_Error( 'lunara_dispatch_image_download', 'Remote image failed the HTTPS, status, or size gate.' );
		}

		$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : '';
		$extensions = array(
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
			'image/avif' => 'avif',
		);
		if ( empty( $extensions[ $mime ] ) ) {
			@unlink( $tmp );
			return new WP_Error( 'lunara_dispatch_image_type', 'Remote file is not a supported image.' );
		}
		$dimensions = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $tmp ) : @getimagesize( $tmp );
		$width = ! empty( $dimensions[0] ) ? (int) $dimensions[0] : 0;
		$height = ! empty( $dimensions[1] ) ? (int) $dimensions[1] : 0;
		$pixels = $width > 0 && $height > 0 ? $width * $height : 0;
		if (
			$width <= 0 ||
			$height <= 0 ||
			$width > self::MAX_IMAGE_DIMENSION ||
			$height > self::MAX_IMAGE_DIMENSION ||
			$pixels > self::MAX_IMAGE_PIXELS
		) {
			@unlink( $tmp );
			return new WP_Error( 'lunara_dispatch_image_dimensions', 'Remote image failed the dimension or decoded-pixel budget.' );
		}

		$path = (string) wp_parse_url( $image_url, PHP_URL_PATH );
		$name = sanitize_file_name( wp_basename( $path ) );
		$current_extension = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$valid_extensions = 'image/jpeg' === $mime ? array( 'jpg', 'jpeg' ) : array( $extensions[ $mime ] );
		if ( '' === $name || ! in_array( $current_extension, $valid_extensions, true ) ) {
			$base_name = sanitize_file_name( (string) pathinfo( $name, PATHINFO_FILENAME ) );
			if ( '' === $base_name ) {
				$base_name = 'lunara-dispatch-' . substr( md5( $image_url ), 0, 12 );
			}
			$name = $base_name . '.' . $extensions[ $mime ];
		}

		$file = array(
			'name' => $name,
			'tmp_name' => $tmp,
			'error' => 0,
			'size' => $size,
		);
		$result = media_handle_sideload( $file, (int) $parent_post_id, sanitize_text_field( (string) $title ) );
		if ( is_wp_error( $result ) ) {
			@unlink( $tmp );
		}
		return $result;
	}

	private function record_attachment_quality( $attachment_id ) {
		$file = get_attached_file( (int) $attachment_id );
		$size = $file && file_exists( $file ) ? wp_getimagesize( $file ) : false;
		if ( empty( $size[0] ) || empty( $size[1] ) ) {
			update_post_meta( (int) $attachment_id, '_lunara_dispatch_image_quality_state', 'unknown' );
			return;
		}
		update_post_meta( (int) $attachment_id, '_lunara_dispatch_source_dimensions', array(
			'width' => (int) $size[0],
			'height' => (int) $size[1],
		) );
		update_post_meta(
			(int) $attachment_id,
			'_lunara_dispatch_image_quality_state',
			(int) $size[0] >= self::HERO_MIN_WIDTH ? 'source_ok' : 'needs_better_source'
		);
	}

	private function is_public_https_url( $url ) {
		$url = esc_url_raw( (string) $url, array( 'https' ) );
		if ( '' === $url || 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || 'localhost' === $host || '.local' === substr( $host, -6 ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false !== filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return (bool) wp_http_validate_url( $url );
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
			if ( ! $this->item_has_source_story_image( $item ) ) {
				continue;
			}

			$attachment = $this->sideload(
				$item['image_url'],
				0,
				$item['title'] ?? '',
				$item['url'] ?? '',
				$item['source_label'] ?? '',
				$item['image_credit'] ?? '',
				$item['image_license'] ?? '',
				$item['image_rights_url'] ?? '',
				$item['image_origin'] ?? ''
			);
			if ( $attachment > 0 ) {
				$out[ $idx ] = $attachment;
			}
		}

		return $out;
	}

	/**
	 * Store provenance so the Media Library retains the exact source story,
	 * publication, extraction signal, and any supplied rights information.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $image_url     Remote image URL.
	 * @param string $source_url    Source article URL.
	 * @param string $source_label  Source label.
	 * @param string $image_credit  Image credit/copyright text.
	 * @return void
	 */
	private function store_source_context( $attachment_id, $image_url, $source_url = '', $source_label = '', $image_credit = '', $image_license = '', $image_rights_url = '', $image_origin = '' ) {
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
		if ( '' !== (string) $image_license ) {
			update_post_meta( $attachment_id, '_lunara_dispatch_image_license', sanitize_text_field( (string) $image_license ) );
		}
		if ( '' !== (string) $image_rights_url ) {
			update_post_meta( $attachment_id, '_lunara_dispatch_image_rights_url', esc_url_raw( (string) $image_rights_url, array( 'https' ) ) );
		}
		if ( '' !== (string) $image_origin ) {
			update_post_meta( $attachment_id, '_lunara_dispatch_image_origin', sanitize_key( (string) $image_origin ) );
		}
		update_post_meta( $attachment_id, '_lunara_dispatch_image_source_verified', '1' );
		if ( '' !== trim( (string) $image_license ) && $this->is_public_https_url( $image_rights_url ) ) {
			update_post_meta( $attachment_id, '_lunara_dispatch_image_rights_verified', '1' );
		} else {
			delete_post_meta( $attachment_id, '_lunara_dispatch_image_rights_verified' );
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
		$this->record_attachment_quality( $attachment_id );
		return false;
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
	 * Match and download only after Journal drafts have passed all text gates.
	 * Each accepted draft uses its canonical source URL for an exact match. If
	 * that provenance is missing, the draft remains unillustrated for review.
	 *
	 * @param int[] $post_ids Accepted Journal draft IDs.
	 * @param array $items    Bounded source items from this run.
	 * @return array
	 */
	public function assign_images_to_posts( array $post_ids, array $items ) {
		$result = array( 'matched' => 0, 'sideloaded' => 0 );
		$candidates = array();
		foreach ( $items as $idx => $item ) {
			if ( ! is_array( $item ) || ! $this->item_has_source_story_image( $item ) ) {
				continue;
			}
			$candidates[ $idx ] = true;
		}

		$used = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post || 'journal' !== $post->post_type || 'draft' !== $post->post_status || get_post_thumbnail_id( (int) $post_id ) ) {
				continue;
			}
			$source_urls = get_post_meta( (int) $post_id, '_lunara_dispatch_source_urls', true );
			$source_urls = is_array( $source_urls ) ? $source_urls : array_filter( array( $source_urls ) );
			$best_idx = $this->find_exact_source_item_index( $source_urls, $items, $candidates, $used );
			if ( $best_idx < 0 ) {
				continue;
			}

			$item = $items[ $best_idx ];
			$attachment_id = $this->sideload(
				$item['image_url'],
				(int) $post_id,
				$post->post_title,
				$item['url'] ?? '',
				$item['source_label'] ?? '',
				$item['image_credit'] ?? '',
				$item['image_license'] ?? '',
				$item['image_rights_url'] ?? '',
				$item['image_origin'] ?? ''
			);
			if ( $attachment_id <= 0 ) {
				continue;
			}

			$used[ $best_idx ] = true;
			$result['matched']++;
			$result['sideloaded']++;
			set_post_thumbnail( (int) $post_id, (int) $attachment_id );
			update_post_meta( (int) $post_id, '_lunara_dispatch_featured_image_source_url', esc_url_raw( (string) ( $item['url'] ?? '' ) ) );
			update_post_meta( (int) $post_id, '_lunara_dispatch_featured_image_match', 'exact_source_url' );
			delete_post_meta( (int) $post_id, '_lunara_dispatch_visual_status' );
			delete_post_meta( (int) $post_id, '_lunara_dispatch_visual_search_query' );
			delete_post_meta( (int) $post_id, '_lunara_dispatch_visual_brief' );
		}

		return $result;
	}

	private function item_has_source_story_image( array $item ) {
		return empty( $item['image_blocked'] )
			&& ! empty( $item['image_source_verified'] )
			&& ! empty( $item['image_url'] )
			&& ! empty( $item['url'] )
			&& ! empty( $item['source_label'] )
			&& ! empty( $item['image_origin'] )
			&& $this->is_public_https_url( $item['image_url'] )
			&& $this->is_public_https_url( $item['url'] );
	}

	private function find_exact_source_item_index( array $source_urls, array $items, array $candidates, array $used ) {
		$canonical_sources = array_values( array_filter( array_map( array( $this, 'canonical_source_url' ), $source_urls ) ) );
		if ( empty( $canonical_sources ) ) {
			return -1;
		}

		foreach ( array_keys( $candidates ) as $idx ) {
			if ( isset( $used[ $idx ] ) || empty( $items[ $idx ]['url'] ) ) {
				continue;
			}
			if ( in_array( $this->canonical_source_url( $items[ $idx ]['url'] ), $canonical_sources, true ) ) {
				return (int) $idx;
			}
		}

		return -1;
	}

	private function canonical_source_url( $url ) {
		$url = esc_url_raw( (string) $url, array( 'https' ) );
		return '' === $url ? '' : strtolower( untrailingslashit( $url ) );
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
