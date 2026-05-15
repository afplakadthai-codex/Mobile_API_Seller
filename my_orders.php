<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

function mobile_now(): string
{
    return date('Y-m-d H:i:s');
}

function mobile_json($statusCode, $payload): void
{
    http_response_code((int) $statusCode);
    if (!is_array($payload)) {
        $payload = ['ok' => false, 'error' => ['code' => 'server_error', 'message' => 'Invalid response payload.']];
    }
    if (!isset($payload['meta']) || !is_array($payload['meta'])) {
        $payload['meta'] = [];
    }
    $payload['meta']['api_version'] = 'mobile-v1';
    $payload['meta']['generated_at'] = mobile_now();
	
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }

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
        'meta' => [],
    ]);
}

function mobile_log(string $message): void
{
    $message = preg_replace('/[^\P{C}\t\r\n]+/u', '', $message) ?? $message;
    $message = preg_replace('/\s+/', ' ', $message) ?? $message;
    $message = trim($message);
    if (strlen($message) > 500) {
        $message = substr($message, 0, 500);
    }
    error_log('[mobile_my_orders] ' . $message);
}

function mobile_identifier(string $identifier): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $identifier)) {
        throw new RuntimeException('Invalid database identifier.');
    }
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function mobile_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $root = dirname(__DIR__, 3);
    $candidates = [
        $root . '/config/db.php',
        $root . '/includes/db.php',
        $root . '/db.php',
    ];

    foreach ($candidates as $file) {
        if (!is_file($file)) {
            continue;
        }

        $beforeKeys = array_keys($GLOBALS);
        ob_start();
        $result = require $file;
        ob_end_clean();

        if ($result instanceof PDO) {
            $pdo = $result;
            break;
        }

        foreach (['pdo', 'db', 'conn', 'dbh', 'database'] as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
                $pdo = $GLOBALS[$name];
                break 2;
            }
        }

        $afterKeys = array_diff(array_keys($GLOBALS), $beforeKeys);
        foreach ($afterKeys as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
                $pdo = $GLOBALS[$name];
                break 2;
            }
        }
    }

    if (!$pdo instanceof PDO) {
        mobile_error('server_error', 'Database connection is not configured for mobile API.', 500);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    return $pdo;
}

function mobile_table_exists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1');
        $stmt->execute([':table' => $table]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare('SELECT 1 FROM ' . mobile_identifier($table) . ' LIMIT 1');
            $stmt->execute();
            return true;
        } catch (Throwable $ignored) {
            return false;
        }
    }
}

function mobile_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return [];
    }

    try {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table');
        $stmt->execute([':table' => $table]);
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $cache[$table] = array_values(array_map('strval', $columns ?: []));
        return $cache[$table];
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare('DESCRIBE ' . mobile_identifier($table));
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $cache[$table] = array_values(array_map(static fn(array $row): string => (string) ($row['Field'] ?? ''), $rows));
            return $cache[$table];
        } catch (Throwable $ignored) {
            return [];
        }
    }
}

function mobile_has_col(PDO $pdo, string $table, string $column): bool
{
    return in_array($column, mobile_columns($pdo, $table), true);
}

function mobile_actual_col(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (is_string($candidate) && mobile_has_col($pdo, $table, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function mobile_bearer_token(): string
{
   $header = '';

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    if ($header === '') {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }

    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    if (!preg_match('/^Bearer\s+(.+)$/i', trim((string) $header), $matches)) {
        return '';
    }

    return trim($matches[1]);
}

function mobile_auth_user(PDO $pdo): array
{
    $token = mobile_bearer_token();
    if ($token === '') {
        mobile_error('unauthorized', 'Missing bearer token.', 401);
    }

    if (!mobile_table_exists($pdo, 'mobile_auth_tokens')) {
        mobile_error('server_error', 'Mobile authentication is not configured.', 500);
    }

    foreach (['token_hash', 'user_id', 'expires_at'] as $required) {
        if (!mobile_has_col($pdo, 'mobile_auth_tokens', $required)) {
            mobile_error('server_error', 'Mobile authentication is not configured.', 500);
        }
    }

    $where = 'token_hash = :token_hash AND expires_at > :now';
    if (mobile_has_col($pdo, 'mobile_auth_tokens', 'revoked_at')) {
        $where .= ' AND revoked_at IS NULL';
    }

    $stmt = $pdo->prepare('SELECT user_id FROM mobile_auth_tokens WHERE ' . $where . ' LIMIT 1');
    $stmt->execute([
        ':token_hash' => hash('sha256', $token),
        ':now' => mobile_now(),
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || empty($row['user_id'])) {
        mobile_error('unauthorized', 'Invalid or expired bearer token.', 401);
    }

    return ['id' => (int) $row['user_id']];
}

function mobile_money_number($value): float
{
    if ($value === null || $value === '') {
        return 0.0;
    }
    return round((float) $value, 2);
}

function mobile_normalize_status($status): string
{
    $status = strtolower(trim((string) $status));
    $status = str_replace(['-', ' '], '_', $status);
    $clean = preg_replace('/[^a-z0-9_]/', '', $status) ?? '';
    return $clean !== '' ? $clean : 'unknown';
}

function mobile_resolve_asset_url(?string $path): string
{
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $normalized = '/' . ltrim($path, '/');

    return $host === '' ? $normalized : $scheme . '://' . $host . $normalized;
}

function mobile_select_expr(PDO $pdo, string $table, array $candidates, string $alias, string $default = "''"): string
{
    $column = mobile_actual_col($pdo, $table, $candidates);
    $expr = $column === null ? $default : mobile_identifier($column);
    return $expr . ' AS ' . mobile_identifier($alias);
}

function mobile_order_items_summary(PDO $pdo, int $orderId): array
{
    $empty = [
        'item_count' => 0,
        'total_qty' => 0,
        'first_title' => '',
        'first_image_url' => '',
        'seller_count' => 0,
        '_rows' => [],
    ];

    if (!mobile_table_exists($pdo, 'order_items')) {
        return $empty;
    }

    $oiCols = mobile_columns($pdo, 'order_items');
    if (!in_array('order_id', $oiCols, true)) {
        return $empty;
    }

    $hasListings = mobile_table_exists($pdo, 'listings') && in_array('listing_id', $oiCols, true) && mobile_has_col($pdo, 'listings', 'id');
    $select = ['oi.id AS item_id'];
    $select[] = in_array('fulfillment_status', $oiCols, true) ? 'oi.fulfillment_status AS fulfillment_status' : "'' AS fulfillment_status";
    $select[] = in_array('tracking_number', $oiCols, true) ? 'oi.tracking_number AS tracking_number' : "'' AS tracking_number";
    $select[] = in_array('qty', $oiCols, true) ? 'oi.qty AS qty' : (in_array('quantity', $oiCols, true) ? 'oi.quantity AS qty' : '1 AS qty');

    $titleParts = [];
    foreach (['title', 'item_title'] as $column) {
        if (in_array($column, $oiCols, true)) {
            $titleParts[] = 'NULLIF(oi.' . mobile_identifier($column) . ", '')";
        }
    }
    $imageParts = [];
    foreach (['image_url', 'image_path'] as $column) {
        if (in_array($column, $oiCols, true)) {
            $imageParts[] = 'NULLIF(oi.' . mobile_identifier($column) . ", '')";
        }
    }
    $sellerParts = [];
    if (in_array('seller_id', $oiCols, true)) {
        $sellerParts[] = 'oi.seller_id';
    }

    $join = '';
    if ($hasListings) {
        $join = ' LEFT JOIN listings l ON oi.listing_id = l.id';
        foreach (['title', 'name'] as $column) {
            if (mobile_has_col($pdo, 'listings', $column)) {
                $titleParts[] = 'NULLIF(l.' . mobile_identifier($column) . ", '')";
            }
        }
        foreach (['image_url', 'image_path', 'main_image', 'cover_image'] as $column) {
            if (mobile_has_col($pdo, 'listings', $column)) {
                $imageParts[] = 'NULLIF(l.' . mobile_identifier($column) . ", '')";
            }
        }
        if (mobile_has_col($pdo, 'listings', 'seller_id')) {
            $sellerParts[] = 'l.seller_id';
        }
    }

    $select[] = (empty($titleParts) ? "''" : 'COALESCE(' . implode(', ', $titleParts) . ", '')") . ' AS title';
    $select[] = (empty($imageParts) ? "''" : 'COALESCE(' . implode(', ', $imageParts) . ", '')") . ' AS image_url';
    $select[] = (empty($sellerParts) ? 'NULL' : 'COALESCE(' . implode(', ', $sellerParts) . ')') . ' AS seller_id';

    $orderBy = in_array('id', $oiCols, true) ? 'oi.id ASC' : 'oi.order_id ASC';
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM order_items oi' . $join . ' WHERE oi.order_id = :order_id ORDER BY ' . $orderBy);
    $stmt->execute([':order_id' => $orderId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $totalQty = 0;
    $firstTitle = '';
    $firstImage = '';
    $sellers = [];

    foreach ($rows as $row) {
        $totalQty += max(0, (int) ($row['qty'] ?? 0));
        if ($firstTitle === '' && trim((string) ($row['title'] ?? '')) !== '') {
            $firstTitle = trim((string) $row['title']);
        }
        if ($firstImage === '' && trim((string) ($row['image_url'] ?? '')) !== '') {
            $firstImage = mobile_resolve_asset_url((string) $row['image_url']);
        }
        $sellerId = trim((string) ($row['seller_id'] ?? ''));
        if ($sellerId !== '') {
            $sellers[$sellerId] = true;
        }
    }

    return [
        'item_count' => count($rows),
        'total_qty' => $totalQty,
        'first_title' => $firstTitle,
        'first_image_url' => $firstImage,
        'seller_count' => count($sellers),
        '_rows' => $rows,
    ];
}

function mobile_order_shipping_summary(array $order, array $itemRows): array
{
    $orderStatus = mobile_normalize_status($order['status'] ?? '');
    $paymentStatus = mobile_normalize_status($order['payment_status'] ?? '');
    $unpaidStatuses = ['pending_payment', 'unpaid', 'pending'];

    if (in_array($orderStatus, $unpaidStatuses, true) || in_array($paymentStatus, $unpaidStatuses, true)) {
        return [
            'key' => 'awaiting_payment',
            'label' => 'Awaiting Payment',
            'message' => 'Waiting for payment confirmation before shipping.',
            'tracking_count' => 0,
        ];
    }

    if (count($itemRows) === 0) {
        return [
            'key' => 'unknown',
            'label' => 'Unknown',
            'message' => 'Shipping status is not available yet.',
            'tracking_count' => 0,
        ];
    }

    $statuses = [];
    $tracking = [];
    foreach ($itemRows as $row) {
        $status = mobile_normalize_status($row['fulfillment_status'] ?? '');
        $statuses[] = $status === '' ? 'pending' : $status;
        $trackingNumber = trim((string) ($row['tracking_number'] ?? ''));
        if ($trackingNumber !== '') {
            $tracking[$trackingNumber] = true;
        }
    }

    $count = count($statuses);
    $completed = count(array_filter($statuses, static fn(string $status): bool => $status === 'completed' || $status === 'delivered'));
    $shippedOrCompleted = count(array_filter($statuses, static fn(string $status): bool => in_array($status, ['shipped', 'completed', 'delivered'], true)));
    $hasShippedOrCompleted = $shippedOrCompleted > 0;
    $hasPendingOrProcessing = (bool) array_filter($statuses, static fn(string $status): bool => in_array($status, ['pending', 'processing'], true));
    $hasProcessing = in_array('processing', $statuses, true);
    $allPending = count(array_filter($statuses, static fn(string $status): bool => $status === 'pending')) === $count;

    if ($completed === $count) {
        $key = 'completed';
        $label = 'Delivered';
        $message = 'Your order has been delivered.';
    } elseif ($shippedOrCompleted === $count) {
        $key = 'shipped';
        $label = 'Shipped';
        $message = 'Your order is on the way.';
    } elseif ($hasShippedOrCompleted && $hasPendingOrProcessing) {
        $key = 'mixed';
        $label = 'Partially Fulfilled';
        $message = 'Some items have shipped while others are still being prepared.';
    } elseif ($hasProcessing) {
        $key = 'processing';
        $label = 'Preparing';
        $message = 'Your order is being prepared for shipment.';
    } elseif ($allPending) {
        $key = 'pending';
        $label = 'To Ship';
        $message = 'Your order is waiting to be shipped.';
    } else {
        $key = 'unknown';
        $label = 'Unknown';
        $message = 'Shipping status is not available yet.';
    }

    return [
        'key' => $key,
        'label' => $label,
        'message' => $message,
        'tracking_count' => count($tracking),
    ];
}

function mobile_order_refund_summary(PDO $pdo, int $orderId): array
{
    $empty = [
        'has_refund' => false,
        'latest_status' => '',
        'requested_amount' => 0.0,
        'approved_amount' => 0.0,
        'actual_refund_amount' => 0.0,
    ];

    if (!mobile_table_exists($pdo, 'order_refunds') || !mobile_has_col($pdo, 'order_refunds', 'order_id')) {
        return $empty;
    }

    $cols = mobile_columns($pdo, 'order_refunds');
    $statusCol = mobile_actual_col($pdo, 'order_refunds', ['status', 'refund_status']);
    $requestedCol = mobile_actual_col($pdo, 'order_refunds', ['requested_refund_amount', 'requested_amount']);
    $approvedCol = mobile_actual_col($pdo, 'order_refunds', ['approved_refund_amount', 'approved_amount']);
    $actualCol = mobile_actual_col($pdo, 'order_refunds', ['actual_refunded_amount', 'actual_refund_amount', 'refunded_amount']); 

    $select = [
        $statusCol === null ? "'' AS latest_status" : mobile_identifier($statusCol) . ' AS latest_status',
        $requestedCol === null ? '0 AS requested_amount' : mobile_identifier($requestedCol) . ' AS requested_amount',
        $approvedCol === null ? '0 AS approved_amount' : mobile_identifier($approvedCol) . ' AS approved_amount',
        $actualCol === null ? '0 AS actual_refund_amount' : mobile_identifier($actualCol) . ' AS actual_refund_amount',
    ];
    $orderParts = [];
    if (in_array('created_at', $cols, true)) {
        $orderParts[] = 'created_at DESC';
    }
    if (in_array('id', $cols, true)) {
        $orderParts[] = 'id DESC';
    }
    $orderBy = empty($orderParts) ? 'order_id DESC' : implode(', ', $orderParts);

    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM order_refunds WHERE order_id = :order_id ORDER BY ' . $orderBy);
    $stmt->execute([':order_id' => $orderId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) === 0) {
        return $empty;
    }

    $requested = 0.0;
    $approved = 0.0;
    $actual = 0.0;
    foreach ($rows as $row) {
        $requested += mobile_money_number($row['requested_amount'] ?? 0);
        $approved += mobile_money_number($row['approved_amount'] ?? 0);
        $actual += mobile_money_number($row['actual_refund_amount'] ?? 0);
    }

    return [
        'has_refund' => true,
        'latest_status' => (string) ($rows[0]['latest_status'] ?? ''),
        'requested_amount' => mobile_money_number($requested),
        'approved_amount' => mobile_money_number($approved),
        'actual_refund_amount' => mobile_money_number($actual),
    ];
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        mobile_error('bad_request', 'Only GET requests are supported.', 400);
    }

    $pdo = mobile_db();
    $user = mobile_auth_user($pdo);

    if (!mobile_table_exists($pdo, 'orders')) {
        mobile_error('server_error', 'Orders table is not configured.', 500);
    }

    $ownershipCol = mobile_actual_col($pdo, 'orders', ['user_id', 'buyer_user_id', 'customer_id']);
    if ($ownershipCol === null) {
        mobile_error('server_error', 'Order ownership column is not configured.', 500);
    }

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(50, max(1, (int) ($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $perPage;
    $sort = strtolower(trim((string) ($_GET['sort'] ?? 'newest')));
    if (!in_array($sort, ['newest', 'oldest'], true)) {
        mobile_error('bad_request', 'Invalid sort value.', 400);
    }

    $where = [mobile_identifier($ownershipCol) . ' = :user_id'];
    $params = [':user_id' => (int) $user['id']];

    if (isset($_GET['status']) && trim((string) $_GET['status']) !== '') {
        if (!mobile_has_col($pdo, 'orders', 'status')) {
            mobile_error('server_error', 'Order status column is not configured.', 500);
        }
        $where[] = '`status` = :status';
        $params[':status'] = trim((string) $_GET['status']);
    }

    if (isset($_GET['payment_status']) && trim((string) $_GET['payment_status']) !== '') {
        if (!mobile_has_col($pdo, 'orders', 'payment_status')) {
            mobile_error('server_error', 'Order payment status column is not configured.', 500);
        }
        $where[] = '`payment_status` = :payment_status';
        $params[':payment_status'] = trim((string) $_GET['payment_status']);
    }

    if (isset($_GET['q']) && trim((string) $_GET['q']) !== '') {
        if (!mobile_has_col($pdo, 'orders', 'order_code')) {
            mobile_error('server_error', 'Order code column is not configured.', 500);
        }
        $where[] = '`order_code` LIKE :q';
        $params[':q'] = '%' . str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $_GET['q'])) . '%';
    }

    $whereSql = implode(' AND ', $where);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE ' . $whereSql);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = $total === 0 ? 0 : (int) ceil($total / $perPage);

    $createdCol = mobile_actual_col($pdo, 'orders', ['created_at']);
    $orderDirection = $sort === 'oldest' ? 'ASC' : 'DESC';
    $orderSql = $createdCol === null
        ? 'id ' . $orderDirection
        : mobile_identifier($createdCol) . ' ' . $orderDirection . ', id ' . $orderDirection;

    $select = [
        'id',
        mobile_select_expr($pdo, 'orders', ['order_code'], 'order_code'),
        mobile_select_expr($pdo, 'orders', ['status'], 'status'),
        mobile_select_expr($pdo, 'orders', ['payment_status'], 'payment_status'),
        mobile_select_expr($pdo, 'orders', ['currency'], 'currency', "'USD'"),
        mobile_select_expr($pdo, 'orders', ['subtotal', 'order_subtotal'], 'subtotal', '0'),
        mobile_select_expr($pdo, 'orders', ['shipping_amount', 'shipping_total'], 'shipping_amount', '0'),
        mobile_select_expr($pdo, 'orders', ['discount_amount', 'seller_discount_total', 'discount_total'], 'discount_amount', '0'),
        mobile_select_expr($pdo, 'orders', ['total', 'order_total'], 'total', '0'),
        mobile_select_expr($pdo, 'orders', ['created_at'], 'created_at'),
        mobile_select_expr($pdo, 'orders', ['paid_at'], 'paid_at'),
        mobile_select_expr($pdo, 'orders', ['updated_at'], 'updated_at'),
    ];

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM orders WHERE ' . $whereSql . ' ORDER BY ' . $orderSql . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $orders = [];
    foreach ($rows as $row) {
        $orderId = (int) $row['id'];
        $itemsSummary = mobile_order_items_summary($pdo, $orderId);
        $itemRows = $itemsSummary['_rows'];
        unset($itemsSummary['_rows']);

        $orders[] = [
            'id' => $orderId,
            'order_code' => (string) ($row['order_code'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'payment_status' => (string) ($row['payment_status'] ?? ''),
            'currency' => (string) (($row['currency'] ?? '') ?: 'USD'),
            'subtotal' => mobile_money_number($row['subtotal'] ?? 0),
            'shipping_amount' => mobile_money_number($row['shipping_amount'] ?? 0),
            'discount_amount' => mobile_money_number($row['discount_amount'] ?? 0),
            'total' => mobile_money_number($row['total'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'paid_at' => (string) ($row['paid_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'shipping' => mobile_order_shipping_summary($row, $itemRows),
            'items_summary' => $itemsSummary,
            'refunds' => mobile_order_refund_summary($pdo, $orderId),
        ];
    }

    mobile_json(200, [
        'ok' => true,
        'data' => [
            'orders' => $orders,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ],
        'meta' => [],
    ]);
} catch (Throwable $e) {
    mobile_log($e->getMessage());
    mobile_error('server_error', 'Unable to load orders at this time.', 500);
}
