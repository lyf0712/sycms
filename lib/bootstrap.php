<?php
/**
 * 引导文件 —— 所有页面统一入口
 * 负责:加载配置、注册自动加载、基础常量。
 */

declare(strict_types=1);

// 加载配置文件(未安装时 config.php 不存在,用 example 兜底)
// 注意:本文件位于 lib/ 目录,config.php 在项目根目录
$configPath = dirname(__DIR__) . '/config.php';
if (file_exists($configPath)) {
    require $configPath;
} else {
    // 兜底常量,保证代码不因缺配置而报错
    define('DB_HOST', '127.0.0.1');
    define('DB_PORT', '3306');
    define('DB_NAME', 'landing_page_cms');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_PREFIX', 'lp_');
    define('SITE_NAME', '速页CMS');
    define('APP_VERSION', '1.0.0');
    define('APP_KEY', '');
    define('ADMIN_PATH', 'admin');
    define('TEMPLATE_DIR', dirname(__DIR__) . '/templates');
    define('DATA_DIR', dirname(__DIR__) . '/data');
    define('CMS_INSTALLED', false);
}

// 时区
date_default_timezone_set('Asia/Shanghai');

// 简单自动加载:lib/ 下同名类(本文件已在 lib/ 内,直接用 __DIR__)
spl_autoload_register(function ($class) {
    $file = __DIR__ . '/' . strtolower($class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// 加载认证(启动 session)
require __DIR__ . '/auth.php';

// 已装环境：每次请求轻量迁移检查,确保 leads 表有新字段
// (只有 CMS_INSTALLED 时才连 DB,SHOW COLUMNS + 条件判断开销极小)
if (defined('CMS_INSTALLED') && CMS_INSTALLED === true && class_exists('Database')) {
    try {
        $pdo = Database::connect();
        $prefix = DB_PREFIX;
        $cols = $pdo->query("SHOW COLUMNS FROM `{$prefix}leads`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('extra', $cols, true))      $pdo->exec("ALTER TABLE `{$prefix}leads` ADD COLUMN `extra` TEXT COMMENT 'JSON 序列化的额外字段' AFTER `message`");
        if (!in_array('ip', $cols, true))         $pdo->exec("ALTER TABLE `{$prefix}leads` ADD COLUMN `ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '客户IP' AFTER `extra`");
        if (!in_array('user_agent', $cols, true)) $pdo->exec("ALTER TABLE `{$prefix}leads` ADD COLUMN `user_agent` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '原始UA' AFTER `ip`");
        if (!in_array('referer', $cols, true))    $pdo->exec("ALTER TABLE `{$prefix}leads` ADD COLUMN `referer` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '来源页面' AFTER `user_agent`");
    } catch (Throwable $e) {
        // 迁移失败忽略(可能是空库或非 MySQL 环境)
    }
}
