<?php
/**
 * End-to-end worker contract: an OpenAI billing failure still creates drafts.
 * Run: php tests/dispatch-ai-fallback-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
define( 'LUNARA_DISPATCH_DIR', $root . DIRECTORY_SEPARATOR );
define( 'LUNARA_DISPATCH_VERSION', '3.2.5' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$fallback_options = array();

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function is_admin() { return false; }
function add_filter() { return true; }
function add_action() { return true; }
function wp_generate_uuid4() { return 'fallback-run-1'; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_cache_delete() { return true; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( $value ) { return sanitize_text_field( $value ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function get_bloginfo() { return 'UTF-8'; }
function wp_trim_words( $text, $count, $more = null ) {
	$words = preg_split( '/\s+/', trim( (string) $text ) );
	return count( $words ) > $count ? implode( ' ', array_slice( $words, 0, $count ) ) . $more : (string) $text;
}
function esc_url_raw( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
function esc_url( $value ) { return esc_url_raw( $value ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function current_time() { return '2026-08-14 18:00:00'; }
function has_post_thumbnail( $post_id ) { return 501 === (int) $post_id; }
function get_post_thumbnail_id( $post_id ) { return 501 === (int) $post_id ? 901 : 0; }

function add_option( $key, $value ) {
	global $fallback_options;
	if ( array_key_exists( $key, $fallback_options ) ) { return false; }
	$fallback_options[ $key ] = $value;
	return true;
}
function get_option( $key, $default = false ) {
	global $fallback_options;
	return array_key_exists( $key, $fallback_options ) ? $fallback_options[ $key ] : $default;
}
function update_option( $key, $value ) {
	global $fallback_options;
	$fallback_options[ $key ] = $value;
	return true;
}

class Fallback_Wpdb {
	public $options = 'wp_options';
	private $args = array();
	public function prepare( $query, ...$args ) { $this->args = $args; return $query; }
	public function get_var() { return get_option( Lunara_Dispatch_Plugin::LOCK_KEY, '' ); }
	public function query( $query ) {
		global $fallback_options;
		if ( 0 === strpos( trim( $query ), 'UPDATE' ) ) {
			$fallback_options[ Lunara_Dispatch_Plugin::LOCK_KEY ] = $this->args[0];
			return 1;
		}
		if ( 0 === strpos( trim( $query ), 'DELETE' ) ) {
			unset( $fallback_options[ Lunara_Dispatch_Plugin::LOCK_KEY ] );
			return 1;
		}
		return 0;
	}
}
$wpdb = new Fallback_Wpdb();

class Lunara_Journal_Control_Plane {}
class Lunara_Dispatch_Control_Plane_Client {
	public static function available() { return true; }
	public static function enabled() { return true; }
	public static function provider() { return 'openai'; }
	public static function model_for_provider() { return 'gpt-4.1'; }
	public static function post_status() { return 'draft'; }
	public static function runtime_config() { return array( 'protocol_version' => '1.2.1', 'config_version' => '25' ); }
}

class Fallback_Feed {
	public $seen = false;
	public $seen_count = 0;
	public function fetch_all() {
		return array(
			'items' => array(
			array(
				'title' => 'The Studio Fight Has Become the Movie',
				'url' => 'https://example.com/studio-fight',
				'source_label' => 'Example Trade',
				'published_at' => '2026-08-14 12:00:00',
				'description' => 'A filmmaker challenged the studio version of events and turned a routine release dispute into a revealing argument about leverage.',
				'image_url' => 'https://example.com/studio-fight.jpg',
				'image_source_verified' => true,
				'fingerprint' => 'fallback-source-1',
			),
			array( 'title' => 'The Sequel Deal Is Now the Real Drama', 'url' => 'https://example.com/sequel', 'source_label' => 'Example Trade', 'description' => 'A sequel negotiation has become more revealing than the official announcement.', 'fingerprint' => 'fallback-source-2' ),
			array( 'title' => 'A Festival Sale Exposes the Streaming Retreat', 'url' => 'https://example.com/festival', 'source_label' => 'Example Daily', 'description' => 'The acquisition terms show how quickly the streaming market has changed.', 'fingerprint' => 'fallback-source-3' ),
			array( 'title' => 'The Fourth Story Must Wait for the Next Run', 'url' => 'https://example.com/deferred', 'source_label' => 'Example Daily', 'description' => 'This source should remain eligible rather than overflowing the bounded AI run.', 'fingerprint' => 'fallback-source-4' ),
			),
			'skipped_duplicates' => 0,
			'errors' => array(),
		);
	}
	public function mark_seen( $items ) { $this->seen = true; $this->seen_count = count( $items ); }
}
class Fallback_Source_Reader {
	public function hydrate_items( $items ) {
		return array( 'items' => $items, 'ready' => 1, 'fallback' => 0, 'cache_hits' => 0, 'errors' => array() );
	}
}
class Fallback_AI {
	public function generate() { return new WP_Error( 'ai_billing_error', 'OpenAI error: no credits remain.' ); }
	public function get_last_usage() {
		return array( 'requested_model' => 'gpt-4.1', 'effective_model' => 'gpt-5.4-mini', 'max_output_tokens' => 2200 );
	}
}
class Fallback_Post_Builder {
	public $html = '';
	public $context = array();
	public function get_target_post_type() { return 'journal'; }
	public function split_into_individual_posts( $html, $map, $type, $status, $context ) {
		unset( $map, $type, $status );
		$this->html = $html;
		$this->context = $context;
		return array( 501 );
	}
	public function get_last_topic_duplicate_skips() { return array(); }
	public function get_last_quality_gate_skips() { return array(); }
	public function get_last_insertion_failures() { return array(); }
}
class Fallback_Image_Handler {
	public function assign_images_to_posts() { return array( 'sideloaded' => 1, 'matched' => 1 ); }
}

require_once $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-source-packet-builder.php';
require_once $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-plugin.php';

$plugin = Lunara_Dispatch_Plugin::instance();
$plugin->feed_fetcher = new Fallback_Feed();
$plugin->source_reader = new Fallback_Source_Reader();
$plugin->ai_client = new Fallback_AI();
$plugin->post_builder = new Fallback_Post_Builder();
$plugin->image_handler = new Fallback_Image_Handler();

$result = $plugin->run( true );
if ( empty( $result['success'] ) || empty( $result['ai_fallback_used'] ) || 1 !== (int) $result['created'] ) {
	fwrite( STDERR, "Billing failure stopped Dispatch instead of creating a source-packet draft.\n" );
	exit( 1 );
}
if ( 'source_packet' !== ( $plugin->post_builder->context['provider'] ?? '' ) || empty( $plugin->post_builder->context['source_packet_mode'] ) ) {
	fwrite( STDERR, "Fallback provenance did not identify the no-AI source-packet path.\n" );
	exit( 1 );
}
if ( 12 !== substr_count( $plugin->post_builder->html, '<!-- wp:paragraph -->' ) || false === strpos( $plugin->post_builder->html, 'https://example.com/studio-fight' ) ) {
	fwrite( STDERR, "Fallback draft lost editable blocks or original provenance.\n" );
	exit( 1 );
}
if ( ! $plugin->feed_fetcher->seen || 3 !== $plugin->feed_fetcher->seen_count || 1 !== (int) $result['created_with_featured_image'] ) {
	fwrite( STDERR, "Successful fallback did not complete seen-state or image accounting.\n" );
	exit( 1 );
}
if ( 1 !== (int) ( $result['deferred_source_items'] ?? 0 ) || false !== strpos( $plugin->post_builder->html, 'https://example.com/deferred' ) ) {
	fwrite( STDERR, "Per-run source bound did not defer overflow safely.\n" );
	exit( 1 );
}
$report = get_option( Lunara_Dispatch_Plugin::REPORT_OPTION, array() );
if ( empty( $report['ai_fallback_used'] ) || 'ai_billing_error' !== ( $report['ai_error_code'] ?? '' ) ) {
	fwrite( STDERR, "Last-run report did not preserve fallback evidence.\n" );
	exit( 1 );
}

echo "Dispatch AI fallback runtime passed.\n";
