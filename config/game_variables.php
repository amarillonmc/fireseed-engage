<?php
// 种火集结号 - 游戏变量配置文件
// 包含游戏中的各种变量，游戏进行时可以修改

// 这些值会从数据库中的game_config表读取
// 这里设置默认值

// 技能冷却修正倍率
$GLOBALS['SKILL_COOLDOWN_MODIFIER'] = 1.0;

// 兵种攻击力修正倍率
$GLOBALS['SOLDIER_ATTACK_MODIFIER'] = 1.0;

// 兵种防御力修正倍率
$GLOBALS['SOLDIER_DEFENSE_MODIFIER'] = 1.0;

// 军队移动速度修正倍率
$GLOBALS['ARMY_MOVEMENT_SPEED_MODIFIER'] = 1.0;

// 武将HP回复倍率
$GLOBALS['GENERAL_HP_RECOVERY_MODIFIER'] = 1.0;

// 科技研究倍率
$GLOBALS['TECHNOLOGY_RESEARCH_MODIFIER'] = 1.0;

// 技能冷却倍率
$GLOBALS['SKILL_COOLDOWN_RATE_MODIFIER'] = 1.0;

// 从数据库加载游戏变量
function loadGameVariables($db) {
    $query = "SELECT `key`, `value` FROM game_config WHERE is_constant = 0";
    $result = $db->query($query);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $key = strtoupper($row['key']);
            $value = $row['value'];
            
            // 将数据库中的值赋给全局变量
            $GLOBALS[$key] = $value;
        }
    }
}

// 更新游戏变量
function updateGameVariable($db, $key, $value) {
    $key = strtolower($key);
    $query = "UPDATE game_config SET `value` = ? WHERE `key` = ? AND is_constant = 0";
    $stmt = $db->prepare($query);
    $stmt->bind_param('ss', $value, $key);
    $result = $stmt->execute();
    $stmt->close();
    
    if ($result) {
        // 更新全局变量
        $GLOBALS[strtoupper($key)] = $value;
        return true;
    }
    
    return false;
}
