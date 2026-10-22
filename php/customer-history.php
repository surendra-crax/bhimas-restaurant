<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * php/customer-history.php
 *
 * Fetches the logged-in customer's order and reservation history.
 * GET /php/customer-history.php (AJAX only)
 */
if (!defined('BHIMAS_LOADED')) {
    define('BHIMAS_LOADED', true);
}
require_once __DIR__ . '/config.php';

// Abort if not logged in
if (empty($_SESSION['customer_logged_in']) || empty($_SESSION['customer_id'])) {
    jsonResponse(401, 'You must be logged in to view your history.');
}

$customerId = (int) $_SESSION['customer_id'];

try {
    // 1. Fetch Takeaway Orders
    $ordersRaw = dbQuery(
        "SELECT id, order_ref, items_json, pickup_time, total_amount, notes, status, created_at 
         FROM orders 
         WHERE customer_id = :cid 
         ORDER BY created_at DESC",
        [':cid' => $customerId]
    );

    $orders = [];
    foreach ($ordersRaw as $ord) {
        $items = json_decode($ord['items_json'], true) ?: [];
        $orders[] = [
            'id'           => (int) $ord['id'],
            'order_ref'    => $ord['order_ref'],
            'items'        => $items,
            'pickup_time'  => $ord['pickup_time'],
            'total_amount' => (float) $ord['total_amount'],
            'notes'        => $ord['notes'] ?: '',
            'status'       => $ord['status'],
            'date_str'     => date('d M Y, H:i', strtotime($ord['created_at']))
        ];
    }

    // 2. Fetch Table Bookings
    $bookingsRaw = dbQuery(
        "SELECT id, booking_ref, date, time, guests, special_requests, status, created_at 
         FROM bookings 
         WHERE customer_id = :cid 
         ORDER BY date DESC, time DESC",
        [':cid' => $customerId]
    );

    $bookings = [];
    foreach ($bookingsRaw as $bk) {
        $bookings[] = [
            'id'               => (int) $bk['id'],
            'booking_ref'      => $bk['booking_ref'],
            'date'             => $bk['date'],
            'date_str'         => date('d M Y', strtotime($bk['date'])),
            'time'             => $bk['time'],
            'guests'           => (int) $bk['guests'],
            'special_requests' => $bk['special_requests'] ?: '',
            'status'           => $bk['status'],
            'created_str'      => date('d M Y, H:i', strtotime($bk['created_at']))
        ];
    }

    jsonResponse(200, [
        'orders'    => $orders,
        'bookings'  => $bookings
    ]);

} catch (\Throwable $e) {
    error_log('[Bhimas customer-history] Error: ' . $e->getMessage());
    jsonResponse(500, 'A database error occurred while fetching your history.');
}
