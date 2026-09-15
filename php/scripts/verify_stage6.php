<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "PHP Stage 6: SKIP — pdo_sqlite extension not available\n");
    exit(0);
}
require $root . '/php/src/bootstrap.php';

$fail = 0;
function check(string $id, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? 'PASS' : 'FAIL') . "  $id  $detail\n";
    if (!$ok) {
        $fail++;
    }
}

$tmp = sys_get_temp_dir() . '/bkbs-s6-' . getmypid() . '.sqlite';
@unlink($tmp);
$db = new Bkbs\Database($tmp);
$pdo = $db->pdo();
$now = gmdate('c');
$sid = '11111111-1111-4111-8111-111111111111';
$pdo->prepare('INSERT INTO sites(id,name,base_url,max_pages,crawl_delay_ms,created_at) VALUES(?,?,?,?,?,?)')
    ->execute([$sid, 'S6', 'https://s6.example', 5, 0, $now]);

$item = [
    'entity_type' => 'capability',
    'name' => 'S6 Cap',
    'description' => 'From scan',
    'properties' => [],
    'relationships' => [],
    'evidence' => [],
    'source' => 'scan',
    'trust_level' => 'medium',
];
$eid = '22222222-2222-4222-8222-222222222222';
$pdo->prepare(
    'INSERT INTO entities(id,site_id,external_key,entity_type,name,description,properties,relationships,evidence,version,trust_level,source,status,last_updated,created_at)
     VALUES(?,?,?,?,?,?,?,?,?,1,?,?,?,?,?)'
)->execute([$eid, $sid, 'k6', 'capability', '', null, '{}', '[]', '[]', 'medium', 'scan', 'pending', $now, $now]);
Bkbs\Resolver::seedPendingClaimsForNewEntity($pdo, ['id' => $eid, 'entity_type' => 'capability', 'source' => 'scan'], $item);

$row = $pdo->query("SELECT name, description FROM entities WHERE id = '$eid'")->fetch();
check('php.s6.envelope_empty', ($row['name'] ?? '') === '' && ($row['description'] ?? null) === null);

$op = Bkbs\Resolver::resolveSite($pdo, $sid, true);
check('php.s6.pending_overlay', isset($op[0]['name']) && $op[0]['name'] === 'S6 Cap');

$pdo->prepare('UPDATE entities SET status=? WHERE id=?')->execute(['approved', $eid]);
$pdo->exec("UPDATE claims SET status='approved' WHERE entity_id='$eid'");
$pdo->prepare('UPDATE entities SET name=?, description=? WHERE id=?')->execute(['', null, $eid]);
$pub = Bkbs\Resolver::resolveSite($pdo, $sid, false);
check('php.s6.public_from_claims', ($pub[0]['name'] ?? '') === 'S6 Cap' && ($pub[0]['description'] ?? '') === 'From scan');

$nClaims = (int) $pdo->query('SELECT COUNT(*) FROM claims')->fetchColumn();
$ids = $pdo->query("SELECT id FROM entities WHERE site_id='$sid'")->fetchAll();
$eids = array_map(static fn($r) => $r['id'], $ids);
$ph = implode(',', array_fill(0, count($eids), '?'));
$pdo->prepare("DELETE FROM claims WHERE entity_id IN ($ph)")->execute($eids);
$pdo->prepare('DELETE FROM sites WHERE id=?')->execute([$sid]);
$left = (int) $pdo->query('SELECT COUNT(*) FROM claims')->fetchColumn();
check('php.s6.delete_claims', $nClaims >= 1 && $left === 0);

echo $fail ? "Stage 6 PHP: FAIL ($fail)\n" : "Stage 6 PHP: PASS\n";
exit($fail ? 1 : 0);
