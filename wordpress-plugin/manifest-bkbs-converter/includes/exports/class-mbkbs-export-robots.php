<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class MBKBS_Export_Robots
{
    public const BEGIN = '# BEGIN BKBS';
    public const END = '# END BKBS';

    /**
     * @param array<string, mixed> $site
     */
    public static function content_usage_line(array $site): ?string
    {
        $search = !empty($site['aipref_search']);
        $aiInput = !empty($site['aipref_ai_input']);
        $train = !empty($site['aipref_train_ai']);
        if (!$search && !$aiInput && !$train) {
            return null;
        }
        $yn = static fn(bool $v): string => $v ? 'y' : 'n';
        return 'Content-Usage: search=' . $yn($search) . ', ai-input=' . $yn($aiInput) . ', train-ai=' . $yn($train);
    }

    /**
     * @param array<string, mixed> $site
     */
    public static function block(array $site): string
    {
        $base = untrailingslashit((string) ($site['base_url'] ?? ''));
        $lines = [
            self::BEGIN,
            '# Machine layers for AI agents (managed by Manifest BKBS Converter)',
            'User-agent: *',
            'Allow: /llms.txt',
            'Allow: /llms-full.txt',
            'Allow: /graph.json',
            'Allow: /schema/',
            'Allow: /.well-known/agent.json',
        ];
        $usage = self::content_usage_line($site);
        if ($usage !== null) {
            $lines[] = $usage;
        }
        $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
        $lines[] = self::END;
        return implode("\n", $lines) . "\n";
    }

    public static function replace_block(string $existing, string $block): string
    {
        $begin = self::BEGIN;
        $end = self::END;
        if (str_contains($existing, $begin)) {
            $startPos = strpos($existing, $begin);
            $endPos = strpos($existing, $end);
            if ($startPos === false) {
                return rtrim($existing) . "\n\n" . $block;
            }
            if ($endPos !== false) {
                $endPos += strlen($end);
                $after = ltrim(substr($existing, $endPos));
            } else {
                $after = '';
            }
            $out = rtrim(substr($existing, 0, $startPos)) . "\n\n" . $block;
            if ($after !== '') {
                $out .= $after;
            }
            return $out;
        }
        return rtrim($existing) . ($existing !== '' ? "\n\n" : '') . $block;
    }

    public static function merge_file(string $root, array $site): void
    {
        $path = rtrim($root, '/\\') . '/robots.txt';
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        file_put_contents($path, self::replace_block($existing, self::block($site)));
    }

    /** @return array<string, mixed> */
    public static function site_from_settings(): array
    {
        return [
            'base_url' => untrailingslashit(home_url('/')),
            'aipref_search' => MBKBS_Database::get_setting('aipref.search', '0') === '1',
            'aipref_ai_input' => MBKBS_Database::get_setting('aipref.ai_input', '0') === '1',
            'aipref_train_ai' => MBKBS_Database::get_setting('aipref.train_ai', '0') === '1',
        ];
    }
}
