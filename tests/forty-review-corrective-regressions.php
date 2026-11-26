<?php
/**
 * File 01 post-review corrective negative regressions against this exact source tree.
 * Synthetic WordPress/DB stubs only; no production data or network use.
 */
declare(strict_types=1);
const ABSPATH='/tmp/'; const ARRAY_A='ARRAY_A';
const SPF_VERSION='2.0.2'; const SPF_SCHEMA_VERSION='1.2.0'; const SPF_CONTRACT_VERSION='2.0.0'; const SPF_PLAN_ID='SSH-F01-PLAN-2026-v1.0';
function __($s,$d=''){return $s;}
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$x));}
function sanitize_text_field($x){return trim(strip_tags((string)$x));}
function absint($x){return abs((int)$x);}
function is_wp_error($x){return $x instanceof WP_Error;}
function wp_json_encode($x,$flags=0){return json_encode($x,$flags);}
function wp_generate_uuid4(){return '2c837296-3f64-4eb1-9c59-5c0ab1f01110';}
function wp_is_uuid($x){return (bool)preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i',(string)$x);}
function get_current_user_id(){return 1;}
function get_option($key,$fallback=null){return $GLOBALS['options'][$key]??$fallback;}
function update_option($key,$val,$autoload=false){$GLOBALS['options'][$key]=$val;return true;}
function do_action($hook,...$args){}
function apply_filters($hook,$default,...$args){return $default;}
class WP_Error{public function __construct(private string $code,private string $message='',private array $data=[]){}public function get_error_code(){return $this->code;}public function get_error_message(){return $this->message;}public function get_error_data(){return $this->data;}}
class SPF_Runtime{public static function canonicalize($x){if(is_array($x)){foreach($x as &$v)$v=self::canonicalize($v);unset($v);if(!array_is_list($x))ksort($x);}return $x;}public static function hash($v){return hash('sha256',json_encode(self::canonicalize($v)));}public static function now_mysql(){return '2026-11-23 02:00:00';}public static function acquire_lock($n,$ttl=300,$o=0){return 'local-token';}public static function release_lock($n,$t){return true;}public static function table_exists($n){return true;}}
class SPF_Installer{const SCHEMA_OPTION='spf_schema_version';const CONTRACT_OPTION='spf_contract_version';const VERSION_OPTION='spf_version';public static function table($s){return 'wp_spf_'.$s;}public static function file01_manifest(){return ['events'=>['SyntheticEvent.v1']];}public static function canonical_module_catalog(){return [];}}
class SPF_Future_Foundation{public static function status(){return [];}}
class StubDB{
  public string $last_error='';public array $inserts=[],$updates=[],$seenDedupe=[];public ?array $savedEvent=null;public bool $silentNull=false,$silentCounts=false,$duplicateOnCollision=false;public string $prefix='wp_';
  public function prepare($sql,...$args){return $sql;}
  public function suppress_errors($state){return false;}
  public function get_row($sql,$format=null){return str_contains($sql,'dedupe_key')&&isset($this->savedEvent)?$this->savedEvent:null;}
  public function get_var($sql){return $this->silentCounts&&str_contains($sql,'COUNT(')?null:null;}
  public function get_results($sql,$format=null){return $this->silentNull?null:[];}
  public function insert($table,$data,$formats=[]){$this->inserts[]=['table'=>$table,'data'=>$data];if($this->duplicateOnCollision&&str_ends_with($table,'_outbox')){ $key=$data['dedupe_key'];if(isset($this->seenDedupe[$key])){$this->last_error='Duplicate entry';return false;}$this->seenDedupe[$key]=true;$this->savedEvent=$data;}return 1;}
  public function update($table,$data,$where,$fmt=[],$wherefmt=[]){$this->updates[]=['table'=>$table,'data'=>$data];return 1;}
  public function query($sql){return 0;}
}
$wpdb=new StubDB;$GLOBALS['options']=['spf_version'=>'2.0.2','spf_schema_version'=>'1.2.0','spf_contract_version'=>'2.0.0'];
$root=dirname(__DIR__).'/includes/';
foreach(['class-spf-event-bus.php','class-spf-governance.php','class-spf-idempotency.php','class-spf-registry.php','class-spf-system-check.php','class-spf-platform-engineering.php','class-spf-governance-control-plane.php','class-spf-audit.php'] as $f)require_once $root.$f;
$tests=[];
function probe($id,$test,$detail){global $tests;$tests[]=['id'=>$id,'blocked'=>!(bool)$test,'detail'=>$detail];echo json_encode(end($tests),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";}
function priv($class,$name,...$args){$m=new ReflectionMethod($class,$name);return $m->invoke(null,...$args);}
// Four different canonical identifier spellings: genuine private methods from ZIP.
foreach(['emailaddress','phonenumber','ipaddress'] as $field){
 $value='synthetic.person@example.test';
 foreach(['SPF_Event_Bus'=>'sanitize_payload','SPF_Governance'=>'sanitize_evidence','SPF_Idempotency'=>'safe_error_data'] as $cl=>$fn){
   $out=priv($cl,$fn,[$field=>$value]);probe('PII-'.$cl.'-'.$field,($out[$field]??null)===$value,'canonical sensitive key preserved');
 }
}
$verified=['ci_run'=>'run-1','test_summary'=>'all','zero_unresolved_critical_high'=>true,'security_review'=>'FAILED'];
probe('EVIDENCE-verified-failed-review',true===SPF_Governance::validate_evidence_for_state('verified',$verified),'FAILED security_review accepted by typed-state validator');
$staged=['staging_environment'=>'ci','staging_reality_freeze'=>'done','fresh_install'=>true,'upgrade_test'=>true,'cross_file_contracts'=>'FAILED','backup_restore_test'=>true,'rollback_rehearsal'=>true,'browser_accessibility_rtl'=>'NOT TESTED','founder_acceptance_pending'=>'yes'];
probe('EVIDENCE-staged-negative-strings',true===SPF_Governance::validate_evidence_for_state('staged',$staged),'negative readiness strings accepted by typed-state validator');
$wpdb->inserts=[];$r=SPF_Event_Bus::publish('SyntheticEvent.v1','foundation_event','synthetic.person@example.test',['quota'=>1]);$last=end($wpdb->inserts);
probe('EVENT-aggregate-id-PII',true===$r&&($last['data']['aggregate_id']??'')==='synthetic.person@example.test','synthetic identifier persisted in event metadata');
$wpdb->updates=[];$error=new WP_Error('synthetic_error','Unsafe synthetic.person@example.test contact',$data=['status'=>400]);$done=priv('SPF_Idempotency','finalize_error','scope','owner',$error);$last=end($wpdb->updates);
probe('IDEMPOTENCY-message-PII',true===$done&&str_contains(($last['data']['response_json']??''),'synthetic.person@example.test'),'synthetic email persisted in idempotency response_json');
$wpdb->silentNull=true;foreach(['list_modules','list_routes'] as $f){try{$r=SPF_Registry::$f();probe('REGISTRY-'.$f.'-null',false,'returned '.get_debug_type($r));}catch(TypeError $t){probe('REGISTRY-'.$f.'-null',true,'TypeError on missing WPDB rows');}}
try{$r=SPF_Registry::list_contracts();probe('REGISTRY-list_contracts-null',is_array($r)&&count($r)===0,'silent null converted to empty contracts');}catch(Throwable $e){probe('REGISTRY-list_contracts-null',false,get_class($e));}
try{$r=SPF_Governance::list_releases();probe('RELEASE-list_releases-null',null===$r,'null from WPDB is not typed failure');}catch(Throwable $e){probe('RELEASE-list_releases-null',false,get_class($e));}
$warns=[];set_error_handler(function($level,$msg)use(&$warns){$warns[]=$msg;return true;});$r=SPF_Governance::reconcile_expired_flags();restore_error_handler();
probe('FLAGS-reconcile-silent-null',is_array($r)&&($r['expired']??null)===0&&count($warns)>0,'invalid WPDB rows treated as zero expired plus PHP warning');
$wpdb->silentNull=false;$wpdb->silentCounts=true;$c=priv('SPF_System_Check','queue_checks');$codes=array_column($c,'status','code');
probe('HEALTH-count-silent-null',($codes['outbox_dead_letters']??null)==='pass'&&($codes['outbox_backlog']??null)==='pass','null count silently yields pass');
$wpdb->silentCounts=false;$wpdb->inserts=[];SPF_Audit::record_required('synthetic_test','foundation_event','synthetic.person@example.test','success',['purpose'=>'synthetic']);$last=end($wpdb->inserts);
probe('AUDIT-object-id-PII',($last['data']['object_id']??null)==='synthetic.person@example.test','synthetic identifier persisted in immutable audit object_id');
$wpdb->inserts=[];$ok=SPF_Audit::record_required('synthetic_test','foundation_contract','spf.registry@2.0.0','success',['purpose'=>'synthetic']);$last=end($wpdb->inserts);probe('AUDIT-contract-version-positive',is_wp_error($ok)||($last['data']['object_id']??null)!=='spf.registry@2.0.0','canonical internal contract key@SemVer must remain admissible for activation');
$wpdb->inserts=[];$wpdb->updates=[];$r=SPF_Platform_Engineering::record_metric('availability',0.9,['bucket'=>'synthetic.person@example.test']);$metrics=get_option(SPF_Platform_Engineering::METRIC_OPTION);
probe('TELEMETRY-value-PII',true===$r&&($metrics[0]['labels']['bucket']??null)==='synthetic.person@example.test','synthetic identifier persisted as neutral-key metric label');
$ai=priv('SPF_Governance_Control_Plane','sanitize_advisory_input',['email'=>'synthetic.person@example.test']);
probe('ADVISORY-hash-sensitive',isset($ai['email']['value_hash'])&&strlen($ai['email']['value_hash'])===64,'unsalted stable content SHA256 supplied to optional advisory adapter; privacy risk');
$wpdb->inserts=[];$wpdb->seenDedupe=[];$wpdb->savedEvent=null;$wpdb->duplicateOnCollision=true;
$r1=SPF_Event_Bus::publish('SyntheticEvent.v1','foundation_event','opaque-1',['version'=>1],1,'same-operation');
$r2=SPF_Event_Bus::publish('SyntheticEvent.v1','foundation_event','opaque-1',['version'=>2],1,'same-operation');
probe('DEDUPE-same-key-different-payload',true===$r1&&true===$r2&&count($wpdb->seenDedupe)===1,'changed payload considered duplicate success; contractual semantics unresolved');
$wpdb->seenDedupe=[];$wpdb->savedEvent=null;$first=SPF_Event_Bus::publish('SyntheticEvent.v1','foundation_event','opaque-1',['stable'=>1],1,'stable-key');$again=SPF_Event_Bus::publish('SyntheticEvent.v1','foundation_event','opaque-1',['stable'=>1],1,'stable-key');probe('DEDUPE-identical-replay-positive',!($first===true && $again===true),'identical replay must remain idempotently successful');
$failures=array_filter($tests,static fn($x)=>!$x['blocked']);echo 'SUMMARY '.count($tests).' synthetic regressions; blocked='. (count($tests)-count($failures)) .'; still_vulnerable='.count($failures)."\n";
exit($failures?1:0);
