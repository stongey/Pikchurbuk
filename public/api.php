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
$useSchedules = ($_GET['schedule'] ?? 'true') !== 'false'; // Default true unless explicitly 'false'
$delay   = isset($_GET['delay']) ? (int)$_GET['delay'] : (int)($_ENV['REFRESH_DELAY'] ?? 30);
$albumId = $_GET['albumId'] ?? null;
$showName = ($_GET['showName'] ?? 'true') !== 'false';
$personId = $_GET['personId'] ?? null;

$scheduleName = null;
$scheduleColor = null;

if ($useSchedules) {
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
}

$asset = null;
$lightAsset = null;

// Priority 1: Check if a specific asset ID was requested in the URL
$specificId = $_GET['id'] ?? null;
if ($specificId) {
    $asset = $immich->getAssetById($specificId);
    if (!$immich->isAssetValid($asset)) {
        $asset = null;
    }
}

$maxRetries = 5;
for ($attempt = 0; $asset === null && $attempt < $maxRetries; $attempt++) {
    $lightAsset = null; // Reset for each search attempt
    if ($mode === 'memory' || !empty($scheduleName) || ($mode === 'mixed' && rand(0, 1) === 0)) {
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

$dimensions = ($width && $height) ? "$width × $height" : "";
$aspect = Metadata::getAspectRatio($width, $height);

// Fix regression: do not show ratio if it is the same as the dimensions
if ($aspect === "{$width}:{$height}") {
    $aspect = "";
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
$firstNames = [];


// In Immich v3, the asset response no longer contains people. 
// We must fetch faces directly from the dedicated faces API endpoint.
$faces = $immich->getAssetFaces($asset['id']) ?? [];

if (!empty($faces) && is_array($faces)) {
    // Sort faces by spatial location in the photo: Left to Right, then Top to Bottom
    usort($faces, function($a, $b) {
        // v3 coordinates standard: boundingBoxX1, boundingBoxX2, etc.
        $ax = $a['boundingBoxX1'] ?? null;
        $ay = $a['boundingBoxY1'] ?? null;
        $bx = $b['boundingBoxX1'] ?? null;
        $by = $b['boundingBoxY1'] ?? null;

        // Push faces without coordinates to the end
        if ($ax === null && $bx === null) return 0;
        if ($ax === null) return 1;
        if ($bx === null) return -1;

        // Space sorting: Left-to-Right first, then Top-to-Bottom fallback
        return ($ax <=> $bx) ?: ($ay <=> $by);
    });

    foreach ($faces as $face) {
        $person = $face['person'] ?? null;
        if (!$person) continue;

        $name = $person['name'] ?? '';
        if (empty($name)) continue;

        $age = Metadata::calculateAge($asset['fileCreatedAt'], $person['birthDate'] ?? null);
        $peopleList[] = "<span style='white-space: nowrap;'>" . htmlspecialchars($name) . ($age ? " <span style='font-size: 0.85em; opacity: 0.7;'>($age)</span>" : "") . "</span>";

        // Extract first name for fallback description
        $honorificsPattern = '/^(dr|fr|mr|ms|mrs|prof|rev|sr|br|msgr|reverend|father|doctor|mme|m|)\.?$/i';
        $cleanName = preg_replace('/\s+/', ' ', trim($name));
        $nameParts = explode(' ', trim($cleanName));
        
        $firstName = 'Guest'; // Fallback default
        foreach ($nameParts as $part) {
            if (!preg_match($honorificsPattern, $part) && $part !== '') {
                $firstName = $part;
                break;
            }
        }
        $firstNames[] = htmlspecialchars($firstName);
    }
}


$address = Metadata::formatLocation($asset['exifInfo'] ?? []);

// Ensure lightAsset is at least an empty array for safe logging and size fallback logic
$lightAsset = $lightAsset ?: [];
$rawSize = $asset['fileSizeInBytes'] ?? 
           $asset['size'] ?? 
           $asset['exifInfo']['fileSizeInByte'] ?? 
           $asset['exifInfo']['fileSizeInBytes'] ??
           $lightAsset['fileSizeInBytes'] ?? 
           $lightAsset['size'] ?? 
           $lightAsset['exifInfo']['fileSizeInByte'] ?? 
           $lightAsset['exifInfo']['fileSizeInBytes'] ?? 0;

app_log("Size Debug - Asset ID: {$asset['id']}");
app_log("  asset[fileSizeInBytes]: " . ($asset['fileSizeInBytes'] ?? 'NOT SET'));
app_log("  asset[size]: " . ($asset['size'] ?? 'NOT SET'));
app_log("  asset[exifInfo][fileSizeInByte]: " . ($asset['exifInfo']['fileSizeInByte'] ?? 'NOT SET'));
app_log("  lightAsset[fileSizeInBytes]: " . ($lightAsset['fileSizeInBytes'] ?? 'NOT SET'));
app_log("  lightAsset[size]: " . ($lightAsset['size'] ?? 'NOT SET'));
app_log("  Final bytes resolved: $rawSize");

$info = [
    'People'     => implode(', ', $peopleList),
    'Camera'     => $camera,
    'Lens'       => $lens,
    'Exposure'   => isset($exif['exposureTime']) ? $exif['exposureTime'] . 's' : '',
    'Aperture'   => isset($exif['fNumber']) ? 'ƒ' . $exif['fNumber'] : '',
    'ISO'        => $exif['iso'] ?? '',
    'Focal'      => isset($exif['focalLength']) ? $exif['focalLength'] . 'mm' : '',
    'Dimensions' => $dimensions,
    'MP'         => $megapixels,
    'Aspect'     => $aspect,
    'Size'       => Metadata::formatFileSize($rawSize),
    'File'       => $asset['originalPath'] ?? '',
    'Location'   => ($lat && $lon) ? sprintf("%.4f, %.4f", $lat, $lon) : $address,
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
$memoryStr = null;

// Compare calendar dates (Month and Day) in the local timezone
if ($yearsAgo > 0 && $photoDate->format('m-d') === $currentDate->format('m-d')) {
    $unit = ($yearsAgo === 1) ? " year ago today" : " years ago today";
    $memoryStr = $yearsAgo . $unit;
}

// Determine description: Schedule -> Metadata Description -> First Names Fallback
$description = $scheduleName ?: Metadata::formatDescription($asset['exifInfo'] ?? []);
if (empty($description) && !empty($firstNames)) {
    $description = implode(', ', $firstNames);
}

// Add Album/Person name to metadata if in those modes
if ($showName) {
    if (!empty($personId)) {
        $person = $immich->getPersonById($personId);
        if ($person && !empty($person['name'])) {
            $memoryStr = ($memoryStr ? $memoryStr . " • " : "") . $person['name'];
        }
    }
    if (!empty($albumId)) {
        $album = $immich->getAlbumById($albumId);
        if ($album && !empty($album['albumName'])) {
            $memoryStr = ($memoryStr ? $memoryStr . " • " : "") . $album['albumName'];
        }
    }
}

echo json_encode([
    'id'          => $asset['id'],
    'location'    => $address,
    'date'        => $dateStr,
    'memory'      => $memoryStr,
    'description' => $description,
    'delay'       => $delay,
    'isNight'     => $isNight,
    'isScheduled' => !empty($scheduleName),
    'scheduleName' => $scheduleName,
    'scheduleColor' => $scheduleColor,
    'exifDetails' => $info,
    'map'         => $mapData, // Coordinates for Leaflet
    'mapsUrl'     => ($lat && $lon) ? Metadata::getGoogleMapsUrl($lat, $lon) : null,
]);