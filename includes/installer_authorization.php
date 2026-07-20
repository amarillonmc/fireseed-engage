<?php
// 种火集结号 - 安装器授权辅助函数 / Fireseed Engage - installer authorization helpers

/**
 * 判断请求是否来自无代理的直接回环连接 / Determine whether a request is a direct, unproxied loopback connection
 * @param array $server 服务器请求变量 / Server request variables
 * @return bool
 */
function isDirectInstallerLoopbackRequest($server) {
    $remoteAddress = isset($server['REMOTE_ADDR'])
        && is_scalar($server['REMOTE_ADDR'])
        ? (string) $server['REMOTE_ADDR']
        : '';
    $requestHost = isset($server['HTTP_HOST'])
        && is_scalar($server['HTTP_HOST'])
        ? strtolower(trim((string) $server['HTTP_HOST']))
        : '';
    $isLoopbackHost = preg_match(
        '/^(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]+)?$/D',
        $requestHost
    ) === 1;

    $forwardedHeaders = [
        'HTTP_FORWARDED',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_FORWARDED_HOST',
        'HTTP_X_FORWARDED_PROTO',
        'HTTP_X_FORWARDED_PORT',
        'HTTP_X_REAL_IP'
    ];
    foreach ($forwardedHeaders as $header) {
        if (isset($server[$header])) {
            return false;
        }
    }

    return in_array($remoteAddress, ['127.0.0.1', '::1'], true)
        && $isLoopbackHost;
}

/**
 * 判断安装请求是否由明确的 TLS 信号保护 / Determine whether explicit TLS evidence protects an installer request
 * @param array $server 服务器请求变量 / Server request variables
 * @param bool $trustProxyHeaders 是否信任代理协议头 / Whether to trust the proxy protocol header
 * @return bool
 */
function isSecureInstallerRequest($server, $trustProxyHeaders = false) {
    $httpsValue = isset($server['HTTPS'])
        && is_scalar($server['HTTPS'])
        ? strtolower(trim((string) $server['HTTPS']))
        : '';
    if ($httpsValue !== '' && $httpsValue !== 'off') {
        return true;
    }

    if (!$trustProxyHeaders
        || !isset($server['HTTP_X_FORWARDED_PROTO'])
        || !is_scalar($server['HTTP_X_FORWARDED_PROTO'])
    ) {
        return false;
    }

    $forwardedProtocols = explode(
        ',',
        (string) $server['HTTP_X_FORWARDED_PROTO']
    );
    return strtolower(trim($forwardedProtocols[0])) === 'https';
}

/**
 * 解析环境变量或一次性文件提供的安装令牌 / Resolve an installer token from the environment or one-time file
 * @param string $projectRoot 项目根目录 / Project root
 * @param mixed $environmentToken 环境变量值 / Environment value
 * @return array 令牌来源、值、文件路径与错误 / Token source, value, file path, and error
 */
function resolveInstallerAuthorizationToken(
    $projectRoot,
    $environmentToken
) {
    if (is_string($environmentToken)
        && trim($environmentToken) !== '') {
        if (strlen($environmentToken) < 32) {
            return [
                'source' => null,
                'token' => '',
                'path' => null,
                'error' => '环境安装令牌必须至少为 32 字节'
                    . ' / The environment installer token must be at least 32 bytes.'
            ];
        }
        return [
            'source' => 'environment',
            'token' => $environmentToken,
            'path' => null,
            'error' => ''
        ];
    }

    $tokenFilePath = rtrim(
        (string) $projectRoot,
        '/\\'
    ) . DIRECTORY_SEPARATOR . 'config'
        . DIRECTORY_SEPARATOR . 'install-token.php';
    if (!file_exists($tokenFilePath)) {
        return [
            'source' => null,
            'token' => '',
            'path' => null,
            'error' => ''
        ];
    }
    if (!is_file($tokenFilePath) || !is_readable($tokenFilePath)) {
        return [
            'source' => null,
            'token' => '',
            'path' => $tokenFilePath,
            'error' => '一次性安装令牌文件不可读'
                . ' / The one-time installer token file is unreadable.'
        ];
    }

    $bufferLevel = ob_get_level();
    ob_start();
    try {
        $fileToken = (static function ($path) {
            return require $path;
        })($tokenFilePath);
        $unexpectedOutput = ob_get_clean();
    } catch (Throwable $exception) {
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
        error_log(
            'Unable to load installer token file: '
            . $exception->getMessage()
        );
        return [
            'source' => null,
            'token' => '',
            'path' => $tokenFilePath,
            'error' => '一次性安装令牌文件无效'
                . ' / The one-time installer token file is invalid.'
        ];
    }

    if (!is_string($fileToken)
        || trim($fileToken) === ''
        || $unexpectedOutput !== '') {
        return [
            'source' => null,
            'token' => '',
            'path' => $tokenFilePath,
            'error' => '一次性安装令牌文件必须只返回非空字符串'
                . ' / The one-time installer token file must only return a non-empty string.'
        ];
    }
    if (strlen($fileToken) < 32) {
        return [
            'source' => null,
            'token' => '',
            'path' => $tokenFilePath,
            'error' => '一次性安装令牌必须至少为 32 字节'
                . ' / The one-time installer token must be at least 32 bytes.'
        ];
    }

    return [
        'source' => 'file',
        'token' => $fileToken,
        'path' => $tokenFilePath,
        'error' => ''
    ];
}
