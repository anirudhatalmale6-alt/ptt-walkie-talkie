<?php
/**
 * קובץ שלוחה 8580 — URL שמוגדר במרכזייה טכנוליין
 *
 * מצב 1 — שיחה יוצאת (PBXcallType=out):
 *   PBXphone = טלפון הנהג (שענה לקמפיין)
 *   קורא call_mapping.json → מוצא נוסע → מחייג לנוסע
 *   הנוסע רואה: מספר וירטואלי
 *
 * מצב 2 — שיחה נכנסת (PBXcallType="" ריק):
 *   PBXphone = טלפון הנוסע/מחייג
 *   PBXdid = מספר וירטואלי שאליו חייגו
 *   קורא drivers.json → מוצא נהג לפי מספר וירטואלי → מחייג לנהג
 *   הנהג רואה: מספר וירטואלי
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

function normalizePhone($p) {
    $p = trim($p);
    $p = preg_replace('/[^0-9]/', '', $p);
    $p = preg_replace('/^972/', '', $p);
    $p = preg_replace('/^0/', '', $p);
    return $p;
}

function loadJ($file) {
    if (file_exists($file)) return json_decode(file_get_contents($file), true) ?: [];
    return [];
}

// ========== דיבאג ==========
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

// ========== callback — אם מודול קודם כבר בוצע, סיום השיחה ==========
// אחרי simpleRouting/simpleMenu, המרכזייה חוזרת עם תוצאת המודול כפרמטר
if (isset($_GET['dialPassenger']) || isset($_GET['dialDriver']) ||
    isset($_GET['noMapping']) || isset($_GET['noDriver'])) {
    echo json_encode(["type" => "goTo", "goTo" => ""]);
    exit;
}

// ========== מצב 1: שיחה יוצאת (PBXcallType=out — קמפיין, נהג ענה, מחבר לנוסע) ==========
if ($callType === 'out') {
    $passengerPhone = '';
    $virtualNumber  = '';
    $driverName     = '';

    $mappings = loadJ($mappingFile);
    $normalPhone = normalizePhone($phone);

    foreach ($mappings as $key => $val) {
        if (normalizePhone($key) === $normalPhone) {
            $passengerPhone = $val['passengerPhone'];
            $virtualNumber  = $val['virtualNumber'] ?? '';
            $driverName     = $val['driverName'] ?? '';
            break;
        }
    }

    // דיבאג
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

    // מחייג לנוסע — הנוסע רואה מספר וירטואלי
    $response = [
        "type"          => "simpleRouting",
        "name"          => "dialPassenger",
        "dialPhone"     => $passengerPhone,
        "displayNumber" => !empty($virtualNumber) ? $virtualNumber : "",
        "routingMusic"  => "yes",
        "ringSec"       => 30,
        "limit"         => ""
    ];

    // דיבאג — שמירת התגובה
    $debugLog[] = [
        "time" => date('Y-m-d H:i:s'),
        "action" => "outgoing_response",
        "response" => $response
    ];
    if (count($debugLog) > 100) $debugLog = array_slice($debugLog, -100);
    file_put_contents($debugFile, json_encode($debugLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== מצב 2: שיחה נכנסת (PBXcallType="" ריק — מישהו חייג למספר וירטואלי) ==========
$drivers = loadJ($driversFile);
$normalDid = normalizePhone($did);
$targetPhone = '';
$driverName = '';
$driverVirtual = $did;

foreach ($drivers as $d) {
    $virtualNum = $d['virtual'] ?? '';
    if (!empty($virtualNum) && normalizePhone($virtualNum) === $normalDid) {
        $targetPhone = $d['phone'];
        $driverName = $d['name'];
        $driverVirtual = $virtualNum;
        break;
    }
}

// דיבאג
$debugLog[] = [
    "time" => date('Y-m-d H:i:s'),
    "action" => "incoming_lookup",
    "PBXphone" => $phone,
    "PBXdid" => $did,
    "normalDid" => $normalDid,
    "foundDriver" => $targetPhone,
    "foundName" => $driverName,
    "driverVirtuals" => array_map(function($d) { return $d['virtual'] ?? ''; }, $drivers)
];
if (count($debugLog) > 100) $debugLog = array_slice($debugLog, -100);
file_put_contents($debugFile, json_encode($debugLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

if (empty($targetPhone)) {
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
$response = [
    "type"          => "simpleRouting",
    "name"          => "dialDriver",
    "dialPhone"     => $targetPhone,
    "displayNumber" => $driverVirtual,
    "routingMusic"  => "yes",
    "ringSec"       => 30,
    "limit"         => ""
];

// דיבאג
$debugLog[] = [
    "time" => date('Y-m-d H:i:s'),
    "action" => "incoming_response",
    "response" => $response
];
if (count($debugLog) > 100) $debugLog = array_slice($debugLog, -100);
file_put_contents($debugFile, json_encode($debugLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo json_encode($response, JSON_UNESCAPED_UNICODE);
