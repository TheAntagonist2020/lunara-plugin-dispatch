<?php
/**
 * Runtime check that a worker losing lock ownership stops before AI or writes.
 * Run: php tests/dispatch-worker-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
define( 'LUNARA_DISPATCH_DIR', $root . DIRECTORY_SEPARATOR );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$worker_options = array();

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

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function is_admin() { return false; }
function add_filter() { return true; }
function add_action() { return true; }
function __( $value ) { return $value; }
function wp_generate_uuid4() { return 'owner-original'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_cache_delete() { return true; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function current_time() { return '2026-07-12 12:00:00'; }

function add_option( $key, $value ) {
    global $worker_options;
    $worker_options[ $key ] = $value;
    return true;
}

function get_option( $key, $default = false ) {
    global $worker_options;
    return array_key_exists( $key, $worker_options ) ? $worker_options[ $key ] : $default;
}

function update_option( $key, $value ) {
    global $worker_options;
    $worker_options[ $key ] = $value;
    return true;
}

class Dispatch_Worker_Wpdb {
    public $options = 'wp_options';
    public function prepare( $query ) { return $query; }
    public function get_var() {
        return json_encode( array(
            'owner' => 'owner-stolen',
            'heartbeat' => time(),
            'expires' => time() + 1200,
        ) );
    }
    public function query() { return 0; }
}

$wpdb = new Dispatch_Worker_Wpdb();

class Dispatch_Worker_Feed {
    public $calls = 0;
    public function fetch_all() {
        $this->calls++;
        return array(
            'items' => array( array( 'title' => 'Story', 'url' => 'https://example.com/story' ) ),
            'skipped_duplicates' => 0,
            'errors' => array(),
        );
    }
}

class Lunara_Journal_Control_Plane {}

class Lunara_Dispatch_Control_Plane_Client {
    public static $available = true;
    public static function available() { return self::$available; }
    public static function runtime_config() {
        return self::$available
            ? array( 'protocol_version' => '1.2.1', 'enabled' => true )
            : array( 'protocol_version' => '2.0.0', 'enabled' => false, 'protocol_error' => 'Unsupported Journal Foundation protocol.' );
    }
    public static function enabled() { return true; }
    public static function post_status() { return 'draft'; }
}

class Dispatch_Worker_AI {
    public $calls = 0;
    public function generate() {
        $this->calls++;
        return '<h3>Should never run</h3><p>Worker lost ownership.</p>';
    }
}

require_once $root . DIRECTORY_SEPARATOR . 'includes/class-plugin.php';

$plugin = Lunara_Dispatch_Plugin::instance();
$plugin->feed_fetcher = new Dispatch_Worker_Feed();
$plugin->ai_client = new Dispatch_Worker_AI();
$result = $plugin->run( true );

if ( ! empty( $result['success'] ) || empty( $result['retry_required'] ) ) {
    fwrite( STDERR, "Worker lock-loss runtime did not fail with retry_required.\n" );
    exit( 1 );
}
if ( 0 !== $plugin->ai_client->calls ) {
    fwrite( STDERR, "Worker continued into AI generation after losing lock ownership.\n" );
    exit( 1 );
}

Lunara_Dispatch_Control_Plane_Client::$available = false;
$feed_calls_before = $plugin->feed_fetcher->calls;
$queue_result = $plugin->queue_manual_run();
if ( ! is_wp_error( $queue_result ) || 'lunara_dispatch_foundation_required' !== $queue_result->get_error_code() ) {
    fwrite( STDERR, "Manual queue accepted an incompatible Foundation.\n" );
    exit( 1 );
}
$protocol_result = $plugin->run( true );
if ( ! empty( $protocol_result['success'] ) || empty( $protocol_result['protocol_error'] ) ) {
    fwrite( STDERR, "Forced worker run did not reject an incompatible active Foundation.\n" );
    exit( 1 );
}
if ( $feed_calls_before !== $plugin->feed_fetcher->calls ) {
    fwrite( STDERR, "Worker fetched feeds before rejecting an incompatible Foundation protocol.\n" );
    exit( 1 );
}

echo "Dispatch worker lock-loss runtime passed.\n";
