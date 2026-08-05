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
		add_filter( 'cron_schedules', array( $this, 'cron_schedules' ) );
		add_action( 'spf_dispatch_outbox', array( 'SPF_Event_Bus', 'dispatch_due' ) );
		add_action( 'rest_api_init', array( 'SPF_REST', 'register' ) );
		add_action( 'admin_menu', array( 'SPF_Admin', 'register_menu' ) );
		SPF_Admin::register_actions();
		add_action( 'init', array( $this, 'register_restricted_routes' ), 5 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'serve_restricted_route' ), 0 );
		add_action( 'init', array( $this, 'register_with_shell' ), 20 );
		add_action( 'init', array( $this, 'register_builtin_contracts' ), 25 );
		add_filter( 'plugin_action_links_' . plugin_basename( SPF_FILE ), array( $this, 'action_links' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	public function cron_schedules( $schedules ) {
		$schedules['spf_five_minutes'] = array( 'interval' => 300, 'display' => __( 'Every five minutes (Sabri Foundation)', 'sabri-platform-foundation' ) );
		return $schedules;
	}

	public function maybe_upgrade() {
		$current = get_option( SPF_Installer::SCHEMA_OPTION, '0.0.0' );
		if ( version_compare( $current, SPF_SCHEMA_VERSION, '<' ) && SPF_Authorization::can( 'manage' ) ) {
			SPF_Installer::install_schema();
			update_option( SPF_Installer::SCHEMA_OPTION, SPF_SCHEMA_VERSION, false );
			SPF_Audit::record( 'schema_upgrade', 'foundation_schema', SPF_SCHEMA_VERSION, 'success', array( 'purpose' => 'idempotent_upgrade', 'from' => $current ) );
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
		if ( ! SPF_Authorization::can( 'view' ) ) {
			status_header( is_user_logged_in() ? 403 : 404 );
			wp_die( esc_html__( 'This restricted foundation endpoint is unavailable.', 'sabri-platform-foundation' ) );
		}
		$data = 'system-check' === $endpoint ? SPF_System_Check::run( false ) : $this->status_dto();
		wp_send_json( $data, 200, JSON_UNESCAPED_SLASHES );
	}

	public function register_with_shell() {
		$provider = array(
			'owner'            => 'file-01',
			'contract_version' => SPF_CONTRACT_VERSION,
			'admin_route'      => admin_url( 'admin.php?page=sabri-foundation' ),
			'status_callback'  => 'spf_foundation_status',
			'public_shell'     => false,
			'search_surface'   => false,
		);
		do_action( 'sabri_shell_register_provider', 'file-01-foundation', $provider );
	}

	public function register_builtin_contracts() {
		if ( get_option( 'spf_builtin_contracts_registered', false ) ) {
			return;
		}
		if ( ! SPF_Authorization::can( 'manage' ) ) {
			return;
		}
		$results = array();
		$results[] = SPF_Registry::register_contract(
			array(
				'contract_key'     => 'FoundationRegistry.v1',
				'contract_version' => '1.0.0',
				'owner_module'     => 'file-01',
				'status'           => 'current',
				'schema'           => array(
					'module_key'       => array( 'type' => 'string', 'required' => true ),
					'owner_file'       => array( 'type' => 'string', 'required' => true ),
					'software_version' => array( 'type' => 'semver', 'required' => true ),
					'state'            => array( 'type' => 'enum', 'required' => true ),
					'required'         => array( 'type' => 'array', 'required' => false ),
				),
				'consumers'        => array( 'file-20', 'file-24', 'file-26' ),
			),
			array( 'purpose' => 'builtin_contract_seed' )
		);
		$results[] = SPF_Registry::register_contract(
			array(
				'contract_key'     => 'FoundationRouteRegistry.v1',
				'contract_version' => '1.0.0',
				'owner_module'     => 'file-01',
				'status'           => 'current',
				'schema'           => array(
					'route_key'      => array( 'type' => 'string', 'required' => true ),
					'route_path'     => array( 'type' => 'path', 'required' => true ),
					'owner_module'   => array( 'type' => 'string', 'required' => true ),
					'layout_context' => array( 'type' => 'string', 'required' => true ),
					'status'         => array( 'type' => 'enum', 'required' => true ),
				),
				'consumers'        => array( 'file-20', 'file-26' ),
			),
			array( 'purpose' => 'builtin_contract_seed' )
		);
		foreach ( $results as $result ) {
			if ( is_wp_error( $result ) ) {
				SPF_Audit::record( 'builtin_contract_seed', 'foundation_contract', SPF_CONTRACT_VERSION, 'failed', array( 'purpose' => 'builtin_contract_seed', 'error_code' => $result->get_error_code() ) );
				return;
			}
		}
		update_option( 'spf_builtin_contracts_registered', SPF_CONTRACT_VERSION, false );
	}

	public function status_dto() {
		return array(
			'file'                => '01-B',
			'plan_id'             => SPF_PLAN_ID,
			'software_version'    => SPF_VERSION,
			'schema_version'      => get_option( SPF_Installer::SCHEMA_OPTION, SPF_SCHEMA_VERSION ),
			'contract_version'    => get_option( SPF_Installer::CONTRACT_OPTION, SPF_CONTRACT_VERSION ),
			'activation_state'    => get_option( 'spf_activation_state', array( 'status' => 'unknown' ) ),
			'module_count'        => count( SPF_Registry::list_modules( array( 'limit' => 100 ) ) ),
			'contract_count'      => count( SPF_Registry::list_contracts( array( 'limit' => 100 ) ) ),
			'route_count'         => count( SPF_Registry::list_routes() ),
			'latest_health'       => SPF_System_Check::latest(),
			'legacy_state'        => get_option( 'spf_reconciliation_state', array( 'status' => 'not_run' ) ),
			'completion_statuses' => array(
				'specified'          => true,
				'coded'              => true,
				'packaged'           => false,
				'automated_qa_green' => false,
				'staging_accepted'   => false,
				'live_deployed'      => false,
				'operational'        => false,
			),
			'ownership_boundary'  => 'No public shell, feed, profile, identity, security-center or search-truth ownership.',
		);
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=sabri-foundation' ) ) . '">' . esc_html__( 'Foundation Status', 'sabri-platform-foundation' ) . '</a>' );
		return $links;
	}
}
