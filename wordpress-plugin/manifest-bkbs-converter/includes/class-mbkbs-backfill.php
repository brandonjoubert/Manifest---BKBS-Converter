<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claim Ledger Stage 2 — backfill approved claims from entity rows (WordPress).
 */
final class MBKBS_Backfill
{
    private const PROP_PREFIX = 'prop:';

    /**
     * @param array{site_id?:string,include_pending?:bool,dry_run?:bool,update?:bool} $args
     * @return array{entities:int,inserted:int,skipped:int,superseded:int}
     */
    public static function run(array $args = []): array
    {
        global $wpdb;
        $siteId = isset($args['site_id']) ? (string) $args['site_id'] : '';
        $includePending = !empty($args['include_pending']);
        $dryRun = !empty($args['dry_run']);
        $update = !empty($args['update']);

        $table = MBKBS_Database::entities_table();
        $sql = "SELECT * FROM {$table}";
        $where = [];
        $params = [];
        if ($siteId !== '') {
            $where[] = 'site_id = %s';
            $params[] = $siteId;
        }
        if ($includePending) {
            $where[] = "status IN ('approved','pending','needs_edit')";
        } else {
            $where[] = "status = 'approved'";
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY entity_type, name';

        if ($params) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $entities = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $entities = $wpdb->get_results($sql, ARRAY_A) ?: [];
        }

        $totals = ['entities' => 0, 'inserted' => 0, 'skipped' => 0, 'superseded' => 0];
        $claims = MBKBS_Database::claims_table();

        foreach ($entities as $ent) {
            $totals['entities']++;
            $pairs = self::entity_attribute_pairs($ent);
            $entityId = (string) $ent['id'];
            $entityType = (string) ($ent['entity_type'] ?? 'unknown');
            $extraction = substr((string) ($ent['source'] ?? 'scan'), 0, 32);
            $approvedAt = (string) ($ent['last_updated'] ?? $ent['created_at'] ?? current_time('mysql', true));

            foreach ($pairs as [$attr, $value]) {
                $existing = $wpdb->get_row(
                    $wpdb->prepare(
                        "SELECT id, value FROM {$claims} WHERE entity_id = %s AND attribute = %s AND status = %s ORDER BY id DESC LIMIT 1",
                        $entityId,
                        $attr,
                        'approved'
                    ),
                    ARRAY_A
                );

                if ($existing) {
                    if ((string) $existing['value'] === $value) {
                        $totals['skipped']++;
                        continue;
                    }
                    if (!$update) {
                        $totals['skipped']++;
                        continue;
                    }
                    if (!$dryRun) {
                        $wpdb->update(
                            $claims,
                            ['status' => 'superseded'],
                            ['id' => (int) $existing['id']]
                        );
                        $wpdb->insert(
                            $claims,
                            [
                                'entity_id' => $entityId,
                                'entity_type' => $entityType,
                                'attribute' => $attr,
                                'value' => $value,
                                'extraction_method' => $extraction,
                                'status' => 'approved',
                                'supersedes_id' => (int) $existing['id'],
                                'created_at' => $approvedAt,
                                'approved_by' => 'backfill',
                                'approved_at' => $approvedAt,
                            ]
                        );
                    }
                    $totals['superseded']++;
                    $totals['inserted']++;
                    continue;
                }

                if (!$dryRun) {
                    $wpdb->insert(
                        $claims,
                        [
                            'entity_id' => $entityId,
                            'entity_type' => $entityType,
                            'attribute' => $attr,
                            'value' => $value,
                            'extraction_method' => $extraction,
                            'status' => 'approved',
                            'created_at' => $approvedAt,
                            'approved_by' => 'backfill',
                            'approved_at' => $approvedAt,
                        ]
                    );
                }
                $totals['inserted']++;
            }
        }

        return $totals;
    }

    /**
     * @param array<string, mixed> $entity
     * @return list<array{0:string,1:string}>
     */
    public static function entity_attribute_pairs(array $entity): array
    {
        $pairs = [];
        $pairs[] = ['name', self::encode_claim_value((string) ($entity['name'] ?? ''))];

        $desc = $entity['description'] ?? null;
        if ($desc !== null && trim((string) $desc) !== '') {
            $pairs[] = ['description', self::encode_claim_value((string) $desc)];
        }

        $props = $entity['properties'] ?? [];
        if (is_string($props)) {
            $decoded = json_decode($props, true);
            $props = is_array($decoded) ? $decoded : [];
        }
        if (is_array($props)) {
            foreach ($props as $k => $v) {
                $pairs[] = [self::PROP_PREFIX . (string) $k, self::encode_claim_value($v)];
            }
        }

        $rel = $entity['relationships'] ?? [];
        if (is_string($rel)) {
            $decoded = json_decode($rel, true);
            $rel = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($rel)) {
            $rel = [];
        }
        $pairs[] = ['relationships', self::encode_claim_value(array_values($rel))];

        $ev = $entity['evidence'] ?? [];
        if (is_string($ev)) {
            $decoded = json_decode($ev, true);
            $ev = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($ev)) {
            $ev = [];
        }
        $pairs[] = ['evidence', self::encode_claim_value(array_values($ev))];

        foreach (['trust_level', 'source'] as $attr) {
            $val = $entity[$attr] ?? null;
            if ($val !== null && (string) $val !== '') {
                $pairs[] = [$attr, self::encode_claim_value((string) $val)];
            }
        }

        return $pairs;
    }

    public static function encode_claim_value(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return $value;
        }
        $json = wp_json_encode($value);
        return is_string($json) ? $json : '';
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

    /**
     * Stage 3: scan pairs omit status.
     *
     * @param array<string, mixed> $entity
     * @return list<array{0:string,1:string}>
     */
    public static function scan_attribute_pairs(array $entity): array
    {
        $out = [];
        foreach (self::entity_attribute_pairs($entity) as [$attr, $value]) {
            if ($attr === 'status') {
                continue;
            }
            $out[] = [$attr, $value];
        }
        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function latest_claim(string $entity_id, string $attribute, string $status): ?array
    {
        global $wpdb;
        $claims = MBKBS_Database::claims_table();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$claims} WHERE entity_id = %s AND attribute = %s AND status = %s ORDER BY id DESC LIMIT 1",
                $entity_id,
                $attribute,
                $status
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    public static function insert_pending_claim(
        string $entity_id,
        string $entity_type,
        string $attribute,
        string $value,
        string $extraction_method = 'scan'
    ): int {
        global $wpdb;
        $claims = MBKBS_Database::claims_table();
        $approved = self::latest_claim($entity_id, $attribute, 'approved');
        $pending = self::latest_claim($entity_id, $attribute, 'pending');
        $supersedesId = null;
        if ($approved) {
            $supersedesId = (int) $approved['id'];
        }
        if ($pending) {
            $wpdb->update($claims, ['status' => 'superseded'], ['id' => (int) $pending['id']]);
            if ($supersedesId === null) {
                $supersedesId = (int) $pending['id'];
            }
        }
        $now = current_time('mysql', true);
        $row = [
            'entity_id' => $entity_id,
            'entity_type' => $entity_type,
            'attribute' => $attribute,
            'value' => $value,
            'extraction_method' => substr($extraction_method, 0, 32),
            'status' => 'pending',
            'created_at' => $now,
        ];
        if ($supersedesId !== null) {
            $row['supersedes_id'] = $supersedesId;
        }
        $wpdb->insert($claims, $row);
        return 1;
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed> $extract
     * @return array{claims_created:int,claims_unchanged:int}
     */
    public static function propose_claims_from_extract(array $entity, array $extract): array
    {
        $created = 0;
        $unchanged = 0;
        $eid = (string) $entity['id'];
        $etype = (string) ($entity['entity_type'] ?? 'unknown');
        $method = substr((string) ($extract['source'] ?? 'scan'), 0, 32);
        foreach (self::scan_attribute_pairs($extract) as [$attr, $incoming]) {
            $baseline = null;
            $approved = self::latest_claim($eid, $attr, 'approved');
            if ($approved) {
                $baseline = (string) $approved['value'];
            } else {
                foreach (self::entity_attribute_pairs($entity) as [$a, $v]) {
                    if ($a === $attr) {
                        $baseline = $v;
                        break;
                    }
                }
            }
            if ($baseline !== null && $baseline === $incoming) {
                $unchanged++;
                continue;
            }
            if ($attr === 'description' && $incoming === '') {
                $unchanged++;
                continue;
            }
            $created += self::insert_pending_claim($eid, $etype, $attr, $incoming, $method);
        }
        return ['claims_created' => $created, 'claims_unchanged' => $unchanged];
    }

    /**
     * @param array<string, mixed> $entity
     */
    public static function seed_pending_claims_for_new_entity(array $entity): int
    {
        $n = 0;
        $eid = (string) $entity['id'];
        $etype = (string) ($entity['entity_type'] ?? 'unknown');
        $method = substr((string) ($entity['source'] ?? 'scan'), 0, 32);
        foreach (self::scan_attribute_pairs($entity) as [$attr, $value]) {
            $n += self::insert_pending_claim($eid, $etype, $attr, $value, $method);
        }
        return $n;
    }

    public static function insert_approved_claim(
        string $entity_id,
        string $entity_type,
        string $attribute,
        string $value,
        string $extraction_method = 'manual',
        ?string $approved_by = null
    ): int {
        global $wpdb;
        $claims = MBKBS_Database::claims_table();
        $approved = self::latest_claim($entity_id, $attribute, 'approved');
        $pending = self::latest_claim($entity_id, $attribute, 'pending');
        $supersedes_id = null;
        if ($approved) {
            $supersedes_id = (int) $approved['id'];
            $wpdb->update($claims, ['status' => 'superseded'], ['id' => (int) $approved['id']]);
        }
        if ($pending) {
            $wpdb->update($claims, ['status' => 'superseded'], ['id' => (int) $pending['id']]);
            if ($supersedes_id === null) {
                $supersedes_id = (int) $pending['id'];
            }
        }
        $now = current_time('mysql', true);
        $row = [
            'entity_id' => $entity_id,
            'entity_type' => $entity_type,
            'attribute' => $attribute,
            'value' => $value,
            'extraction_method' => substr($extraction_method, 0, 32),
            'status' => 'approved',
            'created_at' => $now,
            'approved_at' => $now,
        ];
        if ($approved_by !== null) {
            $row['approved_by'] = $approved_by;
        }
        if ($supersedes_id !== null) {
            $row['supersedes_id'] = $supersedes_id;
        }
        $wpdb->insert($claims, $row);
        return 1;
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed>|null $surface
     */
    public static function write_surface_claims(
        array $entity,
        string $claim_status,
        string $extraction_method = 'manual',
        ?string $approved_by = null,
        ?array $surface = null
    ): int {
        $src = $surface ?? $entity;
        $eid = (string) $entity['id'];
        $etype = (string) ($entity['entity_type'] ?? 'unknown');
        $n = 0;
        foreach (self::scan_attribute_pairs($src) as [$attr, $value]) {
            if ($claim_status === 'approved') {
                $existing = self::latest_claim($eid, $attr, 'approved');
                if ($existing && (string) $existing['value'] === $value) {
                    $pending = self::latest_claim($eid, $attr, 'pending');
                    if ($pending) {
                        global $wpdb;
                        $wpdb->update(MBKBS_Database::claims_table(), ['status' => 'superseded'], ['id' => (int) $pending['id']]);
                    }
                    continue;
                }
                $n += self::insert_approved_claim($eid, $etype, $attr, $value, $extraction_method, $approved_by);
                continue;
            }
            if ($claim_status === 'pending') {
                $pending = self::latest_claim($eid, $attr, 'pending');
                if ($pending && (string) $pending['value'] === $value) {
                    continue;
                }
                $approved = self::latest_claim($eid, $attr, 'approved');
                if ($approved && (string) $approved['value'] === $value && !$pending) {
                    continue;
                }
                $n += self::insert_pending_claim($eid, $etype, $attr, $value, $extraction_method);
            }
        }
        return $n;
    }

    /** @param array<string, mixed> $entity */
    public static function promote_pending_claims(array $entity, ?string $approved_by = null): int
    {
        global $wpdb;
        $claims = MBKBS_Database::claims_table();
        $eid = (string) $entity['id'];
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$claims} WHERE entity_id = %s AND status = %s ORDER BY id ASC", $eid, 'pending'),
            ARRAY_A
        ) ?: [];
        $latest = [];
        foreach ($rows as $row) {
            $attr = (string) $row['attribute'];
            $prev = $latest[$attr] ?? null;
            if ($prev === null || (int) $row['id'] > (int) $prev['id']) {
                $latest[$attr] = $row;
            }
        }
        $now = current_time('mysql', true);
        $n = 0;
        foreach ($latest as $attr => $pending) {
            foreach ($rows as $other) {
                if ((string) $other['attribute'] === $attr && (int) $other['id'] !== (int) $pending['id']) {
                    $wpdb->update($claims, ['status' => 'superseded'], ['id' => (int) $other['id']]);
                }
            }
            $prior = self::latest_claim($eid, $attr, 'approved');
            $data = [
                'status' => 'approved',
                'approved_at' => $now,
            ];
            if ($approved_by !== null) {
                $data['approved_by'] = $approved_by;
            }
            if ($prior && (int) $prior['id'] !== (int) $pending['id']) {
                $wpdb->update($claims, ['status' => 'superseded'], ['id' => (int) $prior['id']]);
                if (empty($pending['supersedes_id'])) {
                    $data['supersedes_id'] = (int) $prior['id'];
                }
            }
            $wpdb->update($claims, $data, ['id' => (int) $pending['id']]);
            $n++;
        }
        return $n;
    }

    /** @param array<string, mixed> $entity */
    public static function seed_missing_approved_claims(array $entity, ?string $approved_by = null): int
    {
        $eid = (string) $entity['id'];
        $etype = (string) ($entity['entity_type'] ?? 'unknown');
        $method = substr((string) ($entity['source'] ?? 'manual'), 0, 32);
        $n = 0;
        foreach (self::scan_attribute_pairs($entity) as [$attr, $value]) {
            if (self::latest_claim($eid, $attr, 'approved')) {
                continue;
            }
            $n += self::insert_approved_claim($eid, $etype, $attr, $value, $method, $approved_by);
        }
        return $n;
    }

    /** @param array<string, mixed> $entity */
    public static function reject_pending_claims(array $entity): int
    {
        global $wpdb;
        $n = $wpdb->update(
            MBKBS_Database::claims_table(),
            ['status' => 'rejected'],
            ['entity_id' => (string) $entity['id'], 'status' => 'pending']
        );
        return is_int($n) ? $n : 0;
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, mixed>|null $surface
     * @return array{claims_written:int,claims_promoted:int,claims_rejected:int}
     */
    public static function apply_human_decision(
        array &$entity,
        string $action,
        ?string $approved_by = null,
        ?array $surface = null
    ): array {
        global $wpdb;
        $stats = ['claims_written' => 0, 'claims_promoted' => 0, 'claims_rejected' => 0];
        $table = MBKBS_Database::entities_table();
        $eid = (string) $entity['id'];
        $now = current_time('mysql', true);
        if ($action === 'approve') {
            if ($surface !== null) {
                $stats['claims_written'] = self::write_surface_claims(
                    $entity,
                    'approved',
                    (string) ($surface['source'] ?? 'manual'),
                    $approved_by,
                    $surface
                );
            } else {
                $stats['claims_promoted'] = self::promote_pending_claims($entity, $approved_by);
                $stats['claims_written'] = self::seed_missing_approved_claims($entity, $approved_by);
            }
            $entity['status'] = 'approved';
            $wpdb->update($table, ['status' => 'approved', 'last_updated' => $now], ['id' => $eid]);
        } elseif ($action === 'reject') {
            $stats['claims_rejected'] = self::reject_pending_claims($entity);
            if (MBKBS_Diff::has_approved_claims($eid)) {
                $entity['status'] = 'approved';
                $wpdb->update($table, ['status' => 'approved', 'last_updated' => $now], ['id' => $eid]);
            } else {
                $entity['status'] = 'rejected';
                $wpdb->update($table, ['status' => 'rejected', 'last_updated' => $now], ['id' => $eid]);
            }
        } elseif ($action === 'needs_edit') {
            $entity['status'] = 'needs_edit';
            $wpdb->update($table, ['status' => 'needs_edit', 'last_updated' => $now], ['id' => $eid]);
        }
        return $stats;
    }

    /**
     * @param array<string, mixed> $entity
     * @return array{claims_written:int,claims_promoted:int,claims_rejected:int}
     */
    public static function apply_save_claims(
        array &$entity,
        string $intent,
        string $previous_status,
        ?string $approved_by = null
    ): array {
        if ($intent === 'save_reject') {
            return self::apply_human_decision($entity, 'reject', $approved_by);
        }
        if ($intent === 'save_approve' || ($entity['status'] ?? '') === 'approved') {
            return self::apply_human_decision($entity, 'approve', $approved_by, $entity);
        }
        $n = self::write_surface_claims(
            $entity,
            'pending',
            (string) ($entity['source'] ?? 'manual'),
            $approved_by,
            $entity
        );
        $envelope = (string) ($entity['status'] ?? '');
        if ($n > 0 && $previous_status === 'approved' && !in_array($envelope, ['pending', 'rejected'], true)) {
            global $wpdb;
            $entity['status'] = 'needs_edit';
            $wpdb->update(
                MBKBS_Database::entities_table(),
                ['status' => 'needs_edit', 'last_updated' => current_time('mysql', true)],
                ['id' => (string) $entity['id']]
            );
        }
        return ['claims_written' => $n, 'claims_promoted' => 0, 'claims_rejected' => 0];
    }

    /**
     * @param array<string, mixed> $entity
     * @param array<string, string> $submitted
     * @param array<string, string> $extract
     * @return array{claims_written:int,claims_promoted:int,claims_rejected:int}
     */
    public static function apply_review_from_form(
        array &$entity,
        string $intent,
        array $submitted,
        array $extract,
        ?string $approved_by = null
    ): array {
        if ($intent === 'save_reject') {
            return self::apply_human_decision($entity, 'reject', $approved_by);
        }
        global $wpdb;
        $stats = ['claims_written' => 0, 'claims_promoted' => 0, 'claims_rejected' => 0];
        $eid = (string) $entity['id'];
        $etype = (string) ($entity['entity_type'] ?? 'unknown');
        $previous = (string) ($entity['status'] ?? '');
        foreach ($submitted as $attr => $value) {
            if (array_key_exists($attr, $extract) && $extract[$attr] === $value) {
                continue;
            }
            if ($intent === 'save_approve') {
                $stats['claims_written'] += self::insert_approved_claim($eid, $etype, $attr, $value, 'manual', $approved_by);
            } else {
                $stats['claims_written'] += self::insert_pending_claim($eid, $etype, $attr, $value, 'manual');
            }
        }
        if ($intent === 'save_approve') {
            $stats['claims_promoted'] = self::promote_pending_claims($entity, $approved_by);
            $stats['claims_written'] += self::seed_missing_approved_claims($entity, $approved_by);
            $entity['status'] = 'approved';
            $wpdb->update(
                MBKBS_Database::entities_table(),
                ['status' => 'approved', 'last_updated' => current_time('mysql', true)],
                ['id' => $eid]
            );
        } elseif ($stats['claims_written'] > 0 && $previous === 'approved') {
            $entity['status'] = 'needs_edit';
            $wpdb->update(
                MBKBS_Database::entities_table(),
                ['status' => 'needs_edit', 'last_updated' => current_time('mysql', true)],
                ['id' => $eid]
            );
        }
        if (isset($submitted['name']) && $submitted['name'] !== '') {
            $wpdb->update(MBKBS_Database::entities_table(), ['name' => $submitted['name']], ['id' => $eid]);
            $entity['name'] = $submitted['name'];
        }
        return $stats;
    }
}
