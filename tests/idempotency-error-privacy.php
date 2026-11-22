<?php
declare(strict_types=1);
const ABSPATH='/tmp/';
function __($s,$d=''){return $s;}
function sanitize_key($v){return strtolower(preg_replace('/[^a-z0-9_\-]/i','',(string)$v));}
function sanitize_text_field($v){return (string)$v;}
require dirname(__DIR__).'/includes/class-spf-idempotency.php';
$fn=new ReflectionMethod('SPF_Idempotency','safe_error_data');
$private=['email','phone','address','birth_date','api_key','patient_note','ip_address','full_name','bank_account','session_token','national_id','passport','password_hash','first_name'];
$public=['status'=>409,'reason'=>'conflict','quota'=>30,'attempts'=>2,'recovery_receipt'=>'opaque-validated'];
$input=$public;foreach($private as $key)$input[$key]='SYNTHETIC PRIVATE VALUE';$input['nested']=['email'=>'synthetic@example.test','sequence'=>7];
$out=$fn->invoke(null,$input);$n=0;$fails=[];
$ok=function($v,$msg)use(&$n,&$fails){$n++;if(!$v)$fails[]=$msg;};
foreach($private as $key)$ok(($out[$key]??null)==='[redacted]','redacted '.$key);
foreach($public as $key=>$v)$ok(($out[$key]??null)===$v,'metadata preserved '.$key);
$ok(($out['nested']['email']??null)==='[redacted]','nested email redacted');$ok(($out['nested']['sequence']??null)===7,'nested numeric preserved');
if($fails){fwrite(STDERR,"IDEMPOTENCY ERROR PRIVACY FAIL ".count($fails)."/$n ".implode(';',$fails)."\n");exit(1);}echo "idempotency error replay privacy: $n/$n PASS\n";
