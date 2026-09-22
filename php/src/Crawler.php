<?php
declare(strict_types=1);

namespace Bkbs;

final class Crawler
{
    /** @return list<array{url:string,title:?string,text:string,status:int,json_ld:list}> */
    public function crawl(string $baseUrl, int $maxPages = 40, int $delayMs = 200): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $origin = parse_url($baseUrl);
        if (!$origin || empty($origin['scheme']) || empty($origin['host'])) {
            throw new \InvalidArgumentException('Invalid base URL');
        }
        $originHost = strtolower($origin['host']);

        $queue = [$baseUrl];
        $seen = [];
        $pages = [];

        while ($queue && count($pages) < $maxPages) {
            $url = array_shift($queue);
            $url = $this->normalize($url);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
            if ($host !== $originHost) {
                continue;
            }
            if ($this->isBinary($url)) {
                continue;
            }

            $res = $this->request($url, 20);
            $finalHost = strtolower((string) (parse_url($res['final_url'], PHP_URL_HOST) ?? ''));
            if ($res['error'] !== null || ($finalHost !== '' && $finalHost !== $originHost)) {
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
                continue;
            }
            if ($res['status'] >= 200 && $res['status'] < 400 && $res['body'] !== '') {
                $parsed = $this->parseHtml($res['body'], $url);
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
            }
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        return $pages;
    }

    /**
     * Public GET used by crawl and origin probes.
     *
     * @return array{status:int,body:string,final_url:string,error:?string}
     */
    public function request(string $url, int $timeoutSec = 10): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'final_url' => $url, 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => max(1, $timeoutSec),
            CURLOPT_USERAGENT => 'BKBS-PHP-Converter/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXREDIRS => 5,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $errno = curl_errno($ch);
        $err = $errno !== 0 ? (string) curl_error($ch) : null;
        curl_close($ch);
        if (!is_string($body)) {
            $body = '';
        }
        if (strlen($body) > 2_000_000) {
            $body = substr($body, 0, 2_000_000);
        }
        if ($final === '') {
            $final = $url;
        }

        $origHost = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $finalHost = strtolower((string) (parse_url($final, PHP_URL_HOST) ?? ''));
        if ($origHost !== '' && $finalHost !== '' && $origHost !== $finalHost) {
            return ['status' => 0, 'body' => '', 'final_url' => $final, 'error' => 'redirect off-origin'];
        }
        if ($err !== null && $err !== '') {
            return [
                'status' => 0,
                'body' => '',
                'final_url' => $final,
                'error' => $this->shortError($err),
            ];
        }
        return ['status' => $status ?: 0, 'body' => $body, 'final_url' => $final, 'error' => null];
    }

    /**
     * Parse application/ld+json blocks from raw HTML (before script strip).
     *
     * @return list<mixed>
     */
    public function extractJsonLd(string $html): array
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
            if (is_array($data) && array_is_list($data)) {
                foreach ($data as $item) {
                    $blocks[] = $item;
                }
            } elseif ($data !== null) {
                $blocks[] = $data;
            }
        }
        return $blocks;
    }

    private function normalize(string $url): string
    {
        $parts = parse_url($url);
        if (!$parts || empty($parts['scheme'])) {
            return $url;
        }
        $path = $parts['path'] ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return strtolower($parts['scheme']) . '://' . strtolower($parts['host'] ?? '') . $port . $path;
    }

    private function isBinary(string $url): bool
    {
        $path = strtolower((string) parse_url($url, PHP_URL_PATH));
        foreach (['.jpg', '.jpeg', '.png', '.gif', '.webp', '.pdf', '.zip', '.css', '.js', '.woff', '.mp4'] as $ext) {
            if (str_ends_with($path, $ext)) {
                return true;
            }
        }
        return false;
    }

    /** @return array{title:?string,text:string,links:list<string>,json_ld:list} */
    private function parseHtml(string $html, string $baseUrl): array
    {
        $jsonLd = $this->extractJsonLd($html);

        $title = null;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $clean = preg_replace('/<title\b[^>]*>.*?<\/title>/is', ' ', $html) ?? $html;
        $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $clean) ?? $clean;
        $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $clean) ?? $clean;
        $text = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
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
                $abs = $this->absolutize($baseUrl, $href);
                if ($abs) {
                    $links[] = $this->normalize($abs);
                }
            }
        }
        return [
            'title' => $title,
            'text' => trim($text),
            'links' => array_values(array_unique($links)),
            'json_ld' => $jsonLd,
        ];
    }

    private function absolutize(string $base, string $href): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $bp = parse_url($base);
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
        $dir = preg_replace('#/[^/]*$#', '/', $bp['path'] ?? '/');
        return $origin . $dir . $href;
    }

    private function shortError(string $err): string
    {
        return substr(trim(str_replace("\n", ' ', $err)), 0, 200);
    }
}
