<?php
// 种火集结号 - 游戏安装脚本 / Fireseed Engage - Game installer

require_once __DIR__ . '/config/version.php';

// 安装器使用独立会话并启用安全 Cookie 选项 / Use an isolated installer session with secure cookie settings
$isHttpsRequest = (
    isset($_SERVER['HTTPS'])
    && $_SERVER['HTTPS'] !== ''
    && strtolower((string) $_SERVER['HTTPS']) !== 'off'
) || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
$installerTrustProxyHeaders = filter_var(
    getenv('FIRESEED_TRUST_PROXY_HEADERS'),
    FILTER_VALIDATE_BOOLEAN
);
if (!$isHttpsRequest
    && $installerTrustProxyHeaders
    && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
) {
    $forwardedProtocols = explode(
        ',',
        (string) $_SERVER['HTTP_X_FORWARDED_PROTO']
    );
    $isHttpsRequest = strtolower(trim($forwardedProtocols[0])) === 'https';
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('fireseed_installer');
session_set_cookie_params([
    'lifetime' => 3600,
    'path' => '/',
    'secure' => $isHttpsRequest,
    'httponly' => true,
    'samesite' => 'Strict'
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// 检查是否已经安装 / Stop when an installation lock already exists
if (file_exists(__DIR__ . '/config/installed.lock')) {
    http_response_code(409);
    die(
        '游戏已经安装完成。不要仅删除锁文件就地重装；'
        . '请按部署文档备份并使用经过确认的空数据库重新安装。'
    );
}

// 所有浏览器安装都必须提供一次性环境令牌，避免反向代理把公网请求伪装成本机来源。 / Every browser installation requires a one-time environment token because reverse proxies can make public requests appear loopback-local.
$requiredInstallToken = getenv('FIRESEED_INSTALL_TOKEN');
if (empty($_SESSION['installer_authorized'])) {
    $installerTokenError = '';
    $requestMethod = isset($_SERVER['REQUEST_METHOD'])
        ? strtoupper((string) $_SERVER['REQUEST_METHOD'])
        : 'GET';
    $remoteAddress = isset($_SERVER['REMOTE_ADDR'])
        ? (string) $_SERVER['REMOTE_ADDR']
        : '';
    $requestHost = isset($_SERVER['HTTP_HOST'])
        ? strtolower((string) $_SERVER['HTTP_HOST'])
        : '';
    $isLoopbackHost = preg_match(
        '/^(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]+)?$/D',
        $requestHost
    ) === 1;
    $hasForwardedClientHeaders = isset(
        $_SERVER['HTTP_FORWARDED']
    ) || isset(
        $_SERVER['HTTP_X_FORWARDED_FOR']
    ) || isset(
        $_SERVER['HTTP_X_FORWARDED_HOST']
    ) || isset(
        $_SERVER['HTTP_X_REAL_IP']
    );
    $isDirectLoopbackTransport =
        in_array($remoteAddress, ['127.0.0.1', '::1'], true)
        && $isLoopbackHost
        && !$hasForwardedClientHeaders;
    $allowInsecureLocalInstall = $isDirectLoopbackTransport
        && filter_var(
            getenv('FIRESEED_ALLOW_INSECURE_LOCAL_INSTALL'),
            FILTER_VALIDATE_BOOLEAN
        );
    $hasSafeTokenTransport = $isHttpsRequest
        || $allowInsecureLocalInstall;
    if (!$hasSafeTokenTransport) {
        $installerTokenError =
            '安装授权必须使用 HTTPS；仅本机开发可显式开启不安全回环安装';
    }

    if ($requestMethod === 'POST' && isset($_POST['install_token'])) {
        $suppliedInstallToken = is_scalar($_POST['install_token'])
            ? (string) $_POST['install_token']
            : '';
        if (!$hasSafeTokenTransport) {
            $installerTokenError =
                '安装授权必须使用 HTTPS；令牌未被处理';
        } elseif (!is_string($requiredInstallToken)
            || $requiredInstallToken === ''
            || $suppliedInstallToken === ''
            || !hash_equals($requiredInstallToken, $suppliedInstallToken)
        ) {
            $installerTokenError = '安装令牌无效';
        } else {
            // x模式原子消费令牌；标记不保存令牌本身。 / Exclusive-create mode consumes the token atomically without storing the secret.
            $tokenClaimPath =
                __DIR__ . '/config/.install-token-consumed';
            $tokenClaimHandle = @fopen($tokenClaimPath, 'x');
            if ($tokenClaimHandle === false) {
                $installerTokenError =
                    '安装令牌已被使用；如需恢复，请按部署文档重置令牌';
            } else {
                $claimPayload = 'claimed_at=' . gmdate(DATE_ATOM) . "\n";
                $claimBytes = @fwrite(
                    $tokenClaimHandle,
                    $claimPayload
                );
                $claimFlushed = $claimBytes === strlen($claimPayload)
                    && @fflush($tokenClaimHandle);
                @fclose($tokenClaimHandle);
                if (!$claimFlushed) {
                    @unlink($tokenClaimPath);
                    $installerTokenError = '无法安全消费安装令牌';
                } elseif (!session_regenerate_id(true)) {
                    @unlink($tokenClaimPath);
                    $installerTokenError = '无法建立安全安装会话';
                } else {
                    if (DIRECTORY_SEPARATOR !== '\\') {
                        @chmod($tokenClaimPath, 0600);
                    }
                    $_SESSION['installer_authorized'] = true;
                    $_SESSION['installer_authorized_at'] = time();
                    header('Location: install.php', true, 303);
                    exit;
                }
            }
        }
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header(
        "Content-Security-Policy: default-src 'none'; "
        . "style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'"
    );
    http_response_code(
        !$hasSafeTokenTransport
            ? 426
            : ($installerTokenError === '' ? 401 : 403)
    );
    $escapedTokenError = htmlspecialchars(
        $installerTokenError,
        ENT_QUOTES,
        'UTF-8'
    );
    echo '<!DOCTYPE html><html lang="zh-CN"><head>'
        . '<meta charset="UTF-8"><meta name="viewport" '
        . 'content="width=device-width, initial-scale=1.0">'
        . '<title>安装授权 / Installer authorization</title>'
        . '<style>body{font-family:sans-serif;max-width:36rem;margin:4rem auto;'
        . 'padding:0 1rem}label,input,button{display:block;width:100%;'
        . 'box-sizing:border-box;margin:.75rem 0;padding:.7rem}'
        . '.error{color:#a00}</style></head><body>'
        . '<h1>安装授权 / Installer authorization</h1>'
        . '<p>请输入 FIRESEED_INSTALL_TOKEN。令牌只通过 POST 提交，'
        . '成功后立即失效。</p>'
        . ($escapedTokenError !== ''
            ? '<p class="error">' . $escapedTokenError . '</p>'
            : '')
        . ($hasSafeTokenTransport
            ? '<form method="post"><label for="install-token">'
                . '一次性令牌 / One-time token</label>'
                . '<input id="install-token" type="password" '
                . 'name="install_token" autocomplete="one-time-code" '
                . 'required><button type="submit">'
                . '授权 / Authorize</button></form>'
            : '')
        . '</body></html>';
    exit;
}

if (empty($_SESSION['installer_csrf_token'])) {
    $_SESSION['installer_csrf_token'] = bin2hex(random_bytes(32));
}
$installerCsrfToken = (string) $_SESSION['installer_csrf_token'];

$requestedStep = isset($_POST['step'])
    ? (int) $_POST['step']
    : (isset($_GET['step']) ? (int) $_GET['step'] : 1);
$step = max(1, min(5, $requestedStep));
$error = '';
$success = '';

// 处理安装步骤 / Process installation steps
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrfToken = isset($_POST['csrf_token'])
        ? (string) $_POST['csrf_token']
        : '';
    if ($submittedCsrfToken === ''
        || !hash_equals($installerCsrfToken, $submittedCsrfToken)
    ) {
        http_response_code(403);
        $error = '安装会话已失效，请刷新页面后重试';
    } else {
        switch ($step) {
        case 1:
            // 环境检查 / Environment check
            $step = 2;
            break;
            
        case 2:
            // 数据库配置
            $dbHost = isset($_POST['db_host'])
                && is_scalar($_POST['db_host'])
                ? trim((string) $_POST['db_host'])
                : '';
            $dbUser = isset($_POST['db_user'])
                && is_scalar($_POST['db_user'])
                ? trim((string) $_POST['db_user'])
                : '';
            $dbPass = isset($_POST['db_pass'])
                && is_scalar($_POST['db_pass'])
                ? (string) $_POST['db_pass']
                : '';
            $dbName = isset($_POST['db_name'])
                && is_scalar($_POST['db_name'])
                ? trim((string) $_POST['db_name'])
                : '';
            $siteUrl = isset($_POST['site_url'])
                && is_scalar($_POST['site_url'])
                ? rtrim(trim((string) $_POST['site_url']), '/')
                : '';
            $adminEmail = isset($_POST['admin_email'])
                && is_scalar($_POST['admin_email'])
                ? trim((string) $_POST['admin_email'])
                : '';
            
            $siteUrlParts = $siteUrl !== '' ? parse_url($siteUrl) : false;
            if (empty($dbHost)
                || empty($dbUser)
                || empty($dbName)
                || empty($siteUrl)
                || empty($adminEmail)
            ) {
                $error = '请填写所有必填字段';
            } elseif (strlen($siteUrl) > 2048
                || !filter_var($siteUrl, FILTER_VALIDATE_URL)
                || !is_array($siteUrlParts)
                || !isset($siteUrlParts['scheme'], $siteUrlParts['host'])
                || !in_array(
                    strtolower((string) $siteUrlParts['scheme']),
                    ['http', 'https'],
                    true
                )
                || isset($siteUrlParts['user'])
                || isset($siteUrlParts['pass'])
            ) {
                $error = '站点 URL 必须是有效的 HTTP 或 HTTPS 地址';
            } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)
                || strlen($adminEmail) > 254
            ) {
                $error = '请输入有效的管理员邮箱';
            } else {
                // 测试数据库连接
                try {
                    $testConn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
                    if ($testConn->connect_error) {
                        error_log(
                            'Installer database connection failed: '
                            . $testConn->connect_error
                        );
                        $error = '数据库连接失败，请核对连接信息和服务器日志';
                    } else {
                        // 保存配置到会话 / Save configuration in the installer session
                        $_SESSION['install_config'] = [
                            'db_host' => $dbHost,
                            'db_user' => $dbUser,
                            'db_pass' => $dbPass,
                            'db_name' => $dbName,
                            'site_url' => $siteUrl,
                            'admin_email' => $adminEmail
                        ];
                        $testConn->close();
                        $step = 3;
                    }
                } catch (Throwable $e) {
                    error_log(
                        'Installer database connection failed: '
                        . $e->getMessage()
                    );
                    $error = '数据库连接失败，请核对连接信息和服务器日志';
                }
            }
            break;
            
        case 3:
            // 管理员账户创建 / Administrator account
            $adminUsername = isset($_POST['admin_username'])
                && is_scalar($_POST['admin_username'])
                ? trim((string) $_POST['admin_username'])
                : '';
            $adminPassword = isset($_POST['admin_password'])
                && is_scalar($_POST['admin_password'])
                ? (string) $_POST['admin_password']
                : '';
            $adminPasswordConfirm = isset($_POST['admin_password_confirm'])
                && is_scalar($_POST['admin_password_confirm'])
                ? (string) $_POST['admin_password_confirm']
                : '';
            
            if (empty($adminUsername) || empty($adminPassword)) {
                $error = '请填写管理员用户名和密码';
            } elseif ($adminPassword !== $adminPasswordConfirm) {
                $error = '两次输入的密码不一致';
            } elseif (mb_strlen($adminUsername, 'UTF-8') < 3
                || mb_strlen($adminUsername, 'UTF-8') > 20
                || !preg_match(
                    '/^[\p{L}\p{N}_-]+$/u',
                    $adminUsername
                )
            ) {
                $error = '管理员用户名须为3至20位文字、数字、下划线或短横线';
            } elseif (strlen($adminPassword) < 10) {
                $error = '密码长度至少10位';
            } elseif (strlen($adminPassword) > 256) {
                $error = '密码长度不能超过256个字节';
            } else {
                $_SESSION['install_config']['admin_username'] = $adminUsername;
                $_SESSION['install_config']['admin_password'] = $adminPassword;
                $step = 4;
            }
            break;
            
        case 4:
            // 执行安装 / Run installation
            if (!isset($_SESSION['install_config'])) {
                $error = '安装配置丢失，请重新开始';
                $step = 1;
            } else {
                set_time_limit(0);
                $result = performInstallation($_SESSION['install_config']);
                if ($result === true) {
                    $success = '安装完成！';
                    $step = 5;
                    $_SESSION['install_config']['db_pass'] = '';
                    $_SESSION['install_config']['admin_password'] = '';
                    unset($_SESSION['installer_csrf_token']);
                } else {
                    $error = $result;
                }
            }
            break;
        }
    }
}

/**
 * 执行安装 / Perform installation
 * @param array $config 安装配置 / Installation configuration
 * @return true|string 成功或错误信息 / Success or error message
 */
function performInstallation($config) {
    $db = null;
    $installerTransactionOpen = false;
    $installationComplete = false;
    $localConfigPath = __DIR__ . '/config/local.php';
    $localConfigWritten = false;
    $hadPreviousLocalConfig = false;
    $previousLocalConfig = null;
    $previousLocalConfigMode = 0600;
    $installationLockHandle = null;
    $installationLockAcquired = false;

    try {
        // 整个安装过程使用文件锁串行化，避免并发请求互相恢复或删除配置。 / Serialize the entire installation so concurrent requests cannot restore or delete each other's configuration.
        $installationLockHandle = @fopen(
            __DIR__ . '/config/.installing.lock',
            'c'
        );
        if ($installationLockHandle === false) {
            return '无法创建安装互斥锁';
        }
        $installationLockAcquired = @flock(
            $installationLockHandle,
            LOCK_EX | LOCK_NB
        );
        if (!$installationLockAcquired) {
            return '另一个安装过程正在进行，请稍后重试';
        }
        if (DIRECTORY_SEPARATOR !== '\\') {
            @chmod(__DIR__ . '/config/.installing.lock', 0600);
        }

        $hadPreviousLocalConfig = is_file($localConfigPath);
        if ($hadPreviousLocalConfig) {
            $previousLocalConfig = file_get_contents($localConfigPath);
            if ($previousLocalConfig === false) {
                return '无法读取现有本地配置，安装已中止';
            }
            $previousMode = fileperms($localConfigPath);
            if ($previousMode !== false) {
                $previousLocalConfigMode = $previousMode & 0777;
            }
        }

        // 环境变量优先于本地配置；必须与表单数据库完全一致，避免拆分安装。 / Environment variables override local configuration and must match the form exactly to prevent a split-database installation.
        $databaseEnvironmentValues = [
            'FIRESEED_DB_HOST' => (string) $config['db_host'],
            'FIRESEED_DB_USER' => (string) $config['db_user'],
            'FIRESEED_DB_PASS' => (string) $config['db_pass'],
            'FIRESEED_DB_NAME' => (string) $config['db_name']
        ];
        foreach ($databaseEnvironmentValues as $environmentKey => $formValue) {
            $environmentValue = getenv($environmentKey);
            if ($environmentValue !== false
                && (string) $environmentValue !== $formValue) {
                error_log(
                    'Installer database configuration conflict: '
                    . $environmentKey
                );
                return '数据库环境变量与安装表单不一致，安装已中止';
            }
        }

        // 1. 创建仅包含部署机密的本地配置文件 / Create a local configuration file containing deployment secrets only
        $configContent = "<?php\n";
        $configContent .= "// 种火集结号 - 本地部署配置 / Fireseed Engage - Local deployment configuration\n";
        $configContent .= "// 由安装程序自动生成 / Generated by the installer\n\n";
        $configContent .= "return [\n";
        $configContent .= "    'DB_HOST' => " . var_export($config['db_host'], true) . ",\n";
        $configContent .= "    'DB_USER' => " . var_export($config['db_user'], true) . ",\n";
        $configContent .= "    'DB_PASS' => " . var_export($config['db_pass'], true) . ",\n";
        $configContent .= "    'DB_NAME' => " . var_export($config['db_name'], true) . ",\n";
        $configContent .= "    'SITE_URL' => " . var_export($config['site_url'], true) . ",\n";
        $configContent .= "    'ADMIN_EMAIL' => " . var_export($config['admin_email'], true) . ",\n";
        $configContent .= "];\n";

        if (!writeInstallerFileAtomically(
            $localConfigPath,
            $configContent,
            0600
        )) {
            return '无法创建配置文件';
        }
        $localConfigWritten = true;
        
        // 2. 连接数据库
        $db = new mysqli($config['db_host'], $config['db_user'], $config['db_pass'], $config['db_name']);
        if ($db->connect_error) {
            error_log(
                'Installation database connection failed: '
                . $db->connect_error
            );
            return '数据库连接失败，请检查服务器日志';
        }
        if (!$db->set_charset('utf8mb4')) {
            return '无法设置数据库字符集';
        }
        $timezoneStmt = $db->prepare("SET SESSION time_zone = '+08:00'");
        if (!$timezoneStmt || !$timezoneStmt->execute()) {
            if ($timezoneStmt) {
                $timezoneStmt->close();
            }
            return '无法设置数据库时区';
        }
        $timezoneStmt->close();
        
        // 3. 创建数据库表
        $sqlFiles = [
            'sql/users.sql',
            'sql/resources.sql',
            'sql/game_config.sql',
            'sql/admin_logs.sql',
            'sql/map_tiles.sql',
            'sql/cities.sql',
            'sql/facilities.sql',
            'sql/soldiers.sql',
            'sql/technologies.sql',
            'sql/user_technologies.sql',
            'sql/generals.sql',
            'sql/general_skills.sql',
            'sql/general_assignments.sql',
            'sql/armies.sql',
            'sql/army_units.sql',
            'sql/battles.sql',
            'sql/gameplay_expansion.sql',
            'sql/upgrade_20260718_skill_mechanisms.sql'
        ];
        
        foreach ($sqlFiles as $sqlFile) {
            $sqlFilePath = __DIR__ . '/' . $sqlFile;
            if (!is_file($sqlFilePath) || !is_readable($sqlFilePath)) {
                return "安装所需SQL文件缺失或不可读 ($sqlFile)";
            }

            $sql = file_get_contents($sqlFilePath);
            if ($sql === false || trim($sql) === '') {
                return "安装所需SQL文件为空或不可读 ($sqlFile)";
            }

            // 按引号与注释语义分割SQL，保留字符串内的分号 / Split SQL with quote/comment awareness and preserve semicolons inside strings
            $statements = splitInstallerSqlStatements($sql);
            if (count($statements) === 0) {
                return "安装所需SQL文件不含可执行语句 ($sqlFile)";
            }
            foreach ($statements as $statement) {
                $statement = trim($statement);
                if (!empty($statement)) {
                    $statement = makeInstallerSqlStatementRerunnable(
                        $statement,
                        $sqlFile
                    );
                    $transactionCommand =
                        getInstallerSqlTransactionCommand($statement);
                    if ($transactionCommand !== null) {
                        if ($transactionCommand === 'START') {
                            if ($installerTransactionOpen
                                || !$db->begin_transaction()) {
                                if ($installerTransactionOpen) {
                                    $db->rollback();
                                    $installerTransactionOpen = false;
                                }
                                return getInstallerDatabaseFailureMessage(
                                    '无法开始SQL事务',
                                    $sqlFile,
                                    $db->error
                                );
                            }
                            $installerTransactionOpen = true;
                        } elseif ($transactionCommand === 'COMMIT') {
                            if (!$installerTransactionOpen
                                || !$db->commit()) {
                                if ($installerTransactionOpen) {
                                    $db->rollback();
                                    $installerTransactionOpen = false;
                                }
                                return getInstallerDatabaseFailureMessage(
                                    '无法提交SQL事务',
                                    $sqlFile,
                                    $db->error
                                );
                            }
                            $installerTransactionOpen = false;
                        } else {
                            if ($installerTransactionOpen
                                && !$db->rollback()) {
                                return getInstallerDatabaseFailureMessage(
                                    '无法回滚SQL事务',
                                    $sqlFile,
                                    $db->error
                                );
                            }
                            $installerTransactionOpen = false;
                        }
                        continue;
                    }

                    $sqlStmt = $db->prepare($statement);
                    if (!$sqlStmt || !$sqlStmt->execute()) {
                        if ($sqlStmt) {
                            $sqlStmt->close();
                        }
                        if ($installerTransactionOpen) {
                            $db->rollback();
                            $installerTransactionOpen = false;
                        }
                        return getInstallerDatabaseFailureMessage(
                            '执行SQL失败',
                            $sqlFile,
                            $db->error
                        );
                    }
                    $sqlStmt->close();
                }
            }
        }
        if ($installerTransactionOpen) {
            $db->rollback();
            $installerTransactionOpen = false;
            return '安装SQL包含未结束的事务';
        }
        
        // 4. 加载本地配置与安装所需类 / Load local configuration and installation classes
        require_once __DIR__ . '/config/config.php';
        $effectiveDatabaseConfig = [
            'DB_HOST' => (string) DB_HOST,
            'DB_USER' => (string) DB_USER,
            'DB_PASS' => (string) DB_PASS,
            'DB_NAME' => (string) DB_NAME
        ];
        $expectedDatabaseConfig = [
            'DB_HOST' => (string) $config['db_host'],
            'DB_USER' => (string) $config['db_user'],
            'DB_PASS' => (string) $config['db_pass'],
            'DB_NAME' => (string) $config['db_name']
        ];
        if ($effectiveDatabaseConfig !== $expectedDatabaseConfig) {
            error_log(
                'Installer effective database configuration does not match '
                . 'the validated form configuration.'
            );
            return '生效的数据库配置与安装表单不一致，安装已中止';
        }
        require_once __DIR__ . '/includes/database.php';
        require_once __DIR__ . '/includes/classes/GameConfig.php';
        require_once __DIR__ . '/includes/classes/User.php';
        require_once __DIR__
            . '/includes/classes/TechnologyEffectService.php';
        require_once __DIR__ . '/includes/classes/Technology.php';
        require_once __DIR__ . '/includes/classes/Map.php';
        require_once __DIR__ . '/includes/classes/MapGenerator.php';
        
        // 5. 创建或恢复同一管理员账户 / Create or recover the same administrator account.
        $adminResult = createOrRecoverInstallationAdmin($db, $config);
        if (!$adminResult['success']) {
            return $adminResult['message'];
        }
        $adminUserId = $adminResult['user_id'];
        
        // 6. 初始化默认科技 / Seed the default technologies.
        if (!Technology::initializeDefaultTechnologies()) {
            return '初始化默认科技失败，请检查服务器日志';
        }
        
        // 7. 生成初始地图 / Generate the initial world map.
        $mapGenerator = new MapGenerator();
        $mapResult = $mapGenerator->generateMap(true);
        if ($mapResult !== true) {
            error_log('Initial map generation failed: ' . $mapResult);
            return '生成地图失败，请检查服务器日志';
        }
        
        // 8. 创建安装锁定文件 / Create the installation lock file.
        $lockContent = "安装完成时间: " . date('Y-m-d H:i:s') . "\n";
        $lockContent .= "管理员用户: " . $config['admin_username'] . "\n";
        $lockContent .= "安装版本: " . GAME_VERSION . "\n";
        
        if (!writeInstallerFileAtomically(
            __DIR__ . '/config/installed.lock',
            $lockContent,
            0600
        )) {
            return '无法创建安装锁定文件';
        }

        $installationComplete = true;
        $db->close();
        $db = null;
        return true;
        
    } catch (Throwable $e) {
        if ($installerTransactionOpen && $db instanceof mysqli) {
            $db->rollback();
            $installerTransactionOpen = false;
        }
        error_log('Installation failed: ' . $e->getMessage());
        return '安装过程中发生错误，请检查服务器日志';
    } finally {
        if ($db instanceof mysqli) {
            if ($installerTransactionOpen) {
                $db->rollback();
                $installerTransactionOpen = false;
            }
            $db->close();
        }

        // 任一步骤失败都恢复原配置，避免重跑留下截断或错误机密。 / Restore the original configuration after any failure so reruns never inherit truncated or incorrect secrets.
        if (!$installationComplete && $localConfigWritten) {
            $restored = $hadPreviousLocalConfig
                ? writeInstallerFileAtomically(
                    $localConfigPath,
                    $previousLocalConfig,
                    $previousLocalConfigMode
                )
                : (!is_file($localConfigPath)
                    || @unlink($localConfigPath));
            if (!$restored) {
                error_log(
                    'Installer failed to restore the previous local '
                    . 'configuration.'
                );
            }
        }

        if (is_resource($installationLockHandle)) {
            if ($installationLockAcquired) {
                @flock($installationLockHandle, LOCK_UN);
            }
            @fclose($installationLockHandle);
        }
    }
}

/**
 * 以同目录临时文件原子写入安装产物 / Atomically write an installer artifact through a same-directory temporary file
 *
 * @param string $path 目标路径 / Target path
 * @param string $contents 文件内容 / File contents
 * @param int $mode POSIX权限 / POSIX mode
 * @return bool 是否完整写入 / Whether the complete artifact was written
 */
function writeInstallerFileAtomically($path, $contents, $mode = 0600) {
    $directory = dirname($path);
    if (!is_dir($directory) || !is_writable($directory)) {
        return false;
    }

    $temporaryPath = tempnam($directory, '.fireseed-install-');
    if ($temporaryPath === false) {
        return false;
    }

    $expectedBytes = strlen($contents);
    $writtenBytes = file_put_contents(
        $temporaryPath,
        $contents,
        LOCK_EX
    );
    if ($writtenBytes !== $expectedBytes) {
        @unlink($temporaryPath);
        return false;
    }

    // Windows 依赖目录 ACL；POSIX 部署必须在发布前收紧权限。 / Windows relies on directory ACLs; POSIX deployments must tighten permissions before publication.
    if (DIRECTORY_SEPARATOR !== '\\' && !@chmod($temporaryPath, $mode)) {
        @unlink($temporaryPath);
        return false;
    }

    if (@rename($temporaryPath, $path)) {
        return true;
    }

    // 部分 Windows 文件系统不能直接覆盖目标；使用可恢复的两步替换。 / Some Windows filesystems cannot replace a destination directly, so use a recoverable two-step replacement.
    if (!is_file($path)) {
        @unlink($temporaryPath);
        return false;
    }
    $backupPath = dirname($path)
        . '/.'
        . basename($path, '.php')
        . '-installer-backup-'
        . bin2hex(random_bytes(8))
        . '.php';
    if (!@rename($path, $backupPath)) {
        @unlink($temporaryPath);
        return false;
    }
    if (!@rename($temporaryPath, $path)) {
        @rename($backupPath, $path);
        @unlink($temporaryPath);
        return false;
    }
    if (!@unlink($backupPath)) {
        error_log(
            'Installer left a local backup artifact at ' . $backupPath
        );
    }
    return true;
}

/**
 * 记录安装数据库错误并返回安全消息 / Log an installer database error and return a safe message
 *
 * @param string $operation 操作名称 / Operation name
 * @param string $sqlFile SQL文件 / SQL file
 * @param string $databaseError 数据库错误 / Database error
 * @return string 安全错误消息 / Safe error message
 */
function getInstallerDatabaseFailureMessage(
    $operation,
    $sqlFile,
    $databaseError
) {
    error_log(
        'Installer database operation failed ['
        . $operation
        . '] ['
        . $sqlFile
        . ']: '
        . $databaseError
    );
    return $operation . " ($sqlFile)，请检查服务器日志";
}

/**
 * 按SQL引号和注释规则分割语句 / Splits statements using SQL quote and comment rules
 *
 * @param string $sql SQL文本 / SQL text
 * @return array SQL语句 / SQL statements
 */
function splitInstallerSqlStatements($sql) {
    $statements = [];
    $buffer = '';
    $length = strlen($sql);
    $quote = null;
    $inLineComment = false;
    $inBlockComment = false;
    $hasExecutableContent = false;

    for ($index = 0; $index < $length; $index++) {
        $character = $sql[$index];
        $next = $index + 1 < $length ? $sql[$index + 1] : '';
        $afterNext = $index + 2 < $length ? $sql[$index + 2] : '';

        if ($inLineComment) {
            $buffer .= $character;
            if ($character === "\n" || $character === "\r") {
                $inLineComment = false;
            }
            continue;
        }

        if ($inBlockComment) {
            $buffer .= $character;
            if ($character === '*' && $next === '/') {
                $buffer .= $next;
                $index++;
                $inBlockComment = false;
            }
            continue;
        }

        if ($quote !== null) {
            $buffer .= $character;
            if ($character === '\\' && $next !== '') {
                $buffer .= $next;
                $index++;
                continue;
            }
            if ($character === $quote) {
                if ($next === $quote) {
                    $buffer .= $next;
                    $index++;
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        if ($character === "'"
            || $character === '"'
            || $character === '`') {
            $quote = $character;
            $hasExecutableContent = true;
            $buffer .= $character;
            continue;
        }
        if ($character === '#') {
            $inLineComment = true;
            $buffer .= $character;
            continue;
        }
        if ($character === '-'
            && $next === '-'
            && ($afterNext === ''
                || preg_match('/\s/', $afterNext) === 1)) {
            $inLineComment = true;
            $buffer .= $character . $next;
            $index++;
            continue;
        }
        if ($character === '/' && $next === '*') {
            $inBlockComment = true;
            $buffer .= $character . $next;
            $index++;
            continue;
        }
        if ($character === ';') {
            if ($hasExecutableContent && trim($buffer) !== '') {
                $statements[] = trim($buffer);
            }
            $buffer = '';
            $hasExecutableContent = false;
            continue;
        }

        $buffer .= $character;
        if (!ctype_space($character)) {
            $hasExecutableContent = true;
        }
    }

    if ($hasExecutableContent && trim($buffer) !== '') {
        $statements[] = trim($buffer);
    }

    return $statements;
}

/**
 * 将基础 SQL 的建表与配置种子改成可重跑形式 / Make base table and configuration seed statements rerunnable
 *
 * @param string $statement SQL 语句 / SQL statement
 * @param string $sqlFile SQL 文件 / SQL file
 * @return string 可重跑的 SQL 语句 / Rerunnable SQL statement
 */
function makeInstallerSqlStatementRerunnable($statement, $sqlFile) {
    $statement = preg_replace(
        '/(^|\R)([ \t]*)CREATE\s+TABLE\s+(?!IF\s+NOT\s+EXISTS\b)/i',
        '$1$2CREATE TABLE IF NOT EXISTS ',
        $statement
    );

    if ($sqlFile === 'sql/game_config.sql') {
        $statement = preg_replace(
            '/(^|\R)([ \t]*)INSERT\s+INTO\s+`game_config`/i',
            '$1$2INSERT IGNORE INTO `game_config`',
            $statement
        );
    }

    return $statement;
}

/**
 * 识别不能通过预处理语句执行的事务控制命令 / Detects transaction controls that cannot use prepared statements
 *
 * @param string $statement SQL语句 / SQL statement
 * @return string|null START、COMMIT、ROLLBACK或空 / START, COMMIT, ROLLBACK, or null
 */
function getInstallerSqlTransactionCommand($statement) {
    $matches = [];
    if (preg_match(
        '/(?:^|\R)[ \t]*(START[ \t]+TRANSACTION|COMMIT|ROLLBACK)[ \t]*$/i',
        $statement,
        $matches
    ) !== 1) {
        return null;
    }

    $command = strtoupper(preg_replace(
        '/[ \t]+/',
        ' ',
        trim($matches[1])
    ));
    return $command === 'START TRANSACTION' ? 'START' : $command;
}

/**
 * 识别必须由服务器SQL层直接处理的控制命令 / Detects SQL controls that require direct server execution
 *
 * 命令文本只来自项目内置SQL文件，不包含用户输入。
 * Command text comes only from bundled SQL files and contains no user input.
 *
 * @param string $statement SQL语句 / SQL statement
 * @return bool 是否为服务器预处理控制命令 / Whether this is a server-side prepare control
 */
function isInstallerSqlServerPrepareCommand($statement) {
    $normalized = trim((string) $statement);
    do {
        $before = $normalized;
        // 只剥离语句前方的项目SQL注释，绝不在正文搜索允许关键字。 /
        // Strip only leading bundled-SQL comments; never search the executable body for an allowed keyword.
        $normalized = preg_replace(
            '/\A(?:'
                . '[ \t\r\n]+'
                . '|--(?=[ \t\r\n]|\z)[^\r\n]*(?:\R|\z)'
                . '|#[^\r\n]*(?:\R|\z)'
                . '|\/\*.*?\*\/'
                . ')+/s',
            '',
            $normalized
        );
        $normalized = trim((string) $normalized);
    } while ($normalized !== $before);

    // 触发器DDL不能可移植地走服务器预处理协议；整个正文须从触发器关键字开始。 /
    // Trigger DDL is not portable through server prepare; the complete body must start with its keyword.
    if (preg_match(
        '/\ACREATE[ \t\r\n]+TRIGGER\b[\s\S]*\z/i',
        $normalized
    ) === 1) {
        return true;
    }

    return preg_match(
        '/\A(?:'
            . 'PREPARE[ \t]+[a-z0-9_]+[ \t]+FROM[ \t]+@[a-z0-9_]+'
            . '|EXECUTE[ \t]+[a-z0-9_]+'
            . '|DEALLOCATE[ \t]+PREPARE[ \t]+[a-z0-9_]+'
            . '|DROP[ \t]+TRIGGER(?:[ \t]+IF[ \t]+EXISTS)?[ \t]+'
                . '(?:`[^`]+`|[a-z0-9_]+)'
                . '(?:\.(?:`[^`]+`|[a-z0-9_]+))?'
            . ')\z/i',
        $normalized
    ) === 1;
}

/**
 * 安全创建或恢复安装管理员 / Safely create or recover the installation administrator
 *
 * 只有用户名和邮箱同时匹配同一账户时才允许恢复，避免接管冲突账户。
 * Recovery is allowed only when username and email match the same account, preventing account takeover.
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @param array $config 安装配置 / Installation configuration
 * @return array 处理结果 / Operation result
 */
function createOrRecoverInstallationAdmin($db, $config) {
    $username = $config['admin_username'];
    $email = $config['admin_email'];
    $passwordHash = password_hash(
        $config['admin_password'],
        PASSWORD_DEFAULT
    );
    if ($passwordHash === false) {
        return [
            'success' => false,
            'message' => '无法安全散列管理员密码'
        ];
    }
    $initialMaxCircuitPoints = max(
        1,
        (int) GameConfig::get('initial_max_circuit_points', 10)
    );
    $initialCircuitPoints = min(
        $initialMaxCircuitPoints,
        max(0, (int) GameConfig::get('initial_circuit_points', 1))
    );
    $initialMaxGeneralCost = max(
        0.0,
        (float) GameConfig::get('initial_max_general_cost', 10.0)
    );
    $initialResources = [
        max(0, (int) GameConfig::get('initial_bright_crystal', 1000)),
        max(0, (int) GameConfig::get('initial_warm_crystal', 1000)),
        max(0, (int) GameConfig::get('initial_cold_crystal', 1000)),
        max(0, (int) GameConfig::get('initial_green_crystal', 1000)),
        max(0, (int) GameConfig::get('initial_day_crystal', 1000)),
        max(0, (int) GameConfig::get('initial_night_crystal', 1000))
    ];

    if (!$db->begin_transaction()) {
        return [
            'success' => false,
            'message' => '无法开始管理员创建事务'
        ];
    }

    try {
        $lookup = $db->prepare(
            'SELECT user_id, username, email
             FROM users
             WHERE username = ? OR email = ?
             FOR UPDATE'
        );
        if (!$lookup) {
            throw new RuntimeException('无法检查管理员账户');
        }
        $lookup->bind_param('ss', $username, $email);
        if (!$lookup->execute()) {
            $lookup->close();
            throw new RuntimeException('无法检查管理员账户');
        }

        $result = $lookup->get_result();
        $accounts = [];
        while ($row = $result->fetch_assoc()) {
            $accounts[] = $row;
        }
        $lookup->close();

        if (count($accounts) > 1
            || (count($accounts) === 1
                && ($accounts[0]['username'] !== $username
                    || $accounts[0]['email'] !== $email))
        ) {
            $db->rollback();
            return [
                'success' => false,
                'message' => '管理员用户名或邮箱已被不同账户占用，安装已中止'
            ];
        }

        if (count($accounts) === 1) {
            $adminUserId = (int) $accounts[0]['user_id'];
            $update = $db->prepare(
                'UPDATE users
                 SET password = ?, admin_level = 9
                 WHERE user_id = ?'
            );
            if (!$update) {
                throw new RuntimeException('无法恢复管理员账户');
            }
            $update->bind_param('si', $passwordHash, $adminUserId);
            if (!$update->execute() || $update->affected_rows > 1) {
                $update->close();
                throw new RuntimeException('无法恢复管理员账户');
            }
            $update->close();
        } else {
            $registrationDate = date('Y-m-d H:i:s');
            $insert = $db->prepare(
                'INSERT INTO users
                 (username, password, email, registration_date,
                  circuit_points, max_circuit_points, max_general_cost,
                  admin_level)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 9)'
            );
            if (!$insert) {
                throw new RuntimeException('无法创建管理员账户');
            }
            $insert->bind_param(
                'ssssiid',
                $username,
                $passwordHash,
                $email,
                $registrationDate,
                $initialCircuitPoints,
                $initialMaxCircuitPoints,
                $initialMaxGeneralCost
            );
            if (!$insert->execute()) {
                $insert->close();
                throw new RuntimeException('无法创建管理员账户');
            }
            $adminUserId = (int) $db->insert_id;
            $insert->close();
        }

        // 只补缺失资源行，绝不重置恢复账户的已有余额。 / Add only a missing resource row; never reset balances on a recovered account.
        $resources = $db->prepare(
            'INSERT INTO resources
             (user_id, bright_crystal, warm_crystal, cold_crystal,
              green_crystal, day_crystal, night_crystal, last_update)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)'
        );
        if (!$resources) {
            throw new RuntimeException('无法初始化管理员资源');
        }
        $resources->bind_param(
            'iiiiiii',
            $adminUserId,
            $initialResources[0],
            $initialResources[1],
            $initialResources[2],
            $initialResources[3],
            $initialResources[4],
            $initialResources[5]
        );
        if (!$resources->execute()) {
            $resources->close();
            throw new RuntimeException('无法初始化管理员资源');
        }
        $resources->close();

        if (!$db->commit()) {
            throw new RuntimeException(
                '无法提交管理员账户事务'
            );
        }
        return [
            'success' => true,
            'user_id' => $adminUserId,
            'message' => ''
        ];
    } catch (Throwable $e) {
        $db->rollback();
        error_log(
            'Installation administrator creation failed: '
            . $e->getMessage()
        );
        return [
            'success' => false,
            'message' => '创建或恢复管理员账户失败，请检查服务器日志'
        ];
    }
}

/**
 * 检查环境要求
 */
function checkEnvironment() {
    $checks = [];
    
    // PHP版本检查
    $checks['php_version'] = [
        'name' => 'PHP版本 (>= 7.4)',
        'status' => version_compare(PHP_VERSION, '7.4.0', '>='),
        'current' => PHP_VERSION
    ];
    
    // MySQL扩展检查
    $checks['mysqli'] = [
        'name' => 'MySQLi扩展',
        'status' => extension_loaded('mysqli'),
        'current' => extension_loaded('mysqli') ? '已安装' : '未安装'
    ];

    // mysqlnd 是 get_result 所需运行依赖 / mysqlnd is required by get_result
    $hasMysqlNativeDriver = extension_loaded('mysqli')
        && method_exists('mysqli_stmt', 'get_result');
    $checks['mysqlnd'] = [
        'name' => 'MySQL Native Driver (mysqlnd)',
        'status' => $hasMysqlNativeDriver,
        'current' => $hasMysqlNativeDriver ? '已安装' : '未安装'
    ];
    
    // JSON扩展检查
    $checks['json'] = [
        'name' => 'JSON扩展',
        'status' => extension_loaded('json'),
        'current' => extension_loaded('json') ? '已安装' : '未安装'
    ];

    // 多字节字符串用于用户名与内容校验 / Multibyte strings are used for username and content validation
    $checks['mbstring'] = [
        'name' => 'Mbstring扩展',
        'status' => extension_loaded('mbstring'),
        'current' => extension_loaded('mbstring') ? '已安装' : '未安装'
    ];
    
    // 会话支持检查
    $checks['session'] = [
        'name' => '会话支持',
        'status' => function_exists('session_start'),
        'current' => function_exists('session_start') ? '支持' : '不支持'
    ];
    
    // 文件写入权限检查
    $checks['config_writable'] = [
        'name' => 'config目录写入权限',
        'status' => is_writable('config'),
        'current' => is_writable('config') ? '可写' : '不可写'
    ];
    
    return $checks;
}

$envChecks = checkEnvironment();

// 从经过筛选的请求信息生成安装默认地址 / Build the default installer URL from filtered request data
$installerHost = isset($_SERVER['HTTP_HOST'])
    ? (string) $_SERVER['HTTP_HOST']
    : 'localhost';
if (!preg_match('/^[a-z0-9.:\[\]-]+$/i', $installerHost)) {
    $installerHost = 'localhost';
}
$installerRequestPath = parse_url(
    isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/install.php',
    PHP_URL_PATH
);
$installerBasePath = str_replace(
    '\\',
    '/',
    dirname($installerRequestPath ?: '/install.php')
);
if ($installerBasePath === '/' || $installerBasePath === '.') {
    $installerBasePath = '';
}
$installerDefaultSiteUrl = ($isHttpsRequest ? 'https' : 'http')
    . '://'
    . $installerHost
    . $installerBasePath;
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>种火集结号 - 游戏安装</title>
    <style>
        body {
            font-family: 'Microsoft YaHei', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
        }
        
        .install-container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .install-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .install-title {
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .install-subtitle {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .install-content {
            padding: 30px;
        }
        
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }
        
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: #ecf0f1;
            color: #7f8c8d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin: 0 10px;
            position: relative;
        }
        
        .step.active {
            background: #3498db;
            color: white;
        }
        
        .step.completed {
            background: #27ae60;
            color: white;
        }
        
        .step:not(:last-child)::after {
            content: '';
            position: absolute;
            right: -25px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 2px;
            background: #ecf0f1;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 16px;
            box-sizing: border-box;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #3498db;
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
        }
        
        .form-hint {
            font-size: 14px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .btn {
            background: #3498db;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 6px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        
        .btn:hover {
            background: #2980b9;
        }
        
        .btn-success {
            background: #27ae60;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #c62828;
        }
        
        .success {
            background: #e8f5e8;
            color: #2e7d32;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #2e7d32;
        }
        
        .check-list {
            list-style: none;
            padding: 0;
        }
        
        .check-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .check-item:last-child {
            border-bottom: none;
        }
        
        .check-status {
            font-weight: bold;
        }
        
        .check-status.pass {
            color: #27ae60;
        }
        
        .check-status.fail {
            color: #e74c3c;
        }
        
        .text-center {
            text-align: center;
        }
        
        .mt-20 {
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="install-container">
        <div class="install-header">
            <div class="install-title">🎮 种火集结号</div>
            <div class="install-subtitle">游戏安装向导</div>
        </div>
        
        <div class="install-content">
            <!-- 步骤指示器 -->
            <div class="step-indicator">
                <div class="step <?php echo $step >= 1 ? ($step > 1 ? 'completed' : 'active') : ''; ?>">1</div>
                <div class="step <?php echo $step >= 2 ? ($step > 2 ? 'completed' : 'active') : ''; ?>">2</div>
                <div class="step <?php echo $step >= 3 ? ($step > 3 ? 'completed' : 'active') : ''; ?>">3</div>
                <div class="step <?php echo $step >= 4 ? ($step > 4 ? 'completed' : 'active') : ''; ?>">4</div>
                <div class="step <?php echo $step >= 5 ? 'active' : ''; ?>">5</div>
            </div>
            
            <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>
            
            <?php if ($step == 1): ?>
            <!-- 步骤1: 环境检查 -->
            <h3>步骤 1: 环境检查</h3>
            <p>正在检查服务器环境是否满足游戏运行要求...</p>
            
            <ul class="check-list">
                <?php foreach ($envChecks as $check): ?>
                <li class="check-item">
                    <span><?php echo $check['name']; ?></span>
                    <span class="check-status <?php echo $check['status'] ? 'pass' : 'fail'; ?>">
                        <?php echo $check['status'] ? '✓ 通过' : '✗ 失败'; ?>
                        (<?php echo $check['current']; ?>)
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            
            <?php
            $allPassed = true;
            foreach ($envChecks as $check) {
                if (!$check['status']) {
                    $allPassed = false;
                    break;
                }
            }
            ?>
            
            <div class="text-center mt-20">
                <?php if ($allPassed): ?>
                <form method="post">
                    <input type="hidden" name="step" value="1">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($installerCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="btn">下一步</button>
                </form>
                <?php else: ?>
                <p style="color: #e74c3c;">请解决上述环境问题后重新检查。</p>
                <button onclick="location.reload()" class="btn">重新检查</button>
                <?php endif; ?>
            </div>
            
            <?php elseif ($step == 2): ?>
            <!-- 步骤2: 数据库配置 -->
            <h3>步骤 2: 数据库配置</h3>
            <p>请填写数据库连接信息和基本站点设置。</p>
            
            <form method="post">
                <input type="hidden" name="step" value="2">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($installerCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label class="form-label">数据库主机 *</label>
                    <input type="text" name="db_host" class="form-input" value="localhost" required>
                    <div class="form-hint">通常为 localhost</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">数据库用户名 *</label>
                    <input type="text" name="db_user" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">数据库密码</label>
                    <input type="password" name="db_pass" class="form-input">
                </div>
                
                <div class="form-group">
                    <label class="form-label">数据库名称 *</label>
                    <input type="text" name="db_name" class="form-input" value="fireseed_engage" required>
                    <div class="form-hint">请确保数据库已创建</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">站点URL *</label>
                    <input type="url" name="site_url" class="form-input"
                           value="<?php echo htmlspecialchars($installerDefaultSiteUrl, ENT_QUOTES, 'UTF-8'); ?>"
                           maxlength="2048" required>
                    <div class="form-hint">游戏的完整访问地址</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">管理员邮箱 *</label>
                    <input type="email" name="admin_email" class="form-input"
                           value="admin@example.com" maxlength="254" required>
                </div>
                
                <div class="text-center mt-20">
                    <button type="submit" class="btn">下一步</button>
                </div>
            </form>
            
            <?php elseif ($step == 3): ?>
            <!-- 步骤3: 管理员账户 -->
            <h3>步骤 3: 创建管理员账户</h3>
            <p>请设置超级管理员账户信息。</p>
            
            <form method="post">
                <input type="hidden" name="step" value="3">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($installerCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label class="form-label">管理员用户名 *</label>
                    <input type="text" name="admin_username" class="form-input"
                           minlength="3" maxlength="20"
                           pattern="[\p{L}\p{N}_-]+" required>
                    <div class="form-hint">3至20位文字、数字、下划线或短横线，用于登录管理后台</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">管理员密码 *</label>
                    <input type="password" name="admin_password" class="form-input"
                           minlength="10" maxlength="256" required>
                    <div class="form-hint">密码长度10至256位</div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">确认密码 *</label>
                    <input type="password" name="admin_password_confirm"
                           class="form-input" minlength="10" maxlength="256"
                           required>
                </div>
                
                <div class="text-center mt-20">
                    <button type="submit" class="btn">下一步</button>
                </div>
            </form>
            
            <?php elseif ($step == 4): ?>
            <!-- 步骤4: 执行安装 -->
            <h3>步骤 4: 正在安装...</h3>
            <p>正在创建数据库表、初始化数据和配置文件，请稍候...</p>
            
            <form method="post">
                <input type="hidden" name="step" value="4">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($installerCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="text-center mt-20">
                    <button type="submit" class="btn">开始安装</button>
                </div>
            </form>
            
            <?php elseif ($step == 5): ?>
            <!-- 步骤5: 安装完成 -->
            <h3>🎉 安装完成！</h3>
            <p>恭喜！种火集结号已成功安装。</p>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 6px; margin: 20px 0;">
                <h4>安装信息：</h4>
                <ul>
                    <li><strong>游戏版本：</strong><?php echo htmlspecialchars(GAME_VERSION, ENT_QUOTES, 'UTF-8'); ?></li>
                    <li><strong>安装时间：</strong><?php echo date('Y-m-d H:i:s'); ?></li>
                    <li><strong>管理员账户：</strong><?php echo htmlspecialchars($_SESSION['install_config']['admin_username'] ?? ''); ?></li>
                </ul>
            </div>
            
            <div style="background: #fff3cd; padding: 15px; border-radius: 6px; margin: 20px 0; border-left: 4px solid #ffc107;">
                <h4>重要提示：</h4>
                <ul>
                    <li>请删除或重命名 <code>install.php</code> 文件以确保安全</li>
                    <li>建议设置定时任务执行 <code>cron_tasks.php</code>（每分钟一次）</li>
                    <li>请妥善保管管理员账户信息</li>
                </ul>
            </div>
            
            <div class="text-center mt-20">
                <a href="index.php" class="btn btn-success">进入游戏</a>
                <a href="admin/" class="btn" style="margin-left: 10px;">管理后台</a>
            </div>
            
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
