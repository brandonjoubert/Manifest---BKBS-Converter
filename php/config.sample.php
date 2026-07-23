<?php
/**
 * Copy to config.php via install.php (do not edit sample on production).
 */
return [
    'installed' => false,
    'app_name' => 'BKBS Converter (PHP)',
    'db_path' => __DIR__ . '/data/bkbs.sqlite',
    'default_publish_root' => '', // e.g. /home/user/public_html or dirname(__DIR__)
    'admin_password' => '', // optional simple lock (future)
];
