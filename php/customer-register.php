<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * php/customer-register.php
 *
 * Handles customer registration.
 * POST /php/customer-register.php (JSON, AJAX only)
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

if (!defined('BHIMAS_LOADED')) {
    define('BHIMAS_LOADED', true);
}
require_once __DIR__ . '/config.php';

requirePost();
requireAjax();
csrf_check();

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);

if (!is_array($input)) {
    jsonResponse(400, 'Invalid request format.');
}

$name     = clean($input['name'] ?? '', 120);
$email    = cleanEmail($input['email'] ?? '');
$password = $input['password'] ?? '';
$phone    = clean($input['phone'] ?? '', 30);

$errors = [];

if (mb_strlen($name) < 2) {
    $errors['name'] = 'Full name must be at least 2 characters.';
}
if ($email === null) {
    $errors['email'] = 'A valid email address is required.';
}
if (strlen($password) < 6) {
    $errors['password'] = 'Password must be at least 6 characters.';
}

if (!empty($errors)) {
    jsonResponse(400, ['message' => 'Please correct the highlighted fields.', 'errors' => $errors]);
}

try {
    // Check if email already registered
    $existing = dbQueryOne("SELECT id FROM customers WHERE email = :email", [':email' => $email]);
    if ($existing) {
        jsonResponse(400, ['message' => 'This email address is already registered.', 'errors' => ['email' => 'Email already registered.']]);
    }

    // Hash the password securely
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Insert new customer
    $customerId = dbInsert(
        "INSERT INTO customers (name, email, password_hash, phone) VALUES (:name, :email, :password_hash, :phone)",
        [
            ':name'          => $name,
            ':email'         => $email,
            ':password_hash' => $passwordHash,
            ':phone'         => $phone ?: null,
        ]
    );

    if (!$customerId) {
        jsonResponse(500, 'Could not create account. Please try again.');
    }

    // Auto-login the new customer by establishing session
    session_regenerate_id(true);
    $_SESSION['customer_logged_in'] = true;
    $_SESSION['customer_id']        = (int) $customerId;
    $_SESSION['customer_name']      = $name;
    $_SESSION['customer_email']     = $email;

    jsonResponse(200, [
        'message' => 'Registration successful! Welcome to Bhimas Kitchen.',
        'user' => [
            'name'  => $name,
            'email' => $email,
        ]
    ]);

} catch (\Throwable $e) {
    error_log('[Bhimas customer-register] Error: ' . $e->getMessage());
    jsonResponse(500, 'A database error occurred. Please try again later.');
}
