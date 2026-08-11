<?php
declare(strict_types=1);

namespace Bkbs;

use PDO;

/**
 * Claim Ledger resolver (Stage 2: real resolve from claims + entity row).
 *
 * Hybrid: claim attributes override entity columns when present;
 * missing claims fall back to entity columns. Unknown id → null.
 */
final class Resolver
{
    private const PROP_PREFIX = 'prop:';

    /**
     * @param string|null $entityId
     * @param string|null $asOf ISO-8601 timestamp (optional filter on approved_at/created_at)
     * @param PDO|null $pdo Required for real resolve; without PDO behaves as Stage 1 stub (null)
     * @return array<string, mixed>|null
     */
    public static function resolveEntity(
        ?string $entityId = null,
        ?string $asOf = null,
        ?PDO $pdo = null
    ): ?array {
        if ($entityId === null || $entityId === '' || $pdo === null) {
            return null;
        }

        $st = $pdo->prepare('SELECT * FROM entities WHERE id = ?');
        $st->execute([$entityId]);
        $ent = $st->fetch(PDO::FETCH_ASSOC);
        if (!$ent) {
            return null;
        }

        $claims = self::latestApprovedClaims($pdo, $entityId, $asOf);

        $properties = self::decodeJsonAssoc($ent['properties'] ?? '{}');
        $relationships = self::decodeJsonList($ent['relationships'] ?? '[]');
        $evidence = self::decodeJsonList($ent['evidence'] ?? '[]');
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
                $properties[$key] = self::decodeClaimValue($raw);
                continue;
            }
            $decoded = self::decodeClaimValue($raw);
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

        // Shape matches fixture/entity rows used by Publisher (graph dumps full arrays).
        // Hybrid identity fields from entity row; claim-backed attrs overlaid above.
        return [
            'id' => (string) $ent['id'],
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
            // last_updated retained for Stage 4/hybrid consumers; Stage 0 normalizer strips it
            'last_updated' => $ent['last_updated'] ?? null,
            // site_id available for callers that need it without polluting graph dump
            'site_id' => (string) ($ent['site_id'] ?? ''),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function latestApprovedClaims(PDO $pdo, string $entityId, ?string $asOf): array
    {
        if ($asOf !== null && $asOf !== '') {
            $sql = 'SELECT * FROM claims WHERE entity_id = ? AND status = ? AND (
                (approved_at IS NOT NULL AND approved_at <= ?)
                OR (approved_at IS NULL AND created_at <= ?)
            )';
            $st = $pdo->prepare($sql);
            $st->execute([$entityId, 'approved', $asOf, $asOf]);
        } else {
            $st = $pdo->prepare(
                'SELECT * FROM claims WHERE entity_id = ? AND status = ?'
            );
            $st->execute([$entityId, 'approved']);
        }
        $byAttr = [];
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $attr = (string) $row['attribute'];
            $prev = $byAttr[$attr] ?? null;
            if ($prev === null || (int) $row['id'] > (int) $prev['id']) {
                $byAttr[$attr] = $row;
            }
        }
        return $byAttr;
    }

    public static function encodeClaimValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    public static function decodeClaimValue(string $raw): mixed
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

    /**
     * Build (attribute, encoded value) pairs for backfill from an entity row.
     *
     * @param array<string, mixed> $entity
     * @return list<array{0:string,1:string}>
     */
    public static function entityAttributePairs(array $entity): array
    {
        $pairs = [];
        $pairs[] = ['name', self::encodeClaimValue((string) ($entity['name'] ?? ''))];

        $desc = $entity['description'] ?? null;
        if ($desc !== null && trim((string) $desc) !== '') {
            $pairs[] = ['description', self::encodeClaimValue((string) $desc)];
        }

        $props = $entity['properties'] ?? [];
        if (is_string($props)) {
            $props = self::decodeJsonAssoc($props);
        }
        if (is_array($props)) {
            foreach ($props as $k => $v) {
                $pairs[] = [self::PROP_PREFIX . (string) $k, self::encodeClaimValue($v)];
            }
        }

        $rel = $entity['relationships'] ?? [];
        if (is_string($rel)) {
            $rel = self::decodeJsonList($rel);
        }
        if (!is_array($rel)) {
            $rel = [];
        }
        $pairs[] = ['relationships', self::encodeClaimValue(array_values($rel))];

        $ev = $entity['evidence'] ?? [];
        if (is_string($ev)) {
            $ev = self::decodeJsonList($ev);
        }
        if (!is_array($ev)) {
            $ev = [];
        }
        $pairs[] = ['evidence', self::encodeClaimValue(array_values($ev))];

        foreach (['trust_level', 'source', 'status'] as $attr) {
            $val = $entity[$attr] ?? null;
            if ($val !== null && (string) $val !== '') {
                $pairs[] = [$attr, self::encodeClaimValue((string) $val)];
            }
        }

        return $pairs;
    }

    /** @return array<string, mixed> */
    private static function decodeJsonAssoc(mixed $raw): array
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
    private static function decodeJsonList(mixed $raw): array
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
