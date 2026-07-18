<?php
// 种火集结号 - 图像资源显示与回退规则测试 / Fireseed Engage - Image-resource display and fallback rule tests

$assertions = 0;

/**
 * 断言图像资源规则 / Assert an image-resource rule
 *
 * @param bool $condition 条件 / Condition
 * @param string $message 失败信息 / Failure message
 * @return void
 */
function assertImageResourceRule($condition, $message) {
    global $assertions;
    $assertions++;
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

/**
 * 测试替身：提供可切换的游戏配置 / Test double: provide mutable game configuration
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
$imageStylesheet = file_get_contents(
    dirname(__DIR__) . '/assets/css/style.css'
);

assertImageResourceRule(
    normalizeImageDisplayMode('image') === 'image'
        && normalizeImageDisplayMode('emoji_fallback') === 'emoji_fallback',
    'Both supported image modes must normalize unchanged'
);
assertImageResourceRule(
    normalizeImageDisplayMode('invalid') === 'image'
        && normalizeImageDisplayMode([]) === 'image',
    'Invalid image modes must fail closed to the installation default'
);
assertImageResourceRule(
    getImageDisplayMode() === 'image' && isImageDisplayEnabled(),
    'The default test mode must enable formal images'
);

GameConfig::$imageMode = 'emoji_fallback';
assertImageResourceRule(
    getImageDisplayMode() === 'emoji_fallback'
        && !isImageDisplayEnabled(),
    'The explicit fallback mode must disable formal images'
);
GameConfig::$imageMode = 'image';

$manifest = getImageResourceManifest();
assertImageResourceRule(
    isset($manifest['resource_bright_crystal'])
        && isset($manifest['facility_barracks'])
        && isset($manifest['soldier_knight'])
        && isset($manifest['general_g014_portrait'])
        && isset($manifest['card_frame_p']),
    'The manifest must expose every major generated-resource family'
);
assertImageResourceRule(
    count($manifest['resource_bright_crystal']['variants']) === 2
        && $manifest['resource_bright_crystal']['variants'][0]['webp'] !== null,
    'Verified resource entries must retain PNG and WebP density variants'
);

$allVariantsExist = true;
$root = dirname(__DIR__);
foreach ($manifest as $asset) {
    foreach ($asset['variants'] as $variant) {
        $pngPath = $root . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $variant['png']);
        $webpPath = empty($variant['webp'])
            ? null
            : $root . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $variant['webp']);
        if (!is_file($pngPath)
            || ($webpPath !== null && !is_file($webpPath))) {
            $allVariantsExist = false;
            break 2;
        }
    }
}
assertImageResourceRule(
    $allVariantsExist,
    'Every variant returned by the server manifest must exist on disk'
);

assertImageResourceRule(
    normalizeImageResourceRelativePath(
        'assets/images/../config/config.php'
    ) === null
        && normalizeImageResourceRelativePath('/assets/images/icon.png') === null
        && normalizeImageResourceRelativePath(
            'assets/images/resources/resource_bright_crystal_32x32.png',
            'png'
        ) !== null,
    'Resource paths must reject traversal and absolute paths'
);

$resourceUrl = getImageResourceUrl(
    'assets/images/resources/resource_bright_crystal_32x32.png'
);
assertImageResourceRule(
    strpos(
        $resourceUrl,
        '/fireseed-engage/assets/images/resources/'
    ) === 0
        && strpos($resourceUrl, '../') === false,
    'Resource URLs must work from root and admin pages without parent traversal'
);

$imageHtml = renderImageResource(
    'resource_bright_crystal',
    32,
    ['mode' => 'image', 'loading' => 'eager']
);
assertImageResourceRule(
    strpos($imageHtml, '<picture>') !== false
        && strpos($imageHtml, '<source type="image/webp"') !== false
        && strpos($imageHtml, '<img src="') !== false
        && strpos($imageHtml, '.png') !== false,
    'Image mode must render WebP picture source with PNG compatibility'
);
assertImageResourceRule(
    strpos($imageHtml, 'onerror="this.parentElement.hidden=true;') !== false
        && strpos($imageHtml, 'image-resource-fallback" hidden') !== false
        && strpos($imageHtml, '⚪') !== false,
    'Image mode must include browser-error Emoji fallback'
);
assertImageResourceRule(
    is_string($imageStylesheet)
        && strpos($imageStylesheet, '.game-image-resource [hidden]') !== false
        && strpos($imageStylesheet, '.general-identity [hidden]') !== false
        && strpos($imageStylesheet, 'display: none !important;') !== false,
    'Scoped CSS must preserve hidden state while switching image fallbacks'
);

$fallbackHtml = renderImageResource(
    'resource_bright_crystal',
    32,
    ['mode' => 'emoji_fallback']
);
assertImageResourceRule(
    strpos($fallbackHtml, '<picture>') === false
        && strpos($fallbackHtml, '<img') === false
        && strpos($fallbackHtml, '⚪') !== false,
    'Fallback mode must emit Emoji without requesting image files'
);

$missingHtml = renderImageResource(
    'resource_that_does_not_exist',
    32,
    ['mode' => 'image']
);
assertImageResourceRule(
    strpos($missingHtml, '<img') === false
        && strpos($missingHtml, '❓') !== false,
    'Unknown or server-missing resources must immediately use fallback'
);

$classHtml = renderImageResource(
    'soldier_knight',
    32,
    [
        'mode' => 'emoji_fallback',
        'class' => ['valid-class', 'bad" onclick="alert(1)']
    ]
);
assertImageResourceRule(
    strpos($classHtml, 'valid-class') !== false
        && strpos($classHtml, 'onclick') === false,
    'Renderer CSS classes must be allowlisted'
);

$clientConfig = getImageResourceClientConfig([
    'map_empty',
    'map_silver_hole'
]);
assertImageResourceRule(
    $clientConfig['mode'] === 'image'
        && count($clientConfig['assets']) === 2
        && isset($clientConfig['assets']['map_empty'])
        && isset($clientConfig['assets']['map_silver_hole']),
    'Client configuration must honor its explicit resource allowlist'
);
assertImageResourceRule(
    strpos(
        $clientConfig['assets']['map_empty']['variants'][0]['png'],
        '/fireseed-engage/assets/images/map/'
    ) === 0
        && strpos(
            $clientConfig['assets']['map_empty']['variants'][0]['webp'],
            '/fireseed-engage/assets/images/map/'
        ) === 0,
    'Client configuration must expose safe root-relative PNG and WebP URLs'
);
assertImageResourceRule(
    getImageResourceClientConfig(['../private'])['assets'] === [],
    'An invalid explicit client allowlist must expose no resources'
);

GameConfig::$imageMode = 'emoji_fallback';
$fallbackClientConfig = getImageResourceClientConfig([
    'map_empty',
    'map_silver_hole'
]);
assertImageResourceRule(
    $fallbackClientConfig['mode'] === 'emoji_fallback'
        && count($fallbackClientConfig['assets']) === 2
        && $fallbackClientConfig['assets']['map_empty']['variants'] === []
        && $fallbackClientConfig['assets']['map_silver_hole']['variants'] === [],
    'Emoji client configuration must expose fallback metadata without image URLs'
);

echo "Image resource rule tests passed: {$assertions} assertions.\n";
