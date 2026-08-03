<?php
/**
 * 模板文件管理 —— 三栏布局
 * 左侧文件树 + 中央代码编辑器 + 右侧变量映射
 *
 * 前端文件存放在 templates/ 目录,文件内使用 {{ key }} 占位符,
 * 部署后由 Template 引擎自动替换为变量值。
 */

require dirname(__DIR__) . '/lib/bootstrap.php';
Auth::requireLogin();

$prefix = DB_PREFIX;
$msg = '';

// 获取 templates/ 下所有文件
function list_template_files(): array
{
    $files = glob(TEMPLATE_DIR . '/*.*') ?: [];
    $list = [];
    foreach ($files as $f) {
        if (is_file($f)) {
            $list[] = basename($f);
        }
    }
    sort($list);
    return $list;
}

// 允许的模板文件扩展名(与上传白名单一致;禁止 php 等可执行文件,防 RCE)
function isAllowedTemplateName(string $file): bool
{
    if ($file === '' || strpos($file, '/') !== false || strpos($file, '\\') !== false || strpos($file, '..') !== false) {
        return false;
    }
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $allowed = ['html', 'htm', 'css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'txt', 'json', 'woff', 'woff2'];
    return in_array($ext, $allowed, true);
}

// ---- 保存文件 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_file') {
    csrf_verify($_POST['_csrf'] ?? null);
    $file = trim((string)($_POST['file'] ?? ''));
    $content = (string)($_POST['content'] ?? '');
    // 安全校验:仅允许 templates/ 一级文件 + 白名单扩展名
    if (!isAllowedTemplateName($file)) {
        $msg = ['type' => 'error', 'text' => '文件名不合法或扩展名不被允许'];
    } else {
        $path = TEMPLATE_DIR . '/' . $file;
        if (@file_put_contents($path, $content) !== false) {
            $msg = ['type' => 'success', 'text' => '文件已保存:' . $file];
        } else {
            $msg = ['type' => 'error', 'text' => '保存失败,请检查 templates/ 目录写入权限'];
        }
    }
}

// ---- 新建文件 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_file') {
    csrf_verify($_POST['_csrf'] ?? null);
    $file = trim((string)($_POST['new_file'] ?? ''));
    if (!isAllowedTemplateName($file)) {
        $msg = ['type' => 'error', 'text' => '文件名不合法或扩展名不被允许'];
    } elseif (file_exists(TEMPLATE_DIR . '/' . $file)) {
        $msg = ['type' => 'error', 'text' => '文件已存在:' . $file];
    } else {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $starter = $ext === 'html' ? '<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>{{ site_name }}</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
  <h1>{{ hero_title }}</h1>
  <p>{{ hero_subtitle }}</p>
  <section class="contact">
    咨询电话:{{ contact_phone }}
    微信:{{ wechat_id }}
  </section>
  <button>{{ submit_text }}</button>
</body>
</html>' : ($ext === 'css' ? '/* {{ site_name }} 样式 */\nbody { font-family: sans-serif; }' : '// {{ site_name }} 脚本\n');
        if (@file_put_contents(TEMPLATE_DIR . '/' . $file, $starter) !== false) {
            $msg = ['type' => 'success', 'text' => '文件已创建:' . $file];
        } else {
            $msg = ['type' => 'error', 'text' => '创建失败,请检查 templates/ 目录写入权限'];
        }
    }
}

// ---- 删除文件 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_file') {
    csrf_verify($_POST['_csrf'] ?? null);
    $file = trim((string)($_POST['file'] ?? ''));
    if ($file === '' || strpos($file, '/') !== false || strpos($file, '\\') !== false || strpos($file, '..') !== false) {
        $msg = ['type' => 'error', 'text' => '文件名不合法'];
    } else {
        @unlink(TEMPLATE_DIR . '/' . $file);
        $msg = ['type' => 'success', 'text' => '文件已删除:' . $file];
    }
}

// ---- 上传文件(支持多文件) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_file') {
    csrf_verify($_POST['_csrf'] ?? null);
    $files = $_FILES['upload'] ?? null;
    if (!$files || empty($files['name'][0])) {
        $msg = ['type' => 'error', 'text' => '请选择要上传的文件'];
    } else {
        $allowed = ['html', 'htm', 'css', 'js', 'mjs', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'ico', 'txt', 'json', 'woff', 'woff2'];
        $ok = 0; $fail = [];
        $count = is_array($files['name']) ? count($files['name']) : 1;
        for ($i = 0; $i < $count; $i++) {
            $name = basename((string)$files['name'][$i]);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $err = (int)$files['error'][$i];
            if (!in_array($ext, $allowed, true)) {
                $fail[] = $name . ' (类型不允许)';
            } elseif ($err !== UPLOAD_ERR_OK) {
                $fail[] = $name . ' (上传失败)';
            } elseif (@move_uploaded_file($files['tmp_name'][$i], TEMPLATE_DIR . '/' . $name)) {
                $ok++;
            } else {
                $fail[] = $name . ' (写入失败)';
            }
        }
        if ($ok && !$fail) {
            $msg = ['type' => 'success', 'text' => "已上传 {$ok} 个文件"];
        } elseif ($ok) {
            $msg = ['type' => 'success', 'text' => "已上传 {$ok} 个,失败 " . count($fail) . ' 个:' . implode(', ', $fail)];
        } else {
            $msg = ['type' => 'error', 'text' => '全部上传失败:' . implode(', ', $fail)];
        }
    }
}

$files = list_template_files();
$currentFile = (string)($_GET['file'] ?? ($files[0] ?? ''));
$fileContent = '';
$fileScans = [];

if ($currentFile !== '' && in_array($currentFile, $files, true)) {
    $fileContent = (string) file_get_contents(TEMPLATE_DIR . '/' . $currentFile);
    $fileScans = Template::scanFile($currentFile);
}

// 所有文件的变量使用情况
$allScans = Template::scanAll();
$allVars = Template::allVariables();

// 注入 CodeMirror 编辑器资源(仅本页加载)
$headExtra = '<link rel="stylesheet" href="../assets/codemirror/lib/codemirror.css">'
    . '<link rel="stylesheet" href="../assets/codemirror/theme/dracula.css">';

$pageTitle = '模板文件';
require __DIR__ . '/partials/header.php';
?>

<div class="tpl-layout">
  <!-- 左侧文件树 -->
  <aside class="tpl-tree">
    <div class="tpl-tree-head">
      <strong>templates/</strong>
      <button class="mini-add" onclick="document.getElementById('newFileBox').style.display='block'">+</button>
    </div>
    <div id="newFileBox" style="display:none" class="new-file-box">
      <form method="post" class="new-file-form">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="action" value="create_file">
        <input type="text" name="new_file" placeholder="new-page.html" required>
        <button type="submit" class="btn sm primary">创建</button>
      </form>
    </div>
    <div class="tree-list">
      <?php foreach ($files as $f): ?>
      <div class="tree-item-wrap <?= $f === $currentFile ? 'active' : '' ?>">
        <a href="templates.php?file=<?= urlencode($f) ?>" class="tree-item">
          <span class="tree-icon"><?= strpos($f, '.css') !== false ? 'C' : (strpos($f, '.js') !== false ? 'J' : 'H') ?></span>
          <span class="tree-name"><?= e($f) ?></span>
          <em><?= count($allScans[$f] ?? []) ?></em>
        </a>
        <form method="post" class="tree-del-form" onsubmit="return confirm('确定删除文件 <?= e(addslashes($f)) ?> 吗？此操作不可撤销')">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="action" value="delete_file">
          <input type="hidden" name="file" value="<?= e($f) ?>">
          <button type="submit" class="tree-del" title="删除文件">×</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="tree-tip">
      将前端文件放入此目录即可挂载,文件代码中的 {{ key }} 会被自动替换为后台变量值。
    </div>
    <label class="btn ghost block upload-trigger" for="uploadInput">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      上传文件
    </label>
    <form method="post" enctype="multipart/form-data" id="uploadFormWrap" class="upload-form">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="upload_file">
      <input type="file" id="uploadInput" name="upload[]" multiple class="upload-input-hidden">
    </form>
    <div id="uploadStatus" class="upload-status"></div>
    <small class="upload-hint">支持一次选择多个文件</small>
  </aside>

  <!-- 中央代码编辑器 -->
  <section class="tpl-editor">
    <div class="editor-tabs">
      <?php foreach ($files as $f): ?>
      <a href="templates.php?file=<?= urlencode($f) ?>" class="tab <?= $f === $currentFile ? 'active' : '' ?>">
        <?= e($f) ?><?php if ($f === $currentFile): ?><span class="tab-dot"></span><?php endif; ?>
      </a>
      <?php endforeach; ?>
    </div>
    <?php if ($msg): ?><div class="alert <?= $msg['type'] ?>" style="margin:12px 20px 0"><?= e($msg['text']) ?></div><?php endif; ?>
    <?php if ($currentFile !== ''): ?>
    <form method="post" class="editor-body" id="editorForm">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save_file">
      <input type="hidden" name="file" value="<?= e($currentFile) ?>">
      <textarea name="content" id="codeArea" spellcheck="false"><?= e($fileContent) ?></textarea>
      <div class="editor-status">
        <span class="st-left">templates/<?= e($currentFile) ?></span>
        <span class="st-right"><?= substr_count($fileContent, '{{') ?> 个 {{ }} 占位符 · <?= strtoupper(pathinfo($currentFile, PATHINFO_EXTENSION)) ?> · UTF-8</span>
      </div>
      <div class="editor-actions">
        <button type="submit" class="btn primary">保存文件</button>
      </div>
    </form>
    <?php else: ?>
    <div class="empty" style="margin:40px">templates/ 目录暂无文件,请先创建或上传</div>
    <?php endif; ?>
  </section>

  <!-- 右侧变量映射 -->
  <aside class="tpl-map">
    <div class="map-head">
      <strong>本页使用的变量</strong>
      <span>在 <?= e($currentFile ?: '—') ?> 中被引用的变量</span>
    </div>
    <div class="map-list">
      <?php if (empty($fileScans)): ?>
      <div class="empty">当前文件没有使用变量</div>
      <?php else: foreach ($fileScans as $key => $count): ?>
      <div class="map-item">
        <code>{{ <?= e($key) ?> }}</code>
        <span><?= $count ?> 处引用<?= !isset($allVars[$key]) ? ' · 未定义' : '' ?></span>
      </div>
      <?php endforeach; endif; ?>
    </div>
    <div class="map-tip">
      <span class="tip-ic">i</span>
      修改后台变量值后,点 发布更新 即生效
    </div>
  </aside>
</div>

<!-- CodeMirror 编辑器初始化 -->
<script src="../assets/codemirror/lib/codemirror.js"></script>
<script>
/**
 * 上传文件状态:选完后显示已选文件列表 + 大小,然后自动提交
 */
(function () {
  function fmtSize(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    return (b / 1048576).toFixed(2) + ' MB';
  }
  var input = document.getElementById('uploadInput');
  var status = document.getElementById('uploadStatus');
  var form = document.getElementById('uploadFormWrap');
  if (!input || !status || !form) return;

  input.addEventListener('change', function () {
    var files = Array.prototype.slice.call(this.files);
    if (!files.length) { status.innerHTML = ''; return; }
    var total = files.reduce(function (a, f) { return a + f.size; }, 0);
    var html = '<div class="upload-pending">';
    html += '<div class="upload-pending-head">📦 已选 <strong>' + files.length + '</strong> 个文件,共 ' + fmtSize(total) + '</div>';
    html += '<div class="upload-pending-files">';
    files.forEach(function (f) {
      html += '<span class="file-pill">' + escHtml(f.name) + '<em>' + fmtSize(f.size) + '</em></span>';
    });
    html += '</div><div class="upload-hint-inline">提交后将自动上传 ↑</div>';
    html += '</div>';
    status.innerHTML = html;
    // 自动提交
    form.submit();
  });
  function escHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
})();
</script>
<script>
/**
 * 简易 AMD shim:CodeMirror 5 的 mode 文件采用 UMD 模式,
 * 浏览器既不是 CommonJS 也不是 AMD 环境,导致 mode 没注册。
 * 在 mode 文件加载前注入 window.define,把 deps 解析成已注册的
 * 全局对象(Codemirror / Codemirror.modes.xxx),让 UMD 走 AMD 分支。
 */
window.define = function (deps, factory) {
  if (typeof deps === 'function') { factory = deps; deps = []; }
  var args = (deps || []).map(function (dep) {
    var file = String(dep).split('/').pop().replace(/\.js$/, '');
    if (file === 'codemirror') return window.CodeMirror;
    return window.CodeMirror && window.CodeMirror.modes ? window.CodeMirror.modes[file] : undefined;
  });
  factory.apply(null, args);
};
</script>
<script src="../assets/codemirror/mode/xml.js"></script>
<script src="../assets/codemirror/mode/css.js"></script>
<script src="../assets/codemirror/mode/javascript.js"></script>
<script src="../assets/codemirror/mode/htmlmixed.js"></script>
<script>
// 等待所有 mode 文件(异步 define 注册)与 DOM 就绪后再初始化编辑器
window.addEventListener('load', function () {
  var ta = document.getElementById('codeArea');
  if (!ta || typeof CodeMirror === 'undefined') return;

  // 根据扩展名自动选择 CodeMirror 语言模式
  var fileName = <?= json_encode($currentFile) ?> || '';
  var ext = (fileName.split('.').pop() || '').toLowerCase();
  var mode;
  if (ext === 'css') {
    mode = 'css';
  } else if (ext === 'js' || ext === 'mjs') {
    mode = 'javascript';
  } else {
    mode = 'htmlmixed'; // html/htm 及默认 → HTML 混合(标签+内嵌 css/js)
  }

  // 用 setTimeout 推到下一个事件循环,确保所有 mode 的 define 都已执行
  setTimeout(function () {
    var editor = CodeMirror.fromTextArea(ta, {
      mode: mode,
      theme: 'dracula',
      lineNumbers: true,
      lineWrapping: true,
      indentUnit: 2,
      tabSize: 2,
      smartIndent: true,
      matchBrackets: true
    });

    // 保存前把编辑器内容同步回 textarea,随表单提交
    var form = document.getElementById('editorForm');
    if (form) form.addEventListener('submit', function () { editor.save(); });
  }, 0);
});
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>
