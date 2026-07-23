<?php
declare(strict_types=1);

namespace Bkbs;

final class Crawler
{
    /** @return list<array{url:string,title:?string,text:string,status:int}> */
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

            $res = $this->fetch($url);
            if ($res['status'] >= 200 && $res['status'] < 400 && $res['body'] !== '') {
                $parsed = $this->parseHtml($res['body'], $url);
                $pages[] = [
                    'url' => $url,
                    'title' => $parsed['title'],
                    'text' => $parsed['text'],
                    'status' => $res['status'],
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

    /** @return array{status:int,body:string} */
    private function fetch(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'BKBS-PHP-Converter/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_MAXREDIRS => 5,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            $body = '';
        }
        // Cap size
        if (strlen($body) > 2_000_000) {
            $body = substr($body, 0, 2_000_000);
        }
        return ['status' => $status ?: 0, 'body' => $body];
    }

    /** @return array{title:?string,text:string,links:list<string>} */
    private function parseHtml(string $html, string $baseUrl): array
    {
        $title = null;
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
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
        return ['title' => $title, 'text' => trim($text), 'links' => array_values(array_unique($links))];
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
}
