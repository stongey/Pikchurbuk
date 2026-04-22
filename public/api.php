<?php
/**
 * Main API controller for the Pikchurbuk photo frame.
 *
 * This script handles:
 * 1. Night mode state detection based on .env hours.
 * 2. Schedule/Event mode logic using schedule.json.
 * 3. Asset selection (Random, Memory, or Mixed) from Immich.
 * 4. Extraction, cleaning, and formatting of EXIF, Person, and Map metadata.
 */
require_once __DIR__ . '/../vendor/autoload.php';

use Stongey\Pikchurbuk\ImmichClient;
use Stongey\Pikchurbuk\Metadata;
use Dotenv\Dotenv;

// Load config
$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

date_default_timezone_set($_ENV['TIMEZONE'] ?? 'UTC');

/**
 * Helper for conditional server-side logging based on .env toggle.
 */
function app_log($message) {
    if (strtolower($_ENV['ENABLE_LOGGING'] ?? 'false') === 'true') {
        error_log("Pikchurbuk: " . $message);
    }
}

$startHour = (int)($_ENV['NIGHT_MODE_START'] ?? 22);
$endHour = (int)($_ENV['NIGHT_MODE_END'] ?? 7);
$currentHour = (int)date('G'); // 24-hour format without leading zeros

$isNight = false;
if ($startHour > $endHour) {
    // Overnights (e.g., 22 to 7)
    if ($currentHour >= $startHour || $currentHour < $endHour) {
        $isNight = true;
    }
} else {
    // Same day (e.g., 9 to 17)
    if ($currentHour >= $startHour && $currentHour < $endHour) {
        $isNight = true;
    }
}

header('Content-Type: application/json');

// Pass the tags from .env to the client
$immich = new ImmichClient(
    $_ENV['IMMICH_URL'], 
    $_ENV['IMMICH_API_KEY'],
    $_ENV['EXCLUDED_TAG_IDS'] ?? ""
);

$mode    = $_GET['mode'] ?? 'random';
$albumId = $_GET['albumId'] ?? null;
$personId = $_GET['personId'] ?? null;

$scheduleName = null;
$scheduleColor = null;

if ($mode === 'schedule') {
    $schedulePath = __DIR__ . '/../schedule.json';
    $matchFound = false;
    
    if (file_exists($schedulePath)) {
        $json = file_get_contents($schedulePath);
        $schedules = json_decode($json, true);

        if (is_array($schedules)) {
            $today = date('m-d');
            app_log("Checking schedules for today ($today)");
            
            foreach ($schedules as $s) {
                $start = $s['startDate'];
                $end = $s['endDate'];
                
                app_log("Testing schedule '{$s['name']}' ($start to $end)");

                // Check if the date is within range, including year-wraparound logic
                $isMatch = false;
                if ($start <= $end) {
                    $isMatch = ($today >= $start && $today <= $end);
                } else {
                    // Wraps around (e.g. 12-25 to 01-05)
                    $isMatch = ($today >= $start || $today <= $end);
                }

                if ($isMatch) {
                    app_log("MATCH FOUND - Event: {$s['name']}, Album ID: {$s['albumId']}");
                    $scheduleName = $s['name'] ?? null;
                    $scheduleColor = $s['color'] ?? null;
                    $albumId = $s['albumId'];
                    $matchFound = true;
                    break;
                }
            }
        }
    } else {
        app_log("schedule.json not found at $schedulePath");
    }
    
    if (!$matchFound) {
        app_log("No schedule match found. Falling back to random.");
        $mode = 'random';
    }
}

$asset = null;
$maxRetries = 5;

for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
    $lightAsset = null;
    if ($mode === 'memory' || $mode === 'schedule' || ($mode === 'mixed' && rand(0, 1) === 0)) {
        app_log("Attempting memory search for Album: " . ($albumId ?? 'All'));
        $lightAsset = $immich->getMemoryAsset($albumId, $personId);
    }

    if (!$lightAsset) {
        app_log("Attempting random search for Album: " . ($albumId ?? 'All'));
        $lightAsset = $immich->getRandomAsset($albumId, $personId);
    }

    if (!$lightAsset) break;

    $asset = $immich->getAssetById($lightAsset['id']);
    if ($immich->isAssetValid($asset)) {
        break;
    } else {
        app_log("Asset {$asset['id']} filtered out after second hop. Retrying...");
        $asset = null;
    }
}

if (!$asset) {
    die(json_encode(['error' => 'No asset found after filtering']));
}
$exif  = $asset['exifInfo'] ?? [];
$width  = $exif['exifImageWidth'] ?? $asset['width'] ?? 0;
$height = $exif['exifImageHeight'] ?? $asset['height'] ?? 0;
$megapixels = ($width && $height) ? round(($width * $height) / 1000000, 1) . ' MP' : '';
$lat   = $exif['latitude'] ?? null;
$lon   = $exif['longitude'] ?? null;

$aspect = Metadata::getAspectRatio($width, $height);
$resolution = $megapixels ? "$width × $height ($megapixels)" : "";
if ($aspect && $aspect !== "$width:$height") {
    $resolution = $resolution ? "$resolution — $aspect" : $aspect;
}

$make = trim($exif['make'] ?? '');
$model = trim($exif['model'] ?? '');

$make = preg_replace('/\s+(EASTMAN|CORPORATION|COMPANY|INC\.?|LTD\.?)/i', '', $make);

$camera = ($make !== '' && stripos($model, $make) === 0) ? $model : trim($make . ' ' . $model);

$lens = trim($exif['lensModel'] ?? '');
if ($lens !== '') {
    if ($make !== '' && stripos($lens, $make) === 0) {
        $lens = trim(substr($lens, strlen($make)));
    }
    if ($model !== '' && stripos($lens, $model) === 0) {
        $lens = trim(substr($lens, strlen($model)));
    }

    $lens = preg_replace('/\s*[\d\.]+(?:-[\d\.]+)?mm/i', '', $lens);
    $lens = preg_replace('/\s*f\/\d+(\.\d+)?/i', '', $lens);

    $lens = preg_replace('/\b(back camera|front camera|back|front)\b/i', '', $lens);

    $lens = trim($lens, " ,-_");
}

$peopleList = [];
if (!empty($asset['people'])) {
    $people = $asset['people'];

    // Sort people by location in photo: Left to Right, then Top to Bottom
    usort($people, function($a, $b) {
        // Fallback to nested faces array if top-level coordinates are missing
        $fa = (!empty($a['faces']) && is_array($a['faces'])) ? $a['faces'][0] : $a;
        $fb = (!empty($b['faces']) && is_array($b['faces'])) ? $b['faces'][0] : $b;

        $ax = $fa['x1'] ?? $fa['boundingBoxX1'] ?? null;
        $ay = $fa['y1'] ?? $fa['boundingBoxY1'] ?? null;
        $bx = $fb['x1'] ?? $fb['boundingBoxX1'] ?? null;
        $by = $fb['y1'] ?? $fb['boundingBoxY1'] ?? null;

        // Push people without face coordinates to the end
        if ($ax === null && $bx === null) return 0;
        if ($ax === null) return 1;
        if ($bx === null) return -1;

        return ($ax <=> $bx) ?: ($ay <=> $by);
    });

    foreach ($people as $person) {
        $name = $person['name'] ?? '';
        if (empty($name)) continue;

        $age = Metadata::calculateAge($asset['fileCreatedAt'], $person['birthDate'] ?? null);
        $peopleList[] = "<span style='white-space: nowrap;'>" . $name . ($age ? " <span style='font-size: 0.85em; opacity: 0.7;'>($age)</span>" : "") . "</span>";
    }
}

$info = [
    'People'     => implode(', ', $peopleList),
    'Camera'     => $camera,
    'Lens'       => $lens,
    'Exposure'   => $exif['exposureTime'] ?? '',
    'Aperture'   => isset($exif['fNumber']) ? 'f/' . $exif['fNumber'] : '',
    'ISO'        => $exif['iso'] ?? '',
    'Focal'      => isset($exif['focalLength']) ? $exif['focalLength'] . 'mm' : '',
    'Resolution' => $resolution,
    'Size'       => Metadata::formatFileSize($asset['fileSizeInBytes'] ?? 0),
    'File'       => $asset['originalPath'] ?? '',
    'Location'   => ($lat && $lon) ? "$lat, $lon" : "",
];

$mapData = ($lat && $lon) ? ['lat' => $lat, 'lon' => $lon] : null;

$info = array_filter($info, function($value) {
    $cleaned = strtolower(trim($value));
    return !empty($cleaned) && $cleaned !== 'standard lens' && $cleaned !== 'unknown lens';
});

// Calculate memory info (On This Day)
// Use dateTimeOriginal for historical accuracy, fallback to fileCreatedAt (upload date)
$rawDate = $asset['exifInfo']['dateTimeOriginal'] ?? $asset['fileCreatedAt'];
$photoDate = new \DateTime($rawDate);
$photoDate->setTimezone(new \DateTimeZone(date_default_timezone_get()));

$currentDate = new \DateTime('now', new \DateTimeZone(date_default_timezone_get()));
$yearsAgo = (int)$currentDate->format('Y') - (int)$photoDate->format('Y');
$dateStr = Metadata::formatDate($rawDate);

// Compare calendar dates (Month and Day) in the local timezone
if ($yearsAgo > 0 && $photoDate->format('m-d') === $currentDate->format('m-d')) {
    $unit = ($yearsAgo === 1) ? " year ago today" : " years ago today";
    $dateStr .= " — " . $yearsAgo . $unit;
}

echo json_encode([
    'id'          => $asset['id'],
    'location'    => Metadata::formatLocation($asset['exifInfo'] ?? []),
    'date'        => $dateStr,
    'description' => $scheduleName ?: Metadata::formatDescription($asset['exifInfo'] ?? []),
    'delay'       => (int)($_ENV['REFRESH_DELAY'] ?? 30),
    'isNight'     => $isNight,
    'isScheduled' => !empty($scheduleName),
    'scheduleName' => $scheduleName,
    'scheduleColor' => $scheduleColor,
    'exifDetails' => $info,
    'map'         => $mapData, // Coordinates for Leaflet
    'mapsUrl'     => ($lat && $lon) ? Metadata::getGoogleMapsUrl($lat, $lon) : null,
]);