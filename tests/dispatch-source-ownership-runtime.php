<?php
/**
 * Runtime contract for Foundation-owned source immutability and legacy recovery.
 * Run: php tests/dispatch-source-ownership-runtime.php
 */

$root = dirname( __DIR__ );
define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );

$dispatch_source_options = array();
$dispatch_source_writes  = array();
$failures                = array();

function dispatch_source_assert( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

function sanitize_key( $value ) {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $value ) );
}

function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}

function sanitize_title( $value ) {
	$value = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', (string) $value ), '-' ) );
	return $value;
}

function esc_url_raw( $value ) {
	$value = trim( (string) $value );
	return preg_match( '#^https?://#i', $value ) ? $value : '';
}

function wp_generate_password() {
	return 'generated-id';
}

function get_option( $key, $default = false ) {
	global $dispatch_source_options;
	return array_key_exists( $key, $dispatch_source_options ) ? $dispatch_source_options[ $key ] : $default;
}

function update_option( $key, $value, $autoload = null ) {
	global $dispatch_source_options, $dispatch_source_writes;
	$dispatch_source_options[ $key ] = $value;
	$dispatch_source_writes[] = array( $key, $value, $autoload );
	return true;
}

final class Lunara_Journal_Control_Plane {
	public static $runtime = array();

	public static function get_dispatch_runtime_config() {
		return self::$runtime;
	}
}

require_once $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-control-plane-client.php';
require_once $root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'class-sources.php';

$legacy_sources = array(
	array(
		'id'                     => 'legacy-feed',
		'label'                  => 'Legacy Feed',
		'url'                    => 'https://legacy.example/feed',
		'enabled'                => true,
		'max'                    => 7,
		'priority'               => 4,
		'image_reuse_allowed'    => false,
		'image_import_disabled'  => true,
	),
);

Lunara_Journal_Control_Plane::$runtime = array(
	'protocol_version'                => '1.2.2',
	'config_version'                  => 'foundation-ready',
	'enabled'                         => true,
	'schedule'                        => 'every_4_hours',
	'target_post_type'                => 'journal',
	'post_status'                     => 'draft',
	'provider'                        => 'openai',
	'models'                          => array( 'openai' => 'foundation-model' ),
	'max_tokens'                      => 2200,
	'sources'                         => array(
		array(
			'id'       => 'foundation-feed',
			'label'    => 'Foundation Private Source',
			'url'      => 'https://foundation-secret.example/feed',
			'enabled'  => true,
			'max'      => 11,
			'priority' => 8,
		),
	),
	'compiled_system_prompt'          => 'FOUNDATION SECRET PROMPT',
	'compiled_user_directive_prompt'  => 'FOUNDATION SECRET DIRECTIVE',
);

$dispatch_source_options[ Lunara_Dispatch_Sources::OPTION ] = $legacy_sources;
$dispatch_source_writes = array();
$blocked_result = Lunara_Dispatch_Sources::save_all( array(
	array(
		'id'       => 'forged-feed',
		'label'    => 'Forged Feed',
		'url'      => 'https://forged.example/feed',
		'enabled'  => false,
		'max'      => 50,
		'priority' => 10,
	),
) );

dispatch_source_assert( 0 === count( $dispatch_source_writes ), 'Foundation-ready save_all() must perform zero legacy option writes.' );
dispatch_source_assert( $legacy_sources === $dispatch_source_options[ Lunara_Dispatch_Sources::OPTION ], 'Foundation-ready save_all() must preserve the exact stored recovery source option.' );
dispatch_source_assert( 'legacy-feed' === ( $blocked_result[0]['id'] ?? '' ), 'A blocked save must return normalized legacy recovery sources, not the forged or Foundation list.' );

$authoritative = Lunara_Dispatch_Sources::all();
dispatch_source_assert( 'foundation-feed' === ( $authoritative[0]['id'] ?? '' ), 'Ready reads must continue consuming Foundation sources.' );

Lunara_Journal_Control_Plane::$runtime['sources'] = array();
$dispatch_source_options[ Lunara_Dispatch_Sources::OPTION ] = $legacy_sources;
dispatch_source_assert( array() === Lunara_Dispatch_Sources::all(), 'An intentionally empty authoritative Foundation source list must not fall through to legacy recovery feeds.' );
Lunara_Journal_Control_Plane::$runtime['sources'] = array(
	array(
		'id'       => 'foundation-feed',
		'label'    => 'Foundation Private Source',
		'url'      => 'https://foundation-secret.example/feed',
		'enabled'  => true,
		'max'      => 11,
		'priority' => 8,
	),
);

unset( $dispatch_source_options[ Lunara_Dispatch_Sources::OPTION ] );
$dispatch_source_writes = array();
Lunara_Dispatch_Sources::install_defaults_if_empty();
dispatch_source_assert( 0 === count( $dispatch_source_writes ), 'Foundation-ready activation must not seed or replace the legacy source option.' );
dispatch_source_assert( ! array_key_exists( Lunara_Dispatch_Sources::OPTION, $dispatch_source_options ), 'Foundation-ready default installation must leave an absent recovery option absent.' );

Lunara_Journal_Control_Plane::$runtime = array(
	'protocol_version' => '2.0.0',
);
$dispatch_source_options[ Lunara_Dispatch_Sources::OPTION ] = $legacy_sources;
$dispatch_source_writes = array();
$legacy_result = Lunara_Dispatch_Sources::save_all( array(
	array(
		'id'                    => 'Recovery Feed!',
		'label'                 => '<b>Recovery Feed</b>',
		'url'                   => 'https://recovery.example/feed',
		'enabled'               => true,
		'max'                   => 99,
		'priority'              => 0,
		'image_reuse_allowed'   => true,
	),
	array(
		'id'      => 'unsafe',
		'label'   => 'Unsafe',
		'url'     => 'javascript:alert(1)',
		'enabled' => true,
	),
) );

dispatch_source_assert( 1 === count( $dispatch_source_writes ), 'Protocol-incompatible Foundation must retain one working legacy recovery write.' );
dispatch_source_assert( 'recoveryfeed' === ( $legacy_result[0]['id'] ?? '' ), 'Legacy recovery IDs must remain sanitized.' );
dispatch_source_assert( 'Recovery Feed' === ( $legacy_result[0]['label'] ?? '' ), 'Legacy recovery labels must remain sanitized.' );
dispatch_source_assert( 50 === ( $legacy_result[0]['max'] ?? 0 ), 'Legacy recovery max items must remain capped at 50.' );
dispatch_source_assert( 1 === ( $legacy_result[0]['priority'] ?? 0 ), 'Legacy recovery priority must remain bounded at 1.' );
dispatch_source_assert( 1 === count( $legacy_result ), 'Unsafe legacy recovery URLs must still be rejected.' );
dispatch_source_assert( $legacy_result === $dispatch_source_options[ Lunara_Dispatch_Sources::OPTION ], 'Legacy recovery save must persist its normalized result.' );

Lunara_Journal_Control_Plane::$runtime = null;
$dispatch_source_writes = array();
$absent_result = Lunara_Dispatch_Sources::save_all( $legacy_sources );
dispatch_source_assert( 1 === count( $dispatch_source_writes ) && 'legacy-feed' === ( $absent_result[0]['id'] ?? '' ), 'An unavailable Foundation response must preserve the legacy recovery save path.' );

if ( $failures ) {
	fwrite( STDERR, "Dispatch source ownership runtime failed:\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo "Dispatch source ownership runtime passed.\n";
