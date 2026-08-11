<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Admin {
	public static function register_menu() {
		add_management_page( __( 'Sabri Platform Foundation', 'sabri-platform-foundation' ), __( 'Sabri Foundation', 'sabri-platform-foundation' ), SPF_Authorization::CAP_VIEW, 'sabri-foundation', array( __CLASS__, 'render' ) );
	}

	public static function register_actions() {
		add_action( 'admin_post_spf_system_check', array( __CLASS__, 'system_check_action' ) );
		add_action( 'admin_post_spf_reconcile', array( __CLASS__, 'reconcile_action' ) );
		add_action( 'admin_post_spf_reconcile_rollback', array( __CLASS__, 'reconcile_rollback_action' ) );
		add_action( 'admin_post_spf_repair', array( __CLASS__, 'repair_action' ) );
	}

	public static function render() {
		if ( ! SPF_Authorization::can( 'view', array( 'object_id' => 'file-01-admin' ), array( 'purpose' => 'foundation_admin_view' ) ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'sabri-platform-foundation' ), '', array( 'response' => 403 ) );
		}
		$status = SPF_Plugin::instance()->status_dto();
		$readiness = SPF_Dependency_Resolver::all_readiness();
		$reconcile = SPF_Reconciler::plan();
		$reconcile_hash = SPF_Reconciler::plan_hash( $reconcile );
		$repair = SPF_Repair::plan();
		$repair_hash = SPF_Repair::plan_hash( $repair );
		$can_system_check = SPF_Authorization::can( 'run_system_check', array( 'object_id' => 'system-check' ), array( 'purpose' => 'admin_run_system_check' ) );
		$can_reconcile = SPF_Authorization::can( 'run_reconciliation', array( 'module_key' => 'file-01' ), array( 'purpose' => 'legacy_cutover' ) );
		$can_repair = SPF_Authorization::can( 'repair_owned_mapping', array( 'module_key' => 'file-01' ), array( 'purpose' => 'safe_repair' ) );
		?>
		<div class="wrap" dir="auto">
		<h1><?php esc_html_e( 'File 01 — Platform Foundation and Master Governance', 'sabri-platform-foundation' ); ?></h1>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'File 01 is a governance, registry and compatibility plane. It does not render a second shell, feed, profile, identity system or Security Center.', 'sabri-platform-foundation' ); ?></p></div>
		<table class="widefat striped"><tbody>
		<tr><th scope="row"><?php esc_html_e( 'Software', 'sabri-platform-foundation' ); ?></th><td><?php echo esc_html( $status['software_version'] ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Schema', 'sabri-platform-foundation' ); ?></th><td><?php echo esc_html( $status['schema_version'] ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Registered manifests / canonical catalog', 'sabri-platform-foundation' ); ?></th><td><?php echo esc_html( $status['module_count'] . ' / ' . $status['catalog_count'] ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Contracts', 'sabri-platform-foundation' ); ?></th><td><?php echo esc_html( (string) $status['contract_count'] ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Routes', 'sabri-platform-foundation' ); ?></th><td><?php echo esc_html( (string) $status['route_count'] ); ?></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Upgrade state', 'sabri-platform-foundation' ); ?></th><td><code><?php echo esc_html( (string) ( $status['upgrade_state']['status'] ?? 'unknown' ) ); ?></code></td></tr>
		<tr><th scope="row"><?php esc_html_e( 'Staging accepted', 'sabri-platform-foundation' ); ?></th><td><?php echo ! empty( $status['completion_statuses']['staging_accepted'] ) ? esc_html__( 'Yes', 'sabri-platform-foundation' ) : esc_html__( 'No — external acceptance required', 'sabri-platform-foundation' ); ?></td></tr>
		</tbody></table>

		<h2><?php esc_html_e( 'System Check', 'sabri-platform-foundation' ); ?></h2>
		<?php if ( $can_system_check ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="spf_system_check"><?php wp_nonce_field( 'spf_system_check' ); ?><?php submit_button( __( 'Run Redacted System Check', 'sabri-platform-foundation' ), 'primary', 'submit', false ); ?></form>
		<?php else : ?>
		<p><?php esc_html_e( 'Persisting a System Check requires current File 01 management authorization.', 'sabri-platform-foundation' ); ?></p>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Dependency Readiness', 'sabri-platform-foundation' ); ?></h2>
		<table class="widefat striped"><thead><tr><th scope="col"><?php esc_html_e( 'Module', 'sabri-platform-foundation' ); ?></th><th scope="col"><?php esc_html_e( 'Ready', 'sabri-platform-foundation' ); ?></th><th scope="col"><?php esc_html_e( 'Code', 'sabri-platform-foundation' ); ?></th></tr></thead><tbody><?php foreach ( $readiness as $item ) : ?><tr><td><?php echo esc_html( $item['module_key'] ); ?></td><td><?php echo empty( $item['ready'] ) ? esc_html__( 'No', 'sabri-platform-foundation' ) : esc_html__( 'Yes', 'sabri-platform-foundation' ); ?></td><td><code><?php echo esc_html( $item['code'] ); ?></code></td></tr><?php endforeach; ?></tbody></table>

		<h2><?php esc_html_e( 'Legacy Reconciliation Dry Run', 'sabri-platform-foundation' ); ?></h2>
		<p><code><?php echo esc_html( $reconcile_hash ); ?></code></p><pre><?php echo esc_html( wp_json_encode( $reconcile, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
		<?php if ( $can_reconcile ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="spf_reconcile"><input type="hidden" name="plan_hash" value="<?php echo esc_attr( $reconcile_hash ); ?>"><label><?php esc_html_e( 'Type: APPLY FILE 01 RECONCILIATION', 'sabri-platform-foundation' ); ?> <input name="confirmation" class="regular-text" autocomplete="off"></label><?php wp_nonce_field( 'spf_reconcile' ); ?><?php submit_button( __( 'Apply Reconciliation', 'sabri-platform-foundation' ), 'secondary', 'submit', false ); ?></form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px"><input type="hidden" name="action" value="spf_reconcile_rollback"><label><?php esc_html_e( 'Type: ROLL BACK FILE 01 RECONCILIATION', 'sabri-platform-foundation' ); ?> <input name="confirmation" class="regular-text" autocomplete="off"></label><?php wp_nonce_field( 'spf_reconcile_rollback' ); ?><?php submit_button( __( 'Rollback Reconciliation', 'sabri-platform-foundation' ), 'secondary', 'submit', false ); ?></form>
		<?php else : ?><p><?php esc_html_e( 'A current File 00 release-operator/Founder claim is required to apply or roll back reconciliation.', 'sabri-platform-foundation' ); ?></p><?php endif; ?>

		<h2><?php esc_html_e( 'Owner-Scoped Repair Dry Run', 'sabri-platform-foundation' ); ?></h2>
		<p><code><?php echo esc_html( $repair_hash ); ?></code></p><pre><?php echo esc_html( wp_json_encode( $repair, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
		<?php if ( $can_repair ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="spf_repair"><input type="hidden" name="plan_hash" value="<?php echo esc_attr( $repair_hash ); ?>"><label><?php esc_html_e( 'Type: REPAIR FILE 01 OWNED STATE', 'sabri-platform-foundation' ); ?> <input name="confirmation" class="regular-text" autocomplete="off"></label><?php wp_nonce_field( 'spf_repair' ); ?><?php submit_button( __( 'Apply Safe Repair', 'sabri-platform-foundation' ), 'secondary', 'submit', false ); ?></form>
		<?php endif; ?>
		</div>
		<?php
	}

	public static function system_check_action() { self::guard( 'run_system_check', 'spf_system_check', array( 'object_id' => 'system-check' ) ); $result = SPF_System_Check::run( true, array( 'purpose'=>'run_system_check' ) ); self::finish( $result, 'system_check_complete' ); }
	public static function reconcile_action() { self::guard( 'run_reconciliation', 'spf_reconcile', array( 'module_key' => 'file-01' ) ); $result = SPF_Reconciler::apply( sanitize_text_field( wp_unslash( $_POST['confirmation'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['plan_hash'] ?? '' ) ) ); self::finish( $result, 'reconciliation_complete' ); }
	public static function reconcile_rollback_action() { self::guard( 'run_reconciliation', 'spf_reconcile_rollback', array( 'module_key' => 'file-01' ) ); $result = SPF_Reconciler::rollback( sanitize_text_field( wp_unslash( $_POST['confirmation'] ?? '' ) ) ); self::finish( $result, 'reconciliation_rollback_complete' ); }
	public static function repair_action() { self::guard( 'repair_owned_mapping', 'spf_repair', array( 'module_key' => 'file-01' ) ); $result = SPF_Repair::apply( sanitize_text_field( wp_unslash( $_POST['confirmation'] ?? '' ) ), sanitize_text_field( wp_unslash( $_POST['plan_hash'] ?? '' ) ) ); self::finish( $result, 'repair_complete' ); }

	private static function guard( $action, $nonce, $object ) {
		if ( ! SPF_Authorization::can( $action, $object, array( 'purpose' => $action ) ) ) {
			wp_die( esc_html__( 'Unauthorized.', 'sabri-platform-foundation' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce );
	}
	private static function finish( $result, $ok ) { self::redirect( is_wp_error( $result ) ? $result->get_error_code() : $ok ); }
	private static function redirect( $notice ) { wp_safe_redirect( add_query_arg( 'spf_notice', sanitize_key( $notice ), admin_url( 'tools.php?page=sabri-foundation' ) ) ); exit; }
}
