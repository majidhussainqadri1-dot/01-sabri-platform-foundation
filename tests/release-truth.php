<?php
declare(strict_types=1);
const ABSPATH='/tmp/'; const SPF_VERSION='2.0.2';const SPF_SCHEMA_VERSION='1.2.0';const SPF_CONTRACT_VERSION='2.0.0';const SPF_PLAN_ID='SSH-F01';
$GLOBALS['opt']=['spf_version'=>'2.0.2','spf_schema_version'=>'1.2.0','spf_contract_version'=>'2.0.0'];
$GLOBALS['release']=['status'=>'deployed','software_version'=>'2.0.2','schema_version'=>'1.2.0','release_id'=>'test-release-UUID','checksum_sha256'=>str_repeat('a',64),'commit_sha'=>str_repeat('b',40)];
$GLOBALS['parity']=null;$GLOBALS['operation']=null;$GLOBALS['health']=null;
function get_option($key,$fallback=null){return $GLOBALS['opt'][$key]??$fallback;}
function apply_filters($name,$fallback,$ctx=null){return match($name){'spf_deployed_artifact_parity_evidence'=>$GLOBALS['parity'],'spf_operational_acceptance_status'=>$GLOBALS['operation'],default=>$fallback};}
class SPF_Governance{public static function list_releases($limit){return $GLOBALS['release']?[$GLOBALS['release']]:[];}}
class SPF_Installer{const SCHEMA_OPTION='spf_schema_version';const CONTRACT_OPTION='spf_contract_version';const VERSION_OPTION='spf_version';public static function canonical_module_catalog(){return[];}}
class SPF_Registry{public static function list_modules($f=[]){return[];}public static function list_contracts($f=[]){return[];}public static function list_routes(){return[];}}
class SPF_System_Check{public static function latest(){return $GLOBALS['health'];}}
class SPF_Future_Foundation{public static function status(){return[];}}
class SPF_Runtime{public static function hash($v){return hash('sha256',json_encode($v));}}
require dirname(__DIR__).'/includes/class-spf-plugin.php';
$n=0;$fail=[];function ch($v,$m){global $n,$fail;$n++;if(!$v)$fail[]=$m;}
function status(){return SPF_Plugin::instance()->status_dto()['completion_statuses'];}
function valid_parity(){return ['verified'=>true,'release_id'=>'test-release-UUID','package_checksum'=>str_repeat('a',64),'source_commit'=>str_repeat('b',40),'software_version'=>'2.0.2','schema_version'=>'1.2.0','environment'=>'production','verifier'=>'independent-file24-review','observed_at'=>gmdate('Y-m-d H:i:s',time()-50),'expires_at'=>gmdate('Y-m-d H:i:s',time()+300)];}
$GLOBALS['release']['software_version']='2.0.1';$s=status();foreach(['packaged','automated_qa_green','staging_accepted','live_deployed','operational'] as $k)ch($s[$k]===false,'stale release must deny '.$k);ch($s['release_record_says_deployed']===true,'historical status preserved separately');
$GLOBALS['release']['software_version']='2.0.2';$s=status();ch($s['live_deployed']===false && $s['staging_accepted']===true,'matching source must still require independent live parity');
$GLOBALS['parity']=valid_parity();$s=status();ch($s['live_deployed']===true,'fresh strongly bound independent parity accepted');
foreach(['release_id','package_checksum','source_commit','software_version','schema_version','environment','verifier','observed_at','expires_at'] as $k){$hold=$GLOBALS['parity'];$GLOBALS['parity'][$k]=match($k){'environment'=>'staging','verifier'=>'   ','observed_at'=>gmdate('Y-m-d H:i:s',time()-2000),'expires_at'=>gmdate('Y-m-d H:i:s',time()-1),default=>'wrong'};$s=status();ch($s['live_deployed']===false,'tamper must deny '.$k);$GLOBALS['parity']=$hold;}
$GLOBALS['parity']['verified']='true';ch(status()['live_deployed']===false,'string verified denied');$GLOBALS['parity']=valid_parity();
$GLOBALS['opt']['spf_version']='2.0.1';ch(status()['live_deployed']===false && status()['staging_accepted']===false,'stale installed option denied');$GLOBALS['opt']['spf_version']='2.0.2';
$GLOBALS['opt']['spf_schema_version']='1.1.0';ch(status()['live_deployed']===false && status()['staging_accepted']===false,'stale schema denied');$GLOBALS['opt']['spf_schema_version']='1.2.0';
$GLOBALS['release']['status']='approved';ch(status()['staging_accepted']===true && status()['live_deployed']===false,'approved not yet live');
$GLOBALS['release']['status']='built';ch(status()['packaged']===true && status()['automated_qa_green']===false,'built not QA green');
$GLOBALS['release']['status']='verified';ch(status()['automated_qa_green']===true && status()['staging_accepted']===false,'QA green not staging');
$GLOBALS['release']['status']='rolled_back';ch(status()['live_deployed']===false && status()['staging_accepted']===false,'rollback no deployment');
$GLOBALS['release']['status']='deployed';$GLOBALS['parity']=null;ch(status()['live_deployed']===false,'missing parity after rollback denied');
if($fail){fwrite(STDERR,"FAIL ".count($fail)."/{$n}: ".implode('; ',$fail)."\n");exit(1);}echo "release truth: {$n}/{$n} PASS\n";
