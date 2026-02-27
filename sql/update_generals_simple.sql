-- 种火集结号 - 更新 generals 表以支持通用武将池（简化版）
-- 更新日期：2025-02-26
-- 说明：修改 generals 表的 owner_id 字段，允许值为 0（通用武将池）

-- 1. 删除原有的外键约束
ALTER TABLE `generals` DROP FOREIGN KEY IF EXISTS `generals_ibfk_1`;

-- 2. 修改 owner_id 字段允许 0 值且无外键约束
ALTER TABLE `generals` MODIFY COLUMN `owner_id` int(11) NOT NULL DEFAULT 0;

-- 3. 添加索引以优化查询
ALTER TABLE `generals` ADD KEY `idx_owner_id` (`owner_id`);
