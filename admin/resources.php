<?php
// 种火集结号 - 后台资源层入口 / Fireseed Engage - administration resource-layer entry
require_once '../includes/init.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user = new User($_SESSION['user_id']);
if (!$user->isValid()) {
    session_unset();
    session_destroy();
    header('Location: login.php');
    exit;
}
if (!$user->isAdmin()) {
    header('Location: ../index.php');
    exit;
}

$adminManager = new AdminManager($user);
$canManageGenerals = $adminManager->hasPermission('manage_generals');
$canManageSkills = $adminManager->hasPermission('manage_skills');
$canManagePools = $adminManager->hasPermission('manage_card_pools');
if (!$canManageGenerals && !$canManageSkills && !$canManagePools) {
    http_response_code(403);
    die('您没有权限访问资源层');
}

$db = Database::getInstance()->getConnection();

/**
 * 安全读取单个计数值 / Safely reads one aggregate count
 *
 * @param mysqli $db 数据库连接 / Database connection
 * @param string $query 固定查询 / Static query
 * @return int 计数 / Count
 */
function adminResourceCount($db, $query) {
    $stmt = $db->prepare($query);
    if (!$stmt || !$stmt->execute()) {
        if ($stmt) {
            $stmt->close();
        }
        return 0;
    }

    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return max(0, (int) ($row['total'] ?? 0));
}

$generalCount = $canManageGenerals
    ? adminResourceCount(
        $db,
        'SELECT COUNT(*) AS total FROM generals WHERE owner_id = 0'
    )
    : 0;
$skillCount = $canManageSkills
    ? adminResourceCount($db, 'SELECT COUNT(*) AS total FROM skill_card_catalog')
    : 0;
$poolCount = $canManagePools
    ? adminResourceCount(
        $db,
        "SELECT COUNT(*) AS total FROM card_pools WHERE status <> 'archived'"
    )
    : 0;
$publishedPoolCount = $canManagePools
    ? adminResourceCount(
        $db,
        "SELECT COUNT(*) AS total FROM card_pools WHERE status = 'published'"
    )
    : 0;
$pageTitle = '资源管理';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml(SITE_NAME . ' - ' . $pageTitle); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body { background: #f4f6f8; }
        .resource-admin { max-width: 1200px; margin: 0 auto; padding: 20px; }
        .resource-header {
            display: flex; justify-content: space-between; align-items: center;
            gap: 16px; padding: 22px; margin-bottom: 24px; color: #fff;
            border-radius: 10px; background: linear-gradient(135deg, #8e44ad, #5b2c6f);
        }
        .resource-header h1 { margin: 0 0 6px; font-size: 26px; }
        .resource-header p { margin: 0; opacity: .9; }
        .back-link {
            flex: 0 0 auto; color: #fff; text-decoration: none;
            padding: 9px 14px; border-radius: 6px; background: rgba(255,255,255,.18);
        }
        .resource-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
        }
        .resource-card {
            display: flex; flex-direction: column; min-height: 220px; padding: 24px;
            color: #2c3e50; text-decoration: none; background: #fff;
            border: 1px solid #e3e8ee; border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0,0,0,.06);
            transition: transform .2s, box-shadow .2s;
        }
        .resource-card:hover {
            transform: translateY(-3px); box-shadow: 0 8px 22px rgba(0,0,0,.1);
        }
        .resource-icon { font-size: 42px; margin-bottom: 14px; }
        .resource-card h2 { margin: 0 0 10px; font-size: 21px; }
        .resource-card p { margin: 0 0 18px; color: #687785; line-height: 1.6; }
        .resource-stats {
            display: flex; gap: 18px; margin-top: auto; padding-top: 16px;
            border-top: 1px solid #edf0f2;
        }
        .resource-stat strong { display: block; font-size: 22px; color: #8e44ad; }
        .resource-stat span { color: #7f8c8d; font-size: 13px; }
        @media (max-width: 640px) {
            .resource-header { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
    <main class="resource-admin">
        <header class="resource-header">
            <div>
                <h1>🗃️ 资源层</h1>
                <p>维护可抽取资源，并将资源组织为可发布的武将或技能卡池。</p>
            </div>
            <a href="index.php" class="back-link">← 返回管理后台</a>
        </header>

        <div class="resource-grid">
            <?php if ($canManageGenerals): ?>
                <a class="resource-card" href="generals.php">
                    <span class="resource-icon">⚔️</span>
                    <h2>武将卡目录</h2>
                    <p>创建和维护公共武将模板、基础属性、元素与固有技能。</p>
                    <div class="resource-stats">
                        <div class="resource-stat">
                            <strong><?php echo number_format($generalCount); ?></strong>
                            <span>公共模板</span>
                        </div>
                    </div>
                </a>
            <?php endif; ?>

            <?php if ($canManageSkills): ?>
                <a class="resource-card" href="skills.php">
                    <span class="resource-icon">✨</span>
                    <h2>技能卡目录</h2>
                    <p>维护技能效果、元素、发动方式、稀有度、冷却和启用状态。</p>
                    <div class="resource-stats">
                        <div class="resource-stat">
                            <strong><?php echo number_format($skillCount); ?></strong>
                            <span>技能卡</span>
                        </div>
                    </div>
                </a>
            <?php endif; ?>

            <?php if ($canManagePools): ?>
                <a class="resource-card" href="card_pools.php">
                    <span class="resource-icon">🎴</span>
                    <h2>卡池与出率</h2>
                    <p>创建武将或技能卡池，配置成本、开放时间、可抽次数、成员权重与发布状态。</p>
                    <div class="resource-stats">
                        <div class="resource-stat">
                            <strong><?php echo number_format($poolCount); ?></strong>
                            <span>未归档卡池</span>
                        </div>
                        <div class="resource-stat">
                            <strong><?php echo number_format($publishedPoolCount); ?></strong>
                            <span>已发布</span>
                        </div>
                    </div>
                </a>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
