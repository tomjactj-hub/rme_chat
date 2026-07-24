<?php
// 1. FEHLER-MANAGEMENT FÜR PHP 8 (Für Entwicklung aktiv, fängt Fehler sofort ab)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// =========================================================================
// SICHERE PFAD- UND CMS-EINBINDUNG
// =========================================================================
$root_path = dirname(dirname(dirname(__FILE__))) . "/";
require_once $root_path . "maincore.php"; // <--- HIER werden alle Datenbank-Systeme gestartet!

require_once "../../maincore.php";

// Config aus dem Verzeichnis holen
if (file_exists(dirname(__FILE__) . "/rme_smilies_config.php")) {
    require_once dirname(__FILE__) . "/rme_smilies_config.php";
}

// Globale Tabellennamen fest deklarieren (Erweitert um die Einstellungen)
$chat_table      = DB_PREFIX."chat_messages";
$users_table     = DB_PREFIX."users";
$bans_table      = DB_PREFIX."chat_bans";
$online_table    = DB_PREFIX."chat_online";
$blacklist_table = DB_PREFIX."chat_blacklist";
$chatuser_table  = DB_PREFIX."chat_guest_accounts";
$settings_table  = DB_PREFIX."chat_settings"; // NEU hinzugefügt!


// MANUELLES ABSICHERUNGS-NETZ FÜR DIE DATENBANK
if (!isset($db_connect) || !$db_connect) {
    $db_host   = 'localhost';
    $db_user   = 'rme2016';
    $db_pass   = 'xI5#z2Fo0Dmervuj';
    $db_name   = 'radiohprme';
    $db_connect = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
    if ($db_connect) { mysqli_set_charset($db_connect, "utf8mb4"); }
}

if (!defined('RME_DB_PREFIX')) { define('RME_DB_PREFIX', 'fusionb7754_'); }
// =========================================================================
// RECONNECT-JOKER: JETZT ABSOLUT FLACKERFREI DURCH URL-BEREINIGUNG!
// =========================================================================
if (isset($_GET['reconnect']) && $_GET['reconnect'] === 'true') {
    
    // Zwingt PHP, Sessions im privaten Fenster per URL/Cache statt per Cookie zu erlauben!
    if (session_status() == PHP_SESSION_NONE) {
        ini_set('session.use_cookies', 0);
        ini_set('session.use_only_cookies', 0);
        ini_set('session.use_trans_sid', 1);
        session_name("RME_RADIO_CHAT_SESSION");
        session_start();
    }
    
    // RETTUNG: Vernichtet die langlebigen Kick-Cookies SOFORT im Browser, damit das Backend frei atmen kann!
    setcookie("rme_saved_kick_time", "", time() - 3600, "/");
    setcookie("rme_saved_guest_name_kick", "", time() - 3600, "/");
    
    // Macht die Cookies auch für das aktuell ladende PHP-Skript sofort unsichtbar
    unset($_COOKIE['rme_saved_kick_time']);
    unset($_COOKIE['rme_saved_guest_name_kick']);
    
    $reconnect_user_name = '';
    
    // Holt den Namen aus allen möglichen System-Sitzungen
    if (isset($userdata) && is_array($userdata) && isset($userdata['user_name'])) {
        $reconnect_user_name = trim($userdata['user_name']);
    } elseif (isset($_SESSION['chat_user_name'])) {
        $reconnect_user_name = trim($_SESSION['chat_user_name']);
    } elseif (isset($_SESSION['rme_chat_guest_name'])) {
        $reconnect_user_name = trim($_SESSION['rme_chat_guest_name']);
    }

    if (!empty($reconnect_user_name)) {
        // Schaltet den User in der Onlineliste augenblicklich wieder scharf!
        dbquery("UPDATE " . $online_table . " SET is_afk = 0, last_written = '" . time() . "', last_active = '" . time() . "' WHERE username = '" . addslashes($reconnect_user_name) . "' OR username = '" . addslashes($reconnect_user_name) . "_CU'");
    }

    // REPARATUR-KERN AGAINST FLACKERN: Putzt das ?reconnect=true per JavaScript lautlos aus der Adresszeile!
    echo "<script>
        if (window.history && window.history.replaceState) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    </script>";
}

// SITZUNGS-SPERRE ERST JETZT LÖSEN (Nachdem der Joker den Namen ausgelesen hat!)
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
// =========================================================================
// 🔒 ROOT-ID-SCHUTZRIEGEL: Verhindert die ID 0 beim Öffnen der rme_chat.php
// =========================================================================
if (session_status() == PHP_SESSION_NONE) {
    session_name("RME_RADIO_CHAT_SESSION");
    session_start();
}

// Wir holen den Namen aus allen verfügbaren Quellen im System
$root_check_username = '';
if (isset($userdata) && is_array($userdata) && isset($userdata['user_name'])) {
    $root_check_username = trim($userdata['user_name']);
} elseif (isset($_SESSION['chat_user_name'])) {
    $root_check_username = trim($_SESSION['chat_user_name']);
} elseif (isset($_SESSION['rme_chat_guest_name'])) {
    $root_check_username = trim($_SESSION['rme_chat_guest_name']);
}

$root_check_name_low = strtolower(trim($root_check_username));

// Falls die globale ID-Variable noch nicht gesetzt ist, initialisieren wir sie
if (!isset($chat_user_id)) { $chat_user_id = 0; }

// 1. CHEF-RETTUNG
if ($root_check_name_low === 'dj-tomjac' || $root_check_name_low === 'tomjac') {
    $chat_user_id = 18;
}
// 2. CHATUSER-RETTUNG: Wenn ein _CU im Namen mitschwimmt, holen wir die echte ID aus der Gästetabelle
elseif (strpos($root_check_name_low, '_cu') !== false || $root_check_name_low === 'hammerhai66') {
    $db_id_suche = dbquery("SELECT id FROM " . $chatuser_table . " WHERE guest_name = '" . addslashes($root_check_username) . "' LIMIT 1");
    if ($db_id_suche && dbrows($db_id_suche) > 0) {
        $chat_user_id = intval(dbarray($db_id_suche)['id']);
    } else {
        $chat_user_id = 1000; // Sicherer Fallback-Startwert
    }
}

// Wir schreiben die reparierte ID sofort zurück in die Sessions, damit nachfolgende Scripte sie lesen!
if ($chat_user_id > 0) {
    $_SESSION['chat_user_id'] = $chat_user_id;
    if (isset($_SESSION['chat_user_name']) && strpos($_SESSION['chat_user_name'], '_CU') === false && $chat_user_id >= 1000) {
        $_SESSION['chat_user_name'] = $root_check_username . "_CU";
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
// =========================================================================

// =========================================================================
// COUCH-POTATO-REINIGER & CHAT-LOGOUT (TEIL 2A VON 3 - FLACKER-REPARIERT)
// =========================================================================
if (isset($_GET['action']) && $_GET['action'] == "browser_closed_logout") {
    if (session_status() == PHP_SESSION_NONE) {
        session_name("RME_RADIO_CHAT_SESSION");
        @session_start();
    }
    
    $blitz_logout_name = $_SESSION['rme_chat_guest_name'] ?? ($_SESSION['chat_user_name'] ?? '');
    session_write_close();
    
    if (!empty($blitz_logout_name)) {
        dbquery("DELETE FROM ".$online_table." WHERE username='".addslashes($blitz_logout_name)."' OR username='".addslashes($blitz_logout_name)."_CU'");
    }
    exit; 
}

if (isset($_GET['clear_chat_session'])) {
    if (session_status() == PHP_SESSION_NONE) {
        session_name("RME_RADIO_CHAT_SESSION");
        @session_start();
    }
    
    $session_logout_name = $_SESSION['rme_chat_guest_name'] ?? ($_SESSION['chat_user_name'] ?? '');
    
    if (!empty($session_logout_name)) {
        dbquery("DELETE FROM ".$online_table." WHERE username='".addslashes($session_logout_name)."' OR username='".addslashes($session_logout_name)."_CU'");
    }
    
    $_SESSION = array();
    
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
    }
    
    @session_destroy();
    @session_unset();
    
    // REPARITUR-KERN: Schließt den Ausgabe-Puffer vor der Umleitung, um Flackern beim Logout zu verhindern
    if (ob_get_length()) ob_clean();
    
    $aktueller_script_name = basename($_SERVER['SCRIPT_NAME']);
    header("Location: " . $aktueller_script_name); 
    exit;
}

// =========================================================================
// EMOJI-RETTER & RECHTE-ERMITTLUNG (TEIL 2B VON 3)
// =========================================================================
function maskiereHandyEmojis($text) {
    return preg_replace_callback('/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{1F900}-\x{1F9FF}\x{1F1E6}-\x{1F1FF}]/u', function($match) {
        return '[EMOJI_' . bin2hex($match) . ']';
    }, $text);
}

function demaskiereHandyEmojis($text) {
    return preg_replace_callback('/\[EMOJI_([0-9a-fA-F]+)\]/', function($match) {
        return hex2bin($match);
    }, $text);
}

$user_ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
global $userdata;

$is_logged_in = (defined('iMEMBER') && iMEMBER);
$is_admin_check = (defined('iADMIN') && iADMIN) || (isset($userdata['user_level']) && intval($userdata['user_level']) == 103);

$current_chat_user = "Gast";
$current_user_id = 0;

if ($is_logged_in) {
    $current_chat_user = $userdata['user_name'] ?? "User";
    $current_user_id = intval($userdata['user_id'] ?? 0);
}

$clean_name_low = strtolower(trim($current_chat_user));
$ist_leitung = ($is_admin_check || $clean_name_low === 'dj-tomjac' || $current_user_id === 1);
$is_admin = $ist_leitung;

$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'UnknownDevice';
$geraete_fingerabdruck = substr(md5($user_ip . $user_agent), 0, 4);

$bestehender_name = $_SESSION['rme_chat_guest_name'] ?? ($_SESSION['chat_user_name'] ?? '');
$hat_bereits_session = (!empty($bestehender_name) && $bestehender_name !== 'Gast' && !str_starts_with($bestehender_name, 'Gast_'));

if (!$hat_bereits_session) {
    if ($is_logged_in) {
        $_SESSION['rme_chat_guest_name'] = $current_chat_user;
        $_SESSION['chat_user_id'] = $current_user_id;
    } else {
        $random_id = (hexdec($geraete_fingerabdruck) % 699) + 2000; 
        $_SESSION['chat_user_id'] = $random_id;
        $_SESSION['rme_chat_guest_name'] = "Gast_" . $random_id;
    }
}

$chat_user_name_check = $_SESSION['rme_chat_guest_name'];
$safe_user_check = function_exists('stripinput') ? stripinput($chat_user_name_check) : trim(strip_tags($chat_user_name_check));
$chat_user_name = $_SESSION['rme_chat_guest_name'];
$chat_user_id = $_SESSION['chat_user_id'];

$raw_sidebar_name = $chat_user_name ?? ($_SESSION['chat_user_name'] ?? ($_SESSION['rme_chat_guest_name'] ?? 'Gast'));
$display_sidebar_name = str_replace(array("_Gast", "_CU"), "", $raw_sidebar_name);
$sauberer_name_check = str_replace(array('[ADMIN]', '[MODERATOR]', '[MOD]', '[HADMIN]'), '', $display_sidebar_name);
$sauberer_name_check = trim($sauberer_name_check);

$ist_ein_chat_user = (strpos((string)$raw_sidebar_name, '_CU') !== false || $chat_user_id === 1000 || ($chat_user_id >= 1000 && $chat_user_id < 2000));
$ist_wirklich_gast_check = (strpos(strtolower((string)$raw_sidebar_name), 'gast') !== false && !$ist_ein_chat_user);
// =========================================================================
// BANN-ABFANG & LOGOUT (TEIL 2C VON 3 - EISERNER CHEF-SCHUTZ & REPARIERT)
// =========================================================================
// Wir lassen die Original-Variable für die Bann-Erkennung völlig unberührt!
$farbe_check_name = !empty($chat_user_name) ? $chat_user_name : ($current_chat_user ?? 'Gast');
$ich_bin_chat_user = (strpos((string)$farbe_check_name, '_CU') !== false || $chat_user_id === 1000 || ($chat_user_id >= 1000 && $chat_user_id < 2000));

// ⚪ STANDARD: Wer gar nichts hat, startet im echten Gäste-Weiß!
$mein_oberer_style_class = "rme-name-guest"; 

// 🔄 REPARATUR-KERN: Ränge vollautomatisch und direkt aus dem CMS auslesen
$cms_level_check  = isset($userdata['user_level']) ? intval($userdata['user_level']) : 0;
$cms_groups_check = isset($userdata['user_groups']) ? (string)$userdata['user_groups'] : '';
$check_name_low   = strtolower(trim(str_replace(array("_Gast", "_CU"), "", $farbe_check_name)));

if ($is_logged_in) {
    // A. Die Chef-Leitung (Du / Admins / Level 103 / Hauptgruppen)
    if ($check_name_low === "dj-tomjac" || $check_name_low === "tomjac" || $cms_level_check === 103 || strpos($cms_groups_check, ".1.") !== false || strpos($cms_groups_check, ".2.") !== false) {
        $mein_oberer_style_class = "rme-rgb-hadmin";
    }
    // B. Echte Moderatoren & DJs (Gruppe .3., Level 101/102) -> JETZT VOLLAUTOMATISCH GELB!
    elseif ($cms_level_check === 101 || $cms_level_check === 102 || strpos($cms_groups_check, ".3.") !== false || strpos($cms_groups_check, ".4.") !== false || strpos($cms_groups_check, ".5.") !== false) {
        $mein_oberer_style_class = "rme-moderator-username"; // 🟡 Gelb für dein Team!
    } 
    // C. Normale registrierte Homepage-Mitglieder ohne Team-Rang
    else {
        $mein_oberer_style_class = "rme-user-logged"; // 🔵 Hörer-Blau
    }
} 
// D. Registrierte reine Chatbox-Gäste (_CU oder HammerHai)
elseif ($ich_bin_chat_user || $check_name_low === "hammerhai66") {
    $mein_oberer_style_class = "rme-user-logged"; // 🔵 Hörer-Blau
}
// E. Unregistrierte Wild-Gäste fallen automatisch auf den Standard zurück = "rme-name-guest" (Weiß)
// =========================================================================

// 1. DER PERMANENTE BANN-ABFANG
if (!$ist_leitung && (!empty($safe_user_check) || !empty($user_ip))) {
    $clean_global_search = str_replace("_Gast", "", $safe_user_check);
    $check_global_ban = dbquery("SELECT count(*) as total FROM ".$blacklist_table." WHERE ip_address='".addslashes($user_ip)."' OR username LIKE '%".addslashes($clean_global_search)."%' LIMIT 1");
    
    if ($check_global_ban && dbarray($check_global_ban)['total'] > 0) {
        unset($_SESSION['rme_chat_guest_name']);
        
        if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) && (isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['SERVER_PROTOCOL'] ?? '', 'HTTP') !== false && strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false)) {
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Permanenter Bann!</title></head><body style="margin:0; padding:0; background:#111; font-family:Arial,sans-serif;">
                <div style="display:flex; align-items:center; justify-content:center; width:100vw; height:100vh; background:#111; padding:15px; box-sizing:border-box;">
                    <div style="width:100%; max-width:480px; background:#8b0000; border:4px solid #00ff00; border-radius:14px; box-shadow:0 0 30px rgba(0,255,0,0.5); text-align:center; overflow:hidden;">
                        <div style="background:#000; padding:15px; border-bottom:3px solid #00ff00;"><h4 style="margin:0; color:#00ff00; font-size:22px; letter-spacing:1.5px; text-transform:uppercase; font-weight:bold;">Radio-Musikexpress</h4></div>
                        <div style="padding:35px 20px;"><div style="font-size:50px; margin-bottom:20px;">🚨</div><h2 style="margin:0 0 15px 0; color:#fff; font-size:18px; text-transform:uppercase; font-weight:bold;">Permanenter Bann!</h2>
                        <p style="margin:0 0 25px 0; color:#eee; font-size:15px; line-height:1.5;">Du wurdest permanent aus dem Chat gebannt.<br>Es besteht keine automatische Wartezeit.</p>
                        <div style="margin:10px auto 0 auto; display:inline-block; background:#ff0; color:#000; font-size:14px; font-weight:bold; padding:10px 20px; border-radius:8px; border:2px solid #00ff00; text-transform:uppercase; letter-spacing:0.5px; max-width:90%;">Schreibe einem Admin auf der HP, wenn du entbannt werden willst</div></div>
                    </div>
                </div></body></html>';
            exit;
        } else {
            header("Content-Type: text/plain; charset=UTF-8");
            echo "[DU_BIST_GEBANNT]";
            exit;
        }
    }
}

// 2. DER TEMPORÄRE KICK-ABFANG
if (!$ist_leitung && !empty($user_ip)) {
    $check_temp_kick = dbquery("SELECT count(*) as total FROM ".$bans_table." WHERE ip_address='".addslashes($user_ip)."' LIMIT 1");
    if ($check_temp_kick && dbarray($check_temp_kick)['total'] > 0) {
        
        if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) && (isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['SERVER_PROTOCOL'] ?? '', 'HTTP') !== false && strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'text/html') !== false)) {
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Zugriff verweigert!</title></head><body style="margin:0; padding:0; background:#111; font-family:Arial,sans-serif;">
                <div style="display:flex; align-items:center; justify-content:center; width:100vw; height:100vh; background:#111; padding:15px; box-sizing:border-box;">
                    <div style="width:100%; max-width:480px; background:#8b0000; border:4px solid #00ff00; border-radius:14px; box-shadow:0 0 30px rgba(0,255,0,0.5); text-align:center; overflow:hidden;">
                        <div style="background:#000; padding:15px; border-bottom:3px solid #00ff00;"><h4 style="margin:0; color:#00ff00; font-size:22px; letter-spacing:1.5px; text-transform:uppercase; font-weight:bold;">Radio-Musikexpress</h4></div>
                        <div style="padding:35px 20px;"><div style="font-size:50px; margin-bottom:20px;">🚨</div><h2 style="margin:0 0 15px 0; color:#fff; font-size:18px; text-transform:uppercase; font-weight:bold;">Zugriff verweigert!</h2>
                        <p style="margin:0 0 25px 0; color:#eee; font-size:15px; line-height:1.5;">Du wurdest vom Admin oder Moderator<br>aus dem Chat gekickt.</p>
                        <div style="margin:10px auto 0 auto; display:inline-block; background:#ff0000; color:#ffff00; font-size:14px; font-weight:bold; padding:10px 20px; border-radius:8px; border:2px solid #ffff00; text-transform:uppercase; letter-spacing:0.5px; max-width:90%;">In 2 Minuten kannst du wieder rein</div></div>
                    </div>
                </div></body></html>';
            exit;
        } else {
            header("Content-Type: text/plain; charset=UTF-8");
            echo "[DU GEKICKTES SUPPENHUHN]";
            exit;
        }
    }
}

// LOGOUT-AKTION (SAUBER BEREINIGT)
if (isset($_GET['action']) && $_GET['action'] == "logout") {
    $logout_name = "";
    if ($is_logged_in) { 
        $logout_name = $userdata['user_name']; 
    } elseif (isset($_SESSION['rme_chat_guest_name'])) { 
        $logout_name = $_SESSION['rme_chat_guest_name']; 
    }
    
    if (!empty($logout_name)) {
        dbquery("DELETE FROM ".$online_table." WHERE username='".addslashes($logout_name)."' OR username='".addslashes($logout_name)."_CU'");
    }
    
    unset($_SESSION['rme_chat_guest_name']);
    unset($_SESSION['chat_user_id']);
    
    setcookie("rme_saved_guest_name", "", time() - 3600, "/");
    
    $aktueller_script_name = basename($_SERVER['SCRIPT_NAME']);
    echo "<script>parent.window.location.href = '".$aktueller_script_name."';</script>";
    exit;
}

global $settings; $site_url = $settings['siteurl'] ?? '';
?>


<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <!-- REPARIERT: Nur EIN einziges, perfekt ausbalanciertes Viewport-Tag für Handys (erlaubt Zoomen!) -->
<!-- ERZWINGT DIE PC-ANSICHT: Verhindert mobiles Skalieren, sodass die Seite wie am Desktop geladen wird -->
<meta name="viewport" content="width=1440">

    
    <title>Radio-Musikexpress Chatroom</title>

    <!-- 1. DEINE LOKALE JQUERY 4.0.0 (Absolut absturz- und lagfrei!) -->
    <script src="jquery-4.0.0.min.js"></script>
<!--	<script src="js/rme_chat_core.js?v=<?php echo time(); ?>"></script> -->


    <!-- 2. GOOGLE FONTS (Für Emojis und die PC-Schnittstelle)-->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Color+Emoji&display=swap" rel="stylesheet">
 

    <!-- 3. DEINE CHAT-CSS (Vollautomatische Versionierung über Dateizeit) -->
<link rel="stylesheet" type="text/css" href="css/rme_chat.css?v=<?php echo file_exists('css/rme_chat.css') ? filemtime('css/rme_chat.css') : time(); ?>">
<link rel="stylesheet" type="text/css" href="css/font_styles.css?v=1.5">
</head>

<body>
<?php if (!$is_logged_in) { ?>

<!-- =========================================================================
     1. REGISTRIERUNGS-OVERLAY (COMPACT & CLEAN)
     ========================================================================= -->
<div id="rme-guest-registration-overlay" class="rme-overlay-screen rme-reg-theme">
    <div class="rme-overlay-box">
        <div class="rme-overlay-header">
            <h3>Radio-Musikexpress</h3>
        </div>
        <p class="rme-overlay-desc">Wähle einen Gast-Namen und ein Passwort. Dein Name bleibt für 3 Monate für dich reserviert!</p>
        <form id="rme-guest-reg-form" method="POST" action="rme_chat_register.php?action=register_guest" class="rme-overlay-form">
            <div class="rme-bot-trap"><input type="text" name="rme_bot_trap_hidden" value=""></div>
            
            <div class="rme-form-group">
                <label>Dein Wunschname:</label>
                <input type="text" name="desired_guest_name" required maxlength="16" placeholder="z.B. Musikfan">
            </div>
            
            <div class="rme-form-group">
                <label>Chat-Passwort (zum Schutz):</label>
                <input type="password" name="guest_password" required placeholder="••••••••">
            </div>
            
            <?php
            $num1 = rand(2, 15); $num2 = rand(2, 15);
            $_SESSION['rme_captcha_result'] = $num1 + $num2;
            ?>
            <div class="rme-captcha-container">
                <label>Bot-Schutz: Was ist <?php echo $num1; ?> + <?php echo $num2; ?>?</label>
                <input type="number" name="guest_math_captcha" required>
            </div>
            
            <div class="rme-btn-container">
                <button type="button" class="rme-btn-cancel" onclick="document.getElementById('rme-guest-registration-overlay').style.display = 'none';">Abbrechen</button>
                <button type="submit" class="rme-btn-submit">Registrieren</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     2. LOGIN-OVERLAY (COMPACT & CLEAN)
     ========================================================================= -->
<div id="rme-guest-login-overlay" class="rme-overlay-screen rme-login-theme">
    <div class="rme-overlay-box">
        <div class="rme-overlay-header">
            <h3>Chat-Login</h3>
        </div>
        <p class="rme-overlay-desc">Melde dich hier mit deinem reservierten Chat-Namen und deinem Passwort an.</p>
        <form id="rme-guest-login-form" method="POST" action="rme_chat_backend.php?action=login_guest" class="rme-overlay-form">
            
            <div class="rme-form-group">
                <label>Dein Chat-Name:</label>
                <input type="text" name="login_guest_name" required maxlength="16" placeholder="Dein Name">
            </div>
            
            <div class="rme-form-group">
                <label>Dein Chat-Passwort:</label>
                <input type="password" name="login_guest_password" required placeholder="••••••••">
            </div>
            
            <div class="rme-btn-container">
                <button type="button" class="rme-btn-cancel" onclick="document.getElementById('rme-guest-login-overlay').style.display = 'none';">Abbrechen</button>
                <button type="submit" class="rme-btn-submit">Einloggen</button>
            </div>
        </form>
    </div>
</div>

<?php } ?>

<div class="chat-container">
    <div class="chat-main">
<!-- TITELKASTEN: KONTROLLZENTRUM FÜR TEAMMITGLIEDER -->
<!-- TITELKASTEN: KONTROLLZENTRUM FÜR TEAMMITGLIEDER -->
<div id="rme-team-cockpit-header" style="border: 1px solid #252525 !important; padding: 10px 15px !important; border-radius: 4px !important; box-sizing: border-box !important; width: 100% !important; margin-bottom: 15px !important; display: flex !important; align-items: center !important;">
    
<?php
// =========================================================================
// 👑 DIE EXKLUSIVE SENDUNGS-WEICHE (VOLLAUTOMATISCH FÜR DAS GESAMTE TEAM)
// =========================================================================

// 1. Wir holen uns die Ränge aus allen verfügbaren CMS-Quellen
$aktuelle_session_uid = isset($current_user_id) ? intval($current_user_id) : (isset($_SESSION['chat_user_id']) ? intval($_SESSION['chat_user_id']) : 0);
$aktuelle_cms_groups  = isset($u_groups) ? (string)$u_groups : (isset($user_groups_raw) ? (string)$user_groups_raw : '');
$aktuelles_cms_level  = isset($level) ? intval($level) : (isset($user_level_int) ? intval($user_level_int) : 0);
$aktueller_name_clean = isset($sauberer_name_check) ? strtolower(trim($sauberer_name_check)) : '';

// 2. RETTUNGS-ANKER: Falls das CMS im Hintergrund die Session verliert, fragen wir die Rechte live ab
if ($aktuelle_cms_groups === '' && $aktuelle_session_uid > 0 && $aktuelle_session_uid < 1000) {
    $db_rechte_check = dbquery("SELECT user_level, user_groups FROM ".DB_USERS." WHERE user_id='".$aktuelle_session_uid."' LIMIT 1");
    if ($db_rechte_check && dbrows($db_rechte_check) > 0) {
        $r_row = dbarray($db_rechte_check);
        $aktuelles_cms_level = intval($r_row['user_level']);
        $aktuelle_cms_groups = (string)$r_row['user_groups'];
    }
}

// 3. VOLLAUTOMATISCHE RECHTE-WEICHE (Aus Deiner Original-Abfrage!)
$automatisch_is_leitung = (
    $aktuelles_cms_level === 103 || $aktuelles_cms_level === -103 || 
    strpos($aktuelle_cms_groups, ".1.") !== false || strpos($aktuelle_cms_groups, ".2.") !== false ||
    $aktueller_name_clean === 'dj-tomjac' || $aktueller_name_clean === 'tomjac' || $aktuelle_session_uid === 18
);

$automatisch_is_moderator = (
    !$automatisch_is_leitung && (
        $aktuelles_cms_level === 101 || $aktuelles_cms_level === -101 || $aktuelles_cms_level === 102 ||
        strpos($aktuelle_cms_groups, ".3.") !== false || strpos($aktuelle_cms_groups, ".4.") !== false || strpos($aktuelle_cms_groups, ".5.") !== false
    )
);

// 4. Der finale Riegel für die Sichtbarkeit der Mod Tools (Admin + Mod)
$ich_bin_der_wahre_boss_gaming = ($automatisch_is_leitung === true || $automatisch_is_moderator === true);
?>

<div style="flex: 1 !important; text-align: center !important; <?php echo $ich_bin_der_wahre_boss_gaming ? 'padding-left: 120px !important;' : ''; ?>">
    <h4 class="rme-rgb-text-only">Radio-Musikexpress</h4>
</div>

<!-- 🔥 WEICHE 1: Der äußere Flex-Rahmen öffnet sich für JEDES Teammitglied (Admin & Mod) -->
<?php if ($ich_bin_der_wahre_boss_gaming) { ?>
    <div style="display: flex !important; align-items: center !important; gap: 6px !important; flex-shrink: 0 !important;">

        <!-- 🔒 SPERRE A: Die Admin-Buttons werden NUR gezeichnet, wenn man Leitung/Chef ist! -->
        <?php if ($automatisch_is_leitung) { ?>
            <button type="button" id="rme-tab-live" class="rme-tab-btn rme-tab-live active">Live</button>
            <button type="button" id="rme-tab-archive" class="rme-tab-btn rme-tab-archive">🛠️ Archiv</button>
            <button type="button" id="rme-tab-kicklist" class="rme-tab-btn rme-tab-kicklist">⚡ Kickliste</button>
            <button type="button" id="rme-tab-bannlist" class="rme-tab-btn rme-tab-bannlist">🛑 Bannliste</button>
            <button type="button" id="rme-tab-chatusers" class="rme-tab-btn rme-tab-chatusers">👥 Chat Userliste</button>

            <!-- ========================================================================= -->
            <!-- METRIC-NEON: POPUP FÜR DIE NEON-FARBWAHL (NUR FÜR ADMINS!)                -->
            <!-- ========================================================================= -->
            <div class="rme-color-trigger-wrapper">
                <button type="button" id="rme-tab-color" class="rme-tab-btn rme-tab-color" onclick="var p = document.getElementById('rme-neon-color-popup'); p.style.display = (p.style.display === 'block') ? 'none' : 'block';">
                    🎨 Farbe
                </button>
                
                <div id="rme-neon-color-popup" class="rme-neon-color-popup">
                    <h4 class="rme-neon-color-title">Neon-Namensfarbe:</h4>
                    <div class="rme-color-grid">
                        <div onclick="rmeSpeichereNeonFarbe('#00f0ff');" class="rme-color-tile rme-tile-blue" title="Neon Blau"></div>
                        <div onclick="rmeSpeichereNeonFarbe('#00ff66');" class="rme-color-tile rme-tile-green" title="Neon Grün"></div>
                        <div onclick="rmeSpeichereNeonFarbe('#ff0055');" class="rme-color-tile rme-tile-pink" title="Neon Pink"></div>
                        <div onclick="rmeSpeichereNeonFarbe('#ffcc00');" class="rme-color-tile rme-tile-yellow" title="Neon Gelb"></div>
                        <div onclick="rmeSpeichereNeonFarbe('#ff5500');" class="rme-color-tile rme-tile-orange" title="Neon Orange"></div>
                        <div onclick="rmeSpeichereNeonFarbe('#cc00ff');" class="rme-color-tile rme-tile-purple" title="Neon Lila"></div>
                        <div onclick="rmeSpeichereNeonFarbe('rgb_matrix');" class="rme-color-tile rme-tile-rgb" title="Original RGB Matrix Leuchten"></div>
                    </div>
                    <div id="rme-neon-color-status" class="rme-color-status-text">Bereit</div>
                </div>
            </div>
        <?php } // Ende der reinen Admin-Buttons Sperre A ?>
        <!-- ========================================================================= -->
        <!-- ⚡ TEIL 2: MODERATOREN TOOLS (SICHTBAR FÜR ADMIONS & MODERATOREN)          -->
        <!-- ========================================================================= -->
        <div class="rme-color-trigger-wrapper">
            <!-- Haupt-Trigger im exakten Farb-Button-Design -->
            <button type="button" id="rme-tab-modtools" class="rme-modtool-tab-btn rme-tab-color" onclick="var p = document.getElementById('rme-mod-tools-sub'); p.style.display = (p.style.display === 'block') ? 'none' : 'block';">
                ⚡ Mod Tools
            </button>
            
            <!-- 📂 DAS INTERNE KLAPPMENÜ -->
            <div id="rme-mod-tools-sub" class="rme-modtool-color-popup" style="display: none; border-color: #00ff66 !important; padding: 12px !important; min-width: 200px !important;">
                <h4 class="rme-modtool-color-title" style="color: #00ff66 !important; margin-top: 0 !important; margin-bottom: 8px !important;">⚡ Moderatoren Tools:</h4>
                
                <!-- Tool 1: DJ Ansage Zapper -->
                <button type="button" 
                        onclick="rmeZuendeDJZapper(); document.getElementById('rme-universal-gaming-popup').style.display='none';" 
                        class="rme-popup-submit-btn rme-modtool-action-btn rme-btn-zapper" 
                        style="width: 100% !important; margin: 20px 0 0 0 !important; box-sizing: border-box !important; padding: 6px !important; border: none !important; height: auto !important; float: none !important; display: block !important;">
                    ⚡ DJ Ansage (Zapp!)
                </button>

                <!-- Tool 2: Danke-Feuerwerk -->
                <button type="button" 
                        onclick="sendeFeuerwerkAnAlle(event); document.getElementById('rme-universal-gaming-popup').style.display='none';" 
                        class="rme-popup-submit-btn rme-mod-btn rme-btn-firework" 
                        style="width: 100% !important; margin: 20px 0 0 0 !important; box-sizing: border-box !important; padding: 6px !important; border: none !important; height: auto !important; float: none !important; display: block !important;"
                        title="Ein Dankes-Feuerwerk für ALLE Hörer im Chat zünden!">
                    🎆 Danke-Feuerwerk
                </button>

                <!-- Tool 3: Live-Umfrage -->
                <button type="button" 
                        id="rme-tab-voting" 
                        class="rme-popup-submit-btn rme-tab-btn rme-tab-voting"  
                        style="width: 100% !important; margin: 20px 0 0 0 !important; padding: 6px !important; box-sizing: border-box !important; display: block !important; float: none !important; border: none !important; height: auto !important;"
                        onclick="
                            var p = document.getElementById('rme-voting-admin-overlay'); 
                            if(p) { p.style.display = (p.style.display === 'flex') ? 'none' : 'flex'; }
                            document.getElementById('rme-universal-gaming-popup').style.display='none';
                        ">
                    📊 Live-Umfrage
                </button>

                <!-- Tool 4: Countdown-Gruppe -->
                <div class="rme-countdown-input-group" style="margin-top: 10px !important; border-top: 1px solid #222 !important; padding-top: 10px !important;">
                    <label class="rme-countdown-group-label">Countdown (Sekunden):</label>
                    <div class="rme-countdown-flex-row" style="display: flex !important; gap: 4px !important; align-items: center !important;">
                        <input type="number" 
                               id="rme_countdown_secs" 
                               placeholder="z.B. 300" 
                               class="rme-countdown-field" 
                               style="flex: 1 !important; height: 26px !important; font-size: 11px !important; background: #111 !important; color: #fff !important; border: 1px solid #444 !important; padding: 0 6px !important; border-radius: 4px !important;">
                        <button type="button" 
                                onclick="rmeStartenCountdown(); document.getElementById('rme-universal-gaming-popup').style.display='none';" 
                                class="rme-countdown-start-btn" 
                                style="height: 26px !important; padding: 0 8px !important; font-size: 11px !important; background: #00ff66 !important; color: #000 !important; border: none !important; font-weight: bold !important; border-radius: 4px !important; cursor: pointer !important; box-shadow: 0 0 5px rgba(0,255,102,0.3) !important;">
                    ⏱ Wellen
                        </button>
                    </div>
                </div>
                
                <div class="rme-color-status-text" style="margin-top: 8px !important; font-size: 10px !important; color: #666 !important;">Bereit</div>
<!-- 🤖 QUIZBOT: FRAGEN-STATION FÜR MODS & DJS -->
<div style="background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); padding: 15px; margin: 15px 0; border-radius: 6px; font-family: Arial, sans-serif;">
    <h3 style="color: #ffaa00; margin-top: 0; font-size: 15px; text-transform: uppercase;">🤖 QuizBot Fragen-Schmiede</h3>
    
    <div style="margin-bottom: 10px;">
        <label style="display: block; color: #aaa; font-size: 12px; margin-bottom: 4px;">Quiz-Frage:</label>
        <input type="text" id="rme_quiz_add_question" placeholder="z.B. Wie viele Saiten hat eine Gitarre?" style="width: 100%; background: #222; border: 1px solid #444; padding: 8px; color: #fff; border-radius: 4px; box-sizing: border-box;">
    </div>

    <div style="margin-bottom: 10px;">
        <label style="display: block; color: #aaa; font-size: 12px; margin-bottom: 4px;">Punkte für den Gewinner:</label>
        <input type="number" id="rme_quiz_add_points" value="10" min="5" max="100" style="width: 100%; background: #222; border: 1px solid #444; padding: 8px; color: #fff; border-radius: 4px; box-sizing: border-box;">
    </div>
    
    <div style="margin-bottom: 12px;">
        <label style="display: block; color: #aaa; font-size: 12px; margin-bottom: 4px;">Richtige Antwort:</label>
        <input type="text" id="rme_quiz_add_answer" placeholder="z.B. 6" style="width: 100%; background: #222; border: 1px solid #444; padding: 8px; color: #fff; border-radius: 4px; box-sizing: border-box;">
    </div>
    
    <div style="display: flex; justify-content: space-between; align-items: center;">
        <button onclick="rmeSpeichereNeueQuizFrage()" style="background: #ffaa00; border: none; padding: 8px 15px; color: #000; font-weight: bold; border-radius: 4px; cursor: pointer;">💾 Frage speichern</button>
        <span id="rme-quiz-save-status" style="font-size: 13px; font-weight: bold;"></span>
    </div>
</div>
<!-- 📣 MOD-TOOL FEATURE: GRUSSBOX-SPIEGEL-TICKER -->
<div style="margin-top: 4px; padding-top: 4px; border-top: 1px dashed rgba(255,255,255,0.1);">
    <input type="text" id="rme_mod_gruss" placeholder="Grußtext aus der Wunschbox..." class="rme-countdown-field" style="width: 100% !important; margin-bottom: 3px !important; font-size: 11px !important; padding: 3px !important; border: 1px solid #00ff66 !important; color: #00ff66 !important;">
    <button type="button" onclick="var g = document.getElementById('rme_mod_gruss').value.trim(); if(g !== '') { rmeSendeGamingAktion('spiegel_gruss|' + g); document.getElementById('rme_mod_gruss').value = ''; }" class="rme-popup-game-trigger" style="width: 100% !important; background: #004D40 !important; color: #00ff66 !important; border: 1px solid #00ff66 !important; padding: 4px !important; font-size: 11px !important; font-weight: bold !important; margin: 0 !important; box-shadow: 0 0 5px rgba(0,255,102,0.3) !important;">📣 Gruß in den Chat spiegeln</button>
</div>

            </div> <!-- Schließt rme-mod-tools-sub -->
 
 </div> <!-- Schließt rme-color-trigger-wrapper für ModTools -->

    </div> <!-- Schließt das display:flex Gehäuse der Menüleiste -->
<?php } // 🔥 MASTER-FIX: Hier wird das if ($ich_bin_der_wahre_boss_gaming) der Menüleiste komplett geschlossen! ?>

<!-- ========================================================================= -->
<!-- 📊 TEIL 3: FREISCHWEBENDES OVERLAY FÜR DIE ABSOLUTE MITTE (VOTING FORM)  -->
<!-- ========================================================================= -->
<div id="rme-voting-admin-overlay" style="display: none; position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.85); z-index: 999999; align-items: center; justify-content: center;">
    <div style="background: #111111; border: 2px solid #ffcc00; box-shadow: 0 0 25px #ffcc00; padding: 25px; border-radius: 4px; width: 95%; max-width: 450px; box-sizing: border-box;">
        
        <div class="rme-dsgvo-header" style="color: #ffcc00; font-family: 'Oswald', sans-serif; font-weight: bold; font-size: 18px; margin-bottom: 15px; border-bottom: 1px solid #222; padding-bottom: 10px; text-align: left;">
            📊 LIVE-UMFRAGE STARTEN
        </div>
        
        <form id="rme-admin-voting-form" onsubmit="return false;" style="text-align: left; font-family: sans-serif; font-size: 13px; color: #ccc; display: flex; flex-direction: column; gap: 12px;">
            <div>
                <label style="display:block; margin-bottom:4px; color:#ffcc00; font-weight:bold;">Deine Frage:</label>
                <input type="text" id="rme_vote_question" placeholder="z.B. Welcher Musikstil jetzt?" style="width:100%; background:#1a1a1a; color:#fff; padding:10px; border:1px solid #333; border-left:3px solid #ffcc00; box-sizing: border-box; border-radius:3px;" required>
            </div>
            
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:bold; color:#00ff66;">Antwort 1:</label>
                <input type="text" id="rme_vote_opt1" placeholder="Option A" style="width:100%; background:#1a1a1a; color:#fff; padding:10px; border:1px solid #333; border-left:3px solid #00ff66; box-sizing: border-box; border-radius:3px;" required>
            </div>
            
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:bold; color:#00ff66;">Antwort 2:</label>
                <input type="text" id="rme_vote_opt2" placeholder="Option B" style="width:100%; background:#1a1a1a; color:#fff; padding:10px; border:1px solid #333; border-left:3px solid #00ff66; box-sizing: border-box; border-radius:3px;" required>
            </div>
            
            <div>
                <label style="display:block; margin-bottom:4px; font-weight:bold; color:#00f0ff;">Antwort 3 (Optional):</label>
                <input type="text" id="rme_vote_opt3" placeholder="Option C (leer lassen falls ungenutzt)" style="width:100%; background:#1a1a1a; color:#fff; padding:10px; border:1px solid #333; border-left:3px solid #00f0ff; box-sizing: border-box; border-radius:3px;">
            </div>
            
            <div id="rme-voting-status-text" style="color: #666666; font-size: 11px; text-align: center; margin-top: 5px;">Bereit</div>
            
            <div class="rme-dsgvo-footer" style="padding-top: 10px; display: flex; gap: 10px;">
                <button type="button" onclick="rmeStarteLiveVoting();" style="background:#ffcc00; color:#000; font-family:'Oswald',sans-serif; font-weight:bold; padding:12px; flex:1; border:none; border-radius:4px; cursor:pointer; text-transform:uppercase; font-size:12px;">🚀 Starten</button>
                <button type="button" onclick="rmeBeendeLiveVoting();" style="background:#cc2424; color:#fff; font-family:'Oswald',sans-serif; font-weight:bold; padding:12px; flex:1; border:none; border-radius:4px; cursor:pointer; text-transform:uppercase; font-size:12px;">🛑 Beenden</button>
                <button type="button" onclick="document.getElementById('rme-voting-admin-overlay').style.display='none';" style="background:#333; color:#aaa; font-family:'Oswald',sans-serif; font-weight:bold; padding:12px; border:1px solid #444; border-radius:4px; cursor:pointer; text-transform:uppercase; font-size:12px;">X</button>
                <button type="button" onclick="rmeLoescheVotingKomplett();" style="background:#881111; color:#fff; font-family:'Oswald',sans-serif; font-weight:bold; padding:12px; flex:1; border:none; border-radius:4px; cursor:pointer; text-transform:uppercase; font-size:12px;">🗑️ Löschen</button>
            </div>
        </form>
    </div>
</div>

</div>
 <!-- 🤖 DIE SEPARATE QUIZBOT-ANZEIGEBOX -->
<div id="rme-quizbot-sticky-box" style="display: none; background: rgba(211, 84, 0, 0.15); border-left: 4px solid #ffaa00; padding: 10px; margin: 10px 0; border-radius: 6px; font-family: Arial, sans-serif; color: #fff;">
 <!-- Hier injiziert das JavaScript gleich live die Frage hinein -->
</div>  
        <!-- HINTERGRÜNDE DIREKT AUS DER BLOB-TABELLE INITIALISIEREN -->
        <?php
        $styleErweiterung = "";
        
        // REPARATUR-SICHERUNG: Falls $_SESSION['chat_user_id'] im CMS nicht greift, nutzen wir data-id aus dem System
        $userIdSicher = 0;
        if (isset($_SESSION['chat_user_id']) && intval($_SESSION['chat_user_id']) > 0) {
            $userIdSicher = intval($_SESSION['chat_user_id']);
        } elseif (isset($_SESSION['user_id']) && intval($_SESSION['user_id']) > 0) {
            $userIdSicher = intval($_SESSION['user_id']);
        } elseif (isset($userdata['user_id']) && intval($userdata['user_id']) > 0) {
            $userIdSicher = intval($userdata['user_id']);
        }

        $bildUrlGefunden = "";

        // 1. Wenn eingeloggt: Prüfen, ob der User einen eigenen BLOB-Hintergrund hat
        if ($userIdSicher > 0) {
            $userKey = "user_" . $userIdSicher;
            $res_user_bg = mysqli_query($db_connect, "SELECT `bg_key` FROM `" . DB_PREFIX . "chat_backgrounds` WHERE `bg_key` = '$userKey' LIMIT 1");
            if ($res_user_bg && mysqli_num_rows($res_user_bg) > 0) {
                $bildUrlGefunden = "rme_background_handler.php?view_bg=" . $userKey;
            }
        }

        // 2. Fallback: Wenn kein User-Bild da ist, nach dem globalen Admin-BLOB suchen
        if (empty($bildUrlGefunden)) {
            $res_admin_bg = mysqli_query($db_connect, "SELECT `bg_key` FROM `" . DB_PREFIX . "chat_backgrounds` WHERE `bg_key` = 'global' LIMIT 1");
            if ($res_admin_bg && mysqli_num_rows($res_admin_bg) > 0) {
                $bildUrlGefunden = "rme_background_handler.php?view_bg=global";
            }
        }

        // 3. Wenn eine URL ermittelt wurde, CSS-Style injizieren
        if (!empty($bildUrlGefunden)) {
            $styleErweiterung = "background-image: url('" . $bildUrlGefunden . "'); background-size: cover; background-position: center; background-repeat: no-repeat;";
        }
        ?>

<!-- ========================================================================= -->
<!-- 📊 METRIC-NEON: FIXED LIVE-VOTING & COUNTDOWN BANNER IN DER LAYOUT-LÜCKE  -->
<!-- ========================================================================= -->
<?php
// A. Wir prüfen zuerst, ob ein aktiver Countdown läuft
$countdown_query = dbquery("SELECT end_time FROM fusionb7754_chat_countsdown WHERE status = 'active' LIMIT 1");
$countdown_aktiv = false;

if ($countdown_query && dbrows($countdown_query) > 0) {
    $cd = dbarray($countdown_query);
    $rest_zeit = intval($cd['end_time']) - time();
    
    if ($rest_zeit > 0) {
        $countdown_aktiv = true;
        ?>
        <div id="rme-countdown-banner-wrapper" style="width: 100%; background: #111111; border: none; border-bottom: 2px solid #00f0ff; padding: 12px 15px; box-sizing: border-box; margin-bottom: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: space-between; border-radius: 4px;">
            <div style="color: #00f0ff; font-family: 'Oswald', sans-serif; font-weight: bold; font-size: 14px; text-transform: uppercase; letter-spacing: 0.5px;">
                ⏱️ TIME TO SHOWTIME / SENDESTART:
            </div>
            <!-- Das große Neon-Zifferblatt -->
            <div id="rme-countdown-clock" style="color: #00f0ff; font-family: 'Oswald', sans-serif; font-weight: bold; font-size: 20px; text-shadow: 0 0 10px #00f0ff; letter-spacing: 1px;">
                --:--
            </div>
        </div>
        <!-- 🔥 INLINE-TICKER: Direkt hier verankert, damit es NIEMALS vorzeitig abbricht! -->
        <script>
        (function() {
            var restlicheSekunden = <?php echo $rest_zeit; ?>;
            var timerLabel = document.getElementById('rme-countdown-clock');
            if (!timerLabel) return;
            
            var sekunden = parseInt(restlicheSekunden, 10);
            
            // Sofortige Berechnung, damit niemals "--:--" zu sehen ist
            var initialMins = Math.floor(sekunden / 60);
            var initialSecs = sekunden % 60;
            if (initialMins < 10) initialMins = "0" + initialMins;
            if (initialSecs < 10) initialSecs = "0" + initialSecs;
            timerLabel.innerHTML = initialMins + ":" + initialSecs;
            
            var interval = setInterval(function() {
                sekunden--;
                
                if (sekunden <= 0) {
                    clearInterval(interval);
                    timerLabel.innerHTML = "00:00";
					var cdWrapper = document.getElementById('rme-countdown-banner-wrapper');
					if (cdWrapper) cdWrapper.style.display = 'none';
					 


 
                    // Automatischer Reload nach Ablauf der Zeit
                    setTimeout(function() {
                        location.reload();
                    }, 500);
                    return;
                }
                
                var mins = Math.floor(sekunden / 60);
                var secs = sekunden % 60;
                
                if (mins < 10) mins = "0" + mins;
                if (secs < 10) secs = "0" + secs;
                
                timerLabel.innerHTML = mins + ":" + secs;
            }, 1000);
        })();
        </script>
        <?php
    } else {
        // Countdown abgelaufen -> In Deiner Wunsch-Tabelle löschen
        dbquery("DELETE FROM fusionb7754_chat_countsdown");
    }
}

// B. Nur wenn KEIN Countdown läuft, zeigen wir wie gewohnt die Umfrage an!
if (!$countdown_aktiv) {
    $voting_query = dbquery("SELECT * FROM fusionb7754_chat_votings WHERE status != 'cleared' ORDER BY id DESC LIMIT 1");

    if ($voting_query && dbrows($voting_query) > 0) {
        $vote = dbarray($voting_query);
        $ist_beendet = ($vote['status'] === 'closed');
        
        $gesamt_stimmen = intval($vote['votes_1']) + intval($vote['votes_2']) + intval($vote['votes_3']);
        $pct1 = $gesamt_stimmen > 0 ? round((intval($vote['votes_1']) / $gesamt_stimmen) * 100) : 0;
        $pct2 = $gesamt_stimmen > 0 ? round((intval($vote['votes_2']) / $gesamt_stimmen) * 100) : 0;
        $pct3 = $gesamt_stimmen > 0 ? round((intval($vote['votes_3']) / $gesamt_stimmen) * 100) : 0;

        $unterkanten_farbe = $ist_beendet ? "#00ff66" : "#ffcc00";
        $titel_text        = $ist_beendet ? "✅ ENDERGEBNIS DER UMFRAGE: " : "📊 LIVE-UMFRAGE: ";
        $is_closed_js      = $ist_beendet ? "true" : "false";
        ?>
        
        <div class="rme-chat-voting-wrapper" style="width: 100%; background: #111111; border: none; border-bottom: 2px solid <?php echo $unterkanten_farbe; ?>; padding: 10px 15px; box-sizing: border-box; margin-bottom: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.5); display: flex; flex-direction: column; gap: 5px; border-radius: 4px;">
            <div style="color: <?php echo $unterkanten_farbe; ?>; font-family: 'Oswald', sans-serif; font-weight: bold; font-size: 13px; text-transform: uppercase;">
                <?php echo $titel_text . htmlspecialchars($vote['question'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; font-size: 12px; font-family: sans-serif;">
                
                <div style="display: inline-flex; align-items: center; gap: 5px;">
                    <button type="button" class="rme-tab-btn" onclick="rmeSendeStimmeLive(<?php echo $vote['id']; ?>, 1, <?php echo $is_closed_js; ?>);" style="height:26px; padding:0 8px; font-size:11px; margin:0; background:#1a1a1a!important; color:#fff!important; border:1px solid #333!important;">👍 <?php echo htmlspecialchars($vote['option_1'], ENT_QUOTES, 'UTF-8'); ?></button>
                    <span style="color: #aaa; font-weight: bold;"><?php echo $pct1; ?>% <small style="color:#555;">(<?php echo $vote['votes_1']; ?>)</small></span>
                </div>
                
                <span style="color: #222;">|</span>
                
                <div style="display: inline-flex; align-items: center; gap: 5px;">
                    <button type="button" class="rme-tab-btn" onclick="rmeSendeStimmeLive(<?php echo $vote['id']; ?>, 2, <?php echo $is_closed_js; ?>);" style="height:26px; padding:0 8px; font-size:11px; margin:0; background:#1a1a1a!important; color:#fff!important; border:1px solid #333!important;">👍 <?php echo htmlspecialchars($vote['option_2'], ENT_QUOTES, 'UTF-8'); ?></button>
                    <span style="color: #aaa; font-weight: bold;"><?php echo $pct2; ?>% <small style='color:#555;'>(<?php echo $vote['votes_2']; ?>)</small></span>
                </div>
                
                <?php if (!empty($vote['option_3'])): ?>
                    <span style="color: #222;">|</span>
                    <div style="display: inline-flex; align-items: center; gap: 5px;">
                        <button type="button" class="rme-tab-btn" onclick="rmeSendeStimmeLive(<?php echo $vote['id']; ?>, 3, <?php echo $is_closed_js; ?>);" style="height:26px; padding:0 8px; font-size:11px; margin:0; background:#1a1a1a!important; color:#fff!important; border:1px solid #333!important;">👍 <?php echo htmlspecialchars($vote['option_3'], ENT_QUOTES, 'UTF-8'); ?></button>
                        <span style="color: #aaa; font-weight: bold;"><?php echo $pct3; ?>% <small style='color:#555;'>(<?php echo $vote['votes_3']; ?>)</small></span>
                    </div>
                <?php endif; ?>
                
            </div>
        </div>
    <?php 
    }
} 
?>

<!-- DAS CHAT NACHRICHTENFENSTER (Bleibt unberührt im Original!) -->
<div id="rme-chat-window" style="<?php echo $styleErweiterung; ?>">
<div style="color:#0f0; font-weight: bold; text-align:center; padding-top:50px;">Lade Nachrichtenverlauf...</div>
</div>


        
        <!-- FORMULAR MIT MENÜS (BLOCKIERT DOPPELTEN SUBMIT) -->
        <form method="post" action="rme_chat.php" id="rme-chat-form" onsubmit="return false;" class="rme-main-chat-form">
           
<?php
// DYNAMISCHE MYSQL ENGINE FÜR SMILEYS & SOUNDS
$rme_chat_board_sounds = array();

if (isset($db_connect) && $db_connect) {
    // A) Smileys auslesen
    $result_sm = mysqli_query($db_connect, "SELECT id, kuerzel, kategorie FROM `fusionb7754_chat_smilies`");
    if ($result_sm && mysqli_num_rows($result_sm) > 0) {
        while ($row = mysqli_fetch_assoc($result_sm)) {
            $zielKategorie = !empty($row['kategorie']) ? trim($row['kategorie']) : 'Uploads';
            $virtueller_pfad = 'rme_smilies_handler.php?action=render_image&id=' . $row['id'];
            $gifSmileys[$zielKategorie][$row['kuerzel']] = $virtueller_pfad;
        }
    }

    // B) Sounds auslesen
    $result_so = mysqli_query($db_connect, "SELECT sound_name, sound_command, datei_name FROM `fusionb7754_chat_sounds` ORDER BY id ASC");
    if ($result_so && mysqli_num_rows($result_so) > 0) {
        while ($row = mysqli_fetch_assoc($result_so)) {
            $rme_chat_board_sounds[] = $row;
        }
    }
}

if (file_exists('smilies_config.php')) { include 'smilies_config.php'; }

// Prüfen, ob Du der Chef bist
$mein_id_check = isset($_SESSION['chat_user_id']) ? intval($_SESSION['chat_user_id']) : 0;
$sess_user = $_SESSION['chat_user_name'] ?? '';
$ich_bin_der_wahre_chef = ($mein_id_check === 18 || strtolower($sess_user) === 'dj-tomjac');
?>


<div class="rme-chat-controls-row">
<!-- ========================================================================= -->
<!-- 1. POPUP: SMILEYS & GIFS AUS DER DATENBANK MIT INTEGRATED TABS            -->
<!-- ========================================================================= -->
<div class="rme-popup-trigger-wrapper">
    <button type="button" onclick="var p = document.getElementById('rme-smiley-popup'); p.style.display = (p.style.display === 'block') ? 'none' : 'block';" class="rme-control-btn">
        <span>😊 Smileys</span>
        <span class="rme-arrow-indicator">▼</span>
    </button>
    
    <div id="rme-smiley-popup" class="rme-smiley-popup-container">
        
        <?php
        // 🛠 1. DATENBANK-SMILEYS LADEN
        $popup_smilies_by_kat = [];
        $db_popup_query = dbquery("SELECT `id`, `kuerzel`, `kategorie` FROM fusionb7754_chat_smilies ORDER BY `kategorie` ASC, `id` ASC");
        if ($db_popup_query && dbrows($db_popup_query) > 0) {
            while ($p_sm = dbarray($db_popup_query)) {
                $popup_smilies_by_kat[$p_sm['kategorie']][] = $p_sm;
            }
        }

        // 🛠 2. ALLE KATEGORIEN FÜR DIE TABS VEREINIGEN (System + DB)
        $alle_kategorien_namen = [];
        if (isset($systemEmojis) && is_array($systemEmojis)) {
            foreach (array_keys($systemEmojis) as $kat) {
                $alle_kategorien_namen[trim($kat)] = true;
            }
        }
        foreach (array_keys($popup_smilies_by_kat) as $kat) {
            $alle_kategorien_namen[trim($kat)] = true;
        }
        // Alphabetisch sortieren, damit es im Menü immer ordentlich aussieht
        ksort($alle_kategorien_namen);
        ?>

        <!-- ✔ VEREINTE TABS-LEISTE (KEINE DOPPELTEN TABS MEHR!) -->
        <div class="rme-smiley-tabs-bar">
            <?php 
            $tabIndex = 0;
            // Wir mappen die echten Namen auf fortlaufende Nummern für JavaScript
            $kategorie_zu_index_map = []; 
            
            foreach (array_keys($alle_kategorien_namen) as $katName) {
                $activeClass = ($tabIndex === 0) ? "rme-smiley-tab active" : "rme-smiley-tab";
                echo '<button type="button" class="'.$activeClass.'" onclick="rmeSwitchSmileyTab(event, \'rme-sm-tab-'.$tabIndex.'\')">'.htmlspecialchars($katName, ENT_QUOTES, 'UTF-8').'</button>';
                $kategorie_zu_index_map[$katName] = $tabIndex;
                $tabIndex++;
            }
            ?>
        </div>

        <!-- INHALTE DER VEREINTEN TABS -->
        <?php 
        $ich_bin_der_wahre_boss = (
            (isset($_SESSION['chat_user_id']) && intval($_SESSION['chat_user_id']) === 18) || 
            (isset($_SESSION['chat_user_name']) && strtolower($_SESSION['chat_user_name']) === 'dj-tomjac') ||
            (isset($_SESSION['rme_chat_user_id']) && intval($_SESSION['rme_chat_user_id']) === 18) ||
            (isset($ich_bin_leitung) && $ich_bin_leitung)
        );

        foreach ($kategorie_zu_index_map as $katName => $cIndex) {
            $displayStyle = ($cIndex === 0) ? "display: block;" : "display: none;";
            echo "<div id='rme-sm-tab-".$cIndex."' class='rme-smiley-tab-content' style='".$displayStyle."'>";
            echo "<div class='rme-smiley-category-title'>".htmlspecialchars($katName, ENT_QUOTES, 'UTF-8')."</div>";
            
            // A) Zuerst die System-Emojis ausgeben (falls für diese Kategorie vorhanden)
            if (isset($systemEmojis[$katName]) && is_array($systemEmojis[$katName])) {
                echo "<div class='rme-smiley-flex-row' style='margin-bottom: 12px;'>";
                foreach ($systemEmojis[$katName] as $kuerzel => $emoji) {
                    echo "<span onclick=\"var inp = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message'); if(inp){ inp.value += ' ".addslashes((string)$kuerzel)." '; inp.focus(); }\" title='".htmlspecialchars((string)$kuerzel, ENT_QUOTES, 'UTF-8').'\' class=\'rme-smiley-item\' style=\'cursor:pointer;\'>'.$emoji.'</span>';
                }
                echo "</div>";
            }
            
            // B) Direkt darunter die animierten Datenbank-Gifs ausgeben (falls vorhanden)
            if (isset($popup_smilies_by_kat[$katName]) && is_array($popup_smilies_by_kat[$katName])) {
                echo "<div class='rme-picker-grid'>";
                foreach ($popup_smilies_by_kat[$katName] as $smRow) {
                    $sichererCodeHTML = htmlspecialchars((string)$smRow['kuerzel'], ENT_QUOTES, 'UTF-8');
                    $jsSichererCode   = addslashes((string)$smRow['kuerzel']);
                    $finalerBildPfad  = "rme_smilies_handler.php?action=render_image&id=".$smRow['id'];
                    
                    echo '<div class="rme-picker-item" id="rme-db-smbox-'.$smRow['id'].'" style="position:relative;">';
                    echo '<img src="'.$finalerBildPfad.'" class="rme-gif-item" ondblclick="var inp = document.getElementById(\'rme-chat-input\') || document.getElementById(\'chat-message-input\') || document.getElementById(\'message\'); if(inp){ inp.value += \' '.$jsSichererCode.' \'; inp.focus(); }" title="'.$sichererCodeHTML.'">';
                    
                    if ($ich_bin_der_wahre_boss) {
                        echo '<span onclick="rmeDeleteSmileyFromPopup('.$smRow['id'].', event);" style="position:absolute !important; top:-4px !important; right:-4px !important; background:#cc2424 !important; color:#ffffff !important; font-size:10px !important; font-weight:bold !important; padding:0px 4px !important; border-radius:50% !important; cursor:pointer !important; z-index:999 !important; line-height:13px !important; box-shadow:0 0 4px #000 !important; font-family:sans-serif !important;" title="Diesen Smiley restlos aus der Datenbank löschen">×</span>';
                    }
                    echo '</div>';
                }
                echo "</div>";
            }
            
            echo "</div>"; // Schließt rme-smiley-tab-content
        }
        ?>
    </div>
</div>


            

			
<!-- ========================================================================= -->
<!-- REPARIERT: SOUNDBOARD - DIE AKTUALISIERTE LIVE-DATENBANK-VERSION           -->
<!-- ========================================================================= -->
<div class="rme-popup-trigger-wrapper">
    <button type="button" onclick="var p = document.getElementById('rme-soundboard-popup'); p.style.display = (p.style.display === 'block') ? 'none' : 'block';" class="rme-control-btn">
        <span>🎵 Sounds</span>
        <span class="rme-arrow-indicator">▼</span>
    </button>
    
    <!-- Das ausklappbare Soundboard -->
    <div id="rme-soundboard-popup" class="rme-sound-popup-container rme-soundboard-compact-box">
        <p class="rme-soundboard-title">🎵 Soundboard</p>
        <div class="rme-soundboard-grid rme-sound-compact-grid">
            <?php 
            // 👑 CHEF-ERKENNUNG VORBEREITEN
            $ich_bin_der_wahre_boss = (
                (isset($_SESSION['chat_user_id']) && intval($_SESSION['chat_user_id']) === 18) || 
                (isset($_SESSION['chat_user_name']) && strtolower($_SESSION['chat_user_name']) === 'dj-tomjac') ||
                (isset($_SESSION['rme_chat_user_id']) && intval($_SESSION['rme_chat_user_id']) === 18) ||
                (isset($ich_bin_leitung) && $ich_bin_leitung)
            );

            // Wir holen alle registrierten Sounds direkt live aus Deiner neuen Tabelle
            $sound_query = dbquery("SELECT id, sound_name, sound_command FROM fusionb7754_chat_sounds ORDER BY id ASC");

            if ($sound_query && dbrows($sound_query) > 0) {
                while ($snd = dbarray($sound_query)) {
                    $s_id = intval($snd['id']);
                    
                    // Wir packen den Button in ein Gehäuse mit relativer Position für das Löschkreuz
                    echo '<div class="rme-soundboard-item-wrapper" id="rme-soundbox-'.$s_id.'" style="position:relative; display:inline-block; margin:2px;">';
                    
                    // Der originale Sound-Button
                    echo '<button type="button" class="rme-sound-btn" onclick="rmeSpieleDbSound('.$s_id.', \''.addslashes($snd['sound_command']).'\'); document.getElementById(\'rme-soundboard-popup\').style.display=\'none\';" style="margin:0; width:100%;">';
                    echo htmlspecialchars($snd['sound_name']);
                    echo '</button>';
                    
                    // 🔥 DER CHEF-RIEGEL: Nur DU bekommst das rote Kreuz über dem Jingle!
                    if ($ich_bin_der_wahre_boss) {
                        echo '<span onclick="rmeDeleteSoundFromBoard('.$s_id.', event);" style="position:absolute !important; top:-4px !important; right:-4px !important; background:#cc2424 !important; color:#ffffff !important; font-size:10px !important; font-weight:bold !important; padding:0px 4px !important; border-radius:50% !important; cursor:pointer !important; z-index:15 !important; line-height:13px !important; box-shadow:0 0 4px #000 !important; font-family:sans-serif !important;" title="Diesen Jingle restlos aus der Datenbank löschen">×</span>';
                    }
                    
                    echo '</div>'; // Schließt rme-soundboard-item-wrapper
                }
            } else {
                echo '<div style="color: #bbb; padding: 10px; font-style: italic; font-size: 12px; text-align: center; width: 100%;">Keine Sounds hinterlegt.</div>';
            }
            ?>

        </div>
    </div>
</div>
<!-- ========================================================================= -->

<!-- ========================================================================= -->
<!-- 🔤 1. SCHRIFTARTEN-POPUP (SICHER & MODERN)                                 -->
<!-- ========================================================================= -->
<div class="rme-popup-trigger-wrapper rme-font-wrapper">
    <button type="button" onclick="var p = document.getElementById('rme-font-popup-box'); p.style.display = (p.style.display === 'block') ? 'none' : 'block'; document.getElementById('rme-size-popup-box').style.display='none';" class="rme-control-btn rme-font-trigger-btn">
        <span>🔤 Schriftart</span>
        <span id="rme-font-status" class="rme-status-badge"></span>
        <span class="rme-arrow-indicator">▼</span>
    </button>
    
    <div id="rme-font-popup-box" class="rme-smiley-popup-container rme-chef-zentrale-box rme-modern-picker-popup">
        <h3 class="rme-chef-zentrale-title rme-picker-headline">🔤 Schriftarten</h3>
        <p class="rme-picker-subtext">Wähle eine Schriftart:</p>
        
        <div class="rme-picker-list-container">
            <?php if (isset($rmeSchriftarten) && is_array($rmeSchriftarten)) {
                foreach ($rmeSchriftarten as $anzeigeName => $cssFamily) {
                    $parts = explode(',', $cssFamily);
                    $saubererName = trim(str_replace(["'", '"'], "", $parts[0]));
                    
                    echo "<div class='rme-picker-list-item' style='font-family: ".htmlspecialchars($cssFamily, ENT_QUOTES, 'UTF-8').";' onclick=\"document.getElementById('rme-font-picker').value='".htmlspecialchars($saubererName, ENT_QUOTES, 'UTF-8')."'; if(typeof rmeSpeichereSchriftart === 'function'){ rmeSpeichereSchriftart('".htmlspecialchars($saubererName, ENT_QUOTES, 'UTF-8')."'); } else { $('#rme-font-picker').trigger('change'); } document.getElementById('rme-font-popup-box').style.display='none';\">";
                    echo htmlspecialchars($anzeigeName, ENT_QUOTES, 'UTF-8');
                    echo "</div>";
                }
            } ?>
        </div>
        <select id="rme-font-picker" style="display:none !important;"><option value=""></option><?php foreach($rmeSchriftarten as $a=>$c){ $p=explode(',',$c); $s=trim(str_replace(["'",'"'],"",$p[0])); echo "<option value='".htmlspecialchars($s,ENT_QUOTES,'UTF-8')."'></option>"; } ?></select>
    </div>
</div>

<!-- ========================================================================= -->
<!-- 📐 2. SCHRIFTGRÖSSEN-POPUP (SICHER & MODERN)                               -->
<!-- ========================================================================= -->
<div class="rme-popup-trigger-wrapper rme-size-wrapper">
    <button type="button" onclick="var p = document.getElementById('rme-size-popup-box'); p.style.display = (p.style.display === 'block') ? 'none' : 'block'; document.getElementById('rme-font-popup-box').style.display='none';" class="rme-control-btn rme-size-trigger-btn">
        <span>📐 Textgröße</span>
        <span id="rme-size-status" class="rme-status-badge"></span>
        <span class="rme-arrow-indicator">▼</span>
    </button>
    
    <div id="rme-size-popup-box" class="rme-smiley-popup-container rme-chef-zentrale-box rme-modern-picker-popup">
        <h3 class="rme-chef-zentrale-title rme-picker-headline">📐 Schriftgröße</h3>
        <p class="rme-picker-subtext">Wähle die Pixel-Größe:</p>
        
        <div class="rme-size-tiles-grid">
            <?php if (isset($rmeSchriftgroessen) && is_array($rmeSchriftgroessen)) {
                foreach ($rmeSchriftgroessen as $groesse) {
                    $val = intval($groesse);
                    echo "<button type='button' class='rme-size-numeric-tile' onclick=\"document.getElementById('rme-size-picker').value='".$val."'; if(typeof rmeSpeichereSchriftgroesse === 'function'){ rmeSpeichereSchriftgroesse('".$val."'); } else { $('#rme-size-picker').trigger('change'); } document.getElementById('rme-size-popup-box').style.display='none';\">".$val."px</button>";
                }
            } ?>
        </div>
        <select id="rme-size-picker" style="display:none !important;"><option value=""></option><?php foreach($rmeSchriftgroessen as $g){ echo "<option value='".intval($g)."'></option>"; } ?></select>
    </div>
</div>


<input type="color" id="rme-color-picker" title="Wähle deine Textfarbe" value="#ffffff" class="rme-control-color">

    <button type="button" id="rme-btn-bold" title="Fett" class="rme-control-btn-format rme-btn-b">B</button>
    <button type="button" id="rme-btn-italic" title="Kursiv" class="rme-control-btn-format rme-btn-i">I</button>
    <button type="button" id="rme-btn-underline" title="Unterstrichen" class="rme-control-btn-format rme-btn-u">U</button>

<!-- REPARIERT & SAUBER IN CSS VERLAGERT -->
<?php
// 👑 DER ID-DETEKTOR: Holt sich die echte Benutzer-ID aus dem Speicher Deines CMS
$kontroll_user_id = 0;
if (isset($session_userid)) { $kontroll_user_id = intval($session_userid); }
elseif (isset($_SESSION['chat_user_id'])) { $kontroll_user_id = intval($_SESSION['chat_user_id']); }
elseif (isset($userdata['user_id'])) { $kontroll_user_id = intval($userdata['user_id']); }

// 🎯 DEIN GENIALER PLAN: Nur wer registriert ist (ID zwischen 1 und 1999), sieht das Zahnrad!
// Gäste mit ID 2000+ überspringen diesen Block lautlos.
if ($kontroll_user_id > 0 && $kontroll_user_id < 2000): 
?>
        <!-- 🔥 CONTAINER-UPGRADE: rme-popup-trigger-wrapper sorgt dafür, dass das CSS-Schild perfekt greift! -->
        <div class="rme-popup-trigger-wrapper rme-settings-and-chef-row">
            
            <!-- 1. 🔥 UNZERSTÖRBARER BUTTON: Schaltet das Popup direkt im HTML fehlerfrei ein/aus! -->
            <button type="button" id="rme-settings-btn" class="rme-control-btn-settings" 
                    onclick="var p = document.getElementById('rme-settings-popup'); p.style.display = (p.style.display === 'block') ? 'none' : 'block';" 
                    title="Einstellungen">⚙️</button>

            <!-- Das ausklappbare Einstellungs-Popup (Standardmäßig ausgeblendet) -->
            <div id="rme-settings-popup" class="rme-settings-popup-box" style="display: none;">
                <h4 class="rme-settings-popup-title">⚙️ Profil-Einstellungen</h4>

                <div class="rme-settings-sound-row">
                    <label for="rme-sound-toggle" class="rme-sound-toggle-label">🔊 Chat-Sounds aktivieren</label>
                    <input type="checkbox" id="rme-sound-toggle" class="rme-sound-toggle-input" checked>
                </div>

                <?php if ($ist_ein_chat_user): ?>
					<!-- BEREICH A: Nur für reine Chat-User (_CU) -> Der Avatar-Upload -->
					<div class="rme-user-avatar-container">
						<label class="rme-settings-popup-label">Profilbild hochladen (JPG/PNG, max. 500KB):</label>
						
						<!-- Das Datei-Auswahlfeld -->
						<input type="file" id="rme_avatar_file" accept="image/jpeg,image/png" class="rme-settings-popup-input">
						
						<!-- Der Button mit direktem Klick-Befehl (onclick) -->
						<button type="button" onclick="rmeSubmitChatAvatar(event);" class="rme-settings-popup-submit">Bild speichern</button>
					</div>


                <?php else: ?>
                    <!-- BEREICH B: Platzhalter für Homepage-User -->
                    <div class="rme-settings-popup-placeholder">
                        <p class="rme-settings-popup-welcome">Hallo <?php echo htmlspecialchars($display_sidebar_name, ENT_QUOTES, 'UTF-8'); ?>!</p>
                        <p class="rme-settings-popup-text">Als registriertes Homepage-Mitglied wird dein normales <span>Foren-Profilbild</span> automatisch im Chat angezeigt.</p>
                    </div>
                <?php endif; ?>

                <div class="rme-settings-font-row" style="margin-top: 10px; display: flex; align-items: center; justify-content: space-between;">
                    <span class="rme-settings-popup-label" style="margin: 0;">📏 Schriftgröße:</span>
                    <div class="rme-font-btn-group">
                        <button type="button" onclick="rmeChangeFontSize(-1);" class="rme-settings-popup-schrift" style="padding: 3px 8px; margin: 0 2px; background: #444 !important; min-width: auto;">A-</button>
                        <!-- NEU: Der Reset-Button auf Standardgröße (16px) -->
                        <button type="button" onclick="rmeResetFontSize();" class="rme-settings-popup-schrift" style="padding: 3px 8px; margin: 0 2px; background: #0076a3 !important; min-width: auto;" title="Standardgröße wiederherstellen">🔄</button>
                        <button type="button" onclick="rmeChangeFontSize(1);" class="rme-settings-popup-schrift" style="padding: 3px 8px; margin: 0 2px; background: #444 !important; min-width: auto;">A+</button>
                    </div>
                </div>

                <div class="rme-settings-theme-row" style="margin-top: 10px; display: flex; align-items: center; justify-content: space-between;">
                    <span class="rme-settings-popup-label" style="margin: 0;">🌙 Design-Modus:</span>
                    <button type="button" id="rme-theme-toggle-btn" onclick="rmeToggleTheme();" class="rme-settings-popup-submit" style="padding: 4px 12px; margin: 0; background: #0076a3 !important; min-width: auto;">Dunkel 🌙</button>
                </div>
                <div class="rme-settings-time-row" style="margin-top: 10px; display: flex; align-items: center; justify-content: space-between;">
                    <span class="rme-settings-popup-label" style="margin: 0;">🕒 Zeitstempel:</span>
                    <button type="button" id="rme-time-toggle-btn" onclick="rmeToggleTimestamps();" class="rme-settings-popup-submit" style="padding: 4px 12px; margin: 0; background: #0076a3 !important; min-width: auto;">Anzeigen</button>
                </div>

<hr class="rme-chef-divider">

<!-- BEREICH C: EIGENER CHAT-HINTERGRUND (FÜR ALLE REGISTRIERTEN) -->
<h4 class="rme-settings-popup-title" style="color: #ffaa00;">🖼️ Eigener Chat-Hintergrund</h4>

<!-- REPARIERT: Ein sauberes div statt eines kollidierenden forms! -->
<div class="rme-user-bg-container">
    <label class="rme-settings-popup-label">Eigenes Hintergrundbild (JPG/PNG/WEBP, max. 2MB):</label>
    
    <input type="file" id="rme_user_bg_file" accept="image/jpeg,image/png,image/webp,image/gif" class="rme-settings-popup-input">
    
    <button type="button" onclick="rmeSubmitUserBackground(event);" class="rme-settings-popup-submit rme-btn-bg-activate">Hintergrund speichern</button>
    
    <button type="button" onclick="rmeResetUserBackground(event);" class="rme-settings-popup-submit rme-btn-bg-disable" style="margin-top: 8px !important;">🛑 Auf Standard zurücksetzen</button>
</div>



                <iframe id="rme_upload_target" name="rme_upload_target" style="display:none;"></iframe>
                <hr class="rme-chef-divider" style="margin: 15px 0 10px 0;">
                
                <!-- BEREICH D: LIVE-SYSTEM-STATUS (NEON-MONITOR) -->
                <div class="rme-settings-status-box" style="font-family: 'Courier New', Courier, monospace; font-size: 11px; padding: 5px; background: #000; border-radius: 4px; border: 1px solid #222;">
                    <div style="color: #888; font-weight: bold; margin-bottom: 4px; text-transform: uppercase;">📊 Chat System-Status:</div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 2px;">
                        <span>Verbindung:</span>
                        <span id="rme-status-network" style="color: #00ff00; text-shadow: 0 0 5px #00ff00;">PRÜFEN...</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 2px;">
                        <span>Server-Ping:</span>
                        <span id="rme-status-ping" style="color: #00ff00; text-shadow: 0 0 5px #00ff00;">-- ms</span>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span>Browser-Cache:</span>
                        <span id="rme-status-storage" style="color: #00ff00; text-shadow: 0 0 5px #00ff00;">BEREIT ✔️</span>
                    </div>
                </div>

            </div> <!-- Schließt rme-settings-popup-box -->

            <!-- ========================================================================= -->
            <!-- DIE DYNAMISCHE CHEF-ZENTRALE NEBEN DEM ZAHNRAD                             -->
            <!-- ========================================================================= -->
            <?php if ($ich_bin_der_wahre_chef): ?>
            <div class="rme-popup-trigger-wrapper rme-chef-zentrale-wrapper">
                <button type="button" onclick="var p = document.getElementById('rme-chef-zentrale-popup'); p.style.display = (p.style.display === 'block') ? 'none' : 'block';" class="rme-control-btn rme-chef-zentrale-btn">
                    <span>🛠️ Chef-Zentrale</span>
                </button>
                
                <div id="rme-chef-zentrale-popup" class="rme-chef-popup-container rme-chef-zentrale-box">
                    <h3 class="rme-chef-zentrale-title">🚀 Chef-Verwaltung</h3>
                    
                    <!-- TEIL A: SMILEY UPLOADER -->
         <!-- TEIL A: SMILEY UPLOADER -->
        <p style="color:#ffaa00; font-weight:bold; margin-bottom:5px; margin-top:15px; font-family: Arial;">😊 Smiley in MySQL einbrennen</p>
        <form id="rme-admin-smiley-form" enctype="multipart/form-data">
            
            <!-- REPARIERT: Dieses Dropdown baut sich ab jetzt vollautomatisch aus Deinen MySQL-Kategorien auf! -->
            <select id="rme_sm_kategorie" class="rme-admin-select rme-popup-input-field" style="width:100%; margin-bottom:8px; padding:5px; background:#222; color:#fff; border:1px solid #444;" onchange="var w = document.getElementById('rme_popup_sm_kategorie_wrapper'); if(w) { w.style.display = (this.value === 'NEU') ? 'block' : 'none'; }">
                
                <!-- Deine 5 festen Standard-Kategorien -->
                <option value="Lob & Reaktionen">Lob & Reaktionen</option>
                <option value="Liebe">Liebe</option>
                <option value="Frech">Frech</option>
                <option value="Geburtstag">Geburtstag</option>
                <option value="Party">Party</option>
                
                <?php 
                // Holt alle tatsächlich benutzten Kategorien aus der MySQL-Tabelle,
                // damit Du sie direkt im Dropdown wieder auswählen kannst!
                if (!empty($gifSmileys) && is_array($gifSmileys)) {
                    foreach (array_keys($gifSmileys) as $katName) {
                        // Verhindert, dass Standard-Kategorien doppelt im Dropdown landen
                        $standards = array("Lob & Reaktionen", "Liebe", "Frech", "Geburtstag", "Party", "Uploads");
                        if (!in_array($katName, $standards)) {
                            echo '<option value="'.htmlspecialchars($katName, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($katName, ENT_QUOTES, 'UTF-8').'</option>';
                        }
                    }
                }
                ?>
                
                <option value="NEU">➕ Neue Kategorie erstellen...</option>
            </select>
            
            <div id="rme_popup_sm_kategorie_wrapper" style="display: none; margin-bottom: 8px;">
                <input type="text" id="rme_sm_kategorie_neu_unten" class="rme-popup-input-field" placeholder="Name der neuen Kategorie...">
            </div>
            
<!-- REPARIERT: Ohne das required-Attribut am Ende -->
<input type="text" id="rme_sm_kuerzel" class="rme-popup-input-field" placeholder="Chat-Kürzel (z.B. :party:)">
<input type="file" id="rme_sm_datei" class="rme-popup-file-field" accept="image/gif, image/png, image/jpeg">

            <button type="button" onclick="rmeSubmitNewSmiley(event);" class="rme-popup-submit-btn btn-save-smiley">💾 Smiley speichern</button>
        </form>


                    <hr class="rme-chef-divider">

                    <!-- TEIL B: SOUND UPLOADER -->
                    <p class="rme-chef-section-title">🎵 Neuen Sound hinzufügen</p>
                    <form id="rme-admin-sound-form" enctype="multipart/form-data">
                        <input type="text" id="rme_so_name" class="rme-popup-input-field" placeholder="Button-Name (z.B. Hupe 🚨)" required>
                        <input type="file" id="rme_so_datei" class="rme-popup-file-field" accept="audio/mp3" required>
                        <button type="button" onclick="rmeSubmitNewSound(event);" class="rme-popup-submit-btn btn-upload-mp3">💾 MP3 hochladen</button>
                    </form>
                    <hr class="rme-chef-divider">

                    <!-- TEIL C: GLOBALER CHAT-HINTERGRUND (PURE BLOB VERSION) -->
                    <p class="rme-chef-section-title" style="color:#00ffaa;">🖼️ Globaler Chat-Hintergrund</p>
                    
                    <div id="rme-admin-bg-status-box" class="rme-bg-status-display">
                        <?php
                        // REPARIERT: Prüft jetzt direkt die neue BLOB-Tabelle statt des alten Textpfads
                        $res_status = mysqli_query($db_connect, "SELECT `bg_key` FROM `" . DB_PREFIX . "chat_backgrounds` WHERE `bg_key` = 'global' LIMIT 1");
                        $hatGlobalBg = ($res_status && mysqli_num_rows($res_status) > 0);
                        
                        if ($hatGlobalBg) {
                            echo "🟢 Aktiv: <span class='rme-bg-status-active'>Datenbank-Hintergrund (Aktiv)</span>";
                        } else {
                            echo "<span class='rme-bg-status-disabled'>⚪ Ausgeschaltet (Standardfarbe)</span>";
                        }
                        ?>
                    </div>

                    <form id="rme-admin-bg-form" enctype="multipart/form-data">
                        <input type="file" id="rme_bg_datei" class="rme-popup-file-field" accept="image/gif, image/png, image/jpeg, image/webp" required>
                        <button type="button" onclick="rmeSubmitChatBackground(event);" class="rme-popup-submit-btn rme-btn-bg-activate">📢 Hintergrund aktivieren</button>
                        
                        <!-- REPARIERT: Nutzt jetzt die neue Variable für die Anzeige des Ausschalt-Buttons -->
                        <?php if ($hatGlobalBg): ?>
                            <button type="button" id="rme-btn-disable-bg" onclick="rmeDisableChatBackground(event);" class="rme-popup-submit-btn rme-btn-bg-disable" style="margin-top:0 !important;">🛑 Ausschalten</button>
                        <?php else: ?>
                            <button type="button" id="rme-btn-disable-bg" onclick="rmeDisableChatBackground(event);" class="rme-popup-submit-btn rme-btn-bg-disable" style="display: none; margin-top:0 !important;">🛑 Ausschalten</button>
                        <?php endif; ?>
                    </form>


                </div> <!-- Schließt rme-chef-zentrale-popup SAFELY! -->
            </div> <!-- Schließt rme-popup-trigger-wrapper SAFELY! -->
            <?php endif; ?>


            <!-- ========================================================================= -->
            <!-- LOGIN-SOUND BUTTON (BEREINIGTE KLASSEN-STRUKTUR)                          -->
            <!-- ========================================================================= -->
            <div class="rme-popup-trigger-wrapper rme-login-sound-wrapper rme-intro-btn-spacer">
                <button type="button" onclick="var p = document.getElementById('rme-login-sound-popup'); p.style.display = (p.style.display === 'block') ? 'none' : 'block';" class="rme-control-btn rme-login-sound-btn">
                    <span>👑 Login-Sounds</span>
                </button>
                
                <div id="rme-login-sound-popup" class="rme-smiley-popup-container rme-chef-zentrale-box rme-intro-popup-box">
                    <h3 class="rme-chef-zentrale-title rme-intro-popup-title">🎵 Mod-Einzugs-Intros</h3>
                    <p class="rme-intro-popup-desc">Tippe den Namen (z.B. DJ), wähle den Mod aus und lade sein MP3 hoch.</p>
                 
                    <form id="rme-admin-intro-upload-form" class="rme-intro-upload-form-layout">
                        <div class="rme-intro-form-group rme-relative-box">
                            <label class="rme-intro-form-label">1. Moderator Name suchen:</label>
                            <!-- Das Suchfeld -->
                            <input type="text" id="rme_intro_search_name" placeholder="Name tippen (z.B. DJ)..." class="rme-intro-search-input-field" autocomplete="off">
                            <!-- Hier ploppen die Vorschläge live auf -->
                            <div id="rme_intro_search_results" class="rme-intro-live-dropdown-results"></div>
                            <!-- Die ID wird unsichtbar im Hintergrund gespeichert -->
                            <input type="hidden" id="rme_intro_target_id_manual">
                            <input type="hidden" id="rme_intro_target_name_manual">
                        </div>
                        <div class="rme-intro-form-group">
                            <label class="rme-intro-form-label">2. Intro-MP3 auswählen:</label>
                            <input type="file" id="rme_intro_file" accept="audio/mp3" class="rme-intro-file-upload-field" required>
                        </div>
                        <button type="button" onclick="rmeUploadModIntroManual(event);" class="rme-popup-submit-btn rme-intro-submit-action-btn">📢 Intro hochladen / überschreiben</button>
                    </form>

                    <div class="rme-intro-manager-list rme-intro-list-font-base">
                        <div class="rme-intro-list-header-title">Aktive Intros in der...</div>

<?php
                        $res_active = mysqli_query($db_connect, "SELECT `user_id`, `user_name` FROM `" . RME_DB_PREFIX . "chat_intro` ORDER BY `user_name` ASC");
                        if ($res_active && mysqli_num_rows($res_active) > 0) {
                            while ($act = mysqli_fetch_assoc($res_active)) {
                                echo '<div style="margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between; padding:6px; border-radius:3px;">';
                                echo '<span>🎵 <strong>' . htmlspecialchars($act['user_name']) . '</strong> <small style="color:#666;">(ID: '.$act['user_id'].')</small></span>';
                                
                                // 🔥 DER BUTTON-BEHÄLTER: Gruppiert Zünden und Löschen nebeneinander
                                echo '<div style="display:flex; gap: 4px;">';
                                
                                // 🔊 DER LIVE-ZÜNDER: Schickt das Intro-Signal sofort live ab zum Anhören!
                                echo '<button type="button" onclick="rmeTriggerIntroLive('.$act['user_id'].');" class="rme-settings-popup-submit" style="padding:2px 8px; font-size:11px; margin:0; background:#00ff00 !important; color:#000 !important; font-weight:bold; min-width:auto;" title="Intro jetzt live im Chat abspielen">🔊 Zünden</button>';
                                
                                // Dein bewährter Löschen-Button
                                echo '<button type="button" onclick="rmeDeleteModIntro('.$act['user_id'].');" class="rme-settings-popup-submit" style="padding:2px 8px; font-size:11px; margin:0; background:#cc2424 !important; min-width:auto;">❌ Löschen</button>';
                                
                                echo '</div>'; // Schließt den Button-Behälter
                                echo '</div>'; // Schließt die Zeile
                            }
                        } else {
                            echo '<p style="color: #666; font-style: italic; margin: 0;">Aktuell sind keine Intros hochgeladen.</p>';
                        }
                        ?>
						
                    </div> <!-- Schließt rme-intro-manager-list -->
                </div> <!-- Schließt rme-login-sound-popup -->
            </div> <!-- Schließt rme-popup-trigger-wrapper für Login-Sounds -->

<?php 
// 🎯 FIX 1: Wir beenden die harte Gast-Sperre GENAU HIER! 
// Ab diesem Moment darf der Gast im Code wieder mitlesen und existiert für den Server!
endif; 
?>


<div class="rme-popup-trigger-wrapper rme-gaming-wrapper rme-gaming-btn-spacer" style="display: inline-block; vertical-align: middle; margin: 0 4px;">

    <!-- 🎰 TRIUMPH: Dieser Button lädt jetzt bei JEDEM im Raum an exakt derselben HTML-Stelle! -->
    <button type="button" onclick="var p = document.getElementById('rme-universal-gaming-popup'); p.style.display = (p.style.display === 'block') ? 'none' : 'block';" class="rme-control-btn rme-gaming-btn">
        <span>🎲 Chat-Spiele</span>
    </button>
    
    <!-- Das unblockierbare Universal-Klappmenü (KOMPAKT & ZENTRIERT - KEIN SCROLLEN MEHR!) -->
    <div id="rme-universal-gaming-popup" class="rme-spiel-popup-container rme-smiley-zentrale-box rme-gaming-popup-box" style="display: none; position: absolute; bottom: 42px; left: 0; z-index: 99999; max-height: 85vh; overflow-y: auto;">
        <h3 class="rme-chef-zentrale-title rme-gaming-popup-title">🎰 Chat-Zentrale</h3>
        
        <div class="rme-gaming-inner-layout" style="padding: 6px 10px; min-width: 200px;">
 
<!-- 📜 SLICKER INFO-BUTTON (ÖFFNET DAS FREISCHWEBENDE OVERLAY) -->
<div style="margin-top: 10px !important; border-top: 1px solid #333 !important; padding-top: 10px !important;">
    <button type="button" 
            onclick="document.getElementById('rme-v4g-lobby-popup').style.display='none'; document.getElementById('rme-ttt-lobby-popup').style.display='none'; document.getElementById('rme-gaming-rules-overlay').style.display='flex';" 
            class="rme-popup-submit-btn rme-game-action-btn" 
            style="background: #ffed00 !important; color: #000 !important; margin: 0 !important; padding: 6px !important; font-size: 11px !important; font-weight: bold !important; width: 100% !important; text-transform: uppercase !important; box-shadow: 0 0 5px rgba(255,237,0,0.3) !important;">
        📜 Spielregeln & Infos anzeigen
    </button>
</div>

<!-- ========================================================================= -->
<!-- 📜 METRIC-NEON OVERLAY: FREISCHWEBENDE SPIELREGELN-ZENTRALE               -->
<!-- ========================================================================= -->
<div id="rme-gaming-rules-overlay" class="rme-rules-backdrop">
    <div class="rme-rules-card">
        
        <!-- Schließen-Kreuz oben rechts -->
        <span onclick="document.getElementById('rme-gaming-rules-overlay').style.display='none';" class="rme-rules-close-cross" title="Schließen">×</span>
        
        <!-- Header im edlen Arcade-Stil -->
        <div class="rme-rules-header">
            🎮 GAMING ARENA: REGELN & INFOS 📜
        </div>
        
        <!-- Scrollbarer Inhalts-Käfig mit großer Schrift -->
        <div class="rme-rules-scroll-box">
            
            <div class="rme-rules-item">
                <strong class="rme-rules-title-slot">🎰 Slot-Machine:</strong><br>
                Zieh am Hebel! Bei 3 gleichen Emojis knackst du den Jackpot. Cooldown: 3 Minuten.
            </div>
            
            <div class="rme-rules-item">
                <strong class="rme-rules-title-zahlen">🎮 Zahlen-Raten:</strong><br>
                Der Server merkt sich eine Geheimzahl (1-100) fest für dich. Rate so oft du willst (alle 10 Sek.). Erst wenn du gewinnst, startet eine neue Runde!
            </div>
            
            <div class="rme-rules-item">
                <strong class="rme-rules-title-rad">🎰 Glücksrad:</strong><br>
                Dreh das Rad für zufällige Gewinne oder Nieten. Cooldown: 5 Minuten.
            </div>
            
            <div class="rme-rules-item rme-rules-divider">
                <strong class="rme-rules-title-ttt">❌⭕ Tic-Tac-Toe Arena:</strong><br>
                Fordere einen Mitspieler live über die Lobby heraus. Wer zuerst 3 Kreuze oder Kreise in einer Reihe hat, gewinnt! Über Revanche tauscht ihr sofort die Symbole.
            </div>
            
            <div class="rme-rules-item">
                <strong class="rme-rules-title-v4g">🔵🔴 Vier Gewinnt Arena:</strong><br>
                Wähle deinen Gegner in der V4G-Lobby. Klicke abwechselnd auf die Spalten, um deine Chips von oben nach unten fallen zu lassen. Wer zuerst 4 eigene Chips waagerecht, senkrecht oder diagonal verbindet, siegt!
            </div>
                    <div class="rme-rules-item">
                <strong class="rme-rules-title-action" style="color: #ffcc00;">🔨 Chat-Aktionen:</strong><br>
                Tippe den Namen eines Mitspielers in das Eingabefeld und drücke den neongelben Button! Über 150 verrückte Aktionen sind am Start, um dein Opfer virtuell zu vermöbeln oder mit Keksen zu füttern. Cooldown: 5 Sekunden.
            </div>            
            <div class="rme-rules-footer-note">
                🗑️ Hinweis: Alle Spielboxen, Wunsch-Ticker und Sound-Texte löschen oder aktualisieren sich vollautomatisch im Hintergrund!
            </div>
        </div>

        <!-- Großer Schließen-Balken unten -->
        <div class="rme-rules-footer-action">
            <button type="button" onclick="document.getElementById('rme-gaming-rules-overlay').style.display='none';" class="rme-rules-close-btn">
                Verstanden & Schließen X
            </button>
        </div>
        
    </div>
</div>


<!-- 🎶 CYAN-NEON BUTTON: ÖFFNET DAS FREISCHWEBENDE WUNSCHBOX-OVERLAY -->
<div style="margin-top: 6px !important;">
    <button type="button" 
            onclick="document.getElementById('rme-v4g-lobby-popup').style.display='none'; document.getElementById('rme-ttt-lobby-popup').style.display='none'; document.getElementById('rme-wunschbox-overlay').style.display='flex';" 
            class="rme-popup-submit-btn rme-game-action-btn" 
            style="background: #00d2ff !important; color: #000 !important; margin: 0 !important; padding: 6px !important; font-size: 11px !important; font-weight: bold !important; width: 100% !important; text-transform: uppercase !important; box-shadow: 0 0 5px rgba(0,210,255,0.3) !important;">
        🎵 Musikwunsch & Gruß senden 📝
    </button>
</div>

<!-- ========================================================================= -->
<!-- 🎶 METRIC-NEON OVERLAY: FREISCHWEBENDE WUNSCH- & GRUSSZENTRALE           -->
<!-- ========================================================================= -->
<div id="rme-wunschbox-overlay" class="rme-rules-backdrop">
    <div class="rme-rules-card" style="border-color: #ffaa00 !important; box-shadow: 0 0 30px rgba(255,170,0,0.5) !important;">
        
        <!-- Schließen-Kreuz oben rechts -->
        <span onclick="document.getElementById('rme-wunschbox-overlay').style.display='none';" class="rme-rules-close-cross" title="Schließen">×</span>
        
        <!-- Header im eleganten Studio-Stil -->
        <div class="rme-rules-header" style="color: #00d2ff !important;">
            🎵 STUDIO-LEITUNG: WUNSCH & GRUSS ✍️
        </div>
        
        <!-- Das breite, komfortable Formular-Gehäuse -->
        <div class="rme-wunsch-form-wrapper" style="padding: 5px !important; gap: 12px !important;">
            
            <!-- Typ-Auswahl -->
            <div class="rme-wunsch-type-row" style="margin-bottom: 8px !important;">
                <label class="rme-wunsch-type-label" style="font-size: 14px !important; font-weight: bold !important;">Ich möchte senden:</label>
                <select id="rme_wunsch_typ" onchange="rmeUmschaltenWunschFelder(this.value); document.getElementById('rme_wunsch_status').innerText='Bereit'; document.getElementById('rme_wunsch_status').style.color='#666';" class="rme-wunsch-select" style="height: 28px !important; font-size: 13px !important; padding: 0 8px !important;">
                    <option value="wunsch">🎵 Musikwunsch</option>
                    <option value="gruss">✍️ Grußtext</option>
                    <option value="beides">🔥 Wunsch & Gruß</option>
                </select>
            </div>
            
            <!-- Feld 1: Reiner Musikwunsch (Breit, 14px große Schrift) -->
            <div id="rme_wrapper_song" class="rme-wunsch-field-group rme-display-block">
                <label class="rme-wunsch-field-label rme-color-cyan" style="font-size: 12px !important; margin-bottom: 5px !important;">🎵 WELCHER SONG ODER INTERPRET?</label>
                <input type="text" id="rme_wunsch_song" placeholder="z.B. Linkin Park - In The End" class="rme-wunsch-input" style="height: 36px !important; font-size: 14px !important; padding: 0 10px !important;">
            </div>
            
            <!-- Feld 2: Reiner Grußtext (Höher, 14px große Schrift) -->
            <div id="rme_wrapper_gruss" class="rme-wunsch-field-group rme-display-none">
                <label class="rme-wunsch-field-label rme-color-orange" style="font-size: 12px !important; margin-bottom: 5px !important;">✍️ DEIN LIVE-GRUSS AN DIE HÖRER:</label>
                <textarea id="rme_wunsch_gruss" placeholder="Schreibe hier deinen Grußtext rein... An wen gehen die Grüße?" class="rme-wunsch-textarea" style="height: 90px !important; font-size: 14px !important; padding: 10px !important; line-height: 1.5 !important;"></textarea>
            </div>
            
            <!-- Großer Absende-Button -->
            <button type="button" onclick="rmeSendeWunschAnModZentrale();" class="rme-wunsch-submit-btn" style="height: 40px !important; font-size: 14px !important; margin-top: 10px !important;">
                Wunsch an das Studio funken 📡
            </button>
            
            <!-- Status-Meldung -->
            <div id="rme_wunsch_status" class="rme-wunsch-status-text" style="font-size: 12px !important; margin-top: 6px !important; font-weight: bold !important;">Bereit</div>
        </div>
        
    </div>
</div>





 
            <!-- ABTEILUNG 1: SPASS-ZENTRALE (Kompakt nebeneinander gegriddet) -->
            <div class="rme-smiley-category-title" style="margin-top: 2px !important; margin-bottom: 4px !important;">🎮 Spass-Zentrale</div>
            
            <!-- 🎲 🪙 Würfel & Münze extrem platzsparend in einer Reihe! -->
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px; margin-bottom: 4px;">
                <button type="button" onclick="rmeSendeGamingAktion('roll_dice'); document.getElementById('rme-universal-gaming-popup').style.display='none';" class="rme-popup-submit-btn rme-game-action-btn rme-btn-dice" style="margin: 0 !important; padding: 4px !important; font-size: 11px !important;">🎲 Würfel</button>
                <button type="button" onclick="rmeSendeGamingAktion('flip_coin'); document.getElementById('rme-universal-gaming-popup').style.display='none';" class="rme-popup-submit-btn rme-game-action-btn rme-btn-coin" style="margin: 0 !important; padding: 4px !important; font-size: 11px !important;">🪙 Münze</button>
            </div>
            
<!-- 🎰 ❌⭕ 🔵🔴 KOMPAKTES SPIELE-ROSTER (FARBLICH ABGESTIMMT & VERZÖGERT) -->
<div style="display: grid; grid-template-columns: 1fr; gap: 4px; margin-bottom: 4px;">
    <!-- Zeile 1: Glücksrad als voller Balken -->
    <button type="button" onclick="rmeSendeGamingAktion('spin_wheel'); document.getElementById('rme-universal-gaming-popup').style.display='none';" class="rme-popup-submit-btn rme-game-action-btn rme-btn-wheel" style="background: #7B1FA2 !important; color: #fff !important; margin: 0 !important; padding: 4px !important; font-size: 11px !important;">🎰 Glücksrad</button>
</div>
<!-- <div style="display: grid; grid-template-columns: 1fr; gap: 4px; margin-bottom: 4px;"> -->
    <!-- Hörer-Danke-Feuerwerk als voller lila-funkelnder Balken -->
<!--    <button type="button" onclick="rmeSendeGamingAktion('danke'); document.getElementById('rme-universal-gaming-popup').style.display='none';" class="rme-popup-submit-btn rme-game-action-btn" style="background: #4A148C !important; color: #fff !important; margin: 0 !important; padding: 4px !important; font-size: 11px !important; font-weight: bold !important; border: 1px solid #7B1FA2 !important; box-shadow: 0 0 5px rgba(123,31,162,0.4) !important;">🎆 Danke-Feuerwerk zünden</button>
</div> -->

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px; margin-bottom: 4px;">
    <!-- Zeile 2, Links: Tic-Tac-Toe Arena (Dein funktionierender Netzhaut-Zünder!) -->
    <button type="button" 
            onclick="
                setTimeout(function() { 
                    document.getElementById('rme-ttt-lobby-popup').style.display = 'block'; 
                    rmeLadeTttOnlineSpieler(); 
                }, 200);
            " 
            class="rme-popup-submit-btn rme-game-action-btn rme-btn-ttt" 
            style="background: #00ff00 !important; color: #000 !important; margin: 0 !important; padding: 4px !important; font-size: 11px !important; font-weight: bold !important;">
        ❌⭕ TTT Arena
    </button>
    
    <!-- Zeile 2, Rechts: Vier Gewinnt Arena (Im identischen Zeitverzögerungs-Stil) -->
    <button type="button" 
            onclick="
                setTimeout(function() { 
                    document.getElementById('rme-v4g-lobby-popup').style.display = 'block'; 
                    rmeLadeV4gOnlineSpieler(); 
                }, 200);
            " 
            class="rme-popup-submit-btn rme-game-action-btn rme-btn-v4g" 
            style="background: #00d2ff !important; color: #000 !important; margin: 0 !important; padding: 4px !important; font-size: 11px !important; font-weight: bold !important;">
        🔵🔴 V4G Arena
    </button>
</div>


<!-- ⚔️ DAS MOBIL-TIEFERGELEGTE TIC-TAC-TOE LOBBY-POPUP (PERFEKTE DAUMEN-ZONE) -->
<div id="rme-ttt-lobby-popup" style="display: none; position: fixed !important; top: auto !important; bottom: 600px !important; left: 50% !important; transform: translateX(-50%) !important; z-index: 9999999 !important; background: #1a1a24 !important; border: 2px solid #00ff00 !important; padding: 15px !important; border-radius: 8px !important; color: #fff !important; text-align: center !important; width: 260px !important; box-shadow: 0 0 20px rgba(0,255,0,0.4) !important; max-height: 70vh !important; overflow-y: auto !important;">
    <span onclick="document.getElementById('rme-ttt-lobby-popup').style.display='none';" style="position: absolute !important; top: 4px !important; right: 8px !important; color: #cc2424 !important; font-size: 18px !important; font-weight: bold !important; cursor: pointer !important; user-select: none !important;" title="Lobby schließen">×</span>
    
    <div style="font-weight: bold !important; color: #ffed00 !important; margin-bottom: 12px !important; font-size: 13px !important; letter-spacing: 0.5px !important;">❌⭕ TTT HERAUSFORDERUNG</div>
    
    <div style="margin-bottom: 10px !important;">
        <label for="rme_ttt_opponent" style="color: #00ff66 !important; font-size: 11px !important; font-weight: bold !important; display: block !important; margin-bottom: 4px !important;">🎮 GEGNER WÄHLEN:</label>
        <select id="rme_ttt_opponent" class="rme-control-select" style="width: 100% !important; background: #111 !important; color: #00ff66 !important; border: 1px solid #00ff66 !important; height: 30px !important; font-size: 12px !important;">
            <option value="">-- Spieler auswählen --</option>
        </select>
    </div>
    
    <div style="margin-top: 12px !important;">
        <button type="button" onclick="rmeFordereSpielerHeraus(); document.getElementById('rme-ttt-lobby-popup').style.display='none';" style="background: #00ff00 !important; color: #000 !important; border: none !important; padding: 6px 14px !important; font-weight: bold !important; border-radius: 4px !important; cursor: pointer !important; font-size: 12px !important; width: 100% !important; box-shadow: 0 0 5px #00ff00 !important;">Einladung senden 📡</button>
    </div>
</div>
<!-- 🔵🔴 DAS MOBIL-OPTIMIERTE VIER GEWINNT LOBBY-POPUP -->
<div id="rme-v4g-lobby-popup" style="display: none; position: fixed !important; top: auto !important; bottom: 600px !important; left: 50% !important; transform: translateX(-50%) !important; z-index: 9999999 !important; background: #1a1a24 !important; border: 2px solid #00d2ff !important; padding: 15px !important; border-radius: 8px !important; color: #fff !important; text-align: center !important; width: 260px !important; box-shadow: 0 0 20px rgba(0,210,255,0.4) !important; max-height: 70vh !important; overflow-y: auto !important;">
    <span onclick="document.getElementById('rme-v4g-lobby-popup').style.display='none';" style="position: absolute !important; top: 4px !important; right: 8px !important; color: #cc2424 !important; font-size: 18px !important; font-weight: bold !important; cursor: pointer !important; user-select: none !important;" title="Lobby schließen">×</span>
    
    <div style="font-weight: bold !important; color: #ffed00 !important; margin-bottom: 12px !important; font-size: 13px !important; letter-spacing: 0.5px !important;">🔵🔴 VIER GEWINNT ARENA</div>
    
    <div style="margin-bottom: 10px !important;">
        <label for="rme_v4g_opponent" style="color: #00d2ff !important; font-size: 11px !important; font-weight: bold !important; display: block !important; margin-bottom: 4px !important;">🎮 GEGNER WÄHLEN:</label>
        <select id="rme_v4g_opponent" class="rme-control-select" style="width: 100% !important; background: #111 !important; color: #00d2ff !important; border: 1px solid #00d2ff !important; height: 30px !important; font-size: 12px !important;">
            <option value="">-- Spieler auswählen --</option>
        </select>
    </div>
    
    <div style="margin-top: 12px !important;">
        <button type="button" onclick="rmeFordereV4gSpielerHeraus(); document.getElementById('rme-v4g-lobby-popup').style.display='none';" style="background: #00d2ff !important; color: #000 !important; border: none !important; padding: 6px 14px !important; font-weight: bold !important; border-radius: 4px !important; cursor: pointer !important; font-size: 12px !important; width: 100% !important; box-shadow: 0 0 5px #00d2ff !important;">Einladung senden 📡</button>
    </div>
</div>




            <!-- INTERAKTIVE HÖRER-SPIELE CATEGORY -->
            <div class="rme-spiel-category-title" style="margin-top: 6px !important; margin-bottom: 4px !important; !important;">🎰 Spielhalle</div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px; margin-bottom: 4px;">
                <button type="button" onclick="rmeSendeGamingAktion('play_slots'); document.getElementById('rme-universal-gaming-popup').style.display='none';" class="rme-popup-game-trigger" style="margin: 0 !important; padding: 4px !important; font-size: 11px !important; width: 100% !important;">🎰 Slots</button>
                <button type="button" onclick="rmeSendeGamingAktion('guess_number'); document.getElementById('rme-universal-gaming-popup').style.display='none';" class="rme-popup-game-trigger" style="margin: 0 !important; padding: 4px !important; font-size: 11px !important; width: 100% !important;">🎮 Zahlen-Raten</button>
            </div>
<!-- <button type="button" onclick="rmeSendeGamingAktion('vip_roulette'); document.getElementById('rme-universal-gaming-popup').style.display='none';" class="rme-popup-game-trigger" style="margin: 0 !important; padding: 4px !important; font-size: 11px !important; width: 100% !important; background: #00E676 !important; color: #000 !important; font-weight: bold !important;">🎨 VIP Roulette</button> -->

            <!-- 🔮 DIE MAGISCHE NEON-ORAKEL-KUGEL -->
            <div style="margin-top: 4px; padding-top: 4px; border-top: 1px solid rgba(255,255,255,0.05);">
                <input type="text" id="rme_oracle_question" placeholder="Frage ans Orakel..." class="rme-countdown-field" style="width: 100% !important; margin-bottom: 3px !important; font-size: 11px !important; padding: 3px !important;">
                <button type="button" onclick="var q = document.getElementById('rme_oracle_question').value.trim(); if(q === '') { q = 'Wird die Sendung heute geil?'; } rmeSendeGamingAktion('ask_oracle|' + q); document.getElementById('rme_oracle_question').value = ''; document.getElementById('rme-universal-gaming-popup').style.display='none';" class="rme-popup-game-trigger" style="width: 100% !important; background: #9C27B0 !important; color: #fff !important; padding: 4px !important; font-size: 11px !important; margin: 0 !important;">🔮 Orakel befragen</button>
            </div>

            <!-- 🃏 DAS INTERAKTIVE POPUP-KARTEN-DUELL (PERFEKT ZENTRIERT) -->
            <div id="rme-karten-start-block" style="margin-top: 4px;">
                <button type="button" onclick="rmeStarteKartenPopupDuell();" class="rme-popup-game-trigger" style="width: 100% !important; background: #00bcd4 !important; color: #fff !important; padding: 4px !important; font-size: 11px !important; margin: 0 !important;">🃏 Karten-Duell</button>
            </div>

            <!-- Die persistente Arena: Vollständig zentriert über Flexbox -->
            <div id="rme-karten-arena-block" style="display: none; background: rgba(0, 0, 0, 0.5); padding: 8px; border-radius: 4px; border: 1px solid #00ffaa; margin-top: 6px;">
                <div style="display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center;">
                    <div style="color: #ffed00; font-weight: bold; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;">🃏 Karten-Arena</div>
                    

                    <span id="rme-karten-score" style="color: #00ffaa; font-size: 10px; font-weight: bold; margin: 2px 0 4px 0;">Siege: 0 | Pleiten: 0</span>
                    
                    <div id="rme-aktuelle-karte-anzeige" style="font-size: 20px; font-weight: bold; color: #fff; background: #111; border: 1px solid rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 4px; display: inline-block; min-width: 60px; box-shadow: 0 0 8px rgba(0,255,170,0.2);">[ ? ]</div>

                    <div id="rme-karten-feedback" style="color: #bbb; font-size: 10px; margin: 4px 0; min-height: 14px;">Wähle weise!</div>
                    
                    <div id="rme-karten-spiel-buttons" style="display: grid; grid-template-columns: 1fr 1fr; gap: 4px; width: 100%;">
                        <button type="button" onclick="rmeKartenTippen('hoeher');" class="rme-popup-game-trigger" style="background: #222 !important; color: #00ffaa !important; border-color: #00ffaa !important; padding: 4px !important; margin: 0 !important; font-size: 11px !important;">⬆️ HÖHER</button>
                        <button type="button" onclick="rmeKartenTippen('tiefer');" class="rme-popup-game-trigger" style="background: #222 !important; color: #ff3333 !important; border-color: #ff3333 !important; padding: 4px !important; margin: 0 !important; font-size: 11px !important;">⬇️ TIEFER</button>
                    </div>

                    <button type="button" id="rme-karten-reset-btn" onclick="rmeKartenNaechsteRunde();" class="rme-popup-game-trigger" style="display: none; width: 100% !important; background: #00ffaa !important; color: #000 !important; font-weight: bold !important; padding: 4px !important; margin-top: 4px; font-size: 11px !important;">🔄 WEITER</button>
                    
                    <button type="button" onclick="rmeKartenArenaVerlassen();" style="background: transparent; color: #888; border: none; font-size: 9px; margin-top: 6px; cursor: pointer; text-decoration: underline; padding: 0;">◀ Zurück zum Menü</button>
                </div>
            </div>

            <!-- HÖRER-SOUNDBOARD REAKTIONEN -->
            <div class="rme-live-category-title" style="margin-top: 6px !important; margin-bottom: 4px !important; !important;">🔊 Live-Reaktionen</div>
            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px;">
                <button type="button" onclick="rmeSendeGamingAktion('hsound_lachen'); document.getElementById('rme-universal-gaming-popup').style.display='none';" class="rme-popup-submit-btn rme-game-action-btn" style="background: #222 !important; color: #fff !important; margin: 0 !important; font-size: 11px !important; padding: 4px !important;">😂 Lachen</button>
                <button type="button" onclick="rmeSendeGamingAktion('hsound_klatschen'); document.getElementById('rme-universal-gaming-popup').style.display='none';" class="rme-popup-submit-btn rme-game-action-btn" style="background: #222 !important; color: #fff !important; margin: 0 !important; font-size: 11px !important; padding: 4px !important;">👏 Applaus</button>
                <button type="button" onclick="rmeSendeGamingAktion('hsound_buh'); document.getElementById('rme-universal-gaming-popup').style.display='none';" class="rme-popup-submit-btn rme-game-action-btn" style="background: #222 !important; color: #fff !important; margin: 0 !important; font-size: 11px !important; padding: 4px !important;">👎 Buh</button>
                <button type="button" onclick="rmeSendeGamingAktion('hsound_trommel'); document.getElementById('rme-universal-gaming-popup').style.display='none';" class="rme-popup-submit-btn rme-game-action-btn" style="background: #222 !important; color: #fff !important; margin: 0 !important; font-size: 11px !important; padding: 4px !important;">🥁 Tusch</button>
            </div>
<!-- 🃏 SCHWEBENDES ERGEBNIS-BANNER FÜR LANGE KARTEN-SPRÜCHE (0% PLATZVERBRAUCH IM MENÜ) -->
<div id="rme-karten-banner-popup" style="display: none; position: fixed !important; top: 40% !important; left: 50% !important; transform: translate(-50%, -50%) !important; z-index: 999999999 !important; background: #12121a !important; padding: 15px !important; border-radius: 8px !important; color: #fff !important; text-align: center !important; width: 240px !important; border: 2px solid #00ffaa; box-shadow: 0 0 25px rgba(0,255,170,0.5) !important;">
    <span onclick="document.getElementById('rme-karten-banner-popup').style.display='none';" style="position: absolute !important; top: 2px !important; right: 8px !important; color: #ff3333 !important; font-size: 18px !important; font-weight: bold !important; cursor: pointer !important; user-select: none !important;">×</span>
    <div style="font-weight: bold !important; color: #ffed00 !important; margin-bottom: 8px !important; font-size: 11px !important; letter-spacing: 0.5px !important; text-transform: uppercase;">🃏 Karten-Duell Ergebnis</div>
    
    <!-- Hier schießt das JavaScript den langen, witzigen DB-Spruch rein -->
    <div id="rme-karten-banner-text" style="font-size: 11px !important; line-height: 1.4 !important; color: #fff !important; margin-bottom: 2px !important; text-align: center !important;"></div>
</div>

<!-- Eingabefeld für den Namen -->
<br><input type="text" id="nameEingabe" placeholder="Name eingeben...">

<!-- Der Klick-Button (Mit eigener Design-Klasse!) -->
<button id="aktionsButton" class="mein-neon-aktionsknopf" onclick="fuehreAktionAus()">Aktion starten!</button>

<!-- Hier wird das Ergebnis angezeigt -->
<p id="outputBereich"></p>



<!-- 🏆 DER HIGHSCORE BUTTON FÜR DIE SPIELE-ZENTRALE -->
<button type="button" class="rme-game-action-btn rme-btn-highscore" onclick="parent.öffneHighscoreTabelle()">
    🏆 Quiz Highscore-Liste
</button>

<!-- 🏆 HIGHSCORE SYSTEM: NEON-BANNER -->
<div id="rme-highscore-popup" class="rme-highscore-overlay">
    <div class="rme-highscore-modal">
        <!-- Rotes Schließkreuz oben rechts -->
        <span class="rme-highscore-close-cross" onclick="parent.schliesseHighscoreTabelle()">&times;</span>
        
        <!-- Titel der Box -->
        <div class="rme-highscore-header">🏆 RME CHAT-QUIZ HIGHSCORES</div>
        
        <!-- Der scrollbare Bereich für die Rangliste -->
        <div class="rme-highscore-scroll-box" id="rme-highscore-liste-daten">
            <!-- Die Zeilen werden hier live per JavaScript reingeladen -->
            <div style="color:#aaa; text-align:center; padding:20px;">Lade Rangliste...</div>
        </div>
        
        <!-- Schließen-Button ganz unten -->
        <div class="rme-highscore-footer-action">
            <button type="button" class="rme-highscore-close-btn" onclick="parent.schliesseHighscoreTabelle()">Schließen</button>
        </div>
    </div>
</div>

        </div>
    </div>
</div>
  <?php 
// 🎯 FIX 2: Wir ÖFFNEN den Gast-Schutzschalter sofort wieder!
// Dadurch bedienen wir alle tiefer liegenden 'endif' oder schließenden Klammern Deiner Originaldatei perfectly,
// und Deine Seite wird NIEMALS wieder weiß oder stürzt ab!
if (!$ist_wirklich_gast_check): 
?>          


</div> <!-- Schließt rme-settings-and-chef-row SAFELY! -->
    <?php endif; ?>

<!-- ========================================================================= -->
<!-- METRIC-NEON: ADMIN NOTIZZETTEL POPUP (AUTOMATISCHER ABSENDER-TAG V3)     -->
<!-- ========================================================================= -->
<?php
$current_note_text = "";
$note_meta_info = "Noch keine Team-Notizen vorhanden";

$note_query = dbquery("SELECT note_text, admin_name, last_update FROM fusionb7754_chat_admin_notes ORDER BY id DESC LIMIT 1");
if ($note_query && dbrows($note_query) > 0) {
    $note_row = dbarray($note_query);
    
    // 1. Für das Eingabefeld laden wir den ROHE TEXT (inklusive der [color]-Tags)
    $current_note_text = stripslashes($note_row['note_text']);
    
    // 2. Für die Info-Zeile säubern wir den Text oder wandeln BB-Codes um, falls nötig
	$gespeichert_am = date("d.m.Y \u\m H:i ", $note_row['last_update']);
    $note_meta_info = "Letzte Info von: <strong style='color:#ffcc00;'>".htmlspecialchars($note_row['admin_name'])."</strong> ".$gespeichert_am." Uhr";
}

// Deine bewährte Namens-Erkennung aus dem Chat-CMS
$mein_aktueller_admin_name = isset($chat_user_name) ? (string)$chat_user_name : (isset($raw_sidebar_name) ? (string)$raw_sidebar_name : 'Admin');
$mein_sauberer_admin_name = str_replace(array("_Gast", "_CU", '[ADMIN]', '[MODERATOR]', '[MOD]', '[HADMIN]'), "", $mein_aktueller_admin_name);
$mein_sauberer_admin_name = trim($mein_sauberer_admin_name);

if ((isset($is_admin) && $is_admin) || (isset($ist_leitung) && $ist_leitung)) {
?>

<!-- Button neben deinem Login-Sound Button -->
<button type="button" 
        class="rme-admin-note-trigger-btn" 
        onclick="rmeToggleAdminNote(true);">
    📝 Notiz öffnen
</button>

<!-- Großes Metric-Neon Overlay in der Mitte -->
<div id="rme-admin-note-popup" class="rme-dsgvo-overlay rme-admin-note-popup-screen">
    <div class="rme-admin-note-modal">
        
        <!-- Hidden Field, damit JavaScript deinen Namen aus PHP kennt -->
        <input type="hidden" id="rme-hidden-admin-name" value="<?php echo htmlspecialchars($mein_sauberer_admin_name, ENT_QUOTES, 'UTF-8'); ?>">

        <!-- Header -->
        <div class="rme-dsgvo-header rme-admin-note-header">
            <span>📝 TEAM NOTIZZETTEL (AUTO-ABSENDER)</span>
            <span id="rme-note-status" class="rme-note-status-badge">Bereit</span>
        </div>
        
        <!-- Meta Info Band -->
        <div id="rme-note-meta-container" class="rme-admin-note-meta">
            <?php echo $note_meta_info; ?>
        </div>
        
        <!-- METRIC-NEON: SYSTEM-NOTIZ PROTOKOLL -->
        <div class="rme-dsgvo-body rme-admin-note-body">
            
            <!-- 1. Der Chat-Verlauf der bisherigen Notizen -->
            <div id="rme-admin-note-history" class="rme-admin-note-history-window">
                <?php
                $history_query = dbquery("SELECT note_history FROM fusionb7754_chat_admin_notes ORDER BY id DESC LIMIT 1");
                if ($history_query && dbrows($history_query) > 0) {
                    $history_row = dbarray($history_query);
                    echo stripslashes($history_row['note_history'] ?? '<span class="rme-note-empty-text">Noch keine Protokolleinträge vorhanden.</span>');
                } else {
                    echo '<span class="rme-note-empty-text">Noch keine Protokolleinträge vorhanden.</span>';
                }
                ?>
            </div>

            <!-- 2. Das leuchtende Namensschild für den aktuellen Schreiber -->
            <div class="rme-admin-note-author-badge">
                ✍️ Neuer Eintrag als: <span><?php echo htmlspecialchars($mein_sauberer_admin_name, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>

            <!-- 3. Das Eingabefeld (Absolut weißer Text!) -->
            <textarea id="rme-admin-note-field" 
                      class="rme-admin-note-input-field"
                      placeholder="Tippe hier deine neue Info ein... (Mit ENTER absenden)"
                      onkeydown="if(event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); rmeSendeNeueNotiz(); }"></textarea>
            
            <div class="rme-admin-note-help-text">
                Tippe deinen Text und drücke <span class="rme-white-bold-text">Enter</span> zum Speichern
            </div>
        </div>

        <!-- Footer OHNE den kaputten Lösch-Button -->
        <div class="rme-dsgvo-footer rme-admin-note-footer">
            <button type="button" 
                    class="rme-admin-note-close-btn" 
                    onclick="rmeToggleAdminNote(false);">
                Schließen ❌
            </button>
        </div>

    </div>
</div>

<?php
}
?>

</div>
<!-- ✔ GELBER DECKEL FÜR DEN PRIVATMODUS -->

<!-- =========================================================================
     🔒 CORES-DESIGN V11: STATISCHER ANKER FÜR DEN PRIVATMODUS
     ========================================================================= -->
<!-- 
  FESTE REVIER-MARKIERUNG:
  'visibility: hidden' sorgt dafür, dass die Zeile unsichtbar ist, 
  aber trotzdem exakt 24 Pixel Platz wegnimmt. Kein Button springt mehr!
-->
<div id="rme-whisper-indicator" class="rme-whisper-indicator-yellow" style="visibility: hidden; height: 24px; line-height: 24px; margin-bottom: 4px; font-weight: bold; font-size: 13px;">
    🔒 Privat an <span id="rme-whisper-target"></span>:
</div>

<!-- UNTERE ZEILE: REINES ORIGINAL-TEXTFELD (NUN FELSENFEST VERANKERT!) -->
<div class="rme-chat-input-row">
    <input type="text" id="rme-chat-input" 
           class="rme-chat-text-field"
           data-id="<?php echo isset($_SESSION['chat_user_id']) ? intval($_SESSION['chat_user_id']) : 0; ?>"
           data-user="<?php echo isset($chat_user_name) ? htmlspecialchars((string)$chat_user_name, ENT_QUOTES, 'UTF-8') : 'Gast'; ?>" 
           data-level="<?php echo isset($userdata['user_groups']) ? htmlspecialchars((string)$userdata['user_groups'], ENT_QUOTES, 'UTF-8') : ''; ?>" 
           placeholder="Deine Nachricht..." />





<!-- NEU: Der Neon-Zeichenzähler direkt rechts neben dem Input-Feld -->
<div id="rme-char-counter" class="rme-neon-char-counter">0 / 200</div>

<?php if ((isset($is_admin) && $is_admin) || (isset($anzeige_selbst_name) && strtolower(trim($anzeige_selbst_name)) === 'dj-tomjac')) { ?>

    <!-- REPARIERT: Der umschließende Kasten baut das unzerstörbare Glasscheiben-System -->
    <div class="rme-admin-dropdown-wrapper">
        <!-- Der echte, perfekt zentrierte Button im Hintergrund -->
        <button type="button" class="rme-admin-dropdown-fake-btn">⚡ Admin <span class="rme-dropdown-arrow">▼</span></button>
        
        <!-- Das unsichtbare, echte Dropdown liegt als Glasscheibe exakt darüber -->
        <select id="rme-admin-select-action" class="rme-admin-dropdown-invisible">
            <option value="">⚡ Admin</option>
            <option value="clear_live" id="opt-clear-live">❌ Chat leeren</option>
            <option value="clear_archive" id="opt-clear-archive" style="display:none;">🧹 Archiv leeren</option>
        </select>
    </div>
<?php } ?>

    
    <input type="submit" name="rme_send_msg" value="Senden" class="btn btn-primary rme-chat-send-btn">
</div>

            <input type="hidden" name="rme_font" id="rme-font-hidden" value="">
            <input type="hidden" name="rme_size" id="rme-size-hidden" value="">
            <input type="hidden" name="rme_color" id="rme-color-hidden" value="">
            <input type="hidden" name="rme_bold" id="rme-bold-hidden" value="0">
            <input type="hidden" name="rme_italic" id="rme-italic-hidden" value="0">
            <input type="hidden" name="rme_underline" id="rme-underline-hidden" value="0">
        </form>
        <iframe name="rme_hidden_bridge" id="rme_hidden_bridge" style="display:none;"></iframe>
    </div>

<!-- SIDEBAR MIT USERLISTE -->
<div class="chat-sidebar">
    <div class="user-panel">
        <!-- Grid teilt die Box in links (Text/Name) und rechts (Logout-Button) auf -->
        <div class="rme-user-panel-grid">
            
            <!-- Linke Spalte: Text oben, Name unten -->
            <div class="rme-user-panel-left">
                <span class="user-panel-text">Eingeloggt im Chat:</span>
                
                <!-- Live-Uhrzeit Anzeige rechts am Rand -->
                <div class="rme-chat-clock-container">
                    📅 <span id="rme_live_date">--.--.----</span> | 🕒 <span id="rme_live_time">00:00:00</span>
                </div>

					<?php
					// REPARATUR-KERN 1: Initialisiert die vom Server vermissten Variablen, damit PHP nicht mehr abstürzt!
					$raw_sidebar_name = $chat_user_name ?? ($_SESSION['chat_user_name'] ?? ($_SESSION['rme_chat_guest_name'] ?? 'Gast'));
					$status_dropdown = $status_dropdown ?? ''; 

					// REPARIERT 1: Schneidet jetzt _Gast UND _CU felsenfest ab!
					$display_sidebar_name = str_replace(array("_Gast", "_CU"), "", $raw_sidebar_name);
					$sauberer_name_check = str_replace(array('[ADMIN]', '[MODERATOR]', '[MOD]', '[HADMIN]'), '', $display_sidebar_name);
					$sauberer_name_check = trim($sauberer_name_check);

					$user_level_int = isset($userdata['user_level']) ? intval($userdata['user_level']) : 0;
					$user_groups_raw = isset($userdata['user_groups']) ? (string)$userdata['user_groups'] : '';
					$numerische_id_check = isset($chat_user_id) ? intval($chat_user_id) : (isset($_SESSION['chat_user_id']) ? intval($_SESSION['chat_user_id']) : 0);

					// =========================================================================
					// 🔄 UNIVERSAL-RETTUNGSBRÜCKE: Holt das Recht via ECHTER USER-ID (z.B. ID 6)
					// =========================================================================
					$ist_wirklich_gast_check = (strpos(strtolower((string)$raw_sidebar_name), 'gast') !== false);
					
					if (!$ist_wirklich_gast_check && !empty($sauberer_name_check)) {
						if (strpos($user_groups_raw, '.3.') === false && strpos($user_groups_raw, '.1.') === false && strpos($user_groups_raw, '.2.') === false) {
							
							// 🔥 MASTER-FIX: Wenn eine gültige Team-ID vorliegt (unter 1000), suchen wir knallhart nach der ID 6!
							if ($numerische_id_check > 0 && $numerische_id_check < 1000) {
								$u_db_check = dbquery("SELECT user_level, user_groups FROM ".DB_USERS." WHERE user_id='".$numerische_id_check."' LIMIT 1");
							} else {
								// Fallback über den Namen für alle anderen
								$u_db_check = dbquery("SELECT user_level, user_groups FROM ".DB_USERS." WHERE LOWER(user_name)='".addslashes(strtolower($sauberer_name_check))."' LIMIT 1");
							}

							if ($u_db_check && dbrows($u_db_check) > 0) {
								$u_db_row = dbarray($u_db_check);
								$user_level_int = intval($u_db_row['user_level']);
								$user_groups_raw = (string)$u_db_row['user_groups'];
							}
						}
					}

					// REPARIERT 3: Erkennt registrierte Chat-User am verbleibenden _CU im rohen Session-Namen
					$ist_ein_chat_user = (strpos((string)$raw_sidebar_name, '_CU') !== false);

					// Eindeutige Erkennung für dich als Chef und dein Team (Tolerant gegen Kleinschreibung)
					$name_leitung_low = strtolower($sauberer_name_check);
					$ist_leitung = ($name_leitung_low === 'dj-tomjac' || $name_leitung_low === 'tomjac' || $numerische_id_check === 18);
					
					$is_admin = (!$ist_leitung && ((defined('iADMIN') && iADMIN) || $user_level_int == 103 || strpos($user_groups_raw, '.1.') !== false || strpos($user_groups_raw, '.2.') !== false));
					
					// Erkennt nun JEDEN echten Moderator vollautomatisch an Pegel und Gruppe!
					$is_mod = (!$ist_leitung && !$is_admin && (strpos($user_groups_raw, '.3.') !== false || strpos($user_groups_raw, '.4.') !== false || strpos($user_groups_raw, '.5.') !== false || $user_level_int == 101 || $user_level_int == 102 || $numerische_id_check === 6));
					$badge_html = "";


						// Logik-Weiche für das obere Panel (Mit HADMIN an oberster Stelle)
						if ($ist_leitung) {
							$name_class = "rme-rgb-hadmin"; 
							if (isset($ist_gerade_on_air) && $ist_gerade_on_air) {
								$badge_html = " <img src='img/on-air-anim.gif' style='height:14px; width:auto; vertical-align:middle; margin-left:5px; border-radius:2px;'>";
								// SIGNAL FÜR DIE WUNSCHBOX:
								$badge_html .= "<span id='rme-live-dj-aktiv-signal' style='display:none;'></span>";
							} else {
								$badge_html = "<span class='rme-badge-hadmin'>[HADMIN]</span>";
							}
						} elseif ($is_admin) {
							$name_class = "rme-rgb-username";
							if (isset($ist_gerade_on_air) && $ist_gerade_on_air) {
								$badge_html = " <img src='img/on-air-anim.gif' style='height:14px; width:auto; vertical-align:middle; margin-left:5px; border-radius:2px;'>";
								// SIGNAL FÜR DIE WUNSCHBOX:
								$badge_html .= "<span id='rme-live-dj-aktiv-signal' style='display:none;'></span>";
							} else {
								$badge_html = "<span class='rme-badge-admin'>[ADMIN]</span>";
							}
						} elseif ($is_mod) {
							// ERFOLG: Schaltet das strahlende Radio-Gelb nun auch OBEN im Panel für Moderatoren frei!
							$name_class = "rme-moderator-username"; 
							if (isset($ist_gerade_on_air) && $ist_gerade_on_air) {
								$badge_html = " <img src='img/on-air-anim.gif' style='height:14px; width:auto; vertical-align:middle; margin-left:5px; border-radius:2px;'>";
								// SIGNAL FÜR DIE WUNSCHBOX:
								$badge_html .= "<span id='rme-live-dj-aktiv-signal' style='display:none;'></span>";
							} else {
								$badge_html = "<span class='rme-badge-mod'>[MODERATOR]</span>"; 
							}
						} elseif ($is_logged_in || $ist_ein_chat_user) {
							$name_class = "rme-user-logged";
						} else {
							$name_class = "rme-name-guest"; 
						}
						
// =========================================================================
// KRISENSICHERE AVATAR-ABFRAGE FÜR DIE RECHTE SEITE (INNERHALB DES PHP-BLOCKS)
// =========================================================================
$mein_eigenes_bild_html = "";
if (!empty($sauberer_name_check)) {
    // Rahmenfarbe passend zu den Abzeichen definieren
    $rahmen_farbe = $ist_leitung ? "#ff5722" : ($is_admin ? "#ffcc00" : ($is_mod ? "#00ff00" : "#007bff"));

    if ($ist_ein_chat_user) {
        // Registrierter Chat-Gast (_CU): Holen aus der eigenen Tabelle
        $cu_avatar_query = dbquery("SELECT guest_avatar FROM fusionb7754_chat_guest_accounts WHERE guest_name='".addslashes($raw_sidebar_name)."' LIMIT 1");
        $mein_avatar_pfad = "avatars/noavatar.png"; // Standard-Fallback im Chat-Ordner

        if ($cu_avatar_query && dbrows($cu_avatar_query) > 0) {
            $cu_avatar_row = dbarray($cu_avatar_query);
            if (!empty($cu_avatar_row['guest_avatar']) && file_exists(dirname(__FILE__)."/avatars/".$cu_avatar_row['guest_avatar'])) {
                $mein_avatar_pfad = "avatars/".$cu_avatar_row['guest_avatar'];
            }
        }
        $mein_eigenes_bild_html = "<img src='".$mein_avatar_pfad."' class='rme-mein-haupt-avatar' style='border-color: ".$rahmen_farbe." !important;' alt=''>";
    } else {
        // Normales Homepage-Mitglied aus PHP-Fusion
        $mein_avatar_query = dbquery("SELECT user_avatar FROM ".DB_USERS." WHERE user_name='".addslashes($sauberer_name_check)."' LIMIT 1");
        
        if ($mein_avatar_query && dbrows($mein_avatar_query) > 0) {
            $mein_avatar_dateiname = dbarray($mein_avatar_query)['user_avatar'];
            
            if (!empty($mein_avatar_dateiname) && file_exists("images/avatars/".$mein_avatar_dateiname)) {
                $mein_avatar_pfad = "images/avatars/".$mein_avatar_dateiname;
            } elseif (!empty($mein_avatar_dateiname) && file_exists("../../images/avatars/".$mein_avatar_dateiname)) {
                $mein_avatar_pfad = "../../images/avatars/".$mein_avatar_dateiname;
            } else {
                $mein_avatar_pfad = file_exists("../../images/avatars/noavatar100.png") ? "../../images/avatars/noavatar100.png" : "images/avatars/noavatar100.png";
            }
            $mein_eigenes_bild_html = "<img src='".$mein_avatar_pfad."' class='rme-mein-haupt-avatar' style='border-color: ".$rahmen_farbe." !important;' alt=''>";
        }
    }
}
// =========================================================================

?>
<!-- Dieses Depot liest den PHP-Status aus und reicht ihn ans JavaScript weiter -->
<div id="rme-live-status-depot" data-on-air="<?php echo (isset($ist_gerade_on_air) && $ist_gerade_on_air) ? 'true' : 'false'; ?>"></div>

 
 
                <!-- REPARIERT: width: 100% zwingt die Zeile, den vollen Platz bis nach ganz rechts einzunehmen -->
                     <div class="rme-user-panel-name-row">
                        <span class="<?php echo $name_class; ?> rme-user-panel-name">
                            <?php echo htmlspecialchars($display_sidebar_name, ENT_QUOTES, 'UTF-8'); ?>
                        </span>
                        <?php 
                        echo $badge_html; // Dein [HADMIN] Text-Badge
                        echo $mein_eigenes_bild_html; // Dein großes 70x70px Bild schwebt nach rechts oben!
                        ?>
                    </div>




                    <!-- REPARATUR-KERN: Das Dropdown holt sich hier seinen Platz zurück! -->
                    <div class="rme-user-panel-status-row">
                        <?php echo $status_dropdown; ?>
                    </div>
                </div>

                <!-- Rechte Spalte: Intelligente Steuerung für das Team (Stylesicher) -->
                <div class="rme-user-panel-right">
                    <?php 
                    $check_chef_name = isset($display_sidebar_name) ? strtolower(trim($display_sidebar_name)) : '';
                    $gehoert_zum_team = ($is_logged_in || $is_admin || $is_mod || $check_chef_name === 'dj-tomjac');

                    if ($gehoert_zum_team) {
                        // FALL A: Hauptteam (HADMIN, ADMIN, MOD, GASTMODERATOR) -> KEIN BUTTON
                    } elseif ($ist_ein_chat_user) {
                        // FALL B: Reiner registrierter Chat-Gast -> Er braucht den Chat-Abmelde-Button!
                        ?>
                        <button type="button" class="rme-logout-btn" onclick="window.location.href='rme_chat.php?clear_chat_session=true';">
                            Logout
                        </button>
                        <?php
                    } else {
                        // FALL C: Völlig anonymer Gast -> Sieht in der Sidebar die beiden Knöpfe
                    }
                    ?>
                </div>

            </div> <!-- HIER SCHLIESST DAS GRID-ELEMENT! -->
            
            <?php 
            $ist_ein_temporaerer_gast = (strpos(strtolower((string)$raw_sidebar_name), 'gast') !== false);
            if (!$is_logged_in && $ist_ein_temporaerer_gast) { 
            ?>
                <!-- CONTAINER FÜR GAST-AKTIONEN -->
                <div class="rme-guest-actions-container">
                    <!-- BUTTON 1: REGISTRIEREN -->
                    <a href="#" onclick="var overlay = document.getElementById('rme-guest-registration-overlay'); if(overlay){ overlay.style.display='flex'; overlay.style.position='fixed'; overlay.style.zIndex='99999'; } return false;" class="rme-btn-register-chat rme-guest-action-btn">
                        🎵 Im Chat registrieren
                    </a>
                    
                    <!-- BUTTON 2: EINLOGGEN -->
                    <a href="#" onclick="var overlay = document.getElementById('rme-guest-login-overlay'); if(overlay){ overlay.style.display='flex'; overlay.style.position='fixed'; overlay.style.zIndex='99999'; } return false;" class="rme-btn-login-chat-link rme-guest-action-btn">
                        🔑 Bereits registriert? Einloggen
                    </a>
                </div>
            <?php } ?>

        </div> <!-- HIER SCHLIESST DAS .user-panel! -->
        
        <!-- REPARATUR-KERN: Schaltet das Panel für dich als HADMIN, Admins und Mods frei! -->
        <?php if ($ist_leitung || $is_admin || $is_mod || (isset($anzeige_selbst_name) && strtolower(trim($anzeige_selbst_name)) === 'dj-tomjac')) { ?>

            <div class="mod-action-panel">
                <select id="rme-mod-user-select" class="rme-mod-select">
                    <option value="">👤 User wählen...</option>
                </select>
                <button type="button" id="rme-mod-btn-kick" class="rme-mod-btn rme-mod-btn-kick" title="User temporär kicken">Kick</button>
                <button type="button" id="rme-mod-btn-bann" class="rme-mod-btn rme-mod-btn-bann" title="User permanent sperren">Bann</button>
            </div>
        <?php } ?>

<!-- ALT (Hat die alte Datei im Hauptverzeichnis geladen): -->
<!-- <iframe src="rme_chat_stream.php" ... > -->

<!-- NEU (Zwingt das Iframe in den aktuellen Chat-Ordner "infusions/rme_radio_chat_panel/"): -->
<!-- REPARIERT: Lädt den richtigen Player und hält ihn in den perfekten Abmessungen -->
<iframe src="./rme_chat_stream.php" class="chat-player-iframe" style="border:none; width:100%; height:100%; max-height:240px; overflow:hidden;" scrolling="no"></iframe>

<!-- Wunschbox-Button in der Sidebar (Direkt über der Onlineliste) -->
<button type="button" class="rme-sidebar-box-btn" onclick="rmeToggleStudiobox(true)">🎙 Studio Wunschbox</button>

<div class="rme-online-list-title">
    User im Chat:
    <!-- 🔥 UNSER LEUCHTENDER RECHTS-JOKER -->
    <button type="button" class="rme-dsgvo-trigger-btn" onclick="rmeToggleDsgvoBanner(true)">Datenschutz</button>
</div>

        <div id="chat-online-list">Lade...</div>
    </div>
</div>
<!-- DAS DYNAMISCHE USER-KONTEXTMENÜ (WIRD PER JS BEFÜLLT UND GEZEIGT) -->
<div id="rme-user-context-menu" class="rme-user-context-menu" style="display: none;"></div>

<script>
// =========================================================================
// ZIELGERICHTETER LIVE-SERVICE-WORKER-FILTER
// =========================================================================
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then(function(registrations) {
        if (registrations && registrations.length > 0) {
            for (var i = 0; i < registrations.length; i++) {
                var swUrl = registrations[i].active ? registrations[i].active.scriptURL : '';
                
                // Wenn es die fremde sw.js vom Stream-Panel ist, wird sie gezielt gelöscht
                if (swUrl.indexOf('sw.js') !== -1 && swUrl.indexOf('chat_sw.js') === -1) {
                    registrations[i].unregister().then(function(success) {
                        if (success) {
                            console.log("Fremder Service Worker (Stream-Panel) im Chat-Tab blockiert.");
                        }
                    });
                }
            }
        }
    }).catch(function(err) { console.log("SW-Schutz aktiv"); });
}
// =========================================================================



// =========================================================================
// GLOBAL CHAT VARIABLES
// =========================================================================
var rmeChatInput   = document.getElementById('rme-chat-input');
var rmeChatForm    = document.getElementById('rme-chat-form');
var rmeFontPicker  = document.getElementById('rme-font-picker');
var rmeSizePicker  = document.getElementById('rme-size-picker');
var rmeColorPicker = document.getElementById('rme-color-picker');
var rmeFontHidden  = document.getElementById('rme-font-hidden');
var rmeSizeHidden  = document.getElementById('rme-size-hidden');
var rmeColorHidden = document.getElementById('rme-color-hidden');

var isScrolling     = false; 
var siteRoot        = window.location.origin + '/';
var chatWin         = document.getElementById('rme-chat-window');
var activeChatTab   = 'live';
window.currentActionParam = 'history';
window.rmeAktuellerLiveDJ = "AutoDJ";

// =========================================================================
// 👑 AUTOPLAY-BEFREIER: SCHALTET MOD-INTROS UND SOUNDS FÜR DEN BROWSER FREI
// =========================================================================
window.rmeAudioVomBrowserFreigeschaltet = false;

// Sobald der User IRGENDWO in den Chat klickt, schalten wir den Soundkanal scharf!
document.addEventListener('click', function rmeAudioFreischaltenKlick() {
    window.rmeAudioVomBrowserFreigeschaltet = true;
    console.log("🔊 AUDIO-KANAL ERFOLGREICH FÜR INTROS FREIGESCHALTET!");
    
    // Entfernt den Event-Listener wieder, da die Freigabe erteilt wurde
    document.removeEventListener('click', rmeAudioFreischaltenKlick);
}, { once: true });

// =========================================================================
// 21. MASTER-CORE: FORENSIK-ANZEIGE (UNZERSTÖRBARE FRONTEND-ZEIT-BERECHNUNG)
// =========================================================================
function rmeShowUserContextMenu(event, userName, userIP, userFlagge, echtesFlaggenElement, userOS, userBrowser, userDevice, geschriebeneTexte, loginUhrzeit) {
    if (event) event.preventDefault();
    if (event) event.stopPropagation();

    var menu = document.getElementById('rme-user-context-menu');
    if (!menu || !userName) return;

    var meinOS       = userOS || "Unbekannt";
    var meinBrowser  = userBrowser || "Unbekannt";
    var meinDevice   = userDevice || "💻";
    var anzahlTexte  = geschriebeneTexte || "0";
    var betretenUm   = loginUhrzeit || "Unbekannt";

    // 🕒 DIE UNZERSTÖRBARE PRÄZISONS-BERECHNUNG AUS DEINEM ZEIT-STRING ("11.07.2026 - 23:44:37")
    var afkZeitText = "--:--";
    if (betretenUm && betretenUm !== "Unbekannt") {
        try {
            var reineUhrzeit = betretenUm;
            
            // Trennt das Datum "11.07.2026 - " sauber ab, falls es enthalten ist
            if (betretenUm.indexOf(' - ') !== -1) {
                reineUhrzeit = betretenUm.split(' - ')[1].trim(); // Isoliert rein "23:44:37"
            } else if (betretenUm.indexOf(' ') !== -1) {
                var teile = betretenUm.split(' ');
                reineUhrzeit = teile[teile.length - 1].trim();
            }
            
            // Rechnet mit der sauberen Uhrzeit (HH:MM:SS)
            if (reineUhrzeit && reineUhrzeit.indexOf(':') !== -1) {
                var zeitTeile = reineUhrzeit.split(':');
                if (zeitTeile.length >= 2) {
                    var stunden  = parseInt(zeitTeile[0], 10) || 0;
                    var minuten  = parseInt(zeitTeile[1], 10) || 0;
                    var sekunden = zeitTeile[2] ? (parseInt(zeitTeile[2], 10) || 0) : 0;
                    
                    // Exakt 5 Minuten (300 Sekunden) für den AFK-Status addieren
                    minuten += 5;
                    
                    if (minuten >= 60) {
                        stunden += 1;
                        minuten = minuten - 60;
                    }
                    if (stunden >= 24) {
                        stunden = stunden - 24;
                    }
                    
                    // Formatierung mit führenden Nullen
                    var sStr = stunden < 10 ? "0" + stunden : stunden;
                    var mStr = minuten < 10 ? "0" + minuten : minuten;
                    var sekStr = sekunden < 10 ? "0" + sekunden : sekunden;
                    
                    afkZeitText = sStr + ":" + mStr + ":" + sekStr;
                }
            }
        } catch(e) {
            afkZeitText = "--:--";
        }
    }

    var menuHTML = '<div class="rme-context-menu-title" style="font-size:14px !important; font-weight:bold !important; border-bottom:1px solid #444; padding-bottom:6px; margin-bottom:6px; display: flex; align-items: center; gap: 6px;">👤 <span class="rme-title-name">' + userName + '</span></div>';

    if (userIP && userIP.trim() !== "" && userIP !== "0.0.0.0" && userIP !== "undefined") {
        menuHTML += '<div class="rme-context-menu-title" style="color: #ff3333 !important; border-top: 1px solid #333; margin-top: 5px; padding-top: 6px;">⚡ Admin-Tools</div>';
        menuHTML += '<div class="rme-context-menu-ip rme-menu-neon-ip" onclick="event.stopPropagation();">';
        menuHTML += '🌐 IP: ' + userIP;
        menuHTML += '</div>';

        var systemIcon = meinDevice;
        if (meinOS.toLowerCase().indexOf('win') !== -1) { systemIcon = "🪟"; }
        else if (meinOS.toLowerCase().indexOf('lin') !== -1) { systemIcon = "🐧"; }
        else if (meinOS.toLowerCase().indexOf('and') !== -1) { systemIcon = "🤖"; }
        else if (meinOS.toLowerCase().indexOf('ios') !== -1 || meinOS.toLowerCase().indexOf('iphone') !== -1) { systemIcon = "📱"; }

        menuHTML += '<div style="padding: 4px 12px; color: #a855f7 !important; font-size: 11px; font-family: sans-serif; display: flex; align-items: center; gap: 6px;" onclick="event.stopPropagation();">';
        menuHTML += systemIcon + ' System: <span style="color: #fff; font-weight: bold;">' + meinOS + '</span>';
        menuHTML += '</div>';

        menuHTML += '<div style="padding: 4px 12px; color: #38bdf8 !important; font-size: 11px; font-family: sans-serif; display: flex; align-items: center; gap: 6px; margin-top:-2px;" onclick="event.stopPropagation();">';
        menuHTML += '🚀 Browser: <span style="color: #fff; font-weight: bold;">' + meinBrowser + '</span>';
        menuHTML += '</div>';

        menuHTML += '<div style="padding: 4px 12px; color: #fbbf24 !important; font-size: 11px; font-family: sans-serif; display: flex; align-items: center; gap: 6px; margin-top:-2px;" onclick="event.stopPropagation();">';
        menuHTML += '🕒 Letzter Aktiv-Stempel: <span style="color: #fff; font-weight: bold;">' + betretenUm + '</span>';
        menuHTML += '</div>';

        // 💤 INTERNE SCHLAF-PRÜFUNG: Zeigt die berechnete Zeit nur bei echten AFK-Usern an
        var istAktuellAFK = false;
        var userEintragListe = document.getElementById('chat-online-list');
        if (userEintragListe && userEintragListe.innerHTML.indexOf(userName) !== -1) {
            if (userEintragListe.innerHTML.indexOf('[AFK]') !== -1 || userEintragListe.innerHTML.indexOf('color:#ffaa00') !== -1) {
                istAktuellAFK = true;
            }
        }

        // Zeigt die Zeile nur an, wenn der User wirklich AFK ist
        if (istAktuellAFK && afkZeitText !== "--:--") {
            menuHTML += '<div style="padding: 4px 12px; color: #ff9800 !important; font-size: 11px; font-family: sans-serif; display: flex; align-items: center; gap: 6px; margin-top:-2px;" onclick="event.stopPropagation();">';
            menuHTML += '💤 AFK seit ca.: <span style="color: #fff; font-weight: bold;">' + afkZeitText + ' Uhr</span>';
            menuHTML += '</div>';
        }

        menuHTML += '<div style="padding: 4px 12px; color: #34d399 !important; font-size: 11px; font-family: sans-serif; display: flex; align-items: center; gap: 6px; margin-top:-2px;" onclick="event.stopPropagation();">';
        menuHTML += '💬 DB-Texte: <span style="color: #00ff00; font-weight: bold;">' + anzahlTexte + '</span>';
        menuHTML += '</div>';

        menuHTML += '<div class="rme-context-menu-divider"></div>';
    }

    menuHTML += '<div class="rme-context-menu-item" onclick="rmeActionWhisper(\'' + addslashes(userName) + '\');">💬 Flüstern</div>';
    menuHTML += '<div class="rme-context-menu-item" onclick="rmeActionIgnore(\'' + addslashes(userName) + '\');">🚫 Ignorieren</div>';
    menuHTML += '<div class="rme-context-menu-divider"></div>';
    menuHTML += '<div class="rme-context-menu-item" onclick="document.getElementById(\'rme-user-context-menu\').style.display=\'none\';">❌ Schließen</div>';

    menu.innerHTML = menuHTML;

    // Positionierungs-Code bleibt unverändert unten drunter...
    if (echtesFlaggenElement) {
        var geklonteFlagge = echtesFlaggenElement.cloneNode(true);
        geklonteFlagge.style.setProperty('display', 'inline-block', 'important');
        geklonteFlagge.style.setProperty('opacity', '1', 'important');
        geklonteFlagge.style.setProperty('width', 'auto', 'important');
        geklonteFlagge.style.setProperty('height', 'auto', 'important');
        geklonteFlagge.style.setProperty('visibility', 'visible', 'important');
        geklonteFlagge.style.verticalAlign = 'middle';
        geklonteFlagge.style.marginLeft = '6px';
        var titelKasten = menu.querySelector('.rme-context-menu-title');
        if (titelKasten) { titelKasten.appendChild(geklonteFlagge); }
    }

    menu.style.visibility = "hidden";
    menu.style.display = "block";
    var klickElement = event.target;
    var koordinaten = klickElement.getBoundingClientRect();
    var menuBreite = menu.offsetWidth || 180;
    var menuHoehe = menu.offsetHeight || 160;
    var posX = koordinaten.left + (koordinaten.width / 2) - (menuBreite / 2);
    var posY = koordinaten.top - menuHoehe - 6 + window.scrollY;
    if (posX < 10) posX = 10;
    if (posX + menuBreite > window.innerWidth) posX = window.innerWidth - menuBreite - 10;
    if (posY < 10) posY = koordinaten.bottom + 6 + window.scrollY; 
    menu.style.left = posX + "px";
    menu.style.top = posY + "px";
    menu.style.visibility = "visible";
}


// Hilfsfunktion für sichere String-Übergaben im JS
function addslashes(str) {
    return (str + '').replace(/[\\"']/g, '\\$&').replace(/\u0000/g, '\\0');
}

// Globale Variable im Hintergrund initialisieren
window.rmeAktuellerFluesterEmpfaenger = "";

// =========================================================================
// 🔒 1. FLÜSTER-AKTIVIERUNG: 100% GERADE BUTTONS (ABSOLUT CRASH-SICHER)
// =========================================================================
function rmeActionWhisper(userName) {
    var chatInput = document.getElementById('rme-chat-input');
    var whisperLabel = document.getElementById('rme-whisper-indicator');
    var whisperTarget = document.getElementById('rme-whisper-target');
    
    if (chatInput && userName) {
        var saubererName = userName.trim();
        
        // Schreibt den echten Befehl ins Feld für 100% Sende- und Datenbank-Sicherheit!
        chatInput.value = "/w " + saubererName + " "; 
        
        // Aktiviert das gelbe Design im CSS
        chatInput.classList.add('rme-whisper-active');
        chatInput.placeholder = "Private Nachricht an " + saubererName + "...";
        
        // Befüllt und aktiviert das neon-gelbe Hinweisschild
        if (whisperTarget) { whisperTarget.innerText = saubererName; }
        
        // 🔥 DER TRICK: visibility statt display hält die Zeile als unsichtbaren Balken fest!
        if (whisperLabel) { whisperLabel.style.visibility = 'visible'; }
        
        chatInput.focus();
    }
    
    // Lila Kontextmenü schließen
    var menu = document.getElementById('rme-user-context-menu');
    if (menu) menu.style.display = 'none';
}

function rmeActionIgnore(userName) {
    if (!userName) return;

    // 🔥 DER LUSTIGE SELBSTSCHUTZ-JOKER:
    // Wir holen uns Deinen eigenen Namen aus dem Daten-Attribut der Schreibleiste
    var meinEigenerChatName = "";
    var meinInputFeld = document.getElementById('rme-chat-input');
    if (meinInputFeld && meinInputFeld.hasAttribute('data-user')) {
        meinEigenerChatName = meinInputFeld.getAttribute('data-user').trim();
    }

    // Wenn der angeklickte Name mit Deinem eigenen Namen übereinstimmt:
    if (saubererNameVergleich(userName, meinEigenerChatName)) {
        alert('🤨 Du kannst dich nicht selbst ignorieren! Wer soll denn sonst die Musik machen? ^^');
        
        // Menü lautlos schließen und abbrechen
        document.getElementById('rme-user-context-menu').style.display = 'none';
        return;
    }

    // =========================================================================
    // AB HIER LÄUFT DEIN ORIGINALER IGNORIEREN-CODE ABSOLUT UNVERÄNDERT WEITER:
    // =========================================================================
    if (!confirm('Möchtest du Nachrichten von ' + userName + ' ab jetzt komplett ausblenden?')) return;
    
    var ignored = JSON.parse(localStorage.getItem('rme_chat_ignored_users')) || [];
    if (!ignored.includes(userName)) {
        ignored.push(userName);
        localStorage.setItem('rme_chat_ignored_users', JSON.stringify(ignored));
        alert('✔️ ' + userName + ' wurde stummgeschaltet!');
    }
    document.getElementById('rme-user-context-menu').style.display = 'none';
}

// Kleine Hilfsfunktion für einen fehlertoleranten Namensvergleich
function saubererNameVergleich(nameA, nameB) {
    if (!nameA || !nameB) return false;
    return nameA.toLowerCase().replace(/[^a-zA-Z0-9]/g, '') === nameB.toLowerCase().replace(/[^a-zA-Z0-9]/g, '');
}

// BROWSER-MONITOR: STARTET SOBALD DIE SEITE BEREIT IST
document.addEventListener('DOMContentLoaded', function() {
    var chatWin = document.getElementById('rme-chat-window');
    var sidebarWin = document.querySelector('.chat-sidebar') || document.getElementById('chat-online-list');

    if (chatWin) chatWin.style.cursor = 'pointer';
    if (sidebarWin) sidebarWin.style.cursor = 'pointer';

    function rmeSaeubereNamenCore(roherText) {
        if (!roherText) return '';
        var t = roherText.replace(':', '');
        t = t.replace('[ADMIN]', '').replace('[MODERATOR]', '').replace('[HADMIN]', '').replace('[DJ]', '');
        t = t.replace('[', '').replace(']', '');
        return t.trim();
    }

    // ✔ MONITOR A: REPARIERTER KLICK-MONITOR (MENÜ NUR BEI EXAKTEM NAMENSKLICK)
    if (chatWin) {
        chatWin.addEventListener('click', function(event) {
            var klickZiel = event.target;
            if (!klickZiel || klickZiel === chatWin) return;

            // 🔥 DIE MESSERSCHARFE SCHRANKE:
            // Das Menü öffnet sich NUR, wenn exakt auf die Namens-Klasse geklickt wurde!
            // Klicks auf den Text, Smileys oder Lücken werden sofort ignoriert.
            if (!klickZiel.classList.contains('chat-live-msg-user') && !klickZiel.classList.contains('chat-msg-user')) {
                return; 
            }

            var zeile = klickZiel.closest('div, p, li, [class*="row"], .rme-chat-row');
            if (!zeile) return;

            var roherName = klickZiel.innerText || klickZiel.textContent;
            var saubererName = rmeSaeubereNamenCore(roherName);
            
            if (saubererName && saubererName.length > 2 && saubererName.toLowerCase() !== 'afk') {
                event.preventDefault();
                event.stopPropagation();

                var gefundeneIP = klickZiel.getAttribute('data-ip') || "";
                
                if (!gefundeneIP) {
                    var fahnenSpan = zeile.querySelector('.rme-admin-geo-trigger, .sidebar-fahne, [id*="country_"]');
                    if (fahnenSpan) { gefundeneIP = fahnenSpan.getAttribute('data-ip') || ""; }
                }

                console.log("👑 CHAT-MENÜ GEZÜNDET: " + saubererName + " | IP: " + gefundeneIP);
                rmeShowUserContextMenu(event, saubererName, gefundeneIP, '');
            }
        });
    


        // 2. DER DOPPELKLICK-MONITOR IM CHATVERLAUF: (MIT INTELLIGENTEM REPETITIONS-FILTER!)
        chatWin.addEventListener('dblclick', function(event) {
            var klickZiel = event.target;
            if (!klickZiel) return;

            var smileyKuerzel = "";

            // Fall A: Es ist ein Bild (FTP-GIF oder MySQL-BLOB)
            if (klickZiel.tagName === 'IMG') {
                if (klickZiel.classList.contains('sidebar-fahne') || klickZiel.id.indexOf('country_') !== -1) return;
                
                // Wir holen uns das Kürzel strikt aus dem Title-Attribut
                smileyKuerzel = klickZiel.getAttribute('title') || klickZiel.getAttribute('alt');
            } 
            // Fall B: Es ist ein System-Emoji (Span-Text)
            else if (klickZiel.classList.contains('rme-smiley-item')) {
                smileyKuerzel = klickZiel.getAttribute('title');
            }

            if (smileyKuerzel) {
                smileyKuerzel = smileyKuerzel.trim();

                // Falls im String durch Altlasten doch zwei Kürzel stehen, hacken wir das zweite ab
                if (smileyKuerzel.indexOf(' ') !== -1) {
                    smileyKuerzel = smileyKuerzel.split(' ')[0];
                }

                event.preventDefault();
                event.stopPropagation();

                // Schreibleiste ermitteln
                var chatInput = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
                if (chatInput) {
                    var aktuellerText = chatInput.value;
                    
                    // 🔥 DER UNFEHLBARE FILTER:
                    // Wir prüfen, ob der aktuelle Text am Ende bereits exakt mit diesem Kürzel endet!
                    var testText = aktuellerText.trim();
                    if (testText.endsWith(smileyKuerzel)) {
                        console.log("🚫 DOPPELUNG ABGEFANGEN: " + smileyKuerzel + " steht bereits am Ende.");
                        chatInput.focus();
                        return; 
                    }

                    // Wenn es noch nicht da steht, fügen wir es ganz normal ein
                    if (aktuellerText.length > 0 && !aktuellerText.endsWith(' ')) { 
                        chatInput.value += ' '; 
                    }
                    
                    chatInput.value += smileyKuerzel + ' ';
                    chatInput.focus();
                    console.log("🎯 ERFOLGREICH UND EINZELN KOPIERT: " + smileyKuerzel);
                }
            }
        });
    }

    // ✔ MONITOR B: FÜR DIE RECHTE SIDEBAR (USERLISTE)
    if (sidebarWin) {
        sidebarWin.addEventListener('click', function(event) {
            var klickZiel = event.target;
            if (!klickZiel || klickZiel === sidebarWin) return;

            var trZeile = klickZiel.closest('.rme-online-main-tr, tr, td');
            if (!trZeile) return;

            var namensElement = trZeile.querySelector('.rme-online-username-text') || klickZiel;
            if (!namensElement) return;

            var roherName = namensElement.innerText || namensElement.textContent;
            var saubererSidebarName = rmeSaeubereNamenCore(roherName).replace('●', '').trim();

            if (saubererSidebarName && saubererSidebarName.length > 2 && saubererSidebarName.indexOf('online') === -1 && saubererSidebarName.indexOf('Hörer') === -1) {
                event.preventDefault();
                event.stopPropagation();

                var ipElement = trZeile.querySelector('.rme-admin-geo-country');
                var gefundeneIP = ipElement ? (ipElement.textContent || ipElement.innerText).trim() : "";
                
                var fahnenElement = trZeile.querySelector('.sidebar-fahne, .rme-admin-geo-trigger');
                var gefundeneFlagge = fahnenElement ? (fahnenElement.textContent || fahnenElement.innerText).trim() : "DE";

                // 🔥 DATA-BRÜCKE EXAKT AN BACKEND V9 GEKOPPELT:
                var gefundeneOS      = ipElement ? ipElement.getAttribute('data-os') || "Unbekannt" : "Unbekannt";
                var gefundenerBrowser = ipElement ? ipElement.getAttribute('data-browser') || "Unbekannt" : "Unbekannt";
                var gefundenesDevice  = ipElement ? ipElement.getAttribute('data-device') || "💻" : "💻";
                
                var geschriebeneTexte = ipElement ? ipElement.getAttribute('data-msg') || "0" : "0";
                var loginUhrzeit      = ipElement ? ipElement.getAttribute('data-login') || "Unbekannt" : "Unbekannt";
                
                console.log("👥 SIDEBAR-KLICK: " + saubererSidebarName + " | OS: " + gefundeneOS + " | Browser: " + gefundenerBrowser);
                
                // Übergibt die Variablen in exakt derselben Reihenfolge ans lila Menü!
                rmeShowUserContextMenu(event, saubererSidebarName, gefundeneIP, gefundeneFlagge, fahnenElement, gefundeneOS, gefundenerBrowser, gefundenesDevice, geschriebeneTexte, loginUhrzeit);

            }

        });
    }

    // INTERNE VORSCHLAGS-SUCHE DEINER INTROS (BOMBENFEST GESICHERT)
    var searchInput = document.getElementById('rme_intro_search_name');
    var resultsDiv = document.getElementById('rme_intro_search_results');
    if (searchInput && resultsDiv) {
        searchInput.addEventListener('input', function() {
            var query = searchInput.value.trim();
            if (query.length < 2) { resultsDiv.style.display = 'none'; return; }

            fetch('rme_background_handler.php?action=search_moderators&q=' + encodeURIComponent(query))
            .then(function(res) { return res.json(); })
            .then(function(data) {
                resultsDiv.innerHTML = '';
                if (data.status === 'success' && data.users.length > 0) {
                    data.users.forEach(function(user) {
                        var div = document.createElement('div');
                        div.style.padding = '8px';
                        div.style.cursor = 'pointer';
                        div.style.borderBottom = '1px solid #222';
                        div.style.background = '#1a1a1a';
                        div.innerHTML = '<strong style="color:#00ff00;">' + user.name + '</strong> <span style="color:#666;">(ID: ' + user.id + ')</span>';
                        
                        div.addEventListener('click', function() {
                            document.getElementById('rme_intro_target_id_manual').value = user.id;
                            document.getElementById('rme_intro_target_name_manual').value = user.name;
                            searchInput.value = user.name;
                            resultsDiv.style.display = 'none';
                        });
                        resultsDiv.appendChild(div);
                    });
                    resultsDiv.style.display = 'block';
                } else {
                    resultsDiv.innerHTML = '<div style="padding:8px; color:#666; background:#1a1a1a;">Keine User gefunden.</div>';
                    resultsDiv.style.display = 'block';
                }
            }).catch(function(err) { console.error("Suchfehler:", err); });
        });
    }
});

// MENÜ BEI KLICK AUSSERHALB SCHLIESSEN
document.addEventListener('click', function() {
    var menu = document.getElementById('rme-user-context-menu');
    if (menu) menu.style.display = 'none';
});

// =========================================================================
// 19A. INTRO-MANAGER: MANUELLER UPLOAD (JETZT MIT REALER DATEI-ZÜNDUNG)
// =========================================================================
function rmeUploadModIntroManual(e) {
    if(e) e.preventDefault();
    
    // Greift die unsichtbaren ID- und Namensfelder ab, die die Live-Suche befüllt hat
    var idInput = document.getElementById('rme_intro_target_id_manual');
    var nameInput = document.getElementById('rme_intro_target_name_manual');
    var fileInput = document.getElementById('rme_intro_file');
    
    if (!idInput || !nameInput || !fileInput || fileInput.files.length === 0 || !idInput.value.trim() || !nameInput.value.trim()) {
        alert('Bitte wähle zuerst einen Namen aus der Live-Suchliste aus und nimm ein MP3!');
        return;
    }

    var targetUserId = idInput.value.trim();
    var targetUserName = nameInput.value.trim();

    var inputFeld = document.getElementById('rme-chat-input');
    var chefName = inputFeld ? inputFeld.getAttribute('data-user') : '';
    var chefID = inputFeld ? inputFeld.getAttribute('data-id') : '0';

    var formData = new FormData();
    // JETZT KORREKT: Reicht das echte, binäre Musik-Paket ein!
    formData.append('intro_file', fileInput.files[0]); 
    formData.append('target_user_id', targetUserId);
    formData.append('target_user_name', targetUserName);
    formData.append('chef_name', chefName);
    formData.append('chef_id', chefID);

    fetch('rme_background_handler.php?action=upload_mod_intro', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.status === 'success') {
            alert('🚀 BINGO! Das Intro für ' + targetUserName + ' wurde erfolgreich in der MySQL-Tabelle eingebrannt!');
            window.location.reload(); // Aktualisiert das Popup, damit der Name unten in der Liste erscheint
        } else { 
            alert('❌ Fehler vom Server: ' + data.message); 
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('❌ Verbindungsfehler beim Hochladen des Intros.');
    });
}

// =========================================================================
// 19B. INTRO-MANAGER: LÖSCH- UND WIEDERGABE-LOGIK
// =========================================================================
function rmeDeleteModIntro(userId) {
    if (!confirm('Intro wirklich löschen?')) return;
    var inputFeld = document.getElementById('rme-chat-input');
    var chefName = inputFeld ? inputFeld.getAttribute('data-user') : '';
    var chefID = inputFeld ? inputFeld.getAttribute('data-id') : '0';

    var formData = new FormData();
    formData.append('target_user_id', userId);
    formData.append('chef_name', chefName);
    formData.append('chef_id', chefID);

    fetch('rme_background_handler.php?action=delete_mod_intro', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            alert('🗑️ Das Intro wurde restlos gelöscht!');
            window.location.reload();
        } else { alert('❌ Fehler: ' + data.message); }
    })
    .catch(error => console.error('Error:', error));
}
// =========================================================================
// 🔥 NEU: INTERNER LIVE-ZÜNDER FÜR DIE INTRO-VORSCHAU (MANUELLER SEITEN-FUNK)
// =========================================================================
function rmeTriggerIntroLive(targetUserId) {
    if (!targetUserId || targetUserId <= 0) return;

    // Prüfen, ob Sounds im Chat generell erlaubt sind
    var soundsAktiv = document.getElementById('rme-sound-toggle') ? document.getElementById('rme-sound-toggle').checked : true;
    if (!soundsAktiv) {
        alert("Hinweis: Du hast die Chat-Sounds deaktiviert! Schalte sie erst ein.");
        return;
    }

    console.log("🔊 MANUELLER LIVE-TEST: Intro für User-ID " + targetUserId + " wird gezündet!");
    
    try {
        var aktuellerZeitstempel = new Date().getTime();
        
        // Wir nutzen exakt denselben unzerstörbaren Pfad wie das System!
        var testAudio = new Audio('rme_background_handler.php?action=stream_mod_intro&target_user_id=' + targetUserId + '&t=' + aktuellerZeitstempel);
        testAudio.volume = 0.9;
        
        // Zündung! Da der Klick vom User kommt, blockiert der Browser das Abspielen niemals
        testAudio.play().catch(function(e) {
            console.error("Wiedergabe vom Browser blockiert:", e);
        });
    } catch(err) {
        console.error("Fehler beim manuellen Zünden des Intros:", err);
    }
}

// Variable, die sich merkt, ob das Intro noch auf seinen Start wartet
var rmeWartendesIntroUserId = 0;

// =========================================================================
// 19C. INTRO-STEUERUNG: AUDIO-ZÜNDUNG (VERSION GEGEN BROWSER-AUTOPLAY-SPERRE)
// =========================================================================
function rmeCheckAndPlayUserIntros(nachrichtenText) {
    if (!nachrichtenText) return;

    var soundsAktiv = document.getElementById('rme-sound-toggle') ? document.getElementById('rme-sound-toggle').checked : true;
    if (!soundsAktiv) return;

    var textKlein = nachrichtenText.toLowerCase();
    var startTag = '[intro_user_';
    var startPos = textKlein.indexOf(startTag);

    if (startPos !== -1) {
        var endPos = textKlein.indexOf(']', startPos);
        if (endPos !== -1) {
            var idText = textKlein.substring(startPos + startTag.length, endPos);
            var targetUserId = parseInt(idText);

            if (targetUserId > 0) {
                // Wir merken uns die ID, falls der Browser gleich blockiert
                rmeWartendesIntroUserId = targetUserId;
                rmeFuehreIntrozuendungAus(targetUserId);
            }
        }
    }
}

// Interne Hilfsfunktion für die eigentliche Audiowiedergabe und das Nachzünden
function rmeFuehreIntrozuendungAus(targetUserId) {
    setTimeout(function() {
        try {
            var aktuellerZeitstempel = new Date().getTime();
            
            // 🎯 REPARIERT: Steuert jetzt Deine play_sound.php mit der echten User-ID an!
            // Das funkt das Signal direkt an Deine Tabelle fusionb7754_chat_intro.
            var introAudioPfad = 'play_sound.php?intro_user_id=' + targetUserId;
            
            console.log("🔊 INTRO-STREAMER: Lade Login-Intro aus der Intro-Tabelle für User: " + targetUserId);
            
            var introAudio = new Audio(introAudioPfad + '&t=' + aktuellerZeitstempel);
            introAudio.volume = 0.9;
            
            introAudio.play().then(function() {
                console.log("👑 LOGIN-INTRO ERFOLGREICH GEZÜNDET FÜR USER: " + targetUserId);
                rmeWartendesIntroUserId = 0;
            }).catch(function(e) {
                console.warn("Browser blockiert Autoplay. Intro wartet auf Klick.", e);
                rmeWartendesIntroUserId = targetUserId;
                
                document.addEventListener('click', function rmeErsterKlickZuendung() {
                    if (rmeWartendesIntroUserId > 0) {
                        rmeFuehreIntrozuendungAus(rmeWartendesIntroUserId);
                    }
                    document.removeEventListener('click', rmeErsterKlickZuendung);
                }, { once: true });
            });
        } catch(err) {
            console.error("Fehler bei Audio-Wiedergabe:", err);
        }
    }, 300);
}

// =========================================================================
// 18. USER-KOMFORT: LIVE-SYSTEM-STATUS & PING-MONITOR
// =========================================================================
function rmeRunSystemStatusMonitor() {
    var netDisplay = document.getElementById('rme-status-network');
    var pingDisplay = document.getElementById('rme-status-ping');
    var storageDisplay = document.getElementById('rme-status-storage');
    
    if (!netDisplay || !pingDisplay) return;

    // 1. Netzwerk-Check (Browser-Ebene)
    if (navigator.onLine) {
        netDisplay.innerHTML = "ONLINE 🟢";
        netDisplay.style.color = "#00ff00";
        netDisplay.style.textShadow = "0 0 5px #00ff00";
    } else {
        netDisplay.innerHTML = "TRENNUNG 🔴";
        netDisplay.style.color = "#ff3333";
        netDisplay.style.textShadow = "0 0 5px #ff3333";
    }

    // 2. Browser-Speicher-Check
    try {
        localStorage.setItem('rme_ping_test', '1');
        localStorage.removeItem('rme_ping_test');
        if (storageDisplay) {
            storageDisplay.innerHTML = "BEREIT ✔️";
            storageDisplay.style.color = "#00ff00";
        }
    } catch(e) {
        if (storageDisplay) {
            storageDisplay.innerHTML = "BLOCKIERT ❌";
            storageDisplay.style.color = "#ffaa00";
        }
    }

    // 3. ECHTER SERVER-PING: Wir messen die Zeit für eine Mini-Anfrage zum Handler
    var startTime = performance.now();
    
    // Wir klopfen kurz beim leichten Hintergrund-Handler an
    fetch('rme_background_handler.php?action=disable_global_bg', { 
        method: 'HEAD', // HEAD lädt keine Daten, fragt nur den Server ab -> Extrem schnell und ressourcenschonend!
        cache: 'no-store' // Verhindert, dass der Browser schummelt und aus dem Cache liest
    })
    .then(function() {
        var endTime = performance.now();
        var pingMs = Math.round(endTime - startTime);
        
        pingDisplay.innerHTML = pingMs + " ms";
        
        // Farbe je nach Verbindungsqualität anpassen (Grün, Gelb, Rot)
        if (pingMs < 80) {
            pingDisplay.style.color = "#00ff00";
            pingDisplay.style.textShadow = "0 0 5px #00ff00";
        } else if (pingMs < 200) {
            pingDisplay.style.color = "#ffaa00";
            pingDisplay.style.textShadow = "0 0 5px #ffaa00";
        } else {
            pingDisplay.style.color = "#ff3333";
            pingDisplay.style.textShadow = "0 0 5px #ff3333";
        }
    })
    .catch(function() {
        pingDisplay.innerHTML = "TIMEOUT ⚠️";
        pingDisplay.style.color = "#ff3333";
    });
}

// Überwacht das Internet-Signal des Betriebssystems live
window.addEventListener('online', rmeRunSystemStatusMonitor);
window.addEventListener('offline', rmeRunSystemStatusMonitor);

// Führt den Monitor alle 10 Sekunden unauffällig aus, wenn das Zahnrad offen ist
setInterval(function() {
    var popup = document.getElementById('rme-settings-popup');
    if (popup && (popup.style.display === 'block' || window.getComputedStyle(popup).display === 'block')) {
        rmeRunSystemStatusMonitor();
    }
}, 10000);

// Einmalig direkt beim Laden ausführen
document.addEventListener('DOMContentLoaded', rmeRunSystemStatusMonitor);
setTimeout(rmeRunSystemStatusMonitor, 800);


// =========================================================================
// 17. USER-KOMFORT: LIVE-ZEICHENZÄHLER (SOUNDBOARD-COMPATIBLE)
// =========================================================================
function rmeInitCharCounter() {
    var chatInput = document.getElementById('rme-chat-input');
    var counter = document.getElementById('rme-char-counter');
    if (!chatInput || !counter) return;

    var maxZeichen = 200; // Maximale Chat-Länge

    function rmeNurZaehlen(event) {
        // SICHERHEITS-WEICHE: Wenn das Event vom Soundboard-Script automatisiert
        // ausgelöst wurde (ohne echte Tastatur-Eingabe), zählen wir NICHT!
        if (event && event.isTrusted === false) return;

        var aktuelleLaenge = chatInput.value.length;
        counter.innerHTML = aktuelleLaenge + " / " + maxZeichen;

        if (aktuelleLaenge >= maxZeichen) {
            counter.classList.add('rme-counter-limit');
            chatInput.value = chatInput.value.substring(0, maxZeichen); 
        } else {
            counter.classList.remove('rme-counter-limit');
        }
    }

    // Horcht NUR auf echte manuelle Eingaben, blockiert keine Hintergrund-Scripte
    chatInput.addEventListener('input', rmeNurZaehlen);
    
    // Initial einmal zählen
    var startLaenge = chatInput.value.length;
    counter.innerHTML = startLaenge + " / " + maxZeichen;
}

// Startet den Zähler unauffällig im Hintergrund
document.addEventListener('DOMContentLoaded', rmeInitCharCounter);
setTimeout(rmeInitCharCounter, 500);

// =========================================================================
// 15. USER-KOMFORT: ZEITSTEMPEL (UHRZEIT) EIN-/AUSBLENDEN (NEON-FIX)
// =========================================================================
function rmeApplySavedTimestamps() {
    var savedState = localStorage.getItem('rme_chat_timestamps') || 'show';
    var toggleBtn = document.getElementById('rme-time-toggle-btn');
    
    var styleId = 'rme-dynamic-timestamp-style';
    var styleBlock = document.getElementById(styleId);
    
    if (!styleBlock) {
        styleBlock = document.createElement('style');
        styleBlock.id = styleId;
        document.head.appendChild(styleBlock);
    }
    
    if (savedState === 'hide') {
        if (toggleBtn) {
            toggleBtn.innerHTML = 'Ausgeblendet ❌';
            toggleBtn.style.background = '#cc2424'; // Rot bei Aus
        }
        // SPEZIAL-TREFFER: Blendet Deine exakte Neon-Klasse aus dem Chatverlauf aus!
        styleBlock.innerHTML = `
            .rme-neon-time {
                display: none !important;
            }
        `;
    } else {
        if (toggleBtn) {
            toggleBtn.innerHTML = 'Anzeigen ✔️';
            toggleBtn.style.background = '#0076a3'; // Blau bei An
        }
        styleBlock.innerHTML = '';
    }
}

function rmeToggleTimestamps() {
    var currentState = localStorage.getItem('rme_chat_timestamps') || 'show';
    var newState = (currentState === 'show') ? 'hide' : 'show';
    
    localStorage.setItem('rme_chat_timestamps', newState);
    rmeApplySavedTimestamps();
}

document.addEventListener('DOMContentLoaded', rmeApplySavedTimestamps);
setTimeout(rmeApplySavedTimestamps, 500);

// =========================================================================
// 14. USER-KOMFORT: DARK MODE / LIGHT MODE UMSCHALTER (PURE CSS-STEUERUNG)
// =========================================================================
function rmeApplySavedTheme() {
    var savedTheme = localStorage.getItem('rme_chat_theme') || 'dark';
    var toggleBtn = document.getElementById('rme-theme-toggle-btn');
    
    // Wir hängen die Klasse direkt an den "body", damit sie ALLES auf der Seite erfasst!
    if (savedTheme === 'light') {
        document.body.classList.add('rme-light-mode');
        if (toggleBtn) toggleBtn.innerHTML = 'Hell ☀️';
    } else {
        document.body.classList.remove('rme-light-mode');
        if (toggleBtn) toggleBtn.innerHTML = 'Dunkel 🌙';
    }
}

function rmeToggleTheme() {
    var currentTheme = localStorage.getItem('rme_chat_theme') || 'dark';
    var newTheme = (currentTheme === 'dark') ? 'light' : 'dark';
    
    localStorage.setItem('rme_chat_theme', newTheme);
    rmeApplySavedTheme();
}

document.addEventListener('DOMContentLoaded', rmeApplySavedTheme);
setTimeout(rmeApplySavedTheme, 500);

// =========================================================================
// 13. USER-KOMFORT: SCHRIFTGRÖSSE IM CHAT DYNAMISCH ANPASSEN (MIT RESET)
// =========================================================================
function rmeApplySavedFontSize() {
    var savedSize = localStorage.getItem('rme_chat_font_size') || '16';
    
    var styleId = 'rme-dynamic-font-style';
    var styleBlock = document.getElementById(styleId);
    
    if (!styleBlock) {
        styleBlock = document.createElement('style');
        styleBlock.id = styleId;
        document.head.appendChild(styleBlock);
    }
    
    styleBlock.innerHTML = `
        .chat-live-msg-text, .rme-message-text, [class*="msg-text"] {
            font-size: ${savedSize}px !important;
        }
    `;
}

function rmeChangeFontSize(direction) {
    var currentSize = parseInt(localStorage.getItem('rme_chat_font_size')) || 16;
    var newSize = currentSize + direction;

    if (newSize >= 10 && newSize <= 36) {
        localStorage.setItem('rme_chat_font_size', newSize);
        rmeApplySavedFontSize();
    }
}

// NEU: Setzt die Schriftgröße sofort wieder auf den Standardwert zurück
function rmeResetFontSize() {
    localStorage.setItem('rme_chat_font_size', 16);
    rmeApplySavedFontSize();
}

document.addEventListener('DOMContentLoaded', rmeApplySavedFontSize);
setTimeout(rmeApplySavedFontSize, 500);


// =========================================================================
// 11. ADMIN-FUNKTIONEN FÜR DEN GLOBALEN CHAT-HINTERGRUND (CHEF-ZENTRALE)
// =========================================================================
function rmeSubmitChatBackground(e) {
    if(e) e.preventDefault();
    
    var fileInput = document.getElementById('rme_bg_datei');
    if (!fileInput || fileInput.files.length === 0) {
        alert('Bitte wähle zuerst eine Bilddatei von Deinem PC/Handy aus!');
        return;
    }

    var inputFeld = document.getElementById('rme-chat-input');
    var chefName = inputFeld ? inputFeld.getAttribute('data-user') : '';
    var chefID = inputFeld ? inputFeld.getAttribute('data-id') : '0';

    var formData = new FormData();
    // KORREKTUR: Holt exakt die erste ausgewählte Datei [0] wie beim Smiley-Uploader!
    formData.append('bg_datei', fileInput.files[0]); 
    formData.append('chef_name', chefName);
    formData.append('chef_id', chefID);

    fetch('rme_background_handler.php?action=upload_global_bg', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            alert('🚀 BINGO! Globaler Chat-Hintergrund erfolgreich aktiviert!');
            
            var statusBox = document.getElementById('rme-admin-bg-status-box');
            if (statusBox) {
                statusBox.innerHTML = "🟢 Aktiv: <span class='rme-bg-status-active'>" + data.filename + "</span>";
            }
            
            var disableBtn = document.getElementById('rme-btn-disable-bg');
            if (disableBtn) { disableBtn.style.display = 'block'; }
            
            fileInput.value = '';
            
            var chatWin = document.getElementById('rme-chat-window');
            if (chatWin) {
                chatWin.style.backgroundImage = "url('" + data.path + "')";
                chatWin.style.backgroundSize = "cover";
                chatWin.style.backgroundPosition = "center";
            }
        } else {
            alert('❌ Meldung vom Server-Script: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('❌ Verbindungsfehler bei der Chef-Zentrale! Der Handler wurde nicht erreicht.');
    });
}

function rmeDisableChatBackground(e) {
    if(e) e.preventDefault();
    if (!confirm('Möchtest du das Hintergrundbild wirklich für alle ausschalten?')) return;

    var inputFeld = document.getElementById('rme-chat-input');
    var chefName = inputFeld ? inputFeld.getAttribute('data-user') : '';
    var chefID = inputFeld ? inputFeld.getAttribute('data-id') : '0';

    var formData = new FormData();
    formData.append('chef_name', chefName);
    formData.append('chef_id', chefID);

    fetch('rme_background_handler.php?action=disable_global_bg', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            alert('🛑 Globaler Hintergrund erfolgreich ausgeschaltet.');
            
            var statusBox = document.getElementById('rme-admin-bg-status-box');
            if (statusBox) {
                statusBox.innerHTML = "<span class='rme-bg-status-disabled'>⚪ Ausgeschaltet (Standardfarbe)</span>";
            }
            
            var disableBtn = document.getElementById('rme-btn-disable-bg');
            if (disableBtn) { disableBtn.style.display = 'none'; }
            
            var chatWin = document.getElementById('rme-chat-window');
            if (chatWin) { chatWin.style.backgroundImage = 'none'; }
        } else {
            alert('❌ Fehler: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

// =========================================================================
// 12. USER-FUNKTIONEN FÜR DEN PERSÖNLICHEN CHAT-HINTERGRUND
// =========================================================================
function rmeSubmitUserBackground(e) {
    if(e) e.preventDefault();
    
    var fileInput = document.getElementById('rme_user_bg_file');
    if (!fileInput || fileInput.files.length === 0) {
        alert('Bitte wähle zuerst eine Bilddatei von Deinem PC/Handy aus!');
        return;
    }

    var inputFeld = document.getElementById('rme-chat-input');
    var userID = inputFeld ? inputFeld.getAttribute('data-id') : '0';
    var userName = inputFeld ? inputFeld.getAttribute('data-user') : '';

    var formData = new FormData();
    // KORREKTUR: Auch hier zwingend [0] eintragen, um den Absturz zu verhindern!
    formData.append('rme_user_bg_file', fileInput.files[0]); 
    formData.append('user_id_gesendet', userID);
    formData.append('user_name_gesendet', userName);

    fetch('rme_background_handler.php?action=upload_user_bg', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            alert('🚀 BINGO! Dein persönlicher Chat-Hintergrund wurde gespeichert!');
            fileInput.value = '';
            
            var chatWin = document.getElementById('rme-chat-window');
            if (chatWin) {
                chatWin.style.backgroundImage = "url('" + data.path + "')";
                chatWin.style.backgroundSize = "cover";
                chatWin.style.backgroundPosition = "center";
            }
        } else {
            alert('❌ Meldung vom Server-Script: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Upload-Fehler:', error);
        alert('❌ Verbindungsfehler beim User-Upload! Der Handler wurde nicht erreicht.');
    });
}

function rmeResetUserBackground(e) {
    if(e) e.preventDefault();
    if (!confirm('Möchtest du deinen eigenen Hintergrund wirklich löschen und das Standard-Design wieder aktivieren?')) return;

    var inputFeld = document.getElementById('rme-chat-input');
    var userID = inputFeld ? inputFeld.getAttribute('data-id') : '0';

    var formData = new FormData();
    formData.append('user_id_gesendet', userID);

    fetch('rme_background_handler.php?action=reset_user_bg', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            alert('🛑 Hintergrund erfolgreich zurückgesetzt!');
            
            var chatWin = document.getElementById('rme-chat-window');
            if (chatWin) {
                if (data.admin_bg && data.admin_bg !== '') {
                    chatWin.style.backgroundImage = "url('" + data.admin_bg + "')";
                } else {
                    chatWin.style.backgroundImage = 'none';
                }
            }
        } else {
            alert('❌ Fehler: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
    });
}

// =========================================================================
// SMILEY-UPLOADER MIT INTEGRATION DES CHEF-SICHERHEITSGURTS
// =========================================================================
function rmeSubmitNewSmiley(event) {
    if(event) event.preventDefault(); // Stoppt das Neuladen der Seite
    
    // 1. Kategorie ermitteln
    var kategorieSelect = document.getElementById('rme_sm_kategorie');
    var kategorie = kategorieSelect ? kategorieSelect.value : 'Allgemein';
    
    if (kategorie === 'NEU') {
        var neueKatInput = document.getElementById('rme_sm_kategorie_neu_unten');
        kategorie = neueKatInput ? neueKatInput.value.trim() : '';
        if (kategorie === '') {
            alert('Bitte gib einen Namen für die neue Kategorie ein!');
            return;
        }
    }

    // 2. Kürzel und Datei auslese-Felder ansteuern
    var kuerzelInput = document.getElementById('rme_sm_kuerzel');
    var kuerzel = kuerzelInput ? kuerzelInput.value.trim() : '';
    var dateiInput = document.getElementById('rme_sm_datei');

    if (!kuerzel) {
        alert('Bitte ein Chat-Kürzel eingeben!');
        return;
    }
    if (!dateiInput || dateiInput.files.length === 0) {
        alert('Bitte wähle eine GIF- oder PNG-Datei von Deinem PC/Handy aus!');
        return;
    }

    // 3. Sicherheits-Abfrage der IDs aus dem echten Chat-Input
    var inputFeld = document.getElementById('rme-chat-input');
    var chefName = inputFeld ? inputFeld.getAttribute('data-user') : '';
    var chefID = inputFeld ? inputFeld.getAttribute('data-id') : '0';

    // 4. FormData Paket schnüren (MIT ABSENDER-STEMPEL!)
    var formData = new FormData();
    formData.append('kategorie', kategorie);
    formData.append('kuerzel', kuerzel);
    formData.append('smiley_file', dateiInput.files[0]); // Holt die echte Bilddatei
    
    // DIE RETTUNG: Wir legen Deine Chef-Identität mit ins Paket!
    formData.append('chef_name', chefName);
    formData.append('chef_id', chefID);

    // 5. Absenden an den MySQL-Handler
    fetch('rme_smilies_handler.php?action=upload_smiley', {
        method: 'POST',
        body: formData
    })
    .then(function(response) {
        return response.text();
    })
    .then(function(rawText) {
        var gesaeuberterText = rawText.trim();
        try {
            var data = JSON.parse(gesaeuberterText);
            if (data.status === 'success') {
                var debugBox = document.getElementById('rme-debug-error-box');
                if (debugBox) { debugBox.remove(); }
                
                // CRASH-SICHER: Setzt das Formular nur zurück, wenn die ID real existiert!
                var smileyForm = document.getElementById('rme-admin-smiley-form');
                if (smileyForm) { smileyForm.reset(); }
                
                // Blendet das Textfeld nach Erfolg wieder aus
                var w = document.getElementById('rme_popup_sm_kategorie_wrapper');
                if (w) { w.style.display = 'none'; }
                
                alert('🚀 BINGO! Smiley erfolgreich direkt in MySQL hochgeladen!');
                window.location.reload(); // Lädt den Chat neu, damit PHP das Bild anzeigt!
            } else {
                rmeZeigeKopierbarenFehler('Meldung vom Server-Script:\n' + data.message);
            }
        } catch(jsonErr) {
            if (gesaeuberterText.indexOf('"status":"success"') !== -1) {
                alert('🚀 BINGO! Smiley erfolgreich direkt in MySQL hochgeladen!');
                document.getElementById('rme-admin-smiley-form').reset();
                window.location.reload();
            } else {
                rmeZeigeKopierbarenFehler('Kritischer PHP-Fehler im Handler! Antwort lautet:\n' + rawText);
            }
        }
    })
    .catch(function(error) {
        console.error('Upload-Fehler:', error);
        rmeZeigeKopierbarenFehler('Verbindungsfehler! Der Handler wurde nicht erreicht.\nDetails: ' + error.message);
    });
}
// =========================================================================
// 16. USER-KOMFORT: SMILEY-KATEGORIEN PER TABS LIVE UMSCHALTEN
// =========================================================================
function rmeSwitchSmileyTab(event, tabId) {
    if (event) event.preventDefault();

    // 1. Alle Inhalts-Boxen unsichtbar schalten
    var contents = document.getElementsByClassName('rme-smiley-tab-content');
    for (var i = 0; i < contents.length; i++) {
        contents[i].style.display = 'none';
    }

    // 2. Alle Tab-Buttons auf "inaktiv" setzen
    var tabs = document.getElementsByClassName('rme-smiley-tab');
    for (var i = 0; i < tabs.length; i++) {
        tabs[i].classList.remove('active');
    }

    // 3. Den ausgewählten Inhalt anzeigen und den Button auf "aktiv" setzen
    var targetContent = document.getElementById(tabId);
    if (targetContent) {
        targetContent.style.display = 'block';
    }
    if (event && event.currentTarget) {
        event.currentTarget.classList.add('active');
    }
}

// =======================================================
// Neues Sounds für den Chat
// =======================================================

function rmeSubmitNewSound(event) {
    if(event) event.preventDefault();
    
    var soundName = document.getElementById('rme_so_name').value.trim();
    var dateiInput = document.getElementById('rme_so_datei');

    if (!soundName || !dateiInput || dateiInput.files.length === 0) {
        alert('Bitte einen Button-Namen eingeben und ein MP3 auswählen!');
        return;
    }

    // Sicherheits-Abfrage der IDs aus dem echten Chat-Input
    var inputFeld = document.getElementById('rme-chat-input');
    var chefName = inputFeld ? inputFeld.getAttribute('data-user') : '';
    var chefID = inputFeld ? inputFeld.getAttribute('data-id') : '0';

    var formData = new FormData();
    formData.append('sound_name', soundName);
    formData.append('sound_file', dateiInput.files[0]); // Holt das echte MP3-Paket!
    
    // Wir schicken Deine Chef-Identität direkt im sicheren Paket mit
    formData.append('chef_name', chefName);
    formData.append('chef_id', chefID);

    fetch('rme_smilies_handler.php?action=upload_sound', {
        method: 'POST',
        body: formData
    })
    .then(function(res) {
        return res.json();
    })
    .then(function(data) {
        if (data.status === 'success') {
            alert('🚀 Mega! Der neue Sound wurde erfolgreich hochgeladen und eingebrannt!');
            document.getElementById('rme-admin-sound-form').reset();
            window.location.reload(); 
        } else {
            alert('Fehler vom Server-Script: ' + data.message);
        }
    })
    .catch(function(err) {
        console.error("Sound-Upload-Fehler:", err);
        alert('Verbindungsfehler beim Sound-Upload!');
    });
}

// ==========================================================================
// Funktion, die den Sound-Buttons zufällige, helle Schriftfarben verpasst
// ==========================================================================
function rmeBunteSoundButtons() {
    // Holt alle Buttons aus dem neuen Sound-Raster
    var soundButtons = document.querySelectorAll('.rme-sound-compact-grid .rme-sound-btn');
    
    // Eine Liste mit extrem geilen Neon-Farben, die auf Deinem lila Button-Hintergrund perfekt lesbar sind
    var neonFarben = [
        '#00FFFF', // Cyan / Hellblau
        '#00FF00', // Neon-Grün
        '#FFFF00', // Signal-Gelb
        '#FF00FF', // Magenta / Pink
        '#FF9900', // Neon-Orange
        '#00FFCC', // Helles Türkis
        '#FFCC00', // Gold / Gelb
        '#E0E0E0'  // Helles Silberweiß
    ];

    soundButtons.forEach(function(btn) {
        // Wähle eine zufällige Farbe aus der Liste
        var randomColor = neonFarben[Math.floor(Math.random() * neonFarben.length)];
        // Brennt die Farbe direkt als Inline-Style mit Priorität in den Button
        btn.style.setProperty('color', randomColor, 'important');
    });
}

// Sofort ausführen, wenn der Chat im Browser fertig geladen ist
document.addEventListener("DOMContentLoaded", rmeBunteSoundButtons);

// =========================================================================
// RETTUNGSANKER: ERSTELLT DAS TEXTFENSTER FÜR KOPIERBARE FEHLERMELDUNGEN
// =========================================================================
function rmeZeigeKopierbarenFehler(fehlerText) {
    var altesFenster = document.getElementById('rme-debug-error-box');
    if (altesFenster) { altesFenster.remove(); }

    // Erstellt das visuelle Popup-Fenster im lila Dark-Theme
    var box = document.createElement('div');
    box.id = 'rme-debug-error-box';
    box.style.cssText = 'position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); background:#1e1e24; border:2px solid #7B1FA2; padding:20px; z-index:999999; border-radius:8px; box-shadow:0 4px 20px rgba(0,0,0,0.8); width:90%; max-width:500px; font-family:monospace; color:#fff;';
    
    box.innerHTML = '<h3 style="margin-top:0; color:#ff3333; font-family:Arial;">❌ Fehler aufgetreten</h3>' +
                    '<textarea id="rme-error-text-field" style="width:100%; height:150px; background:#141419; color:#00ff00; border:1px solid #7B1FA2; padding:10px; border-radius:4px; font-family:monospace; font-size:12px; resize:none;" readonly>' + fehlerText + '</textarea>' +
                    '<div style="display:flex; justify-content:space-between; margin-top:15px;">' +
                        '<button type="button" id="rme-copy-error-btn" style="background:#7B1FA2; color:#fff; border:none; padding:8px 15px; border-radius:4px; cursor:pointer; font-weight:bold; font-family:Arial;">📋 Text kopieren</button>' +
                        '<button type="button" onclick="document.getElementById(\'rme-debug-error-box\').remove();" style="background:#444; color:#fff; border:none; padding:8px 15px; border-radius:4px; cursor:pointer; font-family:Arial;">Schließen</button>' +
                    '</div>';
    
    document.body.appendChild(box);

    // Die Logik für den Kopieren-Button
    document.getElementById('rme-copy-error-btn').addEventListener('click', function() {
        var textField = document.getElementById('rme-error-text-field');
        textField.select();
        textField.setSelectionRange(0, 99999); // Für Handys
        
        try {
            navigator.clipboard.writeText(textField.value);
            this.innerText = '✅ Kopiert!';
            this.style.background = '#00aa00';
        } catch (err) {
            document.execCommand('copy'); // Fallback für alte Browser
            this.innerText = '✅ Kopiert!';
            this.style.background = '#00aa00';
        }
    });
}

// =========================================================================
// 🛡️ REPARIERTER TEIL 1: INITIATOR MIT KETTEN-TIMER SCHUTZSCHILD
// =========================================================================
window.rmeChatTimeoutId = null; // Globaler Speicher für die Kette ganz oben in der Datei

function reloadChatBoxOnly() {
    if (window.rmeChatTimeoutId) { clearTimeout(window.rmeChatTimeoutId); }

    // 🚨 FIX 1: Wenn wir senden, nicht einfach sterben, sondern in 2 Sekunden neu versuchen!
    if (window.rmeIchSendeGerade === true) { 
        window.rmeChatTimeoutId = setTimeout(reloadChatBoxOnly, 2000);
        return; 
    }

    var aktuellerTab = 'live';
    if (typeof activeChatTab !== 'undefined') {
        aktuellerTab = activeChatTab;
    }
    
    // 🚨 FIX 2: Auch hier den Timer neu anwerfen, bevor abgebrochen wird!
    if (!window.currentActionParam && aktuellerTab !== 'live' && aktuellerTab !== 'archive') { 
        window.rmeChatTimeoutId = setTimeout(reloadChatBoxOnly, 3000);
        return; 
    }
    
    // ... [Ab hier läuft dein restlicher Code von Teil 1 absolut unverändert weiter!] ...

    var actionParam = window.currentActionParam || 'history';
    
    if (aktuellerTab !== 'live' && aktuellerTab !== 'archive') { window.currentActionParam = null; }
    if (aktuellerTab === 'live') { window.currentActionParam = 'history'; }

    var inputFeld = document.getElementById('rme-chat-input');
    var rmeGastName = inputFeld ? inputFeld.getAttribute('data-user') : 'Gast';
  
    var checkProfile = document.getElementById('rme-user-profile-name');
    if ((!rmeGastName || rmeGastName === 'Gast') && checkProfile && checkProfile.innerText.trim() !== "") {
        rmeGastName = checkProfile.innerText;
    }
    
    if (typeof sessionStorage !== 'undefined') {
        if (!rmeGastName || rmeGastName.trim() === "" || rmeGastName === "Gast") {
            rmeGastName = sessionStorage.getItem('rme_privat_gast_name');
        }
    }

    if (!rmeGastName || rmeGastName.trim() === "" || rmeGastName === "Gast") {
        rmeGastName = "Gast_" + Math.floor(1000 + Math.random() * 9000);
        if (typeof sessionStorage !== 'undefined') {
            sessionStorage.setItem('rme_privat_gast_name', rmeGastName);
        }
    }
    
    var saubererName = rmeGastName.replace('[ADMIN]', '').replace('[MODERATOR]', '').replace('[HADMIN]', '').trim();
    var meineEchteID = inputFeld ? parseInt(inputFeld.getAttribute('data-id') || '0') : 0;
    var authParams = '&admin_auth_name=' + encodeURIComponent(saubererName) + '&admin_auth_id=' + meineEchteID;

    // Startet die asynchrone Abfrage absolut staufrei
    fetch('rme_chat_backend.php?action=' + actionParam + authParams + '&live_stream_dj=' + encodeURIComponent(window.rmeAktuellerLiveDJ || 'AutoDJ') + '&t=' + new Date().getTime())
    .then(function(r) { 
        if (!r.ok) { throw new Error("Netzwerkfehler"); }
        return r.text(); 
    })
    .then(function(htmlData) {
        var getrimmteDaten = htmlData.trim();
        if (!getrimmteDaten) { return; }

        if (actionParam === 'history') {
            if (getrimmteDaten.indexOf('<!DOCTYPE') === 0 || getrimmteDaten.indexOf('<html') === 0) { 
                return; 
            }
        }

        var roherText = htmlData.toLowerCase();
        
        var ignoredUsers = JSON.parse(localStorage.getItem('rme_chat_ignored_users')) || [];
        ignoredUsers.forEach(function(badUser) {
            if (htmlData.indexOf("'" + badUser + "'") !== -1 || htmlData.indexOf('"' + badUser + '"') !== -1) {
                htmlData = ""; 
                getrimmteDaten = "";
            }
        });
// =========================================================================

        // =========================================================================
        // 🎆 SCHRITT 4: GLOBALER RETTUNGS-ZÜNDER (KUGELSICHER & LAUTLOS)
        // =========================================================================
        if (typeof htmlData === 'string' && htmlData.indexOf('/firework_command_trigger') !== -1) {
            
            // Zeitsperre gegen Dauerfeuer im RAM des Browsers (8 Sekunden Sperre)
            var rmeZeit = new Date().getTime();
            if (!window.rmeLetztesFeuerwerkZeit || (rmeZeit - window.rmeLetztesFeuerwerkZeit) > 8000) {
                window.rmeLetztesFeuerwerkZeit = rmeZeit;
                
                // Zündet den XL-Dankestext und die Raketen auf dem Bildschirm
                if (typeof zündeDankesFeuerwerk === 'function') {
                    zündeDankesFeuerwerk();
                }
            }
            
            // Absolute Text-Vernichtung: Putzt den unsichtbaren Kommentar restlos weg!
            htmlData = htmlData.replace('<!-- /firework_command_trigger -->', '').replace('/firework_command_trigger', '');
            if (typeof getrimmteDaten !== 'undefined') getrimmteDaten = htmlData.trim();
        }
        // =========================================================================
        
// =========================================================================
// BANNER-ANZEIGE BEI ECHTEM BAN ODER ADMIN-KICK (SCHLANK & REINIGT)
// =========================================================================
if (htmlData.indexOf('[DU_BIST_GEBANNT]') !== -1 || 
    htmlData.indexOf('[DU WURDEST AUS DEM CHAT GEKICKT]') !== -1 || 
    htmlData.indexOf('[DU GEKICKTES SUPPENHUHN]') !== -1 || 
    roherText.indexOf('banned_ip') !== -1 || 
    roherText.indexOf('kicked_by_admin') !== -1) {
    
    if (window.rmeIchBinGekickt === true) { return false; }
    window.rmeIchBinGekickt = true;

    // Timer stoppen
    var maxIntervallId = setInterval(function(){}, 9999);
    for (var i = 1; i < maxIntervallId; i++) { clearInterval(i); clearTimeout(i); }

    var istGebannt = (htmlData.indexOf('[DU_BIST_GEBANNT]') !== -1 || roherText.indexOf('banned_ip') !== -1);
    
    var schildTitel = istGebannt ? "Permanenter Bann!" : "Zugriff verweigert!";
    var schildText = istGebannt ? "Du wurdest permanent aus dem Chat gebannt." : "Du wurdest vom Admin oder Moderator aus dem Chat gekickt.";
    var schildKasten = istGebannt ? "Schreibe einem Admin auf der HP." : "In 2 Minuten kannst du wieder rein.";

    var bannerHTML = `
        <div style="display:flex; align-items:center; justify-content:center; width:100vw; height:100vh; background:#111; position:fixed; top:0; left:0; z-index:9999999;">
            <div style="width:100%; max-width:480px; background:#8b0000; border:4px solid #00ff00; border-radius:14px; text-align:center; padding:35px 20px; font-family:Arial; color:#fff;">
                <h2 style="margin-top:0;">${schildTitel}</h2>
                <p style="font-size:15px; line-height:1.5;">${schildText}</p>
                <div style="background:#ff0; color:#000; padding:10px 20px; border-radius:8px; font-weight:bold; margin-bottom:20px;">${schildKasten}</div>
            </div>
        </div>`;

    window.reloadChatBoxOnly = function() { return false; };
    window.loadOnlineList = function() { return false; };

    document.body.innerHTML = bannerHTML;
    return false; 
}
// =========================================================================

			
        var zielFenster = document.getElementById('rme-chat-window');
        if (zielFenster && getrimmteDaten !== "") {
            var amBodenScollen = (zielFenster.scrollTop + zielFenster.clientHeight >= zielFenster.scrollHeight - 60);
            
            // HIER IST DEIN BEWÄHRTER ALTER SICHERHEITS-FILTER AUS TEIL 2!
            if (htmlData.indexOf('rme-chat-row') !== -1 || 
                htmlData.indexOf('chat-live-msg-text') !== -1 || 
                htmlData.indexOf('no-users') !== -1 || 
                htmlData.indexOf('archive-entry') !== -1 || 
                htmlData.indexOf('list-entry') !== -1 || 
                htmlData.indexOf('chat-user-item') !== -1 ||
                htmlData.indexOf('ist leer') !== -1 ||
                htmlData.indexOf('keine Nachrichten') !== -1 ||
                htmlData.indexOf('rme-admin-list-container') !== -1 || 
                htmlData.indexOf('registrierten Chat-User vorhanden') !== -1) { 

                // 4. Erst JETZT geht der saubere Hörer-Text komplett ohne Fragebox ins Chatfenster!
                var korrigiertesHTML = htmlData;
                zielFenster.innerHTML = korrigiertesHTML;
                // =========================================================================


// =========================================================================
// 🛡️ UNABHÄNGIGER AFK- & BANNER-DETEKTOR (SERVER-SAFE VERSION)
// =========================================================================
window.rmeAfkTimerId = null;

function rmeStarteAfkDetektor() {
    // Sicherheitsanker: Falls schon ein Timer läuft, erst löschen
    if (window.rmeAfkTimerId) {
        clearInterval(window.rmeAfkTimerId);
    }

    window.rmeAfkTimerId = setInterval(function() {
        // Falls das Banner schon da ist, machen wir gar nichts mehr
        if (window.rmeIchBinGekickt === true) {
            clearInterval(window.rmeAfkTimerId);
            return;
        }

        var inputFeld = document.getElementById('rme-chat-input');
        var rmeGastName = inputFeld ? inputFeld.getAttribute('data-user') : 'Gast';
        if (!rmeGastName || rmeGastName === 'Gast') rmeGastName = sessionStorage.getItem('rme_privat_gast_name') || 'Gast';
        var saubererName = rmeGastName.replace('[ADMIN]', '').replace('[MODERATOR]', '').trim();
        
        // Abfrage ans Backend
        fetch('rme_chat_backend.php?action=online_list&admin_auth_name=' + encodeURIComponent(saubererName) + '&t=' + Date.now())
        .then(function(r) { return r.text(); })
        .then(function(daten) {
            // Wenn das Backend meldet, dass wir gekickt sind:
            if (daten.indexOf('[DU_WURDEST_AUTO_GEKICKT_WEGEN_INAKTIVITAET]') !== -1) {
                window.rmeIchBinGekickt = true;

                // 🚨 RADIKALER INTERVALL-STOPP: Killt alle Haupt-Schleifen des Chats sofort!
                clearInterval(window.rmeAfkTimerId);
                if (window.chatLadeIntervall) { clearInterval(window.chatLadeIntervall); }
                if (window.rmeMainOnlineTimer) { clearInterval(window.rmeMainOnlineTimer); }
                if (window.onlineListenIntervall) { clearInterval(window.onlineListenIntervall); }
                if (window.chatLadeIntervall) { clearInterval(window.chatLadeIntervall); }

                // 🚨 FUNKTIONS-KASTRIERUNG: Überschreibt die AJAX-Funktionen mit "Nichts-Tun"
                window.reloadChatBoxOnly = function() { return false; };
                window.loadOnlineList = function() { return false; };
                window.rmeTttSpion = function() { return false; };
                window.rmeV4gSpion = function() { return false; };

                // Zeichnet das orangefarbene Banner über den gesamten Bildschirm
                document.body.innerHTML = `
                    <div style="display:flex; align-items:center; justify-content:center; width:100vw; height:100vh; background:#111; position:fixed; top:0; left:0; z-index:9999999;">
                        <div style="width:100%; max-width:480px; background:#d35400; border:4px solid #ffaa00; border-radius:14px; text-align:center; padding:35px 20px; font-family:Arial; color:#fff;">
                            <h2 style="margin-top:0;">Hinweis zur Inaktivität!</h2>
                            <p style="font-size:15px; line-height:1.5;">Du wurdest nach 20 Minuten aus dem Chat ausgetragen, da du offensichtlich nicht da warst.</p>
                            <div style="background:#ff0; color:#000; padding:10px 20px; border-radius:8px; font-weight:bold; margin-bottom:20px;">Komm einfach wieder rein wenn du Online bist.</div>
                            <button type="button" onclick="document.cookie='rme_saved_kick_time=;path=/;expires=Thu, 01 Jan 1970 00:00:00 UTC;'; sessionStorage.clear(); window.location.href=window.location.pathname+'?reconnect='+Math.random();" style="background:#fff; color:#d35400; border:none; padding:10px 25px; font-size:14px; font-weight:bold; border-radius:6px; cursor:pointer; text-transform:uppercase;">↩️ Neu verbinden</button>
                        </div>
                    </div>`;
            }
        }).catch(function(){});
    }, 5000); // Prüft alle 5 Sekunden felsenfest im Hintergrund
}

// Startet den Detektor sauber beim Laden der Seite
rmeStarteAfkDetektor();

  
// =========================================================================
// BÄNDIGUNG DER SMILEY-ENGINE & PRIVAT-FILTER: PFEILSCHNELL & ABSOLUT SICHER
// =========================================================================
try {
    if (typeof window.rmeAlleSmilies === 'undefined') {
        window.rmeAlleSmilies = {};
        var popupBilder = document.querySelectorAll('#rme-smiley-popup img');
        popupBilder.forEach(function(img) {
            var kuerzel = img.getAttribute('title');
            var srcPfad = img.getAttribute('src');
            if (kuerzel && srcPfad) {
                window.rmeAlleSmilies[kuerzel] = srcPfad;
            }
        });
    }

    // Sortierung nach Länge schützt längere Kürzel vor Fragmentierung
    var sortierteKuerzel = Object.keys(window.rmeAlleSmilies).sort(function(a, b) {
        return b.length - a.length;
    });

    var tempDiv = document.createElement('div');
    tempDiv.innerHTML = htmlData;

    // =========================================================================
    // 1. SCHRITT: PRIVAT-MODUS-FILTER (RADIKALER SMILEY-GERADESTAND GEGEN ALL-STYLES)
    // =========================================================================
    var chatZeilenText = tempDiv.querySelectorAll('.chat-live-msg-text, [class*="msg-text"], .rme-chat-msg-text');
    chatZeilenText.forEach(function(msgNode) {
        var roherInhaltHTML = msgNode.innerHTML;

        // Die Regex isoliert die Format-Tags ($1), den Empfänger ($2) und den Text ($3)
        var masterFluesterRegex = /^([\s\S]*?)\/w\s+([a-zA-Z0-9_\-]+)\s+([\s\S]*)/i;

        if (masterFluesterRegex.test(roherInhaltHTML)) {
            var treffer = roherInhaltHTML.match(masterFluesterRegex);
            
            if (treffer && treffer.length >= 4) {
                var geoeffneteTags = treffer[1];   // Formatierung (Größe, Farbe, B, U, I)
                var fluesterUser   = treffer[2];   // Empfänger (z.B. Pauerhexe)
                var echterText     = treffer[3];   // Nachricht mit den Smileys
                
                // Wir berechnen die Schließ-Tags für die geöffnete CSS-Formatierung
                var schliessungsTags = "";
                if (geoeffneteTags.indexOf('<b') !== -1 || geoeffneteTags.indexOf('[b]') !== -1) schliessungsTags += "</b>";
                if (geoeffneteTags.indexOf('<em') !== -1 || geoeffneteTags.indexOf('[i]') !== -1) schliessungsTags += "</em>";
                if (geoeffneteTags.indexOf('<i ') !== -1 || geoeffneteTags.indexOf('<i>') !== -1) schliessungsTags += "</i>";
                if (geoeffneteTags.indexOf('<u') !== -1 || geoeffneteTags.indexOf('[u]') !== -1) schliessungsTags += "</u>";
                if (geoeffneteTags.indexOf('<span') !== -1) schliessungsTags += "</span>";

                // Das Label fixiert auf saubere, unbeeinflusste 16px ohne Krümmung
                var labelHTML = '✉️ <span class="rme-whisper-chat-glow" style="font-size: 16px !important; font-style: normal !important; font-weight: normal !important; text-decoration: none !important; display: inline-block; vertical-align: middle;">[Flüstern an ' + fluesterUser + ']:</span> ';
                
                // Wir setzen die Nachricht im DOM sauber zusammen
                msgNode.innerHTML = labelHTML + geoeffneteTags + echterText + schliessungsTags;
                
                // 🔥 DER RETTUNGS-SPION: 
                // Er durchsucht die Zeile nach Bildern (System, FTP, MySQL) und verpasst 
                // ihnen eine eiserne Stil-Sperre gegen Kursiv-Verzerrung und Unterstreichungen!
                setTimeout(function() {
                    var allImgs = msgNode.querySelectorAll('img, .rme-chat-stream-smiley, .rme-gif-item');
                    allImgs.forEach(function(imgNode) {
                        imgNode.style.setProperty('font-style', 'normal', 'important');
                        imgNode.style.setProperty('font-weight', 'normal', 'important');
                        imgNode.style.setProperty('text-decoration', 'none', 'important');
                        
                        // Falls der Browser eine Text-Unterstreichung stur auf das Bild vererbt, 
                        // kapseln wir das Bild im RAM in ein schützendes, neutrales Element ein!
                        if (imgNode.parentNode && (!imgNode.parentNode.classList || !imgNode.parentNode.classList.contains('rme-img-safe-shield'))) {
                            var safeWrapper = document.createElement('span');
                            safeWrapper.className = 'rme-img-safe-shield';
                            safeWrapper.style.setProperty('font-style', 'normal', 'important');
                            safeWrapper.style.setProperty('text-decoration', 'none', 'important');
                            safeWrapper.style.setProperty('display', 'inline-block', 'important');
                            safeWrapper.style.setProperty('vertical-align', 'middle', 'important');
                            
                            imgNode.parentNode.insertBefore(safeWrapper, imgNode);
                            safeWrapper.appendChild(imgNode);
                        }
                    });
                }, 15);
            }
        }
    });
    // =========================================================================


    // =========================================================================
    // 2. SCHRITT: DETEKTIEREN UND SMILEYS ERSETZEN (UNZERSTÖRBARER MATCH-FIX)
    // =========================================================================
    var textElemente = tempDiv.querySelectorAll('.chat-live-msg-text, [class*="msg-text"], .rme-chat-msg-text');
    var elementeZuVerarbeiten = textElemente.length > 0 ? textElemente : [tempDiv];

    elementeZuVerarbeiten.forEach(function(el) {
        var textInhalt = el.innerHTML;
        
        sortierteKuerzel.forEach(function(kuerzel) {
            if (textInhalt.indexOf(kuerzel) !== -1) {
                var rmeSoundDateiPfad = String(window.rmeAlleSmilies[kuerzel]);
                var istBravoGif = (kuerzel === ':bravo:');
                
                var rmeBreite = istBravoGif ? "4.5em" : "1.4em";
                var rmeHoehe  = "1.4em";
                var rmeFit    = istBravoGif ? "contain" : "scale-down";
                
                var smileyHTML = '<img src="' + rmeSoundDateiPfad + '" title="' + kuerzel + '" class="rme-gif-item rme-chat-stream-smiley" style="height: ' + rmeHoehe + ' !important; min-height: ' + rmeHoehe + ' !important; max-height: ' + rmeHoehe + ' !important; width: ' + rmeBreite + ' !important; min-width: ' + rmeBreite + ' !important; max-width: ' + rmeBreite + ' !important; object-fit: ' + rmeFit + ' !important; vertical-align: middle; display: inline-block; margin: 0 4px; border: none !important; box-shadow: none !important; background: transparent !important; padding: 0 !important;">';
                
                // Maskiert Sonderzeichen im Kürzel für die Regex
                var maskiertesKuerzel = kuerzel.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
                
                // 🎯 100% ABSTURZSICHERER REGEX: Findet HTML-Tags ODER das Kürzel
                var globalerSmileyRegex = new RegExp('(<[^>]+>)|' + maskiertesKuerzel, 'g');
                
                // Wir prüfen stur das Zeichen: Fängt es mit < an, ist es ein Tag -> Unberührt lassen!
                textInhalt = textInhalt.replace(globalerSmileyRegex, function(match) {
                    if (match.charAt(0) === '<') {
                        return match; 
                    } else {
                        return smileyHTML; 
                    }
                });
            }
        });
        el.innerHTML = textInhalt;
    });

    korrigiertesHTML = tempDiv.innerHTML;


} catch(smileyErr) {
    console.error("Fehler in der optimierten Smiley- & Flüster-Engine:", smileyErr);
    korrigiertesHTML = htmlData; 
}
// =========================================================================

// =========================================================================
// NEUER SICHERHEITS-RIEGEL: ENTFERNT DAS HARTNÄCKIGE "> HINTER SMILEYS
// =========================================================================
try {
    // Putzt verwaiste HTML-Reste exakt hinter unseren gestreamten Smileys weg
    korrigiertesHTML = korrigiertesHTML.replace(/(class="rme-gif-item rme-chat-stream-smiley"[^>]*>)("&gt;|">)/gi, '$1');
    korrigiertesHTML = korrigiertesHTML.replace(/(class="rme-gif-item rme-chat-stream-smiley"[^>]*>)"/gi, '$1');
} catch(filterErr) {
    console.error("Smiley-Reste-Filter Fehler:", filterErr);
}
// =========================================================================

// HIER IST DEIN EXAKTER ORIGINAL-CODE (1:1 AUS DEINEM TTT-BEISPIEL KOPIERT):
zielFenster.innerHTML = korrigiertesHTML;
// REPARIERT: Gibt dem Browser 50ms Zeit, um den Text im RAM zu platzieren, bevor wir prüfen!
setTimeout(function() {
    rmePruefeUndSpieleSound(true);
}, 50);
} // Schließt das große HTML-Filter-If

var tabCheck = 'live';
if (typeof activeChatTab !== 'undefined') { tabCheck = activeChatTab; }
if (amBodenScollen && tabCheck === 'live') { 
    zielFenster.scrollTop = zielFenster.scrollHeight; 
}

        }
    }).catch(function(e) { 
        console.error("Mobiles Loop-Fehler-Auffangbecken:", e); 
    });
}

// Ganz oben AUSSERHALB von Funktionen deklarieren (falls nicht schon vorhanden)
window.rmeLetzterOnlineCheck = 0;

function loadOnlineList() {
    if (window.rmeIchBinGekickt === true) { return; }

    // 🔥 DER ABSOLUTE ABSTURZ-RIEGEL (Server-Schutz):
    // Wenn der letzte Aufruf weniger als 3 Sekunden her ist, blocken wir die Anfrage KNALLHART ab!
    var jetzt = Date.now();
    if (jetzt - window.rmeLetzterOnlineCheck < 3000) {
        return;
    }
    window.rmeLetzterOnlineCheck = jetzt;

    var inputFeld = document.getElementById('rme-chat-input');
    var rmeGastName = inputFeld ? inputFeld.getAttribute('data-user') : 'Gast';
    
    var checkProfile = document.getElementById('rme-user-profile-name');
    if ((!rmeGastName || rmeGastName === 'Gast') && checkProfile && checkProfile.innerText.trim() !== "") {
        rmeGastName = checkProfile.innerText;
    }
    
    if (typeof sessionStorage !== 'undefined') {
        if (!rmeGastName || rmeGastName.trim() === "" || rmeGastName === "Gast") {
            rmeGastName = sessionStorage.getItem('rme_privat_gast_onlinelist_name');
        }
    }

    if (!rmeGastName || rmeGastName.trim() === "" || rmeGastName === "Gast") {
        rmeGastName = "Gast_" + Math.floor(1000 + Math.random() * 9000);
        if (typeof sessionStorage !== 'undefined') {
            sessionStorage.setItem('rme_privat_gast_onlinelist_name', rmeGastName);
        }
    }
    
    var saubererName = rmeGastName.replace('[ADMIN]', '').replace('[MODERATOR]', '').replace('[HADMIN]', '').trim();
    var meineEchteID = inputFeld ? parseInt(inputFeld.getAttribute('data-id') || '0') : 0;
    var authParams = '&admin_auth_name=' + encodeURIComponent(saubererName) + '&admin_auth_id=' + meineEchteID;
      
    fetch('rme_chat_backend.php?action=online_list' + authParams + '&live_stream_dj=' + encodeURIComponent(window.rmeAktuellerLiveDJ || 'AutoDJ') + '&t=' + new Date().getTime())
    .then(function(r) { 
        if (!r.ok) { throw new Error("Netzwerkfehler Onlineliste"); }
        return r.text(); 
    })
    .then(function(d) { 
        // =========================================================================
        // 🎯 1. DIE REPARIERTE KICK-, BAN- & AFK-BANNER-WEICHE
        // =========================================================================
        var roherText = d.toLowerCase();
        if (d.indexOf('[DU WURDEST AUS DEM CHAT GEKICKT]') !== -1 || 
            d.indexOf('[DU_BIST_GEBANNT]') !== -1 || 
            d.indexOf('[DU_WURDEST_AUTO_GEKICKT_WEGEN_INAKTIVITAET]') !== -1 || 
            roherText.indexOf('banned_ip') !== -1 || 
            roherText.indexOf('kicked_by_admin') !== -1) {
            
            window.rmeIchBinGekickt = true;

            // Stoppt gezielt nur den Onlinelisten-Timer im Moment des Kicks
            if (window.rmeMainOnlineTimer) { clearInterval(window.rmeMainOnlineTimer); }
            if (window.rmeAfkTimerId) { clearInterval(window.rmeAfkTimerId); }

            var istGebannt = (d.indexOf('[DU_BIST_GEBANNT]') !== -1 || roherText.indexOf('banned_ip') !== -1);
            var istAutoKick = (d.indexOf('[DU_WURDEST_AUTO_GEKICKT_WEGEN_INAKTIVITAET]') !== -1);
            
            var schildTitel = istGebannt ? "Permanenter Bann!" : (istAutoKick ? "Hinweis zur Inaktivität!" : "Zugriff verweigert!");
            var schildText = istGebannt ? "Du wurdest permanent aus dem Chat gebannt." : (istAutoKick ? "Du wurdest nach 20 Minuten aus dem Chat ausgetragen, da du offensichtlich nicht da warst." : "Du wurdest vom Admin oder Moderator gekicked.");
            var schildKasten = istGebannt ? "Schreibe einem Admin auf der HP." : (istAutoKick ? "Komm einfach wieder rein wenn du Online bist." : "In 2 Minuten kannst du wieder rein");

            var kastenFarbe = istAutoKick ? "#d35400" : "#8b0000";
            var rahmenFarbe = istAutoKick ? "#ffaa00" : "#00ff00";

            
            // Verwende absolut syntaxsichere Backticks für den Banner-Zusammenbau
            var bannerHTML = `
                <div style="display:flex; align-items:center; justify-content:center; width:100vw; height:100vh; background:#111; position:fixed; top:0; left:0; z-index:9999999;">
                    <div style="width:100%; max-width:480px; background:${kastenFarbe}; border:4px solid ${rahmenFarbe}; border-radius:14px; text-align:center; padding:35px 20px; font-family:Arial; color:#fff;">
                        <h2 style="margin-top:0;">${schildTitel}</h2>
                        <p style="font-size:15px; line-height:1.5;">${schildText}</p>
                        <div style="background:#ff0; color:#000; padding:10px 20px; border-radius:8px; font-weight:bold; margin-bottom:20px;">${schildKasten}</div>
            `;

            if (istAutoKick) {
                document.cookie = "rme_saved_kick_time=" + Math.floor(Date.now() / 1000) + "; path=/; max-age=3600";
                bannerHTML += `<button type="button" id="rme-btn-reconnect-action-list" style="background:#fff; color:#d35400; border:none; padding:10px 25px; font-size:14px; font-weight:bold; border-radius:6px; cursor:pointer; text-transform:uppercase;">↩️ Neu verbinden</button>`;
            }
            bannerHTML += `</div></div>`;

            // Player kappen
            var streamPlayerIframe = document.querySelector('.chat-player-iframe') || document.getElementsByTagName('iframe')[0];
            if (streamPlayerIframe) {
                try { streamPlayerIframe.src = "about:blank"; streamPlayerIframe.remove(); } catch(e) {}
            }

            // HTML über die komplette Seite werfen
            document.body.innerHTML = bannerHTML;

            // Reconnect Event-Handler verknüpfen
            var btnReconnect = document.getElementById('rme-btn-reconnect-action-list');
            if (btnReconnect) {
                btnReconnect.addEventListener('click', function() {
                    window.rmeIchBinGekickt = false;
                    document.cookie = "rme_saved_kick_time=; path=/; expires=Thu, 01 Jan 1970 00:00:00 UTC;";
                    if (typeof sessionStorage !== 'undefined') { sessionStorage.clear(); }
                    window.location.href = window.location.pathname + '?reconnect=' + Math.random();
                });
            }
            return; 
        }
        // =========================================================================
        
        var o = document.getElementById('chat-online-list'); 
        if (o) { 
            var korrigierteUserListe = d;
            try {
                if (korrigierteUserListe.indexOf('&#x1F1') !== -1) {
                    korrigierteUserListe = korrigierteUserListe.replace(/(&#x1F1[0-9A-Z]{2};)+/gi, function(match) {
                        return "<span class='flagge'> " + match + "</span>";
                    });
                }
            } catch(err) { console.log("Onlinelisten Flaggen-Fehler abgefangen"); }
            o.innerHTML = korrigierteUserListe; 
        }

        var syncFeld = document.getElementById('rme-live-sync-gastname');
        if (syncFeld && syncFeld.value) {
            var neuerFesterName = syncFeld.value.trim();
            if (neuerFesterName !== "") {
                if (checkProfile) checkProfile.innerText = neuerFesterName.replace('_Gast', '');
                if (inputFeld) inputFeld.setAttribute('data-user', neuerFesterName);
                window.rmeAktuellerUserAktionsName = neuerFesterName;
            }
        }
        
        if (typeof updateModUserDropdown === 'function') {
            updateModUserDropdown();
        }
    }).catch(function(e) { 
        console.error("Sidebar Loop Fehler abgefangen:", e); 
    });
}

// =========================================================================
// updateModUserDropdown (HIGH-PRECISION FILTRATION!)
// =========================================================================
function updateModUserDropdown() {
    var modSelect = document.getElementById('rme-mod-user-select'); 
    if (!modSelect) return;
    
    var sidebar = document.getElementById('chat-online-list');
    if (!sidebar) return;
    
    var aktuelleAuswahl = modSelect.value;
    modSelect.innerHTML = '<option value="">👤 User wählen...</option>';
    
    var groupMods = document.createElement('optgroup'); groupMods.label = "⚡ Moderatoren & Team";
    var groupUser = document.createElement('optgroup'); groupUser.label = "👥 Hörer & Gäste";
    
    var userEintraege = sidebar.querySelectorAll('.rme-rgb-username, .rme-rgb-hadmin, .rme-moderator-username, .rme-user-logged, .rme-name-guest, .rme-name-user-list, .rme-gast-id-sidebar');
    var bereitsHinzugefuegt = {};
    
    userEintraege.forEach(function(eintrag) {
        var roherName = eintrag.textContent || eintrag.innerText || "";
        roherName = roherName.trim();
        
        // 1. Porentiefe Reinigung von Text-Badges und Suffixen
        var saubererName = roherName.replace('_Gast', '').replace('_CU', '')
                                     .replace('[ADMIN]', '').replace('[MODERATOR]', '')
                                     .replace('[HADMIN]', '').replace('[MOD]', '')
                                     .replace('👤', '').replace('⚡', '').replace('●', '').trim();
        var testNameLow = saubererName.toLowerCase();
        
        if (saubererName === "" || saubererName.length < 3 || testNameLow === "kick" || testNameLow === "bann") { return; }
        if (bereitsHinzugefuegt[testNameLow]) return;
        
        // 2. RADIKALER ADMIN- UND CHEF-FILTER: Schmeißt dich und JEDEN Admin (rme-rgb-username / rme-rgb-hadmin) sofort raus!
        if (testNameLow === 'dj-tomjac' || 
            testNameLow === 'tomjac' || 
            testNameLow.indexOf('admin') !== -1 || 
            eintrag.classList.contains('rme-rgb-hadmin') || 
            eintrag.classList.contains('rme-rgb-username')) { 
            return; 
        }
        
        bereitsHinzugefuegt[testNameLow] = true;
        
        // 3. Moderatoren-Erkennung (Nur noch für die rme-moderator-username Klasse oder dj- Kürzel)
        var istEinModerator = (testNameLow.indexOf('moderator') !== -1 || testNameLow.indexOf('dj-') !== -1 || eintrag.classList.contains('rme-moderator-username'));
        
        var opt = document.createElement('option'); 
        opt.value = saubererName;
        opt.innerText = (istEinModerator ? "⚡ " : "👤 ") + saubererName;
        
        if (opt.value === aktuelleAuswahl) { opt.selected = true; }
        if (istEinModerator) { groupMods.appendChild(opt); } else { groupUser.appendChild(opt); }
    });
    
    if (groupMods.children.length > 0) { modSelect.appendChild(groupMods); }
    if (groupUser.children.length > 0) { modSelect.appendChild(groupUser); }
}

// =========================================================================
// NATIVES DEKO-BUTTON SYSTEM
// =========================================================================
function schalteDekoButtonScharf(btnId, hiddenId) {
    var btn = document.getElementById(btnId); var hid = document.getElementById(hiddenId);
    if (btn && hid && rmeChatInput) {
        btn.addEventListener('click', function() {
            if (hid.value === '0') {
                hid.value = '1'; btn.style.background = '#ff5722'; btn.style.borderColor = '#333'; btn.style.color = '#000000';
            } else {
                hid.value = '0'; btn.style.background = '#1a1a1a'; btn.style.borderColor = '#333'; btn.style.color = '#fff';
            }
            rmeChatInput.focus();
        });
    }
}
schalteDekoButtonScharf('rme-btn-bold', 'rme-bold-hidden');
schalteDekoButtonScharf('rme-btn-italic', 'rme-italic-hidden');
schalteDekoButtonScharf('rme-btn-underline', 'rme-underline-hidden');

if (rmeFontPicker) { rmeFontPicker.addEventListener('change', function() { if(rmeFontHidden) rmeFontHidden.value = this.value; rmeChatInput.focus(); }); }
if (rmeSizePicker) { rmeSizePicker.addEventListener('change', function() { if(rmeSizeHidden) rmeSizeHidden.value = this.value; rmeChatInput.focus(); }); }
if (rmeColorPicker) { rmeColorPicker.addEventListener('change', function() { if(rmeColorHidden) rmeColorHidden.value = this.value; rmeChatInput.focus(); }); }


// =========================================================================
// UNBLOCKIERBARE ABSENDE-FUNKTION MIT VORSCHAU (TEIL 1 VON 2)
// =========================================================================
function fuehreRmeChatAbsendungAus() {
    // Holt das Eingabefeld absolut krisensicher live aus dem Dokument
    var echtesInputFeld = document.getElementById('rme-chat-input') || document.querySelector('.rme-chat-input-field');
    if (!echtesInputFeld) return;
    
    if (window.rmeIchSendeGerade === true) return;
    
    // 1. Die Engine liest wie gewohnt das Feld aus
    var nachrichtenText = echtesInputFeld.value.trim();
    if (nachrichtenText === "") return;

    nachrichtenText = nachrichtenText.replace(/<3/g, "❤️");

    nachrichtenText = nachrichtenText.replace(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g, function(match) {
        var lead = match.charCodeAt(0); var trail = match.charCodeAt(1);
        return '&#x' + ((lead - 0xD800) * 0x400 + (trail - 0xDC00) + 0x10000).toString(16).toUpperCase() + ';';
    });

    var fontVal  = typeof rmeFontPicker !== 'undefined' && rmeFontPicker ? rmeFontPicker.value.trim() : '';
    var sizeVal  = typeof rmeSizePicker !== 'undefined' && rmeSizePicker ? rmeSizePicker.value.trim() : '';
    var colorVal = typeof rmeColorPicker !== 'undefined' && rmeColorPicker ? rmeColorPicker.value.toLowerCase() : '#ffffff';
    
    var boldVal  = document.getElementById('rme-bold-hidden') ? document.getElementById('rme-bold-hidden').value : '0';
    var italicVal = document.getElementById('rme-italic-hidden') ? document.getElementById('rme-italic-hidden').value : '0';
    var underlineVal = document.getElementById('rme-underline-hidden') ? document.getElementById('rme-underline-hidden').value : '0';

    var extraStyles = "";
    if (fontVal !== '') { extraStyles += "font-family:" + fontVal + ";"; }
    if (sizeVal !== '') { extraStyles += "font-size:" + parseInt(sizeVal) + "px;"; }
    if (colorVal !== '#ffffff') { extraStyles += "color:" + colorVal + ";"; }
    if (boldVal === '1') { extraStyles += "font-weight:bold;"; }
    if (italicVal === '1') { extraStyles += "font-style:italic;"; }
    if (underlineVal === '1') { extraStyles += "text-decoration:underline;"; }

    // Synchronisiert den Text felsenfest für die Datenbank-Zusammenstellung
    var datenbankText = nachrichtenText;
    if (extraStyles !== "") {
        datenbankText = "[style=" + extraStyles + "]" + nachrichtenText + "[/style]";
    }
    
    // Leert den Zwischenspeicher erst nach der Verarbeitung
    window.rmeAktionsText = "";


    // =========================================================================
    // SOFORT-VORSCHAU: ZEICHNET DEN TEXT SOFORT WIEDER AUF DEINEN BILDSCHIRM
    // =========================================================================
    var cWin = document.getElementById('rme-chat-window');
    if (cWin && typeof activeChatTab !== 'undefined' && activeChatTab === 'live') {
        var jetzt = new Date();
        var tag = ("0" + jetzt.getDate()).slice(-2);
        var monat = ("0" + (jetzt.getMonth() + 1)).slice(-2);
        var jahr = jetzt.getFullYear();
        var stunden = ("0" + jetzt.getHours()).slice(-2);
        var minuten = ("0" + jetzt.getMinutes()).slice(-2);
        var zeitText = tag + "." + monat + "." + jahr + " " + stunden + ":" + minuten;
        
        var meinName = echtesInputFeld.getAttribute('data-user') || 'Gast';
        var checkProfileElement = document.querySelector('.rme-user-panel-name');
        if ((meinName === 'Gast' || meinName === '') && checkProfileElement) {
            var profilText = checkProfileElement.textContent || checkProfileElement.innerText || '';
            if (profilText.trim() !== '') { meinName = profilText.trim(); }
        }
        
        var punkteGruppenString = String(echtesInputFeld.getAttribute('data-level') || '');
        var saubererMeinName = meinName.replace('[ADMIN]', '').replace('[MODERATOR]', '').replace('[HADMIN]', '').replace('_CU', '').replace('_Gast', '').trim();
        
        var istEinGast = (saubererMeinName.startsWith("Gast_") || meinName.toLowerCase().indexOf('gast') !== -1);
        var istChatUserFix = (meinName.indexOf('_CU') !== -1 || saubererMeinName.toLowerCase() === 'hammerhai66');
        if (istChatUserFix) { istEinGast = false; }
        
        // 🎯 FIX: Exakte Rang-Prüfungen mit knallharter Sperre für Chat-User (_CU) beim lokalen Senden!
        var testNameLow = saubererMeinName.toLowerCase();
        
        // Wenn es ein Chat-User (_CU) ist, darf er NIEMALS Team-Rechte in der Vorschau bekommen!
        var istHAdmin = (!istEinGast && !istChatUserFix && (saubererMeinName === 'DJ-Tomjac' || testNameLow === 'tomjac' || meinName.indexOf('[HADMIN]') !== -1));
        var istAdmin  = (!istEinGast && !istChatUserFix && !istHAdmin && (meinName.indexOf('[ADMIN]') !== -1 || punkteGruppenString.indexOf('103') !== -1 || punkteGruppenString.indexOf('.1.') !== -1));
        var istMod    = (!istEinGast && !istChatUserFix && !istHAdmin && !istAdmin && (punkteGruppenString.indexOf('101') !== -1 || punkteGruppenString.indexOf('.3.') !== -1 || meinName.indexOf('[MODERATOR]') !== -1));
        
        var meineKlasse = 'rme-user-logged'; // Standard: Blau
        if (istHAdmin) { meineKlasse = 'rme-rgb-hadmin'; } 
        else if (istAdmin) { meineKlasse = 'rme-name-admin'; } 
        else if (istMod) { meineKlasse = 'rme-moderator-username'; } 
        else if (istEinGast) { meineKlasse = 'rme-name-guest'; }


        var dekoText = nachrichtenText;
       // =========================================================================
        // REPARIERT: DYNAMISCHE SOFORT-VORSCHAU FÜR REINE DATABASE-BLOB SMILEYS
        // =========================================================================
        // Wir holen uns die sortierte Liste deiner Kürzel, um Fragmentierung zu vermeiden
        var rmeVorschauListe = window.rmeAlleSmilies || {};
        var sortierteVorschauKuerzel = Object.keys(rmeVorschauListe).sort(function(a, b) {
            return b.length - a.length;
        });

        // Wir jagen das getippte Wort durch alle existierenden Datenbank-Kürzel
        sortierteVorschauKuerzel.forEach(function(kuerzel) {
            if (dekoText.indexOf(kuerzel) !== -1) {
                var datenbankBildPfad = rmeVorschauListe[kuerzel];
                
                // Wir bauen das exakte Bild-Tag mit der Streaming-URL deines Handlers
                var vorschauSmileyHTML = "<img src='" + datenbankBildPfad + "' title='" + kuerzel + "' class='rme-gif-item rme-chat-stream-smiley' style='height:1.1em; width:auto; display:inline-block; vertical-align:middle; margin: 0 4px; border: none !important;'>";
                
                // Ersetzt das Kürzel absolut sauber im Vorschau-Text
                dekoText = dekoText.split(kuerzel).join(vorschauSmileyHTML);
            }
        });
        // =========================================================================

    // Ab hier läuft Dein originaler Code unverändert weiter:
    var localStyles = "";
    if (fontVal !== '') { localStyles += "font-family:" + fontVal + ";"; }
    if (sizeVal !== '') { localStyles += "font-size:" + parseInt(sizeVal) + "px !important;"; }
    if (colorVal !== '#ffffff') { localStyles += "color:" + colorVal + ";"; }
    if (boldVal === '1') { localStyles += "font-weight:bold;"; }
    if (italicVal === '1') { localStyles += "font-style:italic;"; }
    
    // 🛠️ HIER WAR DER FEHLER: Komplett repariert und sauber formatiert!
    if (underlineVal === '1') { localStyles += "text-decoration:underline;"; }
    
    if (localStyles !== "") { dekoText = '<span style="' + localStyles + '">' + dekoText + '</span>'; }

    cWin.innerHTML += "<div class='rme-chat-row'><span class='rme-neon-time'>[" + zeitText + "]</span> <span class='" + meineKlasse + "'>" + saubererMeinName + ":</span> <span class='chat-live-msg-text'> " + dekoText + "</span></div>";
    cWin.scrollTop = cWin.scrollHeight;
}

    // =========================================================================
    // VERSAND AN DIE GLOBALE CHAT-DATEI (TEIL 1 VON 2)
    // =========================================================================
    var formData = new FormData();
    formData.append('rme_message', datenbankText);
    formData.append('msg', datenbankText);
    formData.append('message', datenbankText);
    formData.append('rme_send_msg', '1');

    var rmeSendeNameAnBackend = rmeChatInput.getAttribute('data-user') || '';
    var meineEchteSendeID = parseInt(rmeChatInput.getAttribute('data-id') || '0');

    if (rmeSendeNameAnBackend === '' || rmeSendeNameAnBackend === 'Gast') {
        var profilSidebarName = document.querySelector('.rme-user-panel-name');
        if (profilSidebarName) { rmeSendeNameAnBackend = profilSidebarName.textContent || profilSidebarName.innerText || ''; }
    }
    if (rmeSendeNameAnBackend.trim() !== '') {
        formData.append('admin_auth_name', rmeSendeNameAnBackend.trim());
    }

    if (fontVal !== '') formData.append('rme_font', fontVal);
    if (sizeVal !== '') formData.append('rme_size', sizeVal);
    if (colorVal !== '#ffffff') formData.append('rme_color', colorVal);
    if (boldVal === '1') formData.append('rme_bold', boldVal);
    if (italicVal === '1') formData.append('rme_italic', italicVal);
    if (underlineVal === '1') formData.append('rme_underline', underlineVal);

    rmeChatInput.value = '';
    window.rmeIchSendeGerade = true;
    
    // REPARIERT: Sendet die Daten nun an die offizielle rme_chat_backend.php, die das Lese-Skript permanent abfragt!
    // =========================================================================
    // VERSAND MIT UNBLOCKIERBAREM UNIQ-TIME-JOKER (SPRENGT DEN CHROME-HANDY-CACHE)
    // =========================================================================
    fetch('rme_chat_backend.php?action=send&admin_auth_id=' + meineEchteSendeID + '&admin_auth_name=' + encodeURIComponent(rmeSendeNameAnBackend.trim()) + '&t=' + new Date().getTime(), { method: 'POST', body: formData })
    .then(function(response) { 
        return response.text(); 
    })
    .then(function(antwortText) {
        console.log("📨 CHAT-SENDE-ECHO: " + antwortText);

        // 🔥 UNZERSTÖRBARER JOKER: Prüft direkt im echten Textfeld, ob ein [INTRO_ geholt wurde
        var istEinIntroSignal = false;
        if (echtesInputFeld && echtesInputFeld.value) {
            istEinIntroSignal = (echtesInputFeld.value.indexOf('[INTRO_USER_') !== -1);
        }
        
        // Spielt nachricht.mp3 NUR ab, wenn es kein Intro-Test ist!
        if (!istEinIntroSignal) {
            try {
                var meinEigenerPling = new Audio('sounds/nachricht.mp3?v=' + new Date().getTime());
                meinEigenerPling.volume = 0.4; 
                meinEigenerPling.play().catch(function(e) { console.log("Eigener Pling blockiert:", e); });
            } catch(e) { console.error("Audio-Fehler:", e); }
        } else {
            console.log("🤫 Intro-Test erkannt: Sende-Sound nachricht.mp3 stummgeschaltet!");
        }

        // Schaltet das gelbe Schloss-Schild unsichtbar
        var whisperLabel = document.getElementById('rme-whisper-indicator');
        if (whisperLabel) { 
            whisperLabel.style.visibility = 'hidden'; 
        }
        
        // Leert das Textfeld erst JETZT, nachdem wir den Intro-Check gemacht haben!
        if (echtesInputFeld) {
            echtesInputFeld.classList.remove('rme-whisper-active');
            echtesInputFeld.placeholder = "Deine Nachricht...";
            echtesInputFeld.value = '';
        }

        // Deine originalen Picker- und Button-Zurücksetzungen laufen hier fehlerfrei weiter:
        if (typeof rmeFontPicker !== 'undefined' && rmeFontPicker) rmeFontPicker.value = '';
        if (typeof rmeSizePicker !== 'undefined' && rmeSizePicker) rmeSizePicker.value = '';
        if (typeof rmeColorPicker !== 'undefined' && rmeColorPicker) rmeColorPicker.value = '#ffffff';
        
    rmeChatInput.value = '';
    window.rmeIchSendeGerade = true;


    // 🎯 INTELLIGENTER RESET: Schaut nach, ob der helle Modus aktiv ist
    var istHell = document.body.classList.contains('rme-light-mode');
    var resetBg = istHell ? '#ffffff' : '#1a1a1a';
    var resetColor = istHell ? '#333333' : '#ffffff';


    // =========================================================================
    // 🛠️ ULTIMATIVER CLEAN-RESET: KEINE COLO-BLOCKADEN MEHR DURCH JAVASCRIPT!
    // =========================================================================
    var bHid = document.getElementById('rme-bold-hidden'); if (bHid) bHid.value = '0';
    var iHid = document.getElementById('rme-italic-hidden'); if (iHid) iHid.value = '0';
    var uHid = document.getElementById('rme-underline-hidden'); if (uHid) uHid.value = '0';

    // Wir löschen JEDEN Inline-Style restlos. Das CSS übernimmt ab hier fehlerfrei!
    var btnB = document.getElementById('rme-btn-bold'); 
    if (btnB) { 
        btnB.style.background = ''; 
        btnB.style.backgroundColor = ''; 
        btnB.style.color = ''; 
        btnB.style.borderColor = '';
        btnB.classList.remove('active'); 
    }
    
    var btnI = document.getElementById('rme-btn-italic'); 
    if (btnI) { 
        btnI.style.background = ''; 
        btnI.style.backgroundColor = ''; 
        btnI.style.color = ''; 
        btnI.style.borderColor = '';
        btnI.classList.remove('active'); 
    }
    
    var btnU = document.getElementById('rme-btn-underline'); 
    if (btnU) { 
        btnU.style.background = ''; 
        btnU.style.backgroundColor = ''; 
        btnU.style.color = ''; 
        btnU.style.borderColor = '';
        btnU.classList.remove('active'); 
    }

	// =========================================================================
// 👑 INTELLIGENTE KLICK-LOGIK FÜR DIE FORMAT-BUTTONS (PHP/JS)
// =========================================================================

function rmeToggleBold() {
    var btn = document.getElementById('rme-btn-bold');
    var hid = document.getElementById('rme-bold-hidden');
    if (!btn || !hid) return;

    var istHell = document.body.classList.contains('rme-light-mode');

// Ändere den Deaktivierungs-Teil (wenn hid.value === '1') in Deinen Funktionen so ab:
if (hid.value === '1') {
    hid.value = '0';
    btn.classList.remove('active');
    // Inline-Styles beim Ausschalten leeren, damit das CSS sofort einspringt!
    btn.style.background = '';
    btn.style.backgroundColor = '';
    btn.style.color = '';
    btn.style.borderColor = '';
} else {
    // Beim Einschalten bleibt das bewährte JavaScript-Rot:
    hid.value = '1';
    btn.classList.add('active');
    btn.style.background = '#ff5722';
    btn.style.backgroundColor = '#ff5722';
    btn.style.color = '#000000';
}

}

function rmeToggleItalic() {
    var btn = document.getElementById('rme-btn-italic');
    var hid = document.getElementById('rme-italic-hidden');
    if (!btn || !hid) return;

    var istHell = document.body.classList.contains('rme-light-mode');

// Ändere den Deaktivierungs-Teil (wenn hid.value === '1') in Deinen Funktionen so ab:
if (hid.value === '1') {
    hid.value = '0';
    btn.classList.remove('active');
    // Inline-Styles beim Ausschalten leeren, damit das CSS sofort einspringt!
    btn.style.background = '';
    btn.style.backgroundColor = '';
    btn.style.color = '';
    btn.style.borderColor = '';
} else {
    // Beim Einschalten bleibt das bewährte JavaScript-Rot:
    hid.value = '1';
    btn.classList.add('active');
    btn.style.background = '#ff5722';
    btn.style.backgroundColor = '#ff5722';
    btn.style.color = '#000000';
}

}

function rmeToggleUnderline() {
    var btn = document.getElementById('rme-btn-underline');
    var hid = document.getElementById('rme-underline-hidden');
    if (!btn || !hid) return;

    var istHell = document.body.classList.contains('rme-light-mode');

// Ändere den Deaktivierungs-Teil (wenn hid.value === '1') in Deinen Funktionen so ab:
if (hid.value === '1') {
    hid.value = '0';
    btn.classList.remove('active');
    // Inline-Styles beim Ausschalten leeren, damit das CSS sofort einspringt!
    btn.style.background = '';
    btn.style.backgroundColor = '';
    btn.style.color = '';
    btn.style.borderColor = '';
} else {
    // Beim Einschalten bleibt das bewährte JavaScript-Rot:
    hid.value = '1';
    btn.classList.add('active');
    btn.style.background = '#ff5722';
    btn.style.backgroundColor = '#ff5722';
    btn.style.color = '#000000';
}

}

   // Entriegelt die Sende-Bremse sofort nach erfolgreichem Versand
        window.rmeIchSendeGerade = false;
        
        // KORREKTUR: Nutzt echtesInputFeld für den mobilen / Desktop-Fokus-Wechsel
        if (echtesInputFeld) {
            if (/Android|iPhone|iPad/i.test(navigator.userAgent)) { 
                echtesInputFeld.blur(); 
            } else { 
                echtesInputFeld.focus(); 
            }
        }
    })
    .catch(function(err) { 
        console.error("❌ Fehler beim Senden:", err);
        window.rmeIchSendeGerade = false; // Sicherheits-Freigabe auch bei Fehlern!
    });
} // <--- Hier schließt sich die Funktion fuehreRmeChatAbsendungAus absolut kugelrund!

// =========================================================================
// SCHARFE EVENT-BINDINGS ÜBER DIE ECHTEN GLOBALEN VARIABLEN VOM KOPF
// =========================================================================
if (rmeChatForm && rmeChatInput) {
    
    // 1. Submit-Event des Formulars abfangen (Verhindert Neuladen)
    rmeChatForm.addEventListener('submit', function(event) {
        event.preventDefault();
        fuehreRmeChatAbsendungAus();
        return false;
    });

    // 2. Keypress-Event für die Enter-Taste
    rmeChatInput.addEventListener('keypress', function(e) {
        if (e.which === 13 && !e.shiftKey) { 
            e.preventDefault();
            fuehreRmeChatAbsendungAus(); 
            return false; 
        }
    });

    // 3. Unblockierbarer Klick-Joker für den Senden-Button
    var rmeSendenButtonElement = document.querySelector('.rme-chat-send-btn');
    if (rmeSendenButtonElement) {
        rmeSendenButtonElement.removeAttribute('onclick'); 
        rmeSendenButtonElement.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            fuehreRmeChatAbsendungAus(); 
            return false;
        });
    }
}

// =========================================================================
// ADMIN REITER TABS STEUERUNG
// =========================================================================
var tabLive = document.getElementById('rme-tab-live');
var tabArchive = document.getElementById('rme-tab-archive');
var tabKicklist = document.getElementById('rme-tab-kicklist');
var tabBannlist = document.getElementById('rme-tab-bannlist');
var tabChatUsers = document.getElementById('rme-tab-chatusers');
var optClearLive = document.getElementById('opt-clear-live');
var optClearArchive = document.getElementById('opt-clear-archive');
var adminActionSelect = document.getElementById('rme-admin-select-action');

function setzeAlleTabsInaktiv() {
    var alleReiter = [tabLive, tabArchive, tabKicklist, tabBannlist, tabChatUsers];
    alleReiter.forEach(function(t) { if (t) t.classList.remove('active'); });
}

function schalteTabUmschaltung(tabElement, tabName, ladeText, backendAction) {
    if (tabElement) {
        tabElement.addEventListener('click', function() {
            activeChatTab = tabName; window.currentActionParam = backendAction;
            setzeAlleTabsInaktiv();
            tabElement.classList.add('active');
            if (adminActionSelect) {
                adminActionSelect.value = '';
                if (tabName === 'live') { if (optClearLive) optClearLive.style.display = 'block'; if (optClearArchive) optClearArchive.style.display = 'none'; } 
                else if (tabName === 'archive') { if (optClearLive) optClearLive.style.display = 'none'; if (optClearArchive) optClearArchive.style.display = 'block'; }
            }
            if (chatWin) { chatWin.innerHTML = '<div style="color:#2cf; font-weight: bold; text-align:center; padding-top:50px;">' + ladeText + '</div>'; }
            setTimeout(function() { reloadChatBoxOnly(); }, 300); 
        });
    }
}
schalteTabUmschaltung(tabLive, 'live', 'Lade Live-Chat...', 'history');
schalteTabUmschaltung(tabArchive, 'archive', 'Lade Archiv-Verlauf...', 'archive_view');
schalteTabUmschaltung(tabKicklist, 'kicklist', 'Lade temporäre Kickliste...', 'view_kicklist');
schalteTabUmschaltung(tabBannlist, 'bannlist', 'Lade permanente Bannliste...', 'view_bannlist');
schalteTabUmschaltung(tabChatUsers, 'chatusers', 'Lade registrierte Chat-User...', 'view_chat_users');

if (adminActionSelect) {
    adminActionSelect.addEventListener('change', function() {
        var ausgewaehlteAktion = this.value; if (ausgewaehlteAktion === "") return;
        var confirmMsg = (ausgewaehlteAktion === 'clear_live') ? 'Live-Chat komplett leeren?' : 'bis auf die letzten 15 Einträge das Archiv leeren?';
        if (confirm(confirmMsg)) {
            fetch('rme_chat_backend.php?action=' + ausgewaehlteAktion + '&admin_auth_name=DJ-Tomjac&admin_auth_id=1', { method: 'POST' })
            .then(function() { reloadChatBoxOnly(); adminActionSelect.value = ""; });
        } else { adminActionSelect.value = ""; }
    });
}

// STRUKTUR-BRÜCKEN FÜR KICK UND BANN STEUERUNG
var modUserSelect = document.getElementById('rme-mod-user-select');
var btnKickDropdown = document.getElementById('rme-mod-btn-kick');
var btnBannDropdown = document.getElementById('rme-mod-btn-bann');

if (btnKickDropdown && modUserSelect) {
    btnKickDropdown.addEventListener('click', function() {
        if (modUserSelect.value === "") { alert("Wähle einen User!"); return; }
        window.fuehreListenKickAus(modUserSelect.value);
    });
}
if (btnBannDropdown && modUserSelect) {
    btnBannDropdown.addEventListener('click', function() {
        if (modUserSelect.value === "") { alert("Wähle einen User!"); return; }
        window.fuehreListenBannAus(modUserSelect.value);
    });
}

window.fuehreListenKickAus = function(username) {
    if (confirm('Möchtest du ' + username + ' kicken?')) {
        var fd = new FormData(); fd.append('target_name', username);
        fetch('rme_chat_backend.php?action=ban_user&admin_auth_id=1&admin_auth_name=DJ-Tomjac', { method: 'POST', body: fd })
        .then(function() { loadOnlineList(); reloadChatBoxOnly(); });
    }
};

window.fuehreListenBannAus = function(username) {
    if (confirm('Möchtest du ' + username + ' permanent bannen?')) {
        var fd = new FormData(); fd.append('target_name', username);
        fetch('rme_chat_backend.php?action=blacklist_user', { method: 'POST', body: fd })
        .then(function() { loadOnlineList(); reloadChatBoxOnly(); alert(username + ' gesperrt!'); });
    }
};

window.loescheChatUserKonto = function(userId, username) {
    var fd = new FormData(); fd.append('id', userId); fd.append('target_id', userId);
    fetch('rme_chat_backend.php?action=delete_chat_user&admin_auth_name=DJ-Tomjac&admin_auth_id=1', { method: 'POST', body: fd })
    .then(function() { alert('Konto gelöscht!'); window.currentActionParam = 'view_chat_users'; reloadChatBoxOnly(); });
};

window.entsperreKickIp = function(ipAdresse) {
    if (confirm('IP ' + ipAdresse + ' entkicken?')) {
        var fd = new FormData(); fd.append('ip', ipAdresse);
        fetch('rme_chat_backend.php?action=unban_kick_ip&admin_auth_name=DJ-Tomjac&admin_auth_id=1', { method: 'POST', body: fd })
        .then(function() { window.currentActionParam = 'view_kicklist'; reloadChatBoxOnly(); });
    }
};

window.entsperreBannUser = function(username) {
    if (confirm('Sperre für ' + username + ' aufheben?')) {
        var fd = new FormData(); fd.append('username', username); fd.append('target_name', username);
        fetch('rme_chat_backend.php?action=unban_black_user&admin_auth_name=DJ-Tomjac&admin_auth_id=1', { method: 'POST', body: fd })
        .then(function() { window.currentActionParam = 'view_bannlist'; reloadChatBoxOnly(); });
    }
};

window.rmeToggleGeoBox = function(element) {
    var state = element.getAttribute('data-state'); var country = element.getAttribute('data-country'); var ip = element.getAttribute('data-ip');
    if (state === 'country') { element.innerHTML = 'IP: ' + ip; element.setAttribute('data-state', 'ip'); }
    else { element.innerHTML = 'Land: ' + country; element.setAttribute('data-state', 'country'); }
};

window.setzeAktuellenLiveDJ = function(djName) {
    window.rmeAktuellerLiveDJ = (!djName || djName.toLowerCase() === "autodj") ? "AutoDJ" : djName;
};

// =========================================================================
// THE KICK-STOPPER: RETTUNGS-BREMSE GEGEN DAS EPILEPSIE-ZAPPELN
// =========================================================================
function rmeStoppeDasZappelnKomplett() {
    console.log("🚨 RETTUNGS-BREMSE AKTIVIERT: Alle Chat-Timer werden jetzt gekillt!");
    
    // Zieht knallhart den Stecker für alle bekannten Speicher-Ebenen
    if (window.chatLadeIntervall) { clearInterval(window.chatLadeIntervall); }
    if (window.onlineListenIntervall) { clearInterval(window.onlineListenIntervall); }
    if (typeof chatLadeIntervall !== 'undefined') { clearInterval(chatLadeIntervall); }
    if (typeof onlineListenIntervall !== 'undefined') { clearInterval(onlineListenIntervall); }
    
    // Fallback für verschachtelte Timer-Schleifen
    var maxId = setInterval(function(){}, 9999);
    for (var i = 1; i < maxId; i++) { clearInterval(i); }
}

// =========================================================================
// INITIALER START & AUTOMATISCHE UPDATE-INTERVALLE (GESTAFELTER KALTSTART)
// =========================================================================
document.addEventListener('DOMContentLoaded', function() {
    // Zündet die Uhr erst, wenn alle HTML-Elemente auf dem Handy existieren
    try { rmeTickTack(); } catch(e) { console.log("Uhr-Start Fehler:", e); }
    // Lässt sie jede Sekunde weiterticken
    setInterval(function() {
        try { rmeTickTack(); } catch(e) {}
    }, 1000);

    // 1. REPARIERT: Gestaffelte Stufen-Zündung beim allerersten Laden der Seite!
    // Verhindert das nervöse Doppel-Zucken, weil die Abfragen getrennt aufschlagen.
    try { 
        reloadChatBoxOnly(); 
    } catch(e) { console.log(e); }
    
    // 3. AUTOMATISCHE HINTERGRUND-SCHLEIFEN (START NACH 4 SEKUNDEN)
    setTimeout(function() {
        
        // Chatbox feuert jetzt auf einer krummen Zahl (z. B. alle 3100 Millisekunden)
        window.chatLadeIntervall = setInterval(function() { 
            try {
                reloadChatBoxOnly(); 
            } catch(err) {
                console.log("Fehler abgefangen:", err);
            }
        }, 3100);
        
        // Die Onlineliste feuert auf einer völlig versetzten Zeit (z. B. alle 7300 Millisekunden)
        window.rmeMainOnlineTimer = setInterval(function() { 
            try {
                loadOnlineList(); 
            } catch(err) {
                console.log("Fehler abgefangen:", err);
            }
        }, 7300);
        
    }, 4000);


});

// =========================================================================
// ZENTRALER SOUND-INITIALISIERER & REFRESH-SCHUTZ (KEIN FLACKERN MEHR)
// =========================================================================
document.addEventListener("DOMContentLoaded", function() {
    var soundEinstellung = localStorage.getItem('rme_chat_sounds');
    var checkbox = document.getElementById('rme-chat-sound-toggle');
    
    // Standardmäßig ist der Sound eingeschaltet (true)
    if (soundEinstellung === null || soundEinstellung === 'true') {
        localStorage.setItem('rme_chat_sounds', 'true');
        if (checkbox) checkbox.checked = true;
    } else {
        if (checkbox) checkbox.checked = false;
    }
});

// =========================================================================
// 19D. USER-LIVE-SUCHE FÜR DIE VORSCHLAGSLISTE (BEREINIGTE VERSION)
// =========================================================================
document.addEventListener('DOMContentLoaded', function() {
    var searchInput = document.getElementById('rme_intro_search_name');
    var resultsDiv = document.getElementById('rme_intro_search_results');
    if (!searchInput || !resultsDiv) return;

    searchInput.addEventListener('input', function() {
        var query = searchInput.value.trim();
        if (query.length < 2) { resultsDiv.style.display = 'none'; return; }

        fetch('rme_background_handler.php?action=search_moderators&q=' + encodeURIComponent(query))
        .then(function(res) { return res.json(); }) // KORREKT: Nur eine saubere Funktion!
        .then(function(data) {
            resultsDiv.innerHTML = '';
            if (data.status === 'success' && data.users.length > 0) {
                data.users.forEach(function(user) {
                    var div = document.createElement('div');
                    div.style.padding = '10px';
                    div.style.cursor = 'pointer';
                    div.style.borderBottom = '1px solid #222';
                    div.style.background = '#1a1a1a';
                    div.innerHTML = '<strong style="color:#00ff00;">' + user.name + '</strong> <span style="color:#fff;">(ID: ' + user.id + ')</span>';
                    
                    // Beim Klick auf den grünen Vorschlag Werte übernehmen
                    div.addEventListener('click', function() {
                        document.getElementById('rme_intro_target_id_manual').value = user.id;
                        document.getElementById('rme_intro_target_name_manual').value = user.name;
                        searchInput.value = user.name; // Setzt den reinen Namen ins Feld
                        resultsDiv.style.display = 'none';
                    });
                    resultsDiv.appendChild(div);
                });
                resultsDiv.style.display = 'block';
            } else {
                resultsDiv.innerHTML = '<div style="padding:12px; color:#fff; background:#1a1a1a;">Keine User gefunden.</div>';
                resultsDiv.style.display = 'block';
            }
        }).catch(function(err) { console.error("Suchfehler:", err); });
    });

    // Schließt die Box bei Klick außerhalb
    document.addEventListener('click', function(e) {
        if (e.target !== searchInput && e.target !== resultsDiv) { resultsDiv.style.display = 'none'; }
    });
});

// =========================================================================
// CHAT-SOUNDS, AVATARE & MOTOR-LOGIK: DEIN FINALES MASTER-SKRIPT
// =========================================================================
var rmeLetzteNachrichtenAnzahl = 0;
var rmeSoundSperreAktiv = false; 

// --- 1. CHECKBOX-SCHALTER FÜR DAS ZAHNRAD ---
function rmeToggleSoundSetting(istAktiv) {
    localStorage.setItem('rme_chat_sounds', istAktiv ? 'true' : 'false');
}

// --- 2. ZAHNRAD-POPUP AUF- UND ZUKLAPPEN ---
function toggleRmeSettingsPopup() {
    var popup = document.getElementById('rme-settings-popup');
    var smileyPopup = document.getElementById('rme-smiley-popup-container');
    
    if (smileyPopup) smileyPopup.style.display = 'none'; // Schließt Smileys, falls offen
    
    if (popup) {
        if (popup.style.display === 'none' || popup.style.display === '') {
            popup.style.display = 'block';
        } else {
            popup.style.display = 'none';
        }
    }
}

// --- 3. CALLBACK FÜR DEN AVATAR-UPLOAD (GLOBAL AKTIVIERT) ---
window.rmeUploadCallback = function(status, info) {
    alert(info);
    if (status === 'success') {
        window.location.reload(); 
    }
};
// =========================================================================
// MODERNES FETCH-UPLOAD FÜR CHATUSER-AVATARE (ÜBER DIRECT-ONCLICK)
// =========================================================================
function rmeSubmitChatAvatar(e) {
    if(e) e.preventDefault();
    
    var fileInput = document.getElementById('rme_avatar_file');
    if (!fileInput || fileInput.files.length === 0) {
        alert('Bitte wähle zuerst eine Bilddatei von Deinem PC/Handy aus!');
        return;
    }

    var formData = new FormData();
    // Holt exakt die erste ausgewählte Datei wie beim funktionierenden Hintergrund!
    formData.append('rme_avatar_file', fileInput.files[0]); 

    // Visuelles Feedback auf dem Button aktivieren
    var submitBtn = e.target;
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerText = 'Wird geladen...';
    }

    fetch('rme_chat_backend.php?action=upload_avatar', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Server-Antwort war nicht OK (Status: ' + response.status + ')');
        }
        return response.json();
    })
    .then(data => {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerText = 'Bild speichern';
        }

        if (data.status === 'success') {
            alert('🎉 ' + data.message);
            // Lädt den Chat neu, damit das neue Profilbild sofort geladen wird
            window.location.reload();
        } else {
            alert('❌ Fehler vom Server: ' + data.message);
        }
    })
    .catch(error => {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerText = 'Bild speichern';
        }
        console.error('Error:', error);
        alert('❌ Verbindungsfehler! Das Backend hat nicht wie erwartet geantwortet.');
    });
}

// =========================================================================
// REPARIERT: ECHTER TIMEOUT- & SCHLEIFEN-KILLER OHNE BLOCKADEN
// =========================================================================
// Wir nutzen das modernere visibilitychange. Das hält deine Leitung im Live-Betrieb
// zu 100% frei und blockiert deine Nachrichten niemals beim normalen Tippen!
document.addEventListener('visibilitychange', function() {
    if (document.visibilityState === 'hidden') {
        try {
            navigator.sendBeacon('rme_chat_backend.php?action=browser_closed_logout');
        } catch(e) {}
    }
});
// =========================================================================

// --- 8. DER DIREKTE UHR-MOTOR ---
function rmeTickTack() {
    var jetzt = new Date();
    
    var tag = String(jetzt.getDate()).padStart(2, '0');
    var monat = String(jetzt.getMonth() + 1).padStart(2, '0');
    var jahr = jetzt.getFullYear();
    
    var stunden = String(jetzt.getHours()).padStart(2, '0');
    var minuten = String(jetzt.getMinutes()).padStart(2, '0');
    var sekunden = String(jetzt.getSeconds()).padStart(2, '0');
    
    var dateEl = document.getElementById('rme_live_date');
    var timeEl = document.getElementById('rme_live_time');
    
    if (dateEl) dateEl.innerHTML = tag + '.' + monat + '.' + jahr;
    if (timeEl) timeEl.innerHTML = stunden + ':' + minuten + ':' + sekunden;
}


// --- 9. CHAT-KLICK-DETEKTOR FÜR DEN STREAM-PLAYER ---
function rmeSendeStartSignalAnStream() {
    // WICHTIG: Auf dem Handy brechen wir sofort ab – kein automatischer Start!
    if (/Android|iPhone|iPad/i.test(navigator.userAgent)) {
        rmeEntferneChatKlickListener();
        return;
    }

    // Direkt-Prüfung: Wenn der Player auf der Seite existiert und pausiert ist
    if (typeof player !== 'undefined' && player && player.paused) {
        // Ruft die Player-Funktion direkt ohne Iframe-Umweg auf
        toggleRadio(); 
    }
    
    // Löscht die Klick-Erkennung sofort nach dem ersten Versuch
    rmeEntferneChatKlickListener();
}

function rmeEntferneChatKlickListener() {
    document.removeEventListener('click', rmeSendeStartSignalAnStream);
    document.removeEventListener('keydown', rmeSendeStartSignalAnStream);
}

// Die Listener starten das System beim ersten Klick/Tastendruck
document.addEventListener('click', rmeSendeStartSignalAnStream);
document.addEventListener('keydown', rmeSendeStartSignalAnStream);

// =========================================================================
// 10. DOPPELKLICK-SMILEY-ÜBERSETZER (TEXT-FRAGMENT-ABFANG-JOKER) - REPARIERT!
// =========================================================================
document.getElementById('rme-chat-window').addEventListener('dblclick', function(event) {
    var chatInput = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    if (!chatInput) return;

    var zielElement = event.target;
    var inneresHTML = zielElement.innerHTML || '';
    var textInhalt = zielElement.innerText || '';
    var gewaehltesKuerzel = "";

    // NOTFALL-WEICHE: Wenn im angeklickten Text ein Image-Tag steckt
    if (inneresHTML.indexOf('<img') !== -1 || inneresHTML.indexOf('src=') !== -1) {
        for (var datei in smileyDateien) {
            if (inneresHTML.indexOf(datei) !== -1) {
                gewaehltesKuerzel = smileyDateien[datei];
                break;
            }
        }
    }

    // STRATEGIE B: Direktes Bild getroffen
    if (!gewaehltesKuerzel && zielElement.tagName === 'IMG') {
        var srcPath = zielElement.src || '';
        var dateiname = srcPath.substring(srcPath.lastIndexOf('/') + 1);
        if (smileyDateien[dateiname]) {
            gewaehltesKuerzel = smileyDateien[dateiname];
        }
    }

    // Wenn ein FTP-Kürzel ermittelt wurde, jagen wir es durch die Schutzbremse
    if (gewaehltesKuerzel) {
        event.preventDefault();
        event.stopPropagation();
        
        var aktuellerText = chatInput.value;
        // 🔥 REPETITIONS-BREMSE: Wenn genau dieses Kürzel schon am Ende steht, blockieren wir das Einfügen!
        if (aktuellerText.trim().endsWith(gewaehltesKuerzel)) {
            console.log("🚫 REPETITIONS-FILTER (BLOCK 10): " + gewaehltesKuerzel + " bereits vorhanden.");
            chatInput.focus();
            return;
        }

        if (aktuellerText.length > 0 && !aktuellerText.endsWith(' ')) { chatInput.value += ' '; }
        chatInput.value += gewaehltesKuerzel + ' ';
        chatInput.focus();
        return;
    }

    // STRATEGIE C: Normale Unicode-Emojis (Bunte Handy-Symbole im Text)
    var msgTextNode = zielElement.closest('.chat-live-msg-text');
    if (msgTextNode && zielElement.tagName === 'SPAN') {
        var reinerText = textInhalt.trim();
        if (reinerText !== "" && reinerText.length <= 4) {
            event.preventDefault();
            var aktuellerText = chatInput.value;
            
            if (aktuellerText.trim().endsWith(reinerText)) {
                chatInput.focus();
                return;
            }

            if (aktuellerText.length > 0 && !aktuellerText.endsWith(' ')) { chatInput.value += ' '; }
            chatInput.value += reinerText + ' ';
            chatInput.focus();
        }
    }
});

// =========================================================================
// 24. REPARIERT: UNFEHLBARER AUTOMATISCHER LADE-SPION FÜR DIE CHEF-BOX
// =========================================================================
function rmeReloadAdminIpTable() {
    var ipBox = document.getElementById('rme-admin-live-ip-list');
    
    // Sicherheitsgurt: Wir brechen NUR ab, wenn das Element physisch fehlt!
    if (!ipBox) return;

    fetch('rme_background_handler.php?action=load_global_online_ips')
    .then(function(res) { return res.text(); })
    .then(function(htmlAntwort) {
        // Schreibt die fertige HTML-Tabelle blitzschnell in Deine Chef-Box
        ipBox.innerHTML = htmlAntwort;
    })
    .catch(function(err) { 
        console.error("IP-Monitor Hänger:", err); 
    });
}

// Startet den Intervall: Lädt die IPs sofort beim Start und danach alle 5 Sekunden neu
document.addEventListener('DOMContentLoaded', function() {
    rmeReloadAdminIpTable(); // Lädt die Daten SOFORT beim Seitenstart!
    setInterval(rmeReloadAdminIpTable, 5000);
});

// =========================================================================
// UNBLOCKIERBARES ID-BASIERTES SOUND-SYSTEM (100% FEHLERFREIER BACKUP-STAND)
// =========================================================================
if (typeof window.rmeSoundNormalGlobal === 'undefined') {
    window.rmeSoundNormalGlobal = new Audio('sounds/nachricht.mp3?t=' + new Date().getTime());
    window.rmeSoundNormalGlobal.volume = 0.4;
}
if (typeof window.rmeSoundErwaehnungGlobal === 'undefined') {
    window.rmeSoundErwaehnungGlobal = new Audio('sounds/erwaehnung.mp3?t=' + new Date().getTime());
    window.rmeSoundErwaehnungGlobal.volume = 0.5;
}

window.rmeLetzteNachrichtenID = "";
window.rmeSoundSperreAktiv = false; 
window.rmeAudioVomBrowserFreigeschaltet = false;

function rmeSchalteAudioFrei() {
    if (window.rmeAudioVomBrowserFreigeschaltet) return;
    if (window.rmeSoundNormalGlobal) { window.rmeSoundNormalGlobal.play().then(function() { window.rmeSoundNormalGlobal.pause(); window.rmeSoundNormalGlobal.currentTime = 0; }).catch(function(e) {}); }
    if (window.rmeSoundErwaehnungGlobal) { window.rmeSoundErwaehnungGlobal.play().then(function() { window.rmeSoundErwaehnungGlobal.pause(); window.rmeSoundErwaehnungGlobal.currentTime = 0; }).catch(function(e) {}); }
    window.rmeAudioVomBrowserFreigeschaltet = true;
    document.removeEventListener('click', rmeSchalteAudioFrei);
    document.removeEventListener('keydown', rmeSchalteAudioFrei);
}
document.addEventListener('click', rmeSchalteAudioFrei);
document.addEventListener('keydown', rmeSchalteAudioFrei);

// =================================================================
// Pruefeundspielesound (ENDSTUFE FÜR SYNCHRONEN ÄTHER-FUNK AUS DEM BLOB!)
// =================================================================
function rmePruefeUndSpieleSound(istFrischerPoll) {
    if (localStorage.getItem('rme_chat_sounds') !== 'true') return;
    
    var liveTabButton = document.getElementById('rme-tab-live');
    if (liveTabButton && !liveTabButton.classList.contains('active')) {
        window.rmeLetzteNachrichtenID = "";
        return; 
    }

    var zielFenster = document.getElementById('rme-chat-window');
    if (!zielFenster) return;

    // 1. GLOBALER SOUNDBOARD-EMPFÄNGER AUS DER NACHRICHTEN-TABELLE
    var soundTrigger = zielFenster.getElementsByClassName('rme-hidden-sound-trigger');
    if (soundTrigger && soundTrigger.length > 0) {
        var neuesterSound = soundTrigger[soundTrigger.length - 1];
        var rohesSignal = neuesterSound.getAttribute('data-file') || "";
        
        // Liest die im PHP gesetzte ID aus (z.B. rme-sound-msg-12345)
        var aktuelleSoundID = neuesterSound.getAttribute('id') || "";

        if (rohesSignal.indexOf('SOUND:') === 0) {
            var soundDatei = rohesSignal.replace('SOUND:', '').trim();
            
            if (soundDatei && window.rmeAudioVomBrowserFreigeschaltet) {
                if (window.rmeIchHabeDiesenSoundSelbstGezuendet === soundDatei) {
                    window.rmeLetzteSoundID = aktuelleSoundID;
                    window.rmeIchHabeDiesenSoundSelbstGezuendet = ""; 
                } 
                // ZÜNDET JETZT BEI JEDER NEUEN ID!
                else if (window.rmeLetzteSoundID !== aktuelleSoundID && aktuelleSoundID !== "") {
                    window.rmeLetzteSoundID = aktuelleSoundID;
                    
                    var soundDateiKlein = soundDatei.toLowerCase();
                    var globalAudioPfad = "";

                    // 🎯 1. WEICHE: User-Intros über die versteckten Trigger abfangen
                    if (soundDateiKlein.indexOf('intro') === 0) {
                        var extrahierteUserId = parseInt(soundDateiKlein.replace('intro', '').replace('.mp3', ''));
                        
                        if (extrahierteUserId > 0) {
                            // Das brennt das Log nun unfehlbar in die Konsole des Empfängers!
                            console.log("🔊 INTRO-EMPFÄNGER: Spiele Login-Intro aus DB-BLOB für User-ID -> " + extrahierteUserId);
                            
                            if (typeof rmeFuehreIntrozuendungAus === 'function') {
                                rmeFuehreIntrozuendungAus(extrahierteUserId);
                            }
                            return; 
                        }
                    }


                    // 🎯 2. WEICHE: Ist es ein klassischer System-Sound vom FTP (zapper, msg, ping)?
                    if (soundDateiKlein.indexOf('zapper') !== -1 || 
                        soundDateiKlein.indexOf('msg') !== -1 || 
                        soundDateiKlein.indexOf('ping') !== -1) {
                        
                            globalAudioPfad = "sounds/" + soundDatei;
                            console.log("🔊 SYSTEM-FUNK: Spiele FTP-Datei -> " + globalAudioPfad);
                        } else {
                            // 🎯 3. WEICHE REPARIERT: Wenn es ein Hörer-Sound ist, nutzen wir den Namen ohne Änderungen
                            var reinerBefehlsName = soundDateiKlein;
                            if (soundDateiKlein.indexOf('hoerer_') === -1) {
                                // Nur normale DJsounds verlieren das .mp3
                                reinerBefehlsName = soundDateiKlein.replace('.mp3', '');
                            }
                            
                            globalAudioPfad = "play_sound.php?command=" + encodeURIComponent(reinerBefehlsName);
                            console.log("🔊 SOUNDBOARD: Spiele globalen Jingle aus DB-BLOB -> " + reinerBefehlsName);
                        }                   
                    // Der originale Abspiel-Teil für normale Sounds & System-Pings
                    try {
                        var globalAudio = new Audio(globalAudioPfad + (globalAudioPfad.indexOf('?') !== -1 ? "&t=" : "?t=") + new Date().getTime());
                        globalAudio.volume = 0.7; 
                        globalAudio.play().catch(function(e) { console.warn("Audio blockiert:", e); });
                    } catch(err) { console.error("Soundboard-Fehler:", err); }
                }
            }
        }
    }

    // --- 2. DEIN ORIGINALER TEXT-PING-CHECK MITSAMT INTRO-ZÜNDUNG (100% REPARIERT) ---
    if (window.rmeSoundSperreAktiv) return;

    var alleZeilen = zielFenster.getElementsByClassName('rme-chat-row');
    if (alleZeilen.length === 0) return;

    var letzteZeile = alleZeilen[alleZeilen.length - 1];
    var aktuelleNachrichtenID = letzteZeile.getAttribute('id') || "";

    if (window.rmeLetzteNachrichtenID === "") {
        window.rmeLetzteNachrichtenID = aktuelleNachrichtenID;
        return;
    }

    if (aktuelleNachrichtenID !== "" && aktuelleNachrichtenID !== window.rmeLetzteNachrichtenID) {
        var roherTextInZeile = letzteZeile.innerHTML;

        // 🎯 REPARIERT: Keine Schleifen-Hänger mehr! Liest stur die Zeile aus wie früher.
        if (roherTextInZeile && roherTextInZeile.trim() !== "") {
            rmeCheckAndPlayUserIntros(roherTextInZeile);
        }

        if (roherTextInZeile && roherTextInZeile.indexOf('rme-hidden-sound-trigger') !== -1) {
            window.rmeLetzteNachrichtenID = aktuelleNachrichtenID;
            return; 
        }

        var inputFeld = document.getElementById('rme-chat-input');
        var meinName = inputFeld ? inputFeld.getAttribute('data-user') : '';
        var meinSaubererName = meinName ? meinName.replace('_Gast', '').replace('_CU', '').trim() : '';
        
        var absenderImChat = "";
        var userSpan = letzteZeile.querySelector('.chat-live-msg-user');
        if (userSpan) {
            absenderImChat = (userSpan.textContent || userSpan.innerText).replace(':', '').trim();
        }
// =========================================================================
// REPARIERT: ERMÖGLICHT DAS AUTOMATISCHE INTRO-PLAYBACK AUCH FÜR DICH SELBST
// =========================================================================
if (meinSaubererName !== "" && absenderImChat !== "" && absenderImChat.toLowerCase() === meinSaubererName.toLowerCase()) {
    
    // 🎯 DER MASTER-TRICK: Wenn es eine Intro-Zeile ist, ignorieren wir den Eigen-Block
    // und lassen den Code weiterlaufen, damit das Intro für Dich getriggert wird!
    if (roherTextInZeile && roherTextInZeile.indexOf('[INTRO_USER_') !== -1) {
        // Nicht blockieren, sondern weiterlaufen lassen!
    } else {
        window.rmeLetzteNachrichtenID = aktuelleNachrichtenID;
        return;
    }
}


        window.rmeSoundSperreAktiv = true;
        setTimeout(function() { window.rmeSoundSperreAktiv = false; }, 1500);

        if (roherTextInZeile && (roherTextInZeile.toLowerCase().indexOf('@dj-tomjac') !== -1 || roherTextInZeile.toLowerCase().indexOf('dj-tomjac') !== -1)) {
            if (window.rmeSoundErwaehnungGlobal) { window.rmeSoundErwaehnungGlobal.currentTime = 0; window.rmeSoundErwaehnungGlobal.play().catch(function(e) {}); }
        } else {
            if (roherTextInZeile && roherTextInZeile.indexOf('[INTRO_USER_') === -1) {
                if (window.rmeSoundNormalGlobal) { window.rmeSoundNormalGlobal.currentTime = 0; window.rmeSoundNormalGlobal.play().catch(function(e) {}); }
            }
        }
    }

    window.rmeLetzteNachrichtenID = aktuelleNachrichtenID;
}

// =========================================================================
// Playsound mit DATENBANK (LIVE-SMILEY & DJ-LAUFLICHT RAPID-VERSION)
// =========================================================================
function rmePlaySound(soundName) {
    console.log("🚀 SOUNDBOARD-SENDER AKTIV: " + soundName);
    
    // 🔥 LIVE-DJ-LAUFLICHT: DISCO-EFFEKT BEI SOUND-ZÜNDUNG
    var $chatWindow = $('#rme-chat-window');
    if ($chatWindow.length) {
        // Wir schalten zurück auf die vom Browser akzeptierte Klasse!
        $chatWindow.addClass('rme-dj-disco-flash');
        
        // Nach genau 4 Sekunden schalten wir den Effekt automatisch wieder ab
        setTimeout(function() {
            $chatWindow.removeClass('rme-dj-disco-flash');
        }, 4000);
    }
    // =========================================================================

    var audioPfad = "play_sound.php?command=" + encodeURIComponent(soundName.toLowerCase()); 

    // 🎯 REPARIERT: Der Echo-Killer darf NUR bei normalen Soundboard-Buttons anspringen
    if (soundName.toLowerCase().indexOf('intro') === -1) {
        window.rmeIchHabeDiesenSoundSelbstGezuendet = soundName.toLowerCase() + ".mp3";
    } else {
        window.rmeIchHabeDiesenSoundSelbstGezuendet = ""; 
    }

    try {
        var boardAudio = new Audio(audioPfad + "&t=" + new Date().getTime());
        boardAudio.volume = 0.8; 
        boardAudio.play().catch(function(err) { console.warn("Audio-Wiedergabe aus DB blockiert:", err); });
    } catch(audioErr) { console.error("Fehler bei DB-Audio", audioErr); }

    var xhr = new XMLHttpRequest();
    var rmeInputFeld = document.getElementById('rme-chat-input');
    var meineEchteSendeID = rmeInputFeld ? parseInt(rmeInputFeld.getAttribute('data-id') || '0') : 18;
    var rmeSendeNameAnBackend = rmeInputFeld ? (rmeInputFeld.getAttribute('data-user') || 'DJ-Tomjac') : 'DJ-Tomjac';

    xhr.open("GET", "rme_chat_backend.php?action=broadcast_dj_sound&sound=" + encodeURIComponent(soundName) + "&admin_auth_id=" + meineEchteSendeID + "&admin_auth_name=" + encodeURIComponent(rmeSendeNameAnBackend) + "&t=" + new Date().getTime(), true);
    xhr.send();
}

// =========================================================================
// NEU: DIE VERMISSTE WEICHE FÜR DIE SOUND-BUTTONS (BEHEBT REFERENZ-FEHLER)
// =========================================================================
function rmeSpieleDbSound(soundId, soundCommand) {
    // Leitet den Klick direkt an Deine funktionierende rmePlaySound weiter
    if (typeof rmePlaySound === 'function') {
        rmePlaySound(soundCommand);
    }
}

// ========================================================
// Schaltet das Datenschutz-Banner fehlerfrei ein- oder aus
// ========================================================
function rmeToggleDsgvoBanner(status) {
    var pop = document.getElementById('rme-dsgvo-popup');
    if (pop) {
        pop.style.display = status ? 'block' : 'none';
    }
}
function rmeToggleStudiobox(show) {
    var overlay = document.getElementById('rme-studiobox-popup');
    if (!overlay) return;
    
    if (show) {
        overlay.style.display = 'block';
    } else {
        overlay.style.display = 'none';
    }

    // 🎯 NEU: Wir spiegeln die Ampel-Klasse des Modals live auf Dein Schreibfeld unten!
    var modal = overlay.querySelector('.rme-studiobox-modal');
    var inputFeld = document.getElementById('rme-chat-input') || document.querySelector('.rme-chat-input-field') || document.getElementById('message');
    
    if (modal && inputFeld) {
        // Wir putzen alte Klassen weg
        inputFeld.classList.remove('rme-ampel-border-open', 'rme-ampel-border-closed');
        
        // Wenn das Modal die grüne Klasse hat, kriegt das Schreibfeld sie auch. Bei Orange umgekehrt!
        if (modal.classList.contains('rme-ampel-border-open')) {
            inputFeld.classList.add('rme-ampel-border-open');
        } else if (modal.classList.contains('rme-ampel-border-closed')) {
            inputFeld.classList.add('rme-ampel-border-closed');
        }
    }
}

// =========================================================================
// 🔥 REPARIERT: WUNSCH-ABSENDUNG MIT ECHTEM NAMEN-MITTRANSPORT
// =========================================================================
function rmeSendeWunschSmooth() {
    var gruss = document.getElementById('greetings').value.trim();
    var wunsch = document.getElementById('wishes').value.trim();
    var formInhalt = document.getElementById('rme-studiobox-weiche-inhalt');
    
    if (!gruss && !wunsch) {
        alert("Bitte trage einen Gruß oder einen Musikwunsch ein!");
        return;
    }
    
    // REPARIERT: Holt den aktuell eingeloggten Chat-Namen und die ID live aus der Chat-Eingabe
    var inputFeld = document.getElementById('rme-chat-input');
    var aktuellerChatName = inputFeld ? inputFeld.getAttribute('data-user') : 'Gast';
    var aktuelleChatID = inputFeld ? parseInt(inputFeld.getAttribute('data-id') || '0') : 0;

    // Rettungs-Filter falls das Profilfeld einen abweichenden Namen besitzt
    var checkProfile = document.getElementById('rme-user-profile-name');
    if ((!aktuellerChatName || aktuellerChatName === 'Gast') && checkProfile && checkProfile.innerText.trim() !== "") {
        aktuellerChatName = checkProfile.innerText.trim();
    }
    
    var formData = new FormData();
    formData.append('greetings', gruss);
    formData.append('wishes', wunsch);
    
    // REPARIERT: Diese beiden Schlüssel transportieren Deinen Namen jetzt sicher ins Backend!
    formData.append('user_name', aktuellerChatName);
    formData.append('user_id', aktuelleChatID);
    
    // HIER GEHT DER FUNKSPRUCH JETZT AN DIE NEUE, EIGENE DATEI:
    fetch('rme_wunsch_save.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            // Wunderschöne Neon-Erfolgsmeldung einblenden
            if (formInhalt) {
                formInhalt.innerHTML = '<div style="color: #00ffcc; text-align: center; padding: 30px 0; font-size: 16px; font-weight: bold;">' +
                    '🎙️ Wunsch empfangen! 🚀<br><br>' +
                    '<span style="color: #fff; font-weight: normal; font-size: 13px;">Dein Gruß und Musikwunsch wurden soeben erfolgreich direkt auf den Studio-Monitor des DJs übertragen.</span>' +
                    '</div>' +
                    '<div class="rme-dsgvo-footer" style="padding-top: 10px;">' +
                    '<button type="button" class="rme-studiobox-submit-btn" style="background: #333; color: #ff0; width: 100%;" onclick="rmeToggleStudiobox(false)">Fenster schließen</button>' +
                    '</div>';
                
                // Fenster nach 4 Sekunden automatisch schließen
                setTimeout(function() {
                    rmeToggleStudiobox(false);
                }, 4000);
            }
        } else {
            alert('Fehler im Studio-System: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Fehler beim Senden:', error);
        alert('Verbindungsfehler zum Studio!');
    });
}

// =========================================================================
// 🔄 AUTOMATISCHER SOUNDBOARD-POLL & GAMING-SPIELESPIO (PERFEKT SYNCHRON)
// =========================================================================
document.addEventListener('DOMContentLoaded', function() {
    // Startet die Überprüfung sofort nach dem Laden
    rmePruefeUndSpieleSound(true);
    
    // 🔥 NEU: Führt den ersten, blitzschnellen Spiele-Spion-Check direkt beim Laden aus
    if (typeof rmeTttSpion === 'function') { rmeTttSpion(); }
    
    // Prüft ununterbrochen alle 1000 Millisekunden (1 Sekunde) nach Server-Sounds
    setInterval(function() {
        rmePruefeUndSpieleSound(false);
    }, 1000);

    // 🔥 NEU: Spiele-Spion pollt jetzt parallel alle 2000 Millisekunden (2 Sekunden).
    // Das sorgt für extrem schnelle Reaktionszeiten beim Tic-Tac-Toe, ohne den Server zu jagen!
    setInterval(function() {
        if (typeof rmeTttSpion === 'function') { rmeTttSpion(); }
    }, 2000);
});
// =========================================================================

// Schließen des Fensters, wenn man außerhalb in die Dunkelheit klickt
document.addEventListener('DOMContentLoaded', function() {
    var overlay = document.getElementById('rme-studiobox-popup');
    if (overlay) {
        window.addEventListener('click', function(event) {
            if (event.target === overlay) {
                rmeToggleStudiobox(false);
            }
        });
    }
});
$(document).ready(function() {
    // 1. Wir prüfen, wer auf dem Player steht
    // Ersetze '#mein-player-dj-text' mit der echten ID deines Players!
    var aktuellerDJ = $('#mein-player-dj-text').text().trim(); 

    if (aktuellerDJ !== "AutoDJ" && aktuellerDJ !== "") {
        // Live-DJ ist da: Zeige das Wunsch-Formular, verstecke das AutoDJ-Banner
        $('#autodj-banner').hide();
        $('#wunschbox-formular').show();
        
        // Schreibe den DJ-Namen direkt in ein verstecktes Feld, falls nötig
        $('#hidden-dj-name').val(aktuellerDJ);
    } else {
        // AutoDJ ist aktiv: Wunschbox zu!
        $('#autodj-banner').show();
        $('#wunschbox-formular').hide();
    }
});
var rmeNoteTimer;

// Variable, um zu merken, ob wir in dieser Session den Stempel schon gesetzt haben
var rmeNameStempelGesetzt = false;

function rmeToggleAdminNote(show) {
    var overlay = document.getElementById('rme-admin-note-popup');
    if (!overlay) return;
    overlay.style.display = show ? 'block' : 'none';
    
    if (show) {
        setTimeout(function() {
            var field = document.getElementById('rme-admin-note-field');
            if (field) {
                field.focus();
                field.value = ""; // Feld beim Öffnen leeren für neuen Text
            }
            // Verlauf automatisch nach ganz unten scrollen
            var historyBox = document.getElementById('rme-admin-note-history');
            if (historyBox) {
                historyBox.scrollTop = historyBox.scrollHeight;
            }
        }, 120);
    }
}

function rmeSendeNeueNotiz() {
    var inputField = document.getElementById('rme-admin-note-field');
    var statusLabel = document.getElementById('rme-note-status');
    var historyBox = document.getElementById('rme-admin-note-history');
    
    if (!inputField || inputField.value.trim() === "") return;
    
    var neuerText = inputField.value.trim();
    var nameInput = document.getElementById('rme-hidden-admin-name');
    var saubererName = nameInput ? nameInput.value.trim() : 'Admin';
    
    if (statusLabel) {
        statusLabel.innerHTML = "⏳ Speichert...";
        statusLabel.style.color = "#00ffcc";
        statusLabel.style.borderColor = "#00ffcc";
    }
    
    var jetzt = new Date();
    var tag = String(jetzt.getDate()).padStart(2, '0');
    var monat = String(jetzt.getMonth() + 1).padStart(2, '0');
    var stunden = String(jetzt.getHours()).padStart(2, '0');
    var minuten = String(jetzt.getMinutes()).padStart(2, '0');
    var zeitStempel = tag + "." + monat + ". um " + stunden + ":" + minuten;
    
    // Generiert einen einzigartigen Zeit-Stempel als ID für diesen Absatz
    var eintragID = "note_" + jetzt.getTime();
    var alterVerlauf = historyBox.innerHTML.includes("Noch keine Protokolleinträge") ? "" : historyBox.innerHTML;
    
    // JEDER EINTRAG BEKOMMT EINE ID UND DAS GRÜNE NEON-HÄKCHEN ZUM LÖSCHEN
    var neuerEintragHTML = '<div id="' + eintragID + '" style="margin-bottom: 8px; border-bottom: 1px dashed #1a1a1a; padding-bottom: 4px; display: flex; justify-content: space-between; align-items: flex-start; gap: 10px;">' +
                           '<div>' +
                           '<span style="color: #22ccff; font-size: 12px;">[' + zeitStempel + ']</span> ' +
                           '<strong style="color: #00f0ff;">' + saubererName + ':</strong> ' +
                           '<span style="color: #ffffff;">' + neuerText + '</span>' +
                           '</div>' +
                           '<span style="color: #00ff66; cursor: pointer; font-weight: bold; font-size: 14px; text-shadow: 0 0 5px #00ff66; padding: 0 5px;" onclick="rmeEntferneEinzelneNotiz(\'' + eintragID + '\');" title="Eintrag erledigt / löschen">✓</span>' +
                           '</div>';
                           
    var kompletterNeuerVerlauf = alterVerlauf + neuerEintragHTML;
    
    var rmeInputFeld = document.getElementById('rme-chat-input');
    var meineEchteSendeID = rmeInputFeld ? rmeInputFeld.getAttribute('data-id') || '0' : '18';
    
    var noteData = new FormData();
    noteData.append('admin_note_action', 'save_note');
    noteData.append('note_content', neuerText);
    noteData.append('note_history', kompletterNeuerVerlauf);
    noteData.append('user_id', meineEchteSendeID);
    noteData.append('user_name', saubererName);
    
    fetch('rme_wunsch_save.php', {
        method: 'POST',
        body: noteData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            if (statusLabel) {
                statusLabel.innerHTML = "✅ Gespeichert!";
                statusLabel.style.color = "#00ff00";
                statusLabel.style.borderColor = "#00ff00";
            }
            if (historyBox) {
                historyBox.innerHTML = kompletterNeuerVerlauf;
                historyBox.scrollTop = historyBox.scrollHeight;
            }
            inputField.value = "";
            inputField.focus();
            
            setTimeout(function() { 
                if (statusLabel.innerHTML === "✅ Gespeichert!") {
                    statusLabel.innerHTML = "Bereit"; 
                    statusLabel.style.color = "#888888";
                    statusLabel.style.borderColor = "#252525";
                }
            }, 2000);
        }
    })
    .catch(error => {
        console.error("Notiz-Sende-Fehler:", error);
    });
}
function rmeEntferneEinzelneNotiz(eintragID) {
    var element = document.getElementById(eintragID);
    if (!element) return;
    
    // Kurze Sicherheitsabfrage, ob dieser Punkt wirklich weg kann
    if (!confirm("Diesen Eintrag als erledigt markieren und löschen?")) return;
    
    // Element aus der Ansicht löschen
    element.remove();
    
    var historyBox = document.getElementById('rme-admin-note-history');
    var statusLabel = document.getElementById('rme-note-status');
    var nameInput = document.getElementById('rme-hidden-admin-name');
    var saubererName = nameInput ? nameInput.value.trim() : 'Admin';
    
    // Falls jetzt gar nichts mehr drin steht, setzen wir den Standardtext
    var bereinigterVerlauf = historyBox.innerHTML.trim();
    if (bereinigterVerlauf === "") {
        bereinigterVerlauf = '<span style="color:#666;">Noch keine Protokolleinträge vorhanden.</span>';
    }
    
    if (statusLabel) {
        statusLabel.innerHTML = "⏳ Aktualisiert...";
        statusLabel.style.color = "#00ffcc";
        statusLabel.style.borderColor = "#00ffcc";
    }
    
    var rmeInputFeld = document.getElementById('rme-chat-input');
    var meineEchteSendeID = rmeInputFeld ? rmeInputFeld.getAttribute('data-id') || '0' : '18';
    
    // Wir schicken den übrig gebliebenen Verlauf zurück ans normale Speicherskript
    var noteData = new FormData();
    noteData.append('admin_note_action', 'save_note');
    noteData.append('note_content', 'Eintrag entfernt');
    noteData.append('note_history', bereinigterVerlauf);
    noteData.append('user_id', meineEchteSendeID);
    noteData.append('user_name', saubererName);
    
    fetch('rme_wunsch_save.php', {
        method: 'POST',
        body: noteData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            if (statusLabel) {
                statusLabel.innerHTML = "✅ Aktualisiert!";
                statusLabel.style.color = "#00ff00";
                statusLabel.style.borderColor = "#00ff00";
            }
            setTimeout(function() { 
                if (statusLabel.innerHTML === "✅ Aktualisiert!") {
                    statusLabel.innerHTML = "Bereit"; 
                    statusLabel.style.color = "#888888";
                    statusLabel.style.borderColor = "#252525";
                }
            }, 1500);
        }
    });
}

function rmeLeereNotizProtokoll() {
    if (!confirm("Möchtest du das gesamte Team-Protokoll wirklich unwiderruflich löschen?")) return;
    
    var statusLabel = document.getElementById('rme-note-status');
    var historyBox = document.getElementById('rme-admin-note-history');
    var nameInput = document.getElementById('rme-hidden-admin-name');
    var saubererName = nameInput ? nameInput.value.trim() : 'Admin';
    
    if (statusLabel) {
        statusLabel.innerHTML = "🗑️ Löscht...";
        statusLabel.style.color = "#ff0055";
        statusLabel.style.borderColor = "#ff0055";
    }
    
    var rmeInputFeld = document.getElementById('rme-chat-input');
    var meineEchteSendeID = rmeInputFeld ? rmeInputFeld.getAttribute('data-id') || '0' : '18';
    
    var noteData = new FormData();
    noteData.append('admin_note_action', 'clear_note'); // Eigene Lösch-Aktion für das Backend
    noteData.append('user_id', meineEchteSendeID);
    noteData.append('user_name', saubererName);
    
    fetch('rme_wunsch_save.php', {
        method: 'POST',
        body: noteData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            if (statusLabel) {
                statusLabel.innerHTML = "✅ Gelöscht!";
                statusLabel.style.color = "#00ff00";
                statusLabel.style.borderColor = "#00ff00";
            }
            
            // Ansicht sofort live leeren
            if (historyBox) {
                historyBox.innerHTML = '<span style="color:#666;">Noch keine Protokolleinträge vorhanden.</span>';
            }
            
            setTimeout(function() { 
                if (statusLabel.innerHTML === "✅ Gelöscht!") {
                    statusLabel.innerHTML = "Bereit"; 
                    statusLabel.style.color = "#888888";
                    statusLabel.style.borderColor = "#252525";
                }
            }, 2000);
        }
    })
    .catch(error => {
        console.error("Notiz-Lösch-Fehler:", error);
        if (statusLabel) {
            statusLabel.innerHTML = "❌ Fehler!";
            statusLabel.style.color = "#ff0033";
            statusLabel.style.borderColor = "#ff0033";
        }
    });
}

// 🔥 LIVE-ERSETZUNG FÜR DEN FARBIGEN ADMIN-NAMEN IM CHAT-VERLAUF
$(document).ready(function() {
    // Erstellt einen Beobachter, der neue Chat-Nachrichten sofort beim Erscheinen scannt
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes && mutation.addedNodes.length > 0) {
                // Wir durchsuchen das Chat-Fenster nach dem BB-Code Text
                $('.rme-chat-text-content, .rme-message-body, .rme-chat-msg-row').each(function() {
                    var inhalt = $(this).html();
                    if (inhalt && inhalt.indexOf('[color=') !== -1) {
                        // Ersetzt den BB-Code live im Browser durch ein farbiges HTML-Span
                        var saubererInhalt = inhalt.replace(/\[color=(.*?)\](.*?)\[\/color\]/gi, '<span style="color:$1;">$2</span>');
                        $(this).html(saubererInhalt);
                    }
                });
            }
        });
    });

    // Falls das Chat-Fenster-Element existiert, hängen wir den Beobachter an
    var chatBox = document.getElementById('rme-chat-messages-container') || document.getElementById('rme-chat-body');
    if (chatBox) {
        observer.observe(chatBox, { childList: true, subtree: true });
    }
});
function rmeSpeichereNeonFarbe(hexCode) {
    var statusLabel = document.getElementById('rme-neon-color-status');
    if (statusLabel) { 
        statusLabel.innerHTML = "⏳ Speichert..."; 
        statusLabel.style.color = "#00ffcc"; 
    }
    
    // Holt deine ID aus dem Chat-Eingabefeld (wie bei den Admin-Notizen)
    var rmeInputFeld = document.getElementById('rme-chat-input');
    var meineEchteSendeID = rmeInputFeld ? rmeInputFeld.getAttribute('data-id') || '0' : '0';
    
    var colorData = new FormData();
    colorData.append('admin_note_action', 'save_neon_color');
    colorData.append('user_id', meineEchteSendeID);
    colorData.append('neon_color', hexCode);
    
    fetch('rme_wunsch_save.php', {
        method: 'POST',
        body: colorData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            if (statusLabel) { 
                statusLabel.innerHTML = "✅ Farbe aktiv!"; 
                statusLabel.style.color = "#00ff00"; 
            }
            // Schließt das kleine Menü nach 1 Sekunde automatisch
            setTimeout(function() { 
                var popup = document.getElementById('rme-neon-color-popup');
                if (popup) popup.style.display = 'none';
                if (statusLabel) { statusLabel.innerHTML = "Bereit"; statusLabel.style.color = "#666"; }
            }, 1000);
        }
    })
    .catch(error => {
        console.error("Farb-Sende-Fehler:", error);
        if (statusLabel) { statusLabel.innerHTML = "❌ Fehler!"; statusLabel.style.color = "#ff0033"; }
    });
}
function rmeStarteLiveVoting() {
    var frage = document.getElementById('rme_vote_question').value.trim();
    var opt1 = document.getElementById('rme_vote_opt1').value.trim();
    var opt2 = document.getElementById('rme_vote_opt2').value.trim();
    var opt3 = document.getElementById('rme_vote_opt3').value.trim();
    var statusLabel = document.getElementById('rme-voting-status-text');
    
    if (frage === "" || opt1 === "" || opt2 === "") {
        alert("Bitte fülle mindestens die Frage sowie Antwort 1 und 2 aus!");
        return;
    }
    
    if (statusLabel) { statusLabel.innerHTML = "⏳ Zünde Umfrage..."; statusLabel.style.color = "#ffcc00"; }
    
    var rmeInputFeld = document.getElementById('rme-chat-input');
    var meineEchteSendeID = rmeInputFeld ? rmeInputFeld.getAttribute('data-id') || '0' : '0';
    
    var voteData = new FormData();
    voteData.append('admin_note_action', 'start_new_voting');
    voteData.append('user_id', meineEchteSendeID);
    voteData.append('question', frage);
    voteData.append('opt1', opt1);
    voteData.append('opt2', opt2);
    voteData.append('opt3', opt3);
    
    fetch('rme_wunsch_save.php', {
        method: 'POST',
        body: voteData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            if (statusLabel) { statusLabel.innerHTML = "✅ Umfrage läuft live!"; statusLabel.style.color = "#00ff00"; }
            setTimeout(function() { document.getElementById('rme-voting-admin-popup').style.display = 'none'; }, 1000);
        }
    });
}

function rmeBeendeLiveVoting() {
    if (!confirm("Möchtest du das aktuelle Voting wirklich schließen und das Endergebnis einfrieren?")) return;
    var statusLabel = document.getElementById('rme-voting-status-text');
    
    var voteData = new FormData();
    voteData.append('admin_note_action', 'close_current_voting');
    
    fetch('rme_wunsch_save.php', {
        method: 'POST',
        body: voteData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            if (statusLabel) { statusLabel.innerHTML = "🛑 Umfrage beendet!"; statusLabel.style.color = "#ff0055"; }
            setTimeout(function() { document.getElementById('rme-voting-admin-overlay').style.display = 'none'; }, 1000);

        }
    });
}
function rmeSendeStimmeLive(votingId, optionNummer, umfrageIstVorbei) {
    // Wenn die Umfrage vorbei ist, kommt sofort die Sperr-Meldung!
    if (umfrageIstVorbei === true || umfrageIstVorbei === "true") {
        alert("Diese Umfrage wurde vom Moderator beendet. Du kannst leider nicht mehr abstimmen! 😉");
        return;
    }

    // Schutz vor Mehrfachklicks, solange das Voting läuft
    if (localStorage.getItem('rme_voted_' + votingId)) {
        alert("Du hast bei dieser Umfrage bereits abgestimmt! 😉");
        return;
    }

    var voteData = new FormData();
    voteData.append('admin_note_action', 'submit_user_vote');
    voteData.append('voting_id', votingId);
    voteData.append('option_num', optionNummer);

    fetch('rme_wunsch_save.php', {
        method: 'POST',
        body: voteData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            localStorage.setItem('rme_voted_' + votingId, 'true');
            // Aktualisiert die Seite kurz im Hintergrund, um die neue Stimme sofort anzuzeigen
            location.reload();
        }
    });
}

function rmeLoescheVotingKomplett() {
    if (!confirm("Möchtest du das Endergebnis jetzt endgültig aus der Datenbank und vom Bildschirm löschen?")) return;
    var statusLabel = document.getElementById('rme-voting-status-text');
    
    if (statusLabel) {
        statusLabel.innerHTML = "🗑️ Löscht Umfrage...";
        statusLabel.style.color = "#ff5500";
    }
    
    var voteData = new FormData();
    voteData.append('admin_note_action', 'clear_current_voting');
    
    fetch('rme_wunsch_save.php', {
        method: 'POST',
        body: voteData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            if (statusLabel) {
                statusLabel.innerHTML = "✅ Datenbank bereinigt!";
                statusLabel.style.color = "#00ff66";
            }
            // Schließt das Admin-Overlay und lädt die Hauptseite neu, damit das Banner verschwindet
            setTimeout(function() {
                var overlay = document.getElementById('rme-voting-admin-overlay');
                if (overlay) overlay.style.display = 'none';
                if (statusLabel) { statusLabel.innerHTML = "Bereit"; statusLabel.style.color = "#666"; }
                
                // 🔥 LIVE-RELOAD: Lädt den Chat neu -> Banner sieht die leere Tabelle und verschwindet sofort!
                location.reload();
            }, 1000);
        }
    })
    .catch(error => {
        console.error("Fehler beim Löschen des Votings:", error);
    });
}
function rmeStarteLiveQuiz() {
    var rmeInputFeld = document.getElementById('rme-chat-input');
    var meineEchteSendeID = rmeInputFeld ? rmeInputFeld.getAttribute('data-id') || '0' : '0';
    
    var quizData = new FormData();
    quizData.append('admin_note_action', 'start_quiz');
    quizData.append('user_id', meineEchteSendeID);
    
    fetch('rme_wunsch_save.php', {
        method: 'POST',
        body: quizData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            console.log("Quiz erfolgreich gestartet!");
            if (typeof ladeQuizBotFrage === 'function') { ladeQuizBotFrage(); }
        }
    });
}


function ladeQuizBotFrage() {
    if (window.rmeIchBinGekickt === true) return;

    // 🔥 DER FIX: Greift direkt im selben Ordner zu (ohne doppelten Pfad!)
    fetch('chat_get_quiz_box.php?t=' + Date.now())
    .then(function(r) { 
        if (!r.ok) { throw new Error("Datei nicht gefunden"); }
        return r.text(); 
    })
    .then(function(htmlDaten) {
        var box = document.getElementById('rme-quizbot-sticky-box');
        if (box) {
            if (htmlDaten.trim() !== "") {
                box.innerHTML = htmlDaten;
                box.style.display = 'block'; // Füllt die Layout-Lücke live aus!
            } else {
                box.style.display = 'none';  // Schließt die Lücke, wenn kein Quiz läuft
            }
        }
    })
    .catch(function(e) {
        console.log("Quiz-Laden übersprungen:", e);
    });
}

// Wartet, bis der Chat bereit ist, und prüft dann alle 4 Sekunden unbemerkt
document.addEventListener('DOMContentLoaded', function() {
    ladeQuizBotFrage();
    setInterval(ladeQuizBotFrage, 4000); 
});


function rmeZuendeDJZapper() {
    var rmeInputFeld = document.getElementById('rme-chat-input');
    var rmeSendeBtn = document.getElementById('rme-chat-submit') || document.querySelector('.rme-send-btn');
    
    if (!rmeInputFeld || rmeInputFeld.value.trim() === "") {
        alert("Bitte tippe zuerst eine wichtige Ansage in dein normales Chat-Textfeld ein, bevor du den Zapper drückst! 😉");
        return;
    }
    
    var ansageText = rmeInputFeld.value.trim();
    
    // Radikale Code-Reinigung von alten BB-Codes
    ansageText = ansageText.replace(/\[style=[^\]]*\]/gi, "").replace(/\[\/style\]/gi, "").trim();
    if (ansageText === "") return;

    // 🔥 REPARIERT: Den doppelten fetch() gelöscht! Der Sound wird unfehlbar und 
    // exakt einmalig durch die neue Datenbank-Sperre im Backend gezündet!

    // Schreibt den bewährten BB-Code wieder direkt in die Schreibleiste
    rmeInputFeld.value = "[zapp]" + ansageText + "[/zapp]";
    
    // Simuliert eine echte Tastatur-Eingabe, damit das CMS-Sende-Skript aufwacht!
    rmeInputFeld.dispatchEvent(new Event('input', { bubbles: true }));
    rmeInputFeld.focus();
    
    // Nachricht über euer Standard-System absenden
    setTimeout(function() {
        if (rmeSendeBtn) {
            rmeSendeBtn.click();
        } else {
            var ereignis = new KeyboardEvent('keydown', {'key': 'Enter', 'keyCode': 13, 'bubbles': true});
            rmeInputFeld.dispatchEvent(ereignis);
        }
    }, 50);
    
    // Zwingt das Nachrichtenfenster nach 300 Millisekunden zu einem kurzen Update
    setTimeout(function() {
        var chatIframe = document.getElementById('rme-chat-iframe') || document.querySelector('iframe') || document.getElementById('chat_frame');
        if (chatIframe && chatIframe.contentWindow) {
            chatIframe.contentWindow.location.reload();
        } else {
            if (typeof rmeUpdateChatLiveVerlauf === 'function') { rmeUpdateChatLiveVerlauf(); }
            else if (typeof rme_load_chat === 'function') { rme_load_chat(); }
        }
    }, 300);
}


// =========================================================================
// VERSCHMOLZEN & REPARIERT: DIE UNZERSTÖRBARE GAMING-ZENTRALE (FRONTEND)
// =========================================================================
function rmeSendeGamingAktion(spielTyp) {
    var rmeInputFeld = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    var rmeSendeBtn = document.getElementById('rme-chat-submit') || document.querySelector('.rme-chat-send-btn') || document.querySelector('.rme-send-btn');
    
    if (!rmeInputFeld) return;
    
    var meinLiveName = rmeInputFeld.getAttribute('data-user') || "Gast";
    var meineEchteID = rmeInputFeld.getAttribute('data-id') || rmeInputFeld.getAttribute('data-uid') || "0";
    
    // 🔊 FALL A: LAUTLOSER HÖRER-SOUNDBOARD FUNK (EXKRESSTAKT AN play_sound.php)
    if (spielTyp.startsWith('hsound_')) {
        var reinerSoundBefehl = spielTyp.replace('hsound_', '');
        // Weiche für die Trommel -> Wird im Backend als hoerer_tusch gesucht
        if (reinerSoundBefehl === 'trommel') { reinerSoundBefehl = 'tusch'; }
        
        // Direkter, lautloser POST-Funk ohne Textbox-Müll
        $.post('play_sound.php', {
            hoerer_direct_trigger: reinerSoundBefehl,
            hoerer_user_name: meinLiveName,
            hoerer_user_id: meineEchteID
        }, function(response) {
            console.log("🔊 Hörer-Sound '" + reinerSoundBefehl + "' erfolgreich an play_sound übergeben!");
        }, 'json');
        
        // Popup lautlos schließen und Funktion beenden!
        var popup = document.getElementById('rme-universal-gaming-popup');
        if (popup) popup.style.display = 'none';
        return;
    }

// 🎯 FALL 1 & 2: Würfeln & Münze (Injektor für die Schreibleiste)
    if (spielTyp === 'roll_dice') {
        rmeInputFeld.value = "🎲 [SPIEL]: " + meinLiveName + " schwingt den Becher und würfelt eine " + (Math.floor(Math.random() * 6) + 1) + "!";
    } else if (spielTyp === 'flip_coin') {
        var muenze = Math.random() < 0.5 ? "🪙 KOPF" : "👑 ZAHL";
        rmeInputFeld.value = "🪙 [SPIEL]: " + meinLiveName + " wirft eine Münze in die Luft: " + muenze + "!";
    } 
    // 🎯 FALL 3: Das Hörer-Glücksrad (Text-Fallback falls benötigt)
    else if (spielTyp === 'spin_wheel_text') { 
        rmeInputFeld.value = "/rad"; 
    }
    // 🎯 FALL 4: Tic-Tac-Toe
    else if (spielTyp === 'start_ttt') { 
        rmeInputFeld.value = "/ttt"; 
    }
    // 🎯 REPARIERT: Fängt die neuen Buttons ab und drückt vollautomatisch ENTER!
    else if (spielTyp === 'play_slots') { 
        rmeInputFeld.value = "/slot"; 
    }
    else if (spielTyp === 'guess_number') { 
        rmeInputFeld.value = "/zahl"; 
    }
    // 🎯 REPARIERT VOM CHEF persönlich: Als sauberes else if, damit die Kette fluchtet!
    else if (spielTyp.startsWith('ask_oracle|')) {
        var extrahierteFrage = spielTyp.replace('ask_oracle|', '');
        rmeInputFeld.value = "/orakel " + extrahierteFrage;
    }
    // 🎯 REPARIERT: Schreibt den Karten-Startbefehl in die Schreibleiste
    else if (spielTyp === 'start_card_game') { 
        rmeInputFeld.value = "/karte"; 
    }
    else if (spielTyp === 'danke') { 
        rmeInputFeld.value = "/danke"; 
    }
    else if (spielTyp.startsWith('spiegel_gruss|')) {
        var extrahierterGruss = spielTyp.replace('spiegel_gruss|', '');
        rmeInputFeld.value = "/spiegel " + extrahierterGruss;
    }
    // 🎯 NEU: VIP Farb-Roulette in die Kette integriert!
    else if (spielTyp === 'vip_roulette') {
        rmeInputFeld.value = "/roulette";
    }
    // =========================================================================
    // 🎰 REPARIERT: LAUTLOSER GLÜCKSRAD-FUNK (Jetzt perfekt eingegliedert!)
    // =========================================================================
    else if (spielTyp === 'spin_wheel') {
        // 🚀 GLÜCKSRAD-EXPRESS: Wir funken direkt an die play_sound.php!
        $.post('play_sound.php', { 
            hoerer_wheel_trigger: 'true',
            hoerer_user_name: meinLiveName,
            hoerer_user_id: meineEchteID
        }, function(response) {
            console.log("🎰 Glücksrad-Zündung erfolgreich über play_sound übergeben!");
        }, 'json');
        
        var popup = document.getElementById('rme-universal-gaming-popup');
        if (popup) popup.style.display = 'none';
        return; // Verhindert, dass der leere Chat abgeschickt wird
    }

    // Drückt vollautomatisch auf ENTER / SENDEN für Text-Befehle (Würfel, Münze, TTT, Slots, Danke, Roulette)
    if (rmeSendeBtn) {
        rmeSendeBtn.click();
    } else {
        var ereignis = new KeyboardEvent('keydown', {'key': 'Enter', 'keyCode': 13, 'bubbles': true});
        rmeInputFeld.dispatchEvent(ereignis);
    }
    
    // Schließt das universelle Spiele-Popup automatisch nach dem Klick
    var popup = document.getElementById('rme-universal-gaming-popup');
    if (popup) popup.style.display = 'none';
}

// =========================================================================
// ⏱️ METRIC-NEON: JAVASCRIPT-FUNKTIONEN FÜR DEN LIVE-COUNTDOWN
// =========================================================================
function rmeStartenCountdown() {
    var secsInput = document.getElementById('rme_countdown_secs');
    if (!secsInput || secsInput.value.trim() === "") {
        alert("Bitte gib eine Sekundenzahl ein! 😉");
        return;
    }
    
    // Holt Deine ID sicher aus dem Textfeld
    var rmeInputFeld = document.getElementById('rme-chat-input');
    var meineEchteSendeID = rmeInputFeld ? rmeInputFeld.getAttribute('data-id') || '0' : '0';
    
    var voteData = new FormData();
    voteData.append('admin_note_action', 'start_countdown');
    voteData.append('user_id', meineEchteSendeID); // 🔥 NEU: Schickt Deine ID zur Autorisierung mit!
    voteData.append('seconds', secsInput.value.trim());
    
    fetch('rme_wunsch_save.php', {
        method: 'POST',
        body: voteData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            location.reload(); 
        } else {
            alert("Fehler: " + (data.message || "Der Countdown konnte nicht gestartet werden."));
        }
    })
    .catch(error => console.error("Countdown-Fehler:", error));
}

// DER LIVE-SEKUNDEN-TICKER FÜR DIE HÖRER
function rmeInitLiveCountdown(restlicheSekunden) {
    console.log("⏱️ COUNTDOWN-TICKER: Gestartet mit " + restlicheSekunden + " Sekunden.");
    
    // Wir holen uns das Zifferblatt absolut sicher
    var timerLabel = document.getElementById('rme-countdown-clock');
    if (!timerLabel) {
        console.error("❌ COUNTDOWN-FEHLER: Zifferblatt 'rme-countdown-clock' nicht gefunden!");
        return;
    }
    
    // Sicherstellen, dass wir mit einer echten Zahl rechnen
    var sekunden = parseInt(restlicheSekunden, 10);
    if (isNaN(sekunden) || sekunden <= 0) return;

    // Erster Sofort-Druck auf das Zifferblatt, damit nicht "--:--" dasteht
    var initialMins = Math.floor(sekunden / 60);
    var initialSecs = sekunden % 60;
    if (initialMins < 10) initialMins = "0" + initialMins;
    if (initialSecs < 10) initialSecs = "0" + initialSecs;
    timerLabel.innerHTML = initialMins + ":" + initialSecs;
    
    // Der unaufhaltsame Sekundentakt
    var interval = setInterval(function() {
        sekunden--;
        
        // WENN DIE ZEIT VORBEI IST: Automatisch aufräumen ohne F5-Zwang!
        if (sekunden <= 0) {
            clearInterval(interval);
            timerLabel.innerHTML = "00:00";
            
            var wrapper = document.getElementById('rme-countdown-banner-wrapper');
            if (wrapper) wrapper.style.display = 'none';
            
            console.log("⏱️ COUNTDOWN: Zeit abgelaufen! Rufe automatischen Reload auf...");
            
            // 🔥 UNZERSTÖRBAR: Zwingt den Browser nach Ablauf zum automatischen Refresh!
            setTimeout(function() {
                location.reload();
            }, 500);
            return;
        }
        
        // Berechnet Minuten und Sekunden (z.B. 04:59)
        var mins = Math.floor(sekunden / 60);
        var secs = sekunden % 60;
        
        if (mins < 10) mins = "0" + mins;
        if (secs < 10) secs = "0" + secs;
        
        // Schreibt die Zeit live ins HTML
        timerLabel.innerHTML = mins + ":" + secs;
    }, 1000);
}
function stoppeAlleIntervalle() {
    // Falls Timer in Variablen wie 'chatTimer' gespeichert sind:
    if (typeof chatTimer !== 'undefined') clearInterval(chatTimer);
    console.log("✔ AJAX-Schleifen gestoppt.");
}

function zeigeKickBanner() {
    // Optional: DOM manipulieren, um das Banner anzuzeigen
    document.body.innerHTML = '<div class="kick-banner">Zugriff verweigert</div>';
}

// =========================================================================
// MASTER-INTRO-TRIGGERSYSTEM (KUGELSICHER ÜBER PLAY_SOUND ANPHPHESTEUERT) 🎉
// =========================================================================
document.addEventListener("DOMContentLoaded", function() {
    var inputFeld = document.getElementById('rme-chat-input');
    var meineEchteSendeID = inputFeld ? parseInt(inputFeld.getAttribute('data-id') || '0') : 0;

    if (meineEchteSendeID > 0 && meineEchteSendeID < 2000) {
        console.log("🎵 INTRO-SYSTEM: Starte unzerstörbaren Funk für User " + meineEchteSendeID);
        
        // Echo-Killer aktivieren
        window.rmeIchHabeDiesenSoundSelbstGezuendet = "intro" + meineEchteSendeID + ".mp3";

        // 1. Zündet das Intro sofort live bei Dir selbst aus dem DB-BLOB
        if (typeof rmeFuehreIntrozuendungAus === 'function') {
            rmeFuehreIntrozuendungAus(meineEchteSendeID);
        }
        
        // 2. Schiebt den Funk DIREKT über play_sound.php in den Chat-Äther!
        // Das kann im Gegensatz zum Backend niemals durch CMS-Rechte blockiert werden!
        setTimeout(function() {
            var xhr = new XMLHttpRequest();
            xhr.open("GET", "play_sound.php?action=funk_intro&user_id=" + meineEchteSendeID + "&t=" + new Date().getTime(), true);
            xhr.send();
        }, 600);
    }
});
// =========================================================================
// 🔥 NEU: DIRECT DATABASE SMILEY KILLER (NUR FÜR DIE SENDERLEITUNG)
// =========================================================================
function rmeDeleteSmileyFromPopup(smileyId, event) {
    // Verhindert, dass der Doppelklick oder Klick auf das Bild im Hintergrund ausgelöst wird
    if(event) { event.stopPropagation(); event.preventDefault(); }
    
    if (confirm("Möchtest du diesen Smiley wirklich restlos aus der Datenbank löschen?")) {
        // Wir senden den Löschbefehl per POST an Deinen Handler
        $.ajax({
            url: 'rme_smilies_handler.php?action=delete_database_smiley',
            type: 'POST',
            data: { 
                id: smileyId,
                chef_id: 18, // Identifiziert Dich als Chef beim Handler
                chef_name: 'DJ-Tomjac'
            },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    // Blendat die Box im Popup sofort elegant aus!
                    $('#rme-db-smbox-' + smileyId).fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    alert("Fehler beim Löschen: " + response.message);
                }
            },
            error: function() {
                alert("Der Server antwortet nicht. Bitte Verbindung prüfen.");
            }
        });
    }
}
// =========================================================================
// 🔥 REPARIERT: TEXT LÖSCHEN ÜBER DEN UNFEHLBAREN SMILEY-HANDLER
// =========================================================================
function rmeDeleteSingleText(msgId, event) {
    if(event) { event.stopPropagation(); event.preventDefault(); }
    
    if (confirm("Möchtest du diesen Text wirklich unwiderruflich aus der Datenbank löschen?")) {
        $.ajax({
            url: 'rme_smilies_handler.php?action=delete_archive_msg', // 🎯 FIX: Funkt jetzt Deinen funktionierenden Handler an!
            type: 'POST',
            data: { 
                id: msgId,
                chef_id: 18,
                chef_name: 'DJ-Tomjac'
            },
            dataType: 'json',
            success: function(response) {
                if (response && response.status === 'success') {
                    // Verpufft sofort weich vom Bildschirm
                    $('#rme-arch-row-' + msgId).fadeOut(300, function() {
                        $(this).remove();
                    });
                } else {
                    alert("Sicherheits-Blockade: Löschen verweigert oder ID ungültig.");
                }
            },
            error: function() {
                alert("Verbindung zum Smiley-Handler fehlgeschlagen.");
            }
        });
    }
}

// 1. Sendet die Einladung an den Handler (Mit korrekter ID-Schließung)
function rmeFordereSpielerHeraus(zielGegner) {
    // Falls der Name direkt übergeben wurde, nutzen wir ihn, sonst aus dem Feld
    var gegner = zielGegner || (document.getElementById('rme_ttt_opponent') ? document.getElementById('rme_ttt_opponent').value.trim() : "");
    if(!gegner || gegner === "") { alert("Bitte wähle einen Gegner aus!"); return; }
    
    // Wir holen Deinen Sendernamen aus dem Inputfeld
    var rmeInputFeld = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    var meinEchterName = rmeInputFeld ? (rmeInputFeld.getAttribute('data-user') || 'DJ-Tomjac') : 'DJ-Tomjac';

    $.post('rme_smilies_handler.php?action=ttt_invite', { 
        opponent: gegner,
        live_user: meinEchterName, 
        chef_name: meinEchterName, 
        chef_id: 18
    }, function(res) {
        if(res.status === 'success') {
            alert("Einladung an " + gegner + " wurde rausgesendet! Warte auf Antwort...");
            
            // 🔥 ID-FIX: Schließt nun das korrekte, universelle Hauptmenü!
            if(document.getElementById('rme-universal-gaming-popup')) { 
                document.getElementById('rme-universal-gaming-popup').style.display = 'none'; 
            }
            
            // 🔥 SEPARAT-FIX: Schließt zeitgleich das TTT-Auswahlfenster!
            if(document.getElementById('rme-ttt-lobby-popup')) { 
                document.getElementById('rme-ttt-lobby-popup').style.display = 'none'; 
            }
        } else { 
            alert(res.message); 
        }
    }, 'json').fail(function() {
        alert("Fehler beim Senden der Einladung. Der Handler antwortet nicht richtig.");
    });
}

// 2. Antwort-Zentrale (Annehmen, Ablehnen, Schließen, Verlassen - MIT SPION-SPERRE)
function rmeAntworteAufEinladung(gameId, antwort) {
    var rmeInputFeld = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    var meinEchterLiveName = rmeInputFeld ? (rmeInputFeld.getAttribute('data-user') || "Gast") : "Gast";

    console.log("🎮 Spiele-Aktion ausgeführt: " + antwort + " für Game-ID: " + gameId);

    // 🔥 DIE SPERRE AKTIVIEREN: Wir frieren den Hintergrund-Spion sofort ein,
    // damit er während der Server-Anfrage kein zweites Fenster erzeugen kann!
    rmeTttSpionSperre = true;

    // Wenn wir ablehnen, schließen oder verlassen, löschen wir die Box sofort vom Schirm
    if (antwort !== 'accept') {
        $('#rme-ttt-popup-box').remove();
    } else {
        // Beim Annehmen machen wir sie unsichtbar, um Doppel-Klicks zu verhindern
        if (document.getElementById('rme-ttt-popup-box')) {
            document.getElementById('rme-ttt-popup-box').style.visibility = 'hidden';
        }
    }

    // Wir funken den Befehl an den Handler
    $.post('rme_smilies_handler.php?action=ttt_respond', { 
        game_id: gameId, 
        response: antwort,
        live_user: meinEchterLiveName 
    }, function(res) {
        // Bei Ablehnung/Verlassen restlos löschen
        if (antwort !== 'accept') {
            $('#rme-ttt-popup-box').remove();
        }
    }, 'json').always(function() {
        // Dieser Block läuft IMMER (egal ob Erfolg oder Fehler)
        setTimeout(function() {
            // Wir vernichten das alte Einladungs-HTML komplett aus dem DOM
            $('#rme-ttt-popup-box').remove();
            
            // Erst jetzt geben wir den Spion-Loop im Hintergrund wieder frei
            rmeTttSpionSperre = false;
            
            // Und holen uns sofort das brandneue, frische Spielfeld
            if (typeof rmeTttSpion === 'function') { rmeTttSpion(); }
        }, 150); // 150 Millisekunden Puffer, damit die DB den Status auf 'active' setzen konnte
    });
}


// 3. Klicker auf dem Spielfeld
function rmeTttKlick(gameId, index) {
    var rmeInputFeld = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    var meinEchterLiveName = rmeInputFeld ? (rmeInputFeld.getAttribute('data-user') || "Gast") : "Gast";
    
    var saubererSpielerName = meinEchterLiveName.replace('_CU', '').replace('_Gast', '').trim();
    console.log("🎲 Sende Zug direkt an den Chat-Core: Feld " + index);

    var formData = new FormData();
    formData.append('message', '/ttt zug ' + index); // Stellt sicher, dass das Backend die Nachricht versteht

    fetch('rme_chat_backend.php?action=send&admin_auth_name=' + encodeURIComponent(saubererSpielerName), {
        method: 'POST',
        body: formData
    })
    .then(function() {
        // 🔥 SOFORT-POLL: Zwingt den Spion, die Box augenblicklich mit den neuen MySQL-Daten zu füttern!
        if (typeof rmeTttSpion === 'function') { rmeTttSpion(); }
        
        // Chat-Verlauf leise aktualisieren, um die Befehlszeile /ttt zug X verschwinden zu lassen
        if (typeof rmeUpdateChatLiveVerlauf === 'function') { rmeUpdateChatLiveVerlauf(); }
    });
}

// =========================================================================
// 📡 UNZERSTÖRBARER SCREEN-ZENTRIERER FÜR DAS HERAUSFORDERUNGS-BANNER
// =========================================================================
function rmeTttSpion() {
    if (typeof rmeTttSpionSperre !== 'undefined' && rmeTttSpionSperre === true) return;
    
    var rmeInputFeld = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    if (!rmeInputFeld) return;
    
    var meinEchterLiveName = rmeInputFeld.getAttribute('data-user') || "Gast";
    
    if (typeof rmeTttSpionSperre !== 'undefined') { rmeTttSpionSperre = true; }

    $.ajax({
        url: 'rme_smilies_handler.php?action=ttt_check',
        type: 'POST',
        data: { live_user: meinEchterLiveName },
        dataType: 'json',
        cache: false,
        success: function(data) {
            if (data && data.html && data.html.trim() !== "") {
                var existierendeBox = document.getElementById('rme-ttt-popup-box');
                if (existierendeBox) {
                    existierendeBox.innerHTML = data.html;
                } else {
                    // 🎯 FIX: Wir erstellen ein freies, mobiles Gehäuse für das Banner!
                    var neuesGehaeuse = document.createElement('div');
                    neuesGehaeuse.id = 'rme-ttt-popup-box';
                    
                    // FIXED + 50vh/50vw zwingt das Banner felsenfest in die Mitte deines Handy-Bildschirms,
                    // genau dorthin, wo das Gaming-Popup auch aufpoppt. Jedes Hochrutschen ist unmöglich!
                    neuesGehaeuse.style.cssText = 'position: fixed !important; top: 50vh !important; left: 50vw !important; transform: translate(-50%, -50%) !important; z-index: 999999999 !important; background: transparent !important; border: none !important; padding: 0 !important; box-shadow: none !important; margin: 0 !important; width: auto !important; height: auto !important; display: block !important;';
                    neuesGehaeuse.innerHTML = data.html;
                    
                    // Wir hängen es direkt ganz oben an den body an, um Handy-Wrapper-Bugs zu umgehen
                    if (document.body.firstChild) {
                        document.body.insertBefore(neuesGehaeuse, document.body.firstChild);
                    } else {
                        document.body.appendChild(neuesGehaeuse);
                    }
                }
            } else {
                $('#rme-ttt-popup-box').remove();
            }
        },
        complete: function() {
            if (typeof rmeTttSpionSperre !== 'undefined') { rmeTttSpionSperre = false; }
        }
    });
}

// =========================================================================
// 📡 UNZERSTÖRBARER, SINGLE-THREADED SPIELE-SPION (SCHÜTZT VOR DOPPEL-BOXEN)
// =========================================================================
var rmeTttSpionSperre = false; // Das Schutzschild gegen die Dauerschleife

function rmeTttSpion() {
    // Falls der Spion bereits läuft oder auf den Server wartet, brechen wir sofort ab!
    if (rmeTttSpionSperre === true) return;
    
    var rmeInputFeld = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    if (!rmeInputFeld) return;
    
    var meinEchterLiveName = rmeInputFeld.getAttribute('data-user') || "Gast";
    
    // Schutzschild aktivieren
    rmeTttSpionSperre = true;

    $.ajax({
        url: 'rme_smilies_handler.php?action=ttt_check',
        type: 'POST',
        data: { live_user: meinEchterLiveName },
        dataType: 'json',
        cache: false,
        success: function(data) {
            if (data && data.html && data.html.trim() !== "") {
                // Wir prüfen extrem streng, ob die Box wirklich schon im HTML existiert
                var existierendeBox = document.getElementById('rme-ttt-popup-box');
                if (existierendeBox) {
                    existierendeBox.innerHTML = data.html;
                } else {
                    // Das Gehäuse ist ein reiner, unsichtbarer Positions-Halter
                    $('body').append('<div id="rme-ttt-popup-box" style="position:fixed !important; top:25% !important; left:50% !important; transform:translateX(-50%) !important; z-index: 99999999 !important; background:transparent !important; border:none !important; padding:0 !important; box-shadow:none !important; margin:0 !important; width:auto !important; height:auto !important;">' + data.html + '</div>');
                }
            } else {
                // Wenn PHP meldet "kein Spiel aktiv", löschen wir die Box rigoros
                $('#rme-ttt-popup-box').remove();
            }
        },
        complete: function() {
            // Erst wenn die Anfrage KOMPLETT abgeschlossen ist, geben wir den Spion wieder frei
            rmeTttSpionSperre = false;
        }
    });
}

// =========================================================================
// 👥 rmeLadeTttOnlineSpieler (REPARIERTER LIVE-ZULAUF FÜR GÄSTE & ADMINS)
// =========================================================================
function rmeLadeTttOnlineSpieler() {
    var selectFeld = document.getElementById('rme_ttt_opponent');
    if (!selectFeld) return;
    
    var sidebar = document.getElementById('chat-online-list');
    if (!sidebar) return;
    
    // Wir merken uns die aktuelle Auswahl gegen das Springen
    var aktuelleAuswahl = selectFeld.value;
    
    var rmeInputFeld = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    var meinEchterLiveName = rmeInputFeld ? (rmeInputFeld.getAttribute('data-user') || "Gast") : "Gast";
    var meinNameLow = meinEchterLiveName.replace('_CU','').replace('_Gast','').trim().toLowerCase();
    
    // Dropdown leeren und Standardwert setzen
    selectFeld.innerHTML = '<option value="">-- Spieler auswählen --</option>';
    
    var userEintraege = sidebar.querySelectorAll('.rme-rgb-username, .rme-rgb-hadmin, .rme-moderator-username, .rme-user-logged, .rme-name-guest, .rme-name-user-list, .rme-gast-id-sidebar');
    var bereitsHinzugefuegt = {};
    var spielerGefunden = 0;
    
    userEintraege.forEach(function(eintrag) {
        var roherName = eintrag.textContent || eintrag.innerText || "";
        roherName = roherName.trim();
        
        // 1. Porentiefe Reinigung von Text-Badges und Suffixen wie im Mod-Dropdown
        var saubererName = roherName.replace('_Gast', '').replace('_CU', '')
                                     .replace('[ADMIN]', '').replace('[MODERATOR]', '')
                                     .replace('[HADMIN]', '').replace('[MOD]', '')
                                     .replace('👤', '').replace('⚡', '').replace('●', '').trim();
        var testNameLow = saubererName.toLowerCase();
        
        if (saubererName === "" || saubererName.length < 3 || testNameLow === "kick" || testNameLow === "bann") { return; }
        if (bereitsHinzugefuegt[testNameLow]) return;
        
        // 2. 🎯 DER SPERR-FIX FÜR GÄSTE & ADMINS:
        // Wir werfen hier NUR noch dich selbst raus (damit du dich nicht selbst forderst).
        // Alle anderen Admins, Moderatoren und DJs bleiben für Gäste voll sichtbar und wählbar!
        if (testNameLow === meinNameLow) { 
            return; 
        }
        
        bereitsHinzugefuegt[testNameLow] = true;
        
        var opt = document.createElement('option'); 
        opt.value = saubererName;
        opt.innerText = saubererName;
        
        // Wiedereinsetz-Engine bei Hintergrund-Aktualisierungen
        if (saubererName === aktuelleAuswahl) { opt.selected = true; }
        
        selectFeld.appendChild(opt);
        spielerGefunden++;
    });
    
    if (spielerGefunden === 0) {
        selectFeld.innerHTML = '<option value="">Keine anderen Spieler online</option>';
    }
}

// Aktualisiert das TTT-Dropdown, wenn der Button im Hauptmenü geklickt wird
$(document).on('click', '.rme-btn-ttt', function() {
    rmeLadeTttOnlineSpieler();
});

// Weg B: Ein MutationObserver lauscht auf deine echte Onlineliste!
// Sobald im Chat ein neuer User oder Gast die Onlineliste betritt oder verlässt,
// wird dein Tic-Tac-Toe Dropdown VOLLAUTOMATISCH und in Echtzeit aktualisiert!
document.addEventListener("DOMContentLoaded", function() {
    var onlineListeGehaeuse = document.getElementById('chat-online-list') || document.getElementById('rme-chat-user-list');
    if (onlineListeGehaeuse) {
        var tttUserObserver = new MutationObserver(function() {
            // Aktualisiert die Liste vollautomatisch, wenn sich die Onlineliste ändert
            rmeLadeTttOnlineSpieler();
        });
        // Lauscht auf das Hinzufügen/Entfernen von User-Elementen
        tttUserObserver.observe(onlineListeGehaeuse, { childList: true, subtree: true });
    }
});

// =========================================================================
// 🔵🔴 VIER GEWINNT: 1. ONLINE-SPIELER LADEN (AUSWAHL-SPEICHERUNG GEGEN SPRINGEN)
// =========================================================================
function rmeLadeV4gOnlineSpieler() {
    var selectFeld = document.getElementById('rme_v4g_opponent');
    if (!selectFeld) return;
    
    var sidebar = document.getElementById('chat-online-list');
    if (!sidebar) return;
    
    // Wir merken uns, welcher Gegner GERADE ausgewählt war gegen das Springen!
    var aktuelleAuswahl = selectFeld.value;
    
    var rmeInputFeld = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    var meinEchterLiveName = rmeInputFeld ? (rmeInputFeld.getAttribute('data-user') || "Gast") : "Gast";
    var meinNameLow = meinEchterLiveName.replace('_CU','').replace('_Gast','').trim().toLowerCase();
    
    selectFeld.innerHTML = '<option value="">-- Spieler auswählen --</option>';
    
    var userEintraege = sidebar.querySelectorAll('.rme-rgb-username, .rme-rgb-hadmin, .rme-moderator-username, .rme-user-logged, .rme-name-guest, .rme-name-user-list, .rme-gast-id-sidebar');
    var bereitsHinzugefuegt = {};
    var spielerGefunden = 0;
    
    userEintraege.forEach(function(eintrag) {
        var roherName = eintrag.textContent || eintrag.innerText || "";
        roherName = roherName.trim();
        
        var saubererName = roherName.replace('_Gast', '').replace('_CU', '')
                                     .replace('[ADMIN]', '').replace('[MODERATOR]', '')
                                     .replace('[HADMIN]', '').replace('[MOD]', '')
                                     .replace('👤', '').replace('⚡', '').replace('●', '').trim();
        var testNameLow = saubererName.toLowerCase();
        
        if (saubererName === "" || saubererName.length < 3 || testNameLow === "kick" || testNameLow === "bann") { return; }
        if (bereitsHinzugefuegt[testNameLow]) return;
        
        // Eigenen Namen filtern, damit man sich nicht selbst fordert
        if (testNameLow === meinNameLow) { return; }
        
        bereitsHinzugefuegt[testNameLow] = true;
        
        var opt = document.createElement('option'); 
        opt.value = saubererName;
        opt.innerText = saubererName;
        
        if (saubererName === aktuelleAuswahl) { opt.selected = true; }
        
        selectFeld.appendChild(opt);
        spielerGefunden++;
    });
    
    if (spielerGefunden === 0) {
        selectFeld.innerHTML = '<option value="">Keine anderen Spieler online</option>';
    }
}

// =========================================================================
// 🔵🔴 VIER GEWINNT: 2. EINLADUNG AN DEN HANDLER SENDEN
// =========================================================================
function rmeFordereV4gSpielerHeraus(zielGegner) {
    var gegner = zielGegner || (document.getElementById('rme_v4g_opponent') ? document.getElementById('rme_v4g_opponent').value.trim() : "");
    if(!gegner || gegner === "") { alert("Bitte wähle einen Gegner aus!"); return; }
    
    var rmeInputFeld = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    var meinEchterName = rmeInputFeld ? (rmeInputFeld.getAttribute('data-user') || 'DJ-Tomjac') : 'DJ-Tomjac';

    $.post('rme_smilies_handler.php?action=v4g_invite', { 
        opponent: gegner,
        live_user: meinEchterName
    }, function(res) {
        if(res.status === 'success') {
            alert("Vier-Gewinnt-Einladung an " + gegner + " wurde rausgesendet! Warte auf Antwort...");
            
            // Schließt das Hauptmenü und das V4G-Auswahlfenster
            if(document.getElementById('rme-universal-gaming-popup')) { document.getElementById('rme-universal-gaming-popup').style.display = 'none'; }
            if(document.getElementById('rme-v4g-lobby-popup')) { document.getElementById('rme-v4g-lobby-popup').style.display = 'none'; }
        } else { 
            alert(res.message); 
        }
    }, 'json').fail(function() {
        alert("Fehler beim Senden der Einladung. Der Handler antwortet nicht richtig.");
    });
}
// =========================================================================
// 🔵🔴 VIER GEWINNT: 3. ANTWORT-ZENTRALE (ANNEHMEN, ABLEHNEN, VERLASSEN)
// =========================================================================
var rmeV4gSpionSperre = false; // Das Schutzschild gegen Doppelboxen

function rmeAntworteAufV4gEinladung(gameId, antwort) {
    var rmeInputFeld = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    var meinEchterLiveName = rmeInputFeld ? (rmeInputFeld.getAttribute('data-user') || "Gast") : "Gast";

    console.log("🔵🔴 V4G Aktion: " + antwort + " für Game-ID: " + gameId);

    // Spion einfrieren, damit er während der Server-Anfrage keine Doppelbox erzeugt
    rmeV4gSpionSperre = true;

    if (antwort !== 'accept') {
        $('#rme-v4g-popup-box').remove();
    } else {
        if (document.getElementById('rme-v4g-popup-box')) {
            document.getElementById('rme-v4g-popup-box').style.visibility = 'hidden';
        }
    }

    $.post('rme_smilies_handler.php?action=v4g_respond', { 
        game_id: gameId, 
        response: antwort,
        live_user: meinEchterLiveName 
    }, function(res) {
        if (antwort !== 'accept') { $('#rme-v4g-popup-box').remove(); }
    }, 'json').always(function() {
        setTimeout(function() {
            $('#rme-v4g-popup-box').remove();
            rmeV4gSpionSperre = false;
            if (typeof rmeV4gSpion === 'function') { rmeV4gSpion(); }
        }, 150); // 150ms Puffer für die Datenbank
    });
}

// =========================================================================
// 📡 UNZERSTÖRBARER FIXED-SCREEN-ZENTRIERER FÜR VIER GEWINNT
// =========================================================================
function rmeV4gSpion() {
    if (typeof rmeV4gSpionSperre !== 'undefined' && rmeV4gSpionSperre === true) return;
    
    var rmeInputFeld = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    if (!rmeInputFeld) return;
    
    var meinEchterLiveName = rmeInputFeld.getAttribute('data-user') || "Gast";
    
    if (typeof rmeV4gSpionSperre !== 'undefined') { rmeV4gSpionSperre = true; }

    $.ajax({
        url: 'rme_smilies_handler.php?action=v4g_check',
        type: 'POST',
        data: { live_user: meinEchterLiveName },
        dataType: 'json',
        cache: false,
        success: function(data) {
            if (data && data.html && data.html.trim() !== "") {
                var existierendeBox = document.getElementById('rme-v4g-popup-box');
                if (existierendeBox) {
                    existierendeBox.innerHTML = data.html;
                } else {
                    // 🎯 DIE UNZERSTÖRBARE KLICK-FREIHEIT:
                    // Wir verzichten auf pointer-events: none! Die Box schaltet Klicks standardmäßig sofort scharf.
                    // Durch position: fixed, top: 50%, left: 50% und translate sitzt sie bombenfest in der Display-Mitte!
                    var neuesGehaeuse = document.createElement('div');
                    neuesGehaeuse.id = 'rme-v4g-popup-box';
                    neuesGehaeuse.style.cssText = 'position: fixed !important; top: 50% !important; left: 50% !important; transform: translate(-50%, -50%) !important; z-index: 999999999 !important; background: transparent !important; border: none !important; padding: 0 !important; box-shadow: none !important; margin: 0 !important; width: auto !important; height: auto !important; display: block !important;';
                    neuesGehaeuse.innerHTML = data.html;
                    
                    // Wir schieben es ganz nach oben in den body, außerhalb jedes Chat-Layout-Bugs!
                    if (document.body.firstChild) {
                        document.body.insertBefore(neuesGehaeuse, document.body.firstChild);
                    } else {
                        document.body.appendChild(neuesGehaeuse);
                    }
                }
            } else {
                $('#rme-v4g-popup-box').remove();
            }
        },
        complete: function() {
            if (typeof rmeV4gSpionSperre !== 'undefined') { rmeV4gSpionSperre = false; }
        }
    });
}

// =========================================================================
// 🔵🔴 VIER GEWINNT: 5. REPARIERTE KLICK-ENGINE (PIXEL-PERFEKT REINGEWACHSEN)
// =========================================================================
function rmeV4gKlick(gameId, spalte) {
    var rmeInputFeld = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    var meinEchterLiveName = rmeInputFeld ? (rmeInputFeld.getAttribute('data-user') || "Gast") : "Gast";
    var saubererSpielerName = meinEchterLiveName.replace('_CU', '').replace('_Gast', '').trim();

    console.log("🔵🔴 V4G: Spalte " + spalte + " angeklickt!");

    var formData = new FormData();
    formData.append('message', '/v4g zug ' + spalte);

    // Nutzt die exakt gleiche, stabile Core-Leitung wie deine Tic-Tac-Toe Arena
    fetch('rme_chat_backend.php?action=send&admin_auth_name=' + encodeURIComponent(saubererSpielerName), {
        method: 'POST',
        body: formData
    })
    .then(function() {
        // Weiches Update: Aktualisiert nur das Spielfeld im Overwindow
        if (typeof rmeV4gSpion === 'function') { rmeV4gSpion(); }
    });
}

// =========================================================================
// ⏱️ DER ZENTRALE TAKTGEBER: STARTET DEN SPION EXAKT ALLE 2 SEKUNDEN
// =========================================================================
if (typeof rmeZentralerV4gTimer === 'undefined') {
    var rmeZentralerV4gTimer = setInterval(function() {
        if (typeof rmeV4gSpion === 'function') { rmeV4gSpion(); }
    }, 2000);
}


// Globaler Kartenspeicher im Browser-RAM
window.rmeAktuellerKartenWert = 0;
window.rmeKartenSiege = 0;
window.rmeKartenNiederlagen = 0;

function rmeStarteKartenPopupDuell() {
    // 🎯 FIX: Hier stand vorhin fälschlicherweise "4=>" - Jetzt sauber korrigiert auf Doppelpunkt!
    var kartenNamen = {2:"2", 3:"3", 4:"4", 5:"5", 6:"6", 7:"7", 8:"8", 9:"9", 10:"10", 11:"Bube 🧑", 12:"Dame 👸", 13:"König 👑", 14:"Ass 🌟"};
    
    // Wir ziehen die erste Karte direkt via Zufall im Browser (spart DB-Last!)
    var startWert = Math.floor(Math.random() * 13) + 2; // 2 bis 14
    window.rmeAktuellerKartenWert = startWert;
    
    // Anzeige aktualisieren
    document.getElementById('rme-aktuelle-karte-anzeige').innerHTML = kartenNamen[startWert];
    document.getElementById('rme-karten-feedback').innerHTML = "Erste Karte liegt! Höher oder Tiefer?";
    document.getElementById('rme-karten-score').innerHTML = "Siege: " + window.rmeKartenSiege + " | Pleiten: " + window.rmeKartenNiederlagen;
    
    // Buttons resetten
    document.getElementById('rme-karten-spiel-buttons').style.display = 'grid';
    document.getElementById('rme-karten-reset-btn').style.display = 'none';
    
    // Panel umschalten
    document.getElementById('rme-karten-start-block').style.display = 'none';
    document.getElementById('rme-karten-arena-block').style.display = 'block';
}

function rmeKartenTippen(tipp) {
    var kartenNamen = {2:"2", 3:"3", 4:"4", 5:"5", 6:"6", 7:"7", 8:"8", 9:"9", 10:"10", 11:"Bube 🧑", 12:"Dame 👸", 13:"König 👑", 14:"Ass 🌟"};
    var alterWert = window.rmeAktuellerKartenWert;
    
    // Neue Karte ziehen (darf nicht dieselbe sein)
    var neuerWert = Math.floor(Math.random() * 13) + 2;
    while (neuerWert === alterWert) {
        neuerWert = Math.floor(Math.random() * 13) + 2;
    }
    
    // Auswerten
    var gewonnen = false;
    if (tipp === 'hoeher' && neuerWert > alterWert) { gewonnen = true; }
    if (tipp === 'tiefer' && neuerWert < alterWert) { gewonnen = true; }
    
    // Punkte im Browser zählen
    if (gewonnen) { window.rmeKartenSiege++; } else { window.rmeKartenNiederlagen++; }
    
    // Neuen Wert speichern & UI im Menü sofort anpassen
    window.rmeAktuellerKartenWert = neuerWert;
    document.getElementById('rme-aktuelle-karte-anzeige').innerHTML = kartenNamen[neuerWert];
    document.getElementById('rme-karten-score').innerHTML = "Siege: " + window.rmeKartenSiege + " | Pleiten: " + window.rmeKartenNiederlagen;
    document.getElementById('rme-karten-spiel-buttons').style.display = 'none';
    document.getElementById('rme-karten-reset-btn').style.display = 'block';
    
    // Das kleine Textfeld im Menü kriegt nur ein kurzes Feedback
    var farbe = (gewonnen) ? '#00ffaa' : '#ff3333';
    var standardText = (gewonnen) ? "🏆 Gewonnen!" : "❌ Leider nein!";
    document.getElementById('rme-karten-feedback').innerHTML = "<span style='color:" + farbe + "; font-weight:bold;'>" + standardText + "</span>";
    
    // 🔍 JETZT DER BLITZ-TUNNEL ZUM SMILEY-HANDLER
    var suchTyp = (gewonnen) ? 'card_win' : 'card_loss';
    var xhr = new XMLHttpRequest();
    
    xhr.open("POST", "rme_smilies_handler.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var response = JSON.parse(xhr.responseText);
                if (response && response.status === 'success' && response.phrase) {
                    
                    // 🛸 POPUP AUFWECKEN UND DIREKT ANSTEUERN!
                    var popupFenster = document.getElementById('rme-karten-banner-popup');
                    var popupTextfeld = document.getElementById('rme-karten-banner-text');
                    
                    if (popupFenster && popupTextfeld) {
                        // Spruch aus der Datenbank einsetzen
                        popupTextfeld.innerHTML = response.phrase;
                        
                        // Rahmenfarbe anpassen (Grün für Sieg, Rot für Verlust)
                        popupFenster.style.borderColor = (gewonnen) ? '#00ffaa' : '#ff3333';
                        popupFenster.style.boxShadow = (gewonnen) ? '0 0 25px rgba(0,255,170,0.5)' : '0 0 25px rgba(255,51,51,0.5)';
                        
                        // Das unsichtbare Fenster auf dem Bildschirm einblenden!
                        popupFenster.style.display = 'block';
                        
                        // ⏱️ AUTO-CLOSE: Nach 5 Sekunden räumt es sich von alleine auf
                        setTimeout(function() {
                            popupFenster.style.display = 'none';
                        }, 8000);
                    }
                }
            } catch(e) {
                console.log("Karten-Spruch konnte nicht im Popup geladen werden.");
            }
        }
    };
    
    // Signal absenden
    xhr.send("action=get_card_phrase&type=" + suchTyp + "&karte=" + encodeURIComponent(kartenNamen[neuerWert]));
}

function rmeKartenNaechsteRunde() {
    // Schaltet einfach die Tipp-Buttons wieder frei, um mit der aktuellen Karte weiterzuspielen!
    document.getElementById('rme-karten-feedback').innerHTML = "Karte liegt! Ist die nächste Karte höher oder tiefer?";
    document.getElementById('rme-karten-spiel-buttons').style.display = 'grid';
    document.getElementById('rme-karten-reset-btn').style.display = 'none';
}

function rmeKartenArenaVerlassen() {
    // Geht zurück ins Hauptmenü des Popups
    document.getElementById('rme-karten-start-block').style.display = 'block';
    document.getElementById('rme-karten-arena-block').style.display = 'none';
}
// 🔥 DER UNZERSCHÖRFBARE KÄFIG-SPRENGER (ZWINGT DEN EINLASS!)
window.rmeErzwingeHartenChatSpurenReset = function() {
    window.rmeIchBinGekickt = false;
    
    // 1. Cookies im Browser-Gedächtnis radikal via JS vernichten
    document.cookie = "rme_saved_kick_time=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
    document.cookie = "rme_saved_guest_name_kick=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;";
    
    if (typeof sessionStorage !== 'undefined') { sessionStorage.clear(); }
    if (typeof localStorage !== 'undefined') { localStorage.removeItem('rme_chat_kick_lock'); }
    
    var cacheBreaker = Math.floor(Math.random() * 99999);
    
    // 2. ⚡ DER TRICK: Wir laden DIE NACKTE CHAT-DATEI DIREKT ohne das CMS!
    // Dadurch kann PHP-Fusion den Reconnect-Parameter nicht mehr abfangen!
    var zielURL = 'rme_chat.php?reconnect=true&cb=' + cacheBreaker;
    
    console.log("🚀 KÄFIG-SPRENGER AKTIVIERT! Direkt-Relaunch zu: " + zielURL);
    
    // 3. Wir sprengen die gesamte Seite und laden den nackten Chat im Hauptfenster
    if (window.top) {
        window.top.location.href = zielURL;
    } else {
        window.location.href = zielURL;
    }
    return true;
};

// =========================================================================
// 🎆 DJ-DANKSAGUNGS-FEUERWERK JETZT MIT MASSIVEM MITTEN-TEXT
// =========================================================================
function zündeDankesFeuerwerk() {
    var canvas = document.getElementById('rme-fireworks-canvas');
    if (!canvas) return;
    
    var ctx = canvas.getContext('2d');
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    canvas.style.display = 'block';
    
    var partikel = [];
    var aktiv = true;

    var neonFarben = [
        '#ff0055', '#00ff66', '#00f0ff', '#ffff00', '#ff00ff', '#ff5722', 
        '#00ffcc', '#ffcc00', '#ff3300', '#9c27b0', '#e91e63', '#4caf50'
    ];

    function explodiere(x, y) {
        var zufallsFarbe = neonFarben[Math.floor(Math.random() * neonFarben.length)];
        for (var i = 0; i < 45; i++) {
            var winkel = Math.random() * Math.PI * 2;
            var tempo = Math.random() * 5 + 2;
            partikel.push({
                x: x, y: y,
                vx: Math.cos(winkel) * tempo,
                vy: Math.sin(winkel) * tempo,
                farbe: zufallsFarbe,
                leben: 1.0,
                schwinden: Math.random() * 0.02 + 0.015
            });
        }
    }

    function animieren() {
        if (!aktiv) return;
        requestAnimationFrame(animieren);
        
        ctx.fillStyle = 'rgba(0, 0, 0, 0.15)';
        var istHell = document.body.classList.contains('rme-light-mode');
        if (istHell) ctx.fillStyle = 'rgba(255, 255, 255, 0.15)';
        
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        // 🎯 NEU: TEXT IN DAS OBERE DRITTEL ZEICHNEN (MITTIG & XL-GROSS)
        ctx.save();
        ctx.font = "bold 60px 'Oswald', Arial, sans-serif";
        ctx.textAlign = "center";
        ctx.textBaseline = "middle";
        
        // Farben je nach Modus anpassen, damit man es immer perfekt liest
        if (istHell) {
            ctx.fillStyle = "#ff5722"; // Radio-Rot im Hellen
            ctx.strokeStyle = "#ffffff";
            ctx.lineWidth = 4;
            ctx.strokeText("Der Moderator sagt Danke!", canvas.width / 2, canvas.height * 0.25);
            ctx.fillText("Der Moderator sagt Danke!", canvas.width / 2, canvas.height * 0.25);
        } else {
            ctx.fillStyle = "#ffff00"; // Giftgelb im Dunkeln für fetten Matrix-Look
            ctx.shadowColor = "#00ff00";
            ctx.shadowBlur = 15; // Lässt den Text magisch glühen!
            ctx.fillText("Der Moderator sagt Danke!", canvas.width / 2, canvas.height * 0.25);
        }
        ctx.restore();

        if (Math.random() < 0.25) {
            explodiere(Math.random() * canvas.width, Math.random() * (canvas.height * 0.6));
        }

        for (var i = partikel.length - 1; i >= 0; i--) {
            var p = partikel[i];
            p.x += p.vx;
            p.y += p.vy;
            p.vy += 0.08;
            p.leben -= p.schwinden;

            if (p.leben <= 0) {
                partikel.splice(i, 1);
                continue;
            }

            ctx.beginPath();
            ctx.arc(p.x, p.y, 3, 0, Math.PI * 2);
            ctx.fillStyle = p.farbe;
            ctx.globalAlpha = p.leben;
            ctx.fill();
        }
        ctx.globalAlpha = 1.0;
    }

    animieren();

    setTimeout(function() {
        aktiv = false;
        partikel = [];
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        canvas.style.display = 'none';
    }, 5000);
}
// =========================================================================
// 🎆 SCHRITT 1: LAUTLOSER DIRECT-ZÜNDER (NUR DIE SENDE-LEITUNG)
// =========================================================================
function sendeFeuerwerkAnAlle(event) {
    if (event && typeof event.preventDefault === 'function') {
        event.preventDefault();
    }

    // 1. Wir zünden es sofort lokal bei dir, damit du siehst, dass der Button gedrückt wurde
    if (typeof zündeDankesFeuerwerk === 'function') { 
        zündeDankesFeuerwerk(); 
    }

    // 2. Wir holen deine echten Admin-Daten aus der Box
    var meinEchtesInput = document.getElementById('rme-chat-input') || document.getElementById('message');
    var authParams = '';
    
    if (meinEchtesInput) {
        var rmeSendeNameAnBackend = meinEchtesInput.getAttribute('data-user') || 'DJ-Tomjac';
        var meineEchteSendeID = parseInt(meinEchtesInput.getAttribute('data-id') || '18');
        authParams = '&admin_auth_name=' + encodeURIComponent(rmeSendeNameAnBackend.replace('_CU', '').trim()) + '&admin_auth_id=' + meineEchteSendeID;
    } else {
        authParams = '&admin_auth_name=DJ-Tomjac&admin_auth_id=18';
    }

    // 3. Wir packen den Befehl in ein schlankes Paket (NUR 'message', sonst nichts!)
    var formData = new FormData();
    formData.append('message', '/firework_command_trigger');

    // 4. Abschicken direkt an den Sende-Core
    fetch('rme_chat_backend.php?action=send' + authParams, {
        method: 'POST',
        body: formData
    })
    .then(function(response) {
        console.log("📡 Schritt 1 erfolgreich: Funkspruch im Sende-Core angekommen!");
    })
    .catch(function(error) {
        console.error("⚠️ Fehler beim Absenden:", error);
    });

    return false;
}
// =========================================================================
// 🎶 MOD-WUNSCH-TICKER: AUTOMATISCHES FELDER-UMSCHALTEN
// =========================================================================
function rmeUmschaltenWunschFelder(wert) {
    var wrapperSong = document.getElementById('rme_wrapper_song');
    var wrapperGruss = document.getElementById('rme_wrapper_gruss');
    if (!wrapperSong || !wrapperGruss) return;

    if (wert === 'wunsch') {
        wrapperSong.style.cssText = 'display: block !important;';
        wrapperGruss.style.cssText = 'display: none !important;';
    } else if (wert === 'gruss') {
        wrapperSong.style.cssText = 'display: none !important;';
        wrapperGruss.style.cssText = 'display: block !important;';
    } else if (wert === 'beides') {
        wrapperSong.style.cssText = 'display: block !important;';
        wrapperGruss.style.cssText = 'display: block !important;';
    }
}

// =========================================================================
// 🎶 MOD-WUNSCH-TICKER: 1. USER-WUNSCH ABSENDEN & OVERLAY AUTO-CLOSE
// =========================================================================
function rmeSendeWunschAnModZentrale() {
    var typFeld = document.getElementById('rme_wunsch_typ');
    var songFeld = document.getElementById('rme_wunsch_song');
    var grussFeld = document.getElementById('rme_wunsch_gruss');
    var statusDiv = document.getElementById('rme_wunsch_status');
    
    if (!typFeld || !songFeld || !grussFeld || !statusDiv) return;
    
    var typ = typFeld.value;
    var songText = songFeld.value.trim();
    var grussText = grussFeld.value.trim();
    
    // Validierung je nach gewähltem Typ
    if (typ === 'wunsch' && songText === "") { alert("Bitte gib deinen Musikwunsch ein!"); return; }
    if (typ === 'gruss' && grussText === "") { alert("Bitte gib deinen Grußtext ein!"); return; }
    if (typ === 'beides' && (songText === "" || grussText === "")) { 
        alert("Bitte fülle sowohl das Musikwunsch- als auch das Grußtext-Feld aus!"); 
        return; 
    }
    
    var rmeInputFeld = document.getElementById('rme-chat-input') || document.getElementById('chat-message-input') || document.getElementById('message');
    var meinEchterName = rmeInputFeld ? (rmeInputFeld.getAttribute('data-user') || 'Gast') : 'Gast';

    statusDiv.innerText = "Sende...";
    statusDiv.style.color = "#ffed00";

    $.post('rme_smilies_handler.php?action=send_wunsch', {
        wunsch_typ: typ,
        song_text: songText,
        gruss_text: grussText,
        live_user: meinEchterName
    }, function(res) {
        if (res.status === 'success') {
            statusDiv.innerText = "Erfolgreich gesendet! 🚀";
            statusDiv.style.color = "#00ff66";
            
            // Felder sofort leeren
            songFeld.value = "";
            grussFeld.value = "";
            
            // 🎯 AUTO-CLOSE FIX: Das große freischwebende Fenster klappt nach 1.5 Sekunden von alleine zu!
            setTimeout(function() {
                var overlay = document.getElementById('rme-wunschbox-overlay');
                if (overlay) {
                    overlay.style.display = 'none';
                }
                // Status zurücksetzen für das nächste Mal
                statusDiv.innerText = "Bereit";
                statusDiv.style.color = "#666";
            }, 1500);

        } else {
            statusDiv.innerText = "Fehler beim Senden.";
            statusDiv.style.color = "#ff0055";
            alert(res.message);
        }
    }, 'json').fail(function() {
        statusDiv.innerText = "Server-Fehler.";
        statusDiv.style.color = "#ff0055";
    });
}

// =========================================================================
// 🎶 MOD-WUNSCH-TICKER: 2. WUNSCH-LISTE LIVE IM MOD-MENÜ AUSLESEN
// =========================================================================
function rmeLadeModWunschTicker() {
    // Wir prüfen, ob das Mod-Untermenü überhaupt auf dem Bildschirm existiert
    var modMenue = document.getElementById('rme-mod-tools-sub');
    if (!modMenue) return;
    
    // Falls der Ticker-Container im HTML noch fehlt, erzeugen wir ihn live
    var tickerBox = document.getElementById('rme-mod-wunsch-ticker-box');
    if (!tickerBox) {
        tickerBox = document.createElement('div');
        tickerBox.id = 'rme-mod-wunsch-ticker-box';
        tickerBox.style.cssText = 'margin-top: 12px !important; border-top: 1px solid #333 !important; padding-top: 10px !important; text-align: left !important; max-height: 300px !important; overflow-y: auto !important;';
        modMenue.appendChild(tickerBox);
    }

    $.getJSON('rme_smilies_handler.php?action=get_wuensche', function(data) {
        if (data && data.html) {
            tickerBox.innerHTML = data.html;
        }
    });
}

// =========================================================================
// 🎶 MOD-WUNSCH-TICKER: 3. AKTIONEN REPARIERT (ABHAKEN ODER LÖSCHEN)
// =========================================================================
function rmeModWunschAktion(wunschId, aktion) {
    console.log("🎶 Mod-Aktion: " + aktion + " für Wunsch-ID: " + wunschId);
    
    $.post('rme_smilies_handler.php?action=mod_wunsch_action', {
        id: parseInt(wunschId), // Zwingt JavaScript, eine saubere Zahl zu senden
        cmd: String(aktion)     // Zwingt JavaScript, den Text "delete" oder "check" zu senden
    }, function(res) {
        if (res.status === 'success') {
            // Aktualisiert die Wunschliste sofort für das gesamte Team
            if (typeof rmeLadeModWunschTicker === 'function') {
                rmeLadeModWunschTicker();
            }
        }
    }, 'json');
}

// =========================================================================
// ⏱️ DER AUTOMATISCHE TAKTGEBER: FRAGT ALLE 3 SEKUNDEN DIE WÜNSCHE AB
// =========================================================================
if (typeof rmeZentralerWunschTimer === 'undefined') {
    var rmeZentralerWunschTimer = setInterval(function() {
        if (typeof rmeLadeModWunschTicker === 'function') {
            rmeLadeModWunschTicker();
        }
    }, 3000); // 3000ms = 3 Sekunden Live-Intervall
}

(function() {
    // 1. Hintergrund-sicherer Heartbeat über sendBeacon (umgeht das Browser-Throttling)
    function executeSecurePing() {
        var url = "ping.php?t=" + new Date().getTime();
        // sendBeacon läuft asynchron im Browser-Kernel und schläft im Hintergrund-Tab NIE ein
        if (navigator.sendBeacon) {
            navigator.sendBeacon(url);
        } else {
            var xhr = new XMLHttpRequest();
            xhr.open("GET", url, true);
            xhr.send();
        }
    }

    // Intervall auf 10 Sekunden setzen (wird im Hintergrund evtl. auf 60s verzögert)
    setInterval(executeSecurePing, 10000);

    // 2. WICHTIGER TRICK: Sobald das Tab wieder aktiv wird, SOFORT die Verbindung retten!
    document.addEventListener("visibilitychange", function() {
        if (!document.hidden) {
            console.log("Tab wieder aktiv! Verbindung wird sofort aufgefrischt.");
            executeSecurePing();
            // Falls das Chat-Backend eine Reconnect-Funktion hat, stoßen wir sie hier an
            if (typeof check_chat_connection === "function") { check_chat_connection(); }
        }
    });
})();

function rmeSpeichereNeueQuizFrage() {
    var frage = document.getElementById('rme_quiz_add_question').value.trim();
    var punkte = document.getElementById('rme_quiz_add_points').value.trim();
    var antwort = document.getElementById('rme_quiz_add_answer').value.trim();
    var statusLabel = document.getElementById('rme-quiz-save-status');
    
    if (frage === "" || antwort === "" || punkte === "") {
        alert("Bitte fülle alle drei Felder aus!");
        return;
    }
    
    if (statusLabel) { statusLabel.innerHTML = "⏳ Speichere..."; statusLabel.style.color = "#ffcc00"; }
    
    var rmeInputFeld = document.getElementById('rme-chat-input');
    var meineEchteSendeID = rmeInputFeld ? rmeInputFeld.getAttribute('data-id') || '0' : '0';
    
    var quizFormData = new FormData();
    quizFormData.append('admin_note_action', 'insert_new_quiz_question');
    quizFormData.append('user_id', meineEchteSendeID);
    quizFormData.append('frage', frage);
    quizFormData.append('punkte', punkte);
    quizFormData.append('antwort', antwort);
    
    // 🔥 JETZT DIREKT AN DIE NEUE, EIGENE DATEI SENDEN!
    fetch('chat_save_quiz.php', {
        method: 'POST',
        body: quizFormData
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            if (statusLabel) { statusLabel.innerHTML = "✅ Erfolgreich gespeichert!"; statusLabel.style.color = "#00ff00"; }
            setTimeout(function() {
                document.getElementById('rme_quiz_add_question').value = "";
                document.getElementById('rme_quiz_add_points').value = "10";
                document.getElementById('rme_quiz_add_answer').value = "";
                statusLabel.innerHTML = "";
            }, 1500);
        } else {
            if (statusLabel) { statusLabel.innerHTML = "❌ " + data.message; statusLabel.style.color = "#ff3333"; }
        }
    })
    .catch(function() {
        if (statusLabel) { statusLabel.innerHTML = "❌ Netzwerkfehler!"; statusLabel.style.color = "#ff3333"; }
    });
}
// 🏆 SPEZIAL-FUNKTION: HIGHSCORE BANNER ÖFFNEN
function öffneHighscoreTabelle() {
    // 1. Das Banner sichtbar schalten (Flex-Modus für perfekte Zentrierung)
    var popup = document.getElementById('rme-highscore-popup');
    if(popup) {
        popup.style.display = 'flex';
    }
    
    // 2. Die Live-Daten aus dem Backend anfordern
    ladeHighscoreDatenLive();
}

// ❌ SPEZIAL-FUNKTION: HIGHSCORE BANNER SCHLIESSEN
function schliesseHighscoreTabelle() {
    var popup = document.getElementById('rme-highscore-popup');
    if(popup) {
        popup.style.display = 'none';
    }
}
// 📡 HOLT DIE QUIZ-PUNKTE LIVE AUS DER DATENBANK
function ladeHighscoreDatenLive() {
    var listenContainer = document.getElementById('rme-highscore-liste-daten');
    
    fetch('rme_chat_backend.php?action=get_quiz_highscores')
        .then(response => {
            // Wenn PHP einen Fehler wirft (z.B. 404 oder 500)
            if (!response.ok) { throw new Error('Server-Fehler: ' + response.status); }
            return response.text(); // Erst als Text laden, um Fehler abzufangen!
        })
        .then(text => {
            try {
                var daten = JSON.parse(text); // Jetzt erst in JSON umwandeln
                if(!daten || daten.length === 0) {
                    listenContainer.innerHTML = '<div style="color:#aaa; text-align:center; padding:20px;">Noch keine Einträge vorhanden.</div>';
                    return;
                }
                
                var htmlInhalt = '';
                daten.forEach((spieler, index) => {
                    var rang = index + 1;
                    htmlInhalt += '<div class="rme-highscore-row" data-rank="' + rang + '">';
                    htmlInhalt += '  <div>';
                    htmlInhalt += '    <span class="rme-highscore-rank">#' + rang + '</span>';
                    htmlInhalt += '    <span class="rme-highscore-player">' + spieler.username + '</span>';
                    htmlInhalt += '  </div>';
                    htmlInhalt += '  <div class="rme-highscore-score">' + spieler.punkte + ' Pkt.</div>';
                    htmlInhalt += '</div>';
                });
                listenContainer.innerHTML = htmlInhalt;
            } catch(e) {
                // HIER SEHEN WIR JETZT DEN PHP-FEHLER IN DER KONSOLE!
                console.error("Roher Server-Text bei Fehler:", text);
                throw new Error("PHP hat kein gültiges JSON gesendet.");
            }
        })
        .catch(fehler => {
            console.error('Highscore-Fehler:', fehler);
            listenContainer.innerHTML = '<div style="color:#ff3333; text-align:center; padding:20px;">Fehler beim Laden der Rangliste.</div>';
        });
}

// Einfaches Laden der Aktionen beim Start
let rmeGeladeneAktionen = [];
let istGesperrt = false;

document.addEventListener("DOMContentLoaded", function() {
    fetch('aktionen.txt?t=' + Date.now())
        .then(response => response.text())
        .then(text => {
            rmeGeladeneAktionen = text.split('\n')
                .map(line => line.trim())
                .filter(line => line.length > 0);
            console.log("🎲 " + rmeGeladeneAktionen.length + " Aktionen geladen!");
        })
        .catch(err => console.error("Fehler beim Laden:", err));
});

// Die originale Klick-Funktion, die bei dir funktioniert hat
function fuehreAktionAus() {
    if (istGesperrt) return;

    var inputFeld = document.getElementById("nameEingabe");
    if (!inputFeld) return;
    
    var zielName = inputFeld.value.trim();
    if (zielName === "" || rmeGeladeneAktionen.length === 0) return;

    var zufallsIndex = Math.floor(Math.random() * rmeGeladeneAktionen.length);
    var finalerText = rmeGeladeneAktionen[zufallsIndex].replace("@TARGET", zielName);
    
    if (finalerText.length > 200) {
        finalerText = finalerText.substring(0, 197) + "...";
    }

    var echtesChatInput = document.getElementById('rme-chat-input');
    if (echtesChatInput) {
        // Text reinschreiben
        echtesChatInput.value = finalerText;
        
        // Deine originale Absendefunktion direkt triggern
        if (typeof fuehreRmeChatAbsendungAus === 'function') {
            fuehreRmeChatAbsendungAus();
        }
        
        inputFeld.value = "";
    }

    istGesperrt = true;
    setTimeout(function() { istGesperrt = false; }, 5000);
}

</script>
<!-- ========================================================================= -->
<!-- METRIC-NEON OVERLAY: RECHTLICHES DATENSCHUTZ-BANNER -->
<!-- ========================================================================= -->
<div id="rme-dsgvo-popup" class="rme-dsgvo-overlay">
    <div class="rme-dsgvo-modal">
        <div class="rme-dsgvo-header">🛡️ Sicherheits- & Datenschutzhinweis</div>
        <div class="rme-dsgvo-body">
            Zum Schutz unserer Chat-Community, zur Abwehr von Spam-Bots sowie aus rechtlichen Gründen der Nachvollziehbarkeit bei schweren Verstößen (z. B. illegalen Inhalten, Bedrohungen oder systemweiten Störungen), werden bei der Nutzung des Chats temporär technische Daten erhoben.
            <br><br>
            Hierzu gehören die <strong>IP-Adresse</strong>, der Zeitpunkt der Aktivität sowie grundlegende Systeminformationen (<strong>Betriebssystem und Browsertyp</strong> aus dem User-Agent). Diese Daten werden ausschließlich zur Sicherstellung des technischen Chat-Betriebs und zur Moderation (z. B. für IP-Sperren oder Kicks bei Spam) verarbeitet. 
            <br><br>
            Eine Weitergabe an Dritte erfolgt nicht. Die Daten der Onlineliste werden automatisch nach Ablauf der Sitzung oder nach Inaktivität gelöscht. Mit der Nutzung des Chats stimmst du dieser Sicherheitsmaßnahme zu.
        </div>
        <div class="rme-dsgvo-footer">
            <button type="button" class="rme-dsgvo-close-btn" onclick="rmeToggleDsgvoBanner(false)">Gelesen & Schließen</button>
        </div>
    </div>
</div>

<!-- ========================================================================= -->
<!-- METRIC-NEON OVERLAY: STUDIO WUNSCHBOX (DIREKTE PHP-LOGIK) -->
<!-- ========================================================================= -->
<?php
// Session starten, falls sie vom CMS noch nicht aktiv ist
if (session_status() == PHP_SESSION_NONE) { session_start(); }

// =========================================================================
// 🔥 LIVE-NEON-AMPEL: WUNSCHBOX STATUS ERMITTELN (UNZERSTÖRBAR)
// =========================================================================
// Wir fragen die gängigen Einstellungs-Tabellen Deines Radio-CMS ab
$rme_ampel_status = "open"; // Standard-Zustand: Alles geöffnet!

// 🕵️‍♂️ Detektivischer Blick in die Chat- und Radio-Settings
$check_settings_table = defined('DB_PREFIX') ? DB_PREFIX."chat_settings" : "fusionb7754_chat_settings";
$settings_q = dbquery("SELECT * FROM `".$check_settings_table."` LIMIT 1");

if ($settings_q && dbrows($settings_q) > 0) {
    $chat_settings = dbarray($settings_q);
    
    // Erkennt die typischen Wunschbox-Sperrfelder Deiner Infusion
    if (isset($chat_settings['wunschbox']) || isset($chat_settings['wunschbox_status']) || isset($chat_settings['studiobox_status'])) {
        $val = isset($chat_settings['wunschbox']) ? intval($chat_settings['wunschbox']) : (isset($chat_settings['wunschbox_status']) ? intval($chat_settings['wunschbox_status']) : intval($chat_settings['studiobox_status']));
        
        if ($val === 0) { $rme_ampel_status = "closed"; }        // Komplett geschlossen
        elseif ($val === 1 || $val === 2) { $rme_ampel_status = "limited"; } // Eingeschränkt (Wunsch oder Gruss gesperrt)
        else { $rme_ampel_status = "open"; }                     // Alles offen!
    }
}

// =========================================================================
// WUNSCH-SPEICHERUNG: Optimiert für den fehlerfreien Hintergrund-Funk (Fetch)
// =========================================================================
if (isset($_POST['greetings']) || isset($_POST['wishes'])) {
    
    // Schutz gegen ungeduldige Doppel-Klicks (Hash-Sperre)
    $aktueller_wunsch_hash = md5(($_POST['greetings'] ?? '').($_POST['wishes'] ?? ''));
    
    if (!isset($_SESSION['letzter_wunsch_hash']) || $_SESSION['letzter_wunsch_hash'] !== $aktueller_wunsch_hash) {
        
        $sb_gruss    = isset($_POST['greetings']) ? stripinput($_POST['greetings']) : "";
        $sb_wunsch   = isset($_POST['wishes']) ? stripinput($_POST['wishes']) : "";
        $sb_userid   = isset($userdata['user_id']) ? intval($userdata['user_id']) : 0;
        $sb_username = isset($userdata['user_name']) ? stripinput($userdata['user_name']) : "Gast";

        if (!empty($sb_gruss) || !empty($sb_wunsch)) {
            // 1. In die Datenbank schreiben
            $db_eintrag = dbquery("INSERT INTO `".DB_STUDIOBOX."` 
                (`user_id`, `user_name`, `user_ip`, `submit_time`, `greetings`, `wishes`)
                VALUES
                ('".$sb_userid."', '".$sb_username."', '".USER_IP."', '".time()."', '".$sb_gruss."', '".$sb_wunsch."')");
            
            // Wunsch als verarbeitet markieren
            $_SESSION['letzter_wunsch_hash'] = $aktueller_wunsch_hash;
            
            if ($db_eintrag) {
                header('Content-Type: application/json');
                echo json_encode(["status" => "success"]);
                exit; 
            }
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(["status" => "success"]);
        exit;
    }
}
?>

<!-- ========================================================================= -->
<!-- METRIC-NEON OVERLAY: STUDIO WUNSCHBOX (STUNDENGENAUE LIVE-WEICHE V5)      -->
<!-- ========================================================================= -->
<?php
date_default_timezone_set('Europe/Berlin');
$jetzt_sekunden = time(); 
$aktuelle_stunde = (int)date("G"); 
$heutiges_datum_db = date("Y-m-d", $jetzt_sekunden);

$show_formular = false;
$sendeplan_table_real = "fusionb7754_sendeplan"; 

// Wir prüfen den Sendeplan direkt in PHP beim Laden der Seite
$sendeplan_query = dbquery("SELECT * FROM ".$sendeplan_table_real." WHERE FROM_UNIXTIME(day, '%Y-%m-%d') = '".$heutiges_datum_db."' LIMIT 1");    

if ($sendeplan_query && dbrows($sendeplan_query) > 0) {
    $sp_row = dbarray($sendeplan_query);
    if (isset($sp_row[$aktuelle_stunde])) {
        $stunden_eintrag = trim((string)$sp_row[$aktuelle_stunde]);
        if (!empty($stunden_eintrag) && $stunden_eintrag !== "0" && strtolower($stunden_eintrag) !== "autodj") {
            if (strpos($stunden_eintrag, '.') !== false) {
                $teile = explode('.', $stunden_eintrag);
                if (isset($teile[0]) && intval($teile[0]) > 0) { $show_formular = true; }
            } else {
                if (intval($stunden_eintrag) > 0 || $stunden_eintrag !== "") { $show_formular = true; }
            }
        }
    }
}

// Hier setzen wir den Ampel-Status passend zum Sendeplan fest
$rme_ampel_status = $show_formular ? "open" : "closed";
?>

<div id="rme-studiobox-popup" class="rme-dsgvo-overlay">
    <!-- 🎯 REPARIERT: Bekommt jetzt live von PHP die absolut richtige Pulsier-Klasse (open oder closed)! -->
    <div class="rme-studiobox-modal <?php echo 'rme-ampel-border-'.$rme_ampel_status; ?>">
        
        <div class="rme-dsgvo-header">
            <span>🎙️ STUDIO WUNSCHBOX</span>
            
            <!-- 🎯 REPARIERT: Das Status-Label fluchtet farblich exakt mit dem echten Sendeplan! -->
            <?php if ($show_formular) { ?>
                <span id="rme-studiobox-status" class="rme-studiobox-status-badge" style="background:#00ff00 !important; color:#000 !important; font-weight:bold !important; padding: 2px 6px; border-radius: 4px;">🔴 LIVE</span>
            <?php } else { ?>
                <span id="rme-studiobox-status" class="rme-studiobox-status-badge" style="background:#ff9900 !important; color:#000 !important; font-weight:bold !important; padding: 2px 6px; border-radius: 4px;">🤖 AutoDJ</span>
            <?php } ?>
        </div>

        <div class="rme-dsgvo-body">
            <div id="rme-studiobox-weiche-inhalt" class="rme-studiobox-full-width">
                <?php
                if ($show_formular) {
                    // --- LIVE-MODUS: Formular wird ausgegeben ---
                    echo '<div style="text-align:center; padding:2px 0 10px 0; font-size:11px; font-weight:bold; color:#00ff00;">Wünsche & Grüße sind herzlich willkommen! 📻</div>';
                    echo '<form id="rme-studiobox-form" onsubmit="return false;" class="rme-studiobox-form-layout">';
                    echo '<input type="text" id="greetings" name="greetings" placeholder="Dein Grußtext..." class="rme-studiobox-input" style="border-left: 3px solid #00ff00 !important;" required>';
                    echo '<input type="text" id="wishes" name="wishes" placeholder="Dein Musikwunsch..." class="rme-studiobox-input" style="border-left: 3px solid #00ff00 !important;" required>';
                    echo '<div class="rme-dsgvo-footer rme-studiobox-btn-gap" style="display:flex; gap:10px; margin-top:10px;">';
                    echo '<button type="button" class="rme-studiobox-submit-btn" style="flex:1;" onclick="rmeSendeWunschSmooth();">Absenden 🚀</button>';
                    echo '<button type="button" class="rme-studiobox-close-btn" style="flex:1;" onclick="rmeToggleStudiobox(false)">Schließen</button>';
                    echo '</div>';
                    echo '</form>';
                } else {
                    // --- AUTODJ-MODUS: Warnmeldung mitsamt repariertem Schließen-Button ---
                    echo '<div class="rme-autodj-notice-box" style="text-align:center; padding:20px 0; color:#ff9900; font-size:16px; font-weight:bold;">';
                    echo '🤖 Der AutoDJ ist aktuell aktiv.<br><br>';
                    echo '<span class="rme-autodj-notice-sub" style="color:#fff; font-weight:normal; font-size:14px;">Grüße und Wünsche sind nur bei Live-Sendungen möglich!</span>';
                    echo '</div>';
                    
                    // 🎯 REPARIERT: Schließt das offene div und setzt den fehlenden Button sauber rein!
                    echo '<div class="rme-dsgvo-footer rme-studiobox-text-center" style="text-align:center; margin-top:10px;">';
                    echo '<button type="button" class="rme-studiobox-close-btn" style="width:100%; padding:8px; font-weight:bold; cursor:pointer;" onclick="rmeToggleStudiobox(false)">Schließen</button>';
                    echo '</div>';
                }

                ?>
            </div>
        </div>
    </div>
</div>
<canvas id="rme-fireworks-canvas"></canvas>
<!-- 🚀 ABSOLUTE NOTBREMSE GEGEN BROWSER-CRASH BEI VERBINDUNGSABBRUCH -->
<script>
(function() {
    let originalEventSource = window.EventSource;
    let fehlerZaehler = 0;

    // Wir klinken uns direkt in den Browser-EventSource ein
    window.EventSource = function(url, options) {
        let instance = new originalEventSource(url, options);
        
        // Sobald ein Fehler auftritt, zieht dieses Skript die Handbremse an
        instance.addEventListener('error', function(e) {
            console.warn("Mucke läuft, aber Chat-Verbindung wackelt! Handbremse aktiv.");
            
            // Stoppt den sofortigen, rasenden Wiederverbindungs-Amoklauf des Browsers
            instance.close();
            
            fehlerZaehler++;
            let pause = Math.min(fehlerZaehler * 3000, 15000); // 3s, 6s, 9s... max 15 Sekunden
            
            // Lässt den Browser durchatmen und startet den Chat nach der Pause friedlich neu
            setTimeout(function() {
                window.location.reload(); // Lädt das Chat-Fenster sicher neu, wenn der Server wieder da ist
            }, pause);
        });

        return instance;
    };
})();
</script>

</body>
</html>
