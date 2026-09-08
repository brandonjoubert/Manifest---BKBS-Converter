<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/** Stage 4c: script-safe JSON-LD HTML snippet ('<' → \u003c). */
final class MBKBS_Export_Jsonld
{
    public static function script_safe_json(mixed $data): string
    {
        $json = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
     * @param array<string, mixed> $organization
     */
    public static function snippet(array $organization): string
    {
        return '<script type="application/ld+json">' . self::script_safe_json($organization) . "</script>\n";
    }
}
