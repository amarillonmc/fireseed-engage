-- 种火集结号 - 更新 generals 表以支持通用武将池
-- 更新日期：2025-02-26
-- 说明：修改 generals 表的 owner_id 字段，允许值为 0（通用武将池）

-- 1. 删除原有的外键约束
ALTER TABLE `generals` DROP FOREIGN KEY `generals_ibfk_1`;

-- 2. 修改 owner_id 字段，允许 0 值
ALTER TABLE `generals` MODIFY COLUMN `owner_id` int(11) NOT NULL DEFAULT 0;

-- 3. 添加新的外键约束，当用户被删除时将 owner_id 设为 0
ALTER TABLE `generals` ADD CONSTRAINT `generals_ibfk_1` 
    FOREIGN KEY (`owner_id`) REFERENCES `users` (`user_id`) 
    ON DELETE SET NULL;

-- 4. 修改字段为允许 NULL
ALTER TABLE `generals` MODIFY COLUMN `owner_id` int(11) NULL;

-- 5. 将 NULL 值更新为 0
UPDATE `generals` SET `owner_id` = 0 WHERE `owner_id` IS NULL;

-- 6. 重新设置为 NOT NULL
ALTER TABLE `generals` MODIFY COLUMN `owner_id` int(11) NOT NULL DEFAULT 0;

-- 7. 删除外键约束，改用触发器处理
ALTER TABLE `generals` DROP FOREIGN KEY `generals_ibfk_1`;

-- 8. 添加新的触发器，在用户删除时将相关武将的 owner_id 设为 0
DELIMITER //
CREATE TRIGGER `before_user_delete_general` 
BEFORE DELETE ON `users` 
FOR EACH ROW 
BEGIN
    UPDATE `generals` SET `owner_id` = 0 WHERE `owner_id` = OLD.user_id;
END //
DELIMITER ;

-- 更新 general_skills 表，添加 slot 字段（如果不存在）
ALTER TABLE `general_skills` 
ADD COLUMN IF NOT EXISTS `slot` int(11) NOT NULL DEFAULT 0 AFTER `skill_name`;
