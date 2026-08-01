<?php
/**
 * Runtime contract for bounded, cached article-context retrieval.
 *
 * Run: php tests/source-reader-runtime.php
 */

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );

$reader_transients = array();
$reader_requests   = array();
$reader_responses  = array();

class WP_Error {
    public function __construct( public $code = '', public $message = '' ) {}
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function esc_url_raw( $value, $protocols = null ) { unset( $protocols ); return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
function wp_parse_url( $value, $component = -1 ) { return parse_url( (string) $value, $component ); }
function wp_http_validate_url( $url ) {
    $host = (string) parse_url( (string) $url, PHP_URL_HOST );
    return '' !== $host && ! in_array( $host, array( 'localhost', '127.0.0.1', '10.0.0.1' ), true );
}
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function get_bloginfo() { return 'UTF-8'; }
function get_transient( $key ) { global $reader_transients; return $reader_transients[ $key ] ?? false; }
function set_transient( $key, $value, $ttl ) { global $reader_transients; $reader_transients[ $key ] = array_merge( $value, array( '_ttl' => $ttl ) ); return true; }
function wp_safe_remote_get( $url, $args ) {
    global $reader_requests, $reader_responses;
    $reader_requests[] = array( 'url' => $url, 'args' => $args );
    return $reader_responses[ $url ] ?? new WP_Error( 'missing_fixture', 'Missing fixture.' );
}
function wp_remote_retrieve_response_code( $response ) { return (int) ( $response['code'] ?? 0 ); }
function wp_remote_retrieve_header( $response, $name ) { return (string) ( $response['headers'][ strtolower( $name ) ] ?? '' ); }
function wp_remote_retrieve_body( $response ) { return (string) ( $response['body'] ?? '' ); }

$paragraph = 'Warner Bros. faces a concrete negotiation problem involving valuable sequel rights, a deadline, and the filmmakers whose leverage now shapes the studio decision. ';
$reader_responses['https://example.com/story'] = array(
    'code'    => 200,
    'headers' => array( 'content-type' => 'text/html; charset=UTF-8' ),
    'body'    => '<html><body><nav>Subscribe to everything</nav><article><h2>The actual story</h2><p>' . $paragraph . $paragraph . '</p><p>' . $paragraph . $paragraph . '</p><p>Advertisement</p></article><footer>Unrelated footer copy</footer></body></html>',
);
$reader_responses['https://example.com/data'] = array(
    'code'    => 200,
    'headers' => array( 'content-type' => 'application/json' ),
    'body'    => '{"not":"an article"}',
);

require dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-source-reader.php';

$reader = new Lunara_Dispatch_Source_Reader();
$first  = $reader->read( 'https://example.com/story' );
if ( 'ready' !== $first['status'] || false === strpos( $first['context'], 'concrete negotiation problem' ) ) {
    fwrite( STDERR, "Article reader did not extract the story body.\n" );
    exit( 1 );
}
if ( false !== strpos( $first['context'], 'Unrelated footer' ) || false !== strpos( $first['context'], 'Subscribe to everything' ) ) {
    fwrite( STDERR, "Article reader retained page chrome instead of story context.\n" );
    exit( 1 );
}
if ( 1 !== count( $reader_requests ) || 1048576 !== $reader_requests[0]['args']['limit_response_size'] || empty( $reader_requests[0]['args']['reject_unsafe_urls'] ) ) {
    fwrite( STDERR, "Article reader did not enforce the safe HTTP request budget.\n" );
    exit( 1 );
}

$second = $reader->read( 'https://example.com/story' );
if ( empty( $second['cache_hit'] ) || 1 !== count( $reader_requests ) ) {
    fwrite( STDERR, "Article reader did not reuse its bounded cache.\n" );
    exit( 1 );
}

$request_count = count( $reader_requests );
if ( ! $reader->prime_from_html( 'https://example.com/primed', $reader_responses['https://example.com/story']['body'] ) ) {
    fwrite( STDERR, "Article reader did not accept bounded HTML already fetched for source-image discovery.\n" );
    exit( 1 );
}
$primed = $reader->read( 'https://example.com/primed' );
if ( 'ready' !== $primed['status'] || empty( $primed['cache_hit'] ) || $request_count !== count( $reader_requests ) ) {
    fwrite( STDERR, "Article reader repeated a network request after source-image cache priming.\n" );
    exit( 1 );
}

$non_html = $reader->read( 'https://example.com/data' );
if ( 'fallback' !== $non_html['status'] || 'non_html' !== $non_html['reason'] ) {
    fwrite( STDERR, "Article reader accepted a non-HTML response.\n" );
    exit( 1 );
}
$request_count = count( $reader_requests );
$unsafe = $reader->read( 'http://127.0.0.1/private' );
if ( 'unsafe_url' !== $unsafe['reason'] || $request_count !== count( $reader_requests ) ) {
    fwrite( STDERR, "Article reader attempted an unsafe URL.\n" );
    exit( 1 );
}

echo "Source reader runtime passed.\n";
