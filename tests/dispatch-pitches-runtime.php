<?php
/**
 * Runtime check for the pitch gate: pitch mode files stories without writing,
 * only approved pitches are written (with the editor's angle), passed pitches
 * never are, and every pitch is closed out honestly.
 * Run: php tests/dispatch-pitches-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
define( 'LUNARA_DISPATCH_DIR', $root . DIRECTORY_SEPARATOR );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$pitch_options   = array();
$pitch_scheduled = array();
$pitch_failures  = array();

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code = '', $message = '' ) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function is_admin() { return false; }
function add_filter() { return true; }
function add_action() { return true; }
function __( $value ) { return $value; }
function wp_generate_uuid4() { static $n = 0; return 'owner-' . ( ++$n ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_cache_delete() { return true; }
function sanitize_text_field( $value ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) ); }
function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function get_bloginfo() { return 'UTF-8'; }
function wp_http_validate_url( $url ) { return $url; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $value ) { return (string) $value; }
function absint( $value ) { return abs( (int) $value ); }
function current_time() { return '2026-09-25 12:00:00'; }
function get_post_thumbnail_id() { return 0; }
function current_user_can( $cap ) { return 'edit_others_posts' === $cap; }
function rest_ensure_response( $value ) { return $value; }
function register_rest_route() { return true; }
function wp_next_scheduled() { return false; }
function wp_schedule_single_event( $time, $hook ) {
    global $pitch_scheduled;
    $pitch_scheduled[] = $hook;
    return true;
}
function spawn_cron() { return true; }
function delete_option( $key ) {
    global $pitch_options;
    unset( $pitch_options[ $key ] );
    return true;
}
function add_option( $key, $value ) {
    global $pitch_options;
    if ( array_key_exists( $key, $pitch_options ) ) {
        return false;
    }
    $pitch_options[ $key ] = $value;
    return true;
}
function get_option( $key, $default = false ) {
    global $pitch_options;
    return array_key_exists( $key, $pitch_options ) ? $pitch_options[ $key ] : $default;
}
function update_option( $key, $value ) {
    global $pitch_options;
    $pitch_options[ $key ] = $value;
    return true;
}

// A cooperative options table: the worker lock is granted, heartbeats and
// release succeed, exactly as they would for the only running worker.
class Dispatch_Pitch_Wpdb {
    public $options = 'wp_options';
    public function prepare( $query, ...$args ) { return array( $query, $args ); }
    public function get_var( $prepared ) {
        global $pitch_options;
        return $pitch_options[ $prepared[1][0] ] ?? null;
    }
    public function query( $prepared ) {
        global $pitch_options;
        list( $sql, $args ) = $prepared;
        if ( 0 === strpos( $sql, 'UPDATE' ) ) {
            if ( ( $pitch_options[ $args[1] ] ?? null ) !== $args[2] ) {
                return 0;
            }
            $changed = $pitch_options[ $args[1] ] !== $args[0];
            $pitch_options[ $args[1] ] = $args[0];
            return $changed ? 1 : 0;
        }
        if ( 0 === strpos( $sql, 'DELETE' ) && ( $pitch_options[ $args[0] ] ?? null ) === $args[1] ) {
            unset( $pitch_options[ $args[0] ] );
            return 1;
        }
        return 0;
    }
}
$wpdb = new Dispatch_Pitch_Wpdb();

function pitch_story( $n, array $extra = array() ) {
    return array_merge( array(
        'title'        => "Story $n",
        'url'          => "https://example.com/story-$n",
        'description'  => "What the outlet reported about story $n.",
        'source_label' => 'Deadline',
        'fingerprint'  => md5( "https://example.com/story-$n" ),
        'image_url'    => "https://example.com/story-$n.jpg",
        'published_at' => '2026-09-25T11:00:00+00:00',
    ), $extra );
}

class Dispatch_Pitch_Feed {
    public $calls = 0;
    public $items = array();
    public $seen  = array();
    public function fetch_all() {
        $this->calls++;
        return array( 'items' => $this->items, 'skipped_duplicates' => 0, 'errors' => array() );
    }
    public function mark_seen( array $items ) {
        foreach ( $items as $item ) {
            $this->seen[] = $item['url'];
        }
    }
    public function load_seen_sources() { return array(); }
}

class Dispatch_Pitch_Reader {
    public function hydrate_items( array $items ) {
        return array( 'items' => $items, 'ready' => 0, 'fallback' => count( $items ), 'cache_hits' => 0, 'errors' => array() );
    }
}

class Dispatch_Pitch_AI {
    public $prompts = array();
    public function generate( $news_data ) {
        $this->prompts[] = $news_data;
        return '<h3>Written</h3><p>Entry.</p>';
    }
}

class Dispatch_Pitch_Builder {
    public $next_id = 100;
    public $quality_gate = false;
    public $last_quality = array();
    public function get_target_post_type() { return 'journal'; }
    public function split_into_individual_posts( $generated, $a, $type, $status, $context ) {
        $this->last_quality = array();
        if ( $this->quality_gate ) {
            $this->last_quality = array( 'Entry failed the gate.' );
            return array();
        }
        $ids = array();
        foreach ( $context['items'] as $item ) {
            $ids[] = ++$this->next_id;
        }
        return $ids;
    }
    public function get_last_topic_duplicate_skips() { return array(); }
    public function get_last_quality_gate_skips() { return $this->last_quality; }
    public function get_last_insertion_failures() { return array(); }
}

class Dispatch_Pitch_Images {
    public function assign_images_to_posts() { return array( 'sideloaded' => 0, 'matched' => 0 ); }
}

class Lunara_Journal_Control_Plane {}

class Lunara_Dispatch_Control_Plane_Client {
    public static function available() { return true; }
    public static function runtime_config() { return array( 'protocol_version' => '1.2.1', 'enabled' => true, 'config_version' => '7' ); }
    public static function enabled() { return true; }
    public static function post_status() { return 'draft'; }
    public static function provider() { return 'openai'; }
    public static function model_for_provider() { return 'test-model'; }
}

class Lunara_Journal_Automation {
    public static $signals  = array();
    public static $outcomes = array();
    public static function dispatch_source_items() { return self::$signals; }
    public static function record_dispatch_source_outcome( array $ids, $outcome ) {
        foreach ( $ids as $id ) {
            self::$outcomes[ $id ] = $outcome;
        }
        return count( $ids );
    }
}

function pitch_check( $ok, $message ) {
    global $pitch_failures;
    if ( ! $ok ) {
        $pitch_failures[] = $message;
    }
}

function pitch_by_title( $title ) {
    foreach ( Lunara_Dispatch_Pitches::all() as $p ) {
        if ( $title === $p['item']['title'] ) {
            return $p;
        }
    }
    return null;
}

class Dispatch_Pitch_Request {
    private $params;
    private $method;
    public function __construct( $method, array $params = array() ) {
        $this->method = $method;
        $this->params = $params;
    }
    public function get_param( $key ) { return $this->params[ $key ] ?? null; }
    public function get_method() { return $this->method; }
}

require_once $root . DIRECTORY_SEPARATOR . 'includes/class-plugin.php';
require_once $root . DIRECTORY_SEPARATOR . 'includes/class-pitches.php';

$plugin = Lunara_Dispatch_Plugin::instance();
$plugin->feed_fetcher  = new Dispatch_Pitch_Feed();
$plugin->source_reader = new Dispatch_Pitch_Reader();
$plugin->ai_client     = new Dispatch_Pitch_AI();
$plugin->post_builder  = new Dispatch_Pitch_Builder();
$plugin->image_handler = new Dispatch_Pitch_Images();

// 1. Pitch mode files what the run found, writes nothing.
$mode = Lunara_Dispatch_Pitches::rest_mode( new Dispatch_Pitch_Request( 'POST', array( 'enabled' => true ) ) );
pitch_check( true === $mode['pitch_mode'], 'pitch mode did not switch on over REST' );
pitch_check( true === Lunara_Dispatch_Pitches::permissions_check(), 'editor capability was refused' );

$plugin->feed_fetcher->items = array( pitch_story( 1 ), pitch_story( 2 ), pitch_story( 3, array( 'image_blocked' => true ) ) );
Lunara_Journal_Automation::$signals = array( array( 'signal_id' => 55, 'title' => 'Radar tip', 'note' => 'Worth a look.', 'source_url' => 'https://example.org/tip', 'received_at' => '2026-09-25 10:00:00' ) );
$filed = $plugin->run( true );
pitch_check( ! empty( $filed['success'] ) && 4 === $filed['pitches_filed'], 'pitch run did not file four pitches' );
pitch_check( 0 === count( $plugin->ai_client->prompts ), 'pitch run called the model' );
pitch_check( 0 === $plugin->post_builder->next_id - 100, 'pitch run wrote drafts' );
pitch_check( 3 === count( $plugin->feed_fetcher->seen ), 'filed feed stories were not marked seen (radar must stay open)' );
pitch_check( empty( Lunara_Journal_Automation::$outcomes ), 'radar signal closed before a decision' );
pitch_check( '' === get_option( 'lunara_dispatch_running', '' ), 'pitch run left the worker lock held' );

$again = $plugin->run( true );
pitch_check( 0 === $again['pitches_filed'] && 4 === $again['pitches_waiting'], 'the same stories were filed twice' );

$listed = Lunara_Dispatch_Pitches::rest_list();
pitch_check( 4 === count( $listed['pitches'] ) && true === $listed['pitch_mode'], 'hub list is incomplete' );
$blocked = null;
foreach ( $listed['pitches'] as $row ) {
    if ( 'Story 3' === $row['title'] ) {
        $blocked = $row;
    }
    pitch_check( ! isset( $row['item'] ), 'hub list leaks the raw source item' );
}
pitch_check( $blocked && '' === $blocked['image_url'], 'blocked source image offered to the hub' );

// 2. Dalton decides: write one with an angle, pass two (one of them radar).
$p1 = pitch_by_title( 'Story 1' );
$p2 = pitch_by_title( 'Story 2' );
$pr = pitch_by_title( 'Radar tip' );
$decided = Lunara_Dispatch_Pitches::rest_decide( new Dispatch_Pitch_Request( 'POST', array(
    'write'  => array( $p1['id'] ),
    'pass'   => array( $p2['id'], $pr['id'] ),
    'angles' => array( $p1['id'] => 'Lead with the director, not the IP.' ),
) ) );
pitch_check( 1 === $decided['approved'] && 2 === $decided['passed'], 'decide counts are wrong' );
pitch_check( in_array( 'lunara_dispatch_manual_requested', $pitch_scheduled, true ) || ! empty( $decided['writer']['queued'] ), 'approval did not queue the writer' );
pitch_check( 'editorial_skip' === ( Lunara_Journal_Automation::$outcomes[55] ?? '' ), 'passed radar pitch did not close its signal' );

// 3. The approved pitch is written, with the angle, without a feed pull.
$feed_calls = $plugin->feed_fetcher->calls;
$written = $plugin->run( true );
pitch_check( ! empty( $written['success'] ) && 1 === $written['created'], 'approved pitch was not written' );
pitch_check( $feed_calls === $plugin->feed_fetcher->calls, 'approved-pitch run pulled the feeds' );
$prompt = end( $plugin->ai_client->prompts );
pitch_check( false !== strpos( $prompt, 'TITLE: Story 1' ), 'prompt is missing the approved story' );
pitch_check( false === strpos( $prompt, 'Story 2' ), 'passed pitch reached the model' );
$angle_at = strpos( $prompt, 'EDITOR_ANGLE' );
pitch_check( false !== $angle_at && $angle_at > strpos( $prompt, '[END_UNTRUSTED_SOURCE_ITEM]' ), 'editor angle missing or inside the untrusted block' );
pitch_check( false !== strpos( $prompt, 'Lead with the director, not the IP.' ), 'editor angle text lost' );
$p1 = pitch_by_title( 'Story 1' );
pitch_check( 'written' === $p1['status'] && array( 101 ) === $p1['post_ids'], 'written pitch not closed out with its post id' );
pitch_check( 'passed' === pitch_by_title( 'Story 2' )['status'], 'passed pitch changed state' );

// 4. More approvals than one run's cap: write three, queue the rest.
$plugin->feed_fetcher->items = array( pitch_story( 4 ), pitch_story( 5 ), pitch_story( 6 ), pitch_story( 7 ) );
$plugin->run( true );
$ids = array();
foreach ( array( 'Story 3', 'Story 4', 'Story 5', 'Story 6', 'Story 7' ) as $t ) {
    $ids[] = pitch_by_title( $t )['id'];
}
Lunara_Dispatch_Pitches::decide( $ids, array() );
$pitch_scheduled = array();
$batch = $plugin->run( true );
pitch_check( 3 === $batch['created'], 'capped run did not write three' );
pitch_check( 2 === Lunara_Dispatch_Pitches::count_status( 'approved' ), 'remaining approvals were lost' );
pitch_check( array( 'lunara_dispatch_manual_requested' ) === $pitch_scheduled, 'remaining approvals did not queue the next run' );

// 5. Editorial gate rejection closes the pitch as skipped, with the reason.
$plugin->post_builder->quality_gate = true;
$plugin->run( true );
pitch_check( 0 === Lunara_Dispatch_Pitches::count_status( 'approved' ), 'gate-rejected pitches stayed approved' );
$skipped = array_values( array_filter( Lunara_Dispatch_Pitches::all(), function ( $p ) { return 'skipped' === $p['status']; } ) );
pitch_check( 2 === count( $skipped ) && '' !== $skipped[0]['note'], 'gate skips not recorded with a reason' );
$plugin->post_builder->quality_gate = false;

// 6. Decided pitches cannot be re-opened or passed after the fact.
$again = Lunara_Dispatch_Pitches::decide( array( $p1['id'] ), array( $p1['id'] ) );
pitch_check( 0 === $again['approved'] && 0 === $again['passed'], 'a written pitch was re-decided' );

// 7. Pitch mode off: the classic pipeline runs untouched.
Lunara_Dispatch_Pitches::set_enabled( false );
Lunara_Journal_Automation::$signals = array();
$plugin->feed_fetcher->items = array( pitch_story( 9 ) );
$prompts = count( $plugin->ai_client->prompts );
$classic = $plugin->run( true );
pitch_check( ! empty( $classic['success'] ) && 1 === $classic['created'], 'classic run broke with pitch mode off' );
pitch_check( $prompts + 1 === count( $plugin->ai_client->prompts ), 'classic run skipped the model' );
pitch_check( null === pitch_by_title( 'Story 9' ), 'classic run filed a pitch' );

if ( $pitch_failures ) {
    fwrite( STDERR, "Dispatch pitch runtime failed:\n- " . implode( "\n- ", $pitch_failures ) . "\n" );
    exit( 1 );
}
echo "Dispatch pitch runtime passed.\n";
