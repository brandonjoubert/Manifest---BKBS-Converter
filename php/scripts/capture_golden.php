<?php
declare(strict_types=1);

/**
 * Claim Ledger Stage 0 — capture PHP golden publish output from shared fixture.
 *
 * Usage: php php/scripts/capture_golden.php
 */

$root = dirname(__DIR__, 2);
require $root . '/php/src/bootstrap.php';

$fixturePath = $root . '/test-fixtures/stage0_site.json';
$outDir = $root . '/test-fixtures/golden-v0-php';

if (!is_file($fixturePath)) {
    fwrite(STDERR, "Missing fixture: $fixturePath\n");
    exit(1);
}

$fixture = json_decode((string) file_get_contents($fixturePath), true);
if (!is_array($fixture)) {
    fwrite(STDERR, "Invalid fixture JSON\n");
    exit(1);
}

$site = $fixture['site'];
$entities = $fixture['entities'];

// Fresh output dir
if (is_dir($outDir)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($outDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
}
mkdir($outDir, 0755, true);

$publisher = new Bkbs\Publisher();
$result = $publisher->publish($site, $entities, $outDir);
if (empty($result['ok'])) {
    fwrite(STDERR, 'Publish failed: ' . ($result['error'] ?? 'unknown') . "\n");
    exit(1);
}

// Write counts alongside (shared with Python)
$counts = [];
foreach ($entities as $e) {
    $st = $e['status'] ?? 'unknown';
    $counts[$st] = ($counts[$st] ?? 0) + 1;
}
$countsPath = $root . '/test-fixtures/entity-counts.json';
file_put_contents($countsPath, json_encode([
    'site_id' => $site['id'],
    'site_name' => $site['name'],
    'by_status' => $counts,
    'total' => count($entities),
    'approved' => $counts['approved'] ?? 0,
    'pending' => $counts['pending'] ?? 0,
], JSON_PRETTY_PRINT) . "\n");

echo "PHP golden written: $outDir\n";
echo "Files: " . implode(', ', $result['files'] ?? []) . "\n";
echo "Approved entities published: " . ($result['entity_count'] ?? 0) . "\n";
exit(0);
