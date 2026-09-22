<?php
declare(strict_types=1);

/**
 * Claim Ledger Stage 8 — PHP: ScanAudit catalog, extract-before-strip, scan status.
 * Exit 0 PASS (or SKIP without pdo_sqlite), 1 FAIL.
 */

$here = __DIR__;
$bootstrap = null;
foreach ([dirname($here, 2) . '/php/src/bootstrap.php', dirname($here) . '/src/bootstrap.php'] as $cand) {
    if (is_file($cand)) {
        $bootstrap = $cand;
        break;
    }
}
if ($bootstrap === null) {
    fwrite(STDERR, "PHP Stage 8: FAIL — cannot find src/bootstrap.php\n");
    exit(1);
}
$haveSqlite = extension_loaded('pdo_sqlite');
if (!$haveSqlite) {
    fwrite(STDOUT, "PHP Stage 8: SKIP sqlite job row — pdo_sqlite extension not available\n");
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
    ], $over);
}

/** @param list<array<string,mixed>> $findings */
function s8_by_id(array $findings): array
{
    $ids = array_map(static fn($f) => $f['id'] ?? '', $findings);
    if ($ids !== Bkbs\ScanAudit::FINDING_IDS) {
        check('php.s8.catalog.order', false, json_encode($ids));
    }
    $out = [];
    foreach ($findings as $f) {
        $out[(string) $f['id']] = $f;
    }
    return $out;
}

$phpSrc = dirname($bootstrap);
$auditSrc = file_get_contents($phpSrc . '/ScanAudit.php') ?: '';
$crawlerSrc = file_get_contents($phpSrc . '/Crawler.php') ?: '';
$routerSrc = file_get_contents($phpSrc . '/Router.php') ?: '';

check('php.s8.ids', Bkbs\ScanAudit::FINDING_IDS === ['jsonld-on-page', 'aipref-robots', 'llms-txt', 'agent-json', 'tdmrep']);
check(
    'php.s8.catalog.order',
    array_column(Bkbs\ScanAudit::evaluateFindings(s8_inp(['html_ok_count' => 1, 'pages_json_ld' => [['https://ex.com/', []]]])), 'id') === Bkbs\ScanAudit::FINDING_IDS
);
check('php.s8.no_merge_robots', !str_contains($auditSrc, 'merge_robots'));
check('php.s8.no_publisher', !str_contains($auditSrc, 'Publisher'));
check('php.s8.no_wp_inject', !str_contains($auditSrc, 'maybe_print_jsonld'));
check('php.s8.no_tdm_header', !str_contains($auditSrc, 'TDM-Reservation'));

// T25 jsonld-on-page
$passF = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'pages_json_ld' => [['https://ex.com/', [['@type' => 'Organization', 'name' => 'Acme']]]],
    'html_ok_count' => 1,
])))['jsonld-on-page'];
check('php.s8.t25.pass', $passF['status'] === 'pass' && $passF['severity'] === 'high' && $passF['cta'] === '' && $passF['cta_href'] === '');
check('php.s8.t25.pass.type', str_contains((string) $passF['evidence'], 'Organization'));

$failF = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'pages_json_ld' => [['https://ex.com/', []]],
    'html_ok_count' => 1,
])))['jsonld-on-page'];
check('php.s8.t25.fail', $failF['status'] === 'fail' && $failF['severity'] === 'high' && $failF['cta'] === 'Copy JSON-LD snippet');
check(
    'php.s8.t25.fail.href',
    str_contains((string) $failF['cta_href'], 'index.php?r=')
    && str_contains((string) $failF['cta_href'], 'site-1')
    && str_contains((string) $failF['cta_href'], '#machine-layers')
);
check('php.s8.t25.fail.home', str_contains((string) $failF['evidence'], 'Homepage https://ex.com/'));

$unk = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp(['html_ok_count' => 0, 'pages_json_ld' => []])))['jsonld-on-page'];
check('php.s8.t25.unknown', $unk['status'] === 'unknown' && $unk['severity'] === 'high' && str_contains((string) $unk['evidence'], '0 HTML pages fetched'));

// T26 aipref detect-only
$body = "User-agent: *\nContent-Usage: search=y, ai-input=n\n";
$aPass = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'robots' => s8_probe('https://ex.com/robots.txt', 200, $body),
])))['aipref-robots'];
check('php.s8.t26.pass', $aPass['status'] === 'pass' && $aPass['severity'] === 'info' && str_contains((string) $aPass['evidence'], 'Content-Usage'));
check('php.s8.t26.pass.href', str_contains((string) $aPass['cta_href'], '#machine-layers'));

$aFail = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'robots' => s8_probe('https://ex.com/robots.txt', 200, "User-agent: *\nAllow: /\n"),
])))['aipref-robots'];
check('php.s8.t26.fail_info', $aFail['status'] === 'fail' && $aFail['severity'] === 'info' && $aFail['cta'] === 'Leave AIPREF off unless intended');

$aUnk = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp(['robots' => null])))['aipref-robots'];
check('php.s8.t26.unknown', $aUnk['status'] === 'unknown' && $aUnk['severity'] === 'info');

// T27 llms-txt ordered eval (no bare contains llms)
$llmsUrl = 'https://ex.com/llms.txt';
$lUnk = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, null, null, 'ConnectTimeout'),
])))['llms-txt'];
check('php.s8.t27.transport', $lUnk['status'] === 'unknown' && $lUnk['severity'] === 'high');

$lMiss = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, 404, ''),
])))['llms-txt'];
check('php.s8.t27.missing', $lMiss['status'] === 'fail' && $lMiss['cta'] === 'Publish live');
check(
    'php.s8.t27.publish_href',
    str_contains((string) $lMiss['cta_href'], 'index.php?r=')
    && !str_contains((string) $lMiss['cta_href'], '#machine-layers')
);

$lEmpty = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, 200, ''),
])))['llms-txt'];
check('php.s8.t27.empty', $lEmpty['status'] === 'fail');

$lHash = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, 200, "# Title\nHello\n"),
])))['llms-txt'];
check('php.s8.t27.hash', $lHash['status'] === 'pass');

$lMark = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, 200, "Hello\n" . Bkbs\ScanAudit::LLMS_MARKER . "\n"),
])))['llms-txt'];
check('php.s8.t27.marker', $lMark['status'] === 'pass');

$htmlLlms = '<!DOCTYPE html><html><body>See our llms.txt guide in the footer</body></html>';
$lHtml = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, 200, $htmlLlms),
])))['llms-txt'];
check('php.s8.t27.html_llms', $lHtml['status'] === 'fail' && $lHtml['severity'] === 'high');

$lOther = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'llms_txt' => s8_probe($llmsUrl, 200, 'plain text without a hash'),
])))['llms-txt'];
check('php.s8.t27.other', $lOther['status'] === 'fail');

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

$passAll = Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'pages_json_ld' => $pages,
    'html_ok_count' => 1,
    'llms_txt' => $llmsOk,
    'agent_json' => s8_probe($agentUrl, 200, (string) $honest),
]));
$agentPass = s8_by_id($passAll)['agent-json'];
check('php.s8.t28.honest', $agentPass['status'] === 'pass' && $agentPass['severity'] === 'info');

$missingAll = Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'pages_json_ld' => $pages,
    'html_ok_count' => 1,
    'llms_txt' => $llmsOk,
    'agent_json' => s8_probe($agentUrl, 404, ''),
]));
$agent404 = s8_by_id($missingAll)['agent-json'];
check('php.s8.t28.missing_medium', $agent404['status'] === 'fail' && $agent404['severity'] === 'medium');
check('php.s8.t28.no_high_404', !Bkbs\ScanAudit::hasHighSeverityFail($missingAll) && !Bkbs\ScanAudit::hasHighSeverityFail([$agent404]));

$stubAll = Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'pages_json_ld' => $pages,
    'html_ok_count' => 1,
    'llms_txt' => $llmsOk,
    'agent_json' => s8_probe($agentUrl, 200, (string) $stub),
]));
$agentStub = s8_by_id($stubAll)['agent-json'];
check('php.s8.t28.stub_high', $agentStub['status'] === 'fail' && $agentStub['severity'] === 'high' && str_contains((string) $agentStub['evidence'], 'protocol'));
check('php.s8.t28.high_fail_stub', Bkbs\ScanAudit::hasHighSeverityFail($stubAll) && Bkbs\ScanAudit::hasHighSeverityFail([$agentStub]));

// T29 tdmrep never fail
$tMiss = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'tdmrep' => s8_probe('https://ex.com/.well-known/tdmrep.json', 404, ''),
])))['tdmrep'];
check('php.s8.t29.missing_unknown', $tMiss['status'] === 'unknown' && $tMiss['severity'] === 'info' && $tMiss['cta_href'] === '' && $tMiss['status'] !== 'fail');

$tPass = s8_by_id(Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'tdmrep' => s8_probe('https://ex.com/.well-known/tdmrep.json', 200, '{"tdm":1}'),
])))['tdmrep'];
check('php.s8.t29.present', $tPass['status'] === 'pass' && $tPass['cta'] !== '' && $tPass['cta_href'] === '');

// T30 status rules
$warnFindings = Bkbs\ScanAudit::evaluateFindings(s8_inp([
    'pages_json_ld' => [['https://ex.com/', []]],
    'html_ok_count' => 1,
    'llms_txt' => s8_probe($llmsUrl, 404, ''),
]));
$warnStatus = Bkbs\ScanAudit::hasHighSeverityFail($warnFindings) ? 'completed-with-warnings' : 'completed';
check('php.s8.t30.warnings', $warnStatus === 'completed-with-warnings' && $warnStatus !== 'failed');
check('php.s8.t30.jsonld_not_failed', s8_by_id($warnFindings)['jsonld-on-page']['status'] === 'fail');

$unkRows = Bkbs\ScanAudit::unknownFindings('dns timeout');
check('php.s8.t30.probe_exc', count($unkRows) === 5 && !Bkbs\ScanAudit::hasHighSeverityFail($unkRows));
check('php.s8.t30.probe_exc.ids', array_map(static fn($f) => $f['id'], $unkRows) === Bkbs\ScanAudit::FINDING_IDS);
check('php.s8.t30.probe_exc.status', array_reduce($unkRows, static fn($ok, $f) => $ok && $f['status'] === 'unknown', true));
$probeExcStatus = Bkbs\ScanAudit::hasHighSeverityFail($unkRows) ? 'completed-with-warnings' : 'completed';
check('php.s8.t30.probe_exc.completed', $probeExcStatus === 'completed');
check(
    'php.s8.t30.router_nested',
    str_contains($routerSrc, 'unknownFindings')
    && str_contains($routerSrc, 'completed-with-warnings')
    && str_contains($routerSrc, 'Scan complete:')
    && str_contains($routerSrc, "url('scans/'")
);

if ($haveSqlite) {
    $tmp = sys_get_temp_dir() . '/bkbs-s8-' . getmypid() . '.sqlite';
    @unlink($tmp);
    $db = new Bkbs\Database($tmp);
    $pdo = $db->pdo();
    $now = gmdate('c');
    $sid = '11111111-1111-4111-8111-111111111111';
    $jid = '33333333-3333-4333-8333-333333333333';
    $pdo->prepare('INSERT INTO sites(id,name,base_url,max_pages,crawl_delay_ms,created_at) VALUES(?,?,?,?,?,?)')
        ->execute([$sid, 'S8', 'https://s8.example', 5, 0, $now]);
    $pdo->prepare('INSERT INTO scan_jobs(id,site_id,status,pages_fetched,entities_found,stats_json,created_at,finished_at) VALUES(?,?,?,?,?,?,?,?)')
        ->execute([$jid, $sid, $warnStatus, 1, 0, json_encode(['findings' => $warnFindings]), $now, $now]);
    $row = $pdo->query("SELECT status FROM scan_jobs WHERE id='$jid'")->fetch();
    check('php.s8.t30.job_row', ($row['status'] ?? '') === 'completed-with-warnings');
    @unlink($tmp);
} else {
    echo "SKIP  php.s8.t30.job_row  pdo_sqlite not available\n";
}

// skip-after-2 transport failures
$calls = [];
$fn = static function (string $url) use (&$calls): array {
    $calls[] = $url;
    return ['status' => 0, 'body' => '', 'final_url' => $url, 'error' => 'Connection timed out'];
};
$probes = Bkbs\ScanAudit::probeOrigin('https://ex.com/shop/', null, $fn);
check('php.s8.skip.urls', str_contains($calls[0] ?? '', '/robots.txt') && str_contains($calls[1] ?? '', '/llms.txt'));
check('php.s8.skip.count', count($calls) === 2);
check('php.s8.skip.remaining', ($probes['agent_json']['error'] ?? '') === Bkbs\ScanAudit::SKIP_ERROR && ($probes['tdmrep']['error'] ?? '') === Bkbs\ScanAudit::SKIP_ERROR);
$stats = Bkbs\ScanAudit::probesAsStats($probes);
check('php.s8.skip.origin_files_ok', ($stats['origin_files_ok'] ?? -1) === 0);

$calls = [];
$reused = Bkbs\ScanAudit::probeOrigin('https://ex.com/shop/', "User-agent: *\n", $fn);
check('php.s8.robots_reuse', ($reused['robots']['status'] ?? 0) === 200 && !in_array('https://ex.com/shop/robots.txt', $calls, true));

// T31 extract JSON-LD before script strip
$t31 = '<html><head>'
    . '<script type="application/ld+json">{"@type":"Organization","name":"Acme"}</script>'
    . '<script>var x=1;</script>'
    . '</head><body><p>Hi</p></body></html>';
$crawler = new Bkbs\Crawler();
$blocks = $crawler->extractJsonLd($t31);
check('php.s8.t31.extract', count($blocks) === 1 && ($blocks[0]['@type'] ?? '') === 'Organization' && ($blocks[0]['name'] ?? '') === 'Acme');
$stripped = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $t31) ?? $t31;
check('php.s8.t31.after_strip', $crawler->extractJsonLd($stripped) === []);
$parsePos = strpos($crawlerSrc, 'function parseHtml');
$callPos = $parsePos === false ? false : strpos($crawlerSrc, 'extractJsonLd', $parsePos);
$stripPos = $parsePos === false ? false : strpos($crawlerSrc, "preg_replace('/<script", $parsePos);
check('php.s8.t31.order', $callPos !== false && $stripPos !== false && $callPos < $stripPos);
check('php.s8.request.public', str_contains($crawlerSrc, 'function request(string $url') && str_contains($crawlerSrc, 'redirect off-origin'));

$scanTpl = file_get_contents(dirname($phpSrc) . '/templates/scan.php') ?: '';
check('php.s8.ui.findings_above_stats', str_contains($scanTpl, 'id="findings"') && strpos($scanTpl, 'id="findings"') < strpos($scanTpl, 'Stats'));
check('php.s8.ui.humanized', str_contains($scanTpl, 'Scan completed with warnings') && str_contains($scanTpl, 'Scan completed'));
check('php.s8.ui.cta_and_href', str_contains($scanTpl, 'cta_href') && str_contains($scanTpl, "\$cta !== '' && \$ctaHref !== ''"));

$header = file_get_contents(dirname($phpSrc) . '/templates/layout_header.php') ?: '';
check('php.s8.ui.pills', str_contains($header, 'pill-completed-with-warnings') && str_contains($header, 'pill-pass') && str_contains($header, 'pill-fail'));

$siteTpl = file_get_contents(dirname($phpSrc) . '/templates/site.php') ?: '';
check('php.s8.ui.site_scan_link', str_contains($siteTpl, "url('scans/'"));

echo $fail ? "Stage 8 PHP: FAIL ($fail)\n" : "Stage 8 PHP: PASS\n";
exit($fail ? 1 : 0);
