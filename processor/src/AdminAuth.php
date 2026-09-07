<?php

declare(strict_types=1);

namespace Apigate;

/**
 * 管理端账号：用户名 + 密码登录，HttpOnly Cookie 会话。
 * 首次访问进入安装向导（建管理员、建默认应用、初始化 cron）。
 */
final class AdminAuth
{
    public const COOKIE = 'gk_admin';
    public const TTL = 7 * 86400; // 会话有效期：7 天
    private const FAIL_KEY = 'gk:login:fail:%s';
    public const MAX_ATTEMPTS = 5;
    public const ATTEMPT_WINDOW = 300; // 秒

    /** 是否已安装（存在管理员账号即视为已初始化）。 */
    public static function installed(): bool
    {
        $row = Db::pdo()->query('SELECT COUNT(*) c FROM admin_users')->fetch();
        return (int) ($row['c'] ?? 0) > 0;
    }

    /**
     * 安装向导：创建管理员 + 默认应用 + 初始化 cron。
     *
     * @return array{username:string, cron:string, cron_ok:bool, cron_php:string}
     */
    public static function install(string $username, string $password): array
    {
        if (self::installed()) {
            throw new \RuntimeException('系统已安装，请直接登录');
        }
        $username = trim($username);
        if ($username === '' || mb_strlen($username) > 64) {
            throw new \RuntimeException('用户名不能为空且不超过 64 字符');
        }
        if (strlen($password) < 8) {
            throw new \RuntimeException('密码至少 8 位');
        }

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)')
                ->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);

            // 零配置可用：确保存在 base_path=/ 的默认应用
            $exists = $pdo->query('SELECT id FROM apps WHERE base_path = \'/\' ORDER BY id ASC LIMIT 1')->fetch();
            if ($exists === false) {
                $pdo->prepare(
                    'INSERT INTO apps (name, base_path, match_mode, match_value, default_allow,
                                      default_cache_enabled, default_cache_ttl, auto_add_rules, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([
                    '默认应用', '/', 'prefix', '', 1, 0, 60, 1, 1,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        App::invalidate();

        $cron = self::setupCron();
        return [
            'username' => $username,
            'cron' => $cron['line'],
            'cron_ok' => $cron['ok'],
            'cron_php' => $cron['php'],
        ];
    }

    /** 登录成功返回会话随机值（HttpOnly Cookie 只存它）；失败返回 null。 */
    public static function login(string $username, string $password): ?string
    {
        $stmt = Db::pdo()->prepare('SELECT id, password_hash FROM admin_users WHERE username = ?');
        $stmt->execute([trim($username)]);
        $row = $stmt->fetch();
        if ($row === false || !password_verify($password, (string) $row['password_hash'])) {
            return null;
        }
        $token = bin2hex(random_bytes(32));
        Db::pdo()->prepare('INSERT INTO admin_sessions (token_hash, user_id, expires_at) VALUES (?, ?, datetime(\'now\', ?))')
            ->execute([hash('sha256', $token), (int) $row['id'], '+' . self::TTL . ' seconds']);
        return $token;
    }

    /** 登录失败计数（按客户端 IP，固定窗口防爆破）。 */
    public static function failCount(string $ip): int
    {
        return (int) Cache::get(sprintf(self::FAIL_KEY, $ip), 0);
    }

    public static function registerFailure(string $ip): void
    {
        Cache::incr(sprintf(self::FAIL_KEY, $ip), 1, self::ATTEMPT_WINDOW);
    }

    public static function resetFailures(string $ip): void
    {
        Cache::delete(sprintf(self::FAIL_KEY, $ip));
    }

    /** 修改当前用户密码；成功返回 true。旧会话中仅保留当前这一个。 */
    public static function changePassword(int $userId, string $oldPassword, string $newPassword): bool
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if ($row === false || !password_verify($oldPassword, (string) $row['password_hash'])) {
            return false;
        }
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
        if ($token !== '') {
            $pdo->prepare('DELETE FROM admin_sessions WHERE user_id = ? AND token_hash != ?')
                ->execute([$userId, hash('sha256', $token)]);
        }
        return true;
    }

    /** 当前会话用户 id；未登录返回 0。 */
    public static function currentUserId(): int
    {
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($token === '') {
            return 0;
        }
        $stmt = Db::pdo()->prepare(
            'SELECT user_id FROM admin_sessions WHERE token_hash = ? AND expires_at > datetime(\'now\')'
        );
        $stmt->execute([hash('sha256', $token)]);
        $row = $stmt->fetch();
        return $row === false ? 0 : (int) $row['user_id'];
    }

    /** 登出：删除会话并清理过期会话。 */
    public static function logout(): void
    {
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($token !== '') {
            Db::pdo()->prepare('DELETE FROM admin_sessions WHERE token_hash = ?')
                ->execute([hash('sha256', $token)]);
        }
        Db::pdo()->exec('DELETE FROM admin_sessions WHERE expires_at <= datetime(\'now\')');
    }

    public static function cookieHeader(string $token, int $maxAge = self::TTL): string
    {
        $expire = $maxAge > 0 ? '; Max-Age=' . $maxAge : '; Max-Age=0';
        return sprintf(
            '%s=%s; Path=/admin; HttpOnly; SameSite=Lax%s',
            self::COOKIE,
            rawurlencode($token),
            $expire
        );
    }

    /**
     * 初始化导入器 cron（尽力而为；失败时把命令返回给安装向导展示）。
     *
     * @return array{line:string, ok:bool, php:string}
     */
    private static function setupCron(): array
    {
        $web = dirname(APIGATE_ROOT);
        $php = self::resolvePhp();
        $log = $web . '/data/logs/import.log';
        $line = sprintf(
            '* * * * * cd %s && %s processor/import.php >> %s 2>&1',
            escapeshellarg($web),
            $php['cmd'],
            escapeshellarg($log)
        );
        $ok = false;
        $payload = '';
        $out = trim((string) (shell_exec('crontab -l 2>/dev/null') ?: ''));
        foreach (explode("\n", $out) as $l) {
            $l = trim($l);
            if ($l !== '' && !str_contains($l, 'processor/import.php')) {
                $payload .= $l . "\n";
            }
        }
        $payload .= $line . "\n";
        exec('printf %s ' . escapeshellarg($payload) . ' | crontab - 2>&1', $o, $rc);
        $ok = $rc === 0;
        return ['line' => $line, 'ok' => $ok, 'php' => $php['cmd']];
    }

    /** @return array{cmd:string} */
    private static function resolvePhp(): array
    {
        $candidates = [];
        $configured = (string) Config::get('cron.php_bin', '');
        if ($configured !== '') {
            $candidates[] = $configured;
        }
        $env = (string) (getenv('APIGATE_PHP_BIN') ?: '');
        if ($env !== '') {
            $candidates[] = $env;
        }
        foreach (['/root/frampp/bin/php', '/opt/frampp/bin/php', '/usr/local/frampp/bin/php'] as $p) {
            $candidates[] = $p;
        }
        foreach ((array) (glob('/home/*/frampp/bin/php') ?: []) as $p) {
            $candidates[] = $p;
        }
        $which = trim((string) (shell_exec('command -v php 2>/dev/null') ?: ''));
        if ($which !== '') {
            $candidates[] = $which;
        }
        if (defined('PHP_BINARY') && is_string(PHP_BINARY) && PHP_BINARY !== '') {
            $candidates[] = PHP_BINARY;
        }
        foreach ($candidates as $cand) {
            $cand = trim($cand);
            if ($cand === '' || !is_executable($cand)) {
                continue;
            }
            $base = basename($cand);
            if (str_contains($base, 'frankenphp')) {
                return ['cmd' => escapeshellarg($cand) . ' php-cli'];
            }
            return ['cmd' => escapeshellarg($cand)];
        }
        return ['cmd' => 'php']; // 最后兜底（PATH 中的 php）
    }
}
