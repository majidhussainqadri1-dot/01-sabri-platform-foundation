<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Dependency_Resolver {
	public static function readiness( $module_key ) {
		$module = SPF_Registry::get_module( $module_key );
		if ( ! $module ) {
			return array( 'module_key' => $module_key, 'ready' => false, 'code' => 'module_unregistered', 'dependencies' => array() );
		}
		$visited = array();
		$stack = array();
		$details = array();
		$ready = self::walk( $module_key, $visited, $stack, $details );
		return array(
			'module_key'   => $module_key,
			'ready'        => $ready,
			'code'         => $ready ? 'ready' : self::primary_code( $details ),
			'state'        => $module['state'],
			'dependencies' => $details,
			'checked_at'   => current_time( 'mysql', true ),
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
			$details[] = array( 'module_key' => $module_key, 'ready' => false, 'code' => 'dependency_missing' );
			return false;
		}
		if ( in_array( $module['state'], array( 'degraded', 'suspended', 'retired', 'unregistered' ), true ) ) {
			$details[] = array( 'module_key' => $module_key, 'ready' => false, 'code' => 'dependency_' . $module['state'] );
			return false;
		}
		$stack[ $module_key ] = true;
		$all_ready = true;
		foreach ( $module['required'] as $dependency ) {
			$key = $dependency['module_key'];
			$target = SPF_Registry::get_module( $key );
			if ( ! $target ) {
				$details[] = array( 'module_key' => $key, 'ready' => false, 'code' => 'dependency_missing' );
				$all_ready = false;
				continue;
			}
			if ( version_compare( $target['software_version'], $dependency['minimum_version'], '<' ) ) {
				$details[] = array( 'module_key' => $key, 'ready' => false, 'code' => 'dependency_below_minimum', 'actual' => $target['software_version'], 'minimum' => $dependency['minimum_version'] );
				$all_ready = false;
				continue;
			}
			if ( ! empty( $dependency['maximum_version'] ) && version_compare( $target['software_version'], $dependency['maximum_version'], '>' ) ) {
				$details[] = array( 'module_key' => $key, 'ready' => false, 'code' => 'dependency_above_maximum', 'actual' => $target['software_version'], 'maximum' => $dependency['maximum_version'] );
				$all_ready = false;
				continue;
			}
			$child_ready = self::walk( $key, $visited, $stack, $details );
			$details[] = array( 'module_key' => $key, 'ready' => $child_ready, 'code' => $child_ready ? 'ready' : 'dependency_not_ready' );
			$all_ready = $all_ready && $child_ready;
		}
		unset( $stack[ $module_key ] );
		$visited[ $module_key ] = $all_ready;
		return $all_ready;
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
