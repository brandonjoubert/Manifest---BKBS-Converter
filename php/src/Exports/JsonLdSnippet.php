<?php
declare(strict_types=1);

namespace Bkbs\Exports;

/** Stage 4c: script-safe JSON-LD HTML snippet ('<' → \u003c). */
final class JsonLdSnippet
{
    public static function scriptSafeJson(mixed $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            $json = '{}';
        }
        return str_replace(
            ["<", "\u{2028}", "\u{2029}"],
            ['\u003c', '\u2028', '\u2029'],
            $json
        );
    }

    /**
     * @param array<string, mixed> $site
     * @param list<array<string, mixed>> $entities
     */
    public static function organization(array $site, array $entities): string
    {
        $payload = self::scriptSafeJson(SchemaOrg::organization($site, $entities));
        return '<script type="application/ld+json">' . $payload . "</script>\n";
    }
}
