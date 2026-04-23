<?php
require_once __DIR__ . '/../vendor/autoload.php';
use Stongey\Pikchurbuk\ImmichClient;
use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->load();

$immich = new ImmichClient(
    $_ENV['IMMICH_URL'], 
    $_ENV['IMMICH_API_KEY'],
    $_ENV['EXCLUDED_TAG_IDS'] ?? ""
);
$albums = $immich->getAlbums();
$people = $immich->getPeople();

// We still need a tiny bit of PHP to handle initial URL params for the JS to read
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'random';
$albumId = isset($_GET['albumId']) ? $_GET['albumId'] : '';
$personId = isset($_GET['personId']) ? $_GET['personId'] : '';
$delay = isset($_GET['delay']) ? intval($_GET['delay']) : (int)($_ENV['REFRESH_DELAY'] ?? 30);
$schedule = ($_GET['schedule'] ?? 'true') !== 'false' ? 'true' : 'false';
$bg = isset($_GET['bg']) ? $_GET['bg'] : '000000';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Pikchurbuk</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Pikchurbuk">
    <link rel="apple-touch-icon" sizes="180x180" href="apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="96x96" href="favicon-96x96.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="mask-icon" href="safari-pinned-tab.svg" color="#000000">
    <link rel="shortcut icon" href="favicon.ico">
    <meta name="msapplication-TileColor" content="#000000">
    <meta name="theme-color" content="#000000">
    <link rel="stylesheet" href="style.css?v=1.0">
    <style>
        body { background-color: <?php echo ($bg === 'blur' ? '#000' : '#' . $bg); ?>; }
        .bg-blur { display: <?php echo ($bg === 'blur' ? 'block' : 'none'); ?>; }

        /* Toggle Switch Styling */
        .switch {
            position: relative;
            display: inline-block;
            width: 46px;
            height: 24px;
        }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #444;
            transition: .4s;
            border-radius: 24px;
        }
        .slider:before {
            position: absolute;
            content: "";
            height: 18px; width: 18px;
            left: 3px; bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }
        input:checked + .slider { background-color: #3498db; }
        input:checked + .slider:before { transform: translateX(22px); }
    </style>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.7.1/dist/leaflet.css" /> <!-- version supported by old devices -->
    <script src="https://unpkg.com/leaflet@1.7.1/dist/leaflet.js"></script>
</head>
<body onclick="toggleActionOverlay(event)">
    <div id="nightOverlay">
        <div class="moon-icon"></div>
    </div>
    <div class="bg-blur" id="bgBlur"></div>
    <img class="main" id="mainImg" src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7">
    <div id="exifOverlay" onclick="this.style.display='none'; if(event && event.stopPropagation) event.stopPropagation();">
        <h1 style="font-weight: 200;">Photo Details</h1>
        <div id="exifContent" class="exif-grid"></div>
        <div id="mapContainer"></div>
        <p style="text-align:center; margin-top:50px; opacity:0.5;">Tap anywhere to close</p>
    </div>
    <div id="actionOverlay" style="display: none;">
        <div class="action-btn" id="btnRefresh" onclick="forceRefresh(event)">
            <svg viewBox="0 0 24 24" fill="currentColor" style="width: 1em; height: 1em;">
                <path d="M17.65 6.35C16.2 4.9 14.21 4 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08c-.82 2.33-3.04 4-5.65 4-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/>
            </svg>
        </div>
        <div class="action-btn" id="btnSettings" onclick="toggleSettings(event)">
            <svg viewBox="0 0 24 24" fill="currentColor" style="width: 1em; height: 1em;">
                <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.21.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>
            </svg>
        </div>
    </div>
    <div id="settingsOverlay">
        <div class="settings-content">
            <h1 style="font-weight: 200; margin-bottom: 30px;">Settings</h1>
            <div class="settings-row">
                <label>Slideshow Mode</label>
                <select id="setMode">
                    <option value="random" <?php echo $mode=='random'?'selected':''; ?>>Randomise Library</option>
                    <option value="memory" <?php echo $mode=='memory'?'selected':''; ?>>On This Day (Memories)</option>
                    <option value="mixed" <?php echo $mode=='mixed'?'selected':''; ?>>Mixed (Random + Memories)</option>
                </select>
            </div>
            <div class="settings-row">
                <label>Auto-Schedule</label>
                <label class="switch">
                    <input type="checkbox" id="setSchedule" <?php echo $schedule=='true'?'checked':''; ?>>
                    <span class="slider"></span>
                </label>
            </div>
            <div class="settings-row">
                <label>Background</label>
                <select id="setBg">
                    <option value="000000" <?php echo $bg!='blur'?'selected':''; ?>>Solid Black</option>
                    <option value="blur" <?php echo $bg=='blur'?'selected':''; ?>>Blurred Image</option>
                </select>
            </div>
            <div class="settings-row">
                <label>Album</label>
                <select id="setAlbum">
                    <option value="">Full Library</option>
                    <?php foreach ($albums as $album): ?>
                        <?php $aId = $album['id'] ?? ''; $aName = $album['albumName'] ?? 'Unnamed Album'; ?>
                        <option value="<?php echo $aId; ?>" <?php echo $albumId == $aId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($aName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="settings-row">
                <label>Person</label>
                <select id="setPerson">
                    <option value="">All People</option>
                    <?php foreach ($people as $person): ?>
                        <?php $pId = $person['id'] ?? ''; $pName = $person['name'] ?? 'Unnamed Person'; ?>
                        <option value="<?php echo $pId; ?>" <?php echo $personId == $pId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($pName); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="btn-close" onclick="updateSettings()">Apply & Reload</div>
            <div class="btn-close" style="background:transparent;" onclick="toggleSettings(event)">Cancel</div>
        </div>
    </div>
    <div class="meta-container" id="metaBox" onclick="toggleExif(event)">
        <div class="meta-date" id="uiDate"></div>
        <div class="meta-location" id="uiLocation"></div>
        <div class="meta-description" id="uiDescription"></div>
    </div>

    <script>
        // Config from PHP
        var config = {
            mode: "<?php echo $mode; ?>",
            albumId: "<?php echo $albumId; ?>",
            personId: "<?php echo $personId; ?>",
            schedule: "<?php echo $schedule; ?>",
            delay: <?php echo $delay * 1000; ?>,
            bg: "<?php echo $bg; ?>"
        };
    </script>
    <script src="script.js?v=1.0"></script>
</body>
</html>