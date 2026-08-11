<?php
declare(strict_types=1);

/**
 * Claim Ledger Stage 2 — backfill approved claims from entity rows (PHP Host).
 *
 * Usage:
 *   php php/scripts/backfill_claims.php --db=/path/to/bkbs.sqlite
 *   php php/scripts/backfill_claims.php --db=... --site-id=<uuid>
 *   php php/scripts/backfill_claims.php --db=... --dry-run
 *   php php/scripts/backfill_claims.php --db=... --include-pending
 *   php php/scripts/backfill_claims.php --db=... --update
 *
 * Default: approved entities only; skip identical existing approved claims.
 */

// Packaged zip: bkbs-php/scripts → bkbs-php/src
// Monorepo: php/scripts → php/src (or repo/php/src)
$bootstrap = null;
foreach (
    [
        dirname(__DIR__) . '/src/bootstrap.php',
        dirname(__DIR__, 2) . '/php/src/bootstrap.php',
        dirname(__DIR__) . '/bootstrap.php',
    ] as $cand
) {
    if (is_file($cand)) {
        $bootstrap = $cand;
        break;
    }
}
if ($bootstrap === null) {
    fwrite(STDERR, "Cannot locate php/src/bootstrap.php from " . __DIR__ . "\n");
    exit(1);
}
require $bootstrap;

use Bkbs\Database;
use Bkbs\Resolver;

$opts = getopt('', ['db:', 'site-id:', 'all-sites', 'dry-run', 'include-pending', 'update', 'help']);
if (isset($opts['help']) || empty($opts['db'])) {
    fwrite(STDOUT, "Usage: php php/scripts/backfill_claims.php --db=PATH [--site-id=UUID] [--all-sites] [--dry-run] [--include-pending] [--update]\n");
    exit(isset($opts['help']) ? 0 : 1);
}

$dbPath = (string) $opts['db'];
$siteId = isset($opts['site-id']) ? (string) $opts['site-id'] : null;
$dryRun = isset($opts['dry-run']);
$includePending = isset($opts['include-pending']);
$update = isset($opts['update']);

$db = new Database($dbPath);
$pdo = $db->pdo();

$sql = 'SELECT * FROM entities';
$params = [];
$where = [];
if ($siteId !== null && $siteId !== '') {
    $where[] = 'site_id = ?';
    $params[] = $siteId;
}
if ($includePending) {
    $where[] = "status IN ('approved','pending','needs_edit')";
} else {
    $where[] = "status = 'approved'";
}
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY entity_type, name';

$st = $pdo->prepare($sql);
$st->execute($params);
$entities = $st->fetchAll(PDO::FETCH_ASSOC);

$totals = ['entities' => 0, 'inserted' => 0, 'skipped' => 0, 'superseded' => 0];

$findApproved = $pdo->prepare(
    'SELECT id, value FROM claims WHERE entity_id = ? AND attribute = ? AND status = ? ORDER BY id DESC LIMIT 1'
);
$insert = $pdo->prepare(
    'INSERT INTO claims(entity_id, entity_type, attribute, value, source_url, extraction_method, confidence, status, supersedes_id, created_at, approved_by, approved_at, review_due_at)
     VALUES(?,?,?,?,NULL,?,NULL,?,?,?,?,?,NULL)'
);
$supersede = $pdo->prepare('UPDATE claims SET status = ? WHERE id = ?');

if (!$dryRun) {
    $pdo->beginTransaction();
}

foreach ($entities as $ent) {
    $totals['entities']++;
    $entityId = (string) $ent['id'];
    $entityType = (string) ($ent['entity_type'] ?? 'unknown');
    $extraction = substr((string) ($ent['source'] ?? 'scan'), 0, 32);
    $approvedAt = (string) ($ent['last_updated'] ?? $ent['created_at'] ?? gmdate('c'));
    $pairs = Resolver::entityAttributePairs($ent);

    foreach ($pairs as [$attr, $value]) {
        $findApproved->execute([$entityId, $attr, 'approved']);
        $existing = $findApproved->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ((string) $existing['value'] === $value) {
                $totals['skipped']++;
                continue;
            }
            if (!$update) {
                $totals['skipped']++;
                continue;
            }
            if (!$dryRun) {
                $supersede->execute(['superseded', (int) $existing['id']]);
                $insert->execute([
                    $entityId,
                    $entityType,
                    $attr,
                    $value,
                    $extraction,
                    'approved',
                    (int) $existing['id'],
                    $approvedAt,
                    'backfill',
                    $approvedAt,
                ]);
            }
            $totals['superseded']++;
            $totals['inserted']++;
            continue;
        }
        if (!$dryRun) {
            $insert->execute([
                $entityId,
                $entityType,
                $attr,
                $value,
                $extraction,
                'approved',
                null,
                $approvedAt,
                'backfill',
                $approvedAt,
            ]);
        }
        $totals['inserted']++;
    }
}

if (!$dryRun) {
    $pdo->commit();
}

$mode = $dryRun ? 'DRY-RUN ' : '';
echo "{$mode}Stage 2 backfill (PHP): entities={$totals['entities']} inserted={$totals['inserted']} skipped={$totals['skipped']} superseded={$totals['superseded']}\n";
exit(0);
