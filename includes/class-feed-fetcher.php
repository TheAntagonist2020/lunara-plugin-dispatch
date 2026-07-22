<?php
/**
 * Lunara_Dispatch_Feed_Fetcher
 *
 * Fetches multiple RSS feeds, dedupes against the seen-sources tracker,
 * and extracts an image URL for each item using a layered strategy:
 *   1. RSS <enclosure> / richer <media:content>
 *   2. og:image / twitter:image scraped from the article URL
 *   3. Largest srcset candidate / lazy-loaded image on the article page
 *   4. <media:thumbnail>
 *   5. First <img> in the RSS content/description
 *   6. First <img> in the scraped article HTML
 *
 * We also normalize common resized image URLs (for example `-300x169` file
 * suffixes or `?w=300&h=169` query strings) so Dispatch reaches for the
 * best available source asset before sideloading it into WordPress.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lunara_Dispatch_Feed_Fetcher {

    const SEEN_OPTION = 'lunara_dispatch_seen_sources';
    const MAX_ITEMS_PER_RUN = 18;
    const MAX_DESCRIPTION_CHARS = 1800;
    const MAX_FEED_BYTES = 2097152;
    const MAX_ARTICLE_BYTES = 1048576;

    /**
     * Aggregate enabled sources into a single deduped list of fresh items.
     *
     * @return array {
     *   @type array $items              [ ['title','url','description','image_url','image_credit','source_label','fingerprint'], ... ]
     *   @type int   $skipped_duplicates
     *   @type array $errors             [ source_label => message ]
     * }
     */
    public function fetch_all() {
        $sources = Lunara_Dispatch_Sources::enabled();
        usort($sources, static function ($a, $b) {
            $priority = ((int) ($b['priority'] ?? 5)) <=> ((int) ($a['priority'] ?? 5));
            return 0 !== $priority ? $priority : strcasecmp((string) ($a['label'] ?? ''), (string) ($b['label'] ?? ''));
        });
        $seen    = $this->load_seen_sources();

        $items   = array();
        $skipped = 0;
        $errors  = array();
        $seen_in_this_run = array();

        foreach ($sources as $source) {
            if (count($items) >= self::MAX_ITEMS_PER_RUN) {
                break;
            }
            if (!$this->is_public_https_url($source['url'] ?? '')) {
                $errors[$source['label']] = 'Feed URL must be a public HTTPS endpoint.';
                continue;
            }
            $feed = $this->fetch_bounded_feed($source['url']);
            if (is_wp_error($feed)) {
                $errors[$source['label']] = $feed->get_error_message();
                continue;
            }

            $rss_items = $feed->get_items(0, $source['max']);
            if (empty($rss_items)) {
                continue;
            }

            foreach ($rss_items as $rss_item) {
                if (count($items) >= self::MAX_ITEMS_PER_RUN) {
                    break;
                }
                $url = esc_url_raw($rss_item->get_permalink());
                if (empty($url) || !$this->is_public_https_url($url)) {
                    continue;
                }
                $fp = $this->fingerprint($url);
                if (isset($seen[$fp]) || isset($seen_in_this_run[$fp])) {
                    $skipped++;
                    continue;
                }
                $seen_in_this_run[$fp] = true;

                $image_blocked = $this->is_image_blocked_source( $source, $url );
                $source_allows_images = !$image_blocked;
                $image_origin = '';
                $image_url = $source_allows_images ? $this->extract_image_url($rss_item, $url, $image_origin) : '';
                $image_credit = $source_allows_images ? $this->extract_image_credit($rss_item) : '';
                $image_rights = $source_allows_images ? $this->extract_image_rights($rss_item) : array();
                $image_source_verified = $source_allows_images && $this->is_source_story_image(
                    $image_url,
                    $image_origin
                );
                $image_rights_verified = $source_allows_images && $this->has_asset_reuse_rights(
                    $image_url,
                    $image_origin,
                    $image_credit,
                    $image_rights
                );

                $items[] = array(
                    'title'        => $this->truncate_text(wp_strip_all_tags((string) $rss_item->get_title()), 250),
                    'url'          => $url,
                    'description'  => $this->truncate_text(wp_strip_all_tags((string) $rss_item->get_description()), self::MAX_DESCRIPTION_CHARS),
                    'image_url'    => $image_url,
                    'image_credit' => $image_credit,
                    'image_origin' => $image_origin,
                    'image_license' => $image_rights['license'] ?? '',
                    'image_rights_url' => $image_rights['url'] ?? '',
                    'image_source_verified' => $image_source_verified,
                    'image_rights_verified' => $image_rights_verified,
                    'source_label' => $source['label'],
                    'source_policy' => $this->source_policy_note( $source, $url ),
                    'image_blocked' => $image_blocked,
                    'image_reuse_allowed' => $image_source_verified,
                    'priority'     => isset($source['priority']) ? (int) $source['priority'] : 5,
                    'fingerprint'  => $fp,
                );
            }
        }

        return array(
            'items'              => $items,
            'skipped_duplicates' => $skipped,
            'errors'             => $errors,
        );
    }

    private function fetch_bounded_feed($url) {
        $target = esc_url_raw((string) $url, array('https'));
        $args_filter = static function ($args, $request_url) use ($target) {
            if (esc_url_raw((string) $request_url, array('https')) !== $target) {
                return $args;
            }
            $args['timeout'] = 15;
            $args['redirection'] = 2;
            $args['reject_unsafe_urls'] = true;
            $args['limit_response_size'] = Lunara_Dispatch_Feed_Fetcher::MAX_FEED_BYTES;
            return $args;
        };
        add_filter('http_request_args', $args_filter, 10, 2);
        try {
            return fetch_feed($target);
        } finally {
            remove_filter('http_request_args', $args_filter, 10);
        }
    }

    public function mark_seen(array $items) {
        if (empty($items)) {
            return;
        }
        $seen = $this->load_seen_sources();
        $now  = current_time('mysql');
        foreach ($items as $i) {
            if (!empty($i['fingerprint'])) {
                $seen[$i['fingerprint']] = $now;
            }
        }
        if (count($seen) > 5000) {
            $seen = array_slice($seen, -5000, null, true);
        }
        update_option(self::SEEN_OPTION, $seen, false);
    }

    public function load_seen_sources() {
        $seen = get_option(self::SEEN_OPTION, array());
        return is_array($seen) ? $seen : array();
    }

    public function clear_seen_sources() {
        $count = count($this->load_seen_sources());
        delete_option(self::SEEN_OPTION);
        return $count;
    }

    private function fingerprint($url) {
        return md5(trim(strtolower((string) $url)));
    }

    /**
     * Sources that may be useful as fast signals but must not donate images.
     *
     * @param array  $source Feed source record.
     * @param string $article_url Item permalink.
     * @return bool
     */
    private function is_image_blocked_source( array $source, $article_url = '' ) {
        unset( $article_url );
        return ! empty( $source['image_import_disabled'] );
    }

    /**
     * Extra instruction passed into the model beside each source item.
     *
     * @param array  $source Feed source record.
     * @param string $article_url Item permalink.
     * @return string
     */
    private function source_policy_note( array $source, $article_url = '' ) {
        if ( $this->is_world_of_reel_source( $source, $article_url ) ) {
            return 'World of Reel fast-signal source: attribute when dependent, do not mimic structure or headline logic, and add a distinct Lunara angle.';
        }

        return '';
    }

    /**
     * Identify World of Reel even when the saved feed label/id drifts.
     *
     * @param array  $source Feed source record.
     * @param string $article_url Item permalink.
     * @return bool
     */
    private function is_world_of_reel_source( array $source, $article_url = '' ) {
        $haystack = strtolower(
            (string) ( $source['id'] ?? '' ) . ' ' .
            (string) ( $source['label'] ?? '' ) . ' ' .
            (string) ( $source['url'] ?? '' ) . ' ' .
            (string) $article_url
        );

        if ( false !== strpos( $haystack, 'worldofreel.com' ) || false !== strpos( $haystack, 'world of reel' ) ) {
            return true;
        }

        $host = wp_parse_url( (string) $article_url, PHP_URL_HOST );
        return is_string( $host ) && preg_match( '/(^|\.)worldofreel\.com$/i', $host );
    }

    /**
     * Layered image extraction: RSS-native media first, then the exact
     * article's Open Graph/Twitter image, then RSS thumbnail/body fallbacks.
     */
    private function extract_image_url($item, $article_url, &$origin = '') {
        $origin = '';
        // 1. <enclosure>
        $enclosures = $item->get_enclosures();
        if (!empty($enclosures[0])) {
            $u = $enclosures[0]->get_link();
            if (!empty($u)) {
                $origin = 'rss_enclosure';
                return $this->normalize_image_url($u);
            }
        }

        // 2. <media:content>
        $media = $item->get_item_tags('http://search.yahoo.com/mrss/', 'content');
        if (!empty($media)) {
            $best_media_url   = '';
            $best_media_score = 0;
            foreach ($media as $tag) {
                $a = isset($tag['attribs']['']) ? $tag['attribs'][''] : array();
                if (!empty($a['url']) && (!isset($a['medium']) || strtolower($a['medium']) === 'image')) {
                    $score = 1;
                    if (!empty($a['width']) && !empty($a['height'])) {
                        $score = ((int) $a['width']) * ((int) $a['height']);
                    }
                    if ($score > $best_media_score) {
                        $best_media_score = $score;
                        $best_media_url   = (string) $a['url'];
                    }
                }
            }
            if (!empty($best_media_url)) {
                $origin = 'media_content';
                return $this->normalize_image_url($best_media_url);
            }
        }

        // 3. Scrape the article page for richer candidates.
        $scrape_origin = '';
        $scraped = $this->scrape_article_image($article_url, $scrape_origin);
        if (!empty($scraped) && $this->is_source_story_image($scraped, $scrape_origin)) {
            $origin = $scrape_origin;
            return $scraped;
        }

        // 4. <media:thumbnail>
        $thumb = $item->get_item_tags('http://search.yahoo.com/mrss/', 'thumbnail');
        if (!empty($thumb[0]['attribs']['']['url'])) {
            $origin = 'media_thumbnail';
            return $this->normalize_image_url($thumb[0]['attribs']['']['url']);
        }

        // 5. First <img> in RSS content/description
        $html = (string) $item->get_content();
        if (empty($html)) {
            $html = (string) $item->get_description();
        }
        if (!empty($html) && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            $origin = 'rss_body';
            return $this->normalize_image_url($m[1]);
        }

        return '';
    }

    /**
     * Extract an RSS/media credit when a source feed provides one.
     *
     * @param SimplePie_Item $item RSS item.
     * @return string
     */
    private function extract_image_credit($item) {
        $credit_tags = array(
            array('http://search.yahoo.com/mrss/', 'credit'),
            array('http://search.yahoo.com/mrss/', 'copyright'),
            array('http://purl.org/dc/elements/1.1/', 'creator'),
        );

        foreach ($credit_tags as $tag_spec) {
            $tags = $item->get_item_tags($tag_spec[0], $tag_spec[1]);
            if (empty($tags)) {
                continue;
            }

            foreach ($tags as $tag) {
                if (!empty($tag['data'])) {
                    $credit = $this->normalize_credit_text($tag['data']);
                    if ('' !== $credit) {
                        return $credit;
                    }
                }
            }
        }

        return '';
    }

    private function extract_image_rights($item) {
        $license = '';
        $url = '';
        $tag_sets = array(
            array('http://search.yahoo.com/mrss/', 'license'),
            array('http://purl.org/dc/elements/1.1/', 'rights'),
            array('http://creativecommons.org/ns#', 'license'),
        );
        foreach ($tag_sets as $tag_spec) {
            $tags = $item->get_item_tags($tag_spec[0], $tag_spec[1]);
            foreach (is_array($tags) ? $tags : array() as $tag) {
                if ('' === $license && !empty($tag['data'])) {
                    $license = $this->normalize_credit_text($tag['data']);
                }
                $attributes = isset($tag['attribs']['']) && is_array($tag['attribs']['']) ? $tag['attribs'][''] : array();
                foreach (array('href', 'url', 'resource') as $attribute) {
                    if (empty($attributes[$attribute])) {
                        continue;
                    }
                    $candidate = esc_url_raw((string) $attributes[$attribute], array('https'));
                    if ($this->is_public_https_url($candidate)) {
                        $url = $candidate;
                        break 2;
                    }
                }
                if ('' === $url && !empty($tag['data']) && filter_var(trim((string) $tag['data']), FILTER_VALIDATE_URL)) {
                    $candidate = esc_url_raw(trim((string) $tag['data']), array('https'));
                    if ($this->is_public_https_url($candidate)) {
                        $url = $candidate;
                    }
                }
            }
        }
        return array('license' => $license, 'url' => $url);
    }

    private function has_asset_reuse_rights($image_url, $origin, $credit, array $rights) {
        if (empty($image_url) || !in_array($origin, array('rss_enclosure', 'media_content', 'media_thumbnail'), true)) {
            return false;
        }
        if ('' === trim((string) $credit) || '' === trim((string) ($rights['license'] ?? ''))) {
            return false;
        }
        $rights_url = (string) ($rights['url'] ?? '');
        return $this->is_public_https_url($rights_url);
    }

    /**
     * Confirm that the candidate was exposed by the exact source story or its
     * RSS item rather than discovered through an unrelated image search.
     *
     * @param string $image_url Candidate image URL.
     * @param string $origin    Extraction signal.
     * @return bool
     */
    private function is_source_story_image($image_url, $origin) {
        if (!$this->is_public_https_url($image_url)) {
            return false;
        }

        return in_array((string) $origin, array(
            'rss_enclosure',
            'media_content',
            'article_open_graph',
            'article_twitter',
            'media_thumbnail',
            'rss_body',
        ), true);
    }

    /**
     * Normalize feed-supplied credit text before it is stored on attachments.
     *
     * @param string $credit Raw credit.
     * @return string
     */
    private function normalize_credit_text($credit) {
        $credit = html_entity_decode((string) $credit, ENT_QUOTES, get_bloginfo('charset'));
        $credit = wp_strip_all_tags($credit);
        $credit = preg_replace('/\s+/', ' ', $credit);

        return sanitize_text_field(trim((string) $credit));
    }

    /**
     * Pull og:image / twitter:image from the article page (cached per URL
     * for 6 hours so we don't repeatedly hammer outlets on every cron run).
     */
    public function resolve_source_story_image($url, &$origin = '') {
        $origin = '';
        $image_url = $this->scrape_article_image($url, $origin);
        if (!$this->is_source_story_image($image_url, $origin)) {
            $origin = '';
            return '';
        }
        return $image_url;
    }

    private function scrape_article_image($url, &$origin = '') {
        $origin = '';
        if (empty($url) || !$this->is_public_https_url($url)) {
            return '';
        }
        // Version the cache so the 3.2.3 exact-source rules never inherit a
        // legacy scalar that may have come from a generic body image.
        $cache_key = 'lunara_source_image_v2_' . md5($url);
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            if ('__none__' === $cached) {
                return '';
            }
            if (is_array($cached) && !empty($cached['url'])) {
                $origin = !empty($cached['origin']) ? sanitize_key($cached['origin']) : 'article_cached';
                return $this->normalize_image_url($cached['url']);
            }
            return '';
        }

        $response = wp_safe_remote_get($url, array(
            'timeout'    => 12,
            'redirection' => 2,
            'reject_unsafe_urls' => true,
            'limit_response_size' => self::MAX_ARTICLE_BYTES,
            'user-agent' => 'Mozilla/5.0 (compatible; LunaraDispatch/3.2.3; +https://lunarafilm.com)',
        ));

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($cache_key, '__none__', 6 * HOUR_IN_SECONDS);
            return '';
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html) || strlen($html) >= self::MAX_ARTICLE_BYTES) {
            set_transient($cache_key, '__none__', 6 * HOUR_IN_SECONDS);
            return '';
        }

        // Prefer og:image, then twitter:image, then richest image tag.
        $patterns = array(
            array('/<meta[^>]+property=["\']og:image(?::(?:secure_url|url))?["\'][^>]+content=["\']([^"\']+)["\']/i', 'article_open_graph'),
            array('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image(?::(?:secure_url|url))?["\']/i', 'article_open_graph'),
            array('/<meta[^>]+name=["\']twitter:image(?::src)?["\'][^>]+content=["\']([^"\']+)["\']/i', 'article_twitter'),
            array('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image(?::src)?["\']/i', 'article_twitter'),
        );
        foreach ($patterns as $pattern) {
            if (preg_match($pattern[0], $html, $m)) {
                $found = $this->normalize_image_url(html_entity_decode($m[1], ENT_QUOTES));
                if (!empty($found)) {
                    $origin = $pattern[1];
                    set_transient($cache_key, array('url' => $found, 'origin' => $origin), 6 * HOUR_IN_SECONDS);
                    return $found;
                }
            }
        }

        $srcset = $this->extract_largest_srcset_candidate($html);
        if (!empty($srcset)) {
            $origin = 'article_srcset';
            set_transient($cache_key, array('url' => $srcset, 'origin' => $origin), 6 * HOUR_IN_SECONDS);
            return $srcset;
        }

        $lazy = $this->extract_best_lazy_image_candidate($html);
        if (!empty($lazy)) {
            $origin = 'article_lazy';
            set_transient($cache_key, array('url' => $lazy, 'origin' => $origin), 6 * HOUR_IN_SECONDS);
            return $lazy;
        }

        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            $found = $this->normalize_image_url($m[1]);
            if (!empty($found)) {
                $origin = 'article_body';
                set_transient($cache_key, array('url' => $found, 'origin' => $origin), 6 * HOUR_IN_SECONDS);
                return $found;
            }
        }

        set_transient($cache_key, '__none__', 6 * HOUR_IN_SECONDS);
        return '';
    }

    /**
     * Try to uprez a candidate URL by stripping common crop/resize hints while
     * keeping the image on the same host.
     */
    private function normalize_image_url($url) {
        $url = trim(html_entity_decode((string) $url, ENT_QUOTES));
        if (0 === strpos($url, '//')) {
            $url = 'https:' . $url;
        }
        $url = esc_url_raw($url);
        if (empty($url)) {
            return '';
        }

        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return $url;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if ('http' === $scheme) {
            $parts['scheme'] = 'https';
        } elseif ('https' !== $scheme) {
            return '';
        }

        $path = preg_replace('/-\d{2,5}x\d{2,5}(?=\.(?:jpe?g|png|gif|webp|avif)$)/i', '', $parts['path']);
        $query_args = array();
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query_args);
            foreach (array('w', 'h', 'width', 'height', 'resize', 'fit', 'crop', 'quality', 'dpr') as $key) {
                unset($query_args[$key]);
            }
        }

        $normalized = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $normalized .= ':' . $parts['port'];
        }
        $normalized .= $path;
        if (!empty($query_args)) {
            $normalized .= '?' . http_build_query($query_args);
        }

        if (!empty($parts['fragment'])) {
            $normalized .= '#' . $parts['fragment'];
        }

        return esc_url_raw($normalized);
    }

    /**
     * Find the largest width candidate from srcset or data-srcset attributes.
     */
    private function extract_largest_srcset_candidate($html) {
        if (empty($html)) {
            return '';
        }

        if (!preg_match_all('/(?:srcset|data-srcset)=["\']([^"\']+)["\']/i', $html, $matches)) {
            return '';
        }

        $best_url   = '';
        $best_width = 0;

        foreach ($matches[1] as $srcset) {
            $candidates = array_map('trim', explode(',', (string) $srcset));
            foreach ($candidates as $candidate) {
                if ('' === $candidate) {
                    continue;
                }

                $parts = preg_split('/\s+/', $candidate);
                $candidate_url = $parts[0] ?? '';
                $candidate_w   = 0;
                if (!empty($parts[1]) && preg_match('/(\d+)w/i', $parts[1], $width_match)) {
                    $candidate_w = (int) $width_match[1];
                }

                $candidate_url = $this->normalize_image_url($candidate_url);
                if (!empty($candidate_url) && $candidate_w >= $best_width) {
                    $best_width = $candidate_w;
                    $best_url   = $candidate_url;
                }
            }
        }

        return $best_url;
    }

    /**
     * Find the best lazy-loaded image candidate from common data-* attributes.
     */
    private function extract_best_lazy_image_candidate($html) {
        if (empty($html)) {
            return '';
        }

        $patterns = array(
            '/<img[^>]+data-lazy-src=["\']([^"\']+)["\']/i',
            '/<img[^>]+data-src=["\']([^"\']+)["\']/i',
            '/<img[^>]+data-original=["\']([^"\']+)["\']/i',
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                $candidate = $this->normalize_image_url($matches[1]);
                if (!empty($candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    private function truncate_text($text, $max_chars) {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        $max_chars = max(1, (int) $max_chars);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $max_chars ? rtrim(mb_substr($text, 0, $max_chars - 3)) . '...' : $text;
        }
        return strlen($text) > $max_chars ? rtrim(substr($text, 0, $max_chars - 3)) . '...' : $text;
    }

    private function is_public_https_url($url) {
        $url = esc_url_raw((string) $url, array('https'));
        if ('' === $url || 'https' !== strtolower((string) wp_parse_url($url, PHP_URL_SCHEME))) {
            return false;
        }

        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ('' === $host || 'localhost' === $host || '.local' === substr($host, -6)) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }
        return (bool) wp_http_validate_url($url);
    }
}
