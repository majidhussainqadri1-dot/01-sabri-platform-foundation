<?php
declare(strict_types=1);
const ABSPATH='/tmp/';const ARRAY_A='ARRAY_A';
function __($s,$d=''){return $s;}
function is_wp_error($x){return $x instanceof WP_Error;}
function absint($v){return abs((int)$v);}
function get_current_user_id(){return 1;}
class WP_Error{public function __construct(public string $code,public string $message='',public array $data=[]){}public function get_error_code(){return $this->code;}}
class SPF_Runtime{public static function acquire_lock($a,$b,$c){return 'fake-lock-token';}public static function release_lock($a,$b){return true;}public static function now_mysql(){return '2026-11-22 14:00:00';}}
class SPF_Installer{public static function table($t){return 'wp_spf_'.$t;}}
class DB{public $last_error='';public $data=null;public $error='';public function prepare($q,...$args){return $q;}public function query($q){return 0;}public function get_results($q,$format){$this->last_error=$this->error;return $this->data;}}
$wpdb=new DB;
require dirname(__DIR__).'/includes/class-spf-event-bus.php';
$n=0;$f=[];$ok=function($v,$m)use(&$n,&$f){$n++;if(!$v)$f[]=$m;};
foreach([null,false,'wrong',17] as $data){$wpdb->data=$data;$wpdb->error='';$r=SPF_Event_Bus::dispatch_due();$ok($r instanceof WP_Error && $r->get_error_code()==='spf_outbox_select_failed','invalid rows rejected '.get_debug_type($data));}
$wpdb->data=[];$wpdb->error='Synthetic DB error';$r=SPF_Event_Bus::dispatch_due();$ok($r instanceof WP_Error && $r->get_error_code()==='spf_outbox_select_failed','last_error rejected');
$wpdb->error='';$r=SPF_Event_Bus::dispatch_due();$ok(is_array($r) && $r['sent']===0 && $r['retry']===0,'empty valid array permitted');
if($f){fwrite(STDERR,"OUTBOX DB FAIL ".count($f)."/$n ".implode(';',$f)."\n");exit(1);}echo "outbox DB read safety: $n/$n PASS\n";
