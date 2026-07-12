<?php
/**
 * Runtime check that Dispatch cannot insert when Journal Foundation is absent.
 * Run: php tests/dispatch-foundation-dependency-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
$insert_calls = 0;

class WP_Error {
    private $code;
    public function __construct( $code = '' ) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_insert_post() {
    global $insert_calls;
    $insert_calls++;
    return 77;
}

require_once $root . DIRECTORY_SEPARATOR . 'includes/class-journal-ingest-bridge.php';

$result = Lunara_Dispatch_Journal_Ingest_Bridge::ingest_payload( array(
    'idempotency_key' => 'source-1-section-0',
    'source_items' => array( array( 'source_url' => 'https://example.com/story' ) ),
) );

if ( ! is_wp_error( $result ) || 'lunara_dispatch_foundation_required' !== $result->get_error_code() ) {
    fwrite( STDERR, "Absent Foundation did not return the dependency error.\n" );
    exit( 1 );
}
if ( 0 !== $insert_calls ) {
    fwrite( STDERR, "Dispatch attempted a standalone insert without Foundation.\n" );
    exit( 1 );
}

echo "Dispatch Foundation dependency runtime passed.\n";
