<?php
/**
 * Canonical Journal Foundation ingestion bridge for Dispatch drafts.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Dispatch_Journal_Ingest_Bridge {
    public static function build_payload( $title, $body, array $run_context = array(), $section_index = 0 ) {
        $title = trim( wp_strip_all_tags( (string) $title ) );
        $body = wp_kses_post( (string) $body );
        $excerpt = self::plain_excerpt( $body, 260 );
        $seo = self::plain_excerpt( $body, 155 );
        $items = ! empty( $run_context['items'] ) && is_array( $run_context['items'] ) ? $run_context['items'] : array();
        $selected = self::select_source_items( $title, $body, $items, (int) $section_index );
        $canonical_sources = array();
        $source_keys = array();

        foreach ( $selected as $item ) {
            $canonical_sources[] = array(
                'source_headline'     => sanitize_text_field( (string) ( $item['title'] ?? $title ) ),
                'source_publication'  => sanitize_text_field( (string) ( $item['source_label'] ?? '' ) ),
                'source_author'       => sanitize_text_field( (string) ( $item['source_author'] ?? '' ) ),
                'source_url'          => esc_url_raw( (string) ( $item['url'] ?? '' ) ),
                'source_published_at' => sanitize_text_field( (string) ( $item['published_at'] ?? '' ) ),
                'source_reliability'  => sanitize_key( (string) ( $item['source_reliability'] ?? 'unknown' ) ),
                'source_excerpt'      => sanitize_textarea_field( (string) ( $item['description'] ?? '' ) ),
            );
            $source_key = ! empty( $item['fingerprint'] ) ? (string) $item['fingerprint'] : (string) ( $item['url'] ?? '' );
            if ( '' !== $source_key ) {
                $source_keys[] = strtolower( trim( $source_key ) );
            }
        }

        if ( empty( $source_keys ) ) {
            foreach ( $items as $item ) {
                if ( ! is_array( $item ) ) {
                    continue;
                }
                $key = ! empty( $item['fingerprint'] ) ? (string) $item['fingerprint'] : (string) ( $item['url'] ?? '' );
                if ( '' !== $key ) {
                    $source_keys[] = strtolower( trim( $key ) );
                }
            }
        }

        $source_keys[] = 'section:' . max( 0, (int) $section_index );
        sort( $source_keys, SORT_STRING );
        $idempotency_key = 'lunara-dispatch-v1-' . hash( 'sha256', implode( '|', array_values( array_unique( $source_keys ) ) ) );
        $section = ! empty( $run_context['section'] ) ? sanitize_text_field( (string) $run_context['section'] ) : 'Signal';
        $item_type = ! empty( $run_context['item_type'] ) ? sanitize_key( (string) $run_context['item_type'] ) : 'signal';
        $topics = ! empty( $run_context['topics'] ) && is_array( $run_context['topics'] )
            ? array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $run_context['topics'] ) ) ) )
            : array();

        return array(
            'title'           => $title,
            'content'         => $body,
            'excerpt'         => $excerpt,
            'deck'            => $excerpt,
            'seo_description' => $seo,
            'featured_media'  => absint( $run_context['featured_media'] ?? 0 ),
            'idempotency_key' => $idempotency_key,
            'section'         => $section,
            'topics'          => $topics,
            'source_items'    => $canonical_sources,
            'classification'  => array(
                'section'           => $section,
                'topics'            => $topics,
                'item_type'         => $item_type,
                'primary_title'     => sanitize_text_field( (string) ( $run_context['primary_title'] ?? '' ) ),
                'primary_year'      => absint( $run_context['primary_year'] ?? 0 ),
                'people'            => self::sanitize_list( $run_context['people'] ?? array() ),
                'studios_platforms' => self::sanitize_list( $run_context['studios_platforms'] ?? array() ),
            ),
            'acf' => array(
                'journal_deck'                      => $excerpt,
                'journal_seo_description'           => $seo,
                'journal_source_items'              => $canonical_sources,
                'journal_original_dispatch_copy'    => $body,
                'journal_item_type'                 => $item_type,
                'journal_priority'                  => 'normal',
                'journal_status'                    => 'needs_chatgpt_review',
                'journal_validation_status'         => 'unchecked',
                'journal_ready_for_review'          => 0,
                'journal_writer_source'             => 'dispatch',
                'journal_dispatch_actor'            => 'Lunara Dispatch Automation',
                'journal_dispatch_ingested_at'      => current_time( 'mysql' ),
                'journal_dispatch_conversion_notes' => 'Created by Dispatch through the Journal Foundation ingest contract.',
            ),
            'provenance' => array(
                'run_id'          => sanitize_text_field( (string) ( $run_context['run_id'] ?? '' ) ),
                'provider'        => sanitize_key( (string) ( $run_context['provider'] ?? '' ) ),
                'model'           => sanitize_text_field( (string) ( $run_context['model'] ?? '' ) ),
                'config_version'  => sanitize_text_field( (string) ( $run_context['config_version'] ?? '' ) ),
                'prompt_version'  => sanitize_text_field( (string) ( $run_context['prompt_version'] ?? '' ) ),
                'generated_at_gmt'=> current_time( 'mysql', true ),
                'dispatch_version'=> defined( 'LUNARA_DISPATCH_VERSION' ) ? LUNARA_DISPATCH_VERSION : '',
            ),
        );
    }

    public static function ingest_payload( array $payload, $author = 0, array $run_context = array() ) {
        unset( $author, $run_context );
        if ( empty( $payload['idempotency_key'] ) ) {
            return new WP_Error( 'lunara_dispatch_idempotency_required', 'Dispatch requires a stable idempotency key before Journal ingest.' );
        }
        if ( empty( $payload['source_items'] ) || ! is_array( $payload['source_items'] ) ) {
            return new WP_Error( 'lunara_dispatch_source_required', 'Dispatch requires a canonical source item before Journal ingest.' );
        }
        if ( ! class_exists( 'Lunara_Journal_Control_Plane' ) ) {
            return new WP_Error( 'lunara_dispatch_foundation_required', 'Journal Foundation is required before Dispatch can create Journal drafts.' );
        }
        if ( ! class_exists( 'Lunara_Dispatch_Control_Plane_Client' ) ) {
            return new WP_Error( 'lunara_dispatch_bridge_missing', 'Dispatch cannot reach the active Journal Foundation.' );
        }
        return Lunara_Dispatch_Control_Plane_Client::ingest_foundation_draft( $payload );
    }

    private static function select_source_items( $title, $body, array $items, $section_index ) {
        $valid = array_values( array_filter( $items, static function ( $item ) {
            return is_array( $item ) && ! empty( $item['url'] );
        } ) );
        if ( empty( $valid ) ) {
            return array();
        }
        $section_words = self::tokens( $title . ' ' . wp_strip_all_tags( $body ) );
        $best_index = -1;
        $best_score = -1;
        foreach ( $valid as $index => $item ) {
            $item_words = self::tokens( ( $item['title'] ?? '' ) . ' ' . ( $item['description'] ?? '' ) );
            $score = count( array_intersect( $section_words, $item_words ) );
            if ( $score > $best_score ) {
                $best_score = $score;
                $best_index = $index;
            }
        }
        if ( $best_index < 0 || $best_score <= 0 ) {
            $best_index = min( max( 0, (int) $section_index ), count( $valid ) - 1 );
        }
        return array( $valid[ $best_index ] );
    }

    private static function tokens( $text ) {
        $text = strtolower( remove_accents( wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, get_bloginfo( 'charset' ) ) ) ) );
        $parts = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
        $stop = array( 'about', 'after', 'and', 'are', 'because', 'film', 'films', 'from', 'movie', 'movies', 'news', 'that', 'the', 'their', 'this', 'was', 'were', 'when', 'which', 'with' );
        return array_values( array_unique( array_filter( is_array( $parts ) ? $parts : array(), static function ( $word ) use ( $stop ) {
            return strlen( $word ) >= 3 && ! in_array( $word, $stop, true );
        } ) ) );
    }

    private static function plain_excerpt( $html, $max_chars ) {
        $text = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, get_bloginfo( 'charset' ) );
        $text = trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
        $max_chars = max( 1, (int) $max_chars );
        if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
            return mb_strlen( $text ) > $max_chars ? rtrim( mb_substr( $text, 0, $max_chars - 3 ) ) . '...' : $text;
        }
        return strlen( $text ) > $max_chars ? rtrim( substr( $text, 0, $max_chars - 3 ) ) . '...' : $text;
    }

    private static function sanitize_list( $values ) {
        if ( ! is_array( $values ) ) {
            return array();
        }
        return array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $values ) ) ) );
    }
}
