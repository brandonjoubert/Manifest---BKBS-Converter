<?php
declare(strict_types=1);

$here = __DIR__;
$bootstrap = null;
foreach ([dirname($here, 2) . '/php/src/bootstrap.php', dirname($here) . '/src/bootstrap.php'] as $cand) {
    if (is_file($cand)) {
        $bootstrap = $cand;
        break;
    }
}
if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDOUT, "PHP Stage 7: SKIP — pdo_sqlite extension not available\n");
    exit(0);
}
if ($bootstrap === null) {
    fwrite(STDERR, "PHP Stage 7: FAIL — cannot find src/bootstrap.php\n");
    exit(1);
}
require $bootstrap;

$fail = 0;
function check(string $id, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? 'PASS' : 'FAIL') . "  $id  $detail\n";
    if (!$ok) {
        $fail++;
    }
}

$tmp = sys_get_temp_dir() . '/bkbs-s7-' . getmypid() . '.sqlite';
@unlink($tmp);
$db = new Bkbs\Database($tmp);
$pdo = $db->pdo();
$now = gmdate('c');
$sid = '11111111-1111-4111-8111-111111111111';
$eid = '22222222-2222-4222-8222-222222222222';
$pdo->prepare('INSERT INTO sites(id,name,base_url,max_pages,crawl_delay_ms,created_at) VALUES(?,?,?,?,?,?)')
    ->execute([$sid, 'S7', 'https://s7.example', 5, 0, $now]);
$pdo->prepare(
    'INSERT INTO entities(id,site_id,external_key,entity_type,name,description,properties,relationships,evidence,version,trust_level,source,status,last_updated,created_at)
     VALUES(?,?,?,?,?,?,?,?,?,1,?,?,?,?,?)'
)->execute([$eid, $sid, 'k7', 'business_identity', '', null, '{}', '[]', '[]', 'medium', 'scan', 'approved', $now, $now]);

$t1 = '2026-01-01T12:00:00+00:00';
$t2 = '2026-06-01T12:00:00+00:00';
Bkbs\Resolver::insertApprovedClaim($pdo, $eid, 'business_identity', 'name', '"S7 Co"', 'manual', 'test');
Bkbs\Resolver::insertApprovedClaim($pdo, $eid, 'business_identity', 'description', '"First approved"', 'manual', 'test');
$pdo->prepare("UPDATE claims SET approved_at = ?, created_at = ? WHERE entity_id = ?")->execute([$t1, $t1, $eid]);
Bkbs\Resolver::insertApprovedClaim($pdo, $eid, 'business_identity', 'description', '"Second approved"', 'manual', 'test');
$pdo->prepare("UPDATE claims SET approved_at = ?, created_at = ? WHERE entity_id = ? AND value LIKE '%Second%'")->execute([$t2, $t2, $eid]);

$row = $pdo->query("SELECT COUNT(*) c FROM claims WHERE entity_id='$eid' AND status='approved' AND approved_at IS NULL")->fetch();
check('php.s7.approve_sets_approved_at', (int) ($row['c'] ?? 1) === 0);

$early = Bkbs\Resolver::resolveEntity($eid, '2026-02-01T00:00:00+00:00', $pdo);
$late = Bkbs\Resolver::resolveEntity($eid, '2026-07-01T00:00:00+00:00', $pdo);
check('php.s7.as_of_early', is_array($early) && ($early['description'] ?? '') === 'First approved', json_encode($early['description'] ?? null));
check('php.s7.as_of_late', is_array($late) && ($late['description'] ?? '') === 'Second approved', json_encode($late['description'] ?? null));

$hist = Bkbs\Resolver::listClaims($pdo, $eid, '2026-02-01T00:00:00+00:00');
$secondInHist = false;
foreach ($hist as $c) {
    if (str_contains((string) ($c['value'] ?? ''), 'Second')) {
        $secondInHist = true;
    }
}
check('php.s7.claims_as_of', $hist !== [] && !$secondInHist, 'n=' . count($hist));

$all = Bkbs\Resolver::listClaims($pdo, $eid);
check('php.s7.claims_all', count($all) >= 2, 'n=' . count($all));

check('php.s7.helpers_present', function_exists('bkbs_require_api_auth') && function_exists('bkbs_api_token'));

@unlink($tmp);
echo $fail ? "Stage 7 PHP: FAIL ($fail)\n" : "Stage 7 PHP: PASS\n";
exit($fail ? 1 : 0);
