<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$file=$root.'/docs/FILE01-CROSS-FILE-INTEGRATION-MATRIX-2026-11-22.json';
$matrix=json_decode((string)file_get_contents($file),true);
if(!is_array($matrix)){fwrite(STDERR,"Invalid cross-file matrix JSON\n");exit(1);}
$n=0;$failure=[];$assert=function($good,$what)use(&$n,&$failure){$n++;if(!$good)$failure[]=$what;};
$assert(($matrix['schema']??'')==='file01-crossfile-audit-v1','Matrix version incorrect');
$assert(($matrix['relationship_count']??null)===32 && count($matrix['modules']??[])===32,'32 companions not enumerated');
$assert(($matrix['native_runtime_joint_pass_count']??null)===0,'Unperformed multi-plugin runtime test claimed verified');
$seen=[];$optional=[];$cf=0;
foreach($matrix['modules'] as $row){
 $key=$row['file_id']??'';
 $assert(!isset($seen[$key]),'Duplicate module '.$key);
 $seen[$key]=true;
 $assert(str_starts_with($row['repository']??'','majidhussainqadri1-dot/'),'Canonical repository pointer missing '.$key);
 $assert(!empty($row['native_owner'])&&!empty($row['file01_relationship']),'Owner boundary missing '.$key);
 $assert(($row['no_foreign_canonical_writes']??null)===true,'File01 foreign table mutation claim '.$key);
 foreach(['exact_owner_api_verified','staging_installed_jointly_verified','production_deployed_verified']as$e){
   $assert(($row[$e]??null)===false,'Unverified acceptance claim '.$key.' '.$e);
 }
 if($row['direct_optional_dependency'])$optional[]='file-'.$key;
 if(str_starts_with($key,'CF-')){$cf++;$assert($row['activation']==='disabled_or_unverified','Unsafe CF activation '.$key);}
}
for($i=0;$i<=26;$i++){
 if($i===1)continue;
 $assert(isset($seen[sprintf('%02d',$i)]),'Missing numbered module '.sprintf('%02d',$i));
}
for($i=1;$i<=6;$i++)$assert(isset($seen[sprintf('CF-%02d',$i)]),'Missing conditional module '.sprintf('CF-%02d',$i));
$assert($cf===6,'CF scope wrong');
sort($optional);$declared=$matrix['file01']['direct_optional_dependencies'];sort($declared);
$assert($optional===$declared,'Dependency set inconsistent with owner registry');
$assert($declared===['file-00','file-20','file-21','file-24','file-26'],'Five optional dependencies changed or invented');
$assert(($matrix['file01']['additional_health_observer_not_dependency']??[])===['file-25'],'Visual health observer not separated');
$assert(($matrix['file01']['own_event_count']??0)===20,'Event catalog count wrong');
$assert(str_contains($matrix['file01']['event_dispatch_semantics'],'no consumer'),'Outbox delivery proof overstated');
$assert(count($matrix['acceptance_gates']??[])>=12,'Independent staged/live gates omitted');
$assert(($matrix['known_external_contract_pins'][0]['file01_sha']??'')==='adf6dbb9980a85f25f7cf4c2ee679b52703c2e71','Historical File04 exact pin overwritten');
if($failure){fwrite(STDERR,"Cross-file matrix test failed ".count($failure)."/{$n}:\n".implode("\n",$failure)."\n");exit(1);}
echo "Cross-file 32-module matrix assertions: {$n}/{$n} PASS\n";
