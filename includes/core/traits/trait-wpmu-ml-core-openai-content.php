<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI 可见文本、HTML、代码与区块处理。
 *
 * 本 trait 仅用于拆分历史 WPMU_Multilingual 大类；方法签名和可见性保持不变。
 */
if (!trait_exists('WPMU_ML_Core_OpenAI_Content_Trait')) {
    trait WPMU_ML_Core_OpenAI_Content_Trait {
    private function openai_extract_visible_text_parts($content, $protected_regex) {
        $content = (string)$content;
        $parts = [];
        $cursor = 0;
        if (preg_match_all($protected_regex, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $start = (int)$match[1];
                if ($start > $cursor) {
                    $parts[] = [
                        'offset' => $cursor,
                        'length' => $start - $cursor,
                        'text' => substr($content, $cursor, $start - $cursor),
                    ];
                }
                $cursor = $start + strlen((string)$match[0]);
            }
        }
        if ($cursor < strlen($content)) {
            $parts[] = [
                'offset' => $cursor,
                'length' => strlen($content) - $cursor,
                'text' => substr($content, $cursor),
            ];
        }
        return array_values($parts);
    }

    private function openai_target_primary_code($settings, $target_label = '') {
        $target_context = is_array($settings['openai_target_language_context'] ?? null)
            ? $settings['openai_target_language_context']
            : [];
        $primary = sanitize_key((string)($target_context['primary'] ?? ''));
        if ($primary !== '') {
            return $primary;
        }
        $token = strtolower(str_replace('_', '-', trim((string)(
            $target_context['translation_locale']
            ?? $target_context['locale']
            ?? $target_context['hreflang']
            ?? $target_label
        ))));
        return sanitize_key((string)strtok($token, '-'));
    }

    private function openai_fragment_contains_source_language_residue($source, $translated, $settings, $target_label = '') {
        $source_plain = $this->openai_language_audit_excerpt((string)$source, 1400);
        $target_plain = $this->openai_language_audit_excerpt((string)$translated, 1400);
        if ($target_plain === '') {
            return false;
        }

        $source_context = is_array($settings['openai_source_language_context'] ?? null)
            ? $settings['openai_source_language_context']
            : [];
        $target_context = is_array($settings['openai_target_language_context'] ?? null)
            ? $settings['openai_target_language_context']
            : [];
        $source_primary = sanitize_key((string)($source_context['primary'] ?? ''));
        $target_primary = sanitize_key((string)($target_context['primary'] ?? ''));
        if ($source_primary !== '' && $target_primary !== '' && $source_primary === $target_primary) {
            return false;
        }

        // For non-CJK target languages, visible Han characters in ordinary body text are
        // source-language residue even if the source/target visible-part arrays do not align
        // perfectly. Latin brands/technical tokens can remain, but Chinese product names need
        // translation or configured glossary handling. Japanese and Chinese targets are
        // excluded because Han characters are normal there.
        if (!in_array($target_primary, ['ja', 'zh', 'zh-hans', 'zh-hant'], true)
            && preg_match('/[\x{3400}-\x{9FFF}]/u', $target_plain)) {
            return true;
        }

        if ($source_plain === '') {
            return false;
        }

        // Exact URLs, host names, filenames, product tokens and numbered brand labels are
        // legitimate cross-language invariants. They must not be sent to residual translation
        // merely because source and target are identical.
        if ($this->openai_language_invariant_pair($source_plain, $target_plain)) {
            return false;
        }

        $source_norm = preg_replace('/[\s\x{00A0}\p{P}\p{S}]+/u', '', $source_plain);
        $target_norm = preg_replace('/[\s\x{00A0}\p{P}\p{S}]+/u', '', $target_plain);
        if (!is_string($source_norm) || !is_string($target_norm) || $source_norm === '' || $target_norm === '') {
            return false;
        }
        if (!$this->openai_language_audit_should_check($source_plain, $target_plain)) {
            return false;
        }

        if ($source_norm === $target_norm) {
            return true;
        }

        $source_length = function_exists('mb_strlen') ? mb_strlen($source_norm, 'UTF-8') : strlen($source_norm);
        return $source_length >= 12 && strpos($target_norm, $source_norm) !== false;
    }

    private function openai_contains_human_language_text($text) {
        return is_string($text) && preg_match('/\p{L}/u', $text);
    }

    /**
     * Language-neutral source-text gate used by the OpenAI translation path.
     * Any human-readable value containing a letter is a translation candidate unless
     * its complete shape is a high-confidence machine token. This deliberately accepts
     * short mixed values such as prices, billing periods and compact specifications.
     */

    private function openai_contains_translatable_source_text($text) {
        if (!is_string($text) || trim($text) === '') {
            return false;
        }
        $plain = html_entity_decode(wp_strip_all_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/[\s\x{00A0}]+/u', ' ', trim((string)$plain));
        if (!is_string($plain) || $plain === '' || !preg_match('/\p{L}/u', $plain)) {
            return false;
        }

        if (preg_match('~^(?:(?:https?:)?//|mailto:|tel:)\S+$~iu', $plain)) {
            return false;
        }
        if (preg_match('/^[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,63}$/iu', $plain)) {
            return false;
        }
        if (preg_match('/^(?:\{\{[^{}]+\}\}|\$\{[^{}]+\}|__[A-Z0-9_\-]+__|%[0-9$]*[bcdeEfFgGosuxX])$/u', $plain)) {
            return false;
        }

        // Protect clear paths, filenames, namespaces and code identifiers. A bare word,
        // hyphenated phrase or mixed source-language specification is not automatically
        // a machine token and must remain eligible for contextual AI localization.
        if (!preg_match('/\s/u', $plain)
            && $this->wpmu_ml_strlen($plain) <= 160
            && preg_match('/^[A-Za-z0-9_@#%+.,:\/\\\-]+$/u', $plain)
            && preg_match('/[._:\/\\@#]/u', $plain)) {
            return false;
        }
        if (strpos($plain, '_') !== false
            && preg_match('/^[\p{L}\p{M}\p{N}_-]+$/u', $plain)
            && $this->wpmu_ml_strlen($plain) <= 64) {
            return false;
        }
        return true;
    }

    private function openai_repair_translated_code_blocks_against_source($source_content, $translated_content) {
        $source_content = (string)$source_content;
        $translated_content = (string)$translated_content;
        if ($source_content === '' || $translated_content === '' || stripos($translated_content, '<pre') === false) {
            return $translated_content;
        }

        preg_match_all('~<pre\b[^>]*>.*?</pre>~is', $source_content, $source_matches);
        preg_match_all('~<pre\b[^>]*>.*?</pre>~is', $translated_content, $translated_matches, PREG_OFFSET_CAPTURE);
        $source_blocks = $source_matches[0] ?? [];
        $target_blocks = $translated_matches[0] ?? [];
        if (!$source_blocks || !$target_blocks) {
            return $translated_content;
        }

        $limit = min(count($source_blocks), count($target_blocks));
        $replacements = [];
        for ($i = 0; $i < $limit; $i++) {
            $source_block = (string)$source_blocks[$i];
            $target_block = (string)$target_blocks[$i][0];
            if ($source_block === '' || $target_block === '') {
                continue;
            }

            $repaired = $this->openai_repair_accidentally_wrapped_code_assignments($source_block, $target_block);
            $repaired = $this->openai_repair_concat_operator_linebreaks($source_block, (string)$repaired);
            // Final narrow pass: merge accidental operator breaks in code blocks only.
            $repaired = preg_replace('/([.\+,])\s*(?:\r\n|\n|\r)\s*(?=(?:\(|\$|[A-Za-z_][A-Za-z0-9_]*\s*\(|[0-9]))/u', '$1 ', (string)$repaired);

            // v0.7.19: final code-block repair must never cause another
            // whole-block regression. Apply it only when the target block's
            // newline signature is unchanged; otherwise keep the translated
            // fragment-level code block and let QA report any residual issue.
            if (is_string($repaired) && $repaired !== '' && $repaired !== $target_block && $this->openai_linebreak_signature($repaired) === $this->openai_linebreak_signature($target_block)) {
                $replacements[] = [$target_block, $repaired];
            }
        }

        foreach ($replacements as $pair) {
            $translated_content = $this->replace_first($translated_content, $pair[0], $pair[1]);
        }
        return $translated_content;
    }

    private function replace_first($haystack, $needle, $replacement) {
        $haystack = (string)$haystack;
        $needle = (string)$needle;
        if ($needle === '') {
            return $haystack;
        }
        $pos = strpos($haystack, $needle);
        if ($pos === false) {
            return $haystack;
        }
        return substr($haystack, 0, $pos) . (string)$replacement . substr($haystack, $pos + strlen($needle));
    }

    /**
     * Protect content that the editor explicitly marks as not requiring translation.
     * The comment-pair marker is the safest option for nested Gutenberg/custom HTML:
     * <!-- wpmu-ml:no-translate:start --> ... <!-- wpmu-ml:no-translate:end -->
     */

    private function openai_protect_explicit_no_translate_regions($content, &$protected_map, $settings = []) {
        $content = (string)$content;
        if ($content === '') {
            return $content;
        }

        $content = preg_replace_callback(
            '~<!--\s*wpmu-ml:no-translate:start\s*-->.*?<!--\s*wpmu-ml:no-translate:end\s*-->~is',
            function($m) use (&$protected_map) {
                return $this->openai_protect_region((string)$m[0], $protected_map);
            },
            $content
        );

        // Common explicit markers always work, even when the administrator does not add
        // them to the selector setting. Configured simple selectors are merged into the
        // same preservation-first matcher, which supports nested div-based Gutenberg blocks
        // without reserializing or changing the surrounding article HTML.
        $selectors = array_merge(
            [
                '[data-no-translation]',
                '[translate="no"]',
                '.notranslate',
                '.no-translate',
                '.wpmu-ml-no-translate',
            ],
            $this->get_openai_excluded_html_selectors($settings)
        );
        $selectors = array_values(array_unique($selectors));

        if (class_exists('WPMU_ML_HTML_Exclusion')) {
            $content = WPMU_ML_HTML_Exclusion::replace_selected_regions(
                (string)$content,
                $selectors,
                function($region) use (&$protected_map) {
                    return $this->openai_protect_region((string)$region, $protected_map);
                }
            );
        }

        return is_string($content) ? $content : '';
    }

    private function openai_prepare_code_regions_for_translation($content, $target_label, $settings, $task_instruction, &$protected_map) {
        $content = (string)$content;
        if ($content === '') {
            return $content;
        }

        $excluded_tags = $this->get_openai_excluded_html_tags($settings);

        // 1) 代码块：pre 默认翻译代码中的自然语言文本；script/style/textarea 整体保护。
        // 若“排除翻译标签”包含 pre，则整块 pre 保护，不翻译其中注释/字符串。
        $content = preg_replace_callback('~<(pre|script|style|textarea)\b[^>]*>.*?</\1>~is', function($m) use ($target_label, $settings, $task_instruction, &$protected_map, $excluded_tags) {
            $block = (string)$m[0];
            $tag = strtolower((string)$m[1]);

            if ($tag === 'pre' && !in_array('pre', $excluded_tags, true) && $this->get_openai_code_block_strategy($settings) === 'smart_text' && $this->openai_contains_translatable_source_text($block)) {
                $translated_block = $this->openai_translate_human_text_in_code_block($block, $target_label, $settings, $task_instruction);
                if (is_wp_error($translated_block)) {
                    return $block;
                }
                $block = (string)$translated_block;
            }

            return $this->openai_protect_region($block, $protected_map);
        }, $content);

        // 2) 行内 code：纯代码保护；含源语言说明文字时只翻译人类可读部分。
        // 若“排除翻译标签”包含 code，则所有 inline code 都保持原样；注意这会让正文 <code>文字</code> 不翻译。
        $content = preg_replace_callback('~<code\b([^>]*)>(.*?)</code>~is', function($m) use ($target_label, $settings, $task_instruction, $excluded_tags) {
            $attrs = (string)$m[1];
            $inner = (string)$m[2];

            if (!empty($settings['openai_defer_inline_code_to_semantic_block'])
                || in_array('code', $excluded_tags, true)
                || !$this->openai_contains_translatable_source_text($inner)
                || $this->get_openai_inline_code_strategy($settings) !== 'smart') {
                return $m[0];
            }

            // 多行 code 更像代码片段，不作为行内术语处理。
            $plain = html_entity_decode(wp_strip_all_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (strpos($plain, "\n") !== false || strpos($plain, "\r") !== false || $this->wpmu_ml_strlen($plain) > 160) {
                return $m[0];
            }

            $translated = $this->openai_translate_plain_text(
                $plain,
                $target_label,
                $settings,
                'Translate only source-language human-readable words inside this inline code label into the configured target language. Preserve technical tokens, function names, CSS/HTML keywords, commands, URLs, punctuation, numbers and code syntax exactly. Return ONLY valid JSON with key: text.'
            );
            if (is_wp_error($translated) || !is_string($translated) || $translated === '') {
                return $m[0];
            }

            return '<code' . $attrs . '>' . esc_html($translated) . '</code>';
        }, $content);

        return is_string($content) ? $content : '';
    }

    private function openai_protect_region($html, &$protected_map) {
        $id = count($protected_map);
        $placeholder = '<wpmuml-protected data-id="' . $id . '"></wpmuml-protected>';
        $protected_map[$placeholder] = (string)$html;
        return $placeholder;
    }

    private function openai_restore_protected_regions($content, $protected_map) {
        if (empty($protected_map) || !is_array($protected_map)) {
            return (string)$content;
        }
        return strtr((string)$content, $protected_map);
    }

    private function get_openai_code_block_strategy($settings) {
        $value = sanitize_key($settings['openai_code_block_strategy'] ?? 'smart_text');
        return $value === 'protect' ? 'protect' : 'smart_text';
    }

    private function get_openai_inline_code_strategy($settings) {
        $value = sanitize_key($settings['openai_inline_code_strategy'] ?? 'smart');
        return in_array($value, ['protect', 'smart'], true) ? $value : 'smart';
    }

    private function get_openai_excluded_html_selectors($settings) {
        $raw = (string)($settings['openai_excluded_html_tags'] ?? 'pre');
        if (class_exists('WPMU_ML_HTML_Exclusion')) {
            return WPMU_ML_HTML_Exclusion::normalize_selector_list($raw);
        }
        return ['pre'];
    }

    private function get_openai_excluded_html_tags($settings) {
        $selectors = $this->get_openai_excluded_html_selectors($settings);
        if (class_exists('WPMU_ML_HTML_Exclusion')) {
            return WPMU_ML_HTML_Exclusion::extract_tag_names($selectors);
        }
        return in_array('pre', $selectors, true) ? ['pre'] : [];
    }

    private function openai_translate_human_text_in_code_block($block_html, $target_label, $settings, $task_instruction = '') {
        $block_html = (string)$block_html;
        if ($block_html === '' || !$this->openai_contains_translatable_source_text($block_html)) {
            return $block_html;
        }

        $tokens = [];
        $texts = [];
        $counter = 0;
        $collect = function($kind, $prefix, $text, $suffix, $quote = '') use (&$tokens, &$texts, &$counter) {
            $text = (string)$text;
            if (!$this->openai_contains_translatable_source_text($text)) {
                return (string)$prefix . $text . (string)$suffix;
            }
            $token = '%%WPMU_ML_CODE_TEXT_' . $counter++ . '%%';
            $tokens[$token] = [
                'kind' => (string)$kind,
                'prefix' => (string)$prefix,
                'text' => $text,
                'suffix' => (string)$suffix,
                'quote' => (string)$quote,
            ];
            $texts[$token] = $text;
            return $token;
        };

        /**
         * v0.7.13 hard line-lock mode:
         * Do NOT let the AI return a whole code block or even a whole code line.
         * Split the original code block by real line breaks, tokenize human-language
         * snippets within each original line, translate snippets only, and restore the
         * original line-break delimiters verbatim. This prevents long strings, block-comment closers, indentation and syntax from moving across lines.
         */
        $parts = preg_split('/(\r\n|\n|\r)/', $block_html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $block_html;
        }

        foreach ($parts as $i => $part) {
            if ($part === '' || $part === "\n" || $part === "\r" || $part === "\r\n" || !$this->openai_contains_translatable_source_text($part)) {
                continue;
            }
            $parts[$i] = $this->openai_tokenize_code_line_locked((string)$part, $collect);
        }

        if (!$texts) {
            return $block_html;
        }

        $translated = $this->openai_translate_fragment_map(
            $texts,
            $target_label,
            $settings,
            'Translate ONLY the given human-language snippets extracted from code comments, quoted string values, and HTML example text. Return plain translations for each key only. Do not return code. Do not include comment markers, quotes, backticks, Markdown fences, JSON explanations, line breaks, escape sequences, variables, functions, array/object keys, URLs, paths, commands, syntax, indentation, or surrounding punctuation unless they are part of the natural-language phrase itself. Return ONLY valid JSON with the exact same keys.'
        );
        if (is_wp_error($translated)) {
            return $translated;
        }

        foreach ($parts as $i => $part) {
            if ($part === '' || $part === "\n" || $part === "\r" || $part === "\r\n") {
                continue;
            }
            foreach ($tokens as $token => $info) {
                if (strpos((string)$part, (string)$token) === false) {
                    continue;
                }
                $text = isset($translated[$token]) ? (string)$translated[$token] : (string)$info['text'];
                $text = $this->openai_normalize_code_fragment_translation($text, (string)$info['text'], (string)($info['kind'] ?? 'text'), (string)($info['prefix'] ?? ''), (string)($info['suffix'] ?? ''));
                if (in_array(($info['kind'] ?? ''), ['string', 'string_part'], true)) {
                    $text = $this->openai_escape_code_string_text($text, (string)($info['quote'] ?? ''));
                }
                // Absolute last guard: a replacement for a single-line token must never contain a real line break.
                $text = str_replace(["\r\n", "\n", "\r"], ' ', (string)$text);
                $text = preg_replace('/[ \t]{2,}/u', ' ', (string)$text);
                $parts[$i] = str_replace((string)$token, (string)$info['prefix'] . trim((string)$text) . (string)$info['suffix'], (string)$parts[$i]);
            }

            // A line segment must remain a line segment. If anything injected a newline, collapse it inside this segment only.
            $parts[$i] = str_replace(["\r\n", "\n", "\r"], ' ', (string)$parts[$i]);
        }

        $out = implode('', $parts);

        // v0.7.19: never roll back a whole code block just because one risky
        // line-repair attempt changes the line-break signature. Whole-block
        // rollback caused large code examples to revert to the source language.
        // Keep the line-locked fragment replacements as the safe baseline, and
        // only apply optional repair output when it preserves the exact newline
        // signature. If repair changes line breaks, discard the repair only.
        $safe_out = $out;
        $repaired_out = $this->openai_repair_accidentally_wrapped_code_assignments($block_html, $out);
        if (is_string($repaired_out) && $repaired_out !== '' && $this->openai_linebreak_signature($repaired_out) === $this->openai_linebreak_signature($block_html)) {
            $out = $repaired_out;
        } else {
            $out = $safe_out;
        }

        // The line-locked baseline should preserve real newline delimiters. If a
        // model still injects a line break into an individual fragment, rebuild
        // from the already-normalized per-line parts rather than returning the
        // original source-language code block.
        if ($this->openai_linebreak_signature($out) !== $this->openai_linebreak_signature($block_html)) {
            $out = $safe_out;
        }

        return $out;
    }

    private function openai_repair_concat_operator_linebreaks($source_block, $translated_block) {
        $source_block = (string)$source_block;
        $translated_block = (string)$translated_block;
        if ($source_block === '' || $translated_block === '') {
            return $translated_block;
        }

        // v0.7.19: always scan for narrow accidental continuation breaks; do not rely only on global line-break counts.

        $parts = preg_split('/(\r\n|\n|\r)/', $translated_block, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts) || count($parts) < 3) {
            return $translated_block;
        }

        $out = [];
        $count = count($parts);
        for ($i = 0; $i < $count; $i++) {
            $line = (string)$parts[$i];
            if ($line === "\n" || $line === "\r" || $line === "\r\n") {
                $out[] = $line;
                continue;
            }

            // Merge one or more obvious accidental continuation lines produced after concatenation operators.
            // Common broken pattern:  ... "text" .\n(function_call(...)) . "more";
            while (($i + 2) < $count) {
                $delimiter = (string)$parts[$i + 1];
                $next_line = (string)$parts[$i + 2];
                if (!in_array($delimiter, ["\n", "\r", "\r\n"], true) || $next_line === '') {
                    break;
                }
                $trim_next = ltrim($next_line);
                if ($trim_next === '' || preg_match('/^(?:\*\/|\*|\/\*|<!--|\/\/|#|--|<\/?(?:pre|code)\b)/i', $trim_next)) {
                    break;
                }
                // Only join if the previous line clearly ends with a concatenation/operator continuation.
                // Also handle syntax-highlighted code, where the dot may appear before closing span tags.
                $line_plain_tail = html_entity_decode(wp_strip_all_tags((string)$line), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $next_plain_head = ltrim(html_entity_decode(wp_strip_all_tags((string)$next_line), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (!preg_match('/(?:\.\s*|\+\s*|,\s*)$/u', rtrim((string)$line_plain_tail))) {
                    break;
                }
                // The next line should look like an expression continuation, not a new declaration/assignment.
                if (!preg_match('/^(?:\(|\[|\{|\$|[A-Za-z_][A-Za-z0-9_]*\s*\(|[A-Za-z_][A-Za-z0-9_]*::|[A-Za-z_][A-Za-z0-9_]*->|["\']|[0-9])/', $next_plain_head)) {
                    break;
                }
                if (preg_match('/^(?:\$[A-Za-z_][A-Za-z0-9_]*|(?:const|let|var)\s+[A-Za-z_][A-Za-z0-9_]*)\s*=/', $trim_next)) {
                    break;
                }
                $line = rtrim($line) . ' ' . $trim_next;
                $i += 2;
            }

            $out[] = $line;
        }

        $repaired = implode('', $out);
        if (!is_string($repaired) || $repaired === '') {
            return $translated_block;
        }
        return $repaired;
    }

    private function openai_repair_accidentally_wrapped_code_assignments($source_block, $translated_block) {
        $source_block = (string)$source_block;
        $translated_block = (string)$translated_block;
        if ($source_block === '' || $translated_block === '' || $source_block === $translated_block) {
            return $translated_block;
        }

        // v0.7.19: first repair generic accidental line wrapping after PHP/JS concatenation operators.
        // This catches cases such as:  ... " |Calculation result:" .\n(sqrt(...)
        // It does not rely on variable-name detection, so it also works when code is HTML-escaped or syntax-highlighted.
        $translated_block = $this->openai_repair_concat_operator_linebreaks($source_block, $translated_block);

        $single_line_vars = [];
        $source_lines = preg_split('/\r\n|\n|\r/', $source_block);
        if (is_array($source_lines)) {
            foreach ($source_lines as $line) {
                if (preg_match('/(\$[A-Za-z_][A-Za-z0-9_]*)\s*=.*;/', (string)$line, $m)) {
                    $single_line_vars[$m[1]] = true;
                }
                if (preg_match('/\b(?:const|let|var)\s+([A-Za-z_][A-Za-z0-9_]*)\s*=.*;/', (string)$line, $m)) {
                    $single_line_vars[$m[1]] = true;
                }
            }
        }
        if (!$single_line_vars) {
            return $translated_block;
        }

        $parts = preg_split('/(\r\n|\n|\r)/', $translated_block, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts) || count($parts) < 3) {
            return $translated_block;
        }

        $out = [];
        $count = count($parts);
        for ($i = 0; $i < $count; $i++) {
            $part = (string)$parts[$i];
            if ($part === "\n" || $part === "\r" || $part === "\r\n") {
                $out[] = $part;
                continue;
            }

            $matched_var = '';
            foreach ($single_line_vars as $var => $_) {
                if (strpos($part, (string)$var) !== false && preg_match('/' . preg_quote((string)$var, '/') . '\s*=/', $part)) {
                    $matched_var = (string)$var;
                    break;
                }
            }

            if ($matched_var !== '' && strpos($part, ';') === false) {
                $merged = $part;
                $j = $i + 1;
                while ($j + 1 < $count) {
                    $delimiter = (string)$parts[$j];
                    $next_line = (string)$parts[$j + 1];
                    if (!in_array($delimiter, ["\n", "\r", "\r\n"], true) || $next_line === '') {
                        break;
                    }
                    // Only rejoin obvious accidental continuation lines. Never absorb comments, tags that open a new block, or another assignment.
                    $trim_next = ltrim($next_line);
                    if ($trim_next === '' || preg_match('/^(?:\*\/|\*|\/\*|<!--|\/\/|#|--|<\/?(?:pre|code)\b)/i', $trim_next)) {
                        break;
                    }
                    if (preg_match('/^(?:\$[A-Za-z_][A-Za-z0-9_]*|(?:const|let|var)\s+[A-Za-z_][A-Za-z0-9_]*)\s*=/', $trim_next)) {
                        break;
                    }
                    $merged = rtrim($merged) . ' ' . ltrim($next_line);
                    $i = $j + 1;
                    if (strpos($next_line, ';') !== false) {
                        break;
                    }
                    $j += 2;
                }
                $out[] = $merged;
                continue;
            }

            $out[] = $part;
        }

        $repaired = implode('', $out);
        return is_string($repaired) && $repaired !== '' ? $repaired : $translated_block;
    }

    private function openai_tokenize_code_line_locked($line, $collect) {
        $line = (string)$line;
        if ($line === '' || !$this->openai_contains_translatable_source_text($line)) {
            return $line;
        }

        // Entity-escaped HTML examples are handled first so human-readable text can be isolated safely.
        $line = $this->openai_tokenize_escaped_html_code_fragments($line, $collect);

        // Actual HTML comment examples inside code blocks, single-line only.
        $line = preg_replace_callback('~(<!--\s*)([^\r\n]*\p{L}[^\r\n]*?)(\s*-->)~u', function($m) use ($collect) {
            return $collect('comment', $m[1], $m[2], $m[3]);
        }, $line);

        // Inline block comments on one line are translated while preserving markers and spacing.
        $line = preg_replace_callback('~(/\*+\s*)([^\r\n]*\p{L}[^\r\n]*?)(\s*\*/)~u', function($m) use ($collect) {
            return $collect('comment', $m[1], $m[2], $m[3]);
        }, $line);

        // Javadoc/PHPDoc middle lines preserve their optional closing marker.
        $line = preg_replace_callback('~(^[ \t]*\*\s?)([^\r\n]*\p{L}[^\r\n]*?)([ \t]*\*/[ \t]*)?$~u', function($m) use ($collect) {
            $suffix = isset($m[3]) ? (string)$m[3] : '';
            return $collect('comment', $m[1], $m[2], $suffix);
        }, $line);

        // Full-line comments preserve their comment prefix.
        $line = preg_replace_callback('~(^[ \t]*(?://|#|--)\s?)([^\r\n]*\p{L}[^\r\n]*)$~u', function($m) use ($collect) {
            return $collect('comment', $m[1], $m[2], '');
        }, $line);

        // Header-style code comments such as Theme Name or Description are handled as text values.
        // If the header value contains an inline // note, leave the value intact and let the // rule handle only the note.
        $line = preg_replace_callback('~(^[ \t]*(?:[A-Z][A-Za-z0-9 _-]{1,40}:\s*))((?:(?!//)[^\r\n])*\p{L}(?:(?!//)[^\r\n])*)$~u', function($m) use ($collect) {
            return $collect('text', $m[1], $m[2], '');
        }, $line);

        // Trailing // comments after code/text, but never URL schemes. Preserve everything before //.
        $line = preg_replace_callback('~(.+?(?<!:)//\s?)([^\r\n]*\p{L}[^\r\n]*)$~u', function($m) use ($collect) {
            return $collect('comment', $m[1], $m[2], '');
        }, $line);

        // Quoted string values and string sub-fragments. This is line-local, so it cannot move text across lines.
        $line = $this->openai_tokenize_code_string_values($line, $collect);

        // Syntax-highlighter HTML span/text-node fallback, still line-local.
        $line = $this->openai_tokenize_code_html_text_nodes($line, $collect);

        return is_string($line) ? $line : '';
    }

    private function openai_tokenize_multiline_comment_body($body, $collect) {
        $body = (string)$body;
        if ($body === '' || !$this->openai_contains_translatable_source_text($body)) {
            return $body;
        }

        $parts = preg_split('/(\r\n|\n|\r)/', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $body;
        }

        foreach ($parts as $i => $line) {
            if ($line === '' || $line === "\n" || $line === "\r" || $line === "\r\n" || !$this->openai_contains_translatable_source_text($line)) {
                continue;
            }
            // 保留块注释常见前导格式，如 " * "，只翻译该行中的自然语言正文。
            if (preg_match('/^([ \t]*(?:\*\s?)?)(.*?)([ \t]*)$/u', (string)$line, $m)) {
                $text = (string)$m[2];
                if ($text !== '' && $this->openai_contains_translatable_source_text($text)) {
                    $parts[$i] = $collect('comment', (string)$m[1], $text, (string)$m[3]);
                }
            }
        }

        return implode('', $parts);
    }

    private function openai_linebreak_signature($text) {
        $text = (string)$text;
        if ($text === '') {
            return '';
        }
        if (!preg_match_all('/\r\n|\n|\r/', $text, $m)) {
            return '0:' . strlen($text);
        }
        // 只比较真实换行序列，不比较行长度；翻译后同一行变长是允许的。
        return (string)count($m[0]) . ':' . implode(',', $m[0]);
    }

    private function openai_tokenize_code_string_values($code, $collect) {
        $code = (string)$code;
        if ($code === '' || !$this->openai_contains_translatable_source_text($code)) {
            return $code;
        }

        $len = strlen($code);
        $out = '';
        $i = 0;
        while ($i < $len) {
            $ch = $code[$i];
            if ($ch === '`') {
                $j = $i + 1;
                $escaped = false;
                while ($j < $len) {
                    $c = $code[$j];
                    if ($escaped) {
                        $escaped = false;
                        $j++;
                        continue;
                    }
                    if ($c === '\\') {
                        $escaped = true;
                        $j++;
                        continue;
                    }
                    if ($c === '`') {
                        break;
                    }
                    $j++;
                }
                if ($j >= $len) {
                    $out .= substr($code, $i);
                    break;
                }
                $full = substr($code, $i, $j - $i + 1);
                $inner = substr($full, 1, -1);
                if ($this->openai_contains_translatable_source_text($inner)) {
                    $tokenized_inner = $this->openai_tokenize_code_template_string_inner($inner, $collect);
                    $out .= '`' . $tokenized_inner . '`';
                } else {
                    $out .= $full;
                }
                $i = $j + 1;
                continue;
            }

            if ($ch !== "'" && $ch !== '"') {
                $out .= $ch;
                $i++;
                continue;
            }

            $quote = $ch;
            $j = $i + 1;
            $escaped = false;
            while ($j < $len) {
                $c = $code[$j];
                if ($escaped) {
                    $escaped = false;
                    $j++;
                    continue;
                }
                if ($c === '\\') {
                    $escaped = true;
                    $j++;
                    continue;
                }
                if ($c === $quote) {
                    break;
                }
                $j++;
            }

            if ($j >= $len) {
                $out .= substr($code, $i);
                break;
            }

            $full = substr($code, $i, $j - $i + 1);
            $inner = substr($full, 1, -1);
            $after = substr($code, $j + 1, 24);

            // PHP 数组 key / JS、JSON 对象 key 不翻译，只消费整个字符串，避免从闭合引号再次匹配。
            if (preg_match('/^\s*(=>|:)/', $after)) {
                $out .= $full;
                $i = $j + 1;
                continue;
            }

            if ($this->openai_contains_translatable_source_text($inner)) {
                // Extract only human-readable string fragments. Source-language identity is
                // supplied by the backend context; no script or language is assumed here.
                $tokenized_inner = $this->openai_tokenize_code_string_inner($inner, $collect, $quote);
                $out .= $quote . $tokenized_inner . $quote;
            } else {
                $out .= $full;
            }
            $i = $j + 1;
        }

        return $out;
    }

    private function openai_tokenize_code_template_string_inner($inner, $collect) {
        $inner = (string)$inner;
        if ($inner === '' || !$this->openai_contains_translatable_source_text($inner)) {
            return $inner;
        }
        $parts = preg_split('/(\$\{[^\r\n{}]*\})/', $inner, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $inner;
        }
        foreach ($parts as $i => $part) {
            if ($part === '' || preg_match('/^\$\{[^\r\n{}]*\}$/', (string)$part) || !$this->openai_contains_translatable_source_text((string)$part)) {
                continue;
            }
            $parts[$i] = $this->openai_tokenize_code_string_inner((string)$part, $collect, '`');
        }
        return implode('', $parts);
    }

    private function openai_tokenize_code_string_inner($inner, $collect, $quote = '') {
        $inner = (string)$inner;
        if ($inner === '' || !$this->openai_contains_translatable_source_text($inner)) {
            return $inner;
        }

        // 只翻译字符串里的自然语言片段，不碰原始转义序列、变量插值和代码符号。
        // 例如："特殊字符测试: \" \\ \t\n" 只会提取“特殊字符测试: ”，后面的 \t\n 保持原样。
        $natural_chars = '[\\p{L}\\p{M}0-9 \\t\\x{3000}，。！？、；：：“”‘’（）《》【】\\[\\]\-_.,!?;:%()·]+';
        $out = preg_replace_callback('~(' . $natural_chars . ')~u', function($m) use ($collect, $quote) {
            $segment = (string)$m[1];
            if (!$this->openai_contains_translatable_source_text($segment)) {
                return $segment;
            }
            $plain = trim(html_entity_decode($segment, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($plain === '') {
                return $segment;
            }
            if (preg_match('~^(.*?)(https?://.*)$~u', $segment, $url_match) || preg_match('~^(.*?)(https?:)$~u', $segment, $url_match)) {
                $before_url = (string)$url_match[1];
                if ($before_url !== '' && $this->openai_contains_translatable_source_text($before_url)) {
                    return $collect('string_part', '', $before_url, '', (string)$quote) . (string)$url_match[2];
                }
                return $segment;
            }
            // 片段里如果混入明显变量插值或代码表达式，跳过，避免破坏运行逻辑。
            if (preg_match('/[{}$<>]|=>|::|->|\\$\\{/', $segment)) {
                return $segment;
            }
            return $collect('string_part', '', $segment, '', (string)$quote);
        }, $inner);

        return is_string($out) ? $out : $inner;
    }

    private function openai_tokenize_escaped_html_code_fragments($code, $collect) {
        $code = (string)$code;
        if ($code === '' || !$this->openai_contains_translatable_source_text($code)) {
            return $code;
        }

        // WordPress 代码块中的 HTML 示例通常以实体形式保存，插件只提取其中的人类可读文字。
        // 这里只翻译注释正文，保留 &lt;!--、--&gt; 和原有空格。
        $code = preg_replace_callback('~(&lt;!--\s*)(.*?\p{L}.*?)(\s*--&gt;)~isu', function($m) use ($collect) {
            return $collect('comment', $m[1], $m[2], $m[3]);
        }, $code);

        // 兼容被实体转义的引号字符串，并保持原实体引号。
        $code = preg_replace_callback('~(&quot;|&#039;|&apos;)((?:(?!&quot;|&#039;|&apos;)[^\r\n])*\p{L}(?:(?!&quot;|&#039;|&apos;)[^\r\n])*?)(\1)~u', function($m) use ($collect) {
            return $collect('string', $m[1], $m[2], $m[3], $m[1]);
        }, $code);

        // 兼容弯引号字符串，保持引号样式和内部结构。
        $code = preg_replace_callback('~(‘)([^\r\n‘’]*\p{L}[^\r\n‘’]*?)(’)~u', function($m) use ($collect) {
            return $collect('string', $m[1], $m[2], $m[3], $m[1]);
        }, $code);
        $code = preg_replace_callback('~(“)([^\r\n“”]*\p{L}[^\r\n“”]*?)(”)~u', function($m) use ($collect) {
            return $collect('string', $m[1], $m[2], $m[3], $m[1]);
        }, $code);

        // 处理简单的实体 HTML 标签文本：&lt;span&gt;内容&lt;/span&gt;。
        // 只处理单层短文本，避免改写复杂嵌套 HTML 示例。
        $code = preg_replace_callback('~(&lt;([A-Za-z][A-Za-z0-9:_-]*)(?:\s+(?:(?!&gt;).)*?)?&gt;)([^&\r\n<>]*\p{L}[^&\r\n<>]*?)(&lt;/\2&gt;)~su', function($m) use ($collect) {
            $inner = html_entity_decode((string)$m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!$this->openai_code_leaf_looks_like_natural_language($inner)) {
                return $m[0];
            }
            return $collect('text', $m[1], $m[3], $m[4]);
        }, $code);

        // 处理实体 HTML 片段中的标签后文本：&lt;a href=&quot;...&quot;&gt;阅读更多&lt;/a&gt;。
        $code = preg_replace_callback('~(&gt;)([^&\r\n<>]*\p{L}[^&\r\n<>]*?)(&lt;/[A-Za-z][A-Za-z0-9:_-]*&gt;)~su', function($m) use ($collect) {
            $inner = html_entity_decode((string)$m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!$this->openai_code_leaf_looks_like_natural_language($inner)) {
                return $m[0];
            }
            return $collect('text', $m[1], $m[2], $m[3]);
        }, $code);

        // 处理实体 HTML 示例中紧挨 PHP 模板标签的短文本标签。
        // 只翻译 PHP 标签外的自然语言短文本，保留 &lt;?php ... ?&gt; 原样。
        $code = preg_replace_callback('~(^|&gt;|\s)([^&\r\n<>]*\p{L}[^&\r\n<>]*?)(\s*&lt;\?php\b)~u', function($m) use ($collect) {
            $inner = html_entity_decode((string)$m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!$this->openai_code_leaf_looks_like_natural_language($inner)) {
                return $m[0];
            }
            return $m[1] . $collect('text', '', $m[2], '') . $m[3];
        }, $code);
        $code = preg_replace_callback('~(\?&gt;\s*)([^&\r\n<>]*\p{L}[^&\r\n<>]*?)($|&lt;|\s)~u', function($m) use ($collect) {
            $inner = html_entity_decode((string)$m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!$this->openai_code_leaf_looks_like_natural_language($inner)) {
                return $m[0];
            }
            return $m[1] . $collect('text', '', $m[2], '') . $m[3];
        }, $code);

        return is_string($code) ? $code : '';
    }

    private function openai_normalize_code_fragment_translation($translated, $source, $kind = 'text', $prefix = '', $suffix = '') {
        $translated = trim((string)$translated);
        $source = (string)$source;
        $kind = sanitize_key((string)$kind);
        $prefix = (string)$prefix;
        $suffix = (string)$suffix;

        if ($translated === '') {
            return $source;
        }

        // 防止模型把片段包进 Markdown 代码围栏、JSON 说明或原注释符号里。
        $translated = preg_replace('~^```[a-zA-Z0-9_-]*\s*~', '', $translated);
        $translated = preg_replace('~\s*```$~', '', (string)$translated);
        $translated = trim((string)$translated);

        // 片段原文是单行时，译文也必须是单行，避免破坏代码滚动条和语法展示。
        if (strpos($source, "\n") === false && strpos($source, "\r") === false) {
            $translated = preg_replace('~<br\s*/?>~i', ' ', $translated);
            $translated = preg_replace('~&(?:#10|#x0a|NewLine);~i', ' ', (string)$translated);
            $translated = str_replace(["\r\n", "\n", "\r", "\t"], ' ', (string)$translated);
            $translated = preg_replace('/\s*\R\s*/u', ' ', (string)$translated);
            $translated = preg_replace('/[ \t]{2,}/u', ' ', (string)$translated);
            $translated = trim((string)$translated);
        }

        if ($kind === 'comment') {
            // 如果模型返回了完整注释，把外层注释符号去掉，只保留正文。
            $translated = preg_replace('~^\s*/\*\s*(.*?)\s*\*/\s*$~s', '$1', $translated);
            $translated = preg_replace('~^\s*<!--\s*(.*?)\s*-->\s*$~s', '$1', $translated);
            $translated = preg_replace('~^\s*&lt;!--\s*(.*?)\s*--&gt;\s*$~s', '$1', $translated);
            $translated = preg_replace('~^\s*(//|#|--)\s*~', '', $translated);
            $translated = preg_replace('~\s*\*/\s*$~', '', (string)$translated);
            $translated = trim((string)$translated);
        } elseif ($kind === 'string' || $kind === 'string_part' || $kind === 'text') {
            // 如果模型把字符串值再次包了一层引号，去掉这层引号，保留原代码中的引号。
            if ((strlen($translated) >= 2) && ((substr($translated, 0, 1) === '"' && substr($translated, -1) === '"') || (substr($translated, 0, 1) === "'" && substr($translated, -1) === "'"))) {
                $translated = substr($translated, 1, -1);
            }
            if (preg_match('~^(&quot;|&#039;|&apos;)(.*)\1$~s', $translated, $qm)) {
                $translated = (string)$qm[2];
            }
            $translated = trim((string)$translated);
        }

        if ($translated === '') {
            return $source;
        }
        return $translated;
    }

    private function openai_tokenize_code_html_text_nodes($html, $collect) {
        $html = (string)$html;
        if ($html === '' || !$this->openai_contains_translatable_source_text($html)) {
            return $html;
        }

        $parts = preg_split('~(<[^>]+>)~s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts) || count($parts) <= 1) {
            return $this->openai_tokenize_code_text_leaf($html, $collect);
        }

        foreach ($parts as $i => $part) {
            if ($part === '' || $part[0] === '<' || !$this->openai_contains_translatable_source_text($part)) {
                continue;
            }
            $parts[$i] = $this->openai_tokenize_code_text_leaf($part, $collect);
        }

        return implode('', $parts);
    }

    private function openai_tokenize_code_text_leaf($text, $collect) {
        $text = (string)$text;
        if ($text === '' || !$this->openai_contains_translatable_source_text($text)) {
            return $text;
        }

        // 行级兜底：翻译文本节点中的注释正文，不让 AI 改动注释符号或缩进。
        $text = preg_replace_callback('~(^|\r?\n)([ \t]*(?://|#|--)\s?)([^\r\n]*\p{L}[^\r\n]*)~u', function($m) use ($collect) {
            return $m[1] . $collect('comment', $m[2], $m[3], '');
        }, $text);

        if (!$this->openai_contains_translatable_source_text($text)) {
            return $text;
        }

        // 语法高亮后常见形态：文本节点本身就是引号包起来的字符串值。
        $text = preg_replace_callback('~(["\'])([^"\'\r\n]*\p{L}[^"\'\r\n]*)(\1)~u', function($m) use ($collect) {
            return $collect('string', $m[1], $m[2], $m[3], $m[1]);
        }, $text);

        if (!$this->openai_contains_translatable_source_text($text)) {
            return $text;
        }

        // SQL 语法高亮有时会把注释符号和注释内容拆成多个节点，只剩纯文本节点。
        // 这里仅处理“几乎全是自然语言”的短文本，避免把复杂代码表达式交给 AI 改写。
        $plain = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($plain !== '' && $this->wpmu_ml_strlen($plain) <= 120 && $this->openai_code_leaf_looks_like_natural_language($plain)) {
            $leading_len = strlen($text) - strlen(ltrim($text));
            $trailing_len = strlen($text) - strlen(rtrim($text));
            $leading = $leading_len > 0 ? substr($text, 0, $leading_len) : '';
            $trailing = $trailing_len > 0 ? substr($text, -$trailing_len) : '';
            $body_len = strlen($text) - $leading_len - $trailing_len;
            $body = $body_len > 0 ? substr($text, $leading_len, $body_len) : '';
            return $collect('text', $leading, $body, $trailing);
        }

        return $text;
    }

    private function openai_code_leaf_looks_like_natural_language($text) {
        $text = trim((string)$text);
        if ($text === '' || !$this->openai_contains_translatable_source_text($text)) {
            return false;
        }
        // 明显代码 token 较多时不兜底翻译，避免破坏表达式。允许 JSX/模板里的简单占位符，如 {username}。
        $placeholder_safe = preg_replace('/\{\s*[A-Za-z_$][A-Za-z0-9_$]*(?:\.[A-Za-z_$][A-Za-z0-9_$]*)?\s*\}/', '', $text);
        if (preg_match('/[{}=;<>]|\$[A-Za-z_]|\b(function|class|const|let|var|if|else|for|while|return|echo|print|array|SELECT|INSERT|UPDATE|DELETE|CREATE|FROM|WHERE)\b/i', (string)$placeholder_safe)) {
            return false;
        }
        // URL / 路径 / 邮箱不作为自然语言片段处理。
        if (preg_match('~(https?:)?//|[A-Za-z]:\\\\|/[A-Za-z0-9_\-./]+|\S+@\S+~', $text)) {
            return false;
        }
        return true;
    }

    private function openai_escape_code_string_text($text, $quote) {
        $text = (string)$text;
        $quote = (string)$quote;
        $text = str_replace('\\', '\\\\', $text);
        if ($quote === "'") {
            $text = str_replace("'", "\\'", $text);
        } elseif ($quote === '"') {
            $text = str_replace('"', '\\"', $text);
        }
        $text = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $text);
        return $text;
    }

    private function openai_translate_wp_block_comments($content, $target_label, $settings, $task_instruction = '') {
        $content = (string)$content;
        if ($content === '' || strpos($content, '<!--') === false || !$this->openai_contains_human_language_text($content)) {
            return $content;
        }

        preg_match_all('~<!--(.*?)-->~s', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (!$matches) {
            return $content;
        }

        $records = [];
        $batch_fields = [];
        $batch_roles = [];
        $batch_locators = [];
        $complex = [];
        foreach ($matches as $index => $match) {
            $source_comment = (string)$match[0][0];
            $inner = substr($source_comment, 4, -3);
            if (!preg_match('/^\s*wp:/', $inner) || preg_match('/^\s*wp:(?:code|preformatted)\b/i', $inner)) {
                continue;
            }
            $json_start = strpos($inner, '{');
            $json_end = strrpos($inner, '}');
            if ($json_start === false || $json_end === false || $json_end <= $json_start) {
                continue;
            }
            $json = substr($inner, $json_start, $json_end - $json_start + 1);
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                continue;
            }

            $record_index = count($records);
            $records[$record_index] = [
                'offset' => (int)$match[0][1],
                'length' => strlen($source_comment),
                'source_comment' => $source_comment,
                'prefix' => '<!--' . substr($inner, 0, $json_start),
                'suffix' => substr($inner, $json_end + 1) . '-->',
                'source_data' => $decoded,
                'target_data' => $decoded,
            ];

            $before_fields = array_keys($batch_fields);
            $before_complex = count($complex);
            $this->openai_collect_batchable_data_translation_leaves(
                $decoded,
                'gutenberg',
                $settings,
                $batch_fields,
                $batch_roles,
                $batch_locators,
                $complex,
                [],
                [],
                false
            );
            foreach (array_diff(array_keys($batch_fields), $before_fields) as $field_key) {
                $batch_locators[$field_key]['record_index'] = $record_index;
            }
            for ($i = $before_complex; $i < count($complex); $i++) {
                $complex[$i]['record_index'] = $record_index;
            }
        }

        if (!$records) {
            return $content;
        }

        if ($batch_fields) {
            $translated_batch = $this->openai_translate_collected_data_leaves($batch_fields, $batch_roles, $target_label, $settings, 'gutenberg_data');
            if (is_wp_error($translated_batch)) {
                return $translated_batch;
            }
            foreach ($batch_fields as $field_key => $source_value) {
                if (!isset($batch_locators[$field_key]) || !array_key_exists($field_key, $translated_batch)) {
                    continue;
                }
                $locator = $batch_locators[$field_key];
                $record_index = (int)($locator['record_index'] ?? -1);
                if (!isset($records[$record_index])) {
                    continue;
                }
                $candidate = $this->openai_preserve_fragment_boundary_whitespace((string)$source_value, (string)$translated_batch[$field_key]);
                $this->openai_meta_quality_apply_locator($records[$record_index]['target_data'], $locator, $candidate);
            }
        }

        foreach ($complex as $locator) {
            $record_index = (int)($locator['record_index'] ?? -1);
            if (!isset($records[$record_index])) {
                continue;
            }
            $source_value = (string)($locator['source'] ?? '');
            $changed = false;
            $translated_value = $this->openai_translate_data_string(
                $source_value,
                $target_label,
                $settings,
                $changed,
                (string)($locator['field_key'] ?? 'gutenberg')
            );
            if (is_wp_error($translated_value)) {
                return $translated_value;
            }
            if ($changed || (string)$translated_value !== $source_value) {
                $this->openai_meta_quality_apply_locator($records[$record_index]['target_data'], $locator, (string)$translated_value);
            }
        }

        $qa_source = [];
        $qa_target = [];
        $qa_roles = [];
        $qa_locators = [];
        foreach ($records as $record_index => $record) {
            $before_keys = array_keys($qa_source);
            $this->openai_collect_meta_quality_pairs(
                $record['source_data'],
                $record['target_data'],
                'g' . $record_index,
                'gutenberg',
                $qa_source,
                $qa_target,
                $qa_roles,
                $qa_locators,
                []
            );
            foreach (array_diff(array_keys($qa_source), $before_keys) as $qa_key) {
                $qa_locators[$qa_key]['record_index'] = (int)$record_index;
            }
        }

        if ($qa_source) {
            $reviewed = $this->openai_central_quality_review_fields(
                $qa_source,
                $qa_target,
                $qa_roles,
                $target_label,
                $settings,
                'gutenberg_data'
            );
            if (is_wp_error($reviewed)) {
                return $reviewed;
            }
            foreach ($reviewed as $qa_key => $value) {
                if (!isset($qa_locators[$qa_key])) {
                    continue;
                }
                $locator = $qa_locators[$qa_key];
                $record_index = (int)($locator['record_index'] ?? -1);
                if (!isset($records[$record_index])) {
                    continue;
                }
                $this->openai_meta_quality_apply_locator($records[$record_index]['target_data'], $locator, (string)$value);
            }
        }

        $replacements = [];
        $changed_records = 0;
        foreach ($records as $record) {
            if (serialize($record['source_data']) === serialize($record['target_data'])) {
                continue;
            }
            $new_json = wp_json_encode($record['target_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($new_json) || $new_json === '') {
                continue;
            }
            $replacements[] = [
                'offset' => (int)$record['offset'],
                'length' => (int)$record['length'],
                'value' => (string)$record['prefix'] . $new_json . (string)$record['suffix'],
            ];
            $changed_records++;
        }

        usort($replacements, static function($a, $b) {
            return ((int)$b['offset']) <=> ((int)$a['offset']);
        });
        foreach ($replacements as $replacement) {
            $content = substr_replace($content, (string)$replacement['value'], (int)$replacement['offset'], (int)$replacement['length']);
        }
        $this->openai_cli_trace_line(sprintf(
            'GUTENBERG DATA BATCH comments=%d changed=%d batch_text_fields=%d complex_fields=%d qa_fields=%d',
            count($records),
            $changed_records,
            count($batch_fields),
            count($complex),
            count($qa_source)
        ));
        return $content;
    }

    private function openai_translate_wp_block_comment($comment, $target_label, $settings, $task_instruction = '') {
        $comment = (string)$comment;
        if (!$this->openai_contains_human_language_text($comment) || strpos($comment, '{') === false || strpos($comment, '}') === false) {
            return $comment;
        }

        $inner = substr($comment, 4, -3);
        // 只处理 WordPress block 注释，避免翻译普通 HTML 注释中的开发说明。
        if (!preg_match('/^\s*wp:/', $inner)) {
            return $comment;
        }
        if (preg_match('/^\s*wp:(?:code|preformatted)\b/i', $inner)) {
            return $comment;
        }

        $json_start = strpos($inner, '{');
        $json_end = strrpos($inner, '}');
        if ($json_start === false || $json_end === false || $json_end <= $json_start) {
            return $comment;
        }

        $json = substr($inner, $json_start, $json_end - $json_start + 1);
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $comment;
        }

        $changed = false;
        $translated = $this->openai_translate_decoded_data_value($decoded, $target_label, $settings, $changed, '');
        if (is_wp_error($translated)) {
            return $translated;
        }
        if (!$changed) {
            return $comment;
        }

        $new_json = wp_json_encode($translated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($new_json) || $new_json === '') {
            return $comment;
        }

        return '<!--' . substr($inner, 0, $json_start) . $new_json . substr($inner, $json_end + 1) . '-->';
    }
    }
}
