<?php
/**
 * קובץ שלוחה 8580 — URL שמוגדר במרכזייה טכנוליין
 *
 * לוגיקה:
 * 1. בודק אם PBXphone (המחייג) נמצא ברשימת הנהגים
 *    → כן: מחפש את הנוסע שלו ב-call_mapping → מחייג לנוסע
 * 2. אם לא נהג, בודק PBXdid (המספר שאליו חייגו) מול מספרים וירטואליים
 *    → כן: מוצא את הנהג → מחייג לנהג
 * 3. אין התאמה → "אין התאמה בשיחה"
 */

header('Content-Type: application/json; charset=utf-8');

$phone      = $_GET['PBXphone'] ?? '';
$did        = $_GET['PBXdid'] ?? '';
$callStatus = $_GET['PBXcallStatus'] ?? '';
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

// callback — אם מודול קודם כבר בוצע, סיום
if (isset($_GET['dialPassenger']) || isset($_GET['dialDriver']) ||
    isset($_GET['noMapping']) || isset($_GET['noDriver'])) {
    echo json_encode(["type" => "goTo", "goTo" => ""]);
    exit;
}

// דיבאג
$debugFile = __DIR__ . '/debug_log.json';
$debugLog = loadJ($debugFile);
$debugLog[] = [
    "time" => date('Y-m-d H:i:s'),
    "PBXphone" => $phone,
    "PBXdid" => $did,
    "PBXcallStatus" => $callStatus,
    "all_params" => $_GET
];
if (count($debugLog) > 100) $debugLog = array_slice($debugLog, -100);
file_put_contents($debugFile, json_encode($debugLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$drivers = loadJ($driversFile);
$normalPhone = normalizePhone($phone);

// ========== בדיקה 1: האם המחייג הוא נהג? ==========
$isDriver = false;
$driverData = null;
foreach ($drivers as $d) {
    if (normalizePhone($d['phone']) === $normalPhone) {
        $isDriver = true;
        $driverData = $d;
        break;
    }
}

if ($isDriver) {
    // המחייג הוא נהג — מחפש את הנוסע שלו ב-call_mapping
    $mappings = loadJ($mappingFile);
    $passengerPhone = '';
    $virtualNumber = $driverData['virtual'] ?? '';

    foreach ($mappings as $key => $val) {
        if (normalizePhone($key) === $normalPhone) {
            $passengerPhone = $val['passengerPhone'];
            if (empty($virtualNumber)) $virtualNumber = $val['virtualNumber'] ?? '';
            break;
        }
    }

    // דיבאג
    $debugLog[] = [
        "time" => date('Y-m-d H:i:s'),
        "action" => "driver_found",
        "driver" => $driverData['name'],
        "driverPhone" => $phone,
        "foundPassenger" => $passengerPhone,
        "virtualNumber" => $virtualNumber
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
            "files" => [["text" => "אין התאמה בשיחה"]],
            "extensionChange" => ""
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // מחייג לנוסע — הנוסע רואה מספר וירטואלי
    echo json_encode([
        "type"          => "simpleRouting",
        "name"          => "dialPassenger",
        "dialPhone"     => $passengerPhone,
        "displayNumber" => !empty($virtualNumber) ? $virtualNumber : "",
        "routingMusic"  => "yes",
        "ringSec"       => 30,
        "limit"         => ""
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== בדיקה 2: האם PBXdid הוא מספר וירטואלי של נהג? ==========
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
    "action" => "virtual_lookup",
    "PBXdid" => $did,
    "normalDid" => $normalDid,
    "foundDriver" => $targetPhone,
    "foundName" => $driverName,
    "driverVirtuals" => array_map(function($d) { return ($d['virtual'] ?? '') . '=' . ($d['phone'] ?? ''); }, $drivers)
];
if (count($debugLog) > 100) $debugLog = array_slice($debugLog, -100);
file_put_contents($debugFile, json_encode($debugLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

if (!empty($targetPhone)) {
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

    // מחייג לנהג — הנהג רואה מספר וירטואלי
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

// ========== אין התאמה ==========
echo json_encode([
    "type" => "simpleMenu",
    "name" => "noDriver",
    "times" => 1,
    "timeout" => 3,
    "enabledKeys" => "",
    "files" => [["text" => "אין התאמה בשיחה"]],
    "extensionChange" => ""
], JSON_UNESCAPED_UNICODE);
