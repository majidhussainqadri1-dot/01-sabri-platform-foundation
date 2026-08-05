<?php
defined( 'ABSPATH' ) || exit;

final class SPF_REST {
	private const NS = 'sabri-foundation/v1';

	public static function register() {
		self::route( '/status', 'GET', array( __CLASS__, 'status' ), 'view' );
		self::route( '/modules', 'GET', array( __CLASS__, 'modules' ), 'view' );
		self::route( '/modules', 'POST', array( __CLASS__, 'register_module' ), 'register_manifest' );
		self::route( '/modules/(?P<module_key>[a-z0-9-]+)/readiness', 'GET', array( __CLASS__, 'readiness' ), 'view' );
		self::route( '/contracts', 'GET', array( __CLASS__, 'contracts' ), 'view' );
		self::route( '/contracts', 'POST', array( __CLASS__, 'register_contract' ), 'register_contract' );
		self::route( '/contracts/(?P<contract_key>[A-Za-z0-9_.-]+)/(?P<contract_version>[0-9A-Za-z.-]+)/acknowledge', 'POST', array( __CLASS__, 'acknowledge_contract' ), 'acknowledge_contract' );
		self::route( '/routes', 'GET', array( __CLASS__, 'routes' ), 'view' );
		self::route( '/routes', 'POST', array( __CLASS__, 'map_route' ), 'map_route' );
		self::route( '/system-check', 'POST', array( __CLASS__, 'system_check' ), 'system_check' );
		self::route( '/reconciliation/plan', 'GET', array( __CLASS__, 'reconciliation_plan' ), 'view' );
		self::route( '/reconciliation/apply', 'POST', array( __CLASS__, 'reconciliation_apply' ), 'run_reconciliation' );
		self::route( '/reconciliation/rollback', 'POST', array( __CLASS__, 'reconciliation_rollback' ), 'run_reconciliation' );
		self::route( '/repair/plan', 'GET', array( __CLASS__, 'repair_plan' ), 'view' );
		self::route( '/repair/apply', 'POST', array( __CLASS__, 'repair_apply' ), 'repair_owned_mapping' );
		self::route( '/purge/plan', 'GET', array( __CLASS__, 'purge_plan' ), 'view' );
		self::route( '/purge/apply', 'POST', array( __CLASS__, 'purge_apply' ), 'purge' );
		self::route( '/releases', 'GET', array( __CLASS__, 'releases' ), 'view' );
		self::route( '/releases', 'POST', array( __CLASS__, 'record_release' ), 'record_release' );
		self::route( '/releases/(?P<release_id>[a-f0-9-]+)/transition', 'POST', array( __CLASS__, 'transition_release' ), 'record_release' );
		self::route( '/amendments', 'GET', array( __CLASS__, 'amendments' ), 'view' );
		self::route( '/amendments', 'POST', array( __CLASS__, 'record_amendment' ), 'approve_amendment' );
		self::route( '/flags', 'POST', array( __CLASS__, 'set_flag' ), 'set_flag' );
	}

	private static function route( $path, $methods, $callback, $action ) {
		register_rest_route( self::NS, $path, array(
			'methods' => $methods,
			'callback' => $callback,
			'permission_callback' => static function( WP_REST_Request $request ) use ( $action ) {
				$request->set_attribute( 'spf_action', $action );
				return SPF_Authorization::rest_permission( $request );
			},
		) );
	}

	public static function status() { return rest_ensure_response( SPF_Plugin::instance()->status_dto() ); }
	public static function modules( WP_REST_Request $r ) { return rest_ensure_response( SPF_Registry::list_modules( array( 'limit'=>$r->get_param('limit') ?: 50 ) ) ); }
	public static function contracts( WP_REST_Request $r ) { return rest_ensure_response( SPF_Registry::list_contracts( array( 'limit'=>$r->get_param('limit') ?: 50 ) ) ); }
	public static function routes() { return rest_ensure_response( SPF_Registry::list_routes() ); }
	public static function readiness( WP_REST_Request $r ) { return rest_ensure_response( SPF_Dependency_Resolver::readiness( sanitize_key( $r['module_key'] ) ) ); }
	public static function system_check() { return rest_ensure_response( SPF_System_Check::run( true ) ); }
	public static function releases( WP_REST_Request $r ) { return rest_ensure_response( SPF_Governance::list_releases( $r->get_param('limit') ?: 50 ) ); }
	public static function amendments( WP_REST_Request $r ) { return rest_ensure_response( SPF_Governance::list_amendments( $r->get_param('limit') ?: 100 ) ); }

	public static function register_module( WP_REST_Request $r ) { return self::mutation( $r, 'register_manifest', static fn() => SPF_Registry::register_manifest( (array)$r->get_json_params(), array( 'purpose'=>'rest_manifest_registration' ) ) ); }
	public static function register_contract( WP_REST_Request $r ) { return self::mutation( $r, 'register_contract', static fn() => SPF_Registry::register_contract( (array)$r->get_json_params(), array( 'purpose'=>'rest_contract_registration' ) ) ); }
	public static function acknowledge_contract( WP_REST_Request $r ) { return self::mutation( $r, 'acknowledge_contract', static fn() => SPF_Registry::acknowledge_contract( $r['contract_key'], $r['contract_version'], sanitize_key( $r->get_param('consumer_module') ), array( 'purpose'=>'rest_contract_acknowledgement' ) ) ); }
	public static function map_route( WP_REST_Request $r ) { return self::mutation( $r, 'map_route', static fn() => SPF_Registry::map_route( (array)$r->get_json_params(), array( 'purpose'=>'rest_route_mapping' ) ) ); }
	public static function reconciliation_plan() { $p=SPF_Reconciler::plan(); $p['plan_hash']=SPF_Reconciler::plan_hash($p); return rest_ensure_response($p); }
	public static function reconciliation_apply( WP_REST_Request $r ) { return self::mutation( $r, 'reconciliation_apply', static fn() => SPF_Reconciler::apply( (string)$r->get_param('confirmation'), (string)$r->get_param('plan_hash') ) ); }
	public static function reconciliation_rollback( WP_REST_Request $r ) { return self::mutation( $r, 'reconciliation_rollback', static fn() => SPF_Reconciler::rollback( (string)$r->get_param('confirmation') ) ); }
	public static function repair_plan() { $p=SPF_Repair::plan(); $p['plan_hash']=SPF_Repair::plan_hash($p); return rest_ensure_response($p); }
	public static function repair_apply( WP_REST_Request $r ) { return self::mutation( $r, 'repair_apply', static fn() => SPF_Repair::apply( (string)$r->get_param('confirmation'), (string)$r->get_param('plan_hash') ) ); }
	public static function purge_plan() { $p=SPF_Purge::plan(); $p['plan_hash']=SPF_Purge::plan_hash($p); return rest_ensure_response($p); }
	public static function purge_apply( WP_REST_Request $r ) { return self::mutation( $r, 'purge_apply', static fn() => SPF_Purge::apply( (string)$r->get_param('confirmation'), (string)$r->get_param('backup_reference'), (string)$r->get_param('plan_hash') ) ); }
	public static function record_release( WP_REST_Request $r ) { return self::mutation( $r, 'record_release', static fn() => SPF_Governance::record_release( (array)$r->get_json_params(), array( 'purpose'=>'rest_release_evidence' ) ) ); }
	public static function transition_release( WP_REST_Request $r ) { return self::mutation( $r, 'transition_release', static fn() => SPF_Governance::transition_release( $r['release_id'], (string)$r->get_param('next_status'), (array)$r->get_param('evidence'), array( 'purpose'=>'rest_release_transition','expected_sequence'=>$r->get_param('expected_sequence') ) ) ); }
	public static function record_amendment( WP_REST_Request $r ) { return self::mutation( $r, 'record_amendment', static fn() => SPF_Governance::record_amendment( (array)$r->get_json_params(), array( 'purpose'=>'rest_change_control' ) ) ); }
	public static function set_flag( WP_REST_Request $r ) { return self::mutation( $r, 'set_flag', static fn() => SPF_Governance::set_flag( (array)$r->get_json_params(), array( 'purpose'=>'rest_feature_flag' ) ) ); }

	private static function mutation( WP_REST_Request $request, $action, callable $callback ) {
		global $wpdb;
		$key = trim( (string)$request->get_header( 'X-Idempotency-Key' ) );
		if ( strlen($key) < 8 || strlen($key) > 191 ) { return new WP_Error( 'spf_idempotency_required', __( 'A valid X-Idempotency-Key is required.', 'sabri-platform-foundation' ), array( 'status'=>400 ) ); }
		$actor = get_current_user_id();
		$request_hash = hash( 'sha256', wp_json_encode( array( 'route'=>$request->get_route(),'method'=>$request->get_method(),'body'=>$request->get_json_params() ) ) );
		$scope_hash = hash( 'sha256', $actor.'|'.$action.'|'.$key );
		$table = SPF_Installer::table( 'idempotency' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE scope_hash=%s AND expires_at>%s", $scope_hash, current_time('mysql',true) ), ARRAY_A );
		if ( $existing ) {
			if ( ! hash_equals( $existing['request_hash'], $request_hash ) ) { return new WP_Error( 'spf_idempotency_conflict', __( 'The idempotency key was reused with a different request.', 'sabri-platform-foundation' ), array( 'status'=>409 ) ); }
			return rest_ensure_response( json_decode( $existing['response_json'], true ) );
		}
		if ( ! self::rate_limit( $actor, $action ) ) { return new WP_Error( 'spf_rate_limited', __( 'Too many foundation mutations.', 'sabri-platform-foundation' ), array( 'status'=>429 ) ); }
		$result = $callback();
		if ( is_wp_error( $result ) ) { return $result; }
		$response = array( 'success'=>true,'result'=>$result,'trace_id'=>SPF_Audit::trace_id() );
		$wpdb->insert( $table, array( 'idempotency_key'=>$key,'scope_hash'=>$scope_hash,'actor_id'=>$actor,'action_name'=>sanitize_key($action),'request_hash'=>$request_hash,'response_json'=>wp_json_encode($response),'expires_at'=>gmdate('Y-m-d H:i:s',time()+DAY_IN_SECONDS),'created_at'=>current_time('mysql',true) ) );
		return rest_ensure_response( $response );
	}

	private static function rate_limit( $actor, $action ) {
		$key='spf_rl_'.md5($actor.'|'.$action.'|'.gmdate('YmdHi')); $count=(int)get_transient($key); if($count>=30){return false;} set_transient($key,$count+1,90); return true;
	}
}
