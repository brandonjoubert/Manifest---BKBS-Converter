<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claim Ledger resolver (Stage 2: real resolve from claims + entity row).
 *
 * Hybrid: claim attributes override entity columns when present;
 * missing claims fall back to entity columns. Unknown id → null.
 */
final class MBKBS_Resolver
{
    private const PROP_PREFIX = 'prop:';

    /**
     * @param string|null $entity_id
     * @param string|null $as_of ISO-8601 / MySQL datetime; optional filter
     * @return array<string, mixed>|null
     */
    public static function resolve_entity(?string $entity_id = null, ?string $as_of = null): ?array
    {
        if ($entity_id === null || $entity_id === '') {
            return null;
        }

        global $wpdb;
        $entities = MBKBS_Database::entities_table();
        $ent = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$entities} WHERE id = %s", $entity_id),
            ARRAY_A
        );
        if (!$ent) {
            return null;
        }

        $claims = self::latest_approved_claims($entity_id, $as_of);

        $properties = self::decode_json_assoc($ent['properties'] ?? '{}');
        $relationships = self::decode_json_list($ent['relationships'] ?? '[]');
        $evidence = self::decode_json_list($ent['evidence'] ?? '[]');
        $name = (string) ($ent['name'] ?? '');
        $description = isset($ent['description']) && $ent['description'] !== ''
            ? (string) $ent['description']
            : null;
        $trustLevel = (string) ($ent['trust_level'] ?? 'medium');
        $source = (string) ($ent['source'] ?? 'scan');
        $status = (string) ($ent['status'] ?? 'approved');

        foreach ($claims as $attr => $claim) {
            $raw = (string) ($claim['value'] ?? '');
            if (str_starts_with($attr, self::PROP_PREFIX)) {
                $key = substr($attr, strlen(self::PROP_PREFIX));
                $properties[$key] = self::decode_claim_value($raw);
                continue;
            }
            $decoded = self::decode_claim_value($raw);
            if ($attr === 'name') {
                $name = $decoded === null ? '' : (string) $decoded;
            } elseif ($attr === 'description') {
                $description = ($decoded === null || $decoded === '') ? null : (string) $decoded;
            } elseif ($attr === 'relationships') {
                $relationships = is_array($decoded) ? array_values($decoded) : [];
            } elseif ($attr === 'evidence') {
                $evidence = is_array($decoded) ? array_values($decoded) : [];
            } elseif ($attr === 'trust_level') {
                $trustLevel = $decoded === null ? $trustLevel : (string) $decoded;
            } elseif ($attr === 'source') {
                $source = $decoded === null ? $source : (string) $decoded;
            } elseif ($attr === 'status') {
                $status = $decoded === null ? $status : (string) $decoded;
            }
        }

        return [
            'id' => (string) $ent['id'],
            'site_id' => (string) ($ent['site_id'] ?? ''),
            'external_key' => (string) ($ent['external_key'] ?? ''),
            'entity_type' => (string) ($ent['entity_type'] ?? ''),
            'name' => $name,
            'description' => $description,
            'properties' => $properties,
            'relationships' => $relationships,
            'evidence' => $evidence,
            'version' => (int) ($ent['version'] ?? 1),
            'trust_level' => $trustLevel !== '' ? $trustLevel : 'medium',
            'source' => $source !== '' ? $source : 'scan',
            'status' => $status !== '' ? $status : 'approved',
            'notes' => $ent['notes'] ?? null,
            'last_updated' => $ent['last_updated'] ?? null,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function latest_approved_claims(string $entity_id, ?string $as_of): array
    {
        global $wpdb;
        $claims = MBKBS_Database::claims_table();
        if ($as_of !== null && $as_of !== '') {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$claims} WHERE entity_id = %s AND status = %s AND (
                        (approved_at IS NOT NULL AND approved_at <= %s)
                        OR (approved_at IS NULL AND created_at <= %s)
                    )",
                    $entity_id,
                    'approved',
                    $as_of,
                    $as_of
                ),
                ARRAY_A
            ) ?: [];
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$claims} WHERE entity_id = %s AND status = %s",
                    $entity_id,
                    'approved'
                ),
                ARRAY_A
            ) ?: [];
        }

        $byAttr = [];
        foreach ($rows as $row) {
            $attr = (string) $row['attribute'];
            $prev = $byAttr[$attr] ?? null;
            if ($prev === null || (int) $row['id'] > (int) $prev['id']) {
                $byAttr[$attr] = $row;
            }
        }
        return $byAttr;
    }

    public static function decode_claim_value(string $raw): mixed
    {
        if ($raw === '') {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return $raw;
    }

    /** @return array<string, mixed> */
    private static function decode_json_assoc(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    /** @return list<mixed> */
    private static function decode_json_list(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $d = json_decode($raw, true);
        return is_array($d) ? array_values($d) : [];
    }
}
