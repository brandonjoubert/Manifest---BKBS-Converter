<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Stage 4c: honest knowledge index. No stub protocol, no A2A endpoint.
 */
final class MBKBS_Export_Agent
{
    /**
     * @return array<string, mixed>
     */
    public static function build(string $name, string $home): array
    {
        $base = untrailingslashit($home);
        return [
            'name' => $name,
            'url' => $home,
            'knowledge' => [
                'llms_txt' => $base . '/llms.txt',
                'llms_full' => $base . '/llms-full.txt',
                'graph' => $base . '/graph.json',
                'schema_organization' => $base . '/schema/organization.jsonld',
                'schema_services' => $base . '/schema/services.jsonld',
            ],
        ];
    }
}
