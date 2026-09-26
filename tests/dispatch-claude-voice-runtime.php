<?php
/**
 * Runtime check for Dispatch 3.4.0: Claude writes the Journal.
 *
 * Drafts did not sound like Dalton: a small model wrote up to three entries in
 * one 2,200-token response, and a banned phrase got the entry thrown away
 * instead of fixed. This holds:
 *
 *   client   -- the Claude Opus 5 request (adaptive thinking, effort, default
 *               server-side fallbacks, cached system prompt, no sampling
 *               parameters), stop reasons checked before any text is read,
 *               only text blocks kept, usage and cost reported, and a
 *               revision sent as a follow-up turn. An older Claude ID keeps
 *               the plain request.
 *   cap      -- the Control Plane client gives Claude room to think (16,000)
 *               and keeps every other provider at 2,200.
 *   revision -- a draft carrying a house tell goes back to the model once,
 *               with the tells named; the revision replaces it; a clean draft
 *               or a failed revision costs no second call or loses nothing.
 *
 * Each part runs in its own PHP process because each stubs a different piece.
 *
 * Run: php tests/dispatch-claude-voice-runtime.php
 */

$root = dirname( __DIR__ );
$part = $argv[1] ?? '';

if ( '' === $part ) {
	$failed = false;
	foreach ( array( 'client', 'cap', 'revision' ) as $name ) {
		$output = array();
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ ) . ' ' . $name . ' 2>&1', $output, $code );
		echo implode( "\n", $output ) . "\n";
		$failed = $failed || 0 !== $code;
	}
	if ( $failed ) {
		fwrite( STDERR, "Dispatch Claude voice runtime failed.\n" );
		exit( 1 );
	}
	echo "Dispatch Claude voice runtime passed.\n";
	exit( 0 );
}

define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$failures = array();
function cv_check( $ok, $message ) {
	global $failures;
	if ( ! $ok ) {
		$failures[] = $message;
	}
}
function cv_finish( $label ) {
	global $failures;
	if ( $failures ) {
		fwrite( STDERR, "[{$label}] failed:\n- " . implode( "\n- ", $failures ) . "\n" );
		exit( 1 );
	}
	echo "[{$label}] passed.\n";
	exit( 0 );
}

class WP_Error {
	private $code; private $message; private $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $value ) ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }

/* ───────────────────────────── client ───────────────────────────── */
if ( 'client' === $part ) {
	$GLOBALS['cv_model'] = 'claude-opus-5';
	$GLOBALS['cv_requests'] = array();
	$GLOBALS['cv_response'] = null;
	function get_option( $key, $default = false ) { return 'lunara_dispatch_claude_key' === $key ? 'sk-ant-test-key' : $default; }
	function wp_safe_remote_post( $url, $args ) {
		$GLOBALS['cv_requests'][] = array( 'url' => $url, 'args' => $args );
		return array( 'response' => array( 'code' => $GLOBALS['cv_response'][0] ), 'body' => json_encode( $GLOBALS['cv_response'][1] ) );
	}
	function wp_remote_retrieve_response_code( $r ) { return (int) $r['response']['code']; }
	function wp_remote_retrieve_body( $r ) { return $r['body']; }
	class Lunara_Dispatch_Control_Plane_Client {
		public static function provider() { return 'claude'; }
		public static function model_for_provider( $provider, $default ) { return $GLOBALS['cv_model']; }
		public static function max_tokens() { return 16000; }
	}
	class Lunara_Dispatch_Prompts {
		public static function system_prompt() { return 'SYSTEM WITH DALTON EXEMPLARS'; }
		public static function user_directive_prompt() { return 'USER DIRECTIVE'; }
		public static function user_directive( $news ) { return "USER DIRECTIVE\n" . $news; }
	}
	require $root . '/includes/class-ai-client.php';

	$entry = '<h3>Paramount Let It Be Weird</h3><p>Entry.</p><p>Question?</p>';
	$ok = array( 200, array(
		'model' => 'claude-opus-5',
		'stop_reason' => 'end_turn',
		'content' => array(
			array( 'type' => 'thinking', 'thinking' => '', 'signature' => 'sig' ),
			array( 'type' => 'text', 'text' => $entry ),
		),
		'usage' => array( 'input_tokens' => 1000, 'cache_creation_input_tokens' => 6000, 'cache_read_input_tokens' => 0, 'output_tokens' => 2000 ),
	) );

	$client = new Lunara_Dispatch_AI_Client();
	$GLOBALS['cv_response'] = $ok;
	$html = $client->generate( '[BEGIN_UNTRUSTED_SOURCE_ITEM] one story [END_UNTRUSTED_SOURCE_ITEM]' );
	$sent = $GLOBALS['cv_requests'][0];
	$body = json_decode( $sent['args']['body'], true );
	cv_check( $entry === $html, 'Only the text block is the entry; thinking is never part of it.' );
	cv_check( 'https://api.anthropic.com/v1/messages' === $sent['url'] && 'claude-opus-5' === $body['model'], 'The request did not go to Claude Opus 5.' );
	cv_check( 'adaptive' === ( $body['thinking']['type'] ?? '' ) && 'high' === ( $body['output_config']['effort'] ?? '' ), 'Opus 5 drafts must use adaptive thinking at high effort.' );
	cv_check( 'default' === ( $body['fallbacks'] ?? '' ) && 'server-side-fallback-2026-07-01' === ( $sent['args']['headers']['anthropic-beta'] ?? '' ), 'Default server-side fallbacks and their beta header must be sent together.' );
	cv_check( 16000 === $body['max_tokens'] && 300 === $sent['args']['timeout'], 'Opus 5 needs room and time to think before it writes.' );
	cv_check( ! isset( $body['temperature'] ) && ! isset( $body['top_p'] ) && ! isset( $body['top_k'] ) && ! isset( $body['thinking']['budget_tokens'] ), 'Sampling and budget parameters are rejected by Opus 5 and must not be sent.' );
	cv_check( 'SYSTEM WITH DALTON EXEMPLARS' === ( $body['system'][0]['text'] ?? '' ) && 'ephemeral' === ( $body['system'][0]['cache_control']['type'] ?? '' ), 'The system prompt (with the exemplars) must be a cached block.' );
	cv_check( 1 === count( $body['messages'] ) && 'user' === $body['messages'][0]['role'], 'A first draft is a single user turn with no prefill.' );
	cv_check( 'sk-ant-test-key' === $sent['args']['headers']['x-api-key'] && false === strpos( $sent['args']['body'], 'sk-ant-test-key' ), 'The key travels only in its header.' );
	$usage = $client->get_last_usage();
	cv_check( 'claude' === $usage['provider'] && 7000 === $usage['input_tokens'] && 0 === $usage['cached_input_tokens'] && 2000 === $usage['output_tokens'], 'Claude usage is not reported.' );
	cv_check( 0.0925 === $usage['estimated_cost_usd'], 'Claude Opus 5 cost is not estimated at $5 in, $6.25 cache write, $25 out per million (got ' . var_export( $usage['estimated_cost_usd'], true ) . ').' );

	// A revision is a follow-up turn on the same conversation.
	$GLOBALS['cv_requests'] = array();
	$client->generate( 'news', array( 'draft' => '<h3>Old</h3><p>This matters because.</p>', 'note' => 'Revise your draft.' ) );
	$revision = json_decode( $GLOBALS['cv_requests'][0]['args']['body'], true );
	cv_check( 3 === count( $revision['messages'] ) && 'assistant' === $revision['messages'][1]['role'] && '<h3>Old</h3><p>This matters because.</p>' === $revision['messages'][1]['content'], 'The revision must show the model its own draft as its previous turn.' );
	cv_check( 'user' === $revision['messages'][2]['role'] && 'Revise your draft.' === $revision['messages'][2]['content'], 'The revision note must be the last (user) turn.' );

	// Stop reasons are checked before any text is read.
	$refusal = array( 200, array( 'stop_reason' => 'refusal', 'content' => array( array( 'type' => 'text', 'text' => 'partial' ) ) ) );
	$GLOBALS['cv_response'] = $refusal;
	$result = $client->generate( 'news' );
	cv_check( is_wp_error( $result ) && 'ai_refusal' === $result->get_error_code(), 'A refusal must never become a draft.' );
	$GLOBALS['cv_response'] = array( 200, array( 'stop_reason' => 'max_tokens', 'content' => array( array( 'type' => 'text', 'text' => '<h3>Half</h3><p>An entry cut off mid' ) ) ) );
	$result = $client->generate( 'news' );
	cv_check( is_wp_error( $result ) && 'ai_truncated' === $result->get_error_code(), 'A draft cut off at the token ceiling must never become a draft.' );
	$GLOBALS['cv_response'] = array( 200, array( 'model' => 'claude-opus-4-8', 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'fallback', 'model' => 'claude-opus-4-8' ), array( 'type' => 'text', 'text' => $entry ) ) ) );
	cv_check( $entry === $client->generate( 'news' ) && 'claude-opus-4-8' === $client->get_last_usage()['effective_model'] && null === $client->get_last_usage()['estimated_cost_usd'], 'A fallback answer keeps only its text and reports the model that wrote it.' );
	$GLOBALS['cv_response'] = array( 401, array( 'type' => 'error', 'error' => array( 'type' => 'authentication_error', 'message' => 'invalid x-api-key' ) ) );
	cv_check( 'ai_auth_error' === $client->generate( 'news' )->get_error_code(), 'A rejected key must read as an authentication error.' );
	$GLOBALS['cv_response'] = array( 400, array( 'type' => 'error', 'error' => array( 'type' => 'invalid_request_error', 'message' => 'Your credit balance is too low to access the Anthropic API.' ) ) );
	cv_check( 'ai_billing_error' === $client->generate( 'news' )->get_error_code(), 'An empty credit balance must read as a billing error.' );

	// An older Claude ID keeps the request it has always accepted.
	$GLOBALS['cv_model'] = 'claude-opus-4-5';
	$GLOBALS['cv_requests'] = array();
	$GLOBALS['cv_response'] = $ok;
	$client->generate( 'news' );
	$legacy = json_decode( $GLOBALS['cv_requests'][0]['args']['body'], true );
	cv_check( ! isset( $legacy['thinking'] ) && ! isset( $legacy['output_config'] ) && ! isset( $legacy['fallbacks'] ) && ! isset( $GLOBALS['cv_requests'][0]['args']['headers']['anthropic-beta'] ) && 120 === $GLOBALS['cv_requests'][0]['args']['timeout'], 'An older Claude model must keep the plain request.' );
	cv_check( Lunara_Dispatch_AI_Client::is_claude_5( 'claude-opus-5' ) && Lunara_Dispatch_AI_Client::is_claude_5( 'claude-opus-5-5' ) && ! Lunara_Dispatch_AI_Client::is_claude_5( 'claude-opus-4-5' ) && ! Lunara_Dispatch_AI_Client::is_claude_5( 'claude-opus-50' ), 'The Claude 5-family check is wrong.' );

	cv_finish( 'client' );
}

/* ────────────────────────────── cap ─────────────────────────────── */
if ( 'cap' === $part ) {
	$GLOBALS['cv_runtime'] = array();
	function get_option( $key, $default = false ) { return $default; }
	class Lunara_Journal_Control_Plane {
		public static function get_dispatch_runtime_config() { return $GLOBALS['cv_runtime']; }
	}
	require $root . '/includes/class-control-plane-client.php';
	$base = array( 'protocol_version' => '1.2.2', 'max_tokens' => 16000, 'models' => array() );
	foreach ( array( 'claude' => 16000, 'openai' => 2200, 'gemini' => 2200, 'grok' => 2200 ) as $provider => $cap ) {
		$GLOBALS['cv_runtime'] = array_merge( $base, array( 'provider' => $provider ) );
		cv_check( $cap === Lunara_Dispatch_Control_Plane_Client::max_tokens(), "The {$provider} output cap is not {$cap}." );
	}
	$GLOBALS['cv_runtime'] = array_merge( $base, array( 'provider' => 'claude', 'house_tells' => array( 'Notably,', ' this matters because ', '', array( 'x' ), str_repeat( 'x', 400 ) ) ) );
	cv_check( array( 'notably,', 'this matters because' ) === Lunara_Dispatch_Control_Plane_Client::house_tells(), 'House tells are not normalized and bounded.' );
	$GLOBALS['cv_runtime'] = $base;
	cv_check( array() === Lunara_Dispatch_Control_Plane_Client::house_tells(), 'An older Foundation without house tells must yield none.' );
	cv_finish( 'cap' );
}

/* ──────────────────────────── revision ──────────────────────────── */
if ( 'revision' === $part ) {
	$GLOBALS['cv_options'] = array();
	function is_admin() { return false; }
	function add_filter() { return true; }
	function add_action() { return true; }
	function __( $value ) { return $value; }
	function wp_generate_uuid4() { static $n = 0; return 'owner-' . ( ++$n ); }
	function wp_cache_delete() { return true; }
	function sanitize_textarea_field( $value ) { return trim( strip_tags( (string) $value ) ); }
	function get_bloginfo() { return 'UTF-8'; }
	function wp_http_validate_url( $url ) { return $url; }
	function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
	function esc_url_raw( $value ) { return (string) $value; }
	function absint( $value ) { return abs( (int) $value ); }
	function current_time() { return '2026-09-26 12:00:00'; }
	function get_post_thumbnail_id() { return 0; }
	function wp_next_scheduled() { return false; }
	function wp_schedule_single_event() { return true; }
	function spawn_cron() { return true; }
	function delete_option( $key ) { unset( $GLOBALS['cv_options'][ $key ] ); return true; }
	function add_option( $key, $value ) {
		if ( array_key_exists( $key, $GLOBALS['cv_options'] ) ) { return false; }
		$GLOBALS['cv_options'][ $key ] = $value;
		return true;
	}
	function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['cv_options'] ) ? $GLOBALS['cv_options'][ $key ] : $default; }
	function update_option( $key, $value ) { $GLOBALS['cv_options'][ $key ] = $value; return true; }
	class CV_Wpdb {
		public $options = 'wp_options';
		public function prepare( $query, ...$args ) { return array( $query, $args ); }
		public function get_var( $prepared ) { return $GLOBALS['cv_options'][ $prepared[1][0] ] ?? null; }
		public function query( $prepared ) {
			list( $sql, $args ) = $prepared;
			if ( 0 === strpos( $sql, 'UPDATE' ) ) {
				if ( ( $GLOBALS['cv_options'][ $args[1] ] ?? null ) !== $args[2] ) { return 0; }
				$changed = $GLOBALS['cv_options'][ $args[1] ] !== $args[0];
				$GLOBALS['cv_options'][ $args[1] ] = $args[0];
				return $changed ? 1 : 0;
			}
			if ( 0 === strpos( $sql, 'DELETE' ) && ( $GLOBALS['cv_options'][ $args[0] ] ?? null ) === $args[1] ) {
				unset( $GLOBALS['cv_options'][ $args[0] ] );
				return 1;
			}
			return 0;
		}
	}
	$wpdb = new CV_Wpdb();

	class CV_Feed {
		public $items = array();
		public function fetch_all() { return array( 'items' => $this->items, 'skipped_duplicates' => 0, 'errors' => array() ); }
		public function mark_seen( array $items ) {}
		public function load_seen_sources() { return array(); }
	}
	class CV_Reader {
		public function hydrate_items( array $items ) { return array( 'items' => $items, 'ready' => 0, 'fallback' => count( $items ), 'cache_hits' => 0, 'errors' => array() ); }
	}
	class CV_AI {
		public $calls = array();
		public $script = array();
		public function generate( $news_data, array $revision = array() ) {
			$this->calls[] = array( 'news' => $news_data, 'revision' => $revision );
			return array_shift( $this->script );
		}
		public function get_last_usage() { return array( 'provider' => 'claude', 'input_tokens' => 100, 'cached_input_tokens' => 0, 'output_tokens' => 50, 'estimated_cost_usd' => 0.001 ); }
	}
	class CV_Builder {
		public $written = array();
		public function get_target_post_type() { return 'journal'; }
		public function split_into_individual_posts( $generated, $a, $type, $status, $context ) { $this->written[] = $generated; return array( 101 ); }
		public function get_last_topic_duplicate_skips() { return array(); }
		public function get_last_quality_gate_skips() { return array(); }
		public function get_last_insertion_failures() { return array(); }
	}
	class CV_Images {
		public function assign_images_to_posts() { return array( 'sideloaded' => 0, 'matched' => 0 ); }
	}
	class Lunara_Journal_Control_Plane {}
	class Lunara_Dispatch_Control_Plane_Client {
		public static function available() { return true; }
		public static function runtime_config() { return array( 'protocol_version' => '1.2.2', 'enabled' => true, 'config_version' => '1.0.27' ); }
		public static function enabled() { return true; }
		public static function post_status() { return 'draft'; }
		public static function provider() { return 'claude'; }
		public static function model_for_provider() { return 'claude-opus-5'; }
		public static function house_tells() { return array( 'notably,', 'poised to' ); }
	}
	require $root . '/includes/class-post-builder.php';
	require $root . '/includes/class-plugin.php';

	$plugin = Lunara_Dispatch_Plugin::instance();
	$plugin->feed_fetcher  = new CV_Feed();
	$plugin->source_reader = new CV_Reader();
	$plugin->image_handler = new CV_Images();
	$story = array( 'title' => 'Story', 'url' => 'https://example.com/story', 'description' => 'What happened.', 'source_label' => 'Deadline', 'fingerprint' => 'f1', 'image_url' => '', 'published_at' => '2026-09-26T10:00:00+00:00' );

	// 1. A draft with a post-builder tell and a Control Plane tell is revised once.
	$plugin->ai_client    = new CV_AI();
	$plugin->post_builder = new CV_Builder();
	$plugin->feed_fetcher->items = array( $story );
	$tainted = '<h3>Notably, a Headline</h3><p>This matters because the studio is poised to blink.</p>';
	$clean   = '<h3>The Studio Blinked</h3><p>They blinked. Good.</p><p>Would you have?</p>';
	$plugin->ai_client->script = array( $tainted, $clean );
	$result = $plugin->run( true );
	$calls = $plugin->ai_client->calls;
	cv_check( 2 === count( $calls ), 'A tainted draft must get exactly one revision call.' );
	cv_check( isset( $calls[1]['revision']['draft'] ) && $tainted === $calls[1]['revision']['draft'] && $calls[0]['news'] === $calls[1]['news'], 'The revision must carry the same sources and the model\'s own draft.' );
	$note = $calls[1]['revision']['note'] ?? '';
	cv_check( false !== strpos( $note, '"this matters because"' ) && false !== strpos( $note, '"notably,"' ) && false !== strpos( $note, '"poised to"' ), 'The revision note must name every tell found, headline included.' );
	cv_check( false !== strpos( $note, 'closing question' ) && false !== strpos( $note, 'add nothing the source does not support' ), 'The revision note must protect the close and the facts.' );
	cv_check( array( $clean ) === $plugin->post_builder->written, 'The revision must replace the tainted draft.' );
	$report = get_option( Lunara_Dispatch_Plugin::REPORT_OPTION );
	cv_check( true === $report['voice_revision']['revised'] && array() === $report['voice_revision']['tells_remaining'] && 3 === count( $report['voice_revision']['tells_found'] ), 'The run report must record the revision.' );
	cv_check( 200 === $report['ai_usage']['input_tokens'] && 100 === $report['ai_usage']['output_tokens'] && 0.002 === $report['ai_usage']['estimated_cost_usd'], 'The run report must total both calls.' );

	// 2. A clean draft costs no second call. Whole words only: "notablyish" is not a tell.
	$plugin->ai_client    = new CV_AI();
	$plugin->post_builder = new CV_Builder();
	$plugin->ai_client->script = array( '<h3>Notablyish Headline</h3><p>Clean copy.</p>' );
	$plugin->run( true );
	cv_check( 1 === count( $plugin->ai_client->calls ) && 1 === count( $plugin->post_builder->written ), 'A clean draft must not be revised.' );
	cv_check( array() === get_option( Lunara_Dispatch_Plugin::REPORT_OPTION )['voice_revision'], 'A clean run must not report a revision.' );

	// 3. A failed revision keeps the original draft; the post builder decides.
	$plugin->ai_client    = new CV_AI();
	$plugin->post_builder = new CV_Builder();
	$plugin->ai_client->script = array( $tainted, new WP_Error( 'ai_refusal', 'declined' ) );
	$plugin->run( true );
	cv_check( array( $tainted ) === $plugin->post_builder->written, 'A failed revision must not lose the draft.' );
	$report = get_option( Lunara_Dispatch_Plugin::REPORT_OPTION );
	cv_check( false === $report['voice_revision']['revised'] && 'ai_refusal' === $report['voice_revision']['error_code'], 'A failed revision must be reported with its reason.' );

	// 4. A revision that turns into a skip marker does not throw the draft away.
	$plugin->ai_client    = new CV_AI();
	$plugin->post_builder = new CV_Builder();
	$plugin->ai_client->script = array( $tainted, '<!-- LUNARA_SKIP: no reader-worthy items -->' );
	$plugin->run( true );
	cv_check( array( $tainted ) === $plugin->post_builder->written, 'A revision that skips must not discard the entry.' );

	cv_finish( 'revision' );
}

fwrite( STDERR, "Unknown part: {$part}\n" );
exit( 1 );
