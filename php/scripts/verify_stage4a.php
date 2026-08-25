<?php
declare(strict_types=1);

/**
 * Claim Ledger Stage 4a — PHP publication rule (T1–T4, T7).
 *
 * Usage: php php/scripts/verify_stage4a.php
 * Exit 0 on PASS, 1 on FAIL. Skips if pdo_sqlite is unavailable.
 */

$bootstrap = null;
foreach (
    [
        dirname(__DIR__) . '/src/bootstrap.php',
        dirname(__DIR__, 2) . '/php/src/bootstrap.php',
    ] as $cand
) {
    if (is_file($cand)) {
        $bootstrap = $cand;
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
    fwrite(STDOUT, "PHP Stage 4a: SKIP — pdo_sqlite extension not available\n");
    exit(0);
}

function fail(string $msg): never
{
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
}

function ok(string $msg): void
{
    fwrite(STDOUT, "OK: {$msg}\n");
}

function insertSite(PDO $pdo, string $id, string $name): void
{
    $pdo->prepare(
        'INSERT INTO sites(id, name, base_url, max_pages, crawl_delay_ms, publish_root, auto_publish, created_at)
         VALUES(?,?,?,?,?,?,?,?)'
    )->execute([$id, $name, 'https://s4.example', 5, 0, null, 0, gmdate('c')]);
}

function insertEntity(PDO $pdo, array $e): void
{
    $now = gmdate('c');
    $pdo->prepare(
        'INSERT INTO entities(id, site_id, external_key, entity_type, name, description, properties, relationships, evidence, version, trust_level, source, status, notes, last_updated, created_at)
         VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([
        $e['id'],
        $e['site_id'],
        $e['external_key'],
        $e['entity_type'],
        $e['name'],
        $e['description'] ?? null,
        json_encode($e['properties'] ?? new stdClass()),
        json_encode($e['relationships'] ?? []),
        json_encode($e['evidence'] ?? []),
        1,
        $e['trust_level'] ?? 'medium',
        $e['source'] ?? 'scan',
        $e['status'] ?? 'pending',
        null,
        $now,
        $now,
    ]);
}

function loadEntity(PDO $pdo, string $id): array
{
    $st = $pdo->prepare('SELECT * FROM entities WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fail("entity {$id} missing");
    }
    return $row;
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}

$tmp = sys_get_temp_dir() . '/bkbs-stage4a-php-' . getmypid();
rrmdir($tmp);
mkdir($tmp, 0755, true);
$db = new Database($tmp . '/s4.sqlite');
$pdo = $db->pdo();

$site = ['id' => 'site-s4', 'name' => 'Stage4 Co', 'base_url' => 'https://s4.example'];
insertSite($pdo, 'site-s4', 'Stage4 Co');

// T1 pending-only
insertEntity($pdo, [
    'id' => 'ent-pending',
    'site_id' => 'site-s4',
    'external_key' => 'pending-key',
    'entity_type' => 'capability',
    'name' => 'SECRET_PENDING_CAPABILITY',
    'description' => 'should not leak',
    'status' => 'pending',
]);
$ent = loadEntity($pdo, 'ent-pending');
Resolver::seedPendingClaimsForNewEntity($pdo, $ent);
$origin1 = $tmp . '/origin-t1';
mkdir($origin1, 0755, true);
$pub = new Publisher();
$result = $pub->publish($site, Resolver::resolveSite($pdo, 'site-s4', false), $origin1);
if (empty($result['ok'])) {
    fail('T1 publish failed: ' . ($result['error'] ?? ''));
}
$llms = (string) file_get_contents($origin1 . '/llms.txt');
if (str_contains($llms, 'SECRET_PENDING_CAPABILITY')) {
    fail('T1 pending name leaked into live llms.txt');
}
ok('T1 pending-only excluded from live files');

// T2 + T4: approve then pending description / needs_edit
insertEntity($pdo, [
    'id' => 'ent-cap',
    'site_id' => 'site-s4',
    'external_key' => 'cap-key',
    'entity_type' => 'capability',
    'name' => 'Install CCTV',
    'description' => 'OLD_APPROVED_DESC',
    'status' => 'pending',
]);
$ent = loadEntity($pdo, 'ent-cap');
Resolver::seedPendingClaimsForNewEntity($pdo, $ent);
Resolver::applyHumanDecision($pdo, $ent, 'approve', 'test');
$st = $pdo->prepare("SELECT COUNT(*) FROM claims WHERE entity_id = ? AND status = 'approved'");
$st->execute(['ent-cap']);
if ((int) $st->fetchColumn() < 1) {
    fail('T3 approve did not write approved claims');
}
ok('T3 approve promotes/writes approved claims');

Resolver::insertPendingClaim($pdo, 'ent-cap', 'capability', 'description', 'NEW_PENDING_LEAK', 'scan');
$pdo->prepare('UPDATE entities SET status = ? WHERE id = ?')->execute(['needs_edit', 'ent-cap']);
$resolved = Resolver::resolveSite($pdo, 'site-s4', false);
$names = array_column($resolved, 'name');
if (!in_array('Install CCTV', $names, true)) {
    fail('T4 needs_edit entity missing from public set');
}
$desc = null;
foreach ($resolved as $r) {
    if (($r['name'] ?? '') === 'Install CCTV') {
        $desc = (string) ($r['description'] ?? '');
    }
}
if ($desc !== 'OLD_APPROVED_DESC') {
    fail("T2/T4 public description should stay OLD_APPROVED_DESC, got {$desc}");
}
if (str_contains($desc, 'NEW_PENDING_LEAK')) {
    fail('T2 pending description leaked into resolve');
}
$origin2 = $tmp . '/origin-t2';
mkdir($origin2, 0755, true);
$result = $pub->publish($site, $resolved, $origin2);
if (empty($result['ok'])) {
    fail('T2 publish failed');
}
$llms2 = (string) file_get_contents($origin2 . '/llms.txt');
if (!str_contains($llms2, 'Install CCTV')) {
    fail('T4 live files dropped needs_edit entity');
}
if (str_contains($llms2, 'NEW_PENDING_LEAK')) {
    fail('T2 pending description leaked into live files');
}
ok('T2 pending overlay ignored; T4 needs_edit keeps last approved');

// T7 draft resolve vs origin
$draft = Resolver::resolveSite($pdo, 'site-s4', true);
$draftNames = array_column($draft, 'name');
if (!in_array('SECRET_PENDING_CAPABILITY', $draftNames, true)) {
    fail('T7 draft resolve should include pending');
}
$origin3 = $tmp . '/origin-t7';
mkdir($origin3, 0755, true);
file_put_contents($origin3 . '/sentinel.txt', 'keep');
$result = $pub->publish($site, Resolver::resolveSite($pdo, 'site-s4', false), $origin3);
if (empty($result['ok'])) {
    fail('T7 origin publish failed');
}
$llms3 = (string) file_get_contents($origin3 . '/llms.txt');
if (str_contains($llms3, 'SECRET_PENDING_CAPABILITY')) {
    fail('T7 origin received pending name');
}
if ((string) file_get_contents($origin3 . '/sentinel.txt') !== 'keep') {
    fail('T7 origin sentinel lost');
}
ok('T7 draft pending is not written to origin');

// Reject excluded
insertEntity($pdo, [
    'id' => 'ent-rej',
    'site_id' => 'site-s4',
    'external_key' => 'rej-key',
    'entity_type' => 'capability',
    'name' => 'NOPE',
    'status' => 'pending',
]);
$ent = loadEntity($pdo, 'ent-rej');
Resolver::seedPendingClaimsForNewEntity($pdo, $ent);
Resolver::applyHumanDecision($pdo, $ent, 'reject', 'test');
foreach (Resolver::resolveSite($pdo, 'site-s4', false) as $r) {
    if (($r['name'] ?? '') === 'NOPE') {
        fail('rejected entity in public set');
    }
}
ok('rejected envelope excluded from public set');

foreach (Resolver::entityAttributePairs(['name' => 'N', 'status' => 'approved', 'trust_level' => 'high', 'source' => 'x']) as [$attr]) {
    if ($attr === 'status') {
        fail('entityAttributePairs still emits status');
    }
}
ok('backfill pairs omit attribute=status');

rrmdir($tmp);
fwrite(STDOUT, "PHP Stage 4a: PASS\n");
exit(0);
