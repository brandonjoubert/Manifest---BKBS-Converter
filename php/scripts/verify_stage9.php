<?php
declare(strict_types=1);

/**
 * Claim Ledger Stage 9 — PHP Host capability layer.
 * Exit 0 PASS, 1 FAIL.
 */

$here = __DIR__;
$bootstrap = null;
foreach ([dirname($here, 2) . '/php/src/bootstrap.php', dirname($here) . '/src/bootstrap.php'] as $cand) {
    if (is_file($cand)) {
        $bootstrap = $cand;
        break;
    }
}
$haveSqlite = extension_loaded('pdo_sqlite');
if ($bootstrap === null) {
    fwrite(STDERR, "PHP Stage 9: FAIL — cannot find src/bootstrap.php\n");
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

$root = dirname($here, 2);
$contractPath = $root . '/test-fixtures/stage9_capability_contract.json';
$contract = json_decode((string) file_get_contents($contractPath), true);
check('php.s9.contract', is_array($contract) && isset($contract['card_keys']));

$card = Bkbs\Capability::agentCard('Acme', 'https://host/sites/1/a2a');
check('php.s9.card_keys', array_keys($card) === $contract['card_keys'], implode(',', array_keys($card)));
check('php.s9.card_protocol', ($card['protocolVersion'] ?? '') === $contract['protocolVersion']);
check('php.s9.card_skill', ($card['skills'][0]['id'] ?? '') === $contract['skill_id']);
check('php.s9.card_not_knowledge', !array_key_exists('knowledge', $card));
check('php.s9.card_bearer', ($card['securitySchemes']['bearer']['scheme'] ?? '') === 'bearer');

$entity = [
    'id' => 'e1',
    'entity_type' => 'business_identity',
    'name' => 'Acme Phone',
    'description' => 'Public desk',
    'properties' => ['phone' => '555-0100'],
    'notes' => 'SECRET PENDING',
];
check('php.s9.search_secret', Bkbs\Capability::searchApproved([$entity], 'SECRET') === []);
$hit = Bkbs\Capability::searchApproved([$entity], '555-0100');
check('php.s9.search_phone', count($hit) === 1 && !array_key_exists('notes', $hit[0]));
check('php.s9.search_no_secret_field', !str_contains(json_encode($hit), 'SECRET'));

$off = Bkbs\Capability::enabled(['capability_enabled' => 0]);
$on = Bkbs\Capability::enabled(['capability_enabled' => 1]);
check('php.s9.flag_off', $off === false);
check('php.s9.flag_on', $on === true);

$askSecret = Bkbs\Capability::answerAsk([$entity], 'SECRET', 'https://acme.example');
check('php.s9.ask_secret', $askSecret['status'] === 200 && $askSecret['body']['results'] === []);
$askHit = Bkbs\Capability::answerAsk([$entity], '555-0100', 'https://acme.example');
check(
    'php.s9.ask_hit',
    $askHit['status'] === 200
        && ($askHit['body']['results'][0]['url'] ?? '') === 'https://acme.example'
        && !str_contains(json_encode($askHit['body']), 'SECRET')
);
$askMissing = Bkbs\Capability::answerAsk([$entity], null, 'https://acme.example');
check('php.s9.ask_required', $askMissing['status'] === 400);

$mcp = Bkbs\Capability::answerMcp([$entity], ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/list']);
$names = array_map(static fn($t) => $t['name'] ?? '', $mcp['body']['result']['tools'] ?? []);
check('php.s9.mcp_tools', $names === $contract['mcp_tools'], implode(',', $names));
$called = Bkbs\Capability::answerMcp([$entity], [
    'jsonrpc' => '2.0',
    'id' => 8,
    'method' => 'tools/call',
    'params' => ['name' => 'search_approved', 'arguments' => ['query' => 'SECRET']],
]);
$payload = json_decode($called['body']['result']['content'][0]['text'] ?? '{}', true);
check('php.s9.mcp_secret', ($payload['results'] ?? null) === []);

$a2a = Bkbs\Capability::answerA2a([$entity], [
    'jsonrpc' => '2.0',
    'id' => 9,
    'method' => 'message/send',
    'params' => ['message' => ['role' => 'user', 'messageId' => 'm1', 'parts' => [['kind' => 'text', 'text' => 'phone']]]],
]);
$state = $a2a['body']['result']['status']['state'] ?? '';
$text = $a2a['body']['result']['artifacts'][0]['parts'][0]['text'] ?? '';
$data = json_encode($a2a['body']['result']['artifacts'][0]['parts'][1]['data'] ?? []);
check('php.s9.a2a', $state === 'completed' && str_contains((string) $data, '555-0100') && !str_contains($text, 'SECRET'));

$agent = Bkbs\Exports\AgentJson::build(['name' => 'Acme', 'base_url' => 'https://acme.example'], []);
check('php.s9.agent_json', array_keys($agent) === ['name', 'url', 'knowledge'] && !isset($agent['protocol']));

$router = (string) file_get_contents($root . '/php/src/Router.php');
check('php.s9.routes', str_contains($router, 'agent-card.json') && str_contains($router, 'capabilityRoute') && str_contains($router, "kind !== 'card'"));
$publisher = (string) file_get_contents($root . '/php/src/Publisher.php');
check('php.s9.no_static_card', !str_contains($publisher, $contract['publish_must_not_contain']));

if (!$haveSqlite) {
    echo "SKIP  php.s9.sqlite  pdo_sqlite extension not available\n";
    echo $fail ? "Stage 9 PHP: FAIL ($fail)\n" : "Stage 9 PHP: PASS (sqlite checks skipped)\n";
    exit($fail ? 1 : 0);
}

$tmp = sys_get_temp_dir() . '/bkbs-s9-' . getmypid() . '.sqlite';
@unlink($tmp);
$db = new Bkbs\Database($tmp);
$pdo = $db->pdo();
$cols = $pdo->query('PRAGMA table_info(sites)')->fetchAll();
$names = array_map(static fn($r) => (string) $r['name'], $cols);
check('php.s9.column', in_array('capability_enabled', $names, true));
$now = gmdate('c');
$sid = '11111111-1111-4111-8111-111111111111';
$eid = '22222222-2222-4222-8222-222222222222';
$pdo->prepare('INSERT INTO sites(id,name,base_url,max_pages,crawl_delay_ms,created_at) VALUES(?,?,?,?,?,?)')
    ->execute([$sid, 'Acme', 'https://acme.example', 5, 0, $now]);
$row = $pdo->query("SELECT capability_enabled FROM sites WHERE id = '$sid'")->fetch();
check('php.s9.default_off', (int) ($row['capability_enabled'] ?? 1) === 0);
$pdo->prepare(
    'INSERT INTO entities(id,site_id,external_key,entity_type,name,description,properties,relationships,evidence,version,trust_level,source,status,notes,last_updated,created_at)
     VALUES(?,?,?,?,?,?,?,?,?,1,?,?,?,?,?,?)'
)->execute([$eid, $sid, 'k9', 'business_identity', '', null, '{}', '[]', '[]', 'medium', 'scan', 'approved', 'SECRET PENDING', $now, $now]);
Bkbs\Resolver::insertApprovedClaim($pdo, $eid, 'business_identity', 'name', '"Acme Phone"', 'manual', 'test');
Bkbs\Resolver::insertApprovedClaim($pdo, $eid, 'business_identity', 'description', '"Public desk"', 'manual', 'test');
Bkbs\Resolver::insertApprovedClaim($pdo, $eid, 'business_identity', 'prop:phone', '"555-0100"', 'manual', 'test');
$pdo->prepare(
    'INSERT INTO claims(entity_id, entity_type, attribute, value, extraction_method, status, created_at)
     VALUES(?,?,?,?,?,?,?)'
)->execute([$eid, 'business_identity', 'internal_note', '"SECRET PENDING"', 'scan', 'pending', $now]);
$public = Bkbs\Resolver::resolveSite($pdo, $sid, false);
$resolvedHit = Bkbs\Capability::searchApproved($public, 'SECRET');
$resolvedPhone = Bkbs\Capability::searchApproved($public, '555-0100');
check('php.s9.resolve_hides_pending', $resolvedHit === [] && count($resolvedPhone) === 1 && !str_contains(json_encode($resolvedPhone), 'SECRET'));
@unlink($tmp);

echo $fail ? "Stage 9 PHP: FAIL ($fail)\n" : "Stage 9 PHP: PASS\n";
exit($fail ? 1 : 0);
