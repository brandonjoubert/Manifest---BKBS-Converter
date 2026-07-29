<?php
declare(strict_types=1);

/**
 * Claim Ledger Stage 0 — verify PHP publisher matches golden-v0-php.
 *
 * Usage: php php/scripts/verify_exports.php
 * Exit 0 match, 1 mismatch.
 */

$root = dirname(__DIR__, 2);
require $root . '/php/src/bootstrap.php';

$fixturePath = $root . '/test-fixtures/stage0_site.json';
$goldenDir = $root . '/test-fixtures/golden-v0-php';

if (!is_file($fixturePath)) {
    fwrite(STDERR, "Missing fixture\n");
    exit(1);
}
if (!is_dir($goldenDir)) {
    fwrite(STDERR, "Missing PHP golden dir. Run: php php/scripts/capture_golden.php\n");
    exit(1);
}

$fixture = json_decode((string) file_get_contents($fixturePath), true);
$tmp = sys_get_temp_dir() . '/bkbs-stage0-php-' . getmypid();
if (is_dir($tmp)) {
    // clean
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
}
mkdir($tmp, 0755, true);

$publisher = new Bkbs\Publisher();
$result = $publisher->publish($fixture['site'], $fixture['entities'], $tmp);
if (empty($result['ok'])) {
    fwrite(STDERR, 'Publish failed: ' . ($result['error'] ?? '') . "\n");
    exit(1);
}

$files = [
    'llms.txt',
    'llms-full.txt',
    'graph.json',
    'schema/organization.jsonld',
    'schema/services.jsonld',
    '.well-known/agent.json',
];

function normalize_content(string $name, string $text): string
{
    $text = str_replace("\r\n", "\n", $text);
    if (str_ends_with($name, '.json') || str_ends_with($name, '.jsonld')) {
        $data = json_decode($text, true);
        if (is_array($data)) {
            $data = strip_volatile($data);
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        }
    }
    return preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})?/', '<TIMESTAMP>', $text) ?? $text;
}

function strip_volatile(mixed $obj): mixed
{
    if (is_array($obj)) {
        $out = [];
        foreach ($obj as $k => $v) {
            if (in_array($k, ['generated_at', 'last_updated', 'created_at'], true)) {
                continue;
            }
            $out[$k] = strip_volatile($v);
        }
        // sort keys for objects (associative)
        if (array_keys($out) !== range(0, count($out) - 1)) {
            ksort($out);
        }
        return $out;
    }
    return $obj;
}

$diffs = [];
foreach ($files as $rel) {
    $g = $goldenDir . '/' . $rel;
    $c = $tmp . '/' . $rel;
    if (!is_file($g) || !is_file($c)) {
        $diffs[] = "$rel (missing golden or current)";
        continue;
    }
    $gn = normalize_content($rel, (string) file_get_contents($g));
    $cn = normalize_content($rel, (string) file_get_contents($c));
    if ($gn !== $cn) {
        $diffs[] = $rel;
    }
}

// cleanup tmp
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
}
@rmdir($tmp);

if ($diffs) {
    echo "PHP mismatches:\n";
    foreach ($diffs as $d) {
        echo "  - $d\n";
    }
    exit(1);
}
echo "PHP publisher matches golden-v0-php (normalized)\n";
exit(0);
