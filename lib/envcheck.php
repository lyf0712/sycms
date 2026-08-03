<?php
/**
 * 环境检测库 —— 安装向导第 1 步使用
 * 逐项检测服务器环境是否满足运行要求,返回每项的支持状态。
 */

class EnvCheck
{
    public static function run(): array
    {
        $checks = [];

        // 1. 服务器操作系统
        $os = PHP_OS_FAMILY ?? (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'Windows' : 'Linux');
        $checks['os'] = [
            'name' => '服务器操作系统',
            'ok' => true,
            'value' => $os . ' ' . (function_exists('php_uname') ? php_uname('r') : ''),
            'hint' => '',
        ];

        // 2. Web 服务器
        $server = $_SERVER['SERVER_SOFTWARE'] ?? 'CLI';
        $serverName = preg_replace('/\s.*/', '', $server) ?: $server;
        $checks['server'] = [
            'name' => 'Web 服务器',
            'ok' => true,
            'value' => $serverName,
            'hint' => '',
        ];

        // 3. PHP 版本(需要 >= 7.4)
        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '7.4.0', '>=');
        $checks['php'] = [
            'name' => 'PHP 版本',
            'ok' => $phpOk,
            'value' => $phpVersion,
            'hint' => $phpOk ? '' : '需要 PHP >= 7.4,当前版本过低',
        ];

        // 4. PDO 扩展
        $pdoOk = extension_loaded('pdo');
        $checks['pdo'] = [
            'name' => 'PDO 扩展',
            'ok' => $pdoOk,
            'value' => $pdoOk ? '已启用' : '未安装',
            'hint' => $pdoOk ? '' : '需要启用 pdo 扩展',
        ];

        // 5. pdo_mysql 驱动
        $pdoMysql = extension_loaded('pdo_mysql');
        $checks['pdo_mysql'] = [
            'name' => 'pdo_mysql 驱动',
            'ok' => $pdoMysql,
            'value' => $pdoMysql ? '已启用' : '未安装',
            'hint' => $pdoMysql ? '' : '需要启用 pdo_mysql 扩展以连接 MySQL',
        ];

        // 6. GD 图片库(需要 >= 2.1)
        $gdOk = extension_loaded('gd');
        $gdVersion = $gdOk && defined('GD_VERSION') ? GD_VERSION : '';
        $checks['gd'] = [
            'name' => 'GD 图片库',
            'ok' => $gdOk,
            'value' => $gdOk ? ($gdVersion ?: '已启用') : '未安装',
            'hint' => $gdOk ? '' : '需要 GD 2.1+ 用于图片处理',
        ];

        // 7. cURL 扩展
        $curlOk = extension_loaded('curl');
        $curlVersion = '';
        if ($curlOk && function_exists('curl_version')) {
            $cv = curl_version();
            $curlVersion = $cv['version'] ?? '';
        }
        $checks['curl'] = [
            'name' => 'cURL 扩展',
            'ok' => $curlOk,
            'value' => $curlOk ? ($curlVersion ?: '已启用') : '未安装',
            'hint' => $curlOk ? '' : '需要启用 curl 扩展',
        ];

        // 8. 目录写入权限(data/ 与 config.php)
        $dataWritable = is_dir(DATA_DIR) ? is_writable(DATA_DIR) : @mkdir(DATA_DIR, 0755, true);
        $configWritable = is_writable(dirname(__DIR__) . '/config.php') || !file_exists(dirname(__DIR__) . '/config.php');
        $checks['writable'] = [
            'name' => '目录写入权限',
            'ok' => $dataWritable && $configWritable,
            'value' => 'data/ ' . ($dataWritable ? '可写' : '不可写') . ' · config.php ' . ($configWritable ? '可写' : '不可写'),
            'hint' => $dataWritable && $configWritable ? '' : '请检查 data/ 目录与 config.php 的写入权限(通常需要 755 或 775)',
        ];

        return $checks;
    }

    /** 是否全部通过 */
    public static function allPass(array $checks): bool
    {
        foreach ($checks as $c) {
            if (empty($c['ok'])) {
                return false;
            }
        }
        return true;
    }
}
