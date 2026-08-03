<?php
/**
 * 安装向导(类似织梦 CMS 的首次安装流程)
 *
 * 步骤:
 *   1. 环境检测 —— 检测服务器配置(PHP 版本 / MySQL / 扩展 / 目录权限),支持打勾、不支持标红并提示所需版本
 *   2. 数据库配置 —— 填写连接信息,测试连接,写入 config.php,创建数据表
 *   3. 管理员设置 —— 创建管理员账号密码
 *   4. 完成 —— 显示安装成功,引导进入后台
 */

require __DIR__ . '/lib/bootstrap.php';

// 已安装则禁止重复安装(除非强制)
if (defined('CMS_INSTALLED') && CMS_INSTALLED === true && empty($_GET['force'])) {
    header('Location: ' . ADMIN_PATH . '/login.php');
    exit;
}

$step = (int)($_GET['step'] ?? 1);
$error = '';
$success = '';

// 步骤 3 需要已通过"测试连接"保存的数据库配置;直接 GET 跳转且无配置则回退步骤 2
if ($step === 3 && empty($_SESSION['install_db']) && ($_POST['action'] ?? '') !== 'install') {
    header('Location: install.php?step=2');
    exit;
}

// 步骤 2:数据库配置 + 测试连接
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_db') {
    $host = trim((string)($_POST['host'] ?? ''));
    $port = trim((string)($_POST['port'] ?? '3306'));
    $dbname = trim((string)($_POST['dbname'] ?? ''));
    $user = trim((string)($_POST['user'] ?? ''));
    $pass = (string)($_POST['pass'] ?? '');
    $prefix = trim((string)($_POST['prefix'] ?? 'lp_'));

    if ($host === '' || $dbname === '' || $user === '') {
        $error = '请填写完整的数据库连接信息';
    } else {
        $result = Database::testConnection($host, $port, $user, $pass, $dbname);
        if ($result['success']) {
            // 测试通过:尝试建表
            try {
                $pdo = new PDO(
                    'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4',
                    $user, $pass,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                );
                Database::installTables($pdo, $prefix);
                Database::seedVariables($pdo, $prefix);
                $success = '连接成功 · 服务器版本 ' . $result['version'] . ' · 数据表已创建';
                $tested = ['host' => $host, 'port' => $port, 'dbname' => $dbname, 'user' => $user, 'pass' => $pass, 'prefix' => $prefix];
                // 存入 session,供步骤 3 使用
                $_SESSION['install_db'] = $tested;
            } catch (Throwable $e) {
                $error = '连接成功但建表失败:' . $e->getMessage();
            }
        } else {
            $error = '连接失败:' . $result['error'];
        }
    }
}

// 步骤 3:写入配置 + 创建管理员
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
    $host = trim((string)($_POST['host'] ?? ''));
    $port = trim((string)($_POST['port'] ?? '3306'));
    $dbname = trim((string)($_POST['dbname'] ?? ''));
    $user = trim((string)($_POST['user'] ?? ''));
    $pass = (string)($_POST['pass'] ?? '');
    $prefix = trim((string)($_POST['prefix'] ?? 'lp_'));

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');
    $email = trim((string)($_POST['email'] ?? ''));

    if ($username === '' || strlen($username) < 3) {
        $error = '管理员账号至少 3 个字符';
    } elseif (strlen($password) < 6) {
        $error = '管理员密码至少 6 位';
    } elseif ($password !== $confirm) {
        $error = '两次输入的密码不一致';
    } else {
        try {
            // 1. 测试并建立连接
            $result = Database::testConnection($host, $port, $user, $pass, $dbname);
            if (!$result['success']) {
                throw new RuntimeException('数据库连接失败:' . $result['error']);
            }
            $pdo = new PDO(
                'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $dbname . ';charset=utf8mb4',
                $user, $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
            );
            Database::installTables($pdo, $prefix);

            // 2. 创建管理员(已存在则更新为本次设置的账号密码,保证重复安装不报错)
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("INSERT INTO `{$prefix}users` (`username`,`password`,`email`) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE `password` = VALUES(`password`), `email` = VALUES(`email`)")
                ->execute([$username, $hash, $email]);

            // 3. 写入 config.php
            $key = bin2hex(random_bytes(24));
            $config = "<?php\n"
                . "// 速页CMS · 模板变量 CMS 配置(由安装向导生成)\n"
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

            if (!@file_put_contents(dirname(__FILE__) . '/config.php', $config)) {
                throw new RuntimeException('config.php 写入失败,请检查目录权限');
            }

            // 4. 清理安装会话,跳转完成页
            unset($_SESSION['install_db']);
            header('Location: install.php?step=4');
            exit;
        } catch (Throwable $e) {
            $error = '安装失败:' . $e->getMessage();
        }
    }
}

$checks = EnvCheck::run();
$allPass = EnvCheck::allPass($checks);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>安装向导 · 速页CMS <?= APP_VERSION ?></title>
<link rel="icon" type="image/x-icon" href="logo/icon.ico">
<link rel="stylesheet" href="assets/css/install.css">
</head>
<body>
<div class="install-wrap">
  <div class="install-card">
    <!-- 左侧步骤条 -->
    <aside class="install-side">
      <div class="side-logo">
        <img src="logo/logo2.png" alt="速页CMS" class="logo-wide">
        <span class="install-version">安装向导 v<?= APP_VERSION ?></span>
      </div>
      <nav class="side-steps">
        <?php foreach ([1 => ['环境检测', '服务器配置检查'], 2 => ['数据库配置', '连接信息与测试'], 3 => ['管理员设置', '账号与安全'], 4 => ['完成安装', '系统初始化']] as $n => $label): ?>
        <div class="step-item <?= $n === $step ? 'active' : ($n < $step ? 'done' : '') ?>">
          <span class="step-num"><?= $n < $step ? '✓' : $n ?></span>
          <div class="step-text">
            <strong><?= $label[0] ?></strong>
            <span><?= $label[1] ?></span>
          </div>
        </div>
        <?php endforeach; ?>
      </nav>
      <div class="side-foot">
        <span class="dot"></span>
        安装前请确认已准备好 MySQL 数据库与目录写入权限
      </div>
    </aside>

    <!-- 右侧内容 -->
    <main class="install-main">
      <?php if ($step === 1): ?>
      <!-- 步骤 1:环境检测 -->
      <h1>环境检测</h1>
      <p class="sub">检测服务器配置是否满足系统运行要求,不满足的项将以红色标出并提示所需版本</p>
      <div class="check-list">
        <?php foreach ($checks as $c): ?>
        <div class="check-row <?= $c['ok'] ? '' : 'fail' ?>">
          <span class="check-icon <?= $c['ok'] ? 'ok' : 'no' ?>"><?= $c['ok'] ? '✓' : '×' ?></span>
          <span class="check-name"><?= e($c['name']) ?></span>
          <span class="check-value"><?= e($c['value']) ?></span>
          <?php if (!$c['ok']): ?><span class="check-hint"><?= e($c['hint']) ?></span><?php endif; ?>
          <span class="check-tag <?= $c['ok'] ? 'ok' : 'no' ?>"><?= $c['ok'] ? '支持' : '不支持' ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="check-actions">
        <?php if (!$allPass): ?>
        <div class="warn"><span class="warn-ic">!</span> 有 <?= count(array_filter($checks, function ($c) { return !$c['ok']; })) ?> 项不满足要求,请先处理后继续</div>
        <?php endif; ?>
        <div class="btns">
          <button class="btn" onclick="location.reload()">重新检测</button>
          <button class="btn primary" <?= $allPass ? '' : 'disabled' ?> onclick="location.href='install.php?step=2'">下一步</button>
        </div>
      </div>

      <?php elseif ($step === 2): ?>
      <!-- 步骤 2:数据库配置 -->
      <h1>数据库配置</h1>
      <p class="sub">填写 MySQL 数据库连接信息,系统将自动测试连接。表前缀建议保持默认以避免冲突</p>
      <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
      <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
      <form method="post" class="form">
        <input type="hidden" name="action" value="test_db">
        <div class="form-row">
          <div class="form-field grow">
            <label>数据库主机</label>
            <input type="text" name="host" value="<?= e($_POST['host'] ?? '127.0.0.1') ?>" required>
          </div>
          <div class="form-field fixed">
            <label>端口</label>
            <input type="text" name="port" value="<?= e($_POST['port'] ?? '3306') ?>">
          </div>
        </div>
        <div class="form-field">
          <label>数据库名</label>
          <input type="text" name="dbname" value="<?= e($_POST['dbname'] ?? 'landing_page_cms') ?>" required>
        </div>
        <div class="form-row">
          <div class="form-field grow">
            <label>用户名</label>
            <input type="text" name="user" value="<?= e($_POST['user'] ?? 'root') ?>" required>
          </div>
          <div class="form-field grow">
            <label>密码</label>
            <input type="password" name="pass" value="<?= e($_POST['pass'] ?? '') ?>">
          </div>
        </div>
        <div class="form-field">
          <label>数据表前缀 <em>建议保持默认以避免冲突</em></label>
          <input type="text" name="prefix" value="<?= e($_POST['prefix'] ?? 'lp_') ?>">
        </div>
        <div class="form-actions">
          <button type="submit" class="btn">测试连接</button>
          <div class="btns">
            <a class="btn" href="install.php?step=1">上一步</a>
            <a class="btn primary" href="install.php?step=3">下一步</a>
          </div>
        </div>
      </form>

      <?php elseif ($step === 3): ?>
      <!-- 步骤 3:管理员设置 -->
      <h1>管理员设置</h1>
      <p class="sub">设置超级管理员账号和初始密码,请使用强密码并妥善保管</p>
      <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
      <form method="post" class="form">
        <input type="hidden" name="action" value="install">
        <input type="hidden" name="host" value="<?= e($_POST['host'] ?? ($_SESSION['install_db']['host'] ?? '127.0.0.1')) ?>">
        <input type="hidden" name="port" value="<?= e($_POST['port'] ?? ($_SESSION['install_db']['port'] ?? '3306')) ?>">
        <input type="hidden" name="dbname" value="<?= e($_POST['dbname'] ?? ($_SESSION['install_db']['dbname'] ?? '')) ?>">
        <input type="hidden" name="user" value="<?= e($_POST['user'] ?? ($_SESSION['install_db']['user'] ?? 'root')) ?>">
        <input type="hidden" name="pass" value="<?= e($_POST['pass'] ?? ($_SESSION['install_db']['pass'] ?? '')) ?>">
        <input type="hidden" name="prefix" value="<?= e($_POST['prefix'] ?? ($_SESSION['install_db']['prefix'] ?? 'lp_')) ?>">
        <div class="form-field">
          <label>管理员账号</label>
          <input type="text" name="username" value="<?= e($_POST['username'] ?? 'admin') ?>" required>
        </div>
        <div class="form-field">
          <label>管理员密码</label>
          <input type="password" name="password" placeholder="至少 6 位,建议大小写字母 + 数字 + 符号" required>
        </div>
        <div class="form-field">
          <label>确认密码</label>
          <input type="password" name="confirm" placeholder="再次输入密码" required>
        </div>
        <div class="form-field">
          <label>管理员邮箱 <em>用于找回密码</em></label>
          <input type="email" name="email" value="<?= e($_POST['email'] ?? '') ?>" placeholder="admin@example.com">
        </div>
        <div class="form-actions">
          <div class="btns">
            <a class="btn" href="install.php?step=2">上一步</a>
            <button type="submit" class="btn primary">完成安装</button>
          </div>
        </div>
      </form>

      <?php else: ?>
      <!-- 步骤 4:完成 -->
      <div class="done-wrap">
        <div class="done-icon">✓</div>
        <h1>安装完成!</h1>
        <p class="sub">恭喜!速页CMS 已成功安装。系统已初始化数据表与管理员账号。</p>
        <div class="done-info">
          <div><span>后台地址</span><code><?= e(($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') ?>://<?= e($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/admin/login.php</code></div>
          <div><span>前端页面</span><code><?= e(($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') ?>://<?= e($_SERVER['HTTP_HOST'] ?? 'localhost') ?>/</code></div>
        </div>
        <p class="done-tip">安全提示:请立即删除服务器上的 install.php 文件,避免被重复安装。</p>
        <a class="btn primary big" href="admin/login.php">进入后台登录</a>
      </div>
      <?php endif; ?>
    </main>
  </div>
  <footer class="install-foot">速页CMS v<?= APP_VERSION ?> · 模板变量驱动的小型 CMS</footer>
</div>
</body>
</html>
