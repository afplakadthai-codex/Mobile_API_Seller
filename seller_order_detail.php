<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/seller_order_detail.php
// Authenticated seller endpoint: full detail of one seller-owned order.
//
// CRITICAL: Never touches $_SESSION. Never outputs HTML. Never redirects.
// Does NOT interfere with the website session-based login in login.php.
//
// CORS: Not opened broadly. Configure Access-Control-Allow-Origin later
//       when mobile app domain or API gateway is finalized.
// =============================================================================

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'ok'    => false,
        'error' => ['code' => 'method_not_allowed', 'message' => 'Only GET requests are accepted.'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// =============================================================================
// Root path helpers
// File lives at: /public_html/api/mobile/v1/seller_order_detail.php
// dirname(__DIR__, 3) → /public_html
// dirname(__DIR__, 4) → account root (parent of public_html)
// =============================================================================

if (!function_exists('bv_seller_order_detail_public_root')) {
    function bv_seller_order_detail_public_root(): string { return dirname(__DIR__, 3); }
}
if (!function_exists('bv_seller_order_detail_project_root')) {
    function bv_seller_order_detail_project_root(): string { return dirname(bv_seller_order_detail_public_root()); }
}

// =============================================================================
// Helpers
// =============================================================================

if (!function_exists('bv_seller_order_detail_json')) {
    function bv_seller_order_detail_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_seller_order_detail_error')) {
    function bv_seller_order_detail_error(string $code, string $message, int $statusCode = 400): void
    {
        bv_seller_order_detail_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

if (!function_exists('bv_seller_order_detail_log')) {
    function bv_seller_order_detail_log(string $message): void
    {
        error_log('[BV Seller Order Detail] ' . $message);
        $line = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        foreach ([
            bv_seller_order_detail_project_root() . '/private_html/mobile_api.log',
            bv_seller_order_detail_public_root()  . '/logs/mobile_api.log',
        ] as $logFile) {
            $logDir = dirname($logFile);
            if (is_dir($logDir) && is_writable($logDir)) {
                @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
                break;
            }
        }
    }
}

if (!function_exists('bv_seller_order_detail_db')) {
    function bv_seller_order_detail_db(): ?object
    {
        static $cached = false;
        static $connection = null;
        if ($cached !== false) { return $connection; }
        $cached     = true;
        $publicRoot = bv_seller_order_detail_public_root();
        foreach ([
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
            $publicRoot . '/includes/config.php',
        ] as $cfg) {
            if (!is_file($cfg)) { continue; }
            $loader = static function (string $path): array {
                $db_host = $db_user = $db_pass = $db_name = $db_port = null;
                $host = $user = $pass = $name = $port = null;
                $DB_HOST = $DB_USER = $DB_PASS = $DB_NAME = $DB_PORT = null;
                $dsn = null;
                $pdo = $conn = $db = $mysqli = $link = null;
                /** @noinspection PhpIncludeInspection */
                @include $path;
                return compact(
                    'db_host','db_user','db_pass','db_name','db_port',
                    'host','user','pass','name','port',
                    'DB_HOST','DB_USER','DB_PASS','DB_NAME','DB_PORT',
                    'dsn','pdo','conn','db','mysqli','link'
                );
            };
            $vars = $loader($cfg);
            foreach (['pdo','conn','db','mysqli','link'] as $n) {
                if (isset($vars[$n]) && ($vars[$n] instanceof PDO || $vars[$n] instanceof mysqli)) {
                    $connection = $vars[$n]; return $connection;
                }
            }
            foreach (['pdo','conn','db','mysqli','link'] as $gn) {
                if (isset($GLOBALS[$gn])) {
                    $obj = $GLOBALS[$gn];
                    if ($obj instanceof PDO || $obj instanceof mysqli) { $connection = $obj; return $connection; }
                }
            }
            $h  = $vars['db_host'] ?? $vars['host']    ?? $vars['DB_HOST']  ?? null;
            $u  = $vars['db_user'] ?? $vars['user']    ?? $vars['DB_USER']  ?? null;
            $p  = $vars['db_pass'] ?? $vars['pass']    ?? $vars['DB_PASS']  ?? null;
            $n  = $vars['db_name'] ?? $vars['name']    ?? $vars['DB_NAME']  ?? null;
            $pt = (int)($vars['db_port'] ?? $vars['port'] ?? $vars['DB_PORT'] ?? 3306);
            if ($h && $u !== null && $n) {
                try {
                    $pdo = new PDO("mysql:host={$h};port={$pt};dbname={$n};charset=utf8mb4", $u, (string)$p, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]);
                    $connection = $pdo; return $connection;
                } catch (\Throwable $e) { error_log('[BV Seller Order Detail] PDO: ' . $e->getMessage()); }
            }
            if ($h && $u !== null && $n && function_exists('mysqli_connect')) {
                try {
                    $m = @new mysqli($h, (string)$u, (string)$p, $n, $pt ?: 3306);
                    if (!$m->connect_errno) { $m->set_charset('utf8mb4'); $connection = $m; return $connection; }
                    error_log('[BV Seller Order Detail] mysqli: ' . $m->connect_error);
                } catch (\Throwable $e) { error_log('[BV Seller Order Detail] mysqli ex: ' . $e->getMessage()); }
            }
            break;
        }
        return null;
    }
}

if (!function_exists('bv_seller_order_detail_table_exists')) {
    function bv_seller_order_detail_table_exists(object $db, string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) { return false; }
        $sql = "SELECT 1 FROM `{$table}` LIMIT 1";
        try {
            if ($db instanceof PDO) {
                $prev = $db->getAttribute(PDO::ATTR_ERRMODE);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                try { $db->query($sql); $db->setAttribute(PDO::ATTR_ERRMODE, $prev); return true; }
                catch (\PDOException $e) { $db->setAttribute(PDO::ATTR_ERRMODE, $prev); return false; }
            }
            if ($db instanceof mysqli) {
                $res = $db->query($sql);
                if ($res !== false) { if ($res instanceof mysqli_result) { $res->free(); } return true; }
                return false;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Order Detail] table_exists: ' . $e->getMessage()); }
        return false;
    }
}

if (!function_exists('bv_seller_order_detail_columns')) {
    function bv_seller_order_detail_columns(object $db, string $table): array
    {
        $cols = [];
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
                $st->execute();
                foreach ($st->fetchAll() as $row) { $cols[] = strtolower((string)($row['Field'] ?? '')); }
            } elseif ($db instanceof mysqli) {
                $safe = str_replace('`', '', $table);
                $res  = $db->query("SHOW COLUMNS FROM `{$safe}`");
                if ($res) { while ($row = $res->fetch_assoc()) { $cols[] = strtolower((string)($row['Field'] ?? '')); } }
            }
        } catch (\Throwable $e) { error_log('[BV Seller Order Detail] columns: ' . $e->getMessage()); }
        return $cols;
    }
}

if (!function_exists('bv_seller_order_detail_has_col')) {
    function bv_seller_order_detail_has_col(object $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = in_array(strtolower($column), bv_seller_order_detail_columns($db, $table), true);
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_sod_clean')) {
    function bv_sod_clean($value): string
    {
        if ($value === null || $value === false) { return ''; }
        return htmlspecialchars_decode(strip_tags((string)$value), ENT_QUOTES | ENT_HTML5);
    }
}

if (!function_exists('bv_sod_int')) {
    function bv_sod_int($value, int $default = 0): int
    { return is_numeric($value) ? (int)$value : $default; }
}

if (!function_exists('bv_sod_float')) {
    function bv_sod_float($value, float $default = 0.0): float
    { return is_numeric($value) ? (float)$value : $default; }
}

if (!function_exists('bv_sod_asset_url')) {
    function bv_sod_asset_url(?string $path): string
    {
        if (!$path || trim($path) === '') { return ''; }
        $path = trim($path);
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) { return $path; }
        $base = rtrim(defined('BV_SITE_URL') ? BV_SITE_URL : 'https://www.bettavaro.com', '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('bv_sod_listing_url')) {
    function bv_sod_listing_url(?int $id, ?string $slug): string
    {
        $base = rtrim(defined('BV_SITE_URL') ? BV_SITE_URL : 'https://www.bettavaro.com', '/');
        if ($slug && $slug !== '') { return $base . '/listing.php?slug=' . urlencode($slug); }
        if ($id && $id > 0)        { return $base . '/listing.php?id=' . $id; }
        return $base . '/listing.php';
    }
}

if (!function_exists('bv_sod_read_bearer')) {
    function bv_sod_read_bearer(): string
    {
        $header = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION']))               { $header = (string)$_SERVER['HTTP_AUTHORIZATION']; }
        elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']))  { $header = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']; }
        elseif (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strtolower($k) === 'authorization') { $header = (string)$v; break; }
            }
        }
        if ($header === '' || !preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) { return ''; }
        return $m[1];
    }
}

// ── Query helpers ─────────────────────────────────────────────────────────────

if (!function_exists('bv_sod_query_row')) {
    function bv_sod_query_row(object $db, string $sql, array $params = []): ?array
    {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql); $st->execute($params);
                $row = $st->fetch(); return $row ?: null;
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) { return null; }
                if (!empty($params)) {
                    $types = implode('', array_map(static fn($v) => is_int($v)?'i':(is_float($v)?'d':'s'), $params));
                    $ref   = [&$types];
                    foreach ($params as $k => $_) { $ref[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $ref);
                }
                $st->execute(); $res = $st->get_result();
                $row = $res ? $res->fetch_assoc() : null; $st->close();
                return $row ?: null;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Order Detail] query_row: ' . $e->getMessage()); }
        return null;
    }
}

if (!function_exists('bv_sod_query_rows')) {
    function bv_sod_query_rows(object $db, string $sql, array $params = []): array
    {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql); $st->execute($params);
                return $st->fetchAll() ?: [];
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) { return []; }
                if (!empty($params)) {
                    $types = implode('', array_map(static fn($v) => is_int($v)?'i':(is_float($v)?'d':'s'), $params));
                    $ref   = [&$types];
                    foreach ($params as $k => $_) { $ref[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $ref);
                }
                $st->execute(); $res = $st->get_result();
                $rows = [];
                if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
                $st->close(); return $rows;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Order Detail] query_rows: ' . $e->getMessage()); }
        return [];
    }
}

if (!function_exists('bv_sod_execute')) {
    function bv_sod_execute(object $db, string $sql, array $params = []): bool
    {
        try {
            if ($db instanceof PDO) { return $db->prepare($sql)->execute($params); }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) { return false; }
                if (!empty($params)) {
                    $types = implode('', array_map(static fn($v) => is_int($v)?'i':(is_float($v)?'d':'s'), $params));
                    $ref   = [&$types];
                    foreach ($params as $k => $_) { $ref[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $ref);
                }
                $result = $st->execute(); $st->close(); return $result;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Order Detail] execute: ' . $e->getMessage()); }
        return false;
    }
}

// =============================================================================
// Main execution
// =============================================================================
try {

    // ── Auth ──────────────────────────────────────────────────────────────────
    $plainToken = bv_sod_read_bearer();
    if ($plainToken === '') {
        bv_seller_order_detail_error('token_missing', 'Authorization token is required.', 401);
    }
    $tokenHash = hash('sha256', $plainToken);

    $db = bv_seller_order_detail_db();
    if ($db === null) {
        bv_seller_order_detail_log('No database connection.');
        bv_seller_order_detail_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    $tokenRow = bv_sod_query_row(
        $db,
        "SELECT mat.id AS token_id, u.id AS user_id, u.role,
                u.account_status, u.first_name, u.last_name
         FROM mobile_auth_tokens mat
         INNER JOIN users u ON u.id = mat.user_id
         WHERE mat.token_hash = ?
           AND mat.revoked_at IS NULL
           AND mat.expires_at > NOW()
           AND u.account_status = 'active'
         LIMIT 1",
        [$tokenHash]
    );

    if ($tokenRow === null) {
        bv_seller_order_detail_error('token_invalid', 'Token is invalid or has expired.', 401);
    }

    bv_sod_execute($db,
        'UPDATE mobile_auth_tokens SET last_used_at = NOW() WHERE id = ? LIMIT 1',
        [(int)$tokenRow['token_id']]
    );

    $userId    = (int)$tokenRow['user_id'];
    $userRole  = bv_sod_clean($tokenRow['role']       ?? 'user');
    $firstName = bv_sod_clean($tokenRow['first_name'] ?? '');
    $lastName  = bv_sod_clean($tokenRow['last_name']  ?? '');

    if (!in_array($userRole, ['seller', 'admin'], true)) {
        bv_seller_order_detail_error('seller_required', 'Seller access is required.', 403);
    }

    // Admin override
    $filterSellerId = $userId;
    if ($userRole === 'admin') {
        $rawAdminTarget = bv_sod_int($_GET['seller_id'] ?? 0, 0);
        if ($rawAdminTarget > 0) { $filterSellerId = $rawAdminTarget; }
    }

    // ── Parse order identifier ────────────────────────────────────────────────
    $rawId        = bv_sod_int($_GET['id']         ?? ($_GET['order_id'] ?? 0), 0);
    $rawOrderCode = trim((string)($_GET['order_code'] ?? ''));

    if ($rawId <= 0 && $rawOrderCode === '') {
        bv_seller_order_detail_error('order_id_required', 'Order id is required.', 400);
    }

    // ── Verify required tables ────────────────────────────────────────────────
    if (!bv_seller_order_detail_table_exists($db, 'orders')) {
        bv_seller_order_detail_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }
    if (!bv_seller_order_detail_table_exists($db, 'order_items')) {
        bv_seller_order_detail_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // ── Detect columns ────────────────────────────────────────────────────────
    $oCols   = bv_seller_order_detail_columns($db, 'orders');
    $hasOCol = static fn(string $c): bool => in_array(strtolower($c), $oCols, true);

    $oiCols   = bv_seller_order_detail_columns($db, 'order_items');
    $hasOiCol = static fn(string $c): bool => in_array(strtolower($c), $oiCols, true);

    // ── Fetch order row ───────────────────────────────────────────────────────
    $orderSelectCols = ['o.id'];
    foreach (['order_code','status','payment_status','payment_provider','currency','total',
              'buyer_name','buyer_email','buyer_phone','user_id',
              'ship_name','ship_phone','ship_country','ship_province','ship_city',
              'ship_district','ship_subdistrict','ship_postal_code',
              'ship_address_line1','ship_address_line2','ship_address',
              'created_at','paid_at','confirmed_at','processing_at',
              'completed_at','cancelled_at','refunded_at','updated_at'] as $col) {
        if ($hasOCol($col)) { $orderSelectCols[] = "o.`{$col}`"; }
    }

    if ($rawId > 0) {
        $orderRow = bv_sod_query_row(
            $db,
            'SELECT ' . implode(', ', $orderSelectCols) . ' FROM orders o WHERE o.id = ? LIMIT 1',
            [$rawId]
        );
    } else {
        $sanitizedCode = preg_replace('/[^A-Za-z0-9\-_]/', '', $rawOrderCode);
        $orderRow = bv_sod_query_row(
            $db,
            'SELECT ' . implode(', ', $orderSelectCols) . ' FROM orders o WHERE o.order_code = ? LIMIT 1',
            [$sanitizedCode]
        );
    }

    if ($orderRow === null) {
        bv_seller_order_detail_error('order_not_found', 'Order not found.', 404);
    }

    $orderId     = (int)$orderRow['id'];
    $orderStatus = bv_sod_clean($orderRow['status']         ?? '');
    $payStatus   = bv_sod_clean($orderRow['payment_status'] ?? '');

    // ── Determine seller ownership column on order_items ─────────────────────
    $oiSellerCol = null;
    foreach (['seller_id', 'seller_user_id'] as $cand) {
        if ($hasOiCol($cand)) { $oiSellerCol = $cand; break; }
    }

    // Fallback: listings JOIN
    $hasListings     = bv_seller_order_detail_table_exists($db, 'listings');
    $listingOwnerCol = null;
    $listingJoinSQL  = '';
    if ($oiSellerCol === null && $hasListings && $hasOiCol('listing_id')) {
        $lCols = bv_seller_order_detail_columns($db, 'listings');
        foreach (['seller_id','seller_user_id','user_id','owner_user_id'] as $cand) {
            if (in_array($cand, $lCols, true)) { $listingOwnerCol = $cand; break; }
        }
        if ($listingOwnerCol !== null) {
            $listingJoinSQL = " INNER JOIN listings l ON l.id = oi.listing_id AND l.`{$listingOwnerCol}` = ?";
        }
    }

    if ($oiSellerCol === null && $listingOwnerCol === null) {
        bv_seller_order_detail_log('Cannot determine seller ownership column.');
        bv_seller_order_detail_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // ── Verify order contains at least one item owned by seller ───────────────
    if ($oiSellerCol !== null) {
        $ownerCheck = bv_sod_query_row(
            $db,
            "SELECT 1 FROM order_items WHERE order_id = ? AND `{$oiSellerCol}` = ? LIMIT 1",
            [$orderId, $filterSellerId]
        );
    } else {
        $ownerCheck = bv_sod_query_row(
            $db,
            "SELECT 1 FROM order_items oi
             INNER JOIN listings l ON l.id = oi.listing_id AND l.`{$listingOwnerCol}` = ?
             WHERE oi.order_id = ? LIMIT 1",
            [$filterSellerId, $orderId]
        );
    }

    if ($ownerCheck === null) {
        bv_seller_order_detail_error('order_not_found', 'Order not found.', 404);
    }

    // ── Fetch seller-owned items ──────────────────────────────────────────────
    // Build SELECT for order_items, optionally joining listings for cover/slug
    $hasLcols      = $hasListings ? bv_seller_order_detail_columns($db, 'listings') : [];
    $hasLCol       = static fn(string $c): bool => in_array(strtolower($c), $hasLcols, true);
    $lCoverCol     = null;
    foreach (['cover_image','image','image_path','main_image','thumbnail'] as $cand) {
        if ($hasLCol($cand)) { $lCoverCol = $cand; break; }
    }
    $lHasSlug      = $hasLCol('slug');
    $lHasTitle     = $hasLCol('title');

    $itemSelectCols = ['oi.id', 'oi.order_id'];
    foreach (['listing_id','title_snapshot','item_title','quantity','qty',
              'unit_price','line_total','currency',
              'fulfillment_status','tracking_number','carrier',
              'processed_at','shipped_at','completed_at'] as $col) {
        if ($hasOiCol($col)) { $itemSelectCols[] = "oi.`{$col}`"; }
    }
    if ($hasListings && $hasOiCol('listing_id')) {
        if ($lHasTitle)  { $itemSelectCols[] = 'l.title AS listing_title_db'; }
        if ($lHasSlug)   { $itemSelectCols[] = 'l.slug AS listing_slug'; }
        if ($lCoverCol)  { $itemSelectCols[] = "l.`{$lCoverCol}` AS listing_cover"; }
        $itemJoinStr = ' LEFT JOIN listings l ON l.id = oi.listing_id';
    } else {
        $itemJoinStr = '';
    }

    if ($oiSellerCol !== null) {
        $itemRows = bv_sod_query_rows(
            $db,
            'SELECT ' . implode(', ', $itemSelectCols)
            . " FROM order_items oi{$itemJoinStr}"
            . " WHERE oi.order_id = ? AND oi.`{$oiSellerCol}` = ?"
            . ' ORDER BY oi.id ASC',
            [$orderId, $filterSellerId]
        );
    } else {
        // listing JOIN ownership path
        $itemRows = bv_sod_query_rows(
            $db,
            'SELECT ' . implode(', ', $itemSelectCols)
            . " FROM order_items oi{$itemJoinStr}"
            . " WHERE oi.order_id = ? AND EXISTS ("
            . "   SELECT 1 FROM listings lx WHERE lx.id = oi.listing_id AND lx.`{$listingOwnerCol}` = ?"
            . ' ) ORDER BY oi.id ASC',
            [$orderId, $filterSellerId]
        );
    }

    // ── Derive seller subtotal and format items ───────────────────────────────
    $sellerSubtotal = 0.0;
    $formattedItems = [];
    $fulfillmentStatuses = [];

    foreach ($itemRows as $ir) {
        // Line total: prefer line_total, else qty * unit_price
        $lt = bv_sod_float($ir['line_total'] ?? null, -1.0);
        if ($lt < 0) {
            $qty = bv_sod_int($ir['quantity'] ?? ($ir['qty'] ?? 1), 1);
            $up  = bv_sod_float($ir['unit_price'] ?? ($ir['price'] ?? 0), 0.0);
            $lt  = $qty * $up;
        }
        $sellerSubtotal += $lt;

        $fStatus = bv_sod_clean($ir['fulfillment_status'] ?? '');
        if ($fStatus !== '') { $fulfillmentStatuses[] = $fStatus; }

        // Title: prefer title_snapshot, then item_title, then listing_title_db
        $title = bv_sod_clean(
            $ir['title_snapshot'] ?? ($ir['item_title'] ?? ($ir['listing_title_db'] ?? ''))
        );
        $listingTitle = bv_sod_clean($ir['listing_title_db'] ?? '');
        $listingId    = bv_sod_int($ir['listing_id'] ?? 0);
        $slug         = bv_sod_clean($ir['listing_slug'] ?? '');
        $cover        = bv_sod_asset_url(bv_sod_clean($ir['listing_cover'] ?? ''));

        // Derive available_actions for mobile UI based on normalized fulfillment_status.
        // Only expose valid forward transitions — never backwards.
        $normalizedFStatus = strtolower($fStatus);
        $availableActions = match($normalizedFStatus) {
            'pending', ''  => ['process'],
            'processing'   => ['ship'],
            'shipped'      => ['complete'],
            default        => [],   // completed, cancelled, refunded, unknown
        };

        $formattedItems[] = [
            'id'               => bv_sod_int($ir['id']),
            'listing_id'       => $listingId > 0 ? $listingId : null,
            'title'            => $title,
            'listing_title'    => $listingTitle,
            'listing_url'      => bv_sod_listing_url($listingId ?: null, $slug ?: null),
            'cover_image'      => $cover,
            'quantity'         => bv_sod_int($ir['quantity'] ?? ($ir['qty'] ?? 1), 1),
            'unit_price'       => round(bv_sod_float($ir['unit_price'] ?? 0), 2),
            'line_total'       => round($lt, 2),
            'currency'         => bv_sod_clean($ir['currency'] ?? 'USD'),
            'fulfillment_status' => $fStatus,
            'available_actions'  => $availableActions,
            'tracking_number'  => bv_sod_clean($ir['tracking_number'] ?? ''),
            'carrier'          => bv_sod_clean($ir['carrier']         ?? ''),
            'processed_at'     => bv_sod_clean($ir['processed_at']    ?? ''),
            'shipped_at'       => bv_sod_clean($ir['shipped_at']      ?? ''),
            'completed_at'     => bv_sod_clean($ir['completed_at']    ?? ''),
        ];
    }

    // ── Derive fulfillment status ─────────────────────────────────────────────
    // Guard: if order not paid, don't show shipped/completed
    $paidStatuses = ['paid', 'paid-awaiting-verify', 'confirmed', 'processing',
                     'packing', 'shipped', 'completed', 'refunded'];
    $orderIsPaid  = in_array($orderStatus, $paidStatuses, true)
                    || $payStatus === 'paid';

    $deriveFulfillment = static function (array $statuses, string $oStatus, bool $isPaid): array {
        if (empty($statuses)) {
            if (!$isPaid) { return ['status' => 'pending',  'label' => 'To Ship']; }
            return match($oStatus) {
                'shipped'   => ['status' => 'shipped',   'label' => 'Shipped'],
                'completed' => ['status' => 'completed', 'label' => 'Completed'],
                'paid','confirmed','processing','packing' => ['status' => 'pending', 'label' => 'To Ship'],
                default     => ['status' => 'unknown',   'label' => 'Unknown'],
            };
        }
        $unique        = array_unique($statuses);
        $hasShipped    = in_array('shipped',    $statuses, true);
        $hasCompleted  = in_array('completed',  $statuses, true);
        $hasProcessing = in_array('processing', $statuses, true);
        $hasPending    = in_array('pending',    $statuses, true) || in_array('', $statuses, true);

        if (!$isPaid) { return ['status' => 'pending', 'label' => 'To Ship']; }
        if (count($unique) === 1) {
            return match($unique[0]) {
                'completed'  => ['status' => 'completed',  'label' => 'Completed'],
                'shipped'    => ['status' => 'shipped',    'label' => 'Shipped'],
                'processing' => ['status' => 'processing', 'label' => 'Preparing'],
                default      => ['status' => 'pending',    'label' => 'To Ship'],
            };
        }
        if (($hasShipped || $hasCompleted) && ($hasPending || $hasProcessing)) {
            return ['status' => 'mixed', 'label' => 'Mixed'];
        }
        if ($hasShipped && $hasCompleted) { return ['status' => 'mixed', 'label' => 'Mixed']; }
        if ($hasProcessing) { return ['status' => 'processing', 'label' => 'Preparing']; }
        return ['status' => 'pending', 'label' => 'To Ship'];
    };

    $fulfillmentResult = $deriveFulfillment($fulfillmentStatuses, $orderStatus, $orderIsPaid);

    // ── Refunds ───────────────────────────────────────────────────────────────
    $refunds      = [];
    $hasRefunds   = bv_seller_order_detail_table_exists($db, 'order_refunds');
    $hasRefItems  = $hasRefunds && bv_seller_order_detail_table_exists($db, 'order_refund_items');

    if ($hasRefunds) {
        $rCols    = bv_seller_order_detail_columns($db, 'order_refunds');
        $hasRCol  = static fn(string $c): bool => in_array($c, $rCols, true);

        $refSelectCols = ['r.id', 'r.order_id'];
        foreach (['refund_code','status','requested_refund_amount','approved_refund_amount',
                  'actual_refunded_amount','created_at','updated_at'] as $c) {
            if ($hasRCol($c)) { $refSelectCols[] = "r.`{$c}`"; }
        }

        if ($hasRefItems && $oiSellerCol !== null) {
            // Seller-scoped refunds via order_refund_items → order_items.seller_id
            $refRows = bv_sod_query_rows(
                $db,
                'SELECT DISTINCT ' . implode(', ', $refSelectCols)
                . ' FROM order_refunds r'
                . ' INNER JOIN order_refund_items ri ON ri.refund_id = r.id'
                . ' INNER JOIN order_items oi ON oi.id = ri.order_item_id'
                . " WHERE r.order_id = ? AND oi.`{$oiSellerCol}` = ?"
                . ' ORDER BY r.id DESC',
                [$orderId, $filterSellerId]
            );
        } elseif ($hasRefItems && $listingOwnerCol !== null) {
            // Seller-scoped via listings
            $refRows = bv_sod_query_rows(
                $db,
                'SELECT DISTINCT ' . implode(', ', $refSelectCols)
                . ' FROM order_refunds r'
                . ' INNER JOIN order_refund_items ri ON ri.refund_id = r.id'
                . " INNER JOIN listings lref ON lref.id = ri.listing_id AND lref.`{$listingOwnerCol}` = ?"
                . ' WHERE r.order_id = ?'
                . ' ORDER BY r.id DESC',
                [$filterSellerId, $orderId]
            );
        } else {
            // Fallback: order-level refunds
            $refRows = bv_sod_query_rows(
                $db,
                'SELECT ' . implode(', ', $refSelectCols)
                . ' FROM order_refunds r WHERE r.order_id = ? ORDER BY r.id DESC',
                [$orderId]
            );
        }

        foreach ($refRows as $rr) {
            $refunds[] = [
                'id'                      => bv_sod_int($rr['id']),
                'refund_code'             => bv_sod_clean($rr['refund_code']             ?? ''),
                'status'                  => bv_sod_clean($rr['status']                  ?? ''),
                'requested_refund_amount' => round(bv_sod_float($rr['requested_refund_amount'] ?? 0), 2),
                'approved_refund_amount'  => round(bv_sod_float($rr['approved_refund_amount']  ?? 0), 2),
                'actual_refunded_amount'  => round(bv_sod_float($rr['actual_refunded_amount']  ?? 0), 2),
                'created_at'              => bv_sod_clean($rr['created_at'] ?? ''),
                'updated_at'              => bv_sod_clean($rr['updated_at'] ?? ''),
            ];
        }
    }

    // ── Cancellations ─────────────────────────────────────────────────────────
    $cancellations     = [];
    $hasCancellations  = bv_seller_order_detail_table_exists($db, 'order_cancellations');
    $hasCancelItems    = $hasCancellations && bv_seller_order_detail_table_exists($db, 'order_cancellation_items');

    if ($hasCancellations) {
        $cCols   = bv_seller_order_detail_columns($db, 'order_cancellations');
        $hasCCol = static fn(string $c): bool => in_array($c, $cCols, true);

        $canSelectCols = ['oc.id', 'oc.order_id'];
        foreach (['status','cancel_reason_text','cancel_reason_code','refund_status',
                  'requested_at','approved_at','rejected_at','created_at','updated_at'] as $c) {
            if ($hasCCol($c)) { $canSelectCols[] = "oc.`{$c}`"; }
        }

        if ($hasCancelItems) {
            // order_cancellation_items has seller_user_id directly
            $ciCols    = bv_seller_order_detail_columns($db, 'order_cancellation_items');
            $hasCiCol  = static fn(string $c): bool => in_array($c, $ciCols, true);
            $ciSellerCol = $hasCiCol('seller_user_id') ? 'seller_user_id' : null;

            if ($ciSellerCol !== null) {
                $cancelRows = bv_sod_query_rows(
                    $db,
                    'SELECT DISTINCT ' . implode(', ', $canSelectCols)
                    . ' FROM order_cancellations oc'
                    . ' INNER JOIN order_cancellation_items oci ON oci.cancellation_id = oc.id'
                    . " WHERE oc.order_id = ? AND oci.`{$ciSellerCol}` = ?"
                    . ' ORDER BY oc.id DESC',
                    [$orderId, $filterSellerId]
                );
                $scope = 'seller_slice';
            } else {
                $cancelRows = bv_sod_query_rows(
                    $db,
                    'SELECT ' . implode(', ', $canSelectCols)
                    . ' FROM order_cancellations oc WHERE oc.order_id = ? ORDER BY oc.id DESC',
                    [$orderId]
                );
                $scope = 'order_level';
            }
        } else {
            $cancelRows = bv_sod_query_rows(
                $db,
                'SELECT ' . implode(', ', $canSelectCols)
                . ' FROM order_cancellations oc WHERE oc.order_id = ? ORDER BY oc.id DESC',
                [$orderId]
            );
            $scope = 'order_level';
        }

        foreach ($cancelRows as $cr) {
            $reason = bv_sod_clean($cr['cancel_reason_text'] ?? ($cr['cancel_reason_code'] ?? ''));
            $cancellations[] = [
                'id'           => bv_sod_int($cr['id']),
                'status'       => bv_sod_clean($cr['status']        ?? ''),
                'reason'       => $reason,
                'refund_status'=> bv_sod_clean($cr['refund_status'] ?? ''),
                'scope'        => $scope,
                'created_at'   => bv_sod_clean($cr['requested_at'] ?? ($cr['created_at'] ?? '')),
                'updated_at'   => bv_sod_clean($cr['updated_at']   ?? ''),
            ];
        }
    }

    // ── Timeline ──────────────────────────────────────────────────────────────
    $tNull = static fn($v): ?string => ($v !== null && $v !== '' && $v !== '0000-00-00 00:00:00') ? bv_sod_clean($v) : null;

    $timeline = [];
    $addEvent = static function (string $key, string $label, ?string $at) use (&$timeline): void {
        $timeline[] = [
            'key'    => $key,
            'label'  => $label,
            'at'     => $at ?? '',
            'status' => $at ? 'done' : 'pending',
        ];
    };


    // Use fulfillment data for shipped/processed if available from seller-owned items
    $anyShipped    = in_array('shipped',   $fulfillmentStatuses, true);
    $anyCompleted  = in_array('completed', $fulfillmentStatuses, true);
    $firstProcessedAt = null;
    $firstShippedAt   = null;
    $firstCompletedAt = null;
    foreach ($formattedItems as $fi) {
        if ($fi['processed_at']  !== '' && $firstProcessedAt  === null) { $firstProcessedAt  = $fi['processed_at'];  }
        if ($fi['shipped_at']    !== '' && $firstShippedAt    === null) { $firstShippedAt    = $fi['shipped_at'];    }
        if ($fi['completed_at']  !== '' && $firstCompletedAt  === null) { $firstCompletedAt  = $fi['completed_at']; }
    }

    // Derive seller_processing timeline from item-level processed_at.
    // Falls back to order-level processing_at if no item timestamp found.
    $sellerProcessingAt = null;
    try {
        $sellerProcessingAt = $tNull($firstProcessedAt ?? null)
                           ?? $tNull($orderRow['processing_at'] ?? null);
    } catch (\Throwable $tpErr) {
        bv_seller_order_detail_log('seller_processing_timeline_derive_failed: ' . $tpErr->getMessage());
    }

    $addEvent('order_created',    'Order Created',     $tNull($orderRow['created_at']   ?? null));
    $addEvent('paid',             'Payment Received',  $tNull($orderRow['paid_at']      ?? null));
    $addEvent('seller_processing','Seller Processing', $sellerProcessingAt);
    $addEvent('shipped',          'Shipped',           null); // placeholder, overridden below

    // Replace shipped/completed events with item-level timestamps where available
    foreach ($timeline as &$te) {
        if ($te['key'] === 'shipped') {
            if ($anyShipped || $firstShippedAt) {
                $te['at']     = $firstShippedAt ?? '';
                $te['status'] = 'done';
            } else {
                $te['at']     = '';
                $te['status'] = 'pending';
            }
        }
    }
    unset($te);

    $addEvent('completed', 'Completed', $tNull($orderRow['completed_at'] ?? null) ?? ($firstCompletedAt ?: null));

    // Refund/cancellation timeline entries from live data
    if (!empty($refunds)) {
        $refCreated = $refunds[0]['created_at'] ?? null;
        $addEvent('refund_requested', 'Refund Requested', $refCreated ?: null);
        $refActual  = $refunds[0]['actual_refunded_amount'] ?? 0;
        $addEvent('refunded', 'Refunded', ($refActual > 0 && $refCreated) ? $refCreated : null);
    }
    if (!empty($cancellations)) {
        $canAt = $cancellations[0]['created_at'] ?? null;
        $addEvent('cancelled', 'Cancelled', $tNull($orderRow['cancelled_at'] ?? null) ?? ($canAt ?: null));
    }

    // ── Buyer ─────────────────────────────────────────────────────────────────
    $buyerUserId = bv_sod_int($orderRow['user_id'] ?? 0);
    $buyerName   = bv_sod_clean($orderRow['buyer_name']  ?? '');
    $buyerEmail  = bv_sod_clean($orderRow['buyer_email'] ?? '');

    if ($buyerUserId > 0) {
        $hasUsers = bv_seller_order_detail_table_exists($db, 'users');
        if ($hasUsers && ($buyerName === '' || $buyerEmail === '')) {
            $buyerRow = bv_sod_query_row(
                $db,
                'SELECT first_name, last_name, email FROM users WHERE id = ? LIMIT 1',
                [$buyerUserId]
            );
            if ($buyerRow) {
                if ($buyerName  === '') { $buyerName  = trim(bv_sod_clean($buyerRow['first_name'] ?? '') . ' ' . bv_sod_clean($buyerRow['last_name'] ?? '')); }
                if ($buyerEmail === '') { $buyerEmail = bv_sod_clean($buyerRow['email'] ?? ''); }
            }
        }
    }

    // ── Seller identity ───────────────────────────────────────────────────────
    $sellerName = trim($firstName . ' ' . $lastName);
    if ($sellerName === '' && $filterSellerId !== $userId) {
        $snRow = bv_sod_query_row($db, 'SELECT first_name, last_name FROM users WHERE id = ? LIMIT 1', [$filterSellerId]);
        if ($snRow) {
            $sellerName = trim(bv_sod_clean($snRow['first_name'] ?? '') . ' ' . bv_sod_clean($snRow['last_name'] ?? ''));
        }
    }

    // ── Shipping ──────────────────────────────────────────────────────────────
    $shipping = [
        'name'         => bv_sod_clean($orderRow['ship_name']         ?? ''),
        'phone'        => bv_sod_clean($orderRow['ship_phone']        ?? ''),
        'country'      => bv_sod_clean($orderRow['ship_country']      ?? ''),
        'province'     => bv_sod_clean($orderRow['ship_province']     ?? ''),
        'city'         => bv_sod_clean($orderRow['ship_city']         ?? ($orderRow['ship_district'] ?? '')),
        'district'     => bv_sod_clean($orderRow['ship_district']     ?? ($orderRow['ship_subdistrict'] ?? '')),
        'postal_code'  => bv_sod_clean($orderRow['ship_postal_code']  ?? ''),
        'address_line1'=> bv_sod_clean($orderRow['ship_address_line1']?? ($orderRow['ship_address'] ?? '')),
        'address_line2'=> bv_sod_clean($orderRow['ship_address_line2']?? ''),
    ];

    // ── Build response ────────────────────────────────────────────────────────
    bv_seller_order_detail_json(200, [
        'ok'   => true,
        'data' => [
            'seller' => [
                'id'   => $filterSellerId,
                'name' => $sellerName ?: "Seller #{$filterSellerId}",
                'role' => $userRole,
            ],
            'order' => [
                'id'                => $orderId,
                'order_code'        => bv_sod_clean($orderRow['order_code']       ?? ''),
                'status'            => $orderStatus,
                'payment_status'    => $payStatus,
                'payment_provider'  => bv_sod_clean($orderRow['payment_provider'] ?? ''),
                'currency'          => bv_sod_clean($orderRow['currency']         ?? 'USD'),
                'order_total'       => round(bv_sod_float($orderRow['total']      ?? 0), 2),
                'seller_subtotal'   => round($sellerSubtotal, 2),
                'seller_item_count' => count($formattedItems),
                'created_at'        => bv_sod_clean($orderRow['created_at']       ?? ''),
                'paid_at'           => bv_sod_clean($orderRow['paid_at']          ?? ''),
                'updated_at'        => bv_sod_clean($orderRow['updated_at']       ?? ''),
            ],
            'buyer'         => [
                'id'    => $buyerUserId > 0 ? $buyerUserId : null,
                'name'  => $buyerName,
                'email' => $buyerEmail,
            ],
            'shipping'      => $shipping,
            'items'         => $formattedItems,
            'fulfillment'   => $fulfillmentResult,
            'refunds'       => $refunds,
            'cancellations' => $cancellations,
            'timeline'      => $timeline,
        ],
        'meta' => [
            'api_version'  => 'v1',
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);

} catch (\Throwable $e) {
    bv_seller_order_detail_log('Unhandled exception: ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_seller_order_detail_json(500, [
        'ok'    => false,
        'error' => ['code' => 'server_error', 'message' => 'Something went wrong.'],
    ]);
}
