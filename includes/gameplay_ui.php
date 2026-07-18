<?php
// 种火集结号 - 扩展玩法共用界面组件 / Fireseed Engage - Shared expansion gameplay UI

/**
 * 渲染扩展玩法页首和导航 / Render the expansion header and navigation
 * @param string $pageTitle 页面标题 / Page title
 * @param User $user 当前玩家 / Current user
 * @param string $activePage 当前页面代码 / Active page code
 * @return void
 */
function renderGameplayHeader($pageTitle, $user, $activePage = '') {
    $links = [
        'home' => ['index.php', '主基地'],
        'engage' => ['engage.php', '集结中枢'],
        'subbases' => ['subbases.php', '分基地'],
        'generals' => ['generals.php', '武将'],
        'skills' => ['skills.php', '技能卡'],
        'season' => ['season.php', '赛季'],
        'challenges' => ['challenges.php', '挑战'],
        'battles' => ['battles.php', '战报'],
        'scouting' => ['scouting.php', '侦察'],
        'alliance' => ['alliance.php', '联盟'],
        'vassal' => ['vassal.php', '附属'],
        'quests' => ['quests.php', '任务'],
        'shop' => ['shop.php', '兑换'],
        'social' => ['social.php', '通讯']
    ];

    echo '<header>';
    echo '<h1 class="site-title">' . escapeHtml(SITE_NAME) . '</h1>';
    echo '<h2 class="page-title">' . escapeHtml($pageTitle) . '</h2>';
    echo '<nav class="main-nav"><ul>';
    foreach ($links as $code => $link) {
        $class = $code === $activePage ? ' class="active"' : '';
        echo '<li><a' . $class . ' href="' . escapeHtml($link[0]) . '">'
            . escapeHtml($link[1]) . '</a></li>';
    }
    $circuitIcon = function_exists('renderImageResource')
        ? renderImageResource(
            'resource_circuit_points',
            24,
            ['alt' => '思考回路 / Circuit Points']
        )
        : '<span aria-hidden="true">🧠</span>';
    echo '<li class="circuit-points">' . $circuitIcon
        . '<span class="circuit-label">思考回路:</span> '
        . '<span class="circuit-value">'
        . number_format($user->getCircuitPoints()) . ' / '
        . number_format($user->getMaxCircuitPoints())
        . '</span></li>';
    echo '</ul></nav>';
    echo '</header>';

    $vassalService = new VassalService();
    $vassalRelation = $vassalService->getActiveRelation(
        $user->getUserId()
    );
    if ($vassalRelation) {
        echo '<div class="message warning">';
        echo '当前附属于 ' . escapeHtml($vassalRelation['lord_name'])
            . '；领地与排行榜积分计入 '
            . escapeHtml($vassalRelation['overlord_name'])
            . ' 的势力。'
            . ' <a href="vassal.php">查看救出与主动脱离规则</a>';
        echo '</div>';
    }

    if (isSeasonGameplayFrozen()) {
        echo '<div class="message error">'
            . escapeHtml(getSeasonGameplayFreezeMessage())
            . ' 武将招募、成长与技能管理仍可使用。'
            . ' / General recruitment, progression, and skill management remain available.'
            . '</div>';
    }
}

/**
 * 渲染六系资源栏 / Render the six-resource bar
 * @param Resource $resource 资源对象 / Resource object
 * @return void
 */
function renderGameplayResourceBar($resource) {
    $resources = [
        [
            'bright-crystal',
            '亮晶晶',
            $resource->getBrightCrystal(),
            'resource_bright_crystal',
            '💎'
        ],
        [
            'warm-crystal',
            '暖洋洋',
            $resource->getWarmCrystal(),
            'resource_warm_crystal',
            '🔥'
        ],
        [
            'cold-crystal',
            '冷冰冰',
            $resource->getColdCrystal(),
            'resource_cold_crystal',
            '❄️'
        ],
        [
            'green-crystal',
            '郁萌萌',
            $resource->getGreenCrystal(),
            'resource_green_crystal',
            '🌿'
        ],
        [
            'day-crystal',
            '昼闪闪',
            $resource->getDayCrystal(),
            'resource_day_crystal',
            '☀️'
        ],
        [
            'night-crystal',
            '夜静静',
            $resource->getNightCrystal(),
            'resource_night_crystal',
            '🌙'
        ]
    ];

    echo '<div class="resource-bar">';
    foreach ($resources as $entry) {
        $icon = function_exists('renderImageResource')
            ? renderImageResource(
                $entry[3],
                32,
                ['alt' => $entry[1]]
            )
            : '<span aria-hidden="true">' . $entry[4] . '</span>';
        echo '<div class="resource ' . escapeHtml($entry[0]) . '">';
        echo '<span class="resource-name">' . $icon
            . '<span>' . escapeHtml($entry[1]) . '</span></span>';
        echo '<span class="resource-value">' . number_format((int) $entry[2]) . '</span>';
        echo '</div>';
    }
    echo '</div>';
}

/**
 * 渲染操作反馈 / Render an operation result
 * @param array|null $result 操作结果 / Operation result
 * @return void
 */
function renderGameplayNotice($result) {
    if (!$result || !is_array($result)) {
        return;
    }

    $class = !empty($result['success']) ? 'success' : 'error';
    $message = isset($result['message']) ? $result['message'] : '操作完成';
    echo '<div class="message ' . $class . '">' . escapeHtml($message) . '</div>';
}

/**
 * 将奖励或成本包格式化为中文摘要 / Format a reward or cost bundle as a Chinese summary
 * @param array $bundle 奖励或成本包 / Reward or cost bundle
 * @return string 摘要 / Summary
 */
function formatGameplayBundle($bundle) {
    if (!is_array($bundle) || empty($bundle)) {
        return '无';
    }

    $labels = [
        'bright' => '亮晶晶',
        'warm' => '暖洋洋',
        'cold' => '冷冰冰',
        'green' => '郁萌萌',
        'day' => '昼闪闪',
        'night' => '夜静静',
        'bright_crystal' => '亮晶晶',
        'warm_crystal' => '暖洋洋',
        'cold_crystal' => '冷冰冰',
        'green_crystal' => '郁萌萌',
        'day_crystal' => '昼闪闪',
        'night_crystal' => '夜静静',
        'circuit_points' => '思考回路',
        'skill_points' => '技能点',
        'merit_points' => '功勋',
        'arena_tokens' => '竞技代币',
        'break_core' => '蜕变核心',
        'resources' => '资源',
        'wallet' => '代币',
        'items' => '道具'
    ];
    $parts = [];
    foreach ($bundle as $key => $value) {
        $label = isset($labels[$key]) ? $labels[$key] : $key;
        if (is_array($value)) {
            $parts[] = $label . '（' . formatGameplayBundle($value) . '）';
        } else {
            $parts[] = $label . ' × ' . number_format((int) $value);
        }
    }

    return implode('、', $parts);
}

/**
 * 格式化公开卡池概率，最多保留六位小数 / Format a published pool probability with up to six decimals
 * @param mixed $probability 百分比数值 / Percentage value
 * @return string 可读百分比 / Readable percentage
 */
function formatGameplayPoolProbability($probability) {
    $value = is_numeric($probability) && is_finite((float) $probability)
        ? max(0.0, min(100.0, (float) $probability))
        : 0.0;
    $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

    return ($formatted === '' ? '0' : $formatted) . '%';
}

/**
 * 格式化开放卡池排期 / Format an open pool schedule
 * @param array $pool 卡池数据 / Pool data
 * @return string 排期说明 / Schedule description
 */
function formatGameplayPoolSchedule(array $pool) {
    $startsAt = isset($pool['starts_at']) && is_scalar($pool['starts_at'])
        ? trim((string) $pool['starts_at'])
        : '';
    $endsAt = isset($pool['ends_at']) && is_scalar($pool['ends_at'])
        ? trim((string) $pool['ends_at'])
        : '';

    if ($startsAt === '' && $endsAt === '') {
        return '常驻开放 / Permanently available';
    }
    if ($startsAt !== '' && $endsAt !== '') {
        return '开放期间：' . $startsAt . ' 至 ' . $endsAt
            . ' / Available from ' . $startsAt . ' to ' . $endsAt;
    }
    if ($startsAt !== '') {
        return '自 ' . $startsAt . ' 起开放 / Available from ' . $startsAt;
    }

    return '开放至 ' . $endsAt . ' / Available until ' . $endsAt;
}

/**
 * 计算页面展示用的多抽总成本 / Calculate a multi-draw total for display
 * @param array $unitCost 每抽成本 / Per-draw cost
 * @param int $count 抽取次数 / Draw count
 * @return array 总成本 / Total cost
 */
function multiplyGameplayPoolCost(array $unitCost, $count) {
    $normalizedCount = max(1, (int) $count);
    $total = [];

    foreach ($unitCost as $key => $amount) {
        if (!is_numeric($amount) || (int) $amount <= 0) {
            continue;
        }
        $total[(string) $key] = (int) $amount * $normalizedCount;
    }

    return $total;
}

/**
 * 渲染扩展玩法页脚 / Render the expansion footer
 * @return void
 */
function renderGameplayFooter() {
    echo '<footer><p>&copy; ' . date('Y') . ' ' . escapeHtml(SITE_NAME)
        . ' - 版本 ' . escapeHtml(GAME_VERSION) . '</p></footer>';
}
