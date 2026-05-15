<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization, X-Requested-With');
header('Access-Control-Max-Age: 86400');

const API_VERSION = 'mobile-v1';

function mobile_meta(): array
{
    return [
        'api_version' => API_VERSION,
        'generated_at' => gmdate('c'),
    ];
}

function mobile_json_response(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    if (!array_key_exists('meta', $payload)) {
        $payload['meta'] = mobile_meta();
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function mobile_error_response(int $statusCode, string $code, string $message): void
{
    mobile_json_response($statusCode, [
        'ok' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
        'meta' => mobile_meta(),
    ]);
}

function mobile_log_internal(string $message): void
{
    $safeMessage = '[' . gmdate('c') . '] mobile register API: ' . str_replace(["\r", "\n"], ' ', $message) . PHP_EOL;
    $candidateDirs = [
        __DIR__ . '/../../../../logs',
        __DIR__ . '/../../../logs',
        __DIR__ . '/../../logs',
        __DIR__ . '/../logs',
        dirname(__DIR__, 5) . '/logs',
    ];

    foreach (array_unique($candidateDirs) as $dir) {
        if (is_dir($dir) && is_writable($dir)) {
            file_put_contents($dir . '/mobile_api_errors.log', $safeMessage, FILE_APPEND | LOCK_EX);
            return;
        }
    }

    error_log(trim($safeMessage));
}

function mobile_clean_include_output(int $targetLevel): void
{
    while (ob_get_level() > $targetLevel) {
        ob_end_clean();
    }
}


function mobile_can_include_config_file(string $file): bool
{
    $contents = file_get_contents($file, false, null, 0, 512);
    if ($contents === false) {
        return false;
    }

    if (str_contains($contents, "dirname(__DIR__) . '/config/db.php'")) {
        return is_file(dirname(dirname($file)) . '/config/db.php');
    }

    return true;
}

function mobile_load_database_config(): void
{
    $candidates = [
        __DIR__ . '/../../../includes/db.php',
        __DIR__ . '/../../../includes/config.php',
        __DIR__ . '/../../../../includes/db.php',
        __DIR__ . '/../../../../includes/config.php',
        __DIR__ . '/../../../../db.php',
        __DIR__ . '/../../../../config.php',
        dirname(__DIR__, 5) . '/includes/db.php',
        dirname(__DIR__, 5) . '/includes/config.php',
        dirname(__DIR__, 5) . '/db.php',
        dirname(__DIR__, 5) . '/config.php',
    ];

    $bufferLevel = ob_get_level();
    ob_start();
    foreach (array_unique($candidates) as $file) {
        if (is_file($file) && is_readable($file) && mobile_can_include_config_file($file)) {
            include_once $file;
        }
    }
    mobile_clean_include_output($bufferLevel);
}

function mobile_find_pdo(): ?PDO
{
    foreach (['pdo', 'db', 'conn', 'connection'] as $name) {
        if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
            return $GLOBALS[$name];
        }
    }

    return null;
}

function mobile_find_mysqli(): ?mysqli
{
    foreach (['mysqli', 'conn', 'db', 'connection'] as $name) {
        if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof mysqli) {
            return $GLOBALS[$name];
        }
    }

    return null;
}

function mobile_make_pdo_from_constants(): ?PDO
{
    $host = defined('DB_HOST') ? (string)constant('DB_HOST') : '';
    $name = defined('DB_NAME') ? (string)constant('DB_NAME') : '';
    $user = defined('DB_USER') ? (string)constant('DB_USER') : '';
    $pass = defined('DB_PASS') ? (string)constant('DB_PASS') : (defined('DB_PASSWORD') ? (string)constant('DB_PASSWORD') : '');
    $charset = defined('DB_CHARSET') ? (string)constant('DB_CHARSET') : 'utf8mb4';

    if ($host === '' || $name === '' || $user === '') {
        return null;
    }

    $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=' . $charset;

    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function mobile_request_data(): array
{
    $contentType = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? '')));
    if ($contentType === '') {
        $contentType = strtolower(trim((string)($_SERVER['HTTP_CONTENT_TYPE'] ?? '')));
    }

    if (str_contains($contentType, ';')) {
        $contentType = trim(strtok($contentType, ';'));
    }

    if ($contentType === 'application/json') {
        $rawBody = file_get_contents('php://input');
        $decoded = json_decode($rawBody === false ? '' : $rawBody, true);
        if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
            mobile_error_response(400, 'invalid_json', 'Request body must be valid JSON.');
        }

        return $decoded;
    }

    if ($contentType === 'application/x-www-form-urlencoded' || $contentType === '') {
        return $_POST;
    }

    mobile_error_response(400, 'validation_failed', 'Content-Type must be application/json or application/x-www-form-urlencoded.');
}

function mobile_string(array $data, string $key): string
{
    $value = $data[$key] ?? '';
    if (is_array($value) || is_object($value)) {
        return '';
    }

    return trim((string)$value);
}

function mobile_normalize_columns(array $rows): array
{
    $columns = [];
    foreach ($rows as $row) {
        $field = '';
        if (is_array($row)) {
            $field = (string)($row['Field'] ?? $row['field'] ?? reset($row));
        } elseif (is_object($row) && isset($row->Field)) {
            $field = (string)$row->Field;
        }

        if ($field !== '') {
            $columns[$field] = true;
        }
    }

    return $columns;
}

function mobile_get_pdo_columns(PDO $pdo): array
{
    $stmt = $pdo->query('SHOW COLUMNS FROM users');
    if ($stmt === false) {
        return [];
    }

    return mobile_normalize_columns($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function mobile_get_mysqli_columns(mysqli $mysqli): array
{
    $result = $mysqli->query('SHOW COLUMNS FROM users');
    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();

    return mobile_normalize_columns($rows);
}

function mobile_pdo_email_exists(PDO $pdo, string $email): bool
{
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);

    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
}

function mobile_mysqli_email_exists(mysqli $mysqli, string $email): bool
{
    $stmt = $mysqli->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare email lookup.');
    }

    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->free_result();
    $stmt->close();

    return $exists;
}

function mobile_insert_values(array $columns, array $form, string $passwordHash): array
{
    $values = [];
    $fullName = trim($form['first_name'] . ' ' . $form['last_name']);

    if (isset($columns['first_name'])) {
        $values['first_name'] = $form['first_name'];
    }
    if (isset($columns['last_name'])) {
        $values['last_name'] = $form['last_name'];
    }
    if (isset($columns['name'])) {
        $values['name'] = $fullName;
    }
    if (isset($columns['email'])) {
        $values['email'] = $form['email'];
    }
    if (isset($columns['phone'])) {
        $values['phone'] = $form['phone'] !== '' ? $form['phone'] : null;
    }
    if (isset($columns['password_hash'])) {
        $values['password_hash'] = $passwordHash;
    } elseif (isset($columns['password'])) {
        $values['password'] = $passwordHash;
    }
    if (isset($columns['role'])) {
        $values['role'] = 'user';
    }
    if (isset($columns['account_status'])) {
        $values['account_status'] = 'active';
    }
    if (isset($columns['status'])) {
        $values['status'] = 'active';
    }
    if (isset($columns['created_at'])) {
        $values['created_at'] = new DateTimeImmutable('now');
    }
    if (isset($columns['updated_at'])) {
        $values['updated_at'] = new DateTimeImmutable('now');
    }

    return $values;
}

function mobile_pdo_insert_user(PDO $pdo, array $values): int
{
    $columns = array_keys($values);
    $placeholders = [];
    $params = [];

    foreach ($columns as $column) {
        if ($values[$column] instanceof DateTimeInterface) {
            $placeholders[] = 'NOW()';
            continue;
        }

        $placeholder = ':' . $column;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $values[$column];
    }

    $sql = 'INSERT INTO users (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int)$pdo->lastInsertId();
}

function mobile_mysqli_insert_user(mysqli $mysqli, array $values): int
{
    $columns = array_keys($values);
    $placeholders = [];
    $bindValues = [];
    $types = '';

    foreach ($columns as $column) {
        if ($values[$column] instanceof DateTimeInterface) {
            $placeholders[] = 'NOW()';
            continue;
        }

        $placeholders[] = '?';
        $bindValues[] = $values[$column];
        $types .= 's';
    }

    $sql = 'INSERT INTO users (`' . implode('`, `', $columns) . '`) VALUES (' . implode(', ', $placeholders) . ')';
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare user insert.');
    }

    if ($bindValues !== []) {
        $stmt->bind_param($types, ...$bindValues);
    }
    $stmt->execute();
    $insertId = (int)$mysqli->insert_id;
    $stmt->close();

    return $insertId;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST, OPTIONS');
    mobile_error_response(405, 'method_not_allowed', 'Only POST requests are allowed.');
}

$data = mobile_request_data();

$form = [
    'first_name' => mobile_string($data, 'first_name'),
    'last_name' => mobile_string($data, 'last_name'),
    'email' => strtolower(mobile_string($data, 'email')),
    'password' => mobile_string($data, 'password'),
    'confirm_password' => mobile_string($data, 'confirm_password'),
    'phone' => mobile_string($data, 'phone'),
];

$validationErrors = [];
if ($form['first_name'] === '') {
    $validationErrors['first_name'] = 'First name is required.';
}
if ($form['last_name'] === '') {
    $validationErrors['last_name'] = 'Last name is required.';
}
if ($form['email'] === '') {
    $validationErrors['email'] = 'Email is required.';
} elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
    $validationErrors['email'] = 'Email must be valid.';
}
if ($form['password'] === '') {
    $validationErrors['password'] = 'Password is required.';
} elseif (strlen($form['password']) < 8) {
    $validationErrors['password'] = 'Password must be at least 8 characters.';
}
if ($form['confirm_password'] !== $form['password']) {
    $validationErrors['confirm_password'] = 'Confirm password must match password.';
}

if ($validationErrors !== []) {
    mobile_json_response(422, [
        'ok' => false,
        'error' => [
            'code' => 'validation_failed',
            'message' => 'Validation failed.',
            'details' => $validationErrors,
        ],
        'meta' => mobile_meta(),
    ]);
}

try {
    mobile_load_database_config();

    $pdo = mobile_find_pdo();
    $mysqli = mobile_find_mysqli();

    if (!$pdo && !$mysqli) {
        $pdo = mobile_make_pdo_from_constants();
    }

    if ($pdo) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $columns = mobile_get_pdo_columns($pdo);
        if (!isset($columns['email']) || (!isset($columns['password_hash']) && !isset($columns['password']))) {
            throw new RuntimeException('Required users table columns are unavailable.');
        }

        if (mobile_pdo_email_exists($pdo, $form['email'])) {
            mobile_error_response(409, 'email_exists', 'Email is already registered.');
        }

        $passwordHash = password_hash($form['password'], PASSWORD_DEFAULT);
        $values = mobile_insert_values($columns, $form, $passwordHash);
        $userId = mobile_pdo_insert_user($pdo, $values);
    } elseif ($mysqli) {
        $mysqli->set_charset('utf8mb4');
        $columns = mobile_get_mysqli_columns($mysqli);
        if (!isset($columns['email']) || (!isset($columns['password_hash']) && !isset($columns['password']))) {
            throw new RuntimeException('Required users table columns are unavailable.');
        }

        if (mobile_mysqli_email_exists($mysqli, $form['email'])) {
            mobile_error_response(409, 'email_exists', 'Email is already registered.');
        }

        $passwordHash = password_hash($form['password'], PASSWORD_DEFAULT);
        $values = mobile_insert_values($columns, $form, $passwordHash);
        $userId = mobile_mysqli_insert_user($mysqli, $values);
    } else {
        mobile_error_response(503, 'db_unavailable', 'Database is unavailable.');
    }

    mobile_json_response(201, [
        'ok' => true,
        'data' => [
            'user' => [
                'id' => $userId,
                'first_name' => $form['first_name'],
                'last_name' => $form['last_name'],
                'email' => $form['email'],
                'role' => 'user',
                'account_status' => 'active',
            ],
        ],
        'meta' => mobile_meta(),
    ]);
} catch (PDOException|mysqli_sql_exception $exception) {
    mobile_log_internal($exception->getMessage());
    mobile_error_response(503, 'db_unavailable', 'Database is unavailable.');
} catch (Throwable $exception) {
    mobile_log_internal($exception->getMessage());
    mobile_error_response(500, 'server_error', 'Unable to complete registration.');
}
