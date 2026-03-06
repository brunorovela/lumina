<?php

declare(strict_types=1);
/**
 * Watcher config — hot reload em desenvolvimento.
 * No Docker use o driver ScanFileDriver (não depende de fswatch).
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 */
use Hyperf\Watcher\Driver\ScanFileDriver;

return [
    'driver' => ScanFileDriver::class,
    'bin' => PHP_BINARY,
    'watch' => [
        'dir' => ['app', 'config'],
        'file' => ['.env'],
        'scan_interval' => 2000,
    ],
    'ext' => ['.php', '.env'],
];
