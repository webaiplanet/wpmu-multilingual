# WPMU多语言插件 0.9.8.16 Agent API 写回校验修复报告

日期：2026-07-24

## 背景

使用真实 HTTP Agent API 翻译文章时，`/agent/result` 已经写入目标文章，但返回 `wpmu_ml_agent_post_writeback_mismatch`。

触发原因是 WordPress 保存正文时会把 `&` 标准化为 `&amp;`，并把 `<img .../>` 标准化为 `<img ... />`。Agent API 的回读校验之前按原始字符串比较，导致保存后的等价内容被误判为不一致。

## 修复内容

1. `post_content` / `post_excerpt` 回读校验改为兼容 WordPress 实体标准化和 HTML 自闭合标签空格标准化结果。
2. Agent API result 写回期间增加 `_wpmu_ml_agent_result_writeback_running` 内部标记。
3. 目标文章发布状态钩子检测到该内部标记时跳过，避免任务在 `/agent/result` 完整校验和收尾前被提前标记完成。

## 保持不变

以下结构保护仍然严格执行：

- Gutenberg 区块注释必须一致。
- HTML 标签集合必须一致。
- URL 必须一致。
- 短代码和占位符必须一致。
- source_hash 不匹配仍会拒绝写回并重新入队。

## 测试

使用真实 HTTP API 流程测试：

1. `GET /wp-json/wpmu-ml/v1/agent/next`
2. `POST /wp-json/wpmu-ml/v1/agent/claim`
3. `GET /wp-json/wpmu-ml/v1/agent/payload`
4. `POST /wp-json/wpmu-ml/v1/agent/result`

测试文章：`12356119`

结果：`/agent/result` 返回成功，目标英文文章写回并发布。
