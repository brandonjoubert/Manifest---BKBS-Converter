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
        add_action('admin_enqueue_scripts', [$this, 'assets']);
        add_action('admin_post_mbkbs_save_settings', [$this, 'save_settings']);
        add_action('admin_post_mbkbs_scan', [$this, 'scan']);
        add_action('admin_post_mbkbs_save_entity', [$this, 'save_entity']);
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
        add_submenu_page('mbkbs', __('Settings', 'manifest-bkbs'), __('Settings', 'manifest-bkbs'), 'manage_options', 'mbkbs-settings', [$this, 'page_settings']);
        add_submenu_page('mbkbs', __('Tools', 'manifest-bkbs'), __('Tools', 'manifest-bkbs'), 'manage_options', 'mbkbs-tools', [$this, 'page_tools']);
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
        include MBKBS_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public function page_entities(): void
    {
        global $wpdb;
        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash((string) $_GET['status'])) : '';
        $sql = 'SELECT * FROM ' . MBKBS_Database::entities_table();
        $params = [];
        if ($status !== '') {
            $sql .= ' WHERE status = %s';
            $params[] = $status;
        }
        $sql .= ' ORDER BY status, entity_type, name LIMIT 500';
        $entities = $params
            ? $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A)
            : $wpdb->get_results($sql, ARRAY_A);
        $entities = $entities ?: [];
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
        include MBKBS_PLUGIN_DIR . 'admin/views/entity-edit.php';
    }

    public function page_settings(): void
    {
        $provider = MBKBS_Database::get_setting('llm.provider', 'openai');
        $base_url = MBKBS_Database::get_setting('llm.base_url', 'https://api.openai.com/v1');
        $model = MBKBS_Database::get_setting('llm.model', 'gpt-4o-mini');
        $enabled = MBKBS_Database::get_setting('llm.enabled', '1') !== '0';
        $key_set = MBKBS_Database::get_setting('llm.api_key', '') !== '';
        $llm_ok = MBKBS_LLM::from_settings() !== null;
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

        try {
            @set_time_limit(300);
            $crawler = new MBKBS_Crawler();
            $pages = $crawler->crawl($site['base_url'], (int) $site['max_pages'], (int) $site['crawl_delay_ms']);
            $extractor = new MBKBS_Extractor();
            $found = $extractor->extract_heuristic($pages);
            $llm = MBKBS_LLM::from_settings();
            if ($llm) {
                try {
                    $found = array_merge($found, $extractor->extract_with_llm($llm, $pages, $site['base_url']));
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
            $this->redirect(
                'mbkbs-entities',
                sprintf(
                    /* translators: 1: pages, 2: entities */
                    __('Scan complete: %1$d pages, %2$d entities touched. Review pending items, edit if needed, then approve.', 'manifest-bkbs'),
                    count($pages),
                    $n
                )
            );
        } catch (Throwable $e) {
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
                    'name' => $name,
                    'description' => $desc,
                    'properties' => $props,
                    'relationships' => $rels,
                    'evidence' => $evid,
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
            MBKBS_Backfill::seed_pending_claims_for_new_entity($row);
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
        $wpdb->update(
            MBKBS_Database::entities_table(),
            ['status' => $map[$action], 'last_updated' => current_time('mysql', true)],
            ['id' => $id]
        );
        $this->redirect('mbkbs-entities', 'Entity updated.');
    }

    public function bulk_verify(): void
    {
        global $wpdb;
        $this->assert_admin();
        check_admin_referer('mbkbs_bulk_verify');
        $action = sanitize_text_field(wp_unslash($_POST['action_name'] ?? 'approve'));
        $map = ['approve' => 'approved', 'reject' => 'rejected', 'needs_edit' => 'needs_edit'];
        $status = $map[$action] ?? 'approved';
        $ids = isset($_POST['entity_ids']) && is_array($_POST['entity_ids']) ? array_map('sanitize_text_field', wp_unslash($_POST['entity_ids'])) : [];
        foreach ($ids as $id) {
            $wpdb->update(
                MBKBS_Database::entities_table(),
                ['status' => $status, 'last_updated' => current_time('mysql', true)],
                ['id' => $id]
            );
        }
        $this->redirect('mbkbs-entities', sprintf('Updated %d entities.', count($ids)));
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
            $wpdb->update(
                MBKBS_Database::entities_table(),
                ['status' => 'approved', 'last_updated' => current_time('mysql', true)],
                ['site_id' => $site_id, 'external_key' => $key]
            );
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
