## v0.9.8.18: Language unavailable modal dark mode

### 0.9.8.18 核心变化

- 语言切换器“目标语言版本暂未发布”弹窗支持夜间模式颜色。
- 使用 CSS 变量保留白天样式，夜间只切换颜色 token，不改变弹窗结构和 JS 行为。
- 兼容系统 `prefers-color-scheme: dark`，以及常见主题/夜间插件使用的 `dark`、`dark-mode`、`theme-dark`、`is-dark-theme`、`wp-dark-mode-active`、`data-theme="dark"`、`data-bs-theme="dark"` 等规则。
- 实现和测试说明见 [WPMU多语言插件_0.9.8.18_未发布语言弹窗夜间模式报告_20260724.md](reports/WPMU多语言插件_0.9.8.18_未发布语言弹窗夜间模式报告_20260724.md)。

## v0.9.8.17: Agent API Gutenberg data fields

### 0.9.8.17 核心变化

- Agent API payload 会把 Gutenberg / ACF block 注释 JSON 中的人类可读文本拆成独立 `field_scope=gutenberg` 字段。
- 外部 Agent 仍需保持 `post_content` 里的 Gutenberg 注释原样；区块 JSON 内的文字通过独立字段回传。
- `/agent/result` 写回时只修改 JSON value，不修改 URL、字段 key、ACF field key 或区块结构。

## v0.9.8.16: Agent API write-back normalization

### 0.9.8.16 核心变化

- Agent API result 写回后，正文回读校验兼容 WordPress 对 `&` 等实体和 HTML 自闭合标签空格的标准化保存结果。
- Agent API 写回发布状态时，不再让发布状态钩子提前完成同一个任务，任务完成统一由 `/agent/result` 收尾。
- Gutenberg 区块注释、HTML 标签、URL、短代码和占位符的结构保护保持严格。

## v0.9.8.15: Generic FSE relation scope

### 0.9.8.15 核心变化

- 插件关联范围按 WordPress 通用对象模型处理，不绑定某个网站当前已有数据。
- `wp_template`、`wp_template_part`、`wp_navigation` 纳入可翻译文章类型默认范围，使用文章关系表建立跨语言关系。
- 后台内容类型候选列表允许选择这 3 个站点编辑器对象。
- 媒体、用户、ACF 配置、经典菜单项和全局样式继续不作为普通翻译内容处理。

## v0.9.8.14: WPMU language switcher admin label

### 0.9.8.14 核心变化

- 菜单调用显示名称从 `Language Switcher` 改为 `WPMU 语言切换器`。
- “外观 → 菜单”中的菜单项面板名称同步改为 `WPMU 语言切换器`。
- 设置页说明同步改名，降低与其他插件的名称冲突。
- 内部 post type 标识保持不变，兼容已有菜单项。

## v0.9.8.13: Language switcher code example display fix

### 0.9.8.13 核心变化

- 修复“语言切换 → 代码调用”示例代码显示为一行的问题。
- 示例代码改为真正多行 `<pre><code>` 输出。
- 后台代码块补充等宽字体、行高、边框、内边距和横向滚动样式。

## v0.9.8.12: Admin UI wording cleanup and OpenAI subtab style

### 0.9.8.12 核心变化

- OpenAI 兼容页二级选项卡改为与“语言切换”页二级选项卡一致的 UI。
- 清理后台界面中遗留的开发阶段文案。
- 翻译引擎、OpenAI、人工翻译、帮助页和语言切换页的说明改为正式管理员说明。
- 模型示例改为通用 OpenAI 兼容模型示例。
- 移除工具页中已停用的同 ID 猜测补建按钮。

## v0.9.8.11: OpenCC source-language guard

### 0.9.8.11 核心变化

- OpenCC 只用于“简体中文源站 → 繁体中文目标站”的简繁转换。
- 非简体中文源站不会因为目标语言是繁体中文而自动 fallback 到 OpenCC。
- 后台目标语言翻译方式下拉中，只有符合简繁转换场景时才显示 OpenCC 选项。
- OpenCC 环境检测的“按需必需”判断同步收紧，避免公开安装时误报。

## v0.9.8.10: Unpublished language setting cleanup

### 0.9.8.10 核心变化

- 后台只保留一个“未发布语言处理”入口，位置在“语言切换 → 基础设置”。
- “内容类型”页移除旧的“隐藏未发布语言”checkbox，避免配置重复。
- 语言切换器可选择：隐藏未发布语言，或显示语言项并弹窗提示暂未发布。
- hreflang 始终只输出已发布且可索引的目标文章，不跟随语言切换器提示策略。
- 修正 term name 翻译结果写入时被源站 name 覆盖的问题。

## v0.9.8.9: Taxonomy term name/description translation switches

### 0.9.8.9 核心变化

- 新增分类/标签本体翻译开关：可分别控制是否翻译 term `name` 和 `description`。
- 两个开关默认关闭，关闭时完全保持 0.9.8.8 的同步行为，只复制源站 term 文本。
- 开启后，源站 term 新增/编辑同步到目标站时，按目标语言翻译勾选字段；`slug` 仍固定同步源站，不自动翻译。
- OpenAI 兼容引擎复用现有纯文本翻译与语言配置；繁体中文目标语言可走 OpenCC 转换；manual 或不支持的引擎会保留源文并记录跳过日志。
- term 翻译失败不会阻断 term 本体同步，目标站仍保留源文，日志记录 `term_translation_error`。

## v0.9.8.8: Taxonomy term CRUD synchronization

### 0.9.8.8 核心变化

- 新增 taxonomy term 本体同步：源站新增、编辑、删除 `sync_taxonomies` 白名单内的分类、标签和自定义 taxonomy term 时，会同步到启用的目标语言站。
- 新增、编辑 term 时优先使用 `wpmu_ml_term_relations`，关系缺失时只对当前 term 尝试同 slug + taxonomy、同 ID + taxonomy 的目标 term 认领并修复关系，不做历史全量重建。
- hierarchical taxonomy 会先确保父级 term 已同步，目标站子级 parent 使用目标站父级 term ID，不再直接使用源站 ID。
- 源站删除 term 时只按关系表确认的目标 term 删除并清理关系，不按 slug 模糊删除；分站单独删除不会反向影响源站。
- 文章同步分类关系时改为把源站 term ID 映射为目标站 term ID，避免多语言站 term ID 不一致时挂错分类或标签。
- 新增回滚 smoke：`wp eval-file wpmu-multilingual/tests/term-sync-smoke.php --allow-root --skip-themes`。

## v0.9.8.7: PHP field-delta synchronization

### 0.9.8.7 核心变化

- PHP 对标题、摘要、正文和可同步 Meta 建立源字段哈希快照，每次保存只生成实际变化字段清单。
- 未翻译目标同步变化后的源字段并进入首次翻译；已有译文目标不覆盖未变化译文，只把变化字段送入增量翻译。
- 增量任务显示为“更新已翻译的内容”，OpenAI、OpenCC 和 Agent 只写回任务清单中的字段。
- 是否已有译文使用明确完成标记和完成任务证据；未完成任务不会因为关系状态是 `needs_update` 而误触发保护。
- 历史任务第一次没有快照时保持安全兼容；完成一次同步后，后续修改稳定按字段增量处理。
- 实现与回滚测试结果见 [WPMU多语言插件_0.9.8.7_PHP字段增量同步测试报告_20260721.md](WPMU多语言插件_0.9.8.7_PHP字段增量同步测试报告_20260721.md)。

## v0.9.8.6: Visible Chinese suffix for slug conflicts

### 0.9.8.6 核心变化

- 新目标遇源 slug 占用时，fallback slug 使用 `源slug-源文章ID-和源站id重复`。
- 固定中文尾标用于后台人工快速识别冲突草稿；WordPress 数据库中的中文 slug 会按标准 URL 百分号编码保存，后台和正常链接显示时可还原。
- fallback 草稿、待复核 meta、关系和翻译任务规则不变，翻译写回不会移除该标识或自动发布。
- 当前全网 `fallback_review=0`，本次不迁移、不修改任何已有文章。

## v0.9.8.5: Sync safety recovery and slug fallback drafts

### 0.9.8.5 核心变化

- 修复 `trait-wpmu-ml-core-sync.php` 被旧备份覆盖造成的安全能力回退，重新恢复身份校验、任务校验、异常标记、只读审计和严格 meta 恢复 API。
- 新增独立 `trait-wpmu-ml-core-relation-safety.php`，将安全契约与历史同步大文件拆开，降低单文件损坏导致全部保护同时丢失的风险。
- 实际同步入口重新使用严格关系校验：无关系时只认完整来源 meta，不按相同 ID 或 slug 认领目标。
- 新规则：新目标遇源 slug 占用时，创建 `源slug-源文章ID` 的独立草稿，写待复核 meta，并正常建立关系和翻译任务。
- fallback 草稿在任务准备、OpenAI、OpenCC、Agent Payload/Result 中保持 fallback slug 和 `draft`，不会自动改回冲突 slug 或发布。
- 源站删除/回收/恢复和目标站人工生命周期处理重新接入身份校验与操作标记。
- 问题处理结果见 [WPMU多语言插件_0.9.8.5同步策略问题处理报告_20260721.md](WPMU多语言插件_0.9.8.5同步策略问题处理报告_20260721.md)。

## v0.9.8.4: Post-insert slug failure cleanup

### 0.9.8.4 核心变化

- 根据 OpenClaw 对 0.9.8.3 的审核建议，补齐新建目标文章后的 slug 二次锁定异常路径。
- 仅当本次刚创建的目标尚未写来源 meta、关系和任务，且 slug 锁定失败时，立即永久删除该新建目标，避免留下未关联草稿。
- 清理被 WordPress 钩子阻止时记录 `relation_create_orphan` 和目标文章 ID，便于人工定位；已有目标文章不参与此清理。
- OpenClaw 已验证正常新文章同步和 slug 占用冲突两个可回滚灰度场景通过，测试数据均已清理。

## v0.9.8.3: Target slug collision protection

### 0.9.8.3 核心变化

- 新增统一目标 slug 可用性校验；同类型文章或附件已占用源 slug 时返回 `target_slug_conflict`，不认领、不覆盖占用文章。
- 自动同步、翻译任务、OpenAI、OpenCC、Agent 写回、Agent Tools 和 slug 修复均在正文写入前检查冲突。
- 强制 slug 写入不再忽略失败结果；Agent 写回移除独立的直接数据库写入，统一使用受保护入口。
- 详细和汇总关系审计新增 slug 冲突统计。当前全网只读审计发现 234 条冲突关系，每个目标站 18 条，未自动修改任何文章或关系。
- 冲突关系后续写入会停止并记录 `target_slug_conflict`；现有文章 ID、内容、状态、URL 和关系保持不变，等待人工核对。
- 交付说明和人工测试清单见 [WPMU多语言插件_0.9.8.3关联改进与测试汇报.md](WPMU多语言插件_0.9.8.3关联改进与测试汇报.md)。

## v0.9.8.2: Database uniqueness and strict reconciliation

### 0.9.8.2 核心变化

- 文章关系表新增 `(target_blog_id, target_post_id)` 数据库唯一约束，在并发情况下也禁止一个目标文章属于多个源文章。
- 关系保存由 `REPLACE` 改为已有关系 `UPDATE`、新关系 `INSERT`；唯一键冲突只会失败，不会替换或删除旧关系。
- 索引迁移前已确认 149,617 条关系没有零目标 ID 和重复目标组，并单独备份关系表。
- 新增严格、非破坏性的 `wp wpmu-ml reconcile-relations`：只读取完整来源 meta，默认 dry-run，只新增缺失关系，不更新或删除既有关系。
- `wp wpmu-ml audit-relations --summary` 使用聚合 SQL 汇总全部目标站的缺失、类型、身份 meta、异常状态和重复目标，不逐条修改数据。
- 写入恢复必须指定目标站并同时提供 `--apply --confirm=ADD_META_RELATIONS`；ID 和 slug 永远不参与恢复认领。
- `hreflang` 进一步排除站点禁止索引、密码保护以及 Yoast、Rank Math、AIOSEO 明确 noindex 的文章。

## v0.9.8.1: Article relation identity protection

### 0.9.8.1 核心变化

- 新增统一文章关系与目标身份校验，区分严格身份、历史兼容、目标缺失、身份冲突和生命周期阻断。
- 新关系只允许通过精确来源 meta 认领；完全未找到时使用 WordPress 返回的实际目标 ID 新建，不再按相同 ID 或 slug 自动认领。
- 自动同步、翻译队列、OpenAI/OpenCC、Agent、Agent Tools、slug 修复、批量状态、删除恢复和前台链接统一接入身份保护。
- 历史关系缺少来源 meta 时继续兼容，并在下一次安全写入时补写来源 meta 与 `_wpmu_ml_relation_version=2`。
- 目标站人工删除、回收站和恢复会更新关系状态；异常关系不再继续自动写入。
- 危险关系重建入口已禁用，旧版 `TRUNCATE`/ID/slug 猜测实现已从运行代码删除，新增只读 `wp wpmu-ml audit-relations` 命令。
- 数据库目标唯一索引尚未自动添加；当前先执行应用层目标占用检查，待备份与完整审计后再实施索引迁移。

## v0.9.8: Article relation safety preparation

### 0.9.8 核心变化

- 插件维护版本从 `0.9.8` 开始，入口常量、核心类和文档版本保持一致。
- 根目录 Markdown 文档统一迁移到 `docs/`，新增文章关联改进记录。
- `WPMU多语言插件_文章关联改进方案.md` 作为后续关系机制改造的规范基线。
- 本版本只完成改造准备和文档治理，尚未改变文章同步、删除、重建、语言切换或 `hreflang` 行为。
- 当前同 ID / 同 slug 自动认领及目标身份校验风险仍然存在，完成代码保护和验证前不得执行生产关系重建。
- 详细方案见 [WPMU多语言插件_文章关联改进方案.md](WPMU多语言插件_文章关联改进方案.md)，实施进度见 [ARTICLE_RELATION_IMPROVEMENT_LOG.md](ARTICLE_RELATION_IMPROVEMENT_LOG.md)。

## v0.9.7.1: Whole-concept grouped article review

### 0.9.7.1 核心变化

- 最终 AI 审校不再只抽同一概念的部分字段，而是按重复源概念成组选择，并将已选概念在全文中的全部出现位置一并送审。
- 候选优先级改为：疑似源文残留与未翻译 → 完整概念组 → 同源不同译法 → 标题/摘要/标题层级 → 章节首尾与普通风险样本。
- 每个审校字段附带 `g` 概念组标签，AI 负责判断是否保持或重写；PHP 仍不生成目标译法、不替换自然语言。
- 默认最终审校最多 120 个重点字段，其中概念组最多 12 组、合计约 80 个字段，兼顾全文覆盖与请求规模。
- 后台语言提示词保持两句，不增加自动翻译记忆。

## v0.9.7.0: Streamlined source-context translation and inline final review

### 0.9.7.0 核心变化

- 文章上下文改为源内容确定性生成，不再额外调用 AI 生成 TOPIC/AUDIENCE/STYLE，也不再存在标签被翻译后解析失败的问题。
- 正文继续按 H2/H3 连续语义组翻译，每批共享源标题、摘要、章节标题、相邻章节以及上一批源文/译文尾部。
- 合并后的文章只走一条最终 AI 审校链：模型同时看到源文、当前译文、字段角色和章节上下文，并直接返回 `keep` 或最终 `rewrite` 文本。
- 删除文章正文的多轮抽样、关联扩张和独立盲修复链，减少请求数，并防止“前后台切换”被修成“前后摄像头切换”一类脱离源文的错误。
- PHP 继续只做确定性完整性保护，不进行词语、标点或自然语言硬替换；不启用自动翻译记忆。
- 后台语言提示词建议保持 2～3 句，示例已缩短。

## v0.9.6.6: Full-context, section translation, article consistency review

### 0.9.6.6 核心变化

- 全文先生成短总览，但不生成机器目标译法。
- 正文按 H2/H3 与连续语义组翻译，每批携带当前、上一、下一章节以及上一批译文尾部作为只读上下文。
- 默认正文批次最多 36 个字段、约 2200 个源字符。
- 合并后全量审校标题、摘要、各章节标题及各章首尾锚点，再执行全文自适应质检。
- 后台语言提示词建议只保留 2～3 句；PHP 继续只负责完整性和结构保护。
- 不启用自动翻译记忆。

## v0.9.6.5: Article terminology context and related-field AI review

### 0.9.6.5 核心变化

- PHP 本地检查始终开启，但只负责字段返回、空值、结构、占位符和数据库写回完整性；不再对译文做任何标点、空格、数字或长度改写。
- 疑似源文残留、数字差异、长度差异和边界空格只作为可选 AI 质检提示；AI 返回 `keep` 时原译文保持不变。
- AI 质检状态解析兼容冒号、短横线、斜杠等明确 `rewrite` 返回格式。
- 帮助选项卡按“必需 / 建议 / OpenAI 必需 / OpenCC 按需”显示服务器环境检测。
- 日志新增清晰的 QA 唯一字段/原始字段口径和写回校验结果。


- 翻译前生成文章级动态术语上下文，并在标题、摘要、正文、Gutenberg、元数据和 SEO 字段之间共享。
- 代码不做自然语言词语硬替换；术语上下文和相关残留片段仅用于 AI 提示与候选扩展。
- AI 发现源语言残留后，可自动扩展检查同文中包含相同片段的其他字段，AI `keep` 仍是最终决定。

## v0.9.6.3: Mandatory PHP integrity and AI-authoritative quality review

### 0.9.6.3 核心变化

- PHP 本地完整性检查始终开启，后台不提供关闭选项；仅检查返回字段、非空值、WordPress/HTML/JSON 结构、占位符以及数据库写回完整性。
- 后台质检设置只保留“开启 AI 质量检查”。关闭时不发起第二次 AI 质检，PHP 完整性检查仍照常执行。
- 疑似源文残留、数字差异和长度差异只作为 AI 审校提示，不再由 PHP 强制重写、阻止写入或覆盖 AI 的 `keep` 判断。
- AI 返回 `keep` 时保留当前译文；只有 AI 明确返回 `rewrite` 才进入定点修复。
- 内置 OpenAI 通道与 Agent API 通道采用同一完整性边界，并对文章字段、状态、slug 和元数据执行写回回读校验。

## v0.9.6.2: Internal safe batching profile

### 0.9.6.2 核心变化

后台不再显示“单次请求字符上限”。真实测试中，30,000 相比 6,000 没有改善重复出现的质量结果，却显著增加正文请求耗时。因此插件固定使用经过测试的内部安全参数：正文源文字符上限 6,000、单批字段上限 200、adaptive 候选上限 24、质检字段上限 80、质检字符上限 12,000。

这些参数只决定请求如何分批，不能代替术语、本土化、结构、数字和内容质量检查。升级后旧的 30,000 等自定义值会被迁移并在运行时忽略。

## v0.9.6.1: Simplified adaptive localization settings

### 0.9.6.1 核心变化

- 默认质量模式改为 `adaptive`：主翻译请求在返回前静默自检全部字段，不增加请求。
- PHP 对全部译文执行结构、占位符、URL、数字、金额、版本、规格、残留和字段完整性检查。
- 第二次 AI 只接收确定性异常、标题/摘要、重复源文译法冲突、长度异常及少量最高风险候选。
- `all` 仍可用于完整第二遍 AI 复核，但只建议排错或基准测试时启用。
- 默认 adaptive 候选上限为 24；确定性错误不受上限限制。
- 目标是避免围绕单篇文章堆积短语补丁，同时保留通用本土化、术语一致性和严格草稿保护。


### 0.9.5.0 重点

- 保留 `openai_qa_coverage_mode=all` 的 100% AI 质检覆盖，但针对 0.9.4.9 真实长文测试中出现的 41 次请求、638.9 秒耗时进行了性能修复。
- Gutenberg 区块 JSON 中的可见文本先统一收集，再集中翻译和按原 JSON 路径写回；不再出现多个短按钮、标题分别调用一次 API。
- ACF/Postmeta 及 Rank Math、Yoast、AIOSEO 的可识别纯文本叶子统一收集、集中翻译、按原数组或 JSON 路径写回；URL、ID、字段引用、键名和机器值继续跳过。
- 集中翻译默认每批最多 `120` 个字段，仍受正文字符上限约束；集中 QA 默认每批最多 `80` 个唯一字段、源译合计 `16000` 字符。
- 升级时仅把 0.9.4.9 的精确默认值 `45 / 20 / 9000` 迁移为 `120 / 80 / 16000`，管理员自行修改过的值不会被覆盖。
- `all` 模式不再在正式集中 QA 前执行一次重复的正文结构修复请求。
- 问题字段修复请求的 JSON 值只包含当前译文，源文、角色和问题放在指令上下文中；程序会拒绝模型把 `ROLE/SOURCE/ISSUES` 包装内容写进文章。
- 数字验收区分“必须精确保留的事实数字”和“允许语言形式变化的语义数字”：金额、百分比、日期、版本、配置、范围、数量及单位关系仍严格检查，而 `4 → four`、`第一步 → Step 1`、`从0到1 → from scratch` 不再误判。
- 继续拦截 `双11 + 亿级订单 → 11 billion orders` 一类数字与实体边界错误。
- 修正 `deterministic_checked` 统计，并自动清理 `20, 000+` 这类千位分隔空格。

### 推荐默认设置

- 集中翻译批量：`120`
- AI 质检覆盖模式：`adaptive`
- 翻译内联自检：开启
- adaptive AI 候选上限：`24`
- 集中质检每批字段数：`80`
- 集中质检每批字符数：`16000`
- 问题字段自动修复：开启
- 质检不完整时保持草稿：开启
- 严格质检状态：开启

### 0.9.4.9 / 0.9.4.8 compatibility notes

- `risk` 仍保留 0.9.4.8 的高风险抽样模式，`off` 仍可只执行程序确定性检查。
- 0.9.4.9 的全覆盖 Manifest、去重映射、集中修复和严格草稿发布门槛继续保留。
- Compact prompts、空字段恢复、API fallback 诊断和严格草稿暂存继续启用。
- 0.9.5.0 主要改变批处理、数字验收和修复载荷，不新增网站专用 ACF/SEO 字段映射。


## v0.9.4.7: QA state separation, editor circuit breaking and faster publication checks

- Accepts plain single-field editorial statuses such as `keep`, `ok`, `pass`, `correct`, `accept` and `no change`, preventing a valid `keep` response from triggering unnecessary Chat/Responses retries.
- Treats article-editor availability as optional polish rather than translation completion. Successfully translated content is preserved; strict mode records `review_required` and keeps the target as a draft instead of retrying the entire translation job.
- Opens a per-job editor circuit after one complete optional-editor failure, so the remaining blocks are counted as unavailable without repeating the same three-mode failure chain.
- Reduces AI editor coverage for short workflow diagrams, labels, headings and ordinary short list items that already pass deterministic residue, arrow, placeholder and HTML checks. Facts, prices, billing periods, specifications and substantial prose remain high-risk AI-review targets.
- Stages strict-QA target posts as drafts and publishes only after all mandatory final checks pass.
- Synchronizes stale auto-generated Rank Math title/description values with the newly accepted title/excerpt while preserving templates and custom SEO copy.
- Emits `API PERFORMANCE outcome=...` for success, review-required and failure paths.
- New trace markers include `ARTICLE EDITOR CIRCUIT SKIP`, `QA STAGING`, `QA STAGING RELEASE`, and `SEO CONSISTENCY SYNC`.


## v0.9.4.6: Gutenberg flow translation and faster non-duplicated QA

- Prevents optional long-excerpt editorial polish from aborting a job that already passed required target-language QA.
- Routes human-readable architecture/workflow lines stored in Gutenberg `code` fields through normal prose translation.
- Keeps safe single-field alias recovery for gateways that return `text` instead of `t0`.
- Uses risk-based final editor coverage so long prose, facts, prices, specifications, markup and workflow lines receive AI review while tiny labels/headings avoid redundant reasoning calls.
- New trace markers: `TITLE/EXCERPT EDITOR FAST SKIP`, `GUTENBERG HUMAN FLOW ROUTE`, and `ARTICLE EDITOR RISK FILTER`.


## v0.9.4.5: Faster complete QA and Gutenberg field recovery

- Version identifiers are synchronized as `0.9.4.5`.
- Single-field structured translations can safely recover common model aliases such as `text`, `translation`, `result` and `output`. A request for `t0` that receives only `{"text":"..."}` is mapped to `t0` instead of being discarded and retried four times.
- Gutenberg block-comment data, translatable attributes and code-text stages start with the compact prompt instead of the full article rule bundle, reducing prompt size and avoiding predictable output-less failures on small fields.
- The fast quality pipeline defers repetitive per-batch body language audits to the whole-article editorial pass. Local checks still reject empty output, damaged placeholders and obvious source-language residue before accepting a translation.
- QA starts with small bounded batches (default: 3 fields) instead of first attempting 12- or 30-field requests and waiting for recursive failures.
- Long title/excerpt editorial checks are proactively split into single fields.
- Gutenberg block-data failures are counted as incomplete quality coverage.
- `translate-one` prints a warning rather than `Success` when the final job status is `review_required`.

## v0.9.4.3: Semantic-block localization and publication-quality QA

- Version identifiers are synchronized as `0.9.4.3`.
- Body translation now defaults to complete semantic units (paragraphs, headings, list items, table cells and inline-markup sentences); batch limits control request size rather than forcing text-node translation.
- Human-readable inline-code labels stay inside their sentence-level AI context, so compact specifications are interpreted contextually instead of translated as isolated tokens.
- Editorial QA recognizes negative verdicts such as `wrong:`, `fail:` and `reject:` and performs targeted repair instead of silently publishing them.
- Title/excerpt repair uses source + current target + issue context and protects facts, currency identity, prices and billing relationships.
- Non-CJK targets receive conservative visible-text punctuation localization and inline-HTML boundary spacing repair.
- Detailed trace logs expose semantic translation mode, QA decisions, invalid model statuses and boundary-polish activity.

## v0.9.4.2: Compact stage prompts and output-missing recovery

- Version identifiers are synchronized as `0.9.4.2`.
- Empty request fields are removed before API calls and restored unchanged afterward, so a non-empty title plus an empty excerpt is treated as a single-field task.
- Lightweight stages use compact prompts. Body translation can start with the full rule bundle, then genuinely fall back to a compact prompt instead of appending more recovery text to an already large prompt.
- A single field falls back through compact keyed JSON, Chat plain text, and finally Responses API plain text. PHP restores the original field key; Responses API is a last resort, not the default route.
- Plain-text fallback explicitly preserves facts, numeric values, original currency, brands, and official product names. It must not convert currency or invent information.
- Output-less success envelopes are classified as `upstream_output_missing` when output tokens were consumed but no visible `content` or `output_text` was returned.
- Trace output now includes removed empty fields, full/compact prompt sizes, prompt component lengths and hashes, protocol/endpoint selection, content presence, and Responses API diagnostics.

## v0.9.3：各语言分站自动同步语言菜单

- 主题注册 `language-menu` 时，每个启用语言分站会自动创建或修复其菜单绑定，并补齐“当前语言”父项和全部启用语言子项。
- 前台父项始终显示当前访问分站的语言；下拉中会自动移除重复的当前语言。
- 保存“语言站点”或“语言切换”后会重新同步；升级到本版本时也会执行一次迁移同步。

## v0.9.2：语言切换菜单集成

- “翻译设置”新增独立的 **语言切换** 选项卡，位于“语言站点”和“内容类型”之间。
- 启用“显示在后台菜单”后，各语言分站的 **外观 → 菜单** 中会出现 **WPMU 语言切换器** 面板，可添加“当前语言”和全部已启用语言。
- 前台菜单会动态生成对应语言页面链接，并移除下拉菜单中重复的当前语言。

## v0.9.1：修复后台设置无法保存与大表单截断

- 网络后台恢复为一个一级菜单 **WPMU 多语言**，其下包含 **翻译设置** 与 **翻译引擎** 两个子菜单；`翻译设置` 仍使用 `admin.php?page=wpmu-multilingual`，`翻译引擎` 使用独立页面。
- 翻译引擎第一层改用与翻译设置一致的 WordPress `nav-tab-wrapper / nav-tab` UI；OpenAI 内部二级选项卡继续保留。
- 保持 0.8 系列 network option 名 `wpmu_ml_settings` 与 OpenAI 字段名不变；增加早期/误存主站设置的只补缺失键兼容读取。API Key 留空保存不再清除，需显式勾选才会删除。
- 默认与路由中的“按目标语言设置”和“目标语言 + 文章类型精确覆盖”默认折叠，避免语言数量多时页面过长。


网络后台现在保留一个一级菜单 **WPMU 多语言**，其下包含两个子菜单：

- **翻译设置**：概览、语言站点、语言切换、内容类型、关联管理、自动同步、翻译队列、工具和帮助。
- **翻译引擎**：默认与路由、OpenAI 兼容、Agent API、OpenCC、人工翻译、翻译规则和高级说明。

OpenAI 兼容引擎内部使用二级选项卡，将接口、目标语言档案、内容处理和质检分开。语言档案自动读取“翻译设置 → 语言站点”中已启用的目标站，每种语言可单独设置模型覆盖、Temperature 和语言专用提示词。质检不再按语言提供独立开关，后台只保留全局“开启 AI 质量检查”。

语言专用提示词只作用于当前目标语言，不需要复制全站通用规则。内置事实忠实、目标语言锁、HTML/代码保护和安全规则始终优先。OpenAI 模型选择顺序为：目标语言 + 文章类型精确覆盖、语言档案模型、文章类型模型、全局默认模型。PHP 字段、空值、结构、占位符和写回完整性检查始终运行；AI 质量检查仅承担编辑判断。

## v0.8.17.32：整篇 AI 编辑审校与定点重写

本版把“翻译人员的判断”放回模型，而不是继续增加固定词表或某种语言的句尾规则。正文仍按 Translation Blocks 保留 HTML 结构并一次主翻译；主翻译和通用目标语言审计通过后，新增整篇 `article_editor_qa`：模型同时对照源文块、当前译文和文章顺序，判断是否存在残句、机械直译、语义错位、前后矛盾、被 HTML 边界切断、重复或不符合目标语言母语写作的问题。审校只返回 `ok` 或 `rewrite:原因`，随后只重写被标记的块，并提供前后相邻块作为上下文。

编辑审校完全读取后台源语言和目标语言配置，不固定日语、俄语、泰语或任何词法规则；品牌、URL、代码、文件名等跨语言固定值仍跳过。重写结果继续经过占位符完整性和通用目标语言锁检查，不合格时保留原译并在 `--trace` 中显示 `ARTICLE EDITOR REJECT`，不会破坏 HTML。因为新增一次或多次整篇审校请求，长文预计会比 0.8.17.31 多几十秒，但只对确有问题的段落执行重写。

## v0.8.17.31：跨语言不可翻译值与残留误判修复

本版继续使用后台配置驱动的通用源语言→目标语言审计，不固定任何语言。修复残留补翻把裸域名、文件名、代码标识符、版本号和编号品牌项当成未翻译正文的问题。`hollywoodreporter.com`、`functions.php`、`PHP 8.3`、`1. Divi` 等值现在会按内容形态识别为跨语言不可翻译值，不再进入 `body_residual`，也不会因保持原样而被语言审计拒绝。

跳过规则只适用于明确的非正文值；普通自然语言句子即使与源文相同，仍会按后台目标语言进行审计。文章中真正出现的中日混杂、俄泰混杂或其他目标语言跑偏依旧会被定位、单字段重译并在持续失败时阻止保存。CLI 继续直接输出失败字段、原因、源文和模型返回内容。

## v0.8.17.30：通用源语言→目标语言锁与 CLI 直出错误

本版不再在 OpenAI 翻译接受链路中固定日语、俄语、泰语或任何其他语言。源语言与目标语言完全读取后台站点语言配置中的 `openai_source_language_context` 和 `openai_target_language_context`。每个有意义的翻译批次完成后，会追加一次 `language_qa` 审计请求，按当前配置判断候选正文是否使用目标语言；品牌、产品名、代码、命令、URL、文件名、标识符、缩写、型号、数字、引用和通用技术术语允许原样保留。

语言审计发现跑偏时，只重试对应字段；连续恢复后仍不是目标语言，就原子失败并阻止保存。正文提取入口也改为 Unicode 通用自然语言检测，不再依赖汉字、假名、西里尔文、泰文等固定字符集；中文、英文、俄文、泰文、阿拉伯文等源站均走同一套规则。残留补翻改为“源语言残留”，通过源文与译文对照判断，不再写死“残留中文”。

WP-CLI 单篇翻译失败时会直接输出 `job_id`、任务状态、尝试次数和任务表中的完整 `last_error`；持续语言跑偏的最终错误还会包含失败字段、拒绝原因、源文与模型返回片段。`--trace` 中可看到 `stage=language_qa`、`LANGUAGE AUDIT` 和 `LANGUAGE AUDIT REJECT`。通用审计通常每个有意义的翻译批次增加一次 QA 请求，而不是每个字段各发一次。

## v0.8.17.29：修复日语“数”字误判为简体中文

`0.8.17.28` 的目标语言锁把正则字符类中的“数据”拆成了单字符匹配，导致正常日语里的“数”被当作简体中文。`0.8.17.29` 改为组合判定：只有无假名/极少假名且包含强简体字或中文功能短语的文本才会被拒绝；`複数、数日、数字、関数、インストール数` 等正常日语不再触发重试。其他语言跑偏硬锁保持不变。

## v0.8.17.28：日语编辑式修复与目标语言硬锁

本版继续只调整翻译链路，不改多站同步、队列、关系、slug、发布和 CDN 逻辑。在 0.8.17.27 基础上，正文仍以整篇有序 Translation Blocks 一次主请求翻译，但提示词明确改为“忠于原文事实的日语编辑式本土化”：遇到原文残句、缺谓语、重复标点等明确缺陷时，允许保守补全语法，不得新增事实。新增日语目标语言硬锁，逐字段拦截韩语、俄语、阿拉伯语、泰语、长篇英语或明显中文等跑偏结果，只重试失败字段；连续重试仍跑偏则停止保存。正文主翻译完成后，仅对检测到悬空助词、缺谓语、引号不闭合等明确问题的段落执行一次小型修复，不会再翻整篇文章。

## v0.8.17.27：日语标题与短标签译后质检

本版继续只调整翻译链路，不改多站同步、队列、关系、slug 和发布逻辑。在 0.8.17.26 的完整语义块与成对占位符基础上，新增保守的日语译后质检：仅扫描可见文本节点，修正 `提示/警告/Pros/Cons` 等短标签和 `。.` 等重复标点；代码、标签、属性、URL、Gutenberg 注释及 no-translate 区域保持不变。标题出现 `仮想専用サーバーVPS` 或重复 `VPS` 时，仅对标题额外重译一次。

# WPMU多语言插件方案说明

## v0.8.17.25：参考成熟插件的 Translation Block 提取

本版本只调整 OpenAI 正文翻译链路，不改多站同步、队列、文章关系、slug、发布和 CDN 相关逻辑。

- 参考 TranslatePress 的 top-parent / deepest translation block 思路，正文不再依赖简单的 `<[^>]+>` 标签正则判断。
- 新增保留原始字节位置的 HTML 扫描器，可正确识别属性值中包含 `>` 的标签、注释、嵌套标签和松散文本，回填时不重新序列化 HTML。
- `p`、标题、`li`、图注、表格单元格、引用及没有更深语义块的 `div/section/article` 等按完整语义块提取；父块包含子翻译块时自动跳过父块，避免重复翻译和二次回填。
- 行内 `<strong>`、`<a>`、`<span>` 等仍以占位符保护，整段内容作为一个字段发送；文章标题、已翻译标题和摘要作为只读上下文提供给正文模型。
- 新增页面可见属性翻译：`alt`、`title`、`placeholder`、ARIA 标签以及按钮类 `value`，只替换属性值，不改标签、URL、class、ID 或其他属性。
- `--trace` 中正文显示 `TRANSLATION BLOCKS`，属性显示 `TRANSLATABLE ATTRIBUTES` / `body_attributes`，便于确认提取数量和请求阶段。
- 新增 translation-block parser 回归测试，覆盖父子去重、行内标签整段、带 `>` 的属性和属性安全回填。

版本：0.9.8.18
适用环境：WordPress Multisite / 多站点网络  
建议启用方式：网络启用 Network Activate

---

## 1. 项目目标

本插件用于把一个 WordPress Multisite 网络改造成多语言站群系统。核心目标不是简单前端动态翻译，而是：

1. 用一个源语言站点作为权威内容源。
2. 自动在其他语言站点创建对应目标文章。
3. 建立源文章与目标文章的关系表。
4. 通过队列慢慢处理机器翻译、OpenCC 转换或人工翻译。
5. 翻译完成后把目标语言内容真正写入目标站数据库。
6. 输出 hreflang，方便搜索引擎识别多语言版本。
7. 支持长文章、ACF 字段、字段感知翻译、SEO 字段、代码智能翻译、OpenAI 兼容 翻译规则和质量检查，并提供给本地 Agent 使用的 REST 工具接口。

本方案不是 TranslatePress 那种访问时动态翻译，也不是单站多语言插件，而是基于 WordPress Multisite 的“多语言多站点内容同步 + 翻译队列”方案。

---

## 2. 当前站点规划

当前约定的语言站结构如下：

| 语言 | 站点路径 | 说明 |
|---|---|---|
| 英文 | `/` | 根站，Blog ID 1 |
| 简体中文 | `/zh-hans/` | 源站，权威内容源 |
| 繁体中文 | `/zh-hant/` | 统一繁体，不再拆 zh-tw / zh-hk |
| 俄语 | `/ru/` | 目标语言站 |
| 日语 | `/ja/` | 目标语言站 |
| 韩语 | `/ko/` | 目标语言站 |
| 西班牙语 | `/es/` | 目标语言站 |
| 葡萄牙语 | `/pt/` | 目标语言站 |
| 德语 | `/de/` | 目标语言站 |
| 法语 | `/fr/` | 目标语言站 |
| 阿拉伯语 | `/ar/` | 目标语言站 |
| 土耳其语 | `/tr/` | 目标语言站 |
| 印尼语 | `/id/` | 目标语言站 |
| 越南语 | `/vi/` | 目标语言站 |

当前原则：

- `/zh-hans/` 是源站。
- 其他站点都是目标语言站。
- 英文根站 `/` 也是目标语言站，不是源站。
- 目标语言文章是真实写入数据库的独立文章。
- slug 绝对不翻译，目标语言文章始终使用源站 `post_name`，这是硬规则，不提供关闭选项。
- 分类 slug / 标签 slug 不翻译。
- 繁体只保留一个 `/zh-hant/`，不再拆台湾和香港。

---

## 3. 繁体中文方案

繁体中文采用 OpenCC 转换后落库，不做访问时动态转换。

推荐设置：

| 项目 | 推荐值 |
|---|---|
| 语言 slug | `zh-hant` |
| URL | `/zh-hant/` |
| Locale | `zh_TW` |
| hreflang | `zh-Hant` |
| OpenCC 配置 | `s2twp.json` |

说明：

- URL 和 hreflang 使用泛繁体：`zh-Hant`。
- 内容转换可使用台湾正体惯用词：`s2twp.json`。
- OpenCC 转换后写入 `/zh-hant/` 目标文章。
- 不建议访问时临时转换，因为不利于 SEO、缓存、后台管理和性能。

---

## 4. 插件核心原则

### 4.1 同步和翻译分离

插件分两步工作：

```text
同步：创建目标语言文章壳子 + 建立关联关系 + 创建翻译任务
翻译：读取源站内容 + 翻译/转换 + 写入目标站文章
```

同步不等于翻译。同步只负责把文章关系建起来，不应该在保存源文章时直接调用 API。

### 4.2 源站为权威内容

源站 `/zh-hans/` 的内容是权威内容。目标站内容由源站翻译或转换生成。

翻译时逻辑为：

```text
读取 /zh-hans/ 源文章
↓
读取标题、摘要、正文、ACF/postmeta、SEO 字段
↓
按目标语言选择翻译方式
↓
写入目标语言站点对应文章
```

不是拿目标语言当前文章自己原地翻译。

### 4.3 已翻译内容保护

默认情况下，已翻译目标文章不应被源中文直接覆盖。重翻译时需要显式勾选“强制覆盖已有目标内容”或 CLI 使用 `--force`。

---

## 5. 插件目录结构与命名约定

插件已经进入结构化拆分阶段。`WPMU_Multilingual` 保持原有公共接口，但历史大核心类已按职责拆入 `includes/core/traits/`；核心文件现在只负责类装配、单例和 Hook 注册。长期维护约束见 `docs/ARCHITECTURE_AND_TRANSLATION_RULES.md`。

当前结构：

```text
wpmu-multilingual/
├── wpmu-multilingual.php
├── docs/
│   ├── README.md
│   ├── CHANGELOG.md
│   ├── ARCHITECTURE_AND_TRANSLATION_RULES.md
│   ├── WPMU多语言插件_文章关联改进方案.md
│   └── ARTICLE_RELATION_IMPROVEMENT_LOG.md
├── includes/
│   ├── bootstrap.php
│   ├── class-wpmu-ml-core.php                  # 兼容旧路径的加载壳
│   ├── class-wpmu-ml-openai-helper.php         # 兼容旧路径的加载壳
│   ├── class-wpmu-ml-agent.php                 # 兼容旧路径的加载壳
│   ├── core/
│   │   ├── class-wpmu-ml-core.php              # 核心装配壳
│   │   └── traits/                             # 按职责拆分的历史核心实现
│   │       ├── bootstrap.php
│   │       ├── trait-wpmu-ml-core-openai-client.php
│   │       ├── trait-wpmu-ml-core-openai-content.php
│   │       ├── trait-wpmu-ml-core-openai-quality.php
│   │       ├── trait-wpmu-ml-core-queue.php
│   │       └── ...
│   ├── cli/
│   │   └── class-wpmu-ml-cli.php
│   ├── engines/
│   │   ├── openai/
│   │   │   └── class-wpmu-ml-openai-helper.php
│   │   └── agent/
│   │       ├── class-wpmu-ml-agent.php
│   │       ├── class-wpmu-ml-agent-payload.php
│   │       ├── class-wpmu-ml-agent-result-applier.php
│   │       └── class-wpmu-ml-agent-validator.php
│   ├── admin/
│   ├── database/
│   ├── sync/
│   ├── queue/
│   ├── translation/
│   ├── rest/
│   ├── security/
│   ├── support/
│   └── index.php
└── assets/
    └── index.php
```

当前拆分说明：

| 路径 | 作用 |
|---|---|
| `wpmu-multilingual.php` | 插件入口、常量、激活钩子、全局语言切换函数 |
| `includes/bootstrap.php` | 统一加载器，后续新增类优先从这里加载 |
| `includes/core/class-wpmu-ml-core.php` | 核心装配壳，只保留类状态、生命周期、Hook 注册和 trait 组合 |
| `includes/core/traits/` | 按后台、队列、同步、OpenCC、OpenAI 内容/质量/API 等职责拆分的兼容实现 |
| `includes/cli/class-wpmu-ml-cli.php` | WP-CLI 命令注册，已从 core 中拆出 |
| `includes/engines/openai/` | OpenAI/OpenAI 兼容 直连翻译路径相关代码 |
| `includes/engines/agent/` | `agent` 翻译引擎与 REST API 任务交接层，只给本地/外部 Agent 调用 |
| `includes/admin/` | 预留后台页面拆分目录 |
| `includes/database/` | 预留数据表和 repository 拆分目录 |
| `includes/sync/` | 预留文章、分类、slug 同步服务目录 |
| `includes/queue/` | 预留翻译队列、锁、重试等服务目录 |
| `includes/translation/` | 预留字段抽取、结果写回、内容校验等公共翻译服务目录 |
| `includes/rest/` | 预留 REST controller 目录 |
| `includes/security/` | 预留 API Key、权限校验等安全服务目录 |
| `includes/support/` | 预留 Logger、Settings、Utils 等支撑类目录 |

### 5.1 命名约定

本插件采用 WordPress 常见命名风格：

- **文件名和文件夹名统一小写，用连字符 `-` 分隔**，例如：`class-wpmu-ml-core.php`、`includes/engines/openai/`。
- **PHP 类名继续使用历史前缀 `WPMU_ML_` 或 `WPMU_Multilingual`**，例如：`WPMU_ML_CLI`、`WPMU_ML_OpenAI_Helper`。
- **Hook、option、meta、engine、status 标识统一小写，用下划线 `_` 分隔**，例如：`wpmu_ml_translation_jobs`、`agent_pending`、`opencc_s2twp`。
- **后台显示文案可以使用大小写和中文**，例如：`Agent API`、`OpenAI 兼容`。
- 不建议在插件目录里混用 `Core/`、`Engines/OpenAI/` 这类大写目录。虽然 PHP 本身可以加载，但 Linux 文件系统大小写敏感，WordPress 生态也更常用小写连字符目录。

### 5.2 引擎目录原则

`includes/engines/` 只放翻译引擎相关代码。

如果一个引擎只有一个文件，可以直接放在对应引擎目录下；如果一个引擎后续需要多个文件，就在对应目录中继续拆分：

```text
includes/engines/openai/
├── class-wpmu-ml-openai-engine.php
├── class-wpmu-ml-openai-client.php
├── class-wpmu-ml-openai-helper.php
└── class-wpmu-ml-openai-result-parser.php

includes/engines/agent/
├── class-wpmu-ml-agent.php
├── class-wpmu-ml-agent-payload.php
├── class-wpmu-ml-agent-result-applier.php
└── class-wpmu-ml-agent-validator.php
```

当前 v0.8.4 已把 Agent 内部拆成 payload builder、result applier、validator 和主 REST 引擎入口，后续仍可继续把通用字段抽取/写回服务移动到 `includes/translation/`。

### 5.3 后续拆分方向

trait 拆分用于先降低单文件复杂度并保持兼容；下一阶段应把无状态、可复用逻辑逐步迁移为独立服务类，而不是长期把 trait 当作最终架构。公共翻译服务应由 OpenAI 直连和 Agent 共享：

```text
includes/translation/class-wpmu-ml-field-extractor.php
includes/translation/class-wpmu-ml-result-applier.php
includes/translation/class-wpmu-ml-content-validator.php
```

目标是让 OpenAI 引擎和 Agent 引擎共用同一套：

```text
字段抽取 → 翻译 → 结果校验 → 写回目标站
```

OpenAI 由插件内部调用模型；Agent 通过 REST API 领取 payload 并提交 result。两者不应维护两套不同的 ACF/SEO/postmeta 写回逻辑。

维护要求：从 v0.7.2 起，**每次更新插件源码都必须同步更新 `docs/README.md`**，至少包括版本号、版本记录、功能变化、使用方式变化、已知限制和排查方式。README 是换会话、换服务器或后续继续维护时的主说明文件，不能和代码版本脱节。
---

## 6. 数据表

插件使用网络级自建表，表前缀通常基于 `$wpdb->base_prefix`。

主要表：

| 表 | 作用 |
|---|---|
| `{base_prefix}wpmu_ml_sites` | 多语言站点配置 |
| `{base_prefix}wpmu_ml_post_relations` | 源文章和目标文章关联 |
| `{base_prefix}wpmu_ml_term_relations` | 分类/标签关系 |
| `{base_prefix}wpmu_ml_translation_jobs` | 翻译任务队列 |
| `{base_prefix}wpmu_ml_logs` | 插件日志 |

常用诊断命令：

```bash
cd wpmu-multilingual/

wp wpmu-ml doctor --allow-root --skip-themes
```

查询某个任务：

```bash
wp wpmu-ml doctor --job_id=62 --allow-root --skip-themes
```

或：

```bash
wp wpmu-ml job --job_id=62 --allow-root --skip-themes
```

---

### 单篇翻译过程追踪

测试单篇翻译时可追加 `--trace`。0.9.6.1 会输出请求计划、降级模式、字段 key、字符数、源文摘要、响应结构、模型正文长度、`reasoning_content`、`refusal`、内嵌 error、Token 用量和具体失败原因：

```bash
LOG="/tmp/wpmu-ml-POSTID-LANG-$(date +%Y%m%d-%H%M%S).log"

wp wpmu-ml translate-one \
  --post_id=12351505 \
  --lang=en \
  --force \
  --trace \
  --allow-root \
  --skip-themes \
  2>&1 | tee "$LOG"

echo "日志文件：$LOG"
```

重点日志：

| 日志 | 含义 |
|---|---|
| `API CALL ... PLAN` | 原始/有效字段数、空字段数量、字符数、完整/精简提示词长度、模型、Chat/Responses endpoint 和降级模式 |
| `API FIELD` / `API FIELD SKIP` | 实际发送字段及被跳过的空字段；包含 key、字符数、哈希和源文摘要 |
| `API ... mode=full_json/compact_json/plain_text_chat/plain_text_responses` | 当前完整提示词、精简提示词、Chat 纯文本或 Responses 纯文本模式 |
| `PROMPT COMPONENTS` | 语言专用提示词、站点规则、术语表、任务、完整/精简基础提示词及输出契约的长度 |
| `API RESPONSE_DIAG` | 协议、状态、外层/message/output 键、content 是否存在、content/reasoning/refusal/error 和 Token 用量 |
| `API SEMANTIC_FAILURE` | HTTP 成功但模型正文为空、拒绝、输出缺失或格式不可用的明确原因 |
| `API UPSTREAM_OUTPUT_MISSING` | 已消耗 completion/output Tokens，但 Chat `message.content` 或 Responses `output_text` 缺失；随后切换轻量模式 |
| `API RAW_BASE64` | 仅语义失败时记录的完整原始响应，可用 `base64 -d` 解码 |
| `BATCH SPLIT` / `BATCH LEAF FAILED` | 批量二分定位过程和最终失败字段 |
| `TRANSLATION MODE semantic_blocks=on` | 正文按完整语义块翻译；字段数量只控制 API 批次大小 |
| `TITLE/EXCERPT EDITOR QA` | 标题/摘要编辑审校的 keep/rewrite 决策、原因和原始状态 |
| `API PLAIN STATUS RECOVERY` | 单字段质检安全接受 `keep/ok/pass` 或 `rewrite:原因` 纯文本状态，停止无意义的后续协议重试 |
| `ARTICLE EDITOR AUDIT` | 文章块编辑审校的 requested/checked/unavailable/rewrite 和 completed/partial 状态 |
| `ARTICLE EDITOR CIRCUIT SKIP` | 某个可选编辑质检叶子已耗尽全部模式后，本任务剩余编辑批次由熔断器跳过并计入质量覆盖 |
| `ARTICLE EDITOR SPLIT` / `LANGUAGE AUDIT SPLIT` | 大批质检空响应后的递归拆分路径 |
| `ARTICLE EDITOR PRECHUNK` / `LANGUAGE AUDIT PRECHUNK` / `TITLE EDITOR PRECHUNK` | 快速质量流水线主动使用安全小批次，避免先等待大批请求失败 |
| `FAST QUALITY PIPELINE` | 正文批次的重复语言 AI 审计已延后并合并到整篇编辑审校 |
| `API SINGLE FIELD ALIAS RECOVERY` | 单字段请求安全接受模型返回的唯一通用别名键，例如 `text → t0` |
| `QUALITY COVERAGE` | 某个内容表面未完成时进入 `review_required`，不会宣称完整成功 |
| `QA STAGING` / `QA STAGING RELEASE` | 严格质检模式先写入草稿，通过最终检查后才切换到请求的发布状态 |
| `SEO CONSISTENCY SYNC` | 将遗留的自动生成 SEO 标题/摘要同步到本次接受的标题/摘要，不改 Rank Math 模板和自定义文案 |
| `API PERFORMANCE` | 当前任务的终态 outcome、API 请求数、失败数、降级数、累计秒数和分阶段耗时；成功、需复核和失败都会输出 |
| `API RESPONSE FIELD FILTER` | 模型返回未请求字段时被丢弃的键 |
| `MACHINE TOKEN PROTECTION` | 本次语义块中受保护的域名、URL、路径等机器值数量 |
| `HTML BOUNDARY POLISH` | 非 CJK 目标语言的行内标签边界空格修复 |
| `FINAL TYPOGRAPHY RESIDUE` / `FINAL HTML BOUNDARY RESIDUE` | 最终发布前仍存在全角标点或标签粘连 |
| `LANGUAGE AUDIT status=unavailable` | 审计接口未完成；不是“检查通过” |
| `RESIDUAL FIELD` / `FINAL RESIDUE` | 残留候选和最终仍未修复的位置 |

解码最后一条失败响应：

```bash
grep 'API RAW_BASE64' "$LOG" \
  | tail -n 1 \
  | sed 's/^.* body=//' \
  | base64 -d \
  | jq .
```

`--trace` 会显示待翻译正文摘要和失败原始响应，排查结束后应删除调试日志。插件不会记录 API Key 或 Authorization 请求头。

`正文残留中文二次补翻`开启后，残留候选会按通用文本规则批量处理；显式 no-translate、配置为保护的代码和机器内容会在最终校验中使用同一套豁免规则。


## 7. 内容类型策略

插件采用白名单策略。

后台配置中有两类文章类型：

1. 参与翻译的文章类型
2. 共享发布的文章类型

### 7.1 参与翻译

勾选后，该 post type 会被同步到目标语言站，并进入翻译队列。

适合：

- `post`
- `page`
- `wp_block`
- `wp_template`
- `wp_template_part`
- `wp_navigation`
- `guide_post`
- `knowledge_post`
- 需要多语言 SEO 的自定义文章类型

### 7.2 共享发布

这类内容不需要翻译，只需要各语言站共享发布状态或同步存在。

适合：

- 某些内部跳转链接类型
- 不需要语言差异的工具型 post type

### 7.3 不勾选

不勾选的 post type 插件不处理。

内部类型一般不建议参与翻译：

```text
attachment
revision
nav_menu_item
acf-field-group
acf-field
wp_global_styles
```

---

## 8. 分类法策略

分类法采用“参与同步分类法”白名单。

原则：

- 分类/标签名称可以按目标语言翻译。
- 分类/标签 slug 不翻译。
- 内部分类法不建议处理。
- 文章和分类关系需要同步。

slug 绝对不翻译的原因：

1. URL 更稳定。
2. 避免跨语言链接混乱。
3. 有利于后续批量维护。
4. 避免 AI 把 slug 翻成不可控字符串。

从 v0.7.8 起，文章 slug 被提升为强保护字段；从 v0.7.9 起，slug 保护改为数据库级强制锁定：自动同步、OpenAI 翻译、OpenCC 转换和重新翻译都会在 WordPress 保存动作之后再次直接回写源站 `post_name`。这可以绕过主题、SEO 插件或 WordPress 保存钩子根据译文标题重新生成 slug 的问题。已被错误翻译的 slug 可用 `wp wpmu-ml repair-slugs` 或 `wp wpmu-ml repair-one-slug` 修复。
单篇修复示例：

```bash
wp wpmu-ml repair-one-slug --post_id=12346401 --lang=en --dry-run --allow-root --skip-themes
wp wpmu-ml repair-one-slug --post_id=12346401 --lang=en --allow-root --skip-themes
```

批量修复示例：

```bash
wp wpmu-ml repair-slugs --lang=en --dry-run --allow-root --skip-themes
wp wpmu-ml repair-slugs --lang=en --allow-root --skip-themes
```


---

## 9. 翻译队列设计

### 9.1 为什么要队列

队列不是让单个任务更快，而是为了避免保存文章时拖死后台。

正确流程：

```text
保存源文章
↓
同步目标文章壳子
↓
创建 pending 翻译任务
↓
由后台按钮 / WP-CLI / cron 慢慢处理
```

如果保存文章时直接调用 API，长文章、ACF 内容或多语言同时翻译很容易导致：

- PHP 超时
- Nginx / FastCGI 502
- 后台卡死
- API 请求中断
- 数据写入不完整

### 9.2 队列状态

常见状态：

| 状态 | 含义 |
|---|---|
| `pending` | 等待处理 |
| `needs_update` | 源文更新，需要重新处理 |
| `machine_translated` | 机器翻译完成，待审核或待发布 |
| `machine_done_published` | 机器翻译完成并发布 |
| `opencc_converted` | OpenCC 转换完成 |
| `opencc_done_published` | OpenCC 转换完成并发布 |
| `manual_waiting` | 等待人工翻译 |
| `manual_done` | 人工完成 |
| `review_required` | 质检失败，需要人工复查 |
| `failed` | 失败，达到最大重试次数或严重错误 |

### 9.3 锁机制

任务处理时会写入：

```text
locked_at
locked_by
```

这样可以避免多个进程同时处理同一个任务。

如果任务意外中断，锁会等待超时后释放。后台也有“释放超时锁”。指定任务 ID 手动处理时，会优先释放该任务自己的锁。v0.7.2 起，“人工完成”和“重新翻译”会同步清理 `locked_at`、`locked_by` 和 `process_after`，避免任务状态看起来已重置但仍被旧锁挡住。

---

## 10. PHP 运行方式和性能问题

这是整个方案中非常重要的一点。

### 10.1 后台按钮

后台点击“处理队列”或勾选“立即翻译”时，走的是：

```text
浏览器
↓
wp-admin
↓
PHP-FPM
↓
插件队列处理函数
↓
OpenAI 兼容/OpenCC
↓
写数据库
```

这是网页 PHP 请求，适合少量测试，不适合长期批量翻译。

风险：

- 受 `max_execution_time` 影响
- 受 Nginx / Apache / FastCGI 超时影响
- 浏览器等待时间长
- PHP-FPM 进程会被占用
- 长文容易 502 或请求中断

### 10.2 SSH / WP-CLI

SSH 中执行 WP-CLI 时，走的是：

```text
SSH
↓
PHP CLI
↓
WordPress CLI bootstrap
↓
插件队列处理函数
↓
OpenAI 兼容/OpenCC
↓
写数据库
```

它仍然是 PHP，但不是网页 PHP-FPM，而是服务器里的 PHP CLI。

适合：

- 长文章
- 批量翻译
- 慢慢跑队列
- cron 定时任务
- 稳定处理 10 万篇以上内容

推荐正式批量翻译使用 WP-CLI。

---

## 11. 帮助选项卡和环境自检

v0.7.2 起，后台在“工具”选项卡后新增“帮助”选项卡，用于自动检查当前服务器是否满足插件运行条件。帮助页只读取环境和设置，不修改数据，也不会调用 OpenAI 翻译 API。

自动检查内容包括：

- 插件版本和 README 维护要求。
- WordPress 是否为 Multisite。
- WordPress / PHP 版本。
- PHP SAPI、`memory_limit`、`max_execution_time`。
- cURL、JSON、mbstring 扩展。
- `shell_exec`、`proc_open`、`proc_close` 等函数是否可用。
- 临时目录是否可写。
- 插件自建数据表是否存在及行数。
- OpenAI 兼容 Base 和 API Key 是否已配置。
- OpenCC 命令是否能检测到，默认 OpenCC 配置是否能完成简单转换测试。

OpenCC 相关说明：

```bash
apt update
apt install -y opencc
command -v opencc
opencc --version
```

后台“翻译引擎”里的 OpenCC 命令路径可以留空自动检测。常见路径是：

```text
/usr/bin/opencc
/usr/local/bin/opencc
opencc
```

如果 `shell_exec` 被禁用，OpenCC 当前无法执行。如果 `proc_open` / `proc_close` 被禁用，通常会影响 `wp db query` 这类需要调用外部命令的 WP-CLI 命令，但优先使用插件内置命令 `wp wpmu-ml doctor` 和 `wp wpmu-ml job` 更稳。

---

## 12. 常用 WP-CLI 命令

进入站点目录：

```bash
cd wpmu-multilingual/
```

### 12.1 单篇立即翻译

```bash
wp wpmu-ml translate-one \
  --post_id=12352024 \
  --lang=en \
  --force \
  --allow-root \
  --skip-themes
```

说明：

| 参数 | 含义 |
|---|---|
| `--post_id` | 源站文章 ID，也可填目标语言文章 ID |
| `--lang` | 目标语言 |
| `--force` | 强制覆盖已有目标内容 |

### 12.2 处理队列

```bash
wp wpmu-ml translate --limit=1 --lang=en --allow-root --skip-themes
```

### 12.3 指定任务处理

```bash
wp wpmu-ml translate --job_id=62 --allow-root --skip-themes
```

### 12.4 查看诊断

```bash
wp wpmu-ml doctor --allow-root --skip-themes
```

### 12.5 查看指定任务

```bash
wp wpmu-ml job --job_id=62 --allow-root --skip-themes
```

### 12.6 建议 cron

每 10 分钟处理 1 篇英文：

```cron
*/10 * * * * cd wpmu-multilingual/ && wp wpmu-ml translate --limit=1 --lang=en --allow-root --skip-themes >/dev/null 2>&1
```

更稳的方式是每种语言分开设定不同频率。

---

## 13. 单篇指定翻译逻辑

后台“单篇指定翻译”有两个模式。

### 13.1 不勾选“立即翻译”

只做：

```text
创建/重置 pending 队列任务
不调用 API
不触发后台事件
不自动加锁
```

适合长文章和正式批量流程。

### 13.2 勾选“立即翻译”

会在当前后台请求中马上调用 API。

适合短文测试，不建议大规模使用。

---

## 14. 当前真实接入的翻译方式

v0.7.19 起，后台选项卡统一命名为“翻译引擎”。后台只显示已经真实可用或通过扩展接口注册的翻译方式，不再显示未接入的占位引擎。

默认翻译引擎下拉只显示：

| 翻译方式 | 状态 | 说明 |
|---|---|---|
| 人工翻译 | 已接入 | 队列只标记等待人工处理，不自动改写正文 |
| OpenAI 兼容 | 已接入 | 使用 OpenAI 兼容 Chat Completions 接口，支持规则、术语、ACF、SEO、结构保护和本地 QA |
| Agent API | 已接入 | 内部值 `agent`；外部 Agent 通过 REST API 读取共用规则、领取队列任务、获取带 `translation_rules` 的结构化 payload、回传译文，由插件校验并写回目标站 |

OpenCC 不再作为“默认翻译引擎”显示，而是在“按目标语言设置翻译方式”中，仅对繁体相关语言显示：

| 繁体转换方式 | 内部值 | 说明 |
|---|---|---|
| 繁体台湾惯用词 s2twp | `opencc_s2twp` | 简体转台湾正体惯用词，默认推荐 |
| 繁体台湾 s2tw | `opencc_s2tw` | 简体转台湾正体 |
| 繁体香港 s2hk | `opencc_s2hk` | 简体转香港繁体 |
| 繁体通用 s2t | `opencc_s2t` | 简体转通用繁体 |

v0.8.2 起，`agent` 是正式内置翻译引擎，不再映射为 OpenAI；OpenAI 辅助类已改名为 `class-wpmu-ml-openai-helper.php`，避免和 Agent 引擎混淆。旧 OpenCC 引擎记录仍建议清空队列后重新生成任务。

Agent API 的最小调用流程：

```text
1. 源站文章更新后，队列表生成 engine = agent 的任务
2. 外部 Agent 请求 GET /wp-json/wpmu-ml/v1/agent/health 测试连接和 Key
3. 可选：请求 GET /wp-json/wpmu-ml/v1/agent/rules?target_lang=es 查看内置母语化原则、有效目标语言、共用 Skill、术语库和排除字段
4. 外部 Agent 请求 GET /wp-json/wpmu-ml/v1/agent/next 获取待处理任务
5. 外部 Agent 请求 POST /wp-json/wpmu-ml/v1/agent/claim 领取任务并获得 claim_token
6. 外部 Agent 请求 /wp-json/wpmu-ml/v1/agent/payload 获取结构化字段；payload 会内嵌 translation_rules
7. 外部 Agent 按 translation_rules 翻译 fields[*].source，并按相同 field_id 返回 fields[*].target
8. 外部 Agent 请求 POST /wp-json/wpmu-ml/v1/agent/result 回传结果
9. 插件校验 source_hash、field_id、HTML/区块/短代码/URL 结构后写入目标文章和可翻译 meta
10. 长任务可调用 /agent/heartbeat 续期；放弃任务调用 /agent/release；失败调用 /agent/fail
```

所有 Agent API 请求都必须带：

```http
Authorization: Bearer 你在后台生成的 Agent API Key
```

Agent payload 内置字段范围：`post_title`、`post_excerpt`、`post_content`，以及在现有元字段翻译开关启用时可进入翻译链路的 SEO meta、ACF/自定义字段文本、序列化数组中的可翻译文本、HTML 片段和 JSON 字符串。v0.8.17.11 起，payload 新增 `translation_rules`，与 `/agent/rules` 返回同一套共用配置，包括内置母语化原则、有效目标语言标签、全站 Skill、原始术语库、按目标语言筛选后的有效术语，以及排除自定义字段列表。模型和执行流程仍由外部 Agent 管理，WordPress 插件负责规则来源、任务、字段抽取、结构校验和写回。Agent 不直接登录后台，也不直接修改 WordPress 数据库。

v0.8.11 起新增 **Agent Tools API**，与文章翻译队列 API 分开。它用于让本地 Agent 翻译你手动指定的分类、标签、样板、模板等非队列内容。工具接口使用单独的 Agent Tools API Key，不复用文章队列 Key。第一版支持：

```text
GET  /wp-json/wpmu-ml/v1/agent-tools/health
GET  /wp-json/wpmu-ml/v1/agent-tools/types
POST /wp-json/wpmu-ml/v1/agent-tools/list
POST /wp-json/wpmu-ml/v1/agent-tools/read
POST /wp-json/wpmu-ml/v1/agent-tools/write
```

支持对象：

| object_type | 用途 | 字段 |
|---|---|---|
| `term` | 分类、标签、自定义 taxonomy term | `term:name`、`term:description`、`term:slug` |
| `post_like` | 样板、可复用区块、模板、模板部件等 post-like 对象 | `post_title`、`post_excerpt`、`post_content` |

Agent Tools API 不创建翻译队列任务，不处理文章自动同步，只做“读取指定对象字段 → Agent 本地翻译 → 写回指定目标对象”。插件仍负责对象定位、关联表查询、WordPress 写回和日志记录；模型和执行流程由外部 Agent 管理，共用翻译规则与术语可使用主 Agent API Key 从 `/agent/rules` 读取。

DeepL、腾讯云机器翻译和自定义 API 暂未接入真实翻译逻辑，因此不会在后台显示。后续如果通过 `wpmu_ml_registered_translation_engines` 扩展接口注册了真实引擎，例如 DeepL，后台才会自动显示。

---

## 15. OpenAI 兼容 + 模型翻译

OpenAI 翻译使用 OpenAI 或兼容 Chat Completions 的 API。这里的“AI”不是让模型自由发挥，而是把站点规则、术语库、WordPress 结构保护、代码智能翻译、字段感知翻译、SEO 母语化表达和本地 QA 组合成一个稳定翻译流程。

### 15.1 翻译定位：忠实且母语化的网站翻译

这里的“母语化”描述的是译文质量，而不是让模型自由改写。OpenAI 兼容引擎和 Agent API 都会收到同一套内置规则：译文应自然、可信、符合目标语言用户的真实表达习惯，像当地母语技术作者原创，不应保留中文语序、逐字对应、陌生搭配或明显机器翻译腔。

```text
必须保持：原意、事实、品牌、产品名、价格、日期、数字、URL、代码和 WordPress 结构
允许调整：语序、拼写、词汇、语法、标点、UI 短语、标题表达、SEO 搜索用语和地区习惯
质量目标：目标语言母语读者读起来顺畅、自然、可信，不像从中文直译
```

从 v0.8.17.12 起，语言站点不再提供“AI 本地化说明”自由文本框。母语化质量要求属于所有语言都必须遵守的系统级翻译原则，已经写入内部 OpenAI 系统提示词，并通过 Agent API 的 `built_in_rules` 和每个 payload 的 `translation_rules` 统一提供，不需要为每种语言重复维护。

“WordPress 站点语言”和“AI 实际翻译目标”仍然分开：

| 配置 | 来源与用途 | 翻译优先级 |
|---|---|---|
| AI 翻译标签 `translation_locale` | 网络后台“语言站点”按语言选填，使用 BCP 47，例如 `es-419`、`pt-BR`、`en-US`；填写后决定文章实际采用的语言/地区变体 | 最高 |
| AI 语言名称 `translation_language_name` | 根据有效 AI 翻译标签通过 PHP Intl/CLDR 自动获取；未安装 Intl 时安全回退为标签或 WordPress 语言名称 | 同步传入 |
| WordPress `Locale` | 自动读取对应分站“设置 → 常规 → 站点语言”，例如 `es_MX`、`pt_BR`；AI 翻译标签留空时，同时作为翻译目标的回退值 | 回退 |
| 语言名称 `language_name` | 根据 WordPress Locale 自动获取，便于识别站点后台语言 | 辅助 |
| `hreflang` | SEO 替代语言标签，只用于搜索引擎，不决定翻译地区变体 | 不参与覆盖 |
| 语言标识 `lang_slug` | 队列、路由、URL 和术语库的简短别名，例如 `en`、`es`、`pt` | 辅助 |

西班牙语全球技术站推荐：

```text
语言标识：es
WordPress Locale（自动）：es_MX
AI 翻译标签：es-419
AI 语言名称（自动）：Latin American Spanish
hreflang：es
```

模型会以 `es-419` 为实际翻译目标，并自动执行母语化翻译原则；`es_MX` 只负责 WordPress 后台语言包和留空回退，`hreflang="es"` 只用于 SEO。其他语言也是同一逻辑：需要覆盖分站 Locale 时填写 AI 翻译标签，不需要覆盖时留空。

当前项目仍以 `/zh-hans/` 简体中文为权威源站，代码块中文片段提取等专项保护也主要针对中文源文；本次通用化重点是让**目标语言和地区变体**由站点配置驱动。

核心设置包括：

- API Base URL
- API Key
- 模型名称
- Temperature
- 请求超时
- 单次请求/分段字符上限（`0` 表示不按字符数拆批）
- 单批片段数量上限（`0` 表示不按片段数量拆批）
- 翻译 ACF/postmeta 字段
- 翻译 SEO 字段
- 字段感知翻译，已内置开启
- 翻译模式
- AI 质检
- “翻译规则”子选项卡中的 AI 翻译规则 / Skill
- “翻译规则”子选项卡中的术语库
- “翻译规则”子选项卡中的排除自定义字段

长文章默认会分段处理，避免一次请求太长导致超时。字符上限和片段数量上限独立生效，任一达到非零上限就会拆批。填写 `0` 表示关闭对应限制；两项均为 `0` 时，正文中的标题和普通文本按原顺序合并为一个主请求，使模型尽量一次看到完整正文上下文。标题/摘要、正文、postmeta 仍属于不同字段流程；模型漏字段时仍会进入小批恢复，避免保存中外文混排结果。

性能优先时，数值越大，请求次数越少，但单次失败后的重试代价越高。普通分批模式可从 `8000` 字符、`30` 个片段起步；需要整篇正文主请求时可将两项都设为 `0`，并确认所用模型的上下文和最大输出能力足够。

推荐测试设置：

```text
每批处理数量：1
请求超时：300 - 600 秒
单次请求/分段字符上限：8000 起步，稳定后提高到 12000+；整篇正文模式填 0
单批片段数量上限：默认 30；整篇正文模式填 0
失败重试次数：3
重试间隔：10 分钟
```

---

## 16. 字段感知的母语化翻译

v0.7.5 起，OpenAI 翻译默认开启字段感知翻译。插件不会把所有内容都当作普通正文处理，而是按字段类型给 AI 不同任务说明。

### 16.1 文章标题、摘要

文章标题按 H1 / SEO 敏感标题处理：

```text
保持原文含义、搜索意图、核心关键词、品牌、价格、日期、数字不变
允许根据目标语言习惯调整语序和表达
避免逐字硬翻译
不生成或翻译 slug；目标文章 URL slug 始终回写源站 post_name
```

摘要按自然简介处理，不新增原文没有的信息。

### 16.2 H1 / H2 / H3 / H4

正文 HTML 中的 `<h1>`、`<h2>`、`<h3>`、`<h4>` 会被单独识别为内容标题字段。

处理原则：

```text
保持标题层级不变
保持核心意思和关键词不变
按目标语言自然表达
不新增事实、不夸大营销
保留数字、价格、日期、品牌、产品名
```

例如：

```text
腾讯云服务器优惠活动汇总
```

英文不应硬翻成：

```text
Tencent Cloud Server Preferential Activity Summary
```

更推荐母语化表达为：

```text
Tencent Cloud Server Deals and Promotions
```

### 16.3 SEO title / description / keywords

常见 Rank Math、Yoast、AIOSEO 的 SEO 字段会按 SEO 类型处理：

| 字段类型 | 处理方式 |
|---|---|
| SEO title | 保持搜索意图和关键词，改成本地用户自然会搜索的标题 |
| SEO description | 写成自然搜索结果摘要，不新增承诺 |
| SEO keywords / focus keyword | 转成目标语言真实关键词短语，不翻译成句子 |

这部分不是逐字翻译，而是“保持意思不变的母语化表达”。

---

## 17. 代码块和行内 code 策略

v0.7.6 起，OpenAI 翻译默认采用“代码智能翻译”策略：不再把 `<pre><code>` 代码块整体视为完全不可翻译内容，而是在保护代码结构的前提下翻译其中的人类可读文字。v0.7.10 起，代码块进一步改为片段级处理：插件先提取中文注释、字符串 value、实体 HTML 注释和简单标签文本，再让 OpenAI 翻译这些短片段并原位替换。v0.7.12 起，字符串 value 不再整段交给 AI，而是只提取其中的中文自然语言片段，保留原始转义符和行结构。v0.7.13 起，代码块进入“逐行硬锁”模式：插件先按真实换行把代码块切成行，只在每个原始行内提取中文注释、字符串片段和 HTML 示例文本，AI 只能返回短片段译文，最终由插件在原始行内原位替换。v0.7.14 起，进一步把代码块保护提前到 WordPress block JSON 翻译之前，并跳过 `wp:code` / `wp:preformatted` block 注释，避免 code/content 字段绕过代码行锁；同时新增单行赋值意外换行修复，专门处理 `$longLine1` 这类原文单行赋值被拆开的情况。v0.7.15 起，新增拼接操作符误换行修复，用于处理 `.\n(`、`.\nfunction_call(...)` 等由翻译过程产生的意外拆行。v0.7.16 起，在正文恢复 protected 代码块后再次按源站/目标站 `<pre>` 代码块做最终回写校正，并兼容 syntax-highlight span 包装后的拼接符识别。v0.7.18 起，删除“代码块整块回滚为源文”的策略，改为“片段级回滚”：翻译成功的注释、字符串和 HTML 示例文本会继续原位替换，某个片段或某个修复步骤失败时只放弃该片段或该修复，不再把整个大代码块恢复成中文。AI 翻译规则会注入 prompt，但代码块安全主要由片段提取器、原位替换器和最终行结构校验保证。v0.7.19 起，OpenCC 简繁转换也使用逐行硬锁的代码片段转换，并额外保护 `\n`、`\t`、`\r`、`\0`、`\x0B` 等反斜杠转义序列。v0.7.20 起，OpenCC 写入目标文章时统一使用 `wp_slash()`，避免 WordPress 保存链路里的 `wp_unslash()` 把代码块中的 `\n`、`\t`、`\r`、`\0`、`\x0B` 保存成普通字母 `n`、`t`、`r`、`0`、`x0B`。v0.7.21 起，OpenCC 代码片段回填会保留源片段前后空格，避免把 `"处理结果: "`、`" | 计算结果: "`、`" 毫秒"` 这类字符串输出格式转换成缺空格的形式。

v0.7.7 进一步改为“代码片段级翻译”：插件会优先提取代码块里的中文注释、SQL 注释、字符串 value、语法高亮 `<span>` 文本节点，再把这些短片段交给 OpenAI 翻译，最后原位替换回代码，尽量避免 AI 重排整段代码或拆长行。

```text
保持不变：代码结构、变量名、函数名、类名、数组/对象 key、命令、URL、路径、HTML 标签、Gutenberg block、短代码、技术标识、语法符号
需要翻译：代码注释、字符串 value、数组/对象 value、代码示例中的中文提示语
行内 code：纯代码不翻译；混合中文说明时只翻译中文说明部分
```

例如 PHP 代码：

```php
$testString = "这是一个普通的字符串变量";
$arrayData = [
  'name' => '测试数据',
  'type' => '演示',
];
```

英文目标语言推荐变为：

```php
$testString = "This is a regular string variable";
$arrayData = [
  'name' => 'Test data',
  'type' => 'Demo',
];
```

注意：`name`、`type` 这类 key 不翻译；只翻译右侧 value。

例如：

```html
<code>@import</code>
<code>wp_enqueue_style()</code>
<code>Vary 特性</code>
```

推荐结果：

```html
<code>@import</code>
<code>wp_enqueue_style()</code>
<code>Vary feature</code>
```

相关策略已转为 AI 内置默认行为；主要行为应交给 AI 翻译规则和结构保护逻辑控制。

---

## 18. AI 翻译规则和术语库

v0.7.4 起继续保留术语库，因为高频固定译法不适合完全交给模型临场判断。AI 翻译规则适合写“行为要求”，术语库适合写“固定映射”。

### 18.1 AI 翻译规则 / Skill

AI 翻译规则支持普通文本，也支持 Markdown 写法。插件不会把 Markdown 输出到前台，而是把它作为规则注入提示词。

该字段已经实际生效：保存后会写入 network option，并在插件内部 OpenAI 兼容引擎的每一次系统提示词中注入。它是**全站、全语言通用规则**，适合品牌保护、代码结构、SEO、格式和总体语气；不要把 `es-419` 这类只属于某一种目标语言的地区变体写在这里。语言地区变体应配置在“语言站点 → AI 翻译标签”；自然、可信、像母语作者原创的质量要求由系统提示词统一执行。v0.8.17.11 起，Agent API 也会通过 `/agent/rules` 和每个 payload 的 `translation_rules` 读取同一份 Skill；OpenCC 和人工翻译仍不会使用它。

示例：

```markdown
- LikaCloud 不翻译。
- VPS、CPU、RAM、SSD、CDN、DNS、SSL、API、HTTP、HTTPS 保留英文。
- 云服务器翻译为 cloud server。
- 独立服务器翻译为 dedicated server。
- 优惠码翻译为 coupon code。
- 文章 slug、分类 slug、标签 slug 不翻译。
- 保持 HTML、短代码、URL、代码结构不变；代码中的注释和字符串 value 可翻译。
```

### 18.2 术语库格式

新格式为：

```text
原词 | 语言 | 译法
```

示例：

```text
云服务器 | en | cloud server
独立服务器 | en | dedicated server
优惠码 | en | coupon code
虚拟主机 | en | web hosting
LikaCloud | all | LikaCloud
```

语言列不再限制为固定列表，可写以下任一种：

- 语言标识：`en`、`pt`、`nl`
- WordPress Locale：`en_US`、`pt_PT`、`pt_BR`
- hreflang / BCP 47：`en-US`、`zh-Hant`
- 通用规则：`all`、`*`、`any`

插件会把语言标识、AI 翻译标签、AI 语言名称、WordPress Locale、hreflang 和主语言代码统一匹配。例如目标站的 WordPress Locale 为 `es_MX`、AI 翻译标签为 `es-419` 时，`es`、`es-419` 和 `es_MX` 都可用于有针对性的术语规则；`es-ES` 不会误命中。以后新增语言无需改术语库解析代码。也支持 Tab 分隔。不再兼容旧的 `原词 => 译法` 格式，测试期请统一使用新格式。

Agent API 的 `/agent/rules` 会同时返回 `glossary.raw` 和 `glossary.effective_for_target`；任务 payload 里的 `translation_rules.glossary` 使用相同结构，因此外部 Agent 不需要另写一份术语库。

### 18.3 Agent API 读取共用规则

使用与任务接口相同的 Agent API Key：

```bash
curl -s \
  -H "Authorization: Bearer API_KEY" \
  "https://example.com/wp-json/wpmu-ml/v1/agent/rules?target_lang=es"
```

主要返回：

```text
rules.site_rules
rules.target_language.translation_locale 与 rules.built_in_rules
rules.glossary.raw
rules.glossary.effective_for_target
rules.excluded_custom_fields.effective_patterns
rules.rules_hash
```

每次 `/agent/payload` 也会返回相同的 `translation_rules` 对象。`/agent/rules` 适合预加载或单独检查配置，payload 内嵌规则适合确保每个任务都携带当时的有效设置。

### 18.4 质量检查开关

后台质检只保留一个选项：

```text
开启 AI 质量检查：开启 / 关闭
```

无论该选项是否开启，PHP 都会检查字段是否完整返回、必填译文是否为空、WordPress/HTML/JSON 结构、占位符以及数据库写回完整性。开启后，插件会额外把疑似残留、数字差异、长度差异和其他高风险信号交给 AI 判断；这些信号只是提示，AI 返回 `keep` 时不会被 PHP 改判。

---

## 18.5 翻译前污染清理与响应恢复

插件会在内存中清理已保存进正文或 ACF 富文本字段的浏览器翻译结构污染，不修改源文章数据库内容。当前兼容 Immersive Translate、Chrome/Google Translate、Firefox/Bergamot、Microsoft Edge `_mst*` 属性、若干常见翻译扩展前缀以及剪贴板 `StartFragment/EndFragment` 标记。普通站点自有标签和合法 `.notranslate` 区域不会因为名称相似而被普遍删除。

OpenAI 兼容接口返回值也使用容错 JSON 提取，可跳过 BOM、PHP Warning、HTML/Markdown 包装和前后调试输出。使用 `--trace` 时，结构清理会显示 `POLLUTION CLEANUP`，受污染响应恢复会显示 `RESPONSE CLEANUP`。

注意：清理只能移除可识别的结构、属性和包装。如果浏览器翻译已经直接覆盖了原始文字且没有保留源文，插件无法凭空恢复被替换前的文本；这类源文章仍应从历史版本或备份恢复。

---

## 18.6 长正文一致性与本土化

正文按完整段落、标题、列表项和表格单元等语义单元组织，行内 `<strong>`、`<a>`、`<span>` 等标签会转换为受控占位符。占位符缺失、重复或结构损坏会被 PHP 拦截，不会写入目标文章。

0.9.6.3 继续使用固定内部安全分批参数：正文源文字符上限 6,000、单批字段上限 200、adaptive AI 候选上限 24、质检字段上限 80、质检字符上限 12,000。后台不再用字符或字段数量控制质量规则。

目标语言本土化、术语、语气和编辑判断由主翻译与可选 AI 质量检查完成。PHP 不会因为疑似残留、数字差异或长度差异自行补翻或覆盖 AI 的 `keep`。

---

## 19. AI 质量检查与 PHP 完整性检查

### PHP 完整性检查（始终开启）

PHP 只负责以下硬性条件：

- 请求字段是否全部返回；
- 源字段非空时，目标字段是否为空；
- Gutenberg、HTML、JSON、短代码、URL 等受保护结构是否完整；
- 受控占位符和机器标记是否完整；
- 文章标题、摘要、正文、状态、slug 和元数据写入后，回读值是否与预期一致。

这些项目失败时会停止写入或进入失败状态，因为继续保存可能造成数据缺失或结构损坏。

### AI 质量检查（后台可开关）

开启后，插件把疑似源文残留、数字差异、长度差异、术语不一致和其他高风险信号作为提示发送给 AI。提示本身不构成错误：AI 返回 `keep` 时保留当前译文，PHP 不会强制改为 `rewrite`；只有 AI 明确返回 `rewrite:原因` 才执行定点修复。

关闭后不发起第二次 AI 质量检查，但主翻译和 PHP 完整性检查继续运行。

故意不翻译的广告、法律声明或第三方嵌入区块，建议用下面的显式标记包住：

```html
<!-- wpmu-ml:no-translate:start -->
<div>这里保持源文，不参与翻译和中文残留质检</div>
<!-- wpmu-ml:no-translate:end -->
```

简单元素也支持 `translate="no"`、`data-no-translation`、`notranslate`、`no-translate` 或 `wpmu-ml-no-translate`，但复杂嵌套区块优先使用上面的注释成对标记。

后台“翻译规则 → 排除翻译标签 / 选择器”还支持按元素选择器集中配置，每行一个：

```text
pre
.jd-cloud-ad
#fixed-banner
div.key-points
[data-role="vendor"]
class="legacy-box"
id="legacy-banner"
```

支持标签、class、ID、标签与 class/ID 组合、属性存在和属性值匹配。匹配元素及其内部内容不会发送给 AI，也不会计入中文残留质检。为了避免误排除，不支持后代选择器、`>`、伪类或通配符；不要填写过于宽泛的 `div`、`p`、`span`。

---

## 20. SEO 字段翻译

插件支持翻译常见 SEO 插件字段：

- Rank Math
- Yoast SEO
- AIOSEO

主要处理：

- SEO title
- SEO description
- SEO keywords / focus keyword
- OpenGraph title/description
- Twitter title/description

原则：

- SEO 字段要使用自然的母语化表达，不要机械直译。
- SEO title 保持搜索意图和核心关键词，改成目标语言中自然、可搜索的标题。
- SEO description 保持原意，写成自然搜索摘要。
- SEO keywords / focus keyword 转成目标语言真实关键词短语，不翻译成句子。
- slug 不翻译。
- 分类 slug 不翻译。
- meta description 要适合搜索结果展示。

---

## 21. ACF / postmeta 翻译

插件可翻译 ACF 和 postmeta 中的文本字段。

原则：

- 文本、textarea、WYSIWYG 等可翻译。
- 图片 ID、附件 ID、URL、颜色、布尔值、数字、布局配置不翻译。
- JSON 或序列化结构需要保持结构不变，只翻译文本叶子节点。

建议先在少量文章测试 ACF 翻译，再批量开启。

---

## 22. 发布策略

不要所有内容都逐篇人工检查，也不要所有内容无脑自动发布。

推荐分层：

| 内容类型 | 推荐策略 |
|---|---|
| 首页、核心页面、商业页面 | AI + 质检，人工审核后发布 |
| 高价值教程 | AI + 质检，通过后可发布或待审核 |
| 普通文章 | 普通翻译 + QA，通过后自动发布 |
| 繁体中文 | OpenCC 转换，通过后自动发布 |
| 异常内容 | review_required，人工复查 |

10 多万篇内容不适合逐篇人工发布，应依靠机器翻译 + 质检 + 抽样复查。

---

## 22. 推荐测试流程

### 22.1 确认 OpenAI 设置

```bash
cd wpmu-multilingual/

wp site option get wpmu_ml_settings --format=json --allow-root --skip-themes | php -r '
$j=json_decode(stream_get_contents(STDIN),true);
echo "openai_agent_mode: ".($j["openai_agent_mode"] ?? "NULL").PHP_EOL;
echo "openai_agent_quality_check: ".($j["openai_agent_quality_check"] ?? "NULL").PHP_EOL;
echo "openai_agent_fail_on_qa: ".($j["openai_agent_fail_on_qa"] ?? "NULL").PHP_EOL;
echo "openai_agent_site_rules:\n".($j["openai_agent_site_rules"] ?? "").PHP_EOL;
echo "openai_agent_terms:\n".($j["openai_agent_terms"] ?? "").PHP_EOL;
'
```

### 22.2 跑单篇翻译

```bash
wp wpmu-ml translate-one \
  --post_id=12346518 \
  --lang=en \
  --force \
  --allow-root \
  --skip-themes
```

v0.7.1 起，命令完成后会直接输出：

- 任务表名
- 任务状态
- 任务引擎
- 翻译模式
- 任务说明 / QA 结果

### 22.3 查看任务

```bash
wp wpmu-ml job --job_id=62 --allow-root --skip-themes
```

### 22.4 诊断表和设置

```bash
wp wpmu-ml doctor --job_id=62 --allow-root --skip-themes
```

---

## 24. 故障排查

### 24.1 `wp option get wpmu_ml_settings` 报错

这是正常的。插件设置是网络级设置，应该用：

```bash
wp site option get wpmu_ml_settings --format=json --allow-root --skip-themes
```

不是：

```bash
wp option get wpmu_ml_settings
```

### 24.2 查任务表没结果

先用：

```bash
wp wpmu-ml doctor --allow-root --skip-themes
```

它会输出当前插件认为的真实表名、表是否存在、每张表行数。

### 24.3 后台按钮能跑，但长文容易失败

后台按钮走 PHP-FPM，不适合长期批量。

正式批量请用：

```bash
wp wpmu-ml translate --limit=1 --lang=en --allow-root --skip-themes
```

### 24.4 任务被锁住

后台可以点“释放超时锁”。

CLI 可指定任务处理：

```bash
wp wpmu-ml translate --job_id=62 --allow-root --skip-themes
```

### 24.5 API 超时

建议：

```text
每批处理数量：1
分段字符上限：3000 - 5000
请求超时：300 - 600 秒
失败重试次数：3
重试间隔：10 分钟
```

---

## 25. 版本记录

完整版本记录已移到独立文件，避免 README 过长：

- [CHANGELOG.md](CHANGELOG.md)

---

## 26. 当前推荐设置

正式测试建议：

```text
默认翻译引擎：OpenAI 兼容或 Agent API
繁体目标语言：优先选择 繁体台湾惯用词 s2twp；需要外部 Agent 工作流时选择 Agent API
每批处理数量：1
请求超时：300 - 600 秒
翻译 ACF 字段：开启
翻译 SEO 字段：开启
开启 AI 质量检查：按发布要求选择；正式发布建议开启
PHP 本地完整性检查：始终开启，无需配置
```

批量运行方式：

```bash
wp wpmu-ml translate --limit=1 --lang=en --allow-root --skip-themes
```

不要用后台网页按钮长期跑 10 万篇内容。后台按钮适合短文测试和少量人工干预，正式批量建议用 WP-CLI 或系统 Cron。

---

## 27. 下一步建议

后续可以继续增强：

1. 真正多步骤 AI：Analyze → Translate → QA → Repair → Write。
2. 继续优化翻译路由规则，例如模型档案/Profile、导入导出路由规则。
3. 按语言设置不同发布策略。
4. 术语库支持导入/导出 CSV。
5. QA 结果单独建表，便于筛选问题文章。
6. 长文章分段上下文摘要，减少前后术语不一致。
7. 后台增加“抽样检查”和“批量发布通过 QA 的文章”。

当前 v0.9.8.18 建议先运行 `wp wpmu-ml audit-relations --summary --allow-root --skip-themes` 查看身份、fallback 草稿和 slug 冲突汇总，再使用 `--target_blog_id`、`--source_post_id`、`--limit` 和 `--offset` 查看明细。严格关系恢复可使用 `wp wpmu-ml reconcile-relations --target_blog_id=目标站ID --limit=500 --allow-root --skip-themes` 预览，但不要在未检查候选前增加 `--apply`。生产破坏性关系重建保持禁用。term 本体同步只对新增、编辑、删除事件生效，不自动批量处理历史 term。term name/description 翻译开关默认关闭，开启后才会在同步后翻译对应字段。站点编辑器对象 `wp_template`、`wp_template_part`、`wp_navigation` 默认纳入文章关系和翻译范围。Agent API result 写回后会兼容 WordPress 实体标准化，同时继续严格保护结构；ACF/Gutenberg 注释 JSON 中的人类可读文本通过独立 `gutenberg` 字段翻译和写回。未发布语言显示策略统一在“语言切换 → 基础设置”中配置。OpenCC 只在源站识别为简体中文且目标语言为繁体中文时自动提供或 fallback。后台公开说明已清理为面向插件管理员的正式文案。
