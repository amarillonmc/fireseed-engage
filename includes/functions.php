<?php
// 种火集结号 - 辅助函数

/**
 * 格式化数字
 * @param int $number 要格式化的数字
 * @return string 格式化后的数字
 */
function formatNumber($number) {
    return number_format($number);
}

/**
 * 格式化时间
 * @param int $seconds 秒数
 * @return string 格式化后的时间（HH:MM:SS）
 */
function formatTime($seconds) {
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

/**
 * 获取士兵名称
 * @param string $type 士兵类型
 * @return string 士兵名称
 */
function getSoldierName($type) {
    switch ($type) {
        case 'pawn':
            return '兵卒';
        case 'knight':
            return '骑士';
        case 'rook':
            return '城壁';
        case 'bishop':
            return '主教';
        case 'golem':
            return '锤子兵';
        case 'scout':
            return '侦察兵';
        default:
            return '未知士兵';
    }
}

/**
 * 获取设施名称
 * @param string $type 设施类型
 * @param string $subtype 设施子类型
 * @return string 设施名称
 */
function getFacilityName($type, $subtype = null) {
    switch ($type) {
        case 'resource_production':
            switch ($subtype) {
                case 'bright':
                    return '亮晶晶产出点';
                case 'warm':
                    return '暖洋洋产出点';
                case 'cold':
                    return '冷冰冰产出点';
                case 'green':
                    return '郁萌萌产出点';
                case 'day':
                    return '昼闪闪产出点';
                case 'night':
                    return '夜静静产出点';
                default:
                    return '资源产出点';
            }
        case 'governor_office':
            return '总督府';
        case 'barracks':
            return '兵营';
        case 'research_lab':
            return '研究所';
        case 'dormitory':
            return '宿舍';
        case 'storage':
            return '贮存所';
        case 'watchtower':
            return '瞭望台';
        case 'workshop':
            return '工程所';
        default:
            return '未知设施';
    }
}

/**
 * 获取资源名称
 * @param string $type 资源类型
 * @return string 资源名称
 */
function getResourceName($type) {
    switch ($type) {
        case 'bright':
            return '亮晶晶';
        case 'warm':
            return '暖洋洋';
        case 'cold':
            return '冷冰冰';
        case 'green':
            return '郁萌萌';
        case 'day':
            return '昼闪闪';
        case 'night':
            return '夜静静';
        default:
            return '未知资源';
    }
}

/**
 * 计算两点之间的距离
 * @param int $x1 起点X坐标
 * @param int $y1 起点Y坐标
 * @param int $x2 终点X坐标
 * @param int $y2 终点Y坐标
 * @return float 距离
 */
function calculateDistance($x1, $y1, $x2, $y2) {
    return sqrt(pow($x2 - $x1, 2) + pow($y2 - $y1, 2));
}

/**
 * 生成随机字符串
 * @param int $length 字符串长度
 * @return string 随机字符串
 */
function generateRandomString($length = 10) {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}
