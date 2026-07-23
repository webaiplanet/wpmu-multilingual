# WPMU 多语言插件 0.9.8.8 taxonomy-term 同步审核说明

日期：2026-07-24

## 结论

本版本通过审核。

### 已验证能力

- 源站 taxonomy term 新增，可同步到启用的目标语言站。
- 源站 taxonomy term 修改，可同步更新到目标语言站。
- 源站 taxonomy term 删除，可同步删除目标语言站对应 term。
- 目标站 term ID 与源站 term ID 不要求一致，依赖 `wpmu_ml_term_relations` 做映射。
- 分站关闭后不会参与同步。
- 文章保存时的分类/标签关系映射仍可正常工作。

### 关键实现

- 已新增 taxonomy term 同步 trait。
- 已接入 `created_term` / `edited_term` / `delete_term`。
- 已支持 hierarchical taxonomy 的 parent 递归同步。
- 已支持关系修复：目标 term 缺失时可按 slug / 关系表重建。

### 验证结果

- PHP 语法检查通过。
- `tests/term-sync-smoke.php` 通过。
- `tests/incremental-sync-smoke.php` 通过。
- 额外 CRUD 实测通过：
  - 新增同步通过
  - 修改同步通过
  - 删除同步通过
  - 关闭语言站不参与同步
  - 关系表清理正常

## 当前边界

当前版本只做了 taxonomy term 本体同步，尚未接入 term 名称/描述的翻译开关。

也就是说：

- 现在同步的是“源站是什么，目标站就是什么”。
- 还没有“term 本体同步后再按语言翻译 name/description”的开关。

## 下一步要改的处理状态

以下事项已在 `0.9.8.9` 中处理：

### 1. 增加 taxonomy term 翻译开关

已新增设置：

- 是否翻译分类/标签名称
- 是否翻译分类/标签描述

默认关闭，避免影响现有 term 内容。

### 2. 接入 term 翻译流程

在 term 同步到目标站时：

- 对 `name` 进行翻译
- 对 `description` 进行翻译
- `slug` 仍建议先保持源站同步，不做自动翻译

当前实现：OpenAI 兼容引擎翻译 name/description；繁体中文目标语言可使用 OpenCC 转换；manual 或不支持引擎会保留源站文本并记录日志。

### 3. 保持 term CRUD 同步规则不变

翻译开关只影响同步后写入目标站的展示内容，不改变以下规则：

- 源站为主
- 目标站按关系表映射
- 新增 / 修改 / 删除仍同步
- 关闭站点不参与同步

### 4. 补充验收测试

已补充非 API 回归验证：

- 开关关闭：term 只同步，不翻译
- 删除流程不受翻译开关影响
- 文章分类/标签关系映射不受影响

真实 OpenAI 翻译写回未在本轮自动测试中调用，建议后续用可丢弃 term 做一次小范围人工验证。

## 备注

0.9.8.8 已满足“分类/标签和文章一样做增删改同步规则”的目标；0.9.8.9 已补上 term name/description 翻译开关。
