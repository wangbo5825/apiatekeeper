<?php

/**
 * 管理端密码命令行工具（本地运维用）。
 *
 * 用法：
 *   php processor/admin-passwd.php list
 *     列出已存在的管理员用户名
 *   php processor/admin-passwd.php <用户名> <新密码>
 *     未安装时：初始化管理员（同时创建默认应用并尝试注册 cron）
 *     已安装时：重置该用户密码（用户不存在则报错）
 *
 * 密码至少 8 位。也可用环境变量 GK_ADMIN_USER / GK_ADMIN_PASS 传参
 * （兼容 frankenphp php-cli 偶发不传 argv 的情况）。
 */

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Apigate\AdminAuth;
use Apigate\Db;

$username = (string) ($argv[1] ?? getenv('GK_ADMIN_USER') ?: '');
$password = (string) ($argv[2] ?? getenv('GK_ADMIN_PASS') ?: '');

if ($username === 'list') {
    $rows = Db::pdo()->query('SELECT id, username, created_at FROM admin_users ORDER BY id ASC')->fetchAll();
    if ($rows === []) {
        echo "(no admin users; system not installed)\n";
        exit(0);
    }
    foreach ($rows as $r) {
        printf("#%d  %s  (created %s)\n", (int) $r['id'], $r['username'], $r['created_at']);
    }
    exit(0);
}

if ($username === '' || $password === '') {
    fwrite(STDERR, "Usage: php admin-passwd.php <username> <new-password> (at least 8 chars)\n");
    fwrite(STDERR, "       or php admin-passwd.php list\n");
    exit(2);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "!! Password must be at least 8 characters\n");
    exit(1);
}

$pdo = Db::pdo();

if (!AdminAuth::installed()) {
    // 未安装：等同执行安装向导的命令行版（建管理员 + 默认应用 + cron）
    try {
        $r = AdminAuth::install($username, $password);
    } catch (\Throwable $e) {
        fwrite(STDERR, "!! Initialization failed: " . $e->getMessage() . "\n");
        exit(1);
    }
    echo "Admin initialized: {$r['username']}\n";
    echo $r['cron_ok'] ? "cron registered\n" : "cron not registered; add it manually:\n{$r['cron']}\n";
    exit(0);
}

// 已安装：重置指定用户密码
$stmt = $pdo->prepare('SELECT id FROM admin_users WHERE username = ?');
$stmt->execute([$username]);
if ($stmt->fetch() === false) {
    fwrite(STDERR, "!! User not found: {$username}\n");
    fwrite(STDERR, "   Existing users: ");
    $names = array_column(
        Db::pdo()->query('SELECT username FROM admin_users ORDER BY id ASC')->fetchAll(),
        'username'
    );
    fwrite(STDERR, implode(' / ', $names) . "\n");
    exit(1);
}

$pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE username = ?')
    ->execute([password_hash($password, PASSWORD_DEFAULT), $username]);
// 重置后清掉该用户所有会话，强制重新登录
$pdo->prepare(
    'DELETE FROM admin_sessions WHERE user_id = (SELECT id FROM admin_users WHERE username = ?)'
)->execute([$username]);

echo "Password for user {$username} has been reset (all existing sessions invalidated)\n";
