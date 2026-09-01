<?php

declare(strict_types=1);

$type = strtolower(trim((string) ($_GET['type'] ?? 'diagnostics')));
$file = basename(trim((string) ($_GET['file'] ?? '')));
$roots = [
    'diagnostics' => '/boot/config/plugins/md12xx.fancontrol/diagnostics',
    'commissioning' => '/boot/config/plugins/md12xx.fancontrol/commissioning',
];

if (!isset($roots[$type]) || !preg_match('/^[a-zA-Z0-9._-]+\.(?:zip|tar\.gz)$/', $file)) {
    http_response_code(400);
    exit('Invalid download request');
}

$root = realpath($roots[$type]);
$path = realpath($roots[$type] . '/' . $file);
if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Archive not found');
}

header('Content-Type: ' . (str_ends_with($file, '.tar.gz') ? 'application/gzip' : 'application/zip'));
header('Content-Disposition: attachment; filename="' . addcslashes($file, "\"\\") . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: no-store');
readfile($path);
