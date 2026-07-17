<?php
// 种火集结号 - 无数据库依赖的核心游戏规则 / Fireseed Engage - Database-independent core game rules

/**
 * 集中管理可独立计算和测试的游戏规则 / Centralizes game rules that can be calculated and tested independently
 */
final class GameRules {
    private const GENERAL_RECRUITMENT_PROBABILITIES = [
        'normal' => [
            'B' => 70.0,
            'A' => 25.0,
            'S' => 5.0,
            'SS' => 0.0,
            'P' => 0.0
        ],
        'advanced' => [
            'B' => 0.0,
            'A' => 70.0,
            'S' => 25.0,
            'SS' => 5.0,
            'P' => 0.0
        ],
        'resonance' => [
            'B' => 0.0,
            'A' => 0.0,
            'S' => 50.0,
            'SS' => 35.0,
            'P' => 15.0
        ]
    ];

    private const SKILL_CARD_PROBABILITIES = [
        'B' => 55.0,
        'A' => 30.0,
        'S' => 10.0,
        'SS' => 4.0,
        'P' => 1.0
    ];

    private const UNIT_TYPES = [
        'pawn',
        'knight',
        'rook',
        'bishop',
        'golem',
        'scout'
    ];

    private const UNIT_COUNTERS = [
        'pawn' => ['golem'],
        'knight' => ['pawn', 'rook'],
        'rook' => ['pawn', 'bishop'],
        'bishop' => ['pawn', 'knight'],
        'golem' => [],
        'scout' => []
    ];

    private const COUNTER_MULTIPLIER = 1.5;

    private const BREAK_RULES = [
        'B' => [
            'level_cap' => 40,
            'break_material' => 1,
            'bright_crystal' => 1000
        ],
        'A' => [
            'level_cap' => 50,
            'break_material' => 2,
            'bright_crystal' => 2500
        ],
        'S' => [
            'level_cap' => 60,
            'break_material' => 3,
            'bright_crystal' => 5000
        ],
        'SS' => [
            'level_cap' => 80,
            'break_material' => 5,
            'bright_crystal' => 10000
        ],
        'P' => [
            'level_cap' => 100,
            'break_material' => 8,
            'bright_crystal' => 20000
        ]
    ];

    private const BATTLE_LOSS_RATES = [
        'attacker_win_big' => [
            'attacker' => 0.05,
            'defender' => 0.50
        ],
        'attacker_win' => [
            'attacker' => 0.10,
            'defender' => 0.30
        ],
        'draw' => [
            'attacker' => 0.20,
            'defender' => 0.20
        ],
        'defender_win' => [
            'attacker' => 0.30,
            'defender' => 0.10
        ],
        'defender_win_big' => [
            'attacker' => 0.50,
            'defender' => 0.05
        ]
    ];

    private const CAPTIVE_RATES = [
        'regular' => 0.10,
        'big' => 0.20
    ];

    /**
     * 获取指定非付费招募类型的稀有度概率 / Gets rarity probabilities for a non-paid recruitment type
     *
     * @param string $type 招募类型 / Recruitment type
     * @return array 稀有度概率表 / Rarity probability table
     */
    public static function getGeneralRecruitmentProbabilities($type): array {
        $normalizedType = strtolower(trim((string) $type));

        if (!isset(self::GENERAL_RECRUITMENT_PROBABILITIES[$normalizedType])) {
            throw new InvalidArgumentException('未知的武将招募类型 / Unknown general recruitment type');
        }

        return self::GENERAL_RECRUITMENT_PROBABILITIES[$normalizedType];
    }

    /**
     * 按指定非付费招募类型抽取武将稀有度 / Rolls a general rarity for a non-paid recruitment type
     *
     * @param string $type 招募类型 / Recruitment type
     * @param float|null $roll 可选的百分位点，用于可重复测试 / Optional percentile roll for deterministic tests
     * @return string 抽中的稀有度 / Rolled rarity
     */
    public static function rollGeneralRarity($type, $roll = null): string {
        return self::selectWeightedRarity(
            self::getGeneralRecruitmentProbabilities($type),
            $roll
        );
    }

    /**
     * 获取技能卡稀有度概率 / Gets skill-card rarity probabilities
     *
     * @return array 稀有度概率表 / Rarity probability table
     */
    public static function getSkillCardProbabilities(): array {
        return self::SKILL_CARD_PROBABILITIES;
    }

    /**
     * 抽取技能卡稀有度 / Rolls a skill-card rarity
     *
     * @param float|null $roll 可选的百分位点，用于可重复测试 / Optional percentile roll for deterministic tests
     * @return string 抽中的稀有度 / Rolled rarity
     */
    public static function rollSkillCardRarity($roll = null): string {
        return self::selectWeightedRarity(self::SKILL_CARD_PROBABILITIES, $roll);
    }

    /**
     * 获取攻击兵种对防御兵种的克制倍率 / Gets the attacking unit's counter multiplier against a defending unit
     *
     * @param string $attackerType 攻击兵种 / Attacking unit type
     * @param string $defenderType 防御兵种 / Defending unit type
     * @return float 克制倍率 / Counter multiplier
     */
    public static function getUnitCounterMultiplier($attackerType, $defenderType): float {
        $attacker = strtolower(trim((string) $attackerType));
        $defender = strtolower(trim((string) $defenderType));

        if (!in_array($attacker, self::UNIT_TYPES, true)
            || !in_array($defender, self::UNIT_TYPES, true)) {
            throw new InvalidArgumentException('未知的兵种 / Unknown unit type');
        }

        return in_array($defender, self::UNIT_COUNTERS[$attacker], true)
            ? self::COUNTER_MULTIPLIER
            : 1.0;
    }

    /**
     * 获取指定稀有度的BREAK等级上限 / Gets the BREAK level cap for a rarity
     *
     * @param string $rarity 稀有度 / Rarity
     * @return int 等级上限 / Level cap
     */
    public static function getBreakLevelCap($rarity): int {
        $rule = self::getBreakRule($rarity);

        return $rule['level_cap'];
    }

    /**
     * 获取指定稀有度的BREAK材料与光晶成本 / Gets BREAK material and bright-crystal costs for a rarity
     *
     * @param string $rarity 稀有度 / Rarity
     * @return array BREAK成本 / BREAK costs
     */
    public static function getBreakCost($rarity): array {
        $rule = self::getBreakRule($rarity);

        return [
            'break_material' => $rule['break_material'],
            'bright_crystal' => $rule['bright_crystal']
        ];
    }

    /**
     * 根据双方战力计算战斗结果 / Calculates a battle outcome from both sides' power
     *
     * @param float $attackerPower 攻击方战力 / Attacker power
     * @param float $defenderPower 防御方战力 / Defender power
     * @return string 战斗结果代码 / Battle outcome code
     */
    public static function calculateBattleOutcome($attackerPower, $defenderPower): string {
        $attacker = self::normalizeNonNegativeNumber(
            $attackerPower,
            '攻击方战力不能为负数 / Attacker power cannot be negative'
        );
        $defender = self::normalizeNonNegativeNumber(
            $defenderPower,
            '防御方战力不能为负数 / Defender power cannot be negative'
        );

        if ($attacker === 0.0 && $defender === 0.0) {
            return 'draw';
        }

        if ($attacker > $defender * 1.5) {
            return 'attacker_win_big';
        }

        if ($attacker > $defender) {
            return 'attacker_win';
        }

        if ($defender > $attacker * 1.5) {
            return 'defender_win_big';
        }

        if ($defender > $attacker) {
            return 'defender_win';
        }

        return 'draw';
    }

    /**
     * 获取战斗结果对应的双方损失率 / Gets both sides' loss rates for a battle outcome
     *
     * @param string $outcome 战斗结果代码 / Battle outcome code
     * @return array 双方损失率 / Loss rates for both sides
     */
    public static function getBattleLossRates($outcome): array {
        $normalizedOutcome = strtolower(trim((string) $outcome));

        if (!isset(self::BATTLE_LOSS_RATES[$normalizedOutcome])) {
            throw new InvalidArgumentException('未知的战斗结果 / Unknown battle outcome');
        }

        return self::BATTLE_LOSS_RATES[$normalizedOutcome];
    }

    /**
     * 按损失率计算部队损失数量 / Calculates troop losses from a loss rate
     *
     * @param int $troopCount 部队数量 / Troop count
     * @param float $lossRate 损失率 / Loss rate
     * @return int 损失数量 / Loss count
     */
    public static function calculateBattleLosses($troopCount, $lossRate): int {
        $troops = self::normalizeNonNegativeInteger(
            $troopCount,
            '部队数量必须是非负整数 / Troop count must be a non-negative integer'
        );
        $rate = self::normalizeNonNegativeNumber(
            $lossRate,
            '损失率不能为负数 / Loss rate cannot be negative'
        );

        if ($rate > 1.0) {
            throw new InvalidArgumentException('损失率不能大于1 / Loss rate cannot exceed 1');
        }

        return min($troops, (int) ceil($troops * $rate));
    }

    /**
     * 根据胜负和可俘虏败军数量计算俘虏 / Calculates captives from the outcome and eligible defeated troops
     *
     * @param int $eligibleDefeatedTroops 可被俘虏的败军数量 / Eligible defeated troop count
     * @param string $outcome 战斗结果代码 / Battle outcome code
     * @param string $captorSide 俘虏方 / Captor side
     * @return int 俘虏数量 / Captive count
     */
    public static function calculateCaptiveCount(
        $eligibleDefeatedTroops,
        $outcome,
        $captorSide = 'attacker'
    ): int {
        $eligibleTroops = self::normalizeNonNegativeInteger(
            $eligibleDefeatedTroops,
            '可俘虏部队数量必须是非负整数 / Eligible captive troop count must be a non-negative integer'
        );
        $normalizedOutcome = strtolower(trim((string) $outcome));
        $normalizedCaptorSide = strtolower(trim((string) $captorSide));

        self::getBattleLossRates($normalizedOutcome);

        if (!in_array($normalizedCaptorSide, ['attacker', 'defender'], true)) {
            throw new InvalidArgumentException('俘虏方必须是攻击方或防御方 / Captor side must be attacker or defender');
        }

        if ($normalizedOutcome === 'draw') {
            return 0;
        }

        $winnerSide = strpos($normalizedOutcome, 'attacker_') === 0
            ? 'attacker'
            : 'defender';

        if ($winnerSide !== $normalizedCaptorSide) {
            return 0;
        }

        $rate = substr($normalizedOutcome, -4) === '_big'
            ? self::CAPTIVE_RATES['big']
            : self::CAPTIVE_RATES['regular'];

        return (int) floor($eligibleTroops * $rate);
    }

    /**
     * 计算竞技场双方的Elo分数变化 / Calculates Arena Elo rating changes for both players
     *
     * @param float $ratingA 玩家A当前分数 / Player A's current rating
     * @param float $ratingB 玩家B当前分数 / Player B's current rating
     * @param float $scoreA 玩家A赛果，胜1、平0.5、负0 / Player A's score: 1 win, 0.5 draw, 0 loss
     * @param float $kFactor Elo变化系数 / Elo change factor
     * @return array 双方分数变化 / Rating changes for both players
     */
    public static function calculateArenaEloChanges(
        $ratingA,
        $ratingB,
        $scoreA,
        $kFactor = 32
    ): array {
        $normalizedRatingA = self::normalizeNonNegativeNumber(
            $ratingA,
            '玩家A的Elo分数不能为负数 / Player A Elo rating cannot be negative'
        );
        $normalizedRatingB = self::normalizeNonNegativeNumber(
            $ratingB,
            '玩家B的Elo分数不能为负数 / Player B Elo rating cannot be negative'
        );
        $normalizedScoreA = self::normalizeNonNegativeNumber(
            $scoreA,
            '赛果不能为负数 / Score cannot be negative'
        );
        $normalizedKFactor = self::normalizeNonNegativeNumber(
            $kFactor,
            'Elo变化系数不能为负数 / Elo change factor cannot be negative'
        );

        if (!in_array($normalizedScoreA, [0.0, 0.5, 1.0], true)) {
            throw new InvalidArgumentException('赛果必须是0、0.5或1 / Score must be 0, 0.5, or 1');
        }

        if ($normalizedKFactor <= 0.0) {
            throw new InvalidArgumentException('Elo变化系数必须大于0 / Elo change factor must be greater than 0');
        }

        $expectedScoreA = 1.0 / (
            1.0 + pow(10.0, ($normalizedRatingB - $normalizedRatingA) / 400.0)
        );
        $changeA = (int) round($normalizedKFactor * ($normalizedScoreA - $expectedScoreA));

        return [
            'player_a' => $changeA,
            'player_b' => -$changeA
        ];
    }

    /**
     * 获取讨伐奖励的最低伤害贡献 / Gets the minimum damage contribution for raid rewards
     *
     * @param int $maxHp 讨伐目标最大生命 / Raid target maximum HP
     * @return int 最低贡献 / Minimum contribution
     */
    public static function getRaidMinimumContribution($maxHp): int {
        $normalizedMaxHp = self::normalizeNonNegativeInteger(
            $maxHp,
            '讨伐目标最大生命必须是非负整数 / Raid maximum HP must be a non-negative integer'
        );
        $ratioThreshold = intdiv($normalizedMaxHp, 1000);
        if ($normalizedMaxHp % 1000 !== 0) {
            $ratioThreshold++;
        }

        return max(100, $ratioThreshold);
    }

    /**
     * 获取战斗塔指定楼层的敌军战力 / Gets enemy power for a Battle Tower floor
     *
     * @param int $floor 楼层 / Floor
     * @return int 敌军战力 / Enemy power
     */
    public static function getTowerEnemyPower($floor): int {
        $normalizedFloor = self::normalizePositiveInteger(
            $floor,
            '战斗塔楼层必须是正整数 / Battle Tower floor must be a positive integer'
        );

        return (int) round(1000 * pow($normalizedFloor, 0.35));
    }

    /**
     * 获取战斗塔指定楼层的通关奖励 / Gets the clear reward for a Battle Tower floor
     *
     * @param int $floor 楼层 / Floor
     * @return array 楼层奖励 / Floor reward
     */
    public static function getTowerReward($floor): array {
        $normalizedFloor = self::normalizePositiveInteger(
            $floor,
            '战斗塔楼层必须是正整数 / Battle Tower floor must be a positive integer'
        );

        return [
            'bright_crystal' => 200 * $normalizedFloor,
            'night_crystal' => 20 * $normalizedFloor,
            'break_material' => intdiv($normalizedFloor - 1, 10) + 1
        ];
    }

    /**
     * 按权重和百分位点选择稀有度 / Selects a rarity from weights and a percentile roll
     *
     * @param array $weights 稀有度权重 / Rarity weights
     * @param float|null $roll 可选百分位点 / Optional percentile roll
     * @return string 选中的稀有度 / Selected rarity
     */
    private static function selectWeightedRarity(array $weights, $roll = null): string {
        $total = array_sum($weights);

        if (abs($total - 100.0) > 0.00001) {
            throw new LogicException('稀有度概率总和必须为100 / Rarity probabilities must total 100');
        }

        if ($roll === null) {
            $normalizedRoll = mt_rand(1, 1000000) / 10000;
        } else {
            $normalizedRoll = self::normalizeNonNegativeNumber(
                $roll,
                '抽取百分位点必须大于0 / Roll percentile must be greater than 0'
            );
        }

        if ($normalizedRoll <= 0.0 || $normalizedRoll > 100.0) {
            throw new InvalidArgumentException('抽取百分位点必须在(0, 100]内 / Roll percentile must be in (0, 100]');
        }

        $cumulative = 0.0;

        foreach ($weights as $rarity => $weight) {
            $cumulative += $weight;

            if ($normalizedRoll <= $cumulative && $weight > 0.0) {
                return $rarity;
            }
        }

        throw new LogicException('无法根据概率选择稀有度 / Unable to select a rarity from probabilities');
    }

    /**
     * 获取标准化后的BREAK规则 / Gets a normalized BREAK rule
     *
     * @param string $rarity 稀有度 / Rarity
     * @return array BREAK规则 / BREAK rule
     */
    private static function getBreakRule($rarity): array {
        $normalizedRarity = strtoupper(trim((string) $rarity));

        if (!isset(self::BREAK_RULES[$normalizedRarity])) {
            throw new InvalidArgumentException('未知的BREAK稀有度 / Unknown BREAK rarity');
        }

        return self::BREAK_RULES[$normalizedRarity];
    }

    /**
     * 验证并标准化非负数值 / Validates and normalizes a non-negative number
     *
     * @param mixed $value 待验证数值 / Value to validate
     * @param string $errorMessage 验证失败信息 / Validation error message
     * @return float 标准化数值 / Normalized value
     */
    private static function normalizeNonNegativeNumber($value, $errorMessage): float {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($errorMessage);
        }

        $normalizedValue = (float) $value;

        if (!is_finite($normalizedValue) || $normalizedValue < 0.0) {
            throw new InvalidArgumentException($errorMessage);
        }

        return $normalizedValue;
    }

    /**
     * 验证并标准化非负整数 / Validates and normalizes a non-negative integer
     *
     * @param mixed $value 待验证数值 / Value to validate
     * @param string $errorMessage 验证失败信息 / Validation error message
     * @return int 标准化整数 / Normalized integer
     */
    private static function normalizeNonNegativeInteger($value, $errorMessage): int {
        $normalizedValue = self::normalizeNonNegativeNumber($value, $errorMessage);

        if (floor($normalizedValue) !== $normalizedValue
            || $normalizedValue > PHP_INT_MAX) {
            throw new InvalidArgumentException($errorMessage);
        }

        return (int) $normalizedValue;
    }

    /**
     * 验证并标准化正整数 / Validates and normalizes a positive integer
     *
     * @param mixed $value 待验证数值 / Value to validate
     * @param string $errorMessage 验证失败信息 / Validation error message
     * @return int 标准化整数 / Normalized integer
     */
    private static function normalizePositiveInteger($value, $errorMessage): int {
        $normalizedValue = self::normalizeNonNegativeInteger($value, $errorMessage);

        if ($normalizedValue <= 0) {
            throw new InvalidArgumentException($errorMessage);
        }

        return $normalizedValue;
    }
}
