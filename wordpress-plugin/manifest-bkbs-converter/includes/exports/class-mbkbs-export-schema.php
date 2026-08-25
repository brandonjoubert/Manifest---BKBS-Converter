<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class MBKBS_Export_Schema
{
    /**
     * @param list<array<string, mixed>> $entities
     * @return array<string, mixed>
     */
    public static function organization(string $name, string $home, array $entities): array
    {
        $identity_desc = $name;
        foreach ($entities as $e) {
            if ($e['entity_type'] === 'business_identity' && !empty($e['description'])) {
                $identity_desc = $e['description'];
                break;
            }
        }
        return [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $name,
            'url' => $home,
            'description' => $identity_desc,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entities
     * @return list<array<string, mixed>>
     */
    public static function services(array $entities): array
    {
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
        return $services;
    }
}
