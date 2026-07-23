<?php
declare(strict_types=1);

namespace Bkbs;

final class Extractor
{
    private const SYSTEM = <<<'PROMPT'
You are a Business Knowledge Base (BKBS) extractor.
Return ONLY valid JSON: {"entities":[{"entity_type":"...","name":"...","description":"...","properties":{},"relationships":[],"evidence":[{"url":"...","snippet":"...","kind":"text"}],"trust_level":"medium"}]}
entity_type must be one of: business_identity, product_service, capability, expertise, facility_served, operational_problem, project, knowledge_article, policy, team, asset, relationship
Do not invent facts not in the text. Prefer precise capabilities over marketing fluff.
PROMPT;

    /**
     * @param list<array{url:string,title:?string,text:string}> $pages
     * @return list<array<string,mixed>>
     */
    public function extractHeuristic(array $pages): array
    {
        $entities = [];
        $keywords = [
            'cctv' => 'CCTV Installation',
            'access control' => 'Access Control Systems',
            'alarm' => 'Alarm Systems',
            'intrusion' => 'Intrusion Detection',
            'fencing' => 'Electric Fencing',
            'gate automation' => 'Gate Automation',
            'network' => 'Network Installation',
            'maintenance' => 'Maintenance Services',
        ];
        $facilities = ['warehouse', 'office', 'retail', 'hospital', 'school', 'industrial', 'commercial'];

        foreach ($pages as $i => $page) {
            $text = strtolower($page['text'] ?? '');
            $url = $page['url'];
            $title = $page['title'] ?: $url;

            if ($i === 0 || preg_match('#/($|index\.html?$)#', (string) parse_url($url, PHP_URL_PATH))) {
                $entities[] = $this->ent('business_identity', $title, substr($page['text'], 0, 500), $url, 'heuristic');
            }
            foreach ($keywords as $kw => $label) {
                if (str_contains($text, $kw)) {
                    $entities[] = $this->ent('capability', $label, "Mentioned on: $title", $url, 'heuristic');
                }
            }
            foreach ($facilities as $f) {
                if (preg_match('/\b' . preg_quote($f, '/') . '\b/', $text)) {
                    $entities[] = $this->ent('facility_served', ucfirst($f) . (str_ends_with($f, 's') ? '' : 's'), "Referenced on $title", $url, 'heuristic');
                }
            }
            if (str_contains($url, 'privacy') || str_contains($text, 'privacy policy')) {
                $entities[] = $this->ent('policy', 'Privacy Policy', substr($page['text'], 0, 400), $url, 'heuristic');
            }
        }
        return $entities;
    }

    /**
     * @param list<array{url:string,title:?string,text:string}> $pages
     * @return list<array<string,mixed>>
     */
    public function extractWithLlm(LlmClient $llm, array $pages, string $baseUrl): array
    {
        $batch = array_slice($pages, 0, 8);
        $digests = [];
        foreach ($batch as $p) {
            $digests[] = [
                'url' => $p['url'],
                'title' => $p['title'],
                'text' => mb_substr($p['text'], 0, 3500),
            ];
        }
        $user = json_encode([
            'site_base_url' => $baseUrl,
            'pages' => $digests,
            'instruction' => 'Extract BKBS entities for this business.',
        ], JSON_UNESCAPED_UNICODE);
        $raw = $llm->chat(self::SYSTEM, (string) $user);
        $json = $this->parseJson($raw);
        $list = $json['entities'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (!is_array($item) || empty($item['name']) || empty($item['entity_type'])) {
                continue;
            }
            $item['source'] = 'llm';
            $out[] = $item;
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function ent(string $type, string $name, string $desc, string $url, string $source): array
    {
        return [
            'entity_type' => $type,
            'name' => $name,
            'description' => $desc,
            'properties' => new \stdClass(),
            'relationships' => [],
            'evidence' => [['url' => $url, 'snippet' => mb_substr($desc, 0, 200), 'kind' => 'text']],
            'trust_level' => 'medium',
            'source' => $source,
        ];
    }

    /** @return array<string,mixed> */
    private function parseJson(string $content): array
    {
        $content = trim($content);
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $m)) {
            $content = trim($m[1]);
        }
        $data = json_decode($content, true);
        if (is_array($data)) {
            return $data;
        }
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $data = json_decode(substr($content, $start, $end - $start + 1), true);
            if (is_array($data)) {
                return $data;
            }
        }
        return [];
    }
}
