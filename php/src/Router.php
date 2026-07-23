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

        match (true) {
            $route === 'home' && $method === 'GET' => $this->home(),
            $route === 'sites/create' && $method === 'POST' => $this->siteCreate(),
            str_starts_with($route, 'sites/') && str_ends_with($route, '/scan') && $method === 'POST' => $this->scan($this->idFrom($route, 1)),
            str_starts_with($route, 'sites/') && str_ends_with($route, '/publish') && $method === 'POST' => $this->publish($this->idFrom($route, 1)),
            str_starts_with($route, 'sites/') && str_ends_with($route, '/settings') && $method === 'POST' => $this->siteSettings($this->idFrom($route, 1)),
            str_starts_with($route, 'sites/') && str_ends_with($route, '/delete') && $method === 'POST' => $this->siteDelete($this->idFrom($route, 1)),
            str_starts_with($route, 'sites/') && !str_contains(substr($route, 6), '/') && $method === 'GET' => $this->siteDetail($this->idFrom($route, 1)),
            str_starts_with($route, 'sites/') && str_ends_with($route, '/entities') && $method === 'GET' => $this->entities($this->idFrom($route, 1)),
            str_starts_with($route, 'entities/') && str_ends_with($route, '/verify') && $method === 'POST' => $this->verify($this->idFrom($route, 1)),
            $route === 'entities/bulk' && $method === 'POST' => $this->bulkVerify(),
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
        render('site', [
            'site' => $site,
            'jobs' => $jobs->fetchAll(),
            'counts' => $by,
            'has_llm' => LlmClient::fromSettings($db) !== null,
            'publish_candidates' => bkbs_detect_publish_paths(),
            'best_publish_path' => bkbs_best_publish_path(),
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
            'UPDATE sites SET name=?, base_url=?, max_pages=?, crawl_delay_ms=?, publish_root=?, auto_publish=? WHERE id=?'
        );
        $st->execute([
            trim($_POST['name'] ?? $site['name']),
            rtrim($base, '/'),
            max(1, min(500, (int) ($_POST['max_pages'] ?? 40))),
            max(0, (int) ($_POST['crawl_delay_ms'] ?? 300)),
            trim($_POST['publish_root'] ?? '') ?: null,
            isset($_POST['auto_publish']) ? 1 : 0,
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
        $st = bkbs_db()->pdo()->prepare('DELETE FROM sites WHERE id = ?');
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
            $db->pdo()->prepare(
                'UPDATE scan_jobs SET status=?, pages_fetched=?, entities_found=?, stats_json=?, finished_at=? WHERE id=?'
            )->execute([
                'completed',
                count($pages),
                $merged,
                json_encode(['pages' => count($pages), 'heuristic' => count($found) - $llmCount, 'llm' => $llmCount]),
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
        redirect(url('sites/' . $id));
    }

    /** @param array<string,mixed> $item */
    private function upsertEntity(string $siteId, array $item): bool
    {
        $type = (string) ($item['entity_type'] ?? '');
        $name = trim((string) ($item['name'] ?? ''));
        if ($type === '' || $name === '') {
            return false;
        }
        $key = external_key($siteId, $type, $name);
        $db = bkbs_db()->pdo();
        $st = $db->prepare('SELECT id, status FROM entities WHERE site_id = ? AND external_key = ?');
        $st->execute([$siteId, $key]);
        $existing = $st->fetch();
        $now = gmdate('c');
        $props = json_encode($item['properties'] ?? new \stdClass());
        $rels = json_encode($item['relationships'] ?? []);
        $evid = json_encode($item['evidence'] ?? []);
        $desc = isset($item['description']) ? (string) $item['description'] : null;
        $source = (string) ($item['source'] ?? 'scan');
        $trust = (string) ($item['trust_level'] ?? 'medium');

        if ($existing) {
            $db->prepare(
                'UPDATE entities SET description=COALESCE(?, description), properties=?, relationships=?, evidence=?,
                 source=?, last_updated=?, version=version+1 WHERE id=?'
            )->execute([$desc, $props, $rels, $evid, $source, $now, $existing['id']]);
        } else {
            $db->prepare(
                'INSERT INTO entities(id,site_id,external_key,entity_type,name,description,properties,relationships,evidence,version,trust_level,source,status,last_updated,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?,1,?,?,?,?,?)'
            )->execute([
                uuid(), $siteId, $key, $type, $name, $desc, $props, $rels, $evid, $trust, $source, 'pending', $now, $now,
            ]);
        }
        return true;
    }

    private function entities(string $siteId): void
    {
        $site = $this->requireSite($siteId);
        $status = $_GET['status'] ?? '';
        $sql = 'SELECT * FROM entities WHERE site_id = ?';
        $params = [$siteId];
        if ($status !== '') {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
        $sql .= ' ORDER BY status, entity_type, name LIMIT 500';
        $st = bkbs_db()->pdo()->prepare($sql);
        $st->execute($params);
        render('entities', [
            'site' => $site,
            'entities' => $st->fetchAll(),
            'status' => $status,
            'types' => entity_types(),
            'has_llm' => LlmClient::fromSettings(bkbs_db()) !== null,
        ]);
    }

    private function verify(string $entityId): void
    {
        $action = $_POST['action'] ?? '';
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'needs_edit' => 'needs_edit'];
        if (!isset($map[$action])) {
            flash_set('err', 'Bad action');
            redirect(url('home'));
        }
        $st = bkbs_db()->pdo()->prepare('SELECT site_id FROM entities WHERE id = ?');
        $st->execute([$entityId]);
        $row = $st->fetch();
        if (!$row) {
            flash_set('err', 'Entity not found');
            redirect(url('home'));
        }
        bkbs_db()->pdo()->prepare('UPDATE entities SET status=?, last_updated=? WHERE id=?')
            ->execute([$map[$action], gmdate('c'), $entityId]);
        flash_set('ok', 'Entity ' . $action . 'd');
        redirect(url('sites/' . $row['site_id'] . '/entities'));
    }

    private function bulkVerify(): void
    {
        $siteId = $_POST['site_id'] ?? '';
        $action = $_POST['action'] ?? 'approve';
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'needs_edit' => 'needs_edit'];
        $status = $map[$action] ?? 'approved';
        $ids = $_POST['entity_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $pdo = bkbs_db()->pdo();
        $st = $pdo->prepare('UPDATE entities SET status=?, last_updated=? WHERE id=?');
        foreach ($ids as $id) {
            $st->execute([$status, gmdate('c'), (string) $id]);
        }
        flash_set('ok', 'Updated ' . count($ids) . ' entities');
        redirect(url('sites/' . $siteId . '/entities'));
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
        $st = bkbs_db()->pdo()->prepare('SELECT * FROM entities WHERE site_id = ?');
        $st->execute([$id]);
        $entities = [];
        foreach ($st->fetchAll() as $row) {
            $row['properties'] = json_decode($row['properties'] ?: '{}', true);
            $row['relationships'] = json_decode($row['relationships'] ?: '[]', true);
            $row['evidence'] = json_decode($row['evidence'] ?: '[]', true);
            $entities[] = $row;
        }
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
            bkbs_db()->pdo()->prepare('UPDATE entities SET status=? WHERE site_id=? AND external_key=?')
                ->execute(['approved', $siteId, $key]);
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
