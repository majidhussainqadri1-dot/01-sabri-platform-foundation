<?php
/** Read-only synthetic database emulator against exact extracted 2.0.2 SPF_Privacy class. */
declare(strict_types=1);
const ABSPATH='/tmp/'; const ARRAY_A='ARRAY_A'; const MINUTE_IN_SECONDS=60;
function __($s,$domain=''){return $s;}
function absint($x){return abs((int)$x);}
function get_user_by($field,$email){return (object)['ID'=>222];}
class WP_Error{public function __construct(public string $code,public string $message=''){}public function get_error_code(){return $this->code;}}
function wp_salt($scheme='auth'){return 'synthetic-test-site-secret';}
$GLOBALS['export_transients']=[];function set_transient($key,$value,$ttl){$GLOBALS['export_transients'][$key]=$value;return true;}function get_transient($key){return $GLOBALS['export_transients'][$key]??false;}
class SPF_Runtime{public static function table_exists($table){return true;} public static function hash($v){return hash('sha256',json_encode($v));}}
class SPF_Installer{public static function table($name){return 'wp_spf_'.$name;}}
class SyntheticDB {
 public $last_error='';public array $ids=[];
 public function prepare($q,...$args){$i=0;return preg_replace_callback('/%[ds]/',function($m)use($args,&$i){$v=$args[$i++];return $m[0]==='%d'?(string)(int)$v:"'".str_replace("'","''",(string)$v)."'";},$q);}
 public function get_var($q){if(preg_match('/COUNT\(\*\) FROM ([a-z_]+)/',$q,$m)){return str_contains($m[1],'spf_audit')?count($this->ids):0;}if(preg_match('/COALESCE\(MAX\(id\),0\) FROM ([a-z_]+)/',$q,$m)){return str_contains($m[1],'spf_audit')?max($this->ids):0;}throw new RuntimeException('Unexpected SQL: '.$q);}
 public function get_results($sql,$format){if(!preg_match('/FROM (wp_spf_[a-z_]+) .*LIMIT (\d+) OFFSET (\d+)$/',$sql,$m))throw new RuntimeException('Not paginated: '.$sql);if($m[1]!=='wp_spf_audit')return [];$ids=array_slice($this->ids,(int)$m[3],(int)$m[2]);return array_map(static fn($id)=>['id'=>$id,'action_name'=>'synthetic','object_type'=>'test','object_id'=>'opaque','purpose'=>'test','result_code'=>'success','created_at'=>'2026-11-23'], $ids);}
}
$wpdb=new SyntheticDB();$wpdb->ids=range(1,101);
require dirname(__DIR__).'/includes/class-spf-privacy.php';
$page1=SPF_Privacy::export_personal_data('synthetic@example.test',1);
if (!is_array($page1) || count($page1['data'])!==100) {fwrite(STDERR,'Page 1 not bounded correctly'.PHP_EOL);exit(1);}
$page2=SPF_Privacy::export_personal_data('synthetic@example.test',2);
if (!is_array($page2) || count($page2['data'])!==1 || $page2['done']!==true) {fwrite(STDERR,'Stable second page failed'.PHP_EOL);exit(1);}
$wpdb->ids=range(1,101);$page1=SPF_Privacy::export_personal_data('synthetic@example.test',1);
array_shift($wpdb->ids); // simulate concurrent retention deleting the first row
$page2=SPF_Privacy::export_personal_data('synthetic@example.test',2);
if (!($page2 instanceof WP_Error) || $page2->get_error_code()!=='spf_privacy_export_changed') {fwrite(STDERR,'Concurrent deletion silently accepted'.PHP_EOL);exit(1);}
echo 'Privacy export page mutation detected; stable page path passes: 2/2 PASS'.PHP_EOL;
