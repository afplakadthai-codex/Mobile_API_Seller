<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/home.php
// Read-only home / discovery endpoint for the mobile app.
// Do NOT modify existing website files. This file is standalone.
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
// File lives at: /public_html/api/mobile/v1/home.php
// dirname(__DIR__, 3) → /public_html
// dirname(__DIR__, 4) → account root (parent of public_html)
// =============================================================================

if (!function_exists('bv_mobile_home_public_root')) {
    function bv_mobile_home_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_mobile_home_project_root')) {
    function bv_mobile_home_project_root(): string
    {
        return dirname(bv_mobile_home_public_root());
    }
}

// =============================================================================
// Helper functions
// =============================================================================

if (!function_exists('bv_mobile_home_json')) {
    function bv_mobile_home_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_mobile_home_error')) {
    function bv_mobile_home_error(string $code, string $message, int $statusCode = 400): void
    {
        bv_mobile_home_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

if (!function_exists('bv_mobile_home_log')) {
    function bv_mobile_home_log(string $message): void
    {
        error_log('[BV Mobile Home API] ' . $message);
        $line          = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        $logCandidates = [
            bv_mobile_home_project_root() . '/private_html/mobile_api.log',
            bv_mobile_home_public_root()  . '/logs/mobile_api.log',
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

if (!function_exists('bv_mobile_home_db')) {
    function bv_mobile_home_db(): ?object
    {
        static $cached = false;
        static $connection = null;
        if ($cached !== false) {
            return $connection;
        }
        $cached     = true;
        $publicRoot = bv_mobile_home_public_root();
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
            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $n) {
                if (isset($vars[$n]) && ($vars[$n] instanceof PDO || $vars[$n] instanceof mysqli)) {
                    $connection = $vars[$n];
                    return $connection;
                }
            }
            // 2) Connection object in $GLOBALS
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
                    error_log('[BV Mobile Home API] PDO connect failed: ' . $e->getMessage());
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
                    error_log('[BV Mobile Home API] mysqli connect failed: ' . $m->connect_error);
                } catch (\Throwable $e) {
                    error_log('[BV Mobile Home API] mysqli exception: ' . $e->getMessage());
                }
            }
            break;
        }
        return null;
    }
}

if (!function_exists('bv_mobile_home_table_exists')) {
    // Direct SELECT 1 probe — avoids SHOW TABLES LIKE false negatives
    function bv_mobile_home_table_exists(object $db, string $table): bool
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
            error_log('[BV Mobile Home API] table_exists error: ' . $e->getMessage());
        }
        return false;
    }
}

if (!function_exists('bv_mobile_home_columns')) {
    function bv_mobile_home_columns(object $db, string $table): array
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
            error_log('[BV Mobile Home API] columns error: ' . $e->getMessage());
        }
        return $cols;
    }
}

if (!function_exists('bv_mobile_home_has_col')) {
    function bv_mobile_home_has_col(object $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = in_array(strtolower($column), bv_mobile_home_columns($db, $table), true);
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_mobile_home_clean_string')) {
    function bv_mobile_home_clean_string($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5);
    }
}

if (!function_exists('bv_mobile_home_int')) {
    function bv_mobile_home_int($value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}

if (!function_exists('bv_mobile_home_float')) {
    function bv_mobile_home_float($value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}

if (!function_exists('bv_mobile_home_asset_url')) {
    function bv_mobile_home_asset_url(?string $path): string
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

if (!function_exists('bv_mobile_home_listing_url')) {
    function bv_mobile_home_listing_url(array $row): string
    {
        $base = rtrim(defined('BV_SITE_URL') ? BV_SITE_URL : 'https://www.bettavaro.com', '/');
        $slug = trim((string) ($row['slug'] ?? ''));
        $id   = bv_mobile_home_int($row['id'] ?? 0);
        if ($slug !== '') {
            return $base . '/listing.php?slug=' . urlencode($slug);
        }
        return $id > 0 ? $base . '/listing.php?id=' . $id : $base . '/listing.php';
    }
}

if (!function_exists('bv_mobile_home_money')) {
    function bv_mobile_home_money($amount, string $currency): array
    {
        $amt      = bv_mobile_home_float($amount, 0.0);
        $currency = strtoupper(trim($currency ?: 'USD'));
        return [
            'amount'    => $amt,
            'currency'  => $currency,
            'formatted' => $currency . ' ' . number_format($amt, 2),
        ];
    }
}

// ── Low-level query helpers ────────────────────────────────────────────────────

if (!function_exists('bv_home_query_rows')) {
    function bv_home_query_rows(object $db, string $sql, array $params = []): array
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
                    $types   = implode('', array_map(
                        static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'),
                        $params
                    ));
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
            error_log('[BV Mobile Home API] query_rows error: ' . $e->getMessage());
        }
        return [];
    }
}

if (!function_exists('bv_home_query_scalar')) {
    function bv_home_query_scalar(object $db, string $sql, array $params = [], $default = 0)
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
                if ($st === false) {
                    return $default;
                }
                if (!empty($params)) {
                    $types   = implode('', array_map(
                        static fn($v) => is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'),
                        $params
                    ));
                    $bindRef = [&$types];
                    foreach ($params as $k => $_) {
                        $bindRef[] = &$params[$k];
                    }
                    call_user_func_array([$st, 'bind_param'], $bindRef);
                }
                $st->execute();
                $res = $st->get_result();
                $row = $res ? $res->fetch_row() : null;
                $st->close();
                return $row ? $row[0] : $default;
            }
        } catch (\Throwable $e) {
            error_log('[BV Mobile Home API] query_scalar error: ' . $e->getMessage());
        }
        return $default;
    }
}

// ── Listing card formatter ────────────────────────────────────────────────────

if (!function_exists('bv_mobile_home_fetch_listing_cards')) {
    /**
     * Build a listing card array from raw DB rows.
     * Accepts column-existence flags and formats each row consistently.
     */
    function bv_mobile_home_fetch_listing_cards(array $rows, array $opts = []): array
    {
        $cards = [];
        foreach ($rows as $row) {
            $sellerId   = bv_mobile_home_int($row['seller_id'] ?? 0, 0);
            $sellerName = bv_mobile_home_clean_string($row['seller_name'] ?? '');
            if ($sellerName === '' && $sellerId > 0) {
                $sellerName = "Seller #{$sellerId}";
            }

            $currency   = bv_mobile_home_clean_string($row['currency'] ?? 'USD') ?: 'USD';
            $coverUrl   = bv_mobile_home_asset_url(bv_mobile_home_clean_string($row['cover_image'] ?? ''));
            $reviewAvg  = round(bv_mobile_home_float($row['review_avg']    ?? 0, 0.0), 2);
            $reviewCnt  = bv_mobile_home_int($row['review_count']          ?? 0, 0);
            $rankScore  = round(bv_mobile_home_float($row['ranking_score'] ?? 0, 0.0), 4);

            $cards[] = [
                'id'            => bv_mobile_home_int($row['id']),
                'title'         => bv_mobile_home_clean_string($row['title']       ?? ''),
                'slug'          => bv_mobile_home_clean_string($row['slug']        ?? ''),
                'url'           => bv_mobile_home_listing_url($row),
                'cover_image'   => $coverUrl,
                'price'         => bv_mobile_home_money($row['price'] ?? 0, $currency),
                'status'        => bv_mobile_home_clean_string($row['status']      ?? ''),
                'sale_status'   => bv_mobile_home_clean_string($row['sale_status'] ?? ''),
                'species'       => bv_mobile_home_clean_string($row['species']     ?? ''),
                'strain'        => bv_mobile_home_clean_string($row['strain']      ?? ''),
                'seller'        => [
                    'id'   => $sellerId > 0 ? $sellerId : null,
                    'name' => $sellerName,
                ],
                'rating'        => [
                    'average' => $reviewAvg,
                    'count'   => $reviewCnt,
                ],
                'ranking_score' => $rankScore,
                'created_at'    => bv_mobile_home_clean_string($row['created_at']  ?? ''),
            ];
        }
        return $cards;
    }
}

// =============================================================================
// Main execution
// =============================================================================
try {
    $db = bv_mobile_home_db();
    if ($db === null) {
        bv_mobile_home_log('No database connection available.');
        bv_mobile_home_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    if (!bv_mobile_home_table_exists($db, 'listings')) {
        bv_mobile_home_log('listings table not found.');
        bv_mobile_home_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // ── Detect optional tables ────────────────────────────────────────────────
    $hasUsers    = bv_mobile_home_table_exists($db, 'users');
    $hasSpProf   = bv_mobile_home_table_exists($db, 'seller_profiles');
    $hasReviews  = bv_mobile_home_table_exists($db, 'listing_reviews');
    $hasRanking  = bv_mobile_home_table_exists($db, 'listing_ranking_scores');

    // ── Detect listing columns ────────────────────────────────────────────────
    $lCols   = bv_mobile_home_columns($db, 'listings');
    $hasLCol = static fn(string $c): bool => in_array(strtolower($c), $lCols, true);

    $colSlug       = $hasLCol('slug');
    $colSellerId   = $hasLCol('seller_id');
    $colPrice      = $hasLCol('price');
    $colCurrency   = $hasLCol('currency');
    $colStatus     = $hasLCol('status');
    $colSaleStatus = $hasLCol('sale_status');
    $colSpecies    = $hasLCol('species');
    $colStrain     = $hasLCol('strain');
    $colCreatedAt  = $hasLCol('created_at');
    $colCoverImage = null;
    foreach (['cover_image', 'image', 'image_path', 'main_image', 'thumbnail'] as $cand) {
        if ($hasLCol($cand)) {
            $colCoverImage = $cand;
            break;
        }
    }

    // ── Detect user/seller name columns ──────────────────────────────────────
    $uCols      = $hasUsers ? bv_mobile_home_columns($db, 'users') : [];
    $hasUCol    = static fn(string $c): bool => in_array(strtolower($c), $uCols, true);
    $spCols     = $hasSpProf ? bv_mobile_home_columns($db, 'seller_profiles') : [];
    $hasSpCol   = static fn(string $c): bool => in_array(strtolower($c), $spCols, true);

    // ── Review aggregation column names ──────────────────────────────────────
    $rvCols          = $hasReviews ? bv_mobile_home_columns($db, 'listing_reviews') : [];
    $rvRatingCol     = null;
    $rvListingIdCol  = null;
    $rvStatusCol     = null;
    if ($hasReviews) {
        foreach (['rating', 'score', 'stars', 'review_score'] as $c) {
            if (in_array($c, $rvCols, true)) { $rvRatingCol = $c; break; }
        }
        foreach (['listing_id', 'item_id', 'product_id'] as $c) {
            if (in_array($c, $rvCols, true)) { $rvListingIdCol = $c; break; }
        }
        foreach (['status', 'approved', 'is_approved'] as $c) {
            if (in_array($c, $rvCols, true)) { $rvStatusCol = $c; break; }
        }
        if (!$rvRatingCol || !$rvListingIdCol) {
            $hasReviews = false;
        }
    }

    // ── Public status constants (from live schema) ────────────────────────────
    // listings.status enum('draft','pending','active','sold','hidden')
    $publicStatuses     = ['active'];
    $publicSaleStatuses = ['available', 'reserved', 'sold'];

    // ── Shared SQL building blocks ────────────────────────────────────────────

    // Status WHERE clause + params
    $statusWhere  = '';
    $statusParams = [];
    if ($colStatus) {
        $ph           = implode(', ', array_fill(0, count($publicStatuses), '?'));
        $statusWhere  = "listings.status IN ({$ph})";
        $statusParams = $publicStatuses;
    }
    if ($colSaleStatus) {
        $ph2            = implode(', ', array_fill(0, count($publicSaleStatuses), '?'));
        $statusWhere   .= ($statusWhere ? ' AND ' : '') . "listings.sale_status IN ({$ph2})";
        $statusParams   = array_merge($statusParams, $publicSaleStatuses);
    }
    $whereSQL = $statusWhere ? "WHERE {$statusWhere}" : '';

    // Common SELECT columns for listing cards
    $cardCols   = ['listings.id'];
    if ($hasLCol('title'))     $cardCols[] = 'listings.title';
    if ($colSlug)              $cardCols[] = 'listings.slug';
    if ($colPrice)             $cardCols[] = 'listings.price';
    if ($colCurrency)          $cardCols[] = 'listings.currency';
    if ($colStatus)            $cardCols[] = 'listings.status';
    if ($colSaleStatus)        $cardCols[] = 'listings.sale_status';
    if ($colSpecies)           $cardCols[] = 'listings.species';
    if ($colStrain)            $cardCols[] = 'listings.strain';
    if ($colCreatedAt)         $cardCols[] = 'listings.created_at';
    if ($colCoverImage)        $cardCols[] = "listings.{$colCoverImage} AS cover_image";
    if ($colSellerId)          $cardCols[] = 'listings.seller_id';

    // Seller name expression
    // users: first_name, last_name, email (no farm_name / display_name)
    // seller_profiles: farm_name, public_display_name (JOIN via user_id)
    $sellerJoin = '';
    $spJoin     = '';
    if ($hasUsers && $colSellerId) {
        $sellerJoin = ' LEFT JOIN users u ON u.id = listings.seller_id';
        // Build seller name COALESCE: farm_name (sp) > public_display_name (sp) > first+last (u) > email (u) > Seller #id
        $nameParts = [];
        if ($hasSpProf && $hasSpCol('farm_name')) {
            $spJoin      = ' LEFT JOIN seller_profiles sp ON sp.user_id = listings.seller_id';
            $nameParts[] = "NULLIF(TRIM(sp.farm_name), '')";
        }
        if ($hasSpProf && $hasSpCol('public_display_name')) {
            if (!$spJoin) { $spJoin = ' LEFT JOIN seller_profiles sp ON sp.user_id = listings.seller_id'; }
            $nameParts[] = "NULLIF(TRIM(sp.public_display_name), '')";
        }
        if ($hasUCol('first_name') && $hasUCol('last_name')) {
            $nameParts[] = "NULLIF(TRIM(CONCAT_WS(' ', NULLIF(TRIM(u.first_name),''), NULLIF(TRIM(u.last_name),''))), '')";
        } elseif ($hasUCol('first_name')) {
            $nameParts[] = "NULLIF(TRIM(u.first_name), '')";
        }
        if ($hasUCol('email')) {
            $nameParts[] = "NULLIF(TRIM(u.email), '')";
        }
        $nameParts[]    = "CONCAT('Seller #', listings.seller_id)";
        $coalesceExpr   = count($nameParts) > 1
            ? 'COALESCE(' . implode(', ', $nameParts) . ')'
            : reset($nameParts);
        $cardCols[]     = $coalesceExpr . ' AS seller_name';
    }

    // Ranking score
    $rankJoin = '';
    if ($hasRanking) {
        $rankJoin   = ' LEFT JOIN listing_ranking_scores lrs ON lrs.listing_id = listings.id';
        $cardCols[] = 'COALESCE(lrs.final_score, 0) AS ranking_score';
    } else {
        $cardCols[] = '0 AS ranking_score';
    }

    // Review aggregation subquery
    if ($hasReviews) {
        $rvApprWhere = '';
        if ($rvStatusCol) {
            $rvApprWhere = "AND (rv.{$rvStatusCol} = 'approved' OR rv.{$rvStatusCol} = 1)";
        }
        $cardCols[] = "(SELECT COALESCE(AVG(rv.{$rvRatingCol}), 0) FROM listing_reviews rv
                        WHERE rv.{$rvListingIdCol} = listings.id {$rvApprWhere}) AS review_avg";
        $cardCols[] = "(SELECT COUNT(*) FROM listing_reviews rv
                        WHERE rv.{$rvListingIdCol} = listings.id {$rvApprWhere}) AS review_count";
    } else {
        $cardCols[] = '0 AS review_avg';
        $cardCols[] = '0 AS review_count';
    }

    $selectClause = 'SELECT ' . implode(', ', $cardCols);
    $fromClause   = 'FROM listings' . $sellerJoin . $spJoin . $rankJoin;

    // Default ORDER expressions
    $orderNewest  = $colCreatedAt ? 'ORDER BY listings.created_at DESC, listings.id DESC' : 'ORDER BY listings.id DESC';
    $orderRanking = $hasRanking ? 'ORDER BY ranking_score DESC, listings.id DESC' : $orderNewest;

    // ── 1) Hero ───────────────────────────────────────────────────────────────
    $heroData = [
        'title'         => 'Bettavaro',
        'subtitle'      => 'Premium betta marketplace',
        'message'       => 'Discover collector-grade bettas from trusted breeders.',
        'primary_label' => 'Explore Bettas',
        'primary_url'   => '/listings.php',
    ];

    // ── 2) Featured (up to 6) ─────────────────────────────────────────────────
    $featuredRows = bv_home_query_rows(
        $db,
        "{$selectClause} {$fromClause} {$whereSQL} {$orderRanking} LIMIT 6",
        $statusParams
    );
    $featuredCards = bv_mobile_home_fetch_listing_cards($featuredRows);

    // ── 3) Newest (up to 10) ─────────────────────────────────────────────────
    $newestRows = bv_home_query_rows(
        $db,
        "{$selectClause} {$fromClause} {$whereSQL} {$orderNewest} LIMIT 10",
        $statusParams
    );
    $newestCards = bv_mobile_home_fetch_listing_cards($newestRows);

    // ── 4) Ranking (up to 10) ────────────────────────────────────────────────
    $rankingCards = [];
    if ($hasRanking) {
        $rankingRows  = bv_home_query_rows(
            $db,
            "{$selectClause} {$fromClause} {$whereSQL} {$orderRanking} LIMIT 10",
            $statusParams
        );
        $rankingCards = bv_mobile_home_fetch_listing_cards($rankingRows);
    }

    // ── 5) Species summary ───────────────────────────────────────────────────
    $speciesData = [];
    if ($colSpecies) {
        $base       = rtrim(defined('BV_SITE_URL') ? BV_SITE_URL : 'https://www.bettavaro.com', '/');
        $spRows     = bv_home_query_rows(
            $db,
            "SELECT listings.species, COUNT(*) AS species_count
             FROM listings
             {$whereSQL}
             AND listings.species IS NOT NULL AND TRIM(listings.species) != ''
             GROUP BY listings.species
             ORDER BY species_count DESC, listings.species ASC
             LIMIT 20",
            $statusParams
        );
        foreach ($spRows as $sp) {
            $name = bv_mobile_home_clean_string($sp['species'] ?? '');
            if ($name === '') {
                continue;
            }
            $speciesData[] = [
                'name'  => $name,
                'count' => bv_mobile_home_int($sp['species_count'] ?? 0),
                'url'   => $base . '/listings.php?species=' . urlencode($name),
            ];
        }
    }

    // ── 6) Stats ─────────────────────────────────────────────────────────────

    // Active listing count
    $activeListings = 0;
    if ($colStatus) {
        $activeListings = bv_mobile_home_int(
            bv_home_query_scalar(
                $db,
                "SELECT COUNT(*) FROM listings WHERE status IN (" . implode(', ', array_fill(0, count($publicStatuses), '?')) . ")",
                $publicStatuses,
                0
            )
        );
    } else {
        $activeListings = bv_mobile_home_int(
            bv_home_query_scalar($db, 'SELECT COUNT(*) FROM listings', [], 0)
        );
    }

    // Available listing count (sale_status = available among public listings)
    $availableListings = 0;
    if ($colSaleStatus && $colStatus) {
        $avParams          = array_merge($publicStatuses, ['available']);
        $ph                = implode(', ', array_fill(0, count($publicStatuses), '?'));
        $availableListings = bv_mobile_home_int(
            bv_home_query_scalar(
                $db,
                "SELECT COUNT(*) FROM listings WHERE status IN ({$ph}) AND sale_status = ?",
                $avParams,
                0
            )
        );
    } elseif ($colSaleStatus) {
        $availableListings = bv_mobile_home_int(
            bv_home_query_scalar(
                $db,
                "SELECT COUNT(*) FROM listings WHERE sale_status = ?",
                ['available'],
                0
            )
        );
    } else {
        $availableListings = $activeListings;
    }

    // Distinct active seller count (sellers who have at least one public listing)
    $sellerCount = 0;
    if ($colSellerId && $colStatus) {
        $ph          = implode(', ', array_fill(0, count($publicStatuses), '?'));
        $sellerCount = bv_mobile_home_int(
            bv_home_query_scalar(
                $db,
                "SELECT COUNT(DISTINCT seller_id) FROM listings WHERE status IN ({$ph}) AND seller_id IS NOT NULL",
                $publicStatuses,
                0
            )
        );
    } elseif ($hasUsers) {
        $sellerCount = bv_mobile_home_int(
            bv_home_query_scalar(
                $db,
                "SELECT COUNT(*) FROM users WHERE role = 'seller'",
                [],
                0
            )
        );
    }

    // Review count (approved only)
    $reviewCount = 0;
    if ($hasReviews) {
        $rvApprWhere = '';
        $rvApprParam = [];
        if ($rvStatusCol) {
            $rvApprWhere = "WHERE {$rvStatusCol} = 'approved'";
        }
        $reviewCount = bv_mobile_home_int(
            bv_home_query_scalar(
                $db,
                "SELECT COUNT(*) FROM listing_reviews {$rvApprWhere}",
                $rvApprParam,
                0
            )
        );
    }

    $statsData = [
        'active_listings'    => $activeListings,
        'available_listings' => $availableListings,
        'seller_count'       => $sellerCount,
        'review_count'       => $reviewCount,
    ];

    // ── Build response ────────────────────────────────────────────────────────
    bv_mobile_home_json(200, [
        'ok'   => true,
        'data' => [
            'hero'     => $heroData,
            'featured' => $featuredCards,
            'newest'   => $newestCards,
            'ranking'  => $rankingCards,
            'species'  => $speciesData,
            'stats'    => $statsData,
        ],
        'meta' => [
            'api_version'  => 'v1',
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);

} catch (\Throwable $e) {
    bv_mobile_home_log('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_mobile_home_json(500, [
        'ok'    => false,
        'error' => [
            'code'    => 'server_error',
            'message' => 'Something went wrong.',
        ],
    ]);
}
