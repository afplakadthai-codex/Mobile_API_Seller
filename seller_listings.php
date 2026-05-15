<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/seller_listings.php
// Authenticated seller endpoint: returns listings owned by the token holder.
//
// PATCH LOG (mobile-v1 spec alignment):
//  - Added CORS headers + OPTIONS 204 preflight
//  - Param: limit→per_page (max 100), q→search
//  - Error code: listings_table_missing (was db_unavailable)
//  - Status filter: inactive→hidden mapping
//  - Listing fields renamed: cover_image→image_url, sex→gender
//  - Added views/favorites (0, no DB col), stock_quantity (flat int)
//  - avg_rating / sold_count sourced from listing_ranking_cache
//  - Response: items→listings, stats→summary, added filters key
//  - Summary keys: {total, active, draft, sold, inactive} (inactive=hidden)
//  - meta.api_version = "mobile-v1"
//
// CRITICAL: Never touches $_SESSION. Never outputs HTML. Never redirects.
// Does NOT interfere with the website session-based login in login.php.
// =============================================================================

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

// ── OPTIONS preflight ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

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
// File lives at: /public_html/api/mobile/v1/seller_listings.php
// dirname(__DIR__, 3) → /public_html
// dirname(__DIR__, 4) → account root (parent of public_html)
// =============================================================================

if (!function_exists('bv_seller_listings_public_root')) {
    function bv_seller_listings_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_seller_listings_project_root')) {
    function bv_seller_listings_project_root(): string
    {
        return dirname(bv_seller_listings_public_root());
    }
}

// =============================================================================
// Helpers
// =============================================================================

if (!function_exists('bv_seller_listings_json')) {
    function bv_seller_listings_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_seller_listings_error')) {
    function bv_seller_listings_error(string $code, string $message, int $statusCode = 400): void
    {
        bv_seller_listings_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

if (!function_exists('bv_seller_listings_log')) {
    function bv_seller_listings_log(string $message): void
    {
        error_log('[BV Seller Listings] ' . $message);
        $line          = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        $logCandidates = [
            bv_seller_listings_project_root() . '/private_html/mobile_api.log',
            bv_seller_listings_public_root()  . '/logs/mobile_api.log',
        ];
        foreach ($logCandidates as $logFile) {
            $logDir = dirname($logFile);
            if (is_dir($logDir) && is_writable($logDir)) {
                @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
                break;
            }
        }
    }
}

if (!function_exists('bv_seller_listings_db')) {
    function bv_seller_listings_db(): ?object
    {
        static $cached = false;
        static $connection = null;
        if ($cached !== false) {
            return $connection;
        }
        $cached     = true;
        $publicRoot = bv_seller_listings_public_root();
        $candidates = [
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
            $publicRoot . '/includes/config.php',
        ];
        foreach ($candidates as $cfg) {
            if (!is_file($cfg)) {
                continue;
            }
            $loader = static function (string $path): array {
                $db_host = $db_user = $db_pass = $db_name = $db_port = null;
                $host = $user = $pass = $name = $port = null;
                $DB_HOST = $DB_USER = $DB_PASS = $DB_NAME = $DB_PORT = null;
                $dsn = null;
                $pdo = $conn = $db = $mysqli = $link = null;
                /** @noinspection PhpIncludeInspection */
                @include $path;
                return compact(
                    'db_host', 'db_user', 'db_pass', 'db_name', 'db_port',
                    'host', 'user', 'pass', 'name', 'port',
                    'DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'DB_PORT',
                    'dsn', 'pdo', 'conn', 'db', 'mysqli', 'link'
                );
            };
            $vars = $loader($cfg);

            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $n) {
                if (isset($vars[$n]) && ($vars[$n] instanceof PDO || $vars[$n] instanceof mysqli)) {
                    $connection = $vars[$n];
                    return $connection;
                }
            }
            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $gn) {
                if (isset($GLOBALS[$gn])) {
                    $obj = $GLOBALS[$gn];
                    if ($obj instanceof PDO || $obj instanceof mysqli) {
                        $connection = $obj;
                        return $connection;
                    }
                }
            }

            $h  = $vars['db_host'] ?? $vars['host']    ?? $vars['DB_HOST']  ?? null;
            $u  = $vars['db_user'] ?? $vars['user']    ?? $vars['DB_USER']  ?? null;
            $p  = $vars['db_pass'] ?? $vars['pass']    ?? $vars['DB_PASS']  ?? null;
            $n  = $vars['db_name'] ?? $vars['name']    ?? $vars['DB_NAME']  ?? null;
            $pt = (int) ($vars['db_port'] ?? $vars['port'] ?? $vars['DB_PORT'] ?? 3306);

            if ($h && $u !== null && $n) {
                try {
                    $pdo = new PDO(
                        "mysql:host={$h};port={$pt};dbname={$n};charset=utf8mb4",
                        $u,
                        (string) $p,
                        [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES   => false,
                        ]
                    );
                    $connection = $pdo;
                    return $connection;
                } catch (\Throwable $e) {
                    error_log('[BV Seller Listings] PDO connect failed: ' . $e->getMessage());
                }
            }
            if ($h && $u !== null && $n && function_exists('mysqli_connect')) {
                try {
                    $m = @new mysqli($h, (string) $u, (string) $p, $n, $pt ?: 3306);
                    if (!$m->connect_errno) {
                        $m->set_charset('utf8mb4');
                        $connection = $m;
                        return $connection;
                    }
                    error_log('[BV Seller Listings] mysqli connect failed: ' . $m->connect_error);
                } catch (\Throwable $e) {
                    error_log('[BV Seller Listings] mysqli exception: ' . $e->getMessage());
                }
            }
            break;
        }
        return null;
    }
}

if (!function_exists('bv_seller_listings_table_exists')) {
    function bv_seller_listings_table_exists(object $db, string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }
        $sql = "SELECT 1 FROM `{$table}` LIMIT 1";
        try {
            if ($db instanceof PDO) {
                $prev = $db->getAttribute(PDO::ATTR_ERRMODE);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                try {
                    $db->query($sql);
                    $db->setAttribute(PDO::ATTR_ERRMODE, $prev);
                    return true;
                } catch (\PDOException $e) {
                    $db->setAttribute(PDO::ATTR_ERRMODE, $prev);
                    return false;
                }
            }
            if ($db instanceof mysqli) {
                $res = $db->query($sql);
                if ($res !== false) {
                    if ($res instanceof mysqli_result) {
                        $res->free();
                    }
                    return true;
                }
                return false;
            }
        } catch (\Throwable $e) {
            error_log('[BV Seller Listings] table_exists error: ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('bv_seller_listings_columns')) {
    function bv_seller_listings_columns(object $db, string $table): array
    {
        $cols = [];
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare('SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
                $st->execute();
                foreach ($st->fetchAll() as $row) {
                    $cols[] = strtolower((string) ($row['Field'] ?? ''));
                }
            } elseif ($db instanceof mysqli) {
                $safe = str_replace('`', '', $table);
                $res  = $db->query("SHOW COLUMNS FROM `{$safe}`");
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $cols[] = strtolower((string) ($row['Field'] ?? ''));
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[BV Seller Listings] columns error: ' . $e->getMessage());
        }
        return $cols;
    }
}

if (!function_exists('bv_seller_listings_has_col')) {
    function bv_seller_listings_has_col(object $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = in_array(strtolower($column), bv_seller_listings_columns($db, $table), true);
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_seller_listings_clean_string')) {
    function bv_seller_listings_clean_string($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5);
    }
}

if (!function_exists('bv_seller_listings_int')) {
    function bv_seller_listings_int($value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}

if (!function_exists('bv_seller_listings_float')) {
    function bv_seller_listings_float($value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}

if (!function_exists('bv_seller_listings_asset_url')) {
    function bv_seller_listings_asset_url(?string $path): string
    {
        if (!$path || trim($path) === '') {
            return '';
        }
        $path = trim($path);
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        $base = rtrim(defined('BV_SITE_URL') ? BV_SITE_URL : 'https://www.bettavaro.com', '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('bv_seller_listings_listing_url')) {
    function bv_seller_listings_listing_url(array $row): string
    {
        $base = rtrim(defined('BV_SITE_URL') ? BV_SITE_URL : 'https://www.bettavaro.com', '/');
        $slug = trim((string) ($row['slug'] ?? ''));
        $id   = bv_seller_listings_int($row['id'] ?? 0);
        if ($slug !== '') {
            return $base . '/listing.php?slug=' . urlencode($slug);
        }
        return $id > 0 ? $base . '/listing.php?id=' . $id : $base . '/listing.php';
    }
}

if (!function_exists('bv_seller_listings_read_bearer')) {
    function bv_seller_listings_read_bearer(): string
    {
        $header = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strtolower($k) === 'authorization') {
                    $header = (string) $v;
                    break;
                }
            }
        }
        if ($header === '' || !preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m)) {
            return '';
        }
        return $m[1];
    }
}

// ── Query helpers ─────────────────────────────────────────────────────────────

if (!function_exists('bv_sl_query_row')) {
    function bv_sl_query_row(object $db, string $sql, array $params = []): ?array
    {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql);
                $st->execute($params);
                $row = $st->fetch();
                return $row ?: null;
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) return null;
                if (!empty($params)) {
                    $types   = implode('', array_map(static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'), $params));
                    $bindRef = [&$types];
                    foreach ($params as $k => $_) { $bindRef[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $bindRef);
                }
                $st->execute();
                $res = $st->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $st->close();
                return $row ?: null;
            }
        } catch (\Throwable $e) {
            error_log('[BV Seller Listings] query_row: ' . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('bv_sl_query_rows')) {
    function bv_sl_query_rows(object $db, string $sql, array $params = []): array
    {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql);
                $st->execute($params);
                return $st->fetchAll() ?: [];
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) return [];
                if (!empty($params)) {
                    $types   = implode('', array_map(static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'), $params));
                    $bindRef = [&$types];
                    foreach ($params as $k => $_) { $bindRef[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $bindRef);
                }
                $st->execute();
                $res  = $st->get_result();
                $rows = [];
                if ($res) { while ($row = $res->fetch_assoc()) { $rows[] = $row; } }
                $st->close();
                return $rows;
            }
        } catch (\Throwable $e) {
            error_log('[BV Seller Listings] query_rows: ' . $e->getMessage());
        }
        return [];
    }
}

if (!function_exists('bv_sl_query_scalar')) {
    function bv_sl_query_scalar(object $db, string $sql, array $params = [], $default = 0)
    {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql);
                $st->execute($params);
                $row = $st->fetch(PDO::FETCH_NUM);
                return $row ? $row[0] : $default;
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) return $default;
                if (!empty($params)) {
                    $types   = implode('', array_map(static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'), $params));
                    $bindRef = [&$types];
                    foreach ($params as $k => $_) { $bindRef[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $bindRef);
                }
                $st->execute();
                $res = $st->get_result();
                $row = $res ? $res->fetch_row() : null;
                $st->close();
                return $row ? $row[0] : $default;
            }
        } catch (\Throwable $e) {
            error_log('[BV Seller Listings] query_scalar: ' . $e->getMessage());
        }
        return $default;
    }
}

if (!function_exists('bv_sl_execute')) {
    function bv_sl_execute(object $db, string $sql, array $params = []): bool
    {
        try {
            if ($db instanceof PDO) {
                return $db->prepare($sql)->execute($params);
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) return false;
                if (!empty($params)) {
                    $types   = implode('', array_map(static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'), $params));
                    $bindRef = [&$types];
                    foreach ($params as $k => $_) { $bindRef[] = &$params[$k]; }
                    call_user_func_array([$st, 'bind_param'], $bindRef);
                }
                $result = $st->execute();
                $st->close();
                return $result;
            }
        } catch (\Throwable $e) {
            error_log('[BV Seller Listings] execute: ' . $e->getMessage());
        }
        return false;
    }
}

// =============================================================================
// Main execution
// =============================================================================
try {

    // ── Read and hash Bearer token ────────────────────────────────────────────
    $plainToken = bv_seller_listings_read_bearer();
    if ($plainToken === '') {
        bv_seller_listings_error('token_missing', 'Authorization token is required.', 401);
    }
    $tokenHash = hash('sha256', $plainToken);

    // ── Connect ───────────────────────────────────────────────────────────────
    $db = bv_seller_listings_db();
    if ($db === null) {
        bv_seller_listings_log('No database connection.');
        bv_seller_listings_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // ── Validate token + user ─────────────────────────────────────────────────
    $tokenRow = bv_sl_query_row(
        $db,
        "SELECT mat.id AS token_id,
                u.id AS user_id,
                u.email,
                u.role,
                u.account_status,
                u.first_name,
                u.last_name
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
        bv_seller_listings_error('token_invalid', 'Token is invalid or has expired.', 401);
    }

    // Update last_used_at best-effort
    bv_sl_execute(
        $db,
        'UPDATE mobile_auth_tokens SET last_used_at = NOW() WHERE id = ? LIMIT 1',
        [(int) $tokenRow['token_id']]
    );

    $userId    = (int) $tokenRow['user_id'];
    $userRole  = bv_seller_listings_clean_string($tokenRow['role'] ?? 'user');

    // ── Permission: seller or admin only ──────────────────────────────────────
    if (!in_array($userRole, ['seller', 'admin'], true)) {
        bv_seller_listings_error('seller_required', 'Seller access is required.', 403);
    }

    // ── Confirm listings table ────────────────────────────────────────────────
    if (!bv_seller_listings_table_exists($db, 'listings')) {
        bv_seller_listings_log('listings table not found.');
        bv_seller_listings_error('listings_table_missing', 'Listings service is unavailable.', 503);
    }

    // ── Detect listings columns ───────────────────────────────────────────────
    $lCols   = bv_seller_listings_columns($db, 'listings');
    $hasLCol = static fn(string $c): bool => in_array(strtolower($c), $lCols, true);

    // Ownership column — prefer seller_id, then alternatives
    $ownerCol = null;
    foreach (['seller_id', 'seller_user_id', 'user_id', 'owner_user_id'] as $cand) {
        if ($hasLCol($cand)) {
            $ownerCol = $cand;
            break;
        }
    }
    if ($ownerCol === null) {
        bv_seller_listings_log('No ownership column found on listings table.');
        bv_seller_listings_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // Effective seller id (admin can scope to another seller via ?seller_id=)
    $filterUserId = $userId;
    if ($userRole === 'admin') {
        $rawAdminSellerId = bv_seller_listings_int($_GET['seller_id'] ?? 0, 0);
        if ($rawAdminSellerId > 0) {
            $filterUserId = $rawAdminSellerId;
        }
    }

    // Cover image column detection: cover_image → image → photo → thumbnail
    $coverCol = null;
    foreach (['cover_image', 'image_url', 'image', 'photo', 'thumbnail'] as $cand) {
        if ($hasLCol($cand)) { $coverCol = $cand; break; }
    }

    // ── Parse query parameters ────────────────────────────────────────────────
    // Spec params: status, page, per_page, search
    $page    = max(1, bv_seller_listings_int($_GET['page']     ?? 1, 1));
    $perPage = min(100, max(1, bv_seller_listings_int($_GET['per_page'] ?? 20, 20)));
    $offset  = ($page - 1) * $perPage;
    $search  = trim((string) ($_GET['search'] ?? ''));

    // Status filter — spec supported values: all, active, draft, sold, inactive, pending
    // "inactive" maps to DB value "hidden"
    $qStatus = trim((string) ($_GET['status'] ?? 'all'));
    $allowedStatuses = ['all', 'active', 'draft', 'sold', 'inactive', 'pending'];
    if (!in_array($qStatus, $allowedStatuses, true)) {
        $qStatus = 'all';
    }
    // Translate spec status → DB enum value
    $dbStatusFilter = match ($qStatus) {
        'inactive' => 'hidden',
        default    => $qStatus,
    };

    // ── Build WHERE clause ────────────────────────────────────────────────────
    $whereParts  = ["l.`{$ownerCol}` = ?"];
    $whereParams = [$filterUserId];

    if ($qStatus !== 'all' && $hasLCol('status')) {
        $whereParts[]  = 'l.`status` = ?';
        $whereParams[] = $dbStatusFilter;
    }

    if ($search !== '') {
        $searchParts = [];
        foreach (['title', 'slug', 'species', 'strain'] as $sc) {
            if ($hasLCol($sc)) {
                $searchParts[] = "l.`{$sc}` LIKE ?";
                $whereParams[] = '%' . $search . '%';
            }
        }
        if (!empty($searchParts)) {
            $whereParts[] = '(' . implode(' OR ', $searchParts) . ')';
        }
    }

    $whereSQL = 'WHERE ' . implode(' AND ', $whereParts);

    // ── Optional ranking cache table detection ────────────────────────────────
    // listing_ranking_cache carries avg_rating and sold_count
    $hasRankCache = bv_seller_listings_table_exists($db, 'listing_ranking_cache');
    $rcListingCol = null;
    $rcAvgCol     = null;
    $rcSoldCol    = null;
    if ($hasRankCache) {
        $rcCols       = bv_seller_listings_columns($db, 'listing_ranking_cache');
        $rcListingCol = in_array('listing_id', $rcCols, true) ? 'listing_id' : null;
        $rcAvgCol     = in_array('avg_rating',   $rcCols, true) ? 'avg_rating'  : null;
        $rcSoldCol    = in_array('sold_count',   $rcCols, true) ? 'sold_count'  : null;
        if (!$rcListingCol) { $hasRankCache = false; }
    }

    // ── Build SELECT ──────────────────────────────────────────────────────────
    $selectCols = ['l.id'];

    // Core spec fields
    foreach (['title', 'slug', 'status', 'sale_status', 'species', 'strain'] as $col) {
        if ($hasLCol($col)) { $selectCols[] = "l.`{$col}`"; }
    }

    // gender → sex in DB
    if ($hasLCol('sex')) {
        $selectCols[] = 'l.`sex` AS gender';
    } elseif ($hasLCol('gender')) {
        $selectCols[] = 'l.`gender`';
    }

    // price / currency
    if ($hasLCol('price'))    { $selectCols[] = 'l.`price`'; }
    if ($hasLCol('currency')) { $selectCols[] = 'l.`currency`'; }

    // image_url — from whichever cover column was found
    if ($coverCol) {
        $selectCols[] = "l.`{$coverCol}` AS image_url";
    }

    // timestamps
    if ($hasLCol('created_at')) { $selectCols[] = 'l.`created_at`'; }
    if ($hasLCol('updated_at')) { $selectCols[] = 'l.`updated_at`'; }

    // stock_quantity from stock_total (preferred) or stock_available
    if ($hasLCol('stock_total')) {
        $selectCols[] = 'l.`stock_total` AS stock_quantity';
    } elseif ($hasLCol('stock_quantity')) {
        $selectCols[] = 'l.`stock_quantity`';
    }

    // ranking_score — prefer listing_ranking_scores.final_score via JOIN,
    // else listing_ranking_cache.ranking_score via JOIN,
    // else listings.ranking_score column directly
    $hasRankingScores = bv_seller_listings_table_exists($db, 'listing_ranking_scores');
    $joinSQL          = '';

    if ($hasRankingScores) {
        $rsListingCol = bv_seller_listings_has_col($db, 'listing_ranking_scores', 'listing_id') ? 'listing_id' : null;
        $rsFinalCol   = bv_seller_listings_has_col($db, 'listing_ranking_scores', 'final_score') ? 'final_score' : null;
        if ($rsListingCol && $rsFinalCol) {
            $joinSQL      .= ' LEFT JOIN listing_ranking_scores lrs ON lrs.listing_id = l.id';
            $selectCols[]  = 'COALESCE(lrs.final_score, 0) AS ranking_score';
        } elseif ($hasLCol('ranking_score')) {
            $selectCols[] = 'l.`ranking_score`';
        } else {
            $selectCols[] = '0 AS ranking_score';
        }
    } elseif ($hasLCol('ranking_score')) {
        $selectCols[] = 'l.`ranking_score`';
    } else {
        $selectCols[] = '0 AS ranking_score';
    }

    // avg_rating and sold_count from listing_ranking_cache via JOIN
    if ($hasRankCache && $rcListingCol) {
        $joinSQL .= " LEFT JOIN listing_ranking_cache lrc ON lrc.{$rcListingCol} = l.id";
        $selectCols[] = $rcAvgCol  ? 'COALESCE(lrc.avg_rating, 0) AS avg_rating'   : '0 AS avg_rating';
        $selectCols[] = $rcSoldCol ? 'COALESCE(lrc.sold_count, 0) AS sold_count'    : '0 AS sold_count';
    } else {
        $selectCols[] = '0 AS avg_rating';
        $selectCols[] = '0 AS sold_count';
    }

    $fromClause   = "FROM listings l{$joinSQL}";
    $selectClause = 'SELECT ' . implode(', ', $selectCols);
    $orderSQL     = $hasLCol('created_at') ? 'ORDER BY l.created_at DESC, l.id DESC' : 'ORDER BY l.id DESC';

    // ── Count query ───────────────────────────────────────────────────────────
    $totalCount = (int) bv_sl_query_scalar(
        $db,
        "SELECT COUNT(*) {$fromClause} {$whereSQL}",
        $whereParams,
        0
    );

    // ── Data query ────────────────────────────────────────────────────────────
    $dataParams = array_merge($whereParams, [$perPage, $offset]);
    $rows       = bv_sl_query_rows(
        $db,
        "{$selectClause} {$fromClause} {$whereSQL} {$orderSQL} LIMIT ? OFFSET ?",
        $dataParams
    );

    // ── Format listings ───────────────────────────────────────────────────────
    $listings = [];
    foreach ($rows as $row) {
        $currency     = bv_seller_listings_clean_string($row['currency'] ?? 'USD') ?: 'USD';
        $rawImagePath = bv_seller_listings_clean_string($row['image_url'] ?? '');
        $imageUrl     = bv_seller_listings_asset_url($rawImagePath);

        $listingItem = [
            'id'             => bv_seller_listings_int($row['id']),
            'title'          => bv_seller_listings_clean_string($row['title']       ?? ''),
            'slug'           => bv_seller_listings_clean_string($row['slug']        ?? ''),
            'status'         => bv_seller_listings_clean_string($row['status']      ?? ''),
            'sale_status'    => bv_seller_listings_clean_string($row['sale_status'] ?? ''),
            'species'        => bv_seller_listings_clean_string($row['species']     ?? ''),
            'strain'         => bv_seller_listings_clean_string($row['strain']      ?? ''),
            'gender'         => bv_seller_listings_clean_string($row['gender']      ?? ''),
            'price'          => bv_seller_listings_float($row['price'] ?? 0),
            'currency'       => $currency,
            'image_url'      => $imageUrl,
            'created_at'     => bv_seller_listings_clean_string($row['created_at']  ?? ''),
            'updated_at'     => bv_seller_listings_clean_string($row['updated_at']  ?? ''),
            'views'          => 0,   // column does not exist in schema
            'favorites'      => 0,   // column does not exist in schema
            'stock_quantity' => bv_seller_listings_int($row['stock_quantity'] ?? 1),
        ];

        // Optional ranking / review fields — include when available
        $rankingScore = bv_seller_listings_float($row['ranking_score'] ?? 0);
        $avgRating    = round(bv_seller_listings_float($row['avg_rating'] ?? 0), 2);
        $soldCount    = bv_seller_listings_int($row['sold_count'] ?? 0);

        $listingItem['ranking_score'] = round($rankingScore, 4);
        $listingItem['avg_rating']    = $avgRating;
        $listingItem['sold_count']    = $soldCount;

        $listings[] = $listingItem;
    }

    // ── Summary (seller-only counts, unaffected by current filters) ────────────
    // DB status enum: draft, pending, active, sold, hidden
    // Spec summary keys: total, active, draft, sold, inactive
    //   inactive = count of rows with status='hidden'
    $summary = [
        'total'    => 0,
        'active'   => 0,
        'draft'    => 0,
        'sold'     => 0,
        'inactive' => 0,   // = hidden in DB
    ];

    $summary['total'] = (int) bv_sl_query_scalar(
        $db,
        "SELECT COUNT(*) FROM listings WHERE `{$ownerCol}` = ?",
        [$filterUserId],
        0
    );

    if ($hasLCol('status')) {
        $statusRows = bv_sl_query_rows(
            $db,
            "SELECT status, COUNT(*) AS cnt FROM listings WHERE `{$ownerCol}` = ? GROUP BY status",
            [$filterUserId]
        );
        foreach ($statusRows as $sr) {
            $dbStatus = (string) ($sr['status'] ?? '');
            $cnt      = (int) ($sr['cnt'] ?? 0);
            switch ($dbStatus) {
                case 'active':  $summary['active']   += $cnt; break;
                case 'draft':   $summary['draft']    += $cnt; break;
                case 'sold':    $summary['sold']      += $cnt; break;
                case 'hidden':  $summary['inactive'] += $cnt; break;
                // 'pending' and other values are not in spec summary, skip
            }
        }
    }

    // ── Build response ────────────────────────────────────────────────────────
    bv_seller_listings_json(200, [
        'ok'   => true,
        'data' => [
            'seller'     => [
                'id'   => $filterUserId,
                'role' => $userRole,
            ],
            'filters'    => [
                'status' => $qStatus,
            ],
            'summary'    => $summary,
            'listings'   => $listings,
            'pagination' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $totalCount,
                'has_more' => ($page * $perPage) < $totalCount,
            ],
        ],
        'meta' => [
            'api_version'  => 'mobile-v1',
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ],
    ]);

} catch (\Throwable $e) {
    bv_seller_listings_log('Unhandled exception: ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_seller_listings_json(500, [
        'ok'    => false,
        'error' => ['code' => 'server_error', 'message' => 'Something went wrong.'],
    ]);
}
