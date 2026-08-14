<?php
/**
 * Runtime contract for the cost-guarded OpenAI Responses integration.
 * Run: php tests/openai-cost-guard-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

$openai_request = array();
$openai_response = array(
	'code' => 200,
	'body' => array(
		'id' => 'resp_test_1',
		'output' => array(
			array(
				'type' => 'message',
				'content' => array(
					array( 'type' => 'output_text', 'text' => '<h2>A Strong Journal Headline</h2><p>Draft copy.</p>' ),
				),
			),
		),
		'usage' => array(
			'input_tokens' => 4000,
			'input_tokens_details' => array( 'cached_tokens' => 1000 ),
			'output_tokens' => 1000,
		),
	),
);

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function get_option( $key, $default = false ) {
	return 'lunara_dispatch_openai_key' === $key ? 'test-key' : $default;
}
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_safe_remote_post( $url, $args ) {
	global $openai_request, $openai_response;
	$openai_request = array( 'url' => $url, 'args' => $args );
	return array(
		'response' => array( 'code' => $openai_response['code'] ),
		'body' => json_encode( $openai_response['body'] ),
	);
}
function wp_remote_retrieve_response_code( $response ) { return (int) $response['response']['code']; }
function wp_remote_retrieve_body( $response ) { return (string) $response['body']; }

final class Lunara_Dispatch_Prompts {
	public static function system_prompt() { return 'Write a provocative, honest Journal draft.'; }
	public static function user_directive_prompt() { return 'Use only supplied sources.'; }
	public static function user_directive( $news_data ) { return "NEWS\n" . $news_data; }
}

final class Lunara_Dispatch_Control_Plane_Client {
	public static $model = 'gpt-4.1';
	public static function provider() { return 'openai'; }
	public static function max_tokens() { return 4096; }
	public static function model_for_provider() { return self::$model; }
}

require_once $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-ai-client.php';

$client = new Lunara_Dispatch_AI_Client();
$result = $client->generate( str_repeat( 'source ', 5000 ) );
if ( is_wp_error( $result ) || false === strpos( $result, 'A Strong Journal Headline' ) ) {
	fwrite( STDERR, "OpenAI Responses success payload did not parse.\n" );
	exit( 1 );
}

$body = json_decode( $openai_request['args']['body'], true );
if ( 'https://api.openai.com/v1/responses' !== $openai_request['url'] ) {
	fwrite( STDERR, "Dispatch did not use the Responses API.\n" );
	exit( 1 );
}
if ( 'gpt-5.4-mini' !== ( $body['model'] ?? '' ) ) {
	fwrite( STDERR, "Expensive configured model was not replaced by the safe default.\n" );
	exit( 1 );
}
if ( 2200 !== ( $body['max_output_tokens'] ?? 0 ) || false !== ( $body['store'] ?? true ) ) {
	fwrite( STDERR, "OpenAI output/storage cost controls are missing.\n" );
	exit( 1 );
}
if ( strlen( (string) ( $body['input'] ?? '' ) ) > 18010 ) {
	fwrite( STDERR, "OpenAI input character ceiling was not enforced.\n" );
	exit( 1 );
}
if ( 'none' !== ( $body['reasoning']['effort'] ?? '' ) || 'low' !== ( $body['text']['verbosity'] ?? '' ) ) {
	fwrite( STDERR, "OpenAI reasoning/verbosity cost controls are missing.\n" );
	exit( 1 );
}

$usage = $client->get_last_usage();
if ( 'gpt-4.1' !== ( $usage['requested_model'] ?? '' ) || 'gpt-5.4-mini' !== ( $usage['effective_model'] ?? '' ) ) {
	fwrite( STDERR, "Requested and effective models were not recorded.\n" );
	exit( 1 );
}
if ( 0.006825 !== ( $usage['estimated_cost_usd'] ?? null ) ) {
	fwrite( STDERR, "Usage cost estimate is incorrect.\n" );
	exit( 1 );
}

Lunara_Dispatch_Control_Plane_Client::$model = 'gpt-5.4-nano';
$client->generate( 'A bounded source packet.' );
$body = json_decode( $openai_request['args']['body'], true );
if ( 'gpt-5.4-nano' !== ( $body['model'] ?? '' ) ) {
	fwrite( STDERR, "Allowed cost-controlled model was unexpectedly replaced.\n" );
	exit( 1 );
}

$openai_response = array(
	'code' => 400,
	'body' => array(
		'error' => array(
			'type' => 'insufficient_quota',
			'code' => 'insufficient_quota',
			'message' => 'No credits remain.',
		),
	),
);
$billing = $client->generate( 'A bounded source packet.' );
if ( ! is_wp_error( $billing ) || 'ai_billing_error' !== $billing->get_error_code() ) {
	fwrite( STDERR, "Billing exhaustion was not classified for safe fallback.\n" );
	exit( 1 );
}

echo "OpenAI cost guard runtime passed.\n";
