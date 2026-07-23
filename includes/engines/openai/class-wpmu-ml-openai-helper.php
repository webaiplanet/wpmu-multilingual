<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI/API translation and editorial helper.
 * It preserves WordPress structures deterministically while asking the model to translate and
 * review prose with professional human editorial judgment rather than mechanical substitution.
 */
final class WPMU_ML_OpenAI_Helper {
    public static function enabled(array $settings): bool {
        return !empty($settings['openai_agent_mode']) && $settings['openai_agent_mode'] !== 'off';
    }

    /**
     * Build the shared translation-rule bundle used by both the internal OpenAI-compatible
     * engine and the external Agent API. This keeps one source of truth for site-wide rules,
     * built-in native-quality rules, site-wide rules and glossary matching.
     */
    public static function build_shared_rules_bundle(array $settings, array $target_context = [], string $target_label = ''): array {
        $context = self::normalize_language_context($target_context, $target_label);
        $site_rules = trim((string)($settings['openai_agent_site_rules'] ?? ''));
        $glossary_raw = trim((string)($settings['openai_agent_terms'] ?? ''));
        $glossary_effective = self::format_glossary_for_prompt($glossary_raw, $context, $target_label);

        return [
            // Keep the historical machine-readable mode value for Agent compatibility.
            'translation_mode' => 'website_localization',
            'translation_style' => 'native_quality',
            'terminology' => [
                'label' => 'native-quality website localization',
                'note' => '“母语化”描述质量目标：译文应像目标语言母语技术作者原创，自然、可信、符合当地表达习惯，而不是后台配置源语言的生硬直译。',
            ],
            'target_language' => $context,
            'built_in_rules' => [
                'Use the explicit AI translation locale when configured; otherwise use the WordPress Locale as the target language variant.',
                'Write idiomatic, natural and trustworthy target-language copy that reads as if originally written by an experienced native technical writer.',
                'Avoid literal word-for-word translation, source-language syntax, unnatural calques and stiff machine-translation wording.',
                'Adapt spelling, vocabulary, grammar, punctuation, UI wording and SEO phrasing to the target language variant without changing meaning or facts.',
                'Keep every ordinary human-readable output value in the configured target language only; never switch prose to another language.',
                'When a source sentence has an obvious fragment or missing predicate and the intended meaning is unambiguous, repair it conservatively in the target language without inventing facts.',
                'Treat the ordered article as continuous context: source HTML block boundaries may split one idea, so use neighboring blocks to produce complete, natural target-language units without duplicating adjacent content.',
                'Preserve brands, product names, prices, dates, numbers, URLs, code, commands, identifiers and WordPress structure unless the glossary explicitly requires a fixed translation.',
            ],
            'site_rules' => $site_rules,
            'glossary' => [
                'format' => 'source | language | target',
                'raw' => $glossary_raw,
                'effective_for_target' => $glossary_effective,
            ],
            'html_exclusions' => [
                'raw' => trim((string)($settings['openai_excluded_html_tags'] ?? 'pre')),
                'effective_selectors' => self::normalize_excluded_html_selectors((string)($settings['openai_excluded_html_tags'] ?? 'pre')),
                'note' => 'Matched elements and their descendants must remain unchanged and must not be translated or counted as untranslated residue.',
            ],
            'priority' => [
                'Built-in native-quality, fidelity, structure and safety rules always remain in force.',
                'Glossary fixed translations should be applied consistently.',
                'Site-wide rules apply to every target language and must not weaken the built-in fidelity or structure rules.',
                'The explicit AI translation locale overrides the WordPress Locale only for the translated content variant.',
            ],
        ];
    }

    public static function build_system_prompt(string $target_label, string $task_instruction, array $settings): string {
        $mode = sanitize_key($settings['openai_agent_mode'] ?? 'rules_qa');
        $target_context = self::normalize_language_context(
            $settings['openai_target_language_context'] ?? [],
            $target_label
        );
        $source_context = self::normalize_language_context(
            $settings['openai_source_language_context'] ?? [],
            'the configured source language'
        );
        $target_description = self::describe_language_context($target_context, $target_label);
        $source_description = self::describe_language_context($source_context, 'the configured source language');

        if ($mode === 'off') {
            $base_prompt = 'You are a professional native-quality website translator and localization editor. Translate content from ' . $source_description . ' into ' . $target_description . '. ' . $task_instruction . ' The result must read as if originally written by an experienced native technical writer: natural, credible and idiomatic, never like a literal translation from the configured source language. Follow the target language variant, preserve meaning and protected structures, and do not add explanations.';
            $article_terms = trim((string)($settings['openai_article_terminology_context'] ?? ''));
            if ($article_terms !== '') {
                $base_prompt .= "\n\nArticle-specific translation context (advisory; high-risk source concepts are not fixed translations):\n" . $article_terms;
            }
            return $base_prompt;
        }

        $shared_rules = self::build_shared_rules_bundle($settings, $target_context, $target_label);
        $rules = (string)($shared_rules['site_rules'] ?? '');
        $language_rules = trim((string)($settings['openai_language_prompt'] ?? ''));
        $formatted_terms = (string)($shared_rules['glossary']['effective_for_target'] ?? '');
        $article_terms = trim((string)($settings['openai_article_terminology_context'] ?? ''));
        $built_in_rules = is_array($shared_rules['built_in_rules'] ?? null) ? $shared_rules['built_in_rules'] : [];

        $prompt = [];
        $prompt[] = 'You are a senior WordPress multilingual native-quality website translator, localization editor, technical editor, and SEO translator.';
        $prompt[] = 'Translate website content from ' . $source_description . ' into ' . $target_description . ' while preserving WordPress structure exactly.';
        $prompt[] = 'The translated text must sound natural, credible and locally appropriate, as if it were originally written by an experienced native technical writer in the target language.';
        $prompt[] = 'Do not produce literal word-for-word translation, source-language sentence patterns, source-language interference, unnatural calques, or stiff machine-translated wording. This is faithful translation, not free rewriting.';
        $prompt[] = 'HARD TARGET-LANGUAGE LOCK: every human-readable output value must be written in the configured target language only. Never switch the prose to another natural language, even when the source contains mixed language or the model is uncertain. Keep other languages only where they are intentionally preserved as brand names, product names, code, commands, URLs, identifiers, conventional technical tokens, or glossary-mandated terms.';
        $prompt[] = 'The configured AI translation locale is authoritative for regional spelling, vocabulary, grammar, punctuation, tone, UI wording, date/number conventions and search phrasing. If it is blank, use the WordPress site Locale. Hreflang is only the SEO alternate-language signal and does not determine the translation variant.';
        $prompt[] = 'You are not a generic machine translator: follow the built-in rules, site rules, glossary and quality rules below.';
        if ($built_in_rules) {
            $prompt[] = '';
            $prompt[] = 'Built-in native-quality translation rules:';
            foreach ($built_in_rules as $index => $rule) {
                $prompt[] = ($index + 1) . '. ' . $rule;
            }
        }
        $prompt[] = '';
        $prompt[] = 'Target-language rule: use only the configured target language for ordinary prose. Preserve a different language only when it is intentionally part of a brand, product name, code, command, URL, identifier, quotation, conventional technical token, or glossary-mandated term.';
        $prompt[] = '';
        $prompt[] = 'Core skills:';
        $prompt[] = '1. WordPress/Gutenberg skill: preserve block comments like <!-- wp:paragraph -->, HTML tags, attributes, classes, IDs, styles, shortcodes, URLs, media IDs and JSON fragments exactly.';
        $prompt[] = '2. Code skill: preserve executable code, commands, CSS, JS, PHP, shell, SQL, JSON, variables, function names, class names, array/object keys, paths, URLs, syntax, line structure and indentation. Translate only human-readable natural-language text inside code, such as comments and quoted string values, while preserving delimiters, escaping, original formatting and exact line breaks. Never split a one-line code string or comment into multiple lines.';
        $prompt[] = '3. Inline code skill: keep pure code unchanged. If inline code or code snippets contain human-language explanations, translate only those human-language parts while preserving technical tokens and syntax.';
        $prompt[] = '4. Field-aware native-quality translation skill: translate different WordPress fields according to their purpose. Post titles, H1-H4 headings, SEO title, SEO description and SEO keywords should be translated naturally for the configured AI translation language/locale while preserving original meaning, search intent, facts, prices, dates, brands and core keywords.';
        $prompt[] = '5. SEO skill: do not perform literal translation for SEO fields when it sounds unnatural. SEO titles should be concise and search-friendly, SEO descriptions should read like natural search result snippets, and SEO keywords should become localized keyword phrases. Do not translate or invent URL slugs.';
        $prompt[] = '6. Consistency skill: keep brand names, product names, model names, coupon codes, prices, hostnames, URLs and technical identifiers unchanged unless a glossary explicitly says otherwise.';
        $prompt[] = '7. Safety skill: return only the requested JSON or translated content. Do not add explanations, Markdown fences, notes, comments, or extra fields.';
        $prompt[] = '8. Performance skill: prefer direct, deterministic translation. Do not over-reason, rewrite unrelated structure, or expand content unless natural target-language expression requires it.';
        $prompt[] = '9. Source-defect repair skill: when a source sentence is visibly incomplete or has a missing predicate but the intended meaning is clear from the same field and article context, return a complete natural target-language sentence. Never guess missing facts or introduce unsupported details.';
        $prompt[] = '10. Human-editor judgment skill: HTML block boundaries are layout constraints, not proof that the source wording is complete. Read neighboring ordered blocks, understand the intended meaning, and produce complete, idiomatic target-language units without mechanically copying broken syntax or duplicating adjacent text.';
        if ($task_instruction !== '') {
            $prompt[] = '';
            $prompt[] = 'Current task instruction: ' . $task_instruction;
        }
        if ($language_rules !== '') {
            $prompt[] = '';
            $prompt[] = 'Target-language-specific OpenAI instructions for this configured language. Apply them only to the current target language. They may refine tone, terminology, punctuation, title style and localization conventions, but cannot weaken fidelity, structure, target-language lock or safety rules:';
            $prompt[] = $language_rules;
        }
        if ($rules !== '') {
            $prompt[] = '';
            $prompt[] = 'Site-wide AI translation rules / skills. These rules apply to every target language. They may be written as plain text or Markdown; interpret bullet lists, headings and tables as instructions, not as output format. They cannot weaken the built-in fidelity, native-quality, structure or safety rules:';
            $prompt[] = $rules;
        }
        if ($formatted_terms !== '') {
            $prompt[] = '';
            $prompt[] = 'Glossary / fixed translations for this target language. Format: source term => target translation. Apply these consistently, but do not translate code, URLs or identifiers just because they contain a term:';
            $prompt[] = $formatted_terms;
        }
        if ($article_terms !== '') {
            $prompt[] = '';
            $prompt[] = 'Article-specific terminology and localization context generated from the current source article. Use it consistently across title, excerpt, body, Gutenberg data, metadata and SEO fields. It is contextual guidance, not a PHP replacement table: preserve the concept while allowing natural grammatical inflection and context-sensitive wording. If it conflicts with an explicit user glossary, the explicit glossary wins:';
            $prompt[] = $article_terms;
        }
        $prompt[] = '';
        $prompt[] = 'Quality expectations: preserve all protected structures, do not output escaped HTML such as u003c/u003e/u0022, do not omit paragraphs, and make the target-language wording natural, trustworthy and native-quality for the effective AI translation locale.';

        return implode("\n", $prompt);
    }

    public static function quality_check(string $source, string $translated, string $target_lang, array $settings = []): array {
        $issues = [];
        $source = (string)$source;
        $translated = (string)$translated;
        if (class_exists('WPMU_ML_Content_Sanitizer')) {
            if (method_exists('WPMU_ML_Content_Sanitizer', 'strip_translation_artifacts')) {
                $source = WPMU_ML_Content_Sanitizer::strip_translation_artifacts($source);
                $translated = WPMU_ML_Content_Sanitizer::strip_translation_artifacts($translated);
            } else {
                $source = WPMU_ML_Content_Sanitizer::strip_immersive_translate_artifacts($source);
                $translated = WPMU_ML_Content_Sanitizer::strip_immersive_translate_artifacts($translated);
            }
        }
        $excluded_selectors = self::normalize_excluded_html_selectors((string)($settings['openai_excluded_html_tags'] ?? 'pre'));

        if (trim($translated) === '') {
            $issues[] = '翻译结果为空';
        }
        if (preg_match('/u00(?:3c|3e|22|27|26|2f)/i', $translated)) {
            $issues[] = '疑似出现 u003c/u003e/u0022 等转义污染';
        }
        if (self::count_pattern('/<!--\s*wp:/i', $translated) !== self::count_pattern('/<!--\s*wp:/i', $source)) {
            $issues[] = 'Gutenberg block 起始标记数量变化';
        }
        if (self::count_pattern('/<!--\s*\/wp:/i', $translated) !== self::count_pattern('/<!--\s*\/wp:/i', $source)) {
            $issues[] = 'Gutenberg block 结束标记数量变化';
        }
        if (self::count_pattern('/\[[A-Za-z0-9_-]+[^\]]*\]/', $translated) !== self::count_pattern('/\[[A-Za-z0-9_-]+[^\]]*\]/', $source)) {
            $issues[] = '短代码数量变化';
        }
        if (self::count_pattern('~https?://[^\s"\'<>]+~i', $translated) !== self::count_pattern('~https?://[^\s"\'<>]+~i', $source)) {
            $issues[] = 'URL 数量变化';
        }
        if (self::count_pattern('/<img\b/i', $translated) !== self::count_pattern('/<img\b/i', $source)) {
            $issues[] = '图片标签数量变化';
        }
        if (self::count_pattern('/<pre\b/i', $translated) !== self::count_pattern('/<pre\b/i', $source)) {
            $issues[] = 'pre 代码块数量变化';
        }
        if (self::code_block_line_signature($translated) !== self::code_block_line_signature($source)) {
            $issues[] = '代码块行数或换行结构变化';
        }

        preg_match_all('/%[A-Za-z0-9_-]+%|%%[A-Za-z0-9_-]+%%|\{\{[^{}]+\}\}|__WPMU_ML_[A-Z0-9_]+__/u', $source, $source_placeholders);
        preg_match_all('/%[A-Za-z0-9_-]+%|%%[A-Za-z0-9_-]+%%|\{\{[^{}]+\}\}|__WPMU_ML_[A-Z0-9_]+__/u', $translated, $target_placeholders);
        $source_placeholder_values = array_values((array)($source_placeholders[0] ?? []));
        $target_placeholder_values = array_values((array)($target_placeholders[0] ?? []));
        sort($source_placeholder_values, SORT_STRING);
        sort($target_placeholder_values, SORT_STRING);
        if ($source_placeholder_values !== $target_placeholder_values) {
            $issues[] = '占位符集合变化';
        }

        preg_match_all('/<\/?(?!wpmu-ml-\d+\b)([A-Za-z][A-Za-z0-9:-]*)\b/u', $source, $source_tags);
        preg_match_all('/<\/?(?!wpmu-ml-\d+\b)([A-Za-z][A-Za-z0-9:-]*)\b/u', $translated, $target_tags);
        $source_tag_values = array_map('strtolower', array_values((array)($source_tags[0] ?? [])));
        $target_tag_values = array_map('strtolower', array_values((array)($target_tags[0] ?? [])));
        sort($source_tag_values, SORT_STRING);
        sort($target_tag_values, SORT_STRING);
        if ($source_tag_values !== $target_tag_values) {
            $issues[] = 'HTML 标签集合变化';
        }

        // 0.9.6.3: no residue, numeric or length heuristics are hard failures here.

        return [
            'ok' => empty($issues),
            'issues' => $issues,
        ];
    }


    private static function format_glossary_for_prompt(string $terms, array $target_context, string $target_label): string {
        $terms = trim($terms);
        if ($terms === '') {
            return '';
        }
        $lines = preg_split('/\r\n|\r|\n/', $terms);
        $out = [];
        foreach ($lines as $line) {
            $row = self::parse_glossary_line((string)$line);
            if (!$row) {
                continue;
            }
            if (!self::glossary_language_matches($row['lang'], $target_context, $target_label)) {
                continue;
            }
            $out[] = '- ' . $row['source'] . ' => ' . $row['target'] . ' [' . $row['lang'] . ']';
        }
        return implode("\n", $out);
    }

    private static function parse_glossary_line(string $line): ?array {
        $line = trim($line);
        if ($line === '' || preg_match('/^\s*(#|\/\/)/', $line)) {
            return null;
        }
        $line = preg_replace('/^[-*+]\s+/', '', $line);
        $parts = [];
        if (strpos($line, '|') !== false) {
            $parts = array_map('trim', explode('|', $line, 3));
        } elseif (strpos($line, "\t") !== false) {
            $parts = array_map('trim', preg_split('/\t+/', $line, 3));
        } else {
            $parts = array_map('trim', preg_split('/\s+/', $line, 3));
        }
        if (count($parts) < 3) {
            return null;
        }
        [$source, $lang, $target] = $parts;
        $source = trim((string)$source);
        $lang = strtolower(trim((string)$lang));
        $target = trim((string)$target);
        if ($source === '' || $lang === '' || $target === '') {
            return null;
        }
        return [
            'source' => $source,
            'lang' => $lang,
            'target' => $target,
        ];
    }

    /**
     * Match glossary language selectors generically against the configured language context.
     * Supported selectors include lang_slug (en), Locale (en_US/en-US), hreflang (en-GB),
     * a primary language code, or the global selectors all, *, and any. Unknown future languages work automatically.
     */
    private static function glossary_language_matches(string $lang, array $target_context, string $target_label): bool {
        $lang = self::normalize_language_token($lang);
        if ($lang === '' || in_array($lang, ['all', '*', 'any'], true)) {
            return true;
        }

        $candidates = [];
        foreach (['lang_slug', 'translation_locale', 'translation_language_name', 'locale', 'hreflang', 'primary', 'language_name'] as $key) {
            if (!empty($target_context[$key])) {
                $token = self::normalize_language_token((string)$target_context[$key]);
                if ($token !== '') {
                    $candidates[] = $token;
                    $primary = (string)strtok($token, '-');
                    if ($primary !== '') {
                        $candidates[] = $primary;
                    }
                }
            }
        }

        $label_token = self::normalize_language_token($target_label);
        if ($label_token !== '') {
            $candidates[] = $label_token;
        }

        // Human-readable language aliases and locale variants come only from the
        // configured subsite language context. There is no plugin-owned language map.

        $candidates = array_values(array_unique(array_filter($candidates)));
        if (in_array($lang, $candidates, true)) {
            return true;
        }

        // A locale-specific glossary row such as en-US should only match that locale, while a
        // primary selector such as en intentionally matches every English locale.
        if (strpos($lang, '-') === false) {
            foreach ($candidates as $candidate) {
                if ((string)strtok($candidate, '-') === $lang) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function normalize_language_context($context, string $fallback_label = ''): array {
        $context = is_array($context) ? $context : [];
        $lang_slug = sanitize_key((string)($context['lang_slug'] ?? ''));
        $locale = trim((string)($context['locale'] ?? ''));
        $language_name = trim((string)($context['language_name'] ?? ''));
        $translation_locale = trim((string)($context['translation_locale'] ?? ''));
        $translation_language_name = trim((string)($context['translation_language_name'] ?? ''));
        $hreflang = trim((string)($context['hreflang'] ?? ''));
        $primary = sanitize_key((string)($context['primary'] ?? ''));
        $prompt_label = trim((string)($context['prompt_label'] ?? ''));

        if ($prompt_label === '') {
            $prompt_label = trim($fallback_label);
        }
        if ($primary === '') {
            $primary = self::language_primary_code([
                'translation_locale' => $translation_locale,
                'locale' => $locale,
                'hreflang' => $hreflang,
                'lang_slug' => $lang_slug,
            ], $fallback_label);
        }

        if ($translation_locale === '') {
            $translation_locale = $locale !== '' ? str_replace('_', '-', $locale) : $hreflang;
        }
        if ($translation_language_name === '') {
            $translation_language_name = $language_name;
        }

        return [
            'lang_slug' => $lang_slug,
            'locale' => $locale,
            'language_name' => $language_name,
            'translation_locale' => $translation_locale,
            'translation_language_name' => $translation_language_name,
            'hreflang' => $hreflang,
            'primary' => $primary,
            'prompt_label' => $prompt_label,
        ];
    }

    private static function describe_language_context(array $context, string $fallback): string {
        $parts = [];
        if (!empty($context['translation_language_name'])) {
            $parts[] = 'AI translation language ' . $context['translation_language_name'];
        }
        if (!empty($context['translation_locale'])) {
            $parts[] = 'AI translation locale ' . $context['translation_locale'];
        }
        if (!empty($context['language_name'])) {
            $parts[] = 'WordPress language name ' . $context['language_name'];
        }
        if (!empty($context['locale'])) {
            $parts[] = 'WordPress site locale ' . $context['locale'];
        }
        if (!empty($context['hreflang'])) {
            $parts[] = 'SEO hreflang ' . $context['hreflang'];
        }
        if (!empty($context['lang_slug'])) {
            $parts[] = 'site language key ' . $context['lang_slug'];
        }
        if ($parts) {
            return implode('; ', $parts);
        }
        if (!empty($context['prompt_label'])) {
            return (string)$context['prompt_label'];
        }
        return trim($fallback) !== '' ? trim($fallback) : 'the configured language';
    }

    private static function language_primary_code(array $context, string $fallback = ''): string {
        foreach (['primary', 'translation_locale', 'locale', 'hreflang', 'lang_slug'] as $key) {
            if (!empty($context[$key])) {
                $token = self::normalize_language_token((string)$context[$key]);
                if ($token !== '') {
                    return sanitize_key((string)strtok($token, '-'));
                }
            }
        }
        $token = self::normalize_language_token($fallback);
        return $token !== '' ? sanitize_key((string)strtok($token, '-')) : '';
    }

    private static function normalize_language_token(string $value): string {
        $value = strtolower(trim($value));
        $value = preg_replace('/@.*$/', '', $value);
        $value = str_replace('_', '-', $value);
        $value = preg_replace('/[^a-z0-9*-]+/', '-', $value);
        return trim((string)$value, '-');
    }

    private static function count_pattern(string $pattern, string $text): int {
        $n = preg_match_all($pattern, $text, $m);
        return $n ? (int)$n : 0;
    }

    private static function plain_len(string $text): int {
        $plain = trim(wp_strip_all_tags($text));
        if (function_exists('mb_strlen')) {
            return (int)mb_strlen($plain, 'UTF-8');
        }
        return strlen($plain);
    }

    private static function code_block_line_signature(string $html): string {
        if (!preg_match_all('~<pre\b[^>]*>.*?</pre>~is', $html, $blocks)) {
            return '';
        }
        $items = [];
        foreach ($blocks[0] as $block) {
            $plain = html_entity_decode(wp_strip_all_tags((string)$block), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $items[] = (string)substr_count($plain, "\n") . ':' . (string)substr_count($plain, "\r");
        }
        return implode('|', $items);
    }

    /**
     * Remove regions that are intentionally excluded from translation and QA.
     * Supported explicit markers:
     *   <!-- wpmu-ml:no-translate:start --> ... <!-- wpmu-ml:no-translate:end -->
     *   translate="no", data-no-translation, .notranslate, .no-translate,
     *   .wpmu-ml-no-translate (simple non-nested elements).
     */
    private static function strip_qa_ignored_regions(string $html, array $excluded_selectors = []): string {
        $html = preg_replace(
            '~<!--\s*wpmu-ml:no-translate:start\s*-->.*?<!--\s*wpmu-ml:no-translate:end\s*-->~is',
            ' ',
            $html
        );
        $html = preg_replace('~<(pre|code|script|style|textarea)\b[^>]*>.*?</\1>~is', ' ', (string)$html);

        $marked_element = '~<([a-z][a-z0-9:-]*)\b[^>]*(?:\bdata-no-translation\b|\btranslate\s*=\s*["\']?no["\']?|\bclass\s*=\s*["\'][^"\']*(?:notranslate|no-translate|wpmu-ml-no-translate)[^"\']*["\'])[^>]*>.*?</\1\s*>~is';
        for ($i = 0; $i < 3; $i++) {
            $next = preg_replace($marked_element, ' ', (string)$html);
            if (!is_string($next) || $next === $html) {
                break;
            }
            $html = $next;
        }
        if ($excluded_selectors && class_exists('WPMU_ML_HTML_Exclusion')) {
            $html = WPMU_ML_HTML_Exclusion::remove_selected_regions((string)$html, $excluded_selectors);
        }
        return is_string($html) ? $html : '';
    }

    private static function strip_code_like_blocks(string $html): string {
        $html = preg_replace('~<pre\b[^>]*>.*?</pre>~is', ' ', $html);
        $html = preg_replace('~<code\b[^>]*>.*?</code>~is', ' ', (string)$html);
        $html = preg_replace('~<script\b[^>]*>.*?</script>~is', ' ', (string)$html);
        $html = preg_replace('~<style\b[^>]*>.*?</style>~is', ' ', (string)$html);
        return is_string($html) ? $html : '';
    }

    private static function normalize_excluded_html_selectors(string $raw): array {
        if (class_exists('WPMU_ML_HTML_Exclusion')) {
            return WPMU_ML_HTML_Exclusion::normalize_selector_list($raw);
        }
        return ['pre'];
    }
}
