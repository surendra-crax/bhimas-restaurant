<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * php/customer-session.php
 *
 * Checks if the customer is logged in and returns user details + CSRF token.
 * GET /php/customer-session.php (AJAX only)
 */
if (!defined('BHIMAS_LOADED')) {
    define('BHIMAS_LOADED', true);
}
require_once __DIR__ . '/config.php';

$loggedIn = !empty($_SESSION['customer_logged_in']) && !empty($_SESSION['customer_id']);
$user = null;

if ($loggedIn) {
    try {
        $data = dbQueryOne("SELECT id, name, email, phone FROM customers WHERE id = :id", [':id' => $_SESSION['customer_id']]);
        if ($data) {
            $user = [
                'id'    => (int) $data['id'],
                'name'  => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? ''
            ];
        } else {
            // Cleanup invalid session (e.g. user was deleted)
            unset($_SESSION['customer_logged_in']);
            unset($_SESSION['customer_id']);
            unset($_SESSION['customer_name']);
            unset($_SESSION['customer_email']);
            $loggedIn = false;
        }
    } catch (\Throwable $e) {
        $loggedIn = false;
    }
}

jsonResponse(200, [
    'logged_in'  => $loggedIn,
    'user'       => $user,
    'csrf_token' => csrf_token()
]);
