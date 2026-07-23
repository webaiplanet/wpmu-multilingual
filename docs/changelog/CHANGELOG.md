## 0.9.8.16 - 2026-07-24

- 修复 Agent API result 写回长正文时，WordPress 将正文中的 `&` 规范化为 `&amp;`、将 `<img .../>` 规范化为 `<img ... />` 后导致回读完整性校验误报 `post_content` 不一致的问题。
- Agent API 写回发布状态时增加内部运行标记，避免 `transition_post_status` 钩子在 result 完整校验和任务收尾前提前把任务标记完成。
- 本修复不放宽 Agent API 的结构保护：Gutenberg 区块注释、HTML 标签、URL、短代码和占位符仍必须保持一致。

## 0.9.8.15 - 2026-07-24

- 调整为插件通用关联策略，不再按某个网站现有对象判断应不应该关联。
- 将 WordPress 站点编辑器对象 `wp_template`、`wp_template_part`、`wp_navigation` 纳入可翻译文章类型默认范围，使用现有 `wpmu_ml_post_relations` 建立跨语言关系。
- 后台“参与翻译 / 共享发布”候选列表不再硬排除 `wp_template`、`wp_template_part`、`wp_navigation`。
- 版本升级时会把这 3 个 FSE post-like 类型补进 `translatable_post_types`，因为旧版本后台无法选择它们。
- 继续硬排除媒体 `attachment`、经典菜单项 `nav_menu_item`、ACF 配置类、全局样式 `wp_global_styles` 和用户相关对象，避免把资源文件、菜单结构配置、字段配置或用户数据当翻译内容处理。

## 0.9.8.14 - 2026-07-24

- 将菜单调用相关的后台显示名称从 `Language Switcher` 改为 `WPMU 语言切换器`。
- WordPress “外观 → 菜单”中的菜单项面板名称同步改为 `WPMU 语言切换器`，减少与其他多语言插件的同名冲突。
- 插件设置页中的菜单调用说明同步改名。
- 内部 post type 标识 `wpmu_ml_switcher` 保持不变，避免影响已有菜单项和历史数据。

## 0.9.8.13 - 2026-07-24

- 修复“语言切换 → 代码调用”示例代码显示为一行的问题。
- 示例代码改为真正多行 `<pre><code>` 输出，不再显示转义的 `\n`。
- 补充后台代码块样式：等宽字体、行高、边框、内边距和横向滚动。

## 0.9.8.12 - 2026-07-24

- OpenAI 兼容页的二级选项卡样式改为与“语言切换”二级选项卡一致，使用顶部标签式 UI 和连贯内容面板。
- 清理后台界面中遗留的开发阶段文案，例如“自己的二级选项卡”“从翻译设置独立出来”“后续继续”等说明。
- 将翻译引擎、OpenAI、人工翻译、帮助页和语言切换页的说明改为面向插件管理员的正式配置说明。
- 模型输入示例改为通用 OpenAI 兼容模型示例，避免使用开发测试阶段模型名。
- 移除工具页中已停用的同 ID 猜测补建按钮；直接请求该旧动作会返回“工具已停用”。

## 0.9.8.11 - 2026-07-24

- 收紧 OpenCC 路由：只有源站识别为简体中文、目标语言为繁体中文时，才自动提供或 fallback 到 OpenCC。
- 非简体中文源站即使目标语言是 `zh-hant` / `zh-tw` / `zh-hk`，也不会自动走 OpenCC，避免公开插件被英文、日文等源语言站误用简繁转换。
- 后台“翻译引擎”说明同步更新，明确 OpenCC 只用于“简体中文源站 → 繁体中文目标站”。
- 环境检测中 OpenCC 的“按需必需”判断同步收紧，非简体中文源站不会因为繁体目标站误报 OpenCC 必需。

## 0.9.8.10 - 2026-07-24

- 清理后台重复的“隐藏未发布语言”配置入口：内容类型页不再显示旧 checkbox，统一在“语言切换 → 基础设置”中处理。
- 将语言切换页字段改名为“未发布语言处理”，明确只控制语言切换器的“隐藏语言 / 显示提示”行为。
- hreflang 输出规则固定为只输出已发布且可索引的目标文章，不再依赖旧 `hide_unpublished` UI。
- 修正 taxonomy term name 翻译写入：开启 term name 翻译后，目标 term 新增/更新时不再被源站 name 覆盖。

## 0.9.8.9 - 2026-07-24

- 新增 taxonomy term 本体翻译开关，可分别控制是否翻译 term `name` 和 `description`。
- 开关默认关闭，关闭时继续只复制源站 term 文本，不影响 0.9.8.8 的 CRUD 同步行为。
- 开启后，term 新增/编辑同步到目标站时，OpenAI 兼容引擎翻译勾选字段，繁体中文目标可走 OpenCC 转换。
- `slug` 固定保持源站同步，不自动翻译，避免 URL 不稳定。
- manual 或不支持的引擎会保留源站文本并记录 `term_translation_skipped`；翻译失败记录 `term_translation_error`，但不阻断 term 同步。

## 0.9.8.8 - 2026-07-24

- 新增 taxonomy term 本体 CRUD 同步，源站新增、编辑、删除 `sync_taxonomies` 白名单内的分类、标签和自定义 taxonomy term 时同步到启用目标站。
- 新建/编辑 term 时优先使用 `wpmu_ml_term_relations`；关系缺失时只针对当前 term 尝试同 slug + taxonomy、同 ID + taxonomy 认领并修复关系，不做历史全量重建。
- hierarchical taxonomy 先同步父级，目标 parent 写目标站父级 term ID；非 hierarchical taxonomy 不处理 parent。
- 源站删除 term 时只删除关系表确认的目标 term，并清理对应关系；分站删除不会反向影响源站。
- 文章同步分类关系时新增源 term ID 到目标 term ID 的映射，避免目标站 ID 不一致时挂错 term。
- 新增 `tests/term-sync-smoke.php`，覆盖新增、编辑、删除、目标缺失修复、父子层级和文章分类关系映射。

## 0.9.8.7 - 2026-07-21

- 新增 PHP 字段快照和哈希差异检测，不再只凭 `needs_update` / `review_required` 推测目标是否翻译过。
- 源站保存时按标题、摘要、正文和 Meta Key 生成变化清单；未变化字段不重复同步或翻译。
- 从未翻译的目标只同步源站发生变化的字段，再进入首次翻译队列。
- 已有真实译文的目标保留未变化译文，任务状态记录为 `translated_update_pending`（更新已翻译的内容），翻译成功后只写回变化字段。
- OpenAI、OpenCC 和 Agent Payload/Result 均接入增量字段清单；旧任务没有清单时继续走兼容的全量翻译。
- 翻译成功后写入持久完成标记；任务表新增 `change_manifest` 和 `translated_content`，升级时只按明确完成状态补写历史完成证据。

## 0.9.8.6 - 2026-07-21

- slug 冲突 fallback 格式调整为 `源slug-源文章ID-和源站id重复`，增加固定中文尾标，方便后台人工快速识别。
- fallback 仍强制保存为草稿，继续写入待复核 meta，并由同步、OpenAI、OpenCC 和 Agent 写回路径保持该 slug。
- 当前没有 fallback 待复核存量，因此不需要迁移旧格式或修改任何已有文章。

## 0.9.8.5 - 2026-07-21

- 修复 sync trait 被截断并由旧备份覆盖造成的关系安全能力回退。
- 新增独立关系安全 trait，恢复目标身份校验、关系校验、翻译任务校验、异常关系标记、详细/汇总审计和严格来源 meta 恢复。
- 同步入口重新严格执行“已有关系 → 完整来源 meta → 新建”，不按相同 ID 或相同 slug 认领。
- 新目标 slug 冲突时创建 `源slug-源文章ID` 草稿，记录待复核 meta，正常建立关系并入队，不覆盖占用文章。
- fallback 目标在队列覆盖、OpenAI、OpenCC、Agent Payload/Result 中保持 fallback slug 和草稿状态。
- 源站删除、回收、恢复及目标站人工生命周期事件重新接入身份校验、操作标记和任务停止逻辑。
- `audit-relations` 重新可用，并新增 `fallback_review` 汇总字段；fallback 关系不计入未处理的普通 slug 冲突。

## 0.9.8.4 - 2026-07-21

- 根据 OpenClaw 审核建议，补齐 `wp_insert_post()` 成功后 slug 二次锁定失败的竞态异常处理。
- 异常时只清理本次刚创建、尚未写来源 meta、关系和任务的目标文章，不触碰已有目标文章。
- 自动清理被钩子阻止时新增 `relation_create_orphan` 日志，并记录源/目标站、源/目标文章 ID 和错误码。
- OpenClaw 已完成正常新文章同步和 slug 占用冲突的可回滚灰度测试，两项通过且测试数据无残留。

## 0.9.8.3 - 2026-07-21

- 新增统一目标 slug 可用性校验，同类型文章或附件占用源 slug 时返回 `target_slug_conflict`。
- 自动同步在更新或创建目标文章前检查 slug；冲突时不认领、不覆盖，也不创建翻译任务。
- 翻译队列、OpenAI、OpenCC、Agent Payload/Result、Agent Tools 和 slug 修复接入相同冲突保护。
- Agent Result 移除独立的 `posts.post_name` 直接写入，强制 slug 入口内部再次校验并要求调用方处理错误。
- 关系详细审计和聚合审计新增 slug 冲突报告，后台关联统计将 `target_slug_conflict` 视为人工处理状态。
- 全网只读审计发现 234 条 slug 冲突关系，每个目标站 18 条；本版本不自动修改历史 slug、文章或关系。

## 0.9.8.2 - 2026-07-21

- 文章关系表新增 `target_unique (target_blog_id, target_post_id)` 唯一键。
- 文章关系写入从 `REPLACE` 改为显式 `UPDATE`/`INSERT`，避免多个唯一键下的替换语义删除冲突关系。
- 索引迁移前确认 149,617 条关系没有零目标 ID和重复目标组，并导出独立关系表备份。
- 新增严格非破坏性命令 `wp wpmu-ml reconcile-relations`，仅按完整来源 meta 查找候选，默认 dry-run。
- `audit-relations` 新增 `--summary` 聚合模式，可汇总全部或指定目标站的关系完整性。
- 严格恢复要求指定 `target_blog_id`；写入必须同时使用 `--apply --confirm=ADD_META_RELATIONS`，且只插入不存在的关系。
- 严格恢复遇到源文章已关联其他目标或目标已被占用时只报告冲突，不覆盖任何关系。
- `hreflang` 新增站点隐私、密码文章和常见 SEO noindex 元数据检查，并提供 `wpmu_ml_post_is_indexable_for_hreflang` 过滤器。

## 0.9.8.1 - 2026-07-21

- 新增统一的文章关系查询、目标占用查询、目标身份校验、异常标记、历史身份补写和只读审计接口。
- `sync_one_target()` 删除相同 ID 和相同 slug 自动认领；已有关系目标异常时阻断，新关系只按精确来源 meta 认领或创建新文章。
- 自动同步、翻译队列、OpenAI/OpenCC 分发、Agent 载荷与写回、Agent Tools 跨站写回、slug 修复和批量状态修改接入身份校验。
- 删除、回收站和恢复操作执行前校验目标身份；新增目标站人工删除、回收站和恢复的关系状态跟踪。
- 语言切换、后台当前页链接和 `hreflang` 只使用通过身份校验的关系；`hreflang` 只输出已发布页面。
- 后台移除危险重建按钮，直接后台请求和 `wp wpmu-ml rebuild` 均拒绝执行；旧版 `TRUNCATE`、同 ID 和同 slug 猜测重建实现已从运行代码删除。
- 新增只读命令 `wp wpmu-ml audit-relations`，支持 limit、offset、target_blog_id 和 source_post_id 范围参数。
- 后台关联页显示目标缺失、身份冲突、关系无效和目标人工删除/回收站异常汇总。
- 同步结果新增按目标站统计的阻断失败数量。
- 数据库目标唯一索引暂缓到完成备份和全量审计后实施；本版先使用应用层目标占用校验。

## 0.9.8 - 2026-07-21

- 将插件维护版本提升到 `0.9.8`，同步更新插件入口、版本常量、核心类和维护文档。
- 将插件根目录 Markdown 文档统一迁入 `docs/`。
- 新增 `docs/ARTICLE_RELATION_IMPROVEMENT_LOG.md`，记录文章关联改进范围、基线数据、实施状态、验证和回滚信息。
- 确认 `docs/WPMU多语言插件_文章关联改进方案.md` 为 0.9.8 文章关系安全改造的规范基线。
- 本次只进行文档与版本准备，不改变现有同步和关联行为；同 ID / 同 slug 自动认领风险仍待后续代码改造。

## 0.9.7.1 - 2026-07-16

- 最终审校新增“源概念成组复核”：从全文重复源片段、标题/摘要/章节锚点及源文与译文共享片段中选择有限概念组，并把每个已选概念的全部出现字段一起交给 AI。
- 调整最终审校候选优先级：疑似源语言残留/未翻译提示优先，其次为完整源概念组、同源不同译法、标题摘要与章节标题，最后才是章节首尾和普通风险抽样。
- 最终审校载荷新增 `g` 源概念组标签；AI 可跨字段统一理解同一概念，但仍允许符合语法和上下文的自然变体。
- 共享汉字片段只用于追加 AI 候选，不作为 PHP 错误判定或替换规则；AI `keep` 仍具有最终决定权。
- 默认最终审校上限由 100 调整为 120 个重点字段，并采用最多 12 个、合计约 80 字段的完整概念组预算，避免修好一处却遗漏同概念其他位置。
- 改进重复源片段挖掘与 Unicode 长度计算，降低固定句子碎片和宽泛短词挤占候选的概率。
- 不增加自动翻译记忆，不增加任何语言词语硬替换。
- 版本号同步更新为 0.9.7.1。

## 0.9.7.0 - 2026-07-16

- 删除易失败的 AI 文章规划格式解析，改由 PHP 从源标题、摘要、章节标题和重复源片段构建只读文章上下文；不生成目标语言术语，也不进行自然语言替换。
- 保留按章节连续翻译，并同时传递上一篇源文尾部与译文尾部，降低跨批次语义漂移。
- 文章级质量链重构为一次“源文 + 当前译文 + 字段角色 + 章节上下文”的最终审校；AI 只返回 `keep` 或 `rewrite|||最终完整译文`。
- 取消文章正文的“先判 rewrite、再单独盲修复、再关联扩张”多层链路，避免修复请求脱离源文后改错含义。
- 标题、摘要、各级标题、章节首尾、结构/残留提示字段、重复源文冲突及源片段相关字段统一进入最终审校候选。
- PHP 仍只负责字段完整、非空、WordPress/HTML/JSON/短代码/URL/占位符结构和写回回读验证；AI `keep` 不得被覆盖。
- 后台日语提示词示例压缩为两句；不加入自动翻译记忆。
- 版本号同步更新为 0.9.7.0。

## 0.9.6.6 - 2026-07-16

- 正文翻译改为“全文短总览 + H2/H3 连续章节批次 + 相邻章节上下文”，默认每批最多 36 个字段、约 2200 个源字符，避免一次提交 172 个零散字段。
- 动态文章规划不再生成“源词 => 目标译法”的机器术语表，只生成主题、受众、文风和 4～12 个高风险源概念，避免错误术语提前锚定全文。
- 新增整篇一致性锚点审校：标题、摘要、各级标题及每章首尾正文全量 AI 检查，之后仍执行全文自适应高风险质检。
- AI 明确 rewrite 后，可扩展复核同篇文章中包含相同源概念的其他字段；PHP 仅选择候选，仍不得直接替换自然语言。
- 压缩代码层正文任务说明与紧凑提示组件；后台语言提示词界面改为建议 2～3 句，结构保护继续由代码层负责。
- 不加入自动翻译记忆。
- 版本号同步更新为 0.9.6.6。

## 0.9.6.5 - 2026-07-16

- 新增文章级动态术语上下文：翻译标题、摘要、正文、Gutenberg、postmeta 和 SEO 字段前，先由 AI 根据当前文章生成上下文术语指南，并在各批次中共享。
- 动态术语仅作为 AI 上下文，不作为 PHP 固定词典或字符串替换规则；用户显式术语表仍具有更高优先级。
- 加强跨字段术语一致性与目标语言自然度，减少同一概念在标题、正文和元数据中出现互相冲突的译法。
- 新增关联残留扩展质检：当 AI 明确发现某个字段存在源语言残留时，PHP 只负责定位包含同一共享片段的其他字段并追加给 AI 复核，不直接替换文本，也不覆盖 AI 的 keep。
- 集中 QA 和定点修复均接收文章级术语上下文。
- 新增 `ARTICLE TERMINOLOGY CONTEXT`、`RELATED TERM QA EXPANSION` 和 `RELATED TERM QA RESULT` 跟踪日志。
- 版本号同步更新为 0.9.6.5。

## 0.9.6.4 - 2026-07-16

- PHP 本地质量逻辑改为严格只读：不再修改标点、空格、数字格式、长度或疑似残留；这些只作为可选 AI 质检提示。
- AI 返回 `keep` 始终保留当前译文；只有明确 `rewrite` 才进入 AI 定点修复。
- QA 状态解析兼容 `rewrite/reason`、`rewrite:reason`、`rewrite-reason` 等明确格式。
- 质量日志区分唯一候选、原始字段、唯一已检查和原始已覆盖字段，避免 54/55 口径混淆。
- 新增文章与 postmeta 的 `WRITEBACK VERIFY` 成功/失败追踪日志。
- “翻译设置 → 帮助”新增网络启用、HTTP/SSL、PCRE Unicode、Cron、数据库字符集、MySQL packet、disable_functions 和按需 OpenCC 依赖检测。
- 版本号同步更新为 0.9.6.4。

## 0.9.6.3 - 2026-07-16

- PHP 本地完整性检查改为始终开启且不可关闭，只检查字段返回、空值、WordPress/HTML/JSON 结构、占位符与数据库写回完整性。
- 后台质量设置收敛为单一“开启 AI 质量检查”开关；移除覆盖模式、自动修复、草稿保护和按语言质检开关等用户配置。
- 疑似源文残留、数字差异和长度差异改为 AI 提示，不再作为 PHP 硬错误、强制 rewrite 或发布拦截条件。
- AI `keep` 结果具有最终编辑判断权，不会被本地启发式规则覆盖；只有 AI 明确返回 `rewrite` 才进入定点修复。
- 永久关闭旧的 PHP 残留补翻和本地标点编辑修复路径，避免本地启发式修改已接受译文。
- OpenAI 与 Agent API 共用字段、结构、占位符完整性规则；结构比较允许成对行内标签随目标语言语序安全移动。
- 新增文章标题、摘要、正文、状态、slug 及元数据写回后的数据库回读校验，并在写入失败或回读不一致时返回明确错误。
- 版本号同步更新为 0.9.6.3。

## 0.9.6.2 - 2026-07-16

- Removed the request-character-limit control from the admin UI.
- Internalized the tested safe batching profile: 6,000 source characters per translation request, 200 fields, 24 adaptive QA candidates, 80 QA fields, and 12,000 QA characters.
- Added an upgrade migration and runtime normalization so legacy values such as 30,000 cannot continue producing oversized requests.
- Clarified that batching affects latency and request reliability; translation quality remains governed by prompts, context, terminology, deterministic checks, and adaptive QA.

## 0.9.6.1 - 2026-07-16

- Simplified the OpenAI content and quality settings UI.
- Kept only one user-facing performance control: the per-request character limit.
- Removed user-facing semantic-block count, adaptive candidate count, translation batch size, QA batch field count, QA character count, risk sampling, legacy QA batch and residue controls.
- Internal translation and QA limits are now derived automatically from the request character limit.
- Consolidated quality controls into quality mode, automatic repair, and draft protection.
- Preserved existing ACF/Postmeta and SEO translation switches.
- Added upgrade migration so existing sites receive coherent internally derived limits without manual reconfiguration.

## 0.9.6.0 - 2026-07-16

- Replaced exhaustive second-pass AI review as the default with `adaptive` quality mode: every translation request performs silent inline self-review, PHP deterministically checks all translated fields, and only anomalies plus a bounded set of highest-risk fields receive a second AI review.
- Kept `all` mode as an explicit diagnostic option and retained `risk`/`off` compatibility modes.
- Added adaptive candidate detection for deterministic number/entity defects, placeholder or source-residue rejection, punctuation damage, abnormal length ratios, unchanged human text, title/summary priority, and exact repeated-source translation inconsistency.
- Added compact QA payloads, reducing repeated ROLE/SOURCE/TRANSLATION wrapper overhead.
- Added detailed repair-validation failure logs with key, reason, source snippet and candidate snippet.
- Added model-output normalization for spaced thousands separators such as `20, 000+`.
- Added settings for inline translation self-review and adaptive AI candidate limits; exact prior default `all` installations migrate to `adaptive` while explicit non-default modes are preserved.

## 0.9.5.0 - 2026-07-16

- Reworked ordinary Gutenberg block-data translation so all recognized human-readable JSON leaves are collected and translated in bounded batches instead of one API request per short field.
- Reworked ACF/Postmeta/Rank Math/Yoast/AIOSEO translation so recognized plain-text leaves are collected across Meta Keys, translated together, and written back by their original array/JSON paths; URLs, IDs, ACF references, keys and machine values remain protected.
- Increased the centralized translation field ceiling from 45 to 120 and the centralized QA defaults from 20/9000 to 80/16000. The 0.9.5.0 migration changes only exact 0.9.4.9 defaults and preserves explicitly customized values.
- Deferred the older pre-QA structural repair in `all` mode, removing a duplicate large body request before the full centralized QA pass.
- Changed concentrated repair payloads so API values contain only the current translation; source, role and issue context now live in the instruction. Added rejection of repair-context wrapper echoes.
- Replaced literal digit-presence validation with hard-fact numeric normalization. Money, percentages, dates, versions, specifications, ranges, quantities and number/unit attachment remain strict, while valid language changes such as `4` → `four`, `第一步` → `Step 1`, and `从0到1` → `from scratch` no longer fail repair validation.
- Retained explicit number/entity-boundary protection for errors such as `双11 + 亿级订单` being collapsed into `11 billion orders`.
- Corrected deterministic coverage accounting so logs report checked fields rather than only the number of final check runs.
- Added target typography normalization for misplaced spaces inside thousands separators, such as `20, 000+` → `20,000+`.
- Updated trace output with `DATA TRANSLATION BATCH`, `GUTENBERG DATA BATCH`, `POSTMETA BATCH SUMMARY`, `ARTICLE PRE-REPAIR DEFER`, and effective translation batch limits.
- Updated all plugin version constants and documentation to `0.9.5.0`.

## 0.9.4.9 - 2026-07-16

- Upgraded centralized AI QA from a maximum of eight risk-selected body blocks to full coverage of all successfully translated human-readable content.
- Added `all`, `risk`, and `off` QA coverage modes; `all` is the default.
- Added dual QA batching limits: 20 fields and 9000 source-plus-translation characters per request by default.
- Added source/translation pair deduplication with raw-field coverage accounting.
- Included post title, excerpt, coherent body blocks, Gutenberg block-data JSON, translatable HTML attributes, and recognized ACF/Postmeta/Rank Math/Yoast/AIOSEO text in centralized QA.
- Added concentrated problem-field repair and deterministic post-repair checks for numbers, number/entity boundaries, JSON key structure, HTML tags, template variables, placeholders, and machine tokens.
- Replaced the final fixed Meta Key residue list with the existing generic Postmeta field-discovery and skip rules.
- Added strict `review_required` handling whenever expected QA coverage is incomplete or repairs fail.
- Updated performance diagnostics to a 120–180 second normal target based on the 0.9.4.8 120-second baseline, with 240 seconds as the acceptable diagnostic ceiling.
- Updated all plugin version constants and documentation to `0.9.4.9`.

## 0.9.4.8 - 2026-07-16

- Replaced per-block repeated AI QA with a centralized high-risk article audit. AI quality review remains enabled; it is concentrated into one or two bounded batches rather than removed.
- Added risk scoring and a configurable `openai_central_qa_max_fields` limit (default 8) for prices, billing periods, specifications, dates, protected machine values, structural defects and substantial prose.
- Added `openai_central_translation_batch_fields` (default 45), reducing a typical 69-block body from three translation batches to two when the character limit permits.
- Large body translation now starts with the compact locale/fidelity/structure prompt and retains the full prompt as fallback, reducing prompt tokens and latency.
- Title/excerpt no longer receive duplicate language-identification and editor approval calls after their initial field-aware AI translation in centralized mode.
- Plain Gutenberg/postmeta text no longer receives a separate AI language-identification request per value in centralized mode; deterministic acceptance and final residue checks remain mandatory.
- Centralized article QA and repair use bounded 4-field batches with recursive splitting disabled; the default 8 selected blocks require at most two QA requests. Strict mode records unavailable centralized QA as partial coverage and keeps the target draft.
- Added trace markers `CENTRAL QUALITY DEFER` and `CENTRAL AI QA`, plus performance targets of 8–15 requests and 70–120 cumulative API seconds.
- Added administrator controls for centralized QA, centralized translation batch size, maximum AI-reviewed high-risk blocks and QA batch size.
- Synchronized plugin version and Markdown documentation to `0.9.4.8`.


## 0.9.4.7 - 2026-07-16

- Fixed editorial single-field fallback parsing so plain `keep`, `ok`, `pass`, `correct`, `accept`, `no change` and equivalent statuses end the QA request immediately instead of being misclassified as invalid JSON and retried through Responses API.
- Separated completed translation from optional editorial-QA availability. An unavailable article-editor request now keeps the successfully translated block, records partial quality coverage, and becomes `review_required` in strict mode instead of putting the translation job back into `pending`.
- Added a per-job article-editor circuit breaker. After one complete multi-mode editor failure, remaining optional editor batches are skipped and counted as unavailable instead of repeating three costly empty-output requests for every block.
- Tightened risk-based editor selection. Short translated workflow diagrams with preserved arrows, short labels, headings and ordinary short list items use deterministic validation; prices, billing periods, specifications, placeholders and substantial prose still receive AI editorial review.
- Strict-QA publications are staged as drafts and released to their requested final status only after final residue, deterministic and quality-coverage checks pass. This removes the temporary publication window before QA completes.
- Added conservative Rank Math consistency synchronization for auto-generated old title/description values while preserving `%variable%` templates and intentionally customized SEO copy.
- `API PERFORMANCE` now includes a terminal `outcome` and is emitted for completed, review-required and failed jobs.
- Added trace markers `ARTICLE EDITOR CIRCUIT SKIP`, `QA STAGING`, `QA STAGING RELEASE`, and `SEO CONSISTENCY SYNC`.
- Synchronized plugin version and Markdown documentation to `0.9.4.7`.


## 0.9.4.6 - 2026-07-16

- Fixed a 0.9.4.5 regression where optional long-excerpt editorial QA could return an empty upstream shell and abort the entire translation before Gutenberg/body processing began.
- Fast quality mode now performs the separate title/editor polish on the title only; excerpts remain covered by the required source/target audit, deterministic checks and final article QA.
- Optional title/editor polish availability failures no longer discard an otherwise valid translation or restart the job.
- Added a generic human-flow detector for Gutenberg/page-builder fields named `code`; architecture and workflow lines with arrows are translated as prose instead of being routed through the executable-code parser.
- Retained safe single-field alias recovery (`text/translation/result/output -> requested key`) for compatible gateways that rename the only response field.
- Added risk-based article editor filtering: facts, prices, specifications, long prose, markup and workflow lines still receive AI editorial review, while very short labels/headings use deterministic checks. This reduces unnecessary reasoning-model calls without relaxing residue or structural validation.
- Added trace markers `TITLE/EXCERPT EDITOR FAST SKIP`, `GUTENBERG HUMAN FLOW ROUTE`, and `ARTICLE EDITOR RISK FILTER`.
- Synchronized plugin version and Markdown documentation to `0.9.4.6`.


## 0.9.4.5 - 2026-07-16

- Added safe single-field alias recovery for compatible models that return `text`, `translation`, `translated_text`, `result`, `output`, `content`, or `value` instead of the requested key. Extra fields remain forbidden, so this does not permit generated excerpts or unrelated data.
- Small Gutenberg block-data, attribute and code-text requests now use compact prompts first, avoiding a large full-prompt failure before fallback.
- Added the fast quality pipeline. Main body translation batches use deterministic integrity checks immediately and defer duplicate AI language identification to the whole-article editor audit.
- QA/editor requests start with a configurable small batch (`openai_qa_batch_fields`, default 3) rather than repeatedly failing at 12/30 fields before recursive splitting.
- Long combined title/excerpt editor requests are proactively split when their audit payload exceeds the reliable compact threshold.
- Gutenberg block-data failures now contribute to quality coverage, and fail-on-QA prevents an unresolved content surface from being reported as a completed publication.
- WP-CLI `translate-one` reports `review_required` as a warning and no longer prints a misleading success banner.
- Added per-job API performance diagnostics: request count, semantic failures, fallbacks, cumulative seconds, and per-stage request/time totals.
- Added administrator controls for the fast quality pipeline and QA batch size.
- Synchronized plugin version, README, CHANGELOG and `ARCHITECTURE_AND_TRANSLATION_RULES.md` to `0.9.4.5`.

## 0.9.4.3 - 2026-07-16

- Translation quality release: semantic-block body translation is enabled by default while the existing character/field settings continue to control API batch size.
- Human-readable inline-code labels are deferred into their surrounding semantic block, allowing the model to infer technical units from sentence context and keep repeated specifications consistent.
- Fixed QA status parsing so `wrong:`, `fail:`, `reject:`, `fix:` and structured negative verdicts can no longer be silently treated as a pass.
- Fixed the language-audit `han` substring bug that matched ordinary words such as `changes` and discarded genuine semantic findings.
- Title/excerpt editorial repair now receives source, current target and the concrete QA issue; it explicitly preserves facts, currency identity and billing relationships.
- Added non-CJK target typography normalization for full-width source punctuation and zero-width separator artifacts in visible translated text.
- Added conservative inline-HTML boundary spacing repair for publication copy while protecting code/pre/script/style/textarea regions.
- Added `TRANSLATION MODE`, `TITLE/EXCERPT EDITOR QA`, `ARTICLE EDITOR AUDIT invalid_status` and `HTML BOUNDARY POLISH` trace diagnostics.
- Added a network setting to disable semantic-block translation only for legacy/malformed payload compatibility.
- Synchronized plugin version, README, CHANGELOG and `ARCHITECTURE_AND_TRANSLATION_RULES.md` to `0.9.4.3`.

## 0.9.4.2 - 2026-07-16

- 翻译请求会在发送前移除空字符串字段，并在成功后原样恢复；标题有内容但摘要为空时现在按单字段处理，可进入纯文本兜底，不再被误算为双字段 JSON 任务。
- 标题/摘要、SEO 元数据、语言审计、编辑审校、标题层级和残留修复使用分阶段精简提示词；正文主批次仍可先用完整规则，语义失败后真正降级到精简提示词，而不是在原长提示词后继续追加 recovery 文本。
- 单字段失败链路改为 `compact JSON → Chat 纯文本 → Responses API 纯文本`；纯文本由 PHP 包装回原字段键。Responses API 仅作最后兜底，不作为默认接口。
- 纯文本兜底保留事实、数字、原币种、品牌和正式产品名，明确禁止汇率/币种转换和新增信息，避免 `6块5` 被擅自翻成 `$6.50`。
- 新增 `upstream_output_missing` 诊断：HTTP 200、已消耗输出 Token、`finish=stop`，但响应没有 `content`/`output_text` 时，明确报告上游可见输出缺失，并继续切换到更轻量模式。
- `--trace` 新增空字段跳过、完整/精简提示词长度、语言提示词/站点规则/术语表组件长度、系统提示词哈希、实际协议与 endpoint、`content_present`、Responses 输出结构以及 `API UPSTREAM_OUTPUT_MISSING`。
- 小型多字段翻译在所有模式失败后可直接拆为单字段处理；正文大批次仍保留递归二分定位，并把新的上游输出缺失错误纳入可拆分错误。
- 插件入口、核心类、README、CHANGELOG 和 `ARCHITECTURE_AND_TRANSLATION_RULES.md` 版本同步为 `0.9.4.2`。

## 0.9.4.1 - 2026-07-15

- OpenAI 兼容请求改为自适应降级：`json_object`、无 `response_format` JSON、JSON recovery，以及单字段纯文本兜底；不再对语义空响应原样重试六次。
- 正文片段批量失败时采用递归二分拆批，逐步定位失败字段；叶子失败日志包含字段 key、字符数、源文摘要和错误码。
- `--trace` 新增调用计划、请求模式、字段摘要与哈希、响应 envelope/message 键、`content`/`reasoning_content`/`refusal`/内嵌 error、Token 用量及语义失败原始响应 Base64。
- 调整通用可翻译文本判定：短文本和数字/字母/源语言混排不再要求连续两个字母；仅高置信度 URL、路径、占位符和机器标识符跳过。
- 残留补翻遵循 inline code 的 smart/protect 配置，并输出具体残留候选源文和目标文；最终残留校验同步尊重显式 no-translate、受保护代码和排除标签，避免前后规则矛盾。
- 语言审计不可用时记录 `status=unavailable`，不再伪装为 `wrong=0` 的成功审计；修复纯文本审计失败分支中的未定义变量。
- 删除代码内置提示词中的具体厂商/短语个案，保留通用的产品类别、规格、价格、周期和混合文本本地化规则。
- 维护规则文件改名为 `ARCHITECTURE_AND_TRANSLATION_RULES.md`；插件入口、核心类、README、CHANGELOG 和规则文档版本同步为 `0.9.4.1`。

## 0.9.4.0 - 2026-07-15

- 将约 11,800 行的 `includes/core/class-wpmu-ml-core.php` 缩减为核心装配壳，保留单例、类状态、Hook 注册和原有公共接口。
- 把 302 个历史业务方法按后台、路由、队列、OpenCC、OpenAI 主流程、内容处理、元数据、质量校验、API 客户端、同步和语言切换等职责拆入 13 个 trait 文件。
- 新增 `includes/core/traits/bootstrap.php` 统一加载模块；旧核心路径和兼容加载壳保持可用。
- 新增根目录 `ARCHITECTURE_AND_TRANSLATION_RULES.md`，明确代码归属、通用翻译规则、禁止逐词补丁、最终残留定向修复和验收要求。
- 本次为等价结构重构，不修改数据表、option、Hook、任务状态或既有翻译行为。

## 0.9.3

- 修复多站点语言菜单只在已手工添加项目的分站显示、其他语言分站为空的问题。
- 对主题注册的 `language-menu` 自动创建或修复菜单绑定，再补齐“当前语言”父项和全部启用语言子项；前台继续按当前访问分站动态替换父项名称和链接。
- 清理指向不存在 Language Switcher 项目的失效菜单项，避免站点复制或旧菜单数据导致前台空菜单。
- 保存“语言站点”或“语言切换”设置后自动重新同步全部启用分站；插件升级后执行一次幂等迁移同步。

## 0.9.2

- 在“翻译设置”中新增独立的“语言切换”选项卡，位置在“语言站点”和“内容类型”之间。
- 新增“显示在后台菜单”开关；启用后，各语言分站的“外观 → 菜单”会出现 `Language Switcher` 面板。
- 自动创建并同步“当前语言”和所有已启用语言的虚拟菜单项目，语言站点名称或启用状态变化后同步更新。
- 前台经典菜单会动态替换当前语言名称与对应页面链接，并自动隐藏下拉列表中重复的当前语言；下拉展开样式继续继承当前主题菜单。
- 为后续国旗、简称、桌面端与移动端显示样式预留独立设置区域。

## 0.9.1

- 修复启用大量语言时，翻译引擎页单一大表单超过 PHP `max_input_vars`，导致 OpenAI、路由和其他设置无法保存的问题。
- 精确路由覆盖改为一次只编辑一种目标语言，避免“语言数 × 内容类型”形成上千个 POST 字段。
- 保存时只提交当前一级引擎面板，未显示面板不再误清空 checkbox、路由数组或 OpenCC 设置。
- 精确路由保存改为按当前目标语言合并，其他语言已有覆盖规则不会被删除。
- 所有网络设置保存后立即回读校验；数据库只读、写权限不足或对象缓存异常时直接显示错误，不再错误提示“已保存”。

## 0.9.0

- 后台菜单修正为一个一级菜单 `WPMU 多语言`，自动首个子菜单改名为 `翻译设置`，并新增同级子菜单 `翻译引擎`；不再创建两个一级菜单。
- `翻译设置` 保持原链接 `admin.php?page=wpmu-multilingual`；`翻译引擎` 使用 `admin.php?page=wpmu-multilingual-engines`。
- 翻译引擎第一层改用 WordPress 标准 `nav-tab-wrapper / nav-tab` 链接式 UI，与翻译设置页面一致；OpenAI 引擎内部二级选项卡不变。
- 保持 `wpmu_ml_settings`、`openai_api_base`、`openai_api_key`、`openai_model`、`openai_temperature` 等原有字段不变；新增旧 network option、主站 option 和少量旧别名的只补缺失键兼容读取。
- API Key 输入框改为留空保持旧值，只有填写新值才替换；新增显式清除选项，避免页面重构或浏览器密码行为导致 Key 被意外清空。
- “按目标语言设置翻译方式”以及“目标语言 + 文章类型精确覆盖规则”默认折叠，减少多语言网络中的页面长度。
- 保存翻译引擎设置后返回当前引擎选项卡。
- 版本号进入 `0.9.0`。

## 0.8.17.33

- 网络后台拆分为两个独立一级菜单：`翻译设置` 继续承载语言站点、内容类型、关联、同步、队列、工具和帮助；`翻译引擎` 单独承载引擎与路由配置，原 `tab=engines` 地址自动跳转到新菜单。
- 翻译引擎页保留第一层选项卡：默认与路由、OpenAI 兼容、Agent API、OpenCC、人工翻译、翻译规则和高级说明，为每个引擎继续扩展自己的二级选项卡预留空间。
- OpenAI 兼容新增二级选项卡：接口与默认模型、语言设置、内容处理、质检与编辑。
- OpenAI 语言设置自动读取已启用目标语言站点；每种语言可单独设置模型、Temperature、附加提示词、本地质检、整篇 AI 编辑审校和质检失败阻止发布。
- 语言专用提示词只注入当前目标语言；模型优先级改为“语言 + 文章类型精确覆盖 → 语言模型 → 文章类型模型 → 全局默认模型”。
- `article_editor_qa` 新增全局开关和逐语言覆盖，便于对普通长尾语言关闭额外编辑审校、控制 Token；主翻译、结构保护和通用目标语言锁不受影响。
- 新增翻译引擎菜单与 OpenAI 语言档案回归测试；插件版本更新为 `0.8.17.33`。

## 0.8.17.32

- 新增整篇 AI 编辑审校阶段 `article_editor_qa`：按文章顺序对照源文块和当前目标译文，由模型判断残句、机械直译、语义错位、逻辑反转、上下文断裂、重复和非母语表达，而不是依赖固定语言正则或词表。
- 审校阶段只返回 `ok` 或 `rewrite:原因`；仅对被标记的块执行定点重写，并向重写请求提供前后源文/译文上下文，允许修复源文自身的残缺句式，但禁止新增事实或重复邻接内容。
- 初次主翻译提示增强：HTML block 只是布局边界，不代表源句完整；模型必须结合相邻有序块，像专业翻译人员一样产出完整、自然、可发布的目标语言文本。
- 编辑重写后继续执行占位符完整性和通用目标语言审计；失败块不会覆盖原译，并在 CLI trace 中输出 `ARTICLE EDITOR REJECT`。
- 语言、文字和语法规则继续完全来自后台源语言/目标语言上下文，不新增任何硬编码语言分支；跨语言固定值沿用 0.8.17.31 的跳过逻辑。
- 新增整篇编辑审校与定点修复回归测试；插件版本更新为 `0.8.17.32`。

## 0.8.17.31

- 修复通用目标语言锁把应当跨语言保留的值送入残留补翻和语言审计的问题；裸域名、完整 URL、邮箱、文件名、路径、代码标识符、单个品牌/型号词、编号品牌项（如 `1. Divi`）及紧凑版本号不再被误判为未翻译正文。
- 残留补翻在比较“源文与译文完全相同”前，先执行语言无关的不可翻译值识别；`hollywoodreporter.com`、`functions.php`、`PHP 8.3`、`1. Divi` 等不会再触发无意义 API 重试。
- 语言审计沿用后台配置的源语言与目标语言，不新增任何固定语言分支；只有明确的非正文值可以跳过，普通自然语言原文即使保持不变仍会继续接受目标语言检查。
- 保留 0.8.17.30 的混合语言拦截和 CLI 错误直出；本次日志中的三处中日混杂仍会被发现并定点重译，域名/品牌标签误判则被消除。
- 新增域名、文件名、版本号、编号品牌项与普通正文区分的回归测试；插件版本更新为 `0.8.17.31`。

## 0.8.17.30

- 将目标语言锁改为完全由后台源语言与目标语言上下文驱动的通用审计，不再使用日语、俄语、泰语或其他固定语言/字符集分支。
- 每个有意义的翻译批次增加一次 `language_qa` 请求；审计器只判断候选普通正文是否属于当前目标语言，并允许品牌、代码、URL、文件名、缩写、型号、数字、引用和通用技术术语保留原文。
- 语言跑偏只重试失败字段；两轮恢复后仍跑偏则原子失败，阻止不符合目标语言的文章保存或发布。
- OpenAI 正文、Translation Blocks、HTML 属性、Gutenberg/ACF、自定义字段和智能代码文本的源文检测改为 Unicode 通用自然语言检测，不再依赖 `\p{Han}` 等固定源文字脚本；OpenCC 引擎本身仍只用于简繁转换。
- 残留补翻后台文案与实际逻辑统一改为“源语言残留”，根据源文与目标译文逐片段对照判断，不再固定为“残留中文”。
- WP-CLI 单篇任务失败时直接显示 `job_id`、`status`、`attempts` 和任务表 `last_error`；最终不完整翻译错误增加失败字段、拒绝原因、源文及模型返回片段，便于在当前终端直接定位。
- 新增中文→俄语、俄语→泰语、持续跑偏阻止保存、Unicode 多脚本源文提取及 CLI 直出错误回归测试；插件版本更新为 `0.8.17.30`。

## 0.8.17.29

- 修复日语目标语言锁误判：`0.8.17.28` 的简体中文字符类中写入了“数据”，正则字符类会把其中单独的“数”也视为命中，导致“複数、数日、数字、関数、インストール数”等完全正常的日语被错误拒绝。
- 简体中文检测改为“强简体字 + 假名/汉字比例 + 中文功能短语”的组合判断；正常日语汉字不再触发整字段重试。
- 保留对韩语、西里尔文、阿拉伯文、希伯来文、泰文、天城文、长篇英语和明显中文段落的硬拦截。
- 少量混在日语中的中文残留交由原有 source-aware residual filter 定点补翻，不再因为单个共享汉字让整篇任务失败。
- 新增 `複数・数日・数字・関数・インストール数` 等日语误判回归测试；插件版本更新为 `0.8.17.29`。

## 0.8.17.28

- 正文主提示改为“忠于原文事实的目标语言编辑式本土化”：保留事实、数字、品牌和结构，同时允许在意图明确时修复源文残句、缺谓语、重复标点和轻微语病，不得凭空增加事实或观点。
- 新增日语目标语言硬锁：每个标题、摘要、正文块、Gutenberg/ACF 字段、属性和残留补翻结果在接受前都检查语言；韩语、西里尔文、阿拉伯文、希伯来文、泰文、天城文、长篇英语及明显中文会被拒绝。
- 语言跑偏字段沿用缺失字段恢复机制，只重试失败字段；连续两轮仍跑偏时原子失败，阻止混入其他语言的文章保存或发布。
- 标题和摘要新增独立目标语言重试；修复后的标题再次检查语言和缩写冗余。
- 新增轻量级日语文章编辑 QA：正文一次主翻译后，仅识别悬空助词、句尾逗号、缺谓语、条件残句和引号不闭合等明确问题，并对问题段落做一次小型修复，不重新翻译整篇正文。
- 最终 AI 质检新增整篇日语语言跑偏扫描；允许 WordPress、SEO、HTML、CSS 等品牌和技术缩写，不把正常日语汉字或短纯汉字标签误判为中文。
- 新增日语语言锁、持续跑偏原子失败、选择性缺谓语修复、韩语/英语整篇质检回归测试；插件版本更新为 `0.8.17.28`。

## 0.8.17.27

- 新增日语译后可见文本安全润色：仅处理正文可见文本节点，不改 HTML 标签、属性、代码、Gutenberg 注释、URL 和显式 no-translate 区域。
- 自动修正常见未本土化短标签：`提示`→`ポイント`、`警告`→`注意点`、`Pros/Cons`→`メリット/デメリット`、`最終的な判断`→`まとめ`。
- 自动清理日语重复句末标点，例如 `。.`、`。。`、`！.`、`？.`，避免源文标点与日文句号叠加。
- 日语标题新增冗余术语质检：检测 `仮想専用サーバーVPS` 或同一标题重复 `VPS` 时，只对标题进行一次小型重译，不重新翻正文。
- 加强日语提示词：明确要求 Pros/Cons 本土化、标题缩写只引入一次、不得输出重复句末标点。
- 新增日语短标签、标点、受保护区域和标题冗余回归测试；插件版本更新为 `0.8.17.27`。

## 0.8.17.26

- 修复 translation block 在中译日时因不透明 `__WPMU_ML_INLINE_*__` 标记被模型移动、导致占位符校验失败和任务中止的问题。
- 行内 HTML 改用成对、可嵌套的 `<wpmu-ml-N>...</wpmu-ml-N>` 占位标签；模型可按日语语序移动完整标签对，但不能拆散、改名或丢失。
- 移除正文片段前多余的 `__WPMU_ML_CONTEXT_*__` 控制标记，降低模型遗漏键和误改占位符的概率。
- 新增占位符 HTML 实体/大小写/空格归一化、精确数量校验和目标树嵌套校验，恢复时仍写回原始 HTML 字节。
- 带占位符的失败字段只在恢复阶段逐字段重试；正文主请求仍保持整篇/大批上下文，不改变正常翻译批次策略。
- 新增日语语序重排与损坏占位符回归测试；插件版本更新为 `0.8.17.26`。

## 0.8.17.25

- 仅重构 OpenAI 正文翻译提取/回填，不改 WPMU 多站同步、队列、关系表、slug 和发布逻辑。
- 参考 TranslatePress 的 top-parent / deepest translation block 思路：候选父元素包含更深候选块时不再作为整块提交，避免父子内容重复翻译。
- 新增原始字节偏移安全的 HTML 扫描器，替代正文语义块路径中脆弱的标签正则；支持属性值包含 `>`、注释、复杂嵌套和松散文本，同时不序列化或重排 Gutenberg HTML。
- 扩展语义块到段落、H1-H6、列表项、图注、引用、表格单元格以及无更深语义子块的 div/section/article 等；行内标签继续占位保护。
- 正文请求新增文章级标题/摘要上下文，明确要求把按原顺序排列的所有 translation blocks 当作一篇从头到尾连续的文章处理，统一术语、指代、过渡和语气。
- 新增 `alt`、`title`、`placeholder`、ARIA 文本和按钮类 value 的独立安全翻译，只替换属性值并保留原标签及其他属性。
- `--trace` 新增 `TRANSLATION BLOCKS`、`TRANSLATABLE ATTRIBUTES` 和 `body_attributes` 阶段。
- 新增 translation block parser/attribute 回归测试；插件版本更新为 `0.8.17.25`。

## 0.8.17.24

- 修复日语正文开启“残留中文二次补翻”后，正常日语汉字被再次当作 CJK 残留，导致整篇正文产生 `body_residual` 二次翻译的问题；日语现在结合源文逐片段判断，只补传真正仍为中文的内容。
- 当“单批片段数量上限”为 `0` 时，完整段落、H1-H6 标题、列表项等改为连同行内 `<strong>`、`<a>`、`<span>` 标签一起构成语义单元，不再把一句话按行内标签切成多个孤立片段。
- 行内 HTML 使用受控占位符发送，返回结果必须保持占位符数量和顺序；异常结果进入现有缺失字段分组恢复，防止标签损坏或残留占位符写入文章。
- `30000 + 0` 现在表示：正文按完整语义单元和原顺序提交，3 万字符以内通常只使用一个可见正文主请求；字符上限仍可单独控制超长文章拆批。
- 日语系统提示新增日本技术媒体和主机行业本土化规则，减少“共享ホスティング”“提示”“最終的な判断”等中文式直译，要求标题、段落和列表项保持完整自然日语。
- 残留补翻继续遵守 no-translate 注释、选择器和显式不翻译区域，不会在二次补翻时破坏受保护内容。
- 新增 coherent body 与日语 source-aware residual 回归测试；插件版本更新为 `0.8.17.24`。

## 0.8.17.23

- OpenAI 分批设置现在允许填写 `0`：字符上限 `0` 表示不按字符数拆批，片段上限 `0` 表示不按片段数量拆批。
- 两项同时为 `0` 时，正文中的 H1-H4 标题片段和普通正文片段按原顺序合并为一个主请求，尽量让模型一次看到完整正文上下文；若模型漏字段，仍保留小批恢复与原子失败保护。
- 片段数量上限的可配置范围从 `10-100` 调整为 `0-5000`，字符上限调整为 `0-60000`。
- 翻译前污染清理从仅兼容 Immersive Translate 扩展为多来源签名清理：Immersive Translate、Chrome/Google Translate 的 `font style="vertical-align: inherit"` 包装与注入 UI、Firefox/Bergamot 的 `x-bergamot-*`、Edge 的 `_mst*`、常见 DeepL/翻译扩展属性与 class，以及剪贴板 `StartFragment/EndFragment` 标记。
- OpenAI 兼容响应新增混合内容 JSON 提取：可从 BOM、PHP 警告、主题/插件调试输出、HTML 包装、Markdown 代码块、HTML 实体和无关调试 JSON 中恢复正确的 API envelope 与翻译对象。
- `--trace` 新增 `POLLUTION CLEANUP` 与 `RESPONSE CLEANUP` 诊断；Agent payload、Agent Tools 与本地 OpenAI 翻译统一使用同一套结构污染清理。
- 新增整篇正文零限制、浏览器翻译污染和受污染 JSON 响应回归测试；插件版本更新为 `0.8.17.23`。

## 0.8.17.22

- Added narrow cleanup for HTML artifacts injected by the Immersive Translate browser extension before translation extraction.
- Generated `immersive-translate-*` attributes/classes and wrapper spans are removed in memory, while legitimate site-authored `.notranslate` regions remain protected.
- ACF/Gutenberg rich-text fields polluted by browser-extension wrappers can now enter the normal translation pipeline.
- QA now compares sanitized source and target copies so extension-generated `.notranslate` wrappers cannot hide untranslated Chinese.
- `--trace` reports `IMMERSIVE CLEANUP` statistics when artifacts are found.

# WPMU多语言插件版本记录

### v0.8.17.21

- 修复日语目标翻译中的原文回显误判：当待检查片段已经包含平假名或片假名时，不再把“源文与返回值相同”判为未翻译中文。
- 修复 Gutenberg 区块数据先翻译、随后在正文序列化内容中再次遇到同一日语片段时触发两轮无意义重试并导致任务失败的问题。
- 日语目标下的纯汉字/shared 技术词不再仅凭汉字数量判错；只有检测到明确的简体中文字符或常见中文功能词时才触发原文回显恢复。
- 保留对真正中文长段落原样回显的拦截，不降低“未翻译中文不得发布”的保护标准。
- 新增日语片段级回归测试；插件入口、核心类和 README 版本统一更新为 `0.8.17.21`。

### v0.8.17.20

- 将“排除翻译标签”升级为“排除翻译标签 / 选择器”，保留原有 `pre`、`code` 等标签配置兼容性。
- 新增简单 CSS 选择器支持：`.class`、`#id`、`tag.class`、`tag#id`、多 class、`[attr]`、`[attr="value"]` 及其组合。
- 兼容直接粘贴 `class="promo-card"`、`id="fixed-banner"`、`data-no-translation` 和 `translate="no"`，保存后自动规范化为选择器。
- 使用基于原始 HTML 字节位置的嵌套元素匹配器，完整保护包含多层 `div` 的自定义 Gutenberg 区块，不重排或重写其他 HTML。
- 配置选择器同时作用于翻译前保护和 AI 质检排除；故意保留的中文区块不会发送给模型，也不会触发中文残留拦截。
- Agent API 的 `translation_rules` 新增 `html_exclusions`，外部 Agent 可读取相同的排除规则。
- 新增 HTML 选择器与日语 QA 回归测试；插件入口、核心类和 README 版本统一更新为 `0.8.17.20`。

### v0.8.17.19

- 增强 `--trace`：正文片段被判定为原样返回时，输出字段键、源文摘要和模型返回摘要。
- 任务错误信息同步附带最多 3 个失败片段的源文/返回摘要，便于区分“不翻译区块”和模型漏翻。
- 本版本仅增强诊断信息，不改变 0.8.17.18 的翻译、重试、质检和发布判定逻辑。
- 插件入口、核心类和 README 版本统一更新为 `0.8.17.19`。

### v0.8.17.18

- 修复日语目标站完全跳过中文残留质检的问题。日语正常使用汉字，因此不再粗暴统计全部汉字，而是对比源文与译文，拦截仍原样保留的中文长片段。
- “AI 自检字数拦截 = 0”现在对日语同样生效：不允许检测到未翻译的源中文片段；普通日语汉字不会被误判。
- 正文批量翻译新增“原文回显”识别：模型即使返回全部 JSON 键，只要某个较长中文字段仍与源文相同，也会进入分组恢复；两轮后仍未翻译则整次任务失败，不再发布中日文混排内容。
- 新增明确的不翻译区块标记：`<!-- wpmu-ml:no-translate:start --> ... <!-- wpmu-ml:no-translate:end -->`。标记区块会在翻译阶段整体保护，并从 QA 中文残留统计中排除。
- 同时兼容简单元素上的 `translate="no"`、`data-no-translation`、`.notranslate`、`.no-translate` 和 `.wpmu-ml-no-translate` 标记。
- 新增日语 QA 与原文回显恢复回归测试。
- 插件入口、核心类和 README 版本统一更新为 `0.8.17.18`。


### v0.8.17.17

- 修复正文主批量请求返回“合法 JSON 但缺少大量字段”时静默保留中文原文的问题。
- 新增单批片段数量上限，默认 30 个；字符上限和片段数量上限同时生效，避免一次提交 200 多个 JSON 字段。
- 缺失字段改为按最多 15 个一组进行两轮批量恢复，不再逐片段请求；仍缺字段时直接报错并停止保存，防止生成中外文混排文章。
- `--trace` 输出新增返回键数、期望键数、缺失键数和 finish_reason，可直接判断模型是否只返回了部分字段。
- 保留 v0.8.17.16 的性能修复：残留中文补翻默认关闭，且不在 Gutenberg/ACF 嵌套内容中递归执行。
- 插件入口、核心类和 README 版本统一更新为 `0.8.17.17`。


### v0.8.17.16

- 修复正文残留中文补翻导致的 API 请求爆炸：删除逐短片段串行补翻，残留补翻最多只发送一批请求。
- 新增“正文残留中文二次补翻”开关并默认关闭，使默认正文翻译流程恢复为一次主批量翻译；残留中文继续由现有 QA / 人工复核处理。
- Gutenberg Block、ACF 和 postmeta 中的嵌套 HTML 翻译不再递归触发正文残留补翻，避免一篇文章在主正文开始前产生几十次小请求。
- `wp wpmu-ml translate-one` 新增 `--trace` 参数，可实时显示每次 API 请求的阶段、字符数、字段数、耗时、HTTP 状态及 JSON 状态。
- 插件入口、核心类和 README 版本统一更新为 `0.8.17.16`。


### v0.8.17.12

- 删除“语言站点”中的“AI 本地化说明”列；语言站点只保留可选的“AI 翻译标签”，填写后覆盖分站 Locale，留空则自动跟随分站 Locale。
- 删除 `translation_instructions` 在新安装数据库、保存流程、语言上下文、OpenAI 提示词和 Agent API 中的读取与输出；旧数据库中遗留字段即使存在也不再生效。
- 将翻译质量目标统一改为“母语化翻译”：自然、可信、符合当地表达习惯，像目标语言母语技术作者原创，避免中文语序、逐字直译、源语言干扰和机器翻译腔。
- 母语化原则升级为内置规则，由 OpenAI 兼容系统提示词自动执行，并通过 `/agent/rules` 的 `built_in_rules` 与每个 Agent payload 的 `translation_rules` 同步提供。
- Agent rules API 版本更新为 `1.1`，Agent payload API 更新为 `1.3`；`rules_hash` 现在也包含内置母语化规则。
- `AI 翻译规则 / Skill` 继续只维护全站额外规则；术语库继续维护固定译法，不再承担逐语言自然度说明。
- 插件入口、核心类和 README 版本统一更新为 `0.8.17.12`。

### v0.8.17.11

- “翻译引擎”页在“人工翻译”和“高级说明”之间新增“翻译规则”子选项卡。
- 将 `AI 翻译规则 / Skill`、`术语库`、`排除自定义字段` 从 OpenAI 兼容子选项卡移入统一规则中心；原 network option 键保持不变，升级后无需迁移数据。
- 新增鉴权接口 `GET /wp-json/wpmu-ml/v1/agent/rules`，可按 `target_lang` 或 `target_blog_id` 读取全站 Skill、目标语言说明、原始/有效术语和排除字段。
- Agent payload API 当时升级为 `1.2`，每个任务新增 `translation_rules` 和 `rules_hash`，外部 Agent 无需再维护第二份规则与术语库。
- 排除自定义字段仍同时作用于 OpenAI 字段提取和 Agent payload，并通过 Agent API 返回包含内置默认项的有效 pattern 列表。
- 当时界面采用“网站本地化（website localization）”术语；v0.8.17.12 起改为更直观的“母语化翻译”质量描述。
- 插件入口、核心类和 README 版本统一更新为 `0.8.17.11`。

### v0.8.17.10

- 新增每个语言站独立的 `translation_locale` / “AI 翻译标签”，使用 BCP 47 写法，例如 `es-419`、`pt-BR`、`en-US`；留空时才回退跟随 WordPress Locale。
- 新增 `translation_language_name` / “AI 语言名称（自动）”，根据有效 AI 翻译标签通过 PHP Intl/CLDR 自动解析。
- 当时新增 `translation_instructions` / “AI 本地化说明”；该字段已在 v0.8.17.12 从界面和翻译链路移除。
- 系统提示词改为以 AI 翻译标签为内容本地化权威；WordPress Locale 仅负责后台/语言包，hreflang 仅负责 SEO，不再覆盖明确的 AI 翻译目标。
- AI 翻译规则 / Skill 继续作为全站通用规则实际注入 OpenAI 兼容提示词；v0.8.17.12 起不再注入每语言自由文本说明。
- 术语库匹配新增 AI 翻译标签和 AI 语言名称，因此可使用 `es-419` 等目标变体筛选固定译法。
- 当时 `dbDelta` 会自动增加相关字段；v0.8.17.12 起新安装不再创建 `translation_instructions`。
- 插件入口、核心类和 README 版本统一更新为 `0.8.17.10`。

### v0.8.17.9

- “语言站点”中的 Locale 改为只读自动值：每次打开页面、保存设置和版本升级同步时，均从对应分站“设置 → 常规 → 站点语言”读取。
- 新增数据库字段与后台列 `language_name` / “语言名称（自动）”，根据 Locale 使用 PHP Intl/CLDR 或 WordPress 官方语言目录自动解析；无法解析时安全回退显示 Locale。
- OpenAI 兼容翻译在每个任务执行时都会再次读取源站和目标站的实时 WordPress 站点语言，并同时传入语言名称、Locale、hreflang、语言标识与主语言代码；即使后台语言刚被修改，也不会继续使用旧 Locale。
- 术语库语言匹配删除 `English / Russian / Portuguese ...` 等插件内置名称映射，改为使用分站自动取得的 `language_name`，新增语言不需要改 PHP。
- `dbDelta` 会自动为旧安装增加 `language_name` 字段；升级后打开“语言站点”页面即可完成各分站语言信息刷新。
- 插件入口、核心类和 README 版本统一更新为 `0.8.17.9`。

### v0.8.17.8

- 复查并移除 AI 翻译路径中最后一处按语言别名硬编码的默认映射。
- `lang_slug`、`Locale`、`hreflang` 优先从插件“语言站点”表读取；Locale 为空时再读取目标 WordPress 站点的 `get_locale()` / `WPLANG`。
- 新站初始化改为通用推断：URL 路径只生成语言标识/可能的 hreflang，不再把 `en`、`pt`、`es` 等路径自动绑定到某个国家地区。
- 仍保留通用的 Locale/BCP 47 格式规范化，以及 `zh_CN → zh-Hans`、`zh_TW → zh-Hant` 等中文脚本标签转换；这些是标签标准转换，不是翻译语言列表。
- 插件入口、核心类和 README 版本统一更新为 `0.8.17.8`。

### v0.8.17.7

- OpenAI 兼容翻译的目标语言说明改为通用 Locale 驱动，不再使用写死的 `en / es / pt / ...` 目标语言映射。
- 每个任务会从语言站点表读取源站和目标站的 `lang_slug`、`Locale`、`hreflang`，并把完整语言上下文传给模型。
- `Locale` 作为地区化表达的权威配置，可正确区分 `en_US / en_GB`、`pt_PT / pt_BR`、`es_ES / es_MX` 等变体。
- AI 提示词明确为“受约束的网站本地化”：允许调整地区化拼写、词汇、标点、UI 和 SEO 表达，但不得改变原意、事实、品牌、数字、URL、代码和 WordPress 结构。
- 术语库语言匹配改为通用逻辑，支持语言标识、Locale、hreflang、主语言代码及 `all / * / any`；新增语言不再需要修改 PHP 映射。
- 本地 QA 的中文残留检查改为依据源/目标语言上下文判断，不再维护固定的拉丁语言列表。
- 修复 OpenAI/NewAPI 请求重试与 HTTP 错误信息中的历史乱码，后台日志现在会正常显示“请求失败”“接口返回 HTTP”“无可用渠道”等中文提示。
- 后台“语言站点”和“OpenAI 兼容”说明同步更新，明确新增语言只需正确填写 `lang_slug / Locale / hreflang`。
- 插件入口、核心类和 README 当时统一更新为 `0.8.17.7`。
- 变更验证：`php -l` 已通过插件入口、核心类、OpenAI Helper、Agent 与 CLI 等全部 PHP 文件。


### v0.8.17.6

- 高速总控脚本 `wp-content/shell/wpmu_ml_backfill_all_fast.sh` 优化 NewAPI 探测配置读取。
- 脚本启动时一次性读取并缓存 `openai_api_base`、`openai_api_key`、`openai_model`，后续 API 探测直接使用缓存变量。
- 避免 retry 等待期间反复 `wp eval` 读取插件配置，降低主题/插件加载变更导致探测误判“配置不完整”的概率。
- 保持 `API_CHECK_EACH_TASK=0` 默认行为不变；仍只在每轮启动前和 retry 前探测，除非显式开启每篇前探测。
- 本次只修改配套 shell 脚本和 README 版本说明，未改插件 PHP 核心逻辑。


### v0.8.17.5

- 高速总控脚本 `wp-content/shell/wpmu_ml_backfill_all_fast.sh` 默认关闭每篇文章翻译前的 API ping。
- 目的：让 `MAX_PARALLEL` 更接近真实文章级翻译 worker 并发，便于压测和提高 NewAPI 上游实际并发。
- 保留每轮启动前 API 探测，API 异常时每 `API_CHECK_INTERVAL` 秒重试，恢复后再启动整轮。
- 保留 retry 前 API 探测，避免上游异常时直接进入失败重试。
- 新增可选参数：`API_CHECK_EACH_TASK=1`，需要保守模式时才启用每篇文章前检测；默认 `0`。
- 本次只修改配套 shell 脚本和 README 版本说明，未改插件 PHP 核心逻辑。


### v0.8.17.4

- 高速总控脚本 `wp-content/shell/wpmu_ml_backfill_all_fast.sh` 将 NewAPI 健康探测移动到每个翻译 worker 内部。
- 每篇文章执行 `wp wpmu-ml translate-one` 前都会先检测 API；如果 API 异常，该 worker 每 `API_CHECK_INTERVAL` 秒重新检测一次，默认 5 秒，恢复后再翻译该篇。
- 保留每轮启动前的 API 探测，避免整轮在 API 异常时直接起批。
- 修正并导出探测相关函数，确保 `xargs` 子 shell 中也能调用 `wait_for_api_ready`。
- 本次只修改配套 shell 脚本和 README 版本说明，未改插件 PHP 核心逻辑。


### v0.8.17.3

- 高速总控脚本 `wp-content/shell/wpmu_ml_backfill_all_fast.sh` 新增 NewAPI 健康探测。
- 每一轮启动前会读取插件中的 `openai_api_base`、`openai_api_key`、`openai_model`，向 `/chat/completions` 发送一个极小的 `ping` 测试请求。
- 如果 API 探测失败，脚本不会启动大批翻译进程，而是每 `API_CHECK_INTERVAL` 秒重新探测一次；默认 5 秒。
- 新增运行参数：`API_CHECK_INTERVAL`，默认 `5`；`API_CHECK_TIMEOUT`，默认 `15`。
- API Key 只用于本机 curl 探测，不写入日志。日志只记录 HTTP 状态和截断后的错误体，便于判断 NewAPI/上游是否恢复。
- 本次只修改配套 shell 脚本和 README 版本说明，未改插件 PHP 核心逻辑。


### v0.8.17.2

- 修复高速总控脚本 `wp-content/shell/wpmu_ml_backfill_all_fast.sh` 的 retry 阶段参数拆分问题。
- v0.8.17.1 第一轮正常执行成功，但 `failed.tsv` 实际使用空格分隔，retry 阶段仍按 tab 分隔解析，导致重试命令缺少 `--post_id/--lang`。
- retry 阶段已改为与 run 阶段一致的空格分隔解析：`lang="${line%% *}"`、`post_id="${line#* }"`。
- 本次只修改配套 shell 脚本和 README 版本说明，未改插件 PHP 核心逻辑。


### v0.8.17.1

- 维护约定更新：以后每次修改插件核心或配套 shell 脚本，都必须同步递增小版本号，例如 `0.8.17.x`，并在 README/脚本头部记录本次更新说明。
- OpenAI/NewAPI 请求层增加短暂故障自动重试：对 HTTP 429、5xx、NewAPI 上游 `model_not_found`、`无可用渠道`、`do_request_failed`、`upstream error`、连接超时/重置等临时错误，最多自动重试 6 次。
- 请求重试支持 `Retry-After`，否则按约 2/5/10/20/35/60 秒指数退避并加入少量随机抖动，避免 NewAPI 上游短暂断开时整篇文章直接失败。
- 新增高速总控脚本：`wp-content/shell/wpmu_ml_backfill_all_fast.sh`，用于多语言统一回填。它使用全局并发池、失败冷却和低并发重试，避免多个语言脚本各自 20 并发导致 API/数据库被瞬间打爆。
- 高速总控脚本当前版本同为 `0.8.17.1`，日志会输出脚本版本号，便于后续排查。
- 变更验证：`php -l includes/core/class-wpmu-ml-core.php` 通过；单篇英文 `knowledge_post 12358686 -> en` 验证成功，状态 `machine_done_published`。


### v0.8.17

- 增强 OpenAI 兼容翻译的 JSON 输出提示，明确要求只返回同键名 JSON 对象。
- 修复正文/HTML 片段翻译中，单个片段偶发返回纯文本而不是 JSON 时被判失败的问题。
- 对单字段翻译增加安全纯文本容错：如果只有一个待翻译键，模型返回正常译文纯文本时自动按该键接收。
- 保留 v0.8.15 的排除自定义字段功能，继续默认跳过 `_ai_generated_seo` 等旧 SEO 缓存字段。

### v0.8.15

- OpenAI 兼容设置新增“排除自定义字段”列表：每行一个 `meta_key`，支持 `*` 通配符。
- 默认排除旧 AI SEO/统计/处理标记字段，例如 `_ai_generated_seo`、`_ai_generated_summary`、`_ai_seo_auto_generated`、`_deepseek_slug_*`、`views`、`post_views`。
- Agent payload 复用同一排除列表，避免外部 Agent 领取旧缓存字段。
- 对单个普通文本字段的 OpenAI 返回做容错：如果模型没有按 `{"text": ...}` 返回，而是返回纯译文文本，插件会按纯译文接收，减少普通摘要字段误报“不是有效 JSON”。

### v0.8.14

- 翻译队列设置页新增“并发与领取限制”配置。
- 明确区分“每批处理数量”和“最大并发数”：每批处理数量控制一次队列运行扫描/处理多少任务，并发数控制同一时间正在处理或领取的任务数。
- 新增 OpenAI 兼容最大并发、OpenCC 最大并发、Agent API 最大领取数三个设置项。
- 队列处理器在锁定任务前会检查对应引擎的活跃任务数，避免多个 WP-Cron / WP-CLI 进程同时抢占过多 OpenAI 兼容或 OpenCC 资源。
- Agent API 的 next / claim 会受到“Agent API 最大领取数”限制；达到限制时不会继续领取新任务。

### v0.8.13

- 增强文章关联重建逻辑，解决源站和目标分站文章 ID 不一致时关系表丢失后难以恢复的问题。
- 新建或更新目标文章时自动写入 `_wpmu_ml_source_blog_id`、`_wpmu_ml_source_post_id`、`_wpmu_ml_source_lang`、`_wpmu_ml_target_lang` 标记，作为关系表之外的恢复锚点。
- 重建文章关联时按“源站标记 meta → 相同 slug + post_type → 相同 ID”的顺序匹配目标文章。ID 不同但 slug 相同，或目标文章带有源站标记时，也能自动重建关系。
- 重建 taxonomy 关联时优先按 term slug + taxonomy 匹配，再按 term_id 兜底，降低不同 ID 导入或迁移后的关联丢失风险。
- 重建日志会记录 meta、slug、ID 各自匹配的数量，方便排查关系恢复来源。
- 本版本不改变翻译队列、OpenAI 兼容、OpenCC、Agent API 和 Agent Tools API 的翻译行为，只增强关联恢复能力。

### v0.8.12

- Agent Tools API 新增 `/types` 和 `/list` 接口，本地 Agent 可以先查看支持类型，再列出分类、标签、样板、模板等对象，不必每次手动提供 ID。
- `/types` 返回当前源站可操作的 taxonomy 与 post-like 类型，例如 `category`、`post_tag`、`wp_block`、`wp_template`、`wp_template_part`、`wp_navigation`。
- `/list` 支持 `object_type=term`，可分页列出分类、标签、自定义 taxonomy term，并返回 source_id、slug、count、target_id、target_name、has_target。
- `/list` 支持 `object_type=post_like`，可分页列出 `wp_block`、`wp_template`、`wp_template_part`、`wp_navigation` 等对象，并返回 source_id、title、status、modified、target_id、target_title、has_target。
- Agent API 子选项卡的工具接口说明已更新为 health / types / list / read / write。
- Agent Tools API 仍然不创建文章翻译队列任务，不触发 OpenAI 兼容、OpenCC 或 Agent 队列流程；它只是给本地 Agent 一个受控的 WordPress 列表、读取和写回工具面。

### v0.8.11

- 新增 Agent Tools API，与现有 Agent 队列 API 分开，使用独立的 Agent Tools API Key。
- 新增 `/wp-json/wpmu-ml/v1/agent-tools/health`、`/read`、`/write` 三个工具接口。
- 工具接口第一版支持 `object_type=term`，用于读取/写回分类、标签、自定义 taxonomy term 的名称、描述和 slug。
- 工具接口第一版支持 `object_type=post_like`，用于读取/写回样板、可复用区块、模板、模板部件等 post-like 对象的标题、摘要和正文。
- Agent Tools API 不创建文章翻译队列任务，不触发 OpenAI 兼容、OpenCC 或 Agent 队列流程；它只是给本地 Agent 一个受控的 WordPress 读取/写回工具面。
- Agent API 子选项卡新增“Agent 工具接口”区域，区分文章队列 Key 和工具接口 Key。
- README 已同步说明 Agent 队列接口与 Agent Tools 工具接口的边界。

### v0.8.10

- 继续优化“翻译队列”页面，将任务列表进一步拆成：最近任务、待处理、失败 / 需复核、已完成、队列统计、单篇翻译、处理与维护、队列设置。
- “待处理”只显示 pending / needs_update / machine_pending / agent_pending / agent_claimed / agent_payload_sent / manual_waiting 等仍需动作的任务。
- “失败 / 需复核”只显示 failed / agent_failed / review_required，方便集中排查错误。
- “已完成”只显示 machine_done_published / agent_done_published / opencc_done_published / manual_done 等历史完成任务，明确文章发布后任务不会消失，而是保留为历史记录。
- 最近任务和各任务列表的“错误/说明”列保留短文本显示，但鼠标悬停可查看完整 last_error，减少登录服务器查 SQL 的次数。
- 已完成任务不再显示“机器处理”按钮，只保留重新翻译 / 重新入队，降低误操作概率。
- 本版本只优化后台队列 UI 和错误信息查看方式，不改变 OpenAI 兼容、Agent API、OpenCC 的核心翻译逻辑。

### v0.8.9

- 重构“翻译队列”页面 UI，增加二级子选项卡：最近任务、队列统计、单篇翻译、处理与维护、队列设置。
- 默认打开“最近任务”，避免任务多时需要从页面顶部反复向下滚动。
- “队列统计”“单篇翻译”“手动处理队列/释放超时锁”“队列运行参数”分别进入独立面板，减少单页过长和操作混杂。
- 保留原有队列处理逻辑、任务状态、Agent/OpenAI/OpenCC 路由逻辑不变，本版只优化后台操作界面。

### v0.8.8

- 优化“翻译引擎 → 默认与路由”页面 UI。
- 按文章类型设置表格删除重复的“说明”列，改为在表格下方统一说明。
- 按目标语言设置表格中繁体语言的 OpenCC 说明移到表格下方，避免影响表格高度和对齐。
- 将后台显示中的 `OpenAI` 统一改为 `OpenAI 兼容`，强调该引擎支持 OpenAI-compatible Chat Completions 接口。
- 将“高级：目标语言 + 文章类型组合规则”改为更显眼的“精确覆盖规则：目标语言 + 文章类型”，默认展开，并明确其优先级最高。
- 本版本只调整后台 UI 和 README 文案，不改变翻译队列、OpenAI 兼容、OpenCC 或 Agent API 的核心执行逻辑。

### v0.8.7

- 正式接入翻译路由规则 `TranslationRouteResolver`，队列创建时会按规则解析 `engine + model + complete_status`。
- “按文章类型设置翻译方式 / 模型”从 UI 预览改为实际生效，可让不同 post type 使用 OpenAI、Agent API、人工翻译，OpenAI 可指定不同模型。
- 新增“目标语言 + 文章类型组合规则”，优先级最高，可单独设置例如 `ko + knowledge_post = Agent API`、`en + knowledge_post = OpenAI + gpt-5.5`。
- 路由优先级固定为：单篇任务手动指定 > 目标语言 + 文章类型 > 目标语言 > 文章类型 > 默认引擎 > 系统兜底。
- 目标语言规则现在可以选择“继承默认 / 文章类型规则”，避免每个语言都强制覆盖文章类型规则。
- 翻译任务表新增 `model`、`route_reason`、`route_profile` 字段，方便后台和数据库追踪每个任务为什么用了某个引擎/模型。
- OpenAI 引擎支持任务级 `model` 覆盖全局默认模型；Agent、OpenCC、Manual 不使用 model。
- 最近任务列表会显示任务模型和路由来源，便于排查规则是否命中。

### v0.8.6

- 重构“翻译引擎”后台页面，增加二级子选项卡：默认与路由、OpenAI、Agent API、OpenCC、人工翻译、高级说明。
- 将“决定任务用哪个引擎”的默认/目标语言规则，和各引擎自身配置分开，避免 OpenAI、Agent API、OpenCC 设置混在同一长页面。
- 在“默认与路由”中保留当前已生效的默认引擎、默认完成后状态、按目标语言设置翻译方式，并增加“按文章类型设置翻译方式 / 模型”的界面预览。
- Agent API 子选项卡只保留 WordPress 工具接口、API Key 和调用示例，不放本地 Agent 的 prompt、术语库或模型策略。
- OpenAI 子选项卡继续保留 OpenAI 兼容 API 参数、默认模型、字段翻译开关、AI 规则和本地质检配置。

### v0.8.5

- 修正 Agent 任务完成后被目标文章发布钩子覆盖为 `manual_done` 的问题。
- 目标文章发布后，任务完成状态会按引擎区分：`agent` 使用 `agent_done_published`，`openai` 使用 `machine_done_published`，OpenCC 使用 `opencc_done_published`，`manual` 才使用 `manual_done`。
- 最近任务操作按钮按引擎区分，Agent 任务不再显示“人工完成”和“机器处理”，改为“重新入队”。
- 明确 Agent API 的定位：插件只提供 WordPress 工具接口与字段写回；翻译策略、术语和模型调用由本地 Agent 自己负责。

### v0.8.4

- 继续接入 Agent 翻译链路，保持 `engine = agent` 走统一翻译队列，不让 Agent 直接进入目标分站改内容。
- Agent 引擎拆分为 `class-wpmu-ml-agent.php`、`class-wpmu-ml-agent-payload.php`、`class-wpmu-ml-agent-result-applier.php`、`class-wpmu-ml-agent-validator.php`。
- 新增 Agent 健康检查接口：`GET /wp-json/wpmu-ml/v1/agent/health`。
- 新增 Agent 锁续期与释放接口：`POST /agent/heartbeat`、`POST /agent/release`，并加入锁超时自动回收。
- Agent payload 从仅标题/摘要/正文/SEO meta 扩展为可包含现有 OpenAI 元字段翻译链路允许的 ACF/自定义字段文本、序列化数组文本、HTML 片段和 JSON 字符串。
- Agent result 写回时支持目标文章标题、摘要、正文、SEO meta 与可翻译 meta 字段写回。
- Agent result 写回前加入服务端结构校验：必填字段非空、Gutenberg block 注释、shortcode、URL、图片、pre/code 数量不得减少，并拦截 `u003c/u003e/u0022` 等转义污染。
- `source_hash` 不匹配时不再直接 failed，而是拒绝旧译文写回并重新进入 `agent_pending`，等待 Agent 重新领取最新源内容。
- README 同步更新 Agent API 的真实接口范围和调用流程。

### v0.8.3

- 进入结构化拆分阶段，插件入口改为加载 `includes/bootstrap.php`。
- 新增目录：`includes/core/`、`includes/cli/`、`includes/engines/openai/`、`includes/engines/agent/`、`includes/admin/`、`includes/database/`、`includes/sync/`、`includes/queue/`、`includes/translation/`、`includes/rest/`、`includes/security/`、`includes/support/`。
- `class-wpmu-ml-core.php` 移入 `includes/core/`，旧路径保留兼容加载壳。
- OpenAI 辅助类移入 `includes/engines/openai/`，旧路径保留兼容加载壳。
- Agent 引擎移入 `includes/engines/agent/`，旧路径保留兼容加载壳。
- WP-CLI 注册逻辑拆到 `includes/cli/class-wpmu-ml-cli.php`，减少 core 文件继续膨胀。
- 明确命名约定：WordPress 插件文件名和目录名统一使用小写连字符，PHP 类名继续使用 `WPMU_ML_*` 前缀。
- 本版本不新增 Agent 翻译规则，不改变 OpenAI 已有完整翻译逻辑；Agent 后续应复用 OpenAI 现有字段抽取和写回能力。
- README 同步更新目录结构、命名规范和后续拆分方向。

### v0.8.2

- Agent API Key 改为由插件后台生成，不再要求手动填写 Token。
- 后台提供“生成 Agent API Key / 重置 Key / 禁用 Agent API”按钮。
- 本地 Agent 使用后台生成的 Key 调用接口：`Authorization: Bearer API_KEY`。
- 这个 Key 只用于访问 WordPress 工具接口，不是模型 API Key，也不是 Agent 提示词配置。

### v0.8.1

- 修正 v0.8.0 的命名：OpenAI 兼容 辅助类文件从 `includes/class-wpmu-ml-agent.php` 改为 `includes/class-wpmu-ml-openai-helper.php`，类名改为 `WPMU_ML_OpenAI_Helper`。
- `agent` 翻译引擎文件改为 `includes/class-wpmu-ml-agent.php`，类名为 `WPMU_ML_Agent`，不再使用 `class-wpmu-ml-engine-agent.php` 文件名。
- Agent API 设置页改为工具接口配置，只保留 Agent 接口访问 Key 和接口说明；移除 Agent 翻译规则、术语库、本地质检、payload 上限等不应由 WordPress 配置的项目。
- Agent payload 不再携带后台填写的 `site_rules` / `terms`，只提供 WordPress 任务信息、字段、source_hash 和字段回传契约。
- Agent result 只做任务锁、source_hash、field_id 等写回安全校验；具体翻译策略完全由本地 Agent 自己决定。

### v0.8.0

- 版本线从 `0.8.x` 开始。
- 新增正式内置翻译引擎 `agent`，后台显示为 `Agent API`。
- `agent` 引擎继续使用现有翻译队列表，不另建任务系统；源站更新文章后，如果目标语言选择 Agent API，会生成 `engine = agent` 的队列任务。
- 新增 Agent REST API，负责注册 Agent 引擎、任务领取、payload 输出、结果回传和失败回传。
- OpenAI 兼容 翻译路径的提示词与 QA 辅助类已经改名为 `includes/class-wpmu-ml-openai-helper.php`；OpenAI 内部翻译模式值从旧的 `agent` / `agent_qa` 迁移为 `rules` / `rules_qa`，避免和正式 `agent` 引擎混淆。
- Agent API 设置只保留接口访问 Key；本地 Agent 已在外部配置，WordPress 不再提供 Agent 翻译规则、术语库和模型相关设置。
- 新增 REST API：`/wp-json/wpmu-ml/v1/agent/next`、`/claim`、`/payload`、`/result`、`/fail`。
- `agent` 任务状态新增：`agent_pending`、`agent_claimed`、`agent_payload_sent`、`agent_translated`、`agent_done_published`、`agent_failed`。
- `agent` 不再自动映射为 `openai`；后续外部 Agent 只需要按接口领取任务和提交结构化译文。
- 为维护方便，Agent 引擎主要逻辑拆到新类中，避免继续把外部 Agent API 逻辑堆进 `class-wpmu-ml-core.php`。

### v0.7.21

- 修复 OpenCC 代码块字符串片段转换时前后空格被 `trim()` 掉的问题。
- OpenCC 片段回填不再复用 AI 译文清理逻辑，而是使用 OpenCC 专用清理函数，保留源片段的前导/尾随空格。
- 重点保持代码字符串输出格式，例如 `"处理结果: "` 应转换为 `"處理結果: "`，`" | 计算结果: "` 应转换为 `" | 計算結果: "`，`" 毫秒，内存使用: "` 应转换为 `" 毫秒，記憶體使用: "`。
- 继续保留 v0.7.20 的 `wp_slash()` 写入保护，避免 `\n`、`\t`、`\r`、`\0`、`\x0B` 被保存链路吃掉。
- README 与插件版本同步更新。

### v0.7.20

- 修复 OpenCC 写入目标文章时反斜杠转义符被 WordPress 保存链路吃掉的问题。
- OpenCC 转换后的 `post_title`、`post_excerpt`、`post_content` 写入 `wp_update_post()` 前统一 `wp_slash()`，与 OpenAI 写入路径保持一致。
- 重点保护代码块里的 `\n`、`\t`、`\r`、`\0`、`\x0B`，避免被保存成 `n`、`t`、`r`、`0`、`x0B`。
- PHPDoc 闭合行、代码块逐行片段级 OpenCC 转换、slug 强锁等 v0.7.19 逻辑保持不变。
- README 与插件版本同步更新。

### v0.7.19

- 修复 OpenCC 繁体转换代码块安全问题：OpenCC 不再整段转换 `<pre>` 代码块，而是和 OpenAI 路径一样按真实换行逐行处理，只转换中文注释、字符串自然语言片段和 HTML 示例文本。
- 新增 OpenCC 代码块转义符保护：在转换前保护 `\n`、`\t`、`\r`、`\0`、`\x0B`、`\\` 等反斜杠转义序列，转换后原样恢复，避免变成普通 `n/t/r/x0B`。
- 修复 OpenCC 路径下 PHPDoc / JSDoc 块注释闭合符被合并到上一行的问题，`*/` 应继续保留在原始闭合行。
- OpenCC 字符串片段不再调用 OpenAI 字符串转义函数，避免对简繁转换结果做二次反斜杠处理。
- README 与插件版本同步更新。

### v0.7.18

- 后台选项卡名称从“OpenAI 翻译”调整为“翻译引擎”，避免和后续真正多步骤 AI Agent 功能混淆。
- 默认翻译引擎从 `manual` 调整为 `openai`，后台默认下拉显示“人工翻译 / OpenAI”；OpenCC 仍只在繁体目标语言的按语言下拉里显示。
- 任务引擎 key 从开发期的 `agent` 收回为 `openai`；开发测试期建议清空旧队列后重新生成任务。
- 后台文案统一：不再把当前功能称为 Agent 翻译，而称为 OpenAI 兼容+模型翻译、AI 翻译规则、AI 质检、翻译模式。
- 代码块翻译方向调整为开源 i18n 工具常见做法：代码块以源站代码为底板，抽取中文注释、字符串 value、HTML 示例文本等自然语言片段，AI 只翻译片段，插件原位回填，避免 AI 重写整段代码。
- 继续加强代码片段安全：注释片段译文会剥离模型误返回的 `*/` 等注释结束符，避免 PHPDoc 闭合行被合并。
- README 与插件版本同步更新。

### v0.7.17

- 删除“代码块整块回滚为源文”的策略，改为“片段级回滚”：翻译成功的注释、字符串和 HTML 示例文本会继续原位替换，某个片段或某个修复步骤失败时只放弃该片段或该修复，不再把整个大代码块恢复成中文。
- 最终拼接换行修复只在不改变目标代码块换行签名时应用；如果修复有风险，则保留已经翻译好的片段级代码块并交给 QA 判断。
- 保持 slug 强锁、PHPDoc 闭合行保护、字符串转义符保护、字段感知翻译和代码片段级翻译策略不变。

### v0.7.16

- 继续强化代码块行结构保护。
- 新增最终代码块回写校正：正文翻译完成并恢复 protected 区域后，会再次按源站和目标站 `<pre>` 代码块逐一比对，对明显的 `.` / `+` / `,` 后误换行进行合并。
- 兼容 syntax-highlight span 包装后的代码行，判断拼接符时会先剥离高亮标签再识别，避免 `$longLine1` 这类长行仍在 `.` 后被拆开。
- 继续保持 slug 强锁、PHPDoc 闭合行保护、字符串转义符保护和代码片段级翻译策略不变。
- README 与插件版本同步更新。

### v0.7.15

- 继续强化代码块行结构保护。
- 新增拼接操作符换行修复：当 AI 或中间流程把 `$longLine1 = ... .` 后面的表达式意外拆到下一行时，会在写回前合并回原始单行，避免破坏代码滚动条演示和潜在代码运行。
- 修复不再只依赖变量名识别，即使代码内容经过 HTML 转义或语法高亮包装，也会尝试识别 `.\n(`、`.\nfunction_call(...)` 等明显的误拆模式。
- README 与插件版本同步更新。

### v0.7.14

- 调整正文翻译顺序：先保护并处理 `<pre>` / `<code>` 代码区域，再翻译 Gutenberg / ACF block JSON 注释，避免 block comment 中的 code/content 字段绕过代码块逐行硬锁。
- `wp:code` 和 `wp:preformatted` 的 block 注释不再参与 OpenAI 翻译，代码显示内容只走代码块片段翻译通道。
- 新增单行赋值意外换行修复：如果源代码中 `$longLine1 = ...;` 这类赋值本来是单行，目标代码中被拆到下一行，会在写回前尝试合并回同一行。
- 继续保持 slug 数据库级强锁、代码片段原位替换、转义符保护和 QA 检查。
- README 已同步更新，本版本建议继续只测英文单篇，重点确认 `$longLine1` 是否仍被拆行。

### v0.7.13

- 代码块翻译改为逐行硬锁模式：先按真实换行切分代码块，只在每个原始行内提取中文片段，翻译后再原位替换回同一行。
- AI 不再有机会返回整段代码块或整行代码；只允许返回注释正文、字符串自然语言片段、HTML 示例文本等短片段译文。
- 强制保持原始换行位置、缩进、代码拼接符、注释闭合符、字符串引号、数组/对象 key、转义符不变。
- 重点修复长行 `$longLine1` / `$longLine2` 被模型拆成多行，以及块注释 `*/` 被合并到上一行的问题。
- README 已同步更新，本版本建议继续只测英文单篇，确认代码块行结构完全稳定后再测第二语言。

### v0.7.12

- 继续收紧代码块片段翻译：字符串 value 不再整段交给 AI，而是只提取其中的中文自然语言片段并原位替换。
- 代码字符串中的 `\n`、`\t`、`\r`、`\0`、`\x0B` 等反斜杠转义序列默认保持原样，避免翻译后改变代码运行结果。
- 单行代码片段译文强制压成单行，清理模型可能返回的换行、`<br>`、`&#10;`、`&NewLine;` 等，降低长行代码被拆开的风险。
- AI QA 新增代码块换行结构检查：如果翻译后 `<pre>` 代码块行数变化，会标记为需复核。
- 繁体 OpenCC 也改为代码块片段级转换：只转换代码注释、字符串自然语言和 HTML 示例文本，不再整块跳过，也不直接转换整段代码。
- 本版本建议先只用单一目标语言测试代码块，不建议一次跑全语言，确认长行和转义符安全后再扩大测试范围。

### v0.7.10

- 继续加强代码块片段级翻译，不再依赖 AI 自由理解整段代码。
- 新增实体 HTML 示例处理：支持翻译代码块中的 `&lt;!-- 中文注释 --&gt;`、`&lt;span&gt;内容&lt;/span&gt;` 等片段。
- 代码片段翻译后新增归一化：原文单行片段译文强制保持单行，避免长行代码被模型拆行，影响代码滚动条演示或代码结构。
- 自动剥离模型误返回的注释符号、引号或 Markdown 代码围栏，只把译文正文原位写回原代码。
- 后台说明同步强调：AI 翻译规则会注入 prompt，但代码块能否完整翻译主要由插件的片段提取器决定，规则不能替代代码解析。

### v0.7.9

- 重要修复：slug 保护从 `wp_update_post` 参数保护升级为数据库级强制锁定。AI / OpenCC / 自动同步写入目标文章后，会再次直接更新目标站 `posts.post_name` 为源站 slug，并清理文章缓存。
- 修复部分环境中 WordPress、主题或 SEO 插件根据译文标题重新生成目标文章 slug 的问题，例如 `code-highlightjs` 被改成英文长 slug。
- `repair-slugs` 命令改为直接写入数据库字段，不再通过 `wp_update_post`，避免修复过程中再次触发标题生成 slug。
- 新增单篇 slug 修复命令：`wp wpmu-ml repair-one-slug --post_id=12346401 --lang=en --allow-root --skip-themes`。
- 自动同步创建/更新目标文章后增加 slug 二次锁定；OpenAI 翻译在正文写入、字段翻译后、任务完成前多次锁定 slug。
- README 同步更新。

### v0.7.8

- 重要修复：文章 slug 设为强保护字段，目标语言文章始终使用源站 `post_name`，AI / OpenCC / SEO 字段翻译都不能生成或保留译文 slug。
- 自动同步页面移除“别名 slug”可关闭选项，slug 同步不再是可选项。
- OpenAI 翻译写入目标文章时强制回写源站 slug；OpenCC 简繁转换写入目标文章时也强制回写源站 slug。
- 自动同步创建/更新目标草稿时强制使用源站 slug，避免 WordPress 根据译文标题自动生成新 URL。
- 新增 WP-CLI 修复命令：`wp wpmu-ml repair-slugs --lang=en --dry-run --allow-root --skip-themes`，用于检查或修复已经被错误翻译的目标文章 slug。
- AI QA 的代码块中文残留检查收紧：拉丁语系目标语言的 `<pre>` 代码块中只要仍有中文注释或字符串，就提示复核。
- README 同步更新。

### v0.7.7

- 继续增强代码块翻译，改为更细的“代码片段级翻译”：先提取代码块里的中文注释、字符串 value、SQL `--` 注释、语法高亮 `<span>` 文本节点，再原位替换回代码。
- 增加 SQL / shell 风格行注释识别：`-- 创建用户表`、`# 使用Flask创建Web服务`、`// Express.js 简单API示例` 等会作为代码注释片段单独翻译。
- 增加语法高亮 HTML 兜底处理：如果代码块已经被 highlight.js / Prism 等插件包裹成 `<span class=...>`，仍会尝试翻译标签之间的中文自然语言文本。
- 翻译时尽量保持原始代码行结构、缩进、引号、转义、语法符号和 HTML 高亮标签，不把整段代码交给 AI 自由重写。
- AI QA 增加代码块中文残留检查：英文、法文、德文、西文等拉丁语系目标语言中，如果 `<pre>` 代码块仍有较多中文字符，会标记为需要复核。
- README 同步更新。

### v0.7.6

- 优先修复代码翻译策略：`<pre><code>` 代码块不再只翻译注释，而是会在保护代码结构的前提下翻译中文注释、字符串 value、数组/对象 value 等人类可读文字。
- 代码块中会保持变量名、函数名、类名、数组/对象 key、命令、URL、路径和语法不变，避免破坏 PHP / JS / CSS / shell 示例。
- 默认代码块策略调整为 `smart_text`，旧的隐藏设置不再影响默认行为；只有显式 `protect` 才会完全保护代码块。
- AI 系统提示词同步更新 Code skill，明确“代码结构不动，代码中的自然语言要翻译”。
- 正文翻译任务说明同步更新，不再要求 code snippets 完全原样保留，避免与中文残留 QA 互相冲突。
- 后台翻译引擎页的代码说明同步更新。
- README 同步更新。

### v0.7.5

- 新增字段感知翻译：文章标题、摘要、正文 H1-H4、SEO title、SEO description、SEO keywords 会按字段类型给 AI 不同任务说明。
- 正文翻译时会识别 `<h1>`、`<h2>`、`<h3>`、`<h4>` 内的人类可读文本，单独按内容标题本地化，不再完全混入普通正文片段。
- Rank Math / Yoast / AIOSEO 常见 SEO 字段增加字段分类：SEO title 保持搜索意图并本地化，SEO description 写成自然搜索摘要，SEO keywords 转成目标语言关键词短语。
- AI 系统提示词增加 Field-aware localization skill，明确“不是逐字硬翻，而是在不改变事实的前提下做本地化表达”。
- 后台翻译引擎页新增“字段感知翻译”说明。
- README 同步更新。

### v0.7.4

- 开发测试期清理旧任务兼容分支：不再把旧 `openai` 自动映射为 `agent`，也不再把旧 `opencc` 自动映射为 `opencc_s2twp`。
- 后台翻译引擎提示文案更新：明确建议升级测试版前清空旧队列并重新生成任务。
- 术语库解析进一步收敛：只支持 `原词 | 语言 | 译法`、Tab 分隔或三段空格分隔，不再兼容旧的 `原词 => 译法`。
- README 同步更新，保持“每次代码更新必须同步更新 README”的规则。

### v0.7.3

- 版本号更新为 0.7.3，并同步更新 README。
- 后台“翻译引擎”选项卡在当时曾改名为“OpenAI 翻译”。
- 默认翻译引擎下拉不再显示 OpenCC，只显示人工翻译、OpenAI，以及未来真实注册的扩展引擎。
- 保留未来引擎扩展接口：通过 `wpmu_ml_registered_translation_engines` 注册后才会在后台显示；通过 `wpmu_ml_process_translation_job` 可接管自定义引擎处理。
- 内部兼容旧任务：`openai` 自动按 `agent` 处理，`opencc` 自动按 `opencc_s2twp` 处理。
- OpenCC 转换配置不再作为全局下拉项显示，改为只在繁体目标语言的“翻译方式”中显示 `s2twp`、`s2tw`、`s2hk`、`s2t`。
- 繁体相关语言识别扩展到 `zh-hant`、`zh-tw`、`zh-hk`、`zh-mo` 等。
- AI 翻译规则支持普通文本或 Markdown 写法。
- 术语库保留并改为新格式：`原词 | 语言 | 译法`；旧格式 `原词 => 译法` 仍兼容为 all。
- 后台不再单独显示代码块策略和行内 code 策略，改为由 AI 翻译规则和内置结构保护策略统一控制。

### v0.7.2

- 版本号更新为 0.7.2，并同步更新 README。
- 增加维护要求：以后每次更新插件源码，必须同步更新 README.md 的版本说明、功能说明和使用注意事项。
- 后台在“工具”选项卡后新增“帮助”选项卡。
- 帮助页新增环境自检：PHP 版本、扩展、关键函数、临时目录、数据表、OpenAI 设置、OpenCC 命令和转换测试。
- “重新翻译”和“人工完成”会清理任务锁字段：`locked_at`、`locked_by`、`process_after`。
- 最近任务里的“机器处理”改为统一调用单任务处理入口，避免手动预加锁后请求中断造成假死锁。
- 后台可选翻译引擎只保留已真实接入的人工翻译、OpenCC 和 OpenAI 兼容 API；DeepL、腾讯云和自定义 API 暂未接入，已隐藏。

### v0.7.1

- 增加 `README.md`，完整记录当前多语言方案和插件方案。
- `translate-one` 完成后输出任务表名、任务状态、翻译模式和任务说明。
- 新增 WP-CLI 诊断命令：`wp wpmu-ml doctor`。
- 新增 WP-CLI 任务查看命令：`wp wpmu-ml job --job_id=xxx`。
- 用于排查任务表、表前缀、OpenAI 设置、QA 结果和任务记录。

### v0.7.0

- 插件目录结构初步重构。
- 新增轻量 OpenAI 翻译模式。
- 新增 AI + 本地质量检查。
- 新增站点规则 / Skill 和术语库。

### v0.6.8

- 新增代码块策略：保护代码本体，仅翻译代码注释。
- 新增行内 code 策略：纯代码保护，含中文的行内 code 翻译中文部分。

---
