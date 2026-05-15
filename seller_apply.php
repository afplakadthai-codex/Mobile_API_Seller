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

const BV_SELLER_APPLY_API_VERSION = 'mobile-v1';
const BV_SELLER_APPLY_TERMS_VERSION = 'seller_terms_v1_2026_05_14';

if (!function_exists('bv_seller_apply_meta')) {
    function bv_seller_apply_meta(): array
    {
        return [
            'api_version' => BV_SELLER_APPLY_API_VERSION,
            'generated_at' => gmdate('c'),
        ];
    }
}

if (!function_exists('bv_seller_apply_json')) {
    function bv_seller_apply_json(int $statusCode, array $payload): void
    {
        $payload['meta'] = $payload['meta'] ?? bv_seller_apply_meta();
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_seller_apply_error')) {
    function bv_seller_apply_error(string $code, string $message, int $statusCode = 400, array $extra = []): void
    {
        $error = ['code' => $code, 'message' => $message];
        foreach ($extra as $key => $value) {
            $error[$key] = $value;
        }

        bv_seller_apply_json($statusCode, [
            'ok' => false,
            'error' => $error,
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    bv_seller_apply_error('method_not_allowed', 'Only POST requests are accepted.', 405);
}

if (!function_exists('bv_seller_apply_public_root')) {
    function bv_seller_apply_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_seller_apply_project_root')) {
    function bv_seller_apply_project_root(): string
    {
        return dirname(bv_seller_apply_public_root());
    }
}

if (!function_exists('bv_seller_apply_log')) {
    function bv_seller_apply_log(string $message): void
    {
        error_log('[BV Seller Apply] ' . $message);
        $line = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        foreach ([
            bv_seller_apply_project_root() . '/private_html/mobile_api.log',
            bv_seller_apply_public_root() . '/logs/mobile_api.log',
        ] as $logFile) {
            $logDir = dirname($logFile);
            if (is_dir($logDir) && is_writable($logDir)) {
                @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
                break;
            }
        }
    }
}

if (!function_exists('bv_seller_apply_db')) {
    function bv_seller_apply_db(): ?object
    {
        static $loaded = false;
        static $connection = null;

        if ($loaded) {
            return $connection;
        }

        $loaded = true;
        $publicRoot = bv_seller_apply_public_root();
        $candidates = [
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
            $publicRoot . '/includes/config.php',
            dirname($publicRoot) . '/config/db.php',
            dirname($publicRoot) . '/db.php',
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
                    bv_seller_apply_log('PDO connection failed: ' . $e->getMessage());
                }

                if (class_exists('mysqli')) {
                    try {
                        $mysqli = @new mysqli((string) $dbHost, (string) $dbUser, (string) $dbPass, (string) $dbName, $dbPort ?: 3306);
                        if (!$mysqli->connect_errno) {
                            $mysqli->set_charset('utf8mb4');
                            $connection = $mysqli;
                            return $connection;
                        }
                        bv_seller_apply_log('mysqli connection failed: ' . $mysqli->connect_error);
                    } catch (Throwable $e) {
                        bv_seller_apply_log('mysqli connection exception: ' . $e->getMessage());
                    }
                }
            }
        }

        return null;
    }
}

if (!function_exists('bv_seller_apply_read_bearer')) {
    function bv_seller_apply_read_bearer(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            foreach ($headers as $key => $value) {
                if (strcasecmp((string) $key, 'Authorization') === 0) {
                    $header = (string) $value;
                    break;
                }
            }
        }

        if ($header === '' || !preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return '';
        }

        return trim($matches[1]);
    }
}

if (!function_exists('bv_seller_apply_bind_type')) {
    function bv_seller_apply_bind_type($value): string
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

if (!function_exists('bv_seller_apply_query_all')) {
    function bv_seller_apply_query_all(object $db, string $sql, array $params = []): array
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
                $types = implode('', array_map('bv_seller_apply_bind_type', $params));
                $values = array_values($params);
                $refs = [&$types];
                foreach ($values as $key => $value) {
                    $refs[] = &$values[$key];
                }
                call_user_func_array([$statement, 'bind_param'], $refs);
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

if (!function_exists('bv_seller_apply_query_one')) {
    function bv_seller_apply_query_one(object $db, string $sql, array $params = []): ?array
    {
        $rows = bv_seller_apply_query_all($db, $sql, $params);
        return $rows[0] ?? null;
    }
}

if (!function_exists('bv_seller_apply_execute')) {
    function bv_seller_apply_execute(object $db, string $sql, array $params = []): bool
    {
        if ($db instanceof PDO) {
            $statement = $db->prepare($sql);
            return $statement->execute($params);
        }

        if ($db instanceof mysqli) {
            $statement = $db->prepare($sql);
            if (!$statement) {
                throw new RuntimeException('Unable to prepare statement.');
            }

            if ($params) {
                $types = implode('', array_map('bv_seller_apply_bind_type', $params));
                $values = array_values($params);
                $refs = [&$types];
                foreach ($values as $key => $value) {
                    $refs[] = &$values[$key];
                }
                call_user_func_array([$statement, 'bind_param'], $refs);
            }

            $ok = $statement->execute();
            $statement->close();
            return $ok;
        }

        throw new RuntimeException('Unsupported database connection.');
    }
}

if (!function_exists('bv_seller_apply_table_exists')) {
    function bv_seller_apply_table_exists(object $db, string $table): bool
    {
        try {
            bv_seller_apply_query_all($db, 'SHOW COLUMNS FROM `' . $table . '`');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('bv_seller_apply_columns')) {
    function bv_seller_apply_columns(object $db, string $table): array
    {
        $rows = bv_seller_apply_query_all($db, 'SHOW COLUMNS FROM `' . $table . '`');
        $columns = [];
        foreach ($rows as $row) {
            $field = strtolower((string) ($row['Field'] ?? ''));
            if ($field !== '') {
                $columns[$field] = (string) $row['Field'];
            }
        }
        return $columns;
    }
}

if (!function_exists('bv_seller_apply_clean_string')) {
    function bv_seller_apply_clean_string($value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }

        return substr($value, 0, $maxLength);
    }
}

if (!function_exists('bv_seller_apply_is_terms_true')) {
    function bv_seller_apply_is_terms_true($value): bool
    {
        if ($value === true || $value === 1) {
            return true;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            return in_array($normalized, ['1', 'true', 'yes'], true);
        }

        return false;
    }
}

if (!function_exists('bv_seller_apply_client_ip')) {
    function bv_seller_apply_client_ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            $value = trim((string) ($_SERVER[$key] ?? ''));
            if ($value === '') {
                continue;
            }

            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $parts = array_map('trim', explode(',', $value));
                $value = (string) ($parts[0] ?? '');
            }

            if ($value !== '') {
                return substr($value, 0, 45);
            }
        }

        return '';
    }
}

try {
    $plainToken = bv_seller_apply_read_bearer();
    if ($plainToken === '') {
        bv_seller_apply_error('token_missing', 'Authorization Bearer token is required.', 401);
    }

    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody === false ? '' : $rawBody, true);
    if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
        bv_seller_apply_error('invalid_json', 'Request body must be valid JSON.', 400);
    }

    $farmName = bv_seller_apply_clean_string($payload['farm_name'] ?? null, 150);
    $farmNameLength = $farmName === null ? 0 : (function_exists('mb_strlen') ? mb_strlen($farmName, 'UTF-8') : strlen($farmName));
    $validationErrors = [];

    if ($farmName === null || $farmNameLength < 2 || $farmNameLength > 150) {
        $validationErrors['farm_name'] = 'Farm name is required and must be between 2 and 150 characters.';
    }

    if (!array_key_exists('terms_version', $payload) || trim((string) $payload['terms_version']) === '') {
        $validationErrors['terms_version'] = 'Terms version is required.';
    }

    if ($validationErrors) {
        bv_seller_apply_error('validation_failed', 'Please correct the highlighted fields.', 422, ['fields' => $validationErrors]);
    }

    if (!bv_seller_apply_is_terms_true($payload['agree_terms'] ?? null)) {
        bv_seller_apply_error('terms_not_accepted', 'Seller terms must be accepted before applying.', 422);
    }

    $termsVersion = trim((string) ($payload['terms_version'] ?? ''));
    if ($termsVersion !== BV_SELLER_APPLY_TERMS_VERSION) {
        bv_seller_apply_error('terms_version_mismatch', 'Seller terms version is not current.', 422);
    }

    $db = bv_seller_apply_db();
    if ($db === null) {
        bv_seller_apply_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    $tokenHash = hash('sha256', $plainToken);
    $tokenRow = bv_seller_apply_query_one(
        $db,
        "SELECT mat.id AS token_id, mat.user_id, mat.expires_at, mat.revoked_at,
                u.id AS user_id, u.role, u.account_status
         FROM mobile_auth_tokens mat
         LEFT JOIN users u ON u.id = mat.user_id
         WHERE mat.token_hash = ?
         LIMIT 1",
        [$tokenHash]
    );

    if ($tokenRow === null || !empty($tokenRow['revoked_at'])) {
        bv_seller_apply_error('token_invalid', 'Token is invalid.', 401);
    }

    $expiresAt = (string) ($tokenRow['expires_at'] ?? '');
    if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) <= time()) {
        bv_seller_apply_error('token_expired', 'Token has expired.', 401);
    }

    $userId = (int) ($tokenRow['user_id'] ?? 0);
    if ($userId <= 0) {
        bv_seller_apply_error('token_invalid', 'Token is invalid.', 401);
    }

    $accountStatus = strtolower(trim((string) ($tokenRow['account_status'] ?? '')));
    if ($accountStatus !== 'active') {
        bv_seller_apply_error('account_inactive', 'Account is inactive.', 403);
    }

    bv_seller_apply_execute($db, 'UPDATE mobile_auth_tokens SET last_used_at = NOW() WHERE id = ? LIMIT 1', [(int) $tokenRow['token_id']]);

    $userRole = strtolower(trim((string) ($tokenRow['role'] ?? 'user')));
    if (in_array($userRole, ['seller', 'admin'], true)) {
        bv_seller_apply_json(200, [
            'ok' => true,
            'data' => [
                'status' => 'already_seller',
                'message' => 'This account is already approved as a seller.',
            ],
        ]);
    }

    if (!bv_seller_apply_table_exists($db, 'seller_applications')) {
        bv_seller_apply_error('seller_application_table_missing', 'Seller application table is not available.', 503);
    }

    $columns = bv_seller_apply_columns($db, 'seller_applications');
    $hasColumn = static function (string $column) use ($columns): bool {
        return isset($columns[strtolower($column)]);
    };
    $columnName = static function (string $column) use ($columns): string {
        return $columns[strtolower($column)] ?? $column;
    };
    $quoteColumn = static function (string $column) use ($columnName): string {
        return '`' . str_replace('`', '``', $columnName($column)) . '`';
    };

    if (!$hasColumn('user_id')) {
        bv_seller_apply_error('server_error', 'Seller application table is missing required user reference.', 500);
    }

    $statusColumns = array_values(array_filter(['status', 'application_status'], $hasColumn));
    if ($statusColumns) {
        $selectParts = ['`id`'];
        foreach ($statusColumns as $statusColumn) {
            $selectParts[] = $quoteColumn($statusColumn);
        }

        $orderBy = $hasColumn('created_at') ? $quoteColumn('created_at') . ' DESC' : '`id` DESC';
        $latestApplication = bv_seller_apply_query_one(
            $db,
            'SELECT ' . implode(', ', $selectParts) . ' FROM `seller_applications` WHERE ' . $quoteColumn('user_id') . ' = ? ORDER BY ' . $orderBy . ', `id` DESC LIMIT 1',
            [$userId]
        );

        if ($latestApplication !== null) {
            $latestStatus = '';
            foreach (['application_status', 'status'] as $statusColumn) {
                if ($hasColumn($statusColumn) && isset($latestApplication[$columnName($statusColumn)])) {
                    $latestStatus = strtolower(trim((string) $latestApplication[$columnName($statusColumn)]));
                    if ($latestStatus !== '') {
                        break;
                    }
                }
            }

            if (in_array($latestStatus, ['pending', 'under_review', 'approved'], true)) {
                bv_seller_apply_error('application_exists', 'You already have a seller application in review.', 409);
            }
        }
    } else {
        $existingApplication = bv_seller_apply_query_one(
            $db,
            'SELECT `id` FROM `seller_applications` WHERE ' . $quoteColumn('user_id') . ' = ? ORDER BY `id` DESC LIMIT 1',
            [$userId]
        );

        if ($existingApplication !== null) {
            bv_seller_apply_error('application_exists', 'You already have a seller application in review.', 409);
        }
    }

    $applicationData = [
        'user_id' => $userId,
        'farm_name' => $farmName,
        'contact_name' => bv_seller_apply_clean_string($payload['contact_name'] ?? null, 150),
        'phone' => bv_seller_apply_clean_string($payload['phone'] ?? null, 50),
        'country' => bv_seller_apply_clean_string($payload['country'] ?? null, 100),
        'province' => bv_seller_apply_clean_string($payload['province'] ?? null, 100),
        'city' => bv_seller_apply_clean_string($payload['city'] ?? null, 100),
        'address' => bv_seller_apply_clean_string($payload['address'] ?? null, 255),
        'farm_description' => bv_seller_apply_clean_string($payload['farm_description'] ?? null, 5000),
        'experience_years' => isset($payload['experience_years']) && $payload['experience_years'] !== '' ? max(0, (int) $payload['experience_years']) : null,
        'status' => 'pending',
        'application_status' => 'pending',
        'terms_version' => $termsVersion,
        'terms_ip_address' => bv_seller_apply_client_ip(),
        'terms_user_agent' => bv_seller_apply_clean_string($_SERVER['HTTP_USER_AGENT'] ?? '', 500),
    ];

    $insertColumns = [];
    $placeholders = [];
    $params = [];
    foreach ($applicationData as $field => $value) {
        if ($hasColumn($field)) {
            $insertColumns[] = $quoteColumn($field);
            $placeholders[] = '?';
            $params[] = $value;
        }
    }

    if ($hasColumn('terms_accepted_at')) {
        $insertColumns[] = $quoteColumn('terms_accepted_at');
        $placeholders[] = 'NOW()';
    }
    if ($hasColumn('created_at')) {
        $insertColumns[] = $quoteColumn('created_at');
        $placeholders[] = 'NOW()';
    }
    if ($hasColumn('updated_at')) {
        $insertColumns[] = $quoteColumn('updated_at');
        $placeholders[] = 'NOW()';
    }

    if (!$insertColumns) {
        bv_seller_apply_error('server_error', 'Seller application table has no supported columns.', 500);
    }

    $insertSql = 'INSERT INTO `seller_applications` (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';
    bv_seller_apply_execute($db, $insertSql, $params);

    if ($db instanceof PDO) {
        $applicationId = (int) $db->lastInsertId();
    } elseif ($db instanceof mysqli) {
        $applicationId = (int) $db->insert_id;
    } else {
        $applicationId = 0;
    }

    $termsAcceptedAt = gmdate('Y-m-d H:i:s');
    $createdApplication = null;
    if ($applicationId > 0) {
        $selectFields = ['`id`'];
        foreach (['user_id', 'farm_name', 'status', 'application_status', 'terms_version', 'terms_accepted_at'] as $field) {
            if ($hasColumn($field)) {
                $selectFields[] = $quoteColumn($field);
            }
        }

        $createdApplication = bv_seller_apply_query_one(
            $db,
            'SELECT ' . implode(', ', $selectFields) . ' FROM `seller_applications` WHERE `id` = ? LIMIT 1',
            [$applicationId]
        );
    }

    $responseStatus = 'pending';
    if (is_array($createdApplication)) {
        foreach (['application_status', 'status'] as $field) {
            $actual = $columnName($field);
            if ($hasColumn($field) && isset($createdApplication[$actual]) && trim((string) $createdApplication[$actual]) !== '') {
                $responseStatus = (string) $createdApplication[$actual];
                break;
            }
        }

        if ($hasColumn('terms_accepted_at') && isset($createdApplication[$columnName('terms_accepted_at')])) {
            $termsAcceptedAt = (string) $createdApplication[$columnName('terms_accepted_at')];
        }
    }

    bv_seller_apply_json(201, [
        'ok' => true,
        'data' => [
            'application' => [
                'id' => $applicationId,
                'user_id' => $userId,
                'farm_name' => $farmName,
                'status' => $responseStatus,
                'terms_version' => $termsVersion,
                'terms_accepted_at' => $termsAcceptedAt,
            ],
            'message' => 'Seller application submitted successfully.',
        ],
    ]);
} catch (Throwable $e) {
    bv_seller_apply_log('Unhandled error: ' . $e->getMessage());
    bv_seller_apply_error('server_error', 'An unexpected error occurred.', 500);
}
