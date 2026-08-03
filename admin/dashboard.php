<?php
/**
 * 工作台 —— 站点总览
 * 统计卡片 + 模板文件状态 + 最近发布记录
 */

require dirname(__DIR__) . '/lib/bootstrap.php';
Auth::requireLogin();

$prefix = DB_PREFIX;

// 统计
$varCount = (int) Database::value("SELECT COUNT(*) FROM `{$prefix}variables`");
$groupCount = (int) Database::value("SELECT COUNT(DISTINCT `group_name`) FROM `{$prefix}variables`");
$leadTotal = (int) Database::value("SELECT COUNT(*) FROM `{$prefix}leads`");
$leadToday = (int) Database::value("SELECT COUNT(*) FROM `{$prefix}leads` WHERE DATE(`created_at`) = CURDATE()");
$leadWeek = (int) Database::value("SELECT COUNT(*) FROM `{$prefix}leads` WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$templateCount = count(glob(TEMPLATE_DIR . '/*.*') ?: []);

// 模板文件扫描(文件 => 变量数)
$fileScans = Template::scanAll();

// 最近发布的变量(按更新时间取 5 条)
$recent = Database::rows(
    "SELECT `var_key`, `var_name`, `var_value`, `updated_at`, `created_at`
     FROM `{$prefix}variables` ORDER BY `updated_at` DESC LIMIT 6"
);

$pageTitle = '工作台';
require __DIR__ . '/partials/header.php';
?>

<!-- 速页CMS 介绍卡 -->
<section class="cms-intro">
  <div class="cms-intro-bg"></div>
  <div class="cms-intro-content">
    <div class="cms-intro-main">
      <div class="cms-intro-head">
        <img class="cms-intro-logo" src="../logo/logo2.png" alt="速页CMS">
        <span class="cms-intro-badge">v<?= e(APP_VERSION) ?></span>
      </div>
      <h2>欢迎使用 速页CMS</h2>
      <p>专为广告投放人员打造的轻量落地页内容管理系统。前端文件放在 <code>templates/</code> 目录,代码里用 <code>{{ 变量名 }}</code> 占位,后台改变量值,前端页面自动更新。</p>
      <ul class="cms-intro-bullets">
        <li>文件存 <code>templates/</code>,代码写 <code>{{ key }}</code>,改后台即时生效</li>
        <li>支持文本、图片、链接、颜色等变量类型,前端自动渲染</li>
        <li>访客表单提交自动收集,支持 CSV 导出与状态跟进</li>
      </ul>
    </div>
    <div class="cms-intro-actions">
      <a class="btn primary" href="variables.php">开始管理变量</a>
      <a class="btn ghost" href="templates.php">查看模板</a>
    </div>
  </div>
</section>

<div class="stat-grid">
  <div class="stat-card">
    <div class="stat-head"><span>变量总数</span><span class="tag purple"><?= $groupCount ?> 组</span></div>
    <div class="stat-num"><?= $varCount ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-head"><span>模板文件</span><span class="tag green">已挂载</span></div>
    <div class="stat-num"><?= $templateCount ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-head"><span>表单线索</span><span class="tag green">本周 +<?= $leadWeek ?></span></div>
    <div class="stat-num"><?= $leadTotal ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-head"><span>今日新增线索</span><span class="tag amber">实时</span></div>
    <div class="stat-num"><?= $leadToday ?></div>
  </div>
</div>

<div class="dual-col">
  <!-- 模板文件状态 -->
  <section class="card">
    <div class="card-head">
      <h3>模板文件 (/templates)</h3>
      <a class="btn sm ghost" href="templates.php">管理文件 ›</a>
    </div>
    <div class="file-list">
      <?php if (empty($fileScans)): ?>
      <div class="empty">templates/ 目录暂无文件,请前往"模板文件"上传或新建前端页面</div>
      <?php else: foreach ($fileScans as $file => $vars): ?>
      <div class="file-row">
        <div class="file-icon"><?= strpos($file, '.css') !== false ? 'C' : (strpos($file, '.js') !== false ? 'J' : 'H') ?></div>
        <div class="file-mid">
          <strong><?= e($file) ?></strong>
          <span>templates/<?= e($file) ?></span>
        </div>
        <div class="file-tags">
          <span class="tag purple"><?= count($vars) ?> 个变量</span>
          <span class="tag green">已挂载</span>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </section>

  <!-- 最近发布 -->
  <section class="card">
    <div class="card-head">
      <h3>最近发布</h3>
      <a class="btn sm ghost" href="variables.php">查看全部 ›</a>
    </div>
    <div class="publish-list">
      <?php foreach ($recent as $r): ?>
      <div class="publish-row">
        <span class="pub-dot <?= strtotime($r['updated_at']) > strtotime('-1 day') ? 'green' : '' ?>"></span>
        <div class="pub-mid">
          <strong>更新变量:<?= e($r['var_name']) ?></strong>
          <span><code>{{ <?= e($r['var_key']) ?> }}</code> · <?= e(date('m-d H:i', strtotime($r['updated_at']))) ?></span>
        </div>
        <span class="pub-time"><?= e(date('m-d H:i', strtotime($r['updated_at']))) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </section>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
