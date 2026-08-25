<?php
declare(strict_types=1);

namespace Bkbs\Exports;

final class GraphJson
{
    /**
     * @param array<string, mixed> $site
     * @param list<array<string, mixed>> $entities
     * @return array<string, mixed>
     */
    public static function build(array $site, array $entities): array
    {
        return [
            'bkbs_version' => '1.0',
            'generated_at' => gmdate('c'),
            'site' => [
                'id' => $site['id'],
                'name' => $site['name'],
                'base_url' => $site['base_url'],
            ],
            'entity_count' => count($entities),
            'entities' => $entities,
        ];
    }
}
