<?php
/*
  v 1.0 - (w) 2025,2026 by Nima Ghassemi Nejad

  Generate final edomi LBS for Samsung NASA Protocol Connector

  Run:
    php compile.php
*/

$lbsName = "19002625_lbs.php";

// Source paths relative to this script (edomi/)
$srcBase    = "../";
$disclaimer = file_get_contents("../disclaimer");

// PHP files: strip whitespace, compress, base64-encode
$compressMe = [
    $srcBase . "waveshare/class/classWaveshare.php" => "classWaveshare.txt",
    $srcBase . "classNasaProtocol.php" => "classNasaProtocol.txt",
    $srcBase . "classNasaDecode.php"   => "classNasaDecode.txt",
];

$encoded = [];

foreach ($compressMe as $src => $placeholder) {
    if (!file_exists($src)) {
        die("Fehler: Quelldatei nicht gefunden: $src\n");
    }
    $data = php_strip_whitespace($src);
    $encoded[$placeholder] = base64_encode(gzcompress($data, 9, ZLIB_ENCODING_DEFLATE));
    echo "OK: $src -> $placeholder (" . strlen($encoded[$placeholder]) . " bytes)\n";
}

// NASA.ptc: XML-Datei, kein PHP -> direkt komprimieren ohne strip_whitespace
$nasaPtcPath = $srcBase . "protocoldescription/NASA.ptc";

if (!file_exists($nasaPtcPath)) {
    die("Fehler: NASA.ptc nicht gefunden: $nasaPtcPath\n");
}
$nasaData = file_get_contents($nasaPtcPath);
$encoded["NASA.ptc.txt"] = base64_encode(gzcompress($nasaData, 9, ZLIB_ENCODING_DEFLATE));
echo "OK: $nasaPtcPath -> NASA.ptc.txt (" . strlen($encoded["NASA.ptc.txt"]) . " bytes)\n";

// Admin-Seite
$adminSrc = "../nasa_admin.php";
if (file_exists($adminSrc)) {
    $data = php_strip_whitespace($adminSrc);
    $encoded["nasa_admin.txt"] = base64_encode(gzcompress($data, 9, ZLIB_ENCODING_DEFLATE));
    echo "OK: $adminSrc -> nasa_admin.txt (" . strlen($encoded["nasa_admin.txt"]) . " bytes)\n";
} else {
    $encoded["nasa_admin.txt"] = base64_encode(gzcompress("<?php /* admin not yet available */ ?>", 9, ZLIB_ENCODING_DEFLATE));
    echo "HINWEIS: nasa_admin.php nicht gefunden, Platzhalter eingebettet.\n";
}

// KO-Picker: PHP mit strip_whitespace, CSS/JS als Rohdaten
$koPickerPhp = "../ko_picker.php";
if (file_exists($koPickerPhp)) {
    $data = php_strip_whitespace($koPickerPhp);
    $encoded["ko_picker.php.txt"] = base64_encode(gzcompress($data, 9, ZLIB_ENCODING_DEFLATE));
    echo "OK: $koPickerPhp -> ko_picker.php.txt (" . strlen($encoded["ko_picker.php.txt"]) . " bytes)\n";
} else {
    die("Fehler: ko_picker.php nicht gefunden\n");
}

foreach (["ko_picker.css" => "ko_picker.css.txt", "ko_picker.js" => "ko_picker.js.txt"] as $file => $key) {
    $src = "../" . $file;
    if (file_exists($src)) {
        $data = file_get_contents($src);
        $encoded[$key] = base64_encode(gzcompress($data, 9, ZLIB_ENCODING_DEFLATE));
        echo "OK: $src -> $key (" . strlen($encoded[$key]) . " bytes)\n";
    } else {
        die("Fehler: $file nicht gefunden\n");
    }
}

// Template einlesen und Platzhalter ersetzen
$templateFile = "../template_" . $lbsName;
if (!file_exists($templateFile)) {
    die("Fehler: Template nicht gefunden: $templateFile\n");
}
$lbs = file_get_contents($templateFile);

foreach ($encoded as $key => $val) {
    $lbs = str_replace('__' . $key . '__', $val, $lbs);
}

// Disclaimer einfügen
$lbs = str_replace('__INSERT_DISCLAIMER__', $disclaimer, $lbs);

// Prüfen ob noch ungelöste Platzhalter vorhanden sind
// Ignorieren: PHP-Magickonstanten und bekannte Runtime-Platzhalter (werden erst beim LBS-Install ersetzt)
$ignore = ['__FILE__', '__DIR__', '__LINE__', '__CLASS__', '__FUNCTION__', '__METHOD__', '__NAMESPACE__', '__TRAIT__',
           '__INSERT_EDOMI_PATH__', '__INSERT_LBS_ID__', '__INSERT_DISCLAIMER__'];
preg_match_all('/__[A-Za-z0-9_.]+__/', $lbs, $matches);
$unresolved = array_diff(array_unique($matches[0]), $ignore);
if (!empty($unresolved)) {
    echo "WARNUNG: Ungelöste Platzhalter im Template: " . implode(', ', $unresolved) . "\n";
}

file_put_contents($lbsName, $lbs);
echo "\nFertig: $lbsName (" . filesize($lbsName) . " bytes)\n";
?>
