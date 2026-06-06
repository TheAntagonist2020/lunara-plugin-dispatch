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
        $seen    = $this->load_seen_sources();

        $items   = array();
        $skipped = 0;
        $errors  = array();
        $seen_in_this_run = array();

        foreach ($sources as $source) {
            $feed = fetch_feed($source['url']);
            if (is_wp_error($feed)) {
                $errors[$source['label']] = $feed->get_error_message();
                continue;
            }

            $rss_items = $feed->get_items(0, $source['max']);
            if (empty($rss_items)) {
                continue;
            }

            foreach ($rss_items as $rss_item) {
                $url = esc_url_raw($rss_item->get_permalink());
                if (empty($url)) {
                    continue;
                }
                $fp = $this->fingerprint($url);
                if (isset($seen[$fp]) || isset($seen_in_this_run[$fp])) {
                    $skipped++;
                    continue;
                }
                $seen_in_this_run[$fp] = true;

                $image_blocked = $this->is_image_blocked_source( $source, $url );

                $items[] = array(
                    'title'        => wp_strip_all_tags((string) $rss_item->get_title()),
                    'url'          => $url,
                    'description'  => wp_strip_all_tags((string) $rss_item->get_description()),
                    'image_url'    => $image_blocked ? '' : $this->extract_image_url($rss_item, $url),
                    'image_credit' => $image_blocked ? '' : $this->extract_image_credit($rss_item),
                    'source_label' => $source['label'],
                    'source_policy' => $this->source_policy_note( $source, $url ),
                    'image_blocked' => $image_blocked,
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
        return $this->is_world_of_reel_source( $source, $article_url );
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
            return 'World of Reel fast-signal source: attribute when dependent, do not mimic structure or headline logic, add a distinct Lunara angle, and do not use its images.';
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
     * Layered image extraction: RSS-native first, then scrape og:image,
     * then scrape first <img> from the article HTML.
     */
    private function extract_image_url($item, $article_url) {
        // 1. <enclosure>
        $enclosures = $item->get_enclosures();
        if (!empty($enclosures[0])) {
            $u = $enclosures[0]->get_link();
            if (!empty($u)) {
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
                return $this->normalize_image_url($best_media_url);
            }
        }

        // 3. Scrape the article page for richer candidates.
        $scraped = $this->scrape_article_image($article_url);
        if (!empty($scraped)) {
            return $scraped;
        }

        // 4. <media:thumbnail>
        $thumb = $item->get_item_tags('http://search.yahoo.com/mrss/', 'thumbnail');
        if (!empty($thumb[0]['attribs']['']['url'])) {
            return $this->normalize_image_url($thumb[0]['attribs']['']['url']);
        }

        // 5. First <img> in RSS content/description
        $html = (string) $item->get_content();
        if (empty($html)) {
            $html = (string) $item->get_description();
        }
        if (!empty($html) && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
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
            array('http://search.yahoo.com/mrss/', 'title'),
            array('http://search.yahoo.com/mrss/', 'text'),
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
    private function scrape_article_image($url) {
        if (empty($url)) {
            return '';
        }
        $cache_key = 'lunara_og_' . md5($url);
        $cached    = get_transient($cache_key);
        if ($cached !== false) {
            return $cached === '__none__' ? '' : $cached;
        }

        $response = wp_remote_get($url, array(
            'timeout'    => 12,
            'redirection' => 3,
            'user-agent' => 'Mozilla/5.0 (compatible; LunaraDispatch/3.0; +https://lunarafilm.com)',
        ));

        if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($cache_key, '__none__', 6 * HOUR_IN_SECONDS);
            return '';
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) {
            set_transient($cache_key, '__none__', 6 * HOUR_IN_SECONDS);
            return '';
        }

        // Prefer og:image, then twitter:image, then richest image tag.
        $patterns = array(
            '/<meta[^>]+property=["\']og:image(?::secure_url)?["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:image(?::secure_url)?["\']/i',
            '/<meta[^>]+name=["\']twitter:image(?::src)?["\'][^>]+content=["\']([^"\']+)["\']/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']twitter:image(?::src)?["\']/i',
        );
        foreach ($patterns as $rx) {
            if (preg_match($rx, $html, $m)) {
                $found = $this->normalize_image_url(html_entity_decode($m[1], ENT_QUOTES));
                if (!empty($found)) {
                    set_transient($cache_key, $found, 6 * HOUR_IN_SECONDS);
                    return $found;
                }
            }
        }

        $srcset = $this->extract_largest_srcset_candidate($html);
        if (!empty($srcset)) {
            set_transient($cache_key, $srcset, 6 * HOUR_IN_SECONDS);
            return $srcset;
        }

        $lazy = $this->extract_best_lazy_image_candidate($html);
        if (!empty($lazy)) {
            set_transient($cache_key, $lazy, 6 * HOUR_IN_SECONDS);
            return $lazy;
        }

        if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $m)) {
            $found = $this->normalize_image_url($m[1]);
            if (!empty($found)) {
                set_transient($cache_key, $found, 6 * HOUR_IN_SECONDS);
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
        $url = esc_url_raw(html_entity_decode((string) $url, ENT_QUOTES));
        if (empty($url)) {
            return '';
        }

        $parts = wp_parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return $url;
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
}
