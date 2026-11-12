<?php
/**
 * PUSHUP · MySQL 8.0 接口（单文件，无第三方依赖）
 *
 * 部署：上传到网站目录，例如 /www/wwwroot/example.com/pushup/api.php
 * 配置：同目录 config.php（或 config.local.php）
 * 自检：浏览器打开 https://你的域名/pushup/api.php?path=/ping
 *
 * App 侧只用一个地址：https://你的域名/pushup/api.php
 * 之后所有请求都是 api.php?path=<原 Supabase 路径>，不再有反向代理。
 *
 * 要求：PHP 7.4+ / 8.x，开启 pdo_mysql、json、mbstring（没有 mbstring 也能跑）
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Prefer, apikey, X-Client-Info');
header('Access-Control-Max-Age: 86400');
header('X-Content-Type-Options: nosniff');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

const ACCESS_TTL  = 604800;    // access token 7 天
const REFRESH_TTL = 15552000;  // refresh token 180 天

const TABLES = [
    'profiles' => [
        'cols' => ['id', 'name', 'emoji', 'created_at'],
        'json' => [],
        'dt'   => ['created_at'],
        'owner' => 'id',
        'public_read' => true,
    ],
    'posts' => [
        'cols' => ['id', 'uid', 'text', 'workout', 'ts'],
        'json' => ['workout'],
        'dt'   => [],
        'owner' => 'uid',
        'public_read' => true,
    ],
    'post_likes' => [
        'cols' => ['post_id', 'uid', 'ts'],
        'json' => [],
        'dt'   => ['ts'],
        'owner' => 'uid',
        'public_read' => true,
    ],
    'post_comments' => [
        'cols' => ['id', 'post_id', 'uid', 'text', 'ts'],
        'json' => [],
        'dt'   => [],
        'owner' => 'uid',
        'public_read' => true,
    ],
    'records' => [
        'cols' => ['uid', 'd', 'payload', 'updated_at'],
        'json' => ['payload'],
        'dt'   => ['updated_at'],
        'owner' => 'uid',
        'public_read' => false,
    ],
    'settings' => [
        'cols' => ['uid', 'favs', 'custom', 'cats', 'nickname', 'theme', 'palette', 'updated_at'],
        'json' => ['favs', 'custom', 'cats'],
        'dt'   => ['updated_at'],
        'owner' => 'uid',
        'public_read' => false,
    ],
];

$CFG = require __DIR__ . '/config.php';

try {
    route();
} catch (PDOException $e) {
    error_log('[pushup] db: ' . $e->getMessage());
    fail('数据库连接或执行失败', 500, 'db_error');
} catch (Throwable $e) {
    error_log('[pushup] ' . $e->getMessage());
    fail('服务器内部错误', 500, 'server_error');
}

/* ============================== 路由 ============================== */

function route(): void
{
    [$path, $q] = parse_path();
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($path === '/' || $path === '/ping') {
        $dbOk = false;
        try {
            db()->query('SELECT 1');
            $dbOk = true;
        } catch (Throwable $e) {
        }
        out(['ok' => true, 'service' => 'pushup-api', 'php' => PHP_VERSION, 'db' => $dbOk, 'time' => gmdate('c')]);
    }

    if (preg_match('#^/auth/v1/(signup|token|user|logout)$#', $path, $m)) {
        auth_route($m[1], $method, $q);
    }

    if (strpos($path, '/rest/v1/') === 0) {
        rest_route(substr($path, 9), $method, $q);
    }

    fail('接口不存在', 404, 'not_found');
}

function parse_path(): array
{
    $path = isset($_GET['path']) ? (string)$_GET['path'] : '';
    if ($path === '' && isset($_SERVER['PATH_INFO'])) {
        $path = (string)$_SERVER['PATH_INFO'];
    }
    $q = [];
    $pos = strpos($path, '?');
    if ($pos !== false) {
        parse_str(substr($path, $pos + 1), $q);
        $path = substr($path, 0, $pos);
    }
    foreach ($_GET as $k => $v) {
        if ($k === 'path') {
            continue;
        }
        if (!array_key_exists($k, $q)) {
            $q[$k] = $v;
        }
    }
    $path = '/' . ltrim($path, '/');
    $path = rtrim($path, '/');
    return [$path === '' ? '/' : $path, $q];
}

/* ============================== 基础工具 ============================== */

function db(): PDO
{
    global $CFG;
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $CFG['host'],
        (int)$CFG['port'],
        $CFG['name']
    );
    $pdo = new PDO($dsn, (string)$CFG['user'], (string)$CFG['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 8,
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}

function out($data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400, ?string $code = null): void
{
    $body = ['message' => $message];
    if ($code !== null) {
        $body['error_code'] = $code;
    }
    out($body, $status);
}

function body(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return $cached = [];
    }
    if (strlen($raw) > 4194304) {
        fail('请求体太大', 413, 'body_too_large');
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        fail('请求体不是合法 JSON', 400, 'bad_json');
    }
    return $cached = $j;
}

function bearer(): string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp((string)$k, 'Authorization') === 0) {
                $h = (string)$v;
                break;
            }
        }
    }
    if (preg_match('/^\s*Bearer\s+(.+)$/i', (string)$h, $m)) {
        return trim($m[1]);
    }
    return '';
}

function auth_user(bool $required = true): ?array
{
    $token = bearer();
    if ($token === '') {
        if ($required) {
            fail('未登录', 401, 'no_token');
        }
        return null;
    }
    $st = db()->prepare(
        'SELECT s.uid, s.expires_at, u.email, u.created_at, p.name, p.emoji
           FROM sessions s
           JOIN users u ON u.id = s.uid
      LEFT JOIN profiles p ON p.id = s.uid
          WHERE s.token = ? LIMIT 1'
    );
    $st->execute([$token]);
    $row = $st->fetch();
    if (!$row) {
        if ($required) {
            fail('登录已失效，请重新登录', 401, 'invalid_token');
        }
        return null;
    }
    if (strtotime((string)$row['expires_at'] . ' UTC') < time()) {
        if ($required) {
            fail('登录已过期，请重新登录', 401, 'expired_token');
        }
        return null;
    }
    return $row;
}

function iso($dt): ?string
{
    if ($dt === null || $dt === '') {
        return null;
    }
    $t = strtotime((string)$dt . ' UTC');
    return $t === false ? null : gmdate('Y-m-d\TH:i:s\Z', $t);
}

function to_dt($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    $t = strtotime((string)$v);
    return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
}

function str_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function str_cut(string $s, int $n): string
{
    return function_exists('mb_substr') ? mb_substr($s, 0, $n, 'UTF-8') : substr($s, 0, $n);
}

function json_val($v): ?string
{
    if ($v === null) {
        return null;
    }
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function json_col($v)
{
    if ($v === null || $v === '') {
        return null;
    }
    $d = json_decode((string)$v, true);
    return $d === null ? $v : $d;
}

/* ============================== 认证 ============================== */

function auth_route(string $action, string $method, array $q): void
{
    if ($action === 'user' && $method === 'GET') {
        $u = auth_user(true);
        out([
            'id' => $u['uid'],
            'email' => $u['email'],
            'created_at' => iso($u['created_at']),
            'user_metadata' => ['name' => $u['name'] ?? '', 'emoji' => $u['emoji'] ?? '💪'],
        ]);
    }

    if ($action === 'logout' && $method === 'POST') {
        $t = bearer();
        if ($t !== '') {
            db()->prepare('DELETE FROM sessions WHERE token = ?')->execute([$t]);
        }
        out(['ok' => true]);
    }

    if ($action === 'token' && $method === 'POST') {
        $grant = (string)($q['grant_type'] ?? 'password');
        if ($grant === 'refresh_token') {
            refresh_token();
        }
        password_login();
    }

    if ($action === 'signup' && $method === 'POST') {
        signup();
    }

    fail('方法不允许', 405, 'method_not_allowed');
}

function signup(): void
{
    $b = body();
    $email = strtolower(trim((string)($b['email'] ?? '')));
    $pass  = (string)($b['password'] ?? '');
    $data  = (isset($b['data']) && is_array($b['data'])) ? $b['data'] : [];
    $name  = trim((string)($data['name'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        fail('邮箱格式不对', 400, 'email_bad');
    }
    if ($pass === '') {
        fail('密码不能为空', 400, 'password_too_short');
    }
    if (str_len($pass) > 200) {
        fail('密码太长', 400, 'password_too_long');
    }
    if ($name === '') {
        $name = explode('@', $email)[0];
    }
    $name = str_cut($name, 60);

    $pdo = db();
    $st = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    if ($st->fetch()) {
        fail('User already registered', 422, 'user_already_exists');
    }
    $st = $pdo->prepare('SELECT id FROM profiles WHERE name = ? LIMIT 1');
    $st->execute([$name]);
    if ($st->fetch()) {
        fail('昵称已被使用', 409, 'name_taken');
    }

    $uid  = uuidv4();
    $hash = password_hash($pass, PASSWORD_BCRYPT);
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO users (id, email, password_hash) VALUES (?, ?, ?)')
            ->execute([$uid, $email, $hash]);
        $pdo->prepare('INSERT INTO profiles (id, name, emoji) VALUES (?, ?, ?)')
            ->execute([$uid, $name, '💪']);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[pushup] signup: ' . $e->getMessage());
        fail('注册失败，请稍后再试', 500, 'signup_failed');
    }

    issue_session([
        'uid' => $uid,
        'email' => $email,
        'name' => $name,
        'emoji' => '💪',
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
}

function password_login(): void
{
    $b = body();
    $email = strtolower(trim((string)($b['email'] ?? '')));
    $pass  = (string)($b['password'] ?? '');
    if ($email === '' || $pass === '') {
        fail('Invalid login credentials', 400, 'invalid_credentials');
    }
    $st = db()->prepare(
        'SELECT u.id AS uid, u.email, u.password_hash, u.created_at, p.name, p.emoji
           FROM users u LEFT JOIN profiles p ON p.id = u.id
          WHERE u.email = ? LIMIT 1'
    );
    $st->execute([$email]);
    $row = $st->fetch();
    if (!$row || !password_verify($pass, (string)$row['password_hash'])) {
        fail('Invalid login credentials', 400, 'invalid_credentials');
    }
    issue_session($row);
}

function refresh_token(): void
{
    $b = body();
    $rt = (string)($b['refresh_token'] ?? '');
    if ($rt === '') {
        fail('Invalid Refresh Token', 400, 'refresh_token_not_found');
    }
    $st = db()->prepare(
        'SELECT s.uid, s.refresh_expires_at, u.email, u.created_at, p.name, p.emoji
           FROM sessions s
           JOIN users u ON u.id = s.uid
      LEFT JOIN profiles p ON p.id = s.uid
          WHERE s.refresh_token = ? LIMIT 1'
    );
    $st->execute([$rt]);
    $row = $st->fetch();
    if (!$row || strtotime((string)$row['refresh_expires_at'] . ' UTC') < time()) {
        fail('Invalid Refresh Token', 400, 'refresh_token_not_found');
    }
    db()->prepare('DELETE FROM sessions WHERE refresh_token = ?')->execute([$rt]);
    issue_session($row);
}

function issue_session(array $user): void
{
    $token   = bin2hex(random_bytes(32));
    $refresh = bin2hex(random_bytes(32));
    $now     = time();
    db()->prepare(
        'INSERT INTO sessions (token, uid, refresh_token, expires_at, refresh_expires_at)
         VALUES (?, ?, ?, ?, ?)'
    )->execute([
        $token,
        $user['uid'],
        $refresh,
        gmdate('Y-m-d H:i:s', $now + ACCESS_TTL),
        gmdate('Y-m-d H:i:s', $now + REFRESH_TTL),
    ]);

    out([
        'access_token' => $token,
        'token_type' => 'bearer',
        'expires_in' => ACCESS_TTL,
        'refresh_token' => $refresh,
        'user' => [
            'id' => $user['uid'],
            'email' => $user['email'],
            'created_at' => iso($user['created_at'] ?? null) ?: gmdate('Y-m-d\TH:i:s\Z'),
            'user_metadata' => [
                'name' => $user['name'] ?? '',
                'emoji' => $user['emoji'] ?? '💪',
            ],
        ],
    ]);
}

function uuidv4(): string
{
    $d = random_bytes(16);
    $d[6] = chr((ord($d[6]) & 0x0f) | 0x40);
    $d[8] = chr((ord($d[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
}

/* ============================== REST ============================== */

function rest_route(string $table, string $method, array $q): void
{
    if ($table === 'rpc/lite_bundle') {
        bundle($method);
    }
    if (!isset(TABLES[$table])) {
        fail('数据表不存在', 404, 'table_not_found');
    }
    $def = TABLES[$table];
    switch ($method) {
        case 'GET':
            rest_get($table, $def, $q);
            break;
        case 'POST':
            rest_post($table, $def, $q);
            break;
        case 'PATCH':
            rest_patch($table, $def, $q);
            break;
        case 'DELETE':
            rest_delete($table, $def, $q);
            break;
        default:
            fail('方法不允许', 405, 'method_not_allowed');
    }
}

function filters(array $q, array $cols): array
{
    $out = [];
    foreach ($q as $k => $v) {
        if (in_array($k, ['select', 'limit', 'order', 'on_conflict'], true)) {
            continue;
        }
        if (!in_array($k, $cols, true) || !is_string($v)) {
            continue;
        }
        if (preg_match('/^(eq|neq|like|ilike)\.(.*)$/s', $v, $m)) {
            $out[$k] = [$m[1], $m[2]];
        }
    }
    return $out;
}

function where_sql(array $f, array $cols): array
{
    $w = [];
    $p = [];
    foreach ($f as $col => $pair) {
        if (!in_array($col, $cols, true)) {
            continue;
        }
        [$op, $val] = $pair;
        if ($op === 'eq') {
            $w[] = "`$col` = ?";
            $p[] = $val;
        } elseif ($op === 'neq') {
            $w[] = "`$col` <> ?";
            $p[] = $val;
        } else {
            $w[] = "`$col` LIKE ?";
            $p[] = $val;
        }
    }
    return [$w, $p];
}

function order_sql(array $q, array $cols): string
{
    if (empty($q['order']) || !is_string($q['order'])) {
        return '';
    }
    if (!preg_match('/^([A-Za-z0-9_]+)\.(asc|desc)$/i', $q['order'], $m)) {
        return '';
    }
    if (!in_array($m[1], $cols, true)) {
        return '';
    }
    return ' ORDER BY `' . $m[1] . '` ' . strtoupper($m[2]);
}

function limit_val(array $q, int $default): int
{
    $n = isset($q['limit']) ? (int)$q['limit'] : $default;
    return max(1, min(5000, $n));
}

function decorate_rows(array $rows, array $want, array $def, string $table): array
{
    foreach ($rows as &$row) {
        foreach ($want as $c) {
            if (in_array($c, $def['json'], true)) {
                $row[$c] = json_col($row[$c]);
            }
            if (in_array($c, $def['dt'], true)) {
                $row[$c] = iso($row[$c]);
            }
            if ($c === 'ts' && $table !== 'post_likes') {
                $row[$c] = (int)$row[$c];
            }
        }
    }
    unset($row);
    return $rows;
}

function rest_get(string $table, array $def, array $q): void
{
    $u = auth_user(!$def['public_read']);
    $cols = $def['cols'];

    $want = [];
    if (!empty($q['select']) && is_string($q['select'])) {
        foreach (explode(',', $q['select']) as $c) {
            $c = trim($c);
            if (in_array($c, $cols, true)) {
                $want[] = $c;
            }
        }
    }
    if (!$want) {
        $want = $cols;
    }

    $f = filters($q, $cols);
    /* 设置只允许读自己的；记录本来就是对所有登录用户公开（好友页统计要一次拿全部 uid+d） */
    if ($table === 'settings') {
        $f['uid'] = ['eq', $u['uid']];
    }

    [$w, $p] = where_sql($f, $cols);
    $sql = 'SELECT `' . implode('`,`', $want) . '` FROM `' . $table . '`'
        . ($w ? ' WHERE ' . implode(' AND ', $w) : '')
        . order_sql($q, $cols)
        . ' LIMIT ' . limit_val($q, $table === 'records' ? 5000 : 200);

    $st = db()->prepare($sql);
    $st->execute($p);
    out(decorate_rows($st->fetchAll(), $want, $def, $table));
}

function rest_post(string $table, array $def, array $q): void
{
    $u = auth_user(true);
    $rows = body();
    if (!is_array($rows) || $rows === []) {
        fail('请求体为空', 400, 'empty_body');
    }
    if (!isset($rows[0])) {
        $rows = [$rows];
    }

    $prefer = strtolower((string)($_SERVER['HTTP_PREFER'] ?? ''));
    $ignore = strpos($prefer, 'ignore-duplicates') !== false;
    $merge  = strpos($prefer, 'merge-duplicates') !== false || !empty($q['on_conflict']);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            if (is_array($row)) {
                insert_row($table, $def, $row, $u, $ignore, $merge);
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[pushup] insert ' . $table . ': ' . $e->getMessage());
        fail('写入失败（数据不合法或关联的动态不存在）', 400, 'insert_failed');
    }
    out([], 201);
}

function insert_row(string $table, array $def, array $row, array $u, bool $ignore, bool $merge): void
{
    $pdo = db();

    if ($table === 'profiles') {
        $name = trim((string)($row['name'] ?? ''));
        if ($name === '') {
            $name = (string)($u['name'] ?? '新朋友');
        }
        $name = str_cut($name, 60);
        $emoji = str_cut(trim((string)($row['emoji'] ?? '')), 16);
        if ($emoji === '') {
            $emoji = '💪';
        }
        $sql = 'INSERT INTO profiles (id, name, emoji) VALUES (?, ?, ?)';
        if ($ignore) {
            $sql .= ' ON DUPLICATE KEY UPDATE id = id';
        } elseif ($merge) {
            $sql .= ' ON DUPLICATE KEY UPDATE name = VALUES(name), emoji = VALUES(emoji)';
        }
        $pdo->prepare($sql)->execute([$u['uid'], $name, $emoji]);
        return;
    }

    if ($table === 'posts') {
        $id = (string)($row['id'] ?? '');
        if ($id === '') {
            fail('缺少动态 id', 400, 'missing_id');
        }
        $text = str_cut((string)($row['text'] ?? ''), 5000);
        $workout = array_key_exists('workout', $row) ? json_val($row['workout']) : null;
        $ts = (int)($row['ts'] ?? (int)round(microtime(true) * 1000));
        $pdo->prepare(
            'INSERT INTO posts (id, uid, text, workout, ts) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE text = VALUES(text), workout = VALUES(workout), ts = VALUES(ts)'
        )->execute([$id, $u['uid'], $text, $workout, $ts]);
        return;
    }

    if ($table === 'post_likes') {
        $postId = (string)($row['post_id'] ?? '');
        if ($postId === '') {
            fail('缺少 post_id', 400, 'missing_post_id');
        }
        $pdo->prepare(
            'INSERT INTO post_likes (post_id, uid, ts) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE ts = ts'
        )->execute([$postId, $u['uid'], gmdate('Y-m-d H:i:s')]);
        return;
    }

    if ($table === 'post_comments') {
        $id = (string)($row['id'] ?? '');
        $postId = (string)($row['post_id'] ?? '');
        if ($id === '' || $postId === '') {
            fail('缺少评论 id 或 post_id', 400, 'missing_id');
        }
        $text = str_cut((string)($row['text'] ?? ''), 2000);
        $ts = (int)($row['ts'] ?? (int)round(microtime(true) * 1000));
        $pdo->prepare(
            'INSERT INTO post_comments (id, post_id, uid, text, ts) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE text = VALUES(text)'
        )->execute([$id, $postId, $u['uid'], $text, $ts]);
        return;
    }

    if ($table === 'records') {
        $d = (string)($row['d'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            fail('日期格式应为 YYYY-MM-DD', 400, 'bad_date');
        }
        $payload = json_val($row['payload'] ?? null);
        if ($payload === null) {
            $payload = '{}';
        }
        $upd = to_dt($row['updated_at'] ?? null) ?: gmdate('Y-m-d H:i:s');
        $pdo->prepare(
            'INSERT INTO records (uid, d, payload, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)'
        )->execute([$u['uid'], $d, $payload, $upd]);
        return;
    }

    if ($table === 'settings') {
        $fields = ['favs', 'custom', 'cats', 'nickname', 'theme', 'palette'];
        $cols = ['uid'];
        $vals = [$u['uid']];
        $ups  = [];
        foreach ($fields as $f) {
            if (!array_key_exists($f, $row)) {
                continue;
            }
            $cols[] = $f;
            if (in_array($f, ['favs', 'custom', 'cats'], true)) {
                $vals[] = json_val($row[$f]);
            } else {
                $vals[] = $row[$f] === null ? null : str_cut((string)$row[$f], $f === 'nickname' ? 60 : 20);
            }
            $ups[] = "`$f` = VALUES(`$f`)";
        }
        $cols[] = 'updated_at';
        $vals[] = to_dt($row['updated_at'] ?? null) ?: gmdate('Y-m-d H:i:s');
        $ups[] = '`updated_at` = VALUES(`updated_at`)';
        $sql = 'INSERT INTO settings (`' . implode('`,`', $cols) . '`) VALUES ('
            . implode(',', array_fill(0, count($cols), '?')) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $ups);
        $pdo->prepare($sql)->execute($vals);
        return;
    }

    fail('不支持写入该表', 400, 'not_writable');
}

function rest_patch(string $table, array $def, array $q): void
{
    $u = auth_user(true);
    $row = body();
    $f = filters($q, $def['cols']);
    if ($def['owner'] === 'uid') {
        $f['uid'] = ['eq', $u['uid']];
    } else {
        $f['id'] = ['eq', $u['uid']];
    }

    $allowed = [
        'profiles' => ['name', 'emoji'],
        'posts' => ['text', 'workout'],
        'post_comments' => ['text'],
        'records' => ['payload', 'updated_at'],
        'settings' => ['favs', 'custom', 'cats', 'nickname', 'theme', 'palette', 'updated_at'],
    ];
    $fields = $allowed[$table] ?? [];
    $set = [];
    $args = [];
    foreach ($fields as $fname) {
        if (!array_key_exists($fname, $row)) {
            continue;
        }
        $set[] = "`$fname` = ?";
        if (in_array($fname, $def['json'], true)) {
            $args[] = json_val($row[$fname]);
        } elseif ($fname === 'updated_at') {
            $args[] = to_dt($row[$fname]) ?: gmdate('Y-m-d H:i:s');
        } else {
            $args[] = $row[$fname] === null ? null : str_cut((string)$row[$fname], $fname === 'name' || $fname === 'nickname' ? 60 : 5000);
        }
    }
    if (!$set) {
        fail('没有可更新的字段', 400, 'nothing_to_update');
    }

    [$w, $p] = where_sql($f, $def['cols']);
    if (!$w) {
        fail('缺少更新条件', 400, 'missing_filter');
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $set) . ' WHERE ' . implode(' AND ', $w);
    db()->prepare($sql)->execute(array_merge($args, $p));
    out([], 200);
}

function rest_delete(string $table, array $def, array $q): void
{
    $u = auth_user(true);
    $f = filters($q, $def['cols']);
    if ($def['owner'] === 'uid') {
        $f['uid'] = ['eq', $u['uid']];
    } else {
        $f['id'] = ['eq', $u['uid']];
    }
    [$w, $p] = where_sql($f, $def['cols']);
    if (!$w) {
        fail('缺少删除条件', 400, 'missing_filter');
    }
    db()->prepare('DELETE FROM `' . $table . '` WHERE ' . implode(' AND ', $w))->execute($p);
    out([], 200);
}

function bundle(string $method): void
{
    if ($method !== 'POST') {
        fail('方法不允许', 405, 'method_not_allowed');
    }
    /* 动态 / 资料 / 点赞 / 评论本来就是公开数据；不登录也允许拉，
       这样启动时的第一拉不会 401，省下「退回四表」的 4 倍请求。 */
    auth_user(false);
    $pdo = db();

    $profiles = $pdo->query('SELECT id, name, emoji, created_at FROM profiles ORDER BY created_at ASC LIMIT 200')->fetchAll();
    $profiles = decorate_rows($profiles, ['id', 'name', 'emoji', 'created_at'], TABLES['profiles'], 'profiles');

    $posts = $pdo->query('SELECT id, uid, text, workout, ts FROM posts ORDER BY ts DESC LIMIT 30')->fetchAll();
    $posts = decorate_rows($posts, ['id', 'uid', 'text', 'workout', 'ts'], TABLES['posts'], 'posts');

    $likes = $pdo->query('SELECT post_id, uid, ts FROM post_likes ORDER BY ts ASC LIMIT 800')->fetchAll();
    $likes = decorate_rows($likes, ['post_id', 'uid', 'ts'], TABLES['post_likes'], 'post_likes');

    $comments = $pdo->query('SELECT id, post_id, uid, text, ts FROM post_comments ORDER BY ts ASC LIMIT 800')->fetchAll();
    $comments = decorate_rows($comments, ['id', 'post_id', 'uid', 'text', 'ts'], TABLES['post_comments'], 'post_comments');

    out(['profiles' => $profiles, 'posts' => $posts, 'likes' => $likes, 'comments' => $comments]);
}
