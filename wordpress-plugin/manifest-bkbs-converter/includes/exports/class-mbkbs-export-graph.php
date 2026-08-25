<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class MBKBS_Export_Graph
{
    /**
     * @param list<array<string, mixed>> $entities
     * @return array<string, mixed>
     */
    public static function build(string $name, string $home, array $entities): array
    {
        return [
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
        ];
    }
}
