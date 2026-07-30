<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Publishes approved BKBS machine layers for WordPress front-end URLs.
 */
final class MBKBS_Publisher
{
    public static function register_rewrites(): void
    {
        add_rewrite_rule('^llms\.txt$', 'index.php?mbkbs_file=llms_txt', 'top');
        add_rewrite_rule('^llms-full\.txt$', 'index.php?mbkbs_file=llms_full', 'top');
        add_rewrite_rule('^graph\.json$', 'index.php?mbkbs_file=graph', 'top');
        add_rewrite_rule('^schema/organization\.jsonld$', 'index.php?mbkbs_file=org', 'top');
        add_rewrite_rule('^schema/services\.jsonld$', 'index.php?mbkbs_file=services', 'top');
        add_rewrite_rule('^\.well-known/agent\.json$', 'index.php?mbkbs_file=agent', 'top');
    }

    public static function query_vars(array $vars): array
    {
        $vars[] = 'mbkbs_file';
        return $vars;
    }

    public static function template_redirect(): void
    {
        $file = get_query_var('mbkbs_file');
        if (!$file) {
            return;
        }
        $payload = self::build_payload();
        switch ($file) {
            case 'llms_txt':
                header('Content-Type: text/plain; charset=utf-8');
                echo $payload['llms_txt'];
                break;
            case 'llms_full':
                header('Content-Type: text/plain; charset=utf-8');
                echo $payload['llms_full'];
                break;
            case 'graph':
                header('Content-Type: application/json; charset=utf-8');
                echo $payload['graph'];
                break;
            case 'org':
                header('Content-Type: application/ld+json; charset=utf-8');
                echo $payload['org'];
                break;
            case 'services':
                header('Content-Type: application/ld+json; charset=utf-8');
                echo $payload['services'];
                break;
            case 'agent':
                header('Content-Type: application/json; charset=utf-8');
                echo $payload['agent'];
                break;
            default:
                status_header(404);
                echo 'Not found';
        }
        exit;
    }

    /**
     * Write static copies under ABSPATH (optional; for hosts that prefer files).
     *
     * @return array{ok:bool,files?:list<string>,error?:string,entity_count?:int}
     */
    public static function write_static_files(): array
    {
        if (!is_writable(ABSPATH)) {
            return ['ok' => false, 'error' => 'WordPress root is not writable: ' . ABSPATH];
        }
        $payload = self::build_payload();
        $map = [
            'llms.txt' => $payload['llms_txt'],
            'llms-full.txt' => $payload['llms_full'],
            'graph.json' => $payload['graph'],
            'schema/organization.jsonld' => $payload['org'],
            'schema/services.jsonld' => $payload['services'],
            '.well-known/agent.json' => $payload['agent'],
        ];
        $written = [];
        foreach ($map as $rel => $content) {
            $path = ABSPATH . $rel;
            $dir = dirname($path);
            if (!is_dir($dir) && !wp_mkdir_p($dir)) {
                return ['ok' => false, 'error' => "Cannot create directory: {$dir}", 'files' => $written];
            }
            if (file_put_contents($path, $content) === false) {
                return ['ok' => false, 'error' => "Cannot write: {$path}", 'files' => $written];
            }
            $written[] = $rel;
        }
        return [
            'ok' => true,
            'files' => $written,
            'entity_count' => $payload['entity_count'],
        ];
    }

    /**
     * @return array{llms_txt:string,llms_full:string,graph:string,org:string,services:string,agent:string,entity_count:int}
     */
    public static function build_payload(): array
    {
        global $wpdb;
        $table = MBKBS_Database::entities_table();
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE status = 'approved' ORDER BY entity_type, name",
            ARRAY_A
        ) ?: [];

        $entities = [];
        foreach ($rows as $row) {
            $entities[] = [
                'id' => $row['id'],
                'entity_type' => $row['entity_type'],
                'name' => $row['name'],
                'description' => $row['description'],
                'properties' => json_decode($row['properties'] ?: '{}', true) ?: [],
                'relationships' => json_decode($row['relationships'] ?: '[]', true) ?: [],
                'evidence' => json_decode($row['evidence'] ?: '[]', true) ?: [],
                'status' => $row['status'],
                'source' => $row['source'],
                'trust_level' => $row['trust_level'],
                'version' => (int) $row['version'],
            ];
        }

        $name = get_bloginfo('name') ?: 'Business';
        $home = untrailingslashit(home_url('/'));

        $llms = ["# {$name}", '', '> Agent-ready knowledge published by Manifest BKBS Converter for WordPress.', '', '## About', "{$name} — {$home}", ''];
        $by = [];
        foreach ($entities as $e) {
            $by[$e['entity_type']][] = $e;
        }
        foreach (['capability' => 'Core Capabilities', 'product_service' => 'Products & Services', 'facility_served' => 'Facilities Served', 'policy' => 'Policies'] as $t => $label) {
            if (empty($by[$t])) {
                continue;
            }
            $llms[] = '## ' . $label;
            foreach ($by[$t] as $e) {
                $desc = trim((string) ($e['description'] ?? ''));
                $desc = $desc !== '' ? ': ' . str_replace("\n", ' ', substr($desc, 0, 160)) : '';
                $llms[] = '- ' . $e['name'] . $desc;
            }
            $llms[] = '';
        }
        $llms[] = '## Documentation';
        $llms[] = '- ' . $home . '/graph.json';
        $llms[] = '';
        $llms[] = '<!-- Generated by Manifest BKBS Converter for WordPress -->';
        $llms_txt = implode("\n", $llms) . "\n";

        $full = ["# {$name} — BKBS Full Dump", '', 'Entities: ' . count($entities), ''];
        foreach ($entities as $e) {
            $full[] = '## ' . $e['name'];
            $full[] = '- type: ' . $e['entity_type'];
            $full[] = '- status: ' . $e['status'];
            if (!empty($e['description'])) {
                $full[] = '- description: ' . $e['description'];
            }
            $full[] = '';
        }
        $llms_full = implode("\n", $full) . "\n";

        $graph = wp_json_encode([
            'bkbs_version' => '1.0',
            'generated_at' => gmdate('c'),
            'site' => [
                'name' => $name,
                'base_url' => $home,
                'platform' => 'wordpress',
                'plugin' => 'manifest-bkbs-converter',
            ],
            'entity_count' => count($entities),
            'entities' => $entities,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

        $identity_desc = $name;
        foreach ($entities as $e) {
            if ($e['entity_type'] === 'business_identity' && !empty($e['description'])) {
                $identity_desc = $e['description'];
                break;
            }
        }
        $org = wp_json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $name,
            'url' => $home,
            'description' => $identity_desc,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

        $services = [];
        foreach ($entities as $e) {
            if (in_array($e['entity_type'], ['capability', 'product_service'], true)) {
                $services[] = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Service',
                    'name' => $e['name'],
                    'description' => $e['description'] ?: $e['name'],
                ];
            }
        }
        $services_json = wp_json_encode($services, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

        $agent = wp_json_encode([
            'name' => $name,
            'url' => $home,
            'protocol' => 'agent-web-protocol-stub',
            'platform' => 'wordpress',
            'knowledge' => [
                'llms_txt' => $home . '/llms.txt',
                'graph' => $home . '/graph.json',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

        return [
            'llms_txt' => $llms_txt,
            'llms_full' => $llms_full,
            'graph' => $graph,
            'org' => $org,
            'services' => $services_json,
            'agent' => $agent,
            'entity_count' => count($entities),
        ];
    }
}
