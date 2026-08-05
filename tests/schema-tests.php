<?php
declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/includes/class-spf-installer.php');
$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach (['modules','contracts','routes','releases','release_states','amendments','health','flags','audit','idempotency','outbox'] as $table) {
    $assert(str_contains($source, "self::table( '{$table}' )"), "Missing schema table {$table}");
}
foreach ([
    'UNIQUE KEY module_key',
    'UNIQUE KEY contract_version',
    'UNIQUE KEY route_key',
    'UNIQUE KEY route_path',
    'UNIQUE KEY release_id',
    'UNIQUE KEY checksum_sha256',
    'UNIQUE KEY release_sequence',
    'UNIQUE KEY amendment_id',
    'UNIQUE KEY trace_id',
    'UNIQUE KEY entry_hash',
    'UNIQUE KEY owner_flag',
    'UNIQUE KEY scope_hash',
    'UNIQUE KEY event_id',
    'UNIQUE KEY dedupe_key',
] as $constraint) {
    $assert(str_contains($source, $constraint), "Missing constraint {$constraint}");
}
$assert(str_contains($source, 'acknowledgements_json longtext'), 'Missing contract acknowledgement ledger');
$assert(str_contains($source, 'sequence_no int'), 'Missing release-state sequence');
$assert(str_contains($source, 'record_version bigint'), 'Missing record-version columns');
$assert(str_contains($source, 'previous_hash char(64)'), 'Missing audit-chain previous hash');
$assert(str_contains($source, 'entry_hash char(64)'), 'Missing audit-chain entry hash');
$assert(str_contains($source, 'activation_snapshot'), 'Missing activation snapshot');
$assert(str_contains($source, 'activation_lock'), 'Missing activation lock');
$assert(str_contains($source, 'restore_activation_snapshot'), 'Missing activation compensation');

if ($failures) {
    fwrite(STDERR, "Schema tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Schema assertions: {$assertions}/{$assertions} PASS\n";
