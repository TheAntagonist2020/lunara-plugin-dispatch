<?php
/**
 * Runtime checks for same-second heartbeat no-ops.
 * Run: php tests/dispatch-heartbeat-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
define( 'LUNARA_DISPATCH_DIR', $root . DIRECTORY_SEPARATOR );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

function is_admin() { return false; }
function add_filter() { return true; }
function add_action() { return true; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_cache_delete() { return true; }

class Dispatch_Heartbeat_Wpdb {
    public $options = 'wp_options';
    public $state = array();
    public function prepare( $query ) { return $query; }
    public function get_var() { return json_encode( $this->state ); }
    public function query() { return 0; }
}

$wpdb = new Dispatch_Heartbeat_Wpdb();

require_once $root . DIRECTORY_SEPARATOR . 'includes/class-plugin.php';

$plugin = Lunara_Dispatch_Plugin::instance();
$heartbeat = new ReflectionMethod( $plugin, 'heartbeat_lock' );
$heartbeat->setAccessible( true );

$wpdb->state = array(
    'owner'     => 'owner-original',
    'heartbeat' => time(),
    'expires'   => time() + 1200,
);
if ( true !== $heartbeat->invoke( $plugin, 'owner-original' ) ) {
    fwrite( STDERR, "Same-second heartbeat incorrectly lost its valid lock.\n" );
    exit( 1 );
}

$wpdb->state['owner'] = 'owner-stolen';
if ( false !== $heartbeat->invoke( $plugin, 'owner-original' ) ) {
    fwrite( STDERR, "Heartbeat accepted a lock owned by another worker.\n" );
    exit( 1 );
}

$wpdb->state = array(
    'owner'     => 'owner-original',
    'heartbeat' => time() - 1300,
    'expires'   => time() - 100,
);
if ( false !== $heartbeat->invoke( $plugin, 'owner-original' ) ) {
    fwrite( STDERR, "Heartbeat accepted an expired worker lock.\n" );
    exit( 1 );
}

echo "Dispatch same-second heartbeat runtime passed.\n";
