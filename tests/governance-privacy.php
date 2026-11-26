<?php
declare(strict_types=1);
const ABSPATH='/tmp/';
function __($s,$d=''){return $s;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function sanitize_text_field($v){return (string)$v;}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{public function __construct(public string $code,public string $message=''){} }
class SPF_Runtime{public static function canonicalize(array $v){ksort($v);return $v;}}
require dirname(__DIR__).'/includes/class-spf-governance.php';
$ref=new ReflectionMethod('SPF_Governance','sanitize_evidence');
$private=['email','phone','address','password','identity_document','api_key','birth_date','date_of_birth','full_name','ip_address','passport','session_id','card_number','bank_account','user_agent','national_id','credential','security_token','private_notes'];
$public=['owner'=>'file-01','source_commit_verified'=>true,'checksum_sha256'=>str_repeat('b',64),'status'=>'planned','founder_approval_id'=>'opaque-id','deployment_parity'=>false,'schema_version'=>'1.2.0'];
$in=$public;foreach($private as $key)$in[$key]='SYNTHETIC PRIVATE VALUE';$in['nested']=['phone'=>'SYNTHETIC MOBILE','event_version'=>2];
$out=$ref->invoke(null,$in);$n=0;$fail=[];
$ok=function($pass,$msg)use(&$n,&$fail){$n++;if(!$pass)$fail[]=$msg;};
$ok(is_array($out),'result array');
foreach($private as $key)$ok(($out[$key]??null)==='[redacted]','secret redaction '.$key);
foreach($public as $key=>$v)$ok(($out[$key]??null)===$v,'governance metadata retained '.$key);
$ok(($out['nested']['phone']??null)==='[redacted]','nested phone redacted');$ok(($out['nested']['event_version']??null)===2,'nested version retained');
if($fail){fwrite(STDERR,"GOVERNANCE PRIVACY FAIL (".count($fail)."/$n): ".implode(';',$fail)."\n");exit(1);}echo "governance evidence privacy: $n/$n PASS\n";
