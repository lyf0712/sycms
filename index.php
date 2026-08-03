<?php
/**
 * 前端入口
 *
 * 作用:
 *   1. 渲染 templates/index.html,并将 {{ 变量名 }} 替换为后台设置的值
 *   2. 接收落地页表单提交(POST 到本文件),写入数据库线索表
 *   3. 未安装时跳转到安装向导
 */

require __DIR__ . '/lib/bootstrap.php';

// ---- 静态资源路由 ----
// 把 templates/ 目录作为静态资源根:请求 style.css / main.js / logo.png 等
// 时,自动从 templates/ 读取并输出,让模板里可以写 <link href="style.css"> 这种干净路径。
$staticExts = [
    'css'   => 'text/css',
    'js'    => 'application/javascript',
    'mjs'   => 'application/javascript',
    'png'   => 'image/png',
    'jpg'   => 'image/jpeg',
    'jpeg'  => 'image/jpeg',
    'gif'   => 'image/gif',
    'svg'   => 'image/svg+xml',
    'webp'  => 'image/webp',
    'ico'   => 'image/x-icon',
    'woff'  => 'font/woff',
    'woff2' => 'font/woff2',
    'ttf'   => 'font/ttf',
];
$reqPath = ltrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$ext = strtolower(pathinfo($reqPath, PATHINFO_EXTENSION));
if (isset($staticExts[$ext])) {
    // 安全校验:拒绝路径穿越与子目录(仅允许 templates/ 下的一层文件)
    if (strpos($reqPath, '..') !== false || strpos($reqPath, '\\') !== false || strpos($reqPath, '/') !== false) {
        http_response_code(404);
        exit('Not Found');
    }
    $candidate = TEMPLATE_DIR . DIRECTORY_SEPARATOR . $reqPath;
    if (is_file($candidate)) {
        header('Content-Type: ' . $staticExts[$ext]);
        header('Cache-Control: public, max-age=3600');
        readfile($candidate);
        exit;
    }
    http_response_code(404);
    exit('Not Found');
}

// 未安装 → 跳转安装向导
if (!defined('CMS_INSTALLED') || CMS_INSTALLED !== true) {
    header('Location: install.php');
    exit;
}

// ---- 处理表单提交 ----
$submitMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify($_POST['_csrf'] ?? null);
    $name = trim((string)($_POST['visitor_name'] ?? ''));
    $phone = trim((string)($_POST['visitor_phone'] ?? ''));
    $message = trim((string)($_POST['message'] ?? ''));
    $page = trim((string)($_POST['page_name'] ?? ''));

    // 客户环境:IP(兼容反向代理)、UA、来路
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // 取最左侧(最近客户端)IP,若经多层代理只信第一个
        $candidates = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
        $ip = trim($candidates[0]) ?: $ip;
    }
    $userAgent = mb_strimwidth((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 250, '…');
    $referer = mb_strimwidth((string)($_SERVER['HTTP_REFERER'] ?? ''), 0, 250, '…');

    // 扩展字段:除上述固定字段与 csrf token 外,所有 POST 字段都进入 extra(JSON)
    // 不同落地页表单(input name="age"/"gender"/"city" 等)的额外项自动落库,无需改 schema
    $baseFields = ['_csrf', 'visitor_name', 'visitor_phone', 'message', 'page_name'];
    $extra = [];
    foreach ($_POST as $key => $value) {
        if (in_array($key, $baseFields, true)) continue;
        if (is_string($value)) {
            $extra[$key] = trim($value);
        }
    }
    $extraJson = !empty($extra) ? json_encode($extra, JSON_UNESCAPED_UNICODE) : null;

    if ($name === '' || $phone === '') {
        $submitMsg = ['type' => 'error', 'text' => '请填写姓名和联系电话'];
    } elseif (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
        $submitMsg = ['type' => 'error', 'text' => '请输入正确的手机号码'];
    } else {
        Database::insert(
            "INSERT INTO `" . DB_PREFIX . "leads` (`page_name`,`visitor_name`,`visitor_phone`,`message`,`extra`,`ip`,`user_agent`,`referer`,`status`) VALUES (?,?,?,?,?,?,?,?,0)",
            [$page, $name, $phone, $message, $extraJson, $ip, $userAgent, $referer]
        );
        $submitMsg = ['type' => 'success', 'text' => '提交成功,我们将尽快与您联系!'];
    }
}

// ---- 渲染模板(注入 CSRF token 供前端表单使用) ----
$content = Template::renderFile('index.html', ['csrf_token' => csrf_token()]);

// 把表单回执注入页面(在 </body> 前插入提示条)
if ($submitMsg) {
    $banner = '<div class="cms-form-banner cms-form-' . e($submitMsg['type']) . '" style="position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:9999;padding:12px 24px;border-radius:8px;font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;'
        . ($submitMsg['type'] === 'success' ? 'background:#F0FDF4;color:#10B981;border:1px solid #10B981;' : 'background:#FEF2F2;color:#EF4444;border:1px solid #EF4444;')
        . '">' . e($submitMsg['text']) . '</div>';
    $content = str_replace('</body>', $banner . '</body>', $content);
}

// 输出
header('Content-Type: text/html; charset=utf-8');
echo $content;
