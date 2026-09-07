<?php

/**
 * ApiGateKeeper Web 安装器（FRAMPP 0.6.x）——【仅开发环境自举使用】
 *
 * 安全风险声明（不建议在生产使用）：
 *   - 安装器本身是代码执行载体，安装窗口期内可被触发
 *   - 解压目录位于 web 根下：data/ 中的 SQLite 与日志可作为静态文件
 *     被直接下载（含明文令牌），存在数据泄露风险
 *   - 特权操作（写 Caddyfile / 重启服务）最终仍需 SSH/root 完成，
 *     并未真正实现"免 SSH"；正式安装请使用 deploy/install-frampp.sh
 *
 * 正式安装方式：命令行 ./deploy/install-frampp.sh（装到 FRAMPP_HOME/apps，
 * 不在 web 根下，无上述风险）。本文件默认不随发布包生成
 * （package.sh 需 WEB_INSTALL=1 才输出）。
 *
 * 用法：
 *   1. 用 deploy/package.sh 生成 dist/gatekeeper-webinstall/
 *   2. 把 install.php 与 gatekeeper-package.tar.gz 复制到 FRAMPP 的 web 目录
 *      （如 $FRAMPP_HOME/htdocs/）
 *   3. 浏览器访问 http://<host>:8080/install.php
 *   4. 安装完成后再次访问本文件，会显示"安装已完成"提示
 *
 * 安全：安装完成后请删除 install.php 与 gatekeeper-package.tar.gz；
 *      安装锁（data/installed.lock）存在时本脚本拒绝执行。
 */

declare(strict_types=1);

/** 可选安装密钥：非空时需 ?key=xxx 才能安装（推荐设置）。 */
const INSTALL_KEY = '';
/** 打包文件名（与 install.php 同目录）。 */
const PACKAGE_FILE = __DIR__ . '/gatekeeper-package.tar.gz';
/** 解压目标：当前 web 目录下的该子目录。 */
const APP_DIR_NAME = 'gatekeeper';
/** Gatekeeper 监听端口。 */
const GATEKEEPER_PORT = 8090;
/** 兜底上游（host:port，不带 scheme——Caddy 动态上游限制）。 */
const GATEKEEPER_UPSTREAM = '127.0.0.1:9000';

error_reporting(E_ALL);
ini_set('display_errors', '0');

/** 向上查找 FRAMPP_HOME（含 bin/frampp 的目录）。 */
function find_frampp_home(string $start): ?string
{
    $dir = realpath($start) ?: $start;
    for ($i = 0; $i < 5 && $dir !== false && $dir !== ''; $i++) {
        if (is_file($dir . '/bin/frampp') && is_file($dir . '/etc/Caddyfile')) {
            return $dir;
        }
        $dir = dirname($dir);
    }
    return null;
}

function page(string $title, string $body, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $esc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    echo "<!doctype html><html lang='zh-CN'><head><meta charset='utf-8'>"
        . "<meta name='viewport' content='width=device-width,initial-scale=1'>"
        . "<title>{$esc}</title>"
        . "<style>body{font-family:system-ui,'Microsoft YaHei',sans-serif;background:#f5f6f8;color:#222;margin:0;padding:40px 16px}"
        . ".box{max-width:640px;margin:0 auto;background:#fff;border-radius:10px;padding:24px 28px;box-shadow:0 1px 4px rgba(0,0,0,.1)}"
        . "h1{font-size:19px;margin:0 0 14px}pre{background:#f3f4f6;padding:10px;border-radius:6px;overflow:auto;font-size:13px}"
        . "code{background:#f3f4f6;padding:1px 5px;border-radius:4px;font-size:13px}"
        . ".ok{color:#166534;font-weight:600}.err{color:#991b1b;font-weight:600}.warn{background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:10px 12px;font-size:13px;color:#92400e;margin:12px 0}"
        . "li{margin:4px 0;font-size:14px}</style></head><body><div class='box'><h1>{$esc}</h1>{$body}</div></body></html>";
    exit(0);
}

function fail(string $msg): never
{
    page('安装失败', '<p class="err">' . nl2br(htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')) . '</p>');
}

function rand_hex(int $bytes): string
{
    return bin2hex(random_bytes($bytes));
}

/** 渲染 caddyfile 片段占位符。 */
function render_caddy(
    string $template,
    string $target,
    int $port,
    string $secret,
    string $upstream,
    string $loopback
): string {
    // Caddy 占位符上游只允许 host:port（不能带 scheme），此处兜底规范化
    $upstream = preg_replace('#^https?://#i', '', $upstream) ?? $upstream;
    $upstream = rtrim($upstream, '/');
    return strtr($template, [
        '{{PORT}}' => (string) $port,
        '{{PROCESSOR_SECRET}}' => $secret,
        '{{UPSTREAM}}' => $upstream,
        '{{PHP_ROOT}}' => $target,
        '{{WORKER_PHP}}' => $target . '/processor/worker.php',
        '{{ADMIN_DIST}}' => $target . '/admin-web/dist',
        '{{LOOPBACK_BASE}}' => $loopback,
    ]);
}

$webRoot = __DIR__;
$framppHome = find_frampp_home($webRoot);
$target = $webRoot . '/' . APP_DIR_NAME;
$lock = $target . '/data/installed.lock';
$host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
$port = GATEKEEPER_PORT;

// ---------- 已安装 ----------
if (is_file($lock)) {
    $adminUrl = 'http://' . $host . ':' . $port . '/admin/';
    page('安装已完成', '<p class="ok">ApiGateKeeper 已安装完成。</p>'
        . '<ul><li>网关：<code>http://' . htmlspecialchars($host, ENT_QUOTES) . ':' . $port . '</code></li>'
        . '<li>管理端：<a href="' . htmlspecialchars($adminUrl, ENT_QUOTES) . '">' . htmlspecialchars($adminUrl, ENT_QUOTES) . '</a></li></ul>'
        . '<div class="warn">如需重装：删除 ' . htmlspecialchars($lock, ENT_QUOTES)
        . ' 与 ' . htmlspecialchars($framppHome ? $framppHome . '/etc/caddy.d/api-gatekeeper.caddy' : 'caddy.d 片段', ENT_QUOTES)
        . ' 后重新访问本页。<br>生产环境请删除本文件（install.php）与安装包。</div>');
}

// ---------- 密钥校验 ----------
if (INSTALL_KEY !== '' && ($_GET['key'] ?? '') !== INSTALL_KEY) {
    page('需要安装密钥', '<p class="err">请携带 ?key= 访问（在 install.php 顶部 INSTALL_KEY 中配置）。</p>', 403);
}

// ---------- 预检 ----------
if (!is_file(PACKAGE_FILE)) {
    fail('缺少 ' . PACKAGE_FILE . "\n请把 gatekeeper-package.tar.gz 与本文件放到同一目录。");
}
if (!class_exists('PharData')) {
    fail('缺少 Phar 扩展，无法解压安装包。');
}
if ($framppHome === null) {
    fail("未找到 FRAMPP（向上查找 5 层未见 bin/frampp）。\n请把 install.php 放到 FRAMPP 的 web 目录（如 htdocs/）下。");
}
if (is_dir($target) && glob($target . '/processor/*.php') !== []) {
    fail("目标目录已存在文件：$target\n请先删除或改名后重试。");
}

$html = [];
$html[] = '<p>FRAMPP：<code>' . htmlspecialchars($framppHome, ENT_QUOTES) . '</code></p>';

// ---------- 解压到当前 web 目录（gatekeeper/ 子目录） ----------
@mkdir($target, 0777, true);
$phar = new PharData(PACKAGE_FILE);
$phar->extractTo($target, null, true);
$html[] = '<p>[OK] 已解压到 <code>' . htmlspecialchars($target, ENT_QUOTES) . '</code></p>';

// ---------- 初始化参数：config.php（烘焙密钥） ----------
$secret = rand_hex(16);
$adminToken = rand_hex(16);
$jwtSecret = rand_hex(16);
$config = $target . '/processor/config.php';
if (!is_file($config)) {
    $example = $target . '/configs/config.example.php';
    if (is_file($example)) {
        copy($example, $config);
    }
}
if (!is_file($config)) {
    fail('初始化失败：config.example.php 不存在于安装包。');
}
$cfg = require $config;
$cfg['processor']['secret'] = $secret;
$cfg['admin']['tokens'] = [$adminToken];
$cfg['auth']['jwt_secret'] = $jwtSecret;
file_put_contents($config, "<?php\n\nreturn " . var_export($cfg, true) . ";\n");
@mkdir($target . '/data/logs', 0777, true);
$html[] = '<p>[OK] 已生成 processor/config.php（密钥与管理令牌已初始化）</p>';

// ---------- 渲染 caddy.d 片段 ----------
$tpl = file_get_contents($target . '/configs/api-gatekeeper.caddyfile');
if ($tpl === false) {
    fail('初始化失败：安装包缺少 configs/api-gatekeeper.caddyfile。');
}
$caddyDir = $framppHome . '/etc/caddy.d';
@mkdir($caddyDir, 0777, true);
$caddyFrag = $caddyDir . '/api-gatekeeper.caddy';
file_put_contents(
    $caddyFrag,
    render_caddy($tpl, $target, $port, $secret, GATEKEEPER_UPSTREAM, 'http://127.0.0.1:' . $port)
);
$html[] = '<p>[OK] 已生成 <code>' . htmlspecialchars($caddyFrag, ENT_QUOTES) . '</code></p>';

// ---------- 挂载 import（幂等；FRAMPP 支持 caddy.d 自动导入则跳过） ----------
$caddyMain = $framppHome . '/etc/Caddyfile';
if (is_file($caddyMain)) {
    $content = (string) file_get_contents($caddyMain);
    if (!str_contains($content, 'caddy.d') && !str_contains($content, 'api-gatekeeper.caddy')) {
        file_put_contents($caddyMain, rtrim($content) . "\n\nimport {$caddyFrag}\n");
        $html[] = '<p>[OK] 已向 <code>' . htmlspecialchars($caddyMain, ENT_QUOTES) . '</code> 追加 import</p>';
    } else {
        $html[] = '<p>[OK] ' . htmlspecialchars($caddyMain, ENT_QUOTES) . ' 已包含 Gatekeeper 挂载</p>';
    }
} else {
    fail('FRAMPP 尚未初始化（缺少 etc/Caddyfile），请先运行 bin/frampp init。');
}

// ---------- 写安装锁 ----------
file_put_contents($lock, json_encode([
    'installed_at' => date('c'),
    'port' => $port,
    'frampp_home' => $framppHome,
    'target' => $target,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
$html[] = '<p>[OK] 已写入安装锁：<code>' . htmlspecialchars($lock, ENT_QUOTES) . '</code></p>';

// ---------- 尝试重启 FRAMPP（尽力而为） ----------
$restarted = false;
if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
    $out = [];
    $rc = 0;
    @exec('systemctl restart frampp 2>&1', $out, $rc);
    if ($rc === 0) {
        $restarted = true;
    } else {
        @exec('cd ' . escapeshellarg($framppHome) . ' && bin/frampp restart 2>&1', $out, $rc);
        if ($rc === 0) {
            $restarted = true;
        }
    }
}
if ($restarted) {
    $html[] = '<p>[OK] 已重启 FRAMPP（systemd / bin/frampp）</p>';
} else {
    $html[] = '<p class="err">未自动重启 FRAMPP（exec 不可用或无权限）。请手动执行：
        <code>bin/frampp restart</code> 或 <code>systemctl restart frampp</code></p>';
}

// ---------- 完成页 ----------
$adminUrl = 'http://' . $host . ':' . $port . '/admin/';
$body = implode('', $html)
    . '<h2>安装完成</h2>'
    . '<ul>'
    . '<li>网关：<code>http://' . htmlspecialchars($host, ENT_QUOTES) . ':' . $port . '</code></li>'
    . '<li>管理端：<a href="' . htmlspecialchars($adminUrl, ENT_QUOTES) . '">' . htmlspecialchars($adminUrl, ENT_QUOTES) . '</a></li>'
    . '<li>管理令牌（仅此一次显示）：<code>' . htmlspecialchars($adminToken, ENT_QUOTES) . '</code></li>'
    . '<li>处理器密钥：<code>' . htmlspecialchars($secret, ENT_QUOTES) . '</code></li>'
    . '<li>兜底上游：<code>' . htmlspecialchars(GATEKEEPER_UPSTREAM, ENT_QUOTES) . '</code></li>'
    . '</ul>'
    . '<div class="warn">安全提醒：请立即删除本文件（install.php）与 gatekeeper-package.tar.gz；'
    . '再次访问本文件将显示"安装已完成"。管理端请配置真实上游后创建业务应用。</div>';
page('安装完成', $body);
