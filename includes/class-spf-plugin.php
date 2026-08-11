<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Plugin {
	private static $instance;
	private $ran = false;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function run() {
		if ( $this->ran ) {
			return;
		}
		$this->ran = true;
		add_action( 'spf_dispatch_outbox', array( 'SPF_Event_Bus', 'dispatch_due' ) );
		add_action( 'spf_reconcile_expired_flags', array( 'SPF_Governance', 'reconcile_expired_flags' ) );
		SPF_Privacy::register();
		SPF_Future_Foundation::register();
		add_action( 'rest_api_init', array( 'SPF_REST', 'register' ) );
		add_action( 'admin_menu', array( 'SPF_Admin', 'register_menu' ) );
		SPF_Admin::register_actions();
		add_action( 'init', array( $this, 'register_restricted_routes' ), 5 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'serve_restricted_route' ), 0 );
		add_action( 'init', array( $this, 'register_with_shell' ), 20 );
		add_filter( 'plugin_action_links_' . plugin_basename( SPF_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	public function maybe_upgrade() {
		$current = get_option( SPF_Installer::SCHEMA_OPTION, '0.0.0' );
		if ( ! SPF_Registry::valid_semver( (string) $current ) ) {
			$error = new WP_Error( 'spf_schema_version_invalid', __( 'The stored File 01 schema version is malformed; automatic upgrade is blocked pending reconciliation.', 'sabri-platform-foundation' ) );
			update_option( 'spf_upgrade_state', array( 'status'=>'invalid_schema_version','stored_version'=>(string)$current,'checked_at'=>SPF_Runtime::now_mysql() ), false );
			set_transient( 'spf_activation_notice', array( 'code'=>$error->get_error_code(),'message'=>$error->get_error_message() ), HOUR_IN_SECONDS );
			return;
		}
		$result = SPF_Installer::maybe_upgrade();
		if ( is_wp_error( $result ) ) {
			set_transient( 'spf_activation_notice', array( 'code'=>$result->get_error_code(),'message'=>$result->get_error_message() ), HOUR_IN_SECONDS );
		}
	}

	public function register_restricted_routes() {
		add_rewrite_rule( '^platform-system-check/?$', 'index.php?spf_foundation_endpoint=system-check', 'top' );
		add_rewrite_rule( '^platform-foundation/status/?$', 'index.php?spf_foundation_endpoint=status', 'top' );
	}

	public function query_vars( $vars ) {
		$vars[] = 'spf_foundation_endpoint';
		return $vars;
	}

	public function serve_restricted_route() {
		$endpoint = get_query_var( 'spf_foundation_endpoint' );
		if ( ! $endpoint ) {
			return;
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		if ( ! SPF_Authorization::can( 'view', array( 'object_id'=>$endpoint ), array( 'purpose'=>'restricted_foundation_endpoint' ) ) ) {
			status_header( is_user_logged_in() ? 403 : 404 );
			wp_die( esc_html__( 'This restricted foundation endpoint is unavailable.', 'sabri-platform-foundation' ) );
		}
		$data = 'system-check' === $endpoint ? SPF_System_Check::run( false ) : $this->status_dto();
		wp_send_json( $data, 200, JSON_UNESCAPED_SLASHES );
	}

	public function register_with_shell() {
		$provider = array(
			'owner'=>'file-01','contract_version'=>SPF_CONTRACT_VERSION,'admin_route'=>admin_url('tools.php?page=sabri-foundation'),
			'status_callback'=>'spf_foundation_status','public_shell'=>false,'search_surface'=>false,
		);
		do_action( 'sabri_shell_register_provider', 'file-01-foundation', $provider );
	}

	public static function builtin_contract_definitions() {
		return array(
			array(
				'contract_key'=>'FoundationRegistry.v1','contract_version'=>SPF_CONTRACT_VERSION,'owner_module'=>'file-01','status'=>'current',
				'schema'=>array(
					'module_key'=>array('type'=>'string','required'=>true),'owner_file'=>array('type'=>'string','required'=>true),'software_version'=>array('type'=>'semver','required'=>true),
					'contract_version'=>array('type'=>'semver','required'=>true),'state'=>array('type'=>'enum','required'=>true),'required'=>array('type'=>'array','required'=>true),
					'commands'=>array('type'=>'array','required'=>true),'queries'=>array('type'=>'array','required'=>true),'events'=>array('type'=>'array','required'=>true),
				),
				'consumers'=>array('file-00','file-20','file-24','file-26'),
			),
			array(
				'contract_key'=>'FoundationRouteRegistry.v1','contract_version'=>SPF_CONTRACT_VERSION,'owner_module'=>'file-01','status'=>'current',
				'schema'=>array('route_key'=>array('type'=>'string','required'=>true),'route_path'=>array('type'=>'path','required'=>true),'owner_module'=>array('type'=>'string','required'=>true),'layout_context'=>array('type'=>'string','required'=>true),'status'=>array('type'=>'enum','required'=>true),'record_version'=>array('type'=>'integer','required'=>true)),
				'consumers'=>array('file-20','file-26'),
			),
			array(
				'contract_key'=>'FoundationAuthorizationClaim.v1','contract_version'=>SPF_CONTRACT_VERSION,'owner_module'=>'file-01','status'=>'current',
				'schema'=>array(
					'claim_version'=>array('type'=>'semver','required'=>true),'claim_id'=>array('type'=>'string','required'=>true),
					'allowed'=>array('type'=>'boolean','required'=>true),'user_id'=>array('type'=>'integer','required'=>true),'actor_id'=>array('type'=>'integer','required'=>false),
					'action'=>array('type'=>'string','required'=>true),'capability'=>array('type'=>'string','required'=>true),'object_hash'=>array('type'=>'sha256','required'=>true),
					'purpose'=>array('type'=>'string','required'=>true),'issued_at'=>array('type'=>'timestamp','required'=>true),'expires_at'=>array('type'=>'timestamp','required'=>true),
					'institutional_role'=>array('type'=>'string','required'=>true),'plugin'=>array('type'=>'string','required'=>true),'contract'=>array('type'=>'semver','required'=>true),
				),
				'consumers'=>array('file-00','file-24'),
			),
			array(
				'contract_key'=>'FoundationHealth.v1','contract_version'=>SPF_CONTRACT_VERSION,'owner_module'=>'file-01','status'=>'current',
				'schema'=>array('trace_id'=>array('type'=>'uuid','required'=>true),'overall_status'=>array('type'=>'enum','required'=>true),'checks'=>array('type'=>'array','required'=>true),'checked_at'=>array('type'=>'datetime','required'=>true)),
				'consumers'=>array('file-20','file-24'),
			),
			array(
				'contract_key'=>'FoundationFutureControlPlane.v2','contract_version'=>SPF_CONTRACT_VERSION,'owner_module'=>'file-01','status'=>'current',
				'schema'=>array(
					'future_foundation_version'=>array('type'=>'semver','required'=>true),
					'feature_count'=>array('type'=>'integer','required'=>true),
					'coded_count'=>array('type'=>'integer','required'=>true),
					'policy_count'=>array('type'=>'integer','required'=>true),
					'event_schema_count'=>array('type'=>'integer','required'=>true),
					'snapshot_count'=>array('type'=>'integer','required'=>true),
					'ai_autonomous_changes'=>array('type'=>'boolean','required'=>true),
				),
				'consumers'=>array('file-20','file-24'),
			),
		);
	}

	public function status_dto() {
		$releases = SPF_Governance::list_releases( 1 );
		$latest_release = $releases ? $releases[0] : array();
		$release_status = $latest_release ? $latest_release['status'] : 'not-recorded';
		$schema = get_option( SPF_Installer::SCHEMA_OPTION, '0.0.0' );
		$health = SPF_System_Check::latest();
		$operational_context = array(
			'release_id' => (string) ( $latest_release['release_id'] ?? '' ),
			'deployed_package_checksum' => (string) ( $latest_release['checksum_sha256'] ?? '' ),
			'release_status' => $release_status,
			'health' => $health,
		);
		$operational_claim = apply_filters( 'spf_operational_acceptance_status', null, $operational_context );
		$operational = self::validate_operational_claim( $operational_claim, $operational_context );
		return array(
			'file'=>'01-B','plan_id'=>SPF_PLAN_ID,'software_version'=>SPF_VERSION,'schema_version'=>$schema,
			'contract_version'=>get_option(SPF_Installer::CONTRACT_OPTION,SPF_CONTRACT_VERSION),'activation_state'=>get_option('spf_activation_state',array('status'=>'unknown')),
			'upgrade_state'=>get_option('spf_upgrade_state',array('status'=>'not-required')),
			'module_count'=>count(SPF_Registry::list_modules(array('limit'=>200))),'catalog_count'=>count(SPF_Installer::canonical_module_catalog()),
			'contract_count'=>count(SPF_Registry::list_contracts(array('limit'=>200))),'route_count'=>count(SPF_Registry::list_routes()),
			'latest_health'=>$health,'legacy_state'=>get_option('spf_reconciliation_state',array('status'=>'not_run')),'latest_release_status'=>$release_status,
			'future_foundation'=>SPF_Future_Foundation::status(),
			'completion_statuses'=>array(
				'specified'=>true,
				'coded'=>true,
				'packaged'=>in_array($release_status,array('built','verified','staged','approved','deployed'),true),
				'automated_qa_green'=>in_array($release_status,array('verified','staged','approved','deployed'),true),
				'staging_accepted'=>in_array($release_status,array('approved','deployed'),true),
				'live_deployed'=>'deployed'===$release_status,
				'operational'=>$operational,
			),
			'ownership_boundary'=>'No public shell, feed, profile, identity, Security Center, notification truth or search-truth ownership.',
		);
	}

	private static function validate_operational_claim( $claim, array $context ) {
		$operational_claim = $claim;
		if ( ! is_array( $operational_claim ) || ! array_key_exists( 'verified', $operational_claim ) || ! ( true === $operational_claim['verified'] ) || 'deployed' !== ( $context['release_status'] ?? '' ) ) {
			return false;
		}
		$health = $context['health'] ?? null;
		if ( ! is_array( $health ) || 'pass' !== ( $health['overall_status'] ?? '' ) ) {
			return false;
		}
		$release_id = (string) ( $context['release_id'] ?? '' );
		$checksum = strtolower( (string) ( $context['deployed_package_checksum'] ?? '' ) );
		if ( '' === $release_id || ! preg_match( '/^[a-f0-9]{64}$/', $checksum ) ) {
			return false;
		}
		if ( ! hash_equals( $release_id, (string) ( $claim['release_id'] ?? '' ) ) || ! hash_equals( $checksum, strtolower( (string) ( $claim['deployed_package_checksum'] ?? '' ) ) ) ) {
			return false;
		}
		$required_states = array(
			'monitoring_status' => 'pass',
			'support_status' => 'ready',
			'backup_restore_status' => 'pass',
			'slo_status' => 'pass',
		);
		foreach ( $required_states as $field => $expected ) {
			if ( $expected !== sanitize_key( (string) ( $claim[ $field ] ?? '' ) ) ) {
				return false;
			}
		}
		$observed_at = strtotime( (string) ( $claim['observed_at'] ?? '' ) );
		return false !== $observed_at && $observed_at <= time() + 60;
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="'.esc_url(admin_url('tools.php?page=sabri-foundation')).'">'.esc_html__('Foundation Status','sabri-platform-foundation').'</a>' );
		return $links;
	}
}