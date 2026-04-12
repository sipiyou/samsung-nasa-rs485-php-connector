###[DEF]###
[name           = Samsung NASA Protocol Connector v1.00 ]

[e#1 trigger    = (Re)Start/Stopp ]
[e#2 important  = Waveshare IP ]
[e#3 important  = Waveshare Port#init=4196 ]
[e#4 important  = Debug#init=0 ]
[e#5 important  = Wake-Adresse#init=20.00.00 ]
[e#6            = Befehls-Pause in ms#init=100 ]

[e#8 important  = Admin-Interface#init=1 ]

[v#1            = 0] // Exec
###[/DEF]###

###[HELP]###
<a class="cmdButton" href="../samsung_nasa/nasa_admin.php" target="_nasaAdmin">Administration</a>

Samsung NASA Protocol Connector

Dieser LBS stellt eine direkte lokale Verbindung zu Samsung-Klimaanlagen über das NASA-Protokoll her.
Die Kommunikation erfolgt über einen Waveshare RS485-zu-TCP-Konverter.
Eine Cloud-Verbindung ist nicht erforderlich.

Der Baustein darf nur einmal im Projekt verwendet werden!

<b>Hinweis:</b> Nach Änderungen in der Administration (Geräte, KO-Zuordnungen) muss der LBS neu gestartet werden (E1 = 0, dann E1 = 1).

E1  : Betriebsmodus
      0 = LBS stoppen
      1 = LBS starten

E2  : IP-Adresse des Waveshare-Konverters
E3  : Port des Waveshare-Konverters (Default: 4196)
E4  : Debug-Level: 0=Kritisch, 1=Info, 2=Debug, 3=Zugewiesen, 4=Definiert, 5=Rohdaten
      0 = Kritisch: Fehler, LBS beendet
      1	= Info:	Start/Stopp, Verbindung, Konfiguration
      2 = Debug: KO-Schreibvorgänge (read+write), neue Discovery-Entities
      3 = Zugewiesen: RX-Nachrichten die per Admin einem KO zugewiesen sind
      4 = Definiert: Alle RX-Nachrichten mit Protokolldefinition (zusätzlich zu Level 3)
      5 = Rohdaten: RX/TX Hex-Bytes

E5  : NASA-Adresse für Wake-Up-Paket beim LBS-Start (Default: 20.00.00)

E6  : Pause zwischen Sendebefehlen in Millisekunden (Default: 100)
      RS485 ist ein geteiltes Halbduplex-Medium — werden mehrere Befehle gleichzeitig
      an verschiedene Geräte gesendet, können Pakete kollidieren oder verworfen werden.
      Befehle werden intern gepuffert und mit dieser Pause nacheinander gesendet.
      Bei gleichem Zielgerät und gleicher Message-ID gewinnt der neueste Wert.

E8  : Admin-Interface aktivieren (=1) oder deaktivieren (=0)

Die KO-Zuordnung (NASA Message-ID &lt;-&gt; Edomi-KO) erfolgt über die Admin-Oberfläche.
Nach Änderungen in der Admin: LBS über E1=0 stoppen und E1=1 neu starten.

<h2><center>Disclaimer</center></h2>
<b>__INSERT_DISCLAIMER__</b>
###[/HELP]###

###[LBS]###
<?

function LB_LBSID_debug($debugLevel, $level, $str) {
    global $hasWrapper;

    if ($level <= $debugLevel) {
        $labels = array("Kritisch","Info","Debug","Zugewiesen","Definiert","Rohdaten");
        if (isset($hasWrapper)) {
            W_writeToCustomLog("LBS_LBSID", $labels[$level], $str);
        } else {
            writeToCustomLog("LBS_SNASA_LBSID", $labels[$level], $str);
        }
    }
}

function LB_LBSID_installAdmin($adminFile, $debugLevel) {
    $wwwDir = dirname($adminFile);
    if (!is_dir($wwwDir)) {
        mkdir($wwwDir, 0755, true);
    }

    // nasa_admin.php (mit Pfad-Platzhaltern)
    $aPage = gzuncompress(base64_decode("__nasa_admin.txt__"));
    $aPage = str_replace("__INSERT_EDOMI_PATH__", MAIN_PATH, $aPage);
    $aPage = str_replace("__INSERT_LBS_ID__",     LBSID,     $aPage);
    if (!file_put_contents($adminFile, $aPage)) {
        LB_LBSID_debug($debugLevel, 0, "Admin ($adminFile) konnte nicht erstellt werden!");
    } else {
        LB_LBSID_debug($debugLevel, 1, "Admin ($adminFile) aktiviert.");
    }

    // ko_picker.php / ko_picker.css / ko_picker.js
    $staticFiles = [
        "ko_picker.php" => "__ko_picker.php.txt__",
        "ko_picker.css" => "__ko_picker.css.txt__",
        "ko_picker.js"  => "__ko_picker.js.txt__",
    ];
    foreach ($staticFiles as $filename => $encoded) {
        $dest = $wwwDir . "/" . $filename;
        $data = gzuncompress(base64_decode($encoded));
        if (!file_put_contents($dest, $data)) {
            LB_LBSID_debug($debugLevel, 0, "$filename konnte nicht erstellt werden!");
        } else {
            LB_LBSID_debug($debugLevel, 2, "$filename installiert.");
        }
    }
}

function LB_LBSID($id) {
    $ADMIN_FILE = MAIN_PATH . "/www/samsung_nasa/nasa_admin.php";

    if ($E = logic_getInputs($id)) {
        $vars    = logic_getVars($id);
        $running = (int)($vars[1] ?? 0) === 1;

        // E1=0/2 nur weiterleiten wenn EXEC läuft — sonst verwerfen (z.B. Systemstart-Sequenz)
        if (!($E[1]['refresh'] && (int)$E[1]['value'] !== 1 && !$running)) {
            logic_setInputsQueued($id, $E);
        }

        // E8: Admin-Interface installieren / deinstallieren
        if ($E[8]['refresh']) {
            switch ($E[8]['value']) {
            case 0:
                if (file_exists($ADMIN_FILE)) {
                    unlink($ADMIN_FILE);
                }
                LB_LBSID_debug($E[4]['value'], 1, "Admin ($ADMIN_FILE) deaktiviert.");
                break;
            case 1:
                LB_LBSID_installAdmin($ADMIN_FILE, $E[4]['value']);
                break;
            }
        }

        // E1: Daemon starten + Admin bei Bedarf installieren
        if (($E[1]['refresh'] == 1) && ($E[1]['value'] == 1) && !$running) {
            if ($E[8]['value'] == 1) {
                LB_LBSID_installAdmin($ADMIN_FILE, $E[4]['value']);
            }
            logic_setVar($id, 1, 1);
            logic_callExec(LBSID, $id, false);
        }
    }
}
?>
###[/LBS]###

###[EXEC]###
<?php
 //require('wrapper.php');
 require(dirname(__FILE__)."/../../../../main/include/php/incl_lbsexec.php");

sql_connect();
set_time_limit(0);
set_error_handler("customErrorHandler");

// ---- Hilfsfunktionen (müssen vor Verwendung definiert sein) ----

function exec_debug($level, $str) {
    global $debugLevel;
    global $hasWrapper;
    $labels = ["Kritisch", "Info", "Debug", "Zugewiesen", "Definiert", "Rohdaten"];
    if ($level <= $debugLevel) {
        if (isset($hasWrapper)) {
            W_writeToCustomLog("LBS_LBSID", $labels[$level], $str);
        } else {
            writeToCustomLog("LBS_SNASA_LBSID", $labels[$level], $str);
        }
    }
}

function exec_debug_ws($msg) {
    exec_debug(2, "[WS] $msg");
}

function customErrorHandler($errCode, $errText, $errFile, $errRow) {
    if (0 == error_reporting()) return;
    exec_debug(0, "Datei: $errFile | Code: $errCode | Zeile: $errRow | $errText");
}

// ---- Inputs holen ----

$E     = logic_getInputs($id);
$lbsID = LBSID;

if (isset($hasWrapper))
    $E = W_logic_getInputs($id);

$debugLevel = (int)$E[4]['value'];
$wsHost     = trim($E[2]['value']);
$wsPort     = (int)$E[3]['value'];
$wakeAddr   = trim($E[5]['value']) ?: '20.00.01';
$cmdDelay   = (int)$E[6]['value'] / 1000.0; // ms → Sekunden

// ---- Embedded classes entpacken und laden ----

$nasaLibDir  = MAIN_PATH . "/main/include/php/SamsungNasa";
$nasaPtcFile = $nasaLibDir . "/NASA.ptc";

if (!is_dir($nasaLibDir)) {
    mkdir($nasaLibDir, 0755, true);
}

$embeddedFiles = [
    "classWaveshare.php"    => "__classWaveshare.txt__",
    "classNasaProtocol.php" => "__classNasaProtocol.txt__",
    "classNasaDecode.php"   => "__classNasaDecode.txt__",
];

foreach ($embeddedFiles as $filename => $encoded) {
    $path = $nasaLibDir . "/" . $filename;
    file_put_contents($path, gzuncompress(base64_decode($encoded)));
    exec_debug(2, "Installiert: $path");
}

file_put_contents($nasaPtcFile, gzuncompress(base64_decode("__NASA.ptc.txt__")));
exec_debug(2, "Installiert: $nasaPtcFile");

foreach (array_keys($embeddedFiles) as $filename) {
    require_once $nasaLibDir . "/" . $filename;
}

if (empty($wsHost) || $wsPort <= 0) {
    exec_debug(0, "Keine gültige IP/Port konfiguriert. LBS beendet.");
    sql_disconnect();
    die();
}

// ---- DB-Tabellen anlegen falls nicht vorhanden ----

function initDB() {
    // MEMORY-Tabellen: Laufzeit-Cache, gehen bei MySQL-Neustart verloren.
    sql_call("CREATE TABLE IF NOT EXISTS edomiLive.samsungNasaDiscovery (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        address       VARCHAR(12) NOT NULL,
        messageNumber VARCHAR(8) NOT NULL,
        protocolID    VARCHAR(100) DEFAULT NULL,
        unit          VARCHAR(20) DEFAULT NULL,
        messageType   VARCHAR(20) DEFAULT NULL,
        lastRaw       VARCHAR(20)  DEFAULT NULL,
        lastValue     VARCHAR(100) DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_entity (address, messageNumber)
    ) ENGINE=MEMORY");


    sql_call("CREATE TABLE IF NOT EXISTS edomiProject.samsungNasaKoMap (
        id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        address       VARCHAR(12) NOT NULL,
        messageNumber VARCHAR(8) NOT NULL,
        koID          BIGINT UNSIGNED NOT NULL,
        direction     ENUM('r','w','rw') NOT NULL DEFAULT 'r',
        valueModeR    ENUM('decoded','raw') NOT NULL DEFAULT 'decoded',
        valueModeW    ENUM('decoded','raw') NOT NULL DEFAULT 'decoded',
        PRIMARY KEY (id),
        UNIQUE KEY uq_map (address, messageNumber, direction)
    ) ENGINE=MyISAM");

/* Erstmal nicht mehr notwendig
    // Migration: valueModeR/W-Spalten für bestehende Tabellen
    $r = sql_call("SHOW COLUMNS FROM edomiProject.samsungNasaKoMap LIKE 'valueModeR'");
    if (!sql_result($r)) {
        sql_call("ALTER TABLE edomiProject.samsungNasaKoMap ADD COLUMN valueModeR ENUM('decoded','raw') NOT NULL DEFAULT 'decoded'");
        sql_call("ALTER TABLE edomiProject.samsungNasaKoMap ADD COLUMN valueModeW ENUM('decoded','raw') NOT NULL DEFAULT 'decoded'");
    }
*/
}

initDB();

// ---- NASA.ptc parsen ----

$nasaDecoder = new nasaDecodeProtocol($nasaPtcFile);
if (!$nasaDecoder->parseNasaProtocolXML()) {
    exec_debug(0, "NASA.ptc konnte nicht geladen werden. LBS beendet.");
    sql_disconnect();
    die();
}
exec_debug(1, "NASA.ptc geladen: " . count($nasaDecoder->getMessages()) . " Nachrichten.");

// ---- Bekannte Entities aus DB laden (für Discovery) ----

$knownEntities = [];

function loadKnownEntities() {
    global $knownEntities;
    $sql = sql_call("SELECT address, messageNumber FROM edomiLive.samsungNasaDiscovery");
    while ($row = sql_result($sql)) {
        $knownEntities[$row['address']][$row['messageNumber']] = true;
    }
    exec_debug(2, "Discovery: " . array_sum(array_map('count', $knownEntities)) . " bekannte Entities geladen.");
}

loadKnownEntities();

// ---- KO-Mapping aus DB laden ----

$koMapRead  = [];
$koMapWrite = [];

function loadKoMap($id) {
    global $koMapRead, $koMapWrite, $lbsID;

    sql_call("DELETE FROM edomiLive.RAMlogicLink WHERE eingang >= 10 AND elementid = $id");

    $koMapRead  = [];
    $koMapWrite = [];
    $dynIdx = 10;

    $sql = sql_call("SELECT address, messageNumber, koID, direction, valueModeR, valueModeW FROM edomiProject.samsungNasaKoMap");

    while ($row = sql_result($sql)) {
        $msgNum  = hexdec($row['messageNumber']);
        $koID    = (int)$row['koID'];
        $dir     = $row['direction'];
        $address = $row['address'];
        $rawR    = ($row['valueModeR'] === 'raw');
        $rawW    = ($row['valueModeW'] === 'raw');

        if ($dir === 'r' || $dir === 'rw') {
            $koMapRead[$address][$msgNum] = ['koID' => $koID, 'raw' => $rawR];
            exec_debug(3, sprintf("KO-Map read:  %s / 0x%X -> KO %d (%s)", $address, $msgNum, $koID, $row['valueModeR']));
        }
        if ($dir === 'w' || $dir === 'rw') {
            $koMapWrite[$dynIdx] = [
                'messageNumber' => $msgNum,
                'koID'          => $koID,
                'address'       => $address,
                'raw'           => $rawW,
            ];
            sql_call("INSERT INTO edomiLive.RAMlogicLink (elementid, functionid, eingang, linktyp, linkid, ausgang, init, refresh, value) "
                   . "VALUES ($id, $lbsID, $dynIdx, 0, $koID, NULL, 2, 0, '0')");
            exec_debug(3, sprintf("KO-Map write: Eingang %d, 0x%X -> KO %d", $dynIdx, $msgNum, $koID));
            $dynIdx++;
        }
    }

    exec_debug(1, "KO-Map geladen: " . count($koMapRead) . " read, " . count($koMapWrite) . " write Einträge.");
}

loadKoMap($id);

// ---- Waveshare verbinden ----

$waveshare = new WaveshareClient('exec_debug_ws', $wsHost, $wsPort, 2, 10);

exec_debug(1, "Verbinde mit Waveshare $wsHost:$wsPort ...");
if (!$waveshare->connect()) {
    exec_debug(0, "Verbindung zu Waveshare fehlgeschlagen. LBS beendet.");
    sql_disconnect();
    die();
}

// Bus wecken: nach dem Start sofort ein Request senden,
// damit der RS485-Bus aus dem Stromsparmodus kommt.
sendWakePacket();

// ---- Hauptschleife ----

$dataBlock    = [];
$receiving    = false;
$lastStatus   = array(); // [koID => letzter geschriebener Wert] — Send-by-Change
$cmdQueue     = array(); // ['addr+msgNum' => [address, msgNum, rawValue, koValue]] — Send-Queue
$cmdQueueKeys = array(); // geordnete Schlüsselliste (FIFO, neuester Wert gewinnt)
$lastSendTime = 0.0;     // microtime des letzten gesendeten Befehls

do {
    $binData = $waveshare->readDataAsBinaryArray();

    if ($binData !== null) {
        foreach ($binData as $byte) {
            if ($byte === 0x32 && !$receiving) {
                $dataBlock = [];
                $receiving = true;
            }

            if ($receiving) {
                $dataBlock[] = $byte;

                if (count($dataBlock) < 14 || $byte !== 0x34) {
                    continue;
                }

                $receiving = false;
                if ($debugLevel >= 5) exec_debug(5, sprintf("RX Roh-Daten (%d Bytes): %s", count($dataBlock), implode(' ', array_map(fn($b) => sprintf('%02X', $b), $dataBlock))));
                processPacket($dataBlock);
            }
        }
    }

    if ($E = logic_getInputsQueued($id)) {
        if (isset($E[1]['refresh']) && $E[1]['refresh'] && (int)$E[1]['value'] !== 1) {
            exec_debug(1, "E1=" . (int)$E[1]['value'] . " gesetzt. LBS beenden.");
            break;
        }
        if (isset($E[6]['refresh']) && $E[6]['refresh']) {
            $cmdDelay = (int)$E[6]['value'] / 1000.0;
            exec_debug(2, "E6: Befehls-Pause aktualisiert: " . (int)$E[6]['value'] . "ms");
        }
        processEdomiRequests($E, $cmdQueue, $cmdQueueKeys);
    }

    // Queue-Versand: ein Befehl pro Iteration, mit konfigurierter Pause
    if (!empty($cmdQueue)) {
        $now = microtime(true);
        if ($now - $lastSendTime >= $cmdDelay) {
            $key = array_shift($cmdQueueKeys);
            if (isset($cmdQueue[$key])) {
                $entry = $cmdQueue[$key];
                unset($cmdQueue[$key]);
                if ($debugLevel >= 2) exec_debug(2, sprintf("TX NASA 0x%X = %s (raw=%d) an %s", $entry['msgNum'], $entry['koValue'], $entry['rawValue'], $entry['address']));
                $packet  = Packet::create(Address::parse($entry['address']), DataType::Write, $entry['msgNum'], $entry['rawValue']);
                $encoded = $packet->encode();
                if ($debugLevel >= 5) exec_debug(5, sprintf("TX Roh-Daten (%d Bytes): %s", count($encoded), implode(' ', array_map(fn($b) => sprintf('%02X', $b), $encoded))));
                $waveshare->writeArrayToSocket($encoded);
                $lastSendTime = $now;
            }
        }
    }

} while (getSysInfo(1) >= 1);

exec_debug(1, "LBS beendet.");
logic_setVar($id, 1, 0);
sql_disconnect();

// ---- Bus wecken ----

function sendWakePacket() {
    global $waveshare, $wakeAddr;

    exec_debug(1, "Bus wecken: sende Request an $wakeAddr");
    $packet = Packet::create(Address::parse($wakeAddr), DataType::Request, MessageNumber::ENUM_in_operation_power, 0);
    $packet->command->packetNumber = 1;
    $waveshare->writeArrayToSocket($packet->encode());
}

// ---- Paketverarbeitung ----

function processPacket(array $dataBlock) {
    global $nasaDecoder, $koMapRead, $knownEntities, $debugLevel, $lastStatus;

    $packet = new Packet();
    if ($packet->decode($dataBlock) !== 'Ok') {
        return;
    }

    $srcAddr = $packet->sa->toString();

    foreach ($packet->messages as $msg) {
        $msgNum = $msg->messageNumber;

        // Wert dekodieren (für Discovery + KO-Ausgabe)
        $rawValue = $msg->value;
        $obj = $nasaDecoder->getItemObject($msgNum);
        if ($obj !== null) {
            list($unit, $hrName, $decodedValue) = $obj->decodeMessage($rawValue);
            $lastValue = (string)$decodedValue;
        } else {
            $decodedValue = $rawValue;
            $lastValue    = (string)$rawValue;
        }

        // Discovery aktualisieren (neu oder lastValue/lastRaw refreshen)
        updateDiscovery($srcAddr, $msgNum, $lastValue, $rawValue);

        // Debug-Ausgabe für empfangene Nachrichten
        if ($debugLevel >= 3) {
            $isMapped = isset($koMapRead[$srcAddr][$msgNum]);
            if ($isMapped) {
                // Level 3: per Admin zugewiesen
                if ($obj !== null) {
                    exec_debug(3, sprintf("RX NASA 0x%X (%s) = %s (raw=%d) von %s",
                        $msgNum, $hrName, $lastValue, $rawValue, $srcAddr));
                } else {
                    exec_debug(3, sprintf("RX NASA 0x%X = %d von %s (kein Decoder)",
                        $msgNum, $rawValue, $srcAddr));
                }
            } elseif ($debugLevel >= 4 && $obj !== null) {
                // Level 4: nicht zugewiesen, aber in Protokolldefinition vorhanden
                exec_debug(4, sprintf("RX NASA 0x%X (%s) = %s (raw=%d) von %s",
                    $msgNum, $hrName, $lastValue, $rawValue, $srcAddr));
            }
        }

        // KO schreiben wenn gemappt
        if (!isset($koMapRead[$srcAddr][$msgNum])) {
            continue;
        }

        $readEntry   = $koMapRead[$srcAddr][$msgNum];
        $koID        = $readEntry['koID'];
        $useRaw      = $readEntry['raw'];
        $valueForKo  = $useRaw ? $rawValue : $decodedValue;
        if ($debugLevel >= 2) {
            if ($obj !== null) {
                exec_debug(2, sprintf("NASA 0x%X (%s) = %s (raw=%d) -> KO %d [%s]",
                    $msgNum, $hrName, $lastValue, $rawValue, $koID, $useRaw ? 'raw' : 'decoded'));
            } else {
                exec_debug(2, sprintf("NASA 0x%X = %s -> KO %d [%s] (kein Decoder)",
                    $msgNum, $msg->value, $koID, $useRaw ? 'raw' : 'decoded'));
            }
        }
        $cacheKey = (string)$koID;
        if (!isset($lastStatus[$cacheKey]) || (string)$lastStatus[$cacheKey] !== (string)$valueForKo) {
            $lastStatus[$cacheKey] = $valueForKo;
            writeGA($koID, $valueForKo);
        }
    }
}

function updateDiscovery($address, $msgNum, $lastValue, $rawValue) {
    global $knownEntities, $nasaDecoder;

    $msgHex = sprintf("0x%X", $msgNum);
    $pA  = "'" . addslashes($address)          . "'";
    $pM  = "'" . addslashes($msgHex)           . "'";
    $pV  = "'" . addslashes($lastValue)        . "'";
    $pR  = "'" . addslashes((string)$rawValue) . "'";

    if (isset($knownEntities[$address][$msgHex])) {
        sql_call("UPDATE edomiLive.samsungNasaDiscovery SET lastRaw=$pR, lastValue=$pV WHERE address=$pA AND messageNumber=$pM");
        return;
    }

    $obj        = $nasaDecoder->getItemObject($msgNum);
    $protocolID = $obj ? $obj->ProtocolID : null;
    $unit       = $obj ? $obj->Unit       : null;
    $msgType    = null;
    if ($obj !== null) {
        $typeMap = [MessageSetType::Enum => 'Enum', MessageSetType::Variable => 'Variable',
                    MessageSetType::LongVariable => 'LongVariable', MessageSetType::Structure => 'Structure'];
        $rawType = ($msgNum & 0x600) >> 9;
        $msgType = $typeMap[$rawType] ?? null;
    }

    $pID = $protocolID ? "'" . addslashes($protocolID) . "'" : 'NULL';
    $pU  = $unit       ? "'" . addslashes($unit)       . "'" : 'NULL';
    $pT  = $msgType    ? "'" . addslashes($msgType)    . "'" : 'NULL';

    sql_call("INSERT INTO edomiLive.samsungNasaDiscovery
              (address, messageNumber, protocolID, unit, messageType, lastRaw, lastValue)
              VALUES ($pA, $pM, $pID, $pU, $pT, $pR, $pV)
              ON DUPLICATE KEY UPDATE lastRaw=$pR, lastValue=$pV");

    $knownEntities[$address][$msgHex] = true;
    exec_debug(2, "Discovery: neue Entity $address / $msgHex ($protocolID)");
}

function processEdomiRequests(array $E, array &$cmdQueue, array &$cmdQueueKeys) {
    global $koMapWrite, $nasaDecoder, $debugLevel;

    foreach ($E as $key => $val) {
        if ($key < 10 || !$val['refresh']) {
            continue;
        }
        if (!isset($koMapWrite[$key])) {
            continue;
        }

        $entry    = $koMapWrite[$key];
        $msgNum   = $entry['messageNumber'];
        $address  = $entry['address'];
        $koVal    = getGADataFromID($entry['koID'], 0, "value");
        $koValue  = isset($koVal['value']) ? $koVal['value'] : 0;

        if ($entry['raw']) {
            $rawValue = (int)$koValue;
        } else {
            $obj      = $nasaDecoder->getItemObject($msgNum);
            $rawValue = ($obj !== null) ? $obj->encodeMessage($koValue) : (int)$koValue;
        }

        // In Queue einreihen — neuester Wert gewinnt (überschreibt, Position bleibt erhalten)
        $qKey = $address . ':' . $msgNum;
        if (!isset($cmdQueue[$qKey])) {
            $cmdQueueKeys[] = $qKey;
        }
        $cmdQueue[$qKey] = array(
            'address'  => $address,
            'msgNum'   => $msgNum,
            'rawValue' => $rawValue,
            'koValue'  => $koValue,
        );
        if ($debugLevel >= 2) exec_debug(2, sprintf("Queue: NASA 0x%X = %s (raw=%d) an %s", $msgNum, $koValue, $rawValue, $address));
    }
}
?>
###[/EXEC]###
