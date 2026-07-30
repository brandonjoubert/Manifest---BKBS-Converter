<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class MBKBS_Crawler
{
    /**
     * @return list<array{url:string,title:?string,text:string,status:int}>
     */
    public function crawl(string $base_url, int $max_pages = 40, int $delay_ms = 200): array
    {
        $base_url = untrailingslashit($base_url);
        $parts = wp_parse_url($base_url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            throw new InvalidArgumentException('Invalid base URL');
        }
        $origin_host = strtolower((string) $parts['host']);

        $queue = [$base_url];
        $seen = [];
        $pages = [];

        while ($queue && count($pages) < $max_pages) {
            $url = array_shift($queue);
            $url = $this->normalize($url);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $host = strtolower((string) (wp_parse_url($url, PHP_URL_HOST) ?? ''));
            if ($host !== $origin_host || $this->is_binary($url)) {
                continue;
            }

            $response = wp_remote_get(
                $url,
                [
                    'timeout' => 20,
                    'redirection' => 5,
                    'user-agent' => 'Manifest-BKBS-WordPress/' . MBKBS_VERSION,
                    'sslverify' => true,
                ]
            );
            if (is_wp_error($response)) {
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            if ($code < 200 || $code >= 400 || $body === '') {
                continue;
            }
            if (strlen($body) > 2000000) {
                $body = substr($body, 0, 2000000);
            }

            $parsed = $this->parse_html($body, $url);
            $pages[] = [
                'url' => $url,
                'title' => $parsed['title'],
                'text' => $parsed['text'],
                'status' => $code,
            ];
            foreach ($parsed['links'] as $link) {
                if (!isset($seen[$link])) {
                    $queue[] = $link;
                }
            }
            if ($delay_ms > 0) {
                usleep($delay_ms * 1000);
            }
        }

        return $pages;
    }

    private function normalize(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme'])) {
            return $url;
        }
        $path = $parts['path'] ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return strtolower((string) $parts['scheme']) . '://' . strtolower((string) ($parts['host'] ?? '')) . $port . $path;
    }

    private function is_binary(string $url): bool
    {
        $path = strtolower((string) wp_parse_url($url, PHP_URL_PATH));
        foreach (['.jpg', '.jpeg', '.png', '.gif', '.webp', '.pdf', '.zip', '.css', '.js', '.woff', '.mp4'] as $ext) {
            if (str_ends_with($path, $ext)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{title:?string,text:string,links:list<string>}
     */
    private function parse_html(string $html, string $base_url): array
    {
        $title = null;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $clean) ?? $clean;
        $text = html_entity_decode(wp_strip_all_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        if (strlen($text) > 12000) {
            $text = substr($text, 0, 12000) . "\n…[truncated]";
        }

        $links = [];
        if (preg_match_all('/<a\s[^>]*href=["\']([^"\']+)["\']/i', $html, $m)) {
            foreach ($m[1] as $href) {
                $href = trim($href);
                if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'javascript:')) {
                    continue;
                }
                $abs = $this->absolutize($base_url, $href);
                if ($abs) {
                    $links[] = $this->normalize($abs);
                }
            }
        }
        return ['title' => $title, 'text' => trim($text), 'links' => array_values(array_unique($links))];
    }

    private function absolutize(string $base, string $href): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $bp = wp_parse_url($base);
        if (!$bp || empty($bp['scheme']) || empty($bp['host'])) {
            return null;
        }
        $origin = $bp['scheme'] . '://' . $bp['host'] . (isset($bp['port']) ? ':' . $bp['port'] : '');
        if (str_starts_with($href, '//')) {
            return $bp['scheme'] . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $origin . $href;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $bp['path'] ?? '/') ?? '/';
        return $origin . $dir . $href;
    }
}
