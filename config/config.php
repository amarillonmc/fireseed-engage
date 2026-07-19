<?php
// 种火集结号 - 主配置加载器 / Fireseed Engage - Main configuration loader

require_once __DIR__ . '/version.php';

// 本地配置不进入版本库，环境变量具有最高优先级 / Local configuration stays untracked and environment variables take precedence
$localConfig = [];
$localConfigPath = __DIR__ . '/local.php';
if (is_file($localConfigPath)) {
    $loadedLocalConfig = require $localConfigPath;
    if (!is_array($loadedLocalConfig)) {
        throw new RuntimeException(
            'config/local.php 必须返回数组 / config/local.php must return an array'
        );
    }
    $localConfig = $loadedLocalConfig;
}

/**
 * 读取环境或本地配置值 / Read an environment or local configuration value
 *
 * @param string $key 配置键 / Configuration key
 * @param mixed $default 默认值 / Default value
 * @return mixed 配置值 / Configuration value
 */
$readConfigValue = static function ($key, $default) use ($localConfig) {
    $environmentValue = getenv('FIRESEED_' . $key);
    if ($environmentValue !== false) {
        return $environmentValue;
    }
    return array_key_exists($key, $localConfig)
        ? $localConfig[$key]
        : $default;
};

/**
 * 将配置值规范化为布尔值 / Normalize a configuration value to boolean
 *
 * @param mixed $value 配置值 / Configuration value
 * @return bool 布尔值 / Boolean value
 */
$readConfigBoolean = static function ($value) {
    if (is_bool($value)) {
        return $value;
    }
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
};

// 数据库配置 / Database configuration
$databaseConfig = [
    'DB_HOST' => (string) $readConfigValue('DB_HOST', 'localhost'),
    'DB_USER' => (string) $readConfigValue('DB_USER', 'fireseed_user'),
    'DB_PASS' => (string) $readConfigValue('DB_PASS', ''),
    'DB_NAME' => (string) $readConfigValue('DB_NAME', 'fireseed_engage'),
    // 数据库架构与中文种子固定使用utf8mb4，不能由部署配置降级。 / The schema and Chinese seed data require utf8mb4 and deployment configuration cannot downgrade it.
    'DB_CHARSET' => 'utf8mb4'
];
foreach ($databaseConfig as $constantName => $constantValue) {
    if (!defined($constantName)) {
        define($constantName, $constantValue);
    }
}

// 站点与运行模式 / Site and runtime mode
define('SITE_NAME', (string) $readConfigValue('SITE_NAME', '种火集结号'));
define(
    'SITE_URL',
    rtrim(
        (string) $readConfigValue(
            'SITE_URL',
            'http://localhost/fireseed-engage'
        ),
        '/'
    )
);
define('ADMIN_EMAIL', (string) $readConfigValue('ADMIN_EMAIL', ''));
define(
    'DEBUG_MODE',
    $readConfigBoolean($readConfigValue('DEBUG_MODE', false))
);

// PHP、安装器、迁移与数据库会话统一使用上海时区。 / Keep PHP, the installer, migrations, and database sessions on Shanghai time.
date_default_timezone_set('Asia/Shanghai');

// 仅在显式信任反向代理时读取转发协议 / Read forwarded protocol only when the reverse proxy is explicitly trusted
$trustProxyHeaders = $readConfigBoolean(
    $readConfigValue('TRUST_PROXY_HEADERS', false)
);
$isHttpsRequest = (
    isset($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== ''
    && strtolower((string) $_SERVER['HTTPS']) !== 'off'
) || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
if (!$isHttpsRequest
    && $trustProxyHeaders
    && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
) {
    $forwardedProtocols = explode(
        ',',
        (string) $_SERVER['HTTP_X_FORWARDED_PROTO']
    );
    $isHttpsRequest = strtolower(trim($forwardedProtocols[0])) === 'https';
}

// 会话 Cookie 在应用启动前统一加固 / Harden session cookies before application startup
$sessionLifetime = max(
    300,
    (int) $readConfigValue('SESSION_LIFETIME', 86400)
);
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_lifetime', (string) $sessionLifetime);
    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    session_name('fireseed_engage');
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path' => '/',
        'secure' => $isHttpsRequest,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// 生产环境记录全部错误但不向响应输出内部细节 / Log all production errors without rendering internals in responses
error_reporting(E_ALL);
ini_set('log_errors', '1');
ini_set('display_errors', DEBUG_MODE ? '1' : '0');

// 加载游戏常量与运行变量 / Load game constants and runtime variables
require_once __DIR__ . '/game_constants.php';
require_once __DIR__ . '/game_variables.php';

unset(
    $databaseConfig,
    $isHttpsRequest,
    $loadedLocalConfig,
    $localConfig,
    $localConfigPath,
    $readConfigBoolean,
    $readConfigValue,
    $sessionLifetime,
    $trustProxyHeaders
);
