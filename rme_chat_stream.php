<?php
// =========================================================================
// ABSCHNITT 1: PHP-VORBEREITUNG (100% SESSION-FREI GEGEN TIMEOUTS)
// =========================================================================
date_default_timezone_set('Europe/Berlin');

// REPARIERT FÜR KEYHELP: Wir laden hier ABSICHTLICH KEINE maincore.php!
// Dadurch wird keine Session blockiert, und der Stream läuft völlig autark.
$stream_url = "https://radio-musikexpress.de";


set_time_limit(0);
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', 1); }
@ini_set('zlib.output_compression', 0); @ini_set('implicit_flush', 1);
while (ob_get_level()) { ob_end_clean(); }
$is_audio_request = isset($_GET['action']) && $_GET['action'] === 'listen';
$need_metadata = false;
if (isset($_SERVER['HTTP_ICY_METADATA']) && $_SERVER['HTTP_ICY_METADATA'] == 1) { $need_metadata = true; $is_audio_request = true; }
if ($is_audio_request) {
    header("HTTP/1.0 200 OK"); header("Content-Type: audio/mpeg"); header("X-Content-Type-Options: nosniff");
    header("icy-notice1: <BR>This stream requires <a href=\"http://winamp.com\">Winamp</a><BR>");
    header("icy-notice2: SHOUTcast Distributed Network Audio Server/v2.1.6<BR>");
    header("icy-name: Radio Musikexpress"); header("icy-genre: Mix"); header("icy-url: https://radio-musikexpress.de");
    header("icy-pub: 1"); header("icy-br: 128"); header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
    header("Pragma: no-cache"); header("Connection: close");
// Ändere den Port auf 8002 und füge das "ssl://" Protokoll hinzu
// Erstellt einen Kontext, der ungültige/IP-Zertifikate erlaubt
$context = stream_context_create([
    "ssl" => [
        "verify_peer" => false,
        "verify_peer_name" => false,
        "allow_self_signed" => true
    ]
]);

// Verbindung über stream_socket_client statt fsockopen aufbauen
$shoutcast = @stream_socket_client("ssl://148.251.88.105:8002", $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);

if (!$shoutcast) { 
    header("HTTP/1.0 503 Service Unavailable"); 
    exit; 
}

// Der restliche Request-Code bleibt gleich (mit Port 8002)
$request = "GET /; HTTP/1.0\r\nHost: 148.251.88.105:8002\r\nUser-Agent: WinampMPEG/5.0\r\nAccept: */*\r\n";


    if ($need_metadata) { $request .= "Icy-MetaData: 1\r\n"; }
    $request .= "Connection: Close\r\n\r\n"; fwrite($shoutcast, $request); $icy_metaint = 0;
    while (!feof($shoutcast)) {
        $line = fgets($shoutcast, 4096); if (trim($line) == "") { break; }
        if (stripos($line, 'icy-metaint:') === 0) { $icy_metaint = (int)trim(substr($line, 12)); }
    }
    if ($need_metadata && $icy_metaint > 0) { header("icy-metaint: " . $icy_metaint); }
    if ($need_metadata && $icy_metaint > 0) {
        while (!feof($shoutcast) && !connection_aborted()) {
            echo fread($shoutcast, $icy_metaint); flush();
            $len_byte = fread($shoutcast, 1); echo $len_byte; $metadata_len = ord($len_byte) * 16;
            if ($metadata_len > 0) { echo fread($shoutcast, $metadata_len); flush(); }
        }
    } else { while (!feof($shoutcast) && !connection_aborted()) { echo fread($shoutcast, 4096); flush(); } }
    fclose($shoutcast); exit; 
}
$current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$stream_url = $current_url . (strpos($current_url, '?') !== false ? '&' : '?') . 'action=listen';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Radio Musikexpress</title>
    <link rel="manifest" href="manifest.json">
    <meta id="theme-meta-color" name="theme-color" content="#111111">
    
    <!-- 🎨 INTEGRATION: Zusätzliche Styles für den Player-Lightmode -->
    <style>
        /* Wenn der Player-Body hell geschaltet wird */
        body.rme-light-mode {
            background: #ffffff !important;
            color: #222222 !important;
        }
        body.rme-light-mode .player-layout {
            background: #ffffff !important;
        }
        body.rme-light-mode .lbl {
            color: #555555 !important;
			background: #ffffff !important;
        }
        body.rme-light-mode .val {
            color: #111111 !important;
        }
        body.rme-light-mode #mini-songtitle {
            color: #d35400 !important; /* Songtitel im hellen Modus in schickem Radio-Orange */
        }
        body.rme-light-mode #rme_show_image_box {
            background: rgba(0,0,0,0.05) !important;
            border: 1px solid #cccccc !important;
        }
        body.rme-light-mode .custom-controls {
            background: #f5f6f8 !important;
            border-top: 1px solid #dddddd !important;
        }
        body.rme-light-mode .play-btn {
            background: #ff5722 !important;
            fill: #ffffff !important;
        }
        body.rme-light-mode .play-btn svg {
            fill: #ffffff !important;
        }
        body.rme-light-mode .vol-icon {
            fill: #333333 !important;
        }
    </style>
</head>
<body>
<link rel="stylesheet" type="text/css" href="css/rme_chat.css?v=<?php echo file_exists('css/rme_chat.css') ? filemtime('css/rme_chat.css') : '1.0'; ?>">

    <div class="app-wrapper">
        <!-- NEU: Das Gehäuse trennt den fließenden LED-Rand vom Inhalt -->
        <div class="app-inner-content">
            
            <div id="mini-player" style="display: flex; flex-direction: column; width: 100%; height: 100%; justify-content: space-between; gap: 10px;">
                <!-- 1. Der obere Bereich mit Avatar, Texten und schwebendem Bild -->
                <div class="player-layout" style="position: relative; width: 100%; display: flex; flex-direction: row; gap: 10px;">
                    
                    <!-- Avatar des DJs (Bleibt links wie gewohnt) -->
                    <div id="fusion_avatar"></div>
                    
                    <!-- Meta-Box für die Texte (Nutzt jetzt die volle Breite des Gehäuses) -->
                    <div class="meta-box" style="flex: 1; min-width: 0;">
                        
                        <!-- DJ-Zeile: Weicht dem Bild rechts mit 115px Platz elegant aus -->
                        <div class="row" style="padding-right: 105px;">
                            <span class="lbl">DJ:&nbsp;</span>
                            <div class="marquee-container"><span class="val" id="fusion_djname">Lade Moderator...</span></div>
                        </div>

                        <div style="height: 6px;"></div>

                        <!-- Show-Zeile: Weicht dem Bild rechts ebenfalls mit 115px Platz elegant aus -->
                        <div class="row" style="padding-right: 105px;">
                            <span class="lbl">Show:&nbsp;</span>
                            <div class="marquee-container"><span class="val" id="fusion_showname">Lade Show...</span></div>
                        </div>

                        <div style="height: 6px;"></div>

                        <!-- Song-Zeile: FREIBAHN! Läuft unter dem Bild ungehindert bis ganz nach rechts durch -->
                        <div class="row" style="width: 100%; padding-right: 0;">
                            <span class="lbl">Song:&nbsp;</span>
                            <div class="marquee-container" id="song-container" style="width: 100%;"><span class="val" id="mini-songtitle">Lade Song...</span></div>
                        </div>
                        
                    </div>

                    <!-- FREI SCHWEBEND: Das Show-Bild mit Sicherheitsabstand nach oben und rechts -->
                    <div id="rme_show_image_box" style="position: absolute; top: 5px; right: 5px; width: 105px; height: 70px; border-radius: 4px; overflow: hidden; border: 1px solid rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; background: rgba(0,0,0,0.2); z-index: 10;">
                        <img id="rme_show_mini_pic" src="data:image/svg+xml;utf8,<svg xmlns='http://w3.org' width='70' height='45' viewBox='0 0 24 24' fill='rgba(255,255,255,0.2)'><path d='M12 2c-5.52 0-10 4.48-10 10s4.48 10 10 10 10-4.48 10-10-4.48-10-10-10zm1 16h-2v-6h2v6zm0-8h-2v-2h2v2z'/></svg>" style="width: 100%; height: 100%; object-fit: cover;" onerror="this.src='../../infusions/sendeplan/images/shows/GeisterExpres.jpg';">
                    </div>

                </div>

                <!-- 🔥 NEU: DAS COOLE VORSCHAU-BAND IN VOLLER BREITE DARUNTER -->
                <div class="row" style="width: 100%; background: rgba(0, 240, 255, 0.03); border: 1px dashed rgba(0, 240, 255, 0.15); border-left: 3px solid #00f0ff; padding: 6px 10px; border-radius: 3px; box-sizing: border-box; display: flex; align-items: center;">
                    <span class="lbl" style="color: #00f0ff; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold; flex-shrink: 0;">Next:&nbsp;</span>
                    <div class="marquee-container" style="width: 100%; overflow: hidden; white-space: nowrap;">
                        <span id="rme-player-next-show" style="color: #aaaaaa; font-size: 12px; font-weight: bold; display: inline-block;">Prüfe Sendeplan...</span>
                    </div>
                </div>

            </div>
        </div>
        
        <div class="custom-controls">
            <button id="custom-play-btn" class="play-btn" title="Radio starten/stoppen">
                <svg id="play-icon" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                <svg id="pause-icon" viewBox="0 0 24 24" style="display:none;"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
            </button>
            <div class="volume-container">
                <svg class="vol-icon" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/></svg>
                <input type="range" id="custom-volume" min="0" max="1" step="0.05" value="0.05">
            </div>
        </div>
        <audio id="radio-audio" preload="none" src="<?php echo $stream_url; ?>">Ihr Browser unterstützt diesen Player nicht.</audio>
    </div>

            
        </div>
    </div>

<script>
if ('serviceWorker' in navigator) { navigator.serviceWorker.register('chat_sw.js').catch(e => console.log(e)); }

const urlParams = new URLSearchParams(window.location.search);
const isStarterMode = urlParams.get('launcher') === 'true';
const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;

// 1. DER GENIALE GRÖSSEN-ZWINGER: Verkleinert das erste Fenster sofort beim Laden!
// Wenn wir auf dem PC sind und NICHT in der installierten App, schrumpfen wir das Fenster auf 600x240
if (!isStandalone && !/Android|iPhone|iPad/i.test(navigator.userAgent)) {
    // Schaltet die Adresszeile und Menüs unsichtbar, indem wir das aktuelle Fenster neu formatieren
    window.resizeTo(600, 240);
}

// DESIGN-WEICHE: Aktiviert das kantenlose Layout ab der ersten Sekunde für alle PC/App-Modi
document.body.classList.add('is-mini-player');

var deferredPrompt;
var pwaBtn = document.getElementById('pwa-button');

// PWA Installations-Vorbereitung (Wird sauber direkt im Gehäuse angezeigt)
window.addEventListener('beforeinstallprompt', function(e) { 
    e.preventDefault(); 
    deferredPrompt = e; 
    if (pwaBtn && !isStandalone) { pwaBtn.style.display = 'block'; } 
});

if (pwaBtn) {
    pwaBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function(choiceResult) { 
                if (choiceResult.outcome === 'accepted') { 
                    pwaBtn.style.display = 'none'; 
                    // Lädt die Seite nach Erfolg kurz neu, um die App im System zu aktivieren
                    window.location.reload(); 
                } 
                deferredPrompt = null; 
            });
        }
    });
}
window.addEventListener('appinstalled', function() { if (pwaBtn) pwaBtn.style.display = 'none'; deferredPrompt = null; window.name = 'RadioExpressPlayer'; });

var player = document.getElementById('radio-audio');
var playBtn = document.getElementById('custom-play-btn');
var volSlider = document.getElementById('custom-volume');
var playIcon = document.getElementById('play-icon');
var pauseIcon = document.getElementById('pause-icon');

// Setzt die Start-Lautstärke des Streams auf leise 5% und synchronisiert den Regler
player.volume = 0.05;
if (volSlider) { volSlider.value = 0.05; }

// WICHTIG FÜR HANDYS: Verhindert, dass das Handy den Stream im Hintergrund unendlich vorab lädt
player.setAttribute('preload', 'none');

if (/Android|iPhone|iPad/i.test(navigator.userAgent)) {
    if(volSlider) volSlider.parentNode.style.display = 'none';
    if(playBtn) { playBtn.parentNode.style.justifyContent = 'center'; playBtn.style.width = '60px'; playBtn.style.height = '60px'; }
}

function toggleRadio() {
    if (player.paused) {
        // Falls die Quelle leer ist, erzwinge einen frischen Live-Injektion-Reset
        if (!player.src || player.src === "" || player.src === window.location.href) {
            player.src = "<?php echo $stream_url; ?>";
            player.load();
        }
        
        player.play().then(() => { 
            playIcon.style.display = 'none'; 
            pauseIcon.style.display = 'block'; 
        }).catch(e => {
            console.log("Wiedergabe blockiert:", e);
            // Falls es blockiert wurde, leeren wir die Quelle direkt wieder für den nächsten echten Klick
            player.src = "";
        });
    } else {
        player.pause(); 
        // Killt die Verbindung auf dem Handy komplett (spart Datenvolumen & Akku)
        player.src = ""; 
        playIcon.style.display = 'block'; 
        pauseIcon.style.display = 'none';
    }
}

// Der Play-Button schaltet die Musik ab der ersten Sekunde direkt und laut ein!
if (playBtn) {
    // Mobilgeräte reagieren viel besser auf 'touchend' oder direkte Klicks ohne Verzögerung
    playBtn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        toggleRadio();
    });
}

// FIX FÜR HANDYS: Automatischer Start per PostMessage wird auf Mobilgeräten blockiert.
// Wir erlauben das automatische Startsignal NUR noch auf dem Desktop-PC!
window.addEventListener('message', function(event) {
    if (event.data === 'rme_start_radio_now' && player && player.paused) {
        // Wenn es KEIN Handy ist, darf der Autostart durchlaufen
        if (!/Android|iPhone|iPad/i.test(navigator.userAgent)) {
            toggleRadio();
        } else {
            console.log("Autostart auf Mobilgerät ignoriert, um Player-Sperre zu verhindern.");
        }
    }
});

volSlider.addEventListener('input', function() { player.volume = this.value; });

var streamId = "1"; 
if (window.opener && !window.opener.closed) {
    try {
        var mWin = window.opener;
        var listenerElement = mWin.document.querySelector('[id^="current_listeners_"]');
        if (listenerElement && listenerElement.id) { streamId = listenerElement.id.replace('current_listeners_', ''); }
    } catch(e) {}
}

function loadFusionData(apiUrl, targetElementId, isAvatar) {
    fetch(apiUrl).then(r => r.text()).then(htmlCode => {
        var box = document.getElementById(targetElementId);
        if (box) {
            box.innerHTML = htmlCode;
            if (isAvatar) {
                var img = box.querySelector('img');
                if (!img) { box.innerHTML = '<img src="https://radio-musikexpress.de" alt="DJ">'; }
                else {
                    var src = img.getAttribute('src');
                    if (src && !src.startsWith('http') && !src.startsWith('//')) { if (src.startsWith('/')) src = src.substring(1); img.src = window.location.origin + '/' + src; }
                }
            }
        }
    }).catch(e => console.log(e));
}

function checkMarqueeEffect() {
    var textNode = document.getElementById('mini-songtitle');
    if (textNode) {
        if (!textNode.classList.contains('animate-marquee')) {
            var originalText = textNode.innerHTML;
            textNode.innerHTML = '<span class="marquee-text-block">' + originalText + '</span><span class="marquee-text-block">' + originalText + '</span>';
            textNode.classList.add('animate-marquee');
        }
    }
}

function runPlayerUpdates() {
    var pathAvatar = '../../infusions/rme_streamstatus_panel/ajax/systemweb/current_dj_avatar.php?id=' + streamId;
    var pathDjname = '../../infusions/rme_streamstatus_panel/ajax/systemweb/current_dj_profilelink.php?id=' + streamId;
    
    loadFusionData(pathAvatar, 'fusion_avatar', true);
    loadFusionData(pathDjname, 'fusion_djname', false);
    
    // UNFEHLBAR: Holt Show-Text und Bild auf exakt demselben Rechenweg wie dein großer Sendeplan!
    // UNFEHLBAR: Holt Show-Text, Bild UND die nächste Sendung im selben Abwasch!
    fetch('chat_get_show.php')
    .then(r => r.json())
    .then(data => {
        var showField = document.getElementById('fusion_showname');
        var imgField = document.getElementById('rme_show_mini_pic');
        var nextField = document.getElementById('rme-player-next-show'); // 🔥 NEU!
        
        if (showField) showField.innerHTML = data.title;
        if (imgField) imgField.src = '../../infusions/sendeplan/images/shows/' + data.image;
        if (nextField && data.next_show) nextField.innerHTML = data.next_show; // 🔥 NEU!
    })
    .catch(() => {
        var showField = document.getElementById('fusion_showname');
        var nextField = document.getElementById('rme-player-next-show');
        if (showField) showField.innerHTML = 'Express Mix';
        if (nextField) nextField.innerHTML = 'Für den restlichen Tag übernimmt der <span style="color: #ff9900;">AutoDJ 🤖</span>';
    });


    fetch('chat_get_meta.php').then(r => r.json()).then(data => {
        var textNode = document.getElementById('mini-songtitle');
        if (textNode && textNode.getAttribute('data-raw') !== data.song) {
            textNode.classList.remove('animate-marquee');
            textNode.setAttribute('data-raw', data.song);
            textNode.innerHTML = data.song || 'Live Stream';
            setTimeout(checkMarqueeEffect, 200);
            if (player && !player.paused && player.buffered && player.buffered.length > 0) {
                var pufferEnde = player.buffered.end(player.buffered.length - 1);
                var aktuelleZeit = player.currentTime;
                var verzoegerung = pufferEnde - aktuelleZeit;
                if (verzoegerung > 5.0) { 
                    player.currentTime = pufferEnde - 1.0; 
                }
            }
        }
    }).catch(() => { document.getElementById('mini-songtitle').innerHTML = 'Live Stream'; });
}
runPlayerUpdates();
setInterval(runPlayerUpdates, 4000);
// 🎨 DYNAMISCHE LIGHT-MODE SYNCHRONISATION MIT DEM RECHTEN PANEL
function checkParentDesignMode() {
    try {
        if (window.parent && window.parent.document && window.parent.document.body) {
            var chatBody = window.parent.document.body;
            var metaColor = document.getElementById('theme-meta-color');
            
            // Wenn der große Chat hell ist, ziehen wir hier sofort nach!
            if (chatBody.classList.contains('rme-light-mode')) {
                if (!document.body.classList.contains('rme-light-mode')) {
                    document.body.classList.add('rme-light-mode');
                    if (metaColor) metaColor.setAttribute('content', '#ffffff');
                }
            } else {
                // Wenn der Chat dunkel ist, schalten wir wieder auf Dark Mode
                if (document.body.classList.contains('rme-light-mode')) {
                    document.body.classList.remove('rme-light-mode');
                    if (metaColor) metaColor.setAttribute('content', '#111111');
                }
            }
        }
    } catch(e) {
        // Falls Cross-Origin blockiert, fangen wir das hier lautlos ab
    }
}
// Schaut jede Sekunde nach, ob Du am Zahnrad gedreht hast!
setInterval(checkParentDesignMode, 1000);
checkParentDesignMode();

</script>




</body>
</html>
