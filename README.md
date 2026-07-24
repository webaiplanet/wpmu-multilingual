# WPMU Multilingual

Languages: [简体中文](#简体中文) | [English](#english)

## 简体中文

WPMU Multilingual 是一个面向 WordPress Multisite 的多语言插件，用于在多个子站之间建立文章、分类、标签和语言切换关系，并提供 hreflang、自动同步、翻译队列、OpenCC 和 OpenAI 兼容翻译能力。

这个仓库是插件源码，不绑定某个具体网站。

## 适用场景

- 一个 WordPress Multisite 网络中，每种语言使用一个独立子站。
- 需要在源语言站和目标语言站之间维护文章关联。
- 需要前台语言切换器和 SEO `alternate hreflang` 输出。
- 需要自动同步源站文章更新到目标语言站。
- 需要使用 OpenAI 兼容接口、OpenCC 或外部 Agent 流程辅助翻译。

## 主要功能

- 多站点语言配置：语言标识、Locale、hreflang、排序、前台默认站点。
- 文章关联管理：源文章与目标语言文章关系表、关系审核、关系修复工具。
- 自动同步：支持标题、正文、摘要、slug、meta、分类/标签关系同步。
- 增量更新：已翻译文章更新时记录变更字段，避免整篇覆盖译文。
- 分类/标签同步：支持 taxonomy term 新增、编辑、删除同步。
- term 翻译开关：可分别控制 term `name` 和 `description` 是否翻译，`slug` 默认保持源站同步。
- 语言切换器：支持代码调用和菜单调用，可配置旗帜、未发布语言处理方式。
- hreflang：前台自动输出已发布且可索引目标文章的 `alternate hreflang`。
- 翻译队列：支持 OpenAI 兼容接口、Agent API、手动处理等流程；OpenCC 仅用于简体中文源站到繁体中文目标站的简繁转换。
- WP-CLI 工具：用于关系审核、关系修复、翻译队列处理和回归测试。

## 安装

1. 将插件目录放到：

   ```text
   wp-content/plugins/wpmu-multilingual/
   ```

2. 在 WordPress Multisite 网络后台启用插件。

3. 进入网络后台的“WPMU 多语言”设置页面，配置语言站点。

建议使用 Network Activate 网络启用。

## 基本配置流程

1. 在“语言站点”中启用参与多语言的子站，并设置源站和前台默认站点。
2. 在“内容类型”中选择参与翻译或共享发布的 post type。
3. 选择需要同步的 taxonomy，例如 `category`、`post_tag` 或自定义 taxonomy。
4. 在“自动同步”中配置需要同步的字段。
5. 在“语言切换”中选择代码调用或菜单调用，并设置未发布语言处理方式。
6. 如需机器翻译，在翻译引擎相关设置中配置 OpenAI 兼容接口、OpenCC 或 Agent API。

## 语言切换器调用

主题模板中可以直接调用：

```php
if (function_exists('wpmu_ml_language_switcher')) {
    wpmu_ml_language_switcher();
}
```

也可以在插件后台启用菜单调用后，通过 WordPress “外观 → 菜单”添加语言切换项。

## WP-CLI 示例

审核文章关系：

```bash
wp wpmu-ml audit-relations --summary --allow-root --skip-themes
```

预览关系修复：

```bash
wp wpmu-ml reconcile-relations --target_blog_id=目标站ID --limit=500 --allow-root --skip-themes
```

处理翻译队列：

```bash
wp wpmu-ml translate --limit=1 --lang=en --allow-root --skip-themes
```

## 当前版本

当前开发版本：`0.9.8.18`

该插件仍处于持续开发阶段。建议先在测试环境验证语言站点、文章关系、自动同步和翻译队列，再用于生产环境。

## 文档

- 文档索引：[`docs/INDEX.md`](docs/INDEX.md)
- 更新日志：[`docs/changelog/CHANGELOG.md`](docs/changelog/CHANGELOG.md)
- 架构与翻译规则：[`docs/reference/ARCHITECTURE_AND_TRANSLATION_RULES.md`](docs/reference/ARCHITECTURE_AND_TRANSLATION_RULES.md)
- 测试和处理报告：[`docs/reports/`](docs/reports/)

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).

## English

WPMU Multilingual is a WordPress Multisite multilingual plugin. It connects language sites, manages post and taxonomy relationships, outputs `alternate hreflang`, synchronizes source content to target language sites, and supports translation workflows through OpenCC, OpenAI-compatible APIs, Agent APIs, and manual processing.

This repository contains the plugin source code and is not tied to any specific website.

## Use Cases

- One WordPress Multisite network where each language uses a separate subsite.
- Source-language posts need to be linked with their target-language versions.
- Frontend language switchers and SEO `alternate hreflang` output are required.
- Source post updates need to be synchronized to target language sites.
- OpenAI-compatible translation, OpenCC conversion, or external Agent workflows are needed.

## Features

- Language site configuration: language slug, Locale, hreflang, sort order, source site, and frontend default site.
- Post relationship management: source-to-target post relations, audits, and repair tools.
- Automatic synchronization: title, content, excerpt, slug, meta, and taxonomy relationships.
- Incremental updates: translated posts can keep existing translations while recording changed fields.
- Taxonomy term synchronization: create, update, and delete terms across language sites.
- Term translation switches: separately control term `name` and `description` translation; `slug` stays synchronized from the source by default.
- Language switcher: code-based and menu-based rendering, flags, and unpublished-language handling.
- hreflang output: only published and indexable target posts are included.
- Translation queue: OpenAI-compatible APIs, Agent API, and manual workflows; OpenCC is only used for Simplified Chinese source sites converting to Traditional Chinese target sites.
- WP-CLI tools: relation audits, relation repair, queue processing, and regression tests.

## Installation

1. Place the plugin directory at:

   ```text
   wp-content/plugins/wpmu-multilingual/
   ```

2. Network-activate the plugin in WordPress Multisite.

3. Open the network admin plugin settings page and configure language sites.

Network activation is recommended.

## Basic Setup

1. Enable language subsites and choose the source site and frontend default site.
2. Select translatable or shared post types.
3. Select taxonomies to synchronize, such as `category`, `post_tag`, or custom taxonomies.
4. Configure automatic synchronization fields.
5. Configure the language switcher render mode and unpublished-language behavior.
6. Configure OpenAI-compatible APIs, OpenCC, or Agent API settings if machine translation is needed.

## Language Switcher

Use this in a theme template:

```php
if (function_exists('wpmu_ml_language_switcher')) {
    wpmu_ml_language_switcher();
}
```

You can also enable menu mode in the plugin settings and add the language switcher from WordPress “Appearance → Menus”.

## WP-CLI Examples

Audit post relations:

```bash
wp wpmu-ml audit-relations --summary --allow-root --skip-themes
```

Preview relation repair:

```bash
wp wpmu-ml reconcile-relations --target_blog_id=TARGET_SITE_ID --limit=500 --allow-root --skip-themes
```

Process one translation queue item:

```bash
wp wpmu-ml translate --limit=1 --lang=en --allow-root --skip-themes
```

## Current Version

Current development version: `0.9.8.18`

The plugin is still under active development. Test language sites, post relations, synchronization, and translation queues in a staging environment before production use.

## Documentation

- Documentation index: [`docs/INDEX.md`](docs/INDEX.md)
- Changelog: [`docs/changelog/CHANGELOG.md`](docs/changelog/CHANGELOG.md)
- Architecture and translation rules: [`docs/reference/ARCHITECTURE_AND_TRANSLATION_RULES.md`](docs/reference/ARCHITECTURE_AND_TRANSLATION_RULES.md)
- Reports: [`docs/reports/`](docs/reports/)

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
