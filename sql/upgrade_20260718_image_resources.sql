-- 种火集结号 - 图像资源显示模式升级 / Fireseed Engage - Image-resource display-mode upgrade

-- 只补齐缺失配置，绝不覆盖管理员已经选择的显示模式。 / Add the missing setting without overwriting an administrator's selected mode.
INSERT IGNORE INTO `game_config`
(`key`,`value`,`description`,`is_constant`,`category`) VALUES
(
  'image_display_mode',
  'image',
  '全局图像显示模式：image=正式图像，emoji_fallback=仅显示Emoji回退 / Global image mode: image or emoji_fallback',
  0,
  'display'
);
