<?php
declare(strict_types=1);

namespace Bkbs;

/**
 * Claim Ledger resolver (Stage 1 stub).
 *
 * Stage 1: always returns null. Stage 2+ will resolve from approved claims.
 */
final class Resolver
{
    /**
     * @param string|null $entityId
     * @param string|null $asOf ISO-8601 timestamp; ignored in Stage 1
     * @return array<string, mixed>|null Always null in Stage 1
     */
    public static function resolveEntity(?string $entityId = null, ?string $asOf = null): ?array
    {
        return null;
    }
}
