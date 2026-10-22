<?php
/**
 * Test Resend Email Integration
 * Visit: http://localhost:8888/bhimas/php/test-email.php
 */

if (!defined('BHIMAS_LOADED')) { define('BHIMAS_LOADED', true); }
require_once __DIR__ . '/config.php';

echo "<h1>📧 Resend Email Test Tool</h1>";
echo "<pre>API Key being used: " . RESEND_API_KEY . "</pre>";
echo "<pre>Sending test email to: surendrabandaru09@gmail.com</pre>";

$to = 'surendrabandaru09@gmail.com';
$subject = 'Bhimas Local Test - ' . date('H:i:s');
$htmlBody = '<h3>Local Test</h3><p>If you see this, email sending is working!</p>';

$payload = json_encode([
    'from'    => 'Bhimas Kitchen <onboarding@resend.dev>',
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
    // Let's print out header details if needed
    CURLOPT_VERBOSE        => true,
]);

// Capture verbose debug stream
$verbose = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $verbose);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

echo "<h2>Results</h2>";
if ($response === false || $curlErr) {
    echo "<p style='color:red; font-weight:bold;'>❌ cURL Error: " . htmlspecialchars($curlErr) . "</p>";
    
    // Check if it is SSL cert verification issue
    if (strpos(strtolower($curlErr), 'ssl') !== false || strpos(strtolower($curlErr), 'certificate') !== false) {
        echo "<div style='background:#fee; border:1px solid red; padding:10px;'>";
        echo "<strong>SSL Verification Failure Detected!</strong><br>";
        echo "Local MAMP environments often lack a valid SSL certificate bundle for cURL. ";
        echo "This is extremely common in local environments and does NOT affect the live server. ";
        echo "If this is the error, the emails will work perfectly on your live hosting server!</div>";
    }
} else {
    echo "<p>HTTP Status Code: <strong>" . $httpCode . "</strong></p>";
    echo "<pre>Response Payload:\n" . htmlspecialchars($response) . "</pre>";
    
    if ($httpCode >= 200 && $httpCode < 300) {
        echo "<p style='color:green; font-weight:bold;'>✅ Success! Check your inbox/spam folder.</p>";
    } else {
        echo "<p style='color:red; font-weight:bold;'>❌ API Error occurred.</p>";
        $data = json_decode($response, true);
        if (isset($data['message'])) {
            echo "<p>Error Message: " . htmlspecialchars($data['message']) . "</p>";
        }
    }
}

// Show verbose connection details
rewind($verbose);
$verboseLog = stream_get_contents($verbose);
echo "<h2>cURL Verbose Connection Log:</h2>";
echo "<pre>" . htmlspecialchars($verboseLog) . "</pre>";
fclose($verbose);
