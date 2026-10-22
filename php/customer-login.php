<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * php/customer-login.php
 *
 * Handles customer authentication.
 * POST /php/customer-login.php (JSON, AJAX only)
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

$email    = cleanEmail($input['email'] ?? '');
$password = $input['password'] ?? '';

if ($email === null || empty($password)) {
    jsonResponse(400, 'Please enter both your email address and password.');
}

try {
    $user = dbQueryOne("SELECT id, name, email, password_hash FROM customers WHERE email = :email", [':email' => $email]);
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        // Slight delay to prevent brute-force
        usleep(250000);
        jsonResponse(400, 'Incorrect email or password.');
    }

    // Login successful
    session_regenerate_id(true);
    $_SESSION['customer_logged_in'] = true;
    $_SESSION['customer_id']        = (int) $user['id'];
    $_SESSION['customer_name']      = $user['name'];
    $_SESSION['customer_email']     = $user['email'];

    jsonResponse(200, [
        'message' => 'Logged in successfully! Welcome back, ' . htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') . '.',
        'user' => [
            'name'  => $user['name'],
            'email' => $user['email']
        ]
    ]);

} catch (\Throwable $e) {
    error_log('[Bhimas customer-login] Error: ' . $e->getMessage());
    jsonResponse(500, 'A database error occurred. Please try again later.');
}
