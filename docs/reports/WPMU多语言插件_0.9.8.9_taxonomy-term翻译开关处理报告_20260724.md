# WPMU 多语言插件 0.9.8.9 taxonomy term 翻译开关处理报告

## 处理目标

处理 `WPMU多语言插件_0.9.8.8_taxonomy-term同步审核说明_20260724.md` 中“下一步要改的”事项：

- 增加 taxonomy term 翻译开关。
- term 同步后可翻译 `name` 和 `description`。
- `slug` 保持源站同步，不自动翻译。
- 不改变源站到目标站的 CRUD 同步规则。

## 当前规则

```text
源站新增/编辑 term
→ 按 0.9.8.8 规则同步或修复目标 term
→ 如果 term 翻译开关关闭：目标站保留源站 name/description
→ 如果 term 翻译开关开启：按目标语言翻译 name/description
→ slug 始终同步源站 slug，不翻译
→ 翻译失败只记录日志，不阻断 term 同步
```

## 新增设置

- `translate_term_name`：是否翻译分类/标签名称。
- `translate_term_description`：是否翻译分类/标签描述。

两个开关默认关闭，避免升级后改变现有分类/标签显示内容。

## 引擎行为

- OpenAI 兼容：复用现有纯文本翻译能力和目标语言配置。
- OpenCC：繁体中文目标语言可转换 name/description。
- manual 或不支持引擎：保留源站文本，并记录 `term_translation_skipped`。
- 翻译失败：保留源站文本，并记录 `term_translation_error`。

## 验证结果

已执行：

```bash
php -l wpmu-multilingual.php
php -l includes/core/class-wpmu-ml-core.php
php -l includes/core/traits/trait-wpmu-ml-core-term-sync.php
php -l includes/core/traits/trait-wpmu-ml-core-foundation.php
php -l includes/core/traits/trait-wpmu-ml-core-admin-actions.php
php -l includes/core/traits/trait-wpmu-ml-core-admin-ui.php
wp eval-file tests/term-sync-smoke.php --allow-root --skip-themes
wp eval-file tests/incremental-sync-smoke.php --allow-root --skip-themes
```

结果：

- PHP 语法检查通过。
- term CRUD smoke 通过。
- term smoke 新增断言：翻译开关默认关闭，不调用外部 API。
- 文章字段增量同步回归通过。

## 未执行项目

本轮未调用真实 OpenAI API，也未执行真实 OpenCC term 写回专项测试。建议后续在可丢弃 term 上开启开关做一次小范围人工验证。

报告日期：2026-07-24  
处理版本：0.9.8.9
