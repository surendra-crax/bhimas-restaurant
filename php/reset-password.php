<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * php/reset-password.php
 *
 * Validates a reset token and updates the customer's password.
 * POST /php/reset-password.php (JSON, AJAX only)
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

$token    = clean($input['token'] ?? '', 64);
$password = $input['password'] ?? '';

if (empty($token)) {
    jsonResponse(400, 'Invalid request: Security token is missing.');
}
if (strlen($password) < 6) {
    jsonResponse(400, 'Password must be at least 6 characters long.');
}

try {
    // Check if token exists and is valid (not expired)
    $now = date('Y-m-d H:i:s');
    $reset = dbQueryOne(
        "SELECT email FROM password_resets WHERE token = :token AND expires_at > :now",
        [
            ':token' => $token,
            ':now'   => $now
        ]
    );

    if (!$reset) {
        jsonResponse(400, 'The password reset link is invalid or has expired. Please request a new one.');
    }

    $email = $reset['email'];
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Update customer password
    $updated = dbExec(
        "UPDATE customers SET password_hash = :password_hash WHERE email = :email",
        [
            ':password_hash' => $passwordHash,
            ':email'         => $email
        ]
    );

    if ($updated === 0) {
        jsonResponse(400, 'Could not update password. Account may have been removed.');
    }

    // Delete token to prevent reuse
    dbExec("DELETE FROM password_resets WHERE email = :email", [':email' => $email]);

    jsonResponse(200, 'Your password has been successfully reset! You can now log in.');

} catch (\Throwable $e) {
    error_log('[Bhimas reset-password] Error: ' . $e->getMessage());
    jsonResponse(500, 'An error occurred while resetting your password. Please try again later.');
}
