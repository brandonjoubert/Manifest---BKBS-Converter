<?php
declare(strict_types=1);

/**
 * BKBS Converter — PHP edition front controller.
 * Upload this whole `php/` folder to shared hosting (no Python required).
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require __DIR__ . '/src/bootstrap.php';

// First-time install
if (!bkbs_installed()) {
    $r = $_GET['r'] ?? '';
    if ($r !== 'install' && !str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'install.php')) {
        header('Location: install.php');
        exit;
    }
}

$route = $_GET['r'] ?? 'home';
if ($route === 'install') {
    header('Location: install.php');
    exit;
}

try {
    (new \Bkbs\Router())->dispatch((string) $route, $_SERVER['REQUEST_METHOD'] ?? 'GET');
} catch (Throwable $e) {
    http_response_code(500);
    echo '<h1>Error</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
}
