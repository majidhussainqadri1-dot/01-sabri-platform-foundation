<?php
declare(strict_types=1);
const ABSPATH='/tmp/';
function __($s,$d=''){return $s;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function sanitize_text_field($v){return (string)$v;}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{public function __construct(public string $code,public string $message=''){} }
class SPF_Runtime{public static function canonicalize(array $v){ksort($v);return $v;}}
require dirname(__DIR__).'/includes/class-spf-event-bus.php';
$m=new ReflectionMethod('SPF_Event_Bus','sanitize_payload');
$private=['email','user_email','phone','address','full_name','patient_ref','password_hash','clinical_note','medical_history','mobile','ip_address','birth_date','session_id','api_key','ssn','payment_reference','credit_card','guardian_phone','emergency_contact_phone','geolocation','user_agent'];
$public=['status'=>'accepted','module_key'=>'file-01','owner_module'=>'file-01','version'=>'2.0.2','reason'=>'approved','sequence'=>4,'event_version'=>1,'trace_id'=>'synthetic'];
$payload=$public;
foreach($private as $k){$payload[$k]='SYNTHETIC PRIVATE VALUE';}
$payload['nested']=['email'=>'synthetic@example.test','quota'=>2];
$r=$m->invoke(null,$payload);$n=0;$f=[];
$ok=function($v,$message)use(&$n,&$f){$n++;if(!$v)$f[]=$message;};
$ok(is_array($r),'sanitize result is array');
foreach($private as $k){$ok(($r[$k]??null)==='[redacted]','PII redacted '.$k);}
foreach($public as $k=>$v){$ok(($r[$k]??null)===$v,'public metadata preserved '.$k);}
$ok(($r['nested']['email']??null)==='[redacted]','nested direct identifier redacted');
$ok(($r['nested']['quota']??null)===2,'nested non-sensitive numeric metadata preserved');
if($f){fwrite(STDERR,"EVENT PRIVACY FAIL (".count($f)."/$n): ".implode(';',$f)."\n");exit(1);}echo "event-payload privacy: $n/$n PASS\n";
