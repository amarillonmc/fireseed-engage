<?php
// 种火集结号 - 内测准入安全回归测试 / Fireseed Engage - Internal-beta release-gate security regression tests

$root = dirname(__DIR__);
$assertions = 0;

/**
 * 断言内测准入不变量 / Assert an internal-beta release-gate invariant
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertReleaseGate($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

$ranking = file_get_contents($root . '/ranking.php');
$mainScript = file_get_contents($root . '/assets/js/script.js');
$adminLogin = file_get_contents($root . '/admin/login.php');
$adminUsers = file_get_contents($root . '/admin/users.php');
$adminMap = file_get_contents($root . '/admin/map.php');
$legacyAdminMap = file_get_contents($root . '/admin/generate_map.php');
$assignGeneral = file_get_contents($root . '/assign_general.php');
$login = file_get_contents($root . '/login.php');
$register = file_get_contents($root . '/register.php');
$config = file_get_contents($root . '/config/config.php');
$init = file_get_contents($root . '/includes/init.php');
$logout = file_get_contents($root . '/logout.php');
$authSecurityPath = $root . '/includes/classes/AuthSecurity.php';
$authSecurity = file_get_contents($authSecurityPath);

foreach ([
    'ranking' => $ranking,
    'main script' => $mainScript,
    'admin login' => $adminLogin,
    'admin users' => $adminUsers,
    'admin map' => $adminMap,
    'legacy admin map' => $legacyAdminMap,
    'general assignment' => $assignGeneral,
    'login' => $login,
    'registration' => $register,
    'configuration' => $config,
    'initialization' => $init,
    'logout' => $logout,
    'authentication security' => $authSecurity
] as $sourceName => $source) {
    assertReleaseGate(
        $source !== false,
        "{$sourceName} source must be readable"
    );
}

assertReleaseGate(
    strpos($ranking, 'u.created_at') === false
        && strpos($ranking, 'u.registration_date AS created_at') !== false
        && strpos($ranking, 'CASE au.soldier_type') !== false
        && strpos($ranking, 'CASE au.type') === false,
    'Rankings must query the fresh users and army_units schema'
);

$notificationDeclaration = strpos(
    $mainScript,
    'function showNotification(message)'
);
$domReadyRegistration = strpos(
    $mainScript,
    "document.addEventListener('DOMContentLoaded'"
);
assertReleaseGate(
    $notificationDeclaration !== false
        && $domReadyRegistration !== false
        && $notificationDeclaration < $domReadyRegistration
        && strpos($mainScript, 'window.showNotification = showNotification;')
            !== false,
    'Notifications must be exported before independent scripts run'
);

assertReleaseGate(
    strpos($adminLogin, '$loginResult === true') === false
        && strpos($adminLogin, 'AuthSecurity::establishAuthenticatedSession(')
            !== false
        && strpos($adminLogin, 'AuthSecurity::getLoginThrottleStatus(')
            !== false,
    'Administrator login must accept an integer user id and establish a secure session'
);

assertReleaseGate(
    strpos($adminUsers, 'onclick="editUser') === false
        && strpos($adminUsers, 'data-username="') !== false
        && strpos($adminUsers, 'escapeHtml($userData[\'username\'])')
            !== false,
    'Administrator user actions must not interpolate usernames into JavaScript'
);

foreach ([
    'user administration' => $adminUsers,
    'map administration' => $adminMap,
    'legacy map administration' => $legacyAdminMap,
    'general assignment' => $assignGeneral
] as $operationName => $source) {
    assertReleaseGate(
        strpos($source, 'validateCsrfToken()') !== false
            && strpos($source, 'csrfField()') !== false,
        "{$operationName} mutations must validate and render a CSRF token"
    );
}

assertReleaseGate(
    strpos($config, "ini_set('session.use_strict_mode', '1')") !== false
        && strpos($config, "'httponly' => true") !== false
        && strpos($config, "'samesite' => 'Lax'") !== false
        && strpos($config, "'secure' => \$isHttpsRequest") !== false,
    'Session cookies must use strict, HttpOnly, SameSite, and HTTPS-aware settings'
);

assertReleaseGate(
    strpos($init, 'AuthSecurity::enforceMaintenanceMode(') !== false
        && strpos($register, 'AuthSecurity::getRegistrationAvailability(')
            !== false
        && strpos($register, "GET_LOCK(") !== false,
    'Maintenance, registration switches, and capacity must be enforced at runtime'
);

assertReleaseGate(
    strpos($login, 'AuthSecurity::establishAuthenticatedSession(') !== false
        && strpos($login, 'AuthSecurity::getLoginThrottleStatus(') !== false
        && strpos($logout, 'AuthSecurity::destroyAuthenticatedSession()')
            !== false
        && strpos($logout, 'validateCsrfToken()') !== false,
    'Player login must rotate sessions, throttle failures, and expose CSRF-safe logout'
);

require_once $authSecurityPath;

$scope = 'release_gate_test_' . bin2hex(random_bytes(8));
$username = 'test_' . bin2hex(random_bytes(8));
$_SERVER['REMOTE_ADDR'] = '127.0.0.42';
AuthSecurity::clearLoginFailures($scope, $username);

for ($attempt = 0; $attempt < 4; $attempt++) {
    AuthSecurity::recordLoginFailure($scope, $username);
}
$status = AuthSecurity::getLoginThrottleStatus($scope, $username);
assertReleaseGate(
    !empty($status['allowed'])
        && (int) $status['remaining_attempts'] === 1,
    'Four recent failures must leave exactly one login attempt'
);

AuthSecurity::recordLoginFailure($scope, $username);
$status = AuthSecurity::getLoginThrottleStatus($scope, $username);
assertReleaseGate(
    empty($status['allowed']) && (int) $status['retry_after'] > 0,
    'The fifth recent failure must temporarily block login'
);

AuthSecurity::clearLoginFailures($scope, $username);
$status = AuthSecurity::getLoginThrottleStatus($scope, $username);
assertReleaseGate(
    !empty($status['allowed'])
        && (int) $status['remaining_attempts'] === 5,
    'Successful authentication must be able to clear the throttle'
);

echo "Release-gate security tests passed: {$assertions} assertions.\n";
