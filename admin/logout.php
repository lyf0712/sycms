<?php
/**
 * 退出登录
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
Auth::logout();
header('Location: login.php');
exit;
