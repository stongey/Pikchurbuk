/**
 * Core frontend logic for the photo frame.
 * Manages the fetch loop, night mode transitions, and UI state.
 * 
 * Includes specific memory-clearing hacks for older iOS versions to ensure 
 * the frame can run for weeks without a manual restart.
 */
var currentExif = {}; // Global to store details of current photo
var refreshTimer = null;
var map = null;
var marker = null;
var currentMapsUrl = null;

function fetchNext() {
    var xhr = new XMLHttpRequest();
    var url = "api.php" + window.location.search; 
    
    xhr.open("GET", url, true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            var data = JSON.parse(xhr.responseText);
            updateDisplay(data);
            scheduleNext(data.delay);
        }
    };
    xhr.send();
}

function scheduleNext(delaySeconds) {
    if (refreshTimer) clearTimeout(refreshTimer);
    var ms = (delaySeconds || 30) * 1000;
    refreshTimer = setTimeout(function() {
        fetchNext();
    }, ms);
}

/**
 * Main UI update engine.
 * Clears memory from previous images, handles night mode visibility,
 * updates metadata text, and manages the Leaflet map state.
 * 
 * @param {Object} data - The JSON response from api.php
 */
function updateDisplay(data) {
    currentExif = data.exifDetails;
    document.getElementById('exifOverlay').style.display = 'none';

    var img = document.getElementById('mainImg');
    var bgDiv = document.getElementById('bgBlur');
    var night = document.getElementById('nightOverlay');
    var metaBox = document.getElementById('metaBox');
    var locElement = document.getElementById('uiLocation');

    // Clear memory from previous image for iPad 2 stability
    if (bgDiv) bgDiv.style.backgroundImage = 'none';
    img.src = "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7";

    night.style.display = 'none';
    night.style.opacity = 0;

    if (data.isNight) {
        night.style.display = '-webkit-flex';
        night.style.display = 'flex';
        metaBox.style.opacity = 0;
        setTimeout(function() {
            night.style.opacity = 1;
        }, 50);

        var msgId = 'nightMsg';
        var msgEl = document.getElementById(msgId);
        if (!msgEl) {
            msgEl = document.createElement('div');
            msgEl.id = msgId;
            msgEl.style.color = '#222';
            msgEl.style.fontSize = '0.8em';
            night.appendChild(msgEl);
        }
        msgEl.innerHTML = "Night mode active<br><span style='opacity:0.5; font-size:0.7em;'>ZZZzzz...</span>";

        return; 
    } else {
        night.style.display = 'none';
    }

    metaBox.style.opacity = 0;

    var imageURL = "image.php?id=" + data.id + "&t=" + new Date().getTime();
    img.src = imageURL;

    img.onload = function() {
        document.getElementById('metaBox').style.opacity = 1;
    };
    
    img.onerror = function() {
        console.log("Image failed. Retrying in 5s...");
        setTimeout(fetchNext, 5000);
    };

    if (config.bg === 'blur') {
        bgDiv.style.backgroundImage = "url('" + imageURL + "')";
    }

    if (data.location && data.location.trim() !== "") {
        locElement.innerHTML = data.location;
        locElement.style.display = 'block';
    } else {
        locElement.innerHTML = "";
        locElement.style.display = 'none';
    }

    var descElement = document.getElementById('uiDescription');
    if (data.description && data.description.trim() !== "") {
        descElement.innerHTML = data.description;
        descElement.style.display = 'block';
    } else {
        descElement.style.display = 'none';
    }

    if (data.isScheduled) {
        metaBox.className = "meta-container is-scheduled";
        metaBox.style.borderLeftColor = data.scheduleColor || '';
    } else {
        metaBox.className = "meta-container";
        metaBox.style.borderLeftColor = '';
    }

    document.getElementById('uiDate').innerHTML = data.date;
    currentMapsUrl = data.mapsUrl;

    if (data.map) {
        showMap(data.map.lat, data.map.lon);
    } else {
        document.getElementById('mapContainer').style.display = 'none';
    }
}

function showMap(lat, lon) {
    var container = document.getElementById('mapContainer');
    container.style.display = 'block';

    if (map === null) {
        map = L.map('mapContainer', { zoomControl: false }).setView([lat, lon], 12);
        L.DomEvent.disableClickPropagation(container);
        //L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            subdomains: 'abc',
            maxZoom: 20
        }).addTo(map);
        marker = L.marker([lat, lon]).addTo(map);
    } else {
        map.setView([lat, lon], 13);
        marker.setLatLng([lat, lon]);
    }
}

function toggleExif(e) {
    if (e && e.stopPropagation) e.stopPropagation();
    var overlay = document.getElementById('exifOverlay');
    var content = document.getElementById('exifContent');
    var html = '<table style="width:100%; border-spacing: 0 10px;">';

    for (var key in currentExif) {
        var val = currentExif[key];
        if (key === 'Location' && currentMapsUrl) {
            val = '<a href="' + currentMapsUrl + '" target="_blank" style="color:#3498db; text-decoration:none;">' + val + ' <svg viewBox="0 0 24 24" fill="currentColor" style="width: 1.1em; height: 1.1em; vertical-align: middle; margin-left: 4px;"><path d="M19 19H5V5h7V3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2v-7h-2v7zM14 3v2h3.59l-9.83 9.83 1.41 1.41L19 6.41V10h2V3h-7z"/></svg></a>';
        }
        html += '<tr><td style="color:#888; font-size:12px; width:30%;">' + key + '</td>' +
                '<td style="font-size:16px;">' + val + '</td></tr>';
    }
    html += '</table>';
    
    content.innerHTML = html;
    overlay.style.display = 'block';

    if (map) {
        setTimeout(function() {
            map.invalidateSize();
        }, 250);
    }
}

function toggleActionOverlay(e) {
    var overlay = document.getElementById('actionOverlay');
    var isVisible = overlay.style.display === 'flex' || overlay.style.display === '-webkit-flex';
    overlay.style.display = isVisible ? 'none' : 'flex';
    if (!isVisible && overlay.style.display !== 'flex') overlay.style.display = '-webkit-flex';
}

function toggleSettings(e) {
    if (e && e.stopPropagation) e.stopPropagation();
    var overlay = document.getElementById('settingsOverlay');
    overlay.style.display = overlay.style.display === 'block' ? 'none' : 'block';
    document.getElementById('actionOverlay').style.display = 'none';
}

function forceRefresh(e) {
    if (e && e.stopPropagation) e.stopPropagation();
    document.getElementById('mainImg').src = "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7";
    var bgBlur = document.getElementById('bgBlur');
    if (bgBlur) bgBlur.style.backgroundImage = "none";
    setTimeout(function() { window.location.reload(true); }, 100);
}

function updateSettings() {
    var url = window.location.pathname + "?mode=" + document.getElementById('setMode').value + "&bg=" + document.getElementById('setBg').value;
    var albumId = document.getElementById('setAlbum').value;
    var personId = document.getElementById('setPerson').value;
    if (albumId) url += "&albumId=" + albumId;
    if (personId) url += "&personId=" + personId;
    window.location.href = url;
}

fetchNext();
setTimeout(function() { window.location.reload(true); }, 7200000);