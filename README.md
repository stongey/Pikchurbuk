# Pikchurbuk

Pikchurbuk is a high-performance, web-based smart photo frame specifically designed to run on low-memory legacy hardware (such as the iPad 2) while pulling content from an [Immich](https://immich.app/) server.

## Key Features

*   **Multiple Display Modes**: Random Library, "On This Day" Memories, Mixed, and Scheduled Events.
*   **Scheduled Events**: Automatically switch to specific albums during defined date ranges (e.g., Christmas or Summer Holidays) via `schedule.json`.
*   **Legacy Hardware Optimization**:
    *   Server-side image proxying to request JPEG thumbnails instead of large originals to prevent browser crashes.
    *   Aggressive JavaScript memory management and garbage collection.
    *   Automated page reloads to prevent "Safari fatigue" on older iOS versions.
*   **Rich Metadata**:
    *   Automatic reverse geocoding with local caching.
    *   Smart "People" display with age calculation based on the photo date.
    *   Interactive maps using Leaflet.js.
*   **Night Mode**: Automatically dims the screen and stops fetching images during configurable hours.
*   **On-Device Settings**: Adjust modes, backgrounds, and filters directly from the frame UI.

## Installation

1.  Clone this repository to your web server.
2.  Install dependencies via Composer:
    ```bash
    composer install
    ```
3.  Copy the environment template and fill in your Immich details:
    ```bash
    cp .env.example .env
    ```

## Configuration

### Environment Variables (`.env`)

| Variable | Description |
| :--- | :--- |
| `IMMICH_URL` | The base URL of your Immich instance. |
| `IMMICH_API_KEY` | Your Immich API Key. |
| `REFRESH_DELAY` | Seconds between photo transitions (default: 30). |
| `NIGHT_MODE_START` | Hour to start night mode (24h format, default: 22). |
| `NIGHT_MODE_END` | Hour to end night mode (24h format, default: 7). |
| `EXCLUDED_TAG_IDS` | Comma-separated list of Immich tag IDs to ignore. |
| `NOMINATIM_USER_AGENT` | Unique User-Agent for geocoding (e.g., `MyFrame/1.0 (contact@email.com)`). |

### Scheduled Events (`schedule.json`)

Create a `schedule.json` in the root directory to define annual events. If the current date falls within a range, the frame will prioritize the specified album.

```json
[
  {
    "name": "Christmas Season",
    "startDate": "12-15",
    "endDate": "12-31",
    "albumId": "your-album-uuid",
    "color": "#e74c3c"
  }
]
```

## Maintenance

To prevent the geocoding cache from growing indefinitely, set up a daily cron job for the cleanup script:

```bash
0 3 * * * /usr/bin/php /var/www/html/scripts/cache_cleanup.php
```

## Requirements

*   PHP 7.4 or higher
*   PHP GD Extension (for icon generation)
*   Composer
*   An active Immich instance

## Credits

Developed by Stongey. Icons generated via SVG. Maps provided by OpenStreetMap/Leaflet.

## License

This project is licensed under the MIT License - see the LICENSE file for details.