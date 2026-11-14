<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap-minimal.php';

$assertions = 0; $errors = array();
$assert = static function ($pass, $message) use (&$assertions, &$errors) {
    $assertions++;
    if (!$pass) { $errors[] = $message; }
};

// A strict, offline stand-in for a GitHub Contents GET. A fixture cannot bypass
// the collector's own exact commit + checksum + JSON structure validation.
$GLOBALS['spf_github_fixtures'] = array();
if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = array()) {
        return $GLOBALS['spf_github_fixtures'][$url] ?? new WP_Error('mock_missing', 'GitHub fixture not found');
    }
}
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) { return (int) ($response['response']['code'] ?? 0); }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) { return (string) ($response['body'] ?? ''); }
}
$commit = str_repeat('a', 40);
$manifest = static function ($key, $entity, $route) {
    return array('module_key'=>$key,'canonical_entities'=>array($entity),'writes'=>array(),
        'routes'=>array(array('route_path'=>$route,'owner_module'=>$key)));
};
$create = static function ($repository, $key, $manifest) use ($commit) {
    $path = 'architecture-manifest.json';
    $json = json_encode($manifest, JSON_UNESCAPED_SLASHES);
    $url = 'https://api.github.com/repos/' . $repository . '/contents/' . $path . '?ref=' . $commit;
    $GLOBALS['spf_github_fixtures'][$url] = array(
        'response'=>array('code'=>200),
        'body'=>$json,
    );
    return array('repository'=>$repository,'commit_sha'=>$commit,'path'=>$path,
        'expected_sha256'=>hash('sha256',$json),'module_key'=>$key);
};
$left = $create('sabri/20-shell','file-20',$manifest('file-20','global-shell','/shared'));
$right = $create('sabri/21-news','file-21',$manifest('file-21','global-shell','/shared'));
$cross = SPF_Governance_Control_Plane::lint_pinned_repository_sources(array($left,$right));
$assert(is_array($cross) && $cross['source_verified_count']===2,'G-01: two pinned GitHub sources not verified.');
$assert($cross['pass']===false && in_array('duplicate_canonical_owner',array_column($cross['findings'],'code'),true),
    'G-01: conflicting source-owned entities were not detected.');
$wrong = $right; $wrong['expected_sha256'] = str_repeat('0',64);
$rejected = SPF_Governance_Control_Plane::lint_pinned_repository_sources(array($left,$wrong));
$assert($rejected['pass']===false && $rejected['source_verified_count']===1,
    'G-01: tampered manifest was silently treated as verified.');
$unpinned = $right; unset($unpinned['commit_sha']);
$rejected = SPF_Governance_Control_Plane::lint_pinned_repository_sources(array($left,$unpinned));
$assert($rejected['pass']===false,'G-01: unpinned repository source was accepted.');

// V12 00–26 governance means 27 canonical modules, not 26. This exact-count
// regression must fail on the former 26-descriptor limit, and must reject 28.
$complete_canonical_descriptors = array();
for ($file_index = 0; $file_index <= 26; $file_index++) {
    $module_key = sprintf('file-%02d', $file_index);
    $canonical_manifest = $manifest($module_key, $file_index === 20 ? 'global-shell' : 'entity-'.$module_key, '/canonical-'.$module_key);
    if ($file_index === 20) {
        $canonical_manifest['global_shell_owner'] = true;
        $canonical_manifest['application_shell_owner'] = true;
    }
    $complete_canonical_descriptors[] = $create(
        'sabri/canonical-fixture-'.sprintf('%02d',$file_index), $module_key, $canonical_manifest
    );
}
$full_catalog = SPF_Governance_Control_Plane::lint_pinned_repository_sources($complete_canonical_descriptors);
$assert(is_array($full_catalog) && $full_catalog['pass'] === true
    && $full_catalog['source_count'] === 27 && $full_catalog['source_verified_count'] === 27
    && empty($full_catalog['findings']),
    'F01-FUT-003: the valid full canonical 00–26 (27-module) architecture is incorrectly rejected.');
$extra_catalog = $complete_canonical_descriptors;
$extra_catalog[] = $create('sabri/extra-fixture','file-26',$manifest('file-26','unexpected-28','/unexpected-28'));
$too_many = SPF_Governance_Control_Plane::lint_pinned_repository_sources($extra_catalog);
$assert(is_array($too_many) && $too_many['pass'] === false
    && in_array('source_count_invalid',array_column($too_many['findings'],'code'),true),
    'F01-FUT-003: more than 27 canonical sources did not fail closed.');

$root = dirname(__DIR__);
$validHash = hash_file('sha256',$root.'/TRACEABILITY.md');
$assert(SPF_Governance_Control_Plane::verify_v12_source_file($root,'TRACEABILITY.md',$validHash) === true,
    'G-02: genuine source file hash did not verify.');
$assert(SPF_Governance_Control_Plane::verify_v12_source_file($root,'TRACEABILITY.md',str_repeat('0',64)) === false,
    'G-02: forged source hash passed.');
$assert(SPF_Governance_Control_Plane::verify_v12_source_file($root,'../wp-config.php',$validHash) === false,
    'G-02: scanner accepted traversal outside repository.');
$report = SPF_Governance_Control_Plane::audit_v12_local_sources($root,$commit);
$assert(is_array($report) && $report['total']===43,'G-02: 43 canonical requirements were not scanned.');
$assert(!empty($report['source_mapped']) && $report['coded_completion_claim_allowed']===false,
    'G-02: local source evidence failed closed status or incorrectly claimed acceptance.');
$assert(isset($report['rows']['F01-FUT-003'],$report['rows']['F01-CEN-01']),
    'G-02: exact canonical requirement identifiers were not preserved.');

$challenge = str_repeat('b',32);
$receipt = array('scenario'=>'dependency_timeout','challenge'=>$challenge,'adapter'=>'test-only',
    'fault_observed'=>true,'recovery_observed'=>true,'fault_evidence_sha256'=>str_repeat('c',64),
    'recovery_evidence_sha256'=>str_repeat('d',64));
$assert(SPF_Resilience_Lab::verify_chaos_receipt($receipt,'dependency_timeout',$challenge)===true,
    'G-03: authentic structured fault and recovery evidence rejected.');
$bad = $receipt; $bad['challenge']=str_repeat('e',32);
$assert(SPF_Resilience_Lab::verify_chaos_receipt($bad,'dependency_timeout',$challenge)===false,
    'G-03: stale/mismatched challenge accepted.');
$bad = $receipt; $bad['recovery_observed']=false;
$assert(SPF_Resilience_Lab::verify_chaos_receipt($bad,'dependency_timeout',$challenge)===false,
    'G-03: missing recovery proof accepted.');
$bad = $receipt; unset($bad['fault_evidence_sha256']);
$assert(SPF_Resilience_Lab::verify_chaos_receipt($bad,'dependency_timeout',$challenge)===false,
    'G-03: missing observed fault evidence accepted.');

if ($errors) {
    fwrite(STDERR, "V12 three-gap regression tests failed:\n- ".implode("\n- ", $errors)."\n");
    exit(1);
}
echo "V12 three-gap assertions: {$assertions}/{$assertions} PASS\n";
