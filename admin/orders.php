<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * admin/orders.php — Manage takeaway pre-orders
 *
 * GET  ?status=&date=&page=  — filtered + paginated list
 * GET  ?export=csv           — CSV download
 * POST update_order          — change status + send customer email
 */
define('BHIMAS_LOADED', true);
require_once dirname(__DIR__) . '/php/config.php';
require_once __DIR__ . '/_helpers.php';
adminGuard();

$STATUSES = ['pending', 'preparing', 'ready', 'completed', 'cancelled'];

// ── Handle status update (POST + PRG) ─────────────────────────────────────
$flash = $flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid security token.'; $flashType = 'error';
    } else {
        $id     = cleanInt($_POST['order_id'] ?? '', 1);
        $status = in_array($_POST['new_status'] ?? '', $STATUSES, true) ? $_POST['new_status'] : null;

        if ($id && $status) {
            try {
                $order = dbQueryOne('SELECT * FROM orders WHERE id = ?', [$id]);
                if ($order) {
                    dbExec('UPDATE orders SET status = ? WHERE id = ?', [$status, $id]);

                    // Email messages per status
                    $emailMap = [
                        'preparing' => ['Your order is being prepared', 'Order In Preparation &#128373;',
                            'Our kitchen has started preparing your order. It will be ready for collection soon.'],
                        'ready'     => ['Your order is ready for collection', 'Order Ready &#10003;',
                            'Your order is packed and ready! Please come in to collect it.'],
                        'completed' => ['Thank you for your order', 'Order Complete &mdash; Thank You!',
                            'Thank you for ordering from Bhimas Traditional Indian Kitchen. We hope to see you again soon!'],
                        'cancelled' => ['Your order has been cancelled', 'Order Cancelled',
                            'We are sorry your order has been cancelled. Please contact us if you have any questions.'],
                    ];

                    if (isset($emailMap[$status])) {
                        [$subjectSuffix, $title, $note] = $emailMap[$status];
                        $subject = $order['order_ref'] . ': ' . $subjectSuffix;
                        // Decode items JSON for the status update email
                        $orderItems = json_decode($order['items_json'] ?? '[]', true) ?: [];
                        $html = buildOrderStatusEmail($title, $order['order_ref'],
                            $order['name'], $order['pickup_time'],
                            (float)$order['total_amount'], $orderItems, $note);
                        sendEmail($order['email'], $subject, $html);
                    }

                    $flash = 'Order ' . $order['order_ref'] . ' updated to ' . $status . '.';
                    $flashType = 'success';
                } else {
                    $flash = 'Order not found.'; $flashType = 'error';
                }
            } catch (\Throwable $e) {
                error_log('[Bhimas admin/orders] ' . $e->getMessage());
                $flash = 'Database error. Please try again.'; $flashType = 'error';
            }
        } else {
            $flash = 'Invalid data submitted.'; $flashType = 'error';
        }
    }
    $qs = http_build_query(array_filter(['msg' => $flash, 'mt' => $flashType,
        'date' => $_POST['fdate'] ?? '', 'status' => $_POST['fstatus'] ?? '',
        'page' => $_POST['fpage'] ?? '']));
    header('Location: orders.php?' . $qs);
    exit;
}

// Flash from PRG redirect
if (isset($_GET['msg'])) {
    $flash     = clean($_GET['msg'] ?? '', 200);
    $flashType = in_array($_GET['mt'] ?? '', ['success','error','info'], true) ? $_GET['mt'] : 'info';
}

// ── CSV export ─────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $fStatus = in_array($_GET['status'] ?? '', $STATUSES, true) ? $_GET['status'] : '';
    $fDate   = clean($_GET['date'] ?? '', 10);
    $where = '1=1'; $params = [];
    if ($fStatus) { $where .= ' AND status = ?'; $params[] = $fStatus; }
    if ($fDate)   { $where .= ' AND DATE(created_at) = ?'; $params[] = $fDate; }
    $rows = dbQuery(
        "SELECT order_ref, name, phone, email, pickup_time, total_amount,
                items_json, notes, status, created_at
         FROM orders WHERE {$where} ORDER BY created_at DESC",
        $params
    );
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="orders-' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Ref','Name','Phone','Email','Pickup Time','Total (EUR)','Items','Notes','Status','Created At']);
    foreach ($rows as $r) {
        $items = json_decode($r['items_json'], true) ?? [];
        $itemStr = implode('; ', array_map(
            fn($i) => ($i['qty'] ?? 1) . 'x ' . ($i['name'] ?? ''),
            $items
        ));
        fputcsv($out, [
            $r['order_ref'], $r['name'], $r['phone'], $r['email'],
            $r['pickup_time'], number_format((float)$r['total_amount'], 2),
            $itemStr, $r['notes'] ?? '', $r['status'], $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Filters + pagination ───────────────────────────────────────────────────
$fStatus = in_array($_GET['status'] ?? '', array_merge($STATUSES, ['']), true)
           ? ($_GET['status'] ?? '') : '';
$fDate   = clean($_GET['date'] ?? '', 10);
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where = '1=1'; $params = [];
if ($fStatus) { $where .= ' AND status = ?';         $params[] = $fStatus; }
if ($fDate)   { $where .= ' AND DATE(created_at) = ?'; $params[] = $fDate; }

$total  = (int)(dbQueryOne("SELECT COUNT(*) AS n FROM orders WHERE {$where}", $params)['n'] ?? 0);
$pages  = max(1, (int)ceil($total / $perPage));
$orders = dbQuery(
    "SELECT * FROM orders WHERE {$where} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$csrf    = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
$csvLink = '?' . htmlspecialchars(http_build_query(array_filter(
    ['status' => $fStatus, 'date' => $fDate, 'export' => 'csv'])), ENT_QUOTES, 'UTF-8');

function orderPageLink(array $extra, string $fStatus, string $fDate): string {
    return '?' . htmlspecialchars(http_build_query(array_filter(array_merge(
        ['status' => $fStatus, 'date' => $fDate], $extra))), ENT_QUOTES, 'UTF-8');
}

adminHead('Manage Orders', 'orders');
?>

<?php if ($flash): ?>
  <div class="adm-notice adm-notice--<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>

<!-- Filter bar -->
<form class="adm-filter-form" method="GET" action="orders.php">
  <div>
    <label>Status</label>
    <select class="adm-select" name="status">
      <option value="">All Statuses</option>
      <?php foreach ($STATUSES as $s): ?>
        <option value="<?= $s ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label>Date</label>
    <input class="adm-input" type="date" name="date"
           value="<?= htmlspecialchars($fDate, ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <button class="btn-adm btn-adm--primary" type="submit">Filter</button>
  <a href="orders.php" class="btn-adm btn-adm--outline">Reset</a>
  <a href="<?= $csvLink ?>" class="btn-adm btn-adm--gold" style="margin-left:auto;">
    &#8659; Export CSV
  </a>
</form>

<div class="adm-section">
  <div class="adm-section__head">
    <h2>Orders
      <span style="color:#999;font-size:13px;font-weight:400;">(<?= $total ?> total)</span>
    </h2>
  </div>
  <div class="adm-table-wrap">
  <table class="adm-table orders-page-table">
    <thead>
      <tr>
        <th>Ref</th>
        <th>Name / Email</th>
        <th>Phone</th>
        <th>Pickup</th>
        <th>Total</th>
        <th>Items</th>
        <th>Status</th>
        <th>Update</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($orders)): ?>
        <tr>
          <td colspan="8" style="text-align:center;padding:36px;color:#999;">
            No orders found for the selected filters.
          </td>
        </tr>
      <?php else: foreach ($orders as $o):
          $items = json_decode($o['items_json'] ?? '[]', true);
          if (!is_array($items)) $items = [];
      ?>
        <!-- Main row -->
        <tr>
          <td>
            <code style="font-size:11px;white-space:nowrap;">
              <?= htmlspecialchars($o['order_ref'], ENT_QUOTES, 'UTF-8') ?>
            </code><br>
            <small style="color:#aaa;"><?= htmlspecialchars(substr($o['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></small>
          </td>
          <td>
            <strong><?= htmlspecialchars($o['name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
            <small style="color:#888;"><?= htmlspecialchars($o['email'], ENT_QUOTES, 'UTF-8') ?></small>
          </td>
          <td><?= htmlspecialchars($o['phone'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="white-space:nowrap;font-weight:600;">
            <?= htmlspecialchars($o['pickup_time'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="white-space:nowrap;">&euro;<?= number_format((float)$o['total_amount'], 2) ?></td>
          <td>
            <?php if (!empty($items)): ?>
              <details>
                <summary style="color:var(--maroon);cursor:pointer;font-size:12px;">
                  <?= count($items) ?> item<?= count($items) !== 1 ? 's' : '' ?> &mdash; click to expand
                </summary>
                <div style="margin-top:8px;">
                  <ul class="items-list">
                    <?php foreach ($items as $item): ?>
                      <li>
                        <span><?= (int)($item['qty'] ?? 1) ?>&times;
                          <?= htmlspecialchars($item['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                        <span style="color:#555;">&euro;<?= number_format(
                          (float)($item['price'] ?? 0) * (int)($item['qty'] ?? 1), 2) ?></span>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                  <?php if (!empty($o['notes'])): ?>
                    <p style="margin-top:8px;font-size:12px;color:#666;">
                      <strong>Notes:</strong>
                      <?= htmlspecialchars($o['notes'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                  <?php endif; ?>
                </div>
              </details>
            <?php else: ?>
              <em style="color:#aaa;font-size:12px;">No items</em>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge badge--<?= htmlspecialchars($o['status'], ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($o['status'], ENT_QUOTES, 'UTF-8') ?>
            </span>
          </td>
          <td>
            <form method="POST" action="orders.php" style="display:flex;gap:5px;align-items:center;">
              <input type="hidden" name="csrf_token"  value="<?= $csrf ?>">
              <input type="hidden" name="update_order" value="1">
              <input type="hidden" name="order_id"    value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="fstatus"     value="<?= htmlspecialchars($fStatus, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="fdate"       value="<?= htmlspecialchars($fDate,   ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="fpage"       value="<?= $page ?>">
              <select class="adm-select" name="new_status"
                      style="padding:4px 6px;font-size:12px;">
                <?php foreach ($STATUSES as $s): ?>
                  <option value="<?= $s ?>"<?= $o['status'] === $s ? ' selected' : '' ?>>
                    <?= ucfirst($s) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button class="btn-adm btn-adm--primary btn-adm--sm" type="submit">Save</button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
  </div>

  <?php if ($pages > 1): ?>
  <div style="padding:14px 20px;">
    <div class="adm-pager">
      <?php if ($page > 1): ?>
        <a href="<?= orderPageLink(['page' => $page - 1], $fStatus, $fDate) ?>">&laquo; Prev</a>
      <?php endif; ?>
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <?php if ($p === $page): ?>
          <span class="current"><?= $p ?></span>
        <?php else: ?>
          <a href="<?= orderPageLink(['page' => $p], $fStatus, $fDate) ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $pages): ?>
        <a href="<?= orderPageLink(['page' => $page + 1], $fStatus, $fDate) ?>">Next &raquo;</a>
      <?php endif; ?>
      <span style="margin-left:8px;color:#999;font-size:12px;">
        Page <?= $page ?> of <?= $pages ?>
      </span>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php adminFoot(); ?>
