# WPMU 多语言插件 0.9.8.18 未发布语言弹窗夜间模式报告

日期：2026-07-24

## 处理范围

本次只调整语言切换器在“显示提示”模式下的前台弹窗样式：

- 文件：`assets/css/language-switcher.css`
- 弹窗：`.wpmu-ml-language-notice-modal`
- 不修改弹窗 HTML 结构
- 不修改语言切换器 JS
- 不修改文章关联、同步、翻译队列、Agent API 或 Agent Tools API

## 改动内容

1. 给弹窗样式增加 CSS 变量：
   - 遮罩颜色
   - 弹窗背景
   - 标题颜色
   - 正文颜色
   - 图标背景和颜色
   - 关闭按钮背景和颜色
   - 阴影颜色

2. 白天模式保持原有视觉效果。

3. 夜间模式新增深色配色：
   - 深色弹窗背景
   - 浅色标题和正文
   - 更深的遮罩
   - 暗色关闭按钮
   - 保留红色确认按钮作为主操作

4. 兼容规则：
   - `@media (prefers-color-scheme: dark)`
   - `html.dark` / `body.dark`
   - `html.dark-mode` / `body.dark-mode`
   - `html.theme-dark` / `body.theme-dark`
   - `html.is-dark` / `body.is-dark`
   - `html.is-dark-theme` / `body.is-dark-theme`
   - `html.wp-dark-mode-active` / `body.wp-dark-mode-active`
   - `[data-theme="dark"]`
   - `[data-color-scheme="dark"]`
   - `[data-bs-theme="dark"]`
   - `[data-mode="dark"]`

## 兼容性说明

这类前台弹窗不能假设某个主题固定用哪一个夜间 class。当前实现采用两层兼容：

1. 浏览器/系统级夜间：`prefers-color-scheme: dark`
2. 主题/夜间插件级夜间：常见 class 和 data attribute 选择器

如果某个主题使用完全自定义的夜间标识，例如 `.my-theme-night`，后续只需要追加一条选择器，不需要改 HTML 或 JS。

## 测试建议

前台打开一个语言切换器中“目标语言未发布”的页面，分别验证：

1. 普通白天模式：
   - 弹窗仍为白底
   - 标题、正文和按钮布局不变

2. 系统夜间模式：
   - 浏览器 DevTools 模拟 `prefers-color-scheme: dark`
   - 弹窗应切换为深色背景、浅色文字

3. 主题夜间 class：
   - 临时给 `html` 或 `body` 加 `dark` / `dark-mode` / `is-dark-theme`
   - 弹窗应切换为深色配色

4. Bootstrap 类主题：
   - 临时给根节点加 `data-bs-theme="dark"`
   - 弹窗应切换为深色配色

## 验证结果

- CSS 语法检查通过。
- PHP 文件语法检查通过。
- 本次未改数据库结构。
- 本次未改接口行为。
