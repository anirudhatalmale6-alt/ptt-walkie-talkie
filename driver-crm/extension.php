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
$callId     = $_GET['PBXcallId'] ?? '';

// שיחה נותקה
if ($callStatus === 'HANGUP') {
    echo json_encode(["type" => "goTo", "goTo" => ""]);
    exit;
}

$driversFile = __DIR__ . '/drivers.json';
$mappingFile = __DIR__ . '/call_mapping.json';
$logFile     = __DIR__ . '/call_log.json';

// נרמול טלפון — מוריד 0, +972, 972 מההתחלה
function normalizePhone($p) {
    $p = trim($p);
    $p = preg_replace('/[^0-9]/', '', $p); // רק ספרות
    $p = preg_replace('/^972/', '', $p);    // הסר 972
    $p = preg_replace('/^0/', '', $p);      // הסר 0
    return $p;
}

function loadJ($file) {
    if (file_exists($file)) return json_decode(file_get_contents($file), true) ?: [];
    return [];
}

// ========== דיבאג — שמירת כל הפרמטרים לקובץ לוג ==========
$debugFile = __DIR__ . '/debug_log.json';
$debugLog = loadJ($debugFile);
$debugLog[] = [
    "time" => date('Y-m-d H:i:s'),
    "PBXphone" => $phone,
    "PBXdid" => $did,
    "PBXcallType" => $callType,
    "PBXcallStatus" => $callStatus,
    "PBXcallId" => $callId,
    "all_params" => $_GET
];
if (count($debugLog) > 100) $debugLog = array_slice($debugLog, -100);
file_put_contents($debugFile, json_encode($debugLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// ========== מצב 2: שיחה נכנסת (נוסע מחייג למספר וירטואלי) ==========
if ($callType === 'in') {
    $drivers = loadJ($driversFile);
    $normalDid = normalizePhone($did);
    $targetPhone = '';
    $driverName = '';
    $driverVirtual = $did; // ברירת מחדל — PBXdid

    // מוצא נהג לפי מספר וירטואלי (PBXdid)
    foreach ($drivers as $d) {
        $virtualNum = $d['virtual'] ?? '';
        if (!empty($virtualNum) && normalizePhone($virtualNum) === $normalDid) {
            $targetPhone = $d['phone'];
            $driverName = $d['name'];
            $driverVirtual = $virtualNum; // מספר וירטואלי מ-drivers.json
            break;
        }
    }

    if (empty($targetPhone)) {
        // לא נמצא נהג — השמעת הודעה וניתוק
        echo json_encode([
            "type" => "simpleMenu",
            "name" => "noDriver",
            "times" => 1,
            "timeout" => 3,
            "enabledKeys" => "",
            "files" => [["text" => "אין אפשרות להתחבר לנהג"]],
            "extensionChange" => ""
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // לוג שיחה נכנסת
    $log = loadJ($logFile);
    $log[] = [
        "id" => uniqid('call_'), "time" => date('Y-m-d H:i:s'),
        "driverName" => $driverName, "driverPhone" => $targetPhone,
        "passengerPhone" => $phone, "virtualNumber" => $driverVirtual,
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
        "displayNumber" => $driverVirtual,
        "routingMusic"  => "yes",
        "ringSec"       => 30,
        "limit"         => ""
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== מצב 1: שיחה יוצאת (CRM מחייג לנהג → מחבר לנוסע) ==========
$passengerPhone = '';
$virtualNumber  = '';
$driverName     = '';

$mappings = loadJ($mappingFile);
$normalPhone = normalizePhone($phone);

// מחפש את הנוסע לפי מספר הנהג (PBXphone) — בדיקה עם נרמול
foreach ($mappings as $key => $val) {
    if (normalizePhone($key) === $normalPhone) {
        $passengerPhone = $val['passengerPhone'];
        $virtualNumber  = $val['virtualNumber'] ?? '';
        $driverName     = $val['driverName'] ?? '';
        break;
    }
}

// דיבאג — שמירת תוצאת חיפוש
$debugLog[] = [
    "time" => date('Y-m-d H:i:s'),
    "action" => "outgoing_lookup",
    "PBXphone" => $phone,
    "normalPhone" => $normalPhone,
    "foundPassenger" => $passengerPhone,
    "foundVirtual" => $virtualNumber,
    "mappingKeys" => array_keys($mappings)
];
if (count($debugLog) > 100) $debugLog = array_slice($debugLog, -100);
file_put_contents($debugFile, json_encode($debugLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

if (empty($passengerPhone)) {
    // לא נמצא מיפוי — השמעת הודעה וניתוק
    echo json_encode([
        "type" => "simpleMenu",
        "name" => "noMapping",
        "times" => 1,
        "timeout" => 3,
        "enabledKeys" => "",
        "files" => [["text" => "אין אפשרות להתחבר לנהג"]],
        "extensionChange" => ""
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// הנוסע רואה את המספר הוירטואלי
$displayNum = !empty($virtualNumber) ? $virtualNumber : '';

echo json_encode([
    "type"          => "simpleRouting",
    "name"          => "dialPassenger",
    "dialPhone"     => $passengerPhone,
    "displayNumber" => $displayNum,
    "routingMusic"  => "yes",
    "ringSec"       => 30,
    "limit"         => ""
], JSON_UNESCAPED_UNICODE);
