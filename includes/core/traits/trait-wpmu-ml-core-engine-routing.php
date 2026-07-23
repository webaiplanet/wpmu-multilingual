<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 翻译引擎注册、规范化与路由。
 *
 * 本 trait 仅用于拆分历史 WPMU_Multilingual 大类；方法签名和可见性保持不变。
 */
if (!trait_exists('WPMU_ML_Core_Engine_Routing_Trait')) {
    trait WPMU_ML_Core_Engine_Routing_Trait {
    private function get_translation_engines() {
        return $this->get_default_translation_engines();
    }

    private function get_default_translation_engines() {
        // 默认翻译引擎只显示真正可用的主翻译入口；OpenCC 不作为默认机器翻译引擎显示。
        $engines = [
            'manual' => '人工翻译',
            'openai' => 'OpenAI 兼容',
        ];
        return $this->append_registered_translation_engines($engines, 'default', '');
    }

    private function get_translation_engines_for_lang($target_lang = '') {
        $target_lang = sanitize_key($target_lang);
        $engines = $this->get_default_translation_engines();
        if ($this->should_offer_opencc_for_target_lang($target_lang)) {
            $engines = array_merge($engines, $this->get_opencc_translation_engines());
        }
        return $this->append_registered_translation_engines($engines, 'language', $target_lang);
    }

    private function append_registered_translation_engines($engines, $context = 'default', $target_lang = '') {
        /**
         * Future engine extension point.
         *
         * Return an associative array such as:
         * [ 'deepl' => 'DeepL', 'custom_x' => 'Custom Engine' ]
         * Only engines registered here are shown in the backend. The built-in UI no longer lists
         * unimplemented DeepL / Tencent / Custom placeholders.
         */
        $registered = apply_filters('wpmu_ml_registered_translation_engines', [], $context, $target_lang);
        if (is_array($registered)) {
            foreach ($registered as $key => $label) {
                $key = sanitize_key($key);
                if ($key === '' || isset($engines[$key]) || $this->is_opencc_engine($key) || $key === 'opencc') {
                    continue;
                }
                $label = is_scalar($label) ? sanitize_text_field((string)$label) : '';
                if ($label !== '') {
                    $engines[$key] = $label;
                }
            }
        }
        return $engines;
    }

    private function get_opencc_translation_engines() {
        return [
            'opencc_s2twp' => '繁体台湾惯用词 s2twp',
            'opencc_s2tw'  => '繁体台湾 s2tw',
            'opencc_s2hk'  => '繁体香港 s2hk',
            'opencc_s2t'   => '繁体通用 s2t',
        ];
    }

    private function is_traditional_chinese_lang($lang) {
        $lang = strtolower(str_replace('_', '-', sanitize_key($lang)));
        if ($lang === '') {
            return false;
        }
        if (in_array($lang, ['zh-hant','zh-tw','zh-hk','zh-mo','zh-my-hant','zh-sg-hant','hant','tw','hk'], true)) {
            return true;
        }
        return (bool)preg_match('/^zh-(hant|tw|hk|mo)(?:-|$)/', $lang);
    }

    private function is_simplified_chinese_lang($lang) {
        $lang = strtolower(str_replace('_', '-', sanitize_key((string)$lang)));
        if ($lang === '') {
            return false;
        }
        if (in_array($lang, ['zh','zh-hans','zh-cn','zh-sg','zh-my','hans','cn','sg'], true)) {
            return true;
        }
        return (bool)preg_match('/^zh-(hans|cn|sg|my)(?:-|$)/', $lang);
    }

    private function source_language_supports_opencc_s2t($settings = null) {
        global $wpdb;
        $settings = is_array($settings) ? $settings : $this->get_settings();
        $source_blog_id = absint($settings['source_blog_id'] ?? 0);
        if (!$source_blog_id) {
            return false;
        }

        $site = $wpdb->get_row($wpdb->prepare(
            "SELECT lang_slug, locale, translation_locale, hreflang FROM {$this->tables['sites']} WHERE blog_id = %d LIMIT 1",
            $source_blog_id
        ), ARRAY_A);

        $candidates = [];
        if (is_array($site)) {
            $candidates[] = (string)($site['translation_locale'] ?? '');
            $candidates[] = (string)($site['locale'] ?? '');
            $candidates[] = (string)($site['hreflang'] ?? '');
            $candidates[] = (string)($site['lang_slug'] ?? '');
        }

        $live_locale = $this->get_site_wp_locale($source_blog_id);
        if ($live_locale !== '') {
            $candidates[] = $live_locale;
            $candidates[] = $this->locale_to_hreflang($live_locale);
        }

        foreach ($candidates as $candidate) {
            if ($this->is_simplified_chinese_lang($candidate)) {
                return true;
            }
        }
        return false;
    }

    private function should_offer_opencc_for_target_lang($target_lang, $settings = null) {
        return $this->is_traditional_chinese_lang($target_lang)
            && $this->source_language_supports_opencc_s2t($settings);
    }

    private function is_opencc_engine($engine) {
        $engine = sanitize_key($engine);
        return strpos($engine, 'opencc_') === 0;
    }

    private function normalize_translation_engine_key($engine, $target_lang = '') {
        $engine = sanitize_key($engine);
        $valid = array_keys($this->get_translation_engines_for_lang($target_lang));
        if (in_array($engine, $valid, true)) {
            return $engine;
        }
        return 'manual';
    }

    private function get_translation_engine_for_lang($target_lang, $fallback = '') {
        $settings = $this->get_settings();
        $target_lang = sanitize_key($target_lang);
        $engines_by_lang = is_array($settings['translation_engines_by_lang']) ? $settings['translation_engines_by_lang'] : [];
        $engine = sanitize_key($engines_by_lang[$target_lang] ?? '');
        if (!$engine) {
            if ($this->should_offer_opencc_for_target_lang($target_lang, $settings)) {
                $engine = $fallback ?: 'opencc_s2twp';
            } else {
                $engine = $fallback ?: $settings['translation_default_engine'];
            }
        }
        return $this->normalize_translation_engine_key($engine, $target_lang);
    }

    private function get_translation_route_key($target_lang, $post_type) {
        return sanitize_key($target_lang) . ':' . sanitize_key($post_type);
    }

    private function normalize_translation_status($status, $fallback = 'pending') {
        $status = sanitize_key((string)$status);
        $fallback = sanitize_key((string)$fallback);
        if (!in_array($fallback, ['draft','pending','publish'], true)) {
            $fallback = 'pending';
        }
        return in_array($status, ['draft','pending','publish'], true) ? $status : $fallback;
    }

    private function resolve_translation_route($target_lang, $post_type = '', $override_engine = '') {
        $settings = $this->get_settings();
        $target_lang = sanitize_key($target_lang);
        $post_type = sanitize_key($post_type);
        $route_key = $this->get_translation_route_key($target_lang, $post_type);

        $default_engine = $this->normalize_translation_engine_key($settings['translation_default_engine'] ?? 'manual', $target_lang);
        if ($default_engine === 'manual' && $this->should_offer_opencc_for_target_lang($target_lang, $settings)) {
            $default_engine = 'opencc_s2twp';
        }
        $default_status = $this->normalize_translation_status($settings['translation_complete_status'] ?? 'pending');
        $default_model = trim((string)($settings['openai_model'] ?? 'gpt-4o-mini'));

        $engine = '';
        $model = '';
        $complete_status = '';
        $reason = 'default_rule';
        $profile = 'default';

        if ($override_engine !== '') {
            $engine = $this->normalize_translation_engine_key($override_engine, $target_lang);
            $reason = 'single_task_override';
            $profile = 'single:' . $engine;
        }

        if ($engine === '') {
            $combo_engines = is_array($settings['translation_engines_by_lang_post_type'] ?? null) ? $settings['translation_engines_by_lang_post_type'] : [];
            $combo_engine = sanitize_key($combo_engines[$route_key] ?? '');
            if ($combo_engine !== '') {
                $engine = $this->normalize_translation_engine_key($combo_engine, $target_lang);
                $reason = 'language_post_type_rule';
                $profile = $route_key;
            }
        }

        if ($engine === '') {
            $lang_engines = is_array($settings['translation_engines_by_lang'] ?? null) ? $settings['translation_engines_by_lang'] : [];
            $lang_engine = sanitize_key($lang_engines[$target_lang] ?? '');
            if ($lang_engine !== '') {
                $engine = $this->normalize_translation_engine_key($lang_engine, $target_lang);
                $reason = 'language_rule';
                $profile = $target_lang;
            }
        }

        if ($engine === '') {
            $pt_engines = is_array($settings['translation_engines_by_post_type'] ?? null) ? $settings['translation_engines_by_post_type'] : [];
            $pt_engine = sanitize_key($pt_engines[$post_type] ?? '');
            if ($pt_engine !== '') {
                $engine = $this->normalize_translation_engine_key($pt_engine, $target_lang);
                $reason = 'post_type_rule';
                $profile = $post_type;
            }
        }

        if ($engine === '') {
            if ($this->should_offer_opencc_for_target_lang($target_lang, $settings)) {
                $engine = 'opencc_s2twp';
                $reason = 'simplified_to_traditional_chinese_fallback';
                $profile = $target_lang;
            } else {
                $engine = $default_engine;
            }
        }

        if (!array_key_exists($engine, $this->get_translation_engines_for_lang($target_lang))) {
            $engine = 'manual';
            $reason = 'invalid_route_fallback';
            $profile = 'manual';
        }

        $combo_status = '';
        $combo_statuses = is_array($settings['translation_status_by_lang_post_type'] ?? null) ? $settings['translation_status_by_lang_post_type'] : [];
        if (isset($combo_statuses[$route_key]) && $combo_statuses[$route_key] !== '') {
            $combo_status = $combo_statuses[$route_key];
        }
        $lang_statuses = is_array($settings['translation_status_by_lang'] ?? null) ? $settings['translation_status_by_lang'] : [];
        $pt_statuses = is_array($settings['translation_status_by_post_type'] ?? null) ? $settings['translation_status_by_post_type'] : [];
        if ($combo_status !== '') {
            $complete_status = $this->normalize_translation_status($combo_status, $default_status);
        } elseif (isset($lang_statuses[$target_lang]) && $lang_statuses[$target_lang] !== '') {
            $complete_status = $this->normalize_translation_status($lang_statuses[$target_lang], $default_status);
        } elseif (isset($pt_statuses[$post_type]) && $pt_statuses[$post_type] !== '') {
            $complete_status = $this->normalize_translation_status($pt_statuses[$post_type], $default_status);
        } else {
            $complete_status = $default_status;
        }

        if ($engine === 'openai') {
            $combo_models = is_array($settings['translation_models_by_lang_post_type'] ?? null) ? $settings['translation_models_by_lang_post_type'] : [];
            $pt_models = is_array($settings['translation_models_by_post_type'] ?? null) ? $settings['translation_models_by_post_type'] : [];
            $lang_profile = $this->get_openai_language_profile($settings, $target_lang);
            if (!empty($combo_models[$route_key])) {
                $model = trim((string)$combo_models[$route_key]);
            } elseif ($lang_profile['model'] !== '') {
                $model = $lang_profile['model'];
            } elseif (!empty($pt_models[$post_type])) {
                $model = trim((string)$pt_models[$post_type]);
            } else {
                $model = $default_model;
            }
        }

        return [
            'engine' => $engine,
            'model' => $engine === 'openai' ? $model : '',
            'complete_status' => $complete_status,
            'route_reason' => $reason,
            'route_profile' => $profile,
        ];
    }

    private function get_translation_complete_status_for_lang($target_lang) {
        $settings = $this->get_settings();
        $target_lang = sanitize_key($target_lang);
        $status_by_lang = is_array($settings['translation_status_by_lang']) ? $settings['translation_status_by_lang'] : [];
        $status = sanitize_key($status_by_lang[$target_lang] ?? $settings['translation_complete_status']);
        return $this->normalize_translation_status($status, 'pending');
    }

    private function get_sync_target_status_for_lang($target_lang) {
        $settings = $this->get_settings();
        $status = sanitize_key($settings['target_default_status'] ?? 'draft');
        return in_array($status, ['draft','pending'], true) ? $status : 'draft';
    }
    }
}
