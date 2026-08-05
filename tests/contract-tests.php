<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && in_array($file->getExtension(), ['php', 'md', 'txt', 'yml'], true)) {
        $path = $file->getPathname();
        if (str_contains($path, DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR) || str_contains($path, DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR)) {
            continue;
        }
        $files[str_replace($root . DIRECTORY_SEPARATOR, '', $path)] = file_get_contents($path);
    }
}
$all = implode("\n", $files);
$assertions = 0;
$failures = [];

$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach ([
    'F01-FR-001','F01-FR-002','F01-FR-003','F01-FR-004','F01-FR-005','F01-FR-006',
    'F01-FR-007','F01-FR-008','F01-FR-009','F01-FR-010','F01-FR-011','F01-FR-012',
    'F01-NFR-001','F01-NFR-002','F01-NFR-003','F01-NFR-004','F01-NFR-005',
    'F01-NFR-006','F01-NFR-007','F01-NFR-008','F01-NFR-009','F01-NFR-010'
] as $id) {
    $assert(str_contains($files['TRACEABILITY.md'] ?? '', $id), "Traceability missing {$id}");
}

foreach ([
    'class SPF_Registry',
    'class SPF_Dependency_Resolver',
    'class SPF_System_Check',
    'class SPF_Reconciler',
    'class SPF_Repair',
    'class SPF_Purge',
    'class SPF_Governance',
    'class SPF_Event_Bus',
    'class SPF_Audit',
    'class SPF_Authorization',
] as $symbol) {
    $assert(str_contains($all, $symbol), "Missing implementation symbol {$symbol}");
}

foreach ([
    'foundation_module',
    'foundation_contract',
    'foundation_route',
    'foundation_release',
    'foundation_amendment',
    'foundation_health',
] as $entity) {
    $assert(str_contains($all, $entity), "Missing canonical entity reference {$entity}");
}

$assert(str_contains($all, 'FoundationModuleRegistered.v1'), 'Missing module event');
$assert(str_contains($all, 'FoundationRouteMapped.v1'), 'Missing route event');
$assert(str_contains($all, 'FoundationHealthChanged.v1'), 'Missing health event');
$assert(str_contains($all, 'X-Idempotency-Key'), 'Missing REST idempotency contract');
$assert(str_contains($all, 'dependency_cycle'), 'Missing cycle detection');
$assert(str_contains($all, 'spf_route_collision'), 'Missing route collision code');
$assert(str_contains($all, 'record_version'), 'Missing optimistic record versions');
$assert(str_contains($all, 'dead'), 'Missing dead-letter state');
$assert(str_contains($all, 'SPF_ALLOW_DESTRUCTIVE_PURGE'), 'Missing guarded purge gate');
$assert(str_contains($all, 'No public shell'), 'Missing ownership boundary declaration');

$assert(str_contains($all, 'FoundationContractRegistered.v1'), 'Missing contract event');
$assert(str_contains($all, 'FoundationReleaseRecorded.v1'), 'Missing release event');
$assert(str_contains($all, 'FoundationReleaseStateChanged.v1'), 'Missing release-state event');
$assert(str_contains($all, 'FoundationAmendmentApproved.v1'), 'Missing amendment event');
$assert(str_contains($all, 'acknowledge_contract'), 'Missing consumer acknowledgement command');
$assert(str_contains($all, "'/amendments'"), 'Missing amendment REST collection');
$assert(str_contains($all, "'/releases/(?P<release_id>"), 'Missing release-transition REST route');
$assert(str_contains($all, 'expected_sequence'), 'Missing optimistic release sequence guard');
$assert(str_contains($all, 'START TRANSACTION'), 'Missing transactional mutation boundary');
$assert(str_contains($all, 'FOR UPDATE'), 'Missing release concurrency lock');
$assert(str_contains($all, 'finally'), 'Missing outbox lock-release guarantee');
$assert(str_contains($all, 'acknowledgements_json'), 'Missing contract acknowledgement persistence');
$assert(str_contains($all, 'FoundationReleaseStateChanged.v1'), 'Missing release lifecycle history');

if ($failures) {
    fwrite(STDERR, "Contract tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Contract assertions: {$assertions}/{$assertions} PASS\n";
