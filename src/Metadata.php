<?php
namespace Stongey\Pikchurbuk;

use GuzzleHttp\Client;

/**
 * Utility class for processing and formatting photo metadata.
 * 
 * Handles reverse geocoding with caching, date manipulation, and camera/lens string sanitization.
 */
class Metadata {
    private static $userAgent = 'Pikchurbuk/1.0';

    /**
     * Map of strings to find and replace in the final location string.
     * format: 'Search Term' => 'Replacement Term'
     */
    private static $geoReplacements = [
        'United States of America' => 'USA',
        'United Kingdom'           => 'UK',
        'Province of '             => '',
        'State of '                => '',
        'District of '              => '',
        'Region of '                => '',
        'County of '                => '',
        'Prefecture of '            => '',
        ' Municipal Unit'           => '',
        'Municipality of '          => '',
        'City of '                  => '',
        'Sofia-City'                => 'Sofia',
        'Rideau Lakes'              => 'Knowles Cottage',
        'Sintra (Santa Maria e São Miguel, São Martinho e São Pedro de Penaferrim), Sintra, ' => 'Sintra, ',
        // Add your custom ones here
    ];

    /**
     * List of manufacturer-specific strings to remove from descriptions.
     */
    private static $manufacturerBaggage = [
        'OLYMPUS DIGITAL CAMERA',
        'SONY DSC',
        'fujifilm',
        'NIKON CORPORATION',
        'Canon EOS',
        'Apple iPhone',
        'Processed with',
        'TOSHIBA Exif JPEG',
        '<Digimax S500 / Kenox S500 / Digimax Cyber 530>'
        // Add any specific strings you see in your library here
    ];

    private static function applyReplacements($locationString) {
        if (empty($locationString)) return "";

        foreach (self::$geoReplacements as $search => $replace) {
            $locationString = str_replace($search, $replace, $locationString);
        }

        // Clean up double commas or trailing spaces that might result from replacements
        $locationString = trim(str_replace(', ,', ',', $locationString), ', ');

        $parts = explode(',', $locationString);
        $parts = array_map('trim', $parts);
        $parts = array_unique($parts);
        
        $standaloneCountries = ['United States of America', 'United States', 'USA', 'US', 'Canada', 'CAN', 'CA'];
        $parts = array_filter($parts, function($part) use ($standaloneCountries) {
            return !in_array($part, $standaloneCountries);
        });

        // 5. Join back together
        $finalString = implode(', ', $parts);

        return trim($finalString, ', ');    
    }

    /**
     * Resolves a human-readable location string from GPS coordinates.
     * Uses a three-tier approach: 1. Manual EXIF, 2. Local JSON Cache, 3. Nominatim API.
     *
     * @param array $exif The asset EXIF data array.
     * @param string $baseUrl The geocoding service URL.
     * @return string
     */
    public static function formatLocation($exif, $baseUrl = "https://nominatim.openstreetmap.org") {
        $lat = $exif['latitude'] ?? null;
        $lon = $exif['longitude'] ?? null;

        // 1. If no GPS coordinates, try Immich's built-in geodata
        if (!$lat || !$lon) {
            $parts = array_filter([$exif['city'] ?? '', $exif['state'] ?? '', $exif['country'] ?? '']);
            return !empty($parts) ? implode(', ', $parts) : "";
        }

        // 2. Check File Cache (~100m precision)
        $cacheDir = __DIR__ . '/../cache';
        if (!is_dir($cacheDir)) mkdir($cacheDir, 0755, true);
        
        $cacheKey = round($lat, 4) . '_' . round($lon, 4);
        $cacheFile = $cacheDir . '/geocode_' . $cacheKey . '.json';

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
            $data = json_decode(file_get_contents($cacheFile), true);
            return self::parseGeocodeResponse($data);
        }

        // 3. Perform Reverse Geocoding (Server-to-Server)
        $client = new Client(['base_uri' => $baseUrl, 'timeout' => 5.0]);
        
        try {
            // Step A: Detect Language (your logic)
            $lang = self::getGeocodeLanguage($client, $lat, $lon);

            // Step B: Get Full Address
            $response = $client->get('reverse', [
                'query' => [
                    'format' => 'json',
                    'lat' => $lat,
                    'lon' => $lon,
                    'zoom' => 18,
                    'addressdetails' => 1,
                    'accept-language' => $lang
                ],
                'headers' => ['User-Agent' => self::$userAgent]
            ]);

            $resData = json_decode($response->getBody(), true);
            file_put_contents($cacheFile, json_encode($resData));
            
            return self::parseGeocodeResponse($resData);

        } catch (\Exception $e) {
            return "Unknown Location";
        }
    }

    private static function getGeocodeLanguage($client, $lat, $lon) {
        try {
            $res = $client->get('reverse', [
                'query' => ['format' => 'json', 'lat' => $lat, 'lon' => $lon, 'zoom' => 10, 'addressdetails' => 1],
                'headers' => ['User-Agent' => self::$userAgent]
            ]);
            $data = json_decode($res->getBody(), true);
            //error_log("DEBUG EXIF DETAILS: " . print_r($data, true));

            $addr = $data['address'] ?? [];
            
            $cc = strtoupper($addr['country_code'] ?? '');
            $state = strtolower($addr['state'] ?? $addr['province'] ?? '');

            // Québec override (handle accents + common variants)
            if ((isset($addr['ISO3166-2-lvl4']) && $addr['ISO3166-2-lvl4'] === 'CA-QC') || $state === 'quebec' || $state === 'québec') {
                return 'fr';
            }
            if (isset($addr['ISO3166-2-lvl4']) && $addr['ISO3166-2-lvl4'] === 'BE-VLG') {
                return 'nl';
            }

            $langMap = [
                'FR' => 'fr',
                'BE' => 'fr', // partial, but common fallback
                'CH' => 'de', // could vary (de/fr/it), pick default
                'AT' => 'de',
                'DE' => 'de',
                'CZ' => 'cs',
                'ES' => 'es',
                'IT' => 'it',
                'NL' => 'nl',
                'PL' => 'pl',
                'PT' => 'pt',
                'SK' => 'sk',
                'PA' => 'es',
                'MX' => 'es',
                'CR' => 'es',
                'CL' => 'es',
                'BR' => 'pt',
                'CU' => 'es',
                'MA' => 'fr',
            ];
            return $langMap[$cc] ?? 'en';
        } catch (\Exception $e) {
            return 'en';
        }
    }

    private static function parseGeocodeResponse($data) {
        $addr = $data['address'] ?? [];
        $parts = array_filter([
            $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? '',
            $addr['state'] ?? $addr['province'] ?? '',
            $addr['country_code'] ?? ''
        ]);
        
        $location = !empty($parts) ? implode(', ', $parts) : "Remote Location";
        
        // APPLY REPLACEMENTS HERE
        return self::applyReplacements($location);
    }

    /**
     * Converts ISO dates to friendly formats.
     * iPad 2/Safari can't reliably parse "2024-03-24T14:30:00Z".
     */
    public static function formatDate($isoDate) {
        if (!$isoDate) return "";
        $date = new \DateTime($isoDate);
        return $date->format('F j, Y'); // e.g. March 24, 2024
    }

    /**
     * Calculates age at a specific point in time.
     */
    public static function calculateAge($photoDate, $birthDate) {
        if (!$photoDate || !$birthDate) return "";
        try {
            $photo = new \DateTime($photoDate);
            $birth = new \DateTime($birthDate);
            
            // If birth is after photo, age is invalid
            $interval = $birth->diff($photo);
            if ($interval->invert) return "";

            if ($interval->y >= 1) {
                return $interval->y . "y";
            }
            if ($interval->m >= 1) {
                return $interval->m . "mo";
            }
            
            $days = $interval->days;
            if ($days >= 7) return floor($days / 7) . "w";
            return $days . "d";
        } catch (\Exception $e) {
            return "";
        }
    }

    /**
     * Sanitizes descriptions for display.
     */
    public static function formatDescription($exif) {
        if (empty($exif['description'])) {
            return "";
        }

        $description = $exif['description'];

        // 1. Strip manufacturer junk
        foreach (self::$manufacturerBaggage as $junk) {
            // Use case-insensitive replacement to be safe
            $description = str_ireplace($junk, '', $description);
        }

        // 2. Clean up resulting mess (extra spaces, dashes, or commas left behind)
        $description = preg_replace('/^[\s\-\,]+|[\s\-\,]+$/', '', $description);
        $description = trim($description);

        return htmlspecialchars($description);
    }

    public static function getAspectRatio($width, $height) {
        if (!$width || !$height) return "";
        
        // Calculate Greatest Common Divisor
        $gcd = function($a, $b) use (&$gcd) {
            return ($a % $b) ? $gcd($b, $a % $b) : $b;
        };
        
        $divisor = $gcd($width, $height);
        $w = $width / $divisor;
        $h = $height / $divisor;

        // Normalize common camera ratios
        if (($w == 8 && $h == 5) || ($w == 16 && $h == 10)) return "16:10";
        if (($w == 4 && $h == 3)) return "4:3";
        if (($w == 3 && $h == 2)) return "3:2";
        if (($w == 16 && $h == 9)) return "16:9";
        if (($w == 1 && $h == 1)) return "1:1";

        return "$w:$h";
    }

    public static function formatFileSize($bytes) {
        if (!$bytes) return "";
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }

    public static function getGoogleMapsUrl($lat, $lon) {
        if (!$lat || !$lon) return "";
        return "https://www.google.com/maps/search/?api=1&query=$lat,$lon";
    }
}