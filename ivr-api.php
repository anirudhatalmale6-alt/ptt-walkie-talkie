<?php
/**
 * IVR API - Technoline PBX
 *
 * When a call reaches the API extension, the PBX sends a request to this URL.
 * This script returns a JSON command to dial 0527172458.
 *
 * PBX Parameters (automatically appended to URL):
 * - PBXphone: Caller's phone number
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

// Dial 0533124489 with caller ID (PBXcallId)
$response = [
    "type"         => "simpleRouting",
    "name"         => "dialResult",
    "dialPhone"    => "0533124489",
    "displayNumber"=> "0533124489",
    "routingMusic" => "yes",
    "ringSec"      => 30,
    "limit"        => ""
];

echo json_encode($response, JSON_UNESCAPED_UNICODE);
