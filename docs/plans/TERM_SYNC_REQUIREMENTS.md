# 分类 / 标签 / Taxonomy Term 同步开发说明

目标：给 `wpmu-multilingual` 补齐 taxonomy term 本体的增删改同步能力。当前插件主要完整支持文章 / 自定义文章类型同步与翻译；分类、标签、自定义 taxonomy term 目前只作为文章关系被同步，不支持在分类/标签管理页单独增删改时自动同步。

## 一句话需求

让分类、标签、自定义 taxonomy term 的新增、修改、删除，按文章同步的规则同步到其它语言站。

## 范围

需要支持所有后台“同步内容类型”里已选中的 taxonomy，例如：

- `category`
- `post_tag`
- `solution_category`
- `guide_category`
- `knowledge_category`
- `docs_category`
- `provider_category`
- `reviews_category`
- `tools_category`
- `activity_category`
- `doing_category`
- `tolink_category`
- `wp_pattern_category`

实际执行时以插件当前设置里的 `sync_taxonomies` 白名单为准，不要硬编码只支持 `category/post_tag`。

## 必须遵守的同步规则

### 1. 只从源站向启用的目标语言站同步

- 源站由插件设置 `source_blog_id` 决定。
- 只有当前操作发生在源站时才触发同步。
- 目标站只同步到 `wpmu_ml_sites.enabled = 1` 的站点。
- 不要从分站反向同步到源站，也不要分站之间互相同步。

### 2. 新增 term

当源站新增 taxonomy term 时：

- 如果 taxonomy 在 `sync_taxonomies` 白名单中，则在每个启用目标站创建对应 term。
- 写入 `wpmu_ml_term_relations` 关系表。
- 关系必须支持源站 term ID 和目标站 term ID 不一致。
- 不要依赖“同 ID”。
- 推荐先按关系表查找；没有关系时再按 slug + taxonomy 检查目标站是否已有 term，避免重复创建。

目标站创建字段至少包括：

- `name`
- `slug`
- `description`
- `parent`，仅 hierarchical taxonomy 需要处理

注意：当前阶段只要求像文章一样同步增删改，不要求马上做 AI 翻译。可以先复制源站 name/description，后续再接 term 翻译队列。

### 3. 修改 term

当源站修改 taxonomy term 时：

- 通过 `wpmu_ml_term_relations` 找到各目标站对应 term。
- 更新目标站对应 term 的：
  - `name`
  - `slug`
  - `description`
  - `parent`
- 如果某个目标站关系存在但目标 term 已不存在，应按新增逻辑重建目标 term，并修复关系表。
- 如果关系不存在，但目标站有同 slug + taxonomy 的 term，可以建立关系并更新。
- 不要误更新非对应 taxonomy 的 term。

### 4. 删除 term

当源站删除 taxonomy term 时：

- 按文章删除规则，从关系表找到目标站对应 term。
- 删除目标站对应 term。
- 删除或标记清理 `wpmu_ml_term_relations` 里的对应关系。
- 只删除由关系表确认的目标 term，不要按 slug 模糊删除，避免误删。
- 分站单独删除 term 不应反向影响源站。

### 5. Parent 层级规则

对于 hierarchical taxonomy，例如 `category` 或各种 `*_category`：

- 父级 term 必须先同步或先确保关系存在。
- 目标站 parent 应设置为目标站对应父级 term ID，而不是源站 parent ID。
- 如果父级关系不存在，应先同步父级，再同步子级。

对于非 hierarchical taxonomy，例如 `post_tag`：

- 不需要处理 parent。

### 6. 关系表规则

使用现有关系表：

```sql
yzk_wpmu_ml_term_relations
```

核心字段：

```text
source_blog_id
source_term_id
source_taxonomy
target_blog_id
target_term_id
target_taxonomy
source_lang
target_lang
relation_status
```

唯一逻辑应保持：

```text
source_blog_id + source_term_id + source_taxonomy + target_blog_id
```

这表示同一个源站 term 在某个目标站只有一个对应 term。

### 7. Hook 建议

需要监听 WordPress taxonomy term 事件：

```php
created_term
edited_term
delete_term
```

或更具体的相关 hook。实现时注意：

- 防止递归触发，必须有 running guard。
- 只在 `get_current_blog_id() === source_blog_id` 时触发。
- 只处理 `sync_taxonomies` 里的 taxonomy。
- 跳过插件排除的 taxonomy。

### 8. 与文章同步保持一致的安全原则

- 不做全表重建。
- 不按 ID 猜测跨站关系。
- 不清空关系表。
- 不用 slug 模糊批量删除。
- 每次只处理当前被新增/修改/删除的源站 term。
- 操作前后写日志，便于审计。

建议新增日志 action，例如：

- `term_sync_created`
- `term_sync_updated`
- `term_sync_deleted`
- `term_sync_relation_repaired`
- `term_sync_skipped`
- `term_sync_error`

## 当前实测结论

2026-07-24 已测试：

- 源站新增 `post_tag`：英文站 / 日语站不会自动创建。
- 源站修改 `post_tag`：英文站 / 日语站不会自动更新。
- 源站删除 `post_tag`：不会同步删除。
- 分站删除 term：不会影响源站，关系表可能残留旧 target ID。

所以当前需要补的是 taxonomy term 本体 CRUD 同步，不是文章保存时的 term relationship 同步。

## 验收测试

开发完成后至少跑以下测试：

### 测试准备

- 只开启源站、英文站、日语站也可以。
- 使用零引用测试 term，避免影响真实内容。
- 测试 taxonomy 至少包含：
  - `post_tag`
  - `category`
  - 一个自定义 taxonomy，例如 `solution_category` 或 `guide_category`

### 新增测试

在源站新增测试标签：

- 英文站应创建对应 term。
- 日语站应创建对应 term。
- `wpmu_ml_term_relations` 应新增对应关系。
- 源站 ID 和目标站 ID 不一致时仍能正确关联。

### 修改测试

在源站修改测试 term 的：

- name
- slug
- description
- parent，hierarchical taxonomy 才测

目标站对应 term 应同步更新。

### 删除测试

在源站删除测试 term：

- 英文站对应 term 应删除。
- 日语站对应 term 应删除。
- 对应 `wpmu_ml_term_relations` 应清理或标记为删除。

### 分站删除后修复测试

手动删除日语站对应 term 后，再修改源站 term：

- 插件应检测目标 term 缺失。
- 应重新创建日语站 term。
- 应修复 `wpmu_ml_term_relations.target_term_id`。

## 暂不要求

本阶段暂不要求：

- term name/description AI 翻译。
- SEO term meta 翻译。
- 历史全量 term 批量补翻译。
- 分站反向同步源站。

但实现时请预留扩展点，后续可以把 term name/description 接入现有翻译队列。