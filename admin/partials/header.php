<?php
/**
 * 后台公共头部 + 侧边栏
 * 用法:require __DIR__ . '/partials/header.php'; 传入 $pageTitle
 *
 * logo:统一使用 loho3.png(里面已含"速页CMS"字样)
 * 展开态:logo + 文字标签;收起态:仅 logo,按钮在 logo 下方独立排布
 */

if (!isset($pageTitle)) { $pageTitle = '后台'; }
$current = basename($_SERVER['PHP_SELF']);
$adminUser = Auth::user();

// 导航图标:每项 = [链接, 标题, SVG path d]
$navItems = [
    ['dashboard.php',  '工作台',   'M3 3h8v8H3zM13 3h8v8h-8zM3 13h8v8H3zM13 13h8v8h-8z'],
    ['variables.php',  '变量管理', 'M9 6l-3 6 3 6M15 6l3 6-3 6'],
    ['templates.php',  '模板文件', 'M7 4h7l5 5v10a1 1 0 01-1 1H7a1 1 0 01-1-1V5a1 1 0 011-1zM14 4v5h5'],
    ['forms.php',      '表单数据', 'M5 5h14v14H5zM5 9h14M5 13h14M5 17h10M9 5v14'],
    ['settings.php',   '系统设置', 'M12 8a4 4 0 100 8 4 4 0 000-8zM3 12h2M19 12h2M12 3v2M12 19v2'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · 速页CMS</title>
<link rel="icon" type="image/x-icon" href="../logo/icon.ico">
<link rel="stylesheet" href="../assets/css/admin.css">
<?= $headExtra ?? '' ?>
</head>
<body>
<div class="admin-shell">
  <!-- 侧边栏 -->
  <aside class="admin-side">
    <div class="admin-logo">
      <img class="logo-img" src="../logo/loho3.png" alt="速页CMS">
      <div class="logo-text">
        <strong>速页CMS</strong>
        <span>单站变量系统</span>
      </div>
    </div>
    <nav class="admin-nav">
      <?php foreach ($navItems as $item): ?>
      <a href="<?= $item[0] ?>" class="nav-item <?= $current === $item[0] ? 'active' : '' ?>" title="<?= e($item[1]) ?>">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="<?= $item[2] ?>"/></svg>
        <span class="nav-label"><?= $item[1] ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
    <div class="admin-user" title="<?= e($adminUser['username'] ?? '管理员') ?>">
      <div class="user-avatar"><?= e(mb_substr($adminUser['username'] ?? '管', 0, 1)) ?></div>
      <div class="user-info">
        <strong><?= e($adminUser['username'] ?? '管理员') ?></strong>
        <span class="user-status"><i class="dot"></i>已登录</span>
      </div>
    </div>
  </aside>

  <!-- 主区域 -->
  <div class="admin-main">
    <header class="admin-topbar">
      <button class="menu-toggle" type="button" title="打开菜单">
        <svg viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 5h12M3 9h12M3 13h12"/></svg>
      </button>
      <h1><?= e($pageTitle) ?></h1>
      <div class="topbar-actions">
        <a class="btn ghost" href="../index.php" target="_blank">预览页面</a>
        <a class="btn danger-ghost" href="logout.php">退出</a>
      </div>
    </header>
    <main class="admin-content">