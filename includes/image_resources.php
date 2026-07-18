<?php
// 种火集结号 - 统一图像资源解析与 Emoji 回退 / Fireseed Engage - Unified image resource resolution and Emoji fallback

/**
 * 标准化全局图像显示模式 / Normalize the global image display mode
 *
 * @param mixed $mode 待校验模式 / Mode to validate
 * @return string image 或 emoji_fallback / image or emoji_fallback
 */
function normalizeImageDisplayMode($mode) {
    $normalized = is_scalar($mode) ? trim((string) $mode) : '';
    return in_array($normalized, ['image', 'emoji_fallback'], true)
        ? $normalized
        : 'image';
}

/**
 * 获取全局图像显示模式 / Get the global image display mode
 *
 * @return string image 或 emoji_fallback / image or emoji_fallback
 */
function getImageDisplayMode() {
    try {
        return normalizeImageDisplayMode(
            GameConfig::get('image_display_mode', 'image')
        );
    } catch (Throwable $e) {
        // 数据库不可用时保持安装默认值 / Preserve the installation default when the database is unavailable
        return 'image';
    }
}

/**
 * 判断正式图像资源是否启用 / Determine whether formal image resources are enabled
 *
 * @return bool 是否启用 / Whether enabled
 */
function isImageDisplayEnabled() {
    return getImageDisplayMode() === 'image';
}

/**
 * 返回受控资源的 Emoji 与替代文本 / Return fallback Emoji and alt text for controlled resources
 *
 * @return array 资源元数据 / Resource metadata
 */
function getImageResourceFallbackMetadata() {
    static $metadata = null;
    if ($metadata !== null) {
        return $metadata;
    }

    $metadata = [
        'resource_bright_crystal' => ['emoji' => '⚪', 'alt' => '亮晶晶'],
        'resource_warm_crystal' => ['emoji' => '🔴', 'alt' => '暖洋洋'],
        'resource_cold_crystal' => ['emoji' => '🔵', 'alt' => '冷冰冰'],
        'resource_green_crystal' => ['emoji' => '🟢', 'alt' => '郁萌萌'],
        'resource_day_crystal' => ['emoji' => '🟡', 'alt' => '昼闪闪'],
        'resource_night_crystal' => ['emoji' => '⚫', 'alt' => '夜静静'],
        'resource_circuit_points' => ['emoji' => '🧠', 'alt' => '思考回路'],
        'facility_resource_production' => ['emoji' => '⚡', 'alt' => '资源产出点'],
        'facility_governor_office' => ['emoji' => '🏛️', 'alt' => '总督府'],
        'facility_barracks' => ['emoji' => '⚔️', 'alt' => '兵营'],
        'facility_research_lab' => ['emoji' => '🔬', 'alt' => '研究所'],
        'facility_dormitory' => ['emoji' => '🏠', 'alt' => '宿舍'],
        'facility_storage' => ['emoji' => '📦', 'alt' => '贮存所'],
        'facility_watchtower' => ['emoji' => '🗼', 'alt' => '瞭望台'],
        'facility_workshop' => ['emoji' => '🔧', 'alt' => '工程所'],
        'soldier_pawn' => ['emoji' => '♟️', 'alt' => '兵卒'],
        'soldier_knight' => ['emoji' => '♞', 'alt' => '骑士'],
        'soldier_rook' => ['emoji' => '♜', 'alt' => '城壁'],
        'soldier_bishop' => ['emoji' => '♝', 'alt' => '主教'],
        'soldier_golem' => ['emoji' => '🗿', 'alt' => '锤子兵'],
        'soldier_scout' => ['emoji' => '👁️', 'alt' => '侦察兵'],
        'map_empty' => ['emoji' => '🏞️', 'alt' => '空白地块'],
        'map_resource' => ['emoji' => '💎', 'alt' => '资源地块'],
        'map_npc_fort' => ['emoji' => '🏰', 'alt' => 'NPC城池'],
        'map_player_city' => ['emoji' => '🏙️', 'alt' => '玩家城池'],
        'map_silver_hole' => ['emoji' => '🌟', 'alt' => '银白之孔'],
        'ui_build' => ['emoji' => '🏗️', 'alt' => '建造'],
        'ui_upgrade' => ['emoji' => '⬆️', 'alt' => '升级'],
        'ui_attack' => ['emoji' => '⚔️', 'alt' => '攻击'],
        'ui_defense' => ['emoji' => '🛡️', 'alt' => '防御'],
        'status_constructing' => ['emoji' => '🏗️', 'alt' => '建造中'],
        'status_upgrading' => ['emoji' => '⬆️', 'alt' => '升级中'],
        'status_training' => ['emoji' => '⚔️', 'alt' => '训练中'],
        'status_researching' => ['emoji' => '🔬', 'alt' => '研究中'],
        'rarity_b' => ['emoji' => 'B', 'alt' => 'B级'],
        'rarity_a' => ['emoji' => 'A', 'alt' => 'A级'],
        'rarity_s' => ['emoji' => 'S', 'alt' => 'S级'],
        'rarity_ss' => ['emoji' => 'SS', 'alt' => 'SS级'],
        'rarity_p' => ['emoji' => 'P', 'alt' => 'P级'],
        'element_bright' => ['emoji' => '⚪', 'alt' => '亮晶晶元素'],
        'element_warm' => ['emoji' => '🔴', 'alt' => '暖洋洋元素'],
        'element_cold' => ['emoji' => '🔵', 'alt' => '冷冰冰元素'],
        'element_green' => ['emoji' => '🟢', 'alt' => '郁萌萌元素'],
        'element_day' => ['emoji' => '🟡', 'alt' => '昼闪闪元素'],
        'element_night' => ['emoji' => '⚫', 'alt' => '夜静静元素'],
        'card_frame_b' => ['emoji' => '🎴', 'alt' => 'B级武将卡框'],
        'card_frame_a' => ['emoji' => '🎴', 'alt' => 'A级武将卡框'],
        'card_frame_s' => ['emoji' => '🎴', 'alt' => 'S级武将卡框'],
        'card_frame_ss' => ['emoji' => '🎴', 'alt' => 'SS级武将卡框'],
        'card_frame_p' => ['emoji' => '🎴', 'alt' => 'P级武将卡框']
    ];

    // 十四名文档武将共享一致的立绘回退 / The fourteen documented generals share one portrait fallback
    for ($number = 1; $number <= 14; $number++) {
        $code = 'g' . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
        $metadata['general_' . $code . '_portrait'] = [
            'emoji' => '👤',
            'alt' => strtoupper($code) . ' 武将立绘'
        ];
    }

    return $metadata;
}

/**
 * 校验并标准化 manifest 中的相对资源路径 / Validate and normalize a manifest-relative asset path
 *
 * @param mixed $path 待校验路径 / Path to validate
 * @param string|null $extension 允许的扩展名 / Allowed extension
 * @return string|null 安全路径或空值 / Safe path or null
 */
function normalizeImageResourceRelativePath($path, $extension = null) {
    if (!is_string($path)) {
        return null;
    }

    $normalized = str_replace('\\', '/', trim($path));
    if ($normalized === ''
        || strpos($normalized, 'assets/images/') !== 0
        || strpos($normalized, "\0") !== false
        || preg_match('#(?:^|/)\.\.(?:/|$)#', $normalized) === 1
        || preg_match('#^assets/images/[a-z0-9_./-]+\.(png|webp)$#', $normalized) !== 1) {
        return null;
    }

    if ($extension !== null
        && strtolower((string) pathinfo($normalized, PATHINFO_EXTENSION))
            !== strtolower($extension)) {
        return null;
    }

    return $normalized;
}

/**
 * 检查项目内资源文件是否真实存在 / Check whether a project asset actually exists
 *
 * @param string $relativePath 项目相对路径 / Project-relative path
 * @return bool 是否存在 / Whether it exists
 */
function isImageResourceFileAvailable($relativePath) {
    $safePath = normalizeImageResourceRelativePath($relativePath);
    if ($safePath === null) {
        return false;
    }

    static $assetRoot = null;
    static $assetRootResolved = false;
    if (!$assetRootResolved) {
        $assetRoot = realpath(
            dirname(__DIR__) . DIRECTORY_SEPARATOR
            . 'assets' . DIRECTORY_SEPARATOR . 'images'
        );
        $assetRootResolved = true;
    }
    $absolutePath = realpath(
        dirname(__DIR__) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $safePath)
    );
    if ($assetRoot === false
        || $absolutePath === false
        || !is_file($absolutePath)) {
        return false;
    }

    $rootPrefix = rtrim($assetRoot, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR;
    return strncasecmp(
        $absolutePath,
        $rootPrefix,
        strlen($rootPrefix)
    ) === 0;
}

/**
 * 读取并过滤统一图像资源 manifest / Read and filter the unified image resource manifest
 *
 * 返回值按资源键索引，每项包含 emoji、alt 与已确认存在的 variants。
 * The result is keyed by resource key and contains emoji, alt, and verified variants.
 *
 * @return array 安全资源清单 / Safe resource manifest
 */
function getImageResourceManifest() {
    static $manifest = null;
    if ($manifest !== null) {
        return $manifest;
    }

    $metadata = getImageResourceFallbackMetadata();
    $manifest = [];
    foreach ($metadata as $key => $fallback) {
        $manifest[$key] = [
            'emoji' => $fallback['emoji'],
            'alt' => $fallback['alt'],
            'variants' => []
        ];
    }

    $manifestPath = dirname(__DIR__)
        . DIRECTORY_SEPARATOR . 'assets'
        . DIRECTORY_SEPARATOR . 'images'
        . DIRECTORY_SEPARATOR . 'manifest.json';
    if (!is_file($manifestPath)) {
        return $manifest;
    }

    $json = file_get_contents($manifestPath);
    $decoded = $json === false ? null : json_decode($json, true);
    if (!is_array($decoded)
        || !isset($decoded['assets'])
        || !is_array($decoded['assets'])) {
        return $manifest;
    }

    foreach ($decoded['assets'] as $key => $asset) {
        if (!is_string($key)
            || preg_match('/^[a-z0-9_]+$/', $key) !== 1
            || !is_array($asset)
            || !isset($asset['variants'])
            || !is_array($asset['variants'])) {
            continue;
        }

        if (!isset($manifest[$key])) {
            $manifest[$key] = [
                'emoji' => '❓',
                'alt' => str_replace('_', ' ', $key),
                'variants' => []
            ];
        }

        foreach ($asset['variants'] as $variant) {
            if (!is_array($variant)
                || !isset($variant['size'][0], $variant['size'][1], $variant['png'])
                || !is_numeric($variant['size'][0])
                || !is_numeric($variant['size'][1])) {
                continue;
            }

            $width = (int) $variant['size'][0];
            $height = (int) $variant['size'][1];
            $png = normalizeImageResourceRelativePath($variant['png'], 'png');
            if ($width <= 0
                || $height <= 0
                || $png === null
                || !isImageResourceFileAvailable($png)) {
                continue;
            }

            $webp = isset($variant['webp'])
                ? normalizeImageResourceRelativePath($variant['webp'], 'webp')
                : null;
            if ($webp !== null && !isImageResourceFileAvailable($webp)) {
                $webp = null;
            }

            $manifest[$key]['variants'][] = [
                'width' => $width,
                'height' => $height,
                'png' => $png,
                'webp' => $webp
            ];
        }

        usort($manifest[$key]['variants'], function ($left, $right) {
            return $left['width'] <=> $right['width'];
        });
    }

    return $manifest;
}

/**
 * 构建不受当前页面目录影响的资源 URL / Build an asset URL independent of the current page directory
 *
 * @param string $relativePath 已验证项目路径 / Validated project path
 * @return string 根相对 URL / Root-relative URL
 */
function getImageResourceUrl($relativePath) {
    $safePath = normalizeImageResourceRelativePath($relativePath);
    if ($safePath === null) {
        return '';
    }

    $sitePath = '';
    if (defined('SITE_URL')) {
        $parsedPath = parse_url((string) SITE_URL, PHP_URL_PATH);
        if (is_string($parsedPath)) {
            $sitePath = rtrim('/' . ltrim($parsedPath, '/'), '/');
        }
    }

    return ($sitePath === '' ? '' : $sitePath)
        . '/' . ltrim($safePath, '/');
}

/**
 * 选择适合目标显示尺寸的资源版本 / Select the resource variant for a target display size
 *
 * @param array $asset manifest 资源项 / Manifest asset
 * @param int $size 目标宽度 / Target width
 * @return array|null 选中版本或空 / Selected variant or null
 */
function selectImageResourceVariant(array $asset, $size) {
    if (empty($asset['variants']) || !is_array($asset['variants'])) {
        return null;
    }

    $target = max(1, min(2048, (int) $size));
    $selected = end($asset['variants']);
    foreach ($asset['variants'] as $variant) {
        if ((int) $variant['width'] >= $target) {
            $selected = $variant;
            break;
        }
    }

    return is_array($selected) ? $selected : null;
}

/**
 * 清理渲染组件附加的 CSS 类 / Sanitize CSS classes supplied to the renderer
 *
 * @param mixed $classes CSS 类 / CSS classes
 * @return string 安全 CSS 类 / Safe CSS classes
 */
function normalizeImageResourceClasses($classes) {
    $tokens = is_array($classes)
        ? $classes
        : preg_split('/\s+/', trim((string) $classes));
    $safe = [];
    foreach ($tokens as $token) {
        $normalized = trim((string) $token);
        if ($normalized !== ''
            && preg_match('/^[A-Za-z0-9_-]+$/', $normalized) === 1) {
            $safe[] = $normalized;
        }
    }

    return implode(' ', array_values(array_unique($safe)));
}

/**
 * 渲染 WebP/PNG 图像并提供逐项 Emoji 回退 / Render WebP/PNG with per-resource Emoji fallback
 *
 * 支持 options：mode、class、alt、emoji、decorative、loading。
 * Supported options: mode, class, alt, emoji, decorative, and loading.
 *
 * @param string $key manifest 资源键 / Manifest resource key
 * @param int $size 显示宽度 / Display width
 * @param array $options 渲染选项 / Rendering options
 * @return string 安全 HTML / Safe HTML
 */
function renderImageResource($key, $size = 32, $options = []) {
    $normalizedKey = is_string($key)
        && preg_match('/^[a-z0-9_]+$/', $key) === 1
        ? $key
        : '';
    $displaySize = max(1, min(2048, (int) $size));
    $mode = isset($options['mode'])
        ? normalizeImageDisplayMode($options['mode'])
        : getImageDisplayMode();
    $fallbackMetadata = getImageResourceFallbackMetadata();
    $fallbackAsset = $normalizedKey !== ''
        && isset($fallbackMetadata[$normalizedKey])
        ? $fallbackMetadata[$normalizedKey]
        : [
            'emoji' => '❓',
            'alt' => '未知资源'
        ];
    if ($mode === 'emoji_fallback') {
        // Emoji 模式无需读取或检查任何图像文件 / Emoji mode does not need to read or inspect image files
        $asset = $fallbackAsset + ['variants' => []];
    } else {
        $manifest = getImageResourceManifest();
        $asset = $normalizedKey !== '' && isset($manifest[$normalizedKey])
            ? $manifest[$normalizedKey]
            : $fallbackAsset + ['variants' => []];
    }
    $emoji = isset($options['emoji']) && is_scalar($options['emoji'])
        ? (string) $options['emoji']
        : (string) $asset['emoji'];
    $alt = isset($options['alt']) && is_scalar($options['alt'])
        ? (string) $options['alt']
        : (string) $asset['alt'];
    $decorative = !empty($options['decorative']);
    if ($decorative) {
        $alt = '';
    }
    $extraClasses = normalizeImageResourceClasses(
        isset($options['class']) ? $options['class'] : ''
    );
    $classKey = $normalizedKey === '' ? 'unknown' : $normalizedKey;
    $classes = 'game-image-resource game-image-resource--' . $classKey
        . ' image-resource image-resource-' . $classKey;
    if ($extraClasses !== '') {
        $classes .= ' ' . $extraClasses;
    }

    $aria = $decorative
        ? ' aria-hidden="true"'
        : ' role="img" aria-label="' . escapeHtml($alt) . '"';
    $fallback = '<span class="game-image-resource__fallback image-resource-fallback"'
        . $aria
        . ' style="display:inline-flex;width:' . $displaySize . 'px;height:'
        . $displaySize . 'px;align-items:center;justify-content:center;">'
        . escapeHtml($emoji)
        . '</span>';

    $selected = selectImageResourceVariant($asset, $displaySize);
    if ($mode === 'emoji_fallback' || $selected === null) {
        return '<span class="' . escapeHtml($classes)
            . ' image-resource-mode-fallback" style="--game-image-size:'
            . $displaySize . 'px;">' . $fallback . '</span>';
    }

    $pngSrcset = [];
    $webpSrcset = [];
    foreach ($asset['variants'] as $variant) {
        $pngUrl = getImageResourceUrl($variant['png']);
        if ($pngUrl !== '') {
            $pngSrcset[] = $pngUrl . ' ' . (int) $variant['width'] . 'w';
        }
        if (!empty($variant['webp'])) {
            $webpUrl = getImageResourceUrl($variant['webp']);
            if ($webpUrl !== '') {
                $webpSrcset[] = $webpUrl . ' ' . (int) $variant['width'] . 'w';
            }
        }
    }

    $pngUrl = getImageResourceUrl($selected['png']);
    if ($pngUrl === '') {
        return '<span class="' . escapeHtml($classes)
            . ' image-resource-mode-fallback" style="--game-image-size:'
            . $displaySize . 'px;">' . $fallback . '</span>';
    }

    $displayHeight = max(
        1,
        (int) round(
            $displaySize
            * ((int) $selected['height'] / max(1, (int) $selected['width']))
        )
    );
    $loading = isset($options['loading'])
        && in_array($options['loading'], ['lazy', 'eager'], true)
        ? $options['loading']
        : 'lazy';
    $sizes = $displaySize . 'px';
    $picture = '<picture>';
    if (!empty($webpSrcset)) {
        $picture .= '<source type="image/webp" srcset="'
            . escapeHtml(implode(', ', $webpSrcset))
            . '" sizes="' . escapeHtml($sizes) . '">';
    }
    $picture .= '<img src="' . escapeHtml($pngUrl) . '"';
    if (!empty($pngSrcset)) {
        $picture .= ' srcset="' . escapeHtml(implode(', ', $pngSrcset))
            . '" sizes="' . escapeHtml($sizes) . '"';
    }
    $picture .= ' width="' . $displaySize . '" height="' . $displayHeight . '"'
        . ' alt="' . escapeHtml($alt) . '"'
        . ' loading="' . escapeHtml($loading) . '" decoding="async"'
        . ($decorative ? ' aria-hidden="true"' : '')
        . ' onerror="this.parentElement.hidden=true;'
        . 'this.parentElement.nextElementSibling.hidden=false">'
        . '</picture>';

    $hiddenFallback = str_replace(
        'class="game-image-resource__fallback image-resource-fallback"',
        'class="game-image-resource__fallback image-resource-fallback" hidden',
        $fallback
    );

    return '<span class="' . escapeHtml($classes)
        . ' image-resource-mode-image" style="--game-image-size:'
        . $displaySize . 'px;">'
        . $picture . $hiddenFallback . '</span>';
}

/**
 * 返回动态 JavaScript 可消费的安全资源配置 / Return safe resource configuration for dynamic JavaScript
 *
 * @param array $keys 可选资源键白名单 / Optional resource-key allowlist
 * @return array 客户端配置 / Client configuration
 */
function getImageResourceClientConfig(array $keys = []) {
    $hasExplicitFilter = !empty($keys);
    $requested = [];
    foreach ($keys as $key) {
        if (is_string($key)
            && preg_match('/^[a-z0-9_]+$/', $key) === 1) {
            $requested[$key] = true;
        }
    }

    $mode = getImageDisplayMode();
    if ($mode === 'emoji_fallback') {
        // 动态页面在 Emoji 模式下不接收图像 URL / Dynamic pages receive no image URLs in Emoji mode
        $assets = [];
        foreach (getImageResourceFallbackMetadata() as $key => $asset) {
            if ($hasExplicitFilter && !isset($requested[$key])) {
                continue;
            }
            $assets[$key] = [
                'emoji' => (string) $asset['emoji'],
                'alt' => (string) $asset['alt'],
                'variants' => []
            ];
        }

        return [
            'mode' => $mode,
            'assets' => $assets
        ];
    }

    $manifest = getImageResourceManifest();
    $assets = [];
    foreach ($manifest as $key => $asset) {
        if ($hasExplicitFilter && !isset($requested[$key])) {
            continue;
        }

        $variants = [];
        foreach ($asset['variants'] as $variant) {
            $variants[] = [
                'width' => (int) $variant['width'],
                'height' => (int) $variant['height'],
                'png' => getImageResourceUrl($variant['png']),
                'webp' => empty($variant['webp'])
                    ? null
                    : getImageResourceUrl($variant['webp'])
            ];
        }
        $assets[$key] = [
            'emoji' => (string) $asset['emoji'],
            'alt' => (string) $asset['alt'],
            'variants' => $variants
        ];
    }

    return [
        'mode' => $mode,
        'assets' => $assets
    ];
}
