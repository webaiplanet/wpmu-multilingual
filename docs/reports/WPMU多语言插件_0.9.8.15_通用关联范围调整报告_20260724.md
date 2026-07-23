# WPMU多语言插件 0.9.8.15 通用关联范围调整报告

日期：2026-07-24

## 处理结论

本次把关联范围从“按当前网站现有对象判断”调整为“按 WordPress 通用对象模型判断”。

插件现在默认把站点编辑器相关 post-like 对象纳入可关联、可翻译范围：

- `wp_template`
- `wp_template_part`
- `wp_navigation`

这些对象会复用现有文章关系表 `wpmu_ml_post_relations`，不新增关系表。

## 已修改内容

1. 版本提升到 `0.9.8.15`。
2. 新安装默认配置里，`translatable_post_types` 增加：
   - `wp_template`
   - `wp_template_part`
   - `wp_navigation`
3. 已安装站点升级到 `0.9.8.15` 时，会自动把上述 3 个类型补进 `translatable_post_types`。
4. 后台“参与翻译 / 共享发布”候选列表不再硬排除上述 3 个类型。
5. 文档同步说明 FSE 对象属于可关联、可翻译范围。

## 仍然不关联 / 不翻译的范围

以下类型继续不按普通内容处理：

- `attachment`：媒体文件不做跨语言关联。
- 用户相关数据：不做关联。
- `nav_menu_item`：经典菜单项暂不按普通文章处理，避免菜单项内对象 ID 未映射导致错链。
- ACF 配置类：`acf-field-group`、`acf-field`、`acf-post-type`、`acf-taxonomy`、`acf-ui-options-page`。
- `wp_global_styles`：全局样式属于外观配置，不作为翻译内容处理。
- `revision`、`custom_css`、`customize_changeset`、`oembed_cache`、`user_request` 等系统对象。

## 测试

已执行 PHP 语法检查：

```bash
find . -name '*.php' -print0 | xargs -0 -n1 php -l
```

结果：全部通过。

## 后续建议

经典菜单 `nav_menu_item/nav_menu` 如果后续要支持，不能简单加入普通文章同步，需要单独做菜单同步逻辑，并在菜单项中把文章 ID、分类 ID 映射到目标语言对象。
