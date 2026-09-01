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
        return self::buildResolved($ent, $claims);
    }

    public static function isPublicEnvelope(?string $status): bool
    {
        return in_array((string) $status, ['approved', 'needs_edit', 'stale'], true);
    }

    /**
     * Stage 4a: batch-resolve a site's publication set.
     *
     * @return list<array<string, mixed>>
     */
    public static function resolveSite(\PDO $pdo, string $siteId, bool $includePending = false): array
    {
        if ($includePending) {
            $sql = "SELECT * FROM entities WHERE site_id = ? AND status IN ('approved','pending','needs_edit','stale') ORDER BY entity_type, name";
        } else {
            $sql = "SELECT * FROM entities WHERE site_id = ? AND status IN ('approved','needs_edit','stale') ORDER BY entity_type, name";
        }
        $st = $pdo->prepare($sql);
        $st->execute([$siteId]);
        $ents = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if (!$includePending) {
            $ents = array_values(array_filter(
                $ents,
                static fn($e) => self::isPublicEnvelope($e['status'] ?? null)
            ));
        }
        $ids = array_map(static fn($e) => (string) $e['id'], $ents);
        $byId = self::latestApprovedClaimsForIds($pdo, $ids);
        $out = [];
        foreach ($ents as $ent) {
            $eid = (string) $ent['id'];
            $out[] = self::buildResolved($ent, $byId[$eid] ?? []);
        }
        return $out;
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

    /**
     * @param list<string> $entityIds
     * @return array<string, array<string, array<string, mixed>>>
     */
    private static function latestApprovedClaimsForIds(\PDO $pdo, array $entityIds): array
    {
        if ($entityIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($entityIds), '?'));
        $st = $pdo->prepare("SELECT * FROM claims WHERE entity_id IN ($placeholders) AND status = ?");
        $st->execute([...$entityIds, 'approved']);
        $out = [];
        while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
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
    private static function buildResolved(array $ent, array $claims): array
    {
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
            'last_updated' => $ent['last_updated'] ?? null,
            'created_at' => $ent['created_at'] ?? null,
            'site_id' => (string) ($ent['site_id'] ?? ''),
        ];
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

        foreach (['trust_level', 'source'] as $attr) {
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

    public static function insertApprovedClaim(
        \PDO $pdo,
        string $entityId,
        string $entityType,
        string $attribute,
        string $value,
        string $extractionMethod = 'manual',
        ?string $approvedBy = null
    ): int {
        $approved = self::latestClaim($pdo, $entityId, $attribute, 'approved');
        $pending = self::latestClaim($pdo, $entityId, $attribute, 'pending');
        $supersedesId = null;
        if ($approved) {
            $supersedesId = (int) $approved['id'];
            $pdo->prepare('UPDATE claims SET status = ? WHERE id = ?')->execute(['superseded', (int) $approved['id']]);
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
             VALUES(?,?,?,?,NULL,?,NULL,?,?,?,?,?,NULL)'
        )->execute([
            $entityId,
            $entityType,
            $attribute,
            $value,
            substr($extractionMethod, 0, 32),
            'approved',
            $supersedesId,
            $now,
            $approvedBy,
            $now,
        ]);
        return 1;
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed>|null $surface
     */
    public static function writeSurfaceClaims(
        \PDO $pdo,
        array $entity,
        string $claimStatus,
        string $extractionMethod = 'manual',
        ?string $approvedBy = null,
        ?array $surface = null
    ): int {
        $src = $surface ?? $entity;
        $eid = (string) $entity['id'];
        $etype = (string) ($entity['entity_type'] ?? 'unknown');
        $method = substr($extractionMethod, 0, 32);
        $n = 0;
        foreach (self::scanAttributePairs($src) as [$attr, $value]) {
            if ($claimStatus === 'approved') {
                $existing = self::latestClaim($pdo, $eid, $attr, 'approved');
                if ($existing && (string) $existing['value'] === $value) {
                    $pending = self::latestClaim($pdo, $eid, $attr, 'pending');
                    if ($pending) {
                        $pdo->prepare('UPDATE claims SET status = ? WHERE id = ?')->execute(['superseded', (int) $pending['id']]);
                    }
                    continue;
                }
                $n += self::insertApprovedClaim($pdo, $eid, $etype, $attr, $value, $method, $approvedBy);
                continue;
            }
            if ($claimStatus === 'pending') {
                $pending = self::latestClaim($pdo, $eid, $attr, 'pending');
                if ($pending && (string) $pending['value'] === $value) {
                    continue;
                }
                $approved = self::latestClaim($pdo, $eid, $attr, 'approved');
                if ($approved && (string) $approved['value'] === $value && !$pending) {
                    continue;
                }
                $n += self::insertPendingClaim($pdo, $eid, $etype, $attr, $value, $method);
            }
        }
        return $n;
    }

    /** @param array<string, mixed> $entity */
    public static function promotePendingClaims(\PDO $pdo, array $entity, ?string $approvedBy = null): int
    {
        $eid = (string) $entity['id'];
        $st = $pdo->prepare('SELECT * FROM claims WHERE entity_id = ? AND status = ? ORDER BY id ASC');
        $st->execute([$eid, 'pending']);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        $latest = [];
        foreach ($rows as $row) {
            $attr = (string) $row['attribute'];
            $prev = $latest[$attr] ?? null;
            if ($prev === null || (int) $row['id'] > (int) $prev['id']) {
                $latest[$attr] = $row;
            }
        }
        $now = gmdate('c');
        $n = 0;
        $upd = $pdo->prepare(
            'UPDATE claims SET status = ?, approved_at = ?, approved_by = ?, supersedes_id = COALESCE(supersedes_id, ?) WHERE id = ?'
        );
        foreach ($latest as $attr => $pending) {
            foreach ($rows as $other) {
                if ((string) $other['attribute'] === $attr && (int) $other['id'] !== (int) $pending['id']) {
                    $pdo->prepare('UPDATE claims SET status = ? WHERE id = ?')->execute(['superseded', (int) $other['id']]);
                }
            }
            $prior = self::latestClaim($pdo, $eid, $attr, 'approved');
            $supersedes = $pending['supersedes_id'] ?? null;
            if ($prior && (int) $prior['id'] !== (int) $pending['id']) {
                $pdo->prepare('UPDATE claims SET status = ? WHERE id = ?')->execute(['superseded', (int) $prior['id']]);
                if ($supersedes === null) {
                    $supersedes = (int) $prior['id'];
                }
            }
            $upd->execute(['approved', $now, $approvedBy, $supersedes, (int) $pending['id']]);
            $n++;
        }
        return $n;
    }

    /** @param array<string, mixed> $entity */
    public static function seedMissingApprovedClaims(\PDO $pdo, array $entity, ?string $approvedBy = null): int
    {
        $eid = (string) $entity['id'];
        $etype = (string) ($entity['entity_type'] ?? 'unknown');
        $method = substr((string) ($entity['source'] ?? 'manual'), 0, 32);
        $n = 0;
        foreach (self::scanAttributePairs($entity) as [$attr, $value]) {
            if (self::latestClaim($pdo, $eid, $attr, 'approved')) {
                continue;
            }
            $n += self::insertApprovedClaim($pdo, $eid, $etype, $attr, $value, $method, $approvedBy);
        }
        return $n;
    }

    /** @param array<string, mixed> $entity */
    public static function rejectPendingClaims(\PDO $pdo, array $entity): int
    {
        $st = $pdo->prepare('UPDATE claims SET status = ? WHERE entity_id = ? AND status = ?');
        $st->execute(['rejected', (string) $entity['id'], 'pending']);
        return $st->rowCount();
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed>|null $surface
     * @return array{claims_written:int,claims_promoted:int,claims_rejected:int}
     */
    public static function applyHumanDecision(
        \PDO $pdo,
        array &$entity,
        string $action,
        ?string $approvedBy = null,
        ?array $surface = null
    ): array {
        $stats = ['claims_written' => 0, 'claims_promoted' => 0, 'claims_rejected' => 0];
        $eid = (string) $entity['id'];
        if ($action === 'approve') {
            if ($surface !== null) {
                $stats['claims_written'] = self::writeSurfaceClaims(
                    $pdo,
                    $entity,
                    'approved',
                    (string) ($surface['source'] ?? 'manual'),
                    $approvedBy,
                    $surface
                );
            } else {
                $stats['claims_promoted'] = self::promotePendingClaims($pdo, $entity, $approvedBy);
                $stats['claims_written'] = self::seedMissingApprovedClaims($pdo, $entity, $approvedBy);
            }
            $entity['status'] = 'approved';
            $pdo->prepare('UPDATE entities SET status = ?, last_updated = ? WHERE id = ?')
                ->execute(['approved', gmdate('c'), $eid]);
        } elseif ($action === 'reject') {
            $stats['claims_rejected'] = self::rejectPendingClaims($pdo, $entity);
            if (self::hasApprovedClaims($pdo, $eid)) {
                $entity['status'] = 'approved';
                $pdo->prepare('UPDATE entities SET status = ?, last_updated = ? WHERE id = ?')
                    ->execute(['approved', gmdate('c'), $eid]);
            } else {
                $entity['status'] = 'rejected';
                $pdo->prepare('UPDATE entities SET status = ?, last_updated = ? WHERE id = ?')
                    ->execute(['rejected', gmdate('c'), $eid]);
            }
        } elseif ($action === 'needs_edit') {
            $entity['status'] = 'needs_edit';
            $pdo->prepare('UPDATE entities SET status = ?, last_updated = ? WHERE id = ?')
                ->execute(['needs_edit', gmdate('c'), $eid]);
        }
        return $stats;
    }

    /**
     * @param array<string, mixed> $entity
     * @return array{claims_written:int,claims_promoted:int,claims_rejected:int}
     */
    public static function applySaveClaims(
        \PDO $pdo,
        array &$entity,
        string $intent,
        string $previousStatus,
        ?string $approvedBy = null
    ): array {
        if ($intent === 'save_reject') {
            return self::applyHumanDecision($pdo, $entity, 'reject', $approvedBy);
        }
        if ($intent === 'save_approve' || ($entity['status'] ?? '') === 'approved') {
            return self::applyHumanDecision($pdo, $entity, 'approve', $approvedBy, $entity);
        }
        $n = self::writeSurfaceClaims(
            $pdo,
            $entity,
            'pending',
            (string) ($entity['source'] ?? 'manual'),
            $approvedBy,
            $entity
        );
        $envelope = (string) ($entity['status'] ?? '');
        if ($n > 0 && $previousStatus === 'approved' && !in_array($envelope, ['pending', 'rejected'], true)) {
            $entity['status'] = 'needs_edit';
            $pdo->prepare('UPDATE entities SET status = ?, last_updated = ? WHERE id = ?')
                ->execute(['needs_edit', gmdate('c'), (string) $entity['id']]);
        }
        return ['claims_written' => $n, 'claims_promoted' => 0, 'claims_rejected' => 0];
    }

    public static function hasApprovedClaims(\PDO $pdo, string $entityId): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM claims WHERE entity_id = ? AND status = ? LIMIT 1');
        $st->execute([$entityId, 'approved']);
        return (bool) $st->fetchColumn();
    }

    public static function canBulkApprove(\PDO $pdo, string $entityId): bool
    {
        return !self::hasApprovedClaims($pdo, $entityId);
    }

    public static function attributeLabel(string $attr): string
    {
        if (str_starts_with($attr, self::PROP_PREFIX)) {
            return substr($attr, strlen(self::PROP_PREFIX));
        }
        return ucwords(str_replace('_', ' ', $attr));
    }

    public static function displayValue(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        $decoded = self::decodeClaimValue($raw);
        if (is_array($decoded)) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: $raw;
        }
        return (string) $decoded;
    }

    /**
     * @param list<string> $entityIds
     * @return array<string, array<string, mixed>>
     */
    public static function claimDiffsForIds(\PDO $pdo, array $entityIds): array
    {
        $out = [];
        foreach ($entityIds as $eid) {
            $out[$eid] = self::claimDiff($pdo, $eid);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public static function claimDiff(\PDO $pdo, string $entityId): array
    {
        $approved = [];
        $st = $pdo->prepare('SELECT * FROM claims WHERE entity_id = ? AND status = ?');
        $st->execute([$entityId, 'approved']);
        while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
            $attr = (string) $row['attribute'];
            $prev = $approved[$attr] ?? null;
            if ($prev === null || (int) $row['id'] > (int) $prev['id']) {
                $approved[$attr] = $row;
            }
        }
        $pending = [];
        $st->execute([$entityId, 'pending']);
        while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
            $attr = (string) $row['attribute'];
            $prev = $pending[$attr] ?? null;
            if ($prev === null || (int) $row['id'] > (int) $prev['id']) {
                $pending[$attr] = $row;
            }
        }
        $attrs = array_values(array_unique(array_merge(array_keys($approved), array_keys($pending))));
        usort($attrs, static function (string $a, string $b): int {
            $order = ['name' => 0, 'description' => 1, 'trust_level' => 2, 'source' => 3];
            $ra = $order[$a] ?? (str_starts_with($a, 'prop:') ? 10 : 20);
            $rb = $order[$b] ?? (str_starts_with($b, 'prop:') ? 10 : 20);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return $a <=> $b;
        });
        $changes = [];
        $unchanged = [];
        foreach ($attrs as $attr) {
            $old = $approved[$attr] ?? null;
            $new = $pending[$attr] ?? null;
            $oldRaw = $old['value'] ?? null;
            $newRaw = $new['value'] ?? null;
            if ($new === null) {
                $unchanged[] = [
                    'attribute' => $attr,
                    'label' => self::attributeLabel($attr),
                    'value' => (string) $oldRaw,
                    'display' => self::displayValue($oldRaw !== null ? (string) $oldRaw : null),
                ];
                continue;
            }
            if ($old !== null && (string) $oldRaw === (string) $newRaw) {
                $unchanged[] = [
                    'attribute' => $attr,
                    'label' => self::attributeLabel($attr),
                    'value' => (string) $oldRaw,
                    'display' => self::displayValue((string) $oldRaw),
                ];
                continue;
            }
            $kind = $old === null ? 'new' : 'changed';
            $changes[] = [
                'attribute' => $attr,
                'label' => self::attributeLabel($attr),
                'kind' => $kind,
                'old' => $oldRaw,
                'new' => (string) $newRaw,
                'old_display' => $oldRaw !== null ? self::displayValue((string) $oldRaw) : null,
                'new_display' => self::displayValue($newRaw !== null ? (string) $newRaw : null),
                'extraction_method' => (string) ($new['extraction_method'] ?? 'scan'),
            ];
        }
        $isNew = $approved === [];
        $n = count($changes);
        if ($isNew && $n > 0) {
            $summary = 'New entity · ' . $n . ' fact' . ($n === 1 ? '' : 's');
        } elseif ($n > 0) {
            $labels = array_map(static fn($c) => $c['label'], array_slice($changes, 0, 3));
            $summary = $n . ' change' . ($n === 1 ? '' : 's') . ': ' . implode(', ', $labels);
        } else {
            $summary = 'No pending changes';
        }
        $name = $pending['name']['value'] ?? $approved['name']['value'] ?? '';
        return [
            'entity_id' => $entityId,
            'is_new_entity' => $isNew,
            'has_pending' => $pending !== [],
            'can_bulk_approve' => $isNew,
            'summary' => $summary,
            'display_name' => (string) $name,
            'changes' => $changes,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, string> $submitted
     * @param array<string, string> $extract
     * @return array{claims_written:int,claims_promoted:int,claims_rejected:int}
     */
    public static function applyReviewFromForm(
        \PDO $pdo,
        array &$entity,
        string $intent,
        array $submitted,
        array $extract,
        ?string $approvedBy = null
    ): array {
        if ($intent === 'save_reject') {
            return self::applyHumanDecision($pdo, $entity, 'reject', $approvedBy);
        }
        $stats = ['claims_written' => 0, 'claims_promoted' => 0, 'claims_rejected' => 0];
        $eid = (string) $entity['id'];
        $etype = (string) ($entity['entity_type'] ?? 'unknown');
        $previous = (string) ($entity['status'] ?? '');
        foreach ($submitted as $attr => $value) {
            if (array_key_exists($attr, $extract) && $extract[$attr] === $value) {
                continue;
            }
            if ($intent === 'save_approve') {
                $stats['claims_written'] += self::insertApprovedClaim(
                    $pdo, $eid, $etype, $attr, $value, 'manual', $approvedBy
                );
            } else {
                $stats['claims_written'] += self::insertPendingClaim(
                    $pdo, $eid, $etype, $attr, $value, 'manual'
                );
            }
        }
        if ($intent === 'save_approve') {
            $stats['claims_promoted'] = self::promotePendingClaims($pdo, $entity, $approvedBy);
            $stats['claims_written'] += self::seedMissingApprovedClaims($pdo, $entity, $approvedBy);
            $entity['status'] = 'approved';
            $pdo->prepare('UPDATE entities SET status = ?, last_updated = ? WHERE id = ?')
                ->execute(['approved', gmdate('c'), $eid]);
        } elseif ($stats['claims_written'] > 0 && $previous === 'approved') {
            $entity['status'] = 'needs_edit';
            $pdo->prepare('UPDATE entities SET status = ?, last_updated = ? WHERE id = ?')
                ->execute(['needs_edit', gmdate('c'), $eid]);
        }
        if (isset($submitted['name']) && $submitted['name'] !== '') {
            $pdo->prepare('UPDATE entities SET name = ? WHERE id = ?')->execute([$submitted['name'], $eid]);
            $entity['name'] = $submitted['name'];
        }
        return $stats;
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
