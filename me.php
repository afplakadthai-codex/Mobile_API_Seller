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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (!function_exists('bv_mobile_me_meta')) {
    function bv_mobile_me_meta(): array
    {
        return [
            'api_version' => 'mobile-v1',
            'generated_at' => gmdate('c'),
        ];
    }
}

if (!function_exists('bv_mobile_me_json')) {
    function bv_mobile_me_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_mobile_me_error')) {
    function bv_mobile_me_error(string $code, string $message, int $statusCode): void
    {
        bv_mobile_me_json($statusCode, [
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => bv_mobile_me_meta(),
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    bv_mobile_me_error('method_not_allowed', 'Only GET requests are accepted.', 405);
}

if (!function_exists('bv_mobile_me_public_root')) {
    function bv_mobile_me_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_mobile_me_db')) {
    function bv_mobile_me_db(): ?object
    {
        static $loaded = false;
        static $connection = null;

        if ($loaded) {
            return $connection;
        }

        $loaded = true;
        $publicRoot = bv_mobile_me_public_root();
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
                    error_log('[Bettavaro Mobile Me] PDO connection failed: ' . $e->getMessage());
                }

                if (class_exists('mysqli')) {
                    try {
                        $mysqli = @new mysqli((string) $dbHost, (string) $dbUser, (string) $dbPass, (string) $dbName, $dbPort ?: 3306);
                        if (!$mysqli->connect_errno) {
                            $mysqli->set_charset('utf8mb4');
                            $connection = $mysqli;
                            return $connection;
                        }
                        error_log('[Bettavaro Mobile Me] mysqli connection failed: ' . $mysqli->connect_error);
                    } catch (Throwable $e) {
                        error_log('[Bettavaro Mobile Me] mysqli connection exception: ' . $e->getMessage());
                    }
                }
            }
        }

        return null;
    }
}

if (!function_exists('bv_mobile_me_identifier')) {
    function bv_mobile_me_identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new InvalidArgumentException('Invalid database identifier.');
        }

        return '`' . $identifier . '`';
    }
}

if (!function_exists('bv_mobile_me_fetch_all')) {
    function bv_mobile_me_fetch_all(object $db, string $sql, array $params = []): array
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
                $types = str_repeat('s', count($params));
                $values = array_values($params);
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

if (!function_exists('bv_mobile_me_fetch_one')) {
    function bv_mobile_me_fetch_one(object $db, string $sql, array $params = []): ?array
    {
        $rows = bv_mobile_me_fetch_all($db, $sql, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('bv_mobile_me_execute')) {
    function bv_mobile_me_execute(object $db, string $sql, array $params = []): void
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
                $types = str_repeat('s', count($params));
                $values = array_values($params);
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

if (!function_exists('bv_mobile_me_columns')) {
    function bv_mobile_me_columns(object $db, string $table): ?array
    {
        try {
            $quotedTable = bv_mobile_me_identifier($table);
            $rows = bv_mobile_me_fetch_all($db, 'SHOW COLUMNS FROM ' . $quotedTable);
        } catch (Throwable $e) {
            return null;
        }

        $columns = [];
        foreach ($rows as $row) {
            if (isset($row['Field'])) {
                $columns[strtolower((string) $row['Field'])] = (string) $row['Field'];
            }
        }

        return $columns;
    }
}

if (!function_exists('bv_mobile_me_header')) {
    function bv_mobile_me_header(string $name): ?string
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

if (!function_exists('bv_mobile_me_is_active')) {
    function bv_mobile_me_is_active(?string $value): bool
    {
        return strtolower(trim((string) $value)) === 'active';
    }
}

try {
    $authorization = bv_mobile_me_header('Authorization');
    if ($authorization === null || !preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        bv_mobile_me_error('token_missing', 'Bearer token is required.', 401);
    }

    $plainToken = trim($matches[1]);
    if ($plainToken === '') {
        bv_mobile_me_error('token_missing', 'Bearer token is required.', 401);
    }

    $tokenHash = hash('sha256', $plainToken);
    $db = bv_mobile_me_db();
    if (!$db) {
        bv_mobile_me_error('db_unavailable', 'Database connection is unavailable.', 503);
    }

    $tokenColumns = bv_mobile_me_columns($db, 'mobile_auth_tokens');
    if ($tokenColumns === null) {
        bv_mobile_me_error('token_table_missing', 'Token table is unavailable.', 500);
    }

    $userColumns = bv_mobile_me_columns($db, 'users');
    if ($userColumns === null || !isset($userColumns['id'])) {
        bv_mobile_me_error('server_error', 'User table is unavailable.', 500);
    }

    if (!isset($tokenColumns['user_id'])) {
        bv_mobile_me_error('server_error', 'Token table is not configured correctly.', 500);
    }

    $tokenPredicate = null;
    $params = [];
    if (isset($tokenColumns['token_hash'])) {
        $tokenPredicate = 'mat.' . bv_mobile_me_identifier($tokenColumns['token_hash']) . ' = ?';
        $params[] = $tokenHash;
    } elseif (isset($tokenColumns['token'])) {
        $tokenPredicate = 'mat.' . bv_mobile_me_identifier($tokenColumns['token']) . ' = ?';
        $params[] = $tokenHash;
    } elseif (isset($tokenColumns['plain_token'])) {
        $tokenPredicate = 'mat.' . bv_mobile_me_identifier($tokenColumns['plain_token']) . ' = ?';
        $params[] = $plainToken;
    } else {
        bv_mobile_me_error('server_error', 'Token table is not configured correctly.', 500);
    }

    $userSelectColumns = ['id', 'first_name', 'last_name', 'name', 'email', 'phone', 'role', 'account_status', 'status', 'created_at', 'last_login_at'];
    $select = [];
    foreach ($userSelectColumns as $column) {
        if (isset($userColumns[$column])) {
            $actual = $userColumns[$column];
            $select[] = 'u.' . bv_mobile_me_identifier($actual) . ' AS ' . bv_mobile_me_identifier($actual);
        }
    }

    if (!$select) {
        bv_mobile_me_error('server_error', 'User table is not configured correctly.', 500);
    }

    $where = [$tokenPredicate];
    if (isset($tokenColumns['revoked_at'])) {
        $where[] = 'mat.' . bv_mobile_me_identifier($tokenColumns['revoked_at']) . ' IS NULL';
    }

    $sql = 'SELECT ' . implode(', ', $select);
    if (isset($tokenColumns['expires_at'])) {
        $sql .= ', mat.' . bv_mobile_me_identifier($tokenColumns['expires_at']) . ' AS __token_expires_at';
    }
    $sql .= ' FROM ' . bv_mobile_me_identifier('mobile_auth_tokens') . ' mat';
    $sql .= ' INNER JOIN ' . bv_mobile_me_identifier('users') . ' u ON u.' . bv_mobile_me_identifier($userColumns['id']) . ' = mat.' . bv_mobile_me_identifier($tokenColumns['user_id']);
    $sql .= ' WHERE ' . implode(' AND ', $where) . ' LIMIT 1';

    $row = bv_mobile_me_fetch_one($db, $sql, $params);
    if (!$row) {
        bv_mobile_me_error('token_invalid', 'Bearer token is invalid.', 401);
    }

    if (isset($tokenColumns['expires_at'])) {
        $expiresAt = isset($row['__token_expires_at']) ? strtotime((string) $row['__token_expires_at']) : false;
        if ($expiresAt === false || $expiresAt <= time()) {
            bv_mobile_me_error('token_expired', 'Bearer token has expired.', 401);
        }
        unset($row['__token_expires_at']);
    }

    $accountStatusActive = false;
    if (isset($userColumns['account_status'])) {
        $accountStatusActive = bv_mobile_me_is_active($row[$userColumns['account_status']] ?? null);
        if (!$accountStatusActive) {
            bv_mobile_me_error('account_inactive', 'User account is inactive.', 403);
        }
    }

    if (!$accountStatusActive && isset($userColumns['status']) && !bv_mobile_me_is_active($row[$userColumns['status']] ?? null)) {
        bv_mobile_me_error('account_inactive', 'User account is inactive.', 403);
    }

    if (isset($tokenColumns['last_used_at'])) {
        try {
            bv_mobile_me_execute(
                $db,
                'UPDATE ' . bv_mobile_me_identifier('mobile_auth_tokens') . ' SET ' . bv_mobile_me_identifier($tokenColumns['last_used_at']) . ' = NOW() WHERE ' . $tokenPredicate . ' LIMIT 1',
                $params
            );
        } catch (Throwable $e) {
            error_log('[Bettavaro Mobile Me] last_used_at update failed: ' . $e->getMessage());
        }
    }

    $user = [];
    foreach ($userSelectColumns as $column) {
        if (isset($userColumns[$column])) {
            $actual = $userColumns[$column];
            $user[$column] = $row[$actual] ?? null;
        }
    }

    if (array_key_exists('id', $user) && is_numeric($user['id'])) {
        $user['id'] = (int) $user['id'];
    }

    $role = strtolower(trim((string) ($user['role'] ?? '')));
    $capabilities = [
        'can_buy' => true,
        'can_sell' => in_array($role, ['seller', 'admin'], true),
        'is_admin' => $role === 'admin',
    ];

    bv_mobile_me_json(200, [
        'ok' => true,
        'data' => [
            'user' => $user,
            'capabilities' => $capabilities,
        ],
        'meta' => bv_mobile_me_meta(),
    ]);
} catch (Throwable $e) {
    error_log('[Bettavaro Mobile Me] Server error: ' . $e->getMessage());
    bv_mobile_me_error('server_error', 'An unexpected server error occurred.', 500);
}
