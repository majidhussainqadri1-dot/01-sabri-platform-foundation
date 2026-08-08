<?php
defined( 'ABSPATH' ) || exit;

/**
 * Future Foundation governance plane.
 *
 * Implements the File 01-owned parts of:
 * - Constitution / Policy-as-Code
 * - Amendment Impact Simulator
 * - Cross-Repository Architecture Linter
 * - Spec-to-Code Traceability
 * - Advisory-only AI Governance Copilot
 *
 * This class can deny unsafe actions, but it never grants authorization and
 * never writes another module's canonical data.
 */
final class SPF_Governance_Control_Plane {
	const POLICY_OPTION = 'spf_future_policy_catalog';
	const TRACE_OPTION  = 'spf_future_traceability_evidence';

	public static function default_policies() {
		return array(
			array(
				'id'          => 'F01-POL-OWNERSHIP-001',
				'title'       => 'Canonical ownership must not be bypassed',
				'effect'      => 'deny',
				'actions'     => array( 'direct_foreign_write', 'duplicate_owner_claim' ),
				'owner'       => 'file-01',
				'decision_id' => 'P-05',
				'priority'    => 100,
			),
			array(
				'id'          => 'F01-POL-RELEASE-001',
				'title'       => 'Release evidence must remain truthful',
				'effect'      => 'deny',
				'actions'     => array( 'claim_staging_accepted_without_evidence', 'claim_live_without_founder_approval' ),
				'owner'       => 'file-01',
				'decision_id' => 'P-09',
				'priority'    => 100,
			),
			array(
				'id'          => 'F01-POL-SHELL-001',
				'title'       => 'File 20 remains the only application shell owner',
				'effect'      => 'deny',
				'actions'     => array( 'claim_global_shell', 'claim_global_navigation' ),
				'owner'       => 'file-20',
				'decision_id' => 'R-07',
				'priority'    => 90,
			),
		);
	}

	public static function ensure_defaults() {
		$current = get_option( self::POLICY_OPTION, null );
		if ( null === $current ) {
			add_option( self::POLICY_OPTION, self::default_policies(), '', 'no' );
		}
	}

	public static function list_policies() {
		$policies = get_option( self::POLICY_OPTION, self::default_policies() );
		return is_array( $policies ) ? array_values( $policies ) : self::default_policies();
	}

	public static function save_policy( array $policy ) {
		$allowed = SPF_Authorization::require_action(
			'repair_owned_mapping',
			array( 'module_key' => 'file-01', 'object_id' => 'policy-as-code' ),
			array( 'purpose' => 'policy_as_code' )
		);
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$normalized = self::normalize_policy( $policy );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$policies = self::list_policies();
		$found = false;
		foreach ( $policies as $index => $existing ) {
			if ( ( $existing['id'] ?? '' ) === $normalized['id'] ) {
				$policies[ $index ] = $normalized;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			$policies[] = $normalized;
		}
		usort( $policies, static function ( $a, $b ) {
			return (int) ( $b['priority'] ?? 0 ) <=> (int) ( $a['priority'] ?? 0 );
		} );
		update_option( self::POLICY_OPTION, array_slice( $policies, 0, 250 ), false );
		return $normalized;
	}

	public static function evaluate_policy( $action, array $context = array(), $policies = null ) {
		$action = sanitize_key( $action );
		$policies = is_array( $policies ) ? $policies : self::list_policies();
		$result = array(
			'action'     => $action,
			'decision'   => 'abstain',
			'policy_id'  => '',
			'decision_id'=> '',
			'reason'     => 'No matching File 01 policy.',
		);
		foreach ( $policies as $policy ) {
			$policy = self::normalize_policy( (array) $policy );
			if ( is_wp_error( $policy ) || ! in_array( $action, $policy['actions'], true ) ) {
				continue;
			}
			if ( ! self::policy_context_matches( $policy, $context ) ) {
				continue;
			}
			$result = array(
				'action'      => $action,
				'decision'    => 'deny' === $policy['effect'] ? 'deny' : 'require-review',
				'policy_id'   => $policy['id'],
				'decision_id' => $policy['decision_id'],
				'reason'      => $policy['title'],
			);
			break;
		}
		return $result;
	}

	/**
	 * Authorization filter: policy-as-code is deny-only. It cannot grant access.
	 */
	public static function authorization_policy_decision( $existing, $action, $object, $user_id, $context ) {
		if ( false === $existing ) {
			return false;
		}
		$policy_context = is_array( $context ) ? $context : array();
		$policy_context['object']  = $object;
		$policy_context['user_id'] = absint( $user_id );
		$decision = self::evaluate_policy( $action, $policy_context );
		return 'deny' === $decision['decision'] ? false : $existing;
	}

	public static function simulate_amendment( array $amendment, array $inventory = array() ) {
		$amendment = SPF_Runtime::canonicalize( $amendment );
		$affected = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $amendment['affected_files'] ?? array() ) ) ) ) );
		$changed_contracts = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $amendment['contracts'] ?? array() ) ) ) ) );
		$changed_routes = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $amendment['routes'] ?? array() ) ) ) ) );
		$inventory = $inventory ?: self::runtime_inventory();
		$dependents = array();
		foreach ( (array) ( $inventory['modules'] ?? array() ) as $module ) {
			$key = sanitize_key( $module['module_key'] ?? '' );
			$manifest = (array) ( $module['manifest'] ?? $module );
			$deps = array_merge( (array) ( $manifest['required'] ?? array() ), (array) ( $manifest['optional'] ?? array() ) );
			foreach ( $deps as $dep ) {
				$dep_key = sanitize_key( is_array( $dep ) ? ( $dep['module_key'] ?? '' ) : $dep );
				if ( $key && in_array( $dep_key, $affected, true ) ) {
					$dependents[ $key ][] = $dep_key;
				}
			}
		}
		$risk = 0;
		$risk += count( $affected ) * 2;
		$risk += count( $changed_contracts ) * 3;
		$risk += count( $changed_routes ) * 2;
		$risk += count( $dependents ) * 2;
		if ( ! empty( $amendment['security_privacy_impact'] ) ) {
			$risk += 5;
		}
		if ( ! empty( $amendment['migration_required'] ) ) {
			$risk += 4;
		}
		return array(
			'amendment_id'       => sanitize_text_field( $amendment['decision_id'] ?? $amendment['id'] ?? 'unassigned' ),
			'affected_files'      => $affected,
			'dependent_modules'   => $dependents,
			'contracts'           => $changed_contracts,
			'routes'              => $changed_routes,
			'requires_migration'  => ! empty( $amendment['migration_required'] ),
			'requires_rollback'   => ! empty( $amendment['migration_required'] ) || ! empty( $changed_contracts ) || ! empty( $changed_routes ),
			'required_test_gates' => self::impact_test_gates( $amendment, $changed_contracts, $changed_routes ),
			'risk_score'          => min( 100, $risk ),
			'risk_band'           => $risk >= 15 ? 'high' : ( $risk >= 7 ? 'medium' : 'low' ),
			'simulation_only'     => true,
		);
	}

	public static function lint_runtime_architecture() {
		return self::lint_architecture( self::runtime_inventory() );
	}

	public static function lint_architecture( array $inventory ) {
		$findings = array();
		$owner_claims = array();
		$route_claims = array();
		foreach ( (array) ( $inventory['modules'] ?? array() ) as $module ) {
			$key = sanitize_key( $module['module_key'] ?? '' );
			$manifest = (array) ( $module['manifest'] ?? $module );
			foreach ( (array) ( $manifest['canonical_entities'] ?? array() ) as $entity ) {
				$entity = sanitize_key( is_array( $entity ) ? ( $entity['key'] ?? '' ) : $entity );
				if ( $entity ) {
					$owner_claims[ $entity ][] = $key;
				}
			}
			foreach ( (array) ( $manifest['writes'] ?? array() ) as $write ) {
				$target = sanitize_key( is_array( $write ) ? ( $write['owner_module'] ?? '' ) : '' );
				if ( $target && $target !== $key ) {
					$findings[] = self::finding( 'high', 'foreign_direct_write', $key, 'Manifest declares a write to another canonical owner.', array( 'target' => $target ) );
				}
			}
		}
		foreach ( $owner_claims as $entity => $owners ) {
			$owners = array_values( array_unique( array_filter( $owners ) ) );
			if ( count( $owners ) > 1 ) {
				$findings[] = self::finding( 'critical', 'duplicate_canonical_owner', $entity, 'Multiple modules claim one canonical entity.', array( 'owners' => $owners ) );
			}
		}
		foreach ( (array) ( $inventory['routes'] ?? array() ) as $route ) {
			$path = trim( (string) ( $route['route_path'] ?? '' ) );
			$owner = sanitize_key( $route['owner_module'] ?? '' );
			if ( $path ) {
				$route_claims[ $path ][] = $owner;
			}
		}
		foreach ( $route_claims as $path => $owners ) {
			$owners = array_values( array_unique( array_filter( $owners ) ) );
			if ( count( $owners ) > 1 ) {
				$findings[] = self::finding( 'high', 'duplicate_route_owner', $path, 'One canonical route is claimed by multiple owners.', array( 'owners' => $owners ) );
			}
		}
		$known_shell_owners = array_values( array_filter( array_map( 'sanitize_key', (array) ( $inventory['global_shell_owners'] ?? array() ) ) ) );
		if ( count( array_unique( $known_shell_owners ) ) > 1 || ( $known_shell_owners && 'file-20' !== $known_shell_owners[0] ) ) {
			$findings[] = self::finding( 'critical', 'shell_owner_violation', 'global-shell', 'File 20 must remain the only application-shell owner.', array( 'owners' => $known_shell_owners ) );
		}
		return array(
			'pass'        => empty( $findings ),
			'finding_count'=> count( $findings ),
			'findings'    => $findings,
			'inventory_hash' => SPF_Runtime::hash( $inventory ),
		);
	}

	public static function build_traceability_report( array $requirements, array $evidence ) {
		$rows = array();
		$missing = array();
		foreach ( $requirements as $requirement ) {
			$id = sanitize_text_field( is_array( $requirement ) ? ( $requirement['id'] ?? '' ) : $requirement );
			if ( '' === $id ) {
				continue;
			}
			$item = (array) ( $evidence[ $id ] ?? array() );
			$status = array(
				'requirement' => $id,
				'design'      => ! empty( $item['design'] ),
				'code'        => ! empty( $item['code'] ),
				'test'        => ! empty( $item['test'] ),
				'package'     => ! empty( $item['package'] ),
				'staging'     => ! empty( $item['staging'] ),
				'approval'    => ! empty( $item['approval'] ),
			);
			$status['coded_complete'] = $status['design'] && $status['code'] && $status['test'];
			$status['production_complete'] = $status['coded_complete'] && $status['package'] && $status['staging'] && $status['approval'];
			$rows[] = $status;
			if ( ! $status['coded_complete'] ) {
				$missing[] = $id;
			}
		}
		$total = count( $rows );
		$coded = count( array_filter( $rows, static function ( $row ) { return ! empty( $row['coded_complete'] ); } ) );
		$production = count( array_filter( $rows, static function ( $row ) { return ! empty( $row['production_complete'] ); } ) );
		return array(
			'total'                 => $total,
			'coded_complete'        => $coded,
			'production_complete'   => $production,
			'coded_percentage'      => $total ? round( ( $coded / $total ) * 100, 2 ) : 0,
			'production_percentage' => $total ? round( ( $production / $total ) * 100, 2 ) : 0,
			'missing_coded_evidence'=> $missing,
			'rows'                  => $rows,
		);
	}

	public static function advisory_copilot( array $input ) {
		$advice = array();
		$inventory = (array) ( $input['inventory'] ?? array() );
		if ( $inventory ) {
			$lint = self::lint_architecture( $inventory );
			foreach ( $lint['findings'] as $finding ) {
				$advice[] = array(
					'severity' => $finding['severity'],
					'code'     => 'architecture:' . $finding['code'],
					'message'  => $finding['message'],
					'action'   => 'Review and correct before release.',
				);
			}
		}
		if ( ! empty( $input['amendment'] ) ) {
			$impact = self::simulate_amendment( (array) $input['amendment'], $inventory );
			if ( 'high' === $impact['risk_band'] ) {
				$advice[] = array( 'severity'=>'high', 'code'=>'amendment:high-impact', 'message'=>'The amendment has a high cross-file impact score.', 'action'=>'Require explicit migration, rollback and cross-file tests.' );
			}
		}
		if ( ! empty( $input['traceability'] ) && is_array( $input['traceability'] ) ) {
			$trace = $input['traceability'];
			if ( ! empty( $trace['missing_coded_evidence'] ) ) {
				$advice[] = array( 'severity'=>'medium', 'code'=>'traceability:missing-evidence', 'message'=>'Some requirements do not have complete design/code/test evidence.', 'action'=>'Close evidence gaps before claiming coded completion.' );
			}
		}
		$external = apply_filters( 'spf_ai_governance_advisor', array(), $input, $advice );
		if ( is_array( $external ) ) {
			foreach ( array_slice( $external, 0, 50 ) as $item ) {
				if ( is_array( $item ) ) {
					$advice[] = array(
						'severity' => sanitize_key( $item['severity'] ?? 'info' ),
						'code'     => sanitize_text_field( $item['code'] ?? 'external-advice' ),
						'message'  => sanitize_text_field( $item['message'] ?? '' ),
						'action'   => sanitize_text_field( $item['action'] ?? '' ),
					);
				}
			}
		}
		return array(
			'advisory_only'        => true,
			'autonomous_changes'   => false,
			'autonomous_approval'  => false,
			'items'                => $advice,
			'generated_at'         => function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
		);
	}

	private static function runtime_inventory() {
		$modules = array();
		foreach ( SPF_Registry::list_modules( array( 'limit' => 200 ) ) as $module ) {
			if ( ! is_array( $module ) ) {
				continue;
			}
			if ( empty( $module['manifest'] ) && ! empty( $module['manifest_json'] ) ) {
				$decoded = json_decode( (string) $module['manifest_json'], true );
				if ( is_array( $decoded ) ) {
					$module['manifest'] = $decoded;
				}
			}
			$modules[] = $module;
		}
		return array(
			'modules' => $modules,
			'routes'  => SPF_Registry::list_routes(),
			'global_shell_owners' => array( 'file-20' ),
		);
	}

	private static function normalize_policy( array $policy ) {
		$id = strtoupper( preg_replace( '/[^A-Za-z0-9_.-]/', '', (string) ( $policy['id'] ?? '' ) ) );
		$title = sanitize_text_field( $policy['title'] ?? '' );
		$effect = sanitize_key( $policy['effect'] ?? 'deny' );
		$actions = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $policy['actions'] ?? array() ) ) ) ) );
		if ( '' === $id || strlen( $id ) > 100 || '' === $title || ! in_array( $effect, array( 'deny', 'require-review' ), true ) || empty( $actions ) ) {
			return new WP_Error( 'spf_policy_invalid', __( 'A bounded policy id, title, effect and action list are required.', 'sabri-platform-foundation' ), array( 'status' => 400 ) );
		}
		return array(
			'id'          => $id,
			'title'       => substr( $title, 0, 240 ),
			'effect'      => $effect,
			'actions'     => array_slice( $actions, 0, 50 ),
			'owner'       => sanitize_key( $policy['owner'] ?? 'file-01' ),
			'decision_id' => substr( sanitize_text_field( $policy['decision_id'] ?? '' ), 0, 100 ),
			'priority'    => max( 0, min( 1000, (int) ( $policy['priority'] ?? 0 ) ) ),
			'context'     => self::sanitize_context_matcher( (array) ( $policy['context'] ?? array() ) ),
		);
	}

	private static function sanitize_context_matcher( array $matcher ) {
		$out = array();
		foreach ( array_slice( $matcher, 0, 20, true ) as $key => $value ) {
			$key = sanitize_key( $key );
			if ( '' === $key || is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$out[ $key ] = substr( sanitize_text_field( (string) $value ), 0, 191 );
		}
		return $out;
	}

	private static function policy_context_matches( array $policy, array $context ) {
		foreach ( (array) ( $policy['context'] ?? array() ) as $key => $expected ) {
			if ( ! array_key_exists( $key, $context ) || sanitize_text_field( (string) $context[ $key ] ) !== $expected ) {
				return false;
			}
		}
		return true;
	}

	private static function impact_test_gates( array $amendment, array $contracts, array $routes ) {
		$gates = array( 'source', 'unit', 'contract' );
		if ( $contracts ) {
			$gates[] = 'cross-file-contract';
		}
		if ( $routes ) {
			$gates[] = 'route-and-shell-integration';
		}
		if ( ! empty( $amendment['migration_required'] ) ) {
			$gates[] = 'fresh-install';
			$gates[] = 'upgrade';
			$gates[] = 'rollback';
		}
		if ( ! empty( $amendment['security_privacy_impact'] ) ) {
			$gates[] = 'security-privacy';
		}
		return array_values( array_unique( $gates ) );
	}

	private static function finding( $severity, $code, $subject, $message, array $evidence = array() ) {
		return array(
			'severity' => sanitize_key( $severity ),
			'code'     => sanitize_key( $code ),
			'subject'  => sanitize_text_field( $subject ),
			'message'  => sanitize_text_field( $message ),
			'evidence' => SPF_Runtime::canonicalize( $evidence ),
		);
	}
}
