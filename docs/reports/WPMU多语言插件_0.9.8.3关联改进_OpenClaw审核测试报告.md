# WPMU 多语言插件 0.9.8.3 关联改进 OpenClaw 审核测试报告

## 1. 审核结论

审核时间：2026-07-21 03:56-04:00 GMT+8  
审核对象：`wpmu-multilingual/`  
被审核文档：`docs/WPMU多语言插件_0.9.8.3关联改进与测试汇报.md`  
插件版本：`0.9.8.3`

结论：**通过本轮代码审核、只读审计和小规模可回滚写入测试。**

本轮确认：

1. 生产运行代码未再发现同 ID 自动认领、同 slug 自动认领、关系表 `TRUNCATE`、危险 `REPLACE INTO`。
2. `rebuild_relations()`、CLI `wp wpmu-ml rebuild` 已拒绝执行旧版危险重建。
3. 关系表唯一键 `target_unique(target_blog_id,target_post_id)` 已存在。
4. 新文章正常同步时，目标文章使用 `wp_insert_post()` 返回的真实 ID，并写入来源 meta 与关系表。
5. 目标站 slug 被无关文章占用时，不认领、不覆盖、不写来源 meta、不创建该目标站关系、不创建该目标站翻译任务。
6. 测试数据已清理，关系总数、测试任务和测试文章无残留。

保留意见：本轮只在生产站执行了**只读审计 + 两个可回滚专用草稿测试**，未执行永久删除、同 ID 精确占用、并发唯一性、真实 OpenAI/OpenCC/Agent 写回全链路测试。这些仍建议在测试环境或专用测试文章继续灰度验证。

---

## 2. 审核范围

### 2.1 已审核内容

- 0.9.8.3 汇报文档完整阅读。
- 核心同步逻辑抽查：
  - `includes/core/traits/trait-wpmu-ml-core-sync.php`
  - `includes/core/traits/trait-wpmu-ml-core-foundation.php`
  - `includes/core/traits/trait-wpmu-ml-core-queue.php`
  - `includes/core/traits/trait-wpmu-ml-core-language-switcher.php`
  - Agent/OpenAI/OpenCC 相关入口的校验调用关键词抽查。
- WP-CLI 只读审计。
- PHP 语法检查。
- 数据库唯一索引检查。
- 旧版重建拒绝检查。
- 可回滚正常新文章同步测试。
- 可回滚 slug 占用冲突测试。

### 2.2 未执行内容

以下测试本轮未在生产业务数据上执行：

- 强制制造目标站相同 ID 占用。
- 源站永久删除同步。
- 目标站人工永久删除后的恢复/阻断全流程。
- 并发关系写入冲突。
- 真实 OpenAI、OpenCC、Agent、Agent Tools 全链路翻译写回。
- 大批量历史关系修复或迁移。

原因：这些操作有删除、覆盖、并发或外部 API 写入风险，不适合在未隔离环境里直接扩大执行。

---

## 3. 文档检查结果

被审核文档：

```text
docs/WPMU多语言插件_0.9.8.3关联改进与测试汇报.md
```

文档结论与代码方向一致，核心改进目标明确：

- 历史关系保留。
- 新文章不再依赖同 ID 或同 slug 猜测。
- 关系表作为主索引。
- 来源 meta 作为目标身份校验。
- slug 冲突阻断。
- 旧版重建禁用。
- 审计和恢复命令改为只读/严格模式。

文档列出的已知历史问题在本轮审计中复现一致：

```text
关系总数：149,617
源文章缺失：130
目标文章缺失：130
历史身份 meta 缺失：149,474
严格身份关系：13
slug 冲突关系：234
重复目标关系：0
身份 meta 冲突：0
```

---

## 4. 版本与启用状态

执行命令：

```bash
wp --allow-root plugin list --status=active --format=csv | grep wpmu-multilingual || true
wp --allow-root eval 'echo "WPMU_ML_VERSION=".(defined("WPMU_ML_VERSION")?WPMU_ML_VERSION:"NA")."\n"; echo "class=".(class_exists("WPMU_Multilingual")?WPMU_Multilingual::VERSION:"NA")."\n";' --skip-themes
```

结果：

```text
WPMU_ML_VERSION=0.9.8.3
class=0.9.8.3
```

结论：入口常量和核心类版本一致，为 `0.9.8.3`。

---

## 5. 静态代码审核

### 5.1 危险关键词检查

执行命令：

```bash
grep -R "TRUNCATE TABLE\|REPLACE INTO\|\$same_id_post\|find_target_post_by_slug\|get_post(\$source_post->ID" -n includes wpmu-multilingual.php
```

结果：无输出。

结论：未在运行代码中发现：

- 关系表 `TRUNCATE TABLE`。
- 危险 `REPLACE INTO`。
- `$same_id_post` 同 ID 自动认领。
- `find_target_post_by_slug()` slug 自动认领。
- `get_post($source_post->ID)` 作为目标自动认领。

### 5.2 PHP 语法检查

执行命令：

```bash
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l 2>&1 | grep -v 'No syntax errors detected'
```

结果：无输出。

结论：插件正式 PHP 文件未发现语法错误。

### 5.3 核心实现抽查

重点看到以下实现符合方案：

- `rebuild_relations()` 直接返回 `WP_Error`，拒绝生产重建。
- `validate_target_post_identity()` 集中校验：
  - 关系完整性；
  - 目标文章存在；
  - 目标文章是否被其他源关系占用；
  - post type 一致性；
  - 来源 meta 完整性与一致性；
  - 历史关系缺 meta 的兼容策略。
- `sync_one_target()` 在没有关系时只按完整来源 meta 查找目标，不再按同 ID/slug 猜测。
- 关系保存使用明确 `UPDATE`/`INSERT`，不是 `REPLACE`。
- 新建/更新成功后调用 `stamp_target_source_meta()` 写入：
  - `_wpmu_ml_source_blog_id`
  - `_wpmu_ml_source_post_id`
  - `_wpmu_ml_source_lang`
  - `_wpmu_ml_target_lang`
  - `_wpmu_ml_relation_version=2`
- 多处写回路径存在 `validate_post_relation()` / `validate_target_slug_availability()` / `force_target_slug_value()` 调用。

---

## 6. 数据库结构检查

执行命令：

```bash
wp --allow-root db query "SHOW INDEX FROM yzk_wpmu_ml_post_relations WHERE Key_name='target_unique'"
```

结果：

```text
Key_name: target_unique
Column 1: target_blog_id
Column 2: target_post_id
Non_unique: 0
```

结论：唯一约束 `target_unique(target_blog_id,target_post_id)` 已存在，可阻止同一个目标文章被多个源文章关系同时占用。

---

## 7. 旧版重建拒绝测试

执行命令：

```bash
wp wpmu-ml rebuild --allow-root --skip-themes; echo EXIT:$?
```

结果：

```text
Error: 生产关系重建已禁用。请先运行只读关系审计；0.9.8 不再清空关系表后按 ID 或 slug 猜测关联。
EXIT:1
```

结论：旧版危险重建已拒绝执行，符合预期。

---

## 8. 只读审计基线

执行命令：

```bash
wp wpmu-ml audit-relations --summary --allow-root --skip-themes
```

结果摘要：

```text
TOTAL relations=149617
source_missing=130
target_missing=130
source_type_conflict=0
target_type_conflict=0
identity_meta_missing=149474
identity_meta_conflict=0
strict_identity=13
slug_conflicts=234
invalid_status=0
duplicate_targets=0
```

结论：审计结果与 0.9.8.3 汇报文档一致；审计命令只读执行，未修改数据库。

---

## 9. 可回滚测试一：正常新文章同步

### 9.1 测试目的

验证新文章首次同步不会要求目标站同 ID，而是使用 `wp_insert_post()` 返回的实际目标 ID，并写入关系和来源 meta。

### 9.2 测试方法

创建源站 Blog 2 专用草稿：

```text
OPENCLAW-RELATION-AUDIT-20260720195811-iJyYln
```

触发：

```php
WPMU_Multilingual::instance()->sync_source_post_to_targets($post_id, true)
```

随后检查 Blog 1 的关系、目标文章 meta 和翻译任务，最后删除测试源文章、目标文章、关系和任务。

### 9.3 测试结果

```json
{
  "source_post_id": 12416115,
  "sync_result": {
    "targets": 13,
    "queued": 13,
    "failed": 0
  },
  "relation_blog1": {
    "target_post_id": 12426333,
    "relation_status": "needs_translation",
    "post_type": "post"
  },
  "target_blog1": {
    "exists": true,
    "post_name": "openclaw-relation-audit-20260720195811-ijyyln",
    "source_blog_meta": "2",
    "source_post_meta": "12416115",
    "relation_version": "2"
  },
  "jobs": 13,
  "cleanup": "done"
}
```

### 9.4 结论

通过。

关键点：

- 源站测试文章 ID：`12416115`。
- Blog 1 目标文章 ID：`12426333`，不是源站同 ID。
- 关系表记录了实际目标 ID。
- 目标文章写入了来源 meta 和 `relation_version=2`。
- 13 个目标站均创建关系并入队。
- 测试数据已清理。

---

## 10. 可回滚测试二：目标 slug 被无关文章占用

### 10.1 测试目的

验证目标站已有无关文章占用相同 slug 时，插件不会认领、覆盖或写入错误关系。

### 10.2 测试方法

1. 在 Blog 1 创建无关目标草稿，占用测试 slug。
2. 在源站 Blog 2 创建相同 slug 的测试草稿。
3. 触发全站同步。
4. 检查 Blog 1 是否建关系、是否建任务、占用文章是否被改写或写 meta。
5. 清理所有测试数据。

测试标记：

```text
OPENCLAW-SLUG-CONFLICT-20260720195851-XhzZnD
```

### 10.3 测试结果

```json
{
  "occupant_id": 12426335,
  "source_post_id": 12416116,
  "sync_result": {
    "targets": 12,
    "queued": 12,
    "failed": 1
  },
  "relation_blog1": null,
  "occupant_unchanged": true,
  "occupant_source_meta": ["", ""],
  "jobs_blog1": 0,
  "jobs_total": 12,
  "cleanup": "done"
}
```

### 10.4 结论

通过。

关键点：

- Blog 1 的无关占用文章未被覆盖。
- Blog 1 的无关占用文章未被写入 `_wpmu_ml_source_*` meta。
- Blog 1 未创建错误关系。
- Blog 1 未创建错误翻译任务。
- 其他 12 个未冲突目标站继续正常同步入队。
- 测试数据已清理。

---

## 11. 测试后清理确认

执行命令：

```bash
wp wpmu-ml audit-relations --summary --allow-root --skip-themes | tail -5
wp --allow-root db query "SELECT COUNT(*) AS rels FROM yzk_wpmu_ml_post_relations; SELECT COUNT(*) AS test_jobs FROM yzk_wpmu_ml_translation_jobs WHERE source_post_id IN (12416115,12416116);"
wp --allow-root db query "SELECT COUNT(*) AS leftover_posts FROM yzk_2_posts WHERE post_title LIKE 'OPENCLAW-%'; SELECT COUNT(*) AS leftover_target_posts FROM yzk_posts WHERE post_title LIKE 'OPENCLAW-%';"
```

结果：

```text
TOTAL relations=149617 source_missing=130 target_missing=130 source_type_conflict=0 target_type_conflict=0 identity_meta_missing=149474 identity_meta_conflict=0 strict_identity=13 slug_conflicts=234 invalid_status=0 duplicate_targets=0

rels=149617
test_jobs=0
leftover_posts=0
leftover_target_posts=0
```

结论：测试未污染关系总数，无测试任务或测试文章残留。

---

## 12. 风险与建议

### 12.1 已知历史数据风险

当前仍存在历史数据问题：

```text
source_missing=130
target_missing=130
identity_meta_missing=149474
slug_conflicts=234
```

这些不是本轮测试制造。本轮代码策略是报告和阻断危险写入，不自动修改历史 URL、slug、内容或关系。这个策略正确。

### 12.2 建议继续测试

建议后续在测试环境或专用测试文章继续完成：

1. **相同 ID 占用测试**：构造目标站同 ID 无关文章，确认不认领并新建其他目标 ID。
2. **真实翻译写回测试**：OpenAI/OpenCC/Agent/Agent Tools 各跑一篇专用文章。
3. **生命周期测试**：源站回收、恢复；目标站人工回收、恢复；永久删除仅测试环境执行。
4. **并发唯一性测试**：同时写入同一目标，确认唯一键阻止第二条关系且不替换旧关系。
5. **语言切换/hreflang 测试**：用可索引测试站确认有效关系输出正确，异常关系不输出。

### 12.3 一个实现细节建议

在 `sync_one_target()` 的新建路径中，`wp_insert_post()` 成功后如果后续 `force_target_slug_value()` 返回 `target_slug_conflict`，当前逻辑会返回错误且不建关系，但可能已经留下一个未关联的目标草稿。虽然插入前已有 `validate_target_slug_availability()`，正常冲突会提前挡住；但如果插入后出现竞态或过滤器导致 slug 变化，建议后续补充清理策略：

```text
新建目标文章后 slug lock 失败
→ 删除刚创建的目标草稿，或标记为 relation_create_failed_orphan
→ 记录 target_post_id 供人工清理
```

这不是本轮阻断项，但属于并发/异常路径的稳健性改进。

---

## 13. 最终判断

本轮审核认为：

```text
0.9.8.3 的文章关联改进方向正确，关键生产风险已明显降低。
```

可接受进入小范围灰度，但不建议立即执行批量历史修复或高风险生命周期测试。后续应按测试矩阵继续补齐翻译写回、删除恢复、并发与相同 ID 构造测试。
