<?php
declare(strict_types=1);

/**
 * Web installer for non-Python shared hosting.
 */
$root = __DIR__;
$configPath = $root . '/config.php';
$dataDir = $root . '/data';

function hinst(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$done = false;

if (is_file($configPath)) {
    $existing = require $configPath;
    if (!empty($existing['installed'])) {
        header('Location: index.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $publish = trim($_POST['default_publish_root'] ?? '');
    $appName = trim($_POST['app_name'] ?? 'BKBS Converter (PHP)');

    if (!is_dir($dataDir) && !@mkdir($dataDir, 0755, true)) {
        $errors[] = "Cannot create data directory: $dataDir (check permissions)";
    }
    if (is_dir($dataDir) && !is_writable($dataDir)) {
        $errors[] = "Data directory is not writable: $dataDir";
    }

    foreach (['pdo_sqlite', 'curl', 'json'] as $ext) {
        if (!extension_loaded($ext)) {
            $errors[] = "Required PHP extension missing: $ext";
        }
    }

    if (!$errors) {
        $dbPath = $dataDir . '/bkbs.sqlite';
        $export = [
            'installed' => true,
            'app_name' => $appName !== '' ? $appName : 'BKBS Converter (PHP)',
            'db_path' => $dbPath,
            'default_publish_root' => $publish,
            'installed_at' => gmdate('c'),
            'edition' => 'php',
        ];
        $php = "<?php\nreturn " . var_export($export, true) . ";\n";
        if (@file_put_contents($configPath, $php) === false) {
            $errors[] = "Cannot write config.php — make the folder writable during install.";
        } else {
            require $root . '/src/bootstrap.php';
            try {
                bkbs_db();
                @chmod($configPath, 0640);
                @chmod($dataDir, 0750);
                $done = true;
            } catch (Throwable $e) {
                $errors[] = 'Database init failed: ' . $e->getMessage();
                @unlink($configPath);
            }
        }
    }
}

// Detect real paths on this host (never invent /home/user/…)
require_once $root . '/src/bootstrap.php';
$suggest = function_exists('bkbs_best_publish_path') ? bkbs_best_publish_path() : '';
$candidates = function_exists('bkbs_detect_publish_paths') ? bkbs_detect_publish_paths() : [];
if ($suggest === '') {
    $parent = dirname($root);
    if (is_dir($parent . '/public_html')) {
        $suggest = $parent . '/public_html';
    } elseif (basename($parent) === 'public_html') {
        $suggest = $parent;
    } elseif (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $dr = rtrim(str_replace('\\', '/', (string) $_SERVER['DOCUMENT_ROOT']), '/');
        if (in_array(strtolower(basename($dr)), ['bkbs', 'bkbs-php'], true)) {
            $suggest = dirname($dr);
        } else {
            $suggest = $dr;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Install BKBS Converter (PHP)</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #0f1419; color: #e7ecf3; margin: 0; padding: 2rem; }
    .box { max-width: 560px; margin: 0 auto; background: #1e2a3a; border: 1px solid #2d3a4d; border-radius: 12px; padding: 1.5rem; }
    h1 { margin-top: 0; font-size: 1.35rem; }
    label { display: block; color: #9aa8bc; font-size: 0.85rem; margin: 0.75rem 0 0.3rem; }
    input { width: 100%; box-sizing: border-box; padding: 0.55rem 0.7rem; border-radius: 8px; border: 1px solid #2d3a4d; background: #0f1419; color: #e7ecf3; }
    button { margin-top: 1rem; background: #3b9eff; border: 0; color: #041018; font-weight: 700; padding: 0.6rem 1rem; border-radius: 8px; cursor: pointer; }
    .err { background: rgba(243,18,96,.12); border: 1px solid rgba(243,18,96,.35); color: #f31260; padding: .75rem; border-radius: 8px; margin-bottom: 1rem; }
    .ok { background: rgba(61,214,140,.12); border: 1px solid rgba(61,214,140,.35); color: #3dd68c; padding: .75rem; border-radius: 8px; }
    .muted { color: #9aa8bc; font-size: 0.9rem; }
    code { color: #62b1ff; }
    a { color: #3b9eff; }
  </style>
</head>
<body>
  <div class="box">
    <h1>Install BKBS Converter (PHP edition)</h1>
    <p class="muted">No Python required. For shared hosting (cPanel, Plesk, etc.).</p>

    <?php if ($done): ?>
      <div class="ok">
        Installation complete.
        <p><a href="index.php">Open the application →</a></p>
        <p class="muted">Optional: remove write access on this folder after install; keep <code>data/</code> writable.</p>
      </div>
    <?php else: ?>
      <?php foreach ($errors as $e): ?>
        <div class="err"><?= hinst($e) ?></div>
      <?php endforeach; ?>

      <p class="muted">PHP <?= hinst(PHP_VERSION) ?> ·
        PDO SQLite: <?= extension_loaded('pdo_sqlite') ? 'yes' : '<strong>NO</strong>' ?> ·
        cURL: <?= extension_loaded('curl') ? 'yes' : '<strong>NO</strong>' ?>
      </p>

      <form method="post">
        <label>Application name</label>
        <input name="app_name" value="BKBS Converter (PHP)" />

        <label>Default web root (where llms.txt is published)</label>
        <input id="default_publish_root" name="default_publish_root" value="<?= hinst($suggest) ?>"
               placeholder="Real path from File Manager — not /home/user/…" />
        <p class="muted">
          Folder of your <strong>main website</strong> (usually <code>public_html</code>),
          not the BKBS admin folder. Copy the full path from cPanel File Manager
          (example shape: <code>/home/<em>yourcpanelname</em>/public_html</code>).
          Never type the word <code>user</code> as the account name.
        </p>
        <?php if ($candidates): ?>
          <p class="muted"><strong>Detected on this server:</strong></p>
          <ul class="muted" style="padding-left:1.2rem">
            <?php foreach ($candidates as $c): ?>
              <li>
                <a href="#" onclick="document.getElementById('default_publish_root').value=<?= hinst(json_encode($c['path'])) ?>;return false;">
                  <code><?= hinst($c['path']) ?></code>
                </a>
                — <?= hinst($c['label']) ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <button type="submit">Install now</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>
