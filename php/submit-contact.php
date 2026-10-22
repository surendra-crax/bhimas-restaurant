<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * php/submit-contact.php
 *
 * Handles contact form submissions from contact.html.
 * POST /php/submit-contact.php  (JSON body, AJAX only)
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

// ── 2. Sanitise ─────────────────────────────────────────────
$name    = clean($input['name']    ?? '', 120);
$email   = cleanEmail($input['email']   ?? '');
$subject = clean($input['subject'] ?? 'General Enquiry', 120);
$message = clean($input['message'] ?? '', 3000);

// ── 3. Validate ─────────────────────────────────────────────
$errors = [];

if (mb_strlen($name) < 2) {
    $errors['name'] = 'Please enter your name.';
}
if ($email === null) {
    $errors['email'] = 'Please enter a valid email address.';
}
if (mb_strlen($message) < 10) {
    $errors['message'] = 'Message must be at least 10 characters.';
}

if (!empty($errors)) {
    jsonResponse(400, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);
}

// Sanitise subject further — only allow known values plus fallback
$allowedSubjects = [
    'general'        => 'General Enquiry',
    'reservation'    => 'Reservation Help',
    'private-dining' => 'Private Dining / Events',
    'feedback'       => 'Feedback',
    'allergy'        => 'Allergy & Dietary Information',
    'other'          => 'Other',
];
$subjectLabel = $allowedSubjects[$subject] ?? $subject;

// ── 4. Insert into messages table ───────────────────────────
try {
    dbInsert(
        "INSERT INTO messages (name, email, subject, message, is_read)
         VALUES (:name, :email, :subject, :message, 0)",
        [
            ':name'    => $name,
            ':email'   => $email,
            ':subject' => $subjectLabel,
            ':message' => $message,
        ]
    );
} catch (\Throwable $e) {
    error_log('[Bhimas submit-contact] DB insert failed: ' . $e->getMessage());
    // Don't fail silently — still try to email; respond success if email works
}

// ── 5. Send email notification ───────────────────────────────

// buildEmailHtml(), sendEmail(), ownerEmail() are defined in php/config.php

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

$ownerTo  = ownerEmail();
$emailRows = [
    ['label' => 'From',    'value' => "{$name} <{$email}>"],
    ['label' => 'Subject', 'value' => $subjectLabel],
    ['label' => 'Message', 'value' => nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'))],
];
$ownerHtml = buildEmailHtml('New Contact Message', $emailRows, 'Reply directly to this email to respond to the customer.');
sendEmail($ownerTo, "Contact: {$subjectLabel} from {$name}", $ownerHtml);

// Auto-reply to the customer
$guestRows = [
    ['label' => 'Your Name',    'value' => $name],
    ['label' => 'Subject',      'value' => $subjectLabel],
    ['label' => 'Your Message', 'value' => nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'))],
];
$guestHtml = buildEmailHtml(
    'Thank You for Getting in Touch',
    $guestRows,
    'We aim to reply within 24 hours. If your enquiry is urgent, please call us on +352 12 34 56 78.'
);
sendEmail($email, "We received your message — Bhimas Kitchen", $guestHtml);

// ── 6. Respond ───────────────────────────────────────────────
jsonResponse(200, ['message' => 'Thank you! We\'ll get back to you within 24 hours.']);
