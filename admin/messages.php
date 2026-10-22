<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * admin/messages.php — View and manage contact form messages
 *
 * GET  ?filter=unread|read|all  — filter list
 * POST action=read  &id=N       — mark message as read
 * POST action=delete&id=N       — delete message
 */
define('BHIMAS_LOADED', true);
require_once dirname(__DIR__) . '/php/config.php';
require_once __DIR__ . '/_helpers.php';
adminGuard();

// ── Handle POST actions (PRG) ──────────────────────────────────────────────
$flash = $flashType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid security token.'; $flashType = 'error';
    } else {
        $id     = cleanInt($_POST['msg_id'] ?? '', 1);
        $action = in_array($_POST['action'] ?? '', ['read', 'delete'], true)
                  ? $_POST['action'] : null;
        if ($id && $action) {
            try {
                if ($action === 'read') {
                    dbExec('UPDATE messages SET is_read = 1 WHERE id = ?', [$id]);
                    $flash = 'Message marked as read.'; $flashType = 'success';
                } elseif ($action === 'delete') {
                    dbExec('DELETE FROM messages WHERE id = ?', [$id]);
                    $flash = 'Message deleted.'; $flashType = 'success';
                }
            } catch (\Throwable $e) {
                error_log('[Bhimas admin/messages] ' . $e->getMessage());
                $flash = 'Database error. Please try again.'; $flashType = 'error';
            }
        } else {
            $flash = 'Invalid request.'; $flashType = 'error';
        }
    }
    $qs = http_build_query(array_filter(['msg' => $flash, 'mt' => $flashType,
        'filter' => $_POST['ffilter'] ?? '']));
    header('Location: messages.php?' . $qs);
    exit;
}

// Flash from redirect
if (isset($_GET['msg'])) {
    $flash     = clean($_GET['msg'] ?? '', 200);
    $flashType = in_array($_GET['mt'] ?? '', ['success','error','info'], true) ? $_GET['mt'] : 'info';
}

// ── Filter + fetch ─────────────────────────────────────────────────────────
$filter = in_array($_GET['filter'] ?? '', ['unread','read','all'], true)
          ? ($_GET['filter'] ?? 'all') : 'all';
$where  = '1=1'; $params = [];
if ($filter === 'unread') { $where = 'is_read = 0'; }
if ($filter === 'read')   { $where = 'is_read = 1'; }

$messages = dbQuery(
    "SELECT * FROM messages WHERE {$where} ORDER BY created_at DESC",
    $params
);
$total    = count($messages);
$unread   = (int)(dbQueryOne('SELECT COUNT(*) AS n FROM messages WHERE is_read = 0')['n'] ?? 0);

$csrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
adminHead('Messages', 'messages');
?>

<?php if ($flash): ?>
  <div class="adm-notice adm-notice--<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>

<!-- Filter tabs -->
<div style="display:flex;gap:8px;margin-bottom:20px;align-items:center;">
  <a href="?filter=all"
     class="btn-adm <?= $filter === 'all'    ? 'btn-adm--primary' : 'btn-adm--outline' ?>">
    All (<?= $total ?>)
  </a>
  <a href="?filter=unread"
     class="btn-adm <?= $filter === 'unread' ? 'btn-adm--primary' : 'btn-adm--outline' ?>">
    Unread (<?= $unread ?>)
  </a>
  <a href="?filter=read"
     class="btn-adm <?= $filter === 'read'   ? 'btn-adm--primary' : 'btn-adm--outline' ?>">
    Read
  </a>
</div>

<div class="adm-section">
  <div class="adm-section__head">
    <h2>Contact Messages</h2>
    <?php if ($unread > 0): ?>
      <span class="badge badge--pending"><?= $unread ?> unread</span>
    <?php endif; ?>
  </div>

  <?php if (empty($messages)): ?>
    <div style="padding:48px;text-align:center;color:#999;">
      No messages found.
    </div>
  <?php else: ?>
  <div class="adm-table-wrap">
  <table class="adm-table">
    <thead>
      <tr>
        <th>From</th>
        <th>Subject</th>
        <th>Message</th>
        <th>Date</th>
        <th style="white-space:nowrap;">Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($messages as $m):
          $isUnread = !(bool)$m['is_read'];
          $rowClass = $isUnread ? ' class="unread-row"' : '';
      ?>
        <!-- Summary row -->
        <tr<?= $rowClass ?>>
          <td>
            <strong><?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?></strong><br>
            <small>
              <a href="mailto:<?= htmlspecialchars($m['email'], ENT_QUOTES, 'UTF-8') ?>"
                 style="color:var(--maroon);">
                <?= htmlspecialchars($m['email'], ENT_QUOTES, 'UTF-8') ?>
              </a>
            </small>
          </td>
          <td>
            <?php if ($isUnread): ?>
              <span style="display:inline-block;width:7px;height:7px;border-radius:50%;
                           background:var(--maroon);margin-right:5px;vertical-align:middle;"></span>
            <?php endif; ?>
            <?= htmlspecialchars($m['subject'], ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td style="max-width:340px;">
            <details>
              <summary style="cursor:pointer;color:#555;font-size:13px;">
                <?= htmlspecialchars(mb_substr($m['message'], 0, 90), ENT_QUOTES, 'UTF-8') ?>
                <?= mb_strlen($m['message']) > 90 ? '&hellip; <em style="color:var(--maroon);font-size:12px;">(read more)</em>' : '' ?>
              </summary>
              <div class="expand-inner" style="margin-top:8px;background:#f9f6f0;
                           border-radius:5px;padding:12px 14px;font-size:13px;line-height:1.7;
                           white-space:pre-wrap;word-break:break-word;">
                <?= htmlspecialchars($m['message'], ENT_QUOTES, 'UTF-8') ?>
              </div>
            </details>
          </td>
          <td style="white-space:nowrap;color:#888;font-size:12px;">
            <?= htmlspecialchars(substr($m['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?>
          </td>
          <td>
            <div style="display:flex;gap:5px;flex-wrap:wrap;">
              <?php if ($isUnread): ?>
                <form method="POST" action="messages.php">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                  <input type="hidden" name="msg_id"    value="<?= (int)$m['id'] ?>">
                  <input type="hidden" name="action"    value="read">
                  <input type="hidden" name="ffilter"   value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>">
                  <button class="btn-adm btn-adm--outline btn-adm--sm" type="submit"
                          title="Mark as read">&#10003; Read</button>
                </form>
              <?php endif; ?>
              <form method="POST" action="messages.php"
                    onsubmit="return confirm('Delete this message from <?= htmlspecialchars(addslashes($m['name']), ENT_QUOTES, 'UTF-8') ?>? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="msg_id"    value="<?= (int)$m['id'] ?>">
                <input type="hidden" name="action"    value="delete">
                <input type="hidden" name="ffilter"   value="<?= htmlspecialchars($filter, ENT_QUOTES, 'UTF-8') ?>">
                <button class="btn-adm btn-adm--danger btn-adm--sm" type="submit"
                        title="Delete message">&#128465; Delete</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php adminFoot(); ?>
