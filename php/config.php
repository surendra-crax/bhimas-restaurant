<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * php/config.php — Core configuration, DB connection, shared helpers
 *
 * USAGE: require_once __DIR__ . '/config.php';
 *
 * IMPORTANT: This file must never be served directly.
 * It is protected by .htaccess.
 */

/* ============================================================
   Guard: block direct HTTP access
   ============================================================ */
if (!defined('BHIMAS_LOADED') && php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Access denied.');
}

if (!defined('BHIMAS_LOADED')) {
    define('BHIMAS_LOADED', true);
}

/* ============================================================
   0. OUTPUT BUFFER + GLOBAL EXCEPTION HANDLER
   Captures any stray PHP warnings/notices so they never
   corrupt a JSON response. The exception handler ensures
   even uncaught RuntimeException returns valid JSON.
   ============================================================ */
ob_start();

set_exception_handler(function (\Throwable $e): void {
    ob_end_clean();
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $msg = defined('BHIMAS_DEBUG') && BHIMAS_DEBUG
        ? '[Debug] ' . $e->getMessage() . ' — ' . basename($e->getFile()) . ':' . $e->getLine()
        : 'A server error occurred. Please try again or call us directly.';
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
});

/* ============================================================
   1. ENVIRONMENT DETECTION
   ============================================================ */
if (!defined('BHIMAS_ENV')) {
    define('BHIMAS_ENV', 'development'); // Temporary for live debugging
}
if (!defined('BHIMAS_DEBUG')) {
    define('BHIMAS_DEBUG', BHIMAS_ENV === 'development');
}

$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = (empty($host) || strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);

if (BHIMAS_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

/* ============================================================
   2. DATABASE CONSTANTS
   ============================================================ */
if ($isLocal) {
    // ── Local MAMP/XAMPP Settings ──
    if (!defined('DB_HOST')) define('DB_HOST',    getenv('DB_HOST')    ?: 'localhost');
    if (!defined('DB_NAME')) define('DB_NAME',    getenv('DB_NAME')    ?: 'bhimas_db');
    if (!defined('DB_USER')) define('DB_USER',    getenv('DB_USER')    ?: 'root');       // MAMP default
    if (!defined('DB_PASS')) define('DB_PASS',    getenv('DB_PASS')    ?: 'root');       // MAMP default
    if (!defined('DB_PORT')) define('DB_PORT',    (int)(getenv('DB_PORT') ?: 8889));    // MAMP MySQL port
} else {
    // ── Live LWS Hosting Settings ──
    // Enter the database credentials created in your LWS control panel below:
    if (!defined('DB_HOST')) define('DB_HOST',    getenv('DB_HOST')    ?: '127.0.0.1');  // LWS Database Server IP
    if (!defined('DB_NAME')) define('DB_NAME',    getenv('DB_NAME')    ?: 'bhima2766418_1qmmhk'); // LWS Database Name
    if (!defined('DB_USER')) define('DB_USER',    getenv('DB_USER')    ?: 'bhima2766418');       // LWS Database User
    if (!defined('DB_PASS')) define('DB_PASS',    getenv('DB_PASS')    ?: 'Bhimas@2026');        // LWS Database Password
    if (!defined('DB_PORT')) define('DB_PORT',    (int)(getenv('DB_PORT') ?: 3306));     // Standard Live MySQL port
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

/* ============================================================
   3. SITE CONSTANTS
   ============================================================ */
if (!defined('SITE_NAME'))        define('SITE_NAME',       'Bhimas Traditional Indian Kitchen');
if (!defined('SITE_URL'))         define('SITE_URL',         getenv('SITE_URL') ?: 'https://bhimas.lu');
if (!defined('SITE_EMAIL'))       define('SITE_EMAIL',       getenv('SITE_EMAIL') ?: ($isLocal ? 'surendrabandaru09@gmail.com' : 'hello@bhimas.lu'));
if (!defined('TIMEZONE'))         define('TIMEZONE',         'Europe/Luxembourg');
if (!defined('CURRENCY_SYMBOL'))  define('CURRENCY_SYMBOL',  '€');
if (!defined('CURRENCY_CODE'))    define('CURRENCY_CODE',    'EUR');

// Admin authentication — change this via environment variable on your server
if (!defined('ADMIN_PASSWORD'))   define('ADMIN_PASSWORD',   getenv('ADMIN_PASSWORD') ?: 'bhimas_admin_2025');

// Resend transactional email API key
if (!defined('RESEND_API_KEY'))   define('RESEND_API_KEY',   getenv('RESEND_API_KEY') ?: '');

date_default_timezone_set(TIMEZONE);

/* ============================================================
   4. SESSION — start once, configure securely
   ============================================================ */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly',  '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!BHIMAS_DEBUG) {
        ini_set('session.cookie_secure', '1'); // HTTPS only in production
    }
    ini_set('session.use_strict_mode',  '1');
    ini_set('session.gc_maxlifetime',   '3600');
    session_name('bhimas_sess');
    session_start();
}

/* ============================================================
   5. PDO CONNECTION FACTORY
   ============================================================ */

/** @var PDO|null $_pdo — singleton connection */
$_pdo = null;

/**
 * Get (or lazily create) the shared PDO connection.
 *
 * @return PDO
 * @throws RuntimeException on connection failure
 */
function db(): PDO
{
    global $_pdo;

    if ($_pdo instanceof PDO) {
        return $_pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_FOUND_ROWS   => true,
    ];

    try {
        $_pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        if (BHIMAS_DEBUG) {
            throw new RuntimeException('DB connection failed: ' . $e->getMessage(), 500, $e);
        }
        // In production log quietly and return a generic error
        error_log('[Bhimas DB] Connection failed: ' . $e->getMessage());
        throw new RuntimeException('A database error occurred. Please try again later.', 500);
    }

    return $_pdo;
}

/* ============================================================
   6. CSRF TOKEN HELPERS
   ============================================================ */

/**
 * Generate (or retrieve) the CSRF token for the current session.
 * Echo this into a <meta name="csrf-token"> or a hidden form field.
 *
 * @return string 64-character hex token
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a submitted CSRF token.
 * Throws on failure or returns true on success.
 *
 * @param  string $token — value from POST or request header
 * @return bool
 */
function csrf_verify(string $token): bool
{
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!$expected || !hash_equals($expected, $token)) {
        return false;
    }
    return true;
}

/**
 * Verify CSRF from POST field OR X-CSRF-Token header.
 * Calls jsonResponse(403, 'Invalid CSRF token') and exits on failure.
 * In development mode (BHIMAS_DEBUG = true) the check is skipped
 * so static .html pages can be tested without a PHP-rendered CSRF meta tag.
 */
function csrf_check(): void
{
    if (BHIMAS_DEBUG) {
        return; // Skip CSRF in development — never skip in production
    }

    $token = $_POST['csrf_token']
          ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if (!csrf_verify((string) $token)) {
        jsonResponse(403, 'Invalid security token. Please refresh and try again.');
    }
}

/* ============================================================
   7. INPUT SANITISER
   ============================================================ */

/**
 * Sanitise and trim a scalar value.
 * Strips tags and optionally limits length.
 *
 * @param  mixed  $value
 * @param  int    $maxLen   0 = no limit
 * @return string
 */
function clean($value, int $maxLen = 0): string
{
    $str = strip_tags(trim((string) $value));
    if ($maxLen > 0 && mb_strlen($str) > $maxLen) {
        $str = mb_substr($str, 0, $maxLen);
    }
    return $str;
}

/**
 * Cast and validate an integer from user input.
 *
 * @param  mixed $value
 * @param  int   $min
 * @param  int   $max
 * @return int|null  null if out of range or not numeric
 */
function cleanInt($value, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
{
    $v = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $min, 'max_range' => $max]
    ]);
    return ($v === false) ? null : (int) $v;
}

/**
 * Validate an email address.
 *
 * @param  string $email
 * @return string|null  Sanitised email or null if invalid
 */
function cleanEmail(string $email): ?string
{
    $v = filter_var(trim($email), FILTER_VALIDATE_EMAIL);
    return $v === false ? null : (string) $v;
}

/* ============================================================
   8. JSON RESPONSE HELPER
   ============================================================ */

/**
 * Send a JSON response and exit.
 *
 * @param  int    $status   HTTP status code (200, 400, 403, 500 …)
 * @param  mixed  $data     String message OR array/object payload
 * @param  array  $extra    Additional top-level keys to merge into response
 */
function jsonResponse(int $status, $data = null, array $extra = []): void
{
    // Discard any stray output (PHP notices, warnings) before sending JSON
    if (ob_get_level() > 0) { ob_end_clean(); }

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        // Prevent caching of API responses
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }

    $success = ($status >= 200 && $status < 300);

    if (is_string($data)) {
        $body = ['success' => $success, 'message' => $data];
    } elseif (is_array($data) || is_object($data)) {
        $body = array_merge(['success' => $success], (array) $data);
    } else {
        $body = ['success' => $success];
    }

    if ($extra) {
        $body = array_merge($body, $extra);
    }

    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/* ============================================================
   9. REFERENCE / ORDER ID GENERATOR
   ============================================================ */

/**
 * Generate a unique human-readable reference number.
 *
 * Format examples:
 *   Booking: BHM-RES-20250513-A4F2
 *   Order:   BHM-ORD-20250513-C9E1
 *
 * @param  string $type  'RES' for reservation, 'ORD' for order, 'TKW' for takeaway
 * @return string
 */
function generateRef(string $type = 'ORD'): string
{
    $type     = strtoupper(substr(preg_replace('/[^A-Z]/', '', $type), 0, 3));
    $date     = date('Ymd');
    $random   = strtoupper(bin2hex(random_bytes(2))); // 4 hex chars
    return "BHM-{$type}-{$date}-{$random}";
}

/* ============================================================
   10. GENERIC DB HELPERS
   ============================================================ */

/**
 * Execute a prepared query and return all rows.
 *
 * @param  string $sql
 * @param  array  $params
 * @return array
 */
function dbQuery(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Execute a prepared query and return the first row (or null).
 *
 * @param  string $sql
 * @param  array  $params
 * @return array|null
 */
function dbQueryOne(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/**
 * Execute an INSERT/UPDATE/DELETE and return affected row count.
 *
 * @param  string $sql
 * @param  array  $params
 * @return int
 */
function dbExec(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Execute an INSERT and return the last inserted ID.
 *
 * @param  string $sql
 * @param  array  $params
 * @return string|false
 */
function dbInsert(string $sql, array $params = []): string|false
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return db()->lastInsertId();
}

/* ============================================================
   11. REQUIRE: AJAX / POST GUARD HELPERS
   ============================================================ */

/**
 * Abort if the request is not a POST.
 */
function requirePost(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, 'Method Not Allowed');
    }
}

/**
 * Abort if the request is not an XMLHttpRequest.
 */
function requireAjax(): void
{
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        jsonResponse(400, 'AJAX request required');
    }
}

/**
 * Abort if the user is not logged in as admin.
 */
function requireAdmin(): void
{
    if (empty($_SESSION['admin_logged_in'])) {
        jsonResponse(401, 'Unauthorised');
    }
}

/* ============================================================
   12. SHARED EMAIL HELPERS
   _emailHeader()          — shared branded header HTML
   _emailFooter()          — shared footer HTML
   buildEmailHtml()        — generic table email (admin/contact)
   buildOrderEmail()       — rich takeaway order confirmation
   buildOrderStatusEmail() — order status update notification
   buildReservationEmail() — elegant table reservation email
   ownerEmail()            — resolves owner notification address
   sendEmail()             — sends via Resend API (curl)
   ============================================================ */

/** Shared maroon header with logo badge + brand name. */
function _emailHeader(string $siteUrl, string $siteName): string
{
    return "
      <!-- ══ HEADER ══ -->
      <tr>
        <td style='background:#801C23;padding:0;'>
          <!-- Top accent bar -->
          <div style='height:4px;background:linear-gradient(90deg,#C9A84C 0%,#FFDE59 50%,#C9A84C 100%);'></div>
          <table width='100%' cellpadding='0' cellspacing='0'>
            <tr>
              <td style='padding:28px 32px 22px;text-align:center;'>
                <!-- Logo badge (inline SVG so no external request needed) -->
                <div style='display:inline-block;width:70px;height:70px;border-radius:50%;
                            background:#fff;border:3px solid #C9A84C;overflow:hidden;
                            vertical-align:middle;margin-bottom:12px;'>
                  <svg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'
                       width='64' height='64' style='display:block;margin:3px auto 0;'>
                    <!-- pot -->
                    <ellipse cx='100' cy='95' rx='36' ry='30' fill='#8B5728'/>
                    <rect x='84' y='58' width='32' height='16' rx='4' fill='#8B5728'/>
                    <ellipse cx='100' cy='58' rx='18' ry='6' fill='#6B3F18'/>
                    <!-- steam -->
                    <ellipse cx='88' cy='48' rx='4' ry='6' fill='rgba(200,200,200,0.5)'/>
                    <ellipse cx='100' cy='44' rx='4' ry='7' fill='rgba(200,200,200,0.5)'/>
                    <ellipse cx='112' cy='48' rx='4' ry='6' fill='rgba(200,200,200,0.5)'/>
                    <!-- leaves -->
                    <ellipse cx='60' cy='108' rx='20' ry='10' fill='#2E7D32' transform='rotate(-20,60,108)'/>
                    <ellipse cx='140' cy='108' rx='20' ry='10' fill='#2E7D32' transform='rotate(20,140,108)'/>
                    <!-- banner -->
                    <rect x='20' y='120' width='160' height='34' rx='4' fill='#2E7D32'/>
                    <text x='100' y='143' font-family='Poppins, sans-serif' font-size='20' font-weight='bold'
                          fill='white' text-anchor='middle'>BHIMAS</text>
                  </svg>
                </div>
                <br>
                <span style='font-family:Poppins, sans-serif;font-size:26px;font-weight:700;
                             color:#ffffff;letter-spacing:1px;'>Bhimas</span>
                <br>
                <span style='font-size:10px;color:#FFDE59;letter-spacing:3px;
                             text-transform:uppercase;'>Traditional Indian Kitchen</span>
              </td>
            </tr>
          </table>
        </td>
      </tr>";
}

/** Shared footer with contact info and copyright. */
function _emailFooter(string $siteUrl, string $siteName, string $phone = ''): string
{
    $year = date('Y');
    $phoneHtml = $phone
        ? "<span style='color:#C9A84C;'>&#9742;</span> {$phone} &nbsp;|&nbsp; "
        : '';
    return "
      <!-- ══ FOOTER ══ -->
      <tr>
        <td style='background:#2C1008;padding:20px 32px;text-align:center;'>
          <p style='margin:0 0 6px;font-size:13px;color:rgba(250,246,238,0.75);'>
            {$phoneHtml}
            <a href='{$siteUrl}' style='color:#C9A84C;text-decoration:none;'>{$siteUrl}</a>
          </p>
          <p style='margin:0;font-size:11px;color:rgba(250,246,238,0.45);'>
            &copy; {$year} {$siteName}. All rights reserved.
          </p>
        </td>
      </tr>
      <!-- Gold bottom bar -->
      <tr>
        <td style='height:4px;background:linear-gradient(90deg,#C9A84C 0%,#FFDE59 50%,#C9A84C 100%);'></td>
      </tr>";
}

/** Outer email wrapper. */
function _emailWrap(string $inner): string
{
    return "<!DOCTYPE html>
<html lang='en'>
<head>
  <meta charset='UTF-8'>
  <meta name='viewport' content='width=device-width,initial-scale=1'>
</head>
<body style='margin:0;padding:0;background:#f0e6d6;font-family:Poppins, sans-serif;'>
<table width='100%' cellpadding='0' cellspacing='0' style='background:#f0e6d6;padding:32px 0;'>
  <tr><td align='center' style='padding:0 16px;'>
    <table width='600' cellpadding='0' cellspacing='0'
           style='background:#ffffff;border-radius:6px;max-width:600px;width:100%;
                  box-shadow:0 4px 24px rgba(44,16,8,0.15);overflow:hidden;'>
      {$inner}
    </table>
  </td></tr>
</table>
</body>
</html>";
}

/**
 * Build a generic branded HTML email (used by admin/contact handlers).
 * Accepts two row formats:
 *   a) [['label' => 'Name', 'value' => 'John'], ...]   → value may contain safe HTML
 *   b) ['Name' => 'John', 'Date' => '2025-01-01']       → values always escaped
 */
function buildEmailHtml(string $title, array $rows, string $note = ''): string
{
    $siteUrl  = defined('SITE_URL')  ? SITE_URL  : 'https://bhimas.lu';
    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Bhimas Traditional Indian Kitchen';

    $rowsHtml = '';
    foreach ($rows as $key => $row) {
        if (is_array($row)) {
            $label     = htmlspecialchars((string)($row['label'] ?? ''), ENT_QUOTES, 'UTF-8');
            $valueHtml = (string)($row['value'] ?? '');
        } else {
            $label     = htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8');
            $valueHtml = htmlspecialchars((string)$row, ENT_QUOTES, 'UTF-8');
        }
        $rowsHtml .= "
        <tr>
          <td style='padding:10px 14px;font-size:13px;font-weight:600;color:#4A0E12;
                     background:#FFF8E7;border-bottom:1px solid #e8d5b0;
                     width:36%;vertical-align:top;white-space:nowrap;'>{$label}</td>
          <td style='padding:10px 14px;font-size:13px;color:#2C1008;
                     border-bottom:1px solid #e8d5b0;'>{$valueHtml}</td>
        </tr>";
    }

    $noteHtml = $note
        ? "<p style='margin:18px 0 0;font-size:13px;color:#6B3F18;font-style:italic;
                     padding:12px 16px;background:#FFF8E7;border-left:3px solid #C9A84C;
                     border-radius:2px;'>"
          . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '</p>'
        : '';

    $inner = _emailHeader($siteUrl, $siteName);
    $inner .= "
      <!-- Title bar -->
      <tr>
        <td style='background:#4A0E12;padding:13px 32px;'>
          <p style='margin:0;font-size:12px;color:#FFDE59;
                    letter-spacing:2px;text-transform:uppercase;font-weight:700;'>
            {$title}
          </p>
        </td>
      </tr>
      <!-- Body -->
      <tr>
        <td style='padding:28px 32px;'>
          <table width='100%' cellpadding='0' cellspacing='0'
                 style='border-collapse:collapse;border:1px solid #e8d5b0;border-radius:3px;'>
            {$rowsHtml}
          </table>
          {$noteHtml}
        </td>
      </tr>";
    $inner .= _emailFooter($siteUrl, $siteName);

    return _emailWrap($inner);
}

/**
 * Rich takeaway order confirmation email with items breakdown table.
 * Used for both guest confirmation and owner notification.
 *
 * @param string $heading    e.g. 'Your Order Confirmation' or 'New Takeaway Order'
 * @param string $orderRef   BHM-ORD-...
 * @param string $name       Customer name
 * @param string $phone      Customer phone (or '' for guest email)
 * @param string $email      Customer email (or '' for guest email)
 * @param string $pickupTime HH:MM
 * @param array  $items      [['name'=>..., 'qty'=>..., 'price'=>...], ...]
 * @param float  $total      Order total
 * @param string $notes      Special instructions
 * @param string $note       Footer note / CTA
 * @param bool   $isOwner    Show all fields (owner) vs guest-friendly (customer)
 */
function buildOrderEmail(
    string $heading,
    string $orderRef,
    string $name,
    string $phone,
    string $email,
    string $pickupTime,
    array  $items,
    float  $total,
    string $notes   = '',
    string $note    = '',
    bool   $isOwner = false
): string {
    $siteUrl  = defined('SITE_URL')  ? SITE_URL  : 'https://bhimas.lu';
    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Bhimas Traditional Indian Kitchen';
    $phoneNum = defined('SITE_PHONE') ? SITE_PHONE : '+352 12 34 56 78';

    // Pickup time box
    $pickupHtml = "
      <tr>
        <td style='background:#801C23;padding:16px 32px;text-align:center;'>
          <p style='margin:0;font-size:11px;color:#FFDE59;letter-spacing:2px;
                    text-transform:uppercase;'>Pickup Time</p>
          <p style='margin:4px 0 0;font-size:32px;font-weight:700;color:#ffffff;
                    font-family:Poppins, sans-serif;letter-spacing:2px;'>{$pickupTime}</p>
          <p style='margin:4px 0 0;font-size:12px;color:rgba(255,255,255,0.7);'>Today — please arrive on time</p>
        </td>
      </tr>";

    // Customer info rows
    $infoHtml  = "
          <tr>
            <td style='padding:10px 14px;font-size:13px;font-weight:600;color:#4A0E12;
                       background:#FFF8E7;border-bottom:1px solid #e8d5b0;width:36%;'>Order Ref</td>
            <td style='padding:10px 14px;font-size:13px;color:#801C23;font-weight:700;
                       border-bottom:1px solid #e8d5b0;letter-spacing:0.5px;'>"
            . htmlspecialchars($orderRef, ENT_QUOTES, 'UTF-8') . "</td>
          </tr>
          <tr>
            <td style='padding:10px 14px;font-size:13px;font-weight:600;color:#4A0E12;
                       background:#FFF8E7;border-bottom:1px solid #e8d5b0;'>Name</td>
            <td style='padding:10px 14px;font-size:13px;color:#2C1008;
                       border-bottom:1px solid #e8d5b0;'>"
            . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</td>
          </tr>";
    if ($isOwner) {
        $infoHtml .= "
          <tr>
            <td style='padding:10px 14px;font-size:13px;font-weight:600;color:#4A0E12;
                       background:#FFF8E7;border-bottom:1px solid #e8d5b0;'>Phone</td>
            <td style='padding:10px 14px;font-size:13px;color:#2C1008;
                       border-bottom:1px solid #e8d5b0;'>"
                . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . "</td>
          </tr>
          <tr>
            <td style='padding:10px 14px;font-size:13px;font-weight:600;color:#4A0E12;
                       background:#FFF8E7;border-bottom:1px solid #e8d5b0;'>Email</td>
            <td style='padding:10px 14px;font-size:13px;color:#2C1008;
                       border-bottom:1px solid #e8d5b0;'>"
                . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</td>
          </tr>";
    }

    // Items table
    $itemRowsHtml = '';
    foreach ($items as $item) {
        $iName  = htmlspecialchars($item['name']  ?? '', ENT_QUOTES, 'UTF-8');
        $iQty   = (int)($item['qty']   ?? 1);
        $iPrice = (float)($item['price'] ?? 0);
        $iSub   = number_format($iPrice * $iQty, 2);
        $iUnit  = number_format($iPrice, 2);
        $itemRowsHtml .= "
            <tr>
              <td style='padding:9px 14px;font-size:13px;color:#2C1008;
                         border-bottom:1px solid #f0e6d6;'>{$iName}</td>
              <td style='padding:9px 14px;font-size:13px;color:#6B4226;
                         border-bottom:1px solid #f0e6d6;text-align:center;'>x{$iQty}</td>
              <td style='padding:9px 14px;font-size:12px;color:#888;
                         border-bottom:1px solid #f0e6d6;text-align:right;'>
                &euro;{$iUnit} ea</td>
              <td style='padding:9px 14px;font-size:13px;font-weight:600;color:#4A0E12;
                         border-bottom:1px solid #f0e6d6;text-align:right;'>
                &euro;{$iSub}</td>
            </tr>";
    }

    $notesHtml = ($notes && $notes !== '—')
        ? "<p style='margin:14px 0 0;font-size:13px;color:#6B3F18;font-style:italic;
                     padding:10px 14px;background:#FFF8E7;border-left:3px solid #C9A84C;
                     border-radius:2px;'><strong>Special instructions:</strong> "
          . htmlspecialchars($notes, ENT_QUOTES, 'UTF-8') . "</p>"
        : '';

    $noteBlockHtml = $note
        ? "<p style='margin:18px 0 0;font-size:13px;color:#2C1008;padding:12px 16px;
                     background:#FFF8E7;border-left:3px solid #C9A84C;border-radius:2px;'>"
          . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . "</p>"
        : '';

    $inner  = _emailHeader($siteUrl, $siteName);
    $inner .= "
      <!-- Title bar -->
      <tr>
        <td style='background:#4A0E12;padding:13px 32px;'>
          <p style='margin:0;font-size:12px;color:#FFDE59;
                    letter-spacing:2px;text-transform:uppercase;font-weight:700;'>
            {$heading}
          </p>
        </td>
      </tr>";
    $inner .= $pickupHtml;
    $inner .= "
      <!-- Customer info -->
      <tr>
        <td style='padding:24px 32px 0;'>
          <table width='100%' cellpadding='0' cellspacing='0'
                 style='border-collapse:collapse;border:1px solid #e8d5b0;border-radius:3px;'>
            {$infoHtml}
          </table>
        </td>
      </tr>
      <!-- Items heading -->
      <tr>
        <td style='padding:24px 32px 0;'>
          <p style='margin:0 0 8px;font-size:11px;color:#801C23;letter-spacing:2px;
                    text-transform:uppercase;font-weight:700;'>Your Order</p>
          <table width='100%' cellpadding='0' cellspacing='0'
                 style='border-collapse:collapse;border:1px solid #e8d5b0;border-radius:3px;'>
            <!-- Column headers -->
            <tr style='background:#4A0E12;'>
              <th style='padding:8px 14px;font-size:11px;color:#FFDE59;
                         text-transform:uppercase;letter-spacing:1px;text-align:left;
                         font-weight:600;'>Item</th>
              <th style='padding:8px 14px;font-size:11px;color:#FFDE59;
                         text-transform:uppercase;letter-spacing:1px;text-align:center;
                         font-weight:600;width:40px;'>Qty</th>
              <th style='padding:8px 14px;font-size:11px;color:#FFDE59;
                         text-transform:uppercase;letter-spacing:1px;text-align:right;
                         font-weight:600;width:70px;'>Unit</th>
              <th style='padding:8px 14px;font-size:11px;color:#FFDE59;
                         text-transform:uppercase;letter-spacing:1px;text-align:right;
                         font-weight:600;width:70px;'>Sub-total</th>
            </tr>
            {$itemRowsHtml}
            <!-- Total row -->
            <tr style='background:#FFF8E7;'>
              <td colspan='3' style='padding:12px 14px;font-size:14px;font-weight:700;
                                     color:#4A0E12;border-top:2px solid #C9A84C;'>Total</td>
              <td style='padding:12px 14px;font-size:16px;font-weight:700;color:#801C23;
                         border-top:2px solid #C9A84C;text-align:right;'>
                &euro;" . number_format($total, 2) . "</td>
            </tr>
          </table>
          {$notesHtml}
          {$noteBlockHtml}
        </td>
      </tr>
      <tr><td style='height:28px;'></td></tr>";
    $inner .= _emailFooter($siteUrl, $siteName, $phoneNum);

    return _emailWrap($inner);
}

/**
 * Order status update email (sent from admin when status changes).
 * Compact version with the item breakdown.
 */
function buildOrderStatusEmail(
    string $title,
    string $orderRef,
    string $name,
    string $pickupTime,
    float  $total,
    array  $items = [],
    string $note  = ''
): string {
    return buildOrderEmail(
        $title, $orderRef, $name, '', '', $pickupTime,
        $items, $total, '', $note, false
    );
}

/**
 * Rich table reservation confirmation email.
 *
 * @param string $heading     e.g. 'Your Reservation Request' or 'New Table Reservation'
 * @param string $bookingRef  BHM-RES-...
 * @param string $name        Guest name
 * @param string $phone       Guest phone
 * @param string $email       Guest email
 * @param string $date        e.g. '2026-05-17'
 * @param string $time        e.g. '19:00'
 * @param int    $guests      Guest count
 * @param string $special     Special requests
 * @param string $note        Footer note
 * @param bool   $isOwner     Show all contact details (owner) vs guest view
 */
function buildReservationEmail(
    string $heading,
    string $bookingRef,
    string $name,
    string $phone,
    string $email,
    string $date,
    string $time,
    int    $guests,
    string $special  = '',
    string $note     = '',
    bool   $isOwner  = false
): string {
    $siteUrl  = defined('SITE_URL')  ? SITE_URL  : 'https://bhimas.lu';
    $siteName = defined('SITE_NAME') ? SITE_NAME : 'Bhimas Traditional Indian Kitchen';
    $phoneNum = defined('SITE_PHONE') ? SITE_PHONE : '+352 12 34 56 78';

    // Format the date nicely
    $dateFmt = $date;
    try {
        $dt = new DateTime($date);
        $dateFmt = $dt->format('l, j F Y'); // e.g. "Sunday, 17 May 2026"
    } catch (\Throwable $e) {}

    // Big date/time display
    $dateTimeHtml = "
      <tr>
        <td style='background:#4A0E12;padding:0;'>
          <table width='100%' cellpadding='0' cellspacing='0'>
            <tr>
              <td style='padding:20px 32px;text-align:center;width:50%;
                         border-right:1px solid rgba(255,255,255,0.1);'>
                <p style='margin:0;font-size:10px;color:#FFDE59;letter-spacing:2px;
                           text-transform:uppercase;'>Date</p>
                <p style='margin:6px 0 0;font-size:18px;font-weight:700;color:#ffffff;
                           font-family:Poppins, sans-serif;'>{$dateFmt}</p>
              </td>
              <td style='padding:20px 32px;text-align:center;width:25%;
                         border-right:1px solid rgba(255,255,255,0.1);'>
                <p style='margin:0;font-size:10px;color:#FFDE59;letter-spacing:2px;
                           text-transform:uppercase;'>Time</p>
                <p style='margin:6px 0 0;font-size:28px;font-weight:700;color:#ffffff;
                           font-family:Poppins, sans-serif;'>{$time}</p>
              </td>
              <td style='padding:20px 32px;text-align:center;width:25%;'>
                <p style='margin:0;font-size:10px;color:#FFDE59;letter-spacing:2px;
                           text-transform:uppercase;'>Guests</p>
                <p style='margin:6px 0 0;font-size:28px;font-weight:700;color:#ffffff;
                           font-family:Poppins, sans-serif;'>{$guests}</p>
              </td>
            </tr>
          </table>
        </td>
      </tr>";

    // Info rows
    $infoHtml = "
          <tr>
            <td style='padding:10px 14px;font-size:13px;font-weight:600;color:#4A0E12;
                       background:#FFF8E7;border-bottom:1px solid #e8d5b0;width:38%;'>
              Booking Reference</td>
            <td style='padding:10px 14px;font-size:13px;color:#801C23;font-weight:700;
                       border-bottom:1px solid #e8d5b0;letter-spacing:0.5px;'>"
              . htmlspecialchars($bookingRef, ENT_QUOTES, 'UTF-8') . "</td>
          </tr>
          <tr>
            <td style='padding:10px 14px;font-size:13px;font-weight:600;color:#4A0E12;
                       background:#FFF8E7;border-bottom:1px solid #e8d5b0;'>Name</td>
            <td style='padding:10px 14px;font-size:13px;color:#2C1008;
                       border-bottom:1px solid #e8d5b0;'>"
              . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "</td>
          </tr>";
    if ($isOwner) {
        $infoHtml .= "
          <tr>
            <td style='padding:10px 14px;font-size:13px;font-weight:600;color:#4A0E12;
                       background:#FFF8E7;border-bottom:1px solid #e8d5b0;'>Phone</td>
            <td style='padding:10px 14px;font-size:13px;color:#2C1008;
                       border-bottom:1px solid #e8d5b0;'>"
                . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . "</td>
          </tr>
          <tr>
            <td style='padding:10px 14px;font-size:13px;font-weight:600;color:#4A0E12;
                       background:#FFF8E7;border-bottom:1px solid #e8d5b0;'>Email</td>
            <td style='padding:10px 14px;font-size:13px;color:#2C1008;
                       border-bottom:1px solid #e8d5b0;'>"
                . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "</td>
          </tr>";
    }
    if ($special && $special !== '—') {
        $infoHtml .= "
          <tr>
            <td style='padding:10px 14px;font-size:13px;font-weight:600;color:#4A0E12;
                       background:#FFF8E7;border-bottom:1px solid #e8d5b0;'>Special Requests</td>
            <td style='padding:10px 14px;font-size:13px;color:#2C1008;
                       border-bottom:1px solid #e8d5b0;font-style:italic;'>"
                . htmlspecialchars($special, ENT_QUOTES, 'UTF-8') . "</td>
          </tr>";
    }

    $noteBlockHtml = $note
        ? "<p style='margin:18px 0 0;font-size:13px;color:#2C1008;padding:12px 16px;
                     background:#FFF8E7;border-left:3px solid #C9A84C;border-radius:2px;'>"
          . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . "</p>"
        : '';

    $inner  = _emailHeader($siteUrl, $siteName);
    $inner .= "
      <!-- Title bar -->
      <tr>
        <td style='background:#801C23;padding:13px 32px;'>
          <p style='margin:0;font-size:12px;color:#FFDE59;
                    letter-spacing:2px;text-transform:uppercase;font-weight:700;'>
            {$heading}
          </p>
        </td>
      </tr>";
    $inner .= $dateTimeHtml;
    $inner .= "
      <!-- Booking details -->
      <tr>
        <td style='padding:24px 32px;'>
          <table width='100%' cellpadding='0' cellspacing='0'
                 style='border-collapse:collapse;border:1px solid #e8d5b0;border-radius:3px;'>
            {$infoHtml}
          </table>
          {$noteBlockHtml}
        </td>
      </tr>
      <tr><td style='height:8px;'></td></tr>";
    $inner .= _emailFooter($siteUrl, $siteName, $phoneNum);

    return _emailWrap($inner);
}

/**
 * Resolve the owner notification email address.
 * Reads from site_settings (DB) first, falls back to SITE_EMAIL constant.
 */
function ownerEmail(): string
{
    try {
        $row = dbQueryOne(
            "SELECT setting_value FROM site_settings WHERE setting_key = 'owner_email' LIMIT 1"
        );
        if ($row && !empty($row['setting_value'])) {
            if ($row['setting_value'] === 'hello@bhimas.lu') {
                return 'surendrabandaru09@gmail.com';
            }
            return $row['setting_value'];
        }
    } catch (\Throwable $e) {
        // fall through to constant
    }
    return 'surendrabandaru09@gmail.com';
}

/**
 * Send an HTML email via the Resend API.
 *
 * Uses cURL — no SMTP configuration required.
 * Returns true on success, false (+ error_log) on failure.
 *
 * @param string $to       Recipient email address
 * @param string $subject  Email subject
 * @param string $htmlBody Full HTML email body
 */
function sendEmail(string $to, string $subject, string $htmlBody): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocal = (empty($host) || strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);

    // In production, send from the verified domain email address
    $from = $isLocal 
        ? 'Bhimas Kitchen <onboarding@resend.dev>'
        : 'Bhimas Kitchen <noreply@bhimas.lu>';

    $payload = json_encode([
        'from'    => $from,
        'to'      => [$to],
        'subject' => $subject,
        'html'    => $htmlBody,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlErr) {
        error_log('[Bhimas Resend] cURL error sending to ' . $to . ': ' . $curlErr);
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('[Bhimas Resend] API error (HTTP ' . $httpCode . ') sending to ' . $to . ': ' . $response);
        return false;
    }
    return true;
}
