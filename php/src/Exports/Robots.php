<?php
declare(strict_types=1);

namespace Bkbs\Exports;

final class Robots
{
    public static function merge(string $root, string $baseUrl): void
    {
        $path = $root . '/robots.txt';
        $marker = '# BEGIN BKBS';
        $end = '# END BKBS';
        $block = "$marker\nUser-agent: *\nAllow: /llms.txt\nAllow: /graph.json\nAllow: /schema/\nAllow: /.well-known/agent.json\nSitemap: " . rtrim($baseUrl, '/') . "/sitemap.xml\n$end\n";
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        if (str_contains($existing, $marker)) {
            $startPos = strpos($existing, $marker);
            $endPos = strpos($existing, $end);
            if ($startPos !== false && $endPos !== false) {
                $endPos += strlen($end);
                $existing = rtrim(substr($existing, 0, $startPos)) . "\n\n" . $block . ltrim(substr($existing, $endPos));
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
