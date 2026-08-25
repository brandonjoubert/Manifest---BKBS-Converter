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
        return self::build_resolved($ent, $claims);
    }

    public static function is_public_envelope(?string $status): bool
    {
        return in_array((string) $status, ['approved', 'needs_edit', 'stale'], true);
    }

    /**
     * Stage 4a: batch-resolve the WordPress site's public (or draft) set.
     *
     * @return list<array<string, mixed>>
     */
    public static function resolve_site(?string $site_id = null, bool $include_pending = false): array
    {
        global $wpdb;
        $table = MBKBS_Database::entities_table();
        if ($include_pending) {
            $status_sql = "status IN ('approved','pending','needs_edit','stale')";
        } else {
            $status_sql = "status IN ('approved','needs_edit','stale')";
        }
        if ($site_id) {
            $ents = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE site_id = %s AND {$status_sql} ORDER BY entity_type, name",
                    $site_id
                ),
                ARRAY_A
            ) ?: [];
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $ents = $wpdb->get_results(
                "SELECT * FROM {$table} WHERE {$status_sql} ORDER BY entity_type, name",
                ARRAY_A
            ) ?: [];
        }
        if (!$include_pending) {
            $ents = array_values(array_filter(
                $ents,
                static fn($e) => self::is_public_envelope($e['status'] ?? null)
            ));
        }
        $ids = array_map(static fn($e) => (string) $e['id'], $ents);
        $by_id = self::latest_approved_claims_for_ids($ids);
        $out = [];
        foreach ($ents as $ent) {
            $out[] = self::build_resolved($ent, $by_id[(string) $ent['id']] ?? []);
        }
        return $out;
    }

    /**
     * @param list<string> $entity_ids
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function latest_approved_claims_for_ids(array $entity_ids): array
    {
        global $wpdb;
        if ($entity_ids === []) {
            return [];
        }
        $claims = MBKBS_Database::claims_table();
        $placeholders = implode(',', array_fill(0, count($entity_ids), '%s'));
        $params = array_merge($entity_ids, ['approved']);
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$claims} WHERE entity_id IN ($placeholders) AND status = %s",
                ...$params
            ),
            ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $eid = (string) $row['entity_id'];
            $attr = (string) $row['attribute'];
            $prev = $out[$eid][$attr] ?? null;
            if ($prev === null || (int) $row['id'] > (int) $prev['id']) {
                $out[$eid][$attr] = $row;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $ent
     * @param array<string, array<string, mixed>> $claims
     * @return array<string, mixed>
     */
    private static function build_resolved(array $ent, array $claims): array
    {
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
