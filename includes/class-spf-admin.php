<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Admin {
	public static function register_menu() {
		add_management_page( __( 'Sabri Platform Foundation', 'sabri-platform-foundation' ), __( 'Sabri Foundation', 'sabri-platform-foundation' ), SPF_Authorization::CAP_MANAGE, 'sabri-foundation', array( __CLASS__, 'render' ) );
	}
	public static function register_actions() {
		add_action( 'admin_post_spf_system_check', array( __CLASS__, 'system_check_action' ) );
		add_action( 'admin_post_spf_reconcile', array( __CLASS__, 'reconcile_action' ) );
		add_action( 'admin_post_spf_reconcile_rollback', array( __CLASS__, 'reconcile_rollback_action' ) );
		add_action( 'admin_post_spf_repair', array( __CLASS__, 'repair_action' ) );
	}
	public static function render() {
		if ( ! SPF_Authorization::can( 'view' ) ) { wp_die( esc_html__( 'Unauthorized.', 'sabri-platform-foundation' ) ); }
		$status=SPF_Plugin::instance()->status_dto(); $readiness=SPF_Dependency_Resolver::all_readiness(); $reconcile=SPF_Reconciler::plan(); $reconcile_hash=SPF_Reconciler::plan_hash($reconcile); $repair=SPF_Repair::plan(); $repair_hash=SPF_Repair::plan_hash($repair);
		?>
		<div class="wrap" dir="auto">
		<h1><?php esc_html_e( 'File 01 — Platform Foundation and Master Governance', 'sabri-platform-foundation' ); ?></h1>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'File 01 is a governance, registry and compatibility plane. It does not render a second shell, feed, profile, identity system or Security Center.', 'sabri-platform-foundation' ); ?></p></div>
		<table class="widefat striped"><tbody>
		<tr><th><?php esc_html_e('Software','sabri-platform-foundation');?></th><td><?php echo esc_html($status['software_version']);?></td></tr>
		<tr><th><?php esc_html_e('Schema','sabri-platform-foundation');?></th><td><?php echo esc_html($status['schema_version']);?></td></tr>
		<tr><th><?php esc_html_e('Contracts','sabri-platform-foundation');?></th><td><?php echo esc_html((string)$status['contract_count']);?></td></tr>
		<tr><th><?php esc_html_e('Routes','sabri-platform-foundation');?></th><td><?php echo esc_html((string)$status['route_count']);?></td></tr>
		<tr><th><?php esc_html_e('Staging accepted','sabri-platform-foundation');?></th><td><?php echo !empty($status['completion_statuses']['staging_accepted'])?'Yes':'No — external acceptance required';?></td></tr>
		</tbody></table>
		<h2><?php esc_html_e('System Check','sabri-platform-foundation');?></h2>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="spf_system_check"><?php wp_nonce_field('spf_system_check');?><?php submit_button(__('Run Redacted System Check','sabri-platform-foundation'),'primary','submit',false);?></form>
		<h2><?php esc_html_e('Dependency Readiness','sabri-platform-foundation');?></h2><table class="widefat striped"><thead><tr><th>Module</th><th>Ready</th><th>Code</th></tr></thead><tbody><?php foreach($readiness as $item):?><tr><td><?php echo esc_html($item['module_key']);?></td><td><?php echo empty($item['ready'])?'No':'Yes';?></td><td><code><?php echo esc_html($item['code']);?></code></td></tr><?php endforeach;?></tbody></table>
		<h2><?php esc_html_e('Legacy Reconciliation Dry Run','sabri-platform-foundation');?></h2><p><code><?php echo esc_html($reconcile_hash);?></code></p><pre><?php echo esc_html(wp_json_encode($reconcile,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));?></pre>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="spf_reconcile"><input type="hidden" name="plan_hash" value="<?php echo esc_attr($reconcile_hash);?>"><label><?php esc_html_e('Type: APPLY FILE 01 RECONCILIATION','sabri-platform-foundation');?> <input name="confirmation" class="regular-text" autocomplete="off"></label><?php wp_nonce_field('spf_reconcile');?><?php submit_button(__('Apply Reconciliation','sabri-platform-foundation'),'secondary','submit',false);?></form>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" style="margin-top:10px"><input type="hidden" name="action" value="spf_reconcile_rollback"><label><?php esc_html_e('Type: ROLL BACK FILE 01 RECONCILIATION','sabri-platform-foundation');?> <input name="confirmation" class="regular-text" autocomplete="off"></label><?php wp_nonce_field('spf_reconcile_rollback');?><?php submit_button(__('Rollback Reconciliation','sabri-platform-foundation'),'secondary','submit',false);?></form>
		<h2><?php esc_html_e('Owner-Scoped Repair Dry Run','sabri-platform-foundation');?></h2><p><code><?php echo esc_html($repair_hash);?></code></p><pre><?php echo esc_html(wp_json_encode($repair,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));?></pre>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>"><input type="hidden" name="action" value="spf_repair"><input type="hidden" name="plan_hash" value="<?php echo esc_attr($repair_hash);?>"><label><?php esc_html_e('Type: REPAIR FILE 01 OWNED STATE','sabri-platform-foundation');?> <input name="confirmation" class="regular-text" autocomplete="off"></label><?php wp_nonce_field('spf_repair');?><?php submit_button(__('Apply Safe Repair','sabri-platform-foundation'),'secondary','submit',false);?></form>
		</div><?php
	}
	public static function system_check_action(){self::guard('system_check','spf_system_check'); SPF_System_Check::run(true); self::redirect('system_check_complete');}
	public static function reconcile_action(){self::guard('run_reconciliation','spf_reconcile');$result=SPF_Reconciler::apply(sanitize_text_field(wp_unslash($_POST['confirmation']??'')),sanitize_text_field(wp_unslash($_POST['plan_hash']??'')));self::finish($result,'reconciliation_complete');}
	public static function reconcile_rollback_action(){self::guard('run_reconciliation','spf_reconcile_rollback');$result=SPF_Reconciler::rollback(sanitize_text_field(wp_unslash($_POST['confirmation']??'')));self::finish($result,'reconciliation_rollback_complete');}
	public static function repair_action(){self::guard('repair_owned_mapping','spf_repair');$result=SPF_Repair::apply(sanitize_text_field(wp_unslash($_POST['confirmation']??'')),sanitize_text_field(wp_unslash($_POST['plan_hash']??'')));self::finish($result,'repair_complete');}
	private static function guard($action,$nonce){if(!SPF_Authorization::can($action)){wp_die('Unauthorized',403);}check_admin_referer($nonce);}
	private static function finish($result,$ok){if(is_wp_error($result)){self::redirect($result->get_error_code());}self::redirect($ok);}
	private static function redirect($notice){wp_safe_redirect(add_query_arg('spf_notice',sanitize_key($notice),admin_url('tools.php?page=sabri-foundation')));exit;}
}
