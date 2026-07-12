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

	/**
	 * Sections skipped during the latest split because they matched recent
	 * Journal topics.
	 *
	 * @var array
	 */
	private $last_topic_duplicate_skips = array();

	/**
	 * Sections skipped during the latest split because they failed the
	 * editorial quality gate.
	 *
	 * @var array
	 */
	private $last_quality_gate_skips = array();

	/** @var array */
	private $last_insertion_failures = array();

	public function get_target_post_type() {
		return 'journal';
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
	public function split_into_individual_posts( $html, array $section_image_map, $post_type, $post_status = 'draft', array $run_context = array() ) {
		$created                           = array();
		$post_status                       = 'draft';
		$post_type                         = 'journal';
		$this->last_topic_duplicate_skips  = array();
		$this->last_quality_gate_skips     = array();
		$this->last_insertion_failures     = array();
		if ( ! post_type_exists( $post_type ) ) {
			error_log( 'Lunara Dispatch: Journal post type is unavailable; refusing to create fallback posts.' );
			return $created;
		}
		$sections                          = $this->extract_sections_with_body( $html );
		if ( empty( $sections ) ) {
			return $created;
		}

		$author            = get_current_user_id() ?: 1;
		$recent_signatures = $this->get_recent_topic_signatures( $post_type );
		$run_signatures    = array();

		foreach ( $sections as $section_index => $section ) {
			$title = trim( $section['title'] );
			$body  = trim( $section['body'] );
			$quality_failure = $this->publishable_section_failure( $title, $body );
			if ( '' !== $quality_failure ) {
				$this->last_quality_gate_skips[] = array(
					'title'  => '' !== $title ? $title : '(untitled section)',
					'reason' => $quality_failure,
				);

				error_log(
					sprintf(
						'Lunara Dispatch: quality gate skipped "%s" (%s).',
						'' !== $title ? $title : '(untitled section)',
						$quality_failure
					)
				);
				continue;
			}

			$signature = $this->build_topic_signature( $title, $body );
			$match     = $this->find_topic_duplicate_match(
				$signature,
				array_merge( $recent_signatures, $run_signatures )
			);

			if ( ! empty( $match ) ) {
				$this->last_topic_duplicate_skips[] = array(
					'title'         => $title,
					'matched_title' => $match['title'],
					'matched_id'    => $match['post_id'],
					'score'         => $match['score'],
				);

				error_log(
					sprintf(
						'Lunara Dispatch: skipped topic duplicate "%s" near "%s" (score %.2f).',
						$title,
						$match['title'],
						$match['score']
					)
				);
				continue;
			}

			$slug     = sanitize_title( $title );
			$featured = isset( $section_image_map[ $slug ] ) ? (int) $section_image_map[ $slug ] : 0;
			$payload = Lunara_Dispatch_Journal_Ingest_Bridge::build_payload( $title, $body, $run_context, (int) $section_index );
			$payload['featured_media'] = $featured;
			$ingest  = Lunara_Dispatch_Journal_Ingest_Bridge::ingest_payload( $payload, $author, $run_context );
			$new_id  = is_wp_error( $ingest ) ? $ingest : (int) $ingest['post_id'];

			if ( is_wp_error( $new_id ) || ! $new_id ) {
				$this->last_insertion_failures[] = array(
					'title' => $title,
					'reason' => is_wp_error( $new_id ) ? $new_id->get_error_message() : 'unknown insert failure',
				);
				error_log(
					'Lunara Dispatch: failed to create post "' . $title . '": ' .
					( is_wp_error( $new_id ) ? $new_id->get_error_message() : 'unknown error' )
				);
				continue;
			}

			if ( $featured > 0 ) {
				set_post_thumbnail( $new_id, $featured );
				delete_post_meta( $new_id, '_lunara_dispatch_visual_status' );
				delete_post_meta( $new_id, '_lunara_dispatch_visual_search_query' );
				delete_post_meta( $new_id, '_lunara_dispatch_visual_brief' );
			} else {
				$this->store_visual_assignment_brief( $new_id, $title, $body );
			}

			update_post_meta( $new_id, '_lunara_dispatch_editorial_state', 'needs_review' );
			if ( ! empty( $run_context['run_id'] ) ) {
				update_post_meta( $new_id, '_lunara_dispatch_run_id', sanitize_text_field( (string) $run_context['run_id'] ) );
			}
			$source_urls = array_values( array_unique( array_filter( array_map( static function ( $item ) {
				return is_array( $item ) && ! empty( $item['source_url'] ) ? esc_url_raw( $item['source_url'] ) : '';
			}, $payload['source_items'] ) ) ) );
			update_post_meta( $new_id, '_lunara_dispatch_source_urls', array_slice( $source_urls, 0, 3 ) );
			update_post_meta( $new_id, '_lunara_dispatch_prompt_hash', hash( 'sha256', Lunara_Dispatch_Prompts::system_prompt() . "\n" . Lunara_Dispatch_Prompts::user_directive_prompt() ) );

			$signature['post_id'] = (int) $new_id;
			$run_signatures[]     = $signature;
			if ( ! in_array( (int) $new_id, $created, true ) ) {
				$created[] = (int) $new_id;
			}
		}

		return $created;
	}

	/**
	 * Store a human-reviewable visual search brief for drafts that need art.
	 *
	 * @param int    $post_id Created post ID.
	 * @param string $title   Post title.
	 * @param string $body    Post body HTML.
	 * @return void
	 */
	private function store_visual_assignment_brief( $post_id, $title, $body ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		$title = trim( wp_strip_all_tags( (string) $title ) );
		$text  = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $body ) ) );
		$query = $this->build_visual_search_query( $title, $text );
		$brief = sprintf(
			'Needs a safe, exact visual for "%s". Prefer an official still, trailer frame, festival/press image, distributor asset, or correctly credited outlet image tied to the actual subject. Avoid generic actor headshots or unrelated source images unless the story is explicitly about that person.',
			$title
		);

		if ( '' !== $text ) {
			$brief .= ' Context: ' . wp_trim_words( $text, 34, '...' );
		}

		update_post_meta( $post_id, '_lunara_dispatch_visual_status', 'needs_visual' );
		update_post_meta( $post_id, '_lunara_dispatch_visual_search_query', sanitize_text_field( $query ) );
		update_post_meta( $post_id, '_lunara_dispatch_visual_brief', sanitize_textarea_field( $brief ) );
	}

	/**
	 * Build a practical visual-search query from a generated Journal title.
	 *
	 * @param string $title Post title.
	 * @param string $text  Body text.
	 * @return string
	 */
	private function build_visual_search_query( $title, $text = '' ) {
		$base = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$base = trim( preg_replace( '/[^\p{L}\p{N}\s\'".:&-]+/u', ' ', $base ) );
		$base = trim( preg_replace( '/\s+/u', ' ', (string) $base ) );

		if ( '' === $base ) {
			$base = wp_trim_words( wp_strip_all_tags( (string) $text ), 10, '' );
		}

		return trim( $base . ' film official still press image trailer' );
	}

	/**
	 * Return topic-duplicate skips from the latest split operation.
	 *
	 * @return array
	 */
	public function get_last_topic_duplicate_skips() {
		return $this->last_topic_duplicate_skips;
	}

	/**
	 * Return quality-gate skips from the latest split operation.
	 *
	 * @return array
	 */
	public function get_last_quality_gate_skips() {
		return $this->last_quality_gate_skips;
	}

	public function get_last_insertion_failures() {
		return $this->last_insertion_failures;
	}

	/**
	 * Reject thin or formulaic AI sections before they can become live posts.
	 *
	 * @param string $title Section title.
	 * @param string $body  Section body HTML.
	 * @return bool
	 */
	private function is_publishable_section( $title, $body ) {
		return '' === $this->publishable_section_failure( $title, $body );
	}

	/**
	 * Multibyte-safe word count. str_word_count() only recognizes ASCII word
	 * characters, so an accented name (Cuaron, Amelie, Inarritu -- routine in
	 * film journalism) gets fragmented into extra tokens at each accent,
	 * inflating the count. Assumes $text already has whitespace trimmed and
	 * collapsed, which every caller here already does.
	 *
	 * @param string $text Already-normalized text.
	 * @return int
	 */
	private function count_words( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return 0;
		}
		return count( preg_split( '/\s+/u', $text ) );
	}

	/**
	 * Explain why a generated section should not become a Journal draft.
	 *
	 * @param string $title Section title.
	 * @param string $body  Section body HTML.
	 * @return string Empty when publishable.
	 */
	private function publishable_section_failure( $title, $body ) {
		$title = trim( (string) $title );
		$body  = trim( (string) $body );

		if ( '' === $title || '' === $body ) {
			return 'missing title or body';
		}

		if ( preg_match( '/^lunara journal\b/i', $title ) ) {
			return 'generic Journal wrapper title';
		}

		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $body ) ) );
		if ( '' === $text ) {
			return 'empty text after stripping HTML';
		}

		if ( $this->count_words( $text ) < 75 ) {
			return 'under 75 words';
		}

		if ( substr_count( strtolower( $body ), '<p' ) < 2 ) {
			return 'fewer than two real paragraphs';
		}

		if ( ! $this->has_publishable_headline_shape( $title ) ) {
			return 'weak headline shape';
		}

		// Kept to genuine press-release / churnalism tells. Dropped 'poised to'
		// and 'underscores' -- common enough in sharp, legitimate criticism that
		// they were catching good stories on an incidental word, not bad ones.
		$banned_phrases = array(
			'this matters because',
			'worth keeping an eye on',
			'raises significant questions',
			'highly anticipated',
			'made waves',
			'only time will tell',
			'fans are eagerly awaiting',
			'delves into',
			'a testament to',
			'the announcement comes as',
			'the news comes as',
			'the project is described as',
			'in an exclusive report',
		);

		$lower_text = strtolower( $text );
		foreach ( $banned_phrases as $phrase ) {
			if ( false !== strpos( $lower_text, $phrase ) ) {
				return 'banned phrase: ' . $phrase;
			}
		}

		// These two checks used to be AND-gated (both required), which meant a
		// genuinely sharp, well-written section could still be auto-rejected
		// just for not happening to use a word from one of two fixed lists.
		// Either signal is real evidence of editorial voice on its own, so
		// requiring just one cuts false rejections without lowering the bar
		// on stories that show neither. Written inline (not pre-assigned to
		// variables) so && short-circuits and skips the second, pricier
		// keyword scan once the first signal already passed.
		if ( ! $this->has_originality_signal( $title . ' ' . $text ) && ! $this->has_reader_pull_signal( $title . ' ' . $text ) ) {
			return 'no distinct Lunara angle or reader-pull signal';
		}

		if ( $this->has_dead_register_density( $title . ' ' . $text ) ) {
			return 'dead analyst register';
		}

		if ( $this->mentions_source_risk_outlet( $title . ' ' . $text ) && ! $this->has_source_risk_originality_signal( $title . ' ' . $text ) ) {
			return 'source-risk item without enough original judgment';
		}

		return '';
	}

	/**
	 * Ensure headlines look like editorial entries, not feed-parser labels.
	 *
	 * @param string $title Generated title.
	 * @return bool
	 */
	private function has_publishable_headline_shape( $title ) {
		$title = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $title ) ) );
		if ( '' === $title ) {
			return false;
		}

		$word_count = $this->count_words( $title );
		if ( $word_count < 4 || $word_count > 16 ) {
			return false;
		}

		$lower_title = strtolower( $title );
		$weak_shapes = array(
			'/^.+\s+news$/',
			'/^.+\s+update$/',
			'/^.+\s+announcement$/',
			'/^.+\s+trailer\s+(arrives|drops|released)$/',
			'/^.+\s+gets\s+(release date|first look|new trailer)$/',
			'/^(new|latest)\s+.+\s+(revealed|announced|confirmed)$/',
			'/^what\s+we\s+know\s+about\b/',
			'/^everything\s+we\s+know\s+about\b/',
			'/^.+\s+is\s+coming\s+soon$/',
		);

		foreach ( $weak_shapes as $pattern ) {
			if ( preg_match( $pattern, $lower_title ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Require evidence that the draft has a film-reader reason to exist.
	 *
	 * @param string $text Title and body text.
	 * @return bool
	 */
	private function has_reader_pull_signal( $text ) {
		$signals = array(
			'admiration',
			'affection',
			'ambition',
			'argument',
			'audience',
			'brave',
			'cowardice',
			'curiosity',
			'delight',
			'disappointment',
			'dread',
			'exciting',
			'explains',
			'fan',
			'feels',
			'friction',
			'gatekeeping',
			'hope',
			'human',
			'irritation',
			'nerve',
			'pleasure',
			'promise',
			'protective',
			'race',
			'racism',
			'reader',
			'reveals',
			'risk',
			'rooting',
			'stakes',
			'stings',
			'taste',
			'tension',
			'thrilling',
			'watching',
			'worry',
		);

		$count      = 0;
		$lower_text = strtolower( wp_strip_all_tags( (string) $text ) );
		foreach ( $signals as $signal ) {
			if ( false !== strpos( $lower_text, $signal ) ) {
				$count++;
			}
		}

		return $count >= 2;
	}

	/**
	 * Catch prose that is mostly strategy-memo language without pulse.
	 *
	 * @param string $text Title and body text.
	 * @return bool
	 */
	private function has_dead_register_density( $text ) {
		$dead_terms = array(
			'boardroom',
			'brand extension',
			'content engine',
			'corporate strategy',
			'franchise strategy',
			'infrastructure',
			'market positioning',
			'pipeline',
			'platform strategy',
			'production mandate',
			'strategic move',
			'studio strategy',
			'synergy',
			'theme-park',
		);

		$count      = 0;
		$lower_text = strtolower( wp_strip_all_tags( (string) $text ) );
		foreach ( $dead_terms as $term ) {
			if ( false !== strpos( $lower_text, $term ) ) {
				$count++;
			}
		}

		return $count >= 3 && ! $this->has_reader_pull_signal( $text );
	}

	/**
	 * Require some evidence that a generated section has a Lunara angle,
	 * not only a reformatted source summary.
	 *
	 * @param string $text Title and body text.
	 * @return bool
	 */
	private function has_originality_signal( $text ) {
		$phrases = array(
			'not just',
			'is not just',
			'isn\'t just',
			'what separates',
			'what makes',
			'worth rooting for',
			'the point',
			'the signal',
			'the pattern',
			'the risk',
			'the stake',
			'the stakes',
			'the tension',
			'reads like',
			'looks like',
			'starts to look',
			'the evidence',
			'the pleasure',
			'the hook',
			'the disappointment',
			'reader',
			'audience',
			'career',
			'taste',
			'context',
			'judgment',
			'consequence',
			'pressure',
			'promise',
			'hook',
			'ambition',
			'nerve',
			'explains',
			'reveals',
			'strategy',
			'filmmaker',
			'studio',
			'festival',
			'awards',
			'gatekeeping',
			'race',
			'racism',
			'institutional',
		);

		$lower_text = strtolower( wp_strip_all_tags( (string) $text ) );
		foreach ( $phrases as $phrase ) {
			if ( false !== strpos( $lower_text, $phrase ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detect outlets that are useful as fast leads but need a higher originality
	 * bar before a generated section can become even a draft.
	 *
	 * @param string $text Title and body text.
	 * @return bool
	 */
	private function mentions_source_risk_outlet( $text ) {
		$lower_text = strtolower( wp_strip_all_tags( (string) $text ) );
		return false !== strpos( $lower_text, 'world of reel' ) || false !== strpos( $lower_text, 'worldofreel.com' );
	}

	/**
	 * Require more than generic attribution when a source-risk outlet is named.
	 *
	 * @param string $text Title and body text.
	 * @return bool
	 */
	private function has_source_risk_originality_signal( $text ) {
		$signals = array(
			'not just',
			'isn\'t just',
			'the point',
			'the signal',
			'the pattern',
			'the evidence',
			'the risk',
			'the stake',
			'the stakes',
			'the tension',
			'the hook',
			'reads like',
			'looks like',
			'reveals',
			'explains',
			'what separates',
			'what makes',
			'consequence',
			'pressure',
			'contradiction',
			'reader',
			'audience',
			'taste',
			'context',
			'judgment',
			'hook',
		);

		$count      = 0;
		$lower_text = strtolower( wp_strip_all_tags( (string) $text ) );
		foreach ( $signals as $signal ) {
			if ( false !== strpos( $lower_text, $signal ) ) {
				$count++;
			}
		}

		return $count >= 2;
	}

	/**
	 * Build lightweight signatures for recent Journal posts so generated drafts
	 * do not repeat the same person/film/topic a few runs later.
	 *
	 * @param string $post_type Target post type.
	 * @return array
	 */
	private function get_recent_topic_signatures( $post_type ) {
		$ids = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 80,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'date_query'             => array(
					array(
						'after'     => '45 days ago',
						'inclusive' => true,
					),
				),
			)
		);

		if ( empty( $ids ) ) {
			return array();
		}

		$signatures = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}

			$signatures[] = $this->build_topic_signature(
				$post->post_title,
				$post->post_content,
				(int) $post->ID
			);
		}

		return $signatures;
	}

	/**
	 * Create a comparable topic signature from title and body text.
	 *
	 * @param string $title   Post title.
	 * @param string $body    Post body HTML.
	 * @param int    $post_id Existing post ID when available.
	 * @return array
	 */
	private function build_topic_signature( $title, $body, $post_id = 0 ) {
		$title = trim( wp_strip_all_tags( (string) $title ) );
		$text  = trim( wp_strip_all_tags( (string) $body ) );

		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, 1600 );
		} else {
			$text = substr( $text, 0, 1600 );
		}

		return array(
			'post_id'      => (int) $post_id,
			'title'        => $title,
			'normalized'   => $this->normalize_topic_text( $title ),
			'tokens'       => $this->extract_topic_tokens( $title . ' ' . $text ),
			'title_tokens' => $this->extract_topic_tokens( $title ),
			'phrases'      => $this->extract_topic_phrases( $title . ' ' . $text ),
		);
	}

	/**
	 * Find the first recent topic that is too close to a generated section.
	 *
	 * @param array $candidate  Generated section signature.
	 * @param array $signatures Existing/recent signatures.
	 * @return array
	 */
	private function find_topic_duplicate_match( array $candidate, array $signatures ) {
		foreach ( $signatures as $signature ) {
			$score = $this->topic_similarity_score( $candidate, $signature );
			if ( $score >= 0.62 ) {
				return array(
					'post_id' => isset( $signature['post_id'] ) ? (int) $signature['post_id'] : 0,
					'title'   => isset( $signature['title'] ) ? (string) $signature['title'] : '',
					'score'   => $score,
				);
			}
		}

		return array();
	}

	/**
	 * Score two topic signatures using title overlap, important token overlap,
	 * and shared proper-noun phrases.
	 *
	 * @param array $a First signature.
	 * @param array $b Second signature.
	 * @return float
	 */
	private function topic_similarity_score( array $a, array $b ) {
		if ( ! empty( $a['normalized'] ) && $a['normalized'] === $b['normalized'] ) {
			return 1.0;
		}

		$tokens_a       = isset( $a['tokens'] ) ? (array) $a['tokens'] : array();
		$tokens_b       = isset( $b['tokens'] ) ? (array) $b['tokens'] : array();
		$title_tokens_a = isset( $a['title_tokens'] ) ? (array) $a['title_tokens'] : array();
		$title_tokens_b = isset( $b['title_tokens'] ) ? (array) $b['title_tokens'] : array();
		$phrases_a      = isset( $a['phrases'] ) ? (array) $a['phrases'] : array();
		$phrases_b      = isset( $b['phrases'] ) ? (array) $b['phrases'] : array();

		$shared_tokens       = count( array_intersect( $tokens_a, $tokens_b ) );
		$shared_title_tokens = count( array_intersect( $title_tokens_a, $title_tokens_b ) );
		$shared_phrases      = count( array_intersect( $phrases_a, $phrases_b ) );

		$token_overlap = $this->safe_ratio( $shared_tokens, min( count( $tokens_a ), count( $tokens_b ) ) );
		$title_overlap = $this->safe_ratio( $shared_title_tokens, min( count( $title_tokens_a ), count( $title_tokens_b ) ) );
		$token_jaccard = $this->safe_ratio(
			$shared_tokens,
			count( array_unique( array_merge( $tokens_a, $tokens_b ) ) )
		);

		$score = max( $token_jaccard, $token_overlap * 0.70, $title_overlap * 0.82 );

		if ( $shared_title_tokens >= 3 && $title_overlap >= 0.50 ) {
			$score = max( $score, 0.72 );
		}

		if ( $shared_phrases >= 2 && $token_overlap >= 0.25 ) {
			$score = max( $score, 0.74 );
		}

		if ( $shared_phrases >= 1 && $shared_title_tokens >= 2 && $title_overlap >= 0.45 ) {
			$score = max( $score, 0.68 );
		}

		if ( $shared_tokens >= 5 && $token_overlap >= 0.40 ) {
			$score = max( $score, 0.66 );
		}

		return min( 1.0, (float) $score );
	}

	/**
	 * Tokenize topic text, keeping only meaningful film-news terms.
	 *
	 * @param string $text Raw text.
	 * @return array
	 */
	private function extract_topic_tokens( $text ) {
		$text = $this->normalize_topic_text( $text );
		if ( '' === $text ) {
			return array();
		}

		$parts = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( empty( $parts ) ) {
			return array();
		}

		$stopwords = array(
			'about', 'after', 'again', 'also', 'and', 'are', 'because', 'been',
			'before', 'being', 'between', 'but', 'can', 'cannes', 'could',
			'does', 'film', 'films', 'from', 'has', 'have', 'her', 'his',
			'into', 'its', 'journal', 'like', 'more', 'movie', 'movies',
			'new', 'news', 'not', 'now', 'one', 'only', 'over', 'post',
			'press', 'really', 'says', 'she', 'that', 'the', 'their', 'them',
			'then', 'there', 'this', 'through', 'was', 'week', 'when',
			'where', 'which', 'while', 'who', 'will', 'with', 'would',
		);

		$tokens = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( strlen( $part ) < 3 || in_array( $part, $stopwords, true ) ) {
				continue;
			}

			if ( strlen( $part ) > 4 && 's' === substr( $part, -1 ) ) {
				$part = substr( $part, 0, -1 );
			}

			$tokens[ $part ] = true;
		}

		return array_keys( $tokens );
	}

	/**
	 * Extract proper-name/movie-like phrases for stronger topic matching.
	 *
	 * @param string $text Raw text.
	 * @return array
	 */
	private function extract_topic_phrases( $text ) {
		$text = wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, get_bloginfo( 'charset' ) ) );
		if ( '' === trim( $text ) ) {
			return array();
		}

		preg_match_all(
			'/\b([A-Z][A-Za-z0-9\'-]+(?:\s+(?:[A-Z][A-Za-z0-9\'-]+|[A-Z]{2,}|[IVX]+)){1,4})\b/',
			$text,
			$matches
		);

		if ( empty( $matches[1] ) ) {
			return array();
		}

		$phrases = array();
		foreach ( $matches[1] as $phrase ) {
			$normalized = $this->normalize_topic_text( $phrase );
			if ( '' === $normalized ) {
				continue;
			}

			$words = explode( ' ', $normalized );
			if ( count( $words ) < 2 ) {
				continue;
			}

			$phrases[ $normalized ] = true;
		}

		return array_keys( $phrases );
	}

	/**
	 * Normalize text for duplicate comparison.
	 *
	 * @param string $text Raw text.
	 * @return string
	 */
	private function normalize_topic_text( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, get_bloginfo( 'charset' ) );
		$text = remove_accents( $text );
		$text = strtolower( $text );
		$text = preg_replace( '/[^a-z0-9]+/', ' ', $text );
		return trim( preg_replace( '/\s+/', ' ', (string) $text ) );
	}

	/**
	 * Divide safely.
	 *
	 * @param int|float $numerator Numerator.
	 * @param int|float $denominator Denominator.
	 * @return float
	 */
	private function safe_ratio( $numerator, $denominator ) {
		$denominator = (float) $denominator;
		if ( $denominator <= 0 ) {
			return 0.0;
		}

		return (float) $numerator / $denominator;
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
		unset( $dry_run, $limit );
		return array(
			'scanned'  => 0,
			'migrated' => 0,
			'created'  => 0,
			'skipped'  => 0,
			'preview'  => array(),
			'disabled' => true,
			'message'  => 'Dispatch legacy splitting is retired. Use Journal Foundation migration preview and explicit confirmation.',
		);

	}
}
