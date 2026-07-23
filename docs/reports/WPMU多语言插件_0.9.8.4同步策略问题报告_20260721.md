# WPMU 多语言插件 0.9.8.4 同步策略问题报告

时间：2026-07-21 05:42（Asia/Shanghai）

站点路径：`wpmu-multilingual/`

插件路径：`wpmu-multilingual/`

插件版本：`0.9.8.4`

---

## 1. 背景

本次排查/修改围绕文章同步关系安全，重点是避免目标分站已有文章被源站后发文章错误认领、覆盖。

此前已确认的安全原则：

1. 不再依赖同 ID / 同 slug 猜测文章关联。
2. 已有关联时按关系表更新目标文章。
3. 没有关联时，不允许自动认领目标站已有同 ID 或同 slug 的旧文章。
4. 目标站 slug 冲突时，不应覆盖已有文章。
5. 老板最新要求：slug 冲突不应完全阻断同步，而应新建目标对象，slug 改为 `源站 slug-源站文章 ID`，并强制草稿，后续人工处理。

---

## 2. 本次发生的问题

### 2.1 严重操作事故：sync trait 文件曾被截断

文件：

`includes/core/traits/trait-wpmu-ml-core-sync.php`

在尝试修改同步逻辑时，因为脚本写入失败，该文件曾被截断为 0 字节。

随后为避免插件缺文件导致不可加载，临时从旧备份路径复制了旧版文件回生产插件目录：

`wpmu-multilingual/includes/core/traits/trait-wpmu-ml-core-sync.php`

这个旧备份能让插件加载，但它不是完整的 0.9.8.4 最新安全逻辑版本，存在功能回退风险。

### 2.2 临时恢复文件缺失部分 0.9.8.4 安全能力

旧备份版本中存在风险逻辑：

- 关系表缺失或目标 ID 不一致时，旧代码会按以下顺序兜底查找目标文章：
  1. 同 ID；
  2. 来源 meta；
  3. 同 slug + post_type。

这与 0.9.8.4 的安全目标冲突：不能通过同 ID / 同 slug 盲目认领已有目标文章。

### 2.3 后续补丁曾造成重复方法 fatal

补缺失 hook 方法时，曾重复添加已有方法：

`maybe_mark_target_post_translated()`

导致 PHP 报错：

`Cannot redeclare WPMU_ML_Core_Sync_Trait::maybe_mark_target_post_translated()`

该重复方法后续已删除，并重新通过 `php -l` 与 `wp wpmu-ml doctor` 验证。

### 2.4 测试中断产生过临时残留

因为测试脚本调用了旧 trait 中不存在的公开方法 `get_post_relation()`，测试曾中断。

中断期间产生了临时关系/任务/文章残留，后来已清理，最终计数回到基线：

```json
{"relations":149617,"jobs":143869}
```

---

## 3. 当前已经做过的修复

> 注意：以下是当前线上代码状态说明，不代表建议继续由当前操作者处理。建议后续由接手人员复核 git/diff 后决定保留、重做或回滚。

### 3.1 恢复插件可加载状态

已验证：

```bash
php -l wpmu-multilingual/includes/core/traits/trait-wpmu-ml-core-sync.php
php -l wpmu-multilingual/includes/cli/class-wpmu-ml-cli.php
wp wpmu-ml doctor --allow-root --skip-themes
```

结果：通过。

### 3.2 保留/补回危险 rebuild 禁用

当前 `rebuild_relations()` 返回：

```text
生产关系重建已禁用。请先运行只读关系审计；0.9.8 不再清空关系表后按 ID 或 slug 猜测关联。
```

验证命令：

```bash
wp wpmu-ml rebuild --allow-root --skip-themes
```

结果：正确拒绝执行。

### 3.3 补回公开查询方法

当前文件中已补：

- `get_post_relation($source_blog_id, $source_post_id, $target_blog_id)`
- `find_relation_by_target($target_blog_id, $target_post_id)`

用途：测试、CLI、审计逻辑可能依赖这些方法。

### 3.4 补回目标文章生命周期 hook 方法

当前文件中已补：

- `maybe_mark_target_post_trashed($post_id)`
- `maybe_mark_target_post_deleted($post_id)`
- `maybe_mark_target_post_untrashed($post_id)`
- `maybe_mark_target_lifecycle_status($post_id, $event)`

原因：`class-wpmu-ml-core.php` 中注册了这些 hook，如果方法缺失，删除/回收站目标文章时会 fatal。

### 3.5 实现 slug 冲突 fallback 草稿策略

当前 `sync_one_target()` 中已有以下行为：

当目标站无明确关系，且源 slug 已被目标站已有文章占用时：

1. 不认领旧文章；
2. 不覆盖旧文章；
3. 使用 fallback slug：
   
   `sanitize_title($source_slug . '-' . (int)$source_post->ID)`

4. 新建目标文章；
5. 强制 `post_status = draft`；
6. 正常写入关系表；
7. 正常加入翻译任务；
8. 写入 meta：

```text
_wpmu_ml_slug_conflict_source_slug
_wpmu_ml_slug_conflict_fallback_slug
_wpmu_ml_slug_conflict_requires_review = 1
```

9. 记录 warning 日志：

`target_slug_conflict_fallback`

### 3.6 保护已有 fallback 草稿后续同步

如果目标文章已经带有：

`_wpmu_ml_slug_conflict_requires_review = 1`

且当前 slug 等于：

`_wpmu_ml_slug_conflict_fallback_slug`

后续同步会继续保留 fallback slug，不再尝试改回源 slug，避免再次撞上原占用文章。

---

## 4. 已完成的验证

### 4.1 语法与插件加载

命令：

```bash
php -l wpmu-multilingual/includes/core/traits/trait-wpmu-ml-core-sync.php
php -l wpmu-multilingual/includes/cli/class-wpmu-ml-cli.php
wp wpmu-ml doctor --allow-root --skip-themes
```

结果：通过。

`doctor` 关键输出：

```text
版本：0.9.8.4
is_multisite：yes
posts: yzk_wpmu_ml_post_relations | rows=149617
jobs: yzk_wpmu_ml_translation_jobs | rows=143869
```

### 4.2 危险 rebuild 禁用验证

命令：

```bash
wp wpmu-ml rebuild --allow-root --skip-themes
```

结果：

```text
Error: 生产关系重建已禁用。请先运行只读关系审计；0.9.8 不再清空关系表后按 ID 或 slug 猜测关联。
```

### 4.3 slug 冲突 fallback 草稿同步验证

测试脚本：

`/root/.openclaw/workspace/test_0984_slug_fallback_draft.php`

测试流程：

1. 在英文站创建一篇已有文章，占用测试 slug；
2. 在源站创建同 slug 文章；
3. 执行同步；
4. 验证英文站旧文章未被覆盖；
5. 验证英文站新建 fallback 文章；
6. 验证 fallback 文章为草稿；
7. 验证关系表、meta、任务队列；
8. 清理所有测试文章、关系和任务。

测试结果摘要：

```json
{
  "sync_result": {
    "targets": 13,
    "queued": 13
  },
  "conflict_unchanged": true,
  "en_relation_created": true,
  "fallback_status": "draft",
  "fallback_slug": "源slug-源文章ID",
  "job_status": "pending",
  "log_action": "target_slug_conflict_fallback",
  "residue_after_cleanup": {
    "source": 0,
    "conflict": 0,
    "fallback": 0,
    "relations": 0,
    "jobs": 0,
    "relations_final": 149617,
    "jobs_final": 143869
  }
}
```

---

## 5. 当前风险

### 5.1 最大风险：sync trait 是从旧备份恢复后局部补丁，不是完整可信的 0.9.8.4 原始版本

虽然当前语法和核心场景测试通过，但该文件经历过：

1. 截断；
2. 从旧备份恢复；
3. 局部手工补丁；
4. 再修复重复方法。

因此它可能仍缺少此前 0.9.8.4 中已经实现过的完整审计/保护逻辑。

建议接手人员不要只看当前测试通过，应与可信版本或完整变更记录做 diff。

### 5.2 需要重点复核的能力

请重点确认以下方法/能力是否完整：

- `validate_post_relation(...)`
- `validate_target_post_identity(...)`
- `audit_post_relations(...)`
- `find_target_posts_by_source_meta(...)`
- `validate_target_slug_availability(...)`
- Agent/OpenAI/OpenCC/Agent Tools 写回路径的 slug/identity 校验是否还完整
- 批量状态、slug 修复、翻译任务写回是否仍会误改目标文章
- 删除/回收站/恢复同步策略是否与 0.9.8.4 预期一致

### 5.3 当前测试覆盖不足

已测：

- 插件加载；
- rebuild 禁用；
- slug 冲突 fallback 草稿新建；
- 旧英文文章不被覆盖；
- 关系/任务清理回基线。

尚需由接手人员补测：

1. 已有关联更新路径；
2. 同 ID 但无关系时不得认领旧文；
3. 同 slug 但无关系时 fallback 草稿；
4. fallback 草稿二次同步保持 fallback slug；
5. 翻译任务写回不会把 fallback 草稿自动发布；
6. OpenAI/OpenCC/Agent Tools 写回路径遇 slug 冲突不会覆盖旧文；
7. 目标文章删除/回收站/恢复关系状态变化；
8. `audit-relations --summary` 只读审计；
9. `repair-slugs --dry-run` 输出无 warning；
10. 多 post_type，尤其 `solution_post` / `knowledge_post` / `docs_post` 的同步和翻译任务。

---

## 6. 建议接手处理方案

### 方案 A：从可信备份恢复完整 0.9.8.4，再重新打 slug fallback 补丁（推荐）

1. 找到截断前的可信 `trait-wpmu-ml-core-sync.php`。
2. 与当前文件 diff。
3. 先恢复完整 0.9.8.4 安全逻辑。
4. 再最小化加入 slug fallback 草稿策略。
5. 跑完整回归。

优点：风险最低，避免旧备份造成安全能力缺失。

### 方案 B：以当前文件为基础继续补齐缺失能力

1. 列出当前文件与 0.9.8.4 设计/文档要求的差异。
2. 补齐 identity validation、relation audit、write-back protection 等能力。
3. 跑更大范围测试。

缺点：容易漏补，风险高于方案 A。

---

## 7. 建议保留的新业务规则

老板已明确的新规则建议写入插件规范：

1. 已有关联：更新目标文章。
2. 无关系但目标站存在同 ID 文章：不得自动认领。
3. 无关系但目标站存在同 slug 文章：不得覆盖旧文章。
4. slug 冲突时：
   - 新建目标文章；
   - slug = `源 slug-源文章 ID`；
   - 强制 draft；
   - 写入待复核 meta；
   - 正常加入翻译任务。
5. 未来如果自动翻译流程能生成目标语言下不重复、可接受的 slug，可考虑不强制 draft；在此之前保持 draft 更安全。

---

## 8. 当前文件状态快照

关键文件：

```text
wpmu-multilingual/includes/core/traits/trait-wpmu-ml-core-sync.php
wpmu-multilingual/includes/cli/class-wpmu-ml-cli.php
```

最终检查输出：

```text
No syntax errors detected in wpmu-multilingual/includes/core/traits/trait-wpmu-ml-core-sync.php
No syntax errors detected in wpmu-multilingual/includes/cli/class-wpmu-ml-cli.php
WPMU多语言诊断
版本：0.9.8.4
posts rows=149617
jobs rows=143869
```

最终表计数：

```json
{"relations":149617,"jobs":143869}
```

---

## 9. 备注

本报告用于交接处理。后续建议不要继续在线上直接手工改核心 trait 文件，应先建立可回滚备份或版本控制 diff，再由接手人员按上述方案复核处理。
