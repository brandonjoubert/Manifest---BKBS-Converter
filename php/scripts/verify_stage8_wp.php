<?php
declare(strict_types=1);

/**
 * Claim Ledger Stage 8 — WP: extract-before-strip (T31) + catalog without WP HTTP.
 * Defines ABSPATH and loads crawler + scan-audit only.
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
    fwrite(STDERR, "WP Stage 8: FAIL — cannot find wordpress-plugin/manifest-bkbs-converter\n");
    exit(1);
}

require $plugin . '/includes/class-mbkbs-crawler.php';
require $plugin . '/includes/class-mbkbs-scan-audit.php';

$fail = 0;
function check(string $id, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? 'PASS' : 'FAIL') . "  $id  $detail\n";
    if (!$ok) {
        $fail++;
    }
}

function s8_probe(string $url, ?int $status, ?string $body = '', ?string $error = null): array
{
    return ['url' => $url, 'status' => $status, 'body' => $body, 'error' => $error];
}

function s8_inp(array $over = []): array
{
    return array_merge([
        'base_url' => 'https://ex.com',
        'site_id' => 'site-1',
        'pages_json_ld' => [],
        'html_ok_count' => 0,
        'robots' => null,
        'llms_txt' => null,
        'agent_json' => null,
        'tdmrep' => null,
        'wp_jsonld_inject' => false,
    ], $over);
}

/** @param list<array<string,mixed>> $findings */
function s8_by_id(array $findings): array
{
    $ids = array_map(static fn($f) => $f['id'] ?? '', $findings);
    if ($ids !== MBKBS_Scan_Audit::FINDING_IDS) {
        check('wp.s8.catalog.order', false, json_encode($ids));
    }
    $out = [];
    foreach ($findings as $f) {
        $out[(string) $f['id']] = $f;
    }
    return $out;
}

$auditSrc = file_get_contents($plugin . '/includes/class-mbkbs-scan-audit.php') ?: '';
$crawlerSrc = file_get_contents($plugin . '/includes/class-mbkbs-crawler.php') ?: '';
$adminSrc = file_get_contents($plugin . '/admin/class-mbkbs-admin.php') ?: '';
$dashSrc = file_get_contents($plugin . '/admin/views/dashboard.php') ?: '';
$scanSrc = file_get_contents($plugin . '/admin/views/scan.php') ?: '';
$pluginSrc = file_get_contents($plugin . '/manifest-bkbs-converter.php') ?: '';
$dbSrc = file_get_contents($plugin . '/includes/class-mbkbs-database.php') ?: '';

check('wp.s8.ids', MBKBS_Scan_Audit::FINDING_IDS === ['jsonld-on-page', 'aipref-robots', 'llms-txt', 'agent-json', 'tdmrep']);
check(
    'wp.s8.catalog.order',
    array_column(MBKBS_Scan_Audit::evaluate_findings(s8_inp(['html_ok_count' => 1, 'pages_json_ld' => [['https://ex.com/', []]]])), 'id') === MBKBS_Scan_Audit::FINDING_IDS
);
check('wp.s8.no_merge_robots', !str_contains($auditSrc, 'merge_robots'));
check('wp.s8.no_publisher', !str_contains($auditSrc, 'Publisher'));
check('wp.s8.no_wp_inject_writer', !str_contains($auditSrc, 'maybe_print_jsonld'));
check('wp.s8.db_version_5', str_contains($pluginSrc, "MBKBS_DB_VERSION', '5'"));
check('wp.s8.dbdelta_two_spaces', str_contains($dbSrc, 'PRIMARY KEY  (id)') && str_contains($dbSrc, 'mbkbs_scan_jobs'));

// T25 jsonld-on-page
$passF = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'pages_json_ld' => [['https://ex.com/', [['@type' => 'Organization', 'name' => 'Acme']]]],
    'html_ok_count' => 1,
])))['jsonld-on-page'];
check('wp.s8.t25.pass', $passF['status'] === 'pass' && $passF['severity'] === 'high' && $passF['cta'] === '' && $passF['cta_href'] === '');
check('wp.s8.t25.pass.type', str_contains((string) $passF['evidence'], 'Organization'));

$failF = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'pages_json_ld' => [['https://ex.com/', []]],
    'html_ok_count' => 1,
    'wp_jsonld_inject' => false,
])))['jsonld-on-page'];
check('wp.s8.t25.fail', $failF['status'] === 'fail' && $failF['severity'] === 'high' && $failF['cta'] === 'Enable WP homepage inject');
check(
    'wp.s8.t25.fail.href',
    str_contains((string) $failF['cta_href'], 'admin.php?page=mbkbs')
    && str_contains((string) $failF['cta_href'], '#jsonld_wp_head')
    && !str_contains((string) $failF['cta_href'], '#machine-layers')
);

$failOn = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'pages_json_ld' => [['https://ex.com/', []]],
    'html_ok_count' => 1,
    'wp_jsonld_inject' => true,
])))['jsonld-on-page'];
check('wp.s8.t25.fail.inject_on', $failOn['cta'] === 'Copy JSON-LD snippet' && str_contains((string) $failOn['cta_href'], '#machine-layers'));

$unk = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp(['html_ok_count' => 0, 'pages_json_ld' => []])))['jsonld-on-page'];
check('wp.s8.t25.unknown', $unk['status'] === 'unknown' && $unk['severity'] === 'high' && str_contains((string) $unk['evidence'], '0 HTML pages fetched'));

// T26 aipref detect-only
$body = "User-agent: *\nContent-Usage: search=y, ai-input=n\n";
$aPass = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'robots' => s8_probe('https://ex.com/robots.txt', 200, $body),
])))['aipref-robots'];
check('wp.s8.t26.pass', $aPass['status'] === 'pass' && $aPass['severity'] === 'info' && str_contains((string) $aPass['evidence'], 'Content-Usage'));
check('wp.s8.t26.pass.href', str_contains((string) $aPass['cta_href'], '#machine-layers'));

$aFail = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'robots' => s8_probe('https://ex.com/robots.txt', 200, "User-agent: *\nAllow: /\n"),
])))['aipref-robots'];
check('wp.s8.t26.fail_info', $aFail['status'] === 'fail' && $aFail['severity'] === 'info' && $aFail['cta'] === 'Leave AIPREF off unless intended');

$aUnk = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp(['robots' => null])))['aipref-robots'];
check('wp.s8.t26.unknown', $aUnk['status'] === 'unknown' && $aUnk['severity'] === 'info');

// T27 llms-txt ordered eval (no bare contains llms)
$llmsUrl = 'https://ex.com/llms.txt';
$lUnk = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, null, null, 'ConnectTimeout'),
])))['llms-txt'];
check('wp.s8.t27.transport', $lUnk['status'] === 'unknown' && $lUnk['severity'] === 'high');

$lMiss = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, 404, ''),
])))['llms-txt'];
check('wp.s8.t27.missing', $lMiss['status'] === 'fail' && $lMiss['cta'] === 'Publish live');
check(
    'wp.s8.t27.publish_href',
    str_contains((string) $lMiss['cta_href'], 'admin.php?page=mbkbs')
    && !str_contains((string) $lMiss['cta_href'], '#')
);

$lEmpty = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, 200, ''),
])))['llms-txt'];
check('wp.s8.t27.empty', $lEmpty['status'] === 'fail');

$lHash = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, 200, "# Title\nHello\n"),
])))['llms-txt'];
check('wp.s8.t27.hash', $lHash['status'] === 'pass');

$lMark = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, 200, "Hello\n" . MBKBS_Scan_Audit::LLMS_MARKER . "\n"),
])))['llms-txt'];
check('wp.s8.t27.marker', $lMark['status'] === 'pass');

$htmlLlms = '<!DOCTYPE html><html><body>See our llms.txt guide in the footer</body></html>';
$lHtml = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, 200, $htmlLlms),
])))['llms-txt'];
check('wp.s8.t27.html_llms', $lHtml['status'] === 'fail' && $lHtml['severity'] === 'high');

$lOther = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, 200, 'plain text without a hash'),
])))['llms-txt'];
check('wp.s8.t27.other', $lOther['status'] === 'fail');

// T28 agent-json stub → high, 404 → medium
$pages = [['https://ex.com/', [['@type' => 'Organization', 'name' => 'Acme']]]];
$llmsOk = s8_probe('https://ex.com/llms.txt', 200, "# Title\n");
$agentUrl = 'https://ex.com/.well-known/agent.json';
$honest = json_encode(['name' => 'Acme', 'url' => 'https://ex.com', 'knowledge' => new stdClass()], JSON_UNESCAPED_SLASHES);
$stub = json_encode([
    'name' => 'Acme',
    'url' => 'https://ex.com',
    'knowledge' => new stdClass(),
    'protocol' => 'agent-web-protocol-stub',
    'endpoint' => 'https://ex.com/a2a',
], JSON_UNESCAPED_SLASHES);

$passAll = MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'pages_json_ld' => $pages,
    'html_ok_count' => 1,
    'llms_txt' => $llmsOk,
    'agent_json' => s8_probe($agentUrl, 200, (string) $honest),
]));
$agentPass = s8_by_id($passAll)['agent-json'];
check('wp.s8.t28.honest', $agentPass['status'] === 'pass' && $agentPass['severity'] === 'info');

$missingAll = MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'pages_json_ld' => $pages,
    'html_ok_count' => 1,
    'llms_txt' => $llmsOk,
    'agent_json' => s8_probe($agentUrl, 404, ''),
]));
$agent404 = s8_by_id($missingAll)['agent-json'];
check('wp.s8.t28.missing_medium', $agent404['status'] === 'fail' && $agent404['severity'] === 'medium');
check('wp.s8.t28.no_high_404', !MBKBS_Scan_Audit::has_high_severity_fail($missingAll) && !MBKBS_Scan_Audit::has_high_severity_fail([$agent404]));
check(
    'wp.s8.t28.missing.href',
    str_contains((string) $agent404['cta_href'], 'admin.php?page=mbkbs')
    && !str_contains((string) $agent404['cta_href'], '#')
);

$stubAll = MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'pages_json_ld' => $pages,
    'html_ok_count' => 1,
    'llms_txt' => $llmsOk,
    'agent_json' => s8_probe($agentUrl, 200, (string) $stub),
]));
$agentStub = s8_by_id($stubAll)['agent-json'];
check('wp.s8.t28.stub_high', $agentStub['status'] === 'fail' && $agentStub['severity'] === 'high' && str_contains((string) $agentStub['evidence'], 'protocol'));
check('wp.s8.t28.high_fail_stub', MBKBS_Scan_Audit::has_high_severity_fail($stubAll) && MBKBS_Scan_Audit::has_high_severity_fail([$agentStub]));
check('wp.s8.t28.stub.href', str_contains((string) $agentStub['cta_href'], '#machine-layers'));

// T29 tdmrep never fail
$tMiss = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'tdmrep' => s8_probe('https://ex.com/.well-known/tdmrep.json', 404, ''),
])))['tdmrep'];
check('wp.s8.t29.missing_unknown', $tMiss['status'] === 'unknown' && $tMiss['severity'] === 'info' && $tMiss['cta_href'] === '' && $tMiss['status'] !== 'fail');

$tPass = s8_by_id(MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'tdmrep' => s8_probe('https://ex.com/.well-known/tdmrep.json', 200, '{"tdm":1}'),
])))['tdmrep'];
check('wp.s8.t29.present', $tPass['status'] === 'pass' && $tPass['cta'] !== '' && $tPass['cta_href'] === '');

// T30 status rules
$warnFindings = MBKBS_Scan_Audit::evaluate_findings(s8_inp([
    'pages_json_ld' => [['https://ex.com/', []]],
    'html_ok_count' => 1,
    'llms_txt' => s8_probe($llmsUrl, 404, ''),
]));
$warnStatus = MBKBS_Scan_Audit::has_high_severity_fail($warnFindings) ? 'completed-with-warnings' : 'completed';
check('wp.s8.t30.warnings', $warnStatus === 'completed-with-warnings' && $warnStatus !== 'failed');
check('wp.s8.t30.jsonld_not_failed', s8_by_id($warnFindings)['jsonld-on-page']['status'] === 'fail');

$unkRows = MBKBS_Scan_Audit::unknown_findings('dns timeout');
check('wp.s8.t30.probe_exc', count($unkRows) === 5 && !MBKBS_Scan_Audit::has_high_severity_fail($unkRows));
check('wp.s8.t30.probe_exc.ids', array_map(static fn($f) => $f['id'], $unkRows) === MBKBS_Scan_Audit::FINDING_IDS);
check('wp.s8.t30.probe_exc.status', array_reduce($unkRows, static fn($ok, $f) => $ok && $f['status'] === 'unknown', true));
$probeExcStatus = MBKBS_Scan_Audit::has_high_severity_fail($unkRows) ? 'completed-with-warnings' : 'completed';
check('wp.s8.t30.probe_exc.completed', $probeExcStatus === 'completed');
check(
    'wp.s8.t30.admin_nested',
    str_contains($adminSrc, 'unknown_findings')
    && str_contains($adminSrc, 'completed-with-warnings')
    && str_contains($adminSrc, 'Scan complete:')
    && str_contains($adminSrc, "redirect('mbkbs'")
    && str_contains($adminSrc, "remove_submenu_page('mbkbs', 'mbkbs-scan')")
);

// skip-after-2 transport failures
$calls = [];
$fn = static function (string $url) use (&$calls): array {
    $calls[] = $url;
    return ['status' => 0, 'body' => '', 'final_url' => $url, 'error' => 'Connection timed out'];
};
$probes = MBKBS_Scan_Audit::probe_origin('https://ex.com/shop/', null, $fn);
check('wp.s8.skip.urls', str_contains($calls[0] ?? '', '/robots.txt') && str_contains($calls[1] ?? '', '/llms.txt'));
check('wp.s8.skip.count', count($calls) === 2);
check('wp.s8.skip.remaining', ($probes['agent_json']['error'] ?? '') === MBKBS_Scan_Audit::SKIP_ERROR && ($probes['tdmrep']['error'] ?? '') === MBKBS_Scan_Audit::SKIP_ERROR);
$stats = MBKBS_Scan_Audit::probes_as_stats($probes);
check('wp.s8.skip.origin_files_ok', ($stats['origin_files_ok'] ?? -1) === 0);

$calls = [];
$reused = MBKBS_Scan_Audit::probe_origin('https://ex.com/shop/', "User-agent: *\n", $fn);
check('wp.s8.robots_reuse', ($reused['robots']['status'] ?? 0) === 200 && !in_array('https://ex.com/shop/robots.txt', $calls, true));

// T31 extract JSON-LD before script strip — no WP HTTP
$t31 = '<html><head>'
    . '<script type="application/ld+json">{"@type":"Organization","name":"Acme"}</script>'
    . '<script>var x=1;</script>'
    . '</head><body><p>Hi</p></body></html>';
check('wp.s8.t31.fn', function_exists('mbkbs_extract_json_ld'));
$blocks = mbkbs_extract_json_ld($t31);
check('wp.s8.t31.extract', count($blocks) === 1 && ($blocks[0]['@type'] ?? '') === 'Organization' && ($blocks[0]['name'] ?? '') === 'Acme');
$stripped = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $t31) ?? $t31;
check('wp.s8.t31.after_strip', mbkbs_extract_json_ld($stripped) === []);
$parsePos = strpos($crawlerSrc, 'function parse_html');
$callPos = $parsePos === false ? false : strpos($crawlerSrc, 'mbkbs_extract_json_ld', $parsePos);
$stripPos = $parsePos === false ? false : strpos($crawlerSrc, "preg_replace('/<script", $parsePos);
check('wp.s8.t31.order', $callPos !== false && $stripPos !== false && $callPos < $stripPos);
check('wp.s8.request.public', str_contains($crawlerSrc, 'function request(string $url') && str_contains($crawlerSrc, 'redirect off-origin'));

check('wp.s8.ui.dashboard_findings', str_contains($dashSrc, 'Last scan findings') && strpos($dashSrc, 'Last scan findings') < strpos($dashSrc, 'mbkbs-stats'));
check('wp.s8.ui.jsonld_id', str_contains($dashSrc, 'id="jsonld_wp_head"'));
check('wp.s8.ui.cta_and_href', str_contains($dashSrc, 'cta_href') && str_contains($scanSrc, "\$cta !== '' && \$cta_href !== ''"));
check('wp.s8.ui.humanized', str_contains($scanSrc, 'Scan completed with warnings') && str_contains($scanSrc, 'Scan completed'));
check('wp.s8.ui.findings_above_stats', str_contains($scanSrc, 'id="findings"') && strpos($scanSrc, 'id="findings"') < strpos($scanSrc, 'Stats'));

echo $fail ? "Stage 8 WP: FAIL ($fail)\n" : "Stage 8 WP: PASS\n";
exit($fail ? 1 : 0);
