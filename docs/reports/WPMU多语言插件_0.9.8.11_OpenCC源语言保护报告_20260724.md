# WPMU 多语言插件 0.9.8.11 OpenCC 源语言保护报告

## 问题

OpenCC 只适合简繁转换，尤其是当前内置的 `s2twp` / `s2tw` / `s2hk` / `s2t` 都是简体中文到繁体中文方向。

旧逻辑只判断目标语言是否为繁体中文：

```text
目标语言是 zh-hant / zh-tw / zh-hk
→ 未显式配置时 fallback 到 OpenCC
```

如果公开插件被非中文源站安装，例如英文源站翻译到繁体中文，这个 fallback 会错误地使用 OpenCC，结果不会得到真正翻译。

## 处理结果

0.9.8.11 已改为：

```text
源站识别为简体中文
且目标语言识别为繁体中文
→ 才显示 OpenCC 选项
→ 才允许自动 fallback 到 OpenCC
```

否则：

- 不自动 fallback 到 OpenCC。
- 后台目标语言规则不显示 OpenCC 选项。
- 环境检测不会把 OpenCC 误判为必需。
- 应使用 OpenAI 兼容、Agent API 或人工翻译。

## 源语言识别依据

插件会读取源站的：

- `translation_locale`
- WordPress `locale`
- `hreflang`
- `lang_slug`
- 当前源站实时 WordPress Locale

只有识别为 `zh`、`zh-hans`、`zh-cn`、`zh-sg`、`zh-my`、`cn`、`sg` 等简体中文标识时，才允许 OpenCC 简转繁自动路由。

## 验收建议

- 简体中文源站 + 繁体中文目标站：目标语言规则应显示 OpenCC 选项，未显式配置时可 fallback 到 `opencc_s2twp`。
- 英文源站 + 繁体中文目标站：目标语言规则不应显示 OpenCC，未显式配置时应走默认引擎。
- OpenCC 环境检测只在实际路由需要 OpenCC 时提示必需。

报告日期：2026-07-24  
处理版本：0.9.8.11
