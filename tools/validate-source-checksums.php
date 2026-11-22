<?php
/**
 * File 01 strict closed-world source-manifest validator.
 * Rejects even ONE malformed SHA-256 line. GNU sha256sum --check can otherwise
 * return success when other lines are valid and malformed lines are skipped.
 * Repository-only QA; never included in the installable WordPress plugin.
 */
declare(strict_types=1);
$root = dirname(__DIR__);
$manifest = $root . '/SOURCE-CHECKSUMS.sha256';
function validate_checksum_manifest_lines(array $lines, ?string $rootPath = null): array {
    $errors = [];
    $seen = [];
    $paths = [];
    foreach ($lines as $i => $line) {
        // Last trailing newline is not a separate manifest entry.
        if ($line === '' && $i === array_key_last($lines)) {
            continue;
        }
        if (!preg_match('~^([a-f0-9]{64})  \./([^[:space:]]+)$~D', $line, $matches)) {
            $errors[] = 'invalid line format at ' . ($i + 1);
            continue;
        }
        [$whole, $hash, $relative] = $matches;
        $parts = explode('/', $relative);
        if ($relative === '' || str_contains($relative, chr(92)) || in_array('.', $parts, true) || in_array('..', $parts, true) || str_starts_with($relative, '/')) {
            $errors[] = 'unsafe source path at ' . ($i + 1);
            continue;
        }
        if (isset($seen[$relative])) {
            $errors[] = 'duplicate manifest path at ' . ($i + 1);
            continue;
        }
        $seen[$relative] = true;
        $paths[] = './' . $relative;
        if ($rootPath !== null) {
            $file = $rootPath . '/' . $relative;
            if (!is_file($file) || is_link($file)) {
                $errors[] = 'source missing or symlink at ' . ($i + 1);
                continue;
            }
            $actual = hash_file('sha256', $file);
            if (!is_string($actual) || !hash_equals($hash, $actual)) {
                $errors[] = 'source hash mismatch at ' . ($i + 1);
            }
        }
    }
    if (!count($seen)) {
        $errors[] = 'source manifest empty';
    }
    return ['errors'=>$errors,'paths'=>$paths,'count'=>count($seen)];
}
if (in_array('--self-test', $argv, true)) {
    $good = str_repeat('a',64).'  ./includes/test.php';
    $cases = [
      ['lines'=>[$good], 'pass'=>true],
      ['lines'=>[substr($good,1)], 'pass'=>false], // old 63-char SBOM omission
      ['lines'=>[$good,substr($good,1)], 'pass'=>false], // mixed valid/malformed
      ['lines'=>[$good,$good], 'pass'=>false], // duplicated path
      ['lines'=>[str_repeat('g',64).'  ./file.php'], 'pass'=>false], // non-hex
      ['lines'=>[str_repeat('a',64).' ./file.php'], 'pass'=>false], // one-space separator
      ['lines'=>[str_repeat('a',64).'  ./../secrets.php'], 'pass'=>false], // traversal
      ['lines'=>[str_repeat('a',64).'  ./file name.php'], 'pass'=>false], // path with spaces
      ['lines'=>[], 'pass'=>false], // empty
      ['lines'=>[$good,''], 'pass'=>true], // normal terminal newline
    ];
    $fail=0;
    foreach ($cases as $i=>$case) {
        $valid = !validate_checksum_manifest_lines($case['lines'])['errors'];
        if ($valid !== $case['pass']) {fwrite(STDERR, 'Case '.($i+1).' failed'."\n");$fail++;}
    }
    if ($fail) {exit(1);}
    echo 'SHA-256 manifest adversarial grammar: '.count($cases).'/'.count($cases).' PASS'."\n";
    exit(0);
}
if (!is_file($manifest)) {fwrite(STDERR,"SOURCE-CHECKSUMS missing\n");exit(1);}
$lines = explode("\n", (string)file_get_contents($manifest));
$assessment = validate_checksum_manifest_lines($lines,$root);
if ($assessment['errors']) {fwrite(STDERR, implode("\n", $assessment['errors'])."\n");exit(1);}
$actual = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $relative = str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
    if ($relative === 'SOURCE-CHECKSUMS.sha256' || preg_match('~^(\.git|build|dist)/~', $relative)) {continue;}
    if (!$file->isFile() || $file->isLink()) {fwrite(STDERR, "Unexpected source type: {$relative}\n");exit(1);}
    $actual[] = './'.$relative;
}
sort($actual,SORT_STRING);
$paths = $assessment['paths'];sort($paths,SORT_STRING);
if ($paths !== $actual) {fwrite(STDERR,"Source inventory differs from exact manifest. Missing/untracked source is blocked.\n");exit(1);}
echo "Strict exact SHA-256 manifest and closed-world inventory: {$assessment['count']}/{$assessment['count']} PASS\n";
