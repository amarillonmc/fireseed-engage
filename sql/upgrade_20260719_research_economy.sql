-- 种火集结号 - 科研、经济与长期成长升级 / Fireseed Engage - Research, economy, and long-term progression upgrade

-- 与安装器和注册端的邮箱上限保持一致。 / Match the installer and registration email limit.
SET @fireseed_sql = IF(
  COALESCE(
    (
      SELECT `character_maximum_length`
      FROM `information_schema`.`columns`
      WHERE `table_schema` = DATABASE()
        AND `table_name` = 'users'
        AND `column_name` = 'email'
      LIMIT 1
    ),
    0
  ) < 254,
  'ALTER TABLE `users` MODIFY `email` varchar(254) NOT NULL',
  'SELECT 1'
);
PREPARE fireseed_statement FROM @fireseed_sql;
EXECUTE fireseed_statement;
DEALLOCATE PREPARE fireseed_statement;

SET @fireseed_sql = IF(
  EXISTS(
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'technologies'
      AND column_name = 'scope'
  ),
  'SELECT 1',
  'ALTER TABLE `technologies` ADD COLUMN `scope` enum(''seasonal'',''permanent'') NOT NULL DEFAULT ''seasonal'' AFTER `max_level`'
);
PREPARE fireseed_statement FROM @fireseed_sql;
EXECUTE fireseed_statement;
DEALLOCATE PREPARE fireseed_statement;

SET @fireseed_sql = IF(
  EXISTS(
    SELECT 1 FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'technologies'
      AND column_name = 'effect_key'
  ),
  'SELECT 1',
  'ALTER TABLE `technologies` ADD COLUMN `effect_key` varchar(64) NOT NULL DEFAULT '''' AFTER `scope`'
);
PREPARE fireseed_statement FROM @fireseed_sql;
EXECUTE fireseed_statement;
DEALLOCATE PREPARE fireseed_statement;

SET @fireseed_sql = IF(
  EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'technologies'
      AND index_name = 'scope'
  ),
  'SELECT 1',
  'ALTER TABLE `technologies` ADD KEY `scope` (`scope`)'
);
PREPARE fireseed_statement FROM @fireseed_sql;
EXECUTE fireseed_statement;
DEALLOCATE PREPARE fireseed_statement;

SET @fireseed_sql = IF(
  EXISTS(
    SELECT 1 FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'technologies'
      AND index_name = 'effect_key'
  ),
  'SELECT 1',
  'ALTER TABLE `technologies` ADD KEY `effect_key` (`effect_key`)'
);
PREPARE fireseed_statement FROM @fireseed_sql;
EXECUTE fireseed_statement;
DEALLOCATE PREPARE fireseed_statement;

START TRANSACTION;

-- 先确保标记行存在，再锁定该行；即使数据库使用READ COMMITTED也能串行化并发升级。 / Ensure the marker row exists before locking it so concurrent runners serialize even under READ COMMITTED.
INSERT INTO `game_config`
  (`key`,`value`,`description`,`is_constant`,`category`)
VALUES
  ('migration_20260719_research_economy', 'running', '科研、经济与长期成长升级执行中 / Research, economy, and long-term progression migration running', 1, 'system')
ON DUPLICATE KEY UPDATE
  `key` = VALUES(`key`);

SET @fireseed_research_economy_marker_id := NULL;
SET @fireseed_research_economy_marker_value := NULL;
SELECT `config_id`, `value`
INTO @fireseed_research_economy_marker_id,
     @fireseed_research_economy_marker_value
FROM `game_config`
WHERE `key` = 'migration_20260719_research_economy'
LIMIT 1
FOR UPDATE;
SET @fireseed_research_economy_complete :=
  COALESCE(
    @fireseed_research_economy_marker_value = 'complete',
    0
  );

-- 永久货币的产率科研留待数值专项，不沿用旧赛季科技 / Persistent-currency output research is reserved for later balance design
DELETE FROM `technologies`
WHERE @fireseed_research_economy_complete = 0
  AND `name` IN ('亮晶晶产量提升', '夜静静产量提升');

DROP TEMPORARY TABLE IF EXISTS `fireseed_research_technology_seed`;
CREATE TEMPORARY TABLE `fireseed_research_technology_seed` (
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `category` enum('resource','soldier','city','governor') NOT NULL,
  `base_effect` float NOT NULL,
  `base_cost` text NOT NULL,
  `level_coefficient` float NOT NULL,
  `max_level` int(11) NOT NULL,
  `scope` enum('seasonal','permanent') NOT NULL,
  `effect_key` varchar(64) NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_research_technology_seed`
  (`name`,`description`,`category`,`base_effect`,`base_cost`,
   `level_coefficient`,`max_level`,`scope`,`effect_key`)
VALUES
('暖洋洋产量提升','本赛季提高暖洋洋资源的产出效率','resource',0.05,'{"warm":1000,"cold":1000,"green":1000,"day":1000}',0.5,10,'seasonal','resource_production_warm'),
('冷冰冰产量提升','本赛季提高冷冰冰资源的产出效率','resource',0.05,'{"warm":1000,"cold":1000,"green":1000,"day":1000}',0.5,10,'seasonal','resource_production_cold'),
('郁萌萌产量提升','本赛季提高郁萌萌资源的产出效率','resource',0.05,'{"warm":1000,"cold":1000,"green":1000,"day":1000}',0.5,10,'seasonal','resource_production_green'),
('昼闪闪产量提升','本赛季提高昼闪闪资源的产出效率','resource',0.05,'{"warm":1000,"cold":1000,"green":1000,"day":1000}',0.5,10,'seasonal','resource_production_day'),
('资源存储提升','本赛季提高四种赛季资源的存储上限','resource',0.10,'{"warm":1000,"cold":1000,"green":1000,"day":1000}',0.3,10,'seasonal','resource_storage'),
('训练调度','本赛季缩短士兵训练时间','soldier',0.03,'{"warm":1200,"cold":1200,"green":1200,"day":1200}',0.5,10,'seasonal','training_speed'),
('军势演算','本赛季提高士兵攻击力','soldier',0.03,'{"warm":1500,"cold":1500,"green":1500,"day":1500}',0.5,10,'seasonal','soldier_attack'),
('防阵演算','本赛季提高士兵防御力','soldier',0.03,'{"warm":1500,"cold":1500,"green":1500,"day":1500}',0.5,10,'seasonal','soldier_defense'),
('城防工学','本赛季提高城池综合防御力','city',0.04,'{"warm":1500,"cold":1500,"green":1500,"day":1500}',0.5,10,'seasonal','city_defense'),
('永久建筑统筹','跨赛季永久缩短建造与设施升级时间','governor',0.01,'{"bright":2000,"night":500}',0.75,10,'permanent','build_speed'),
('永久回路扩容','跨赛季永久提高思考回路持有上限','governor',1.0,'{"bright":2500,"night":750}',0.75,10,'permanent','circuit_capacity'),
('永久编制扩张','跨赛季永久提高武将编制COST上限','governor',0.5,'{"bright":3000,"night":1000}',0.75,10,'permanent','general_cost_capacity'),
('永久据点许可','跨赛季永久提高分基地数量上限','governor',1.0,'{"bright":5000,"night":1500}',1.0,5,'permanent','subbase_capacity');

INSERT INTO `technologies`
  (`name`,`description`,`category`,`base_effect`,`base_cost`,
   `level_coefficient`,`max_level`,`scope`,`effect_key`)
SELECT
  seed.`name`, seed.`description`, seed.`category`,
  seed.`base_effect`, seed.`base_cost`, seed.`level_coefficient`,
  seed.`max_level`, seed.`scope`, seed.`effect_key`
FROM `fireseed_research_technology_seed` AS seed
WHERE @fireseed_research_economy_complete = 0
ON DUPLICATE KEY UPDATE
  `description` = VALUES(`description`),
  `category` = VALUES(`category`),
  `base_effect` = VALUES(`base_effect`),
  `base_cost` = VALUES(`base_cost`),
  `level_coefficient` = VALUES(`level_coefficient`),
  `max_level` = VALUES(`max_level`),
  `scope` = VALUES(`scope`),
  `effect_key` = VALUES(`effect_key`);

DROP TEMPORARY TABLE `fireseed_research_technology_seed`;

INSERT IGNORE INTO `game_config`
  (`key`,`value`,`description`,`is_constant`,`category`)
VALUES
('persistent_resource_production_multiplier','0.2','亮晶晶与夜静静产出器相对四色产出器的基础倍率 / Base output multiplier for Bright and Night producers relative to seasonal producers',0,'resources'),
('initial_subbase_limit','1','玩家基础分基地上限（永久科研可提高） / Base sub-base cap before permanent research',0,'generals');

-- 玩家等级不再驱动成长，移除已失效的等级奖励配置 / Player level no longer drives progression; remove obsolete level-bonus settings
DELETE FROM `game_config`
WHERE @fireseed_research_economy_complete = 0
  AND `key` IN (
  'level_up_circuit_bonus',
  'level_up_general_cost_bonus'
);

-- 默认武将池只使用亮晶晶，技能池只使用夜静静 / Default general pools use only Bright and skill pools only Night
UPDATE `card_pools`
SET `description` = '消耗亮晶晶的常驻武将契约。',
    `cost_json` = '{"bright":500}'
WHERE @fireseed_research_economy_complete = 0
  AND `pool_code` = 'general_normal'
  AND `pool_type` = 'general';

UPDATE `card_pools`
SET `description` = '消耗较多亮晶晶的高级武将契约。',
    `cost_json` = '{"bright":1500}'
WHERE @fireseed_research_economy_complete = 0
  AND `pool_code` = 'general_advanced'
  AND `pool_type` = 'general';

UPDATE `card_pools`
SET `name` = '亮晶共鸣',
    `description` = '消耗亮晶晶的高阶武将契约。',
    `cost_json` = '{"bright":5000}'
WHERE @fireseed_research_economy_complete = 0
  AND `pool_code` = 'general_resonance'
  AND `pool_type` = 'general';

UPDATE `card_pools`
SET `cost_json` = '{"night":250}'
WHERE @fireseed_research_economy_complete = 0
  AND `pool_code` = 'skill_standard'
  AND `pool_type` = 'skill';

-- 旧版存储科技曾允许十五级；基线改为十级时同步收敛玩家等级，避免运行效果与界面显示分裂。 / Legacy storage research allowed level fifteen; clamp it to the new level-ten baseline so runtime effects and UI values stay consistent.
UPDATE `user_technologies` AS user_tech
INNER JOIN `technologies` AS technology
  ON technology.`tech_id` = user_tech.`tech_id`
SET user_tech.`level` = LEAST(
      user_tech.`level`,
      technology.`max_level`
    ),
    user_tech.`research_time` = NULL
WHERE @fireseed_research_economy_complete = 0
  AND technology.`name` = '资源存储提升'
  AND user_tech.`level` >= technology.`max_level`;

-- 兼容字段物化为基础值加永久科研，不再由玩家等级推导 / Materialize compatibility caps from base values plus permanent research
SET @fireseed_base_circuit = COALESCE(
  (SELECT CAST(`value` AS UNSIGNED) FROM `game_config`
   WHERE `key` = 'initial_max_circuit_points' LIMIT 1),
  10
);
SET @fireseed_base_general_cost = COALESCE(
  (SELECT CAST(`value` AS DECIMAL(10,2)) FROM `game_config`
   WHERE `key` = 'initial_max_general_cost' LIMIT 1),
  10.0
);

UPDATE `users` AS player
LEFT JOIN (
  SELECT user_tech.`user_id`,
         SUM(
           CASE WHEN technology.`effect_key` = 'circuit_capacity'
                THEN technology.`base_effect` * user_tech.`level`
                ELSE 0 END
         ) AS circuit_bonus,
         SUM(
           CASE WHEN technology.`effect_key` = 'general_cost_capacity'
                THEN technology.`base_effect` * user_tech.`level`
                ELSE 0 END
         ) AS general_cost_bonus
  FROM `user_technologies` AS user_tech
  INNER JOIN `technologies` AS technology
    ON technology.`tech_id` = user_tech.`tech_id`
  WHERE technology.`scope` = 'permanent'
  GROUP BY user_tech.`user_id`
) AS permanent_bonus
  ON permanent_bonus.`user_id` = player.`user_id`
SET player.`max_circuit_points` =
      @fireseed_base_circuit + FLOOR(COALESCE(permanent_bonus.`circuit_bonus`, 0)),
    player.`max_general_cost` =
      @fireseed_base_general_cost + COALESCE(permanent_bonus.`general_cost_bonus`, 0)
WHERE @fireseed_research_economy_complete = 0;

INSERT INTO `game_config`
  (`key`,`value`,`description`,`is_constant`,`category`)
VALUES
  ('migration_20260719_research_economy', 'complete', '科研、经济与长期成长升级已完成 / Research, economy, and long-term progression migration completed', 1, 'system')
ON DUPLICATE KEY UPDATE
  `value` = VALUES(`value`),
  `description` = VALUES(`description`),
  `is_constant` = VALUES(`is_constant`),
  `category` = VALUES(`category`);

COMMIT;
