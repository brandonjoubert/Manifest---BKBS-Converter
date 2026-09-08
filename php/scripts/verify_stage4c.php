<?php
declare(strict_types=1);

/**
 * Claim Ledger Stage 4c — PHP: honest agent.json, snippet escape, AIPREF off, robots END.
 * Exit 0 PASS, 1 FAIL.
 */

$root = dirname(__DIR__, 2);
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

$site = [
    'name' => 'Acme',
    'base_url' => 'https://acme.example',
    'aipref_search' => 0,
    'aipref_ai_input' => 0,
    'aipref_train_ai' => 0,
];
$entities = [
    [
        'entity_type' => 'business_identity',
        'name' => 'Acme',
        'description' => 'Cameras </script><script>alert(1)</script>',
    ],
];
$safe = Bkbs\Exports\JsonLdSnippet::scriptSafeJson(['x' => '</script><script>alert(1)</script>']);
check('php.s4c.snippet.json', !str_contains($safe, '<') && str_contains($safe, '\u003c'));

$agent = Bkbs\Exports\AgentJson::build($site, $entities);
check('php.s4c.agent.keys', isset($agent['name'], $agent['url'], $agent['knowledge']));
check('php.s4c.agent.no_protocol', !isset($agent['protocol']));
check('php.s4c.agent.no_endpoint', !isset($agent['endpoint']));
check('php.s4c.agent.no_capabilities', !isset($agent['capabilities']));
$k = $agent['knowledge'] ?? [];
check(
    'php.s4c.agent.knowledge',
    isset($k['llms_txt'], $k['llms_full'], $k['graph'], $k['schema_organization'], $k['schema_services'])
);

$snippet = Bkbs\Exports\JsonLdSnippet::organization($site, $entities);
check('php.s4c.snippet.tag', str_starts_with($snippet, '<script type="application/ld+json">'));
$innerStart = strlen('<script type="application/ld+json">');
$innerEnd = strrpos($snippet, '</script>');
$inner = $innerEnd === false ? $snippet : substr($snippet, $innerStart, $innerEnd - $innerStart);
check('php.s4c.snippet.escape', !str_contains($inner, '</script>') && str_contains($inner, '\u003c'));

$block = Bkbs\Exports\Robots::block($site);
check('php.s4c.aipref.off', !str_contains($block, 'Content-Usage:'));
check('php.s4c.robots.end', str_contains($block, '# END BKBS'));
$siteOn = $site;
$siteOn['aipref_search'] = 1;
$on = Bkbs\Exports\Robots::block($siteOn);
check('php.s4c.aipref.on', str_contains($on, 'Content-Usage: search=y, ai-input=n, train-ai=n'));

$tmp = sys_get_temp_dir() . '/bkbs-s4c-robots-' . getmypid();
@mkdir($tmp, 0755, true);
file_put_contents($tmp . '/robots.txt', "# BEGIN BKBS\nUser-agent: *\nAllow: /old\n");
Bkbs\Exports\Robots::merge($tmp, $site);
$merged = (string) file_get_contents($tmp . '/robots.txt');
check('php.s4c.robots.no_dup', substr_count($merged, '# BEGIN BKBS') === 1 && substr_count($merged, '# END BKBS') === 1);
check('php.s4c.robots.replaced', str_contains($merged, 'Allow: /llms.txt') && !str_contains($merged, 'Allow: /old'));

echo $fail ? "Stage 4c PHP: FAIL ($fail)\n" : "Stage 4c PHP: PASS\n";
exit($fail ? 1 : 0);
