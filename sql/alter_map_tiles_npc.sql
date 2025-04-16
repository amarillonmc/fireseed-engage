-- 种火集结号 - 修改map_tiles表，添加NPC城池相关字段

ALTER TABLE map_tiles
ADD COLUMN npc_level INT DEFAULT 1,
ADD COLUMN npc_garrison INT DEFAULT 0,
ADD COLUMN npc_respawn_time DATETIME DEFAULT NULL;
