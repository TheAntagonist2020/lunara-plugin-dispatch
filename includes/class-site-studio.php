<?php
/**
 * Read-only Site Studio handoff and redacted Dispatch runtime status.
 *
 * This module owns no theme state. Registering the filter is inert until a
 * compatible theme asks for contributed destinations.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical purpose-built Dispatch tool.
 *
 * @return string
 */
function lunara_dispatch_settings_admin_url() {
	return admin_url( 'options-general.php?page=lunara-dispatch-settings' );
}

/**
 * The contribution itself proves the owning plugin is active. Foundation is
 * deliberately not a dependency of the destination because the same screen
 * carries credentials, diagnostics, and legacy recovery controls.
 *
 * @param array $surface Normalized surface metadata supplied by the theme.
 * @return bool
 */
function lunara_dispatch_site_studio_dependency( $surface = array() ) {
	unset( $surface );
	return true;
}

/**
 * Return only aggregate, human-readable runtime health. Raw reports, history,
 * prompts, source records, identifiers, provider errors, and credentials are
 * intentionally outside this API.
 *
 * @param array $surface Normalized surface metadata supplied by the theme.
 * @return array<string,int|string>
 */
function lunara_dispatch_redacted_runtime_status( $surface = array() ) {
	unset( $surface );
	$status = array(
		'state'        => 'unavailable',
		'label'        => 'Journal Foundation required',
		'message'      => 'Legacy settings are preserved, but Dispatch runs remain stopped until Journal Foundation is compatible.',
		'action_label' => 'Open Dispatch',
		'count'        => 0,
		'url'          => lunara_dispatch_settings_admin_url(),
	);

	$client_ready = class_exists( 'Lunara_Dispatch_Control_Plane_Client' )
		&& method_exists( 'Lunara_Dispatch_Control_Plane_Client', 'available' )
		&& Lunara_Dispatch_Control_Plane_Client::available();
	if ( ! $client_ready ) {
		return $status;
	}

	$enabled = method_exists( 'Lunara_Dispatch_Control_Plane_Client', 'enabled' )
		&& Lunara_Dispatch_Control_Plane_Client::enabled();
	if ( ! $enabled ) {
		$status['state']   = 'paused';
		$status['label']   = 'Automation paused';
		$status['message'] = 'Dispatch is available, but automation is turned off in Journal Control Plane.';
		return $status;
	}

	$status['count'] = class_exists( 'Lunara_Dispatch_Sources' )
		? count( Lunara_Dispatch_Sources::enabled() )
		: 0;
	$report = class_exists( 'Lunara_Dispatch_Plugin' )
		? Lunara_Dispatch_Plugin::instance()->get_last_run_report()
		: array();
	$report = is_array( $report ) ? $report : array();
	$timestamp_gmt = isset( $report['timestamp_gmt'] ) && is_scalar( $report['timestamp_gmt'] )
		? trim( (string) $report['timestamp_gmt'] )
		: '';
	if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $timestamp_gmt ) ) {
		$status['updated_at'] = $timestamp_gmt;
	}

	if ( ( isset( $report['success'] ) && ! $report['success'] ) || ! empty( $report['retry_required'] ) ) {
		$status['state']   = 'attention';
		$status['label']   = 'Run needs attention';
		$status['message'] = 'The last completed Dispatch run needs attention. Open Dispatch for its private diagnostics.';
		return $status;
	}

	$scheduled = defined( 'Lunara_Dispatch_Plugin::CRON_HOOK' )
		? wp_next_scheduled( Lunara_Dispatch_Plugin::CRON_HOOK )
		: false;
	if ( ! $scheduled ) {
		$status['state']   = 'attention';
		$status['label']   = 'Schedule needs attention';
		$status['message'] = 'Automation is enabled, but its scheduled worker is not currently registered.';
		return $status;
	}

	$status['state']   = 'ready';
	$status['label']   = 'Automation ready';
	$status['message'] = 'Dispatch is enabled, scheduled, and connected to Journal Foundation.';
	return $status;
}

/**
 * Add the Dispatch operations handoff without calling any theme-owned API.
 *
 * @param mixed $surfaces Existing registry value.
 * @return array<string,array<string,mixed>>
 */
function lunara_dispatch_contribute_site_studio_surface( $surfaces ) {
	$surfaces = is_array( $surfaces ) ? $surfaces : array();
	$surfaces['dispatch-automation'] = array(
		'id'                  => 'dispatch-automation',
		'group'               => 'Journal Workflow',
		'label'               => 'Dispatch Automation',
		'description'         => 'Check automation health, manage provider keys, queue a run, and resolve missing artwork.',
		'aliases'             => array( 'dispatch', 'automation', 'journal', 'provider keys', 'run now', 'reset seen', 'visual assignments' ),
		'owner'               => 'plugin:lunara-dispatch',
		'kind'                => 'operations',
		'capability'          => 'manage_options',
		'supports_preview'    => false,
		'preview_route'       => '',
		'admin_url'           => 'options-general.php?page=lunara-dispatch-settings',
		'dependency_callback' => 'lunara_dispatch_site_studio_dependency',
		'status_callback'     => 'lunara_dispatch_redacted_runtime_status',
		'danger_level'        => 'caution',
		'sections'            => array( 'automation-health', 'provider-keys', 'visual-assignment', 'manual-run' ),
		'classic_url'         => 'options-general.php?page=lunara-dispatch-settings',
	);
	return $surfaces;
}

add_filter( 'lunara_site_studio_surfaces', 'lunara_dispatch_contribute_site_studio_surface', 20, 1 );
