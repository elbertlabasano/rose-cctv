<?php

// PHP development router; document root is public, never the project directory.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath(__DIR__.'/public'.rawurldecode($path ?: '/'));
$root = realpath(__DIR__.'/public').DIRECTORY_SEPARATOR;
if ($file && str_starts_with($file, $root) && is_file($file)) {
    return false;
}
require __DIR__.'/public/index.php';
