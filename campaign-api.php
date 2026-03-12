<?php
/**
 * Campaign API - Technoline / IPSales
 * Sends a campaign call to a phone number using extension activation
 *
 * API URL: https://app.ipsales.co.il/campaignApi.php
 */

// Phone number to call
$phone = "0527172458";

// API parameters
$data = [
    "action"              => "campaignRun",
    "apiKey"              => "798407e3a74922",
    "messagesType"        => "extensionActivation",
    "extensionActivation" => "8576",
    "phones"              => $phone,
    "callLength"          => 25,
    "dialRetries"         => 1,
    "betweenRetries"      => 20,
    "reasonableHours"     => "no"
];

// Send POST request
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

// Output result
header('Content-Type: application/json; charset=utf-8');

if ($error) {
    echo json_encode(["status" => "error", "message" => $error]);
} else {
    echo json_encode([
        "status"   => "sent",
        "httpCode" => $httpCode,
        "phone"    => $phone,
        "response" => json_decode($response, true) ?? $response
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}
