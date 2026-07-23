# WPMU 多语言插件 0.9.8.5 同步策略问题处理报告

## 1. 处理结论

`0.9.8.4` 问题报告属实：生产 `trait-wpmu-ml-core-sync.php` 曾被截断并由旧站备份覆盖，导致多个安全方法从运行类中消失。`wp wpmu-ml audit-relations --summary` 已实测因方法不存在而 fatal。

本次没有把当前旧文件视为可信版本继续局部补齐，而是新增独立关系安全 trait，重建安全契约，并把实际同步、翻译写回和生命周期入口重新接入该安全层。处理后版本为 `0.9.8.5`。

## 2. 已恢复能力

- `validate_target_post_identity()`
- `validate_post_relation()`
- `validate_translation_job_target()`
- `stamp_relation_target_identity()`
- `mark_relation_invalid()`
- `audit_post_relations()`
- `audit_post_relations_summary()`
- `reconcile_post_relations_from_meta()`
- 完整来源 meta 多候选检测
- 目标文章唯一占用检查
- 历史关系兼容和严格身份区分
- 删除、回收站、恢复身份保护
- 目标站人工生命周期状态跟踪

## 3. 当前同步规则

```text
已有关系且身份有效
→ 更新明确目标

没有关系，但找到唯一完整来源 meta
→ 校验身份后认领

没有关系，也没有完整来源 meta
→ 创建新目标，使用 WordPress 返回的实际 ID
```

禁止使用相同 ID 或相同 slug 认领已有目标。

## 4. slug 冲突新规则

新目标的源 slug 已被目标站其他文章占用时：

1. 不认领、不覆盖占用文章。
2. fallback slug 使用 `源slug-源文章ID`。
3. 新目标强制为 `draft`。
4. 写入来源身份 meta 和关系版本 2。
5. 写入 `_wpmu_ml_slug_conflict_source_slug`。
6. 写入 `_wpmu_ml_slug_conflict_fallback_slug`。
7. 写入 `_wpmu_ml_slug_conflict_requires_review=1`。
8. 正常建立关系并创建翻译任务。
9. 记录 `target_slug_conflict_fallback` 日志。

fallback 目标在队列覆盖、OpenAI、OpenCC 和 Agent 写回时继续使用 fallback slug，并保持草稿，直到人工处理。

## 5. 已完成验证

- 全部正式 PHP 文件语法检查通过。
- 插件和安全 trait 可正常加载。
- 8 个安全 API 均由运行类提供。
- 插件在全网启用，运行版本为 `0.9.8.5`。
- 严格身份样本：`strict_identity`。
- 错误目标样本：`relation_target_mismatch`。
- 历史 slug 冲突样本：`target_slug_conflict`。
- `audit-relations --summary` 全站汇总恢复成功，未写数据库。
- `reconcile-relations --target_blog_id=1 --limit=500` dry-run 恢复成功：扫描 1、已有 1、候选 0、写入 0、冲突 0、错误 0。
- `repair-one-slug --dry-run` 安全拒绝历史冲突，没有修改文章。
- `rebuild` 继续拒绝生产关系重建。
- `reconcile-relations --apply` 缺少确认短语时正确拒绝写入。
- 静态检查未发现旧的 `find_target_post_by_slug`、`TRUNCATE` 或 `REPLACE INTO` 入口。
- 语言切换器中的源 ID 赋值仅用于生成返回源站的 URL，不参与目标认领、文章写入或关系写入。
- 同步 trait 中旧生命周期实现已移除，三个公开生命周期 hook 统一进入安全实现。
- 插件根目录没有散落的 Markdown 文件，文档均保存在 `docs/`。
- `doctor` 成功，关系、任务和分类关系数量保持基线。

## 6. 数据基线

```text
文章关系：149,617
翻译任务：143,869
分类关系：39,750
重复目标关系：0
严格身份：13
身份 meta 缺失：149,474
历史 slug 冲突：234
fallback 待复核：0
源文章缺失：130
目标文章缺失：130
```

上述缺失和 slug 冲突是只读汇总发现的历史存量；本次没有自动修复、删除或重建这些关系。`target_unique (target_blog_id, target_post_id)` 唯一索引仍有效。

## 7. 仍待人工测试

- fallback 草稿二次同步保持 fallback slug。
- OpenAI、OpenCC、Agent 的真实 fallback 翻译写回。
- 已有正常关系更新。
- 同 ID 无关系且 slug 不同的目标占用场景。
- 源站回收、恢复和永久删除。
- 目标站人工回收、恢复和永久删除。
- 并发唯一关系写入。

永久删除、并发和真实翻译测试应使用隔离环境或明确可丢弃的测试对象。

报告日期：2026-07-21  
处理版本：0.9.8.5
