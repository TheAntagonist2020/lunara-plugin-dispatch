<?php
/**
 * Runtime contract checks for Dispatch -> Journal Foundation integration.
 * Run: php tests/dispatch-integration-runtime.php
 */

$root = dirname( __DIR__ );
$failures = array();

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
}
if ( ! defined( 'LUNARA_DISPATCH_VERSION' ) ) {
    define( 'LUNARA_DISPATCH_VERSION', '3.2.3' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}

class WP_Error {
    private $code;
    private $message;

    public function __construct( $code = '', $message = '' ) {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }
}

function is_wp_error( $value ) {
    return $value instanceof WP_Error;
}

function sanitize_text_field( $value ) {
    return trim( strip_tags( (string) $value ) );
}

function sanitize_textarea_field( $value ) {
    return sanitize_text_field( $value );
}

function sanitize_key( $value ) {
    return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}

function esc_url_raw( $value ) {
    return filter_var( (string) $value, FILTER_SANITIZE_URL );
}

function absint( $value ) {
    return abs( (int) $value );
}

function wp_strip_all_tags( $value ) {
    return strip_tags( (string) $value );
}

function wp_kses_post( $value ) {
    return (string) $value;
}

function current_time( $type, $gmt = false ) {
    unset( $gmt );
    return 'mysql' === $type ? '2026-07-12 12:00:00' : 0;
}

function get_bloginfo( $field ) {
    return 'charset' === $field ? 'UTF-8' : '';
}

function remove_accents( $value ) {
    return (string) $value;
}

function wp_parse_url( $url, $component = -1 ) {
    return parse_url( (string) $url, $component );
}

function untrailingslashit( $value ) {
    return rtrim( (string) $value, "/\\" );
}

function wp_http_validate_url( $url ) {
    return false !== filter_var( (string) $url, FILTER_VALIDATE_URL );
}

$dispatch_test_transients = array();
function get_transient( $key ) {
    global $dispatch_test_transients;
    return array_key_exists( $key, $dispatch_test_transients ) ? $dispatch_test_transients[ $key ] : false;
}

function set_transient( $key, $value, $expiration ) {
    global $dispatch_test_transients;
    unset( $expiration );
    $dispatch_test_transients[ $key ] = $value;
    return true;
}

function wp_safe_remote_get( $url, $args = array() ) {
    unset( $args );
    if ( 'https://example.com/source-story' === $url ) {
        return array(
            'response' => array( 'code' => 200 ),
            'body' => '<html><head><meta property="og:image" content="http://cdn.example.com/source-lead.jpg?w=1200&amp;h=675"></head></html>',
        );
    }
    return new WP_Error( 'unexpected_url', 'Unexpected test URL.' );
}

function wp_remote_retrieve_response_code( $response ) {
    return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( $response ) {
    return (string) ( $response['body'] ?? '' );
}

function get_option( $key, $default = false ) {
    unset( $key );
    return $default;
}

function update_post_meta() {
    return true;
}

function get_post_type( $post_id ) {
    return 91 === (int) $post_id ? 'journal' : 'post';
}

function get_post_status( $post_id ) {
    return 91 === (int) $post_id ? 'draft' : 'publish';
}

$dispatch_filter_handler = null;
$dispatch_filter_calls = array();
$http_args_callback = null;
function apply_filters( $hook, $value, ...$args ) {
    global $dispatch_filter_handler, $dispatch_filter_calls;
    $dispatch_filter_calls[] = array( $hook, $value, $args );
    if ( is_callable( $dispatch_filter_handler ) ) {
        return $dispatch_filter_handler( $value, ...$args );
    }
    return $value;
}

function add_filter( $hook, $callback ) {
    global $http_args_callback;
    if ( 'http_request_args' === $hook ) {
        $http_args_callback = $callback;
    }
    return true;
}

function remove_filter( $hook, $callback ) {
    global $http_args_callback;
    if ( 'http_request_args' === $hook && $http_args_callback === $callback ) {
        $http_args_callback = null;
    }
    return true;
}

function fetch_feed( $url ) {
    global $http_args_callback;
    return is_callable( $http_args_callback ) ? $http_args_callback( array(), $url ) : array();
}

class Lunara_Journal_Control_Plane {
    public static $runtime = array(
        'protocol_version' => '1.2.1',
        'enabled' => true,
    );

    public static function get_dispatch_runtime_config() {
        return self::$runtime;
    }
}

function dispatch_runtime_assert( $condition, $message, &$failures ) {
    if ( ! $condition ) {
        $failures[] = $message;
    }
}

require_once $root . DIRECTORY_SEPARATOR . 'includes/class-control-plane-client.php';
require_once $root . DIRECTORY_SEPARATOR . 'includes/class-journal-ingest-bridge.php';
require_once $root . DIRECTORY_SEPARATOR . 'includes/class-post-builder.php';
require_once $root . DIRECTORY_SEPARATOR . 'includes/class-image-handler.php';
require_once $root . DIRECTORY_SEPARATOR . 'includes/class-feed-fetcher.php';

$context = array(
    'run_id' => 'run-first',
    'provider' => 'openai',
    'model' => 'gpt-test',
    'config_version' => 'config-9',
    'prompt_version' => 'prompt-4',
    'section' => 'Industry',
    'topics' => array( 'Distribution', 'Festival' ),
    'item_type' => 'dispatch',
    'items' => array(
        array(
            'title' => 'A Film Finds Distribution',
            'description' => 'Festival acquisition and distribution news.',
            'url' => 'https://example.com/story',
            'fingerprint' => 'source-fingerprint-1',
            'source_label' => 'Example Trade',
        ),
    ),
);
$payload = Lunara_Dispatch_Journal_Ingest_Bridge::build_payload(
    'A Film Finds Distribution',
    '<p>A complete generated Journal draft.</p>',
    $context,
    0
);
$retry_context = $context;
$retry_context['run_id'] = 'run-retry';
$retry_payload = Lunara_Dispatch_Journal_Ingest_Bridge::build_payload(
    'A Film Finds Distribution',
    '<p>A complete generated Journal draft.</p>',
    $retry_context,
    0
);

dispatch_runtime_assert( ! empty( $payload['idempotency_key'] ), 'payload idempotency key is missing', $failures );
dispatch_runtime_assert( $payload['idempotency_key'] === $retry_payload['idempotency_key'], 'retry changed the source-stable idempotency key', $failures );
$next_section_payload = Lunara_Dispatch_Journal_Ingest_Bridge::build_payload(
    'A Second Angle on Distribution',
    '<p>A distinct generated section using the same canonical source.</p>',
    $context,
    1
);
dispatch_runtime_assert( $payload['idempotency_key'] !== $next_section_payload['idempotency_key'], 'different generated sections using the same source collapsed onto one idempotency key', $failures );
dispatch_runtime_assert( ! isset( $payload['status'] ) && ! isset( $payload['post_status'] ), 'payload attempts to choose a post status', $failures );
dispatch_runtime_assert( isset( $payload['featured_media'] ) && 0 === $payload['featured_media'], 'payload featured_media default is not explicit', $failures );
dispatch_runtime_assert( 'Industry' === $payload['classification']['section'], 'classification section was not preserved', $failures );
dispatch_runtime_assert( 'openai' === $payload['provenance']['provider'], 'provenance provider was not preserved', $failures );
dispatch_runtime_assert( 1 === count( $payload['source_items'] ), 'payload did not select one canonical source item', $failures );
$untraceable_payload = $payload;
$untraceable_payload['source_items'] = array();
$untraceable = Lunara_Dispatch_Journal_Ingest_Bridge::ingest_payload( $untraceable_payload );
dispatch_runtime_assert( is_wp_error( $untraceable ) && 'lunara_dispatch_source_required' === $untraceable->get_error_code(), 'Journal ingest accepted an untraceable draft without a canonical source', $failures );

$fallback_context = $context;
$fallback_context['items'][] = array(
    'title' => 'Unrelated Second Source',
    'description' => 'A different subject with no matching words.',
    'url' => 'https://example.com/second-story',
    'fingerprint' => 'source-fingerprint-2',
    'source_label' => 'Second Trade',
);
$fallback_first = Lunara_Dispatch_Journal_Ingest_Bridge::build_payload( 'Nebula Quartz', '<p>Obsidian cadence.</p>', $fallback_context, 0 );
$fallback_second = Lunara_Dispatch_Journal_Ingest_Bridge::build_payload( 'Velvet Cobalt', '<p>Saffron geometry.</p>', $fallback_context, 1 );
dispatch_runtime_assert( $fallback_first['idempotency_key'] !== $fallback_second['idempotency_key'], 'zero-overlap source matching collapsed separate sections onto one draft', $failures );

Lunara_Journal_Control_Plane::$runtime = array( 'protocol_version' => '2.0.0', 'enabled' => true );
$incompatible = Lunara_Dispatch_Control_Plane_Client::runtime_config();
dispatch_runtime_assert( empty( $incompatible['enabled'] ) && ! empty( $incompatible['protocol_error'] ), 'incompatible protocol did not fail closed', $failures );

Lunara_Journal_Control_Plane::$runtime = array( 'protocol_version' => '1.2.1', 'enabled' => true );
$dispatch_filter_handler = static function ( $initial, $incoming ) {
    if ( null !== $initial ) {
        return new WP_Error( 'bad_initial', 'Ingest initial filter value was not null.' );
    }
    return array(
        'post_id' => 91,
        'created' => true,
        'post_status' => 'draft',
        'idempotency_key' => $incoming['idempotency_key'],
    );
};
$result = Lunara_Dispatch_Control_Plane_Client::ingest_foundation_draft( $payload );
dispatch_runtime_assert( ! is_wp_error( $result ) && 91 === $result['post_id'], 'valid Foundation response was rejected', $failures );
dispatch_runtime_assert( 'draft' === $result['post_status'], 'verified Foundation result lost draft status', $failures );
dispatch_runtime_assert( 'lunara_journal_foundation_ingest' === $dispatch_filter_calls[0][0], 'wrong Foundation ingest filter was called', $failures );

$dispatch_filter_handler = static function () {
    return null;
};
$unhandled = Lunara_Dispatch_Control_Plane_Client::ingest_foundation_draft( $payload );
dispatch_runtime_assert( is_wp_error( $unhandled ) && 'lunara_dispatch_ingest_unhandled' === $unhandled->get_error_code(), 'missing Foundation handler did not stop ingestion', $failures );

$dispatch_filter_handler = static function () use ( $payload ) {
    return array(
        'post_id' => 91,
        'created' => true,
        'post_status' => 'draft',
        'idempotency_key' => $payload['idempotency_key'] . '-wrong',
    );
};
$unsafe = Lunara_Dispatch_Control_Plane_Client::ingest_foundation_draft( $payload );
dispatch_runtime_assert( is_wp_error( $unsafe ) && 'lunara_dispatch_ingest_unsafe' === $unsafe->get_error_code(), 'mismatched Foundation idempotency response was accepted', $failures );

$migration = ( new Lunara_Dispatch_Post_Builder() )->migrate_roundups( false, 100 );
dispatch_runtime_assert( ! empty( $migration['disabled'] ) && 0 === $migration['created'], 'legacy split migration is not quarantined', $failures );

$image_handler = new Lunara_Dispatch_Image_Handler();
$source_image_method = new ReflectionMethod( $image_handler, 'item_has_source_story_image' );
$source_image_item = array(
    'image_url' => 'https://example.com/image.jpg',
    'image_origin' => 'article_open_graph',
    'image_source_verified' => true,
    'url' => 'https://example.com/story',
    'source_label' => 'Example Trade',
    'image_blocked' => false,
);
dispatch_runtime_assert( true === $source_image_method->invoke( $image_handler, $source_image_item ), 'exact source-story image provenance was rejected', $failures );
unset( $source_image_item['url'] );
dispatch_runtime_assert( false === $source_image_method->invoke( $image_handler, $source_image_item ), 'image import was allowed without its source story URL', $failures );
dispatch_runtime_assert( 0 === $image_handler->sideload( 'https://example.com/image.jpg', 0, 'Image', '', 'Example Trade', '', '', '', 'article_open_graph' ), 'sideload continued without an exact source story URL', $failures );

$exact_match_method = new ReflectionMethod( $image_handler, 'find_exact_source_item_index' );
$exact_items = array(
    array( 'url' => 'https://example.com/other-story' ),
    array( 'url' => 'https://example.com/story/' ),
);
$exact_index = $exact_match_method->invoke(
    $image_handler,
    array( 'https://example.com/story' ),
    $exact_items,
    array( 0 => array( 'other' ), 1 => array( 'story' ) ),
    array()
);
dispatch_runtime_assert( 1 === $exact_index, 'canonical source URL did not win exact image matching before keyword fallback', $failures );

$feed_fetcher = new Lunara_Dispatch_Feed_Fetcher();
$feed_method = new ReflectionMethod( $feed_fetcher, 'fetch_bounded_feed' );
$feed_args = $feed_method->invoke( $feed_fetcher, 'https://example.com/feed.xml' );
dispatch_runtime_assert( Lunara_Dispatch_Feed_Fetcher::MAX_FEED_BYTES === $feed_args['limit_response_size'], 'feed response byte budget was not applied', $failures );
dispatch_runtime_assert( true === $feed_args['reject_unsafe_urls'] && 2 === $feed_args['redirection'], 'feed HTTP safety arguments were not applied', $failures );
dispatch_runtime_assert( null === $http_args_callback, 'temporary feed HTTP filter leaked after fetch', $failures );
$source_origin = '';
$source_image = $feed_fetcher->resolve_source_story_image( 'https://example.com/source-story', $source_origin );
dispatch_runtime_assert( 'https://cdn.example.com/source-lead.jpg' === $source_image, 'source-story Open Graph image was not normalized correctly', $failures );
dispatch_runtime_assert( 'article_open_graph' === $source_origin, 'source-story Open Graph origin was not retained', $failures );

if ( $failures ) {
    fwrite( STDERR, "Dispatch integration runtime failed:\n- " . implode( "\n- ", $failures ) . "\n" );
    exit( 1 );
}

echo "Dispatch integration runtime passed.\n";
