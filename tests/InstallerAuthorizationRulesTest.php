<?php
// 种火集结号 - 安装授权可移植性规则测试 / Fireseed Engage - portable installer authorization rule tests

$root = dirname(__DIR__);
require_once $root . '/includes/installer_authorization.php';

$assertions = 0;

/**
 * 断言安装授权规则 / Assert an installer authorization rule
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertInstallerAuthorizationRule($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

assertInstallerAuthorizationRule(
    isDirectInstallerLoopbackRequest([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost:8000'
    ]),
    'IPv4 loopback access without forwarding headers must be trusted'
);
assertInstallerAuthorizationRule(
    isDirectInstallerLoopbackRequest([
        'REMOTE_ADDR' => '::1',
        'HTTP_HOST' => '[::1]:8000'
    ]),
    'IPv6 loopback access without forwarding headers must be trusted'
);
assertInstallerAuthorizationRule(
    !isDirectInstallerLoopbackRequest([
        'REMOTE_ADDR' => '203.0.113.10',
        'HTTP_HOST' => 'localhost'
    ]),
    'A loopback Host header must not make a remote peer trusted'
);
assertInstallerAuthorizationRule(
    !isDirectInstallerLoopbackRequest([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'game.example.test'
    ]),
    'A public host must not qualify as direct loopback installation'
);
assertInstallerAuthorizationRule(
    !isDirectInstallerLoopbackRequest([
        'REMOTE_ADDR' => '127.0.0.1',
        'HTTP_HOST' => 'localhost',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.10'
    ]),
    'Forwarded client headers must disable direct-loopback trust'
);

assertInstallerAuthorizationRule(
    !isSecureInstallerRequest(['SERVER_PORT' => '443']),
    'Port 443 alone must not be treated as proof of TLS'
);
assertInstallerAuthorizationRule(
    isSecureInstallerRequest(['HTTPS' => 'on']),
    'An explicit web-server HTTPS signal must be trusted'
);
assertInstallerAuthorizationRule(
    !isSecureInstallerRequest(
        ['HTTP_X_FORWARDED_PROTO' => 'https'],
        false
    ),
    'A proxy protocol header must be ignored unless proxy trust is enabled'
);
assertInstallerAuthorizationRule(
    isSecureInstallerRequest(
        ['HTTP_X_FORWARDED_PROTO' => 'https'],
        true
    ),
    'A trusted proxy protocol header may provide explicit TLS evidence'
);

$temporaryRoot = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'fireseed_installer_auth_'
    . bin2hex(random_bytes(8));
$temporaryConfig = $temporaryRoot . DIRECTORY_SEPARATOR . 'config';
if (!mkdir($temporaryConfig, 0700, true)) {
    fwrite(STDERR, "Unable to create installer authorization fixture.\n");
    exit(1);
}
$tokenFile = $temporaryConfig
    . DIRECTORY_SEPARATOR
    . 'install-token.php';
$fileToken = str_repeat('f', 64);
$tokenFileContents = "<?php\nreturn '" . $fileToken . "';\n";
if (file_put_contents($tokenFile, $tokenFileContents) === false) {
    fwrite(STDERR, "Unable to write installer authorization fixture.\n");
    exit(1);
}

$environmentToken = str_repeat('e', 64);
$credential = resolveInstallerAuthorizationToken(
    $temporaryRoot,
    $environmentToken
);
assertInstallerAuthorizationRule(
    $credential['source'] === 'environment'
        && $credential['token'] === $environmentToken
        && $credential['path'] === null,
    'A configured environment token must take precedence'
);

$credential = resolveInstallerAuthorizationToken(
    $temporaryRoot,
    'short-environment-token'
);
assertInstallerAuthorizationRule(
    $credential['source'] === null
        && $credential['token'] === ''
        && $credential['error'] !== '',
    'A short environment token must fail closed without falling back to the file'
);

$credential = resolveInstallerAuthorizationToken($temporaryRoot, false);
assertInstallerAuthorizationRule(
    $credential['source'] === 'file'
        && $credential['token'] === $fileToken
        && $credential['path'] === $tokenFile,
    'A cPanel-compatible token file must authorize without process environment access'
);

file_put_contents(
    $tokenFile,
    "<?php\nreturn 'short-file-token';\n"
);
$credential = resolveInstallerAuthorizationToken($temporaryRoot, false);
assertInstallerAuthorizationRule(
    $credential['source'] === null
        && $credential['token'] === ''
        && $credential['error'] !== '',
    'A short one-time file token must fail closed'
);

unlink($tokenFile);
$credential = resolveInstallerAuthorizationToken($temporaryRoot, false);
assertInstallerAuthorizationRule(
    $credential['source'] === null
        && $credential['token'] === ''
        && $credential['error'] === '',
    'No configured token source must remain distinguishable from an invalid source'
);

file_put_contents(
    $tokenFile,
    "<?php\nreturn ['not-a-token'];\n"
);
$credential = resolveInstallerAuthorizationToken($temporaryRoot, false);
assertInstallerAuthorizationRule(
    $credential['source'] === null
        && $credential['token'] === ''
        && $credential['error'] !== '',
    'An invalid token file must fail closed'
);

unlink($tokenFile);
rmdir($temporaryConfig);
rmdir($temporaryRoot);

echo "Installer authorization rule tests passed: {$assertions} assertions.\n";
