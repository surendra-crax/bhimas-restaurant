<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * php/get-csrf.php
 *
 * Returns a CSRF token for the current session.
 * Called by main.js on page load so static .html pages
 * can participate in CSRF-protected form submissions.
 *
 * GET /php/get-csrf.php  (AJAX only)
 */

if (!defined('BHIMAS_LOADED')) { define('BHIMAS_LOADED', true); }
require_once __DIR__ . '/config.php';

// Allow cross-origin requests from same host (MAMP / local dev)
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

echo json_encode([
    'success' => true,
    'token'   => csrf_token(),
]);
