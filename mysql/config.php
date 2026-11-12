<?php
/**
 * PUSHUP · 数据库配置
 *
 * 优先读环境变量（推荐，密码不落文件）：
 *   PUSHUP_DB_HOST / PUSHUP_DB_PORT / PUSHUP_DB_NAME / PUSHUP_DB_USER / PUSHUP_DB_PASS
 *
 * 没有环境变量时用下面的默认值。也可以在同目录建 config.local.php，
 * 只写需要覆盖的键（这个文件不要提交到仓库）。
 */

$cfg = [
    'host' => getenv('PUSHUP_DB_HOST') ?: '127.0.0.1',
    'port' => (int)(getenv('PUSHUP_DB_PORT') ?: 3306),
    'name' => getenv('PUSHUP_DB_NAME') ?: 'pushup',
    'user' => getenv('PUSHUP_DB_USER') ?: 'pushup',
    'pass' => getenv('PUSHUP_DB_PASS') ?: '改成你的数据库密码',
];

if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) {
        $cfg = array_merge($cfg, $local);
    }
}

return $cfg;
