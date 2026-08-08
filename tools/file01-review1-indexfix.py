from pathlib import Path

p=Path('includes/class-spf-installer.php')
s=p.read_text()
start=s.index('\tpublic static function required_indexes() {')
end=s.index('\n\tpublic static function verify_required_indexes()', start)
method="""\tpublic static function required_indexes() {
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
s=s[:start]+method+s[end:]
p.write_text(s)
