# 图像资源包 / Image Asset Pack

本目录是 `doc/etc/image_resources_specification.txt` 的可部署实现。资源视觉采用原创角色与原创装饰，借鉴日式网页策略卡牌游戏的高辨识度分层布局，但不复用《メガミエンゲイジ！》的角色、标志或原始素材。

This directory is the deployable implementation of `doc/etc/image_resources_specification.txt`. It uses original characters and ornamentation with a layered Japanese browser-strategy card layout; no original Megami Engage character, logo, or source asset is reused.

## 内容 / Contents

- `manifest.json`：64 个语义资源的机器可读清单，包含 PNG 与 WebP 变体。
- `resources/`：7 种资源图标，各有 32px 与 64px 版本。
- `facilities/`：8 种设施图标，按规范提供 64/128px 或 128/256px 版本。
- `soldiers/`：6 种兵种图标，各有 32px 与 64px 版本。
- `generals/`：5 个稀有度徽章、6 个元素徽章、5 套可叠加卡框，以及 G001–G014 的全部立绘。
- `map/`：5 种地图图标。
- `ui/`：4 种操作图标与 4 种状态图标。
- `sprites/`：资源、设施、兵种、武将徽章、地图与 UI 的横向 CSS Sprite；另含卡框检查图集。
- `sources/`：重建正式资源所需的透明原始图集。

- `manifest.json`: machine-readable inventory of 64 semantic assets with PNG and WebP variants.
- `resources/`: seven resource icons at 32px and 64px.
- `facilities/`: eight facility icons at their specified 64/128px or 128/256px sizes.
- `soldiers/`: six unit icons at 32px and 64px.
- `generals/`: five rarity badges, six element badges, five composable frames, and all G001–G014 portraits.
- `map/`: five map icons.
- `ui/`: four action icons and four status icons.
- `sprites/`: horizontal CSS sprites for resources, facilities, units, general badges, map, and UI, plus a frame inspection sheet.
- `sources/`: transparent source atlases required to rebuild deployable assets.

## 武将卡合成 / General Card Composition

运行时卡片采用固定 2:3 画布并按以下顺序叠加：

1. 元素色氛围光；
2. 512×768 立绘（高 DPI 使用 1024×1536）；
3. 对应 B/A/S/SS/P 稀有度的透明卡框，标准尺寸 400×600（高 DPI 为 800×1200）；
4. 动态名称、元素、稀有度、等级、COST、四维与技能文字；
5. 资源缺失或后台切换后显示的 Emoji 信息卡。

Runtime cards use a fixed 2:3 canvas with element aura, portrait, transparent rarity frame, dynamic card data, and an Emoji information-card fallback in that order.

立绘对应关系 / Portrait mapping:

| 代码 / Code | 名称 / Name |
|---|---|
| G001 | 白银之主 |
| G002 | 晶光使者 |
| G003 | 炎之剑客 |
| G004 | 烈火战士 |
| G005 | 冰霜守护者 |
| G006 | 寒冰战士 |
| G007 | 森林之王 |
| G008 | 翠绿射手 |
| G009 | 太阳神使 |
| G010 | 光明祭司 |
| G011 | 暗影大师 |
| G012 | 夜行者 |
| G013 | 数据之王 |
| G014 | 银白之孔守护者 |

## 显示模式 / Display Modes

管理员可在“后台 → 游戏数值配置 → 图像资源显示”全局切换：

- `image`：优先 WebP、以 PNG 兼容，并在单个文件加载失败时回退 Emoji。
- `emoji_fallback`：不请求正式图像，直接输出 Emoji。

The admin setting “Image resource display” switches globally between formal images (`image`) and Emoji-only output (`emoji_fallback`). Image mode prefers WebP, retains PNG compatibility, and falls back per asset when loading fails.

## 重建与验证 / Rebuild and Validate

```bash
python -m pip install -r tools/requirements-image-assets.txt
python tools/build_image_assets.py
python tools/validate_image_assets.py
php tests/ImageResourceRulesTest.php
php tests/GeneralCardVisualRulesTest.php
```

重建脚本使用固定版本 Pillow，并根据 `sources/` 与现有 1024×1536 立绘生成全部尺寸、压缩格式、图集和清单。验证脚本仅使用 Python 标准库且不会修改资源。

The builder uses a pinned Pillow version and regenerates all sizes, compressed formats, sprites, and the manifest from `sources/` and the existing 1024×1536 portraits. The read-only validator uses only the Python standard library.
