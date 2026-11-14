<?php
declare(strict_types=1);
/* Requirement-index integrity only: a fixture probe is NOT full semantic acceptance. */
$root=dirname(__DIR__);$file=$root.'/V12-43-SEMANTIC-EVIDENCE-REGISTER-2026-11-10.json';
$data=json_decode((string)file_get_contents($file),true);$ids=array();
foreach(array('FR'=>12,'NFR'=>10,'CEN'=>3,'FUT'=>18) as $family=>$count){for($i=1;$i<=$count;$i++)$ids[]='F01-'.$family.'-'.sprintf($family==='CEN'?'%02d':'%03d',$i);}
if(!is_array($data)||!is_array($data['requirements']??null)||count($data['requirements'])!==43){fwrite(STDERR,"V12 evidence register is not 43 rows.\n");exit(1);}
$fail=array();$counts=array('B'=>0,'R'=>0,'N'=>0,'S'=>0);
foreach($data['requirements'] as $i=>$r){
 $id=$r['id']??'';
 if($id!==$ids[$i])$fail[]='ID mismatch at '.(string)$i;
 $g=$r['grade']??'';
 if(!array_key_exists($g,$counts)){$fail[]='Invalid evidence grade '.$id;continue;}
 $counts[$g]++;
 if(($r['independent_semantic_verified']??null)!==false||($r['staging_accepted']??null)!==false||($r['live_verified']??null)!==false)$fail[]='Unsupported acceptance '.$id;
 if(!is_string($r['unclosed_gate']??null)||strlen(trim($r['unclosed_gate']))<10)$fail[]='Missing gap '.$id;
 $path=$r['evidence_file']??'';
 if(!is_string($path)||!preg_match('~^(?:tests|qa|\.github/workflows)/[a-zA-Z0-9._/-]+$~D',$path)||str_contains($path,'..')||!is_file($root.'/'.$path)){$fail[]='Bad evidence path '.$id;continue;}
 $probe=$r['test_probe']??'';
 if($g==='S'&&$probe!=='')$fail[]='Structural-only claim with probe '.$id;
 if($g!=='S'&&(!is_string($probe)||$probe===''||!str_contains((string)file_get_contents($root.'/'.$path),$probe)))$fail[]='Missing source probe '.$id;
}
if(array_sum($counts)!==43)$fail[]='Count mismatch';
if($fail){fwrite(STDERR,"V12 requirement evidence FAIL:\n- ".implode("\n- ",$fail)."\n");exit(1);}
echo "V12 evidence register: 43 cases; B=".$counts['B'].", R=".$counts['R'].", N=".$counts['N'].", S=".$counts['S']."; acceptance remains pending\n";
