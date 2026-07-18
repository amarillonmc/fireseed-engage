<?php
// 种火集结号 - 武将卡视觉与页面接入规则测试 / Fireseed Engage - General-card visuals and page-integration rule tests

$assertions = 0;

/**
 * 断言武将卡视觉规则 / Assert a general-card visual rule
 *
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertGeneralCardVisualRule($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

/**
 * 测试替身：提供可切换的图像模式 / Test double: provide a mutable image mode
 */
class GameConfig {
    public static $imageMode = 'image';

    /**
     * 读取测试配置 / Read a test setting
     *
     * @param string $key 配置键 / Setting key
     * @param mixed $default 默认值 / Default value
     * @return mixed 配置值 / Setting value
     */
    public static function get($key, $default = null) {
        return $key === 'image_display_mode'
            ? self::$imageMode
            : $default;
    }
}

/**
 * 测试替身：按生产规则转义 HTML / Test double: escape HTML like production
 *
 * @param mixed $value 待转义值 / Value to escape
 * @return string 已转义值 / Escaped value
 */
function escapeHtml($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

define('SITE_URL', 'http://localhost/fireseed-engage');
require_once dirname(__DIR__) . '/includes/image_resources.php';
require_once dirname(__DIR__) . '/includes/general_card_ui.php';

assertGeneralCardVisualRule(
    normalizeGeneralArtworkCode('G001') === 'G001'
        && normalizeGeneralArtworkCode('G001B') === 'G001'
        && normalizeGeneralArtworkCode(' G014 ') === 'G014',
    'G001, G001B, and G014 must normalize to available artwork'
);
assertGeneralCardVisualRule(
    normalizeGeneralArtworkCode('G015') === null
        && normalizeGeneralArtworkCode('../G001') === null
        && normalizeGeneralArtworkCode('G001/../../config') === null
        && normalizeGeneralArtworkCode([]) === null,
    'Unknown codes, paths, and non-scalars must be rejected'
);

$general = [
    'general_id' => 17,
    'template_code' => 'G001B',
    'name' => '银辉女王',
    'source' => '测试阵营',
    'rarity' => 'SS',
    'cost' => 3.5,
    'element' => '夜静静',
    'level' => 12,
    'hp' => 987,
    'max_hp' => 1200,
    'attack' => 1234,
    'defense' => 567,
    'speed' => 89,
    'intelligence' => 42,
    'skill_name' => '星渊号令'
];

GameConfig::$imageMode = 'image';
$imageCard = renderGeneralCardVisual($general);
assertGeneralCardVisualRule(
    strpos(
        $imageCard,
        'general_g001_portrait_512x768.webp'
    ) !== false
        && strpos(
            $imageCard,
            'general_g001_portrait_1024x1536.png'
        ) !== false
        && strpos(
            $imageCard,
            'card_frame_ss_400x600.webp'
        ) !== false
        && strpos(
            $imageCard,
            'card_frame_ss_800x1200.png'
        ) !== false,
    'Image mode must compose the normalized portrait and rarity frame'
);
assertGeneralCardVisualRule(
    strpos($imageCard, 'general-card-composite rarity-ss element-night') !== false
        && strpos($imageCard, '银辉女王') !== false
        && strpos($imageCard, 'Lv.12') !== false
        && strpos($imageCard, 'COST 3.5') !== false
        && strpos($imageCard, '1,234') !== false
        && strpos($imageCard, '567') !== false
        && strpos($imageCard, '89') !== false
        && strpos($imageCard, '42') !== false
        && strpos($imageCard, '星渊号令') !== false,
    'The composed card must render its dynamic identity, level, cost, stats, and skill'
);
assertGeneralCardVisualRule(
    substr_count($imageCard, '<picture') >= 4
        && strpos($imageCard, '<source type="image/webp"') !== false
        && strpos(
            $imageCard,
            '<article class="general-card-composite rarity-ss element-night image-failed"'
        ) === false,
    'A complete image card must include WebP/PNG picture layers without forced fallback'
);

GameConfig::$imageMode = 'emoji_fallback';
$fallbackCard = renderGeneralCardVisual($general);
assertGeneralCardVisualRule(
    strpos($fallbackCard, '<picture') === false
        && strpos($fallbackCard, '<img') === false
        && strpos($fallbackCard, 'general_g001_portrait') === false
        && strpos($fallbackCard, 'card_frame_ss') === false,
    'Emoji fallback mode must not emit portrait, frame, or icon image requests'
);
assertGeneralCardVisualRule(
    strpos($fallbackCard, 'image-failed') !== false
        && strpos($fallbackCard, '👤') !== false
        && strpos($fallbackCard, '🌙 夜静静') !== false
        && strpos($fallbackCard, '银辉女王') !== false,
    'Emoji fallback mode must preserve a complete readable card identity'
);

GameConfig::$imageMode = 'image';
$compactIdentity = renderGeneralCompactIdentity($general);
assertGeneralCardVisualRule(
    strpos($compactIdentity, '<picture') !== false
        && strpos(
            $compactIdentity,
            'general_g001_portrait_512x768.webp'
        ) !== false
        && strpos(
            $compactIdentity,
            'general_g001_portrait_512x768.png'
        ) !== false
        && strpos(
            $compactIdentity,
            'general_g001_portrait_1024x1536.webp'
        ) !== false
        && strpos(
            $compactIdentity,
            'general_g001_portrait_1024x1536.png'
        ) !== false,
    'Compact identities must retain WebP, PNG, and high-DPI portrait variants'
);

GameConfig::$imageMode = 'emoji_fallback';
$compactFallback = renderGeneralCompactIdentity($general);
assertGeneralCardVisualRule(
    strpos($compactFallback, '<picture') === false
        && strpos($compactFallback, '<img') === false
        && strpos($compactFallback, '👤') !== false,
    'Compact identities must make no image request in Emoji fallback mode'
);

GameConfig::$imageMode = 'image';
$hostileCard = renderGeneralCardVisual([
    'template_code' => 'G014',
    'name' => '<script>alert("name")</script>',
    'rarity' => 'P',
    'element' => '昼闪闪',
    'skill_name' => '</div><img src=x onerror=alert(1)>'
]);
assertGeneralCardVisualRule(
    strpos($hostileCard, '<script>') === false
        && strpos($hostileCard, '<img src=x') === false
        && strpos($hostileCard, '</div><img') === false
        && strpos(
            $hostileCard,
            '&lt;script&gt;alert(&quot;name&quot;)&lt;/script&gt;'
        ) !== false
        && strpos(
            $hostileCard,
            '&lt;img src=x onerror=alert(1)&gt;'
        ) !== false,
    'General names and skill names must be HTML-escaped'
);

$manifestPath = dirname(__DIR__) . '/assets/images/manifest.json';
$manifestJson = file_get_contents($manifestPath);
$manifest = $manifestJson === false
    ? null
    : json_decode($manifestJson, true);
assertGeneralCardVisualRule(
    is_array($manifest)
        && isset($manifest['assets'])
        && is_array($manifest['assets'])
        && count($manifest['assets']) === 64,
    'The generated manifest must expose exactly 64 semantic assets'
);

$allCardManifestAssetsExist = is_array($manifest)
    && isset($manifest['assets'])
    && is_array($manifest['assets']);
if ($allCardManifestAssetsExist) {
    foreach (['b', 'a', 's', 'ss', 'p'] as $rarity) {
        $key = 'card_frame_' . $rarity;
        if (!isset($manifest['assets'][$key]['variants'])
            || count($manifest['assets'][$key]['variants']) !== 2) {
            $allCardManifestAssetsExist = false;
            break;
        }
    }
}
if ($allCardManifestAssetsExist) {
    for ($number = 1; $number <= 14; $number++) {
        $key = 'general_g'
            . str_pad((string) $number, 3, '0', STR_PAD_LEFT)
            . '_portrait';
        if (!isset($manifest['assets'][$key]['variants'])
            || count($manifest['assets'][$key]['variants']) !== 2) {
            $allCardManifestAssetsExist = false;
            break;
        }
    }
}
assertGeneralCardVisualRule(
    $allCardManifestAssetsExist
        && isset($manifest['card_canvas']['aspect_ratio'])
        && $manifest['card_canvas']['aspect_ratio'] === '2:3',
    'The manifest must include all five frame sets, fourteen portraits, and the 2:3 canvas'
);

/**
 * 读取项目文件供静态接入断言 / Read a project file for static integration assertions
 *
 * @param string $relativePath 项目相对路径 / Project-relative path
 * @return string 文件内容 / File contents
 */
function readGeneralCardIntegrationFile($relativePath) {
    $contents = file_get_contents(
        dirname(__DIR__) . '/' . ltrim((string) $relativePath, '/')
    );
    return $contents === false ? '' : $contents;
}

$initSource = readGeneralCardIntegrationFile('includes/init.php');
assertGeneralCardVisualRule(
    strpos($initSource, "includes/image_resources.php") !== false
        && strpos($initSource, "includes/general_card_ui.php") !== false,
    'The application bootstrap must load both shared image renderers'
);

$generalsSource = readGeneralCardIntegrationFile('generals.php');
$detailSource = readGeneralCardIntegrationFile('general_detail.php');
$recruitSource = readGeneralCardIntegrationFile('recruit.php');
assertGeneralCardVisualRule(
    strpos($generalsSource, 'renderGeneralCardVisual(') !== false
        && strpos($generalsSource, 'renderGameplayResourceBar(') !== false
        && strpos($detailSource, 'renderGeneralCardVisual(') !== false
        && strpos($detailSource, 'renderGameplayResourceBar(') !== false
        && substr_count($recruitSource, 'renderGeneralCardVisual(') >= 2
        && strpos($recruitSource, 'renderGeneralCompactIdentity(') !== false,
    'General list, detail, recruitment results, starters, and pool entries must use shared renderers'
);

$indexSource = readGeneralCardIntegrationFile('index.php');
$mapSource = readGeneralCardIntegrationFile('map.php');
$mainScript = readGeneralCardIntegrationFile('assets/js/script.js');
$mapScript = readGeneralCardIntegrationFile('assets/js/map.js');
assertGeneralCardVisualRule(
    strpos($indexSource, 'getImageResourceClientConfig([') !== false
        && strpos($indexSource, 'window.FIRESEED_IMAGE_RESOURCES') !== false
        && strpos($mapSource, 'getImageResourceClientConfig([') !== false
        && strpos($mapSource, 'window.FIRESEED_IMAGE_RESOURCES') !== false,
    'Dynamic city and map pages must receive an allowlisted shared image configuration'
);
assertGeneralCardVisualRule(
    strpos($mainScript, 'window.FIRESEED_IMAGE_RESOURCES') !== false
        && strpos($mainScript, 'createConfiguredImageResource(') !== false
        && strpos($mainScript, "document.createElement('picture')") !== false
        && strpos($mainScript, "addEventListener('error'") !== false
        && strpos($mapScript, 'window.FIRESEED_IMAGE_RESOURCES') !== false
        && strpos($mapScript, 'createMapIconNode(') !== false
        && strpos($mapScript, "document.createElement('picture')") !== false
        && strpos($mapScript, "addEventListener('error'") !== false,
    'Dynamic JavaScript renderers must consume shared config with picture and per-item fallback'
);

echo "General-card visual rule tests passed: {$assertions} assertions.\n";
