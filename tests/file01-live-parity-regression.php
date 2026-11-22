<?php
// Isolated source-regression probes; NOT WordPress/MySQL staging acceptance.
declare(strict_types=1);
define('ABSPATH', '/isolated/');
define('SPF_SCHEMA_VERSION', '1.2.0');
define('SPF_CONTRACT_VERSION', '2.0.0');
class WP_Error { public function __construct(private string $code,private string $message='',private array $data=[]){} public function get_error_code(){return $this->code;}public function get_error_message(){return $this->message;}}
class WP_REST_Request {public function get_attribute($k){return null;}}
function __($s,$d=''){return $s;}function sanitize_text_field($s){return trim(strip_tags((string)$s));}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_-]/','',(string)$s));}
function wp_json_encode($v,$flags=0){return json_encode($v,$flags|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function is_wp_error($o){return $o instanceof WP_Error;}
function has_filter($n){return $GLOBALS['filter_present'][$n]??false;}
function apply_filters($n,$value,...$args){$fn=$GLOBALS['test_filters'][$n]??null;return $fn?$fn($value,...$args):$value;}
function current_user_can($c){return $GLOBALS['test_caps'][$c]??false;}
function is_user_logged_in(){return $GLOBALS['test_logged_in']??true;}
function get_current_user_id(){return $GLOBALS['test_uid']??42;}
function get_option($k,$default=false){return $GLOBALS['test_options'][$k]??$default;}
function update_option($k,$value,$autoload=false){$GLOBALS['test_options'][$k]=$value;return true;}
function current_time($type,$gmt=false){return gmdate('Y-m-d H:i:s');}
function wp_get_environment_type(){return 'production';}
$root=dirname(__DIR__);
$source=is_dir($root.'/source/includes/') ? $root.'/source/includes/' : $root.'/includes/';
foreach(['runtime','registry','authorization','dependency-resolver','installer','governance','governance-control-plane','platform-engineering','resilience-lab','future-foundation'] as $n){require_once $source.'class-spf-'.$n.'.php';}
$pass=0;$fails=[];
function check($description,$actual,$expected){global $pass,$fails;if($actual===$expected){$pass++;}else{$fails[]=['case'=>$description,'actual'=>$actual,'expected'=>$expected];}}
$valid=['0.0.0','1.2.3','1.2.3+build.9','1.2.3-alpha.10','1.2.3-0','999999999999999999999.1.1','1.0.0-alpha.beta','1.0.0-rc.1+abc','1.0.0+metadata-with-XYZ'];
$invalid=['','1','1.2','v1.2.3','01.2.3','1.02.3','1.2.03','1.0.0-alpha.01','1.0.0-alpha..beta','1.0.0+bad..build','1.0.0-','1.0.0+','1.0.0-α','1.0.0 trailing','1.0.0-00'];
foreach($valid as $i=>$v){check("SemVer valid #$i",SPF_Registry::valid_semver($v),true);}foreach($invalid as $i=>$v){check("SemVer invalid #$i",SPF_Registry::valid_semver($v),false);}
foreach([['1.0.0+abc','1.0.0+def',0],['1.0.0+abc','1.0.0',0],['1.2.3-alpha.10','1.2.3-alpha.2',1],['1.2.3-alpha.2','1.2.3-alpha.10',-1],['1.2.3','1.2.3-rc.1',1],['1.2.3-rc.1','1.2.3',-1],['1.2.3-alpha','1.2.3-alpha.1',-1],['1.2.3+build.7','1.2.3+build.8',0],['2.0.0','10.0.0',-1],['1.0.0-beta','1.0.0-3',1],['1.0.0-alpha.01','1.0.0-alpha.1',null]] as $i=>$p){check("SemVer precedence #$i",SPF_Registry::compare_semver($p[0],$p[1]),$p[2]);}
$ref=new ReflectionMethod('SPF_Dependency_Resolver','range_status');
foreach([['1.2.3+meta.1','1.2.3+meta.2','',true],['1.2.2','1.2.3','', 'dependency_below_minimum'],['1.2.4','1.2.3','1.2.3','dependency_above_maximum'],['1.2.3-alpha.01','1.2.3','', 'dependency_version_invalid'],['1.2.3','1.2.3','1.2.3',true]] as $i=>$a){$v=$ref->invoke(null,['software_version'=>$a[0]],['module_key'=>'file-00','minimum_version'=>$a[1],'maximum_version'=>$a[2]]);check("Dependency range #$i",is_array($v)?$v['code']:$v,$a[3]);}
$GLOBALS['test_caps']=['view_sabri_foundation'=>true,'manage_sabri_foundation'=>true];
foreach([['run_system_check',['object_id'=>'system-check'],true],['run_system_check',['object_id'=>'system-check','module_key'=>'file-01'],false],['run_system_check',['object_id'=>'file-01'],false],['run_system_check',[],false],['approve_release',['module_key'=>'file-01'],false],['run_reconciliation',['module_key'=>'file-01'],false],['run_schema_upgrade',['module_key'=>'file-01'],false],['view',['object_id'=>'file-01-admin'],true]] as $i=>$t){check("Authorization bootstrap #$i",SPF_Authorization::can($t[0],$t[1]),$t[2]);}
$GLOBALS['test_caps']['manage_sabri_foundation']=false;check('Viewer cannot run system check',SPF_Authorization::can('run_system_check',['object_id'=>'system-check']),false);
$GLOBALS['test_caps']['manage_sabri_foundation']=true;$GLOBALS['test_logged_in']=false;check('Anonymous cannot run system check',SPF_Authorization::can('run_system_check',['object_id'=>'system-check']),false);$GLOBALS['test_logged_in']=true;
$GLOBALS['filter_present']['spf_file00_authorization_claim']=true;check('File 00 marker without claim fails closed',SPF_Authorization::can('run_system_check',['object_id'=>'system-check']),false);unset($GLOBALS['filter_present']['spf_file00_authorization_claim']);
$GLOBALS['test_options']['spf_schema_version']='1.3.0';$res=SPF_Installer::maybe_upgrade();check('Schema downgrade blocked code',is_wp_error($res)?$res->get_error_code():'NOT_ERROR','spf_schema_downgrade_blocked');check('Schema downgrade blocked state',$GLOBALS['test_options']['spf_upgrade_state']['status'],'downgrade_blocked');
$GLOBALS['test_options']['spf_schema_version']='garbage';$res=SPF_Installer::maybe_upgrade();check('Invalid schema version blocked code',is_wp_error($res)?$res->get_error_code():'NOT_ERROR','spf_schema_version_invalid');
foreach(['staged','deployed'] as $state){$required=$state==='staged'?['staging_environment','fresh_install','upgrade_test','cross_file_contracts','backup_restore_test','rollback_rehearsal','browser_accessibility_rtl','founder_acceptance_pending']:['production_change_id','deployed_package_checksum','smoke_test','monitoring_window','rollback_ready'];$e=array_fill_keys($required,true);$res=SPF_Governance::validate_evidence_for_state($state,$e);check("$state missing parity/freeze blocked",is_wp_error($res)?$res->get_error_code():'NOT_ERROR','spf_release_evidence_incomplete');$e[$state==='staged'?'staging_reality_freeze':'deployment_parity']=true;check("$state has parity/freeze",SPF_Governance::validate_evidence_for_state($state,$e),true);}
$codes=SPF_Resilience_Lab::chaos_scenarios();$scenario=array_key_first($codes);$challenge=str_repeat('a',32);$receipt=['scenario'=>$scenario,'challenge'=>$challenge,'adapter'=>'independent-test','fault_observed'=>true,'recovery_observed'=>true,'fault_evidence_sha256'=>str_repeat('1',64),'recovery_evidence_sha256'=>str_repeat('2',64)];
check('Chaos receipt accepted structurally',SPF_Resilience_Lab::verify_chaos_receipt($receipt,$scenario,$challenge),true);foreach(['scenario','challenge','adapter','fault_observed','recovery_observed','fault_evidence_sha256','recovery_evidence_sha256'] as $key){$t=$receipt;unset($t[$key]);check('Chaos receipt missing '.$key,SPF_Resilience_Lab::verify_chaos_receipt($t,$scenario,$challenge),false);}$t=$receipt;$t['recovery_evidence_sha256']=$t['fault_evidence_sha256'];check('Chaos no identical evidence',SPF_Resilience_Lab::verify_chaos_receipt($t,$scenario,$challenge),false);
$catalog=SPF_Future_Foundation::feature_catalog();check('Future features counted',count($catalog),18);check('Feature completion not falsely certified',in_array('coded',array_column($catalog,'status'),true),false);
$report=SPF_Governance_Control_Plane::build_traceability_report(['F01-FR-001'],['F01-FR-001'=>['design'=>true,'code'=>true,'test'=>true]]);check('Traceability without independent evidence is not coded',$report['coded_complete'],0);check('Traceability incomplete plan scope',$report['v12_requirement_coverage_complete'],false);check('Completion claim denied',$report['coded_completion_claim_allowed'],false);
$report=SPF_Governance_Control_Plane::build_traceability_report(['F01-FR-001','F01-FR-001'],[]);check('Duplicate requirement report invalid',$report['report_valid'],false);
$types=SPF_Platform_Engineering::contract_compatibility(['contract_version'=>'1.2.3+build.2','schema'=>[]],['contract_version'=>'1.2.3+build.1','schema'=>[]]);check('Contract build metadata equal precedence',$types['version_monotonic'],true);
$results=['isolated'=>true,'php'=>PHP_VERSION,'pass'=>$pass,'fail'=>count($fails),'cases'=>$pass+count($fails),'failures'=>$fails];if (is_dir($root.'/evidence')) { file_put_contents($root.'/evidence/offline-regression.json',json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); }echo json_encode($results,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;exit($fails?1:0);
