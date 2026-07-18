<?php
// 种火集结号 - 联盟与协同作战页面 / Fireseed Engage - Alliance and cooperative operation page

require_once 'includes/init.php';
require_once 'includes/gameplay_ui.php';
require_once 'includes/classes/AllianceService.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User((int) $_SESSION['user_id']);
if (!$user->isValid()) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}

/**
 * 转义联盟页面输出 / Escape alliance page output
 */
function allianceEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$service = new AllianceService();
$actionResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = isset($_POST['csrf_token']) ? (string) $_POST['csrf_token'] : '';
    $sessionToken = isset($_SESSION['alliance_csrf_token'])
        ? (string) $_SESSION['alliance_csrf_token']
        : '';

    if ($sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
        $actionResult = [
            'success' => false,
            'message' => '页面令牌已过期，请刷新页面后重试。',
            'data' => []
        ];
    } else {
        $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
        switch ($action) {
            case 'create_alliance':
                $actionResult = $service->createAlliance(
                    $user->getUserId(),
                    $_POST['name'] ?? '',
                    $_POST['tag'] ?? '',
                    $_POST['description'] ?? ''
                );
                break;
            case 'apply':
                $actionResult = $service->applyToAlliance(
                    $user->getUserId(),
                    $_POST['alliance_id'] ?? 0,
                    $_POST['message'] ?? ''
                );
                break;
            case 'approve_application':
                $actionResult = $service->resolveApplication(
                    $user->getUserId(),
                    $_POST['application_id'] ?? 0,
                    'accepted'
                );
                break;
            case 'reject_application':
                $actionResult = $service->resolveApplication(
                    $user->getUserId(),
                    $_POST['application_id'] ?? 0,
                    'rejected'
                );
                break;
            case 'leave_alliance':
                $actionResult = $service->leaveAlliance($user->getUserId());
                break;
            case 'set_member_role':
                $actionResult = $service->setMemberRole(
                    $user->getUserId(),
                    $_POST['member_user_id'] ?? 0,
                    $_POST['role'] ?? ''
                );
                break;
            case 'send_aid':
                $actionResult = $service->sendAid(
                    $user->getUserId(),
                    $_POST['receiver_id'] ?? 0,
                    $_POST['resource_type'] ?? '',
                    $_POST['amount'] ?? 0
                );
                break;
            case 'create_operation':
                $actionResult = $service->createOperation(
                    $user->getUserId(),
                    $_POST['title'] ?? '',
                    $_POST['target_type'] ?? '',
                    $_POST['target_id'] ?? 0,
                    $_POST['target_x'] ?? 0,
                    $_POST['target_y'] ?? 0,
                    $_POST['launch_at'] ?? ''
                );
                break;
            case 'join_operation':
                $actionResult = $service->joinOperation(
                    $user->getUserId(),
                    $_POST['operation_id'] ?? 0,
                    $_POST['army_id'] ?? 0
                );
                break;
            default:
                $actionResult = [
                    'success' => false,
                    'message' => '无法识别该操作。',
                    'data' => []
                ];
        }
    }

    // 每次提交后轮换令牌，防止重复提交 / Rotate the token after each submission to prevent replay
    unset($_SESSION['alliance_csrf_token']);
}

if (empty($_SESSION['alliance_csrf_token'])) {
    $_SESSION['alliance_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['alliance_csrf_token'];

$resource = new Resource($user->getUserId());
$overview = $service->getAllianceOverview($user->getUserId());
$alliances = $overview === null ? $service->listAlliances() : [];
$eligibleArmies = $overview !== null ? $service->getEligibleArmies($user->getUserId()) : [];
$roleLabels = ['leader' => '盟主', 'officer' => '干部', 'member' => '成员'];
$resourceLabels = [
    'bright' => '亮晶晶',
    'warm' => '暖洋洋',
    'cold' => '冷冰冰',
    'green' => '郁萌萌',
    'day' => '昼闪闪',
    'night' => '夜静静'
];
$pageTitle = '联盟与协同作战';
$launchDefault = date('Y-m-d\TH:i', time() + 3600);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo allianceEscape(SITE_NAME); ?> - <?php echo allianceEscape($pageTitle); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .alliance-panel { background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 5px; }
        .alliance-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: 16px; }
        .alliance-card { border: 1px solid #ddd; padding: 15px; border-radius: 5px; background: #fafafa; }
        .alliance-card h3, .alliance-card h4 { margin-bottom: 10px; }
        .alliance-form label { display: block; margin-top: 8px; font-weight: bold; }
        .alliance-form input, .alliance-form textarea, .alliance-form select {
            width: 100%; padding: 8px; margin-top: 4px; border: 1px solid #ccc; border-radius: 3px;
        }
        .alliance-form button, .inline-form button {
            margin-top: 10px; padding: 8px 12px; border: 0; border-radius: 3px;
            background: #333; color: #fff; cursor: pointer;
        }
        .inline-form { display: inline-block; margin-right: 6px; }
        .alliance-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .alliance-table th, .alliance-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        .alliance-table th { background: #f0f0f0; }
        .operation-meta { color: #666; font-size: 14px; margin: 4px 0; }
        .danger-button { background: #9d2323 !important; }
    </style>
</head>
<body>
<div class="container">
    <header>
        <h1 class="site-title"><?php echo allianceEscape(SITE_NAME); ?></h1>
        <h2 class="page-title"><?php echo allianceEscape($pageTitle); ?></h2>
        <nav class="main-nav">
            <ul>
                <li><a href="index.php">主基地</a></li>
                <li><a href="generals.php">武将</a></li>
                <li><a href="armies.php">军队</a></li>
                <li><a href="map.php">地图</a></li>
                <li><a href="social.php">社交</a></li>
                <li class="circuit-points">
                    <?php echo renderImageResource(
                        'resource_circuit_points',
                        24,
                        ['alt' => '思考回路 / Circuit Points']
                    ); ?>
                    <span class="circuit-label">思考回路:</span>
                    <span class="circuit-value">
                        <?php echo number_format($user->getCircuitPoints()); ?> /
                        <?php echo number_format($user->getMaxCircuitPoints()); ?>
                    </span>
                </li>
            </ul>
        </nav>
    </header>

    <main>
        <!-- 资源栏 / Resource bar -->
        <?php renderGameplayResourceBar($resource); ?>

        <?php if ($actionResult !== null): ?>
            <div class="message <?php echo $actionResult['success'] ? 'success' : 'error'; ?>">
                <?php echo allianceEscape($actionResult['message']); ?>
            </div>
        <?php endif; ?>

        <?php if ($overview === null): ?>
            <section class="alliance-panel">
                <h3>建立新联盟</h3>
                <form class="alliance-form" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo allianceEscape($csrfToken); ?>">
                    <input type="hidden" name="action" value="create_alliance">
                    <label for="alliance-name">联盟名称</label>
                    <input id="alliance-name" name="name" maxlength="40" required>
                    <label for="alliance-tag">联盟简称</label>
                    <input id="alliance-tag" name="tag" maxlength="12" pattern="[\w-]{2,12}" required>
                    <label for="alliance-description">联盟简介</label>
                    <textarea id="alliance-description" name="description" maxlength="500" rows="4"></textarea>
                    <button type="submit">建立联盟</button>
                </form>
            </section>

            <section class="alliance-panel">
                <h3>现有联盟</h3>
                <div class="alliance-grid">
                    <?php foreach ($alliances as $alliance): ?>
                        <article class="alliance-card">
                            <h4>[<?php echo allianceEscape($alliance['tag']); ?>] <?php echo allianceEscape($alliance['name']); ?></h4>
                            <p>盟主：<?php echo allianceEscape($alliance['leader_name'] ?? '空缺'); ?></p>
                            <p>等级：<?php echo number_format($alliance['level']); ?>　成员：<?php echo number_format($alliance['member_count']); ?></p>
                            <p><?php echo nl2br(allianceEscape($alliance['description'] ?? '')); ?></p>
                            <form class="alliance-form" method="post">
                                <input type="hidden" name="csrf_token" value="<?php echo allianceEscape($csrfToken); ?>">
                                <input type="hidden" name="action" value="apply">
                                <input type="hidden" name="alliance_id" value="<?php echo (int) $alliance['alliance_id']; ?>">
                                <label>申请留言</label>
                                <input name="message" maxlength="255">
                                <button type="submit">申请加入</button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                    <?php if (empty($alliances)): ?>
                        <p>目前还没有联盟。</p>
                    <?php endif; ?>
                </div>
            </section>
        <?php else: ?>
            <section class="alliance-panel">
                <h3>[<?php echo allianceEscape($overview['alliance']['tag']); ?>] <?php echo allianceEscape($overview['alliance']['name']); ?></h3>
                <p>你的身份：<?php echo allianceEscape($roleLabels[$overview['membership']['role']] ?? $overview['membership']['role']); ?></p>
                <p>联盟等级：<?php echo number_format($overview['alliance']['level']); ?>　经验：<?php echo number_format($overview['alliance']['experience']); ?></p>
                <p><?php echo nl2br(allianceEscape($overview['alliance']['description'] ?? '')); ?></p>
                <form class="inline-form" method="post" onsubmit="return confirm('确定要离开联盟吗？');">
                    <input type="hidden" name="csrf_token" value="<?php echo allianceEscape($csrfToken); ?>">
                    <input type="hidden" name="action" value="leave_alliance">
                    <button class="danger-button" type="submit"><?php echo $overview['membership']['role'] === 'leader' ? '解散或离开联盟' : '离开联盟'; ?></button>
                </form>
            </section>

            <div class="alliance-grid">
                <section class="alliance-panel">
                    <h3>联盟成员</h3>
                    <table class="alliance-table">
                        <thead><tr><th>成员</th><th>身份</th><th>贡献</th></tr></thead>
                        <tbody>
                        <?php foreach ($overview['members'] as $member): ?>
                            <tr>
                                <td><?php echo allianceEscape($member['username']); ?></td>
                                <td>
                                    <?php echo allianceEscape($roleLabels[$member['role']] ?? $member['role']); ?>
                                    <?php if ($overview['membership']['role'] === 'leader' && $member['role'] !== 'leader'): ?>
                                        <form class="inline-form" method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo allianceEscape($csrfToken); ?>">
                                            <input type="hidden" name="action" value="set_member_role">
                                            <input type="hidden" name="member_user_id" value="<?php echo (int) $member['user_id']; ?>">
                                            <input type="hidden" name="role" value="<?php echo $member['role'] === 'officer' ? 'member' : 'officer'; ?>">
                                            <button type="submit"><?php echo $member['role'] === 'officer' ? '撤销干部' : '任命干部'; ?></button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo number_format($member['contribution']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </section>

                <section class="alliance-panel">
                    <h3>成员援助</h3>
                    <form class="alliance-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo allianceEscape($csrfToken); ?>">
                        <input type="hidden" name="action" value="send_aid">
                        <label for="aid-member">接收成员</label>
                        <select id="aid-member" name="receiver_id" required>
                            <?php foreach ($overview['members'] as $member): ?>
                                <?php if ((int) $member['user_id'] !== (int) $user->getUserId()): ?>
                                    <option value="<?php echo (int) $member['user_id']; ?>"><?php echo allianceEscape($member['username']); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <label for="aid-resource">资源</label>
                        <select id="aid-resource" name="resource_type">
                            <?php foreach ($resourceLabels as $resourceType => $resourceName): ?>
                                <option value="<?php echo allianceEscape($resourceType); ?>"><?php echo allianceEscape($resourceName); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <label for="aid-amount">数量</label>
                        <input id="aid-amount" name="amount" type="number" min="1" max="1000000" required>
                        <button type="submit">发送援助</button>
                    </form>
                </section>
            </div>

            <?php if (in_array($overview['membership']['role'], ['leader', 'officer'], true)): ?>
                <section class="alliance-panel">
                    <h3>加入申请</h3>
                    <?php if (empty($overview['applications'])): ?>
                        <p>没有待处理申请。</p>
                    <?php else: ?>
                        <table class="alliance-table">
                            <thead><tr><th>申请者</th><th>留言</th><th>时间</th><th>操作</th></tr></thead>
                            <tbody>
                            <?php foreach ($overview['applications'] as $application): ?>
                                <tr>
                                    <td><?php echo allianceEscape($application['username']); ?></td>
                                    <td><?php echo allianceEscape($application['message'] ?? ''); ?></td>
                                    <td><?php echo allianceEscape($application['created_at']); ?></td>
                                    <td>
                                        <form class="inline-form" method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo allianceEscape($csrfToken); ?>">
                                            <input type="hidden" name="action" value="approve_application">
                                            <input type="hidden" name="application_id" value="<?php echo (int) $application['application_id']; ?>">
                                            <button type="submit">批准</button>
                                        </form>
                                        <form class="inline-form" method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo allianceEscape($csrfToken); ?>">
                                            <input type="hidden" name="action" value="reject_application">
                                            <input type="hidden" name="application_id" value="<?php echo (int) $application['application_id']; ?>">
                                            <button class="danger-button" type="submit">拒绝</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <div class="alliance-grid">
                <section class="alliance-panel">
                    <h3>建立协同作战</h3>
                    <form class="alliance-form" method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo allianceEscape($csrfToken); ?>">
                        <input type="hidden" name="action" value="create_operation">
                        <label for="operation-title">行动标题</label>
                        <input id="operation-title" name="title" maxlength="100" required>
                        <label for="target-type">目标类型</label>
                        <select id="target-type" name="target_type">
                            <option value="tile">地图格</option>
                            <option value="city">城池</option>
                            <option value="army">军队</option>
                        </select>
                        <label for="target-id">目标 ID</label>
                        <input id="target-id" name="target_id" type="number" min="1" required>
                        <p class="operation-meta">目标坐标会由服务器根据目标 ID 自动读取。</p>
                        <label for="launch-at">集合出发时间</label>
                        <input id="launch-at" name="launch_at" type="datetime-local" value="<?php echo allianceEscape($launchDefault); ?>" required>
                        <button type="submit">建立行动</button>
                    </form>
                </section>

                <section class="alliance-panel">
                    <h3>协同作战列表</h3>
                    <p class="operation-meta">报名军队会在集合时间由定时任务统一派遣；目标失效、退出联盟或军队不再空闲时会安全跳过。</p>
                    <?php if (empty($overview['operations'])): ?>
                        <p>尚未建立协同作战。</p>
                    <?php endif; ?>
                    <?php foreach ($overview['operations'] as $operation): ?>
                        <article class="alliance-card">
                            <h4><?php echo allianceEscape($operation['title']); ?></h4>
                            <p class="operation-meta">
                                发起者：<?php echo allianceEscape($operation['creator_name']); ?>　
                                状态：<?php echo allianceEscape($operation['status']); ?>
                            </p>
                            <p class="operation-meta">
                                目标：<?php echo allianceEscape($operation['target_type']); ?>
                                #<?php echo number_format($operation['target_id']); ?>
                                (<?php echo number_format($operation['target_x']); ?>, <?php echo number_format($operation['target_y']); ?>)
                            </p>
                            <p class="operation-meta">
                                出发：<?php echo allianceEscape($operation['launch_at']); ?>　
                                参战军队：<?php echo number_format($operation['army_count']); ?>
                            </p>
                            <?php if ($operation['status'] === 'open' && !empty($eligibleArmies)): ?>
                                <form class="alliance-form" method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo allianceEscape($csrfToken); ?>">
                                    <input type="hidden" name="action" value="join_operation">
                                    <input type="hidden" name="operation_id" value="<?php echo (int) $operation['operation_id']; ?>">
                                    <label>派遣空闲军队</label>
                                    <select name="army_id" required>
                                        <?php foreach ($eligibleArmies as $army): ?>
                                            <option value="<?php echo (int) $army['army_id']; ?>">
                                                <?php echo allianceEscape($army['name']); ?>
                                                (<?php echo number_format($army['unit_count']); ?> 人)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit">加入行动</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </section>
            </div>
        <?php endif; ?>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo allianceEscape(SITE_NAME); ?> - 版本 <?php echo allianceEscape(GAME_VERSION); ?></p>
    </footer>
</div>
</body>
</html>
