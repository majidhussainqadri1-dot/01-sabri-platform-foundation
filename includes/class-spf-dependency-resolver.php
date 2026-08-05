<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Dependency_Resolver {
	public static function readiness( $module_key ) {
		$module_key = sanitize_key( $module_key );
		$module = SPF_Registry::get_module( $module_key );
		if ( ! $module ) {
			return array( 'module_key' => $module_key, 'ready' => false, 'code' => 'module_manifest_missing', 'dependencies' => array(), 'optional_dependencies' => array() );
		}
		$visited = array();
		$stack = array();
		$details = array();
		$ready = self::walk( $module_key, $visited, $stack, $details );
		$optional = self::optional_status( $module );
		return array(
			'module_key'            => $module_key,
			'ready'                 => $ready,
			'code'                  => $ready ? 'ready' : self::primary_code( $details ),
			'state'                 => $module['state'],
			'software_version'      => $module['software_version'],
			'contract_version'      => $module['contract_version'],
			'dependencies'          => $details,
			'optional_dependencies' => $optional,
			'checked_at'            => SPF_Runtime::now_mysql(),
		);
	}

	public static function all_readiness() {
		$result = array();
		foreach ( SPF_Registry::list_modules( array( 'limit' => 100 ) ) as $module ) {
			$result[] = self::readiness( $module['module_key'] );
		}
		return $result;
	}

	private static function walk( $module_key, array &$visited, array &$stack, array &$details ) {
		if ( isset( $stack[ $module_key ] ) ) {
			$details[] = array( 'module_key' => $module_key, 'ready' => false, 'code' => 'dependency_cycle' );
			return false;
		}
		if ( array_key_exists( $module_key, $visited ) ) {
			return (bool) $visited[ $module_key ];
		}
		$module = SPF_Registry::get_module( $module_key );
		if ( ! $module ) {
			$details[] = array( 'module_key' => $module_key, 'ready' => false, 'code' => 'dependency_manifest_missing' );
			return false;
		}
		if ( in_array( $module['state'], array( 'unregistered','degraded','suspended','retired' ), true ) ) {
			$details[] = array( 'module_key' => $module_key, 'ready' => false, 'code' => 'dependency_' . $module['state'] );
			return false;
		}
		$stack[ $module_key ] = true;
		$all_ready = true;
		foreach ( $module['required'] as $dependency ) {
			$key = $dependency['module_key'];
			$target = SPF_Registry::get_module( $key );
			if ( ! $target ) {
				$details[] = array( 'module_key' => $key, 'ready' => false, 'code' => 'dependency_manifest_missing' );
				$all_ready = false;
				continue;
			}
			$range = self::range_status( $target, $dependency );
			if ( true !== $range ) {
				$details[] = $range;
				$all_ready = false;
				continue;
			}
			$child_ready = self::walk( $key, $visited, $stack, $details );
			$details[] = array( 'module_key' => $key, 'ready' => $child_ready, 'code' => $child_ready ? 'ready' : 'dependency_not_ready', 'actual' => $target['software_version'] );
			$all_ready = $all_ready && $child_ready;
		}
		unset( $stack[ $module_key ] );
		$visited[ $module_key ] = $all_ready;
		return $all_ready;
	}

	private static function optional_status( array $module ) {
		$result = array();
		foreach ( $module['optional'] as $dependency ) {
			$target = SPF_Registry::get_module( $dependency['module_key'] );
			if ( ! $target ) {
				$result[] = array( 'module_key' => $dependency['module_key'], 'available' => false, 'code' => 'optional_manifest_missing' );
				continue;
			}
			$range = self::range_status( $target, $dependency );
			$result[] = true === $range
				? array( 'module_key' => $dependency['module_key'], 'available' => ! in_array( $target['state'], array( 'unregistered','suspended','retired' ), true ), 'code' => 'optional_available', 'actual' => $target['software_version'] )
				: array_merge( $range, array( 'available' => false ) );
		}
		return $result;
	}

	private static function range_status( array $target, array $dependency ) {
		if ( version_compare( $target['software_version'], $dependency['minimum_version'], '<' ) ) {
			return array( 'module_key' => $dependency['module_key'], 'ready' => false, 'code' => 'dependency_below_minimum', 'actual' => $target['software_version'], 'minimum' => $dependency['minimum_version'] );
		}
		if ( ! empty( $dependency['maximum_version'] ) && version_compare( $target['software_version'], $dependency['maximum_version'], '>' ) ) {
			return array( 'module_key' => $dependency['module_key'], 'ready' => false, 'code' => 'dependency_above_maximum', 'actual' => $target['software_version'], 'maximum' => $dependency['maximum_version'] );
		}
		return true;
	}

	private static function primary_code( array $details ) {
		foreach ( $details as $detail ) {
			if ( empty( $detail['ready'] ) ) {
				return $detail['code'];
			}
		}
		return 'not_ready';
	}
}
