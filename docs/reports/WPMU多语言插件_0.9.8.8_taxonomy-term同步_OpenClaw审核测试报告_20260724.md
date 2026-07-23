# WPMU 多语言插件 0.9.8.8 taxonomy term 同步 OpenClaw 审核测试报告

## 1. 审核结论

审核时间：2026-07-24  
审核对象：`wpmu-multilingual/`  
被审核版本：`0.9.8.8`

结论：通过本轮代码改造、语法检查和回滚 smoke 验证。

本轮确认：

1. 新增 taxonomy term 本体 CRUD 同步，源站新增、修改、删除 `sync_taxonomies` 白名单内的 term 会同步到启用目标站。
2. term 关系不再依赖 ID 相同；编辑时若关系缺失，会按当前 term 尝试同 slug + taxonomy、同 ID + taxonomy 认领并修复关系。
3. hierarchical taxonomy 会先同步父级，目标站 parent 使用目标站父级 term ID。
4. 源站删除 term 时，只删除关系表确认的目标 term，不按 slug 模糊删除。
5. 文章同步时，分类/标签关系从“源 term ID”映射到“目标 term ID”，避免多语言站 term ID 不一致时挂错关系。
6. 回滚 smoke 通过，测试 term 与测试文章最终均已清理。

保留意见：本轮未做真实生产分类树批量迁移，也未对外部 AI 翻译 term 名称做接入，仅完成 term 本体同步与文章 term 关系映射。

---

## 2. 审核范围

### 2.1 已审核内容

- 新增 `WPMU_ML_Core_Term_Sync_Trait`。
- 核心加载器注册 term CRUD hook。
- 文章同步里的 term ID 映射逻辑。
- `sync_taxonomies` 白名单复用。
- `docs/README.md`、`docs/changelog/CHANGELOG.md`、`docs/logs/ARTICLE_RELATION_IMPROVEMENT_LOG.md` 更新。
- 回滚 smoke 脚本 `tests/term-sync-smoke.php`。

### 2.2 未执行内容

- 真实 OpenAI / OpenCC / Agent term 翻译写回。
- 大规模历史 term 迁移。
- 生产站全量分类树回填。
- 不同站点并发 term 写入压力测试。

---

## 3. 代码改动摘要

- 新增 term 同步 trait，处理：
  - `created_term`
  - `edited_term`
  - `delete_term`
- 新增 term 关系读写与修复逻辑：
  - 优先查 `wpmu_ml_term_relations`
  - 缺失时尝试同 slug + taxonomy、同 ID + taxonomy 认领
  - hierarchical taxonomy 递归同步父级
- 删除时按关系表精确删除目标 term，并清理关系。
- 文章同步分类关系改为源 term ID 到目标 term ID 映射。
- 版本号同步为 `0.9.8.8`。

---

## 4. 静态检查

执行命令：

```bash
find wpmu-multilingual -name '*.php' -print0 | xargs -0 -n1 php -l
```

结果：全部通过，无 PHP 语法错误。

---

## 5. 回滚 smoke

执行命令：

```bash
wp eval-file wpmu-multilingual/tests/term-sync-smoke.php --allow-root --skip-themes
```

结果：

```json
{
  "ok": true,
  "source_blog_id": 2,
  "target_blog_id": 1,
  "created_relation_target_id": 4405,
  "repaired_relation_target_id": 4408,
  "category_parent_target_id": 4406,
  "category_child_target_id": 4407,
  "mapped_post_target_id": 12426380
}
```

验证点：

- 源站新增 `post_tag` 后，目标站自动创建对应 term。
- 源站修改 `post_tag` 后，目标站自动更新对应 term。
- 源站 hierarchical `category` 父子 term 正常同步，目标 parent 指向目标站父级 term ID。
- 手动删除目标站 term 后，再编辑源站 term，可重新创建并修复关系。
- 源文章挂载源站 term 后，目标文章挂载的是目标站 term ID，不是源站 term ID。

---

## 6. 增量回归

执行命令：

```bash
wp eval-file wpmu-multilingual/tests/incremental-sync-smoke.php --allow-root --skip-themes
```

结果：通过。

说明：本轮 term 同步改动没有破坏已有文章字段增量同步逻辑。

---

## 7. 残留检查

验证后检查结果：

- 测试 term 残留：0
- 测试文章残留：0

---

## 8. 版本信息

- 插件入口版本：`0.9.8.8`
- 核心类版本：`0.9.8.8`
- 网络安装版本：`0.9.8.8`

