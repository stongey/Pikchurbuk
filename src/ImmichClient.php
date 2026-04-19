<?php
namespace Stongey\Pikchurbuk;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Specialized wrapper for the Immich API.
 * Focuses on random asset retrieval, memory searches, and excluded tag filtering.
 */
class ImmichClient {
    private $client;
    private $excludedTagIDs = [];

    /**
     * @param string $baseUrl
     * @param string $apiKey
     * @param string|array $excludedTags Comma-separated string or array of IDs
     */
    public function __construct($baseUrl, $apiKey, $excludedTags = "") {
        if (is_string($excludedTags)) {
            $this->excludedTagIDs = array_filter(array_map('trim', explode(',', $excludedTags)));
        } else {
            $this->excludedTagIDs = is_array($excludedTags) ? $excludedTags : [];
        }

        $this->client = new Client([
            'base_uri' => rtrim($baseUrl, '/') . '/',
            'headers'  => [
                'x-api-key' => $apiKey,
                'Accept'    => 'application/json',
            ],
            'timeout'  => 15.0,
        ]);
    }

    public function getRandomAsset($albumId = null, $personId = null) {
        $payload = ["size" => 20, "type" => "IMAGE", 'withExif' => true];
        if ($albumId) $payload["albumIds"] = [$albumId];
        if ($personId) $payload["personIds"] = [$personId];

        try {
            //error_log("Pikchurbuk: Random asset search payload: " . json_encode($payload));
            $response = $this->client->post('api/search/random', ['json' => $payload]);
            $data = json_decode($response->getBody(), true);
            //error_log("Pikchurbuk: Random search returned " . (is_array($data) ? count($data) : 0) . " items");
            return $this->filterAndPick($data);
        } catch (GuzzleException $e) {
            //error_log("Pikchurbuk Error: " . $e->getMessage());
            return null;
        }
    }

    public function getAssetById($id) {
        $response = $this->client->get("api/assets/{$id}");
        return json_decode($response->getBody(), true);
    }

    public function getAlbums() {
        try {
            $response = $this->client->get('api/albums?take=500');
            $albums = json_decode($response->getBody(), true);
            usort($albums, function($a, $b) {
                return strcasecmp($a['albumName'] ?? '', $b['albumName'] ?? '');
            });
            return $albums;
        } catch (GuzzleException $e) {
            return [];
        }
    }

    public function getPeople() {
        try {
            $response = $this->client->get('api/people?withHidden=false&take=500');
            $data = json_decode($response->getBody(), true);
            $people = $data['people'] ?? $data;
            usort($people, function($a, $b) {
                return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
            });
            return $people;
        } catch (GuzzleException $e) {
            return [];
        }
    }

    /**
     * Searches for a "Memory" asset taken on this day in history.
     * Iterates backwards through up to 40 years of history.
     *
     * @param string|null $albumId
     * @param string|null $personId
     * @return array|null
     */
    public function getMemoryAsset($albumId = null, $personId = null) {
        $month = date('m');
        $day   = date('d');
        $currentYear = intval(date('Y'));

        for ($i = 0; $i < 40; $i++) {
            $year = $currentYear - $i;
            $payload = [
                "size" => 10,
                "type" => "IMAGE",
                'withExif' => true,
                "takenAfter" => "{$year}-{$month}-{$day}T00:00:00.000Z",
                "takenBefore" => "{$year}-{$month}-{$day}T23:59:59.999Z"
            ];
            if ($albumId) $payload["albumIds"] = [$albumId];
            if ($personId) $payload["personIds"] = [$personId];

            try {
                $response = $this->client->post('api/search/random', ['json' => $payload]);
                $data = json_decode($response->getBody(), true);
                $match = $this->filterAndPick($data);
                if ($match) return $match;
            } catch (GuzzleException $e) { 
                continue; 
            }
        }
        return null;
    }

    /**
     * Validates assets against the EXCLUDED_TAG_IDS from .env
     */
    private function filterAndPick($assets) {
        if (!is_array($assets) || empty($assets)) return null;

        $valid = array_filter($assets, [$this, 'isAssetValid']);

        return !empty($valid) ? $valid[array_rand($valid)] : null;
    }

    /**
     * Checks if a single asset contains any excluded tags.
     * Supports both 'tags' (array of objects) and 'tagIds' (array of strings).
     *
     * @param array $asset The asset to validate
     * @return bool True if the asset is valid (no excluded tags), false otherwise
     */
    public function isAssetValid($asset) {
        if (empty($this->excludedTagIDs)) return true;

        // Check 'tags' array (objects with 'id' property)
        if (!empty($asset['tags']) && is_array($asset['tags'])) {
            foreach ($asset['tags'] as $tag) {
                if (isset($tag['id']) && in_array($tag['id'], $this->excludedTagIDs)) {
                    return false;
                }
            }
        }

        // Check 'tagIds' array (simple UUID strings)
        if (!empty($asset['tagIds']) && is_array($asset['tagIds'])) {
            foreach ($asset['tagIds'] as $tagId) {
                if (in_array($tagId, $this->excludedTagIDs)) {
                    return false;
                }
            }
        }

        return true;
    }
}