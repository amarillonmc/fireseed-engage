<?php
// 种火集结号 - 资源生产快照与防追溯规则测试 / Fireseed Engage - production snapshot and anti-retroactivity rule tests

require_once __DIR__ . '/../includes/classes/Resource.php';

$assertions = 0;

/**
 * 断言严格相同 / Asserts strict equality
 *
 * @param mixed $expected 期望值 / Expected value
 * @param mixed $actual 实际值 / Actual value
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertProductionSnapshotSame($expected, $actual, $message) {
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(
            $message
            . "\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true)
        );
    }
}

/**
 * 构建六色生产流快照 / Builds a six-resource production-stream snapshot
 *
 * @param array $overrides 要覆盖的资源流 / Resource stream overrides
 * @return array 完整快照 / Complete snapshot
 */
function productionSnapshot(array $overrides = []) {
    $streams = [
        'bright' => [],
        'warm' => [],
        'cold' => [],
        'green' => [],
        'day' => [],
        'night' => []
    ];
    foreach ($overrides as $resourceType => $resourceStreams) {
        foreach ($resourceStreams as &$resourceStream) {
            if (!isset($resourceStream['base_per_tick'])) {
                $resourceStream['base_per_tick'] =
                    $resourceStream['per_tick'];
            }
        }
        unset($resourceStream);
        $streams[$resourceType] = $resourceStreams;
    }
    return [
        'schema_version' => 2,
        'interval_seconds' => 3,
        'streams' => $streams
    ];
}

try {
    $fractionalSnapshot = productionSnapshot([
        'bright' => [
            ['facility_id' => 20, 'per_tick' => 1.5],
            ['facility_id' => 10, 'per_tick' => 1.5]
        ]
    ]);
    assertProductionSnapshotSame(
        2,
        Resource::calculateProductionFromSnapshot(
            $fractionalSnapshot,
            3
        )['bright'],
        '每座设施必须独立向下取整，不能先聚合改变旧语义 / Each facility must floor independently instead of changing legacy semantics through pre-aggregation'
    );
    assertProductionSnapshotSame(
        6,
        Resource::calculateProductionFromSnapshot(
            $fractionalSnapshot,
            6
        )['bright'],
        '多个完整tick必须按持久化设施流结算 / Multiple complete ticks must settle from persisted facility streams'
    );
    assertProductionSnapshotSame(
        2,
        Resource::calculateProductionFromSnapshot(
            $fractionalSnapshot,
            5
        )['bright'],
        '不足一个tick的余数不得提前产出 / A sub-tick remainder must not produce early'
    );
    assertProductionSnapshotSame(
        2147483647,
        Resource::calculateProductionFromSnapshot(
            productionSnapshot([
                'night' => [
                    [
                        'facility_id' => 1,
                        'per_tick' => 2147483647
                    ],
                    [
                        'facility_id' => 2,
                        'per_tick' => 2147483647
                    ]
                ]
            ]),
            6
        )['night'],
        '快照产量必须饱和到数据库安全整数 / Snapshot production must saturate at the database-safe integer'
    );

    $invalidSnapshot = productionSnapshot();
    unset($invalidSnapshot['streams']['warm']);
    assertProductionSnapshotSame(
        [
            'bright' => 0,
            'warm' => 0,
            'cold' => 0,
            'green' => 0,
            'day' => 0,
            'night' => 0
        ],
        Resource::calculateProductionFromSnapshot(
            $invalidSnapshot,
            3600
        ),
        '损坏快照必须安全关闭而不是猜测产量 / A corrupt snapshot must fail closed instead of guessing production'
    );
    $duplicateFacilitySnapshot = productionSnapshot([
        'bright' => [
            ['facility_id' => 1, 'per_tick' => 1]
        ],
        'warm' => [
            ['facility_id' => 1, 'per_tick' => 1]
        ]
    ]);
    assertProductionSnapshotSame(
        0,
        Resource::calculateProductionFromSnapshot(
            $duplicateFacilitySnapshot,
            3
        )['bright'],
        '重复设施ID必须使损坏快照安全关闭 / Duplicate facility IDs must make a corrupt snapshot fail closed'
    );
    $legacyV1Snapshot = $fractionalSnapshot;
    $legacyV1Snapshot['schema_version'] = 1;
    assertProductionSnapshotSame(
        0,
        Resource::calculateProductionFromSnapshot(
            $legacyV1Snapshot,
            3600
        )['bright'],
        '不含基础率的v1快照必须安全失效并由运行路径建立v2基线，不得猜测追授 / A v1 snapshot without base rates must fail closed so the runtime establishes a v2 baseline without guessed backfill'
    );
    $forgedBaseSnapshot = productionSnapshot([
        'bright' => [
            [
                'facility_id' => 1,
                'per_tick' => 2,
                'base_per_tick' => 10
            ]
        ]
    ]);
    assertProductionSnapshotSame(
        0,
        Resource::calculateProductionFromSnapshot(
            $forgedBaseSnapshot,
            3
        )['bright'],
        '伪造为高于最终率的基础率必须安全关闭 / A forged base rate above the final rate must fail closed'
    );

    $oldRateSnapshot = productionSnapshot([
        'bright' => [
            ['facility_id' => 1, 'per_tick' => 2]
        ]
    ]);
    $newRateSnapshot = productionSnapshot([
        'bright' => [
            ['facility_id' => 1, 'per_tick' => 5]
        ]
    ]);
    $sameSnapshotBoundary = Resource::
        calculateProductionAcrossSnapshotBoundary(
            $fractionalSnapshot,
            $fractionalSnapshot,
            100,
            104,
            109
        );
    assertProductionSnapshotSame(
        [
            'production' => Resource::calculateProductionFromSnapshot(
                $fractionalSnapshot,
                9
            )['bright'],
            'previous_ticks' => 3,
            'current_ticks' => 0
        ],
        [
            'production' =>
                $sameSnapshotBoundary['production']['bright'],
            'previous_ticks' =>
                $sameSnapshotBoundary['previous_ticks'],
            'current_ticks' =>
                $sameSnapshotBoundary['current_ticks']
        ],
        '可证明仅一次且端点相同的无关变更不得分段吞掉产量 / One proven unrelated change with identical endpoints must not lose production through segmentation'
    );

    $changedBoundary = Resource::
        calculateProductionAcrossSnapshotBoundary(
            $oldRateSnapshot,
            $newRateSnapshot,
            100,
            104,
            109
        );
    assertProductionSnapshotSame(
        [
            'production' => 9,
            'settled_ticks' => 3,
            'previous_ticks' => 2,
            'current_ticks' => 1,
            'settled_seconds' => 9
        ],
        [
            'production' => $changedBoundary['production']['bright'],
            'settled_ticks' => $changedBoundary['settled_ticks'],
            'previous_ticks' => $changedBoundary['previous_ticks'],
            'current_ticks' => $changedBoundary['current_ticks'],
            'settled_seconds' => $changedBoundary['settled_seconds']
        ],
        'dirty前及跨越dirty的tick必须用旧率，下一完整tick才用新率且不得丢tick / Ticks before and crossing dirty must use the old rate, while the next complete tick uses the new rate without losing a tick'
    );

    $recentBoundary = Resource::
        calculateProductionAcrossSnapshotBoundary(
            $oldRateSnapshot,
            $newRateSnapshot,
            100,
            107,
            112
        );
    assertProductionSnapshotSame(
        [
            'production' => 11,
            'previous_ticks' => 3,
            'current_ticks' => 1
        ],
        [
            'production' => $recentBoundary['production']['bright'],
            'previous_ticks' => $recentBoundary['previous_ticks'],
            'current_ticks' => $recentBoundary['current_ticks']
        ],
        '换高后的新率只能从最近边界后的下一完整tick开始 / A raised rate may begin only with the next complete tick after the latest boundary'
    );

    $unexpectedChange = Resource::
        calculateProductionAcrossSnapshotBoundary(
            $oldRateSnapshot,
            $newRateSnapshot,
            100,
            110,
            110
        );
    assertProductionSnapshotSame(
        [
            'production' => 6,
            'previous_ticks' => 3,
            'current_ticks' => 0
        ],
        [
            'production' => $unexpectedChange['production']['bright'],
            'previous_ticks' => $unexpectedChange['previous_ticks'],
            'current_ticks' => $unexpectedChange['current_ticks']
        ],
        '无dirty的意外指纹变化必须让旧快照结算到当前完整tick / An unexpected fingerprint change without dirty must settle completed ticks with the old snapshot'
    );
    $pendingBoundaryTick = Resource::
        calculateProductionAcrossSnapshotBoundary(
            $oldRateSnapshot,
            $newRateSnapshot,
            100,
            104,
            105
        );
    assertProductionSnapshotSame(
        [
            'production' => 2,
            'previous_ticks' => 1,
            'current_ticks' => 0,
            'settled_seconds' => 3,
            'current_snapshot_starts_at' => 106
        ],
        [
            'production' =>
                $pendingBoundaryTick['production']['bright'],
            'previous_ticks' =>
                $pendingBoundaryTick['previous_ticks'],
            'current_ticks' =>
                $pendingBoundaryTick['current_ticks'],
            'settled_seconds' =>
                $pendingBoundaryTick['settled_seconds'],
            'current_snapshot_starts_at' =>
                $pendingBoundaryTick[
                    'current_snapshot_starts_at'
                ]
        ],
        '跨边界tick未结束时必须保留旧快照到其完整结束 / The old snapshot must remain authoritative until the crossing tick completes'
    );
    $saturatedBoundary = Resource::
        calculateProductionAcrossSnapshotBoundary(
            productionSnapshot([
                'night' => [
                    [
                        'facility_id' => 1,
                        'per_tick' => 2147483647
                    ]
                ]
            ]),
            productionSnapshot([
                'night' => [
                    [
                        'facility_id' => 1,
                        'per_tick' => 2147483647
                    ],
                    [
                        'facility_id' => 2,
                        'per_tick' => 1
                    ]
                ]
            ]),
            100,
            103,
            106
        );
    assertProductionSnapshotSame(
        2147483647,
        $saturatedBoundary['production']['night'],
        '旧新快照分段产量相加必须饱和到安全整数 / Old and current snapshot segments must combine with database-safe saturation'
    );

    $highRateSnapshot = productionSnapshot([
        'bright' => [
            [
                'facility_id' => 1,
                'per_tick' => 10,
                'base_per_tick' => 2
            ]
        ]
    ]);
    $lowRateSnapshot = productionSnapshot([
        'bright' => [
            [
                'facility_id' => 1,
                'per_tick' => 2,
                'base_per_tick' => 2
            ]
        ]
    ]);
    $highLowHigh = Resource::
        calculateProductionAcrossSnapshotChanges(
            $highRateSnapshot,
            $highRateSnapshot,
            100,
            104,
            119,
            2,
            124
        );
    assertProductionSnapshotSame(
        [
            'production' => 32,
            'previous_ticks' => 2,
            'conservative_ticks' => 6,
            'current_ticks' => 0
        ],
        [
            'production' => $highLowHigh['production']['bright'],
            'previous_ticks' => $highLowHigh['previous_ticks'],
            'conservative_ticks' =>
                $highLowHigh['conservative_ticks'],
            'current_ticks' => $highLowHigh['current_ticks']
        ],
        '高率降档很久后换回高率时，首次观测前的不确定区段只能保留设施基础产出 / A high-low-long-high toggle may retain only facility base production through the first observation'
    );
    assertProductionSnapshotSame(
        80,
        Resource::calculateProductionFromSnapshot(
            $highRateSnapshot,
            24
        )['bright'],
        'toggle-back回归测试必须证明旧漏洞会错误追授整段高率 / The toggle-back regression fixture must prove that the old shortcut would grant the high rate for the whole interval'
    );

    $lowHighLow = Resource::
        calculateProductionAcrossSnapshotChanges(
            $lowRateSnapshot,
            $lowRateSnapshot,
            100,
            104,
            119,
            2,
            124
        );
    assertProductionSnapshotSame(
        [
            'production' => 16,
            'conservative_ticks' => 6
        ],
        [
            'production' => $lowHighLow['production']['bright'],
            'conservative_ticks' =>
                $lowHighLow['conservative_ticks']
        ],
        '低率升高后再降回低率不得追授中途高率 / A low-high-low toggle must not backfill the temporary high rate'
    );

    $unrelatedMultipleChanges = Resource::
        calculateProductionAcrossSnapshotChanges(
            $highRateSnapshot,
            $highRateSnapshot,
            100,
            104,
            119,
            4,
            124
        );
    assertProductionSnapshotSame(
        [
            'production' => 32,
            'conservative_ticks' => 6
        ],
        [
            'production' =>
                $unrelatedMultipleChanges['production']['bright'],
            'conservative_ticks' =>
                $unrelatedMultipleChanges['conservative_ticks']
        ],
        '多次无关变更无法与toggle-back区分时必须保留共同基础产出但扣留未证明的技能加成 / Multiple unrelated changes indistinguishable from a toggle-back must preserve common base production while withholding unproven skill bonuses'
    );

    $oldStructureSnapshot = productionSnapshot([
        'bright' => [
            [
                'facility_id' => 1,
                'per_tick' => 4,
                'base_per_tick' => 2
            ],
            [
                'facility_id' => 2,
                'per_tick' => 3,
                'base_per_tick' => 3
            ],
            [
                'facility_id' => 4,
                'per_tick' => 1,
                'base_per_tick' => 1
            ]
        ]
    ]);
    $newStructureSnapshot = productionSnapshot([
        'bright' => [
            [
                'facility_id' => 1,
                'per_tick' => 8,
                'base_per_tick' => 4
            ],
            [
                'facility_id' => 3,
                'per_tick' => 7,
                'base_per_tick' => 7
            ],
            [
                'facility_id' => 4,
                'per_tick' => 1,
                'base_per_tick' => 1
            ]
        ]
    ]);
    $structuralChanges = Resource::
        calculateProductionAcrossSnapshotChanges(
            $oldStructureSnapshot,
            $newStructureSnapshot,
            100,
            104,
            119,
            3,
            124
        );
    assertProductionSnapshotSame(
        [
            'production' => 22,
            'previous_ticks' => 2,
            'conservative_ticks' => 6,
            'current_ticks' => 0
        ],
        [
            'production' =>
                $structuralChanges['production']['bright'],
            'previous_ticks' => $structuralChanges['previous_ticks'],
            'conservative_ticks' =>
                $structuralChanges['conservative_ticks'],
            'current_ticks' => $structuralChanges['current_ticks']
        ],
        '结构变化的不确定区段只能保留两端基础率完全相同的共同设施 / A structurally uncertain segment may retain only facilities with identical base rates at both endpoints'
    );

    $actualThenScheduled = Resource::
        calculateProductionAcrossSnapshotChanges(
            productionSnapshot([
                'bright' => [
                    [
                        'facility_id' => 4,
                        'per_tick' => 1,
                        'base_per_tick' => 1
                    ]
                ]
            ]),
            productionSnapshot([
                'bright' => [
                    [
                        'facility_id' => 4,
                        'per_tick' => 1,
                        'base_per_tick' => 1
                    ],
                    [
                        'facility_id' => 5,
                        'per_tick' => 10,
                        'base_per_tick' => 10
                    ]
                ]
            ]),
            100,
            110,
            120,
            2,
            130
        );
    assertProductionSnapshotSame(
        [
            'production' => 10,
            'previous_ticks' => 4,
            'conservative_ticks' => 6,
            'current_ticks' => 0
        ],
        [
            'production' =>
                $actualThenScheduled['production']['bright'],
            'previous_ticks' =>
                $actualThenScheduled['previous_ticks'],
            'conservative_ticks' =>
                $actualThenScheduled['conservative_ticks'],
            'current_ticks' =>
                $actualThenScheduled['current_ticks']
        ],
        '普通变化后才到期的设施计划边界必须合并为多次变化，不能从普通变化时刻提前追授设施 / A facility transition due after an ordinary change must merge into a multi-change window instead of backdating the facility to the ordinary change'
    );

    $fixedObservedWindow = Resource::
        calculateProductionAcrossSnapshotChanges(
            $highRateSnapshot,
            $highRateSnapshot,
            124,
            124,
            125,
            2,
            130,
            true
        );
    assertProductionSnapshotSame(
        [
            'production' => 12,
            'conservative_ticks' => 1,
            'current_ticks' => 1,
            'change_window_boundary_at' => 125
        ],
        [
            'production' =>
                $fixedObservedWindow['production']['bright'],
            'conservative_ticks' =>
                $fixedObservedWindow['conservative_ticks'],
            'current_ticks' =>
                $fixedObservedWindow['current_ticks'],
            'change_window_boundary_at' =>
                $fixedObservedWindow['change_window_boundary_at']
        ],
        '首次观测边界必须固定，跨界tick完成后当前快照才能用于后续tick / The first observation boundary must remain fixed so the current snapshot starts only after its crossing tick completes'
    );
    $changeAfterObservation = Resource::
        calculateProductionAcrossSnapshotChanges(
            $highRateSnapshot,
            $highRateSnapshot,
            124,
            124,
            128,
            3,
            130,
            false
        );
    assertProductionSnapshotSame(
        [
            'production' => 4,
            'conservative_ticks' => 2,
            'current_ticks' => 0,
            'change_window_boundary_at' => 130
        ],
        [
            'production' =>
                $changeAfterObservation['production']['bright'],
            'conservative_ticks' =>
                $changeAfterObservation['conservative_ticks'],
            'current_ticks' =>
                $changeAfterObservation['current_ticks'],
            'change_window_boundary_at' =>
                $changeAfterObservation[
                    'change_window_boundary_at'
                ]
        ],
        '固定窗口后若再发生变化，触发器必须使窗口失效并保守延伸到下一次观测 / A change after a fixed window must invalidate it and conservatively extend the window to the next observation'
    );

    $singleExactBoundary = Resource::
        calculateProductionAcrossSnapshotChanges(
            $oldRateSnapshot,
            $newRateSnapshot,
            100,
            104,
            104,
            1,
            109
        );
    assertProductionSnapshotSame(
        [
            'production' => 9,
            'previous_ticks' => 2,
            'conservative_ticks' => 0,
            'current_ticks' => 1
        ],
        [
            'production' =>
                $singleExactBoundary['production']['bright'],
            'previous_ticks' =>
                $singleExactBoundary['previous_ticks'],
            'conservative_ticks' =>
                $singleExactBoundary['conservative_ticks'],
            'current_ticks' =>
                $singleExactBoundary['current_ticks']
        ],
        '单次边界仍须按tick起点精确使用旧新快照 / A single boundary must still assign old and current snapshots exactly by tick start'
    );
    $futureScheduledBoundary = Resource::
        calculateProductionAcrossSnapshotChanges(
            $highRateSnapshot,
            $highRateSnapshot,
            100,
            null,
            130,
            0,
            124
        );
    assertProductionSnapshotSame(
        [
            'production' => 80,
            'previous_ticks' => 8,
            'conservative_ticks' => 0,
            'current_ticks' => 0
        ],
        [
            'production' =>
                $futureScheduledBoundary['production']['bright'],
            'previous_ticks' =>
                $futureScheduledBoundary['previous_ticks'],
            'conservative_ticks' =>
                $futureScheduledBoundary['conservative_ticks'],
            'current_ticks' =>
                $futureScheduledBoundary['current_ticks']
        ],
        '未来计划边界在到期前必须保持change_count为零且不切断当前生产 / A future scheduled boundary must keep a zero change count and must not interrupt current production before it is due'
    );

    $root = dirname(__DIR__);
    $resourceSource = file_get_contents(
        $root . '/includes/classes/Resource.php'
    );
    $migrationSql = file_get_contents(
        $root . '/sql/upgrade_20260718_skill_mechanisms.sql'
    );
    $seasonSource = file_get_contents(
        $root . '/includes/classes/SeasonService.php'
    );
    $installerSource = file_get_contents($root . '/install.php');
    assertProductionSnapshotSame(
        true,
        $resourceSource !== false
            && $migrationSql !== false
            && $seasonSource !== false
            && $installerSource !== false,
        '生产防追溯实现文件必须可读 / Production anti-retroactivity implementation files must be readable'
    );

    $addResourceStart = strpos(
        $resourceSource,
        'public function addResource('
    );
    $reduceResourceStart = strpos(
        $resourceSource,
        'public function reduceResource('
    );
    $hasEnoughStart = strpos(
        $resourceSource,
        'public function hasEnoughResource('
    );
    $ordinaryMutationSource = substr(
        $resourceSource,
        $addResourceStart,
        $hasEnoughStart - $addResourceStart
    );
    assertProductionSnapshotSame(
        true,
        $addResourceStart !== false
            && $reduceResourceStart !== false
            && $hasEnoughStart !== false
            && strpos(
                $ordinaryMutationSource,
                'resource_production_states'
            ) === false,
        '普通资源加减不得推进或弄脏独立生产游标 / Ordinary resource additions and spending must not advance or dirty the independent production cursor'
    );
    assertProductionSnapshotSame(
        true,
        strpos(
            $resourceSource,
            'FROM resource_production_states'
        ) !== false
            && strpos($resourceSource, 'settled_at') !== false
            && strpos($resourceSource, 'dirty_at') !== false
            && strpos(
                $resourceSource,
                'dirty_since_offset_seconds'
            ) !== false
            && strpos($resourceSource, 'change_count') !== false
            && strpos(
                $resourceSource,
                "strtotime((string) \$resourceRow['last_update'])"
            ) === false
            && strpos(
                $resourceSource,
                '$settledTicks = intdiv('
            ) !== false,
        '生产结算必须使用独立游标并只推进完整tick / Production settlement must use its independent cursor and advance complete ticks only'
    );
    assertProductionSnapshotSame(
        true,
        strpos(
            $resourceSource,
            'calculateProductionAcrossSnapshotChanges('
        ) !== false
            && strpos(
                $resourceSource,
                '$effectiveChangeCount'
            ) !== false
            && strpos(
                $resourceSource,
                '$nextSettledAt = $settledAt'
            ) !== false
            && strpos(
                $resourceSource,
                '$nextSettledAt = $now;'
            ) === false
            && strpos(
                $resourceSource,
                '$nextSettledAt >= (int) $settlement['
            ) !== false
            && strpos(
                $resourceSource,
                'Retain the old snapshot, first/latest boundaries, and change count'
            ) !== false,
        '状态边界必须分配完整tick且不得把生产游标直接跳到当前时刻 / A state boundary must allocate complete ticks without jumping the production cursor directly to now'
    );

    assertProductionSnapshotSame(
        true,
        strpos(
            $migrationSql,
            'CREATE TABLE IF NOT EXISTS `resource_production_states`'
        ) !== false
            && strpos(
                $migrationSql,
                '`snapshot_json` mediumtext DEFAULT NULL'
            ) !== false
            && strpos(
                $migrationSql,
                '`dirty_since_offset_seconds` int unsigned DEFAULT NULL'
            ) !== false
            && strpos(
                $migrationSql,
                '`change_count` int unsigned NOT NULL DEFAULT 0'
            ) !== false
            && strpos(
                $migrationSql,
                '`change_window_observed` tinyint(1) NOT NULL DEFAULT 0'
            ) !== false
            && strpos(
                $migrationSql,
                '`scheduled_offset_seconds` int unsigned DEFAULT NULL'
            ) !== false
            && strpos(
                $migrationSql,
                '`scheduled_change_count` int unsigned NOT NULL DEFAULT 0'
            ) !== false
            && substr_count(
                $migrationSql,
                'CREATE TRIGGER `fireseed_prod_'
            ) === 20
            && substr_count(
                $migrationSql,
                'DROP TRIGGER IF EXISTS `fireseed_prod_'
            ) === 20
            && strpos(
                $migrationSql,
                "`column_name` = 'dirty_since_offset_seconds'"
            ) !== false
            && strpos(
                $migrationSql,
                'ADD COLUMN `dirty_since_offset_seconds`'
            ) !== false
            && strpos(
                $migrationSql,
                "`column_name` = 'change_count'"
            ) !== false
            && strpos(
                $migrationSql,
                'ADD COLUMN `change_count`'
            ) !== false
            && strpos(
                $migrationSql,
                'ADD COLUMN `change_window_observed`'
            ) !== false
            && strpos(
                $migrationSql,
                'ADD COLUMN `scheduled_offset_seconds`'
            ) !== false
            && strpos(
                $migrationSql,
                'ADD COLUMN `scheduled_change_count`'
            ) !== false,
        '迁移必须可重跑地安装完整生产状态与触发器集合 / Migration must rerunnably install the complete production state and trigger set'
    );
    assertProductionSnapshotSame(
        true,
        strpos($migrationSql, 'fireseed_prod_facilities_bu') !== false
            && strpos($migrationSql, 'fireseed_prod_assignments_bu') !== false
            && strpos($migrationSql, 'fireseed_prod_generals_bu') !== false
            && strpos($migrationSql, 'OLD.`hp` <=> NEW.`hp`') !== false
            && strpos(
                $migrationSql,
                'OLD.`skill_level` <=> NEW.`skill_level`'
            ) !== false
            && strpos($migrationSql, 'fireseed_prod_mappings_bu') !== false
            && strpos($migrationSql, 'fireseed_prod_catalog_bu') !== false
            && strpos(
                $migrationSql,
                'OLD.`effect_json` <=> NEW.`effect_json`'
            ) !== false,
        '设施、分配、HP、技能等级、映射与共享目录修改都必须弄脏快照 / Facilities, assignments, HP, skill levels, mappings, and shared-catalog edits must all dirty the snapshot'
    );
    assertProductionSnapshotSame(
        true,
        substr_count(
            $migrationSql,
            '`dirty_since_offset_seconds` = IF('
        ) === 19
            && substr_count(
                $migrationSql,
                '`dirty_at` = VALUES(`dirty_at`),'
            ) === 19
            && substr_count(
                $migrationSql,
                '`change_count` = IF('
            ) === 19
            && substr_count(
                $migrationSql,
                '`change_window_observed` = 0,'
            ) === 19
            && substr_count(
                $migrationSql,
                'VALUES(`change_count`)'
            ) === 38
            && preg_match_all(
                '/SELECT(?: DISTINCT)? resource\.`user_id`, NOW\(\), 0, NOW\(\), 2, NULL/',
                $migrationSql
            ) === 6,
        '所有状态变更触发器必须维护首末边界并饱和累计变化次数 / Every state-change trigger must maintain first/latest boundaries and a saturating change count'
    );
    assertProductionSnapshotSame(
        true,
        substr_count(
            $migrationSql,
            '`change_window_observed` = 0,'
        ) === 19
            && strpos(
                $resourceSource,
                '$effectiveWindowObserved = false;'
            ) !== false
            && strpos(
                $resourceSource,
                '$nextChangeWindowObserved ='
            ) !== false,
        '任何后续实际或到期计划变化都必须使固定观察窗口失效 / Any later actual or due scheduled change must invalidate a fixed observation window'
    );
    assertProductionSnapshotSame(
        true,
        strpos(
            $migrationSql,
            'VALUES (NEW.`user_id`, NOW(), NULL, NULL, 0, 0, NULL, 0, NULL);'
        ) !== false
            && strpos(
                $resourceSource,
                '$scheduledAt !== null && $scheduledAt <= $now;'
            ) !== false
            && strpos(
                $resourceSource,
                'saturatingProductionChangeCountAdd('
            ) !== false
            && strpos(
                $resourceSource,
                "'scheduled_change_count'"
            ) !== false
            && strpos(
                $resourceSource,
                "'transition_count' => \$transitionCount"
            ) !== false
            && strpos(
                $migrationSql,
                '`scheduled_change_count` = 1,'
            ) !== false
            && strpos(
                $migrationSql,
                '`dirty_at` = NULL'
            ) !== false,
        '未来计划边界不得伪装成已经发生的状态变化 / A future scheduled boundary must not masquerade as an already-observed state change'
    );
    assertProductionSnapshotSame(
        true,
        strpos(
            $migrationSql,
            'LEAST(`dirty_at`, VALUES(`dirty_at`))'
        ) === false
            && strpos(
                $migrationSql,
                'uncertain remainder is conservatively discarded'
            ) === false,
        '迁移不得保留最早dirty或丢弃不确定区间语义 / Migration must not retain earliest-dirty or uncertain-interval discard semantics'
    );

    $triggerSectionEnd = strpos(
        $migrationSql,
        '-- 独立完成标记'
    );
    $triggerSection = substr(
        $migrationSql,
        0,
        $triggerSectionEnd
    );
    assertProductionSnapshotSame(
        true,
        stripos($triggerSection, 'DELIMITER') === false
            && preg_match('/CREATE\s+TRIGGER.*?\bBEGIN\b/is', $triggerSection)
                !== 1,
        '触发器必须保持单语句且不依赖客户端DELIMITER / Triggers must remain single-statement and independent of client DELIMITER handling'
    );
    assertProductionSnapshotSame(
        true,
        strpos(
            $installerSource,
            "'/\\ACREATE[ \\t\\r\\n]+TRIGGER\\b[\\s\\S]*\\z/i'"
        ) !== false
            && strpos(
                $installerSource,
                'DROP[ \\t]+TRIGGER(?:[ \\t]+IF[ \\t]+EXISTS)?'
            ) !== false
            && strpos(
                $installerSource,
                '$db->query($statement)'
            ) !== false,
        '安装器必须只将内置触发器DDL交给直接查询 / Installer must route bundled trigger DDL through direct queries'
    );
    assertProductionSnapshotSame(
        true,
        strpos(
            $seasonSource,
            'UPDATE resource_production_states'
        ) !== false
            && strpos(
                $seasonSource,
                'SET settled_at = DATE_ADD('
            ) !== false
            && strpos(
                $seasonSource,
                'dirty_at = CASE'
            ) !== false
            && strpos(
                $resourceSource,
                '$settledAt + $dirtySinceOffset'
            ) !== false
            && strpos(
                $seasonSource,
                'dirty_since_offset_seconds = DATE_ADD'
            ) === false
            && strpos(
                $seasonSource,
                'scheduled_offset_seconds = DATE_ADD'
            ) === false,
        '赛季冻结必须平移游标和末边界，而相对首边界偏移保持不变 / Season freeze must shift the cursor and latest boundary while leaving the relative first-boundary offset unchanged'
    );

    echo 'Resource production snapshot tests passed: '
        . $assertions . ' assertions.' . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
