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

if (!function_exists('bv_seller_listing_detail_meta')) {
    function bv_seller_listing_detail_meta(): array
    {
        return [
            'api_version' => 'mobile-v1',
            'generated_at' => gmdate('c'),
        ];
    }
}

if (!function_exists('bv_seller_listing_detail_json')) {
    function bv_seller_listing_detail_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_seller_listing_detail_error')) {
    function bv_seller_listing_detail_error(string $code, string $message, int $statusCode): void
    {
        bv_seller_listing_detail_json($statusCode, [
            'ok' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => bv_seller_listing_detail_meta(),
        ]);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    bv_seller_listing_detail_error('method_not_allowed', 'Only GET requests are accepted.', 405);
}

if (!function_exists('bv_seller_listing_detail_public_root')) {
    function bv_seller_listing_detail_public_root(): string
    {
        return dirname(__DIR__, 3);
    }
}

if (!function_exists('bv_seller_listing_detail_project_root')) {
    function bv_seller_listing_detail_project_root(): string
    {
        return dirname(bv_seller_listing_detail_public_root());
    }
}

if (!function_exists('bv_seller_listing_detail_log')) {
    function bv_seller_listing_detail_log(string $message): void
    {
        error_log('[BV Seller Listing Detail] ' . $message);
    }
}

if (!function_exists('bv_seller_listing_detail_db')) {
    function bv_seller_listing_detail_db(): ?object
    {
        static $loaded = false;
        static $connection = null;

        if ($loaded) {
            return $connection;
        }

        $loaded = true;
        $publicRoot = bv_seller_listing_detail_public_root();
        $projectRoot = bv_seller_listing_detail_project_root();
        $candidates = [
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
            $publicRoot . '/includes/config.php',
            $publicRoot . '/db.php',
            $publicRoot . '/config.php',
            $projectRoot . '/config/db.php',
            $projectRoot . '/includes/db.php',
            $projectRoot . '/includes/config.php',
            $projectRoot . '/db.php',
            $projectRoot . '/config.php',
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
                @include $file;

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

            $dsnValue = $vars['dsn'] ?? null;
            $dbUser = $vars['db_user'] ?? $vars['user'] ?? $vars['DB_USER'] ?? null;
            $dbPass = $vars['db_pass'] ?? $vars['pass'] ?? $vars['DB_PASS'] ?? '';
            if ($dsnValue && $dbUser !== null) {
                try {
                    $pdo = new PDO((string) $dsnValue, (string) $dbUser, (string) $dbPass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                    ]);
                    $connection = $pdo;
                    return $connection;
                } catch (Throwable $e) {
                    bv_seller_listing_detail_log('PDO DSN connection failed: ' . $e->getMessage());
                }
            }

            $dbHost = $vars['db_host'] ?? $vars['host'] ?? $vars['DB_HOST'] ?? null;
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
                    bv_seller_listing_detail_log('PDO connection failed: ' . $e->getMessage());
                }

                if (class_exists('mysqli')) {
                    try {
                        $mysqli = @new mysqli((string) $dbHost, (string) $dbUser, (string) $dbPass, (string) $dbName, $dbPort ?: 3306);
                        if (!$mysqli->connect_errno) {
                            $mysqli->set_charset('utf8mb4');
                            $connection = $mysqli;
                            return $connection;
                        }
                        bv_seller_listing_detail_log('mysqli connection failed: ' . $mysqli->connect_error);
                    } catch (Throwable $e) {
                        bv_seller_listing_detail_log('mysqli connection exception: ' . $e->getMessage());
                    }
                }
            }
        }

        return null;
    }
}

if (!function_exists('bv_sld_read_bearer')) {
    function bv_sld_read_bearer(): string
    {
        $header = '';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['HTTP_AUTHORIZATION'];
        } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        } elseif (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $key => $value) {
                if (strtolower((string) $key) === 'authorization') {
                    $header = (string) $value;
                    break;
                }
            }
        }

        if ($header === '' || !preg_match('/^Bearer\s+(\S+)$/i', trim($header), $matches)) {
            return '';
        }

        return $matches[1];
    }
}

if (!function_exists('bv_sld_bind_mysqli_params')) {
    function bv_sld_bind_mysqli_params(mysqli_stmt $stmt, array $params): void
    {
        if (empty($params)) {
            return;
        }

        $types = implode('', array_map(static function ($value): string {
            if (is_int($value)) {
                return 'i';
            }
            if (is_float($value)) {
                return 'd';
            }
            return 's';
        }, $params));

        $refs = [&$types];
        foreach ($params as $key => $_) {
            $refs[] = &$params[$key];
        }

        call_user_func_array([$stmt, 'bind_param'], $refs);
    }
}

if (!function_exists('bv_sld_query_row')) {
    function bv_sld_query_row(object $db, string $sql, array $params = []): ?array
    {
        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                return $row ?: null;
            }

            if ($db instanceof mysqli) {
                $stmt = $db->prepare($sql);
                if ($stmt === false) {
                    return null;
                }
                bv_sld_bind_mysqli_params($stmt, $params);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_assoc() : null;
                $stmt->close();
                return $row ?: null;
            }
        } catch (Throwable $e) {
            bv_seller_listing_detail_log('query_row failed: ' . $e->getMessage());
        }

        return null;
    }
}

if (!function_exists('bv_sld_query_rows')) {
    function bv_sld_query_rows(object $db, string $sql, array $params = []): array
    {
        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            if ($db instanceof mysqli) {
                $stmt = $db->prepare($sql);
                if ($stmt === false) {
                    return [];
                }
                bv_sld_bind_mysqli_params($stmt, $params);
                $stmt->execute();
                $result = $stmt->get_result();
                $rows = [];
                if ($result) {
                    while ($row = $result->fetch_assoc()) {
                        $rows[] = $row;
                    }
                }
                $stmt->close();
                return $rows;
            }
        } catch (Throwable $e) {
            bv_seller_listing_detail_log('query_rows failed: ' . $e->getMessage());
        }

        return [];
    }
}

if (!function_exists('bv_sld_query_scalar')) {
    function bv_sld_query_scalar(object $db, string $sql, array $params = [], $default = 0)
    {
        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $row = $stmt->fetch(PDO::FETCH_NUM);
                return $row ? $row[0] : $default;
            }

            if ($db instanceof mysqli) {
                $stmt = $db->prepare($sql);
                if ($stmt === false) {
                    return $default;
                }
                bv_sld_bind_mysqli_params($stmt, $params);
                $stmt->execute();
                $result = $stmt->get_result();
                $row = $result ? $result->fetch_row() : null;
                $stmt->close();
                return $row ? $row[0] : $default;
            }
        } catch (Throwable $e) {
            bv_seller_listing_detail_log('query_scalar failed: ' . $e->getMessage());
        }

        return $default;
    }
}

if (!function_exists('bv_sld_execute')) {
    function bv_sld_execute(object $db, string $sql, array $params = []): bool
    {
        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare($sql);
                return $stmt->execute($params);
            }

            if ($db instanceof mysqli) {
                $stmt = $db->prepare($sql);
                if ($stmt === false) {
                    return false;
                }
                bv_sld_bind_mysqli_params($stmt, $params);
                $result = $stmt->execute();
                $stmt->close();
                return $result;
            }
        } catch (Throwable $e) {
            bv_seller_listing_detail_log('execute failed: ' . $e->getMessage());
        }

        return false;
    }
}

if (!function_exists('bv_sld_table_exists')) {
    function bv_sld_table_exists(object $db, string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return false;
        }

        try {
            if ($db instanceof PDO) {
                $previous = $db->getAttribute(PDO::ATTR_ERRMODE);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                try {
                    $stmt = $db->prepare("SELECT 1 FROM `{$table}` LIMIT 1");
                    $stmt->execute();
                    $db->setAttribute(PDO::ATTR_ERRMODE, $previous);
                    return true;
                } catch (Throwable $e) {
                    $db->setAttribute(PDO::ATTR_ERRMODE, $previous);
                    return false;
                }
            }

            if ($db instanceof mysqli) {
                $stmt = $db->prepare("SELECT 1 FROM `{$table}` LIMIT 1");
                if ($stmt === false) {
                    return false;
                }
                $ok = $stmt->execute();
                $stmt->close();
                return $ok;
            }
        } catch (Throwable $e) {
            bv_seller_listing_detail_log('table_exists failed: ' . $e->getMessage());
        }

        return false;
    }
}

if (!function_exists('bv_sld_columns')) {
    function bv_sld_columns(object $db, string $table): array
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            return [];
        }

        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        $columns = [];
        try {
            if ($db instanceof PDO) {
                $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}`");
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $field = strtolower((string) ($row['Field'] ?? ''));
                    if ($field !== '') {
                        $columns[] = $field;
                    }
                }
            } elseif ($db instanceof mysqli) {
                $stmt = $db->prepare("SHOW COLUMNS FROM `{$table}`");
                if ($stmt !== false) {
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result) {
                        while ($row = $result->fetch_assoc()) {
                            $field = strtolower((string) ($row['Field'] ?? ''));
                            if ($field !== '') {
                                $columns[] = $field;
                            }
                        }
                    }
                    $stmt->close();
                }
            }
        } catch (Throwable $e) {
            bv_seller_listing_detail_log('columns failed: ' . $e->getMessage());
        }

        $cache[$table] = $columns;
        return $columns;
    }
}

if (!function_exists('bv_sld_has_column')) {
    function bv_sld_has_column(object $db, string $table, string $column): bool
    {
        return in_array(strtolower($column), bv_sld_columns($db, $table), true);
    }
}

if (!function_exists('bv_sld_clean_string')) {
    function bv_sld_clean_string($value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        return htmlspecialchars_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5);
    }
}

if (!function_exists('bv_sld_int')) {
    function bv_sld_int($value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }
}

if (!function_exists('bv_sld_float')) {
    function bv_sld_float($value, float $default = 0.0): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }
}

if (!function_exists('bv_sld_asset_url')) {
    function bv_sld_asset_url(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $base = rtrim(defined('BV_SITE_URL') ? (string) BV_SITE_URL : 'https://www.bettavaro.com', '/');
        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('bv_sld_column_expr')) {
    function bv_sld_column_expr(string $tableAlias, string $column, string $alias = ''): string
    {
        $expr = $tableAlias . '.`' . str_replace('`', '', $column) . '`';
        if ($alias !== '' && $alias !== $column) {
            $expr .= ' AS `' . str_replace('`', '', $alias) . '`';
        }
        return $expr;
    }
}

try {
    $plainToken = bv_sld_read_bearer();
    if ($plainToken === '') {
        bv_seller_listing_detail_error('token_missing', 'Authorization token is required.', 401);
    }

    $listingId = bv_sld_int($_GET['id'] ?? 0, 0);
    if ($listingId <= 0) {
        bv_seller_listing_detail_error('validation_failed', 'A valid integer listing id is required.', 422);
    }

    $db = bv_seller_listing_detail_db();
    if ($db === null) {
        bv_seller_listing_detail_error('db_unavailable', 'Service temporarily unavailable.', 503);
    }

    $tokenHash = hash('sha256', $plainToken);
    $tokenRow = bv_sld_query_row(
        $db,
        "SELECT mat.id AS token_id,
                mat.revoked_at,
                mat.expires_at,
                u.id AS user_id,
                u.role,
                u.account_status
         FROM mobile_auth_tokens mat
         INNER JOIN users u ON u.id = mat.user_id
         WHERE mat.token_hash = ?
         LIMIT 1",
        [$tokenHash]
    );

    if ($tokenRow === null || !empty($tokenRow['revoked_at'])) {
        bv_seller_listing_detail_error('token_invalid', 'Token is invalid.', 401);
    }

    $expiresAt = strtotime((string) ($tokenRow['expires_at'] ?? ''));
    if ($expiresAt > 0 && $expiresAt <= time()) {
        bv_seller_listing_detail_error('token_expired', 'Token has expired.', 401);
    }

    if ((string) ($tokenRow['account_status'] ?? '') !== 'active') {
        bv_seller_listing_detail_error('account_inactive', 'Account is inactive.', 403);
    }

    bv_sld_execute($db, 'UPDATE mobile_auth_tokens SET last_used_at = NOW() WHERE id = ? LIMIT 1', [(int) $tokenRow['token_id']]);

    $userId = (int) $tokenRow['user_id'];
    $userRole = bv_sld_clean_string($tokenRow['role'] ?? 'user');
    if (!in_array($userRole, ['seller', 'admin'], true)) {
        bv_seller_listing_detail_error('seller_required', 'Seller access is required.', 403);
    }

    if (!bv_sld_table_exists($db, 'listings')) {
        bv_seller_listing_detail_error('listings_table_missing', 'Listings service is unavailable.', 503);
    }

    $listingColumns = bv_sld_columns($db, 'listings');
    $hasListingColumn = static fn(string $column): bool => in_array(strtolower($column), $listingColumns, true);

    if (!$hasListingColumn('id')) {
        bv_seller_listing_detail_error('listings_table_missing', 'Listings service is unavailable.', 503);
    }

    $selectColumns = [bv_sld_column_expr('l', 'id')];
    $listingFieldColumns = [
        'seller_id',
        'title',
        'slug',
        'status',
        'sale_status',
        'species',
        'strain',
        'gender',
        'age',
        'size',
        'price',
        'currency',
        'description',
        'short_description',
        'image_url',
        'image',
        'photo',
        'thumbnail',
        'video_url',
        'stock_quantity',
        'views',
        'favorites',
        'created_at',
        'updated_at',
    ];

    foreach ($listingFieldColumns as $column) {
        if ($hasListingColumn($column) && $column !== 'id') {
            $selectColumns[] = bv_sld_column_expr('l', $column);
        }
    }

    if (!$hasListingColumn('gender') && $hasListingColumn('sex')) {
        $selectColumns[] = bv_sld_column_expr('l', 'sex', 'gender');
    }

    $whereSql = 'WHERE l.`id` = ?';
    $whereParams = [$listingId];
    if ($userRole !== 'admin') {
        if (!$hasListingColumn('seller_id')) {
            bv_seller_listing_detail_error('listing_not_found', 'Listing not found.', 404);
        }
        $whereSql .= ' AND l.`seller_id` = ?';
        $whereParams[] = $userId;
    }

    $listingRow = bv_sld_query_row($db, 'SELECT ' . implode(', ', $selectColumns) . ' FROM listings l ' . $whereSql . ' LIMIT 1', $whereParams);
    if ($listingRow === null) {
        bv_seller_listing_detail_error('listing_not_found', 'Listing not found.', 404);
    }

    $imageUrl = '';
    foreach (['image_url', 'image', 'photo', 'thumbnail'] as $imageColumn) {
        $candidate = bv_sld_clean_string($listingRow[$imageColumn] ?? '');
        if ($candidate !== '') {
            $imageUrl = bv_sld_asset_url($candidate);
            break;
        }
    }

    $listing = [
        'id' => bv_sld_int($listingRow['id'] ?? 0),
        'seller_id' => bv_sld_int($listingRow['seller_id'] ?? 0),
        'title' => bv_sld_clean_string($listingRow['title'] ?? ''),
        'slug' => bv_sld_clean_string($listingRow['slug'] ?? ''),
        'status' => bv_sld_clean_string($listingRow['status'] ?? ''),
        'sale_status' => bv_sld_clean_string($listingRow['sale_status'] ?? ''),
        'species' => bv_sld_clean_string($listingRow['species'] ?? ''),
        'strain' => bv_sld_clean_string($listingRow['strain'] ?? ''),
        'gender' => bv_sld_clean_string($listingRow['gender'] ?? ''),
        'age' => bv_sld_clean_string($listingRow['age'] ?? ''),
        'size' => bv_sld_clean_string($listingRow['size'] ?? ''),
        'price' => bv_sld_float($listingRow['price'] ?? 0),
        'currency' => bv_sld_clean_string($listingRow['currency'] ?? 'USD') ?: 'USD',
        'description' => bv_sld_clean_string($listingRow['description'] ?? ''),
        'short_description' => bv_sld_clean_string($listingRow['short_description'] ?? ''),
        'image_url' => $imageUrl,
        'created_at' => bv_sld_clean_string($listingRow['created_at'] ?? ''),
        'updated_at' => bv_sld_clean_string($listingRow['updated_at'] ?? ''),
    ];

    foreach (['image', 'photo', 'thumbnail', 'video_url'] as $optionalStringField) {
        if (array_key_exists($optionalStringField, $listingRow)) {
            $listing[$optionalStringField] = $optionalStringField === 'video_url'
                ? bv_sld_clean_string($listingRow[$optionalStringField] ?? '')
                : bv_sld_asset_url(bv_sld_clean_string($listingRow[$optionalStringField] ?? ''));
        }
    }

    if (array_key_exists('stock_quantity', $listingRow)) {
        $listing['stock_quantity'] = bv_sld_int($listingRow['stock_quantity'] ?? 0);
    }
    if (array_key_exists('views', $listingRow)) {
        $listing['views'] = bv_sld_int($listingRow['views'] ?? 0);
    }
    if (array_key_exists('favorites', $listingRow)) {
        $listing['favorites'] = bv_sld_int($listingRow['favorites'] ?? 0);
    }

    $media = [];
    if (bv_sld_table_exists($db, 'listing_media')) {
        $mediaColumns = bv_sld_columns($db, 'listing_media');
        $hasMediaColumn = static fn(string $column): bool => in_array(strtolower($column), $mediaColumns, true);

        if ($hasMediaColumn('listing_id')) {
            $mediaSelect = [];
            foreach (['id', 'type', 'media_type', 'url', 'file_url', 'path', 'storage_path', 'thumbnail_url', 'sort_order', 'created_at'] as $column) {
                if ($hasMediaColumn($column)) {
                    $mediaSelect[] = bv_sld_column_expr('lm', $column);
                }
            }

            if (!empty($mediaSelect)) {
                $orderParts = [];
                $orderParts[] = $hasMediaColumn('sort_order') ? 'lm.`sort_order` ASC' : '0 ASC';
                $orderParts[] = $hasMediaColumn('id') ? 'lm.`id` ASC' : 'lm.`listing_id` ASC';
                $mediaRows = bv_sld_query_rows(
                    $db,
                    'SELECT ' . implode(', ', $mediaSelect) . ' FROM listing_media lm WHERE lm.`listing_id` = ? ORDER BY ' . implode(', ', $orderParts),
                    [$listingId]
                );

                foreach ($mediaRows as $mediaRow) {
                    $mediaType = bv_sld_clean_string($mediaRow['type'] ?? ($mediaRow['media_type'] ?? ''));
                    $mediaUrl = '';
                    foreach (['url', 'file_url', 'path', 'storage_path'] as $urlColumn) {
                        $candidate = bv_sld_clean_string($mediaRow[$urlColumn] ?? '');
                        if ($candidate !== '') {
                            $mediaUrl = bv_sld_asset_url($candidate);
                            break;
                        }
                    }

                    $mediaItem = [];
                    if (array_key_exists('id', $mediaRow)) {
                        $mediaItem['id'] = bv_sld_int($mediaRow['id'] ?? 0);
                    }
                    $mediaItem['type'] = $mediaType;
                    $mediaItem['url'] = $mediaUrl;
                    if (array_key_exists('thumbnail_url', $mediaRow)) {
                        $mediaItem['thumbnail_url'] = bv_sld_asset_url(bv_sld_clean_string($mediaRow['thumbnail_url'] ?? ''));
                    }
                    if (array_key_exists('sort_order', $mediaRow)) {
                        $mediaItem['sort_order'] = bv_sld_int($mediaRow['sort_order'] ?? 0);
                    }
                    if (array_key_exists('created_at', $mediaRow)) {
                        $mediaItem['created_at'] = bv_sld_clean_string($mediaRow['created_at'] ?? '');
                    }
                    $media[] = $mediaItem;
                }
            }
        }
    }

    $stats = [
        'views' => array_key_exists('views', $listingRow) ? bv_sld_int($listingRow['views'] ?? 0) : 0,
        'favorites' => array_key_exists('favorites', $listingRow) ? bv_sld_int($listingRow['favorites'] ?? 0) : 0,
        'orders' => 0,
        'offers' => 0,
        'reviews' => 0,
        'avg_rating' => 0,
    ];

    if (!array_key_exists('favorites', $listingRow) && bv_sld_table_exists($db, 'listing_favorites')) {
        $favoriteColumns = bv_sld_columns($db, 'listing_favorites');
        if (in_array('listing_id', $favoriteColumns, true)) {
            $stats['favorites'] = bv_sld_int(bv_sld_query_scalar($db, 'SELECT COUNT(*) FROM listing_favorites WHERE `listing_id` = ?', [$listingId], 0));
        }
    }

    if (bv_sld_table_exists($db, 'order_items')) {
        $orderItemColumns = bv_sld_columns($db, 'order_items');
        if (in_array('listing_id', $orderItemColumns, true)) {
            $orderWhere = ['`listing_id` = ?'];
            $orderParams = [$listingId];
            if (in_array('seller_id', $orderItemColumns, true)) {
                $orderWhere[] = '`seller_id` = ?';
                $orderParams[] = bv_sld_int($listingRow['seller_id'] ?? $userId);
            }
            $stats['orders'] = bv_sld_int(bv_sld_query_scalar($db, 'SELECT COUNT(*) FROM order_items WHERE ' . implode(' AND ', $orderWhere), $orderParams, 0));
        }
    }

    if (bv_sld_table_exists($db, 'listing_offers')) {
        $offerColumns = bv_sld_columns($db, 'listing_offers');
        if (in_array('listing_id', $offerColumns, true)) {
            $stats['offers'] = bv_sld_int(bv_sld_query_scalar($db, 'SELECT COUNT(*) FROM listing_offers WHERE `listing_id` = ?', [$listingId], 0));
        }
    }

    if (bv_sld_table_exists($db, 'listing_reviews')) {
        $reviewColumns = bv_sld_columns($db, 'listing_reviews');
        if (in_array('listing_id', $reviewColumns, true)) {
            $reviewWhere = ['`listing_id` = ?'];
            $reviewParams = [$listingId];
            if (in_array('status', $reviewColumns, true)) {
                $reviewWhere[] = '`status` = ?';
                $reviewParams[] = 'approved';
            } elseif (in_array('approved', $reviewColumns, true)) {
                $reviewWhere[] = '`approved` = ?';
                $reviewParams[] = 1;
            }

            $stats['reviews'] = bv_sld_int(bv_sld_query_scalar($db, 'SELECT COUNT(*) FROM listing_reviews WHERE ' . implode(' AND ', $reviewWhere), $reviewParams, 0));
            if (in_array('rating', $reviewColumns, true)) {
                $stats['avg_rating'] = round(bv_sld_float(bv_sld_query_scalar($db, 'SELECT AVG(`rating`) FROM listing_reviews WHERE ' . implode(' AND ', $reviewWhere), $reviewParams, 0)), 2);
            }
        }
    }

    bv_seller_listing_detail_json(200, [
        'ok' => true,
        'data' => [
            'listing' => $listing,
            'media' => $media,
            'stats' => $stats,
        ],
        'meta' => bv_seller_listing_detail_meta(),
    ]);
} catch (Throwable $e) {
    bv_seller_listing_detail_log('server error: ' . $e->getMessage());
    bv_seller_listing_detail_error('server_error', 'An unexpected server error occurred.', 500);
}
