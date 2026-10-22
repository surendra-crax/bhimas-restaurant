<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * admin/_helpers.php — Auth guard, shared layout & email helpers
 *
 * Every admin page: require_once __DIR__ . '/_helpers.php';
 */
if (!defined('BHIMAS_LOADED')) { http_response_code(403); exit; }

// ── Auth guard ─────────────────────────────────────────────────────────────
function adminGuard(): void
{
    if (empty($_SESSION['admin_logged_in'])) {
        header('Location: login.php');
        exit;
    }
}

// ── Admin layout: open ─────────────────────────────────────────────────────
function adminHead(string $pageTitle, string $active = ''): void
{
    $csrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
    $pt   = htmlspecialchars($pageTitle,  ENT_QUOTES, 'UTF-8');
    $pages = [
        'index'     => ['&#8862;&nbsp; Dashboard', 'index.php'],
        'bookings'  => ['&#128197;&nbsp; Bookings', 'bookings.php'],
        'orders'    => ['&#129534;&nbsp; Orders',   'orders.php'],
        // 'customers' => ['&#128101;&nbsp; Customers', 'customers.php'], // Hidden for first deployment
        // 'messages'  => ['&#9993;&nbsp; Messages',   'messages.php'],
        'settings'  => ['&#9881;&nbsp; Settings',   'settings.php'],
    ];
    $nav = '';
    foreach ($pages as $key => [$label, $href]) {
        $cls  = ($key === $active) ? ' class="active"' : '';
        $nav .= '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"' . $cls . '>' . $label . "</a>\n      ";
    }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= $pt ?> &mdash; Bhimas Admin</title>
  <link rel="stylesheet" href="admin.css?v=10005">
  <meta name="csrf-token" content="<?= $csrf ?>">
</head>
<body>
<div class="adm-wrap">
  <aside class="adm-sidebar">
    <div class="adm-sidebar__logo">
      <span class="adm-logo-icon">&#127859;</span>
      <span>Bhimas<br><small>Admin Panel</small></span>
    </div>
    <nav class="adm-sidebar__nav">
      <?= $nav ?>
      <a href="logout.php" class="adm-sidebar__logout">&#10155;&nbsp; Logout</a>
    </nav>
  </aside>
  <div class="adm-body">
    <header class="adm-topbar">
      <h1 class="adm-topbar__title"><?= $pt ?></h1>
      <span class="adm-topbar__user">&#9679; Logged in</span>
    </header>
    <main class="adm-content">
<?php
}

// ── Admin layout: close ────────────────────────────────────────────────────
function adminFoot(): void
{
?>
    </main>
  </div>
</div>
</body>
</html>
<?php
}

// buildEmailHtml(), sendEmail() are defined in php/config.php (loaded via config.php)

// ── Time-slot options helper (for settings hours table) ───────────────────
function timeOptions(string $selected, string $first = ''): string
{
    $html = $first
        ? '<option value=""' . ($selected === '' ? ' selected' : '') . '>' . htmlspecialchars($first, ENT_QUOTES, 'UTF-8') . '</option>'
        : '';
    for ($h = 7; $h < 24; $h++) {
        foreach ([0, 30] as $m) {
            $t    = sprintf('%02d:%02d', $h, $m);
            $sel  = ($t === $selected) ? ' selected' : '';
            $html .= '<option value="' . $t . '"' . $sel . '>' . $t . '</option>';
        }
    }
    return $html;
}
