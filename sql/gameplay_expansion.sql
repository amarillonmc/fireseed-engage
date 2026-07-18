-- 种火集结号 - 企划内玩法扩展 / Fireseed Engage - In-project gameplay expansion
-- 本扩展只使用游戏内资源，不包含付费货币。 / This expansion uses only earned resources and contains no paid currency.
SET SESSION time_zone = '+08:00';

CREATE TABLE IF NOT EXISTS `gameplay_wallets` (
  `user_id` int(11) NOT NULL,
  `skill_points` int(11) NOT NULL DEFAULT 0,
  `merit_points` int(11) NOT NULL DEFAULT 0,
  `arena_tokens` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `gameplay_wallets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `skill_card_catalog` (
  `card_id` int(11) NOT NULL AUTO_INCREMENT,
  `card_code` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `rarity` enum('B','A','S','SS','P') NOT NULL DEFAULT 'B',
  `element` enum('亮晶晶','暖洋洋','冷冰冰','郁萌萌','昼闪闪','夜静静') NOT NULL,
  `activation_type` enum('active','passive') NOT NULL DEFAULT 'passive',
  `category` enum('internal','march','attack','defense','support','special') NOT NULL,
  `effect_json` text NOT NULL,
  `base_cooldown` int(11) NOT NULL DEFAULT 0,
  `max_level` int(11) NOT NULL DEFAULT 5,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`card_id`),
  UNIQUE KEY `card_code` (`card_code`),
  KEY `rarity_active` (`rarity`,`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 卡池是独立于卡片目录的发布资源；目录负责“有什么卡”，卡池负责“何时、以何成本和何概率抽取”。 / Pools are publishable resources separate from catalogs: catalogs define cards, while pools define availability, cost, and odds.
CREATE TABLE IF NOT EXISTS `card_pools` (
  `pool_id` int(11) NOT NULL AUTO_INCREMENT,
  `pool_code` varchar(64) NOT NULL,
  `pool_type` enum('general','skill') NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `cost_json` text NOT NULL,
  `allowed_counts_json` varchar(255) NOT NULL DEFAULT '[1]',
  `status` enum('draft','published','archived') NOT NULL DEFAULT 'draft',
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `revision` int(11) NOT NULL DEFAULT 1,
  `created_by` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`pool_id`),
  UNIQUE KEY `pool_code` (`pool_code`),
  KEY `type_status_schedule` (`pool_type`,`status`,`starts_at`,`ends_at`,`sort_order`),
  KEY `created_by` (`created_by`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `card_pools_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `card_pools_ibfk_2` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `general_pool_entries` (
  `pool_id` int(11) NOT NULL,
  `general_id` int(11) NOT NULL,
  `weight` int(10) unsigned NOT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`pool_id`,`general_id`),
  KEY `general_id` (`general_id`),
  KEY `pool_featured_weight` (`pool_id`,`is_featured`,`weight`),
  CONSTRAINT `general_pool_entries_ibfk_1` FOREIGN KEY (`pool_id`) REFERENCES `card_pools` (`pool_id`) ON DELETE CASCADE,
  CONSTRAINT `general_pool_entries_ibfk_2` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `skill_pool_entries` (
  `pool_id` int(11) NOT NULL,
  `card_id` int(11) NOT NULL,
  `weight` int(10) unsigned NOT NULL,
  `is_featured` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`pool_id`,`card_id`),
  KEY `card_id` (`card_id`),
  KEY `pool_featured_weight` (`pool_id`,`is_featured`,`weight`),
  CONSTRAINT `skill_pool_entries_ibfk_1` FOREIGN KEY (`pool_id`) REFERENCES `card_pools` (`pool_id`) ON DELETE CASCADE,
  CONSTRAINT `skill_pool_entries_ibfk_2` FOREIGN KEY (`card_id`) REFERENCES `skill_card_catalog` (`card_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 资源目录与卡池后台共享一把事务互斥锁，避免发布、成员和稀有度变更互相穿插。 / Catalog and pool administration share one transactional mutex so publishing, membership, and rarity changes cannot interleave.
CREATE TABLE IF NOT EXISTS `resource_admin_locks` (
  `lock_name` varchar(64) NOT NULL,
  PRIMARY KEY (`lock_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `resource_admin_locks` (`lock_name`)
VALUES ('catalog_pools');

CREATE TABLE IF NOT EXISTS `user_skill_cards` (
  `user_id` int(11) NOT NULL,
  `card_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`card_id`),
  CONSTRAINT `user_skill_cards_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `user_skill_cards_ibfk_2` FOREIGN KEY (`card_id`) REFERENCES `skill_card_catalog` (`card_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `equipped_skill_cards` (
  `skill_id` int(11) NOT NULL,
  `card_id` int(11) NOT NULL,
  `equipped_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`skill_id`),
  KEY `card_id` (`card_id`),
  CONSTRAINT `equipped_skill_cards_ibfk_1` FOREIGN KEY (`skill_id`) REFERENCES `general_skills` (`skill_id`) ON DELETE CASCADE,
  CONSTRAINT `equipped_skill_cards_ibfk_2` FOREIGN KEY (`card_id`) REFERENCES `skill_card_catalog` (`card_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `skill_cooldowns` (
  `skill_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ready_at` datetime NOT NULL,
  PRIMARY KEY (`skill_id`,`user_id`),
  CONSTRAINT `skill_cooldowns_ibfk_1` FOREIGN KEY (`skill_id`) REFERENCES `general_skills` (`skill_id`) ON DELETE CASCADE,
  CONSTRAINT `skill_cooldowns_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `active_skill_effects` (
  `skill_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `general_id` int(11) NOT NULL,
  `effect_json` text NOT NULL,
  `activated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`skill_id`),
  KEY `expires_at` (`expires_at`),
  KEY `user_id` (`user_id`),
  KEY `general_id` (`general_id`),
  CONSTRAINT `active_skill_effects_ibfk_1` FOREIGN KEY (`skill_id`) REFERENCES `general_skills` (`skill_id`) ON DELETE CASCADE,
  CONSTRAINT `active_skill_effects_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `active_skill_effects_ibfk_3` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `skill_draw_history` (
  `draw_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `card_id` int(11) NOT NULL,
  `rarity` enum('B','A','S','SS','P') NOT NULL,
  `cost_night` int(11) NOT NULL,
  `pool_id` int(11) DEFAULT NULL,
  `pool_code_snapshot` varchar(64) DEFAULT NULL,
  `pool_revision` int(11) DEFAULT NULL,
  `entry_weight` int(10) unsigned DEFAULT NULL,
  `total_weight` bigint(20) unsigned DEFAULT NULL,
  `cost_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`draw_id`),
  KEY `user_created` (`user_id`,`created_at`),
  KEY `pool_id` (`pool_id`),
  CONSTRAINT `skill_draw_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `skill_draw_history_ibfk_2` FOREIGN KEY (`card_id`) REFERENCES `skill_card_catalog` (`card_id`) ON DELETE RESTRICT,
  CONSTRAINT `skill_draw_history_ibfk_3` FOREIGN KEY (`pool_id`) REFERENCES `card_pools` (`pool_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `recruitment_history` (
  `recruitment_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `template_general_id` int(11) NOT NULL,
  `general_id` int(11) NOT NULL,
  `recruit_type` enum('starter','normal','advanced','resonance','quest','event','pool') NOT NULL,
  `rarity` enum('B','A','S','SS','P') NOT NULL,
  `pool_id` int(11) DEFAULT NULL,
  `pool_code_snapshot` varchar(64) DEFAULT NULL,
  `pool_revision` int(11) DEFAULT NULL,
  `entry_weight` int(10) unsigned DEFAULT NULL,
  `total_weight` bigint(20) unsigned DEFAULT NULL,
  `cost_json` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`recruitment_id`),
  KEY `user_created` (`user_id`,`created_at`),
  KEY `pool_id` (`pool_id`),
  CONSTRAINT `recruitment_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `recruitment_history_ibfk_2` FOREIGN KEY (`template_general_id`) REFERENCES `generals` (`general_id`) ON DELETE RESTRICT,
  CONSTRAINT `recruitment_history_ibfk_3` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE CASCADE,
  CONSTRAINT `recruitment_history_ibfk_4` FOREIGN KEY (`pool_id`) REFERENCES `card_pools` (`pool_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `general_template_catalog` (
  `template_code` varchar(64) NOT NULL,
  `general_id` int(11) NOT NULL,
  PRIMARY KEY (`template_code`),
  UNIQUE KEY `general_id` (`general_id`),
  CONSTRAINT `general_template_catalog_ibfk_1` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `general_progression` (
  `general_id` int(11) NOT NULL,
  `break_level` int(11) NOT NULL DEFAULT 0,
  `experience` int(11) NOT NULL DEFAULT 0,
  `skill_points_spent` int(11) NOT NULL DEFAULT 0,
  `last_hp_recovery` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`general_id`),
  CONSTRAINT `general_progression_ibfk_1` FOREIGN KEY (`general_id`) REFERENCES `generals` (`general_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_items` (
  `user_id` int(11) NOT NULL,
  `item_code` varchar(64) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`,`item_code`),
  CONSTRAINT `user_items_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shop_catalog` (
  `shop_item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_code` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `cost_json` text NOT NULL,
  `grant_json` text NOT NULL,
  `daily_limit` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`shop_item_id`),
  UNIQUE KEY `item_code` (`item_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `shop_purchases` (
  `purchase_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `shop_item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `cost_json` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`purchase_id`),
  KEY `user_item_created` (`user_id`,`shop_item_id`,`created_at`),
  CONSTRAINT `shop_purchases_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `shop_purchases_ibfk_2` FOREIGN KEY (`shop_item_id`) REFERENCES `shop_catalog` (`shop_item_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alliances` (
  `alliance_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `tag` varchar(12) NOT NULL,
  `description` text DEFAULT NULL,
  `leader_id` int(11) DEFAULT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `experience` int(11) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`alliance_id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `tag` (`tag`),
  KEY `leader_id` (`leader_id`),
  CONSTRAINT `alliances_ibfk_1` FOREIGN KEY (`leader_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alliance_members` (
  `member_id` int(11) NOT NULL AUTO_INCREMENT,
  `alliance_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role` enum('leader','officer','member') NOT NULL DEFAULT 'member',
  `contribution` int(11) NOT NULL DEFAULT 0,
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`member_id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `alliance_role` (`alliance_id`,`role`),
  CONSTRAINT `alliance_members_ibfk_1` FOREIGN KEY (`alliance_id`) REFERENCES `alliances` (`alliance_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 附属关系保留完整历史；生成列保证每名玩家同时至多存在一条有效关系。 / Vassalage keeps full history; the generated column permits at most one active relation per player.
CREATE TABLE IF NOT EXISTS `vassal_relations` (
  `relation_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `vassal_id` int(11) NOT NULL,
  `lord_id` int(11) NOT NULL,
  `overlord_id` int(11) NOT NULL,
  `previous_force_owner_id` int(11) NOT NULL,
  `previous_alliance_id` int(11) DEFAULT NULL,
  `previous_alliance_role` enum('leader','officer','member') DEFAULT NULL,
  `previous_alliance_contribution` int(11) NOT NULL DEFAULT 0,
  `previous_alliance_joined_at` datetime DEFAULT NULL,
  `status` enum('active','rescued','redeemed','replaced') NOT NULL DEFAULT 'active',
  `active_vassal_id` int(11) GENERATED ALWAYS AS
    (CASE WHEN `status` = 'active' THEN `vassal_id` ELSE NULL END) STORED,
  `capture_battle_id` int(11) DEFAULT NULL,
  `ended_by_user_id` int(11) DEFAULT NULL,
  `release_payment_json` text DEFAULT NULL,
  `release_destination` varchar(32) DEFAULT NULL,
  `refunded_circuit_points` int(11) NOT NULL DEFAULT 0,
  `captured_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` datetime DEFAULT NULL,
  PRIMARY KEY (`relation_id`),
  UNIQUE KEY `uq_vassal_relations_active_vassal` (`active_vassal_id`),
  KEY `lord_status` (`lord_id`,`status`),
  KEY `overlord_status` (`overlord_id`,`status`),
  KEY `previous_alliance_id` (`previous_alliance_id`),
  KEY `capture_battle_id` (`capture_battle_id`),
  CONSTRAINT `vassal_relations_ibfk_1` FOREIGN KEY (`vassal_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `vassal_relations_ibfk_2` FOREIGN KEY (`lord_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `vassal_relations_ibfk_3` FOREIGN KEY (`overlord_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `vassal_relations_ibfk_4` FOREIGN KEY (`previous_force_owner_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT,
  CONSTRAINT `vassal_relations_ibfk_5` FOREIGN KEY (`previous_alliance_id`) REFERENCES `alliances` (`alliance_id`) ON DELETE SET NULL,
  CONSTRAINT `vassal_relations_ibfk_6` FOREIGN KEY (`capture_battle_id`) REFERENCES `battles` (`battle_id`) ON DELETE SET NULL,
  CONSTRAINT `vassal_relations_ibfk_7` FOREIGN KEY (`ended_by_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 首次失守时固化可执行救出的原势力成员；后续联盟流动不会改写历史资格。 / Freeze original-force rescuers on the first capture so later alliance movement cannot rewrite historical eligibility.
CREATE TABLE IF NOT EXISTS `vassal_rescue_eligibility` (
  `relation_id` bigint(20) NOT NULL,
  `eligible_user_id` int(11) NOT NULL,
  PRIMARY KEY (`relation_id`,`eligible_user_id`),
  KEY `eligible_user_id` (`eligible_user_id`),
  CONSTRAINT `vassal_rescue_eligibility_ibfk_1` FOREIGN KEY (`relation_id`) REFERENCES `vassal_relations` (`relation_id`) ON DELETE CASCADE,
  CONSTRAINT `vassal_rescue_eligibility_ibfk_2` FOREIGN KEY (`eligible_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alliance_applications` (
  `application_id` int(11) NOT NULL AUTO_INCREMENT,
  `alliance_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` varchar(255) DEFAULT NULL,
  `status` enum('pending','accepted','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`application_id`),
  UNIQUE KEY `alliance_user` (`alliance_id`,`user_id`),
  KEY `alliance_status` (`alliance_id`,`status`),
  CONSTRAINT `alliance_applications_ibfk_1` FOREIGN KEY (`alliance_id`) REFERENCES `alliances` (`alliance_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_applications_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alliance_aid_log` (
  `aid_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `alliance_id` int(11) NOT NULL,
  `sender_id` int(11) NOT NULL,
  `receiver_id` int(11) NOT NULL,
  `resource_type` enum('bright','warm','cold','green','day','night') NOT NULL,
  `amount` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`aid_id`),
  KEY `sender_created` (`sender_id`,`created_at`),
  KEY `receiver_created` (`receiver_id`,`created_at`),
  CONSTRAINT `alliance_aid_log_ibfk_1` FOREIGN KEY (`alliance_id`) REFERENCES `alliances` (`alliance_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_aid_log_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_aid_log_ibfk_3` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alliance_operations` (
  `operation_id` int(11) NOT NULL AUTO_INCREMENT,
  `alliance_id` int(11) NOT NULL,
  `creator_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL,
  `target_type` enum('tile','city','army') NOT NULL,
  `target_id` int(11) NOT NULL,
  `target_x` int(11) NOT NULL,
  `target_y` int(11) NOT NULL,
  `launch_at` datetime NOT NULL,
  `status` enum('open','launched','completed','cancelled') NOT NULL DEFAULT 'open',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`operation_id`),
  KEY `alliance_status_launch` (`alliance_id`,`status`,`launch_at`),
  CONSTRAINT `alliance_operations_ibfk_1` FOREIGN KEY (`alliance_id`) REFERENCES `alliances` (`alliance_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_operations_ibfk_2` FOREIGN KEY (`creator_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alliance_operation_armies` (
  `operation_id` int(11) NOT NULL,
  `army_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `joined_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`operation_id`,`army_id`),
  UNIQUE KEY `army_id` (`army_id`),
  CONSTRAINT `alliance_operation_armies_ibfk_1` FOREIGN KEY (`operation_id`) REFERENCES `alliance_operations` (`operation_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_operation_armies_ibfk_2` FOREIGN KEY (`army_id`) REFERENCES `armies` (`army_id`) ON DELETE CASCADE,
  CONSTRAINT `alliance_operation_armies_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `world_sites` (
  `site_id` int(11) NOT NULL AUTO_INCREMENT,
  `tile_id` int(11) NOT NULL,
  `site_code` varchar(64) NOT NULL,
  `site_type` enum('gateway','silver_hole') NOT NULL,
  `display_name` varchar(100) NOT NULL,
  `max_durability` bigint(20) NOT NULL,
  `durability` bigint(20) NOT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `captured_at` datetime DEFAULT NULL,
  `occupation_started_at` datetime DEFAULT NULL,
  PRIMARY KEY (`site_id`),
  UNIQUE KEY `tile_id` (`tile_id`),
  UNIQUE KEY `site_code` (`site_code`),
  KEY `site_type_owner` (`site_type`,`owner_id`),
  CONSTRAINT `world_sites_ibfk_1` FOREIGN KEY (`tile_id`) REFERENCES `map_tiles` (`tile_id`) ON DELETE CASCADE,
  CONSTRAINT `world_sites_ibfk_2` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `seasons` (
  `season_id` int(11) NOT NULL AUTO_INCREMENT,
  `season_number` int(11) NOT NULL,
  `status` enum('active','victory_countdown','won','reset_pending') NOT NULL DEFAULT 'active',
  `started_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `winner_id` int(11) DEFAULT NULL,
  `victory_at` datetime DEFAULT NULL,
  `reset_at` datetime DEFAULT NULL,
  `ended_at` datetime DEFAULT NULL,
  PRIMARY KEY (`season_id`),
  UNIQUE KEY `season_number` (`season_number`),
  KEY `status` (`status`),
  CONSTRAINT `seasons_ibfk_1` FOREIGN KEY (`winner_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `season_scores` (
  `season_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `territory_score` int(11) NOT NULL DEFAULT 0,
  `battle_score` int(11) NOT NULL DEFAULT 0,
  `gateway_score` int(11) NOT NULL DEFAULT 0,
  `raid_score` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`season_id`,`user_id`),
  CONSTRAINT `season_scores_ibfk_1` FOREIGN KEY (`season_id`) REFERENCES `seasons` (`season_id`) ON DELETE CASCADE,
  CONSTRAINT `season_scores_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `territory_garrisons` (
  `garrison_id` int(11) NOT NULL AUTO_INCREMENT,
  `tile_id` int(11) NOT NULL,
  `owner_id` int(11) NOT NULL,
  `soldier_type` enum('pawn','knight','rook','bishop','golem','scout') NOT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `quantity` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`garrison_id`),
  UNIQUE KEY `tile_unit` (`tile_id`,`soldier_type`,`level`),
  KEY `owner_id` (`owner_id`),
  CONSTRAINT `territory_garrisons_ibfk_1` FOREIGN KEY (`tile_id`) REFERENCES `map_tiles` (`tile_id`) ON DELETE CASCADE,
  CONSTRAINT `territory_garrisons_ibfk_2` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 侦察任务与报告独立于战斗记录，防守方不会收到侦察来袭提示。 / Scouting missions and reports stay separate from battles, so defenders receive no incoming-scout alert.
CREATE TABLE IF NOT EXISTS `scouting_missions` (
  `mission_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `army_id` int(11) DEFAULT NULL,
  `target_tile_id` int(11) DEFAULT NULL,
  `target_owner_id` int(11) DEFAULT NULL,
  `status` enum('launched','succeeded','failed') NOT NULL DEFAULT 'launched',
  `outcome` enum('success','failure') DEFAULT NULL,
  `report_json` text DEFAULT NULL,
  `launched_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `arrival_at` datetime NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  PRIMARY KEY (`mission_id`),
  KEY `user_launched` (`user_id`,`launched_at`),
  KEY `status_arrival` (`status`,`arrival_at`),
  KEY `army_status` (`army_id`,`status`),
  KEY `target_owner_id` (`target_owner_id`),
  CONSTRAINT `scouting_missions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `scouting_missions_ibfk_2` FOREIGN KEY (`army_id`) REFERENCES `armies` (`army_id`) ON DELETE SET NULL,
  CONSTRAINT `scouting_missions_ibfk_3` FOREIGN KEY (`target_tile_id`) REFERENCES `map_tiles` (`tile_id`) ON DELETE SET NULL,
  CONSTRAINT `scouting_missions_ibfk_4` FOREIGN KEY (`target_owner_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `battle_participants` (
  `battle_id` int(11) NOT NULL,
  `attacker_user_id` int(11) NOT NULL,
  `defender_user_id` int(11) DEFAULT NULL,
  `attacker_power` bigint(20) NOT NULL DEFAULT 0,
  `defender_power` bigint(20) NOT NULL DEFAULT 0,
  `counter_details` text DEFAULT NULL,
  PRIMARY KEY (`battle_id`),
  KEY `attacker_user_id` (`attacker_user_id`),
  KEY `defender_user_id` (`defender_user_id`),
  CONSTRAINT `battle_participants_ibfk_1` FOREIGN KEY (`battle_id`) REFERENCES `battles` (`battle_id`) ON DELETE CASCADE,
  CONSTRAINT `battle_participants_ibfk_2` FOREIGN KEY (`attacker_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `battle_participants_ibfk_3` FOREIGN KEY (`defender_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `prisoners` (
  `prisoner_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `owner_id` int(11) NOT NULL,
  `source_user_id` int(11) DEFAULT NULL,
  `battle_id` int(11) DEFAULT NULL,
  `soldier_type` enum('pawn','knight','rook','bishop','golem') NOT NULL,
  `level` int(11) NOT NULL DEFAULT 1,
  `quantity` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`prisoner_id`),
  KEY `owner_id` (`owner_id`),
  CONSTRAINT `prisoners_ibfk_1` FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `prisoners_ibfk_2` FOREIGN KEY (`source_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `prisoners_ibfk_3` FOREIGN KEY (`battle_id`) REFERENCES `battles` (`battle_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gameplay_events` (
  `event_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `event_value` int(11) NOT NULL DEFAULT 1,
  `reference_type` varchar(32) DEFAULT NULL,
  `reference_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `user_type_created` (`user_id`,`event_type`,`created_at`),
  CONSTRAINT `gameplay_events_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `quest_definitions` (
  `quest_id` int(11) NOT NULL AUTO_INCREMENT,
  `quest_code` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `target_value` int(11) NOT NULL,
  `reset_cycle` enum('none','daily','weekly') NOT NULL DEFAULT 'none',
  `reward_json` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`quest_id`),
  UNIQUE KEY `quest_code` (`quest_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_quests` (
  `user_quest_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `quest_id` int(11) NOT NULL,
  `period_key` varchar(16) NOT NULL DEFAULT 'lifetime',
  `progress` int(11) NOT NULL DEFAULT 0,
  `status` enum('active','completed','claimed') NOT NULL DEFAULT 'active',
  `claimed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_quest_id`),
  UNIQUE KEY `user_quest_period` (`user_id`,`quest_id`,`period_key`),
  CONSTRAINT `user_quests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `user_quests_ibfk_2` FOREIGN KEY (`quest_id`) REFERENCES `quest_definitions` (`quest_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `achievement_definitions` (
  `achievement_id` int(11) NOT NULL AUTO_INCREMENT,
  `achievement_code` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `event_type` varchar(64) NOT NULL,
  `target_value` int(11) NOT NULL,
  `reward_json` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`achievement_id`),
  UNIQUE KEY `achievement_code` (`achievement_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_achievements` (
  `user_id` int(11) NOT NULL,
  `achievement_id` int(11) NOT NULL,
  `progress` int(11) NOT NULL DEFAULT 0,
  `unlocked_at` datetime DEFAULT NULL,
  `claimed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`user_id`,`achievement_id`),
  CONSTRAINT `user_achievements_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `user_achievements_ibfk_2` FOREIGN KEY (`achievement_id`) REFERENCES `achievement_definitions` (`achievement_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `raid_events` (
  `raid_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text NOT NULL,
  `max_hp` bigint(20) NOT NULL,
  `current_hp` bigint(20) NOT NULL,
  `defense_power` bigint(20) NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `status` enum('scheduled','active','defeated','ended') NOT NULL DEFAULT 'scheduled',
  `reward_json` text NOT NULL,
  PRIMARY KEY (`raid_id`),
  KEY `status_window` (`status`,`starts_at`,`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `raid_participation` (
  `raid_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `total_damage` bigint(20) NOT NULL DEFAULT 0,
  `attack_count` int(11) NOT NULL DEFAULT 0,
  `last_attack_at` datetime DEFAULT NULL,
  `reward_claimed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`raid_id`,`user_id`),
  CONSTRAINT `raid_participation_ibfk_1` FOREIGN KEY (`raid_id`) REFERENCES `raid_events` (`raid_id`) ON DELETE CASCADE,
  CONSTRAINT `raid_participation_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `arena_profiles` (
  `user_id` int(11) NOT NULL,
  `defense_army_id` int(11) DEFAULT NULL,
  `rating` int(11) NOT NULL DEFAULT 1000,
  `wins` int(11) NOT NULL DEFAULT 0,
  `losses` int(11) NOT NULL DEFAULT 0,
  `season_points` int(11) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `rating` (`rating`),
  CONSTRAINT `arena_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `arena_profiles_ibfk_2` FOREIGN KEY (`defense_army_id`) REFERENCES `armies` (`army_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `arena_battles` (
  `arena_battle_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `attacker_id` int(11) NOT NULL,
  `defender_id` int(11) NOT NULL,
  `attacker_army_id` int(11) DEFAULT NULL,
  `defender_army_id` int(11) DEFAULT NULL,
  `attacker_power` bigint(20) NOT NULL,
  `defender_power` bigint(20) NOT NULL,
  `winner_id` int(11) DEFAULT NULL,
  `rating_change` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`arena_battle_id`),
  KEY `attacker_created` (`attacker_id`,`created_at`),
  KEY `defender_created` (`defender_id`,`created_at`),
  CONSTRAINT `arena_battles_ibfk_1` FOREIGN KEY (`attacker_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `arena_battles_ibfk_2` FOREIGN KEY (`defender_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `arena_battles_ibfk_3` FOREIGN KEY (`attacker_army_id`) REFERENCES `armies` (`army_id`) ON DELETE SET NULL,
  CONSTRAINT `arena_battles_ibfk_4` FOREIGN KEY (`defender_army_id`) REFERENCES `armies` (`army_id`) ON DELETE SET NULL,
  CONSTRAINT `arena_battles_ibfk_5` FOREIGN KEY (`winner_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tower_progress` (
  `user_id` int(11) NOT NULL,
  `current_floor` int(11) NOT NULL DEFAULT 1,
  `highest_floor` int(11) NOT NULL DEFAULT 0,
  `attempts_today` int(11) NOT NULL DEFAULT 0,
  `attempt_date` date DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  CONSTRAINT `tower_progress_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `messages` (
  `message_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) DEFAULT NULL,
  `receiver_id` int(11) NOT NULL,
  `subject` varchar(120) NOT NULL,
  `body` text NOT NULL,
  `message_type` enum('player','system','battle','reward') NOT NULL DEFAULT 'player',
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_id`),
  KEY `receiver_read_sent` (`receiver_id`,`is_read`,`sent_at`),
  KEY `sender_id` (`sender_id`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `chat_messages` (
  `chat_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `sender_id` int(11) DEFAULT NULL,
  `channel_type` enum('world','alliance','private') NOT NULL,
  `channel_id` int(11) DEFAULT NULL,
  `body` varchar(500) NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`chat_id`),
  KEY `channel_sent` (`channel_type`,`channel_id`,`sent_at`),
  KEY `sender_id` (`sender_id`),
  CONSTRAINT `chat_messages_ibfk_1` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `friendships` (
  `friendship_id` bigint(20) NOT NULL AUTO_INCREMENT,
  `requester_id` int(11) NOT NULL,
  `addressee_id` int(11) NOT NULL,
  `status` enum('pending','accepted','blocked','rejected') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`friendship_id`),
  UNIQUE KEY `request_pair` (`requester_id`,`addressee_id`),
  KEY `addressee_status` (`addressee_id`,`status`),
  CONSTRAINT `friendships_ibfk_1` FOREIGN KEY (`requester_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE,
  CONSTRAINT `friendships_ibfk_2` FOREIGN KEY (`addressee_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 目录与默认池种子作为一个事务执行，完成标记确保重跑不会恢复运营方已移除或修改的资源。 / Catalog and default-pool seeds run in one transaction; the completion marker prevents reruns from restoring operator-managed resources.
START TRANSACTION;

SELECT `config_id`
FROM `game_config`
WHERE `key` = 'resource_catalog_seed_20260717'
FOR UPDATE;

DROP TEMPORARY TABLE IF EXISTS `fireseed_resource_seed_gate`;
CREATE TEMPORARY TABLE `fireseed_resource_seed_gate` (
  `allowed` tinyint(1) NOT NULL,
  PRIMARY KEY (`allowed`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_resource_seed_gate` (`allowed`)
SELECT 1
WHERE NOT EXISTS (
  SELECT 1
  FROM `game_config`
  WHERE `key` = 'resource_catalog_seed_20260717'
);

DROP TEMPORARY TABLE IF EXISTS `fireseed_skill_card_seed`;
CREATE TEMPORARY TABLE `fireseed_skill_card_seed` LIKE `skill_card_catalog`;

INSERT INTO `fireseed_skill_card_seed`
(`card_code`,`name`,`description`,`rarity`,`element`,`activation_type`,`category`,`effect_json`,`base_cooldown`,`max_level`) VALUES
('training_acceleration_basic','士兵训练加速・初','小幅缩短驻扎城池的士兵训练时间。','B','亮晶晶','passive','internal','{"training_speed":5}',0,5),
('lightning_march_basic','闪电行军・初','短时间提高所属军队的移动速度。','B','暖洋洋','active','march','{"speed":15,"duration":180}',7200,5),
('iron_wall_basic','铁壁防御・初','短时间提高武将与所属士兵的守备力。','B','冷冰冰','active','defense','{"defense":15,"duration":180}',7200,5),
('battle_burst_basic','战斗爆发・初','短时间提高武将与所属士兵的攻击力。','B','郁萌萌','active','attack','{"attack":15,"duration":180}',7200,5),
('healing_basic','治疗・初','恢复少量武将生命值，发动后进入冷却。','B','昼闪闪','active','support','{"healing":10}',3600,5),
('scout_enhancement_basic','侦察强化・初','小幅提高侦察兵的侦察能力。','B','夜静静','passive','special','{"scout_range":5}',0,5),
('resource_acceleration','资源加速','提高驻扎城池的全部资源产量。','A','亮晶晶','passive','internal','{"production":10}',0,5),
('training_acceleration','士兵训练加速','缩短驻扎城池的士兵训练时间。','A','亮晶晶','passive','internal','{"training_speed":10}',0,5),
('resource_burst','资源爆发','立即获得六色资源，发动后进入冷却。','S','亮晶晶','active','support','{"all_resources":1000}',3600,5),
('march_acceleration','行军加速','提高所属军队的移动速度。','A','暖洋洋','passive','march','{"speed":10}',0,5),
('lightning_march','闪电行军','短时间大幅提高所属军队的移动速度。','S','暖洋洋','active','march','{"speed":30,"duration":300}',7200,5),
('attack_enhancement','攻击强化','提高武将与所属士兵的攻击力。','A','郁萌萌','passive','attack','{"attack":10}',0,5),
('battle_burst','战斗爆发','短时间大幅提高武将与所属士兵的攻击力。','S','郁萌萌','active','attack','{"attack":30,"duration":300}',7200,5),
('defense_enhancement','防御强化','提高武将与所属士兵的守备力。','A','冷冰冰','passive','defense','{"defense":10}',0,5),
('iron_wall','铁壁防御','短时间大幅提高武将与所属士兵的守备力。','S','冷冰冰','active','defense','{"defense":30,"duration":300}',7200,5),
('healing','治疗','恢复武将生命值，发动后进入冷却。','A','昼闪闪','active','support','{"healing":20}',3600,5),
('scout_enhancement','侦察强化','提高侦察兵的侦察能力。','A','夜静静','passive','special','{"scout_range":10}',0,5),
('white_hole_resonance','银白共鸣','提高全军攻击、守备与速度。','P','夜静静','passive','special','{"attack":15,"defense":15,"speed":10}',0,5),
('crystal_guard','晶障','降低所属军队受到的战损。','SS','冷冰冰','passive','defense','{"damage_reduction":15}',0,5),
('data_insight','数据洞察','提高技能效果与任务奖励。','SS','亮晶晶','passive','support','{"skill_power":15,"quest_reward":5}',0,5),
-- 以下曲线移植原作“兵种×COST×技能等级”和“同势力参战数”模型，并改写为本企划兵种与六元素。 / The following curves adapt the original troop-by-cost-by-level and matching-faction models to this project's units and six elements.
('pawn_assault_doctrine','兵卒锐击','按武将COST与技能等级提高所属兵卒攻击力。','B','暖洋洋','passive','attack','{"unit_attack_pawn":{"mode":"cost_level_values","values":[8,11,13,16,19,22,26,30,35,40]}}',0,10),
('knight_assault_doctrine','骑士锐击','按武将COST与技能等级提高所属骑士攻击力。','B','暖洋洋','passive','attack','{"unit_attack_knight":{"mode":"cost_level_values","values":[8,11,13,16,19,22,26,30,35,40]}}',0,10),
('rook_assault_doctrine','城壁锐击','按武将COST与技能等级提高所属城壁攻击力。','B','冷冰冰','passive','attack','{"unit_attack_rook":{"mode":"cost_level_values","values":[8,11,13,16,19,22,26,30,35,40]}}',0,10),
('bishop_assault_doctrine','主教锐击','按武将COST与技能等级提高所属主教攻击力。','B','郁萌萌','passive','attack','{"unit_attack_bishop":{"mode":"cost_level_values","values":[8,11,13,16,19,22,26,30,35,40]}}',0,10),
('golem_assault_doctrine','锤兵锐击','按武将COST与技能等级提高所属锤子兵攻击力。','B','郁萌萌','passive','attack','{"unit_attack_golem":{"mode":"cost_level_values","values":[8,11,13,16,19,22,26,30,35,40]}}',0,10),
('scout_assault_doctrine','斥候锐击','按武将COST与技能等级提高所属侦察兵攻击力。','B','夜静静','passive','attack','{"unit_attack_scout":{"mode":"cost_level_values","values":[8,11,13,16,19,22,26,30,35,40]}}',0,10),
('pawn_march_doctrine','兵卒疾行','按武将COST与技能等级提高所属兵卒速度。','A','昼闪闪','passive','march','{"unit_speed_pawn":{"mode":"cost_level_values","values":[9,11,13,16,19,22,26,30,35,40]}}',0,10),
('knight_march_doctrine','骑士疾行','按武将COST与技能等级提高所属骑士速度。','A','暖洋洋','passive','march','{"unit_speed_knight":{"mode":"cost_level_values","values":[9,11,13,16,19,22,26,30,35,40]}}',0,10),
('rook_march_doctrine','城壁疾行','按武将COST与技能等级提高所属城壁速度。','A','冷冰冰','passive','march','{"unit_speed_rook":{"mode":"cost_level_values","values":[9,11,13,16,19,22,26,30,35,40]}}',0,10),
('bishop_march_doctrine','主教疾行','按武将COST与技能等级提高所属主教速度。','A','亮晶晶','passive','march','{"unit_speed_bishop":{"mode":"cost_level_values","values":[9,11,13,16,19,22,26,30,35,40]}}',0,10),
('golem_march_doctrine','锤兵疾行','按武将COST与技能等级提高所属锤子兵速度。','A','郁萌萌','passive','march','{"unit_speed_golem":{"mode":"cost_level_values","values":[9,11,13,16,19,22,26,30,35,40]}}',0,10),
('scout_march_doctrine','斥候疾行','按武将COST与技能等级提高所属侦察兵速度。','A','夜静静','passive','march','{"unit_speed_scout":{"mode":"cost_level_values","values":[9,11,13,16,19,22,26,30,35,40]}}',0,10),
('bright_resonance_assault','亮晶豪击','每名同队亮晶晶武将按技能等级提高全军攻击力，最多计算三名。','S','亮晶晶','passive','attack','{"element_attack_per_bright":{"mode":"level_values","values":[6,7,8,9,10,11,12,13,15,17]}}',0,10),
('warm_resonance_assault','暖洋豪击','每名同队暖洋洋武将按技能等级提高全军攻击力，最多计算三名。','S','暖洋洋','passive','attack','{"element_attack_per_warm":{"mode":"level_values","values":[6,7,8,9,10,11,12,13,15,17]}}',0,10),
('cold_resonance_assault','冷冰豪击','每名同队冷冰冰武将按技能等级提高全军攻击力，最多计算三名。','S','冷冰冰','passive','attack','{"element_attack_per_cold":{"mode":"level_values","values":[6,7,8,9,10,11,12,13,15,17]}}',0,10),
('green_resonance_assault','郁萌豪击','每名同队郁萌萌武将按技能等级提高全军攻击力，最多计算三名。','S','郁萌萌','passive','attack','{"element_attack_per_green":{"mode":"level_values","values":[6,7,8,9,10,11,12,13,15,17]}}',0,10),
('day_resonance_assault','昼闪豪击','每名同队昼闪闪武将按技能等级提高全军攻击力，最多计算三名。','S','昼闪闪','passive','attack','{"element_attack_per_day":{"mode":"level_values","values":[6,7,8,9,10,11,12,13,15,17]}}',0,10),
('night_resonance_assault','夜静豪击','每名同队夜静静武将按技能等级提高全军攻击力，最多计算三名。','S','夜静静','passive','attack','{"element_attack_per_night":{"mode":"level_values","values":[6,7,8,9,10,11,12,13,15,17]}}',0,10);

INSERT INTO `skill_card_catalog`
(`card_code`,`name`,`description`,`rarity`,`element`,`activation_type`,`category`,`effect_json`,`base_cooldown`,`max_level`)
SELECT
  seed.`card_code`, seed.`name`, seed.`description`, seed.`rarity`,
  seed.`element`, seed.`activation_type`, seed.`category`,
  seed.`effect_json`, seed.`base_cooldown`, seed.`max_level`
FROM `fireseed_skill_card_seed` AS seed
JOIN `fireseed_resource_seed_gate` AS seed_gate
  ON seed_gate.`allowed` = 1
LEFT JOIN `skill_card_catalog` AS existing
  ON existing.`card_code` = seed.`card_code`
WHERE existing.`card_id` IS NULL;

DROP TEMPORARY TABLE `fireseed_skill_card_seed`;

-- 从企划文档同步 G001-G014，并补充原作式COST与偏科数值范本及六张低罕贵版本。 / Synchronize G001-G014 and add original-inspired cost/stat archetypes plus six lower-rarity versions.
DROP TEMPORARY TABLE IF EXISTS `fireseed_general_template_seed`;
CREATE TEMPORARY TABLE `fireseed_general_template_seed` (
  `template_code` varchar(64) NOT NULL,
  `name` varchar(100) NOT NULL,
  `rarity` enum('B','A','S','SS','P') NOT NULL,
  `cost` decimal(3,1) NOT NULL,
  `element` enum('亮晶晶','暖洋洋','冷冰冰','郁萌萌','昼闪闪','夜静静') NOT NULL,
  `attack` int(11) NOT NULL,
  `defense` int(11) NOT NULL,
  `speed` int(11) NOT NULL,
  `intelligence` int(11) NOT NULL,
  `skill_card_code` varchar(64) NOT NULL,
  PRIMARY KEY (`template_code`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_general_template_seed`
(`template_code`,`name`,`rarity`,`cost`,`element`,`attack`,`defense`,`speed`,`intelligence`,`skill_card_code`) VALUES
('G001','白银之主','S',3.0,'亮晶晶',20,80,50,100,'resource_acceleration'),
('G002','晶光使者','A',2.0,'亮晶晶',15,60,40,80,'training_acceleration'),
('G003','炎之剑客','S',3.0,'暖洋洋',100,20,80,50,'march_acceleration'),
('G004','烈火战士','A',2.0,'暖洋洋',80,15,60,40,'lightning_march'),
('G005','冰霜守护者','S',3.0,'冷冰冰',50,100,20,50,'defense_enhancement'),
('G006','寒冰战士','A',2.0,'冷冰冰',40,80,15,40,'iron_wall'),
('G007','森林之王','S',3.0,'郁萌萌',100,20,80,50,'attack_enhancement'),
('G008','翠绿射手','A',2.0,'郁萌萌',80,15,60,40,'battle_burst'),
('G009','太阳神使','S',3.0,'昼闪闪',20,50,80,100,'healing'),
('G010','光明祭司','A',2.0,'昼闪闪',15,40,60,80,'healing'),
('G011','暗影大师','S',3.0,'夜静静',20,80,50,100,'scout_enhancement'),
('G012','夜行者','A',2.0,'夜静静',15,60,40,80,'scout_enhancement'),
('G013','数据之王','SS',3.5,'亮晶晶',30,90,60,120,'resource_burst'),
('G014','银白之孔守护者','P',4.0,'夜静静',40,100,70,150,'scout_enhancement'),
('G015','透镜书记','B',1.5,'亮晶晶',18,55,38,72,'bishop_march_doctrine'),
('G016','白昼演算官','SS',3.5,'亮晶晶',45,105,65,135,'bright_resonance_assault'),
('G017','灼轨先锋','B',1.5,'暖洋洋',70,20,75,35,'knight_assault_doctrine'),
('G018','余烬骑将','SS',3.5,'暖洋洋',125,45,105,70,'warm_resonance_assault'),
('G019','霜垒守门人','B',1.5,'冷冰冰',35,78,25,45,'rook_assault_doctrine'),
('G020','极夜壁垒','SS',3.5,'冷冰冰',75,135,40,85,'cold_resonance_assault'),
('G021','藤弦猎手','B',1.5,'郁萌萌',75,25,68,40,'bishop_assault_doctrine'),
('G022','苍森统领','SS',3.5,'郁萌萌',135,50,100,75,'green_resonance_assault'),
('G023','晨辉医官','B',1.5,'昼闪闪',25,50,65,80,'pawn_march_doctrine'),
('G024','天穹引航者','SS',3.5,'昼闪闪',65,90,115,130,'day_resonance_assault'),
('G025','静默观测员','B',1.5,'夜静静',25,60,48,85,'scout_march_doctrine'),
('G026','深宵解码者','SS',3.5,'夜静静',70,110,80,140,'night_resonance_assault'),
('G002B','晶光使者','B',1.0,'亮晶晶',10,45,30,60,'training_acceleration_basic'),
('G004B','烈火战士','B',1.0,'暖洋洋',60,10,45,30,'lightning_march_basic'),
('G006B','寒冰战士','B',1.0,'冷冰冰',30,60,10,30,'iron_wall_basic'),
('G008B','翠绿射手','B',1.0,'郁萌萌',60,10,45,30,'battle_burst_basic'),
('G010B','光明祭司','B',1.0,'昼闪闪',10,30,45,60,'healing_basic'),
('G012B','夜行者','B',1.0,'夜静静',10,45,30,60,'scout_enhancement_basic');

-- 只处理本轮种子开始前尚未映射的模板代码；完成标记存在时目标集为空。 / Process only template codes that were unmapped before this seed run; the target set is empty once the completion marker exists.
DROP TEMPORARY TABLE IF EXISTS `fireseed_general_seed_targets`;
CREATE TEMPORARY TABLE `fireseed_general_seed_targets` (
  `template_code` varchar(64) NOT NULL,
  PRIMARY KEY (`template_code`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_general_seed_targets` (`template_code`)
SELECT seed.`template_code`
FROM `fireseed_general_template_seed` AS seed
JOIN `fireseed_resource_seed_gate` AS seed_gate
  ON seed_gate.`allowed` = 1
LEFT JOIN `general_template_catalog` AS catalog
  ON catalog.`template_code` = seed.`template_code`
WHERE catalog.`template_code` IS NULL;

INSERT INTO `generals`
(`owner_id`,`name`,`source`,`rarity`,`cost`,`element`,`level`,`hp`,`max_hp`,`attack`,`defense`,`speed`,`intelligence`,`is_active`)
SELECT
  0, seed.`name`,
  CONCAT('__resource_seed_20260717__:', seed.`template_code`),
  seed.`rarity`, seed.`cost`, seed.`element`,
  1, 100, 100, seed.`attack`, seed.`defense`, seed.`speed`, seed.`intelligence`, 1
FROM `fireseed_general_template_seed` AS seed
JOIN `fireseed_general_seed_targets` AS seed_target
  ON seed_target.`template_code` = seed.`template_code`;

INSERT INTO `general_template_catalog` (`template_code`,`general_id`)
SELECT seed.`template_code`, general.`general_id`
FROM `fireseed_general_template_seed` AS seed
JOIN `fireseed_general_seed_targets` AS seed_target
  ON seed_target.`template_code` = seed.`template_code`
JOIN `generals` AS general
  ON general.`owner_id` = 0
  AND general.`name` = seed.`name`
  AND general.`source` = CONCAT(
    '__resource_seed_20260717__:',
    seed.`template_code`
  )
  AND general.`rarity` = seed.`rarity`
;

UPDATE `generals` AS general
JOIN `general_template_catalog` AS catalog
  ON catalog.`general_id` = general.`general_id`
JOIN `fireseed_general_seed_targets` AS seed_target
  ON seed_target.`template_code` = catalog.`template_code`
SET general.`source` = '原创角色';

INSERT INTO `general_skills`
(`general_id`,`skill_type`,`skill_name`,`slot`,`skill_level`,`skill_effect`)
SELECT
  catalog.`general_id`, '自带', card.`name`, 0, 1, card.`effect_json`
FROM `general_template_catalog` AS catalog
JOIN `fireseed_general_seed_targets` AS seed_target
  ON seed_target.`template_code` = catalog.`template_code`
JOIN `fireseed_general_template_seed` AS seed
  ON seed.`template_code` = catalog.`template_code`
JOIN `skill_card_catalog` AS card
  ON card.`card_code` = seed.`skill_card_code`
LEFT JOIN `general_skills` AS existing
  ON existing.`general_id` = catalog.`general_id`
  AND existing.`slot` = 0
WHERE existing.`skill_id` IS NULL;

INSERT INTO `equipped_skill_cards` (`skill_id`,`card_id`,`equipped_at`)
SELECT skill.`skill_id`, card.`card_id`, NOW()
FROM `general_template_catalog` AS catalog
JOIN `fireseed_general_seed_targets` AS seed_target
  ON seed_target.`template_code` = catalog.`template_code`
JOIN `fireseed_general_template_seed` AS seed
  ON seed.`template_code` = catalog.`template_code`
JOIN `skill_card_catalog` AS card
  ON card.`card_code` = seed.`skill_card_code`
JOIN `general_skills` AS skill
  ON skill.`general_id` = catalog.`general_id`
  AND skill.`slot` = 0;

DROP TEMPORARY TABLE `fireseed_general_seed_targets`;
DROP TEMPORARY TABLE `fireseed_general_template_seed`;

-- 默认池沿用本项目原有非付费稀有度配置；资料站未公布抽取概率，因此这些只是可编辑的安装预设。 / Default pools preserve this project's existing non-paid rarity settings; the archive does not publish draw odds, so these are editable installation defaults.
DROP TEMPORARY TABLE IF EXISTS `fireseed_card_pool_seed`;
CREATE TEMPORARY TABLE `fireseed_card_pool_seed` LIKE `card_pools`;

INSERT INTO `fireseed_card_pool_seed`
(`pool_code`,`pool_type`,`name`,`description`,`cost_json`,`allowed_counts_json`,`status`,`sort_order`) VALUES
('general_normal','general','常规契约','以四色基础资源进行的常驻武将契约。','{"bright":100,"warm":100,"cold":100,"green":100}','[1,5,10]','published',10),
('general_advanced','general','高级契约','加入昼闪闪与夜静静资源的高级武将契约。','{"bright":500,"warm":500,"cold":500,"green":500,"day":100,"night":100}','[1,5,10]','published',20),
('general_resonance','general','回路共鸣','消耗思考回路的高阶武将契约。','{"circuit_points":5}','[1,5,10]','published',30),
('skill_standard','skill','夜静技能卡池','消耗夜静静抽取技能卡的常驻卡池。','{"night":250}','[1,5,10]','published',10);

-- 在创建前快照本轮真正缺失的预设池；已有、归档或被运营调整过的同代码池均不属于种子目标。 / Snapshot seed pools that are genuinely absent before creation; existing, archived, or operator-managed pools with the same code are never seed targets.
DROP TEMPORARY TABLE IF EXISTS `fireseed_pool_seed_targets`;
CREATE TEMPORARY TABLE `fireseed_pool_seed_targets` (
  `pool_code` varchar(64) NOT NULL,
  `pool_type` enum('general','skill') NOT NULL,
  PRIMARY KEY (`pool_code`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_pool_seed_targets` (`pool_code`,`pool_type`)
SELECT seed.`pool_code`, seed.`pool_type`
FROM `fireseed_card_pool_seed` AS seed
JOIN `fireseed_resource_seed_gate` AS seed_gate
  ON seed_gate.`allowed` = 1
LEFT JOIN `card_pools` AS existing
  ON existing.`pool_code` = seed.`pool_code`
WHERE existing.`pool_id` IS NULL;

INSERT INTO `card_pools`
(`pool_code`,`pool_type`,`name`,`description`,`cost_json`,`allowed_counts_json`,`status`,`sort_order`)
SELECT
  seed.`pool_code`, seed.`pool_type`, seed.`name`, seed.`description`,
  seed.`cost_json`, seed.`allowed_counts_json`, seed.`status`,
  seed.`sort_order`
FROM `fireseed_card_pool_seed` AS seed
JOIN `fireseed_pool_seed_targets` AS seed_target
  ON seed_target.`pool_code` = seed.`pool_code`
  AND seed_target.`pool_type` = seed.`pool_type`;

DROP TEMPORARY TABLE `fireseed_card_pool_seed`;

DROP TEMPORARY TABLE IF EXISTS `fireseed_pool_rarity_seed`;
CREATE TEMPORARY TABLE `fireseed_pool_rarity_seed` (
  `pool_code` varchar(64) NOT NULL,
  `rarity` enum('B','A','S','SS','P') NOT NULL,
  `rarity_weight` int(10) unsigned NOT NULL,
  PRIMARY KEY (`pool_code`,`rarity`)
) ENGINE=InnoDB;

INSERT INTO `fireseed_pool_rarity_seed`
(`pool_code`,`rarity`,`rarity_weight`) VALUES
('general_normal','B',7000000),
('general_normal','A',2500000),
('general_normal','S',500000),
('general_advanced','A',7000000),
('general_advanced','S',2500000),
('general_advanced','SS',500000),
('general_resonance','S',5000000),
('general_resonance','SS',3500000),
('general_resonance','P',1500000),
('skill_standard','B',5500000),
('skill_standard','A',3000000),
('skill_standard','S',1000000),
('skill_standard','SS',400000),
('skill_standard','P',100000);

-- 每张同稀有度卡初始等权，之后仅由资源后台显式调整。 / Cards within a rarity start equally weighted; later changes are explicit resource-admin actions.
INSERT INTO `general_pool_entries`
(`pool_id`,`general_id`,`weight`,`is_featured`)
SELECT
  pool.`pool_id`,
  general.`general_id`,
  GREATEST(1, ROUND(rarity_seed.`rarity_weight` / rarity_count.`card_count`)),
  0
FROM `fireseed_pool_rarity_seed` AS rarity_seed
JOIN `card_pools` AS pool
  ON pool.`pool_code` = rarity_seed.`pool_code`
  AND pool.`pool_type` = 'general'
JOIN `fireseed_pool_seed_targets` AS seed_target
  ON seed_target.`pool_code` = pool.`pool_code`
  AND seed_target.`pool_type` = 'general'
JOIN (
  SELECT `rarity`, COUNT(*) AS `card_count`
  FROM `generals`
  WHERE `owner_id` = 0
    AND `is_active` = 1
  GROUP BY `rarity`
) AS rarity_count
  ON rarity_count.`rarity` = rarity_seed.`rarity`
JOIN `generals` AS general
  ON general.`rarity` = rarity_seed.`rarity`
  AND general.`owner_id` = 0
  AND general.`is_active` = 1;

INSERT INTO `skill_pool_entries`
(`pool_id`,`card_id`,`weight`,`is_featured`)
SELECT
  pool.`pool_id`,
  card.`card_id`,
  GREATEST(1, ROUND(rarity_seed.`rarity_weight` / rarity_count.`card_count`)),
  0
FROM `fireseed_pool_rarity_seed` AS rarity_seed
JOIN `card_pools` AS pool
  ON pool.`pool_code` = rarity_seed.`pool_code`
  AND pool.`pool_type` = 'skill'
JOIN `fireseed_pool_seed_targets` AS seed_target
  ON seed_target.`pool_code` = pool.`pool_code`
  AND seed_target.`pool_type` = 'skill'
JOIN (
  SELECT `rarity`, COUNT(*) AS `card_count`
  FROM `skill_card_catalog`
  WHERE `is_active` = 1
  GROUP BY `rarity`
) AS rarity_count
  ON rarity_count.`rarity` = rarity_seed.`rarity`
JOIN `skill_card_catalog` AS card
  ON card.`rarity` = rarity_seed.`rarity`
  AND card.`is_active` = 1;

DROP TEMPORARY TABLE `fireseed_pool_seed_targets`;
DROP TEMPORARY TABLE `fireseed_pool_rarity_seed`;

INSERT INTO `game_config`
(`key`,`value`,`description`,`is_constant`,`category`)
SELECT
  'resource_catalog_seed_20260717',
  'complete',
  '资源目录与默认卡池安装种子已完成；用于阻止重跑恢复运营配置 / Resource catalog and default-pool seed completed; prevents reruns from restoring live configuration',
  1,
  'system'
FROM `fireseed_resource_seed_gate` AS seed_gate
WHERE seed_gate.`allowed` = 1;

DROP TEMPORARY TABLE `fireseed_resource_seed_gate`;
COMMIT;

INSERT IGNORE INTO `shop_catalog`
(`item_code`,`name`,`description`,`cost_json`,`grant_json`,`daily_limit`) VALUES
('break_core','蜕变核心','用于武将 BREAK 的通用素材。','{"bright":3000,"night":500}','{"items":{"break_core":1}}',5),
('skill_notes','技能札记','获得用于技能升级的技能点。','{"night":800}','{"wallet":{"skill_points":10}}',10),
('field_rations','战地补给','补充六色基础资源。','{"bright":1000}','{"resources":{"warm":1000,"cold":1000,"green":1000,"day":1000}}',5),
('circuit_fragment','回路碎片','获得一点思考回路。','{"bright":5000,"night":1000}','{"circuit_points":1}',1),
('arena_supply','竞技补给','使用竞技场代币兑换蜕变核心。','{"arena_tokens":50}','{"items":{"break_core":1}}',3),
('merit_supply','功勋补给','使用战斗功勋兑换前线资源。','{"merit_points":25}','{"resources":{"bright":1000,"night":300}}',5);

INSERT IGNORE INTO `quest_definitions`
(`quest_code`,`name`,`description`,`event_type`,`target_value`,`reset_cycle`,`reward_json`) VALUES
('daily_recruit','今日契约','今日完成一次武将招募。','general_recruited',1,'daily','{"resources":{"bright":500},"wallet":{"skill_points":2}}'),
('daily_skill_draw','夜色寻卡','今日抽取一次技能卡。','skill_card_drawn',1,'daily','{"resources":{"night":250},"wallet":{"skill_points":2}}'),
('daily_battle','边界巡弋','今日完成一次战斗。','battle_completed',1,'daily','{"resources":{"warm":300,"cold":300,"green":300,"day":300},"wallet":{"merit_points":5}}'),
('weekly_territory','拓界者','本周占领五块领地。','territory_captured',5,'weekly','{"circuit_points":1,"items":{"break_core":1}}'),
('main_gateway','十二门试炼','占领任意一座 NPC 主城。','gateway_captured',1,'none','{"resources":{"bright":10000,"night":3000},"wallet":{"skill_points":20}}'),
('main_tower','数据高塔','通过战斗之塔第十层。','tower_floor_cleared',10,'none','{"items":{"break_core":2},"wallet":{"arena_tokens":50}}');

INSERT IGNORE INTO `achievement_definitions`
(`achievement_code`,`name`,`description`,`event_type`,`target_value`,`reward_json`) VALUES
('first_contract','最初的契约','获得第一名武将。','general_recruited',1,'{"resources":{"bright":1000}}'),
('collector_10','十人阵列','累计获得十名武将。','general_recruited',10,'{"items":{"break_core":1}}'),
('territory_25','数据拓荒者','累计占领二十五块领地。','territory_captured',25,'{"circuit_points":2}'),
('battle_wins_10','边界常胜','累计赢得十场战斗。','battle_won',10,'{"wallet":{"merit_points":100}}'),
('alliance_join','并肩钻探','加入一个联盟。','alliance_joined',1,'{"resources":{"bright":1000,"night":500}}'),
('break_once','第一次蜕变','完成一次武将 BREAK。','general_broken',1,'{"wallet":{"skill_points":20}}'),
('tower_25','塔上观海','通过战斗之塔第二十五层。','tower_floor_cleared',25,'{"items":{"break_core":3}}'),
('raid_hero','潮汐破阵','在讨伐战累计造成十万伤害。','raid_damage',100000,'{"wallet":{"arena_tokens":100}}');

INSERT IGNORE INTO `seasons` (`season_number`,`status`,`started_at`)
VALUES (1,'active',NOW());

INSERT INTO `raid_events`
(`name`,`description`,`max_hp`,`current_hp`,`defense_power`,`starts_at`,`ends_at`,`status`,`reward_json`)
SELECT
  '数据潮汐·零号',
  '来自数据海深层的周期性聚合体。所有钻探者均可贡献伤害。',
  1000000,
  1000000,
  500,
  NOW(),
  DATE_ADD(NOW(), INTERVAL 7 DAY),
  'active',
  '{"resources":{"bright":2000,"night":500},"items":{"break_core":1}}'
WHERE NOT EXISTS (
  SELECT 1 FROM `raid_events` WHERE `name` = '数据潮汐·零号'
);

INSERT IGNORE INTO `game_config`
(`key`,`value`,`description`,`is_constant`,`category`) VALUES
('vassal_release_resource_rate','0.70','附属玩家主动脱离时缴纳当前每类资源的比例（0-1） / Share of every stored resource paid on voluntary release (0-1)',0,'vassalage'),
('vassal_release_relocation_mode','outer','主动脱离后的主城迁移模式：outer=外围，middle=中围，subbase=随机既有分基地 / Main-city relocation after release: outer, middle, or a random existing sub-base',0,'vassalage'),
('vassal_release_lose_all_territory','1','主动脱离后是否失去全部普通领地和未保留分基地（0/1） / Whether release removes all ordinary territory and unkept sub-bases (0/1)',0,'vassalage'),
('vassal_release_refund_circuit','1','清除普通领地时是否全额返还其占用的思考回路（0/1，返还可暂时超过持有上限） / Whether removed ordinary territory fully refunds its occupied Circuit Points (0/1; refunds may temporarily exceed the holding cap)',0,'vassalage');
