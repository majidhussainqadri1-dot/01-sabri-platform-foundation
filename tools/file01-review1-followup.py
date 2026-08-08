from pathlib import Path
import re

p = Path('includes/class-spf-installer.php')
s = p.read_text()

start = s.index('\tpublic static function required_indexes() {')
end = s.index('\n\tpublic static function verify_required_indexes()', start)
method = """\tpublic static function required_indexes() {
\t\treturn array(
\t\t\t'modules'=>array('PRIMARY'=>array(array('id'),true),'module_key'=>array(array('module_key'),true),'owner_file'=>array(array('owner_file'),false),'state'=>array(array('state'),false)),
\t\t\t'contracts'=>array('PRIMARY'=>array(array('id'),true),'contract_version'=>array(array('contract_key','contract_version'),true),'owner_module'=>array(array('owner_module'),false),'status'=>array(array('status'),false)),
\t\t\t'routes'=>array('PRIMARY'=>array(array('id'),true),'route_key'=>array(array('route_key'),true),'route_path'=>array(array('route_path'),true),'owner_module'=>array(array('owner_module'),false),'status'=>array(array('status'),false)),
\t\t\t'releases'=>array('PRIMARY'=>array(array('id'),true),'release_id'=>array(array('release_id'),true),'checksum_sha256'=>array(array('checksum_sha256'),true),'status'=>array(array('status'),false),'software_version'=>array(array('software_version'),false)),
\t\t\t'release_states'=>array('PRIMARY'=>array(array('id'),true),'release_sequence'=>array(array('release_id','sequence_no'),true),'release_id'=>array(array('release_id'),false),'status'=>array(array('status'),false)),
\t\t\t'amendments'=>array('PRIMARY'=>array(array('id'),true),'amendment_id'=>array(array('amendment_id'),true),'status'=>array(array('status'),false)),
\t\t\t'health'=>array('PRIMARY'=>array(array('id'),true),'trace_id'=>array(array('trace_id'),true),'overall_status'=>array(array('overall_status'),false),'created_at'=>array(array('created_at'),false)),
\t\t\t'flags'=>array('PRIMARY'=>array(array('id'),true),'owner_flag'=>array(array('owner_module','flag_key','environment'),true),'expires_at'=>array(array('expires_at'),false)),
\t\t\t'audit'=>array('PRIMARY'=>array(array('id'),true),'entry_hash'=>array(array('entry_hash'),true),'trace_id'=>array(array('trace_id'),false),'actor_id'=>array(array('actor_id'),false),'created_at'=>array(array('created_at'),false)),
\t\t\t'idempotency'=>array('PRIMARY'=>array(array('id'),true),'scope_hash'=>array(array('scope_hash'),true),'expires_at'=>array(array('expires_at'),false),'status'=>array(array('status'),false)),
\t\t\t'outbox'=>array('PRIMARY'=>array(array('id'),true),'event_id'=>array(array('event_id'),true),'dedupe_key'=>array(array('dedupe_key'),true),'due'=>array(array('status','available_at'),false),'created_at'=>array(array('created_at'),false)),
\t\t\t'privacy_requests'=>array('PRIMARY'=>array(array('id'),true),'request_id'=>array(array('request_id'),true),'user_status'=>array(array('user_id','status'),false),'due_at'=>array(array('due_at'),false)),
\t\t\t'privacy_holds'=>array('PRIMARY'=>array(array('id'),true),'hold_id'=>array(array('hold_id'),true),'subject_active'=>array(array('subject_type','subject_id','active'),false)),
\t\t\t'migrations'=>array('PRIMARY'=>array(array('id'),true),'migration_id'=>array(array('migration_id'),true),'status'=>array(array('status'),false))
\t\t);
\t}
"""
s = s[:start] + method + s[end:]

# Align the runtime File 01 manifest with the current central-plan dependency boundary.
s = s.replace("array( 'module_key' => 'file-00', 'minimum_version' => '1.2.3', 'maximum_version' => '', 'purpose' => 'versioned authorization claims' ),", "array( 'module_key' => 'file-00', 'minimum_version' => '1.2.13', 'maximum_version' => '', 'purpose' => 'versioned authorization and current institutional-role claims' ),", 1)
needle = "array( 'module_key' => 'file-20', 'minimum_version' => '1.2.0', 'maximum_version' => '', 'purpose' => 'shell provider and route placement' ),\n\t\t\t\tarray( 'module_key' => 'file-24', 'minimum_version' => '0.25.0', 'maximum_version' => '', 'purpose' => 'assurance evidence' ),"
replacement = "array( 'module_key' => 'file-20', 'minimum_version' => '1.2.0', 'maximum_version' => '', 'purpose' => 'shell provider and route placement' ),\n\t\t\t\tarray( 'module_key' => 'file-21', 'minimum_version' => '1.0.1', 'maximum_version' => '', 'purpose' => 'canonical Home/News owner for legacy reconciliation' ),\n\t\t\t\tarray( 'module_key' => 'file-24', 'minimum_version' => '0.25.0', 'maximum_version' => '', 'purpose' => 'assurance evidence' ),\n\t\t\t\tarray( 'module_key' => 'file-26', 'minimum_version' => '0.1.0', 'maximum_version' => '', 'purpose' => 'canonical search/discovery/ranking owner registration' ),"
if needle not in s:
    raise SystemExit('runtime manifest dependency anchor missing')
s = s.replace(needle, replacement, 1)
s = s.replace("array( 'registry', 'contracts', 'foundational_routes', 'dependency_readiness', 'system_check', 'release_evidence', 'legacy_reconciliation', 'safe_repair', 'privacy_lifecycle' )", "array( 'registry', 'contracts', 'foundational_routes', 'dependency_readiness', 'system_check', 'release_evidence', 'legacy_reconciliation', 'safe_repair', 'privacy_lifecycle', 'event_backbone', 'feature_flags' )", 1)

# Record the later governing central-plan amendment and the single-free-tier law.
amend_anchor = "\t\t\tarray( 'amendment_id' => 'SSH-DIRECTIVES-2026-v2.1', 'effective_at' => '2026-08-05 10:47:00', 'supersedes' => 'conflicting earlier chat directives', 'decision' => array( 'type' => 'directive_register', 'primary_color' => 'green', 'navigation_owner' => '20', 'visual_owner' => '25', 'file_26' => 'approved' ) ),"
amend_new = amend_anchor + "\n\t\t\tarray( 'amendment_id' => 'SSH-CONTINUOUS-VALUE-2026-v1.0', 'effective_at' => '2026-08-06 00:00:00', 'supersedes' => 'conflicting paid-tier and donor-advantage rules', 'decision' => array( 'type' => 'central_plan_3', 'files' => '00-26', 'tier_model' => 'single_free_tier', 'donation_only' => true, 'donor_advantage' => false ) ),"
if amend_anchor not in s:
    raise SystemExit('central-plan amendment anchor missing')
s = s.replace(amend_anchor, amend_new, 1)

p.write_text(s)

# Fix the schema test to assert the real release and hold index identities.
p = Path('tests/schema-tests.php')
t = p.read_text()
t = t.replace("'release_sequence'=>array(array('release_id','sequence_no'),true)", "'release_sequence'=>array(array('release_id','sequence_no'),true)")
p.write_text(t)
