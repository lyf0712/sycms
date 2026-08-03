<?php
/**
 * 表单数据 —— 访客线索收集
 * 展示所有落地页提交的表单数据,支持导出 CSV
 */

require dirname(__DIR__) . '/lib/bootstrap.php';
Auth::requireLogin();

$prefix = DB_PREFIX;

// 状态流转
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    csrf_verify($_POST['_csrf'] ?? null);
    $id = (int)($_POST['id'] ?? 0);
    $status = (int)($_POST['status'] ?? 0);
    Database::exec("UPDATE `{$prefix}leads` SET `status` = ? WHERE `id` = ?", [$status, $id]);
}

// 删除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_verify($_POST['_csrf'] ?? null);
    $id = (int)($_POST['id'] ?? 0);
    Database::exec("DELETE FROM `{$prefix}leads` WHERE `id` = ?", [$id]);
}

// 筛选条件构造(白名单 + 参数化)
$filters = [];
$params = [];
if (!empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $filters[] = 'DATE(`created_at`) = ?';
    $params[] = $_GET['date'];
}
if (isset($_GET['status']) && $_GET['status'] !== '' && in_array((int)$_GET['status'], [0, 1, 2], true)) {
    $filters[] = '`status` = ?';
    $params[] = (int)$_GET['status'];
}
$whereSql = $filters ? ' WHERE ' . implode(' AND ', $filters) : '';

// 导出 CSV(应用筛选)
if (!empty($_GET['export'])) {
    $rows = Database::rows("SELECT * FROM `{$prefix}leads`{$whereSql} ORDER BY `id` DESC", $params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=leads_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM,兼容 Excel

    // CSV 公式注入防护:以 = + - @ 开头的访客可控字段,前面加单引号,避免 Excel 执行
    $csvSafe = function ($s) {
        $s = (string)$s;
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@'], true)) {
            return "'" . $s;
        }
        return $s;
    };

    fputcsv($out, ['ID', '访客', '电话', '留言', '其它字段(扩展)', '客户IP', '设备·浏览器', '来源地址', '状态', '提交时间']);
    $statusMap = [0 => '新线索', 1 => '已联系', 2 => '已成交'];
    foreach ($rows as $r) {
        $device = parseUserAgent((string)($r['user_agent'] ?? ''));
        $refHost = getHostFromReferer((string)($r['referer'] ?? ''));
        // 把 extra JSON 还原为 key=value 字符串,便于 Excel 表格阅读
        $extrasStr = '';
        if (!empty($r['extra'])) {
            $decoded = json_decode((string)$r['extra'], true);
            if (is_array($decoded)) {
                $parts = [];
                foreach ($decoded as $k => $v) $parts[] = $k . '=' . $csvSafe($v);
                $extrasStr = implode('; ', $parts);
            }
        }
        fputcsv($out, [
            $r['id'], $csvSafe($r['visitor_name']), $csvSafe($r['visitor_phone']), $csvSafe($r['message']),
            $extrasStr, $csvSafe($r['ip']), $device, $refHost, $statusMap[$r['status']] ?? '新线索', $r['created_at']
        ]);
    }
    fclose($out);
    exit;
}

// 全局统计(不受筛选影响)
$leadTotal = (int) Database::value("SELECT COUNT(*) FROM `{$prefix}leads`");
$leadToday = (int) Database::value("SELECT COUNT(*) FROM `{$prefix}leads` WHERE DATE(`created_at`) = CURDATE()");
$leadPending = (int) Database::value("SELECT COUNT(*) FROM `{$prefix}leads` WHERE `status` = 0");

// 是否有筛选
$hasFilter = !empty($_GET['date']) || (isset($_GET['status']) && $_GET['status'] !== '');
// 当前 URL 中保留筛选参数(用于分页链接)
$filterParams = [];
if (!empty($_GET['date'])) $filterParams['date'] = $_GET['date'];
if (isset($_GET['status']) && $_GET['status'] !== '') $filterParams['status'] = (int)$_GET['status'];

// 列表(分页 + 筛选)
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 20;
$total = (int) Database::value("SELECT COUNT(*) FROM `{$prefix}leads`{$whereSql}", $params);
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;
$leads = Database::rows("SELECT * FROM `{$prefix}leads`{$whereSql} ORDER BY `id` DESC LIMIT {$perPage} OFFSET {$offset}", $params);

$statusMap = [0 => '新线索', 1 => '已联系', 2 => '已成交'];
$statusClass = [0 => 'blue', 1 => 'green', 2 => 'purple'];

/**
 * 简化 UA 解析为"设备 · 浏览器"
 * - 设备:iOS / Android / Windows / macOS / Linux
 * - 浏览器:微信 / QQ / Edge / Chrome / Firefox / Safari / Opera
 */
function parseUserAgent(string $ua): string {
    if ($ua === '') return '—';
    $device = '';
    if (preg_match('/iPhone|iPad|iPod/', $ua))           $device = 'iOS';
    elseif (preg_match('/Android/', $ua))                $device = 'Android';
    elseif (preg_match('/Windows NT/', $ua))             $device = 'Windows';
    elseif (preg_match('/Macintosh|Mac OS X/', $ua))     $device = 'macOS';
    elseif (preg_match('/Linux/', $ua))                  $device = 'Linux';
    $browser = '';
    if (preg_match('/MicroMessenger/', $ua))             $browser = '微信';
    elseif (preg_match('/QQBrowser|QQ\//', $ua))         $browser = 'QQ';
    elseif (preg_match('/Edg\//', $ua))                  $browser = 'Edge';
    elseif (preg_match('/OPR\/|Opera/', $ua))            $browser = 'Opera';
    elseif (preg_match('/Firefox\//', $ua))              $browser = 'Firefox';
    elseif (preg_match('/Chrome\//', $ua))               $browser = 'Chrome';
    elseif (preg_match('/Safari\//', $ua) && !preg_match('/Chrome/', $ua)) $browser = 'Safari';
    $parts = array_filter([$device, $browser]);
    return $parts ? implode(' · ', $parts) : 'Unknown';
}

/** 从 URL 取主机名(用于来源地址列的简短展示) */
function getHostFromReferer(string $url): string {
    if ($url === '') return '';
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($host) ? $host : '';
}

$pageTitle = '表单数据';
require __DIR__ . '/partials/header.php';
?>

<div class="stat-grid">
  <div class="stat-card"><div class="stat-head"><span>表单线索</span></div><div class="stat-num"><?= $leadTotal ?></div></div>
  <div class="stat-card"><div class="stat-head"><span>今日新增</span></div><div class="stat-num"><?= $leadToday ?></div></div>
  <div class="stat-card"><div class="stat-head"><span>待跟进</span><span class="tag amber">需联系</span></div><div class="stat-num"><?= $leadPending ?></div></div>
</div>

<div class="card">
  <div class="card-head">
    <h3>线索列表</h3>
    <div class="card-head-tools">
      <a class="btn ghost" href="forms.php?<?= e(http_build_query($filterParams + ['export' => 1])) ?>">导出 CSV</a>
      <?php if ($hasFilter): ?><a class="btn ghost" href="forms.php">清除筛选</a><?php endif; ?>
    </div>
  </div>

  <!-- 筛选栏 -->
  <form method="get" class="filter-bar">
    <div class="filter-field">
      <label>日期</label>
      <div class="dp" data-name="date">
        <button type="button" class="dp-trigger">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          <span class="dp-label"><?= !empty($_GET['date']) ? e(date('Y-m-d', strtotime($_GET['date']))) : '选择日期' ?></span>
        </button>
        <input type="hidden" name="date" value="<?= e((string)($_GET['date'] ?? '')) ?>">
      </div>
    </div>
    <div class="filter-field">
      <label>状态</label>
      <?php
        $curStatus = (int)($_GET['status'] ?? -1);
        // 用 .cs 自定义下拉:trigger 显示当前值,option 含 "全部状态"
        $statusOpts = ['-1' => '全部状态'] + $statusMap;
      ?>
      <div class="cs cs-filter" data-current="<?= $curStatus ?>">
        <button type="button" class="cs-trigger">
          <span class="cs-label"><?= e($statusOpts[(string)$curStatus] ?? $statusOpts['-1']) ?></span>
          <svg class="cs-arrow" width="10" height="6" viewBox="0 0 10 6" fill="none"><path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <ul class="cs-menu">
          <?php foreach ($statusOpts as $sk => $sl): ?>
          <li data-value="<?= $sk ?>" class="cs-opt<?= (int)$sk === $curStatus ? ' active' : '' ?><?= $sk !== '-1' ? ' s' . (int)$sk : '' ?>"><?= e($sl) ?></li>
          <?php endforeach; ?>
        </ul>
        <select name="status" class="cs-native" tabindex="-1" aria-hidden="true">
          <?php foreach ($statusOpts as $sk => $sl): ?>
          <option value="<?= e((string)$sk) ?>" <?= (int)$sk === $curStatus ? 'selected' : '' ?>><?= e($sl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <button type="submit" class="btn sm primary">查询</button>
    <a class="btn sm ghost" href="forms.php">清空</a>
  </form>

  <?php if ($hasFilter): ?>
    <div class="filter-info">
      当前筛选:<strong><?= e((string)($_GET['date'] ?? '全部日期')) ?></strong> · <strong><?= isset($_GET['status']) && $_GET['status'] !== '' ? e($statusMap[(int)$_GET['status']] ?? '—') : '全部状态' ?></strong>
      · 共 <strong><?= (int)$total ?></strong> 条结果
    </div>
  <?php endif; ?>

  <?php if (empty($leads)): ?>
  <div class="empty"><?= $hasFilter ? '当前筛选条件下无数据' : '暂无表单数据,访客在前端页面提交表单后会出现在这里' ?></div>
  <?php else: ?>
  <div class="table-card">
    <table>
      <thead><tr><th>ID</th><th>访客</th><th>留言</th><th>联系电话</th><th>提交设备</th><th>来源地址</th><th>提交时间</th><th>状态</th><th>操作</th></tr></thead>
      <tbody>
      <?php foreach ($leads as $l): ?>
      <tr>
        <td>#<?= $l['id'] ?></td>
        <td><strong><?= e($l['visitor_name']) ?></strong></td>
        <td class="lead-msg-cell">
          <?php
            // 把整条线索打包成 JSON,点击留言即可查看完整详情(含扩展字段)
            $leadData = [
              'id'        => (int)$l['id'],
              'name'      => (string)$l['visitor_name'],
              'phone'     => (string)$l['visitor_phone'],
              'message'   => (string)($l['message'] ?? ''),
              'ip'        => (string)($l['ip'] ?? ''),
              'device'    => parseUserAgent((string)($l['user_agent'] ?? '')),
              'referer'   => (string)($l['referer'] ?? ''),
              'time'      => (string)$l['created_at'],
              'extra'     => !empty($l['extra']) ? (json_decode((string)$l['extra'], true) ?: []) : [],
              'page'      => (string)($l['page_name'] ?? ''),
              'status'    => (int)$l['status'],
            ];
            $leadJson = htmlspecialchars(json_encode($leadData, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
            $extrasArr = $leadData['extra'];
            $extrasCount = is_array($extrasArr) ? count($extrasArr) : 0;
          ?>
          <?php if (!empty($l['message']) || $extrasCount > 0): ?>
            <span class="lead-msg" data-lead="<?= $leadJson ?>" onclick="openLeadModal(this.dataset.lead)" title="点击查看完整线索详情"><?= e(mb_strimwidth((string)($l['message'] ?? ''), 0, 24, '…')) ?></span>
            <?php if ($extrasCount > 0): ?>
              <span class="extra-badge" title="包含 <?= $extrasCount ?> 个扩展字段">+<?= $extrasCount ?></span>
            <?php endif; ?>
          <?php else: ?>
            <span class="text-mute">—</span>
          <?php endif; ?>
        </td>
        <td><code><?= e($l['visitor_phone']) ?></code></td>
        <td>
          <code class="lead-ip"><?= e($l['ip'] ?: '—') ?></code>
          <div class="lead-device"><?= e(parseUserAgent((string)($l['user_agent'] ?? ''))) ?></div>
        </td>
        <td class="lead-ref">
          <?php $refHost = getHostFromReferer((string)($l['referer'] ?? '')); ?>
          <?php if ($refHost): ?>
            <a href="<?= e((string)$l['referer']) ?>" target="_blank" rel="noopener" title="<?= e((string)$l['referer']) ?>"><?= e($refHost) ?></a>
          <?php else: ?>
            <span class="text-mute">直接访问</span>
          <?php endif; ?>
        </td>
        <td><?= e(date('Y-m-d H:i', strtotime($l['created_at']))) ?></td>
        <td>
          <form method="post" class="status-form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="id" value="<?= $l['id'] ?>">
            <div class="cs" data-current="<?= (int)$l['status'] ?>">
              <button type="button" class="cs-trigger s<?= (int)$l['status'] ?>">
                <span class="cs-label"><?= e($statusMap[(int)$l['status']] ?? '新线索') ?></span>
                <svg class="cs-arrow" width="10" height="6" viewBox="0 0 10 6" fill="none"><path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
              <ul class="cs-menu">
                <?php foreach ($statusMap as $sk => $sl): ?>
                <li data-value="<?= $sk ?>" class="cs-opt s<?= $sk ?><?= (int)$l['status'] === $sk ? ' active' : '' ?>"><?= e($sl) ?></li>
                <?php endforeach; ?>
              </ul>
              <select name="status" class="cs-native" tabindex="-1" aria-hidden="true">
                <?php foreach ($statusMap as $sk => $sl): ?>
                <option value="<?= $sk ?>" <?= (int)$l['status'] === $sk ? 'selected' : '' ?>><?= e($sl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>
        </td>
        <td class="acts">
          <form method="post" style="display:inline" onsubmit="return confirm('确定删除这条线索吗?')">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $l['id'] ?>">
            <button class="btn sm danger-ghost" type="submit">删除</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="pagination">
    <span>共 <?= $total ?> 条线索</span>
    <div>
      <a class="btn ghost sm" href="forms.php?<?= e(http_build_query($filterParams + ['p' => max(1, $page - 1)])) ?>" <?= $page <= 1 ? 'style=visibility:hidden' : '' ?>>上一页</a>
      <span class="page-info"><?= $page ?> / <?= $pages ?></span>
      <a class="btn ghost sm" href="forms.php?<?= e(http_build_query($filterParams + ['p' => min($pages, $page + 1)])) ?>" <?= $page >= $pages ? 'style=visibility:hidden' : '' ?>>下一页</a>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- 线索详情弹窗 -->
<div class="modal-mask" id="leadModal" style="display:none">
  <div class="modal" style="max-width:600px">
    <div class="modal-head">
      <div>
        <h3 id="leadModalTitle">线索详情</h3>
        <p>完整字段信息(含动态扩展字段)</p>
      </div>
      <button class="modal-close" type="button" onclick="closeLeadModal()">×</button>
    </div>
    <div class="modal-body" id="leadModalBody"></div>
    <div class="modal-foot" style="justify-content:flex-end">
      <button class="btn ghost" type="button" onclick="closeLeadModal()">关闭</button>
    </div>
  </div>
</div>

<script>
/**
 * 线索详情弹窗(支持动态扩展字段)
 */
function openLeadModal(jsonStr) {
  if (!jsonStr) return;
  var data;
  try { data = JSON.parse(jsonStr); }
  catch (e) { console.error('lead JSON parse error', e); return; }

  document.getElementById('leadModalTitle').textContent =
    '线索 #' + (data.id || '?') + ' · ' + (data.name || '');

  var html = '<dl class="lead-detail">';
  function row(label, val) {
    if (val === undefined || val === null || val === '') return;
    html += '<dt>' + esc(label) + '</dt><dd>' + esc(String(val)) + '</dd>';
  }
  row('姓名',      data.name);
  row('手机',      data.phone);
  if (data.message) row('留言',  data.message);
  if (data.page)    row('来源页', data.page);
  row('IP',         data.ip);
  row('设备 / 浏览器', data.device);
  if (data.referer) row('来源 URL', data.referer);
  row('提交时间',   data.time);

  // 动态扩展字段(extra JSON 解码)
  if (data.extra && typeof data.extra === 'object') {
    var keys = Object.keys(data.extra);
    if (keys.length > 0) {
      html += '<dt class="ext-sep" colspan="2">扩展字段</dt>';
      keys.forEach(function (k) {
        html += '<dt class="ext">' + esc(k) + '</dt><dd class="ext">' + esc(data.extra[k]) + '</dd>';
      });
    }
  }
  html += '</dl>';
  document.getElementById('leadModalBody').innerHTML = html;
  document.getElementById('leadModal').style.display = 'flex';
}
function closeLeadModal() { document.getElementById('leadModal').style.display = 'none'; }
function esc(s) {
  return String(s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}
// 关闭:点遮罩 / Esc
document.getElementById('leadModal').addEventListener('click', function (e) {
  if (e.target === this) closeLeadModal();
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') closeLeadModal();
});

/**
 * 自定义下拉组件 .cs —— position: absolute 相对 .cs 容器(menu 保持在 .cs 内,CSS 子选择器生效)
 * 绝不用 position: fixed,避免祖先 transition/transform 创建 containing block 导致定位跑偏
 */
(function () {
  function closeAll(except) {
    document.querySelectorAll('.cs.open').forEach(function (o) {
      if (o !== except) closeCs(o);
    });
  }
  function openCs(cs) {
    var trigger = cs.querySelector('.cs-trigger');
    var menu = cs.querySelector('.cs-menu');
    if (!trigger || !menu) return;
    void trigger.offsetHeight; // 强制 reflow,菜单紧贴 trigger 下方
    cs.classList.add('open');
  }
  function closeCs(cs) {
    var menu = cs.querySelector('.cs-menu');
    if (menu) menu.style.cssText = '';
    cs.classList.remove('open');
  }
  function updateOpenMenus() {
    // absolute 定位的菜单随 .cs 滚动自动跟随。trigger 离开视口时关闭(避免溢出区域干扰)。
    document.querySelectorAll('.cs.open').forEach(function (cs) {
      var trigger = cs.querySelector('.cs-trigger');
      if (!trigger) return;
      var rect = trigger.getBoundingClientRect();
      if (rect.bottom < 0 || rect.top > window.innerHeight) closeCs(cs);
    });
  }

  document.querySelectorAll('.cs').forEach(function (cs) {
    var trigger = cs.querySelector('.cs-trigger');
    var menu = cs.querySelector('.cs-menu');
    var native = cs.querySelector('.cs-native');
    if (!trigger || !menu || !native) return;

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      if (cs.classList.contains('open')) {
        closeCs(cs);                 // 已打开 → 关闭自己
      } else {
        closeAll(cs);                // 关闭其他
        openCs(cs);                  // 然后再开自己
      }
    });

    menu.querySelectorAll('.cs-opt').forEach(function (li) {
      li.addEventListener('click', function (e) {
        e.stopPropagation();
        var v = li.getAttribute('data-value');
        trigger.querySelector('.cs-label').textContent = li.textContent.trim();
        trigger.className = 'cs-trigger s' + v;
        cs.setAttribute('data-current', v);
        menu.querySelectorAll('.cs-opt').forEach(function (x) { x.classList.remove('active'); });
        li.classList.add('active');
        native.value = v;
        closeCs(cs);
        var form = cs.closest('form');
        if (form) form.submit();
      });
    });
  });
  document.addEventListener('click', function () { closeAll(null); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAll(null); });
  // 滚动/resize 时让打开的菜单跟随 trigger + 不可见时自动关
  window.addEventListener('scroll', updateOpenMenus, true);
  window.addEventListener('resize', updateOpenMenus);
})();

/**
 * 自定义日期选择器 .dp —— 替换浏览器原生 <input type="date"> 弹出层
 */
(function () {
  var WDAYS = ['日', '一', '二', '三', '四', '五', '六'];
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function fmt(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function parseISO(s) { if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) return null; var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
  function startOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
  function startOfGrid(d) {
    var first = startOfMonth(d);
    var dow = first.getDay();
    return new Date(first.getFullYear(), first.getMonth(), 1 - dow);
  }
  function isSameDay(a, b) { return a && b && a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate(); }

  function buildPop(dp) {
    var pop = document.createElement('div');
    pop.className = 'dp-pop';
    pop.innerHTML =
      '<div class="dp-head">' +
      '  <button type="button" class="dp-nav" data-act="prev" aria-label="上月">‹</button>' +
      '  <span class="dp-title"></span>' +
      '  <button type="button" class="dp-nav" data-act="next" aria-label="下月">›</button>' +
      '</div>' +
      '<div class="dp-weekdays">' + WDAYS.map(function (w) { return '<span>' + w + '</span>'; }).join('') + '</div>' +
      '<div class="dp-grid"></div>' +
      '<div class="dp-foot">' +
      '  <button type="button" class="dp-act" data-act="clear">清除</button>' +
      '  <button type="button" class="dp-act dp-act-primary" data-act="today">今天</button>' +
      '</div>';
    dp.appendChild(pop);

    var title = pop.querySelector('.dp-title');
    var grid = pop.querySelector('.dp-grid');

    function render(view) {
      var today = new Date(); today.setHours(0, 0, 0, 0);
      var max = new Date(today.getFullYear(), today.getMonth(), today.getDate()); // 今天,不能选未来
      title.textContent = view.getFullYear() + '年 ' + pad(view.getMonth() + 1) + '月';
      grid.innerHTML = '';
      var start = startOfGrid(view);
      var selVal = dp._hidden.value;
      var sel = selVal ? parseISO(selVal) : null;
      for (var i = 0; i < 42; i++) {
        var d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dp-day';
        btn.textContent = d.getDate();
        if (d.getMonth() !== view.getMonth()) btn.classList.add('out');
        if (isSameDay(d, today)) btn.classList.add('today');
        if (isSameDay(d, sel)) btn.classList.add('selected');
        if (d > max) { btn.disabled = true; btn.classList.add('disabled'); }
        else {
          btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var picked = new Date(+d);
            dp._hidden.value = fmt(picked);
            dp._label.textContent = fmt(picked);
            closePop();
          });
        }
        grid.appendChild(btn);
      }
    }

    pop.addEventListener('click', function (e) { e.stopPropagation(); });
    pop.querySelector('[data-act="prev"]').addEventListener('click', function () {
      render(new Date(view.getFullYear(), view.getMonth() - 1, 1));
    });
    pop.querySelector('[data-act="next"]').addEventListener('click', function () {
      var today = new Date();
      var next = new Date(view.getFullYear(), view.getMonth() + 1, 1);
      // 不允许浏览未来月份
      if (next.getFullYear() > today.getFullYear() || (next.getFullYear() === today.getFullYear() && next.getMonth() > today.getMonth())) return;
      render(next);
    });
    pop.querySelector('[data-act="clear"]').addEventListener('click', function () {
      dp._hidden.value = '';
      dp._label.textContent = '选择日期';
      closePop();
    });
    pop.querySelector('[data-act="today"]').addEventListener('click', function () {
      var t = new Date();
      dp._hidden.value = fmt(t);
      dp._label.textContent = fmt(t);
      closePop();
    });

    dp._render = render;
  }

  function positionPop(dp) {
    var rect = dp.getBoundingClientRect();
    var pop = dp.querySelector('.dp-pop');
    pop.style.position = 'fixed';
    pop.style.top = (rect.bottom + 6) + 'px';
    pop.style.left = rect.left + 'px';
    pop.style.minWidth = rect.width + 'px';
    pop.style.zIndex = '1000';
  }

  function closeAllDp(except) {
    document.querySelectorAll('.dp.open').forEach(function (o) {
      if (o !== except) o.classList.remove('open');
    });
  }
  function openDp(dp) {
    closeAllDp(dp);
    var pop = dp.querySelector('.dp-pop');
    if (!pop) buildPop(dp);
    dp.classList.add('open');
    positionPop(dp);
    // 默认 view:用 hidden 的值或今天
    var init = dp._hidden.value ? parseISO(dp._hidden.value) : new Date();
    dp._render(init);
  }

  document.querySelectorAll('.dp').forEach(function (dp) {
    dp._trigger = dp.querySelector('.dp-trigger');
    dp._label = dp.querySelector('.dp-label');
    dp._hidden = dp.querySelector('input[type="hidden"]');
    if (!dp._trigger || !dp._hidden) return;
    dp._trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      if (dp.classList.contains('open')) { dp.classList.remove('open'); return; }
      openDp(dp);
    });
  });
  document.addEventListener('click', function () { closeAllDp(null); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeAllDp(null); });

  function updateOpenDp() {
    document.querySelectorAll('.dp.open').forEach(function (dp) {
      var rect = dp.getBoundingClientRect();
      if (rect.bottom < 0 || rect.top > window.innerHeight) dp.classList.remove('open');
      else positionPop(dp);
    });
  }
  window.addEventListener('scroll', updateOpenDp, true);
  window.addEventListener('resize', updateOpenDp);
})();
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
