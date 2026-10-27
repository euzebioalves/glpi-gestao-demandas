<?php
declare(strict_types=1);
// CLI only, metadata only. Does not boot GLPI, read config or connect to a database.
if (PHP_SAPI !== 'cli') exit(1);
$root = '/var/www/glpi';
$metadata = $root . '/vendor/composer/installed.json';
$lock = json_decode(file_get_contents($metadata), true, flags: JSON_THROW_ON_ERROR);
$packages = [];
foreach ($lock['packages'] as $package) {
    $packages[] = ['name' => $package['name'], 'version' => $package['version'], 'license' => $package['license'] ?? []];
}
echo json_encode(['php' => PHP_VERSION, 'installed_metadata_sha256' => hash_file('sha256', $metadata), 'packages' => $packages], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
