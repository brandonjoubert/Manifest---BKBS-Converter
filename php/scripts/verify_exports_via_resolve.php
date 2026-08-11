<?php
declare(strict_types=1);

/**
 * Claim Ledger Stage 2 — verify PHP publisher output via resolve matches golden-v0-php.
 *
 * 1. Load Stage 0 fixture entities into temp SQLite
 * 2. Backfill claims
 * 3. Resolve each approved entity
 * 4. Publish via Publisher with resolved arrays
 * 5. Compare normalized files to golden
 *
 * Usage: php php/scripts/verify_exports_via_resolve.php
 * Exit 0 match, 1 mismatch.
 */

// Packaged zip: bkbs-php/scripts → bkbs-php/src; monorepo: php/scripts → php/src
$bootstrap = null;
$phpRoot = null;
foreach (
    [
        dirname(__DIR__) . '/src/bootstrap.php',
        dirname(__DIR__, 2) . '/php/src/bootstrap.php',
    ] as $cand
) {
    if (is_file($cand)) {
        $bootstrap = $cand;
        $phpRoot = dirname($cand, 2); // monorepo root or parent of src
        break;
    }
}
if ($bootstrap === null) {
    fwrite(STDERR, "Cannot locate bootstrap.php\n");
    exit(1);
}
require $bootstrap;

use Bkbs\Database;
use Bkbs\Publisher;
use Bkbs\Resolver;

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "PHP via-resolve: SKIP — pdo_sqlite extension not available\n");
    exit(0);
}

// Fixtures live at monorepo root (parent of php/)
$repoRoot = is_dir(dirname(__DIR__, 2) . '/test-fixtures')
    ? dirname(__DIR__, 2)
    : (is_dir(dirname(__DIR__, 3) . '/test-fixtures') ? dirname(__DIR__, 3) : dirname(__DIR__, 2));
$fixturePath = $repoRoot . '/test-fixtures/stage0_site.json';
$goldenDir = $repoRoot . '/test-fixtures/golden-v0-php';

if (!is_file($fixturePath)) {
    fwrite(STDERR, "Missing fixture\n");
    exit(1);
}
if (!is_dir($goldenDir)) {
    fwrite(STDERR, "Missing PHP golden dir\n");
    exit(1);
}

$fixture = json_decode((string) file_get_contents($fixturePath), true);
$tmp = sys_get_temp_dir() . '/bkbs-stage2-php-' . getmypid();
if (is_dir($tmp)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
}
mkdir($tmp, 0755, true);

$dbPath = $tmp . '/via-resolve.sqlite';
$db = new Database($dbPath);
$pdo = $db->pdo();

$site = $fixture['site'];
$now = gmdate('c');
$pdo->prepare(
    'INSERT INTO sites(id, name, base_url, max_pages, crawl_delay_ms, publish_root, auto_publish, created_at)
     VALUES(?,?,?,?,?,?,?,?)'
)->execute([
    $site['id'],
    $site['name'],
    $site['base_url'],
    $site['max_pages'] ?? 10,
    $site['crawl_delay_ms'] ?? 0,
    null,
    0,
    $now,
]);

$insEnt = $pdo->prepare(
    'INSERT INTO entities(id, site_id, external_key, entity_type, name, description, properties, relationships, evidence, version, trust_level, source, status, notes, last_updated, created_at)
     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
);

foreach ($fixture['entities'] as $e) {
    $insEnt->execute([
        $e['id'],
        $site['id'],
        $e['external_key'],
        $e['entity_type'],
        $e['name'],
        $e['description'] ?? null,
        json_encode($e['properties'] ?? new stdClass(), JSON_UNESCAPED_UNICODE),
        json_encode($e['relationships'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($e['evidence'] ?? [], JSON_UNESCAPED_UNICODE),
        $e['version'] ?? 1,
        $e['trust_level'] ?? 'medium',
        $e['source'] ?? 'fixture',
        $e['status'],
        $e['notes'] ?? null,
        $now,
        $now,
    ]);
}

// Inline backfill (approved only)
$entities = $pdo->query("SELECT * FROM entities WHERE status = 'approved' ORDER BY entity_type, name")
    ->fetchAll(PDO::FETCH_ASSOC);
$insert = $pdo->prepare(
    'INSERT INTO claims(entity_id, entity_type, attribute, value, source_url, extraction_method, confidence, status, supersedes_id, created_at, approved_by, approved_at, review_due_at)
     VALUES(?,?,?,?,NULL,?,NULL,?,NULL,?,?,?,NULL)'
);
$claimCount = 0;
foreach ($entities as $ent) {
    $pairs = Resolver::entityAttributePairs($ent);
    $extraction = substr((string) ($ent['source'] ?? 'fixture'), 0, 32);
    $ts = (string) ($ent['last_updated'] ?? $now);
    foreach ($pairs as [$attr, $value]) {
        $insert->execute([
            $ent['id'],
            $ent['entity_type'],
            $attr,
            $value,
            $extraction,
            'approved',
            $ts,
            'backfill',
            $ts,
        ]);
        $claimCount++;
    }
}
echo "Backfill claims: {$claimCount}\n";

$resolved = [];
$errors = [];
foreach ($entities as $ent) {
    $r = Resolver::resolveEntity((string) $ent['id'], null, $pdo);
    if ($r === null) {
        $errors[] = $ent['id'] . ': resolve null';
        continue;
    }
    // Require name claim at minimum
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM claims WHERE entity_id = ? AND attribute = 'name' AND status = 'approved'"
    );
    $st->execute([$ent['id']]);
    if ((int) $st->fetchColumn() < 1) {
        $errors[] = $ent['id'] . ': missing name claim';
    }
    // Publisher dumps full entity arrays into graph.json; strip hybrid-only keys
    // so compare matches Stage 0 fixture shape (normalizer already drops last_updated).
    unset($r['site_id']);
    $resolved[] = $r;
}

if ($errors) {
    fwrite(STDERR, "Claim/resolve gate failed:\n");
    foreach ($errors as $err) {
        fwrite(STDERR, "  - {$err}\n");
    }
    exit(1);
}

// Sort like publisher consumers expect
usort(
    $resolved,
    static fn($a, $b) => [$a['entity_type'], $a['name']] <=> [$b['entity_type'], $b['name']]
);

$publishRoot = $tmp . '/out';
mkdir($publishRoot, 0755, true);
$publisher = new Publisher();
$result = $publisher->publish($site, $resolved, $publishRoot);
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
    return preg_replace(
        '/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})?/',
        '<TIMESTAMP>',
        $text
    ) ?? $text;
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
        if ($out !== [] && array_keys($out) !== range(0, count($out) - 1)) {
            ksort($out);
        }
        return $out;
    }
    return $obj;
}

$diffs = [];
foreach ($files as $rel) {
    $g = $goldenDir . '/' . $rel;
    $c = $publishRoot . '/' . $rel;
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

if ($diffs) {
    echo "PHP via-resolve: FAIL — differences in:\n";
    foreach ($diffs as $d) {
        echo "  - {$d}\n";
    }
    exit(1);
}

echo "PHP via-resolve: OK — publisher output matches golden (normalized)\n";
exit(0);
