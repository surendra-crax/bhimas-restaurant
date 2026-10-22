<?php
/**
 * BHIMAS TRADITIONAL INDIAN KITCHEN
 * php/get-settings.php
 *
 * Returns public site settings as JSON, including real-time
 * open/closed status derived from per-day hours in the DB.
 *
 * GET /php/get-settings.php
 * Response: {
 *   success:      true,
 *   settings:     { ... all public keys ... },
 *   is_open_now:  bool,
 *   todays_hours: "11:00 – 22:00",
 *   next_open:    "Tomorrow 11:00",
 *   server_time:  "14:35",
 *   day_name:     "Wednesday"
 * }
 */

if (!defined('BHIMAS_LOADED')) { define('BHIMAS_LOADED', true); }
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, 'Method not allowed');
}

// ── Day helpers ──────────────────────────────────────────────
$DAY_KEYS   = ['sun','mon','tue','wed','thu','fri','sat'];
$DAY_LABELS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

/**
 * Parse "HH:MM-HH:MM" hours string.
 * Returns assoc array or null if "closed" / malformed.
 */
function parseHoursString(string $str): ?array
{
    $str = trim(strtolower($str));
    if ($str === '' || $str === 'closed') {
        return null;
    }
    $parts = explode('-', $str, 2);
    if (count($parts) !== 2) {
        return null;
    }
    $toMin = static function (string $t): int {
        $p = explode(':', trim($t), 2);
        return (int)$p[0] * 60 + (int)($p[1] ?? 0);
    };
    return [
        'open'      => $toMin($parts[0]),
        'close'     => $toMin($parts[1]),
        'open_str'  => trim($parts[0]),
        'close_str' => trim($parts[1]),
    ];
}

try {
    // ── 1. Load public settings from DB ─────────────────────
    $settings = [];
    try {
        $rows = dbQuery("SELECT setting_key, setting_value FROM site_settings WHERE is_public = 1");
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    } catch (\Throwable $e) {
        // Table not yet created — continue with defaults
        error_log('[Bhimas get-settings] DB query failed: ' . $e->getMessage());
    }

    // ── 2. Defaults (always present, DB values take priority) ─
    $defaults = [
        'restaurant_name'                    => SITE_NAME,
        'phone'                              => '+352 12 34 56 78',
        'email'                              => SITE_EMAIL,
        'address_line1'                      => '123 Rue de la Gare',
        'address_line2'                      => '',
        'city'                               => 'Luxembourg City',
        'postcode'                           => 'L-1616',
        'reservation_enabled'                => '1',
        'takeaway_enabled'                   => '1',
        'hours_sun'                          => '12:00-21:00',
        'hours_mon'                          => '11:00-22:00',
        'hours_tue'                          => '11:00-22:00',
        'hours_wed'                          => '11:00-22:00',
        'hours_thu'                          => '11:00-22:00',
        'hours_fri'                          => '11:00-23:00',
        'hours_sat'                          => '11:00-23:00',
        'slot_interval_mins'                 => '30',
        'pickup_interval_mins'               => '15',
        'last_reservation_mins_before_close' => '60',
        'last_pickup_mins_before_close'      => '30',
        'min_takeaway_minutes'               => '60',
        'min_order_amount'                   => '10.00',
        'currency_symbol'                    => '€',
        'currency_code'                      => 'EUR',
        'open_time'                          => '11:00',
        'close_time'                         => '22:00',
        'open_time_weekend'                  => '11:00',
        'close_time_weekend'                 => '23:00',
        'max_party_size'                     => '20',
    ];

    $merged = array_merge($defaults, $settings);

    // ── 3. Current time in restaurant timezone ────────────────
    $tz  = new DateTimeZone('Europe/Luxembourg');
    $now = new DateTime('now', $tz);

    $todayDow   = (int) $now->format('w');                    // 0=Sun … 6=Sat
    $nowMinutes = (int) $now->format('G') * 60 + (int) $now->format('i');

    $todayKey      = $DAY_KEYS[$todayDow];
    $todayRaw      = $merged['hours_' . $todayKey] ?? 'closed';
    $todayParsed   = parseHoursString($todayRaw);

    // ── 4. Is open right now? ─────────────────────────────────
    $isOpenNow   = false;
    $todaysHours = 'Closed today';

    if ($todayParsed !== null) {
        $todaysHours = $todayParsed['open_str'] . ' – ' . $todayParsed['close_str'];
        $isOpenNow   = ($nowMinutes >= $todayParsed['open'] && $nowMinutes < $todayParsed['close']);
    }

    // ── 5. Next open time string ──────────────────────────────
    $nextOpen = '';
    if (!$isOpenNow) {
        // Still today, just before opening?
        if ($todayParsed !== null && $nowMinutes < $todayParsed['open']) {
            $nextOpen = 'Today ' . $todayParsed['open_str'];
        } else {
            // Search forward up to 7 days
            for ($offset = 1; $offset <= 7; $offset++) {
                $nextDow    = ($todayDow + $offset) % 7;
                $nextKey    = $DAY_KEYS[$nextDow];
                $nextParsed = parseHoursString($merged['hours_' . $nextKey] ?? 'closed');
                if ($nextParsed !== null) {
                    $label    = ($offset === 1) ? 'Tomorrow' : $DAY_LABELS[$nextDow];
                    $nextOpen = $label . ' ' . $nextParsed['open_str'];
                    break;
                }
            }
        }
    }

    // ── 6. Override generic open/close aliases with today's real hours ─
    // JS slot generators use open_time / close_time; sync them to the DB.
    if ($todayParsed !== null) {
        $merged['open_time']  = $todayParsed['open_str'];
        $merged['close_time'] = $todayParsed['close_str'];
    }
    // Fri/Sat weekend alias
    if (($todayDow === 5 || $todayDow === 6) && $todayParsed !== null) {
        $merged['open_time_weekend']  = $todayParsed['open_str'];
        $merged['close_time_weekend'] = $todayParsed['close_str'];
    }

    // ── 7. Respond ────────────────────────────────────────────
    jsonResponse(200, [
        'settings'     => $merged,
        'is_open_now'  => $isOpenNow,
        'todays_hours' => $todaysHours,
        'next_open'    => $nextOpen,
        'server_time'  => $now->format('H:i'),
        'day_name'     => $DAY_LABELS[$todayDow],
    ]);

} catch (\Throwable $e) {
    if (BHIMAS_DEBUG) {
        jsonResponse(500, 'Settings error: ' . $e->getMessage());
    }
    error_log('[Bhimas get-settings] ' . $e->getMessage());
    jsonResponse(500, 'Unable to load settings.');
}
