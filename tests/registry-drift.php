<?php
declare(strict_types=1);
const ABSPATH='/tmp/'; const SPF_VERSION='2.0.2'; const SPF_CONTRACT_VERSION='2.0.0';
function sanitize_key($x){return strtolower(preg_replace('/[^a-z0-9_-]/i','',(string)$x));}
function sanitize_text_field($x){return trim((string)$x);}
class SPF_Installer{
 const VERSION_OPTION='spf_version';
 public static function file01_manifest(){return ['events'=>['FoundationHealthChanged.v1','FoundationModuleRegistered.v1']];}
}
class SPF_Registry {
 public static function valid_semver($v){return is_string($v) && boolval(preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D',$v));}
 public static function get_module($key){return $GLOBALS['manifest'];}
}
function get_option($key,$default){return $GLOBALS['software']??$default;}
require dirname(__DIR__).'/includes/class-spf-system-check.php';
$fn=new ReflectionMethod('SPF_System_Check','source_registry_checks');
$base=['manifest_valid'=>true,'software_version'=>'2.0.2','contract_version'=>'2.0.0','state'=>'active','events'=>['FoundationHealthChanged.v1','FoundationModuleRegistered.v1']];
$n=0;$errors=[];
function e($condition,$message){global $n,$errors;$n++;if(!$condition)$errors[]=$message;}
function probe(){global $fn;$a=$fn->invoke(null);return array_column($a,null,'code');}
$GLOBALS['software']='2.0.2';$GLOBALS['manifest']=$base;$v=probe();e(count($v)===2,'two independent source checks');e($v['installed_software_version']['status']==='pass','matching software');e($v['foundation_manifest_current']['status']==='pass','matching owner events');
$GLOBALS['software']='2.0.1';$v=probe();e($v['installed_software_version']['status']==='warning','same-schema old software flagged');e($v['foundation_manifest_current']['status']==='pass','owner independent from wp_options');
$GLOBALS['software']='nonsense';e(probe()['installed_software_version']['status']==='warning','invalid stored software flagged');$GLOBALS['software']='2.0.2';
$GLOBALS['manifest']['software_version']='2.0.1';e(probe()['foundation_manifest_current']['status']==='warning','old owner software flagged');$GLOBALS['manifest']=$base;
$GLOBALS['manifest']['events']=['FoundationHealthChanged.v1'];e(probe()['foundation_manifest_current']['status']==='warning','old 1/2 events flagged');$GLOBALS['manifest']=$base;
$GLOBALS['manifest']['events'][]='UnauthorizedFakeEvent.v1';e(probe()['foundation_manifest_current']['status']==='warning','extra unknown event flagged');$GLOBALS['manifest']=$base;
$GLOBALS['manifest']['events']=array_reverse($base['events']);e(probe()['foundation_manifest_current']['status']==='pass','event order not material');$GLOBALS['manifest']=$base;
$GLOBALS['manifest']['state']='degraded';e(probe()['foundation_manifest_current']['status']==='warning','degraded manifest not current');$GLOBALS['manifest']=$base;
$GLOBALS['manifest']['contract_version']='1.0.0';e(probe()['foundation_manifest_current']['status']==='warning','contract drift not current');$GLOBALS['manifest']=$base;
$GLOBALS['manifest']['manifest_valid']=false;e(probe()['foundation_manifest_current']['status']==='warning','corrupted manifest not current');$GLOBALS['manifest']=null;
e(probe()['foundation_manifest_current']['status']==='warning','missing manifest not current');
if($errors){fwrite(STDERR,'FAIL '.count($errors).' / '.$n.' '.implode('; ',$errors)."\n");exit(1);}echo "same-schema version/manifest drift: {$n}/{$n} PASS\n";
