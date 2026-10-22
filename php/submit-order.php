<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * php/submit-order.php
 *
 * Handles takeaway pre-order submissions from takeaway.html.
 * POST /php/submit-order.php  (JSON body, AJAX only)
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

if (!defined('BHIMAS_LOADED')) { define('BHIMAS_LOADED', true); }
require_once __DIR__ . '/config.php';

requirePost();
requireAjax();
csrf_check();

// ── 1. Read JSON body ────────────────────────────────────────
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    jsonResponse(400, 'Invalid request format.');
}

// ── 2. Sanitise inputs ──────────────────────────────────────
$name        = clean($input['name']        ?? '', 120);
$phone       = clean($input['phone']       ?? '', 30);
$email       = cleanEmail($input['email']  ?? '');
$pickupTime  = clean($input['pickup_time'] ?? '', 10);
$notes       = clean($input['notes']       ?? '', 1000);
$totalRaw    = $input['total']             ?? '0';
$itemsRaw    = $input['items']             ?? [];

// ── 3. Validate fields ──────────────────────────────────────
$errors = [];

if (mb_strlen($name) < 2) {
    $errors['name'] = 'Full name is required.';
}
if (mb_strlen($phone) < 6) {
    $errors['phone'] = 'A valid phone number is required.';
}
if ($email === null) {
    $errors['email'] = 'A valid email address is required.';
}
if (!preg_match('/^\d{2}:\d{2}$/', $pickupTime)) {
    $errors['pickup_time'] = 'A valid pickup time is required (HH:MM).';
}
if (!is_array($itemsRaw) || count($itemsRaw) === 0) {
    $errors['items'] = 'Your cart is empty.';
}

// Validate and sanitise each item
$items       = [];
$calcTotal   = 0.0;

foreach ($itemsRaw as $item) {
    if (!is_array($item)) continue;
    $itemId    = clean($item['id']    ?? '', 60);
    $itemName  = clean($item['name']  ?? '', 120);
    $itemPrice = round((float)($item['price'] ?? 0), 2);
    $itemQty   = max(1, (int)($item['qty'] ?? 1));

    if ($itemId === '' || $itemName === '' || $itemPrice <= 0) {
        continue; // skip malformed
    }
    $items[]    = ['id' => $itemId, 'name' => $itemName, 'price' => $itemPrice, 'qty' => $itemQty];
    $calcTotal += $itemPrice * $itemQty;
}

if (count($items) === 0) {
    $errors['items'] = 'No valid items in cart.';
}

if (!empty($errors)) {
    jsonResponse(400, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);
}

// ── 4. Load settings for server-side checks ──────────────────
$settings = [];
try {
    $rows = dbQuery("SELECT setting_key, setting_value FROM site_settings");
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (\Throwable $e) {
    // Use defaults
    if (defined('BHIMAS_DEBUG') && BHIMAS_DEBUG) {
        throw $e;
    }
}

if (($settings['takeaway_enabled'] ?? '1') === '0') {
    jsonResponse(400, 'Online ordering is currently unavailable. Please call us.');
}

// Minimum order amount
$minOrder = (float)($settings['min_order_amount'] ?? '10.00');
if ($calcTotal < $minOrder) {
    jsonResponse(400, sprintf('Minimum order is €%.2f. Your total is €%.2f.', $minOrder, $calcTotal));
}

// ── 5. Validate pickup_time lead time ────────────────────────
$minLeadMin  = (int)($settings['min_takeaway_minutes'] ?? 60);
$tz          = new DateTimeZone('Europe/Luxembourg');
$now         = new DateTime('now', $tz);
$nowMinutes  = (int)$now->format('G') * 60 + (int)$now->format('i');

$pickupParts  = explode(':', $pickupTime);
$pickupMinutes = (int)$pickupParts[0] * 60 + (int)($pickupParts[1] ?? 0);
$leadMinutes   = $pickupMinutes - $nowMinutes;

if ($leadMinutes < $minLeadMin) {
    jsonResponse(400, "Please choose a pickup time at least {$minLeadMin} minutes from now.");
}

// Check today's hours
$dow      = (int)$now->format('w');
$dayKeys  = ['sun','mon','tue','wed','thu','fri','sat'];
$hoursRaw = $settings['hours_' . $dayKeys[$dow]] ?? 'closed';

if (strtolower(trim($hoursRaw)) === 'closed') {
    jsonResponse(400, 'We are closed today. Please call us to arrange an order.');
}

$hoursParts = explode('-', $hoursRaw, 2);
if (count($hoursParts) === 2) {
    $toMin    = static function (string $t): int {
        $p = explode(':', trim($t), 2);
        return (int)$p[0] * 60 + (int)($p[1] ?? 0);
    };
    $closeMin  = $toMin($hoursParts[1]);
    $lastSlot  = $closeMin - (int)($settings['last_pickup_mins_before_close'] ?? 30);
    if ($pickupMinutes < $toMin($hoursParts[0]) || $pickupMinutes > $lastSlot) {
        jsonResponse(400, "That pickup time is outside our service window. Please choose an earlier time.");
    }
}

// ── 6. Encode items as JSON ──────────────────────────────────
$itemsJson  = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$totalFinal = round($calcTotal, 2);
$orderRef   = generateRef('ORD');

// ── 7. Insert order ──────────────────────────────────────────
$customerId = !empty($_SESSION['customer_logged_in']) && !empty($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : null;

try {
    dbInsert(
        "INSERT INTO orders
            (customer_id, order_ref, name, phone, email, items_json, pickup_time, total_amount, notes, status)
         VALUES
            (:customer_id, :ref, :name, :phone, :email, :items, :pickup, :total, :notes, 'pending')",
        [
            ':customer_id' => $customerId,
            ':ref'         => $orderRef,
            ':name'        => $name,
            ':phone'       => $phone,
            ':email'       => $email,
            ':items'       => $itemsJson,
            ':pickup'      => $pickupTime,
            ':total'       => $totalFinal,
            ':notes'       => $notes,
        ]
    );
} catch (\Throwable $e) {
    error_log('[Bhimas submit-order] DB insert failed: ' . $e->getMessage());
    $msg = (defined('BHIMAS_DEBUG') && BHIMAS_DEBUG)
        ? 'DB insert failed: ' . $e->getMessage()
        : 'We could not save your order. Please call us directly.';
    jsonResponse(500, $msg);
}

// ── 8. Build item list for email ─────────────────────────────
// buildEmailHtml(), ownerEmail(), sendEmail() are defined in php/config.php

/**
 * Parse today's hours from settings and determine if booking/orders are allowed.
 * Returns ['open'=>bool, 'open_str'=>'11:00', 'close_str'=>'22:00']
 */
function getTodayStatus(array $settings): array
{
    $dayKeys = ['sun','mon','tue','wed','thu','fri','sat'];
    $tz      = new DateTimeZone('Europe/Luxembourg');
    $now     = new DateTime('now', $tz);
    $dow     = (int) $now->format('w');
    $key     = 'hours_' . $dayKeys[$dow];
    $raw     = $settings[$key] ?? 'closed';
    $raw     = trim(strtolower($raw));

    if ($raw === '' || $raw === 'closed') {
        return ['open' => false, 'open_str' => '', 'close_str' => '', 'now_minutes' => 0];
    }
    $parts = explode('-', $raw, 2);
    if (count($parts) !== 2) {
        return ['open' => false, 'open_str' => '', 'close_str' => '', 'now_minutes' => 0];
    }
    $toMin = static function (string $t): int {
        $p = explode(':', trim($t), 2);
        return (int)$p[0] * 60 + (int)($p[1] ?? 0);
    };
    $openMin  = $toMin($parts[0]);
    $closeMin = $toMin($parts[1]);
    $nowMin   = (int)$now->format('G') * 60 + (int)$now->format('i');

    return [
        'open'       => ($nowMin >= $openMin && $nowMin < $closeMin),
        'open_str'   => trim($parts[0]),
        'close_str'  => trim($parts[1]),
        'open_min'   => $openMin,
        'close_min'  => $closeMin,
        'now_minutes'=> $nowMin,
    ];
}

// ── 8. Send emails using rich templates ─────────────────────
// buildOrderEmail() and ownerEmail() defined in php/config.php

$ownerTo   = ownerEmail();
$phoneNum  = defined('SITE_PHONE') ? SITE_PHONE : '+352 12 34 56 78';

// Owner notification — full details including phone + email
$ownerHtml = buildOrderEmail(
    'New Takeaway Order',
    $orderRef, $name, $phone, $email, $pickupTime,
    $items, $totalFinal, $notes,
    'Please prepare this order before the pickup time.',
    true  // isOwner = show phone & email
);
sendEmail($ownerTo, "New Order: {$orderRef} — Pickup {$pickupTime}", $ownerHtml);

// Guest confirmation — friendly version without contact details
$guestHtml = buildOrderEmail(
    'Your Order Confirmation',
    $orderRef, $name, '', '', $pickupTime,
    $items, $totalFinal, $notes,
    "Please arrive a few minutes before your pickup time. To make changes, call us on {$phoneNum}.",
    false // isOwner = guest view
);
sendEmail($email, "Order Confirmed — {$orderRef}", $guestHtml);

// ── 9. Respond ───────────────────────────────────────────────
jsonResponse(200, [
    'message' => 'Order placed! We\'ll have it ready at ' . $pickupTime . '.',
    'ref'     => $orderRef,
    'total'   => number_format($totalFinal, 2),
]);
