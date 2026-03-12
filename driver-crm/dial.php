<?php
/**
 * Dial API - Connects driver to passenger via Technoline PBX
 *
 * Flow:
 * 1. CRM sends: driverPhone + passengerPhone
 * 2. This script saves the mapping (driver → passenger) to a JSON file
 * 3. Triggers campaign API to call the driver's phone
 * 4. When driver answers → PBX hits ivr-api.php → reads mapping → dials passenger
 *
 * GET params:
 *   driverPhone    - Driver's phone number (will be called)
 *   passengerPhone - Passenger's phone number (will be dialed when driver answers)
 *   driverName     - Driver's name (for logging)
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$driverPhone    = $_GET['driverPhone'] ?? '';
$passengerPhone = $_GET['passengerPhone'] ?? '';
$driverName     = $_GET['driverName'] ?? '';

if (empty($driverPhone) || empty($passengerPhone)) {
    echo json_encode(["status" => "error", "message" => "חסר מספר טלפון"]);
    exit;
}

// ========== Save mapping: driver phone → passenger phone ==========
$mappingFile = __DIR__ . '/call_mapping.json';
$mappings = [];
if (file_exists($mappingFile)) {
    $mappings = json_decode(file_get_contents($mappingFile), true) ?: [];
}

// Store mapping (key = driver phone, value = passenger phone + timestamp)
$mappings[$driverPhone] = [
    "passengerPhone" => $passengerPhone,
    "driverName"     => $driverName,
    "timestamp"      => date('Y-m-d H:i:s')
];

file_put_contents($mappingFile, json_encode($mappings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// ========== Trigger Campaign API to call the driver ==========
$data = [
    "action"              => "campaignRun",
    "apiKey"              => "798407e3a74922",
    "messagesType"        => "extensionActivation",
    "extensionActivation" => "8576",
    "phones"              => $driverPhone,
    "callLength"          => 60,
    "dialRetries"         => 1,
    "betweenRetries"      => 20,
    "reasonableHours"     => "no"
];

$ch = curl_init("https://app.ipsales.co.il/campaignApi.php");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// ========== Log the call ==========
$logFile = __DIR__ . '/call_log.json';
$log = [];
if (file_exists($logFile)) {
    $log = json_decode(file_get_contents($logFile), true) ?: [];
}
$log[] = [
    "time"           => date('Y-m-d H:i:s'),
    "driverName"     => $driverName,
    "driverPhone"    => $driverPhone,
    "passengerPhone" => $passengerPhone,
    "httpCode"       => $httpCode,
    "response"       => json_decode($response, true) ?? $response,
    "error"          => $error ?: null
];
// Keep last 500 entries
if (count($log) > 500) {
    $log = array_slice($log, -500);
}
file_put_contents($logFile, json_encode($log, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

// ========== Return result ==========
if ($error) {
    echo json_encode(["status" => "error", "message" => $error]);
} else {
    echo json_encode([
        "status"   => "sent",
        "httpCode" => $httpCode,
        "driver"   => $driverPhone,
        "passenger"=> $passengerPhone,
        "response" => json_decode($response, true) ?? $response
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
