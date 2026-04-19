<?php
/**
 * cache_cleanup.php
 * 
 * Cleans up old geocoding cache files to prevent disk bloat.
 * Recommended to run via cron (e.g., daily).
 */

$cacheDir = __DIR__ . '/../cache';
$maxAge = 30 * 24 * 60 * 60; // Keep files for 30 days
$maxFiles = 2000;           // Keep a maximum of 2000 files

if (!is_dir($cacheDir)) {
    exit("Cache directory does not exist: $cacheDir\n");
}

$files = glob($cacheDir . '/geocode_*.json');
if ($files === false) {
    exit("Could not read cache directory.\n");
}

$now = time();
$deletedCount = 0;

echo "Analyzing " . count($files) . " cache files...\n";

// Sort files by modification time (oldest first)
usort($files, function($a, $b) {
    return filemtime($a) - filemtime($b);
});

// 1. Delete files exceeding max age
foreach ($files as $key => $file) {
    if (($now - filemtime($file)) > $maxAge) {
        if (unlink($file)) {
            $deletedCount++;
            unset($files[$key]);
        }
    }
}

// 2. Delete oldest files if still exceeding max capacity
if (count($files) > $maxFiles) {
    $toDelete = array_slice($files, 0, count($files) - $maxFiles);
    foreach ($toDelete as $file) {
        if (unlink($file)) {
            $deletedCount++;
        }
    }
}

echo "Cleanup complete. Total files deleted: $deletedCount\n";