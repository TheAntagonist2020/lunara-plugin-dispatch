<?php
/**
 * Bounded article-context reader for Dispatch source inputs.
 *
 * Full article text is used only as ephemeral, untrusted prompt context. The
 * canonical Journal source ledger continues to store the bounded description,
 * source title, publication, and URL rather than copied article bodies.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Dispatch_Source_Reader {
    const MAX_ARTICLE_BYTES    = 1048576;
    const MAX_CONTEXT_CHARS    = 12000;
    const MIN_CONTEXT_CHARS    = 300;
    const MAX_READS_PER_RUN    = 8;
    const CACHE_TTL            = 21600;
    const NEGATIVE_CACHE_TTL   = 1800;
    const CACHE_PREFIX         = 'lunara_source_context_v1_';

    public function __construct() {
        if ( function_exists( 'add_action' ) ) {
            add_action( 'lunara_dispatch_article_html_fetched', array( $this, 'prime_from_html' ), 10, 2 );
        }
    }

    /**
     * Reuse HTML already fetched for exact source-image discovery.
     *
     * @param string $url  Article URL.
     * @param string $html Previously fetched bounded HTML.
     * @return bool Whether usable context was cached.
     */
    public function prime_from_html( $url, $html ) {
        $url  = $this->safe_public_url( $url );
        $html = (string) $html;
        if ( '' === $url || '' === trim( $html ) || strlen( $html ) >= self::MAX_ARTICLE_BYTES ) {
            return false;
        }
        $context = $this->extract_article_text( $html );
        if ( $this->string_length( $context ) < self::MIN_CONTEXT_CHARS ) {
            return false;
        }
        set_transient(
            self::CACHE_PREFIX . md5( strtolower( $url ) ),
            array(
                'status'    => 'ready',
                'context'   => $this->limit_string( $context, self::MAX_CONTEXT_CHARS ),
                'reason'    => 'article_text',
                'cache_hit' => false,
            ),
            self::CACHE_TTL
        );
        return true;
    }

    /**
     * Hydrate a bounded number of source items with ephemeral article context.
     *
     * @param array $items Dispatch source items.
     * @return array {items, ready, fallback, cache_hits, errors}
     */
    public function hydrate_items( array $items ) {
        $report = array(
            'items'      => $items,
            'ready'      => 0,
            'fallback'   => 0,
            'cache_hits' => 0,
            'errors'     => array(),
        );
        $reads = 0;

        foreach ( $report['items'] as $index => $item ) {
            $report['items'][ $index ]['full_context']        = '';
            $report['items'][ $index ]['full_context_status'] = 'fallback';
            $report['items'][ $index ]['full_context_reason'] = 'read_budget';

            if ( $reads >= self::MAX_READS_PER_RUN ) {
                $report['fallback']++;
                continue;
            }

            $reads++;
            $result = $this->read( $item['url'] ?? '' );
            $report['items'][ $index ]['full_context_status'] = $result['status'];
            $report['items'][ $index ]['full_context_reason'] = $result['reason'];
            if ( ! empty( $result['cache_hit'] ) ) {
                $report['cache_hits']++;
            }

            if ( 'ready' === $result['status'] ) {
                $report['items'][ $index ]['full_context'] = $result['context'];
                $report['ready']++;
                continue;
            }

            $report['fallback']++;
            $label = sanitize_text_field( (string) ( $item['source_label'] ?? 'Source' ) );
            $report['errors'][] = $label . ': ' . sanitize_key( (string) $result['reason'] );
        }

        $report['errors'] = array_values( array_unique( $report['errors'] ) );
        return $report;
    }

    /**
     * Read one public HTTP(S) article through the WordPress safe HTTP client.
     *
     * @param string $url Article URL.
     * @return array {status, context, reason, cache_hit}
     */
    public function read( $url ) {
        $url = $this->safe_public_url( $url );
        if ( '' === $url ) {
            return $this->fallback( 'unsafe_url' );
        }

        $cache_key = self::CACHE_PREFIX . md5( strtolower( $url ) );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['status'], $cached['reason'] ) ) {
            $cached['cache_hit'] = true;
            $cached['context']   = isset( $cached['context'] ) ? (string) $cached['context'] : '';
            return $cached;
        }

        $response = wp_safe_remote_get(
            $url,
            array(
                'timeout'             => 10,
                'redirection'         => 2,
                'reject_unsafe_urls'  => true,
                'limit_response_size' => self::MAX_ARTICLE_BYTES,
                'user-agent'          => 'Mozilla/5.0 (compatible; LunaraDispatch/3.2.7; +https://lunarafilm.com)',
            )
        );
        if ( is_wp_error( $response ) ) {
            return $this->cache_fallback( $cache_key, 'http_error' );
        }
        if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            return $this->cache_fallback( $cache_key, 'http_status' );
        }

        $content_type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
        if ( '' !== $content_type && false === strpos( $content_type, 'text/html' ) && false === strpos( $content_type, 'application/xhtml+xml' ) ) {
            return $this->cache_fallback( $cache_key, 'non_html' );
        }

        $html = (string) wp_remote_retrieve_body( $response );
        if ( '' === trim( $html ) || strlen( $html ) >= self::MAX_ARTICLE_BYTES ) {
            return $this->cache_fallback( $cache_key, 'response_limit' );
        }

        $context = $this->extract_article_text( $html );
        if ( $this->string_length( $context ) < self::MIN_CONTEXT_CHARS ) {
            return $this->cache_fallback( $cache_key, 'insufficient_text' );
        }

        $result = array(
            'status'    => 'ready',
            'context'   => $this->limit_string( $context, self::MAX_CONTEXT_CHARS ),
            'reason'    => 'article_text',
            'cache_hit' => false,
        );
        set_transient( $cache_key, $result, self::CACHE_TTL );
        return $result;
    }

    private function extract_article_text( $html ) {
        if ( class_exists( 'DOMDocument' ) && class_exists( 'DOMXPath' ) ) {
            $text = $this->extract_with_dom( $html );
            if ( $this->string_length( $text ) >= self::MIN_CONTEXT_CHARS ) {
                return $text;
            }
        }
        return $this->extract_with_markup_fallback( $html );
    }

    private function extract_with_dom( $html ) {
        $previous = libxml_use_internal_errors( true );
        $document = new DOMDocument();
        $flags    = defined( 'LIBXML_NONET' ) ? LIBXML_NONET : 0;
        $loaded   = $document->loadHTML( '<?xml encoding="utf-8" ?>' . $html, $flags );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( ! $loaded ) {
            return '';
        }

        $xpath = new DOMXPath( $document );
        $remove_nodes = array();
        foreach ( $xpath->query( '//script|//style|//nav|//footer|//header|//aside|//form|//noscript|//svg|//iframe|//button|//figure|//figcaption' ) as $node ) {
            $remove_nodes[] = $node;
        }
        foreach ( $remove_nodes as $node ) {
            if ( $node->parentNode ) {
                $node->parentNode->removeChild( $node );
            }
        }

        $candidate_query = "//*[@itemprop='articleBody']|//article|//main|//*[contains(concat(' ', normalize-space(@class), ' '), ' entry-content ')]|//*[contains(concat(' ', normalize-space(@class), ' '), ' post-content ')]|//*[contains(concat(' ', normalize-space(@class), ' '), ' article-body ')]|//*[contains(concat(' ', normalize-space(@class), ' '), ' story-body ')]";
        $best            = '';
        $best_length     = 0;
        $seen            = array();

        foreach ( $xpath->query( $candidate_query ) as $candidate ) {
            $hash = spl_object_hash( $candidate );
            if ( isset( $seen[ $hash ] ) ) {
                continue;
            }
            $seen[ $hash ] = true;
            $parts         = array();
            foreach ( $xpath->query( './/h2|.//h3|.//p', $candidate ) as $paragraph ) {
                $text = $this->normalize_text( $paragraph->textContent );
                if ( $this->string_length( $text ) >= 30 && ! $this->is_boilerplate( $text ) ) {
                    $parts[] = $text;
                }
            }
            if ( empty( $parts ) ) {
                $parts[] = $this->normalize_text( $candidate->textContent );
            }
            $candidate_text = trim( implode( "\n\n", array_values( array_unique( array_filter( $parts ) ) ) ) );
            $length         = $this->string_length( $candidate_text );
            if ( $length > $best_length ) {
                $best        = $candidate_text;
                $best_length = $length;
            }
        }

        return $this->limit_string( $best, self::MAX_CONTEXT_CHARS );
    }

    private function extract_with_markup_fallback( $html ) {
        $html = preg_replace( '#<(script|style|nav|footer|header|aside|form|noscript|svg|iframe|button|figure)[^>]*>.*?</\1>#is', ' ', (string) $html );
        $html = preg_replace( '#</?(?:p|h2|h3|li|br)[^>]*>#i', "\n", (string) $html );
        $text = $this->normalize_text( wp_strip_all_tags( $html ) );
        return $this->limit_string( $text, self::MAX_CONTEXT_CHARS );
    }

    private function safe_public_url( $value ) {
        $url    = esc_url_raw( trim( (string) $value ), array( 'http', 'https' ) );
        $scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        if ( '' === $url || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return '';
        }
        return wp_http_validate_url( $url ) ? $url : '';
    }

    private function is_boilerplate( $text ) {
        return (bool) preg_match( '/^(?:advertisement|subscribe|sign up|cookie settings|related stories|read more)\b/i', trim( (string) $text ) );
    }

    private function normalize_text( $value ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) );
        return trim( preg_replace( '/\s+/u', ' ', $value ) );
    }

    private function cache_fallback( $cache_key, $reason ) {
        $result = $this->fallback( $reason );
        set_transient( $cache_key, $result, self::NEGATIVE_CACHE_TTL );
        return $result;
    }

    private function fallback( $reason ) {
        return array(
            'status'    => 'fallback',
            'context'   => '',
            'reason'    => sanitize_key( (string) $reason ),
            'cache_hit' => false,
        );
    }

    private function limit_string( $value, $limit ) {
        $limit = max( 1, (int) $limit );
        return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, 0, $limit ) : substr( (string) $value, 0, $limit );
    }

    private function string_length( $value ) {
        return function_exists( 'mb_strlen' ) ? mb_strlen( (string) $value ) : strlen( (string) $value );
    }
}
