<?php
/**
 * 后台登录页
 */

require dirname(__DIR__) . '/lib/bootstrap.php';

// 已登录则跳转工作台
if (Auth::check()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['_csrf'] ?? null);

    // 简单防暴力破解:同一 IP 连续失败 5 次后锁定 15 分钟(session 存储,零 DB 依赖)
    $now = time();
    $fail = $_SESSION['login_fail'] ?? ['count' => 0, 'lock_until' => 0];
    if ($fail['lock_until'] > $now) {
        $wait = (int) ceil(($fail['lock_until'] - $now) / 60);
        $error = '尝试次数过多,请 ' . $wait . ' 分钟后再试';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (Auth::attempt($username, $password)) {
            unset($_SESSION['login_fail']);
            header('Location: dashboard.php');
            exit;
        }
        // 失败计数
        $fail['count']++;
        if ($fail['count'] >= 5) {
            $fail['count'] = 0;
            $fail['lock_until'] = $now + 900; // 锁定 15 分钟
            $error = '登录失败次数过多,已锁定 15 分钟,请稍后再试';
        } else {
            $error = '账号或密码错误(还可尝试 ' . (5 - $fail['count']) . ' 次)';
        }
        $_SESSION['login_fail'] = $fail;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>登录 · 速页CMS</title>
<link rel="icon" type="image/x-icon" href="../logo/icon.ico">
<link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <img class="logo-wide" src="../logo/logo2.png" alt="速页CMS">
      <h1>速页CMS</h1>
      <p>模板变量驱动的小型 CMS 后台</p>
    </div>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="login-form">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <div class="form-field">
        <label>管理员账号</label>
        <input type="text" name="username" required autofocus placeholder="请输入管理员账号">
      </div>
      <div class="form-field">
        <label>密码</label>
        <input type="password" name="password" required placeholder="请输入密码">
      </div>
      <button type="submit" class="btn primary block">登 录</button>
    </form>
    <p class="login-foot">速页CMS v<?= APP_VERSION ?></p>
  </div>
</body>
</html>
