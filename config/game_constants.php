<?php
// 种火集结号 - 游戏常量配置文件
// 包含游戏中的各种常量，游戏进行时无法修改

// 地图设置
define('MAP_WIDTH', 512);
define('MAP_HEIGHT', 512);
define('MAP_CENTER_X', 256);
define('MAP_CENTER_Y', 256);

// 资源设置
define('RESOURCE_PRODUCTION_INTERVAL', 3); // 每3秒产出1点资源
define('INITIAL_RESOURCE_STORAGE', 100000); // 初始资源存储上限
define('STORAGE_FACILITY_CAPACITY', 100000); // 每个贮存所增加的存储上限
define('STORAGE_LEVEL_COEFFICIENT', 1.5); // 贮存所每级增加的系数

// 思考回路设置
define('CIRCUIT_PRODUCTION_INTERVAL', 172800); // 每48小时产出1点思考回路

// 士兵设置
// 士兵训练时间（秒）
define('PAWN_TRAINING_TIME', 1);
define('KNIGHT_TRAINING_TIME', 5);
define('ROOK_TRAINING_TIME', 5);
define('BISHOP_TRAINING_TIME', 5);
define('GOLEM_TRAINING_TIME', 30);
define('SCOUT_TRAINING_TIME', 2);

// 士兵移动速度（秒/格）
define('PAWN_MOVEMENT_SPEED', 2);
define('KNIGHT_MOVEMENT_SPEED', 1);
define('ROOK_MOVEMENT_SPEED', 5);
define('BISHOP_MOVEMENT_SPEED', 3);
define('GOLEM_MOVEMENT_SPEED', 30);
define('SCOUT_MOVEMENT_SPEED', 2);

// 士兵对兵攻击力
define('PAWN_ATTACK', 1);
define('KNIGHT_ATTACK', 2);
define('ROOK_ATTACK', 2);
define('BISHOP_ATTACK', 4);
define('GOLEM_ATTACK', 1);

// 士兵对城池攻击力
define('PAWN_CITY_ATTACK', 1);
define('KNIGHT_CITY_ATTACK', 2);
define('ROOK_CITY_ATTACK', 2);
define('BISHOP_CITY_ATTACK', 2);
define('GOLEM_CITY_ATTACK', 10);

// 士兵防御力
define('PAWN_DEFENSE', 1);
define('KNIGHT_DEFENSE', 2);
define('ROOK_DEFENSE', 4);
define('BISHOP_DEFENSE', 2);
define('GOLEM_DEFENSE', 1);

// NPC设置
define('NPC_FORT_BASE_DURABILITY', 3000); // 1级NPC城池的耐久度
define('NPC_FORT_LEVEL_COEFFICIENT', 1.5); // NPC城池每级增加的耐久度系数
define('NPC_FORT_BASE_GARRISON', 1000); // 1级NPC城池的驻军数量
define('NPC_FORT_GARRISON_COEFFICIENT', 2.5); // NPC城池每级增加的驻军系数
define('NPC_RESOURCE_POINT_GARRISON', 100); // 每种资源对应的驻军数量
define('NPC_STRENGTH_COEFFICIENT', 1.0); // NPC强度系数

// 游戏结束设置
define('VICTORY_OCCUPATION_DAYS', 30); // 占领银白之孔需要的天数
