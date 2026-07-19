<?php
// 种火集结号 - 身份验证与运行时访问控制 / Fireseed Engage - Authentication and runtime access control

class AuthSecurity {
    private const MAX_FAILED_ATTEMPTS = 5;
    private const THROTTLE_WINDOW_SECONDS = 900;
    private const THROTTLE_DIRECTORY = 'fireseed-engage-login-throttle';

    /**
     * 建立经过身份验证且已轮换ID的会话 / Establish an authenticated session with a rotated id
     * @param int $userId 用户ID / User id
     * @return bool 是否成功 / Whether the session was established
     */
    public static function establishAuthenticatedSession($userId) {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return false;
        }

        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
            return false;
        }
        if (!session_regenerate_id(true)) {
            return false;
        }

        $_SESSION['user_id'] = $userId;
        unset($_SESSION['csrf_token']);
        return true;
    }

    /**
     * 完整销毁当前会话与会话Cookie / Fully destroy the current session and its cookie
     * @return void
     */
    public static function destroyAuthenticatedSession() {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $parameters['path'],
                'domain' => $parameters['domain'],
                'secure' => (bool) $parameters['secure'],
                'httponly' => (bool) $parameters['httponly'],
                'samesite' => isset($parameters['samesite'])
                    ? $parameters['samesite']
                    : 'Lax'
            ]);
        }

        session_destroy();
    }

    /**
     * 获取登录限速状态 / Get the current login throttle status
     * @param string $scope 登录入口范围 / Login entry scope
     * @param string $username 用户名 / Username
     * @return array 是否允许、剩余次数与重试秒数 / Allowed state, remaining attempts, and retry seconds
     */
    public static function getLoginThrottleStatus($scope, $username) {
        $attempts = self::mutateThrottleRecord(
            $scope,
            $username,
            function ($recentAttempts) {
                return $recentAttempts;
            }
        );

        if ($attempts === null) {
            return [
                'allowed' => true,
                'remaining_attempts' => self::MAX_FAILED_ATTEMPTS,
                'retry_after' => 0,
                'storage_available' => false
            ];
        }

        $attemptCount = count($attempts);
        if ($attemptCount < self::MAX_FAILED_ATTEMPTS) {
            return [
                'allowed' => true,
                'remaining_attempts' =>
                    self::MAX_FAILED_ATTEMPTS - $attemptCount,
                'retry_after' => 0,
                'storage_available' => true
            ];
        }

        $oldestAttempt = min($attempts);
        return [
            'allowed' => false,
            'remaining_attempts' => 0,
            'retry_after' => max(
                1,
                $oldestAttempt + self::THROTTLE_WINDOW_SECONDS - time()
            ),
            'storage_available' => true
        ];
    }

    /**
     * 记录一次登录失败 / Record one failed login attempt
     * @param string $scope 登录入口范围 / Login entry scope
     * @param string $username 用户名 / Username
     * @return void
     */
    public static function recordLoginFailure($scope, $username) {
        self::mutateThrottleRecord(
            $scope,
            $username,
            function ($recentAttempts, $now) {
                $recentAttempts[] = $now;
                return array_slice(
                    $recentAttempts,
                    -self::MAX_FAILED_ATTEMPTS
                );
            }
        );
    }

    /**
     * 清除登录失败记录 / Clear failed login attempts
     * @param string $scope 登录入口范围 / Login entry scope
     * @param string $username 用户名 / Username
     * @return void
     */
    public static function clearLoginFailures($scope, $username) {
        self::mutateThrottleRecord(
            $scope,
            $username,
            function () {
                return [];
            }
        );
    }

    /**
     * 获取新玩家注册的运行时可用状态 / Get runtime new-player registration availability
     * @return array 开放状态、原因与容量 / Open state, reason, and capacity
     */
    public static function getRegistrationAvailability() {
        $registrationEnabled = (bool) GameConfig::get(
            'new_player_registration',
            1
        );
        $maxPlayers = max(1, (int) GameConfig::get('max_players', 1000));
        $currentPlayers = (int) User::getTotalUserCount();

        if (!$registrationEnabled) {
            return [
                'open' => false,
                'message' => '新玩家注册当前已关闭 / New-player registration is currently closed.',
                'current_players' => $currentPlayers,
                'max_players' => $maxPlayers
            ];
        }
        if ($currentPlayers >= $maxPlayers) {
            return [
                'open' => false,
                'message' => '服务器内测名额已满 / The internal-beta player capacity has been reached.',
                'current_players' => $currentPlayers,
                'max_players' => $maxPlayers
            ];
        }

        return [
            'open' => true,
            'message' => '',
            'current_players' => $currentPlayers,
            'max_players' => $maxPlayers
        ];
    }

    /**
     * 对非管理员请求执行维护模式 / Enforce maintenance mode for non-administrator requests
     * @param User|null $currentUser 当前会话用户 / Current session user
     * @return void
     */
    public static function enforceMaintenanceMode($currentUser = null) {
        if (PHP_SAPI === 'cli'
            || !(bool) GameConfig::get('maintenance_mode', 0)
            || (
                $currentUser instanceof User
                && $currentUser->isValid()
                && $currentUser->isAdmin()
            )
            || self::isMaintenanceBypassRequest()
        ) {
            return;
        }

        $message = '系统正在维护，普通玩家暂时无法进入；管理员仍可通过后台登录。'
            . ' / The game is under maintenance; administrators may still use the admin login.';
        $scriptName = self::getRequestScriptName();
        $acceptHeader = isset($_SERVER['HTTP_ACCEPT'])
            ? (string) $_SERVER['HTTP_ACCEPT']
            : '';
        $isJsonRequest = strpos($scriptName, '/api/') !== false
            || stripos($acceptHeader, 'application/json') !== false
            || (
                isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH'])
                    === 'xmlhttprequest'
            );

        http_response_code(503);
        header('Retry-After: 300');
        header('Cache-Control: no-store');
        if ($isJsonRequest) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(
                ['success' => false, 'message' => $message],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

        header('Content-Type: text/html; charset=UTF-8');
        $siteName = defined('SITE_NAME')
            ? htmlspecialchars(
                (string) SITE_NAME,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            )
            : 'Fireseed Engage';
        $safeMessage = htmlspecialchars(
            $message,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $adminLoginUrl = htmlspecialchars(
            (defined('SITE_URL') ? rtrim((string) SITE_URL, '/') : '')
                . '/admin/login.php',
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $siteName . ' - 维护中 / Maintenance</title></head>'
            . '<body><main style="max-width:640px;margin:10vh auto;padding:24px;'
            . 'font-family:sans-serif"><h1>维护中 / Maintenance</h1><p>'
            . $safeMessage . '</p><p><a href="' . $adminLoginUrl . '">'
            . '管理员登录 / Administrator login</a></p></main></body></html>';
        exit;
    }

    /**
     * 以文件锁读取并更新限速记录 / Read and update a throttle record under a file lock
     * @param string $scope 登录入口范围 / Login entry scope
     * @param string $username 用户名 / Username
     * @param callable $mutator 更新函数 / Mutation callback
     * @return array|null 最近失败时间，存储失败时为空 / Recent failure times, or null on storage failure
     */
    private static function mutateThrottleRecord(
        $scope,
        $username,
        $mutator
    ) {
        $directory = rtrim(sys_get_temp_dir(), '\\/')
            . DIRECTORY_SEPARATOR
            . self::THROTTLE_DIRECTORY;
        if (!is_dir($directory)
            && !@mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            error_log('Unable to create the login throttle directory.');
            return null;
        }

        $address = isset($_SERVER['REMOTE_ADDR'])
            ? (string) $_SERVER['REMOTE_ADDR']
            : 'unknown';
        $normalizedScope = preg_replace(
            '/[^a-z0-9_-]/i',
            '_',
            (string) $scope
        );
        $normalizedUsername = function_exists('mb_strtolower')
            ? mb_strtolower(trim((string) $username), 'UTF-8')
            : strtolower(trim((string) $username));
        $recordKey = hash(
            'sha256',
            $normalizedScope . "\0" . $address . "\0" . $normalizedUsername
        );
        $recordPath = $directory . DIRECTORY_SEPARATOR . $recordKey . '.json';
        $handle = @fopen($recordPath, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            error_log('Unable to lock a login throttle record.');
            return null;
        }

        rewind($handle);
        $decoded = json_decode((string) stream_get_contents($handle), true);
        $attempts = isset($decoded['attempts'])
            && is_array($decoded['attempts'])
            ? $decoded['attempts']
            : [];
        $now = time();
        $cutoff = $now - self::THROTTLE_WINDOW_SECONDS;
        $attempts = array_values(array_filter(
            $attempts,
            function ($timestamp) use ($cutoff, $now) {
                return is_numeric($timestamp)
                    && (int) $timestamp > $cutoff
                    && (int) $timestamp <= $now;
            }
        ));

        $updatedAttempts = call_user_func($mutator, $attempts, $now);
        if (!is_array($updatedAttempts)) {
            $updatedAttempts = $attempts;
        }
        $updatedAttempts = array_values(array_map(
            'intval',
            $updatedAttempts
        ));

        rewind($handle);
        ftruncate($handle, 0);
        fwrite(
            $handle,
            json_encode(['attempts' => $updatedAttempts])
        );
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $updatedAttempts;
    }

    /**
     * 判断当前请求是否必须在维护期间保持可用 / Determine whether this request must remain available during maintenance
     * @return bool 是否绕过维护页 / Whether the request bypasses the maintenance page
     */
    private static function isMaintenanceBypassRequest() {
        $scriptName = self::getRequestScriptName();
        return (bool) preg_match(
            '#/(?:admin/login|logout)\.php$#',
            $scriptName
        );
    }

    /**
     * 获取规范化请求脚本路径 / Get the normalized request script path
     * @return string 请求脚本路径 / Request script path
     */
    private static function getRequestScriptName() {
        $scriptName = isset($_SERVER['SCRIPT_NAME'])
            ? (string) $_SERVER['SCRIPT_NAME']
            : '';
        return '/' . ltrim(str_replace('\\', '/', $scriptName), '/');
    }
}
