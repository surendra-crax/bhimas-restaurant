<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * admin/login.php — Password-only admin login
 */
define('BHIMAS_LOADED', true);
require_once dirname(__DIR__) . '/php/config.php';

// Redirect if already authenticated
if (!empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request token. Please refresh and try again.';
    } else {
        $pw      = $_POST['password'] ?? '';
        $adminPw = defined('ADMIN_PASSWORD') ? ADMIN_PASSWORD : '';
        if ($adminPw !== '' && hash_equals($adminPw, $pw)) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            header('Location: index.php');
            exit;
        }
        // Slight delay to slow brute-force
        usleep(500000);
        $error = 'Incorrect password. Please try again.';
    }
}
$csrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin Login &mdash; Bhimas Kitchen</title>
  <link rel="stylesheet" href="admin.css?v=10005">
</head>
<body>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">
      <h1>&#127859; Bhimas Kitchen</h1>
      <p>Restaurant Admin Panel</p>
    </div>
    <?php if ($error): ?>
      <div class="adm-notice adm-notice--error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <form method="POST" action="login.php">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
      <div style="margin-bottom:18px;">
        <label class="adm-label" for="password">Admin Password</label>
        <input class="adm-input" style="width:100%;" type="password"
               id="password" name="password" placeholder="Enter admin password"
               required autofocus autocomplete="current-password">
      </div>
      <button class="btn-adm btn-adm--primary" style="width:100%;padding:11px;" type="submit">
        Sign In &rarr;
      </button>
    </form>
    <p style="margin-top:20px;text-align:center;">
      <a href="../index.html" style="color:#888;font-size:12px;">&larr; Back to website</a>
    </p>
  </div>
</div>
</body>
</html>
