<?php
declare(strict_types=1);
const ABSPATH='/tmp/'; const ARRAY_A='ARRAY_A';
function __(string $s,$domain=''){return $s;}
function absint($v){return abs((int)$v);}
function get_user_by($field,$email){return $email==='unknown@example.test'?false:(object)['ID'=>222];}
class WP_Error{public function __construct(public string $code,public string $message=''){} public function get_error_code(){return $this->code;}}
class SPF_Installer{public static function table($name){return 'wp_spf_'.$name;}}
class SPF_Runtime{public static bool $tableMissing=false;public static function table_exists($table){return !self::$tableMissing;}}
class DB {
 public array $queries=[];public string $last_error='';public bool $failCounts=false;public bool $failPage=false;
 public array $counts=['wp_spf_audit'=>205,'wp_spf_release_states'=>7,'wp_spf_idempotency'=>3,'wp_spf_privacy_requests'=>4];
 public function prepare($query,...$args){$i=0;return preg_replace_callback('/%[ds]/',function($m)use($args,&$i){$value=$args[$i++];return $m[0]==='%d'?(string)(int)$value:"'".addslashes($value)."'";},$query);}
 public function get_var($sql){$this->queries[]=$sql;if($this->failCounts){$this->last_error='synthetic SQL failure';return null;}if(!preg_match('/COUNT\(\*\) FROM (wp_spf_[a-z_]+)/',$sql,$m))throw new RuntimeException('unexpected SQL '.$sql);return (string)($this->counts[$m[1]]??0);}
 public function get_results($sql,$format){$this->queries[]=$sql;if($this->failPage){$this->last_error='synthetic SELECT failure';return null;}if(!preg_match('/FROM (wp_spf_[a-z_]+).*LIMIT (\d+) OFFSET (\d+)$/',$sql,$m))throw new RuntimeException('UNBOUNDED SELECT '.$sql);$n=(int)$m[2];$skip=(int)$m[3];$this->maxRequested=max($this->maxRequested??0,$n);$total=$this->counts[$m[1]];return array_map(fn($i)=>['id'=>$skip+$i+1,'actor_id'=>222,'action_name'=>'audit','object_type'=>'foundation','object_id'=>'opaque','purpose'=>'synthetic','result_code'=>'pass','created_at'=>'2026-11-22','release_id'=>'synthetic','sequence_no'=>1,'status'=>'completed','updated_at'=>'2026-11-22','request_id'=>'synthetic','request_type'=>'export','legal_basis'=>'test','requested_at'=>'2026-11-22','completed_at'=>'2026-11-22'],range(0,max(0,min($n,$total-$skip))-1));}
}
$wpdb=new DB();
require dirname(__DIR__).'/includes/class-spf-privacy.php';
$tests=0;$failures=[];
function ok($condition,$msg){global $tests,$failures;$tests++;if(!$condition)$failures[]=$msg;}
for($page=1;$page<=4;$page++){
 $wpdb->queries=[];$r=SPF_Privacy::export_personal_data('test.person@example.test',$page);
 ok(is_array($r),'array response page'.$page);
 ok(count($r['data'])<=100,'bounded 100 rows page'.$page);
 ok(count(array_filter($wpdb->queries,fn($q)=>str_starts_with($q,'SELECT COUNT(*)')) )===4,'four COUNT queries '.$page);
 ok(count(array_filter($wpdb->queries,fn($q)=>str_starts_with($q,'SELECT ')&&!str_starts_with($q,'SELECT COUNT(*)')&&!preg_match('/LIMIT \d+ OFFSET \d+$/',$q)))===0,'all row reads bounded '.$page);
 ok(($r['done']??null)===($page>=3),'done accurately '.$page);
}
$wpdb->failCounts=true;$r=SPF_Privacy::export_personal_data('test.person@example.test');ok($r instanceof WP_Error && $r->get_error_code()==='spf_privacy_export_count_failed','fail closed count error');$wpdb->failCounts=false;
$wpdb->failPage=true;$r=SPF_Privacy::export_personal_data('test.person@example.test');ok($r instanceof WP_Error && $r->get_error_code()==='spf_privacy_export_page_failed','fail closed page error');$wpdb->failPage=false;
SPF_Runtime::$tableMissing=true;$r=SPF_Privacy::export_personal_data('test.person@example.test');ok($r instanceof WP_Error && $r->get_error_code()==='spf_privacy_export_table_missing','fail closed missing table');SPF_Runtime::$tableMissing=false;
$r=SPF_Privacy::export_personal_data('unknown@example.test');ok(is_array($r) && $r['done']===true && $r['data']===[],'unknown safe');
if($failures){fwrite(STDERR,"FAIL ".count($failures)."/{$tests}: ".implode('; ',$failures)."\n");exit(1);}echo "privacy export: {$tests}/{$tests} PASS\n";
