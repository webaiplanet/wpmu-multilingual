<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 后台表单处理、站点设置与语言规范化。
 *
 * 本 trait 仅用于拆分历史 WPMU_Multilingual 大类；方法签名和可见性保持不变。
 */
if (!trait_exists('WPMU_ML_Core_Admin_Actions_Trait')) {
    trait WPMU_ML_Core_Admin_Actions_Trait {
    public function handle_save_sites() {
        $this->verify_network_action();
        global $wpdb;
        $sites = isset($_POST['sites']) && is_array($_POST['sites']) ? $_POST['sites'] : [];
        $source_blog_id = isset($_POST['source_blog_id']) ? absint($_POST['source_blog_id']) : 0;
        $front_blog_id = isset($_POST['front_blog_id']) ? absint($_POST['front_blog_id']) : 0;

        foreach ($sites as $blog_id => $data) {
            $blog_id = absint($blog_id);
            $site = get_site($blog_id);
            if (!$site) {
                continue;
            }
            $enabled = !empty($data['enabled']) ? 1 : 0;
            $url = get_home_url($blog_id, '/');
            $path = $site->path;
            $detected = $this->detect_site_language_defaults($blog_id, $path);
            $lang = sanitize_key($data['lang_slug'] ?? '') ?: $detected['lang_slug'];
            // Locale is never trusted from the form. It is always read from the subsite's
            // Settings > General > Site Language so translation jobs cannot drift from WordPress.
            $locale = $this->get_site_wp_locale($blog_id);
            if ($locale === '') {
                $locale = (string)($detected['locale'] ?? '');
            }
            $auto_language_name = $this->get_locale_language_name($locale);
            $custom_language_name = sanitize_text_field(wp_unslash((string)($data['language_name'] ?? '')));
            $language_name = $custom_language_name !== '' ? $custom_language_name : $auto_language_name;
            $translation_locale = $this->normalize_language_tag(wp_unslash((string)($data['translation_locale'] ?? '')));
            $effective_translation_locale = $translation_locale !== '' ? $translation_locale : $this->normalize_language_tag($locale);
            $translation_language_name = $effective_translation_locale !== '' ? $this->get_locale_ai_language_name($effective_translation_locale) : $this->get_locale_ai_language_name($locale);
            $hreflang = $this->normalize_hreflang($data['hreflang'] ?? '') ?: $this->normalize_hreflang($detected['hreflang'] ?: $lang);
            $sort = intval($data['sort_order'] ?? 0);

            $site_write_result = $wpdb->replace($this->tables['sites'], [
                'blog_id' => $blog_id,
                'lang_slug' => $lang ?: 'site-' . $blog_id,
                'locale' => $locale,
                'language_name' => $language_name,
                'translation_locale' => $translation_locale,
                'translation_language_name' => $translation_language_name,
                'hreflang' => $hreflang ?: $lang,
                'site_url' => $url,
                'site_path' => $path,
                'enabled' => $enabled,
                'is_source' => $blog_id === $source_blog_id ? 1 : 0,
                'is_front_default' => $blog_id === $front_blog_id ? 1 : 0,
                'sort_order' => $sort,
            ], ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%d']);
            if ($site_write_result === false) {
                $db_error = trim((string)$wpdb->last_error);
                $message = '语言站点表写入失败（Blog ID ' . $blog_id . '）。';
                if ($db_error !== '') {
                    $message .= ' 数据库错误：' . $db_error;
                }
                wp_die(esc_html($message), 'WPMU 多语言设置保存失败', ['response' => 500]);
            }
        }

        $settings = $this->get_settings();
        $settings['source_blog_id'] = $source_blog_id;
        $settings['front_blog_id'] = $front_blog_id;
        $this->persist_network_settings($settings);
        if (!empty($settings['enable_menu_language_switcher'])) {
            $this->register_language_switcher_post_type();
            $this->sync_language_switcher_entries_all_sites();
            $this->sync_language_switcher_menus_all_sites();
        }

        wp_safe_redirect(network_admin_url('admin.php?page=wpmu-multilingual&tab=sites&updated=1'));
        exit;
    }

    public function handle_save_switcher_settings() {
        $this->verify_network_action();
        $settings = $this->get_settings();
        $switcher_mode = sanitize_key((string)($_POST['language_switcher_call_mode'] ?? 'code'));
        if (!in_array($switcher_mode, ['code', 'menu'], true)) {
            $switcher_mode = 'code';
        }
        $flag_mode = sanitize_key((string)($_POST['language_switcher_flag_mode'] ?? 'none'));
        if (!in_array($flag_mode, ['none', 'before', 'after'], true)) {
            $flag_mode = 'none';
        }
        $flag_style = sanitize_key((string)($_POST['language_switcher_flag_style'] ?? '4x3'));
        if (!in_array($flag_style, ['4x3', '1x1'], true)) {
            $flag_style = '4x3';
        }
        $flag_size = absint($_POST['language_switcher_flag_size'] ?? 24);
        if ($flag_size < 12) {
            $flag_size = 24;
        }
        if ($flag_size > 64) {
            $flag_size = 64;
        }
        $flag_radius = absint($_POST['language_switcher_flag_radius'] ?? 2);
        if ($flag_radius > 32) {
            $flag_radius = 32;
        }
        $unpublished_policy = sanitize_key((string)($_POST['language_switcher_unpublished_policy'] ?? 'hide'));
        if (!in_array($unpublished_policy, ['hide', 'notice'], true)) {
            $unpublished_policy = 'hide';
        }
        $settings['language_switcher_call_mode'] = $switcher_mode;
        $settings['language_switcher_flag_mode'] = $flag_mode;
        $settings['language_switcher_flag_style'] = $flag_style;
        $settings['language_switcher_flag_size'] = $flag_size;
        $settings['language_switcher_flag_radius'] = $flag_radius;
        $settings['language_switcher_unpublished_policy'] = $unpublished_policy;
        if (isset($_POST['enable_menu_language_switcher'])) {
            $settings['enable_menu_language_switcher'] = !empty($_POST['enable_menu_language_switcher']) ? 1 : 0;
        }
        $this->persist_network_settings($settings);

        if (!empty($settings['enable_menu_language_switcher'])) {
            $this->register_language_switcher_post_type();
            $this->sync_language_switcher_entries_all_sites();
            $this->sync_language_switcher_menus_all_sites();
        }

        wp_safe_redirect(network_admin_url('admin.php?page=wpmu-multilingual&tab=switcher&updated=1'));
        exit;
    }

    public function handle_save_settings() {
        $this->verify_network_action();
        $settings = $this->get_settings();
        $settings['enable_hreflang'] = !empty($_POST['enable_hreflang']) ? 1 : 0;
        $settings['hide_unpublished'] = !empty($_POST['hide_unpublished']) ? 1 : 0;
        $settings['x_default_mode'] = in_array(($_POST['x_default_mode'] ?? 'front'), ['front','source','none'], true) ? $_POST['x_default_mode'] : 'front';

        // v0.4.0 起优先使用勾选式表单；兼容旧版 textarea 字段。
        if (isset($_POST['translatable_post_types_checked']) || isset($_POST['translatable_post_types_manual'])) {
            $shared_post_types = $this->merge_checked_and_manual('shared_post_types_checked', 'shared_post_types_manual');
            $translatable_post_types = $this->merge_checked_and_manual('translatable_post_types_checked', 'translatable_post_types_manual');
            // v0.4.3 起“参与翻译”和“共享发布”互斥；重复时共享发布优先。
            $settings['shared_post_types'] = $shared_post_types;
            $settings['translatable_post_types'] = array_values(array_diff($translatable_post_types, $shared_post_types));
            // v0.4.1 起文章类型只采用白名单逻辑：未勾选即不参与，不再需要“排除的文章类型”。
            $settings['excluded_post_types'] = [];
            // v0.4.2 起分类法也采用白名单逻辑：只同步勾选的分类法。
            $settings['sync_taxonomies'] = $this->merge_checked_and_manual('sync_taxonomies_checked', 'sync_taxonomies_manual');
            $settings['excluded_taxonomies'] = [];
        } else {
            $shared_post_types = $this->textarea_to_array($_POST['shared_post_types'] ?? '');
            $translatable_post_types = $this->textarea_to_array($_POST['translatable_post_types'] ?? '');
            $settings['shared_post_types'] = $shared_post_types;
            $settings['translatable_post_types'] = array_values(array_diff($translatable_post_types, $shared_post_types));
            $settings['excluded_post_types'] = [];
            $settings['sync_taxonomies'] = $this->textarea_to_array($_POST['sync_taxonomies'] ?? '');
            $settings['excluded_taxonomies'] = [];
        }
        $settings['translate_term_name'] = !empty($_POST['translate_term_name']) ? 1 : 0;
        $settings['translate_term_description'] = !empty($_POST['translate_term_description']) ? 1 : 0;

        $this->persist_network_settings($settings);
        wp_safe_redirect(network_admin_url('admin.php?page=wpmu-multilingual&tab=types&updated=1'));
        exit;
    }

    public function handle_save_misc_settings() {
        $this->verify_network_action();
        $settings = $this->get_settings();
        $settings['admin_bar_current_page_site_links'] = !empty($_POST['admin_bar_current_page_site_links']) ? 1 : 0;
        $settings['show_my_sites_language_card_meta'] = !empty($_POST['show_my_sites_language_card_meta']) ? 1 : 0;
        $settings['admin_bar_language_site_labels'] = !empty($_POST['admin_bar_language_site_labels']) ? 1 : 0;
        $this->persist_network_settings($settings);
        wp_safe_redirect(network_admin_url('admin.php?page=wpmu-multilingual&tab=misc&updated=1'));
        exit;
    }

    public function handle_save_sync_settings() {
        $this->verify_network_action();
        $settings = $this->get_settings();
        foreach (['auto_sync_enabled','auto_sync_on_update','restore_sync_enabled','protect_translated','queue_on_sync','sync_title','sync_content','sync_excerpt','sync_meta','sync_terms'] as $key) {
            $settings[$key] = !empty($_POST[$key]) ? 1 : 0;
        }
        // slug 是 URL 稳定性字段，开发测试期也不允许关闭或翻译。
        $settings['sync_slug'] = 1;
        $trash_policy = sanitize_key($_POST['trash_sync_policy'] ?? 'drafts_only');
        $settings['trash_sync_policy'] = in_array($trash_policy, ['none','drafts_only','all'], true) ? $trash_policy : 'drafts_only';
        $delete_policy = sanitize_key($_POST['delete_sync_policy'] ?? 'drafts_only');
        $settings['delete_sync_policy'] = in_array($delete_policy, ['none','drafts_only','all'], true) ? $delete_policy : 'drafts_only';
        $status = sanitize_key($_POST['target_default_status'] ?? 'draft');
        $settings['target_default_status'] = in_array($status, ['draft','pending'], true) ? $status : 'draft';
        // v0.5.6 起取消“按语言指定同步状态”，避免与翻译完成后状态混淆。
        unset($settings['sync_status_by_lang']);
        $this->persist_network_settings($settings);
        wp_safe_redirect(network_admin_url('admin.php?page=wpmu-multilingual&tab=sync&updated=1'));
        exit;
    }

    public function handle_save_translation_settings() {
        $this->verify_network_action();
        $settings = $this->get_settings();
        $section = sanitize_key($_POST['settings_section'] ?? 'all');
        if (!in_array($section, ['all', 'queue', 'engines'], true)) {
            $section = 'all';
        }

        // 引擎页只提交当前一级选项卡。未显示的面板不会再参与 POST，
        // 因此必须把未编辑分组回填为当前值，避免缺失 checkbox/数组被误清空。
        if ($section === 'engines') {
            $engine_tab_for_save = sanitize_key((string)($_POST['engine_tab'] ?? 'routing'));
            if (!in_array($engine_tab_for_save, ['routing','openai','agent','opencc','manual','rules','advanced'], true)) {
                $engine_tab_for_save = 'routing';
            }

            if ($engine_tab_for_save !== 'routing') {
                foreach (['translation_default_engine','translation_complete_status'] as $key) {
                    if (!isset($_POST[$key])) {
                        $_POST[$key] = $settings[$key] ?? '';
                    }
                }
                foreach (['translation_engines_by_lang','translation_auto_by_lang','translation_status_by_lang','translation_engines_by_post_type','translation_models_by_post_type','translation_status_by_post_type','translation_engines_by_lang_post_type','translation_models_by_lang_post_type','translation_status_by_lang_post_type'] as $key) {
                    if (!isset($_POST[$key])) {
                        $_POST[$key] = is_array($settings[$key] ?? null) ? $settings[$key] : [];
                    }
                }
            }

            if ($engine_tab_for_save !== 'openai') {
                foreach (['openai_api_base','openai_model','openai_temperature','openai_timeout','openai_max_chars','openai_fragment_batch_fields','openai_central_translation_batch_fields','openai_central_qa_max_fields','openai_central_qa_batch_fields','openai_central_qa_batch_chars','openai_qa_batch_fields','openai_agent_mode','openai_agent_cjk_residue_limit'] as $key) {
                    if (!isset($_POST[$key])) {
                        $_POST[$key] = $settings[$key] ?? '';
                    }
                }
                foreach (['openai_semantic_block_translation','openai_residual_body_pass','openai_translate_meta','openai_translate_seo_meta','openai_agent_quality_check','openai_editorial_review_enabled','openai_fast_quality_pipeline','openai_centralized_quality_pipeline','openai_central_qa_auto_repair','openai_qa_draft_on_incomplete','openai_qa_strict_status','openai_agent_fail_on_qa'] as $key) {
                    if (!isset($_POST[$key]) && !empty($settings[$key])) {
                        $_POST[$key] = 1;
                    }
                }
                if (!isset($_POST['openai_language_settings'])) {
                    $_POST['openai_language_settings'] = is_array($settings['openai_language_settings'] ?? null) ? $settings['openai_language_settings'] : [];
                }
            }

            if ($engine_tab_for_save !== 'rules') {
                foreach (['openai_agent_site_rules','openai_agent_terms','openai_excluded_meta_keys','openai_excluded_html_tags'] as $key) {
                    if (!isset($_POST[$key])) {
                        $_POST[$key] = $settings[$key] ?? '';
                    }
                }
            }

            if ($engine_tab_for_save !== 'opencc') {
                if (!isset($_POST['opencc_binary_path'])) {
                    $_POST['opencc_binary_path'] = $settings['opencc_binary_path'] ?? '';
                }
                if (!isset($_POST['opencc_config'])) {
                    $_POST['opencc_config'] = $settings['opencc_config'] ?? 's2twp.json';
                }
                foreach (['opencc_convert_meta','opencc_convert_seo_meta'] as $key) {
                    if (!isset($_POST[$key]) && !empty($settings[$key])) {
                        $_POST[$key] = 1;
                    }
                }
            }
        }

        if (in_array($section, ['all', 'queue'], true)) {
            $runner = sanitize_key($_POST['translation_queue_runner'] ?? $settings['translation_queue_runner']);
            $settings['translation_queue_runner'] = in_array($runner, ['manual','wp_cron','cli'], true) ? $runner : 'manual';
            $settings['translation_queue_limit'] = max(1, min(20, absint($_POST['translation_queue_limit'] ?? $settings['translation_queue_limit'])));
            $settings['translation_openai_concurrency'] = max(1, min(10, absint($_POST['translation_openai_concurrency'] ?? ($settings['translation_openai_concurrency'] ?? 1))));
            $settings['translation_opencc_concurrency'] = max(1, min(50, absint($_POST['translation_opencc_concurrency'] ?? ($settings['translation_opencc_concurrency'] ?? 5))));
            $settings['translation_agent_claim_limit'] = max(1, min(20, absint($_POST['translation_agent_claim_limit'] ?? ($settings['translation_agent_claim_limit'] ?? 1))));
            $settings['translation_lock_ttl_minutes'] = max(1, min(120, absint($_POST['translation_lock_ttl_minutes'] ?? $settings['translation_lock_ttl_minutes'])));
            $settings['translation_max_attempts'] = max(0, min(20, absint($_POST['translation_max_attempts'] ?? $settings['translation_max_attempts'])));
            $settings['translation_retry_delay_minutes'] = max(0, min(1440, absint($_POST['translation_retry_delay_minutes'] ?? ($settings['translation_retry_delay_minutes'] ?? 10))));
        }

        if (in_array($section, ['all', 'engines'], true)) {
            $valid_default_engines = array_keys($this->get_default_translation_engines());
            $engine = $this->normalize_translation_engine_key($_POST['translation_default_engine'] ?? $settings['translation_default_engine'], '');
            $settings['translation_default_engine'] = in_array($engine, $valid_default_engines, true) ? $engine : 'manual';

            $complete_status = sanitize_key($_POST['translation_complete_status'] ?? $settings['translation_complete_status']);
            $settings['translation_complete_status'] = in_array($complete_status, ['draft','pending','publish'], true) ? $complete_status : 'pending';

            $engines_by_lang = [];
            if (!empty($_POST['translation_engines_by_lang']) && is_array($_POST['translation_engines_by_lang'])) {
                foreach ($_POST['translation_engines_by_lang'] as $lang => $value) {
                    $lang = sanitize_key($lang);
                    $raw_value = sanitize_key((string)$value);
                    if ($raw_value === '') {
                        continue;
                    }
                    $value = $this->normalize_translation_engine_key($raw_value, $lang);
                    $valid_lang_engines = array_keys($this->get_translation_engines_for_lang($lang));
                    if ($lang && in_array($value, $valid_lang_engines, true)) {
                        $engines_by_lang[$lang] = $value;
                    }
                }
            }
            $settings['translation_engines_by_lang'] = $engines_by_lang;

            $auto_by_lang = [];
            if (!empty($_POST['translation_auto_by_lang']) && is_array($_POST['translation_auto_by_lang'])) {
                foreach ($_POST['translation_auto_by_lang'] as $lang => $value) {
                    $lang = sanitize_key($lang);
                    if ($lang && !empty($value)) {
                        $auto_by_lang[$lang] = 1;
                    }
                }
            }
            $settings['translation_auto_by_lang'] = $auto_by_lang;

            $status_by_lang = [];
            if (!empty($_POST['translation_status_by_lang']) && is_array($_POST['translation_status_by_lang'])) {
                foreach ($_POST['translation_status_by_lang'] as $lang => $value) {
                    $lang = sanitize_key($lang);
                    $value = sanitize_key($value);
                    if ($lang && in_array($value, ['draft','pending','publish'], true)) {
                        $status_by_lang[$lang] = $value;
                    }
                }
            }
            $settings['translation_status_by_lang'] = $status_by_lang;

            $engines_by_post_type = [];
            if (!empty($_POST['translation_engines_by_post_type']) && is_array($_POST['translation_engines_by_post_type'])) {
                foreach ($_POST['translation_engines_by_post_type'] as $post_type => $value) {
                    $post_type = sanitize_key($post_type);
                    $raw_value = sanitize_key((string)$value);
                    if ($post_type === '' || $raw_value === '') {
                        continue;
                    }
                    $value = $this->normalize_translation_engine_key($raw_value, '');
                    if (array_key_exists($value, $this->get_default_translation_engines())) {
                        $engines_by_post_type[$post_type] = $value;
                    }
                }
            }
            $settings['translation_engines_by_post_type'] = $engines_by_post_type;

            $models_by_post_type = [];
            if (!empty($_POST['translation_models_by_post_type']) && is_array($_POST['translation_models_by_post_type'])) {
                foreach ($_POST['translation_models_by_post_type'] as $post_type => $value) {
                    $post_type = sanitize_key($post_type);
                    $value = sanitize_text_field((string)$value);
                    if ($post_type !== '' && $value !== '') {
                        $models_by_post_type[$post_type] = $value;
                    }
                }
            }
            $settings['translation_models_by_post_type'] = $models_by_post_type;

            $status_by_post_type = [];
            if (!empty($_POST['translation_status_by_post_type']) && is_array($_POST['translation_status_by_post_type'])) {
                foreach ($_POST['translation_status_by_post_type'] as $post_type => $value) {
                    $post_type = sanitize_key($post_type);
                    $value = sanitize_key($value);
                    if ($post_type !== '' && in_array($value, ['draft','pending','publish'], true)) {
                        $status_by_post_type[$post_type] = $value;
                    }
                }
            }
            $settings['translation_status_by_post_type'] = $status_by_post_type;

            $route_lang_scope = sanitize_key((string)($_POST['route_lang'] ?? ''));
            $engines_by_combo = $route_lang_scope !== '' && is_array($settings['translation_engines_by_lang_post_type'] ?? null)
                ? $settings['translation_engines_by_lang_post_type']
                : [];
            if ($route_lang_scope !== '') {
                foreach (array_keys($engines_by_combo) as $existing_route_key) {
                    if (strpos((string)$existing_route_key, $route_lang_scope . ':') === 0) {
                        unset($engines_by_combo[$existing_route_key]);
                    }
                }
            }
            if (!empty($_POST['translation_engines_by_lang_post_type']) && is_array($_POST['translation_engines_by_lang_post_type'])) {
                foreach ($_POST['translation_engines_by_lang_post_type'] as $route_key => $value) {
                    $route_key = sanitize_text_field((string)$route_key);
                    $raw_value = sanitize_key((string)$value);
                    if ($route_key === '' || $raw_value === '' || strpos($route_key, ':') === false) {
                        continue;
                    }
                    list($lang, $post_type) = array_map('sanitize_key', explode(':', $route_key, 2));
                    if ($lang === '' || $post_type === '') {
                        continue;
                    }
                    $normalized_key = $this->get_translation_route_key($lang, $post_type);
                    $value = $this->normalize_translation_engine_key($raw_value, $lang);
                    if (array_key_exists($value, $this->get_translation_engines_for_lang($lang))) {
                        $engines_by_combo[$normalized_key] = $value;
                    }
                }
            }
            $settings['translation_engines_by_lang_post_type'] = $engines_by_combo;

            $models_by_combo = $route_lang_scope !== '' && is_array($settings['translation_models_by_lang_post_type'] ?? null)
                ? $settings['translation_models_by_lang_post_type']
                : [];
            if ($route_lang_scope !== '') {
                foreach (array_keys($models_by_combo) as $existing_route_key) {
                    if (strpos((string)$existing_route_key, $route_lang_scope . ':') === 0) {
                        unset($models_by_combo[$existing_route_key]);
                    }
                }
            }
            if (!empty($_POST['translation_models_by_lang_post_type']) && is_array($_POST['translation_models_by_lang_post_type'])) {
                foreach ($_POST['translation_models_by_lang_post_type'] as $route_key => $value) {
                    $route_key = sanitize_text_field((string)$route_key);
                    $value = sanitize_text_field((string)$value);
                    if ($route_key === '' || $value === '' || strpos($route_key, ':') === false) {
                        continue;
                    }
                    list($lang, $post_type) = array_map('sanitize_key', explode(':', $route_key, 2));
                    if ($lang !== '' && $post_type !== '') {
                        $models_by_combo[$this->get_translation_route_key($lang, $post_type)] = $value;
                    }
                }
            }
            $settings['translation_models_by_lang_post_type'] = $models_by_combo;

            $status_by_combo = $route_lang_scope !== '' && is_array($settings['translation_status_by_lang_post_type'] ?? null)
                ? $settings['translation_status_by_lang_post_type']
                : [];
            if ($route_lang_scope !== '') {
                foreach (array_keys($status_by_combo) as $existing_route_key) {
                    if (strpos((string)$existing_route_key, $route_lang_scope . ':') === 0) {
                        unset($status_by_combo[$existing_route_key]);
                    }
                }
            }
            if (!empty($_POST['translation_status_by_lang_post_type']) && is_array($_POST['translation_status_by_lang_post_type'])) {
                foreach ($_POST['translation_status_by_lang_post_type'] as $route_key => $value) {
                    $route_key = sanitize_text_field((string)$route_key);
                    $value = sanitize_key($value);
                    if ($route_key === '' || strpos($route_key, ':') === false || !in_array($value, ['draft','pending','publish'], true)) {
                        continue;
                    }
                    list($lang, $post_type) = array_map('sanitize_key', explode(':', $route_key, 2));
                    if ($lang !== '' && $post_type !== '') {
                        $status_by_combo[$this->get_translation_route_key($lang, $post_type)] = $value;
                    }
                }
            }
            $settings['translation_status_by_lang_post_type'] = $status_by_combo;

            $agent_api_action = sanitize_key((string)($_POST['agent_api_action'] ?? ''));
            if (in_array($agent_api_action, ['generate', 'reset'], true)) {
                $new_agent_key = '';
                if (function_exists('random_bytes')) {
                    try {
                        $new_agent_key = bin2hex(random_bytes(32));
                    } catch (Exception $e) {
                        $new_agent_key = '';
                    }
                }
                if ($new_agent_key === '') {
                    $new_agent_key = wp_generate_password(64, false, false);
                }
                $settings['agent_api_token'] = $new_agent_key;
            } elseif ($agent_api_action === 'disable') {
                $settings['agent_api_token'] = '';
            } else {
                $settings['agent_api_token'] = sanitize_text_field((string)($settings['agent_api_token'] ?? ''));
            }

            $agent_tools_api_action = sanitize_key((string)($_POST['agent_tools_api_action'] ?? ''));
            if (in_array($agent_tools_api_action, ['generate', 'reset'], true)) {
                $new_tools_key = '';
                if (function_exists('random_bytes')) {
                    try {
                        $new_tools_key = bin2hex(random_bytes(32));
                    } catch (Exception $e) {
                        $new_tools_key = '';
                    }
                }
                if ($new_tools_key === '') {
                    $new_tools_key = wp_generate_password(64, false, false);
                }
                $settings['agent_tools_api_token'] = $new_tools_key;
            } elseif ($agent_tools_api_action === 'disable') {
                $settings['agent_tools_api_token'] = '';
            } else {
                $settings['agent_tools_api_token'] = sanitize_text_field((string)($settings['agent_tools_api_token'] ?? ''));
            }

            $settings['openai_api_base'] = esc_url_raw(trim((string)($_POST['openai_api_base'] ?? $settings['openai_api_base'])));
            if (!$settings['openai_api_base']) {
                $settings['openai_api_base'] = 'https://api.openai.com/v1';
            }
            if (!empty($_POST['openai_api_key_clear'])) {
                $settings['openai_api_key'] = '';
            } elseif (isset($_POST['openai_api_key']) && trim((string)wp_unslash($_POST['openai_api_key'])) !== '') {
                $settings['openai_api_key'] = trim((string)wp_unslash($_POST['openai_api_key']));
            }
            $settings['openai_model'] = sanitize_text_field((string)($_POST['openai_model'] ?? $settings['openai_model']));
            if ($settings['openai_model'] === '') {
                $settings['openai_model'] = 'gpt-4o-mini';
            }
            $temperature = isset($_POST['openai_temperature']) ? (float)$_POST['openai_temperature'] : (float)$settings['openai_temperature'];
            $settings['openai_temperature'] = (string)max(0, min(2, $temperature));
            $settings['openai_timeout'] = max(15, absint($_POST['openai_timeout'] ?? $settings['openai_timeout']));
            $settings['openai_max_chars'] = 6000;
            // 0.9.6.2: batching is an internal safety profile. Keeping it fixed avoids
            // oversized requests, contradictory tuning, and site-specific quality drift.
            $settings['openai_fragment_batch_fields'] = 0;
            $settings['openai_central_translation_batch_fields'] = 200;
            $settings['openai_central_qa_max_fields'] = 8; // legacy risk compatibility only
            $settings['openai_adaptive_qa_max_fields'] = 24;
            $settings['openai_translation_self_review'] = 1;
            $settings['openai_central_qa_batch_fields'] = 80;
            $settings['openai_central_qa_batch_chars'] = 12000;
            $settings['openai_qa_batch_fields'] = 3;
            $settings['openai_semantic_block_translation'] = 1;
            $settings['openai_residual_body_pass'] = 0;
            $settings['openai_translate_meta'] = !empty($_POST['openai_translate_meta']) ? 1 : 0;
            $settings['openai_translate_seo_meta'] = !empty($_POST['openai_translate_seo_meta']) ? 1 : 0;
            $settings['openai_excluded_meta_keys'] = $this->sanitize_meta_key_pattern_list(wp_unslash((string)($_POST['openai_excluded_meta_keys'] ?? ($settings['openai_excluded_meta_keys'] ?? ''))));
            $settings['openai_excluded_html_tags'] = implode("\n", $this->get_openai_excluded_html_selectors(['openai_excluded_html_tags' => wp_unslash((string)($_POST['openai_excluded_html_tags'] ?? ($settings['openai_excluded_html_tags'] ?? 'pre')))]));
            $code_block_strategy = sanitize_key($_POST['openai_code_block_strategy'] ?? ($settings['openai_code_block_strategy'] ?? 'smart_text'));
            $settings['openai_code_block_strategy'] = in_array($code_block_strategy, ['protect','smart_text'], true) ? $code_block_strategy : 'smart_text';
            $inline_code_strategy = sanitize_key($_POST['openai_inline_code_strategy'] ?? ($settings['openai_inline_code_strategy'] ?? 'smart'));
            $settings['openai_inline_code_strategy'] = in_array($inline_code_strategy, ['protect','smart'], true) ? $inline_code_strategy : 'smart';
            $quality_enabled = !empty($_POST['openai_agent_quality_check']);
            $settings['openai_agent_quality_check'] = $quality_enabled ? 1 : 0;
            $settings['openai_agent_mode'] = $quality_enabled ? 'rules_qa' : 'rules';
            $settings['openai_editorial_review_enabled'] = $quality_enabled ? 1 : 0;
            $settings['openai_fast_quality_pipeline'] = 1;
            $settings['openai_centralized_quality_pipeline'] = 1;
            $settings['openai_qa_coverage_mode'] = $quality_enabled ? 'adaptive' : 'off';
            $settings['openai_central_qa_auto_repair'] = $quality_enabled ? 1 : 0;
            $settings['openai_qa_draft_on_incomplete'] = $quality_enabled ? 1 : 0;
            $settings['openai_qa_strict_status'] = $quality_enabled ? 1 : 0;
            $settings['openai_agent_cjk_residue_limit'] = 0;
            $settings['openai_agent_fail_on_qa'] = $quality_enabled ? 1 : 0;

            $language_profiles = is_array($settings['openai_language_settings'] ?? null)
                ? $settings['openai_language_settings']
                : [];
            if (isset($_POST['openai_language_settings']) && is_array($_POST['openai_language_settings'])) {
                foreach ($_POST['openai_language_settings'] as $lang => $profile_data) {
                    $lang = sanitize_key((string)$lang);
                    if ($lang === '' || !is_array($profile_data)) {
                        continue;
                    }

                    $profile_model = sanitize_text_field(wp_unslash((string)($profile_data['model'] ?? '')));
                    $profile_temperature_raw = trim((string)wp_unslash($profile_data['temperature'] ?? ''));
                    $profile_temperature = '';
                    if ($profile_temperature_raw !== '' && is_numeric($profile_temperature_raw)) {
                        $profile_temperature = (string)max(0, min(2, (float)$profile_temperature_raw));
                    }
                    $profile_prompt = sanitize_textarea_field(wp_unslash((string)($profile_data['prompt'] ?? '')));

                    $normalized_profile = [
                        'model' => $profile_model,
                        'temperature' => $profile_temperature,
                        'prompt' => $profile_prompt,
                    ];

                    $has_custom_value = $profile_model !== ''
                        || $profile_temperature !== ''
                        || $profile_prompt !== '';

                    if ($has_custom_value) {
                        $language_profiles[$lang] = $normalized_profile;
                    } else {
                        unset($language_profiles[$lang]);
                    }
                }
            }
            $settings['openai_language_settings'] = $language_profiles;

            $settings['openai_agent_site_rules'] = sanitize_textarea_field(wp_unslash((string)($_POST['openai_agent_site_rules'] ?? ($settings['openai_agent_site_rules'] ?? ''))));
            $settings['openai_agent_terms'] = sanitize_textarea_field(wp_unslash((string)($_POST['openai_agent_terms'] ?? ($settings['openai_agent_terms'] ?? ''))));


            $settings['opencc_binary_path'] = sanitize_text_field(wp_unslash((string)($_POST['opencc_binary_path'] ?? $settings['opencc_binary_path'])));
            $settings['opencc_config'] = $this->sanitize_opencc_config($_POST['opencc_config'] ?? $settings['opencc_config'], $settings['opencc_config'] ?? 's2twp.json');
            $settings['opencc_convert_meta'] = !empty($_POST['opencc_convert_meta']) ? 1 : 0;
            $settings['opencc_convert_seo_meta'] = !empty($_POST['opencc_convert_seo_meta']) ? 1 : 0;
        }

        $this->persist_network_settings($settings);
        $this->maybe_schedule_queue_runner();
        if ($section === 'engines') {
            $engine_tab = sanitize_key($_POST['engine_tab'] ?? 'routing');
            if (!in_array($engine_tab, ['routing','openai','agent','opencc','manual','rules','advanced'], true)) {
                $engine_tab = 'routing';
            }
            wp_safe_redirect(network_admin_url('admin.php?page=wpmu-multilingual-engines&tab=' . $engine_tab . '&updated=1'));
        } else {
            wp_safe_redirect(network_admin_url('admin.php?page=wpmu-multilingual&tab=translation&updated=1'));
        }
        exit;
    }

    public function handle_run_batch_sync() {
        $this->verify_network_action();
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 20;
        $limit = max(1, min(500, $limit));
        $result = $this->batch_sync_recent_source_posts($limit);
        $this->log('info', 'batch_sync', '手动批量同步完成', $result);
        wp_safe_redirect(network_admin_url('admin.php?page=wpmu-multilingual&tab=translation&updated=1'));
        exit;
    }

    public function handle_rebuild_relations() {
        $this->verify_network_action();
        $result = $this->rebuild_relations();
        if (is_wp_error($result)) {
            wp_die(esc_html($result->get_error_message()), '文章关系重建已禁用', ['response' => 409]);
        }
        wp_safe_redirect(network_admin_url('admin.php?page=wpmu-multilingual&tab=relations&rebuilt=1'));
        exit;
    }

    public function handle_sync_same_id_drafts() {
        $this->verify_network_action();
        $this->log('info', 'sync_same_id_drafts', '当前版本暂未实现复杂同步，仅用于占位。');
        wp_safe_redirect(network_admin_url('admin.php?page=wpmu-multilingual&tab=tools&updated=1'));
        exit;
    }

    public function handle_sync_language_status() {
        $this->verify_network_action();
        $target_langs = isset($_POST['target_langs']) && is_array($_POST['target_langs']) ? array_map('sanitize_key', $_POST['target_langs']) : [];
        $target_post_status = sanitize_key($_POST['target_post_status'] ?? 'no_change');
        $relation_status = sanitize_key($_POST['relation_status'] ?? 'no_change');
        $post_types = isset($_POST['post_types']) && is_array($_POST['post_types']) ? array_map('sanitize_key', $_POST['post_types']) : [];

        $result = $this->sync_language_statuses($target_langs, $target_post_status, $relation_status, $post_types);
        $this->log('info', 'language_status_sync', '语言状态同步完成', $result);

        wp_safe_redirect(network_admin_url('admin.php?page=wpmu-multilingual&tab=tools&updated=1'));
        exit;
    }

    public function sync_language_statuses($target_langs, $target_post_status = 'no_change', $relation_status = 'no_change', $post_types = []) {
        global $wpdb;
        $target_langs = array_values(array_unique(array_filter(array_map('sanitize_key', (array)$target_langs))));
        $post_types = array_values(array_unique(array_filter(array_map('sanitize_key', (array)$post_types))));

        $valid_post_statuses = ['no_change','draft','pending','publish','private'];
        $valid_relation_statuses = ['no_change','needs_translation','needs_update','translated_update_pending','machine_translated','review_required','translated','shared_published'];
        $target_post_status = in_array($target_post_status, $valid_post_statuses, true) ? $target_post_status : 'no_change';
        $relation_status = in_array($relation_status, $valid_relation_statuses, true) ? $relation_status : 'no_change';

        if (!$target_langs || ($target_post_status === 'no_change' && $relation_status === 'no_change')) {
            return ['languages' => 0, 'posts_changed' => 0, 'relations_changed' => 0];
        }

        if (!$post_types) {
            $settings = $this->get_settings();
            $post_types = array_values(array_unique(array_merge((array)$settings['translatable_post_types'], (array)$settings['shared_post_types'])));
        }
        $post_types = array_values(array_filter($post_types, [$this, 'is_managed_post_type']));
        $post_type_in = $this->sql_in($post_types);
        if (!$post_type_in) {
            return ['languages' => 0, 'posts_changed' => 0, 'relations_changed' => 0];
        }

        $sites = $this->get_i18n_sites(true);
        $site_by_lang = [];
        foreach ($sites as $site) {
            $site_by_lang[$site['lang_slug']] = $site;
        }

        $languages = 0;
        $posts_changed = 0;
        $relations_changed = 0;
        $blocked = 0;

        foreach ($target_langs as $lang) {
            if (empty($site_by_lang[$lang]) || !empty($site_by_lang[$lang]['is_source'])) {
                continue;
            }
            $site = $site_by_lang[$lang];
            $blog_id = (int)$site['blog_id'];
            $languages++;

            $relations = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->tables['posts']}
                 WHERE target_blog_id = %d AND target_lang = %s
                   AND post_type IN ({$post_type_in}) AND target_post_id > 0
                 ORDER BY id ASC",
                $blog_id,
                $lang
            ), ARRAY_A);
            foreach ((array)$relations as $relation) {
                $identity = $this->validate_post_relation($relation, true);
                if (empty($identity['valid'])) {
                    $this->mark_relation_invalid((int)$relation['id'], (string)$identity['error_code'], (string)$identity['message'], [
                        'source_blog_id' => (int)$relation['source_blog_id'],
                        'source_post_id' => (int)$relation['source_post_id'],
                        'target_blog_id' => (int)$relation['target_blog_id'],
                        'target_post_id' => (int)$relation['target_post_id'],
                        'post_type' => (string)$relation['post_type'],
                        'action' => 'language_status_sync',
                    ]);
                    $blocked++;
                    continue;
                }

                $effective_post_status = (string)$identity['target_post']->post_status;
                if ($target_post_status !== 'no_change' && !in_array($effective_post_status, ['trash','auto-draft','inherit'], true) && $effective_post_status !== $target_post_status) {
                    if ((string)$identity['status'] === 'legacy_relation') {
                        $this->stamp_relation_target_identity($relation);
                    }
                    switch_to_blog($blog_id);
                    $updated = wp_update_post([
                        'ID' => (int)$relation['target_post_id'],
                        'post_status' => $target_post_status,
                    ], true);
                    restore_current_blog();
                    if (is_wp_error($updated)) {
                        $this->log('error', 'relation_update_blocked', '批量语言状态更新目标文章失败。', [
                            'relation_id' => (int)$relation['id'],
                            'target_blog_id' => $blog_id,
                            'target_post_id' => (int)$relation['target_post_id'],
                            'error_code' => $updated->get_error_code(),
                            'error' => $updated->get_error_message(),
                        ]);
                        $blocked++;
                        continue;
                    }
                    $effective_post_status = $target_post_status;
                    $posts_changed++;
                }

                $relation_update = [
                    'target_post_status' => $effective_post_status,
                    'target_modified' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ];
                if ($relation_status !== 'no_change' && !in_array($relation['relation_status'], ['source_deleted_keep','source_trashed_keep'], true)) {
                    $relation_update['relation_status'] = $relation_status;
                }
                $changed_rel = $wpdb->update($this->tables['posts'], $relation_update, ['id' => (int)$relation['id']]);
                if ($changed_rel) {
                    $relations_changed++;
                }
            }
        }

        return [
            'languages' => $languages,
            'posts_changed' => $posts_changed,
            'relations_changed' => $relations_changed,
            'blocked' => $blocked,
            'target_post_status' => $target_post_status,
            'relation_status' => $relation_status,
            'post_types' => $post_types,
            'target_langs' => $target_langs,
        ];
    }

    public function handle_translation_job_action() {
        $this->verify_network_action();
        global $wpdb;
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        $job_action = sanitize_key($_POST['job_action'] ?? '');
        $job = $job_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['jobs']} WHERE id = %d", $job_id), ARRAY_A) : null;
        if (!$job) {
            wp_safe_redirect(network_admin_url('admin.php?page=wpmu-multilingual&tab=translation&updated=1'));
            exit;
        }

        $message = '';
        if ($job_action === 'manual_done') {
            $this->mark_translation_job_done($job, 'manual_done');
            $message = '任务已标记为人工完成。';
        } elseif ($job_action === 'machine_translate') {
            // 最近任务里的“机器处理”统一走单任务处理入口，避免手动预加锁后中断造成假死锁。
            $wpdb->update($this->tables['jobs'], [
                'status' => 'pending',
                'attempts' => 0,
                'locked_at' => null,
                'locked_by' => '',
                'process_after' => current_time('mysql'),
                'last_error' => null,
                'started_at' => null,
                'finished_at' => null,
                'updated_at' => current_time('mysql'),
            ], ['id' => (int)$job['id']], ['%s','%d','%s','%s','%s','%s','%s','%s','%s'], ['%d']);

            $processed = $this->process_single_translation_job((int)$job['id'], 'manual-admin-job-action');
            if (is_wp_error($processed)) {
                wp_safe_redirect(add_query_arg(['wpmu_ml_error' => rawurlencode($processed->get_error_message())], network_admin_url('admin.php?page=wpmu-multilingual&tab=translation')));
                exit;
            }
            $message = '已处理指定机器翻译任务：job_id=' . intval($job['id']) . '。';
        } elseif ($job_action === 'retranslate') {
            $this->mark_translation_job_retranslate($job);
            $message = '任务已重新排队，并已清理锁。';
        }

        $args = ['updated' => 1];
        if ($message !== '') {
            $args['wpmu_ml_message'] = rawurlencode($message);
        }
        wp_safe_redirect(add_query_arg($args, network_admin_url('admin.php?page=wpmu-multilingual&tab=translation')));
        exit;
    }

    private function persist_network_settings($settings, $redirect_url = '') {
        global $wpdb;
        $settings = is_array($settings) ? $settings : [];
        update_site_option(self::OPTION, $settings);

        // 立即回读验证，避免数据库只读、对象缓存异常或写入失败时仍显示“已保存”。
        $stored = get_site_option(self::OPTION, null);
        $saved_ok = is_array($stored) && maybe_serialize($stored) === maybe_serialize($settings);
        if ($saved_ok) {
            return true;
        }

        $db_error = trim((string)$wpdb->last_error);
        $message = 'network option 写入后回读不一致。';
        if ($db_error !== '') {
            $message .= ' 数据库错误：' . $db_error;
        } else {
            $message .= ' 可能是数据库写权限、只读副本、持久对象缓存或安全插件拦截。';
        }
        $this->log('error', 'settings_save_failed', $message, [
            'option' => self::OPTION,
            'user_id' => get_current_user_id(),
            'network_id' => function_exists('get_current_network_id') ? get_current_network_id() : 0,
        ]);

        if ($redirect_url !== '') {
            wp_safe_redirect(add_query_arg('wpmu_ml_save_error', rawurlencode($message), $redirect_url));
            exit;
        }
        wp_die(esc_html($message), 'WPMU 多语言设置保存失败', ['response' => 500]);
    }

    private function verify_network_action() {
        if (!current_user_can('manage_network_options')) {
            wp_die('权限不足');
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);
    }

    private function textarea_to_array($text) {
        $lines = preg_split('/[\r\n,]+/', (string)$text);
        $out = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = sanitize_key($line);
            }
        }
        return array_values(array_unique($out));
    }

    public function sync_sites_from_network($overwrite_existing = true) {
        global $wpdb;
        $settings = $this->get_settings();
        $sites = get_sites(['number' => 0]);
        foreach ($sites as $site) {
            $blog_id = (int) $site->blog_id;
            $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['sites']} WHERE blog_id = %d", $blog_id), ARRAY_A);
            $path = $site->path;
            $guess = $this->detect_site_language_defaults($blog_id, $path);
            $is_source = (int)$settings['source_blog_id'] === $blog_id ? 1 : 0;
            $is_front = (int)$settings['front_blog_id'] === $blog_id ? 1 : 0;

            // Even when preserving user-managed fields, always refresh the WordPress-owned
            // Locale and its language name from the actual subsite settings.
            $locale = $this->get_site_wp_locale($blog_id);
            if ($locale === '') {
                $locale = (string)($guess['locale'] ?? '');
            }
            $auto_language_name = $this->get_locale_language_name($locale);
            $language_name = trim((string)($existing['language_name'] ?? ''));
            if ($language_name === '' || $language_name === $this->get_locale_language_name_legacy($locale)) {
                $language_name = $auto_language_name;
            }
            $translation_locale = $this->normalize_language_tag((string)($existing['translation_locale'] ?? ''));
            $effective_translation_locale = $translation_locale !== '' ? $translation_locale : $this->normalize_language_tag($locale);
            $translation_language_name = $effective_translation_locale !== '' ? $this->get_locale_ai_language_name($effective_translation_locale) : $this->get_locale_ai_language_name($locale);

            $wpdb->replace($this->tables['sites'], [
                'blog_id' => $blog_id,
                'lang_slug' => $existing['lang_slug'] ?? $guess['lang_slug'],
                'locale' => $locale,
                'language_name' => $language_name,
                'translation_locale' => $translation_locale,
                'translation_language_name' => $translation_language_name,
                'hreflang' => $existing['hreflang'] ?? $guess['hreflang'],
                'site_url' => get_home_url($blog_id, '/'),
                'site_path' => $path,
                'enabled' => $existing['enabled'] ?? 1,
                'is_source' => $existing['is_source'] ?? $is_source,
                'is_front_default' => $existing['is_front_default'] ?? $is_front,
                'sort_order' => $existing['sort_order'] ?? $blog_id,
            ], ['%d','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%d','%d']);
        }
    }

    private function detect_site_language_defaults($blog_id, $path) {
        $guess = $this->guess_lang_from_path($path);
        $wp_locale = $this->get_site_wp_locale($blog_id);

        // WordPress site Locale is authoritative. The URL path is used only as a convenient
        // site language key; it never selects a hard-coded language profile.
        if ($wp_locale !== '') {
            $guess['locale'] = $wp_locale;
            $guess['language_name'] = $this->get_locale_language_name($wp_locale);
            $guess['hreflang'] = $this->locale_to_hreflang($wp_locale);
            if (empty($guess['lang_slug'])) {
                $guess['lang_slug'] = sanitize_key(strtolower((string)strtok(str_replace('-', '_', $wp_locale), '_')));
            }
        }

        if (empty($guess['lang_slug'])) {
            $guess['lang_slug'] = 'site-' . absint($blog_id);
        }
        $guess['language_name'] = sanitize_text_field((string)($guess['language_name'] ?? ''));
        $guess['hreflang'] = $this->normalize_hreflang((string)($guess['hreflang'] ?? ''));
        return $guess;
    }

    private function get_site_wp_locale($blog_id) {
        $blog_id = absint($blog_id);
        if ($blog_id <= 0) {
            return '';
        }

        // Read the subsite's own Settings > General > Site Language value directly.
        // Do not use get_locale() here because the current network-admin/user locale can
        // differ from the switched subsite's persisted WPLANG option.
        if (function_exists('get_blog_option')) {
            $locale = get_blog_option($blog_id, 'WPLANG', '');
        } else {
            switch_to_blog($blog_id);
            $locale = get_option('WPLANG', '');
            restore_current_blog();
        }

        // WordPress stores English (United States) as an empty WPLANG value.
        if ($locale === '' || $locale === false || $locale === null) {
            $locale = 'en_US';
        }

        return sanitize_text_field((string)$locale);
    }


    /**
     * Resolve a human-readable language name from a WordPress Locale without maintaining
     * a plugin-owned per-language map. Prefer PHP Intl/CLDR when available, then use the
     * official WordPress translation catalog. The Locale itself is the safe final fallback.
     */

    private function get_locale_language_name($locale) {
        $locale = trim((string)$locale);
        if ($locale === '') {
            return '';
        }

        static $name_cache = [];
        if (array_key_exists($locale, $name_cache)) {
            return $name_cache[$locale];
        }

        $names = $this->resolve_locale_language_names($locale);
        $name = $this->simplify_locale_language_name($names['native_name'] !== '' ? $names['native_name'] : ($names['english_name'] !== '' ? $names['english_name'] : $locale));

        $name_cache[$locale] = sanitize_text_field($name);
        return $name_cache[$locale];
    }

    private function get_locale_language_name_legacy($locale) {
        $locale = trim((string)$locale);
        if ($locale === '') {
            return '';
        }

        static $legacy_name_cache = [];
        if (array_key_exists($locale, $legacy_name_cache)) {
            return $legacy_name_cache[$locale];
        }

        $names = $this->resolve_locale_language_names($locale);
        $native_name = $names['native_name'];
        $english_name = $names['english_name'];

        if ($native_name !== '' && $english_name !== '' && strcasecmp($native_name, $english_name) !== 0) {
            $name = $native_name . ' / ' . $english_name;
        } else {
            $name = $english_name !== '' ? $english_name : ($native_name !== '' ? $native_name : $locale);
        }

        $legacy_name_cache[$locale] = sanitize_text_field($name);
        return $legacy_name_cache[$locale];
    }

    private function get_locale_ai_language_name($locale) {
        // AI prompt 继续使用原来的完整名称格式，不受前台“语言名称”短名/自定义影响。
        // 例如：русский (Россия) / Russian (Russia)。
        return $this->get_locale_language_name_legacy($locale);
    }

    private function resolve_locale_language_names($locale) {
        $locale = trim((string)$locale);
        $english_name = '';
        $native_name = '';
        $intl_locale = str_replace('_', '-', $locale);

        if (function_exists('locale_get_display_name')) {
            $english_name = trim((string)locale_get_display_name($intl_locale, 'en'));
            $native_name = trim((string)locale_get_display_name($intl_locale, $intl_locale));
        } elseif (class_exists('Locale') && method_exists('Locale', 'getDisplayName')) {
            $english_name = trim((string)Locale::getDisplayName($intl_locale, 'en'));
            $native_name = trim((string)Locale::getDisplayName($intl_locale, $intl_locale));
        }

        if ($english_name === '' || strcasecmp($english_name, $locale) === 0 || strcasecmp($english_name, $intl_locale) === 0) {
            if (!function_exists('wp_get_available_translations')) {
                $translation_file = ABSPATH . 'wp-admin/includes/translation-install.php';
                if (is_readable($translation_file)) {
                    require_once $translation_file;
                }
            }
            if (function_exists('wp_get_available_translations')) {
                static $translations = null;
                if ($translations === null) {
                    $translations = wp_get_available_translations();
                    if (!is_array($translations)) {
                        $translations = [];
                    }
                }
                if (isset($translations[$locale]) && is_array($translations[$locale])) {
                    $translation = $translations[$locale];
                    $english_name = trim((string)($translation['english_name'] ?? $english_name));
                    $native_name = trim((string)($translation['native_name'] ?? $native_name));
                }
            }
        }

        // WordPress itself treats en_US as its built-in default and does not require a
        // downloadable translation package, so the translation catalog may not contain it.
        if ($locale === 'en_US' && ($english_name === '' || strcasecmp($english_name, $locale) === 0)) {
            $english_name = 'English (United States)';
            if ($native_name === '') {
                $native_name = $english_name;
            }
        }

        return [
            'english_name' => $english_name,
            'native_name' => $native_name,
        ];
    }

    private function simplify_locale_language_name($name) {
        $name = trim((string)$name);
        if ($name === '') {
            return '';
        }

        // Keep the front-end/admin language label short and native-looking:
        // "русский (Россия) / Russian (Russia)" -> "русский".
        $name = preg_split('/\s+\/\s+/', $name, 2)[0];
        $name = preg_replace('/\s*\([^)]*\)\s*/u', ' ', $name);
        $name = preg_replace('/\s*[（][^）]*[）]\s*/u', ' ', $name);
        $name = trim(preg_replace('/\s+/u', ' ', (string)$name));

        return $name;
    }

    private function locale_to_hreflang($locale) {
        $locale = trim((string)$locale);
        if ($locale === '') {
            return '';
        }
        $map = [
            'zh_CN' => 'zh-Hans',
            'zh_SG' => 'zh-Hans',
            'zh_TW' => 'zh-Hant',
            'zh_HK' => 'zh-Hant',
            'zh_MO' => 'zh-Hant',
        ];
        if (isset($map[$locale])) {
            return $map[$locale];
        }
        return $this->normalize_hreflang(str_replace('_', '-', $locale));
    }

    /**
     * Normalize a model-facing BCP 47 language tag. This accepts language, script,
     * country and numeric region subtags, for example en-US, zh-Hant and es-419.
     */

    private function normalize_language_tag($tag) {
        return $this->normalize_hreflang($tag);
    }

    private function normalize_hreflang($hreflang) {
        $hreflang = trim((string)$hreflang);
        if ($hreflang === '') {
            return '';
        }
        $hreflang = str_replace('_', '-', $hreflang);
        if (strtolower($hreflang) === 'zh-hans') {
            return 'zh-Hans';
        }
        if (strtolower($hreflang) === 'zh-hant') {
            return 'zh-Hant';
        }
        $parts = explode('-', $hreflang);
        if (count($parts) === 1) {
            return strtolower($parts[0]);
        }
        $parts[0] = strtolower($parts[0]);
        for ($i = 1; $i < count($parts); $i++) {
            if (strlen($parts[$i]) === 2) {
                $parts[$i] = strtoupper($parts[$i]);
            } elseif (strlen($parts[$i]) === 4) {
                $parts[$i] = ucfirst(strtolower($parts[$i]));
            }
        }
        return implode('-', $parts);
    }

    private function guess_lang_from_path($path) {
        $slug = sanitize_key(trim((string)$path, '/'));
        if ($slug === '') {
            return ['lang_slug' => '', 'locale' => '', 'language_name' => '', 'hreflang' => ''];
        }

        // Generic inference only: keep the path as the site key and normalize it as a
        // possible BCP 47 tag. Do not assign en_US, pt_PT, es_ES, etc. from a PHP list.
        $hreflang = $this->normalize_hreflang(str_replace('_', '-', $slug));
        $locale = '';
        if (preg_match('/^([a-z]{2,3})-([A-Z]{2})$/', $hreflang, $m)) {
            $locale = $m[1] . '_' . $m[2];
        }

        return [
            'lang_slug' => $slug,
            'locale' => $locale,
            'language_name' => $locale !== '' ? $this->get_locale_language_name($locale) : '',
            'hreflang' => $hreflang,
        ];
    }

    public function get_i18n_sites($enabled_only = false) {
        global $wpdb;
        $where = $enabled_only ? 'WHERE enabled = 1' : '';
        return $wpdb->get_results("SELECT * FROM {$this->tables['sites']} $where ORDER BY sort_order ASC, blog_id ASC", ARRAY_A);
    }

    /**
     * Return enabled language sites for switchers outside singular content.
     *
     * Archive and home pages have no post relation to resolve, so their
     * language links intentionally point to each target site's homepage.
     */
    }
}
