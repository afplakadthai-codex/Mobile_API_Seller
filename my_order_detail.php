<?php
declare(strict_types=1);

while (ob_get_level() > 0) {
    @ob_end_clean();
}

@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
error_reporting(0);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

const MOBILE_API_VERSION = 'mobile-v1';

class MobileClientSafeException extends RuntimeException
{
    private int $statusCode;
    private string $errorCode;

    public function __construct(string $message, int $statusCode = 400, string $errorCode = 'bad_request')
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
        $this->errorCode = $errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

function mobile_now(): string
{
    return date('Y-m-d H:i:s');
}

function mobile_json($statusCode, $payload): void
{
    if (!is_array($payload)) {
        $payload = [];
    }

    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
        $payload['meta'] = [];
    }

    $payload['meta']['api_version'] = MOBILE_API_VERSION;
    $payload['meta']['generated_at'] = mobile_now();

    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

    http_response_code((int) $statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function mobile_error(string $code, string $message, int $statusCode): void
{
    mobile_json($statusCode, [
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ]);
}

function mobile_log(string $message): void
{
    error_log('[Mobile API Order Detail] ' . $message);
}

function mobile_db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $publicRoot = dirname(__DIR__, 3);
    $candidates = [
        $publicRoot . '/config/db.php',
        $publicRoot . '/includes/db.php',
        $publicRoot . '/db.php',
    ];

    foreach ($candidates as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        try {
            $loaded = (static function (string $includePath): array {
                $pdo = $PDO = $db = $conn = $database = $connection = null;
                $mysqli = $link = null;
                $dsn = null;
                $db_host = $db_user = $db_pass = $db_name = $db_port = null;
                $host = $user = $pass = $name = $port = null;
                $DB_HOST = $DB_USER = $DB_PASS = $DB_NAME = $DB_PORT = null;

                ob_start();
                /** @noinspection PhpIncludeInspection */
                include $includePath;
                @ob_end_clean();

                return compact(
                    'pdo',
                    'PDO',
                    'db',
                    'conn',
                    'database',
                    'connection',
                    'mysqli',
                    'link',
                    'dsn',
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
                    'DB_PORT'
                );
            })($path);
        } catch (Throwable $e) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            mobile_log('DB bootstrap failed for ' . $path . ': ' . $e->getMessage());
            continue;
        }

        foreach (['pdo', 'PDO', 'db', 'conn', 'database', 'connection'] as $name) {
            if (($loaded[$name] ?? null) instanceof PDO) {
                $pdo = $loaded[$name];
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                return $pdo;
            }
        }

        foreach (['pdo', 'PDO', 'db', 'conn', 'database', 'connection'] as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
                $pdo = $GLOBALS[$name];
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                return $pdo;
            }
        }

        foreach (['mysqli', 'link'] as $name) {
            if (($loaded[$name] ?? null) instanceof mysqli || (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof mysqli)) {
                throw new MobileClientSafeException(
                    'This mobile endpoint requires a PDO database connection; mysqli-only bootstraps are not supported.',
                    500,
                    'server_error'
                );
            }
        }

        $host = $loaded['db_host'] ?? $loaded['host'] ?? $loaded['DB_HOST'] ?? null;
        $user = $loaded['db_user'] ?? $loaded['user'] ?? $loaded['DB_USER'] ?? null;
        $pass = $loaded['db_pass'] ?? $loaded['pass'] ?? $loaded['DB_PASS'] ?? '';
        $name = $loaded['db_name'] ?? $loaded['name'] ?? $loaded['DB_NAME'] ?? null;
        $port = (int) ($loaded['db_port'] ?? $loaded['port'] ?? $loaded['DB_PORT'] ?? 3306);
        $dsn = $loaded['dsn'] ?? null;

        if (is_string($dsn) && $dsn !== '' && $user !== null) {
            $pdo = new PDO($dsn, (string) $user, (string) $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            return $pdo;
        }

        if ($host !== null && $user !== null && $name !== null) {
            $pdo = new PDO(
                'mysql:host=' . (string) $host . ';port=' . ($port ?: 3306) . ';dbname=' . (string) $name . ';charset=utf8mb4',
                (string) $user,
                (string) $pass,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            return $pdo;
        }
    }

    throw new RuntimeException('PDO database connection could not be loaded.');
}

function mobile_table_exists(PDO $pdo, string $table): bool
{
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute([':table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        mobile_log('Table detection failed for ' . $table . ': ' . $e->getMessage());
        return false;
    }
}

function mobile_columns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
        return [];
    }

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute([':table' => $table]);
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $columns[strtolower((string) $column)] = (string) $column;
        }
        $cache[$table] = $columns;
        return $columns;
    } catch (Throwable $e) {
        mobile_log('Column detection failed for ' . $table . ': ' . $e->getMessage());
        $cache[$table] = [];
        return [];
    }
}

function mobile_has_col(PDO $pdo, string $table, string $column): bool
{
    $columns = mobile_columns($pdo, $table);
    return isset($columns[strtolower($column)]);
}

function mobile_actual_col(PDO $pdo, string $table, array $candidates): ?string
{
    $columns = mobile_columns($pdo, $table);
    foreach ($candidates as $candidate) {
        $key = strtolower((string) $candidate);
        if (isset($columns[$key])) {
            return $columns[$key];
        }
    }
    return null;
}

function mobile_sql_col(PDO $pdo, string $table, array $candidates, string $alias, string $prefix): string
{
    $column = mobile_actual_col($pdo, $table, $candidates);
    if ($column === null) {
        return 'NULL AS `' . str_replace('`', '', $alias) . '`';
    }

    return $prefix . '.`' . str_replace('`', '``', $column) . '` AS `' . str_replace('`', '', $alias) . '`';
}

function mobile_bearer_token(): string
{
    $authorization = '';

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $authorization = trim((string) $value);
                    break;
                }
            }
        }
    }

    if ($authorization === '') {
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    }

    if ($authorization !== '' && preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) === 1) {
        return trim((string) $matches[1]);
    }

    return '';
}

function mobile_auth_user(PDO $pdo): array
{
    $token = mobile_bearer_token();
    if ($token === '') {
        mobile_error('unauthorized', 'Bearer token is required.', 401);
    }

    if (!mobile_table_exists($pdo, 'mobile_auth_tokens')) {
        mobile_error('server_error', 'Mobile authentication is not configured.', 500);
    }

    foreach (['token_hash', 'user_id', 'expires_at'] as $required) {
        if (!mobile_has_col($pdo, 'mobile_auth_tokens', $required)) {
            mobile_error('server_error', 'Mobile authentication schema is incomplete.', 500);
        }
    }

    $where = ['mat.`token_hash` = :token_hash', 'mat.`expires_at` > NOW()'];
    if (mobile_has_col($pdo, 'mobile_auth_tokens', 'revoked_at')) {
        $where[] = 'mat.`revoked_at` IS NULL';
    }

    $select = ['mat.`user_id` AS user_id'];
    $join = '';
    if (mobile_table_exists($pdo, 'users') && mobile_has_col($pdo, 'users', 'id')) {
        $join = ' LEFT JOIN users u ON u.`id` = mat.`user_id`';
        $select[] = mobile_sql_col($pdo, 'users', ['email'], 'email', 'u');
        $select[] = mobile_sql_col($pdo, 'users', ['name', 'full_name', 'display_name', 'username'], 'name', 'u');
    } else {
        $select[] = 'NULL AS `email`';
        $select[] = 'NULL AS `name`';
    }

    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM mobile_auth_tokens mat' . $join . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY mat.`expires_at` DESC LIMIT 1');
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $user = $stmt->fetch();

    if (!$user || (int) ($user['user_id'] ?? 0) <= 0) {
        mobile_error('unauthorized', 'Invalid or expired bearer token.', 401);
    }

    return $user;
}

function mobile_money_number($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }

    if (is_numeric($value)) {
        return round((float) $value, 2);
    }

    $clean = preg_replace('/[^0-9.\-]/', '', (string) $value);
    if ($clean === '' || !is_numeric($clean)) {
        return 0.0;
    }

    return round((float) $clean, 2);
}

function mobile_resolve_asset_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path) === 1 || str_starts_with($path, '//')) {
        return $path;
    }

    $path = str_replace('\\', '/', $path);
    if (str_starts_with($path, '/')) {
        return $path;
    }

    return '/' . ltrim($path, '/');
}

function mobile_order_shipping_summary(array $items, string $orderStatus = '', string $paymentStatus = ''): array
{
    $orderStatus = strtolower(trim($orderStatus));
    $paymentStatus = strtolower(trim($paymentStatus));

    if ($orderStatus === 'pending_payment' || in_array($paymentStatus, ['pending_payment', 'unpaid', 'pending'], true)) {
        return [
            'key' => 'awaiting_payment',
            'label' => 'Awaiting Payment',
            'message' => 'Waiting for payment confirmation before shipping.',
            'tracking_numbers' => [],
        ];
    }
	
    if ($items === []) {
        return [
            'key' => 'unknown',
            'label' => 'Unknown',
            'message' => 'No fulfillment information is available for this order.',
            'tracking_numbers' => [],
        ];
    }

    $statuses = [];
    $tracking = [];
    $seenTracking = [];

    foreach ($items as $item) {
        $status = strtolower(trim((string) ($item['fulfillment_status'] ?? 'pending')));
        if ($status === '') {
            $status = 'pending';
        }
        $statuses[] = $status;

        $trackingNumber = trim((string) ($item['tracking_number'] ?? ''));
        if ($trackingNumber !== '') {
            $trackingKey = strtolower(trim((string) ($item['carrier'] ?? ''))) . '|' . strtolower($trackingNumber);
            if (!isset($seenTracking[$trackingKey])) {
                $seenTracking[$trackingKey] = true;
                $tracking[] = [
                    'carrier' => trim((string) ($item['carrier'] ?? '')),
                    'tracking_number' => $trackingNumber,
                    'item_title' => trim((string) ($item['title'] ?? '')),
                ];
            }
        }
    }

    $count = count($statuses);
    $completed = count(array_filter($statuses, static fn (string $status): bool => $status === 'completed'));
    $shippedOrCompleted = count(array_filter($statuses, static fn (string $status): bool => in_array($status, ['shipped', 'completed'], true)));
    $pending = count(array_filter($statuses, static fn (string $status): bool => $status === 'pending'));
    $hasProcessing = in_array('processing', $statuses, true);
    $hasFulfilled = $shippedOrCompleted > 0;
    $hasUnfulfilled = count(array_filter($statuses, static fn (string $status): bool => in_array($status, ['pending', 'processing'], true))) > 0;

    if ($completed === $count) {
        $key = 'completed';
        $label = 'Delivered';
        $message = 'All items in this order have been delivered.';
    } elseif ($shippedOrCompleted === $count) {
        $key = 'shipped';
        $label = 'Shipped';
        $message = 'All items in this order have shipped.';
    } elseif ($hasFulfilled && $hasUnfulfilled) {
        $key = 'mixed';
        $label = 'Partially Fulfilled';
        $message = 'Some items have shipped or been delivered while others are still being prepared.';
    } elseif ($hasProcessing) {
        $key = 'processing';
        $label = 'Preparing';
        $message = 'Your order is being prepared for shipment.';
    } elseif ($pending === $count) {
        $key = 'pending';
        $label = 'To Ship';
        $message = 'Your order is waiting to be shipped.';
    } else {
        $key = 'unknown';
        $label = 'Unknown';
        $message = 'Fulfillment status is not available for this order.';
    }

    return [
        'key' => $key,
        'label' => $label,
        'message' => $message,
        'tracking_numbers' => $tracking,
    ];
}

function mobile_normalize_status(?string $status): string
{
    $status = strtolower(trim((string) $status));
    if ($status === '') {
        return 'pending';
    }

    if (in_array($status, ['pending', 'processing', 'shipped', 'completed', 'cancelled'], true)) {
        return $status;
    }

    if (in_array($status, ['delivered', 'fulfilled'], true)) {
        return 'completed';
    }

    return $status;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        mobile_error('bad_request', 'Only GET requests are accepted.', 400);
    }

    $rawOrderId = $_GET['id'] ?? ($_GET['order_id'] ?? null);
    if (!is_scalar($rawOrderId) || preg_match('/^[1-9][0-9]*$/', (string) $rawOrderId) !== 1) {
        mobile_error('bad_request', 'A valid order id is required.', 400);
    }
    $orderId = (int) $rawOrderId;

    $pdo = mobile_db();
    $authUser = mobile_auth_user($pdo);
    $userId = (int) $authUser['user_id'];

    if (!mobile_table_exists($pdo, 'orders') || !mobile_has_col($pdo, 'orders', 'id')) {
        mobile_error('server_error', 'Orders are not configured.', 500);
    }

    $buyerColumns = [];
    foreach (['user_id', 'buyer_user_id', 'customer_id'] as $candidate) {
        if (mobile_has_col($pdo, 'orders', $candidate)) {
            $buyerColumns[] = mobile_actual_col($pdo, 'orders', [$candidate]);
        }
    }

    if ($buyerColumns === []) {
        mobile_error('forbidden', 'Order ownership cannot be verified.', 403);
    }

    $orderSelect = [
        'o.`id` AS `id`',
        mobile_sql_col($pdo, 'orders', ['order_code', 'order_number', 'code'], 'order_code', 'o'),
        mobile_sql_col($pdo, 'orders', ['status', 'order_status'], 'status', 'o'),
        mobile_sql_col($pdo, 'orders', ['payment_status', 'paid_status'], 'payment_status', 'o'),
        mobile_sql_col($pdo, 'orders', ['currency'], 'currency', 'o'),
        mobile_sql_col($pdo, 'orders', ['subtotal', 'subtotal_amount', 'items_total'], 'subtotal', 'o'),
        mobile_sql_col($pdo, 'orders', ['shipping_amount', 'shipping_total', 'shipping_fee'], 'shipping_amount', 'o'),
        mobile_sql_col($pdo, 'orders', ['discount_amount', 'discount_total'], 'discount_amount', 'o'),
        mobile_sql_col($pdo, 'orders', ['total', 'total_amount', 'grand_total'], 'total', 'o'),
        mobile_sql_col($pdo, 'orders', ['created_at'], 'created_at', 'o'),
        mobile_sql_col($pdo, 'orders', ['paid_at'], 'paid_at', 'o'),
        mobile_sql_col($pdo, 'orders', ['updated_at'], 'updated_at', 'o'),
    ];

    $ownershipWhere = [];
    foreach ($buyerColumns as $index => $column) {
        $ownershipWhere[] = 'o.`' . str_replace('`', '``', (string) $column) . '` = :buyer_user_id_' . $index;
    }

    $orderSql = 'SELECT ' . implode(', ', $orderSelect) . ' FROM orders o WHERE o.`id` = :order_id AND (' . implode(' OR ', $ownershipWhere) . ') LIMIT 1';
    $orderParams = [':order_id' => $orderId];
    foreach (array_keys($buyerColumns) as $index) {
        $orderParams[':buyer_user_id_' . $index] = $userId;
    }

    $stmt = $pdo->prepare($orderSql);
    $stmt->execute($orderParams);
    $orderRow = $stmt->fetch();

    if (!$orderRow) {
        mobile_error('not_found', 'Order was not found.', 404);
    }

    $currency = trim((string) ($orderRow['currency'] ?? ''));
    if ($currency === '') {
        $currency = 'USD';
    }

    $items = [];
    if (mobile_table_exists($pdo, 'order_items') && mobile_has_col($pdo, 'order_items', 'order_id')) {
        $itemSelect = [
            mobile_sql_col($pdo, 'order_items', ['id'], 'order_item_id', 'oi'),
            mobile_sql_col($pdo, 'order_items', ['listing_id'], 'listing_id', 'oi'),
            mobile_sql_col($pdo, 'order_items', ['title', 'name', 'item_name', 'product_name', 'listing_title'], 'item_title', 'oi'),
            mobile_sql_col($pdo, 'order_items', ['qty', 'quantity'], 'qty', 'oi'),
            mobile_sql_col($pdo, 'order_items', ['unit_price', 'price'], 'unit_price', 'oi'),
            mobile_sql_col($pdo, 'order_items', ['line_total', 'subtotal', 'total'], 'line_total', 'oi'),
            mobile_sql_col($pdo, 'order_items', ['currency'], 'item_currency', 'oi'),
            mobile_sql_col($pdo, 'order_items', ['fulfillment_status', 'shipping_status', 'status'], 'fulfillment_status', 'oi'),
            mobile_sql_col($pdo, 'order_items', ['carrier', 'shipping_carrier'], 'carrier', 'oi'),
            mobile_sql_col($pdo, 'order_items', ['tracking_number', 'tracking_code'], 'tracking_number', 'oi'),
            mobile_sql_col($pdo, 'order_items', ['processed_at'], 'processed_at', 'oi'),
            mobile_sql_col($pdo, 'order_items', ['shipped_at'], 'shipped_at', 'oi'),
            mobile_sql_col($pdo, 'order_items', ['completed_at', 'delivered_at'], 'completed_at', 'oi'),
            mobile_sql_col($pdo, 'order_items', ['image_url', 'image_path', 'photo_url', 'thumbnail_url'], 'item_image_url', 'oi'),
        ];

        $joins = [];
        $hasListingsJoin = mobile_table_exists($pdo, 'listings') && mobile_has_col($pdo, 'listings', 'id') && mobile_has_col($pdo, 'order_items', 'listing_id');
        if ($hasListingsJoin) {
            $joins[] = ' LEFT JOIN listings l ON oi.`' . str_replace('`', '``', (string) mobile_actual_col($pdo, 'order_items', ['listing_id'])) . '` = l.`id`';
            $itemSelect[] = mobile_sql_col($pdo, 'listings', ['title', 'name', 'product_name'], 'listing_title', 'l');
            $itemSelect[] = mobile_sql_col($pdo, 'listings', ['image_url', 'image_path', 'photo_url', 'thumbnail_url', 'main_image'], 'listing_image_url', 'l');
            $itemSelect[] = mobile_sql_col($pdo, 'listings', ['seller_id', 'user_id'], 'seller_id_from_listing', 'l');
        } else {
            $itemSelect[] = 'NULL AS `listing_title`';
            $itemSelect[] = 'NULL AS `listing_image_url`';
            $itemSelect[] = 'NULL AS `seller_id_from_listing`';
        }

        $sellerJoinColumn = $hasListingsJoin ? mobile_actual_col($pdo, 'listings', ['seller_id', 'user_id']) : null;
        $hasUsersJoin = $sellerJoinColumn !== null && mobile_table_exists($pdo, 'users') && mobile_has_col($pdo, 'users', 'id');
        if ($hasUsersJoin) {
            $joins[] = ' LEFT JOIN users u ON l.`' . str_replace('`', '``', $sellerJoinColumn) . '` = u.`id`';
            $itemSelect[] = 'u.`id` AS `seller_user_id`';
            $itemSelect[] = mobile_sql_col($pdo, 'users', ['name', 'full_name', 'display_name', 'username'], 'seller_name', 'u');
        } else {
            $itemSelect[] = 'NULL AS `seller_user_id`';
            $itemSelect[] = 'NULL AS `seller_name`';
        }

        $hasSellerApplicationsJoin = false;
        if ($hasUsersJoin && mobile_table_exists($pdo, 'seller_applications')) {
            $sellerAppUserColumn = mobile_actual_col($pdo, 'seller_applications', ['user_id', 'seller_id']);
            if ($sellerAppUserColumn !== null) {
                $hasSellerApplicationsJoin = true;
                $joins[] = ' LEFT JOIN seller_applications sa ON sa.`' . str_replace('`', '``', $sellerAppUserColumn) . '` = u.`id`';
                $itemSelect[] = mobile_sql_col($pdo, 'seller_applications', ['farm_name', 'business_name', 'seller_name'], 'farm_name', 'sa');
                $itemSelect[] = mobile_sql_col($pdo, 'seller_applications', ['farm_logo_path', 'farm_logo_url', 'logo_path', 'logo_url'], 'farm_logo_path', 'sa');
            }
        }

        if (!$hasSellerApplicationsJoin) {
            $itemSelect[] = 'NULL AS `farm_name`';
            $itemSelect[] = 'NULL AS `farm_logo_path`';
        }

        $orderItemOrder = mobile_has_col($pdo, 'order_items', 'id') ? ' ORDER BY oi.`id` ASC' : '';
        $itemSql = 'SELECT ' . implode(', ', $itemSelect) . ' FROM order_items oi' . implode('', $joins) . ' WHERE oi.`' . str_replace('`', '``', (string) mobile_actual_col($pdo, 'order_items', ['order_id'])) . '` = :order_id' . $orderItemOrder;
        $stmt = $pdo->prepare($itemSql);
        $stmt->execute([':order_id' => $orderId]);

        foreach ($stmt->fetchAll() as $row) {
            $title = trim((string) ($row['item_title'] ?? ''));
            if ($title === '') {
                $title = trim((string) ($row['listing_title'] ?? ''));
            }

            $image = trim((string) ($row['item_image_url'] ?? ''));
            if ($image === '') {
                $image = trim((string) ($row['listing_image_url'] ?? ''));
            }

            $itemCurrency = trim((string) ($row['item_currency'] ?? ''));
            if ($itemCurrency === '') {
                $itemCurrency = $currency;
            }

            $qty = $row['qty'] ?? 0;
            $qtyNumber = is_numeric($qty) ? (float) $qty : 0.0;
            $qtyValue = floor($qtyNumber) === $qtyNumber ? (int) $qtyNumber : $qtyNumber;

            $items[] = [
                'order_item_id' => (int) ($row['order_item_id'] ?? 0),
                'listing_id' => (int) ($row['listing_id'] ?? 0),
                'title' => $title,
                'image_url' => mobile_resolve_asset_url($image),
                'qty' => $qtyValue,
                'unit_price' => mobile_money_number($row['unit_price'] ?? 0),
                'line_total' => mobile_money_number($row['line_total'] ?? 0),
                'currency' => $itemCurrency,
                'fulfillment_status' => mobile_normalize_status($row['fulfillment_status'] ?? null),
                'carrier' => trim((string) ($row['carrier'] ?? '')),
                'tracking_number' => trim((string) ($row['tracking_number'] ?? '')),
                'processed_at' => $row['processed_at'] !== null ? (string) $row['processed_at'] : '',
                'shipped_at' => $row['shipped_at'] !== null ? (string) $row['shipped_at'] : '',
                'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : '',
                'seller' => [
                    'id' => (int) ($row['seller_user_id'] ?? ($row['seller_id_from_listing'] ?? 0)),
                    'name' => trim((string) ($row['seller_name'] ?? '')),
                    'farm_name' => trim((string) ($row['farm_name'] ?? '')),
                    'farm_logo_url' => mobile_resolve_asset_url($row['farm_logo_path'] !== null ? (string) $row['farm_logo_path'] : ''),
                ],
            ];
        }
    }

    $refunds = [
        'has_refund' => false,
        'latest_status' => '',
        'requested_amount' => 0.0,
        'approved_amount' => 0.0,
        'actual_refund_amount' => 0.0,
        'records' => [],
    ];

    if (mobile_table_exists($pdo, 'order_refunds') && mobile_has_col($pdo, 'order_refunds', 'order_id')) {
        $refundSelect = [
            mobile_sql_col($pdo, 'order_refunds', ['id'], 'id', 'r'),
            mobile_sql_col($pdo, 'order_refunds', ['refund_code', 'code'], 'refund_code', 'r'),
            mobile_sql_col($pdo, 'order_refunds', ['status'], 'status', 'r'),
            mobile_sql_col($pdo, 'order_refunds', ['requested_refund_amount', 'requested_amount'], 'requested_refund_amount', 'r'),
            mobile_sql_col($pdo, 'order_refunds', ['approved_refund_amount', 'approved_amount'], 'approved_refund_amount', 'r'),
            mobile_sql_col($pdo, 'order_refunds', ['actual_refunded_amount', 'actual_refund_amount', 'refunded_amount'], 'actual_refunded_amount', 'r'),
            mobile_sql_col($pdo, 'order_refunds', ['created_at'], 'created_at', 'r'),
            mobile_sql_col($pdo, 'order_refunds', ['updated_at'], 'updated_at', 'r'),
        ];

        $refundOrder = [];
        if (mobile_has_col($pdo, 'order_refunds', 'created_at')) {
            $refundOrder[] = 'r.`' . str_replace('`', '``', (string) mobile_actual_col($pdo, 'order_refunds', ['created_at'])) . '` DESC';
        }
        if (mobile_has_col($pdo, 'order_refunds', 'id')) {
            $refundOrder[] = 'r.`id` DESC';
        }

        $refundSql = 'SELECT ' . implode(', ', $refundSelect) . ' FROM order_refunds r WHERE r.`' . str_replace('`', '``', (string) mobile_actual_col($pdo, 'order_refunds', ['order_id'])) . '` = :order_id';
        if ($refundOrder !== []) {
            $refundSql .= ' ORDER BY ' . implode(', ', $refundOrder);
        }

        $stmt = $pdo->prepare($refundSql);
        $stmt->execute([':order_id' => $orderId]);
        $refundRows = $stmt->fetchAll();

        foreach ($refundRows as $index => $refundRow) {
            $requested = mobile_money_number($refundRow['requested_refund_amount'] ?? 0);
            $approved = mobile_money_number($refundRow['approved_refund_amount'] ?? 0);
            $actual = mobile_money_number($refundRow['actual_refunded_amount'] ?? 0);

            if ($index === 0) {
                $refunds['latest_status'] = trim((string) ($refundRow['status'] ?? ''));
            }

            $refunds['requested_amount'] += $requested;
            $refunds['approved_amount'] += $approved;
            $refunds['actual_refund_amount'] += $actual;
            $refunds['records'][] = [
                'id' => (int) ($refundRow['id'] ?? 0),
                'refund_code' => trim((string) ($refundRow['refund_code'] ?? '')),
                'status' => trim((string) ($refundRow['status'] ?? '')),
                'requested_refund_amount' => $requested,
                'approved_refund_amount' => $approved,
                'actual_refunded_amount' => $actual,
                'created_at' => $refundRow['created_at'] !== null ? (string) $refundRow['created_at'] : '',
                'updated_at' => $refundRow['updated_at'] !== null ? (string) $refundRow['updated_at'] : '',
            ];
        }

        $refunds['has_refund'] = $refunds['records'] !== [];
        $refunds['requested_amount'] = mobile_money_number($refunds['requested_amount']);
        $refunds['approved_amount'] = mobile_money_number($refunds['approved_amount']);
        $refunds['actual_refund_amount'] = mobile_money_number($refunds['actual_refund_amount']);
    }

    mobile_json(200, [
        'ok' => true,
        'data' => [
            'order' => [
                'id' => (int) $orderRow['id'],
                'order_code' => trim((string) ($orderRow['order_code'] ?? '')),
                'status' => trim((string) ($orderRow['status'] ?? '')),
                'payment_status' => trim((string) ($orderRow['payment_status'] ?? '')),
                'currency' => $currency,
                'subtotal' => mobile_money_number($orderRow['subtotal'] ?? 0),
                'shipping_amount' => mobile_money_number($orderRow['shipping_amount'] ?? 0),
                'discount_amount' => mobile_money_number($orderRow['discount_amount'] ?? 0),
                'total' => mobile_money_number($orderRow['total'] ?? 0),
                'created_at' => $orderRow['created_at'] !== null ? (string) $orderRow['created_at'] : '',
                'paid_at' => $orderRow['paid_at'] !== null ? (string) $orderRow['paid_at'] : '',
                'updated_at' => $orderRow['updated_at'] !== null ? (string) $orderRow['updated_at'] : '',
            ],
            'shipping' => mobile_order_shipping_summary($items, trim((string) ($orderRow['status'] ?? '')), trim((string) ($orderRow['payment_status'] ?? ''))),
            'items' => $items,
            'refunds' => $refunds,
        ],
    ]);
} catch (MobileClientSafeException $e) {
    mobile_log($e->getMessage());
    mobile_error($e->errorCode(), $e->getMessage(), $e->statusCode());
} catch (Throwable $e) {
    mobile_log($e->getMessage());
    mobile_error('server_error', 'Unable to load order detail at this time.', 500);
}
