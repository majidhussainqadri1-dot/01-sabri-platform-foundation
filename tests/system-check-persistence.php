<?php
declare(strict_types=1);
const ABSPATH = '/tmp/';const SPF_VERSION='2.0.2';const SPF_SCHEMA_VERSION='1.2.0';const SPF_CONTRACT_VERSION='2.0.0'; const ARRAY_A='ARRAY_A';
$GLOBALS['dbFailHealth']=false;
function __($s,$d=''){return $s;}
function sanitize_key($s){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$s));}
function sanitize_text_field($s){return trim((string)$s);}
function absint($n){return abs((int)$n);}
function is_wp_error($v){return $v instanceof WP_Error;}
function wp_next_scheduled($h){return time()+300;}
function wp_get_schedule($h){return match($h){'spf_dispatch_outbox','spf_future_foundation_tick'=>'spf_five_minutes','spf_reconcile_expired_flags'=>'hourly',default=>'daily'};}
function get_bloginfo($k){return '7.1.5';}
function get_option($k,$default=null){return match($k){'rewrite_rules'=>['platform-system-check/?$'=>'stub','platform-foundation/status/?$'=>'stub'],'spf_version'=>'2.0.2','spf_schema_version'=>'1.2.0','spf_upgrade_state'=>['status'=>'not-required'],default=>$default};}
function wp_using_ext_object_cache(){return false;}
function apply_filters($h,$fallback,...$args){return $fallback;}
function wp_generate_password($l,$a=false,$b=false){return str_repeat('A',$l);}
function wp_cache_set($k,$v,$g,$t){$GLOBALS['cache'][$k]=$v;}
function wp_cache_get($k,$g){return $GLOBALS['cache'][$k]??null;}
function wp_cache_delete($k,$g){unset($GLOBALS['cache'][$k]);}
function wp_upload_dir(){return ['basedir'=>'/tmp'];}
function wp_is_writable($s){return true;}
function has_filter($s){return false;}
function has_action($s){return false;}
function wp_json_encode($v){return json_encode($v);}
class WP_Error{function __construct(public string $code,public string $message='',public array $data=[]){}function get_error_code(){return $this->code;}}
class DB{public $dbh=true;public $prefix='wp_';public $last_error=''; public $healthRows=0; public function get_charset_collate(){return 'DEFAULT CHARACTER SET utf8mb4';} public function prepare($q,...$a){return $q;}public function get_var($q){if(str_contains($q,'COUNT(*)'))return '0';return null;} public function query($sql){return true;}public function insert($table,$data,$types){if(str_contains($table,'health')){$this->healthRows++;}return 1;}}
$wpdb=new DB;
class SPF_Authorization{public static function require_action($a,$o,$c){return true;}}
class SPF_Audit{public static function trace_id(){return 'e1965c24-9f21-4286-8348-7f41870f72aa';}public static function verify_chain($n){return ['rows'=>0];}public static function record_required(...$args){return true;}}
class SPF_Event_Bus{public static function publish(...$args){return true;}}
class SPF_Installer{const SCHEMA_OPTION='spf_schema_version';const VERSION_OPTION='spf_version';public static function table($name){return 'wp_spf_'.$name;}public static function verify_schema(){return true;}public static function canonical_module_catalog(){return [['module_key'=>'file-01']];}public static function file01_manifest(){return ['events'=>['FoundationHealthChanged.v1','FoundationModuleRegistered.v1']];}}
class SPF_Runtime{public static function verify_owned_tables_transactional(){return true;}public static function begin(){return $GLOBALS['dbFailHealth']?new WP_Error('db_refusal','Synthetic blocked transaction'):true;}public static function commit(){return true;}public static function rollback(){}public static function table_exists($x){return true;}public static function now_mysql(){return '2026-11-22 14:00:00';}}
class SPF_Registry{public static function valid_semver($v){return preg_match('/^\d+\.\d+\.\d+$/',(string)$v)===1;}public static function compare_semver($a,$b){return version_compare($a,$b);}public static function get_module($m){return $m==='file-01'?['manifest_valid'=>true,'software_version'=>'2.0.2','contract_version'=>'2.0.0','state'=>'active','events'=>['FoundationHealthChanged.v1','FoundationModuleRegistered.v1']]:null;}public static function list_modules(...$a){return [['module_key'=>'file-01']];}}
class SPF_Dependency_Resolver{public static function readiness($m){return ['ready'=>true,'optional_dependencies'=>[]];}}
class SPF_Governance{public static function list_releases($n){return [];}}
require dirname(__DIR__).'/includes/class-spf-system-check.php';
$n=0;$fail=[];function ass($ok,$msg){global $n,$fail;$n++;if(!$ok)$fail[]=$msg;}
$r=SPF_System_Check::run(false);ass(is_array($r),'readonly returns checks');ass($wpdb->healthRows===0,'readonly not persisted');ass(!in_array('health_persistence',array_column($r['checks'],'code'),true),'no spurious failure in readonly');
$GLOBALS['dbFailHealth']=true;
$r=SPF_System_Check::run(true,['purpose'=>'run_system_check']);
ass($r instanceof WP_Error,'persistence failure returns WP_Error');ass($r->get_error_code()==='spf_health_persist_failed','precise persistence failure code');ass($wpdb->healthRows===0,'failed transaction did not write health row');
$GLOBALS['dbFailHealth']=false;
$r=SPF_System_Check::run(true,['purpose'=>'run_system_check']);ass(is_array($r),'successful transaction returns array');ass($wpdb->healthRows===1,'health row saved once');ass(in_array($r['overall_status'],['pass','warning','fail'],true),'health assessment explicit');
if($fail){fwrite(STDERR,'FAIL '.count($fail).'/'.$n.' '.implode(';',$fail)."\n");exit(1);}echo "health persistence handling: {$n}/{$n} PASS\n";
