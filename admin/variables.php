<?php
/**
 * 变量管理 —— 核心页面
 * 变量列表 + 添加/编辑/删除变量
 * 图片类型支持上传或填写 URL
 * 前端通过 {{ key }} 引用变量值,修改后页面自动更新。
 */

require dirname(__DIR__) . '/lib/bootstrap.php';
Auth::requireLogin();

$prefix = DB_PREFIX;
$msg = '';

// ---- 处理操作 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['_csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $varName = trim((string)($_POST['var_name'] ?? ''));
        $varKey = trim((string)($_POST['var_key'] ?? ''));
        $varType = trim((string)($_POST['var_type'] ?? 'text'));
        $varValue = (string)($_POST['var_value'] ?? '');
        // 分组 UI 已移除,后端保留 group_name 字段(默认空),不写入新分组
        $groupName = 'default';

        if ($varName === '' || $varKey === '') {
            $msg = ['type' => 'error', 'text' => '变量名称和 Key 不能为空'];
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $varKey)) {
            $msg = ['type' => 'error', 'text' => '变量 Key 只能包含字母、数字、下划线'];
        } else {
            // 检查 Key 重复
            $exists = Database::row("SELECT `id` FROM `{$prefix}variables` WHERE `var_key` = ? AND `id` != ?", [$varKey, $id]);
            if ($exists) {
                $msg = ['type' => 'error', 'text' => '变量 Key 已存在:' . $varKey];
            } elseif ($varType === 'image' && !empty($_FILES['image_file']['name']) && (int)($_FILES['image_file']['error'] ?? 99) === 0) {
                // 图片类型:优先处理上传
                // 允许常见位图格式;svg 可能含脚本(存储型 XSS),正式版默认禁止
                $allow = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                $ext = strtolower(pathinfo($_FILES['image_file']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allow, true)) {
                    $msg = ['type' => 'error', 'text' => '图片格式不支持,允许:' . implode(', ', $allow)];
                } elseif (!function_exists('getimagesize') || ($info = @getimagesize($_FILES['image_file']['tmp_name'])) === false) {
                    $msg = ['type' => 'error', 'text' => '文件不是有效图片'];
                } else {
                    $uploadDir = DATA_DIR . '/uploads';
                    if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0755, true); }
                    $fname = date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                    $dest = $uploadDir . '/' . $fname;
                    if (!move_uploaded_file($_FILES['image_file']['tmp_name'], $dest)) {
                        $msg = ['type' => 'error', 'text' => '图片上传失败,请检查 data/ 目录权限'];
                    } else {
                        $varValue = '../data/uploads/' . $fname;
                        $thisMsg = doSave($id, $prefix, $groupName, $varName, $varKey, $varType, $varValue);
                        $msg = $thisMsg;
                    }
                }
            } elseif ($varType === 'image') {
                // 图片类型但没上传:用 URL 输入框
                $varValue = trim((string)($_POST['var_image_url'] ?? ''));
                $msg = doSave($id, $prefix, $groupName, $varName, $varKey, $varType, $varValue);
            } else {
                $msg = doSave($id, $prefix, $groupName, $varName, $varKey, $varType, $varValue);
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        Database::exec("DELETE FROM `{$prefix}variables` WHERE `id` = ?", [$id]);
        $msg = ['type' => 'success', 'text' => '变量已删除'];
    }
}

function doSave(int $id, string $prefix, string $groupName, string $varName, string $varKey, string $varType, string $varValue): array {
    if ($id > 0) {
        Database::exec(
            "UPDATE `{$prefix}variables` SET `group_name`=?,`var_name`=?,`var_key`=?,`var_type`=?,`var_value`=? WHERE `id`=?",
            [$groupName, $varName, $varKey, $varType, $varValue, $id]
        );
        return ['type' => 'success', 'text' => '变量已更新'];
    }
    Database::insert(
        "INSERT INTO `{$prefix}variables` (`group_name`,`var_name`,`var_key`,`var_type`,`var_value`) VALUES (?,?,?,?,?)",
        [$groupName, $varName, $varKey, $varType, $varValue]
    );
    return ['type' => 'success', 'text' => '变量已添加'];
}

// ---- 变量数据 ----
$variables = Database::rows("SELECT * FROM `{$prefix}variables` ORDER BY `id` DESC");

$pageTitle = '变量管理';
require __DIR__ . '/partials/header.php';
?>

<div class="var-layout">
  <!-- 中间变量列表 -->
  <section class="var-main">
    <div class="var-toolbar">
      <div class="breadcrumb">
        <span>所有变量</span>
        <em><?= count($variables) ?> 个</em>
      </div>
      <div class="toolbar-right">
        <button class="btn ghost" onclick="location.href='variables.php'">刷新</button>
        <button class="btn primary" onclick="openModal(null)">+ 添加变量</button>
      </div>
    </div>

    <?php if ($msg): ?><div class="alert <?= $msg['type'] ?>"><?= e($msg['text']) ?></div><?php endif; ?>

    <div class="table-card">
      <table>
        <thead>
          <tr><th>变量</th><th>类型</th><th>当前值</th><th>状态</th><th>操作</th></tr>
        </thead>
        <tbody>
        <?php if (empty($variables)): ?>
          <tr><td colspan="5" class="empty">暂无变量,点击右上角"添加变量"创建</td></tr>
        <?php else: foreach ($variables as $v): ?>
          <tr>
            <td>
              <div class="var-cn"><?= e($v['var_name']) ?></div>
              <code class="var-key">{{ <?= e($v['var_key']) ?> }}</code>
            </td>
            <td><span class="type-tag"><?= e($v['var_type']) ?></span></td>
            <td class="var-value">
              <?php if ($v['var_type'] === 'image' && $v['var_value'] !== ''): ?>
                <img src="<?= e($v['var_value']) ?>" alt="" class="var-thumb">
              <?php elseif ($v['var_type'] === 'color'): ?>
                <span class="color-dot" style="background:<?= e($v['var_value']) ?>"></span><?= e($v['var_value']) ?>
              <?php else: ?>
                <?= e(mb_strimwidth((string)$v['var_value'], 0, 40, '…')) ?>
              <?php endif; ?>
            </td>
            <td><span class="tag green">已生效</span></td>
            <td class="acts">
              <a class="link" href="javascript:;" onclick="openModal(<?= (int)$v['id'] ?>)">编辑</a>
              <a class="link danger" href="javascript:;" onclick="delVar(<?= (int)$v['id'] ?>, '<?= e($v['var_key']) ?>')">删除</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- 右侧代码用法说明 -->
  <aside class="var-usage">
    <div class="usage-head">
      <strong>代码用法说明</strong>
      <span>在前端模板里直接写 {{ key }},部署后会自动替换</span>
    </div>
    <div class="usage-tabs" data-tabs>
      <button type="button" class="usage-tab active" data-tab="html">HTML</button>
      <button type="button" class="usage-tab" data-tab="css">CSS</button>
      <button type="button" class="usage-tab" data-tab="js">JS</button>
    </div>
    <div class="code-panels">
      <pre class="code-block active" data-tab="html"><span class="ln">1</span> &lt;h1&gt;<span class="hl">{{ hero_title }}</span>&lt;/h1&gt;
<span class="ln">2</span> &lt;p&gt;电话: <span class="hl">{{ contact_phone }}</span>&lt;/p&gt;
<span class="ln">3</span> &lt;a href="tel:<span class="hl">{{ contact_phone }}</span>"&gt;
<span class="ln">4</span>   微信: <span class="hl">{{ wechat_id }}</span>
<span class="ln">5</span> &lt;/a&gt;</pre>
      <pre class="code-block" data-tab="css"><span class="ln">1</span> <span class="kw">:root</span> { <span class="prop">--brand</span>: <span class="hl">{{ brand_color }}</span>; }
<span class="ln">2</span> .<span class="kw">banner</span> { <span class="prop">background</span>: <span class="fn">var</span>(--brand); }
<span class="ln">3</span> .phone { <span class="prop">color</span>: <span class="hl">{{ brand_color }}</span>; }
<span class="ln">4</span> .qr { <span class="prop">width</span>: <span class="num">160px</span>; }
<span class="ln">5</span> .address { <span class="prop">font-size</span>: <span class="num">14px</span>; }</pre>
      <pre class="code-block" data-tab="js"><span class="ln">1</span> <span class="kw">const</span> phone = <span class="str">"<span class="hl">{{ contact_phone }}</span>"</span>;
<span class="ln">2</span> document.<span class="fn">querySelector</span>(<span class="str">'.phone'</span>).textContent = phone;
<span class="ln">3</span> <span class="kw">const</span> wechat = <span class="str">"<span class="hl">{{ wechat_id }}</span>"</span>;
<span class="ln">4</span> <span class="kw">const</span> address = <span class="str">"<span class="hl">{{ store_address }}</span>"</span>;
<span class="ln">5</span> document.<span class="fn">querySelector</span>(<span class="str">'.wechat'</span>).textContent = wechat;</pre>
    </div>
    <div class="usage-tip">
      <span class="tip-ic">i</span>
      {{ key }} 中 key 必须与左侧变量 Key 完全一致,大小写敏感。
    </div>
  </aside>
</div>

<!-- 把所有变量数据嵌入页面,JS 弹窗编辑时直接读取并填充 -->
<script>
window.__VARS = <?php
$jsData = [];
foreach ($variables as $v) { $jsData[$v['id']] = $v; }
// JSON_HEX_TAG 等:防止变量值包含 </script> 等导致存储型 XSS
echo json_encode($jsData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>;
</script>

<!-- 添加/编辑弹窗 -->
<div class="modal-mask" id="varModal" style="display:none">
  <div class="modal">
    <div class="modal-head">
      <div>
        <h3 id="modalTitle">添加变量</h3>
        <p>定义变量后,前端代码用 {{ key }} 引用即可生效</p>
      </div>
      <button class="modal-close" onclick="closeModal()">×</button>
    </div>
    <form method="post" class="modal-body" id="varForm" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="f-id" value="">
      <div class="form-field">
        <label>变量名称 <em>* 必填</em></label>
        <input type="text" name="var_name" id="f-name" placeholder="如:客服微信号" required>
        <small>在变量管理列表中展示的中文名称</small>
      </div>
      <div class="form-field">
        <label>变量标识 (Key) <em>自动从名称生成</em></label>
        <input type="text" name="var_key" id="f-key" placeholder="service_wechat" required>
        <small>前端通过 {{ key }} 引用此变量的值,只能包含字母、数字、下划线</small>
      </div>
      <div class="form-field">
        <label>变量类型</label>
        <div class="type-grid">
          <label class="type-opt"><input type="radio" name="var_type" value="text" checked><span>文本</span></label>
          <label class="type-opt"><input type="radio" name="var_type" value="textarea"><span>多行文本</span></label>
          <label class="type-opt"><input type="radio" name="var_type" value="image"><span>图片</span></label>
          <label class="type-opt"><input type="radio" name="var_type" value="url"><span>链接</span></label>
          <label class="type-opt"><input type="radio" name="var_type" value="color"><span>颜色</span></label>
          <label class="type-opt"><input type="radio" name="var_type" value="number"><span>数字</span></label>
        </div>
      </div>

      <!-- 普通值字段(text/textarea/url/color/number) -->
      <div class="form-field" id="f-value-box">
        <label>变量值</label>
        <textarea name="var_value" id="f-value" rows="3" placeholder="变量值,前端将显示此内容"></textarea>
      </div>

      <!-- 图片类型专用:上传文件 + URL -->
      <div class="form-field" id="f-image-box" style="display:none">
        <label>上传新图片 <em>(可选,不上传则保留当前图)</em></label>
        <div class="file-trigger">
          <input type="file" name="image_file" id="f-image-file" accept="image/*">
          <button type="button" class="btn sm ghost" id="f-image-btn">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
            选择文件
          </button>
          <span class="file-name" id="f-image-name">未选择任何文件</span>
        </div>
        <small>支持 jpg/png/gif/webp/svg,文件保存在 data/uploads/</small>
        <label style="margin-top:10px">或填写图片地址</label>
        <input type="text" name="var_image_url" id="f-image-url" inputmode="url" placeholder="https://... 或 ../data/uploads/xxx.png">
        <small>留空则不修改图片值</small>
      </div>

      <div class="modal-foot">
        <span class="foot-hint">保存后变量立即生效,前端页面自动更新</span>
        <div>
          <button type="button" class="btn" onclick="closeModal()">取消</button>
          <button type="submit" class="btn primary">保存变量</button>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- 删除表单 -->
<form method="post" id="delForm" style="display:none">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="del-id" value="">
</form>

<script>
function openModal(id) {
  document.getElementById('varModal').style.display = 'flex';
  document.getElementById('modalTitle').textContent = id ? '编辑变量' : '添加变量';
  document.getElementById('f-id').value = id || '';
  // 清空基础字段
  document.getElementById('f-name').value = '';
  document.getElementById('f-key').value = '';
  document.getElementById('f-value').value = '';
  document.getElementById('f-image-url').value = '';
  document.getElementById('f-image-file').value = '';
  setImageName(''); // 清空文件选择显示
  // 默认类型文本
  document.querySelectorAll('input[name=var_type]').forEach(function(r){ r.checked = r.value === 'text'; });
  toggleImageBox();
  if (!id) return;

  // 编辑:从嵌入的 window.__VARS 读取并填充
  var data = window.__VARS && window.__VARS[id];
  if (!data) { alert('变量数据未找到,请刷新页面'); return; }
  document.getElementById('f-name').value = data.var_name || '';
  document.getElementById('f-key').value = data.var_key || '';
  document.getElementById('f-value').value = data.var_value || '';
  document.getElementById('f-image-url').value = data.var_value || '';
  document.querySelectorAll('input[name=var_type]').forEach(function(r){ r.checked = r.value === data.var_type; });
  toggleImageBox();
}

function toggleImageBox() {
  var isImage = document.querySelector('input[name=var_type]:checked').value === 'image';
  document.getElementById('f-value-box').style.display = isImage ? 'none' : '';
  document.getElementById('f-image-box').style.display = isImage ? '' : 'none';
}
// 类型切换时同步显示
document.querySelectorAll('input[name=var_type]').forEach(function(r){
  r.addEventListener('change', toggleImageBox);
});

// 自定义文件上传:点按钮触发原生 input.change() 并显示文件名
document.getElementById('f-image-btn').addEventListener('click', function () {
  document.getElementById('f-image-file').click();
});
document.getElementById('f-image-file').addEventListener('change', function () {
  setImageName(this.files && this.files[0] ? this.files[0].name : '');
});
function setImageName(name) {
  var el = document.getElementById('f-image-name');
  el.textContent = name || '未选择任何文件';
  el.classList.toggle('has-file', !!name);
}

function closeModal() { document.getElementById('varModal').style.display = 'none'; }
function delVar(id, key) {
  if (!confirm('确定删除变量 {{ ' + key + ' }} 吗?')) return;
  document.getElementById('del-id').value = id;
  document.getElementById('delForm').submit();
}
</script>

<?php require __DIR__ . '/partials/footer.php'; ?>