<?php
defined( 'ABSPATH' ) || exit;

/**
 * Coordinator for the approved File 01 v2 Future Foundation Superset.
 * The 18 capabilities remain governance/platform-engineering concerns only.
 */
final class SPF_Future_Foundation {
	public static function feature_catalog() {
		$names = array(
			'F01-FUT-001'=>'Constitution / Policy-as-Code Engine',
			'F01-FUT-002'=>'Amendment Impact Simulator',
			'F01-FUT-003'=>'Cross-Repository Architecture Linter',
			'F01-FUT-004'=>'Automatic Spec-to-Code Traceability Engine',
			'F01-FUT-005'=>'Internal Developer Portal / Service Catalog',
			'F01-FUT-006'=>'Golden-Path Module SDK & Scaffolder',
			'F01-FUT-007'=>'Contract Compatibility Laboratory',
			'F01-FUT-008'=>'Advanced Event Schema Registry & Replay Lab',
			'F01-FUT-009'=>'Configuration-as-Code + Environment Drift Detector',
			'F01-FUT-010'=>'Unified Cross-File Release Train Orchestrator',
			'F01-FUT-011'=>'Progressive Delivery Autopilot',
			'F01-FUT-012'=>'SLO / Error-Budget Release Gate',
			'F01-FUT-013'=>'Platform Digital Twin / Architecture Simulator',
			'F01-FUT-014'=>'Bounded Self-Healing Foundation Reconciler',
			'F01-FUT-015'=>'Chaos & Failure-Injection Test Harness',
			'F01-FUT-016'=>'OpenTelemetry-Compatible Context Fabric',
			'F01-FUT-017'=>'Time-Travel Governance & Configuration Snapshots',
			'F01-FUT-018'=>'AI Governance Copilot — Advisory Only',
		);
		$out = array();
		foreach ( $names as $id => $name ) {
			$n = (int) substr( $id, -3 );
			$out[ $id ] = array(
				'name'     => $name,
				'priority' => in_array( $n, array(1,2,3,4,7,8,9,10,16), true ) ? 'P0' : ( 18 === $n ? 'P2' : 'P1' ),
				'owner'    => 'file-01',
				'status'   => 'coded',
			);
		}
		return $out;
	}

	public static function register() {
		SPF_Governance_Control_Plane::ensure_defaults();
		add_filter( 'spf_authorization_policy_decision', array( 'SPF_Governance_Control_Plane', 'authorization_policy_decision' ), 10, 5 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( 'spf_future_foundation_tick', array( 'SPF_Resilience_Lab', 'periodic_tick' ) );
		if ( ! wp_next_scheduled( 'spf_future_foundation_tick' ) ) {
			wp_schedule_event( time() + 300, 'spf_five_minutes', 'spf_future_foundation_tick' );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'spf_future_foundation_tick' );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'spf_future_foundation_tick' );
			$timestamp = wp_next_scheduled( 'spf_future_foundation_tick' );
		}
	}

	public static function status() {
		$features = self::feature_catalog();
		return array(
			'future_foundation_version' => '2.0.0',
			'feature_count'             => count( $features ),
			'coded_count'               => count( array_filter( $features, static function ( $f ) { return 'coded' === ( $f['status'] ?? '' ); } ) ),
			'features'                  => $features,
			'policy_count'              => count( SPF_Governance_Control_Plane::list_policies() ),
			'event_schema_count'        => count( SPF_Platform_Engineering::list_event_schemas() ),
			'snapshot_count'            => count( SPF_Resilience_Lab::list_snapshots() ),
			'chaos_production_enabled'  => false,
			'ai_autonomous_changes'     => false,
			'ai_autonomous_approval'    => false,
			'ownership_boundary'        => 'File 01 governance/platform engineering only; native domain ownership preserved.',
		);
	}

	/**
	 * Small read-only control-plane surface. Mutating capabilities are provided
	 * through explicit PHP helpers with their native authorization/evidence gates.
	 */
	public static function register_rest() {
		register_rest_route( 'sabri-foundation/v2', '/future/status', array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => array( __CLASS__, 'can_view' ),
			'callback'            => static function () { return rest_ensure_response( self::status() ); },
		) );
		register_rest_route( 'sabri-foundation/v2', '/future/catalog', array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => array( __CLASS__, 'can_view' ),
			'callback'            => static function () { return rest_ensure_response( SPF_Platform_Engineering::service_catalog() ); },
		) );
		register_rest_route( 'sabri-foundation/v2', '/future/architecture-lint', array(
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => array( __CLASS__, 'can_view' ),
			'callback'            => static function () { return rest_ensure_response( SPF_Governance_Control_Plane::lint_runtime_architecture() ); },
		) );
	}

	public static function can_view() {
		return SPF_Authorization::can( 'view', array( 'object_id'=>'future-foundation' ), array( 'purpose'=>'future_foundation_rest_view' ) );
	}
}
