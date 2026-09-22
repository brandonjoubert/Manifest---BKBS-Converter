<?php
declare(strict_types=1);

/**
 * Claim Ledger Stage 9 — WordPress capability layer without WP HTTP.
 * Exit 0 PASS, 1 FAIL.
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/mbkbs-abspath/');
}

$here = __DIR__;
$plugin = null;
foreach ([
    dirname($here, 2) . '/wordpress-plugin/manifest-bkbs-converter',
    dirname($here, 3) . '/wordpress-plugin/manifest-bkbs-converter',
] as $cand) {
    if (is_dir($cand)) {
        $plugin = $cand;
        break;
    }
}
if ($plugin === null) {
    fwrite(STDERR, "WP Stage 9: FAIL — cannot find wordpress-plugin/manifest-bkbs-converter\n");
    exit(1);
}

require $plugin . '/includes/class-mbkbs-capability.php';

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
$contract = json_decode((string) file_get_contents($root . '/test-fixtures/stage9_capability_contract.json'), true);
check('wp.s9.contract', is_array($contract));

$card = MBKBS_Capability::agentCard('Acme', 'https://host/wp-json/mbkbs/v1/a2a');
check('wp.s9.card_keys', array_keys($card) === $contract['card_keys']);
check('wp.s9.card_protocol', ($card['protocolVersion'] ?? '') === $contract['protocolVersion']);
check('wp.s9.card_skill', ($card['skills'][0]['id'] ?? '') === $contract['skill_id']);
check('wp.s9.card_not_knowledge', !array_key_exists('knowledge', $card));

$entity = [
    'id' => 'e1',
    'entity_type' => 'business_identity',
    'name' => 'Acme Phone',
    'description' => 'Public desk',
    'properties' => ['phone' => '555-0100'],
    'notes' => 'SECRET PENDING',
];
check('wp.s9.search_secret', MBKBS_Capability::searchApproved([$entity], 'SECRET') === []);
$hit = MBKBS_Capability::searchApproved([$entity], '555-0100');
check('wp.s9.search_phone', count($hit) === 1 && !str_contains(json_encode($hit), 'SECRET'));

$ask = MBKBS_Capability::answerAsk([$entity], 'SECRET', 'https://acme.example');
check('wp.s9.ask_secret', $ask['status'] === 200 && $ask['body']['results'] === []);
$askHit = MBKBS_Capability::answerAsk([$entity], 'phone', 'https://acme.example');
check('wp.s9.ask_hit', $askHit['status'] === 200 && !str_contains(json_encode($askHit['body']), 'SECRET'));

$mcp = MBKBS_Capability::answerMcp([$entity], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
$names = array_map(static fn($t) => $t['name'] ?? '', $mcp['body']['result']['tools'] ?? []);
check('wp.s9.mcp_tools', $names === $contract['mcp_tools']);

$a2a = MBKBS_Capability::answerA2a([$entity], [
    'jsonrpc' => '2.0',
    'id' => 2,
    'method' => 'message/send',
    'params' => ['message' => ['parts' => [['kind' => 'text', 'text' => 'SECRET']]]],
]);
$text = $a2a['body']['result']['artifacts'][0]['parts'][0]['text'] ?? '';
check('wp.s9.a2a_no_invent', ($a2a['body']['result']['status']['state'] ?? '') === 'completed' && str_contains($text, 'Nothing was invented'));

$src = (string) file_get_contents($plugin . '/includes/class-mbkbs-capability.php');
$pluginSrc = (string) file_get_contents($plugin . '/includes/class-mbkbs-plugin.php');
$main = (string) file_get_contents($plugin . '/manifest-bkbs-converter.php');
$admin = (string) file_get_contents($plugin . '/admin/class-mbkbs-admin.php');
$dash = (string) file_get_contents($plugin . '/admin/views/dashboard.php');
$publisher = (string) file_get_contents($plugin . '/includes/class-mbkbs-publisher.php');
$agent = (string) file_get_contents($plugin . '/includes/exports/class-mbkbs-export-agent.php');

check('wp.s9.default_off', str_contains($src, "capability.enabled', '0'"));
check('wp.s9.routes', str_contains($src, "'/agent-card'") && str_contains($src, "'/ask'") && str_contains($src, "'/mcp'") && str_contains($src, "'/a2a'"));
check('wp.s9.wired', str_contains($pluginSrc, 'MBKBS_Capability::class') && str_contains($main, 'class-mbkbs-capability.php'));
check('wp.s9.setting_saved', str_contains($admin, "capability.enabled"));
check('wp.s9.checkbox', str_contains($dash, 'capability_enabled'));
check('wp.s9.no_static_card', !str_contains($publisher, $contract['publish_must_not_contain']));
check('wp.s9.agent_json_untouched', !str_contains($agent, 'capability') && str_contains($agent, 'knowledge'));

echo $fail ? "Stage 9 WP: FAIL ($fail)\n" : "Stage 9 WP: PASS\n";
exit($fail ? 1 : 0);
