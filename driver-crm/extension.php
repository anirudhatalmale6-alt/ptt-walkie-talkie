<?php
/**
 * קובץ שלוחה 8580 — URL שמוגדר במרכזייה טכנוליין
 *
 * מצב 1 — שיחה יוצאת (CRM מחייג לנהג, PBXcallType=out):
 *   PBXphone = טלפון הנהג
 *   קורא call_mapping.json → מוצא נוסע → מחייג לנוסע
 *   הנוסע רואה: מספר וירטואלי
 *
 * מצב 2 — שיחה נכנסת (נוסע מחייג למספר וירטואלי, PBXcallType=in):
 *   PBXphone = טלפון הנוסע
 *   PBXdid = מספר וירטואלי
 *   קורא drivers.json → מוצא נהג לפי מספר וירטואלי → מחייג לנהג
 *   הנהג רואה: מספר וירטואלי (PBXdid)
 *
 * מספר מערכת: 0765674892
 * מפתח: 7c2cf8346c7633
 * ID שלוחה: 8580
 */

header('Content-Type: application/json; charset=utf-8');

$phone      = $_GET['PBXphone'] ?? '';
$did        = $_GET['PBXdid'] ?? '';
$callStatus = $_GET['PBXcallStatus'] ?? '';
$callType   = $_GET['PBXcallType'] ?? '';

// שיחה נותקה
if ($callStatus === 'HANGUP') {
    echo json_encode(["type" => "goTo", "goTo" => ""]);
    exit;
}

$driversFile = __DIR__ . '/drivers.json';
$mappingFile = __DIR__ . '/call_mapping.json';
$logFile     = __DIR__ . '/call_log.json';

function normalizePhone($p) {
    return preg_replace('/^(\+?972|0)/', '', $p);
}

function loadJ($file) {
    if (file_exists($file)) return json_decode(file_get_contents($file), true) ?: [];
    return [];
}

// ========== מצב 2: שיחה נכנסת (נוסע מחייג למספר וירטואלי) ==========
if ($callType === 'in') {
    $drivers = loadJ($driversFile);
    $normalDid = normalizePhone($did);
    $targetPhone = '';
    $driverName = '';

    // מוצא נהג לפי מספר וירטואלי (PBXdid)
    foreach ($drivers as $d) {
        $virtualNum = $d['virtual'] ?? '';
        if (!empty($virtualNum) && normalizePhone($virtualNum) === $normalDid) {
            $targetPhone = $d['phone'];
            $driverName = $d['name'];
            break;
        }
    }

    if (empty($targetPhone)) {
        // לא נמצא נהג — fallback
        echo json_encode([
            "type" => "simpleMenu", "name" => "noDriver", "times" => 1,
            "timeout" => 5, "enabledKeys" => "",
            "files" => [["text" => "מספר לא מוקצה לנהג"]],
            "extensionChange" => ".."
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // לוג שיחה נכנסת
    $log = loadJ($logFile);
    $log[] = [
        "id" => uniqid('call_'), "time" => date('Y-m-d H:i:s'),
        "driverName" => $driverName, "driverPhone" => $targetPhone,
        "passengerPhone" => $phone, "virtualNumber" => $did,
        "type" => "incoming", "duration" => "", "recording" => "",
        "status" => "connected"
    ];
    if (count($log) > 1000) $log = array_slice($log, -1000);
    file_put_contents($logFile, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // מחייג לנהג — הנהג רואה את המספר הוירטואלי
    echo json_encode([
        "type"          => "simpleRouting",
        "name"          => "dialDriver",
        "dialPhone"     => $targetPhone,
        "displayNumber" => $did,
        "routingMusic"  => "yes",
        "ringSec"       => 30,
        "limit"         => ""
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== מצב 1: שיחה יוצאת (CRM מחייג לנהג → מחבר לנוסע) ==========
$passengerPhone = '';
$virtualNumber  = '';

$mappings = loadJ($mappingFile);

// מחפש את הנוסע לפי מספר הנהג (PBXphone)
if (isset($mappings[$phone])) {
    $passengerPhone = $mappings[$phone]['passengerPhone'];
    $virtualNumber  = $mappings[$phone]['virtualNumber'] ?? '';
} else {
    foreach ($mappings as $key => $val) {
        if (normalizePhone($key) === normalizePhone($phone)) {
            $passengerPhone = $val['passengerPhone'];
            $virtualNumber  = $val['virtualNumber'] ?? '';
            break;
        }
    }
}

if (empty($passengerPhone)) {
    $passengerPhone = '0533124489'; // fallback
}

// הנוסע רואה את המספר הוירטואלי
$displayNum = !empty($virtualNumber) ? $virtualNumber : $passengerPhone;

echo json_encode([
    "type"          => "simpleRouting",
    "name"          => "dialPassenger",
    "dialPhone"     => $passengerPhone,
    "displayNumber" => $displayNum,
    "routingMusic"  => "yes",
    "ringSec"       => 30,
    "limit"         => ""
], JSON_UNESCAPED_UNICODE);
