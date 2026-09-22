<?php
declare(strict_types=1);

namespace Bkbs;

final class Router
{
    public function dispatch(string $route, string $method): void
    {
        $route = trim($route, '/') ?: 'home';
        $method = strtoupper($method);

        if (!bkbs_installed() && $route !== 'install') {
            redirect(url('install'));
        }

        if (str_starts_with($route, 'api/')) {
            $this->dispatchApi($route, $method);
            return;
        }

match (true) {
            $route === 'home' && $method === 'GET' => $this->home(),
            str_starts_with($route, 'scans/') && $method === 'GET' => $this->scanStatus($this->idFrom($route, 1)),
            $route === 'sites/create' && $method === 'POST' => $this->siteCreate(),
            str_starts_with($route, 'sites/') && str_ends_with($route, '/scan') && $method === 'POST' => $this->scan($this->idFrom($route, 1)),
            str_starts_with($route, 'sites/') && str_ends_with($route, '/publish') && $method === 'POST' => $this->publish($this->idFrom($route, 1)),
            str_starts_with($route, 'sites/') && str_ends_with($route, '/settings') && $method === 'POST' => $this->siteSettings($this->idFrom($route, 1)),
            str_starts_with($route, 'sites/') && str_ends_with($route, '/delete') && $method === 'POST' => $this->siteDelete($this->idFrom($route, 1)),
            str_starts_with($route, 'sites/') && !str_contains(substr($route, 6), '/') && $method === 'GET' => $this->siteDetail($this->idFrom($route, 1)),
            str_starts_with($route, 'sites/') && str_ends_with($route, '/bulk-review') && $method === 'GET' => $this->bulkReview($this->idFrom($route, 1)),
            str_starts_with($route, 'sites/') && str_ends_with($route, '/entities') && $method === 'GET' => $this->entities($this->idFrom($route, 1)),
            $route === 'entities/bulk' && $method === 'POST' => $this->bulkVerify(),
            str_starts_with($route, 'entities/') && str_ends_with($route, '/diff') && $method === 'GET' => $this->entityDiff($this->idFrom($route, 1)),
            str_starts_with($route, 'entities/') && str_ends_with($route, '/review') && $method === 'POST' => $this->entityReview($this->idFrom($route, 1)),
            str_starts_with($route, 'entities/') && !str_ends_with($route, '/verify') && $method === 'GET' => $this->entityEdit($this->idFrom($route, 1)),
            str_starts_with($route, 'entities/') && str_ends_with($route, '/verify') && $method === 'POST' => $this->verify($this->idFrom($route, 1)),
            str_starts_with($route, 'entities/') && !str_ends_with($route, '/verify') && $method === 'POST' => $this->entityUpdate($this->idFrom($route, 1)),
            $route === 'settings' && $method === 'GET' => $this->settings(),
            $route === 'settings/llm' && $method === 'POST' => $this->settingsSave(),
            $route === 'settings/llm/test' && $method === 'POST' => $this->settingsTest(),
            $route === 'manual' && $method === 'POST' => $this->manualCreate(),
            default => $this->notFound(),
        };
    }

    private function idFrom(string $route, int $index): string
    {
        $parts = explode('/', $route);
        return $parts[$index] ?? '';
    }

    private function dispatchApi(string $route, string $method): void
    {
        bkbs_require_api_auth();
        if (str_starts_with($route, 'api/entities/') && str_ends_with($route, '/claims') && $method === 'GET') {
            $id = $this->idFrom($route, 2);
            $this->apiEntityClaims($id);
            return;
        }
        if (str_starts_with($route, 'api/entities/') && $method === 'GET') {
            $id = $this->idFrom($route, 2);
            $this->apiEntity($id);
            return;
        }
        bkbs_json_error(404, 'Not found');
    }

    private function apiEntity(string $entityId): void
    {
        $asOf = trim((string) ($_GET['as_of'] ?? ''));
        $asOf = $asOf !== '' ? $asOf : null;
        $pdo = bkbs_db()->pdo();
        $resolved = Resolver::resolveEntity($entityId, $asOf, $pdo);
        if ($resolved === null) {
            bkbs_json_error(404, 'Entity not found');
        }
        bkbs_json($resolved);
    }

    private function apiEntityClaims(string $entityId): void
    {
        $pdo = bkbs_db()->pdo();
        $st = $pdo->prepare('SELECT id FROM entities WHERE id = ?');
        $st->execute([$entityId]);
        if (!$st->fetch()) {
            bkbs_json_error(404, 'Entity not found');
        }
        $asOf = trim((string) ($_GET['as_of'] ?? ''));
        $asOf = $asOf !== '' ? $asOf : null;
        bkbs_json(Resolver::listClaims($pdo, $entityId, $asOf));
    }

    private function home(): void
    {
        $db = bkbs_db();
        $sites = $db->pdo()->query('SELECT * FROM sites ORDER BY created_at DESC')->fetchAll();
        foreach ($sites as &$s) {
            $st = $db->pdo()->prepare('SELECT status, COUNT(*) c FROM entities WHERE site_id = ? GROUP BY status');
            $st->execute([$s['id']]);
            $s['counts'] = ['pending' => 0, 'approved' => 0, 'total' => 0];
            foreach ($st->fetchAll() as $row) {
                $s['counts']['total'] += (int) $row['c'];
                if ($row['status'] === 'approved') {
                    $s['counts']['approved'] = (int) $row['c'];
                }
                if (in_array($row['status'], ['pending', 'needs_edit'], true)) {
                    $s['counts']['pending'] += (int) $row['c'];
                }
            }
        }
        unset($s);
        $llm = LlmClient::fromSettings($db);
        render('home', [
            'sites' => $sites,
            'has_llm' => $llm !== null,
            'publish_candidates' => bkbs_detect_publish_paths(),
            'best_publish_path' => bkbs_best_publish_path(),
        ]);
    }

    private function siteCreate(): void
    {
        $name = trim($_POST['name'] ?? '');
        $base = trim($_POST['base_url'] ?? '');
        if ($name === '' || $base === '') {
            flash_set('err', 'Name and URL required');
            redirect(url('home'));
        }
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        $base = rtrim($base, '/');
        $id = uuid();
        $now = gmdate('c');
        $db = bkbs_db();
        $st = $db->pdo()->prepare(
            'INSERT INTO sites(id,name,base_url,max_pages,crawl_delay_ms,publish_root,auto_publish,created_at)
             VALUES(?,?,?,?,?,?,?,?)'
        );
        $st->execute([
            $id,
            $name,
            $base,
            max(1, min(500, (int) ($_POST['max_pages'] ?? 40))),
            max(0, (int) ($_POST['crawl_delay_ms'] ?? 300)),
            trim($_POST['publish_root'] ?? '') ?: null,
            isset($_POST['auto_publish']) ? 1 : 0,
            $now,
        ]);
        flash_set('ok', 'Site created');
        redirect(url('sites/' . $id));
    }

    private function siteDetail(string $id): void
    {
        $db = bkbs_db();
        $site = $this->requireSite($id);
        $jobs = $db->pdo()->prepare('SELECT * FROM scan_jobs WHERE site_id = ? ORDER BY created_at DESC LIMIT 10');
        $jobs->execute([$id]);
        $counts = $db->pdo()->prepare('SELECT status, COUNT(*) c FROM entities WHERE site_id = ? GROUP BY status');
        $counts->execute([$id]);
        $by = [];
        foreach ($counts->fetchAll() as $r) {
            $by[$r['status']] = (int) $r['c'];
        }
        $public = Resolver::resolveSite($db->pdo(), $id, false);
        $base = rtrim((string) $site['base_url'], '/');
        render('site', [
            'site' => $site,
            'jobs' => $jobs->fetchAll(),
            'counts' => $by,
            'has_llm' => LlmClient::fromSettings($db) !== null,
            'publish_candidates' => bkbs_detect_publish_paths(),
            'best_publish_path' => bkbs_best_publish_path(),
            'jsonld_snippet' => Exports\JsonLdSnippet::organization($site, $public),
            'robots_preview' => Exports\Robots::block($site),
            'live_urls' => [
                ['llms.txt', $base . '/llms.txt'],
                ['llms-full.txt', $base . '/llms-full.txt'],
                ['graph.json', $base . '/graph.json'],
                ['organization.jsonld', $base . '/schema/organization.jsonld'],
                ['services.jsonld', $base . '/schema/services.jsonld'],
                ['agent.json', $base . '/.well-known/agent.json'],
            ],
        ]);
    }

    private function siteSettings(string $id): void
    {
        $site = $this->requireSite($id);
        $base = trim($_POST['base_url'] ?? $site['base_url']);
        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . $base;
        }
        $st = bkbs_db()->pdo()->prepare(
            'UPDATE sites SET name=?, base_url=?, max_pages=?, crawl_delay_ms=?, publish_root=?, auto_publish=?, aipref_search=?, aipref_ai_input=?, aipref_train_ai=? WHERE id=?'
        );
        $st->execute([
            trim($_POST['name'] ?? $site['name']),
            rtrim($base, '/'),
            max(1, min(500, (int) ($_POST['max_pages'] ?? 40))),
            max(0, (int) ($_POST['crawl_delay_ms'] ?? 300)),
            trim($_POST['publish_root'] ?? '') ?: null,
            isset($_POST['auto_publish']) ? 1 : 0,
            isset($_POST['aipref_search']) ? 1 : 0,
            isset($_POST['aipref_ai_input']) ? 1 : 0,
            isset($_POST['aipref_train_ai']) ? 1 : 0,
            $id,
        ]);
        flash_set('ok', 'Settings saved');
        redirect(url('sites/' . $id));
    }

    private function siteDelete(string $id): void
    {
        $site = $this->requireSite($id);
        if (trim($_POST['confirm_name'] ?? '') !== $site['name']) {
            flash_set('err', 'Type the exact site name to confirm delete');
            redirect(url('sites/' . $id));
        }
        $pdo = bkbs_db()->pdo();
        $ids = $pdo->prepare('SELECT id FROM entities WHERE site_id = ?');
        $ids->execute([$id]);
        $eids = array_map(static fn($r) => (string) $r['id'], $ids->fetchAll() ?: []);
        if ($eids) {
            $ph = implode(',', array_fill(0, count($eids), '?'));
            $pdo->prepare("DELETE FROM claims WHERE entity_id IN ($ph)")->execute($eids);
        }
        $st = $pdo->prepare('DELETE FROM sites WHERE id = ?');
        $st->execute([$id]);
        flash_set('ok', 'Site deleted');
        redirect(url('home'));
    }

    private function scan(string $id): void
    {
        $site = $this->requireSite($id);
        $db = bkbs_db();
        $jobId = uuid();
        $now = gmdate('c');
        $db->pdo()->prepare(
            'INSERT INTO scan_jobs(id,site_id,status,pages_fetched,entities_found,created_at) VALUES(?,?,?,?,?,?)'
        )->execute([$jobId, $id, 'running', 0, 0, $now]);

        try {
            $crawler = new Crawler();
            $pages = $crawler->crawl(
                $site['base_url'],
                (int) $site['max_pages'],
                (int) $site['crawl_delay_ms']
            );
            $extractor = new Extractor();
            $found = $extractor->extractHeuristic($pages);
            $llm = LlmClient::fromSettings($db);
            $llmCount = 0;
            if ($llm) {
                try {
                    $llmEnts = $extractor->extractWithLlm($llm, $pages, $site['base_url']);
                    $llmCount = count($llmEnts);
                    $found = array_merge($found, $llmEnts);
                } catch (\Throwable $e) {
                    // keep heuristic
                }
            }
            $merged = 0;
            foreach ($found as $item) {
                $merged += $this->upsertEntity($id, $item) ? 1 : 0;
            }
            try {
                $pagesJsonLd = [];
                foreach ($pages as $p) {
                    $pagesJsonLd[] = [$p['url'], $p['json_ld'] ?? []];
                }
                $probes = ScanAudit::probeOrigin((string) $site['base_url'], null, [$crawler, 'request']);
                $findings = ScanAudit::evaluateFindings([
                    'base_url' => (string) $site['base_url'],
                    'site_id' => $id,
                    'pages_json_ld' => $pagesJsonLd,
                    'html_ok_count' => count($pages),
                    'robots' => $probes['robots'] ?? null,
                    'llms_txt' => $probes['llms_txt'] ?? null,
                    'agent_json' => $probes['agent_json'] ?? null,
                    'tdmrep' => $probes['tdmrep'] ?? null,
                ]);
                $originStats = ScanAudit::probesAsStats($probes);
            } catch (\Throwable $e) {
                $findings = ScanAudit::unknownFindings($e->getMessage());
                $originStats = [];
            }
            $status = ScanAudit::hasHighSeverityFail($findings) ? 'completed-with-warnings' : 'completed';
            $stats = [
                'crawl' => ['ok' => count($pages), 'max_pages' => (int) $site['max_pages']],
                'heuristic_count' => count($found) - $llmCount,
                'llm_count' => $llmCount,
                'touched' => $merged,
                'findings' => $findings,
                'origin_probes' => $originStats,
            ];
            $db->pdo()->prepare(
                'UPDATE scan_jobs SET status=?, pages_fetched=?, entities_found=?, stats_json=?, finished_at=? WHERE id=?'
            )->execute([
                $status,
                count($pages),
                $merged,
                json_encode($stats, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                gmdate('c'),
                $jobId,
            ]);
            flash_set('ok', 'Scan complete: ' . count($pages) . ' pages, ' . $merged . ' entities touched' . ($llm ? ' (LLM on)' : ' (heuristic only)'));
        } catch (\Throwable $e) {
            $db->pdo()->prepare(
                'UPDATE scan_jobs SET status=?, error=?, finished_at=? WHERE id=?'
            )->execute(['failed', $e->getMessage(), gmdate('c'), $jobId]);
            flash_set('err', 'Scan failed: ' . $e->getMessage());
        }
        redirect(url('scans/' . $jobId));
    }

    private function scanStatus(string $jobId): void
    {
        $db = bkbs_db();
        $st = $db->pdo()->prepare('SELECT * FROM scan_jobs WHERE id = ?');
        $st->execute([$jobId]);
        $job = $st->fetch();
        if (!$job) {
            flash_set('err', 'Scan not found');
            redirect(url('home'));
        }
        $site = $this->requireSite((string) $job['site_id']);
        $stats = [];
        if (!empty($job['stats_json'])) {
            $decoded = json_decode((string) $job['stats_json'], true);
            $stats = is_array($decoded) ? $decoded : [];
        }
        $findings = $stats['findings'] ?? [];
        if (!is_array($findings)) {
            $findings = [];
        }
        render('scan', [
            'job' => $job,
            'site' => $site,
            'stats' => $stats,
            'findings' => $findings,
            'has_llm' => LlmClient::fromSettings($db) !== null,
        ]);
    }

    /** @param array<string,mixed> $item */
    private function upsertEntity(string $siteId, array $item): bool
    {
        // Stage 3: claim-only attribute proposals; freeze entity attrs on rescan.
        $type = (string) ($item['entity_type'] ?? '');
        $name = trim((string) ($item['name'] ?? ''));
        if ($type === '' || $name === '') {
            return false;
        }
        $key = external_key($siteId, $type, $name);
        $db = bkbs_db()->pdo();
        $st = $db->prepare('SELECT * FROM entities WHERE site_id = ? AND external_key = ?');
        $st->execute([$siteId, $key]);
        $existing = $st->fetch(\PDO::FETCH_ASSOC);
        $now = gmdate('c');
        $props = json_encode($item['properties'] ?? new \stdClass());
        $rels = json_encode($item['relationships'] ?? []);
        $evid = json_encode($item['evidence'] ?? []);
        $desc = isset($item['description']) ? (string) $item['description'] : null;
        $source = (string) ($item['source'] ?? 'scan');
        $trust = (string) ($item['trust_level'] ?? 'medium');

        if ($existing) {
            $claimStats = Resolver::proposeClaimsFromExtract($db, $existing, $item);
            $status = (string) ($existing['status'] ?? 'pending');
            $version = (int) ($existing['version'] ?? 1);
            $touched = ($claimStats['claims_created'] ?? 0) > 0;
            if ($status === 'stale') {
                $status = 'pending';
                $touched = true;
            }
            if (($claimStats['claims_created'] ?? 0) > 0) {
                if ($status === 'approved') {
                    $status = 'needs_edit';
                } elseif ($status === 'rejected') {
                    $status = 'pending';
                }
                $version++;
                $sourceOut = 'rescan_merge';
            } else {
                $sourceOut = (string) ($existing['source'] ?? $source);
            }
            if ($touched || ($claimStats['claims_created'] ?? 0) > 0) {
                $db->prepare(
                    'UPDATE entities SET status=?, source=?, last_updated=?, version=? WHERE id=?'
                )->execute([$status, $sourceOut, $now, $version, $existing['id']]);
            } else {
                $db->prepare('UPDATE entities SET last_updated=? WHERE id=?')->execute([$now, $existing['id']]);
            }
        } else {
            $id = uuid();
            $db->prepare(
                'INSERT INTO entities(id,site_id,external_key,entity_type,name,description,properties,relationships,evidence,version,trust_level,source,status,last_updated,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?,1,?,?,?,?,?)'
            )->execute([
                $id, $siteId, $key, $type, '', null, '{}', '[]', '[]', $trust, $source, 'pending', $now, $now,
            ]);
            $row = [
                'id' => $id,
                'entity_type' => $type,
                'name' => $name,
                'description' => $desc,
                'properties' => $item['properties'] ?? [],
                'relationships' => $item['relationships'] ?? [],
                'evidence' => $item['evidence'] ?? [],
                'trust_level' => $trust,
                'source' => $source,
                'status' => 'pending',
            ];
            Resolver::seedPendingClaimsForNewEntity($db, $row, $item);
        }
        return true;
    }

    private function entities(string $siteId): void
    {
        $site = $this->requireSite($siteId);
        $status = $_GET['status'] ?? 'inbox';
        if ($status === '') {
            $status = 'inbox';
        }
        $sql = 'SELECT * FROM entities WHERE site_id = ?';
        $params = [$siteId];
        if ($status === 'inbox') {
            $sql .= " AND status IN ('pending','needs_edit')";
        } elseif ($status !== 'all') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY status, entity_type, id LIMIT 500';
        $pdo = bkbs_db()->pdo();
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $entities = $st->fetchAll();
        $ids = array_map(static fn($e) => (string) $e['id'], $entities);
        render('entities', [
            'site' => $site,
            'entities' => $entities,
            'diffs' => Resolver::claimDiffsForIds($pdo, $ids),
            'status' => $status,
            'types' => entity_types(),
            'has_llm' => LlmClient::fromSettings(bkbs_db()) !== null,
        ]);
    }

    private function entityEdit(string $entityId): void
    {
        $db = bkbs_db()->pdo();
        $st = $db->prepare('SELECT * FROM entities WHERE id = ?');
        $st->execute([$entityId]);
        $entity = $st->fetch();
        if (!$entity) {
            flash_set('err', 'Entity not found');
            redirect(url('home'));
        }
        $site = $this->requireSite($entity['site_id']);
        $diff = Resolver::claimDiff($db, (string) $entity['id']);
        if (($diff['display_name'] ?? '') === '') {
            $diff['display_name'] = (string) $entity['name'];
        }
        render('entity_edit', [
            'entity' => $entity,
            'diff' => $diff,
            'site' => $site,
            'types' => entity_types(),
            'has_llm' => LlmClient::fromSettings(bkbs_db()) !== null,
        ]);
    }

    private function entityUpdate(string $entityId): void
    {
        $db = bkbs_db()->pdo();
        $st = $db->prepare('SELECT * FROM entities WHERE id = ?');
        $st->execute([$entityId]);
        $entity = $st->fetch();
        if (!$entity) {
            flash_set('err', 'Entity not found');
            redirect(url('home'));
        }
        $site = $this->requireSite($entity['site_id']);

        $name = trim($_POST['name'] ?? '');
        $entityType = $_POST['entity_type'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $status = $_POST['status'] ?? 'pending';
        $trustLevel = $_POST['trust_level'] ?? 'medium';
        $notes = trim($_POST['notes'] ?? '');
        $intent = $_POST['intent'] ?? 'save';

        if ($name === '' || $entityType === '') {
            flash_set('err', 'Name and entity type are required');
            redirect(url('entities/' . $entityId));
        }

        $propsRaw = $_POST['properties_json'] ?? '{}';
        $relsRaw = $_POST['relationships_json'] ?? '[]';
        $evidRaw = $_POST['evidence_json'] ?? '[]';

        try {
            $props = json_decode($propsRaw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($props)) {
                $props = [];
            }
        } catch (\JsonException) {
            flash_set('err', 'Invalid properties JSON');
            redirect(url('entities/' . $entityId));
        }
        try {
            $rels = json_decode($relsRaw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($rels)) {
                $rels = [];
            }
        } catch (\JsonException) {
            flash_set('err', 'Invalid relationships JSON');
            redirect(url('entities/' . $entityId));
        }
        try {
            $evid = json_decode($evidRaw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($evid)) {
                $evid = [];
            }
        } catch (\JsonException) {
            flash_set('err', 'Invalid evidence JSON');
            redirect(url('entities/' . $entityId));
        }

        $validStatuses = ['pending', 'approved', 'rejected', 'needs_edit'];
        if (!in_array($status, $validStatuses, true)) {
            $status = 'pending';
        }
        $validTrust = ['low', 'medium', 'high'];
        if (!in_array($trustLevel, $validTrust, true)) {
            $trustLevel = 'medium';
        }

        $previousStatus = (string) ($entity['status'] ?? 'pending');
        // Handle intent: save_approve / save_reject override status
        if ($intent === 'save_approve') {
            $status = 'approved';
        } elseif ($intent === 'save_reject') {
            $status = 'rejected';
        }

        $key = external_key($site['id'], $entityType, $name);
        $now = gmdate('c');

        $st = $db->prepare(
            'UPDATE entities SET entity_type=?, name=?, description=?, properties=?, relationships=?, evidence=?, trust_level=?, notes=?, status=?, version=version+1, last_updated=?, external_key=? WHERE id=?'
        );
        $st->execute([
            $entityType,
            $name,
            $description !== '' ? $description : null,
            json_encode($props),
            json_encode($rels),
            json_encode($evid),
            $trustLevel,
            $notes !== '' ? $notes : null,
            $status,
            $now,
            $key,
            $entityId,
        ]);

        $st = $db->prepare('SELECT * FROM entities WHERE id = ?');
        $st->execute([$entityId]);
        $updated = $st->fetch(\PDO::FETCH_ASSOC);
        if ($updated) {
            Resolver::applySaveClaims($db, $updated, (string) $intent, $previousStatus, 'ui');
        }

        flash_set('ok', 'Entity saved');
        redirect(url('entities/' . $entityId));
    }

    private function verify(string $entityId): void
    {
        $action = $_POST['action'] ?? '';
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'needs_edit' => 'needs_edit'];
        if (!isset($map[$action])) {
            flash_set('err', 'Bad action');
            redirect(url('home'));
        }
        $pdo = bkbs_db()->pdo();
        $st = $pdo->prepare('SELECT * FROM entities WHERE id = ?');
        $st->execute([$entityId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            flash_set('err', 'Entity not found');
            redirect(url('home'));
        }
        Resolver::applyHumanDecision($pdo, $row, $action, 'ui');
        $site = $this->requireSite((string) $row['site_id']);
        flash_set('ok', $this->reviewToast($site, $action === 'approve'));
        redirect(url('sites/' . $row['site_id'] . '/entities'));
    }

    private function entityDiff(string $entityId): void
    {
        $pdo = bkbs_db()->pdo();
        $st = $pdo->prepare('SELECT * FROM entities WHERE id = ?');
        $st->execute([$entityId]);
        $entity = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$entity) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'not found']);
            exit;
        }
        $diff = Resolver::claimDiff($pdo, $entityId);
        $diff['envelope_status'] = $entity['status'];
        if (($diff['display_name'] ?? '') === '') {
            $diff['display_name'] = (string) $entity['name'];
        }
        header('Content-Type: application/json');
        echo json_encode($diff, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function entityReview(string $entityId): void
    {
        $pdo = bkbs_db()->pdo();
        $st = $pdo->prepare('SELECT * FROM entities WHERE id = ?');
        $st->execute([$entityId]);
        $entity = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$entity) {
            flash_set('err', 'Entity not found');
            redirect(url('home'));
        }
        $intent = (string) ($_POST['intent'] ?? 'save');
        $submitted = [];
        $extract = [];
        foreach ($_POST as $key => $val) {
            $key = (string) $key;
            if (str_starts_with($key, 'claim:')) {
                $submitted[substr($key, 6)] = (string) $val;
            } elseif (str_starts_with($key, 'extract:')) {
                $extract[substr($key, 8)] = (string) $val;
            }
        }
        if (trim((string) ($_POST['notes'] ?? '')) !== '') {
            $pdo->prepare('UPDATE entities SET notes = ? WHERE id = ?')
                ->execute([trim((string) $_POST['notes']), $entityId]);
        }
        Resolver::applyReviewFromForm($pdo, $entity, $intent, $submitted, $extract, 'ui');
        $site = $this->requireSite((string) $entity['site_id']);
        flash_set('ok', $this->reviewToast($site, $intent === 'save_approve'));
        if (in_array($intent, ['save_approve', 'save_reject'], true)) {
            redirect(url('sites/' . $entity['site_id'] . '/entities'));
        }
        redirect(url('entities/' . $entityId));
    }

    private function bulkReview(string $siteId): void
    {
        $site = $this->requireSite($siteId);
        $ids = array_filter(explode(',', (string) ($_GET['ids'] ?? '')));
        $pdo = bkbs_db()->pdo();
        $entities = [];
        $st = $pdo->prepare('SELECT * FROM entities WHERE id = ?');
        foreach ($ids as $id) {
            $st->execute([(string) $id]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $entities[] = $row;
            }
        }
        $eids = array_map(static fn($e) => (string) $e['id'], $entities);
        render('bulk_review', [
            'site' => $site,
            'entities' => $entities,
            'diffs' => Resolver::claimDiffsForIds($pdo, $eids),
            'has_llm' => LlmClient::fromSettings(bkbs_db()) !== null,
        ]);
    }

    private function bulkVerify(): void
    {
        $siteId = $_POST['site_id'] ?? '';
        $action = $_POST['action'] ?? 'approve';
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'needs_edit' => 'needs_edit'];
        if (!isset($map[$action])) {
            $action = 'approve';
        }
        $ids = $_POST['entity_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $confirm = (string) ($_POST['confirm_diffs'] ?? '') === '1';
        $pdo = bkbs_db()->pdo();
        $load = $pdo->prepare('SELECT * FROM entities WHERE id = ?');
        $approvedN = 0;
        $reviewIds = [];
        foreach ($ids as $id) {
            $load->execute([(string) $id]);
            $ent = $load->fetch(\PDO::FETCH_ASSOC);
            if (!$ent) {
                continue;
            }
            if ($action === 'approve' && !$confirm && !Resolver::canBulkApprove($pdo, (string) $ent['id'])) {
                $reviewIds[] = (string) $ent['id'];
                continue;
            }
            Resolver::applyHumanDecision($pdo, $ent, $action, 'ui');
            $approvedN++;
        }
        if ($action === 'approve' && $reviewIds) {
            $extra = $approvedN ? "Approved {$approvedN} new entities. " : '';
            flash_set('ok', $extra . 'Review claim diffs before bulk-approving previously published items.');
            redirect(url('sites/' . $siteId . '/bulk-review') . '?ids=' . rawurlencode(implode(',', $reviewIds)));
        }
        flash_set('ok', 'Updated ' . $approvedN . ' entities');
        redirect(url('sites/' . $siteId . '/entities'));
    }

    /** @param array<string, mixed> $site */
    private function reviewToast(array $site, bool $publishIntent): string
    {
        if (!$publishIntent || empty($site['auto_publish'])) {
            return 'saved, not published';
        }
        $root = trim((string) ($site['publish_root'] ?? ''));
        if ($root === '') {
            $cfg = bkbs_config();
            $root = trim((string) ($cfg['default_publish_root'] ?? ''));
        }
        if ($root === '') {
            return 'saved, not published';
        }
        $entities = Resolver::resolveSite(bkbs_db()->pdo(), (string) $site['id'], false);
        $result = (new Publisher())->publish($site, $entities, $root, false);
        if (!empty($result['ok'])) {
            return 'Published · ' . rtrim((string) $site['base_url'], '/') . '/llms.txt';
        }
        return 'saved, not published';
    }

    private function publish(string $id): void
    {
        $site = $this->requireSite($id);
        $root = trim((string) ($site['publish_root'] ?? ''));
        if ($root === '') {
            $cfg = bkbs_config();
            $root = trim((string) ($cfg['default_publish_root'] ?? ''));
        }
        if ($root === '') {
            flash_set('err', 'Set web root path (publish root) first');
            redirect(url('sites/' . $id));
        }
        $entities = Resolver::resolveSite(bkbs_db()->pdo(), $id, false);
        $result = (new Publisher())->publish($site, $entities, $root);
        if (!$result['ok']) {
            flash_set('err', $result['error'] ?? 'Publish failed');
        } else {
            flash_set('ok', 'Published ' . $result['entity_count'] . ' entities to ' . $result['root']);
        }
        redirect(url('sites/' . $id));
    }

    private function manualCreate(): void
    {
        $siteId = $_POST['site_id'] ?? '';
        $this->requireSite($siteId);
        $item = [
            'entity_type' => $_POST['entity_type'] ?? 'capability',
            'name' => trim($_POST['name'] ?? ''),
            'description' => trim($_POST['description'] ?? ''),
            'source' => 'manual',
            'trust_level' => 'high',
            'evidence' => [['url' => '', 'snippet' => 'Manual entry', 'kind' => 'manual']],
        ];
        if ($item['name'] === '') {
            flash_set('err', 'Name required');
            redirect(url('sites/' . $siteId));
        }
        $this->upsertEntity($siteId, $item);
        if (isset($_POST['approve_immediately'])) {
            $key = external_key($siteId, $item['entity_type'], $item['name']);
            $pdo = bkbs_db()->pdo();
            $st = $pdo->prepare('SELECT * FROM entities WHERE site_id=? AND external_key=?');
            $st->execute([$siteId, $key]);
            $ent = $st->fetch(\PDO::FETCH_ASSOC);
            if ($ent) {
                Resolver::applyHumanDecision($pdo, $ent, 'approve', 'ui');
            }
        }
        flash_set('ok', 'Entity created');
        redirect(url('sites/' . $siteId . '/entities'));
    }

    private function settings(): void
    {
        $db = bkbs_db();
        render('settings', [
            'provider' => $db->getSetting('llm.provider', 'openai'),
            'base_url' => $db->getSetting('llm.base_url', 'https://api.openai.com/v1'),
            'model' => $db->getSetting('llm.model', 'gpt-4o-mini'),
            'api_key_set' => (bool) $db->getSetting('llm.api_key', ''),
            'enabled' => $db->getSetting('llm.enabled', '1') !== '0',
            'has_llm' => LlmClient::fromSettings($db) !== null,
            'api_token' => bkbs_api_token(),
        ]);
    }

    private function settingsSave(): void
    {
        $db = bkbs_db();
        $db->setSetting('llm.provider', trim($_POST['provider'] ?? 'custom'));
        $db->setSetting('llm.base_url', rtrim(trim($_POST['base_url'] ?? ''), '/'));
        $db->setSetting('llm.model', trim($_POST['model'] ?? ''));
        $db->setSetting('llm.enabled', isset($_POST['enabled']) ? '1' : '0');
        if (isset($_POST['clear_key'])) {
            $db->setSetting('llm.api_key', '');
        } elseif (trim($_POST['api_key'] ?? '') !== '') {
            $db->setSetting('llm.api_key', trim($_POST['api_key']));
        }
        flash_set('ok', 'LLM settings saved');
        redirect(url('settings'));
    }

    private function settingsTest(): void
    {
        $llm = LlmClient::fromSettings(bkbs_db());
        if (!$llm) {
            flash_set('err', 'LLM not configured');
            redirect(url('settings'));
        }
        try {
            $out = $llm->chat('Reply with ok only.', 'ping');
            flash_set('ok', 'Connection OK: ' . mb_substr($out, 0, 80));
        } catch (\Throwable $e) {
            flash_set('err', $e->getMessage());
        }
        redirect(url('settings'));
    }

    /** @return array<string,mixed> */
    private function requireSite(string $id): array
    {
        $st = bkbs_db()->pdo()->prepare('SELECT * FROM sites WHERE id = ?');
        $st->execute([$id]);
        $site = $st->fetch();
        if (!$site) {
            flash_set('err', 'Site not found');
            redirect(url('home'));
        }
        return $site;
    }

    private function notFound(): void
    {
        http_response_code(404);
        echo 'Not found';
    }
}
