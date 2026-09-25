<?php
/**
 * Lunara_Dispatch_Pitches
 *
 * Pitch mode: when it is on, a Dispatch run stops after collecting source
 * stories and files them here as pitches instead of writing drafts. Dalton
 * picks the ones worth writing (from the LUNARA Hub, over REST), optionally
 * adds his angle, and only those are written into Journal drafts on the next
 * worker run. Passed pitches are never written. Nothing here publishes.
 *
 * Storage is a single non-autoloaded option holding a bounded list of records:
 *   [
 *     'id'         => 'p_1a2b3c4d5e6f7a8b',  // stable per source story
 *     'status'     => 'pending' | 'approved' | 'written' | 'passed' | 'skipped',
 *     'item'       => array(...),            // the Dispatch source item
 *     'angle'      => '',                    // Dalton's note for the writer
 *     'created_at' => '2026-09-25 12:00:00', // GMT
 *     'decided_at' => '',
 *     'post_ids'   => array(),
 *     'note'       => '',                    // why Dispatch skipped, etc.
 *   ]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Lunara_Dispatch_Pitches {

    const OPTION         = 'lunara_dispatch_pitches';
    const MODE_OPTION    = 'lunara_dispatch_pitch_mode';
    const LIMIT          = 150;
    const MAX_PER_RUN    = 12;
    const ANGLE_LIMIT    = 600;
    const REST_NAMESPACE = 'lunara/v1';

    public static function bootstrap() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    public static function enabled() {
        return (bool) get_option( self::MODE_OPTION, 0 );
    }

    public static function set_enabled( $enabled ) {
        update_option( self::MODE_OPTION, $enabled ? 1 : 0, false );
    }

    public static function all() {
        $raw = get_option( self::OPTION, array() );
        return is_array( $raw ) ? array_values( array_filter( $raw, 'is_array' ) ) : array();
    }

    private static function save( array $pitches ) {
        // Keep every open pitch; trim the oldest decided ones past the limit.
        if ( count( $pitches ) > self::LIMIT ) {
            $open    = array_values( array_filter( $pitches, array( __CLASS__, 'is_open' ) ) );
            $decided = array_values( array_filter( $pitches, function ( $p ) { return ! self::is_open( $p ); } ) );
            $room    = max( 0, self::LIMIT - count( $open ) );
            $pitches = array_merge( $open, array_slice( $decided, -$room ) );
        }
        update_option( self::OPTION, array_values( $pitches ), false );
    }

    private static function is_open( $pitch ) {
        return in_array( $pitch['status'] ?? '', array( 'pending', 'approved' ), true );
    }

    public static function pitch_id( array $item ) {
        $key = ! empty( $item['fingerprint'] ) ? (string) $item['fingerprint'] : strtolower( trim( (string) ( $item['url'] ?? '' ) ) );
        return 'p_' . substr( hash( 'sha256', $key ), 0, 16 );
    }

    /**
     * File a batch of source items as pending pitches.
     *
     * @return int Number of new pitches.
     */
    public static function add_items( array $items, $now = '' ) {
        $pitches = self::all();
        $known   = array();
        foreach ( $pitches as $p ) {
            $known[ (string) $p['id'] ] = true;
        }
        $now   = '' !== $now ? $now : gmdate( 'Y-m-d H:i:s' );
        $added = 0;
        foreach ( array_slice( $items, 0, self::MAX_PER_RUN ) as $item ) {
            if ( ! is_array( $item ) || empty( $item['url'] ) || empty( $item['title'] ) ) {
                continue;
            }
            $id = self::pitch_id( $item );
            if ( isset( $known[ $id ] ) ) {
                continue;
            }
            $known[ $id ] = true;
            $pitches[]    = array(
                'id'         => $id,
                'status'     => 'pending',
                'item'       => $item,
                'angle'      => '',
                'created_at' => $now,
                'decided_at' => '',
                'post_ids'   => array(),
                'note'       => '',
            );
            $added++;
        }
        if ( $added > 0 ) {
            self::save( $pitches );
        }
        return $added;
    }

    /**
     * Record Dalton's calls. Write → approved (with optional angle), pass → passed.
     * Only open pitches can change.
     *
     * @return array { approved:int, passed:int }
     */
    public static function decide( array $write_ids, array $pass_ids, array $angles = array() ) {
        $write   = array_fill_keys( array_map( 'strval', $write_ids ), true );
        $pass    = array_fill_keys( array_map( 'strval', $pass_ids ), true );
        $pitches = self::all();
        $counts  = array( 'approved' => 0, 'passed' => 0 );
        $now     = gmdate( 'Y-m-d H:i:s' );
        $passed_signals = array();
        foreach ( $pitches as &$p ) {
            $id = (string) $p['id'];
            if ( ! self::is_open( $p ) ) {
                continue;
            }
            if ( isset( $write[ $id ] ) ) {
                $p['status']     = 'approved';
                $p['decided_at'] = $now;
                if ( isset( $angles[ $id ] ) ) {
                    $p['angle'] = self::clean_angle( $angles[ $id ] );
                }
                $counts['approved']++;
            } elseif ( isset( $pass[ $id ] ) && 'pending' === $p['status'] ) {
                $p['status']     = 'passed';
                $p['decided_at'] = $now;
                $counts['passed']++;
                if ( ! empty( $p['item']['automation_signal_id'] ) ) {
                    $passed_signals[] = absint( $p['item']['automation_signal_id'] );
                }
            }
        }
        unset( $p );
        self::save( $pitches );

        // A passed Source Radar pitch closes its signal in the Automation Inbox.
        if ( ! empty( $passed_signals ) && class_exists( 'Lunara_Journal_Automation' ) && method_exists( 'Lunara_Journal_Automation', 'record_dispatch_source_outcome' ) ) {
            Lunara_Journal_Automation::record_dispatch_source_outcome( $passed_signals, 'editorial_skip' );
        }
        return $counts;
    }

    private static function clean_angle( $angle ) {
        $angle = sanitize_textarea_field( (string) $angle );
        return function_exists( 'mb_substr' ) ? mb_substr( $angle, 0, self::ANGLE_LIMIT ) : substr( $angle, 0, self::ANGLE_LIMIT );
    }

    public static function count_status( $status ) {
        $n = 0;
        foreach ( self::all() as $p ) {
            if ( $status === ( $p['status'] ?? '' ) ) {
                $n++;
            }
        }
        return $n;
    }

    public static function has_approved() {
        foreach ( self::all() as $p ) {
            if ( 'approved' === ( $p['status'] ?? '' ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The next approved pitches as Dispatch source items, oldest decision first.
     * Each item carries its pitch id and Dalton's angle for the writer.
     */
    public static function approved_items( $limit ) {
        $approved = array_values( array_filter( self::all(), function ( $p ) {
            return 'approved' === ( $p['status'] ?? '' );
        } ) );
        usort( $approved, function ( $a, $b ) {
            return strcmp( (string) $a['decided_at'], (string) $b['decided_at'] );
        } );
        $items = array();
        foreach ( array_slice( $approved, 0, max( 1, (int) $limit ) ) as $p ) {
            $item                 = is_array( $p['item'] ) ? $p['item'] : array();
            $item['pitch_id']     = (string) $p['id'];
            $item['editor_angle'] = (string) ( $p['angle'] ?? '' );
            $items[]              = $item;
        }
        return $items;
    }

    /** Close out the pitches behind a finished run: written, or skipped with the reason. */
    public static function settle( array $items, $status, array $post_ids = array(), $note = '' ) {
        $ids = array();
        foreach ( $items as $item ) {
            if ( ! empty( $item['pitch_id'] ) ) {
                $ids[ (string) $item['pitch_id'] ] = true;
            }
        }
        if ( empty( $ids ) ) {
            return;
        }
        $pitches = self::all();
        foreach ( $pitches as &$p ) {
            if ( isset( $ids[ (string) $p['id'] ] ) && 'approved' === ( $p['status'] ?? '' ) ) {
                $p['status']   = in_array( $status, array( 'written', 'skipped' ), true ) ? $status : 'skipped';
                $p['post_ids'] = array_values( array_map( 'absint', $post_ids ) );
                $p['note']     = sanitize_text_field( (string) $note );
            }
        }
        unset( $p );
        self::save( $pitches );
    }

    /** Public shape for the hub: the item's display fields, never the raw internals. */
    public static function present( array $p ) {
        $item = is_array( $p['item'] ?? null ) ? $p['item'] : array();
        return array(
            'id'           => (string) $p['id'],
            'status'       => (string) $p['status'],
            'title'        => (string) ( $item['title'] ?? '' ),
            'url'          => (string) ( $item['url'] ?? '' ),
            'source'       => (string) ( $item['source_label'] ?? '' ),
            'summary'      => (string) ( $item['description'] ?? '' ),
            'image_url'    => empty( $item['image_blocked'] ) ? (string) ( $item['image_url'] ?? '' ) : '',
            'published_at' => (string) ( $item['published_at'] ?? '' ),
            'angle'        => (string) ( $p['angle'] ?? '' ),
            'created_at'   => (string) $p['created_at'],
            'decided_at'   => (string) $p['decided_at'],
            'post_ids'     => array_values( (array) ( $p['post_ids'] ?? array() ) ),
            'note'         => (string) ( $p['note'] ?? '' ),
        );
    }

    // --- REST (the hub signs in with Dalton's Application Password) ----------

    public static function register_rest_routes() {
        register_rest_route( self::REST_NAMESPACE, '/dispatch/pitches', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_list' ),
            'permission_callback' => array( __CLASS__, 'permissions_check' ),
        ) );
        register_rest_route( self::REST_NAMESPACE, '/dispatch/pitches/decide', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_decide' ),
            'permission_callback' => array( __CLASS__, 'permissions_check' ),
        ) );
        register_rest_route( self::REST_NAMESPACE, '/dispatch/pitches/mode', array(
            'methods'             => 'GET, POST',
            'callback'            => array( __CLASS__, 'rest_mode' ),
            'permission_callback' => array( __CLASS__, 'permissions_check' ),
        ) );
    }

    public static function permissions_check() {
        return current_user_can( 'edit_others_posts' );
    }

    public static function rest_list() {
        $pitches = array_reverse( self::all() ); // newest first
        return rest_ensure_response( array(
            'pitch_mode' => self::enabled(),
            'pitches'    => array_map( array( __CLASS__, 'present' ), $pitches ),
            'counts'     => array_count_values( array_map( function ( $p ) { return (string) $p['status']; }, $pitches ) ),
        ) );
    }

    public static function rest_decide( $request ) {
        $write  = (array) $request->get_param( 'write' );
        $pass   = (array) $request->get_param( 'pass' );
        $angles = (array) $request->get_param( 'angles' );
        $counts = self::decide( $write, $pass, $angles );

        $queued = null;
        if ( $counts['approved'] > 0 && class_exists( 'Lunara_Dispatch_Plugin' ) ) {
            $queued = Lunara_Dispatch_Plugin::instance()->queue_manual_run();
            if ( is_wp_error( $queued ) ) {
                $queued = array( 'queued' => false, 'message' => $queued->get_error_message() );
            }
        }
        return rest_ensure_response( array(
            'success'  => true,
            'approved' => $counts['approved'],
            'passed'   => $counts['passed'],
            'writer'   => $queued,
        ) );
    }

    public static function rest_mode( $request ) {
        if ( 'POST' === $request->get_method() ) {
            self::set_enabled( (bool) $request->get_param( 'enabled' ) );
        }
        return rest_ensure_response( array( 'pitch_mode' => self::enabled() ) );
    }
}
