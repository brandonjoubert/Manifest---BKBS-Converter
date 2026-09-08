<?php
declare(strict_types=1);

namespace Bkbs\Exports;

final class Robots
{
    public const BEGIN = '# BEGIN BKBS';
    public const END = '# END BKBS';

    /**
     * @param array<string, mixed> $site
     */
    public static function contentUsageLine(array $site): ?string
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
        $base = rtrim((string) ($site['base_url'] ?? ''), '/');
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
        $usage = self::contentUsageLine($site);
        if ($usage !== null) {
            $lines[] = $usage;
        }
        $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
        $lines[] = self::END;
        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed>|string $siteOrBaseUrl
     */
    public static function merge(string $root, array|string $siteOrBaseUrl): void
    {
        $site = is_array($siteOrBaseUrl)
            ? $siteOrBaseUrl
            : ['base_url' => $siteOrBaseUrl];
        $path = $root . '/robots.txt';
        $block = self::block($site);
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        $begin = self::BEGIN;
        $end = self::END;
        if (str_contains($existing, $begin)) {
            $startPos = strpos($existing, $begin);
            $endPos = strpos($existing, $end);
            if ($startPos === false) {
                $existing = rtrim($existing) . "\n\n" . $block;
            } else {
                if ($endPos !== false) {
                    $endPos += strlen($end);
                    $after = ltrim(substr($existing, $endPos));
                } else {
                    $after = '';
                }
                $existing = rtrim(substr($existing, 0, $startPos)) . "\n\n" . $block;
                if ($after !== '') {
                    $existing .= $after;
                }
            }
        } else {
            $existing = rtrim($existing) . ($existing !== '' ? "\n\n" : '') . $block;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $existing);
    }
}
