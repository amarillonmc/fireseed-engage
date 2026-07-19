<?php
// 种火集结号 - 原作技能分类覆盖测试 / Fireseed Engage - Source-skill classification coverage tests

$assertions = 0;

/**
 * 断言分类资料满足不变量 / Asserts a classification-data invariant
 *
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertMeSkillClassification($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $root = dirname(__DIR__);
    $source = file_get_contents($root . '/doc/me_skills.md');
    $classification = file_get_contents(
        $root . '/doc/me_skill_mechanism_classification.md'
    );
    $matrix = file_get_contents(
        $root . '/doc/me_skill_per_skill_mechanism_matrix.md'
    );
    assertMeSkillClassification(
        is_string($source) && is_string($classification) && is_string($matrix),
        '技能来源、分类与逐技能矩阵必须可读 / Source, classification, and per-skill matrix documents must be readable'
    );

    preg_match_all('/^## (.+)\r?$/mu', $source, $sourceMatches);
    $sourceNames = array_map(
        function($name) {
            return rtrim($name, "\r");
        },
        $sourceMatches[1]
    );
    assertMeSkillClassification(
        count($sourceNames) === 470,
        '来源必须恰含470个技能 / Source must contain exactly 470 skills'
    );
    assertMeSkillClassification(
        count(array_unique($sourceNames)) === 470,
        '来源技能名必须唯一 / Source skill names must be unique'
    );

    $coverageMatched = preg_match(
        '/<!-- ME_SKILL_COVERAGE_BEGIN -->(.*?)<!-- ME_SKILL_COVERAGE_END -->/su',
        $classification,
        $coverageBlock
    );
    assertMeSkillClassification(
        $coverageMatched === 1,
        '分类文档必须含机器可读覆盖块 / Classification must contain a machine-readable coverage block'
    );
    preg_match_all(
        '/^(\d{3})\|([A-Z]{1,2})\|(.+)\r?$/mu',
        $coverageBlock[1],
        $coverageRows,
        PREG_SET_ORDER
    );
    assertMeSkillClassification(
        count($coverageRows) === 470,
        '覆盖块必须恰含470行 / Coverage block must contain exactly 470 rows'
    );

    $coverageNames = [];
    $categoryCounts = [];
    foreach ($coverageRows as $index => $row) {
        assertMeSkillClassification(
            (int) $row[1] === $index + 1,
            '覆盖顺序号必须连续 / Coverage ordinals must be contiguous'
        );
        $category = $row[2];
        $coverageNames[] = rtrim($row[3], "\r");
        $categoryCounts[$category] =
            isset($categoryCounts[$category])
                ? $categoryCounts[$category] + 1
                : 1;
    }
    assertMeSkillClassification(
        $coverageNames === $sourceNames,
        '覆盖技能必须按来源顺序逐条一致 / Coverage skills must match source order exactly'
    );
    assertMeSkillClassification(
        count(array_unique($coverageNames)) === 470,
        '覆盖技能不得重复 / Coverage skills must not be duplicated'
    );

    $expectedCounts = [
        'U' => 1,
        'TR' => 6,
        'HT' => 12,
        'RC' => 53,
        'TC' => 22,
        'IR' => 15,
        'BI' => 7,
        'UT' => 5,
        'PP' => 16,
        'SK' => 8,
        'RF' => 8,
        'SG' => 23,
        'CR' => 50,
        'BA' => 39,
        'DF' => 22,
        'CB' => 183
    ];
    ksort($categoryCounts);
    ksort($expectedCounts);
    assertMeSkillClassification(
        $categoryCounts === $expectedCounts,
        '主分类计数必须与分类表一致 / Primary-category counts must match the classification table'
    );

    $matrixMatched = preg_match(
        '/<!-- ME_SKILL_MATRIX_BEGIN -->(.*?)<!-- ME_SKILL_MATRIX_END -->/su',
        $matrix,
        $matrixBlock
    );
    assertMeSkillClassification(
        $matrixMatched === 1,
        '逐技能矩阵必须含机器可读数据块 / Per-skill matrix must contain a machine-readable data block'
    );
    preg_match_all(
        '/^(\d{3})\|([^|\r\n]+)\|([A-Z]{1,2})\|([^|\r\n]+)\|([^|\r\n]+)\|([^|\r\n]+)\|([^|\r\n]+)\|([^|\r\n]+)\|([^|\r\n]+)\r?$/mu',
        $matrixBlock[1],
        $matrixRows,
        PREG_SET_ORDER
    );
    assertMeSkillClassification(
        count($matrixRows) === 470,
        '逐技能矩阵必须恰含470行九列数据 / Per-skill matrix must contain exactly 470 nine-column rows'
    );

    preg_match_all(
        '/^## ([^\r\n]+)\r?\n(.*?)(?=^---\r?$|\z)/msu',
        $source,
        $sourceBlocks,
        PREG_SET_ORDER
    );
    assertMeSkillClassification(
        count($sourceBlocks) === 470,
        '来源技能块必须可逐条解析 / Every source skill block must be parseable'
    );
    $sourceCooldowns = [];
    foreach ($sourceBlocks as $sourceBlock) {
        $sourceName = rtrim($sourceBlock[1], "\r");
        $sourceCooldown = '';
        if (preg_match(
            '/^\| 1 \| (?<row>.*?)(?=^\| 2 \|)/msu',
            $sourceBlock[2],
            $levelOneRow
        )) {
            preg_match_all(
                '/\|\s*([^|\r\n]*)\|\s*$/mu',
                $levelOneRow['row'],
                $levelOneCells
            );
            if (!empty($levelOneCells[1])) {
                $sourceCooldown = trim(
                    $levelOneCells[1][count($levelOneCells[1]) - 1]
                );
            }
        }
        $sourceCooldowns[$sourceName] = $sourceCooldown;
    }

    $matrixNames = [];
    $matrixByName = [];
    $seenStatuses = [];
    $seenLifecycles = [];
    foreach ($matrixRows as $index => $row) {
        $ordinal = (int) $row[1];
        $name = rtrim($row[2], "\r");
        $category = $row[3];
        $template = $row[4];
        $mechanisms = $row[5];
        $lifecycle = $row[6];
        $dependency = $row[7];
        $domain = $row[8];
        $status = $row[9];

        assertMeSkillClassification(
            $ordinal === $index + 1,
            '逐技能矩阵序号必须连续 / Per-skill matrix ordinals must be contiguous'
        );
        assertMeSkillClassification(
            $name === $sourceNames[$index],
            '逐技能矩阵必须与来源顺序和名称一致 / Matrix order and names must match the source'
        );
        assertMeSkillClassification(
            $category === $coverageRows[$index][2],
            '逐技能矩阵必须保留既有主分类 / Matrix must preserve existing primary categories'
        );
        assertMeSkillClassification(
            preg_match('/^T-[A-Z0-9-]+$/', $template) === 1,
            '每个技能必须引用规范模板 / Every skill must reference a normalized template'
        );
        assertMeSkillClassification(
            preg_match('/^[a-z][a-z0-9_]*(?:\(|;|$)/', $mechanisms) === 1,
            '每个技能必须含规范机制标签 / Every skill must contain normalized mechanism tags'
        );
        assertMeSkillClassification(
            preg_match(
                '/^(continuous|active\?|timed\?|instant|event|active\?\+event|timed\?\+event|unknown):/',
                $lifecycle,
                $lifecycleMatch
            ) === 1,
            '每个技能必须含明确生命周期/CT特征 / Every skill must have an explicit lifecycle/CT trait'
        );
        assertMeSkillClassification(
            preg_match('/duration\s*[=:]\s*\d/i', $lifecycle) !== 1,
            'CT不得被记录为持续时间 / CT must never be recorded as duration'
        );
        assertMeSkillClassification(
            preg_match(
                '/(?:^|;)(fixed|COST|intelligence|COST\+intelligence|unknown|source-stat)(?:;|$)/',
                $dependency
            ) === 1,
            '每个技能必须记录数值依赖 / Every skill must record its value dependency'
        );
        assertMeSkillClassification(
            $domain !== '',
            '每个技能必须记录用途域 / Every skill must record its use domain'
        );
        assertMeSkillClassification(
            preg_match(
                '/^(implemented|adapted|placeholder|mixed):/',
                $status,
                $statusMatch
            ) === 1,
            '每个技能必须含受控迁移状态与原因 / Every skill must contain a controlled migration status and reason'
        );

        $sourceCooldown = $sourceCooldowns[$name];
        assertMeSkillClassification(
            $sourceCooldown === ''
                ? $lifecycle === 'unknown:-'
                : strpos($lifecycle, $sourceCooldown) !== false,
            '矩阵必须保留来源Lv1 CT且不得猜测空值 / Matrix must preserve source Lv1 CT without guessing blanks'
        );

        $hasSourceCharacterScope =
            preg_match('/,(character|automata)\)/', $mechanisms) === 1;
        if ($hasSourceCharacterScope) {
            assertMeSkillClassification(
                strpos($mechanisms, 'character_automata_scope') !== false
                    || strpos(
                        $mechanisms,
                        'character_automata_split_modifier'
                    ) !== false,
                '角色/兵士非等效作用域必须显式占位 / Non-equivalent character/automata scopes must be explicit placeholders'
            );
            assertMeSkillClassification(
                preg_match('/^(implemented|adapted):/', $status) !== 1,
                '角色/兵士非等效作用域不得标为已实现或适配 / Non-equivalent character/automata scopes cannot be implemented or adapted'
            );
        }

        $matrixNames[] = $name;
        $matrixByName[$name] = [
            'template' => $template,
            'mechanisms' => $mechanisms,
            'lifecycle' => $lifecycle,
            'dependency' => $dependency,
            'domain' => $domain,
            'status' => $status
        ];
        $seenStatuses[$statusMatch[1]] = true;
        $seenLifecycles[$lifecycleMatch[1]] = true;
    }
    assertMeSkillClassification(
        $matrixNames === $sourceNames
            && count(array_unique($matrixNames)) === 470,
        '逐技能矩阵必须470/470精确覆盖且不重复 / Matrix must cover 470/470 source skills exactly without duplicates'
    );
    foreach (
        ['implemented', 'adapted', 'placeholder', 'mixed']
        as $requiredStatus
    ) {
        assertMeSkillClassification(
            isset($seenStatuses[$requiredStatus]),
            '矩阵必须覆盖全部迁移状态 / Matrix must exercise every migration status'
        );
    }
    foreach (
        ['continuous', 'active?', 'timed?', 'instant', 'event']
        as $requiredLifecycle
    ) {
        assertMeSkillClassification(
            isset($seenLifecycles[$requiredLifecycle]),
            '矩阵必须覆盖主要生命周期 / Matrix must exercise major lifecycle traits'
        );
    }

    $placeholderTags = [
        'resource_collection_percent',
        'treasure_find_chance',
        'treasure_empty_rate_reduction',
        'territory_popularity_damage',
        'territory_popularity_restore',
        'tension_change',
        'resource_conversion_rate',
        'battle_reward_resource_percent',
        'territory_development_speed',
        'reinforcement_only_modifier',
        'skirmish_only_modifier',
        'unit_transfer_on_reinforcement',
        'adjacent_allied_territory_scaling',
        'gender_roster_scaling',
        'defender_general_damage',
        'hp_cost_on_attack',
        'heal_on_battle_success',
        'base_damage_reduction',
        'waiting_roster_heal',
        'advanced_unit_scope'
    ];
    foreach ($placeholderTags as $placeholderTag) {
        assertMeSkillClassification(
            strpos($matrixBlock[1], $placeholderTag) !== false,
            '矩阵必须保留全部已记录占位边界 / Matrix must retain every documented placeholder boundary'
        );
    }

    assertMeSkillClassification(
        $matrixByName['神聖乙女']['template'] === 'T-ATKSPD-ALL'
            && $matrixByName['神聖乙女']['template']
                === $matrixByName['美少女賛歌']['template']
            && $matrixByName['神聖乙女']['mechanisms']
                === $matrixByName['美少女賛歌']['mechanisms'],
        '神聖乙女与美少女賛歌必须共享机制模板 / 神聖乙女 and 美少女賛歌 must share one mechanism template'
    );
    assertMeSkillClassification(
        strpos(
            $matrix,
            '[34,38,42,46,52,58,64,75,87.5,105]%'
        ) !== false
            && strpos(
                $matrix,
                '[23,26,29,32,37,42,47,53,62,75]%'
            ) !== false
            && strpos(
                $matrix,
                '[44,52,60,70,80,92,104,118,136,160]%'
            ) !== false
            && strpos(
                $matrix,
                '[30,34,38,44,50,58,66,76,90,110]%'
            ) !== false,
        '命名对比必须记录两技能完整攻击与速度曲线 / Named comparison must record both complete attack and speed curves'
    );
    assertMeSkillClassification(
        strpos(
            $matrix,
            '[40:00,39:00,38:00,36:30,35:00,33:30,31:30,29:30,27:00,24:00]'
        ) !== false
            && strpos(
                $matrix,
                '[60:00,59:00,58:00,56:50,55:40,54:20,53:00,51:30,50:00,48:00]'
            ) !== false,
        '命名对比必须记录两技能完整CT曲线 / Named comparison must record both complete CT curves'
    );

    foreach (
        [
            ['2.14の告白', '万死の境界線', '月詠ノ花嫁'],
            ['アマテラス', '三千世界'],
            ['はーとふるボム', '赤皇の特権']
        ]
        as $aliasGroup
    ) {
        $firstAlias = array_shift($aliasGroup);
        foreach ($aliasGroup as $alias) {
            assertMeSkillClassification(
                $matrixByName[$firstAlias]['template']
                    === $matrixByName[$alias]['template']
                    && $matrixByName[$firstAlias]['mechanisms']
                        === $matrixByName[$alias]['mechanisms'],
                '来源别名必须共享模板和机制组合 / Source aliases must share templates and mechanism combinations'
            );
        }
    }
    assertMeSkillClassification(
        strpos(
            $matrixByName['ダッシュ！']['status'],
            'implemented:'
        ) !== 0
            && strpos(
                $matrixByName['ダッシュ！']['mechanisms'],
                'character_automata_scope'
            ) !== false,
        'ダッシュ！的角色专属范围必须保持占位 / ダッシュ！ character-only scope must remain explicit'
    );

    echo 'ME skill classification tests passed: '
        . $assertions . ' assertions.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
