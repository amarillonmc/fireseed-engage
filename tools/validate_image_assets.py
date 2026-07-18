#!/usr/bin/env python3
"""验证生成图像资源的结构与像素属性 / Validate generated image assets and pixel properties."""

from __future__ import annotations

import json
import struct
import sys
import zlib
from pathlib import Path
from typing import Dict, List, Optional, Sequence, Set, Tuple


ROOT = Path(__file__).resolve().parents[1]
IMAGE_ROOT = ROOT / "assets" / "images"
MANIFEST_PATH = IMAGE_ROOT / "manifest.json"

ICON_SIZES: Dict[str, Set[Tuple[int, int]]] = {
    **{
        f"resource_{name}": {(32, 32), (64, 64)}
        for name in (
            "bright_crystal",
            "warm_crystal",
            "cold_crystal",
            "green_crystal",
            "day_crystal",
            "night_crystal",
            "circuit_points",
        )
    },
    "facility_resource_production": {(64, 64), (128, 128)},
    "facility_governor_office": {(128, 128), (256, 256)},
    **{
        f"facility_{name}": {(64, 64), (128, 128)}
        for name in (
            "barracks",
            "research_lab",
            "dormitory",
            "storage",
            "watchtower",
            "workshop",
        )
    },
    **{
        f"soldier_{name}": {(32, 32), (64, 64)}
        for name in ("pawn", "knight", "rook", "bishop", "golem", "scout")
    },
    "map_empty": {(32, 32)},
    "map_resource": {(32, 32)},
    "map_npc_fort": {(32, 32)},
    "map_player_city": {(32, 32)},
    "map_silver_hole": {(64, 64)},
    **{
        f"ui_{name}": {(24, 24), (32, 32)}
        for name in ("build", "upgrade", "attack", "defense")
    },
    **{
        f"status_{name}": {(16, 16)}
        for name in ("constructing", "upgrading", "training", "researching")
    },
    **{
        f"rarity_{rarity}": {(24, 24)}
        for rarity in ("b", "a", "s", "ss", "p")
    },
    **{
        f"element_{element}": {(20, 20)}
        for element in ("bright", "warm", "cold", "green", "day", "night")
    },
}

FRAME_SIZES = {(400, 600), (800, 1200)}
PORTRAIT_SIZES = {(512, 768), (1024, 1536)}
FRAME_KEYS = {f"card_frame_{rarity}" for rarity in ("b", "a", "s", "ss", "p")}
PORTRAIT_KEYS = {
    f"general_g{number:03d}_portrait"
    for number in range(1, 15)
}
SPRITES: Dict[str, Tuple[int, int]] = {
    "resources_64x64": (448, 64),
    "facilities_128x128": (1024, 128),
    "soldiers_64x64": (384, 64),
    "map_64x64": (320, 64),
    "ui_32x32": (256, 32),
    "general_badges_24x24": (264, 24),
    "card_frames_200x300": (1000, 300),
}


def png_metadata(
    path: Path,
    inspect_alpha: bool = False,
) -> Tuple[int, int, int, int, Optional[bool]]:
    """读取 PNG 尺寸并按需检查透明像素 / Read PNG dimensions and optionally inspect transparent pixels."""
    payload = path.read_bytes()
    if payload[:8] != b"\x89PNG\r\n\x1a\n":
        raise ValueError("不是有效 PNG / Not a valid PNG")

    position = 8
    width = height = bit_depth = color_type = None
    interlace = None
    compressed_rows: List[bytes] = []
    while position + 12 <= len(payload):
        chunk_length = struct.unpack(">I", payload[position:position + 4])[0]
        chunk_type = payload[position + 4:position + 8]
        chunk_start = position + 8
        chunk_end = chunk_start + chunk_length
        if chunk_end + 4 > len(payload):
            raise ValueError("PNG 区块被截断 / Truncated PNG chunk")
        chunk = payload[chunk_start:chunk_end]
        position = chunk_end + 4

        if chunk_type == b"IHDR":
            if len(chunk) != 13:
                raise ValueError("IHDR 长度错误 / Invalid IHDR length")
            (
                width,
                height,
                bit_depth,
                color_type,
                compression,
                filter_method,
                interlace,
            ) = struct.unpack(">IIBBBBB", chunk)
            if compression != 0 or filter_method != 0:
                raise ValueError(
                    "不支持的 PNG 压缩或滤镜 / Unsupported PNG compression or filter"
                )
        elif chunk_type == b"IDAT":
            compressed_rows.append(chunk)
        elif chunk_type == b"IEND":
            break

    if None in (width, height, bit_depth, color_type, interlace):
        raise ValueError("缺少 PNG IHDR / Missing PNG IHDR")

    has_transparent_pixel: Optional[bool] = None
    if inspect_alpha:
        if bit_depth != 8 or color_type != 6 or interlace != 0:
            raise ValueError(
                "透明度检查仅支持 8 位非交错 RGBA PNG / "
                "Alpha inspection requires 8-bit non-interlaced RGBA PNG"
            )
        rows = zlib.decompress(b"".join(compressed_rows))
        bytes_per_pixel = 4
        row_bytes = int(width) * bytes_per_pixel
        expected_length = (row_bytes + 1) * int(height)
        if len(rows) != expected_length:
            raise ValueError(
                "PNG 扫描线长度不符 / PNG scanline length mismatch"
            )

        has_transparent_pixel = False
        previous = bytearray(row_bytes)
        offset = 0
        for _ in range(int(height)):
            filter_type = rows[offset]
            encoded = rows[offset + 1:offset + 1 + row_bytes]
            offset += row_bytes + 1
            reconstructed = bytearray(row_bytes)
            for index, value in enumerate(encoded):
                left = (
                    reconstructed[index - bytes_per_pixel]
                    if index >= bytes_per_pixel
                    else 0
                )
                above = previous[index]
                upper_left = (
                    previous[index - bytes_per_pixel]
                    if index >= bytes_per_pixel
                    else 0
                )
                if filter_type == 0:
                    predictor = 0
                elif filter_type == 1:
                    predictor = left
                elif filter_type == 2:
                    predictor = above
                elif filter_type == 3:
                    predictor = (left + above) // 2
                elif filter_type == 4:
                    predictor = paeth_predictor(left, above, upper_left)
                else:
                    raise ValueError(
                        f"未知 PNG 滤镜 {filter_type} / "
                        f"Unknown PNG filter {filter_type}"
                    )
                reconstructed[index] = (value + predictor) & 0xFF
            if any(
                reconstructed[index] < 255
                for index in range(3, row_bytes, bytes_per_pixel)
            ):
                has_transparent_pixel = True
            previous = reconstructed

    return (
        int(width),
        int(height),
        int(bit_depth),
        int(color_type),
        has_transparent_pixel,
    )


def paeth_predictor(left: int, above: int, upper_left: int) -> int:
    """实现 PNG Paeth 预测器 / Implement the PNG Paeth predictor."""
    estimate = left + above - upper_left
    left_distance = abs(estimate - left)
    above_distance = abs(estimate - above)
    corner_distance = abs(estimate - upper_left)
    if left_distance <= above_distance and left_distance <= corner_distance:
        return left
    if above_distance <= corner_distance:
        return above
    return upper_left


def webp_dimensions(path: Path) -> Tuple[int, int]:
    """读取常见 VP8/VP8L/VP8X WebP 尺寸 / Read VP8, VP8L, or VP8X WebP dimensions."""
    payload = path.read_bytes()
    if (
        len(payload) < 20
        or payload[:4] != b"RIFF"
        or payload[8:12] != b"WEBP"
    ):
        raise ValueError("不是有效 WebP / Not a valid WebP")

    position = 12
    while position + 8 <= len(payload):
        chunk_type = payload[position:position + 4]
        chunk_length = int.from_bytes(
            payload[position + 4:position + 8],
            "little",
        )
        chunk_start = position + 8
        chunk_end = chunk_start + chunk_length
        if chunk_end > len(payload):
            raise ValueError("WebP 区块被截断 / Truncated WebP chunk")
        chunk = payload[chunk_start:chunk_end]

        if chunk_type == b"VP8X" and len(chunk) >= 10:
            width = int.from_bytes(chunk[4:7], "little") + 1
            height = int.from_bytes(chunk[7:10], "little") + 1
            return width, height
        if chunk_type == b"VP8L" and len(chunk) >= 5:
            if chunk[0] != 0x2F:
                raise ValueError("VP8L 签名字节错误 / Invalid VP8L signature")
            packed = int.from_bytes(chunk[1:5], "little")
            width = (packed & 0x3FFF) + 1
            height = ((packed >> 14) & 0x3FFF) + 1
            return width, height
        if chunk_type == b"VP8 ":
            signature = chunk.find(b"\x9d\x01\x2a")
            if signature >= 0 and signature + 7 <= len(chunk):
                width = int.from_bytes(
                    chunk[signature + 3:signature + 5],
                    "little",
                ) & 0x3FFF
                height = int.from_bytes(
                    chunk[signature + 5:signature + 7],
                    "little",
                ) & 0x3FFF
                return width, height

        position = chunk_end + (chunk_length & 1)

    raise ValueError("无法读取 WebP 尺寸 / Unable to read WebP dimensions")


def safe_asset_path(relative_path: object) -> Path:
    """将 manifest 路径限制在图像目录 / Confine a manifest path to the image directory."""
    if not isinstance(relative_path, str):
        raise ValueError("资源路径不是字符串 / Asset path is not a string")
    normalized = relative_path.replace("\\", "/")
    if not normalized.startswith("assets/images/") or ".." in normalized.split("/"):
        raise ValueError("资源路径越界 / Asset path escapes its root")
    absolute = (ROOT / normalized).resolve()
    try:
        absolute.relative_to(IMAGE_ROOT.resolve())
    except ValueError as error:
        raise ValueError("资源路径越界 / Asset path escapes its root") from error
    return absolute


def validate() -> List[str]:
    """执行完整的只读资源验证 / Run the complete read-only asset validation."""
    errors: List[str] = []

    def check(condition: bool, message: str) -> None:
        """记录失败而不中断其余检查 / Record a failure without stopping later checks."""
        if not condition:
            errors.append(message)

    try:
        manifest = json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as error:
        return [f"无法读取 manifest / Cannot read manifest: {error}"]

    assets = manifest.get("assets")
    if not isinstance(assets, dict):
        return ["manifest.assets 必须是对象 / manifest.assets must be an object"]

    expected_keys = set(ICON_SIZES) | FRAME_KEYS | PORTRAIT_KEYS
    check(
        len(assets) == 64,
        f"语义资源应恰有64项，实际{len(assets)}项 / "
        f"Expected exactly 64 semantic assets, found {len(assets)}",
    )
    check(
        set(assets) == expected_keys,
        "manifest 资源键与45图标+5卡框+14立绘不一致 / "
        "Manifest keys differ from 45 icons + 5 frames + 14 portraits",
    )

    for key, asset in assets.items():
        if not isinstance(asset, dict) or not isinstance(
            asset.get("variants"),
            list,
        ):
            errors.append(
                f"{key}: variants 无效 / variants must be an array"
            )
            continue

        actual_sizes: Set[Tuple[int, int]] = set()
        for variant_index, variant in enumerate(asset["variants"]):
            label = f"{key}[{variant_index}]"
            if (
                not isinstance(variant, dict)
                or not isinstance(variant.get("size"), list)
                or len(variant["size"]) != 2
                or not all(
                    isinstance(value, int) and value > 0
                    for value in variant["size"]
                )
            ):
                errors.append(
                    f"{label}: size 无效 / size must contain two positive integers"
                )
                continue

            declared = tuple(variant["size"])
            actual_sizes.add(declared)
            for extension in ("png", "webp"):
                try:
                    path = safe_asset_path(variant.get(extension))
                except ValueError as error:
                    errors.append(f"{label}.{extension}: {error}")
                    continue
                check(
                    path.suffix.lower() == f".{extension}",
                    f"{label}.{extension}: 扩展名错误 / Invalid extension",
                )
                if not path.is_file():
                    errors.append(
                        f"{label}.{extension}: 文件不存在 / File is missing: {path}"
                    )
                    continue
                try:
                    if extension == "png":
                        measured = png_metadata(path)[:2]
                    else:
                        measured = webp_dimensions(path)
                except (OSError, ValueError, zlib.error) as error:
                    errors.append(
                        f"{label}.{extension}: 无法解析 / Cannot parse: {error}"
                    )
                    continue
                check(
                    measured == declared,
                    f"{label}.{extension}: 声明{declared}，实际{measured} / "
                    f"Declared {declared}, measured {measured}",
                )

        expected_sizes = (
            ICON_SIZES.get(key)
            or (FRAME_SIZES if key in FRAME_KEYS else None)
            or (PORTRAIT_SIZES if key in PORTRAIT_KEYS else None)
        )
        if expected_sizes is not None:
            check(
                actual_sizes == expected_sizes,
                f"{key}: 尺寸集合错误 {actual_sizes} / "
                f"Invalid size set {actual_sizes}",
            )

    # 规范图标必须是带透明通道的32位 PNG / Specification icons must be 32-bit PNGs with alpha.
    for key in ICON_SIZES:
        asset = assets.get(key)
        if not isinstance(asset, dict):
            continue
        for variant in asset.get("variants", []):
            if not isinstance(variant, dict):
                continue
            try:
                path = safe_asset_path(variant.get("png"))
                _, _, bit_depth, color_type, has_alpha = png_metadata(
                    path,
                    inspect_alpha=True,
                )
                check(
                    bit_depth == 8 and color_type == 6,
                    f"{key}: PNG 必须为8位 RGBA / PNG must be 8-bit RGBA",
                )
                check(
                    has_alpha is True,
                    f"{key}: PNG 应保留透明背景像素 / "
                    "PNG must retain transparent background pixels",
                )
            except (OSError, ValueError, zlib.error) as error:
                errors.append(
                    f"{key}: 无法检查 PNG 透明度 / Cannot inspect PNG alpha: {error}"
                )

    # 卡框必须保持2:3画布与透明叠加区域 / Frames must preserve a 2:3 canvas and transparent compositing areas.
    for key in FRAME_KEYS:
        asset = assets.get(key)
        if not isinstance(asset, dict):
            continue
        for variant in asset.get("variants", []):
            if not isinstance(variant, dict):
                continue
            size = variant.get("size", [0, 0])
            if (
                not isinstance(size, list)
                or len(size) != 2
                or not all(isinstance(value, int) for value in size)
            ):
                continue
            check(
                size[0] * 3 == size[1] * 2,
                f"{key}: 卡框画布不是2:3 / Frame canvas is not 2:3",
            )
            try:
                path = safe_asset_path(variant.get("png"))
                _, _, bit_depth, color_type, has_alpha = png_metadata(
                    path,
                    inspect_alpha=True,
                )
                check(
                    bit_depth == 8 and color_type == 6,
                    f"{key}: 卡框必须为8位 RGBA / Frame must be 8-bit RGBA",
                )
                check(
                    has_alpha is True,
                    f"{key}: 卡框缺少透明像素 / Frame has no transparent pixels",
                )
            except (OSError, ValueError, zlib.error) as error:
                errors.append(
                    f"{key}: 无法检查卡框透明度 / "
                    f"Cannot inspect frame alpha: {error}"
                )

    for number in range(1, 15):
        key = f"general_g{number:03d}_portrait"
        asset = assets.get(key)
        check(
            isinstance(asset, dict)
            and {
                tuple(variant.get("size", []))
                for variant in asset.get("variants", [])
                if isinstance(variant, dict)
            } == PORTRAIT_SIZES,
            f"{key}: 必须有512x768与1024x1536双分辨率 / "
            "Must have 512x768 and 1024x1536 resolutions",
        )

    for sprite_stem, expected_size in SPRITES.items():
        for extension in ("png", "webp"):
            path = IMAGE_ROOT / "sprites" / f"{sprite_stem}.{extension}"
            if not path.is_file():
                errors.append(
                    f"{sprite_stem}.{extension}: Sprite 不存在 / Sprite is missing"
                )
                continue
            try:
                measured = (
                    png_metadata(path)[:2]
                    if extension == "png"
                    else webp_dimensions(path)
                )
            except (OSError, ValueError, zlib.error) as error:
                errors.append(
                    f"{sprite_stem}.{extension}: 无法解析 / Cannot parse: {error}"
                )
                continue
            check(
                measured == expected_size,
                f"{sprite_stem}.{extension}: 应为{expected_size}，实际{measured} / "
                f"Expected {expected_size}, measured {measured}",
            )

    return errors


def main() -> int:
    """输出双语验证结果 / Print bilingual validation results."""
    errors = validate()
    if errors:
        print(
            f"图像资源验证失败（{len(errors)}项） / "
            f"Image asset validation failed ({len(errors)} issue(s)):",
            file=sys.stderr,
        )
        for error in errors:
            print(f"- {error}", file=sys.stderr)
        return 1

    print("图像资源验证通过 / Image asset validation passed")
    print("语义资源：64 / Semantic assets: 64")
    print("规范图标：45类 / Specification icons: 45 families")
    print("卡框：5套2:3透明图层 / Card frames: five transparent 2:3 sets")
    print("武将立绘：14名、双分辨率、PNG+WebP / Portraits: 14, two resolutions, PNG+WebP")
    print("CSS Sprite：7套、PNG+WebP / CSS sprites: seven sets, PNG+WebP")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
