<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$runtimeFiles = [
    'sabri-platform-foundation.php',
    'uninstall.php',
];
$php = [];
foreach ($runtimeFiles as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . $relative;
    if (is_file($path)) {
        $php[$relative] = file_get_contents($path);
    }
}
$includes = $root . DIRECTORY_SEPARATOR . 'includes';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($includes, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'php') {
        $path = $file->getPathname();
        $php[str_replace($root . DIRECTORY_SEPARATOR, '', $path)] = file_get_contents($path);
    }
}
$runtime = implode("\n", $php);
$assertions = 0;
$failures = [];
$assert = static function (bool $condition, string $message) use (&$assertions, &$failures): void {
    $assertions++;
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(!preg_match('/update_option\s*\(\s*[\'"]spf_founder_user_id[\'"]\s*,\s*get_current_user_id/i', $runtime), 'Unsafe activating-user Founder assignment remains');
$assert(!str_contains($runtime, "add_shortcode( 'sabri_platform_home'"), 'Legacy public Home shortcode remains');
$assert(!str_contains($runtime, "add_shortcode( 'sabri_platform_module'"), 'Legacy module shortcode remains');
$assert(!preg_match('/wp_insert_post\s*\(/', $runtime), 'File 01 silently creates pages');
$assert(!preg_match('/get_posts\s*\(/', $runtime), 'File 01 still owns a post/feed query');
$assert(!str_contains($runtime, '--spf-orange'), 'Obsolete orange design token remains');
$assert(str_contains($runtime, 'check_admin_referer'), 'Admin CSRF protection missing');
$assert(str_contains($runtime, 'permission_callback'), 'REST permission callbacks missing');
$assert(str_contains($runtime, 'hash_equals'), 'Constant-time plan/idempotency comparison missing');
$assert(str_contains($runtime, 'sanitize_text_field'), 'Input sanitization missing');
$assert(str_contains($runtime, 'wp_safe_redirect'), 'Safe admin redirect missing');
$assert(str_contains($runtime, 'spf_file00_authorization_claim') && str_contains($runtime, 'validate_claim'), 'Canonical structured File 00 authorization adapter missing');
$assert(str_contains($runtime, 'spf_file00_capability_claim'), 'File 00 legacy claim bridge missing');
$assert(str_contains($runtime, 'context_hash'), 'Tamper-evident audit context hash missing');
$assert(str_contains($runtime, '[redacted]') || str_contains($runtime, "'redacted'=>true") || str_contains($runtime, "'redacted' => true"), 'Redaction marker missing');
$assert(str_contains($runtime, 'DROP TABLE'), 'Explicit purge implementation missing');
$assert(str_contains($runtime, 'PURGE FILE 01 GOVERNANCE DATA'), 'Typed purge confirmation missing');
$assert(str_contains($runtime, 'spf_verify_backup_restore_evidence') && str_contains($runtime, 'backup_id') && str_contains($runtime, 'restore_tested_at'), 'Structured purge backup/restore evidence gate missing');
$assert(str_contains($runtime, 'spf_verify_file24_purge_assurance'), 'File 24 purge assurance gate missing');
$assert(str_contains($runtime, 'APPLY FILE 01 RECONCILIATION'), 'Typed reconciliation confirmation missing');
$assert(str_contains($runtime, 'ROLL BACK FILE 01 RECONCILIATION'), 'Reconciliation rollback confirmation missing');
$assert(str_contains($runtime, 'REPAIR FILE 01 OWNED STATE'), 'Typed repair confirmation missing');
$assert(!preg_match('/eval\s*\(|base64_decode\s*\(|shell_exec\s*\(|passthru\s*\(|system\s*\(/', $runtime), 'Dangerous execution primitive found');

$assert(str_contains($runtime, 'is_same_origin_url') || str_contains($runtime, 'same_origin'), 'Same-origin route enforcement missing');
$assert(str_contains($runtime, 'spf_external_route_destination') || str_contains($runtime, 'spf_unsafe_route_destination'), 'Open-redirect rejection code missing');
$assert(str_contains($runtime, 'scope_hash'), 'Actor/action scoped idempotency missing');
$assert(str_contains($runtime, 'previous_hash'), 'Audit chain predecessor missing');
$assert(str_contains($runtime, 'entry_hash'), 'Audit chain entry hash missing');
$assert(str_contains($runtime, 'record_required'), 'Mandatory audit path missing');
$assert(str_contains($runtime, 'START TRANSACTION'), 'Transactional governance writes missing');
$assert(str_contains($runtime, 'ROLLBACK'), 'Transactional compensation missing');
$assert(str_contains($runtime, 'FOR UPDATE'), 'Concurrency locking missing');
$assert(str_contains($runtime, 'finally'), 'Dispatcher lock finally-release missing');
$assert(!str_contains($runtime, 'wp_cache_flush('), 'Global cache flush overreach found');
$assert(!preg_match('/admin_url\([^\)]*\)\s*;?\s*\n?\s*wp_redirect/i', $runtime), 'Unsafe redirect pattern found');
$assert(str_contains($runtime, 'noindex'), 'Restricted route indexing protection missing');
$assert(str_contains($runtime, 'nocache_headers'), 'Restricted route cache protection missing');

// v2.0 Future Foundation: explicitly enforce canonical-domain and non-autonomous safety.
$assert(str_contains($runtime, 'companion_data_modified') && str_contains($runtime, "'file-01-only'"), 'Bounded self-healing ownership evidence missing');
$assert(str_contains($runtime, "'ai_autonomous_changes'") && str_contains($runtime, "'ai_autonomous_approval'"), 'AI advisory-only status fields missing');
$assert(str_contains($runtime, "'production' !== \$environment"), 'Production-safe chaos/event replay guard missing');
$assert(str_contains($runtime, 'error_budget_remaining'), 'SLO/error-budget gate missing');
$assert(str_contains($runtime, 'global_shell_owners') && str_contains($runtime, "'file-20'"), 'File 20 shell ownership linter boundary missing');

if ($failures) {
    fwrite(STDERR, "Security tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Security assertions: {$assertions}/{$assertions} PASS\n";
