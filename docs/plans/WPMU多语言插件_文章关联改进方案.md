# WPMU 多语言插件文章关联改进方案

> 实施基线：`0.9.8` 起。
>
> 当前状态：方案已确认，关系运行逻辑尚未完成安全改造。实际进度、验证结果和回滚信息统一记录在 [ARTICLE_RELATION_IMPROVEMENT_LOG.md](ARTICLE_RELATION_IMPROVEMENT_LOG.md)。

## 1. 文档目的

本方案用于改进当前 WPMU 多语言插件的文章关联机制，重点解决以下问题：

- 现有各语言站已同步约 1 万多篇文章，历史文章的 `post_id` 基本相同；
- 历史数据已经稳定运行，不应重新编号、不应重新创建、不应批量重建关系；
- 源站文章的修改、回收站、恢复及删除仍需按现有关联同步到目标语言站；
- 后续新发布文章不再要求各语言站 `post_id` 必须相同；
- 新文章首次同步时，不能把目标站中“相同 ID”或“相同 slug”的无关文章误认为翻译版本；
- 前台语言切换、`hreflang`、翻译任务及删改同步必须基于明确的跨站关系。

本方案的核心原则是：

> 历史文章保持不动，现有关联继续有效；只改进新文章首次同步、关系校验和异常保护逻辑。

---

## 2. 当前数据模型

当前插件已经使用关系表记录跨站文章关系，核心字段为：

```text
source_blog_id
source_post_id
target_blog_id
target_post_id
```

因此，系统实际上已经支持不同站点使用不同文章 ID，例如：

```text
中文源站：blog_id = 1，post_id = 10001
英文站：  blog_id = 2，post_id = 10038
越南语站：blog_id = 3，post_id = 10017
```

关系表可以记录：

```text
1 + 10001 → 2 + 10038
1 + 10001 → 3 + 10017
```

现有历史数据则大多是：

```text
1 + 123 → 2 + 123
1 + 123 → 3 + 123
```

这类历史关系可以继续保留。ID 相同不是问题，问题在于系统不能长期把“ID 相同”当成唯一身份判断依据。

---

## 3. 当前代码风险

### 3.1 新文章首次同步存在同 ID 误认风险

当前 `sync_one_target()` 在关系表找不到目标文章时，采用以下顺序查找目标文章：

```text
1. 关系表中的 target_post_id
2. 目标站相同 ID
3. 来源 meta
4. 相同 slug + post_type
```

其中“目标站相同 ID”只校验了 `post_type`，可能出现以下情况：

```text
源站 post_id = 10001，类型为 post
目标站 post_id = 10001，类型也为 post，但内容属于另一篇文章
```

插件可能把目标站无关文章当成对应翻译文章并执行更新，造成：

- 覆盖无关文章标题、正文、摘要或 slug；
- 写入错误的来源 meta；
- 创建错误关系；
- 前台语言切换跳转错误；
- 输出错误的 `hreflang`；
- 后续删除同步误删无关文章。

### 3.2 相同 slug 自动匹配同样不可靠

不同语言站可能存在相同 slug，但不一定属于同一翻译组。slug 只能作为人工排查线索，不能作为生产环境中的自动认领依据。

### 3.3 目标文章身份校验不足

关系表读取到 `target_post_id` 后，当前逻辑主要确认目标文章是否存在，但没有在每次更新、删除、语言切换前严格验证：

```text
_wpmu_ml_source_blog_id
_wpmu_ml_source_post_id
```

因此，如果关系表异常、目标站被单独导入数据或目标 ID 被错误复用，可能继续操作错误文章。

### 3.4 “重建关联”不适合直接用于当前生产数据

当前重建逻辑会清空关系表，再依次通过来源 meta、slug 和相同 ID 猜测关系。对于已有 1 万多篇历史数据的生产站点，不应在未审计的情况下执行。

---

## 4. 改进范围

### 4.1 本次必须改进

1. 新文章首次同步的目标文章查找与创建逻辑；
2. 关系表和目标文章来源 meta 的双重校验；
3. 新文章创建后记录实际目标 ID；
4. 更新、删除、恢复、翻译任务和前台语言切换的安全校验；
5. 异常关系的日志、阻断和后台提示；
6. 数据库唯一性保护；
7. 灰度发布、回滚和验收机制。

### 4.2 本次明确不做

1. 不修改现有文章 ID；
2. 不重新同步全部 1 万多篇文章；
3. 不批量删除、重建或重新生成历史目标文章；
4. 不把现有相同 ID关系强制改为不同 ID；
5. 不默认执行“重建关联”；
6. 不改变现有文章 URL、slug、发布时间和发布状态；
7. 不在上线时批量覆盖历史目标站正文。

---

## 5. 目标设计

## 5.1 历史关系与新关系分开处理

### 历史文章

现有关系表已经存在的记录视为历史确定关系：

```text
source_blog_id + source_post_id + target_blog_id
→ target_post_id
```

无论源站和目标站 ID 是否相同，均继续使用，不重新创建目标文章。

历史关系的处理规则：

- 不改 ID；
- 不改 URL；
- 不自动重建；
- 源站修改时继续更新原目标文章；
- 源站删除、回收站和恢复时继续按原关系处理；
- 发现异常时停止自动操作并记录日志，不自动寻找同 ID 替代品。

### 新文章

新文章首次同步时，不要求目标 ID 与源 ID 相同。系统应以 WordPress 实际创建结果为准：

```text
源站 ID 10001
→ 英文站 wp_insert_post() 返回 10038
→ 越南语站 wp_insert_post() 返回 10017
```

随后把实际目标 ID 写入关系表及目标文章来源 meta。

---

## 5.2 关系表作为主索引

文章关联的主键逻辑应为：

```text
source_blog_id
+ source_post_id
+ target_blog_id
```

对应唯一目标：

```text
target_post_id
```

目标文章的完整身份是：

```text
target_blog_id + target_post_id
```

不能只用 `post_id` 判断跨站文章身份。

---

## 5.3 目标文章 meta 作为身份校验

每个目标文章应保存：

```text
_wpmu_ml_source_blog_id
_wpmu_ml_source_post_id
_wpmu_ml_source_lang
_wpmu_ml_target_lang
```

推荐再增加：

```text
_wpmu_ml_relation_version = 2
```

可选增加永久翻译组标识：

```text
_wpmu_ml_translation_uuid
```

其中：

- 关系表负责快速查找；
- 来源 meta 负责确认目标文章身份；
- UUID 可用于未来数据库迁移、导入和灾难恢复，但不是本次上线的强制条件。

---

## 6. 新文章首次同步算法

## 6.1 新的查找顺序

新文章同步到某个目标站时，必须按以下顺序执行：

```text
第一步：查询关系表
第二步：校验关系表指向的目标文章
第三步：按来源 meta 查找目标文章
第四步：仍未找到则创建新目标文章
第五步：写入来源 meta
第六步：写入或更新关系表
第七步：创建翻译任务
```

必须删除生产运行中的以下自动兜底：

```text
相同 ID 自动认领
相同 slug 自动认领
```

### 明确禁止

```php
$same_id_post = get_post($source_post->ID);
```

不能再仅因目标站存在相同 ID 且 `post_type` 相同，就认定它是对应翻译文章。

`find_target_post_by_slug()` 不能再用于生产环境自动认领目标文章。

---

## 6.2 推荐伪代码

```php
private function resolve_or_create_target_post(
    int $source_blog_id,
    WP_Post $source_post,
    int $target_blog_id,
    array $postarr
): array {
    global $wpdb;

    // 1. 查询已有关系
    $relation = $this->get_post_relation(
        $source_blog_id,
        (int) $source_post->ID,
        $target_blog_id
    );

    switch_to_blog($target_blog_id);

    // 2. 有关系时严格校验目标文章
    if ($relation) {
        $target_post_id = (int) $relation['target_post_id'];
        $target_post = get_post($target_post_id);

        if ($target_post && $this->target_matches_source(
            $target_post_id,
            $source_blog_id,
            (int) $source_post->ID,
            (string) $source_post->post_type,
            true // 允许旧关系缺少 meta 时走兼容策略
        )) {
            restore_current_blog();
            return [
                'action' => 'update',
                'target_post_id' => $target_post_id,
                'relation' => $relation,
            ];
        }

        restore_current_blog();
        return [
            'action' => 'blocked',
            'error' => 'relation_target_invalid',
            'relation' => $relation,
        ];
    }

    // 3. 没有关系时，只允许来源 meta 精确查找
    $target_post_id = $this->find_target_post_by_source_meta(
        $source_blog_id,
        (int) $source_post->ID,
        (string) $source_post->post_type
    );

    if ($target_post_id) {
        restore_current_blog();
        return [
            'action' => 'adopt_by_source_meta',
            'target_post_id' => $target_post_id,
        ];
    }

    // 4. 未找到则创建，不再猜测相同 ID 或 slug
    $inserted = wp_insert_post(wp_slash($postarr), true);

    if (is_wp_error($inserted)) {
        restore_current_blog();
        return [
            'action' => 'failed',
            'error' => $inserted->get_error_message(),
        ];
    }

    $target_post_id = (int) $inserted;
    restore_current_blog();

    return [
        'action' => 'created',
        'target_post_id' => $target_post_id,
    ];
}
```

---

## 7. 历史文章兼容策略

现有约 1 万多篇文章大多是从空站按同一顺序创建，因此各语言站 ID 相同。上线新版后，这些文章必须继续正常运行。

## 7.1 已有关系表记录时

已有关系表记录即视为确定关系。例如：

```text
1 + 123 → 2 + 123
```

处理方式：

- 继续更新目标站 123；
- 不重新创建；
- 不因 ID 相同而改变任何内容；
- 不重新推算关系；
- 不自动改写 relation；
- 可在首次安全更新后补写来源 meta。

## 7.2 兼容旧关系缺少来源 meta

如果关系表记录存在，但目标文章没有来源 meta，不应立即判错，也不应直接批量迁移。

建议兼容规则：

```text
关系表存在
+ 目标文章存在
+ target_blog_id、target_post_id 与关系表一致
+ post_type 一致
= 允许作为旧版历史关系继续使用
```

在一次正常同步成功后补写：

```text
_wpmu_ml_source_blog_id
_wpmu_ml_source_post_id
_wpmu_ml_relation_version = 2
```

注意：

- 该兼容规则只适用于“关系表已存在”的历史记录；
- 不适用于没有关系表的新文章；
- 不允许用相同 ID 或 slug临时推测一条新关系。

## 7.3 不批量重建

禁止直接调用会执行以下操作的功能：

```sql
TRUNCATE TABLE 文章关系表;
TRUNCATE TABLE 分类关系表;
```

建议在后台将“重建关联”按钮改为：

- 默认隐藏；或
- 仅超级管理员可见；
- 显示高风险警告；
- 必须先执行 dry-run；
- 必须输入确认短语；
- 生产环境默认禁用。

---

## 8. 源站修改同步

源站已有文章发生修改时，应继续沿用关系表中的实际目标 ID：

```text
源站 10001 修改
→ 查关系表
→ 英文站更新 10038
→ 越南语站更新 10017
```

修改前必须校验：

1. 目标文章存在；
2. 目标文章 `post_type` 与关系表一致；
3. 来源 meta 与源文章一致；
4. 对历史关系缺少 meta 的，按第 7.2 节兼容并补写 meta；
5. 校验失败时停止该目标站更新，不得创建新文章覆盖或替代；
6. 其他语言站可继续独立执行，但必须记录部分失败状态。

推荐关系状态：

```text
synced
needs_translation
needs_update
relation_invalid
target_missing
target_identity_conflict
sync_failed
```

---

## 9. 删除、回收站和恢复同步

## 9.1 源站删除或回收站

仍按现有业务策略处理，但必须通过关系表定位目标文章：

```text
源站删除 10001
→ 英文站处理 10038
→ 越南语站处理 10017
```

删除前必须验证目标身份，防止错误关系导致误删无关文章。

推荐流程：

```text
查询关系
→ 切换到目标站
→ 读取目标文章
→ 校验 post_type 和来源 meta
→ 校验通过后再 trash/delete
→ 更新 relation_status
```

如果校验失败：

```text
不得删除
relation_status = target_identity_conflict
记录错误日志
后台显示人工处理提示
```

## 9.2 源站恢复

源站从回收站恢复时：

- 只恢复此前由源站同步移入回收站的目标文章；
- 继续使用 `_wpmu_ml_trashed_by_source` 判断；
- 同时校验来源 meta；
- 不恢复目标站用户手工删除或手工放入回收站的无关文章。

## 9.3 目标站直接删除

推荐目标语言站只作为同步目标，不允许普通编辑者直接永久删除关联文章。

若仍允许目标站直接删除，应增加钩子：

```text
before_delete_post
trashed_post
untrashed_post
```

处理规则：

- 查询该目标文章对应的关系；
- 将关系标记为 `target_deleted` 或 `target_trashed`；
- 不自动把其他文章填入旧关系；
- 前台语言切换不再输出该目标页面；
- 后续源站更新时由配置决定“重新创建”或“等待人工确认”。

建议默认行为：

```text
目标文章永久删除后，源站下次同步重新创建一篇新的目标文章，
并把关系表中的 target_post_id 更新为新 ID。
```

但重新创建前必须确认目标文章确实已不存在，而不是身份冲突。

---

## 10. 前台语言切换与 hreflang

前台语言切换和 `hreflang` 必须读取同一套关系表。

## 10.1 语言切换

读取目标页面前应检查：

```text
关系存在
目标文章存在
目标 post_type 正确
目标文章来源正确
目标状态符合显示规则
```

校验失败时：

- 不输出错误目标 URL；
- 不自动使用同 ID 页面；
- 不自动使用同 slug 页面；
- 可隐藏该语言，或回退到目标语言分类/首页；
- 后台记录 `relation_invalid`。

## 10.2 hreflang

`hreflang` 仅输出符合以下条件的页面：

```text
关系有效
目标页面存在
目标页面为 publish
目标页面允许索引
页面确属同一内容的语言版本
```

`noindex`、404、搜索结果、登录、用户中心、结算等页面不应作为文章级 `hreflang` 输出目标。

---

## 11. 数据库改进

## 11.1 保留现有唯一键

当前唯一键：

```sql
UNIQUE KEY source_target (
    source_blog_id,
    source_post_id,
    target_blog_id
)
```

该约束应保留，确保一个源文章在一个目标站只有一条关系。

## 11.2 增加目标文章唯一约束

建议增加：

```sql
UNIQUE KEY target_unique (
    target_blog_id,
    target_post_id
)
```

防止以下错误：

```text
源文章 A → 英文文章 500
源文章 B → 英文文章 500
```

增加唯一索引前，必须先执行重复检查：

```sql
SELECT
    target_blog_id,
    target_post_id,
    COUNT(*) AS relation_count
FROM wp_wpmu_ml_posts
GROUP BY target_blog_id, target_post_id
HAVING COUNT(*) > 1;
```

若存在重复，先导出报告并人工处理，不能直接加索引。

## 11.3 可选新增字段

建议关系表增加：

```sql
relation_version TINYINT UNSIGNED NOT NULL DEFAULT 1,
identity_checked_at DATETIME DEFAULT NULL,
last_error_code VARCHAR(80) DEFAULT NULL,
last_error_message TEXT DEFAULT NULL
```

含义：

- `relation_version = 1`：历史关系；
- `relation_version = 2`：新版严格关系；
- `identity_checked_at`：最后一次身份校验时间；
- `last_error_code`：异常代码；
- `last_error_message`：异常说明。

该字段改进不是第一阶段强制项，但有利于审计和维护。

---

## 12. 必须新增的核心函数

建议拆出以下公共函数，避免同步、删除、切换器和翻译任务分别使用不同判断标准。

### 12.1 获取关系

```php
get_post_relation(
    int $source_blog_id,
    int $source_post_id,
    int $target_blog_id
): ?array
```

### 12.2 校验目标文章身份

```php
target_matches_source(
    int $target_post_id,
    int $source_blog_id,
    int $source_post_id,
    string $post_type,
    bool $allow_legacy_relation = false
): bool
```

推荐判断：

```text
目标文章存在
+ post_type 一致
+ 来源 meta 完全一致
= 严格通过
```

历史兼容：

```text
来源 meta 缺失
+ 已有明确关系表记录
+ post_type 一致
= 旧关系兼容通过，并补写 meta
```

以下情况必须失败：

```text
来源 meta 指向另一个源文章
post_type 不一致
目标文章不存在
目标文章已被另一条关系占用
```

### 12.3 标记关系异常

```php
mark_relation_invalid(
    int $relation_id,
    string $error_code,
    string $message,
    array $context = []
): void
```

### 12.4 目标文章唯一性检查

```php
find_relation_by_target(
    int $target_blog_id,
    int $target_post_id
): ?array
```

用于防止一个目标文章关联多个源文章。

---

## 13. 对现有代码的具体调整建议

## 13.1 `sync_one_target()`

文件：

```text
includes/core/traits/trait-wpmu-ml-core-sync.php
```

删除或禁用以下生产兜底：

```text
get_post($source_post->ID) 的相同 ID查找
find_target_post_by_slug() 的自动认领
```

保留：

```text
关系表精确查找
来源 meta 精确查找
wp_insert_post() 创建新目标文章
```

更新已有目标文章前增加身份校验。

## 13.2 `get_alternate_urls()`

文件：

```text
includes/core/traits/trait-wpmu-ml-core-language-switcher.php
```

当前根据关系表取得 `target_post_id` 后，应进一步校验：

- 文章存在；
- `post_type` 正确；
- 来源 meta 一致；
- 发布状态正确；
- 页面允许前台访问。

校验不通过时，不输出该 alternate URL。

## 13.3 删除与恢复逻辑

文件：

```text
includes/core/traits/trait-wpmu-ml-core-sync.php
```

在以下操作前调用统一身份校验：

```text
wp_delete_post()
wp_trash_post()
wp_untrash_post()
wp_update_post()
```

避免错误关系导致误删或误改。

## 13.4 `rebuild_relations()`

生产环境默认禁用相同 ID 和 slug自动匹配。

建议拆成两种模式：

```text
strict：仅按来源 meta 重建
legacy-dry-run：生成同 ID/slug候选报告，但不写数据库
```

不再提供“清空后自动猜测并直接写入”的默认模式。

---

## 14. 日志与后台提示

建议增加以下日志动作：

```text
relation_identity_ok
relation_legacy_meta_stamped
relation_target_missing
relation_target_conflict
relation_target_claimed
relation_create_success
relation_create_failed
relation_delete_blocked
relation_update_blocked
```

每条日志至少记录：

```text
source_blog_id
source_post_id
target_blog_id
target_post_id
post_type
action
error_code
```

后台文章列表或翻译任务页建议显示：

- 已关联；
- 等待翻译；
- 目标缺失；
- 关系冲突；
- 同步失败；
- 需要人工处理。

任何身份冲突都应“阻断操作”，而不是静默修复。

---

## 15. 上线前审计

上线前只做只读审计，不改正文和文章 ID。

## 15.1 统计关系数量

检查：

```text
源站受管文章总数
× 启用目标语言站数量
≈ 应有关系数
```

允许草稿、排除文章类型等业务规则造成差异，但必须解释差异来源。

## 15.2 检查目标缺失

逐条验证：

```text
target_blog_id + target_post_id 是否存在
```

## 15.3 检查 post_type

关系表中的 `post_type` 必须与源文章和目标文章一致。

## 15.4 检查重复目标

检查一个目标文章是否被多条关系引用。

## 15.5 检查来源 meta

分类统计：

```text
来源 meta 正确
来源 meta 缺失
来源 meta 冲突
```

“缺失”可以后续在正常同步时补写；“冲突”必须人工处理。

## 15.6 检查前台跳转

随机抽查至少：

- 100 篇历史文章；
- 每种文章类型不少于 20 篇；
- 每个目标语言站不少于 20 篇；
- 已发布、草稿、回收站各状态；
- 中文进入英文、英文返回中文、越南语返回中文。

---

## 16. 发布步骤

### 第一阶段：代码保护，不改变数据

1. 完整备份数据库；
2. 备份插件目录；
3. 禁止执行重建关联；
4. 增加只读审计命令；
5. 增加目标身份校验函数；
6. 保留历史关系兼容；
7. 删除新文章的同 ID/slug自动认领；
8. 在测试环境验证。

### 第二阶段：灰度测试新文章

仅选择一个目标语言站进行测试：

1. 在目标站提前创建几个占用 ID 的测试对象；
2. 在源站发布新文章；
3. 确认插件创建新的实际目标 ID；
4. 确认关系表记录实际 ID；
5. 确认未覆盖目标站已有同 ID 文章；
6. 测试更新、翻译、回收站、恢复和永久删除；
7. 测试前台切换和 `hreflang`。

### 第三阶段：全语言站启用

灰度通过后，所有目标站启用新版逻辑。

历史文章不批量写入，不批量更新。来源 meta 可在历史文章下一次正常同步时逐步补齐。

---

## 17. 回滚方案

上线前必须保留：

- 数据库完整备份；
- 旧插件压缩包；
- 新版上线时间点；
- 新版创建的关系记录和目标文章日志。

发生问题时：

1. 立即停用翻译队列和自动同步；
2. 恢复旧版插件；
3. 不直接执行关系重建；
4. 根据日志定位新版上线后创建的文章；
5. 仅回滚新增或被错误修改的数据；
6. 必要时恢复数据库备份。

不建议仅回滚插件文件而保留未知状态的错误关系数据。

---

## 18. 验收标准

新版必须满足以下条件：

### 历史数据

- 现有 1 万多篇文章 ID 不发生变化；
- 现有文章 URL 不发生变化；
- 现有语言切换正常；
- 历史文章修改可以同步；
- 历史文章删除、回收站、恢复可以按策略同步；
- 不执行批量重建或批量覆盖。

### 新文章

- 目标站 ID 被占用时，能创建不同 ID 的目标文章；
- 关系表记录实际目标 ID；
- 不覆盖相同 ID 的无关文章；
- 不自动认领相同 slug 的无关文章；
- 新文章更新始终命中正确目标文章；
- 删除同步不会删除无关文章；
- 前台语言切换进入正确页面；
- `hreflang` 指向正确且可索引的语言版本。

### 异常保护

- 目标缺失时不误更新其他文章；
- 来源 meta 冲突时阻断更新和删除；
- 一个目标文章不能关联两个源文章；
- 所有异常均有可追踪日志；
- 管理员能够看到需要人工处理的关系。

---

## 19. 最终实施原则

本次改进不应推翻现有同 ID 架构，而应将它升级为兼容不同 ID 的稳定关系架构：

```text
历史文章：
已有关系继续使用，不改 ID，不重建，不重新同步。

新文章：
创建时接受目标站返回的实际 ID，并写入明确关系。

修改和删除：
始终根据关系表定位目标文章，操作前校验身份。

前台切换和 hreflang：
始终读取经过校验的关系，不使用同 ID 或同 slug猜测。
```

最终目标是：

> 现有 1 万多篇历史文章保持稳定；以后即使各语言站 ID 序列不同，文章同步、翻译、删除、语言切换和 SEO 仍能准确运行。
