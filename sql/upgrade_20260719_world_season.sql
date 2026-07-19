-- 种火集结号 - 全图可见与赛季世界升级 / Fireseed Engage - full-map and seasonal-world upgrade

-- 持久记录每块资源地实际支付的成本，确保改配置后仍能原额返还。 / Persist the paid cost so later configuration changes cannot alter refunds.
SET @fireseed_has_occupation_cost := (
  SELECT COUNT(*)
  FROM `information_schema`.`columns`
  WHERE `table_schema` = DATABASE()
    AND `table_name` = 'map_tiles'
    AND `column_name` = 'occupation_circuit_cost'
);
SET @fireseed_add_occupation_cost_sql := IF(
  @fireseed_has_occupation_cost = 0,
  'ALTER TABLE `map_tiles` ADD COLUMN `occupation_circuit_cost` int(11) NOT NULL DEFAULT 0 AFTER `collection_efficiency`',
  'SELECT 1'
);
PREPARE fireseed_add_occupation_cost
  FROM @fireseed_add_occupation_cost_sql;
EXECUTE fireseed_add_occupation_cost;
DEALLOCATE PREPARE fireseed_add_occupation_cost;

-- 保留兼容列，但新旧地图格均始终公开。 / Keep the compatibility column while making every old and new tile public.
ALTER TABLE `map_tiles`
  MODIFY `is_visible` tinyint(1) NOT NULL DEFAULT 1;

START TRANSACTION;

-- 先确保标记行存在，再锁定该行；即使数据库使用READ COMMITTED也能串行化并发升级。 / Ensure the marker row exists before locking it so concurrent runners serialize even under READ COMMITTED.
INSERT INTO `game_config`
  (`key`, `value`, `description`, `is_constant`, `category`)
VALUES
  ('migration_20260719_world_season', 'running', '全图与赛季世界升级执行中 / Full-map and seasonal-world migration running', 1, 'system')
ON DUPLICATE KEY UPDATE
  `key` = VALUES(`key`);

SET @fireseed_world_season_marker_id := NULL;
SET @fireseed_world_season_marker_value := NULL;
SELECT `config_id`, `value`
INTO @fireseed_world_season_marker_id,
     @fireseed_world_season_marker_value
FROM `game_config`
WHERE `key` = 'migration_20260719_world_season'
LIMIT 1
FOR UPDATE;
SET @fireseed_world_season_complete :=
  COALESCE(
    @fireseed_world_season_marker_value = 'complete',
    0
  );

UPDATE `map_tiles`
SET `is_visible` = 1
WHERE `is_visible` <> 1;

-- 旧地图将十二门通用子类型细化为稳定地点代码，供地图入口识别。 / Give legacy gateways their stable site codes for map routing.
UPDATE `map_tiles` AS tile
INNER JOIN `world_sites` AS site
  ON site.`tile_id` = tile.`tile_id`
SET tile.`subtype` = site.`site_code`
WHERE site.`site_type` = 'gateway';

-- 仅补充缺失配置，不覆盖运营方已经调整的数值。 / Add missing settings without replacing operator-tuned values.
INSERT IGNORE INTO `game_config`
  (`key`, `value`, `description`, `is_constant`, `category`)
VALUES
  ('season_start_bright_grant', '1000', '每赛季开始向既有玩家发放的亮晶晶 / Bright Crystals granted to each existing player at season start', 0, 'resources'),
  ('season_start_night_grant', '1000', '每赛季开始向既有玩家发放的夜静静 / Night Crystals granted to each existing player at season start', 0, 'resources'),
  ('resource_territory_occupation_cost', '2', '占领资源地所需思考回路；空地始终免费 / Circuit cost for resource occupation; empty land is always free', 0, 'map'),
  ('map_resource_weight_bright', '4', '亮晶晶大地图资源点临时权重 / Provisional Bright world-node weight', 0, 'map'),
  ('map_resource_weight_warm', '23', '暖洋洋大地图资源点临时权重 / Provisional Warm world-node weight', 0, 'map'),
  ('map_resource_weight_cold', '23', '冷冰冰大地图资源点临时权重 / Provisional Cold world-node weight', 0, 'map'),
  ('map_resource_weight_green', '23', '郁萌萌大地图资源点临时权重 / Provisional Green world-node weight', 0, 'map'),
  ('map_resource_weight_day', '23', '昼闪闪大地图资源点临时权重 / Provisional Day world-node weight', 0, 'map'),
  ('map_resource_weight_night', '4', '夜静静大地图资源点临时权重 / Provisional Night world-node weight', 0, 'map');

-- 升级前的已占领资源地都支付硬编码的两点成本；完成标记写入前可安全重跑。 / Legacy owned resources paid the former hard-coded cost of two; reruns remain safe until the completion marker is written.
UPDATE `map_tiles`
SET `occupation_circuit_cost` = 2
WHERE @fireseed_world_season_complete = 0
  AND `type` = 'resource'
  AND `owner_id` IS NOT NULL
  AND `occupation_circuit_cost` = 0;

-- 旧版空地也曾锁定两点回路；新规则空地免费，因此一次性把仍由原玩家持有的投入退回。 / Legacy empty tiles also locked two Circuit Points; now that empty land is free, refund investments still represented by current ownership.
DROP TEMPORARY TABLE IF EXISTS `fireseed_legacy_empty_refund`;
CREATE TEMPORARY TABLE `fireseed_legacy_empty_refund` (
  `user_id` int(11) NOT NULL,
  `refund_amount` bigint unsigned NOT NULL,
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_legacy_empty_refund`
  (`user_id`, `refund_amount`)
SELECT `owner_id`, COUNT(*) * 2
FROM `map_tiles`
WHERE @fireseed_world_season_complete = 0
  AND `type` = 'empty'
  AND `owner_id` IS NOT NULL
GROUP BY `owner_id`;

-- 任何无法完整退款的异常余额都让迁移失败，禁止静默截断。 / Fail the migration instead of silently truncating any refund that would overflow.
DROP TEMPORARY TABLE IF EXISTS `fireseed_empty_refund_overflow_guard`;
CREATE TEMPORARY TABLE `fireseed_empty_refund_overflow_guard` (
  `guard_id` tinyint NOT NULL,
  PRIMARY KEY (`guard_id`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_empty_refund_overflow_guard` (`guard_id`)
SELECT 1
FROM `users` AS player
INNER JOIN `fireseed_legacy_empty_refund` AS refund
  ON refund.`user_id` = player.`user_id`
WHERE player.`circuit_points`
      > 2147483647 - refund.`refund_amount`
LIMIT 1;

INSERT INTO `fireseed_empty_refund_overflow_guard` (`guard_id`)
SELECT 1
FROM `users` AS player
INNER JOIN `fireseed_legacy_empty_refund` AS refund
  ON refund.`user_id` = player.`user_id`
WHERE player.`circuit_points`
      > 2147483647 - refund.`refund_amount`
LIMIT 1;

UPDATE `users` AS player
INNER JOIN `fireseed_legacy_empty_refund` AS refund
  ON refund.`user_id` = player.`user_id`
SET player.`circuit_points` =
      player.`circuit_points` + refund.`refund_amount`;

DROP TEMPORARY TABLE `fireseed_empty_refund_overflow_guard`;
DROP TEMPORARY TABLE `fireseed_legacy_empty_refund`;

INSERT INTO `game_config`
  (`key`, `value`, `description`, `is_constant`, `category`)
VALUES
  ('migration_20260719_world_season', 'complete', '全图与赛季世界升级已完成 / Full-map and seasonal-world migration completed', 1, 'system')
ON DUPLICATE KEY UPDATE
  `value` = VALUES(`value`),
  `description` = VALUES(`description`),
  `is_constant` = VALUES(`is_constant`),
  `category` = VALUES(`category`);

COMMIT;
