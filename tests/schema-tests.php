<?php
declare(strict_types=1);
$source=file_get_contents(dirname(__DIR__).'/includes/class-spf-installer.php');
$runtime=file_get_contents(dirname(__DIR__).'/includes/class-spf-runtime.php');
$assertions=0;$failures=[];$assert=static function(bool$c,string$m)use(&$assertions,&$failures){$assertions++;if(!$c)$failures[]=$m;};
$tables=['modules','contracts','routes','releases','release_states','amendments','health','flags','audit','idempotency','outbox','privacy_requests','privacy_holds','migrations'];
foreach($tables as$table){$assert(str_contains($source,"self::table( '{$table}' )"),"Missing schema table {$table}");}
foreach(['ENGINE=InnoDB','UNIQUE KEY module_key','UNIQUE KEY contract_version','UNIQUE KEY route_key','UNIQUE KEY route_path','UNIQUE KEY release_id','UNIQUE KEY checksum_sha256','UNIQUE KEY release_sequence','UNIQUE KEY scope_hash','UNIQUE KEY event_id','UNIQUE KEY dedupe_key','previous_hash char(64)','entry_hash char(64)','evidence_hash char(64)','record_version bigint','owner_token char(36)','locked_at datetime','privacy_class varchar(32)','privacy_requests','privacy_holds','migrations','verify_required_indexes','required_indexes'] as$needle){$assert(str_contains($source,$needle),"Missing schema/constraint {$needle}");}
$assert(str_contains($runtime,'verify_owned_tables_transactional'),'Transactional-engine verification missing.');
$assert(str_contains($source,'capture_runtime_snapshot'),'Activation/upgrade snapshot missing.');
$assert(str_contains($source,'restore_runtime_snapshot'),'Activation/upgrade compensation missing.');
$assert(str_contains($source,"'outbox' => array( 'event_id','event_version','payload_json','privacy_class'"),'Outbox privacy classification is not part of schema verification.');
$assert(str_contains($source,"'due'=>array(array('status','available_at'),false)"),'Outbox due index integrity is not required.');
$assert(str_contains($source,"'release_sequence'=>array(array('release_id','sequence_no'),true)"),'Release-state unique sequence integrity is not required.');
if($failures){fwrite(STDERR,"Schema tests failed:\n- ".implode("\n- ",$failures)."\n");exit(1);}echo "Schema assertions: {$assertions}/{$assertions} PASS\n";
