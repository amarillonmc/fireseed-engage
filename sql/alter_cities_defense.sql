-- 种火集结号 - 修改cities表，添加防御策略字段

ALTER TABLE cities
ADD COLUMN defense_strategy ENUM('defense', 'balanced', 'production') NOT NULL DEFAULT 'balanced';
