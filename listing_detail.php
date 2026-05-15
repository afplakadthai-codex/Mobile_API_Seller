<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/listing_detail.php
// Read-only single listing detail endpoint.
// Do NOT modify existing website files. This file is standalone.
// CORS: Not opened broadly. Configure Access-Control-Allow-Origin later
//       when mobile app domain or API gateway is finalized.
// =============================================================================

// Flush any accidental output buffers
while (ob_get_level() > 0) {
    @ob_end_clean();
}

// ── Security headers ──────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ── Allow GET only ────────────────────────────────────────────────────────────
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
// File lives at: /public_html/api/mobile/v1/listing_detail.php
// dirname(__DIR__, 3) → /public_html
// dirname(__DIR__, 4) → account root (parent of public_html)
// =============================================================================

if (!function_exists('bv_mobile_detail_public_root')) {
    function bv_mobile_detail_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_mobile_detail_project_root')) {
    function bv_mobile_detail_project_root(): string
    {
        return dirname(bv_mobile_detail_public_root());
    }
}

// =============================================================================
// Helper functions
// =============================================================================

if (!function_exists('bv_mobile_detail_json')) {
    function bv_mobile_detail_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_mobile_detail_error')) {
    function bv_mobile_detail_error(string $code, string $message, int $statusCode = 400): void
    {
        bv_mobile_detail_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

if (!function_exists('bv_mobile_detail_log')) {
    function bv_mobile_detail_log(string $message): void
    {
        error_log('[BV Mobile Detail API] ' . $message);
        $line          = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        $logCandidates = [
            bv_mobile_detail_project_root() . '/private_html/mobile_api.log',
            bv_mobile_detail_public_root()  . '/logs/mobile_api.log',
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

if (!function_exists('bv_mobile_detail_db')) {
    function bv_mobile_detail_db(): ?object
    {
        static $cached = false;
        static $connection = null;
        if ($cached !== false) {
            return $connection;
        }
        $cached     = true;
        $publicRoot = bv_mobile_detail_public_root();
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

            // 1) Connection object captured from local scope
            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $name) {
                if (isset($vars[$name]) && ($vars[$name] instanceof PDO || $vars[$name] instanceof mysqli)) {
                    $connection = $vars[$name];
                    return $connection;
                }
            }
            // 2) Connection object in $GLOBALS
            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $gname) {
                if (isset($GLOBALS[$gname])) {
                    $obj = $GLOBALS[$gname];
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

            // PDO first
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
                    error_log('[BV Mobile Detail API] PDO connect failed: ' . $e->getMessage());
                }
            }
            // mysqli fallback
            if ($h && $u !== null && $n && function_exists('mysqli_connect')) {
                try {
                    $m = @new mysqli($h, (string) $u, (string) $p, $n, $pt ?: 3306);
                    if (!$m->connect_errno) {
                        $m->set_charset('utf8mb4');
                        $connection = $m;
                        return $connection;
                    }
                    error_log('[BV Mobile Detail API] mysqli connect failed: ' . $m->connect_error);
                } catch (\Throwable $e) {
                    error_log('[BV Mobile Detail API] mysqli exception: ' . $e->getMessage());
                }
            }
            break; // Config found but connect failed — stop
        }
        return null;
    }
}

if (!function_exists('bv_mobile_detail_table_exists')) {
    // Uses SELECT 1 probe — avoids SHOW TABLES LIKE false negatives
    function bv_mobile_detail_table_exists(object $db, string $table): bool
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
            error_log('[BV Mobile Detail API] table_exists error: ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('bv_mobile_detail_columns')) {
    function bv_mobile_detail_columns(object $db, string $table): array
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
            error_log('[BV Mobile Detail API] columns error: ' . $e->getMessage());
        }
        return $cols;
    }
}

if (!function_exists('bv_mobile_detail_has_col')) {
    function bv_mobile_detail_has_col(object $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = in_array(strtolower($column), bv_mobile_detail_columns($db, $table), true);
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_mobile_detail_clean_string')) {
    function bv_mobile_detail_clean_string($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5);
    }
}

if (!function_exists('bv_mobile_detail_int')) {
    function bv_mobile_detail_int($value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}

if (!function_exists('bv_mobile_detail_float')) {
    function bv_mobile_detail_float($value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}

if (!function_exists('bv_mobile_detail_asset_url')) {
    function bv_mobile_detail_asset_url(?string $path): string
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

if (!function_exists('bv_mobile_detail_listing_url')) {
    function bv_mobile_detail_listing_url(array $row): string
    {
        $base = rtrim(defined('BV_SITE_URL') ? BV_SITE_URL : 'https://www.bettavaro.com', '/');
        $slug = trim((string) ($row['slug'] ?? ''));
        $id   = bv_mobile_detail_int($row['id'] ?? 0);
        if ($slug !== '') {
            return $base . '/listing.php?slug=' . urlencode($slug);
        }
        return $id > 0 ? $base . '/listing.php?id=' . $id : $base . '/listing.php';
    }
}

if (!function_exists('bv_mobile_detail_money')) {
    function bv_mobile_detail_money($amount, string $currency): array
    {
        $amt      = bv_mobile_detail_float($amount, 0.0);
        $currency = strtoupper(trim($currency ?: 'USD'));
        return [
            'amount'    => $amt,
            'currency'  => $currency,
            'formatted' => $currency . ' ' . number_format($amt, 2),
        ];
    }
}

if (!function_exists('bv_mobile_detail_detect_media_type')) {
    function bv_mobile_detail_detect_media_type(array $row): string
    {
        // 1) Explicit media_type / type column
        foreach (['media_type', 'type'] as $col) {
            if (!empty($row[$col])) {
                $v = strtolower(trim((string) $row[$col]));
                if ($v === 'video') {
                    return 'video';
                }
                if ($v === 'image') {
                    return 'image';
                }
            }
        }
        // 2) mime_type
        if (!empty($row['mime_type'])) {
            if (str_starts_with(strtolower((string) $row['mime_type']), 'video/')) {
                return 'video';
            }
        }
        // 3) File extension
        $path = (string) ($row['storage_path'] ?? $row['file_name'] ?? $row['image_path'] ?? '');
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv'], true)) {
            return 'video';
        }
        return 'image';
    }
}

// ── PDO/mysqli query helpers ─────────────────────────────────────────────────

if (!function_exists('bv_detail_query_row')) {
    function bv_detail_query_row(object $db, string $sql, array $params = []): ?array
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
                if ($st === false) {
                    return null;
                }
                if (!empty($params)) {
                    $types   = implode('', array_map(static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'), $params));
                    $bindRef = [&$types];
                    foreach ($params as $k => $_) {
                        $bindRef[] = &$params[$k];
                    }
                    call_user_func_array([$st, 'bind_param'], $bindRef);
                }
                $st->execute();
                $res = $st->get_result();
                $row = $res ? $res->fetch_assoc() : null;
                $st->close();
                return $row ?: null;
            }
        } catch (\Throwable $e) {
            error_log('[BV Mobile Detail API] query_row error: ' . $e->getMessage());
        }
        return null;
    }
}

if (!function_exists('bv_detail_query_rows')) {
    function bv_detail_query_rows(object $db, string $sql, array $params = []): array
    {
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare($sql);
                $st->execute($params);
                return $st->fetchAll() ?: [];
            }
            if ($db instanceof mysqli) {
                $st = $db->prepare($sql);
                if ($st === false) {
                    return [];
                }
                if (!empty($params)) {
                    $types   = implode('', array_map(static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'), $params));
                    $bindRef = [&$types];
                    foreach ($params as $k => $_) {
                        $bindRef[] = &$params[$k];
                    }
                    call_user_func_array([$st, 'bind_param'], $bindRef);
                }
                $st->execute();
                $res  = $st->get_result();
                $rows = [];
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $rows[] = $row;
                    }
                }
                $st->close();
                return $rows;
            }
        } catch (\Throwable $e) {
            error_log('[BV Mobile Detail API] query_rows error: ' . $e->getMessage());
        }
        return [];
    }
}

// =============================================================================
// Main execution
// =============================================================================
try {
    // ── DB connection ─────────────────────────────────────────────────────────
    $db = bv_mobile_detail_db();
    if ($db === null) {
        bv_mobile_detail_log('No database connection available.');
        bv_mobile_detail_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // ── Validate input ────────────────────────────────────────────────────────
    $rawId   = trim((string) ($_GET['id']   ?? ''));
    $rawSlug = trim((string) ($_GET['slug'] ?? ''));

    $inputId   = 0;
    $inputSlug = '';

    if ($rawId !== '') {
        $inputId = bv_mobile_detail_int($rawId, 0);
        if ($inputId <= 0) {
            bv_mobile_detail_error('invalid_id', 'id must be a positive integer.', 400);
        }
    }
    if ($rawSlug !== '') {
        // Allow URL-safe slug characters only
        $inputSlug = preg_replace('/[^a-zA-Z0-9\-_.]/', '', $rawSlug);
    }

    if ($inputId === 0 && $inputSlug === '') {
        bv_mobile_detail_error('missing_identifier', 'Provide id or slug query parameter.', 400);
    }

    // ── Detect listings table ────────────────────────────────────────────────
    if (!bv_mobile_detail_table_exists($db, 'listings')) {
        bv_mobile_detail_log('listings table not found.');
        bv_mobile_detail_error('not_found', 'Listing not found.', 404);
    }

    // ── Detect optional tables ────────────────────────────────────────────────
    $hasUsers          = bv_mobile_detail_table_exists($db, 'users');
    $hasSellerProfiles = bv_mobile_detail_table_exists($db, 'seller_profiles');
    $hasSellerRepCache = bv_mobile_detail_table_exists($db, 'seller_reputation_cache');
    $hasReviews        = bv_mobile_detail_table_exists($db, 'listing_reviews');
    $hasRanking        = bv_mobile_detail_table_exists($db, 'listing_ranking_scores');
    $hasMedia          = bv_mobile_detail_table_exists($db, 'listing_media');
    $hasImages         = bv_mobile_detail_table_exists($db, 'listing_images');

    // ── Detect listing columns ────────────────────────────────────────────────
    $lCols  = bv_mobile_detail_columns($db, 'listings');
    $hasLCol = static fn(string $c): bool => in_array(strtolower($c), $lCols, true);

    // Public statuses for listings.status
    // Schema: enum('draft','pending','active','sold','hidden')
    $publicStatuses     = ['active'];
    $publicSaleStatuses = ['available', 'reserved', 'sold'];

    // ── Fetch the listing row ─────────────────────────────────────────────────
    $whereSql    = '';
    $whereParams = [];

    if ($inputId > 0) {
        $whereSql    = 'listings.id = ?';
        $whereParams = [$inputId];
    } else {
        $whereSql    = 'listings.slug = ?';
        $whereParams = [$inputSlug];
    }

    // Build SELECT — only include columns confirmed to exist
    $selectCols = ['listings.id'];
    $optionalListingCols = [
        'title', 'slug', 'short_description', 'description',
        'species', 'strain', 'tail_type', 'color_pattern', 'body_type',
        'sex', 'age_months', 'breeder_line', 'grade',
        'price', 'currency', 'country', 'city',
        'status', 'sale_format', 'sale_status',
        'auction_enabled', 'auction_status', 'auction_start_price',
        'auction_reserve_price', 'auction_starts_at', 'auction_ends_at',
        'auction_current_bid', 'auction_bid_count',
        'stock_total', 'stock_sold', 'stock_available',
        'cover_image', 'featured', 'ranking_score',
        'seller_id', 'created_at', 'updated_at',
    ];
    foreach ($optionalListingCols as $col) {
        if ($hasLCol($col)) {
            $selectCols[] = 'listings.' . $col;
        }
    }

    // Status filter — only show public statuses
    $statusPlaceholders = implode(', ', array_fill(0, count($publicStatuses), '?'));
    $statusFilterSql    = '';
    $statusParams       = [];
    if ($hasLCol('status')) {
        $statusFilterSql = "AND listings.status IN ({$statusPlaceholders})";
        $statusParams    = $publicStatuses;
    }

    $sql  = 'SELECT ' . implode(', ', $selectCols)
        . ' FROM listings'
        . ' WHERE ' . $whereSql
        . ' ' . $statusFilterSql
        . ' LIMIT 1';

    $listingRow = bv_detail_query_row($db, $sql, array_merge($whereParams, $statusParams));

    if ($listingRow === null) {
        bv_mobile_detail_error('not_found', 'Listing not found.', 404);
    }

    $listingId   = bv_mobile_detail_int($listingRow['id']);
    $saleStatus  = bv_mobile_detail_clean_string($listingRow['sale_status'] ?? 'available');
    $saleFormat  = bv_mobile_detail_clean_string($listingRow['sale_format'] ?? 'fixed');
    $status      = bv_mobile_detail_clean_string($listingRow['status']      ?? '');
    $currency    = bv_mobile_detail_clean_string($listingRow['currency']    ?? 'USD') ?: 'USD';
    $stockAvail  = bv_mobile_detail_int($listingRow['stock_available']      ?? 1);
    $stockTotal  = bv_mobile_detail_int($listingRow['stock_total']          ?? 1);
    $stockSold   = bv_mobile_detail_int($listingRow['stock_sold']           ?? 0);

    // ── Build listing section ─────────────────────────────────────────────────
    $listingData = [
        'id'                => $listingId,
        'title'             => bv_mobile_detail_clean_string($listingRow['title']             ?? ''),
        'slug'              => bv_mobile_detail_clean_string($listingRow['slug']              ?? ''),
        'url'               => bv_mobile_detail_listing_url($listingRow),
        'short_description' => bv_mobile_detail_clean_string($listingRow['short_description'] ?? ''),
        'description'       => bv_mobile_detail_clean_string($listingRow['description']       ?? ''),
        'price'             => bv_mobile_detail_money($listingRow['price'] ?? 0, $currency),
        'status'            => $status,
        'sale_status'       => $saleStatus,
        'sale_format'       => $saleFormat,
        'species'           => bv_mobile_detail_clean_string($listingRow['species']           ?? ''),
        'strain'            => bv_mobile_detail_clean_string($listingRow['strain']            ?? ''),
        'grade'             => bv_mobile_detail_clean_string($listingRow['grade']             ?? ''),
        'sex'               => bv_mobile_detail_clean_string($listingRow['sex']               ?? ''),
        'age_months'        => isset($listingRow['age_months']) && $listingRow['age_months'] !== null
                                ? bv_mobile_detail_int($listingRow['age_months']) : null,
        'color'             => bv_mobile_detail_clean_string($listingRow['color_pattern']     ?? ''),
        'tail_type'         => bv_mobile_detail_clean_string($listingRow['tail_type']         ?? ''),
        'body_type'         => bv_mobile_detail_clean_string($listingRow['body_type']         ?? ''),
        'breeder_line'      => bv_mobile_detail_clean_string($listingRow['breeder_line']      ?? ''),
        'country'           => bv_mobile_detail_clean_string($listingRow['country']           ?? ''),
        'city'              => bv_mobile_detail_clean_string($listingRow['city']              ?? ''),
        'stock_total'       => $stockTotal,
        'stock_sold'        => $stockSold,
        'stock_available'   => $stockAvail,
        'cover_image'       => bv_mobile_detail_asset_url(
                                    bv_mobile_detail_clean_string($listingRow['cover_image'] ?? '')
                               ),
        'ranking_score'     => round(bv_mobile_detail_float($listingRow['ranking_score'] ?? 0, 0.0), 4),
        'created_at'        => bv_mobile_detail_clean_string($listingRow['created_at']       ?? ''),
        'updated_at'        => bv_mobile_detail_clean_string($listingRow['updated_at']       ?? ''),
    ];

    // Auction info (only relevant for auction format)
    if ($saleFormat === 'auction' || bv_mobile_detail_int($listingRow['auction_enabled'] ?? 0) === 1) {
        $listingData['auction'] = [
            'status'        => bv_mobile_detail_clean_string($listingRow['auction_status']       ?? ''),
            'start_price'   => bv_mobile_detail_float($listingRow['auction_start_price']         ?? 0),
            'reserve_price' => bv_mobile_detail_float($listingRow['auction_reserve_price']       ?? 0),
            'current_bid'   => bv_mobile_detail_float($listingRow['auction_current_bid']         ?? 0),
            'bid_count'     => bv_mobile_detail_int($listingRow['auction_bid_count']             ?? 0),
            'starts_at'     => bv_mobile_detail_clean_string($listingRow['auction_starts_at']    ?? ''),
            'ends_at'       => bv_mobile_detail_clean_string($listingRow['auction_ends_at']      ?? ''),
        ];
    } else {
        $listingData['auction'] = null;
    }

    // ── Ranking ───────────────────────────────────────────────────────────────
    $rankingData = ['final_score' => $listingData['ranking_score']];
    if ($hasRanking) {
        $rankRow = bv_detail_query_row(
            $db,
            'SELECT final_score, listing_quality_score, seller_score, sales_score,
                    freshness_score, trust_score, review_score, fulfillment_score,
                    comeback_score
             FROM listing_ranking_scores
             WHERE listing_id = ?
             LIMIT 1',
            [$listingId]
        );
        if ($rankRow) {
            $rankingData = [
                'final_score'            => round(bv_mobile_detail_float($rankRow['final_score']            ?? 0), 4),
                'listing_quality_score'  => round(bv_mobile_detail_float($rankRow['listing_quality_score']  ?? 0), 2),
                'seller_score'           => round(bv_mobile_detail_float($rankRow['seller_score']           ?? 0), 2),
                'sales_score'            => round(bv_mobile_detail_float($rankRow['sales_score']            ?? 0), 2),
                'freshness_score'        => round(bv_mobile_detail_float($rankRow['freshness_score']        ?? 0), 2),
                'trust_score'            => round(bv_mobile_detail_float($rankRow['trust_score']            ?? 0), 2),
                'review_score'           => round(bv_mobile_detail_float($rankRow['review_score']           ?? 0), 2),
                'fulfillment_score'      => round(bv_mobile_detail_float($rankRow['fulfillment_score']      ?? 0), 2),
                'comeback_score'         => round(bv_mobile_detail_float($rankRow['comeback_score']         ?? 0), 2),
            ];
            $listingData['ranking_score'] = $rankingData['final_score'];
        }
    }

    // ── Media ─────────────────────────────────────────────────────────────────
    $mediaItems = [];

    if ($hasMedia) {
        // listing_media: primary media table with status column
        $mediaRows = bv_detail_query_rows(
            $db,
            "SELECT id, media_type, storage_path, file_name, file_ext, mime_type,
                    sort_order, is_cover, original_name
             FROM listing_media
             WHERE listing_id = ? AND status = 'active'
             ORDER BY is_cover DESC, sort_order ASC, id ASC",
            [$listingId]
        );
        foreach ($mediaRows as $mr) {
            $mediaType = bv_mobile_detail_detect_media_type($mr);
            $src       = bv_mobile_detail_asset_url(bv_mobile_detail_clean_string($mr['storage_path'] ?? ''));
            $mediaItems[] = [
                'type'       => $mediaType,
                'src'        => $src,
                'thumb'      => $mediaType === 'image' ? $src : '',
                'poster'     => $mediaType === 'video' ? '' : '',
                'label'      => bv_mobile_detail_clean_string($mr['original_name'] ?? ''),
                'sort_order' => bv_mobile_detail_int($mr['sort_order'] ?? 0),
                'is_cover'   => (bool) ($mr['is_cover'] ?? false),
            ];
        }
    }

    if (empty($mediaItems) && $hasImages) {
        // listing_images: fallback media table
        $imgRows = bv_detail_query_rows(
            $db,
            'SELECT id, image_path, alt_text, sort_order, is_cover
             FROM listing_images
             WHERE listing_id = ?
             ORDER BY is_cover DESC, sort_order ASC, id ASC',
            [$listingId]
        );
        foreach ($imgRows as $ir) {
            $src          = bv_mobile_detail_asset_url(bv_mobile_detail_clean_string($ir['image_path'] ?? ''));
            $mediaItems[] = [
                'type'       => 'image',
                'src'        => $src,
                'thumb'      => $src,
                'poster'     => '',
                'label'      => bv_mobile_detail_clean_string($ir['alt_text'] ?? ''),
                'sort_order' => bv_mobile_detail_int($ir['sort_order'] ?? 0),
                'is_cover'   => (bool) ($ir['is_cover'] ?? false),
            ];
        }
    }

    // Final fallback: cover_image from the listing row
    if (empty($mediaItems) && !empty($listingData['cover_image'])) {
        $mediaItems[] = [
            'type'       => 'image',
            'src'        => $listingData['cover_image'],
            'thumb'      => $listingData['cover_image'],
            'poster'     => '',
            'label'      => bv_mobile_detail_clean_string($listingRow['title'] ?? ''),
            'sort_order' => 0,
            'is_cover'   => true,
        ];
    }

    // ── Seller ────────────────────────────────────────────────────────────────
    $sellerData  = null;
    $rawSellerId = bv_mobile_detail_int($listingRow['seller_id'] ?? 0, 0);

    if ($rawSellerId > 0 && $hasUsers) {
        // Fetch user row
        $userRow = bv_detail_query_row(
            $db,
            'SELECT id, first_name, last_name, email, country
             FROM users
             WHERE id = ?
             LIMIT 1',
            [$rawSellerId]
        );

        // Fetch seller_profile row if available
        $spRow = null;
        if ($hasSellerProfiles) {
            $spRow = bv_detail_query_row(
                $db,
                "SELECT farm_name, public_display_name, public_bio,
                        farm_logo_path, seller_status, farm_province
                 FROM seller_profiles
                 WHERE user_id = ? AND seller_status = 'active'
                 LIMIT 1",
                [$rawSellerId]
            );
        }

        // Seller rating from reputation cache
        $sellerRating = ['average' => 0.0, 'count' => 0];
        if ($hasSellerRepCache) {
            $repRow = bv_detail_query_row(
                $db,
                'SELECT avg_rating, review_count FROM seller_reputation_cache WHERE seller_id = ? LIMIT 1',
                [$rawSellerId]
            );
            if ($repRow) {
                $sellerRating = [
                    'average' => round(bv_mobile_detail_float($repRow['avg_rating'] ?? 0), 2),
                    'count'   => bv_mobile_detail_int($repRow['review_count'] ?? 0),
                ];
            }
        }

        if ($userRow) {
            // Resolve seller display name:
            // farm_name (seller_profiles) > public_display_name > first_name + last_name > email > Seller #id
            $farmName        = bv_mobile_detail_clean_string($spRow['farm_name']           ?? '');
            $publicDisplay   = bv_mobile_detail_clean_string($spRow['public_display_name'] ?? '');
            $firstName       = bv_mobile_detail_clean_string($userRow['first_name']        ?? '');
            $lastName        = bv_mobile_detail_clean_string($userRow['last_name']         ?? '');
            $fullName        = trim($firstName . ' ' . $lastName);
            $emailMasked     = ''; // Never expose raw email to public API
            $sellerName      = $farmName ?: ($publicDisplay ?: ($fullName ?: "Seller #{$rawSellerId}"));

            $profileUrl = rtrim(defined('BV_SITE_URL') ? BV_SITE_URL : 'https://www.bettavaro.com', '/')
                . '/seller.php?id=' . $rawSellerId;

            $sellerData = [
                'id'          => $rawSellerId,
                'name'        => $sellerName,
                'farm_name'   => $farmName,
                'bio'         => bv_mobile_detail_clean_string($spRow['public_bio'] ?? ''),
                'logo'        => bv_mobile_detail_asset_url(bv_mobile_detail_clean_string($spRow['farm_logo_path'] ?? '')),
                'country'     => bv_mobile_detail_clean_string($userRow['country'] ?? ''),
                'profile_url' => $profileUrl,
                'rating'      => $sellerRating,
            ];
        } else {
            $sellerData = [
                'id'          => $rawSellerId,
                'name'        => "Seller #{$rawSellerId}",
                'farm_name'   => '',
                'bio'         => '',
                'logo'        => '',
                'country'     => '',
                'profile_url' => '',
                'rating'      => ['average' => 0.0, 'count' => 0],
            ];
        }
    }

    // ── Reviews ───────────────────────────────────────────────────────────────
    $reviewSummary = ['average' => 0.0, 'count' => 0];
    $reviewItems   = [];

    if ($hasReviews) {
        // Summary
        $summaryRow = bv_detail_query_row(
            $db,
            "SELECT COALESCE(AVG(rating), 0) AS avg_rating, COUNT(*) AS total
             FROM listing_reviews
             WHERE listing_id = ? AND status = 'approved'",
            [$listingId]
        );
        if ($summaryRow) {
            $reviewSummary = [
                'average' => round(bv_mobile_detail_float($summaryRow['avg_rating'] ?? 0), 2),
                'count'   => bv_mobile_detail_int($summaryRow['total'] ?? 0),
            ];
        }

        // Latest 10 approved reviews
        // Detect reviewer name from linked users table
        $reviewerNameExpr = $hasUsers
            ? "NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(u.first_name),''), NULLIF(TRIM(u.last_name),''))), '') AS reviewer_name"
            : "NULL AS reviewer_name";
        $reviewJoin = $hasUsers
            ? 'LEFT JOIN users u ON u.id = lr.user_id'
            : '';

        $rawReviews = bv_detail_query_rows(
            $db,
            "SELECT lr.id, lr.rating, lr.title, lr.comment,
                    lr.is_verified_buyer, lr.submitted_at,
                    {$reviewerNameExpr}
             FROM listing_reviews lr
             {$reviewJoin}
             WHERE lr.listing_id = ? AND lr.status = 'approved'
             ORDER BY lr.submitted_at DESC
             LIMIT 10",
            [$listingId]
        );

        foreach ($rawReviews as $rv) {
            $reviewItems[] = [
                'id'                => bv_mobile_detail_int($rv['id'] ?? 0),
                'rating'            => bv_mobile_detail_int($rv['rating'] ?? 0),
                'title'             => bv_mobile_detail_clean_string($rv['title']   ?? ''),
                'comment'           => bv_mobile_detail_clean_string($rv['comment'] ?? ''),
                'is_verified_buyer' => (bool) ($rv['is_verified_buyer'] ?? false),
                'reviewer_name'     => bv_mobile_detail_clean_string($rv['reviewer_name'] ?? ''),
                'created_at'        => bv_mobile_detail_clean_string($rv['submitted_at']  ?? ''),
            ];
        }
    }

    // ── CTA ───────────────────────────────────────────────────────────────────
    $auctionEnabled = bv_mobile_detail_int($listingRow['auction_enabled'] ?? 0) === 1
                      || $saleFormat === 'auction';
    $offerEnabled   = $saleFormat === 'offer';
    $auctionStatus  = bv_mobile_detail_clean_string($listingRow['auction_status'] ?? '');

    $isSold     = $saleStatus === 'sold'     || $status === 'sold';
    $isReserved = $saleStatus === 'reserved';
    $isActive   = !$isSold && !$isReserved && $stockAvail > 0;
    $isLiveAuction = $auctionEnabled && $auctionStatus === 'live';

    $canCheckout = false;
    $canAddCart  = false;
    $canOffer    = false;
    $canBid      = false;
    $primaryLabel   = '';
    $secondaryLabel = '';
    $ctaMessage     = '';

    if ($isSold) {
        $ctaMessage   = 'This fish has been sold.';
        $primaryLabel = 'Sold';
    } elseif ($isReserved) {
        $ctaMessage   = 'This fish is currently on hold.';
        $primaryLabel = 'On Hold';
    } elseif ($auctionEnabled && $isLiveAuction) {
        $canBid       = true;
        $primaryLabel = 'Place a Bid';
        $ctaMessage   = 'Live auction in progress.';
    } elseif ($auctionEnabled && !$isLiveAuction) {
        $ctaMessage   = 'Auction not yet live.';
        $primaryLabel = 'Auction Coming Soon';
    } elseif ($offerEnabled && $stockAvail > 0) {
        $canOffer       = true;
        $canCheckout    = true;
        $primaryLabel   = 'Make an Offer';
        $secondaryLabel = 'Check Out This Fish';
        $ctaMessage     = 'Make an offer or buy now.';
    } elseif ($isActive) {
        $canCheckout    = true;
        $canAddCart     = true;
        $primaryLabel   = 'Check Out This Fish';
        $secondaryLabel = 'Add to Cart';
        $ctaMessage     = 'Available now — ready to ship.';
    } else {
        $ctaMessage   = 'This listing is not currently available.';
        $primaryLabel = 'Unavailable';
    }

    $ctaData = [
        'can_checkout'    => $canCheckout,
        'can_add_to_cart' => $canAddCart,
        'can_offer'       => $canOffer,
        'can_bid'         => $canBid,
        'primary_label'   => $primaryLabel,
        'secondary_label' => $secondaryLabel,
        'message'         => $ctaMessage,
    ];

    // ── Meta ──────────────────────────────────────────────────────────────────
    $metaData = [
        'seo_title'       => bv_mobile_detail_clean_string($listingRow['meta_title']        ?? $listingData['title']),
        'seo_description' => bv_mobile_detail_clean_string($listingRow['meta_description']  ?? $listingData['short_description']),
    ];

    // ── Final response ────────────────────────────────────────────────────────
    bv_mobile_detail_json(200, [
        'ok'   => true,
        'data' => [
            'listing' => $listingData,
            'ranking' => $rankingData,
            'media'   => $mediaItems,
            'seller'  => $sellerData,
            'reviews' => [
                'summary' => $reviewSummary,
                'items'   => $reviewItems,
            ],
            'cta'     => $ctaData,
            'meta'    => $metaData,
        ],
        'meta' => [
            'api_version'  => 'v1',
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);

} catch (\Throwable $e) {
    bv_mobile_detail_log('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_mobile_detail_json(500, [
        'ok'    => false,
        'error' => [
            'code'    => 'server_error',
            'message' => 'Something went wrong.',
        ],
    ]);
}
