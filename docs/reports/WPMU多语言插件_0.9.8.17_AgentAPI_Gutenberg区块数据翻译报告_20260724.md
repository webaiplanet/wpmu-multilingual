# WPMU多语言插件 0.9.8.17 Agent API Gutenberg 区块数据翻译报告

日期：2026-07-24

## 问题

文章正文里的普通可见内容已经通过 Agent API 翻译，但 ACF 区块注释 JSON 中的文字没有翻译。

典型位置：

```text
<!-- wp:acf/... {"data":{"xxx_title":"开始免费试用"}} /-->
```

这些文字在编辑器里可见，但实际存储在 Gutenberg 注释 JSON 的 value 中，不是普通 HTML 文本，也不是 postmeta。

## 修复

1. Agent API payload 新增 `field_scope=gutenberg` 字段。
2. 只提取 Gutenberg / ACF block 注释 JSON 中的人类可读文本。
3. URL、字段 key、ACF field key、ID、slug、媒体字段继续跳过。
4. 外部 Agent 仍然必须让 `post_content` 中的 Gutenberg 注释原样保留。
5. `/agent/result` 写回时根据 `comment_index` 和 `value_path` 精确更新 JSON value。

## 测试文章

源文章 ID：`12356119`

已验证“相关链接设置”区块标题可以通过 Agent API 独立字段写回英文。
