<?php
defined( 'ABSPATH' ) || exit;

final class SPF_System_Check {
	public static function run( $persist = true ) {
		global $wpdb;
		$trace = SPF_Audit::trace_id();
		$checks = array();

		$checks[] = self::check( 'php_version', version_compare( PHP_VERSION, '8.1.0', '>=' ), PHP_VERSION, 'PHP 8.1 or newer is required.', 'fail' );
		$wp_version = get_bloginfo( 'version' );
		$checks[] = self::check( 'wordpress_version', version_compare( $wp_version, '6.0', '>=' ), $wp_version, 'WordPress 6.0 or newer is required.', 'fail' );
		$checks[] = self::check( 'project_wordpress_baseline', version_compare( $wp_version, '7.0.1', '>=' ), $wp_version, 'Project baseline is WordPress 7.0.1; re-verification is required on an older runtime.', 'warning' );
		$checks[] = self::check( 'database_connection', ! empty( $wpdb->dbh ), empty( $wpdb->dbh ) ? 'unavailable' : 'connected', 'Database connection is unavailable.', 'fail' );
		$checks[] = self::check( 'database_charset', false !== stripos( (string) $wpdb->get_charset_collate(), 'utf8' ), 'utf8-compatible', 'Database character set must be UTF-8 compatible.', 'fail' );

		$schema = SPF_Installer::verify_schema();
		$checks[] = self::check( 'schema_integrity', ! is_wp_error( $schema ), is_wp_error($schema)?'defects':'verified', 'File 01 schema, columns or engines are incomplete.', 'fail' );
		$transactional = SPF_Runtime::verify_owned_tables_transactional();
		$checks[] = self::check( 'transactional_engines', ! is_wp_error( $transactional ), is_wp_error($transactional)?'non-transactional':'InnoDB', 'All File 01 tables must use InnoDB.', 'fail' );
		$tx_probe = self::transaction_probe();
		$checks[] = self::check( 'transaction_rollback_probe', true === $tx_probe, true===$tx_probe?'rollback-verified':'failed', 'Database rollback behavior could not be verified.', 'fail' );

		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$external_cron = apply_filters( 'spf_external_cron_evidence', null, array( 'hooks'=>array('spf_dispatch_outbox','spf_privacy_retention','spf_reconcile_expired_flags','spf_future_foundation_tick') ) );
		$cron_ok = ! $cron_disabled || ( is_array($external_cron) && array_key_exists('verified',$external_cron) && true===$external_cron['verified'] );
		$checks[] = self::check( 'cron_runner', $cron_ok, $cron_disabled ? ( $cron_ok ? 'external-verified' : 'disabled-unverified' ) : 'wp-cron', 'WP-Cron is disabled without verified external scheduler evidence.', 'fail' );
		foreach ( array( 'spf_dispatch_outbox','spf_privacy_retention','spf_reconcile_expired_flags','spf_future_foundation_tick' ) as $hook ) {
			$scheduled = wp_next_scheduled( $hook );
			$checks[] = self::check( 'schedule_'.$hook, (bool)$scheduled, $scheduled?'scheduled':'missing', 'A required File 01 scheduled job is missing.', 'fail' );
		}

		$rules = get_option( 'rewrite_rules', array() );
		$rewrite_ok = is_array($rules) && ( array_key_exists('platform-system-check/?$', $rules) || array_key_exists('^platform-system-check/?$', $rules) ) && ( array_key_exists('platform-foundation/status/?$', $rules) || array_key_exists('^platform-foundation/status/?$', $rules) );
		$checks[] = self::check( 'rewrite_rules', $rewrite_ok, $rewrite_ok?'foundation-routes-present':'foundation-routes-missing', 'File 01 restricted rewrite rules are missing.', 'warning' );

		$cache = self::cache_probe();
		$checks[] = self::check( 'cache_read_write', true === $cache, true===$cache?(wp_using_ext_object_cache()?'persistent-verified':'wordpress-cache-verified'):'failed', 'Cache read/write/delete behavior failed.', 'warning' );
		$checks[] = self::check( 'persistent_object_cache', wp_using_ext_object_cache(), wp_using_ext_object_cache()?'enabled':'not-enabled', 'Persistent object cache is recommended for scale but not required for safe correctness.', 'warning' );

		$mail = apply_filters( 'spf_mail_health_evidence', null, array( 'admin_email_configured'=>(bool)get_option('admin_email') ) );
		$mail_ok = is_array($mail) && array_key_exists('verified',$mail) && true===$mail['verified'];
		$checks[] = self::check( 'mail_delivery_evidence', $mail_ok, $mail_ok?'verified':'not-verified', 'An admin email address is not proof of mail delivery; provider evidence is pending.', 'warning' );

		$upload = wp_upload_dir();
		$upload_path = isset( $upload['basedir'] ) ? $upload['basedir'] : '';
		$writable = $upload_path && ( function_exists('wp_is_writable') ? wp_is_writable($upload_path) : is_writable($upload_path) );
		$checks[] = self::check( 'uploads_writable', $writable, $writable?'writable':'not-writable', 'Uploads directory is not writable.', 'warning' );

		$checks = array_merge( $checks, self::integration_checks() );
		$checks = array_merge( $checks, self::queue_checks() );
		$checks = array_merge( $checks, self::privacy_checks() );

		$audit = SPF_Audit::verify_chain( 50000 );
		$checks[] = self::check( 'audit_chain', ! is_wp_error($audit), is_wp_error($audit)?'broken':('verified-'.(int)$audit['rows']), 'Audit chain integrity verification failed.', 'fail' );

		$upgrade_state = get_option( 'spf_upgrade_state', array( 'status'=>'not-required' ) );
		$schema_current = get_option( SPF_Installer::SCHEMA_OPTION, '0.0.0' );
		$checks[] = self::check( 'schema_version_current', SPF_Registry::valid_semver((string)$schema_current) && version_compare($schema_current,SPF_SCHEMA_VERSION,'>='), sanitize_text_field((string)$schema_current), 'Schema upgrade is pending or blocked.', 'fail' );
		$checks[] = self::check( 'upgrade_state', !in_array($upgrade_state['status']??'',array('authorization_required','backup_evidence_required','rolled_back','rollback_incomplete'),true), sanitize_key($upgrade_state['status']??'not-required'), 'Schema upgrade requires operator/backup evidence or was rolled back.', 'warning' );

		$latest_release = SPF_Governance::list_releases( 1 );
		$release_status = $latest_release ? $latest_release[0]['status'] : 'not-recorded';
		$checks[] = self::check( 'release_evidence_record', (bool)$latest_release, $release_status, 'No immutable File 01 release-evidence record is registered.', 'warning' );
		$checks[] = self::check( 'staging_accepted', 'staged'=== $release_status || 'approved'===$release_status || 'deployed'===$release_status, $release_status, 'Hostinger-equivalent staging acceptance has not been recorded.', 'warning' );

		$overall = self::overall( $checks );
		$result = array(
			'trace_id'=>$trace,
			'overall_status'=>$overall,
			'checks'=>$checks,
			'checked_at'=>SPF_Runtime::now_mysql(),
			'redaction'=>'No secrets, filesystem paths, SQL text, credentials or personal data are included.',
		);
		if ( $persist ) {
			$stored = self::persist( $result );
			if ( is_wp_error( $stored ) ) {
				$result['overall_status'] = 'fail';
				$result['checks'][] = self::check( 'health_persistence', false, 'failed', 'Health result could not be persisted atomically.', 'fail' );
			}
		}
		return $result;
	}

	public static function latest() {
		global $wpdb;
		$table = SPF_Installer::table( 'health' );
		if ( ! SPF_Runtime::table_exists( $table ) ) {
			return null;
		}
		$row = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1", ARRAY_A ); // phpcs:ignore
		return $row ? json_decode( $row['results_json'], true ) : null;
	}

	private static function integration_checks() {
		$checks = array();
		$providers = array(
			'file00' => array( 'module'=>'file-00','available'=>has_filter('spf_file00_authorization_claim') || defined('SMC_VERSION') || function_exists('smc_membership_contract'),'message'=>'File 00 versioned authorization claims are unavailable; sensitive operations remain fail-closed.' ),
			'file20' => array( 'module'=>'file-20','available'=>function_exists('sabri_shell_register_route') || has_action('sabri_shell_register_provider'),'message'=>'File 20 shell provider is unavailable; no public route cutover is authorized.' ),
			'file21' => array( 'module'=>'file-21','available'=>has_filter('spf_owner_reconciliation_plan'),'message'=>'File 21 legacy reconciliation planning adapter is unavailable.' ),
			'file24' => array( 'module'=>'file-24','available'=>has_filter('spf_verify_file24_purge_assurance') || has_filter('spf_verify_backup_restore_evidence'),'message'=>'File 24/assurance evidence adapters are unavailable.' ),
			'file25' => array( 'module'=>'file-25','available'=>has_action('sabri_public_ui_register_component') || has_filter('sabri_public_ui_component_contract'),'message'=>'File 25 visual component contract is unavailable; File 01 exposes no replacement public UI.' ),
		);
		foreach ( $providers as $code => $provider ) {
			$manifest = SPF_Registry::get_module( $provider['module'] );
			$registered = is_array($manifest) && !in_array($manifest['state'],array('unregistered','retired','suspended'),true);
			$ok = $provider['available'] && $registered;
			$checks[] = self::check( $code.'_contract', $ok, $ok?($manifest['contract_version']??'registered'):($provider['available']?'adapter-without-manifest':'unavailable'), $provider['message'], 'warning' );
		}
		$catalog = SPF_Installer::canonical_module_catalog();
		$registered_keys = array_column( SPF_Registry::list_modules(array('limit'=>200)), 'module_key' );
		$missing = array_values(array_filter(array_column($catalog,'module_key'),static fn($key)=>!in_array($key,$registered_keys,true)));
		$checks[] = self::check( 'owner_manifests', empty($missing), empty($missing)?'all-owner-manifests-registered':(count($missing).'-owner-manifests-pending'), 'Canonical catalog entries are not runtime manifests; each owner must register a real versioned manifest.', 'warning' );
		return $checks;
	}

	private static function queue_checks() {
		global $wpdb;
		$table = SPF_Installer::table('outbox');
		if ( ! SPF_Runtime::table_exists($table) ) {
			return array(self::check('outbox',false,'missing','Outbox table is missing.','fail'));
		}
		$pending_raw=$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','retry','processing')"); // phpcs:ignore
		if(!empty($wpdb->last_error)){return array(self::check('outbox_query',false,'query-failed','Outbox health query failed.','fail'));}
		$dead_raw=$wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='dead'"); // phpcs:ignore
		if(!empty($wpdb->last_error)){return array(self::check('outbox_query',false,'query-failed','Outbox dead-letter query failed.','fail'));}
		$oldest_raw=$wpdb->get_var("SELECT MIN(created_at) FROM {$table} WHERE status IN ('pending','retry','processing')"); // phpcs:ignore
		if(!empty($wpdb->last_error)){return array(self::check('outbox_query',false,'query-failed','Outbox age query failed.','fail'));}
		$pending=(int)$pending_raw;
		$dead=(int)$dead_raw;
		$oldest=(string)$oldest_raw;
		$stale=$oldest && strtotime($oldest)<time()-3600;
		return array(
			self::check('outbox_backlog',!$stale,$stale?'stale':('pending-'.$pending),'Outbox contains events pending for more than one hour.','warning'),
			self::check('outbox_dead_letters',0===$dead,(string)$dead,'Outbox contains dead-letter events requiring operator review.','warning'),
		);
	}

	private static function privacy_checks() {
		global $wpdb;
		$checks=array();
		$table=SPF_Installer::table('privacy_requests');
		if ( ! SPF_Runtime::table_exists( $table ) ) {
			$checks[]=self::check('privacy_requests_registry',false,'missing','Privacy request registry is unavailable.','fail');
		} else {
			$overdue_raw=$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status NOT IN ('completed','rejected') AND due_at<%s",SPF_Runtime::now_mysql()));
			if ( ! empty( $wpdb->last_error ) || null === $overdue_raw ) {
				$checks[]=self::check('privacy_requests_query',false,'query-failed','Privacy request health query failed.','fail');
			} else {
				$overdue=(int)$overdue_raw;
				$checks[]=self::check('privacy_requests_overdue',0===$overdue,(string)$overdue,'Privacy requests are overdue.','warning');
			}
		}
		$holds=SPF_Installer::table('privacy_holds');
		if ( ! SPF_Runtime::table_exists( $holds ) ) {
			$checks[]=self::check('privacy_holds_registry',false,'missing','Privacy hold registry is unavailable.','fail');
		} else {
			$active_raw=$wpdb->get_var("SELECT COUNT(*) FROM {$holds} WHERE active=1"); // phpcs:ignore
			if ( ! empty( $wpdb->last_error ) || null === $active_raw ) {
				$checks[]=self::check('privacy_holds_query',false,'query-failed','Privacy hold health query failed.','fail');
			} else {
				$active=(int)$active_raw;
				$checks[]=self::check('privacy_holds_registry',true,'active-'.$active,'Privacy hold registry is unavailable.','fail');
			}
		}
		$retention=wp_next_scheduled('spf_privacy_retention');
		$checks[]=self::check('privacy_retention_schedule',(bool)$retention,$retention?'scheduled':'missing','Privacy retention job is not scheduled.','fail');
		return $checks;
	}

	private static function transaction_probe() {
		global $wpdb;
		$table=$wpdb->prefix.'spf_tx_probe';
		$wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$table}"); // phpcs:ignore
		if(false===$wpdb->query("CREATE TEMPORARY TABLE {$table} (id INT PRIMARY KEY) ENGINE=InnoDB")){return false;} // phpcs:ignore
		if(false===$wpdb->query('START TRANSACTION')){return false;}
		if(false===$wpdb->query("INSERT INTO {$table} (id) VALUES (1)")){ $wpdb->query('ROLLBACK'); return false; } // phpcs:ignore
		if(false===$wpdb->query('ROLLBACK')){return false;}
		$count_raw=$wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore
		if(!empty($wpdb->last_error) || null===$count_raw){return false;}
		$count=(int)$count_raw;
		$wpdb->query("DROP TEMPORARY TABLE IF EXISTS {$table}"); // phpcs:ignore
		return 0===$count;
	}

	private static function cache_probe() {
		$key='probe_'.wp_generate_password(12,false,false);
		$value=wp_generate_password(20,false,false);
		wp_cache_set($key,$value,'sabri_platform_foundation',60);
		$read=wp_cache_get($key,'sabri_platform_foundation');
		wp_cache_delete($key,'sabri_platform_foundation');
		return hash_equals($value,(string)$read);
	}

	private static function persist( array $result ) {
		global $wpdb;
		$tx=SPF_Runtime::begin();
		if(is_wp_error($tx)){return $tx;}
		try{
			$ok=$wpdb->insert(SPF_Installer::table('health'),array('trace_id'=>$result['trace_id'],'overall_status'=>$result['overall_status'],'results_json'=>wp_json_encode($result),'created_at'=>SPF_Runtime::now_mysql()),array('%s','%s','%s','%s'));
			if(false===$ok){throw new RuntimeException('Health row insert failed.');}
			$audit=SPF_Audit::record_required('system_check','foundation_health',$result['trace_id'],$result['overall_status'],array('purpose'=>'operational_health'),$result['trace_id']);
			if(is_wp_error($audit)){throw new RuntimeException($audit->get_error_message());}
			$event=SPF_Event_Bus::publish('FoundationHealthChanged.v1','foundation_health',$result['trace_id'],array('status'=>$result['overall_status']),1,'health-'.$result['trace_id']);
			if(is_wp_error($event)){throw new RuntimeException($event->get_error_message());}
			$commit=SPF_Runtime::commit();
			if(is_wp_error($commit)){throw new RuntimeException($commit->get_error_message());}
			return true;
		}catch(Throwable $e){SPF_Runtime::rollback();return new WP_Error('spf_health_persist_failed',$e->getMessage());}
	}

	private static function overall( array $checks ) {
		$overall='pass';
		foreach($checks as $check){if('fail'===$check['status']){return 'fail';}if('warning'===$check['status']){$overall='warning';}}
		return $overall;
	}

	private static function check( $code, $passed, $value, $failure_message, $severity ) {
		$status=$passed?'pass':('warning'===$severity?'warning':'fail');
		return array('code'=>sanitize_key($code),'status'=>$status,'value'=>substr(sanitize_text_field((string)$value),0,191),'message'=>$passed?'OK':sanitize_text_field($failure_message));
	}
}
