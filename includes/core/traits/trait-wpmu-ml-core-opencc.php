<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenCC 翻译引擎。
 *
 * 本 trait 仅用于拆分历史 WPMU_Multilingual 大类；方法签名和可见性保持不变。
 */
if (!trait_exists('WPMU_ML_Core_OpenCC_Trait')) {
    trait WPMU_ML_Core_OpenCC_Trait {
    private function process_opencc_translation_job($job, $engine = 'opencc_s2twp') {
        global $wpdb;
        $job_id = (int)$job['id'];
        $attempts = ((int)$job['attempts']) + 1;
        $settings = $this->get_settings();
        $engine = $this->normalize_translation_engine_key($engine ?: ($job['engine'] ?? ''), $job['target_lang'] ?? '');
        $settings['opencc_config'] = $this->get_opencc_config_for_engine($engine, $settings);

        if (!$this->is_traditional_chinese_lang($job['target_lang'] ?? '')) {
            return $this->fail_translation_job($job, $attempts, 'OpenCC 仅用于繁体中文目标语言，本任务目标语言不是繁体相关语言。');
        }

        $source_post = null;
        switch_to_blog((int)$job['source_blog_id']);
        $source_post = get_post((int)$job['source_post_id']);
        restore_current_blog();

        if (!$source_post) {
            return $this->fail_translation_job($job, $attempts, '源文章不存在。');
        }
        $translate_title = $this->translation_job_selects_core_field($job, 'post_title');
        $translate_excerpt = $this->translation_job_selects_core_field($job, 'post_excerpt');
        $translate_content = $this->translation_job_selects_core_field($job, 'post_content');
        $slug_policy = $this->get_translation_job_slug_policy($job);
        if (is_wp_error($slug_policy)) {
            return $this->fail_translation_job($job, $attempts, '目标文章策略校验失败：' . $slug_policy->get_error_message());
        }
        $target_slug = (string)$slug_policy['target_slug'];

        $converted_title = $translate_title ? $this->opencc_convert_text((string)$source_post->post_title, $settings) : '';
        $converted_excerpt = $translate_excerpt ? $this->opencc_convert_text((string)$source_post->post_excerpt, $settings) : '';
        $converted_content = $translate_content ? $this->opencc_convert_html((string)$source_post->post_content, $settings) : '';

        if (($translate_title && $converted_title === false) || ($translate_excerpt && $converted_excerpt === false) || ($translate_content && $converted_content === false)) {
            return $this->fail_translation_job($job, $attempts, 'OpenCC 执行失败：未找到可用 opencc 命令，或命令未产生有效输出。请先在服务器安装 opencc，并检查“翻译引擎”里的 OpenCC 服务器设置。');
        }

        $complete_status = $this->normalize_translation_status($job['complete_status'] ?? '', $this->get_translation_complete_status_for_lang($job['target_lang']));
        $complete_status = $this->enforce_translation_target_status($job, $complete_status);

        $slug_validation = $this->validate_target_slug_availability(
            (int)$job['target_blog_id'],
            (int)$job['target_post_id'],
            $target_slug,
            (string)$source_post->post_type
        );
        if (is_wp_error($slug_validation)) {
            return $this->fail_translation_job($job, $attempts, '目标文章 slug 冲突：' . $slug_validation->get_error_message());
        }

        switch_to_blog((int)$job['target_blog_id']);
        // v0.7.20：OpenCC 转换后的正文里会保留代码转义序列，例如 \n、\t、\r、\0、\x0B。
        // wp_insert_post/wp_update_post 内部会执行 wp_unslash()，所以这里必须像 OpenAI 兼容写入路径一样先 wp_slash()。
        // 否则代码块里的 "\n" 会被保存成 "n"，" \t\n\r\0\x0B" 会被保存成 " tnrx0B"。
        $post_update = [
            'ID' => (int)$job['target_post_id'],
            'post_name' => $target_slug,
            'post_status' => $complete_status,
        ];
        if ($translate_title) {
            $post_update['post_title'] = $converted_title;
        }
        if ($translate_excerpt) {
            $post_update['post_excerpt'] = $converted_excerpt;
        }
        if ($translate_content) {
            $post_update['post_content'] = $converted_content;
        }
        $update_result = wp_update_post(wp_slash($post_update), true);

        if (!is_wp_error($update_result) && !empty($settings['opencc_convert_meta'])) {
            $this->opencc_convert_target_post_meta((int)$job['target_post_id'], $settings, $this->translation_job_meta_keys($job));
        }
        restore_current_blog();

        if (is_wp_error($update_result)) {
            return $this->fail_translation_job($job, $attempts, '写入目标文章失败：' . $update_result->get_error_message());
        }
        $slug_lock = $this->force_target_slug_from_source((int)$job['source_blog_id'], (int)$job['source_post_id'], (int)$job['target_blog_id'], (int)$job['target_post_id']);
        if (is_wp_error($slug_lock)) {
            return $this->fail_translation_job($job, $attempts, '目标文章 slug 强制锁定失败：' . $slug_lock->get_error_message());
        }
        $this->mark_translation_content_completed($job);

        $status = $complete_status === 'publish' ? 'opencc_done_published' : 'opencc_converted';
        $relation_status = $complete_status === 'publish' ? 'translated' : 'machine_translated';
        $updated = $wpdb->update($this->tables['jobs'], [
            'engine' => $engine,
            'status' => $status,
            'attempts' => $attempts,
            'last_error' => 'OpenCC 简转繁完成（' . $this->opencc_engine_label($engine) . '），目标文章状态：' . $complete_status,
            'locked_at' => null,
            'locked_by' => '',
            'process_after' => null,
            'finished_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $job_id], ['%s','%s','%d','%s','%s','%s','%s','%s','%s'], ['%d']);

        $this->update_relation_for_job($job, $relation_status);
        $this->log('info', 'translation_opencc_done', 'OpenCC 简转繁完成', [
            'job_id' => $job_id,
            'target_lang' => $job['target_lang'],
            'target_status' => $complete_status,
            'opencc_engine' => $engine,
            'opencc_config' => $settings['opencc_config'],
        ]);
        return $updated !== false;
    }

    private function opencc_convert_target_post_meta($post_id, $settings, $allowed_meta_keys = null) {
        $all_meta = get_post_meta($post_id);
        if (!is_array($all_meta) || empty($all_meta)) {
            return;
        }

        foreach ($all_meta as $meta_key => $values) {
            if (is_array($allowed_meta_keys) && !in_array((string)$meta_key, $allowed_meta_keys, true)) {
                continue;
            }
            if ($this->opencc_should_skip_meta_key($meta_key, $settings)) {
                continue;
            }

            $new_values = [];
            $changed = false;

            foreach ((array)$values as $value) {
                $unserialized = maybe_unserialize($value);
                $converted = $this->opencc_convert_meta_value($unserialized, $settings);
                if ($converted !== $unserialized) {
                    $changed = true;
                }
                $new_values[] = $converted;
            }

            if ($changed) {
                delete_post_meta($post_id, $meta_key);
                foreach ($new_values as $new_value) {
                    add_post_meta($post_id, $meta_key, $new_value, false);
                }
            }
        }
    }

    private function opencc_should_skip_meta_key($meta_key, $settings) {
        $meta_key = (string)$meta_key;
        $skip_exact = [
            '_edit_lock', '_edit_last', '_thumbnail_id', '_wp_attached_file', '_wp_attachment_metadata',
            '_wp_page_template', '_wp_old_slug', '_menu_item_type', '_menu_item_menu_item_parent',
            '_menu_item_object_id', '_menu_item_object', '_menu_item_target', '_menu_item_classes',
            '_menu_item_xfn', '_menu_item_url', '_acf_changed', '_wpml_media_featured', '_wpml_media_duplicate',
        ];
        if (in_array($meta_key, $skip_exact, true)) {
            return true;
        }

        if (strpos($meta_key, '_') === 0) {
            $seo_allow = !empty($settings['opencc_convert_seo_meta']) && preg_match('/^_(yoast_wpseo_title|yoast_wpseo_metadesc|aioseo_title|aioseo_description)$/', $meta_key);
            return !$seo_allow;
        }

        if (preg_match('/^(rank_math_focus_keyword|rank_math_title|rank_math_description|rank_math_facebook_title|rank_math_facebook_description|rank_math_twitter_title|rank_math_twitter_description)$/', $meta_key)) {
            return empty($settings['opencc_convert_seo_meta']);
        }

        return false;
    }

    private function opencc_convert_meta_value($value, $settings) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->opencc_convert_meta_value($v, $settings);
            }
            return $out;
        }
        if (is_object($value)) {
            foreach ($value as $k => $v) {
                $value->$k = $this->opencc_convert_meta_value($v, $settings);
            }
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return $value;
        }
        if (!$this->opencc_string_should_convert($value)) {
            return $value;
        }
        $converted = $this->opencc_convert_text($value, $settings);
        return $converted === false ? $value : $converted;
    }

    private function opencc_string_should_convert($text) {
        if (!is_string($text) || trim($text) === '') {
            return false;
        }
        if (preg_match('~^(https?:)?//|^mailto:|^tel:|^[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}$~i', trim($text))) {
            return false;
        }
        if (!preg_match('/[\x{4e00}-\x{9fff}]/u', $text)) {
            return false;
        }
        return true;
    }

    private function opencc_convert_html($html, $settings) {
        if (!is_string($html) || $html === '') {
            return $html;
        }

        // v0.7.19：繁体 OpenCC 不再整段转换 <pre> 代码块。
        // 代码块先走逐行片段级转换，并保护反斜杠转义符，再保护整块，避免语法和转义序列被 OpenCC 误伤。
        if ($this->contains_cjk_text($html)) {
            $html = preg_replace_callback('~<pre\b[^>]*>.*?</pre>~is', function($m) use ($settings) {
                return $this->opencc_convert_human_text_in_code_block((string)$m[0], $settings);
            }, $html);
            if (!is_string($html)) {
                return false;
            }
        }

        $restore_map = [];
        $protected = $this->opencc_protect_blocks($html, $restore_map);
        $converted = $this->opencc_convert_text($protected, $settings);
        if ($converted === false) {
            return false;
        }
        return strtr($converted, $restore_map);
    }

    private function opencc_convert_human_text_in_code_block($block_html, $settings) {
        $block_html = (string)$block_html;
        if ($block_html === '' || !$this->contains_cjk_text($block_html)) {
            return $block_html;
        }

        /**
         * v0.7.19：OpenCC 也必须走和 OpenAI 兼容一样的“逐行硬锁 + 片段级转换”。
         * 不能再把块注释、字符串 value 或整段代码交给 OpenCC 直接转换，否则 OpenCC/后处理
         * 可能把 \t\n\r\0\x0B 这类转义符变成普通 tnrx0B，或把 PHPDoc 结束标记合并到上一行。
         */
        $escape_map = [];
        $work = $this->opencc_protect_code_escape_sequences($block_html, $escape_map);

        $tokens = [];
        $counter = 0;
        $collect = function($kind, $prefix, $text, $suffix, $quote = '') use (&$tokens, &$counter) {
            $text = (string)$text;
            if (!$this->contains_cjk_text($text)) {
                return (string)$prefix . $text . (string)$suffix;
            }
            $token = '%%WPMU_ML_OPENCC_CODE_TEXT_' . $counter++ . '%%';
            $tokens[$token] = [
                'kind' => (string)$kind,
                'prefix' => (string)$prefix,
                'text' => $text,
                'suffix' => (string)$suffix,
                'quote' => (string)$quote,
            ];
            return $token;
        };

        $parts = preg_split('/(\r\n|\n|\r)/', (string)$work, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $block_html;
        }

        foreach ($parts as $i => $part) {
            if ($part === '' || $part === "\n" || $part === "\r" || $part === "\r\n" || !$this->contains_cjk_text($part)) {
                continue;
            }
            $parts[$i] = $this->openai_tokenize_code_line_locked((string)$part, $collect);
        }

        if (!$tokens) {
            return $block_html;
        }

        foreach ($parts as $i => $part) {
            if ($part === '' || $part === "\n" || $part === "\r" || $part === "\r\n") {
                continue;
            }
            foreach ($tokens as $token => $info) {
                if (strpos((string)$part, (string)$token) === false) {
                    continue;
                }
                $converted = $this->opencc_convert_text((string)$info['text'], $settings);
                if ($converted === false || !is_string($converted) || $converted === '') {
                    $converted = (string)$info['text'];
                }
                $text = $this->opencc_normalize_code_fragment_conversion(
                    $converted,
                    (string)$info['text'],
                    (string)($info['kind'] ?? 'text')
                );

                // v0.7.21：OpenCC 是确定性的简繁转换，不应像 AI 译文那样 trim 片段。
                // 字符串输出里的前后空格、分隔符后的空格必须保留，例如：
                // "处理结果: " -> "處理結果: "，" | 计算结果: " -> " | 計算結果: "。
                $parts[$i] = str_replace((string)$token, (string)$info['prefix'] . (string)$text . (string)$info['suffix'], (string)$parts[$i]);
            }
            $parts[$i] = str_replace(["\r\n", "\n", "\r"], ' ', (string)$parts[$i]);
        }

        $out = implode('', $parts);
        $out = strtr((string)$out, $escape_map);

        // 最后一道保险：OpenCC 代码块转换不得改变真实换行序列。若出问题，保留片段转换前的结构底板，
        // 但仍恢复所有转义符，避免写入损坏代码。
        if ($this->openai_linebreak_signature($out) !== $this->openai_linebreak_signature($block_html)) {
            $fallback = strtr((string)$work, $escape_map);
            return $this->openai_linebreak_signature($fallback) === $this->openai_linebreak_signature($block_html) ? $fallback : $block_html;
        }

        return (string)$out;
    }

    private function opencc_normalize_code_fragment_conversion($converted, $source, $kind = 'text') {
        $converted = (string)$converted;
        $source = (string)$source;
        $kind = sanitize_key((string)$kind);

        if ($converted === '') {
            return $source;
        }

        // OpenCC should never introduce real line breaks into a line-locked code fragment.
        // If a CLI/environment adds a line break, collapse it without trimming the source's intended edges.
        if (strpos($source, "\n") === false && strpos($source, "\r") === false) {
            $converted = preg_replace('~<br\s*/?>~i', ' ', $converted);
            $converted = preg_replace('~&(?:#10|#x0a|NewLine);~i', ' ', (string)$converted);
            $converted = str_replace(["\r\n", "\n", "\r"], ' ', (string)$converted);
        }

        // Preserve the exact leading/trailing whitespace from the source fragment.
        // This is critical for code strings such as "处理结果: ", " | 计算结果: ", and " 毫秒".
        $leading = '';
        $trailing = '';
        if (preg_match('/^\s+/u', $source, $m)) {
            $leading = (string)$m[0];
        }
        if (preg_match('/\s+$/u', $source, $m)) {
            $trailing = (string)$m[0];
        }

        $core = preg_replace('/^\s+/u', '', (string)$converted);
        $core = preg_replace('/\s+$/u', '', (string)$core);
        if (!is_string($core)) {
            $core = (string)$converted;
        }

        // OpenCC should not return comment or quote wrappers, but strip them defensively
        // while still restoring the original source boundary spaces.
        if ($kind === 'comment') {
            $core = preg_replace('~^\s*/\*+\s*(.*?)\s*\*/\s*$~s', '$1', (string)$core);
            $core = preg_replace('~^\s*<!--\s*(.*?)\s*-->\s*$~s', '$1', (string)$core);
            $core = preg_replace('~^\s*&lt;!--\s*(.*?)\s*--&gt;\s*$~s', '$1', (string)$core);
            $core = preg_replace('~^\s*(//|#|--)\s*~', '', (string)$core);
            $core = preg_replace('~\s*\*/\s*$~', '', (string)$core);
            $core = trim((string)$core);
        } elseif ($kind === 'string' || $kind === 'string_part' || $kind === 'text') {
            if ((strlen((string)$core) >= 2) && ((substr((string)$core, 0, 1) === '"' && substr((string)$core, -1) === '"') || (substr((string)$core, 0, 1) === "'" && substr((string)$core, -1) === "'"))) {
                $core = substr((string)$core, 1, -1);
            }
            if (preg_match('~^(&quot;|&#039;|&apos;)(.*)\1$~s', (string)$core, $qm)) {
                $core = (string)$qm[2];
            }
            // Do not trim after wrapper stripping; source boundary spaces are re-applied below.
            $core = preg_replace('/^\s+/u', '', (string)$core);
            $core = preg_replace('/\s+$/u', '', (string)$core);
        }

        if ($core === '' && $source !== '') {
            return $source;
        }
        return $leading . (string)$core . $trailing;
    }

    private function opencc_protect_code_escape_sequences($code, &$restore_map) {
        $restore_map = [];
        $code = (string)$code;
        if ($code === '' || strpos($code, '\\') === false) {
            return $code;
        }

        $counter = 0;
        $pattern = '~\\\\(?:x[0-9A-Fa-f]{1,2}|u\\{[0-9A-Fa-f]+\\}|u[0-9A-Fa-f]{4}|[0-7]{1,3}|[abfnrtv0"\'\\\\$]|.)~u';
        $out = preg_replace_callback($pattern, function($m) use (&$restore_map, &$counter) {
            $token = '%%WPMU_ML_OPENCC_ESC_' . $counter++ . '%%';
            $restore_map[$token] = (string)$m[0];
            return $token;
        }, $code);

        return is_string($out) ? $out : $code;
    }

    private function opencc_protect_blocks($html, &$restore_map) {
        $restore_map = [];
        $counter = 0;
        $patterns = [
            '~<(script|style|pre|code|textarea)\b[^>]*>.*?</\1>~is',
            '~\[[a-zA-Z0-9_\-]+(?:\s[^\]]*)?\](?:.*?\[/[a-zA-Z0-9_\-]+\])?~s',
            '~https?://[^\s<>"]+~i',
        ];
        foreach ($patterns as $pattern) {
            $html = preg_replace_callback($pattern, function($m) use (&$restore_map, &$counter) {
                $placeholder = 'WPMUML_OPENCC_PROTECT_' . $counter . '_TOKEN';
                $restore_map[$placeholder] = $m[0];
                $counter++;
                return $placeholder;
            }, $html);
        }

        $html = preg_replace_callback('~<([a-zA-Z][a-zA-Z0-9:_-]*)\b([^>]*(?:data-no-opencc|translate=(?:"|\')no(?:"|\'))[^>]*)>.*?</\1>~is', function($m) use (&$restore_map, &$counter) {
            $placeholder = 'WPMUML_OPENCC_PROTECT_' . $counter . '_TOKEN';
            $restore_map[$placeholder] = $m[0];
            $counter++;
            return $placeholder;
        }, $html);

        return $html;
    }

    private function opencc_convert_text($text, $settings) {
        if (!is_string($text) || $text === '') {
            return $text;
        }
        if (!$this->opencc_string_should_convert($text)) {
            return $text;
        }

        if (!function_exists('shell_exec')) {
            return false;
        }

        $binaries = $this->get_opencc_binary_candidates($settings);
        $config = $this->get_opencc_config($settings);
        $tmp_in = function_exists('wp_tempnam') ? wp_tempnam('wpmu_ml_opencc_in') : tempnam(sys_get_temp_dir(), 'wpmu_ml_opencc_in');
        $tmp_out = function_exists('wp_tempnam') ? wp_tempnam('wpmu_ml_opencc_out') : tempnam(sys_get_temp_dir(), 'wpmu_ml_opencc_out');

        if (!$tmp_in || !$tmp_out) {
            return false;
        }
        file_put_contents($tmp_in, $text);
        $converted = false;

        foreach ($binaries as $binary) {
            if ($binary === '') {
                continue;
            }
            $command = escapeshellarg($binary)
                . ' -c ' . escapeshellarg($config)
                . ' -i ' . escapeshellarg($tmp_in)
                . ' -o ' . escapeshellarg($tmp_out)
                . ' 2>&1';
            @shell_exec($command);
            $candidate = @file_get_contents($tmp_out);
            if (is_string($candidate) && $candidate !== '') {
                $converted = $candidate;
                break;
            }
            @file_put_contents($tmp_out, '');
        }

        if (is_string($tmp_in) && $tmp_in !== '') {
            wp_delete_file($tmp_in);
        }
        if (is_string($tmp_out) && $tmp_out !== '') {
            wp_delete_file($tmp_out);
        }

        return $converted;
    }

    private function get_opencc_binary_candidates($settings) {
        $candidates = [];
        $configured = trim((string)($settings['opencc_binary_path'] ?? ''));
        if ($configured !== '') {
            $candidates[] = $configured;
        }
        $candidates[] = '/usr/bin/opencc';
        $candidates[] = '/usr/local/bin/opencc';
        $candidates[] = 'opencc';
        if (function_exists('shell_exec')) {
            $resolved = @shell_exec('command -v opencc 2>/dev/null');
            $resolved = is_string($resolved) ? trim($resolved) : '';
            if ($resolved !== '') {
                $candidates[] = $resolved;
            }
        }
        return array_values(array_unique(array_filter($candidates)));
    }

    private function get_opencc_config($settings) {
        return $this->sanitize_opencc_config($settings['opencc_config'] ?? 's2twp.json', 's2twp.json');
    }

    private function get_opencc_config_for_engine($engine, $settings) {
        $engine = sanitize_key($engine);
        $map = [
            'opencc_s2twp' => 's2twp.json',
            'opencc_s2tw'  => 's2tw.json',
            'opencc_s2hk'  => 's2hk.json',
            'opencc_s2t'   => 's2t.json',
        ];
        return $this->sanitize_opencc_config($map[$engine] ?? ($settings['opencc_config'] ?? 's2twp.json'), 's2twp.json');
    }

    private function opencc_engine_label($engine) {
        $engine = sanitize_key($engine);
        $labels = $this->get_opencc_translation_engines();
        return $labels[$engine] ?? $engine;
    }

    private function sanitize_opencc_config($value, $fallback = 's2twp.json') {
        $value = is_string($value) ? wp_unslash($value) : '';
        $value = strtolower(trim($value));
        $value = basename($value);
        $value = preg_replace('/[^a-z0-9_\.-]/', '', $value);

        $aliases = [
            's2twp' => 's2twp.json',
            's2tw'  => 's2tw.json',
            's2hk'  => 's2hk.json',
            's2t'   => 's2t.json',
        ];
        if (isset($aliases[$value])) {
            $value = $aliases[$value];
        }

        $allowed = ['s2twp.json','s2tw.json','s2hk.json','s2t.json'];
        if (in_array($value, $allowed, true)) {
            return $value;
        }

        $fallback = is_string($fallback) ? strtolower(trim(basename($fallback))) : 's2twp.json';
        if (isset($aliases[$fallback])) {
            $fallback = $aliases[$fallback];
        }

        return in_array($fallback, $allowed, true) ? $fallback : 's2twp.json';
    }
    }
}
