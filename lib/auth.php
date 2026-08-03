<?php
/**
 * 认证与安全辅助 —— 会话管理、密码哈希、CSRF、输入过滤
 */

session_start();

class Auth
{
    /** 当前登录管理员,未登录返回 null */
    public static function user(): ?array
    {
        if (empty($_SESSION['admin_id'])) {
            return null;
        }
        return Database::row("SELECT * FROM `" . DB_PREFIX . "users` WHERE `id` = ?", [$_SESSION['admin_id']]);
    }

    public static function check(): bool
    {
        return !empty($_SESSION['admin_id']);
    }

    /** 尝试登录 */
    public static function attempt(string $username, string $password): bool
    {
        $user = Database::row("SELECT * FROM `" . DB_PREFIX . "users` WHERE `username` = ?", [$username]);
        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_id'] = (int) $user['id'];
            $_SESSION['login_at'] = time();
            return true;
        }
        return false;
    }

    /** 修改管理员密码 */
    public static function changePassword(int $userId, string $newPassword): void
    {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        Database::exec("UPDATE `" . DB_PREFIX . "users` SET `password` = ? WHERE `id` = ?", [$hash, $userId]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    /** 未登录跳转到登录页 */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: ' . ADMIN_PATH . '/login.php');
            exit;
        }
    }
}

/** 生成 CSRF Token */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** 校验 CSRF Token,失败则中止 */
function csrf_verify(?string $token): void
{
    if (!is_string($token) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $token)) {
        http_response_code(403);
        exit('CSRF 校验失败,请刷新页面重试。');
    }
}

/** 输出转义(防 XSS) */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** 简洁的随机字符串 */
function str_random(int $length = 16): string
{
    return bin2hex(random_bytes((int) ceil($length / 2)));
}
