<?php
/**
 * Runtime contract for the same-process Foundation Source Radar bridge.
 *
 * Run: php tests/source-radar-bridge-runtime.php
 */

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
define( 'LUNARA_DISPATCH_DIR', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
define( 'MINUTE_IN_SECONDS', 60 );

function is_admin() { return false; }
function add_filter() { return true; }
function add_action() { return true; }
function absint( $value ) { return abs( (int) $value ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $value, $protocols = null ) { unset( $protocols ); return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
function wp_parse_url( $value, $component = -1 ) { return parse_url( (string) $value, $component ); }
function wp_http_validate_url( $url ) { return 'example.com' === parse_url( (string) $url, PHP_URL_HOST ); }

class Radar_Feed_Stub {
    public function load_seen_sources() { return array( md5( 'https://example.com/duplicate' ) => '2026-08-01 12:00:00' ); }
    public function resolve_source_story_image( $url, &$origin = '' ) { unset( $url ); $origin = 'article_open_graph'; return 'https://example.com/story.jpg'; }
}

class Lunara_Journal_Automation {
    public static $outcomes = array();
    public static function dispatch_source_items( $limit = 6 ) {
        unset( $limit );
        return array(
            array( 'signal_id' => 91, 'title' => 'Captured source', 'note' => 'The studio has a deadline and the filmmakers have leverage.', 'source_url' => 'https://example.com/new-story', 'received_at' => '2026-08-01 15:00:00' ),
            array( 'signal_id' => 92, 'title' => 'Seen source', 'note' => 'Duplicate.', 'source_url' => 'https://example.com/duplicate', 'received_at' => '2026-08-01 14:00:00' ),
        );
    }
    public static function record_dispatch_source_outcome( $ids, $outcome, $post_ids, $run_id ) {
        self::$outcomes[] = compact( 'ids', 'outcome', 'post_ids', 'run_id' );
    }
}

require dirname( __DIR__ ) . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-plugin.php';

$plugin = Lunara_Dispatch_Plugin::instance();
$plugin->feed_fetcher = new Radar_Feed_Stub();
$merge = new ReflectionMethod( $plugin, 'merge_source_radar_items' );
$merge->setAccessible( true );
$result = $merge->invoke( $plugin, array() );

if ( array( 91 ) !== $result['accepted_signal_ids'] || array( 92 ) !== $result['duplicate_signal_ids'] ) {
    fwrite( STDERR, "Source Radar bridge did not separate fresh and seen signals.\n" );
    exit( 1 );
}
if ( 91 !== $result['items'][0]['automation_signal_id'] || 'https://example.com/story.jpg' !== $result['items'][0]['image_url'] || empty( $result['items'][0]['image_source_verified'] ) ) {
    fwrite( STDERR, "Fresh Source Radar item lost its exact URL or source-story image.\n" );
    exit( 1 );
}

$run_id = new ReflectionProperty( $plugin, 'current_run_id' );
$run_id->setAccessible( true );
$run_id->setValue( $plugin, 'run-source-radar' );
$record = new ReflectionMethod( $plugin, 'record_source_radar_outcome' );
$record->setAccessible( true );
$record->invoke( $plugin, $result['items'], 'drafted', array( 501 ) );
if ( 1 !== count( Lunara_Journal_Automation::$outcomes ) || array( 91 ) !== Lunara_Journal_Automation::$outcomes[0]['ids'] || 'run-source-radar' !== Lunara_Journal_Automation::$outcomes[0]['run_id'] ) {
    fwrite( STDERR, "Source Radar terminal outcome was not returned to Foundation.\n" );
    exit( 1 );
}

echo "Source Radar bridge runtime passed.\n";
