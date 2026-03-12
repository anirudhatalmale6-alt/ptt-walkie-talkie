<?php
/**
 * קובץ שלוחה 8580 — URL שמוגדר במרכזייה טכנוליין
 *
 * 2 מצבים:
 *
 * מצב 1 — שיחה יוצאת (CRM מחייג לנהג):
 *   campaign מתקשר לנהג → נהג עונה → PBXphone = טלפון הנהג
 *   קורא call_mapping.json → מוצא נוסע → מחייג לנוסע
 *
 * מצב 2 — שיחה נכנסת (נוסע מחייג למספר וירטואלי):
 *   נוסע מחייג למספר וירטואלי → PBXdid = מספר וירטואלי
 *   קורא drivers.json → מוצא נהג לפי מספר וירטואלי → מחייג לנהג
 *   displayNumber = מספר הנוסע (PBXphone) כדי שהנהג יראה מי מתקשר
 *
 * מספר מערכת: 0765674892
 * מפתח: 7c2cf8346c7633
 * ID שלוחה: 8580
 */

header('Content-Type: application/json; charset=utf-8');

$phone      = $_GET['PBXphone'] ?? '';      // מי מתקשר
$did        = $_GET['PBXdid'] ?? '';         // לאיזה מספר חייגו (מספר וירטואלי)
$callStatus = $_GET['PBXcallStatus'] ?? '';
$callType   = $_GET['PBXcallType'] ?? '';    // in / out

// שיחה נותקה
if ($callStatus === 'HANGUP') {
    echo json_encode(["type" => "goTo", "goTo" => ""]);
    exit;
}

$driversFile = __DIR__ . '/drivers.json';
$mappingFile = __DIR__ . '/call_mapping.json';
$vnumsFile   = __DIR__ . '/virtual_numbers.json';

// Helper: נורמליזציה של מספר טלפון (מוריד 0, +972, 972)
function normalizePhone($p) {
    return preg_replace('/^(\+?972|0)/', '', $p);
}

// Helper: טוען JSON
function loadJ($file) {
    if (file_exists($file)) return json_decode(file_get_contents($file), true) ?: [];
    return [];
}

// ========== בדיקה: האם זו שיחה נכנסת (נוסע מחייג למספר וירטואלי)? ==========
// אם PBXdid מכיל מספר וירטואלי שקיים במערכת → מצב 2
$isIncoming = false;
$targetPhone = '';
$displayNum = '';

if (!empty($did)) {
    $drivers = loadJ($driversFile);
    $normalDid = normalizePhone($did);

    foreach ($drivers as $d) {
        $virtualNum = $d['virtual'] ?? '';
        if (!empty($virtualNum) && normalizePhone($virtualNum) === $normalDid) {
            // נמצא נהג עם המספר הוירטואלי הזה
            $isIncoming = true;
            $targetPhone = $d['phone'];         // מחייגים לנהג
            $displayNum = $phone;                // הנהג רואה את מספר הנוסע
            break;
        }
    }
}

if ($isIncoming) {
    // ===== מצב 2: נוסע → מספר וירטואלי → מחבר לנהג =====
    // לוג שיחה נכנסת
    $logFile = __DIR__ . '/call_log.json';
    $log = loadJ($logFile);

    // מוצא שם נהג
    $driverName = '';
    $drivers = loadJ($driversFile);
    foreach ($drivers as $d) {
        if (normalizePhone($d['phone']) === normalizePhone($targetPhone)) {
            $driverName = $d['name']; break;
        }
    }

    $log[] = [
        "id"             => uniqid('call_'),
        "time"           => date('Y-m-d H:i:s'),
        "driverName"     => $driverName,
        "driverPhone"    => $targetPhone,
        "passengerPhone" => $phone,
        "virtualNumber"  => $did,
        "type"           => "incoming",
        "duration"       => "",
        "recording"      => "",
        "status"         => "connected"
    ];
    if (count($log) > 1000) $log = array_slice($log, -1000);
    file_put_contents($logFile, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    echo json_encode([
        "type"          => "simpleRouting",
        "name"          => "dialDriver",
        "dialPhone"     => $targetPhone,
        "displayNumber" => $displayNum,
        "routingMusic"  => "yes",
        "ringSec"       => 30,
        "limit"         => ""
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===== מצב 1: CRM שלח campaign → נהג ענה → מחבר לנוסע =====
$passengerPhone = '';
$virtualNumber  = '';

if (file_exists($mappingFile)) {
    $mappings = json_decode(file_get_contents($mappingFile), true) ?: [];

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
}

if (empty($passengerPhone)) {
    $passengerPhone = '0533124489'; // fallback
}

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
