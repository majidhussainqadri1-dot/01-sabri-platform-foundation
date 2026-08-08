from pathlib import Path
import subprocess

ROOT=Path('.')
BRANCH='codex/file-01-complete-foundation-1.0.0'

def read(p): return (ROOT/p).read_text()
def write(p,s): (ROOT/p).write_text(s)
def sh(c): print('+',c,flush=True); subprocess.run(c,shell=True,check=True)

tests='''<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap-minimal.php';
$root=dirname(__DIR__);$pass=0;
$fail=static function(string $m):void{fwrite(STDERR,"FAIL: {$m}\\n");exit(1);};
$expect=static function(bool $c,string $m)use(&$pass,$fail):void{if(!$c)$fail($m);$pass++;};
$src=static fn(string $f):string=>(string)file_get_contents($root.'/'.$f);
$runtime=$src('includes/class-spf-runtime.php');$expect(str_contains($runtime,'information_schema.TABLES')&&str_contains($runtime,'TABLE_NAME = %s')&&str_contains($runtime,'time() >= $current_expires'),'R1 exact table/lock truth missing');
$mm=new ReflectionMethod(SPF_Registry::class,'normalize_manifest');$mm->setAccessible(true);$m=['module_key'=>'file-01','owner_file'=>'01','owner_name'=>'File 01','slug'=>'file-01','namespace_prefix'=>'SPF_','software_version'=>'2.0.0','contract_version'=>'2.0.0','state'=>'active','required'=>[],'optional'=>[],'capabilities'=>[],'commands'=>['ok',''],'queries'=>[],'events'=>[],'routes'=>[],'data_classes'=>['internal'],'health'=>[],'canonical_entities'=>[],'writes'=>[]];$r=$mm->invoke(null,$m);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_manifest_collection_invalid','R2 invalid manifest value not rejected');
$cm=new ReflectionMethod(SPF_Registry::class,'normalize_contract');$cm->setAccessible(true);$r=$cm->invoke(null,['contract_key'=>'Test.v1','contract_version'=>'1.0.0','owner_module'=>'file-01','status'=>'current','schema'=>['x'=>['type'=>'string']],'consumers'=>['file-00','file-00']]);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_duplicate_contract_consumer','R2 duplicate contract consumer not rejected');
$registry=$src('includes/class-spf-registry.php');$expect(str_contains($registry,'spf_invalid_route_redirect')&&str_contains($registry,'spf_duplicate_route_redirect'),'R2 route redirect strictness missing');
$system=$src('includes/class-spf-system-check.php');$expect(str_contains($system,"'scheduler_id'")&&str_contains($system,"'verified_at'")&&str_contains($system,"'expires_at'")&&str_contains($system,'array_diff( $required_hooks, $reported_hooks )'),'R3 external cron evidence binding missing');
$pm=new ReflectionMethod(SPF_Event_Bus::class,'sanitize_payload');$pm->setAccessible(true);$x=[];for($i=0;$i<101;$i++)$x['f'.$i]=$i;$r=$pm->invoke(null,$x,0);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_event_payload_too_many_fields','R4 oversized event payload not rejected');$r=$pm->invoke(null,['Bad Key'=>'x'],0);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_event_payload_key_invalid','R4 noncanonical event payload key not rejected');
$sc=SPF_Platform_Engineering::scaffold_module(['module_key'=>'file-26','owner_file'=>'26','owner_name'=>'Test','slug'=>'test','prefix'=>'TST','required'=>['file-01'],'optional'=>[]]);$ci=$sc['files']['.github/workflows/qa.yml']??'';$expect(str_contains($ci,'actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1')&&str_contains($ci,'shivammathur/setup-php@7c071dfe9dc99bdf297fa79cb49ea005b9fcadbc'),'R5 Golden Path CI pins stale');
$cfg=new ReflectionMethod(SPF_Platform_Engineering::class,'sanitize_config');$cfg->setAccessible(true);$big=[];for($i=0;$i<201;$i++)$big['k'.$i]=$i;$r=$cfg->invoke(null,$big);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_config_too_large','R6 config truncation remains');$r=$cfg->invoke(null,['Bad Key'=>'x']);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_config_key_invalid','R6 noncanonical config key not rejected');
$num=new ReflectionMethod(SPF_Platform_Engineering::class,'sanitize_numeric_map');$num->setAccessible(true);$r=$num->invoke(null,['latency_p95'=>'bad']);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_numeric_map_invalid','R7 malformed SLO silently dropped');$g=SPF_Platform_Engineering::evaluate_slo_gate(['latency_p95'=>100],['Bad Metric'=>200]);$expect(empty($g['allow'])&&($g['reason']??'')==='invalid_slo_input','R7 invalid SLO did not fail closed');
$esm=new ReflectionMethod(SPF_Platform_Engineering::class,'normalize_event_schema');$esm->setAccessible(true);$r=$esm->invoke(null,['event_name'=>'Test.v1','version'=>'1.0.0','owner_module'=>'file-01','privacy_class'=>'internal','fields'=>['x'=>['type'=>'mystery']]]);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_event_schema_type_invalid','R8 invalid schema type not rejected');$r=$esm->invoke(null,['event_name'=>'Test.v1','version'=>'1.0.0','owner_module'=>'file-01','privacy_class'=>'internal','fields'=>['x'=>['type'=>'string']],'deprecated_at'=>'not-a-date']);$expect(is_wp_error($r)&&$r->get_error_code()==='spf_event_schema_deprecation_invalid','R8 invalid deprecation timestamp accepted');$r=$esm->invoke(null,['event_name'=>'Test.v1','version'=>'1.0.0','owner_module'=>'file-01','privacy_class'=>'confidential','fields'=>['x'=>['type'=>'string']]]);$expect(is_array($r)&&($r['privacy_class']??'')==='confidential','R8 privacy vocabulary mismatch remains');
$gov=$src('includes/class-spf-governance.php');$expect(str_contains($gov,'spf_release_package_name_invalid')&&substr_count($gov,'spf_release_evidence_too_large')>=2&&str_contains($gov,'spf_amendment_decision_too_large'),'R9 bounded release/amendment evidence guards missing');
$res=$src('includes/class-spf-resilience-lab.php');$expect(str_contains($res,'spf_self_heal_recovery_capacity_full')&&!str_contains($res,'array_slice( $recoveries, -20')&&str_contains($res,'spf_self_heal_compensation_incomplete')&&str_contains($res,'spf_self_heal_rollback_compensation_incomplete'),'R10 self-heal recovery/compensation truth missing');
printf("Seventh ten-round review assertions: %d/%d PASS\\n",$pass,$pass);
'''
write('tests/seventh-ten-round-review-tests.php',tests)
qa=read('qa/run-tests.sh');anchor='php tests/sixth-ten-round-review-tests.php\n'
if 'seventh-ten-round-review-tests.php' not in qa: qa=qa.replace(anchor,anchor+'php tests/seventh-ten-round-review-tests.php\n',1)
write('qa/run-tests.sh',qa)
review='''# File 01 — Seventh Fresh Ten-Round Review and Fix Cycle — 2026-08-08

A seventh independent adversarial review was completed after the sixth cycle. Each round was corrected immediately, regression-checked and committed before proceeding. The governing basis remains the consolidated central plan and File 01 v2.0 Future Foundation plan. Staging/live/operational acceptance remain separate evidence gates.

1. Exact database/lock runtime truth — fixed SQL LIKE wildcard table detection and exact lease expiry handling.
2. Canonical registry normalization truth — malformed/duplicate manifest values, contract consumers and redirects now fail instead of disappearing/collapsing.
3. External cron evidence truth — requires identified, fresh, expiring evidence covering every required File 01 hook.
4. Event fact completeness — oversized/deep/noncanonical/unsupported payloads now fail instead of silently truncating.
5. Golden-Path CI currency — generated workflow now pins checkout v7.0.1 and the approved PHP setup action.
6. Configuration drift completeness — oversized/deep/noncanonical input now fails instead of being truncated.
7. SLO/progressive-ring integrity — malformed metrics/objectives and duplicate/noncanonical rings now fail closed.
8. Event-schema/runtime alignment — schema privacy vocabulary matches runtime, invalid field types fail and deprecation timestamps are validated.
9. Release/amendment evidence envelope — non-empty canonical package binding and bounded evidence/decision payloads are enforced.
10. Self-heal recovery/compensation truth — recovery snapshots are not silently evicted; compensation is read-back verified; orphan dynamic quarantine options are removed.

**Defects found:** rounds 1, 2, 3, 4, 5, 6, 7, 8, 9 and 10.

**Defect-free rounds before correction:** none.

Two stale historical regression assertions were also refreshed after they failed against deliberately stronger implementations: the third-cycle external-cron exact-string assertion and the fourth-cycle schedule-health occurrence-count assertion. These were test-maintenance corrections, not additional review rounds.

Automated-QA Green is reasserted only after source, WordPress/MySQL runtime, concurrency, purge and deterministic-package jobs succeed on the exact final head. Hostinger staging, real companion coexistence, browser/device/accessibility/RTL/weak-network, representative load/cache/cron, independent backup/restore/rollback, Founder staging acceptance, production cutover and sustained monitoring remain separate pending gates.
'''
write('SEVENTH-TEN-ROUND-REVIEW-2026-08-08.md',review)
ch=read('CHANGELOG.md')
if 'Seventh fresh ten-round corrective cycle' not in ch: ch+='\n## 2.0.0 — Seventh fresh ten-round corrective cycle (2026-08-08)\n- Hardened exact table/lock truth, registry normalization, cron evidence, event payloads, generated CI, config drift, SLO/rings, event schemas, release evidence and self-heal recovery/compensation.\n- Added seventh-cycle regression assertions and permanent review evidence; refreshed two stale historical exact-source assertions.\n'
write('CHANGELOG.md',ch)
b=read('tools/build-package.sh')
needle='    FIFTH-TEN-ROUND-REVIEW-2026-08-08.md SIXTH-TEN-ROUND-REVIEW-2026-08-08.md\n'
if 'SEVENTH-TEN-ROUND-REVIEW-2026-08-08.md' not in b: b=b.replace(needle,'    FIFTH-TEN-ROUND-REVIEW-2026-08-08.md SIXTH-TEN-ROUND-REVIEW-2026-08-08.md\n    SEVENTH-TEN-ROUND-REVIEW-2026-08-08.md\n',1)
write('tools/build-package.sh',b)
for f in ['.github/workflows/seventh-fix-runner.yml','.github/workflows/seventh-resume-runner.yml','.github/workflows/seventh-finalize-runner.yml','tools/seventh-review-fix.py','tools/seventh-review-resume.py','tools/seventh-review-finalize.py']:
    p=Path(f)
    if p.exists(): p.unlink()
sh("find . -type f -not -path './.git/*' -not -path './build/*' -not -path './dist/*' -not -name 'SOURCE-CHECKSUMS.sha256' -print0 | LC_ALL=C sort -z | xargs -0 sha256sum > SOURCE-CHECKSUMS.sha256")
sh('bash qa/run-tests.sh')
sh('bash tools/build-package.sh')
sh('git config user.name "majidhussainqadri1-dot"');sh('git config user.email "majidhussainqadri1@gmail.com"')
sh('git add -A');sh('git diff --cached --check');sh("git commit -m '[seventh review final] Record seventh cycle and refresh exact evidence'");sh(f'git push origin HEAD:{BRANCH}')
print('SEVENTH_CYCLE_FINAL_HEAD='+subprocess.check_output('git rev-parse HEAD',shell=True,text=True).strip())
