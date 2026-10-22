<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * admin/index.php — Dashboard overview
 */
define('BHIMAS_LOADED', true);
require_once dirname(__DIR__) . '/php/config.php';
require_once __DIR__ . '/_helpers.php';
adminGuard();

// ── Stats ──────────────────────────────────────────────────────────────────
$pendingBookings = 0; $pendingOrders = 0; $unreadMessages = 0; $weekOrders = 0;
$recentBookings = []; $recentOrders  = [];

try {
    $pendingBookings = (int)(dbQueryOne("SELECT COUNT(*) AS n FROM bookings WHERE status = 'pending'")['n'] ?? 0);
    $pendingOrders   = (int)(dbQueryOne("SELECT COUNT(*) AS n FROM orders WHERE status = 'pending'")['n'] ?? 0);
    $unreadMessages = (int)(dbQueryOne('SELECT COUNT(*) AS n FROM messages WHERE is_read = 0')['n'] ?? 0);
    $weekOrders     = (int)(dbQueryOne(
        "SELECT COUNT(*) AS n FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
    )['n'] ?? 0);
    $recentBookings = dbQuery(
        'SELECT booking_ref, name, date, time, guests, status FROM bookings ORDER BY created_at DESC LIMIT 5'
    );
    $recentOrders = dbQuery(
        'SELECT order_ref, name, pickup_time, total_amount, status FROM orders ORDER BY created_at DESC LIMIT 5'
    );
} catch (\Throwable $e) {
    error_log('[Bhimas admin/index] ' . $e->getMessage());
}

adminHead('Dashboard', 'index');
?>
<div class="stat-cards">
  <div class="stat-card">
    <div class="stat-card__value"><?= $pendingBookings ?></div>
    <div class="stat-card__label">Pending Bookings</div>
  </div>
  <div class="stat-card">
    <div class="stat-card__value"><?= $pendingOrders ?></div>
    <div class="stat-card__label">Pending Orders</div>
  </div>
  <div class="stat-card">
    <div class="stat-card__value"><?= $weekOrders ?></div>
    <div class="stat-card__label">This Week&rsquo;s Orders</div>
  </div>
</div>

<div class="adm-section">
  <div class="adm-section__head">
    <h2>Recent Bookings</h2>
    <a href="bookings.php" class="btn-adm btn-adm--outline btn-adm--sm">View All</a>
  </div>
  <div class="adm-table-wrap">
    <table class="adm-table">
      <thead>
        <tr>
          <th>Ref</th><th>Name</th><th>Date</th><th>Time</th><th>Guests</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recentBookings)): ?>
          <tr><td colspan="6" style="text-align:center;padding:28px;color:#999;">No bookings yet.</td></tr>
        <?php else: foreach ($recentBookings as $b): ?>
          <tr>
            <td><code style="font-size:11px;"><?= htmlspecialchars($b['booking_ref'], ENT_QUOTES, 'UTF-8') ?></code></td>
            <td><?= htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($b['date'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($b['time'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= (int)$b['guests'] ?></td>
            <td><span class="badge badge--<?= htmlspecialchars($b['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($b['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="adm-section">
  <div class="adm-section__head">
    <h2>Recent Orders</h2>
    <a href="orders.php" class="btn-adm btn-adm--outline btn-adm--sm">View All</a>
  </div>
  <div class="adm-table-wrap">
    <table class="adm-table">
      <thead>
        <tr>
          <th>Ref</th><th>Name</th><th>Pickup</th><th>Total</th><th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($recentOrders)): ?>
          <tr><td colspan="5" style="text-align:center;padding:28px;color:#999;">No orders yet.</td></tr>
        <?php else: foreach ($recentOrders as $o): ?>
          <tr>
            <td><code style="font-size:11px;"><?= htmlspecialchars($o['order_ref'], ENT_QUOTES, 'UTF-8') ?></code></td>
            <td><?= htmlspecialchars($o['name'], ENT_QUOTES, 'UTF-8') ?></td>
            <td><?= htmlspecialchars($o['pickup_time'], ENT_QUOTES, 'UTF-8') ?></td>
            <td>&euro;<?= number_format((float)$o['total_amount'], 2) ?></td>
            <td><span class="badge badge--<?= htmlspecialchars($o['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($o['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php adminFoot(); ?>
