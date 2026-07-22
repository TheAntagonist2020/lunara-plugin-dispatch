<?php
/**
 * Lunara_Dispatch_Sources
 *
 * Multi-source RSS configuration manager. Stores feeds in a single
 * option as a flat array of associative records:
 *   [
 *     [
 *       'id'      => 'world-of-reel',  // slug, unique
 *       'label'   => 'World of Reel',
 *       'url'     => 'https://www.worldofreel.com/blog?format=rss',
 *       'enabled' => true,
 *       'max'     => 10,               // max items per run from this feed
 *       'image_import_disabled' => false, // explicit source-image opt-out
 *     ],
 *     ...
 *   ]
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lunara_Dispatch_Sources {

    const OPTION = 'lunara_dispatch_sources';

    /**
     * Default feed list installed on activation.
     */
    public static function defaults() {
        return array(
            array('id' => 'world-of-reel', 'label' => 'World of Reel', 'url' => 'https://www.worldofreel.com/blog?format=rss', 'enabled' => true, 'max' => 10, 'priority' => 4, 'image_reuse_allowed' => false),
            array('id' => 'entertainment-weekly-movies', 'label' => 'Entertainment Weekly Movies', 'url' => 'https://feeds-api.dotdashmeredith.com/v1/rss/google/defadcea-6edf-45ce-88f1-eabd71a81843', 'enabled' => true, 'max' => 10, 'priority' => 5, 'image_reuse_allowed' => false),
            array('id' => 'deadline', 'label' => 'Deadline', 'url' => 'https://deadline.com/v/film/feed/', 'enabled' => true, 'max' => 10, 'priority' => 7, 'image_reuse_allowed' => false),
            array('id' => 'variety', 'label' => 'Variety', 'url' => 'https://variety.com/v/film/feed/', 'enabled' => true, 'max' => 10, 'priority' => 7, 'image_reuse_allowed' => false),
            array('id' => 'indiewire', 'label' => 'IndieWire', 'url' => 'https://www.indiewire.com/feed/', 'enabled' => true, 'max' => 10, 'priority' => 6, 'image_reuse_allowed' => false),
            array('id' => 'film-stage', 'label' => 'The Film Stage', 'url' => 'https://thefilmstage.com/feed/', 'enabled' => true, 'max' => 10, 'priority' => 6, 'image_reuse_allowed' => false),
            array('id' => 'the-playlist', 'label' => 'The Playlist', 'url' => 'https://theplaylist.net/feed/', 'enabled' => true, 'max' => 10, 'priority' => 5, 'image_reuse_allowed' => false),
            array('id' => 'screen-daily', 'label' => 'Screen Daily', 'url' => 'https://www.screendaily.com/XmlServers/navsectionRSS.aspx?navsectioncode=4523', 'enabled' => true, 'max' => 10, 'priority' => 5, 'image_reuse_allowed' => false),
        );
    }

    public static function install_defaults_if_empty() {
        $existing = get_option(self::OPTION, null);
        if (!is_array($existing) || empty($existing)) {
            update_option(self::OPTION, self::defaults(), false);
        }
    }

    /**
     * Get all sources (always returns array of normalized records).
     */
    public static function all() {
        if ( class_exists( 'Lunara_Dispatch_Control_Plane_Client' ) && method_exists( 'Lunara_Dispatch_Control_Plane_Client', 'available' ) && Lunara_Dispatch_Control_Plane_Client::available() ) {
            $runtime = Lunara_Dispatch_Control_Plane_Client::runtime_config();
            if ( ! empty( $runtime['sources'] ) && is_array( $runtime['sources'] ) ) {
                return self::normalize_runtime_sources( $runtime['sources'] );
            }
        }
        $raw = get_option(self::OPTION, array());
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $row) {
            if (!is_array($row) || empty($row['url'])) {
                continue;
            }
            $out[] = array(
                'id'      => isset($row['id'])      ? sanitize_key($row['id'])              : sanitize_key(wp_generate_password(8, false)),
                'label'   => isset($row['label'])   ? sanitize_text_field($row['label'])    : 'Unnamed Feed',
                'url'     => esc_url_raw($row['url']),
                'enabled' => !empty($row['enabled']),
                'max'     => isset($row['max']) ? max(1, min(50, (int) $row['max'])) : 10,
                'priority' => isset($row['priority']) ? max(1, min(10, (int) $row['priority'])) : 5,
                'image_reuse_allowed' => !empty($row['image_reuse_allowed']),
                'image_import_disabled' => !empty($row['image_import_disabled']),
            );
        }
        return $out;
    }

    /**
     * Normalize the authoritative Control Plane source list into the compact
     * records consumed by the existing feed fetcher and admin diagnostics.
     */
    public static function normalize_runtime_sources( array $sources ) {
        $out = array();
        foreach ( $sources as $row ) {
            if ( ! is_array( $row ) || empty( $row['url'] ) ) {
                continue;
            }

            $url = esc_url_raw( $row['url'] );
            if ( empty( $url ) ) {
                continue;
            }

            $label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : 'Unnamed Feed';
            $id = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
            if ( '' === $id ) {
                $id = sanitize_key( $label );
            }
            if ( '' === $id ) {
                $id = sanitize_key( wp_generate_password( 8, false ) );
            }

            $out[] = array(
                'id'       => $id,
                'label'    => '' !== $label ? $label : 'Unnamed Feed',
                'url'      => $url,
                'enabled'  => ! empty( $row['enabled'] ),
                'max'      => isset( $row['max'] ) ? max( 1, min( 50, (int) $row['max'] ) ) : 10,
                'priority' => isset( $row['priority'] ) ? max( 1, min( 10, (int) $row['priority'] ) ) : 5,
                'image_reuse_allowed' => ! empty( $row['image_reuse_allowed'] ),
                'image_import_disabled' => ! empty( $row['image_import_disabled'] ),
            );
        }
        return $out;
    }

    public static function enabled() {
        return array_values(array_filter(self::all(), function ($s) {
            return !empty($s['enabled']);
        }));
    }

    /**
     * Replace the entire source list. Caller is responsible for sanitization.
     */
    public static function save_all(array $sources) {
        $clean = array();
        $seen_ids = array();
        foreach ($sources as $row) {
            if (empty($row['url'])) {
                continue;
            }
            $url = esc_url_raw($row['url']);
            if (empty($url)) {
                continue;
            }
            $id = !empty($row['id']) ? sanitize_key($row['id']) : sanitize_title($row['label'] ?? $url);
            // Ensure id uniqueness
            $base = $id ?: 'feed';
            $i = 2;
            while (isset($seen_ids[$id])) {
                $id = $base . '-' . $i;
                $i++;
            }
            $seen_ids[$id] = true;
            $clean[] = array(
                'id'      => $id,
                'label'   => isset($row['label']) ? sanitize_text_field($row['label']) : $id,
                'url'     => $url,
                'enabled' => !empty($row['enabled']),
                'max'     => isset($row['max']) ? max(1, min(50, (int) $row['max'])) : 10,
                'priority' => isset($row['priority']) ? max(1, min(10, (int) $row['priority'])) : 5,
                'image_reuse_allowed' => !empty($row['image_reuse_allowed']),
                'image_import_disabled' => !empty($row['image_import_disabled']),
            );
        }
        update_option(self::OPTION, $clean, false);
        return $clean;
    }
}
