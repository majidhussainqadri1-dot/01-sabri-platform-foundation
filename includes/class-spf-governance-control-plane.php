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
		$normalized = self::normalize_policy( $policy );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		if ( '' === (string) ( $normalized['decision_id'] ?? '' ) ) {
			return new WP_Error( 'spf_policy_decision_required', __( 'A Founder-approved decision id is required before changing governance policy.', 'sabri-platform-foundation' ), array( 'status' => 400 ) );
		}
		$allowed = SPF_Authorization::require_action(
			'approve_amendment',
			array( 'module_key' => 'file-01', 'object_id' => 'policy:' . $normalized['id'] ),
			array( 'purpose' => 'policy_as_code', 'decision_id' => $normalized['decision_id'] )
		);
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		$lock_name = 'future-policy-catalog';
		$lock = SPF_Runtime::acquire_lock( $lock_name, 120 );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		try {
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
				if ( count( $policies ) >= 250 ) {
					return new WP_Error( 'spf_policy_catalog_full', __( 'The bounded governance policy catalog is full; retire or consolidate a policy before adding another.', 'sabri-platform-foundation' ), array( 'status'=>409 ) );
				}
				$policies[] = $normalized;
			}
			usort( $policies, static function ( $a, $b ) {
				return (int) ( $b['priority'] ?? 0 ) <=> (int) ( $a['priority'] ?? 0 );
			} );
			$expected = $policies;
			update_option( self::POLICY_OPTION, $expected, false );
			$persisted = get_option( self::POLICY_OPTION, array() );
			if ( SPF_Runtime::hash( $persisted ) !== SPF_Runtime::hash( $expected ) ) {
				return new WP_Error( 'spf_policy_persistence_failed', __( 'The governance policy change could not be verified after persistence.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );
			}
			return $normalized;
		} finally {
			SPF_Runtime::release_lock( $lock_name, $lock );
		}
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
		$dependency_map = array();
		foreach ( (array) ( $inventory['modules'] ?? array() ) as $module ) {
			$key = sanitize_key( $module['module_key'] ?? '' );
			if ( ! $key ) {
				continue;
			}
			$manifest = (array) ( $module['manifest'] ?? $module );
			$deps = array_merge( (array) ( $manifest['required'] ?? array() ), (array) ( $manifest['optional'] ?? array() ) );
			$dependency_map[ $key ] = array();
			foreach ( $deps as $dep ) {
				$dep_key = sanitize_key( is_array( $dep ) ? ( $dep['module_key'] ?? '' ) : $dep );
				if ( $dep_key ) {
					$dependency_map[ $key ][] = $dep_key;
				}
			}
			$dependency_map[ $key ] = array_values( array_unique( $dependency_map[ $key ] ) );
		}
		$frontier = $affected;
		$visited = array_fill_keys( $affected, true );
		while ( $frontier ) {
			$target = array_shift( $frontier );
			foreach ( $dependency_map as $consumer => $deps ) {
				if ( ! in_array( $target, $deps, true ) ) {
					continue;
				}
				$dependents[ $consumer ][] = $target;
				if ( empty( $visited[ $consumer ] ) ) {
					$visited[ $consumer ] = true;
					$frontier[] = $consumer;
				}
			}
		}
		foreach ( $dependents as $key => $deps ) {
			$dependents[ $key ] = array_values( array_unique( $deps ) );
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

	/**
	 * Read-only and pinned cross-repository architecture source collector.
	 * Inputs are externally selected immutable commits plus independently known
	 * SHA-256 of each canonical JSON architecture manifest. No branch refs,
	 * redirect follows, arbitrary hosts, writes, or silent unverified sources.
	 * This does not certify companion repositories that are not supplied.
	 */
	public static function lint_pinned_repository_sources( array $descriptors ) {
		$findings = array();
		$modules = array();
		$routes = array();
		$shell_owners = array();
		$provenance = array();
		$seen = array();
		$verified = 0;
		if ( empty( $descriptors ) || count( $descriptors ) > 26 ) {
			return array( 'pass'=>false, 'source_verified_count'=>0,
				'findings'=>array( self::finding( 'critical', 'source_count_invalid', 'cross-repository', 'One to 26 explicitly pinned manifest descriptors are required.', array() ) ),
				'provenance'=>array(), 'source_count'=>count( $descriptors ) );
		}
		foreach ( $descriptors as $index => $descriptor ) {
			$descriptor = is_array( $descriptor ) ? $descriptor : array();
			$repo = $descriptor['repository'] ?? null;
			$commit = $descriptor['commit_sha'] ?? null;
			$path = $descriptor['path'] ?? null;
			$digest = $descriptor['expected_sha256'] ?? null;
			$owner = $descriptor['module_key'] ?? null;
			$key = is_string( $repo ) && is_string( $path ) ? $repo . '/' . $path : 'source-' . (int) $index;
			if ( ! is_string( $repo ) || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}\\/[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/D', $repo )
				|| str_contains( $repo, '..' ) || ! is_string( $commit ) || ! preg_match( '/^[a-f0-9]{40}$/D', $commit )
				|| ! is_string( $path ) || ! preg_match( '/^(?:[A-Za-z0-9_-]+\\/){0,3}(?:architecture-manifest|manifest)\\.json$/D', $path )
				|| ! is_string( $digest ) || ! preg_match( '/^[a-f0-9]{64}$/D', $digest )
				|| ! is_string( $owner ) || ! preg_match( '/^file-(?:0[0-9]|1[0-9]|2[0-6])$/D', $owner )
				|| isset( $seen[$repo] ) ) {
				$findings[] = self::finding( 'critical', 'source_pin_invalid', $key, 'A unique canonical repository, exact commit, bounded path, owner and independent SHA-256 are mandatory.', array() );
				continue;
			}
			$seen[$repo] = true;
			if ( ! function_exists( 'wp_remote_get' ) ) {
				$findings[] = self::finding( 'critical', 'source_fetch_unavailable', $key, 'WordPress HTTP read-only transport is unavailable.', array() );
				continue;
			}
			$url = 'https://api.github.com/repos/' . $repo . '/contents/' . $path . '?ref=' . $commit;
			$response = wp_remote_get( $url, array(
				'headers'=>array( 'Accept'=>'application/vnd.github+json', 'User-Agent'=>'Sabri-File01-ReadOnly-Audit' ),
				'timeout'=>10, 'redirection'=>0, 'sslverify'=>true, 'reject_unsafe_urls'=>true,
				'limit_response_size'=>262144,
			) );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				$findings[] = self::finding( 'critical', 'source_fetch_unverified', $key, 'Pinned manifest HTTP retrieval failed.', array() );
				continue;
			}
			$body = wp_remote_retrieve_body( $response );
			if ( ! is_string( $body ) || strlen( $body ) > 262144 ) {
				$findings[] = self::finding( 'critical', 'source_response_oversized', $key, 'Remote response is outside the bounded envelope.', array() );
				continue;
			}
			$payload = json_decode( $body, true );
			if ( ! is_array( $payload ) || 'base64' !== ( $payload['encoding'] ?? null )
				|| ! is_string( $payload['content'] ?? null ) ) {
				$findings[] = self::finding( 'critical', 'source_encoding_invalid', $key, 'An exact GitHub base64 content object is required.', array() );
				continue;
			}
			$raw = base64_decode( preg_replace( '/\\s+/', '', $payload['content'] ), true );
			if ( ! is_string( $raw ) || strlen( $raw ) > 131072 || ! hash_equals( $digest, hash( 'sha256', $raw ) ) ) {
				$findings[] = self::finding( 'critical', 'source_digest_mismatch', $key, 'Manifest bytes did not match the independently supplied pinned SHA-256.', array() );
				continue;
			}
			$manifest = json_decode( $raw, true );
			if ( ! is_array( $manifest ) || ( $manifest['module_key'] ?? null ) !== $owner
				|| ! is_array( $manifest['canonical_entities'] ?? null )
				|| ! is_array( $manifest['writes'] ?? null ) || ! is_array( $manifest['routes'] ?? null )
				|| count( $manifest['canonical_entities'] ) > 128 || count( $manifest['writes'] ) > 128
				|| count( $manifest['routes'] ) > 128 ) {
				$findings[] = self::finding( 'critical', 'source_manifest_invalid', $key, 'Pinned content lacks complete, bounded, matching architecture declarations.', array() );
				continue;
			}
			$modules[] = array( 'module_key'=>$owner, 'manifest'=>array(
				'canonical_entities'=>$manifest['canonical_entities'],
				'writes'=>$manifest['writes'],
				'global_shell_owner'=>true === ( $manifest['global_shell_owner'] ?? false ),
				'application_shell_owner'=>true === ( $manifest['application_shell_owner'] ?? false ),
			) );
			foreach ( $manifest['routes'] as $route ) {
				if ( ! is_array( $route ) || ! is_string( $route['route_path'] ?? null )
					|| ! is_string( $route['owner_module'] ?? null ) || $route['owner_module'] !== $owner ) {
					$findings[] = self::finding( 'high', 'source_route_invalid', $key, 'A supplied route does not declare the exact source owner.', array() );
					continue;
				}
				$routes[] = array( 'route_path'=>$route['route_path'], 'owner_module'=>$owner );
			}
			if ( true === ( $manifest['global_shell_owner'] ?? false ) || true === ( $manifest['application_shell_owner'] ?? false )
				|| in_array( 'global-shell', $manifest['canonical_entities'], true )
				|| in_array( 'application-shell', $manifest['canonical_entities'], true )
				|| in_array( 'global-navigation', $manifest['canonical_entities'], true ) ) {
				$shell_owners[] = $owner;
			}
			$verified++;
			$provenance[] = array( 'repository'=>$repo, 'commit_sha'=>$commit, 'path'=>$path,
				'content_sha256'=>$digest, 'module_key'=>$owner, 'verified_by'=>'exact_commit_and_sha256' );
		}
		$lint = self::lint_architecture( array( 'modules'=>$modules, 'routes'=>$routes,
			'global_shell_owners'=>array_values( array_unique( $shell_owners ) ) ) );
		$findings = array_merge( $findings, $lint['findings'] );
		return array( 'pass'=>empty( $findings ) && $verified === count( $descriptors ),
			'source_verified_count'=>$verified, 'source_count'=>count( $descriptors ),
			'findings'=>$findings, 'finding_count'=>count( $findings ),
			'provenance'=>$provenance, 'inventory_hash'=>$lint['inventory_hash'],
			'read_only'=>true, 'companion_source_coverage_complete'=>false );
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
			if ( count( $route_claims[ $path ] ) > 1 ) {
				$code = count( $owners ) > 1 ? 'duplicate_route_owner' : 'duplicate_route_declaration';
				$findings[] = self::finding( 'high', $code, $path, 'The canonical route has multiple declarations; every route path must be unique.', array( 'owners' => $owners, 'declaration_count' => count( $route_claims[ $path ] ) ) );
			}
		}
		$known_shell_owners = array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $inventory['global_shell_owners'] ?? array() ) ) ) ) );
		if ( empty( $known_shell_owners ) ) {
			$findings[] = self::finding( 'critical', 'shell_owner_missing', 'global-shell', 'The canonical File 20 application-shell owner is not present in runtime inventory.', array( 'owners' => array() ) );
		} elseif ( count( $known_shell_owners ) > 1 || 'file-20' !== $known_shell_owners[0] ) {
			$findings[] = self::finding( 'critical', 'shell_owner_violation', 'global-shell', 'File 20 must remain the only application-shell owner.', array( 'owners' => $known_shell_owners ) );
		}
		return array(
			'pass'        => empty( $findings ),
			'finding_count'=> count( $findings ),
			'findings'    => $findings,
			'inventory_hash' => SPF_Runtime::hash( $inventory ),
		);
	}

	/**
	 * Resolve only files that are genuinely under the supplied local repository.
	 * This scanner is read-only; it does not trust user-supplied boolean evidence.
	 */
	public static function verify_v12_source_file( $root, $path, $expected_sha256 ) {
		if ( ! is_string( $root ) || ! is_string( $path ) || ! is_string( $expected_sha256 )
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $expected_sha256 )
			|| ! preg_match( '/^[A-Za-z0-9_.\\/-]+$/D', $path )
			|| str_contains( $path, '..' ) || str_starts_with( $path, '/' )
			|| str_contains( $path, '\\\\' ) ) {
			return false;
		}
		$base = realpath( $root );
		$target = $base ? realpath( $base . DIRECTORY_SEPARATOR . $path ) : false;
		if ( false === $base || false === $target || ! str_starts_with( $target, $base . DIRECTORY_SEPARATOR )
			|| ! is_file( $target ) || ! is_readable( $target ) ) {
			return false;
		}
		$actual = hash_file( 'sha256', $target );
		return is_string( $actual ) && hash_equals( $expected_sha256, $actual );
	}

	/**
	 * Automatically scan the canonical 43 IDs, declared implementations and
	 * on-disk source/test bytes from an exact checked-out tree. This is source
	 * provenance, NOT independent CI success or production acceptance.
	 */
	public static function audit_v12_local_sources( $root, $commit_sha ) {
		$ids = self::file01_v12_requirement_ids();
		$empty = array( 'total'=>count( $ids ), 'source_mapped'=>0, 'test_ref_verified_count'=>0,
			'coded_completion_claim_allowed'=>false, 'ci_result_verified'=>false, 'rows'=>array(),
			'missing_requirements'=>$ids, 'status'=>'source_unavailable' );
		if ( ! is_string( $root ) || ! is_dir( $root ) || ! is_string( $commit_sha )
			|| ! preg_match( '/^[a-f0-9]{40}$/D', $commit_sha ) ) {
			return $empty;
		}
		$trace_path = $root . '/TRACEABILITY.md';
		$manifest_path = $root . '/SOURCE-CHECKSUMS.sha256';
		if ( ! is_file( $trace_path ) || ! is_file( $manifest_path ) ) {
			return $empty;
		}
		$trace = file_get_contents( $trace_path );
		$checksums = file_get_contents( $manifest_path );
		if ( ! is_string( $trace ) || ! is_string( $checksums ) ) {
			return $empty;
		}
		$hashes = array();
		foreach ( explode( "\n", $checksums ) as $line ) {
			if ( preg_match( '/^([a-f0-9]{64})  \\.\\/([A-Za-z0-9_.\\/-]+)$/D', trim( $line ), $m ) ) {
				if ( isset( $hashes[ $m[2] ] ) ) {
					return $empty; // A duplicated checksum entry is not independent truth.
				}
				$hashes[ $m[2] ] = $m[1];
			}
		}
		if ( ! isset( $hashes['TRACEABILITY.md'] )
			|| ! self::verify_v12_source_file( $root, 'TRACEABILITY.md', $hashes['TRACEABILITY.md'] ) ) {
			return $empty;
		}
		$declarations = array();
		foreach ( explode( "\n", $trace ) as $line ) {
			if ( ! preg_match( '/^\\|\\s*(F01-(?:FR-[0-9]{3}|NFR-[0-9]{3}|CEN-[0-9]{2}|FUT-[0-9]{3}))(?:\\s+[^|]*)?\\s*\\|\\s*([^|]+)\\|\\s*([^|]+)\\|/D', $line, $match ) ) {
				continue;
			}
			if ( isset( $declarations[ $match[1] ] ) ) {
				return $empty;
			}
			$declarations[ $match[1] ] = array( 'implementation'=>trim( $match[2] ), 'test_reference'=>trim( $match[3] ) );
		}
		$rows = array();
		$source_mapped = 0;
		$tests_found = 0;
		foreach ( $ids as $id ) {
			$declaration = $declarations[ $id ] ?? array();
			$implementation = $declaration['implementation'] ?? '';
			$source_evidence = array();
			if ( preg_match_all( '/\\b(SPF_[A-Za-z0-9_]+)(?:::(\\w+))?/', $implementation, $symbols, PREG_SET_ORDER ) ) {
				foreach ( $symbols as $symbol ) {
					$class = $symbol[1];
					$method = $symbol[2] ?? '';
					$path = 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
					if ( ! isset( $hashes[ $path ] ) || ! self::verify_v12_source_file( $root, $path, $hashes[ $path ] ) ) {
						continue;
					}
					$source = file_get_contents( $root . '/' . $path );
					if ( ! is_string( $source ) || ! str_contains( $source, 'class ' . $class )
						|| ( '' !== $method && ! preg_match( '/\\bfunction\\s+' . preg_quote( $method, '/' ) . '\\s*\\(/', $source ) ) ) {
						continue;
					}
					$source_evidence[] = array( 'symbol'=>$class . ( '' !== $method ? '::' . $method : '' ),
						'path'=>$path, 'sha256'=>$hashes[ $path ] );
				}
			}
			$has_source = ! empty( $source_evidence );
			if ( $has_source ) {
				$source_mapped++;
			}
			$test_refs = array();
			if ( preg_match_all( '~\\btests/[A-Za-z0-9_-]+\\.php\\b~', $declaration['test_reference'] ?? '', $matches ) ) {
				foreach ( array_unique( $matches[0] ) as $path ) {
					if ( isset( $hashes[$path] ) && self::verify_v12_source_file( $root, $path, $hashes[$path] ) ) {
						$test_refs[] = array( 'path'=>$path, 'sha256'=>$hashes[$path],
							'assertion_individually_verified'=>false );
					}
				}
			}
			if ( $test_refs ) {
				$tests_found++;
			}
			$rows[$id] = array( 'source_mapped'=>$has_source, 'source_evidence'=>$source_evidence,
				'test_files_pinned'=>$test_refs, 'test_result'=>'requires_independent_ci_attestation',
				'coded_complete_verified'=>false, 'staging_verified'=>false, 'live_verified'=>false );
		}
		$missing = array_values( array_diff( $ids, array_keys( $declarations ) ) );
		$extra = array_values( array_diff( array_keys( $declarations ), $ids ) );
		return array(
			'total'=>count($ids), 'declared_count'=>count($declarations), 'source_mapped'=>$source_mapped,
			'test_ref_verified_count'=>$tests_found, 'missing_requirements'=>$missing,
			'unexpected_requirements'=>$extra, 'tree_commit_claim'=>$commit_sha,
			'commit_verified'=>false, 'ci_result_verified'=>false,
			'coded_completion_claim_allowed'=>false, 'rows'=>$rows,
			'status'=>empty($missing) && empty($extra) ? 'source_scan_complete_acceptance_pending' : 'requirement_coverage_incomplete',
		);
	}

	public static function file01_v12_requirement_ids() {
		$ids = array();
		foreach ( array( 'FR'=>12, 'NFR'=>10, 'CEN'=>3, 'FUT'=>18 ) as $family => $count ) {
			for ( $i = 1; $i <= $count; $i++ ) {
				$ids[] = 'F01-' . $family . '-' . sprintf( 'CEN' === $family ? '%02d' : '%03d', $i );
			}
		}
		return $ids;
	}

	/**
	 * Evidence is supplied by callers; raw booleans are NOT verification.
	 * The only way to label a row coded_complete is for a separate verifier
	 * to attest its bound evidence through the explicit integration hook.
	 */
	public static function build_traceability_report( array $requirements, array $evidence ) {
		$rows = array();
		$missing = array();
		$duplicate_ids = array();
		$invalid_requirement_entries = array();
		$seen = array();
		foreach ( $requirements as $index => $requirement ) {
			$raw_id = is_array( $requirement ) ? (string) ( $requirement['id'] ?? '' ) : (string) $requirement;
			$id = trim( sanitize_text_field( $raw_id ) );
			if ( '' === $id || $id !== trim( $raw_id ) || strlen( $id ) > 128 || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $id ) ) {
				$invalid_requirement_entries[] = array( 'index'=>(int)$index, 'id'=>substr( $id, 0, 128 ) );
				continue;
			}
			if ( isset( $seen[ $id ] ) ) {
				$duplicate_ids[] = $id;
				continue;
			}
			$seen[ $id ] = true;
			$item = is_array( $evidence[ $id ] ?? null ) ? $evidence[ $id ] : array();
			$proof = is_array( $item['proof'] ?? null ) ? $item['proof'] : array();
			$refs = array( 'design_ref','code_ref','test_ref','test_run' );
			$proof_structured = true;
			foreach ( $refs as $field ) {
				$proof_structured = $proof_structured && is_string( $proof[ $field ] ?? null ) && '' !== trim( $proof[ $field ] ) && strlen( $proof[ $field ] ) <= 500;
			}
			$proof_structured = $proof_structured && is_string( $proof['commit_sha'] ?? null )
				&& 1 === preg_match( '/^[a-f0-9]{40}$/D', (string) ( $proof['commit_sha'] ?? '' ) )
				&& 'passed' === ( $proof['test_result'] ?? null );
			$declared = true === ( $item['design'] ?? false ) && true === ( $item['code'] ?? false ) && true === ( $item['test'] ?? false );
			$verified = $declared && $proof_structured
				&& true === apply_filters( 'spf_verify_traceability_requirement_evidence', false, $id, $proof );
			$status = array(
				'requirement' => $id,
				'design' => true === ( $item['design'] ?? false ),
				'code' => true === ( $item['code'] ?? false ),
				'test' => true === ( $item['test'] ?? false ),
				'evidence_registered' => $proof_structured,
				'evidence_verified' => $verified,
				'package' => true === ( $item['package'] ?? false ),
				'staging' => true === ( $item['staging'] ?? false ),
				'approval' => true === ( $item['approval'] ?? false ),
				'live' => true === ( $item['live'] ?? false ) || true === ( $item['deployed'] ?? false ),
				'operational' => true === ( $item['operational'] ?? false ),
			);
			$status['coded_complete'] = $verified;
			$status['packaged_complete'] = $verified && $status['package'];
			$status['staging_complete'] = $status['packaged_complete'] && $status['staging'];
			$status['release_ready'] = $status['staging_complete'] && $status['approval'];
			$status['live_deployed'] = $status['release_ready'] && $status['live'];
			$status['production_complete'] = $status['live_deployed'] && $status['operational'];
			$rows[] = $status;
			if ( ! $verified ) {
				$missing[] = $id;
			}
		}
		$expected = self::file01_v12_requirement_ids();
		$coverage_complete = empty( array_diff( $expected, array_keys( $seen ) ) )
			&& empty( array_diff( array_keys( $seen ), $expected ) ) && count( $seen ) === count( $expected );
		$unexpected_evidence_ids = array_values( array_diff( array_map( 'strval', array_keys( $evidence ) ), array_keys( $seen ) ) );
		sort( $unexpected_evidence_ids, SORT_STRING );
		$total = count( $rows );
		$coded = count( array_filter( $rows, static function ( $row ) { return ! empty( $row['coded_complete'] ); } ) );
		$release_ready = count( array_filter( $rows, static function ( $row ) { return ! empty( $row['release_ready'] ); } ) );
		$live = count( array_filter( $rows, static function ( $row ) { return ! empty( $row['live_deployed'] ); } ) );
		$production = count( array_filter( $rows, static function ( $row ) { return ! empty( $row['production_complete'] ); } ) );
		$report_valid = empty( $duplicate_ids ) && empty( $invalid_requirement_entries ) && empty( $unexpected_evidence_ids );
		$claimable_coded_complete = $report_valid && $coverage_complete && $coded === count( $expected );
		return array(
			'report_valid' => $report_valid,
			'v12_requirement_coverage_complete' => $coverage_complete,
			'coded_completion_claim_allowed' => $claimable_coded_complete,
			'total' => $total,
			'coded_complete' => $coded,
			'release_ready' => $release_ready,
			'live_deployed' => $live,
			'production_complete' => $production,
			'coded_percentage' => $total ? round( ( $coded / $total ) * 100, 2 ) : 0,
			'release_ready_percentage' => $total ? round( ( $release_ready / $total ) * 100, 2 ) : 0,
			'live_percentage' => $total ? round( ( $live / $total ) * 100, 2 ) : 0,
			'production_percentage' => $total ? round( ( $production / $total ) * 100, 2 ) : 0,
			'missing_coded_evidence' => $missing,
			'duplicate_requirement_ids' => array_values( array_unique( $duplicate_ids ) ),
			'invalid_requirement_entries' => $invalid_requirement_entries,
			'unexpected_evidence_ids' => $unexpected_evidence_ids,
			'rows' => $rows,
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
		$advisor_input = self::sanitize_advisory_input( $input );
		$external = apply_filters( 'spf_ai_governance_advisor', array(), $advisor_input, $advice );
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

	private static function sanitize_advisory_input( $value, $depth = 0, $parent_key = '' ) {
		if ( $depth > 6 ) {
			return array( '_truncated' => true );
		}
		if ( is_array( $value ) ) {
			$out = array();
			$count = 0;
			foreach ( $value as $key => $item ) {
				if ( $count++ >= 100 ) {
					$out['_truncated'] = true;
					break;
				}
				$safe_key = is_int( $key ) ? $key : sanitize_key( $key );
				if ( ! is_int( $safe_key ) && '' === $safe_key ) {
					continue;
				}
				$key_text = (string) $safe_key;
				if ( preg_match( '/(patient|clinical|name|address|message|content|prompt|email|phone|token|secret|password|credential|cookie|authorization|document|government|national[_-]?id)/i', $key_text ) ) {
					$out[ $safe_key ] = array( 'redacted' => true, 'value_hash' => SPF_Runtime::hash( $item ) );
					continue;
				}
				$out[ $safe_key ] = self::sanitize_advisory_input( $item, $depth + 1, $key_text );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return self::sanitize_advisory_input( get_object_vars( $value ), $depth + 1, $parent_key );
		}
		if ( is_string( $value ) ) {
			return substr( sanitize_text_field( $value ), 0, 500 );
		}
		return is_scalar( $value ) || null === $value ? $value : null;
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
		$shell_owners = array();
		foreach ( $modules as $module ) {
			$key = sanitize_key( $module['module_key'] ?? '' );
			$manifest = (array) ( $module['manifest'] ?? $module );
			$claims_shell = ! empty( $manifest['global_shell_owner'] ) || ! empty( $manifest['application_shell_owner'] );
			foreach ( (array) ( $manifest['canonical_entities'] ?? array() ) as $entity ) {
				$entity_key = sanitize_key( is_array( $entity ) ? ( $entity['key'] ?? '' ) : $entity );
				if ( in_array( $entity_key, array( 'global-shell', 'application-shell', 'global-navigation' ), true ) ) {
					$claims_shell = true;
				}
			}
			if ( $key && $claims_shell ) {
				$shell_owners[] = $key;
			}
		}
		return array(
			'modules' => $modules,
			'routes'  => SPF_Registry::list_routes(),
			'global_shell_owners' => array_values( array_unique( $shell_owners ) ),
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
