<?php
declare(strict_types=1);
$root=dirname(__DIR__);$all='';
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS));
foreach($it as$f){if($f->isFile()&&in_array($f->getExtension(),['php','md','txt','yml','json'],true)&&!str_contains($f->getPathname(),DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR)&&!str_contains($f->getPathname(),DIRECTORY_SEPARATOR.'build'.DIRECTORY_SEPARATOR)){$all.=file_get_contents($f->getPathname())."\n";}}
$trace=file_get_contents($root.'/TRACEABILITY.md');$assertions=0;$failures=[];$assert=static function(bool$c,string$m)use(&$assertions,&$failures){$assertions++;if(!$c)$failures[]=$m;};
foreach(array_merge(array_map(fn($n)=>sprintf('F01-FR-%03d',$n),range(1,12)),array_map(fn($n)=>sprintf('F01-NFR-%03d',$n),range(1,10)))as$id){$assert(str_contains($trace,$id),"Traceability missing {$id}");}
foreach(['class SPF_Runtime','class SPF_Authorization','class SPF_Registry','class SPF_Dependency_Resolver','class SPF_Idempotency','class SPF_Governance','class SPF_Privacy','class SPF_System_Check','class SPF_Reconciler','class SPF_Repair','class SPF_Purge','class SPF_Event_Bus','class SPF_Audit']as$symbol){$assert(str_contains($all,$symbol),"Missing implementation {$symbol}");}
foreach(['FoundationModuleActivated.v1','FoundationModuleDeactivated.v1','FoundationContractDeprecated.v1','FoundationHealthChanged.v1','FoundationReleaseStateChanged.v1','ReleaseApproved.v1','FoundationPrivacyErasureCompleted.v1','FeatureFlagChanged.v1','FeatureFlagExpired.v1']as$event){$assert(str_contains($all,$event),"Missing event {$event}");}
foreach(['spf_file00_authorization_claim','spf_verify_backup_restore_evidence','spf_verify_file24_purge_assurance','spf_owner_reconciliation_plan','spf_execute_owner_reconciliation','spf_rollback_owner_reconciliation']as$hook){$assert(str_contains($all,$hook),"Missing cross-file hook {$hook}");}
foreach(['planned','built','verified','staged','approved','deployed','rolled_back','superseded']as$state){$assert(str_contains($all,"'{$state}'"),"Missing release state {$state}");}
if($failures){fwrite(STDERR,"Contract tests failed:\n- ".implode("\n- ",$failures)."\n");exit(1);}echo "Contract assertions: {$assertions}/{$assertions} PASS\n";
