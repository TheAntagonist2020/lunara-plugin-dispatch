<?php
/**
 * Runtime contract for Dispatch's inert Site Studio contribution and redacted status API.
 * Run: php tests/dispatch-site-studio-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
define( 'LUNARA_DISPATCH_VERSION', '3.2.8' );

$dispatch_filters       = array();
$dispatch_status_reads  = array( 'options' => 0, 'schedules' => 0, 'reports' => 0, 'history' => 0, 'sources' => 0 );
$dispatch_next_run      = 1770000000;
$failures               = array();

function dispatch_site_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	global $dispatch_filters;
	$dispatch_filters[ $hook ][] = array( $callback, $priority, $accepted_args );
	return true;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function get_option( $key, $default = false ) {
	global $dispatch_status_reads;
	$dispatch_status_reads['options']++;
	return $default;
}

function wp_next_scheduled( $hook ) {
	global $dispatch_status_reads, $dispatch_next_run;
	$dispatch_status_reads['schedules']++;
	return 'lunara_dispatch_scheduled' === $hook ? $dispatch_next_run : false;
}

final class Lunara_Dispatch_Control_Plane_Client {
	public static $present   = true;
	public static $available = true;
	public static $enabled   = true;

	public static function foundation_present() {
		return self::$present;
	}

	public static function available() {
		return self::$available;
	}

	public static function enabled() {
		return self::$enabled;
	}
}

final class Lunara_Dispatch_Plugin {
	const CRON_HOOK        = 'lunara_dispatch_scheduled';
	const MANUAL_CRON_HOOK = 'lunara_dispatch_manual_requested';

	public static $report = array(
		'run_id'        => 'SECRET-RUN-ID-123',
		'timestamp_gmt' => '2026-08-29 14:15:16',
		'success'       => true,
		'message'       => 'RAW PROVIDER ERROR SECRET',
		'feed_errors'   => array( 'SECRET SOURCE' => 'SECRET FEED ERROR' ),
		'ai_error_code' => 'secret_provider_error',
		'ai_usage'      => array( 'response_id' => 'SECRET-RESPONSE-ID' ),
	);

	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function get_last_run_report() {
		global $dispatch_status_reads;
		$dispatch_status_reads['reports']++;
		return self::$report;
	}

	public function get_run_history() {
		global $dispatch_status_reads;
		$dispatch_status_reads['history']++;
		return array( self::$report );
	}
}

final class Lunara_Dispatch_Sources {
	public static function enabled() {
		global $dispatch_status_reads;
		$dispatch_status_reads['sources']++;
		return array(
			array( 'id' => 'secret-one', 'label' => 'SECRET SOURCE LABEL ONE', 'url' => 'https://secret-one.example/feed' ),
			array( 'id' => 'secret-two', 'label' => 'SECRET SOURCE LABEL TWO', 'url' => 'https://secret-two.example/feed' ),
		);
	}
}

$module = $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-site-studio.php';
if ( ! is_file( $module ) ) {
	fwrite( STDERR, "Dispatch Site Studio runtime failed:\n- Site Studio module is missing.\n" );
	exit( 1 );
}
require_once $module;

dispatch_site_assert( isset( $dispatch_filters['lunara_site_studio_surfaces'] ) && 1 === count( $dispatch_filters['lunara_site_studio_surfaces'] ), 'Bootstrap must register exactly one inert Site Studio filter.' );
dispatch_site_assert( array( 'options' => 0, 'schedules' => 0, 'reports' => 0, 'history' => 0, 'sources' => 0 ) === $dispatch_status_reads, 'Loading the compatibility module must not read runtime state.' );

$registration = $dispatch_filters['lunara_site_studio_surfaces'][0] ?? array();
dispatch_site_assert( 20 === ( $registration[1] ?? null ) && 1 === ( $registration[2] ?? null ), 'The contribution must use the stable priority-20, one-argument filter boundary.' );
$callback = $registration[0] ?? null;
dispatch_site_assert( is_callable( $callback ), 'The registered contribution callback must be callable without theme code.' );

$surfaces = is_callable( $callback ) ? call_user_func( $callback, array( 'existing-tool' => array( 'id' => 'existing-tool' ) ) ) : array();
$surface  = $surfaces['dispatch-automation'] ?? array();
dispatch_site_assert( isset( $surfaces['existing-tool'] ), 'Contribution must preserve existing registry destinations.' );
dispatch_site_assert( 'dispatch-automation' === ( $surface['id'] ?? '' ), 'The contributed destination ID must be dispatch-automation.' );
dispatch_site_assert( 'plugin:lunara-dispatch' === ( $surface['owner'] ?? '' ), 'The contribution must retain unique Dispatch ownership.' );
dispatch_site_assert( 'operations' === ( $surface['kind'] ?? '' ) && 'manage_options' === ( $surface['capability'] ?? '' ), 'The destination must remain a manage_options operations handoff.' );
dispatch_site_assert( false === ( $surface['supports_preview'] ?? null ), 'The operations destination must never claim an unsaved front-end preview.' );
dispatch_site_assert( 'caution' === ( $surface['danger_level'] ?? '' ), 'The destination must advertise its operational caution level.' );
dispatch_site_assert( 'options-general.php?page=lunara-dispatch-settings' === ( $surface['admin_url'] ?? '' ) && ( $surface['admin_url'] ?? '' ) === ( $surface['classic_url'] ?? null ), 'Canonical and Classic destinations must use the Dispatch settings URL.' );
dispatch_site_assert( is_callable( $surface['dependency_callback'] ?? null ) && true === call_user_func( $surface['dependency_callback'], $surface ), 'The active plugin contribution must expose a safe dependency callback.' );
dispatch_site_assert( is_callable( $surface['status_callback'] ?? null ), 'The redacted status callback must be callable.' );

$allowed_status_keys = array( 'state', 'label', 'message', 'updated_at', 'action_label', 'count', 'url' );
$secret_needles = array(
	'SECRET-RUN-ID-123', 'RAW PROVIDER ERROR SECRET', 'SECRET SOURCE', 'SECRET FEED ERROR',
	'secret_provider_error', 'SECRET-RESPONSE-ID', 'SECRET SOURCE LABEL ONE',
	'https://secret-one.example/feed', 'FOUNDATION SECRET PROMPT', 'lunara_dispatch_openai_key',
);

$status_callback = $surface['status_callback'] ?? null;
$status = is_callable( $status_callback ) ? call_user_func( $status_callback, $surface ) : array();
dispatch_site_assert( array() === array_values( array_diff( array_keys( $status ), $allowed_status_keys ) ), 'Ready status must use only the public allowlisted vocabulary.' );
dispatch_site_assert( 'ready' === ( $status['state'] ?? '' ) && 2 === ( $status['count'] ?? -1 ), 'A scheduled, enabled, healthy runtime must report ready with an aggregate source count.' );
dispatch_site_assert( '2026-08-29 14:15:16' === ( $status['updated_at'] ?? '' ), 'Ready status may expose only the bounded last-run timestamp.' );
dispatch_site_assert( 'https://example.test/wp-admin/options-general.php?page=lunara-dispatch-settings' === ( $status['url'] ?? '' ), 'Status action must use the same-origin canonical Dispatch URL.' );
$encoded_status = json_encode( $status );
foreach ( $secret_needles as $needle ) {
	dispatch_site_assert( false === strpos( $encoded_status, $needle ), 'Redacted ready status leaked forbidden data: ' . $needle );
}
dispatch_site_assert( 0 === $dispatch_status_reads['history'], 'Redacted status must never read raw run history.' );

Lunara_Dispatch_Plugin::$report['timestamp_gmt'] = 'SECRET-KEY-IN-MALFORMED-TIMESTAMP';
$malformed_timestamp = lunara_dispatch_redacted_runtime_status();
dispatch_site_assert( ! isset( $malformed_timestamp['updated_at'] ), 'Malformed report timestamps must be omitted rather than copied into redacted status.' );
dispatch_site_assert( false === strpos( json_encode( $malformed_timestamp ), 'SECRET-KEY-IN-MALFORMED-TIMESTAMP' ), 'Malformed report timestamps must never become a raw-report exfiltration channel.' );
Lunara_Dispatch_Plugin::$report['timestamp_gmt'] = '2026-08-29 14:15:16';

Lunara_Dispatch_Control_Plane_Client::$enabled = false;
$paused = lunara_dispatch_redacted_runtime_status();
dispatch_site_assert( 'paused' === ( $paused['state'] ?? '' ) && 0 === ( $paused['count'] ?? -1 ), 'A compatible but disabled runtime must report paused without enumerating source data.' );

Lunara_Dispatch_Control_Plane_Client::$enabled = true;
Lunara_Dispatch_Plugin::$report['success'] = false;
$attention = lunara_dispatch_redacted_runtime_status();
dispatch_site_assert( 'attention' === ( $attention['state'] ?? '' ), 'A failed last run must report generic attention without its raw error.' );
dispatch_site_assert( false === strpos( json_encode( $attention ), 'RAW PROVIDER ERROR SECRET' ), 'Attention status must not echo the raw last-run message.' );

Lunara_Dispatch_Plugin::$report['success'] = true;
$dispatch_next_run = false;
$unscheduled = lunara_dispatch_redacted_runtime_status();
dispatch_site_assert( 'attention' === ( $unscheduled['state'] ?? '' ), 'An enabled runtime with no scheduled worker must report attention.' );

$dispatch_next_run = 1770000000;
Lunara_Dispatch_Control_Plane_Client::$present = false;
Lunara_Dispatch_Control_Plane_Client::$available = false;
$absent = lunara_dispatch_redacted_runtime_status();
dispatch_site_assert( 'unavailable' === ( $absent['state'] ?? '' ) && 0 === ( $absent['count'] ?? -1 ), 'An absent Foundation must report unavailable without reading source rows.' );

Lunara_Dispatch_Control_Plane_Client::$present = true;
$incompatible = lunara_dispatch_redacted_runtime_status();
dispatch_site_assert( 'unavailable' === ( $incompatible['state'] ?? '' ), 'A present but protocol-incompatible Foundation must remain unavailable.' );
dispatch_site_assert( false === strpos( json_encode( $incompatible ), '2.0.0' ), 'Incompatible status must not disclose raw protocol payloads.' );
dispatch_site_assert( 0 === $dispatch_status_reads['history'], 'No status branch may read raw history.' );

if ( $failures ) {
	fwrite( STDERR, "Dispatch Site Studio runtime failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Dispatch Site Studio runtime passed.\n";
