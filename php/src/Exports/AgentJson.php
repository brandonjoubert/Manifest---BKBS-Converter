<?php
declare(strict_types=1);

namespace Bkbs\Exports;

/**
 * Stage 4b: current knowledge-index stub. Honest A2A card is Stage 4c/9.
 * Do not change bytes in this slice.
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
        return [
            'name' => $site['name'],
            'url' => $site['base_url'],
            'protocol' => 'agent-web-protocol-stub',
            'knowledge' => [
                'llms_txt' => rtrim((string) $site['base_url'], '/') . '/llms.txt',
                'graph' => rtrim((string) $site['base_url'], '/') . '/graph.json',
            ],
        ];
    }
}
