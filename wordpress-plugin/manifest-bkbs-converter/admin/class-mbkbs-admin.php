<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class MBKBS_Admin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function hooks(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_menu', [$this, 'hide_internal_pages'], 99);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_mbkbs_save_settings', [$this, 'save_settings']);
        add_action('admin_post_mbkbs_save_machine_layers', [$this, 'save_machine_layers']);
        add_action('admin_post_mbkbs_scan', [$this, 'scan']);
        add_action('admin_post_mbkbs_save_entity', [$this, 'save_entity']);
        add_action('admin_post_mbkbs_review_entity', [$this, 'review_entity']);
        add_action('admin_post_mbkbs_verify', [$this, 'verify']);
        add_action('admin_post_mbkbs_bulk_verify', [$this, 'bulk_verify']);
        add_action('admin_post_mbkbs_publish', [$this, 'publish']);
        add_action('admin_post_mbkbs_add_site', [$this, 'add_site']);
        add_action('admin_post_mbkbs_manual_entity', [$this, 'manual_entity']);
        add_action('admin_post_mbkbs_backfill_claims', [$this, 'backfill_claims']);
    }

    public function assets(string $hook): void
    {
        if (!str_contains($hook, 'mbkbs')) {
            return;
        }
        wp_enqueue_style('mbkbs-admin', MBKBS_PLUGIN_URL . 'assets/css/admin.css', [], MBKBS_VERSION);
    }

    public function menu(): void
    {
        add_menu_page(
            __('Manifest BKBS', 'manifest-bkbs'),
            __('Manifest BKBS', 'manifest-bkbs'),
            'manage_options',
            'mbkbs',
            [$this, 'page_dashboard'],
            'dashicons-networking',
            58
        );
        add_submenu_page('mbkbs', __('Dashboard', 'manifest-bkbs'), __('Dashboard', 'manifest-bkbs'), 'manage_options', 'mbkbs', [$this, 'page_dashboard']);
        add_submenu_page('mbkbs', __('Entities', 'manifest-bkbs'), __('Entities', 'manifest-bkbs'), 'manage_options', 'mbkbs-entities', [$this, 'page_entities']);
        add_submenu_page('mbkbs', __('Edit entity', 'manifest-bkbs'), __('Edit entity', 'manifest-bkbs'), 'manage_options', 'mbkbs-entity', [$this, 'page_entity_edit']);
        add_submenu_page('mbkbs', __('Bulk review', 'manifest-bkbs'), __('Bulk review', 'manifest-bkbs'), 'manage_options', 'mbkbs-bulk-review', [$this, 'page_bulk_review']);
        add_submenu_page('mbkbs', __('Settings', 'manifest-bkbs'), __('Settings', 'manifest-bkbs'), 'manage_options', 'mbkbs-settings', [$this, 'page_settings']);
        add_submenu_page('mbkbs', __('Tools', 'manifest-bkbs'), __('Tools', 'manifest-bkbs'), 'manage_options', 'mbkbs-tools', [$this, 'page_tools']);
        add_submenu_page('mbkbs', __('Scan', 'manifest-bkbs'), __('Scan', 'manifest-bkbs'), 'manage_options', 'mbkbs-scan', [$this, 'page_scan']);
    }

    public function hide_internal_pages(): void
    {
        remove_submenu_page('mbkbs', 'mbkbs-bulk-review');
        remove_submenu_page('mbkbs', 'mbkbs-scan');
    }

    public function page_tools(): void
    {
        global $wpdb;
        $claimsTable = MBKBS_Database::claims_table();
        $claimCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$claimsTable}");
        $approvedEntities = (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . MBKBS_Database::entities_table() . " WHERE status = 'approved'"
        );
        include MBKBS_PLUGIN_DIR . 'admin/views/tools.php';
    }

    public function backfill_claims(): void
    {
        $this->assert_admin();
        check_admin_referer('mbkbs_backfill_claims');
        $update = !empty($_POST['update']);
        $includePending = !empty($_POST['include_pending']);
        $totals = MBKBS_Backfill::run([
            'include_pending' => $includePending,
            'update' => $update,
            'dry_run' => false,
        ]);
        $msg = sprintf(
            /* translators: 1: entities, 2: inserted, 3: skipped, 4: superseded */
            __('Backfill complete: entities=%1$d inserted=%2$d skipped=%3$d superseded=%4$d', 'manifest-bkbs'),
            (int) $totals['entities'],
            (int) $totals['inserted'],
            (int) $totals['skipped'],
            (int) $totals['superseded']
        );
        $this->redirect('mbkbs-tools', $msg, false);
    }

    public function page_dashboard(): void
    {
        global $wpdb;
        $sites = $wpdb->get_results('SELECT * FROM ' . MBKBS_Database::sites_table() . ' ORDER BY created_at DESC', ARRAY_A) ?: [];
        $counts = $wpdb->get_results(
            'SELECT status, COUNT(*) AS c FROM ' . MBKBS_Database::entities_table() . ' GROUP BY status',
            ARRAY_A
        ) ?: [];
        $by = [];
        foreach ($counts as $row) {
            $by[$row['status']] = (int) $row['c'];
        }
        $llm = MBKBS_LLM::from_settings() !== null;
        $payload = MBKBS_Publisher::build_payload();
        $jsonld_snippet = (string) ($payload['jsonld_snippet'] ?? '');
        $jsonld_inject = MBKBS_Database::get_setting('jsonld.wp_head', '0') === '1';
        $aipref_search = MBKBS_Database::get_setting('aipref.search', '0') === '1';
        $aipref_ai_input = MBKBS_Database::get_setting('aipref.ai_input', '0') === '1';
        $aipref_train_ai = MBKBS_Database::get_setting('aipref.train_ai', '0') === '1';
        $robots_preview = MBKBS_Export_Robots::block(MBKBS_Export_Robots::site_from_settings());
        $home = untrailingslashit(home_url('/'));
        $live_urls = [
            ['llms.txt', $home . '/llms.txt'],
            ['llms-full.txt', $home . '/llms-full.txt'],
            ['graph.json', $home . '/graph.json'],
            ['organization.jsonld', $home . '/schema/organization.jsonld'],
            ['services.jsonld', $home . '/schema/services.jsonld'],
            ['agent.json', $home . '/.well-known/agent.json'],
        ];
        $last_scans = [];
        $jobs_table = MBKBS_Database::scan_jobs_table();
        foreach ($sites as $s) {
            $job = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$jobs_table} WHERE site_id = %s ORDER BY created_at DESC LIMIT 1",
                    $s['id']
                ),
                ARRAY_A
            );
            if (is_array($job)) {
                $last_scans[(string) $s['id']] = $job;
            }
        }
        include MBKBS_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public function page_scan(): void
    {
        global $wpdb;
        $job_id = isset($_GET['job']) ? sanitize_text_field(wp_unslash((string) $_GET['job'])) : '';
        $job = null;
        $site = null;
        $stats = [];
        $findings = [];
        if ($job_id !== '') {
            $job = $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM ' . MBKBS_Database::scan_jobs_table() . ' WHERE id = %s', $job_id),
                ARRAY_A
            );
            if (is_array($job)) {
                $site = $wpdb->get_row(
                    $wpdb->prepare('SELECT * FROM ' . MBKBS_Database::sites_table() . ' WHERE id = %s', $job['site_id']),
                    ARRAY_A
                );
                if (!empty($job['stats_json'])) {
                    $decoded = json_decode((string) $job['stats_json'], true);
                    $stats = is_array($decoded) ? $decoded : [];
                }
                $findings = is_array($stats['findings'] ?? null) ? $stats['findings'] : [];
            }
        }
        include MBKBS_PLUGIN_DIR . 'admin/views/scan.php';
    }

    public function page_entities(): void
    {
        global $wpdb;
        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash((string) $_GET['status'])) : 'inbox';
        if ($status === '') {
            $status = 'inbox';
        }
        $sql = 'SELECT * FROM ' . MBKBS_Database::entities_table();
        $params = [];
        if ($status === 'inbox') {
            $sql .= " WHERE status IN ('pending','needs_edit')";
        } elseif ($status !== 'all') {
            $sql .= ' WHERE status = %s';
            $params[] = $status;
        }
        $sql .= ' ORDER BY status, entity_type, name LIMIT 500';
        $entities = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);
        $entities = $entities ?: [];
        $ids = array_map(static fn($e) => (string) $e['id'], $entities);
        $diffs = MBKBS_Diff::for_ids($ids);
        $types = MBKBS_Plugin::entity_types();
        include MBKBS_PLUGIN_DIR . 'admin/views/entities.php';
    }

    public function page_entity_edit(): void
    {
        global $wpdb;
        $id = isset($_GET['id']) ? sanitize_text_field(wp_unslash((string) $_GET['id'])) : '';
        $entity = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . MBKBS_Database::entities_table() . ' WHERE id = %s', $id),
            ARRAY_A
        );
        if (!$entity) {
            wp_die(esc_html__('Entity not found.', 'manifest-bkbs'));
        }
        $types = MBKBS_Plugin::entity_types();
        $diff = MBKBS_Diff::for_entity((string) $entity['id']);
        if (($diff['display_name'] ?? '') === '') {
            $diff['display_name'] = (string) $entity['name'];
        }
        include MBKBS_PLUGIN_DIR . 'admin/views/entity-edit.php';
    }

    public function page_bulk_review(): void
    {
        global $wpdb;
        $raw = isset($_GET['ids']) ? sanitize_text_field(wp_unslash((string) $_GET['ids'])) : '';
        $ids = array_filter(explode(',', $raw));
        $entities = [];
        foreach ($ids as $id) {
            $row = $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM ' . MBKBS_Database::entities_table() . ' WHERE id = %s', $id),
                ARRAY_A
            );
            if (is_array($row)) {
                $entities[] = $row;
            }
        }
        $diffs = MBKBS_Diff::for_ids(array_map(static fn($e) => (string) $e['id'], $entities));
        include MBKBS_PLUGIN_DIR . 'admin/views/bulk-review.php';
    }

    public function page_settings(): void
    {
        $provider = MBKBS_Database::get_setting('llm.provider', 'openai');
        $base_url = MBKBS_Database::get_setting('llm.base_url', 'https://api.openai.com/v1');
        $model = MBKBS_Database::get_setting('llm.model', 'gpt-4o-mini');
        $enabled = MBKBS_Database::get_setting('llm.enabled', '1') !== '0';
        $key_set = MBKBS_Database::get_setting('llm.api_key', '') !== '';
        $llm_ok = MBKBS_LLM::from_settings() !== null;
        $jsonld_inject = MBKBS_Database::get_setting('jsonld.wp_head', '0') === '1';
        $aipref_search = MBKBS_Database::get_setting('aipref.search', '0') === '1';
        $aipref_ai_input = MBKBS_Database::get_setting('aipref.ai_input', '0') === '1';
        $aipref_train_ai = MBKBS_Database::get_setting('aipref.train_ai', '0') === '1';
        $api_token = MBKBS_Query_API::ensure_token();
        include MBKBS_PLUGIN_DIR . 'admin/views/settings.php';
    }

    public function save_settings(): void
    {
        $this->assert_admin();
        check_admin_referer('mbkbs_save_settings');
        MBKBS_Database::set_setting('llm.provider', sanitize_text_field(wp_unslash($_POST['provider'] ?? 'custom')));
        MBKBS_Database::set_setting('llm.base_url', esc_url_raw(wp_unslash($_POST['base_url'] ?? '')));
        MBKBS_Database::set_setting('llm.model', sanitize_text_field(wp_unslash($_POST['model'] ?? '')));
        MBKBS_Database::set_setting('llm.enabled', isset($_POST['enabled']) ? '1' : '0');
        if (!empty($_POST['clear_key'])) {
            MBKBS_Database::set_setting('llm.api_key', '');
        } elseif (!empty($_POST['api_key'])) {
            MBKBS_Database::set_setting('llm.api_key', sanitize_text_field(wp_unslash((string) $_POST['api_key'])));
        }
        $this->redirect('mbkbs-settings', 'Settings saved.');
    }

    public function save_machine_layers(): void
    {
        $this->assert_admin();
        check_admin_referer('mbkbs_save_machine_layers');
        MBKBS_Database::set_setting('jsonld.wp_head', isset($_POST['jsonld_wp_head']) ? '1' : '0');
        MBKBS_Database::set_setting('aipref.search', isset($_POST['aipref_search']) ? '1' : '0');
        MBKBS_Database::set_setting('aipref.ai_input', isset($_POST['aipref_ai_input']) ? '1' : '0');
        MBKBS_Database::set_setting('aipref.train_ai', isset($_POST['aipref_train_ai']) ? '1' : '0');
        $dest = sanitize_text_field(wp_unslash((string) ($_POST['redirect_page'] ?? 'mbkbs')));
        if (!in_array($dest, ['mbkbs', 'mbkbs-settings'], true)) {
            $dest = 'mbkbs';
        }
        $this->redirect($dest, 'Machine-layer settings saved.');
    }

    public function add_site(): void
    {
        global $wpdb;
        $this->assert_admin();
        check_admin_referer('mbkbs_add_site');
        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $url = esc_url_raw(wp_unslash($_POST['base_url'] ?? ''));
        if ($name === '' || $url === '') {
            $this->redirect('mbkbs', 'Name and URL required.', true);
        }
        $wpdb->insert(
            MBKBS_Database::sites_table(),
            [
                'id' => MBKBS_Database::uuid(),
                'name' => $name,
                'base_url' => untrailingslashit($url),
                'max_pages' => max(1, min(200, (int) ($_POST['max_pages'] ?? 40))),
                'crawl_delay_ms' => max(0, (int) ($_POST['crawl_delay_ms'] ?? 200)),
                'created_at' => current_time('mysql', true),
            ]
        );
        $this->redirect('mbkbs', 'Site added.');
    }

    public function scan(): void
    {
        global $wpdb;
        $this->assert_admin();
        check_admin_referer('mbkbs_scan');
        $site_id = sanitize_text_field(wp_unslash($_POST['site_id'] ?? ''));
        $site = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . MBKBS_Database::sites_table() . ' WHERE id = %s', $site_id),
            ARRAY_A
        );
        if (!$site) {
            $this->redirect('mbkbs', 'Site not found.', true);
        }

        $job_id = MBKBS_Database::uuid();
        $now = current_time('mysql', true);
        $jobs = MBKBS_Database::scan_jobs_table();
        $wpdb->insert(
            $jobs,
            [
                'id' => $job_id,
                'site_id' => $site_id,
                'status' => 'running',
                'pages_fetched' => 0,
                'entities_found' => 0,
                'created_at' => $now,
            ],
            ['%s', '%s', '%s', '%d', '%d', '%s']
        );

        try {
            @set_time_limit(300);
            $crawler = new MBKBS_Crawler();
            $pages = $crawler->crawl($site['base_url'], (int) $site['max_pages'], (int) $site['crawl_delay_ms']);
            $extractor = new MBKBS_Extractor();
            $found = $extractor->extract_heuristic($pages);
            $heuristic_count = count($found);
            $llm = MBKBS_LLM::from_settings();
            $llm_count = 0;
            if ($llm) {
                try {
                    $llm_ents = $extractor->extract_with_llm($llm, $pages, $site['base_url']);
                    $llm_count = count($llm_ents);
                    $found = array_merge($found, $llm_ents);
                } catch (Throwable $e) {
                    // keep heuristic
                }
            }
            $n = 0;
            foreach ($found as $item) {
                if ($this->upsert_entity($site_id, $item)) {
                    $n++;
                }
            }
            try {
                $pages_json_ld = [];
                foreach ($pages as $p) {
                    $pages_json_ld[] = [$p['url'], $p['json_ld'] ?? []];
                }
                $probes = MBKBS_Scan_Audit::probe_origin((string) $site['base_url'], null, [$crawler, 'request']);
                $findings = MBKBS_Scan_Audit::evaluate_findings([
                    'base_url' => (string) $site['base_url'],
                    'site_id' => $site_id,
                    'pages_json_ld' => $pages_json_ld,
                    'html_ok_count' => count($pages),
                    'robots' => $probes['robots'] ?? null,
                    'llms_txt' => $probes['llms_txt'] ?? null,
                    'agent_json' => $probes['agent_json'] ?? null,
                    'tdmrep' => $probes['tdmrep'] ?? null,
                    'wp_jsonld_inject' => MBKBS_Database::get_setting('jsonld.wp_head', '0') === '1',
                ]);
                $origin_stats = MBKBS_Scan_Audit::probes_as_stats($probes);
            } catch (Throwable $e) {
                $findings = MBKBS_Scan_Audit::unknown_findings($e->getMessage());
                $origin_stats = [];
            }
            $status = MBKBS_Scan_Audit::has_high_severity_fail($findings) ? 'completed-with-warnings' : 'completed';
            $stats = [
                'crawl' => ['ok' => count($pages), 'max_pages' => (int) $site['max_pages']],
                'heuristic_count' => $heuristic_count,
                'llm_count' => $llm_count,
                'touched' => $n,
                'findings' => $findings,
                'origin_probes' => $origin_stats,
            ];
            $wpdb->update(
                $jobs,
                [
                    'status' => $status,
                    'pages_fetched' => count($pages),
                    'entities_found' => $n,
                    'stats_json' => wp_json_encode($stats),
                    'finished_at' => current_time('mysql', true),
                ],
                ['id' => $job_id]
            );
            $msg = sprintf(
                /* translators: 1: pages, 2: entities */
                __('Scan complete: %1$d pages, %2$d entities touched. Review pending items, edit if needed, then approve.', 'manifest-bkbs'),
                count($pages),
                $n
            );
            $need = 0;
            foreach ($findings as $f) {
                if (($f['status'] ?? '') === 'fail') {
                    $need++;
                }
            }
            if ($need > 0) {
                $msg .= ' ' . sprintf(
                    /* translators: %d: number of findings */
                    _n('%d finding needs attention.', '%d findings need attention.', $need, 'manifest-bkbs'),
                    $need
                );
            }
            $this->redirect('mbkbs', $msg);
        } catch (Throwable $e) {
            $wpdb->update(
                $jobs,
                [
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                    'finished_at' => current_time('mysql', true),
                ],
                ['id' => $job_id]
            );
            $this->redirect('mbkbs', 'Scan failed: ' . $e->getMessage(), true);
        }
    }

    /**
     * Stage 3: claim-only attribute proposals; freeze entity attrs on rescan.
     *
     * @param array<string,mixed> $item
     */
    private function upsert_entity(string $site_id, array $item): bool
    {
        global $wpdb;
        $type = (string) ($item['entity_type'] ?? '');
        $name = trim((string) ($item['name'] ?? ''));
        if ($type === '' || $name === '') {
            return false;
        }
        $key = MBKBS_Database::external_key($site_id, $type, $name);
        $table = MBKBS_Database::entities_table();
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE site_id = %s AND external_key = %s", $site_id, $key),
            ARRAY_A
        );
        $now = current_time('mysql', true);
        $props = wp_json_encode($item['properties'] ?? new stdClass());
        $rels = wp_json_encode($item['relationships'] ?? []);
        $evid = wp_json_encode($item['evidence'] ?? []);
        $desc = isset($item['description']) ? (string) $item['description'] : null;
        $source = (string) ($item['source'] ?? 'scan');
        $trust = (string) ($item['trust_level'] ?? 'medium');

        if ($existing) {
            $claimStats = MBKBS_Backfill::propose_claims_from_extract($existing, $item);
            $status = (string) ($existing['status'] ?? 'pending');
            $version = (int) ($existing['version'] ?? 1);
            $sourceOut = (string) ($existing['source'] ?? $source);
            if (($claimStats['claims_created'] ?? 0) > 0) {
                if ($status === 'approved') {
                    $status = 'needs_edit';
                } elseif ($status === 'rejected' || $status === 'stale') {
                    $status = 'pending';
                }
                $version++;
                $sourceOut = 'rescan_merge';
            } elseif ($status === 'stale') {
                $status = 'pending';
            }
            $wpdb->update(
                $table,
                [
                    'status' => $status,
                    'source' => $sourceOut,
                    'last_updated' => $now,
                    'version' => $version,
                ],
                ['id' => $existing['id']]
            );
        } else {
            $id = MBKBS_Database::uuid();
            $site_id = (string) $site_id;
            $inserted = $wpdb->insert(
                $table,
                [
                    'id' => $id,
                    'site_id' => $site_id,
                    'external_key' => $key,
                    'entity_type' => $type,
                    'name' => '',
                    'description' => null,
                    'properties' => '{}',
                    'relationships' => '[]',
                    'evidence' => '[]',
                    'version' => 1,
                    'trust_level' => $trust,
                    'source' => $source,
                    'status' => 'pending',
                    'last_updated' => $now,
                    'created_at' => $now,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s']
            );
            if ($inserted === false) {
                return false;
            }
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
            MBKBS_Backfill::seed_pending_claims_for_new_entity($row, $item);
        }
        return true;
    }

    public function save_entity(): void
    {
        global $wpdb;
        $this->assert_admin();
        check_admin_referer('mbkbs_save_entity');
        $id = sanitize_text_field(wp_unslash($_POST['id'] ?? ''));
        $entity = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . MBKBS_Database::entities_table() . ' WHERE id = %s', $id),
            ARRAY_A
        );
        if (!$entity) {
            $this->redirect('mbkbs-entities', 'Entity not found.', true);
        }

        $name = sanitize_text_field(wp_unslash($_POST['name'] ?? ''));
        $type = sanitize_text_field(wp_unslash($_POST['entity_type'] ?? $entity['entity_type']));
        $desc = sanitize_textarea_field(wp_unslash($_POST['description'] ?? ''));
        $status = sanitize_text_field(wp_unslash($_POST['status'] ?? $entity['status']));
        $trust = sanitize_text_field(wp_unslash($_POST['trust_level'] ?? 'medium'));
        $notes = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));
        $intent = sanitize_text_field(wp_unslash($_POST['intent'] ?? 'save'));
        $previous_status = (string) ($entity['status'] ?? 'pending');
        if ($intent === 'save_approve') {
            $status = 'approved';
        } elseif ($intent === 'save_reject') {
            $status = 'rejected';
        }

        $key = MBKBS_Database::external_key($entity['site_id'], $type, $name);
        $wpdb->update(
            MBKBS_Database::entities_table(),
            [
                'name' => $name,
                'entity_type' => $type,
                'description' => $desc,
                'status' => $status,
                'trust_level' => $trust,
                'notes' => $notes,
                'external_key' => $key,
                'version' => ((int) $entity['version']) + 1,
                'last_updated' => current_time('mysql', true),
            ],
            ['id' => $id]
        );
        $updated = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . MBKBS_Database::entities_table() . ' WHERE id = %s', $id),
            ARRAY_A
        );
        if (is_array($updated)) {
            MBKBS_Backfill::apply_save_claims($updated, $intent, $previous_status, 'ui');
        }

        $msg = 'Saved.';
        if ($intent === 'save_approve') {
            $msg = 'Saved and approved.';
        } elseif ($intent === 'save_reject') {
            $msg = 'Saved and rejected.';
        }
        if (in_array($intent, ['save_approve', 'save_reject'], true)) {
            $this->redirect('mbkbs-entities', $msg);
        }
        $url = admin_url('admin.php?page=mbkbs-entity&id=' . rawurlencode($id));
        $url = add_query_arg('mbkbs_msg', rawurlencode($msg), $url);
        wp_safe_redirect($url);
        exit;
    }

    public function verify(): void
    {
        global $wpdb;
        $this->assert_admin();
        check_admin_referer('mbkbs_verify');
        $id = sanitize_text_field(wp_unslash($_POST['id'] ?? ''));
        $action = sanitize_text_field(wp_unslash($_POST['action_name'] ?? ''));
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'needs_edit' => 'needs_edit'];
        if (!isset($map[$action])) {
            $this->redirect('mbkbs-entities', 'Bad action.', true);
        }
        $ent = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . MBKBS_Database::entities_table() . ' WHERE id = %s', $id),
            ARRAY_A
        );
        if (!is_array($ent)) {
            $this->redirect('mbkbs-entities', 'Entity not found.', true);
        }
        MBKBS_Backfill::apply_human_decision($ent, $action, 'ui');
        $this->redirect('mbkbs-entities', $this->review_toast($action === 'approve'));
    }

    public function review_entity(): void
    {
        $this->assert_admin();
        check_admin_referer('mbkbs_review_entity');
        $id = sanitize_text_field(wp_unslash($_POST['id'] ?? ''));
        global $wpdb;
        $entity = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . MBKBS_Database::entities_table() . ' WHERE id = %s', $id),
            ARRAY_A
        );
        if (!is_array($entity)) {
            $this->redirect('mbkbs-entities', 'Entity not found.', true);
        }
        $intent = sanitize_text_field(wp_unslash($_POST['intent'] ?? 'save'));
        $submitted = [];
        $extract = [];
        foreach ($_POST as $key => $val) {
            $key = (string) $key;
            if (str_starts_with($key, 'claim:')) {
                $submitted[substr($key, 6)] = is_string($val) ? wp_unslash($val) : '';
            } elseif (str_starts_with($key, 'extract:')) {
                $extract[substr($key, 8)] = is_string($val) ? wp_unslash($val) : '';
            }
        }
        $notes = sanitize_textarea_field(wp_unslash($_POST['notes'] ?? ''));
        if ($notes !== '') {
            $wpdb->update(MBKBS_Database::entities_table(), ['notes' => $notes], ['id' => $id]);
        }
        MBKBS_Backfill::apply_review_from_form($entity, $intent, $submitted, $extract, 'ui');
        $msg = $this->review_toast($intent === 'save_approve');
        if (in_array($intent, ['save_approve', 'save_reject'], true)) {
            $this->redirect('mbkbs-entities', $msg);
        }
        $url = admin_url('admin.php?page=mbkbs-entity&id=' . rawurlencode($id));
        $url = add_query_arg('mbkbs_msg', rawurlencode($msg), $url);
        wp_safe_redirect($url);
        exit;
    }

    public function bulk_verify(): void
    {
        global $wpdb;
        $this->assert_admin();
        check_admin_referer('mbkbs_bulk_verify');
        $action = sanitize_text_field(wp_unslash($_POST['action_name'] ?? 'approve'));
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'needs_edit' => 'needs_edit'];
        if (!isset($map[$action])) {
            $action = 'approve';
        }
        $ids = isset($_POST['entity_ids']) && is_array($_POST['entity_ids']) ? array_map('sanitize_text_field', wp_unslash($_POST['entity_ids'])) : [];
        $confirm = sanitize_text_field(wp_unslash($_POST['confirm_diffs'] ?? '')) === '1';
        $approved_n = 0;
        $review_ids = [];
        foreach ($ids as $id) {
            $ent = $wpdb->get_row(
                $wpdb->prepare('SELECT * FROM ' . MBKBS_Database::entities_table() . ' WHERE id = %s', $id),
                ARRAY_A
            );
            if (!is_array($ent)) {
                continue;
            }
            if ($action === 'approve' && !$confirm && !MBKBS_Diff::can_bulk_approve((string) $ent['id'])) {
                $review_ids[] = (string) $ent['id'];
                continue;
            }
            MBKBS_Backfill::apply_human_decision($ent, $action, 'ui');
            $approved_n++;
        }
        if ($action === 'approve' && $review_ids) {
            $url = admin_url('admin.php?page=mbkbs-bulk-review');
            $url = add_query_arg('ids', implode(',', $review_ids), $url);
            $extra = $approved_n ? sprintf('Approved %d new entities. ', $approved_n) : '';
            $url = add_query_arg('mbkbs_msg', rawurlencode($extra . 'Review claim diffs before bulk-approving previously published items.'), $url);
            wp_safe_redirect($url);
            exit;
        }
        $this->redirect('mbkbs-entities', sprintf('Updated %d entities.', $approved_n));
    }

    private function review_toast(bool $publish_intent): string
    {
        if (!$publish_intent) {
            return 'saved, not published';
        }
        return 'Published · ' . untrailingslashit(home_url('/')) . '/llms.txt';
    }

    public function publish(): void
    {
        $this->assert_admin();
        check_admin_referer('mbkbs_publish');
        flush_rewrite_rules(false);
        $write = !empty($_POST['write_files']);
        if ($write) {
            $result = MBKBS_Publisher::write_static_files();
            if (empty($result['ok'])) {
                $this->redirect('mbkbs', $result['error'] ?? 'Publish failed.', true);
            }
            $this->redirect(
                'mbkbs',
                sprintf(
                    'Published %d approved entities. Files written: %s. Also available via rewrite URLs (/llms.txt, /graph.json).',
                    (int) ($result['entity_count'] ?? 0),
                    implode(', ', $result['files'] ?? [])
                )
            );
        }
        $payload = MBKBS_Publisher::build_payload();
        $this->redirect(
            'mbkbs',
            sprintf(
                'Publish ready: %d approved entities. Visit %s/llms.txt and %s/graph.json (rewrite endpoints). Optional: re-publish with “Write static files”.',
                $payload['entity_count'],
                untrailingslashit(home_url()),
                untrailingslashit(home_url())
            )
        );
    }

    public function manual_entity(): void
    {
        global $wpdb;
        $this->assert_admin();
        check_admin_referer('mbkbs_manual_entity');
        $site_id = sanitize_text_field(wp_unslash($_POST['site_id'] ?? ''));
        $site = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . MBKBS_Database::sites_table() . ' WHERE id = %s', $site_id));
        if (!$site) {
            $this->redirect('mbkbs', 'Site not found.', true);
        }
        $item = [
            'entity_type' => sanitize_text_field(wp_unslash($_POST['entity_type'] ?? 'capability')),
            'name' => sanitize_text_field(wp_unslash($_POST['name'] ?? '')),
            'description' => sanitize_textarea_field(wp_unslash($_POST['description'] ?? '')),
            'source' => 'manual',
            'trust_level' => 'high',
            'evidence' => [['url' => home_url('/'), 'snippet' => 'Manual entry', 'kind' => 'manual']],
        ];
        if ($item['name'] === '') {
            $this->redirect('mbkbs', 'Name required.', true);
        }
        $this->upsert_entity($site_id, $item);
        if (!empty($_POST['approve_immediately'])) {
            $key = MBKBS_Database::external_key($site_id, $item['entity_type'], $item['name']);
            $ent = $wpdb->get_row(
                $wpdb->prepare(
                    'SELECT * FROM ' . MBKBS_Database::entities_table() . ' WHERE site_id = %s AND external_key = %s',
                    $site_id,
                    $key
                ),
                ARRAY_A
            );
            if (is_array($ent)) {
                MBKBS_Backfill::apply_human_decision($ent, 'approve', 'ui');
            }
        }
        $this->redirect('mbkbs-entities', 'Entity created.');
    }

    private function assert_admin(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Forbidden', 'manifest-bkbs'));
        }
    }

    private function redirect(string $page, string $message, bool $error = false): void
    {
        $url = admin_url('admin.php?page=' . $page);
        $url = add_query_arg($error ? 'mbkbs_err' : 'mbkbs_msg', rawurlencode($message), $url);
        wp_safe_redirect($url);
        exit;
    }
}
