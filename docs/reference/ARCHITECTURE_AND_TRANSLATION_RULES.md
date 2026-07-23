# WPMU Multilingual: Architecture and Translation Rules

> 本文件是插件的长期维护约束。新增功能、修复翻译问题或调整校验逻辑时，应先检查是否符合这里的通用规则，禁止继续把所有逻辑堆入 `includes/core/class-wpmu-ml-core.php`。

## 1. 本次结构调整

历史核心类曾达到约 11,800 行、305 个方法，混合了后台界面、队列、同步、OpenCC、OpenAI、质量校验、语言菜单等职责。本次调整只进行**等价拆分**：

- `includes/core/class-wpmu-ml-core.php` 仅保留类常量、属性、单例、构造函数、Hook 注册和 trait 组合。
- 原有公共方法、私有方法、方法名、参数、可见性保持不变。
- 业务实现移动到 `includes/core/traits/`，由 `includes/core/traits/bootstrap.php` 统一加载。
- 本次结构调整不主动改变数据库结构、Hook 名称、option、meta、任务状态或翻译行为。

当前核心模块：

| 文件 | 职责 |
|---|---|
| `trait-wpmu-ml-core-foundation.php` | 安装、升级、表结构、设置、诊断、基础工具 |
| `trait-wpmu-ml-core-admin-ui.php` | 网络后台页面、表格、选项卡和帮助界面 |
| `trait-wpmu-ml-core-engine-routing.php` | 翻译引擎注册、规范化和路由选择 |
| `trait-wpmu-ml-core-queue.php` | 队列、任务调度、锁、任务状态和提示上下文 |
| `trait-wpmu-ml-core-opencc.php` | OpenCC 转换流程 |
| `trait-wpmu-ml-core-openai-translation.php` | OpenAI 主翻译、正文规划、残留补翻和目标语言润色 |
| `trait-wpmu-ml-core-openai-content.php` | HTML、可见文本、代码块、Gutenberg 区块和保护区域 |
| `trait-wpmu-ml-core-openai-metadata.php` | 文本片段、自定义字段、SEO/meta 和字段感知翻译 |
| `trait-wpmu-ml-core-openai-quality.php` | 语言审计、结果验收、文章编辑审校和占位符校验 |
| `trait-wpmu-ml-core-openai-client.php` | API 请求、响应解析、重试、追踪和文本分块 |
| `trait-wpmu-ml-core-admin-actions.php` | 后台表单保存、站点语言配置和输入规范化 |
| `trait-wpmu-ml-core-sync.php` | 文章关系、同步、目标文章写入、状态和入队 |
| `trait-wpmu-ml-core-language-switcher.php` | hreflang、语言菜单、短代码和后台工具栏 |

## 2. 代码放置规则

### 2.1 核心壳文件

`includes/core/class-wpmu-ml-core.php` 只允许包含：

1. 类常量与属性；
2. `instance()`、构造函数和统一 Hook 注册；
3. trait 引用；
4. 极少量无法独立归类的装配代码。

不得再把完整业务流程直接追加到核心壳文件。

### 2.2 新增逻辑的归属

- 新增后台页面或 UI：优先放入后台类；过渡期放入 `admin-ui` trait。
- 新增队列、锁、重试：放入 `queue` trait 或后续独立 Queue Service。
- 新增 OpenAI 请求协议与响应兼容：放入 `openai-client` trait。
- 新增正文提取、HTML/代码处理：放入 `openai-content` trait。
- 新增质量校验和定向修复：放入 `openai-quality` trait。
- 新增 meta/ACF/SEO 字段处理：放入 `openai-metadata` trait。
- 新增文章同步、关系、目标写回：放入 `sync` trait。
- 可复用且不依赖核心类状态的逻辑，应优先做成独立类，而不是继续增加 trait。

### 2.3 文件规模

- 单个方法超过约 150 行时，应优先拆成可命名的小步骤。
- 单个 trait 持续超过约 1,500 行时，应评估拆成独立服务类。
- 新功能不得以“先塞进 core，以后再拆”为默认做法。

## 3. 翻译系统的通用原则

### 3.1 模型负责语义，规则负责调度

规则层只负责判断：

- 哪些内容属于人类可读文本；
- 哪些内容必须保护；
- 哪些片段应携带上下文交给模型；
- 哪些结果需要重试、定向修复或人工检查。

规则层不应建立不断增长的逐词替换库。禁止用下面这种方式作为主要修复：

```text
2核2G3M -> 2 vCPU / 2 GB RAM / 3 Mbps
68元 -> ¥68
某个固定短语 -> 某个固定译文
```

单个例子只能用于测试，不能成为核心算法。

### 3.2 人类可读内容默认可翻译

只要可见内容含有源语言信息，即使与数字、拉丁字母、单位或型号混合，也应默认进入翻译候选。例如：

```text
2核2G3M
68元/年
100GB流量/月
首年6.5元/月
A区节点
第2代
```

不能因为文本较短、只含一个源语言字符、位于 `<code>` 标签或包含数字，就直接当作机器标识符跳过。

### 3.3 只有高置信度机器内容才保护

以下内容通常应原样保护：

- URL、域名、邮箱；
- 文件路径、命令、变量名、函数名；
- 代码语法、短代码结构、JSON 键；
- CSS class、HTML id、数据库键；
- 明确标记为 `no-translate` 的区域。

是否保护应由内容形态和上下文共同决定，不能仅根据 HTML 标签名判断。`<code>2核2G3M</code>` 可能是给读者看的规格，而不是程序代码。

### 3.4 短片段必须携带上下文

对规格、价格、按钮、表格单元格和短标签，调用模型时应尽量提供：

- 源文前后片段；
- 当前文章主题；
- 字段类型；
- 源语言与目标语言；
- 必须保留的数字、占位符和结构。

模型负责根据上下文判断 `G` 是内存、磁盘还是流量，`M` 是带宽还是文件大小；插件不应靠固定词表猜测。

### 3.5 本土化而非机械替换

翻译提示词应要求：

- 保留事实、数值、产品名和技术含义；
- 将源语言单位、货币、周期和紧凑规格转换为目标语言惯用表达；
- 生成自然、可发布、符合目标语言读者习惯的文本；
- 不因片段像“技术 token”就保留其中的源语言字符；
- 不新增原文没有的事实、承诺或参数。

### 3.6 四个阶段必须采用一致规则

以下阶段必须共享同一套分类原则：

1. 正文/字段提取；
2. 保护区域识别；
3. 残留扫描与定向补翻；
4. 最终发布校验。

禁止出现：

```text
提取阶段认为“不需要翻译”
残留阶段认为“需要保护”
最终校验又认为“绝不能保留”
```

如因业务需要允许某类内容保留，最终校验也必须使用同样的豁免依据。

### 3.7 最终校验优先局部修复

最终发现源语言残留时，流程应优先为：

```text
定位残留片段
→ 获取 HTML 位置、字段类型和前后上下文
→ 只对该片段调用模型做定向本土化
→ 保留结构并回填
→ 再次校验
→ 多次失败后才进入人工检查
```

不应因一个短片段重新翻译整篇文章，也不应只返回模糊的“接口为空或格式错误”。

## 4. API 与响应解析规则

- HTTP 200 不等于翻译成功；必须验证模型正文、键名、字段完整性和目标语言。若响应消耗了输出 Token 但没有 `content`/`output_text`，应分类为 `upstream_output_missing`，不能只写成笼统的空响应。
- 日志应区分：网络错误、HTTP 错误、外层 JSON 错误、模型正文为空、正文 JSON 错误、字段缺失、语言不合格、占位符损坏。
- 响应兼容逻辑集中在 `openai-client` 模块，禁止在不同翻译入口各写一套解析器。
- 完整原始响应仅在显式 trace/debug 模式记录，并注意正文和 API 数据可能包含敏感信息。
- 批量失败后可降级为单字段，但应记录失败字段、源文、返回摘要和拒绝原因。小字段任务应先移除空字段；单字段可使用程序包装的纯文本结果，避免强迫模型生成 JSON。
- 降级必须真正降低复杂度：完整阶段提示词失败后切换精简提示词，再切换 Chat 纯文本；Responses API 只作为最后兜底。禁止在已过长的提示词后不断追加 recovery 说明。
- 精简兜底仍必须保留事实、数值和原币种，禁止自动汇率转换或把人民币语境擅自改成美元。
- 日志应记录提示词组件长度和哈希，而不是默认明文输出完整系统提示词；这样既能诊断提示词膨胀，又减少敏感规则泄露。

## 5. 数据与兼容性规则

下列内容属于稳定接口，除非有明确迁移方案，不得随意修改：

- 类名 `WPMU_Multilingual`；
- 已注册的 WordPress Hook 与回调方法；
- network option、post meta、数据表名和状态值；
- WP-CLI 命令和参数；
- 公共方法名、参数顺序和返回类型约定；
- 旧路径加载壳。

结构重构应做到“文件位置变化，外部行为不变”。

## 6. 日志与错误信息规则

错误信息必须尽量回答四件事：

1. 哪个阶段失败；
2. 哪个字段或片段失败；
3. 为什么被拒绝；
4. 下一步应查看什么。

推荐日志信息包括：

```text
stage
job_id
field key
source excerpt
HTML/字段位置
HTTP status
finish reason
content length
missing keys
rejection reason
retry count
```

默认日志不得长期输出完整 API Key、认证头或未经处理的敏感字段。

## 7. 变更流程

每次修改翻译规则时至少执行：

1. 为问题归类，确认是提取、保护、提示词、响应解析、验收还是写回问题；
2. 优先修复通用规则，不添加单例补丁；
3. 增加类别测试，而不是只测试一个固定字符串；
4. 执行全部 PHP 语法检查；
5. 验证 `WPMU_Multilingual` 公共方法和 Hook 未丢失；
6. 在真实 WordPress Multisite 测试环境跑单篇翻译、队列、同步和语言切换；
7. 使用 `--trace` 核对 API 阶段、残留修复和最终验收；
8. 在 `CHANGELOG.md` 记录行为变化和兼容性影响。

建议测试类别：

- 普通长文与短文；
- 中英文/数字/单位混排；
- 价格、计费周期和云服务器规格；
- 表格、按钮、图注、链接和 HTML 属性；
- Gutenberg、ACF、SEO/meta；
- 真代码、行内代码、命令、路径和 JSON；
- URL、品牌、型号和版本号；
- 零宽字符、特殊空格和异常 HTML；
- 模型空响应、错误键名、部分 JSON 和语言跑偏；
- 最终残留定向修复。

## 8. 验收标准

插件的目标不是保证所有领域文本绝对零误差，而是对受支持的 WordPress 内容做到：

- 作者可以按源语言正常写作，无需提前改成目标语言术语；
- 人类可读内容不因短小或混合格式被漏掉；
- 真正的代码和机器标识符不被破坏；
- 翻译结果自然、本土化、事实忠实；
- 疑似残留能够作为 AI 提示被定位；只有 AI 明确要求 rewrite 时才局部修复；
- 无法自动处理时给出具体、可操作的错误信息；
- 新问题通过通用分类和上下文机制解决，而不是不断累加固定词条。

## 9. Release and Documentation Policy

- The plugin header, `WPMU_ML_VERSION`, `WPMU_Multilingual::VERSION`, `docs/README.md`, `docs/CHANGELOG.md`, and this rules document must be updated together for every release.
- Versioned releases must use a single synchronized semantic version across code and documentation. This release is `0.9.8.7`.
- A release is incomplete if PHP syntax checks, archive integrity checks, version consistency checks, or documentation synchronization fail.
- Trace behavior and any new fallback mode must be documented in both `CHANGELOG.md` and the diagnostics section of `README.md`.

## 0.9.8 article relation safety rule

1. `WPMU多语言插件_文章关联改进方案.md` is the normative design for the 0.9.8 article relation changes.
2. Existing relation rows are historical compatibility data and must not be renumbered, recreated, or bulk claimed by ID or slug.
3. A new target post may be reused only through an existing valid relation or matching source identity meta. Same ID and same slug are diagnostic hints only.
4. Update, translation write-back, trash, restore, delete, language switching, and hreflang resolution must validate the target identity before acting.
5. Missing identity meta on an otherwise consistent historical relation is a legacy state; conflicting identity meta is an error that must block mutation.
6. Production relation rebuilding remains prohibited until a read-only audit, duplicate-target protection, dry-run reporting, backup, and rollback procedure are implemented and verified.
7. Every implementation step and verification result must be recorded in `ARTICLE_RELATION_IMPROVEMENT_LOG.md`.
8. A newly created target with an occupied source slug must use `源slug-源文章ID-和源站id重复`, remain a draft, and retain its review metadata until a human resolves the conflict.
9. Source updates must be detected by deterministic PHP field snapshots. A translated target keeps unchanged translated fields; only changed source fields may enter incremental translation and write-back.
10. Relation workflow statuses are not translation evidence. Only a completed translation marker or an explicitly completed job may establish translated-content history.
8. When a new target has no relation or exact source meta and the source slug is occupied, create a separate draft using `source-slug-source-post-id`; never adopt or overwrite the occupant.
9. A slug-conflict fallback target must keep its fallback slug and draft status throughout queue preparation, OpenAI, OpenCC and Agent write-back until manual review.
10. Relation identity, audit and strict recovery APIs live in the dedicated relation-safety trait so synchronization file replacement cannot silently remove the safety contract.
## 0.9.4.5 performance and completeness rules

- A single requested field may accept exactly one returned alias key only when that key is one of the generic translation aliases (`text`, `translation`, `translated_text`, `result`, `output`, `content`, `value`) and its value is non-empty. Multi-field requests and responses with extra keys remain strict.
- Small structural fields must not pay the full article-prompt cost before compact fallback. Gutenberg block data, translatable attributes and code-text stages start with the compact contract.
- Quality assurance must avoid duplicate AI work. When the fast quality pipeline and whole-article editor are enabled, body batches use local integrity/source-residue checks and defer general target-language/editorial judgment to the final article audit.
- QA reliability is achieved by bounded proactive batches, not repeated oversized failures. The default QA batch is three fields, with recursive splitting only when a bounded group still fails.
- Every content surface is part of completion accounting. Gutenberg block data, body, title, excerpt and enabled metadata cannot be silently skipped while the job is reported as complete.
- A final `review_required` job is not a successful publication. CLI and administrative messages must distinguish translated-and-published, translated-but-review-required, and failed states.
- Trace output must provide enough performance evidence to compare releases: total requests, failures, fallbacks, cumulative API seconds and stage-level timing.
- Programmatic performance changes must preserve the core division of responsibility: PHP protects structure and validates deterministic invariants; the AI remains responsible for contextual interpretation and natural localization.

## 0.9.4.3 quality pipeline rules

- Human-readable WordPress body content must be translated as complete semantic units by default. API field limits are batching limits, not permission to split sentences at inline HTML.
- AI editorial verdict parsing must be defensive. Explicit negative statuses (`rewrite`, `wrong`, `fail`, `reject`, `fix`, `revise`) require repair or an explicit unresolved result; they must never become an implicit pass.
- Title, excerpt and SEO repair requests must include the source, current target and concrete issue. They must preserve numeric facts, currency identity, billing relationships, brands and search intent.
- Programmatic rules may normalize target-locale punctuation, invisible separators and obvious inline-tag spacing after translation, but they must protect code and machine-readable regions.
- The AI remains responsible for contextual meaning, professional terminology, sentence restructuring and natural localization. Programmatic rules must not grow into phrase-by-phrase translation dictionaries.
- Every release must update the plugin header version, `WPMU_ML_VERSION`, core `VERSION`, README, CHANGELOG and this document in the same change set.


## 0.9.4.8 centralized quality and latency rules

- AI QA is retained, not removed. The default pipeline concentrates semantic/editorial judgment into one or two bounded high-risk article-audit batches instead of issuing language and editor requests for dozens of individual fields.
- PHP remains responsible for deterministic invariants: non-empty output, exact requested keys, placeholder/machine-token preservation, URL/domain integrity, Gutenberg/HTML structure, numeric presence and final source-language residue.
- The centralized risk selector prioritizes prices, currencies, billing periods, dates, specifications, protected tokens, structural defects, long prose and substantial headings. Low-risk short labels and correctly translated workflow diagrams use deterministic validation.
- A normal article should plan 8–15 API requests and target 70–120 cumulative API seconds when the upstream API is healthy. More than 20 requests or 180 seconds is an over-target diagnostic condition, not the normal design point.
- Central QA and targeted repair use bounded 4-field batches; with the default maximum of 8 selected blocks, centralized QA plans at most two requests. QA failure must not recursively split into dozens of calls; strict mode records partial coverage and keeps the post as a draft.
- Central body translation defaults to 45 semantic fields per request, still constrained by the configured character limit and exact-key/placeholder checks.
- Compact body prompts must preserve the same fidelity, locale, glossary, structure and safety contract as full prompts. Full prompts remain available as fallback rather than mandatory first attempts.
- Duplicate title/excerpt approval and per-postmeta language-identification calls are skipped in centralized mode. Initial AI translation, centralized article QA and final deterministic publication checks together provide quality coverage.


## 0.9.4.7 quality and performance rules

- Translation completion and optional editorial-QA availability are separate states. A block whose translation passed mandatory structural and language checks must not be reclassified as a translation failure merely because the editor endpoint returned an empty shell.
- Single-field editorial fallback must accept equivalent positive statuses (`keep`, `ok`, `pass`, `correct`, `accept`, `no change`, `unchanged`) and explicit negative statuses (`rewrite`, `wrong`, `fail`, `reject`, `fix`, `revise`) without requiring JSON.
- Once one optional article-editor leaf exhausts compact JSON, Chat plain text and Responses fallback, a per-job circuit may skip remaining optional-editor calls. Every skipped field must be counted in quality coverage; strict QA saves a draft and reports `review_required`.
- Fast QA must use deterministic validation for low-risk short content, including successfully translated workflow diagrams with preserved arrows. AI remains mandatory for high-risk facts, prices, billing periods, technical specifications, protected placeholders and substantial prose.
- When strict QA is enabled, a target requested as `publish` must be written as a staging draft and released only after mandatory residue, structure and quality-coverage checks pass.
- SEO consistency repair may synchronize stale auto-generated title/description values, but it must preserve explicit templates and intentionally customized SEO text.
- Performance summaries must be emitted for completed, review-required and failed terminal outcomes.


## 0.9.4.6 quality and performance rules

- Required language/fact/structure checks and optional editorial polish are different reliability classes. An unavailable optional polish call must not erase a valid translation that already passed required checks.
- Long excerpts are not sent through a second duplicate editorial pass in fast mode; they remain covered by source/target audit, deterministic residue checks and final article validation.
- A Gutenberg or page-builder field named `code` is not automatically executable code. Short natural-language workflow/architecture chains containing arrows are translated as prose while structural symbols and product tokens are preserved.
- Final AI editorial review is risk based: factual/technical/long/markup-bearing blocks are reviewed by AI; very short labels and headings may use deterministic validation to reduce reasoning-model calls.


## 0.9.4.9 full-coverage centralized quality rules

- The default QA mode is `all`. Every successfully translated human-readable field is AI-checked; risk scoring affects order only.
- `risk` keeps the 0.9.4.8 sampling behavior for compatibility, and `off` uses deterministic checks only.
- QA batches are bounded by both field count and combined source/translation characters. The 0.9.4.9 defaults were 20 fields and 9000 characters.
- Identical source/translation pairs are deduplicated for API efficiency, while coverage and repair results are mapped back to every raw field.
- Title, excerpt and coherent body blocks share the article-level QA pass. Gutenberg JSON, visible HTML attributes and recognized Postmeta/SEO text use the same QA engine in bounded group passes.
- AI verdicts are field-level only. `keep` preserves the current translation; `rewrite` adds the field to a concentrated repair pool.
- Publication is allowed only when expected unique pairs equal checked unique pairs, unavailable is zero, repairs are complete and final PHP validation succeeds.

## 0.9.5.0 batching, repair and numeric-validation rules

- Ordinary human-readable leaves inside Gutenberg block JSON, ACF, Postmeta and recognized SEO metadata must be discovered first and translated in bounded maps. A short leaf must not automatically create its own API request.
- Discovery and write-back are structural responsibilities. Existing skip rules continue to protect URLs, IDs, ACF field references, JSON keys, attachment references, code, placeholders and machine values; translation rules do not depend on a site-specific Meta Key.
- Central body translation defaults to at most 120 fields per request, still constrained by the configured character ceiling. Central QA defaults to at most 80 unique pairs and 16000 source-plus-target characters per request.
- Upgrade migration may replace only the exact 0.9.4.9 defaults (`45`, `20`, `9000`). Explicit administrator values must be preserved.
- Full-coverage `all` mode performs one centralized QA/repair cycle. It must not run the older pre-QA structural repair over the same body first.
- Repair request values contain only current target text. Source text, field role and issue descriptions are instruction context and must never be accepted as publishable output if echoed by the model.
- Deterministic numeric validation distinguishes hard factual entities from language-form variants. Money, percentages, dates, time periods, versions, configurations, ranges, quantities, magnitudes and number/unit attachment remain strict. Ordinal wording and natural cardinal wording may change form without failure when no hard fact changes.
- Number/entity boundary checks remain mandatory. Campaign names, dates or versions must not become quantities, and units or magnitude markers must not attach to the wrong number.
- Deterministic coverage logs report the number of fields checked separately from the number of validation passes.
- Target typography normalization may repair unambiguous formatting defects such as whitespace inside thousands separators without invoking AI.
- Performance targets are evaluated against the same article and upstream conditions. The design target for the real 267-unit test article is approximately 12–16 total requests; this is an architectural target and must be verified by a live rerun rather than claimed from static tests.


## 0.9.6.1 adaptive localization quality rules

1. A translation job is one article-level localization task even when structure-safe fields are internally mapped for write-back.
2. Every AI translation request must silently self-review all returned values for fidelity, completeness, target-locale naturalness, terminology consistency, number/unit/entity relationships and placeholder integrity before output.
3. Deterministic PHP QA covers every translated human-readable field. It remains the publication blocker for structure, placeholders, URLs, numeric facts, money, versions, specifications and missing fields.
4. The default `adaptive` second pass sends only anomalies and a bounded risk-ranked candidate set to AI. `all` is a diagnostic mode, not the production default.
5. Exact repeated source strings with differing target forms are adaptive terminology-consistency candidates; no site-specific phrase map is required.
6. Repair failures must log the field key, validation reason and bounded source/candidate snippets.
7. A partial adaptive review, failed repair or deterministic blocker keeps the target article as a draft.


## 0.9.6.2 internal batching rule

- Request batching is an implementation safety profile and is not user-configurable.
- Translation batches use a 6,000 source-character ceiling and a 200-field ceiling.
- Adaptive second-pass QA uses a 24-candidate normal ceiling; deterministic blockers remain mandatory.
- QA batches use 80 fields and 12,000 source-plus-target/context characters.
- Stored legacy batching values are normalized at upgrade and ignored at runtime.
- Batching may affect latency, reliability, and how much adjacent context is available in one request, but it is not a language-quality preference. Language quality is controlled by the translation contract, locale profile, glossary, deterministic validation, and adaptive repair workflow.

## 0.9.6.3 integrity and AI-authority rule

1. PHP integrity validation is always enabled and has no administrative off switch.
2. PHP hard failures are limited to missing returned fields, required empty values, protected WordPress/HTML/JSON structure, placeholders/machine tokens, and database write-back mismatches.
3. Suspected source-language residue, numeric differences and length differences are advisory signals only. They may prioritize or annotate an optional AI review, but cannot independently reject, rewrite, draft, overwrite or mutate a translation.
4. An AI `keep` verdict is authoritative for advisory signals. Only an explicit AI `rewrite` verdict may add a field to the repair pool.
5. The backend exposes one quality preference: whether optional AI quality review is enabled. Legacy coverage, auto-repair, draft, strictness and per-language quality controls are internalized and normalized from that switch.
6. The OpenAI-compatible and external Agent paths apply the same PHP integrity boundary.
7. Post title, excerpt, content, status, slug and translated metadata must be read back after writing and compared with the intended values before the job is reported complete.


## 0.9.6.4 validation-only PHP rule

- PHP may reject missing fields, empty required values, broken HTML/JSON/Gutenberg/shortcode/URL structure, damaged placeholders, or failed database readback.
- PHP must not normalize punctuation, whitespace, thousands separators, source-language residue, terminology, or length after AI output.
- Residue, numeric and length heuristics are advisory inputs to optional AI QA only. An AI `keep` decision is authoritative.
- Only an explicit AI `rewrite` decision may replace accepted target text.
- Environment diagnostics must distinguish global requirements from engine-specific requirements such as OpenCC and `shell_exec`.


## 0.9.6.5 article terminology context rule

- Before translating publishable fields, the internal OpenAI-compatible engine may generate one compact article-specific terminology context from the source title, excerpt and an ordered body sample.
- The context is prompt guidance only. PHP must never apply it as a direct natural-language replacement table.
- The same context is shared across title, excerpt, body, Gutenberg data, postmeta, SEO QA and repair requests.
- An explicitly configured user glossary has higher priority than generated article terminology.
- If AI QA explicitly identifies source-language residue, PHP may select other fields containing the same shared fragment for an additional AI audit. PHP must not rewrite those fields itself, and AI `keep` remains authoritative.


## 0.9.6.6 section-context translation rule

1. The complete source article is parsed before translation, but body requests are grouped by semantic H2/H3 sections rather than one oversized field batch or isolated single fields.
2. Each body request receives a short article brief, the current and adjacent section headings, and a short tail from the previous translated batch as read-only context.
3. The article-planning call lists high-risk source concepts only. It must not generate a machine source-to-target glossary; explicit human glossary entries remain authoritative.
4. After merging, title, excerpt, headings, and section boundary anchors receive full-coverage AI editorial review; the full body then retains adaptive risk review.
5. PHP may discover related source concepts after an AI rewrite and submit other fields for AI review, but PHP must never replace natural-language wording itself.
6. No automatic cross-article translation memory is created.

## 0.9.7.0 streamlined article review rule

1. Article context is assembled deterministically from source title, excerpt, headings and recurring source review fragments. It must not contain PHP-generated target-language replacements.
2. Translation remains section-based and receives adjacent source context plus short previous source and target continuity samples.
3. The final article editor receives source text, current target text, field role and section context in the same request.
4. `keep` is authoritative. Only an explicit `rewrite|||<complete final target>` may modify a field.
5. Every rewrite must pass the same PHP structure, placeholder and machine-token validation as initial translation.
6. The article path must not perform a second source-blind repair request or recursive semantic replacement.
7. Automatic cross-article translation memory remains disabled.


## 0.9.7.1 grouped source-concept review rule

1. Final article QA may discover recurring source-side concept groups from deterministic source context, repeated source fragments, exact repeated-source conflicts, and advisory shared-script fragments.
2. Selecting a concept group must include every article field containing that selected source fragment, subject to a bounded whole-group budget. A selected group must not be truncated field-by-field.
3. Group labels are advisory context only. PHP must not create a target-language replacement, infer semantic equivalence, or override AI `keep`.
4. AI receives source, current target, role, section and group labels together, and may return only `keep` or `rewrite|||<complete final target>`.
5. Natural grammatical variation across a concept group is allowed; consistency does not require mechanical identical wording.
6. Shared Han/script fragments may raise review priority but are never deterministic translation errors.
7. Automatic cross-article translation memory remains disabled.
