<?php
/**
 * קובץ שלוחה 8576 — URL שמוגדר במרכזייה טכנוליין
 *
 * כשנהג עונה לשיחה, המרכזייה פונה לקובץ הזה.
 * הקובץ קורא את call_mapping.json, מוצא את מספר הנוסע
 * לפי מספר הנהג, ומחזיר simpleRouting לחייג לנוסע.
 *
 * URL לשלוחה: https://your-server.com/extension.php
 */

header('Content-Type: application/json; charset=utf-8');

$phone      = $_GET['PBXphone'] ?? '';
$callStatus = $_GET['PBXcallStatus'] ?? '';

// שיחה נותקה — לא עושים כלום
if ($callStatus === 'HANGUP') {
    echo json_encode(["type" => "goTo", "goTo" => ""]);
    exit;
}

// קורא את המיפוי נהג→נוסע
$mappingFile = __DIR__ . '/call_mapping.json';
$passengerPhone = '';
$virtualNumber  = '';

if (file_exists($mappingFile)) {
    $mappings = json_decode(file_get_contents($mappingFile), true) ?: [];

    // חיפוש ישיר
    if (isset($mappings[$phone])) {
        $passengerPhone = $mappings[$phone]['passengerPhone'];
        $virtualNumber  = $mappings[$phone]['virtualNumber'] ?? '';
    } else {
        // חיפוש עם נורמליזציה (0 / +972 / 972)
        foreach ($mappings as $key => $val) {
            $normalKey   = preg_replace('/^(\+?972|0)/', '', $key);
            $normalPhone = preg_replace('/^(\+?972|0)/', '', $phone);
            if ($normalKey === $normalPhone) {
                $passengerPhone = $val['passengerPhone'];
                $virtualNumber  = $val['virtualNumber'] ?? '';
                break;
            }
        }
    }
}

// אם לא נמצא מיפוי — fallback
if (empty($passengerPhone)) {
    $passengerPhone = '0533124489';
}

// הנוסע רואה את המספר הוירטואלי
$displayNum = !empty($virtualNumber) ? $virtualNumber : $passengerPhone;

// מחזיר למרכזייה: תחייג לנוסע, הנוסע רואה מספר וירטואלי
echo json_encode([
    "type"          => "simpleRouting",
    "name"          => "dialPassenger",
    "dialPhone"     => $passengerPhone,
    "displayNumber" => $displayNum,
    "routingMusic"  => "yes",
    "ringSec"       => 30,
    "limit"         => ""
], JSON_UNESCAPED_UNICODE);
