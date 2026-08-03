<?php
/**
 * 系统设置 —— 修改管理员密码、后台访问地址
 */

require dirname(__DIR__) . '/lib/bootstrap.php';
Auth::requireLogin();

$prefix = DB_PREFIX;
$msg = '';

// 修改密码
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    csrf_verify($_POST['_csrf'] ?? null);
    $user = Auth::user();
    $old = (string)($_POST['old_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!password_verify($old, $user['password'])) {
        $msg = ['type' => 'error', 'text' => '当前密码错误'];
    } elseif (strlen($new) < 6) {
        $msg = ['type' => 'error', 'text' => '新密码至少 6 位'];
    } elseif ($new !== $confirm) {
        $msg = ['type' => 'error', 'text' => '两次输入的新密码不一致'];
    } else {
        Auth::changePassword((int)$user['id'], $new);
        $msg = ['type' => 'success', 'text' => '密码已更新,请使用新密码重新登录'];
    }
}

// 修改后台地址(通过生成路由映射文件实现)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_path') {
    csrf_verify($_POST['_csrf'] ?? null);
    $newPath = trim((string)($_POST['new_path'] ?? ''), '/');
    $newPath = preg_replace('/[^a-zA-Z0-9_\-]/', '', $newPath);

    if (strlen($newPath) < 3) {
        $msg = ['type' => 'error', 'text' => '后台路径至少 3 个字符,仅支持字母、数字、下划线、短横线'];
    } elseif ($newPath === 'admin') {
        $msg = ['type' => 'error', 'text' => '不能设置为 admin,请使用更隐蔽的路径'];
    } else {
        // 生成入口文件:新路径.php -> require admin/login.php
        // 根目录保留文件名,禁止覆盖
        $reserved = ['index', 'install', 'config', 'admin', 'login', 'README', 'check-php', 'install-cli', 'cleanup-seed-vars'];
        $entry = dirname(__DIR__) . '/' . $newPath . '.php';
        if (in_array($newPath, $reserved, true)) {
            $msg = ['type' => 'error', 'text' => '该路径为系统保留文件,不能覆盖'];
        } elseif (file_exists($entry)) {
            $msg = ['type' => 'error', 'text' => '同名文件已存在,请换一个路径'];
        } else {
            $code = "<?php\n/** 后台入口(由系统设置生成) */\nheader('Location: admin/login.php');\nexit;\n";
            if (@file_put_contents($entry, $code) !== false) {
                // 记录到 settings 表
                $set = Database::row("SELECT * FROM `{$prefix}settings` WHERE `setting_key` = 'admin_path'");
                $newUrl = (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/' . $newPath . '.php';
                if ($set) {
                    Database::exec("UPDATE `{$prefix}settings` SET `setting_value` = ? WHERE `setting_key` = 'admin_path'", [$newUrl]);
                } else {
                    Database::insert("INSERT INTO `{$prefix}settings` (`setting_key`,`setting_value`) VALUES ('admin_path', ?)", [$newUrl]);
                }
                $msg = ['type' => 'success', 'text' => '后台入口已生成:' . $newUrl];
            } else {
                $msg = ['type' => 'error', 'text' => '入口文件创建失败,请检查网站根目录写入权限'];
            }
        }
    }
}

$user = Auth::user();
$adminPathSetting = Database::row("SELECT `setting_value` FROM `{$prefix}settings` WHERE `setting_key` = 'admin_path'");
$currentAdminUrl = $adminPathSetting['setting_value'] ?? (($_SERVER['HTTPS'] ?? '') ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/admin/login.php';

$pageTitle = '系统设置';
require __DIR__ . '/partials/header.php';
?>

<?php if ($msg): ?><div class="alert <?= $msg['type'] ?>"><?= e($msg['text']) ?></div><?php endif; ?>

<div class="settings-cols">
  <!-- 修改管理员密码 -->
  <section class="card">
    <div class="card-head">
      <div>
        <h3>管理员密码</h3>
        <p class="card-sub">建议定期更换密码,并启用大小写字母 + 数字 + 特殊字符</p>
      </div>
    </div>
    <form method="post" class="form" style="margin-top:18px">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="change_password">
      <div class="form-field">
        <label>当前密码</label>
        <input type="password" name="old_password" required placeholder="输入当前密码">
      </div>
      <div class="form-field">
        <label>新密码</label>
        <input type="password" name="new_password" required placeholder="至少 6 位,建议强密码">
      </div>
      <div class="form-field">
        <label>确认新密码</label>
        <input type="password" name="confirm_password" required placeholder="再次输入新密码">
      </div>
      <div class="form-actions" style="justify-content:flex-end">
        <button type="submit" class="btn primary">更新密码</button>
      </div>
    </form>
  </section>

  <!-- 修改后台地址 -->
  <section class="card">
    <div class="card-head">
      <div>
        <h3>后台访问地址</h3>
        <p class="card-sub">修改后旧的 /admin.php 入口仍保留,但建议使用新入口访问,增强安全性</p>
      </div>
    </div>
    <form method="post" class="form" style="margin-top:18px">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="change_path">
      <div class="form-field">
        <label>当前后台地址</label>
        <input type="text" value="<?= e($currentAdminUrl) ?>" readonly style="background:#F4F4F5;color:#6B7280">
      </div>
      <div class="form-field">
        <label>新后台路径 <em>建议使用随机路径,如 manage-x7k9</em></label>
        <input type="text" name="new_path" placeholder="manage-x7k9" required>
        <small>生成后通过 http://你的域名/新路径.php 访问后台</small>
      </div>
      <div class="form-actions" style="justify-content:flex-end">
        <button type="submit" class="btn primary">生成新入口</button>
      </div>
    </form>
  </section>
</div>

<!-- 系统信息 -->
<section class="card" style="margin-top:20px">
  <div class="card-head"><h3>系统信息</h3></div>
  <div class="sys-info">
    <div><span>系统版本</span><code><?= e(APP_VERSION) ?></code></div>
    <div><span>PHP 版本</span><code><?= e(PHP_VERSION) ?></code></div>
    <div><span>数据库前缀</span><code><?= e(DB_PREFIX) ?></code></div>
    <div><span>模板目录</span><code><?= e(TEMPLATE_DIR) ?></code></div>
    <div><span>管理员账号</span><code><?= e($user['username']) ?></code></div>
  </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
