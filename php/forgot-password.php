<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * php/forgot-password.php
 *
 * Generates a password reset token and sends an email to the user.
 * POST /php/forgot-password.php (JSON, AJAX only)
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

$email = cleanEmail($input['email'] ?? '');

if ($email === null) {
    jsonResponse(400, 'A valid email address is required.');
}

try {
    // Check if the user exists
    $user = dbQueryOne("SELECT name FROM customers WHERE email = :email", [':email' => $email]);
    
    // To prevent email harvesting, always return success even if user doesn't exist
    $successMsg = 'If your email is registered with us, you will receive a password reset link shortly.';

    if ($user) {
        // Generate secure random token
        $token = bin2hex(random_bytes(32));
        $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour expiry

        // Store reset token
        dbExec(
            "REPLACE INTO password_resets (email, token, expires_at) VALUES (:email, :token, :expires_at)",
            [
                ':email'      => $email,
                ':token'      => $token,
                ':expires_at' => $expiry
            ]
        );

        // Dynamically build base URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Check if bhimas is in URI or root
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $baseDir = (strpos($uri, '/bhimas/') !== false) ? '/bhimas' : '';
        $resetLink = "{$protocol}://{$host}{$baseDir}/login.html?token={$token}";

        // Send Reset Email
        $subject = 'Reset Your Password — Bhimas Kitchen';
        $emailHtml = buildEmailHtml(
            'Password Reset Request',
            [
                'Hello'           => htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') . ',',
                'Description'     => 'We received a request to reset the password for your Bhimas Kitchen account.',
                'Reset Link'      => "<a href='{$resetLink}' style='background-color:#801C23; color:#FFDE59; padding:10px 18px; text-decoration:none; font-family:Poppins, sans-serif; font-size:14px; font-weight:bold; border-radius:4px; display:inline-block; margin:8px 0; border:1px solid #C9A84C;'>Reset Password</a>",
                'Link Expires In' => '1 hour'
            ],
            "If you did not request a password reset, please ignore this email. Your password will remain unchanged."
        );

        sendEmail($email, $subject, $emailHtml);
    }

    jsonResponse(200, $successMsg);

} catch (\Throwable $e) {
    error_log('[Bhimas forgot-password] Error: ' . $e->getMessage());
    jsonResponse(500, 'An error occurred while processing your request. Please try again later.');
}
