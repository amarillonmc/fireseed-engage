<?php
// 种火集结号 - 游戏内资源兑换 / Fireseed Engage - Earned-resource exchange

require_once 'includes/init.php';
require_once 'includes/gameplay_ui.php';

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

$economyService = new EconomyService();
$result = null;
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken()) {
        $result = ['success' => false, 'message' => '请求校验失败，请刷新页面后重试'];
    } elseif (isset($_POST['action']) && $_POST['action'] === 'purchase') {
        $result = $economyService->purchase(
            $user->getUserId(),
            isset($_POST['shop_item_id']) ? (int) $_POST['shop_item_id'] : 0,
            isset($_POST['quantity']) ? (int) $_POST['quantity'] : 1
        );
    }
}

$resource = new Resource($user->getUserId());
$wallet = $economyService->getWallet($user->getUserId());
$items = $economyService->getItems($user->getUserId());
$catalog = $economyService->getShopCatalog($user->getUserId());
$pageTitle = '资源兑换';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo escapeHtml(SITE_NAME . ' - ' . $pageTitle); ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container">
    <?php renderGameplayHeader($pageTitle, $user, 'shop'); ?>
    <main>
        <?php renderGameplayResourceBar($resource); ?>
        <?php renderGameplayNotice($result); ?>

        <section class="gameplay-section">
            <h3>持有代币与素材</h3>
            <p>技能点 <?php echo number_format((int) $wallet['skill_points']); ?> /
                功勋 <?php echo number_format((int) $wallet['merit_points']); ?> /
                竞技代币 <?php echo number_format((int) $wallet['arena_tokens']); ?> /
                蜕变核心 <?php echo number_format(isset($items['break_core']) ? $items['break_core'] : 0); ?></p>
            <p class="muted">所有兑换只使用游戏内产出，不存在付费货币。</p>
        </section>

        <div class="gameplay-grid">
            <?php foreach ($catalog as $item): ?>
                <?php
                $remaining = $item['daily_limit'] === null
                    ? null
                    : max(0, (int) $item['daily_limit'] - (int) $item['purchased_today']);
                ?>
                <section class="gameplay-card">
                    <h3><?php echo escapeHtml($item['name']); ?></h3>
                    <p><?php echo escapeHtml($item['description']); ?></p>
                    <p><strong>成本：</strong><?php echo escapeHtml(formatGameplayBundle($item['cost'])); ?></p>
                    <p><strong>获得：</strong><?php echo escapeHtml(formatGameplayBundle($item['grant'])); ?></p>
                    <?php if ($remaining !== null): ?>
                        <p>今日剩余：<?php echo number_format($remaining); ?></p>
                    <?php endif; ?>
                    <form method="post" class="gameplay-form">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="purchase">
                        <input type="hidden" name="shop_item_id" value="<?php echo (int) $item['shop_item_id']; ?>">
                        <label>数量
                            <input type="number" name="quantity" min="1" max="<?php echo $remaining === null ? 10 : max(1, min(10, $remaining)); ?>" value="1">
                        </label>
                        <button class="gameplay-button" type="submit" <?php echo $remaining === 0 ? 'disabled' : ''; ?>>兑换</button>
                    </form>
                </section>
            <?php endforeach; ?>
        </div>
    </main>
    <?php renderGameplayFooter(); ?>
</div>
</body>
</html>
