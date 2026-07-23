<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds structured payloads for the external Agent engine.
 *
 * The Agent receives WordPress fields plus the shared translation-rule bundle. WordPress-specific extraction remains here.
 */
final class WPMU_ML_Agent_Payload {
    private $core;

    public function __construct($core) {
        $this->core = $core;
    }

    public function build($job, $claim_token) {
        $identity = $this->core->validate_translation_job_target($job, true);
        if (empty($identity['valid'])) {
            return new WP_Error(
                'wpmu_ml_agent_target_identity_invalid',
                '目标文章身份校验失败：' . (string)($identity['message'] ?? ''),
                ['status' => 409, 'error_code' => (string)($identity['error_code'] ?? 'relation_invalid')]
            );
        }
        $source_post = null;
        $source_meta = [];
        $source_url = '';

        switch_to_blog((int)$job['source_blog_id']);
        $source_post = get_post((int)$job['source_post_id']);
        if ($source_post) {
            $source_meta = get_post_meta((int)$source_post->ID);
            $source_url = get_permalink((int)$source_post->ID);
        }
        restore_current_blog();

        if (!$source_post) {
            return new WP_Error('wpmu_ml_agent_source_missing', '源文章不存在。', ['status' => 404]);
        }

        $target_url = '';
        switch_to_blog((int)$job['target_blog_id']);
        $target_post = get_post((int)$job['target_post_id']);
        if ($target_post) {
            $target_url = get_permalink((int)$target_post->ID);
        }
        restore_current_blog();

        $settings = $this->core->get_settings();
        $translation_rules = $this->get_shared_translation_rules(
            (string)($job['target_lang'] ?? ''),
            (int)($job['target_blog_id'] ?? 0),
            $settings
        );
        $fields = $this->build_post_fields($source_post);
        $meta_fields = $this->build_meta_fields($source_meta, $settings);
        $fields = array_merge($fields, $meta_fields);

        /**
         * Last-chance extension point for site-specific fields.
         * Keep this as payload shaping only; shared rules are exposed separately in translation_rules.
         */
        $fields = apply_filters('wpmu_ml_agent_payload_fields', $fields, $job, $source_post, $source_meta, $this->core);
        if (!is_array($fields)) {
            $fields = [];
        }
        $fields = array_values($this->normalize_fields($fields));
        if (method_exists($this->core, 'translation_job_is_incremental') && $this->core->translation_job_is_incremental($job)) {
            $allowed_meta_keys = $this->core->translation_job_meta_keys($job);
            $fields = array_values(array_filter($fields, function($field) use ($job, $allowed_meta_keys) {
                if ((string)($field['field_scope'] ?? '') === 'post') {
                    return $this->core->translation_job_selects_core_field($job, (string)($field['field_id'] ?? ''));
                }
                return !is_array($allowed_meta_keys) || in_array((string)($field['meta_key'] ?? ''), $allowed_meta_keys, true);
            }));
        }

        $hash_parts = [];
        foreach ($fields as $field) {
            $hash_parts[] = [
                (string)($field['field_id'] ?? ''),
                (string)($field['source'] ?? ''),
                (string)($field['field_type'] ?? ''),
            ];
        }

        $source_hash = hash('sha256', wp_json_encode([
            'source_blog_id' => (int)$job['source_blog_id'],
            'source_post_id' => (int)$job['source_post_id'],
            'target_blog_id' => (int)$job['target_blog_id'],
            'target_lang' => (string)$job['target_lang'],
            'source_modified_gmt' => (string)$source_post->post_modified_gmt,
            'fields' => $hash_parts,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'ok' => true,
            'api_version' => '1.3',
            'job_id' => (int)$job['id'],
            'claim_token' => (string)$claim_token,
            'source_hash' => $source_hash,
            'job' => [
                'engine' => (string)($job['engine'] ?? 'agent'),
                'model' => (string)($job['model'] ?? ''),
                'route_reason' => (string)($job['route_reason'] ?? ''),
                'route_profile' => (string)($job['route_profile'] ?? ''),
                'complete_status' => !empty($identity['force_draft']) ? 'draft' : (string)($job['complete_status'] ?? ''),
                'job_type' => (string)($job['job_type'] ?? 'full_translate'),
            ],
            'source' => [
                'blog_id' => (int)$job['source_blog_id'],
                'post_id' => (int)$job['source_post_id'],
                'lang' => (string)$job['source_lang'],
                'url' => $source_url,
                'post_type' => (string)$job['post_type'],
                'slug' => (string)$source_post->post_name,
                'modified_gmt' => (string)$source_post->post_modified_gmt,
            ],
            'target' => [
                'blog_id' => (int)$job['target_blog_id'],
                'post_id' => (int)$job['target_post_id'],
                'lang' => (string)$job['target_lang'],
                'url' => $target_url,
            ],
            'contract' => [
                'role' => 'wordpress_translation_tool_payload',
                'agent_writes_wordpress' => false,
                'return_shape' => 'Return the same field_id values with translated target strings only.',
                'follow_translation_rules' => true,
                'preserve_slug' => true,
                'preserve_urls' => true,
                'preserve_shortcodes' => true,
                'preserve_html_tags' => true,
                'preserve_code_structure' => true,
                'do_not_add_facts' => true,
            ],
            'translation_rules' => $translation_rules,
            'fields' => $fields,
        ];
    }

    /**
     * Return the shared translation settings that an external Agent should use.
     * The same site rules and glossary are used by the internal OpenAI-compatible engine,
     * so administrators only maintain them once in the “翻译规则” tab.
     */
    public function get_shared_translation_rules($target_lang = '', $target_blog_id = 0, $settings = null) {
        if (!is_array($settings)) {
            $settings = $this->core->get_settings();
        }

        $target_context = [];
        if (method_exists($this->core, 'get_translation_language_context')) {
            $target_context = $this->core->get_translation_language_context($target_lang, $target_blog_id);
        }
        if (!is_array($target_context)) {
            $target_context = [];
        }
        $target_label = trim((string)($target_context['prompt_label'] ?? $target_lang));

        if (class_exists('WPMU_ML_OpenAI_Helper') && method_exists('WPMU_ML_OpenAI_Helper', 'build_shared_rules_bundle')) {
            $bundle = WPMU_ML_OpenAI_Helper::build_shared_rules_bundle($settings, $target_context, $target_label);
        } else {
            $bundle = [
                'translation_mode' => 'website_localization',
                'translation_style' => 'native_quality',
                'target_language' => $target_context,
                'built_in_rules' => [
                    'Use the explicit AI translation locale when configured; otherwise use the WordPress Locale.',
                    'Produce natural, trustworthy and idiomatic text that reads as if written by a native technical writer.',
                    'Avoid literal translation, Chinese-style phrasing, source-language syntax and machine-translation wording.',
                    'Preserve meaning, facts, brands, numbers, URLs, code and WordPress structure.',
                ],
                'site_rules' => trim((string)($settings['openai_agent_site_rules'] ?? '')),
                'glossary' => [
                    'format' => 'source | language | target',
                    'raw' => trim((string)($settings['openai_agent_terms'] ?? '')),
                    'effective_for_target' => '',
                ],
            ];
        }

        $bundle['excluded_custom_fields'] = [
            'custom_raw' => trim((string)($settings['openai_excluded_meta_keys'] ?? '')),
            'effective_patterns' => $this->get_effective_excluded_meta_key_patterns($settings),
            'note' => 'These meta_key patterns are excluded before Agent payload fields are built.',
        ];
        $bundle['rules_hash'] = hash('sha256', wp_json_encode([
            'translation_style' => $bundle['translation_style'] ?? '',
            'target_language' => $bundle['target_language'] ?? [],
            'built_in_rules' => $bundle['built_in_rules'] ?? [],
            'site_rules' => $bundle['site_rules'] ?? '',
            'glossary' => $bundle['glossary'] ?? [],
            'html_exclusions' => $bundle['html_exclusions'] ?? [],
            'excluded_custom_fields' => $bundle['excluded_custom_fields'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return apply_filters(
            'wpmu_ml_agent_translation_rules',
            $bundle,
            (string)$target_lang,
            (int)$target_blog_id,
            $settings,
            $this->core
        );
    }

    public function get_effective_excluded_meta_key_patterns($settings = null) {
        if (!is_array($settings)) {
            $settings = $this->core->get_settings();
        }
        $patterns = $this->default_excluded_meta_key_patterns();
        $raw = (string)($settings['openai_excluded_meta_keys'] ?? '');
        if ($raw !== '') {
            $extra = preg_split('/\r\n|\r|\n|,/', $raw);
            foreach ((array)$extra as $pattern) {
                $pattern = trim((string)$pattern);
                if ($pattern !== '' && strpos($pattern, '#') !== 0) {
                    $patterns[] = $pattern;
                }
            }
        }
        return array_values(array_unique($patterns));
    }

    private function build_post_fields($source_post) {
        $post_content = $this->sanitize_translation_source((string)$source_post->post_content);
        return [
            [
                'field_id' => 'post_title',
                'field_scope' => 'post',
                'field_type' => 'title',
                'format' => 'plain_text',
                'source' => (string)$source_post->post_title,
                'translatable' => true,
                'required' => true,
            ],
            [
                'field_id' => 'post_excerpt',
                'field_scope' => 'post',
                'field_type' => 'excerpt',
                'format' => 'plain_text',
                'source' => (string)$source_post->post_excerpt,
                'translatable' => true,
                'required' => false,
            ],
            [
                'field_id' => 'post_content',
                'field_scope' => 'post',
                'field_type' => 'content',
                'format' => 'wp_post_content',
                'source' => $post_content,
                'translatable' => true,
                'required' => true,
                'preserve_wp_blocks' => true,
                'preserve_shortcodes' => true,
                'preserve_html_tags' => true,
            ],
        ];
    }

    private function build_meta_fields($source_meta, $settings) {
        $fields = [];
        if (empty($settings['openai_translate_meta']) || !is_array($source_meta) || empty($source_meta)) {
            return $fields;
        }

        foreach ($source_meta as $meta_key => $values) {
            $meta_key = (string)$meta_key;
            if ($this->should_skip_meta_key($meta_key, $settings)) {
                continue;
            }
            foreach ((array)$values as $index => $raw_value) {
                $decoded = maybe_unserialize($raw_value);
                $this->append_meta_value_fields($fields, $meta_key, (int)$index, $decoded, [], $settings);
            }
        }
        return $fields;
    }

    private function append_meta_value_fields(&$fields, $meta_key, $meta_index, $value, $path, $settings) {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_string($k) && $this->should_skip_data_key($k, $settings)) {
                    continue;
                }
                $next_path = $path;
                $next_path[] = $k;
                $this->append_meta_value_fields($fields, $meta_key, $meta_index, $v, $next_path, $settings);
            }
            return;
        }

        if (is_object($value)) {
            foreach ($value as $k => $v) {
                if (is_string($k) && $this->should_skip_data_key($k, $settings)) {
                    continue;
                }
                $next_path = $path;
                $next_path[] = $k;
                $this->append_meta_value_fields($fields, $meta_key, $meta_index, $v, $next_path, $settings);
            }
            return;
        }

        if (!is_string($value)) {
            return;
        }

        $value = $this->sanitize_translation_source((string)$value);
        if (!$this->should_translate_meta_string($value)) {
            return;
        }

        $field_type = $this->classify_field_type($meta_key, $path);
        $format = $this->detect_format($value, $meta_key, $path);
        $path_json = wp_json_encode($path, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $field_id = 'meta:' . substr(sha1($meta_key . '|' . $meta_index . '|' . $path_json), 0, 20);

        $fields[] = [
            'field_id' => $field_id,
            'field_scope' => 'meta',
            'field_type' => $field_type,
            'format' => $format,
            'source' => $value,
            'translatable' => true,
            'required' => false,
            'meta_key' => $meta_key,
            'meta_index' => $meta_index,
            'value_path' => $path,
        ];
    }

    private function sanitize_translation_source($value) {
        $value = (string)$value;
        if ($value === '' || !class_exists('WPMU_ML_Content_Sanitizer')) {
            return $value;
        }
        if (method_exists('WPMU_ML_Content_Sanitizer', 'strip_translation_artifacts')) {
            return (string)WPMU_ML_Content_Sanitizer::strip_translation_artifacts($value);
        }
        return (string)WPMU_ML_Content_Sanitizer::strip_immersive_translate_artifacts($value);
    }

    private function normalize_fields($fields) {
        $out = [];
        $seen = [];
        foreach ((array)$fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $field_id = sanitize_text_field((string)($field['field_id'] ?? ''));
            if ($field_id === '' || isset($seen[$field_id])) {
                continue;
            }
            $source = isset($field['source']) ? (string)$field['source'] : '';
            $field['field_id'] = $field_id;
            $field['source'] = $source;
            $field['translatable'] = array_key_exists('translatable', $field) ? (bool)$field['translatable'] : true;
            $out[] = $field;
            $seen[$field_id] = true;
        }
        return $out;
    }

    private function default_excluded_meta_key_patterns() {
        return [
            '_ai_generated_seo',
            '_ai_generated_summary',
            '_ai_seo_auto_generated',
            '_deepseek_slug_generated',
            '_deepseek_slug_last_value',
            '_auto_internal_links_done',
            '_mr_reactions_processed',
            '_initial_views_processed',
            'post_views',
            'utv_post_views',
            'views',
        ];
    }

    private function meta_key_matches_pattern($meta_key, $pattern) {
        $meta_key = (string)$meta_key;
        $pattern = trim((string)$pattern);
        if ($pattern === '') {
            return false;
        }
        if ($meta_key === $pattern) {
            return true;
        }
        if (strpos($pattern, '*') !== false) {
            $regex = '/^' . str_replace('\\*', '.*', preg_quote($pattern, '/')) . '$/i';
            return (bool)preg_match($regex, $meta_key);
        }
        return false;
    }

    private function meta_key_is_excluded_by_setting($meta_key, $settings) {
        foreach ($this->get_effective_excluded_meta_key_patterns($settings) as $pattern) {
            if ($this->meta_key_matches_pattern($meta_key, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function should_skip_meta_key($meta_key, $settings) {
        $skip_exact = [
            '_edit_lock', '_edit_last', '_thumbnail_id', '_wp_attached_file', '_wp_attachment_metadata',
            '_wp_page_template', '_wp_old_slug', '_menu_item_type', '_menu_item_menu_item_parent',
            '_menu_item_object_id', '_menu_item_object', '_menu_item_target', '_menu_item_classes',
            '_menu_item_xfn', '_menu_item_url', '_wp_trash_meta_status', '_wp_trash_meta_time',
            '_acf_changed', '_wpml_media_featured', '_wpml_media_duplicate',
        ];
        if (in_array($meta_key, $skip_exact, true)) {
            return true;
        }
        if ($this->meta_key_is_excluded_by_setting($meta_key, $settings)) {
            return true;
        }

        $seo_keys = $this->seo_meta_keys();
        if (isset($seo_keys[$meta_key])) {
            return empty($settings['openai_translate_seo_meta']);
        }

        // ACF reference rows such as _field_name = field_xxx are structural and should not be translated.
        if (strpos($meta_key, '_') === 0) {
            return true;
        }

        if (preg_match('/(^|_)(slug|url|uri|link|permalink|canonical|path|file|image|img|attachment|media|id|ids|uuid|hash|token|nonce|code|ref|key)(_|$)/i', $meta_key)) {
            return true;
        }
        return false;
    }

    private function should_skip_data_key($key, $settings) {
        $key = (string)$key;
        if ($key === '') {
            return false;
        }
        if (strpos($key, '_') === 0) {
            return true;
        }
        $lower = strtolower($key);
        $skip_exact = [
            'id', 'ids', 'uuid', 'uid', 'key', 'ref', 'hash', 'token', 'nonce', 'code',
            'url', 'uri', 'link', 'href', 'src', 'path', 'slug', 'permalink', 'canonical',
            'file', 'files', 'image', 'images', 'img', 'icon', 'icons', 'attachment', 'attachments', 'media',
            'class', 'classname', 'style', 'css', 'align', 'anchor', 'mode', 'name', 'namespace',
            'backgroundcolor', 'textcolor', 'gradient', 'fontsize', 'lock', 'supports',
        ];
        if (in_array($lower, $skip_exact, true)) {
            return true;
        }
        if (preg_match('/(^|_)(slug|url|uri|link|href|src|permalink|canonical|path|file|image|images|img|icon|attachment|media|id|ids|uuid|hash|token|nonce|code|ref|key)(_|$)/i', $key)) {
            return true;
        }
        return false;
    }

    private function should_translate_meta_string($value) {
        $trim = trim((string)$value);
        if ($trim === '' || !$this->contains_cjk_text($trim)) {
            return false;
        }
        if (is_numeric($trim)) {
            return false;
        }
        if (preg_match('~^(https?:)?//|^mailto:|^tel:~i', $trim)) {
            return false;
        }
        if (preg_match('/^[a-z0-9_\-\.\/\#\?\=&:%]+$/i', $trim)) {
            return false;
        }
        return true;
    }

    private function detect_format($value, $meta_key, $path) {
        $value = trim((string)$value);
        $key = strtolower((string)$meta_key . ' ' . implode(' ', array_map('strval', (array)$path)));
        if ($value !== '' && (($value[0] === '{' && substr($value, -1) === '}') || ($value[0] === '[' && substr($value, -1) === ']')) && is_array(json_decode($value, true))) {
            return 'json_string';
        }
        if (strpos($value, '&lt;') !== false && strpos($value, '&gt;') !== false) {
            return 'html_entity_fragment';
        }
        if (strpos($value, '<') !== false && strpos($value, '>') !== false) {
            return 'html_fragment';
        }
        if (preg_match('/(^|\n)\s*(?:\$[A-Za-z_][A-Za-z0-9_]*\s*=|(?:const|let|var)\s+[A-Za-z_][A-Za-z0-9_]*\s*=|function\s+[A-Za-z_]|class\s+[A-Za-z_]|#\s*[^\n]*\p{Han}|\/\/\s*[^\n]*\p{Han}|--\s*[^\n]*\p{Han}|<\?php|SELECT\s+|CREATE\s+TABLE)/iu', $value) || (strpos($value, "\n") !== false && preg_match('/[;{}()=]|=>|->|::|\$[A-Za-z_]/', $value))) {
            return 'code_text';
        }
        if (preg_match('/(seo|meta|og|twitter|description|desc|excerpt|summary|content|html|text|title|heading|caption|label|button)/i', $key)) {
            return 'plain_text';
        }
        return 'plain_text';
    }

    private function classify_field_type($meta_key, $path) {
        $last = '';
        if (!empty($path)) {
            $last = strtolower((string)end($path));
        }
        $key = strtolower(trim($last !== '' ? $last : (string)$meta_key));
        if ($key === '') {
            return 'meta_text';
        }

        if (isset($this->seo_meta_keys()[$meta_key])) {
            return $this->seo_meta_keys()[$meta_key];
        }
        if (preg_match('/(^|_)(seo|meta|og|twitter)_(title)$/', $key) || preg_match('/(^|_)(title)$/', $key)) {
            return 'seo_title';
        }
        if (preg_match('/(^|_)(seo|meta|og|twitter)_(description|desc|metadesc)$/', $key) || preg_match('/(^|_)(description|desc|summary|excerpt|intro)$/', $key)) {
            return 'seo_description';
        }
        if (preg_match('/(^|_)(focus_)?keywords?$/', $key)) {
            return 'seo_keywords';
        }
        if (preg_match('/(^|_)(heading|headline|subheading|sub_title|subtitle)$/', $key)) {
            return 'heading';
        }
        if (preg_match('/(^|_)(title|name|label|button_text|button|cta|caption)$/', $key)) {
            return 'short_ui';
        }
        if (preg_match('/(^|_)(description|desc|summary|excerpt|intro|content|text)$/', $key)) {
            return 'description';
        }
        return 'meta_text';
    }

    private function seo_meta_keys() {
        return [
            '_yoast_wpseo_title' => 'seo_title',
            '_yoast_wpseo_metadesc' => 'seo_description',
            '_yoast_wpseo_focuskw' => 'seo_keywords',
            '_aioseo_title' => 'seo_title',
            '_aioseo_description' => 'seo_description',
            '_aioseo_keywords' => 'seo_keywords',
            '_aioseo_og_title' => 'seo_title',
            '_aioseo_og_description' => 'seo_description',
            '_aioseo_twitter_title' => 'seo_title',
            '_aioseo_twitter_description' => 'seo_description',
            'rank_math_title' => 'seo_title',
            'rank_math_description' => 'seo_description',
            'rank_math_focus_keyword' => 'seo_keywords',
            'rank_math_facebook_title' => 'seo_title',
            'rank_math_facebook_description' => 'seo_description',
            'rank_math_twitter_title' => 'seo_title',
            'rank_math_twitter_description' => 'seo_description',
        ];
    }

    private function contains_cjk_text($text) {
        return is_string($text) && preg_match('/\p{Han}/u', $text);
    }
}
