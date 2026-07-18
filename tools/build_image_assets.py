#!/usr/bin/env python3
"""生成可部署的游戏图像资源 / Build deployable game image resources."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Dict, Iterable, List, Sequence, Tuple

from PIL import Image, ImageDraw, ImageFont


ROOT = Path(__file__).resolve().parents[1]
IMAGE_ROOT = ROOT / "assets" / "images"
SOURCE_ROOT = IMAGE_ROOT / "sources"
RESAMPLING = Image.Resampling.LANCZOS


def grid_cell(
    atlas: Image.Image,
    columns: int,
    rows: int,
    index: int,
) -> Image.Image:
    """按稳定网格切出一个单元 / Crop one cell from a stable grid."""
    column = index % columns
    row = index // columns
    left = round(atlas.width * column / columns)
    top = round(atlas.height * row / rows)
    right = round(atlas.width * (column + 1) / columns)
    bottom = round(atlas.height * (row + 1) / rows)
    return atlas.crop((left, top, right, bottom)).convert("RGBA")


def alpha_bbox(image: Image.Image) -> Tuple[int, int, int, int]:
    """取得非透明内容范围 / Return the non-transparent content bounds."""
    alpha = image.getchannel("A")
    bounds = alpha.getbbox()
    if bounds is None:
        raise ValueError("资源单元为空 / Asset cell is empty")
    return bounds


def fit_square(
    image: Image.Image,
    size: int,
    padding_ratio: float = 0.06,
) -> Image.Image:
    """将内容等比居中到方形画布 / Fit content into a centered square."""
    cropped = image.crop(alpha_bbox(image))
    padding = max(1, round(size * padding_ratio))
    available = max(1, size - padding * 2)
    scale = min(available / cropped.width, available / cropped.height)
    target = (
        max(1, round(cropped.width * scale)),
        max(1, round(cropped.height * scale)),
    )
    resized = cropped.resize(target, RESAMPLING)
    canvas = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    canvas.alpha_composite(
        resized,
        ((size - target[0]) // 2, (size - target[1]) // 2),
    )
    return canvas


def save_variants(
    image: Image.Image,
    directory: Path,
    stem: str,
    sizes: Iterable[int],
) -> List[Dict[str, object]]:
    """保存 PNG 与 WebP 尺寸变体 / Save PNG and WebP size variants."""
    directory.mkdir(parents=True, exist_ok=True)
    records: List[Dict[str, object]] = []
    for size in sizes:
        resized = fit_square(image, size)
        png_path = directory / f"{stem}_{size}x{size}.png"
        webp_path = directory / f"{stem}_{size}x{size}.webp"
        resized.save(png_path, "PNG", optimize=True, compress_level=9)
        resized.save(webp_path, "WEBP", lossless=True, method=6)
        records.append(
            {
                "size": [size, size],
                "png": png_path.relative_to(ROOT).as_posix(),
                "webp": webp_path.relative_to(ROOT).as_posix(),
            }
        )
    return records


def save_frame_variants(
    image: Image.Image,
    stem: str,
    sizes: Sequence[Tuple[int, int]],
) -> List[Dict[str, object]]:
    """保存同定位卡框图层 / Save aligned card-frame layers."""
    directory = IMAGE_ROOT / "generals" / "cards"
    directory.mkdir(parents=True, exist_ok=True)
    records: List[Dict[str, object]] = []

    # 调用方已完成一次边界清理，避免重复收缩内容 / The caller cleans boundaries once to avoid repeatedly trimming content.
    cleaned = image.copy()

    for width, height in sizes:
        resized = cleaned.resize((width, height), RESAMPLING)
        png_path = directory / f"{stem}_{width}x{height}.png"
        webp_path = directory / f"{stem}_{width}x{height}.webp"
        resized.save(png_path, "PNG", optimize=True, compress_level=9)
        resized.save(webp_path, "WEBP", lossless=True, method=6)
        records.append(
            {
                "size": [width, height],
                "png": png_path.relative_to(ROOT).as_posix(),
                "webp": webp_path.relative_to(ROOT).as_posix(),
            }
        )
    return records


def clear_frame_atlas_dividers(image: Image.Image) -> Image.Image:
    """清除贴边图集分隔线且保留装饰尖端 / Remove edge dividers without clipping ornamental tips."""
    cleaned = image.copy()
    alpha = cleaned.getchannel("A")
    draw = ImageDraw.Draw(alpha)
    scan_depth = min(24, cleaned.width // 4, cleaned.height // 4)

    # 分隔线几乎横跨整行或整列；卡框尖端只占少量像素。 / Dividers span almost an entire row or column; frame tips occupy only a small portion.
    row_threshold = round(cleaned.width * 0.9)
    column_threshold = round(cleaned.height * 0.9)
    rows_to_clear = set()
    columns_to_clear = set()
    for offset in range(scan_depth):
        for row in (offset, cleaned.height - 1 - offset):
            row_histogram = alpha.crop(
                (0, row, cleaned.width, row + 1)
            ).histogram()
            opaque = sum(
                row_histogram[1:]
            )
            if opaque >= row_threshold:
                rows_to_clear.add(row)
        for column in (offset, cleaned.width - 1 - offset):
            column_histogram = alpha.crop(
                (column, 0, column + 1, cleaned.height)
            ).histogram()
            opaque = sum(
                column_histogram[1:]
            )
            if opaque >= column_threshold:
                columns_to_clear.add(column)

    for row in rows_to_clear:
        draw.line((0, row, cleaned.width - 1, row), fill=0)
    for column in columns_to_clear:
        draw.line((column, 0, column, cleaned.height - 1), fill=0)

    # 清除被透明间隔隔开的底部残片 / Remove bottom fragments separated by a transparent divider gap.
    row_counts: List[int] = []
    for row in range(cleaned.height):
        row_histogram = alpha.crop(
            (0, row, cleaned.width, row + 1)
        ).histogram()
        row_counts.append(sum(row_histogram[1:]))
    search_start = max(1, cleaned.height - max(48, cleaned.height // 10))
    row = search_start
    while row < cleaned.height - 1:
        if row_counts[row] != 0:
            row += 1
            continue
        gap_start = row
        while row < cleaned.height and row_counts[row] == 0:
            row += 1
        gap_end = row - 1
        has_content_above = row_counts[gap_start - 1] > 0
        has_content_below = any(
            count > 0
            for count in row_counts[gap_end + 1:]
        )
        if (
            gap_end - gap_start + 1 >= 3
            and has_content_above
            and has_content_below
        ):
            draw.rectangle(
                (
                    0,
                    gap_end + 1,
                    cleaned.width - 1,
                    cleaned.height - 1,
                ),
                fill=0,
            )
            break

    cleaned.putalpha(alpha)
    return cleaned


def rarity_badge(image: Image.Image, rarity: str) -> Image.Image:
    """给空徽章加入稳定字标 / Add a stable letter mark to a blank badge."""
    badge = fit_square(image, 96, 0.02)
    colors = {
        "B": (58, 67, 79, 230),
        "A": (18, 55, 101, 230),
        "S": (47, 31, 83, 230),
        "SS": (91, 20, 31, 230),
        "P": (93, 65, 0, 230),
    }
    overlay = Image.new("RGBA", badge.size, (0, 0, 0, 0))
    draw = ImageDraw.Draw(overlay)
    draw.ellipse((24, 24, 72, 72), fill=colors[rarity])
    badge.alpha_composite(overlay)

    font_paths = [
        Path("C:/Windows/Fonts/arialbd.ttf"),
        Path("C:/Windows/Fonts/segoeuib.ttf"),
    ]
    font_path = next((path for path in font_paths if path.is_file()), None)
    font_size = 30 if rarity == "SS" else 40
    if font_path is None:
        font = ImageFont.load_default(size=font_size)
    else:
        font = ImageFont.truetype(
            str(font_path),
            font_size,
        )
    draw = ImageDraw.Draw(badge)
    bounds = draw.textbbox((0, 0), rarity, font=font, stroke_width=2)
    width = bounds[2] - bounds[0]
    height = bounds[3] - bounds[1]
    position = (
        (badge.width - width) // 2 - bounds[0],
        (badge.height - height) // 2 - bounds[1] - 1,
    )
    draw.text(
        position,
        rarity,
        font=font,
        fill=(255, 255, 255, 255),
        stroke_width=2,
        stroke_fill=(22, 18, 30, 255),
    )
    return badge


def pack_sprite(
    images: Sequence[Image.Image],
    cell_size: int,
    filename: str,
) -> None:
    """打包横向 CSS Sprite / Pack a horizontal CSS sprite."""
    canvas = Image.new(
        "RGBA",
        (cell_size * len(images), cell_size),
        (0, 0, 0, 0),
    )
    for index, image in enumerate(images):
        canvas.alpha_composite(
            fit_square(image, cell_size),
            (index * cell_size, 0),
        )
    directory = IMAGE_ROOT / "sprites"
    directory.mkdir(parents=True, exist_ok=True)
    png_path = directory / f"{filename}.png"
    webp_path = directory / f"{filename}.webp"
    canvas.save(png_path, "PNG", optimize=True, compress_level=9)
    canvas.save(webp_path, "WEBP", lossless=True, method=6)


def build_icons() -> Dict[str, object]:
    """生成规范中的全部图标 / Build every icon from the specification."""
    manifest: Dict[str, object] = {"version": 1, "assets": {}}
    assets = manifest["assets"]
    if not isinstance(assets, dict):
        raise TypeError("资源清单初始化失败 / Manifest initialization failed")

    groups = [
        {
            "source": "resources_rgba.png",
            "grid": (4, 2),
            "directory": "resources",
            "items": [
                ("resource_bright_crystal", (32, 64)),
                ("resource_warm_crystal", (32, 64)),
                ("resource_cold_crystal", (32, 64)),
                ("resource_green_crystal", (32, 64)),
                ("resource_day_crystal", (32, 64)),
                ("resource_night_crystal", (32, 64)),
                ("resource_circuit_points", (32, 64)),
            ],
            "sprite": ("resources_64x64", 64),
        },
        {
            "source": "facilities_rgba.png",
            "grid": (4, 2),
            "directory": "facilities",
            "items": [
                ("facility_resource_production", (64, 128)),
                ("facility_governor_office", (128, 256)),
                ("facility_barracks", (64, 128)),
                ("facility_research_lab", (64, 128)),
                ("facility_dormitory", (64, 128)),
                ("facility_storage", (64, 128)),
                ("facility_watchtower", (64, 128)),
                ("facility_workshop", (64, 128)),
            ],
            "sprite": ("facilities_128x128", 128),
        },
        {
            "source": "soldiers_rgba.png",
            "grid": (3, 2),
            "directory": "soldiers",
            "items": [
                ("soldier_pawn", (32, 64)),
                ("soldier_knight", (32, 64)),
                ("soldier_rook", (32, 64)),
                ("soldier_bishop", (32, 64)),
                ("soldier_golem", (32, 64)),
                ("soldier_scout", (32, 64)),
            ],
            "sprite": ("soldiers_64x64", 64),
        },
        {
            "source": "map_rgba.png",
            "grid": (3, 2),
            "directory": "map",
            "items": [
                ("map_empty", (32,)),
                ("map_resource", (32,)),
                ("map_npc_fort", (32,)),
                ("map_player_city", (32,)),
                ("map_silver_hole", (64,)),
            ],
            "sprite": ("map_64x64", 64),
        },
        {
            "source": "ui_rgba.png",
            "grid": (4, 2),
            "directory": "ui",
            "items": [
                ("ui_build", (24, 32)),
                ("ui_upgrade", (24, 32)),
                ("ui_attack", (24, 32)),
                ("ui_defense", (24, 32)),
                ("status_constructing", (16,)),
                ("status_upgrading", (16,)),
                ("status_training", (16,)),
                ("status_researching", (16,)),
            ],
            "sprite": ("ui_32x32", 32),
        },
    ]

    for group in groups:
        atlas = Image.open(SOURCE_ROOT / str(group["source"])).convert("RGBA")
        columns, rows = group["grid"]
        source_images: List[Image.Image] = []
        for index, item in enumerate(group["items"]):
            stem, sizes = item
            source = grid_cell(atlas, columns, rows, index)
            source_images.append(source)
            records = save_variants(
                source,
                IMAGE_ROOT / str(group["directory"]),
                stem,
                sizes,
            )
            assets[stem] = {"variants": records}
        sprite_name, sprite_cell = group["sprite"]
        pack_sprite(source_images, sprite_cell, sprite_name)

    badges_atlas = Image.open(
        SOURCE_ROOT / "general_badges_rgba.png"
    ).convert("RGBA")
    badge_sources: List[Image.Image] = []
    for index, rarity in enumerate(("B", "A", "S", "SS", "P")):
        raw_badge = grid_cell(badges_atlas, 6, 2, index)
        badge = rarity_badge(raw_badge, rarity)
        badge_sources.append(badge)
        stem = f"rarity_{rarity.lower()}"
        records = save_variants(
            badge,
            IMAGE_ROOT / "generals",
            stem,
            (24,),
        )
        assets[stem] = {"variants": records}

    element_names = ("bright", "warm", "cold", "green", "day", "night")
    element_sources: List[Image.Image] = []
    for column, element in enumerate(element_names):
        source = grid_cell(badges_atlas, 6, 2, 6 + column)
        element_sources.append(source)
        stem = f"element_{element}"
        records = save_variants(
            source,
            IMAGE_ROOT / "generals",
            stem,
            (20,),
        )
        assets[stem] = {"variants": records}

    pack_sprite(
        badge_sources + element_sources,
        24,
        "general_badges_24x24",
    )
    return manifest


def build_frames(manifest: Dict[str, object]) -> None:
    """生成五级可叠加卡框 / Build five composable rarity frames."""
    atlas = Image.open(SOURCE_ROOT / "card_frames_rgba.png").convert("RGBA")
    assets = manifest["assets"]
    if not isinstance(assets, dict):
        raise TypeError("资源清单无效 / Invalid asset manifest")
    frame_sources: List[Image.Image] = []
    for index, rarity in enumerate(("b", "a", "s", "ss", "p")):
        source = grid_cell(atlas, 3, 2, index)
        cleaned_source = clear_frame_atlas_dividers(source)
        frame_sources.append(cleaned_source)
        stem = f"card_frame_{rarity}"
        records = save_frame_variants(
            cleaned_source,
            stem,
            ((400, 600), (800, 1200)),
        )
        assets[stem] = {"variants": records}

    # 卡框预览图集只用于设计与后台检查 / The frame sprite is for design/admin inspection.
    previews = [frame.resize((200, 300), RESAMPLING) for frame in frame_sources]
    canvas = Image.new("RGBA", (1000, 300), (0, 0, 0, 0))
    for index, preview in enumerate(previews):
        canvas.alpha_composite(preview, (index * 200, 0))
    sprite_dir = IMAGE_ROOT / "sprites"
    canvas.save(
        sprite_dir / "card_frames_200x300.png",
        "PNG",
        optimize=True,
        compress_level=9,
    )
    canvas.save(
        sprite_dir / "card_frames_200x300.webp",
        "WEBP",
        lossless=True,
        method=6,
    )


def build_portraits(manifest: Dict[str, object]) -> None:
    """导出十四名武将的双分辨率立绘 / Export all fourteen portraits."""
    assets = manifest["assets"]
    if not isinstance(assets, dict):
        raise TypeError("资源清单无效 / Invalid asset manifest")
    directory = IMAGE_ROOT / "generals" / "portraits"
    directory.mkdir(parents=True, exist_ok=True)

    for number in range(1, 15):
        code = f"g{number:03d}"
        source_candidates = [
            SOURCE_ROOT / f"general_{code}_portrait_source.png",
            directory / f"general_{code}_portrait_1024x1536.png",
        ]
        source_path = next(
            (path for path in source_candidates if path.is_file()),
            source_candidates[0],
        )
        source = Image.open(source_path).convert("RGB")
        if source.size != (1024, 1536):
            raise ValueError(
                f"{source_path.name} 尺寸错误 / has invalid dimensions"
            )
        records: List[Dict[str, object]] = []
        for width, height in ((512, 768), (1024, 1536)):
            image = (
                source
                if source.size == (width, height)
                else source.resize((width, height), RESAMPLING)
            )
            stem = f"general_{code}_portrait_{width}x{height}"
            png_path = directory / f"{stem}.png"
            webp_path = directory / f"{stem}.webp"
            image.save(png_path, "PNG", optimize=True, compress_level=9)
            image.save(
                webp_path,
                "WEBP",
                quality=90,
                method=6,
            )
            records.append(
                {
                    "size": [width, height],
                    "png": png_path.relative_to(ROOT).as_posix(),
                    "webp": webp_path.relative_to(ROOT).as_posix(),
                }
            )
        assets[f"general_{code}_portrait"] = {"variants": records}


def write_manifest(manifest: Dict[str, object]) -> None:
    """写出机器可读资源清单 / Write the machine-readable manifest."""
    manifest["generated_from"] = (
        "doc/etc/image_resources_specification.txt"
    )
    manifest["card_canvas"] = {
        "aspect_ratio": "2:3",
        "runtime_size": [400, 600],
        "high_dpi_size": [800, 1200],
    }
    path = IMAGE_ROOT / "manifest.json"
    path.write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
        encoding="utf-8",
    )


def main() -> None:
    """执行完整构建 / Run the complete build."""
    manifest = build_icons()
    build_frames(manifest)
    build_portraits(manifest)
    write_manifest(manifest)
    print(
        "图像资源构建完成 / Image asset build complete: "
        f"{len(manifest['assets'])} semantic assets"
    )


if __name__ == "__main__":
    main()
