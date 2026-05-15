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
header('Access-Control-Allow-Methods: POST, OPTIONS');

const BV_MOBILE_API_VERSION = 'mobile-v1';
const BV_MOBILE_TOKEN_DAYS = 90;

if (!function_exists('bv_mobile_generated_at')) {
    function bv_mobile_generated_at(): string
    {
        return gmdate('c');
    }
}

if (!function_exists('bv_mobile_payload')) {
    function bv_mobile_payload(array $body): array
    {
        $body['meta'] = [
            'api_version' => BV_MOBILE_API_VERSION,
            'generated_at' => bv_mobile_generated_at(),
        ];

        return $body;
    }
}

if (!function_exists('bv_mobile_json')) {
    function bv_mobile_json(int $statusCode, array $body): void
    {
        http_response_code($statusCode);
        echo json_encode(
            bv_mobile_payload($body),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
        exit;
    }
}

if (!function_exists('bv_mobile_error')) {
    function bv_mobile_error(string $code, string $message, int $statusCode): void
    {
        bv_mobile_json($statusCode, [
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    bv_mobile_error('method_not_allowed', 'Only POST requests are accepted.', 405);
}

if (!function_exists('bv_mobile_base_paths')) {
    function bv_mobile_base_paths(): array
    {
        $paths = [
            __DIR__,
            dirname(__DIR__),
            dirname(__DIR__, 2),
            dirname(__DIR__, 3),
            dirname(__DIR__, 4),
            dirname(__DIR__, 5),
            dirname(__DIR__, 6),
            (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
            dirname((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')),
        ];

        return array_values(array_unique(array_filter($paths, static fn(string $path): bool => $path !== '' && is_dir($path))));
    }
}

if (!function_exists('bv_mobile_db_candidates')) {
    function bv_mobile_db_candidates(): array
    {
        $relative = [
            'config/db.php',
            'includes/db.php',
            'includes/config.php',
            'db.php',
        ];

        $candidates = [];
        foreach (bv_mobile_base_paths() as $basePath) {
            foreach ($relative as $relPath) {
                $candidates[] = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . $relPath;
            }
        }

        return array_values(array_unique($candidates));
    }
}

if (!function_exists('bv_mobile_load_db_file')) {
    function bv_mobile_load_db_file(string $path): array
    {
        $db_host = $db_user = $db_pass = $db_name = $db_port = null;
        $host = $user = $pass = $name = $port = null;
        $DB_HOST = $DB_USER = $DB_PASS = $DB_NAME = $DB_PORT = null;
        $dsn = null;
        $pdo = $conn = $db = $mysqli = $link = null;

        include $path;

        return compact(
            'db_host',
            'db_user',
            'db_pass',
            'db_name',
            'db_port',
            'host',
            'user',
            'pass',
            'name',
            'port',
            'DB_HOST',
            'DB_USER',
            'DB_PASS',
            'DB_NAME',
            'DB_PORT',
            'dsn',
            'pdo',
            'conn',
            'db',
            'mysqli',
            'link'
        );
    }
}

if (!function_exists('bv_mobile_db')) {
    function bv_mobile_db(): ?object
    {
        static $loaded = false;
        static $connection = null;

        if ($loaded) {
            return $connection;
        }
        $loaded = true;

        foreach (bv_mobile_db_candidates() as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }

            $vars = bv_mobile_load_db_file($candidate);

            foreach (['pdo', 'conn', 'db', 'mysqli', 'link'] as $name) {
                $value = $vars[$name] ?? $GLOBALS[$name] ?? null;
                if ($value instanceof PDO) {
                    $value->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $value->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    $connection = $value;
                    return $connection;
                }
                if ($value instanceof mysqli) {
                    $value->set_charset('utf8mb4');
                    $connection = $value;
                    return $connection;
                }
            }

            $h = $vars['db_host'] ?? $vars['host'] ?? $vars['DB_HOST'] ?? null;
            $u = $vars['db_user'] ?? $vars['user'] ?? $vars['DB_USER'] ?? null;
            $p = $vars['db_pass'] ?? $vars['pass'] ?? $vars['DB_PASS'] ?? '';
            $n = $vars['db_name'] ?? $vars['name'] ?? $vars['DB_NAME'] ?? null;
            $pt = (int) ($vars['db_port'] ?? $vars['port'] ?? $vars['DB_PORT'] ?? 3306);
            $dsn = $vars['dsn'] ?? null;

            if (is_string($dsn) && $dsn !== '' && $u !== null) {
                $connection = new PDO($dsn, (string) $u, (string) $p, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
                return $connection;
            }

            if ($h !== null && $u !== null && $n !== null) {
                $connection = new PDO(
                    'mysql:host=' . (string) $h . ';port=' . ($pt ?: 3306) . ';dbname=' . (string) $n . ';charset=utf8mb4',
                    (string) $u,
                    (string) $p,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]
                );
                return $connection;
            }
        }

        return null;
    }
}

if (!function_exists('bv_mobile_identifier')) {
    function bv_mobile_identifier(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
            throw new RuntimeException('Unsafe database identifier.');
        }

        return '`' . $identifier . '`';
    }
}

if (!function_exists('bv_mobile_columns')) {
    function bv_mobile_columns(object $db, string $table): ?array
    {
        try {
            $sql = 'SHOW COLUMNS FROM ' . bv_mobile_identifier($table);

            if ($db instanceof PDO) {
                $stmt = $db->prepare($sql);
                $stmt->execute();
                $columns = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $field = strtolower((string) ($row['Field'] ?? ''));
                    if ($field !== '') {
                        $columns[$field] = $field;
                    }
                }
                return $columns;
            }

            if ($db instanceof mysqli) {
                $stmt = $db->prepare($sql);
                if ($stmt === false || !$stmt->execute()) {
                    return null;
                }
                $result = $stmt->get_result();
                $columns = [];
                if ($result instanceof mysqli_result) {
                    while ($row = $result->fetch_assoc()) {
                        $field = strtolower((string) ($row['Field'] ?? ''));
                        if ($field !== '') {
                            $columns[$field] = $field;
                        }
                    }
                    $result->free();
                }
                $stmt->close();
                return $columns;
            }
        } catch (Throwable $e) {
            error_log('[Bettavaro Mobile Login] Column detection failed for ' . $table . ': ' . $e->getMessage());
            return null;
        }

        return null;
    }
}

if (!function_exists('bv_mobile_has_column')) {
    function bv_mobile_has_column(array $columns, string $column): bool
    {
        return array_key_exists(strtolower($column), $columns);
    }
}

if (!function_exists('bv_mobile_bind_mysqli')) {
    function bv_mobile_bind_mysqli(mysqli_stmt $stmt, array &$params): void
    {
        if ($params === []) {
            return;
        }

        $types = '';
        foreach ($params as $param) {
            $types .= is_int($param) ? 'i' : (is_float($param) ? 'd' : 's');
        }

        $refs = [&$types];
        foreach ($params as $key => $_value) {
            $refs[] = &$params[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('bv_mobile_fetch_one')) {
    function bv_mobile_fetch_one(object $db, string $sql, array $params = []): ?array
    {
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return is_array($row) ? $row : null;
        }

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                return null;
            }
            bv_mobile_bind_mysqli($stmt, $params);
            if (!$stmt->execute()) {
                $stmt->close();
                return null;
            }
            $result = $stmt->get_result();
            $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
            if ($result instanceof mysqli_result) {
                $result->free();
            }
            $stmt->close();
            return is_array($row) ? $row : null;
        }

        return null;
    }
}

if (!function_exists('bv_mobile_execute')) {
    function bv_mobile_execute(object $db, string $sql, array $params = []): bool
    {
        if ($db instanceof PDO) {
            $stmt = $db->prepare($sql);
            return $stmt->execute($params);
        }

        if ($db instanceof mysqli) {
            $stmt = $db->prepare($sql);
            if ($stmt === false) {
                return false;
            }
            bv_mobile_bind_mysqli($stmt, $params);
            $ok = $stmt->execute();
            $stmt->close();
            return $ok;
        }

        return false;
    }
}

if (!function_exists('bv_mobile_read_input')) {
    function bv_mobile_read_input(): array
    {
        $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));

        if (str_contains($contentType, 'application/json')) {
            $raw = (string) file_get_contents('php://input');
            $decoded = json_decode($raw, true);

            if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
                bv_mobile_error('invalid_json', 'Request body must be valid JSON.', 400);
            }

            return [
                'email' => trim((string) ($decoded['email'] ?? '')),
                'password' => (string) ($decoded['password'] ?? ''),
            ];
        }

        return [
            'email' => trim((string) ($_POST['email'] ?? '')),
            'password' => (string) ($_POST['password'] ?? ''),
        ];
    }
}

if (!function_exists('bv_mobile_client_ip')) {
    function bv_mobile_client_ip(): string
    {
        return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }
}

if (!function_exists('bv_mobile_user_agent')) {
    function bv_mobile_user_agent(): string
    {
        return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }
}

if (!function_exists('bv_mobile_clean')) {
    function bv_mobile_clean(mixed $value): string
    {
        return trim(htmlspecialchars_decode(strip_tags((string) ($value ?? '')), ENT_QUOTES | ENT_HTML5));
    }
}

try {
    $input = bv_mobile_read_input();
    $email = strtolower($input['email']);
    $password = $input['password'];

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        bv_mobile_error('validation_failed', 'Email and password are required. Email must be valid.', 422);
    }

    $db = bv_mobile_db();
    if (!$db instanceof PDO && !$db instanceof mysqli) {
        bv_mobile_error('db_unavailable', 'Database is unavailable.', 503);
    }

    $userColumns = bv_mobile_columns($db, 'users');
    if ($userColumns === null) {
        bv_mobile_error('db_unavailable', 'Database is unavailable.', 503);
    }

    $tokenColumns = bv_mobile_columns($db, 'mobile_auth_tokens');
    if ($tokenColumns === null) {
        bv_mobile_error('token_table_missing', 'Mobile token storage is not available.', 503);
    }

    $selectColumns = ['id', 'email'];
    foreach (['password_hash', 'password', 'account_status', 'status', 'role', 'first_name', 'last_name'] as $column) {
        if (bv_mobile_has_column($userColumns, $column)) {
            $selectColumns[] = $column;
        }
    }

    $selectSql = 'SELECT ' . implode(', ', array_map('bv_mobile_identifier', $selectColumns))
        . ' FROM ' . bv_mobile_identifier('users')
        . ' WHERE LOWER(' . bv_mobile_identifier('email') . ') = ? LIMIT 1';

    $user = bv_mobile_fetch_one($db, $selectSql, [$email]);
    $dummyHash = '$2y$10$CwTycUXWue0Thq9StjUM0uJ8viQ2w4Q7t9Q1scRAPk3TuFMSR.s3S';
    $storedHash = $dummyHash;

    if (is_array($user)) {
        if (bv_mobile_has_column($userColumns, 'password_hash') && (string) ($user['password_hash'] ?? '') !== '') {
            $storedHash = (string) $user['password_hash'];
        } elseif (bv_mobile_has_column($userColumns, 'password') && (string) ($user['password'] ?? '') !== '') {
            $storedHash = (string) $user['password'];
        }
    }

    if (!password_verify($password, $storedHash) || !is_array($user)) {
        bv_mobile_error('invalid_credentials', 'Invalid email or password.', 401);
    }

    $accountStatus = bv_mobile_has_column($userColumns, 'account_status')
        ? strtolower(trim((string) ($user['account_status'] ?? '')))
        : '';
    $status = bv_mobile_has_column($userColumns, 'status')
        ? strtolower(trim((string) ($user['status'] ?? '')))
        : '';

    if (bv_mobile_has_column($userColumns, 'account_status')) {
        if ($accountStatus !== 'active') {
            bv_mobile_error('account_inactive', 'Account is inactive.', 403);
        }
    } elseif (bv_mobile_has_column($userColumns, 'status') && $status !== 'active') {
        bv_mobile_error('account_inactive', 'Account is inactive.', 403);
    }

    $plainToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $plainToken);
    $now = date('Y-m-d H:i:s');
    $expiresAt = date('Y-m-d H:i:s', time() + (BV_MOBILE_TOKEN_DAYS * 86400));
    $userId = (int) ($user['id'] ?? 0);

    $insertColumns = [];
    $insertValues = [];

    if (bv_mobile_has_column($tokenColumns, 'user_id')) {
        $insertColumns[] = 'user_id';
        $insertValues[] = $userId;
    }

    if (bv_mobile_has_column($tokenColumns, 'token_hash')) {
        $insertColumns[] = 'token_hash';
        $insertValues[] = $tokenHash;
    } elseif (bv_mobile_has_column($tokenColumns, 'token')) {
        $insertColumns[] = 'token';
        $insertValues[] = $tokenHash;
    } elseif (bv_mobile_has_column($tokenColumns, 'plain_token')) {
        $insertColumns[] = 'plain_token';
        $insertValues[] = $plainToken;
    } else {
        bv_mobile_error('server_error', 'Token storage is not configured.', 500);
    }

    foreach (['created_at' => $now, 'updated_at' => $now, 'last_used_at' => $now, 'expires_at' => $expiresAt] as $column => $value) {
        if (bv_mobile_has_column($tokenColumns, $column)) {
            $insertColumns[] = $column;
            $insertValues[] = $value;
        }
    }

    if (bv_mobile_has_column($tokenColumns, 'device_name')) {
        $insertColumns[] = 'device_name';
        $insertValues[] = '';
    }
    if (bv_mobile_has_column($tokenColumns, 'device_id')) {
        $insertColumns[] = 'device_id';
        $insertValues[] = '';
    }
    if (bv_mobile_has_column($tokenColumns, 'user_agent')) {
        $insertColumns[] = 'user_agent';
        $insertValues[] = bv_mobile_user_agent();
    }
    if (bv_mobile_has_column($tokenColumns, 'ip_address')) {
        $insertColumns[] = 'ip_address';
        $insertValues[] = bv_mobile_client_ip();
    }

    $insertSql = 'INSERT INTO ' . bv_mobile_identifier('mobile_auth_tokens')
        . ' (' . implode(', ', array_map('bv_mobile_identifier', $insertColumns)) . ') VALUES ('
        . implode(', ', array_fill(0, count($insertColumns), '?')) . ')';

    if (!bv_mobile_execute($db, $insertSql, $insertValues)) {
        bv_mobile_error('server_error', 'Unable to create mobile token.', 500);
    }

    if (bv_mobile_has_column($userColumns, 'last_login_at')) {
        bv_mobile_execute(
            $db,
            'UPDATE ' . bv_mobile_identifier('users')
            . ' SET ' . bv_mobile_identifier('last_login_at') . ' = ? WHERE ' . bv_mobile_identifier('id') . ' = ? LIMIT 1',
            [$now, $userId]
        );
    }

    $role = bv_mobile_has_column($userColumns, 'role') && bv_mobile_clean($user['role'] ?? '') !== ''
        ? bv_mobile_clean($user['role'])
        : 'user';
    $responseAccountStatus = $accountStatus !== '' ? $accountStatus : ($status !== '' ? $status : 'active');

    bv_mobile_json(200, [
        'ok' => true,
        'data' => [
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_in_days' => BV_MOBILE_TOKEN_DAYS,
            'user' => [
                'id' => $userId,
                'first_name' => bv_mobile_clean($user['first_name'] ?? ''),
                'last_name' => bv_mobile_clean($user['last_name'] ?? ''),
                'email' => strtolower(bv_mobile_clean($user['email'] ?? $email)),
                'role' => $role,
                'account_status' => $responseAccountStatus,
            ],
        ],
    ]);
} catch (Throwable $e) {
    error_log('[Bettavaro Mobile Login] ' . $e->getMessage());
    bv_mobile_error('server_error', 'Something went wrong.', 500);
}
