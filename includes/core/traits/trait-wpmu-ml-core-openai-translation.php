<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI 主翻译流程、正文规划与残留修复。
 *
 * 本 trait 仅用于拆分历史 WPMU_Multilingual 大类；方法签名和可见性保持不变。
 */
if (!trait_exists('WPMU_ML_Core_OpenAI_Translation_Trait')) {
    trait WPMU_ML_Core_OpenAI_Translation_Trait {
    private function get_openai_language_profile($settings, $target_lang = '') {
        $target_lang = sanitize_key((string)$target_lang);
        $profiles = is_array($settings['openai_language_settings'] ?? null)
            ? $settings['openai_language_settings']
            : [];
        $profile = isset($profiles[$target_lang]) && is_array($profiles[$target_lang])
            ? $profiles[$target_lang]
            : [];

        return [
            'model' => trim((string)($profile['model'] ?? '')),
            'temperature' => trim((string)($profile['temperature'] ?? '')),
            'prompt' => trim((string)($profile['prompt'] ?? '')),
        ];
    }

    private function apply_openai_language_profile($settings, $target_lang = '') {
        $profile = $this->get_openai_language_profile($settings, $target_lang);

        if ($profile['temperature'] !== '' && is_numeric($profile['temperature'])) {
            $settings['openai_temperature'] = (string)max(0, min(2, (float)$profile['temperature']));
        }
        $settings['openai_language_prompt'] = $profile['prompt'];

        return $settings;
    }

    /**
     * Build deterministic article context from source content only.
     *
     * 0.9.7.0 deliberately removes the extra AI planning call. The previous planner could
     * translate its own machine labels (TOPIC/AUDIENCE/STYLE), causing a successful API
     * response to be discarded by the parser. Source title, excerpt, headings and recurring
     * source phrases are enough to give every translation batch stable article context.
     * The recurring concepts are advisory source strings only; PHP never proposes or applies
     * a target-language replacement.
     */
    private function openai_article_context_utf8_substr($value, $start, $length = null) {
        $value = (string)$value;
        if (function_exists('mb_substr')) {
            return $length === null
                ? mb_substr($value, (int)$start, null, 'UTF-8')
                : mb_substr($value, (int)$start, (int)$length, 'UTF-8');
        }
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($chars)) {
            return $length === null ? substr($value, (int)$start) : substr($value, (int)$start, (int)$length);
        }
        $slice = $length === null
            ? array_slice($chars, (int)$start)
            : array_slice($chars, (int)$start, (int)$length);
        return implode('', $slice);
    }

    private function openai_article_context_plain_text($value, $limit = 0) {
        $value = html_entity_decode(wp_strip_all_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\s\x{00A0}]+/u', ' ', trim((string)$value));
        if (!is_string($value)) {
            return '';
        }
        $limit = max(0, (int)$limit);
        if ($limit > 0 && $this->wpmu_ml_strlen($value) > $limit) {
            $value = rtrim($this->openai_article_context_utf8_substr($value, 0, $limit));
        }
        return $value;
    }

    private function openai_article_source_headings($content, $limit = 12) {
        $content = (string)$content;
        $limit = max(1, min(30, (int)$limit));
        $headings = [];
        if (preg_match_all('/<h([1-4])\b[^>]*>([\s\S]*?)<\/h\1>/iu', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $heading = $this->openai_article_context_plain_text((string)($match[2] ?? ''), 150);
                if ($heading === '' || isset($headings[$heading])) {
                    continue;
                }
                $headings[$heading] = 1;
                if (count($headings) >= $limit) {
                    break;
                }
            }
        }
        return array_keys($headings);
    }

    private function openai_article_recurring_source_concepts($texts, $priority_texts = [], $limit = 12) {
        $texts = array_values(array_filter(array_map('strval', (array)$texts), static function($value) {
            return trim((string)$value) !== '';
        }));
        $priority_texts = array_values(array_filter(array_map('strval', (array)$priority_texts), static function($value) {
            return trim((string)$value) !== '';
        }));
        $limit = max(0, min(20, (int)$limit));
        if (!$texts || $limit === 0) {
            return [];
        }

        $stats = [];
        foreach ($texts as $doc_index => $text) {
            $seen_in_doc = [];
            if (preg_match_all('/[\x{3400}-\x{9FFF}]{2,18}/u', (string)$text, $runs)) {
                foreach ((array)($runs[0] ?? []) as $run) {
                    $run_chars = preg_split('//u', (string)$run, -1, PREG_SPLIT_NO_EMPTY);
                    if (!is_array($run_chars)) {
                        continue;
                    }
                    $run_length = count($run_chars);
                    $max_n = min(5, $run_length);
                    for ($n = 2; $n <= $max_n; $n++) {
                        for ($offset = 0; $offset <= $run_length - $n; $offset++) {
                            $gram = implode('', array_slice($run_chars, $offset, $n));
                            if ($gram === '') {
                                continue;
                            }
                            if (!isset($stats[$gram])) {
                                $stats[$gram] = [
                                    'total' => 0,
                                    'docs' => 0,
                                    'priority' => 0,
                                    'length' => $n,
                                    'doc_keys' => [],
                                    'left' => [],
                                    'right' => [],
                                ];
                            }
                            $stats[$gram]['total']++;
                            $left = $offset > 0 ? (string)$run_chars[$offset - 1] : '^';
                            $right_index = $offset + $n;
                            $right = $right_index < $run_length ? (string)$run_chars[$right_index] : '$';
                            $stats[$gram]['left'][$left] = 1;
                            $stats[$gram]['right'][$right] = 1;
                            $seen_in_doc[$gram] = 1;
                        }
                    }
                }
            }
            foreach (array_keys($seen_in_doc) as $gram) {
                $stats[$gram]['docs']++;
                $stats[$gram]['doc_keys'][(string)$doc_index] = 1;
            }
        }

        foreach ($priority_texts as $priority_text) {
            foreach ($stats as $gram => &$row) {
                if (strpos((string)$priority_text, (string)$gram) !== false) {
                    $row['priority']++;
                }
            }
            unset($row);
        }

        $ranked = [];
        foreach ($stats as $gram => $row) {
            $docs = (int)($row['docs'] ?? 0);
            $total = (int)($row['total'] ?? 0);
            $length = (int)($row['length'] ?? 0);
            $priority = (int)($row['priority'] ?? 0);
            $left_diversity = count((array)($row['left'] ?? []));
            $right_diversity = count((array)($row['right'] ?? []));
            if ($docs < 2 || $total < 2) {
                continue;
            }
            // Very broad two-character fragments are normally generic. Priority source areas may
            // retain them because compact product terms often have only two source characters.
            if ($length === 2 && $docs > 18 && $priority === 0) {
                continue;
            }
            // Long fragments repeated only inside one fixed sentence tend to be clause shards.
            // Boundary diversity is a language-neutral way to down-rank those accidental n-grams.
            if ($length >= 4 && $docs <= 3 && $priority === 0
                && $left_diversity <= 1 && $right_diversity <= 1) {
                continue;
            }
            $boundary_score = min(4, $left_diversity) * 120 + min(4, $right_diversity) * 120;
            $length_score = $length * 170;
            $coverage_score = min(16, $docs) * 70 + min(20, $total) * 8;
            $priority_score = min(4, $priority) * 1000;
            $generic_penalty = ($length === 2 && $docs > 12) ? min(1500, ($docs - 12) * 100) : 0;
            $ranked[$gram] = $boundary_score + $length_score + $coverage_score + $priority_score - $generic_penalty;
        }
        arsort($ranked, SORT_NUMERIC);

        $selected = [];
        foreach ($ranked as $gram => $score) {
            $row = $stats[$gram];
            $current_docs = array_keys((array)($row['doc_keys'] ?? []));
            $redundant = false;
            foreach ($selected as $existing => $existing_row) {
                if (strpos((string)$existing, (string)$gram) === false
                    && strpos((string)$gram, (string)$existing) === false) {
                    continue;
                }
                $existing_docs = array_keys((array)($existing_row['doc_keys'] ?? []));
                $union = array_unique(array_merge($current_docs, $existing_docs));
                $intersection = array_intersect($current_docs, $existing_docs);
                $similarity = $union ? count($intersection) / count($union) : 0;
                if ($similarity >= 0.85) {
                    $redundant = true;
                    break;
                }
            }
            if ($redundant) {
                continue;
            }
            $selected[(string)$gram] = $row;
            if (count($selected) >= $limit) {
                break;
            }
        }
        return array_keys($selected);
    }

    private function openai_build_article_terminology_context($source_post, $target_label, $settings) {
        if (!is_object($source_post)) {
            return '';
        }

        $source_title = $this->openai_article_context_plain_text((string)($source_post->post_title ?? ''), 220);
        $source_excerpt = $this->openai_article_context_plain_text((string)($source_post->post_excerpt ?? ''), 420);
        $content = (string)($source_post->post_content ?? '');
        $headings = $this->openai_article_source_headings($content, 12);

        $body_fragments = [];
        if (method_exists($this, 'openai_build_coherent_body_plan')) {
            $body_plan = $this->openai_build_coherent_body_plan($content);
            $body_chars = 0;
            foreach ((array)($body_plan['fragments'] ?? []) as $fragment) {
                $plain_fragment = $this->openai_article_fragment_plain_text((string)$fragment);
                if ($plain_fragment === '') {
                    continue;
                }
                $body_fragments[] = $plain_fragment;
                $body_chars += $this->wpmu_ml_strlen($plain_fragment);
                if (count($body_fragments) >= 320 || $body_chars >= 14000) {
                    break;
                }
            }
        }
        if (!$body_fragments) {
            $body = preg_replace('/<!--\s*\/?wp:[\s\S]*?-->/u', ' ', $content);
            $body = preg_replace('/\[[A-Za-z0-9_-]+[^\]]*\]/u', ' ', (string)$body);
            $body = $this->openai_article_context_plain_text((string)$body, 9000);
            if ($body !== '') {
                $body_fragments[] = $body;
            }
        }
        $concept_texts = array_merge([$source_title, $source_excerpt], $headings, $body_fragments);
        $priority_texts = array_merge([$source_title, $source_excerpt], $headings);
        $concepts = $this->openai_article_recurring_source_concepts($concept_texts, $priority_texts, 18);

        $lines = [];
        if ($source_title !== '') {
            $lines[] = 'SOURCE TITLE: ' . $source_title;
        }
        if ($source_excerpt !== '') {
            $lines[] = 'SOURCE EXCERPT: ' . $source_excerpt;
        }
        if ($headings) {
            $lines[] = 'SOURCE SECTION HEADINGS: ' . implode(' | ', $headings);
        }
        if ($concepts) {
            $lines[] = 'SOURCE REVIEW FRAGMENTS: ' . implode(' | ', $concepts);
        }
        $context = trim(implode("\n", $lines));
        if ($context === '') {
            $this->openai_cli_trace_line('ARTICLE SOURCE CONTEXT status=empty generator=php');
            return '';
        }
        if ($this->wpmu_ml_strlen($context) > 1800) {
            $context = rtrim($this->openai_article_context_utf8_substr($context, 0, 1800));
        }
        $this->openai_cli_trace_line(sprintf(
            'ARTICLE SOURCE CONTEXT status=ready generator=php chars=%d headings=%d fragments=%d sha256=%s',
            $this->wpmu_ml_strlen($context),
            count($headings),
            count($concepts),
            substr(hash('sha256', $context), 0, 16)
        ));
        return $context;
    }
    private function process_openai_translation_job($job, $engine = 'openai') {
        global $wpdb;
        $job_id = (int)$job['id'];
        $attempts = ((int)$job['attempts']) + 1;
        $this->openai_quality_runtime_reset();
        $this->openai_performance_runtime_reset();
        $settings = $this->get_settings();
        $job_model = trim((string)($job['model'] ?? ''));

        $api_key = trim((string)($settings['openai_api_key'] ?? ''));
        if ($api_key === '') {
            return $this->fail_translation_job($job, $attempts, 'OpenAI 兼容 API Key 为空，请先在“翻译引擎”设置中填写。');
        }

        $source_post = null;
        switch_to_blog((int)$job['source_blog_id']);
        $source_post = get_post((int)$job['source_post_id']);
        restore_current_blog();

        if (!$source_post) {
            return $this->fail_translation_job($job, $attempts, '源文章不存在。');
        }

        $target_post_before = null;
        switch_to_blog((int)$job['target_blog_id']);
        $target_post_before = get_post((int)$job['target_post_id']);
        restore_current_blog();
        $previous_target_title = $target_post_before ? (string)$target_post_before->post_title : '';
        $previous_target_excerpt = $target_post_before ? (string)$target_post_before->post_excerpt : '';
        $previous_target_content = $target_post_before ? (string)$target_post_before->post_content : '';
        $translate_title = $this->translation_job_selects_core_field($job, 'post_title');
        $translate_excerpt = $this->translation_job_selects_core_field($job, 'post_excerpt');
        $translate_content = $this->translation_job_selects_core_field($job, 'post_content');

        $slug_policy = $this->get_translation_job_slug_policy($job);
        if (is_wp_error($slug_policy)) {
            return $this->fail_translation_job($job, $attempts, '目标文章策略校验失败：' . $slug_policy->get_error_message());
        }
        // 正常关系使用源 slug；slug 冲突待复核关系继续使用带源 ID 后缀的 fallback slug。
        $source_post_name = (string)$slug_policy['target_slug'];

        // v0.8.17.10: build language context from the configured target/source site without a
        // hard-coded language map. An explicit AI translation tag (for example es-419) is authoritative
        // for translated content; WordPress Locale and hreflang keep their separate UI/SEO roles.
        $target_context = $this->get_language_prompt_context($job['target_lang'], (int)$job['target_blog_id']);
        $source_context = $this->get_language_prompt_context('', (int)$job['source_blog_id']);
        $settings['openai_target_language_context'] = $target_context;
        $settings['openai_source_language_context'] = $source_context;
        $profile_lang = sanitize_key((string)($target_context['lang_slug'] ?? $job['target_lang']));
        $settings = $this->apply_openai_language_profile($settings, $profile_lang);
        if ($job_model !== '') {
            // Route-level model selection has higher priority than the language profile.
            $settings['openai_model'] = $job_model;
        }
        $target_label = (string)$target_context['prompt_label'];
        $max_chars = absint($settings['openai_max_chars'] ?? 8000);

        // 0.9.7.0: build deterministic source-only article context. No extra AI planning call,
        // no generated target terminology, and no parser failure mode.
        $article_terminology_context = $this->openai_build_article_terminology_context($source_post, $target_label, $settings);
        if ($article_terminology_context !== '') {
            $settings['openai_article_terminology_context'] = $article_terminology_context;
        }

        $title_excerpt_fields = [];
        if ($translate_title) {
            $title_excerpt_fields['title'] = (string)$source_post->post_title;
        }
        if ($translate_excerpt) {
            $title_excerpt_fields['excerpt'] = (string)$source_post->post_excerpt;
        }
        $translated_title_excerpt = ['title' => $previous_target_title, 'excerpt' => $previous_target_excerpt];
        if ($title_excerpt_fields) {
            $translated_selected = $this->openai_request_fields_with_recursive_split(
                $title_excerpt_fields,
                $target_label,
                $settings,
                'Translate only the supplied changed title/excerpt fields for publication in the configured target locale. Keep search intent, meaning, facts, numbers and verified names. Return only the supplied JSON keys; never generate or translate a slug.',
                0,
                'TITLE TRANSLATION'
            );
            if (is_wp_error($translated_selected)) {
                return $this->fail_translation_job($job, $attempts, '标题/摘要翻译失败：' . $translated_selected->get_error_message());
            }
            $translated_selected = $this->openai_polish_title_excerpt_for_target(
                $title_excerpt_fields,
                $translated_selected,
                $target_label,
                $settings
            );
            if (is_wp_error($translated_selected)) {
                return $this->fail_translation_job($job, $attempts, '标题/摘要目标语言质检失败：' . $translated_selected->get_error_message());
            }
            $translated_title_excerpt = array_merge($translated_title_excerpt, $translated_selected);
        }

        // Give the body translator article-level context so ordered translation blocks
        // read like one continuous local article rather than unrelated strings.
        $settings['openai_article_context'] = [
            'source_title' => (string)$source_post->post_title,
            'target_title' => isset($translated_title_excerpt['title']) ? (string)$translated_title_excerpt['title'] : '',
            'source_excerpt' => (string)$source_post->post_excerpt,
            'target_excerpt' => isset($translated_title_excerpt['excerpt']) ? (string)$translated_title_excerpt['excerpt'] : '',
            'terminology_context' => (string)($settings['openai_article_terminology_context'] ?? ''),
        ];

        $source_content = (string)$source_post->post_content;
        $translated_content = $translate_content
            ? $this->openai_translate_wp_content(
                $source_content,
                $target_label,
                $settings,
                'Translate only human-readable WordPress content. Preserve protected structure, markup, URLs, identifiers and code tokens exactly; translate natural-language comments or string values only when the code translator exposes them. Return no commentary.'
            )
            : $previous_target_content;

        if (is_wp_error($translated_content)) {
            return $this->fail_translation_job($job, $attempts, '正文翻译失败：' . $translated_content->get_error_message());
        }

        if ($title_excerpt_fields && !$this->openai_quality_scope_processed('article')) {
            $fallback_source = $title_excerpt_fields;
            $fallback_target = [];
            foreach ($title_excerpt_fields as $key => $value) {
                $fallback_target[$key] = (string)($translated_title_excerpt[$key] ?? $value);
            }
            $fallback_reviewed = $this->openai_central_quality_review_fields(
                $fallback_source,
                $fallback_target,
                ['title' => 'title', 'excerpt' => 'summary'],
                $target_label,
                $settings,
                'title_excerpt'
            );
            if (is_wp_error($fallback_reviewed)) {
                return $this->fail_translation_job($job, $attempts, '标题/摘要集中质检失败：' . $fallback_reviewed->get_error_message());
            }
            if (isset($fallback_reviewed['title'])) {
                $this->openai_quality_runtime['field_overrides']['title'] = (string)$fallback_reviewed['title'];
            }
            if (isset($fallback_reviewed['excerpt'])) {
                $this->openai_quality_runtime['field_overrides']['excerpt'] = (string)$fallback_reviewed['excerpt'];
            }
        }

        $new_title = $this->openai_quality_field_override('title', isset($translated_title_excerpt['title']) ? (string)$translated_title_excerpt['title'] : (string)$source_post->post_title);
        $new_excerpt = $this->openai_quality_field_override('excerpt', isset($translated_title_excerpt['excerpt']) ? (string)$translated_title_excerpt['excerpt'] : (string)$source_post->post_excerpt);
        $new_content = (string)$translated_content;

        // PHP local integrity checks are mandatory and independent of the AI QA switch.
        $php_integrity_result = $translate_content && class_exists('WPMU_ML_OpenAI_Helper')
            ? WPMU_ML_OpenAI_Helper::quality_check((string)$source_post->post_content, $new_content, (string)$job['target_lang'], $settings)
            : ['ok' => true, 'issues' => []];
        if (is_array($php_integrity_result) && empty($php_integrity_result['ok'])) {
            return $this->fail_translation_job($job, $attempts, 'PHP 本地完整性检查未通过：' . implode('；', array_slice((array)($php_integrity_result['issues'] ?? []), 0, 8)));
        }

        $complete_status = $this->normalize_translation_status($job['complete_status'] ?? '', $this->get_translation_complete_status_for_lang($job['target_lang']));
        $complete_status = $this->enforce_translation_target_status($job, $complete_status);
        $qa_mode_for_staging = $this->openai_quality_coverage_mode($settings);
        $draft_on_incomplete_for_staging = !array_key_exists('openai_qa_draft_on_incomplete', (array)$settings)
            || !empty($settings['openai_qa_draft_on_incomplete']);
        $strict_qa_for_staging = !array_key_exists('openai_qa_strict_status', (array)$settings)
            || !empty($settings['openai_qa_strict_status']);
        $must_stage_for_qa = $complete_status === 'publish'
            && (!empty($settings['openai_agent_fail_on_qa'])
                || $draft_on_incomplete_for_staging
                || ($strict_qa_for_staging && $qa_mode_for_staging !== 'off'));
        $write_status = $must_stage_for_qa ? 'draft' : $complete_status;
        if ($write_status !== $complete_status) {
            $this->openai_cli_trace_line('QA STAGING target_status=' . $write_status . ' final_status=' . $complete_status . ' mode=' . $qa_mode_for_staging);
        }

        if ($source_post_name !== '') {
            $slug_validation = $this->validate_target_slug_availability(
                (int)$job['target_blog_id'],
                (int)$job['target_post_id'],
                $source_post_name,
                (string)$source_post->post_type
            );
            if (is_wp_error($slug_validation)) {
                return $this->fail_translation_job($job, $attempts, '目标文章 slug 冲突：' . $slug_validation->get_error_message());
            }
        }

        switch_to_blog((int)$job['target_blog_id']);
        $post_update = [
            'ID' => (int)$job['target_post_id'],
            'post_status' => $write_status,
        ];
        if ($translate_title) {
            $post_update['post_title'] = $new_title;
        }
        if ($translate_excerpt) {
            $post_update['post_excerpt'] = $new_excerpt;
        }
        if ($translate_content) {
            $post_update['post_content'] = $new_content;
        }
        // 目标文章 slug 绝对不翻译：始终回写源站 slug，保护已收录 URL。
        if ($source_post_name !== '') {
            $post_update['post_name'] = $source_post_name;
        }
        $update_result = wp_update_post(wp_slash($post_update), true);
        restore_current_blog();

        if (is_wp_error($update_result)) {
            return $this->fail_translation_job($job, $attempts, '写入目标文章失败：' . $update_result->get_error_message());
        }
        $slug_lock = $this->force_target_slug_from_source((int)$job['source_blog_id'], (int)$job['source_post_id'], (int)$job['target_blog_id'], (int)$job['target_post_id']);
        if (is_wp_error($slug_lock)) {
            return $this->fail_translation_job($job, $attempts, '目标文章 slug 强制锁定失败：' . $slug_lock->get_error_message());
        }

        $meta_result = ['translated' => 0, 'skipped' => 0];
        if (!empty($settings['openai_translate_meta'])) {
            $meta_result = $this->openai_translate_target_post_meta_from_source($job, $target_label, $settings);
            if (is_wp_error($meta_result)) {
                return $this->fail_translation_job($job, $attempts, 'ACF/自定义字段翻译失败：' . $meta_result->get_error_message());
            }
        }
        $slug_lock = $this->force_target_slug_from_source((int)$job['source_blog_id'], (int)$job['source_post_id'], (int)$job['target_blog_id'], (int)$job['target_post_id']);
        if (is_wp_error($slug_lock)) {
            return $this->fail_translation_job($job, $attempts, '目标文章 slug 二次锁定失败：' . $slug_lock->get_error_message());
        }

        // 0.9.6.4: PHP validation must not overwrite accepted AI output.

        $writeback_expected = [
            'post_status' => $write_status,
        ];
        if ($translate_title) {
            $writeback_expected['post_title'] = $new_title;
        }
        if ($translate_excerpt) {
            $writeback_expected['post_excerpt'] = $new_excerpt;
        }
        if ($translate_content) {
            $writeback_expected['post_content'] = $new_content;
        }
        if ($source_post_name !== '') {
            $writeback_expected['post_name'] = $source_post_name;
        }
        $writeback_check = $this->openai_verify_post_writeback(
            (int)$job['target_blog_id'],
            (int)$job['target_post_id'],
            $writeback_expected
        );
        if (is_wp_error($writeback_check)) {
            return $this->fail_translation_job($job, $attempts, $writeback_check->get_error_message());
        }
        $this->mark_translation_content_completed($job);

        $quality_runtime = $this->openai_quality_runtime_summary();
        $quality_unavailable = (int)($quality_runtime['unavailable'] ?? 0);
        $quality_expected = (int)($quality_runtime['expected'] ?? 0);
        $quality_checked = (int)($quality_runtime['checked'] ?? 0);
        $quality_repair_failed = (int)($quality_runtime['repair_failed'] ?? 0);
        $quality_mode = (string)($quality_runtime['effective_mode'] ?? '');
        if ($quality_mode === '') {
            $quality_mode = $this->openai_quality_coverage_mode($settings);
        }
        $quality_strict = !array_key_exists('openai_qa_strict_status', (array)$settings) || !empty($settings['openai_qa_strict_status']);
        $quality_incomplete = $quality_unavailable > 0
            || $quality_repair_failed > 0
            || ($quality_strict && $quality_mode !== 'off' && $quality_expected !== $quality_checked);
        $this->openai_cli_trace_line(sprintf(
            'QUALITY COVERAGE translated=%d self_reviewed=%d ai_candidate_unique=%d ai_candidate_raw_fields=%d ai_checked_unique=%d ai_checked_raw_fields=%d deterministic_checked=%d adaptive_skipped=%d unavailable=%d repairs_failed=%d status=%s',
            (int)($quality_runtime['eligible_fields'] ?? 0),
            (int)($quality_runtime['self_reviewed_fields'] ?? 0),
            (int)($quality_runtime['ai_candidate_unique'] ?? $quality_runtime['expected'] ?? 0),
            (int)($quality_runtime['ai_candidate_raw_fields'] ?? $quality_runtime['ai_candidate_fields'] ?? 0),
            (int)($quality_runtime['ai_checked_unique'] ?? $quality_runtime['checked'] ?? 0),
            (int)($quality_runtime['ai_checked_raw_fields'] ?? $quality_runtime['covered_raw_fields'] ?? 0),
            (int)($quality_runtime['deterministic_checked'] ?? 0),
            (int)($quality_runtime['adaptive_skipped_fields'] ?? 0),
            $quality_unavailable,
            $quality_repair_failed,
            $quality_incomplete ? 'partial' : 'complete'
        ));
        $draft_on_incomplete = !array_key_exists('openai_qa_draft_on_incomplete', (array)$settings) || !empty($settings['openai_qa_draft_on_incomplete']);
        if ($quality_incomplete && (!empty($settings['openai_agent_fail_on_qa']) || $draft_on_incomplete)) {
            $quality_scopes = implode(',', array_keys((array)($quality_runtime['scopes'] ?? [])));
            $review_post_status = 'draft';
            switch_to_blog((int)$job['target_blog_id']);
            $review_status_result = wp_update_post([
                'ID' => (int)$job['target_post_id'],
                'post_status' => $review_post_status,
            ], true);
            restore_current_blog();
            if (is_wp_error($review_status_result)) {
                return $this->fail_translation_job($job, $attempts, '存在未完成翻译/质检字段，且目标文章改为草稿失败：' . $review_status_result->get_error_message());
            }
            $review_writeback_check = $this->openai_verify_post_writeback(
                (int)$job['target_blog_id'],
                (int)$job['target_post_id'],
                ['post_status' => $review_post_status]
            );
            if (is_wp_error($review_writeback_check)) {
                return $this->fail_translation_job($job, $attempts, $review_writeback_check->get_error_message());
            }
            $qa_message = '翻译或质检未完整完成：expected=' . $quality_expected . '，checked=' . $quality_checked . '，unavailable=' . $quality_unavailable . '，repair_failed=' . $quality_repair_failed
                . ($quality_scopes !== '' ? '（' . $quality_scopes . '）' : '')
                . '；目标文章状态已改为 draft 草稿，未发布。';
            $wpdb->update($this->tables['jobs'], [
                'engine' => 'openai',
                'status' => 'review_required',
                'attempts' => $attempts,
                'last_error' => $qa_message,
                'locked_at' => null,
                'locked_by' => '',
                'process_after' => null,
                'finished_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ], ['id' => $job_id], ['%s','%s','%d','%s','%s','%s','%s','%s','%s'], ['%d']);
            $this->update_relation_for_job($job, 'review_required', $review_post_status);
            $this->openai_cli_trace_line('QUALITY COVERAGE status=review_required expected=' . $quality_expected . ' checked=' . $quality_checked . ' unavailable=' . $quality_unavailable . ' repair_failed=' . $quality_repair_failed . ' scopes=' . ($quality_scopes !== '' ? $quality_scopes : '-'));
            $this->openai_cli_trace_performance_summary('review_required');
            return true;
        }

        if ($write_status !== $complete_status) {
            switch_to_blog((int)$job['target_blog_id']);
            $final_status_result = wp_update_post([
                'ID' => (int)$job['target_post_id'],
                'post_status' => $complete_status,
            ], true);
            restore_current_blog();
            if (is_wp_error($final_status_result)) {
                return $this->fail_translation_job($job, $attempts, '质检通过，但目标文章切换到最终状态失败：' . $final_status_result->get_error_message());
            }
            $release_check = $this->openai_verify_post_writeback(
                (int)$job['target_blog_id'],
                (int)$job['target_post_id'],
                ['post_status' => $complete_status]
            );
            if (is_wp_error($release_check)) {
                return $this->fail_translation_job($job, $attempts, $release_check->get_error_message());
            }
            $this->openai_cli_trace_line('QA STAGING RELEASE final_status=' . $complete_status);
        }

        $performance = $this->openai_cli_trace_performance_summary('completed');
        $status = $complete_status === 'publish' ? 'machine_done_published' : 'machine_translated';
        $relation_status = $complete_status === 'publish' ? 'translated' : 'machine_translated';
        $message = 'OpenAI 兼容翻译完成，正文按 HTML/区块安全方式处理，目标文章状态：' . $complete_status;
        $message .= '；API请求 ' . (int)($performance['requests'] ?? 0) . ' 次，累计 ' . number_format((float)($performance['seconds'] ?? 0), 1, '.', '') . ' 秒';
        $quality_runtime = $this->openai_quality_runtime_summary();
        $quality_unavailable = (int)($quality_runtime['unavailable'] ?? 0);
        $quality_expected = (int)($quality_runtime['expected'] ?? 0);
        $quality_checked = (int)($quality_runtime['checked'] ?? 0);
        $quality_repair_failed = (int)($quality_runtime['repair_failed'] ?? 0);
        $quality_complete = $quality_unavailable === 0 && $quality_repair_failed === 0
            && (((string)($quality_runtime['effective_mode'] ?? $this->openai_quality_coverage_mode($settings))) === 'off' || $quality_expected === $quality_checked);
        $effective_quality_mode = (string)($quality_runtime['effective_mode'] ?? $this->openai_quality_coverage_mode($settings));
        if ($effective_quality_mode === 'off' && is_array($php_integrity_result) && !empty($php_integrity_result['ok'])) {
            $message .= '；PHP 本地完整性检查通过（AI 质量检查关闭）';
        } elseif (is_array($php_integrity_result) && !empty($php_integrity_result['ok']) && $quality_complete) {
            if ($effective_quality_mode === 'adaptive') {
                $message .= '；翻译内联自检与 AI 异常质检通过（AI候选 ' . $quality_checked . '/' . $quality_expected . '，程序检查 ' . (int)($quality_runtime['deterministic_checked'] ?? 0) . '）';
            } else {
                $message .= '；AI 质检通过（' . $effective_quality_mode . ' ' . $quality_checked . '/' . $quality_expected . '）';
            }
        } elseif ($quality_unavailable > 0 || $quality_repair_failed > 0 || !$quality_complete) {
            $quality_scopes = implode(',', array_keys((array)($quality_runtime['scopes'] ?? [])));
            $message .= '；AI 质检部分完成，expected=' . $quality_expected . '，checked=' . $quality_checked . '，unavailable=' . $quality_unavailable . '，repair_failed=' . $quality_repair_failed
                . ($quality_scopes !== '' ? '（' . $quality_scopes . '）' : '');
        }
        if (is_array($meta_result)) {
            $message .= '；字段翻译 ' . intval($meta_result['translated'] ?? 0) . ' 项，跳过 ' . intval($meta_result['skipped'] ?? 0) . ' 项';
        }
        $updated = $wpdb->update($this->tables['jobs'], [
            'engine' => 'openai',
            'status' => $status,
            'attempts' => $attempts,
            'last_error' => $message,
            'locked_at' => null,
            'locked_by' => '',
            'process_after' => null,
            'finished_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $job_id], ['%s','%s','%d','%s','%s','%s','%s','%s','%s'], ['%d']);

        $this->update_relation_for_job($job, $relation_status);
        $this->log('info', 'translation_openai_done', 'OpenAI 兼容翻译完成', [
            'job_id' => $job_id,
            'target_lang' => $job['target_lang'],
            'target_status' => $complete_status,
            'model' => trim((string)($settings['openai_model'] ?? '')),
            'route_reason' => (string)($job['route_reason'] ?? ''),
            'meta_translated' => is_array($meta_result) ? intval($meta_result['translated'] ?? 0) : 0,
        ]);
        return $updated !== false;
    }

    /**
     * Remove known browser/page-translation structural pollution before extraction.
     * Source posts are never modified; cleanup only affects the in-memory copy used
     * for translation and QA. The sanitizer is signature-based so ordinary authored
     * spans, font tags and legitimate .notranslate regions remain untouched.
     */

    private function openai_strip_translation_artifacts($content) {
        $content = (string)$content;
        if ($content === '' || !class_exists('WPMU_ML_Content_Sanitizer')) {
            return $content;
        }

        $stats = [];
        if (method_exists('WPMU_ML_Content_Sanitizer', 'strip_translation_artifacts')) {
            $cleaned = WPMU_ML_Content_Sanitizer::strip_translation_artifacts($content, $stats);
        } else {
            $cleaned = WPMU_ML_Content_Sanitizer::strip_immersive_translate_artifacts($content, $stats);
        }
        if ($cleaned !== $content) {
            $this->openai_cli_trace_line(sprintf(
                'POLLUTION CLEANUP attrs=%d class_tokens=%d notranslate=%d wrappers=%d ui=%d markers=%d chars=%d->%d',
                intval($stats['attributes_removed'] ?? 0),
                intval($stats['class_tokens_removed'] ?? 0),
                intval($stats['notranslate_tokens_removed'] ?? 0),
                intval($stats['wrappers_unwrapped'] ?? 0),
                intval($stats['ui_nodes_removed'] ?? 0),
                intval($stats['fragment_markers_removed'] ?? 0),
                strlen($content),
                strlen((string)$cleaned)
            ));
        }

        return (string)$cleaned;
    }

    // Backward-compatible private alias for older internal call sites.

    private function openai_strip_immersive_translate_artifacts($content) {
        return $this->openai_strip_translation_artifacts($content);
    }

    private function openai_translate_wp_content($content, $target_label, $settings, $task_instruction = '', $allow_residual_pass = true) {
        $content = $this->openai_strip_translation_artifacts((string)$content);
        $original_content_for_code_repair = $content;
        if ($content === '') {
            return $content;
        }

        $semantic_block_translation = !array_key_exists('openai_semantic_block_translation', (array)$settings)
            || !empty($settings['openai_semantic_block_translation']);
        if ($semantic_block_translation) {
            // Human-readable inline-code labels are translated inside their complete sentence
            // so the AI can infer whether G/M mean RAM, bandwidth, size or another unit.
            $settings['openai_defer_inline_code_to_semantic_block'] = 1;
        }

        /**
         * 代码保护策略：
         * - pre 代码块默认智能翻译其中的人类可读文字：注释、字符串 value、数组/对象 value 等。
         * - 代码结构、变量名、函数名、数组 key、路径、URL、命令和语法保持不变。
         * - script/style/textarea 整体保护；行内 code 默认智能处理。
         */
        $protected_map = [];
        // Explicit no-translate markers are protected before code/block parsing so intentional
        // ad widgets, legal notices or embedded vendor blocks are neither translated nor counted
        // as untranslated residue by QA.
        $content = $this->openai_protect_explicit_no_translate_regions($content, $protected_map, $settings);
        $content = $this->openai_prepare_code_regions_for_translation($content, $target_label, $settings, $task_instruction, $protected_map);
        if (is_wp_error($content)) {
            return $content;
        }

        // 再处理 Gutenberg / ACF block 注释中的 JSON 数据。
        // v0.7.14：必须在 pre/script/style 已保护之后处理，避免 block JSON 中的 code/content 字段绕过代码行锁。
        $comment_translated = $this->openai_translate_wp_block_comments($content, $target_label, $settings, $task_instruction);
        if (is_wp_error($comment_translated)) {
            return $comment_translated;
        }
        $content = (string)$comment_translated;

        // When the fragment-count limit is disabled, keep each paragraph/list item/heading
        // as one coherent translation unit instead of translating every text node around
        // <strong>, <a>, <span> and other inline tags independently. This is especially
        // important for target languages whose natural word order differs from the source
        // from the configured source language. The character limit still applies and may split a very long article
        // into multiple ordered requests.
        // 0.9.4.7: semantic block translation is the quality-first default. The batch-field
        // setting now controls request size only; it no longer forces the legacy text-node
        // splitter. Existing installations can explicitly disable the semantic mode if a
        // malformed third-party HTML payload requires the old fallback behavior.
        $whole_body_request = $semantic_block_translation;
        $this->openai_cli_trace_line(sprintf(
            'TRANSLATION MODE semantic_blocks=%s configured_fragment_fields=%d effective_batch_fields=%d max_chars=%d',
            $whole_body_request ? 'on' : 'off',
            absint($settings['openai_fragment_batch_fields'] ?? 30),
            $this->openai_centralized_quality_enabled($settings)
                ? $this->openai_central_translation_batch_fields($settings)
                : absint($settings['openai_fragment_batch_fields'] ?? 30),
            absint($settings['openai_max_chars'] ?? 8000)
        ));
        if ($whole_body_request) {
            $coherent_content = $this->openai_translate_coherent_visible_body(
                $content,
                $target_label,
                $settings,
                $task_instruction
            );
            if (is_wp_error($coherent_content)) {
                return $coherent_content;
            }

            // Translate user-facing HTML attributes in a separate compact pass, similar to
            // mature visual translation plugins. The main article request remains focused on
            // coherent prose while alt/title/placeholder/ARIA labels are no longer silently left in the source language.
            $coherent_content = $this->openai_translate_translatable_html_attributes(
                (string)$coherent_content,
                $target_label,
                $settings,
                $task_instruction
            );
            if (is_wp_error($coherent_content)) {
                return $coherent_content;
            }

            $restored_content = $this->openai_restore_protected_regions((string)$coherent_content, $protected_map);
            if ($allow_residual_pass && !empty($settings['openai_residual_body_pass'])) {
                $restored_content = $this->openai_translate_residual_source_text(
                    $original_content_for_code_repair,
                    (string)$restored_content,
                    $target_label,
                    $settings,
                    $task_instruction
                );
                if (is_wp_error($restored_content)) {
                    return $restored_content;
                }
            }

            $restored_content = $this->openai_apply_target_visible_text_polish(
                (string)$restored_content,
                $target_label,
                $settings
            );
            $restored_content = $this->openai_apply_target_html_boundary_polish(
                (string)$restored_content,
                $target_label,
                $settings
            );

            return $this->openai_repair_translated_code_blocks_against_source(
                $original_content_for_code_repair,
                (string)$restored_content
            );
        }

        // 保护普通 HTML 标签、短代码，以及仍然保留的 inline code/kbd/samp 等不可翻译块。
        // 注意：普通 HTML 标签只保护标签本身，标签之间的人类可读文本仍会被翻译。
        $protected_regex = '~(<(?:code|kbd|samp)\b[^>]*>.*?</(?:code|kbd|samp)>|<[^>]+>|\[[A-Za-z0-9_-]+[^\]]*(?:\].*?\[/[A-Za-z0-9_-]+\])?)~is';
        $parts = preg_split($protected_regex, $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts) || count($parts) <= 1) {
            $translated_plain = $this->openai_translate_plain_text($content, $target_label, $settings, $task_instruction);
            if (is_wp_error($translated_plain)) {
                return $translated_plain;
            }
            $restored_plain = $this->openai_restore_protected_regions((string)$translated_plain, $protected_map);
            $restored_plain = $this->openai_apply_target_visible_text_polish((string)$restored_plain, $target_label, $settings);
            $restored_plain = $this->openai_apply_target_html_boundary_polish((string)$restored_plain, $target_label, $settings);
            return $this->openai_repair_translated_code_blocks_against_source($original_content_for_code_repair, (string)$restored_plain);
        }

        $out = $parts;
        $fragments = [];
        $heading_fragments = [1 => [], 2 => [], 3 => [], 4 => []];
        $current_heading_level = 0;
        $whole_body_request = false;

        foreach ($parts as $i => $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match($protected_regex, $part)) {
                if (preg_match('~^<h([1-4])\b~i', $part, $hm)) {
                    $current_heading_level = (int)$hm[1];
                } elseif (preg_match('~^</h([1-4])\s*>~i', $part)) {
                    $current_heading_level = 0;
                }
                continue;
            }

            if (!$this->openai_contains_translatable_source_text($part)) {
                continue;
            }

            if ($whole_body_request) {
                // 0 + 0 means one ordered main request for the complete visible body.
                // Headings and body text remain in original order so the model can use
                // article-wide context instead of translating each heading level separately.
                $fragments[$i] = $part;
            } elseif ($current_heading_level >= 1 && $current_heading_level <= 4) {
                $heading_fragments[$current_heading_level][$i] = $part;
            } else {
                $fragments[$i] = $part;
            }
        }

        if ($whole_body_request && $fragments) {
            $whole_instruction = trim((string)$task_instruction);
            if ($whole_instruction !== '') {
                $whole_instruction .= ' ';
            }
            $whole_instruction .= 'These are ordered visible text fragments from one complete WordPress article, including headings and ordinary body text. Translate them as one coherent article in a single main response. Maintain terminology, pronoun references, tone and context across all fragments. Infer concise heading style from position and wording. Preserve the exact JSON keys and do not merge, omit or reorder fragments.';
            $translated = $this->openai_translate_fragment_map($fragments, $target_label, $settings, $whole_instruction);
            if (is_wp_error($translated)) {
                return $translated;
            }
            foreach ($translated as $i => $value) {
                if (array_key_exists($i, $out)) {
                    $out[$i] = (string)$value;
                }
            }
        } else {
            foreach ($heading_fragments as $level => $items) {
                if (!$items) {
                    continue;
                }
                $translated_headings = $this->openai_translate_fragment_map(
                    $items,
                    $target_label,
                    $settings,
                    'Field-aware translation: these are H' . $level . ' heading text fragments from WordPress post content. Localize naturally for the target language while preserving heading level, original meaning, search intent, core keywords, numbers, prices, dates, product names and brand names. Do not translate word-for-word if unnatural. Do not add facts. Return ONLY valid JSON with the exact same keys.'
                );
                if (is_wp_error($translated_headings)) {
                    return $translated_headings;
                }
                foreach ($translated_headings as $i => $value) {
                    if (array_key_exists($i, $out)) {
                        $out[$i] = (string)$value;
                    }
                }
            }

            if ($fragments) {
                $translated = $this->openai_translate_fragment_map($fragments, $target_label, $settings, $task_instruction);
                if (is_wp_error($translated)) {
                    return $translated;
                }

                foreach ($translated as $i => $value) {
                    if (array_key_exists($i, $out)) {
                        $out[$i] = (string)$value;
                    }
                }
            }
        }

        $restored_content = $this->openai_restore_protected_regions(implode('', $out), $protected_map);

        // Performance safeguard: residual CJK cleanup is optional and runs only once for the
        // top-level post body. Nested Gutenberg/ACF HTML must not recursively trigger another
        // residual pass, otherwise a single article can fan out into dozens of tiny API calls.
        if ($allow_residual_pass && !empty($settings['openai_residual_body_pass'])) {
            $restored_content = $this->openai_translate_residual_source_text(
                $original_content_for_code_repair,
                (string)$restored_content,
                $target_label,
                $settings,
                $task_instruction
            );
            if (is_wp_error($restored_content)) {
                return $restored_content;
            }
        }

        $restored_content = $this->openai_apply_target_visible_text_polish(
            (string)$restored_content,
            $target_label,
            $settings
        );
        $restored_content = $this->openai_apply_target_html_boundary_polish(
            (string)$restored_content,
            $target_label,
            $settings
        );

        return $this->openai_repair_translated_code_blocks_against_source($original_content_for_code_repair, (string)$restored_content);
    }

    /**
     * Translate the visible body in coherent units while preserving the original HTML bytes.
     * Leaf paragraphs, headings and list items are submitted as complete units with inline
     * markup replaced by control tokens. Remaining loose text nodes are included in source
     * order. The control tokens are validated by the normal missing-field recovery path.
     */

    private function openai_translate_coherent_visible_body($content, $target_label, $settings, $task_instruction = '') {
        $content = (string)$content;
        if ($content === '') {
            return $content;
        }

        $plan = $this->openai_build_coherent_body_plan($content);
        $fragments = (array)($plan['fragments'] ?? []);
        $items = (array)($plan['items'] ?? []);
        if (!$fragments || !$items) {
            return $content;
        }

        $coherent_chars = 0;
        foreach ($fragments as $fragment) {
            $coherent_chars += $this->wpmu_ml_strlen((string)$fragment);
        }
        $sections = $this->openai_build_article_section_plan($fragments, $items);
        $this->openai_cli_trace_line(sprintf(
            'TRANSLATION BLOCKS units=%d chars=%d sections=%d strategy=section_context parser=offset-safe',
            count($fragments),
            $coherent_chars,
            count($sections)
        ));

        $instruction = trim((string)$task_instruction);
        if ($instruction !== '') {
            $instruction .= ' ';
        }
        $instruction .= 'Translate the supplied ordered blocks as one contiguous part of the same article. Write natural publication-ready target-language prose, preserve meaning and facts, and keep terminology coherent with the article context. Preserve every placeholder and machine token exactly once and keep balanced placeholder tags well nested. Return only valid JSON with the exact same keys.';

        if (!array_key_exists('openai_translation_self_review', (array)$settings) || !empty($settings['openai_translation_self_review'])) {
            $this->openai_cli_trace_line(sprintf('TRANSLATION SELF REVIEW mode=inline scope=article fields=%d extra_requests=0', count($fragments)));
        }

        $translated = $this->openai_translate_article_sections(
            $fragments,
            $items,
            $sections,
            $target_label,
            $settings,
            $instruction
        );
        if (is_wp_error($translated)) {
            return $translated;
        }

        $translated = $this->openai_repair_suspicious_article_blocks(
            $fragments,
            $translated,
            $items,
            $target_label,
            $settings
        );
        if (is_wp_error($translated)) {
            return $translated;
        }

        $translated = $this->openai_ai_editorial_review_article_blocks(
            $fragments,
            $translated,
            $items,
            $target_label,
            $settings
        );
        if (is_wp_error($translated)) {
            return $translated;
        }

        $replacements = [];
        foreach ($items as $id => $item) {
            if (!array_key_exists($id, $translated)) {
                continue;
            }
            $value = (string)$translated[$id];
            $token_map = is_array($item['token_map'] ?? null) ? $item['token_map'] : [];
            if ($token_map) {
                $value = strtr($value, $token_map);
            }
            $value = $this->openai_preserve_fragment_boundary_whitespace(
                (string)($item['source'] ?? ''),
                $value
            );
            $replacements[] = [
                'offset' => (int)($item['offset'] ?? 0),
                'length' => (int)($item['length'] ?? 0),
                'value' => $value,
            ];
        }

        usort($replacements, static function($a, $b) {
            return ((int)$b['offset']) <=> ((int)$a['offset']);
        });
        foreach ($replacements as $replacement) {
            $content = substr_replace(
                $content,
                (string)$replacement['value'],
                (int)$replacement['offset'],
                (int)$replacement['length']
            );
        }

        return $content;
    }

    private function openai_article_fragment_plain_text($value) {
        $value = $this->openai_normalize_translation_control_placeholders((string)$value);
        $value = preg_replace('/<\/?wpmu-ml-\d+\s*\/?>/iu', ' ', (string)$value);
        $value = preg_replace('/__WPMU_ML_(?:ATOMIC|MACHINE)_\d+__/u', ' ', (string)$value);
        $value = html_entity_decode(wp_strip_all_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\s\x{00A0}]+/u', ' ', trim((string)$value));
        return is_string($value) ? $value : '';
    }

    private function openai_build_article_section_plan($fragments, $items) {
        $fragments = (array)$fragments;
        $items = (array)$items;
        $sections = [];
        $current = -1;
        foreach ($fragments as $id => $value) {
            $type = strtolower((string)($items[$id]['type'] ?? 'text'));
            $is_boundary = in_array($type, ['h1', 'h2'], true);
            if ($current < 0 || ($is_boundary && !empty($sections[$current]['ids']))) {
                $current++;
                $sections[$current] = [
                    'id' => $current,
                    'heading' => '',
                    'heading_type' => '',
                    'ids' => [],
                ];
            }
            if ($is_boundary && $sections[$current]['heading'] === '') {
                $sections[$current]['heading'] = $this->openai_article_fragment_plain_text((string)$value);
                $sections[$current]['heading_type'] = $type;
            }
            $sections[$current]['ids'][] = $id;
            $items[$id]['section_id'] = $current;
        }

        $headings = [];
        foreach ($sections as $idx => $section) {
            $heading = trim((string)($section['heading'] ?? ''));
            $headings[$idx] = $heading !== '' ? $heading : ('Section ' . ($idx + 1));
        }
        foreach ($sections as $idx => &$section) {
            $section['previous_heading'] = $idx > 0 ? (string)$headings[$idx - 1] : '';
            $section['next_heading'] = isset($headings[$idx + 1]) ? (string)$headings[$idx + 1] : '';
            if (trim((string)$section['heading']) === '') {
                $section['heading'] = (string)$headings[$idx];
            }
        }
        unset($section);
        return array_values($sections);
    }

    private function openai_partition_article_section_ids($ids, $fragments, $max_fields, $max_chars) {
        $batches = [];
        $current = [];
        $chars = 0;
        foreach ((array)$ids as $id) {
            $value = (string)($fragments[$id] ?? '');
            $length = $this->wpmu_ml_strlen($value);
            if ($current && ((int)$max_fields > 0 && count($current) >= (int)$max_fields || $chars + $length > (int)$max_chars)) {
                $batches[] = $current;
                $current = [];
                $chars = 0;
            }
            $current[] = $id;
            $chars += $length;
        }
        if ($current) {
            $batches[] = $current;
        }
        return $batches;
    }

    private function openai_translate_article_sections($fragments, $items, $sections, $target_label, $settings, $base_instruction) {
        $fragments = (array)$fragments;
        $items = (array)$items;
        $sections = (array)$sections;
        $translated = [];
        $max_fields = max(12, min(50, absint($settings['openai_section_batch_fields'] ?? 36)));
        $configured_chars = absint($settings['openai_max_chars'] ?? 6000);
        $max_chars = max(1200, min(2600, absint($settings['openai_section_batch_chars'] ?? 2200)));
        if ($configured_chars > 0) {
            $max_chars = min($max_chars, max(1200, $configured_chars));
        }

        // Build request batches from complete H2 sections when possible. Several short
        // neighboring sections may share one request; a long section is split only at a
        // block boundary. This retains semantic boundaries without creating one API call per H3.
        $plans = [];
        $pending = ['ids' => [], 'section_indexes' => [], 'chars' => 0];
        $flush_pending = static function() use (&$plans, &$pending) {
            if (!empty($pending['ids'])) {
                $plans[] = $pending;
            }
            $pending = ['ids' => [], 'section_indexes' => [], 'chars' => 0];
        };
        foreach ($sections as $section_index => $section) {
            $chunks = $this->openai_partition_article_section_ids((array)($section['ids'] ?? []), $fragments, $max_fields, $max_chars);
            foreach ($chunks as $chunk_index => $ids) {
                $chunk_chars = 0;
                foreach ($ids as $id) {
                    $chunk_chars += $this->wpmu_ml_strlen((string)($fragments[$id] ?? ''));
                }
                $would_overflow = !empty($pending['ids'])
                    && (count($pending['ids']) + count($ids) > $max_fields || (int)$pending['chars'] + $chunk_chars > $max_chars);
                if ($would_overflow) {
                    $flush_pending();
                }
                // A chunk produced from a long section already approaches the request limit;
                // isolate it so the next semantic section starts cleanly.
                if (count($ids) >= $max_fields || $chunk_chars >= (int)floor($max_chars * 0.82)) {
                    $flush_pending();
                    $plans[] = [
                        'ids' => array_values($ids),
                        'section_indexes' => [$section_index],
                        'chars' => $chunk_chars,
                        'section_part' => $chunk_index + 1,
                        'section_parts' => count($chunks),
                    ];
                    continue;
                }
                $pending['ids'] = array_merge((array)$pending['ids'], array_values($ids));
                $pending['section_indexes'][$section_index] = $section_index;
                $pending['chars'] = (int)$pending['chars'] + $chunk_chars;
            }
        }
        $flush_pending();

        $this->openai_cli_trace_line(sprintf(
            'SECTION TRANSLATION PLAN sections=%d batches=%d limits=fields:%d,chars:%d context=source_article+adjacent',
            count($sections),
            count($plans),
            $max_fields,
            $max_chars
        ));

        $previous_source_tail = '';
        $previous_target_tail = '';
        foreach ($plans as $batch_index => $plan) {
            $batch_fragments = [];
            foreach ((array)($plan['ids'] ?? []) as $id) {
                $batch_fragments[$id] = (string)($fragments[$id] ?? '');
            }
            $section_indexes = array_values((array)($plan['section_indexes'] ?? []));
            if (!$section_indexes) {
                $section_indexes = [0];
            }
            sort($section_indexes, SORT_NUMERIC);
            $headings = [];
            foreach ($section_indexes as $section_index) {
                $heading = trim((string)($sections[$section_index]['heading'] ?? ''));
                if ($heading !== '') {
                    $headings[] = $heading;
                }
            }
            $first_section = (int)$section_indexes[0];
            $last_section = (int)$section_indexes[count($section_indexes) - 1];
            $context = [];
            if ($headings) {
                $context[] = 'Current section sequence: ' . implode(' > ', $headings);
            }
            if ($first_section > 0 && trim((string)($sections[$first_section - 1]['heading'] ?? '')) !== '') {
                $context[] = 'Previous section: ' . (string)$sections[$first_section - 1]['heading'];
            }
            if (isset($sections[$last_section + 1]) && trim((string)($sections[$last_section + 1]['heading'] ?? '')) !== '') {
                $context[] = 'Next section: ' . (string)$sections[$last_section + 1]['heading'];
            }
            if ($previous_source_tail !== '') {
                $context[] = 'Previous source continuity sample: ' . $previous_source_tail;
            }
            if ($previous_target_tail !== '') {
                $context[] = 'Previous translated continuity sample: ' . $previous_target_tail;
            }
            $instruction = trim((string)$base_instruction)
                . ' Read this request as one continuous semantic group from the article.'
                . ($context ? ' Read-only context: ' . implode(' | ', $context) . '.' : '');
            $batch_settings = $settings;
            $batch_settings['openai_fragment_batch_fields'] = $max_fields;
            $batch_settings['openai_max_chars'] = $max_chars;
            $batch_settings['openai_internal_preserve_fragment_limits'] = 1;
            $this->openai_cli_trace_line(sprintf(
                'SECTION TRANSLATION BATCH batch=%d/%d sections=%s fields=%d chars=%d headings=%s',
                $batch_index + 1,
                count($plans),
                implode(',', array_map(static function($i) { return (string)($i + 1); }, $section_indexes)),
                count($batch_fragments),
                (int)($plan['chars'] ?? 0),
                $this->openai_cli_trace_snippet($headings ? implode(' > ', $headings) : '-', 140)
            ));
            $batch_result = $this->openai_translate_fragment_map($batch_fragments, $target_label, $batch_settings, $instruction);
            if (is_wp_error($batch_result)) {
                return $batch_result;
            }
            foreach ($batch_result as $id => $value) {
                $translated[$id] = (string)$value;
            }
            $source_tail_values = array_slice(array_values($batch_fragments), -2);
            $previous_source_tail = $this->openai_article_fragment_plain_text(implode(' ', array_map('strval', $source_tail_values)));
            if (function_exists('mb_substr')) {
                $previous_source_tail = mb_substr($previous_source_tail, 0, 260, 'UTF-8');
            } else {
                $previous_source_tail = substr($previous_source_tail, 0, 260);
            }
            $tail_values = array_slice(array_values($batch_result), -2);
            $previous_target_tail = $this->openai_article_fragment_plain_text(implode(' ', array_map('strval', $tail_values)));
            if (function_exists('mb_substr')) {
                $previous_target_tail = mb_substr($previous_target_tail, 0, 320, 'UTF-8');
            } else {
                $previous_target_tail = substr($previous_target_tail, 0, 320);
            }
        }
        return $translated;
    }

    private function openai_build_coherent_body_plan($content) {
        $content = (string)$content;
        $ranges = $this->openai_find_leaf_text_container_ranges($content);
        $items = [];
        $covered = [];
        $token_counter = 0;

        foreach ($ranges as $range) {
            $offset = (int)$range['offset'];
            $length = (int)$range['length'];
            if ($length <= 0) {
                continue;
            }
            $source = substr($content, $offset, $length);
            $visible = html_entity_decode(wp_strip_all_tags((string)$source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($source === '' || !$this->openai_contains_translatable_source_text($visible)) {
                continue;
            }
            $token_map = [];
            $value = $this->openai_tokenize_inline_markup($source, $token_map, $token_counter);
            $tag = strtolower((string)($range['tag'] ?? ''));
            $items[] = [
                'offset' => $offset,
                'length' => $length,
                'source' => $source,
                'value' => $value,
                'token_map' => $token_map,
                'type' => $tag,
            ];
            $covered[] = [$offset, $offset + $length];
        }

        // Add only uncovered visible text tokens. The offset-safe tokenizer understands quoted
        // ">" characters in attributes, comments and malformed fragments better than <[^>]+>.
        foreach ($this->openai_scan_html_tokens($content) as $token) {
            if (($token['type'] ?? '') !== 'text') {
                continue;
            }
            $this->openai_add_uncovered_text_slices(
                $content,
                (int)$token['start'],
                (int)$token['end'],
                $covered,
                $items,
                $token_counter
            );
        }

        usort($items, static function($a, $b) {
            return ((int)$a['offset']) <=> ((int)$b['offset']);
        });

        // Absolute overlap guard: a byte range is translated once only. This prevents a parent
        // div and a child paragraph from both being submitted and then written back twice.
        $deduped = [];
        $last_end = -1;
        foreach ($items as $item) {
            $offset = (int)($item['offset'] ?? 0);
            $end = $offset + (int)($item['length'] ?? 0);
            if ($offset < $last_end || $end <= $offset) {
                continue;
            }
            $deduped[] = $item;
            $last_end = $end;
        }

        $fragments = [];
        $indexed_items = [];
        foreach (array_values($deduped) as $id => $item) {
            $fragments[$id] = (string)$item['value'];
            $indexed_items[$id] = $item;
        }

        return [
            'fragments' => $fragments,
            'items' => $indexed_items,
        ];
    }

    /**
     * Find deepest translation blocks while retaining exact byte offsets.
     *
     * The top-parent rule is modeled on mature full-page translation plugins: a candidate
     * element is used as one semantic block only when it does not contain another candidate
     * block. This gives complete p/li/heading/caption units, lets leaf div/section widgets work,
     * and prevents parent/child duplicate translation.
     */

    private function openai_find_leaf_text_container_ranges($content) {
        $content = (string)$content;
        $candidate_tags = array_fill_keys([
            'p','div','li','ol','ul','h1','h2','h3','h4','h5','h6','article','section',
            'figure','figcaption','blockquote','td','th','caption','form','label','dt','dd',
            'summary','address','button','option'
        ], true);
        $excluded_tags = array_fill_keys([
            'script','style','textarea','pre','code','kbd','samp','noscript','template','svg','math'
        ], true);
        $void_tags = array_fill_keys([
            'area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'
        ], true);
        $stack = [];
        $ranges = [];

        foreach ($this->openai_scan_html_tokens($content) as $token) {
            if (($token['type'] ?? '') !== 'tag' || empty($token['tag'])) {
                continue;
            }
            $tag = strtolower((string)$token['tag']);
            $is_close = !empty($token['is_close']);
            $is_self_closing = !empty($token['is_self_closing']) || isset($void_tags[$tag]);

            if (!$is_close) {
                $inherited_excluded = false;
                foreach ($stack as $entry) {
                    if (!empty($entry['excluded'])) {
                        $inherited_excluded = true;
                        break;
                    }
                }
                $excluded = $inherited_excluded || isset($excluded_tags[$tag]);
                $candidate = isset($candidate_tags[$tag]) && !$excluded;
                if ($candidate) {
                    foreach ($stack as $index => $entry) {
                        if (!empty($entry['candidate']) && empty($entry['excluded'])) {
                            $stack[$index]['has_candidate_descendant'] = true;
                        }
                    }
                }
                if (!$is_self_closing) {
                    $stack[] = [
                        'tag' => $tag,
                        'inner_start' => (int)$token['end'],
                        'candidate' => $candidate,
                        'excluded' => $excluded,
                        'has_candidate_descendant' => false,
                    ];
                }
                continue;
            }

            $match_pos = -1;
            for ($i = count($stack) - 1; $i >= 0; $i--) {
                if ((string)$stack[$i]['tag'] === $tag) {
                    $match_pos = $i;
                    break;
                }
            }
            if ($match_pos < 0) {
                continue;
            }

            $entry = $stack[$match_pos];
            $stack = array_slice($stack, 0, $match_pos);
            if (empty($entry['candidate']) || !empty($entry['excluded']) || !empty($entry['has_candidate_descendant'])) {
                continue;
            }

            $inner_start = (int)$entry['inner_start'];
            $length = (int)$token['start'] - $inner_start;
            if ($length <= 0) {
                continue;
            }
            $inner = substr($content, $inner_start, $length);
            $plain = html_entity_decode(wp_strip_all_tags((string)$inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (trim((string)$plain) === '' || !$this->openai_contains_translatable_source_text($plain)) {
                continue;
            }
            $ranges[] = [
                'tag' => $tag,
                'offset' => $inner_start,
                'length' => $length,
            ];
        }

        usort($ranges, static function($a, $b) {
            return ((int)$a['offset']) <=> ((int)$b['offset']);
        });
        return $ranges;
    }

    /**
     * Offset-safe HTML tokenizer. It never serializes the DOM, so Gutenberg comments, unusual
     * whitespace and original attributes remain byte-for-byte intact. It also handles ">" inside
     * quoted attribute values, which the previous tag regex could split incorrectly.
     */

    private function openai_scan_html_tokens($html) {
        $html = (string)$html;
        $length = strlen($html);
        $tokens = [];
        $i = 0;
        while ($i < $length) {
            if ($html[$i] !== '<') {
                $next = strpos($html, '<', $i);
                if ($next === false) {
                    $next = $length;
                }
                $tokens[] = [
                    'type' => 'text',
                    'start' => $i,
                    'end' => $next,
                    'raw' => substr($html, $i, $next - $i),
                ];
                $i = $next;
                continue;
            }

            if (substr($html, $i, 4) === '<!--') {
                $close = strpos($html, '-->', $i + 4);
                $end = $close === false ? $length : $close + 3;
                $tokens[] = [
                    'type' => 'comment',
                    'start' => $i,
                    'end' => $end,
                    'raw' => substr($html, $i, $end - $i),
                ];
                $i = $end;
                continue;
            }

            $quote = '';
            $j = $i + 1;
            for (; $j < $length; $j++) {
                $ch = $html[$j];
                if ($quote !== '') {
                    if ($ch === $quote) {
                        $quote = '';
                    }
                    continue;
                }
                if ($ch === '"' || $ch === "'") {
                    $quote = $ch;
                    continue;
                }
                if ($ch === '>') {
                    $j++;
                    break;
                }
            }
            if ($j > $length) {
                $j = $length;
            }
            if ($j <= $i + 1) {
                $j = $i + 1;
            }
            $raw = substr($html, $i, $j - $i);
            if (!preg_match('/^<\s*(\/?)\s*([A-Za-z][A-Za-z0-9:_-]*)/s', $raw, $m)) {
                // Not a real HTML tag (for example a literal comparison operator). Keep it text.
                $tokens[] = [
                    'type' => 'text',
                    'start' => $i,
                    'end' => $i + 1,
                    'raw' => '<',
                ];
                $i++;
                continue;
            }
            $tag = strtolower((string)$m[2]);
            $is_close = (string)$m[1] === '/';
            $trimmed = rtrim($raw);
            $tokens[] = [
                'type' => 'tag',
                'start' => $i,
                'end' => $j,
                'raw' => $raw,
                'tag' => $tag,
                'is_close' => $is_close,
                'is_self_closing' => !$is_close && substr($trimmed, -2) === '/>',
            ];
            $i = $j;
        }
        return $tokens;
    }

    /**
     * Replace inline markup with balanced custom placeholder tags.
     *
     * Opaque one-token-per-tag placeholders are easy for a model to move away from the
     * phrase they are supposed to wrap, especially when source and target word order differ
     * and the natural word order changes. Balanced placeholders retain the semantics of an
     * opening/closing pair, for example:
     *
     *   <strong>高流量</strong> => <wpmu-ml-12>高流量</wpmu-ml-12>
     *
     * The original bytes are restored after translation. Atomic comments, void tags and
     * shortcodes still use single control tokens.
     */

    private function openai_tokenize_inline_markup($source, &$token_map, &$token_counter) {
        $source = (string)$source;
        $token_map = is_array($token_map) ? $token_map : [];
        $out = '';
        $stack = [];
        $void_tags = array_fill_keys([
            'area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'
        ], true);

        foreach ($this->openai_scan_html_tokens($source) as $part) {
            $type = (string)($part['type'] ?? '');
            $raw = (string)($part['raw'] ?? '');

            if ($type === 'comment') {
                $token = '__WPMU_ML_ATOMIC_' . $token_counter++ . '__';
                $token_map[$token] = $raw;
                $out .= $token;
                continue;
            }

            if ($type === 'tag') {
                $tag = strtolower((string)($part['tag'] ?? ''));
                $is_close = !empty($part['is_close']);
                $is_self_closing = !empty($part['is_self_closing']) || isset($void_tags[$tag]);

                if ($is_close) {
                    $match_index = null;
                    for ($i = count($stack) - 1; $i >= 0; $i--) {
                        if ((string)($stack[$i]['tag'] ?? '') === $tag) {
                            $match_index = $i;
                            break;
                        }
                    }
                    if ($match_index !== null) {
                        $entry = $stack[$match_index];
                        array_splice($stack, $match_index, 1);
                        $placeholder = '</wpmu-ml-' . (int)$entry['id'] . '>';
                        $token_map[$placeholder] = $raw;
                        $out .= $placeholder;
                    } else {
                        // Malformed/unmatched closing tag: keep it atomic rather than risking
                        // an invalid custom-tag tree in the model request.
                        $token = '__WPMU_ML_ATOMIC_' . $token_counter++ . '__';
                        $token_map[$token] = $raw;
                        $out .= $token;
                    }
                    continue;
                }

                $id = $token_counter++;
                if ($is_self_closing) {
                    $placeholder = '<wpmu-ml-' . $id . '/>';
                    $token_map[$placeholder] = $raw;
                    $out .= $placeholder;
                } else {
                    $placeholder = '<wpmu-ml-' . $id . '>';
                    $token_map[$placeholder] = $raw;
                    $out .= $placeholder;
                    $stack[] = ['tag' => $tag, 'id' => $id];
                }
                continue;
            }

            $text_with_atomic_tokens = preg_replace_callback(
                '~(\[[A-Za-z0-9_-]+[^\]]*(?:\].*?\[/[A-Za-z0-9_-]+\])?|%%WPMU_ML_[A-Z0-9_]+%%)~is',
                function($m) use (&$token_map, &$token_counter) {
                    $token = '__WPMU_ML_ATOMIC_' . $token_counter++ . '__';
                    $token_map[$token] = (string)$m[0];
                    return $token;
                },
                $raw
            );
            $out .= $this->openai_tokenize_machine_readable_text(
                is_string($text_with_atomic_tokens) ? $text_with_atomic_tokens : $raw,
                $token_map,
                $token_counter
            );
        }

        return $out;
    }

    private function openai_tokenize_machine_readable_text($text, &$token_map, &$token_counter) {
        $text = (string)$text;
        if ($text === '') {
            return $text;
        }

        // Protect only high-confidence machine-readable values. Mixed human-language
        // specifications such as "2核2G3M" intentionally remain visible to the AI.
        $pattern = "~(?:https?://[^\s<>\"']+|mailto:[^\s<>\"']+|[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|(?<![A-Za-z0-9_-])(?:www\.)?(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}(?:/[A-Za-z0-9._\~:/?#\[\]@!$&'()*+,;=%-]*)?|(?<!\d)(?:\d{1,3}\.){3}\d{1,3}(?!\d)|(?:[A-Za-z]:\\\\|/)(?:[A-Za-z0-9._-]+[\\/])+[A-Za-z0-9._-]+|\b[A-Za-z0-9_-]+\.(?:php|js|css|json|xml|yaml|yml|ini|conf|sql|sh|py|html?|md|txt|zip|tar|gz)\b|\bv?\d+\.\d+(?:\.\d+){1,3}\b)~iu";
        $count = 0;
        $protected = preg_replace_callback(
            $pattern,
            function($m) use (&$token_map, &$token_counter, &$count) {
                $token = '__WPMU_ML_MACHINE_' . $token_counter++ . '__';
                $token_map[$token] = (string)$m[0];
                $count++;
                return $token;
            },
            $text
        );
        if ($count > 0 && $this->openai_cli_trace_enabled()) {
            $this->openai_cli_trace_line('MACHINE TOKEN PROTECTION tokens=' . $count);
        }
        return is_string($protected) ? $protected : $text;
    }

    private function openai_add_uncovered_text_slices($content, $start, $end, $covered, &$items, &$token_counter = 0) {
        $start = (int)$start;
        $end = (int)$end;
        if ($end <= $start) {
            return;
        }
        $segments = [[$start, $end]];
        foreach ((array)$covered as $range) {
            $range_start = (int)($range[0] ?? 0);
            $range_end = (int)($range[1] ?? 0);
            $next = [];
            foreach ($segments as $segment) {
                $seg_start = (int)$segment[0];
                $seg_end = (int)$segment[1];
                if ($range_end <= $seg_start || $range_start >= $seg_end) {
                    $next[] = [$seg_start, $seg_end];
                    continue;
                }
                if ($range_start > $seg_start) {
                    $next[] = [$seg_start, min($range_start, $seg_end)];
                }
                if ($range_end < $seg_end) {
                    $next[] = [max($range_end, $seg_start), $seg_end];
                }
            }
            $segments = $next;
            if (!$segments) {
                break;
            }
        }

        foreach ($segments as $segment) {
            $seg_start = (int)$segment[0];
            $seg_end = (int)$segment[1];
            $source = substr((string)$content, $seg_start, $seg_end - $seg_start);
            if ($source === '' || !$this->openai_contains_translatable_source_text(html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) {
                continue;
            }
            $items[] = [
                'offset' => $seg_start,
                'length' => $seg_end - $seg_start,
                'source' => $source,
                'value' => $source,
                'token_map' => [],
                'type' => 'text',
            ];
        }
    }

    /** Translate visible HTML attributes without serializing or rewriting the surrounding tag. */

    private function openai_translate_translatable_html_attributes($content, $target_label, $settings, $task_instruction = '') {
        $content = (string)$content;
        if ($content === '') {
            return $content;
        }
        $attribute_names = array_fill_keys([
            'alt','title','placeholder','aria-label','aria-description','data-placeholder'
        ], true);
        $items = [];
        foreach ($this->openai_scan_html_tokens($content) as $token) {
            if (($token['type'] ?? '') !== 'tag' || !empty($token['is_close'])) {
                continue;
            }
            $tag = strtolower((string)($token['tag'] ?? ''));
            if (in_array($tag, ['script','style','textarea','pre','code','kbd','samp','template','svg','math'], true)) {
                continue;
            }
            $attributes = $this->openai_parse_tag_attribute_ranges((string)$token['raw'], (int)$token['start']);
            $input_type = '';
            foreach ($attributes as $attribute) {
                if (strtolower((string)$attribute['name']) === 'type') {
                    $input_type = strtolower(trim(html_entity_decode((string)$attribute['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                }
            }
            foreach ($attributes as $attribute) {
                $name = strtolower((string)$attribute['name']);
                $allowed = isset($attribute_names[$name]);
                if ($name === 'value' && in_array($tag, ['button','option'], true)) {
                    $allowed = true;
                }
                if ($name === 'value' && $tag === 'input' && in_array($input_type, ['button','submit','reset'], true)) {
                    $allowed = true;
                }
                if (!$allowed) {
                    continue;
                }
                $decoded = html_entity_decode((string)$attribute['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!$this->openai_contains_translatable_source_text($decoded) || !$this->openai_is_human_translatable_attribute_value($decoded)) {
                    continue;
                }
                $items[] = [
                    'offset' => (int)$attribute['value_offset'],
                    'length' => (int)$attribute['value_length'],
                    'source' => (string)$attribute['value'],
                    'decoded' => $decoded,
                    'quote' => (string)$attribute['quote'],
                    'name' => $name,
                ];
            }
        }
        if (!$items) {
            return $content;
        }

        $fields = [];
        foreach ($items as $index => $item) {
            $fields[$index] = (string)$item['decoded'];
        }
        $this->openai_cli_trace_line(sprintf('TRANSLATABLE ATTRIBUTES count=%d', count($fields)));
        $instruction = trim((string)$task_instruction);
        if ($instruction !== '') {
            $instruction .= ' ';
        }
        $instruction .= 'These values are user-facing HTML attributes from the same WordPress article (alt text, title, placeholder, ARIA labels or button labels). Translate them concisely and naturally for their UI/accessibility purpose. Preserve product names, numbers and meaning. Return ONLY valid JSON with the exact same keys.';
        $translated = $this->openai_translate_fragment_map($fields, $target_label, $settings, $instruction);
        if (is_wp_error($translated)) {
            return $translated;
        }
        $attribute_roles = array_fill_keys(array_keys($fields), 'short_text');
        $translated = $this->openai_central_quality_review_fields(
            $fields,
            $translated,
            $attribute_roles,
            $target_label,
            $settings,
            'html_attributes'
        );
        if (is_wp_error($translated)) {
            return $translated;
        }

        $replacements = [];
        foreach ($items as $index => $item) {
            if (!array_key_exists($index, $translated)) {
                continue;
            }
            $value = $this->openai_preserve_fragment_boundary_whitespace((string)$item['decoded'], (string)$translated[$index]);
            $value = htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
            $replacements[] = [
                'offset' => (int)$item['offset'],
                'length' => (int)$item['length'],
                'value' => $value,
            ];
        }
        usort($replacements, static function($a, $b) {
            return ((int)$b['offset']) <=> ((int)$a['offset']);
        });
        foreach ($replacements as $replacement) {
            $content = substr_replace($content, (string)$replacement['value'], (int)$replacement['offset'], (int)$replacement['length']);
        }
        return $content;
    }

    private function openai_is_human_translatable_attribute_value($value) {
        $value = trim((string)$value);
        if ($value === '' || is_numeric($value)) {
            return false;
        }
        if (preg_match('~^(?:https?:)?//|^mailto:|^tel:|^data:~i', $value)) {
            return false;
        }
        if (preg_match('/^[A-Za-z0-9_\-\.\/\#\?\=&:%]+$/', $value)) {
            return false;
        }
        if (((substr($value, 0, 1) === '{') && (substr($value, -1) === '}')) || ((substr($value, 0, 1) === '[') && (substr($value, -1) === ']'))) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return false;
            }
        }
        return true;
    }

    /** Parse exact attribute-value byte ranges inside one opening tag. */

    private function openai_parse_tag_attribute_ranges($raw_tag, $global_offset = 0) {
        $raw_tag = (string)$raw_tag;
        $length = strlen($raw_tag);
        $attributes = [];
        if (!preg_match('/^<\s*[A-Za-z][A-Za-z0-9:_-]*/', $raw_tag, $m)) {
            return $attributes;
        }
        $i = strlen((string)$m[0]);
        while ($i < $length) {
            while ($i < $length && preg_match('/\s/', $raw_tag[$i])) {
                $i++;
            }
            if ($i >= $length || $raw_tag[$i] === '>' || ($raw_tag[$i] === '/' && ($i + 1 < $length && $raw_tag[$i + 1] === '>'))) {
                break;
            }
            $name_start = $i;
            while ($i < $length && !preg_match('/[\s=\/>]/', $raw_tag[$i])) {
                $i++;
            }
            $name = substr($raw_tag, $name_start, $i - $name_start);
            if ($name === '') {
                $i++;
                continue;
            }
            while ($i < $length && preg_match('/\s/', $raw_tag[$i])) {
                $i++;
            }
            if ($i >= $length || $raw_tag[$i] !== '=') {
                continue;
            }
            $i++;
            while ($i < $length && preg_match('/\s/', $raw_tag[$i])) {
                $i++;
            }
            if ($i >= $length) {
                break;
            }
            $quote = '';
            if ($raw_tag[$i] === '"' || $raw_tag[$i] === "'") {
                $quote = $raw_tag[$i];
                $i++;
                $value_start = $i;
                while ($i < $length && $raw_tag[$i] !== $quote) {
                    $i++;
                }
                $value_end = $i;
                if ($i < $length) {
                    $i++;
                }
            } else {
                $value_start = $i;
                while ($i < $length && !preg_match('/[\s>]/', $raw_tag[$i])) {
                    $i++;
                }
                $value_end = $i;
            }
            $attributes[] = [
                'name' => strtolower($name),
                'value' => substr($raw_tag, $value_start, $value_end - $value_start),
                'value_offset' => (int)$global_offset + $value_start,
                'value_length' => $value_end - $value_start,
                'quote' => $quote,
            ];
        }
        return $attributes;
    }

    /**
     * Final, conservative target-language polish for text that has already been translated.
     * This is deliberately language-neutral and touches visible text nodes only: code, tags,
     * attributes, Gutenberg comments, URLs and protected regions stay byte-for-byte unchanged.
     */

    private function openai_apply_target_visible_text_polish($content, $target_label, $settings) {
        // 0.9.6.4: PHP local quality logic is validation-only. Punctuation, whitespace,
        // numeric formatting, source-residue and length concerns may be sent to optional AI
        // QA, but PHP must never mutate an accepted translation.
        return (string)$content;
    }

    /**
     * Backward-compatible name retained from earlier releases. The implementation is now
     * language-neutral and only removes clearly duplicated terminal punctuation.
     */

    /**
     * Normalize source-locale punctuation and invisible separators only after translation.
     * The transformation is limited to visible text nodes and non-CJK targets, so code,
     * attributes, URLs and intentionally protected regions are not modified.
     */
    private function openai_localize_target_typography_fragment($text, $target_label, $settings) {
        // Compatibility no-op. Target typography is owned by the translation/QA model.
        return (string)$text;
    }

    /**
     * Repair missing prose spaces around restored inline HTML tags for non-CJK targets.
     * This runs after AI translation and placeholder restoration. It deliberately protects
     * code/pre/script/style/textarea regions and only touches common inline prose tags.
     */
    private function openai_apply_target_html_boundary_polish($content, $target_label, $settings) {
        // Compatibility no-op. HTML boundary spacing is advisory AI QA, not a PHP rewrite.
        return (string)$content;
    }

    private function openai_polish_target_text_fragment($text, $context = 'body') {
        // Compatibility no-op. PHP performs no editorial normalization in 0.9.6.4.
        return (string)$text;
    }

    /** Backward-compatible wrapper for integrations reflecting the old private method name. */

    private function openai_polish_japanese_text_fragment($text, $context = 'body') {
        return $this->openai_polish_target_text_fragment($text, $context);
    }

    /**
     * Apply generic target-language verification to title/excerpt. No language code or script
     * is fixed here; source and target identities come only from the configured site contexts.
     */

    private function openai_polish_title_excerpt_for_target($source_fields, $translated_fields, $target_label, $settings) {
        if (!is_array($translated_fields)) {
            return $translated_fields;
        }

        foreach (['title', 'excerpt'] as $field_key) {
            if (array_key_exists($field_key, $translated_fields)) {
                $translated_fields[$field_key] = $this->openai_polish_target_text_fragment(
                    (string)$translated_fields[$field_key],
                    $field_key
                );
                $translated_fields[$field_key] = $this->openai_localize_target_typography_fragment(
                    (string)$translated_fields[$field_key],
                    $target_label,
                    $settings
                );
            }
        }

        if ($this->openai_centralized_quality_enabled($settings)) {
            $this->openai_cli_trace_line('CENTRAL QUALITY DEFER scope=title_excerpt separate_language_and_editor_calls=skipped');
            return $translated_fields;
        }

        $audit = $this->openai_audit_target_language_fields(
            (array)$source_fields,
            (array)$translated_fields,
            $target_label,
            $settings
        );
        if (is_wp_error($audit)) {
            $this->openai_cli_trace_line('TARGET LANGUAGE QA status=unavailable reason=' . $audit->get_error_message());
            return !empty($settings['openai_agent_fail_on_qa']) ? $audit : $translated_fields;
        }
        if (!$audit) {
            $audit = [];
        }

        foreach ($audit as $field_key => $reason) {
            $source_value = (string)($source_fields[$field_key] ?? '');
            if ($source_value === '') {
                continue;
            }
            $this->openai_cli_trace_line(sprintf(
                'TARGET LANGUAGE QA field=%s retry=1 reason=%s',
                $field_key,
                $reason
            ));
            $retry_input = 'SOURCE FIELD:\n' . $source_value
                . '\nCURRENT TARGET FIELD:\n' . (string)($translated_fields[$field_key] ?? '')
                . '\nQA ISSUE:\n' . (string)$reason;
            $retry = $this->openai_request_fields_with_recursive_split(
                [$field_key => $retry_input],
                $target_label,
                $settings,
                'Target-language and semantic field repair. Each value contains SOURCE FIELD, CURRENT TARGET FIELD and QA ISSUE. Return ONLY the corrected complete field value with the exact same JSON key, without labels or explanation. Use the configured target language and locale. Preserve meaning, brands, product names, established technical abbreviations, quotations, numeric values, original currency identity, billing relationships and facts. Correct the reported issue; do not add information or switch to another natural language.',
                0,
                'TITLE REPAIR'
            );
            if (is_wp_error($retry) || empty($retry[$field_key])) {
                return is_wp_error($retry)
                    ? $retry
                    : new WP_Error('wpmu_ml_wrong_target_language', $field_key . ' 目标语言重试未返回有效内容。');
            }
            $candidate = $this->openai_polish_target_text_fragment((string)$retry[$field_key], $field_key);
            $second_audit = $this->openai_audit_target_language_fields(
                [$field_key => $source_value],
                [$field_key => $candidate],
                $target_label,
                $settings
            );
            if (is_wp_error($second_audit)) {
                return $second_audit;
            }
            if (!empty($second_audit[$field_key])) {
                return new WP_Error(
                    'wpmu_ml_wrong_target_language',
                    $field_key . ' 重试后仍不是后台配置的目标语言：' . $second_audit[$field_key]
                );
            }
            $translated_fields[$field_key] = $candidate;
        }

        $editorial_fields = [];
        $fast_quality = $this->openai_fast_quality_enabled($settings);
        foreach (['title', 'excerpt'] as $field_key) {
            $source_value = (string)($source_fields[$field_key] ?? '');
            $target_value = (string)($translated_fields[$field_key] ?? '');
            if ($source_value === '' || $target_value === '') {
                continue;
            }
            // The excerpt has already passed the source/target language audit above.
            // Auditing the same long field again is expensive on reasoning models and,
            // on some compatible gateways, frequently returns an empty success shell.
            // Fast mode therefore reserves the separate editorial pass for the title;
            // the excerpt is still covered by deterministic checks and the final deterministic publication checks.
            if ($fast_quality && $field_key === 'excerpt') {
                $this->openai_cli_trace_line(sprintf(
                    'TITLE/EXCERPT EDITOR FAST SKIP field=excerpt reason=duplicate_long_field_qa chars=%d',
                    $this->wpmu_ml_strlen($target_value)
                ));
                continue;
            }
            $editorial_fields[$field_key] = 'FIELD TYPE: ' . $field_key
                . "\nSOURCE FIELD:\n" . $source_value
                . "\nCURRENT TARGET FIELD:\n" . $target_value;
        }
        if ($editorial_fields) {
            $editorial_statuses = $this->openai_request_fields_with_recursive_split(
                $editorial_fields,
                $target_label,
                $settings,
                '[WPMU_ML_EDITORIAL_AUDIT] Review title/excerpt fields for publication quality. For every key return exactly either "keep" or "rewrite:<brief concrete reason>". Mark rewrite when the current target is mechanically literal, awkward for the target locale, semantically shifted, SEO-hostile, unnaturally long, contains duplicated brand/product wording, mistranslates a product category, changes a price/currency/billing relationship, turns a rhetorical comparison into a different factual claim, or would look non-native on a professional technical website. Use locale-natural currency-code placement, duration wording and service/hosting terminology rather than literal source ordering. Pay special attention to duplicated or mechanically calqued vendor-plus-category wording: rewrite it into a natural target-language form unless the repeated wording is the verified official product name. Do not flag correct wording merely for subjective preference. Preserve facts, brands, product names, numbers, currency identity and search intent.',
                0,
                'TITLE EDITOR'
            );
            if (is_wp_error($editorial_statuses)) {
                // Title/excerpt editorial audit is a polish layer. If the local model/API
                // fails to return JSON for this optional audit, keep the already translated
                // fields that passed target-language QA; do not fail the whole article.
                $this->openai_cli_trace_line('TITLE/EXCERPT EDITOR QA status=partial reason=' . $editorial_statuses->get_error_message());
                // This is an optional polish pass after the required language audit.
                // A gateway availability failure must not discard a valid translation or
                // restart the whole article. The final deterministic and article checks
                // still decide whether publication is allowed.
                return $translated_fields;
            }
            $repair_sources = [];
            foreach ($editorial_fields as $field_key => $unused) {
                $parsed_status = $this->openai_parse_editorial_audit_status($editorial_statuses[$field_key] ?? '');
                $action = (string)($parsed_status['action'] ?? 'invalid');
                $reason = trim((string)($parsed_status['reason'] ?? ''));
                $this->openai_cli_trace_line(sprintf(
                    'TITLE/EXCERPT EDITOR QA field=%s action=%s reason=%s returned=%s',
                    (string)$field_key,
                    $action,
                    $reason !== '' ? $this->openai_cli_trace_snippet($reason, 300) : '-',
                    $this->openai_cli_trace_snippet((string)($editorial_statuses[$field_key] ?? ''), 300)
                ));
                if ($action === 'rewrite') {
                    $repair_sources[$field_key] = 'FIELD TYPE: ' . $field_key
                        . "\nSOURCE FIELD:\n" . (string)($source_fields[$field_key] ?? '')
                        . "\nCURRENT TARGET FIELD:\n" . (string)($translated_fields[$field_key] ?? '')
                        . "\nEDITOR ISSUE:\n" . ($reason !== '' ? $reason : 'publication quality issue');
                }
            }
            if ($repair_sources) {
                $this->openai_cli_trace_line(sprintf('TITLE/EXCERPT EDITOR QA rewrite=%d', count($repair_sources)));
                $repaired = $this->openai_request_fields_with_recursive_split(
                    $repair_sources,
                    $target_label,
                    $settings,
                    'Each value contains FIELD TYPE, SOURCE FIELD, CURRENT TARGET FIELD and EDITOR ISSUE. Return ONLY the corrected publication-quality target-language title or excerpt for each key, without labels, diagnosis or explanation. Preserve meaning, facts, brand names, product names, numeric values, original currency identity, billing relationships and search intent. Resolve colloquial or rhetorical cost wording naturally without inventing a budget, salary claim, currency conversion or unsupported factual relationship. Avoid source-language syntax, duplicated brand/product wording, semantic reversal, awkward category names and overly long SEO titles. Return ONLY valid JSON with the exact same keys.',
                    0,
                    'TITLE EDITOR REPAIR'
                );
                if (is_wp_error($repaired)) {
                    $this->openai_cli_trace_line('TITLE/EXCERPT EDITOR REPAIR status=partial reason=' . $repaired->get_error_message());
                    return $translated_fields;
                }
                $repair_audit_sources = [];
                foreach ($repair_sources as $field_key => $unused) {
                    $repair_audit_sources[$field_key] = (string)($source_fields[$field_key] ?? '');
                }
                $repair_audit = $this->openai_audit_target_language_fields($repair_audit_sources, $repaired, $target_label, $settings);
                if (is_wp_error($repair_audit)) {
                    $this->openai_cli_trace_line('TITLE/EXCERPT EDITOR REPAIR audit status=unavailable reason=' . $repair_audit->get_error_message());
                    return $translated_fields;
                }
                foreach ($repair_sources as $field_key => $source_value) {
                    if (empty($repaired[$field_key]) || !empty($repair_audit[$field_key])) {
                        continue;
                    }
                    $candidate = $this->openai_polish_target_text_fragment((string)$repaired[$field_key], $field_key);
                    $translated_fields[$field_key] = $this->openai_localize_target_typography_fragment($candidate, $target_label, $settings);
                }
            }
        }

        return $translated_fields;
    }

    /** Legacy compatibility shim: title language rules are now configured, not hard-coded. */

    private function openai_japanese_title_needs_rewrite($title) {
        return false;
    }

    private function openai_preserve_fragment_boundary_whitespace($source, $translated) {
        $source = (string)$source;
        $translated = (string)$translated;
        $leading = '';
        $trailing = '';
        if (preg_match('/^\s+/u', $source, $m)) {
            $leading = (string)$m[0];
        }
        if (preg_match('/\s+$/u', $source, $m)) {
            $trailing = (string)$m[0];
        }
        $core = preg_replace('/^\s+|\s+$/u', '', $translated);
        if (!is_string($core)) {
            $core = $translated;
        }
        return $leading . $core . $trailing;
    }

    private function openai_translate_residual_source_text($source_content, $content, $target_label, $settings, $task_instruction = '') {
        // 0.9.6.3 compatibility shim. Local residue detection is advisory-only and must never
        // initiate a rewrite. Optional AI QA receives residue hints through the central review.
        return (string)$content;
    }

    /** Backward-compatible wrapper for the pre-0.8.17.30 private method name. */

    private function openai_translate_residual_body_cjk_text($source_content, $content, $target_label, $settings, $task_instruction = '') {
        return $this->openai_translate_residual_source_text($source_content, $content, $target_label, $settings, $task_instruction);
    }
    }
}
