-- 种火集结号 - 修改地图格子表，添加资源收集相关字段

ALTER TABLE map_tiles
ADD COLUMN last_collection_time DATETIME DEFAULT NULL,
ADD COLUMN collection_efficiency INT DEFAULT 100;
