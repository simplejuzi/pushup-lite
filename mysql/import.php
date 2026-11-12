<?php
/**
 * PUSHUP · 把 Supabase 导出的 JSON 灌进 MySQL 8.0
 *
 * 用法（在服务器命令行执行，不要在浏览器打开）：
 *     php import.php supabase-dump.json
 *
 * 幂等：重复执行不会产生重复数据（按主键 upsert）。
 * 顺序：users → profiles → posts → likes/comments → records/settings
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("只在命令行运行\n");
}

if ($argc < 2) {
    fwrite(STDERR, "用法：php import.php supabase-dump.json\n");
    exit(1);
}

$file = $argv[1];
if (!is_file($file)) {
    fwrite(STDERR, "找不到文件：$file\n");
    exit(1);
}

require __DIR__ . '/config.php';

$raw = file_get_contents($file);
$dump = json_decode((string)$raw, true);
if (!is_array($dump)) {
    fwrite(STDERR, "JSON 解析失败，确认复制的是完整的一格（不是截断的）\n");
    exit(1);
}

function to_dt($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    $t = strtotime((string)$v);
    return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
}

function j($v): ?string
{
    return $v === null ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $cfg['host'],
    (int)$cfg['port'],
    $cfg['name']
);
$pdo = new PDO($dsn, (string)$cfg['user'], (string)$cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone = '+00:00'");

$stat = ['users' => 0, 'profiles' => 0, 'records' => 0, 'posts' => 0, 'likes' => 0, 'comments' => 0, 'settings' => 0];
$skipped = 0;
$needReset = [];

echo "连接成功：{$cfg['name']} @ {$cfg['host']}\n";

/* ---------- users ---------- */
$pdo->beginTransaction();
foreach (($dump['users'] ?? []) as $u) {
    $id = (string)($u['id'] ?? '');
    $email = strtolower(trim((string)($u['email'] ?? '')));
    if ($id === '' || $email === '') {
        $skipped++;
        continue;
    }
    $hash = (string)($u['password_hash'] ?? '');
    if ($hash === '') {
        $hash = password_hash(bin2hex(random_bytes(12)), PASSWORD_BCRYPT);
        $needReset[] = $email;
    }
    $created = to_dt($u['created_at'] ?? null) ?: gmdate('Y-m-d H:i:s');
    $pdo->prepare(
        'INSERT INTO users (id, email, password_hash, created_at) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE email = VALUES(email), password_hash = VALUES(password_hash)'
    )->execute([$id, $email, $hash, $created]);
    $stat['users']++;
}
$pdo->commit();

/* ---------- profiles ---------- */
$pdo->beginTransaction();
foreach (($dump['profiles'] ?? []) as $p) {
    $id = (string)($p['id'] ?? '');
    if ($id === '') {
        $skipped++;
        continue;
    }
    $name = trim((string)($p['name'] ?? ''));
    if ($name === '') {
        $name = '新朋友';
    }
    $emoji = trim((string)($p['emoji'] ?? ''));
    if ($emoji === '') {
        $emoji = '💪';
    }
    $created = to_dt($p['created_at'] ?? null) ?: gmdate('Y-m-d H:i:s');
    try {
        $pdo->prepare(
            'INSERT INTO profiles (id, name, emoji, created_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE name = VALUES(name), emoji = VALUES(emoji)'
        )->execute([$id, $name, $emoji, $created]);
        $stat['profiles']++;
    } catch (PDOException $e) {
        $skipped++;
    }
}
$pdo->commit();

/* ---------- posts ---------- */
$pdo->beginTransaction();
foreach (($dump['posts'] ?? []) as $p) {
    $id = (string)($p['id'] ?? '');
    $uid = (string)($p['uid'] ?? '');
    if ($id === '' || $uid === '') {
        $skipped++;
        continue;
    }
    try {
        $pdo->prepare(
            'INSERT INTO posts (id, uid, text, workout, ts) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE text = VALUES(text), workout = VALUES(workout), ts = VALUES(ts)'
        )->execute([
            $id,
            $uid,
            (string)($p['text'] ?? ''),
            j($p['workout'] ?? null),
            (int)($p['ts'] ?? 0),
        ]);
        $stat['posts']++;
    } catch (PDOException $e) {
        $skipped++;
    }
}
$pdo->commit();

/* ---------- likes ---------- */
$pdo->beginTransaction();
foreach (($dump['likes'] ?? []) as $l) {
    $postId = (string)($l['post_id'] ?? '');
    $uid = (string)($l['uid'] ?? '');
    if ($postId === '' || $uid === '') {
        $skipped++;
        continue;
    }
    try {
        $pdo->prepare(
            'INSERT IGNORE INTO post_likes (post_id, uid, ts) VALUES (?, ?, ?)'
        )->execute([$postId, $uid, to_dt($l['ts'] ?? null) ?: gmdate('Y-m-d H:i:s')]);
        $stat['likes']++;
    } catch (PDOException $e) {
        $skipped++;
    }
}
$pdo->commit();

/* ---------- comments ---------- */
$pdo->beginTransaction();
foreach (($dump['comments'] ?? []) as $c) {
    $id = (string)($c['id'] ?? '');
    $postId = (string)($c['post_id'] ?? '');
    $uid = (string)($c['uid'] ?? '');
    if ($id === '' || $postId === '' || $uid === '') {
        $skipped++;
        continue;
    }
    try {
        $pdo->prepare(
            'INSERT INTO post_comments (id, post_id, uid, text, ts) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE text = VALUES(text)'
        )->execute([$id, $postId, $uid, (string)($c['text'] ?? ''), (int)($c['ts'] ?? 0)]);
        $stat['comments']++;
    } catch (PDOException $e) {
        $skipped++;
    }
}
$pdo->commit();

/* ---------- records ---------- */
$pdo->beginTransaction();
foreach (($dump['records'] ?? []) as $r) {
    $uid = (string)($r['uid'] ?? '');
    $d = (string)($r['d'] ?? '');
    if ($uid === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $skipped++;
        continue;
    }
    try {
        $pdo->prepare(
            'INSERT INTO records (uid, d, payload, updated_at) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)'
        )->execute([
            $uid,
            $d,
            j($r['payload'] ?? new stdClass()) ?: '{}',
            to_dt($r['updated_at'] ?? null) ?: gmdate('Y-m-d H:i:s'),
        ]);
        $stat['records']++;
    } catch (PDOException $e) {
        $skipped++;
    }
}
$pdo->commit();

/* ---------- settings ---------- */
$pdo->beginTransaction();
foreach (($dump['settings'] ?? []) as $s) {
    $uid = (string)($s['uid'] ?? '');
    if ($uid === '') {
        $skipped++;
        continue;
    }
    try {
        $pdo->prepare(
            'INSERT INTO settings (uid, favs, custom, cats, nickname, theme, palette, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE favs = VALUES(favs), custom = VALUES(custom), cats = VALUES(cats),
               nickname = VALUES(nickname), theme = VALUES(theme), palette = VALUES(palette)'
        )->execute([
            $uid,
            j($s['favs'] ?? null),
            j($s['custom'] ?? null),
            j($s['cats'] ?? null),
            $s['nickname'] ?? null,
            $s['theme'] ?? null,
            $s['palette'] ?? null,
            to_dt($s['updated_at'] ?? null) ?: gmdate('Y-m-d H:i:s'),
        ]);
        $stat['settings']++;
    } catch (PDOException $e) {
        $skipped++;
    }
}
$pdo->commit();

echo "导入完成：\n";
foreach ($stat as $k => $v) {
    echo "  $k: $v\n";
}
echo "  跳过（关联数据缺失 / 字段不合法）: $skipped\n";
if ($needReset) {
    echo "\n以下账号没有密码（第三方登录或旧数据），需要重置密码：\n";
    foreach ($needReset as $mail) {
        echo "  - $mail\n";
    }
}
echo "\n下一步：浏览器打开 https://你的域名/pushup/api.php?path=/ping 确认 db=true\n";
