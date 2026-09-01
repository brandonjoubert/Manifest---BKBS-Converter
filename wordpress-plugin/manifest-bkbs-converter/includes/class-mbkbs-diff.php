<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Claim Ledger Stage 5 — claim-level diff (old = approved, new = pending). */
final class MBKBS_Diff
{
    public static function has_approved_claims(string $entity_id): bool
    {
        global $wpdb;
        $claims = MBKBS_Database::claims_table();
        $n = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$claims} WHERE entity_id = %s AND status = %s LIMIT 1", $entity_id, 'approved')
        );
        return (bool) $n;
    }

    public static function can_bulk_approve(string $entity_id): bool
    {
        return !self::has_approved_claims($entity_id);
    }

    public static function attribute_label(string $attr): string
    {
        if (str_starts_with($attr, 'prop:')) {
            return substr($attr, 5);
        }
        return ucwords(str_replace('_', ' ', $attr));
    }

    public static function display_value(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $json = wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            return is_string($json) ? $json : $raw;
        }
        return $raw;
    }

    /** @return array<string, mixed> */
    public static function for_entity(string $entity_id): array
    {
        global $wpdb;
        $claims = MBKBS_Database::claims_table();
        $approved = [];
        $pending = [];
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$claims} WHERE entity_id = %s AND status IN ('approved','pending')", $entity_id),
            ARRAY_A
        ) ?: [];
        foreach ($rows as $row) {
            $attr = (string) $row['attribute'];
            $bucket = ((string) $row['status'] === 'pending') ? 'pending' : 'approved';
            $prev = ${$bucket}[$attr] ?? null;
            if ($prev === null || (int) $row['id'] > (int) $prev['id']) {
                ${$bucket}[$attr] = $row;
            }
        }
        $attrs = array_values(array_unique(array_merge(array_keys($approved), array_keys($pending))));
        sort($attrs);
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
                    'label' => self::attribute_label($attr),
                    'display' => self::display_value($oldRaw !== null ? (string) $oldRaw : null),
                ];
                continue;
            }
            if ($old !== null && (string) $oldRaw === (string) $newRaw) {
                $unchanged[] = [
                    'attribute' => $attr,
                    'label' => self::attribute_label($attr),
                    'display' => self::display_value((string) $oldRaw),
                ];
                continue;
            }
            $changes[] = [
                'attribute' => $attr,
                'label' => self::attribute_label($attr),
                'kind' => $old === null ? 'new' : 'changed',
                'old' => $oldRaw,
                'new' => (string) $newRaw,
                'old_display' => $oldRaw !== null ? self::display_value((string) $oldRaw) : null,
                'new_display' => self::display_value($newRaw !== null ? (string) $newRaw : null),
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
        return [
            'entity_id' => $entity_id,
            'is_new_entity' => $isNew,
            'has_pending' => $pending !== [],
            'can_bulk_approve' => $isNew,
            'summary' => $summary,
            'display_name' => (string) ($pending['name']['value'] ?? $approved['name']['value'] ?? ''),
            'changes' => $changes,
            'unchanged' => $unchanged,
        ];
    }

    /**
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    public static function for_ids(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = self::for_entity($id);
        }
        return $out;
    }
}
