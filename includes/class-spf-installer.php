<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Installer {
	const LOCK_OPTION     = 'spf_activation_lock';
	const SNAPSHOT_OPTION = 'spf_activation_snapshot';
	const VERSION_OPTION  = 'spf_version';
	const SCHEMA_OPTION   = 'spf_schema_version';
	const CONTRACT_OPTION = 'spf_contract_version';

	private static $internal_seed_depth = 0;

	public static function table_names() {
		return array(
			'modules', 'contracts', 'routes', 'releases', 'release_states', 'amendments',
			'health', 'flags', 'audit', 'idempotency', 'outbox', 'privacy_requests',
			'privacy_holds', 'migrations',
		);
	}

	public static function table( $name ) {
		global $wpdb;
		if ( ! in_array( $name, self::table_names(), true ) ) {
			wp_die( 'Invalid File 01 table.' );
		}
		return $wpdb->prefix . 'spf_' . $name;
	}

	public static function with_internal_seed( callable $callback ) {
		self::$internal_seed_depth++;
		try {
			return $callback();
		} finally {
			self::$internal_seed_depth--;
		}
	}

	public static function is_internal_seed() {
		return self::$internal_seed_depth > 0;
	}

	public static function activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to activate File 01.', 'sabri-platform-foundation' ) );
		}
		$lock = SPF_Runtime::acquire_lock( 'activation', 900, get_current_user_id() );
		if ( is_wp_error( $lock ) ) {
			wp_die( esc_html( $lock->get_error_message() ) );
		}
		$trace = SPF_Audit::trace_id();
		$snapshot = array();
		try {
			$snapshot = self::capture_runtime_snapshot( $lock );
			self::install_schema();
			$verified = self::verify_schema();
			if ( is_wp_error( $verified ) ) {
				throw new RuntimeException( $verified->get_error_message() );
			}
			SPF_Authorization::install_capabilities();
			self::seed_governance();
			update_option( self::VERSION_OPTION, SPF_VERSION, false );
			update_option( self::SCHEMA_OPTION, SPF_SCHEMA_VERSION, false );
			update_option( self::CONTRACT_OPTION, SPF_CONTRACT_VERSION, false );
			update_option(
				'spf_activation_state',
				array(
					'status'       => 'active',
					'trace_id'     => $trace,
					'activated_at' => SPF_Runtime::now_mysql(),
					'version'      => SPF_VERSION,
				),
				false
			);
			$scheduled = self::schedule_jobs();
			if ( is_wp_error( $scheduled ) ) {
				throw new RuntimeException( $scheduled->get_error_message() );
			}
			$audit = SPF_Audit::record_required( 'activation', 'foundation', 'file-01', 'success', array( 'purpose' => 'plugin_activation', 'version' => SPF_VERSION ), $trace );
			if ( is_wp_error( $audit ) ) {
				throw new RuntimeException( $audit->get_error_message() );
			}
			$event = SPF_Event_Bus::publish( 'FoundationModuleActivated.v1', 'foundation_module', 'file-01', array( 'version' => SPF_VERSION, 'schema_version' => SPF_SCHEMA_VERSION ), 1, 'file-01-activation-' . SPF_VERSION );
			if ( is_wp_error( $event ) ) {
				throw new RuntimeException( $event->get_error_message() );
			}
			self::discard_shadow_backups( $snapshot );
			SPF_Plugin::instance()->register_restricted_routes();
			flush_rewrite_rules( false );
		} catch ( Throwable $error ) {
			$compensation = self::restore_runtime_snapshot( $snapshot );
			$compensated = ! is_wp_error( $compensation );
			update_option( 'spf_activation_state', array( 'status' => 'failed', 'trace_id' => $trace, 'failed_at' => SPF_Runtime::now_mysql(), 'error_code' => $compensated ? 'activation_compensated' : 'activation_compensation_incomplete' ), false );
			SPF_Runtime::release_lock( 'activation', $lock );
			$message = 'File 01 activation failed: ' . $error->getMessage();
			if ( is_wp_error( $compensation ) ) {
				$message .= ' Compensation verification also failed: ' . $compensation->get_error_message();
			}
			wp_die( esc_html( $message ) );
		}
		SPF_Runtime::release_lock( 'activation', $lock );
	}

	public static function deactivate() {
		self::unschedule_jobs();
		update_option( 'spf_activation_state', array( 'status' => 'inactive', 'deactivated_at' => SPF_Runtime::now_mysql(), 'version' => SPF_VERSION ), false );
		if ( class_exists( 'SPF_Audit', false ) && SPF_Runtime::table_exists( self::table( 'audit' ) ) ) {
			SPF_Audit::record( 'deactivation', 'foundation', 'file-01', 'success', array( 'purpose' => 'plugin_deactivation' ) );
			SPF_Event_Bus::publish( 'FoundationModuleDeactivated.v1', 'foundation_module', 'file-01', array( 'version' => SPF_VERSION ), 1, 'file-01-deactivation-' . gmdate( 'YmdHi' ) );
		}
		flush_rewrite_rules( false );
	}

	/**
	 * Explicit, evidence-gated schema upgrade. It never runs as an unaudited
	 * background mutation. Staging may opt into the internal shadow backup gate
	 * with SPF_ALLOW_INTERNAL_SNAPSHOT_UPGRADE=true; production should supply a
	 * File 24/operations verification claim.
	 */
	public static function maybe_upgrade() {
		$current = get_option( self::SCHEMA_OPTION, '0.0.0' );
		if ( ! SPF_Registry::valid_semver( (string) $current ) || version_compare( $current, SPF_SCHEMA_VERSION, '>=' ) ) {
			return true;
		}
		if ( ! SPF_Authorization::can( 'run_schema_upgrade', array( 'module_key' => 'file-01', 'from' => $current, 'to' => SPF_SCHEMA_VERSION ), array( 'purpose' => 'schema_upgrade' ) ) ) {
			update_option( 'spf_upgrade_state', array( 'status' => 'authorization_required', 'from' => $current, 'to' => SPF_SCHEMA_VERSION, 'checked_at' => SPF_Runtime::now_mysql() ), false );
			return new WP_Error( 'spf_upgrade_authorization_required', __( 'File 01 schema upgrade requires an authorized operator.', 'sabri-platform-foundation' ) );
		}
		$environment = self::environment();
		$evidence_context = array( 'module'=>'file-01', 'from'=>$current, 'to'=>SPF_SCHEMA_VERSION, 'environment'=>$environment );
		$evidence = SPF_Runtime::verify_evidence(
			'spf_verify_migration_backup_evidence',
			$evidence_context,
			array( 'backup_id','restore_tested_at','environment','verifier','module','from','to' )
		);
		if ( ! is_wp_error( $evidence ) ) {
			foreach ( array( 'module','from','to','environment' ) as $binding_field ) {
				if ( ! array_key_exists( $binding_field, $evidence ) || ! hash_equals( (string) $evidence_context[ $binding_field ], (string) $evidence[ $binding_field ] ) ) {
					return new WP_Error( 'spf_migration_backup_evidence_binding_invalid', __( 'Migration backup evidence is not bound to this exact File 01 upgrade context.', 'sabri-platform-foundation' ), array( 'status'=>412, 'field'=>$binding_field ) );
				}
			}
		}
		$internal_snapshot_allowed = defined( 'SPF_ALLOW_INTERNAL_SNAPSHOT_UPGRADE' ) && true === SPF_ALLOW_INTERNAL_SNAPSHOT_UPGRADE && in_array( $environment, array( 'local','development','staging' ), true );
		if ( is_wp_error( $evidence ) && ! $internal_snapshot_allowed ) {
			update_option( 'spf_upgrade_state', array( 'status'=>'backup_evidence_required', 'from'=>$current, 'to'=>SPF_SCHEMA_VERSION, 'environment'=>$environment, 'checked_at'=>SPF_Runtime::now_mysql() ), false );
			return $evidence;
		}
		if ( is_wp_error( $evidence ) ) {
			$evidence = array( 'verified'=>true, 'mode'=>'internal_shadow_snapshot', 'environment'=>$environment, 'verifier'=>'SPF_ALLOW_INTERNAL_SNAPSHOT_UPGRADE', 'evidence_hash'=>'internal' );
		}
		return self::run_upgrade( $current, SPF_SCHEMA_VERSION, $evidence );
	}

	private static function run_upgrade( $from, $to, array $evidence ) {
		global $wpdb;
		$lock = SPF_Runtime::acquire_lock( 'schema_upgrade', 1800, get_current_user_id() );
		if ( is_wp_error( $lock ) ) {
			return $lock;
		}
		$snapshot = array();
		$migration_id = wp_generate_uuid4();
		try {
			$snapshot = self::capture_runtime_snapshot( $lock );
			self::install_schema();
			$verified = self::verify_schema();
			if ( is_wp_error( $verified ) ) {
				throw new RuntimeException( $verified->get_error_message() );
			}
			self::seed_governance();
			$inserted = $wpdb->insert(
				self::table( 'migrations' ),
				array(
					'migration_id' => $migration_id,
					'from_version' => sanitize_text_field( $from ),
					'to_version'   => sanitize_text_field( $to ),
					'status'       => 'completed',
					'snapshot_ref' => sanitize_text_field( $snapshot['snapshot_id'] ?? '' ),
					'evidence_json'=> wp_json_encode( SPF_Runtime::canonicalize( $evidence ) ),
					'started_at'   => $snapshot['captured_at'] ?? SPF_Runtime::now_mysql(),
					'completed_at' => SPF_Runtime::now_mysql(),
					'record_version'=> 1,
				),
				array( '%s','%s','%s','%s','%s','%s','%s','%s','%d' )
			);
			if ( false === $inserted ) {
				throw new RuntimeException( 'Migration evidence could not be stored.' );
			}
			update_option( self::SCHEMA_OPTION, $to, false );
			update_option( self::VERSION_OPTION, SPF_VERSION, false );
			update_option( self::CONTRACT_OPTION, SPF_CONTRACT_VERSION, false );
			update_option( 'spf_upgrade_state', array( 'status' => 'completed', 'migration_id' => $migration_id, 'from' => $from, 'to' => $to, 'completed_at' => SPF_Runtime::now_mysql() ), false );
			$audit = SPF_Audit::record_required( 'schema_upgrade', 'foundation_migration', $migration_id, 'success', array( 'purpose' => 'schema_upgrade', 'from' => $from, 'to' => $to, 'evidence_hash' => $evidence['evidence_hash'] ?? '' ) );
			if ( is_wp_error( $audit ) ) {
				throw new RuntimeException( $audit->get_error_message() );
			}
			$event = SPF_Event_Bus::publish( 'FoundationSchemaMigrated.v1', 'foundation_migration', $migration_id, array( 'from' => $from, 'to' => $to ), 1, 'schema-' . $to );
			if ( is_wp_error( $event ) ) {
				throw new RuntimeException( $event->get_error_message() );
			}
			self::discard_shadow_backups( $snapshot );
			SPF_Runtime::release_lock( 'schema_upgrade', $lock );
			return true;
		} catch ( Throwable $error ) {
			$compensation = self::restore_runtime_snapshot( $snapshot );
			$compensated = ! is_wp_error( $compensation );
			update_option( 'spf_upgrade_state', array( 'status' => $compensated ? 'rolled_back' : 'rollback_incomplete', 'migration_id' => $migration_id, 'from' => $from, 'to' => $to, 'failed_at' => SPF_Runtime::now_mysql(), 'error_code' => $compensated ? 'upgrade_compensated' : 'upgrade_compensation_incomplete' ), false );
			SPF_Runtime::release_lock( 'schema_upgrade', $lock );
			$message = $error->getMessage();
			if ( is_wp_error( $compensation ) ) {
				$message .= ' Compensation verification failed: ' . $compensation->get_error_message();
			}
			return new WP_Error( 'spf_upgrade_failed', $message, array( 'status' => 500, 'migration_id' => $migration_id, 'compensated' => $compensated ) );
		}
	}

	public static function install_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$engine = ' ENGINE=InnoDB ';
		$sql = array();

		$sql[] = "CREATE TABLE " . self::table( 'modules' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			module_key varchar(64) NOT NULL,
			owner_file varchar(16) NOT NULL,
			owner_name varchar(191) NOT NULL,
			slug varchar(191) NOT NULL,
			namespace_prefix varchar(64) NOT NULL DEFAULT '',
			software_version varchar(32) NOT NULL,
			contract_version varchar(32) NOT NULL,
			state varchar(32) NOT NULL DEFAULT 'registered',
			manifest_json longtext NOT NULL,
			record_version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY module_key (module_key),
			KEY owner_file (owner_file),
			KEY state (state)
		) {$engine} {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'contracts' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			contract_key varchar(128) NOT NULL,
			contract_version varchar(32) NOT NULL,
			owner_module varchar(64) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'draft',
			schema_json longtext NOT NULL,
			consumers_json longtext NOT NULL,
			acknowledgements_json longtext NOT NULL,
			deprecation_at datetime NULL,
			record_version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY contract_version (contract_key,contract_version),
			KEY owner_module (owner_module),
			KEY status (status)
		) {$engine} {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'routes' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			route_key varchar(128) NOT NULL,
			route_path varchar(191) NOT NULL,
			owner_module varchar(64) NOT NULL,
			page_id bigint(20) unsigned NULL,
			layout_context varchar(64) NOT NULL DEFAULT 'minimal',
			status varchar(32) NOT NULL DEFAULT 'registered',
			destination varchar(255) NOT NULL DEFAULT '',
			redirects_json longtext NOT NULL,
			record_version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY route_key (route_key),
			UNIQUE KEY route_path (route_path),
			KEY owner_module (owner_module),
			KEY status (status)
		) {$engine} {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'releases' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			release_id char(36) NOT NULL,
			software_version varchar(32) NOT NULL,
			commit_sha varchar(64) NOT NULL,
			package_name varchar(191) NOT NULL,
			checksum_sha256 char(64) NOT NULL,
			schema_version varchar(32) NOT NULL,
			evidence_json longtext NOT NULL,
			evidence_hash char(64) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'planned',
			approved_by bigint(20) unsigned NOT NULL DEFAULT 0,
			approved_at datetime NULL,
			deployed_at datetime NULL,
			record_version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY release_id (release_id),
			UNIQUE KEY checksum_sha256 (checksum_sha256),
			KEY status (status),
			KEY software_version (software_version)
		) {$engine} {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'release_states' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			release_id char(36) NOT NULL,
			sequence_no int(10) unsigned NOT NULL,
			status varchar(32) NOT NULL,
			evidence_json longtext NOT NULL,
			evidence_hash char(64) NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY release_sequence (release_id,sequence_no),
			KEY release_id (release_id),
			KEY status (status)
		) {$engine} {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'amendments' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			amendment_id varchar(64) NOT NULL,
			effective_at datetime NOT NULL,
			supersedes varchar(191) NOT NULL DEFAULT '',
			decision_json longtext NOT NULL,
			approver_ref varchar(191) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'approved',
			record_version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY amendment_id (amendment_id),
			KEY status (status)
		) {$engine} {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'health' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			trace_id char(36) NOT NULL,
			overall_status varchar(32) NOT NULL,
			results_json longtext NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY trace_id (trace_id),
			KEY overall_status (overall_status),
			KEY created_at (created_at)
		) {$engine} {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'flags' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			owner_module varchar(64) NOT NULL,
			flag_key varchar(128) NOT NULL,
			environment varchar(32) NOT NULL,
			enabled tinyint(1) NOT NULL DEFAULT 0,
			expires_at datetime NULL,
			reason varchar(500) NOT NULL,
			record_version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY owner_flag (owner_module,flag_key,environment),
			KEY expires_at (expires_at)
		) {$engine} {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'audit' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			trace_id char(36) NOT NULL,
			action_name varchar(128) NOT NULL,
			object_type varchar(64) NOT NULL,
			object_id varchar(191) NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			purpose varchar(191) NOT NULL DEFAULT '',
			result_code varchar(64) NOT NULL,
			context_hash char(64) NOT NULL,
			previous_hash char(64) NOT NULL,
			entry_hash char(64) NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY entry_hash (entry_hash),
			KEY trace_id (trace_id),
			KEY actor_id (actor_id),
			KEY created_at (created_at)
		) {$engine} {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'idempotency' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			idempotency_key varchar(191) NOT NULL,
			scope_hash char(64) NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action_name varchar(128) NOT NULL,
			request_hash char(64) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'processing',
			owner_token char(36) NOT NULL,
			response_json longtext NOT NULL,
			response_status smallint(5) unsigned NOT NULL DEFAULT 0,
			attempts int(10) unsigned NOT NULL DEFAULT 1,
			locked_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY scope_hash (scope_hash),
			KEY expires_at (expires_at),
			KEY status (status)
		) {$engine} {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'outbox' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id char(36) NOT NULL,
			event_name varchar(191) NOT NULL,
			event_version int(10) unsigned NOT NULL,
			aggregate_type varchar(64) NOT NULL,
			aggregate_id varchar(191) NOT NULL,
			dedupe_key varchar(191) NOT NULL,
			payload_json longtext NOT NULL,
			privacy_class varchar(32) NOT NULL DEFAULT 'internal',
			status varchar(32) NOT NULL DEFAULT 'pending',
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			available_at datetime NOT NULL,
			sent_at datetime NULL,
			last_error varchar(191) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			UNIQUE KEY dedupe_key (dedupe_key),
			KEY due (status,available_at),
			KEY created_at (created_at)
		) {$engine} {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'privacy_requests' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_id char(36) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			request_type varchar(32) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'received',
			purpose varchar(191) NOT NULL,
			legal_basis varchar(191) NOT NULL,
			result_json longtext NOT NULL,
			record_version bigint(20) unsigned NOT NULL DEFAULT 1,
			requested_at datetime NOT NULL,
			due_at datetime NOT NULL,
			completed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY request_id (request_id),
			KEY user_status (user_id,status),
			KEY due_at (due_at)
		) {$engine} {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'privacy_holds' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			hold_id char(36) NOT NULL,
			subject_type varchar(64) NOT NULL,
			subject_id varchar(191) NOT NULL,
			reason varchar(500) NOT NULL,
			authority_ref varchar(191) NOT NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			released_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY hold_id (hold_id),
			KEY subject_active (subject_type,subject_id,active)
		) {$engine} {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'migrations' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			migration_id char(36) NOT NULL,
			from_version varchar(32) NOT NULL,
			to_version varchar(32) NOT NULL,
			status varchar(32) NOT NULL,
			snapshot_ref varchar(191) NOT NULL,
			evidence_json longtext NOT NULL,
			record_version bigint(20) unsigned NOT NULL DEFAULT 1,
			started_at datetime NOT NULL,
			completed_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY migration_id (migration_id),
			KEY status (status)
		) {$engine} {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
		// dbDelta does not always change an existing engine. Enforce the
		// transactional invariant explicitly on File 01-owned tables.
		foreach ( self::table_names() as $name ) {
			$table = self::table( $name );
			if ( SPF_Runtime::table_exists( $table ) && 'INNODB' !== SPF_Runtime::table_engine( $table ) ) {
				$wpdb->query( "ALTER TABLE {$table} ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted File 01 table.
			}
		}
	}

	public static function verify_schema() {
		global $wpdb;
		$missing = array();
		foreach ( self::table_names() as $name ) {
			$table = self::table( $name );
			if ( ! SPF_Runtime::table_exists( $table ) ) {
				$missing[ $name ] = 'missing_table';
				continue;
			}
			if ( 'INNODB' !== SPF_Runtime::table_engine( $table ) ) {
				$missing[ $name ] = 'non_transactional_engine';
			}
		}
		$required_columns = array(
			'idempotency' => array( 'scope_hash','request_hash','status','owner_token','response_status','locked_at','expires_at' ),
			'releases' => array( 'evidence_hash','record_version','approved_by','approved_at','deployed_at' ),
			'release_states' => array( 'evidence_hash','sequence_no' ),
			'privacy_requests' => array( 'request_id','request_type','legal_basis','due_at' ),
			'privacy_holds' => array( 'hold_id','subject_type','subject_id','active' ),
			'outbox' => array( 'event_id','event_version','payload_json','privacy_class','status','attempts','available_at' ),
		);
		foreach ( $required_columns as $name => $columns ) {
			$table = self::table( $name );
			if ( ! SPF_Runtime::table_exists( $table ) ) {
				continue;
			}
			$actual = $wpdb->get_col( "DESCRIBE {$table}", 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted table.
			foreach ( $columns as $column ) {
				if ( ! in_array( $column, $actual, true ) ) {
					$missing[ $name . '.' . $column ] = 'missing_column';
				}
			}
		}
		if ( ! empty( $missing ) ) {
			return new WP_Error( 'spf_schema_verification_failed', __( 'File 01 schema verification failed.', 'sabri-platform-foundation' ), array( 'defects' => $missing ) );
		}
		return self::verify_required_indexes();
	}

	public static function required_indexes() {
		return array(
			'modules'=>array('PRIMARY'=>array(array('id'),true),'module_key'=>array(array('module_key'),true),'owner_file'=>array(array('owner_file'),false),'state'=>array(array('state'),false)),
			'contracts'=>array('PRIMARY'=>array(array('id'),true),'contract_version'=>array(array('contract_key','contract_version'),true),'owner_module'=>array(array('owner_module'),false),'status'=>array(array('status'),false)),
			'routes'=>array('PRIMARY'=>array(array('id'),true),'route_key'=>array(array('route_key'),true),'route_path'=>array(array('route_path'),true),'owner_module'=>array(array('owner_module'),false),'status'=>array(array('status'),false)),
			'releases'=>array('PRIMARY'=>array(array('id'),true),'release_id'=>array(array('release_id'),true),'checksum_sha256'=>array(array('checksum_sha256'),true),'status'=>array(array('status'),false),'software_version'=>array(array('software_version'),false)),
			'release_states'=>array('PRIMARY'=>array(array('id'),true),'release_sequence'=>array(array('release_id','sequence_no'),true),'release_id'=>array(array('release_id'),false),'status'=>array(array('status'),false)),
			'amendments'=>array('PRIMARY'=>array(array('id'),true),'amendment_id'=>array(array('amendment_id'),true),'status'=>array(array('status'),false)),
			'health'=>array('PRIMARY'=>array(array('id'),true),'trace_id'=>array(array('trace_id'),true),'overall_status'=>array(array('overall_status'),false),'created_at'=>array(array('created_at'),false)),
			'flags'=>array('PRIMARY'=>array(array('id'),true),'owner_flag'=>array(array('owner_module','flag_key','environment'),true),'expires_at'=>array(array('expires_at'),false)),
			'audit'=>array('PRIMARY'=>array(array('id'),true),'entry_hash'=>array(array('entry_hash'),true),'trace_id'=>array(array('trace_id'),false),'actor_id'=>array(array('actor_id'),false),'created_at'=>array(array('created_at'),false)),
			'idempotency'=>array('PRIMARY'=>array(array('id'),true),'scope_hash'=>array(array('scope_hash'),true),'expires_at'=>array(array('expires_at'),false),'status'=>array(array('status'),false)),
			'outbox'=>array('PRIMARY'=>array(array('id'),true),'event_id'=>array(array('event_id'),true),'dedupe_key'=>array(array('dedupe_key'),true),'due'=>array(array('status','available_at'),false),'created_at'=>array(array('created_at'),false)),
			'privacy_requests'=>array('PRIMARY'=>array(array('id'),true),'request_id'=>array(array('request_id'),true),'user_status'=>array(array('user_id','status'),false),'due_at'=>array(array('due_at'),false)),
			'privacy_holds'=>array('PRIMARY'=>array(array('id'),true),'hold_id'=>array(array('hold_id'),true),'subject_active'=>array(array('subject_type','subject_id','active'),false)),
			'migrations'=>array('PRIMARY'=>array(array('id'),true),'migration_id'=>array(array('migration_id'),true),'status'=>array(array('status'),false))
		);
	}

	public static function verify_required_indexes() {
		global $wpdb;
		foreach ( self::required_indexes() as $name => $required ) {
			$table = self::table( $name );
			if ( ! SPF_Runtime::table_exists( $table ) ) {
				return new WP_Error( 'spf_schema_missing_table', 'Missing File 01 table: ' . $name );
			}
			$rows = $wpdb->get_results( 'SHOW INDEX FROM ' . $table, ARRAY_A );
			$actual = array();
			foreach ( (array) $rows as $row ) {
				$key = (string) $row['Key_name'];
				$seq = (int) $row['Seq_in_index'];
				$actual[$key]['columns'][$seq] = (string) $row['Column_name'];
				$actual[$key]['unique'] = 0 === (int) $row['Non_unique'];
			}
			foreach ( $actual as &$index ) {
				ksort( $index['columns'] );
				$index['columns'] = array_values( $index['columns'] );
			}
			unset( $index );
			foreach ( $required as $key => $spec ) {
				list( $columns, $unique ) = $spec;
				if ( empty( $actual[$key] ) || $actual[$key]['columns'] !== $columns || (bool)$actual[$key]['unique'] !== (bool)$unique ) {
					return new WP_Error( 'spf_schema_index_invalid', 'Missing or invalid File 01 index: ' . $name . '.' . $key, array( 'table'=>$name, 'index'=>$key, 'expected_columns'=>$columns, 'expected_unique'=>(bool)$unique ) );
				}
			}
		}
		return true;
	}

	private static function seed_governance() {
		global $wpdb;
		$now = SPF_Runtime::now_mysql();
		$amendments = array(
			array( 'amendment_id' => 'SSH-PMP-2026-v3.0', 'effective_at' => '2026-07-31 00:00:00', 'supersedes' => 'Foundation 0.25; Comprehensive Master Plan 2.0', 'decision' => array( 'type' => 'governing_constitution', 'numbering' => '00-26', 'runtime' => '01-B' ) ),
			array( 'amendment_id' => 'SSH-DIRECTIVES-2026-v2.1', 'effective_at' => '2026-08-05 10:47:00', 'supersedes' => 'conflicting earlier chat directives', 'decision' => array( 'type' => 'directive_register', 'primary_color' => 'green', 'navigation_owner' => '20', 'visual_owner' => '25', 'file_26' => 'approved' ) ),
			array( 'amendment_id' => 'SSH-CONTINUOUS-VALUE-2026-v1.0', 'effective_at' => '2026-08-06 00:00:00', 'supersedes' => 'conflicting paid-tier and donor-advantage rules', 'decision' => array( 'type' => 'central_plan_3', 'files' => '00-26', 'tier_model' => 'single_free_tier', 'donation_only' => true, 'donor_advantage' => false ) ),
			array( 'amendment_id' => 'F01-REPOSITORY-ALIAS-2026-08-06', 'effective_at' => '2026-08-06 00:00:00', 'supersedes' => 'plan repository label only', 'decision' => array( 'type' => 'repository_alias', 'canonical_repository' => '01-sabri-platform-foundation', 'package_folder' => 'sabri-platform-foundation-01', 'reason' => 'Founder-selected repository retained; runtime slug unchanged.' ) ),
		);
		foreach ( $amendments as $item ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO " . self::table( 'amendments' ) . " (amendment_id,effective_at,supersedes,decision_json,approver_ref,status,record_version,created_at) VALUES (%s,%s,%s,%s,%s,'approved',1,%s)",
					$item['amendment_id'], $item['effective_at'], $item['supersedes'], wp_json_encode( $item['decision'] ), 'Founder-approved governing source', $now
				)
			);
			if ( false === $result ) {
				throw new RuntimeException( 'A governing amendment could not be seeded.' );
			}
		}

		self::with_internal_seed(
			static function () {
				$manifest = self::file01_manifest();
				$result = SPF_Registry::register_manifest( $manifest, array( 'purpose' => 'activation_seed' ) );
				if ( is_wp_error( $result ) ) {
					throw new RuntimeException( $result->get_error_message() );
				}
				foreach ( self::foundation_routes() as $route ) {
					$result = SPF_Registry::map_route( $route, array( 'purpose' => 'activation_seed' ) );
					if ( is_wp_error( $result ) ) {
						throw new RuntimeException( $result->get_error_message() );
					}
				}
				foreach ( SPF_Plugin::builtin_contract_definitions() as $contract ) {
					$result = SPF_Registry::register_contract( $contract, array( 'purpose' => 'activation_seed' ) );
					if ( is_wp_error( $result ) ) {
						throw new RuntimeException( $result->get_error_message() );
					}
				}
			}
		);
	}

	public static function file01_manifest() {
		return array(
			'module_key'       => 'file-01',
			'owner_file'       => '01',
			'owner_name'       => 'Sabri Platform Foundation',
			'slug'             => 'sabri-platform-foundation',
			'namespace_prefix' => 'SPF_',
			'software_version' => SPF_VERSION,
			'contract_version' => SPF_CONTRACT_VERSION,
			'state'            => 'active',
			'required'         => array(),
			'optional'         => array(
				array( 'module_key' => 'file-00', 'minimum_version' => '1.2.13', 'maximum_version' => '', 'purpose' => 'versioned authorization and current institutional-role claims', 'fail_mode' => 'Sensitive actions fail closed; read-only diagnostics may remain under bootstrap policy' ),
				array( 'module_key' => 'file-20', 'minimum_version' => '1.2.0', 'maximum_version' => '', 'purpose' => 'shell provider and route placement', 'fail_mode' => 'No duplicate shell; File 01 remains restricted/admin-only' ),
				array( 'module_key' => 'file-21', 'minimum_version' => '1.0.1', 'maximum_version' => '', 'purpose' => 'canonical Home/News owner for legacy reconciliation', 'fail_mode' => 'Legacy reconciliation is blocked until a versioned reversible owner adapter is available' ),
				array( 'module_key' => 'file-24', 'minimum_version' => '0.25.0', 'maximum_version' => '', 'purpose' => 'assurance evidence', 'fail_mode' => 'Destructive or production-grade claims remain gated without independently verified evidence' ),
				array( 'module_key' => 'file-26', 'minimum_version' => '0.1.0', 'maximum_version' => '', 'purpose' => 'canonical search/discovery/ranking owner registration', 'fail_mode' => 'File 01 never creates a parallel search, discovery or ranking truth' ),
			),
			'capabilities'     => array( 'registry', 'contracts', 'foundational_routes', 'dependency_readiness', 'system_check', 'release_evidence', 'legacy_reconciliation', 'safe_repair', 'privacy_lifecycle', 'event_backbone', 'feature_flags', 'policy_as_code', 'amendment_impact_simulation', 'architecture_lint', 'spec_code_traceability', 'developer_service_catalog', 'golden_path_scaffolder', 'contract_compatibility_lab', 'event_schema_registry', 'config_drift_detection', 'release_train_planning', 'progressive_delivery', 'slo_error_budget_gate', 'platform_digital_twin', 'bounded_self_heal', 'chaos_harness', 'telemetry_context', 'governance_snapshots', 'ai_governance_advisory' ),
			'commands'         => array( 'RegisterFoundationManifest.v1', 'RegisterFoundationContract.v1', 'MapFoundationRoute.v1', 'TransitionFoundationRelease.v1' ),
			'queries'          => array( 'GetFoundationReadiness.v1', 'ListFoundationContracts.v1', 'GetFoundationStatus.v1' ),
			'events'           => array( 'FoundationModuleActivated.v1', 'FoundationModuleDeactivated.v1', 'FoundationContractDeprecated.v1', 'FoundationHealthChanged.v1', 'FoundationReleaseStateChanged.v1', 'ReleaseApproved.v1' ),
			'routes'           => array( '/platform-system-check/', '/platform-foundation/status/' ),
			'data_classes'     => array( 'operational', 'governance', 'audit', 'privacy_request' ),
			'health'           => array( 'callback' => 'spf_foundation_status', 'contract' => 'FoundationHealth.v1' ),
			'source'           => 'SSH-F01-PLAN-2026-v1.0',
			'governing_sources'=> array( 'SSH-F01-PLAN-2026-v1.0', 'File 01 Future Foundation 18 Enhancements v2.0', 'Continuous Value / Third Central Plan v1.0' ),
		);
	}

	public static function canonical_module_catalog() {
		$names = array(
			'00'=>'Sabri Membership Core','01'=>'Sabri Platform Foundation','02'=>'Authentication and Accounts','03'=>'Profiles and Doctors','04'=>'News Feed and Publishing — Legacy Foundation Adapter','05'=>'Learn Sabri Classical Homeopathy','06'=>'Homeopathy Encyclopedia','07'=>'Doctors Directory and Discovery','08'=>'Worldwide Clinic and Appointments','09'=>'Global Doctor Onboarding and Verification','10'=>'Video Wall and Live Broadcasting','11'=>'Reels and Short Video Discovery','12'=>'PDF Library and Digital Reading','13'=>'Welcome Intro Animation','14'=>'Global Clinic USP and Conversion Integration','15'=>'Radar, Symptom, Remedy Research and Trend Intelligence','16'=>'Sabri Classical Homeopathy AI','17'=>'Communication Network','18'=>'Marketplace','19'=>'Unified Notifications and Alerts','20'=>'Sabri Unified Application Shell','21'=>'Sabri Complete Home and News Feed','22'=>'Universal Post Composer','23'=>'Doctor and Founder Publishing Dashboard','24'=>'Sabri Platform Security, Privacy, Compliance and Resilience Center','25'=>'Sabri Unified Global Visual Experience and Design System','26'=>'Search, Discovery and Ranking',
		);
		$result = array();
		foreach ( $names as $file => $name ) {
			$result[] = array( 'module_key' => 'file-' . $file, 'owner_file' => $file, 'owner_name' => $name, 'registration_state' => 'owner_manifest_required' );
		}
		return $result;
	}

	private static function foundation_routes() {
		return array(
			array( 'route_key'=>'file01-system-check','route_path'=>'/platform-system-check/','owner_module'=>'file-01','layout_context'=>'minimal','status'=>'active','destination'=>admin_url( 'tools.php?page=sabri-foundation' ),'redirects'=>array() ),
			array( 'route_key'=>'file01-foundation-status','route_path'=>'/platform-foundation/status/','owner_module'=>'file-01','layout_context'=>'minimal','status'=>'active','destination'=>admin_url( 'tools.php?page=sabri-foundation' ),'redirects'=>array() ),
		);
	}


	private static function schedule_jobs() {
		$jobs = array(
			array( 'hook' => 'spf_dispatch_outbox', 'time' => time() + 120, 'recurrence' => 'spf_five_minutes' ),
			array( 'hook' => 'spf_privacy_retention', 'time' => time() + HOUR_IN_SECONDS, 'recurrence' => 'daily' ),
			array( 'hook' => 'spf_reconcile_expired_flags', 'time' => time() + 300, 'recurrence' => 'hourly' ),
			array( 'hook' => 'spf_future_foundation_tick', 'time' => time() + 300, 'recurrence' => 'spf_five_minutes' ),
		);
		foreach ( $jobs as $job ) {
			if ( wp_next_scheduled( $job['hook'] ) ) {
				continue;
			}
			$result = wp_schedule_event( $job['time'], $job['recurrence'], $job['hook'], array(), true );
			if ( is_wp_error( $result ) || false === $result ) {
				return is_wp_error( $result ) ? $result : new WP_Error( 'spf_schedule_failed', __( 'A File 01 scheduled job could not be registered.', 'sabri-platform-foundation' ) );
			}
		}
		return true;
	}

	private static function unschedule_jobs() {
		foreach ( array( 'spf_dispatch_outbox', 'spf_privacy_retention', 'spf_reconcile_expired_flags', 'spf_future_foundation_tick' ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				$timestamp = wp_next_scheduled( $hook );
			}
		}
	}

	private static function capture_runtime_snapshot( $token ) {
		global $wpdb;
		$snapshot_id = wp_generate_uuid4();
		$snapshot = array(
			'snapshot_id' => $snapshot_id,
			'captured_at' => SPF_Runtime::now_mysql(),
			'options'     => array(),
			'admin_caps'  => array(),
			'schedules'   => array(),
			'preexisting_tables' => array(),
			'shadow_tables' => array(),
		);
		foreach ( self::owned_options() as $option ) {
			$sentinel = new stdClass();
			$value = get_option( $option, $sentinel );
			$snapshot['options'][ $option ] = array( 'exists' => $value !== $sentinel, 'value' => $value !== $sentinel ? $value : null );
		}
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( array( SPF_Authorization::CAP_VIEW, SPF_Authorization::CAP_MANAGE, SPF_Authorization::CAP_RELEASE, SPF_Authorization::CAP_FOUNDER, SPF_Authorization::CAP_PURGE ) as $cap ) {
				$snapshot['admin_caps'][ $cap ] = $role->has_cap( $cap );
			}
		}
		foreach ( array( 'spf_dispatch_outbox','spf_privacy_retention','spf_reconcile_expired_flags','spf_future_foundation_tick' ) as $hook ) {
			$snapshot['schedules'][ $hook ] = wp_next_scheduled( $hook );
		}
		$shadow_prefix = $wpdb->prefix . 'spf_shadow_' . substr( md5( $token . $snapshot_id ), 0, 8 ) . '_';
		try {
			foreach ( self::table_names() as $name ) {
				$table = self::table( $name );
				$exists = SPF_Runtime::table_exists( $table );
				$snapshot['preexisting_tables'][ $name ] = $exists;
				if ( $exists ) {
					$shadow = $shadow_prefix . $name;
					$wpdb->query( "DROP TABLE IF EXISTS {$shadow}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated allowlisted shadow.
					if ( false === $wpdb->query( "CREATE TABLE {$shadow} LIKE {$table}" ) || false === $wpdb->query( "INSERT INTO {$shadow} SELECT * FROM {$table}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						throw new RuntimeException( 'A File 01 shadow backup could not be created.' );
					}
					$snapshot['shadow_tables'][ $name ] = $shadow;
				}
			}
		} catch ( Throwable $error ) {
			foreach ( $snapshot['shadow_tables'] as $shadow ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$shadow}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated shadow.
			}
			throw $error;
		}
		update_option( self::SNAPSHOT_OPTION, $snapshot, false );
		$persisted_snapshot = get_option( self::SNAPSHOT_OPTION, null );
		if ( ! is_array( $persisted_snapshot ) || SPF_Runtime::hash( $persisted_snapshot ) !== SPF_Runtime::hash( $snapshot ) ) {
			foreach ( $snapshot['shadow_tables'] as $shadow ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$shadow}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated shadow.
			}
			delete_option( self::SNAPSHOT_OPTION );
			throw new RuntimeException( 'The activation snapshot could not be verified after persistence.' );
		}
		return $snapshot;
	}


	private static function restore_runtime_snapshot( array $snapshot ) {
		global $wpdb;
		if ( empty( $snapshot ) ) {
			return new WP_Error( 'spf_snapshot_unavailable', __( 'No File 01 runtime snapshot was available for compensation.', 'sabri-platform-foundation' ) );
		}
		$failures = array();
		foreach ( self::table_names() as $name ) {
			$table = self::table( $name );
			$existed = ! empty( $snapshot['preexisting_tables'][ $name ] );
			$shadow = $snapshot['shadow_tables'][ $name ] ?? '';
			if ( $existed ) {
				if ( ! $shadow || ! SPF_Runtime::table_exists( $shadow ) ) {
					$failures[] = $name . ':shadow_missing';
					continue;
				}
				$failed = $table . '_failed_' . substr( md5( $snapshot['snapshot_id'] . $name ), 0, 6 );
				$wpdb->query( "DROP TABLE IF EXISTS {$failed}" ); // phpcs:ignore
				$result = SPF_Runtime::table_exists( $table )
					? $wpdb->query( "RENAME TABLE {$table} TO {$failed}, {$shadow} TO {$table}" ) // phpcs:ignore
					: $wpdb->query( "RENAME TABLE {$shadow} TO {$table}" ); // phpcs:ignore
				if ( false === $result || ! SPF_Runtime::table_exists( $table ) || SPF_Runtime::table_exists( $shadow ) ) {
					$failures[] = $name . ':restore_failed';
					continue;
				}
				$wpdb->query( "DROP TABLE IF EXISTS {$failed}" ); // phpcs:ignore
			} elseif ( SPF_Runtime::table_exists( $table ) ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore
				if ( SPF_Runtime::table_exists( $table ) ) {
					$failures[] = $name . ':new_table_drop_failed';
				}
			}
		}
		foreach ( $snapshot['options'] ?? array() as $option => $state ) {
			$sentinel = new stdClass();
			if ( ! empty( $state['exists'] ) ) {
				update_option( $option, $state['value'], false );
				$restored = get_option( $option, $sentinel );
				if ( $sentinel === $restored || SPF_Runtime::hash( $restored ) !== SPF_Runtime::hash( $state['value'] ) ) {
					$failures[] = $option . ':option_restore_failed';
				}
			} else {
				delete_option( $option );
				if ( $sentinel !== get_option( $option, $sentinel ) ) {
					$failures[] = $option . ':option_delete_restore_failed';
				}
			}
		}
		$role = get_role( 'administrator' );
		if ( $role ) {
			foreach ( $snapshot['admin_caps'] ?? array() as $cap => $had ) {
				$had ? $role->add_cap( $cap ) : $role->remove_cap( $cap );
				if ( (bool) $role->has_cap( $cap ) !== (bool) $had ) {
					$failures[] = $cap . ':capability_restore_failed';
				}
			}
		}
		self::unschedule_jobs();
		foreach ( array( 'spf_dispatch_outbox','spf_privacy_retention','spf_reconcile_expired_flags','spf_future_foundation_tick' ) as $hook ) {
			if ( wp_next_scheduled( $hook ) ) {
				$failures[] = $hook . ':schedule_unschedule_failed';
			}
		}
		foreach ( $snapshot['schedules'] ?? array() as $hook => $timestamp ) {
			if ( $timestamp ) {
				$recurrence_map = array(
					'spf_dispatch_outbox'          => 'spf_five_minutes',
					'spf_privacy_retention'       => 'daily',
					'spf_reconcile_expired_flags' => 'hourly',
					'spf_future_foundation_tick'  => 'spf_five_minutes',
				);
				$recurrence = $recurrence_map[ $hook ] ?? '';
				if ( '' === $recurrence ) {
					$failures[] = $hook . ':schedule_recurrence_unknown';
					continue;
				}
				$result = wp_schedule_event( (int) $timestamp, $recurrence, $hook, array(), true );
				if ( is_wp_error( $result ) || false === $result || $recurrence !== wp_get_schedule( $hook ) ) {
					$failures[] = $hook . ':schedule_restore_failed';
				}
			}
		}
		return empty( $failures ) ? true : new WP_Error( 'spf_compensation_incomplete', __( 'File 01 compensation could not be fully verified.', 'sabri-platform-foundation' ), array( 'failures' => $failures ) );
	}

	private static function discard_shadow_backups( array $snapshot ) {
		global $wpdb;
		foreach ( $snapshot['shadow_tables'] ?? array() as $shadow ) {
			if ( $shadow ) {
				$wpdb->query( "DROP TABLE IF EXISTS {$shadow}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- generated shadow table.
			}
		}
		delete_option( self::SNAPSHOT_OPTION );
	}

	public static function owned_options() {
		return array(
			self::LOCK_OPTION, self::SNAPSHOT_OPTION, self::VERSION_OPTION, self::SCHEMA_OPTION, self::CONTRACT_OPTION,
			'spf_activation_state','spf_upgrade_state','spf_builtin_contracts_registered','spf_reconciliation_snapshot',
			'spf_reconciliation_state','spf_external_purge_receipt','spf_audit_chain_lock','spf_outbox_dispatch_lock',
			'spf_page_map','spf_founder_user_id',
		);
	}

	private static function environment() {
		return function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
	}
}
