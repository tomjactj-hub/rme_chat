<?php
// rme_smilies_handler.php - BEREINIGTE SENDERLEITUNGS-VERSION (SESSION-TIMING-FIX)

error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() == PHP_SESSION_NONE) {
    session_name("RME_RADIO_CHAT_SESSION");
    session_start();
}

define('RME_DB_PREFIX', 'fusionb7754_');

$db_host   = 'localhost';
$db_user   = 'rme2016';
$db_pass   = 'xI5#z2Fo0Dmervuj';
$db_name   = 'radiohprme';

$db_connect = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
if (!$db_connect) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'MySQL-Verbindung fehlgeschlagen.']);
    exit;
}
mysqli_set_charset($db_connect, "utf8mb4");

// --- DOPPELTER CHEF-SCHUTZSCHALTER ---
$session_id_check = isset($_SESSION['chat_user_id']) ? intval($_SESSION['chat_user_id']) : 0;
$session_username = $_SESSION['chat_user_name'] ?? '';

// 🎯 ANTI-STAU NOTBREMSE: Wir schließen die Session-Datei sofort wieder für den Server
session_write_close();

$post_id_check = isset($_POST['chef_id']) ? intval($_POST['chef_id']) : 0;
$post_username = $_POST['chef_name'] ?? '';

$ist_chef = ($session_id_check === 18 || strtolower($session_username) === 'dj-tomjac' || $post_id_check === 18 || strtolower($post_username) === 'dj-tomjac');

// Aktion aus GET oder POST holen
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

// =========================================================================
// 1. SMILEY-UPLOADER (REPARIERTER KOORDINATOR MIT GRÖSSEN-BREMSE)
// =========================================================================
if ($action === "upload_smiley") {
    header('Content-Type: application/json');
    if (!$ist_chef) { echo json_encode(['status' => 'error', 'message' => 'Zugriff verweigert. Sendeleitung erforderlich.']); exit; }

    $kategorie = isset($_POST['kategorie']) ? trim($_POST['kategorie']) : 'Allgemein';
    $kuerzel = isset($_POST['kuerzel']) ? trim($_POST['kuerzel']) : '';

    if (empty($kuerzel) || !isset($_FILES['smiley_file'])) {
        echo json_encode(['status' => 'error', 'message' => 'Fehlende Daten.']);
        exit;
    }

    $file = $_FILES['smiley_file'];
    $file_tmp = $file['tmp_name'];

    if (empty($file_tmp) || !is_uploaded_file($file_tmp)) {
        echo json_encode(['status' => 'error', 'message' => 'Upload-Fehler auf dem Server.']);
        exit;
    }

    // 🎯 DIE RETTUNG: Eiserne Größen-Bremse! 
    // Wenn das GIF/Bild größer als 500 KB (512000 Bytes) ist, bremen wir den Upload ab.
    // Das verhindert JEDEN 'max_allowed_packet' Fatal Error und schützt Deine Datenbank!
    if ($file['size'] > 512000) {
        echo json_encode(['status' => 'error', 'message' => 'Datei zu groß! Große Smiley-GIFs sprengen das Paketlimit des Hosters. Bitte verkleinere das Bild auf unter 500 KB.']);
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime_type = finfo_file($finfo, $file_tmp);
    finfo_close($finfo);

    // DEIN BEWÄHRTER ORIGINAL-CODE (Garantierte Bild-Anzeige!)
    $bild_daten = file_get_contents($file_tmp);
    $hex_bild = bin2hex($bild_daten);
    $safe_kuerzel = mysqli_real_escape_string($db_connect, $kuerzel);
    $safe_kategorie = mysqli_real_escape_string($db_connect, $kategorie);
    $safe_mime_type = mysqli_real_escape_string($db_connect, $mime_type);

    $query = "INSERT INTO `" . RME_DB_PREFIX . "chat_smilies` (`kuerzel`, `kategorie`, `bild_blob`, `mime_type`) 
              VALUES ('$safe_kuerzel', '$safe_kategorie', x'$hex_bild', '$safe_mime_type') 
              ON DUPLICATE KEY UPDATE `kategorie` = '$safe_kategorie', `bild_blob` = x'$hex_bild', `mime_type` = '$safe_mime_type'";
    
    if (mysqli_query($db_connect, $query)) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'MySQL-Fehler: ' . mysqli_error($db_connect)]);
    }
    exit;
}

// =========================================================================
// 2. IMAGE-STREAMER (FÜR DIE SMILEYS IN POPUP & CHATVERLAUF)
// =========================================================================
if ($action === "render_image") {
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    $query = "SELECT `bild_blob`, `mime_type` FROM `" . RME_DB_PREFIX . "chat_smilies` WHERE `id` = '$id' LIMIT 1";
    $result = mysqli_query($db_connect, $query);
    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        
        // Browser-Caching aktivieren, damit Smileys pfeilschnell laden
        header("Content-Type: " . $row['mime_type']);
        header("Cache-Control: public, max-age=2592000"); 
        echo $row['bild_blob'];
    } else {
        header("HTTP/1.0 404 Not Found");
    }
    exit;
}

// =========================================================================
// 3. SOUNDBOARD-UPLOADER (REPARIERT MIT EISERNEM HEX-EINBRENN-VERFAHREN)
// =========================================================================
if ($action === "upload_sound") {
    header('Content-Type: application/json');
    if (!$ist_chef) { echo json_encode(['status' => 'error', 'message' => 'Zugriff verweigert. Sendeleitung erforderlich.']); exit; }

    $sound_name = isset($_POST['sound_name']) ? trim($_POST['sound_name']) : '';
    
    if (empty($sound_name) || !isset($_FILES['sound_file'])) {
        echo json_encode(['status' => 'error', 'message' => 'Bitte Namen eingeben und MP3 auswählen!']);
        exit;
    }

    $file = $_FILES['sound_file'];
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if ($file_extension !== 'mp3') {
        echo json_encode(['status' => 'error', 'message' => 'Es sind nur echte MP3-Dateien erlaubt!']);
        exit;
    }

    $clean_name = preg_replace('/[^a-zA-Z0-9]/', '', $sound_name);
    $sound_command = strtolower($clean_name) ?: 'sound_' . time();
    $final_filename = strtolower($sound_command) . '.mp3';

    $binary_data = file_get_contents($file['tmp_name']);
    if ($binary_data === false) {
        echo json_encode(['status' => 'error', 'message' => 'Die Musikdaten konnten nicht verarbeitet werden.']);
        exit;
    }

    // 🔥 REPARATUR-KERN: MP3 absolut binär-sicher in HEX übersetzen!
    $hex_sound = bin2hex($binary_data);

    $safe_name = mysqli_real_escape_string($db_connect, $sound_name);
    $safe_command = mysqli_real_escape_string($db_connect, $sound_command);
    $safe_file = mysqli_real_escape_string($db_connect, $final_filename);

    // 🔥 REPARIERT: Mit x'$hex_sound' brennen wir das Musik-Blob absolut fehlerfrei ein
    $query = "INSERT INTO `" . RME_DB_PREFIX . "chat_sounds` (`sound_name`, `sound_command`, `datei_name`, `sound_blob`) 
              VALUES ('$safe_name', '$safe_command', '$safe_file', x'$hex_sound')
              ON DUPLICATE KEY UPDATE `sound_name` = '$safe_name', `datei_name` = '$safe_file', `sound_blob` = x'$hex_sound'";
    
    if (mysqli_query($db_connect, $query)) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Fehler beim DB-Eintrag: ' . mysqli_error($db_connect)]);
    }
    exit;
}
// =========================================================================
// 4. SMILEY RESTLOS AUS DER DATENBANK LÖSCHEN
// =========================================================================
if ($action === "delete_database_smiley") {
    header('Content-Type: application/json');
    if (!$ist_chef) { echo json_encode(['status' => 'error', 'message' => 'Sendeleitung erforderlich.']); exit; }

    $smiley_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($smiley_id <= 0) { echo json_encode(['status' => 'error', 'message' => 'Ungültige ID.']); exit; }

    $query = "DELETE FROM `" . RME_DB_PREFIX . "chat_smilies` WHERE `id` = $smiley_id";
    if (mysqli_query($db_connect, $query)) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'MySQL-Fehler: ' . mysqli_error($db_connect)]);
    }
    exit;
}

// =========================================================================
// 5. SOUND RESTLOS AUS DER DATENBANK LÖSCHEN
// =========================================================================
if ($action === "delete_database_sound") {
    header('Content-Type: application/json');
    if (!$ist_chef) { echo json_encode(['status' => 'error', 'message' => 'Sendeleitung erforderlich.']); exit; }

    $sound_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($sound_id <= 0) { echo json_encode(['status' => 'error', 'message' => 'Ungültige ID.']); exit; }

    $query = "DELETE FROM `" . RME_DB_PREFIX . "chat_sounds` WHERE `id` = $sound_id";
    if (mysqli_query($db_connect, $query)) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'MySQL-Fehler: ' . mysqli_error($db_connect)]);
    }
    exit;
}
// =========================================================================
// 6. ARCHIV-TEXT RESTLOS AUS DER DATENBANK LÖSCHEN (UNZERSTÖRBAR)
// =========================================================================
if ($action === "delete_archive_msg") {
    header('Content-Type: application/json');
    if (!$ist_chef) { echo json_encode(['status' => 'error', 'message' => 'Sendeleitung erforderlich.']); exit; }

    $msg_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($msg_id <= 0) { echo json_encode(['status' => 'error', 'message' => 'Ungültige ID.']); exit; }

    // 🎯 FIX: Da der Handler mit $db_connect arbeitet, löschen wir direkt über diese Verbindung
    $query = "DELETE FROM `fusionb7754_chat_messages` WHERE `id` = $msg_id";
    if (mysqli_query($db_connect, $query)) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'MySQL-Fehler: ' . mysqli_error($db_connect)]);
    }
    exit;
}
// =========================================================================
// 7. CHAT-SPIELE: DYNAMISCHER BUTTON-EMPFÄNGER FÜR DAS HÖRER-GLÜCKSRAD
// =========================================================================
if ($action === "execute_chat_game") {
    header('Content-Type: application/json');
    if (session_status() === PHP_SESSION_NONE) { session_name("RME_RADIO_CHAT_SESSION"); session_start(); }
    
    $spieler_id = isset($_SESSION['chat_user_id']) ? intval($_SESSION['chat_user_id']) : 0;
    $spieler_name = isset($_SESSION['chat_user_name']) ? trim($_SESSION['chat_user_name']) : 'Gast';
    $spiel_art = isset($_POST['game_type']) ? trim((string)$_POST['game_type']) : '';
    $aktueller_zeitstempel = time();

    // Wir nutzen die Konstante Deines Handlers für die Nachrichten-Tabelle
    $sicherer_chat_speicher = "`" . RME_DB_PREFIX . "chat_messages`";

    if ($spiel_art === 'spin_wheel' && $spieler_id > 0) {
        $cooldown_zeit = 600; // 10 Minuten Cooldown (in Sekunden)
        
        // 1. Cooldown aus der Glücksrad-Tabelle auslesen
        $check_query = "SELECT `last_spin` FROM `" . RME_DB_PREFIX . "chat_gluecksrad` WHERE `user_id` = $spieler_id LIMIT 1";
        $check_wheel = mysqli_query($db_connect, $check_query);
        $darf_drehen = true;
        $restzeit = 0;
        
        if ($check_wheel && mysqli_num_rows($check_wheel) > 0) {
            $wheel_row = mysqli_fetch_assoc($check_wheel);
            $vergangene_zeit = $aktueller_zeitstempel - intval($wheel_row['last_spin']);
            if ($vergangene_zeit < $cooldown_zeit) {
                $darf_drehen = false;
                $restzeit = ceil(($cooldown_zeit - $vergangene_zeit) / 60);
            }
        }
        
        if (!$darf_drehen) {
            // 🛑 SPAM-SCHUTZ GREIFT: Rote Fehler-Box in den Verlauf werfen
            $fehler_text = "🎰 [Glücksrad]: Hey " . $spieler_name . ", Deine Finger glühen! Du darfst erst in " . $restzeit . " Min. wieder am Rad drehen.";
            $insert_err = "INSERT INTO $sicherer_chat_speicher (`user_id`, `guest_name`, `message`, `datestamp`) 
                           VALUES (995, 'SYSTEM_SOUND', 'WHEEL_ERROR:" . mysqli_real_escape_string($db_connect, $fehler_text) . "', '" . $aktueller_zeitstempel . "')";
            mysqli_query($db_connect, $insert_err);
        } else {
            // 🎰 DAS RAD ROTIERT: Der Zufalls-Generator ermittelt den Gewinn!
            $gewinne = [
                "🎉 GEWINN! Du darfst Dir sofort einen Musikwunsch beim DJ erfüllen lassen! 🎵",
                "❌ Niete! Das Rad blieb knapp vor dem Hauptgewinn stehen. Versuch es gleich wieder!",
                "🎉 GEWINN! Du darfst einen dicken Gruß an alle Hörer im Stream raushauen! 🎙️",
                "❌ Niete! Der Wind hat das Rad ausgebremst. Nächste Runde kommt bestimmt!",
                "🎉 GEWINN! Der DJ muss Dir Deinen absoluten Lieblings-Smiley im Chat widmen! 😊",
                "❌ Niete! Satz mit X, das war wohl nix. Trink erst mal einen Kaffee! ☕"
            ];
            
            $zufalls_gewinn = $gewinne[array_rand($gewinne)];
            $gewinn_nachricht = "<div class='rme-wheel-alert-box'>🎰 <strong>" . $spieler_name . "</strong> dreht am Glücksrad... <br><span class='rme-wheel-result'>" . $zufalls_gewinn . "</span></div>";
            
            // 2. Cooldown in der Tabelle eintragen oder aktualisieren
            $save_cooldown = "INSERT INTO `" . RME_DB_PREFIX . "chat_gluecksrad` (`user_id`, `last_spin`) 
                              VALUES ($spieler_id, $aktueller_zeitstempel) 
                              ON DUPLICATE KEY UPDATE `last_spin` = $aktueller_zeitstempel";
            mysqli_query($db_connect, $save_cooldown);
            
            // 3. Den Gewinn synchron für alle Hörer als SYSTEM_SOUND in den Chat brennen
            $insert_win = "INSERT INTO $sicherer_chat_speicher (`user_id`, `guest_name`, `message`, `datestamp`) 
                           VALUES (999, 'SYSTEM_SOUND', 'WHEEL_WIN:" . mysqli_real_escape_string($db_connect, $gewinn_nachricht) . "', '" . $aktueller_zeitstempel . "')";
            mysqli_query($db_connect, $insert_win);
        }
        
        echo json_encode(['status' => 'success']);
        exit;
    }
}
// =========================================================================
// 8. HIGH-END MULTIPLAYER TIC-TAC-TOE CENTRALE (UNZERSTÖRBARER LIVE-SYNC - TEIL 1)
// =========================================================================
if (session_status() === PHP_SESSION_NONE) { session_name("RME_RADIO_CHAT_SESSION"); session_start(); }

$ich_selbst = "Gast";
if (isset($_POST['live_user']) && trim($_POST['live_user']) !== "") { 
    $ich_selbst = trim($_POST['live_user']); 
} elseif (isset($_POST['chef_name']) && trim($_POST['chef_name']) !== "") { 
    $ich_selbst = trim($_POST['chef_name']); 
} elseif (isset($_SESSION['chat_user_name']) && trim($_SESSION['chat_user_name']) !== "") { 
    $ich_selbst = trim($_SESSION['chat_user_name']); 
} elseif (isset($_SESSION['rme_chat_guest_name']) && trim($_SESSION['rme_chat_guest_name']) !== "") { 
    $ich_selbst = trim($_SESSION['rme_chat_guest_name']); 
}

$db = isset($db_connect) ? $db_connect : (isset($conn) ? $conn : (isset($mysqli) ? $mysqli : (isset($link) ? $link : null)));

// =========================================================================
// 👥 GEGNER-LISTE: HOLT DIE REINEN NAMEN AUS DER SPALTE 'username' (CORRECTED)
// =========================================================================
if ($action === "ttt_get_players") {
    header('Content-Type: application/json; charset=UTF-8');
    $options_html = '<option value="">-- Spieler auswählen --</option>';
    $tabelle = "fusionb7754_chat_online";
    $rows = array();
    
    // 🎯 REPARIERT: Wir schneiden hier KEINE Namen mehr kaputt, sondern säubern nur noch 
    // administrative Zusätze für den reinen Textabgleich! Gast_123 bleibt voll erhalten!
    $saeuberungs_filter_light = array("_CU", "[ADMIN]", "[MODERATOR]", "[MOD]", "[HADMIN]");
    $mein_name_clean = trim(str_replace($saeuberungs_filter_light, "", $ich_selbst));
    // =========================================================================
    // PART 2: DIE UNFEHLBARE SCHLEIFE (LÄSST GÄSTE GARANTIERT IM DROPDOWN)
    // =========================================================================
    if (!empty($rows)) {
        $counter = 0;
        foreach ($rows as $row) {
            $username_raw = trim($row['username']); 
            
            // Wir reinigen den Datenbank-Namen NUR von Admins und CU-Zusätzen
            $filter_db = array("_CU", "[ADMIN]", "[MODERATOR]", "[MOD]", "[HADMIN]");
            $db_name_clean = trim(str_replace($filter_db, "", $username_raw));
            
            // Wir reinigen deinen eigenen Namen genau identisch
            $mein_name_clean = trim(str_replace($filter_db, "", $ich_selbst));
            
            // STRIKTE PRÜFUNG: Nur wenn der DB-Name exakt deinem Namen entspricht, wird er ignoriert!
            // JEDER andere Name (egal ob Gast_123, Admin oder User) MUSS ins Dropdown!
            if (strtolower($db_name_clean) !== strtolower($mein_name_clean) && !empty($username_raw)) {
                $options_html .= '<option value="'.htmlspecialchars($db_name_clean, ENT_QUOTES, 'UTF-8').'">'.htmlspecialchars($username_raw, ENT_QUOTES, 'UTF-8').'</option>';
                $counter++;
            }
        }
        if ($counter === 0) { 
            $options_html .= '<option value="">Keine anderen Spieler online</option>'; 
        }
    } else {
        $options_html .= '<option value="">Keine anderen Spieler online</option>';
    }

    echo json_encode(['options' => $options_html]);
    exit;
}

// =========================================================================
// --- 8A. EINLADUNG SENDEN (NATIVE LÖSCHUNG DER STATE-DATEI GEGEN ALTLASTEN) ---
// =========================================================================
if ($action === "ttt_invite") {
    header('Content-Type: application/json');
    
    if (!$db) {
        echo json_encode(['status' => 'error', 'message' => 'Keine Datenbank-Verbindung im Handler aktiv.']);
        exit;
    }

    $gegner = mysqli_real_escape_string($db, trim($_POST['opponent']));
    
    if(strtolower($ich_selbst) === strtolower($gegner)) { 
        echo json_encode(['status'=>'error', 'message'=>'Du kannst Dich nicht selbst herausfordern!']); 
        exit; 
    }
    
    // 🔥 MASTER-FIX: Wir vernichten die ttt_state.json sofort beim Absenden einer Einladung!
    // Dadurch wird das alte Spielfeld im Server-Gedächtnis restlos gelöscht.
    $state_file = dirname(__FILE__) . "/ttt_state.json";
    if (file_exists($state_file)) {
        @unlink($state_file);
    }
    
    $time = time();
    $insert_query = "INSERT INTO `fusionb7754_chat_ttt` (`player_x`, `player_o`, `status`, `last_update`, `board`, `turn`) 
                     VALUES ('$ich_selbst', '$gegner', 'invited', $time, ',,,,,,,,', 'X')";
    
    if (mysqli_query($db, $insert_query)) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Datenbank-Fehler beim Einladen.']);
    }
    exit;
}

// =========================================================================
// 8B. EINLADUNG BEANTWORTEN / ABLEHNEN / ARENA VERLASSEN / REVANCHE
// =========================================================================
if ($action === "ttt_respond") {
    header('Content-Type: application/json');
    $g_id = isset($_POST['game_id']) ? intval($_POST['game_id']) : 0;
    $response = isset($_POST['response']) ? trim($_POST['response']) : '';
    $time = time();
    
    if ($g_id > 0) {
        if ($response === 'accept') {
            // Herausforderung angenommen
            mysqli_query($db_connect, "UPDATE `fusionb7754_chat_ttt` SET `status` = 'active', `board` = ',,,,,,,,', `turn` = 'X', `last_update` = $time WHERE `id` = $g_id");
        } 
        // 🔄 NEU: BLITZ-REVANCHE EMULATOR
        elseif ($response === 'rematch') {
            // Wir holen uns das aktuelle Spiel, um die Rollen beizubehalten
            $rematch_q = mysqli_query($db_connect, "SELECT * FROM `fusionb7754_chat_ttt` WHERE `id` = $g_id LIMIT 1");
            if ($rematch_q && mysqli_num_rows($rematch_q) > 0) {
                $r_game = mysqli_fetch_assoc($rematch_q);
                
                // Derjenige, der klickt, fordert den anderen automatisch NEU heraus!
                // Wir löschen zusätzlich die Server-JSON-Datei für einen sauberen Neustart
                $state_file = dirname(__FILE__) . "/ttt_state.json";
                if (file_exists($state_file)) { @unlink($state_file); }
                
                // Wir setzen das Spiel in der DB einfach zurück auf 'invited' und leeren das Board!
                // Wer klickt, wird player_x (Sender), der andere wird player_o (Empfänger)
                $neuer_sender = $ich_selbst;
                $neuer_empfaenger = (strtolower($r_game['player_x']) === strtolower($ich_selbst)) ? $r_game['player_o'] : $r_game['player_x'];
                
                mysqli_query($db_connect, "UPDATE `fusionb7754_chat_ttt` 
                                           SET `player_x` = '$neuer_sender', 
                                               `player_o` = '$neuer_empfaenger', 
                                               `status` = 'invited', 
                                               `board` = ',,,,,,,,', 
                                               `turn` = 'X', 
                                               `last_update` = $time 
                                           WHERE `id` = $g_id");
            }
        } 
        else {
            // Ablehnen oder Verlassen: Match vernichten
            mysqli_query($db_connect, "DELETE FROM `fusionb7754_chat_ttt` WHERE `id` = $g_id");
        }
    }
    
    echo json_encode(['status' => 'success']);
    exit;
}

// =========================================================================
// 8C. EINEN ZUG AUSFÜHREN (CRASH-SICHERE ENGINE)
// =========================================================================
if ($action === "ttt_turn") {
    header('Content-Type: application/json');
    $g_id = isset($_POST['game_id']) ? intval($_POST['game_id']) : 0;
    $cell = isset($_POST['cell']) ? intval($_POST['cell']) : 0;
    $time = time();
    
    $ich_selbst = "Gast";
    if (isset($_POST['live_user']) && trim($_POST['live_user']) !== "") { 
        $ich_selbst = trim($_POST['live_user']); 
    }
    
    // Namens-Anhänge für die Zug-Validierung entfernen
    $saeuberungs_filter = array("_Gast", "_CU", "[ADMIN]", "[MODERATOR]", "[MOD]", "[HADMIN]");
    $ich_selbst = trim(str_replace($saeuberungs_filter, "", $ich_selbst));
    
    $game_q = mysqli_query($db_connect, "SELECT * FROM `fusionb7754_chat_ttt` WHERE `id` = $g_id LIMIT 1");
    if($game_q && mysqli_num_rows($game_q) > 0) {
        $game = mysqli_fetch_assoc($game_q);
        
        $aktuelles_zeichen = $game['turn'];
        $erlaubter_spieler = ($aktuelles_zeichen === 'X') ? $game['player_x'] : $game['player_o'];
        
        // Bereinigung des erlaubten Spielers aus der DB für den Abgleich
        $erlaubter_spieler_clean = trim(str_replace($saeuberungs_filter, "", $erlaubter_spieler));
        
        if(strtolower($ich_selbst) === strtolower($erlaubter_spieler_clean) && $game['status'] === 'active') {
            $board_arr = explode(',', $game['board']);
            if(isset($board_arr[$cell]) && $board_arr[$cell] === '') {
                $board_arr[$cell] = $aktuelles_zeichen;
                $neues_board = implode(',', $board_arr);
                $naechster_turn = ($aktuelles_zeichen === 'X') ? 'O' : 'X';
                
                $b = $board_arr;
                $status = 'active';
                
                // Horizontale Reihen
                if ($b[0] != '' && $b[0] === $b[1] && $b[0] === $b[2]) { $status = ($b[0] === 'X') ? 'won_x' : 'won_o'; }
                if ($b[3] != '' && $b[3] === $b[4] && $b[3] === $b[5]) { $status = ($b[3] === 'X') ? 'won_x' : 'won_o'; }
                if ($b[6] != '' && $b[6] === $b[7] && $b[6] === $b[8]) { $status = ($b[6] === 'X') ? 'won_x' : 'won_o'; }
                // Vertikale Spalten
                if ($b[0] != '' && $b[0] === $b[3] && $b[0] === $b[6]) { $status = ($b[0] === 'X') ? 'won_x' : 'won_o'; }
                if ($b[1] != '' && $b[1] === $b[4] && $b[1] === $b[7]) { $status = ($b[1] === 'X') ? 'won_x' : 'won_o'; }
                if ($b[2] != '' && $b[2] === $b[5] && $b[2] === $b[8]) { $status = ($b[2] === 'X') ? 'won_x' : 'won_o'; }
                // Diagonale Linien
                if ($b[0] != '' && $b[0] === $b[4] && $b[0] === $b[8]) { $status = ($b[0] === 'X') ? 'won_x' : 'won_o'; }
                if ($b[2] != '' && $b[2] === $b[4] && $b[2] === $b[6]) { $status = ($b[2] === 'X') ? 'won_x' : 'won_o'; }
                
                if($status === 'active' && !in_array('', $b)) { $status = 'draw'; }
                
                mysqli_query($db_connect, "UPDATE `fusionb7754_chat_ttt` SET `board`='$neues_board', `turn`='$naechster_turn', `status`='$status', `last_update`=$time WHERE `id` = $g_id");
            }
        }
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// =========================================================================
// ⚔️ SCHRITT 1: KUGELSICHERER SPIELE-SPION EMPFÄNGER (DESIGN-ENGINE - TEIL 1)
// =========================================================================
if ($action === "ttt_check") {
    header('Content-Type: application/json');
    $time = time();
    
    $roher_live_user = "";
    if (isset($_POST['live_user']) && trim($_POST['live_user']) !== "") {
        $roher_live_user = trim($_POST['live_user']);
    } elseif (isset($_GET['admin_auth_name']) && trim((string)$_GET['admin_auth_name']) !== '') {
        $roher_live_user = trim((string)$_GET['admin_auth_name']);
    } elseif (isset($_SESSION['chat_user_name']) && trim($_SESSION['chat_user_name']) !== "") {
        $roher_live_user = trim($_SESSION['chat_user_name']);
    } elseif (isset($_SESSION['rme_chat_guest_name']) && trim($_SESSION['rme_chat_guest_name']) !== "") {
        $roher_live_user = trim($_SESSION['rme_chat_guest_name']);
    }

    $saeuberungs_filter = array("_Gast", "_CU", "[ADMIN]", "[MODERATOR]", "[MOD]", "[HADMIN]");
    $ich_selbst = trim(str_replace($saeuberungs_filter, "", $roher_live_user));
    
    if (empty($ich_selbst) || strtolower($ich_selbst) === "undefined") { 
        $ich_selbst = "Gast"; 
    }

mysqli_query($db_connect, "DELETE FROM `fusionb7754_chat_ttt` WHERE $time - `last_update` > 10800");


    $safe_user = mysqli_real_escape_string($db_connect, $ich_selbst);
    
    $query = "SELECT * FROM `fusionb7754_chat_ttt` WHERE (LOWER(`player_x`) = '".mysqli_real_escape_string($db_connect, strtolower($ich_selbst))."' OR LOWER(`player_o`) = '".mysqli_real_escape_string($db_connect, strtolower($ich_selbst))."') ORDER BY id DESC LIMIT 1";
    $res = mysqli_query($db_connect, $query);
    
    if($res && mysqli_num_rows($res) > 0) {
        $game = mysqli_fetch_assoc($res);
        $g_id = $game['id'];
        $html = "";

        if($game['status'] === 'invited') {
            if (strtolower($game['player_o']) === strtolower($ich_selbst)) {
                // Ich bin der Empfänger: Zeige die Buttons zum Annehmen / Ablehnen
                // 🎯 REPARIERT: position:relative gibt dem Kreuz (x) wieder seinen festen Anker oben rechts!
                $html .= "<div class='rme-ttt-arena' style='position:relative !important; background:#1a1a24 !important; border:2px solid #7B1FA2 !important; padding:15px !important; border-radius:8px !important; color:#fff !important; text-align:center !important; width:260px !important; min-width:260px !important; box-shadow:0 0 15px rgba(123,31,162,0.5) !important;'>";
                $html .= "<span onclick='rmeAntworteAufEinladung($g_id, \"decline\")' style='position:absolute !important; top:6px !important; right:12px !important; color:#cc2424 !important; font-size:20px !important; font-weight:bold !important; cursor:pointer !important; user-select:none !important; z-index:99999999 !important;' title='Schließen'>×</span>";
                $html .= "<div class='rme-ttt-title' style='font-weight:bold !important; color:#ffed00 !important; margin-bottom:10px !important; font-size:13px !important; letter-spacing:0.5px !important;'>⚔️ HERAUSFORDERUNG!</div>";
                $html .= "<div class='rme-ttt-status' style='font-size:13px !important; line-height:1.4 !important; color:#fff !important;'><strong>".htmlspecialchars($game['player_x'], ENT_QUOTES, 'UTF-8')."</strong> fordert Dich zu Tic-Tac-Toe heraus!</div>";
                $html .= "<div style='margin-top:12px !important; display:block !important; height:auto !important; visibility:visible !important;'>";
                $html .= "<button type='button' onclick='rmeAntworteAufEinladung($g_id, \"accept\"); event.preventDefault();' style='background:#00ff00 !important; color:#000 !important; border:none !important; padding:6px 14px !important; font-weight:bold !important; border-radius:4px !important; margin-right:8px !important; cursor:pointer !important; box-shadow:0 0 5px #00ff00 !important; display:inline-block !important; font-size:12px !important;'>Annehmen</button>";
                $html .= "<button type='button' onclick='rmeAntworteAufEinladung($g_id, \"decline\"); event.preventDefault();' style='background:#cc2424 !important; color:#fff !important; border:none !important; padding:6px 14px !important; font-weight:bold !important; border-radius:4px !important; cursor:pointer !important; display:inline-block !important; font-size:12px !important;'>Ablehnen</button>";
                $html .= "</div></div>";
            } else {
                // Ich bin der Absender: Zeige die Warte-Box mit einem Abbrechen-Button
                // 🎯 REPARIERT: position:relative gibt dem Kreuz (x) wieder seinen festen Anker oben rechts!
                $html .= "<div class='rme-ttt-arena' style='position:relative !important; background:#1a1a24 !important; border:2px solid #00d2ff !important; padding:15px !important; border-radius:8px !important; color:#fff !important; text-align:center !important; width:260px !important; min-width:260px !important; box-shadow:0 0 15px rgba(0,210,255,0.4) !important;'>";
                $html .= "<span onclick='rmeAntworteAufEinladung($g_id, \"decline\")' style='position:absolute !important; top:6px !important; right:12px !important; color:#cc2424 !important; font-size:20px !important; font-weight:bold !important; cursor:pointer !important; user-select:none !important; z-index:99999999 !important;' title='Einladung zurückziehen'>×</span>";
                $html .= "<div class='rme-ttt-title' style='font-weight:bold !important; color:#00d2ff !important; margin-bottom:10px !important; font-size:13px !important; letter-spacing:0.5px !important;'>📡 SPIELER GEFORDERT</div>";
                $html .= "<div class='rme-ttt-status' style='font-size:13px !important; line-height:1.4 !important; color:#fff !important;'>Einladung an <strong>".htmlspecialchars($game['player_o'], ENT_QUOTES, 'UTF-8')."</strong> gesendet.<br><small style='color:#aaa !important; font-size:11px !important;'>Warte auf Rückmeldung...</small></div>";
                $html .= "<div style='margin-top:12px !important; display:block !important; height:auto !important; visibility:visible !important;'>";
                $html .= "<button type='button' onclick='rmeAntworteAufEinladung($g_id, \"decline\"); event.preventDefault();' style='background:#cc2424 !important; color:#fff !important; border:none !important; padding:5px 12px !important; font-size:12px !important; font-weight:bold !important; border-radius:4px !important; cursor:pointer !important; display:inline-block !important;'>Zurückziehen ❌</button>";
                $html .= "</div></div>";
            }
        }


        // =========================================================================
        // 🎮 FALL 2: DAS SPIEL LÄUFT ODER IST BEENDET (SCHWEBENDE ARENA)
        // =========================================================================
        elseif(in_array($game['status'], ['active', 'won_x', 'won_o', 'draw'])) {
            $board_arr = explode(',', $game['board']);
            
            // Gehäuse-Start (Zwingt die Box auf 260px Breite)
            $html .= "<div class='rme-ttt-arena' style='position:relative !important; background:#111116 !important; border:2px solid #7B1FA2 !important; padding:15px !important; border-radius:10px !important; color:#fff !important; text-align:center !important; width:260px !important; min-width:260px !important; box-shadow:0 0 20px rgba(123,31,162,0.6) !important;'>";
            
            // Rotes Schließen-Kreuz oben rechts
            $html .= "<span onclick='rmeAntworteAufEinladung($g_id, \"decline\")' style='position:absolute !important; top:4px !important; right:8px !important; color:#cc2424 !important; font-size:18px !important; font-weight:bold !important; cursor:pointer !important; user-select:none !important;' title='Spiel abbrechen & Arena schließen'>×</span>";
            $html .= "<div class='rme-ttt-title' style='font-weight:bold !important; color:#ffed00 !important; font-size:14px !important; margin-bottom:8px !important; letter-spacing:1px !important;'>❌⭕ TIC-TAC-TOE ARENA</div>";
            
            // Gewinn- und Dran-Logik
            $spiel_vorbei = false;
            if($game['status'] === 'won_x') { 
                $html .= "<div class='rme-ttt-winner' style='color:#00ffaa !important; font-weight:bold !important; font-size:13px !important; margin-bottom:8px !important;'>🎉 SIEG FÜR ❌!<br><small style='color:#fff !important;'>Gewinner: ".htmlspecialchars($game['player_x'], ENT_QUOTES, 'UTF-8')."</small></div>"; 
                $spiel_vorbei = true;
            }
            elseif($game['status'] === 'won_o') { 
                $html .= "<div class='rme-ttt-winner' style='color:#00ffaa !important; font-weight:bold !important; font-size:13px !important; margin-bottom:8px !important;'>🎉 SIEG FÜR ⭕!<br><small style='color:#fff !important;'>Gewinner: ".htmlspecialchars($game['player_o'], ENT_QUOTES, 'UTF-8')."</small></div>"; 
                $spiel_vorbei = true;
            }
            elseif($game['status'] === 'draw') { 
                $html .= "<div class='rme-ttt-draw' style='color:#ff9900 !important; font-size:13px !important; margin-bottom:8px !important; font-weight:bold !important;'>🤝 UNENTSCHIEDEN!</div>"; 
                $spiel_vorbei = true;
            }
            else {
                // 🎯 FIX: Wir holen die Namen und reinigen sie sanft von Rang-Anhängen für die Anzeige
                $filter_anzeige = array("_CU", "[ADMIN]", "[MODERATOR]", "[MOD]", "[HADMIN]");
                $spieler_mit_x = trim(str_replace($filter_anzeige, "", $game['player_x']));
                $spieler_mit_o = trim(str_replace($filter_anzeige, "", $game['player_o']));
                
                // Wer ist wirklich am Zug? turn='X' -> player_x, turn='O' -> player_o
                $wer_ist_wirklich_dran = ($game['turn'] === 'X') ? $spieler_mit_x : $spieler_mit_o;
                $aktuelles_symbol = ($game['turn'] === 'X') ? '❌' : '⭕';
                
                // 🏷️ Zeigt permanent an, wer welches Zeichen hat und wer am Zug ist
                $html .= "<div style='font-size:11px !important; color:#aaa !important; margin-bottom:6px !important; display:block !important;'>❌ " . htmlspecialchars($spieler_mit_x, ENT_QUOTES, 'UTF-8') . " &nbsp;|&nbsp; ⭕ " . htmlspecialchars($spieler_mit_o, ENT_QUOTES, 'UTF-8') . "</div>";
                $html .= "<div class='rme-ttt-status' style='font-size:12px !important; margin-bottom:8px !important; font-weight:bold !important; color:#fff !important; display:block !important;'>Am Zug: " . $aktuelles_symbol . " (<span style='color:#00ff66 !important;'>" . htmlspecialchars($wer_ist_wirklich_dran, ENT_QUOTES, 'UTF-8') . "</span>)</div>";
            }

            
            // =========================================================================
            // 🎨 VISUELLES TUNING: DIE VEREDELTE 3X3 MATRIX MIT NEON-GLOW
            // =========================================================================
            $html .= "<div class='rme-ttt-grid' style='display:grid !important; grid-template-columns:repeat(3, 1fr) !important; gap:8px !important; max-width:190px !important; margin:12px auto !important;'>";
            
            for($i=0; $i<9; $i++) {
                $cell = isset($board_arr[$i]) ? $board_arr[$i] : '';
                
                // Basis-Styles für alle Zellen (Edles Dunkelgrau, weiche Kanten, flüssiger Übergang)
                $style = "width:58px !important; height:55px !important; font-size:22px !important; background:#181822 !important; border:1px solid #333 !important; color:#fff !important; border-radius:6px !important; cursor:pointer !important; font-weight:bold !important; transition: all 0.2s ease-in-out !important; box-shadow: inset 0 0 5px rgba(0,0,0,0.5) !important;";
                
                // Spezial-Styles für gesetzte Symbole (Echter Neon-Glow!)
                if ($cell === 'X') {
                    // Kreuz leuchtet giftig-rot/pink
                    $style .= "color:#ff0055 !important; border-color:#ff0055 !important; text-shadow:0 0 8px #ff0055 !important; box-shadow:0 0 8px rgba(255,0,85,0.3) !important; background:#111 !important;";
                } elseif ($cell === 'O') {
                    // Kreis leuchtet elektrisierend-blau
                    $style .= "color:#00d2ff !important; border-color:#00d2ff !important; text-shadow:0 0 8px #00d2ff !important; box-shadow:0 0 8px rgba(0,210,255,0.3) !important; background:#111 !important;";
                } else {
                    // Leere, klickbare Felder bekommen einen coolen Hover-Effekt vererbt
                    $style .= " border-color:#444 !important;";
                }
                
                // Wenn das Spiel vorbei ist oder das Feld besetzt, wird der Klick blockiert
                $dis = ($game['status'] !== 'active' || $cell !== '') ? 'disabled' : "onmousedown='rmeTttKlick($g_id, $i); event.preventDefault();'";
                
                // Falls klickbar, packen wir einen Inline-Hover-Effekt über CSS-Zusatz rein
                $hover_class = ($cell === '') ? " class='rme-ttt-cell-active' " : "";
                
                $html .= "<button type='button' $hover_class $dis style='$style'>".($cell==='X'?'❌':($cell==='O'?'⭕':' '))."</button>";
            }
            $html .= "</div>";

            
            // =========================================================================
            // 🚪 SPIELENDE: REVANCHE- UND VERLASSEN-BUTTONS IM DOPPELPACK
            // =========================================================================
            if ($game['status'] === 'won_x' || $game['status'] === 'won_o' || $game['status'] === 'draw') {
                
                // 🔄 BUTTON 1: REVANCHE (Setzt das Spiel zurück und fordert den Gegner erneut!)
                $html .= "<button type='button' onclick='rmeAntworteAufEinladung($g_id, \"rematch\"); event.preventDefault();' style='background:#00ff00 !important; color:#000 !important; border:none !important; width:100% !important; padding:8px !important; font-weight:bold !important; border-radius:4px !important; margin-top:14px !important; cursor:pointer !important; box-shadow:0 0 8px rgba(0,255,0,0.4) !important; text-transform:uppercase !important; font-size:11px !important;'>Nochmal spielen 🔄</button>";
                
                // 🚪 BUTTON 2: ARENA VERLASSEN (Löscht das Match komplett)
                $html .= "<button type='button' onclick='rmeAntworteAufEinladung($g_id, \"decline\"); event.preventDefault();' style='background:#7B1FA2 !important; color:#fff !important; border:none !important; width:100% !important; padding:8px !important; font-weight:bold !important; border-radius:4px !important; margin-top:6px !important; cursor:pointer !important; box-shadow:0 0 8px rgba(123,31,162,0.4) !important; text-transform:uppercase !important; font-size:11px !important;'>Arena verlassen 🚪</button>";
            }
            $html .= "</div>"; // Schließt das rme-ttt-arena Gehäuse

        }


        echo json_encode(['html' => $html]);
        exit;
    } else {
        echo json_encode(['html' => '']);
        exit;
    }
}
// =========================================================================
// 🔵🔴 MULTIPLAYER-ENGINE: VIER GEWINNT (TEIL 1: INVITATION & RESPOND)
// =========================================================================

// --- FALL 1: EINLADUNG SENDEN ---
if ($action === "v4g_invite") {
    header('Content-Type: application/json; charset=UTF-8');
    if (!$db) { echo json_encode(['status' => 'error', 'message' => 'Keine DB-Verbindung active.']); exit; }

    $gegner = mysqli_real_escape_string($db, trim($_POST['opponent']));
    if(strtolower($ich_selbst) === strtolower($gegner)) { 
        echo json_encode(['status'=>'error', 'message'=>'Du kannst Dich nicht selbst herausfordern!']); 
        exit; 
    }
    
    // Altes v4g-JSON-Gedächtnis löschen für einen sauberen Neustart
    $state_file = dirname(__FILE__) . "/v4g_state.json";
    if (file_exists($state_file)) { @unlink($state_file); }
    
    $time = time();
    // 41 Kommas erzeugen exakt 42 leere Felder (7 Spalten x 6 Reihen)
    $leeres_board = str_repeat(",", 41);
    
    $insert_query = "INSERT INTO `fusionb7754_chat_v4g` (`player_x`, `player_o`, `status`, `last_update`, `board`, `turn`) 
                     VALUES ('$ich_selbst', '$gegner', 'invited', $time, '$leeres_board', 'X')";
    
    if (mysqli_query($db, $insert_query)) { echo json_encode(['status' => 'success']); } 
    else { echo json_encode(['status' => 'error', 'message' => 'Datenbank-Fehler beim Einladen.']); }
    exit;
}

// --- FALL 2: EINLADUNG BEANTWORTEN / REVANCHE / ABBRECHEN ---
if ($action === "v4g_respond") {
    header('Content-Type: application/json; charset=UTF-8');
    $g_id = isset($_POST['game_id']) ? intval($_POST['game_id']) : 0;
    $response = isset($_POST['response']) ? trim($_POST['response']) : '';
    $time = time();
    
    if ($g_id > 0 && $db) {
        if ($response === 'accept') {
            $leeres_board = str_repeat(",", 41);
            mysqli_query($db, "UPDATE `fusionb7754_chat_v4g` SET `status` = 'active', `board` = '$leeres_board', `turn` = 'X', `last_update` = $time WHERE `id` = $g_id");
        } 
        elseif ($response === 'rematch') {
            $rematch_q = mysqli_query($db, "SELECT * FROM `fusionb7754_chat_v4g` WHERE `id` = $g_id LIMIT 1");
            if ($rematch_q && mysqli_num_rows($rematch_q) > 0) {
                $r_game = mysqli_fetch_assoc($rematch_q);
                
                $state_file = dirname(__FILE__) . "/v4g_state.json";
                if (file_exists($state_file)) { @unlink($state_file); }
                
                $neuer_sender = $ich_selbst;
                $neuer_empfaenger = (strtolower($r_game['player_x']) === strtolower($ich_selbst)) ? $r_game['player_o'] : $r_game['player_x'];
                $leeres_board = str_repeat(",", 41);
                
                $rematch_query = "UPDATE `fusionb7754_chat_v4g` 
                                   SET `player_x` = '".mysqli_real_escape_string($db, $neuer_sender)."', 
                                       `player_o` = '".mysqli_real_escape_string($db, $neuer_empfaenger)."', 
                                       `status` = 'invited', 
                                       `board` = '$leeres_board', 
                                       `turn` = 'X', 
                                       `last_update` = $time 
                                   WHERE `id` = $g_id";
                mysqli_query($db, $rematch_query);
            }
        } 
        else {
            mysqli_query($db, "DELETE FROM `fusionb7754_chat_v4g` WHERE `id` = $g_id");
        }
        echo json_encode(['status' => 'success']);
        exit;
    }
    echo json_encode(['status' => 'error']);
    exit;
}
// --- FALL 3: STÄNDIGER STATUS-SPION & SPIELFELD-GENERATOR ---
if ($action === "v4g_check") {
    header('Content-Type: application/json; charset=UTF-8');
    if (!$db) { echo json_encode(['html' => '']); exit; }

    $time = time();
    $ich_selbst_safe = mysqli_real_escape_string($db, $ich_selbst);

    // 💣 ZEITBOMBEN-SCHUTZ: Löscht alte Leichen erst nach 3 Stunden (10800 Sek.) Inaktivität
    mysqli_query($db, "DELETE FROM `fusionb7754_chat_v4g` WHERE $time - `last_update` > 10800");

    // Suche nach einem Match, wo du entweder Herausforderer oder Empfänger bist
    $query = "SELECT * FROM `fusionb7754_chat_v4g` 
              WHERE (LOWER(`player_x`) = LOWER('$ich_selbst_safe') OR LOWER(`player_o`) = LOWER('$ich_selbst_safe')) 
              ORDER BY `id` DESC LIMIT 1";
    $result = mysqli_query($db, $query);

    $html = "";

    if ($result && mysqli_num_rows($result) > 0) {
        $game = mysqli_fetch_assoc($result);
        $g_id = intval($game['id']);

        // A. STATUS: ENTWEDER DU BIST NOCH BEI DER EINLADUNG
        if($game['status'] === 'invited') {
            if (strtolower($game['player_o']) === strtolower($ich_selbst)) {
                // Ich bin der Empfänger: Einladungsbox anzeigen
                $html .= "<div class='rme-v4g-arena' style='position:relative !important; background:#1a1a24 !important; border:2px solid #00d2ff !important; padding:15px !important; border-radius:8px !important; color:#fff !important; text-align:center !important; width:290px !important; min-width:290px !important; box-shadow:0 0 15px rgba(0,210,255,0.5) !important;'>";
                $html .= "<span onclick='rmeAntworteAufV4gEinladung($g_id, \"decline\")' style='position:absolute !important; top:6px !important; right:12px !important; color:#cc2424 !important; font-size:20px !important; font-weight:bold !important; cursor:pointer !important; user-select:none !important; z-index:999999999 !important;' title='Schließen'>×</span>";
                $html .= "<div style='font-weight:bold !important; color:#ffed00 !important; margin-bottom:10px !important; font-size:13px !important; letter-spacing:0.5px !important;'>⚔️ V4G HERAUSFORDERUNG!</div>";
                $html .= "<div style='font-size:13px !important; line-height:1.4 !important; color:#fff !important;'><strong>".htmlspecialchars($game['player_x'], ENT_QUOTES, 'UTF-8')."</strong> fordert Dich zu Vier Gewinnt heraus!</div>";
                $html .= "<div style='margin-top:12px !important; display:block !important;'>";
                $html .= "<button type='button' onclick='rmeAntworteAufV4gEinladung($g_id, \"accept\"); event.preventDefault();' style='background:#00ff00 !important; color:#000 !important; border:none !important; padding:6px 14px !important; font-weight:bold !important; border-radius:4px !important; margin-right:8px !important; cursor:pointer !important; box-shadow:0 0 5px #00ff00 !important; display:inline-block !important; font-size:12px !important;'>Annehmen</button>";
                $html .= "<button type='button' onclick='rmeAntworteAufV4gEinladung($g_id, \"decline\"); event.preventDefault();' style='background:#cc2424 !important; color:#fff !important; border:none !important; padding:6px 14px !important; font-weight:bold !important; border-radius:4px !important; cursor:pointer !important; display:inline-block !important; font-size:12px !important;'>Ablehnen</button>";
                $html .= "</div></div>";
            } else {
                // Ich bin der Absender: Wartebox anzeigen
                $html .= "<div class='rme-v4g-arena' style='position:relative !important; background:#1a1a24 !important; border:2px solid #00d2ff !important; padding:15px !important; border-radius:8px !important; color:#fff !important; text-align:center !important; width:290px !important; min-width:290px !important; box-shadow:0 0 15px rgba(0,210,255,0.4) !important;'>";
                $html .= "<span onclick='rmeAntworteAufV4gEinladung($g_id, \"decline\")' style='position:absolute !important; top:6px !important; right:12px !important; color:#cc2424 !important; font-size:20px !important; font-weight:bold !important; cursor:pointer !important; user-select:none !important; z-index:999999999 !important;' title='Einladung zurückziehen'>×</span>";
                $html .= "<div style='font-weight:bold !important; color:#00d2ff !important; margin-bottom:10px !important; font-size:13px !important; letter-spacing:0.5px !important;'>📡 V4G GEFORDERT</div>";
                $html .= "<div style='font-size:13px !important; line-height:1.4 !important; color:#fff !important;'>Einladung an <strong>".htmlspecialchars($game['player_o'], ENT_QUOTES, 'UTF-8')."</strong> gesendet.<br><small style='color:#aaa !important; font-size:11px !important;'>Warte auf Rückmeldung...</small></div>";
                $html .= "<div style='margin-top:12px !important; display:block !important;'>";
                $html .= "<button type='button' onclick='rmeAntworteAufV4gEinladung($g_id, \"decline\"); event.preventDefault();' style='background:#cc2424 !important; color:#fff !important; border:none !important; padding:5px 12px !important; font-size:12px !important; font-weight:bold !important; border-radius:4px !important; cursor:pointer !important; display:inline-block !important;'>Zurückziehen ❌</button>";
                $html .= "</div></div>";
            }
        }
        // B. STATUS: DAS SPIEL LÄUFT ODER IST BEENDET (DAS GRAPHISCHE GRID)
        elseif(in_array($game['status'], ['active', 'won_x', 'won_o', 'draw'])) {
            $board_arr = explode(',', $game['board']);
            
            // Gehäuse-Start (Vier Gewinnt braucht exakt 310px Breite)
            $html .= "<div class='rme-v4g-arena' style='position:relative !important; background:#111116 !important; border:2px solid #00d2ff !important; padding:15px !important; border-radius:10px !important; color:#fff !important; text-align:center !important; width:310px !important; min-width:310px !important; box-shadow:0 0 20px rgba(0,210,255,0.5) !important;'>";
            
            // Rotes Schließen-Kreuz oben rechts
            $html .= "<span onclick='rmeAntworteAufV4gEinladung($g_id, \"decline\")' style='position:absolute !important; top:4px !important; right:8px !important; color:#cc2424 !important; font-size:18px !important; font-weight:bold !important; cursor:pointer !important; user-select:none !important;' title='Spiel abbrechen'>×</span>";
            $html .= "<div style='font-weight:bold !important; color:#ffed00 !important; font-size:14px !important; margin-bottom:8px !important; letter-spacing:1px !important;'>🔵🔴 VIER GEWINNT ARENA</div>";
            
            // Namen säubern für die Anzeige
            $filter_anzeige = array("_CU", "[ADMIN]", "[MODERATOR]", "[MOD]", "[HADMIN]");
            $spieler_mit_x = trim(str_replace($filter_anzeige, "", $game['player_x']));
            $spieler_mit_o = trim(str_replace($filter_anzeige, "", $game['player_o']));
            
            if($game['status'] === 'won_x') { 
                $html .= "<div style='color:#00ffaa !important; font-weight:bold !important; font-size:13px !important; margin-bottom:8px !important;'>🎉 SIEG FÜR 🔵!<br><small style='color:#fff !important;'>Gewinner: ".htmlspecialchars($spieler_mit_x, ENT_QUOTES, 'UTF-8')."</small></div>"; 
            }
            elseif($game['status'] === 'won_o') { 
                $html .= "<div style='color:#00ffaa !important; font-weight:bold !important; font-size:13px !important; margin-bottom:8px !important;'>🎉 SIEG FÜR 🔴!<br><small style='color:#fff !important;'>Gewinner: ".htmlspecialchars($spieler_mit_o, ENT_QUOTES, 'UTF-8')."</small></div>"; 
            }
            elseif($game['status'] === 'draw') { 
                $html .= "<div style='color:#ff9900 !important; font-size:13px !important; margin-bottom:8px !important; font-weight:bold !important;'>🤝 UNENTSCHIEDEN!</div>"; 
            }
            else {
                // Wer ist dran? turn='X' -> player_x, turn='O' -> player_o
                $wer_ist_wirklich_dran = ($game['turn'] === 'X') ? $spieler_mit_x : $spieler_mit_o;
                $aktuelles_symbol = ($game['turn'] === 'X') ? '🔵' : '🔴';
                
                $html .= "<div style='font-size:11px !important; color:#aaa !important; margin-bottom:6px !important;'>🔵 $spieler_mit_x &nbsp;|&nbsp; 🔴 $spieler_mit_o</div>";
                $html .= "<div class='rme-v4g-status' style='font-size:12px !important; margin-bottom:8px !important; font-weight:bold !important; color:#fff !important;'>Am Zug: $aktuelles_symbol (<span style='color:#00ff66 !important;'>".htmlspecialchars($wer_ist_wirklich_dran, ENT_QUOTES, 'UTF-8')."</span>)</div>";
            }
            
            // 🎮 DAS REALISTISCHE 7x6 SPIELFELD GENERIEREN
            $html .= "<div style='display:grid !important; grid-template-columns:repeat(7, 1fr) !important; gap:4px !important; max-width:280px !important; margin:10px auto !important; background:#0a0a14 !important; padding:8px !important; border-radius:6px !important; border:1px solid #222 !important;'>";
            
            for($i=0; $i<42; $i++) {
                $cell = isset($board_arr[$i]) ? $board_arr[$i] : '';
                
                // Basis-Design für die runden Löcher
                $style = "width:34px !important; height:34px !important; font-size:16px !important; background:#111116 !important; border:1px solid #333 !important; border-radius:50% !important; display:flex !important; align-items:center !important; justify-content:center !important; padding:0 !important; margin:0 auto !important; font-weight:bold !important; box-shadow:inset 0 0 5px rgba(0,0,0,0.8) !important; cursor:pointer !important; transition:all 0.15s ease-in-out !important;";
                
                $dis_attr = "";
                $click_attr = "";
                $hover_class = "";
                
                if ($cell === 'X') {
                    $style .= "color:#00d2ff !important; border-color:#00d2ff !important; text-shadow:0 0 6px #00d2ff !important; box-shadow:0 0 8px rgba(0,210,255,0.4) !important; background:#050510 !important;";
                    $chip = "🔵";
                    $dis_attr = "disabled";
                } elseif ($cell === 'O') {
                    $style .= "color:#ff0055 !important; border-color:#ff0055 !important; text-shadow:0 0 6px #ff0055 !important; box-shadow:0 0 8px rgba(255,0,85,0.4) !important; background:#050510 !important;";
                    $chip = "🔴";
                    $dis_attr = "disabled";
                } else {
                    $chip = " ";
                    if ($game['status'] !== 'active') {
                        $dis_attr = "disabled";
                    } else {
                        // 🎯 Klicks und Attribute sauber getrennt gegen JSON-Fehler
                        $spalten_index = $i % 7;
                        $click_attr = 'onclick="rmeV4gKlick(' . $g_id . ', ' . $spalten_index . '); event.preventDefault();"';
                        $hover_class = ' class="rme-v4g-cell-active" ';
                    }
                }
                
                $html .= "<button type='button' " . $hover_class . " " . $dis_attr . " " . $click_attr . " style='" . $style . "'>" . $chip . "</button>";
            }
            $html .= "</div>";
            
            // REVANCHE- UND ABBRECHEN-BUTTONS
            if ($game['status'] === 'won_x' || $game['status'] === 'won_o' || $game['status'] === 'draw') {
                $html .= "<button type='button' onclick='rmeAntworteAufV4gEinladung(" . $g_id . ", \"rematch\"); event.preventDefault();' style='background:#00ff00 !important; color:#000 !important; border:none !important; width:100% !important; padding:8px !important; font-weight:bold !important; border-radius:4px !important; margin-top:14px !important; cursor:pointer !important; box-shadow:0 0 8px rgba(0,255,0,0.4) !important; text-transform:uppercase !important; font-size:11px !important;'>Nochmal spielen 🔄</button>";
                $html .= "<button type='button' onclick='rmeAntworteAufV4gEinladung(" . $g_id . ", \"decline\"); event.preventDefault();' style='background:#7B1FA2 !important; color:#fff !important; border:none !important; width:100% !important; padding:8px !important; font-weight:bold !important; border-radius:4px !important; margin-top:6px !important; cursor:pointer !important; box-shadow:0 0 8px rgba(123,31,162,0.4) !important; text-transform:uppercase !important; font-size:11px !important;'>Arena verlassen 🚪</button>";
            }
            $html .= "</div>";
        }

        // 🔥 DER DATEN-AUSWURF: Schickt das fertige HTML als sauberes JSON an den Browser!
        echo json_encode(['html' => $html]);
        exit;
    } else {
        echo json_encode(['html' => '']);
        exit;
    }
}

// =========================================================================
// 🎶 DJ- & MOD-WUNSCHZENTRALE (TEIL 3: UNZERSTÖRBARER SPALTEN-ZUGRIFF)
// =========================================================================

// --- ACTION 1: USER SENDET EINEN WUNSCH / GRUSS IN GETRENNTE SPALTEN ---
if ($action === "send_wunsch") {
    header('Content-Type: application/json; charset=UTF-8');
    if (!$db) { echo json_encode(['status' => 'error', 'message' => 'Keine DB-Verbindung active.']); exit; }

    $typ   = isset($_POST['wunsch_typ']) ? mysqli_real_escape_string($db, trim($_POST['wunsch_typ'])) : 'wunsch';
    $song  = isset($_POST['song_text']) ? mysqli_real_escape_string($db, trim($_POST['song_text'])) : '';
    $gruss = isset($_POST['gruss_text']) ? mysqli_real_escape_string($db, trim($_POST['gruss_text'])) : '';
    
    if (empty($song) && empty($gruss)) {
        echo json_encode(['status' => 'error', 'message' => 'Bitte fülle die Felder aus!']);
        exit;
    }

    $time = time();
    $sauberer_absender = mysqli_real_escape_string($db, $ich_selbst);

    // 🎯 MASTER-FIX: Wir befüllen exakt die Spalten der frisch erstellten Tabelle!
    $insert_q = "INSERT INTO `fusionb7754_chat_wuensche` (`absender`, `typ`, `song_text`, `gruss_text`, `status`, `datestamp`) 
                 VALUES ('$sauberer_absender', '$typ', '$song', '$gruss', 'offen', $time)";
    
    if (mysqli_query($db, $insert_q)) {
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Datenbank-Fehler beim Speichern.']);
    }
    exit;
}

// --- ACTION 2: WÜNSCHE GETRENNT LIVE AUS DEN ECHTEN SPALTEN AUSLESEN ---
if ($action === "get_wuensche") {
    header('Content-Type: application/json; charset=UTF-8');
    if (!$db) { echo json_encode(['html' => '']); exit; }

    // =========================================================================
    // 💣 DIE AUTOMATISCHE 10-MINUTEN-MÜLLABFUHR (NUR FÜR ERLEDIGTE WÜNSCHE!)
    // =========================================================================
    $aktueller_zeitstempel = time();
    // 600 Sekunden = 10 Minuten. Löscht NUR Einträge, die 'gespielt' UND älter als 10 Min. sind!
    $muell_query = "DELETE FROM `fusionb7754_chat_wuensche` 
                    WHERE `status` = 'gespielt' 
                    AND (" . $aktueller_zeitstempel . " - `datestamp` > 600)";
    mysqli_query($db, $muell_query);
    // =========================================================================


    $query = "SELECT * FROM `fusionb7754_chat_wuensche` WHERE `status` = 'offen' ORDER BY `id` DESC LIMIT 20";
    $result = mysqli_query($db, $query);

    $html = "";
    $html .= "<div style='font-weight:bold !important; color:#ffed00 !important; font-size:11px !important; margin-bottom:8px !important; text-transform:uppercase !important; letter-spacing:0.5px !important;'>🎵 Live Wunsch-Ticker:</div>";

    if ($result && mysqli_num_rows($result) > 0) {
        $html .= "<div style='display:flex !important; flex-direction:column !important; gap:8px !important;'>";
        
        while ($row = mysqli_fetch_assoc($result)) {
            $w_id = intval($row['id']);
            $absender = htmlspecialchars($row['absender'], ENT_QUOTES, 'UTF-8');
            $song_inhalt = htmlspecialchars($row['song_text'], ENT_QUOTES, 'UTF-8');
            $gruss_inhalt = htmlspecialchars($row['gruss_text'], ENT_QUOTES, 'UTF-8');
            $typ = $row['typ'];
            
            $label_color = "#00d2ff"; 
            $label_text = "Wunsch";
            if ($typ === 'gruss') {
                $label_color = "#ffaa00"; 
                $label_text = "Gruß";
            } elseif ($typ === 'beides') {
                $label_color = "#cc00ff"; 
                $label_text = "Wunsch & Gruß";
            }

            $html .= "<div style='background:#111116 !important; border:1px solid #333 !important; border-left:3px solid $label_color !important; padding:6px !important; border-radius:4px !important; font-size:11px !important; line-height:1.4 !important; position:relative !important;'>";
            
            // Header
            $html .= "<div style='display:flex !important; justify-content:space-between !important; margin-bottom:6px !important; border-bottom:1px solid #222 !important; padding-bottom:3px !important;'>";
            $html .= "<span style='color:#00ff66 !important; font-weight:bold !important;'>$absender</span>";
            $html .= "<span style='color:$label_color !important; font-size:9px !important; text-transform:uppercase !important; font-weight:bold !important;'>$label_text</span>";
            $html .= "</div>";
            
            // Saubere getrennte Spalten-Ausgabe
            if (!empty($song_inhalt)) {
                $html .= "<div style='color:#00d2ff !important; font-weight:bold !important; margin-bottom:4px !important;'>🎵 Song: <span style='font-weight:normal !important; color:#fff !important;'>$song_inhalt</span></div>";
            }
            
            if (!empty($gruss_inhalt)) {
                if (!empty($song_inhalt)) {
                    $html .= "<div style='border-top:1px dashed #222 !important; margin:4px 0 !important;'></div>";
                }
                $html .= "<div style='color:#ffaa00 !important; font-weight:bold !important;'>✍️ Gruß: <span style='font-weight:normal !important; color:#ccc !important; white-space:pre-wrap !important; word-break:break-word !important;'>$gruss_inhalt</span></div>";
            }
            
            // =========================================================================
            // 🎯 MASTER-FIX FÜR DIE ANFÜHRUNGSZEICHEN: JETZT KOMMT JEDER KLICK DURCH!
            // =========================================================================
            $html .= "<div style='display:flex !important; gap:4px !important; justify-content:flex-end !important; margin-top:6px !important;'>";
            $html .= '<button type="button" onclick="rmeModWunschAktion(' . $w_id . ', \'check\'); event.preventDefault();" style="background:#00ff00 !important; color:#000 !important; border:none !important; padding:2px 6px !important; font-size:10px !important; font-weight:bold !important; border-radius:3px !important; cursor:pointer !important;" title="Als gespielt markieren">✅</button>';
            $html .= '<button type="button" onclick="rmeModWunschAktion(' . $w_id . ', \'delete\'); event.preventDefault();" style="background:#cc2424 !important; color:#fff !important; border:none !important; padding:2px 6px !important; font-size:10px !important; font-weight:bold !important; border-radius:3px !important; cursor:pointer !important;" title="Löschen">🗑️</button>';
            $html .= "</div>";

            
            $html .= "</div>";
        }
        $html .= "</div>";
    } else {
        $html .= "<div style='color:#666 !important; font-size:11px !important; font-style:italic !important; text-align:center !important; padding:10px 0 !important;'>Aktuell keine Wünsche offen.</div>";
    }

    echo json_encode(['html' => $html]);
    exit;
}
// --- ACTION 3: MODERATOR VERARBEITET EINEN WUNSCH (MIT ZEITUHR-RESET) ---
if ($action === "mod_wunsch_action") {
    header('Content-Type: application/json; charset=UTF-8');
    
    $sicherer_db_link = isset($db) ? $db : (isset($db_connect) ? $db_connect : null);
    if (!$sicherer_db_link) { echo json_encode(['status' => 'error', 'message' => 'Keine DB']); exit; }

    $w_id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $cmd  = isset($_POST['cmd']) ? mysqli_real_escape_string($sicherer_db_link, trim($_POST['cmd'])) : '';

    if ($w_id > 0 && !empty($cmd)) {
        if ($cmd === 'check') {
            // 🎯 ZEITUHR-RESET: Wir setzen den Zeitstempel beim Abhaken auf JETZT (time())!
            // Dadurch ticken die 10 Minuten für die Müllabfuhr erst AB dem Klick auf das Häkchen!
            $update_query = "UPDATE `fusionb7754_chat_wuensche` 
                             SET `status` = 'gespielt', `datestamp` = " . time() . " 
                             WHERE `id` = " . $w_id;
            mysqli_query($sicherer_db_link, $update_query);
        } elseif ($cmd === 'delete') {
            // Sofortige, rückstandslose Vernichtung via Papierkorb
            $delete_query = "DELETE FROM `fusionb7754_chat_wuensche` WHERE `id` = " . $w_id;
            mysqli_query($sicherer_db_link, $delete_query);
        }
        
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Ungültige ID oder Befehl']);
    exit;
}

?>
