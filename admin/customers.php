<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * admin/customers.php — Manage registered customer accounts
 */
define('BHIMAS_LOADED', true);
require_once dirname(__DIR__) . '/php/config.php';
require_once __DIR__ . '/_helpers.php';
adminGuard();

// Temporary redirect for first deployment (hide customer management)
header('Location: index.php');
exit;

$flash = $flashType = '';
$csrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');

// ── Handle CRUD Requests (POST + PRG) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid security token. Please refresh and try again.';
        $flashType = 'error';
    } else {
        $action = clean($_POST['action'] ?? '', 32);

        // 1. ADD CUSTOMER
        if ($action === 'add') {
            $name     = clean($_POST['name'] ?? '', 100);
            $email    = cleanEmail($_POST['email'] ?? '');
            $phone    = clean($_POST['phone'] ?? '', 30);
            $password = $_POST['password'] ?? '';

            if (empty($name) || empty($email) || empty($password)) {
                $flash = 'Name, email, and password are required.';
                $flashType = 'error';
            } elseif (strlen($password) < 6) {
                $flash = 'Password must be at least 6 characters.';
                $flashType = 'error';
            } else {
                try {
                    // Check if email already registered
                    $exists = dbQueryOne("SELECT id FROM customers WHERE email = :email", [':email' => $email]);
                    if ($exists) {
                        $flash = 'Email is already registered.';
                        $flashType = 'error';
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        dbExec(
                            "INSERT INTO customers (name, email, phone, password_hash) 
                             VALUES (:name, :email, :phone, :hash)",
                            [
                                ':name'  => $name,
                                ':email' => $email,
                                ':phone' => $phone ?: null,
                                ':hash'  => $hash
                            ]
                        );
                        $flash = 'Customer added successfully.';
                        $flashType = 'success';
                    }
                } catch (\Throwable $e) {
                    error_log('[Bhimas admin/customers add] ' . $e->getMessage());
                    $flash = 'Database error. Please try again.';
                    $flashType = 'error';
                }
            }
        }

        // 2. CHANGE PASSWORD
        elseif ($action === 'change_password') {
            $cid      = cleanInt($_POST['customer_id'] ?? '', 1);
            $password = $_POST['password'] ?? '';

            if (!$cid || empty($password)) {
                $flash = 'Customer ID and new password are required.';
                $flashType = 'error';
            } elseif (strlen($password) < 6) {
                $flash = 'Password must be at least 6 characters.';
                $flashType = 'error';
            } else {
                try {
                    $user = dbQueryOne("SELECT name FROM customers WHERE id = :id", [':id' => $cid]);
                    if (!$user) {
                        $flash = 'Customer not found.';
                        $flashType = 'error';
                    } else {
                        $hash = password_hash($password, PASSWORD_DEFAULT);
                        dbExec("UPDATE customers SET password_hash = :hash WHERE id = :id", [
                            ':hash' => $hash,
                            ':id'   => $cid
                        ]);
                        $flash = 'Password for ' . $user['name'] . ' updated successfully.';
                        $flashType = 'success';
                    }
                } catch (\Throwable $e) {
                    error_log('[Bhimas admin/customers change_pwd] ' . $e->getMessage());
                    $flash = 'Database error. Please try again.';
                    $flashType = 'error';
                }
            }
        }

        // 3. DELETE CUSTOMER
        elseif ($action === 'delete') {
            $cid = cleanInt($_POST['customer_id'] ?? '', 1);

            if ($cid) {
                try {
                    $user = dbQueryOne("SELECT name, email FROM customers WHERE id = :id", [':id' => $cid]);
                    if ($user) {
                        // Orphan orders and bookings (they remain in database for accounting but customer_id becomes NULL)
                        dbExec("UPDATE orders SET customer_id = NULL WHERE customer_id = :id", [':id' => $cid]);
                        dbExec("UPDATE bookings SET customer_id = NULL WHERE customer_id = :id", [':id' => $cid]);
                        dbExec("DELETE FROM password_resets WHERE email = :email", [':email' => $user['email']]);
                        
                        // Delete customer record
                        dbExec("DELETE FROM customers WHERE id = :id", [':id' => $cid]);

                        // Log out the customer if deleting the currently active customer session (local test helper)
                        if (isset($_SESSION['customer_id']) && (int)$_SESSION['customer_id'] === (int)$cid) {
                            unset($_SESSION['customer_logged_in']);
                            unset($_SESSION['customer_id']);
                            unset($_SESSION['customer_name']);
                            unset($_SESSION['customer_email']);
                        }

                        $flash = 'Customer account ' . $user['name'] . ' has been deleted.';
                        $flashType = 'success';
                    } else {
                        $flash = 'Customer not found.';
                        $flashType = 'error';
                    }
                } catch (\Throwable $e) {
                    error_log('[Bhimas admin/customers delete] ' . $e->getMessage());
                    $flash = 'Database error. Please try again.';
                    $flashType = 'error';
                }
            } else {
                $flash = 'Invalid customer ID.';
                $flashType = 'error';
            }
        }
    }
    
    // PRG Redirect
    $qs = http_build_query(array_filter([
        'msg' => $flash,
        'mt'  => $flashType,
        'q'   => $_POST['fq'] ?? '',
        'page'=> $_POST['fpage'] ?? ''
    ]));
    header('Location: customers.php?' . $qs);
    exit;
}

// ── Read flash messages from GET ───────────────────────────────────────────
if (isset($_GET['msg'])) {
    $flash     = clean($_GET['msg'] ?? '', 200);
    $flashType = in_array($_GET['mt'] ?? '', ['success','error','info'], true) ? $_GET['mt'] : 'info';
}

// ── Search & Pagination parameters ─────────────────────────────────────────
$search  = clean($_GET['q'] ?? '', 100);
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where = '1=1';
$params = [];
if ($search) {
    $where .= ' AND (name LIKE :search OR email LIKE :search OR phone LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

try {
    // Count total customers matching query
    $total = (int) (dbQueryOne("SELECT COUNT(*) AS n FROM customers WHERE {$where}", $params)['n'] ?? 0);
    $pages = max(1, (int) ceil($total / $perPage));

    // Fetch matching customers
    $customers = dbQuery(
        "SELECT id, name, email, phone, created_at 
         FROM customers 
         WHERE {$where} 
         ORDER BY created_at DESC 
         LIMIT {$perPage} OFFSET {$offset}",
        $params
    );
} catch (\Throwable $e) {
    error_log('[Bhimas admin/customers list] ' . $e->getMessage());
    $customers = [];
    $total = 0;
    $pages = 1;
}

function pageLink(array $extra, string $search): string {
    return '?' . htmlspecialchars(http_build_query(array_filter(array_merge(
        ['q' => $search], $extra))), ENT_QUOTES, 'UTF-8');
}

adminHead('Customer Management', 'customers');
?>

<?php if ($flash): ?>
  <div class="adm-notice adm-notice--<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>

<!-- Two-Column Layout -->
<div style="display: grid; grid-template-columns: 320px 1fr; gap: 32px; align-items: start;">

  <!-- LEFT: ACTION CARDS -->
  <div style="display: flex; flex-direction: column; gap: 24px;">
    
    <!-- 1. ADD NEW CUSTOMER -->
    <div class="adm-section" style="margin-bottom: 0;">
      <div class="adm-section__head">
        <h2>Add Customer</h2>
      </div>
      <div class="adm-section__body">
        <form method="POST" action="customers.php">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="fq" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="fpage" value="<?= $page ?>">

          <div style="margin-bottom: 12px;">
            <label class="adm-label">Full Name</label>
            <input class="adm-input" type="text" name="name" required style="width: 100%;">
          </div>
          <div style="margin-bottom: 12px;">
            <label class="adm-label">Email Address</label>
            <input class="adm-input" type="email" name="email" required style="width: 100%;">
          </div>
          <div style="margin-bottom: 12px;">
            <label class="adm-label">Phone (Optional)</label>
            <input class="adm-input" type="tel" name="phone" style="width: 100%;">
          </div>
          <div style="margin-bottom: 16px;">
            <label class="adm-label">Password</label>
            <input class="adm-input" type="password" name="password" required minlength="6" style="width: 100%;">
          </div>
          <button class="btn-adm btn-adm--primary" type="submit" style="width: 100%;">Create Account</button>
        </form>
      </div>
    </div>

    <!-- 2. CHANGE PASSWORD (POPULATED BY JS) -->
    <div class="adm-section" id="change-pwd-card" style="display: none; border-color: var(--gold);">
      <div class="adm-section__head" style="border-bottom-color: rgba(201, 168, 76, 0.2);">
        <h2 id="change-pwd-title" style="color: var(--dark-brown);">Change Password</h2>
      </div>
      <div class="adm-section__body">
        <form method="POST" action="customers.php">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="change_password">
          <input type="hidden" name="customer_id" id="change-pwd-id">
          <input type="hidden" name="fq" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="fpage" value="<?= $page ?>">

          <div style="margin-bottom: 16px;">
            <label class="adm-label">New Password</label>
            <input class="adm-input" type="password" name="password" required minlength="6" style="width: 100%; border-color: var(--gold-dark);">
          </div>
          <div style="display: flex; gap: 8px;">
            <button class="btn-adm btn-adm--gold" type="submit" style="flex: 1;">Update Password</button>
            <button class="btn-adm btn-adm--outline" type="button" onclick="cancelChangePwd()" style="flex: 1;">Cancel</button>
          </div>
        </form>
      </div>
    </div>

  </div>

  <!-- RIGHT: CUSTOMER LIST TABLE -->
  <div>
    <!-- Search Bar -->
    <form class="adm-filter-form" method="GET" action="customers.php" style="margin-bottom: 24px;">
      <div style="flex: 1;">
        <label>Search Customers</label>
        <input class="adm-input" type="text" name="q" placeholder="Search by name, email, or phone..."
               value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" style="width: 100%;">
      </div>
      <button class="btn-adm btn-adm--primary" type="submit">Search</button>
      <a href="customers.php" class="btn-adm btn-adm--outline">Reset</a>
    </form>

    <div class="adm-section">
      <div class="adm-section__head">
        <h2>Registered Customers
          <span style="color:#999;font-size:13px;font-weight:400;">(<?= $total ?> total)</span>
        </h2>
      </div>
      
      <div class="adm-table-wrap">
      <table class="adm-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Email</th>
            <th>Phone</th>
            <th>Registered At</th>
            <th style="text-align: right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($customers)): ?>
            <tr>
              <td colspan="6" style="text-align:center;padding:36px;color:#999;">
                No customer accounts found.
              </td>
            </tr>
          <?php else: foreach ($customers as $c): ?>
            <tr>
              <td><?= (int)$c['id'] ?></td>
              <td><strong><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
              <td><code><?= htmlspecialchars($c['email'], ENT_QUOTES, 'UTF-8') ?></code></td>
              <td><?= htmlspecialchars($c['phone'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
              <td><small style="color:#888;"><?= htmlspecialchars(date('d M Y, H:i', strtotime($c['created_at'])), ENT_QUOTES, 'UTF-8') ?></small></td>
              <td style="text-align: right;">
                <div style="display: flex; gap: 6px; justify-content: flex-end; align-items: center;">
                  
                  <button class="btn-adm btn-adm--outline btn-adm--sm" type="button" 
                          data-id="<?= (int)$c['id'] ?>"
                          data-name="<?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>"
                          onclick="showChangePwd(this)">
                    Password
                  </button>
 
                  <form method="POST" action="customers.php" 
                        data-name="<?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?>"
                        onsubmit="return confirmDeleteCustomer(this);" 
                        style="margin: 0;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="customer_id" value="<?= (int)$c['id'] ?>">
                    <input type="hidden" name="fq" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="fpage" value="<?= $page ?>">
                    <button class="btn-adm btn-adm--danger btn-adm--sm" type="submit">Delete</button>
                  </form>
 
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      </div>

      <!-- Pager -->
      <?php if ($pages > 1): ?>
      <div style="padding:14px 20px; border-top: 1px solid var(--color-border);">
        <div class="adm-pager">
          <?php if ($page > 1): ?>
            <a href="<?= pageLink(['page' => $page - 1], $search) ?>">&laquo; Prev</a>
          <?php endif; ?>
          <?php for ($p = 1; $p <= $pages; $p++): ?>
            <?php if ($p === $page): ?>
              <span class="current"><?= $p ?></span>
            <?php else: ?>
              <a href="<?= pageLink(['page' => $p], $search) ?>"><?= $p ?></a>
            <?php endif; ?>
          <?php endfor; ?>
          <?php if ($page < $pages): ?>
            <a href="<?= pageLink(['page' => $page + 1], $search) ?>">Next &raquo;</a>
          <?php endif; ?>
          <span style="margin-left:8px;color:#999;font-size:12px;">
            Page <?= $page ?> of <?= $pages ?>
          </span>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
  function showChangePwd(btn) {
    var id = btn.getAttribute('data-id');
    var name = btn.getAttribute('data-name');
    document.getElementById('change-pwd-id').value = id;
    document.getElementById('change-pwd-title').textContent = 'Password: ' + name;
    document.getElementById('change-pwd-card').style.display = 'block';
    
    // Scroll action card into view on small viewports
    document.getElementById('change-pwd-card').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function cancelChangePwd() {
    document.getElementById('change-pwd-card').style.display = 'none';
    document.getElementById('change-pwd-id').value = '';
  }

  function confirmDeleteCustomer(form) {
    var name = form.getAttribute('data-name') || 'this customer';
    return confirm('Are you sure you want to delete the customer account "' + name + '"? This will preserve their order/booking records under empty accounts.');
  }
</script>

<?php adminFoot(); ?>
