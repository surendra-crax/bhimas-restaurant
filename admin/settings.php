<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * admin/settings.php — Manage site settings
 *
 * Sections:
 *   1. Availability toggles (reservation_enabled, takeaway_enabled)
 *   2. Per-day opening hours (hours_sun … hours_sat)
 *   3. Takeaway settings (min_takeaway_minutes)
 *   4. Contact details
 *   5. Hero Slider Manager (3 slides with per-slide upload + gallery)
 */
define('BHIMAS_LOADED', true);
require_once dirname(__DIR__) . '/php/config.php';
require_once __DIR__ . '/_helpers.php';
adminGuard();

$DAY_KEYS   = ['mon','tue','wed','thu','fri','sat','sun'];
$DAY_LABELS = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'];

$UPDATABLE_KEYS = [
    'reservation_enabled', 'takeaway_enabled',
    'hours_mon','hours_tue','hours_wed','hours_thu','hours_fri','hours_sat','hours_sun',
    'min_takeaway_minutes',
    'restaurant_name','phone','email','address_line1','address_line2','city','postcode',
];

$flash = $flashType = '';

// ── Handle per-slide image upload ─────────────────────────────────────────
// Forms in the slide cards post: upload_to_slide=N  +  hero_image file
$uploadMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['upload_to_slide'])
    && !empty($_FILES['hero_image']['name'])
) {
    $slideTarget = (int)($_POST['upload_to_slide']);

    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $uploadMsg = 'error:Invalid security token.';
    } elseif ($slideTarget < 1 || $slideTarget > 3) {
        $uploadMsg = 'error:Invalid slide number.';
    } elseif ($_FILES['hero_image']['error'] !== UPLOAD_ERR_OK) {
        $codes = [1=>'File too large (server limit)',2=>'File too large',
                  3=>'Partial upload',4=>'No file',6=>'No temp folder',
                  7=>'Cannot write to disk',8=>'Extension blocked'];
        $uploadMsg = 'error:Upload error: ' . ($codes[$_FILES['hero_image']['error']] ?? 'Unknown');
    } elseif ($_FILES['hero_image']['size'] > 5 * 1024 * 1024) {
        $uploadMsg = 'error:File too large. Maximum size is 5 MB.';
    } else {
        // Robust MIME detection
        $mime = '';
        $imgInfo = @getimagesize($_FILES['hero_image']['tmp_name']);
        if ($imgInfo) {
            $mime   = $imgInfo['mime'] ?? '';
            $width  = $imgInfo[0] ?? 0;
            $height = $imgInfo[1] ?? 0;
        }
        if (empty($mime) && class_exists('finfo')) {
            try {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime  = $finfo->file($_FILES['hero_image']['tmp_name']);
            } catch (\Throwable $e) { $mime = ''; }
        }
        if (empty($mime)) { $mime = $_FILES['hero_image']['type'] ?? ''; }

        $allowed = ['image/jpeg','image/png','image/webp','image/jpg'];
        if (!in_array($mime, $allowed, true)) {
            $uploadMsg = 'error:Invalid file type (' . htmlspecialchars($mime) . '). Only JPG, PNG, and WEBP are accepted.';
        } elseif (empty($imgInfo) || ($width ?? 0) < 1920 || ($height ?? 0) < 1080) {
            $uploadMsg = 'error:Image resolution too low (' . ($width ?? '?') . 'x' . ($height ?? '?') . 'px). Minimum 1920×1080 px required.';
        } else {
            $imgDir = dirname(__DIR__) . '/images';
            if (!is_dir($imgDir)) { @mkdir($imgDir, 0755, true); }

            $ext = strtolower(pathinfo($_FILES['hero_image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png','webp'])) { $ext = 'jpg'; }

            $filename = 'hero-custom-' . time() . '.' . $ext;
            $dest     = $imgDir . '/' . $filename;

            if (!move_uploaded_file($_FILES['hero_image']['tmp_name'], $dest)) {
                $uploadMsg = 'error:Failed to save image. Check directory permissions (' . $imgDir . ').';
            } else {
                $path    = 'images/' . $filename;
                $slideKey = 'hero_slide_' . $slideTarget;
                try {
                    dbExec(
                        "INSERT INTO site_settings (setting_key, setting_value, is_public) VALUES (?, ?, 1)
                         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                        [$slideKey, $path]
                    );
                    $uploadMsg = 'success:Image uploaded and assigned to Slide ' . $slideTarget . ' successfully.';
                } catch (\Throwable $e) {
                    $uploadMsg = 'success:Image uploaded (database update failed — please assign manually).';
                }
            }
        }
    }
}

// ── Handle assign image to slide ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_slide_image'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid security token.'; $flashType = 'error';
    } else {
        $slideNum = (int)($_POST['slide_num'] ?? 0);
        $path     = clean($_POST['image_path'] ?? '', 255);
        if ($slideNum < 1 || $slideNum > 3) {
            $flash = 'Invalid slide number.'; $flashType = 'error';
        } else {
            $key = 'hero_slide_' . $slideNum;
            try {
                dbExec(
                    "INSERT INTO site_settings (setting_key, setting_value, is_public) VALUES (?, ?, 1)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                    [$key, $path]
                );
                $flash = ($path === '')
                    ? 'Slide ' . $slideNum . ' reset to default.'
                    : 'Slide ' . $slideNum . ' image updated successfully.';
                $flashType = 'success';
            } catch (\Throwable $e) {
                $flash = 'Database error. Could not update slide image.'; $flashType = 'error';
            }
        }
    }
    header('Location: settings.php?msg=' . urlencode($flash) . '&mt=' . urlencode($flashType));
    exit;
}

// ── Handle delete hero image ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_hero_image'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid security token.'; $flashType = 'error';
    } else {
        $path     = clean($_POST['image_path'] ?? '', 255);
        $basename = basename($path);

        if ($basename === 'hero-bg.jpg' || $basename === 'hero.jpg' || !str_starts_with($basename, 'hero-custom-')) {
            $flash = 'Cannot delete system default images.'; $flashType = 'error';
        } else {
            $fullPath = dirname(__DIR__) . '/images/' . $basename;
            if (file_exists($fullPath)) { @unlink($fullPath); }

            // Unassign from any slide that was using this image
            try {
                for ($sn = 1; $sn <= 3; $sn++) {
                    $k   = 'hero_slide_' . $sn;
                    $cur = dbQueryOne("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$k]);
                    if (($cur['setting_value'] ?? '') === $path) {
                        dbExec(
                            "INSERT INTO site_settings (setting_key, setting_value, is_public) VALUES (?, '', 1)
                             ON DUPLICATE KEY UPDATE setting_value = ''",
                            [$k]
                        );
                    }
                }
                // Also clean up legacy active_hero_image if it pointed here
                $ah = dbQueryOne("SELECT setting_value FROM site_settings WHERE setting_key = 'active_hero_image'");
                if (($ah['setting_value'] ?? '') === $path) {
                    dbExec(
                        "INSERT INTO site_settings (setting_key, setting_value, is_public) VALUES ('active_hero_image', 'default_video', 1)
                         ON DUPLICATE KEY UPDATE setting_value = 'default_video'",
                        []
                    );
                }
                $flash = 'Image deleted successfully.'; $flashType = 'success';
            } catch (\Throwable $e) {
                $flash = 'Image deleted from disk; database cleanup may be incomplete.'; $flashType = 'warning';
            }
        }
    }
    header('Location: settings.php?msg=' . urlencode($flash) . '&mt=' . urlencode($flashType));
    exit;
}

// ── Handle settings save ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
        $flash = 'Invalid security token. Please refresh and try again.'; $flashType = 'error';
    } else {
        try {
            foreach (['reservation_enabled','takeaway_enabled'] as $key) {
                $val = isset($_POST[$key]) ? '1' : '0';
                dbExec(
                    "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                    [$key, $val]
                );
            }
            foreach ($DAY_KEYS as $dk) {
                $closed    = isset($_POST['closed_' . $dk]);
                $openTime  = clean($_POST['open_'  . $dk] ?? '', 5);
                $closeTime = clean($_POST['close_' . $dk] ?? '', 5);
                if ($closed) {
                    $val = 'closed';
                } elseif ($openTime !== '' && $closeTime !== '') {
                    $val = $openTime . '-' . $closeTime;
                } else {
                    $existing = dbQueryOne("SELECT setting_value FROM site_settings WHERE setting_key = ?", ['hours_' . $dk]);
                    $val = $existing['setting_value'] ?? '11:00-22:00';
                }
                dbExec(
                    "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                    ['hours_' . $dk, $val]
                );
            }
            $scalarKeys = ['min_takeaway_minutes',
                           'restaurant_name','phone','email',
                           'address_line1','address_line2','city','postcode'];
            foreach ($scalarKeys as $key) {
                $val = clean($_POST[$key] ?? '', 255);
                dbExec(
                    "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)",
                    [$key, $val]
                );
            }
            $flash = 'Settings saved successfully.'; $flashType = 'success';
        } catch (\Throwable $e) {
            error_log('[Bhimas admin/settings] ' . $e->getMessage());
            $flash = 'Database error. Could not save settings.'; $flashType = 'error';
        }
    }
    header('Location: settings.php?msg=' . urlencode($flash) . '&mt=' . urlencode($flashType));
    exit;
}

// Flash from redirect
if (isset($_GET['msg'])) {
    $flash     = clean($_GET['msg'] ?? '', 200);
    $flashType = in_array($_GET['mt'] ?? '', ['success','error','warning','info'], true) ? $_GET['mt'] : 'info';
}

// ── Load current settings from DB ──────────────────────────────────────────
$dbSettings = [];
try {
    $rows = dbQuery('SELECT setting_key, setting_value FROM site_settings');
    foreach ($rows as $r) {
        $dbSettings[$r['setting_key']] = $r['setting_value'];
    }
} catch (\Throwable $e) {
    error_log('[Bhimas admin/settings load] ' . $e->getMessage());
}

// Helper: get setting with fallback (HTML-safe)
function s(array $db, string $key, string $default = ''): string {
    return htmlspecialchars($db[$key] ?? $default, ENT_QUOTES, 'UTF-8');
}

// Parse hours string for a day
function parseDay(string $raw): array {
    if ($raw === '' || strtolower($raw) === 'closed') {
        return ['closed' => true, 'open' => '', 'close' => ''];
    }
    $parts = explode('-', $raw, 2);
    return ['closed' => false, 'open' => trim($parts[0]), 'close' => trim($parts[1] ?? '')];
}

$csrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');

// Upload notice handling
$uploadFlash = $uploadType = '';
if ($uploadMsg) {
    [$uploadType, $uploadFlash] = explode(':', $uploadMsg, 2);
}

// ── Scan /images/ for all hero-custom images ───────────────────────────────
$imagesDir   = dirname(__DIR__) . '/images';
$allHeroImgs = [];
if (is_dir($imagesDir)) {
    foreach (scandir($imagesDir) as $file) {
        if (preg_match('/^hero-custom-.*\.(jpg|jpeg|png|webp)$/i', $file)) {
            $fp            = $imagesDir . '/' . $file;
            $allHeroImgs[] = ['path' => 'images/' . $file, 'file' => $file, 'ts' => filemtime($fp) ?: 0];
        }
    }
    usort($allHeroImgs, fn($a,$b) => $b['ts'] <=> $a['ts']); // newest first
}

// Current slide assignments (1-indexed)
$slideVals = [
    1 => $dbSettings['hero_slide_1'] ?? '',
    2 => $dbSettings['hero_slide_2'] ?? '',
    3 => $dbSettings['hero_slide_3'] ?? '',
];

// Validate slide paths — clear DB if file no longer exists
foreach ($slideVals as $sn => $sv) {
    if ($sv !== '' && !str_starts_with($sv, 'http')) {
        $absPath = dirname(__DIR__) . '/' . $sv;
        if (!file_exists($absPath)) {
            // File gone — auto-clear from DB silently
            try {
                dbExec(
                    "INSERT INTO site_settings (setting_key, setting_value, is_public) VALUES (?, '', 1)
                     ON DUPLICATE KEY UPDATE setting_value = ''",
                    ['hero_slide_' . $sn]
                );
            } catch (\Throwable $e) {}
            $slideVals[$sn] = '';
        }
    }
}

adminHead('Settings', 'settings');
?>

<?php if ($flash): ?>
  <div class="adm-notice adm-notice--<?= htmlspecialchars($flashType, ENT_QUOTES, 'UTF-8') ?>">
    <?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>

<form method="POST" action="settings.php">
<input type="hidden" name="csrf_token"    value="<?= $csrf ?>">
<input type="hidden" name="save_settings" value="1">

<!-- ── 1. Availability Toggles ─────────────────────────────────────────── -->
<div class="adm-section">
  <div class="adm-section__head"><h2>Availability</h2></div>
  <div class="adm-section__body">
    <div style="display:flex;gap:40px;flex-wrap:wrap;">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;">
        <input type="checkbox" name="reservation_enabled"
               <?= ($dbSettings['reservation_enabled'] ?? '1') === '1' ? 'checked' : '' ?>
               style="width:18px;height:18px;accent-color:var(--maroon);">
        <span><strong>Accept Online Bookings</strong><br>
          <small style="color:#888;">Allow customers to submit reservation requests</small></span>
      </label>
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;">
        <input type="checkbox" name="takeaway_enabled"
               <?= ($dbSettings['takeaway_enabled'] ?? '1') === '1' ? 'checked' : '' ?>
               style="width:18px;height:18px;accent-color:var(--maroon);">
        <span><strong>Accept Takeaway Orders</strong><br>
          <small style="color:#888;">Allow customers to place pre-orders online</small></span>
      </label>
    </div>
  </div>
</div>

<!-- ── 2. Opening Hours ─────────────────────────────────────────────────── -->
<div class="adm-section">
  <div class="adm-section__head">
    <h2>Opening Hours</h2>
    <small style="color:#888;">Format: opening and closing times per day. Check &ldquo;Closed&rdquo; to mark a day off.</small>
  </div>
  <div class="adm-section__body">
    <div class="adm-table-wrap">
    <table class="hours-table">
      <thead>
        <tr>
          <th style="width:130px;">Day</th>
          <th>Opens</th>
          <th>Closes</th>
          <th style="width:80px;text-align:center;">Closed</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($DAY_KEYS as $i => $dk):
            $raw  = $dbSettings['hours_' . $dk] ?? 'closed';
            $day  = parseDay($raw);
        ?>
        <tr>
          <td><strong><?= $DAY_LABELS[$i] ?></strong></td>
          <td>
            <select name="open_<?= $dk ?>"
                    <?= $day['closed'] ? 'disabled' : '' ?>>
              <?= timeOptions($day['open']) ?>
            </select>
          </td>
          <td>
            <select name="close_<?= $dk ?>"
                    <?= $day['closed'] ? 'disabled' : '' ?>>
              <?= timeOptions($day['close']) ?>
            </select>
          </td>
          <td style="text-align:center;">
            <input type="checkbox" name="closed_<?= $dk ?>"
                   <?= $day['closed'] ? 'checked' : '' ?>
                   style="width:16px;height:16px;accent-color:var(--maroon);"
                   onchange="
                     var row = this.closest('tr');
                     row.querySelectorAll('select').forEach(function(s){ s.disabled = this.checked; }.bind(this));
                   ">
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<!-- ── 3. Takeaway Settings ─────────────────────────────────────────────── -->
<div class="adm-section">
  <div class="adm-section__head"><h2>Takeaway Settings</h2></div>
  <div class="adm-section__body">
    <div style="max-width:400px;">
      <label class="adm-label" for="min_takeaway_minutes">
        Minimum preparation time (minutes)
      </label>
      <input class="adm-input" type="number" id="min_takeaway_minutes"
             name="min_takeaway_minutes" min="15" max="180" step="5"
             value="<?= s($dbSettings, 'min_takeaway_minutes', '60') ?>"
             style="width:120px;">
      <p style="margin-top:6px;font-size:12px;color:#888;">
        Customers can only select pickup times at least this many minutes in the future.
      </p>
    </div>
  </div>
</div>

<!-- ── 4. Contact Details ───────────────────────────────────────────────── -->
<div class="adm-section">
  <div class="adm-section__head"><h2>Contact Details</h2></div>
  <div class="adm-section__body">
    <div class="adm-settings-grid">
      <div>
        <label class="adm-label" for="restaurant_name">Restaurant Name</label>
        <input class="adm-input" style="width:100%;" type="text" id="restaurant_name"
               name="restaurant_name" value="<?= s($dbSettings, 'restaurant_name') ?>">
      </div>
      <div>
        <label class="adm-label" for="phone">Phone</label>
        <input class="adm-input" style="width:100%;" type="text" id="phone"
               name="phone" value="<?= s($dbSettings, 'phone') ?>">
      </div>
      <div>
        <label class="adm-label" for="email">Public Email</label>
        <input class="adm-input" style="width:100%;" type="email" id="email"
               name="email" value="<?= s($dbSettings, 'email') ?>">
      </div>
      <div>
        <label class="adm-label" for="postcode">Postcode</label>
        <input class="adm-input" style="width:100%;" type="text" id="postcode"
               name="postcode" value="<?= s($dbSettings, 'postcode') ?>">
      </div>
      <div>
        <label class="adm-label" for="address_line1">Address Line 1</label>
        <input class="adm-input" style="width:100%;" type="text" id="address_line1"
               name="address_line1" value="<?= s($dbSettings, 'address_line1') ?>">
      </div>
      <div>
        <label class="adm-label" for="address_line2">Address Line 2</label>
        <input class="adm-input" style="width:100%;" type="text" id="address_line2"
               name="address_line2" value="<?= s($dbSettings, 'address_line2') ?>">
      </div>
      <div>
        <label class="adm-label" for="city">City</label>
        <input class="adm-input" style="width:100%;" type="text" id="city"
               name="city" value="<?= s($dbSettings, 'city') ?>">
      </div>
    </div>
  </div>
</div>

<div style="margin-bottom:32px;">
  <button class="btn-adm btn-adm--primary" type="submit"
          style="padding:11px 32px;font-size:15px;">
    &#10003; Save All Settings
  </button>
</div>
</form>


<!-- ══════════════════════════════════════════════════════════════════════════
     HERO SLIDER MANAGER
     - 3 slide cards with direct per-slide upload
     - Gallery of uploaded custom images with assign / delete controls
════════════════════════════════════════════════════════════════════════════ -->

<style>
/* ─── Hero Slider Manager ─────────────────────────────────────────────────── */

/* 3-column slide slots */
.hslider-slots {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
  margin-bottom: 36px;
}
@media (max-width: 820px) { .hslider-slots { grid-template-columns: 1fr; } }

.hslide-card {
  border: 2px solid rgba(44,24,16,0.12);
  border-radius: 6px;
  overflow: hidden;
  background: #fff;
  display: flex;
  flex-direction: column;
  transition: box-shadow 0.2s;
}
.hslide-card:hover { box-shadow: 0 4px 18px rgba(44,24,16,0.1); }

.hslide-card__hdr {
  background: #2C1810;
  color: #FFDE59;
  font-family: 'Outfit', sans-serif;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.hslide-card__hdr-badge {
  background: rgba(255,222,89,0.18);
  color: #FFDE59;
  font-size: 10px;
  padding: 2px 8px;
  border-radius: 20px;
  letter-spacing: 0.08em;
}

/* Thumbnail */
.hslide-card__thumb-wrap {
  position: relative;
  width: 100%;
  height: 160px;
  background: #F5F0E8;
  overflow: hidden;
}
.hslide-card__thumb {
  width: 100%; height: 100%;
  object-fit: cover;
  display: block;
  transition: transform 0.4s ease;
}
.hslide-card:hover .hslide-card__thumb { transform: scale(1.04); }
.hslide-card__thumb-placeholder {
  width: 100%; height: 100%;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  color: #bbb; gap: 6px;
  font-size: 12px;
}
.hslide-card__thumb-placeholder svg { opacity: 0.4; }

/* Upload zone inside card */
.hslide-card__body {
  padding: 14px;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.hslide-upload-zone {
  border: 1.5px dashed rgba(128,28,35,0.35);
  border-radius: 5px;
  padding: 12px 10px;
  text-align: center;
  cursor: pointer;
  transition: border-color 0.2s, background 0.2s;
  background: #FDF9F2;
  position: relative;
}
.hslide-upload-zone:hover {
  border-color: #801C23;
  background: #FFF3F4;
}
.hslide-upload-zone input[type="file"] {
  position: absolute; inset: 0;
  opacity: 0; cursor: pointer; width: 100%; height: 100%;
}
.hslide-upload-zone__icon { font-size: 22px; margin-bottom: 4px; display: block; }
.hslide-upload-zone__txt {
  font-size: 11.5px;
  color: #801C23;
  font-weight: 600;
  display: block;
  line-height: 1.4;
}
.hslide-upload-zone__hint {
  font-size: 10px;
  color: #999;
  display: block;
  margin-top: 2px;
}

.hslide-upload-btn {
  width: 100%;
  padding: 9px 12px;
  background: #801C23;
  color: #fff;
  border: none;
  border-radius: 4px;
  font-family: 'Outfit', sans-serif;
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  transition: background 0.2s;
  display: flex; align-items: center; justify-content: center; gap: 6px;
}
.hslide-upload-btn:hover { background: #4A0E12; }
.hslide-upload-btn:disabled { opacity: 0.55; cursor: not-allowed; }

.hslide-filename-preview {
  font-size: 11px;
  color: #555;
  word-break: break-all;
  display: none;
  background: #f9f6ef;
  border-radius: 3px;
  padding: 4px 8px;
  border: 1px solid rgba(44,24,16,0.1);
}

/* ─── Gallery ─────────────────────────────────────────────────────────── */
.hgallery-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
  padding-bottom: 10px;
  border-bottom: 1px solid rgba(44,24,16,0.08);
}
.hgallery-header h3 {
  font-family: 'Outfit', sans-serif;
  font-size: 15px;
  font-weight: 700;
  color: #2C1810;
  margin: 0;
}
.hgallery-empty {
  text-align: center;
  padding: 40px 20px;
  color: #999;
  font-size: 13px;
  border: 1.5px dashed rgba(44,24,16,0.15);
  border-radius: 6px;
  background: #faf8f4;
}
.hgallery-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: 16px;
}
.hgallery-card {
  border: 1px solid rgba(44,24,16,0.1);
  border-radius: 5px;
  overflow: hidden;
  background: #fff;
  transition: box-shadow 0.2s;
}
.hgallery-card:hover { box-shadow: 0 3px 14px rgba(44,24,16,0.1); }
.hgallery-card__thumb {
  width: 100%; height: 110px;
  object-fit: cover;
  display: block;
}
.hgallery-card__meta {
  padding: 8px 10px 4px;
  font-size: 10.5px;
  color: #666;
  line-height: 1.4;
  word-break: break-all;
}
.hgallery-card__meta strong {
  display: block; color: #2C1810; font-size: 11px; margin-bottom: 1px;
}
.hgallery-card__actions {
  padding: 8px 10px 10px;
  display: flex;
  flex-direction: column;
  gap: 5px;
}
.hgallery-assign-row {
  display: flex;
  gap: 5px;
}
.hgallery-assign-btn {
  flex: 1;
  padding: 5px 4px;
  font-size: 10.5px;
  font-weight: 600;
  font-family: 'Outfit', sans-serif;
  border: 1.5px solid #801C23;
  background: #fff;
  color: #801C23;
  border-radius: 3px;
  cursor: pointer;
  transition: background 0.18s, color 0.18s;
  white-space: nowrap;
}
.hgallery-assign-btn:hover { background: #801C23; color: #fff; }
.hgallery-assign-btn.assigned {
  background: #801C23; color: #FFDE59;
  border-color: #801C23;
}
.hgallery-delete-btn {
  width: 100%;
  padding: 5px;
  font-size: 10.5px;
  background: transparent;
  border: 1px solid rgba(200,50,50,0.3);
  color: #c00;
  border-radius: 3px;
  cursor: pointer;
  font-family: 'Outfit', sans-serif;
  transition: background 0.18s;
}
.hgallery-delete-btn:hover { background: #fff0f0; border-color: #c00; }

/* Slide assigned badges on gallery cards */
.hgallery-card__badges {
  display: flex; gap: 4px; flex-wrap: wrap;
  padding: 0 10px 6px;
}
.hgallery-badge {
  font-size: 9.5px;
  font-weight: 700;
  font-family: 'Outfit', sans-serif;
  letter-spacing: 0.06em;
  padding: 2px 7px;
  border-radius: 20px;
  background: rgba(128,28,35,0.12);
  color: #801C23;
}

.hreq-info {
  background: #FFF8E7;
  border-left: 3px solid #C9A84C;
  padding: 10px 14px;
  font-size: 12px;
  color: #6B3F18;
  border-radius: 2px;
  line-height: 1.5;
  margin-bottom: 20px;
}
.hreq-info strong { color: #5A3010; }
</style>

<div class="adm-section">
  <div class="adm-section__head">
    <h2>Hero Slider</h2>
    <small style="color:#888;">Upload images directly to each slide or assign from the gallery below.</small>
  </div>
  <div class="adm-section__body">

    <?php if ($uploadFlash): ?>
      <div class="adm-notice adm-notice--<?= htmlspecialchars($uploadType, ENT_QUOTES, 'UTF-8') ?>"
           style="margin-bottom:20px;">
        <?= htmlspecialchars($uploadFlash, ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <!-- Requirements notice -->
    <div class="hreq-info">
      <strong>Image Requirements:</strong> &nbsp;JPG / PNG / WEBP &nbsp;·&nbsp; Minimum <strong>1920×1080 px</strong> &nbsp;·&nbsp; Max <strong>5 MB</strong>
    </div>

    <!-- ── 3 Slide Slots ────────────────────────────────────────────────── -->
    <div class="hslider-slots">
      <?php for ($sn = 1; $sn <= 3; $sn++):
          $curVal   = $slideVals[$sn];
          $hasImg   = ($curVal !== '');
          $thumbSrc = $hasImg
              ? (str_starts_with($curVal, 'http') ? $curVal : '../' . $curVal . '?v=' . time())
              : '';
      ?>
      <div class="hslide-card">

        <!-- Header -->
        <div class="hslide-card__hdr">
          <span>Slide <?= $sn ?></span>
          <?php if ($hasImg): ?><span class="hslide-card__hdr-badge">✓ Image set</span><?php endif; ?>
        </div>

        <!-- Thumbnail preview -->
        <div class="hslide-card__thumb-wrap">
          <?php if ($hasImg): ?>
            <img class="hslide-card__thumb" src="<?= htmlspecialchars($thumbSrc) ?>" alt="Slide <?= $sn ?> preview"
                 onerror="this.parentNode.innerHTML='<div class=\'hslide-card__thumb-placeholder\'>⚠ Image not found</div>'">
          <?php else: ?>
            <div class="hslide-card__thumb-placeholder">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
              <span>Using default image</span>
            </div>
          <?php endif; ?>
        </div>

        <!-- Per-slide upload form -->
        <div class="hslide-card__body">
          <form method="POST" action="settings.php" enctype="multipart/form-data"
                id="slideForm<?= $sn ?>">
            <input type="hidden" name="csrf_token"      value="<?= $csrf ?>">
            <input type="hidden" name="upload_to_slide" value="<?= $sn ?>">

            <div class="hslide-upload-zone" id="zone<?= $sn ?>">
              <input type="file" name="hero_image" accept="image/jpeg,image/png,image/webp"
                     id="file<?= $sn ?>" onchange="previewSlideFile(<?= $sn ?>)">
              <span class="hslide-upload-zone__icon">📁</span>
              <span class="hslide-upload-zone__txt">Click to choose image</span>
              <span class="hslide-upload-zone__hint">JPG · PNG · WEBP · min 1920×1080</span>
            </div>

            <div class="hslide-filename-preview" id="fname<?= $sn ?>"></div>

            <button type="submit" class="hslide-upload-btn" id="ubtn<?= $sn ?>" disabled>
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
              Upload to Slide <?= $sn ?>
            </button>
          </form>

          <?php if ($hasImg): ?>
            <!-- Remove this slide's image (revert to default) -->
            <form method="POST" action="settings.php" style="margin:0;">
              <input type="hidden" name="csrf_token"     value="<?= $csrf ?>">
              <input type="hidden" name="set_slide_image" value="1">
              <input type="hidden" name="slide_num"      value="<?= $sn ?>">
              <input type="hidden" name="image_path"     value="">
              <button type="submit" class="hgallery-delete-btn"
                      onclick="return confirm('Remove image from Slide <?= $sn ?> and revert to default?')">
                ✕ Remove / reset to default
              </button>
            </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endfor; ?>
    </div>

    <!-- ── Uploaded Images Gallery ───────────────────────────────────── -->
    <div class="hgallery-header">
      <h3>📷 Uploaded Images Library</h3>
      <small style="color:#999;"><?= count($allHeroImgs) ?> image<?= count($allHeroImgs) !== 1 ? 's' : '' ?> uploaded</small>
    </div>

    <?php if (empty($allHeroImgs)): ?>
      <div class="hgallery-empty">
        No custom images uploaded yet.<br>Use the upload buttons above to add hero images.
      </div>
    <?php else: ?>
    <div class="hgallery-grid">
      <?php foreach ($allHeroImgs as $img):
          $bg          = $img['path'];
          $bname       = $img['file'];
          $bgMTime     = $img['ts'];
          $label       = preg_match('/hero-custom-(\d+)/', $bname, $m3)
                             ? date('d M Y · H:i', (int)$m3[1])
                             : $bname;
          // Which slides use this image?
          $usedInSlides = array_keys(array_filter($slideVals, fn($v) => $v === $bg));
      ?>
      <div class="hgallery-card">
        <img class="hgallery-card__thumb"
             src="../<?= htmlspecialchars($bg) ?>?v=<?= $bgMTime ?>"
             alt="<?= htmlspecialchars($label) ?>"
             onerror="this.style.background='#f5f0e8'">

        <?php if (!empty($usedInSlides)): ?>
        <div class="hgallery-card__badges">
          <?php foreach ($usedInSlides as $sNum): ?>
            <span class="hgallery-badge">Slide <?= $sNum ?></span>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="hgallery-card__meta">
          <strong><?= htmlspecialchars($label) ?></strong>
          <?= htmlspecialchars($bname) ?>
        </div>

        <div class="hgallery-card__actions">
          <!-- Assign to slide buttons -->
          <div class="hgallery-assign-row">
            <?php for ($sn = 1; $sn <= 3; $sn++): ?>
              <form method="POST" action="settings.php" style="margin:0; flex:1;">
                <input type="hidden" name="csrf_token"      value="<?= $csrf ?>">
                <input type="hidden" name="set_slide_image" value="1">
                <input type="hidden" name="slide_num"       value="<?= $sn ?>">
                <input type="hidden" name="image_path"      value="<?= htmlspecialchars($bg) ?>">
                <button type="submit"
                  class="hgallery-assign-btn <?= in_array($sn, $usedInSlides) ? 'assigned' : '' ?>"
                  title="Assign to Slide <?= $sn ?>">
                  S<?= $sn ?>
                </button>
              </form>
            <?php endfor; ?>
          </div>

          <!-- Delete permanently -->
          <form method="POST" action="settings.php" style="margin:0;"
                onsubmit="return confirm('Delete this image permanently from disk and all slides?')">
            <input type="hidden" name="csrf_token"        value="<?= $csrf ?>">
            <input type="hidden" name="delete_hero_image" value="1">
            <input type="hidden" name="image_path"        value="<?= htmlspecialchars($bg) ?>">
            <button type="submit" class="hgallery-delete-btn">🗑 Delete permanently</button>
          </form>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
function previewSlideFile(n) {
  var inp  = document.getElementById('file'  + n);
  var lbl  = document.getElementById('fname' + n);
  var btn  = document.getElementById('ubtn'  + n);
  var zone = document.getElementById('zone'  + n);
  if (inp.files && inp.files[0]) {
    var f = inp.files[0];
    lbl.textContent = '📄 ' + f.name + ' (' + (f.size/1024/1024).toFixed(2) + ' MB)';
    lbl.style.display = 'block';
    btn.disabled = false;
    zone.style.borderColor = '#801C23';
    zone.style.background  = '#fff3f4';
  } else {
    lbl.style.display = 'none';
    btn.disabled = true;
    zone.style.borderColor = '';
    zone.style.background  = '';
  }
}
</script>

<?php adminFoot(); ?>
