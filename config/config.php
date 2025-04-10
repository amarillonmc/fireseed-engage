<?php
// 种火集结号 - 主配置文件
// 包含数据库连接信息和基本游戏设置

// 数据库配置
define('DB_HOST', 'localhost');
define('DB_USER', 'fireseed_user');
define('DB_PASS', 'your_password_here');
define('DB_NAME', 'fireseed_engage');
define('DB_CHARSET', 'utf8mb4');

// 网站基本设置
define('SITE_NAME', '种火集结号');
define('SITE_URL', 'http://localhost/fireseed-engage');
define('ADMIN_EMAIL', 'admin@example.com');

// 游戏基本设置
define('GAME_VERSION', '0.1.0');
define('DEBUG_MODE', true);

// 时区设置
date_default_timezone_set('Asia/Shanghai');

// 会话设置
ini_set('session.cookie_lifetime', 86400); // 24小时
ini_set('session.gc_maxlifetime', 86400); // 24小时
session_start();

// 错误报告设置
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// 包含其他配置文件
require_once 'game_constants.php';
require_once 'game_variables.php';
