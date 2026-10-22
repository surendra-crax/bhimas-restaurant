<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * php/customer-logout.php
 *
 * Handles customer logout.
 */
if (!defined('BHIMAS_LOADED')) {
    define('BHIMAS_LOADED', true);
}
require_once __DIR__ . '/config.php';

// Unset customer session variables
unset($_SESSION['customer_logged_in']);
unset($_SESSION['customer_id']);
unset($_SESSION['customer_name']);
unset($_SESSION['customer_email']);

// If AJAX, return JSON response, otherwise redirect back to index
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
    jsonResponse(200, 'Logged out successfully.');
} else {
    header('Location: ../index.html');
    exit;
}
