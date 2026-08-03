<?php
/**
 * 数据库封装层 —— 基于 PDO(MySQL)
 * 提供安全的预处理查询、安装建表、以及"环境检测"所需的连接测试。
 */

class Database
{
    private static ?PDO $pdo = null;

    /** 环境检测用:测试 MySQL 连接(不依赖 config.php,由安装向导调用) */
    public static function testConnection(string $host, string $port, string $user, string $pass, ?string $dbname = null): array
    {
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4';
        if ($dbname) {
            $dsn .= ';dbname=' . $dbname;
        }
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $version = $pdo->query('SELECT VERSION()')->fetchColumn();
            return ['success' => true, 'version' => $version];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** 建立主连接(使用 config.php 配置) */
    public static function connect(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return self::$pdo;
    }

    /** 便捷:预处理查询 */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** 取单行 */
    public static function row(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /** 取多行 */
    public static function rows(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    /** 取单值 */
    public static function value(string $sql, array $params = [])
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    /** 执行写入,返回受影响行数 */
    public static function exec(string $sql, array $params = []): int
    {
        return self::query($sql, $params)->rowCount();
    }

    /** 插入并返回自增 ID */
    public static function insert(string $sql, array $params = []): int
    {
        self::query($sql, $params);
        return (int) self::connect()->lastInsertId();
    }

    /**
     * 安装建表(安装向导第 2 步调用)
     * 使用传入的 $pdo 连接(可能指向新建的数据库),避免与主连接冲突。
     */
    public static function installTables(PDO $pdo, string $prefix): void
    {
        $pdo->exec("SET NAMES utf8mb4");

        // 管理员表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}users` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(64) NOT NULL,
            `password` VARCHAR(255) NOT NULL,
            `email` VARCHAR(128) DEFAULT '',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_username` (`username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员'");

        // 变量表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}variables` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `group_name` VARCHAR(64) NOT NULL DEFAULT 'default',
            `var_key` VARCHAR(64) NOT NULL,
            `var_name` VARCHAR(64) NOT NULL,
            `var_type` VARCHAR(16) NOT NULL DEFAULT 'text',
            `var_value` TEXT,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_key` (`var_key`),
            KEY `idx_group` (`group_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='变量'");

        // 表单线索表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}leads` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `page_name` VARCHAR(128) NOT NULL DEFAULT '',
            `visitor_name` VARCHAR(64) NOT NULL DEFAULT '',
            `visitor_phone` VARCHAR(32) NOT NULL DEFAULT '',
            `message` TEXT,
            `extra` TEXT COMMENT 'JSON 序列化的额外字段(年龄/性别/城市等)',
            `ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '客户IP',
            `user_agent` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '原始UA',
            `referer` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '来源页面',
            `status` TINYINT NOT NULL DEFAULT 0 COMMENT '0新线索 1已联系 2已成交',
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表单线索'");

        // 兼容旧库升级:为已有 leads 表追加 ip / user_agent / referer / extra 列(MySQL 5.7 无 IF NOT EXISTS,手工检查)
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `{$prefix}leads`")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('extra', $cols, true))      $pdo->exec("ALTER TABLE `{$prefix}leads` ADD COLUMN `extra` TEXT COMMENT 'JSON 序列化的额外字段' AFTER `message`");
            if (!in_array('ip', $cols, true))         $pdo->exec("ALTER TABLE `{$prefix}leads` ADD COLUMN `ip` VARCHAR(45) NOT NULL DEFAULT '' COMMENT '客户IP' AFTER `extra`");
            if (!in_array('user_agent', $cols, true)) $pdo->exec("ALTER TABLE `{$prefix}leads` ADD COLUMN `user_agent` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '原始UA' AFTER `ip`");
            if (!in_array('referer', $cols, true))    $pdo->exec("ALTER TABLE `{$prefix}leads` ADD COLUMN `referer` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '来源页面' AFTER `user_agent`");
        } catch (Throwable $e) {
            // 升级失败不致命(空库等情况)
        }

        // 系统设置表
        $pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}settings` (
            `setting_key` VARCHAR(64) NOT NULL,
            `setting_value` TEXT,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统设置'");
    }

    /** 安装时写入默认变量(演示数据) */
    public static function seedVariables(PDO $pdo, string $prefix): void
    {
        // 精简版默认变量:只保留每个落地页都需要的核心变量
        // (后台已去分组 UI,统一存 default 分组)
        $defaults = [
            ['default', 'site_name',       '站点名称',   'text', '速页CMS 展示页'],
            ['default', 'brand_color',     '主题色',     'color', '#2F7CF6'],
            ['default', 'hero_title',      '主标题',     'text', '欢迎光临,点击咨询'],
            ['default', 'hero_subtitle',   '副标题',     'text', '专业的服务,值得信赖'],
            ['default', 'contact_phone',   '联系电话',   'text', '400-888-5621'],
            ['default', 'wechat_id',       '微信号',     'text', 'service_wechat_2026'],
            ['default', 'submit_text',     '提交按钮文字', 'text', '立即咨询'],
        ];
        // INSERT IGNORE:变量已存在(如重复安装/重复点击测试)则跳过,不覆盖已有值
        $stmt = $pdo->prepare("INSERT IGNORE INTO `{$prefix}variables` (`group_name`,`var_key`,`var_name`,`var_type`,`var_value`) VALUES (?,?,?,?,?)");
        foreach ($defaults as $v) {
            $stmt->execute($v);
        }
    }
}
