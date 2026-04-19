<?php
/**
 * Image Proxy and Optimizer.
 *
 * Acts as a secure proxy to Immich asset endpoints.
 * CRITICAL: Specifically optimized for low-memory devices (iPad 2) by requesting
 * JPEG preview thumbnails instead of raw originals to prevent browser crashes.
 */
require_once __DIR__ . '/../vendor/autoload.php';
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$immichUrl = rtrim($_ENV['IMMICH_URL'], '/');
$apiKey = $_ENV['IMMICH_API_KEY'];

if (!isset($_GET['id'])) {
    http_response_code(400);
    exit("Missing ID");
}

$id = $_GET['id'];

// Fetch preview thumbnail from Immich
$ch = curl_init();
// Use 'preview' size (~1440px) instead of 'original' to save massive amounts of RAM on iPad 2.
// Force 'jpeg' because iOS 9/Safari does not support WebP.
curl_setopt($ch, CURLOPT_URL, $immichUrl . "/api/assets/" . $id . "/thumbnail?size=preview&format=jpeg");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    "x-api-key: " . $apiKey
));

$image = curl_exec($ch);
$contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Handle errors
if ($status !== 200) {
    header("Content-Type: application/json");
    echo $image;
    exit;
}

header("Content-Type: " . $contentType);
echo $image;
