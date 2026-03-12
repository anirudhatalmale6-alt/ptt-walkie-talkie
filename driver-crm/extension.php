<?php
/**
 * קובץ שלוחה 8580 — URL שמוגדר במרכזייה טכנוליין
 *
 * לוגיקה פשוטה — קובץ last_calls.json שומר שיחה אחרונה לכל נהג:
 * { "driverPhone": { "passengerPhone": "...", "virtualNumber": "...", "driverName": "..." }, ... }
 *
 * שיחה נכנסת:
 * 1. PBXphone = נהג? → מחייג לנוסע האחרון שלו
 * 2. PBXphone = נוסע? → מחייג לנהג שלו
 * 3. אין התאמה → "אין התאמה בשיחה" + ניתוק
 *
 * displayNumber תמיד = מספר וירטואלי של הנהג
 */

header('Content-Type: application/json; charset=utf-8');

$phone      = $_GET['PBXphone'] ?? '';
$callStatus = $_GET['PBXcallStatus'] ?? '';

// שיחה נותקה
if ($callStatus === 'HANGUP') {
    echo json_encode(["type" => "goTo", "goTo" => ""]);
    exit;
}

// callback — אם מודול קודם כבר בוצע, סיום וניתוק
if (isset($_GET['dialPassenger']) || isset($_GET['dialDriver']) ||
    isset($_GET['noMatch']) || isset($_GET['msg'])) {
    echo json_encode(["type" => "goTo", "goTo" => ""]);
    exit;
}

$lastCallsFile = __DIR__ . '/last_calls.json';
$logFile       = __DIR__ . '/call_log.json';

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

// דיבאג
$debugFile = __DIR__ . '/debug_log.json';
$debugLog = loadJ($debugFile);
$debugLog[] = [
    "time" => date('Y-m-d H:i:s'),
    "PBXphone" => $phone,
    "PBXcallStatus" => $callStatus,
    "all_params" => $_GET
];
if (count($debugLog) > 100) $debugLog = array_slice($debugLog, -100);
file_put_contents($debugFile, json_encode($debugLog, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

$normalPhone = normalizePhone($phone);
$lastCalls = loadJ($lastCallsFile);

// ========== בדיקה 1: האם PBXphone = נהג? ==========
$foundAsDriver = null;
$foundDriverKey = '';
foreach ($lastCalls as $drvPhone => $data) {
    if (normalizePhone($drvPhone) === $normalPhone) {
        $foundAsDriver = $data;
        $foundDriverKey = $drvPhone;
        break;
    }
}

if ($foundAsDriver && !empty($foundAsDriver['passengerPhone'])) {
    $passengerPhone = $foundAsDriver['passengerPhone'];
    $virtualNumber  = $foundAsDriver['virtualNumber'] ?? '';

    // לוג
    $log = loadJ($logFile);
    $log[] = [
        "id" => uniqid('call_'), "time" => date('Y-m-d H:i:s'),
        "driverName" => $foundAsDriver['driverName'] ?? '',
        "driverPhone" => $foundDriverKey,
        "passengerPhone" => $passengerPhone,
        "virtualNumber" => $virtualNumber,
        "type" => "outgoing", "duration" => "", "recording" => "",
        "status" => "connected"
    ];
    if (count($log) > 1000) $log = array_slice($log, -1000);
    file_put_contents($logFile, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // מחייג לנוסע — displayNumber = וירטואלי של הנהג
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

// ========== בדיקה 2: האם PBXphone = נוסע? ==========
$foundAsPassenger = null;
$foundPassengerDriverKey = '';
foreach ($lastCalls as $drvPhone => $data) {
    if (!empty($data['passengerPhone']) && normalizePhone($data['passengerPhone']) === $normalPhone) {
        $foundAsPassenger = $data;
        $foundPassengerDriverKey = $drvPhone;
        break;
    }
}

if ($foundAsPassenger) {
    $driverPhone   = $foundPassengerDriverKey;
    $virtualNumber = $foundAsPassenger['virtualNumber'] ?? '';

    // לוג
    $log = loadJ($logFile);
    $log[] = [
        "id" => uniqid('call_'), "time" => date('Y-m-d H:i:s'),
        "driverName" => $foundAsPassenger['driverName'] ?? '',
        "driverPhone" => $driverPhone,
        "passengerPhone" => $phone,
        "virtualNumber" => $virtualNumber,
        "type" => "incoming", "duration" => "", "recording" => "",
        "status" => "connected"
    ];
    if (count($log) > 1000) $log = array_slice($log, -1000);
    file_put_contents($logFile, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // מחייג לנהג — displayNumber = וירטואלי של הנהג
    echo json_encode([
        "type"          => "simpleRouting",
        "name"          => "dialDriver",
        "dialPhone"     => $driverPhone,
        "displayNumber" => !empty($virtualNumber) ? $virtualNumber : "",
        "routingMusic"  => "yes",
        "ringSec"       => 30,
        "limit"         => ""
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== אין התאמה ==========
echo json_encode([
    "type" => "simpleMenu",
    "name" => "noMatch",
    "times" => 1,
    "timeout" => 3,
    "enabledKeys" => "",
    "errorReturn" => "NOMATCH",
    "files" => [["text" => "אין התאמה בשיחה"]],
    "extensionChange" => ""
], JSON_UNESCAPED_UNICODE);
