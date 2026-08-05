<?php
defined( 'ABSPATH' ) || exit;

final class SPF_Installer {
	const LOCK_OPTION = 'spf_activation_lock';
	const SNAPSHOT_OPTION = 'spf_activation_snapshot';
	const VERSION_OPTION = 'spf_version';
	const SCHEMA_OPTION = 'spf_schema_version';
	const CONTRACT_OPTION = 'spf_contract_version';

	public static function table( $name ) {
		global $wpdb;
		$allowed = array( 'modules', 'contracts', 'routes', 'releases', 'release_states', 'amendments', 'health', 'flags', 'audit', 'idempotency', 'outbox' );
		if ( ! in_array( $name, $allowed, true ) ) {
			wp_die( 'Invalid File 01 table.' );
		}
		return $wpdb->prefix . 'spf_' . $name;
	}

	public static function activate() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		$lock = self::acquire_lock();
		if ( is_wp_error( $lock ) ) {
			wp_die( esc_html( $lock->get_error_message() ) );
		}
		$trace = SPF_Audit::trace_id();
		try {
			self::capture_activation_snapshot();
			self::install_schema();
			SPF_Authorization::install_capabilities();
			self::seed_governance();
			update_option( self::VERSION_OPTION, SPF_VERSION, false );
			update_option( self::SCHEMA_OPTION, SPF_SCHEMA_VERSION, false );
			update_option( self::CONTRACT_OPTION, SPF_CONTRACT_VERSION, false );
			update_option( 'spf_activation_state', array( 'status' => 'active', 'trace_id' => $trace, 'activated_at' => current_time( 'mysql', true ) ), false );
			$audit = SPF_Audit::record_required( 'activation', 'foundation', 'file-01', 'success', array( 'purpose' => 'plugin_activation', 'version' => SPF_VERSION ), $trace );
			if ( is_wp_error( $audit ) ) {
				throw new RuntimeException( $audit->get_error_message() );
			}
			$event = SPF_Event_Bus::publish( 'FoundationModuleActivated.v1', 'foundation_module', 'file-01', array( 'version' => SPF_VERSION, 'schema_version' => SPF_SCHEMA_VERSION ), 1, 'file-01-activation-' . SPF_VERSION );
			if ( is_wp_error( $event ) ) {
				throw new RuntimeException( $event->get_error_message() );
			}
			if ( ! wp_next_scheduled( 'spf_dispatch_outbox' ) ) {
				wp_schedule_event( time() + 120, 'spf_five_minutes', 'spf_dispatch_outbox' );
			}
			flush_rewrite_rules( false );
		} catch ( Throwable $error ) {
			self::restore_activation_snapshot();
			update_option( 'spf_activation_state', array( 'status' => 'failed', 'trace_id' => $trace, 'failed_at' => current_time( 'mysql', true ) ), false );
			self::release_lock( $lock );
			wp_die( esc_html( 'File 01 activation failed safely: ' . $error->getMessage() ) );
		}
		self::release_lock( $lock );
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( 'spf_dispatch_outbox' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'spf_dispatch_outbox' );
		}
		update_option( 'spf_activation_state', array( 'status' => 'inactive', 'deactivated_at' => current_time( 'mysql', true ) ), false );
		flush_rewrite_rules( false );
	}

	public static function install_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

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
		) {$charset};";
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
		) {$charset};";
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
		) {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'releases' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			release_id char(36) NOT NULL,
			software_version varchar(32) NOT NULL,
			commit_sha varchar(64) NOT NULL,
			package_name varchar(191) NOT NULL,
			checksum_sha256 char(64) NOT NULL,
			schema_version varchar(32) NOT NULL,
			evidence_json longtext NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'built',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY release_id (release_id),
			UNIQUE KEY checksum_sha256 (checksum_sha256),
			KEY status (status)
		) {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'release_states' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			release_id char(36) NOT NULL,
			sequence_no int(10) unsigned NOT NULL,
			status varchar(32) NOT NULL,
			evidence_json longtext NOT NULL,
			actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY release_sequence (release_id,sequence_no),
			KEY release_id (release_id),
			KEY status (status)
		) {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'amendments' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			amendment_id varchar(64) NOT NULL,
			effective_at datetime NOT NULL,
			supersedes varchar(191) NOT NULL DEFAULT '',
			decision_json longtext NOT NULL,
			approver_ref varchar(191) NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'approved',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY amendment_id (amendment_id),
			KEY status (status)
		) {$charset};";
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
		) {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'flags' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			flag_key varchar(128) NOT NULL,
			owner_module varchar(64) NOT NULL,
			environment varchar(32) NOT NULL DEFAULT 'all',
			enabled tinyint(1) NOT NULL DEFAULT 0,
			expires_at datetime NULL,
			reason varchar(500) NOT NULL,
			record_version bigint(20) unsigned NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY owner_flag (owner_module,flag_key,environment),
			KEY enabled (enabled)
		) {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'audit' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			trace_id char(36) NOT NULL,
			action_name varchar(64) NOT NULL,
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
			KEY trace_id (trace_id),
			UNIQUE KEY entry_hash (entry_hash),
			KEY object_ref (object_type,object_id),
			KEY actor_id (actor_id),
			KEY created_at (created_at)
		) {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'idempotency' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			idempotency_key varchar(191) NOT NULL,
			scope_hash char(64) NOT NULL,
			actor_id bigint(20) unsigned NOT NULL,
			action_name varchar(64) NOT NULL,
			request_hash char(64) NOT NULL,
			response_json longtext NOT NULL,
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY scope_hash (scope_hash),
			KEY expires_at (expires_at)
		) {$charset};";
		$sql[] = "CREATE TABLE " . self::table( 'outbox' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_id char(36) NOT NULL,
			event_name varchar(128) NOT NULL,
			event_version int(10) unsigned NOT NULL,
			aggregate_type varchar(64) NOT NULL,
			aggregate_id varchar(191) NOT NULL,
			dedupe_key varchar(191) NOT NULL,
			payload_json longtext NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'pending',
			attempts int(10) unsigned NOT NULL DEFAULT 0,
			available_at datetime NOT NULL,
			sent_at datetime NULL,
			last_error varchar(191) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_id (event_id),
			UNIQUE KEY dedupe_key (dedupe_key),
			KEY due (status,available_at)
		) {$charset};";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}

	private static function seed_governance() {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$amendments = array(
			array(
				'amendment_id' => 'SSH-PMP-2026-v3.0',
				'effective_at' => '2026-07-31 00:00:00',
				'supersedes'   => 'Foundation 0.25; Comprehensive Master Plan 2.0',
				'decision'     => array( 'type' => 'governing_constitution', 'numbering' => '00-25', 'runtime' => '01-B' ),
			),
			array(
				'amendment_id' => 'SSH-DIRECTIVES-2026-v2.1',
				'effective_at' => '2026-08-05 10:47:00',
				'supersedes'   => 'conflicting earlier chat directives',
				'decision'     => array( 'type' => 'directive_register', 'primary_color' => 'green', 'navigation_owner' => '20', 'visual_owner' => '25', 'file_26' => 'approved' ),
			),
		);
		foreach ( $amendments as $item ) {
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO " . self::table( 'amendments' ) . " (amendment_id,effective_at,supersedes,decision_json,approver_ref,status,created_at) VALUES (%s,%s,%s,%s,%s,'approved',%s)",
					$item['amendment_id'],
					$item['effective_at'],
					$item['supersedes'],
					wp_json_encode( $item['decision'] ),
					'Founder-approved governing source',
					$now
				)
			);
		}
		foreach ( self::seed_manifests() as $manifest ) {
			$existing = SPF_Registry::get_module( $manifest['module_key'] );
			if ( $existing && 'file-01' !== $manifest['module_key'] ) {
				continue;
			}
			$result = SPF_Registry::register_manifest( $manifest, array( 'system_seed' => true, 'purpose' => 'activation_seed' ) );
			if ( is_wp_error( $result ) ) {
				throw new RuntimeException( $result->get_error_message() );
			}
		}
		$route_result = SPF_Registry::map_route(
			array(
				'route_key'      => 'file01-system-check',
				'route_path'     => '/platform-system-check/',
				'owner_module'   => 'file-01',
				'layout_context' => 'minimal',
				'status'         => 'active',
				'destination'    => admin_url( 'admin.php?page=sabri-foundation' ),
			),
			array( 'system_seed' => true, 'purpose' => 'activation_seed' )
		);
		if ( is_wp_error( $route_result ) ) {
			throw new RuntimeException( $route_result->get_error_message() );
		}
		$route_result = SPF_Registry::map_route(
			array(
				'route_key'      => 'file01-foundation-status',
				'route_path'     => '/platform-foundation/status/',
				'owner_module'   => 'file-01',
				'layout_context' => 'minimal',
				'status'         => 'active',
				'destination'    => admin_url( 'admin.php?page=sabri-foundation' ),
			),
			array( 'system_seed' => true, 'purpose' => 'activation_seed' )
		);
		if ( is_wp_error( $route_result ) ) {
			throw new RuntimeException( $route_result->get_error_message() );
		}
	}

	public static function seed_manifests() {
		$names = array(
			'00' => array( 'membership-core', 'Sabri Membership Core' ),
			'01' => array( 'platform-foundation', 'Platform Foundation and Master Governance' ),
			'02' => array( 'authentication', 'Authentication and Accounts' ),
			'03' => array( 'profiles-doctors', 'Profiles and Doctors' ),
			'04' => array( 'legacy-news-publishing', 'Legacy News Feed and Publishing Compatibility' ),
			'05' => array( 'learn-homeopathy', 'Learn Sabri Classical Homeopathy' ),
			'06' => array( 'homeopathy-encyclopedia', 'Homeopathy Encyclopedia' ),
			'07' => array( 'doctors-directory', 'Doctors Directory and Discovery' ),
			'08' => array( 'worldwide-clinic', 'Worldwide Clinic and Appointments' ),
			'09' => array( 'doctor-verification', 'Global Doctor Onboarding and Verification' ),
			'10' => array( 'video-wall', 'Video Wall and Educational Broadcasting' ),
			'11' => array( 'reels', 'Reels and Short Video Discovery' ),
			'12' => array( 'pdf-library', 'PDF Library and Digital Reading' ),
			'13' => array( 'welcome-intro', 'Welcome Intro Animation' ),
			'14' => array( 'clinic-usp', 'Global Clinic USP and Conversion Sections' ),
			'15' => array( 'radar', 'Radar, Symptom and Remedy Research' ),
			'16' => array( 'ai-study-guide', 'Sabri Classical Homeopathy AI' ),
			'17' => array( 'communication-network', 'Communication Network' ),
			'18' => array( 'marketplace', 'Marketplace' ),
			'19' => array( 'notifications', 'Unified Notifications' ),
			'20' => array( 'application-shell', 'Unified Application Shell' ),
			'21' => array( 'home-news-feed', 'Complete Home and News Feed' ),
			'22' => array( 'universal-composer', 'Universal Post Composer' ),
			'23' => array( 'publishing-dashboard', 'Doctor and Founder Publishing Dashboard' ),
			'24' => array( 'security-center', 'Security, Privacy, Compliance and Resilience Center' ),
			'25' => array( 'public-ui', 'Complete Public UI, Profile Timeline and Visual Experience' ),
			'26' => array( 'search-discovery', 'Search, Discovery, Recommendations, Knowledge Graph and Classification' ),
		);
		$result = array();
		foreach ( $names as $file => $data ) {
			$result[] = array(
				'module_key'       => 'file-' . $file,
				'owner_file'       => $file,
				'owner_name'       => $data[1],
				'slug'             => $data[0],
				'namespace_prefix' => 'file' . $file,
				'software_version' => '0.0.0',
				'contract_version' => '1.0.0',
				'state'            => '01' === $file ? 'active' : 'unregistered',
				'required'         => array(),
				'optional'         => array(),
				'capabilities'     => array(),
				'source'           => 'governing-plan-seed',
			);
		}
		return $result;
	}

	private static function capture_activation_snapshot() {
		global $wpdb;
		$preexisting_tables = array();
		foreach ( array( 'modules', 'contracts', 'routes', 'releases', 'release_states', 'amendments', 'health', 'flags', 'audit', 'idempotency', 'outbox' ) as $name ) {
			$table = self::table( $name );
			$preexisting_tables[ $name ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}
		$snapshot = array(
			'captured_at'          => current_time( 'mysql', true ),
			'preexisting_tables'   => $preexisting_tables,
			'spf_page_map'         => get_option( 'spf_page_map', null ),
			'spf_founder_user_id'  => get_option( 'spf_founder_user_id', null ),
			'spf_version'          => get_option( self::VERSION_OPTION, null ),
			'spf_schema_version'   => get_option( self::SCHEMA_OPTION, null ),
			'spf_contract_version' => get_option( self::CONTRACT_OPTION, null ),
		);
		update_option( self::SNAPSHOT_OPTION, $snapshot, false );
	}

	public static function restore_activation_snapshot() {
		$snapshot = get_option( self::SNAPSHOT_OPTION, array() );
		if ( ! is_array( $snapshot ) ) {
			return false;
		}
		foreach ( array( 'spf_page_map', 'spf_founder_user_id', 'spf_version', 'spf_schema_version', 'spf_contract_version' ) as $key ) {
			if ( array_key_exists( $key, $snapshot ) ) {
				null === $snapshot[ $key ] ? delete_option( $key ) : update_option( $key, $snapshot[ $key ], false );
			}
		}
		if ( ! empty( $snapshot['preexisting_tables'] ) && is_array( $snapshot['preexisting_tables'] ) ) {
			global $wpdb;
			foreach ( $snapshot['preexisting_tables'] as $name => $existed ) {
				if ( ! $existed ) {
					$table = self::table( $name );
					$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- allowlisted File 01 table.
				}
			}
		}
		return true;
	}

	private static function acquire_lock() {
		$token = wp_generate_uuid4();
		$payload = array( 'token' => $token, 'created' => time(), 'owner' => get_current_user_id() );
		if ( add_option( self::LOCK_OPTION, $payload, '', 'no' ) ) {
			return $token;
		}
		$existing = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $existing ) && isset( $existing['created'] ) && ( time() - (int) $existing['created'] ) > 900 ) {
			delete_option( self::LOCK_OPTION );
			if ( add_option( self::LOCK_OPTION, $payload, '', 'no' ) ) {
				return $token;
			}
		}
		return new WP_Error( 'spf_activation_locked', __( 'File 01 activation is already running. Try again after the lock expires.', 'sabri-platform-foundation' ) );
	}

	private static function release_lock( $token ) {
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && isset( $current['token'] ) && hash_equals( (string) $current['token'], (string) $token ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}
}
