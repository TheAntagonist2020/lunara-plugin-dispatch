<?php
/**
 * Runtime contract for Dispatch's Foundation-ready summaries, immutable legacy
 * settings, provider-key ownership, and absent/incompatible recovery form.
 * Run: php tests/dispatch-foundation-ownership-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

$dispatch_admin_actions  = array();
$dispatch_admin_settings = array();
$dispatch_admin_options  = array(
	'lunara_dispatch_enabled'                => 0,
	'lunara_dispatch_schedule'               => 'twice_daily',
	'lunara_dispatch_provider'               => 'grok',
	'lunara_dispatch_max_tokens'             => 1337,
	'lunara_dispatch_claude_model'            => 'legacy-claude-model',
	'lunara_dispatch_openai_model'            => 'legacy-openai-model',
	'lunara_dispatch_gemini_model'            => 'legacy-gemini-model',
	'lunara_dispatch_grok_model'              => 'legacy-grok-model',
	'lunara_dispatch_voice_refinement'        => 'LEGACY VOICE SECRET',
	'lunara_dispatch_system_prompt_override'  => 'LEGACY PROMPT SECRET',
	'lunara_dispatch_claude_key'              => 'OLD-CLAUDE-SECRET',
	'lunara_dispatch_openai_key'              => 'OLD-OPENAI-SECRET',
	'lunara_dispatch_gemini_key'              => 'OLD-GEMINI-SECRET',
	'lunara_dispatch_grok_key'                => 'OLD-GROK-SECRET',
);
$dispatch_prompt_calls   = 0;
$failures                = array();

function dispatch_admin_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $dispatch_admin_actions;
	$dispatch_admin_actions[ $hook ][] = array( $callback, $priority, $accepted_args );
	return true;
}

function register_setting( $group, $option, $args = array() ) {
	global $dispatch_admin_settings;
	$dispatch_admin_settings[ $option ] = array( 'group' => $group, 'args' => $args );
	return true;
}

function add_options_page() {
	return 'settings_page_lunara-dispatch-settings';
}

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function wp_unslash( $value ) {
	return $value;
}

function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}

function get_option( $key, $default = false ) {
	global $dispatch_admin_options;
	return array_key_exists( $key, $dispatch_admin_options ) ? $dispatch_admin_options[ $key ] : $default;
}

function current_user_can( $capability ) {
	return 'manage_options' === $capability;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function esc_url( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_js( $value ) { return addslashes( (string) $value ); }

function checked( $checked, $current = true, $echo = true ) {
	$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $echo ) { echo $result; }
	return $result;
}

function selected( $selected, $current = true, $echo = true ) {
	$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $echo ) { echo $result; }
	return $result;
}

function disabled( $disabled, $current = true, $echo = true ) {
	$result = (bool) $disabled === (bool) $current ? ' disabled="disabled"' : '';
	if ( $echo ) { echo $result; }
	return $result;
}

function settings_fields( $group ) {
	echo '<input type="hidden" name="option_page" value="' . esc_attr( $group ) . '" />';
}

function submit_button( $text, $type = 'primary', $name = 'submit', $wrap = true, $other = array() ) {
	unset( $type, $other );
	$button = '<button type="submit" name="' . esc_attr( $name ) . '">' . esc_html( $text ) . '</button>';
	if ( $wrap ) { echo '<p class="submit">' . $button . '</p>'; } else { echo $button; }
}

function wp_nonce_field( $action, $name = '_wpnonce' ) {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce-' . esc_attr( $action ) . '" />';
}

function wp_create_nonce( $action ) { return 'nonce-' . $action; }
function wp_next_scheduled( $hook ) { return 'lunara_dispatch_scheduled' === $hook ? 1770000000 : false; }
function wp_date( $format, $timestamp ) { unset( $format, $timestamp ); return 'August 29, 2026 2:15 pm'; }
function get_date_from_gmt( $date, $format ) { unset( $format ); return $date; }
function post_type_exists( $post_type ) { return 'journal' === $post_type; }
function wp_reset_postdata() {}

class WP_Post {}

class WP_Query {
	public $posts = array();
	public function __construct( $args = array() ) { unset( $args ); }
}

final class Lunara_Dispatch_Control_Plane_Client {
	public static $present   = true;
	public static $available = true;
	public static $runtime_calls = 0;
	public static $legacy_calls  = 0;

	public static function foundation_present() { return self::$present; }
	public static function available() { return self::$available; }

	public static function runtime_config() {
		self::$runtime_calls++;
		if ( ! self::$available ) {
			return array(
				'protocol_version' => self::$present ? '2.0.0' : '',
				'config_version'   => 'incompatible',
				'enabled'          => false,
				'schedule'         => 'daily',
				'provider'         => 'openai',
				'models'           => array(),
				'max_tokens'       => 2200,
				'sources'          => array(),
				'compiled_system_prompt' => '',
				'compiled_user_directive_prompt' => '',
			);
		}
		return array(
			'protocol_version' => '1.2.2',
			'config_version'   => 'foundation-ready',
			'enabled'          => true,
			'schedule'         => 'every_4_hours',
			'provider'         => 'openai',
			'models'           => array(
				'openai' => 'foundation-active-model',
				'claude' => 'foundation-claude-model',
				'gemini' => 'foundation-gemini-model',
				'grok'   => 'foundation-grok-model',
			),
			'max_tokens'       => 2200,
			'sources'          => Lunara_Dispatch_Sources::$foundation_sources,
			'compiled_system_prompt' => 'FOUNDATION COMPILED PROMPT SECRET',
			'compiled_user_directive_prompt' => 'FOUNDATION DIRECTIVE SECRET',
		);
	}

	public static function legacy_runtime_config() {
		self::$legacy_calls++;
		return array(
			'protocol_version' => 'legacy',
			'config_version'   => 'legacy',
			'enabled'          => (bool) get_option( 'lunara_dispatch_enabled', 0 ),
			'schedule'         => get_option( 'lunara_dispatch_schedule', 'daily' ),
			'provider'         => get_option( 'lunara_dispatch_provider', 'openai' ),
			'models'           => array(
				'openai' => get_option( 'lunara_dispatch_openai_model', '' ),
				'claude' => get_option( 'lunara_dispatch_claude_model', '' ),
				'gemini' => get_option( 'lunara_dispatch_gemini_model', '' ),
				'grok'   => get_option( 'lunara_dispatch_grok_model', '' ),
			),
			'max_tokens'       => (int) get_option( 'lunara_dispatch_max_tokens', 2200 ),
			'sources'          => Lunara_Dispatch_Sources::$legacy_sources,
			'compiled_system_prompt' => 'LEGACY ASSEMBLED PROMPT SECRET',
			'compiled_user_directive_prompt' => 'LEGACY DIRECTIVE SECRET',
		);
	}
}

final class Lunara_Dispatch_Sources {
	public static $foundation_sources = array(
		array( 'id' => 'foundation-one', 'label' => 'FOUNDATION SOURCE LABEL SECRET', 'url' => 'https://foundation-one.example/feed', 'enabled' => true, 'max' => 10 ),
		array( 'id' => 'foundation-two', 'label' => 'FOUNDATION SECOND SOURCE SECRET', 'url' => 'https://foundation-two.example/feed', 'enabled' => true, 'max' => 10 ),
	);
	public static $legacy_sources = array(
		array( 'id' => 'legacy-one', 'label' => 'Legacy Recovery Feed', 'url' => 'https://legacy.example/feed', 'enabled' => true, 'max' => 7 ),
	);

	public static function all() {
		return Lunara_Dispatch_Control_Plane_Client::$available ? self::$foundation_sources : self::$legacy_sources;
	}

	public static function defaults() { return self::$legacy_sources; }
	public static function save_all( $sources ) { return $sources; }
}

final class Lunara_Dispatch_Prompts {
	public static function system_prompt() {
		global $dispatch_prompt_calls;
		$dispatch_prompt_calls++;
		return Lunara_Dispatch_Control_Plane_Client::$available ? 'FOUNDATION COMPILED PROMPT SECRET' : 'LEGACY ASSEMBLED PROMPT SECRET';
	}
}

final class Lunara_Dispatch_AI_Client {
	public static function secret_is_configured( $provider ) {
		return '' !== (string) get_option( 'lunara_dispatch_' . $provider . '_key', '' );
	}
}

class Dispatch_Admin_Feed_Fetcher {
	public function clear_seen_sources() { return 0; }
}

class Lunara_Dispatch_Plugin {
	const CRON_HOOK = 'lunara_dispatch_scheduled';
	public $feed_fetcher;
	public function __construct() { $this->feed_fetcher = new Dispatch_Admin_Feed_Fetcher(); }
	public function ensure_services() {}
	public function get_last_run_report() {
		return array(
			'timestamp_gmt' => '2026-08-29 14:15:16',
			'success' => true,
			'message' => 'Admin-only diagnostic note.',
			'ai_usage' => array( 'effective_model' => 'foundation-active-model', 'estimated_cost_usd' => 0.0042 ),
		);
	}
	public function get_status_label( $status ) { return $status; }
	public function queue_manual_run() { return array( 'success' => true ); }
}

require_once $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-admin.php';

$plugin = new Lunara_Dispatch_Plugin();
$admin  = new Lunara_Dispatch_Admin( $plugin );
$admin->register_settings();

$owned_options = array(
	'lunara_dispatch_enabled'               => 1,
	'lunara_dispatch_schedule'              => 'daily',
	'lunara_dispatch_provider'              => 'openai',
	'lunara_dispatch_max_tokens'            => 2200,
	'lunara_dispatch_claude_model'           => 'forged-claude',
	'lunara_dispatch_openai_model'           => 'forged-openai',
	'lunara_dispatch_gemini_model'           => 'forged-gemini',
	'lunara_dispatch_grok_model'             => 'forged-grok',
	'lunara_dispatch_voice_refinement'       => 'FORGED VOICE',
	'lunara_dispatch_system_prompt_override' => 'FORGED PROMPT',
);

foreach ( $owned_options as $option => $forged ) {
	$callback = $dispatch_admin_settings[ $option ]['args']['sanitize_callback'] ?? null;
	dispatch_admin_assert( is_callable( $callback ), $option . ' must retain a Settings API sanitizer.' );
	if ( is_callable( $callback ) ) {
		$sanitized = call_user_func( $callback, $forged );
		dispatch_admin_assert( get_option( $option ) === $sanitized, 'Foundation-ready forged options.php write changed ' . $option . '.' );
		$missing = call_user_func( $callback, null );
		dispatch_admin_assert( get_option( $option ) === $missing, 'Foundation-ready missing options.php field changed ' . $option . '.' );
	}
}

$openai_key_callback = $dispatch_admin_settings['lunara_dispatch_openai_key']['args']['sanitize_callback'] ?? null;
dispatch_admin_assert( is_callable( $openai_key_callback ), 'Dispatch-owned provider keys must retain their sanitizer.' );
if ( is_callable( $openai_key_callback ) ) {
	dispatch_admin_assert( 'NEW-OPENAI-SECRET' === call_user_func( $openai_key_callback, 'NEW-OPENAI-SECRET' ), 'Foundation-ready provider key updates must remain writable.' );
	dispatch_admin_assert( 'OLD-OPENAI-SECRET' === call_user_func( $openai_key_callback, '' ), 'Blank provider key submissions must preserve the existing secret.' );
}

ob_start();
$admin->settings_page();
$ready_html = ob_get_clean();

$duplicate_name_pattern = '/name=["\'](?:lunara_dispatch_(?:enabled|schedule|provider|max_tokens|(?:claude|openai|gemini|grok)_model|voice_refinement|system_prompt_override)|lds_sources\[)/i';
dispatch_admin_assert( 0 === preg_match( $duplicate_name_pattern, $ready_html ), 'Foundation-ready UI emitted a submittable duplicate configuration name.' );
foreach ( array( 'claude', 'openai', 'gemini', 'grok' ) as $provider ) {
	dispatch_admin_assert( false !== strpos( $ready_html, 'name="lunara_dispatch_' . $provider . '_key"' ), 'Foundation-ready UI must retain the ' . $provider . ' provider-key field.' );
}
dispatch_admin_assert( false !== strpos( $ready_html, 'https://example.test/wp-admin/edit.php?post_type=journal&amp;page=lunara-journal-control-plane' ), 'Foundation-ready summary must link to the canonical Journal Control Plane.' );
dispatch_admin_assert( false !== strpos( $ready_html, 'foundation-active-model' ) && false !== strpos( $ready_html, 'Every 4 Hours' ), 'Foundation-ready summary must identify the active model and schedule in plain language.' );
dispatch_admin_assert( false !== strpos( $ready_html, '2 enabled sources' ), 'Foundation-ready source summary must expose only the aggregate enabled count.' );
foreach ( array( 'FOUNDATION COMPILED PROMPT SECRET', 'FOUNDATION DIRECTIVE SECRET', 'LEGACY PROMPT SECRET', 'LEGACY VOICE SECRET', 'FOUNDATION SOURCE LABEL SECRET', 'https://foundation-one.example/feed', 'legacy-openai-model' ) as $secret ) {
	dispatch_admin_assert( false === strpos( $ready_html, $secret ), 'Foundation-ready summary leaked duplicate configuration data: ' . $secret );
}
dispatch_admin_assert( 0 === $dispatch_prompt_calls, 'Foundation-ready settings page must not load or render the assembled prompt.' );
foreach ( array( 'id="lunara-dispatch-run-now"', 'id="lunara-dispatch-reset-seen"', 'Visual Assignment Assistant', 'Automation Health' ) as $operation ) {
	dispatch_admin_assert( false !== strpos( $ready_html, $operation ), 'Dispatch-owned operation disappeared in ready mode: ' . $operation );
}

Lunara_Dispatch_Sources::$foundation_sources = array();
ob_start();
$admin->settings_page();
$empty_ready_html = ob_get_clean();
dispatch_admin_assert( false !== strpos( $empty_ready_html, '0 enabled sources' ), 'An empty authoritative Foundation source list must remain visibly empty.' );
dispatch_admin_assert( false === strpos( $empty_ready_html, 'Legacy Recovery Feed' ), 'An empty authoritative Foundation source list must not display preserved recovery feeds as live.' );
Lunara_Dispatch_Sources::$foundation_sources = array(
	array( 'id' => 'foundation-one', 'label' => 'FOUNDATION SOURCE LABEL SECRET', 'url' => 'https://foundation-one.example/feed', 'enabled' => true, 'max' => 10 ),
	array( 'id' => 'foundation-two', 'label' => 'FOUNDATION SECOND SOURCE SECRET', 'url' => 'https://foundation-two.example/feed', 'enabled' => true, 'max' => 10 ),
);

Lunara_Dispatch_Control_Plane_Client::$available = false;
Lunara_Dispatch_Control_Plane_Client::$present   = false;

$legacy_sanitize_cases = array(
	'lunara_dispatch_enabled'               => array( 'candidate' => 1, 'expected' => 1 ),
	'lunara_dispatch_schedule'              => array( 'candidate' => 'unsafe', 'expected' => 'daily' ),
	'lunara_dispatch_provider'              => array( 'candidate' => 'unsafe', 'expected' => 'openai' ),
	'lunara_dispatch_max_tokens'            => array( 'candidate' => 9999, 'expected' => 2200 ),
	'lunara_dispatch_openai_model'           => array( 'candidate' => '<b>recovery-model</b>', 'expected' => 'recovery-model' ),
	'lunara_dispatch_voice_refinement'       => array( 'candidate' => "  <b>Recovery</b>   voice\n\n\n\nline  ", 'expected' => "Recovery voice\n\nline" ),
	'lunara_dispatch_system_prompt_override' => array( 'candidate' => "  <b>Recovery</b>   prompt  ", 'expected' => 'Recovery prompt' ),
);
foreach ( $legacy_sanitize_cases as $option => $case ) {
	$callback = $dispatch_admin_settings[ $option ]['args']['sanitize_callback'];
	dispatch_admin_assert( $case['expected'] === call_user_func( $callback, $case['candidate'] ), 'Legacy fallback sanitizer changed behavior for ' . $option . '.' );
}

foreach ( array( 'absent' => false, 'incompatible' => true ) as $mode => $present ) {
	Lunara_Dispatch_Control_Plane_Client::$present = $present;
	Lunara_Dispatch_Control_Plane_Client::$legacy_calls = 0;
	ob_start();
	$admin->settings_page();
	$legacy_html = ob_get_clean();
	dispatch_admin_assert( 1 === preg_match( '/name="lunara_dispatch_enabled"/', $legacy_html ), ucfirst( $mode ) . ' Foundation must restore the legacy enabled control.' );
	dispatch_admin_assert( 1 === preg_match( '/name="lunara_dispatch_schedule"/', $legacy_html ) && false !== strpos( $legacy_html, 'value="twice_daily"  selected="selected"' ), ucfirst( $mode ) . ' Foundation must render the stored legacy schedule.' );
	dispatch_admin_assert( 1 === preg_match( '/name="lunara_dispatch_provider"/', $legacy_html ) && false !== strpos( $legacy_html, 'value="grok"  selected="selected"' ), ucfirst( $mode ) . ' Foundation must render the stored legacy provider.' );
	dispatch_admin_assert( false !== strpos( $legacy_html, 'name="lds_sources[0][url]"' ), ucfirst( $mode ) . ' Foundation must restore the legacy source editor.' );
	dispatch_admin_assert( false !== strpos( $legacy_html, 'LEGACY PROMPT SECRET' ) && false !== strpos( $legacy_html, 'LEGACY VOICE SECRET' ), ucfirst( $mode ) . ' Foundation must preserve visible recovery prompt values.' );
	dispatch_admin_assert( false === strpos( $legacy_html, '<strong>Managed by Journal Control Plane.</strong>' ), ucfirst( $mode ) . ' Foundation must not claim active Control Plane ownership.' );
	dispatch_admin_assert( Lunara_Dispatch_Control_Plane_Client::$legacy_calls > 0, ucfirst( $mode ) . ' Foundation must use legacy_runtime_config() rather than incompatible defaults.' );
	foreach ( array( 'id="lunara-dispatch-run-now"', 'id="lunara-dispatch-reset-seen"', 'Visual Assignment Assistant' ) as $operation ) {
		dispatch_admin_assert( false !== strpos( $legacy_html, $operation ), ucfirst( $mode ) . ' recovery mode lost Dispatch operation: ' . $operation );
	}
}

if ( $failures ) {
	fwrite( STDERR, "Dispatch Foundation ownership runtime failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Dispatch Foundation ownership runtime passed.\n";
