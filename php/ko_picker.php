<?php
/*
  KO-Picker – Wiederverwendbarer AJAX-Handler
  Einbinden am Anfang einer Admin-Datei, nach $mysqli = mysqli_connect(...)

  Behandelt:
    ?action=koTree&folderId=X  – Ordnerinhalte laden
    ?action=koSearch&q=X       – Volltext-Suche mit Pfadangabe

  Ruft mysqli_close() und die() wenn eine Action gefunden wurde.
*/

if (!isset($_GET['action']) || !in_array($_GET['action'], ['koTree', 'koSearch', 'koGetFolder'])) {
    return; // Nichts zu tun
}

header('Content-Type: application/json');

if ($_GET['action'] === 'koTree') {

    $folderId = max(0, (int)($_GET['folderId'] ?? 30));

    $folders = [];
    $res = $mysqli->query("SELECT id, name FROM edomiProject.editRoot
        WHERE parentid=$folderId AND namedb='editKo'
        ORDER BY sortid, name");
    if ($res) while ($r = $res->fetch_assoc())
        $folders[] = ['id' => (int)$r['id'], 'name' => $r['name']];

    $items = [];
    $res = $mysqli->query("SELECT id, name, ga, gatyp FROM edomiProject.editKo
        WHERE folderid=$folderId ORDER BY name");
    if ($res) while ($r = $res->fetch_assoc())
        $items[] = [
            'id'    => (int)$r['id'],
            'name'  => $r['name'],
            'ga'    => (string)($r['ga'] ?? ''),
            'gatyp' => (int)$r['gatyp'],
        ];

    $folderInfo = null;
    $res = $mysqli->query("SELECT id, name, parentid FROM edomiProject.editRoot WHERE id=$folderId");
    if ($res && $r = $res->fetch_assoc())
        $folderInfo = ['id' => (int)$r['id'], 'name' => $r['name'], 'parentid' => (int)$r['parentid']];

    echo json_encode(['folders' => $folders, 'items' => $items, 'folder' => $folderInfo]);

} elseif ($_GET['action'] === 'koSearch') {

    $q = '%' . $mysqli->real_escape_string(trim($_GET['q'] ?? '')) . '%';

    // Alle KO-Ordner einmalig laden für Pfad-Berechnung
    $allFolders = [];
    $res = $mysqli->query("SELECT id, name, parentid FROM edomiProject.editRoot WHERE namedb='editKo'");
    if ($res) while ($r = $res->fetch_assoc())
        $allFolders[(int)$r['id']] = ['name' => $r['name'], 'parentid' => (int)$r['parentid']];

    $items = [];
    $res = $mysqli->query("SELECT id, name, ga, gatyp, folderid FROM edomiProject.editKo
        WHERE name LIKE '$q' OR ga LIKE '$q'
        ORDER BY name LIMIT 80");
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $items[] = [
                'id'    => (int)$r['id'],
                'name'  => $r['name'],
                'ga'    => (string)($r['ga'] ?? ''),
                'gatyp' => (int)$r['gatyp'],
                'path'  => _koPicker_buildPath((int)$r['folderid'], $allFolders),
            ];
        }
    }

    echo json_encode(['items' => $items]);

} elseif ($_GET['action'] === 'koGetFolder') {

    $koId   = max(0, (int)($_GET['koId'] ?? 0));
    $rootId = 30;

    $folderId = null;
    if ($koId > 0) {
        $res = $mysqli->query("SELECT folderid FROM edomiProject.editKo WHERE id=$koId");
        if ($res && $r = $res->fetch_assoc()) $folderId = (int)$r['folderid'];
    }

    if ($folderId === null) {
        echo json_encode(['folderId' => $rootId, 'ancestors' => []]);
    } else {
        $allFolders = [];
        $res = $mysqli->query("SELECT id, name, parentid FROM edomiProject.editRoot WHERE namedb='editKo'");
        if ($res) while ($r = $res->fetch_assoc())
            $allFolders[(int)$r['id']] = ['name' => $r['name'], 'parentid' => (int)$r['parentid']];

        // Von folderId nach oben bis root laufen, dann umkehren
        $path    = [];
        $current = $folderId;
        $visited = [];
        while ($current && $current != $rootId && !in_array($current, $visited)) {
            $visited[] = $current;
            if (!isset($allFolders[$current])) break;
            $path[] = ['id' => $current, 'name' => $allFolders[$current]['name']];
            $current = $allFolders[$current]['parentid'];
        }
        if (isset($allFolders[$rootId]))
            $path[] = ['id' => $rootId, 'name' => $allFolders[$rootId]['name']];

        $path = array_reverse($path); // root zuerst
        array_pop($path);             // folderId selbst raus – ist current, nicht ancestor

        echo json_encode(['folderId' => $folderId, 'ancestors' => $path]);
    }
}

$mysqli->close();
die();

// ---------------------------------------------------------------------------

function _koPicker_buildPath($folderid, $allFolders, $rootId = 30) {
    $parts   = [];
    $current = $folderid;
    $visited = [];
    while ($current && $current != $rootId && !in_array($current, $visited)) {
        $visited[] = $current;
        if (isset($allFolders[$current])) {
            array_unshift($parts, $allFolders[$current]['name']);
            $current = $allFolders[$current]['parentid'];
        } else {
            break;
        }
    }
    return implode(' › ', $parts);
}
