<?php
declare(strict_types=1);

// =============================================================================
// Bettavaro Mobile API v1 — /api/mobile/v1/seller_fulfillment_action.php
// Authenticated seller endpoint: update fulfillment_status on owned order items.
//
// CRITICAL: Never touches $_SESSION. Never outputs HTML. Never redirects.
// Does NOT interfere with the website session-based login in login.php.
//
// CORS: Not opened broadly. Configure Access-Control-Allow-Origin later
//       when mobile app domain or API gateway is finalized.
// =============================================================================

while (ob_get_level() > 0) {
    @ob_end_clean();
}

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$method = $_SERVER['REQUEST_METHOD'] ?? 'CLI';
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok'    => false,
        'error' => ['code' => 'method_not_allowed', 'message' => 'Only POST requests are accepted.'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// =============================================================================
// Root path helpers
// File lives at: /public_html/api/mobile/v1/seller_fulfillment_action.php
// dirname(__DIR__, 3) → /public_html
// dirname(__DIR__, 4) → account root (parent of public_html)
// =============================================================================

if (!function_exists('bv_seller_fulfillment_public_root')) {
    function bv_seller_fulfillment_public_root(): string { return dirname(__DIR__, 3); }
}
if (!function_exists('bv_seller_fulfillment_project_root')) {
    function bv_seller_fulfillment_project_root(): string { return dirname(bv_seller_fulfillment_public_root()); }
}

if (!function_exists('bv_seller_fulfillment_debug_log')) {
    function bv_seller_fulfillment_debug_log(string $message, array $context = []): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . ' '
              . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
              . PHP_EOL;
        @file_put_contents(
            __DIR__ . '/seller_fulfillment_action_debug.log',
            $line,
            FILE_APPEND | LOCK_EX
        );
    }
}

// =============================================================================
// Helpers
// =============================================================================

if (!function_exists('bv_seller_fulfillment_json')) {
    function bv_seller_fulfillment_json(int $statusCode, array $payload): void
    {
        http_response_code($statusCode);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        exit;
    }
}

if (!function_exists('bv_seller_fulfillment_error')) {
    function bv_seller_fulfillment_error(string $code, string $message, int $statusCode = 400): void
    {
        bv_seller_fulfillment_json($statusCode, [
            'ok'    => false,
            'error' => ['code' => $code, 'message' => $message],
        ]);
    }
}

if (!function_exists('bv_seller_fulfillment_log')) {
    function bv_seller_fulfillment_log(string $message): void
    {
        error_log('[BV Seller Fulfillment] ' . $message);
        $line = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        foreach ([
            bv_seller_fulfillment_project_root() . '/private_html/mobile_api.log',
            bv_seller_fulfillment_public_root()  . '/logs/mobile_api.log',
        ] as $lf) {
            $ld = dirname($lf);
            if (is_dir($ld) && is_writable($ld)) { @file_put_contents($lf, $line, FILE_APPEND | LOCK_EX); break; }
        }
    }
}

if (!function_exists('bv_seller_fulfillment_db')) {
    function bv_seller_fulfillment_db(): ?object
    {
        static $cached = false;
        static $connection = null;
        if ($cached !== false) { return $connection; }
        $cached     = true;
        $publicRoot = bv_seller_fulfillment_public_root();
        foreach ([
            $publicRoot . '/config/db.php',
            $publicRoot . '/includes/db.php',
            $publicRoot . '/includes/config.php',
        ] as $cfg) {
            if (!is_file($cfg)) { continue; }
            $loader = static function (string $path): array {
                $db_host=$db_user=$db_pass=$db_name=$db_port=null;
                $host=$user=$pass=$name=$port=null;
                $DB_HOST=$DB_USER=$DB_PASS=$DB_NAME=$DB_PORT=null;
                $dsn=null; $pdo=$conn=$db=$mysqli=$link=null;
                /** @noinspection PhpIncludeInspection */ @include $path;
                return compact('db_host','db_user','db_pass','db_name','db_port',
                    'host','user','pass','name','port',
                    'DB_HOST','DB_USER','DB_PASS','DB_NAME','DB_PORT',
                    'dsn','pdo','conn','db','mysqli','link');
            };
            $vars = $loader($cfg);
            foreach (['pdo','conn','db','mysqli','link'] as $n) {
                if (isset($vars[$n]) && ($vars[$n] instanceof PDO || $vars[$n] instanceof mysqli)) {
                    $connection = $vars[$n]; return $connection;
                }
            }
            foreach (['pdo','conn','db','mysqli','link'] as $gn) {
                if (isset($GLOBALS[$gn])) {
                    $obj = $GLOBALS[$gn];
                    if ($obj instanceof PDO || $obj instanceof mysqli) { $connection=$obj; return $connection; }
                }
            }
            $h  = $vars['db_host'] ?? $vars['host']    ?? $vars['DB_HOST']  ?? null;
            $u  = $vars['db_user'] ?? $vars['user']    ?? $vars['DB_USER']  ?? null;
            $p  = $vars['db_pass'] ?? $vars['pass']    ?? $vars['DB_PASS']  ?? null;
            $n  = $vars['db_name'] ?? $vars['name']    ?? $vars['DB_NAME']  ?? null;
            $pt = (int)($vars['db_port'] ?? $vars['port'] ?? $vars['DB_PORT'] ?? 3306);
            if ($h && $u !== null && $n) {
                try {
                    $pdo = new PDO("mysql:host={$h};port={$pt};dbname={$n};charset=utf8mb4", $u, (string)$p, [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                    ]);
                    $connection=$pdo; return $connection;
                } catch (\Throwable $e) { error_log('[BV Seller Fulfillment] PDO: '.$e->getMessage()); }
            }
            if ($h && $u !== null && $n && function_exists('mysqli_connect')) {
                try {
                    $m = @new mysqli($h,(string)$u,(string)$p,$n,$pt?:3306);
                    if (!$m->connect_errno) { $m->set_charset('utf8mb4'); $connection=$m; return $connection; }
                    error_log('[BV Seller Fulfillment] mysqli: '.$m->connect_error);
                } catch (\Throwable $e) { error_log('[BV Seller Fulfillment] mysqli ex: '.$e->getMessage()); }
            }
            break;
        }
        return null;
    }
}

if (!function_exists('bv_seller_fulfillment_table_exists')) {
    function bv_seller_fulfillment_table_exists(object $db, string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) { return false; }
        try {
            if ($db instanceof PDO) {
                $prev = $db->getAttribute(PDO::ATTR_ERRMODE);
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                try { $db->query("SELECT 1 FROM `{$table}` LIMIT 1"); $db->setAttribute(PDO::ATTR_ERRMODE,$prev); return true; }
                catch (\PDOException $e) { $db->setAttribute(PDO::ATTR_ERRMODE,$prev); return false; }
            }
            if ($db instanceof mysqli) {
                $res = $db->query("SELECT 1 FROM `{$table}` LIMIT 1");
                if ($res!==false) { if ($res instanceof mysqli_result){$res->free();} return true; }
                return false;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Fulfillment] table_exists: '.$e->getMessage()); }
        return false;
    }
}

if (!function_exists('bv_seller_fulfillment_columns')) {
    function bv_seller_fulfillment_columns(object $db, string $table): array
    {
        $cols = [];
        try {
            if ($db instanceof PDO) {
                $st = $db->prepare('SHOW COLUMNS FROM `'.str_replace('`','',$table).'`');
                $st->execute();
                foreach ($st->fetchAll() as $r) { $cols[] = strtolower((string)($r['Field']??'')); }
            } elseif ($db instanceof mysqli) {
                $safe = str_replace('`','',$table);
                $res  = $db->query("SHOW COLUMNS FROM `{$safe}`");
                if ($res) { while ($r=$res->fetch_assoc()) { $cols[] = strtolower((string)($r['Field']??'')); } }
            }
        } catch (\Throwable $e) { error_log('[BV Seller Fulfillment] columns: '.$e->getMessage()); }
        return $cols;
    }
}

if (!function_exists('bv_seller_fulfillment_has_col')) {
    function bv_seller_fulfillment_has_col(object $db, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table.'.'.$column;
        if (!array_key_exists($key,$cache)) {
            $cache[$key] = in_array(strtolower($column), bv_seller_fulfillment_columns($db,$table), true);
        }
        return $cache[$key];
    }
}

if (!function_exists('bv_sfa_clean')) {
    function bv_sfa_clean($v): string
    { if ($v===null||$v===false) return ''; return htmlspecialchars_decode(strip_tags((string)$v),ENT_QUOTES|ENT_HTML5); }
}
if (!function_exists('bv_sfa_int')) {
    function bv_sfa_int($v,int $d=0): int { return is_numeric($v)?(int)$v:$d; }
}
if (!function_exists('bv_sfa_read_bearer')) {
    function bv_sfa_read_bearer(): string
    {
        $h='';
        if (!empty($_SERVER['HTTP_AUTHORIZATION'])) { $h=(string)$_SERVER['HTTP_AUTHORIZATION']; }
        elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) { $h=(string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION']; }
        elseif (function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k=>$v) {
                if (strtolower($k)==='authorization') { $h=(string)$v; break; }
            }
        }
        if ($h===''||!preg_match('/^Bearer\s+(\S+)$/i',trim($h),$m)) return '';
        return $m[1];
    }
}
if (!function_exists('bv_sfa_read_input')) {
    function bv_sfa_read_input(): array
    {
        $defaults = ['order_item_id'=>'','action'=>'','carrier'=>'','tracking_number'=>'','note'=>''];
        $ct = strtolower(trim((string)($_SERVER['CONTENT_TYPE']??'')));
        if (str_contains($ct,'application/json')) {
            $raw = (string)file_get_contents('php://input');
            if ($raw!=='') {
                $d = json_decode($raw,true);
                if (is_array($d)) {
                    return [
                        'order_item_id'   => trim((string)($d['order_item_id']   ?? '')),
                        'action'          => trim((string)($d['action']          ?? '')),
                        'carrier'         => trim((string)($d['carrier']         ?? '')),
                        'tracking_number' => trim((string)($d['tracking_number'] ?? '')),
                        'note'            => trim((string)($d['note']            ?? '')),
                    ];
                }
            }
        }
        return [
            'order_item_id'   => trim((string)($_POST['order_item_id']   ?? '')),
            'action'          => trim((string)($_POST['action']          ?? '')),
            'carrier'         => trim((string)($_POST['carrier']         ?? '')),
            'tracking_number' => trim((string)($_POST['tracking_number'] ?? '')),
            'note'            => trim((string)($_POST['note']            ?? '')),
        ];
    }
}

// ── Query helpers ─────────────────────────────────────────────────────────────
if (!function_exists('bv_sfa_row')) {
    function bv_sfa_row(object $db, string $sql, array $p=[]): ?array
    {
        try {
            if ($db instanceof PDO) { $st=$db->prepare($sql); $st->execute($p); $r=$st->fetch(); return $r?:null; }
            if ($db instanceof mysqli) {
                $st=$db->prepare($sql); if ($st===false) return null;
                if (!empty($p)) { $t=implode('',array_map(static fn($v)=>is_int($v)?'i':(is_float($v)?'d':'s'),$p));$ref=[&$t];foreach($p as $k=>$_){$ref[]=&$p[$k];}call_user_func_array([$st,'bind_param'],$ref); }
                $st->execute(); $res=$st->get_result(); $r=$res?$res->fetch_assoc():null; $st->close(); return $r?:null;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Fulfillment] row: '.$e->getMessage()); }
        return null;
    }
}
if (!function_exists('bv_sfa_rows')) {
    function bv_sfa_rows(object $db, string $sql, array $p=[]): array
    {
        try {
            if ($db instanceof PDO) { $st=$db->prepare($sql); $st->execute($p); return $st->fetchAll()?:[]; }
            if ($db instanceof mysqli) {
                $st=$db->prepare($sql); if ($st===false) return [];
                if (!empty($p)) { $t=implode('',array_map(static fn($v)=>is_int($v)?'i':(is_float($v)?'d':'s'),$p));$ref=[&$t];foreach($p as $k=>$_){$ref[]=&$p[$k];}call_user_func_array([$st,'bind_param'],$ref); }
                $st->execute(); $res=$st->get_result(); $rows=[]; if ($res){while($r=$res->fetch_assoc()){$rows[]=$r;}} $st->close(); return $rows;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Fulfillment] rows: '.$e->getMessage()); }
        return [];
    }
}
if (!function_exists('bv_sfa_exec')) {
    function bv_sfa_exec(object $db, string $sql, array $p=[]): bool
    {
        try {
            if ($db instanceof PDO) { return $db->prepare($sql)->execute($p); }
            if ($db instanceof mysqli) {
                $st=$db->prepare($sql); if ($st===false) return false;
                if (!empty($p)) { $t=implode('',array_map(static fn($v)=>is_int($v)?'i':(is_float($v)?'d':'s'),$p));$ref=[&$t];foreach($p as $k=>$_){$ref[]=&$p[$k];}call_user_func_array([$st,'bind_param'],$ref); }
                $result=$st->execute(); $st->close(); return $result;
            }
        } catch (\Throwable $e) { error_log('[BV Seller Fulfillment] exec: '.$e->getMessage()); }
        return false;
    }
}
if (!function_exists('bv_sfa_begin')) {
    function bv_sfa_begin(object $db): void
    {
        try {
            if ($db instanceof PDO) { $db->beginTransaction(); }
            elseif ($db instanceof mysqli) { $db->autocommit(false); $db->begin_transaction(); }
        } catch (\Throwable $e) { error_log('[BV Seller Fulfillment] begin: '.$e->getMessage()); }
    }
}
if (!function_exists('bv_sfa_commit')) {
    function bv_sfa_commit(object $db): void
    {
        try {
            if ($db instanceof PDO) { $db->commit(); }
            elseif ($db instanceof mysqli) { $db->commit(); $db->autocommit(true); }
        } catch (\Throwable $e) { error_log('[BV Seller Fulfillment] commit: '.$e->getMessage()); }
    }
}
if (!function_exists('bv_sfa_rollback')) {
    function bv_sfa_rollback(object $db): void
    {
        try {
            if ($db instanceof PDO) { if ($db->inTransaction()) $db->rollBack(); }
            elseif ($db instanceof mysqli) { $db->rollback(); $db->autocommit(true); }
        } catch (\Throwable $e) { error_log('[BV Seller Fulfillment] rollback: '.$e->getMessage()); }
    }
}

// =============================================================================
// Main execution
// =============================================================================
try {

    // ── Auth ──────────────────────────────────────────────────────────────────
    bv_seller_fulfillment_debug_log('start', [
        'method'       => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'content_type' => $_SERVER['CONTENT_TYPE']   ?? '',
    ]);
    $plainToken = bv_sfa_read_bearer();
    if ($plainToken==='') { bv_seller_fulfillment_error('token_missing','Authorization token is required.',401); }
    $tokenHash = hash('sha256',$plainToken);

    $db = bv_seller_fulfillment_db();
    if ($db===null) { bv_seller_fulfillment_log('No DB.'); bv_seller_fulfillment_error('db_unavailable','Service temporarily unavailable.',503); }

    bv_seller_fulfillment_debug_log('db_connected', [
        'db_type' => ($db instanceof PDO) ? 'PDO' : (($db instanceof mysqli) ? 'mysqli' : 'unknown'),
    ]);

    $tokenRow = bv_sfa_row($db,
        "SELECT mat.id AS token_id,u.id AS user_id,u.role,u.account_status
         FROM mobile_auth_tokens mat INNER JOIN users u ON u.id=mat.user_id
         WHERE mat.token_hash=? AND mat.revoked_at IS NULL AND mat.expires_at>NOW() AND u.account_status='active' LIMIT 1",
        [$tokenHash]
    );
    if ($tokenRow===null) { bv_seller_fulfillment_error('token_invalid','Token is invalid or has expired.',401); }

    bv_sfa_exec($db,'UPDATE mobile_auth_tokens SET last_used_at=NOW() WHERE id=? LIMIT 1',[(int)$tokenRow['token_id']]);

    $userId   = (int)$tokenRow['user_id'];
    $userRole = bv_sfa_clean($tokenRow['role']??'user');

    if (!in_array($userRole,['seller','admin'],true)) { bv_seller_fulfillment_error('seller_required','Seller access is required.',403); }

    bv_seller_fulfillment_debug_log('auth_ok', [
        'user_id' => $userId,
        'role'    => $userRole,
    ]);

    // Admin override (seller cannot override)
    $filterSellerId = $userId;
    if ($userRole==='admin') {
        $input0 = bv_sfa_read_input();
        $rawAdmin = bv_sfa_int($input0['order_item_id']??0,0); // placeholder — read properly below
        // seller_id from POST/JSON for admin
        $ct0 = strtolower(trim((string)($_SERVER['CONTENT_TYPE']??'')));
        if (str_contains($ct0,'application/json')) {
            $raw0 = (string)file_get_contents('php://input');
            $d0   = $raw0!=='' ? json_decode($raw0,true) : null;
            $adminSid = is_array($d0) ? bv_sfa_int($d0['seller_id']??0,0) : 0;
        } else {
            $adminSid = bv_sfa_int($_POST['seller_id']??0,0);
        }
        if ($adminSid>0) { $filterSellerId=$adminSid; }
    }

    // ── Read input ────────────────────────────────────────────────────────────
    $input          = bv_sfa_read_input();
    $orderItemId    = bv_sfa_int($input['order_item_id'], 0);
    if ($orderItemId<=0) { bv_seller_fulfillment_error('order_item_id_required','Order item id is required.',400); }

    $action          = strtolower(trim($input['action']));
    $allowedActions  = ['process','ship','complete'];
    if (!in_array($action,$allowedActions,true)) { bv_seller_fulfillment_error('invalid_action','Invalid fulfillment action.',400); }

    $inputTracking = substr(trim($input['tracking_number']),0,120);
    $inputCarrier  = substr(trim($input['carrier']),0,100);
    $inputNote     = substr(trim($input['note']),0,500);

    bv_seller_fulfillment_debug_log('input_parsed', [
        'order_item_id'    => $orderItemId,
        'action'           => $action,
        'has_tracking_number' => $inputTracking !== '',
        'has_carrier'      => $inputCarrier !== '',
    ]);

    // ── Verify required tables ────────────────────────────────────────────────
    if (!bv_seller_fulfillment_table_exists($db,'orders')) { bv_seller_fulfillment_error('db_unavailable','Service temporarily unavailable.',503); }
    if (!bv_seller_fulfillment_table_exists($db,'order_items')) { bv_seller_fulfillment_error('db_unavailable','Service temporarily unavailable.',503); }

    // ── Detect columns ────────────────────────────────────────────────────────
    $oiCols   = bv_seller_fulfillment_columns($db,'order_items');
    $hasOiCol = static fn(string $c): bool => in_array(strtolower($c),$oiCols,true);

    $oCols    = bv_seller_fulfillment_columns($db,'orders');
    $hasOCol  = static fn(string $c): bool => in_array(strtolower($c),$oCols,true);

    // fulfillment_status column is required for write
    if (!$hasOiCol('fulfillment_status')) { bv_seller_fulfillment_error('schema_missing','Fulfillment columns are not ready.',503); }

    // ── Determine ownership column ────────────────────────────────────────────
    $oiSellerCol = null;
    foreach (['seller_id','seller_user_id'] as $cand) {
        if ($hasOiCol($cand)) { $oiSellerCol=$cand; break; }
    }
    $hasListings     = bv_seller_fulfillment_table_exists($db,'listings');
    $listingOwnerCol = null;
    if ($oiSellerCol===null && $hasListings && $hasOiCol('listing_id')) {
        $lCols = bv_seller_fulfillment_columns($db,'listings');
        foreach (['seller_id','seller_user_id','user_id','owner_user_id'] as $cand) {
            if (in_array($cand,$lCols,true)) { $listingOwnerCol=$cand; break; }
        }
    }
    if ($oiSellerCol===null && $listingOwnerCol===null) {
        bv_seller_fulfillment_log('Cannot determine ownership column.');
        bv_seller_fulfillment_error('db_unavailable','Service temporarily unavailable.',503);
    }

    // ── Optional log tables ───────────────────────────────────────────────────
    $hasItemLogs    = bv_seller_fulfillment_table_exists($db,'order_item_logs');
    $hasFulfillLogs = bv_seller_fulfillment_table_exists($db,'order_fulfillment_logs');

    // ── BEGIN TRANSACTION ─────────────────────────────────────────────────────
    bv_sfa_begin($db);

    try {
        // ── Re-read order item inside transaction (FOR UPDATE if PDO) ─────────
        bv_seller_fulfillment_debug_log('ownership_check_start', [
            'order_item_id'      => $orderItemId,
            'effective_seller_id' => $filterSellerId,
        ]);
        // Build item SELECT
        $oiSelectCols = ['oi.id','oi.order_id'];
        foreach (['listing_id','fulfillment_status','tracking_number','carrier',
                  'processed_at','shipped_at','completed_at'] as $c) {
            if ($hasOiCol($c)) { $oiSelectCols[]="oi.`{$c}`"; }
        }
        if ($oiSellerCol!==null) { $oiSelectCols[]="oi.`{$oiSellerCol}`"; }

        if ($oiSellerCol!==null) {
            $oiRow = bv_sfa_row($db,
                'SELECT '.implode(',',$oiSelectCols)." FROM order_items oi WHERE oi.id=? AND oi.`{$oiSellerCol}`=? LIMIT 1",
                [$orderItemId,$filterSellerId]
            );
        } else {
            $oiRow = bv_sfa_row($db,
                'SELECT '.implode(',',$oiSelectCols)." FROM order_items oi
                 WHERE oi.id=? AND EXISTS(SELECT 1 FROM listings lx WHERE lx.id=oi.listing_id AND lx.`{$listingOwnerCol}`=?) LIMIT 1",
                [$orderItemId,$filterSellerId]
            );
        }

        if ($oiRow===null) {
            bv_seller_fulfillment_debug_log('ownership_check_result', ['found' => false]);
            bv_sfa_rollback($db);
            bv_seller_fulfillment_error('order_item_not_found','Order item not found.',404);
        }

        bv_seller_fulfillment_debug_log('ownership_check_result', [
            'found'                    => true,
            'order_id'                 => (int)$oiRow['order_id'],
            'current_fulfillment_status' => bv_sfa_clean($oiRow['fulfillment_status'] ?? ''),
        ]);

        $orderId = (int)$oiRow['order_id'];

        // ── Load parent order ─────────────────────────────────────────────────
        $orderSelectCols = ['o.id'];
        foreach (['status','payment_status','shipped_at','completed_at'] as $c) {
            if ($hasOCol($c)) { $orderSelectCols[]="o.`{$c}`"; }
        }
        $orderRow = bv_sfa_row($db,
            'SELECT '.implode(',',$orderSelectCols).' FROM orders o WHERE o.id=? LIMIT 1',
            [$orderId]
        );
        if ($orderRow===null) {
            bv_sfa_rollback($db);
            bv_seller_fulfillment_error('order_item_not_found','Order item not found.',404);
        }

        // ── Payment/status gate ───────────────────────────────────────────────
        $orderStatus = bv_sfa_clean($orderRow['status']??'');
        $payStatus   = bv_sfa_clean($orderRow['payment_status']??'');

        bv_seller_fulfillment_debug_log('order_ready_check', [
            'order_id'       => $orderId,
            'order_status'   => $orderStatus,
            'payment_status' => $payStatus,
        ]);

        $blockedStatuses = ['pending','pending_payment','reserved','cancelled','refunded'];
        $allowedStatuses = ['paid','paid-awaiting-verify','confirmed','processing','packing','shipped','completed'];
        $isPaid = $payStatus==='paid' || in_array($orderStatus,$allowedStatuses,true);

        if (!$isPaid || in_array($orderStatus,$blockedStatuses,true)) {
            bv_sfa_rollback($db);
            bv_seller_fulfillment_error('order_not_ready','This order is not ready for fulfillment.',409);
        }

        // ── State machine ─────────────────────────────────────────────────────
        // Normalize to lowercase to avoid case sensitivity issues from DB.
        $currentStatus    = strtolower(bv_sfa_clean($oiRow['fulfillment_status'] ?? ''));
        $existingTracking = bv_sfa_clean($oiRow['tracking_number'] ?? '');

        // ── Centralized transition table ──────────────────────────────────────
        // Maps: current_status -> action -> [ new_status | 'noop' | 'reject' ]
        // 'noop'   = idempotent success, no DB write needed for status
        // 'reject' = HTTP 409 invalid_transition
        // string   = new fulfillment_status to write
        $transitionTable = [
            ''            => ['process' => 'processing', 'ship' => 'shipped',   'complete' => 'completed'],
            'pending'     => ['process' => 'processing', 'ship' => 'shipped',   'complete' => 'completed'],
            'processing'  => ['process' => 'noop',       'ship' => 'shipped',   'complete' => 'completed'],
            'shipped'     => ['process' => 'reject',     'ship' => 'noop',      'complete' => 'completed'],
            'completed'   => ['process' => 'reject',     'ship' => 'reject',    'complete' => 'noop'],
            'cancelled'   => ['process' => 'reject',     'ship' => 'reject',    'complete' => 'reject'],
        ];

        // Resolve outcome — unknown current statuses fall back to the '' (empty) row
        // to allow forward progression, but reject any downgrade explicitly.
        $row = $transitionTable[$currentStatus] ?? null;
        if ($row === null) {
            // Unknown status: allow process/ship forward; reject complete unless clear path
            $row = ['process' => 'processing', 'ship' => 'shipped', 'complete' => 'reject'];
        }

        $outcome   = $row[$action] ?? 'reject';
        $isNoOp    = ($outcome === 'noop');
        $newStatus = $isNoOp ? $currentStatus : $outcome;

        if ($outcome === 'reject') {
            bv_sfa_rollback($db);
            bv_seller_fulfillment_error('invalid_transition','This fulfillment status cannot be changed with the requested action.',409);
        }

        // ── Pre-flight checks that depend on the resolved new status ──────────
        if ($newStatus === 'shipped') {
            // Require tracking unless it already exists on the item
            if ($inputTracking === '' && $existingTracking === '') {
                bv_sfa_rollback($db);
                bv_seller_fulfillment_error('tracking_required','Tracking number is required to mark as shipped.',400);
            }
        }

        $oldStatus = $currentStatus;
        $now       = date('Y-m-d H:i:s');

        bv_seller_fulfillment_debug_log('before_update', [
            'old_status' => $oldStatus,
            'action'     => $action,
            'new_status' => $newStatus,
        ]);

        // ── Build UPDATE for order_items ──────────────────────────────────────
        $setClauses = ["`fulfillment_status`=?"];
        $setParams  = [$newStatus];

        if ($action==='process' || !$isNoOp) {
            if ($hasOiCol('processed_at') && (bv_sfa_clean($oiRow['processed_at']??'')==='')) {
                $setClauses[] = '`processed_at`=?'; $setParams[] = $now;
            }
        }

        if (in_array($action,['ship'],true) || ($action==='complete' && !$isNoOp)) {
            if ($inputTracking!=='' && $hasOiCol('tracking_number')) {
                $setClauses[] = '`tracking_number`=?'; $setParams[] = $inputTracking;
            }
            if ($inputCarrier!=='' && $hasOiCol('carrier')) {
                $setClauses[] = '`carrier`=?'; $setParams[] = $inputCarrier;
            }
        }
        if ($isNoOp && $action==='ship') {
            // No-op ship: still update tracking/carrier if provided
            if ($inputTracking!=='' && $hasOiCol('tracking_number')) {
                $setClauses[] = '`tracking_number`=?'; $setParams[] = $inputTracking;
            }
            if ($inputCarrier!=='' && $hasOiCol('carrier')) {
                $setClauses[] = '`carrier`=?'; $setParams[] = $inputCarrier;
            }
        }

        if ($action==='ship') {
            if ($hasOiCol('shipped_at') && bv_sfa_clean($oiRow['shipped_at']??'')==='') {
                $setClauses[] = '`shipped_at`=?'; $setParams[] = $now;
            }
            if ($hasOiCol('processed_at') && bv_sfa_clean($oiRow['processed_at']??'')==='') {
                $setClauses[] = '`processed_at`=?'; $setParams[] = $now;
            }
        }

        if ($action==='complete') {
            if ($hasOiCol('completed_at') && bv_sfa_clean($oiRow['completed_at']??'')==='') {
                $setClauses[] = '`completed_at`=?'; $setParams[] = $now;
            }
            if ($hasOiCol('shipped_at') && bv_sfa_clean($oiRow['shipped_at']??'')==='') {
                $setClauses[] = '`shipped_at`=?'; $setParams[] = $now;
            }
            if ($hasOiCol('processed_at') && bv_sfa_clean($oiRow['processed_at']??'')==='') {
                $setClauses[] = '`processed_at`=?'; $setParams[] = $now;
            }
        }

        $setParams[] = $orderItemId;
        bv_sfa_exec($db,
            'UPDATE order_items SET '.implode(', ',$setClauses).' WHERE id=? LIMIT 1',
            $setParams
        );

        bv_seller_fulfillment_debug_log('after_update', ['success' => true]);

        // ── Re-read updated item ──────────────────────────────────────────────
        $updatedItem = bv_sfa_row($db,
            'SELECT id,order_id,'.
            implode(',', array_filter([
                $hasOiCol('listing_id')        ? 'listing_id'        : null,
                $hasOiCol('fulfillment_status') ? 'fulfillment_status' : null,
                $hasOiCol('tracking_number')   ? 'tracking_number'   : null,
                $hasOiCol('carrier')           ? 'carrier'           : null,
                $hasOiCol('processed_at')      ? 'processed_at'      : null,
                $hasOiCol('shipped_at')        ? 'shipped_at'        : null,
                $hasOiCol('completed_at')      ? 'completed_at'      : null,
            ])).
            ' FROM order_items WHERE id=? LIMIT 1',
            [$orderItemId]
        );

        // ── Optional order-status sync ────────────────────────────────────────
        $newOrderStatus = $orderStatus; // track what we set, if anything
        if ($hasOCol('status')) {
            // Load all items in this order (not just seller's)
            $allItems = bv_sfa_rows($db,
                'SELECT COALESCE(fulfillment_status,\'\') AS fs FROM order_items WHERE order_id=?',
                [$orderId]
            );
            $allStatuses = array_map(static fn($r)=>bv_sfa_clean($r['fs']??''), $allItems);
            $allStatuses = array_filter($allStatuses, static fn($s)=>$s!=='cancelled');

            $allCompleted = !empty($allStatuses) && count(array_filter($allStatuses,static fn($s)=>$s==='completed'))===count($allStatuses);
            $allShippedOrCompleted = !empty($allStatuses) && count(array_filter($allStatuses,static fn($s)=>in_array($s,['shipped','completed'],true)))===count($allStatuses);
            $anyActive = !empty(array_filter($allStatuses,static fn($s)=>in_array($s,['processing','shipped','completed'],true)));

            $blockedOrderUpdate = in_array($orderStatus,['cancelled','refunded'],true);

            // Orders status enum: pending,pending_payment,reserved,paid,paid-awaiting-verify,
            //   processing,confirmed,packing,shipped,completed,cancelled,refunded
            if (!$blockedOrderUpdate) {
                if ($allCompleted && $orderStatus!=='completed') {
                    $oSetParts = ['`status`=?'];
                    $oSetVals  = ['completed'];
                    if ($hasOCol('completed_at') && bv_sfa_clean($orderRow['completed_at']??'')==='') {
                        $oSetParts[] = '`completed_at`=?'; $oSetVals[] = $now;
                    }
                    $oSetVals[] = $orderId;
                    bv_sfa_exec($db,'UPDATE orders SET '.implode(', ',$oSetParts).' WHERE id=? LIMIT 1',$oSetVals);
                    $newOrderStatus = 'completed';
                } elseif ($allShippedOrCompleted && !in_array($orderStatus,['shipped','completed'],true)) {
                    $oSetParts = ['`status`=?'];
                    $oSetVals  = ['shipped'];
                    if ($hasOCol('shipped_at') && bv_sfa_clean($orderRow['shipped_at']??'')==='') {
                        $oSetParts[] = '`shipped_at`=?'; $oSetVals[] = $now;
                    }
                    $oSetVals[] = $orderId;
                    bv_sfa_exec($db,'UPDATE orders SET '.implode(', ',$oSetParts).' WHERE id=? LIMIT 1',$oSetVals);
                    $newOrderStatus = 'shipped';
                } elseif ($anyActive && in_array($orderStatus,['paid','paid-awaiting-verify','confirmed'],true)) {
                    bv_sfa_exec($db,'UPDATE orders SET `status`=? WHERE id=? LIMIT 1',['processing',$orderId]);
                    $newOrderStatus = 'processing';
                }
            }
        }

        // ── Logging (best-effort — never fail the transaction) ────────────────
        if ($hasItemLogs) {
            $ilCols = bv_seller_fulfillment_columns($db,'order_item_logs');
            $hasiLC = static fn(string $c): bool => in_array($c,$ilCols,true);
            $logCols=[]; $logVals=[];
            if ($hasiLC('order_item_id')) { $logCols[]='order_item_id'; $logVals[]=$orderItemId; }
            if ($hasiLC('order_id'))      { $logCols[]='order_id';      $logVals[]=$orderId; }
            if ($hasiLC('action'))        { $logCols[]='action';        $logVals[]=$action; }
            if ($hasiLC('old_status'))    { $logCols[]='old_status';    $logVals[]=$oldStatus; }
            if ($hasiLC('new_status'))    { $logCols[]='new_status';    $logVals[]=$newStatus; }
            if ($hasiLC('actor_type'))    { $logCols[]='actor_type';    $logVals[]=$userRole; }
            if ($hasiLC('actor_id'))      { $logCols[]='actor_id';      $logVals[]=$userId; }
            if (!empty($logCols)) {
                try {
                    $ph=implode(', ',array_fill(0,count($logVals),'?'));
                    $colList=implode(', ',array_map(static fn($c)=>"`{$c}`",$logCols));
                    bv_sfa_exec($db,"INSERT INTO order_item_logs ({$colList}) VALUES ({$ph})",$logVals);
                } catch (\Throwable $e) {
                    error_log('[BV Seller Fulfillment] log insert failed: '.$e->getMessage());
                }
            }
        }

        // ── COMMIT ────────────────────────────────────────────────────────────
        bv_sfa_commit($db);

    } catch (\Throwable $inner) {
        bv_sfa_rollback($db);
        bv_seller_fulfillment_log('Transaction error: '.$inner->getMessage().' in '.$inner->getFile().':'.$inner->getLine());
        bv_seller_fulfillment_error('server_error','Something went wrong.',500);
    }

    // ── Build response ────────────────────────────────────────────────────────
    $actionMessages = [
        'process'  => 'Fulfillment updated successfully.',
        'ship'     => 'Fulfillment updated successfully.',
        'complete' => 'Fulfillment updated successfully.',
    ];

    bv_seller_fulfillment_debug_log('success_response');
    bv_seller_fulfillment_json(200, [
        'ok'   => true,
        'data' => [
            'order_item' => [
                'id'                => $orderItemId,
                'order_id'          => $orderId,
                'listing_id'        => bv_sfa_int($updatedItem['listing_id']??0)?:null,
                'old_status'        => $oldStatus,
                'new_status'        => $newStatus,
                'fulfillment_status'=> bv_sfa_clean($updatedItem['fulfillment_status']??$newStatus),
                'tracking_number'   => bv_sfa_clean($updatedItem['tracking_number']??''),
                'carrier'           => bv_sfa_clean($updatedItem['carrier']??''),
                'processed_at'      => bv_sfa_clean($updatedItem['processed_at']??''),
                'shipped_at'        => bv_sfa_clean($updatedItem['shipped_at']??''),
                'completed_at'      => bv_sfa_clean($updatedItem['completed_at']??''),
            ],
            'order' => [
                'id'     => $orderId,
                'status' => $newOrderStatus,
            ],
            'action'  => $action,
            'message' => $actionMessages[$action] ?? 'Fulfillment updated successfully.',
        ],
        'meta' => [
            'api_version'  => 'v1',
            'generated_at' => gmdate('Y-m-d H:i:s'),
        ],
    ]);

} catch (\Throwable $e) {
    bv_seller_fulfillment_debug_log('exception', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
    bv_seller_fulfillment_log('Unhandled exception: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
    bv_seller_fulfillment_json(500, [
        'ok'    => false,
        'error' => ['code'=>'server_error','message'=>'Something went wrong.'],
    ]);
}
