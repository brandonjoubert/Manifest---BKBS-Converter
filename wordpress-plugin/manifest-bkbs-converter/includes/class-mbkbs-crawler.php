<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parse application/ld+json blocks from raw HTML (before script strip). Pure: no HTTP.
 *
 * @return list<mixed>
 */
function mbkbs_extract_json_ld(string $html): array
{
    $blocks = [];
    if (!preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER)) {
        return $blocks;
    }
    foreach ($matches as $m) {
        $attrs = $m[1];
        if (!preg_match('/type\s*=\s*["\']?[^"\'>\s]*ld\+json/i', $attrs)) {
            continue;
        }
        $raw = trim(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($raw === '') {
            continue;
        }
        $data = json_decode($raw, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            continue;
        }
        if (is_array($data) && mbkbs_json_ld_is_list($data)) {
            foreach ($data as $item) {
                $blocks[] = $item;
            }
        } elseif ($data !== null) {
            $blocks[] = $data;
        }
    }
    return $blocks;
}

/** @param array<mixed> $data */
function mbkbs_json_ld_is_list(array $data): bool
{
    if (function_exists('array_is_list')) {
        return array_is_list($data);
    }
    $expected = 0;
    foreach ($data as $key => $_) {
        if ($key !== $expected) {
            return false;
        }
        $expected++;
    }
    return true;
}

final class MBKBS_Crawler
{
    /**
     * @return list<array{url:string,title:?string,text:string,status:int,json_ld:list}>
     */
    public function crawl(string $base_url, int $max_pages = 40, int $delay_ms = 200): array
    {
        $base_url = function_exists('untrailingslashit') ? untrailingslashit($base_url) : rtrim($base_url, '/');
        $parts = $this->parse_url_parts($base_url);
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

            $host = strtolower((string) ($this->parse_url_parts($url)['host'] ?? ''));
            if ($host !== $origin_host || $this->is_binary($url)) {
                continue;
            }

            $res = $this->request($url, 20);
            if ($res['error'] !== null || $res['status'] < 200 || $res['status'] >= 400 || $res['body'] === '') {
                continue;
            }

            $parsed = $this->parse_html($res['body'], $url);
            $pages[] = [
                'url' => $url,
                'title' => $parsed['title'],
                'text' => $parsed['text'],
                'status' => $res['status'],
                'json_ld' => $parsed['json_ld'],
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

    /**
     * Public GET used by crawl and origin probes.
     *
     * @return array{status:int,body:string,final_url:string,error:?string}
     */
    public function request(string $url, int $timeout_sec = 10): array
    {
        if (!function_exists('wp_remote_get')) {
            return ['status' => 0, 'body' => '', 'final_url' => $url, 'error' => 'wp_remote_get unavailable'];
        }
        $ua = defined('MBKBS_VERSION') ? 'Manifest-BKBS-WordPress/' . MBKBS_VERSION : 'Manifest-BKBS-WordPress/0.1.0';
        $response = wp_remote_get(
            $url,
            [
                'timeout' => max(1, $timeout_sec),
                'redirection' => 5,
                'user-agent' => $ua,
                'sslverify' => true,
            ]
        );
        if (is_wp_error($response)) {
            return [
                'status' => 0,
                'body' => '',
                'final_url' => $url,
                'error' => $this->short_error($response->get_error_message()),
            ];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        if (strlen($body) > 2000000) {
            $body = substr($body, 0, 2000000);
        }
        $final = $this->effective_url($response, $url);
        $orig_host = strtolower((string) ($this->parse_url_parts($url)['host'] ?? ''));
        $final_host = strtolower((string) ($this->parse_url_parts($final)['host'] ?? ''));
        if ($orig_host !== '' && $final_host !== '' && $orig_host !== $final_host) {
            return ['status' => 0, 'body' => '', 'final_url' => $final, 'error' => 'redirect off-origin'];
        }
        return ['status' => $code ?: 0, 'body' => $body, 'final_url' => $final, 'error' => null];
    }

    private function normalize(string $url): string
    {
        $parts = $this->parse_url_parts($url);
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
        $path = strtolower((string) ($this->parse_url_parts($url)['path'] ?? ''));
        foreach (['.jpg', '.jpeg', '.png', '.gif', '.webp', '.pdf', '.zip', '.css', '.js', '.woff', '.mp4'] as $ext) {
            if (str_ends_with($path, $ext)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{title:?string,text:string,links:list<string>,json_ld:list}
     */
    private function parse_html(string $html, string $base_url): array
    {
        $json_ld = mbkbs_extract_json_ld($html);

        $title = null;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $stripped = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($m[1]) : strip_tags($m[1]);
            $title = trim(html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
        $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $clean) ?? $clean;
        $text_src = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($clean) : strip_tags($clean);
        $text = html_entity_decode($text_src, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
        return [
            'title' => $title,
            'text' => trim($text),
            'links' => array_values(array_unique($links)),
            'json_ld' => $json_ld,
        ];
    }

    private function absolutize(string $base, string $href): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $bp = $this->parse_url_parts($base);
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

    /**
     * @param array<string, mixed> $response
     */
    private function effective_url(array $response, string $fallback): string
    {
        if (isset($response['http_response']) && is_object($response['http_response'])) {
            $http = $response['http_response'];
            if (method_exists($http, 'get_response_object')) {
                $obj = $http->get_response_object();
                if (is_object($obj) && !empty($obj->url)) {
                    return (string) $obj->url;
                }
            }
        }
        if (function_exists('wp_remote_retrieve_header')) {
            $loc = wp_remote_retrieve_header($response, 'location');
            if (is_string($loc) && $loc !== '') {
                return $loc;
            }
        }
        return $fallback;
    }

    /** @return array<string, mixed>|false */
    private function parse_url_parts(string $url)
    {
        if (function_exists('wp_parse_url')) {
            $parts = wp_parse_url($url);
            return is_array($parts) ? $parts : false;
        }
        $parts = parse_url($url);
        return is_array($parts) ? $parts : false;
    }

    private function short_error(string $err): string
    {
        return substr(trim(str_replace("\n", ' ', $err)), 0, 200);
    }
}
