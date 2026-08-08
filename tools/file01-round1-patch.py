from pathlib import Path


def once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly one anchor, found {count}")
    return text.replace(old, new, 1)


# 1) Schema: durable privacy classification + exact required-index integrity.
p = Path("includes/class-spf-installer.php")
s = p.read_text()
s = once(
    s,
    "\t\t\tpayload_json longtext NOT NULL,\n\t\t\tstatus varchar(32) NOT NULL DEFAULT 'pending',",
    "\t\t\tpayload_json longtext NOT NULL,\n\t\t\tprivacy_class varchar(32) NOT NULL DEFAULT 'internal',\n\t\t\tstatus varchar(32) NOT NULL DEFAULT 'pending',",
    "outbox privacy column",
)
s = once(
    s,
    "\t\t\t'outbox'=>array('event_id','event_name','event_version','aggregate_type','aggregate_id','dedupe_key','payload_json','status','attempts','available_at','created_at'),",
    "\t\t\t'outbox'=>array('event_id','event_name','event_version','aggregate_type','aggregate_id','dedupe_key','payload_json','privacy_class','status','attempts','available_at','created_at'),",
    "outbox required privacy column",
)
marker = "\t\t$transactional = SPF_Runtime::verify_owned_tables_transactional();\n\t\tif ( is_wp_error( $transactional ) ) {\n\t\t\treturn $transactional;\n\t\t}\n\t\treturn true;\n\t}\n\n\tpublic static function seed_foundation() {"
replacement = """\t\t$indexes = self::verify_required_indexes();
\t\tif ( is_wp_error( $indexes ) ) {
\t\t\treturn $indexes;
\t\t}
\t\t$transactional = SPF_Runtime::verify_owned_tables_transactional();
\t\tif ( is_wp_error( $transactional ) ) {
\t\t\treturn $transactional;
\t\t}
\t\treturn true;
\t}

\tpublic static function required_indexes() {
\t\treturn array(
\t\t\t'modules'=>array('PRIMARY'=>array(array('id'),true),'module_key'=>array(array('module_key'),true),'owner_file'=>array(array('owner_file'),false),'state'=>array(array('state'),false)),
\t\t\t'contracts'=>array('PRIMARY'=>array(array('id'),true),'contract_identity'=>array(array('contract_key','contract_version'),true),'owner_module'=>array(array('owner_module'),false),'status'=>array(array('status'),false)),
\t\t\t'contract_acks'=>array('PRIMARY'=>array(array('contract_key','contract_version','consumer_module'),true)),
\t\t\t'routes'=>array('PRIMARY'=>array(array('id'),true),'route_key'=>array(array('route_key'),true),'route_path'=>array(array('route_path'),true),'owner_module'=>array(array('owner_module'),false),'status'=>array(array('status'),false)),
\t\t\t'releases'=>array('PRIMARY'=>array(array('id'),true),'release_id'=>array(array('release_id'),true),'software_version_unique'=>array(array('software_version'),true),'status'=>array(array('status'),false)),
\t\t\t'release_states'=>array('PRIMARY'=>array(array('id'),true),'release_sequence'=>array(array('release_id','sequence_no'),true),'release_id'=>array(array('release_id'),false),'status'=>array(array('status'),false)),
\t\t\t'amendments'=>array('PRIMARY'=>array(array('id'),true),'amendment_id'=>array(array('amendment_id'),true),'status'=>array(array('status'),false)),
\t\t\t'health'=>array('PRIMARY'=>array(array('id'),true),'trace_id'=>array(array('trace_id'),true),'overall_status'=>array(array('overall_status'),false),'created_at'=>array(array('created_at'),false)),
\t\t\t'flags'=>array('PRIMARY'=>array(array('id'),true),'flag_identity'=>array(array('owner_module','flag_key','environment'),true),'expires_at'=>array(array('expires_at'),false)),
\t\t\t'audit'=>array('PRIMARY'=>array(array('id'),true),'entry_hash'=>array(array('entry_hash'),true),'trace_id'=>array(array('trace_id'),false),'actor_id'=>array(array('actor_id'),false),'created_at'=>array(array('created_at'),false)),
\t\t\t'idempotency'=>array('PRIMARY'=>array(array('id'),true),'scope_hash'=>array(array('scope_hash'),true),'expires_at'=>array(array('expires_at'),false),'status'=>array(array('status'),false)),
\t\t\t'outbox'=>array('PRIMARY'=>array(array('id'),true),'event_id'=>array(array('event_id'),true),'dedupe_key'=>array(array('dedupe_key'),true),'due'=>array(array('status','available_at'),false),'created_at'=>array(array('created_at'),false)),
\t\t\t'privacy_requests'=>array('PRIMARY'=>array(array('id'),true),'request_id'=>array(array('request_id'),true),'user_status'=>array(array('user_id','status'),false),'due_at'=>array(array('due_at'),false)),
\t\t\t'privacy_holds'=>array('PRIMARY'=>array(array('id'),true),'hold_id'=>array(array('hold_id'),true),'subject_active'=>array(array('subject_id','active'),false)),
\t\t\t'migrations'=>array('PRIMARY'=>array(array('id'),true),'migration_key'=>array(array('migration_key'),true),'status'=>array(array('status'),false))
\t\t);
\t}

\tpublic static function verify_required_indexes() {
\t\tglobal $wpdb;
\t\tforeach ( self::required_indexes() as $name => $required ) {
\t\t\t$table = self::table( $name );
\t\t\tif ( ! SPF_Runtime::table_exists( $table ) ) {
\t\t\t\treturn new WP_Error( 'spf_schema_missing_table', 'Missing File 01 table: ' . $name );
\t\t\t}
\t\t\t$rows = $wpdb->get_results( 'SHOW INDEX FROM ' . $table, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- allowlisted File 01 table.
\t\t\t$actual = array();
\t\t\tforeach ( (array) $rows as $row ) {
\t\t\t\t$key = (string) $row['Key_name'];
\t\t\t\t$seq = (int) $row['Seq_in_index'];
\t\t\t\t$actual[$key]['columns'][$seq] = (string) $row['Column_name'];
\t\t\t\t$actual[$key]['unique'] = 0 === (int) $row['Non_unique'];
\t\t\t}
\t\t\tforeach ( $actual as &$index ) {
\t\t\t\tksort( $index['columns'] );
\t\t\t\t$index['columns'] = array_values( $index['columns'] );
\t\t\t}
\t\t\tunset( $index );
\t\t\tforeach ( $required as $key => $spec ) {
\t\t\t\tlist( $columns, $unique ) = $spec;
\t\t\t\tif ( empty( $actual[$key] ) || $actual[$key]['columns'] !== $columns || (bool)$actual[$key]['unique'] !== (bool)$unique ) {
\t\t\t\t\treturn new WP_Error( 'spf_schema_index_invalid', 'Missing or invalid File 01 index: ' . $name . '.' . $key, array( 'table'=>$name, 'index'=>$key, 'expected_columns'=>$columns, 'expected_unique'=>(bool)$unique ) );
\t\t\t\t}
\t\t\t}
\t\t}
\t\treturn true;
\t}

\tpublic static function seed_foundation() {"""
s = once(s, marker, replacement, "required index verifier")
p.write_text(s)


# 2) Shared event backbone: explicit durable privacy classification.
p = Path("includes/class-spf-event-bus.php")
s = p.read_text()
s = once(
    s,
    "\tpublic static function publish( $event_name, $aggregate_type, $aggregate_id, array $payload, $version = 1, $dedupe_key = '' ) {",
    "\tpublic static function publish( $event_name, $aggregate_type, $aggregate_id, array $payload, $version = 1, $dedupe_key = '', $privacy_class = 'internal' ) {",
    "event publish signature",
)
s = once(
    s,
    "\t\tif ( ! $event_name || ! $aggregate_type || ! $aggregate_id || $version < 1 ) {\n\t\t\treturn new WP_Error( 'spf_invalid_event', __( 'Invalid event contract.', 'sabri-platform-foundation' ) );\n\t\t}\n\t\t$payload = self::sanitize_payload( $payload );",
    "\t\tif ( ! $event_name || ! $aggregate_type || ! $aggregate_id || $version < 1 ) {\n\t\t\treturn new WP_Error( 'spf_invalid_event', __( 'Invalid event contract.', 'sabri-platform-foundation' ) );\n\t\t}\n\t\t$privacy_class = sanitize_key( $privacy_class );\n\t\tif ( ! in_array( $privacy_class, array( 'public','internal','restricted','confidential','ephemeral' ), true ) ) {\n\t\t\treturn new WP_Error( 'spf_invalid_event_privacy_class', __( 'A valid event privacy classification is required.', 'sabri-platform-foundation' ) );\n\t\t}\n\t\t$payload = self::sanitize_payload( $payload );",
    "event privacy validation",
)
s = once(
    s,
    "\t\t\t\t'aggregate_id'=>$aggregate_id,'dedupe_key'=>$dedupe_key,'payload_json'=>$payload_json,\n\t\t\t\t'status'=>'pending','attempts'=>0,'available_at'=>$now,'created_at'=>$now,\n\t\t\t),\n\t\t\tarray( '%s','%s','%d','%s','%s','%s','%s','%s','%d','%s','%s' )",
    "\t\t\t\t'aggregate_id'=>$aggregate_id,'dedupe_key'=>$dedupe_key,'payload_json'=>$payload_json,'privacy_class'=>$privacy_class,\n\t\t\t\t'status'=>'pending','attempts'=>0,'available_at'=>$now,'created_at'=>$now,\n\t\t\t),\n\t\t\tarray( '%s','%s','%d','%s','%s','%s','%s','%s','%s','%d','%s','%s' )",
    "event privacy persistence",
)
p.write_text(s)


# 3) Feature activation: fail closed without dependency + migration/health/rollback/gate evidence.
p = Path("includes/class-spf-governance.php")
s = p.read_text()
anchor = "\t\tif ( $old && (int) $context['expected_version'] !== (int) $old['record_version'] ) {\n\t\t\treturn new WP_Error( 'spf_stale_record', __( 'The feature flag changed before this update.', 'sabri-platform-foundation' ), array( 'status' => 409 ) );\n\t\t}\n"
gate = anchor + """\t\t$activation_evidence_hash = '';
\t\tif ( ! empty( $flag['enabled'] ) ) {
\t\t\t$readiness = SPF_Dependency_Resolver::readiness( $owner );
\t\t\tif ( empty( $readiness['ready'] ) ) {
\t\t\t\treturn new WP_Error( 'spf_feature_dependency_not_ready', __( 'Feature activation is blocked because the owner dependency graph is not ready.', 'sabri-platform-foundation' ), array( 'status'=>412, 'readiness_code'=>$readiness['code'] ?? 'not_ready' ) );
\t\t\t}
\t\t\t$activation = SPF_Runtime::verify_evidence(
\t\t\t\t'spf_verify_feature_activation_evidence',
\t\t\t\tarray( 'owner_module'=>$owner, 'flag_key'=>$key, 'environment'=>$env, 'readiness_hash'=>SPF_Runtime::hash($readiness) ),
\t\t\t\tarray( 'migration_status','health_status','rollback_evidence','gate_evidence','verifier','expires_at' )
\t\t\t);
\t\t\tif ( is_wp_error( $activation ) ) {
\t\t\t\treturn $activation;
\t\t\t}
\t\t\tif ( ! in_array( sanitize_key( $activation['migration_status'] ), array( 'ready','not_required' ), true ) || 'pass' !== sanitize_key( $activation['health_status'] ) ) {
\t\t\t\treturn new WP_Error( 'spf_feature_activation_evidence_failed', __( 'Feature activation evidence does not prove migration and health readiness.', 'sabri-platform-foundation' ), array( 'status'=>412 ) );
\t\t\t}
\t\t\t$activation_evidence_hash = $activation['evidence_hash'];
\t\t}
"""
s = once(s, anchor, gate, "feature activation gate")
s = once(
    s,
    "array( 'purpose'=>$context['purpose']??'feature_flag' ) );\n\t\tif ( is_wp_error( $pre ) )",
    "array( 'purpose'=>$context['purpose']??'feature_flag','activation_evidence_hash'=>$activation_evidence_hash ) );\n\t\tif ( is_wp_error( $pre ) )",
    "feature activation precommit evidence hash",
)
s = once(
    s,
    "array( 'purpose'=>$context['purpose']??'feature_flag','enabled'=>empty($flag['enabled'])?0:1 ) );",
    "array( 'purpose'=>$context['purpose']??'feature_flag','enabled'=>empty($flag['enabled'])?0:1,'activation_evidence_hash'=>$activation_evidence_hash ) );",
    "feature activation success evidence hash",
)
p.write_text(s)


# 4) Runtime assertions for exact index integrity and fail-closed feature activation.
p = Path("qa/wp-runtime-smoke.php")
s = p.read_text()
s = once(
    s,
    "$assert( ! is_wp_error( $schema ), 'Schema verification failed: ' . ( is_wp_error( $schema ) ? $schema->get_error_message() : '' ) );",
    "$assert( ! is_wp_error( $schema ), 'Schema verification failed: ' . ( is_wp_error( $schema ) ? $schema->get_error_message() : '' ) );\n$assert( true === SPF_Installer::verify_required_indexes(), 'Required File 01 index integrity failed.' );",
    "runtime index assertion",
)
s = once(
    s,
    "$flag = SPF_Governance::set_flag( [ 'owner_module'=>'file-01','flag_key'=>'runtime_probe','environment'=>'all','enabled'=>true,'reason'=>'runtime test' ], [ 'purpose'=>'feature_flag' ] );",
    "$ungated_flag = SPF_Governance::set_flag( [ 'owner_module'=>'file-01','flag_key'=>'runtime_probe','environment'=>'all','enabled'=>true,'reason'=>'runtime test' ], [ 'purpose'=>'feature_flag' ] );\n$assert( is_wp_error( $ungated_flag ) && 'spf_evidence_unverified' === $ungated_flag->get_error_code(), 'Feature activation did not fail closed without readiness evidence.' );\nadd_filter( 'spf_verify_feature_activation_evidence', static fn() => [ 'verified'=>true,'migration_status'=>'ready','health_status'=>'pass','rollback_evidence'=>'ci-rollback-ready','gate_evidence'=>'ci-gate-proof','verifier'=>'CI runtime','expires_at'=>gmdate('c',time()+3600) ] );\n$flag = SPF_Governance::set_flag( [ 'owner_module'=>'file-01','flag_key'=>'runtime_probe','environment'=>'all','enabled'=>true,'reason'=>'runtime test' ], [ 'purpose'=>'feature_flag' ] );",
    "runtime feature activation assertion",
)
p.write_text(s)
