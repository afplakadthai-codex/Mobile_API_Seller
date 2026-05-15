<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/seller_orders.php
// Authenticated seller endpoint: orders containing items owned by the token holder.
//
// MERGED FILE: OLD base (production-safe, schema-adaptive) +
//              NEW improvements (payment_status filter, sort, buyer enrichment,
//              shipping fields, refund/cancellation summaries per order).
//
// SAFETY GUARANTEE: Every optional column (line_total, qty, unit_price,
//   fulfillment_status, carrier, tracking_number, ship_*, payment_provider,
//   total, user_id, etc.) is guarded by bv_so_has_col() before use.
//   If a column does not exist the query falls back safely.
//   Optional tables (order_refunds, order_cancellations) are detected at
//   runtime; if absent their output keys contain safe empty defaults.
//
// CRITICAL: Never touches $_SESSION. Never outputs HTML. Never redirects.
// =============================================================================

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------------
// Core output helpers
// ---------------------------------------------------------------------------

if (!function_exists('bv_so_meta')) {
    function bv_so_meta(): array
    {
        return ['api_version' => 'mobile-v1', 'generated_at' => gmdate('c')];
    }
}

if (!function_exists('bv_so_json')) {
    function bv_so_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_so_error')) {
    function bv_so_error(string $code, string $message, int $statusCode): void
    {
        bv_so_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
            'meta'  => bv_so_meta(),
        ]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    bv_so_error('method_not_allowed', 'Only GET requests are accepted.', 405);
}

// ---------------------------------------------------------------------------
// Path helpers
// ---------------------------------------------------------------------------

if (!function_exists('bv_so_public_root')) {
    function bv_so_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_so_project_root')) {
    function bv_so_project_root(): string
    {
        return dirname(bv_so_public_root());
    }
}

// ---------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------

if (!function_exists('bv_so_log')) {
    function bv_so_log(string $message): void
    {
        error_log('[Bettavaro Mobile Seller Orders] ' . $message);
        $line = gmdate('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        foreach ([
            bv_so_public_root()  . '/logs/mobile_api.log',
            bv_so_project_root() . '/logs/mobile_api.log',
            bv_so_project_root() . '/private_html/mobile_api.log',
        ] as $path) {
            $dir = dirname($path);
            if (is_dir($dir) && is_writable($dir)) {
                @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
                break;
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Database connection (schema-adaptive, tries multiple config paths)
// ---------------------------------------------------------------------------

if (!function_exists('bv_so_db')) {
    function bv_so_db(): ?object
    {
        static $loaded = false;
        static $connection = null;

        if ($loaded) {
            return $connection;
        }

        $loaded     = true;
        $publicRoot = bv_so_public_root();
        $candidates = [
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
            $publicRoot . '/includes/config.php',
            bv_so_project_root() . '/config/db.php',
            bv_so_project_root() . '/db.php',
        ];

        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }

            $loader = static function (string $file): array {
                $db_host = $db_user = $db_pass = $db_name = $db_port = null;
                $host = $user = $pass = $name = $port = null;
                $DB_HOST = $DB_USER = $DB_PASS = $DB_NAME = $DB_PORT = null;
                $dsn = null;
                $pdo = $conn = $db = $mysqli = $link = null;
                /** @noinspection PhpIncludeInspection */
                include $file;

                return compact(
                    'db_host', 'db_user', 'db_pass', 'db_name', 'db_port',
                    'host', 'user', 'pass', 'name', 'port',
                    'DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME', 'DB_PORT',
                    'dsn', 'pdo', 'conn', 'db', 'mysqli', 'link'
                );
            };

            $vars = $loader($path);

            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $name) {
                if (isset($vars[$name]) && ($vars[$name] instanceof PDO || $vars[$name] instanceof mysqli)) {
                    $connection = $vars[$name];
                    return $connection;
                }
            }

            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $name) {
                if (isset($GLOBALS[$name]) && ($GLOBALS[$name] instanceof PDO || $GLOBALS[$name] instanceof mysqli)) {
                    $connection = $GLOBALS[$name];
                    return $connection;
                }
            }

            $dbHost = $vars['db_host'] ?? $vars['host'] ?? $vars['DB_HOST'] ?? null;
            $dbUser = $vars['db_user'] ?? $vars['user'] ?? $vars['DB_USER'] ?? null;
            $dbPass = $vars['db_pass'] ?? $vars['pass'] ?? $vars['DB_PASS'] ?? '';
            $dbName = $vars['db_name'] ?? $vars['name'] ?? $vars['DB_NAME'] ?? null;
            $dbPort = (int) ($vars['db_port'] ?? $vars['port'] ?? $vars['DB_PORT'] ?? 3306);

            if ($dbHost && $dbUser !== null && $dbName && class_exists('PDO')) {
                try {
                    $pdo = new PDO(
                        'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4',
                        (string) $dbUser,
                        (string) $dbPass,
                        [
                            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                            PDO::ATTR_EMULATE_PREPARES   => false,
                        ]
                    );
                    $connection = $pdo;
                    return $connection;
                } catch (Throwable $e) {
                    bv_so_log('PDO connection failed: ' . $e->getMessage());
                }
            }

            if ($dbHost && $dbUser !== null && $dbName && class_exists('mysqli')) {
                try {
                    $mysqli = @new mysqli((string) $dbHost, (string) $dbUser, (string) $dbPass, (string) $dbName, $dbPort ?: 3306);
                    if (!$mysqli->connect_errno) {
                        $mysqli->set_charset('utf8mb4');
                        $connection = $mysqli;
                        return $connection;
                    }
                    bv_so_log('mysqli connection failed: ' . $mysqli->connect_error);
                } catch (Throwable $e) {
                    bv_so_log('mysqli connection exception: ' . $e->getMessage());
                }
            }
        }

        return null;
    }
}

// ---------------------------------------------------------------------------
// SQL identifier quoting (strict — throws on unsafe input)
// ---------------------------------------------------------------------------

if (!function_exists('bv_so_ident')) {
    function bv_so_ident(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Unsafe SQL identifier: ' . $identifier);
        }
        return '`' . $identifier . '`';
    }
}

// ---------------------------------------------------------------------------
// Bind-type helper for mysqli
// ---------------------------------------------------------------------------

if (!function_exists('bv_so_bind_type')) {
    function bv_so_bind_type($value): string
    {
        if (is_int($value)) {
            return 'i';
        }
        if (is_float($value)) {
            return 'd';
        }
        return 's';
    }
}

// ---------------------------------------------------------------------------
// Query helpers: fetch_all, fetch_one, scalar, execute
// ---------------------------------------------------------------------------

if (!function_exists('bv_so_fetch_all')) {
    function bv_so_fetch_all(object $db, string $sql, array $params = []): array
    {
        if ($db instanceof PDO) {
            $st = $db->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        if ($db instanceof mysqli) {
            $st = $db->prepare($sql);
            if ($st === false) {
                throw new RuntimeException($db->error);
            }
            if ($params) {
                $types = implode('', array_map('bv_so_bind_type', $params));
                $refs  = [&$types];
                foreach ($params as $key => $_) {
                    $refs[] = &$params[$key];
                }
                call_user_func_array([$st, 'bind_param'], $refs);
            }
            $st->execute();
            $res  = $st->get_result();
            $rows = [];
            if ($res instanceof mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    $rows[] = $row;
                }
                $res->free();
            }
            $st->close();
            return $rows;
        }
        return [];
    }
}

if (!function_exists('bv_so_fetch_one')) {
    function bv_so_fetch_one(object $db, string $sql, array $params = []): ?array
    {
        $rows = bv_so_fetch_all($db, $sql, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('bv_so_scalar')) {
    function bv_so_scalar(object $db, string $sql, array $params = [], $default = 0)
    {
        $row = bv_so_fetch_one($db, $sql, $params);
        if ($row === null) {
            return $default;
        }
        $values = array_values($row);
        return $values[0] ?? $default;
    }
}

if (!function_exists('bv_so_execute')) {
    function bv_so_execute(object $db, string $sql, array $params = []): bool
    {
        if ($db instanceof PDO) {
            return $db->prepare($sql)->execute($params);
        }
        if ($db instanceof mysqli) {
            $st = $db->prepare($sql);
            if ($st === false) {
                return false;
            }
            if ($params) {
                $types = implode('', array_map('bv_so_bind_type', $params));
                $refs  = [&$types];
                foreach ($params as $key => $_) {
                    $refs[] = &$params[$key];
                }
                call_user_func_array([$st, 'bind_param'], $refs);
            }
            $ok = $st->execute();
            $st->close();
            return $ok;
        }
        return false;
    }
}

// ---------------------------------------------------------------------------
// Schema detection — returns [lowercase_name => actual_name] or null
// ---------------------------------------------------------------------------

if (!function_exists('bv_so_columns')) {
    function bv_so_columns(object $db, string $table): ?array
    {
        static $cache = [];
        $table = strtolower($table);
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return null;
        }

        try {
            $rows = bv_so_fetch_all($db, 'SHOW COLUMNS FROM ' . bv_so_ident($table));
            if (!$rows) {
                $cache[$table] = null;
                return null;
            }
            $columns = [];
            foreach ($rows as $row) {
                $field = (string) ($row['Field'] ?? '');
                if ($field !== '') {
                    $columns[strtolower($field)] = $field;
                }
            }
            $cache[$table] = $columns;
            return $columns;
        } catch (Throwable $e) {
            bv_so_log('Column detection failed for ' . $table . ': ' . $e->getMessage());
            $cache[$table] = null;
            return null;
        }
    }
}

if (!function_exists('bv_so_col')) {
    /**
     * Look up the actual (real-case) column name from a schema map.
     * Returns null if the column does not exist.
     */
    function bv_so_col(?array $columns, string $column): ?string
    {
        return $columns[strtolower($column)] ?? null;
    }
}

if (!function_exists('bv_so_has_col')) {
    function bv_so_has_col(?array $columns, string $column): bool
    {
        return bv_so_col($columns, $column) !== null;
    }
}

// ---------------------------------------------------------------------------
// Authorization header reader
// ---------------------------------------------------------------------------

if (!function_exists('bv_so_header')) {
    function bv_so_header(string $name): ?string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverKey]) && trim((string) $_SERVER[$serverKey]) !== '') {
            return trim((string) $_SERVER[$serverKey]);
        }
        foreach (['REDIRECT_HTTP_AUTHORIZATION', 'Authorization'] as $key) {
            if (isset($_SERVER[$key]) && trim((string) $_SERVER[$key]) !== '') {
                return trim((string) $_SERVER[$key]);
            }
        }
        if (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $headerName => $value) {
                if (strcasecmp((string) $headerName, $name) === 0) {
                    return trim((string) $value);
                }
            }
        }
        return null;
    }
}

// ---------------------------------------------------------------------------
// Value normalizers
// ---------------------------------------------------------------------------

if (!function_exists('bv_so_active_value')) {
    function bv_so_active_value($value): bool
    {
        if ($value === null) {
            return true;
        }
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'active', 'enabled', 'approved', 'seller', 'admin'], true);
    }
}

if (!function_exists('bv_so_clean')) {
    function bv_so_clean($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        $value = htmlspecialchars_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5);
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }
}

if (!function_exists('bv_so_int')) {
    function bv_so_int($value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}

if (!function_exists('bv_so_float')) {
    function bv_so_float($value): float
    {
        return is_numeric($value) ? round((float) $value, 2) : 0.0;
    }
}

// ---------------------------------------------------------------------------
// Status helpers
// ---------------------------------------------------------------------------

if (!function_exists('bv_so_allowed_statuses')) {
    /**
     * Returns the set of raw DB status values that map to a logical status key.
     */
    function bv_so_allowed_statuses(string $status): array
    {
        return match ($status) {
            'pending'    => ['pending', 'pending_payment', 'reserved'],
            'paid'       => ['paid'],
            'confirmed'  => ['confirmed'],
            'processing' => ['processing', 'packing'],
            'shipped'    => ['shipped'],
            'completed'  => ['completed'],
            'cancelled'  => ['cancelled'],
            'refunded'   => ['refunded'],
            default      => [],
        };
    }
}

if (!function_exists('bv_so_status_clause')) {
    /**
     * Builds a WHERE clause fragment for a logical status filter.
     * Supports both status and payment_status columns.
     * Passes bound params by reference.
     */
    function bv_so_status_clause(array $orderColumns, string $status, array &$params): ?string
    {
        if ($status === 'all') {
            return null;
        }
        $values = bv_so_allowed_statuses($status);
        if (!$values) {
            return null;
        }
        $parts = [];
        if (bv_so_has_col($orderColumns, 'status')) {
            $parts[] = 'o.' . bv_so_ident((string) bv_so_col($orderColumns, 'status'))
                . ' IN (' . implode(', ', array_fill(0, count($values), '?')) . ')';
            array_push($params, ...$values);
        }
        if ($status === 'paid' && bv_so_has_col($orderColumns, 'payment_status')) {
            $parts[] = 'o.' . bv_so_ident((string) bv_so_col($orderColumns, 'payment_status')) . ' = ?';
            $params[] = 'paid';
        } elseif (in_array($status, ['pending', 'refunded'], true) && bv_so_has_col($orderColumns, 'payment_status')) {
            $payValues = $status === 'pending' ? ['pending', 'pending_payment', 'unpaid'] : ['refunded'];
            $parts[]   = 'o.' . bv_so_ident((string) bv_so_col($orderColumns, 'payment_status'))
                . ' IN (' . implode(', ', array_fill(0, count($payValues), '?')) . ')';
            array_push($params, ...$payValues);
        }
        return $parts ? '(' . implode(' OR ', $parts) . ')' : null;
    }
}

// ---------------------------------------------------------------------------
// Fulfillment deriver (from item fulfillment_status CSV or order status fallback)
// Returns: ['key' => string, 'label' => string, 'tracking_count' => int]
// ---------------------------------------------------------------------------

if (!function_exists('bv_so_fulfillment')) {
    function bv_so_fulfillment(?string $csv, ?string $orderStatus, int $trackingCount): array
    {
        $statuses = [];
        if ($csv !== null && trim($csv) !== '') {
            foreach (explode(',', $csv) as $value) {
                $value = strtolower(trim($value));
                if ($value !== '') {
                    $statuses[] = $value;
                }
            }
        }

        if ($statuses) {
            $unique = array_values(array_unique($statuses));
            if (count($unique) === 1) {
                $only = $unique[0];
                if (in_array($only, ['pending', 'to_ship', 'awaiting_shipment'], true)) {
                    return ['key' => 'to_ship', 'label' => 'To ship', 'tracking_count' => $trackingCount];
                }
                if (in_array($only, ['processing', 'packing'], true)) {
                    return ['key' => 'processing', 'label' => 'Processing', 'tracking_count' => $trackingCount];
                }
                if ($only === 'shipped') {
                    return ['key' => 'shipped', 'label' => 'Shipped', 'tracking_count' => $trackingCount];
                }
                if ($only === 'completed') {
                    return ['key' => 'completed', 'label' => 'Completed', 'tracking_count' => $trackingCount];
                }
            }
            // Mixed or ambiguous
            if (in_array('processing', $statuses, true) || in_array('packing', $statuses, true)) {
                return ['key' => 'processing', 'label' => 'Processing', 'tracking_count' => $trackingCount];
            }
            return ['key' => 'mixed', 'label' => 'Mixed fulfillment', 'tracking_count' => $trackingCount];
        }

        // No item-level fulfillment data — fall back to order status
        $fallback = strtolower(trim((string) $orderStatus));
        if (in_array($fallback, ['pending', 'pending_payment', 'reserved', 'paid', 'confirmed'], true)) {
            return ['key' => 'to_ship', 'label' => 'To ship', 'tracking_count' => $trackingCount];
        }
        if (in_array($fallback, ['processing', 'packing'], true)) {
            return ['key' => 'processing', 'label' => 'Processing', 'tracking_count' => $trackingCount];
        }
        if ($fallback === 'shipped') {
            return ['key' => 'shipped', 'label' => 'Shipped', 'tracking_count' => $trackingCount];
        }
        if ($fallback === 'completed') {
            return ['key' => 'completed', 'label' => 'Completed', 'tracking_count' => $trackingCount];
        }
        return ['key' => 'unknown', 'label' => 'Unknown', 'tracking_count' => $trackingCount];
    }
}

// =============================================================================
// Main execution
// =============================================================================

try {

    // ── Bearer token extraction ───────────────────────────────────────────────
    $authorization = bv_so_header('Authorization');
    if ($authorization === null || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        bv_so_error('token_missing', 'Bearer token is required.', 401);
    }

    $plainToken = trim((string) $matches[1]);
    if ($plainToken === '') {
        bv_so_error('token_missing', 'Bearer token is required.', 401);
    }

    // ── Database ──────────────────────────────────────────────────────────────
    $db = bv_so_db();
    if (!$db) {
        bv_so_error('db_unavailable', 'Database connection is unavailable.', 503);
    }

    // ── Schema-adaptive token validation ──────────────────────────────────────
    $tokenColumns = bv_so_columns($db, 'mobile_auth_tokens');
    $userColumns  = bv_so_columns($db, 'users');
    if ($tokenColumns === null || $userColumns === null
        || !bv_so_has_col($tokenColumns, 'user_id')
        || !bv_so_has_col($userColumns, 'id')) {
        bv_so_error('server_error', 'Authentication tables are not configured correctly.', 500);
    }

    $tokenHash      = hash('sha256', $plainToken);
    $tokenPredicate = null;
    $tokenParams    = [];

    if (bv_so_has_col($tokenColumns, 'token_hash')) {
        $tokenPredicate = 'mat.' . bv_so_ident((string) bv_so_col($tokenColumns, 'token_hash')) . ' = ?';
        $tokenParams[]  = $tokenHash;
    } elseif (bv_so_has_col($tokenColumns, 'token')) {
        $tokenPredicate = 'mat.' . bv_so_ident((string) bv_so_col($tokenColumns, 'token')) . ' = ?';
        $tokenParams[]  = $tokenHash;
    } elseif (bv_so_has_col($tokenColumns, 'plain_token')) {
        $tokenPredicate = 'mat.' . bv_so_ident((string) bv_so_col($tokenColumns, 'plain_token')) . ' = ?';
        $tokenParams[]  = $plainToken;
    } else {
        bv_so_error('server_error', 'Token table is not configured correctly.', 500);
    }

    $authWhere = [$tokenPredicate];
    if (bv_so_has_col($tokenColumns, 'revoked_at')) {
        $authWhere[] = 'mat.' . bv_so_ident((string) bv_so_col($tokenColumns, 'revoked_at')) . ' IS NULL';
    }

    $userSelect = [];
    foreach (['id', 'role', 'account_status', 'status', 'email', 'first_name', 'last_name', 'name'] as $column) {
        if (bv_so_has_col($userColumns, $column)) {
            $actual         = (string) bv_so_col($userColumns, $column);
            $userSelect[]   = 'u.' . bv_so_ident($actual) . ' AS ' . bv_so_ident($actual);
        }
    }
    if (bv_so_has_col($tokenColumns, 'id')) {
        $userSelect[] = 'mat.' . bv_so_ident((string) bv_so_col($tokenColumns, 'id')) . ' AS __token_id';
    }
    if (bv_so_has_col($tokenColumns, 'expires_at')) {
        $userSelect[] = 'mat.' . bv_so_ident((string) bv_so_col($tokenColumns, 'expires_at')) . ' AS __token_expires_at';
    }

    $authSql  = 'SELECT ' . implode(', ', $userSelect)
        . ' FROM ' . bv_so_ident('mobile_auth_tokens') . ' mat'
        . ' INNER JOIN ' . bv_so_ident('users') . ' u'
        . ' ON u.' . bv_so_ident((string) bv_so_col($userColumns, 'id'))
        . ' = mat.' . bv_so_ident((string) bv_so_col($tokenColumns, 'user_id'))
        . ' WHERE ' . implode(' AND ', $authWhere) . ' LIMIT 1';

    $authRow = bv_so_fetch_one($db, $authSql, $tokenParams);
    if (!$authRow) {
        bv_so_error('token_invalid', 'Bearer token is invalid.', 401);
    }

    // Expiry check
    if (bv_so_has_col($tokenColumns, 'expires_at')) {
        $expiresRaw = $authRow['__token_expires_at'] ?? null;
        $expiresAt  = $expiresRaw !== null ? strtotime((string) $expiresRaw) : false;
        if ($expiresAt === false || $expiresAt <= time()) {
            bv_so_error('token_expired', 'Bearer token has expired.', 401);
        }
    }

    // Account status check
    if (bv_so_has_col($userColumns, 'account_status')
        && !bv_so_active_value($authRow[bv_so_col($userColumns, 'account_status')] ?? null)) {
        bv_so_error('account_inactive', 'User account is inactive.', 403);
    }
    if (bv_so_has_col($userColumns, 'status')
        && !bv_so_active_value($authRow[bv_so_col($userColumns, 'status')] ?? null)) {
        bv_so_error('account_inactive', 'User account is inactive.', 403);
    }

    // Role check
    $roleColumn = bv_so_col($userColumns, 'role');
    $role       = strtolower(trim((string) ($roleColumn ? ($authRow[$roleColumn] ?? 'user') : 'user')));
    if (!in_array($role, ['seller', 'admin'], true)) {
        bv_so_error('seller_required', 'Seller access is required.', 403);
    }

    // Update last_used_at best-effort
    if (bv_so_has_col($tokenColumns, 'last_used_at')) {
        try {
            if (bv_so_has_col($tokenColumns, 'id') && isset($authRow['__token_id'])) {
                bv_so_execute(
                    $db,
                    'UPDATE ' . bv_so_ident('mobile_auth_tokens')
                    . ' SET ' . bv_so_ident((string) bv_so_col($tokenColumns, 'last_used_at')) . ' = NOW()'
                    . ' WHERE ' . bv_so_ident((string) bv_so_col($tokenColumns, 'id')) . ' = ? LIMIT 1',
                    [bv_so_int($authRow['__token_id'])]
                );
            }
        } catch (Throwable $e) {
            bv_so_log('last_used_at update failed: ' . $e->getMessage());
        }
    }

    $userId = bv_so_int($authRow[(string) bv_so_col($userColumns, 'id')] ?? 0);

    // ── Required table schema detection ───────────────────────────────────────
    $ordersColumns = bv_so_columns($db, 'orders');
    if ($ordersColumns === null) {
        bv_so_error('orders_table_missing', 'Orders table is unavailable.', 500);
    }

    $itemColumns = bv_so_columns($db, 'order_items');
    if ($itemColumns === null) {
        bv_so_error('order_items_table_missing', 'Order items table is unavailable.', 500);
    }

    $listingColumns = bv_so_columns($db, 'listings');
    if ($listingColumns === null) {
        bv_so_error('listings_table_missing', 'Listings table is unavailable.', 500);
    }

    if (!bv_so_has_col($ordersColumns, 'id')
        || !bv_so_has_col($itemColumns, 'order_id')
        || !bv_so_has_col($itemColumns, 'listing_id')
        || !bv_so_has_col($listingColumns, 'id')) {
        bv_so_error('server_error', 'Order tables are not configured correctly.', 500);
    }

    // Seller ownership column on listings table
    $listingSellerColumn = bv_so_col($listingColumns, 'seller_id')
        ?? bv_so_col($listingColumns, 'user_id')
        ?? bv_so_col($listingColumns, 'owner_id');
    if ($listingSellerColumn === null) {
        bv_so_error('server_error', 'Listings table is missing seller ownership column.', 500);
    }

    // ── Optional tables (NEW: refunds, cancellations) ─────────────────────────
    // Detected at runtime; never crash if absent.
    $refundsColumns       = bv_so_columns($db, 'order_refunds');
    $cancellationsColumns = bv_so_columns($db, 'order_cancellations');

    // ── Request parameters ────────────────────────────────────────────────────
    // Status filter (maps logical names to DB values via bv_so_status_clause)
    $allowedStatuses = ['all', 'pending', 'paid', 'confirmed', 'processing', 'shipped', 'completed', 'cancelled', 'refunded'];
    $status = strtolower(trim((string) ($_GET['status'] ?? 'all')));
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'all';
    }

    // Payment status filter (NEW — only applied if column exists on orders)
    $allowedPaymentStatuses = ['all', 'pending', 'paid', 'failed', 'refunded', 'cancelled'];
    $paymentStatus = strtolower(trim((string) ($_GET['payment_status'] ?? 'all')));
    if (!in_array($paymentStatus, $allowedPaymentStatuses, true)) {
        $paymentStatus = 'all';
    }

    // Sort order (NEW — newest/oldest/total_high/total_low)
    $allowedSorts = ['newest', 'oldest', 'total_high', 'total_low'];
    $sort = strtolower(trim((string) ($_GET['sort'] ?? 'newest')));
    if (!in_array($sort, $allowedSorts, true)) {
        $sort = 'newest';
    }

    // Pagination
    $page    = max(1, bv_so_int($_GET['page'] ?? 1, 1));
    $perPage = min(100, max(1, bv_so_int($_GET['per_page'] ?? 20, 20)));
    $offset  = ($page - 1) * $perPage;

    // Search (sanitized, length-capped)
    $search = bv_so_clean($_GET['search'] ?? '');
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($search) > 120) {
            $search = mb_substr($search, 0, 120);
        }
    } elseif (strlen($search) > 120) {
        $search = substr($search, 0, 120);
    }

    // Seller context (admin can override via ?seller_id=)
    $sellerContextId = $role === 'admin' ? bv_so_int($_GET['seller_id'] ?? 0, 0) : $userId;
    if ($sellerContextId <= 0) {
        $sellerContextId = null;
    }

    // ── Build JOIN + WHERE ────────────────────────────────────────────────────
    // Uses INNER JOIN for seller isolation so COUNT(DISTINCT) is required.
    $joinSql = ' FROM ' . bv_so_ident('orders') . ' o';
    $where   = [];
    $params  = [];

    if ($sellerContextId !== null) {
        $joinSql .= ' INNER JOIN ' . bv_so_ident('order_items') . ' oi_filter'
            . ' ON oi_filter.' . bv_so_ident((string) bv_so_col($itemColumns, 'order_id'))
            . ' = o.' . bv_so_ident((string) bv_so_col($ordersColumns, 'id'));
        $joinSql .= ' INNER JOIN ' . bv_so_ident('listings') . ' l_filter'
            . ' ON l_filter.' . bv_so_ident((string) bv_so_col($listingColumns, 'id'))
            . ' = oi_filter.' . bv_so_ident((string) bv_so_col($itemColumns, 'listing_id'));
        $where[]  = 'l_filter.' . bv_so_ident($listingSellerColumn) . ' = ?';
        $params[] = $sellerContextId;
    }

    // Logical status filter (uses bv_so_status_clause for proper mapping)
    $statusParams  = [];
    $statusClause  = bv_so_status_clause($ordersColumns, $status, $statusParams);
    if ($statusClause !== null) {
        $where[] = $statusClause;
        array_push($params, ...$statusParams);
    }

    // Payment status filter (NEW — safe: only if column exists)
    if ($paymentStatus !== 'all' && bv_so_has_col($ordersColumns, 'payment_status')) {
        $where[]  = 'o.' . bv_so_ident((string) bv_so_col($ordersColumns, 'payment_status')) . ' = ?';
        $params[] = $paymentStatus;
    }

    // Search across order fields and optionally buyer user table
    if ($search !== '') {
        $searchParts = [];
        $needle      = '%' . $search . '%';
        foreach (['order_code', 'buyer_name', 'buyer_email'] as $column) {
            if (bv_so_has_col($ordersColumns, $column)) {
                $searchParts[] = 'o.' . bv_so_ident((string) bv_so_col($ordersColumns, $column)) . ' LIKE ?';
                $params[]      = $needle;
            }
        }
        if (bv_so_has_col($ordersColumns, 'user_id')) {
            $buyerJoinNeeded = false;
            foreach (['name', 'email', 'first_name', 'last_name'] as $buyerColumn) {
                if (bv_so_has_col($userColumns, $buyerColumn)) {
                    $buyerJoinNeeded = true;
                    $searchParts[]   = 'buyer.' . bv_so_ident((string) bv_so_col($userColumns, $buyerColumn)) . ' LIKE ?';
                    $params[]        = $needle;
                }
            }
            if ($buyerJoinNeeded) {
                $joinSql .= ' LEFT JOIN ' . bv_so_ident('users') . ' buyer'
                    . ' ON buyer.' . bv_so_ident((string) bv_so_col($userColumns, 'id'))
                    . ' = o.' . bv_so_ident((string) bv_so_col($ordersColumns, 'user_id'));
            }
        }
        // Item title search via EXISTS subquery (from NEW — avoids extra JOIN duplication)
        foreach (['title_snapshot', 'item_title'] as $titleCol) {
            if (bv_so_has_col($itemColumns, $titleCol)) {
                $searchParts[] = 'EXISTS (SELECT 1 FROM ' . bv_so_ident('order_items') . ' oix'
                    . ' WHERE oix.' . bv_so_ident((string) bv_so_col($itemColumns, 'order_id'))
                    . ' = o.' . bv_so_ident((string) bv_so_col($ordersColumns, 'id'))
                    . ' AND oix.' . bv_so_ident((string) bv_so_col($itemColumns, $titleCol)) . ' LIKE ?)';
                $params[] = $needle;
                break; // use first found title column only
            }
        }
        if ($searchParts) {
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }
    }

    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    // ── ORDER BY (NEW sort support — safe column existence checks) ────────────
    $idCol      = 'o.' . bv_so_ident((string) bv_so_col($ordersColumns, 'id'));
    $createdCol = bv_so_has_col($ordersColumns, 'created_at')
        ? 'o.' . bv_so_ident((string) bv_so_col($ordersColumns, 'created_at'))
        : null;
    $totalCol = bv_so_has_col($ordersColumns, 'total')
        ? 'o.' . bv_so_ident((string) bv_so_col($ordersColumns, 'total'))
        : null;

    $orderBy = match ($sort) {
        'oldest'     => $createdCol
            ? ' ORDER BY ' . $createdCol . ' ASC, '  . $idCol . ' ASC'
            : ' ORDER BY ' . $idCol . ' ASC',
        'total_high' => $totalCol
            ? ' ORDER BY ' . $totalCol . ' DESC, ' . $idCol . ' DESC'
            : ($createdCol ? ' ORDER BY ' . $createdCol . ' DESC, ' . $idCol . ' DESC' : ' ORDER BY ' . $idCol . ' DESC'),
        'total_low'  => $totalCol
            ? ' ORDER BY ' . $totalCol . ' ASC, '  . $idCol . ' DESC'
            : ($createdCol ? ' ORDER BY ' . $createdCol . ' DESC, ' . $idCol . ' DESC' : ' ORDER BY ' . $idCol . ' DESC'),
        default      => $createdCol
            ? ' ORDER BY ' . $createdCol . ' DESC, ' . $idCol . ' DESC'
            : ' ORDER BY ' . $idCol . ' DESC',
    };

    // ── Count (DISTINCT to handle seller JOIN fan-out) ────────────────────────
    $countParams = $params;
    $total = (int) bv_so_scalar(
        $db,
        'SELECT COUNT(DISTINCT o.' . bv_so_ident((string) bv_so_col($ordersColumns, 'id')) . ')' . $joinSql . $whereSql,
        $countParams,
        0
    );

    // ── Status summary (unfiltered by page, gives seller totals) ─────────────
    $summary = array_fill_keys($allowedStatuses, 0);
    unset($summary['all']);
    $summary = ['total' => $total] + $summary;

    if ($total > 0 && bv_so_has_col($ordersColumns, 'status')) {
        $summaryRows = bv_so_fetch_all(
            $db,
            'SELECT o.' . bv_so_ident((string) bv_so_col($ordersColumns, 'status'))
            . ' AS order_status, COUNT(DISTINCT o.' . bv_so_ident((string) bv_so_col($ordersColumns, 'id'))
            . ') AS count_value' . $joinSql . $whereSql
            . ' GROUP BY o.' . bv_so_ident((string) bv_so_col($ordersColumns, 'status')),
            $params
        );
        foreach ($summaryRows as $row) {
            $rawStatus = strtolower((string) ($row['order_status'] ?? ''));
            $count     = (int) ($row['count_value'] ?? 0);
            foreach (['pending', 'paid', 'confirmed', 'processing', 'shipped', 'completed', 'cancelled', 'refunded'] as $key) {
                if (in_array($rawStatus, bv_so_allowed_statuses($key), true)) {
                    $summary[$key] += $count;
                    break;
                }
            }
        }
    }

    // ── Build SELECT for order rows ───────────────────────────────────────────
    // Expanded (NEW) to include payment_provider, total, user_id, shipping fields.
    // Every column guarded by bv_so_has_col().
    $selectColumns = ['o.' . bv_so_ident((string) bv_so_col($ordersColumns, 'id')) . ' AS id'];

    foreach ([
        'order_code', 'status', 'payment_status', 'payment_provider',
        'currency', 'total',
        'buyer_name', 'buyer_email', 'user_id',
        'ship_name', 'ship_country', 'ship_province', 'ship_city',
        'ship_postal_code', 'ship_district', 'ship_subdistrict',
        'created_at', 'paid_at', 'updated_at',
    ] as $column) {
        if (bv_so_has_col($ordersColumns, $column)) {
            $selectColumns[] = 'o.' . bv_so_ident((string) bv_so_col($ordersColumns, $column))
                . ' AS ' . bv_so_ident($column);
        }
    }

    // ── Fetch paginated order rows ────────────────────────────────────────────
    $orderRows = bv_so_fetch_all(
        $db,
        'SELECT DISTINCT ' . implode(', ', $selectColumns) . $joinSql . $whereSql . $orderBy . ' LIMIT ? OFFSET ?',
        array_merge($params, [$perPage, $offset])
    );

    $orderIds = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $orderRows);

    // ── Batch: seller-item metrics per order ──────────────────────────────────
    // SAFETY: every optional column (line_total, qty/quantity, unit_price/price,
    // fulfillment_status, tracking_number, title columns, image columns) is
    // checked with bv_so_has_col() before inclusion in the expression.
    $metricsByOrder = [];

    if ($orderIds) {
        // Quantity expression — prefer quantity, fall back to qty, default 1
        $quantityColumn    = bv_so_col($itemColumns, 'quantity') ?? bv_so_col($itemColumns, 'qty');
        $qtyExpression     = $quantityColumn
            ? 'COALESCE(oi.' . bv_so_ident($quantityColumn) . ', 1)'
            : '1';

        // Line value expression — tries columns in priority order, falls back to 0
        $lineParts = [];
        foreach (['line_total', 'total', 'subtotal'] as $column) {
            if (bv_so_has_col($itemColumns, $column)) {
                $lineParts[] = 'oi.' . bv_so_ident((string) bv_so_col($itemColumns, $column));
            }
        }
        foreach (['unit_price', 'price'] as $priceColumn) {
            if (bv_so_has_col($itemColumns, $priceColumn)) {
                $lineParts[] = '(oi.' . bv_so_ident((string) bv_so_col($itemColumns, $priceColumn))
                    . ' * ' . $qtyExpression . ')';
            }
        }
        $lineExpression = $lineParts ? 'COALESCE(' . implode(', ', $lineParts) . ', 0)' : '0';

        // Title expression — tries item columns then listing columns
        $titleExpressionParts = [];
        foreach (['item_title', 'title_snapshot', 'title', 'name'] as $column) {
            if (bv_so_has_col($itemColumns, $column)) {
                $titleExpressionParts[] = 'oi.' . bv_so_ident((string) bv_so_col($itemColumns, $column));
            }
        }
        foreach (['title', 'name'] as $column) {
            if (bv_so_has_col($listingColumns, $column)) {
                $titleExpressionParts[] = 'l.' . bv_so_ident((string) bv_so_col($listingColumns, $column));
            }
        }
        $titleExpression = $titleExpressionParts
            ? 'COALESCE(' . implode(', ', $titleExpressionParts) . ')'
            : "''";

        // Cover image expression
        $imageExpressionParts = [];
        foreach (['cover_image_snapshot', 'image_url', 'first_image_url', 'cover_image'] as $column) {
            if (bv_so_has_col($itemColumns, $column)) {
                $imageExpressionParts[] = 'oi.' . bv_so_ident((string) bv_so_col($itemColumns, $column));
            }
        }
        foreach (['cover_image', 'image_url', 'first_image_url', 'photo_url'] as $column) {
            if (bv_so_has_col($listingColumns, $column)) {
                $imageExpressionParts[] = 'l.' . bv_so_ident((string) bv_so_col($listingColumns, $column));
            }
        }
        $imageExpression = $imageExpressionParts
            ? 'COALESCE(' . implode(', ', $imageExpressionParts) . ')'
            : "''";

        // Fulfillment status expression — only if column exists (SAFETY critical)
        $fulfillmentExpression = bv_so_has_col($itemColumns, 'fulfillment_status')
            ? 'GROUP_CONCAT(COALESCE(oi.' . bv_so_ident((string) bv_so_col($itemColumns, 'fulfillment_status'))
                . ", '') SEPARATOR ',')"
            : "''";

        // Tracking count — only if column exists (SAFETY critical)
        $trackingExpression = bv_so_has_col($itemColumns, 'tracking_number')
            ? 'SUM(CASE WHEN oi.' . bv_so_ident((string) bv_so_col($itemColumns, 'tracking_number'))
                . " IS NOT NULL AND TRIM(oi." . bv_so_ident((string) bv_so_col($itemColumns, 'tracking_number'))
                . ") <> '' THEN 1 ELSE 0 END)"
            : '0';

        // Sort expression for GROUP_CONCAT ordering
        $sortIdCol = (string) (bv_so_col($itemColumns, 'id') ?? bv_so_col($itemColumns, 'order_id'));

        $metricParams = [];
        $metricWhere  = [
            'oi.' . bv_so_ident((string) bv_so_col($itemColumns, 'order_id'))
            . ' IN (' . implode(', ', array_fill(0, count($orderIds), '?')) . ')',
        ];
        array_push($metricParams, ...$orderIds);

        // Seller isolation in the metrics sub-query (mirrors the main JOIN above)
        if ($sellerContextId !== null) {
            $metricWhere[] = 'l.' . bv_so_ident($listingSellerColumn) . ' = ?';
            $metricParams[] = $sellerContextId;
        }

        $metricSql = 'SELECT'
            . ' oi.' . bv_so_ident((string) bv_so_col($itemColumns, 'order_id')) . ' AS order_id,'
            . ' COUNT(*) AS seller_item_count,'
            . ' SUM(' . $qtyExpression . ') AS seller_qty_total,'
            . ' SUM(' . $lineExpression . ') AS seller_subtotal,'
            . ' SUBSTRING_INDEX(GROUP_CONCAT(' . $titleExpression
                . ' ORDER BY oi.' . bv_so_ident($sortIdCol) . " ASC SEPARATOR '|||BVSEP|||'), '|||BVSEP|||', 1) AS first_title,"
            . ' SUBSTRING_INDEX(GROUP_CONCAT(' . $imageExpression
                . ' ORDER BY oi.' . bv_so_ident($sortIdCol) . " ASC SEPARATOR '|||BVSEP|||'), '|||BVSEP|||', 1) AS first_image_url,"
            . ' ' . $fulfillmentExpression . ' AS fulfillment_csv,'
            . ' ' . $trackingExpression . ' AS tracking_count'
            . ' FROM ' . bv_so_ident('order_items') . ' oi'
            . ' INNER JOIN ' . bv_so_ident('listings') . ' l'
            . ' ON l.' . bv_so_ident((string) bv_so_col($listingColumns, 'id'))
            . ' = oi.' . bv_so_ident((string) bv_so_col($itemColumns, 'listing_id'))
            . ' WHERE ' . implode(' AND ', $metricWhere)
            . ' GROUP BY oi.' . bv_so_ident((string) bv_so_col($itemColumns, 'order_id'));

        foreach (bv_so_fetch_all($db, $metricSql, $metricParams) as $row) {
            $metricsByOrder[(int) ($row['order_id'] ?? 0)] = $row;
        }
    }

    // ── Batch: latest refund per order (NEW — safe: skipped if table absent) ──
    $refundsByOrder = [];
    if ($orderIds && $refundsColumns !== null
        && bv_so_has_col($refundsColumns, 'order_id')
        && bv_so_has_col($refundsColumns, 'id')) {

        $refSelectParts = [
            bv_so_ident((string) bv_so_col($refundsColumns, 'id'))       . ' AS id',
            bv_so_ident((string) bv_so_col($refundsColumns, 'order_id')) . ' AS order_id',
        ];
        if (bv_so_has_col($refundsColumns, 'status')) {
            $refSelectParts[] = bv_so_ident((string) bv_so_col($refundsColumns, 'status')) . ' AS status';
        }
        if (bv_so_has_col($refundsColumns, 'refund_code')) {
            $refSelectParts[] = bv_so_ident((string) bv_so_col($refundsColumns, 'refund_code')) . ' AS refund_code';
        }

        $ph      = implode(', ', array_fill(0, count($orderIds), '?'));
        $refRows = bv_so_fetch_all(
            $db,
            'SELECT ' . implode(', ', $refSelectParts)
            . ' FROM ' . bv_so_ident('order_refunds')
            . ' WHERE ' . bv_so_ident((string) bv_so_col($refundsColumns, 'order_id')) . ' IN (' . $ph . ')'
            . ' ORDER BY ' . bv_so_ident((string) bv_so_col($refundsColumns, 'id')) . ' DESC',
            $orderIds
        );
        foreach ($refRows as $rr) {
            $oid = (int) ($rr['order_id'] ?? 0);
            if ($oid > 0 && !isset($refundsByOrder[$oid])) {
                $refundsByOrder[$oid] = $rr; // first row = latest (DESC)
            }
        }
    }

    // ── Batch: latest cancellation per order (NEW — safe) ────────────────────
    $cancelsByOrder = [];
    if ($orderIds && $cancellationsColumns !== null
        && bv_so_has_col($cancellationsColumns, 'order_id')
        && bv_so_has_col($cancellationsColumns, 'id')) {

        $canSelectParts = [
            bv_so_ident((string) bv_so_col($cancellationsColumns, 'id'))       . ' AS id',
            bv_so_ident((string) bv_so_col($cancellationsColumns, 'order_id')) . ' AS order_id',
        ];
        if (bv_so_has_col($cancellationsColumns, 'status')) {
            $canSelectParts[] = bv_so_ident((string) bv_so_col($cancellationsColumns, 'status')) . ' AS status';
        }

        $ph      = implode(', ', array_fill(0, count($orderIds), '?'));
        $canRows = bv_so_fetch_all(
            $db,
            'SELECT ' . implode(', ', $canSelectParts)
            . ' FROM ' . bv_so_ident('order_cancellations')
            . ' WHERE ' . bv_so_ident((string) bv_so_col($cancellationsColumns, 'order_id')) . ' IN (' . $ph . ')'
            . ' ORDER BY ' . bv_so_ident((string) bv_so_col($cancellationsColumns, 'id')) . ' DESC',
            $orderIds
        );
        foreach ($canRows as $cr) {
            $oid = (int) ($cr['order_id'] ?? 0);
            if ($oid > 0 && !isset($cancelsByOrder[$oid])) {
                $cancelsByOrder[$oid] = $cr; // first row = latest (DESC)
            }
        }
    }

    // ── Batch: buyer names from users table (NEW — safe, schema-adaptive) ─────
    // Only runs if orders table has user_id and users table is accessible.
    $buyersByUserId = [];
    if ($orderIds && bv_so_has_col($ordersColumns, 'user_id') && $userColumns !== null) {
        $buyerUserIds = array_unique(array_filter(
            array_map(static fn(array $row): int => bv_so_int($row['user_id'] ?? 0, 0), $orderRows),
            static fn(int $id): bool => $id > 0
        ));

        if ($buyerUserIds && bv_so_has_col($userColumns, 'id')) {
            $buyerSelectParts = ['u.' . bv_so_ident((string) bv_so_col($userColumns, 'id')) . ' AS id'];
            foreach (['first_name', 'last_name', 'name', 'email'] as $col) {
                if (bv_so_has_col($userColumns, $col)) {
                    $buyerSelectParts[] = 'u.' . bv_so_ident((string) bv_so_col($userColumns, $col)) . ' AS ' . bv_so_ident($col);
                }
            }
            $bph      = implode(', ', array_fill(0, count($buyerUserIds), '?'));
            $buyerRows = bv_so_fetch_all(
                $db,
                'SELECT ' . implode(', ', $buyerSelectParts)
                . ' FROM ' . bv_so_ident('users') . ' u'
                . ' WHERE u.' . bv_so_ident((string) bv_so_col($userColumns, 'id')) . ' IN (' . $bph . ')',
                array_values($buyerUserIds)
            );
            foreach ($buyerRows as $br) {
                $bid = bv_so_int($br['id'] ?? 0, 0);
                if ($bid > 0) {
                    $buyersByUserId[$bid] = $br;
                }
            }
        }
    }

    // ── Format order list ─────────────────────────────────────────────────────
    $orders = [];
    foreach ($orderRows as $row) {
        $orderId     = (int) ($row['id'] ?? 0);
        $metrics     = $metricsByOrder[$orderId] ?? [];
        $orderStatus = bv_so_clean($row['status'] ?? '');

        // Buyer: orders columns first, then user table enrichment (NEW)
        $buyerName    = bv_so_clean($row['buyer_name']  ?? '');
        $buyerEmail   = bv_so_clean($row['buyer_email'] ?? '');
        $buyerUserId  = bv_so_int($row['user_id'] ?? 0, 0);
        if ($buyerUserId > 0 && isset($buyersByUserId[$buyerUserId])) {
            $bu = $buyersByUserId[$buyerUserId];
            if ($buyerName === '') {
                $fn = bv_so_clean($bu['first_name'] ?? $bu['name'] ?? '');
                $ln = bv_so_clean($bu['last_name']  ?? '');
                $buyerName = trim($fn . ($ln !== '' ? ' ' . $ln : ''));
            }
            if ($buyerEmail === '') {
                $buyerEmail = bv_so_clean($bu['email'] ?? '');
            }
        }

        // Shipping address with province fallback (NEW — ship_district/ship_subdistrict)
        $shipProvince = bv_so_clean(
            (isset($row['ship_province'])    && $row['ship_province']    !== '') ? $row['ship_province']    :
            ((isset($row['ship_district'])   && $row['ship_district']    !== '') ? $row['ship_district']    :
            ($row['ship_subdistrict'] ?? ''))
        );
        $shipCity = bv_so_clean(
            (isset($row['ship_city'])      && $row['ship_city']      !== '') ? $row['ship_city']      :
            ($row['ship_district'] ?? '')
        );

        // Latest refund (NEW — empty defaults when table absent or no refund)
        $refRow = $refundsByOrder[$orderId] ?? null;
        $latestRefund = [
            'id'          => $refRow ? bv_so_int($refRow['id'] ?? 0) : null,
            'status'      => $refRow ? bv_so_clean($refRow['status']      ?? '') : '',
            'refund_code' => $refRow ? bv_so_clean($refRow['refund_code'] ?? '') : '',
        ];

        // Latest cancellation (NEW — empty defaults when table absent or no cancellation)
        $canRow = $cancelsByOrder[$orderId] ?? null;
        $latestCancellation = [
            'id'     => $canRow ? bv_so_int($canRow['id'] ?? 0) : null,
            'status' => $canRow ? bv_so_clean($canRow['status'] ?? '') : '',
        ];

        $orders[] = [
            // Core identity (OLD)
            'id'                  => $orderId,
            'order_code'          => bv_so_clean($row['order_code']     ?? ''),
            'status'              => $orderStatus,
            'payment_status'      => bv_so_clean($row['payment_status'] ?? ''),
            'payment_provider'    => bv_so_clean($row['payment_provider'] ?? ''), // NEW
            'currency'            => bv_so_clean($row['currency']        ?? 'USD') ?: 'USD',
            'order_total'         => bv_so_float($row['total']           ?? 0),   // NEW
            // Seller slice (OLD)
            'seller_subtotal'     => bv_so_float($metrics['seller_subtotal']   ?? 0),
            'seller_item_count'   => (int) ($metrics['seller_item_count']      ?? 0),
            'seller_qty_total'    => (int) ($metrics['seller_qty_total']       ?? 0),
            'first_title'         => bv_so_clean($metrics['first_title']       ?? ''),
            'first_image_url'     => bv_so_clean($metrics['first_image_url']   ?? ''),
            // Buyer object (NEW)
            'buyer'               => [
                'id'    => $buyerUserId > 0 ? $buyerUserId : null,
                'name'  => $buyerName,
                'email' => $buyerEmail,
            ],
            // Shipping object (NEW — each key is '' when column absent)
            'shipping'            => [
                'name'        => bv_so_clean($row['ship_name']        ?? ''),
                'country'     => bv_so_clean($row['ship_country']     ?? ''),
                'province'    => $shipProvince,
                'city'        => $shipCity,
                'postal_code' => bv_so_clean($row['ship_postal_code'] ?? ''),
            ],
            // Fulfillment (OLD function, schema-safe)
            'fulfillment'         => bv_so_fulfillment(
                isset($metrics['fulfillment_csv']) ? (string) $metrics['fulfillment_csv'] : null,
                $orderStatus,
                (int) ($metrics['tracking_count'] ?? 0)
            ),
            // Refund / cancellation (NEW — null id when no record)
            'latest_refund'       => $latestRefund,
            'latest_cancellation' => $latestCancellation,
            // Timestamps (OLD)
            'created_at'          => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'paid_at'             => isset($row['paid_at'])    ? (string) $row['paid_at']    : null,
            'updated_at'          => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    // ── Response ──────────────────────────────────────────────────────────────
    bv_so_json(200, [
        'ok'   => true,
        'data' => [
            'seller'     => ['id' => $userId, 'role' => $role],
            'filters'    => [
                'status'         => $status,
                'payment_status' => $paymentStatus, // NEW
                'sort'           => $sort,           // NEW
                'search'         => $search,
            ],
            'summary'    => $summary,
            'orders'     => $orders,
            'pagination' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $total,
                'has_more' => ($offset + $perPage) < $total,
            ],
        ],
        'meta' => bv_so_meta(),
    ]);

} catch (Throwable $e) {
    bv_so_log('Unhandled error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    bv_so_error('server_error', 'An unexpected server error occurred.', 500);
}
