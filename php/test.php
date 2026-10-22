<?php
/**
 * BHIMAS — Diagnostic Test Page
 * Visit: http://localhost:8888/bhimas/php/test.php
 * DELETE this file before going live.
 */
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Bhimas — Diagnostics</title>
<style>
  body { font-family: monospace; background: #1a0a0a; color: #f0e0c0; padding: 2rem; }
  h1 { color: #FFDE59; border-bottom: 1px solid #444; padding-bottom: 0.5rem; }
  h2 { color: #ffa; margin-top: 2rem; }
  .ok   { color: #7fff7f; }
  .fail { color: #ff6b6b; }
  .warn { color: #ffd07f; }
  .box  { background: #2a1a1a; border: 1px solid #444; border-radius: 4px;
          padding: 1rem 1.5rem; margin: 0.5rem 0; }
  pre   { margin: 0.25rem 0; font-size: 0.9rem; }
  .badge { display:inline-block; padding:2px 8px; border-radius:3px;
           font-size:0.8rem; font-weight:700; margin-left:8px; }
  .badge-ok   { background:#1a4a1a; color:#7fff7f; }
  .badge-fail { background:#4a1a1a; color:#ff6b6b; }
  .badge-warn { background:#4a3a1a; color:#ffd07f; }
  table { border-collapse: collapse; width: 100%; }
  td,th { padding: 6px 12px; border-bottom: 1px solid #333; text-align:left; }
  th { color: #ffa; }
</style>
</head>
<body>
<h1>🍛 Bhimas Kitchen — Diagnostic Page</h1>
<p style="color:#999">Run at: <?= date('Y-m-d H:i:s') ?> | PHP <?= phpversion() ?></p>

<?php

// ── 1. PHP Version ──────────────────────────────────────────────
echo '<h2>1. PHP Environment</h2><div class="box">';
$phpOk = version_compare(phpversion(), '8.0', '>=');
echo '<pre>PHP Version: ' . phpversion() . ' <span class="badge ' . ($phpOk ? 'badge-ok">OK' : 'badge-fail">NEEDS 8.0+ — change PHP version in MAMP → Preferences → PHP') . '</span></pre>';
// Extra check: never return type requires 8.1
$php81 = version_compare(phpversion(), '8.1', '>=');
if (!$php81) {
    echo '<pre><span class="warn">⚠ PHP ' . phpversion() . ' detected. The site was updated to work on PHP 8.0+.</span></pre>';
    echo '<pre>  If you see "Network error" on forms, try switching to PHP 8.1+ in MAMP → Preferences → PHP.</pre>';
}
echo '<pre>Timezone: ' . date_default_timezone_get() . '</pre>';
echo '<pre>Server time (UTC): ' . gmdate('Y-m-d H:i:s') . '</pre>';
echo '<pre>Server time (Luxembourg): ';
$tz = new DateTimeZone('Europe/Luxembourg');
$now = new DateTime('now', $tz);
echo $now->format('Y-m-d H:i:s') . ' (' . $now->format('l') . ')</pre>';
echo '<pre>PDO available: ' . (extension_loaded('pdo') ? '<span class="ok">YES</span>' : '<span class="fail">NO</span>') . '</pre>';
echo '<pre>PDO MySQL: ' . (extension_loaded('pdo_mysql') ? '<span class="ok">YES</span>' : '<span class="fail">NO — install php-pdo-mysql</span>') . '</pre>';
echo '</div>';

if (!defined('BHIMAS_LOADED')) { define('BHIMAS_LOADED', true); }
require_once __DIR__ . '/config.php';

// ── 2. Database Connection ──────────────────────────────────────
echo '<h2>2. Database Connection</h2><div class="box">';
$dbHost = DB_HOST;
$dbPort = DB_PORT;
$dbName = DB_NAME;
$dbUser = DB_USER;
$dbPass = DB_PASS;

echo "<pre>Host: {$dbHost}:{$dbPort}</pre>";
echo "<pre>Database: {$dbName}</pre>";
echo "<pre>User: {$dbUser}</pre>";

try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    echo '<pre>Connection: <span class="ok">✓ CONNECTED</span></pre>';

    // Test tables
    $tables = [];
    $stmt = $pdo->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) { $tables[] = $row[0]; }
    $required = ['bookings', 'orders', 'messages', 'site_settings'];
    echo '<br><b>Tables:</b><br>';
    foreach ($required as $t) {
        $exists = in_array($t, $tables);
        echo "<pre>  {$t}: " . ($exists ? '<span class="ok">✓ EXISTS</span>' : '<span class="fail">✗ MISSING — run database.sql in phpMyAdmin</span>') . '</pre>';
    }

    // Test site_settings row count
    if (in_array('site_settings', $tables)) {
        $cnt = $pdo->query("SELECT COUNT(*) FROM site_settings")->fetchColumn();
        echo "<pre>  site_settings rows: {$cnt} " . ($cnt > 0 ? '<span class="ok">✓</span>' : '<span class="fail">✗ EMPTY — re-import database.sql</span>') . '</pre>';

        // Show key settings
        echo '<br><b>Key settings from DB:</b><br>';
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('takeaway_enabled','reservation_enabled','min_takeaway_minutes','hours_fri','hours_sat','open_time','close_time')");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<pre>  {$row['setting_key']} = {$row['setting_value']}</pre>";
        }
    }

} catch (PDOException $e) {
    echo '<pre>Connection: <span class="fail">✗ FAILED</span></pre>';
    echo '<pre>Error: <span class="fail">' . htmlspecialchars($e->getMessage()) . '</span></pre>';
    echo '<br><div class="warn">⚠ Common fixes:<br>';
    echo '• Make sure MAMP is running (Apache + MySQL both green)<br>';
    echo '• MAMP MySQL port is usually 8889 — check MAMP → Preferences → Ports<br>';
    echo '• Create database "bhimas_db" in phpMyAdmin first<br>';
    echo '• Import database.sql via phpMyAdmin → Import tab</div>';
}
echo '</div>';

// ── 3. Lead-time check simulation ──────────────────────────────
echo '<h2>3. Pickup Time Validation</h2><div class="box">';
echo '<p style="color:#999">This is why orders might be rejected even when the form looks correct.</p>';
$now = new DateTime('now', new DateTimeZone('Europe/Luxembourg'));
$nowMin = (int)$now->format('G') * 60 + (int)$now->format('i');
$minLead = 60;
$closingSlot = 22 * 60; // 22:00

echo "<pre>Current time (Luxembourg): {$now->format('H:i')}</pre>";
echo "<pre>Min lead time required: {$minLead} minutes</pre>";
echo "<pre>Earliest valid pickup: " . gmdate('H:i', ($nowMin + $minLead) * 60) . "</pre>";

$nextSlot = $nowMin + $minLead;
$nextSlot = (int)(ceil($nextSlot / 15) * 15); // round up to next 15-min slot
echo "<pre>Next available slot: " . sprintf('%02d:%02d', intdiv($nextSlot, 60), $nextSlot % 60) . "</pre>";

if ($nextSlot >= $closingSlot - 30) {
    echo '<pre><span class="warn">⚠ No slots available today — too close to closing time.</span></pre>';
    echo '<pre>Orders placed now would be rejected with "choose a time at least 60 minutes from now"</pre>';
} else {
    echo '<pre><span class="ok">✓ Slots available today from ' . sprintf('%02d:%02d', intdiv($nextSlot, 60), $nextSlot % 60) . '</span></pre>';
}
echo '</div>';

// ── 4. get-settings.php test ───────────────────────────────────
echo '<h2>4. get-settings.php Response</h2><div class="box">';
$settingsUrl = 'http://localhost:8888/bhimas/php/get-settings.php';
$ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => 'X-Requested-With: XMLHttpRequest']]);
$resp = @file_get_contents($settingsUrl, false, $ctx);
if ($resp === false) {
    echo '<pre><span class="fail">✗ Could not fetch get-settings.php (internal request blocked)</span></pre>';
    echo '<pre>This is normal — MAMP may block loopback self-requests. The browser fetch will still work.</pre>';
} else {
    $json = json_decode($resp, true);
    if ($json && isset($json['settings'])) {
        echo '<pre><span class="ok">✓ get-settings.php returns valid JSON</span></pre>';
        echo '<pre>takeaway_enabled: ' . ($json['settings']['takeaway_enabled'] ?? 'n/a') . '</pre>';
        echo '<pre>is_open_now: ' . ($json['is_open_now'] ? 'true' : 'false') . '</pre>';
    } else {
        echo '<pre><span class="fail">✗ Invalid response: ' . htmlspecialchars(substr($resp, 0, 200)) . '</span></pre>';
    }
}
echo '</div>';

// ── 5. Summary ─────────────────────────────────────────────────
echo '<h2>5. Quick Fix Checklist</h2><div class="box">';
echo '<table>';
echo '<tr><th>Issue</th><th>Fix</th></tr>';
echo '<tr><td>Database not connected</td><td>Check MAMP is running. Verify port 8889 in Preferences → Ports.</td></tr>';
echo '<tr><td>bhimas_db missing</td><td>phpMyAdmin → New → create database "bhimas_db" (utf8mb4_unicode_ci)</td></tr>';
echo '<tr><td>Tables missing / empty</td><td>phpMyAdmin → bhimas_db → Import → select database.sql</td></tr>';
echo '<tr><td>Pickup time rejected</td><td>Select a time at least 60 mins from now. Or reduce min_takeaway_minutes in site_settings.</td></tr>';
echo '<tr><td>Still "Network error"</td><td>Open Chrome DevTools → Network tab → click the failed php/submit-order.php request → Preview tab shows exact PHP error.</td></tr>';
echo '</table>';
echo '</div>';

?>

<h2 style="color:#ff6b6b; margin-top:2rem;">⚠ Delete this file before going live!</h2>
<p style="color:#999">Remove <code>php/test.php</code> from your server when deploying to production.</p>
</body>
</html>
