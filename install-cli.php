<?php
/**
 * 命令行安装工具(可选)
 * 在没有浏览器的情况下,通过 CLI 完成安装:
 *
 *   php install-cli.php --host=127.0.0.1 --port=3306 --db=landing_page_cms --user=root --pass=xxx --prefix=lp_ --admin=admin --admin-pass=Admin@2026 --email=admin@example.com
 *
 * 也可交互式运行: php install-cli.php
 */

require __DIR__ . '/lib/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit("请通过命令行运行: php install-cli.php\n");
}

echo "==============================================\n";
echo "  速页CMS 模板变量 CMS · 命令行安装工具\n";
echo "==============================================\n\n";

// 已安装检查
if (defined('CMS_INSTALLED') && CMS_INSTALLED === true) {
    echo "[!] 系统已安装。如需重装,请先删除 config.php 和数据库表。\n";
    exit(1);
}

// ---- 第 1 步:环境检测 ----
echo "[1/3] 环境检测\n";
$checks = EnvCheck::run();
foreach ($checks as $c) {
    printf("  %s  %-18s %s %s\n", $c['ok'] ? '[OK]' : '[FAIL]', $c['name'], $c['value'], $c['ok'] ? '' : '(' . $c['hint'] . ')');
}
if (!EnvCheck::allPass($checks)) {
    echo "\n[!] 存在不满足项,请先处理后重试。\n";
    exit(1);
}
echo "\n";

// ---- 第 2 步:数据库配置 ----
echo "[2/3] 数据库配置\n";
$opts = getopt('', ['host::', 'port::', 'db::', 'user::', 'pass::', 'prefix::', 'admin::', 'admin-pass::', 'email::']);

$host = $opts['host'] ?? readline('  数据库主机 [127.0.0.1]: ');
$host = $host === '' ? '127.0.0.1' : $host;
$port = $opts['port'] ?? readline('  数据库端口 [3306]: ');
$port = $port === '' ? '3306' : $port;
$dbname = $opts['db'] ?? readline('  数据库名: ');
$user = $opts['user'] ?? readline('  用户名 [root]: ');
$user = $user === '' ? 'root' : $user;
$pass = $opts['pass'] ?? readline('  密码: ');
$prefix = $opts['prefix'] ?? readline('  表前缀 [lp_]: ');
$prefix = $prefix === '' ? 'lp_' : $prefix;

// 测试连接
$result = Database::testConnection($host, $port, $user, $pass, $dbname);
if (!$result['success']) {
    echo "  [!] 连接失败:{$result['error']}\n";
    exit(1);
}
echo "  [OK] 连接成功,服务器版本:{$result['version']}\n";

// 建表
$pdo = new PDO(
    'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4',
    $user, $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
);
Database::installTables($pdo, $prefix);
Database::seedVariables($pdo, $prefix);
echo "  [OK] 数据表已创建,默认变量已写入。\n\n";

// ---- 第 3 步:管理员设置 ----
echo "[3/3] 管理员设置\n";
$admin = $opts['admin'] ?? readline('  管理员账号 [admin]: ');
$admin = $admin === '' ? 'admin' : $admin;
$adminPass = $opts['admin-pass'] ?? '';
if ($adminPass === '') {
    // 交互模式隐藏输入(Windows/Unix 简化)
    echo '  管理员密码: ';
    system('stty -echo 2>/dev/null');
    $adminPass = trim(fgets(STDIN));
    system('stty echo 2>/dev/null');
    echo "\n";
}
$email = $opts['email'] ?? readline('  管理员邮箱 [admin@example.com]: ');
$email = $email === '' ? 'admin@example.com' : $email;

if (strlen($admin) < 3) { echo "[!] 账号至少 3 字符\n"; exit(1); }
if (strlen($adminPass) < 6) { echo "[!] 密码至少 6 位\n"; exit(1); }

// 创建管理员
$hash = password_hash($adminPass, PASSWORD_DEFAULT);
$pdo->prepare("INSERT INTO `{$prefix}users` (`username`,`password`,`email`) VALUES (?,?,?)")
    ->execute([$admin, $hash, $email]);
echo "  [OK] 管理员已创建:{$admin}\n";

// 写入 config.php
$key = bin2hex(random_bytes(24));
$config = "<?php\n"
    . "// 速页CMS · 模板变量 CMS 配置(由命令行安装生成)\n"
    . "define('DB_HOST', '" . addslashes($host) . "');\n"
    . "define('DB_PORT', '" . addslashes($port) . "');\n"
    . "define('DB_NAME', '" . addslashes($dbname) . "');\n"
    . "define('DB_USER', '" . addslashes($user) . "');\n"
    . "define('DB_PASS', '" . addslashes($pass) . "');\n"
    . "define('DB_PREFIX', '" . addslashes($prefix) . "');\n"
    . "define('SITE_NAME', '速页CMS');\n"
    . "define('APP_VERSION', '" . APP_VERSION . "');\n"
    . "define('APP_KEY', '" . $key . "');\n"
    . "define('ADMIN_PATH', 'admin');\n"
    . "define('TEMPLATE_DIR', __DIR__ . '/templates');\n"
    . "define('DATA_DIR', __DIR__ . '/data');\n"
    . "define('CMS_INSTALLED', true);\n";
if (!@file_put_contents(__DIR__ . '/config.php', $config)) {
    echo "[!] config.php 写入失败\n";
    exit(1);
}
echo "  [OK] config.php 已生成。\n\n";

echo "==============================================\n";
echo "  安装完成!\n";
echo "  后台地址: " . (defined('ADMIN_PATH') ? ADMIN_PATH : 'admin') . "/login.php\n";
echo "  前端页面: /index.php\n";
echo "  安全提示: 请删除 install.php 与 install-cli.php\n";
echo "==============================================\n";
