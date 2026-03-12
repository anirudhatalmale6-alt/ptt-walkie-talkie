<?php
/**
 * IVR API - Technoline PBX
 *
 * When the campaign calls a driver and the driver answers,
 * the PBX sends a request to this URL.
 *
 * This script looks up the driver's phone in call_mapping.json
 * to find which passenger to dial, then returns simpleRouting
 * to connect the driver to the passenger.
 *
 * PBX Parameters (automatically appended to URL):
 * - PBXphone: Caller's phone number (= driver's phone)
 * - PBXnum: PBX number
 * - PBXdid: DID number dialed
 * - PBXcallId: Unique call ID
 * - PBXcallType: in=incoming, out=outgoing campaign
 * - PBXcallStatus: CALL=active, HANGUP=disconnected
 * - PBXextensionId: Extension ID
 * - PBXextensionPath: Extension path
 */

header('Content-Type: application/json; charset=utf-8');

// Get PBX parameters
$phone       = $_GET['PBXphone'] ?? '';
$callId      = $_GET['PBXcallId'] ?? '';
$callStatus  = $_GET['PBXcallStatus'] ?? '';
$callType    = $_GET['PBXcallType'] ?? '';
$extensionId = $_GET['PBXextensionId'] ?? '';

// If call is disconnected - do nothing
if ($callStatus === 'HANGUP') {
    echo json_encode(["type" => "goTo", "goTo" => ""]);
    exit;
}

// ========== Look up passenger phone from mapping ==========
$mappingFile = __DIR__ . '/call_mapping.json';
$passengerPhone = '';
$driverName = '';

if (file_exists($mappingFile)) {
    $mappings = json_decode(file_get_contents($mappingFile), true) ?: [];

    // Try exact match first
    if (isset($mappings[$phone])) {
        $passengerPhone = $mappings[$phone]['passengerPhone'];
        $driverName = $mappings[$phone]['driverName'] ?? '';
    } else {
        // Try matching without leading 0 / with +972
        // PBX might send 972XXXXXXX or 0XXXXXXX
        foreach ($mappings as $key => $val) {
            $normalKey = preg_replace('/^(\+?972|0)/', '', $key);
            $normalPhone = preg_replace('/^(\+?972|0)/', '', $phone);
            if ($normalKey === $normalPhone) {
                $passengerPhone = $val['passengerPhone'];
                $driverName = $val['driverName'] ?? '';
                break;
            }
        }
    }
}

// If no mapping found, use fallback number
if (empty($passengerPhone)) {
    $passengerPhone = '0533124489'; // Default fallback
}

// ========== Return routing to dial the passenger ==========
$response = [
    "type"          => "simpleRouting",
    "name"          => "dialPassenger",
    "dialPhone"     => $passengerPhone,
    "displayNumber" => $passengerPhone,
    "routingMusic"  => "yes",
    "ringSec"       => 30,
    "limit"         => ""
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
