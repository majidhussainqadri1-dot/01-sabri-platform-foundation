<?php
declare(strict_types=1);
// Independent isolated harness. Does not assert actual companion installation.
define('ABSPATH', '/tmp');
define('SPF_VERSION', '2.0.1');
define('SPF_CONTRACT_VERSION', '2.0.0');
define('SMC_VERSION', '1.2.44'); // Presence of this symbol MUST NOT authorize File 00.
function __(string $s, $domain = ''): string { return $s; }
function sanitize_key($s): string { return strtolower((string) preg_replace('/[^A-Za-z0-9_-]/','',(string)$s)); }
function sanitize_text_field($s): string { return trim((string)$s); }
function is_wp_error($v): bool { return $v instanceof WP_Error; }
function has_filter($hook) { return !empty($GLOBALS['xf_filters'][$hook]); }
function has_action($hook) { return !empty($GLOBALS['xf_actions'][$hook]); }
class WP_Error {private string $code; public function __construct($c) {$this->code=$c;} public function get_error_code(){return $this->code;}}
class SPF_Runtime {public static function now_mysql() {return '2026-11-22 00:00:00';}}
class SPF_Registry {
 public static function get_module($key) {return $GLOBALS['xf_modules'][$key] ?? null;}
 public static function list_modules($args=[]) {return array_values($GLOBALS['xf_modules']);}
 public static function valid_semver($v) {return is_string($v)&&boolval(preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[A-Za-z0-9.-]+)?$/D',$v));}
 public static function compare_semver($left,$right) {return version_compare($left,$right);}
}
$root=dirname(__DIR__).'/includes';
require_once $root.'/class-spf-dependency-resolver.php';
require_once $root.'/class-spf-installer.php';
require_once $root.'/class-spf-system-check.php';
require_once $root.'/class-spf-event-bus.php';
$checks=0;$fail=[];
function expect_true($truth,$msg) {global $checks,$fail;$checks++;if(!$truth)$fail[]=$msg;}
function foundation() {return SPF_Installer::file01_manifest()+['manifest_valid'=>true];}
function module($key,$state='active',$version='2.0.0',$valid=true) {return ['module_key'=>$key,'state'=>$state,'software_version'=>$version,'contract_version'=>'1.2.3','manifest_valid'=>$valid,'required'=>[],'optional'=>[]];}
function getchecks() {$m=new ReflectionMethod('SPF_System_Check','integration_checks');return array_column($m->invoke(null),null,'code');}
function init_modules() {$GLOBALS['xf_modules']=['file-01'=>foundation()];$GLOBALS['xf_modules']['file-01']['manifest_valid']=true;$GLOBALS['xf_filters']=[];$GLOBALS['xf_actions']=[];}
init_modules();
$initial=getchecks();
expect_true(isset($initial['file00_contract']),'File00 check missing');
expect_true($initial['file00_contract']['status']==='warning','Absent File00 incorrectly pass');
expect_true($initial['owner_manifests']['status']==='warning','Missing companion manifests incorrectly pass');
expect_true(count($initial)===6,'Unexpected baseline health check count');
$ref=new ReflectionMethod('SPF_Dependency_Resolver','optional_status');
$state=[];
foreach (['missing','degraded','registered','compatible','active','suspended','retired','unregistered'] as $v){
 init_modules();$GLOBALS['xf_modules']['file-00']=module('file-00',$v==='missing'?'active':$v,'1.2.44');
 if($v==='missing')unset($GLOBALS['xf_modules']['file-00']);
 $GLOBALS['xf_filters']['spf_file00_authorization_claim']=true;
 $res=getchecks()['file00_contract'];
 $optional=SPF_Dependency_Resolver::readiness('file-01')['optional_dependencies'];
 $dep=current(array_filter($optional,static fn($x)=>$x['module_key']==='file-00'));
 $expected=in_array($v,['registered','compatible','active'],true);
 expect_true(($res['status']==='pass')===$expected,'Health mismatch for File00 state='.$v);
 expect_true(($dep['available']??false)===$expected,'Dependency resolver mismatch for File00 state='.$v);
}
init_modules();$GLOBALS['xf_modules']['file-00']=module('file-00','active','1.2.44');
expect_true(getchecks()['file00_contract']['status']==='warning','SMC_VERSION must not imply authorized provider');
$GLOBALS['xf_filters']['spf_file00_authorization_claim']=true;
expect_true(getchecks()['file00_contract']['status']==='pass','Ready manifest+adapter should pass structural check');
$GLOBALS['xf_modules']['file-00']['manifest_valid']=false;
expect_true(getchecks()['file00_contract']['status']==='warning','Invalid manifest must warn');
$GLOBALS['xf_modules']['file-00']['manifest_valid']=true;
$GLOBALS['xf_modules']['file-00']['software_version']='1.2.12';
expect_true(getchecks()['file00_contract']['status']==='warning','Below-minimum File00 must warn');
$GLOBALS['xf_modules']['file-00']['software_version']='nonsense';
expect_true(getchecks()['file00_contract']['status']==='warning','Non-semver File00 must warn');
foreach(['file-20'=>['file20','sabri_shell_register_provider'],'file-21'=>['file21','spf_owner_reconciliation_plan'],'file-24'=>['file24','spf_verify_backup_restore_evidence']] as $module=>[$code,$hook]) {
 init_modules();$GLOBALS['xf_modules'][$module]=module($module,'active','2.0.0');
 if($module==='file-20')$GLOBALS['xf_actions'][$hook]=true;else $GLOBALS['xf_filters'][$hook]=true;
 expect_true(getchecks()[$code.'_contract']['status']==='pass','Ready '.$module.' should pass structural health');
 $GLOBALS['xf_modules'][$module]['state']='degraded';
 expect_true(getchecks()[$code.'_contract']['status']==='warning','Degraded '.$module.' must warn');
 $GLOBALS['xf_modules'][$module]['state']='active';$GLOBALS['xf_modules'][$module]['manifest_valid']=false;
 expect_true(getchecks()[$code.'_contract']['status']==='warning','Invalid '.$module.' must warn');
}
init_modules();$GLOBALS['xf_modules']['file-25']=module('file-25','active','0.15.0');$GLOBALS['xf_actions']['sabri_public_ui_register_component']=true;
expect_true(getchecks()['file25_contract']['status']==='pass','Ready visual provider structural check');
expect_true(!in_array('file-25',array_column(SPF_Installer::file01_manifest()['optional'],'module_key'),true),'Visual-only observation must not mutate declared optional dependencies');
expect_true(in_array('file-26',array_column(SPF_Installer::file01_manifest()['optional'],'module_key'),true),'File26 must remain explicitly optional');
$catalog=SPF_Installer::file01_event_catalog();
expect_true(count($catalog)===20,'File01 owner event catalog must enumerate exactly 20 names');
expect_true(count($catalog)===count(array_unique($catalog)),'Owner event catalog contains duplicates');
expect_true($catalog===SPF_Installer::file01_manifest()['events'],'Declared event list diverges from source event catalog');
foreach($catalog as $event)expect_true((bool)preg_match('/^[A-Za-z][A-Za-z0-9]+\.v1$/',$event),'Invalid canonical event name '.$event);
$sem=SPF_Event_Bus::delivery_semantics();
expect_true($sem['consumer_receipt_verified']===false,'WP action completion incorrectly claimed consumer acknowledgement');
expect_true($sem['provider_delivery_claim_allowed']===false,'Outbox incorrectly claimed provider delivery');
expect_true($sem['consumer_commit_proof_required']===true,'Consumer receipt boundary not enforced in contract');
// Behavioral/static audit: require every owner-published event name to be declared,
// including all conditional event-name branches. Only File01 include sources.
$observed=[];
foreach(glob($root.'/class-spf-*.php') as $path){
 $text=file_get_contents($path);
 preg_match_all('/SPF_Event_Bus::publish\(\s*[\'\"]([^\'\"]+)[\'\"]/', $text,$m);
 foreach($m[1] as $x)$observed[$x]=true;
 preg_match_all('/\$event_name\s*=\s*[^;]+;/', $text,$dynamic);
 foreach($dynamic[0] as $expr){preg_match_all('/[\'\"]([A-Za-z][A-Za-z0-9]+\.v[0-9]+)[\'\"]/', $expr,$names);foreach($names[1] as $x)$observed[$x]=true;}
}
$unexpected=array_values(array_diff(array_keys($observed),$catalog));
$unused=array_values(array_diff($catalog,array_keys($observed)));
expect_true($unexpected===[],'Undeclared publisher events: '.implode(',',$unexpected));
expect_true($unused===[],'Catalog entries unbound to owner publications: '.implode(',',$unused));
if($fail){fwrite(STDERR,"Cross-file regressions failed (".count($fail)."/{$checks}):\n".implode("\n",$fail)."\n");exit(1);}echo "Cross-file health/event contract assertions: {$checks}/{$checks} PASS\n";
