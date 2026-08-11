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

        foreach (['trust_level', 'source', 'status'] as $attr) {
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
}
