-- 种火集结号 - 游戏配置表

CREATE TABLE `game_config` (
  `config_id` int(11) NOT NULL AUTO_INCREMENT,
  `key` varchar(100) NOT NULL,
  `value` text NOT NULL,
  `description` text DEFAULT NULL,
  `is_constant` tinyint(1) NOT NULL DEFAULT 0,
  `category` varchar(50) DEFAULT 'general',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`config_id`),
  UNIQUE KEY `key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 插入默认配置
INSERT INTO `game_config` (`key`, `value`, `description`, `is_constant`, `category`) VALUES
-- 游戏基础设置
('game_name', '种火集结号', '游戏名称', 1, 'basic'),
('game_version', '1.0.0', '游戏版本', 1, 'basic'),
('max_players', '1000', '最大玩家数量', 0, 'basic'),
('new_player_registration', '1', '是否允许新玩家注册 (0=关闭, 1=开启)', 0, 'basic'),
('maintenance_mode', '0', '维护模式 (0=关闭, 1=开启)', 0, 'basic'),

-- 图像资源显示设置 / Image-resource display settings
('image_display_mode', 'image', '全局图像显示模式：image=正式图像，emoji_fallback=仅显示Emoji回退 / Global image mode: image or emoji_fallback', 0, 'display'),

-- 资源相关设置
('initial_bright_crystal', '1000', '新玩家初始亮晶晶数量', 0, 'resources'),
('initial_warm_crystal', '1000', '新玩家初始暖洋洋数量', 0, 'resources'),
('initial_cold_crystal', '1000', '新玩家初始冷冰冰数量', 0, 'resources'),
('initial_green_crystal', '1000', '新玩家初始郁萌萌数量', 0, 'resources'),
('initial_day_crystal', '1000', '新玩家初始昼闪闪数量', 0, 'resources'),
('initial_night_crystal', '1000', '新玩家初始夜静静数量', 0, 'resources'),
('resource_production_rate', '1.0', '资源产出倍率', 0, 'resources'),
('resource_collection_interval', '3', '资源收集间隔（秒）', 0, 'resources'),

-- 建筑相关设置
('building_speed_multiplier', '1.0', '建筑速度倍率', 0, 'building'),
('upgrade_speed_multiplier', '1.0', '升级速度倍率', 0, 'building'),
('max_facility_level', '20', '设施最大等级', 0, 'building'),

-- 科技相关设置
('research_speed_multiplier', '1.0', '研究速度倍率', 0, 'technology'),
('max_technology_level', '10', '科技最大等级', 0, 'technology'),

-- 军事相关设置
('training_speed_multiplier', '1.0', '训练速度倍率', 0, 'military'),
('battle_damage_multiplier', '1.0', '战斗伤害倍率', 0, 'military'),
('army_movement_speed', '1.0', '军队移动速度倍率', 0, 'military'),

-- 附属与脱离设置 / Vassalage and release settings
('vassal_release_resource_rate', '0.70', '附属玩家主动脱离时缴纳当前每类资源的比例（0-1） / Share of every stored resource paid on voluntary release (0-1)', 0, 'vassalage'),
('vassal_release_relocation_mode', 'outer', '主动脱离后的主城迁移模式：outer=外围，middle=中围，subbase=随机既有分基地 / Main-city relocation after release: outer, middle, or a random existing sub-base', 0, 'vassalage'),
('vassal_release_lose_all_territory', '1', '主动脱离后是否失去全部普通领地和未保留分基地（0/1） / Whether release removes all ordinary territory and unkept sub-bases (0/1)', 0, 'vassalage'),
('vassal_release_refund_circuit', '1', '清除普通领地时是否全额返还其占用的思考回路（0/1，返还可暂时超过持有上限） / Whether removed ordinary territory fully refunds its occupied Circuit Points (0/1; refunds may temporarily exceed the holding cap)', 0, 'vassalage'),

-- 武将相关设置
('general_recruitment_cost_multiplier', '1.0', '武将招募费用倍率', 0, 'generals'),
('general_max_level', '100', '武将最大等级', 0, 'generals'),
('initial_circuit_points', '1', '新玩家初始思考回路', 0, 'generals'),
('initial_max_circuit_points', '10', '新玩家最大思考回路', 0, 'generals'),
('initial_max_general_cost', '10.0', '新玩家最大武将费用', 0, 'generals'),

-- 地图相关设置
('map_size', '512', '地图大小', 1, 'map'),
('silver_hole_x', '256', '银白之孔X坐标', 1, 'map'),
('silver_hole_y', '256', '银白之孔Y坐标', 1, 'map'),
('npc_respawn_time', '86400', 'NPC城池重生时间（秒）', 0, 'map'),
('resource_point_respawn_time', '3600', '资源点重生时间（秒）', 0, 'map'),

-- 游戏平衡设置
('level_up_circuit_bonus', '2', '升级时思考回路上限增加', 0, 'balance'),
('level_up_general_cost_bonus', '0.5', '升级时武将费用上限增加', 0, 'balance'),
('city_durability_base', '3000', '城池基础耐久度', 0, 'balance'),
('victory_condition_days', '30', '胜利条件：占领银白之孔天数', 0, 'balance'),

-- 系统设置
('cron_interval', '60', '定时任务执行间隔（秒）', 0, 'system'),
('session_timeout', '86400', '会话超时时间（秒）', 0, 'system'),
('log_retention_days', '30', '日志保留天数', 0, 'system'),
('backup_retention_days', '7', '备份保留天数', 0, 'system');
