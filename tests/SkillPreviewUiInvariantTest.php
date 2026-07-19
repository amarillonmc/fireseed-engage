<?php
// 种火集结号 - 技能修正预览界面不变量测试 / Fireseed Engage - skill-adjusted preview UI invariant tests

$assertions = 0;

/**
 * 断言技能修正预览源码不变量 / Assert a skill-adjusted preview source invariant
 *
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertSkillPreviewUiInvariant($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

/**
 * 截取两个标记之间的源码 / Extract source between two markers
 *
 * @param string $source 完整源码 / Full source
 * @param string $startMarker 起始标记 / Start marker
 * @param string $endMarker 结束标记 / End marker
 * @return string 截取源码 / Extracted source
 */
function extractSkillPreviewUiSection(
    $source,
    $startMarker,
    $endMarker
) {
    $start = strpos($source, $startMarker);
    if ($start === false) {
        return '';
    }
    $end = strpos($source, $endMarker, $start + strlen($startMarker));
    if ($end === false) {
        return substr($source, $start);
    }
    return substr($source, $start, $end - $start);
}

$root = dirname(__DIR__);
$buildPage = file_get_contents($root . '/build.php');
$barracksPage = file_get_contents($root . '/barracks.php');
$mainScript = file_get_contents($root . '/assets/js/script.js');

assertSkillPreviewUiInvariant(
    substr_count($buildPage, 'getAdjustedCityActionDuration(') === 2
        && strpos(
            $buildPage,
            '$constructionSeconds =' . "\n"
                . '                    $city->getAdjustedCityActionDuration('
        ) !== false,
    'Construction must preview the adjusted duration and recompute it authoritatively under the city lock'
);
assertSkillPreviewUiInvariant(
    strpos($buildPage, '（实际 <?php echo formatTime(') !== false
        && substr_count($buildPage, '基础 <?php echo formatTime(') >= 2,
    'Adjusted construction durations must clearly retain their base-duration comparison'
);
assertSkillPreviewUiInvariant(
    strpos(
        $barracksPage,
        "getAssignedGeneralCityBonuses([\n    'phase' => 'training'"
    ) !== false
        && strpos(
            $barracksPage,
            'Soldier::getAdjustedTrainingCost('
        ) !== false,
    'The server-rendered barracks must use the same assigned-city training cost calculation as deduction'
);
assertSkillPreviewUiInvariant(
    strpos($barracksPage, '<th>每名训练费用</th>') !== false
        && substr_count($barracksPage, 'data-training-cost=') === 2,
    'Every server-rendered training row must display and carry its per-soldier cost'
);
assertSkillPreviewUiInvariant(
    strpos($barracksPage, 'JSON_HEX_TAG') !== false
        && strpos($barracksPage, 'JSON_HEX_AMP') !== false
        && strpos($barracksPage, 'JSON_HEX_APOS') !== false
        && strpos($barracksPage, 'JSON_HEX_QUOT') !== false
        && strpos(
            $barracksPage,
            'escapeHtml($encodedTrainingCosts[$type])'
        ) !== false,
    'Training-cost data attributes must be encoded for an HTML attribute context'
);
assertSkillPreviewUiInvariant(
    strpos($mainScript, 'function normalizeTrainingCost(') !== false
        && strpos($mainScript, 'Array.isArray(rawCost)') !== false
        && strpos($mainScript, 'rawKeys.some(resourceType =>') !== false
        && strpos($mainScript, 'Number.isSafeInteger(amount)') !== false
        && strpos($mainScript, 'amount >= 0') !== false
        && strpos($mainScript, 'return malformed ? {} : normalized;')
            !== false,
    'Client cost normalization must fail closed for malformed shapes and amounts'
);
assertSkillPreviewUiInvariant(
    strpos($mainScript, 'function parseTrainingCostData(') !== false
        && strpos($mainScript, 'JSON.parse(rawJson)') !== false
        && strpos($mainScript, '} catch (error) {') !== false,
    'Malformed server-rendered cost JSON must be caught without breaking the page'
);
assertSkillPreviewUiInvariant(
    strpos($mainScript, 'soldier.training_cost') !== false
        && strpos($mainScript, 'formatTrainingCost(trainingCost, 1)')
            !== false
        && strpos($mainScript, 'showTrainingDialog(type, trainingCost)')
            !== false,
    'Dynamically refreshed rows must render and pass through API training costs'
);
assertSkillPreviewUiInvariant(
    strpos($mainScript, '每名费用：${formatTrainingCost(') !== false
        && strpos($mainScript, '总费用：${formatTrainingCost(') !== false
        && strpos(
            $mainScript,
            "quantityInput.addEventListener('input', updateTotalCost)"
        ) !== false,
    'The dialog must show per-soldier cost and update total cost when quantity changes'
);

$barracksRefreshSection = extractSkillPreviewUiSection(
    $mainScript,
    'function updateBarracksView(',
    'function showNotification('
);
$trainingDialogSection = extractSkillPreviewUiSection(
    $mainScript,
    'function showTrainingDialog(',
    'function trainSoldiers('
);
assertSkillPreviewUiInvariant(
    strpos($barracksRefreshSection, 'replaceChildren()') !== false
        && strpos($barracksRefreshSection, '.innerHTML') === false
        && strpos($trainingDialogSection, '.innerHTML') === false
        && strpos($trainingDialogSection, 'eval(') === false
        && strpos($trainingDialogSection, 'new Function') === false,
    'Barracks refresh and training dialog rendering must use safe DOM APIs'
);

echo "Skill preview UI invariant tests passed ({$assertions} assertions).\n";
