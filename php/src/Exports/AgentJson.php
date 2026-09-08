<?php
declare(strict_types=1);

namespace Bkbs\Exports;

/**
 * Stage 4c: honest knowledge index. No stub protocol, no A2A endpoint.
 */
final class AgentJson
{
    /**
     * @param array<string, mixed> $site
     * @param list<array<string, mixed>> $entities
     * @return array<string, mixed>
     */
    public static function build(array $site, array $entities): array
    {
        unset($entities);
        $base = rtrim((string) $site['base_url'], '/');
        return [
            'name' => $site['name'],
            'url' => $site['base_url'],
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
