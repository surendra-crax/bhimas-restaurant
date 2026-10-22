<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * php/submit-booking.php
 *
 * Handles table reservation form submissions from reservation.html.
 * POST /php/submit-booking.php  (JSON body, AJAX only)
 */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }

if (!defined('BHIMAS_LOADED')) { define('BHIMAS_LOADED', true); }
require_once __DIR__ . '/config.php';

requirePost();
requireAjax();
csrf_check();

// ── 1. Read and decode JSON body ────────────────────────────
$raw   = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    jsonResponse(400, 'Invalid request format.');
}

// ── 2. Sanitise inputs ──────────────────────────────────────
$name             = clean($input['name']             ?? '', 120);
$phone            = clean($input['phone']            ?? '', 30);
$email            = cleanEmail($input['email']       ?? '');
$date             = clean($input['date']             ?? '', 10);
$time             = clean($input['time']             ?? '', 10);
$guestsRaw        = $input['guests']                 ?? '';
$specialRequests  = clean($input['special_requests'] ?? '', 1000);

// ── 3. Validate required fields ─────────────────────────────
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
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $errors['date'] = 'A valid date is required (YYYY-MM-DD).';
} else {
    $bookingDate = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    $today       = new DateTimeImmutable('today', new DateTimeZone('Europe/Luxembourg'));
    if (!$bookingDate || $bookingDate < $today) {
        $errors['date'] = 'Booking date cannot be in the past.';
    }
}
if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
    $errors['time'] = 'A valid time is required (HH:MM).';
}

// Guests: allow "11+" as a string or integer
$guestsStr = trim((string) $guestsRaw);
if ($guestsStr === '11+') {
    $guests = 11;
} else {
    $guests = cleanInt($guestsRaw, 1, 20);
    if ($guests === null) {
        $errors['guests'] = 'Number of guests must be between 1 and 20.';
    }
}

if (!empty($errors)) {
    jsonResponse(400, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);
}

// ── 4. Server-side availability check ───────────────────────
$settings = [];
try {
    $rows = dbQuery("SELECT setting_key, setting_value FROM site_settings");
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (\Throwable $e) {
    // Proceed with defaults if DB unavailable
    if (defined('BHIMAS_DEBUG') && BHIMAS_DEBUG) {
        throw $e;
    }
}

if (($settings['reservation_enabled'] ?? '1') === '0') {
    jsonResponse(400, 'Online reservations are currently unavailable. Please call us.');
}

// Check hours for the requested date (not necessarily today)
$reqDate   = DateTimeImmutable::createFromFormat('Y-m-d', $date, new DateTimeZone('Europe/Luxembourg'));
$reqDow    = (int) $reqDate->format('w');
$dayKeys   = ['sun','mon','tue','wed','thu','fri','sat'];
$hoursKey  = 'hours_' . $dayKeys[$reqDow];
$hoursRaw  = $settings[$hoursKey] ?? 'closed';

if (strtolower(trim($hoursRaw)) === 'closed') {
    jsonResponse(400, 'We are closed on that day. Please choose another date.');
}

$hoursParts = explode('-', $hoursRaw, 2);
if (count($hoursParts) === 2) {
    $toMin = static function (string $t): int {
        $p = explode(':', trim($t), 2);
        return (int)$p[0] * 60 + (int)($p[1] ?? 0);
    };
    $openMin   = $toMin($hoursParts[0]);
    $closeMin  = $toMin($hoursParts[1]);
    $lastSlot  = $closeMin - (int)($settings['last_reservation_mins_before_close'] ?? 60);
    $reqMin    = $toMin($time);

    if ($reqMin < $openMin || $reqMin > $lastSlot) {
        $openStr  = trim($hoursParts[0]);
        $closeStr = trim($hoursParts[1]);
        jsonResponse(400, "That time is outside our opening hours ({$openStr} – {$closeStr}). Please choose another time.");
    }
}

// ── 5. Generate booking reference ───────────────────────────
$bookingRef = generateRef('RES');

// ── 6. Insert booking into database ─────────────────────────
$customerId = !empty($_SESSION['customer_logged_in']) && !empty($_SESSION['customer_id']) ? (int) $_SESSION['customer_id'] : null;

try {
    dbInsert(
        "INSERT INTO bookings
            (customer_id, booking_ref, name, phone, email, date, time, guests, special_requests, status)
         VALUES
            (:customer_id, :ref, :name, :phone, :email, :date, :time, :guests, :requests, 'pending')",
        [
            ':customer_id' => $customerId,
            ':ref'         => $bookingRef,
            ':name'        => $name,
            ':phone'       => $phone,
            ':email'       => $email,
            ':date'        => $date,
            ':time'        => $time,
            ':guests'      => (int) $guests,
            ':requests'    => $specialRequests,
        ]
    );
} catch (\Throwable $e) {
    error_log('[Bhimas submit-booking] DB insert failed: ' . $e->getMessage());
    $msg = (defined('BHIMAS_DEBUG') && BHIMAS_DEBUG)
        ? 'DB insert failed: ' . $e->getMessage()
        : 'We could not save your reservation. Please call us directly.';
    jsonResponse(500, $msg);
}

// ── 7. Send notification email to owner ─────────────────────
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

// ── 7. Send emails using rich templates ─────────────────────
// buildReservationEmail() defined in php/config.php

$ownerTo  = ownerEmail();
$phoneNum = defined('SITE_PHONE') ? SITE_PHONE : '+352 12 34 56 78';
$guestCount = ($guestsStr === '11+') ? 11 : (int)$guests;

// Owner notification — full contact details
$ownerHtml = buildReservationEmail(
    'New Table Reservation',
    $bookingRef, $name, $phone, $email,
    $date, $time, $guestCount,
    $specialRequests ?: '',
    'Please confirm or decline this booking at your earliest convenience.',
    true  // isOwner
);
sendEmail($ownerTo, "New Reservation: {$bookingRef} — {$name}", $ownerHtml);

// ── 8. Send confirmation email to guest ─────────────────────
$guestHtml = buildReservationEmail(
    'Your Reservation Request',
    $bookingRef, $name, '', $email,
    $date, $time, $guestCount,
    $specialRequests ?: '',
    "We will confirm your booking by email shortly. To make any changes, please call us on {$phoneNum}.",
    false // guest view
);
sendEmail($email, "Reservation Request Received — {$bookingRef}", $guestHtml);

// ── 9. Respond ───────────────────────────────────────────────
jsonResponse(200, [
    'message' => 'Reservation request received! We will confirm shortly.',
    'ref'     => $bookingRef,
]);
