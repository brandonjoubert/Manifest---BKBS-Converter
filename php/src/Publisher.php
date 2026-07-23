<?php
declare(strict_types=1);

namespace Bkbs;

final class Publisher
{
    /**
     * @param list<array<string,mixed>> $entities
     * @return array{ok:bool,root?:string,files?:list<string>,error?:string,entity_count?:int}
     */
    public function publish(array $site, array $entities, string $publishRoot): array
    {
        $raw = trim($publishRoot);
        $localHint = dirname(__DIR__) . '/data/live-public';

        if ($this->looksLikePlaceholder($raw)) {
            $hint = '';
            if (function_exists('bkbs_best_publish_path')) {
                $best = bkbs_best_publish_path();
                if ($best !== '') {
                    $hint = " Detected path on this server: {$best}";
                }
            }
            return [
                'ok' => false,
                'error' => "“{$raw}” is a documentation placeholder, not your real folder. "
                    . 'On this PHP host, open cPanel → File Manager → public_html and copy the full path from the top '
                    . '(it looks like /home/yourcpanelname/public_html — your real account name, not “user”).'
                    . $hint,
            ];
        }

        $root = $this->resolveRoot($raw);
        if ($root === null) {
            return [
                'ok' => false,
                'error' => "Invalid or empty publish root path. "
                    . "Set a real folder path. Local testing: {$localHint}",
            ];
        }

        if (!is_dir($root)) {
            $parent = dirname($root);
            if (!is_dir($parent) && !@mkdir($parent, 0755, true) && !is_dir($parent)) {
                return [
                    'ok' => false,
                    'error' => "Cannot create publish root: {$root}. "
                        . "Parent folder does not exist and could not be created "
                        . '(wrong path or no permission). '
                        . 'Use your real public_html path from the host control panel. '
                        . "Local testing: {$localHint}",
                ];
            }
            if (!@mkdir($root, 0755, true) && !is_dir($root)) {
                return [
                    'ok' => false,
                    'error' => "Cannot create publish root: {$root}. "
                        . 'Check the path is correct and the web server can write there. '
                        . "Local testing: {$localHint}",
                ];
            }
        }
        if (!is_writable($root)) {
            return [
                'ok' => false,
                'error' => "Publish root not writable: {$root}. "
                    . 'Fix folder permissions for the web server user. '
                    . "Local testing: {$localHint}",
            ];
        }

        $approved = array_values(array_filter(
            $entities,
            static fn($e) => ($e['status'] ?? '') === 'approved'
        ));

        $files = [];
        $llms = $this->renderLlms($site, $approved);
        $this->write($root . '/llms.txt', $llms);
        $files[] = 'llms.txt';

        $this->write($root . '/llms-full.txt', $this->renderLlmsFull($site, $approved));
        $files[] = 'llms-full.txt';

        $graph = [
            'bkbs_version' => '1.0',
            'generated_at' => gmdate('c'),
            'site' => [
                'id' => $site['id'],
                'name' => $site['name'],
                'base_url' => $site['base_url'],
            ],
            'entity_count' => count($approved),
            'entities' => $approved,
        ];
        $this->write($root . '/graph.json', json_encode($graph, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        $files[] = 'graph.json';

        @mkdir($root . '/schema', 0755, true);
        @mkdir($root . '/.well-known', 0755, true);
        @mkdir($root . '/bkbs', 0755, true);

        $org = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $site['name'],
            'url' => $site['base_url'],
            'description' => $this->identityDescription($approved) ?: $site['name'],
        ];
        $this->write($root . '/schema/organization.jsonld', json_encode($org, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        $files[] = 'schema/organization.jsonld';

        $services = [];
        foreach ($approved as $e) {
            if (in_array($e['entity_type'] ?? '', ['capability', 'product_service'], true)) {
                $services[] = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Service',
                    'name' => $e['name'],
                    'description' => $e['description'] ?? $e['name'],
                ];
            }
        }
        $this->write($root . '/schema/services.jsonld', json_encode($services, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        $files[] = 'schema/services.jsonld';

        $agent = [
            'name' => $site['name'],
            'url' => $site['base_url'],
            'protocol' => 'agent-web-protocol-stub',
            'knowledge' => [
                'llms_txt' => rtrim($site['base_url'], '/') . '/llms.txt',
                'graph' => rtrim($site['base_url'], '/') . '/graph.json',
            ],
        ];
        $this->write($root . '/.well-known/agent.json', json_encode($agent, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        $files[] = '.well-known/agent.json';

        $this->write($root . '/bkbs/README.txt', "BKBS PHP edition published for {$site['name']}\n");
        $files[] = 'bkbs/README.txt';

        $this->mergeRobots($root, $site['base_url']);
        $files[] = 'robots.txt';

        return [
            'ok' => true,
            'root' => $root,
            'files' => $files,
            'entity_count' => count($approved),
        ];
    }

    private function looksLikePlaceholder(string $raw): bool
    {
        $s = strtolower(str_replace('\\', '/', $raw));
        $markers = [
            '/home/user/',
            '/home/username/',
            '/home/youruser/',
            '/home/your_real_username/',
            'yourdomain',
        ];
        foreach ($markers as $m) {
            if (str_contains($s, $m)) {
                return true;
            }
        }
        return in_array($s, ['/home/user/public_html', '/home/username/public_html'], true);
    }

    private function resolveRoot(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // Absolute
        if ($raw[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $raw)) {
            return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $raw), DIRECTORY_SEPARATOR);
        }
        // Relative to PHP app root
        $base = dirname(__DIR__);
        $full = $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $raw);
        $real = realpath($full);
        return $real !== false ? $real : $full;
    }

    private function write(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $content);
    }

    /** @param list<array<string,mixed>> $entities */
    private function renderLlms(array $site, array $entities): string
    {
        $name = $site['name'];
        $lines = ["# $name", '', "> Business knowledge package for AI agents.", '', '## About', "$name — " . ($site['base_url'] ?? ''), ''];
        $byType = [];
        foreach ($entities as $e) {
            $byType[$e['entity_type'] ?? 'other'][] = $e;
        }
        foreach (['capability' => 'Core Capabilities', 'product_service' => 'Products & Services', 'facility_served' => 'Facilities Served', 'policy' => 'Policies'] as $t => $label) {
            if (empty($byType[$t])) {
                continue;
            }
            $lines[] = '## ' . $label;
            foreach ($byType[$t] as $e) {
                $desc = trim((string) ($e['description'] ?? ''));
                $desc = $desc !== '' ? ': ' . str_replace("\n", ' ', mb_substr($desc, 0, 160)) : '';
                $lines[] = '- ' . $e['name'] . $desc;
            }
            $lines[] = '';
        }
        $lines[] = '## Documentation';
        $lines[] = '- graph.json (full knowledge graph)';
        $lines[] = '';
        $lines[] = '<!-- Generated by BKBS Converter PHP edition -->';
        return implode("\n", $lines) . "\n";
    }

    /** @param list<array<string,mixed>> $entities */
    private function renderLlmsFull(array $site, array $entities): string
    {
        $lines = ["# {$site['name']} — BKBS Full Dump", '', 'Entities: ' . count($entities), ''];
        foreach ($entities as $e) {
            $lines[] = '## ' . ($e['name'] ?? '');
            $lines[] = '- type: ' . ($e['entity_type'] ?? '');
            $lines[] = '- status: ' . ($e['status'] ?? '');
            if (!empty($e['description'])) {
                $lines[] = '- description: ' . $e['description'];
            }
            $lines[] = '';
        }
        return implode("\n", $lines) . "\n";
    }

    /** @param list<array<string,mixed>> $entities */
    private function identityDescription(array $entities): string
    {
        foreach ($entities as $e) {
            if (($e['entity_type'] ?? '') === 'business_identity' && !empty($e['description'])) {
                return (string) $e['description'];
            }
        }
        return '';
    }

    private function mergeRobots(string $root, string $baseUrl): void
    {
        $path = $root . '/robots.txt';
        $marker = '# BEGIN BKBS';
        $end = '# END BKBS';
        $block = "$marker\nUser-agent: *\nAllow: /llms.txt\nAllow: /graph.json\nAllow: /schema/\nAllow: /.well-known/agent.json\nSitemap: " . rtrim($baseUrl, '/') . "/sitemap.xml\n$end\n";
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        if (str_contains($existing, $marker)) {
            $startPos = strpos($existing, $marker);
            $endPos = strpos($existing, $end);
            if ($startPos !== false && $endPos !== false) {
                $endPos += strlen($end);
                $existing = rtrim(substr($existing, 0, $startPos)) . "\n\n" . $block . ltrim(substr($existing, $endPos));
            }
        } else {
            $existing = rtrim($existing) . ($existing !== '' ? "\n\n" : '') . $block;
        }
        $this->write($path, $existing);
    }
}
