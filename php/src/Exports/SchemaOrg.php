<?php
declare(strict_types=1);

namespace Bkbs\Exports;

final class SchemaOrg
{
    /**
     * @param array<string, mixed> $site
     * @param list<array<string, mixed>> $entities
     * @return array<string, mixed>
     */
    public static function organization(array $site, array $entities): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $site['name'],
            'url' => $site['base_url'],
            'description' => self::identityDescription($entities) ?: $site['name'],
        ];
    }

    /**
     * @param array<string, mixed> $site
     * @param list<array<string, mixed>> $entities
     * @return list<array<string, mixed>>
     */
    public static function services(array $site, array $entities): array
    {
        $services = [];
        foreach ($entities as $e) {
            if (in_array($e['entity_type'] ?? '', ['capability', 'product_service'], true)) {
                $services[] = [
                    '@context' => 'https://schema.org',
                    '@type' => 'Service',
                    'name' => $e['name'],
                    'description' => $e['description'] ?? $e['name'],
                ];
            }
        }
        return $services;
    }

    /**
     * @param list<array<string, mixed>> $entities
     */
    public static function identityDescription(array $entities): string
    {
        foreach ($entities as $e) {
            if (($e['entity_type'] ?? '') === 'business_identity' && !empty($e['description'])) {
                return (string) $e['description'];
            }
        }
        return '';
    }
}
