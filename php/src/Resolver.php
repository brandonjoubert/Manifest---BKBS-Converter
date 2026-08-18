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

    /**
     * Stage 3: scan attribute pairs (omit status).
     *
     * @param array<string, mixed> $entity
     * @return list<array{0:string,1:string}>
     */
    public static function scanAttributePairs(array $entity): array
    {
        $out = [];
        foreach (self::entityAttributePairs($entity) as [$attr, $value]) {
            if ($attr === 'status') {
                continue;
            }
            $out[] = [$attr, $value];
        }
        return $out;
    }

    public static function latestClaim(\PDO $pdo, string $entityId, string $attribute, string $status): ?array
    {
        $st = $pdo->prepare(
            'SELECT * FROM claims WHERE entity_id = ? AND attribute = ? AND status = ? ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$entityId, $attribute, $status]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Insert pending claim; supersede prior pending; point supersedes_id at approved if any.
     *
     * @return int claims inserted (0 or 1)
     */
    public static function insertPendingClaim(
        \PDO $pdo,
        string $entityId,
        string $entityType,
        string $attribute,
        string $value,
        string $extractionMethod = 'scan'
    ): int {
        $approved = self::latestClaim($pdo, $entityId, $attribute, 'approved');
        $pending = self::latestClaim($pdo, $entityId, $attribute, 'pending');
        $supersedesId = null;
        if ($approved) {
            $supersedesId = (int) $approved['id'];
        }
        if ($pending) {
            $pdo->prepare('UPDATE claims SET status = ? WHERE id = ?')->execute(['superseded', (int) $pending['id']]);
            if ($supersedesId === null) {
                $supersedesId = (int) $pending['id'];
            }
        }
        $now = gmdate('c');
        $pdo->prepare(
            'INSERT INTO claims(entity_id, entity_type, attribute, value, source_url, extraction_method, confidence, status, supersedes_id, created_at, approved_by, approved_at, review_due_at)
             VALUES(?,?,?,?,NULL,?,NULL,?,?,?,?,NULL,NULL)'
        )->execute([
            $entityId,
            $entityType,
            $attribute,
            $value,
            substr($extractionMethod, 0, 32),
            'pending',
            $supersedesId,
            $now,
            null,
        ]);
        return 1;
    }

    /**
     * Baseline encoded value: approved claim else entity pair map.
     *
     * @param array<string, mixed> $entity
     */
    public static function baselineEncoded(\PDO $pdo, array $entity, string $attribute): ?string
    {
        $eid = (string) ($entity['id'] ?? '');
        if ($eid !== '') {
            $approved = self::latestClaim($pdo, $eid, $attribute, 'approved');
            if ($approved) {
                return (string) $approved['value'];
            }
        }
        foreach (self::entityAttributePairs($entity) as [$attr, $value]) {
            if ($attr === $attribute) {
                return $value;
            }
        }
        return null;
    }

    /**
     * Propose pending claims from extract vs baseline. Does not mutate entity attrs.
     *
     * @param array<string, mixed> $entity full entity row
     * @param array<string, mixed> $extract scan item
     * @return array{claims_created:int,claims_unchanged:int}
     */
    public static function proposeClaimsFromExtract(\PDO $pdo, array $entity, array $extract): array
    {
        $created = 0;
        $unchanged = 0;
        $eid = (string) $entity['id'];
        $etype = (string) ($entity['entity_type'] ?? 'unknown');
        $method = substr((string) ($extract['source'] ?? 'scan'), 0, 32);
        foreach (self::scanAttributePairs($extract) as [$attr, $incoming]) {
            $current = self::baselineEncoded($pdo, $entity, $attr);
            if ($current !== null && $current === $incoming) {
                $unchanged++;
                continue;
            }
            if ($attr === 'description' && $incoming === '') {
                $unchanged++;
                continue;
            }
            $created += self::insertPendingClaim($pdo, $eid, $etype, $attr, $incoming, $method);
        }
        return ['claims_created' => $created, 'claims_unchanged' => $unchanged];
    }

    /**
     * @param array<string, mixed> $entity
     */
    public static function seedPendingClaimsForNewEntity(\PDO $pdo, array $entity): int
    {
        $n = 0;
        $eid = (string) $entity['id'];
        $etype = (string) ($entity['entity_type'] ?? 'unknown');
        $method = substr((string) ($entity['source'] ?? 'scan'), 0, 32);
        foreach (self::scanAttributePairs($entity) as [$attr, $value]) {
            $n += self::insertPendingClaim($pdo, $eid, $etype, $attr, $value, $method);
        }
        return $n;
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
