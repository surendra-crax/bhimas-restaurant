<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * admin/logout.php — Destroy session and redirect to login
 */
define('BHIMAS_LOADED', true);
require_once dirname(__DIR__) . '/php/config.php';

// Clear session data
$_SESSION = [];

// Expire session cookie
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
              $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}

session_destroy();
header('Location: login.php');
exit;
