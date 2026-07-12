<?php
/**
 * Dispatch-side client for the authoritative Journal Control Plane.
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Lunara_Dispatch_Control_Plane_Client {
    public static function supports_protocol( $version ) {
        if ( ! is_string( $version ) || ! preg_match( '/^(\d+)\.(\d+)\.(\d+)$/', $version, $matches ) ) {
            return false;
        }
        return 1 === (int) $matches[1];
    }

    public static function foundation_present() {
        return class_exists( 'Lunara_Journal_Control_Plane' ) && method_exists( 'Lunara_Journal_Control_Plane', 'get_dispatch_runtime_config' );
    }

    public static function available() {
        if ( ! self::foundation_present() ) {
            return false;
        }
        $runtime = Lunara_Journal_Control_Plane::get_dispatch_runtime_config();
        return is_array( $runtime ) && self::supports_protocol( $runtime['protocol_version'] ?? '' );
    }

    public static function runtime_config() {
        if ( self::foundation_present() ) {
            $runtime = Lunara_Journal_Control_Plane::get_dispatch_runtime_config();
            if ( is_array( $runtime ) && self::supports_protocol( $runtime['protocol_version'] ?? '' ) ) {
                return $runtime;
            }
            return self::incompatible_runtime_config( is_array( $runtime ) ? ( $runtime['protocol_version'] ?? '' ) : '' );
        }
        return self::legacy_runtime_config();
    }

    private static function incompatible_runtime_config( $version ) {
        return array(
            'protocol_version' => sanitize_text_field( (string) $version ),
            'config_version'   => 'incompatible',
            'enabled'          => false,
            'schedule'         => 'daily',
            'target_post_type' => 'journal',
            'post_status'      => 'draft',
            'provider'         => 'openai',
            'models'           => array(),
            'max_tokens'       => 4096,
            'sources'          => array(),
            'compiled_system_prompt' => '',
            'compiled_user_directive_prompt' => '',
            'protocol_error'   => 'Unsupported Journal Foundation protocol.',
        );
    }

    public static function legacy_runtime_config() {
        $provider = sanitize_key( (string) get_option( 'lunara_dispatch_provider', 'openai' ) );
        if ( ! in_array( $provider, array( 'openai', 'claude', 'gemini', 'grok' ), true ) ) {
            $provider = 'openai';
        }
        return array(
            'protocol_version' => 'legacy',
            'config_version'   => 'legacy',
            'enabled'          => (bool) get_option( 'lunara_dispatch_enabled', 0 ),
            'schedule'         => sanitize_key( (string) get_option( 'lunara_dispatch_schedule', 'daily' ) ),
            'target_post_type' => 'journal',
            'post_status'      => 'draft',
            'provider'         => $provider,
            'models'           => array(
                'openai' => sanitize_text_field( (string) get_option( 'lunara_dispatch_openai_model', 'gpt-4o' ) ),
                'claude' => sanitize_text_field( (string) get_option( 'lunara_dispatch_claude_model', 'claude-opus-4-5' ) ),
                'gemini' => sanitize_text_field( (string) get_option( 'lunara_dispatch_gemini_model', 'gemini-2.5-pro' ) ),
                'grok'   => sanitize_text_field( (string) get_option( 'lunara_dispatch_grok_model', 'grok-4' ) ),
            ),
            'max_tokens' => max( 1024, min( 16000, (int) get_option( 'lunara_dispatch_max_tokens', 4096 ) ) ),
            'sources'    => class_exists( 'Lunara_Dispatch_Sources' ) ? Lunara_Dispatch_Sources::all() : array(),
            'compiled_system_prompt' => class_exists( 'Lunara_Dispatch_Prompts' ) ? Lunara_Dispatch_Prompts::legacy_system_prompt() : '',
            'compiled_user_directive_prompt' => class_exists( 'Lunara_Dispatch_Prompts' ) ? Lunara_Dispatch_Prompts::legacy_user_directive_prompt() : '',
        );
    }

    public static function provider() {
        $runtime = self::runtime_config();
        $provider = isset( $runtime['provider'] ) ? sanitize_key( (string) $runtime['provider'] ) : 'openai';
        return in_array( $provider, array( 'openai', 'claude', 'gemini', 'grok' ), true ) ? $provider : 'openai';
    }

    public static function model_for_provider( $provider, $default ) {
        $runtime = self::runtime_config();
        $provider = sanitize_key( (string) $provider );
        if ( isset( $runtime['models'][ $provider ] ) && is_scalar( $runtime['models'][ $provider ] ) && '' !== trim( (string) $runtime['models'][ $provider ] ) ) {
            return sanitize_text_field( (string) $runtime['models'][ $provider ] );
        }
        return sanitize_text_field( (string) $default );
    }

    public static function max_tokens() {
        $runtime = self::runtime_config();
        return max( 1024, min( 16000, (int) ( $runtime['max_tokens'] ?? 4096 ) ) );
    }

    public static function schedule() {
        $runtime = self::runtime_config();
        $schedule = sanitize_key( (string) ( $runtime['schedule'] ?? 'daily' ) );
        return in_array( $schedule, array( 'daily', 'twice_daily', 'every_4_hours', 'every_2_hours' ), true ) ? $schedule : 'daily';
    }

    public static function enabled() {
        $runtime = self::runtime_config();
        return ! empty( $runtime['enabled'] );
    }

    public static function target_post_type() {
        return 'journal';
    }

    public static function post_status() {
        return 'draft';
    }

    public static function system_prompt() {
        $runtime = self::runtime_config();
        if ( ! empty( $runtime['compiled_system_prompt'] ) ) {
            return (string) $runtime['compiled_system_prompt'];
        }
        return class_exists( 'Lunara_Dispatch_Prompts' ) ? Lunara_Dispatch_Prompts::legacy_system_prompt() : '';
    }

    public static function user_directive_prompt() {
        $runtime = self::runtime_config();
        if ( ! empty( $runtime['compiled_user_directive_prompt'] ) ) {
            return (string) $runtime['compiled_user_directive_prompt'];
        }
        return class_exists( 'Lunara_Dispatch_Prompts' ) ? Lunara_Dispatch_Prompts::legacy_user_directive_prompt() : '';
    }

    /**
     * Hand a complete draft payload to Journal Foundation's in-process ingest
     * contract. Foundation presence is authoritative: an unavailable handler
     * or incompatible protocol is an error, never a cue for a partial insert.
     *
     * @param array $payload Canonical Journal draft payload.
     * @return array|WP_Error Normalized result with post_id and created.
     */
    public static function ingest_foundation_draft( array $payload ) {
        if ( ! self::foundation_present() ) {
            return new WP_Error( 'lunara_dispatch_foundation_absent', 'Journal Foundation is not active.' );
        }
        if ( ! self::available() ) {
            $runtime = Lunara_Journal_Control_Plane::get_dispatch_runtime_config();
            $version = is_array( $runtime ) ? (string) ( $runtime['protocol_version'] ?? '' ) : '';
            return new WP_Error( 'lunara_dispatch_protocol_mismatch', 'Journal Foundation protocol is not supported: ' . $version );
        }

        $result = apply_filters( 'lunara_journal_foundation_ingest', null, $payload );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        if ( null === $result ) {
            return new WP_Error( 'lunara_dispatch_ingest_unhandled', 'Journal Foundation did not handle the Dispatch ingest payload.' );
        }

        $required = array( 'post_id', 'created', 'post_status', 'idempotency_key' );
        if ( ! is_array( $result ) || array_diff( $required, array_keys( $result ) ) || ! is_bool( $result['created'] ) ) {
            return new WP_Error( 'lunara_dispatch_ingest_invalid', 'Journal Foundation returned an invalid ingest response.' );
        }

        $post_id = (int) $result['post_id'];
        $idempotency_key = sanitize_text_field( (string) $result['idempotency_key'] );
        if (
            $post_id <= 0 ||
            'journal' !== get_post_type( $post_id ) ||
            'draft' !== get_post_status( $post_id ) ||
            'draft' !== (string) $result['post_status'] ||
            $idempotency_key !== sanitize_text_field( (string) ( $payload['idempotency_key'] ?? '' ) )
        ) {
            return new WP_Error( 'lunara_dispatch_ingest_unsafe', 'Journal Foundation ingest did not return a verified Journal draft.' );
        }

        return array(
            'post_id'         => $post_id,
            'created'         => $result['created'],
            'post_status'     => 'draft',
            'idempotency_key' => $idempotency_key,
        );
    }
}
