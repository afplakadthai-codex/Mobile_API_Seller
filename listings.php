<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/listings.php
// Read-only public marketplace listings endpoint.
// Do NOT modify existing website files. This file is standalone.
// CORS: Not opened broadly. Configure Access-Control-Allow-Origin later
//       when mobile app domain or API gateway is finalized.
// =============================================================================

// Prevent any accidental HTML output
while (ob_get_level() > 0) {
    @ob_end_clean();
}

// ── Security headers ─────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// ── Only allow GET ────────────────────────────────────────────────────────────
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
// This file lives at: /public_html/api/mobile/v1/listings.php
// dirname(__DIR__, 3) resolves to /public_html  (the web root)
// dirname(__DIR__, 4) resolves to the account root (parent of public_html)
// =============================================================================

if (!function_exists('bv_mobile_api_public_root')) {
    function bv_mobile_api_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_mobile_api_project_root')) {
    function bv_mobile_api_project_root(): string
    {
        return dirname(bv_mobile_api_public_root());
    }
}

// =============================================================================
// Helper functions (all guarded with if (!function_exists(...)))
// =============================================================================

if (!function_exists('bv_mobile_api_json')) {
    function bv_mobile_api_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_mobile_api_error')) {
    function bv_mobile_api_error(string $code, string $message, int $statusCode = 400): void
    {
        bv_mobile_api_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

if (!function_exists('bv_mobile_api_db')) {
    /**
     * Returns a PDO connection (preferred) or a mysqli connection.
     * Searches known config paths relative to the project root.
     * Returns null on failure.
     */
    function bv_mobile_api_db(): ?object
    {
        static $cached = false;
        static $connection = null;

        if ($cached !== false) {
            return $connection;
        }
        $cached = true;

        // Resolve public_html root dynamically — safe on DirectAdmin hosting.
        // No hardcoded /public_html literals anywhere below.
        $publicRoot = bv_mobile_api_public_root();

        $candidates = [
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
            $publicRoot . '/includes/config.php',
        ];

        foreach ($candidates as $cfg) {
            if (is_file($cfg)) {
                // Isolate the include so it sets variables in its own scope.
                // Pre-initialise both credential vars and common connection object
                // vars so compact() captures whatever config/db.php sets locally.
                $loader = static function (string $path): array {
                    $db_host = $db_user = $db_pass = $db_name = $db_port = null;
                    $host = $user = $pass = $name = $port = null;
                    $DB_HOST = $DB_USER = $DB_PASS = $DB_NAME = $DB_PORT = null;
                    $dsn = null;
                    // Connection objects — pre-declared so compact() sees them
                    // even if config/db.php assigns them as plain local variables.
                    $pdo = $conn = $db = $mysqli = $link = null;
                    /** @noinspection PhpIncludeInspection */
                    @include $path;
                    return compact(
                        'db_host', 'db_user', 'db_pass', 'db_name', 'db_port',
                        'host', 'user', 'pass', 'name', 'port',
                        'DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'DB_PORT',
                        'dsn',
                        'pdo', 'conn', 'db', 'mysqli', 'link'
                    );
                };
                $vars = $loader($cfg);

                // 1) Prefer a ready-made connection object returned by the config file
                foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $name) {
                    if (isset($vars[$name]) && ($vars[$name] instanceof PDO || $vars[$name] instanceof mysqli)) {
                        $connection = $vars[$name];
                        return $connection;
                    }
                }

                // 2) Fallback: connection object injected into $GLOBALS by the config file
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

                // Try PDO first
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
                        error_log('[BV Mobile API] PDO connect failed: ' . $e->getMessage());
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
                        error_log('[BV Mobile API] mysqli connect failed: ' . $m->connect_error);
                    } catch (\Throwable $e) {
                        error_log('[BV Mobile API] mysqli exception: ' . $e->getMessage());
                    }
                }

                // Config found but connection failed — stop searching
                break;
            }
        }

        return null;
    }
}

if (!function_exists('bv_mobile_api_table_exists')) {
    function bv_mobile_api_table_exists(object $db, string $table): bool
    {
        // Validate table name — allow only safe identifier characters
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }

        // Probe with a direct SELECT rather than SHOW TABLES LIKE, which can
        // return false negatives when the DB user lacks SHOW privilege.
        $sql = "SELECT 1 FROM `{$table}` LIMIT 1";

        try {
            if ($db instanceof PDO) {
                // Temporarily switch to exception mode so a missing table
                // throws rather than returning false silently, then restore.
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
            error_log('[BV Mobile API] table_exists error: ' . $e->getMessage());
        }

        return false;
    }
}

if (!function_exists('bv_mobile_api_columns')) {
    function bv_mobile_api_columns(object $db, string $table): array
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
            error_log('[BV Mobile API] columns error: ' . $e->getMessage());
        }
        return $cols;
    }
}

if (!function_exists('bv_mobile_api_has_col')) {
    function bv_mobile_api_has_col(object $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (!array_key_exists($key, $cache)) {
            $cache[$key] = in_array(strtolower($column), bv_mobile_api_columns($db, $table), true);
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_mobile_api_clean_string')) {
    function bv_mobile_api_clean_string($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        return htmlspecialchars_decode(
            strip_tags((string) $value),
            ENT_QUOTES | ENT_HTML5
        );
    }
}

if (!function_exists('bv_mobile_api_int')) {
    function bv_mobile_api_int($value, int $default = 0): int
    {
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }
}

if (!function_exists('bv_mobile_api_float')) {
    function bv_mobile_api_float($value, float $default = 0.0): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        return $default;
    }
}

if (!function_exists('bv_mobile_api_asset_url')) {
    function bv_mobile_api_asset_url(?string $path): string
    {
        if (!$path || trim($path) === '') {
            return '';
        }
        $path = trim($path);
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        $base = rtrim((defined('BV_SITE_URL') ? BV_SITE_URL : 'https://www.bettavaro.com'), '/');
        $path = '/' . ltrim($path, '/');
        return $base . $path;
    }
}

if (!function_exists('bv_mobile_api_listing_url')) {
    function bv_mobile_api_listing_url(array $row): string
    {
        $base = rtrim((defined('BV_SITE_URL') ? BV_SITE_URL : 'https://www.bettavaro.com'), '/');
        $slug = trim((string) ($row['slug'] ?? ''));
        $id   = bv_mobile_api_int($row['id'] ?? 0);
        if ($slug !== '') {
            return $base . '/listing.php?slug=' . urlencode($slug);
        }
        if ($id > 0) {
            return $base . '/listing.php?id=' . $id;
        }
        return $base . '/listing.php';
    }
}

if (!function_exists('bv_mobile_api_money')) {
    function bv_mobile_api_money($amount, string $currency): array
    {
        $amt      = bv_mobile_api_float($amount, 0.0);
        $currency = strtoupper(trim($currency ?: 'USD'));
        return [
            'amount'    => $amt,
            'currency'  => $currency,
            'formatted' => $currency . ' ' . number_format($amt, 2),
        ];
    }
}

// ── Private log helper ────────────────────────────────────────────────────────
if (!function_exists('bv_mobile_api_log')) {
    function bv_mobile_api_log(string $message): void
    {
        // Always log to PHP error_log as the guaranteed fallback
        error_log('[BV Mobile API] ' . $message);

        // Attempt to write to a file log — never crash the API if this fails.
        // Candidates use dynamic roots — no hardcoded /public_html literals.
        $line = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;

        $logCandidates = [
            bv_mobile_api_project_root() . '/private_html/mobile_api.log',
            bv_mobile_api_public_root()  . '/logs/mobile_api.log',
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

// =============================================================================
// Main execution
// =============================================================================
try {
    // ── Connect to DB ─────────────────────────────────────────────────────────
    $db = bv_mobile_api_db();
    if ($db === null) {
        bv_mobile_api_log('No database connection available.');
        bv_mobile_api_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    // ── Check listings table ──────────────────────────────────────────────────
    if (!bv_mobile_api_table_exists($db, 'listings')) {
        bv_mobile_api_log('listings table not found.');
        bv_mobile_api_error('not_found', 'Listings data is not available.', 404);
    }

    // ── Detect optional tables ────────────────────────────────────────────────
    $hasUsers   = bv_mobile_api_table_exists($db, 'users');
    $hasReviews = bv_mobile_api_table_exists($db, 'listing_reviews');
    $hasRanking = bv_mobile_api_table_exists($db, 'listing_ranking_scores');

    // ── Detect columns on listings ────────────────────────────────────────────
    $listingCols = bv_mobile_api_columns($db, 'listings');
    $hasCol      = static function (string $col) use ($listingCols): bool {
        return in_array(strtolower($col), $listingCols, true);
    };

    if (!$hasCol('id')) {
        bv_mobile_api_error('schema_error', 'Unexpected database schema.', 500);
    }

    $colSlug        = $hasCol('slug');
    $colSellerId    = $hasCol('seller_id');
    $colPrice       = $hasCol('price');
    $colCurrency    = $hasCol('currency');
    $colStatus      = $hasCol('status');
    $colSaleStatus  = $hasCol('sale_status');
    $colSpecies     = $hasCol('species');
    $colStrain      = $hasCol('strain');
    $colGrade       = $hasCol('grade');
    $colSex         = $hasCol('sex');
    $colColor       = $hasCol('color') ? 'color' : ($hasCol('colour') ? 'colour' : null);
    $colTailType    = $hasCol('tail_type');
    $colCountry     = $hasCol('country');
    $colCity        = $hasCol('city');
    $colCreatedAt   = $hasCol('created_at');
    $colDescription = $hasCol('description');

    $colCoverImage = null;
    foreach (['cover_image', 'image', 'image_path', 'main_image', 'thumbnail'] as $cand) {
        if ($hasCol($cand)) {
            $colCoverImage = $cand;
            break;
        }
    }

    // ── Detect user columns ───────────────────────────────────────────────────
    $userCols        = $hasUsers ? bv_mobile_api_columns($db, 'users') : [];
    $hasUserCol      = static function (string $col) use ($userCols): bool {
        return in_array(strtolower($col), $userCols, true);
    };
    $uColFarmName    = $hasUserCol('farm_name');
    $uColDisplayName = $hasUserCol('display_name');
    $uColFirstName   = $hasUserCol('first_name');
    $uColLastName    = $hasUserCol('last_name');
    $uColEmail       = $hasUserCol('email');

    // ── Review table columns ──────────────────────────────────────────────────
    $reviewApprovedCol  = null;
    $reviewRatingCol    = null;
    $reviewListingIdCol = null;
    if ($hasReviews) {
        $rvCols = bv_mobile_api_columns($db, 'listing_reviews');
        foreach (['approved', 'status', 'is_approved', 'is_active', 'active'] as $cand) {
            if (in_array($cand, $rvCols, true)) {
                $reviewApprovedCol = $cand;
                break;
            }
        }
        foreach (['rating', 'score', 'stars', 'review_score'] as $cand) {
            if (in_array($cand, $rvCols, true)) {
                $reviewRatingCol = $cand;
                break;
            }
        }
        foreach (['listing_id', 'item_id', 'product_id'] as $cand) {
            if (in_array($cand, $rvCols, true)) {
                $reviewListingIdCol = $cand;
                break;
            }
        }
        if (!$reviewRatingCol || !$reviewListingIdCol) {
            $hasReviews = false;
        }
    }

    // ── Parse query parameters ────────────────────────────────────────────────
    $page    = max(1, bv_mobile_api_int($_GET['page']     ?? 1, 1));
    $perPage = min(50, max(1, bv_mobile_api_int($_GET['per_page'] ?? 20, 20)));
    $offset  = ($page - 1) * $perPage;

    $qSearch  = trim((string) ($_GET['q']         ?? ''));
    $qSpecies = trim((string) ($_GET['species']    ?? ''));
    $qStrain  = trim((string) ($_GET['strain']     ?? ''));
    $qCountry = trim((string) ($_GET['country']    ?? ''));
    $qCity    = trim((string) ($_GET['city']       ?? ''));
    $qSeller  = bv_mobile_api_int($_GET['seller_id'] ?? 0, 0);
    $qStatus  = trim((string) ($_GET['status']     ?? ''));
    $qSort    = trim((string) ($_GET['sort']       ?? ''));

    $publicStatuses     = ['active', 'available', 'published'];
    $publicSaleStatuses = ['available', 'reserved', 'sold'];

    if ($qStatus !== '' && !in_array(strtolower($qStatus), $publicStatuses, true)) {
        $qStatus = '';
    }

    $allowedSorts = ['newest', 'price_low', 'price_high', 'ranking'];
    if (!in_array($qSort, $allowedSorts, true)) {
        $qSort = $hasRanking ? 'ranking' : 'newest';
    }
    if ($qSort === 'ranking' && !$hasRanking) {
        $qSort = 'newest';
    }

    // ── Build SELECT clause ───────────────────────────────────────────────────
    $selectParts = ['listings.id'];
    if ($hasCol('title'))  $selectParts[] = 'listings.title';
    if ($colSlug)          $selectParts[] = 'listings.slug';
    if ($colPrice)         $selectParts[] = 'listings.price';
    if ($colCurrency)      $selectParts[] = 'listings.currency';
    if ($colStatus)        $selectParts[] = 'listings.status';
    if ($colSaleStatus)    $selectParts[] = 'listings.sale_status';
    if ($colSpecies)       $selectParts[] = 'listings.species';
    if ($colStrain)        $selectParts[] = 'listings.strain';
    if ($colGrade)         $selectParts[] = 'listings.grade';
    if ($colSex)           $selectParts[] = 'listings.sex';
    if ($colColor)         $selectParts[] = "listings.{$colColor} AS color";
    if ($colTailType)      $selectParts[] = 'listings.tail_type';
    if ($colCountry)       $selectParts[] = 'listings.country';
    if ($colCity)          $selectParts[] = 'listings.city';
    if ($colCreatedAt)     $selectParts[] = 'listings.created_at';
    if ($colCoverImage)    $selectParts[] = "listings.{$colCoverImage} AS cover_image";
    if ($colSellerId)      $selectParts[] = 'listings.seller_id';

    if ($hasUsers && $colSellerId) {
        $nameParts = [];
        if ($uColFarmName)                   $nameParts[] = 'NULLIF(TRIM(u.farm_name), \'\')';
        if ($uColDisplayName)                $nameParts[] = 'NULLIF(TRIM(u.display_name), \'\')';
        if ($uColFirstName && $uColLastName) {
            $nameParts[] = 'NULLIF(TRIM(CONCAT_WS(\' \', NULLIF(TRIM(u.first_name),\'\'), NULLIF(TRIM(u.last_name),\'\'))),\'\')';
        } elseif ($uColFirstName) {
            $nameParts[] = 'NULLIF(TRIM(u.first_name), \'\')';
        }
        if ($uColEmail)                      $nameParts[] = 'NULLIF(TRIM(u.email), \'\')';
        $nameParts[] = 'CONCAT(\'Seller #\', listings.seller_id)';

        $coalesceExpr  = count($nameParts) > 1
            ? 'COALESCE(' . implode(', ', $nameParts) . ')'
            : reset($nameParts);
        $selectParts[] = $coalesceExpr . ' AS seller_name';
        $selectParts[] = 'u.id AS seller_user_id';
    }

    if ($hasRanking) {
        $selectParts[] = 'COALESCE(lrs.final_score, 0) AS ranking_score';
    } else {
        $selectParts[] = '0 AS ranking_score';
    }

    if ($hasReviews) {
        $approvedWhere = '';
        if ($reviewApprovedCol) {
            $approvedWhere = "AND (rv.{$reviewApprovedCol} = 1 OR rv.{$reviewApprovedCol} = 'approved')";
        }
        $selectParts[] = "(
            SELECT COALESCE(AVG(rv.{$reviewRatingCol}), 0)
            FROM listing_reviews rv
            WHERE rv.{$reviewListingIdCol} = listings.id {$approvedWhere}
        ) AS review_avg";
        $selectParts[] = "(
            SELECT COUNT(*)
            FROM listing_reviews rv
            WHERE rv.{$reviewListingIdCol} = listings.id {$approvedWhere}
        ) AS review_count";
    } else {
        $selectParts[] = '0 AS review_avg';
        $selectParts[] = '0 AS review_count';
    }

    // ── Build FROM / JOIN ─────────────────────────────────────────────────────
    $fromClause = 'FROM listings';
    $joins      = '';
    if ($hasUsers && $colSellerId) {
        $joins .= ' LEFT JOIN users u ON u.id = listings.seller_id';
    }
    if ($hasRanking) {
        $joins .= ' LEFT JOIN listing_ranking_scores lrs ON lrs.listing_id = listings.id';
    }

    // ── Build WHERE clause ────────────────────────────────────────────────────
    $whereParts = [];
    $params     = [];

    if ($colStatus) {
        if ($qStatus !== '') {
            $whereParts[] = 'listings.status = ?';
            $params[]     = $qStatus;
        } else {
            $placeholders = implode(', ', array_fill(0, count($publicStatuses), '?'));
            $whereParts[] = "listings.status IN ({$placeholders})";
            foreach ($publicStatuses as $s) {
                $params[] = $s;
            }
        }
    }

    if ($colSaleStatus) {
        $placeholders = implode(', ', array_fill(0, count($publicSaleStatuses), '?'));
        $whereParts[] = "listings.sale_status IN ({$placeholders})";
        foreach ($publicSaleStatuses as $s) {
            $params[] = $s;
        }
    }

    if ($qSearch !== '') {
        $searchCols = [];
        if ($hasCol('title'))  $searchCols[] = 'listings.title';
        if ($colStrain)        $searchCols[] = 'listings.strain';
        if ($colSpecies)       $searchCols[] = 'listings.species';
        if ($colDescription)   $searchCols[] = 'listings.description';

        if (!empty($searchCols)) {
            $likeParts = [];
            foreach ($searchCols as $sc) {
                $likeParts[] = "{$sc} LIKE ?";
                $params[]    = '%' . $qSearch . '%';
            }
            $whereParts[] = '(' . implode(' OR ', $likeParts) . ')';
        }
    }

    if ($qSpecies !== '' && $colSpecies) {
        $whereParts[] = 'listings.species LIKE ?';
        $params[]     = '%' . $qSpecies . '%';
    }

    if ($qStrain !== '' && $colStrain) {
        $whereParts[] = 'listings.strain LIKE ?';
        $params[]     = '%' . $qStrain . '%';
    }

    if ($qCountry !== '' && $colCountry) {
        $whereParts[] = 'listings.country LIKE ?';
        $params[]     = '%' . $qCountry . '%';
    }

    if ($qCity !== '' && $colCity) {
        $whereParts[] = 'listings.city LIKE ?';
        $params[]     = '%' . $qCity . '%';
    }

    if ($qSeller > 0 && $colSellerId) {
        $whereParts[] = 'listings.seller_id = ?';
        $params[]     = $qSeller;
    }

    $whereSQL = !empty($whereParts) ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

    // ── Build ORDER BY ────────────────────────────────────────────────────────
    $orderSQL = match ($qSort) {
        'price_low'  => $colPrice   ? 'ORDER BY listings.price ASC,  listings.id DESC' : 'ORDER BY listings.id DESC',
        'price_high' => $colPrice   ? 'ORDER BY listings.price DESC, listings.id DESC' : 'ORDER BY listings.id DESC',
        'ranking'    => $hasRanking ? 'ORDER BY ranking_score DESC, listings.id DESC'  : 'ORDER BY listings.id DESC',
        default      => $colCreatedAt ? 'ORDER BY listings.created_at DESC, listings.id DESC' : 'ORDER BY listings.id DESC',
    };

    // ── Count query ───────────────────────────────────────────────────────────
    $countSQL = "SELECT COUNT(*) AS total {$fromClause} {$joins} {$whereSQL}";

    // ── Data query ────────────────────────────────────────────────────────────
    $selectSQL = 'SELECT ' . implode(', ', $selectParts)
        . " {$fromClause} {$joins} {$whereSQL} {$orderSQL}"
        . ' LIMIT ? OFFSET ?';

    // ── Execute queries ───────────────────────────────────────────────────────
    $totalCount = 0;
    $rows       = [];

    if ($db instanceof PDO) {
        $stCount = $db->prepare($countSQL);
        $stCount->execute($params);
        $totalCount = (int) ($stCount->fetch()['total'] ?? 0);

        $dataParams = array_merge($params, [$perPage, $offset]);
        $stData     = $db->prepare($selectSQL);
        $stData->execute($dataParams);
        $rows = $stData->fetchAll();

    } elseif ($db instanceof mysqli) {
        $buildTypes = static function (array $vals): string {
            $t = '';
            foreach ($vals as $v) {
                if (is_int($v))       { $t .= 'i'; }
                elseif (is_float($v)) { $t .= 'd'; }
                else                  { $t .= 's'; }
            }
            return $t;
        };

        $stCount = $db->prepare($countSQL);
        if ($stCount === false) {
            throw new \RuntimeException('mysqli prepare (count) failed: ' . $db->error);
        }
        if (!empty($params)) {
            $types   = $buildTypes($params);
            $bindRef = [&$types];
            foreach ($params as $k => $_) {
                $bindRef[] = &$params[$k];
            }
            call_user_func_array([$stCount, 'bind_param'], $bindRef);
        }
        $stCount->execute();
        $res        = $stCount->get_result();
        $totalCount = (int) (($res ? $res->fetch_assoc() : [])['total'] ?? 0);
        $stCount->close();

        $dataParams = array_merge($params, [$perPage, $offset]);
        $stData     = $db->prepare($selectSQL);
        if ($stData === false) {
            throw new \RuntimeException('mysqli prepare (data) failed: ' . $db->error);
        }
        $types   = $buildTypes($dataParams);
        $bindRef = [&$types];
        foreach ($dataParams as $k => $_) {
            $bindRef[] = &$dataParams[$k];
        }
        call_user_func_array([$stData, 'bind_param'], $bindRef);
        $stData->execute();
        $res = $stData->get_result();
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stData->close();
    }

    // ── Format items ──────────────────────────────────────────────────────────
    $items = [];
    foreach ($rows as $row) {
        $sellerId   = $colSellerId ? bv_mobile_api_int($row['seller_id'] ?? 0, 0) : 0;
        $sellerName = bv_mobile_api_clean_string($row['seller_name'] ?? ($sellerId > 0 ? "Seller #{$sellerId}" : ''));
        if ($sellerName === '' && $sellerId > 0) {
            $sellerName = "Seller #{$sellerId}";
        }

        $coverRaw = $row['cover_image'] ?? '';
        $coverUrl = bv_mobile_api_asset_url(bv_mobile_api_clean_string($coverRaw));

        $currency = bv_mobile_api_clean_string($row['currency'] ?? 'USD') ?: 'USD';
        $price    = bv_mobile_api_money($row['price'] ?? 0, $currency);

        $reviewAvg = round(bv_mobile_api_float($row['review_avg']    ?? 0, 0.0), 2);
        $reviewCnt = bv_mobile_api_int($row['review_count']          ?? 0, 0);
        $rankScore = round(bv_mobile_api_float($row['ranking_score'] ?? 0, 0.0), 4);

        $items[] = [
            'id'            => bv_mobile_api_int($row['id']),
            'title'         => bv_mobile_api_clean_string($row['title']       ?? ''),
            'slug'          => bv_mobile_api_clean_string($row['slug']        ?? ''),
            'url'           => bv_mobile_api_listing_url($row),
            'cover_image'   => $coverUrl,
            'price'         => $price,
            'status'        => bv_mobile_api_clean_string($row['status']      ?? ''),
            'sale_status'   => bv_mobile_api_clean_string($row['sale_status'] ?? ''),
            'species'       => bv_mobile_api_clean_string($row['species']     ?? ''),
            'strain'        => bv_mobile_api_clean_string($row['strain']      ?? ''),
            'grade'         => bv_mobile_api_clean_string($row['grade']       ?? ''),
            'sex'           => bv_mobile_api_clean_string($row['sex']         ?? ''),
            'color'         => bv_mobile_api_clean_string($row['color']       ?? ''),
            'tail_type'     => bv_mobile_api_clean_string($row['tail_type']   ?? ''),
            'country'       => bv_mobile_api_clean_string($row['country']     ?? ''),
            'city'          => bv_mobile_api_clean_string($row['city']        ?? ''),
            'seller'        => [
                'id'   => $sellerId > 0 ? $sellerId : null,
                'name' => $sellerName,
            ],
            'rating'        => [
                'average' => $reviewAvg,
                'count'   => $reviewCnt,
            ],
            'ranking_score' => $rankScore,
            'created_at'    => bv_mobile_api_clean_string($row['created_at']  ?? ''),
        ];
    }

    // ── Build response ────────────────────────────────────────────────────────
    $hasMore = ($page * $perPage) < $totalCount;

    bv_mobile_api_json(200, [
        'ok'   => true,
        'data' => [
            'items'      => $items,
            'pagination' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $totalCount,
                'has_more' => $hasMore,
            ],
        ],
        'meta' => [
            'api_version'  => 'v1',
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);

} catch (\Throwable $e) {
    bv_mobile_api_log('Unhandled exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_mobile_api_json(500, [
        'ok'    => false,
        'error' => [
            'code'    => 'server_error',
            'message' => 'Something went wrong.',
        ],
    ]);
}
