<?php
declare(strict_types=1);

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

if (!function_exists('bv_sd_meta')) {
    function bv_sd_meta(): array
    {
        return [
            'api_version' => 'mobile-v1',
            'generated_at' => gmdate('c'),
        ];
    }
}

if (!function_exists('bv_sd_json')) {
    function bv_sd_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_sd_error')) {
    function bv_sd_error(string $code, string $message, int $statusCode): void
    {
        bv_sd_json($statusCode, [
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => bv_sd_meta(),
        ]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    bv_sd_error('method_not_allowed', 'Only GET requests are accepted.', 405);
}

if (!function_exists('bv_sd_public_root')) {
    function bv_sd_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_sd_project_root')) {
    function bv_sd_project_root(): string
    {
        return dirname(bv_sd_public_root());
    }
}

if (!function_exists('bv_sd_log')) {
    function bv_sd_log(string $message): void
    {
        error_log('[Bettavaro Mobile Seller Dashboard] ' . $message);
        $line = gmdate('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        foreach ([
            bv_sd_public_root() . '/logs/mobile_api.log',
            bv_sd_project_root() . '/logs/mobile_api.log',
            bv_sd_project_root() . '/private_html/mobile_api.log',
        ] as $path) {
            $dir = dirname($path);
            if (is_dir($dir) && is_writable($dir)) {
                @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
                break;
            }
        }
    }
}

if (!function_exists('bv_sd_db')) {
    function bv_sd_db(): ?object
    {
        static $loaded = false;
        static $connection = null;

        if ($loaded) {
            return $connection;
        }

        $loaded = true;
         $publicRoot = bv_sd_public_root();
        $candidates = [
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
            $publicRoot . '/includes/config.php',
            dirname($publicRoot) . '/config/db.php',
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

            if ($dbHost && $dbUser !== null && $dbName) {
                if (class_exists('PDO')) {
                    try {
                        $pdo = new PDO(
                            'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4',
                            (string) $dbUser,
                            (string) $dbPass,
                            [
                                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                                PDO::ATTR_EMULATE_PREPARES => false,
                            ]
                        );
                        $connection = $pdo;
                        return $connection;
                    } catch (Throwable $e) {
                        bv_sd_log('PDO connection failed: ' . $e->getMessage());
                    }
                }

                if (class_exists('mysqli')) {
                    try {
                        $mysqli = @new mysqli((string) $dbHost, (string) $dbUser, (string) $dbPass, (string) $dbName, $dbPort ?: 3306);
                        if (!$mysqli->connect_errno) {
                            $mysqli->set_charset('utf8mb4');
                            $connection = $mysqli;
                            return $connection;
                        }
                        bv_sd_log('mysqli connection failed: ' . $mysqli->connect_error);
                    } catch (Throwable $e) {
                        bv_sd_log('mysqli connection exception: ' . $e->getMessage());
                    }
                }
            }
        }

        return null;
    }
}

if (!function_exists('bv_sd_ident')) {
    function bv_sd_ident(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Invalid database identifier.');
        }
        return '`' . $identifier . '`';
    }
}

if (!function_exists('bv_sd_bind_types')) {
    function bv_sd_bind_types(array $params): string
    {
        $types = '';
        foreach ($params as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        return $types;
    }
}

if (!function_exists('bv_sd_fetch_all')) {
    function bv_sd_fetch_all(object $db, string $sql, array $params = []): array
    {
        if ($db instanceof PDO) {
            $statement = $db->prepare($sql);
            $statement->execute($params);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        if ($db instanceof mysqli) {
            $statement = $db->prepare($sql);
            if (!$statement) {
                throw new RuntimeException('Unable to prepare statement.');
            }

            if ($params) {
                $values = array_values($params);
                $types = bv_sd_bind_types($values);
                $statement->bind_param($types, ...$values);
            }

            if (!$statement->execute()) {
                $statement->close();
                throw new RuntimeException('Unable to execute statement.');
            }

            $result = $statement->get_result();
            $rows = $result ? ($result->fetch_all(MYSQLI_ASSOC) ?: []) : [];
            if ($result instanceof mysqli_result) {
                $result->free();
            }
            $statement->close();
            return $rows;
        }

        throw new RuntimeException('Unsupported database connection.');
    }
}

if (!function_exists('bv_sd_fetch_one')) {
    function bv_sd_fetch_one(object $db, string $sql, array $params = []): ?array
    {
        $rows = bv_sd_fetch_all($db, $sql, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('bv_sd_scalar')) {
    function bv_sd_scalar(object $db, string $sql, array $params = [], $default = 0)
    {
        $row = bv_sd_fetch_one($db, $sql, $params);
        if (!$row) {
            return $default;
        }
        $values = array_values($row);
        return $values[0] ?? $default;
    }
}

if (!function_exists('bv_sd_execute')) {
    function bv_sd_execute(object $db, string $sql, array $params = []): void
    {
        if ($db instanceof PDO) {
            $statement = $db->prepare($sql);
            $statement->execute($params);
            return;
        }

        if ($db instanceof mysqli) {
            $statement = $db->prepare($sql);
            if (!$statement) {
                throw new RuntimeException('Unable to prepare statement.');
            }
            if ($params) {
                $values = array_values($params);
                $types = bv_sd_bind_types($values);
                $statement->bind_param($types, ...$values);
            }
            if (!$statement->execute()) {
                $statement->close();
                throw new RuntimeException('Unable to execute statement.');
            }
            $statement->close();
            return;
        }

        throw new RuntimeException('Unsupported database connection.');
    }
}

if (!function_exists('bv_sd_table_exists')) {
    function bv_sd_table_exists(object $db, string $table): bool
    {
        static $cache = [];
        $key = strtolower($table);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            $cache[$key] = false;
            return false;
        }

        try {
            $rows = bv_sd_fetch_all($db, 'SHOW TABLES LIKE ?', [$table]);
            $cache[$key] = !empty($rows);
            return $cache[$key];
        } catch (Throwable $e) {
            bv_sd_log('Table detection failed for ' . $table . ': ' . $e->getMessage());
            $cache[$key] = false;
            return false;
        }
    }
}

if (!function_exists('bv_sd_columns')) {
    function bv_sd_columns(object $db, string $table): ?array
    {
        static $cache = [];
        $key = strtolower($table);
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $rows = bv_sd_fetch_all($db, 'SHOW COLUMNS FROM ' . bv_sd_ident($table));
        } catch (Throwable $e) {
            bv_sd_log('Column detection failed for ' . $table . ': ' . $e->getMessage());
            $cache[$key] = null;
            return null;
        }

        $columns = [];
        foreach ($rows as $row) {
            if (isset($row['Field'])) {
                $columns[strtolower((string) $row['Field'])] = (string) $row['Field'];
            }
        }

        $cache[$key] = $columns;
        return $columns;
    }
}

if (!function_exists('bv_sd_has_col')) {
    function bv_sd_has_col(?array $columns, string $column): bool
    {
        return $columns !== null && isset($columns[strtolower($column)]);
    }
}

if (!function_exists('bv_sd_col')) {
    function bv_sd_col(?array $columns, string $column): ?string
    {
        return $columns[strtolower($column)] ?? null;
    }
}

if (!function_exists('bv_sd_first_col')) {
    function bv_sd_first_col(?array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $column = bv_sd_col($columns, $candidate);
            if ($column !== null) {
                return $column;
            }
        }
        return null;
    }
}

if (!function_exists('bv_sd_header')) {
    function bv_sd_header(string $name): ?string
    {
        $serverName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (isset($_SERVER[$serverName]) && trim((string) $_SERVER[$serverName]) !== '') {
            return trim((string) $_SERVER[$serverName]);
        }

        if (strtolower($name) === 'authorization') {
            foreach (['REDIRECT_HTTP_AUTHORIZATION', 'Authorization'] as $key) {
                if (isset($_SERVER[$key]) && trim((string) $_SERVER[$key]) !== '') {
                    return trim((string) $_SERVER[$key]);
                }
            }

            if (function_exists('getallheaders')) {
                $headers = getallheaders();
                foreach ($headers as $headerName => $value) {
                    if (strtolower((string) $headerName) === 'authorization' && trim((string) $value) !== '') {
                        return trim((string) $value);
                    }
                }
            }
        }

        return null;
    }
}

if (!function_exists('bv_sd_active_value')) {
    function bv_sd_active_value($value): bool
    {
        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['active', '1', 'enabled', 'verified'], true);
    }
}

if (!function_exists('bv_sd_clean_string')) {
    function bv_sd_clean_string($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim(htmlspecialchars_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5));
        return $value === '' ? null : $value;
    }
}

if (!function_exists('bv_sd_int_value')) {
    function bv_sd_int_value($value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

if (!function_exists('bv_sd_float_value')) {
    function bv_sd_float_value($value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}

if (!function_exists('bv_sd_abs_url')) {
    function bv_sd_abs_url($path): ?string
    {
        $path = bv_sd_clean_string($path);
        if ($path === null) {
            return null;
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'www.bettavaro.com';
        return $scheme . '://' . $host . '/' . ltrim($path, '/');
    }
}

if (!function_exists('bv_sd_placeholders')) {
    function bv_sd_placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}

if (!function_exists('bv_sd_status_where')) {
    function bv_sd_status_where(string $alias, string $column, array $statuses, array &$params): string
    {
        foreach ($statuses as $status) {
            $params[] = $status;
        }
        return $alias . '.' . bv_sd_ident($column) . ' IN (' . bv_sd_placeholders($statuses) . ')';
    }
}

if (!function_exists('bv_sd_count')) {
    function bv_sd_count(object $db, string $sql, array $params = []): int
    {
        try {
            return bv_sd_int_value(bv_sd_scalar($db, $sql, $params, 0));
        } catch (Throwable $e) {
            bv_sd_log('Count query failed: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('bv_sd_order_paid_filter')) {
    function bv_sd_order_paid_filter(?array $orderColumns, array &$params): string
    {
        $clauses = [];
        if (bv_sd_has_col($orderColumns, 'status')) {
            $statuses = ['paid', 'confirmed', 'processing', 'packing'];
            foreach ($statuses as $status) {
                $params[] = $status;
            }
            $clauses[] = 'o.' . bv_sd_ident(bv_sd_col($orderColumns, 'status')) . ' IN (' . bv_sd_placeholders($statuses) . ')';
        }
        if (bv_sd_has_col($orderColumns, 'payment_status')) {
            $statuses = ['paid', 'confirmed', 'processing'];
            foreach ($statuses as $status) {
                $params[] = $status;
            }
            $clauses[] = 'o.' . bv_sd_ident(bv_sd_col($orderColumns, 'payment_status')) . ' IN (' . bv_sd_placeholders($statuses) . ')';
        }
        return $clauses ? ' AND (' . implode(' OR ', $clauses) . ')' : '';
    }
}

if (!function_exists('bv_sd_select_expr')) {
    function bv_sd_select_expr(?array $columns, array $candidates, string $alias, string $fallback = 'NULL'): string
    {
        $column = bv_sd_first_col($columns, $candidates);
        return ($column !== null ? $alias . '.' . bv_sd_ident($column) : $fallback);
    }
}

try {
    $authorization = bv_sd_header('Authorization');
    if ($authorization === null || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        bv_sd_error('token_missing', 'Bearer token is required.', 401);
    }

    $plainToken = trim($matches[1]);
    if ($plainToken === '') {
        bv_sd_error('token_missing', 'Bearer token is required.', 401);
    }

    $db = bv_sd_db();
    if (!$db) {
        bv_sd_error('db_unavailable', 'Database connection is unavailable.', 503);
    }

    $tokenColumns = bv_sd_columns($db, 'mobile_auth_tokens');
    if ($tokenColumns === null) {
        bv_sd_error('token_table_missing', 'Token table is unavailable.', 500);
    }

    $userColumns = bv_sd_columns($db, 'users');
    if ($userColumns === null || !bv_sd_has_col($userColumns, 'id') || !bv_sd_has_col($tokenColumns, 'user_id')) {
        bv_sd_error('server_error', 'Authentication tables are not configured correctly.', 500);
    }

    $tokenHash = hash('sha256', $plainToken);
    $tokenPredicate = null;
    $tokenParams = [];
    if (bv_sd_has_col($tokenColumns, 'token_hash')) {
        $tokenPredicate = 'mat.' . bv_sd_ident(bv_sd_col($tokenColumns, 'token_hash')) . ' = ?';
        $tokenParams[] = $tokenHash;
    } elseif (bv_sd_has_col($tokenColumns, 'token')) {
        $tokenPredicate = 'mat.' . bv_sd_ident(bv_sd_col($tokenColumns, 'token')) . ' = ?';
        $tokenParams[] = $tokenHash;
    } elseif (bv_sd_has_col($tokenColumns, 'plain_token')) {
        $tokenPredicate = 'mat.' . bv_sd_ident(bv_sd_col($tokenColumns, 'plain_token')) . ' = ?';
        $tokenParams[] = $plainToken;
    } else {
        bv_sd_error('server_error', 'Token table is not configured correctly.', 500);
    }

    $where = [$tokenPredicate];
    if (bv_sd_has_col($tokenColumns, 'revoked_at')) {
        $where[] = 'mat.' . bv_sd_ident(bv_sd_col($tokenColumns, 'revoked_at')) . ' IS NULL';
    }
    if (bv_sd_has_col($tokenColumns, 'expires_at')) {
        $where[] = 'mat.' . bv_sd_ident(bv_sd_col($tokenColumns, 'expires_at')) . ' > NOW()';
    }

    $userSelectWanted = ['id', 'first_name', 'last_name', 'name', 'email', 'phone', 'role', 'account_status', 'status'];
    $select = [];
    foreach ($userSelectWanted as $column) {
        if (bv_sd_has_col($userColumns, $column)) {
            $actual = bv_sd_col($userColumns, $column);
            $select[] = 'u.' . bv_sd_ident($actual) . ' AS ' . bv_sd_ident($actual);
        }
    }
    if (bv_sd_has_col($tokenColumns, 'id')) {
        $select[] = 'mat.' . bv_sd_ident(bv_sd_col($tokenColumns, 'id')) . ' AS __token_id';
    }
    if (bv_sd_has_col($tokenColumns, 'expires_at')) {
        $select[] = 'mat.' . bv_sd_ident(bv_sd_col($tokenColumns, 'expires_at')) . ' AS __token_expires_at';
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . bv_sd_ident('mobile_auth_tokens') . ' mat';
    $sql .= ' INNER JOIN ' . bv_sd_ident('users') . ' u ON u.' . bv_sd_ident(bv_sd_col($userColumns, 'id')) . ' = mat.' . bv_sd_ident(bv_sd_col($tokenColumns, 'user_id'));
    $sql .= ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1';

    $authRow = bv_sd_fetch_one($db, $sql, $tokenParams);
    if (!$authRow) {
        bv_sd_error('token_invalid', 'Bearer token is invalid.', 401);
    }

    if (bv_sd_has_col($tokenColumns, 'expires_at')) {
        $expiresAtRaw = $authRow['__token_expires_at'] ?? null;
        $expiresAt = $expiresAtRaw !== null ? strtotime((string) $expiresAtRaw) : false;
        if ($expiresAt === false || $expiresAt <= time()) {
            bv_sd_error('token_expired', 'Bearer token has expired.', 401);
        }
    }

    $accountStatusActive = false;
    if (bv_sd_has_col($userColumns, 'account_status')) {
        $accountStatusActive = bv_sd_active_value($authRow[bv_sd_col($userColumns, 'account_status')] ?? null);
        if (!$accountStatusActive) {
            bv_sd_error('account_inactive', 'User account is inactive.', 403);
        }
    }
    if (!$accountStatusActive && bv_sd_has_col($userColumns, 'status') && !bv_sd_active_value($authRow[bv_sd_col($userColumns, 'status')] ?? null)) {
        bv_sd_error('account_inactive', 'User account is inactive.', 403);
    }

    $role = strtolower(trim((string) ($authRow[bv_sd_col($userColumns, 'role') ?? 'role'] ?? 'user')));
    if (!in_array($role, ['seller', 'admin'], true)) {
        bv_sd_error('seller_required', 'Seller access is required.', 403);
    }

    if (bv_sd_has_col($tokenColumns, 'last_used_at')) {
        try {
            if (bv_sd_has_col($tokenColumns, 'id') && isset($authRow['__token_id'])) {
                bv_sd_execute(
                    $db,
                    'UPDATE ' . bv_sd_ident('mobile_auth_tokens') . ' SET ' . bv_sd_ident(bv_sd_col($tokenColumns, 'last_used_at')) . ' = NOW() WHERE ' . bv_sd_ident(bv_sd_col($tokenColumns, 'id')) . ' = ? LIMIT 1',
                    [bv_sd_int_value($authRow['__token_id'])]
                );
            } else {
                 bv_sd_execute(
                    $db,
                    'UPDATE ' . bv_sd_ident('mobile_auth_tokens') . ' SET ' . bv_sd_ident(bv_sd_col($tokenColumns, 'last_used_at')) . ' = NOW() WHERE ' . str_replace('mat.', '', $tokenPredicate) . ' LIMIT 1',
                    $tokenParams
                );
            }
        } catch (Throwable $e) {
            bv_sd_log('last_used_at update failed: ' . $e->getMessage());
        }
    }

    $userId = bv_sd_int_value($authRow[bv_sd_col($userColumns, 'id')] ?? 0);
    $nameParts = [];
    if (bv_sd_has_col($userColumns, 'first_name')) {
        $nameParts[] = bv_sd_clean_string($authRow[bv_sd_col($userColumns, 'first_name')] ?? null);
    }
    if (bv_sd_has_col($userColumns, 'last_name')) {
        $nameParts[] = bv_sd_clean_string($authRow[bv_sd_col($userColumns, 'last_name')] ?? null);
    }
    $nameParts = array_values(array_filter($nameParts, static fn($value): bool => $value !== null && $value !== ''));
    $name = $nameParts ? implode(' ', $nameParts) : bv_sd_clean_string($authRow[bv_sd_col($userColumns, 'name') ?? 'name'] ?? null);
    $seller = [
        'id' => $userId,
        'name' => $name,
        'email' => bv_sd_clean_string($authRow[bv_sd_col($userColumns, 'email') ?? 'email'] ?? null),
        'role' => $role,
    ];

    $sellerApplicationColumns = bv_sd_columns($db, 'seller_applications');
    if ($sellerApplicationColumns !== null && bv_sd_has_col($sellerApplicationColumns, 'user_id')) {
        try {
            $appSelect = [];
            $appMap = [
                'farm_name' => ['farm_name'],
                'province' => ['province', 'farm_province'],
                'country' => ['country', 'farm_country'],
                'phone' => ['phone', 'farm_phone'],
            ];
            foreach ($appMap as $field => $candidates) {
                $column = bv_sd_first_col($sellerApplicationColumns, $candidates);
                if ($column !== null) {
                    $appSelect[$field] = $column;
                }
            }
            if ($appSelect) {
                $appWhere = ['sa.' . bv_sd_ident(bv_sd_col($sellerApplicationColumns, 'user_id')) . ' = ?'];
                $appParams = [$userId];
                if (bv_sd_has_col($sellerApplicationColumns, 'application_status')) {
                    $appWhere[] = 'sa.' . bv_sd_ident(bv_sd_col($sellerApplicationColumns, 'application_status')) . ' = ?';
                    $appParams[] = 'approved';
                } elseif (bv_sd_has_col($sellerApplicationColumns, 'status')) {
                    $appWhere[] = 'sa.' . bv_sd_ident(bv_sd_col($sellerApplicationColumns, 'status')) . ' = ?';
                    $appParams[] = 'approved';
                }
                $orderColumn = bv_sd_first_col($sellerApplicationColumns, ['reviewed_at', 'approved_at', 'updated_at', 'created_at', 'id']);
                $appSqlParts = [];
                foreach ($appSelect as $field => $column) {
                    $appSqlParts[] = 'sa.' . bv_sd_ident($column) . ' AS ' . bv_sd_ident($field);
                }
                $appSql = 'SELECT ' . implode(', ', $appSqlParts) . ' FROM ' . bv_sd_ident('seller_applications') . ' sa WHERE ' . implode(' AND ', $appWhere);
                if ($orderColumn !== null) {
                    $appSql .= ' ORDER BY sa.' . bv_sd_ident($orderColumn) . ' DESC';
                }
                $appSql .= ' LIMIT 1';
                $application = bv_sd_fetch_one($db, $appSql, $appParams);
                if ($application) {
                    foreach ($appMap as $field => $_) {
                        if (array_key_exists($field, $application)) {
                            $seller[$field] = bv_sd_clean_string($application[$field]);
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            bv_sd_log('Seller application lookup failed: ' . $e->getMessage());
        }
    }

    $summary = [
        'active_listings' => 0,
        'draft_listings' => 0,
        'sold_listings' => 0,
        'total_orders' => 0,
        'pending_fulfillment' => 0,
        'shipped_orders' => 0,
        'completed_orders' => 0,
        'pending_refunds' => 0,
        'open_offers' => 0,
    ];
    $recentOrders = [];
    $recentListings = [];

    $listingColumns = bv_sd_columns($db, 'listings');
    $listingSellerCol = bv_sd_first_col($listingColumns, ['seller_id', 'seller_user_id', 'user_id', 'owner_user_id']);
    $listingIdCol = bv_sd_col($listingColumns, 'id');

    if ($listingColumns !== null && $listingSellerCol !== null && $listingIdCol !== null) {
        if (bv_sd_has_col($listingColumns, 'status')) {
            $params = [$userId];
            $summary['active_listings'] = bv_sd_count($db, 'SELECT COUNT(*) FROM ' . bv_sd_ident('listings') . ' l WHERE l.' . bv_sd_ident($listingSellerCol) . ' = ? AND ' . bv_sd_status_where('l', bv_sd_col($listingColumns, 'status'), ['active', 'available', 'published'], $params), $params);
            $params = [$userId];
            $summary['draft_listings'] = bv_sd_count($db, 'SELECT COUNT(*) FROM ' . bv_sd_ident('listings') . ' l WHERE l.' . bv_sd_ident($listingSellerCol) . ' = ? AND ' . bv_sd_status_where('l', bv_sd_col($listingColumns, 'status'), ['draft', 'pending', 'inactive'], $params), $params);
        }

        $soldParts = [];
        $soldParams = [$userId];
        if (bv_sd_has_col($listingColumns, 'status')) {
            $soldParts[] = bv_sd_status_where('l', bv_sd_col($listingColumns, 'status'), ['sold', 'completed'], $soldParams);
        }
        if (bv_sd_has_col($listingColumns, 'sale_status')) {
            $soldParts[] = bv_sd_status_where('l', bv_sd_col($listingColumns, 'sale_status'), ['sold', 'completed'], $soldParams);
        }
        if ($soldParts) {
            $summary['sold_listings'] = bv_sd_count($db, 'SELECT COUNT(*) FROM ' . bv_sd_ident('listings') . ' l WHERE l.' . bv_sd_ident($listingSellerCol) . ' = ? AND (' . implode(' OR ', $soldParts) . ')', $soldParams);
        }

        $listingTitleExpr = bv_sd_select_expr($listingColumns, ['title', 'name'], 'l');
        $listingStatusExpr = bv_sd_select_expr($listingColumns, ['status'], 'l');
        $listingSaleStatusExpr = bv_sd_select_expr($listingColumns, ['sale_status'], 'l');
        $listingPriceExpr = bv_sd_select_expr($listingColumns, ['price', 'unit_price', 'amount'], 'l', '0');
        $listingCurrencyExpr = bv_sd_select_expr($listingColumns, ['currency'], 'l', "'USD'");
        $listingImageExpr = bv_sd_select_expr($listingColumns, ['cover_image', 'image_url', 'image', 'photo_url', 'thumbnail_url'], 'l');
        $listingCreatedExpr = bv_sd_select_expr($listingColumns, ['created_at', 'published_at', 'updated_at'], 'l');
        $listingOrderCol = bv_sd_first_col($listingColumns, ['created_at', 'published_at', 'updated_at', 'id']);

        try {
            $rows = bv_sd_fetch_all(
                $db,
                'SELECT l.' . bv_sd_ident($listingIdCol) . ' AS id, ' . $listingTitleExpr . ' AS title, ' . $listingStatusExpr . ' AS status, ' . $listingSaleStatusExpr . ' AS sale_status, ' . $listingPriceExpr . ' AS price, ' . $listingCurrencyExpr . ' AS currency, ' . $listingImageExpr . ' AS image_url, ' . $listingCreatedExpr . ' AS created_at FROM ' . bv_sd_ident('listings') . ' l WHERE l.' . bv_sd_ident($listingSellerCol) . ' = ? ORDER BY l.' . bv_sd_ident($listingOrderCol ?? $listingIdCol) . ' DESC LIMIT 5',
                [$userId]
            );
            foreach ($rows as $row) {
                $recentListings[] = [
                    'id' => bv_sd_int_value($row['id'] ?? 0),
                    'title' => bv_sd_clean_string($row['title'] ?? null),
                    'status' => bv_sd_clean_string($row['status'] ?? null),
                    'sale_status' => bv_sd_clean_string($row['sale_status'] ?? null),
                    'price' => bv_sd_float_value($row['price'] ?? 0),
                    'currency' => bv_sd_clean_string($row['currency'] ?? null) ?: 'USD',
                    'image_url' => bv_sd_abs_url($row['image_url'] ?? null),
                    'created_at' => bv_sd_clean_string($row['created_at'] ?? null),
                ];
            }
        } catch (Throwable $e) {
            bv_sd_log('Recent listings query failed: ' . $e->getMessage());
            $recentListings = [];
        }
    }

    $orderColumns = bv_sd_columns($db, 'orders');
    $orderItemColumns = bv_sd_columns($db, 'order_items');
    $orderIdCol = bv_sd_col($orderColumns, 'id');
    $itemOrderCol = bv_sd_col($orderItemColumns, 'order_id');
    $itemListingCol = bv_sd_col($orderItemColumns, 'listing_id');
    $itemIdCol = bv_sd_col($orderItemColumns, 'id');
    $itemSellerCol = bv_sd_first_col($orderItemColumns, ['seller_id', 'seller_user_id']);
    $hasListingOrderChain = $orderColumns !== null && $orderItemColumns !== null && $listingColumns !== null && $listingSellerCol !== null && $listingIdCol !== null && $orderIdCol !== null && $itemOrderCol !== null && $itemListingCol !== null;
    $hasDirectItemOwnership = $orderColumns !== null && $orderItemColumns !== null && $orderIdCol !== null && $itemOrderCol !== null && $itemSellerCol !== null;
    $hasOrderOwnership = $hasListingOrderChain || $hasDirectItemOwnership;

    if ($hasOrderOwnership) {
        if ($hasListingOrderChain) {
            $baseJoin = ' FROM ' . bv_sd_ident('orders') . ' o INNER JOIN ' . bv_sd_ident('order_items') . ' oi ON oi.' . bv_sd_ident($itemOrderCol) . ' = o.' . bv_sd_ident($orderIdCol) . ' INNER JOIN ' . bv_sd_ident('listings') . ' l ON l.' . bv_sd_ident($listingIdCol) . ' = oi.' . bv_sd_ident($itemListingCol) . ' WHERE l.' . bv_sd_ident($listingSellerCol) . ' = ?';
        } else {
            $baseJoin = ' FROM ' . bv_sd_ident('orders') . ' o INNER JOIN ' . bv_sd_ident('order_items') . ' oi ON oi.' . bv_sd_ident($itemOrderCol) . ' = o.' . bv_sd_ident($orderIdCol) . ' WHERE oi.' . bv_sd_ident($itemSellerCol) . ' = ?';
        }

        $summary['total_orders'] = bv_sd_count($db, 'SELECT COUNT(DISTINCT o.' . bv_sd_ident($orderIdCol) . ')' . $baseJoin, [$userId]);

        $fulfillmentCol = bv_sd_first_col($orderItemColumns, ['fulfillment_status', 'seller_fulfillment_status', 'shipping_status']);
        if ($fulfillmentCol !== null) {
            $params = [$userId];
            $whereStatus = bv_sd_status_where('oi', $fulfillmentCol, ['pending', 'processing'], $params);
            $paidFilter = bv_sd_order_paid_filter($orderColumns, $params);
            $summary['pending_fulfillment'] = bv_sd_count($db, 'SELECT COUNT(*)' . $baseJoin . ' AND ' . $whereStatus . $paidFilter, $params);

            $params = [$userId];
            $summary['shipped_orders'] = bv_sd_count($db, 'SELECT COUNT(DISTINCT o.' . bv_sd_ident($orderIdCol) . ')' . $baseJoin . ' AND ' . bv_sd_status_where('oi', $fulfillmentCol, ['shipped'], $params), $params);

            $params = [$userId];
            $summary['completed_orders'] = bv_sd_count($db, 'SELECT COUNT(DISTINCT o.' . bv_sd_ident($orderIdCol) . ')' . $baseJoin . ' AND ' . bv_sd_status_where('oi', $fulfillmentCol, ['completed'], $params), $params);
        } elseif (bv_sd_has_col($orderColumns, 'status')) {
            $params = [$userId];
            $summary['pending_fulfillment'] = bv_sd_count($db, 'SELECT COUNT(*)' . $baseJoin . ' AND ' . bv_sd_status_where('o', bv_sd_col($orderColumns, 'status'), ['confirmed', 'paid', 'processing', 'packing'], $params), $params);

            $params = [$userId];
            $summary['shipped_orders'] = bv_sd_count($db, 'SELECT COUNT(DISTINCT o.' . bv_sd_ident($orderIdCol) . ')' . $baseJoin . ' AND ' . bv_sd_status_where('o', bv_sd_col($orderColumns, 'status'), ['shipped'], $params), $params);

            $params = [$userId];
            $summary['completed_orders'] = bv_sd_count($db, 'SELECT COUNT(DISTINCT o.' . bv_sd_ident($orderIdCol) . ')' . $baseJoin . ' AND ' . bv_sd_status_where('o', bv_sd_col($orderColumns, 'status'), ['completed'], $params), $params);
        }

        $lineTotalExpr = '0';
        $lineTotalCol = bv_sd_first_col($orderItemColumns, ['line_total', 'total', 'subtotal', 'item_total']);
        if ($lineTotalCol !== null) {
            $lineTotalExpr = 'COALESCE(oi.' . bv_sd_ident($lineTotalCol) . ', 0)';
        } else {
            $unitCol = bv_sd_first_col($orderItemColumns, ['unit_price', 'price', 'sale_price']);
            $qtyCol = bv_sd_first_col($orderItemColumns, ['quantity', 'qty']);
            if ($unitCol !== null && $qtyCol !== null) {
                $lineTotalExpr = 'COALESCE(oi.' . bv_sd_ident($unitCol) . ', 0) * COALESCE(oi.' . bv_sd_ident($qtyCol) . ', 1)';
            } elseif ($unitCol !== null) {
                $lineTotalExpr = 'COALESCE(oi.' . bv_sd_ident($unitCol) . ', 0)';
            }
        }

        $qtyExpr = bv_sd_has_col($orderItemColumns, 'quantity') ? 'COALESCE(oi.' . bv_sd_ident(bv_sd_col($orderItemColumns, 'quantity')) . ', 1)' : (bv_sd_has_col($orderItemColumns, 'qty') ? 'COALESCE(oi.' . bv_sd_ident(bv_sd_col($orderItemColumns, 'qty')) . ', 1)' : '1');
        $orderCodeExpr = bv_sd_select_expr($orderColumns, ['order_code', 'code', 'order_number'], 'o');
        $orderStatusExpr = bv_sd_select_expr($orderColumns, ['status'], 'o');
        $paymentStatusExpr = bv_sd_select_expr($orderColumns, ['payment_status'], 'o');
        $orderCurrencyExpr = bv_sd_select_expr($orderColumns, ['currency'], 'o', "'USD'");
 
        $orderCreatedExpr = bv_sd_select_expr($orderColumns, ['created_at', 'ordered_at', 'updated_at'], 'o');
        $orderPaidExpr = bv_sd_select_expr($orderColumns, ['paid_at', 'payment_paid_at'], 'o');
        $orderSortCol = bv_sd_first_col($orderColumns, ['created_at', 'ordered_at', 'updated_at', 'id']);
        if ($hasListingOrderChain) {
            $firstTitleExpr = bv_sd_select_expr($listingColumns, ['title', 'name'], 'l');
            $firstImageExpr = bv_sd_select_expr($listingColumns, ['cover_image', 'image_url', 'image', 'photo_url', 'thumbnail_url'], 'l');
        } else {
            $firstTitleExpr = bv_sd_select_expr($orderItemColumns, ['title', 'item_title', 'listing_title', 'name'], 'oi');
            $firstImageExpr = bv_sd_select_expr($orderItemColumns, ['image_url', 'cover_image', 'image', 'photo_url', 'thumbnail_url'], 'oi');
        }
        $fulfillmentSummaryExpr = $fulfillmentCol !== null ? 'GROUP_CONCAT(DISTINCT oi.' . bv_sd_ident($fulfillmentCol) . ' ORDER BY oi.' . bv_sd_ident($fulfillmentCol) . ' SEPARATOR \', \')' : 'MAX(' . $orderStatusExpr . ')'; 

        try {
            $rows = bv_sd_fetch_all(
                $db,
               'SELECT o.' . bv_sd_ident($orderIdCol) . ' AS id, MAX(' . $orderCodeExpr . ') AS order_code, MAX(' . $orderStatusExpr . ') AS status, MAX(' . $paymentStatusExpr . ') AS payment_status, MAX(' . $orderCurrencyExpr . ') AS currency, COALESCE(SUM(' . $lineTotalExpr . '), 0) AS total, COALESCE(SUM(' . $lineTotalExpr . '), 0) AS seller_subtotal, COALESCE(SUM(' . $qtyExpr . '), COUNT(*)) AS item_count, MIN(' . $firstTitleExpr . ') AS first_title, MIN(' . $firstImageExpr . ') AS first_image_url, MAX(' . $orderCreatedExpr . ') AS created_at, MAX(' . $orderPaidExpr . ') AS paid_at, ' . $fulfillmentSummaryExpr . ' AS fulfillment_status_summary' . $baseJoin . ' GROUP BY o.' . bv_sd_ident($orderIdCol) . ' ORDER BY MAX(o.' . bv_sd_ident($orderSortCol ?? $orderIdCol) . ') DESC LIMIT 5',
                [$userId]
            );
            foreach ($rows as $row) {
                $recentOrders[] = [
                    'id' => bv_sd_int_value($row['id'] ?? 0),
                    'order_code' => bv_sd_clean_string($row['order_code'] ?? null),
                    'status' => bv_sd_clean_string($row['status'] ?? null),
                    'payment_status' => bv_sd_clean_string($row['payment_status'] ?? null),
                    'currency' => bv_sd_clean_string($row['currency'] ?? null) ?: 'USD',
                    'total' => bv_sd_float_value($row['total'] ?? 0),
                    'seller_subtotal' => bv_sd_float_value($row['seller_subtotal'] ?? 0),
                    'item_count' => bv_sd_int_value($row['item_count'] ?? 0),
                    'first_title' => bv_sd_clean_string($row['first_title'] ?? null),
                    'first_image_url' => bv_sd_abs_url($row['first_image_url'] ?? null),
                    'created_at' => bv_sd_clean_string($row['created_at'] ?? null),
                    'paid_at' => bv_sd_clean_string($row['paid_at'] ?? null),
                    'fulfillment_status_summary' => bv_sd_clean_string($row['fulfillment_status_summary'] ?? null),
                ];
            }
        } catch (Throwable $e) {
            bv_sd_log('Recent orders query failed: ' . $e->getMessage());
            $recentOrders = [];
        }

        $refundColumns = bv_sd_columns($db, 'order_refunds');
        $refundItemColumns = bv_sd_columns($db, 'order_refund_items');
          if ($hasListingOrderChain && $refundColumns !== null && $refundItemColumns !== null && bv_sd_has_col($refundColumns, 'id') && bv_sd_has_col($refundItemColumns, 'refund_id') && bv_sd_has_col($refundItemColumns, 'order_item_id') && $itemIdCol !== null) {
            $params = [$userId];
            $refundStatusSql = '';
            if (bv_sd_has_col($refundColumns, 'status')) {
                $refundStatusSql = ' AND ' . bv_sd_status_where('r', bv_sd_col($refundColumns, 'status'), ['pending_approval', 'approved', 'processing'], $params);
            }
            $summary['pending_refunds'] = bv_sd_count(
                $db,
                'SELECT COUNT(DISTINCT r.' . bv_sd_ident(bv_sd_col($refundColumns, 'id')) . ') FROM ' . bv_sd_ident('order_refunds') . ' r INNER JOIN ' . bv_sd_ident('order_refund_items') . ' ri ON ri.' . bv_sd_ident(bv_sd_col($refundItemColumns, 'refund_id')) . ' = r.' . bv_sd_ident(bv_sd_col($refundColumns, 'id')) . ' INNER JOIN ' . bv_sd_ident('order_items') . ' oi ON oi.' . bv_sd_ident($itemIdCol) . ' = ri.' . bv_sd_ident(bv_sd_col($refundItemColumns, 'order_item_id')) . ' INNER JOIN ' . bv_sd_ident('listings') . ' l ON l.' . bv_sd_ident($listingIdCol) . ' = oi.' . bv_sd_ident($itemListingCol) . ' WHERE l.' . bv_sd_ident($listingSellerCol) . ' = ?' . $refundStatusSql,
                $params
            );
        }
    }

    $offerColumns = bv_sd_columns($db, 'listing_offers');
    if ($offerColumns !== null) {
        $offerSellerCol = bv_sd_first_col($offerColumns, ['seller_user_id', 'seller_id']);
        $offerListingCol = bv_sd_col($offerColumns, 'listing_id');
        $params = [$userId];
        $statusSql = bv_sd_has_col($offerColumns, 'status') ? ' AND ' . bv_sd_status_where('lo', bv_sd_col($offerColumns, 'status'), ['open', 'seller_countered', 'buyer_countered', 'seller_accepted', 'buyer_checkout_ready'], $params) : '';
        if ($offerSellerCol !== null) {
            $summary['open_offers'] = bv_sd_count($db, 'SELECT COUNT(*) FROM ' . bv_sd_ident('listing_offers') . ' lo WHERE lo.' . bv_sd_ident($offerSellerCol) . ' = ?' . $statusSql, $params);
        } elseif ($offerListingCol !== null && $listingColumns !== null && $listingIdCol !== null && $listingSellerCol !== null) {
            $summary['open_offers'] = bv_sd_count($db, 'SELECT COUNT(*) FROM ' . bv_sd_ident('listing_offers') . ' lo INNER JOIN ' . bv_sd_ident('listings') . ' l ON l.' . bv_sd_ident($listingIdCol) . ' = lo.' . bv_sd_ident($offerListingCol) . ' WHERE l.' . bv_sd_ident($listingSellerCol) . ' = ?' . $statusSql, $params);
        }
    }

    $alerts = [];
    if ($summary['pending_fulfillment'] > 0) {
        $alerts[] = [
            'code' => 'pending_fulfillment',
            'message' => 'You have orders pending fulfillment.',
            'count' => $summary['pending_fulfillment'],
        ];
    }
    if ($summary['pending_refunds'] > 0) {
        $alerts[] = [
            'code' => 'pending_refunds',
            'message' => 'You have refunds pending review or processing.',
            'count' => $summary['pending_refunds'],
        ];
    }
    if ($summary['open_offers'] > 0) {
        $alerts[] = [
            'code' => 'open_offers',
            'message' => 'You have open listing offers.',
            'count' => $summary['open_offers'],
        ];
    }

    bv_sd_json(200, [
        'ok' => true,
        'data' => [
            'seller' => $seller,
            'summary' => $summary,
            'recent_orders' => $recentOrders,
            'recent_listings' => $recentListings,
            'alerts' => $alerts,
        ],
        'meta' => bv_sd_meta(),
    ]);
} catch (Throwable $e) {
    bv_sd_log('Server error: ' . $e->getMessage());
    bv_sd_error('server_error', 'An unexpected server error occurred.', 500);
}
