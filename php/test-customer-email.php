<?php
/**
 * Test Resend Customer Email Integration
 * Visit: https://bhimas.lu/php/test-customer-email.php?to=YOUR_EMAIL
 */

if (!defined('BHIMAS_LOADED')) { define('BHIMAS_LOADED', true); }
require_once __DIR__ . '/config.php';

echo "<h1>📧 Resend Live Customer Email Test Tool</h1>";
echo "<pre>API Key: " . substr(RESEND_API_KEY, 0, 10) . "...</pre>";

$to = 'surendrabandaru09@gmail.com'; // Default fallback
if (isset($_GET['to']) && filter_var($_GET['to'], FILTER_VALIDATE_EMAIL)) {
    $to = $_GET['to'];
}
echo "<pre>Sending test email to: " . htmlspecialchars($to) . " from 'noreply@bhimas.lu'</pre>";

$subject = 'Bhimas Live Customer Email Test - ' . date('H:i:s');
$htmlBody = '<h3>Live Test</h3><p>If you see this, email sending from noreply@bhimas.lu is working perfectly!</p>';

$payload = json_encode([
    'from'    => 'Bhimas Kitchen <noreply@bhimas.lu>',
    'to'      => [$to],
    'subject' => $subject,
    'html'    => $htmlBody,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . RESEND_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
]);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

echo "<h2>Results</h2>";
if ($response === false || $curlErr) {
    echo "<p style='color:red; font-weight:bold;'>❌ cURL Error: " . htmlspecialchars($curlErr) . "</p>";
} else {
    echo "<p>HTTP Status Code: <strong>" . $httpCode . "</strong></p>";
    echo "<pre>Response Payload:\n" . htmlspecialchars($response) . "</pre>";
    
    if ($httpCode >= 200 && $httpCode < 300) {
        echo "<p style='color:green; font-weight:bold;'>✅ Success! Email was sent successfully.</p>";
    } else {
        echo "<p style='color:red; font-weight:bold;'>❌ API Error occurred from Resend.</p>";
        $data = json_decode($response, true);
        if (isset($data['message'])) {
            echo "<p>Error: " . htmlspecialchars($data['message']) . "</p>";
        }
    }
}
