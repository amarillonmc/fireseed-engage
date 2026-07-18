<?php
// 种火集结号 - 可拼装武将卡界面 / Fireseed Engage - Composable general-card UI

/**
 * 将模板代码归一到已有立绘 / Normalize a template code to available artwork
 * @param mixed $templateCode 模板代码 / Template code
 * @return string|null G001至G014，或空值 / G001 through G014, or null
 */
function normalizeGeneralArtworkCode($templateCode) {
    if (!is_scalar($templateCode)) {
        return null;
    }

    $normalized = strtoupper(trim((string) $templateCode));
    if (!preg_match('/^(G(?:00[1-9]|01[0-4]))B?$/D', $normalized, $matches)) {
        return null;
    }

    return $matches[1];
}

/**
 * 归一武将稀有度 / Normalize a general rarity
 * @param mixed $rarity 原始稀有度 / Raw rarity
 * @return string 合法稀有度 / Valid rarity
 */
function normalizeGeneralCardRarity($rarity) {
    $normalized = strtoupper(trim((string) $rarity));
    return in_array($normalized, ['B', 'A', 'S', 'SS', 'P'], true)
        ? $normalized
        : 'B';
}

/**
 * 取得元素的资源键、CSS与回退符号 / Get an element resource key, CSS class, and fallback
 * @param mixed $element 元素名称 / Element name
 * @return array 元素显示定义 / Element display definition
 */
function getGeneralElementVisual($element) {
    $definitions = [
        '亮晶晶' => ['bright', 'element_bright', '💎'],
        '暖洋洋' => ['warm', 'element_warm', '🔥'],
        '冷冰冰' => ['cold', 'element_cold', '❄️'],
        '郁萌萌' => ['green', 'element_green', '🌿'],
        '昼闪闪' => ['day', 'element_day', '☀️'],
        '夜静静' => ['night', 'element_night', '🌙']
    ];
    $name = (string) $element;

    return isset($definitions[$name])
        ? [
            'code' => $definitions[$name][0],
            'resource_key' => $definitions[$name][1],
            'emoji' => $definitions[$name][2],
            'name' => $name
        ]
        : [
            'code' => 'unknown',
            'resource_key' => null,
            'emoji' => '✦',
            'name' => $name === '' ? '未知' : $name
        ];
}

/**
 * 从对象或数组提取卡片数据 / Extract card data from an object or array
 * @param mixed $general 武将对象或数组 / General object or array
 * @return array 标准卡片数据 / Normalized card data
 */
function getGeneralCardData($general) {
    $fieldMethods = [
        'general_id' => 'getGeneralId',
        'template_code' => 'getTemplateCode',
        'name' => 'getName',
        'source' => 'getSource',
        'rarity' => 'getRarity',
        'cost' => 'getCost',
        'element' => 'getElement',
        'level' => 'getLevel',
        'hp' => 'getHp',
        'max_hp' => 'getMaxHp',
        'attack' => 'getAttack',
        'defense' => 'getDefense',
        'speed' => 'getSpeed',
        'intelligence' => 'getIntelligence'
    ];
    $data = [];

    if (is_object($general)) {
        foreach ($fieldMethods as $field => $method) {
            $data[$field] = method_exists($general, $method)
                ? $general->$method()
                : null;
        }
        $data['skill_name'] = '';
        if (method_exists($general, 'getSkills')) {
            $skills = $general->getSkills();
            $selectedSkill = null;
            foreach ($skills as $skill) {
                if (!$selectedSkill) {
                    $selectedSkill = $skill;
                }
                if (method_exists($skill, 'getSlot')
                    && (int) $skill->getSlot() === 0) {
                    $selectedSkill = $skill;
                    break;
                }
            }
            if ($selectedSkill && method_exists($selectedSkill, 'getSkillName')) {
                $data['skill_name'] = (string) $selectedSkill->getSkillName();
            }
        }
    } elseif (is_array($general)) {
        foreach ($fieldMethods as $field => $method) {
            $data[$field] = array_key_exists($field, $general)
                ? $general[$field]
                : null;
        }
        $data['skill_name'] = isset($general['skill_name'])
            && is_scalar($general['skill_name'])
            ? (string) $general['skill_name']
            : '';
    }

    $data += [
        'general_id' => 0,
        'template_code' => null,
        'name' => '未知武将',
        'source' => '',
        'rarity' => 'B',
        'cost' => 0,
        'element' => '',
        'level' => 1,
        'hp' => 100,
        'max_hp' => 100,
        'attack' => 0,
        'defense' => 0,
        'speed' => 0,
        'intelligence' => 0,
        'skill_name' => ''
    ];

    $data['general_id'] = max(0, (int) $data['general_id']);
    $data['template_code'] = normalizeGeneralArtworkCode(
        $data['template_code']
    );
    $data['name'] = trim((string) $data['name']);
    $data['name'] = $data['name'] === '' ? '未知武将' : $data['name'];
    $data['source'] = trim((string) $data['source']);
    $data['rarity'] = normalizeGeneralCardRarity($data['rarity']);
    $data['cost'] = is_numeric($data['cost']) ? (float) $data['cost'] : 0.0;
    $data['element'] = (string) $data['element'];
    foreach ([
        'level',
        'hp',
        'max_hp',
        'attack',
        'defense',
        'speed',
        'intelligence'
    ] as $numericField) {
        $data[$numericField] = max(0, (int) $data[$numericField]);
    }
    $data['skill_name'] = trim((string) $data['skill_name']);

    return $data;
}

/**
 * 判断当前是否启用图片模式 / Determine whether image mode is enabled
 * @return bool 是否启用 / Whether enabled
 */
function isGeneralCardImageMode() {
    if (function_exists('isImageDisplayEnabled')) {
        return isImageDisplayEnabled();
    }
    if (function_exists('getImageDisplayMode')) {
        return getImageDisplayMode() === 'image';
    }

    return class_exists('GameConfig')
        && GameConfig::get('image_display_mode', 'image') === 'image';
}

/**
 * 验证仓库内图像文件 / Validate an image inside the repository
 * @param string $relativePath assets/images下的相对路径 / Path relative to assets/images
 * @return bool 文件是否存在 / Whether the file exists
 */
function generalCardAssetExists($relativePath) {
    $normalized = str_replace('\\', '/', (string) $relativePath);
    if ($normalized === ''
        || strpos($normalized, '..') !== false
        || $normalized[0] === '/') {
        return false;
    }

    return is_file(
        dirname(__DIR__) . '/assets/images/' . $normalized
    );
}

/**
 * 生成武将卡资源URL / Build a general-card asset URL
 * @param string $relativePath assets/images下的相对路径 / Path relative to assets/images
 * @param string $assetPrefix 页面相对前缀 / Page-relative prefix
 * @return string 已转义前的URL / URL before escaping
 */
function getGeneralCardAssetUrl($relativePath, $assetPrefix = '') {
    $normalizedPath = ltrim(
        str_replace('\\', '/', (string) $relativePath),
        '/'
    );
    if ($normalizedPath === ''
        || strpos($normalizedPath, '..') !== false
        || preg_match(
            '#^[a-z0-9_./-]+\.(?:png|webp)$#D',
            $normalizedPath
        ) !== 1) {
        return '';
    }
    $projectPath = 'assets/images/' . $normalizedPath;
    if (function_exists('getImageResourceUrl')) {
        $url = getImageResourceUrl($projectPath);
        if ($url !== '') {
            return $url;
        }
    }

    $prefix = str_replace('\\', '/', trim((string) $assetPrefix));
    if ($prefix !== '' && substr($prefix, -1) !== '/') {
        $prefix .= '/';
    }
    if ($prefix !== ''
        && preg_match('#^(?:(?:\.\.|[A-Za-z0-9_-]+)/)+$#D', $prefix) !== 1) {
        $prefix = '';
    }

    return $prefix . $projectPath;
}

/**
 * 渲染允许缺少可选密度或 WebP 的 picture / Render a picture that tolerates missing optional density or WebP variants
 * @param string $smallStem 低密度资源，不含扩展名 / Low-density stem without extension
 * @param string $largeStem 高密度资源，不含扩展名 / High-density stem without extension
 * @param string $pictureClass picture类 / Picture class
 * @param string $imageClass img类 / Image class
 * @param string $alt 替代文本 / Alternative text
 * @param string $assetPrefix 页面相对前缀 / Page-relative prefix
 * @param string $errorHandler 加载失败脚本 / Load-error handler
 * @param bool $decorative 是否为装饰图 / Whether decorative
 * @return string HTML
 */
function renderGeneralResponsivePicture(
    $smallStem,
    $largeStem,
    $pictureClass,
    $imageClass,
    $alt,
    $assetPrefix,
    $errorHandler,
    $decorative = false
) {
    $paths = [];
    foreach (['png', 'webp'] as $extension) {
        foreach (['small' => $smallStem, 'large' => $largeStem] as $size => $stem) {
            $path = $stem . '.' . $extension;
            $paths[$size . '_' . $extension] = generalCardAssetExists($path)
                ? getGeneralCardAssetUrl($path, $assetPrefix)
                : null;
        }
    }

    $basePng = $paths['small_png'] ?: $paths['large_png'];
    if ($basePng === null) {
        return '';
    }

    $pictureAttributes = ' class="' . escapeHtml($pictureClass) . '"';
    if ($decorative) {
        $pictureAttributes .= ' aria-hidden="true"';
        $alt = '';
    }
    $html = '<picture' . $pictureAttributes . '>';
    $webpSrcset = [];
    if ($paths['small_webp'] !== null) {
        $webpSrcset[] = $paths['small_webp'] . ' 1x';
    }
    if ($paths['large_webp'] !== null) {
        $webpSrcset[] = $paths['large_webp']
            . ($paths['small_webp'] !== null ? ' 2x' : ' 1x');
    }
    if (!empty($webpSrcset)) {
        $html .= '<source type="image/webp" srcset="'
            . escapeHtml(implode(', ', $webpSrcset)) . '">';
    }

    $imageClassAttribute = trim((string) $imageClass) === ''
        ? ''
        : ' class="' . escapeHtml($imageClass) . '"';
    $html .= '<img' . $imageClassAttribute
        . ' src="' . escapeHtml($basePng) . '"';
    if ($paths['small_png'] !== null && $paths['large_png'] !== null) {
        $html .= ' srcset="' . escapeHtml($paths['large_png']) . ' 2x"';
    }
    $html .= ' alt="' . escapeHtml($alt) . '" loading="lazy" decoding="async"'
        . ' onerror="' . escapeHtml($errorHandler) . '">'
        . '</picture>';

    return $html;
}

/**
 * 渲染武将立绘的picture元素 / Render a portrait picture element
 * @param string $artworkCode G001至G014 / G001 through G014
 * @param string $alt 替代文本 / Alternative text
 * @param string $assetPrefix 页面相对前缀 / Page-relative prefix
 * @return string HTML
 */
function renderGeneralPortraitPicture(
    $artworkCode,
    $alt,
    $assetPrefix = ''
) {
    $code = strtolower((string) $artworkCode);
    $smallStem = "generals/portraits/general_{$code}_portrait_512x768";
    $largeStem = "generals/portraits/general_{$code}_portrait_1024x1536";
    $errorHandler = "this.closest('.general-card-composite')"
        . ".classList.add('image-failed')";
    return renderGeneralResponsivePicture(
        $smallStem,
        $largeStem,
        'general-card-composite__portrait',
        '',
        $alt,
        $assetPrefix,
        $errorHandler
    );
}

/**
 * 渲染卡框的picture元素 / Render a card-frame picture element
 * @param string $rarity 稀有度 / Rarity
 * @param string $assetPrefix 页面相对前缀 / Page-relative prefix
 * @return string HTML
 */
function renderGeneralFramePicture($rarity, $assetPrefix = '') {
    $rarityCode = strtolower(normalizeGeneralCardRarity($rarity));
    $smallStem = "generals/cards/card_frame_{$rarityCode}_400x600";
    $largeStem = "generals/cards/card_frame_{$rarityCode}_800x1200";
    $errorHandler = "this.closest('.general-card-composite')"
        . ".classList.add('image-failed')";
    return renderGeneralResponsivePicture(
        $smallStem,
        $largeStem,
        'general-card-composite__frame',
        '',
        '',
        $assetPrefix,
        $errorHandler,
        true
    );
}

/**
 * 渲染单张可拼装武将卡 / Render one composable general card
 * @param mixed $general 武将对象或数组 / General object or array
 * @param array $options 显示选项 / Display options
 * @return string HTML
 */
function renderGeneralCardVisual($general, array $options = []) {
    $data = getGeneralCardData($general);
    $element = getGeneralElementVisual($data['element']);
    $compact = !empty($options['compact']);
    $assetPrefix = isset($options['asset_prefix'])
        ? (string) $options['asset_prefix']
        : '';
    $class = 'general-card-composite rarity-'
        . strtolower($data['rarity'])
        . ' element-' . escapeHtml($element['code'])
        . ($compact ? ' is-compact' : '');
    $portrait = '';
    $frame = '';

    if (isGeneralCardImageMode() && $data['template_code'] !== null) {
        $portrait = renderGeneralPortraitPicture(
            $data['template_code'],
            $data['name'] . '立绘 / portrait',
            $assetPrefix
        );
        $frame = renderGeneralFramePicture(
            $data['rarity'],
            $assetPrefix
        );
    }
    $hasVisual = $portrait !== '' && $frame !== '';
    if (!$hasVisual) {
        $class .= ' image-failed';
    }

    $elementIcon = escapeHtml($element['emoji']);
    if (function_exists('renderImageResource')
        && $element['resource_key'] !== null) {
        $elementIcon = renderImageResource(
            $element['resource_key'],
            20,
            [
                'alt' => $element['name'],
                'asset_prefix' => $assetPrefix,
                'class' => 'general-card-composite__element-icon'
            ]
        );
    }

    $rarityIcon = escapeHtml($data['rarity']);
    if (function_exists('renderImageResource')) {
        $rarityIcon = renderImageResource(
            'rarity_' . strtolower($data['rarity']),
            24,
            [
                'alt' => $data['rarity'] . '稀有度',
                'asset_prefix' => $assetPrefix,
                'class' => 'general-card-composite__rarity-icon'
            ]
        );
    }

    $cost = rtrim(rtrim(number_format($data['cost'], 1, '.', ''), '0'), '.');
    $skillName = $data['skill_name'] === ''
        ? '无固有技能'
        : $data['skill_name'];

    $html = '<article class="' . $class . '"'
        . ' aria-label="' . escapeHtml(
            $data['name'] . '，' . $data['rarity'] . '级武将'
        ) . '">';
    $html .= '<div class="general-card-composite__visual" aria-hidden="true">';
    $html .= '<div class="general-card-composite__aura"></div>';
    $html .= $portrait . $frame;
    $html .= '<div class="general-card-composite__name">'
        . escapeHtml($data['name']) . '</div>';
    $html .= '<div class="general-card-composite__element">'
        . $elementIcon . '</div>';
    $html .= '<div class="general-card-composite__rarity">'
        . $rarityIcon . '</div>';
    $html .= '<div class="general-card-composite__level">'
        . 'Lv.' . number_format($data['level'])
        . '<span>COST ' . escapeHtml($cost) . '</span></div>';
    $html .= '<div class="general-card-composite__stats">'
        . '<span>攻 <b>' . number_format($data['attack']) . '</b></span>'
        . '<span>守 <b>' . number_format($data['defense']) . '</b></span>'
        . '<span>速 <b>' . number_format($data['speed']) . '</b></span>'
        . '<span>智 <b>' . number_format($data['intelligence']) . '</b></span>'
        . '</div>';
    $html .= '<div class="general-card-composite__skill">'
        . escapeHtml($skillName) . '</div>';
    $html .= '</div>';

    $html .= '<div class="general-card-composite__fallback">';
    $html .= '<div class="general-card-composite__fallback-avatar">👤</div>';
    $html .= '<strong>' . escapeHtml($data['name']) . '</strong>';
    $html .= '<span>' . escapeHtml($data['rarity'])
        . ' · ' . escapeHtml($element['emoji'] . ' ' . $element['name'])
        . ' · COST ' . escapeHtml($cost) . '</span>';
    $html .= '<span>Lv.' . number_format($data['level'])
        . ' · HP ' . number_format($data['hp'])
        . '/' . number_format($data['max_hp']) . '</span>';
    $html .= '<span>攻 ' . number_format($data['attack'])
        . ' / 守 ' . number_format($data['defense'])
        . ' / 速 ' . number_format($data['speed'])
        . ' / 智 ' . number_format($data['intelligence']) . '</span>';
    $html .= '<span>' . escapeHtml($skillName) . '</span>';
    $html .= '</div>';
    $html .= '</article>';

    return $html;
}

/**
 * 渲染卡池中的紧凑武将身份 / Render a compact general identity in a pool
 * @param mixed $general 武将数组或对象 / General array or object
 * @param array $options 显示选项 / Display options
 * @return string HTML
 */
function renderGeneralCompactIdentity($general, array $options = []) {
    $data = getGeneralCardData($general);
    $element = getGeneralElementVisual($data['element']);
    $assetPrefix = isset($options['asset_prefix'])
        ? (string) $options['asset_prefix']
        : '';
    $portrait = '';

    if (isGeneralCardImageMode() && $data['template_code'] !== null) {
        $code = strtolower($data['template_code']);
        $smallStem = "generals/portraits/general_{$code}_portrait_512x768";
        $largeStem = "generals/portraits/general_{$code}_portrait_1024x1536";
        $picture = renderGeneralResponsivePicture(
            $smallStem,
            $largeStem,
            'general-identity__portrait-picture',
            'general-identity__portrait',
            '',
            $assetPrefix,
            'this.parentElement.hidden=true;'
                . 'this.parentElement.nextElementSibling.hidden=false',
            true
        );
        if ($picture !== '') {
            $portrait = $picture
                . '<span class="general-identity__emoji" hidden>👤</span>';
        }
    }
    if ($portrait === '') {
        $portrait = '<span class="general-identity__emoji">👤</span>';
    }

    return '<span class="general-identity">'
        . $portrait
        . '<span class="general-identity__text"><strong>'
        . escapeHtml($data['name']) . '</strong><small>'
        . escapeHtml(
            $data['rarity'] . ' · ' . $element['emoji']
            . ' ' . $element['name']
        )
        . '</small></span></span>';
}
