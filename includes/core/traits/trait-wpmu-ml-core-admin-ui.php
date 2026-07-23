<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 网络后台页面与界面渲染。
 *
 * 本 trait 仅用于拆分历史 WPMU_Multilingual 大类；方法签名和可见性保持不变。
 */
if (!trait_exists('WPMU_ML_Core_Admin_UI_Trait')) {
    trait WPMU_ML_Core_Admin_UI_Trait
    {
        public function render_admin_page()
        {
            if (!current_user_can('manage_network_options')) {
                wp_die('权限不足');
            }
            $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
            if ($tab === 'engines') {
                wp_safe_redirect(network_admin_url('admin.php?page=wpmu-multilingual-engines'));
                exit;
            }
            echo '<div class="wrap wpmu-ml-wrap">';
            echo '<h1>翻译设置 <span class="wpmu-ml-muted">WPMU多语言</span></h1>';
            $this->render_tabs($tab);

            $this->render_settings_save_error_notice();
            if (isset($_GET['updated'])) {
                echo '<div class="notice notice-success is-dismissible"><p>设置已保存。</p></div>';
            }
            if (isset($_GET['rebuilt'])) {
                echo '<div class="notice notice-success is-dismissible"><p>关联已重建。</p></div>';
            }

            switch ($tab) {
                case 'sites':
                    $this->render_sites_page();
                    break;
                case 'switcher':
                    $this->render_language_switcher_settings_page();
                    break;
                case 'types':
                    $this->render_types_page();
                    break;
                case 'relations':
                    $this->render_relations_page();
                    break;
                case 'sync':
                    $this->render_sync_page();
                    break;
                case 'translation':
                    $this->render_translation_page();
                    break;
                case 'tools':
                    $this->render_tools_page();
                    break;
                case 'misc':
                    $this->render_misc_settings_page();
                    break;
                case 'help':
                    $this->render_help_page();
                    break;
                default:
                    $this->render_dashboard_page();
            }
            echo '</div>';
            echo '<style>.wpmu-ml-wrap table input[type=text]{width:100%;max-width:180px}.wpmu-ml-wrap textarea{width:100%;max-width:760px}.wpmu-ml-badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#eef2ff}.wpmu-ml-muted{color:#666;font-size:12px;}.wpmu-ml-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px}.wpmu-ml-card{background:#fff;border:1px solid #ccd0d4;padding:16px;border-radius:4px}.wpmu-ml-checkgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:8px 16px;max-width:960px;margin-bottom:10px}.wpmu-ml-checkgrid label{display:block}.wpmu-ml-checkgrid label.wpmu-ml-disabled{opacity:.45}.wpmu-ml-ok{color:#008a20;font-weight:600}.wpmu-ml-warn{color:#996800;font-weight:600}.wpmu-ml-bad{color:#b32d2e;font-weight:600}.wpmu-ml-help-table td,.wpmu-ml-help-table th{vertical-align:top}.wpmu-ml-pre{background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto;max-width:1100px}.wpmu-ml-inline-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.wpmu-ml-inline-controls select,.wpmu-ml-inline-controls input[type=number]{height:40px;min-height:40px;box-sizing:border-box;margin:0}.wpmu-ml-inline-field{display:inline-flex;align-items:center;gap:6px;margin:0}.wpmu-ml-inline-field input[type=number]{width:90px}.wpmu-ml-flag-controls select{min-width:84px}.wpmu-ml-switcher-subtabs{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0 14px;border-bottom:1px solid #ccd0d4}.wpmu-ml-switcher-subtab{border:1px solid #ccd0d4;border-bottom:none;background:#f6f7f7;color:#1d2327;padding:9px 18px;border-radius:4px 4px 0 0;text-decoration:none;font-weight:600;font-size:14px;cursor:pointer}.wpmu-ml-switcher-subtab:hover{color:#2271b1;background:#fff}.wpmu-ml-switcher-subtab.is-active{background:#fff;color:#2271b1;border-bottom:1px solid #fff;margin-bottom:-1px}.wpmu-ml-switcher-panel{display:none;background:#fff;border:1px solid #ccd0d4;padding:18px 20px 8px;max-width:1240px}.wpmu-ml-switcher-panel.is-active{display:block}@media (max-width:782px){.wpmu-ml-switcher-subtabs{align-items:stretch}.wpmu-ml-switcher-subtab{width:100%;border-bottom:1px solid #ccd0d4;border-radius:4px}}</style>';
        }

        public function render_engine_admin_page()
        {
            if (!current_user_can('manage_network_options')) {
                wp_die('权限不足');
            }

            $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'routing';
            $valid_tabs = ['routing', 'openai', 'agent', 'opencc', 'manual', 'rules', 'advanced'];
            if (!in_array($tab, $valid_tabs, true)) {
                $tab = 'routing';
            }

            echo '<div class="wrap wpmu-ml-wrap wpmu-ml-engine-wrap">';
            echo '<h1>翻译引擎 <span class="wpmu-ml-muted">WPMU多语言</span></h1>';
            $this->render_engine_tabs($tab);

            $this->render_settings_save_error_notice();
            if (isset($_GET['updated'])) {
                echo '<div class="notice notice-success is-dismissible"><p>翻译引擎设置已保存。</p></div>';
            }

            $this->render_engines_page($tab);
            echo '</div>';
            echo '<style>.wpmu-ml-wrap table input[type=text]{width:100%;max-width:240px}.wpmu-ml-wrap textarea{width:100%;max-width:980px}.wpmu-ml-badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#eef2ff}.wpmu-ml-muted{color:#666;font-size:12px}.wpmu-ml-pre{background:#f6f7f7;border:1px solid #dcdcde;padding:10px;overflow:auto;max-width:1100px}.wpmu-ml-engine-wrap{max-width:none}.wpmu-ml-engine-wrap .form-table th{width:220px}</style>';
        }

        private function render_engine_tabs($active)
        {
            $tabs = [
                'routing' => '默认与路由',
                'openai' => 'OpenAI 兼容',
                'agent' => 'Agent API',
                'opencc' => 'OpenCC',
                'manual' => '人工翻译',
                'rules' => '翻译规则',
                'advanced' => '高级说明',
            ];
            echo '<h2 class="nav-tab-wrapper">';
            foreach ($tabs as $key => $label) {
                $class = $active === $key ? ' nav-tab-active' : '';
                echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url(network_admin_url('admin.php?page=wpmu-multilingual-engines&tab=' . $key)) . '">' . esc_html($label) . '</a>';
            }
            echo '</h2>';
        }

        private function render_settings_save_error_notice()
        {
            if (empty($_GET['wpmu_ml_save_error'])) {
                return;
            }
            $message = sanitize_text_field(wp_unslash((string)$_GET['wpmu_ml_save_error']));
            if ($message === '') {
                $message = '设置写入失败，请检查数据库权限、对象缓存或错误日志。';
            }
            echo '<div class="notice notice-error"><p><strong>设置未保存：</strong>' . esc_html($message) . '</p></div>';
        }

        private function render_tabs($active)
        {
            $tabs = [
                'dashboard' => '概览',
                'sites' => '语言站点',
                'switcher' => '语言切换',
                'types' => '内容类型',
                'relations' => '关联管理',
                'sync' => '自动同步',
                'translation' => '翻译队列',
                'tools' => '工具',
                'misc' => '其他设置',
                'help' => '帮助',
            ];
            echo '<h2 class="nav-tab-wrapper">';
            foreach ($tabs as $key => $label) {
                $class = $active === $key ? ' nav-tab-active' : '';
                echo '<a class="nav-tab' . esc_attr($class) . '" href="' . esc_url(network_admin_url('admin.php?page=wpmu-multilingual&tab=' . $key)) . '">' . esc_html($label) . '</a>';
            }
            echo '</h2>';
        }

        private function render_dashboard_page()
        {
            global $wpdb;
            $settings = $this->get_settings();
            $site_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['sites']} WHERE enabled = 1");
            $post_rel = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['posts']}");
            $term_rel = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['terms']}");
            $job_pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['jobs']} WHERE status IN ('pending','needs_update','translated_update_pending')");
            $source = $settings['source_blog_id'] ? get_site($settings['source_blog_id']) : null;

            echo '<div class="wpmu-ml-grid">';
            echo '<div class="wpmu-ml-card"><h2>当前源站</h2><p>' . ($source ? esc_html(get_home_url($source->blog_id, '/')) : '未设置') . '</p></div>';
            echo '<div class="wpmu-ml-card"><h2>启用语言站</h2><p style="font-size:28px;margin:0">' . esc_html($site_count) . '</p></div>';
            echo '<div class="wpmu-ml-card"><h2>文章关联</h2><p style="font-size:28px;margin:0">' . esc_html($post_rel) . '</p></div>';
            echo '<div class="wpmu-ml-card"><h2>分类关联</h2><p style="font-size:28px;margin:0">' . esc_html($term_rel) . '</p></div>';
            echo '<div class="wpmu-ml-card"><h2>待处理翻译</h2><p style="font-size:28px;margin:0">' . esc_html($job_pending) . '</p></div>';
            echo '</div>';
            echo '<p class="wpmu-ml-muted">建议流程：先在“语言站点”设置源站和各语言代码，再在“内容类型”设置参与翻译的文章类型，最后使用只读审计命令检查关联。生产关系重建已禁用。</p>';
        }

        private function render_sites_page()
        {
            $this->sync_sites_from_network(false);
            $sites = $this->get_i18n_sites();
            $settings = $this->get_settings();
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="wpmu_ml_save_sites">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
            echo '<div style="overflow-x:auto;margin-bottom:16px">';
            echo '<table class="widefat striped" style="min-width:1420px"><thead><tr><th>启用</th><th>Blog ID</th><th>站点</th><th>语言标识<br><span class="wpmu-ml-muted">Slug 别名</span></th><th>语言代码<br><span class="wpmu-ml-muted">Locale</span></th><th>语言名称<br><span class="wpmu-ml-muted">可自定义</span></th><th>AI 翻译标签<br><span class="wpmu-ml-muted">留空跟随 Locale</span></th><th>AI 翻译使用名称<br><span class="wpmu-ml-muted">自动获取</span></th><th>hreflang<br><span class="wpmu-ml-muted">SEO 专用</span></th><th>源站</th><th>前台默认</th><th>排序</th></tr></thead><tbody>';
            foreach ($sites as $site) {
                $bid = (int) $site['blog_id'];
                $translation_locale = $this->normalize_language_tag((string)($site['translation_locale'] ?? ''));
                $effective_translation_locale = $translation_locale !== '' ? $translation_locale : $this->normalize_language_tag((string)($site['locale'] ?? ''));
                $translation_language_name = trim((string)($site['translation_language_name'] ?? ''));
                if ($effective_translation_locale !== '') {
                    $translation_language_name = $this->get_locale_ai_language_name($effective_translation_locale);
                }
                echo '<tr>';
                echo '<td><input type="checkbox" name="sites[' . esc_attr($bid) . '][enabled]" value="1" ' . checked($site['enabled'], 1, false) . '></td>';
                echo '<td>' . esc_html($bid) . '</td>';
                echo '<td><a href="' . esc_url($site['site_url']) . '" target="_blank">' . esc_html($site['site_url']) . '</a><br><span class="wpmu-ml-muted">' . esc_html($site['site_path']) . '</span></td>';
                echo '<td><input type="text" name="sites[' . esc_attr($bid) . '][lang_slug]" value="' . esc_attr($site['lang_slug']) . '" style="min-width:80px;max-width:80px"></td>';
                echo '<td><input type="text" value="' . esc_attr($site['locale']) . '" readonly aria-readonly="true" title="自动读取该分站“设置 → 常规 → 站点语言”" style="background:#f6f7f7;min-width:80px;max-width:80px"></td>';
                $auto_language_name = $this->get_locale_language_name((string)($site['locale'] ?? ''));
                echo '<td><input type="text" name="sites[' . esc_attr($bid) . '][language_name]" value="' . esc_attr($site['language_name'] ?? '') . '" placeholder="' . esc_attr($auto_language_name) . '" title="可自定义前台显示名称；留空则按 Locale 自动获取简短母语名称" style="min-width:180px"></td>';
                echo '<td><input type="text" name="sites[' . esc_attr($bid) . '][translation_locale]" value="' . esc_attr($translation_locale) . '" placeholder="' . esc_attr($this->normalize_language_tag((string)($site['locale'] ?? ''))) . '" style="color: #0026f7;min-width:100px;max-width:100px"></td>';
                echo '<td><input type="text" value="' . esc_attr($translation_language_name) . '" readonly aria-readonly="true" title="根据有效 AI 翻译标签自动获取" style="background:#f6f7f7;min-width:200px"></td>';
                echo '<td><input type="text" name="sites[' . esc_attr($bid) . '][hreflang]" value="' . esc_attr($site['hreflang']) . '" style="min-width:80px;max-width:80px"></td>';
                echo '<td><input type="radio" name="source_blog_id" value="' . esc_attr($bid) . '" ' . checked((int)$settings['source_blog_id'], $bid, false) . '></td>';
                echo '<td><input type="radio" name="front_blog_id" value="' . esc_attr($bid) . '" ' . checked((int)$settings['front_blog_id'], $bid, false) . '></td>';
                echo '<td><input type="text" name="sites[' . esc_attr($bid) . '][sort_order]" value="' . esc_attr($site['sort_order']) . '" style="max-width:70px"></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '</div>';
            echo '<p class="description"><strong>字段分工：</strong><code>Locale</code> 从分站 WordPress 后台自动读取，负责 WordPress 语言包和默认翻译变体；<code>语言名称</code> 可自定义，主要用于前台语言切换显示，留空则自动获取简短母语名称，例如 русский；<code>AI 翻译标签</code> 是可选覆盖值，使用 BCP 47 写法，例如 <code>es-419</code>、<code>pt-BR</code>、<code>en-US</code>。填写后按该标签翻译，留空则跟随 Locale；<code>AI 翻译使用名称</code> 仍自动获取并用于 AI 提示词；<code>hreflang</code> 仅用于 SEO。自然、可信、像目标语言母语作者原创的“母语化”质量要求已内置在提示词和 Agent 共用规则中，不需要逐语言重复填写。</p>';
            submit_button('保存语言站点设置');
            echo '</form>';
        }

        private function render_language_switcher_settings_page()
        {
            $settings = $this->get_settings();
            $enabled = !empty($settings['enable_menu_language_switcher']);
            $call_mode = sanitize_key((string)($settings['language_switcher_call_mode'] ?? 'code'));
            if (!in_array($call_mode, ['code', 'menu'], true)) {
                $call_mode = 'code';
            }
            $unpublished_policy = sanitize_key((string)($settings['language_switcher_unpublished_policy'] ?? 'hide'));
            if (!in_array($unpublished_policy, ['hide', 'notice'], true)) {
                $unpublished_policy = 'hide';
            }
            $flag_mode = sanitize_key((string)($settings['language_switcher_flag_mode'] ?? 'none'));
            if (!in_array($flag_mode, ['none', 'before', 'after'], true)) {
                $flag_mode = 'none';
            }
            $flag_style = sanitize_key((string)($settings['language_switcher_flag_style'] ?? '4x3'));
            if (!in_array($flag_style, ['4x3', '1x1'], true)) {
                $flag_style = '4x3';
            }
            $flag_size = absint($settings['language_switcher_flag_size'] ?? 24);
            if ($flag_size < 12) {
                $flag_size = 24;
            }
            if ($flag_size > 64) {
                $flag_size = 64;
            }
            $flag_radius = absint($settings['language_switcher_flag_radius'] ?? 2);
            if ($flag_radius > 32) {
                $flag_radius = 32;
            }

            echo '<div class="wpmu-ml-switcher-subtabs" id="wpmu-ml-switcher-subtabs">';
            echo '<button type="button" class="wpmu-ml-switcher-subtab is-active" data-panel="basic">基础设置</button>';
            echo '<button type="button" class="wpmu-ml-switcher-subtab" data-panel="code">代码调用</button>';
            echo '<button type="button" class="wpmu-ml-switcher-subtab" data-panel="menu">菜单调用</button>';
            echo '</div>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="wpmu_ml_save_switcher_settings">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

            echo '<div class="wpmu-ml-switcher-panel is-active" id="wpmu-ml-switcher-panel-basic">';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th scope="row">语言调用方式</th><td>';
            echo '<select name="language_switcher_call_mode">';
            echo '<option value="code" ' . selected($call_mode, 'code', false) . '>代码调用</option>';
            echo '<option value="menu" ' . selected($call_mode, 'menu', false) . '>菜单调用</option>';
            echo '</select>';
            echo '<p class="description">这里只选择当前推荐/默认的调用方式；具体显示结构、显示哪些语言、样式效果，放到对应的“代码调用”或“菜单调用”里设置。</p>';
            echo '</td></tr>';
            echo '<tr><th scope="row">语言切换旗帜</th><td>';
            echo '<div class="wpmu-ml-inline-controls wpmu-ml-flag-controls">';
            echo '<select name="language_switcher_flag_mode">';
            echo '<option value="none" ' . selected($flag_mode, 'none', false) . '>不显示</option>';
            echo '<option value="before" ' . selected($flag_mode, 'before', false) . '>语言前</option>';
            echo '<option value="after" ' . selected($flag_mode, 'after', false) . '>语言后</option>';
            echo '</select>';
            echo '<select name="language_switcher_flag_style">';
            echo '<option value="4x3" ' . selected($flag_style, '4x3', false) . '>4:3</option>';
            echo '<option value="1x1" ' . selected($flag_style, '1x1', false) . '>1:1</option>';
            echo '</select>';
            echo '<label class="wpmu-ml-inline-field"><input type="number" min="12" max="64" step="1" name="language_switcher_flag_size" value="' . esc_attr((string)$flag_size) . '" placeholder="尺寸"><span>px</span></label>';
            echo '<label class="wpmu-ml-inline-field"><input type="number" min="0" max="32" step="1" name="language_switcher_flag_radius" value="' . esc_attr((string)$flag_radius) . '" placeholder="圆角"><span>px</span></label>';
            echo '</div>';
            echo '<p class="description">旗帜图标来自 <code>assets/flags/4x3</code> 或 <code>assets/flags/1x1</code>。位置可选语言前或语言后，尺寸和圆角都按像素控制；圆角填 <code>0</code> 为直角，填 <code>999</code> 不支持，最大限制为 <code>32px</code>。</p>';
            echo '</td></tr>';
            echo '<tr><th scope="row">未发布语言处理</th><td>';
            echo '<select name="language_switcher_unpublished_policy">';
            echo '<option value="hide" ' . selected($unpublished_policy, 'hide', false) . '>隐藏语言</option>';
            echo '<option value="notice" ' . selected($unpublished_policy, 'notice', false) . '>显示提示</option>';
            echo '</select>';
            echo '<p class="description">只影响语言切换器。文章详情页遇到目标语言文章未发布时：“隐藏语言”会直接不显示该语言；“显示提示”会显示语言项，但点击后弹窗提示该语言版本暂未发布。hreflang 始终只输出已发布且可索引的目标文章。</p>';
            echo '</td></tr>';
            echo '<tr><th scope="row">当前状态</th><td>';
            echo $call_mode === 'menu' ? '<span class="wpmu-ml-ok">当前选择：菜单调用</span>' : '<span class="wpmu-ml-ok">当前选择：代码调用</span>';
            echo '<p class="description">代码调用适合主题里直接写 <code>wpmu_ml_language_switcher()</code>；菜单调用适合 WordPress 外观菜单里拖拽 Language Switcher 菜单项。</p>';
            echo '</td></tr>';
            echo '</tbody></table>';
            echo '</div>';

            echo '<div class="wpmu-ml-switcher-panel" id="wpmu-ml-switcher-panel-code">';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th scope="row">代码调用函数</th><td>';
            echo '<code>wpmu_ml_language_switcher()</code>';
            echo '<p class="description">主题模板中可直接调用。插件负责当前语言、可切换语言、URL、hreflang/lang；主题负责 CSS、下拉效果和响应式。</p>';
            echo '<pre class="wpmu-ml-pre">&lt;?php\nif (function_exists(\'wpmu_ml_language_switcher\')) {\n    wpmu_ml_language_switcher([\n        \'display\' =&gt; \'full\',\n        \'class\'   =&gt; \'language-menu\',\n        \'flag_mode\' =&gt; \'before\',\n        \'flag_style\' =&gt; \'4x3\',\n        \'flag_size\' =&gt; 24,\n        \'flag_radius\' =&gt; 2,\n    ]);\n}\n?&gt;</pre>';
            echo '</td></tr>';
            echo '<tr><th scope="row">显示设置</th><td><p class="description">后续代码调用专用的显示项会放这里，例如显示名称/简称、是否显示当前语言、未发布语言处理、结构类型、旗帜位置等。</p></td></tr>';
            echo '</tbody></table>';
            echo '</div>';

            echo '<div class="wpmu-ml-switcher-panel" id="wpmu-ml-switcher-panel-menu">';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th scope="row">显示在后台菜单</th><td>';
            echo '<input type="hidden" name="enable_menu_language_switcher" value="0">';
            echo '<label><input type="checkbox" name="enable_menu_language_switcher" value="1" ' . checked($enabled, true, false) . '> 在各语言站点的“外观 → 菜单”中显示 <strong>Language Switcher</strong> 菜单项面板</label>';
            echo '<p class="description">启用后，面板会自动提供“当前语言”和全部已启用语言。进入各分站的“外观 → 菜单”，勾选项目并添加到菜单，再把具体语言拖到“当前语言”下面即可形成二级下拉菜单。</p>';
            echo '</td></tr>';
            echo '<tr><th scope="row">当前状态</th><td>';
            if ($enabled) {
                echo '<span class="wpmu-ml-ok">已启用</span><p class="description">语言菜单项会根据“语言站点”中的启用状态、排序和语言名称自动同步。前台菜单会把“当前语言”替换为当前站点语言，并隐藏下拉列表中重复的当前语言。</p>';
            } else {
                echo '<span class="wpmu-ml-muted">未启用</span><p class="description">关闭时不会在“外观 → 菜单”中注册 Language Switcher 面板。已创建的虚拟语言项目会保留，重新启用后可继续使用。</p>';
            }
            echo '</td></tr>';
            echo '<tr><th scope="row">旗帜设置</th><td><p class="description">菜单调用下也可以按这里的全局默认显示旗帜，后续可继续拆成桌面/移动端、圆角、方形等更细的选项。</p></td></tr>';
            echo '</tbody></table>';
            echo '</div>';

            submit_button('保存语言切换设置');
            echo '</form>';
            echo '<script>(function(){var tabs=document.querySelectorAll("#wpmu-ml-switcher-subtabs .wpmu-ml-switcher-subtab");var panels=document.querySelectorAll(".wpmu-ml-switcher-panel");function show(id){if(!id){id="basic";}var found=false;panels.forEach(function(p){var active=p.id==="wpmu-ml-switcher-panel-"+id;p.classList.toggle("is-active",active);if(active){found=true;}});if(!found){id="basic";panels.forEach(function(p){p.classList.toggle("is-active",p.id==="wpmu-ml-switcher-panel-basic");});}tabs.forEach(function(t){t.classList.toggle("is-active",t.getAttribute("data-panel")===id);});try{localStorage.setItem("wpmuMlSwitcherTab",id);}catch(e){}if(window.history&&window.history.replaceState){window.history.replaceState(null,"",window.location.pathname+window.location.search+"#switcher-"+id);}}var start="basic";if(window.location.hash&&window.location.hash.indexOf("#switcher-")===0){start=window.location.hash.replace("#switcher-","");}else{try{start=localStorage.getItem("wpmuMlSwitcherTab")||"basic";}catch(e){}}tabs.forEach(function(t){t.addEventListener("click",function(){show(t.getAttribute("data-panel"));});});show(start);})();</script>';
        }

        private function render_types_page()
        {
            $settings = $this->get_settings();
            $post_types = $this->get_detected_post_types();
            $taxonomies = $this->get_detected_taxonomies();

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="wpmu_ml_save_settings">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>启用 hreflang</th><td><label><input type="checkbox" name="enable_hreflang" value="1" ' . checked($settings['enable_hreflang'], 1, false) . '> 前台自动输出 alternate hreflang</label></td></tr>';
            echo '<tr><th>x-default</th><td><select name="x_default_mode"><option value="front" ' . selected($settings['x_default_mode'], 'front', false) . '>前台默认站点</option><option value="source" ' . selected($settings['x_default_mode'], 'source', false) . '>源站</option><option value="none" ' . selected($settings['x_default_mode'], 'none', false) . '>不输出</option></select></td></tr>';

            $shared_selected_for_ui = array_values(array_unique(array_map('sanitize_key', (array)$settings['shared_post_types'])));
            $translatable_selected_for_ui = array_values(array_diff(array_unique(array_map('sanitize_key', (array)$settings['translatable_post_types'])), $shared_selected_for_ui));

            echo '<tr><th>参与翻译的文章类型</th><td>';
            $this->render_checkbox_grid('translatable_post_types_checked', $post_types, $translatable_selected_for_ui, 'post_type', 'translatable');
            echo '<p class="description">从源站数据库和当前已注册 post type 自动列出。勾选后，同一个 post_type 会自动禁止在“共享发布”里重复勾选。</p>';
            echo '<textarea name="translatable_post_types_manual" rows="3" placeholder="额外补充，每行一个 post_type">' . esc_textarea($this->manual_items_not_in_candidates($translatable_selected_for_ui, $post_types)) . '</textarea>';
            echo '</td></tr>';

            echo '<tr><th>共享发布的文章类型</th><td>';
            $this->render_checkbox_grid('shared_post_types_checked', $post_types, $shared_selected_for_ui, 'post_type', 'shared');
            echo '<p class="description">例如 tolink。这类内容通常不进入翻译流程，目标站保持发布。勾选后，同一个 post_type 会自动禁止在“参与翻译”里重复勾选。</p>';
            echo '<textarea name="shared_post_types_manual" rows="2" placeholder="额外补充，每行一个 post_type">' . esc_textarea($this->manual_items_not_in_candidates($shared_selected_for_ui, $post_types)) . '</textarea>';
            echo '</td></tr>';

            echo '<tr><th>说明</th><td><p class="description">文章类型采用互斥白名单：一个 post_type 只能属于“参与翻译”或“共享发布”其中一种；没勾选的文章类型默认不参与同步。如果手动补充里重复填写，保存时共享发布优先。</p></td></tr>';

            $selected_taxonomies = $this->get_selected_sync_taxonomies_for_ui($taxonomies);
            echo '<tr><th>参与同步的分类法</th><td>';
            $this->render_checkbox_grid('sync_taxonomies_checked', $taxonomies, $selected_taxonomies, 'taxonomy');
            echo '<p class="description">只同步勾选的分类法关系，例如 category、post_tag、各类 xxx_category；没勾选的分类法默认不处理。</p>';
            echo '<textarea name="sync_taxonomies_manual" rows="2" placeholder="额外补充，每行一个 taxonomy">' . esc_textarea($this->manual_items_not_in_candidates($selected_taxonomies, $taxonomies)) . '</textarea>';
            echo '</td></tr>';
            echo '<tr><th>分类/标签本体翻译</th><td>';
            echo '<label style="display:inline-block;margin-inline-end:16px;margin-block-end:8px"><input type="checkbox" name="translate_term_name" value="1" ' . checked(!empty($settings['translate_term_name']), true, false) . '> 翻译分类/标签名称 name</label>';
            echo '<label style="display:inline-block;margin-inline-end:16px;margin-block-end:8px"><input type="checkbox" name="translate_term_description" value="1" ' . checked(!empty($settings['translate_term_description']), true, false) . '> 翻译分类/标签描述 description</label>';
            echo '<p class="description">默认关闭。开启后，源站 term 新增/编辑同步到目标站时会按目标语言翻译勾选字段；slug 仍固定同步源站，不自动翻译。</p>';
            echo '</td></tr>';
            echo '</tbody></table>';
            submit_button('保存内容类型设置');
            echo '</form>';
            $this->render_post_type_exclusive_script();
        }

        private function render_post_type_exclusive_script()
        {
?>
            <script>
                (function() {
                    function setDisabled(box, disabled) {
                        if (!box) return;
                        box.disabled = disabled;
                        var label = box.closest ? box.closest('label') : null;
                        if (label) {
                            if (disabled) {
                                label.classList.add('wpmu-ml-disabled');
                            } else {
                                label.classList.remove('wpmu-ml-disabled');
                            }
                        }
                    }

                    function refreshExclusivePostTypes() {
                        var boxes = document.querySelectorAll('.wpmu-ml-posttype-exclusive');
                        var map = {};
                        boxes.forEach(function(box) {
                            var type = box.getAttribute('data-wpmu-ml-type');
                            var role = box.getAttribute('data-wpmu-ml-role');
                            if (!map[type]) map[type] = {};
                            map[type][role] = box;
                        });
                        Object.keys(map).forEach(function(type) {
                            var translatable = map[type].translatable;
                            var shared = map[type].shared;
                            if (!translatable || !shared) return;
                            setDisabled(translatable, false);
                            setDisabled(shared, false);
                            if (translatable.checked) {
                                setDisabled(shared, true);
                            } else if (shared.checked) {
                                setDisabled(translatable, true);
                            }
                        });
                    }
                    document.addEventListener('change', function(event) {
                        if (event.target && event.target.classList && event.target.classList.contains('wpmu-ml-posttype-exclusive')) {
                            refreshExclusivePostTypes();
                        }
                    });
                    document.addEventListener('DOMContentLoaded', refreshExclusivePostTypes);
                    refreshExclusivePostTypes();
                })();
            </script>
<?php
        }

        private function render_checkbox_grid($name, $items, $selected, $kind = 'post_type', $exclusive_role = '')
        {
            $items = array_values(array_unique(array_filter(array_map('sanitize_key', (array)$items))));
            sort($items, SORT_NATURAL);
            $selected = array_flip(array_map('sanitize_key', (array)$selected));
            echo '<div class="wpmu-ml-checkgrid">';
            foreach ($items as $item) {
                $label = $kind === 'taxonomy' ? $this->get_taxonomy_label($item) : $this->get_post_type_label($item);
                $exclusive_attrs = '';
                if ($kind === 'post_type' && $exclusive_role !== '') {
                    $exclusive_attrs = ' class="wpmu-ml-posttype-exclusive" data-wpmu-ml-role="' . esc_attr($exclusive_role) . '" data-wpmu-ml-type="' . esc_attr($item) . '"';
                }
                echo '<label><input type="checkbox"' . $exclusive_attrs . ' name="' . esc_attr($name) . '[]" value="' . esc_attr($item) . '" ' . checked(isset($selected[$item]), true, false) . '> <code>' . esc_html($item) . '</code>';
                if ($label && $label !== $item) {
                    echo ' <span class="wpmu-ml-muted">' . esc_html($label) . '</span>';
                }
                echo '</label>';
            }
            echo '</div>';
        }

        private function manual_items_not_in_candidates($items, $candidates)
        {
            $candidates = array_flip(array_map('sanitize_key', (array)$candidates));
            $out = [];
            foreach ((array)$items as $item) {
                $item = sanitize_key($item);
                if ($item !== '' && !isset($candidates[$item])) {
                    $out[] = $item;
                }
            }
            return implode("\n", array_values(array_unique($out)));
        }

        private function merge_checked_and_manual($checked_key, $manual_key)
        {
            $checked = isset($_POST[$checked_key]) && is_array($_POST[$checked_key]) ? $_POST[$checked_key] : [];
            $manual = $this->textarea_to_array($_POST[$manual_key] ?? '');
            $items = array_merge($checked, $manual);
            $out = [];
            foreach ($items as $item) {
                $item = sanitize_key($item);
                if ($item !== '') {
                    $out[] = $item;
                }
            }
            return array_values(array_unique($out));
        }

        private function get_detected_post_types()
        {
            global $wpdb;
            $settings = $this->get_settings();
            $types = [];
            foreach ((array)get_post_types([], 'objects') as $name => $obj) {
                $types[] = $name;
            }
            $source_blog_id = absint($settings['source_blog_id']);
            if ($source_blog_id) {
                $prefix = $wpdb->get_blog_prefix($source_blog_id);
                $db_types = $wpdb->get_col("SELECT DISTINCT post_type FROM `{$prefix}posts` WHERE post_type <> '' ORDER BY post_type ASC");
                $types = array_merge($types, (array)$db_types);
            }
            $types = array_merge($types, (array)$settings['translatable_post_types'], (array)$settings['shared_post_types']);
            $types = array_values(array_unique(array_filter(array_map('sanitize_key', $types))));
            $types = array_values(array_diff($types, $this->get_hard_ignored_post_types()));
            sort($types, SORT_NATURAL);
            return $types;
        }

        private function get_hard_ignored_post_types()
        {
            return [
                'attachment',
                'revision',
                'nav_menu_item',
                'acf-field-group',
                'acf-field',
                'acf-post-type',
                'acf-taxonomy',
                'acf-ui-options-page',
                'custom_css',
                'customize_changeset',
                'oembed_cache',
                'user_request',
                'wp_global_styles',
                'wp_template',
                'wp_template_part',
                'wp_navigation',
            ];
        }

        private function get_detected_taxonomies()
        {
            global $wpdb;
            $settings = $this->get_settings();
            $taxonomies = [];
            foreach ((array)get_taxonomies([], 'objects') as $name => $obj) {
                $taxonomies[] = $name;
            }
            $source_blog_id = absint($settings['source_blog_id']);
            if ($source_blog_id) {
                $prefix = $wpdb->get_blog_prefix($source_blog_id);
                $db_tax = $wpdb->get_col("SELECT DISTINCT taxonomy FROM `{$prefix}term_taxonomy` WHERE taxonomy <> '' ORDER BY taxonomy ASC");
                $taxonomies = array_merge($taxonomies, (array)$db_tax);
            }
            $raw_settings = get_site_option(self::OPTION, []);
            if (!empty($raw_settings['sync_taxonomies']) && is_array($raw_settings['sync_taxonomies'])) {
                $taxonomies = array_merge($taxonomies, (array)$raw_settings['sync_taxonomies']);
            }
            $taxonomies = array_values(array_unique(array_filter(array_map('sanitize_key', $taxonomies))));
            $taxonomies = array_values(array_diff($taxonomies, $this->get_hard_ignored_taxonomies()));
            sort($taxonomies, SORT_NATURAL);
            return $taxonomies;
        }

        private function get_hard_ignored_taxonomies()
        {
            return [
                'nav_menu',
                'link_category',
                'wp_theme',
                'wp_template_part_area',
            ];
        }

        private function get_selected_sync_taxonomies_for_ui($taxonomies)
        {
            $raw_settings = get_site_option(self::OPTION, []);

            if (array_key_exists('sync_taxonomies', (array)$raw_settings)) {
                return array_values(array_unique(array_filter(array_map('sanitize_key', (array)$raw_settings['sync_taxonomies']))));
            }

            $settings = $this->get_settings();
            $excluded = array_merge((array)$settings['excluded_taxonomies'], $this->get_hard_ignored_taxonomies());
            return array_values(array_diff((array)$taxonomies, array_map('sanitize_key', $excluded)));
        }

        private function get_effective_sync_taxonomies($candidate_taxonomies = [])
        {
            $raw_settings = get_site_option(self::OPTION, []);

            if (array_key_exists('sync_taxonomies', (array)$raw_settings)) {
                return array_values(array_unique(array_filter(array_map('sanitize_key', (array)$raw_settings['sync_taxonomies']))));
            }

            $settings = $this->get_settings();
            $excluded = array_merge((array)$settings['excluded_taxonomies'], $this->get_hard_ignored_taxonomies());
            $candidate_taxonomies = array_values(array_unique(array_filter(array_map('sanitize_key', (array)$candidate_taxonomies))));
            return array_values(array_diff($candidate_taxonomies, array_map('sanitize_key', $excluded)));
        }

        private function get_post_type_label($post_type)
        {
            $obj = get_post_type_object($post_type);
            return $obj && !empty($obj->label) ? $obj->label : $post_type;
        }

        private function get_taxonomy_label($taxonomy)
        {
            $obj = get_taxonomy($taxonomy);
            return $obj && !empty($obj->label) ? $obj->label : $taxonomy;
        }

        private function render_relations_page()
        {
            global $wpdb;
            echo '<h2>关联统计</h2>';
            $invalid_statuses = ['target_missing','target_identity_conflict','target_slug_conflict','relation_invalid','target_deleted','target_trashed'];
            $invalid_in = implode(',', array_map(function($status) {
                return "'" . esc_sql($status) . "'";
            }, $invalid_statuses));
            $invalid_counts = $wpdb->get_results(
                "SELECT relation_status, COUNT(*) AS cnt FROM {$this->tables['posts']} WHERE relation_status IN ({$invalid_in}) GROUP BY relation_status ORDER BY relation_status",
                ARRAY_A
            );
            if ($invalid_counts) {
                $parts = [];
                foreach ($invalid_counts as $invalid_count) {
                    $parts[] = esc_html((string)$invalid_count['relation_status']) . '：' . intval($invalid_count['cnt']);
                }
                echo '<div class="notice notice-error inline"><p><strong>存在需要人工处理的文章关系。</strong>' . implode('；', $parts) . '。异常关系已阻止自动更新、翻译写回或删除，请先运行只读审计。</p></div>';
            }
            $rows = $wpdb->get_results("SELECT target_lang, target_blog_id, post_type, relation_status, COUNT(*) AS cnt FROM {$this->tables['posts']} GROUP BY target_lang, target_blog_id, post_type, relation_status ORDER BY target_blog_id, post_type", ARRAY_A);
            if ($rows) {
                echo '<table class="widefat striped"><thead><tr><th>目标语言</th><th>Blog ID</th><th>文章类型</th><th>状态</th><th>数量</th></tr></thead><tbody>';
                foreach ($rows as $r) {
                    echo '<tr><td>' . esc_html($r['target_lang']) . '</td><td>' . esc_html($r['target_blog_id']) . '</td><td>' . esc_html($r['post_type']) . '</td><td>' . esc_html($r['relation_status']) . '</td><td>' . esc_html($r['cnt']) . '</td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p>还没有关联数据。</p>';
            }
            echo '<hr>';
            echo '<div class="notice notice-warning inline"><p><strong>生产关系重建已禁用。</strong>0.9.8 不再清空关系表后按相同 ID 或 slug 猜测关联。</p><p>全站只读汇总：<code>wp wpmu-ml audit-relations --summary --allow-root --skip-themes</code></p><p>逐条分页审计：<code>wp wpmu-ml audit-relations --target_blog_id=1 --limit=500 --offset=0 --allow-root --skip-themes</code></p><p>严格来源 meta 恢复预览：<code>wp wpmu-ml reconcile-relations --target_blog_id=1 --limit=500 --allow-root --skip-themes</code></p></div>';
        }

        private function render_sync_page()
        {
            $settings = $this->get_settings();
            echo '<h2>自动同步</h2>';
            echo '<p>源站保存文章后，自动为其他语言站创建或更新目标草稿，并写入关联表。已发布且已翻译的目标文章默认不会被源站内容覆盖，只会标记为需要更新。</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="wpmu_ml_save_sync_settings">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>启用自动同步</th><td><label><input type="checkbox" name="auto_sync_enabled" value="1" ' . checked($settings['auto_sync_enabled'], 1, false) . '> 源站保存内容时自动同步到所有启用语言站</label></td></tr>';
            echo '<tr><th>源站更新时同步</th><td><label><input type="checkbox" name="auto_sync_on_update" value="1" ' . checked($settings['auto_sync_on_update'], 1, false) . '> 源站更新后同步未翻译草稿，并标记已翻译文章需要更新</label></td></tr>';
            echo '<tr><th>源站移入回收站时</th><td><select name="trash_sync_policy">';
            foreach (["none" => "不处理目标文章", "drafts_only" => "仅处理未翻译草稿和共享发布内容", "all" => "所有关联目标文章都移入回收站"] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '" ' . selected($settings['trash_sync_policy'], $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select><p class="description">默认策略会保护已翻译/已发布目标文章，只标记为 source_trashed_keep。</p></td></tr>';
            echo '<tr><th>源站永久删除时</th><td><select name="delete_sync_policy">';
            foreach (["none" => "不处理目标文章，只标记源文已删除", "drafts_only" => "仅永久删除未翻译草稿和共享发布内容", "all" => "永久删除所有关联目标文章"] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '" ' . selected($settings['delete_sync_policy'], $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select><p class="description">正式站建议不要选“所有”，除非确认目标语言没有独立内容。</p></td></tr>';
            echo '<tr><th>源站恢复时</th><td><label><input type="checkbox" name="restore_sync_enabled" value="1" ' . checked($settings['restore_sync_enabled'], 1, false) . '> 恢复由插件同步移入回收站的目标文章</label></td></tr>';
            echo '<tr><th>目标默认状态</th><td><select name="target_default_status"><option value="draft" ' . selected($settings['target_default_status'], 'draft', false) . '>草稿 draft</option><option value="pending" ' . selected($settings['target_default_status'], 'pending', false) . '>待审核 pending</option></select><p class="description">控制目标文章刚同步生成时的初始状态。共享发布文章类型仍自动保持 publish。机器翻译或 OpenCC 转换完成后的状态，请到“翻译引擎”中设置。</p></td></tr>';
            echo '<tr><th>保护已翻译内容</th><td><label><input type="checkbox" name="protect_translated" value="1" ' . checked($settings['protect_translated'], 1, false) . '> 目标关联状态为 translated / needs_update / machine_translated 时，不覆盖标题、正文、摘要和字段，只标记 needs_update</label></td></tr>';
            echo '<tr><th>同步后加入翻译队列</th><td><label><input type="checkbox" name="queue_on_sync" value="1" ' . checked($settings['queue_on_sync'], 1, false) . '> 新建或更新目标草稿后，自动生成翻译任务</label></td></tr>';
            echo '<tr><th>同步字段</th><td>';
            foreach (['sync_title' => '标题', 'sync_content' => '正文', 'sync_excerpt' => '摘要', 'sync_meta' => '自定义字段 / ACF / SEO meta', 'sync_terms' => '分类关系'] as $key => $label) {
                echo '<label style="display:inline-block;margin-inline-end:16px;margin-block-end:8px"><input type="checkbox" name="' . esc_attr($key) . '" value="1" ' . checked($settings[$key], 1, false) . '> ' . esc_html($label) . '</label>';
            }
            echo '<p class="description"><strong>slug 固定保护：</strong>目标文章 URL slug 永远同步源站 <code>post_name</code>，不提供关闭选项，OpenAI 兼容 / OpenCC / SEO 字段都不得翻译 slug。</p>';
            echo '</td></tr>';
            echo '</tbody></table>';
            submit_button('保存自动同步设置');
            echo '</form>';

            echo '<hr><h2>手动测试同步</h2>';
            echo '<p>用于测试下一步效果：从源站取最近若干篇内容，补齐目标语言草稿并加入翻译队列。</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'确定执行批量同步测试吗？\');">';
            echo '<input type="hidden" name="action" value="wpmu_ml_run_batch_sync">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
            echo '<p><label>处理最近 <input type="number" name="limit" value="20" min="1" max="500" style="width:90px"> 篇源站内容</label></p>';
            submit_button('同步最近内容并生成翻译队列', 'secondary');
            echo '</form>';
        }

        private function render_translation_page()
        {
            global $wpdb;
            $settings = $this->get_settings();
            echo '<style>.wpmu-ml-select{min-width:160px}.wpmu-ml-small-select{min-width:120px}.wpmu-ml-job-actions form{display:inline-block;margin:0;margin-inline-end:4px;margin-block-end:4px}.wpmu-ml-nowrap{white-space:nowrap}.wpmu-ml-status-code{color:#666;font-size:12px}.wpmu-ml-queue-controls{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:8px 0 10px}.wpmu-ml-queue-controls form{display:flex!important;align-items:center;gap:12px;flex-wrap:wrap;margin:0!important}.wpmu-ml-queue-controls label{display:flex;align-items:center;gap:6px;margin:0}.wpmu-ml-queue-controls input[type=number]{width:80px;height:36px;min-height:36px;line-height:34px;padding:0 8px;margin:0}.wpmu-ml-queue-controls select{height:36px;min-height:36px;line-height:34px;margin:0}.wpmu-ml-queue-controls .button{height:36px;min-height:36px;line-height:34px;padding:0 16px;margin:0}.wpmu-ml-queue-subtabs{display:flex;flex-wrap:wrap;gap:8px;margin:18px 0 14px;border-bottom:1px solid #ccd0d4}.wpmu-ml-queue-subtab{border:1px solid #ccd0d4;border-bottom:none;background:#f6f7f7;color:#1d2327;padding:9px 14px;border-radius:4px 4px 0 0;cursor:pointer;font-weight:600}.wpmu-ml-queue-subtab.is-active{background:#fff;color:#2271b1;border-bottom:1px solid #fff;margin-bottom:-1px}.wpmu-ml-queue-panel{display:none;background:#fff;border:1px solid #ccd0d4;padding:18px 20px 18px;max-width:1280px}.wpmu-ml-queue-panel.is-active{display:block}.wpmu-ml-queue-panel h2{margin-top:0}.wpmu-ml-queue-help{max-width:1100px}.wpmu-ml-queue-summary{margin:10px 0 16px}.wpmu-ml-queue-summary code{font-size:12px}.wpmu-ml-error-snippet{cursor:help;border-bottom:1px dotted #646970}.wpmu-ml-error-empty{color:#8c8f94}.wpmu-ml-queue-filter-note{margin:0 0 12px;color:#646970}@media (max-width:782px){.wpmu-ml-queue-controls,.wpmu-ml-queue-controls form{align-items:flex-start;flex-direction:column}.wpmu-ml-queue-controls label{width:100%;justify-content:flex-start}.wpmu-ml-queue-subtabs{align-items:stretch}.wpmu-ml-queue-subtab{width:100%;border-bottom:1px solid #ccd0d4;border-radius:4px}}</style>';
            echo '<h2>翻译队列</h2>';
            echo '<p class="wpmu-ml-queue-help">这里负责队列任务查看、单篇入队、手动处理、锁释放和队列运行参数。翻译 API、模型和各语言翻译方式请到“翻译引擎”选项卡设置。</p>';
            echo '<div class="notice notice-info inline wpmu-ml-queue-summary"><p><strong>页面已分组：</strong>常看任务用“最近任务”，待处理、失败/复核、已完成分开查看；查数量用“队列统计”，临时补任务用“单篇翻译”，手动跑队列和释放锁用“处理与维护”，运行参数放在“队列设置”。</p></div>';
            echo '<div class="wpmu-ml-queue-subtabs" id="wpmu-ml-queue-subtabs">';
            echo '<button type="button" class="wpmu-ml-queue-subtab is-active" data-panel="recent">最近任务</button>';
            echo '<button type="button" class="wpmu-ml-queue-subtab" data-panel="pending">待处理</button>';
            echo '<button type="button" class="wpmu-ml-queue-subtab" data-panel="attention">失败 / 需复核</button>';
            echo '<button type="button" class="wpmu-ml-queue-subtab" data-panel="done">已完成</button>';
            echo '<button type="button" class="wpmu-ml-queue-subtab" data-panel="stats">队列统计</button>';
            echo '<button type="button" class="wpmu-ml-queue-subtab" data-panel="single">单篇翻译</button>';
            echo '<button type="button" class="wpmu-ml-queue-subtab" data-panel="process">处理与维护</button>';
            echo '<button type="button" class="wpmu-ml-queue-subtab" data-panel="settings">队列设置</button>';
            echo '</div>';
            echo '<div id="wpmu-ml-queue-panel-settings" class="wpmu-ml-queue-panel">';
            echo '<h2>队列设置</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="wpmu_ml_save_translation_settings">';
            echo '<input type="hidden" name="settings_section" value="queue">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>队列处理方式</th><td><select class="wpmu-ml-select" name="translation_queue_runner">';
            foreach (['manual' => '手动处理', 'wp_cron' => 'WP-Cron 定时处理', 'cli' => '仅 WP-CLI 处理'] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '" ' . selected($settings['translation_queue_runner'], $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select><p class="description">正式站建议先用“手动处理”或“仅 WP-CLI”，稳定后再启用 WP-Cron。</p></td></tr>';
            echo '<tr><th>每批处理数量</th><td><input type="number" name="translation_queue_limit" value="' . esc_attr(absint($settings['translation_queue_limit'])) . '" min="1" max="20" style="width:90px"> 个任务 <p class="description">一次队列运行最多取多少条任务；它不是“同时翻译数量”。建议 1-3。</p></td></tr>';
            echo '<tr><th colspan="2"><h3 style="margin:12px 0 0">并发与领取限制</h3><p class="description">用于限制同一时间正在处理/领取的任务数，避免多个 WP-Cron、WP-CLI 或外部 Agent 同时抢占过多资源。</p></th></tr>';
            echo '<tr><th>OpenAI 兼容最大并发</th><td><input type="number" name="translation_openai_concurrency" value="' . esc_attr(max(1, absint($settings['translation_openai_concurrency'] ?? 1))) . '" min="1" max="10" style="width:90px"> 个任务 <p class="description">限制同一时间正在调用 OpenAI 兼容接口的任务数。正式批量建议 1-2。</p></td></tr>';
            echo '<tr><th>OpenCC 最大并发</th><td><input type="number" name="translation_opencc_concurrency" value="' . esc_attr(max(1, absint($settings['translation_opencc_concurrency'] ?? 5))) . '" min="1" max="50" style="width:90px"> 个任务 <p class="description">OpenCC 是本地转换，通常可以比 OpenAI 兼容更高。建议 5-10。</p></td></tr>';
            echo '<tr><th>Agent API 最大领取数</th><td><input type="number" name="translation_agent_claim_limit" value="' . esc_attr(max(1, absint($settings['translation_agent_claim_limit'] ?? 1))) . '" min="1" max="20" style="width:90px"> 个任务 <p class="description">限制外部 Agent 同时领取的 agent 任务数；实际翻译并发仍由本地 Agent 自己控制。</p></td></tr>';
            echo '<tr><th>任务锁超时</th><td><input type="number" name="translation_lock_ttl_minutes" value="' . esc_attr(absint($settings['translation_lock_ttl_minutes'])) . '" min="1" max="120" style="width:90px"> 分钟 <p class="description">任务异常中断后，超过该时间可自动释放锁。</p></td></tr>';
            echo '<tr><th>失败重试次数</th><td><input type="number" name="translation_max_attempts" value="' . esc_attr(absint($settings['translation_max_attempts'])) . '" min="0" max="20" style="width:90px"> 次 <p class="description">达到次数后才标记为失败；未达到前会自动延迟重试。</p></td></tr>';
            echo '<tr><th>失败后重试间隔</th><td><input type="number" name="translation_retry_delay_minutes" value="' . esc_attr(absint($settings['translation_retry_delay_minutes'] ?? 10)) . '" min="0" max="1440" style="width:90px"> 分钟 <p class="description">0 表示下一次队列运行即可重试。建议 5-30 分钟。</p></td></tr>';
            echo '</tbody></table>';
            submit_button('保存队列设置');
            echo '</form>';
            echo '</div>';

            $sites = $this->get_i18n_sites();
            $source_blog_id = absint($settings['source_blog_id']);
            $target_lang_options = [];
            foreach ($sites as $site) {
                if (empty($site['enabled']) || (int)$site['blog_id'] === $source_blog_id) {
                    continue;
                }
                $lang = sanitize_key($site['lang_slug']);
                if ($lang) {
                    $target_lang_options[$lang] = $lang;
                }
            }

            if (!empty($_GET['wpmu_ml_error'])) {
                echo '<div class="notice notice-error"><p>' . esc_html(wp_unslash($_GET['wpmu_ml_error'])) . '</p></div>';
            }
            if (!empty($_GET['wpmu_ml_message'])) {
                echo '<div class="notice notice-success"><p>' . esc_html(wp_unslash($_GET['wpmu_ml_message'])) . '</p></div>';
            }

            echo '<div id="wpmu-ml-queue-panel-single" class="wpmu-ml-queue-panel">';
            echo '<h2>单篇指定翻译</h2>';
            echo '<p class="description">输入源站文章 ID 或目标语言文章 ID，选择目标语言后，可重建任务并加入翻译队列。默认不在当前后台页面直接调用 API，适合长文和 ACF 内容。</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(&quot;确定要对指定文章创建/重置翻译任务吗？如果勾选强制覆盖，会把目标文章重新设为待审/草稿并覆盖标题、正文、摘要和可翻译字段。&quot;);">';
            echo '<input type="hidden" name="action" value="wpmu_ml_translate_single">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>文章 ID</th><td><input type="number" name="single_post_id" min="1" style="width:160px" placeholder="例如 12346783"> <span class="description">可填 /zh-hans/ 源文章 ID，也可填目标语言文章 ID。</span></td></tr>';
            echo '<tr><th>目标语言</th><td><select class="wpmu-ml-select" name="single_target_lang">';
            $single_sites = $this->get_i18n_sites();
            $single_source_blog_id = absint($settings['source_blog_id']);
            foreach ($single_sites as $site) {
                if (empty($site['enabled']) || (int)$site['blog_id'] === $single_source_blog_id) {
                    continue;
                }
                echo '<option value="' . esc_attr($site['lang_slug']) . '">' . esc_html($site['lang_slug']) . ' ｜ Blog ID ' . esc_html($site['blog_id']) . '</option>';
            }
            echo '</select></td></tr>';
            echo '<tr><th>翻译方式</th><td><select class="wpmu-ml-select" name="single_engine"><option value="">按该语言设置</option>';
            foreach ($this->get_translation_engines() as $key => $label) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($label) . '</option>';
            }
            echo '</select></td></tr>';
            echo '<tr><th>覆盖方式</th><td><label><input type="checkbox" name="single_overwrite" value="1" checked> 强制覆盖已有目标内容</label><p class="description">建议重翻时勾选。会把关联状态改回 needs_translation，并按自动同步默认状态把目标文章设为 draft/pending，然后由翻译结果覆盖。</p></td></tr>';
            echo '<tr><th>执行方式</th><td><label><input type="checkbox" name="single_run_now" value="1"> 立即翻译</label><p class="description">默认不勾选：只创建/重置 pending 队列，不触发后台事件，不调用 API，也不会自动加锁。勾选后才会在当前后台请求中马上调用 API。</p></td></tr>';
            echo '</tbody></table>';
            submit_button('开始单篇翻译', 'primary');
            echo '</form>';
            echo '<p class="description">WP-CLI：<code>wp wpmu-ml translate-one --post_id=12346783 --lang=en --force --allow-root --skip-themes</code></p>';

            echo '</div>';
            echo '<div id="wpmu-ml-queue-panel-process" class="wpmu-ml-queue-panel">';
            echo '<h2>处理与维护</h2>';
            echo '<h3>手动处理队列</h3>';
            echo '<p>点击后会在当前后台请求中直接处理少量队列任务，并显示扫描/锁定/处理/失败数量；长文或批量翻译建议每次 1 个任务，正式批量建议用 WP-CLI/系统 Cron。</p>';
            $queue_limit_for_cli = max(1, min(20, absint($settings['translation_queue_limit'])));
            echo '<div class="wpmu-ml-queue-controls">';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpmu-ml-process-queue-form">';
            echo '<input type="hidden" name="action" value="wpmu_ml_process_queue">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
            echo '<label class="wpmu-ml-nowrap">处理 <input id="wpmu-ml-queue-limit" type="number" name="limit" value="' . esc_attr($queue_limit_for_cli) . '" min="1" max="20"> 个任务</label>';
            echo '<label class="wpmu-ml-nowrap">目标语言 <select id="wpmu-ml-queue-lang" class="wpmu-ml-small-select" name="target_lang"><option value="">全部</option>';
            foreach ($target_lang_options as $lang) {
                echo '<option value="' . esc_attr($lang) . '">' . esc_html($lang) . '</option>';
            }
            echo '</select></label>';
            echo '<label class="wpmu-ml-nowrap">指定任务ID <input id="wpmu-ml-queue-job-id" type="number" name="job_id" min="1" placeholder="可选"></label>';
            submit_button('处理队列', 'secondary', 'submit', false);
            echo '</form>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="wpmu-ml-release-locks-form">';
            echo '<input type="hidden" name="action" value="wpmu_ml_release_queue_locks">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
            submit_button('释放超时锁', 'secondary', 'submit', false);
            echo '</form>';
            echo '</div>';
            echo '<p class="description">WP-CLI：<code id="wpmu-ml-queue-cli">' . esc_html('wp wpmu-ml translate --limit=' . $queue_limit_for_cli . ' --allow-root --skip-themes') . '</code></p>';
            echo '<script>(function(){var limit=document.getElementById("wpmu-ml-queue-limit"),lang=document.getElementById("wpmu-ml-queue-lang"),job=document.getElementById("wpmu-ml-queue-job-id"),cli=document.getElementById("wpmu-ml-queue-cli");function update(){if(!cli)return;var jid=job?parseInt(job.value,10):0;if(jid&&jid>0){cli.textContent="wp wpmu-ml translate --job_id="+jid+" --allow-root --skip-themes";return;}var n=limit?parseInt(limit.value,10):1;if(!n||n<1)n=1;if(n>20)n=20;var cmd="wp wpmu-ml translate --limit="+n;if(lang&&lang.value){cmd += " --lang="+lang.value;}cmd += " --allow-root --skip-themes";cli.textContent=cmd;}[limit,lang,job].forEach(function(el){if(el){el.addEventListener("input",update);el.addEventListener("change",update);}});update();})();</script>';

            echo '</div>';
            echo '<div id="wpmu-ml-queue-panel-stats" class="wpmu-ml-queue-panel">';
            echo '<h2>队列统计</h2>';
            $stats = $wpdb->get_results("SELECT target_lang, engine, status, COUNT(*) AS total FROM {$this->tables['jobs']} GROUP BY target_lang, engine, status ORDER BY target_lang, engine, status", ARRAY_A);
            if ($stats) {
                echo '<table class="widefat striped"><thead><tr><th>目标语言</th><th>引擎</th><th>状态</th><th>数量</th></tr></thead><tbody>';
                foreach ($stats as $row) {
                    echo '<tr><td>' . esc_html($row['target_lang']) . '</td><td>' . esc_html($this->translation_engine_label($row['engine'])) . '<br><code>' . esc_html($row['engine']) . '</code></td><td>' . esc_html($this->translation_status_label($row['status'])) . '<br><code class="wpmu-ml-status-code">' . esc_html($row['status']) . '</code></td><td>' . esc_html($row['total']) . '</td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p>暂无翻译任务。</p>';
            }

            echo '</div>';
            echo '<div id="wpmu-ml-queue-panel-recent" class="wpmu-ml-queue-panel is-active">';
            echo '<h2>最近任务</h2>';
            echo '<p class="wpmu-ml-queue-filter-note">显示最近 50 条任务，包含待处理、失败、已完成等历史记录。错误/说明被截断时，将鼠标放到文字上可查看完整内容。</p>';
            $jobs = $wpdb->get_results("SELECT * FROM {$this->tables['jobs']} ORDER BY id DESC LIMIT 50", ARRAY_A);
            $this->render_queue_jobs_table($jobs, '暂无最近任务。');
            echo '</div>';

            echo '<div id="wpmu-ml-queue-panel-pending" class="wpmu-ml-queue-panel">';
            echo '<h2>待处理</h2>';
            echo '<p class="wpmu-ml-queue-filter-note">只显示仍需要处理或等待 Agent / 人工动作的任务。</p>';
            $pending_statuses = ['pending', 'needs_update', 'translated_update_pending', 'machine_pending', 'agent_pending', 'agent_claimed', 'agent_payload_sent', 'manual_waiting'];
            $pending_in = "'" . implode("','", array_map('esc_sql', $pending_statuses)) . "'";
            $pending_jobs = $wpdb->get_results("SELECT * FROM {$this->tables['jobs']} WHERE status IN ({$pending_in}) ORDER BY priority DESC, id ASC LIMIT 100", ARRAY_A);
            $this->render_queue_jobs_table($pending_jobs, '暂无待处理任务。');
            echo '</div>';

            echo '<div id="wpmu-ml-queue-panel-attention" class="wpmu-ml-queue-panel">';
            echo '<h2>失败 / 需复核</h2>';
            echo '<p class="wpmu-ml-queue-filter-note">只显示失败、Agent 失败、结构校验失败或需要人工复查的任务。错误/说明列支持鼠标悬停查看完整内容。</p>';
            $attention_statuses = ['failed', 'agent_failed', 'review_required'];
            $attention_in = "'" . implode("','", array_map('esc_sql', $attention_statuses)) . "'";
            $attention_jobs = $wpdb->get_results("SELECT * FROM {$this->tables['jobs']} WHERE status IN ({$attention_in}) ORDER BY updated_at DESC, id DESC LIMIT 100", ARRAY_A);
            $this->render_queue_jobs_table($attention_jobs, '暂无失败或需复核任务。');
            echo '</div>';

            echo '<div id="wpmu-ml-queue-panel-done" class="wpmu-ml-queue-panel">';
            echo '<h2>已完成</h2>';
            echo '<p class="wpmu-ml-queue-filter-note">显示已经翻译、转换、发布或人工完成的历史任务。文章发布后任务不会消失，会保留在这里便于追踪和重新翻译。</p>';
            $done_statuses = ['machine_translated', 'machine_done_published', 'agent_translated', 'agent_done_published', 'opencc_converted', 'opencc_done_published', 'manual_done', 'translated', 'skipped'];
            $done_in = "'" . implode("','", array_map('esc_sql', $done_statuses)) . "'";
            $done_jobs = $wpdb->get_results("SELECT * FROM {$this->tables['jobs']} WHERE status IN ({$done_in}) ORDER BY updated_at DESC, id DESC LIMIT 100", ARRAY_A);
            $this->render_queue_jobs_table($done_jobs, '暂无已完成任务。');
            echo '</div>';
            echo '<script>(function(){var tabs=document.querySelectorAll("#wpmu-ml-queue-subtabs .wpmu-ml-queue-subtab");var panels=document.querySelectorAll(".wpmu-ml-queue-panel");function show(id){if(!id){id="recent";}var found=false;panels.forEach(function(p){var active=p.id==="wpmu-ml-queue-panel-"+id;p.classList.toggle("is-active",active);if(active){found=true;}});if(!found){id="recent";panels.forEach(function(p){p.classList.toggle("is-active",p.id==="wpmu-ml-queue-panel-recent");});}tabs.forEach(function(t){t.classList.toggle("is-active",t.getAttribute("data-panel")===id);});try{localStorage.setItem("wpmuMlQueueTab",id);}catch(e){}if(window.history&&window.history.replaceState){window.history.replaceState(null,"",window.location.pathname+window.location.search+"#queue-"+id);}}var start="recent";if(window.location.hash&&window.location.hash.indexOf("#queue-")===0){start=window.location.hash.replace("#queue-","");}else{try{start=localStorage.getItem("wpmuMlQueueTab")||"recent";}catch(e){}}tabs.forEach(function(t){t.addEventListener("click",function(){show(t.getAttribute("data-panel"));});});show(start);})();</script>';
        }

        private function render_queue_jobs_table($jobs, $empty_message = '暂无任务。')
        {
            if (empty($jobs)) {
                echo '<p>' . esc_html($empty_message) . '</p>';
                return;
            }

            $done_statuses = ['machine_translated', 'machine_done_published', 'agent_translated', 'agent_done_published', 'opencc_converted', 'opencc_done_published', 'manual_done', 'translated', 'skipped'];
            echo '<table class="widefat striped"><thead><tr><th>ID</th><th>源文章</th><th>目标站</th><th>目标语言</th><th>目标文章</th><th>类型</th><th>引擎</th><th>状态</th><th>尝试</th><th>锁</th><th>更新时间</th><th>错误/说明</th><th>操作</th></tr></thead><tbody>';
            foreach ($jobs as $j) {
                $locked = !empty($j['locked_at']) ? (esc_html($j['locked_at']) . '<br><code>' . esc_html($j['locked_by']) . '</code>') : '-';
                $error_full = isset($j['last_error']) ? trim((string)$j['last_error']) : '';
                if ($error_full !== '') {
                    $error_short = wp_trim_words($error_full, 18);
                    $error_cell = '<span class="wpmu-ml-error-snippet" title="' . esc_attr($error_full) . '">' . esc_html($error_short) . '</span>';
                } else {
                    $error_cell = '<span class="wpmu-ml-error-empty">-</span>';
                }

                $engine_cell = esc_html($this->translation_engine_label($j['engine'])) . '<br><code>' . esc_html($j['engine']) . '</code>';
                if (!empty($j['model'])) {
                    $engine_cell .= '<br><span class="description">model: <code>' . esc_html($j['model']) . '</code></span>';
                }
                if (!empty($j['route_reason'])) {
                    $engine_cell .= '<br><span class="description">route: <code>' . esc_html($j['route_reason']) . '</code></span>';
                }

                $engine_key = sanitize_key($j['engine'] ?? '');
                $status_key = sanitize_key($j['status'] ?? '');
                $is_done = in_array($status_key, $done_statuses, true);

                echo '<tr><td>' . esc_html($j['id']) . '</td><td>' . esc_html($j['source_post_id']) . '</td><td>' . esc_html($j['target_blog_id']) . '</td><td>' . esc_html($j['target_lang']) . '</td><td>' . esc_html($j['target_post_id']) . '</td><td>' . esc_html($j['post_type']) . '</td><td>' . $engine_cell . '</td><td>' . esc_html($this->translation_status_label($j['status'])) . '<br><code class="wpmu-ml-status-code">' . esc_html($j['status']) . '</code></td><td>' . esc_html($j['attempts']) . '</td><td>' . $locked . '</td><td>' . esc_html($j['updated_at']) . '</td><td>' . $error_cell . '</td><td class="wpmu-ml-job-actions">';
                if (!$is_done) {
                    if ($engine_key === 'manual') {
                        $this->render_job_action_button((int)$j['id'], 'manual_done', '人工完成');
                    } elseif ($engine_key !== 'agent') {
                        $this->render_job_action_button((int)$j['id'], 'machine_translate', '机器处理');
                    }
                }
                $this->render_job_action_button((int)$j['id'], 'retranslate', $engine_key === 'agent' ? '重新入队' : '重新翻译');
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }

        private function render_engines_page($active_tab = 'routing')
        {
            $valid_tabs = ['routing', 'openai', 'agent', 'opencc', 'manual', 'rules', 'advanced'];
            $active_tab = in_array($active_tab, $valid_tabs, true) ? $active_tab : 'routing';
            $settings = $this->get_settings();
            $default_engines = $this->get_default_translation_engines();
            $status_labels = ['draft' => '草稿 draft', 'pending' => '待审核 pending', 'publish' => '直接发布 publish'];
            $default_engine = $this->normalize_translation_engine_key($settings['translation_default_engine'] ?? 'manual', '');
            if (!array_key_exists($default_engine, $default_engines)) {
                $default_engine = 'manual';
            }

            echo '<style>';
            echo '.wpmu-ml-select{min-width:180px}.wpmu-ml-engine-table td{vertical-align:middle}.wpmu-ml-engine-note{max-width:980px}.wpmu-ml-mini-code{background:#f6f7f7;border:1px solid #dcdcde;padding:2px 5px;border-radius:3px}';
            echo '.wpmu-ml-engine-panel{display:none;background:#fff;border:1px solid #ccd0d4;padding:18px 20px 8px;max-width:1240px;margin-top:0}.wpmu-ml-engine-panel.is-active{display:block}.wpmu-ml-engine-panel h2{margin-top:0}.wpmu-ml-route-priority{margin:0;margin-block-start:8px;margin-inline-start:18px}.wpmu-ml-route-priority li{margin-bottom:4px}.wpmu-ml-preview-table select[disabled],.wpmu-ml-preview-table input[disabled]{opacity:.65}.wpmu-ml-subtab-save{margin-top:18px}.wpmu-ml-route-help{margin:10px 0 18px;max-width:1100px}.wpmu-ml-combo-notice{max-width:1120px}.wpmu-ml-combo-rules,.wpmu-ml-route-language-rules{border:1px solid #c3c4c7;background:#fff;padding:12px 14px;margin:10px 0 14px}.wpmu-ml-combo-rules summary,.wpmu-ml-route-language-rules summary{cursor:pointer;font-size:14px}';
            echo '.wpmu-ml-engine-inner-tabs{display:flex;flex-wrap:wrap;gap:6px;margin:14px 0 18px;padding:6px;background:#f0f0f1;border-radius:6px}.wpmu-ml-engine-inner-tab{border:1px solid transparent;background:transparent;padding:8px 13px;border-radius:4px;cursor:pointer;font-weight:600;color:#3c434a}.wpmu-ml-engine-inner-tab.is-active{background:#fff;border-color:#c3c4c7;color:#2271b1;box-shadow:0 1px 2px rgba(0,0,0,.05)}.wpmu-ml-engine-inner-panel{display:none}.wpmu-ml-engine-inner-panel.is-active{display:block}.wpmu-ml-language-profile{border:1px solid #c3c4c7;border-radius:6px;background:#fff;margin:0 0 12px}.wpmu-ml-language-profile summary{cursor:pointer;padding:13px 15px;font-weight:600;background:#f6f7f7}.wpmu-ml-language-profile__body{padding:6px 16px 16px}.wpmu-ml-language-profile .form-table th{width:190px}.wpmu-ml-language-profile textarea{max-width:900px}.wpmu-ml-language-meta{font-weight:400;color:#646970;margin-inline-start:8px}.wpmu-ml-engine-panel{max-width:none}.wpmu-ml-engine-primary-note{max-width:1180px}';
            echo '</style>';

            echo '<p class="wpmu-ml-engine-note wpmu-ml-engine-primary-note">这里已经从“翻译设置”中独立出来。上方使用与“翻译设置”一致的一级选项卡；每个引擎内部仍可继续增加自己的二级选项卡和语言级配置。</p>';
            echo '<div class="notice notice-info inline"><p>当前内置主翻译入口为：人工翻译、OpenAI 兼容、Agent API。OpenCC 不作为默认翻译引擎显示；只有源站识别为简体中文、目标语言为繁体中文时，才在目标语言规则中显示为高性能简繁转换方式。Agent API 不在 WordPress 内部调用模型，但会通过 <code>/agent/rules</code> 和任务 payload 向外部 Agent 提供本插件统一维护的翻译规则与术语库。</p></div>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" id="wpmu-ml-engine-settings-form">';
            echo '<input type="hidden" name="action" value="wpmu_ml_save_translation_settings">';
            echo '<input type="hidden" name="settings_section" value="engines">';
            echo '<input type="hidden" name="engine_tab" value="' . esc_attr($active_tab) . '">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

            echo '<div class="wpmu-ml-engine-panel' . ($active_tab === 'routing' ? ' is-active' : '') . '" data-panel="routing">';
            echo '<h2>默认与路由</h2>';
            echo '<p class="description">这里负责决定某个翻译任务进入队列时使用哪个引擎、完成后是什么文章状态。引擎自身的 Key、模型参数、OpenCC 路径等放到对应子选项卡。</p>';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>默认翻译引擎</th><td><select class="wpmu-ml-select" name="translation_default_engine">';
            foreach ($default_engines as $key => $label) {
                echo '<option value="' . esc_attr($key) . '" ' . selected($default_engine, $key, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select><p class="description">默认下拉不显示 OpenCC。非繁体语言通常使用 OpenAI 兼容或 Agent API；只有源站为简体中文且目标语言为繁体中文时，下方目标语言规则才会显示 s2twp / s2tw / s2hk / s2t。</p></td></tr>';
            echo '<tr><th>默认完成后状态</th><td><select class="wpmu-ml-select" name="translation_complete_status">';
            foreach ($status_labels as $value => $label) {
                echo '<option value="' . esc_attr($value) . '" ' . selected($settings['translation_complete_status'], $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select><p class="description">建议 pending。OpenAI 兼容、Agent API 或 OpenCC 写入完成后先待审核，确认无误后再发布。</p></td></tr>';
            echo '</tbody></table>';

            echo '<details class="wpmu-ml-route-language-rules"><summary><strong>按目标语言设置翻译方式</strong> <span class="description">（目标语言较多，默认折叠）</span></summary>';
            echo '<table class="widefat striped wpmu-ml-engine-table" style="margin-top:12px"><thead><tr><th>目标站</th><th>语言</th><th>翻译方式</th><th>允许自动处理</th><th>完成后状态</th></tr></thead><tbody>';
            $sites = $this->get_i18n_sites();
            $source_blog_id = absint($settings['source_blog_id']);
            $by_lang = is_array($settings['translation_engines_by_lang']) ? $settings['translation_engines_by_lang'] : [];
            $auto_by_lang = is_array($settings['translation_auto_by_lang']) ? $settings['translation_auto_by_lang'] : [];
            $status_by_lang = is_array($settings['translation_status_by_lang']) ? $settings['translation_status_by_lang'] : [];
            foreach ($sites as $site) {
                if (empty($site['enabled']) || (int)$site['blog_id'] === $source_blog_id) {
                    continue;
                }
                $lang = sanitize_key($site['lang_slug']);
                $lang_engines = $this->get_translation_engines_for_lang($lang);
                $explicit_engine = isset($by_lang[$lang]) ? $this->normalize_translation_engine_key($by_lang[$lang], $lang) : '';
                if ($explicit_engine !== '' && !array_key_exists($explicit_engine, $lang_engines)) {
                    $explicit_engine = '';
                }
                $complete_status = sanitize_key($status_by_lang[$lang] ?? $settings['translation_complete_status']);
                echo '<tr>';
                echo '<td><a href="' . esc_url($site['site_url']) . '" target="_blank">' . esc_html($site['site_url']) . '</a><br><span class="description">Blog ID: ' . esc_html($site['blog_id']) . '</span></td>';
                echo '<td><code>' . esc_html($lang) . '</code></td>';
                echo '<td><select class="wpmu-ml-select" name="translation_engines_by_lang[' . esc_attr($lang) . ']">';
                echo '<option value="" ' . selected($explicit_engine, '', false) . '>继承默认 / 文章类型规则</option>';
                foreach ($lang_engines as $key => $label) {
                    echo '<option value="' . esc_attr($key) . '" ' . selected($explicit_engine, $key, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select>';
                echo '</td>';
                echo '<td><label><input type="checkbox" name="translation_auto_by_lang[' . esc_attr($lang) . ']" value="1" ' . checked(!empty($auto_by_lang[$lang]), true, false) . '> 队列自动处理该语言</label></td>';
                echo '<td><select class="wpmu-ml-select" name="translation_status_by_lang[' . esc_attr($lang) . ']">';
                foreach ($status_labels as $value => $label) {
                    echo '<option value="' . esc_attr($value) . '" ' . selected($complete_status, $value, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select></td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
            echo '<p class="description wpmu-ml-route-help">说明：如果某个目标语言单独设置了翻译方式，则目标语言规则会优先于文章类型规则；如果需要指定“某语言下某文章类型”的例外，请使用下方“精确覆盖规则”。OpenCC 只用于“简体中文源站 → 繁体中文目标站”的简繁转换；其他源语言请使用 OpenAI 兼容、Agent API 或人工翻译。</p>';
            echo '</details>';

            echo '<h3>按文章类型设置翻译方式 / 模型</h3>';
            echo '<p class="description">这里从 v0.8.7 开始参与实际路由。适合让不同 CPT 使用不同引擎或 OpenAI 兼容模型，例如 knowledge_post 用 gpt-5.5，普通 post 用 gpt-5.4，某些 CPT 走 Agent。</p>';
            $post_types = array_values(array_unique(array_filter((array)$settings['translatable_post_types'])));
            if (!$post_types) {
                $post_types = $this->get_detected_post_types();
            }
            $post_type_engines = is_array($settings['translation_engines_by_post_type'] ?? null) ? $settings['translation_engines_by_post_type'] : [];
            $post_type_models = is_array($settings['translation_models_by_post_type'] ?? null) ? $settings['translation_models_by_post_type'] : [];
            $post_type_statuses = is_array($settings['translation_status_by_post_type'] ?? null) ? $settings['translation_status_by_post_type'] : [];
            if ($post_types) {
                echo '<table class="widefat striped wpmu-ml-route-table"><thead><tr><th>文章类型</th><th>翻译方式</th><th>OpenAI 兼容模型</th><th>完成后状态</th></tr></thead><tbody>';
                foreach ($post_types as $post_type) {
                    $pt = sanitize_key($post_type);
                    $pt_engine = sanitize_key($post_type_engines[$pt] ?? '');
                    $pt_model = trim((string)($post_type_models[$pt] ?? ''));
                    $pt_status = sanitize_key($post_type_statuses[$pt] ?? '');
                    echo '<tr>';
                    echo '<td><code>' . esc_html($pt) . '</code><br><span class="description">' . esc_html($this->get_post_type_label($pt)) . '</span></td>';
                    echo '<td><select class="wpmu-ml-select" name="translation_engines_by_post_type[' . esc_attr($pt) . ']">';
                    echo '<option value="" ' . selected($pt_engine, '', false) . '>继承默认 / 目标语言</option>';
                    foreach ($default_engines as $key => $label) {
                        echo '<option value="' . esc_attr($key) . '" ' . selected($pt_engine, $key, false) . '>' . esc_html($label) . '</option>';
                    }
                    echo '</select></td>';
                    echo '<td><input type="text" class="regular-text" name="translation_models_by_post_type[' . esc_attr($pt) . ']" value="' . esc_attr($pt_model) . '" placeholder="仅 OpenAI 兼容有效，例如 gpt-5.5"></td>';
                    echo '<td><select class="wpmu-ml-select" name="translation_status_by_post_type[' . esc_attr($pt) . ']">';
                    echo '<option value="" ' . selected($pt_status, '', false) . '>继承默认/目标语言</option>';
                    foreach ($status_labels as $value => $label) {
                        echo '<option value="' . esc_attr($value) . '" ' . selected($pt_status, $value, false) . '>' . esc_html($label) . '</option>';
                    }
                    echo '</select></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '<p class="description wpmu-ml-route-help">说明：文章类型规则用于设置某类内容默认走哪个引擎或模型；如果同一目标语言也设置了引擎，目标语言规则优先于文章类型规则。需要更细时，用下方“精确覆盖规则”。</p>';
            } else {
                echo '<p>当前没有检测到文章类型。</p>';
            }

            echo '<div class="notice notice-info inline wpmu-ml-combo-notice"><p><strong>精确覆盖规则：目标语言 + 文章类型</strong>（优先级最高）。用于指定某个语言下某类文章的引擎和模型，例如 <code>ko + knowledge_post = Agent API</code>，或 <code>en + knowledge_post = OpenAI 兼容 + gpt-5.5</code>。</p></div>';
            echo '<details class="wpmu-ml-combo-rules" style="margin-top:10px"><summary><strong>展开/收起精确覆盖规则</strong></summary>';
            echo '<p class="description">组合规则优先级最高。适合设置：ko + knowledge_post 走 Agent；en + knowledge_post 走 OpenAI 兼容 + gpt-5.5。</p>';
            $combo_engines = is_array($settings['translation_engines_by_lang_post_type'] ?? null) ? $settings['translation_engines_by_lang_post_type'] : [];
            $combo_models = is_array($settings['translation_models_by_lang_post_type'] ?? null) ? $settings['translation_models_by_lang_post_type'] : [];
            $combo_statuses = is_array($settings['translation_status_by_lang_post_type'] ?? null) ? $settings['translation_status_by_lang_post_type'] : [];
            $target_langs_for_routes = [];
            foreach ($sites as $site) {
                if (empty($site['enabled']) || (int)$site['blog_id'] === $source_blog_id) {
                    continue;
                }
                $l = sanitize_key($site['lang_slug']);
                if ($l) {
                    $target_langs_for_routes[] = $l;
                }
            }
            $selected_route_lang = sanitize_key((string)($_GET['route_lang'] ?? ''));
            if ($selected_route_lang === '' || !in_array($selected_route_lang, $target_langs_for_routes, true)) {
                $selected_route_lang = $target_langs_for_routes ? (string)reset($target_langs_for_routes) : '';
            }
            if ($target_langs_for_routes && $post_types && $selected_route_lang !== '') {
                echo '<p><label for="wpmu-ml-route-lang-selector"><strong>选择要编辑的目标语言：</strong></label> ';
                echo '<select id="wpmu-ml-route-lang-selector" class="wpmu-ml-select">';
                foreach ($target_langs_for_routes as $route_lang_option) {
                    $route_lang_url = network_admin_url('admin.php?page=wpmu-multilingual-engines&tab=routing&route_lang=' . rawurlencode($route_lang_option));
                    echo '<option value="' . esc_url($route_lang_url) . '" ' . selected($selected_route_lang, $route_lang_option, false) . '>' . esc_html($route_lang_option) . '</option>';
                }
                echo '</select> <span class="description">一次只编辑一种语言，避免 40 个语言 × 多个文章类型导致 PHP <code>max_input_vars</code> 截断保存数据。</span></p>';
                echo '<input type="hidden" name="route_lang" value="' . esc_attr($selected_route_lang) . '">';
                echo '<table class="widefat striped wpmu-ml-route-table"><thead><tr><th>目标语言</th><th>文章类型</th><th>翻译方式</th><th>OpenAI 兼容模型</th><th>完成后状态</th></tr></thead><tbody>';
                $lang = $selected_route_lang;
                $lang_engines = $this->get_translation_engines_for_lang($lang);
                foreach ($post_types as $post_type) {
                    $pt = sanitize_key($post_type);
                    $rk = $this->get_translation_route_key($lang, $pt);
                    $ce = sanitize_key($combo_engines[$rk] ?? '');
                    $cm = trim((string)($combo_models[$rk] ?? ''));
                    $cs = sanitize_key($combo_statuses[$rk] ?? '');
                    echo '<tr>';
                    echo '<td><code>' . esc_html($lang) . '</code></td>';
                    echo '<td><code>' . esc_html($pt) . '</code><br><span class="description">' . esc_html($this->get_post_type_label($pt)) . '</span></td>';
                    echo '<td><select class="wpmu-ml-select" name="translation_engines_by_lang_post_type[' . esc_attr($rk) . ']">';
                    echo '<option value="" ' . selected($ce, '', false) . '>继承上级规则</option>';
                    foreach ($lang_engines as $key => $label) {
                        echo '<option value="' . esc_attr($key) . '" ' . selected($ce, $key, false) . '>' . esc_html($label) . '</option>';
                    }
                    echo '</select></td>';
                    echo '<td><input type="text" class="regular-text" name="translation_models_by_lang_post_type[' . esc_attr($rk) . ']" value="' . esc_attr($cm) . '" placeholder="仅 OpenAI 兼容有效"></td>';
                    echo '<td><select class="wpmu-ml-select" name="translation_status_by_lang_post_type[' . esc_attr($rk) . ']">';
                    echo '<option value="" ' . selected($cs, '', false) . '>继承上级规则</option>';
                    foreach ($status_labels as $value => $label) {
                        echo '<option value="' . esc_attr($value) . '" ' . selected($cs, $value, false) . '>' . esc_html($label) . '</option>';
                    }
                    echo '</select></td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p>当前没有可用的目标语言或文章类型。</p>';
            }
            echo '</details>';

            echo '<h3>当前路由优先级</h3>';
            echo '<ol class="wpmu-ml-route-priority"><li>单篇任务手动指定</li><li>目标语言 + 文章类型规则</li><li>目标语言规则</li><li>文章类型规则</li><li>默认翻译引擎</li><li>系统兜底，例如繁体语言可用 OpenCC</li></ol>';
            echo '</div>';

            echo '<div class="wpmu-ml-engine-panel' . ($active_tab === 'openai' ? ' is-active' : '') . '" data-panel="openai">';
            echo '<h2>OpenAI 兼容</h2>';
            echo '<p class="description">OpenAI 兼容引擎现在使用自己的二级选项卡。接口、语言专用配置、内容处理和质检分别维护；共用 Skill、术语库和排除字段仍放在第一层“翻译规则”中。</p>';

            echo '<div class="wpmu-ml-engine-inner-tabs wpmu-ml-openai-tabs" role="tablist" aria-label="OpenAI 兼容二级选项卡">';
            $openai_inner_tabs = [
                'connection' => '接口与默认模型',
                'languages' => '语言设置',
                'content' => '内容处理',
                'quality' => '质检与编辑',
            ];
            foreach ($openai_inner_tabs as $inner_key => $inner_label) {
                $inner_active = $inner_key === 'connection' ? ' is-active' : '';
                echo '<button type="button" class="wpmu-ml-engine-inner-tab' . esc_attr($inner_active) . '" data-openai-panel="' . esc_attr($inner_key) . '">' . esc_html($inner_label) . '</button>';
            }
            echo '</div>';

            echo '<div class="wpmu-ml-engine-inner-panel is-active" data-openai-panel="connection">';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>API Base URL</th><td><input type="url" name="openai_api_base" value="' . esc_attr($settings['openai_api_base']) . '" class="regular-text" placeholder="https://api.openai.com/v1"><p class="description">兼容 OpenAI Chat Completions 的接口地址，插件会请求 <code>/chat/completions</code>。</p></td></tr>';
            $has_openai_key = trim((string)($settings['openai_api_key'] ?? '')) !== '';
            echo '<tr><th>API Key</th><td><input type="password" name="openai_api_key" value="" class="regular-text" autocomplete="new-password" placeholder="' . ($has_openai_key ? '已保存，留空保持不变' : '尚未设置') . '"><p class="description">当前状态：<strong>' . ($has_openai_key ? '已保存' : '未设置') . '</strong>。留空不会清除旧 Key；只有填写新值才会替换。</p><label><input type="checkbox" name="openai_api_key_clear" value="1"> 明确清除已保存的 API Key</label></td></tr>';
            echo '<tr><th>默认模型名称</th><td><input type="text" name="openai_model" value="' . esc_attr($settings['openai_model']) . '" class="regular-text" placeholder="gpt-5.5 / your-compatible-model"><p class="description">全局兜底模型。模型优先级为：目标语言 + 文章类型精确覆盖 → 语言设置中的模型 → 文章类型模型 → 这里的默认模型。</p></td></tr>';
            echo '<tr><th>默认 Temperature</th><td><input type="number" step="0.1" min="0" max="2" name="openai_temperature" value="' . esc_attr($settings['openai_temperature']) . '" style="width:90px"><p class="description">建议 0.1 - 0.3。各语言可在“语言设置”中单独覆盖。</p></td></tr>';
            echo '<tr><th>请求超时</th><td><input type="number" min="15" name="openai_timeout" value="' . esc_attr(absint($settings['openai_timeout'])) . '" style="width:100px"> 秒 <p class="description">长文或慢速模型可设 300、600、1800 秒；正式批量建议用 WP-CLI/系统 Cron。</p></td></tr>';
            echo '</tbody></table>';
            echo '</div>';

            echo '<div class="wpmu-ml-engine-inner-panel" data-openai-panel="languages">';
            echo '<h3>按目标语言配置 OpenAI</h3>';
            echo '<p class="description">语言列表自动读取“翻译设置 → 语言站点”中已启用的目标站。这里的附加提示词只注入当前语言，不影响其他语言；留空时完全继承全局规则。</p>';
            $openai_language_profiles = is_array($settings['openai_language_settings'] ?? null)
                ? $settings['openai_language_settings']
                : [];
            $openai_sites = $this->get_i18n_sites();
            $openai_source_blog_id = absint($settings['source_blog_id']);
            $openai_profile_count = 0;
            foreach ($openai_sites as $openai_site) {
                if (empty($openai_site['enabled']) || (int)$openai_site['blog_id'] === $openai_source_blog_id) {
                    continue;
                }
                $openai_profile_count++;
                $openai_lang = sanitize_key((string)$openai_site['lang_slug']);
                $openai_profile = isset($openai_language_profiles[$openai_lang]) && is_array($openai_language_profiles[$openai_lang])
                    ? $openai_language_profiles[$openai_lang]
                    : [];
                $openai_language_name = trim((string)($openai_site['translation_language_name'] ?? ''));
                if ($openai_language_name === '') {
                    $openai_language_name = trim((string)($openai_site['language_name'] ?? ''));
                }
                if ($openai_language_name === '') {
                    $openai_language_name = $openai_lang;
                }
                $openai_locale = trim((string)($openai_site['translation_locale'] ?? ''));
                if ($openai_locale === '') {
                    $openai_locale = trim((string)($openai_site['locale'] ?? ''));
                }
                $profile_model = trim((string)($openai_profile['model'] ?? ''));
                $profile_temperature = trim((string)($openai_profile['temperature'] ?? ''));
                $profile_prompt = trim((string)($openai_profile['prompt'] ?? ''));

                echo '<details class="wpmu-ml-language-profile">';
                echo '<summary>' . esc_html($openai_language_name) . ' <code>' . esc_html($openai_lang) . '</code><span class="wpmu-ml-language-meta">' . esc_html($openai_locale) . ' · Blog ID ' . esc_html($openai_site['blog_id']) . '</span></summary>';
                echo '<div class="wpmu-ml-language-profile__body">';
                echo '<table class="form-table"><tbody>';
                echo '<tr><th>目标站</th><td><a href="' . esc_url($openai_site['site_url']) . '" target="_blank">' . esc_html($openai_site['site_url']) . '</a><p class="description">语言标识和 Locale 由语言站点自动读取，这里不重复维护。</p></td></tr>';
                echo '<tr><th>模型覆盖</th><td><input type="text" class="regular-text" name="openai_language_settings[' . esc_attr($openai_lang) . '][model]" value="' . esc_attr($profile_model) . '" placeholder="留空继承，例如 gpt-5.5"><p class="description">只影响该目标语言。精确的“语言 + 文章类型”模型仍然优先。</p></td></tr>';
                echo '<tr><th>Temperature 覆盖</th><td><input type="number" step="0.1" min="0" max="2" name="openai_language_settings[' . esc_attr($openai_lang) . '][temperature]" value="' . esc_attr($profile_temperature) . '" placeholder="继承" style="width:100px"><p class="description">留空继承 OpenAI 默认 Temperature。</p></td></tr>';
                echo '<tr><th>语言专用提示词</th><td><textarea name="openai_language_settings[' . esc_attr($openai_lang) . '][prompt]" rows="4" class="large-text" placeholder="建议只写 2～3 句：目标语言自然度、文风和该语言特有的本地化要求。">' . esc_textarea($profile_prompt) . '</textarea><p class="description">保持简短，只负责语言风格。HTML、JSON、URL、数字、占位符、Gutenberg 与写回完整性由代码层统一保护，请勿在这里重复。</p></td></tr>';
                echo '</tbody></table>';
                echo '</div>';
                echo '</details>';
            }
            if ($openai_profile_count === 0) {
                echo '<div class="notice notice-warning inline"><p>没有可配置的目标语言。请先到“翻译设置 → 语言站点”启用目标站并设置源站。</p></div>';
            }
            echo '</div>';

            echo '<div class="wpmu-ml-engine-inner-panel" data-openai-panel="content">';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>翻译文章自定义字段</th><td><label><input type="checkbox" name="openai_translate_meta" value="1" ' . checked(!empty($settings['openai_translate_meta']), true, false) . '> 翻译可识别的 ACF / postmeta 文本字段</label><p class="description">会跳过 slug、URL、ID、图片、附件、ACF 字段引用等结构字段。</p></td></tr>';
            echo '<tr><th>翻译 SEO 字段</th><td><label><input type="checkbox" name="openai_translate_seo_meta" value="1" ' . checked(!empty($settings['openai_translate_seo_meta']), true, false) . '> 翻译 Rank Math / Yoast / AIOSEO 的标题和描述字段</label></td></tr>';
            echo '<tr><th>代码和行内 code</th><td><p>默认采用代码片段级智能翻译：插件先程序化提取注释、字符串值、HTML 示例注释和标签文本等人类可读片段，AI 只翻译这些片段，再原位替换回代码。</p></td></tr>';
            echo '</tbody></table>';
            echo '</div>';

            echo '<div class="wpmu-ml-engine-inner-panel" data-openai-panel="quality">';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>AI 质量检查</th><td><label><input type="checkbox" name="openai_agent_quality_check" value="1" ' . checked(!empty($settings['openai_agent_quality_check']), true, false) . '> 开启 AI 质量检查</label><p class="description">开启后，程序会把疑似源文残留、数字差异、长度差异及其他高风险信号作为提示交给 AI 复核；AI 返回 <code>keep</code> 时，PHP 不得改判或覆盖。关闭后不发起第二次 AI 质检。</p><p class="description"><strong>PHP 本地完整性检查始终开启：</strong>只检查字段是否完整返回、非空、WordPress/HTML/JSON 结构、占位符，以及写回数据库后的完整性，不提供关闭选项。</p></td></tr>';
            echo '</tbody></table>';
            echo '</div>';

            echo '</div>';

            echo '<div class="wpmu-ml-engine-panel' . ($active_tab === 'agent' ? ' is-active' : '') . '" data-panel="agent">';
            echo '<h2>Agent API</h2>';
            echo '<p class="description">这里不是外部 Agent 的模型配置页，只管理 WordPress 提供的任务接口和访问 Key。共享翻译规则、术语库和排除字段在“翻译规则”子选项卡统一维护，并自动随 payload 提供。</p>';
            echo '<table class="form-table"><tbody>';
            $agent_token = trim((string)($settings['agent_api_token'] ?? ''));
            if ($agent_token === '') {
                echo '<tr><th>Agent API Key</th><td><p><strong>当前未生成，Agent API 未启用。</strong></p><p><button type="submit" class="button button-primary" name="agent_api_action" value="generate">生成 Agent API Key</button></p><p class="description">这个 Key 由 WordPress 插件生成。本地/外部 Agent 调用接口时使用 <code>Authorization: Bearer API_KEY</code>。</p></td></tr>';
            } else {
                echo '<tr><th>Agent API Key</th><td><input type="text" readonly value="' . esc_attr($agent_token) . '" class="large-text code" onclick="this.select();"><p class="description">把这串 Key 配到本地 Agent，请求 WordPress 工具接口时使用 <code>Authorization: Bearer API_KEY</code>。它不是模型 API Key。</p><p><button type="submit" class="button" name="agent_api_action" value="reset" onclick="return confirm(\'确定要重置 Agent API Key？旧 Key 会立即失效。\');">重置 Key</button> <button type="submit" class="button" name="agent_api_action" value="disable" onclick="return confirm(\'确定要禁用 Agent API？当前 Key 会被清空。\');">禁用 Agent API</button></p></td></tr>';
            }
            echo '<tr><th>Agent 队列 REST API</th><td><p><code>/wp-json/wpmu-ml/v1/agent/health</code>、<code>/rules</code>、<code>/next</code>、<code>/claim</code>、<code>/payload</code>、<code>/result</code>、<code>/fail</code>、<code>/heartbeat</code>、<code>/release</code></p><p class="description">用于文章翻译队列。<code>/rules</code> 可单独读取共用 Skill、按目标语言筛选后的术语和排除字段；每个 <code>/payload</code> 也会内嵌同一份 <code>translation_rules</code>。外部 Agent 翻译 fields 并回传 result，插件负责队列、字段抽取、结构校验和写回目标文章。</p></td></tr>';
            echo '<tr><th>队列接口 curl 示例</th><td><pre class="wpmu-ml-pre">curl -s -H "Authorization: Bearer API_KEY" "' . esc_html(home_url('/wp-json/wpmu-ml/v1/agent/health')) . '"

curl -s -H "Authorization: Bearer API_KEY" "' . esc_html(home_url('/wp-json/wpmu-ml/v1/agent/rules?target_lang=es')) . '"</pre><p class="description">Agent 侧可先调用 rules 查看共享规则；完整任务流程：health → rules（可选）→ next → claim → payload → result。失败时 fail，长任务可 heartbeat，放弃任务可 release。</p></td></tr>';
            echo '<tr><th colspan="2"><h3 style="margin:8px 0 0">Agent 工具接口</h3></th></tr>';
            echo '<tr><td colspan="2"><p class="description">工具接口和文章翻译队列分开，用于外部 Agent 翻译你手动指定的分类、标签、样板、模板等非队列内容。插件提供读取/写回工具；模型仍由外部 Agent 管理，共享翻译规则与术语可通过主 Agent API 的 <code>/agent/rules</code> 读取。</p></td></tr>';
            $agent_tools_token = trim((string)($settings['agent_tools_api_token'] ?? ''));
            if ($agent_tools_token === '') {
                echo '<tr><th>Agent Tools API Key</th><td><p><strong>当前未生成，Agent 工具接口未启用。</strong></p><p><button type="submit" class="button button-primary" name="agent_tools_api_action" value="generate">生成 Agent Tools API Key</button></p><p class="description">建议使用单独 Key，不与文章队列 Agent API Key 混用。</p></td></tr>';
            } else {
                echo '<tr><th>Agent Tools API Key</th><td><input type="text" readonly value="' . esc_attr($agent_tools_token) . '" class="large-text code" onclick="this.select();"><p class="description">把这串 Key 配到本地 Agent 的工具接口配置中。它只能访问 <code>/agent-tools/*</code> 这一组接口。</p><p><button type="submit" class="button" name="agent_tools_api_action" value="reset" onclick="return confirm(\'确定要重置 Agent Tools API Key？旧 Key 会立即失效。\');">重置 Tools Key</button> <button type="submit" class="button" name="agent_tools_api_action" value="disable" onclick="return confirm(\'确定要禁用 Agent 工具接口？当前 Key 会被清空。\');">禁用工具接口</button></p></td></tr>';
            }
            echo '<tr><th>Agent Tools REST API</th><td><p><code>/wp-json/wpmu-ml/v1/agent-tools/health</code>、<code>/types</code>、<code>/list</code>、<code>/read</code>、<code>/write</code></p><p class="description"><code>/types</code> 用于查看可操作类型；<code>/list</code> 用于列出分类、标签、样板、模板等对象；<code>/read</code> 读取指定对象字段；<code>/write</code> 写回 Agent 翻译结果。</p></td></tr>';
            echo '<tr><th>工具接口 curl 示例</th><td><pre class="wpmu-ml-pre">curl -s -H "Authorization: Bearer TOOLS_KEY" "' . esc_html(home_url('/wp-json/wpmu-ml/v1/agent-tools/health')) . '"

curl -s -H "Authorization: Bearer TOOLS_KEY" "' . esc_html(home_url('/wp-json/wpmu-ml/v1/agent-tools/types')) . '"</pre><p class="description">列出对象用 POST <code>/agent-tools/list</code>，读取对象用 POST <code>/agent-tools/read</code>，写回译文用 POST <code>/agent-tools/write</code>。这个接口不创建翻译队列任务。</p></td></tr>';
            echo '</tbody></table>';
            echo '</div>';

            echo '<div class="wpmu-ml-engine-panel' . ($active_tab === 'opencc' ? ' is-active' : '') . '" data-panel="opencc">';
            echo '<h2>OpenCC</h2>';
            echo '<p class="description">OpenCC 只负责简体中文到繁体中文的文字转换，不调用模型。只有源站识别为简体中文、目标语言为繁体中文时，才会在“默认与路由”的目标语言规则里显示 s2twp / s2tw / s2hk / s2t。</p>';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>OpenCC 命令路径</th><td><input type="text" name="opencc_binary_path" value="' . esc_attr($settings['opencc_binary_path']) . '" class="regular-text" placeholder="自动检测：/usr/bin/opencc 或 opencc"><p class="description">留空自动检测。转换配置不再全局设置，而是在繁体目标语言的翻译方式里选择 s2twp / s2tw / s2hk / s2t。</p></td></tr>';
            echo '<tr><th>转换文章自定义字段</th><td><label><input type="checkbox" name="opencc_convert_meta" value="1" ' . checked(!empty($settings['opencc_convert_meta']), true, false) . '> 转换目标文章的可翻译 postmeta / ACF 字段值</label></td></tr>';
            echo '<tr><th>转换 SEO 字段</th><td><label><input type="checkbox" name="opencc_convert_seo_meta" value="1" ' . checked(!empty($settings['opencc_convert_seo_meta']), true, false) . '> 转换 Rank Math / Yoast / AIOSEO 的标题和描述字段</label></td></tr>';
            echo '</tbody></table>';
            echo '<p class="description">推荐：简体中文源站转换到 <code>zh-hant</code> 时选 <code>opencc_s2twp</code>；香港用词需求可选 <code>opencc_s2hk</code>。如果源站不是简体中文，不应使用 OpenCC。</p>';
            echo '</div>';

            echo '<div class="wpmu-ml-engine-panel' . ($active_tab === 'manual' ? ' is-active' : '') . '" data-panel="manual">';
            echo '<h2>人工翻译</h2>';
            echo '<p>人工翻译是一个队列状态和人工操作流程，不调用任何模型或外部 Agent。</p>';
            echo '<table class="widefat striped"><thead><tr><th>场景</th><th>说明</th></tr></thead><tbody>';
            echo '<tr><td><code>engine = manual</code></td><td>目标文章由人工编辑或复制粘贴完成，点击“人工完成”后进入 <code>manual_done</code>。</td></tr>';
            echo '<tr><td>目标文章发布钩子</td><td>v0.8.5 起按引擎区分完成状态，只有 manual 才会被标记为 <code>manual_done</code>，Agent/OpenAI 兼容/OpenCC 不再被覆盖。</td></tr>';
            echo '</tbody></table>';
            echo '</div>';

            echo '<div class="wpmu-ml-engine-panel' . ($active_tab === 'rules' ? ' is-active' : '') . '" data-panel="rules">';
            echo '<h2>翻译规则</h2>';
            echo '<p class="description">这里是 OpenAI 兼容与 Agent API 共用的规则中心。保存一次后，内部 OpenAI 兼容引擎会直接注入提示词；外部 Agent 可通过 <code>/wp-json/wpmu-ml/v1/agent/rules</code> 查看，并会在每个任务 payload 的 <code>translation_rules</code> 字段中收到同一份规则。</p>';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>母语化翻译原则</th><td><p>系统提示词已内置母语化质量要求：译文应自然、可信、符合目标语言用户的真实表达习惯，像当地母语技术作者原创，而不是保留源语言语序、逐字对应或机器翻译腔。</p><p>允许根据有效 AI 翻译标签调整拼写、词汇、语法、标点、UI 短语和 SEO 表达，但必须保持原意、事实、品牌、核心关键词、数字、价格、URL、代码和 WordPress 结构不变。</p><p class="description">语言变体只在“语言站点 → AI 翻译标签”设置。例如分站 Locale 为 <code>es_MX</code>，填写 <code>es-419</code> 后就按中性拉丁美洲西班牙语翻译；留空则按 <code>es_MX</code>。该原则同时写入 OpenAI 兼容系统提示词和 Agent API 共用规则，无需逐语言填写说明。</p></td></tr>';
            echo '<tr><th>AI 翻译规则 / Skill</th><td><textarea name="openai_agent_site_rules" rows="9" class="large-text" placeholder="支持普通文本或 Markdown，例如：
- LikaCloud 不翻译
- VPS、CPU、RAM、SSD、CDN 保留英文
- 保持 HTML、短代码、代码、URL 不变
- 只翻译人类可读文本">' . esc_textarea($settings['openai_agent_site_rules'] ?? '') . '</textarea><p class="description">全站、全语言通用，适合补充品牌保护、技术术语、格式和内容策略。OpenAI 兼容直接使用；Agent API 通过 <code>/agent/rules</code> 和 payload 读取。内置的忠实翻译、母语化、结构保护和安全规则始终优先。</p></td></tr>';
            echo '<tr><th>术语库</th><td><textarea name="openai_agent_terms" rows="8" class="large-text code" placeholder="每行：原词 | 语言 | 译法
例如：
云服务器 | en | cloud server
虚拟主机 | es-419 | alojamiento web
LikaCloud | all | LikaCloud">' . esc_textarea($settings['openai_agent_terms'] ?? '') . '</textarea><p class="description">语言列支持语言标识、AI 翻译标签、WordPress Locale、hreflang 和 <code>all/*/any</code>。OpenAI 兼容会按当前目标语言筛选；Agent API 的 <code>/rules</code> 和 payload 同时返回原始术语库与当前目标语言的有效术语。</p></td></tr>';
            echo '<tr><th>排除自定义字段</th><td><textarea name="openai_excluded_meta_keys" rows="8" class="large-text code" placeholder="每行一个 meta_key，支持 * 通配符，例如：&#10;_ai_generated_seo&#10;_ai_generated_*&#10;views">' . esc_textarea($settings['openai_excluded_meta_keys'] ?? '') . '</textarea><p class="description">这些 postmeta 在生成 OpenAI 翻译字段和 Agent payload 前都会被排除。Agent API 也可查看自定义值和包含内置默认项的有效规则列表。</p></td></tr>';
            echo '<tr><th>排除翻译标签 / 选择器</th><td><textarea name="openai_excluded_html_tags" rows="7" class="large-text code" placeholder="每行一个，也兼容逗号分隔：&#10;pre&#10;.jd-cloud-ad&#10;#fixed-banner&#10;div.key-points&#10;[data-no-translation]&#10;class=&quot;legacy-box&quot;">' . esc_textarea($settings['openai_excluded_html_tags'] ?? 'pre') . '</textarea><p class="description">匹配到的整个 HTML 元素及其内部内容会原样保护：不发送给 AI，也不参与可见文本翻译；其结构在写回时仍受完整性保护。支持标签 <code>pre</code>、class <code>.promo-card</code>、ID <code>#fixed-banner</code>、组合 <code>div.key-points</code>、属性 <code>[data-role=&quot;ad&quot;]</code>，也兼容直接粘贴 <code>class=&quot;promo-card&quot;</code> 或 <code>id=&quot;fixed-banner&quot;</code>。为避免误排除，不支持空格后代选择器、<code>&gt;</code>、伪类和通配符；不要填写通用的 <code>div</code>、<code>p</code>、<code>span</code>。</p></td></tr>';
            echo '</tbody></table>';
            echo '</div>';

            echo '<div class="wpmu-ml-engine-panel' . ($active_tab === 'advanced' ? ' is-active' : '') . '" data-panel="advanced">';
            echo '<h2>高级说明</h2>';
            echo '<p>队列运行器、批处理数量、锁超时和失败重试仍在“翻译队列”页配置。本页只管理引擎和路由。</p>';
            echo '<table class="widefat striped"><thead><tr><th>概念</th><th>说明</th></tr></thead><tbody>';
            echo '<tr><td>engine</td><td>决定任务由 OpenAI 兼容、Agent API、OpenCC 还是人工处理。</td></tr>';
            echo '<tr><td>model</td><td>仅对 OpenAI 兼容引擎有意义。后续 TranslationRouteResolver 接入后，任务可拥有自己的模型。</td></tr>';
            echo '<tr><td>Agent API</td><td>模型和执行流程由外部 Agent 管理；内置母语化原则、目标语言标签、共用 Skill、术语库与排除字段由插件统一维护，可通过 <code>/agent/rules</code> 或任务 payload 读取。</td></tr>';
            echo '</tbody></table>';
            echo '</div>';

            echo '<p class="submit wpmu-ml-subtab-save"><button type="submit" class="button button-primary">保存翻译引擎与规则设置</button></p>';
            echo '</form>';
            echo '<script>(function(){var selector=document.getElementById("wpmu-ml-route-lang-selector");if(selector){selector.addEventListener("change",function(){if(this.value){window.location.href=this.value;}});}var form=document.getElementById("wpmu-ml-engine-settings-form");if(form){form.addEventListener("submit",function(){form.querySelectorAll(".wpmu-ml-engine-panel:not(.is-active) input,.wpmu-ml-engine-panel:not(.is-active) select,.wpmu-ml-engine-panel:not(.is-active) textarea,.wpmu-ml-engine-panel:not(.is-active) button").forEach(function(el){el.disabled=true;});});}var root=document.querySelector(".wpmu-ml-openai-tabs");if(!root)return;var wrap=root.closest("[data-panel=openai]");if(!wrap)return;var tabs=wrap.querySelectorAll(".wpmu-ml-engine-inner-tab[data-openai-panel]"),panels=wrap.querySelectorAll(".wpmu-ml-engine-inner-panel[data-openai-panel]");var key="wpmu_ml_openai_inner_tab";function show(name){tabs.forEach(function(t){t.classList.toggle("is-active",t.getAttribute("data-openai-panel")===name);});panels.forEach(function(p){p.classList.toggle("is-active",p.getAttribute("data-openai-panel")===name);});try{localStorage.setItem(key,name);}catch(e){}}tabs.forEach(function(t){t.addEventListener("click",function(){show(t.getAttribute("data-openai-panel"));});});var initial="connection";try{initial=localStorage.getItem(key)||initial;}catch(e){}var ok=false;tabs.forEach(function(t){if(t.getAttribute("data-openai-panel")===initial){ok=true;}});show(ok?initial:"connection");})();</script>';
        }

        private function render_job_action_button($job_id, $action, $label)
        {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:inline-block;margin:0;margin-inline-end:4px;margin-block-end:4px">';
            echo '<input type="hidden" name="action" value="wpmu_ml_translation_job_action">';
            echo '<input type="hidden" name="job_action" value="' . esc_attr($action) . '">';
            echo '<input type="hidden" name="job_id" value="' . esc_attr($job_id) . '">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
            echo '<button type="submit" class="button button-small">' . esc_html($label) . '</button>';
            echo '</form>';
        }

        private function render_tools_page()
        {
            $settings = $this->get_settings();
            $managed_post_types = array_values(array_unique(array_merge((array)$settings['translatable_post_types'], (array)$settings['shared_post_types'])));
            $sites = $this->get_i18n_sites(true);

            echo '<h2>工具</h2>';
            echo '<p>这些工具是初始化和批量整理阶段使用的。正式翻译上线后，谨慎使用覆盖式同步。</p>';

            echo '<div class="wpmu-ml-card" style="max-width:1100px;margin-bottom:18px">';
            echo '<h3>按语言批量同步状态</h3>';
            echo '<p class="description">用于批量把某些语言站的目标文章改成草稿、待审或发布，并同步更新关联表状态。不会改正文，也不会生成翻译任务。</p>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'确定要批量调整所选语言的文章状态和关联状态吗？该操作会直接修改数据库。\');">';
            echo '<input type="hidden" name="action" value="wpmu_ml_sync_language_status">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

            echo '<table class="form-table" role="presentation"><tbody>';
            echo '<tr><th>目标语言</th><td><div class="wpmu-ml-checkgrid">';
            foreach ($sites as $site) {
                if (!empty($site['is_source'])) {
                    continue;
                }
                echo '<label><input type="checkbox" name="target_langs[]" value="' . esc_attr($site['lang_slug']) . '"> <code>' . esc_html($site['lang_slug']) . '</code> <span class="wpmu-ml-muted">Blog ID ' . esc_html($site['blog_id']) . '</span></label>';
            }
            echo '</div><p class="description">例如：.com/en 选 en 后可批量改草稿；繁体站选 zh-hant 后可批量改发布。</p></td></tr>';

            $post_statuses = [
                'no_change' => '不改文章状态',
                'draft' => '草稿 draft',
                'pending' => '待审核 pending',
                'publish' => '发布 publish',
                'private' => '私密 private',
            ];
            echo '<tr><th>目标文章状态</th><td><select name="target_post_status" style="min-width:220px">';
            foreach ($post_statuses as $value => $label) {
                echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
            }
            echo '</select></td></tr>';

            $relation_statuses = [
                'no_change' => '不改关联状态',
                'needs_translation' => '未翻译 needs_translation',
                'needs_update' => '需更新 needs_update',
                'translated_update_pending' => '更新已翻译的内容 translated_update_pending',
                'machine_translated' => '机器已翻译 machine_translated',
                'translated' => '已翻译 translated',
                'shared_published' => '共享发布 shared_published',
            ];
            echo '<tr><th>关联状态</th><td><select name="relation_status" style="min-width:260px">';
            foreach ($relation_statuses as $value => $label) {
                echo '<option value="' . esc_attr($value) . '">' . esc_html($label) . '</option>';
            }
            echo '</select><p class="description">如果只是先发布 zh-hant、后续再 OpenCC，可选“需更新 needs_update”；如果是英文根站先隐藏，可选“未翻译 needs_translation”。</p></td></tr>';

            echo '<tr><th>文章类型</th><td>';
            if ($managed_post_types) {
                echo '<div class="wpmu-ml-checkgrid">';
                foreach ($managed_post_types as $type) {
                    echo '<label><input type="checkbox" name="post_types[]" value="' . esc_attr($type) . '" checked> <code>' . esc_html($type) . '</code> ' . esc_html($this->get_post_type_label($type)) . '</label>';
                }
                echo '</div>';
                echo '<p class="description">默认只处理这里勾选的“参与翻译/共享发布”文章类型。未勾选则不处理。</p>';
            } else {
                echo '<p>还没有配置参与翻译或共享发布的文章类型。</p>';
            }
            echo '</td></tr>';
            echo '</tbody></table>';
            submit_button('执行语言状态同步', 'primary');
            echo '</form>';
            echo '</div>';

            echo '<div class="wpmu-ml-card" style="max-width:1100px">';
            echo '<h3>初始化检查工具</h3>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'该工具只会根据相同 ID 补建草稿，不会复制全部历史字段。确定执行？\');">';
            echo '<input type="hidden" name="action" value="wpmu_ml_sync_same_id_drafts">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
            submit_button('根据源站 ID 检查目标站缺失文章', 'secondary');
            echo '</form>';
            echo '</div>';
        }

        private function render_misc_settings_page()
        {
            $settings = $this->get_settings();
            echo '<h2>其他设置</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="wpmu_ml_save_misc_settings">';
            wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
            echo '<table class="form-table" role="presentation"><tbody>';
            echo '<tr><th>后台“我的站点”当前页跳转</th><td>';
            echo '<label><input type="checkbox" name="admin_bar_current_page_site_links" value="1" ' . checked(!empty($settings['admin_bar_current_page_site_links']), true, false) . '> 开启后，将后台顶部“我的站点”下各分站名称链接改为当前页面在对应分站的链接，并高亮当前站点。</label>';
            echo '<p class="description">编辑文章、页面或自定义文章类型时，会优先根据多语言关联表跳到目标分站对应文章编辑页；前台文章页会跳到对应前台链接。其他后台页面会按当前后台相对路径跳到目标分站后台。</p>';
            echo '</td></tr>';
            echo '<tr><th>“我的站点”卡片语言信息</th><td>';
            echo '<label><input type="checkbox" name="show_my_sites_language_card_meta" value="1" ' . checked(!empty($settings['show_my_sites_language_card_meta']), true, false) . '> 在后台“我的站点”页面的每个站点卡片中显示语言名称和语言别名。</label>';
            echo '<p class="description">语言名称自动读取分站 WordPress Locale，例如 <code>中文（台灣）</code>；语言别名读取多语言站点配置，例如 <code>zh-hant</code>。</p>';
            echo '</td></tr>';
            echo '<tr><th>顶部“我的站点”菜单语言名称</th><td>';
            echo '<label><input type="checkbox" name="admin_bar_language_site_labels" value="1" ' . checked(!empty($settings['admin_bar_language_site_labels']), true, false) . '> 将后台顶部“我的站点”下拉里的分站名称显示为“语言名称 / 语言别名”。</label>';
            echo '<p class="description">例如 <code>русский (Россия) / ru</code>、<code>中文（台灣） / zh-hant</code>，方便直接识别语言。</p>';
            echo '</td></tr>';
            echo '</tbody></table>';
            submit_button('保存其他设置');
            echo '</form>';
        }

        private function render_help_page()
        {
            $checks = $this->get_environment_checks();
            echo '<h2>帮助 / 环境自检</h2>';
            echo '<p>这里会自动检查插件运行所需的 WordPress、PHP、数据库、HTTP、Cron、临时目录，以及 OpenCC 环境。标记为“必需”的项目会直接影响对应翻译流程；“按需”项目只在启用相关引擎时必须。此页面只检测环境，不会修改数据，也不会调用翻译 API。</p>';

            echo '<h3>环境检查结果</h3>';
            echo '<table class="widefat striped wpmu-ml-help-table"><thead><tr><th style="width:220px">检查项</th><th style="width:120px">状态</th><th>当前值 / 说明</th><th>建议处理</th></tr></thead><tbody>';
            foreach ($checks as $check) {
                $status = sanitize_key($check['status'] ?? 'info');
                $class = $status === 'ok' ? 'wpmu-ml-ok' : ($status === 'bad' ? 'wpmu-ml-bad' : 'wpmu-ml-warn');
                $label = $status === 'ok' ? '通过' : ($status === 'bad' ? '异常' : '注意');
                echo '<tr>';
                echo '<td style="word-break: break-all; white-space: pre-wrap;vertical-align: middle;"><strong>' . esc_html($check['label'] ?? '') . '</strong></td>';
                echo '<td class="' . esc_attr($class) . '" style="word-break: break-all; white-space: pre-wrap;vertical-align: middle;">' . esc_html($label) . '</td>';
                echo '<td style="word-break: break-all; white-space: pre-wrap;vertical-align: middle;width:25%;">' . wp_kses_post($check['value'] ?? '') . '</td>';
                echo '<td style="word-break: break-all; white-space: pre-wrap;vertical-align: middle;">' . wp_kses_post($check['help'] ?? '') . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';

            echo '<h3>推荐服务器环境</h3>';
            echo '<div class="wpmu-ml-card" style="max-width:1100px">';
            echo '<ul style="list-style:disc;padding-inline-start:22px">';
            echo '<li>WordPress 必须启用 Multisite，并建议网络启用本插件。</li>';
            echo '<li>PHP 最低 7.4，建议 8.1/8.2；内存建议 256M 以上，长文建议 512M。</li>';
            echo '<li>OpenAI 兼容翻译依赖 JSON、WordPress HTTP API 和 HTTPS/SSL 传输能力，服务器需要能主动访问 API Base URL。</li>';
            echo '<li>只有实际使用 OpenCC 路由时，才需要服务器安装 <code>opencc</code>、允许 <code>shell_exec</code>，并保证临时目录可写。</li>';
            echo '<li>宝塔或安全面板如果禁用了 <code>proc_open</code> / <code>proc_close</code>，通常会影响 <code>wp db query</code> 这类命令；本插件自己的 <code>wp wpmu-ml doctor/job/translate</code> 仍建议优先使用。</li>';
            echo '<li>正式批量翻译不建议长期用后台网页按钮，建议使用 WP-CLI 或系统 Cron。</li>';
            echo '</ul>';
            echo '</div>';

            echo '<h3>OpenCC 安装和检测</h3>';
            echo '<p>Debian / Ubuntu 常用安装命令：</p>';
            echo '<pre class="wpmu-ml-pre">apt update
apt install -y opencc
command -v opencc
opencc --version</pre>';
            echo '<p>插件后台“翻译引擎”里 OpenCC 命令路径可以留空自动检测，常见路径是 <code>/usr/bin/opencc</code>。繁体站一般使用 <code>s2twp.json</code> 或 <code>s2t.json</code>。</p>';

            echo '<h3>常用诊断命令</h3>';
            echo '<pre class="wpmu-ml-pre">cd wpmu-multilingual
wp wpmu-ml doctor --allow-root --skip-themes
wp wpmu-ml doctor --job_id=62 --allow-root --skip-themes
wp wpmu-ml job --job_id=62 --allow-root --skip-themes
wp wpmu-ml translate --job_id=62 --allow-root --skip-themes</pre>';

            echo '<h3>更新维护要求</h3>';
            echo '<div class="notice notice-info inline"><p>从 v0.7.2 起，每次更新插件源码时，必须同步更新 <code>docs/README.md</code> 的版本号、版本记录、功能说明和使用注意事项。README 是换会话、换环境继续维护时的主说明文件。</p></div>';
        }

        private function get_environment_checks()
        {
            global $wpdb, $wp_version;
            $settings = $this->get_settings();
            $checks = [];
            $add = function ($label, $status, $value, $help = '') use (&$checks) {
                $checks[] = [
                    'label' => $label,
                    'status' => $status,
                    'value' => $value,
                    'help' => $help,
                ];
            };

            $add('【必需】插件版本', 'ok', esc_html(self::VERSION), '每次发版必须同步更新 docs/README.md、docs/CHANGELOG.md 和版本常量。');
            $add('【必需】WordPress Multisite', is_multisite() ? 'ok' : 'bad', is_multisite() ? '已启用多站点' : '未启用多站点', '本插件只支持 WordPress Multisite。');
            if (!function_exists('is_plugin_active_for_network') && defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $network_active = function_exists('is_plugin_active_for_network') && defined('WPMU_ML_PLUGIN_FILE')
                ? is_plugin_active_for_network(plugin_basename(WPMU_ML_PLUGIN_FILE))
                : false;
            $add('【必需】网络启用', $network_active ? 'ok' : 'bad', $network_active ? '已网络启用' : '未确认网络启用', '请在“网络管理 → 插件”中网络启用本插件。');
            $add('【必需】WordPress 版本', version_compare((string)$wp_version, '6.0', '>=') ? 'ok' : 'warn', esc_html((string)$wp_version), '最低建议 WordPress 6.0，并及时安装安全更新。');
            $add('【必需】PHP 版本', version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'bad', esc_html(PHP_VERSION), '最低 PHP 7.4；建议 PHP 8.1/8.2。');
            $add('【信息】PHP SAPI', php_sapi_name() === 'cli' ? 'warn' : 'ok', esc_html(php_sapi_name()), '后台页面通常是 fpm-fcgi；WP-CLI 下会显示 cli。');
            $memory_limit = ini_get('memory_limit');
            $memory_bytes = $this->parse_size_to_bytes($memory_limit);
            $add('【必需】memory_limit', ($memory_bytes === 0 || $memory_bytes >= 256 * 1024 * 1024) ? 'ok' : 'warn', esc_html((string)$memory_limit), '建议至少 256M；长文、复杂 ACF 或大批量建议 512M。');
            $max_execution = ini_get('max_execution_time');
            $add('【建议】max_execution_time', ((int)$max_execution === 0 || (int)$max_execution >= 120) ? 'ok' : 'warn', esc_html((string)$max_execution) . ' 秒', '后台请求建议 120 秒以上；批量翻译优先用 WP-CLI/Cron，API 请求还有插件自己的 timeout。');
            $add('【必需】JSON 扩展', extension_loaded('json') ? 'ok' : 'bad', extension_loaded('json') ? '已启用' : '未启用', '设置、API 请求和返回字段解析均依赖 JSON。');
            $add('【建议】mbstring 扩展', extension_loaded('mbstring') ? 'ok' : 'warn', extension_loaded('mbstring') ? '已启用' : '未启用', '未启用时插件会使用兼容回退，但多语言字符长度和截断建议启用 mbstring。');
            $add('【建议】cURL 扩展', extension_loaded('curl') ? 'ok' : 'warn', extension_loaded('curl') ? '已启用' : '未启用', 'OpenAI 兼容走 WordPress HTTP API；无 cURL 时可能退回其他传输，但生产环境建议启用。');
            $ssl_http = function_exists('wp_http_supports') ? wp_http_supports(['ssl' => true]) : extension_loaded('openssl');
            $add('【OpenAI 必需】HTTP / SSL 传输', $ssl_http ? 'ok' : 'bad', $ssl_http ? 'WordPress 检测到 HTTPS 传输能力' : '未检测到 HTTPS 传输能力', '使用 HTTPS API Base 时必须可用。通常需要 cURL+CA 证书或 OpenSSL streams。');
            $http_blocked = defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL;
            $accessible_hosts = defined('WP_ACCESSIBLE_HOSTS') ? trim((string)WP_ACCESSIBLE_HOSTS) : '';
            $add('【OpenAI 按需】外部 HTTP 策略', $http_blocked ? 'warn' : 'ok', $http_blocked ? 'WP_HTTP_BLOCK_EXTERNAL=true；白名单：<code>' . esc_html($accessible_hosts !== '' ? $accessible_hosts : '未设置') . '</code>' : '未全局阻止外部 HTTP', $http_blocked ? '请确认 API Base 的主机已加入 WP_ACCESSIBLE_HOSTS，否则 WordPress 会阻止请求。' : '服务器防火墙、DNS 和出口策略仍需在真实 API 测试中确认。');
            $disabled_functions = trim((string)ini_get('disable_functions'));
            $add('【信息】disable_functions', $disabled_functions === '' ? 'ok' : 'warn', $disabled_functions === '' ? '未配置禁用函数' : '<code>' . esc_html($disabled_functions) . '</code>', 'OpenAI 通常不要求放开 shell_exec/proc_open；OpenCC 只要求 shell_exec。');
            $unicode_pcre = @preg_match('/\p{L}+/u', '测试Test') === 1;
            $add('【必需】PCRE Unicode', $unicode_pcre ? 'ok' : 'bad', $unicode_pcre ? '支持 UTF-8 Unicode 正则' : 'Unicode 正则不可用', '字段、结构和多语言文本检查依赖 PCRE Unicode。');
            $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
            $add('【队列建议】WP-Cron', $cron_disabled ? 'warn' : 'ok', $cron_disabled ? 'DISABLE_WP_CRON=true' : 'WordPress Cron 未被禁用', $cron_disabled ? '如已配置系统 Cron/WP-CLI 定时处理可忽略；否则自动队列不会按计划运行。' : '生产环境也可改用系统 Cron 调用 WP-CLI。');
            $opencc_required = $this->environment_opencc_is_required($settings);
            $shell_available = $this->environment_function_available('shell_exec');
            $add('【OpenCC 按需】shell_exec', $shell_available ? 'ok' : ($opencc_required ? 'bad' : 'warn'), $shell_available ? '可用' : '被 disable_functions 禁用或不可用', $opencc_required ? '当前路由使用 OpenCC，必须允许 shell_exec。' : '当前未检测到 OpenCC 路由；仅使用 OpenAI/Agent 时可保持禁用。');
            $proc_available = $this->environment_function_available('proc_open') && $this->environment_function_available('proc_close');
            $add('【运维建议】proc_open / proc_close', $proc_available ? 'ok' : 'warn', $proc_available ? '可用' : '被禁用或不可用', '主要影响部分 WP-CLI 外部命令和运维工具，不是 OpenAI 翻译本身的硬依赖。');
            $temp_writable = wp_is_writable(get_temp_dir());
            $add('【OpenCC 按需】临时目录', $temp_writable ? 'ok' : ($opencc_required ? 'bad' : 'warn'), esc_html(get_temp_dir()), $opencc_required ? 'OpenCC 会写临时输入/输出文件，当前路由使用 OpenCC，目录必须可写。' : 'OpenAI/Agent 不依赖 OpenCC 临时文件；启用 OpenCC 前必须修复。');
            $db_charset = isset($wpdb->charset) ? (string)$wpdb->charset : '';
            $add('【必需】数据库字符集', stripos($db_charset, 'utf8') === 0 ? 'ok' : 'warn', '<code>' . esc_html($db_charset !== '' ? $db_charset : '未知') . '</code>', '建议使用 utf8mb4，避免多语言字符或 emoji 写回异常。');
            $packet = $wpdb->get_var("SHOW VARIABLES LIKE 'max_allowed_packet'", 1);
            $packet_bytes = is_numeric($packet) ? (int)$packet : 0;
            $add('【建议】MySQL max_allowed_packet', ($packet_bytes === 0 || $packet_bytes >= 16 * 1024 * 1024) ? 'ok' : 'warn', $packet_bytes > 0 ? esc_html(size_format($packet_bytes)) : '无法读取', '长文章和大型 postmeta 建议至少 16MB；无法读取通常是数据库权限限制，不代表异常。');

            foreach ($this->tables as $key => $name) {
                $exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === (string)$name;
                $rows = $exists ? (int)$wpdb->get_var("SELECT COUNT(*) FROM {$name}") : 0;
                $add('数据表：' . $key, $exists ? 'ok' : 'bad', '<code>' . esc_html($name) . '</code>' . ($exists ? '，行数：' . esc_html((string)$rows) : '，不存在'), '如果表不存在，请停用/启用插件或执行网络启用触发建表。');
            }

            $api_base = trim((string)($settings['openai_api_base'] ?? ''));
            $api_key = trim((string)($settings['openai_api_key'] ?? ''));
            $add('OpenAI 兼容 API Base', $api_base !== '' ? 'ok' : 'warn', $api_base !== '' ? '<code>' . esc_html($api_base) . '</code>' : '未设置', '兼容 OpenAI Chat Completions 的接口地址。');
            $add('OpenAI 兼容 API Key', $api_key !== '' ? 'ok' : 'warn', $api_key !== '' ? '已填写' : '未填写', '未填写时 OpenAI 兼容翻译任务会失败。');

            $opencc = $this->get_opencc_environment_status($settings);
            $add('OpenCC 命令', $opencc['status'], $opencc['value'], $opencc['help']);
            $add('OpenCC 转换测试', $opencc['test_status'], $opencc['test_value'], $opencc['test_help']);

            return $checks;
        }

        private function get_opencc_environment_status($settings)
        {
            $configured = trim((string)($settings['opencc_binary_path'] ?? ''));
            $binary_note = '';
            $status = 'warn';

            $opencc_required = $this->environment_opencc_is_required($settings);
            if (!$this->environment_function_available('shell_exec')) {
                return [
                    'status' => $opencc_required ? 'bad' : 'warn',
                    'value' => 'shell_exec 被禁用，无法检测 opencc' . ($opencc_required ? '（当前路由需要 OpenCC）' : '（当前路由未使用 OpenCC）'),
                    'help' => '需要在 PHP 配置中允许 shell_exec，或后续改造为 PHP OpenCC 扩展/服务化接口。',
                    'test_status' => $opencc_required ? 'bad' : 'warn',
                    'test_value' => '无法测试',
                    'test_help' => 'OpenCC 转换依赖 shell_exec。',
                ];
            }

            if ($configured !== '') {
                $binary_note = '后台配置：<code>' . esc_html($configured) . '</code>';
                if ($this->is_absolute_path($configured)) {
                    $status = (file_exists($configured) && is_executable($configured)) ? 'ok' : 'bad';
                }
            }

            $resolved = trim((string)@shell_exec('command -v opencc 2>/dev/null'));
            if ($resolved !== '') {
                $status = 'ok';
                $binary_note .= ($binary_note ? '<br>' : '') . '系统检测：<code>' . esc_html($resolved) . '</code>';
            } elseif ($binary_note === '') {
                $binary_note = '未检测到 opencc 命令';
                $status = $opencc_required ? 'bad' : 'warn';
            }

            $sample = '软件测试';
            $converted = $this->opencc_convert_text($sample, $settings);
            $test_ok = is_string($converted) && $converted !== '' && $converted !== $sample;
            return [
                'status' => $status,
                'value' => $binary_note,
                'help' => 'Debian/Ubuntu 可执行 apt install -y opencc；后台路径可留空自动检测。',
                'test_status' => $test_ok ? 'ok' : ($opencc_required ? 'bad' : 'warn'),
                'test_value' => $test_ok ? esc_html($sample . ' → ' . $converted) : '未得到有效转换结果',
                'test_help' => '测试使用默认 OpenCC 配置：<code>' . esc_html($this->get_opencc_config($settings)) . '</code>。如果未通过，请检查 opencc 是否安装、路径是否正确、配置文件是否存在。',
            ];
        }


        private function environment_function_available($name)
        {
            $name = trim((string)$name);
            if ($name === '' || !function_exists($name)) {
                return false;
            }
            $disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
            return !in_array($name, $disabled, true);
        }

        private function environment_opencc_is_required($settings)
        {
            $settings = is_array($settings) ? $settings : [];
            $values = [];
            $values[] = (string)($settings['translation_default_engine'] ?? '');
            foreach (['translation_engines_by_lang', 'translation_engines_by_post_type', 'translation_engines_by_lang_post_type'] as $key) {
                foreach ((array)($settings[$key] ?? []) as $engine) {
                    $values[] = (string)$engine;
                }
            }
            foreach ($values as $engine) {
                if ($this->is_opencc_engine(sanitize_key($engine)) || sanitize_key($engine) === 'opencc') {
                    return true;
                }
            }
            // Simplified-Chinese source + Traditional-Chinese targets default to OpenCC unless
            // explicitly routed elsewhere. Other source languages must not require OpenCC.
            foreach ((array)$this->get_i18n_sites() as $site) {
                if (empty($site['enabled']) || (int)($site['blog_id'] ?? 0) === (int)($settings['source_blog_id'] ?? 0)) {
                    continue;
                }
                $lang = sanitize_key((string)($site['lang_slug'] ?? ''));
                if (!$this->should_offer_opencc_for_target_lang($lang, $settings)) {
                    continue;
                }
                $configured = sanitize_key((string)((array)($settings['translation_engines_by_lang'] ?? [])[$lang] ?? ''));
                if ($configured === '' || $this->is_opencc_engine($configured)) {
                    return true;
                }
            }
            return false;
        }

        private function parse_size_to_bytes($value)
        {
            $value = trim((string)$value);
            if ($value === '' || $value === '-1') {
                return 0;
            }
            $unit = strtolower(substr($value, -1));
            $number = (float)$value;
            switch ($unit) {
                case 'g':
                    $number *= 1024;
                    // no break
                case 'm':
                    $number *= 1024;
                    // no break
                case 'k':
                    $number *= 1024;
            }
            return (int)$number;
        }

        private function is_absolute_path($path)
        {
            $path = (string)$path;
            return $path !== '' && ($path[0] === '/' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path));
        }
    }
}
