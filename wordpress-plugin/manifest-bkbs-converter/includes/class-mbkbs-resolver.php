<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claim Ledger resolver (Stage 1 stub).
 *
 * Stage 1: always returns null. Stage 2+ will resolve from approved claims.
 */
final class MBKBS_Resolver
{
    /**
     * @param string|null $entity_id
     * @param string|null $as_of ISO-8601 timestamp; ignored in Stage 1
     * @return array<string, mixed>|null Always null in Stage 1
     */
    public static function resolve_entity(?string $entity_id = null, ?string $as_of = null): ?array
    {
        return null;
    }
}
