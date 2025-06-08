<?php
// 种火集结号 - 初始化文件
// 包含所有必要的配置和类

// 包含配置文件
require_once 'config/config.php';

// 包含数据库连接类
require_once 'includes/database.php';

// 包含核心类
require_once 'includes/classes/User.php';
require_once 'includes/classes/Resource.php';
require_once 'includes/classes/Map.php';
require_once 'includes/classes/MapGenerator.php';
require_once 'includes/classes/ResourceCollector.php';
require_once 'includes/classes/City.php';
require_once 'includes/classes/Facility.php';
require_once 'includes/classes/Soldier.php';
require_once 'includes/classes/General.php';
require_once 'includes/classes/GeneralSkill.php';
require_once 'includes/classes/GeneralAssignment.php';
require_once 'includes/classes/Technology.php';
require_once 'includes/classes/UserTechnology.php';
require_once 'includes/classes/Army.php';
require_once 'includes/classes/Battle.php';

// 包含辅助函数
require_once 'includes/functions.php';

// 获取数据库连接
$db = Database::getInstance()->getConnection();

// 加载游戏变量
loadGameVariables($db);

// 检查用户会话
if (isset($_SESSION['user_id'])) {
    $user = new User($_SESSION['user_id']);
    if (!$user->isValid()) {
        // 用户不存在或已被删除，清除会话
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
}
