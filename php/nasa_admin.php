<?php
/*
  Samsung NASA Protocol Connector - Admin Interface
  (c) 2025 Nima Ghassemi Nejad

  Wird vom LBS als embedded Datei unter EDOMI_ROOT/www/nasa_admin.php installiert.
  Direktaufruf zu Testzwecken möglich wenn EDOMI_PATH gesetzt ist.
*/

define('MAIN_PATH', '__INSERT_EDOMI_PATH__');

$mysqli = mysqli_connect("localhost", "root", "", "");

// KO-Picker AJAX-Handler (behandelt ?action=koTree und ?action=koSearch)
require_once dirname(__FILE__) . '/ko_picker.php';

// ---- NASA-Protokoll laden (für Tooltips) ----
$nasaDecoder = null;
$nasaPtcFile  = MAIN_PATH . "/main/include/php/SamsungNasa/NASA.ptc";
$nasaDecodeClass = MAIN_PATH . "/main/include/php/SamsungNasa/classNasaDecode.php";
if (file_exists($nasaPtcFile) && file_exists($nasaDecodeClass)) {
    require_once $nasaDecodeClass;
    $nasaDecoder = new nasaDecodeProtocol($nasaPtcFile);
    $nasaDecoder->parseNasaProtocolXML();
}

function nasaInfoHtml($msgNumHex, $nasaDecoder) {
    if ($nasaDecoder === null) return '';
    $msgNum = hexdec($msgNumHex);
    $obj = $nasaDecoder->getItemObject($msgNum);
    if ($obj === null) return '';

    $h = '<b>' . htmlspecialchars($obj->ProtocolID) . '</b><br>';
    if (!empty($obj->Enum)) {
        $h .= '<table style="border-collapse:collapse;margin-top:4px">';
        $h .= '<tr><th style="padding:1px 8px 1px 0;color:#505050">Raw</th><th style="padding:1px 0;color:#505050">Wert</th></tr>';
        foreach ($obj->Enum as $item) {
            $h .= '<tr><td style="padding:1px 8px 1px 0;color:#209020;font-weight:bold">' . htmlspecialchars($item['Value']) . '</td>'
                . '<td>' . htmlspecialchars($item['String']) . '</td></tr>';
        }
        if (!empty($obj->EnumDefault)) {
            foreach ($obj->EnumDefault as $def) {
                if ($def['Tag'] === 'String') {
                    $h .= '<tr><td style="color:#a0a0a0">*</td><td>' . htmlspecialchars($def['Value']) . '</td></tr>';
                }
            }
        }
        $h .= '</table>';
    } else {
        $rows = [];
        if (!empty($obj->Range))      $rows[] = ['Bereich', $obj->Range['Min'] . ' – ' . $obj->Range['Max']];
        if (!empty($obj->Unit))       $rows[] = ['Einheit', $obj->Unit];
        if (!empty($obj->Arithmatic)) $rows[] = ['Arithmetik', $obj->Arithmatic['Operation'] . ' ' . $obj->Arithmatic['Value']];
        if (!empty($obj->Variable) && isset($obj->Variable['Signed'])) {
            $rows[] = ['Vorzeichen', $obj->Variable['Signed']];
        }
        if ($obj->Structure) $rows[] = ['Typ', 'Binärstruktur'];
        if ($rows) {
            $h .= '<table style="border-collapse:collapse;margin-top:4px">';
            foreach ($rows as $r) {
                $h .= '<tr><td style="padding:1px 10px 1px 0;color:#505050">' . htmlspecialchars($r[0]) . '</td>'
                    . '<td>' . htmlspecialchars($r[1]) . '</td></tr>';
            }
            $h .= '</table>';
        }
    }
    return $h;
}

function nasaAccess($protocolID) {
    if (preg_match('/_out_/i', $protocolID)) return 'R';
    return 'RW';
}

// ---- POST: KO-Zuweisung speichern / löschen ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'save') {
        $address       = trim($_POST['address']);
        $messageNumber = trim($_POST['messageNumber']);
        $koID          = (int)$_POST['koID'];
        $direction     = in_array($_POST['direction'], ['r','w','rw']) ? $_POST['direction'] : 'r';

        if ($address && $messageNumber && $koID > 0) {
            $stmt = $mysqli->prepare("INSERT INTO edomiProject.samsungNasaKoMap
                (address, messageNumber, koID, direction)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE koID=VALUES(koID)");
            $stmt->bind_param("ssis", $address, $messageNumber, $koID, $direction);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'err' => 'Ungültige Parameter']);
        }

    } elseif ($_POST['action'] === 'setValueMode') {
        $mapID    = (int)$_POST['id'];
        $field    = $_POST['field'] === 'w' ? 'valueModeW' : 'valueModeR';
        $newMode  = in_array($_POST['valueMode'], ['decoded','raw']) ? $_POST['valueMode'] : 'decoded';
        $mysqli->query("UPDATE edomiProject.samsungNasaKoMap SET $field='$newMode' WHERE id=$mapID");
        echo json_encode(['ok' => true]);

    } elseif ($_POST['action'] === 'delete') {
        $mapID = (int)$_POST['id'];
        $mysqli->query("DELETE FROM edomiProject.samsungNasaKoMap WHERE id = $mapID");
        echo json_encode(['ok' => true]);

    } elseif ($_POST['action'] === 'clearDiscovery') {
        $mysqli->query("DELETE FROM edomiLive.samsungNasaDiscovery");
        echo json_encode(['ok' => true]);
    }

    $mysqli->close();
    die();
}

// ---- Daten laden ----
$entities = [];
$result = $mysqli->query("SELECT id, address, messageNumber, protocolID, unit, messageType, lastRaw, lastValue
    FROM edomiLive.samsungNasaDiscovery ORDER BY address, messageNumber");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $entities[$row['address']][] = $row;
    }
}

$koMap = [];
$result = $mysqli->query("SELECT m.id, m.address, m.messageNumber, m.koID, m.direction, m.valueModeR, m.valueModeW,
        k.name AS koName, k.ga AS koGA, k.gatyp AS koGAtyp
    FROM edomiProject.samsungNasaKoMap m
    LEFT JOIN edomiProject.editKo k ON k.id = m.koID");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $koMap[$row['address']][$row['messageNumber']][$row['direction']] = $row;
    }
}

// Mappings ohne Discovery-Eintrag ergänzen (nach MySQL-Neustart)
foreach ($koMap as $address => $msgs) {
    foreach ($msgs as $msgNum => $dirs) {
        $found = false;
        if (isset($entities[$address])) {
            foreach ($entities[$address] as $e) {
                if ($e['messageNumber'] === $msgNum) { $found = true; break; }
            }
        }
        if (!$found) {
            $entities[$address][] = [
                'id' => null, 'address' => $address, 'messageNumber' => $msgNum,
                'protocolID' => null, 'unit' => null, 'messageType' => null,
                'lastRaw' => null, 'lastValue' => null,
            ];
        }
    }
}

$totalEntities = array_sum(array_map('count', $entities));
$totalMapped   = array_sum(array_map(function($msgs) { return array_sum(array_map('count', $msgs)); }, $koMap));

$mysqli->close();
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<title>Samsung NASA Admin</title>
<link rel="stylesheet" href="ko_picker.css">
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; font-size: 12px; background: #343434; color: #000000; margin: 0; padding: 10px; }
input, select, textarea { font-family: inherit; font-size: inherit; color: #000000; background: #ffffff; }

.page-wrap {
    display: inline-block; border-radius: 3px;
    box-shadow: 3px 10px 40px #303030;
    background: -webkit-linear-gradient(top, #ffffff 0px, #f0f0e9 74px, #ffffff 74px);
    background: linear-gradient(to bottom, #ffffff 0px, #f0f0e9 74px, #ffffff 74px);
    background-repeat: no-repeat; background-color: #ffffff;
    text-align: left; width: 100%; padding: 10px 12px 15px;
}
h1 { font-size: 15px; font-weight: bold; color: #343434; margin: 0 0 10px; }
h2 { font-size: 13px; color: #343434; margin: 15px 0 5px; padding-top: 15px; border-bottom: 1px dotted #a0a0a0; padding-bottom: 3px; }

.toolbar { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.toolbar .cmdButton {
    display: inline-block; font-family: inherit; font-size: inherit;
    padding: 5px; text-align: center; color: #000000;
    background: -webkit-linear-gradient(top, #d9d9d9 0%, #f0f0f0 100%);
    background: linear-gradient(to bottom, #d9d9d9 0%, #f0f0f0 100%);
    cursor: pointer; line-height: 15px; border: 1px solid #c0c0c0;
    border-radius: 3px; min-width: 62px; height: 27px;
}
.toolbar .cmdButton:hover { background: #f0f0f0; }
.toolbar .cmdButton.danger { border-color: #c00000; color: #c00000; }
.toolbar .cmdButton.danger:hover { background: #c00000; color: #ffffff; }
.stat { color: #343434; }
.stat span { font-weight: bold; color: #000000; }

table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
.table-scroll { overflow-y: auto; max-height: calc(100vh - 160px); }
thead { position: sticky; top: 0; z-index: 5; }
thead th { background: -webkit-linear-gradient(top, #d9d9d9 0%, #f0f0f0 100%);
    background: linear-gradient(to bottom, #d9d9d9 0%, #f0f0f0 100%);
    border: 1px solid #c0c0c0; padding: 5px 6px; text-align: left;
    font-weight: bold; color: #000000; }
tbody tr:nth-child(even) { background: #f5f5f0; }
tbody tr:hover { background: #e0e0e0; }
td { padding: 3px 6px; vertical-align: middle; position: relative; border-bottom: 1px solid #e8e8e8; }
.addr-header { background: #e0e0d8; font-weight: bold; color: #343434; padding: 5px 6px; border-top: 1px solid #c0c0c0; }

.type-Enum         { color: #209020; }
.type-Variable     { color: #0000c0; }
.type-LongVariable { color: #c06000; }
.type-Structure    { color: #800080; }

/* KO-Zell-Anzeige */
.ko-cell {
    display: flex; align-items: center; gap: 4px;
    min-height: 20px; cursor: pointer;
    padding: 2px 4px;
    border: 1px solid #d9d9d9; background: #ffffff;
    transition: border-color 0.1s;
}
.ko-cell:hover { border-color: #808080; background: #f0f0e9; }
.ko-cell-empty { color: #707070; font-style: italic; flex: 1; }
.ko-cell-name  { flex: 1; color: #00a000; }
.ko-ga-badge {
    display: inline-block;
    padding: 0 2px; white-space: nowrap;
}
.ko-ga-badge.gatyp1 { background: #209020; color: #ffffff; }
.ko-ga-badge.gatyp2 { background: #606060; color: #ffffff; }
.ko-ga-badge.gatyp3 { background: #606060; color: #ffffff; }
.ko-btn-del {
    padding: 0 4px; border: 1px solid #c0c0c0;
    background: -webkit-linear-gradient(top, #d9d9d9 0%, #f0f0f0 100%);
    background: linear-gradient(to bottom, #d9d9d9 0%, #f0f0f0 100%);
    color: #c00000; cursor: pointer; flex-shrink: 0;
    line-height: 16px;
}
.ko-btn-del:hover { background: #c00000; color: #ffffff; border-color: #900000; }
.ko-btn-mode {
    padding: 0 4px; border: 1px solid #c0c0c0; font-size: 10px;
    background: -webkit-linear-gradient(top, #d9d9d9 0%, #f0f0f0 100%);
    background: linear-gradient(to bottom, #d9d9d9 0%, #f0f0f0 100%);
    cursor: pointer; flex-shrink: 0; line-height: 16px; color: #000000;
}
.ko-btn-mode.mode-raw  { background: #e06000; color: #ffffff; border-color: #a04000; }
.ko-btn-mode:hover     { background: #d0d0d0; }

.btn-save {
    display: inline-block; font-family: inherit; font-size: inherit;
    padding: 5px; text-align: center; color: #000000;
    background: -webkit-linear-gradient(top, #80e020 0%, #50b000 100%);
    background: linear-gradient(to bottom, #80e020 0%, #50b000 100%);
    cursor: pointer; line-height: 15px; border: 1px solid #409000;
    border-radius: 3px; height: 27px; min-width: 62px;
}
.btn-save:hover { background: #80e000; }

.info-btn { cursor: pointer; color: #0000e0; margin-left: 4px; user-select: none; text-decoration: none; }
.info-btn:hover { color: #0000e0; text-decoration: underline; }
.info-panel {
    display: none; position: absolute; z-index: 200;
    background: #ffffff; border: 1px solid #c0c0c0;
    border-radius: 3px; padding: 6px 10px; color: #000000;
    min-width: 200px; box-shadow: 0 3px 10px #505050;
    user-select: text; cursor: text;
}
.info-panel.visible { display: block; }
.msg { padding: 5px 10px; margin: 6px 0; border: 1px solid #c0c0c0; border-radius: 3px; }
.msg-ok  { background: #e8f5e8; color: #209020; border-color: #90c090; }
.msg-err { background: #f5e8e8; color: #c00000; border-color: #c09090; }

.manual-form { background: #f0f0e9; padding: 8px; border: 1px solid #d0d0d0; display: flex; gap: 8px; align-items: flex-end; flex-wrap: wrap; }
.manual-form label { color: #343434; display: block; margin-bottom: 2px; }
.manual-form input, .manual-form select {
    padding: 2px 3px; border: 1px solid #d9d9d9; background: #ffffff;
    color: #000000; height: 22px;
}
.filter-row th { background: #e8e8e0; padding: 2px 3px; }
.filter-input {
    width: 100%; padding: 1px 3px; border: 1px solid #c0c0c0;
    background: #ffffff; font-size: 11px; font-family: inherit; color: #000000;
}
.filter-clear { cursor: pointer; color: #808080; padding: 0 3px; user-select: none; }
.filter-clear:hover { color: #c00000; }
</style>
</head>
<body>
<div class="page-wrap">

<h1>Samsung NASA &mdash; Admin</h1>

<p style="background:#f4ece8;border:1px solid #c08080;padding:6px 8px;margin-bottom:8px;border-radius:3px">Nach &Auml;nderungen an Ger&auml;ten oder KO-Zuordnungen muss der LBS neu gestartet werden (E1 = 0, dann E1 = 1).</p>

<div class="toolbar">
    <div class="stat">Entities auf Bus: <span><?= $totalEntities ?></span></div>
    <div class="stat">KO-Zuweisungen: <span><?= $totalMapped ?></span></div>
    <select id="f_dev" onchange="filterTable()" style="height:27px; padding:0 4px; border:1px solid #c0c0c0;">
        <option value="">Alle Geräte</option>
        <?php foreach (array_keys($entities) as $addr): ?>
        <option value="<?= htmlspecialchars($addr) ?>"><?= htmlspecialchars($addr) ?></option>
        <?php endforeach; ?>
    </select>
    <div class="cmdButton danger" onclick="clearDiscovery()">Discovery zurücksetzen</div>
    <div style="margin-left:auto; color:#F00000;">
        Nach Änderungen: LBS über E1=0/1 neu starten.
    </div>
</div>

<div id="msg"></div>

<?php if (count($entities) > 0): ?>

<h2>Discovered Entities</h2>
<div class="table-scroll">
<table>
    <thead>
        <tr>
            <th style="width:90px">Message-ID</th>
            <th>Name (ProtocolID)</th>
            <th style="width:60px">Typ</th>
            <th style="width:60px">Einheit</th>
            <th style="width:70px">Wert</th>
            <th style="width:60px">Rohwert</th>
            <th style="width:40px" title="Basiert auf ProtocolID-Konvention">Zugriff</th>
            <th style="width:220px">Read-KO (NASA → Edomi)</th>
            <th style="width:220px">Write-KO (Edomi → NASA)</th>
        </tr>
        <tr class="filter-row">
            <th><input class="filter-input" id="f_msg"  type="text" placeholder="Filter…" oninput="filterTable()"></th>
            <th><input class="filter-input" id="f_name" type="text" placeholder="Filter…" oninput="filterTable()"></th>
            <th><input class="filter-input" id="f_typ"  type="text" placeholder="Filter…" oninput="filterTable()"></th>
            <th></th>
            <th><input class="filter-input" id="f_val"  type="text" placeholder="Filter…" oninput="filterTable()"></th>
            <th><input class="filter-input" id="f_raw"  type="text" placeholder="Filter…" oninput="filterTable()"></th>
            <th><span class="filter-clear" onclick="clearFilter()" title="Filter löschen">&#x2715;</span></th>
            <th></th>
            <th></th>
        </tr>
    </thead>
    <tbody id="discoveryTbody">
    <?php foreach ($entities as $address => $msgs): ?>
        <tr data-addr="<?= htmlspecialchars($address) ?>"><td colspan="9" class="addr-header">&#9654; Gerät: <?= htmlspecialchars($address) ?></td></tr>
        <?php foreach ($msgs as $e):
            $msgNum      = $e['messageNumber'];
            $mapR        = $koMap[$address][$msgNum]['r']  ?? null;
            $mapW        = $koMap[$address][$msgNum]['w']  ?? null;
            $mapRW       = $koMap[$address][$msgNum]['rw'] ?? null;
            $typeClass   = 'type-' . ($e['messageType'] ?: 'Variable');
            $infoHtml    = nasaInfoHtml($msgNum, $nasaDecoder);
            $infoId      = 'info_' . substr(md5($address . $msgNum), 0, 8);
            $access      = nasaAccess($e['protocolID'] ?: '');
            $noDiscovery = ($e['id'] === null);

            $existR    = $mapR ?: $mapRW;
            $existRid  = $existR ? (int)$existR['id']       : 0;
            $existRko  = $existR ? (int)$existR['koID']     : 0;
            $existRdir = $existR ? $existR['direction']     : 'r';
            $existRvm  = $existR ? ($existR['valueModeR'] ?? 'decoded') : 'decoded';

            $existW   = $mapW ?: ($existRdir === 'rw' ? $mapRW : null);
            $existWid = $existW ? (int)$existW['id']       : 0;
            $existWko = $existW ? (int)$existW['koID']     : 0;
            $existWvm = $existW ? ($existW['valueModeW'] ?? 'decoded') : 'decoded';
        ?>
        <tr<?= $noDiscovery ? ' style="opacity:.6" title="Kein Discovery-Eintrag"' : '' ?>>
            <td><code><?= htmlspecialchars($msgNum) ?></code></td>
            <td>
                <span class="proto-id"><?= htmlspecialchars($e['protocolID'] ?: '—') ?></span>
                <?php if ($infoHtml): ?>
                    <span class="info-btn" onclick="toggleInfo('<?= $infoId ?>'); event.stopPropagation()">&#9432;</span>
                    <div class="info-panel" id="<?= $infoId ?>"><?= $infoHtml ?></div>
                <?php endif; ?>
            </td>
            <td class="<?= $typeClass ?>"><?= htmlspecialchars($e['messageType'] ?: '—') ?></td>
            <td><?= htmlspecialchars($e['unit'] ?: '—') ?></td>
            <td style="color:#209020;font-weight:bold"><?= htmlspecialchars($e['lastValue'] ?? '—') ?></td>
            <td style="color:#505050"><?= htmlspecialchars($e['lastRaw'] ?? '—') ?></td>
            <td style="color:<?= $access === 'R' ? '#c00000' : '#209020' ?>;font-weight:bold"><?= $access ?></td>

            <!-- Read-KO -->
            <td>
                <div class="ko-cell"
                     data-addr="<?= htmlspecialchars($address) ?>"
                     data-msg="<?= htmlspecialchars($msgNum) ?>"
                     data-dir="r"
                     data-mapid="<?= $existRid ?>"
                     data-koid="<?= $existRko ?>"
                     data-vmode="<?= $existRvm ?>"
                     onclick="nasaPickerOpen(this)">
                    <?php if ($existR): ?>
                        <span class="ko-ga-badge gatyp<?= (int)($existR['koGAtyp'] ?? 1) ?>"><?= htmlspecialchars($existR['koGA'] ?: 'KO'.$existRko) ?></span>
                        <span class="ko-cell-name"><?= htmlspecialchars($existR['koName'] ?: '?') ?></span>
                        <button class="ko-btn-mode<?= $existRvm === 'raw' ? ' mode-raw' : '' ?>" title="Wertmodus: Wert oder Rohwert"
                                onclick="event.stopPropagation(); nasaToggleValueMode(this.closest('.ko-cell'), 'r')"><?= $existRvm === 'raw' ? 'Raw' : 'Dek' ?></button>
                        <button class="ko-btn-del" onclick="event.stopPropagation(); nasaDeleteMap(<?= $existRid ?>, this.closest('.ko-cell'))" title="Zuweisung entfernen">&#x2715;</button>
                    <?php else: ?>
                        <span class="ko-cell-empty">— nicht zugewiesen —</span>
                    <?php endif; ?>
                </div>
            </td>

            <!-- Write-KO -->
            <td<?= $access === 'R' ? ' style="opacity:.35" title="Laut ProtocolID nur lesbar"' : '' ?>>
                <div class="ko-cell"
                     data-addr="<?= htmlspecialchars($address) ?>"
                     data-msg="<?= htmlspecialchars($msgNum) ?>"
                     data-dir="w"
                     data-mapid="<?= $existWid ?>"
                     data-koid="<?= $existWko ?>"
                     data-vmode="<?= $existWvm ?>"
                     onclick="nasaPickerOpen(this)">
                    <?php if ($existW): ?>
                        <span class="ko-ga-badge gatyp<?= (int)($existW['koGAtyp'] ?? 1) ?>"><?= htmlspecialchars($existW['koGA'] ?: 'KO'.$existWko) ?></span>
                        <span class="ko-cell-name"><?= htmlspecialchars($existW['koName'] ?: '?') ?></span>
                        <button class="ko-btn-mode<?= $existWvm === 'raw' ? ' mode-raw' : '' ?>" title="Wertmodus: Wert oder Rohwert"
                                onclick="event.stopPropagation(); nasaToggleValueMode(this.closest('.ko-cell'), 'w')"><?= $existWvm === 'raw' ? 'Raw' : 'Dek' ?></button>
                        <button class="ko-btn-del" onclick="event.stopPropagation(); nasaDeleteMap(<?= $existWid ?>, this.closest('.ko-cell'))" title="Zuweisung entfernen">&#x2715;</button>
                    <?php else: ?>
                        <span class="ko-cell-empty">— nicht zugewiesen —</span>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
</table>
</div>

<?php else: ?>
    <div class="msg msg-err">Keine Entities entdeckt. LBS starten und einige Minuten warten.</div>
<?php endif; ?>

<h2>Manuelle Zuweisung</h2>
<div class="manual-form">
    <div>
        <label>Geräteadresse</label>
        <input type="text" id="man_addr" placeholder="z.B. 20.00.01" style="width:110px">
    </div>
    <div>
        <label>Message-ID (hex)</label>
        <input type="text" id="man_msg" placeholder="z.B. 0x4201" style="width:100px">
    </div>
    <div>
        <label>KO auswählen</label>
        <div class="ko-cell" id="man_ko_cell" onclick="nasaPickerOpenManual()"
             style="width:220px;">
            <span class="ko-cell-empty" id="man_ko_empty">— KO wählen —</span>
            <span id="man_ko_display" style="display:none; flex:1;"></span>
            <input type="hidden" id="man_ko" value="0">
        </div>
    </div>
    <div>
        <label>Richtung</label>
        <select id="man_dir">
            <option value="r">Read (NASA→KO)</option>
            <option value="w">Write (KO→NASA)</option>
            <option value="rw">Read+Write</option>
        </select>
    </div>
    <button class="btn-save" onclick="saveManual()">Speichern</button>
</div>

<script src="ko_picker.js"></script>
<script>
// ---- Reload mit Filter-Erhalt ----
function reloadWithFilter() {
    try {
        var state = {
            dev: document.getElementById('f_dev').value,
            msg: document.getElementById('f_msg').value,
            name: document.getElementById('f_name').value,
            typ: document.getElementById('f_typ').value,
            val: document.getElementById('f_val').value,
            raw: document.getElementById('f_raw').value,
        };
        sessionStorage.setItem('nasaFilter', JSON.stringify(state));
    } catch(e) {}
    location.reload();
}

// ---- Tabellen-Filter ----
function filterTable() {
    var fDev  = document.getElementById('f_dev').value;
    var fMsg  = document.getElementById('f_msg').value.toLowerCase().trim();
    var fName = document.getElementById('f_name').value.toLowerCase().trim();
    var fTyp  = document.getElementById('f_typ').value.toLowerCase().trim();
    var fVal  = document.getElementById('f_val').value.toLowerCase().trim();
    var fRaw  = document.getElementById('f_raw').value.toLowerCase().trim();

    var rows = document.querySelectorAll('#discoveryTbody > tr');
    var addrRow = null, addrVisible = false, skipAddr = false;

    rows.forEach(function(row) {
        if (row.querySelector('.addr-header')) {
            if (addrRow) addrRow.style.display = addrVisible ? '' : 'none';
            addrRow = row;
            addrVisible = false;
            skipAddr = fDev && row.dataset.addr !== fDev;
            if (skipAddr) addrRow.style.display = 'none';
            return;
        }
        if (skipAddr) { row.style.display = 'none'; return; }
        var cells = row.cells;
        var show = (!fMsg  || (cells[0] && cells[0].textContent.toLowerCase().includes(fMsg)))
                && (!fName || (cells[1] && (cells[1].querySelector('.proto-id')||cells[1]).textContent.toLowerCase().includes(fName)))
                && (!fTyp  || (cells[2] && cells[2].textContent.toLowerCase().includes(fTyp)))
                && (!fVal  || (cells[4] && cells[4].textContent.toLowerCase().includes(fVal)))
                && (!fRaw  || (cells[5] && cells[5].textContent.toLowerCase().includes(fRaw)));
        row.style.display = show ? '' : 'none';
        if (show) addrVisible = true;
    });
    if (addrRow && !skipAddr) addrRow.style.display = addrVisible ? '' : 'none';
}

function clearFilter() {
    document.getElementById('f_dev').value = '';
    ['f_msg','f_name','f_typ','f_val','f_raw'].forEach(function(id) {
        document.getElementById(id).value = '';
    });
    sessionStorage.removeItem('nasaFilter');
    filterTable();
}

// ---- Filter-Zustand wiederherstellen ----
document.addEventListener('DOMContentLoaded', function() {
    var saved = sessionStorage.getItem('nasaFilter');
    if (!saved) return;
    sessionStorage.removeItem('nasaFilter');
    var s = JSON.parse(saved);
    if (s.dev)  document.getElementById('f_dev').value  = s.dev;
    if (s.msg)  document.getElementById('f_msg').value  = s.msg;
    if (s.name) document.getElementById('f_name').value = s.name;
    if (s.typ)  document.getElementById('f_typ').value  = s.typ;
    if (s.val)  document.getElementById('f_val').value  = s.val;
    if (s.raw)  document.getElementById('f_raw').value  = s.raw;
    filterTable();
});

// ---- Info-Panel ----
function toggleInfo(id) {
    var el = document.getElementById(id);
    var wasVisible = el.classList.contains('visible');
    document.querySelectorAll('.info-panel.visible').forEach(function(p) { p.classList.remove('visible'); });
    if (!wasVisible) el.classList.add('visible');
}
document.addEventListener('click', function(e) {
    if (!e.target.classList.contains('info-btn') && !e.target.closest('.info-panel')) {
        document.querySelectorAll('.info-panel.visible').forEach(function(p) { p.classList.remove('visible'); });
    }
});

// ---- Status-Meldung ----
function showMsg(txt, ok) {
    var el = document.getElementById('msg');
    el.className = 'msg ' + (ok ? 'msg-ok' : 'msg-err');
    el.innerHTML = txt;
    setTimeout(function(){ el.innerHTML = ''; el.className = ''; }, 3000);
}

// ---- Discovery löschen ----
function clearDiscovery() {
    if (!confirm('Discovery-Daten löschen?\nDer Daemon schreibt sie beim nächsten Durchlauf neu.')) return;
    fetch('?', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=clearDiscovery' })
        .then(function(r){ return r.json(); })
        .then(function(d){ if (d.ok) { showMsg('Discovery zurückgesetzt.', true); setTimeout(function(){ reloadWithFilter(); }, 1500); } });
}

// ---- Wertmodus umschalten (Dek/Raw) ----
function nasaToggleValueMode(cell, field) {
    var mapId   = parseInt(cell.dataset.mapid);
    var current = cell.dataset.vmode || 'decoded';
    var newMode = current === 'decoded' ? 'raw' : 'decoded';
    if (!mapId) return;
    fetch('?', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=setValueMode&id=' + mapId + '&field=' + field + '&valueMode=' + newMode
    }).then(function(r) { return r.json(); })
    .then(function(d) { if (d.ok) reloadWithFilter(); });
}

// ---- KO-Zuweisung löschen (X-Button) ----
function nasaDeleteMap(mapId, cell) {
    if (!mapId || !confirm('Zuweisung löschen?')) return;
    fetch('?', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=delete&id=' + mapId })
        .then(function(r){ return r.json(); })
        .then(function(d){ if (d.ok) reloadWithFilter(); });
}

// ---- KO-Picker öffnen (Tabellen-Zelle) ----
function nasaPickerOpen(cell) {
    var addr  = cell.dataset.addr;
    var msg   = cell.dataset.msg;
    var dir   = cell.dataset.dir;
    var mapId = parseInt(cell.dataset.mapid) || 0;
    var koId  = parseInt(cell.dataset.koid)  || 0;

    koPickerOpen({
        currentKoId: koId || null,
        onConfirm: function(id, name, ga, gatyp) {
            fetch('?', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=save'
                    + '&address='       + encodeURIComponent(addr)
                    + '&messageNumber=' + encodeURIComponent(msg)
                    + '&koID='          + id
                    + '&direction='     + dir
            }).then(function(r){ return r.json(); })
            .then(function(d){
                if (d.ok) reloadWithFilter();
                else showMsg('Fehler: ' + d.err, false);
            });
        },
        onReset: mapId ? function() {
            fetch('?', { method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:'action=delete&id=' + mapId })
                .then(function(r){ return r.json(); })
                .then(function(d){ if (d.ok) reloadWithFilter(); });
        } : null,
    });
}

// ---- KO-Picker öffnen (manuelles Formular) ----
function nasaPickerOpenManual() {
    koPickerOpen({
        currentKoId: parseInt(document.getElementById('man_ko').value) || null,
        onConfirm: function(id, name, ga, gatyp) {
            document.getElementById('man_ko').value = id;
            var disp  = document.getElementById('man_ko_display');
            var empty = document.getElementById('man_ko_empty');
            disp.textContent = (ga ? '[' + ga + '] ' : '') + (name || 'KO ' + id);
            disp.style.display = '';
            empty.style.display = 'none';
        },
    });
}

// ---- Manuell speichern ----
function saveManual() {
    var addr = document.getElementById('man_addr').value.trim();
    var msg  = document.getElementById('man_msg').value.trim();
    var ko   = document.getElementById('man_ko').value.trim();
    var dir  = document.getElementById('man_dir').value;
    if (!addr || !msg || !ko || ko === '0') { showMsg('Alle Felder ausfüllen.', false); return; }
    fetch('?', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=save&address=' + encodeURIComponent(addr)
            + '&messageNumber=' + encodeURIComponent(msg)
            + '&koID=' + ko + '&direction=' + dir
    }).then(function(r){ return r.json(); })
    .then(function(d){
        if (d.ok) { showMsg('Manueller Eintrag gespeichert.', true); setTimeout(function(){ reloadWithFilter(); }, 1200); }
        else showMsg('Fehler: ' + d.err, false);
    });
}
</script>
</div><!-- /page-wrap -->
</body>
</html>
