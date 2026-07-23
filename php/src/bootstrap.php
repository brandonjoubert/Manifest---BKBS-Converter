<?php
declare(strict_types=1);

/**
 * BKBS Converter — PHP edition bootstrap (shared hosting, no Python).
 */

const BKBS_PHP_VERSION = '1.0.0';

$BKBS_ROOT = dirname(__DIR__);
$BKBS_CONFIG = $BKBS_ROOT . '/config.php';

spl_autoload_register(function (string $class): void {
    $map = [
        'Bkbs\\Database' => __DIR__ . '/Database.php',
        'Bkbs\\Crawler' => __DIR__ . '/Crawler.php',
        'Bkbs\\Extractor' => __DIR__ . '/Extractor.php',
        'Bkbs\\LlmClient' => __DIR__ . '/LlmClient.php',
        'Bkbs\\Publisher' => __DIR__ . '/Publisher.php',
        'Bkbs\\Router' => __DIR__ . '/Router.php',
    ];
    if (isset($map[$class])) {
        require_once $map[$class];
    }
});

require_once __DIR__ . '/helpers.php';

function bkbs_config(): array
{
    global $BKBS_CONFIG;
    static $cfg;
    if ($cfg !== null) {
        return $cfg;
    }
    if (!is_file($BKBS_CONFIG)) {
        return [];
    }
    $cfg = require $BKBS_CONFIG;
    return is_array($cfg) ? $cfg : [];
}

function bkbs_installed(): bool
{
    $cfg = bkbs_config();
    return !empty($cfg['installed']) && is_file($cfg['db_path'] ?? '');
}

function bkbs_db(): \Bkbs\Database
{
    static $db;
    if ($db === null) {
        $cfg = bkbs_config();
        if (empty($cfg['db_path'])) {
            throw new RuntimeException('Database not configured. Run install.php');
        }
        $db = new \Bkbs\Database($cfg['db_path']);
    }
    return $db;
}
