<?php
@set_time_limit(0); // Verhindert, dass der Server das Backend-Skript mitten im Poll abwürgt!

// ??? SYSTEM-START MIT EINMALIGEM SESSION-START GANZ OBEN (WICHTIG GEGEN LAGS!)
if (session_status() == PHP_SESSION_NONE) { 
    session_name("RME_RADIO_CHAT_SESSION");
    session_start(); 
}

// ??? HOCHLEISTUNGS-ENTFESSELUNG: Wir kopieren die Session-Daten in lokale Variablen
$rme_mein_username = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : (isset($_SESSION['rme_guest_name']) ? $_SESSION['rme_guest_name'] : 'Gast');
$rme_mein_status   = isset($_SESSION['user_status']) ? $_SESSION['user_status'] : 0;
$rme_admin_auth    = isset($_GET['admin_auth_name']) ? trim(strip_tags((string)$_GET['admin_auth_name'])) : '';

// ?? DER MAGISCHE BEFEHL: Gibt die Session-Datei SOFORT wieder frei!
// Ab JETZT blockieren sich Deine 1-Sekunden- und 2-Sekunden-Intervalle im Browser niemals wieder untereinander!
session_write_close();


// ?? SERVERSCHUTZ V24: SPRENGT DEN PHP-AUSGABEPUFFER FÜR HANDYS!
if (function_exists('ob_get_level') && ob_get_level() > 0) {
    while (ob_get_level() > 0) { ob_end_clean(); }
}
// Zwingt den Webserver (Apache/Nginx), Daten IMMER SOFORT ans Handy zu senden!
header('X-Accel-Buffering: no'); 
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Connection: close'); // Beendet den PHP-Prozess sauber nach jeder Antwort!


// =========================================================================
// GLOBALER LOG-UMLEIT-MANAGER: SCHREIBT FEHLER ALS TEXTDATEI IN DEN LOGS-ORDNER
// =========================================================================
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (strpos($errstr, 'Trying to access array offset on false') !== false || $errno == E_NOTICE || $errno == E_WARNING) {
        $logOrdner = __DIR__ . "/logs/";
        $logDatei = $logOrdner . "chat_fehler_log.txt";
        if (is_dir($logOrdner)) {
            $zeitstempel = date("d.m.Y H:i:s");
            $logEintrag = "[".$zeitstempel."] Fehler (".$errno."): ".$errstr." in ".$errfile." auf Zeile ".$errline.PHP_EOL;
            @file_put_contents($logDatei, $logEintrag, FILE_APPEND);
        }
        return true; 
    }
    return false;
});

@ini_set('display_errors', 0);
@error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

require_once "../../maincore.php";

if (file_exists(dirname(__FILE__) . "/rme_smilies_config.php")) {
    require_once dirname(__FILE__) . "/rme_smilies_config.php";
}

header("Content-Type: text/html; charset=UTF-8");
header("Cache-Control: no-cache, must-revalidate");

if (isset($_GET['action']) && $_GET['action'] === 'execute_reconnect') {
    header("Location: https://radio-musikexpress.de/infusions/rme_radio_chat_panel/rme_chat.php");
    exit;
}

// HINWEIS füR DEN WEITEREN CODE:
// Du kannst im restlichen Skript Überall, wo $_SESSION['user_name'] abgefragt wird, 
// einfach die unblockierbare Variable $rme_mein_username nutzen!


// !!! HIER IST DIE RETTUNG !!!
// Wir müssen uns die Session-ID holen, BEVOR die Session geschlossen wird!
$meine_aktuelle_session = session_id();

// DIE HANDY-RETTUNG: Holt die wichtigen Daten aus der Session und schließt 
// die Datei SOFORT wieder, damit parallele Handy-AJAX-Anfragen nicht blockieren!
$session_username_start = $_SESSION['rme_chat_guest_name'] ?? ($_SESSION['chat_user_name'] ?? '');
$session_userid_start   = isset($_SESSION['chat_user_id']) ? intval($_SESSION['chat_user_id']) : 0;

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close(); // Gibt die Sperre für mobile Browser sofort frei!
}
// =========================================================================

// Globale Tabellennamen fest deklarieren
$chat_table      = DB_PREFIX."chat_messages";
$users_table     = DB_PREFIX."users";
$bans_table      = DB_PREFIX."chat_bans";
$online_table    = DB_PREFIX."chat_online";
$blacklist_table = DB_PREFIX."chat_blacklist";
$chatuser_table  = DB_PREFIX."chat_guest_accounts";

$get_auth_name = isset($_GET['admin_auth_name']) ? trim((string)$_GET['admin_auth_name']) : '';
$get_auth_id   = isset($_GET['admin_auth_id']) ? intval($_GET['admin_auth_id']) : 0;

// DIE RETTUNG: Zwingt die PHP-Verbindung dazu, echte 4-Byte Emojis durchzulassen!
dbquery("SET NAMES 'utf8mb4'");

// 🔥 MASTER-FIX GEGEN MYSQL-TABELLEN-SPERRUNG:
// Dieser Befehl erlaubt es dem Server, Daten zu lesen, selbst wenn eine andere 
// Abfrage (wie dein privates Fenster) die Tabelle gerade blockiert oder beschreibt!

dbquery("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");


global $userdata;
$user_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$is_admin = false;

// Authentifizierten Namen aus dem AJAX-Request holen
$safe_user_check = isset($_GET['admin_auth_name']) ? trim(strip_tags((string)$_GET['admin_auth_name'])) : '';
$sauberer_backend_name = str_replace(array('[ADMIN]', '[MODERATOR]', '[MOD]', '[HADMIN]'), '', $safe_user_check);
$sauberer_backend_name = trim($sauberer_backend_name);

// CMS-Rechte sicher auslesen
$is_logged_in = (defined('iMEMBER') && iMEMBER);

// =========================================================================
// REPARATUR-KERN: KUGELSICHERE GÄSTE-SCHRANKE (SESSION SCHLÄGT AJAX-URL)
// =========================================================================
$session_id_aktiv = $session_userid_start;
$session_name_aktiv = trim((string)$session_username_start);

// EISERNER MASTER-SCHLÜSSEL FÜR DICH ALS CHEF (VERHINDERT JEDE BLOCKADE FÜR DEINE IP/ID):
$ich_bin_der_chef_absolut = ($_SERVER['REMOTE_ADDR'] === '91.10.82.244' || $get_auth_id === 18 || strtolower($get_auth_name) === 'dj-tomjac' || strtolower($session_name_aktiv) === 'dj-tomjac');

if ($ich_bin_der_chef_absolut) {
    if (session_status() == PHP_SESSION_NONE) { 
        session_start(); 
    }
    unset($_SESSION['rme_kick_time']); // Löscht deine Sperre auf dem Server sofort
    unset($_COOKIE['rme_saved_kick_time']); // Löscht die Keks-Sperre im RAM
    unset($_COOKIE['rme_saved_guest_name_kick']);
    
    // 🔥 REPARIERT: Gibt die Session-Datei SOFORT wieder für das zweite Fenster frei!
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

// MULTI-SCHILD-ABFRAGE FÜR NORMALE HÖRER
$kick_sperre_aktiv_teil1 = (isset($_COOKIE['rme_saved_kick_time']) || isset($_COOKIE['rme_saved_guest_name_kick']) || isset($_SESSION['rme_kick_time']));
$ist_echter_reconnect_aufruf = (isset($_GET['reconnect']) && $_GET['reconnect'] === 'true');

if ($kick_sperre_aktiv_teil1 && !$ist_echter_reconnect_aufruf && !$ich_bin_der_chef_absolut) {
    // Normale Hörer werden hier sofort abgewiesen und flackern nicht mehr!
    if (!empty($meine_aktuelle_session)) {
        dbquery("DELETE FROM " . $online_table . " WHERE session_id='" . addslashes($meine_aktuelle_session) . "'");
    }
    header("Content-Type: text/plain; charset=UTF-8");
    echo "[DU_WURDEST_AUTO_GEKICKT_WEGEN_INAKTIVITAET]";
    exit;
}

// Reguläre CMS-Erkennung für aktive Fenster
if ($session_id_aktiv >= 2000 && strpos(strtolower($session_name_aktiv), 'gast') !== false) {
    $current_chat_user = $session_name_aktiv;
    $current_user_id = $session_id_aktiv;
} elseif ($is_logged_in && isset($userdata) && is_array($userdata)) {
    $current_chat_user = isset($userdata['user_name']) ? (string)$userdata['user_name'] : 'User';
    $current_user_id = isset($userdata['user_id']) ? intval($userdata['user_id']) : 0;
} elseif ($get_auth_name !== '' && strpos(strtolower($get_auth_name), 'gast') === false) {
    $current_chat_user = trim(strip_tags($get_auth_name));
    $current_user_id = $get_auth_id; 
} else {
    if (!empty($session_name_aktiv)) {
        $current_chat_user = $session_name_aktiv;
        $current_user_id = $session_id_aktiv;
    } else {
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UnknownDevice';
        $geraete_fingerabdruck = substr(md5($user_ip . $user_agent), 0, 4);
        $random_id = (hexdec($geraete_fingerabdruck) % 699) + 2000; 
        $current_chat_user = "Gast_" . $random_id;
        $current_user_id = $random_id;
    }
}

// Namen gründlich entschärfen für SQL-Sicherheit
$safe_user_check = function_exists('stripinput') ? stripinput($current_chat_user) : trim(strip_tags($current_chat_user));
$safe_chat_user_name_db = $safe_user_check;

$final_name_clean = str_replace(array('_Gast', '_CU', '[ADMIN]', '[MODERATOR]', '[MOD]', '[HADMIN]'), '', $safe_user_check);
$final_name_clean = trim($final_name_clean);

$ajax_auth_name_clean = trim(str_replace(array('[ADMIN]', '[MODERATOR]', '[MOD]', '[HADMIN]'), '', $get_auth_name));

// 2. RECHTEPRIORISIERUNG (SCHÜTZT VOR JEDEM PHP-BRUCH)
$u_level_data = (isset($userdata) && is_array($userdata) && isset($userdata['user_level'])) ? intval($userdata['user_level']) : 0;

if (defined('iADMIN') && iADMIN) {
    $is_admin = true;
} elseif ($u_level_data === 103) {
    $is_admin = true;
} elseif ($final_name_clean === 'DJ-Tomjac' || $ajax_auth_name_clean === 'DJ-Tomjac' || $current_user_id === 18) {
    // DER ENTSCHEIDENDE CHEF-JOKER: Schaltet deine Admin-Rechte unfehlbar frei! (Auf deine echte ID 18 korrigiert!)
    $is_admin = true;
}

$ist_leitung_safe = $is_admin;
// =========================================================================

// REPARATUR-KERN: Alle sitzungsrelevanten Daten ($current_chat_user, $current_user_id) 
// wurden erfolgreich ermittelt. Wir schließen die Session JETZT, um den 504 Timeout zu verhindern!
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// =========================================================================
// 🔥 F5-STAUBSAUGER: REINIGT DEINE SPALTE SOFORT BEIM NEULADEN DER SEITE
// =========================================================================
// Wenn Du (ID 18) oder Dein Admin-Account den Chat neu lädt, fegen wir
// alte Soundboard-Befehle augenblicklich aus dem user_agent Feld heraus!
if (isset($current_user_id) && intval($current_user_id) === 18) {
    dbquery("UPDATE " . $online_table . " 
             SET user_agent='Mozilla/5.0 (Windows NT 10.0; Win64; x64)' 
             WHERE user_id=18 AND user_agent LIKE 'SOUND:%'");
}
// =========================================================================

// =========================================================================
// BANNER-SCHLEUSEN (KICK & BANN VORAB-ABFANGEN - TIMEOUT-SICHER)
// =========================================================================
if (!$ist_leitung_safe) {
    if (!empty($safe_user_check) || !empty($user_ip)) {
        $clean_global_search = str_replace("_Gast", "", $safe_user_check);
        $check_global_ban = dbquery("SELECT count(*) as total FROM ".$blacklist_table." WHERE ip_address='".addslashes($user_ip)."' OR username='".addslashes($clean_global_search)."' LIMIT 1");
        
        if ($check_global_ban && dbarray($check_global_ban)['total'] > 0) {
            if (isset($_SESSION['rme_chat_guest_name'])) { unset($_SESSION['rme_chat_guest_name']); }
            header("Content-Type: text/plain; charset=UTF-8");
            echo "[DU_BIST_GEBANNT]";
            exit;
        }
    }
    if (!empty($user_ip)) {
        $check_temp_kick = dbquery("SELECT count(*) as total FROM ".$bans_table." WHERE ip_address='".addslashes($user_ip)."' LIMIT 1");
        if ($check_temp_kick && dbarray($check_temp_kick)['total'] > 0) {
            header("Content-Type: text/plain; charset=UTF-8");
            echo "[DU WURDEST AUS DEM CHAT GEKICKT]";
            exit;
        }
    }
}

// =========================================================================
// REPARIERT: GLOBALER CHAT-HINTERGRUND (WEICHE ZUM BLOB-BACKGROUND-HANDLER)
// =========================================================================
if (isset($_POST['action'])) {
    
    // HINTERGRUND HOCHLADEN (Weiche für Rückwärtskompatibilität im Frontend)
    if ($_POST['action'] === 'upload_global_bg') {
        header('Content-Type: application/json');
        // Wenn das Frontend fälschlicherweise hier landet, leiten wir das Signal virtuell um
        $virtuellerPfad = "rme_background_handler.php?view_bg=global&t=" . time();
        echo json_encode(['success' => true, 'path' => $virtuellerPfad, 'filename' => 'Admin-Hintergrund (Datenbank)']);
        exit;
    }
    
    // HINTERGRUND DEAKTIVIEREN
    if ($_POST['action'] === 'disable_global_bg') {
        header('Content-Type: application/json');
        // Wir löschen den Eintrag aus deiner echten BLOB-Tabelle
        dbquery("DELETE FROM " . RME_DB_PREFIX . "chat_backgrounds WHERE bg_key = 'global'");
        echo json_encode(['success' => true]);
        exit;
    }
}

// -----------------------------------------------------------------
// SYSTEM-AKTIONEN: CHAT & ARCHIV LEEREN
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "clear_live") {
    if (!$ist_leitung_safe) { die("Keine Rechte"); }
    dbquery("TRUNCATE TABLE ".$chat_table);
    echo "success";
    exit;
}

if (isset($_GET['action']) && $_GET['action'] == "clear_archive") {
    if (!$ist_leitung_safe) { die("Keine Rechte"); }
    $id_query = dbquery("SELECT id FROM ".$chat_table." ORDER BY id DESC LIMIT 1 OFFSET 15");
    if ($id_query && dbrows($id_query) > 0) {
        $id_data = dbarray($id_query);
        dbquery("DELETE FROM ".$chat_table." WHERE id <= ".intval($id_data['id']));
    }
    echo "success";
    exit;
}
// =========================================================================
// 🔊 GLOBALER SOUNDBOARD-SENDER (MIT FIXEM SESSION-RELEASE)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] == "broadcast_dj_sound") {
    if (session_status() == PHP_SESSION_NONE) { session_name("RME_RADIO_CHAT_SESSION"); session_start(); }
    
    $sound_befehl = isset($_GET['sound']) ? trim(strip_tags((string)$_GET['sound'])) : '';
    $jetzt_time = time();
    $ziel_tabelle = "fusionb7754_chat_messages";
    
    if (!empty($sound_befehl)) {
        // Holt den echten Dateinamen aus Deiner Tabelle fusionb7754_chat_sounds
        $sound_abfrage = dbquery("SELECT datei_name FROM fusionb7754_chat_sounds WHERE sound_command='" . addslashes($sound_befehl) . "' LIMIT 1");
        
        if ($sound_abfrage && dbrows($sound_abfrage) > 0) {
            $sound_row = dbarray($sound_abfrage);
            $echter_dateiname = trim((string)$sound_row['datei_name']);
            
            // 🔥 DER ENTSCHEIDENDE SCHALTER: Wir schließen die Session EXAKT HIER,
            // bevor wir die Datenbank-Queries abfeuern! Das killt jeden Lade-Stau auf PC & Handy.
            if (session_status() == PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            
            // 1. Wir löschen den alten Sound-Eintrag
			dbquery("DELETE FROM " . $chat_table . " WHERE user_id=999 OR user_id=995 OR guest_name='SYSTEM_SOUND'");
          
            // 2. Trägt den neuen Jingle frisch ein
            dbquery("INSERT INTO " . $ziel_tabelle . " (user_id, guest_name, message, datestamp) 
                     VALUES (999, 'SYSTEM_SOUND', 'SOUND:" . addslashes($echter_dateiname) . "', '" . $jetzt_time . "')");
                     
            echo "sound_broadcast_success";
        }
    }
    // Verhindert, dass das Backend im Hintergrund weiterläuft und den Chat blockiert
// 🔥 UNZERSTÖRBARER DATABASE-CLEANER: Schließt MySQL, bevor das Skript stirbt!
global $mysqli, $db_connect;
if (isset($mysqli) && $mysqli instanceof mysqli) { 
    @mysqli_close($mysqli); 
} elseif (isset($db_connect) && is_resource($db_connect)) { 
    @mysql_close($db_connect); 
}
    exit;
}

// -----------------------------------------------------------------
// AKTION: NEUE NACHRICHT IM CHAT SPEICHERN (100% REBOOT / REWIND)
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "send" && (isset($_POST['msg']) || isset($_POST['message']) || isset($_POST['rme_message']))) {
    
    if (session_status() == PHP_SESSION_NONE) { 
        session_name("RME_RADIO_CHAT_SESSION");
        session_start(); 
    }

    date_default_timezone_set('Europe/Berlin');
    
$meine_aktuelle_session_id = session_id();
if (empty($meine_aktuelle_session_id) || $meine_aktuelle_session_id == "") {
    // 🔥 FIX: Koppelt die temporäre ID an IP + Browser-Spur, damit das private Fenster 
    // während der gesamten Sitzung dieselbe feste Identität behält und MySQL nicht flutet!
    $meine_aktuelle_session_id = md5($user_ip . ($_SERVER['HTTP_USER_AGENT'] ?? 'privat_mode'));
}

    $session_username = $_SESSION['rme_chat_guest_name'] ?? ($_SESSION['chat_user_name'] ?? '');
    $session_userid = isset($_SESSION['chat_user_id']) ? intval($_SESSION['chat_user_id']) : 0;

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $raw_message = $_POST['msg'] ?? $_POST['message'] ?? $_POST['rme_message'] ?? '';
   
    $message = preg_replace_callback('/([\xF0-\xF4][\x80-\xBF]{3})+/u', function($match) {
        $kette = $match[0]; $ausgabe = ''; $laenge = mb_strlen($kette, 'UTF-8');
        for ($i = 0; $i < $laenge; $i++) {
            $einzelZeichen = mb_substr($kette, $i, 1, 'UTF-8');
            $zeichenUtf32 = mb_convert_encoding($einzelZeichen, 'UTF-8', 'UTF-32'); 
            $hex = bin2hex($zeichenUtf32); $code = ltrim($hex, '0');
            $ausgabe .= '&#x' . strtoupper($code) . ';';
        }
        return $ausgabe;
    }, (string)$raw_message);

    $message = trim(strip_tags($message));
   
     // =========================================================================
    // 🔥 REPARIERT: ERWEITERTER COMMAND-FILTER (SCHRITT 1 - GLÜCKSRAD & SLOTS)
    // =========================================================================
    
    // --- 1A. DAS HÖRER-GLÜCKSRAD ---
    if (strtolower($message) === '/rad' || strtolower($message) === '/gluecksrad') {
        $spieler_id = intval($session_userid);
        $spieler_name = !empty($session_username) ? trim($session_username) : 'Gast';
        $aktueller_zeitstempel = time();
        $sicherer_chat_speicher = isset($chat_table) ? $chat_table : "fusionb7754_chat_messages";
        
        $cooldown_zeit = 300; 
        $darf_drehen = true;
        $restzeit_sek = 0;
        
        if ($spieler_id > 0 && $spieler_id !== 18) {
            $check_wheel = dbquery("SELECT last_wheel FROM fusionb7754_chat_hoerer_wheel WHERE user_id = " . $spieler_id . " LIMIT 1");
            if ($check_wheel && dbrows($check_wheel) > 0) {
                $wheel_row = dbarray($check_wheel);
                $vergangene_zeit = $aktueller_zeitstempel - intval($wheel_row['last_wheel']);
                if ($vergangene_zeit < $cooldown_zeit) { $darf_drehen = false; $restzeit_sek = $cooldown_zeit - $vergangene_zeit; }
            }
        }
        if ($spieler_id === 18) { $darf_drehen = true; }
        
        if (!$darf_drehen) {
            $fehler_text = "🎰 [Glücksrad]: Langsam " . $spieler_name . "! Du darfst erst in " . $restzeit_sek . " Sek. wieder am Rad drehen.";
            dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (995, 'SYSTEM_SOUND', 'WHEEL_ERROR:" . addslashes($fehler_text) . "', '" . $aktueller_zeitstempel . "')");
        } else {
            $gewinne = [
                "🎉 GEWINN! Du darfst Dir sofort einen Musikwunsch beim DJ erfüllen lassen! 🎵",
                "❌ Niete! Das Rad blieb knapp vor dem Hauptgewinn stehen. Versuch es gleich wieder!",
                "🎉 GEWINN! Du darfst einen dicken Gruß an alle Hörer im Stream raushauen! 🎙️",
                "❌ Niete! Der Wind hat das Rad ausgebremst. Nächste Runde kommt bestimmt!",
                "🎉 GEWINN! Der DJ muss Dir Deinen absoluten Lieblings-Smiley im Chat widmen! 😊",
                "❌ Niete! Satz mit X, das war wohl nix. Trink erst mal einen Kaffee! ☕",

            ];
            $zufalls_gewinn = $gewinne[array_rand($gewinne)];
            $gewinn_nachricht = "<span style='color:#00ffaa !important; font-weight:bold !important; font-size:12px !important; margin-left:5px;'>🎰 <strong>" . htmlspecialchars($spieler_name, ENT_QUOTES, 'UTF-8') . "</strong> dreht am Glücksrad... <br>➡️ " . $zufalls_gewinn . "</span>";
            
            dbquery("DELETE FROM " . $sicherer_chat_speicher . " WHERE user_id = 777 AND guest_name = 'SYSTEM_SOUND'");
            dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (777, 'SYSTEM_SOUND', 'WHEEL_WIN:" . addslashes($gewinn_nachricht) . "', '" . $aktueller_zeitstempel . "')");
            if ($spieler_id > 0) { dbquery("INSERT INTO fusionb7754_chat_hoerer_wheel (user_id, last_wheel) VALUES (" . $spieler_id . ", " . $aktueller_zeitstempel . ") ON DUPLICATE KEY UPDATE last_wheel = " . $aktueller_zeitstempel); }
        }
        header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
    }

    // --- 1B. DIE INTERAKTIVE HÖRER-SLOT-MACHINE (UNZERSTÖRBARE ACCOUNT-RETTUNG) ---
    if (strtolower($message) === '/slot' || strtolower($message) === '/slots') {
        $aktueller_zeitstempel = time();
        $sicherer_chat_speicher = isset($chat_table) ? $chat_table : "fusionb7754_chat_messages";
        
        // 🎯 ULTIMATIVE RETTUNG: Zieht Deinen echten Namen direkt aus der unfehlbaren URL-Leitung!
        $spieler_name = "Hörer";
        if (isset($_REQUEST['admin_auth_name']) && trim($_REQUEST['admin_auth_name']) !== "") {
            $spieler_name = trim($_REQUEST['admin_auth_name']);
        } elseif (!empty($session_username)) {
            $spieler_name = trim($session_username);
        } elseif (isset($_SESSION['chat_user_name'])) {
            $spieler_name = trim($_SESSION['chat_user_name']);
        }
        
        // ID-Ermittlung absichern
        $spieler_id = 0;
        if (isset($_REQUEST['admin_auth_id'])) {
            $spieler_id = intval($_REQUEST['admin_auth_id']);
        } elseif (isset($session_userid)) {
            $spieler_id = intval($session_userid);
        }
        
        $sicherer_name = htmlspecialchars($spieler_name, ENT_QUOTES, 'UTF-8');
        $user_id_key = ($spieler_id > 0) ? $spieler_id : rand(999000, 999999);
        
        $cooldown_zeit = 180; 
        $darf_spielen = true; $restzeit_sek = 0;
        
        if ($spieler_id > 0 && $spieler_id !== 18) {
            $check_slots = dbquery("SELECT last_slot FROM fusionb7754_chat_hoerer_slots WHERE user_id = " . $spieler_id . " LIMIT 1");
            if ($check_slots && dbrows($check_slots) > 0) {
                $slots_row = dbarray($check_slots);
                $vergangene_zeit = $aktueller_zeitstempel - intval($slots_row['last_slot']);
                if ($vergangene_zeit < $cooldown_zeit) { $darf_spielen = false; $restzeit_sek = $cooldown_zeit - $vergangene_zeit; }
            }
        }
        if ($spieler_id === 18) { $darf_spielen = true; }
        
        if (!$darf_spielen) {
            $fehler_text = "🎰 [Slot-Machine]: Langsam " . $sicherer_name . "! Die Walzen kühlen ab. Warte " . $restzeit_sek . " Sek.";
            dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (995, 'SYSTEM_SOUND', 'WHEEL_ERROR:" . addslashes($fehler_text) . "', '" . $aktueller_zeitstempel . "')");
        } else {
            $pool = ["🍒", "🍋", "🍉", "💎", "👑", "🍀"];
            $w1 = $pool[array_rand($pool)]; $w2 = $pool[array_rand($pool)]; $w3 = $pool[array_rand($pool)];
            
            if ($w1 === $w2 && $w2 === $w3) {
                $ergebnis_text = ($w1 === "👑" || $w1 === "💎") ? "🏆 MEGA JACKPOT!! Du hast den Chat gesprengt! Der DJ schuldet Dir Respekt! 🔥👑" : "🎉 JACKPOT! Drei Richtige! Wünsche Dir sofort einen Hit beim DJ! 🎵";
            } elseif ($w1 === $w2 || $w2 === $w3 || $w1 === $w3) {
                $ergebnis_text = "💵 Kleiner Gewinn! Zwei Richtige. Immerhin kein Satz mit X! 😉";
            } else {
                $ergebnis_text = "❌ Niete! Kein Pärchen erwischt. Schade, wirf gleich noch eine Münze ein! 🪙";
            }
            
            // 🎯 NUTZT JETZT DEN SICHEREN NAMEN
            $slot_nachricht = "<span style='color:#00ffaa !important; font-weight:bold !important; font-size:12px !important; margin-left:5px;'>🎰 <strong>" . $sicherer_name . "</strong> zieht am Hebel der Slot-Machine... <br>➡️ [ " . $w1 . " | " . $w2 . " | " . $w3 . " ] 🎰 <br>➡️ " . $ergebnis_text . "</span>";
            
            dbquery("DELETE FROM " . $sicherer_chat_speicher . " WHERE user_id = 777 AND guest_name = 'SYSTEM_SOUND'");
            dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (777, 'SYSTEM_SOUND', 'WHEEL_WIN:" . addslashes($slot_nachricht) . "', '" . $aktueller_zeitstempel . "')");
            if ($spieler_id > 0) { dbquery("INSERT INTO fusionb7754_chat_hoerer_slots (user_id, last_slot) VALUES (" . $spieler_id . ", " . $aktueller_zeitstempel . ") ON DUPLICATE KEY UPDATE last_slot = " . $aktueller_zeitstempel); }
        }
        header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
    }

    // =========================================================================
    // 🔥 REPARIERT: INTEKRAKTIVES TIC-TAC-TOE BACKEND (LIVE-SYMBOL-TRACKING)
    // =========================================================================
    if (strpos(strtolower($message), '/ttt') === 0) {
        
        $sauberes_html = "";
        $fehlerfreier_spieler_name = "";
        
        if (isset($_GET['admin_auth_name']) && trim((string)$_GET['admin_auth_name']) !== '' && trim((string)$_GET['admin_auth_name']) !== 'undefined') {
            $fehlerfreier_spieler_name = trim((string)$_GET['admin_auth_name']);
        } elseif (!empty($current_chat_user) && $current_chat_user !== "Gast" && $current_chat_user !== "User") {
            $fehlerfreier_spieler_name = $current_chat_user;
        } elseif (isset($_SESSION['chat_user_name']) && trim($_SESSION['chat_user_name']) !== "") {
            $fehlerfreier_spieler_name = trim($_SESSION['chat_user_name']);
        } elseif (isset($_SESSION['rme_chat_guest_name']) && trim($_SESSION['rme_chat_guest_name']) !== "") {
            $fehlerfreier_spieler_name = trim($_SESSION['rme_chat_guest_name']);
        }

        $säuberungs_liste = array("_Gast", "_CU", "[ADMIN]", "[MODERATOR]", "[MOD]", "[HADMIN]");
        $spieler_name = trim(str_replace($säuberungs_liste, "", $fehlerfreier_spieler_name));
        
        if (empty($spieler_name) || strtolower($spieler_name) === "undefined") { 
            $spieler_id_temp = isset($_SESSION['chat_user_id']) ? intval($_SESSION['chat_user_id']) : 0;
            $spieler_name = ($spieler_id_temp === 18) ? "DJ-Tomjac" : "Gast_" . rand(100, 999); 
        }

        $aktueller_zeitstempel = time();
        $sicherer_chat_speicher = isset($chat_table) ? $chat_table : "fusionb7754_chat_messages";
        $state_file = dirname(__FILE__) . "/ttt_state.json";

        // 🎯 DYNAMIC ROLE ENGINE: Wir fragen die echte MySQL-Tabelle ab, um zu sehen, 
        // welches Symbol du in DIESEM Match wirklich hast!
        $mein_aktuelles_zug_symbol = "X"; // Standard-Fallback
        $safe_name_db = addslashes($spieler_name);
        
        // Suche nach dem laufenden Spiel in der TTT-Tabelle
        $rollen_check_q = dbquery("SELECT * FROM `fusionb7754_chat_ttt` WHERE `status` = 'active' AND (LOWER(`player_x`) = LOWER('$safe_name_db') OR LOWER(`player_o`) = LOWER('$safe_name_db')) LIMIT 1");
        
        if ($rollen_check_q && dbarraynum($rollen_check_q) > 0) {
            $aktuelles_match = dbarray($rollen_check_q);
            // Wenn dein Name bei player_o steht, hast du unumstößlich Kreis (O)!
            if (strtolower(trim($aktuelles_match['player_o'])) === strtolower(trim($spieler_name))) {
                $mein_aktuelles_zug_symbol = "O";
            }
        }

        // Spielstand aus Datei laden
        $board = array_fill(0, 9, "");
        $current_turn = "X";
        $last_player = "";
        
        if (file_exists($state_file)) {
            $state = json_decode(file_get_contents($state_file), true);
            if (isset($state['board'])) { $board = $state['board']; }
            if (isset($state['turn'])) { $current_turn = $state['turn']; }
            if (isset($state['last_player'])) { $last_player = $state['last_player']; }
        }


        // --- AKTION A: EIN NEUES SPIEL STARTEN ---
        if (strtolower($message) === '/ttt' || strtolower($message) === '/ttt start') {
            $board = array_fill(0, 9, ""); // Board leeren
            $current_turn = "X";
            $last_player = "";
            
            // Wir putzen alte Spielfelder radikal aus dem Chat, damit nur EIN Feld existiert!
            dbquery("DELETE FROM " . $sicherer_chat_speicher . " WHERE user_id = 888");
            @unlink($state_file);
        } 
        // --- AKTION B: EIN FELD ANKLICKEN (DIREKTER BROWSER-ZUG - SYNCHRONISIERT) ---
        elseif (preg_match('/\/ttt zug (\d)/i', $message, $treffer)) {
            // 🎯 DEIN PERFEKTER ORIGINAL-INDEX-ZUG!
            $feld_index = intval($treffer[1]);
            
            // Spam-Schutz: Man darf nicht zweimal hintereinander ziehen!
            if (!empty($last_player) && strtolower($spieler_name) === strtolower($last_player)) {
                header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
            }
            
            if (isset($board[$feld_index]) && $board[$feld_index] === "") {
                $board[$feld_index] = $current_turn;
                $last_player = $spieler_name;
                $current_turn = ($current_turn === "X") ? "O" : "X";
            }
            
            // =========================================================================
            // 🔥 LILA MULTIPLAYER-BRIDGE: SYNCHRONISIERT DAS BOARD DIREKT MIT MYSQL
            // =========================================================================
            // Wir wandeln das Array wieder in den Text um, den die Spieletabelle erwartet
            $neues_board_fuer_db = implode(',', $board);
            
            // Wir bestimmen den neuen Status live anhand der Gewinnprüfung (die direkt darunter folgt)
            $neuer_db_status = 'active';
            
            $safe_spieler_name = addslashes($spieler_name);
            
            // Jagt den Zug live in die Tabelle, damit das Pop-up bei X und O sofort Bescheid weiß!
            $sync_query = "UPDATE `fusionb7754_chat_ttt` 
                           SET `board` = '$neues_board_fuer_db', 
                               `turn` = '$current_turn', 
                               `status` = '$neuer_db_status', 
                               `last_update` = " . time() . " 
                           WHERE `status` = 'active' 
                           AND (LOWER(`player_x`) = LOWER('$safe_spieler_name') OR LOWER(`player_o`) = LOWER('$safe_spieler_name'))";
                           
            dbquery($sync_query);
            // =========================================================================
            
            // Wir löschen das alte Feld aus der DB, bevor wir das aktualisierte eintragen!
            dbquery("DELETE FROM " . $sicherer_chat_speicher . " WHERE user_id = 888");
        }


        // 💾 Spielstand für die JSON-Datei wegsichern
        file_put_contents($state_file, json_encode(['board' => $board, 'turn' => $current_turn, 'last_player' => $last_player]));

        // 🏆 1. JETZT DIE GEWINNPRÜFUNG DIREKT HIER FEUERN! (MUSS VOR DEM DB-UPDATE STEHEN!)
        $gewinn_linien = [[0,1,2], [3,4,5], [6,7,8], [0,3,6], [1,4,7], [2,5,8], [0,4,8], [2,4,6]];
        $sieger = "";
        foreach ($gewinn_linien as $linie) {
            if ($board[$linie[0]] !== "" && $board[$linie[0]] === $board[$linie[1]] && $board[$linie[1]] === $board[$linie[2]]) {
                $sieger = $board[$linie[0]];
                break;
            }
        }

        // Unentschieden prüfen (keine leeren Felder mehr)
        $unentschieden = (!in_array("", $board) && $sieger === "");

        if ($sieger !== "" || $unentschieden) {
            @unlink($state_file); // Löscht die JSON-Datei bei Spielende sofort
        }

        // =========================================================================
        // 🔥 2. MULTIPLAYER-BRIDGE: MIT DEM RICHTIGEN STATUS UPDATEN
        // =========================================================================
        $neues_board_fuer_db = implode(',', $board);
        
        // Wir bestimmen den exakten neuen Status anhand der Gewinnprüfung oben!
        $neuer_db_status = 'active';
        if ($sieger === 'X') { $neuer_db_status = 'won_x'; }
        elseif ($sieger === 'O') { $neuer_db_status = 'won_o'; }
        elseif ($unentschieden) { $neuer_db_status = 'draw'; }
        
        $safe_spieler_name = addslashes($spieler_name);
        
        // Jagt den brandneuen Zustand samt Sieger live in deine TTT-Tabelle!
        $sync_query = "UPDATE `fusionb7754_chat_ttt` 
                       SET `board` = '$neues_board_fuer_db', 
                           `turn` = '$current_turn', 
                           `status` = '$neuer_db_status', 
                           `last_update` = " . time() . " 
                       WHERE `status` = 'active' 
                       AND (LOWER(`player_x`) = LOWER('$safe_spieler_name') OR LOWER(`player_o`) = LOWER('$safe_spieler_name'))";
                       
        dbquery($sync_query);
        // =========================================================================

        // Bleibt auskommentiert! Kein HTML-Müll im normalen Nachrichtenverlauf
        /* 
        dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) 
                 VALUES (888, 'SYSTEM_GAME', 'TTT_ARENA:" . addslashes($grid_html) . "', '" . $aktueller_zeitstempel . "')");
        */

        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
// 🔥 UNZERSTÖRBARER DATABASE-CLEANER: Schließt MySQL, bevor das Skript stirbt!
global $mysqli, $db_connect;
if (isset($mysqli) && $mysqli instanceof mysqli) { 
    @mysqli_close($mysqli); 
} elseif (isset($db_connect) && is_resource($db_connect)) { 
    @mysql_close($db_connect); 
} 
 exit;
    }


    // HIER LÄUFT JETZT DEIN ORIGINALER UNTERER CODE WEITER (Das echte INSERT INTO für normale Texte)...

    // =========================================================================
    // 🔵🔴 MULTIPLAYER-ENGINE: VIER GEWINNT BACKEND (TEIL 1 - REPARIERT)
    // =========================================================================
    
    // 🎯 FIX 1: Der asynchrone Abfang-Jäger für die Klick-Engine!
    if (!isset($message) || trim($message) === "") {
        if (isset($_POST['message'])) {
            $message = trim($_POST['message']);
        }
    }

    if (strpos(strtolower($message), '/v4g') === 0) {
        
        // 📡 UNFEHLBARER NAMENS-SPION ÜBER DIE CORE-LEITUNG
        $fehlerfreier_spieler_name = "";
        if (isset($_GET['admin_auth_name']) && trim((string)$_GET['admin_auth_name']) !== '' && trim((string)$_GET['admin_auth_name']) !== 'undefined') {
            $fehlerfreier_spieler_name = trim((string)$_GET['admin_auth_name']);
        } elseif (!empty($current_chat_user) && $current_chat_user !== "Gast") {
            $fehlerfreier_spieler_name = $current_chat_user;
        } elseif (isset($_SESSION['chat_user_name'])) {
            $fehlerfreier_spieler_name = $_SESSION['chat_user_name'];
        }

        $säuberungs_liste = array("_Gast", "_CU", "[ADMIN]", "[MODERATOR]", "[MOD]", "[HADMIN]");
        $spieler_name = trim(str_replace($säuberungs_liste, "", $fehlerfreier_spieler_name));
        if (empty($spieler_name)) { $spieler_name = "Gast_" . rand(100, 999); }

        $sicherer_chat_speicher = isset($chat_table) ? $chat_table : "fusionb7754_chat_messages";
        $state_file = dirname(__FILE__) . "/v4g_state.json";

        // Spielfeld laden (7 Spalten x 6 Reihen = 42 Felder)
        $board = array_fill(0, 42, "");
        $current_turn = "X";
        $last_player = "";
        
        // 🎯 FIX 2: Wir synchronisieren den Startzustand IMMER zuerst direkt aus der echten DB-Tabelle!
        $safe_spieler_name = addslashes($spieler_name);
        $sync_board_q = dbquery("SELECT * FROM `fusionb7754_chat_v4g` WHERE `status` = 'active' AND (LOWER(`player_x`) = LOWER('$safe_spieler_name') OR LOWER(`player_o`) = LOWER('$safe_spieler_name')) LIMIT 1");
        
        if ($sync_board_q && dbrows($sync_board_q) > 0) {
            $match_data = dbarray($sync_board_q);
            if (!empty($match_data['board'])) {
                $board = explode(',', $match_data['board']);
            }
        }
        
        // Fallback-Laden aus der JSON-Datei für den internen Verlauf
        if (file_exists($state_file) && empty($match_data['board'])) {
            $state = json_decode(file_get_contents($state_file), true);
            if (isset($state['board'])) { $board = $state['board']; }
            if (isset($state['turn'])) { $current_turn = $state['turn']; }
            if (isset($state['last_player'])) { $last_player = $state['last_player']; }
        }
        // --- AKTION B: EIN CHIP IN EINE SPALTE WERFEN (PULSIERENDER KLICK-FIX) ---
        if (preg_match('/\/v4g zug (\d)/i', $message, $treffer)) {
            // 🎯 FIX 1: $treffer[1] liest die Spaltennummer unfehlbar als echte Zahl aus!
            $spalte = intval($treffer[1]); 
            
            // Weiche: Match live aus der echten V4G-Tabelle holen
            $safe_spieler_name = addslashes($spieler_name);
            $zug_check_q = dbquery("SELECT * FROM `fusionb7754_chat_v4g` WHERE `status` = 'active' AND (LOWER(`player_x`) = LOWER('$safe_spieler_name') OR LOWER(`player_o`) = LOWER('$safe_spieler_name')) LIMIT 1");
            
            if ($zug_check_q && dbrows($zug_check_q) > 0) {
                $match = dbarray($zug_check_q);
                $erlaubter_spieler = ($match['turn'] === 'X') ? $match['player_x'] : $match['player_o'];
                $aktuelles_db_symbol = $match['turn']; // X oder O
                
                // 🛑 EISERNE ZUGS-SPERRE: Du darfst nur klicken, wenn du wirklich dran bist!
                if (strtolower(trim($spieler_name)) !== strtolower(trim($erlaubter_spieler))) {
                    header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['status' => 'success']); exit;
                }
            } else {
                header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['status' => 'success']); exit;
            }

            // Spam-Schutz: Man darf nicht zweimal hintereinander ziehen!
            if (!empty($last_player) && strtolower($spieler_name) === strtolower($last_player)) {
                header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['status' => 'success']); exit;
            }
            
            // 🎯 🛠️ DIE SCHWERKRAFT-ENGINE REPARIERT!
            // Wir wandeln das Board-Gedächtnis für den Algorithmus kurz in ein echtes Array um
            $board_pruefung = $board; 
            $zug_erfolgreich = false;
            
            // Die Schleife zählt sauber von Reihe 5 (ganz unten) bis Reihe 0 (ganz oben) runter!
            for ($reihe = 5; $reihe >= 0; $reihe--) {
                $index = ($reihe * 7) + $spalte; // Berechnet das exakte Feld im 42er Raster
                
                if (isset($board_pruefung[$index]) && $board_pruefung[$index] === "") {
                    $board[$index] = $aktuelles_db_symbol; // Chip fällt felsenfest rein!
                    $last_player = $spieler_name;
                    $current_turn = ($aktuelles_db_symbol === "X") ? "O" : "X";
                    $zug_erfolgreich = true;
                    break; // Schleife sofort beenden, Chip hat seinen Boden gefunden!
                }
            }
            
            // Falls die Spalte bereits randvoll war, blockieren wir lautlos
            if (!$zug_erfolgreich) {
                header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['status' => 'success']); exit;
            }
        }
    // 🔥 MASTER-FIX: Diese Klammer schließt jetzt das "if (strpos(strtolower($message), '/v4g') === 0)" fehlerfrei!

       // 🏆 MATHEMATISCHE GEWINN-PRÜFUNG (4ER-REIHEN ERKENNUNG AUTOMATISCH)
        // =========================================================================
        $sieger = "";
        // 💾 Spielstand für die JSON-Datei wegsichern
        file_put_contents($state_file, json_encode(['board' => $board, 'turn' => $current_turn, 'last_player' => $last_player]));

        // =========================================================================
        // 🏆 1. MATHEMATISCHE GEWINNPRÜFUNG (MUSS VOR DEM DB-UPDATE STEHEN!)
        // =========================================================================
        $sieger = "";
        
        // A. Waagerechte Prüfung (Horizontale 4er-Reihen im 7x6 Feld)
        for ($r = 0; $r < 6; $r++) {
            for ($s = 0; $s < 4; $s++) {
                $start = ($r * 7) + $s;
                if ($board[$start] !== "" && $board[$start] === $board[$start+1] && $board[$start+1] === $board[$start+2] && $board[$start+2] === $board[$start+3]) {
                    $sieger = $board[$start];
                }
            }
        }
        
        // B. Senkrechte Prüfung (Vertikale 4er-Reihen im 7x6 Feld)
        for ($r = 0; $r < 3; $r++) {
            for ($s = 0; $s < 7; $s++) {
                $start = ($r * 7) + $s;
                if ($board[$start] !== "" && $board[$start] === $board[$start+7] && $board[$start+7] === $board[$start+14] && $board[$start+14] === $board[$start+21]) {
                    $sieger = $board[$start];
                }
            }
        }
        
        // C. Diagonale Prüfung (nach rechts unten \)
        for ($r = 0; $r < 3; $r++) {
            for ($s = 0; $s < 4; $s++) {
                $start = ($r * 7) + $s;
                if ($board[$start] !== "" && $board[$start] === $board[$start+8] && $board[$start+8] === $board[$start+16] && $board[$start+16] === $board[$start+24]) {
                    $sieger = $board[$start];
                }
            }
        }
        
        // D. Diagonale Prüfung (nach links unten /)
        for ($r = 0; $r < 3; $r++) {
            for ($s = 3; $s < 7; $s++) {
                $start = ($r * 7) + $s;
                if ($board[$start] !== "" && $board[$start] === $board[$start+6] && $board[$start+6] === $board[$start+12] && $board[$start+12] === $board[$start+18]) {
                    $sieger = $board[$start];
                }
            }
        }

        // Unentschieden prüfen (keine leeren Felder mehr im 42er Grid)
        $unentschieden = (!in_array("", $board) && $sieger === "");

        if ($sieger !== "" || $unentschieden) {
            @unlink($state_file); // Löscht die JSON-Datei bei Spielende sofort
        }

        // =========================================================================
        // 🔥 2. MULTIPLAYER-BRIDGE: MIT DEM RICHTIGEN STATUS ENTSPRECHEND UPDATEN
        // =========================================================================
        $neues_board_fuer_db = implode(',', $board);
        
        // Wir bestimmen den exakten neuen Status anhand der Gewinnprüfung oben!
        $neuer_db_status = 'active';
        if ($sieger === 'X') { $neuer_db_status = 'won_x'; }
        elseif ($sieger === 'O') { $neuer_db_status = 'won_o'; }
        elseif ($unentschieden) { $neuer_db_status = 'draw'; }
        
        $safe_spieler_name = addslashes($spieler_name);
        
        // Jagt den brandneuen Zustand samt Sieger live in deine V4G-Tabelle!
        $sync_query = "UPDATE `fusionb7754_chat_v4g` 
                       SET `board` = '$neues_board_fuer_db', 
                           `turn` = '$current_turn', 
                           `status` = '$neuer_db_status', 
                           `last_update` = " . time() . " 
                       WHERE `status` = 'active' 
                       AND (LOWER(`player_x`) = LOWER('$safe_spieler_name') OR LOWER(`player_o`) = LOWER('$safe_spieler_name'))";
                       
        dbquery($sync_query);
        // =========================================================================

        // Bleibt auskommentiert! Kein HTML-Müll im normalen Nachrichtenverlauf
        /* 
        dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) 
                 VALUES (888, 'SYSTEM_GAME', 'V4G_ARENA:...', '" . time() . "')");
        */

        header('Content-Type: application/json');
        echo json_encode(['status' => 'success']);
// 🔥 UNZERSTÖRBARER DATABASE-CLEANER: Schließt MySQL, bevor das Skript stirbt!
global $mysqli, $db_connect;
if (isset($mysqli) && $mysqli instanceof mysqli) { 
    @mysqli_close($mysqli); 
} elseif (isset($db_connect) && is_resource($db_connect)) { 
    @mysql_close($db_connect); 
}
        exit;
    }



    // =========================================================================
    // 🔥 REPARIERT: CRASH-SICHERES INTERAKTIVES HÖRER-SOUNDBOARD (DATENBANK V3)
    // =========================================================================
    $sauberes_kommando = isset($message) ? strtolower(trim((string)$message)) : '';

    if ($sauberes_kommando === '/sound lachen' || $sauberes_kommando === '/sound klatschen' || $sauberes_kommando === '/sound buh' || $sauberes_kommando === '/sound tusch') {
        if (session_status() === PHP_SESSION_NONE) { session_name("RME_RADIO_CHAT_SESSION"); session_start(); }
        
        $spieler_id = isset($session_userid) ? intval($session_userid) : 0;
        $spieler_name = !empty($session_username) ? trim($session_username) : 'Gast';
        $aktueller_zeitstempel = time();
        $sicherer_chat_speicher = isset($chat_table) ? $chat_table : "fusionb7754_chat_messages";
        
        $sound_typ = str_replace('/sound ', '', $sauberes_kommando);
        
        $sound_befehle_db = [
            'lachen'    => 'hoerer_lachen',
            'klatschen' => 'hoerer_applaus',
            'buh'       => 'hoerer_buhruf', 
            'tusch'     => 'hoerer_tusch'  
        ];
        
        if (array_key_exists($sound_typ, $sound_befehle_db)) {
            $cooldown_zeit = 300; 
            $darf_spielen = true;
            $restzeit_sek = 0;
            
            if ($spieler_id > 0 && $spieler_id !== 18) {
                $check_sound = dbquery("SELECT last_sound FROM fusionb7754_chat_hoerer_sounds WHERE user_id = " . $spieler_id . " LIMIT 1");
                if ($check_sound && dbrows($check_sound) > 0) {
                    $sound_row = dbarray($check_sound);
                    $vergangene_zeit = $aktueller_zeitstempel - intval($sound_row['last_sound']);
                    if ($vergangene_zeit < $cooldown_zeit) { $darf_spielen = false; $restzeit_sek = $cooldown_zeit - $vergangene_zeit; }
                }
            }
            
            if ($spieler_id <= 0) {
                if (isset($_SESSION['last_hoerer_sound_time'])) {
                    $vergangene_zeit_gast = $aktueller_zeitstempel - intval($_SESSION['last_hoerer_sound_time']);
                    if ($vergangene_zeit_gast < $cooldown_zeit) { $darf_spielen = false; $restzeit_sek = $cooldown_zeit - $vergangene_zeit_gast; }
                }
            }
            
            if ($spieler_id === 18 || strtolower($spieler_name) === 'dj-tomjac') { $darf_spielen = true; }
            
            if (!$darf_spielen) {
                $fehler_text = "🔊 [Soundboard]: Langsam " . $spieler_name . "! Du darfst erst in " . $restzeit_sek . " Sek. wieder einen Sound abspielen.";
                dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (995, 'SYSTEM_SOUND', 'WHEEL_ERROR:" . addslashes($fehler_text) . "', '" . $aktueller_zeitstempel . "')");
            } else {
                // 🎯 BLITZ-REINIGUNG: Alte Texte (988) und Sounds (999) vor dem neuen Eintrag gnadenlos weglöschen!
                dbquery("DELETE FROM " . $sicherer_chat_speicher . " WHERE user_id = 988 OR user_id = 999");

                $emoji_map = ['lachen' => '😂', 'klatschen' => '👏', 'buh' => '👎', 'tusch' => '🥁'];
                $emoji = isset($emoji_map[$sound_typ]) ? $emoji_map[$sound_typ] : '🔊';
                
                $chat_text = "<div style='color:#ffed00 !important; font-weight:bold; font-size:12px; padding:4px 0;'>" . $emoji . " <strong>" . htmlspecialchars($spieler_name, ENT_QUOTES, 'UTF-8') . "</strong> löst im Studio die Live-Reaktion aus: [" . strtoupper($sound_typ) . "]!</div>";
                
                dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (988, 'SYSTEM_SOUND', 'HOERER_SND:" . addslashes($chat_text) . "', '" . $aktueller_zeitstempel . "')");
                
                $signal_text = "SOUND:" . $sound_befehle_db[$sound_typ] . ".mp3";
                $einzigartiger_sound_datestamp = time() . rand(100, 999);
                
                dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (999, 'SYSTEM_SOUND', '" . addslashes($signal_text) . "', '" . $einzigartiger_sound_datestamp . "')");

                if ($spieler_id > 0) {
                    dbquery("INSERT INTO fusionb7754_chat_hoerer_sounds (user_id, last_sound) VALUES (" . $spieler_id . ", " . $aktueller_zeitstempel . ") ON DUPLICATE KEY UPDATE last_sound = " . $aktueller_zeitstempel);
                } else {
                    $_SESSION['last_hoerer_sound_time'] = $aktueller_zeitstempel;
                }
            }
        }
        
        header('Content-Type: application/json'); echo json_encode(['status' => 'success']); 
// 🔥 UNZERSTÖRBARER DATABASE-CLEANER: Schließt MySQL, bevor das Skript stirbt!
global $mysqli, $db_connect;
if (isset($mysqli) && $mysqli instanceof mysqli) { 
    @mysqli_close($mysqli); 
} elseif (isset($db_connect) && is_resource($db_connect)) { 
    @mysql_close($db_connect); 
}
		exit;
    }


    // =========================================================================
    // 🎮 SCHRITT 1: NAMENSSICHERES ZAPPER- & HOERER-ZAHLENDUELL
    // =========================================================================
    if (strpos(strtolower($message), '/zahl ') === 0 || strtolower($message) === '/zahl') {
        $aktueller_zeitstempel = time();
        $sicherer_chat_speicher = isset($chat_table) ? $chat_table : "fusionb7754_chat_messages";
        
        // 🔥 UNZERSTÖRBARER NAMENS-DETEKTOR: Sucht in 4 Stufen nach dem echten Namen!
        $ermittelter_name = "Hörer";
        
        if (isset($_GET['admin_auth_name']) && trim((string)$_GET['admin_auth_name']) !== '' && trim((string)$_GET['admin_auth_name']) !== 'undefined') {
            // A. Absolut sicher: Der Name, den das Browser-Fenster beim Tippen mitschickt!
            $ermittelter_name = trim((string)$_GET['admin_auth_name']);
        } elseif (!empty($current_chat_user) && $current_chat_user !== "Gast" && $current_chat_user !== "User") {
            // B. Aus dem CMS-Core, falls registriert
            $ermittelter_name = $current_chat_user;
        } elseif (!empty($session_username_start) && $session_username_start !== "Gast") {
            // C. Aus der geretteten Handy-Session
            $ermittelter_name = $session_username_start;
        } elseif (!empty($final_guest_name)) {
            // D. Falls es ein anonymer Gast im System ist
            $ermittelter_name = $final_guest_name;
        }

        // Wir putzen Anhänge wie _CU, _Gast oder Rang-Tags radikal weg!
        $säuberungs_array = array("_Gast", "_CU", "[ADMIN]", "[MODERATOR]", "[MOD]", "[HADMIN]");
        $fehlerfreier_name = trim(str_replace($säuberungs_array, "", $ermittelter_name));
        if ($fehlerfreier_name === "" || strtolower($fehlerfreier_name) === "undefined") { $fehlerfreier_name = "Hörer"; }

        $sicherer_name = htmlspecialchars($fehlerfreier_name, ENT_QUOTES, 'UTF-8');
        
        // 🔥 SCHUTZ-RIEGEL FÜR DIE SPIELER-ID:
        $spieler_id = isset($session_userid_start) ? intval($session_userid_start) : 0;
        $get_auth_id_backup = isset($_GET['admin_auth_id']) ? intval($_GET['admin_auth_id']) : 0;
        
        // Wenn es eine echte ID (z.B. Maiks ID 6 oder deine 18) gibt, nutzen wir sie, sonst die Backup-ID
        $user_id_key = ($spieler_id > 0) ? $spieler_id : (($get_auth_id_backup > 0) ? $get_auth_id_backup : 999777);
        
        // Falls ein Hörer Maik oder Tomjac heißt, aber die ID auf 0 rutscht, sichern wir sie ab
        if ($user_id_key <= 0) {
            $user_id_key = abs(crc32($fehlerfreier_name)) % 100000; // Erzeugt eine feste, eindeutige ID aus dem Namen!
        }

        $cooldown_zeit = 10; 
        $darf_raten = true; $restzeit_sek = 0;
        // =========================================================================

        
        if ($spieler_id > 0 && $spieler_id !== 18) {
            $check_guess = dbquery("SELECT last_guess FROM fusionb7754_chat_hoerer_guess WHERE user_id = " . $spieler_id . " LIMIT 1");
            if ($check_guess && dbrows($check_guess) > 0) {
                $guess_row = dbarray($check_guess);
                $vergangene_zeit = $aktueller_zeitstempel - intval($guess_row['last_guess']);
                if ($vergangene_zeit < $cooldown_zeit) { $darf_raten = false; $restzeit_sek = $cooldown_zeit - $vergangene_zeit; }
            }
        }
        
        if (!$darf_raten) {
            $fehler_text = "🎮 [Zahlen-Duell]: Zu schnell, " . $sicherer_name . "! Warte noch " . $restzeit_sek . " Sek. bis zum nächsten Tipp.";
            dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (995, 'SYSTEM_SOUND', 'WHEEL_ERROR:" . addslashes($fehler_text) . "', '" . $aktueller_zeitstempel . "')");
            header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
        } else {
            $geheimzahl = rand(1, 100);
            
            $db_search = dbquery("SELECT secret_number FROM fusionb7754_chat_hoerer_guess WHERE user_id = " . $user_id_key . " LIMIT 1");
            if ($db_search && dbrows($db_search) > 0) {
                $geheimzahl = intval(dbarray($db_search)['secret_number']);
            } else {
                dbquery("INSERT IGNORE INTO fusionb7754_chat_hoerer_guess (user_id, secret_number, last_guess) VALUES (" . $user_id_key . ", " . $geheimzahl . ", 0)");
            }
            
            // =========================================================================
            // 🎮 NEUE SPIEL-LOGIK: KEIN AUTOMATISCHES LÖSCHEN MEHR WÄHREND DES RATENS!
            // =========================================================================
            $tipp = trim(str_replace('/zahl ', '', strtolower($message)));
            $tipp_int = intval($tipp);
            
            if ($tipp === '/zahl' || $message === '/zahl' || $tipp_int <= 0 || $tipp_int > 100) {
                // Ein NEUES Spiel wird gestartet! JETZT räumen wir die alten Spieltexte aus dem Verlauf auf!
                dbquery("DELETE FROM " . $sicherer_chat_speicher . " WHERE user_id = 777 AND guest_name = 'SYSTEM_SOUND'");
                
                $feedback = "🎮 Start frei! Ich habe mir eine neue geheime Zahl zwischen 1 und 100 gemerkt. Rate mit per Button oder tippe: /zahl [DeineZahl]";
            } else if ($tipp_int < $geheimzahl) {
                $feedback = "🎯 Tipp: <strong>" . $tipp_int . "</strong>... Höher! ⬆️ Der Server grinst.";
            } else if ($tipp_int > $geheimzahl) {
                $feedback = "🎯 Tipp: <strong>" . $tipp_int . "</strong>... Tiefer! ⬇️ Du bist zu weit oben.";
            } else {
                $feedback = "🏆🏆 GEWONNEN!! Du hast die Geheimzahl <strong>" . $geheimzahl . "</strong> erraten! Der Chat feiert Dich! 🎉 🥁";
                
                // Aktuellen Gewinner-Eintrag aus der Rate-Tabelle löschen, damit er beim nächsten Mal neu würfelt
                dbquery("DELETE LOW_PRIORITY FROM fusionb7754_chat_hoerer_guess WHERE user_id = " . $user_id_key);
                
                // 🧹 STAUB-SAUGER FÜR DIE RATE-TABELLE (Hält sie schlank)
                $check_limit = dbquery("SELECT last_guess FROM fusionb7754_chat_hoerer_guess ORDER BY last_guess DESC LIMIT 1 OFFSET 50");
                if ($check_limit && dbrows($check_limit) > 0) {
                    $limit_zeitstempel = intval(dbarray($check_limit)['last_guess']);
                    dbquery("DELETE LOW_PRIORITY FROM fusionb7754_chat_hoerer_guess WHERE last_guess <= " . $limit_zeitstempel);
                }
            }

            // Der Spiel-Text, der im Chat ausgegeben wird
            $guess_nachricht = "<span style='color:#00ffaa !important; font-weight:bold !important; font-size:12px !important; margin-left:5px;'>🎮 <strong>" . $sicherer_name . "</strong> tritt zum Zahlen-Duell an... <br>➡️ " . $feedback . "</span>";
            
            // 🔥 REPARIERT: Wir löschen hier KEINE Einträge mehr weg! 
            // Jedes "Höher" und "Tiefer" bleibt als eigenständige Nachricht im Chat stehen.
            dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) 
                     VALUES (777, 'SYSTEM_SOUND', 'WHEEL_WIN:" . addslashes($guess_nachricht) . "', '" . $aktueller_zeitstempel . "')");
            
            // Aktualisiert die Rate-Tabelle, damit der Cooldown von 10 Sekunden zählt
            dbquery("INSERT INTO fusionb7754_chat_hoerer_guess (user_id, secret_number, last_guess) 
                     VALUES (" . $user_id_key . ", " . $geheimzahl . ", $aktueller_zeitstempel) 
                     ON DUPLICATE KEY UPDATE last_guess = $aktueller_zeitstempel");
            
            header('Content-Type: application/json'); echo json_encode(['status' => 'success']); exit;
        }
    }

	// ==============================================

    // --- 1D. DIE MAGISCHE NEON-ORAKEL-KUGEL (SESSION-BLOCKADE-FREI) ---
    if (strpos(strtolower($message), '/orakel ') === 0 || strtolower($message) === '/orakel') {
        $spieler_id = intval($session_userid);
        $aktueller_zeitstempel = time();
        $sicherer_chat_speicher = isset($chat_table) ? $chat_table : "fusionb7754_chat_messages";
        
        $spieler_name = "Hörer";
        if (isset($_REQUEST['admin_auth_name']) && trim($_REQUEST['admin_auth_name']) !== "") { $spieler_name = trim($_REQUEST['admin_auth_name']); }
        elseif (!empty($session_username)) { $spieler_name = trim($session_username); }
        elseif (isset($_SESSION['chat_user_name'])) { $spieler_name = trim($_SESSION['chat_user_name']); }
        
        $cooldown_zeit = 15; 
        $darf_fragen = true; $restzeit_sek = 0;
        
        if (session_status() === PHP_SESSION_NONE) { session_name("RME_RADIO_CHAT_SESSION"); session_start(); }
        
        if (isset($_SESSION['last_oracle_time']) && $spieler_id !== 18) {
            $vergangene_zeit = $aktueller_zeitstempel - intval($_SESSION['last_oracle_time']);
            if ($vergangene_zeit < $cooldown_zeit) { $darf_fragen = false; $restzeit_sek = $cooldown_zeit - $vergangene_zeit; }
        }
        
        // 🎯 RETTUNG: Wir schließen die Session-Datei sofort wieder für andere AJAX-Anfragen!
        session_write_close();
        
        if (!$darf_fragen) {
            $fehler_text = "🔮 [Orakel]: Langsam " . $spieler_name . "! Die magische Kugel lädt ihre Energie auf. Warte " . $restzeit_sek . " Sek.";
            dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (995, 'SYSTEM_SOUND', 'WHEEL_ERROR:" . addslashes($fehler_text) . "', '" . $aktueller_zeitstempel . "')");
        } else {
            $frage = trim(str_replace('/orakel ', '', $message));
            if ($frage === '/orakel' || $frage === "") { $frage = "Wird die Sendung heute geil?"; }
            
            $antworten = [
                "🌟 Die kosmischen Signale sagen: DEFINITIV JA! 🔥",
                "💨 Vergiss es, der Wind hat die Antwort verweht. Nö! ❌",
                "☕ Frag mich nach der nächsten Tasse Kaffee noch mal... 🤫",
                "🎵 Absolut! Sogar der AutoDJ würde dazu tanzen! 🕺",
                "🎧 Wenn der DJ das liest, wird er das lautstark im Stream bejahen! 🎙️",
                "🔮 Die Kugel schüttelt den Kopf. Sieht düster aus! 💀",
                "✨ Die Sterne stehen perfekt für ein fettes JA! 🎉",
                "🤐 Das Orakel schweigt und genießt lieber die Musik! 🎶",
                "💥 Unwahrscheinlich! Ungefähr so treffsicher wie eine Niete am Glücksrad. 😉",
                "👑 Der Chat-Gott hat gesprochen: Es wird genau so passieren!",
				"🚀 Schnall dich an, die Antwort schießt direkt Richtung JA! 🌌",
				"💎 Glasklarer Fall: Das Schicksal sagt absolut JA! 🔮",
				"🍕 So sicher wie Käse auf der Pizza: Auf jeden Fall! 🧀",
				"📈 Die Kurve zeigt steil nach oben. Das wird ein Erfolg! 🚀",
				"🦄 Selbst das letzte Einhorn würde hierzu laut 'JA' rufen! 🦄",
				"🎯 Volltreffer! Genau so und nicht anders wird es passieren! 🎯",
				"☀️ Die Wolken verziehen sich, alles deutet auf ein fettes JA hin! ☀️",
				"🥳 Konfetti frei! Die Zeichen stehen komplett auf Eskalation und JA! 🎉",
				"🔑 Du hast den goldenen Schlüssel erwischt: Es passiert! 🔑",
				"🔋 Mein Akku ist voll und meine Intuition sagt: Definitiv! 🔋",
				"🛑 Haltstop! Die Ampel steht auf tiefstem Rot. Vergiss es! 🛑",
				"❄️ Die Antwort ist kälter als die Arktis: Auf keinen Fall! 🧊",
				"📉 Die Aktien für diese Idee stehen gerade im Keller. Nein! 📉",
				"🌵 Trockener als die Wüste: Die Antwort lautet leider Nein! 🌵",
				"🎭 Netter Versuch, aber das Universum schüttelt heftig den Kopf! ❌",
				"🔌 Stecker gezogen! Diese Leitung ist tot. Das wird nix. 🔌",
				"🕳️ Die Antwort ist im schwarzen Loch verschwunden. Such dir ein Nein aus! 🕳️",
				"🧱 Du rennst hier gegen eine Wand. Spar dir die Energie! 🧱",
				"🎈 Pfff... Da ist die Luft raus. Das ist ein klares Nein! 🎈",
				"🛸 Aliens haben deine positive Antwort entführt. Übrig bleibt ein Nö! 🛸",
				"🌪️ Meine Gedanken wirbeln im Kreis. Frag mich gleich noch mal! 🌀",
				"🎲 Würfel die Frage im Kopf noch mal neu aus. Zu vage! 🎲",
				"⏳ Ladebalken hängt... Bitte warte, bis das Schicksal geladen ist. ⏳",
				"🍃 Die Antwort flattert noch im Wind. Frag später noch mal! 🍃",
				"🗺️ Das Orakel hat sich verlaufen. Komm in fünf Minuten wieder! 🗺️",
				"🌫️ Alles vernebelt hier. Ich kann die Zukunft gerade nicht sehen! 🌫️",
				"💤 Das Orakel macht gerade ein Nickerchen. Bitte nicht stören! 💤",
				"🎭 50% Chance auf Genie, 50% auf Wahnsinn. Entscheide selbst! 🎭",
				"🧩 Da fehlen mir noch ein paar Puzzleteile für eine echte Antwort! 🧩",
				"🛸 Die kosmische Verbindung hat gerade brutale Lags. Moment noch... 🛸",
				"🎸 Der Bass drückt so hart, dass er das 'JA' direkt herbeivibriert! 🔊",
				"🔇 Sorry, die Musik ist zu laut! Ich hab die Frage nicht verstanden! 🎧",
				"🎹 Diese Idee trifft genau den richtigen Ton! Absolut ja! 🎶",
				"💿 Die Antwort läuft in Dauerschleife: Ja, ja und nochmals ja! 💿",
				"🎛️ Der Mixer steht auf Anschlag: Das wird ein absoluter Hit! 🔥",
				"📢 Die Boxen übersteuern vor Freude: Ein lautes, fettes JA! 📢",
				"🎵 Das ist der perfekte Soundtrack für ein garantiertes Gelingen! 🎼",
				"🗣️ Der Mod im Chat hat gerade genickt. Das ist Gesetz! 👑",
				"💃 Selbst der Tanzbereich würde bei dieser Antwort komplett ausrasten! 🕺",
				"📻 Fehler im Stream-Protokoll: Die Antwort wurde wegzensiert! 🤫",
				"🥔 Meine magische Kartoffel sagt: Die Chancen stehen gut! 🥔",
				"🧙‍♂️ Der Zauberstab hat Ladehemmungen. Sieht aber eher nach Nein aus. 🪄",
				"🦖 Selbst die Dinosaurier sind vor dieser Idee ausgestorben. Lass es lieber! 🦖",
				"🦥 Das Orakel bewegt sich im Faultier-Modus. Frag mich morgen! 🦥",
				"👾 Die Gaming-Götter haben gesprochen: Drücke 'F' für ein Nein! 👾",
				"🌮 Erst wenn du mir einen Taco bringst, verrate ich dir das JA! 🌮",
				"🐧 Ein Pinguin hat gerade die Antwort geklaut. Schade Schokolade! 🐧",
				"🦄 Zu 99% Ja, das restliche 1% ist glitzernder Sternenstaub! ✨",
				"🍦 Schmilzt schneller weg als Eis in der Sonne: Die Antwort ist Nein! 🍦",
				"🧠 Mein Gehirn hat gerade den Raum verlassen. Frag den Chatbot! 🤖"
            ];
            
            shuffle($antworten);
            $zufalls_antwort = $antworten[array_rand($antworten)];
            $orakel_nachricht = "<span style='color:#00ffaa !important; font-weight:bold !important; font-size:12px !important; margin-left:5px;'>🔮 <strong>" . htmlspecialchars($spieler_name, ENT_QUOTES, 'UTF-8') . "</strong> befragt die magische Kugel: '" . htmlspecialchars($frage, ENT_QUOTES, 'UTF-8') . "'... <br>➡️ " . $zufalls_antwort . "</span>";
            
            dbquery("DELETE FROM " . $sicherer_chat_speicher . " WHERE user_id = 777 AND guest_name = 'SYSTEM_SOUND'");
            dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (777, 'SYSTEM_SOUND', 'WHEEL_WIN:" . addslashes($orakel_nachricht) . "', '" . $aktueller_zeitstempel . "')");
          
            // Für den Cooldown kurz neu öffnen und wegschreiben
            if (session_status() === PHP_SESSION_NONE) { session_name("RME_RADIO_CHAT_SESSION"); session_start(); }
            $_SESSION['last_oracle_time'] = $aktueller_zeitstempel;
            session_write_close();
        }
        header('Content-Type: application/json'); echo json_encode(['status' => 'success']); 
		// 🔥 UNZERSTÖRBARER DATABASE-CLEANER: Schließt MySQL, bevor das Skript stirbt!
global $mysqli, $db_connect;
if (isset($mysqli) && $mysqli instanceof mysqli) { 
    @mysqli_close($mysqli); 
} elseif (isset($db_connect) && is_resource($db_connect)) { 
    @mysql_close($db_connect); 
}
		exit;
    }

    // --- 1E. DAS DYNAMISCHE HÖHER-TIEFER KARTEN-DUELL (SCHLEIFEN-FREI & APACHE-SCHUTZ) ---
    if (strpos(strtolower($message), '/karte ') === 0) {
        $aktueller_zeitstempel = time();
        $sicherer_chat_speicher = isset($chat_table) ? $chat_table : "fusionb7754_chat_messages";
        
        // 🔥 FIX 1: Holt den Namen krisensicher aus der am Dateianfang geretteten Session
        $spieler_name = !empty($session_username_start) ? trim($session_username_start) : "Hörer";
        $spieler_id = isset($session_userid_start) ? intval($session_userid_start) : 0;
        
        // 🔥 FIX 2: Absolut stabiler ID-Key ohne anfälligen Zufallsgenerator!
        $get_auth_id_backup = isset($_GET['admin_auth_id']) ? intval($_GET['admin_auth_id']) : 0;
        $user_id_key = ($spieler_id > 0) ? $spieler_id : (($get_auth_id_backup > 0) ? $get_auth_id_backup : 999777);
        
        $karten_namen = [2=>"2", 3=>"3", 4=>"4", 5=>"5", 6=>"6", 7=>"7", 8=>"8", 9=>"9", 10=>"10", 11=>"Bube 🫅", 12=>"Dame 👸", 13=>"König 👑", 14=>"Ass 🌟"];
        
        $aktion = trim(str_replace('/karte ', '', strtolower($message)));

        // READ UNCOMMITTED (oben im Backend) schützt diese Abfrage vor dem Handy-Lade-Tod
        $db_search = dbquery("SELECT secret_number FROM fusionb7754_chat_hoerer_guess WHERE user_id = " . $user_id_key . " LIMIT 1");
        if ($db_search && dbrows($db_search) > 0) {
            $alte_karte = intval(dbarray($db_search)['secret_number']);
            if ($alte_karte < 2 || $alte_karte > 14) { $alte_karte = 7; } 
            
            $neue_karte = rand(2, 14);
            
            // 🎯 DEIN EXZELLENTER SCHUTZ: Keine unendliche while-Schleife mehr!
            if ($neue_karte === $alte_karte) {
                $neue_karte = ($neue_karte >= 14) ? 2 : $neue_karte + 1;
            }
            
            $gewonnen = false;
            if ($aktion === "hoeher" && $neue_karte > $alte_karte) { $gewonnen = true; }
            if ($aktion === "tiefer" && $neue_karte < $alte_karte) { $gewonnen = true; }
            
            if ($gewonnen) {
                $ergebnis = "🏆 GEWONNEN!! Die nächste Karte war eine <strong style='color:#ffed00;'>[" . $karten_namen[$neue_karte] . "]</strong>! 🎉🥁";
            } else {
                $ergebnis = "❌ Verloren! Die nächste Karte war eine <strong style='color:#ff3333;'>[" . $karten_namen[$neue_karte] . "]</strong>. ☕";
            }
            
            $aufloesung_text = "<span style='color:#00ffaa !important; font-weight:bold !important; font-size:12px !important; margin-left:5px;'>🃏 <strong>" . htmlspecialchars($spieler_name, ENT_QUOTES, 'UTF-8') . "s</strong> Karten-Duell Auflösung:<br>➡️ Deine Karte war: <strong style='color:#fff;'>[" . $karten_namen[$alte_karte] . "]</strong> <br>➡️ Die nächste Karte war: <strong style='color:#fff;'>[" . $karten_namen[$neue_karte] . "]</strong><br>➡️ Ergebnis: " . $ergebnis . "</span>";
            
            // 🔥 FIX 3: LOW_PRIORITY fegt Tabellensperren weg, damit das Handy flüssig durchzieht!
            dbquery("DELETE LOW_PRIORITY FROM " . $sicherer_chat_speicher . " WHERE user_id = 777 AND guest_name = 'SYSTEM_SOUND'");
            dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (777, 'SYSTEM_SOUND', 'WHEEL_WIN:" . addslashes($aufloesung_text) . "', '" . $aktueller_zeitstempel . "')");
            dbquery("DELETE LOW_PRIORITY FROM fusionb7754_chat_hoerer_guess WHERE user_id = " . $user_id_key);
            
            header('Content-Type: application/json'); 
            echo json_encode(['status' => 'success']); 
// 🔥 UNZERSTÖRBARER DATABASE-CLEANER: Schließt MySQL, bevor das Skript stirbt!
global $mysqli, $db_connect;
if (isset($mysqli) && $mysqli instanceof mysqli) { 
    @mysqli_close($mysqli); 
} elseif (isset($db_connect) && is_resource($db_connect)) { 
    @mysql_close($db_connect); 
}
            exit;
        }
    }
	// =========================================================

// --- 1F. DAS AUTOMATISCHE BLITZ-QUIZ (MIT 15-SEKUNDEN AUTO-EXPIRE) ---
    if (strpos(strtolower($message), '/lösung ') === 0) {
        $aktueller_zeitstempel = time();
        $sicherer_chat_speicher = isset($chat_table) ? $chat_table : "fusionb7754_chat_messages";
        
        $spieler_name = "Hörer";
        if (!empty($session_username_start)) { $spieler_name = trim($session_username_start); }
        elseif (isset($_SESSION['user_name'])) { $spieler_name = trim($_SESSION['user_name']); }
        elseif (isset($userdata['user_name'])) { $spieler_name = trim($userdata['user_name']); }
        
        $spieler_id = isset($session_userid_start) ? intval($session_userid_start) : (isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : 0);
        $get_auth_id_backup = isset($_GET['admin_auth_id']) ? intval($_GET['admin_auth_id']) : 0;
        $user_id_key = ($spieler_id > 0) ? $spieler_id : (($get_auth_id_backup > 0) ? $get_auth_id_backup : 999777);
        
        $tipp_antwort = trim(str_replace('/lösung ', '', strtolower($message)));
        $quiz_check = dbquery("SELECT * FROM fusionb7754_chat_quiz WHERE status = 1 LIMIT 1");
        
        if ($quiz_check && dbrows($quiz_check) > 0) {
            $aktive_frage = dbarray($quiz_check);
            $gesuchte_antwort = trim(strtolower($aktive_frage['antwort']));
            
            if ($tipp_antwort === $gesuchte_antwort) {
                $gewonnene_punkte = intval($aktive_frage['punkte']);
                $frage_id = intval($aktive_frage['id']);
                
                // 1. Punkte verbuchen
                dbquery("INSERT INTO fusionb7754_chat_quiz_punkte (user_id, username, punkte) 
                         VALUES (" . $user_id_key . ", '" . addslashes($spieler_name) . "', " . $gewonnene_punkte . ") 
                         ON DUPLICATE KEY UPDATE punkte = punkte + " . $gewonnene_punkte . ", username = '" . addslashes($spieler_name) . "'");
                
                // 2. Frage in der DB schließen und JETZT die last_triggered Zeit für den Schredder festnageln!
                dbquery("UPDATE fusionb7754_chat_quiz SET status = 0, last_triggered = " . $aktueller_zeitstempel . " WHERE id = " . $frage_id);
                
                // 3. Gewinn-Nachricht zusammenbauen
                $bot_msg_text = "<div style='background: rgba(0, 255, 170, 0.1); border-left: 4px solid #00ffaa; padding: 10px; margin: 5px 0; border-radius: 6px; font-family: Arial, sans-serif; color: #fff;'><strong style='color: #00ffaa; font-size: 13px;'>🤖 QuizBot verkündet:</strong><br>🎉 Richtig! <strong>" . htmlspecialchars($spieler_name, ENT_QUOTES, 'UTF-8') . "</strong> hat das Rätsel gelöst!<br>➡️ Die Antwort war: <strong style='color: #ffed00;'>[" . htmlspecialchars($aktive_frage['antwort'], ENT_QUOTES, 'UTF-8') . "]</strong><br>🏆 Gewinn: <strong style='color: #00ffff;'>" . $gewonnene_punkte . " Quiz-Punkte</strong> wurden verbucht! 🥁</div>";
                
                // 4. Gewinnnachricht in die Tabelle schreiben (mit echter aktueller Zeit)
                dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (889, 'QuizBot 🤖', '" . addslashes($bot_msg_text) . "', '" . $aktueller_zeitstempel . "')");
                
                // 5. 🚀 DER FRONTEND-RETTER: Kein JSON senden! Wir lassen das Skript einfach weiterlaufen,
                // damit es wie bei einer normalen Chat-Nachricht das erwartete HTML ausgibt. Kein Hänger mehr!
                
            } else {
                // Falschmeldung in den Chat schreiben
                $falsch_text = "<div style='background: rgba(255, 51, 51, 0.08); border-left: 4px solid #ff3333; padding: 6px; margin: 3px 0; border-radius: 4px; font-family: Arial; color: #fff;'>❌ Leider falsch! <strong>" . htmlspecialchars($spieler_name, ENT_QUOTES, 'UTF-8') . "</strong>, deine Antwort stimmt leider nicht. Rate weiter! ☕</div>";
                
                dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (889, 'QuizBot 🤖', '" . addslashes($falsch_text) . "', '" . $aktueller_zeitstempel . "')");
                
                // Auch hier lassen wir das Skript geschmeidig weiterlaufen
            }
        }
    }

// =========================================================================
// 🤖 VOLLAUTOMATISCHER QUIZ-TAKTEBER (KORRIGIERTER SCHREDDER)
// =========================================================================
$aktueller_zeitstempel = time();
$sicherer_chat_speicher = isset($chat_table) ? $chat_table : "fusionb7754_chat_messages";

// 1. Prüfen, ob aktuell bereits ein Quiz aktiv ist (status = 1)
$quiz_check_aktiv = dbquery("SELECT id FROM fusionb7754_chat_quiz WHERE status = 1 LIMIT 1");

if ($quiz_check_aktiv && dbrows($quiz_check_aktiv) > 0) {
    // Wenn eine Frage aktiv ist, pinne sie bombenfest ganz unten fest
    dbquery("UPDATE " . $sicherer_chat_speicher . " 
             SET datestamp = " . $aktueller_zeitstempel . " 
             WHERE user_id = 888");

} else {
    // Es läuft aktuell kein Quiz (status = 0) -> Jemand hat gewonnen!
    
    // 🔥 DER ABSOLUT PREZISE SCHREDDER-TIMER:
    // Wir schauen nach, wann die Gewinn-Nachricht (889) in den Chat geschrieben wurde
    $check_gewinn_zeit = dbquery("SELECT datestamp FROM " . $sicherer_chat_speicher . " WHERE user_id = 889 ORDER BY datestamp DESC LIMIT 1");
    
    if ($check_gewinn_zeit && dbrows($check_gewinn_zeit) > 0) {
        $gewinn_row = dbarray($check_gewinn_zeit);
        $alter_der_nachricht = $aktueller_zeitstempel - intval($gewinn_row['datestamp']);
        
        // Erst wenn die Gewinnverkündung EXAKT 15 Sekunden lang für alle sichtbar war, wird gelöscht!
        if ($alter_der_nachricht >= 15) {
            dbquery("DELETE FROM " . $sicherer_chat_speicher . " WHERE user_id IN (888, 889)");
        }
    } else {
        // Falls aus irgendeinem Grund keine 889 da ist, aber eine alte Leiche von 888 existiert, fegen wir sie weg
        dbquery("DELETE FROM " . $sicherer_chat_speicher . " WHERE user_id = 888 AND " . $aktueller_zeitstempel . " - datestamp >= 15");
    }

    // Zeitstempel für die 15-Minuten-Pause (900 Sekunden) holen
    $check_zeit = dbquery("SELECT last_triggered FROM fusionb7754_chat_quiz ORDER BY last_triggered DESC LIMIT 1");
    $letzter_start = 0;
    if ($check_zeit && dbrows($check_zeit) > 0) {
        $letzter_start = intval(dbarray($check_zeit)['last_triggered']);
    }

    // Wenn die 15 Minuten Pause (900 Sekunden) rum sind, würfeln wir die nächste Frage
    if (($aktueller_zeitstempel - $letzter_start) >= 900) {
        // Zur Sicherheit noch mal Reste fegen
        dbquery("DELETE FROM " . $sicherer_chat_speicher . " WHERE user_id IN (888, 889)");

        $zufalls_frage_query = dbquery("SELECT * FROM fusionb7754_chat_quiz WHERE status = 0 ORDER BY RAND() LIMIT 1");
        
        if ($zufalls_frage_query && dbrows($zufalls_frage_query) > 0) {
            $neue_frage = dbarray($zufalls_frage_query);
            $neue_frage_id = intval($neue_frage['id']);
            
            dbquery("UPDATE fusionb7754_chat_quiz SET status = 1, last_triggered = " . $aktueller_zeitstempel . " WHERE id = " . $neue_frage_id);
            
            $quiz_bot_text = "<div style='background: rgba(211, 84, 0, 0.15); border-left: 4px solid #ffaa00; padding: 10px; margin: 5px 0; border-radius: 6px; font-family: Arial, sans-serif; color: #fff;'><strong style='color: #ffaa00; font-size: 13px;'>🤖 QuizBot startet ein automatisches Blitz-Quiz:</strong><br><span style='font-size: 14px; font-weight: bold; display: block; margin: 5px 0;'>❓ Frage: " . htmlspecialchars($neue_frage['frage'], ENT_QUOTES, 'UTF-8') . "</span>💰 Zu gewinnen: <strong style='color: #00ffaa;'>" . $neue_frage['punkte'] . " Quiz-Punkte</strong>!<br><span style='color: #00ffff; font-size: 11px; font-weight: bold;'>➡️ Löse das Rätsel mit: <strong style='color:#fff;'>/lösung [deine Antwort]</strong></span></div>";
            
            dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) VALUES (888, 'QuizBot 🤖', '" . addslashes($quiz_bot_text) . "', '" . $aktueller_zeitstempel . "')");
        }
    }
}
// =========================================================================

     // =========================================================================
    // 🔥 LIVE OS-TEXT-BRENNER (FIXED: KEINE FALSCHE WIN 11 ERKENNUNG MEHR)
    // =========================================================================
    $agent_spur = isset($_SERVER['HTTP_USER_AGENT']) ? strtolower($_SERVER['HTTP_USER_AGENT']) : 'unknown';
    $mein_os_shortcode = "[os:win]"; // Standard für Windows PC
    
    if (strpos($agent_spur, 'android') !== false) { 
        $mein_os_shortcode = "[os:and]"; 
    } elseif (strpos($agent_spur, 'iphone') !== false || strpos($agent_spur, 'ipad') !== false) { 
        $mein_os_shortcode = "[os:ios]"; 
    } elseif (strpos($agent_spur, 'linux') !== false) { 
        $mein_os_shortcode = "[os:lin]"; 
    } elseif (strpos($agent_spur, 'macintosh') !== false || strpos($agent_spur, 'mac os') !== false) { 
        $mein_os_shortcode = "[os:mac]"; 
    }
    
    if (!empty($message)) {
        $message = $message . " " . $mein_os_shortcode;
    }
    // =========================================================================   

    // =========================================================================
    // 🔥 KORREKTUR: DJ-STYLE-SICHERE FLÜSTER-SCHRANKE (RETTET DEINE PRIVATSPHÄRE!)
    // =========================================================================
    $url_joker_empfaenger = isset($_GET['rme_whisper_switch']) ? trim(strip_tags((string)$_GET['rme_whisper_switch'])) : '';
    if (empty($url_joker_empfaenger) && isset($_POST['rme_whisper_switch'])) {
        $url_joker_empfaenger = trim(strip_tags((string)$_POST['rme_whisper_switch']));
    }

    if (!empty($url_joker_empfaenger)) {
        // Wir putzen alte, falsch platzierte /w Befehle aus dem Text heraus
        $message = preg_replace('/\/w\s+[a-zA-Z0-9_\-]+\s*/i', '', $message);
        
        // 🎯 DER MASTER-TRICK: Wir prüfen, ob die Nachricht mit einem DJ-Style beginnt (z.B. [style=...])
        if (preg_match('/^(\[style=[^\]]+\])(.*)/i', $message, $style_treffer)) {
            $style_anfang = $style_treffer[1]; // Das öffnende Style-Tag
            $rest_nachricht = $style_treffer[2]; // Der eigentliche Text mitsamt OS-Code
            
            // Wir betten das /w Name DIREKT HINTER dem öffnenden Style-Tag ein!
            // Das sorgt dafür, dass die BB-Code-Engine es beim Auslesen perfekt versteht!
            $message = $style_anfang . "/w " . $url_joker_empfaenger . " " . $rest_nachricht;
        } else {
            // Falls kein DJ-Style aktiv ist, kommt das /w wie gewohnt ganz normal nach vorne
            $message = "/w " . $url_joker_empfaenger . " " . $message;
        }
    }
    // =========================================================================
    
    if (!empty($message)) {
        $jetzt_time = time();

        
        $ajax_get_name = isset($_GET['admin_auth_name']) ? trim(strip_tags((string)$_GET['admin_auth_name'])) : '';
        $ajax_get_id = isset($_GET['admin_auth_id']) ? intval($_GET['admin_auth_id']) : 0;
        
        $aktueller_name_check = strtolower(trim($session_username));
        $ajax_name_check = strtolower(trim($ajax_get_name));
        
        if ($aktueller_name_check === "dj-tomjac" || $ajax_name_check === "dj-tomjac" || strpos($aktueller_name_check, 'tomjac') !== false) {
            $sichere_speicher_id = 18; 
            $final_guest_name = "DJ-Tomjac";
            $guest_name_clean = "NULL";
        } else {
            $online_suche = dbquery("SELECT user_id, username FROM ".$online_table." WHERE session_id='".addslashes($meine_aktuelle_session_id)."' LIMIT 1");
            
            if ((!$online_suche || dbrows($online_suche) == 0) && !empty($ajax_get_name)) {
                $online_suche = dbquery("SELECT user_id, username FROM ".$online_table." WHERE username='".addslashes($ajax_get_name)."' OR username='".addslashes($ajax_get_name)."_CU' LIMIT 1");
            }
            
            if ($online_suche && dbrows($online_suche) > 0) {
                $on_row = dbarray($online_suche);
                $final_guest_name = trim((string)$on_row['username']);
                $sichere_speicher_id = intval($on_row['user_id']);
            } else {
                if (!empty($ajax_get_name)) {
                    if (strpos(strtolower($ajax_get_name), 'gast') === false) {
                        $final_guest_name = $ajax_get_name . "_CU";
                        $sichere_speicher_id = ($ajax_get_id > 0) ? $ajax_get_id : 1000;
                    } else {
                        $final_guest_name = $ajax_get_name;
                        $sichere_speicher_id = ($ajax_get_id > 0) ? $ajax_get_id : rand(2000, 2699);
                    }
                } else {
                    $sichere_speicher_id = ($session_userid >= 2000) ? $session_userid : rand(2000, 2699);
                    $final_guest_name = !empty($session_username) ? trim($session_username) : "Gast_" . $sichere_speicher_id;
                }
                
                dbquery("INSERT IGNORE INTO ".$online_table." (user_id, username, session_id, last_active, last_written, is_afk) 
                         VALUES ('".$sichere_speicher_id."', '".addslashes($final_guest_name)."', '".addslashes($meine_aktuelle_session_id)."', '".$jetzt_time."', '".$jetzt_time."', 0)");
            }
 // Zwingt die PHP-Verbindung dazu, echte 4-Byte Emojis durchzulassen
dbquery("SET NAMES 'utf8mb4'");

// 🔥 MASTER-FIX GEGEN TABELLEN-SPERRUNG:
// Erlaubt es dem Handy und dem PC, die Chat-Nachrichten sofort zu lesen,
// selbst wenn die andere Verbindung die Online-Tabelle gerade blockiert!
dbquery("SET SESSION TRANSACTION ISOLATION LEVEL READ UNCOMMITTED");
           
            // =========================================================================
            // REPARATUR-KERN: REINIGUNG DER SENDENAMEN FÜR CHAT-USER (_CU)
            // =========================================================================
            $sende_name_aus_sitzung = '';
            if (isset($_SESSION['chat_user_name'])) {
                $sende_name_aus_sitzung = trim($_SESSION['chat_user_name']);
            } elseif (isset($_SESSION['rme_chat_guest_name'])) {
                $sende_name_aus_sitzung = trim($_SESSION['rme_chat_guest_name']);
            }

            if (empty($sende_name_aus_sitzung)) {
                $sende_name_aus_sitzung = !empty($safe_chat_user_name_db) ? $safe_chat_user_name_db : 'Hörer';
            }

            if ($sichere_speicher_id > 0 && $sichere_speicher_id < 100 && strpos(strtolower($sende_name_aus_sitzung), 'gast') === false) {
                $guest_name_clean = "NULL";
            } else {
                $guest_name_clean = "'" . addslashes($sende_name_aus_sitzung) . "'";
            }
        }

        // =========================================================================
        // 🪟 🌐 MAXIMAL-FORENSIK: DETEKTOR FÜR SEPARATE SPALTEN (SENDE-CORE)
        // =========================================================================
        $uaString = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Kein User Agent';
        $agent_klein = strtolower($uaString);
        
        $p_browser = "Unbekannter Browser";
        $p_os      = "Unbekanntes OS";
        $p_device  = "💻";

        // A. Architektur heraustrennen
        $arch = "";
        if (preg_match('/x86_64|win64|x64|wow64|arm64/i', $agent_klein)) { $arch = " (64-Bit)"; } 
        elseif (preg_match('/i686|i386|win32|x86/i', $agent_klein)) { $arch = " (32-Bit)"; }

        // B. Das exakte Betriebssystem (OS) bestimmen
        if (strpos($agent_klein, 'windows nt 10.0') !== false) { $p_os = 'Windows 10/11' . $arch; }
        elseif (strpos($agent_klein, 'windows nt 6.3') !== false) { $p_os = 'Windows 8.1' . $arch; }
        elseif (strpos($agent_klein, 'windows nt 6.2') !== false) { $p_os = 'Windows 8' . $arch; }
        elseif (strpos($agent_klein, 'windows nt 6.1') !== false) { $p_os = 'Windows 7' . $arch; }
        elseif (strpos($agent_klein, 'macintosh') !== false || strpos($agent_klein, 'mac os x') !== false) { $p_os = 'Mac OS X'; }
        elseif (strpos($agent_klein, 'android') !== false) { $p_os = 'Android'; }
        elseif (strpos($agent_klein, 'iphone') !== false || strpos($agent_klein, 'ipad') !== false) { $p_os = 'iOS'; }
        elseif (strpos($agent_klein, 'linux') !== false) { $p_os = 'Linux' . $arch; }

        // C. Den exakten Browser bestimmen
        if (strpos($agent_klein, 'edg/') !== false || strpos($agent_klein, 'edge/') !== false) { $p_browser = 'Microsoft Edge'; }
        elseif (strpos($agent_klein, 'chrome/') !== false) { $p_browser = 'Google Chrome'; }
        elseif (strpos($agent_klein, 'firefox/') !== false) { $p_browser = 'Mozilla Firefox'; }
        elseif (strpos($agent_klein, 'safari/') !== false && strpos($agent_klein, 'chrome/') === false) { $p_browser = 'Apple Safari'; }
        elseif (strpos($agent_klein, 'opera/') !== false || strpos($agent_klein, 'opr/') !== false) { $p_browser = 'Opera'; }

        // D. Den Gerätetyp bestimmen
        if (strpos($agent_klein, 'tablet') !== false || strpos($agent_klein, 'ipad') !== false) { $p_device = '📱'; }
        elseif (strpos($agent_klein, 'mobile') !== false || strpos($agent_klein, 'iphone') !== false || strpos($agent_klein, 'android') !== false) { $p_device = '📱'; }

        // 🔥 FIX: Einheitlicher Variablenname für den kombinierten String!
        $finaler_user_agent_eintrag = $p_os . " (" . $p_browser . ") " . $p_device;

        // E. Kurzcode für das Text-Archiv anhängen
        $kurz_code = "[os:win]";
        if (strpos($agent_klein, 'android') !== false) { $kurz_code = "[os:and]"; }
        elseif (strpos($agent_klein, 'iphone') !== false || strpos($agent_klein, 'ipad') !== false) { $kurz_code = "[os:ios]"; }
        elseif (strpos($agent_klein, 'linux') !== false) { $kurz_code = "[os:lin]"; }
        
        if (!empty($message) && strpos($message, '[os:') === false) {
            $message = $message . " " . $kurz_code;
        }
if (strpos($message, '[zapp]') !== false) {
    dbquery("DELETE FROM " . $chat_table . " WHERE user_id=995 OR message='SOUND:zapper.mp3'");
    dbquery("INSERT INTO " . $chat_table . " (user_id, guest_name, message, datestamp) VALUES (995, 'SYSTEM_SOUND', 'SOUND:zapper.mp3', '" . $jetzt_time . "')");
}

// =========================================================================
// 🎆 SCHRITT 2: DYNAMISCHE SYSTEM-TRENNUNG FÜR MODERATOREN & ADMIINS
// =========================================================================
if (strpos($message, '/firework_command_trigger') !== false) {
    $fw_tabelle = "fusionb7754_chat_feuerwerk";
    
    // Wir ermitteln dynamisch die ID und den Namen des Senders (Admin oder Moderator)
    $dynamische_dj_id = isset($sichere_digital_id) && $sichere_digital_id > 0 ? $sichere_digital_id : 18;
    $dynamischer_dj_name = !empty($finaler_sitzungs_name) ? $finaler_sitzungs_name : 'DJ-Tomjac';
    
    // 1. Putzt alte Reste aus Deiner echten Feuerwerks-Tabelle
    dbquery("DELETE FROM " . $fw_tabelle . " WHERE style_type LIKE '%/firework_command_trigger%'");
    
    // 2. Schreibt das Signal DYNAMISCH mit den echten Daten des auslösenden DJs/Mods!
    dbquery("INSERT INTO " . $fw_tabelle . " (dj_id, username, style_type, triggered_at) 
             VALUES (" . intval($dynamische_dj_id) . ", '" . addslashes($dynamischer_dj_name) . "', '/firework_command_trigger', '" . time() . "')");
             
    // 3. Wir leeren die Nachricht für den normalen Chat-Verlauf komplett!
    $message = ""; 
} else {
    // Nur normale Nachrichten landen im echten Chatverlauf!
    dbquery("INSERT INTO " . $chat_table . " (user_id, guest_name, message, datestamp) 
             VALUES (" . intval($sichere_speicher_id) . ", " . $guest_name_clean . ", '" . addslashes($message) . "', '" . $jetzt_time . "')");
}
// =========================================================================




        // =========================================================================
        // 🔒 ULTIMATIVE NOTBREMSE: Verhindert die ID 0 für alle Zeiten!
        // =========================================================================
        $sichere_digital_id = intval($sichere_speicher_id ?? 0);
        $finaler_sitzungs_name = !empty($final_guest_name) ? $final_guest_name : "Gast";
        $pruef_name_low = strtolower(trim($finaler_sitzungs_name));

        // 1. CHEF-SCHUTZ
        if ($pruef_name_low === 'dj-tomjac' || $pruef_name_low === 'tomjac') {
            $sichere_digital_id = 18;
        }
        // 2. CHAT-USER SCHUTZ: Wenn ein _CU im Namen steckt, DARF die ID niemals 0 oder kleiner sein!
        elseif (strpos($pruef_name_low, '_cu') !== false || $pruef_name_low === 'hammerhai66') {
            if ($sichere_digital_id <= 0) {
                // Blitz-Abfrage in der Gästetabelle, um die echte 1000/1001 zu holen
                $db_rettung = dbquery("SELECT id FROM fusionb7754_chat_guest_accounts WHERE guest_name='" . addslashes($finaler_sitzungs_name) . "' LIMIT 1");
                if ($db_rettung && dbrows($db_rettung) > 0) {
                    $sichere_digital_id = intval(dbarray($db_rettung)['id']);
                } else {
                    $sichere_digital_id = 1000; // Unzerstörbarer Fallback-Anker
                }
            }
        }
        // 3. GAST-SCHUTZ: Falls ein normaler Gast mal auf 0 rutschen will
        elseif ($sichere_digital_id <= 0) {
            $sichere_digital_id = rand(2000, 2699);
        }

        // 🔥 JETZT SCHREIBEN WIR ES IN DIE ONLINE-TABELLE:
        // Wir aktualisieren user_id JEDES MAL im UPDATE-Teil! Das bügelt eine alte 0 sofort eiskalt weg!
        dbquery("INSERT INTO " . $online_table . " (user_id, username, session_id, last_active, last_written, is_afk, user_agent, user_os, user_browser, user_device) 
                 VALUES ('" . $sichere_digital_id . "', '" . addslashes($finaler_sitzungs_name) . "', '" . addslashes($meine_aktuelle_session_id) . "', '" . $jetzt_time . "', '" . $jetzt_time . "', 0, '" . addslashes($finaler_user_agent_eintrag) . "', '" . addslashes($p_os) . "', '" . addslashes($p_browser) . "', '" . addslashes($p_device) . "') 
                 ON DUPLICATE KEY UPDATE user_id='" . $sichere_digital_id . "', last_active='" . $jetzt_time . "', last_written='" . $jetzt_time . "', is_afk=0, user_agent='" . addslashes($finaler_user_agent_eintrag) . "', user_os='" . addslashes($p_os) . "', user_browser='" . addslashes($p_browser) . "', user_device='" . addslashes($p_device) . "'");
        // =========================================================================


        echo "success";
        exit;
    }
    echo "success"; 
// 🔥 UNZERSTÖRBARER DATABASE-CLEANER: Schließt MySQL, bevor das Skript stirbt!
global $mysqli, $db_connect;
if (isset($mysqli) && $mysqli instanceof mysqli) { 
    @mysqli_close($mysqli); 
} elseif (isset($db_connect) && is_resource($db_connect)) { 
    @mysql_close($db_connect); 
}
    exit;
}

// =========================================================================
// AUTOMATISCHES AFK- & AUTO-KICK-SYSTEM (EISERNE NOTBREMSE - REPARIERT)
// =========================================================================
$jetzt_zeit = time();

// HINWEIS: Punkt 1 (Die künstliche Chef-Befreiung) wurde gelöscht! 
// Dadurch wirst du nach 5 Minuten ganz normal für alle als [AFK] angezeigt.

// 2. AFK PHASE 1: Wer 5 Minuten stumm ist, wird [AFK] (Status 1)
// REPARIERT: Hier darf dein Name NICHT blockiert werden, damit das [AFK] bei dir anspringt!
dbquery("UPDATE " . $online_table . " SET is_afk = 1 WHERE (" . $jetzt_zeit . " - last_written) > 300 AND is_afk = 0 LIMIT 5");

// 3. RETTUNG: Wer schreibt, springt sofort zurück auf 0
dbquery("UPDATE " . $online_table . " SET is_afk = 0 WHERE (" . $jetzt_zeit . " - last_written) <= 300 AND is_afk = 1 LIMIT 10");

// 4. AFK PHASE 2 (KICK): Wer 20 Minuten stumm ist, fliegt ins Banner (Status 2)
// EISERNER SCHUTZ: Dein Name 'dj-tomjac' steht hier als Ausnahme drin. Du wirst NIEMALS gekickt!
$kick_grenze = $jetzt_zeit - 1200;
dbquery("UPDATE " . $online_table . " SET is_afk = 2 WHERE last_written < " . intval($kick_grenze) . " AND is_afk IN (0,1) AND user_id >= 150 AND LOWER(username) != 'dj-tomjac' LIMIT 5");

// 5. SICHERHEITS-NETZ: Löscht hängende Geister nach insgesamt 25 Minuten
// Auch hier bist du als Ausnahme felsenfest geschützt!
$geist_grenze = $jetzt_zeit - 1500;
dbquery("DELETE FROM " . $online_table . " WHERE last_active < " . intval($geist_grenze) . " AND user_id >= 150 AND LOWER(username) != 'dj-tomjac' LIMIT 5");
// =========================================================================

// -----------------------------------------------------------------
// AKTION: ONLINELISTE AUSGEBEN (STARTBEREICH - REPARIERT)
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "online_list") {
    
    // 🌟 DIE MOBIL-RETTUNG FÜR DIE USERLISTE:
    if (session_status() == PHP_SESSION_NONE) {
        session_name("RME_RADIO_CHAT_SESSION");
        @session_start();
    }
    $mein_session_name_online = $_SESSION['rme_chat_guest_name'] ?? ($_SESSION['chat_user_name'] ?? '');
    $mein_session_id_online = isset($_SESSION['chat_user_id']) ? intval($_SESSION['chat_user_id']) : 0;
    
    // 🎯 HIER IST DER TRICK: Wir merken uns die Session-ID, BEVOR wir zuschließen!
    $meine_aktuelle_session = session_id(); 
    
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // Gibt die Handy-Leitung augenblicklich frei!
    }
    // =========================================================================

    // 🛠️ KALTSTART-SCHUTZ: Verhindert den Absturz bei leerer Datenbank
    if (!isset($sound_dateien) || !is_array($sound_dateien)) { $sound_dateien = array(); }
    if (!isset($sound_typ)) { $sound_typ = ""; }
    if (!isset($signal_text)) { $signal_text = ""; }

    // 🔥 DIE ALTE ZEILE GEKILLT: Da $meine_aktuelle_session jetzt oben schon befüllt wurde,
    // bleibt der Wert stabil und wird hier nicht mehr gelöscht!
    
    $aktuell_sendender_dj = isset($_GET['live_stream_dj']) ? trim((string)$_GET['live_stream_dj']) : 'AutoDJ';
    $aktuell_sendender_dj_low = strtolower($aktuell_sendender_dj);

    
    // =========================================================================
    // SICHERE ZEIT-ERMITTLUNG FÜR DEUTSCHE ZEIT (ON AIR SENSOR)
    // =========================================================================
    date_default_timezone_set('Europe/Berlin');

    // Übersetzer für deutsche Wochentage
    $wochentage_deutsch = [
        "Sunday" => "Sonntag", "Monday" => "Montag", "Tuesday" => "Dienstag", 
        "Wednesday" => "Mittwoch", "Thursday" => "Donnerstag", "Friday" => "Freitag", "Saturday" => "Samstag"
    ];
    $englischer_tag = date("l"); 
    $aktueller_wochentag_name = isset($wochentage_deutsch[$englischer_tag]) ? $wochentage_deutsch[$englischer_tag] : $englischer_tag;

    $jetzt_sekunden = time(); 
    $heutiger_wochentag_index = (int)date("w"); // 0=So, 1=Mo...
    $aktuelle_stunde = (int)date("G"); 
    // =========================================================================
    
    $echter_db_day_wert = null;
    $sendeplan_dj_name_gefunden = "AutoDJ";
    $sendeplan_user_id = 0;
    $stunden_eintrag = "LEER";
    
    $sendeplan_table_real = "fusionb7754_sendeplan"; 
     
    
    // REPARIERT: Sucht exakt nach dem heutigen Kalendertag in der Datenbank (Verhindert Samstags-Leichen)
    $heutiges_datum_db = date("Y-m-d", $jetzt_sekunden);
    $sendeplan_query = @dbquery("SELECT * FROM ".$sendeplan_table_real." WHERE FROM_UNIXTIME(day, '%Y-%m-%d') = '".$heutiges_datum_db."' LIMIT 1");    
    
    if ($sendeplan_query && @dbrows($sendeplan_query) > 0) {
        $sp_row = dbarray($sendeplan_query);
        
        // Speichert den echten Timestamp für die Diagnose ab
        if (isset($sp_row['day'])) {
            $echter_db_day_wert = intval($sp_row['day']);
        }
        
        // Schaut PUNKTGENAU nur in die Spalte der AKTUELLEN STUNDE (z.B. Spalte '10')!
        if (isset($sp_row[$aktuelle_stunde])) {
            $stunden_eintrag = trim((string)$sp_row[$aktuelle_stunde]);
        }
        
        // Wenn in der aktuellen Stunde ein Eintrag mit Punkt existiert (z.B. "18.5")
        if (!empty($stunden_eintrag) && strpos($stunden_eintrag, '.') !== false) {
            $teile = explode('.', $stunden_eintrag);
            
            // REPARIERT: Holt felsenfest das ERSTE Element [0] aus dem Array vor dem Punkt!
            $sendeplan_user_id = isset($teile[0]) ? intval($teile[0]) : 0; 
            
            // Wenn eine gültige User-ID gefunden wurde, holen wir den echten Namen aus der Homepage-Tabelle
            if ($sendeplan_user_id > 0) {
                $user_lookup = @dbquery("SELECT user_name FROM ".DB_PREFIX."users WHERE user_id='".$sendeplan_user_id."' LIMIT 1");
                if ($user_lookup && @dbrows($user_lookup) > 0) {
                    $u_row = dbarray($user_lookup);
                    $sendeplan_dj_name_gefunden = trim((string)$u_row['user_name']); 
                }
            }
        }
    }
    
    // Fallback-Sicherheitsnetz über die Iframe-URL
    $url_dj_check = isset($_GET['live_stream_dj']) ? strtolower(trim((string)$_GET['live_stream_dj'])) : '';
    if (!empty($url_dj_check) && $url_dj_check !== "autodj" && $url_dj_check !== "undefined") {
        $finaler_live_dj_sauber = $url_dj_check;
    } else {
        $finaler_live_dj_sauber = strtolower(trim($sendeplan_dj_name_gefunden));
    }
    // =========================================================================

    // REPARIERT: SCHEF-DIAGNOSE FÜR MATRIX-WOCHENTAG (NUR FÜR DICH SICHTBAR!)
    $session_check_name_low = isset($_SESSION['chat_user_name']) ? strtolower(trim($_SESSION['chat_user_name'])) : '';
    $guest_check_name_low = isset($_SESSION['rme_chat_guest_name']) ? strtolower(trim($_SESSION['rme_chat_guest_name'])) : '';

    if (defined('iADMIN') && iADMIN || 
        $session_check_name_low === 'dj-tomjac' || 
        $guest_check_name_low === 'dj-tomjac') {
        
        // Lokale Übersetzung für das Spywindow
        $spy_wochentage = ["Sunday" => "Sonntag", "Monday" => "Montag", "Tuesday" => "Dienstag", "Wednesday" => "Mittwoch", "Thursday" => "Donnerstag", "Friday" => "Freitag", "Saturday" => "Samstag"];
        $englischer_spy_tag = isset($echter_db_day_wert) ? date("l", $echter_db_day_wert) : '---';
        $deutscher_spy_tag = isset($spy_wochentage[$englischer_spy_tag]) ? $spy_wochentage[$englischer_spy_tag] : $englischer_spy_tag;
/*
        echo "<div style='color:#ffcc00; background:#111; border:1px solid #333; padding:6px; font-size:12px; margin-bottom:10px; border-radius:4px; font-family:monospace; white-space:pre-line; text-align:left;'>
        [Chef-Sendeplan-Spionage - Matrix-Wochentag 3.3]
        Gesuchte Spalte (Aktuelle Stunde): ".$aktuelle_stunde."
        Heutiger Wochentag-Index (0=So, 5=Fr): ".$heutiger_wochentag_index."
        Geladener DB-Tag-Timestamp: '".($echter_db_day_wert ?? 'LEER')."' (" .$deutscher_spy_tag. ")
        Gefundener Spalten-Wert der Stunde: '".$stunden_eintrag."'
        Ausgelesene DJ-User-ID: '".$sendeplan_user_id."'
        Im Sendeplan ermittelter Name: '".$sendeplan_dj_name_gefunden."'
        Verglichener Suchname (klein): '".$finaler_live_dj_sauber."'
        </div>";*/
    }

// =========================================================================
// ONLINELISTER UPDATE: SCHREIBZEIT-SABOTAGE BEHOBEN & NEU-LOGIN AFK-SCHUTZ
// =========================================================================
// 🔥 REPARIERT: Unterstrich eingefügt ($ich_bin_leitung), kein Leerzeichen mehr!
$ich_bin_leitung = (isset($ist_leitung_safe) && $ist_leitung_safe === true) || (isset($is_admin) && $is_admin);
$ich_bin_moderator_check = (isset($ist_moderator_safe) && $ist_moderator_safe === true) || (isset($is_moderator) && $is_moderator);

$name_fuer_liste = !empty($safe_chat_user_name_db) ? trim($safe_chat_user_name_db) : '';
if ($name_fuer_liste === "" || $name_fuer_liste === "undefined") {
    $name_fuer_liste = "Gast_" . substr(md5($user_ip), 0, 4);
}

$final_save_name = $name_fuer_liste;
$ist_registrierter_chat_user = (strpos($final_save_name, "_CU") !== false);
$aktuelle_user_id_numerisch = intval($current_user_id);

if ($final_save_name === "DJ-Tomjac") {
    $final_save_name = str_replace("_Gast", "", $final_save_name);
} 
else if ($aktuelle_user_id_numerisch > 150 && !$ich_bin_leitung && !$ich_bin_moderator_check && strpos($final_save_name, "_Gast") === false && !$ist_registrierter_chat_user && $aktuelle_user_id_numerisch == 0) { 
    $final_save_name .= "_Gast"; 
}

$name_sicher_escaped = addslashes($final_save_name);
$aktueller_zeitstempel = time();

// =========================================================================
// 🔒 REPARIERT: ECHTE CHEF-ERKENNUNG (Nutzt die korrekte Variable $final_save_name!)
// =========================================================================
$kick_aktiv_riegel_teil4 = isset($_COOKIE['rme_saved_kick_time']) || isset($_COOKIE['rme_saved_guest_name_kick']) || isset($_SESSION['rme_kick_time']);
$ist_echter_reconnect_teil4 = (isset($_GET['reconnect']) && $_GET['reconnect'] === 'true');

// REPARIERT: Greift jetzt auf die berechnete Variable $final_save_name zu!
$ich_bin_der_chef_teil4 = ($user_ip === '91.10.82.244' || $sauberer_backend_name === 'dj-tomjac' || $aktuelle_user_id_numerisch === 18 || strtolower($final_save_name) === 'dj-tomjac');

if ($kick_aktiv_riegel_teil4 && !$ist_echter_reconnect_teil4 && !$ich_bin_der_chef_teil4) {
    if (!empty($meine_aktuelle_session)) {
        dbquery("DELETE FROM " . $online_table . " WHERE session_id='" . addslashes($meine_aktuelle_session) . "'");
    }
    http_response_code(403);
    header("Content-Type: text/plain; charset=UTF-8");
    echo "[DU_WURDEST_AUTO_GEKICKT_WEGEN_INAKTIVITAET]";
    exit;
}
// =========================================================================

if (!empty($meine_aktuelle_session)) {
    if (!isset($_SESSION)) { session_start(); }

    // 1. Wir prüfen den aktuellen Zustand in der Onlineliste
    $check_user_existiert = dbquery("SELECT is_afk, last_written FROM " . $online_table . " WHERE session_id='" . addslashes($meine_aktuelle_session) . "' OR username='" . $name_sicher_escaped . "' LIMIT 1");
    
    $ist_bereits_online = false;
    $aktueller_status_in_db = 0;
    $bestehender_schreibstempel = 0;

    if ($check_user_existiert && dbrows($check_user_existiert) > 0) {
        $exist_row = dbarray($check_user_existiert);
        $aktueller_status_in_db = intval($exist_row['is_afk']);
        $bestehender_schreibstempel = intval($exist_row['last_written']);
        if ($aktueller_status_in_db !== 2) {
            $ist_bereits_online = true;
        }
    }
    
    // =========================================================================
    // DIE ABSOLUTE MOBIL-BLOCKADE (SYNTAX-SICHER - REPARIERT V6)
    // =========================================================================
    $ist_automatischer_poll = (isset($_GET['action']) && ($_GET['action'] === 'history' || $_GET['action'] === 'online_list' || $_GET['action'] === 'read'));
    
    // Akzeptiert jetzt JEDEN Wert für Reconnect (egal ob 'true' oder Zufallszahl!)
    $ist_manueller_reconnect = (!empty($_GET['reconnect']));

    // Wenn die Session-Sperre noch aktiv ist:
    $sperre_aktiv = false;
    if (isset($_SESSION['rme_kick_time'])) {
        if ((time() - intval($_SESSION['rme_kick_time'])) < 600) { 
            $sperre_aktiv = true;
        } else {
            unset($_SESSION['rme_kick_time']); 
        }
    }

    // Hebt die Sperre auf, wenn er AKTIV und MANUELL auf den Button klickt (Nach oben verschoben!)
    if ($ist_manueller_reconnect) {
        if (session_status() == PHP_SESSION_NONE) { session_name("RME_RADIO_CHAT_SESSION"); session_start(); }
        unset($_SESSION['rme_kick_time']);
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        
        // Erst BEIM Reconnect löschen wir den alten Status, damit er frisch eingetragen wird
        dbquery("DELETE FROM " . $online_table . " WHERE session_id='" . addslashes($meine_aktuelle_session) . "' OR username='" . addslashes($safe_chat_user_name_db) . "'");
        $aktueller_status_in_db = 0;
        $ist_bereits_online = false;
    }

    // EISERNE BANNER-BLOCKADE: Wenn der User auf Status 2 steht, brechen wir ab.
    // WICHTIG: Wir löschen ihn HIER NICHT mehr! Er bleibt mit Status 2 in der DB, damit er blockiert bleibt!
    if ($aktueller_status_in_db === 2 && !$ist_manueller_reconnect && !$ich_bin_der_chef_teil4) {
        header("Content-Type: text/plain; charset=UTF-8");
        echo "[DU_WURDEST_AUTO_GEKICKT_WEGEN_INAKTIVITAET]";
        exit; 
    }

    // Wenn die Session-Sperre aktiv ist und ein automatischer Poll reinkommt
    if ($sperre_aktiv && $ist_automatischer_poll && !$ist_manueller_reconnect && !$ich_bin_der_chef_teil4) {
        header("Content-Type: text/plain; charset=UTF-8");
        echo "[DU_WURDEST_AUTO_GEKICKT_WEGEN_INAKTIVITAET]";
        exit; 
    }
    // ===================================================================

    // REPARIERT: EISERNER SCHREIBSTEMPEL-SCHUTZ (NUR BEIM ECHTEN SENDEN!)
    $ist_echte_sende_aktion = (isset($_GET['action']) && $_GET['action'] === 'send');

    if ($ist_bereits_online && $bestehender_schreibstempel > 0) {
        $sicherer_schreibstempel = $bestehender_schreibstempel;
    } else {
        $sicherer_schreibstempel = $aktueller_zeitstempel;
    }

    if ($ist_echte_sende_aktion) {
        $sicherer_schreibstempel = $aktueller_zeitstempel;
    }
    // =========================================================================

    $db_session_id = !empty($meine_aktuelle_session) ? $meine_aktuelle_session : $sicherer_session_anker;
    $sicherer_name_db = isset($name_sicher_escaped) ? $name_sicher_escaped : ($_SESSION['rme_chat_guest_name'] ?? 'Gast');

    // =========================================================================
    // REPARIERT 3: Trennung der SQL-Abfragen als Variablen mit Cookie-Riegel
    // =========================================================================
    // MULTI-SCHILD: Prüft Hörer-Cookie ODER Gast-Cookie ODER die eiserne Server-Sitzung!
    $hat_aktiven_kick_cookie_weiche = isset($_COOKIE['rme_saved_kick_time']) || isset($_COOKIE['rme_saved_guest_name_kick']) || isset($_SESSION['rme_kick_time']);

    // REPARATUR-STAUBSAUGER FÜR DIE FLAGGEN: Wenn irgendwo ?? oder Leerzeichen drinstehen, knallhart auf DE korrigieren!
    dbquery("UPDATE " . $online_table . " SET user_country='DE' WHERE username='" . addslashes($sicherer_name_db) . "' AND (user_country='??' OR user_country='')");

    // =========================================================================
    // 🪟 🌐 FORENSIK-UPGRADE REFRESH-WEICHE: ABSOLUT REALE EINZELSPALTEN
    // =========================================================================
    $uaString = $_SERVER['HTTP_USER_AGENT'] ?? 'Unbekanntes Gerät';
    $agent_klein = strtolower($uaString);

    $p_browser = "Unbekannter Browser";
    $p_os      = "Unbekanntes OS";
    $p_device  = "💻";

    // A. Architektur (64-Bit vs 32-Bit) messerscharf heraustrennen
    $arch = "";
    if (preg_match('/x86_64|win64|x64|wow64|arm64/i', $agent_klein)) { $arch = " (64-Bit)"; } 
    elseif (preg_match('/i686|i386|win32|x86/i', $agent_klein)) { $arch = " (32-Bit)"; }

    // B. Das exakte Betriebssystem (OS) bestimmen
    if (strpos($agent_klein, 'windows nt 10.0') !== false) { $p_os = 'Windows 10/11' . $arch; }
    elseif (strpos($agent_klein, 'windows nt 6.3') !== false) { $p_os = 'Windows 8.1' . $arch; }
    elseif (strpos($agent_klein, 'windows nt 6.2') !== false) { $p_os = 'Windows 8' . $arch; }
    elseif (strpos($agent_klein, 'windows nt 6.1') !== false) { $p_os = 'Windows 7' . $arch; }
    elseif (strpos($agent_klein, 'macintosh') !== false || strpos($agent_klein, 'mac os x') !== false) { $p_os = 'Mac OS X'; }
    elseif (strpos($agent_klein, 'android') !== false) { $p_os = 'Android'; }
    elseif (strpos($agent_klein, 'iphone') !== false || strpos($agent_klein, 'ipad') !== false) { $p_os = 'iOS'; }
    elseif (strpos($agent_klein, 'linux') !== false) { $p_os = 'Linux' . $arch; }

    // C. Den exakten Browser bestimmen
    if (strpos($agent_klein, 'edg/') !== false || strpos($agent_klein, 'edge/') !== false) { $p_browser = 'Microsoft Edge'; }
    elseif (strpos($agent_klein, 'chrome/') !== false) { $p_browser = 'Google Chrome'; }
    elseif (strpos($agent_klein, 'firefox/') !== false) { $p_browser = 'Mozilla Firefox'; }
    elseif (strpos($agent_klein, 'safari/') !== false && strpos($agent_klein, 'chrome/') === false) { $p_browser = 'Apple Safari'; }
    elseif (strpos($agent_klein, 'opera/') !== false || strpos($agent_klein, 'opr/') !== false) { $p_browser = 'Opera'; }

    // D. Den Gerätetyp (Device) bestimmen
    if (strpos($agent_klein, 'tablet') !== false || strpos($agent_klein, 'ipad') !== false) { $p_device = '📱'; }
    elseif (strpos($agent_klein, 'mobile') !== false || strpos($agent_klein, 'iphone') !== false || strpos($agent_klein, 'android') !== false) { $p_device = '📱'; }

    $finaler_user_agent_eintrag = $p_os . " (" . $p_browser . ") " . $p_device;

     // =========================================================================
    // 🔥 REPARIERT: AUTOMATISCHER EINZUGS-FUNK (NUR 1X PRO SESSION TIMEOUT-SAFE)
    // =========================================================================
    if (!$ist_bereits_online && intval($aktuelle_user_id_numerisch) > 0) {
        
        // 🌟 SESSION-RIEGEL: Wir prüfen, ob für diese User-ID das Intro heute schon lief
        if (session_status() == PHP_SESSION_NONE) { session_name("RME_RADIO_CHAT_SESSION"); @session_start(); }
        $intro_bereits_gespielt = isset($_SESSION['rme_intro_played_' . intval($aktuelle_user_id_numerisch)]);
        
        if (!$intro_bereits_gespielt) {
            $check_intro_exists = dbquery("SELECT user_id FROM fusionb7754_chat_intro WHERE user_id = '" . intval($aktuelle_user_id_numerisch) . "' LIMIT 1");
            
            if ($check_intro_exists && dbrows($check_intro_exists) > 0) {
                
                // Array-Key-Schutz gegen den "Undefined array key" Absturz!
                $sicherer_sound_key = (isset($sound_typ) && $sound_typ !== "") ? $sound_typ : "default";
                $sound_datei_name = (isset($sound_dateien) && is_array($sound_dateien) && isset($sound_dateien[$sicherer_sound_key])) ? $sound_dateien[$sicherer_sound_key] : "intro.mp3";
                
                $signal_text = "SOUND:" . $sound_datei_name;
                $intro_zeitstempel = time() - 10;
                $sicherer_chat_speicher = isset($chat_table) ? $chat_table : "fusionb7754_chat_messages";
                
                // Putzteufel für alte Signale
                dbquery("DELETE FROM fusionb7754_chat_messages WHERE (user_id=999 OR user_id=995 OR user_id=988) AND datestamp < " . (time() - 10));

                // 🚀 Signal frisch einstreuen
                dbquery("INSERT INTO " . $sicherer_chat_speicher . " (user_id, guest_name, message, datestamp) 
                         VALUES (999, 'SYSTEM_SOUND', '" . addslashes($signal_text) . "', '" . $intro_zeitstempel . "')");
                         
                // 🌟 RIEMEN AUFS CONFLICT-RAD: Wir brennen das "Erfolgreich gespielt" in die Session!
                $_SESSION['rme_intro_played_' . intval($aktuelle_user_id_numerisch)] = true;
            }
        }
    }
    // =========================================================================

    // =========================================================================
    // NATIVE MYSQL-MOBILFUNK-BLITZWEICHE (JETZT MIT COOPERATIVE SOUND-SCHUTZ!)
    // =========================================================================
    // WICHTIG: Wenn der User gekickt ist (Status 2), blockieren wir das REPLACE INTO.
    // Dadurch wird seine Zeit NICHT mehr erneuert und das Müllschlucker-System 
    // löscht ihn nach 60 Sekunden, falls er das Fenster schließt!
    if ($aktueller_status_in_db === 2 && !$ist_manueller_reconnect && !$ich_bin_der_chef_teil4) {
        $sql_replace_user = ""; 
    } else {
        $sql_replace_user = "REPLACE INTO " . $online_table . " (session_id, user_id, username, last_active, last_written, is_afk, ip_address, user_country, user_agent, user_os, user_browser, user_device)
        VALUES ('" . addslashes($db_session_id) . "', '" . $aktuelle_user_id_numerisch . "', '" . addslashes($sicherer_name_db) . "', '" . $aktueller_zeitstempel . "', '" . $sicherer_schreibstempel . "', " . intval($aktueller_status_in_db) . ", '" . $user_ip . "', 'DE', '" . addslashes($finaler_user_agent_eintrag) . "', '" . addslashes($p_os) . "', '" . addslashes($p_browser) . "', '" . addslashes($p_device) . "')";
    }

    // Nur ausführen, wenn die Query nicht durch die Kick-Sperre geleert wurde
    if (!empty($sql_replace_user)) {
        dbquery($sql_replace_user);
    }
  
    // 🔥 COOPERATIVE SOUND-SCHUTZ: Blockiert das automatische Überschreiben der Sound-Zelle!
    $ich_bin_der_dj_check = (intval($aktuelle_user_id_numerisch) === 18 || $db_session_id === session_id());
    
    if ($ich_bin_der_dj_check) {
        $check_sound_zelle = dbquery("SELECT user_agent FROM " . $online_table . " WHERE user_id=18 OR session_id='" . addslashes($db_session_id) . "' LIMIT 1");
        if ($check_sound_zelle && dbrows($check_sound_zelle) > 0) {
            $zelle = dbarray($check_sound_zelle);
            if (isset($zelle['user_agent']) && strpos($zelle['user_agent'], 'SOUND:') === 0) {
                // Ein Sound läuft! Wir halten Dich online, berühren aber das Feld user_agent nicht!
                dbquery("UPDATE " . $online_table . " SET last_active='" . $aktueller_zeitstempel . "' WHERE user_id=18 OR session_id='" . addslashes($db_session_id) . "'");
                $sql_replace_user = ""; // Schaltet den zerstörerischen Standard-Replace stumm!
            }
        }
    }

    // Wenn kein Sound aktiv ist, läuft alles normal weiter:
    if (!empty($sql_replace_user)) {
        dbquery($sql_replace_user);
    }

    // =========================================================================

    // Wir lassen dem System 100 Mikrosekunden Luft zum Atmen
    usleep(100);
    // =========================================================================

    // DOPPELTE SICHERHEIT: Aktualisiert für das Team ebenfalls NUR NOCH last_active!
    if (($aktuelle_user_id_numerisch > 0 && $aktuelle_user_id_numerisch < 150) || $ich_bin_leitung || $ich_bin_moderator_check || $final_save_name === 'DJ-Tomjac') {
        $sicherer_team_name = isset($name_sicher_escaped) ? addslashes($name_sicher_escaped) : 'DJ-Tomjac';
        $sql_team_final = "UPDATE ".$online_table." SET last_active='".$aktueller_zeitstempel."' WHERE username='".$sicherer_team_name."'";
        dbquery($sql_team_final);
    }

} // Schließt die große Onlinetabelle-Prüfungs-Weiche

// =========================================================================
    $online_query = dbquery("SELECT * FROM ".$online_table." ORDER BY username ASC");
    $sortierte_user_liste = array();
    if ($online_query && dbrows($online_query) > 0) {
        while ($online_user = dbarray($online_query)) {
            
            // =========================================================================
            // 🔥 1. GLOBALER SOUNDBOARD-EMPFÄNGER ÜBER DEINE ZEILE (ID 18)
            // =========================================================================
            if (intval($online_user['user_id']) === 18 || strtolower($online_user['username']) === 'dj-tomjac') {
                if (isset($online_user['user_agent']) && strpos($online_user['user_agent'], 'SOUND:') === 0) {
                    echo "<script class='rme-hidden-sound-trigger' data-file='" . htmlspecialchars($online_user['user_agent'], ENT_QUOTES, 'UTF-8') . "'></script>";
                }
            }
            // =========================================================================

            $raw_loop_name = (string)$online_user['username'];
            $display_name = str_replace(array("_Gast", "_CU"), "", $raw_loop_name);
            $sauberer_name_low = strtolower(trim($display_name));
            
            $loop_ist_chat_user = (strpos($raw_loop_name, '_CU') !== false);
            $loop_user_id = intval($online_user['user_id']);

            $ist_wirklich_gast = (strpos(strtolower($raw_loop_name), 'gast') !== false || ($loop_user_id >= 2000 && !$loop_ist_chat_user) || ($loop_user_id === 0 && !$loop_ist_chat_user));

            $level = 0; $u_groups = ''; $prio = 6;

            if (!$ist_wirklich_gast || $loop_ist_chat_user) {
                $user_db_name = addslashes($display_name);
                $user_info_query = dbquery("SELECT user_level, user_groups FROM ".$users_table." WHERE user_name='".$user_db_name."' LIMIT 1");
                if ($user_info_query && dbrows($user_info_query) > 0) {
                    $user_info_row = dbarray($user_info_query);
                    $level = intval($user_info_row['user_level']);
                    $u_groups = (string)$user_info_row['user_groups'];
                }
                
                // REPARIERT: Einheitliche Priorisierung für die Sortierung (Testuser bleibt Hörer)
                if ($sauberer_name_low === 'dj-tomjac' || $sauberer_name_low === 'tomjac') { 
                    $prio = 1; 
                } elseif (strpos($u_groups, ".1.") !== false || strpos($u_groups, ".2.") !== false || $level === 103 || $level === -103) { 
                    $prio = 2; 
                } elseif (strpos($u_groups, ".3.") !== false || $level === 102 || $level === 101 || $level === -101) { 
                    $prio = 3; 
                } elseif ($loop_user_id > 0 || $loop_ist_chat_user) { 
                    $prio = 5; 
                }
            }
            
            // Schreibt die Spalten fest in das Sortier-Array
            $online_user['user_os']      = isset($online_user['user_os']) ? $online_user['user_os'] : '';
            $online_user['user_browser'] = isset($online_user['user_browser']) ? $online_user['user_browser'] : '';
            $online_user['user_device']  = isset($online_user['user_device']) ? $online_user['user_device'] : '💻';

            $online_user['u_level'] = $level;
            $online_user['u_groups'] = $u_groups;
            $online_user['prio_wert'] = $prio;
            $sortierte_user_liste[] = $online_user;
        }
    }
    usort($sortierte_user_liste, function($a, $b) {
        if ($a['prio_wert'] == $b['prio_wert']) { return strcmp($a['username'], $b['username']); }
        return ($a['prio_wert'] < $b['prio_wert']) ? -1 : 1;
    });
 
echo "<table class='rme-online-main-table'>";

    foreach ($sortierte_user_liste as $online_user) {
        $afk_suffix = "";
        $raw_loop_name = isset($online_user['username']) ? (string)$online_user['username'] : '';
        $display_name = str_replace(array("_Gast", "_CU"), "", $raw_loop_name);
        $sauberer_name_low = strtolower(trim($display_name));
        $loop_user_id = isset($online_user['user_id']) ? intval($online_user['user_id']) : 0;
        
        $loop_ist_chat_user = (strpos($raw_loop_name, '_CU') !== false);
        $ist_wirklich_gast = (strpos(strtolower($raw_loop_name), 'gast') !== false || ($loop_user_id >= 2000 && !$loop_ist_chat_user) || ($loop_user_id === 0 && !$loop_ist_chat_user));

        // ECHTE RECHTE AUS DEM SORTIER-ARRAY HOCHHOLEN
        $level = isset($online_user['u_level']) ? intval($online_user['u_level']) : 0;
        $u_groups = isset($online_user['u_groups']) ? (string)$online_user['u_groups'] : '';

        $loop_ist_leitung = false; 
        $loop_ist_moderator = false; 
        $loop_ist_team_mitglied = false;

        // UNZERSTÖRBARE TEAM-RECHTE-WEICHE (Chef-Joker greift immer!)
        if ($sauberer_name_low === 'dj-tomjac' || $sauberer_name_low === 'tomjac') {
            $loop_ist_leitung = true;
            $loop_ist_team_mitglied = true;
        } elseif (!$ist_wirklich_gast) {
            if (strpos($u_groups, ".1.") !== false || strpos($u_groups, ".2.") !== false || $level === 103 || $level === -103) {
                $loop_ist_leitung = true;
                $loop_ist_team_mitglied = true;
            } elseif (strpos($u_groups, ".3.") !== false || $level === 102 || $level === 101 || $level === -101) {
                $loop_ist_moderator = true;
                $loop_ist_team_mitglied = true;
            }
        }

        $last_active_time = isset($online_user['last_active']) ? intval($online_user['last_active']) : time();
        $aktueller_afk_db_wert = isset($online_user['is_afk']) ? intval($online_user['is_afk']) : 0;

        if ($aktueller_afk_db_wert === 1) {
            $afk_suffix = " <span class='rme-afk-badge'>[AFK]</span>";
        }

        // =========================================================================
        // AUTOMATISCHER INAKTIVITÄTS-KICK (NUTZT JETZT DIE KORREKTEN VARIABLEN)
        // =========================================================================
        $ist_team_schutz_aktiv = ($loop_ist_team_mitglied || $sauberer_name_low === 'dj-tomjac');
        $current_time = time();
        $kick_zeit_grenze = 1200; 

        if (!$ist_team_schutz_aktiv) {
            $letzter_echter_db_schreibstempel = !empty($online_user['last_written']) ? intval($online_user['last_written']) : $last_active_time;
            if (($current_time - $letzter_echter_db_schreibstempel) > $kick_zeit_grenze) {
                if ($aktueller_afk_db_wert !== 2) {
                    $meine_aktuelle_session = session_id();
                    $name_sicher_escaped = $_SESSION['rme_chat_guest_name'] ?? ($_SESSION['chat_user_name'] ?? '');
                    if ($raw_loop_name === $name_sicher_escaped || (isset($online_user['session_id']) && $online_user['session_id'] === $meine_aktuelle_session)) {
                        setcookie("rme_saved_kick_time", time(), time() + 120, "/");
                    }
                    dbquery("UPDATE " . $online_table . " SET is_afk=2 WHERE username='" . addslashes($raw_loop_name) . "'");
                }
            }
        }

        $badge_html = "";
        $ist_gerade_on_air = (isset($finaler_live_dj_sauber) && $finaler_live_dj_sauber !== "autodj" && !$ist_wirklich_gast && (strpos($sauberer_name_low, $finaler_live_dj_sauber) !== false || strpos($finaler_live_dj_sauber, $sauberer_name_low) !== false));

        // DYNAMISCHE BADGE-WEICHE (FARBEN-RETTUNG)
        if ($sauberer_name_low === "dj-tomjac" || $sauberer_name_low === "tomjac") { 
            $name_class = "rme-rgb-hadmin"; 
            $badge_html = $ist_gerade_on_air ? "" : "<span class='rme-badge-hadmin'>[HADMIN]</span>"; 
        } elseif ($loop_ist_leitung) { 
            $name_class = "rme-rgb-username"; 
            $badge_html = $ist_gerade_on_air ? "" : "<span class='rme-badge-admin'>[ADMIN]</span>"; 
        } elseif ($loop_ist_moderator) { 
            $name_class = "rme-moderator-username"; 
            $badge_html = $ist_gerade_on_air ? "" : "<span class='rme-badge-mod'>[MODERATOR]</span>"; 
        } elseif (!$ist_wirklich_gast) { 
            $name_class = "rme-user-logged"; 
        } else { 
            $name_class = "rme-name-guest"; 
        }

        if ($ist_gerade_on_air) { 
            $badge_html .= " <img src='img/on-air-anim.gif' class='rme-onair-gif-badge' title='SENDET LIVE!'>"; 
        }

        $meine_aktuelle_session = session_id();
        $ist_mein_eigen_eintrag = ($online_user['session_id'] === $meine_aktuelle_session);

        
        // FLAGGEN- & IP-BOX BERECHNEN
        $u_ip = (string)($online_user['ip_address'] ?? '127.0.0.1');
        $raw_country = !empty($online_user['user_country']) ? strtoupper(trim($online_user['user_country'])) : 'DE';
        if (strlen($raw_country) !== 2) { $raw_country = 'DE'; }
        
        if ($u_ip === '127.0.0.1' || $u_ip === '::1') { 
            $saubere_admin_flagge = "🏠 LOCAL"; 
        } else {
            $b1 = substr($raw_country, 0, 1); $b2 = substr($raw_country, 1, 1);
            $hex1 = dechex(ord($b1) + 127397); $hex2 = dechex(ord($b2) + 127397);
            $saubere_admin_flagge = "&#x" . $hex1 . ";&#x" . $hex2 . ";";
        }
        
        $clean_id_name = preg_replace("/[^a-zA-Z0-9]/", "", $raw_loop_name);
        $id_country = "rme_box_c_" . $clean_id_name; $id_ip = "rme_box_i_" . $clean_id_name;
        
        // KUGELSICHERE CHEF-ERKENNUNG FÜR IP-BOX
        $echter_betrachter = isset($userdata['user_name']) ? trim($userdata['user_name']) : '';
        $betrachter_level = isset($userdata['user_level']) ? intval($userdata['user_level']) : 0;
        
        $ich_darf_admin_tools_sehen = (
            $echter_betrachter === 'DJ-Tomjac' || 
            (defined('iADMIN') && iADMIN) || 
            $betrachter_level == 103 || 
            (isset($_SESSION['chat_user_name']) && $_SESSION['chat_user_name'] === 'DJ-Tomjac') ||
            (isset($_SESSION['rme_chat_guest_name']) && $_SESSION['rme_chat_guest_name'] === 'DJ-Tomjac')
        );


// =========================================================================
// RADIKALE FORENSIK V8: REALE SPALTEN DIRECT-INJECTION FÜR JAVASCRIPT
// =========================================================================
$rohes_land = isset($online_user['user_country']) ? strtoupper(trim((string)$online_user['user_country'])) : 'DE';
if (empty($rohes_land)) { $rohes_land = 'DE'; }
$saubere_admin_flagge = ($rohes_land === 'DE') ? "🇩🇪" : "🏳️"; 

$id_country = 'country_'.uniqid();
$id_ip = 'ip_'.uniqid();

// IP-Ermittlung
$u_ip_adresse_sicher = "";
if (isset($online_user['ip_address'])) { $u_ip_adresse_sicher = trim((string)$online_user['ip_address']); }

// Nachrichtenzähler (INTELLIGENTE PHP-FUSION WEICHE: USER_ID VS GUEST_NAME)
$roher_schleifen_name = (string)$online_user['username'];
$sauberer_autoren_name = str_replace(array("_Gast", "_CU"), "", $roher_schleifen_name);
$loop_user_name_db = addslashes(trim($sauberer_autoren_name));
$loop_user_id_numerisch = intval($online_user['user_id']);

$anzahl_nachrichten = 0;
if (!empty($chat_table)) {
    if ($loop_user_id_numerisch > 0 && $loop_user_id_numerisch < 2000) {
        $spam_check_query = dbquery("SELECT COUNT(*) as gesamt_texte FROM " . $chat_table . " WHERE user_id='" . $loop_user_id_numerisch . "'");
    } else {
        $spam_check_query = dbquery("SELECT COUNT(*) as gesamt_texte FROM " . $chat_table . " WHERE guest_name='" . $loop_user_name_db . "' OR guest_name='" . $loop_user_name_db . "_CU'");
    }
    if ($spam_check_query && dbrows($spam_check_query) > 0) { $spam_row = dbarray($spam_check_query); $anzahl_nachrichten = intval($spam_row['gesamt_texte']); }
}

// 🎯 HIER WAR DER HÄNGER: Wir lesen die echten Spalten absolut direkt und unberührt aus!
$db_os      = !empty($online_user['user_os']) ? trim((string)$online_user['user_os']) : 'Unbekannt';
$db_browser = !empty($online_user['user_browser']) ? trim((string)$online_user['user_browser']) : 'Unbekannt';
$db_device  = !empty($online_user['user_device']) ? trim((string)$online_user['user_device']) : '💻';

// =========================================================================
// 🕒 REPARIERT: NUTZT NUN LAST_WRITTEN FÜR DIE ABSOLUTE PRÄZISION
// =========================================================================
$login_zeit_formatiert = "Unbekannt";
$exakte_afk_uhrzeit_php = "--:--";

// Wir greifen direkt auf die unberührte Schreib-Zeit zu
$echte_schreib_sekunden = isset($online_user['last_written']) ? intval($online_user['last_written']) : 0;

if ($echte_schreib_sekunden > 0) {
    // 1. Der Stempel zeigt nun felsenfest die Schreibzeit mit Sekunden an!
    $login_zeit_formatiert = date("d.m. H:i:s", $echte_schreib_sekunden);
    
    // 2. Mathematisch exakte AFK-Uhrzeit (Schreibzeit + 5 Minuten)
    $afk_ziel_sekunden = $echte_schreib_sekunden + 300;
    $exakte_afk_uhrzeit_php = date("H:i:s", $afk_ziel_sekunden);
}
// =========================================================================

// =========================================================================
// 🕒 EXAKTE AFK-STARTZEIT AUS LAST_WRITTEN BERECHNEN (PHP-CORE)
// =========================================================================
$exakte_afk_uhrzeit_php = "--:--";

// Wir holen den echten Schreib-Zeitstempel dieses Users aus der Onlineliste
$db_schreib_stempel = isset($online_user['last_written']) ? intval($online_user['last_written']) : 0;

// Wenn der Stempel gültig ist, rechnen wir exakt 5 Minuten (300 Sekunden) drauf
if ($db_schreib_stempel > 0) {
    $afk_ziel_zeitstempel = $db_schreib_stempel + 300;
    $exakte_afk_uhrzeit_php = date("H:i:s", $afk_ziel_zeitstempel); // Format: HH:MM:SS
}

// =========================================================================

// =========================================================================
// GOOGLE-FLAGGEN-TARNUNG (JETZT SIND DIE MULTI-DATA SPALTEN AM START!)
// =========================================================================
$admin_geo_html = "<div class='rme-geo-row-layout' style='width: 0px !important; height: 0px !important; opacity: 0 !important; overflow: hidden !important; display: inline-block !important; margin: 0 !important; padding: 0 !important; vertical-align: middle !important;'>";
$admin_geo_html .= "<span id='".$id_country."' class='sidebar-fahne rme-admin-geo-trigger'>".$saubere_admin_flagge."</span>";

if ($ich_darf_admin_tools_sehen && !empty($u_ip_adresse_sicher)) {
    // 🔥 JETZT STIMMT DIE DATA-BRÜCKE: Wir übergeben die 3 separaten Variablen fehlerfrei an das Frontend!
    $admin_geo_html .= "<span id='".$id_ip."' class='rme-admin-geo-country' 
        data-os='".htmlspecialchars($db_os, ENT_QUOTES, 'UTF-8')."' 
        data-browser='".htmlspecialchars($db_browser, ENT_QUOTES, 'UTF-8')."' 
        data-device='".htmlspecialchars($db_device, ENT_QUOTES, 'UTF-8')."' 
        data-msg='".$anzahl_nachrichten."' 
        data-login='".$login_zeit_formatiert."'>".$u_ip_adresse_sicher."</span>";
}
$admin_geo_html .= "</div>";

        // =========================================================================
        // 🔒 UNBESTECHLICHE BETRACHTER-RECHTE (DIREKT-ABFRAGE FÜR MAIK & TEAM)
        // =========================================================================
        // Wir holen uns die ID desjenigen, der die Liste gerade im Browser geöffnet hat
        $betrachter_uid_numerisch = isset($current_user_id) ? intval($current_user_id) : (isset($sichere_digital_id) ? intval($sichere_digital_id) : 0);
        $betrachter_name_low = isset($name_sicher_escaped) ? strtolower(trim($name_sicher_escaped)) : '';

        // Falls die Session im Hintergrund wackelt, fragen wir die echten CMS-Gruppen live aus der DB ab!
        $betrachter_groups = isset($u_groups) ? (string)$u_groups : '';
        $betrachter_level = isset($level) ? intval($level) : 0;

        if ($betrachter_groups === '' && $betrachter_uid_numerisch > 0 && $betrachter_uid_numerisch < 1000) {
            $db_b_check = dbquery("SELECT user_level, user_groups FROM " . DB_USERS . " WHERE user_id='" . $betrachter_uid_numerisch . "' LIMIT 1");
            if ($db_b_check && dbrows($db_b_check) > 0) {
                $b_row = dbarray($db_b_check);
                $betrachter_level = intval($b_row['user_level']);
                $betrachter_groups = (string)$b_row['user_groups'];
            }
        }

        // 1. Wer schaut sich die Liste gerade an? (Vollautomatisch & Ausfallsicher)
        $ich_bin_leitung_betrachter = (
            $betrachter_level === 103 || $betrachter_level === -103 || 
            strpos($betrachter_groups, ".1.") !== false || strpos($betrachter_groups, ".2.") !== false ||
            $betrachter_name_low === 'dj-tomjac' || $betrachter_name_low === 'tomjac' || $betrachter_uid_numerisch === 18
        );

        $ich_bin_mod_betrachter = (
            !$ich_bin_leitung_betrachter && (
                $betrachter_level === 101 || $betrachter_level === -101 || $betrachter_level === 102 ||
                strpos($betrachter_groups, ".3.") !== false || strpos($betrachter_groups, ".4.") !== false || strpos($betrachter_groups, ".5.") !== false ||
                $betrachter_uid_numerisch === 6 // 🟡 Unzerstörbare Brücke exklusiv für Maik!
            )
        );

        // 2. Welchen Rang hat der User in dieser Zeile der Schleife? (Ziel-Rechte auswerten)
        $loop_ist_leitung_ziel = ($loop_ist_leitung || $sauberer_name_low === 'dj-tomjac' || $raw_loop_name === 'DJ-Tomjac');
        $loop_ist_mod_ziel = $loop_ist_moderator;
        $ist_mein_eigen_eintrag = ($raw_loop_name === $name_sicher_escaped);

        // 3. Die logische Weiche, wer wen kicken darf
        $darf_ich_diesen_user_kicken = false;
        // =========================================================================


        if (!$ist_mein_eigen_eintrag) {
            if ($ich_bin_leitung_betrachter) {
                // Admins/Chef dürfen jeden kicken, AUẞER andere Admins oder dich selbst
                if (!$loop_ist_leitung_ziel) {
                    $darf_ich_diesen_user_kicken = true;
                }
            } elseif ($ich_bin_mod_betrachter) {
                // Moderatoren dürfen NUR normale Hörer und Gäste kicken (keine Admins, keine anderen Mods!)
                if (!$loop_ist_leitung_ziel && !$loop_ist_mod_ziel) {
                    $darf_ich_diesen_user_kicken = true;
                }
            }
        }

        // Name und Spalten komplett über Klassen sauber aufgebaut
        echo "<tr class='rme-online-main-tr'>";
        echo "<td colspan='2' class='rme-online-main-td'>";

        echo "<table class='rme-online-inner-table'>";
        echo "<tr class='rme-online-inner-tr'>";

        echo "<td class='rme-online-name-td'>";
        echo "<div class='rme-online-name-wrapper'>";
        echo "• <span class='".$name_class." rme-online-username-text'>".htmlspecialchars($display_name, ENT_QUOTES, 'UTF-8')."</span>" .$afk_suffix . $badge_html . " " . $admin_geo_html;       
        echo "</div>";
        echo "</td>"; 

        echo "<td class='rme-online-action-td'>";
        
        // HIER WIRD DIE NEUE WEICHE ANGEWENDET
        if ($darf_ich_diesen_user_kicken) {
            echo "<span class='rme-online-action-buttons-wrapper'>";
            echo "<button type='button' class='rme-list-btn rme-list-btn-kick' onclick='parent.fuehreListenKickAus(\"".htmlspecialchars($raw_loop_name, ENT_QUOTES, 'UTF-8')."\")'>Kick</button>";
            echo "<button type='button' class='rme-list-btn rme-list-btn-bann' onclick='parent.fuehreListenBannAus(\"".htmlspecialchars($raw_loop_name, ENT_QUOTES, 'UTF-8')."\")'>Bann</button>";
            echo "</span>";
        } else {
            echo "<span class='rme-online-action-empty-space'>&nbsp;</span>";
        }
        
        echo "</td>"; 
        echo "</tr>"; 
        echo "</table>";

        echo "</td>";
        echo "</tr>";
        
        // REPARIERT: Valider Tabellenabschluss ohne offene Geister-Tags
        echo "<tr class='rme-online-hidden-row'><td colspan='2'></td></tr>";
    }
    echo "</table>"; 
    
    if (strpos(strtolower($name_sicher_escaped), 'gast') !== false) {
        echo "<input type='hidden' id='rme-live-sync-gastname' value='".htmlspecialchars($name_sicher_escaped, ENT_QUOTES, 'UTF-8')."'>";
    }
    exit;
}

// =========================================================================
// NEU: LOKALER PROFILBILD-UPLOAD FÜR REGISTRIERTE CHAT-USER (_CU) VIA FETCH
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] == "upload_avatar") {
    if (!isset($_SESSION)) { session_start(); }
    
    // 🛠️ FIX 1: Verhindert "Headers already sent" Fehler
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    
    $session_user_name = $_SESSION['rme_chat_guest_name'] ?? ($_SESSION['chat_user_name'] ?? '');
    
    if (empty($session_user_name) || strpos($session_user_name, '_CU') === false) {
        echo json_encode(['status' => 'error', 'message' => 'Zugriff verweigert oder kein Chat-User.']);
        exit;
    }

    if (isset($_FILES['rme_avatar_file']) && $_FILES['rme_avatar_file']['error'] == 0) {
        $file = $_FILES['rme_avatar_file'];
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
        
        if (!in_array($file['type'], $allowed_types)) {
            echo json_encode(['status' => 'error', 'message' => 'Ungültiges Format! Nur JPG oder PNG erlaubt.']);
            exit;
        }
        
        if ($file['size'] > 512000) { 
            echo json_encode(['status' => 'error', 'message' => 'Datei zu groß! Maximal 500 KB erlaubt.']);
            exit;
        }

        $user_db_search = dbquery("SELECT id FROM fusionb7754_chat_guest_accounts WHERE guest_name='".addslashes($session_user_name)."' LIMIT 1");
        
        if ($user_db_search && dbrows($user_db_search) > 0) {
            $user_id_cu = dbarray($user_db_search)['id'];
            
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $neuer_dateiname = "cu_avatar_" . $user_id_cu . "." . strtolower($ext);
            
            $upload_ordner = __DIR__ . "/avatars/";
            $upload_ziel = $upload_ordner . $neuer_dateiname;
            
            if (!is_dir($upload_ordner)) {
                @mkdir($upload_ordner, 0777, true);
            }
            
            if (move_uploaded_file($file['tmp_name'], $upload_ziel)) {
                // 🛠️ FIX 2: Mit LIMIT 1 versehen, um Deadlocks bei gleichzeitigem Upload zu verhindern
                dbquery("UPDATE fusionb7754_chat_guest_accounts SET guest_avatar='".addslashes($neuer_dateiname)."' WHERE id='".$user_id_cu."' LIMIT 1");
                echo json_encode(['status' => 'success', 'message' => 'Dein Profilbild wurde erfolgreich im Chat-Ordner gespeichert!']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Server-Fehler: Schreibrechte für den Ordner avatars/ fehlen!']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Chat-User im System nicht gefunden.']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Keine Datei ausgewählt oder Upload-Fehler.']);
    }
    exit;
}

// =========================================================================


// =========================================================================
// DIAGNOSE-VERSION: USER-HINTERGRUND HOCHLADEN
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'upload_user_bg') {
    // DIAGNOSE 1: Prüfen, ob überhaupt eine Datei ankommt
    if (!isset($_FILES['rme_user_bg_file'])) {
        echo "<script>alert('❌ Backend-Fehler: Keine Datei im Formular gefunden! (Formular-Feldname falsch?)');</script>";
        exit;
    }

    $file = $_FILES['rme_user_bg_file'];
    $smileyOrdner = "rme_smilies/"; 
    $erlaubteTypen = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
    
    // DIAGNOSE 2: PHP-Upload-Fehler prüfen (z.B. upload_max_filesize in der php.ini)
    if ($file['error'] !== 0) {
        echo "<script>alert('❌ PHP-Upload-Fehler-Code: " . $file['error'] . " (1 = zu groß für Server, 4 = keine Datei)');</script>";
        exit;
    }

    // DIAGNOSE 3: Format- und Größenprüfung
    if (!in_array($file['type'], $erlaubteTypen)) {
        echo "<script>alert('❌ Falsches Format: " . htmlspecialchars($file['type']) . " wird nicht unterstützt!');</script>";
        exit;
    }
    if ($file['size'] > 2097152) { // 2MB
        echo "<script>alert('❌ Datei zu groß: Deine Datei hat " . round($file['size']/1024/1024, 2) . " MB. Erlaubt sind max 2 MB.');</script>";
        exit;
    }

    // DIAGNOSE 4: Wer bist du? (User-ID ermitteln aus PHP-Fusion)
    // Falls $_SESSION['user_id'] leer ist, versuchen wir das PHP-Fusion $userdata-Array
    $userIdSicher = 0;
    if (isset($_SESSION['user_id'])) {
        $userIdSicher = (int)$_SESSION['user_id'];
    } elseif (isset($userdata['user_id'])) {
        $userIdSicher = (int)$userdata['user_id'];
    }

    if ($userIdSicher <= 0) {
        echo "<script>alert('❌ Sicherheits-Fehler: Deine User-ID konnte nicht ermittelt werden! Bist du eingeloggt?');</script>";
        exit;
    }

    // SICHERHEIT: Tabellennamen absichern, falls im Backend nicht definiert
    if (!isset($users_table)) { $users_table = DB_PREFIX."users"; }

    $dateiendung = pathinfo($file['name'], PATHINFO_EXTENSION);
    $neuerName = "user_bg_" . $userIdSicher . "_" . time() . "." . $dateiendung;
    $zielPfad = $smileyOrdner . $neuerName;
    
    // Altes Bild löschen
    $sql_old = "SELECT chat_bg_image FROM $users_table WHERE user_id = '$userIdSicher' LIMIT 1";
    $res_old = mysqli_query($db_connect, $sql_old);
    if ($res_old && $row_old = mysqli_fetch_assoc($res_old)) {
        if (!empty($row_old['chat_bg_image']) && file_exists($row_old['chat_bg_image'])) {
            @unlink($row_old['chat_bg_image']);
        }
    }
    
    // Datei verschieben
    if (move_uploaded_file($file['tmp_name'], $zielPfad)) {
        $sichererPfad = mysqli_real_escape_string($db_connect, $zielPfad);
        
        $sql = "UPDATE $users_table SET chat_bg_image = '$sichererPfad' WHERE user_id = '$userIdSicher'";
        $query_run = mysqli_query($db_connect, $sql);
        
        if (!$query_run) {
            echo "<script>alert('❌ Datenbank-Fehler: Konnte den Pfad nicht in die Tabelle eintragen. Spalte chat_bg_image vorhanden?');</script>";
            exit;
        }
        
        echo "<script>
            alert('✔️ Dein persönlicher Chat-Hintergrund wurde gespeichert!');
            window.parent.document.getElementById('rme-chat-window').style.backgroundImage = \"url('" . $zielPfad . "')\";
            window.parent.document.getElementById('rme-chat-window').style.backgroundSize = \"cover\";
            window.parent.document.getElementById('rme-chat-window').style.backgroundPosition = \"center\";
        </script>";
        exit;
    } else {
        echo "<script>alert('❌ FTP-Fehler: Die Datei konnte nicht in den Ordner " . $smileyOrdner . " verschoben werden. Schreibrechte (CHMOD 777) prüfen!');</script>";
        exit;
    }
}

// =========================================================================
// ACTION-EMPFÄNGER: USER-HINTERGRUND AUF ADMIN-STANDARD ZURÜCKSETZEN
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] === 'reset_user_bg') {
    $userIdSicher = (int)($_SESSION['user_id'] ?? 0);
    
    // Altes Bild löschen
    $sql_old = "SELECT chat_bg_image FROM $users_table WHERE user_id = '$userIdSicher' LIMIT 1";
    $res_old = mysqli_query($db_connect, $sql_old);
    if ($res_old && $row_old = mysqli_fetch_assoc($res_old)) {
        if (!empty($row_old['chat_bg_image']) && file_exists($row_old['chat_bg_image'])) {
            @unlink($row_old['chat_bg_image']);
        }
    }
    
    // Feld auf NULL zurücksetzen
    $sql = "UPDATE $users_table SET chat_bg_image = NULL WHERE user_id = '$userIdSicher'";
    mysqli_query($db_connect, $sql);
    
    // Jetzt den Admin-Hintergrund auslesen, damit das JavaScript weiß, wohin es zurückspringen soll
    $sql_admin = "SELECT setting_value FROM $settings_table WHERE setting_key = 'global_chat_bg' LIMIT 1";
    $res_admin = mysqli_query($db_connect, $sql_admin);
    $row_admin = mysqli_fetch_assoc($res_admin);
    $adminBg = $row_admin ? $row_admin['setting_value'] : '';
    
    if (!empty($adminBg)) {
        $jsBgStyle = "url('" . $adminBg . "')";
    } else {
        $jsBgStyle = "none";
    }
    
    echo "<script>
        alert('🛑 Hintergrund zurückgesetzt!');
        window.parent.document.getElementById('rme-chat-window').style.backgroundImage = \"" . $jsBgStyle . "\";
    </script>";
    exit;
}

// -----------------------------------------------------------------
// AKTION: DROPDOWN JSON ENDPUNKT (FINALE KATEGORIEN-SORTIERUNG)
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "get_online_json") {
    header("Content-Type: application/json; charset=UTF-8");
    $json_users = [];
    
    // DEINE GENIALE SQL-ABFRAGE AUS DEM BACKUP (BLEIBT 100% ERHALTEN!)
    $get_js_users = dbquery("
        SELECT 
            IFNULL(o.online_name, IFNULL(o.user_name, o.username)) as online_user_name, 
            o.user_id, 
            IFNULL(u.user_level, 0) as u_level, 
            IFNULL(u.user_groups, '') as u_groups 
        FROM ".$online_table." o 
        LEFT JOIN ".$users_table." u ON (o.user_id = u.user_id AND o.user_id > 0) 
        WHERE (o.online_name != '' OR o.user_name != '' OR o.username != '') 
        ORDER BY online_user_name ASC
    ");
    
    if ($get_js_users && dbrows($get_js_users) > 0) {
        while ($ju = dbarray($get_js_users)) {
            $raw_name = (string)$ju['online_user_name']; 
            $level = intval($ju['u_level']); 
            $u_groups = (string)$ju['u_groups']; 
            $uid = intval($ju['user_id']);
            
            if (trim($raw_name) == "" || strtolower(trim($raw_name)) == "gast") { continue; }
            
            $clean_name = trim(str_replace(array("_Gast", "_CU"), "", $raw_name));
            $clean_name_low = strtolower($clean_name);
            
            // DIE EISERNE TEAM-WEICHE
            $ist_leitung = ($level === -103 || $level === 103 || strpos($u_groups, ".1.") !== false || strpos($u_groups, ".2.") !== false || $clean_name_low === 'dj-tomjac' || $clean_name_low === 'tomjac' || $clean_name_low === 'nachteule' || $uid === 1);
            $ist_moderator = (!$ist_leitung && (strpos($u_groups, ".3.") !== false || $level === -101 || $level === 101));
            
            // 1. SCHRANKE: Admins und du selbst fliegen RADIKAL raus (Niemals kickbar!)
            if ($ist_leitung) { continue; }
            
            // 2. SCHRANKE: Wenn es ein Moderator/DJ ist, bekommt er den Typ "mod" für die obere Kategorie
            if ($ist_moderator) {
                $json_users[] = [ "username" => $raw_name, "display" => $clean_name, "type" => "mod" ];
            } else {
                // Ansonsten ist es ein normaler Hörer oder ein Gast für die untere Kategorie
                $json_users[] = [ "username" => $raw_name, "display" => $clean_name, "type" => "user" ];
            }
        }
    }
    
    echo json_encode($json_users, JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------
// AKTION: GAST-LOGIN VERARBEITEN (FINALE BCRYPT- & FRIST-REPARATUR)
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "login_guest" && isset($_POST['login_guest_name']) && isset($_POST['login_guest_password'])) {
    
    $l_name = trim(strip_tags((string)$_POST['login_guest_name']));
    $l_pass = trim((string)$_POST['login_guest_password']);
    
    if (!empty($l_name) && !empty($l_pass)) {
        $db_l_name_sicher = addslashes($l_name);
        
        // Suffix-Sicherung: Wenn der Hörer das _CU nicht eingetippt hat, hängen wir es an
        $name_mit_suffix = (strpos($db_l_name_sicher, "_CU") === false) ? $db_l_name_sicher . "_CU" : $db_l_name_sicher;
        
        // Sucht das Konto in der Chat-Gästetabelle
        $check_konto = dbquery("SELECT * FROM ".$chatuser_table." WHERE guest_name='".$name_mit_suffix."' LIMIT 1");
        
        if ($check_konto && dbrows($check_konto) > 0) {
            $konto_row = dbarray($check_konto);
            
            // REPARIERT 1: Liest das Passwort aus der korrekten Spalte 'guest_password_hash' aus
            $db_hash = $konto_row['guest_password_hash'] ?? '';
            
            // REPARIERT 2: Nutzt die native PHP-Funktion password_verify für BCRYPT-Abgleich!
            if (password_verify($l_pass, $db_hash)) {
                
                // PRÜFUNG: Ist das Konto abgelaufen? (3-Monats-Schutz)
                if (time() > intval($konto_row['expires_at'])) {
                    echo "<script>alert('Dein Chat-Konto ist nach 3 Monaten abgelaufen. Bitte registriere dich einfach neu!'); window.location.href='rme_chat.php';</script>";
                    exit;
                }
                
                // ERFOLG: Session reaktivieren und felsenfest einbrennen!
                if (session_status() == PHP_SESSION_NONE) {
                    session_name("RME_RADIO_CHAT_SESSION");
                    @session_start();
                }
                
                $_SESSION['rme_chat_guest_name'] = $konto_row['guest_name'];
                $_SESSION['chat_user_id'] = intval($konto_row['id']); // Nutzt die eindeutige ID des Kontos
                
                // Verlängert die Laufzeit bei jedem erfolgreichen Login wieder um 3 Monate (7776000 Sek)
                $three_months_extend = time() + 7776000;
                $aktuelle_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
                dbquery("UPDATE ".$chatuser_table." SET last_ip='".addslashes($aktuelle_ip)."', expires_at='".$three_months_extend."' WHERE id='".$konto_row['id']."'");
                
                // Leitet den Hörer sofort zurück in den Chat
                header("Location: rme_chat.php");
                exit;
                
            } else {
                echo "<script>alert('Fehler: Das eingegebene Passwort ist nicht korrekt.'); window.history.back();</script>";
                exit;
            }
        } else {
            echo "<script>alert('Fehler: Dieser Chat-Name existiert nicht oder ist abgelaufen.'); window.history.back();</script>";
            exit;
        }
    }
    
    header("Location: rme_chat.php");
    exit;
}
// -----------------------------------------------------------------
// AKTION: REGISTRIERTEN GAST LÖSCHEN
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "delete_chat_user" && isset($_POST['id'])) {
    if (!$ist_leitung_safe) { die("Keine Rechte"); }
    dbquery("DELETE FROM ".$chatuser_table." WHERE id='".intval($_POST['id'])."'");
    echo "deleted_user";
    exit;
}

// -----------------------------------------------------------------
// AKTION 5: REGISTRIERTE GÄSTE ANZEIGEN (BOMBENFESTE FORM-REPARATUR)
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "view_chat_users") {
    if (!$is_admin && !(isset($_SESSION['user_level']) && $_SESSION['user_level'] <= -102)) { 
        echo "<div class='rme-admin-error-msg'>Fehler: Keine Berechtigung zum Einsehen dieser Liste.</div>";
        exit; 
    }
    
    // Prüft live im Code, ob die neue Spalte existiert – falls nicht, legt er sie vollautomatisch an!
    $spalten_check = dbquery("SHOW COLUMNS FROM ".$chatuser_table." LIKE 'pw_changed_at'");
    if ($spalten_check && dbrows($spalten_check) == 0) {
        dbquery("ALTER TABLE ".$chatuser_table." ADD pw_changed_at INT(11) NOT NULL DEFAULT 0");
    }

    $gaeste_query = dbquery("SELECT * FROM ".$chatuser_table." ORDER BY id ASC");
    if ($gaeste_query && dbrows($gaeste_query) > 0) {
        echo "<div class='rme-admin-list-container'>";
        while ($gast_row = dbarray($gaeste_query)) {
            $u_id = intval($gast_row['id']);
            $raw_name = (string)$gast_row['guest_name'];
            $clean_ip = !empty($gast_row['last_ip']) ? htmlspecialchars($gast_row['last_ip']) : '0.0.0.0';
            
            $reg_timestamp = !empty($gast_row['created_at']) ? intval($gast_row['created_at']) : time();
            $reg_time = date("d.m.Y H:i", $reg_timestamp);
            
            // Holt das Datum aus der neuen Spalte
            $pw_timestamp = isset($gast_row['pw_changed_at']) ? intval($gast_row['pw_changed_at']) : 0;
            $pw_change_time = ($pw_timestamp > 0) ? date("d.m.Y H:i", $pw_timestamp) : "Noch nie";
            
            $expiry_timestamp = !empty($gast_row['expires_at']) ? intval($gast_row['expires_at']) : (time() + 7776000);
            $expiry_time = date("d.m.Y H:i", $expiry_timestamp);
            
            $clean_name = str_replace(array("_Gast", "_CU"), "", $raw_name);
            $js_clean_name = addslashes(htmlspecialchars($clean_name, ENT_QUOTES, 'UTF-8'));
            
             // BEREINIGT: Alle Inline-Styles wurden restlos gelöscht und durch Klassen ersetzt!
            echo "<div class='rme-admin-list-row rme-admin-users-row'>
                    <span class='rme-admin-list-text-wrapper rme-admin-users-wrapper'>
                        <span class='rme-admin-user-dot'>•</span> 
                        <span class='rme-admin-user-name'>".htmlspecialchars($clean_name, ENT_QUOTES, 'UTF-8')."</span>
                        <span class='rme-admin-user-ip'>[".$clean_ip."]</span>
                        <span class='rme-admin-user-reg'>Reg: ".$reg_time."</span>
                        <span class='rme-admin-user-expiry'>Ablauf: ".$expiry_time."</span>
                        <span class='rme-admin-user-pwchange'>Passwort geändert am: <span class='rme-admin-user-pwdate'>".$pw_change_time."</span></span>						
                    </span>
                    
                    <!-- REPARIERT: Formular komplett frei von Styles -->
                    <form method='POST' action='rme_chat_backend.php?action=reset_chat_password' onsubmit='return confirm(\"Möchtest du das Passwort für ".$js_clean_name." wirklich neu setzen?\");' class='rme-admin-pw-form'>
                        <input type='hidden' name='user_id' value='".$u_id."'>
                        <input type='text' name='new_password' required placeholder='Neues PW' class='rme-admin-pw-input'>
                        <button type='submit' class='rme-reset-btn rme-admin-pw-btn'>🔑</button>
                    </form>

                    <button type='button' class='rme-unban-btn' onclick='if(confirm(\"Möchtest du das Chat-Konto von ".$js_clean_name." wirklich sofort löschen?\")){ window.parent.loescheChatUserKonto(".$u_id.", \"".$js_clean_name."\"); }'>Löschen</button>
                  </div>";
        }
        echo "</div>";
    } else { 
        echo "<div class='rme-admin-empty-msg'>Aktuell sind keine registrierten Chat-User vorhanden.</div>"; 
    }
    exit;
}

// -----------------------------------------------------------------
// AKTION: CHEF PASSWORT-RESET FÜR CHAT-KONTEN (DIREKT-FORMULAR ABFANGEN)
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "reset_chat_password") {
    if (!$is_admin && !(isset($_SESSION['user_level']) && $_SESSION['user_level'] <= -102)) { 
        die("Keine Rechte"); 
    }
    
    $target_uid = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
    $plain_new_pw = isset($_POST['new_password']) ? trim((string)$_POST['new_password']) : '';
    
    if ($target_uid > 0 && !empty($plain_new_pw)) {
        // Passwort sicher verschlüsseln
        $new_password_hash = password_hash($plain_new_pw, PASSWORD_BCRYPT);
        
        // Führt das Datenbank-Update aus
        $update_status = dbquery("UPDATE ".$chatuser_table." SET guest_password_hash='".addslashes($new_password_hash)."', pw_changed_at='".time()."' WHERE id='".$target_uid."'");
        
        if ($update_status) {

			// REPARIERT: Lädt die Hauptseite (rme_chat.php) komplett neu, damit kein Frame zerreißt!
			echo "<script>alert('Das Passwort wurde erfolgreich geändert!'); window.parent.location.href='rme_chat.php';</script>";
            exit;
        } else {
            echo "<script>alert('Datenbank-Fehler beim Speichern!'); window.history.back();</script>";
            exit;
        }
    }
    
    header("Location: rme_chat.php");
    exit;
}
// -----------------------------------------------------------------
// AKTION: ADMIN USER-KICK (TEMPORÄRE IP-SPERRE - DESIGN- & SPEICHER-FIX)
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "ban_user" && isset($_POST['target_name'])) {
    if (!$ist_leitung_safe) { die("Keine Rechte"); }
    $target_name = trim(strip_tags((string)$_POST['target_name']));
    if (!empty($target_name)) {
        $db_name_sicher = addslashes($target_name);
        $name_mit_suffix = (strpos($db_name_sicher, "_Gast") === false) ? $db_name_sicher . "_Gast" : $db_name_sicher;
        $name_ohne_suffix = str_replace("_Gast", "", $db_name_sicher);

        $get_user_info = dbquery("SELECT ip_address, user_id, username FROM ".$online_table." WHERE username='".$db_name_sicher."' OR username='".$name_mit_suffix."' OR username='".$name_ohne_suffix."' LIMIT 1");
        if ($get_user_info && dbrows($get_user_info) > 0) {
            $u_info = dbarray($get_user_info);
            $target_ip = addslashes($u_info['ip_address']);
            
            $exakter_kick_name = !empty($u_info['username']) ? $u_info['username'] : $target_name;
            $db_kick_name_sicher = addslashes($exakter_kick_name);

            dbquery("DELETE FROM ".$bans_table." WHERE ip_address='".$target_ip."'");
            
            // REPARIERT: Nutzt die vorhandene Spalte 'ban_reason', um den Namen unfehlbar mitzusichern!
            dbquery("INSERT INTO ".$bans_table." (user_id, ip_address, ban_reason, datestamp) 
                     VALUES ('".intval($u_info['user_id'])."', '".$target_ip."', 'KICK:".$db_kick_name_sicher."', '".time()."')");
            
            dbquery("DELETE FROM ".$online_table." WHERE ip_address='".$target_ip."' OR username='".$db_name_sicher."'");
            echo "banned_by_ip";
        }
    }
// 🔥 UNZERSTÖRBARER DATABASE-CLEANER: Schließt MySQL, bevor das Skript stirbt!
global $mysqli, $db_connect;
if (isset($mysqli) && $mysqli instanceof mysqli) { 
    @mysqli_close($mysqli); 
} elseif (isset($db_connect) && is_resource($db_connect)) { 
    @mysql_close($db_connect); 
} 
 exit;
}

// -----------------------------------------------------------------
// AKTION: KICK-LISTE / TEMPORÄRE SPERREN ANZEIGEN (FINALE REPARATUR)
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "view_kicklist") {
    if (!$is_admin && !(isset($_SESSION['user_level']) && $_SESSION['user_level'] <= -102)) { 
        echo "<div class='rme-admin-error-msg'>Fehler: Keine Berechtigung zum Einsehen dieser Liste.</div>";
        exit; 
    }
    
    $kick_query = dbquery("SELECT * FROM ".$bans_table." ORDER BY id DESC");
    if ($kick_query && dbrows($kick_query) > 0) {
        echo "<div class='rme-admin-list-container'>";
        while ($kick_row = dbarray($kick_query)) {
            $k_ip = htmlspecialchars((string)($kick_row['ip_address'] ?? '0.0.0.0'), ENT_QUOTES, 'UTF-8');
            $raw_reason = !empty($kick_row['ban_reason']) ? (string)$kick_row['ban_reason'] : '';
            
            // REPARIERT: Schneidet das "KICK:" vorne ab, um den echten Usernamen zu erhalten
            $k_name = 'Gekickter User';
            if (strpos($raw_reason, 'KICK:') === 0) {
                $k_name = substr($raw_reason, 5);
            }
            
            $k_name = str_replace(array("_Gast", "_CU"), "", $k_name);
            $k_name = htmlspecialchars($k_name, ENT_QUOTES, 'UTF-8');
            
            echo "<div class='rme-admin-list-row'>
                    <span class='rme-admin-list-text-wrapper'>
                        <span class='rme-admin-list-dot'>•</span> 
                        <span class='rme-admin-kick-name'>".$k_name."</span>
                        <span class='rme-admin-kick-ip'>[".$k_ip."]</span>
                    </span>
                    <button type='button' class='rme-unban-btn' onclick='if(confirm(\"Möchtest du den Kick für ".$k_name." wirklich aufheben?\")){ parent.entsperreKickIp(\"".$k_ip."\"); }'>Aufheben</button>
                  </div>";
        }
        echo "</div>";
    } else { 
        echo "<div class='rme-admin-empty-msg'>Die Kick-Liste ist leer.</div>"; 
    }
    exit;
}


// -----------------------------------------------------------------
// AKTION: TEMPORÄREN KICK AUFHEBEN
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "unban_kick_ip" && isset($_POST['ip'])) {
    if (!$ist_leitung_safe) { die("Keine Rechte"); }
    dbquery("DELETE FROM ".$bans_table." WHERE ip_address='".addslashes($_POST['ip'])."'");
    echo "unbanned_kick";
    exit;
}
// -----------------------------------------------------------------
// AKTION: PERMANENTER BANNER-FIX
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "blacklist_user" && isset($_POST['target_name'])) {
    if (!$ist_leitung_safe) { die("Keine Rechte"); }
    $target_name = trim(strip_tags((string)$_POST['target_name']));
    
    if (!empty($target_name)) {
        $db_name_sicher = addslashes($target_name);
        $name_mit_suffix = (strpos($db_name_sicher, "_Gast") === false) ? $db_name_sicher . "_Gast" : $db_name_sicher;
        $name_ohne_suffix = str_replace("_Gast", "", $db_name_sicher);

        $get_user_info = dbquery("SELECT ip_address, user_id FROM ".$online_table." WHERE username='".$db_name_sicher."' OR username='".$name_mit_suffix."' OR username='".$name_ohne_suffix."' LIMIT 1");
        
        if ($get_user_info && dbrows($get_user_info) > 0) {
            $u_info = dbarray($get_user_info);
            $target_ip = addslashes($u_info['ip_address']);
            $target_uid = intval($u_info['user_id']);

            $check_exists = dbquery("SELECT id FROM ".$blacklist_table." WHERE ip_address='".$target_ip."'");
            if (dbrows($check_exists) == 0) {
                dbquery("INSERT INTO ".$blacklist_table." (user_id, username, ip_address, banned_at) VALUES ('".$target_uid."', '".$db_name_sicher."', '".$target_ip."', '".time()."')");
            }

            dbquery("DELETE FROM ".$online_table." WHERE ip_address='".$target_ip."' OR username='".$db_name_sicher."'");
            echo "banned_by_ip";
        }
    }
// 🔥 UNZERSTÖRBARER DATABASE-CLEANER: Schließt MySQL, bevor das Skript stirbt!
global $mysqli, $db_connect;
if (isset($mysqli) && $mysqli instanceof mysqli) { 
    @mysqli_close($mysqli); 
} elseif (isset($db_connect) && is_resource($db_connect)) { 
    @mysql_close($db_connect); 
}
    exit;
}

// -----------------------------------------------------------------
// AKTION: BLACKLIST / PERMANENTE BANNS ANZEIGEN
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "view_bannlist") {
    if (!$is_admin && !(isset($_SESSION['user_level']) && $_SESSION['user_level'] <= -102)) { 
        echo "<div class='rme-admin-error-msg'>Fehler: Keine Berechtigung zum Einsehen dieser Liste.</div>";
        exit; 
    }
    
    $bann_query = dbquery("SELECT * FROM ".$blacklist_table." ORDER BY id DESC");
    if ($bann_query && dbrows($bann_query) > 0) {
        echo "<div class='rme-admin-list-container'>";
        while ($bann_row = dbarray($bann_query)) {
            $raw_b_name = !empty($bann_row['username']) ? $bann_row['username'] : 'Unbekannter Gast';
            $b_name = str_replace(array("_Gast", "_CU"), "", $raw_b_name);
            $b_ip = !empty($bann_row['ip_address']) ? htmlspecialchars((string)$bann_row['ip_address'], ENT_QUOTES, 'UTF-8') : '';
            
            // REPARIERT: IP-Addon nutzt jetzt eine saubere CSS-Klasse statt Inline-Style
            $ip_addon = ($b_ip !== '') ? " <span class='rme-admin-bann-ip'>[".$b_ip."]</span>" : '';
            
            // REPARIERT: Inline-Styles komplett entfernt und durch Klassen ersetzt
            echo "<div class='rme-admin-list-row'>
                    <span class='rme-admin-list-text-wrapper'>
                        <span class='rme-admin-list-dot'>•</span> 
                        <span class='rme-admin-bann-name'>".htmlspecialchars($b_name, ENT_QUOTES, 'UTF-8')."</span>".$ip_addon."
                    </span>
                    <button type='button' class='rme-unban-btn' onclick='if(confirm(\"Möchtest du den permanenten Bann für ".htmlspecialchars($b_name, ENT_QUOTES, 'UTF-8')." wirklich aufheben?\")){ parent.entsperreBannUser(\"".addslashes($raw_b_name)."\"); }'>Aufheben</button>
                  </div>";
        }
        echo "</div>"; 
    } else { 
        echo "<div class='rme-admin-empty-msg'>Die Blacklist ist leer.</div>"; 
    }
    exit;
}

// -----------------------------------------------------------------
// AKTION: PERMANENTEN BANN AUFHEBEN
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "unban_black_user") {
    if (!$ist_leitung_safe) { die("Keine Rechte"); }
    $target_name = $_POST['username'] ?? $_POST['target_name'] ?? '';
    $target_name = trim(strip_tags((string)$target_name));
    
    if (!empty($target_name)) {
        dbquery("DELETE FROM ".$blacklist_table." WHERE username='".addslashes($target_name)."'");
        echo "unbanned_black";
    } else {
        echo "error_no_name";
    }
    exit;
}

// -----------------------------------------------------------------
// AKTION: LIVE-NACHRICHTEN AUSLESEN (KICK-SCHUTZ & REAKTIVIERUNG)
// -----------------------------------------------------------------
if (isset($_GET['action']) && ($_GET['action'] == "read" || $_GET['action'] == "history")) {

    $mein_aktueller_name_fuer_check = !empty($safe_chat_user_name_db) ? trim($safe_chat_user_name_db) : '';
    
    // Rettungsbrücke für private Fenster: AJAX-Namen auslesen
    $ajax_auth_name_get = isset($_GET['admin_auth_name']) ? trim(strip_tags((string)$_GET['admin_auth_name'])) : '';
    if (($mein_aktueller_name_fuer_check === "" || $mein_aktueller_name_fuer_check === "undefined") && !empty($ajax_auth_name_get)) {
        $mein_aktueller_name_fuer_check = $ajax_auth_name_get;
    }

    if ($mein_aktueller_name_fuer_check === "" || $mein_aktueller_name_fuer_check === "undefined") {
        $mein_aktueller_name_fuer_check = "Gast_" . substr(md5($user_ip), 0, 4);
    // ... (Hier werden Deine originalen Nachrichten per foreach ausgegeben) ...
    
    // 📱 HANDY-RETTUNG: Zwingt den mobilen Browser, die Verbindung SOFORT zu kappen!
    // Dadurch wird die LTE-Leitung augenblicklich für Deinen nächsten Schreib-Befehl frei!
    if (!headers_sent()) {
        header("Connection: close");
    }
    
    exit;
} // Hier schließt sich die if ($_GET['action'] == "read" ...) Weiche

    // =========================================================================
    // 🔒 EISERNE FLÜSTER-SCHRANKE V5 (DIE ULTIMATIVE ABSENDER-RETTUNG)
    // =========================================================================
    $mein_sicherer_name_fuer_filter = addslashes(trim($mein_aktueller_name_fuer_check));
    $mein_sauberer_name_ohne_cu = str_replace(array("_Gast", "_CU"), "", $mein_aktueller_name_fuer_check);
    $mein_sicherer_name_clean_filter = addslashes(trim($mein_sauberer_name_ohne_cu));

    // Live-Rettung: Wir holen deine echte user_id direkt aus der Onlinetabelle, 
    // weil die normale Session durch session_write_close() oft schon gelöscht ist!
    $id_rettungs_query = dbquery("SELECT user_id FROM " . $online_table . " WHERE username='" . $mein_sicherer_name_fuer_filter . "' OR username='" . $mein_sicherer_name_clean_filter . "' LIMIT 1");
    $meine_reale_live_id = 0;
    if ($id_rettungs_query && dbrows($id_rettungs_query) > 0) {
        $meine_reale_live_id = intval(dbarray($id_rettungs_query)['user_id']);
    }

    // Wenn keine ID in der Onlinetabelle war, nehmen wir den Standard-Fallback
    if ($meine_reale_live_id === 0 && isset($session_userid)) {
        $meine_reale_live_id = intval($session_userid);
    }

    // Die wasserdichte Weiche:
    $sql_sicheres_auslesen = "SELECT * FROM " . $chat_table . " 
        WHERE (message NOT LIKE '%/w %') 
           OR (message LIKE '%/w " . $mein_sicherer_name_clean_filter . " %') 
           OR (message LIKE '%/w " . $mein_sicherer_name_clean_filter . "_CU %') 
           OR (guest_name = '" . $mein_sicherer_name_fuer_filter . "' AND guest_name <> '') 
           OR (guest_name = '" . $mein_sicherer_name_clean_filter . "' AND guest_name <> '')
           OR (user_id = " . $meine_reale_live_id . " AND user_id > 0)
        ORDER BY id DESC LIMIT 18";

    $result = dbquery($sql_sicheres_auslesen);

    
    $nachrichten_stapel = []; 
    if ($result && dbrows($result) > 0) { 
        while ($row = dbarray($result)) { $nachrichten_stapel[] = $row; } 
    }
    $nachrichten_stapel = array_reverse($nachrichten_stapel);
    
    if (!empty($nachrichten_stapel)) {
        foreach ($nachrichten_stapel as $msg) {
            
            // =========================================================================
            // REPARIERT: SCHNELL-LÖSCH-ID FÜR DIE ARCHIV-ROW (FÜR DAS JAVASCRIPT)
            // =========================================================================
            $sichere_msg_id = intval($msg['id']);
            
            // 👑 EISERNE CHEF-ERKENNUNG DIREKT IM ARCHIV-LOOP
            $ich_bin_der_wahre_boss = (
                (isset($_SESSION['chat_user_id']) && intval($_SESSION['chat_user_id']) === 18) || 
                (isset($_SESSION['chat_user_name']) && strtolower($_SESSION['chat_user_name']) === 'dj-tomjac') ||
                (isset($_SESSION['rme_chat_user_id']) && intval($_SESSION['rme_chat_user_id']) === 18)
            );

            // Wir leiten das HTML Deiner Archiv-Zeile ein und verpassen ihr die ID,
            // damit das JavaScript die Box nach dem Löschen live ausblenden kann!
            // (Passe hier ggf. Dein echtes Chat-Row-HTML an, falls Du ein anderes Tag nutzt)
            echo '<div class="rme-chat-row" id="rme-arch-row-'.$sichere_msg_id.'" style="position:relative;">';
            
            // =========================================================================

            // HIER LÄUFT JETZT DEIN ORIGINALER INNEN-CODE DER FOREACH-SCHLEIFE WEITER!
            // Also dort, wo Name, Datum, Uhrzeit und Dein gerade reparierter Text-Retter-Filter ausgegeben werden...

            // =========================================================================
            // 🔥 SOUND-TARNKAPPE V6: JETZT MIT MULTI-DETEKTOR & 15-SEKUNDEN-WHEEL-PURGE
            // =========================================================================
            if (isset($msg['guest_name']) && trim((string)$msg['guest_name']) === 'SYSTEM_SOUND') {
                $sichere_sound_id = intval($msg['id']);
                $roher_sound_inhalt = (string)$msg['message'];
                $sound_zeitstempel = intval($msg['datestamp']);

                // =========================================================================
                // 🎰 FALL A1: DAS HÖRER-GLÜCKSRAD & ORAKEL (ID 777 - REAGIERT AUF WHEEL_WIN:)
                // =========================================================================
                if (strpos($roher_sound_inhalt, 'WHEEL_WIN:') === 0) {
                    $sauberes_html = str_replace('WHEEL_WIN:', '', $roher_sound_inhalt);
                    
                    // Rendert die lila-grüne Spiele-Box in den Chat
                    echo "<div class='rme-chat-row' id='rme-wheel-msg-".$sichere_sound_id."'>" . $sauberes_html . "</div>";
                    
                    // Nutzt deine perfekt funktionierende Löschschleife (15 Sekunden Purge)
                    if (time() - $sound_zeitstempel > 15) {
                        if ($sichere_sound_id > 0) { dbquery("DELETE FROM " . $chat_table . " WHERE id = " . $sichere_sound_id); }
                    }
                    continue; 
                }

                // =========================================================================
                // 🔊 NEU FALL A3: DAS HÖRER-SOUNDBOARD (ID 988 - REAGIERT AUF HOERER_SND:)
                // =========================================================================
                if (strpos($roher_sound_inhalt, 'HOERER_SND:') === 0) {
                    $sauberes_sound_html = str_replace('HOERER_SND:', '', $roher_sound_inhalt);
                    
                    // Rendert die gelbe Zeile exakt einzeilig in den Chat-Stream
                    echo "<div class='rme-chat-row' id='rme-msg-row-".$sichere_sound_id."'>" . $sauberes_sound_html . "</div>";
                    
                    // Nutzt exakt dieselbe unzerstörbare Löschlogik wie dein Orakel!
                    if (time() - $sound_zeitstempel > 15) {
                        if ($sichere_sound_id > 0) { dbquery("DELETE FROM " . $chat_table . " WHERE id = " . $sichere_sound_id); }
                    }
                    continue; 
                }


                // =========================================================================
                // ❌⭕ FALL A2: INTERAKTIVES TIC-TAC-TOE (NUR NOCH OVERWINDOW-STEUERUNG!)
                // =========================================================================
                if (strpos($roher_sound_inhalt, 'TTT_ARENA:') === 0) {
                    $sauberes_html = str_replace('TTT_ARENA:', '', $roher_sound_inhalt);
                    
                    // 🎯 BLOCKADE: Wir geben das HTML HIER NICHT MEHR in den Chat-Verlauf aus!
                    // Dadurch verschwindet das zweite, störende Fenster bei den Nachrichten komplett.
                    
                    // Wir prüfen trotzdem, ob das Spiel laut Nachricht beendet ist
                    $spiel_beendet = (strpos($sauberes_html, '🎉 SIEG') !== false || strpos($sauberes_html, '🤝 UNENTSCHIEDEN') !== false);
                    
                    if ($spiel_beendet) {
                        // REINIGUNGS-BLITZ: Sofortige restlose Vernichtung des Beitrags aus der Chat-Tabelle!
                        // Kein 20-Sekunden-Warten mehr, damit das Spiel augenblicklich beendet wird.
                        if ($sichere_sound_id > 0) { 
                            dbquery("DELETE FROM " . $chat_table . " WHERE id = " . $sichere_sound_id); 
                        }
                    }
                    
                    continue; // Springt sofort lautlos zur nächsten Nachricht weiter, ohne Müll im Chat zu hinterlassen!
                }

                // 🎰 FALL B: Es ist ein Glücksrad-Fehler (Cooldown - 10 Sekunden Purge)
                if (strpos($roher_sound_inhalt, 'WHEEL_ERROR:') === 0) {
                    $fehler_nachricht = str_replace('WHEEL_ERROR:', '', $roher_sound_inhalt);
                    
                    echo "<div class='rme-chat-row' style='background:rgba(204,36,36,0.15) !important; border-left:4px solid #cc2424 !important; padding:8px 12px !important; margin:4px 0 !important; border-radius:4px !important; color:#ffffff !important; font-size:13px !important; font-weight:bold !important; box-shadow: 0 0 10px rgba(204,36,36,0.2);'>" . htmlspecialchars($fehler_nachricht, ENT_QUOTES, 'UTF-8') . "</div>";
                    
                    if (time() - $sound_zeitstempel > 10) {
                        if ($sichere_sound_id > 0) { dbquery("DELETE FROM " . $chat_table . " WHERE id = " . $sichere_sound_id); }
                    }
                    continue;
                }

                // 🔊 FALL C: Das universelle Datenbank-BLOB-Soundboard (DJs & Hörer auf ID 999!)
                if (strpos($roher_sound_inhalt, 'SOUND:') === 0) {
                    // Wir übergeben das Signal exakt so, wie es aus der DB kommt (z.B. SOUND:hoerer_tusch)
                    echo "<script id='rme-sound-msg-".$sichere_sound_id."' class='rme-hidden-sound-trigger' data-file='" . htmlspecialchars($roher_sound_inhalt, ENT_QUOTES, 'UTF-8') . "'></script>";
                    
                    if (time() - $sound_zeitstempel > 4) {
                        if ($sichere_sound_id > 0) { dbquery("DELETE FROM " . $chat_table . " WHERE id = " . $sichere_sound_id); }
                    }
                    continue; 
                }

            // =========================================================================
            // 🔊 HÖRER-SOUNDBOARD: SEPARATER RIEGEL FÜR ID 988 (AUSSERHALB VON SYSTEM_SOUND!)
            // =========================================================================
            if (isset($msg['guest_name']) && trim((string)$msg['guest_name']) === 'SYSTEM_HOERER' && intval($msg['user_id']) === 988) {
                $sichere_msg_id = intval($msg['id']);
                $nachrichten_text = (string)$msg['message'];
                $zeitstempel = intval($msg['datestamp']);

                if (strpos($nachrichten_text, 'SOUND:') !== false) {
                    $teile = explode('SOUND:', $nachrichten_text);
                    $reines_html = $teile[0];
                    $sound_datei_raw = isset($teile[1]) ? trim($teile[1]) : '';
                    $reiner_kommando_name = str_replace('.mp3', '', strtolower($sound_datei_raw));

                    // 1. Gelben Info-Text in den Chat rendern
                    echo "<div class='rme-chat-row' id='rme-msg-row-".$sichere_msg_id."'>" . $reines_html . "</div>";

                    // 2. Den unsichtbaren Audio-Trigger für das JS auswerfen
                    $globalAudioPfad = "play_sound.php?command=" . urlencode($reiner_kommando_name);


// =========================================================================
// 🗑️ DER UNZERSTÖRBARE FRONTEND-VISUAL-PURGER (100% FRUSTFREI)
// =========================================================================
$sichere_msg_id_purger = intval($msg['id']);
$msg_user_id_check = intval($msg['user_id']);
$msg_datestamp_check = intval($msg['datestamp']);
$reiner_text_fuer_purge = (string)$msg['message'];

// Wir nutzen die exakt gleiche Zeitberechnung wie bei Deiner Uhrzeit-Anzeige!
$echte_deutsche_nachrichten_zeit = $msg_datestamp_check + 7200;
$aktuelle_deutsche_zeit = time() + 7200;

// Wenn die Nachricht älter als 20 Sekunden ist, feuern wir den optischen Müllschlucker ab
if (($aktuelle_deutsche_zeit - $echte_deutsche_nachrichten_zeit) > 20) {
    $muss_geloescht_werden = false;

    // Scannt Glücksrad, Slots, Orakel, Karten, Live-Reaktionen & Zahlen-Duell
    if ($msg_user_id_check === 777 || $msg_user_id_check === 988 ||
        strpos($reiner_text_fuer_purge, 'Glücksrad') !== false || 
        strpos($reiner_text_fuer_purge, 'Slot-Machine') !== false || 
        strpos($reiner_text_fuer_purge, 'magische Kugel') !== false ||
        strpos($reiner_text_fuer_purge, 'Karten-Duell') !== false ||
        strpos($reiner_text_fuer_purge, 'Live-Reaktion') !== false ||
        strpos($reiner_text_fuer_purge, 'Reaktion') !== false ||
        strpos($reiner_text_fuer_purge, 'Zahlen-Duell') !== false) {
        $muss_geloescht_werden = true;
    }

    if ($muss_geloescht_werden && $sichere_msg_id_purger > 0) {
        // 🎯 DER MASTER-TRICK: Wir löschen die Zeile aus der Datenbank...
        dbquery("DELETE FROM " . $chat_table . " WHERE id = " . $sichere_msg_id_purger);
        
        // 🎯 ...und schicken ein unsichtbares JS-Kommando an ALLE Browser, das die Box sofort vom Bildschirm fegt!
        // Da die ID der Reihe im Chat meistens "rme-msg-row-ID" oder ähnlich heißt, blenden wir sie über jQuery aus.
        echo "<script>
            setTimeout(function() {
                jQuery('#rme-msg-row-".$sichere_msg_id_purger."').fadeOut(400, function() { jQuery(this).remove(); });
                jQuery('#rme-wheel-msg-".$sichere_msg_id_purger."').fadeOut(400, function() { jQuery(this).remove(); });
                jQuery('[id*=\"-".$sichere_msg_id_purger."\"]').fadeOut(400, function() { jQuery(this).remove(); });
            }, 100);
        </script>";
        
        continue; // Bricht ab, damit kein alter Text mehr gerendert wird!
    }
}

            // =========================================================================
            // 🔊 FLIEẞTEXT HÖRER-SOUND DETEKTOR MIT INTEGRIERTEM BLITZ-MÜLLSCHLUCKER
            // =========================================================================
            if (isset($msg['message']) && strpos($msg['message'], 'SOUND:') !== false) {
                $sichere_msg_id = intval($msg['id']);
                $msg_datestamp_check = intval($msg['datestamp']);
                
                // 🎯 RETTUNG: Wenn der Sound-Text älter als 30 Sekunden ist, fegen wir ihn DIREKT HIER weg!
                if (time() - $msg_datestamp_check > 30) {
                    dbquery("DELETE FROM " . $chat_table . " WHERE id = " . $sichere_msg_id);
                    continue; // Löscht die Nachricht aus der DB und überspringt die Anzeige komplett!
                }

                // Wenn sie noch frisch ist, ganz normal für den Ton verarbeiten
                $teile = explode('SOUND:', $msg['message']);
                $msg['message'] = isset($teile[0]) ? $teile[0] : $msg['message']; 
                $sound_datei_raw = isset($teile[1]) ? trim($teile[1]) : '';
                $reiner_kommando_name = str_replace('.mp3', '', strtolower($sound_datei_raw));

                $globalAudioPfad = "play_sound.php?command=" . urlencode($reiner_kommando_name);
                echo "<script id='rme-sound-msg-".$sichere_msg_id."' class='rme-hidden-sound-trigger' data-file='" . htmlspecialchars($globalAudioPfad, ENT_QUOTES, 'UTF-8') . "'></script>";
                
                // Wir erzwingen hier KEIN voreiliges continue mehr, damit nachfolgende Text-Zuweisungen weiterlaufen können!
            }
		}
	}
}

            $roher_datestamp = isset($msg['datestamp']) ? intval($msg['datestamp']) : time();
            $deutsche_zeit_nachricht = $roher_datestamp + 7200; 
            
            $date_time = date("d.m.Y H:i", $deutsche_zeit_nachricht);

            $msg_user_id = intval($msg['user_id']);
            $db_level = 0;
            $db_groups = '';
            $roher_name = "Hörer";

            if (isset($msg['guest_name']) && trim((string)$msg['guest_name']) !== "") {
                $roher_name = trim((string)$msg['guest_name']);
            }

            if ($msg_user_id > 0 && $msg_user_id < 100) {
                $u_search = dbquery("SELECT user_name, user_level, user_groups FROM ".$users_table." WHERE user_id='".$msg_user_id."' LIMIT 1");
                if ($u_search && dbrows($u_search) > 0) {
                    $u_row = dbarray($u_search);
                    $roher_name = $u_row['user_name'];
                    $db_level = intval($u_row['user_level']);
                    $db_groups = (string)$u_row['user_groups'];
                }
            } 
            else if ($msg_user_id >= 100 && $roher_name === "Hörer") {
                $online_name_search = dbquery("SELECT username FROM ".$online_table." WHERE user_id='".$msg_user_id."' LIMIT 1");
                if ($online_name_search && dbrows($online_name_search) > 0) {
                    $roher_name = dbarray($online_name_search)['username'];
                } else {
                    $roher_name = "Hörer"; 
                }
            }

            // =========================================================================
            // 🎯 STEP 4: RECHTE- UND REINIGUNGS-CHECKS (DEIN ORIGINAL-GEFÜGE)
            // =========================================================================
            $ist_ein_chat_user = (strpos((string)$roher_name, '_CU') !== false || $msg_user_id === 1000 || ($msg_user_id >= 1000 && $msg_user_id < 2000));
            $sauberer_name = str_replace(array("_Gast", "_CU"), "", $roher_name);
            $sauberer_name_low = strtolower(trim($sauberer_name));
            
            $ist_ein_gast = ((strpos(strtolower($roher_name), 'gast') !== false || $msg_user_id >= 2000 || $msg_user_id === 0) && !$ist_ein_chat_user);

            $msg_ist_leitung = false;
            $msg_ist_moderator = false;

            if (!$ist_ein_gast) {
                if ($db_groups === '' && !$ist_ein_chat_user) {
                    $u_name_search = dbquery("SELECT user_level, user_groups FROM ".$users_table." WHERE user_name='".addslashes($sauberer_name)."' LIMIT 1");
                    if ($u_name_search && dbrows($u_name_search) > 0) {
                        $u_name_row = dbarray($u_name_search);
                        $db_level = intval($u_name_row['user_level']);
                        $db_groups = (string)$u_name_row['user_groups'];
                    }
                }

                if (strpos($db_groups, ".1.") !== false || strpos($db_groups, ".2.") !== false || $db_level === -103 || $sauberer_name_low === 'dj-tomjac') {
                    $msg_ist_leitung = true;
                } elseif (strpos($db_groups, ".3.") !== false || $db_level === -101) {
                    $msg_ist_moderator = true;
                }
            }

            // =========================================================================
            // 🔥 METRIC-NEON: LIVE-FARBWAHL SENSOR FÜR TEAM-MITGLIEDER (KLASSEN-FIX)
            // =========================================================================
            $custom_neon_style = ""; // Standardmäßig leer
            $farbe_wurde_gewaehlt = false;

            if ($msg_ist_leitung || $msg_ist_moderator || $sauberer_name_low === "dj-tomjac") {
                $msg_user_id_int = intval($msg_user_id);
                $color_lookup = dbquery("SELECT admin_color FROM fusionb7754_chat_admin_settings WHERE admin_id = '".$msg_user_id_int."' LIMIT 1");
                
                if ($color_lookup && dbrows($color_lookup) > 0) {
                    $color_row = dbarray($color_lookup);
                    $gespeicherte_farbe = trim($color_row['admin_color']);
                    
                    // Nur wenn eine echte Farbe gewechselt wurde (und NICHT das RGB-Lauflicht)
                    if (!empty($gespeicherte_farbe) && $gespeicherte_farbe !== "rgb_matrix") {
                        $custom_neon_style = " style='color: ".$gespeicherte_farbe." !important; text-shadow: 0 0 5px ".$gespeicherte_farbe."; background: none !important; animation: none !important;' ";
                        $farbe_wurde_gewaehlt = true; // Sensor aktivieren!
                    }
                }
            }

            // DIE INTELIGENTE KLASSEN-WEICHE:
            // Wenn eine feste Neon-Farbe aktiv ist, entziehen wir dem Namen die RGB-Klasse,
            // damit die Animation gestoppt wird und die Wunschfarbe erstrahlen kann!
            if (!$ist_ein_gast && $sauberer_name_low === "dj-tomjac") {
                $name_class = $farbe_wurde_gewaehlt ? "rme-user-logged" : "rme-rgb-hadmin";
            } elseif ($msg_ist_leitung) {
                $name_class = $farbe_wurde_gewaehlt ? "rme-user-logged" : "rme-rgb-username";
            } elseif ($msg_ist_moderator) {
                $name_class = "rme-moderator-username";
            } elseif (!$ist_ein_gast || $ist_ein_chat_user) {
                $name_class = "rme-user-logged";
            } else {
                $name_class = "rme-name-guest";
            }
           // =========================================================================
            // 1. ROHTEXT HOCHHOLEN, DEKODIEREN & UNBLOCKIERBARES HERZ-RETTEN
            // =========================================================================
            $msg_body = html_entity_decode((string)($msg['message'] ?? ''), ENT_QUOTES, 'UTF-8');
            $msg_body = htmlspecialchars_decode($msg_body, ENT_QUOTES);
            $msg_body = stripslashes($msg_body);

            // DIE RETTUNG FÜR DAS HERZ: Wird direkt als erstes als stabile Emoji eingebrannt!
            $herzHtml = '<span style="font-size: 1.4em !important; line-height: 1 !important; display: inline-block !important; vertical-align: middle !important; margin: 0 2px !important;">❤️</span>';
            $msg_body = str_ireplace('<3', $herzHtml, $msg_body);

            // =========================================================================
            // 2. BB-CODES & STYLES ERSETZEN
            // =========================================================================
            $msg_body = preg_replace('/\[b\](.*?)\[\/b\]/is', '<b>$1</b>', $msg_body);
            $msg_body = preg_replace('/\[i\](.*?)\[\/i\]/is', '<em>$1</em>', $msg_body);
            $msg_body = preg_replace('/\[u\](.*?)\[\/u\]/is', '<u>$1</u>', $msg_body);
			$msg_body = preg_replace('/\[color=(.*?)\](.*?)\[\/color\]/is', '<span style="color:$1;">$2</span>', $msg_body);
			$msg_body = preg_replace('/\[color&#61;(.*?)\](.*?)\[\/color\]/is', '<span style="color:$1;">$2</span>', $msg_body);

            // 🎯 MASTER-FIX: DIE BRANDNEUE DYNAMISCHE CHAT-STYLE ENGINE (ÜBERSETZT [style=...])
            $msg_body = preg_replace('/\[style=(.*?)\](.*?)\[\/style\]/is', '<span style="$1">$2</span>', $msg_body);

            // Flaggen einpacken
            $msg_body = preg_replace_callback('/(&#x1F1[E-Z][0-9A-F];)+/i', function($matches) {
                return "<span class='flagge' style='font-size: 1.0em !important;'>" . $matches[0] . "</span>";
            }, $msg_body);
            // =========================================================================
            // REPARIERT: ALL-IN-ONE SMILEY ENGINE FÜR DATENBANK & SYSTEM-SMILEYS (LIVE)
            // =========================================================================
            // 1. Erst die klassischen System-Emojis verarbeiten, falls das Array existiert
            if (isset($systemEmojis) && is_array($systemEmojis)) {
                foreach ($systemEmojis as $kat => $smilies) {
                    foreach ($smilies as $code => $emoji) { 
                        $emojiHtml = '<span style="font-size: 1.4em !important; line-height: 1 !important; display: inline-block !important; vertical-align: middle !important; margin: 0 2px !important;">'.$emoji.'</span>';
                        if (in_array($code, [':D', ':', ':(', ';('])) {
                            $quoted_code = preg_quote($code, '/');
                            $msg_body = preg_replace('/(?<!\w)' . $quoted_code . '(?!\w)/i', $emojiHtml, $msg_body);
                        } else {
                            $msg_body = str_ireplace($code, $emojiHtml, $msg_body); 
                        }
                    }
                }
            }

            // 2. Jetzt alle Grafik- und System-Smileys aus Deiner MySQL-Tabelle streamen
            $db_smilies_query = dbquery("SELECT `id`, `kuerzel` FROM fusionb7754_chat_smilies");
            
            if ($db_smilies_query && dbrows($db_smilies_query) > 0) {
                $sortierte_db_smilies = [];
                while ($db_smiley = dbarray($db_smilies_query)) {
                    $sortierte_db_smilies[] = $db_smiley;
                }
                
                usort($sortierte_db_smilies, function($a, $b) {
                    return strlen($b['kuerzel']) - strlen($a['kuerzel']);
                });

                foreach ($sortierte_db_smilies as $s_data) {
                    $s_code = $s_data['kuerzel'];
                    $s_id   = intval($s_data['id']);

                    if (str_ireplace($s_code, '', $msg_body) !== $msg_body) {
                        $imgWidth = ($s_code === ":bravo:") ? "5.0em" : "1.8em";
                        $html_img = "<img src='rme_smilies_handler.php?action=render_image&id=".$s_id."' class='rme-gif-item rme-chat-stream-smiley' style='width:".$imgWidth."; height:1.8em !important; min-height:1.8em !important; vertical-align:middle; display:inline-block; max-width:none;' title='".htmlspecialchars($s_code, ENT_QUOTES, 'UTF-8')."'>";
                        $msg_body = str_ireplace($s_code, $html_img, $msg_body);
                    }
                }
            }
            // =========================================================================

             // =========================================================================
            // 🔥 SOUND-TARNKAPPE: 100% FLACKER- UND VERSCHIEBUNGSFREI!
            // =========================================================================
            if (isset($msg['guest_name']) && $msg['guest_name'] === 'SYSTEM_SOUND') {
                // Wir übergeben den Sound als echtes, unsichtbares Script-Signal!
                // Das erzeugt physisch NULL Text im HTML und kann den Chat NIEMALS nach oben schieben!
                echo "<script class='rme-hidden-sound-trigger' data-file='" . htmlspecialchars($msg['message'], ENT_QUOTES, 'UTF-8') . "'></script>";
                continue; // ⚡ SENSATIONELL: Überspringt das HTML-Div komplett! Null Verschiebung!
            }
            // =========================================================================

            // =========================================================================
            // 🔥 METRIC-NEON: LIVE-FARBWAHL SENSOR FÜR TEAM-MITGLIEDER
            // =========================================================================
            $custom_neon_style = ""; // Standardmäßig leer

            if ($msg_ist_leitung || $msg_ist_moderator || $sauberer_name_low === "dj-tomjac") {
                // Wir holen die gespeicherte Farbe für diesen Admin/Mod aus der eigenen Chat-Tabelle
                $msg_user_id_int = intval($msg_user_id);
                $color_lookup = dbquery("SELECT admin_color FROM fusionb7754_chat_admin_settings WHERE admin_id = '".$msg_user_id_int."' LIMIT 1");
                
                if ($color_lookup && dbrows($color_lookup) > 0) {
                    $color_row = dbarray($color_lookup);
                    $gespeicherte_farbe = trim($color_row['admin_color']);
                    
                    // Nur wenn KEINE RGB-Matrix gewünscht ist, setzen wir die feste Neon-Farbe ein
                    if (!empty($gespeicherte_farbe) && $gespeicherte_farbe !== "rgb_matrix") {
                        $custom_neon_style = " style='color: ".$gespeicherte_farbe." !important; text-shadow: 0 0 5px ".$gespeicherte_farbe.";' ";
                    }
                }
            }

            // =========================================================================
            // 4. DER GOLDENE ENTSTÖR-SCHLÜSSEL (UNIVERSAL-GERADESTAND FÜR JEDE MSG!)
            // =========================================================================
            $msg_body = htmlspecialchars($msg_body, ENT_QUOTES, 'UTF-8');
            $msg_body = htmlspecialchars_decode($msg_body, ENT_QUOTES);

            $final_text = $msg_body;

            // SCHRITT A: Wir prüfen, ob die Nachricht ein Flüster-Befehl ist (JETZT PERFEKT GEKLAMMERT!)
            $phpFluesterRegex = '/^([\s\S]*?)\/w\s+([a-zA-Z0-9_\-]+)\s+([\s\S]*)/i';

            if (preg_match($phpFluesterRegex, $final_text, $treffer)) {
                $geoeffneteTags     = $treffer[1]; // Das [style=...] oder <span style="...">
                $fluesterEmpfaenger = trim($treffer[2]); // Der Name des Empfängers
                $eigentlicherText   = $treffer[3]; // Die Nachricht mitsamt Emojis
                
                $schliessungsTags = "";
                if (stripos($geoeffneteTags, '<b') !== false) $schliessungsTags .= "</b>";
                if (stripos($geoeffneteTags, '<em') !== false) $schliessungsTags .= "</em>";
                if (stripos($geoeffneteTags, '<i') !== false) $schliessungsTags .= "</i>";
                if (stripos($geoeffneteTags, '<u') !== false) $schliessungsTags .= "</u>";
                if (stripos($geoeffneteTags, '<span') !== false) $schliessungsTags .= "</span>";

                // INTELLIGENTER RECHTS-DREHER: Wer spricht mit wem?
                $mein_aktueller_name_low = strtolower(trim($mein_aktueller_name_fuer_check));
                $empfaenger_low = strtolower($fluesterEmpfaenger);
                
                if ($mein_aktueller_name_low === $empfaenger_low || $mein_aktueller_name_low === $empfaenger_low . "_cu") {
                    // Jemand flüstert DICH an -> Zeige an, VON wem es kommt!
                    $labelHTML = '✉️ <span class="rme-whisper-chat-glow">[Flüstern von ' . htmlspecialchars($sauberer_name, ENT_QUOTES, 'UTF-8') . ']:</span> ';
                } else {
                    // DU flüsterst jemanden an -> Zeige an, AN wen es geht!
                    $labelHTML = '✉️ <span class="rme-whisper-chat-glow">[Flüstern an ' . htmlspecialchars($fluesterEmpfaenger, ENT_QUOTES, 'UTF-8') . ']:</span> ';
                }
                
                // Fügt das Label und den Text fehlerfrei zusammen
                $final_text = $labelHTML . $geoeffneteTags . $eigentlicherText . $schliessungsTags;
            }



            // 🔥 SCHRITT B: DIE UNIVERSAL-EMOTICON-RETTUNG (NUN FÜR FLÜSTERN UND NORMALEN CHAT!)
            // Wir jagen die präzisen Riegel-Ersetzungen über das $final_text, 
            // damit Bilder und Emojis physikalisch NIEMALS mehr kursiv oder unterstrichen werden können!
            
            // 1. Schutz für echte IMG-Tags (FTP & MySQL)
            $final_text = preg_replace('/<img([^>]*?)(style="[^"]*?")?([^>]*?)>/i', '<img$1 style="font-style:normal !important; font-weight:normal !important; text-decoration:none !important; border:none !important;"$3>', $final_text);
            
            // 2. Schutz für die generierten System-Smiley-Spans (Zwingt die Text-Emojis im Browser in den GeradESTAND!)
            $final_text = preg_replace('/<span style="font-size:\s*1\.4em[^">]*">(.*?)<\/span>/i', '<span style="font-size: 1.4em !important; line-height: 1 !important; display: inline-block !important; vertical-align: middle !important; margin: 0 2px !important; font-style: normal !important; font-weight: normal !important; text-decoration: none !important;">$1</span>', $final_text);


            // IP FÜR JEDE CHAT-ZEILE ABSICHERN
            $sichere_zeilen_ip = !empty($msg['ip_address']) ? htmlspecialchars($msg['ip_address'], ENT_QUOTES, 'UTF-8') : (!empty($msg['user_ip']) ? htmlspecialchars($msg['user_ip'], ENT_QUOTES, 'UTF-8') : '0.0.0.0');
            $js_sicherer_name  = addslashes((string)$sauberer_name);
            $html_sicherer_name = htmlspecialchars($sauberer_name, ENT_QUOTES, 'UTF-8');

            // =========================================================================
            // 🔎 ECHTE FORENSIK-BRÜCKE: COUPLING AN DEINE SEPARATEN NEW-SPALTEN
            // =========================================================================
            $db_os_zeile      = "Unbekannt";
            $db_browser_zeile = "Unbekannt";
            $db_device_zeile  = "💻";
            
            // 1. Wir holen uns die echten, getrennten Werte aus Deiner Online-Tabelle
            $os_suche = dbquery("SELECT user_os, user_browser, user_device FROM ".$online_table." WHERE user_id='".intval($msg['user_id'])."' LIMIT 1");
            if ($os_suche && dbrows($os_suche) > 0) {
                $os_row = dbarray($os_suche);
                if (!empty($os_row['user_os']))      { $db_os_zeile      = trim((string)$os_row['user_os']); }
                if (!empty($os_row['user_browser'])) { $db_browser_zeile = trim((string)$os_row['user_browser']); }
                if (!empty($os_row['user_device']))  { $db_device_zeile  = trim((string)$os_row['user_device']); }
            }
            
            // 2. Krisensicheres Text-Archiv Fallback (Falls der User gerade offline gegangen ist)
            if ($db_os_zeile === "Unbekannt" || $db_os_zeile === "") {
                if (preg_match('/\[os:([a-z]{3})\]/i', $msg['message'], $os_treffer)) {
                    $code = strtolower($os_treffer[1]);
                    if ($code === "win")      { $db_os_zeile = "Windows (PC)"; }
                    elseif ($code === "lin")  { $db_os_zeile = "Linux-System"; }
                    elseif ($code === "and")  { $db_os_zeile = "Android (Mobil)"; $db_device_zeile = "📱"; }
                    elseif ($code === "ios")  { $db_os_zeile = "iPhone/iOS"; $db_device_zeile = "📱"; }
                    elseif ($code === "mac")  { $db_os_zeile = "Mac OS"; }
                }
            }

            // 3. Waschvorgang für das sichtbare Text-Fenster
            $final_text = preg_replace('/\[os:[a-z]{3}\]/i', '', $final_text);
            $final_text = str_ireplace(array('[os:win]', '{os:win}', 'os:win', '[os:lin]', '{os:lin}', 'os:lin', '[os:and]', '{os:and}', 'os:and', '[os:ios]', '{os:ios}', 'os:ios', '[os:mac]', '{os:mac}', 'os:mac'), '', $final_text);
            $final_text = trim((string)$final_text);
            
            // 4. LIVE-TEXT ZÄHLER DIREKT FÜR DIESE CHAT-ZEILE INTERGRIEREN
            $anzahl_nachrichten_zeile = 0;
            $msg_user_id_numerisch = intval($msg['user_id']);
            if (!empty($chat_table)) {
                if ($msg_user_id_numerisch > 0 && $msg_user_id_numerisch < 2000) {
                    $spam_check_zeile = dbquery("SELECT COUNT(*) as gesamt_texte FROM " . $chat_table . " WHERE user_id='" . $msg_user_id_numerisch . "'");
                } else {
                    $spam_check_zeile = dbquery("SELECT COUNT(*) as gesamt_texte FROM " . $chat_table . " WHERE guest_name='" . $js_sicherer_name . "' OR guest_name='" . $js_sicherer_name . "_CU'");
                }
                if ($spam_check_zeile && dbrows($spam_check_zeile) > 0) { $anzahl_nachrichten_zeile = intval(dbarray($spam_check_zeile)['gesamt_texte']); }
            }

            // Maskieren für den JavaScript-Transport
            $js_sicheres_os      = addslashes($db_os_zeile);
            $js_sicherer_browser = addslashes($db_browser_zeile);
            $js_sicheres_device  = addslashes($db_device_zeile);
            // =========================================================================

            // =========================================================================
            // 🔥 METRIC-NEON: UNZERSTÖRBARE CSS-KLASSEN-WEICHE (WEISS-EFFEKT-KILLER)
            // =========================================================================
            $team_farbe_klasse = "";
            $sauberer_reiner_name = $html_sicherer_name; // Fallback

            if ($msg_ist_leitung || $msg_ist_moderator || $sauberer_name_low === "dj-tomjac") {
                $msg_user_id_int = intval($msg['user_id']); 
                $color_lookup = dbquery("SELECT admin_color FROM fusionb7754_chat_admin_settings WHERE admin_id = '".$msg_user_id_int."' LIMIT 1");
                
                if ($color_lookup && dbrows($color_lookup) > 0) {
                    $color_row = dbarray($color_lookup);
                    $gespeicherte_farbe = trim($color_row['admin_color']);
                    
                    // Wenn eine feste Farbe gewaehlt wurde (and NICHT das RGB-Lauflicht):
                    if (!empty($gespeicherte_farbe) && $gespeicherte_farbe !== "rgb_matrix") {
                        
                        // ABSOLUT SICHER: Wir filtern alles außer Zahlen und Buchstaben heraus, 
                        // damit wir IMMER den sauberen HEX-Wert ohne '#' bekommen!
                        $reiner_hex_code = preg_replace('/[^a-zA-Z0-9]/', '', $gespeicherte_farbe);
                        
                        // Wir kleben die Oberklasse und die spezifische Farbklasse fehlerfrei zusammen
                        $team_farbe_klasse = " rme-admin-base-style rme-neon-color-".$reiner_hex_code;
                        
                        // Wir werfen sämtlichen alten HTML-Farb-Müll aus dem Namen raus
                        $sauberer_reiner_name = strip_tags($html_sicherer_name);
                        $sauberer_reiner_name = rtrim($sauberer_reiner_name, ':'); 
                    }
                }
            }

// =========================================================================
// ⚡ METRIC-NEON: DJ-ZAPPER BB-CODE ÜBERSETZER (REIN OPTISCHES RENDERING)
// =========================================================================
$ist_zapper_ansage = false;

if (strpos((string)$final_text, '[zapp]') !== false || (isset($msg['message']) && strpos((string)$msg['message'], '[zapp]') !== false)) {
    $ist_zapper_ansage = true;
    
    // 1. Wir isolieren den reinen Text und rasieren alle Klammern radikal weg
    $reiner_ansage_text = str_replace(array('[zapp]', '[/zapp]'), '', $final_text);
    $reiner_ansage_text = preg_replace('/\[style=[^\]]*\]/i', '', $reiner_ansage_text);
    $reiner_ansage_text = preg_replace('/\[\/style\]/i', '', $reiner_ansage_text);
    
    // Rasiert auch eventuelle Reste von [os:win] oder []-Klammern am Ende weg
    $reiner_ansage_text = preg_replace('/\[os:[^\]]*\]/i', '', $reiner_ansage_text);
    $reiner_ansage_text = preg_replace('/\[browser:[^\]]*\]/i', '', $reiner_ansage_text);
    $reiner_ansage_text = preg_replace('/\[device:[^\]]*\]/i', '', $reiner_ansage_text);
    $reiner_ansage_text = trim(strip_tags($reiner_ansage_text));
    
    // 2. Namens-Abfrage für den Absender aus Deinem System
    $ansage_sender = "DJ";
    if (isset($msg['name']) && $msg['name'] !== "System" && !empty($msg['name'])) {
        $ansage_sender = strtoupper(trim((string)$msg['name']));
    } elseif (isset($sauberer_reiner_name) && !empty($sauberer_reiner_name)) {
        $ansage_sender = strtoupper(rtrim(trim(strip_tags($sauberer_reiner_name)), ':'));
    } elseif (isset($html_sicherer_name) && !empty($html_sicherer_name)) {
        $ansage_sender = strtoupper(rtrim(trim(strip_tags($html_sicherer_name)), ':'));
    }
    
    // 3. 🎯 KEINE DB-QUERIES MEHR HIER!
    // Der Sound wird jetzt exakt 1x beim Absenden erzeugt.
    // Das verhindert die Endlosschleife und schont die Server-Performance.
    
    // 4. Wir bauen das saubere Neon-Rote Gehäuse (VÖLLIG JS-FREI UND REIN!)
    $zapper_html = "<div class='rme-zapper-alert-box' style='font-size: 22px !important; line-height: 1.4 !important; width: 100% !important; box-sizing: border-box !important;'>";
    $zapper_html .= "  ⚡ [WICHTIGE DJ-ANSAGE VON " . $ansage_sender . "]: <span style='color:#fff; text-shadow:none; font-weight:bold;'> " . $reiner_ansage_text . "</span> ⚡";
    $zapper_html .= "</div>";
}

// =========================================================================
// DER BLITZSAUBERE AUSGABE-STAPEL AN DEN BROWSER
// =========================================================================
// WENN ES EIN ZAPPER IST: Zeichnen wir AUSSCHLIESSLICH die fette Box!
if (isset($ist_zapper_ansage) && $ist_zapper_ansage) {
    echo $zapper_html;
} else {
	
    // NORMALER CHAT-TEXT: Euer unberührtes Original-Design!
    echo "<div class='rme-chat-row' id='msg-id-".$msg['id']."' style='margin-bottom:10px; line-height:1.5; word-wrap:break-word;'>";
    echo "  <span class='rme-neon-time'>[".$date_time."]</span>";
    
    // Im Chatverlauf übergeben wir hinten immer \"--:--\", da die Live-Berechnung nur für die Onlineliste gilt!
    if (!empty($team_farbe_klasse)) {
        echo "  <span class='chat-live-msg-user".$team_farbe_klasse."' data-ip='".$sichere_zeilen_ip."' onclick='event.stopPropagation(); rmeShowUserContextMenu(event, \"".$js_sicherer_name."\", \"".$sichere_zeilen_ip."\", \"\", null, \"".$js_sicheres_os."\", \"".$js_sicherer_browser."\", \"".$js_sicheres_device."\", \"".$anzahl_nachrichten_zeile."\", \"Live im Verlauf\", \"--:--\");' style='cursor:pointer;'>".$sauberer_reiner_name.":</span>";
    } else {
        echo "  <span class='chat-live-msg-user ".$name_class."' data-ip='".$sichere_zeilen_ip."' onclick='event.stopPropagation(); rmeShowUserContextMenu(event, \"".$js_sicherer_name."\", \"".$sichere_zeilen_ip."\", \"\", null, \"".$js_sicheres_os."\", \"".$js_sicherer_browser."\", \"".$js_sicheres_device."\", \"".$anzahl_nachrichten_zeile."\", \"Live im Verlauf\", \"--:--\");' style='cursor:pointer;'>".$html_sicherer_name.":</span>";
    }
// =========================================================================   


// =========================================================================
// 🔒 REPARATUR-KERN: 5-SEKUNDEN-POLL GEGEN ID 0 ABSICHERN
// =========================================================================
// Egal welche Funktion im Backend gerade den Online-Status aktualisiert:
// Wir fangen die ID und den Namen ab, BEVOR MySQL irgendwas speichert!

if (isset($sichere_digital_id) || isset($sichere_speicher_id)) {
    $aktuelle_id_check = intval($sichere_digital_id ?? ($sichere_speicher_id ?? 0));
    $aktueller_name_check = !empty($finaler_sitzungs_name) ? $finaler_sitzungs_name : (!empty($final_guest_name) ? $final_guest_name : ($ajax_get_name ?? ''));
    
    $name_low = strtolower(trim($aktueller_name_check));
    
    // Chef-Schutz
    if ($name_low === 'dj-tomjac' || $name_low === 'tomjac') {
        $sichere_digital_id = 18;
        $sichere_speicher_id = 18;
    }
    // Eiserner Chatuser-Riegel: Sobald ein _CU im Namen mitschwimmt, wird die 0 VERBOTEN!
    elseif (strpos($name_low, '_cu') !== false || $name_low === 'hammerhai66') {
        if ($aktuelle_id_check <= 0) {
            // Wir holen die ID direkt aus der Gästetabelle
            $db_check = dbquery("SELECT id FROM fusionb7754_chat_guest_accounts WHERE guest_name='" . addslashes($aktueller_name_check) . "' LIMIT 1");
            if ($db_check && dbrows($db_check) > 0) {
                $neue_id = intval(dbarray($db_check)['id']);
                $sichere_digital_id = $neue_id;
                $sichere_speicher_id = $neue_id;
            } else {
                $sichere_digital_id = 1000;
                $sichere_speicher_id = 1000;
            }
        }
    }
}
// =========================================================================



    echo "  <span class='chat-live-msg-text'> ".$final_text."</span>";
    echo "</div>";
}
            
        } // Schließt die Nachrichten-Ausgabe
    } else { 
        echo "<div style='color:#fff; font-weight:bold; text-align:center; padding:40px;'>Der Chatroom ist leer. Sei der Erste, der hier reinschreibt..</div>"; 
    }

    // =========================================================================
    // 🎆 SCHRITT 3: AUSGABE-SENSOR GEEICHT AUF DEINE FEUERWERKS-TABELLE
    // =========================================================================
    $feuerwerk_check = dbquery("SELECT COUNT(*) as aktiv FROM fusionb7754_chat_feuerwerk WHERE style_type LIKE '%/firework_command_trigger%'");
    if ($feuerwerk_check && dbrows($feuerwerk_check) > 0) {
        $fw_row = dbarray($feuerwerk_check);
        if (intval($fw_row['aktiv']) > 0) {
            // Schweißt den unsichtbaren Kommentar ans Ende des gesamten Datenstroms
            echo "<!-- /firework_command_trigger -->";
        }
    }

    // 🛠️ AUTOMATISCHER MÜLLSCHLUCKER: Löscht das Signal nach 4 Sekunden vollautomatisch
    dbquery("DELETE FROM fusionb7754_chat_feuerwerk WHERE style_type LIKE '%/firework_command_trigger%' AND (" . time() . " - triggered_at) > 4");
    // =========================================================================

    exit; 
}

// 🏆 ENDPUNKT: HOLT DIE TOP 50 QUIZ-SPIELER AUS DER DATENBANK
if (isset($_GET['action']) && $_GET['action'] == 'get_quiz_highscores') {
    header('Content-Type: application/json; charset=utf-8');
    
    // Teste, ob die Funktion dbquery existiert
    if (!function_exists('dbquery')) {
        echo json_encode(array(array('username' => 'SYSTEM-ERROR', 'punkte' => 'Core fehlt')));
        exit;
    }
    
    $sql = "SELECT username, punkte FROM fusionb7754_chat_quiz_punkte ORDER BY punkte DESC LIMIT 50";
    $result = dbquery($sql);
    $highscores = array();
    $schleifen_notbremse = 0; // 🚨 DER RETTUNGS-ANKER
    
    if ($result && dbrows($result) > 0) {
        while ($row = dbarray($result)) {
            $schleifen_notbremse++;
            if ($schleifen_notbremse > 60) { // Mehr als 50 Einträge gibt es durch das LIMIT eh nicht!
                break; // Notstopp: Verhindert die CPU-Endlosschleife!
            }
            
            $highscores[] = array(
                'username' => htmlspecialchars($row['username']),
                'punkte'   => (int)$row['punkte']
            );
        }
    } // 🌟 HIER WAR DIE FEHLENDE KLAMMER FÜR DAS IF!
    
    echo json_encode($highscores);
    exit;
}

// -----------------------------------------------------------------
// AKTION: CHAT-ARCHIV ANZEIGEN - OPTIMIERT GEGEN LOCKS
// -----------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == "archive_view") {
    // REPARATUR-KERN: Zwingt PHP, auch das Archiv nach deutscher Uhrzeit (inkl. Sommerzeit) zu berechnen!
    date_default_timezone_set('Europe/Berlin');

    if (!$is_admin && !(isset($_SESSION['user_level']) && $_SESSION['user_level'] <= -102)) { 
        echo "<div style='color:#ff3333; font-weight:bold; text-align:center; padding:40px 15px; font-size:15px;'>Fehler: Keine Berechtigung zum Einsehen dieser Liste.</div>";
        exit; 
    }
    
    // ... Hier läuft dein restlicher Archiv-Code (SQL-Abfrage etc.) völlig unverändert weiter ...

    
    // =========================================================================
    // 🔒 EISERNE ARCHIV-SCHRANKE (DATENSCHUTZ AUF TABELLEN-EBENE!)
    // =========================================================================
    $mein_filter_name_db = isset($session_username) ? addslashes(trim($session_username)) : '';
    $mein_sauberer_name_archiv = str_replace(array("_Gast", "_CU"), "", $mein_filter_name_db);
    $mein_filter_clean_db = addslashes(trim($mein_sauberer_name_archiv));
    $meine_sichere_archiv_uid = isset($session_userid) ? intval($session_userid) : 0;

    // Filtert fremdes Flüstern direkt beim Laden der 300 Einträge heraus!
    $sql_archiv_sicher = "SELECT m.*, 
        CASE WHEN m.user_id > 0 THEN u.user_name ELSE '' END as mitglied_name, 
        CASE WHEN m.user_id > 0 THEN IFNULL(u.user_level, 0) ELSE 0 END as u_level, 
        CASE WHEN m.user_id > 0 THEN IFNULL(u.user_groups, '') ELSE '' END as u_groups 
        FROM ".$chat_table." m 
        LEFT JOIN ".$users_table." u ON (m.user_id = u.user_id AND m.user_id > 0) 
        WHERE (m.message NOT LIKE '%/w %') 
           OR (m.message LIKE '%/w " . $mein_filter_clean_db . " %') 
           OR (m.message LIKE '%/w " . $mein_filter_clean_db . "_CU %') 
           OR (m.guest_name = '" . $mein_filter_name_db . "' AND m.guest_name <> '') 
           OR (m.guest_name = '" . $mein_filter_clean_db . "' AND m.guest_name <> '')
           OR (m.user_id = " . $meine_sichere_archiv_uid . " AND m.user_id > 0)
        ORDER BY m.id ASC LIMIT 300";

    $result = dbquery($sql_archiv_sicher);
    $nachrichten_stapel = []; 
    if ($result && dbrows($result) > 0) { 
        while ($row = dbarray($result)) { $nachrichten_stapel[] = $row; } 
    }
    if (!empty($nachrichten_stapel)) {
        foreach ($nachrichten_stapel as $msg) {
            
            // =========================================================================
            // REPARIERT: ALLTIME-CHEF-DETEKTOR (LÖSCHKREUZ FLIEGT NACH HINTEN)
            // =========================================================================
            $sichere_msg_id = intval($msg['id'] ?? 0);
            
            // 👑 UNZERSTÖRBARER ADMIN-PASS (Garantiert sichtbares X)
            $ich_bin_der_wahre_boss = (
                (isset($_SESSION['chat_user_id']) && intval($_SESSION['chat_user_id']) === 18) || 
                (isset($_SESSION['chat_user_name']) && strtolower($_SESSION['chat_user_name']) === 'dj-tomjac') ||
                (isset($_SESSION['user_id']) && intval($_SESSION['user_id']) === 18) ||
                (isset($userdata['user_id']) && intval($userdata['user_id']) === 18) ||
                (defined('iADMIN'))
            );

            // Jede Zeile im Archiv bekommt ihr Gehäuse für das JavaScript-Ausblenden
            echo '<div class="rme-chat-row" id="rme-arch-row-'.$sichere_msg_id.'" style="position:relative; display:block; width:100%;">';
            
            // Wir bereiten den Mülleimer vor, geben ihn aber ERST HINTER DEM TEXT aus!
            $html_loesch_button = "";
            if ($ich_bin_der_wahre_boss && $sichere_msg_id > 0) {
                // Das Mülleimer-Symbol mit edlem Abstand für das Zeilenende
                $html_loesch_button = '<span onclick="rmeDeleteSingleText('.$sichere_msg_id.', event);" style="color:#cc2424 !important; font-weight:bold !important; margin-left:12px !important; cursor:pointer !important; font-size:12px !important; font-family:sans-serif !important; display:inline-block; user-select:none; transition:transform 0.1s;" onmouseover="this.style.transform=\'scale(1.25)\';" onmouseout="this.style.transform=\'scale(1)\';" title="Diesen Text unwiderruflich löschen">🗑️</span>';
            }
            // =========================================================================

            // HIER IST DEINE ORIGINAL-ZEILE VOM STAPEL START:
            $time = date("d.m.Y H:i", $msg['datestamp'] ?? time());




            // ARCHIV-RETTUNG: TEXT DEKODIEREN
            $clean_msg_text = html_entity_decode((string)$msg['message'], ENT_QUOTES, 'UTF-8');
            $clean_msg_text = htmlspecialchars_decode($clean_msg_text, ENT_QUOTES);
            
            // FORMATIERUNGEN IM ARCHIV ENTPACKEN
            $clean_msg_text = preg_replace('/\[b\](.*?)\[\/b\]/is', '<b>$1</b>', $clean_msg_text);
            $clean_msg_text = preg_replace('/\[i\](.*?)\[\/i\]/is', '<em>$1</em>', $clean_msg_text);
            $clean_msg_text = preg_replace('/\[u\](.*?)\[\/u\]/is', '<u>$1</u>', $clean_msg_text);
            $clean_msg_text = preg_replace('/\[style=(.*?)\](.*?)\[\/style\]/is', '<span style="$1">$2</span>', $clean_msg_text);

            // 🔥 FIX: Index [0] für die Archiv-Flaggen wieder eingesetzt!
            $clean_msg_text = preg_replace_callback('/(&#x1F1[E-Z][0-9A-F];)+/i', function($matches) {
                return "<span class='flagge' style='font-size: 1.0em !important;'>" . $matches[0] . "</span>";
            }, $clean_msg_text);

            // =========================================================================
            // REPARIERT: PURE DATABASE-BLOB SMILEY-ENGINE IM ARCHIV (FINAL & FEHLERFREI)
            // =========================================================================
            // 1. Wir starten, indem wir den rohen Text aus Deiner Datenbank als Basis nehmen
            $final_archive_text = htmlspecialchars($clean_msg_text, ENT_QUOTES, 'UTF-8');
            $final_archive_text = htmlspecialchars_decode($final_archive_text, ENT_QUOTES);

            // 2. Erst die klassischen System-Emojis im Archiv verarbeiten
            if (isset($systemEmojis) && is_array($systemEmojis)) {
                foreach ($systemEmojis as $kat => $smilies) {
                    foreach ($smilies as $code => $emoji) { 
                        $emojiHtml = '<span style="font-size: 1.4em !important; line-height: 1 !important; display: inline-block !important; vertical-align: middle !important; margin: 0 2px !important;">'.$emoji.'</span>';
                        if (in_array($code, [':D', ':', ':(', ';('])) {
                            $quoted_code = preg_quote($code, '/');
                            $final_archive_text = preg_replace('/(?<!\w)' . $quoted_code . '(?!\w)/i', $emojiHtml, $final_archive_text);
                        } else {
                            $final_archive_text = str_ireplace($code, $emojiHtml, $final_archive_text); 
                        }
                    }
                }
            }

            // 3. Jetzt alle Grafik- und System-Smileys aus Deiner MySQL-Tabelle streamen (ARCHIV-FIX)
            $db_smilies_query = dbquery("SELECT `id`, `kuerzel` FROM fusionb7754_chat_smilies");
            
            if ($db_smilies_query && dbrows($db_smilies_query) > 0) {
                $sortierte_db_smilies = [];
                while ($db_smiley = dbarray($db_smilies_query)) {
                    $sortierte_db_smilies[] = $db_smiley;
                }
                
                usort($sortierte_db_smilies, function($a, $b) {
                    return strlen($b['kuerzel']) - strlen($a['kuerzel']);
                });

                foreach ($sortierte_db_smilies as $s_data) {
                    $s_code = $s_data['kuerzel'];
                    $s_id   = intval($s_data['id']);

                    // ⚡ PERFORMANCE-GUARD: Nur ersetzen, wenn der Smiley-Code wirklich im Archivtext existiert!
                    if (str_ireplace($s_code, '', $final_archive_text) !== $final_archive_text) {
                        $imgWidth = ($s_code === ":bravo:") ? "5.0em" : "1.8em";
                        
                        // 🌟 DIE RETTUNG: Jetzt mit exakt denselben CSS-Klassen wie im Live-Chat gegen das Quetschen!
                        $html_img = "<img src='rme_smilies_handler.php?action=render_image&id=".$s_id."' class='rme-gif-item rme-chat-stream-smiley' style='width:".$imgWidth."; height:1.8em !important; min-height:1.8em !important; vertical-align:middle; display:inline-block; max-width:none; object-fit: contain !important;' title='".htmlspecialchars($s_code, ENT_QUOTES, 'UTF-8')."'>";
                        
                        $final_archive_text = str_ireplace($s_code, $html_img, $final_archive_text);
                    }
                }
            }
            // =========================================================================


            // =========================================================================
            // ⚡ ARCHIV-FIX: REIN OPTISCHER ZAPPER-ÜBERSETZER (NULL DATENBANK-LAST)
            // =========================================================================
            if (strpos((string)$final_archive_text, '[zapp]') !== false) {
                // 1. Wir isolieren den reinen Text und rasieren alle Klammern radikal weg
                $reiner_ansage_text = str_replace(array('[zapp]', '[/zapp]'), '', $final_archive_text);
                $reiner_ansage_text = preg_replace('/\[style=[^\]]*\]/i', '', $reiner_ansage_text);
                $reiner_ansage_text = preg_replace('/\[\/style\]/i', '', $reiner_ansage_text);
                $reiner_ansage_text = preg_replace('/\[os:[^\]]*\]/i', '', $reiner_ansage_text);
                $reiner_ansage_text = preg_replace('/\[browser:[^\]]*\]/i', '', $reiner_ansage_text);
                $reiner_ansage_text = preg_replace('/\[device:[^\]]*\]/i', '', $reiner_ansage_text);
                $reiner_ansage_text = trim(strip_tags($reiner_ansage_text));
                
                // 2. Absender für das Archiv bestimmen
                $ansage_sender = "DJ";
                if (isset($msg['guest_name']) && !empty($msg['guest_name'])) {
                    $ansage_sender = strtoupper(trim((string)$msg['guest_name']));
                } elseif (isset($msg['mitglied_name']) && !empty($msg['mitglied_name'])) {
                    $ansage_sender = strtoupper(trim((string)$msg['mitglied_name']));
                }
                $ansage_sender = str_replace(array("_GIEST", "_CU", "_GAST"), "", $ansage_sender);

                // 3. Wir bauen das saubere Neon-Rote Gehäuse (Völlig ohne DB-Befehle!)
                $final_archive_text = "<div class='rme-zapper-alert-box' style='font-size: 22px !important; line-height: 1.4 !important; width: 100% !important; box-sizing: border-box !important;'>";
                $final_archive_text .= "  ⚡ [WICHTIGE DJ-ANSAGE VON " . htmlspecialchars($ansage_sender, ENT_QUOTES, 'UTF-8') . "]: <span style='color:#fff; text-shadow:none; font-weight:bold;'> " . $reiner_ansage_text . "</span> ⚡";
                $final_archive_text .= "</div>";
            }
            // =========================================================================
      
            $final_archive_text = stripslashes($final_archive_text);

            // 🔥 MASTER-FIX FOR ARCHIVE: Das Style-Tag wird nun auf der korrekten Variable verarbeitet!
            if (strpos($final_archive_text, '[style=') !== false) {
                $final_archive_text = preg_replace_callback('/\[style=(.*?)\](.*?)\[\/style\]/is', function($matches) {
                    $style_inhalt = $matches[1];
                    $text_inhalt = $matches[2];
                    
                    preg_match('/font-size:\s*([0-9]+)/i', $style_inhalt, $groessen_treffer);
                    $pixel_zahl = isset($groessen_treffer[1]) ? intval($groessen_treffer[1]) : 16;
                    $zusatz_line_height = ($pixel_zahl > 24) ? "line-height: normal !important; display: inline-block !important;" : "";
                    
                    $sicheres_css = str_replace([';', 'px'], [' !important;', 'px'], $style_inhalt);
                    $sicheres_css = preg_replace('/font-size:\s*([0-9]+)px/i', 'font-size: ' . $pixel_zahl . 'px !important', $sicheres_css);
                    
                    return '<span style="' . $sicheres_css . $zusatz_line_height . '">' . $text_inhalt . '</span>';
                }, $final_archive_text);
            }
            
            $anzeige_name = (!empty($msg['guest_name'])) ? trim($msg['guest_name']) : (!empty($msg['mitglied_name']) ? trim($msg['mitglied_name']) : "Hörer");
            $sauberer_autoren_name_archiv = str_replace(array("_Gast", "_CU"), "", $anzeige_name);
            $anzeige_name = $sauberer_autoren_name_archiv;
            
            // =========================================================================
            // 🔥 REPARATUR-JOKER: COCKPIT-SYNCHRONISATION FÜR DIE ARCHIV-FARBEN (REPARIERT)
            // =========================================================================
            $uid = intval($msg['user_id']);
            $level = 0;
            $u_groups = "";

            // Ein User ist ein Gast, wenn er "gast" im Namen hat ODER seine ID im Gastbereich (ab 2000 / gleich 0) liegt
            $archiv_ist_gast = (strpos(strtolower($sauberer_autoren_name_archiv), 'gast') !== false || $uid >= 2000 || $uid === 0);
            
            // Wenn es kein Gast ist, holen wir die echten Ränge live aus der Benutzer-Tabelle
            if (!$archiv_ist_gast && !empty($sauberer_autoren_name_archiv)) {
                $archiv_user_check = dbquery("SELECT user_level, user_groups FROM " . $users_table . " WHERE user_name='" . addslashes($sauberer_autoren_name_archiv) . "' LIMIT 1");
                if ($archiv_user_check && dbrows($archiv_user_check) > 0) {
                    $archiv_user_row = dbarray($archiv_user_check);
                    $level = intval($archiv_user_row['user_level']);
                    $u_groups = (string)$archiv_user_row['user_groups'];
                }
            }

            // Eindeutige Erkennung (Chef-Joker greift vorweg, Moderatoren Level 101/102 eingebunden)
            $sauberer_name_low = strtolower(trim($sauberer_autoren_name_archiv));
            $ist_leitung = (!$archiv_ist_gast && ($level === 103 || $level === -103 || strpos($u_groups, ".1.") !== false || strpos($u_groups, ".2.") !== false || $sauberer_name_low === 'dj-tomjac' || $sauberer_name_low === 'tomjac'));
            $ist_moderator = (!$archiv_ist_gast && !$ist_leitung && (strpos($u_groups, ".3.") !== false || $level === 102 || $level === 101 || $level === -101));
            $badge_html = "";
            
            if ($sauberer_name_low === "dj-tomjac" || $sauberer_name_low === "tomjac" || $uid === 1) { 
                $name_class = "rme-rgb-hadmin";
            } elseif ($ist_leitung) { 
                $name_class = "rme-rgb-username";
            } elseif ($ist_moderator) { 
                $name_class = "rme-moderator-username";
            } elseif (!$archiv_ist_gast && $uid > 0 && $uid < 2000) { 
                // Nur echte, registrierte HP-User bekommen hier das Hörer-Blau!
                $name_class = "rme-user-logged"; 
            } else { 
                // Alle Gäste ab ID 2000 leuchten jetzt brav in sauberem Weiß!
                $name_class = "rme-name-guest"; 
            }
            // =========================================================================
       
            // 🔥 FLÜSTER-RECHTSDREHER FÜR DAS ARCHIV
            $phpFluesterRegex = '/^([\s\S]*?)\/w\s+([a-zA-Z0-9_\-]+)\s+([\s\S]*)/i';
            if (preg_match($phpFluesterRegex, $final_archive_text, $treffer)) {
                $geoeffneteTags     = $treffer[1];
                $fluesterEmpfaenger = trim($treffer[2]);
                $eigentlicherText   = $treffer[3];
                
                $schliessungsTags = "";
                if (stripos($geoeffneteTags, '<b') !== false) $schliessungsTags .= "</b>";
                if (stripos($geoeffneteTags, '<em') !== false) $schliessungsTags .= "</em>";
                if (stripos($geoeffneteTags, '<i') !== false) $schliessungsTags .= "</i>";
                if (stripos($geoeffneteTags, '<u') !== false) $schliessungsTags .= "</u>";
                if (stripos($geoeffneteTags, '<span') !== false) $schliessungsTags .= "</span>";

                $mein_aktueller_name_low = strtolower(trim($mein_filter_name_db ?? ''));
                $empfaenger_low = strtolower($fluesterEmpfaenger);
                
                if ($mein_aktueller_name_low === $empfaenger_low || $mein_aktueller_name_low === $empfaenger_low . "_cu") {
                    $labelHTML = '✉️ <span class="rme-whisper-chat-glow" style="font-size: 16px !important; color: #ff00ff !important;">[Flüstern von ' . htmlspecialchars($anzeige_name, ENT_QUOTES, 'UTF-8') . ']:</span> ';
                } else {
                    $labelHTML = '✉️ <span class="rme-whisper-chat-glow" style="font-size: 16px !important;">[Flüstern an ' . htmlspecialchars($fluesterEmpfaenger, ENT_QUOTES, 'UTF-8') . ']:</span> ';
                }
                
                $final_archive_text = $labelHTML . $geoeffneteTags . $eigentlicherText . $schliessungsTags;
            }
            echo "<div class='rme-chat-row' style='margin-bottom:10px; line-height:1.5; font-size:16px !important;'>";
            // =========================================================================
            // 🔎 ARCHIV-FORENSIK-BRÜCKE: KOPPLUNG AN DIE NEUEN SEPARATEN SPALTEN
            // =========================================================================
            $sichere_archiv_ip = !empty($msg['ip_address']) ? htmlspecialchars($msg['ip_address'], ENT_QUOTES, 'UTF-8') : (!empty($msg['user_ip']) ? htmlspecialchars($msg['user_ip'], ENT_QUOTES, 'UTF-8') : '0.0.0.0');
            $js_sicherer_name  = addslashes((string)$anzeige_name);

            $db_os_archiv      = !empty($msg['user_os']) ? trim((string)$msg['user_os']) : 'Unbekannt';
            $db_browser_archiv = !empty($msg['user_browser']) ? trim((string)$msg['user_browser']) : 'Unbekannt';
            $db_device_archiv  = !empty($msg['user_device']) ? trim((string)$msg['user_device']) : '💻';

            // Falls es sich um einen uralten Eintrag handelt, greift das krisensichere Text-Archiv Fallback
            if ($db_os_archiv === "Unbekannt" || $db_os_archiv === "") {
                if (preg_match('/\[os:([a-z]{3})\]/i', $msg['message'], $os_treffer)) {
                    $code = strtolower($os_treffer[1]);
                    if ($code === "win")      { $db_os_archiv = "Windows (PC)"; }
                    elseif ($code === "lin")  { $db_os_archiv = "Linux-System"; }
                    elseif ($code === "and")  { $db_os_archiv = "Android (Mobil)"; $db_device_archiv = "📱"; }
                    elseif ($code === "ios")  { $db_os_archiv = "iPhone/iOS"; $db_device_archiv = "📱"; }
                    elseif ($code === "mac")  { $db_os_archiv = "Mac OS"; }
                }
            }

            // Textreinigung von alten OS-Shortcode-Resten (NUR wenn es KEINE Zapper-Ansage ist!)
            if (strpos((string)$final_archive_text, 'rme-zapper-alert-box') === false) {
                $final_archive_text = preg_replace('/\[os:[a-z]{3}\]/i', '', $final_archive_text);
                $final_archive_text = str_ireplace(array('[os:win]', '{os:win}', 'os:win', '[os:lin]', '{os:lin}', 'os:lin', '[os:and]', '{os:and}', 'os:and', '[os:ios]', '{os:ios}', 'os:ios', '[os:mac]', '{os:mac}', 'os:mac'), '', $final_archive_text);
            }
            $final_archive_text = trim((string)$final_archive_text);

            // Wir holen den realen Nachrichtenzähler für diese Archiv-Zeile (HP-User vs Gast)
            $anzahl_nachrichten_archiv = 0;
            $msg_uid_numerisch = intval($msg['user_id']);
            if (!empty($chat_table)) {
                if ($msg_uid_numerisch > 0 && $msg_uid_numerisch < 2000) {
                    $spam_check_archiv = dbquery("SELECT COUNT(*) as gesamt_texte FROM " . $chat_table . " WHERE user_id='" . $msg_uid_numerisch . "'");
                } else {
                    $spam_check_archiv = dbquery("SELECT COUNT(*) as gesamt_texte FROM " . $chat_table . " WHERE guest_name='" . $js_sicherer_name . "' OR guest_name='" . $js_sicherer_name . "_CU'");
                }
                if ($spam_check_archiv && dbrows($spam_check_archiv) > 0) { $anzahl_nachrichten_archiv = intval(dbarray($spam_check_archiv)['gesamt_texte']); }
            }

            $js_sicheres_os      = addslashes($db_os_archiv);
            $js_sicherer_browser = addslashes($db_browser_archiv);
            $js_sicheres_device  = addslashes($db_device_archiv);
            // =========================================================================

            // Macht den Namen auch im Archiv anklickbar mitsamt aller Forensik-Daten!
            echo "  <span class='rme-neon-time'>[".$time."]</span>";
            
            // 🔥 DER DETEKTIV-BRÜCKEN-SCHLÜSSEL: Übergibt alle Daten in exakt der richtigen Reihenfolge wie im Live-Chat!
            echo "  <span class='".$name_class."' onclick='event.stopPropagation(); rmeShowUserContextMenu(event, \"".$js_sicherer_name."\", \"".$sichere_archiv_ip."\", \"\", null, \"".$js_sicheres_os."\", \"".$js_sicherer_browser."\", \"".$js_sicheres_device."\", \"".$anzahl_nachrichten_archiv."\", \"Archiv-Verlauf\");' style='cursor:pointer;'>".htmlspecialchars($anzeige_name, ENT_QUOTES, 'UTF-8').":</span> ";
            
            // Extrabreite Weiche für das visuelle Gehäuse der Zapper-Ansage
            if (strpos((string)$final_archive_text, 'rme-zapper-alert-box') !== false) {
                echo "  " . $badge_html . " " . $final_archive_text;
            } else {
                echo "  " . $badge_html . " <span class='chat-live-msg-text' style='font-size:inherit !important;'> " . $final_archive_text . "</span>";
            }
            
            // 🎯 REPARIERT: Wir hängen Deinen Mülleimer-Button genau HIER direkt hinter den Nachrichtentext!
            echo " " . $html_loesch_button;
            echo "</div>";
        }
    } else { 
        echo "<div style='color:#2cf; font-weight:bold; text-align:center; padding:40px;'>Das Chat-Archiv ist leer.</div>"; 
    }
// 🔥 SERVERSCHUTZ EXTRAORDINAIR: Zwingt PHP-FPM zum sofortigen Prozess-Tod!
if (function_exists('ob_get_level') && ob_get_level() > 0) {
    @ob_flush();
    @flush();
}

// Schließt MySQL krisensicher
global $mysqli, $db_connect;
if (isset($mysqli) && $mysqli instanceof mysqli) { @mysqli_close($mysqli); }

// Killt den PHP-Arbeiter sofort, damit er nicht im "Sleep" hängenbleibt
exit;

}

// =========================================================================
// HIGH-SPEED MYSQL UNBLOCKER & CLEANER (PRODUKTION MASTER 2026 - FINAL)
// =========================================================================
$jetzt_zeitstempel = time();
$chef_schutz_sql = "AND user_id != 18 AND LOWER(username) != 'dj-tomjac' AND LOWER(username) NOT LIKE '%tomjac%'";

// UNBLOCKER 1: Wir zwingen MySQL, eventuell offene Tabellen-Sperren dieses Threads sofort zu lösen
if (isset($conn) && $conn instanceof mysqli) {
    mysqli_query($conn, "UNLOCK TABLES;");
    mysqli_query($conn, "SET autocommit = 1;"); // Verhindert, dass Transaktionen die Tabelle einfrieren
} elseif (function_exists('dbquery')) {
    dbquery("UNLOCK TABLES;");
}

// 1. ECHTER GEISTER-KILLER: Löscht sofort blockierende Sessions desselben Users
if (!empty($session_username_start)) {
    // LOW_PRIORITY verhindert, dass das DELETE andere Abfragen (wie vom Handy) blockiert!
    dbquery("DELETE LOW_PRIORITY FROM ".$online_table." 
             WHERE username='".addslashes($session_username_start)."' 
             AND session_id != '".$meine_aktuelle_session."' 
             ".$chef_schutz_sql);
}

// 2. Vampir-Jäger (Nur für Geister, nicht für reale Gäste)
dbquery("DELETE LOW_PRIORITY FROM ".$online_table." 
         WHERE last_active = last_written 
         AND (".$jetzt_zeitstempel." - last_active) > 15 
         AND is_afk = 0 
         AND user_id < 2000 
         ".$chef_schutz_sql);

// 3. Lebenszeichen-Filter für bereits aktive Banner (nach 60 Sekunden)
dbquery("DELETE LOW_PRIORITY FROM ".$online_table." 
         WHERE is_afk = 2 
         AND (".$jetzt_zeitstempel." - last_active) > 60 
         ".$chef_schutz_sql);

// 4. Standard-Müllschlucker für Tab-Schließer
$timeout_aktivlimit = $jetzt_zeitstempel - 600;
dbquery("DELETE LOW_PRIORITY FROM ".$online_table." 
         WHERE last_active < '".$timeout_aktivlimit."' 
         ".$chef_schutz_sql);

// 5. Löscht abgelaufene temporäre Kicks
$kick_timeout = $jetzt_zeitstempel - 120;
dbquery("DELETE LOW_PRIORITY FROM ".$bans_table." WHERE datestamp < '".$kick_timeout."'");

// Sicherheits-Schluss: Leert alle Ausgabepuffer, damit das Handy die Antwort SOFORT erhält
if (ob_get_length()) { ob_end_clean(); }
// =========================================================================


if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close(); 
}
// =========================================================================

?>
