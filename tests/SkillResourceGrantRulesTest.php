<?php
// 种火集结号 - 技能资源即时动作规则测试 / Fireseed Engage - Skill resource instant-action rule tests

require_once dirname(__DIR__) . '/includes/classes/SkillCardService.php';

$assertions = 0;

/**
 * 断言技能资源规则 / Asserts a skill-resource rule
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertSkillResourceGrantRule($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

assertSkillResourceGrantRule(
    SkillCardService::calculateSaturatedResourceGrant(
        70,
        50,
        100
    ) === 30,
    '技能资源入账必须受仓储剩余空间限制 / Skill resource credits must respect remaining storage'
);
assertSkillResourceGrantRule(
    SkillCardService::calculateSaturatedResourceGrant(
        100,
        50,
        100
    ) === 0,
    '仓储已满时不得继续入账 / Full storage must receive no further credit'
);
assertSkillResourceGrantRule(
    SkillCardService::calculateSaturatedResourceGrant(
        120,
        50,
        100
    ) === 0,
    '既有超额余额不得被技能发放动作继续增加 / Existing over-cap balances must not grow from skill grants'
);
assertSkillResourceGrantRule(
    SkillCardService::calculateSaturatedResourceGrant(
        2147483645,
        100,
        9999999999
    ) === 2,
    '入账必须受数据库有符号INT上限限制 / Credits must respect the signed database INT ceiling'
);
assertSkillResourceGrantRule(
    SkillCardService::calculateSaturatedResourceGrant(
        10,
        9999999999,
        40
    ) === 30,
    '超大请求只能取得权威剩余容量 / Oversized requests may receive only authoritative remaining capacity'
);
assertSkillResourceGrantRule(
    SkillCardService::calculateSaturatedResourceGrant(
        10,
        -20,
        100
    ) === 0,
    '负数请求不得扣除资源 / Negative requests must not deduct resources'
);
assertSkillResourceGrantRule(
    SkillCardService::calculateSaturatedResourceGrant(
        10.9,
        20.9,
        100.9
    ) === 20,
    '资源入账计算必须使用确定的整数单位 / Resource grant calculations must use deterministic integer units'
);
assertSkillResourceGrantRule(
    SkillCardService::calculateSaturatedResourceGrant(
        10,
        INF,
        100
    ) === 0,
    '非有限请求必须安全失败 / Non-finite requests must fail closed'
);

$root = dirname(__DIR__);
$source = file_get_contents(
    $root . '/includes/classes/SkillCardService.php'
);

assertSkillResourceGrantRule(
    strpos(
        $source,
        'Resource::getUserResourceStorageCapacity($userId)'
    ) !== false,
    '技能发资源必须使用权威仓储容量 / Skill resource grants must use authoritative storage capacity'
);
assertSkillResourceGrantRule(
    strpos(
        $source,
        'SELECT bright_crystal, warm_crystal, cold_crystal,'
    ) !== false
        && strpos($source, 'FOR UPDATE";') !== false,
    '技能发资源必须锁定并读取真实余额 / Skill resource grants must lock and read authoritative balances'
);
assertSkillResourceGrantRule(
    substr_count($source, 'LEAST(2147483647,') >= 7,
    '每种资源更新都必须防止数据库整数上溢 / Every resource update must prevent database integer overflow'
);
assertSkillResourceGrantRule(
    strpos($source, "'credited_resources' => \$actualCredits") !== false
        && strpos(
            $source,
            "\$applied['all_resources'] = \$actualCredits;"
        ) !== false,
    '结构化及旧版动作都必须回报实际入账 / Structured and legacy actions must both report actual credits'
);
assertSkillResourceGrantRule(
    strpos($source, "\$columns = [\n            'bright'") !== false
        && strpos(
            $source,
            'SET {$column} = LEAST(2147483647, {$column} + ?)'
        ) !== false
        && strpos($source, "\$stmt->bind_param('ii', \$actual, \$userId);")
            !== false,
    '单资源动作必须只用固定列白名单且数值预处理 / Single-resource actions must use a fixed column allowlist and prepared values'
);
assertSkillResourceGrantRule(
    strpos($source, '$this->beginTransaction();') !== false
        && strpos($source, '$this->commitTransaction();') !== false
        && strpos($source, '$this->db->rollback();') !== false,
    '技能即时动作必须保留事务边界 / Skill instant actions must retain transaction boundaries'
);

echo "Skill resource grant tests passed: {$assertions} assertions.\n";
