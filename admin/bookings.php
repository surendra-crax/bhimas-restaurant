<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * admin/bookings.php — Manage table reservations
 *
 * GET  ?date=&status=&page=   — filtered + paginated list
 * GET  ?export=csv            — download CSV of current filter
 * POST update_booking         — change status of one booking
 */
define('BHIMAS_LOADED', true);
require_once dirname(__DIR__) . '/php/config.php';
require_once __DIR__ . '/_helpers.php';
adminGuard();

$STATUSES = ['pending', 'confirmed', 'cancelled'];

// ── Handle status update (POST + PRG) ─────────────────────────────────────
$flash = $flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_booking'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid security token. Please refresh and try again.';
        $flashType = 'error';
    } else {
        $id     = cleanInt($_POST['booking_id'] ?? '', 1);
        $status = in_array($_POST['new_status'] ?? '', $STATUSES, true) ? $_POST['new_status'] : null;

        if ($id && $status) {
            try {
                $booking = dbQueryOne('SELECT * FROM bookings WHERE id = ?', [$id]);
                if ($booking) {
                    dbExec('UPDATE bookings SET status = ? WHERE id = ?', [$status, $id]);

                    // Email customer on confirmed or cancelled
                    if (in_array($status, ['confirmed', 'cancelled'], true)) {
                        $subject = ($status === 'confirmed')
                            ? 'Your booking ' . $booking['booking_ref'] . ' is confirmed'
                            : 'Your booking ' . $booking['booking_ref'] . ' has been cancelled';
                        $title   = ($status === 'confirmed') ? 'Booking Confirmed' : 'Booking Cancelled';
                        $note    = ($status === 'confirmed')
                            ? 'We look forward to welcoming you! Please call us if you need to make any changes.'
                            : 'We are sorry for any inconvenience. Please contact us to arrange another visit.';
                        $html = buildReservationEmail(
                            $title,
                            $booking['booking_ref'],
                            $booking['name'], '', $booking['email'],
                            $booking['date'], $booking['time'],
                            (int)$booking['guests'],
                            $booking['special_requests'] ?? '',
                            $note,
                            false
                        );
                        sendEmail($booking['email'], $subject, $html);
                    }
                    $flash = 'Booking #' . $booking['booking_ref'] . ' updated to ' . $status . '.';
                    $flashType = 'success';
                } else {
                    $flash = 'Booking not found.'; $flashType = 'error';
                }
            } catch (\Throwable $e) {
                error_log('[Bhimas admin/bookings] ' . $e->getMessage());
                $flash = 'Database error. Please try again.'; $flashType = 'error';
            }
        } else {
            $flash = 'Invalid data submitted.'; $flashType = 'error';
        }
    }
    $qs = http_build_query(array_filter(['msg' => $flash, 'mt' => $flashType,
        'date' => $_POST['fdate'] ?? '', 'status' => $_POST['fstatus'] ?? '',
        'page' => $_POST['fpage']  ?? '']));
    header('Location: bookings.php?' . $qs);
    exit;
}

// Pick up flash from PRG redirect
if (isset($_GET['msg'])) {
    $flash     = clean($_GET['msg'] ?? '', 200);
    $flashType = in_array($_GET['mt'] ?? '', ['success','error','info'], true) ? $_GET['mt'] : 'info';
}

// ── CSV export ─────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $fDate   = clean($_GET['date']   ?? '', 10);
    $fStatus = in_array($_GET['status'] ?? '', $STATUSES, true) ? $_GET['status'] : '';
    $where = '1=1'; $params = [];
    if ($fDate)   { $where .= ' AND date = ?';   $params[] = $fDate; }
    if ($fStatus) { $where .= ' AND status = ?'; $params[] = $fStatus; }
    $rows = dbQuery(
        "SELECT booking_ref, name, phone, email, date, time, guests,
                special_requests, status, created_at
         FROM bookings WHERE {$where} ORDER BY date DESC, time ASC",
        $params
    );
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="bookings-' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Ref','Name','Phone','Email','Date','Time','Guests','Special Requests','Status','Created At']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['booking_ref'], $r['name'], $r['phone'], $r['email'],
            $r['date'], $r['time'], $r['guests'],
            $r['special_requests'] ?? '', $r['status'], $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

// ── Filters + pagination ───────────────────────────────────────────────────
$fDate   = clean($_GET['date']   ?? '', 10);
$fStatus = in_array($_GET['status'] ?? '', array_merge($STATUSES, ['']), true)
           ? ($_GET['status'] ?? '') : '';
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where = '1=1'; $params = [];
if ($fDate)   { $where .= ' AND date = ?';   $params[] = $fDate; }
if ($fStatus) { $where .= ' AND status = ?'; $params[] = $fStatus; }

$total    = (int)(dbQueryOne("SELECT COUNT(*) AS n FROM bookings WHERE {$where}", $params)['n'] ?? 0);
$pages    = max(1, (int)ceil($total / $perPage));
$bookings = dbQuery(
    "SELECT * FROM bookings WHERE {$where} ORDER BY date DESC, time ASC LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$csrf    = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
$csvLink = '?' . htmlspecialchars(http_build_query(array_filter(
    ['date' => $fDate, 'status' => $fStatus, 'export' => 'csv'])), ENT_QUOTES, 'UTF-8');

function pageLink(array $extra, string $fDate, string $fStatus): string {
    return '?' . htmlspecialchars(http_build_query(array_filter(array_merge(
        ['date' => $fDate, 'status' => $fStatus], $extra))), ENT_QUOTES, 'UTF-8');
}

adminHead('Manage Bookings', 'bookings');
?>

<?php if ($flash): ?>
  <div class="adm-notice adm-notice--<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>

<!-- Filter bar -->
<form class="adm-filter-form" method="GET" action="bookings.php">
  <div>
    <label>Date</label>
    <input class="adm-input" type="date" name="date"
           value="<?= htmlspecialchars($fDate, ENT_QUOTES, 'UTF-8') ?>">
  </div>
  <div>
    <label>Status</label>
    <select class="adm-select" name="status">
      <option value="">All Statuses</option>
      <?php foreach (['pending','confirmed','cancelled'] as $s): ?>
        <option value="<?= $s ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= ucfirst($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <button class="btn-adm btn-adm--primary" type="submit">Filter</button>
  <a href="bookings.php" class="btn-adm btn-adm--outline">Reset</a>
  <a href="<?= $csvLink ?>" class="btn-adm btn-adm--gold" style="margin-left:auto;">
    &#8659; Export CSV
  </a>
</form>

<div class="adm-section">
  <div class="adm-section__head">
    <h2>Bookings
      <span style="color:#999;font-size:13px;font-weight:400;">(<?= $total ?> total)</span>
    </h2>
  </div>
  <div class="adm-table-wrap">
  <table class="adm-table bookings-page-table">
    <thead>
      <tr>
        <th>Ref</th>
        <th>Name / Email</th>
        <th>Phone</th>
        <th>Date</th>
        <th>Time</th>
        <th>Guests</th>
        <th>Special Requests</th>
        <th>Status</th>
        <th>Update</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($bookings)): ?>
        <tr>
          <td colspan="9" style="text-align:center;padding:36px;color:#999;">
            No bookings found for the selected filters.
          </td>
        </tr>
      <?php else: foreach ($bookings as $b): ?>
        <tr>
          <td>
            <code style="font-size:11px;white-space:nowrap;">
              <?= htmlspecialchars($b['booking_ref'], ENT_QUOTES, 'UTF-8') ?>
            </code><br>
            <small style="color:#aaa;"><?= htmlspecialchars(substr($b['created_at'], 0, 10), ENT_QUOTES, 'UTF-8') ?></small>
          </td>
          <td>
            <strong><?= htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
            <small style="color:#888;"><?= htmlspecialchars($b['email'], ENT_QUOTES, 'UTF-8') ?></small>
          </td>
          <td><?= htmlspecialchars($b['phone'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="white-space:nowrap;"><?= htmlspecialchars($b['date'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="white-space:nowrap;"><?= htmlspecialchars($b['time'], ENT_QUOTES, 'UTF-8') ?></td>
          <td style="text-align:center;"><?= (int)$b['guests'] ?></td>
          <td style="max-width:180px;font-size:12px;color:#555;">
            <?php
              $sr = $b['special_requests'] ?? '';
              echo htmlspecialchars(mb_substr($sr, 0, 80), ENT_QUOTES, 'UTF-8');
              if (mb_strlen($sr) > 80) echo '&hellip;';
            ?>
          </td>
          <td>
            <span class="badge badge--<?= htmlspecialchars($b['status'], ENT_QUOTES, 'UTF-8') ?>">
              <?= htmlspecialchars($b['status'], ENT_QUOTES, 'UTF-8') ?>
            </span>
          </td>
          <td>
            <form method="POST" action="bookings.php" style="display:flex;gap:5px;align-items:center;">
              <input type="hidden" name="csrf_token"   value="<?= $csrf ?>">
              <input type="hidden" name="update_booking" value="1">
              <input type="hidden" name="booking_id"   value="<?= (int)$b['id'] ?>">
              <input type="hidden" name="fdate"        value="<?= htmlspecialchars($fDate,   ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="fstatus"      value="<?= htmlspecialchars($fStatus, ENT_QUOTES, 'UTF-8') ?>">
              <input type="hidden" name="fpage"        value="<?= $page ?>">
              <select class="adm-select" name="new_status"
                      style="padding:4px 6px;font-size:12px;">
                <?php foreach ($STATUSES as $s): ?>
                  <option value="<?= $s ?>"<?= $b['status'] === $s ? ' selected' : '' ?>>
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
        <a href="<?= pageLink(['page' => $page - 1], $fDate, $fStatus) ?>">&laquo; Prev</a>
      <?php endif; ?>
      <?php for ($p = 1; $p <= $pages; $p++): ?>
        <?php if ($p === $page): ?>
          <span class="current"><?= $p ?></span>
        <?php else: ?>
          <a href="<?= pageLink(['page' => $p], $fDate, $fStatus) ?>"><?= $p ?></a>
        <?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $pages): ?>
        <a href="<?= pageLink(['page' => $page + 1], $fDate, $fStatus) ?>">Next &raquo;</a>
      <?php endif; ?>
      <span style="margin-left:8px;color:#999;font-size:12px;">
        Page <?= $page ?> of <?= $pages ?>
      </span>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php adminFoot(); ?>
