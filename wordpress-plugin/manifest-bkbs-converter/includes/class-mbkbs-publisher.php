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
            'schema/jsonld-snippet.html' => $payload['jsonld_snippet'],
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
        MBKBS_Export_Robots::merge_file(ABSPATH, MBKBS_Export_Robots::site_from_settings());
        $written[] = 'robots.txt';
        return [
            'ok' => true,
            'files' => $written,
            'entity_count' => $payload['entity_count'],
        ];
    }

    public static function maybe_print_jsonld(): void
    {
        if (is_admin()) {
            return;
        }
        if (MBKBS_Database::get_setting('jsonld.wp_head', '0') !== '1') {
            return;
        }
        if (!is_front_page()) {
            return;
        }
        $payload = self::build_payload();
        echo $payload['jsonld_snippet']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public static function filter_robots_txt(string $output, $public): string
    {
        unset($public);
        $block = MBKBS_Export_Robots::block(MBKBS_Export_Robots::site_from_settings());
        return MBKBS_Export_Robots::replace_block($output, $block);
    }

    /**
     * @return array{llms_txt:string,llms_full:string,graph:string,org:string,services:string,agent:string,entity_count:int}
     */
    public static function build_payload(): array
    {
        $resolved = MBKBS_Resolver::resolve_site(null, false);
        $entities = [];
        foreach ($resolved as $row) {
            $entities[] = [
                'id' => $row['id'],
                'entity_type' => $row['entity_type'],
                'name' => $row['name'],
                'description' => $row['description'],
                'properties' => is_array($row['properties'] ?? null) ? $row['properties'] : [],
                'relationships' => is_array($row['relationships'] ?? null) ? $row['relationships'] : [],
                'evidence' => is_array($row['evidence'] ?? null) ? $row['evidence'] : [],
                'status' => $row['status'],
                'source' => $row['source'],
                'trust_level' => $row['trust_level'],
                'version' => (int) ($row['version'] ?? 1),
            ];
        }

        $name = get_bloginfo('name') ?: 'Business';
        $home = untrailingslashit(home_url('/'));

        $llms_txt = MBKBS_Export_Llms::render_txt($name, $home, $entities);
        $llms_full = MBKBS_Export_Llms::render_full($name, $entities);
        $graph = wp_json_encode(MBKBS_Export_Graph::build($name, $home, $entities), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        $orgArr = MBKBS_Export_Schema::organization($name, $home, $entities);
        $org = wp_json_encode($orgArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        $services_json = wp_json_encode(MBKBS_Export_Schema::services($entities), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        $agent = wp_json_encode(MBKBS_Export_Agent::build($name, $home), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
        $snippet = MBKBS_Export_Jsonld::snippet($orgArr);

        return [
            'llms_txt' => $llms_txt,
            'llms_full' => $llms_full,
            'graph' => $graph,
            'org' => $org,
            'services' => $services_json,
            'agent' => $agent,
            'jsonld_snippet' => $snippet,
            'entity_count' => count($entities),
        ];
    }
}
