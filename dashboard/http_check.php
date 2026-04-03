<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'ok' => true,
    'php_version' => PHP_VERSION,
    'curl_init' => function_exists('curl_init'),
    'allow_url_fopen' => (bool) ini_get('allow_url_fopen'),
    'loaded_extensions' => array_values(array_filter([
        extension_loaded('curl') ? 'curl' : null,
        extension_loaded('openssl') ? 'openssl' : null,
        extension_loaded('mbstring') ? 'mbstring' : null,
    ])),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

