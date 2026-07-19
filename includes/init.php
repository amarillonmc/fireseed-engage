<?php
// 种火集结号 - 初始化文件 / Fireseed Engage - Application bootstrap

// 使用绝对路径，确保 Web、定时任务和维护脚本从任意工作目录启动。 / Use absolute paths so web, cron, and maintenance entry points work from any current directory.
$projectRoot = dirname(__DIR__);

// 包含配置文件 / Include configuration
require_once $projectRoot . '/config/config.php';

// 包含数据库连接类 / Include the database connection
require_once $projectRoot . '/includes/database.php';

// 包含核心类 / Include core classes
require_once $projectRoot . '/includes/classes/User.php';
require_once $projectRoot . '/includes/classes/Resource.php';
require_once $projectRoot . '/includes/classes/Map.php';
require_once $projectRoot . '/includes/classes/MapGenerator.php';
require_once $projectRoot . '/includes/classes/ResourceCollector.php';
require_once $projectRoot . '/includes/classes/City.php';
require_once $projectRoot . '/includes/classes/Facility.php';
require_once $projectRoot . '/includes/classes/Soldier.php';
require_once $projectRoot . '/includes/classes/General.php';
require_once $projectRoot . '/includes/classes/GeneralSkill.php';
require_once $projectRoot . '/includes/classes/GeneralAssignment.php';
require_once $projectRoot . '/includes/classes/TechnologyEffectService.php';
require_once $projectRoot . '/includes/classes/Technology.php';
require_once $projectRoot . '/includes/classes/UserTechnology.php';
require_once $projectRoot . '/includes/classes/Army.php';
require_once $projectRoot . '/includes/classes/Battle.php';
require_once $projectRoot . '/includes/classes/AdminManager.php';
require_once $projectRoot . '/includes/classes/GameConfig.php';
require_once $projectRoot . '/includes/classes/AuthSecurity.php';
require_once $projectRoot . '/includes/classes/GameRules.php';
require_once $projectRoot . '/includes/classes/CardPoolService.php';
require_once $projectRoot . '/includes/classes/VassalService.php';
require_once $projectRoot . '/includes/classes/EconomyService.php';
require_once $projectRoot . '/includes/classes/ProgressService.php';
require_once $projectRoot . '/includes/classes/RecruitmentService.php';
require_once $projectRoot . '/includes/classes/SkillCardService.php';
require_once $projectRoot . '/includes/classes/GeneralProgression.php';
require_once $projectRoot . '/includes/classes/AllianceService.php';
require_once $projectRoot . '/includes/classes/SocialService.php';
require_once $projectRoot . '/includes/classes/ChallengeService.php';
require_once $projectRoot . '/includes/classes/SeasonService.php';
require_once $projectRoot . '/includes/classes/TerritoryGarrisonService.php';
require_once $projectRoot . '/includes/classes/ScoutingService.php';
require_once $projectRoot . '/includes/classes/SubBaseService.php';

// 包含辅助函数 / Include helper functions
require_once $projectRoot . '/includes/functions.php';

// 包含统一图像资源与 Emoji 回退辅助函数 / Include unified image-resource and Emoji-fallback helpers
require_once $projectRoot . '/includes/image_resources.php';

// 包含可拼装武将卡界面辅助函数 / Include composable general-card UI helpers
require_once $projectRoot . '/includes/general_card_ui.php';

// 获取数据库连接
$db = Database::getInstance()->getConnection();

// 加载游戏变量
loadGameVariables($db);

// 检查用户会话 / Validate the authenticated user session
$sessionUser = null;
if (isset($_SESSION['user_id'])) {
    $user = new User($_SESSION['user_id']);
    $sessionUser = $user;
    if (!$user->isValid()) {
        // 用户不存在或已被删除，清除会话 / Clear a session whose user no longer exists
        session_unset();
        session_destroy();
        header('Location: login.php');
        exit;
    }
}

// 维护期间仅管理员、后台登录和登出流程可用 / During maintenance only administrators, admin login, and logout remain available
AuthSecurity::enforceMaintenanceMode($sessionUser);

unset($projectRoot);
