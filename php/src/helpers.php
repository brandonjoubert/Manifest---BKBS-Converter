<?php
declare(strict_types=1);

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash_set(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_get(): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

function base_path(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    if ($dir === '/' || $dir === '\\' || $dir === '.') {
        return '';
    }
    return rtrim($dir, '/');
}

function url(string $path = ''): string
{
    $base = base_path();
    $path = ltrim($path, '/');
    if ($path === '') {
        return $base === '' ? '/' : $base . '/';
    }
    return ($base === '' ? '' : $base) . '/index.php?r=' . rawurlencode($path);
}

function render(string $template, array $vars = []): void
{
    $root = dirname(__DIR__);
    extract($vars, EXTR_SKIP);
    $flash = flash_get();
    $cfg = bkbs_config();
    $appName = $cfg['app_name'] ?? 'Manifest BKBS Converter (PHP)';
    require $root . '/templates/layout_header.php';
    require $root . '/templates/' . $template . '.php';
    require $root . '/templates/layout_footer.php';
}

function bkbs_json(array $payload, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bkbs_json_error(int $code, string $message): never
{
    if ($code === 401) {
        header('WWW-Authenticate: Bearer');
    }
    bkbs_json(['error' => $message], $code);
}

function bkbs_request_api_token(): string
{
    $hdr = '';
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp((string) $k, 'Authorization') === 0) {
                $hdr = (string) $v;
                break;
            }
        }
    }
    if ($hdr === '') {
        $hdr = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    }
    if (stripos($hdr, 'Bearer ') === 0) {
        return trim(substr($hdr, 7));
    }
    $key = '';
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp((string) $k, 'X-API-Key') === 0) {
                $key = (string) $v;
                break;
            }
        }
    }
    if ($key === '') {
        $key = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
    }
    return trim($key);
}

function bkbs_api_token(): string
{
    $cfg = bkbs_config();
    $fromCfg = trim((string) ($cfg['api_token'] ?? ''));
    if ($fromCfg !== '') {
        return $fromCfg;
    }
    $env = trim((string) (getenv('BKBS_API_TOKEN') ?: ''));
    if ($env !== '') {
        return $env;
    }
    $db = bkbs_db();
    $stored = trim((string) ($db->getSetting('api.token', '') ?? ''));
    if ($stored !== '') {
        return $stored;
    }
    $token = bin2hex(random_bytes(24));
    $db->setSetting('api.token', $token);
    return $token;
}

function bkbs_require_api_auth(): void
{
    $provided = bkbs_request_api_token();
    $expected = bkbs_api_token();
    if ($provided === '' || !hash_equals($expected, $provided)) {
        bkbs_json_error(401, 'Unauthorized');
    }
}

function uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function external_key(string $siteId, string $type, string $name): string
{
    $norm = strtolower(trim(preg_replace('/\s+/', ' ', $name) ?? $name));
    return substr(hash('sha256', $siteId . '|' . $type . '|' . $norm), 0, 32);
}

function entity_types(): array
{
    return [
        'business_identity' => 'Business Identity',
        'product_service' => 'Products & Services',
        'capability' => 'Capabilities',
        'expertise' => 'Expertise',
        'facility_served' => 'Facilities Served',
        'operational_problem' => 'Operational Problems',
        'project' => 'Projects',
        'knowledge_article' => 'Knowledge Articles',
        'policy' => 'Policies',
        'team' => 'Team',
        'asset' => 'Assets',
        'relationship' => 'Relationships',
    ];
}

/**
 * Detect likely public web-root paths on this PHP host (shared hosting helpers).
 *
 * @return list<array{path:string,label:string,writable:bool}>
 */
function bkbs_detect_publish_paths(): array
{
    $candidates = [];

    $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if (is_string($docRoot) && $docRoot !== '') {
        $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
        // App often lives in …/public_html/bkbs — publish to parent public_html
        $base = basename($docRoot);
        if (in_array(strtolower($base), ['bkbs', 'bkbs-php', 'bkbs-converter', 'admin'], true)) {
            $parent = dirname($docRoot);
            $candidates[] = ['path' => $parent, 'label' => 'Parent of this app folder (usual public site root)'];
        }
        $candidates[] = ['path' => $docRoot, 'label' => 'This app’s document root (only if the whole site is here)'];
    }

    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    if (is_string($home) && $home !== '' && is_dir($home)) {
        foreach (['public_html', 'www', 'httpdocs', 'htdocs'] as $web) {
            $p = rtrim(str_replace('\\', '/', $home), '/') . '/' . $web;
            if (is_dir($p)) {
                $candidates[] = ['path' => $p, 'label' => "Detected ~/$web"];
            }
        }
    }

    // Realpath of the PHP app directory
    $appDir = str_replace('\\', '/', dirname(__DIR__));
    $appParent = dirname($appDir);
    if (is_dir($appParent) && basename(strtolower($appParent)) !== 'home') {
        $candidates[] = ['path' => $appParent, 'label' => 'Parent of the BKBS PHP folder'];
    }

    // De-dupe and check writability
    $seen = [];
    $out = [];
    foreach ($candidates as $c) {
        $path = $c['path'];
        if ($path === '' || isset($seen[$path])) {
            continue;
        }
        // Skip obvious placeholders
        if (preg_match('#/home/(user|username)/#i', $path)) {
            continue;
        }
        $seen[$path] = true;
        $exists = is_dir($path);
        $writable = $exists && is_writable($path);
        $out[] = [
            'path' => $path,
            'label' => $c['label'] . ($exists ? ($writable ? ' — writable' : ' — exists, check permissions') : ' — not found'),
            'writable' => $writable,
            'exists' => $exists,
        ];
    }
    return $out;
}

/** Best guess for default publish root on this host. */
function bkbs_best_publish_path(): string
{
    $paths = bkbs_detect_publish_paths();
    foreach ($paths as $p) {
        if (!empty($p['writable'])) {
            return $p['path'];
        }
    }
    foreach ($paths as $p) {
        if (!empty($p['exists'])) {
            return $p['path'];
        }
    }
    return $paths[0]['path'] ?? '';
}
