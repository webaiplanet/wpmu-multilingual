<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI 语言审计、译文验收与编辑审校。
 *
 * 本 trait 仅用于拆分历史 WPMU_Multilingual 大类；方法签名和可见性保持不变。
 */
if (!trait_exists('WPMU_ML_Core_OpenAI_Quality_Trait')) {
    trait WPMU_ML_Core_OpenAI_Quality_Trait {
    private $openai_quality_runtime = [];

    private function openai_quality_runtime_reset() {
        $this->openai_quality_runtime = [
            'unavailable' => 0,
            'scopes' => [],
            'processed_scopes' => [],
            'effective_mode' => '',
            'term_inventory' => [],
            'raw_fields' => 0,
            'eligible_fields' => 0,
            'unique_pairs' => 0,
            'expected' => 0,
            'checked' => 0,
            'keep' => 0,
            'rewrite' => 0,
            'repaired' => 0,
            'repair_failed' => 0,
            'covered_raw_fields' => 0,
            'ai_candidate_unique' => 0,
            'ai_candidate_raw_fields' => 0,
            'ai_checked_unique' => 0,
            'ai_checked_raw_fields' => 0,
            'deterministic_checked' => 0,
            'self_reviewed_fields' => 0,
            // Legacy aliases retained for integrations reading the old runtime keys.
            'ai_candidate_fields' => 0,
            'adaptive_skipped_fields' => 0,
            'roles' => [],
            'field_overrides' => [],
            'editor_circuit_open' => false,
            'editor_circuit_reason' => '',
            'editor_circuit_skipped' => 0,
        ];
    }

    private function openai_quality_mark_unavailable($scope, $count, $reason = '') {
        if (!is_array($this->openai_quality_runtime) || !$this->openai_quality_runtime) {
            $this->openai_quality_runtime_reset();
        }
        $scope = sanitize_key((string)$scope);
        $count = max(1, (int)$count);
        $this->openai_quality_runtime['unavailable'] = (int)($this->openai_quality_runtime['unavailable'] ?? 0) + $count;
        if (!isset($this->openai_quality_runtime['scopes'][$scope])) {
            $this->openai_quality_runtime['scopes'][$scope] = ['count' => 0, 'reasons' => []];
        }
        $this->openai_quality_runtime['scopes'][$scope]['count'] += $count;
        if ($reason !== '') {
            $this->openai_quality_runtime['scopes'][$scope]['reasons'][] = $this->openai_cli_trace_snippet((string)$reason, 240);
        }
    }

    private function openai_quality_runtime_summary() {
        if (!is_array($this->openai_quality_runtime) || !$this->openai_quality_runtime) {
            return ['unavailable' => 0, 'scopes' => [], 'processed_scopes' => [], 'effective_mode' => '', 'term_inventory' => [], 'raw_fields' => 0, 'eligible_fields' => 0, 'unique_pairs' => 0, 'expected' => 0, 'checked' => 0, 'keep' => 0, 'rewrite' => 0, 'repaired' => 0, 'repair_failed' => 0, 'covered_raw_fields' => 0, 'ai_candidate_unique' => 0, 'ai_candidate_raw_fields' => 0, 'ai_checked_unique' => 0, 'ai_checked_raw_fields' => 0, 'deterministic_checked' => 0, 'self_reviewed_fields' => 0, 'ai_candidate_fields' => 0, 'adaptive_skipped_fields' => 0, 'roles' => [], 'field_overrides' => [], 'editor_circuit_open' => false, 'editor_circuit_reason' => '', 'editor_circuit_skipped' => 0];
        }
        return $this->openai_quality_runtime;
    }

    private function openai_editor_qa_circuit_is_open() {
        return !empty($this->openai_quality_runtime['editor_circuit_open']);
    }

    private function openai_editor_qa_open_circuit($reason = '') {
        if (!is_array($this->openai_quality_runtime) || !$this->openai_quality_runtime) {
            $this->openai_quality_runtime_reset();
        }
        $this->openai_quality_runtime['editor_circuit_open'] = true;
        if ((string)$reason !== '') {
            $this->openai_quality_runtime['editor_circuit_reason'] = (string)$reason;
        }
    }

    private function openai_editor_qa_mark_circuit_skip($count) {
        if (!is_array($this->openai_quality_runtime) || !$this->openai_quality_runtime) {
            $this->openai_quality_runtime_reset();
        }
        $this->openai_quality_runtime['editor_circuit_skipped'] = (int)($this->openai_quality_runtime['editor_circuit_skipped'] ?? 0) + max(0, (int)$count);
    }
    private function openai_audit_target_language_fields($source_fields, $translated_fields, $target_label, $settings) {
        $source_fields = is_array($source_fields) ? $source_fields : [];
        $translated_fields = is_array($translated_fields) ? $translated_fields : [];
        $audit_fields = [];

        foreach ($source_fields as $key => $source_value) {
            if (!array_key_exists($key, $translated_fields)) {
                continue;
            }
            $candidate = (string)$translated_fields[$key];
            if (!$this->openai_language_audit_should_check((string)$source_value, $candidate)) {
                continue;
            }
            $audit_fields[$key] = "SOURCE:\n"
                . $this->openai_language_audit_excerpt((string)$source_value)
                . "\n\nCANDIDATE:\n"
                . $this->openai_language_audit_excerpt($candidate);
        }

        if (!$audit_fields) {
            return [];
        }

        $instruction = '[WPMU_ML_LANGUAGE_AUDIT] Language-identification QA only; do not translate, rewrite, edit, polish or judge writing quality. For every input key, compare SOURCE and CANDIDATE against the configured source language and configured target language from the backend. Return exactly "ok" when the candidate ordinary prose is in the configured target language, even if it is awkward, non-native, literal, truncated by the excerpt, stylistically weak, or needs editorial improvement. Return "wrong:<brief detected language or reason>" only when ordinary prose is clearly in the source language or another natural language, or when visible source-language residue remains. Do not mark a field wrong merely because it preserves brands, product names, URLs, code, commands, filenames, identifiers, acronyms, model names, numbers, quotations, conventional technical terms, or punctuation that also appear in the source. Return ONLY valid JSON with the exact same keys and one short status string per key.';
        $audit = $this->openai_request_fields_with_recursive_split($audit_fields, $target_label, $settings, $instruction, 0, 'LANGUAGE AUDIT');
        if (is_wp_error($audit)) {
            $this->openai_cli_trace_line(sprintf(
                'LANGUAGE AUDIT status=unavailable target=%s checked=%d phase=initial reason=%s',
                $this->openai_target_primary_code($settings, $target_label) ?: 'configured',
                count($audit_fields),
                $audit->get_error_message()
            ));
            $this->openai_quality_mark_unavailable('language_audit', count($audit_fields), $audit->get_error_message());
            if (!empty($settings['openai_agent_fail_on_qa'])) {
                return $audit;
            }
            return [];
        }

        $wrong = [];
        $invalid = [];
        foreach ($audit_fields as $key => $unused) {
            $status = array_key_exists($key, $audit) ? trim((string)$audit[$key]) : '';
            $parsed = $this->openai_parse_language_audit_status($status);
            if ($parsed === 'ok') {
                continue;
            }
            if ($parsed === 'invalid') {
                $invalid[$key] = $audit_fields[$key];
                continue;
            }
            if ($this->openai_language_audit_wrong_reason_is_unsupported_by_candidate($parsed, (string)($translated_fields[$key] ?? ''))) {
                continue;
            }
            $wrong[$key] = $parsed;
        }

        // Compatible gateways occasionally omit statuses. Retry only invalid/missing
        // keys once, in bounded groups so a large article does not create another oversized QA response.
        if ($invalid) {
            $still_invalid = [];
            foreach (array_chunk($invalid, 50, true) as $invalid_chunk) {
                $retry = $this->openai_request_fields_with_recursive_split(
                    $invalid_chunk,
                    $target_label,
                    $settings,
                    $instruction . ' Recovery request: return every key exactly once and use only "ok" or "wrong:<brief reason>" as each value.',
                    0,
                    'LANGUAGE AUDIT'
                );
                if (is_wp_error($retry)) {
                    $this->openai_cli_trace_line(sprintf(
                        'LANGUAGE AUDIT status=unavailable target=%s checked=%d phase=recovery reason=%s',
                        $this->openai_target_primary_code($settings, $target_label) ?: 'configured',
                        count($invalid_chunk),
                        $retry->get_error_message()
                    ));
                    $this->openai_quality_mark_unavailable('language_audit', count($invalid_chunk), $retry->get_error_message());
                    if (!empty($settings['openai_agent_fail_on_qa'])) {
                        return $retry;
                    }
                    return [];
                }
                foreach ($invalid_chunk as $key => $unused) {
                    $status = array_key_exists($key, $retry) ? trim((string)$retry[$key]) : '';
                    $parsed = $this->openai_parse_language_audit_status($status);
                    if ($parsed === 'ok') {
                        continue;
                    }
                    if ($parsed === 'invalid') {
                        $still_invalid[] = $key;
                        continue;
                    }
                    if ($this->openai_language_audit_wrong_reason_is_unsupported_by_candidate($parsed, (string)($translated_fields[$key] ?? ''))) {
                        continue;
                    }
                    $wrong[$key] = $parsed;
                }
            }
            if ($still_invalid) {
                $this->openai_cli_trace_line(sprintf(
                    'LANGUAGE AUDIT status=unavailable target=%s checked=%d phase=invalid_status fields=%s',
                    $this->openai_target_primary_code($settings, $target_label) ?: 'configured',
                    count($audit_fields),
                    implode(',', array_slice($still_invalid, 0, 10))
                ));
                $this->openai_quality_mark_unavailable('language_audit', count($still_invalid), 'invalid status fields: ' . implode(',', array_slice($still_invalid, 0, 10)));
                if (!empty($settings['openai_agent_fail_on_qa'])) {
                    return new WP_Error('wpmu_ml_openai_qa_incomplete', '语言质检未能返回所有字段的有效状态。');
                }
                return [];
            }
        }

        $target_code = $this->openai_target_primary_code($settings, $target_label);
        $this->openai_cli_trace_line(sprintf(
            'LANGUAGE AUDIT status=completed target=%s checked=%d wrong=%d',
            $target_code !== '' ? $target_code : 'configured',
            count($audit_fields),
            count($wrong)
        ));
        foreach (array_slice($wrong, 0, 5, true) as $key => $reason) {
            $this->openai_cli_trace_line(sprintf(
                'LANGUAGE AUDIT REJECT key=%s reason=%s source=%s returned=%s',
                $key,
                $reason,
                $this->openai_cli_trace_snippet((string)($source_fields[$key] ?? '')),
                $this->openai_cli_trace_snippet((string)($translated_fields[$key] ?? ''))
            ));
        }

        return $wrong;
    }

    private function openai_verify_post_writeback($blog_id, $post_id, $expected) {
        $blog_id = absint($blog_id);
        $post_id = absint($post_id);
        $expected = is_array($expected) ? $expected : [];
        if (!$blog_id || !$post_id) {
            return new WP_Error('wpmu_ml_writeback_invalid_post', 'PHP 写回完整性检查失败：目标站点或文章 ID 无效。');
        }
        switch_to_blog($blog_id);
        clean_post_cache($post_id);
        $post = get_post($post_id);
        restore_current_blog();
        if (!$post) {
            return new WP_Error('wpmu_ml_writeback_post_missing', 'PHP 写回完整性检查失败：写入后无法读取目标文章。');
        }
        foreach (['post_title', 'post_excerpt', 'post_content', 'post_status', 'post_name'] as $field) {
            if (!array_key_exists($field, $expected)) {
                continue;
            }
            if ((string)$post->$field !== (string)$expected[$field]) {
                $this->openai_cli_trace_line('WRITEBACK VERIFY scope=post status=failed field=' . $field . ' blog_id=' . $blog_id . ' post_id=' . $post_id);
                return new WP_Error(
                    'wpmu_ml_writeback_post_mismatch',
                    'PHP 写回完整性检查失败：字段 ' . $field . ' 写入值与回读值不一致。'
                );
            }
        }
        $this->openai_cli_trace_line('WRITEBACK VERIFY scope=post status=ok fields=' . count($expected) . ' blog_id=' . $blog_id . ' post_id=' . $post_id);
        return true;
    }

    private function openai_normalize_writeback_value($value) {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $child) {
                $out[$key] = $this->openai_normalize_writeback_value($child);
            }
            return ['__type' => 'array', '__value' => $out];
        }
        if (is_object($value)) {
            $out = [];
            foreach (get_object_vars($value) as $key => $child) {
                $out[$key] = $this->openai_normalize_writeback_value($child);
            }
            return ['__type' => 'object:' . get_class($value), '__value' => $out];
        }
        if ($value === null) {
            return '';
        }
        return (string)$value;
    }

    private function openai_writeback_values_equal($expected, $actual) {
        return serialize($this->openai_normalize_writeback_value($expected))
            === serialize($this->openai_normalize_writeback_value($actual));
    }

    private function openai_final_non_cjk_residue_check($job, $target_context, $complete_status = '', $settings = []) {
        // 0.9.6.3: source-language residue is an editorial signal, not a PHP publish gate.
        // Candidate-level residue hints are supplied to the optional AI quality review. A local
        // heuristic must never rewrite content, force draft status, or override an AI `keep`.
        $this->openai_cli_trace_line('FINAL RESIDUE GATE disabled policy=ai_advisory_only');
        return true;
    }

    private function openai_extract_han_snippet($text) {
        $text = preg_replace('/\s+/u', ' ', wp_strip_all_tags((string)$text));
        if (preg_match('/.{0,40}[\x{3400}-\x{9FFF}].{0,40}/u', $text, $m)) {
            return trim((string)$m[0]);
        }
        return '';
    }

    private function openai_language_audit_wrong_reason_is_unsupported_by_candidate($reason, $candidate) {
        $reason = strtolower((string)$reason);
        $candidate = (string)$candidate;
        if ($candidate === '') {
            return false;
        }
        // Match language labels as complete words. The old bare `han` alternative also
        // matched ordinary words such as `changes`, causing a genuine QA finding like
        // "changes 月薪 to monthly budget" to be silently discarded.
        if (preg_match('/(?:\b(?:chinese|source(?:[-_ ]?language)?|cjk|han)\b|中文|源语)/iu', $reason)
            && !preg_match('/[\x{3400}-\x{9FFF}]/u', $candidate)) {
            return true;
        }
        return false;
    }

    /**
     * Parse model/editor QA status strings defensively.
     *
     * Compatible gateways and models do not always follow the requested `rewrite:` prefix;
     * they may return `wrong:`, `fail:`, `reject:` or a JSON-encoded status object. Treat all
     * explicit negative quality verdicts as rewrite requests instead of silently passing them.
     */
    private function openai_parse_editorial_audit_status($status) {
        if (is_array($status)) {
            $action = strtolower(trim((string)($status['action'] ?? $status['status'] ?? $status['result'] ?? '')));
            $reason = trim((string)($status['reason'] ?? $status['issue'] ?? $status['message'] ?? ''));
        } else {
            $raw = trim((string)$status);
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $action = strtolower(trim((string)($decoded['action'] ?? $decoded['status'] ?? $decoded['result'] ?? '')));
                $reason = trim((string)($decoded['reason'] ?? $decoded['issue'] ?? $decoded['message'] ?? ''));
            } else {
                $normalized = strtolower(trim($raw, " \t\n\r\0\x0B\"'`."));
                if (preg_match('/^(keep|ok|pass|correct|accept|accepted|no[-_ ]?change|unchanged)(?:\\s*[:：\/-]\\s*(.*))?$/iu', $normalized, $m)) {
                    return ['action' => 'keep', 'reason' => trim((string)($m[2] ?? ''))];
                }
                if (preg_match('/^(rewrite|wrong|fail|failed|reject|rejected|fix|revise|revision|improve|needs?[-_ ]?(?:rewrite|revision|fix))(?:\\s*[:：\/-]\\s*(.*))?$/iu', $normalized, $m)) {
                    return [
                        'action' => 'rewrite',
                        'reason' => trim((string)($m[2] ?? '')) ?: 'editorial quality issue',
                    ];
                }
                return ['action' => 'invalid', 'reason' => $raw];
            }
        }

        if (in_array($action, ['keep','ok','pass','correct','accept','accepted','no_change','unchanged'], true)) {
            return ['action' => 'keep', 'reason' => $reason];
        }
        if (in_array($action, ['rewrite','wrong','fail','failed','reject','rejected','fix','revise','revision','improve'], true)) {
            return ['action' => 'rewrite', 'reason' => $reason !== '' ? $reason : 'editorial quality issue'];
        }
        return ['action' => 'invalid', 'reason' => $reason !== '' ? $reason : (string)$action];
    }

    private function openai_parse_language_audit_status($status) {
        $status = trim((string)$status);
        $normalized = strtolower($status);
        $normalized = trim($normalized, " \t\n\r\0\x0B\"'`.");
        if (in_array($normalized, ['ok', 'pass', 'true', 'correct', 'target'], true)) {
            return 'ok';
        }
        if (preg_match('/^(?:wrong|fail|false|off[-_ ]?target|source[-_ ]?language)(?:\s*[:：\/-]\s*(.*))?$/iu', $normalized, $m)) {
            $reason = trim((string)($m[1] ?? ''));
            return $reason !== '' ? $reason : 'off_target_language';
        }
        return 'invalid';
    }

    /**
     * Return true only for obvious non-prose values that are expected to remain byte-for-byte
     * equivalent across languages. This is intentionally based on value shape, not on any
     * source/target language code, so the rule works for every configured language pair.
     */

    private function openai_language_invariant_pair($source, $candidate) {
        $source = preg_replace('/[\s\x{00A0}]+/u', ' ', trim((string)$source));
        $candidate = preg_replace('/[\s\x{00A0}]+/u', ' ', trim((string)$candidate));
        if (!is_string($source) || !is_string($candidate) || $source === '' || $candidate === '') {
            return false;
        }
        if ($source !== $candidate) {
            return false;
        }

        // Full URLs, protocol-relative URLs, e-mail addresses and telephone links.
        if (preg_match('~^(?:(?:https?:)?//|mailto:|tel:)\S+$~iu', $source)) {
            return true;
        }
        if (preg_match('/^[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,63}$/iu', $source)) {
            return true;
        }

        // Bare host/domain names, optionally followed by a path/query/fragment.
        if (preg_match('~^(?:[A-Z0-9](?:[A-Z0-9\-]{0,61}[A-Z0-9])?\.)+[A-Z]{2,63}(?:[/:?#][^\s]*)?$~iu', $source)) {
            return true;
        }

        // File names, relative paths, code identifiers and command-like tokens without prose.
        if (!preg_match('/\s/u', $source)
            && preg_match('/^[A-Z0-9_@#%+.,:\/\\\-]+$/iu', $source)
            && preg_match('/[._:\/\\@#]/u', $source)
            && $this->wpmu_ml_strlen($source) <= 160) {
            return true;
        }

        // A single Latin product/brand/model token may legitimately stay unchanged.
        if (preg_match('/^[A-Z][A-Z0-9&+._\-]{1,63}$/iu', $source)) {
            return true;
        }

        // Short conventional product/technical names such as "Redis Cluster",
        // "Tencent Cloud TDSQL-C" or "Google Analytics" can remain identical in
        // many target languages. Keep this narrow: title-cased Latin tokens only,
        // short length, no stopword glue, and at least one technical marker unless
        // it is exactly a two-token proper technical/product name.
        $token_count = preg_match_all('/\S+/u', $source, $m);
        if ($token_count >= 2 && $token_count <= 4 && $this->wpmu_ml_strlen($source) <= 64) {
            $tokens = preg_split('/\s+/u', $source, -1, PREG_SPLIT_NO_EMPTY);
            $allowed = is_array($tokens) && count($tokens) === $token_count;
            $has_technical_marker = false;
            $has_technical_word = false;
            foreach ((array)$tokens as $token) {
                $token = (string)$token;
                if (!preg_match('/^[A-Z][A-Za-z0-9&+._\-]{0,31}$/u', $token)) {
                    $allowed = false;
                    break;
                }
                if (preg_match('/[0-9&+._\-]/u', $token) || preg_match('/[A-Z]{2,}/u', $token)) {
                    $has_technical_marker = true;
                }
                if (preg_match('/^(?:Cloud|Cluster|Analytics|Database|Server|Redis|MySQL|PostgreSQL|MongoDB|Kubernetes|Docker|WordPress|WooCommerce|Nginx|Apache|Node|React|Vue|Laravel)$/u', $token)) {
                    $has_technical_word = true;
                }
                if (preg_match('/^(?:and|or|the|of|for|to|with|in|on|by|from|at|is|are|a|an|more|learn|solution|service|services)$/iu', $token)) {
                    $allowed = false;
                    break;
                }
            }
            if ($allowed && ($has_technical_marker || $has_technical_word)) {
                return true;
            }
        }

        // Numbered product/brand list labels such as "1. Divi" or "2) WooCommerce".
        // Restrict the payload to one Latin token so ordinary prose such as "1. Learn more"
        // still goes through the configured-language audit.
        if (preg_match('/^\s*\d+(?:\.\d+)*\s*[.)、:\-]?\s*[A-Z][A-Z0-9&+._\-]{1,63}\s*$/iu', $source)) {
            return true;
        }

        // Compact version/model labels such as "PHP 8.3" or "WordPress 6.5".
        if (preg_match('/^[A-Z][A-Z0-9&+._\-]{1,31}\s+v?\d+(?:\.\d+){0,4}(?:[-_A-Z0-9.]*)$/iu', $source)) {
            return true;
        }

        return false;
    }

    private function openai_language_audit_should_check($source, $candidate) {
        $source_plain = $this->openai_language_audit_excerpt((string)$source, 1400);
        $candidate_plain = $this->openai_language_audit_excerpt((string)$candidate, 1400);
        if ($candidate_plain === '') {
            return false;
        }
        if ($this->openai_language_invariant_pair($source_plain, $candidate_plain)) {
            return false;
        }
        $letters = preg_match_all('/\p{L}/u', $candidate_plain, $m);
        $length = function_exists('mb_strlen') ? mb_strlen($candidate_plain, 'UTF-8') : strlen($candidate_plain);
        if ($letters < 3 || $length < 6) {
            return false;
        }
        if (preg_match('~^(?:https?:)?//|^mailto:|^tel:~iu', $candidate_plain)) {
            return false;
        }
        if (preg_match('/^[\p{L}\p{N}._+\-\/\\:]+$/u', $candidate_plain)
            && !preg_match('/[\s,，.。!?！？;；:：]/u', $candidate_plain)
            && $length <= 32) {
            return false;
        }
        return true;
    }

    private function openai_language_audit_excerpt($value, $limit = 700) {
        $value = $this->openai_normalize_translation_control_placeholders((string)$value);
        $value = preg_replace('/__WPMU_ML_ATOMIC_\d+__/u', ' ', (string)$value);
        $value = html_entity_decode(wp_strip_all_tags((string)$value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\s\x{00A0}]+/u', ' ', trim((string)$value));
        if (!is_string($value) || $value === '') {
            return '';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length <= $limit) {
            return $value;
        }
        $head = (int)floor($limit * 0.7);
        $tail = max(20, $limit - $head);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $head, 'UTF-8') . ' … ' . mb_substr($value, -$tail, $tail, 'UTF-8');
        }
        return substr($value, 0, $head) . ' … ' . substr($value, -$tail);
    }

    private function openai_translation_fragment_rejection_reason($source, $translated, $settings, $target_label = '') {
        $source = (string)$source;
        $translated = (string)$translated;
        if (trim($translated) === '') {
            return 'empty';
        }
        if (!$this->openai_translation_preserves_control_tokens($source, $translated)) {
            return 'placeholder_damage';
        }
        return $this->openai_translation_structure_integrity_issue($source, $translated);
    }

    /**
     * Mandatory PHP structure validation. This deliberately excludes residue, numeric and
     * length heuristics; those are advisory AI signals in 0.9.6.4.
     */
    private function openai_translation_structure_integrity_issue($source, $translated) {
        $source = (string)$source;
        $translated = (string)$translated;
        if (preg_match('/u00(?:3c|3e|22|27|26|2f)/i', $translated)) {
            return 'escaped_html_pollution';
        }

        $source_skeleton = $this->openai_quality_json_key_skeleton($source);
        if ($source_skeleton !== null) {
            $target_skeleton = $this->openai_quality_json_key_skeleton($translated);
            if ($target_skeleton === null || $source_skeleton !== $target_skeleton) {
                return 'json_structure_mismatch';
            }
        }

        $patterns = [
            'wp_block_comments' => '/<!--\s*\/?wp:[^>]*-->/i',
            'shortcodes' => '/\[\/?[A-Za-z0-9_-]+(?:\s[^\]]*)?\]/u',
            'template_variables' => '/%[A-Za-z0-9_-]+%|%%[A-Za-z0-9_-]+%%|\{\{[^{}]+\}\}|\[[A-Za-z0-9_-]+\]/u',
            'urls' => '~https?://[^\s"\'<>]+~i',
        ];
        foreach ($patterns as $name => $pattern) {
            preg_match_all($pattern, $source, $source_matches);
            preg_match_all($pattern, $translated, $target_matches);
            $source_values = array_values((array)($source_matches[0] ?? []));
            $target_values = array_values((array)($target_matches[0] ?? []));
            sort($source_values, SORT_STRING);
            sort($target_values, SORT_STRING);
            if ($source_values !== $target_values) {
                return $name . '_mismatch';
            }
        }

        preg_match_all('/<\/?(?!wpmu-ml-\d+\b)([A-Za-z][A-Za-z0-9:-]*)\b/u', $source, $source_tags);
        preg_match_all('/<\/?(?!wpmu-ml-\d+\b)([A-Za-z][A-Za-z0-9:-]*)\b/u', $translated, $target_tags);
        $source_tag_values = array_map('strtolower', array_values((array)($source_tags[0] ?? [])));
        $target_tag_values = array_map('strtolower', array_values((array)($target_tags[0] ?? [])));
        sort($source_tag_values, SORT_STRING);
        sort($target_tag_values, SORT_STRING);
        if ($source_tag_values !== $target_tag_values) {
            return 'html_tag_mismatch';
        }
        return '';
    }

    /** Advisory-only local signals supplied to AI. */
    private function openai_quality_advisory_flags($source, $target, $settings, $target_label = '') {
        $flags = [];
        $source = (string)$source;
        $target = (string)$target;

        $target_primary = $this->openai_target_primary_code($settings, $target_label);
        if (!in_array($target_primary, ['ja', 'zh', 'zh-hans', 'zh-hant'], true)
            && preg_match('/[\x{3400}-\x{9FFF}]/u', wp_strip_all_tags($target))) {
            $flags[] = 'suspected_source_residue:han';
        }
        $source_norm = $this->openai_quality_normalize_pair_text(wp_strip_all_tags($source));
        $target_norm = $this->openai_quality_normalize_pair_text(wp_strip_all_tags($target));
        if ($source_norm !== '' && $source_norm === $target_norm && $this->openai_contains_translatable_source_text($source_norm)) {
            $flags[] = 'suspected_unchanged_source';
        }

        $numeric_hint = $this->openai_quality_number_entity_boundary_issue($source, $target);
        if ($numeric_hint !== '') {
            $flags[] = 'suspected_' . $numeric_hint;
        }

        $source_len = $this->openai_quality_plain_text_length($source);
        $target_len = $this->openai_quality_plain_text_length($target);
        if ($source_len >= 20 && ($target_len < max(4, (int)floor($source_len * 0.22)) || $target_len > (int)ceil($source_len * 4.8))) {
            $flags[] = 'suspected_abnormal_length_ratio';
        }

        return array_values(array_unique($flags));
    }

    /**
     * Legacy compatibility shim. Target-language identity is now checked by the generic
     * source-to-target audit, which reads the configured source/target language contexts.
     */

    private function openai_translation_wrong_target_language_reason($source, $translated, $settings, $target_label = '') {
        return '';
    }

    /**
     * Lightweight, language-neutral editorial repair after the one-pass article translation.
     * Only structural defects that can be detected without knowing a particular language are
     * retried. The model receives the configured source and target language contexts.
     */

    private function openai_repair_suspicious_article_blocks($source_fragments, $translated_fragments, $items, $target_label, $settings) {
        // 0.9.6.3: PHP does not perform local editorial rewrites. Punctuation, residue,
        // numeric and length concerns belong to the optional AI review. Hard structural
        // defects are rejected by openai_translation_fragment_rejection_reason().
        return (array)$translated_fragments;
    }

    /**
     * Whole-article AI editorial review.
     *
     * The first pass already sees the ordered article, but a compatible model can still keep
     * a broken source fragment too literally. This second pass asks an AI editor to identify
     * real defects by comparing source and target blocks in article order, then rewrites only
     * flagged blocks with neighboring context. No language, script or grammar pattern is
     * hard-coded here.
     */

    private function openai_article_block_needs_editor_ai($source, $current, $type = 'text') {
        $source = trim((string)$source);
        $current = trim((string)$current);
        $type = strtolower((string)$type);
        if ($source === '' || $current === '') {
            return false;
        }
        $source_len = $this->wpmu_ml_strlen($source);
        $target_len = $this->wpmu_ml_strlen($current);
        $combined = $source . ' ' . $current;

        // Human-readable workflow diagrams are already checked deterministically for
        // source-language residue and arrow preservation. Do not spend multiple QA calls
        // asking a reasoning model to approve a short, successfully translated flow.
        if (preg_match('/(?:→|⇒|➜|⟶|\s->\s)/u', $source)) {
            preg_match_all('/(?:→|⇒|➜|⟶|\s->\s)/u', $source, $source_arrows);
            preg_match_all('/(?:→|⇒|➜|⟶|\s->\s)/u', $current, $target_arrows);
            $source_has_han = (bool)preg_match('/[\x{3400}-\x{9FFF}]/u', $source);
            $target_has_han = (bool)preg_match('/[\x{3400}-\x{9FFF}]/u', $current);
            if (count($source_arrows[0] ?? []) === count($target_arrows[0] ?? [])
                && (!$source_has_han || !$target_has_han)
                && $target_len >= max(8, (int)floor($source_len * 0.55))) {
                return false;
            }
            return true;
        }

        // Facts, prices, billing periods, specifications and protected placeholders
        // remain high-risk and always receive AI editorial review.
        if (preg_match('/[0-9]|[$€£¥￥]|\b(?:RMB|CNY|USD|EUR|GBP)\b|<wpmu-ml-|__WPMU_ML_/iu', $combined)) {
            return true;
        }
        if ($source_len >= 120 || $target_len >= 180) {
            return true;
        }
        if (in_array($type, ['paragraph', 'quote', 'list_item', 'table', 'cell', 'caption'], true)
            && ($source_len >= 70 || $target_len >= 110)) {
            return true;
        }

        // Short headings, labels and ordinary short list items are covered by deterministic
        // residue, placeholder, punctuation and HTML-boundary checks.
        return $source_len >= 65 || $target_len >= 105;
    }

    private function openai_article_block_editor_risk_score($source, $current, $type = 'text') {
        $source = trim((string)$source);
        $current = trim((string)$current);
        $type = strtolower((string)$type);
        if ($source === '' || $current === '') {
            return 0;
        }

        $source_len = $this->wpmu_ml_strlen($source);
        $target_len = $this->wpmu_ml_strlen($current);
        $combined = $source . ' ' . $current;
        $score = 0;

        // A successfully translated human-readable workflow is safer to validate
        // deterministically than to spend a reasoning request asking for approval.
        if (preg_match('/(?:→|⇒|➜|⟶|\s->\s)/u', $source)) {
            preg_match_all('/(?:→|⇒|➜|⟶|\s->\s)/u', $source, $source_arrows);
            preg_match_all('/(?:→|⇒|➜|⟶|\s->\s)/u', $current, $target_arrows);
            $source_has_han = (bool)preg_match('/[\x{3400}-\x{9FFF}]/u', $source);
            $target_has_han = (bool)preg_match('/[\x{3400}-\x{9FFF}]/u', $current);
            if (count($source_arrows[0] ?? []) === count($target_arrows[0] ?? [])
                && (!$source_has_han || !$target_has_han)
                && $target_len >= max(8, (int)floor($source_len * 0.55))) {
                return 0;
            }
            $score += 220;
        }

        if (preg_match('/<wpmu-ml-|__WPMU_ML_/u', $combined)) {
            $score += 220;
        }
        if (preg_match('/[$€£¥￥]|\b(?:RMB|CNY|USD|EUR|GBP)\b|(?:元|块|美元|欧元|英镑)/iu', $combined)) {
            $score += 180;
        }
        if (preg_match('/\b\d+(?:[.,]\d+)?\s*(?:v?CPU|cores?|GB|TB|MB|Mbps|Gbps|MHz|GHz|years?|months?|days?|hours?|%|元|年|月|天|核|G|M)\b/iu', $combined)) {
            $score += 160;
        } elseif (preg_match('/\d/u', $combined)) {
            $score += 70;
        }
        if (preg_match('~https?://|(?:[A-Za-z0-9-]+\.)+[A-Za-z]{2,}|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}~u', $combined)) {
            $score += 130;
        }

        $generic_issue = $this->openai_generic_translation_block_edit_issue($source, $current, $type);
        if ($generic_issue !== '') {
            $score += 240;
        }

        if ($source_len >= 160 || $target_len >= 230) {
            $score += 120;
        } elseif ($source_len >= 100 || $target_len >= 150) {
            $score += 75;
        } elseif ($source_len >= 70 || $target_len >= 110) {
            $score += 35;
        }

        if (in_array($type, ['paragraph', 'quote', 'table', 'cell', 'caption'], true)) {
            $score += 25;
        }
        if (in_array($type, ['h1', 'h2', 'h3', 'h4', 'title'], true) && ($source_len >= 20 || $target_len >= 35)) {
            $score += 45;
        }

        // Ordinary short labels/list items stay on deterministic validation unless a
        // concrete risk signal above raised the score.
        return $score;
    }

    private function openai_quality_coverage_mode($settings) {
        $mode = sanitize_key((string)($settings['openai_qa_coverage_mode'] ?? 'adaptive'));
        return in_array($mode, ['adaptive', 'all', 'risk', 'off'], true) ? $mode : 'adaptive';
    }

    private function openai_quality_scope_processed($scope) {
        $scope = sanitize_key((string)$scope);
        return !empty($this->openai_quality_runtime['processed_scopes'][$scope]);
    }

    private function openai_quality_field_override($key, $default = '') {
        $key = sanitize_key((string)$key);
        if ($key !== '' && isset($this->openai_quality_runtime['field_overrides'][$key])) {
            return (string)$this->openai_quality_runtime['field_overrides'][$key];
        }
        return (string)$default;
    }

    private function openai_quality_role_from_type($type) {
        $type = strtolower(trim((string)$type));
        if ($type === 'title' || $type === 'seo_title') {
            return 'title';
        }
        if (in_array($type, ['summary', 'excerpt', 'seo_description', 'description'], true)) {
            return 'summary';
        }
        if (in_array($type, ['h1','h2','h3','h4','h5','h6','heading'], true)) {
            return 'heading';
        }
        if (in_array($type, ['li','list_item'], true)) {
            return 'list_item';
        }
        if (in_array($type, ['td','th','table','cell','table_cell','caption'], true)) {
            return 'table_cell';
        }
        if (in_array($type, ['seo_keywords','keyword_list'], true)) {
            return 'keyword_list';
        }
        if (in_array($type, ['button','option','short_ui','label','attribute'], true)) {
            return 'short_text';
        }
        if ($type === 'metadata' || $type === 'postmeta' || $type === 'gutenberg') {
            return 'metadata';
        }
        return 'paragraph';
    }

    private function openai_quality_role_priority($role) {
        $map = [
            'title' => 90,
            'summary' => 80,
            'heading' => 70,
            'keyword_list' => 65,
            'paragraph' => 60,
            'table_cell' => 55,
            'list_item' => 50,
            'metadata' => 45,
            'short_text' => 40,
        ];
        return (int)($map[(string)$role] ?? 30);
    }

    private function openai_quality_normalize_pair_text($text) {
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', trim((string)$text));
        return is_string($text) ? $text : trim((string)$text);
    }

    private function openai_quality_extract_term_inventory($rows) {
        $terms = [];
        foreach ((array)$rows as $row) {
            $text = (string)($row['source'] ?? '') . "\n" . (string)($row['target'] ?? '');
            preg_match_all('/\b[A-Z][A-Za-z0-9+._-]*(?:\s+[A-Z][A-Za-z0-9+._-]*){0,3}\b/u', $text, $matches);
            foreach ((array)($matches[0] ?? []) as $term) {
                $term = trim((string)$term);
                if ($term === '' || $this->wpmu_ml_strlen($term) > 80) {
                    continue;
                }
                $terms[$term] = (int)($terms[$term] ?? 0) + 1;
            }
        }
        arsort($terms, SORT_NUMERIC);
        return array_slice(array_keys($terms), 0, 40);
    }

    /**
     * Extract short Han-script fragments that occur in both a flagged source/target pair.
     * They are used only to expand AI review; PHP never rewrites them.
     */
    private function openai_quality_shared_han_fragments($source, $target) {
        $source = (string)$source;
        $target = (string)$target;
        preg_match_all('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]{2,12}/u', $target, $matches);
        $tokens = [];
        foreach ((array)($matches[0] ?? []) as $run) {
            $run = (string)$run;
            $characters = preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($characters)) {
                continue;
            }
            $length = count($characters);
            $max_len = min(8, $length);
            for ($size = $max_len; $size >= 2; $size--) {
                for ($offset = 0; $offset <= $length - $size; $offset++) {
                    $token = implode('', array_slice($characters, $offset, $size));
                    if ($token === '' || strpos($source, $token) === false) {
                        continue;
                    }
                    $tokens[$token] = $size;
                }
            }
        }
        if (!$tokens) {
            return [];
        }
        uksort($tokens, static function($a, $b) use ($tokens) {
            $cmp = ((int)$tokens[$b]) <=> ((int)$tokens[$a]);
            return $cmp !== 0 ? $cmp : strcmp((string)$a, (string)$b);
        });
        $selected = [];
        foreach (array_keys($tokens) as $token) {
            $contained = false;
            foreach ($selected as $existing) {
                if (strpos($existing, $token) !== false) {
                    $contained = true;
                    break;
                }
            }
            if (!$contained) {
                $selected[] = $token;
            }
            if (count($selected) >= 8) {
                break;
            }
        }
        return $selected;
    }

    /**
     * Extract reusable source-side concept fragments for AI candidate expansion.
     * This is language-neutral candidate discovery only; PHP never maps or replaces text.
     */
    private function openai_quality_source_semantic_fragments($source) {
        $plain = html_entity_decode(wp_strip_all_tags((string)$source), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/<\/?wpmu-ml-\d+\s*\/?>|__WPMU_ML_(?:ATOMIC|MACHINE)_\d+__/iu', ' ', (string)$plain);
        $plain = preg_replace('/[\s\x{00A0}]+/u', ' ', trim((string)$plain));
        if ($plain === '') {
            return [];
        }
        $tokens = [];

        // No-space scripts: retain 3-8 character concepts, then later keep only fragments
        // that actually recur in another source field of the same article.
        preg_match_all('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]{3,16}/u', $plain, $han_runs);
        foreach ((array)($han_runs[0] ?? []) as $run) {
            $chars = preg_split('//u', (string)$run, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($chars)) {
                continue;
            }
            $length = count($chars);
            for ($size = min(8, $length); $size >= 3; $size--) {
                for ($offset = 0; $offset <= $length - $size; $offset++) {
                    $token = implode('', array_slice($chars, $offset, $size));
                    if ($token !== '') {
                        $tokens[$token] = $size;
                    }
                }
            }
        }

        // Space-delimited scripts: collect compact 2-4 word phrases.
        preg_match_all('/\p{L}[\p{L}\p{N}._+-]*/u', $plain, $word_matches);
        $words = array_values((array)($word_matches[0] ?? []));
        $word_count = count($words);
        for ($size = min(4, $word_count); $size >= 2; $size--) {
            for ($offset = 0; $offset <= $word_count - $size; $offset++) {
                $token = implode(' ', array_slice($words, $offset, $size));
                if ($this->wpmu_ml_strlen($token) >= 8 && $this->wpmu_ml_strlen($token) <= 80) {
                    $tokens[$token] = $this->wpmu_ml_strlen($token);
                }
            }
        }

        arsort($tokens, SORT_NUMERIC);
        return array_slice(array_keys($tokens), 0, 120);
    }

    private function openai_quality_reason_suggests_source_residue($reason) {
        return preg_match('/(?:source[- ]?language|untranslated|residue|mixed[- ]?language|non[- ]?target|Chinese|中文|中国語|残留|未翻译|未翻訳|混在|直译词|直訳語)/iu', (string)$reason) === 1;
    }

    private function openai_quality_partition_batches($fields, $max_fields, $max_chars) {
        $fields = is_array($fields) ? $fields : [];
        $max_fields = max(1, (int)$max_fields);
        $max_chars = max(500, (int)$max_chars);
        $batches = [];
        $batch = [];
        $chars = 0;
        foreach ($fields as $key => $value) {
            $length = $this->wpmu_ml_strlen((string)$value);
            if ($batch && (count($batch) >= $max_fields || ($chars + $length) > $max_chars)) {
                $batches[] = $batch;
                $batch = [];
                $chars = 0;
            }
            $batch[$key] = (string)$value;
            $chars += $length;
            if ($length > $max_chars) {
                $batches[] = $batch;
                $batch = [];
                $chars = 0;
            }
        }
        if ($batch) {
            $batches[] = $batch;
        }
        return $batches;
    }

    private function openai_quality_normalize_numeric_text($text) {
        $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (function_exists('mb_convert_kana')) {
            $text = mb_convert_kana($text, 'n', 'UTF-8');
        }
        $text = preg_replace('/[\x{00A0}\x{2007}\x{202F}]/u', ' ', (string)$text);
        // Normalize common 1-to-1 spellings without forcing ordinary semantic numbers to stay digits.
        $text = preg_replace('/\bone[\s-]+on[\s-]+one\b/iu', '1v1', (string)$text);
        $text = preg_replace('/(?<!\d)1\s*(?:对|對|대|v|vs\.?|×|x|:)\s*1(?!\d)/iu', '1v1', (string)$text);
        // Models occasionally insert a space after a thousands separator: 20, 000 -> 20,000.
        $text = preg_replace('/(?<=\d),\s+(?=\d{3}(?:\D|$))/u', ',', (string)$text);
        return is_string($text) ? $text : (string)$text;
    }

    private function openai_quality_canonical_number($value) {
        $value = trim((string)$value);
        $value = preg_replace('/\s+/u', '', $value);
        // Commas are treated as thousands separators. Decimal points remain significant.
        $value = str_replace(',', '', (string)$value);
        $value = ltrim((string)$value, '+');
        if (preg_match('/^-?\d+\.0+$/', (string)$value)) {
            $value = preg_replace('/\.0+$/', '', (string)$value);
        }
        return (string)$value;
    }

    private function openai_quality_collect_numbers_from_matches($text, $pattern, &$positions) {
        preg_match_all($pattern, (string)$text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ((array)$matches as $match) {
            $whole = (string)($match[0][0] ?? '');
            $base_offset = (int)($match[0][1] ?? 0);
            preg_match_all('/\d+(?:[.,]\s*\d+)*/u', $whole, $numbers, PREG_OFFSET_CAPTURE);
            foreach ((array)($numbers[0] ?? []) as $number) {
                $canonical = $this->openai_quality_canonical_number((string)$number[0]);
                if ($canonical === '') {
                    continue;
                }
                $positions[($base_offset + (int)$number[1]) . ':' . $canonical] = $canonical;
            }
        }
    }

    private function openai_quality_hard_numeric_values($text) {
        $text = $this->openai_quality_normalize_numeric_text($text);
        $positions = [];
        $number = '\\d+(?:[.,]\\s*\\d+)*';
        $unit = '(?:%|％|ms|msec|毫秒|秒|分钟|分鐘|小时|小時|天|周|星期|个月|個月|月|年|GB|MB|TB|KB|GiB|MiB|kbps|Mbps|Gbps|QPS|TPS|FPS|Hz|kHz|MHz|GHz|CPU|GPU|RAM|核|核心|线程|執行緒|进程|連線|连接|并发|用户|用戶|观众|觀眾|人|路|流|房间|房間|设备|設備|台|美元|美金|人民币|人民幣|元|円|日元|韩元|韓元|卢布|盧布|USD|CNY|RMB|EUR|GBP|JPY|KRW|RUB|dollars?|euros?|pounds?|yen|won|rubles?|users?|viewers?|people|connections?|streams?|rooms?|devices?|cores?|threads?|processes?|guests?|speakers?|participants?|seconds?|minutes?|hours?|days?|weeks?|months?|years?)';
        $patterns = [
            '/(?:[$€£¥￥₽]\s*' . $number . '|(?:USD|CNY|RMB|EUR|GBP|JPY|KRW|RUB)\s*' . $number . '|' . $number . '\s*(?:USD|CNY|RMB|EUR|GBP|JPY|KRW|RUB|美元|美金|人民币|人民幣|元|円|日元|韩元|韓元|卢布|盧布|dollars?|euros?|pounds?|yen|won|rubles?))/iu',
            '/' . $number . '\s*(?:%|％)/u',
            '/(?:v(?:ersion)?\s*)?' . $number . '(?:\.' . $number . ')+(?!\w)/iu',
            '/(?<!\d)\d+\s*v\s*\d+(?!\d)/iu',
            '/\d{2,4}\s*(?:[-\/.年])\s*\d{1,2}(?:\s*(?:[-\/.月])\s*\d{1,2}\s*日?)?/u',
            '/' . $number . '\s*(?:[-~～—–至到to]+)\s*' . $number . '\s*' . $unit . '/iu',
            '/' . $number . '\s*' . $unit . '/iu',
            '/' . $number . '\s*\+/u',
        ];
        foreach ($patterns as $pattern) {
            $this->openai_quality_collect_numbers_from_matches($text, $pattern, $positions);
        }
        $values = array_values($positions);
        sort($values, SORT_STRING);
        return $values;
    }

    private function openai_quality_magnitude_totals($text) {
        $text = $this->openai_quality_normalize_numeric_text($text);
        $magnitude = '(万|萬|亿|億|兆|百万|百萬|千万|千萬|million|billion|trillion|mn|bn|억|만|миллион(?:а|ов)?|миллиард(?:а|ов)?)';
        preg_match_all('/(\d+(?:[.,]\s*\d+)*)\s*' . $magnitude . '/iu', (string)$text, $matches, PREG_SET_ORDER);
        $out = [];
        foreach ((array)$matches as $match) {
            $number = (float)$this->openai_quality_canonical_number((string)($match[1] ?? '0'));
            $unit = strtolower((string)($match[2] ?? ''));
            $multiplier = 1.0;
            if (in_array($unit, ['万','萬','만'], true)) {
                $multiplier = 1.0e4;
            } elseif (in_array($unit, ['亿','億','억'], true)) {
                $multiplier = 1.0e8;
            } elseif (in_array($unit, ['百万','百萬','million','mn'], true) || strpos($unit, 'миллион') === 0) {
                $multiplier = 1.0e6;
            } elseif (in_array($unit, ['千万','千萬'], true)) {
                $multiplier = 1.0e7;
            } elseif (in_array($unit, ['billion','bn'], true) || strpos($unit, 'миллиард') === 0) {
                $multiplier = 1.0e9;
            } elseif (in_array($unit, ['兆','trillion'], true)) {
                $multiplier = 1.0e12;
            }
            $out[] = sprintf('%.12g', $number * $multiplier);
        }
        sort($out, SORT_STRING);
        return $out;
    }

    private function openai_quality_json_key_skeleton($value) {
        $decoded = json_decode(trim((string)$value), true);
        if (!is_array($decoded)) {
            return null;
        }
        $walk = static function($node) use (&$walk) {
            if (!is_array($node)) {
                return 'value';
            }
            $out = [];
            foreach ($node as $key => $child) {
                $out[(string)$key] = $walk($child);
            }
            return $out;
        };
        return $walk($decoded);
    }

    private function openai_quality_number_entity_boundary_issue($source, $target) {
        // Advisory only in 0.9.6.4. The AI receives this signal, but PHP must not force a
        // rewrite or reject an AI `keep` because of a numeric comparison heuristic.
        $source_magnitudes = $this->openai_quality_magnitude_totals($source);
        $target_magnitudes = $this->openai_quality_magnitude_totals($target);
        if ($source_magnitudes !== $target_magnitudes) {
            return 'number_entity_boundary';
        }
        $source_numbers = $this->openai_quality_hard_numeric_values($source);
        $target_numbers = $this->openai_quality_hard_numeric_values($target);
        if ($source_numbers !== $target_numbers) {
            return 'numeric_value_mismatch';
        }
        return '';
    }

    private function openai_quality_plain_text_length($text) {
        $text = $this->openai_normalize_translation_control_placeholders((string)$text);
        $text = preg_replace('/__WPMU_ML_(?:ATOMIC|MACHINE)_\d+__/u', ' ', (string)$text);
        $text = html_entity_decode(wp_strip_all_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', trim((string)$text));
        return $this->wpmu_ml_strlen((string)$text);
    }

    private function openai_quality_adaptive_issue($row, $settings, $target_label = '') {
        $source = (string)($row['source'] ?? '');
        $target = (string)($row['target'] ?? '');
        $role = (string)($row['role'] ?? 'paragraph');
        $flags = array_values((array)($row['advisory_flags'] ?? []));
        if (!empty($row['consistency_issue'])) {
            $flags[] = (string)$row['consistency_issue'];
        }
        $generic = $this->openai_generic_translation_block_edit_issue($source, $target, $role);
        if ($generic !== '') {
            $flags[] = 'suspected_structural_polish:' . $generic;
        }
        if ($flags) {
            return implode(',', array_values(array_unique($flags)));
        }
        if (in_array($role, ['title', 'summary'], true)) {
            return 'publication_priority_role';
        }
        return '';
    }

    private function openai_quality_finalize_candidate_spacing($candidate) {
        $candidate = (string)$candidate;
        $candidate = preg_replace('/(?<=\d),\s+(?=\d{3}(?:\D|$))/u', ',', $candidate);
        return is_string($candidate) ? $candidate : (string)$candidate;
    }

    private function openai_central_quality_review_fields($source_fields, $translated_fields, $roles, $target_label, $settings, $scope = 'content') {
        $source_fields = is_array($source_fields) ? $source_fields : [];
        $translated_fields = is_array($translated_fields) ? $translated_fields : [];
        $roles = is_array($roles) ? $roles : [];
        $scope = sanitize_key((string)$scope);
        if ($scope === '') {
            $scope = 'content';
        }
        if (!is_array($this->openai_quality_runtime) || !$this->openai_quality_runtime) {
            $this->openai_quality_runtime_reset();
        }
        $this->openai_quality_runtime['processed_scopes'][$scope] = 1;

        $mode = $this->openai_quality_coverage_mode($settings);
        $agent_mode = sanitize_key((string)($settings['openai_agent_mode'] ?? 'rules_qa'));
        if ($agent_mode === 'off' || empty($settings['openai_editorial_review_enabled'])) {
            $mode = 'off';
        }
        $this->openai_quality_runtime['effective_mode'] = $mode;

        $raw = [];
        $structural = 0;
        $deterministic_seen = 0;
        foreach ($source_fields as $key => $source) {
            if (!array_key_exists($key, $translated_fields)) {
                return new WP_Error('wpmu_ml_php_missing_field', 'PHP 本地完整性检查失败：模型未返回字段 ' . (string)$key . '。');
            }
            $source = (string)$source;
            $target = (string)$translated_fields[$key];
            if (trim($source) !== '' && trim($target) === '') {
                return new WP_Error('wpmu_ml_php_empty_field', 'PHP 本地完整性检查失败：字段 ' . (string)$key . ' 返回空值。');
            }
            if (trim($source) === '') {
                $structural++;
                continue;
            }
            $deterministic_seen++;
            $hard_issue = $this->openai_translation_fragment_rejection_reason($source, $target, $settings, $target_label);
            if ($hard_issue !== '') {
                return new WP_Error('wpmu_ml_php_integrity_failure', 'PHP 本地完整性检查失败：字段 ' . (string)$key . '，原因=' . $hard_issue . '。');
            }
            if ($this->openai_language_invariant_pair($source, $target)) {
                $structural++;
                continue;
            }
            $role = $this->openai_quality_role_from_type((string)($roles[$key] ?? 'paragraph'));
            $advisory_flags = $this->openai_quality_advisory_flags($source, $target, $settings, $target_label);
            $risk = $this->openai_article_block_editor_risk_score($source, $target, $role);
            if ($advisory_flags) {
                $risk += 300;
            }
            $raw[(string)$key] = [
                'source' => $source,
                'target' => $target,
                'role' => $role,
                'risk' => max(1, (int)$risk),
                'advisory_flags' => $advisory_flags,
            ];
            $this->openai_quality_runtime['roles'][$role] = (int)($this->openai_quality_runtime['roles'][$role] ?? 0) + 1;
        }

        // Exact repeated source strings should normally resolve to one target form inside the
        // same article. Flag differing targets for adaptive terminology review without using a
        // site- or language-specific phrase list.
        $source_variants = [];
        foreach ($raw as $raw_key => $row) {
            $source_hash = hash('sha256', $this->openai_quality_normalize_pair_text((string)$row['source']));
            $target_norm = $this->openai_quality_normalize_pair_text((string)$row['target']);
            if (!isset($source_variants[$source_hash])) {
                $source_variants[$source_hash] = [];
            }
            if (!isset($source_variants[$source_hash][$target_norm])) {
                $source_variants[$source_hash][$target_norm] = [];
            }
            $source_variants[$source_hash][$target_norm][] = $raw_key;
        }
        foreach ($source_variants as $variants) {
            if (count($variants) <= 1) {
                continue;
            }
            foreach ($variants as $keys) {
                foreach ($keys as $raw_key) {
                    $raw[$raw_key]['consistency_issue'] = 'inconsistent_repeated_source';
                    $raw[$raw_key]['risk'] = (int)$raw[$raw_key]['risk'] + 300;
                }
            }
        }

        $raw_count = count($raw);
        $this->openai_quality_runtime['deterministic_checked'] += $deterministic_seen;
        if (!array_key_exists('openai_translation_self_review', (array)$settings) || !empty($settings['openai_translation_self_review'])) {
            $this->openai_quality_runtime['self_reviewed_fields'] += $raw_count;
        }
        $this->openai_quality_runtime['raw_fields'] += count($source_fields);
        $this->openai_quality_runtime['eligible_fields'] += $raw_count;
        if (!$raw_count) {
            $this->openai_cli_trace_line(sprintf('QA MANIFEST scope=%s translated_fields=%d human_readable=0 protected_or_structural=%d unique_pairs=0', $scope, count($source_fields), $structural));
            return $translated_fields;
        }

        $unique = [];
        foreach ($raw as $raw_key => $row) {
            $hash = hash('sha256', $this->openai_quality_normalize_pair_text($row['source']) . "\0" . $this->openai_quality_normalize_pair_text($row['target']));
            if (!isset($unique[$hash])) {
                $unique[$hash] = $row + ['raw_keys' => [], 'order' => count($unique)];
            }
            $unique[$hash]['raw_keys'][] = $raw_key;
            if ((int)$row['risk'] > (int)$unique[$hash]['risk']) {
                $unique[$hash]['risk'] = (int)$row['risk'];
            }
            if ($this->openai_quality_role_priority($row['role']) > $this->openai_quality_role_priority($unique[$hash]['role'])) {
                $unique[$hash]['role'] = $row['role'];
            }
            if (!empty($row['consistency_issue'])) {
                $unique[$hash]['consistency_issue'] = (string)$row['consistency_issue'];
            }
        }
        $unique_count = count($unique);
        $this->openai_quality_runtime['unique_pairs'] += $unique_count;
        foreach ($this->openai_quality_extract_term_inventory($raw) as $term) {
            $this->openai_quality_runtime['term_inventory'][$term] = 1;
        }

        $role_parts = [];
        $scope_roles = [];
        foreach ($raw as $row) {
            $scope_roles[$row['role']] = (int)($scope_roles[$row['role']] ?? 0) + 1;
        }
        foreach ($scope_roles as $role => $count) {
            $role_parts[] = $role . ':' . $count;
        }
        $this->openai_cli_trace_line(sprintf(
            'QA MANIFEST scope=%s translated_fields=%d human_readable=%d protected_or_structural=%d unique_pairs=%d roles=%s',
            $scope,
            count($source_fields),
            $raw_count,
            $structural,
            $unique_count,
            $role_parts ? implode(',', $role_parts) : '-'
        ));

        if ($mode === 'off') {
            $this->openai_cli_trace_line(sprintf('CENTRAL AI QA PLAN scope=%s coverage=off unique_fields=%d batch_count=0 php_integrity=passed', $scope, $unique_count));
            return $translated_fields;
        }

        uasort($unique, static function($a, $b) {
            $risk = ((int)$b['risk']) <=> ((int)$a['risk']);
            return $risk !== 0 ? $risk : (((int)$a['order']) <=> ((int)$b['order']));
        });
        $all_unique = $unique;
        $eligible_unique_count = count($unique);
        $mandatory_count = 0;
        $sampled_count = 0;
        if ($mode === 'adaptive') {
            $mandatory = [];
            $optional = [];
            foreach ($unique as $hash => $row) {
                $adaptive_issue = $this->openai_quality_adaptive_issue($row, $settings, $target_label);
                $row['adaptive_issue'] = $adaptive_issue;
                if ($adaptive_issue !== '') {
                    $mandatory[$hash] = $row;
                } elseif ((int)($row['risk'] ?? 0) >= 90) {
                    $optional[$hash] = $row;
                }
            }
            $adaptive_limit = max(4, min(80, absint($settings['openai_adaptive_qa_max_fields'] ?? 24)));
            $mandatory_count = count($mandatory);
            $slots = max(0, $adaptive_limit - $mandatory_count);
            if ($slots > 0 && $optional) {
                $optional = array_slice($optional, 0, $slots, true);
                $sampled_count = count($optional);
            } else {
                $optional = [];
            }
            $unique = $mandatory + $optional;
            uasort($unique, static function($a, $b) {
                $risk = ((int)$b['risk']) <=> ((int)$a['risk']);
                return $risk !== 0 ? $risk : (((int)$a['order']) <=> ((int)$b['order']));
            });
            $this->openai_quality_runtime['adaptive_skipped_fields'] += max(0, $eligible_unique_count - count($unique));
            $this->openai_cli_trace_line(sprintf(
                'ADAPTIVE QA SELECTION scope=%s eligible_unique=%d ai_candidates=%d mandatory=%d sampled_high_risk=%d skipped=%d limit=%d',
                $scope,
                $eligible_unique_count,
                count($unique),
                $mandatory_count,
                $sampled_count,
                max(0, $eligible_unique_count - count($unique)),
                $adaptive_limit
            ));
        } elseif ($mode === 'risk') {
            $limit = max(4, min(20, absint($settings['openai_central_qa_max_fields'] ?? 8)));
            if (count($unique) > $limit) {
                $unique = array_slice($unique, 0, $limit, true);
            }
        }

        $api_fields = [];
        $api_to_hash = [];
        $index = 0;
        foreach ($unique as $hash => $row) {
            $api_key = 'q' . $index++;
            $api_fields[$api_key] = wp_json_encode(['r' => $row['role'], 's' => $row['source'], 't' => $row['target'], 'f' => (string)($row['adaptive_issue'] ?? ''), 'h' => array_values((array)($row['advisory_flags'] ?? []))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($api_fields[$api_key])) {
                $api_fields[$api_key] = (string)$row['source'] . "\n" . (string)$row['target'];
            }
            $api_to_hash[$api_key] = $hash;
        }
        $max_fields = max(4, min(120, absint($settings['openai_central_qa_batch_fields'] ?? 80)));
        $max_chars = max(2000, min(50000, absint($settings['openai_central_qa_batch_chars'] ?? 16000)));
        $batches = $this->openai_quality_partition_batches($api_fields, $max_fields, $max_chars);
        $candidate_unique = count($api_fields);
        $candidate_raw_fields = 0;
        foreach ($unique as $row) {
            $candidate_raw_fields += count((array)($row['raw_keys'] ?? []));
        }
        $this->openai_quality_runtime['expected'] += $candidate_unique;
        $this->openai_quality_runtime['ai_candidate_unique'] += $candidate_unique;
        $this->openai_quality_runtime['ai_candidate_raw_fields'] += $candidate_raw_fields;
        $this->openai_quality_runtime['ai_candidate_fields'] += $candidate_raw_fields;
        $this->openai_cli_trace_line(sprintf(
            'CENTRAL AI QA PLAN scope=%s coverage=%s eligible_unique=%d ai_candidates=%d batch_count=%d batch_limits=fields:%d,chars:%d',
            $scope,
            $mode,
            $eligible_unique_count,
            count($api_fields),
            count($batches),
            $max_fields,
            $max_chars
        ));

        $term_inventory = array_slice(array_keys((array)($this->openai_quality_runtime['term_inventory'] ?? [])), 0, 40);
        $term_context = $term_inventory ? ' ARTICLE TERM INVENTORY (observed forms; detect inconsistent variants): ' . implode(' | ', $term_inventory) . '.' : '';
        $preferred_article_terms = trim((string)($settings['openai_article_terminology_context'] ?? ''));
        if ($preferred_article_terms !== '') {
            $term_context .= " ARTICLE-SPECIFIC TRANSLATION CONTEXT:\n" . $preferred_article_terms
                . "\nUse it to understand the article and high-risk source concepts. It does not prescribe target wording; return keep when the current contextual wording is natural and faithful.";
        }
        $instruction = '[WPMU_ML_CENTRAL_QA] Review every supplied candidate. Each JSON string decodes to compact keys r=role, s=source, t=current translation, f=selection reason and h=PHP advisory hints. Return exactly "keep" or "rewrite:<brief concrete issue>" for every key. IMPORTANT: h/f values such as suspected source residue, numeric difference or length difference are hints only. Independently compare source and target; return keep whenever the candidate is acceptable. Never rewrite merely because PHP supplied a hint. PHP has already enforced only field return, non-empty values, structure and placeholders. Apply role-aware publication standards, fidelity, target-locale naturalness and terminology consistency. Preserve HTML, placeholders, URLs, machine tokens and every supplied key. Do not return article text.' . $term_context;
        $flagged = [];
        $checked = 0;
        $keep = 0;
        $unavailable = 0;
        foreach ($batches as $batch_index => $batch) {
            $request_settings = $settings;
            $request_settings['openai_internal_require_all_keys'] = 1;
            $request_settings['openai_internal_no_field_split'] = 1;
            $request_settings['openai_request_max_attempts'] = 1;
            $statuses = $this->openai_translate_json_fields($batch, $target_label, $request_settings, $instruction);
            if (is_wp_error($statuses)) {
                $unavailable += count($batch);
                $this->openai_quality_mark_unavailable($scope . '_qa', count($batch), $statuses->get_error_message());
                $this->openai_cli_trace_line(sprintf('CENTRAL AI QA batch=%d/%d scope=%s status=partial fields=%d reason=%s', $batch_index + 1, count($batches), $scope, count($batch), $statuses->get_error_message()));
                continue;
            }
            foreach ($batch as $api_key => $unused) {
                $parsed = $this->openai_parse_editorial_audit_status($statuses[$api_key] ?? '');
                if (($parsed['action'] ?? 'invalid') === 'invalid') {
                    $unavailable++;
                    $this->openai_quality_mark_unavailable($scope . '_qa', 1, 'invalid status for ' . $api_key);
                    continue;
                }
                $hash = $api_to_hash[$api_key];
                $row = $unique[$hash];
                $checked++;
                $checked_raw_fields = count((array)$row['raw_keys']);
                $this->openai_quality_runtime['ai_checked_unique']++;
                $this->openai_quality_runtime['ai_checked_raw_fields'] += $checked_raw_fields;
                $this->openai_quality_runtime['covered_raw_fields'] += $checked_raw_fields;
                if (($parsed['action'] ?? '') === 'rewrite') {
                    $reason = trim((string)($parsed['reason'] ?? ''));
                    $flagged[$hash] = $reason !== '' ? $reason : 'editorial defect';
                } else {
                    // AI `keep` is authoritative for residue/numeric/length advisory hints.
                    $keep++;
                }
            }
        }
        // 0.9.6.6: an explicit AI rewrite can reveal a repeated source concept that was
        // translated differently elsewhere. PHP expands the candidate set only; AI remains
        // authoritative and only an explicit rewrite can change another field.
        $related_expected = 0;
        $related_target_tokens = [];
        $related_source_candidates = [];
        foreach ($flagged as $flagged_hash => $flagged_reason) {
            $flagged_row = $all_unique[$flagged_hash] ?? ($unique[$flagged_hash] ?? null);
            if (!is_array($flagged_row)) {
                continue;
            }
            if ($this->openai_quality_reason_suggests_source_residue($flagged_reason)) {
                foreach ($this->openai_quality_shared_han_fragments((string)$flagged_row['source'], (string)$flagged_row['target']) as $token) {
                    $related_target_tokens[$token] = 1;
                }
            }
            foreach ($this->openai_quality_source_semantic_fragments((string)$flagged_row['source']) as $token) {
                $related_source_candidates[$token] = 1;
            }
        }

        // Keep source concepts only when they recur in a reasonable number of other fields.
        // This rejects one-off long phrases and extremely generic high-frequency fragments.
        $related_source_tokens = [];
        foreach (array_keys($related_source_candidates) as $token) {
            $occurrences = 0;
            foreach ($all_unique as $row) {
                if (strpos((string)$row['source'], (string)$token) !== false) {
                    $occurrences++;
                }
            }
            if ($occurrences >= 2 && $occurrences <= 12) {
                $related_source_tokens[$token] = $occurrences;
            }
            if (count($related_source_tokens) >= 8) {
                break;
            }
        }

        if ($related_target_tokens || $related_source_tokens) {
            $selected_hashes = array_fill_keys(array_keys($unique), 1);
            $related_unique = [];
            foreach ($all_unique as $hash => $row) {
                if (isset($selected_hashes[$hash])) {
                    continue;
                }
                $matched_target = [];
                foreach (array_keys($related_target_tokens) as $token) {
                    if (strpos((string)$row['target'], (string)$token) !== false) {
                        $matched_target[] = (string)$token;
                    }
                }
                $matched_source = [];
                foreach (array_keys($related_source_tokens) as $token) {
                    if (strpos((string)$row['source'], (string)$token) !== false) {
                        $matched_source[] = (string)$token;
                    }
                }
                if (!$matched_target && !$matched_source) {
                    continue;
                }
                $issues = [];
                if ($matched_target) {
                    $issues[] = 'related_source_residue:' . implode('|', array_slice($matched_target, 0, 4));
                }
                if ($matched_source) {
                    $issues[] = 'related_source_concept:' . implode('|', array_slice($matched_source, 0, 4));
                }
                $row['adaptive_issue'] = implode(',', $issues);
                $related_unique[$hash] = $row;
                if (count($related_unique) >= 40) {
                    break;
                }
            }

            if ($related_unique) {
                $related_api_fields = [];
                $related_api_to_hash = [];
                $related_raw_fields = 0;
                $related_index = 0;
                foreach ($related_unique as $hash => $row) {
                    $api_key = 'x' . $related_index++;
                    $related_api_fields[$api_key] = wp_json_encode([
                        'r' => $row['role'],
                        's' => $row['source'],
                        't' => $row['target'],
                        'f' => (string)$row['adaptive_issue'],
                        'h' => array_values((array)($row['advisory_flags'] ?? [])),
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (!is_string($related_api_fields[$api_key])) {
                        $related_api_fields[$api_key] = (string)$row['source'] . "\n" . (string)$row['target'];
                    }
                    $related_api_to_hash[$api_key] = $hash;
                    $related_raw_fields += count((array)($row['raw_keys'] ?? []));
                }
                $related_batches = $this->openai_quality_partition_batches($related_api_fields, $max_fields, $max_chars);
                $related_expected = count($related_api_fields);
                $this->openai_quality_runtime['expected'] += $related_expected;
                $this->openai_quality_runtime['ai_candidate_unique'] += $related_expected;
                $this->openai_quality_runtime['ai_candidate_raw_fields'] += $related_raw_fields;
                $this->openai_quality_runtime['ai_candidate_fields'] += $related_raw_fields;
                $this->openai_quality_runtime['adaptive_skipped_fields'] = max(0, (int)$this->openai_quality_runtime['adaptive_skipped_fields'] - $related_expected);
                $related_checked = 0;
                $token_log = [];
                foreach (array_keys($related_target_tokens) as $token) {
                    $token_log[] = 'target:' . $token;
                }
                foreach (array_keys($related_source_tokens) as $token) {
                    $token_log[] = 'source:' . $token;
                }
                $this->openai_cli_trace_line(sprintf(
                    'RELATED CONCEPT QA EXPANSION scope=%s tokens=%s candidates=%d raw_fields=%d batches=%d',
                    $scope,
                    $token_log ? implode('|', $token_log) : '-',
                    $related_expected,
                    $related_raw_fields,
                    count($related_batches)
                ));

                foreach ($related_batches as $batch) {
                    $request_settings = $settings;
                    $request_settings['openai_internal_require_all_keys'] = 1;
                    $request_settings['openai_internal_no_field_split'] = 1;
                    $request_settings['openai_request_max_attempts'] = 1;
                    $statuses = $this->openai_translate_json_fields($batch, $target_label, $request_settings, $instruction);
                    if (is_wp_error($statuses)) {
                        $unavailable += count($batch);
                        $this->openai_quality_mark_unavailable($scope . '_related_qa', count($batch), $statuses->get_error_message());
                        continue;
                    }
                    foreach ($batch as $api_key => $unused) {
                        $parsed = $this->openai_parse_editorial_audit_status($statuses[$api_key] ?? '');
                        if (($parsed['action'] ?? 'invalid') === 'invalid') {
                            $unavailable++;
                            $this->openai_quality_mark_unavailable($scope . '_related_qa', 1, 'invalid status for ' . $api_key);
                            continue;
                        }
                        $hash = $related_api_to_hash[$api_key];
                        $row = $related_unique[$hash];
                        $checked++;
                        $related_checked++;
                        $checked_raw_fields = count((array)$row['raw_keys']);
                        $this->openai_quality_runtime['ai_checked_unique']++;
                        $this->openai_quality_runtime['ai_checked_raw_fields'] += $checked_raw_fields;
                        $this->openai_quality_runtime['covered_raw_fields'] += $checked_raw_fields;
                        if (($parsed['action'] ?? '') === 'rewrite') {
                            $reason = trim((string)($parsed['reason'] ?? ''));
                            $flagged[$hash] = $reason !== '' ? $reason : 'related source concept requires review';
                            $unique[$hash] = $row;
                        } else {
                            $keep++;
                        }
                    }
                }
                $this->openai_cli_trace_line(sprintf(
                    'RELATED CONCEPT QA RESULT scope=%s expected=%d checked=%d cumulative_rewrite=%d unavailable=%d',
                    $scope,
                    $related_expected,
                    $related_checked,
                    count($flagged),
                    $unavailable
                ));
            }
        }

        $this->openai_quality_runtime['checked'] += $checked;
        $this->openai_quality_runtime['keep'] += $keep;
        $this->openai_quality_runtime['rewrite'] += count($flagged);
        $scope_expected = $candidate_unique + $related_expected;
        $this->openai_cli_trace_line(sprintf(
            'CENTRAL AI QA RESULT scope=%s expected=%d checked=%d keep=%d rewrite=%d unavailable=%d coverage=%.2f%% status=%s',
            $scope,
            $scope_expected,
            $checked,
            $keep,
            count($flagged),
            $unavailable,
            $scope_expected > 0 ? ($checked * 100 / $scope_expected) : 100,
            $unavailable > 0 || $checked !== $scope_expected ? 'partial' : 'completed'
        ));

        if (!$flagged) {
            return $translated_fields;
        }
        if (empty($settings['openai_central_qa_auto_repair'])) {
            $this->openai_quality_runtime['repair_failed'] += count($flagged);
            $this->openai_quality_mark_unavailable($scope . '_repair_disabled', count($flagged), 'auto repair disabled');
            return $translated_fields;
        }

        $repair_fields = [];
        $repair_to_hash = [];
        $repair_context = [];
        $index = 0;
        foreach ($flagged as $hash => $reason) {
            $row = $unique[$hash];
            $key = 'r' . $index++;
            // Keep the API value itself equal to the current target text. 0.9.4.9 embedded
            // ROLE/SOURCE/ISSUES inside the value, and some compatible models echoed that
            // wrapper into the repaired article. Context now lives only in the instruction.
            $repair_fields[$key] = (string)$row['target'];
            $repair_context[$key] = 'KEY=' . $key . ' ROLE=' . $row['role'] . ' ISSUES=' . $reason
                . "\nSOURCE:\n" . $row['source']
                . "\nCURRENT TRANSLATION:\n" . $row['target'];
            $repair_to_hash[$key] = $hash;
        }
        $repair_partition_fields = [];
        foreach ($repair_fields as $key => $value) {
            $repair_partition_fields[$key] = (string)$value . "\n" . (string)($repair_context[$key] ?? '');
        }
        $repair_partition_batches = $this->openai_quality_partition_batches($repair_partition_fields, $max_fields, $max_chars);
        $repair_batches = [];
        foreach ($repair_partition_batches as $partition_batch) {
            $repair_batches[] = array_intersect_key($repair_fields, $partition_batch);
        }
        $repair_instruction_base = 'Repair only the supplied problem fields. Each JSON input value is the current translation; use the matching FIELD CONTEXT below to compare it with the source and issue. Return ONLY the corrected complete target-language value for each exact key, never the ROLE/SOURCE/ISSUES wrapper. Respect ROLE: keep titles concise, summaries complete, headings native, keyword lists as lists, and paragraphs/metadata natural and precise. Preserve source facts, brands, product names, terminology, numbers, money, dates, percentages, versions, specifications, URLs, HTML tags, placeholders and machine tokens. Correct number/entity attachment errors such as turning a campaign name plus billion-scale orders into an eleven-billion quantity. Do not add explanations or unsupported facts.';
        if ($preferred_article_terms !== '') {
            $repair_instruction_base .= "\n\nARTICLE-SPECIFIC TRANSLATION CONTEXT:\n" . $preferred_article_terms
                . "\nUse this context consistently, but repair each field according to its own grammar and meaning; do not perform mechanical substitutions.";
        }
        $repaired_count = 0;
        $repair_failed = 0;
        foreach ($repair_batches as $batch_index => $batch) {
            $request_settings = $settings;
            $request_settings['openai_internal_require_all_keys'] = 1;
            $request_settings['openai_internal_no_field_split'] = 1;
            $request_settings['openai_request_max_attempts'] = 1;
            $batch_context = [];
            foreach ($batch as $repair_key => $unused) {
                if (isset($repair_context[$repair_key])) {
                    $batch_context[] = $repair_context[$repair_key];
                }
            }
            $repair_instruction = $repair_instruction_base . "\n\nFIELD CONTEXT:\n" . implode("\n\n", $batch_context);
            $repaired = $this->openai_translate_json_fields($batch, $target_label, $request_settings, $repair_instruction);
            if (is_wp_error($repaired)) {
                $repair_failed += count($batch);
                $this->openai_quality_mark_unavailable($scope . '_repair', count($batch), $repaired->get_error_message());
                continue;
            }
            foreach ($batch as $repair_key => $unused) {
                $hash = $repair_to_hash[$repair_key];
                $row = $unique[$hash];
                $candidate = $this->openai_normalize_translation_control_placeholders((string)($repaired[$repair_key] ?? ''));
                $candidate = $this->openai_quality_finalize_candidate_spacing($candidate);
                $issue = '';
                // Reject a compatible model echoing the repair context wrapper into publishable
                // content. The wrapper is instruction metadata, never article text.
                if (preg_match('/(?:^|\n)\s*(?:ROLE\s*=|CURRENT\s+TRANSLATION\s*:|ISSUES\s*=)/iu', $candidate)) {
                    $issue = 'repair_wrapper_echo';
                }
                if ($issue === '') {
                    $issue = $this->openai_translation_fragment_rejection_reason($row['source'], $candidate, $settings, $target_label);
                }
                if ($candidate === '' || $issue !== '') {
                    $repair_failed++;
                    $failure_reason = $issue !== '' ? $issue : 'empty';
                    $this->openai_quality_mark_unavailable($scope . '_repair_validation', 1, $failure_reason);
                    $this->openai_cli_trace_line(sprintf(
                        'ARTICLE EDITOR REPAIR FAILURE scope=%s key=%s reason=%s source=%s candidate=%s',
                        $scope,
                        $repair_key,
                        $failure_reason,
                        $this->openai_cli_trace_snippet((string)$row['source'], 180),
                        $this->openai_cli_trace_snippet($candidate, 180)
                    ));
                    continue;
                }
                foreach ((array)$row['raw_keys'] as $raw_key) {
                    $translated_fields[$raw_key] = $candidate;
                }
                $repaired_count++;
            }
        }
        $this->openai_quality_runtime['repaired'] += $repaired_count;
        $this->openai_quality_runtime['repair_failed'] += $repair_failed;
        $this->openai_cli_trace_line(sprintf('ARTICLE EDITOR REPAIR scope=%s requested=%d repaired=%d failed=%d batches=%d', $scope, count($flagged), $repaired_count, $repair_failed, count($repair_batches)));
        return $translated_fields;
    }

    private function openai_parse_article_final_review_result($value) {
        if (is_array($value)) {
            $action = strtolower(trim((string)($value['action'] ?? $value['status'] ?? '')));
            $target = (string)($value['target'] ?? $value['translation'] ?? $value['text'] ?? '');
            if (in_array($action, ['keep', 'ok', 'pass', 'accepted'], true)) {
                return ['action' => 'keep', 'target' => ''];
            }
            if (in_array($action, ['rewrite', 'revise', 'fix'], true) && trim($target) !== '') {
                return ['action' => 'rewrite', 'target' => $target];
            }
            return ['action' => 'invalid', 'target' => ''];
        }

        $raw = trim((string)$value);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $this->openai_parse_article_final_review_result($decoded);
        }
        $normalized = strtolower(trim($raw, " \t\n\r\0\x0B\"'`."));
        if (in_array($normalized, ['keep', 'ok', 'pass', 'accepted', 'unchanged', 'no_change'], true)) {
            return ['action' => 'keep', 'target' => ''];
        }
        if (preg_match('/^rewrite\s*\|\|\|([\s\S]+)$/iu', $raw, $match)) {
            $target = trim((string)($match[1] ?? ''));
            return $target !== ''
                ? ['action' => 'rewrite', 'target' => $target]
                : ['action' => 'invalid', 'target' => ''];
        }
        return ['action' => 'invalid', 'target' => ''];
    }

    private function openai_article_final_review_source_concepts($settings) {
        $context = trim((string)($settings['openai_article_terminology_context'] ?? ''));
        if ($context === '' || !preg_match('/^(?:RECURRING SOURCE CONCEPTS|SOURCE REVIEW FRAGMENTS)\s*[:：]\s*(.+)$/miu', $context, $match)) {
            return [];
        }
        $concepts = [];
        foreach (preg_split('/\s*\|\s*/u', trim((string)$match[1])) as $concept) {
            $concept = trim((string)$concept);
            if ($concept === '' || isset($concepts[$concept])) {
                continue;
            }
            $concepts[$concept] = 1;
            if (count($concepts) >= 18) {
                break;
            }
        }
        return array_keys($concepts);
    }


    /**
     * Build bounded, source-side concept groups for the final AI review.
     *
     * This is candidate discovery only. PHP never decides a target-language term and never
     * rewrites natural language. A selected group means that every field containing the same
     * recurring source fragment is shown to AI together, so one repaired occurrence does not
     * leave other occurrences unreviewed.
     */
    private function openai_article_final_review_unicode_length($value) {
        $value = (string)$value;
        if (function_exists('mb_strlen')) {
            return (int)mb_strlen($value, 'UTF-8');
        }
        $count = preg_match_all('/./us', $value, $matches);
        return $count === false ? strlen($value) : (int)$count;
    }

    private function openai_article_final_review_concept_groups($rows, $settings, $max_groups = 12, $field_budget = 80) {
        $rows = is_array($rows) ? $rows : [];
        $max_groups = max(0, min(20, (int)$max_groups));
        $field_budget = max(0, min(140, (int)$field_budget));
        if (!$rows || $max_groups === 0 || $field_budget === 0) {
            return ['discovered' => 0, 'groups' => [], 'keys' => []];
        }

        $context_concepts = array_fill_keys($this->openai_article_final_review_source_concepts($settings), 1);
        $groups = [];
        $add_key = static function(&$groups, $token, $key, $from_context = false, $from_shared = false) {
            $token = trim((string)$token);
            if ($token === '') {
                return;
            }
            if (!isset($groups[$token])) {
                $groups[$token] = [
                    'keys' => [],
                    'context' => false,
                    'shared' => false,
                    'shared_hits' => 0,
                    'score' => 0,
                    'flagged' => 0,
                    'anchor' => 0,
                    'target_overlap' => 0,
                ];
            }
            $groups[$token]['keys'][(string)$key] = 1;
            if ($from_context) {
                $groups[$token]['context'] = true;
            }
            if ($from_shared) {
                $groups[$token]['shared'] = true;
            }
        };

        // Context fragments may be only two characters long, so search them explicitly.
        foreach (array_keys($context_concepts) as $token) {
            foreach ($rows as $key => $row) {
                if ($token !== '' && strpos((string)$row['source'], (string)$token) !== false) {
                    $add_key($groups, $token, $key, true);
                }
            }
        }

        // Mine longer language-neutral phrases directly from every source field.
        $shared_token_rows = [];
        foreach ($rows as $key => $row) {
            foreach ($this->openai_quality_source_semantic_fragments((string)$row['source']) as $token) {
                $add_key($groups, $token, $key, isset($context_concepts[$token]));
            }
            // Exact source/target Han fragments are advisory residue seeds only. Once a seed is
            // found, all source fields containing that fragment are grouped for AI review, so a
            // single visible residue can trigger review of every related occurrence.
            foreach ($this->openai_quality_shared_han_fragments((string)$row['source'], (string)$row['target']) as $token) {
                if (!isset($shared_token_rows[$token])) {
                    $shared_token_rows[$token] = [];
                }
                $shared_token_rows[$token][(string)$key] = 1;
            }
        }
        foreach ($shared_token_rows as $token => $shared_keys) {
            foreach ($rows as $key => $row) {
                if ($token !== '' && strpos((string)$row['source'], (string)$token) !== false) {
                    $add_key($groups, $token, $key, isset($context_concepts[$token]), true);
                }
            }
            if (isset($groups[$token])) {
                $groups[$token]['shared_hits'] = count((array)$shared_keys);
            }
        }

        $ranked = [];
        foreach ($groups as $token => &$group) {
            $keys = array_keys((array)$group['keys']);
            $count = count($keys);
            $length = $this->openai_article_final_review_unicode_length((string)$token);
            $is_han = preg_match('/^[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]+$/u', (string)$token) === 1;
            if ($count < 2 || $count > 18 || $length < 2 || $length > 80) {
                unset($groups[$token]);
                continue;
            }

            // Drop clause-like extensions when a longer deterministic context concept already
            // covers the same fields. This keeps groups such as the core product term and avoids
            // selecting arbitrary one- or two-character tails around it.
            if (empty($group['context']) && empty($group['shared'])) {
                foreach (array_keys($context_concepts) as $context_token) {
                    $context_length = $this->openai_article_final_review_unicode_length((string)$context_token);
                    if ($context_length < 3 || $context_token === '' || $context_token === $token
                        || strpos((string)$token, (string)$context_token) === false
                        || !isset($groups[$context_token])) {
                        continue;
                    }
                    $context_keys = array_keys((array)$groups[$context_token]['keys']);
                    if (!array_diff($keys, $context_keys)
                        && $length <= $context_length + 3) {
                        unset($groups[$token]);
                        continue 2;
                    }
                }
            }

            foreach ($keys as $key) {
                $row = $rows[$key];
                if (!empty($row['flags'])) {
                    $group['flagged']++;
                }
                if (in_array((string)$row['kind'], ['title', 'excerpt'], true)
                    || in_array((string)$row['role'], ['title', 'heading', 'summary'], true)) {
                    $group['anchor']++;
                }
                if ($token !== '' && strpos((string)$row['target'], (string)$token) !== false) {
                    $group['target_overlap']++;
                }
            }

            // Two-character no-space fragments are useful only when promoted by the deterministic
            // article context and kept reasonably narrow. This avoids flooding the review with
            // generic character pairs.
            if ($is_han && $length === 2 && ((empty($group['context']) && empty($group['shared'])) || ($count > 10 && empty($group['shared'])) || $count > 18)) {
                unset($groups[$token]);
                continue;
            }
            if ($is_han && $length === 3 && $count > 18 && empty($group['context'])) {
                unset($groups[$token]);
                continue;
            }

            // A dynamically mined phrase occurring in only two fields can be accidental. Retain
            // it only when it extends one of the deterministic article fragments or is supported
            // by an anchor/advisory signal. This stays language-neutral and merely narrows AI
            // candidate selection.
            if (empty($group['context']) && empty($group['shared']) && $count === 2 && empty($group['flagged']) && empty($group['anchor'])) {
                $related_to_context = false;
                foreach (array_keys($context_concepts) as $context_token) {
                    if ($context_token !== ''
                        && (strpos((string)$token, (string)$context_token) !== false
                            || strpos((string)$context_token, (string)$token) !== false)) {
                        $related_to_context = true;
                        break;
                    }
                }
                if (!$related_to_context) {
                    unset($groups[$token]);
                    continue;
                }
            }
            $breadth_penalty = $count > 12 ? ($count - 12) * 140 : 0;
            $shared_hits = min($count, max(0, (int)($group['shared_hits'] ?? 0)));
            $shared_ratio = $count > 0 ? $shared_hits / $count : 0;
            $shared_bonus = 0;
            if (!empty($group['shared'])) {
                $shared_bonus = (int)round(max(0, 1 - $shared_ratio) * 5000);
            }
            $group['shared_ratio'] = $shared_ratio;
            $group['score'] = $shared_bonus
                + ($group['context'] ? 600 : 0)
                + min(20, $length * $length) * 180
                + min(12, $count) * 45
                + min(6, (int)$group['flagged']) * 320
                + min(6, (int)$group['anchor']) * 220
                + min(6, (int)$group['target_overlap']) * 140
                - $breadth_penalty;
            $ranked[$token] = (int)$group['score'];
        }
        unset($group);
        arsort($ranked, SORT_NUMERIC);

        $selected = [];
        $selected_keys = [];
        $try_select = function($token) use (&$selected, &$selected_keys, $groups, $field_budget, $max_groups) {
            $token = (string)$token;
            if (!isset($groups[$token]) || isset($selected[$token]) || count($selected) >= $max_groups) {
                return false;
            }
            $keys = array_keys((array)$groups[$token]['keys']);
            sort($keys, SORT_STRING);

            foreach ($selected as $existing_token => $existing_group) {
                $existing_keys = array_keys((array)$existing_group['keys']);
                sort($existing_keys, SORT_STRING);
                if ($existing_keys === $keys
                    && (strpos((string)$existing_token, $token) !== false
                        || strpos($token, (string)$existing_token) !== false)) {
                    return false;
                }
            }

            $new_keys = array_diff($keys, array_keys($selected_keys));
            if ($selected && count($selected_keys) + count($new_keys) > $field_budget) {
                return false;
            }
            foreach ($keys as $key) {
                $selected_keys[$key] = 1;
            }
            $selected[$token] = $groups[$token];
            return true;
        };

        $ranked_tokens = array_keys($ranked);
        $shared_tokens = array_values(array_filter($ranked_tokens, static function($token) use ($groups) {
            return !empty($groups[$token]['shared']) && (float)($groups[$token]['shared_ratio'] ?? 1) < 0.80;
        }));
        usort($shared_tokens, static function($a, $b) use ($groups) {
            $anchor = ((int)($groups[$b]['anchor'] ?? 0)) <=> ((int)($groups[$a]['anchor'] ?? 0));
            if ($anchor !== 0) {
                return $anchor;
            }
            $ratio = ((float)($groups[$a]['shared_ratio'] ?? 1)) <=> ((float)($groups[$b]['shared_ratio'] ?? 1));
            if ($ratio !== 0) {
                return $ratio;
            }
            return ((int)($groups[$b]['score'] ?? 0)) <=> ((int)($groups[$a]['score'] ?? 0));
        });

        $context_tokens = array_values(array_filter($ranked_tokens, static function($token) use ($groups) {
            return !empty($groups[$token]['context']);
        }));
        usort($context_tokens, function($a, $b) use ($groups) {
            $a_long = $this->openai_article_final_review_unicode_length((string)$a) >= 3 ? 1 : 0;
            $b_long = $this->openai_article_final_review_unicode_length((string)$b) >= 3 ? 1 : 0;
            if ($a_long !== $b_long) {
                return $b_long <=> $a_long;
            }
            return ((int)($groups[$b]['score'] ?? 0)) <=> ((int)($groups[$a]['score'] ?? 0));
        });
        $anchor_tokens = array_values(array_filter($ranked_tokens, static function($token) use ($groups) {
            return empty($groups[$token]['context'])
                && empty($groups[$token]['shared'])
                && !empty($groups[$token]['anchor']);
        }));

        $selected_in_pass = 0;
        foreach ($shared_tokens as $token) {
            if ($selected_in_pass >= 4) {
                break;
            }
            if ($try_select($token)) {
                $selected_in_pass++;
            }
        }
        $selected_in_pass = 0;
        foreach ($context_tokens as $token) {
            if ($selected_in_pass >= 5) {
                break;
            }
            if ($try_select($token)) {
                $selected_in_pass++;
            }
        }
        $selected_in_pass = 0;
        foreach ($anchor_tokens as $token) {
            if ($selected_in_pass >= 3) {
                break;
            }
            if ($try_select($token)) {
                $selected_in_pass++;
            }
        }
        foreach ($ranked_tokens as $token) {
            if (count($selected) >= $max_groups || count($selected_keys) >= $field_budget) {
                break;
            }
            $try_select($token);
        }

        return [
            'discovered' => count($groups),
            'groups' => $selected,
            'keys' => array_keys($selected_keys),
        ];
    }

    /**
     * 0.9.7.0 streamlined article editor.
     *
     * The old article pipeline audited anchors, sampled the body, expanded related concepts,
     * then sent a separate repair request. That made the control flow slow and allowed a repair
     * request to reinterpret an already identified source concept. This final pass sends source,
     * current target, role and section context together and requires one authoritative response:
     * keep, or rewrite||| followed by the complete final field. PHP only selects candidates and
     * validates structure; it never rewrites natural language itself.
     */
    private function openai_ai_editorial_review_article_blocks($source_fragments, $translated_fragments, $items, $target_label, $settings) {
        $source_fragments = (array)$source_fragments;
        $translated_fragments = (array)$translated_fragments;
        $items = (array)$items;
        if (!is_array($this->openai_quality_runtime) || !$this->openai_quality_runtime) {
            $this->openai_quality_runtime_reset();
        }
        $this->openai_quality_runtime['processed_scopes']['article'] = 1;

        $mode = $this->openai_quality_coverage_mode($settings);
        $agent_mode = sanitize_key((string)($settings['openai_agent_mode'] ?? 'rules_qa'));
        if ($agent_mode === 'off' || empty($settings['openai_editorial_review_enabled'])) {
            $mode = 'off';
        }
        $this->openai_quality_runtime['effective_mode'] = $mode;

        $article_context = is_array($settings['openai_article_context'] ?? null)
            ? $settings['openai_article_context']
            : [];
        $sections = method_exists($this, 'openai_build_article_section_plan')
            ? $this->openai_build_article_section_plan($source_fragments, $items)
            : [];
        $section_by_id = [];
        foreach ((array)$sections as $section_index => $section) {
            foreach ((array)($section['ids'] ?? []) as $id) {
                $section_by_id[(string)$id] = [
                    'index' => (int)$section_index,
                    'heading' => trim((string)($section['heading'] ?? '')),
                ];
            }
        }

        $rows = [];
        $order = 0;
        $add_row = function($key, $source, $target, $role, $kind, $raw_id = null, $section_heading = '') use (&$rows, &$order, $settings, $target_label) {
            $source = (string)$source;
            $target = (string)$target;
            if (trim($source) === '' || trim($target) === '') {
                return;
            }
            $hard_issue = $this->openai_translation_fragment_rejection_reason($source, $target, $settings, $target_label);
            if ($hard_issue !== '') {
                return;
            }
            $role = $this->openai_quality_role_from_type((string)$role);
            $flags = $this->openai_quality_advisory_flags($source, $target, $settings, $target_label);
            $rows[(string)$key] = [
                'source' => $source,
                'target' => $target,
                'role' => $role,
                'kind' => (string)$kind,
                'raw_id' => $raw_id,
                'section' => (string)$section_heading,
                'flags' => array_values((array)$flags),
                'risk' => max(1, (int)$this->openai_article_block_editor_risk_score($source, $target, $role)),
                'order' => $order++,
            ];
        };

        $source_title = trim((string)($article_context['source_title'] ?? ''));
        $target_title = $this->openai_quality_field_override('title', (string)($article_context['target_title'] ?? ''));
        if ($source_title !== '' && trim($target_title) !== '') {
            $add_row('__post_title', $source_title, $target_title, 'title', 'title');
        }
        $source_excerpt = trim((string)($article_context['source_excerpt'] ?? ''));
        $target_excerpt = $this->openai_quality_field_override('excerpt', (string)($article_context['target_excerpt'] ?? ''));
        if ($source_excerpt !== '' && trim($target_excerpt) !== '') {
            $add_row('__post_excerpt', $source_excerpt, $target_excerpt, 'summary', 'excerpt');
        }
        foreach ($source_fragments as $id => $source) {
            if (!array_key_exists($id, $translated_fragments)) {
                continue;
            }
            $type = strtolower((string)($items[$id]['type'] ?? 'paragraph'));
            $section_heading = (string)($section_by_id[(string)$id]['heading'] ?? '');
            $add_row('body:' . (string)$id, (string)$source, (string)$translated_fragments[$id], $type, 'body', $id, $section_heading);
        }

        $total_rows = count($rows);
        $this->openai_quality_runtime['raw_fields'] += $total_rows;
        $this->openai_quality_runtime['eligible_fields'] += $total_rows;
        $this->openai_quality_runtime['deterministic_checked'] += $total_rows;
        if (!array_key_exists('openai_translation_self_review', (array)$settings) || !empty($settings['openai_translation_self_review'])) {
            $this->openai_quality_runtime['self_reviewed_fields'] += $total_rows;
        }
        foreach ($rows as $row) {
            $role = (string)$row['role'];
            $this->openai_quality_runtime['roles'][$role] = (int)($this->openai_quality_runtime['roles'][$role] ?? 0) + 1;
        }
        $unique_pairs = [];
        foreach ($rows as $row) {
            $unique_pairs[hash('sha256', $this->openai_quality_normalize_pair_text((string)$row['source']) . "\0" . $this->openai_quality_normalize_pair_text((string)$row['target']))] = 1;
        }
        $this->openai_quality_runtime['unique_pairs'] += count($unique_pairs);

        if ($mode === 'off' || !$rows) {
            $this->openai_cli_trace_line(sprintf(
                'ARTICLE FINAL REVIEW PLAN coverage=off translated_fields=%d candidates=0 batches=0',
                $total_rows
            ));
            return $translated_fragments;
        }

        $candidate_meta = [];
        $mark_candidate = function($key, $priority, $reason) use (&$candidate_meta, $rows) {
            $key = (string)$key;
            if (!isset($rows[$key])) {
                return;
            }
            if (!isset($candidate_meta[$key])) {
                $candidate_meta[$key] = ['priority' => (int)$priority, 'reasons' => []];
            }
            $candidate_meta[$key]['priority'] = max((int)$candidate_meta[$key]['priority'], (int)$priority);
            if ($reason !== '' && !in_array((string)$reason, $candidate_meta[$key]['reasons'], true)) {
                $candidate_meta[$key]['reasons'][] = (string)$reason;
            }
        };

        // Highest priority: deterministic PHP hints remain advisory, but source-residue and
        // unchanged-source signals should be shown to AI before lower-value sampling hints.
        foreach ($rows as $key => $row) {
            $flags = array_values((array)($row['flags'] ?? []));
            if (!$flags) {
                continue;
            }
            $priority = 1220;
            foreach ($flags as $flag) {
                if (preg_match('/(?:source_residue|unchanged_source|wrong_target|mixed_language)/iu', (string)$flag)) {
                    $priority = max($priority, 1600);
                } elseif (strpos((string)$flag, 'numeric') !== false) {
                    $priority = max($priority, 1240);
                } elseif (strpos((string)$flag, 'length') !== false) {
                    $priority = max($priority, 1160);
                }
            }
            $mark_candidate($key, $priority, 'php_advisory:' . implode('|', array_slice($flags, 0, 4)));
        }

        // 0.9.7.1: discover recurring source concepts across all fields, then include every
        // occurrence of each selected concept as one bounded review group. PHP only chooses the
        // fields; AI remains authoritative for keep/rewrite and may use grammatical variants.
        $group_max = max(4, min(16, absint($settings['openai_article_final_review_concept_groups'] ?? 12)));
        $group_field_budget = max(40, min(100, absint($settings['openai_article_final_review_concept_fields'] ?? 80)));
        $concept_plan = $this->openai_article_final_review_concept_groups($rows, $settings, $group_max, $group_field_budget);
        $candidate_groups = [];
        foreach ((array)($concept_plan['groups'] ?? []) as $concept => $group) {
            $group_priority = 1480 + min(100, (int)floor(((int)($group['score'] ?? 0)) / 100));
            foreach (array_keys((array)($group['keys'] ?? [])) as $key) {
                $mark_candidate($key, $group_priority, 'source_concept_group:' . (string)$concept);
                if (!isset($candidate_groups[$key])) {
                    $candidate_groups[$key] = [];
                }
                if (!in_array((string)$concept, $candidate_groups[$key], true)) {
                    $candidate_groups[$key][] = (string)$concept;
                }
            }
        }
        $group_names = array_keys((array)($concept_plan['groups'] ?? []));
        $this->openai_cli_trace_line(sprintf(
            'ARTICLE CONCEPT GROUP PLAN discovered=%d selected=%d grouped_fields=%d field_budget=%d groups=%s',
            (int)($concept_plan['discovered'] ?? 0),
            count($group_names),
            count((array)($concept_plan['keys'] ?? [])),
            $group_field_budget,
            $this->openai_cli_trace_snippet(implode(' | ', array_slice($group_names, 0, 12)), 360)
        ));

        // Exact repeated source strings with conflicting targets are a strong consistency signal.
        $source_variants = [];
        foreach ($rows as $key => $row) {
            $source_hash = hash('sha256', $this->openai_quality_normalize_pair_text((string)$row['source']));
            $target_norm = $this->openai_quality_normalize_pair_text((string)$row['target']);
            if (!isset($source_variants[$source_hash])) {
                $source_variants[$source_hash] = [];
            }
            if (!isset($source_variants[$source_hash][$target_norm])) {
                $source_variants[$source_hash][$target_norm] = [];
            }
            $source_variants[$source_hash][$target_norm][] = $key;
        }
        foreach ($source_variants as $variants) {
            if (count($variants) <= 1) {
                continue;
            }
            foreach ($variants as $keys) {
                foreach ($keys as $key) {
                    $mark_candidate($key, 1420, 'inconsistent_repeated_source');
                }
            }
        }

        // Article anchors remain mandatory after concept and defect groups.
        if (isset($rows['__post_title'])) {
            $mark_candidate('__post_title', 1380, 'article_title');
        }
        if (isset($rows['__post_excerpt'])) {
            $mark_candidate('__post_excerpt', 1360, 'article_excerpt');
        }

        $section_prose = [];
        foreach ($rows as $key => $row) {
            if ($row['kind'] !== 'body') {
                continue;
            }
            $type = strtolower((string)($items[$row['raw_id']]['type'] ?? 'paragraph'));
            if (in_array($type, ['h1', 'h2', 'h3', 'h4'], true)) {
                $mark_candidate($key, 1340, 'article_heading');
                continue;
            }
            $section_key = (string)($section_by_id[(string)$row['raw_id']]['index'] ?? -1);
            if (!isset($section_prose[$section_key])) {
                $section_prose[$section_key] = [];
            }
            $section_prose[$section_key][] = $key;
        }
        foreach ($section_prose as $keys) {
            if (!$keys) {
                continue;
            }
            $mark_candidate($keys[0], 760, 'section_first_prose');
            $mark_candidate($keys[count($keys) - 1], 750, 'section_last_prose');
        }

        // Keep broad article visibility after all mandatory defect/concept/anchor groups.
        $risk_order = array_keys($rows);
        usort($risk_order, function($a, $b) use ($rows) {
            $risk = ((int)$rows[$b]['risk']) <=> ((int)$rows[$a]['risk']);
            return $risk !== 0 ? $risk : (((int)$rows[$a]['order']) <=> ((int)$rows[$b]['order']));
        });
        $minimum_candidates = min($total_rows, 48);
        foreach ($risk_order as $key) {
            if (count($candidate_meta) >= $minimum_candidates) {
                break;
            }
            $mark_candidate($key, 400 + min(250, (int)$rows[$key]['risk']), 'risk_sample');
        }

        $candidate_keys = array_keys($candidate_meta);
        usort($candidate_keys, function($a, $b) use ($candidate_meta, $rows) {
            $priority = ((int)$candidate_meta[$b]['priority']) <=> ((int)$candidate_meta[$a]['priority']);
            return $priority !== 0 ? $priority : (((int)$rows[$a]['order']) <=> ((int)$rows[$b]['order']));
        });
        $max_candidates = max(60, min(140, absint($settings['openai_article_final_review_max_fields'] ?? 120)));
        if (count($candidate_keys) > $max_candidates) {
            $candidate_keys = array_slice($candidate_keys, 0, $max_candidates);
        }

        $api_fields = [];
        $api_to_key = [];
        foreach ($candidate_keys as $index => $key) {
            $row = $rows[$key];
            $api_key = 'q' . $index;
            $payload = [
                'r' => (string)$row['role'],
                's' => (string)$row['source'],
                't' => (string)$row['target'],
                'c' => (string)$row['section'],
                'g' => array_values((array)($candidate_groups[$key] ?? [])),
                'h' => array_values((array)($candidate_meta[$key]['reasons'] ?? [])),
            ];
            $api_fields[$api_key] = wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($api_fields[$api_key])) {
                $api_fields[$api_key] = (string)$row['source'] . "\n" . (string)$row['target'];
            }
            $api_to_key[$api_key] = $key;
        }

        $max_fields = max(60, min(140, absint($settings['openai_article_final_review_batch_fields'] ?? 120)));
        $max_chars = max(16000, min(60000, absint($settings['openai_article_final_review_batch_chars'] ?? 50000)));
        $batches = $this->openai_quality_partition_batches($api_fields, $max_fields, $max_chars);
        $candidate_count = count($api_fields);
        $this->openai_quality_runtime['expected'] += $candidate_count;
        $this->openai_quality_runtime['ai_candidate_unique'] += $candidate_count;
        $this->openai_quality_runtime['ai_candidate_raw_fields'] += $candidate_count;
        $this->openai_quality_runtime['ai_candidate_fields'] += $candidate_count;
        $this->openai_quality_runtime['adaptive_skipped_fields'] += max(0, $total_rows - $candidate_count);
        $this->openai_cli_trace_line(sprintf(
            'ARTICLE FINAL REVIEW PLAN translated_fields=%d candidates=%d concept_groups=%d grouped_fields=%d batches=%d limits=fields:%d,chars:%d mode=source_target_inline_rewrite',
            $total_rows,
            $candidate_count,
            count((array)($concept_plan['groups'] ?? [])),
            count((array)($concept_plan['keys'] ?? [])),
            count($batches),
            $max_fields,
            $max_chars
        ));

        $checked = 0;
        $kept = 0;
        $rewrite_requested = 0;
        $rewritten = 0;
        $invalid = 0;
        foreach ($batches as $batch_index => $batch) {
            $request_settings = $settings;
            $request_settings['openai_internal_require_all_keys'] = 1;
            $request_settings['openai_internal_no_field_split'] = 1;
            $request_settings['openai_request_max_attempts'] = 1;
            $request_settings['openai_temperature'] = (string)min(0.15, max(0.0, (float)($settings['openai_temperature'] ?? 0.2)));
            $instruction = '[WPMU_ML_ARTICLE_FINAL_REVIEW] Review every supplied source/current-target pair as part of one article. Fields sharing the same g source-concept group must be reviewed together for natural, context-appropriate consistency; grammatical variation is allowed and identical wording is not required. Return keep when publication-ready. For a concrete defect return rewrite||| followed by the complete corrected target field. The corrected field must remain faithful to its own source; use the section and full source article context only to resolve ambiguity and consistency. PHP hints and group labels are advisory only.';
            $result = $this->openai_translate_json_fields($batch, $target_label, $request_settings, $instruction);
            if (is_wp_error($result)) {
                $invalid += count($batch);
                $this->openai_quality_mark_unavailable('article_final_review', count($batch), $result->get_error_message());
                $this->openai_cli_trace_line(sprintf(
                    'ARTICLE FINAL REVIEW BATCH batch=%d/%d status=unavailable fields=%d reason=%s',
                    $batch_index + 1,
                    count($batches),
                    count($batch),
                    $result->get_error_message()
                ));
                continue;
            }
            foreach ($batch as $api_key => $unused) {
                $key = $api_to_key[$api_key];
                $row = $rows[$key];
                $parsed = $this->openai_parse_article_final_review_result($result[$api_key] ?? '');
                if (($parsed['action'] ?? 'invalid') === 'invalid') {
                    $invalid++;
                    $this->openai_quality_mark_unavailable('article_final_review', 1, 'invalid final review result for ' . $api_key);
                    continue;
                }
                $checked++;
                $this->openai_quality_runtime['ai_checked_unique']++;
                $this->openai_quality_runtime['ai_checked_raw_fields']++;
                $this->openai_quality_runtime['covered_raw_fields']++;
                if (($parsed['action'] ?? '') === 'keep') {
                    $kept++;
                    continue;
                }

                $rewrite_requested++;
                $candidate = $this->openai_normalize_translation_control_placeholders((string)($parsed['target'] ?? ''));
                $candidate = $this->openai_quality_finalize_candidate_spacing($candidate);
                $issue = '';
                if (preg_match('/(?:^|\n)\s*(?:SOURCE\s*:|CURRENT\s+TRANSLATION\s*:|ROLE\s*=|ISSUES\s*=)/iu', $candidate)) {
                    $issue = 'review_wrapper_echo';
                }
                if ($issue === '') {
                    $issue = $this->openai_translation_fragment_rejection_reason((string)$row['source'], $candidate, $settings, $target_label);
                }
                if ($candidate === '' || $issue !== '') {
                    $this->openai_quality_runtime['repair_failed']++;
                    $this->openai_quality_mark_unavailable('article_final_rewrite_validation', 1, $issue !== '' ? $issue : 'empty');
                    $this->openai_cli_trace_line(sprintf(
                        'ARTICLE FINAL REWRITE FAILURE key=%s reason=%s source=%s candidate=%s',
                        $key,
                        $issue !== '' ? $issue : 'empty',
                        $this->openai_cli_trace_snippet((string)$row['source'], 180),
                        $this->openai_cli_trace_snippet($candidate, 180)
                    ));
                    continue;
                }

                if ($row['kind'] === 'title') {
                    $this->openai_quality_runtime['field_overrides']['title'] = $candidate;
                } elseif ($row['kind'] === 'excerpt') {
                    $this->openai_quality_runtime['field_overrides']['excerpt'] = $candidate;
                } elseif ($row['kind'] === 'body' && array_key_exists($row['raw_id'], $translated_fragments)) {
                    $translated_fragments[$row['raw_id']] = $candidate;
                }
                $rewritten++;
            }
        }

        $this->openai_quality_runtime['checked'] += $checked;
        $this->openai_quality_runtime['keep'] += $kept;
        $this->openai_quality_runtime['rewrite'] += $rewrite_requested;
        $this->openai_quality_runtime['repaired'] += $rewritten;
        $this->openai_cli_trace_line(sprintf(
            'ARTICLE FINAL REVIEW RESULT expected=%d checked=%d keep=%d rewrite_requested=%d rewritten=%d invalid=%d coverage=%.2f%% status=%s',
            $candidate_count,
            $checked,
            $kept,
            $rewrite_requested,
            $rewritten,
            $invalid,
            $candidate_count > 0 ? ($checked * 100 / $candidate_count) : 100,
            $invalid > 0 || $checked !== $candidate_count ? 'partial' : 'completed'
        ));
        return $translated_fragments;
    }

    /** Backward-compatible wrapper for the pre-0.8.17.30 private method name. */

    private function openai_repair_suspicious_japanese_article_blocks($source_fragments, $translated_fragments, $items, $target_label, $settings) {
        return $this->openai_repair_suspicious_article_blocks($source_fragments, $translated_fragments, $items, $target_label, $settings);
    }

    private function openai_generic_translation_block_edit_issue($source, $translated, $type = 'text') {
        $type = strtolower((string)$type);
        if (in_array($type, ['h1','h2','h3','h4','h5','h6','button','option','summary','th'], true)) {
            return '';
        }

        $plain = $this->openai_normalize_translation_control_placeholders((string)$translated);
        $plain = preg_replace('/__WPMU_ML_ATOMIC_\d+__/u', ' ', (string)$plain);
        $plain = trim(html_entity_decode(wp_strip_all_tags((string)$plain), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($plain === '') {
            return '';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
        if ($length < 5) {
            return '';
        }

        $pairs = [
            ['(', ')'], ['[', ']'], ['{', '}'],
            ['（', '）'], ['【', '】'], ['「', '」'], ['『', '』'],
            ['“', '”'], ['‘', '’'], ['«', '»'], ['‹', '›'],
        ];
        foreach ($pairs as $pair) {
            if (substr_count($plain, $pair[0]) !== substr_count($plain, $pair[1])) {
                return 'unbalanced_punctuation';
            }
        }

        if (preg_match('/[,，;；:]\s*$/u', $plain)) {
            return 'dangling_punctuation';
        }
        return '';
    }

    /** Backward-compatible method name for third-party reflection/tests. */

    private function openai_japanese_translation_block_edit_issue($source, $translated, $type = 'text') {
        return $this->openai_generic_translation_block_edit_issue($source, $translated, $type);
    }

    private function contains_cjk_text($text) {
        return is_string($text) && preg_match('/\p{Han}/u', $text);
    }

    /**
     * Legacy compatibility shim. Source-language echo detection is performed by the
     * configured-language audit, because local script heuristics cannot be language-neutral.
     */

    private function openai_translation_fragment_is_suspiciously_unchanged($source, $translated, $settings, $target_label = '') {
        return false;
    }

    /** Normalize harmless model formatting changes around internal placeholder tags. */

    private function openai_normalize_translation_control_placeholders($value) {
        $value = (string)$value;
        if ($value === '') {
            return $value;
        }

        // Some compatible gateways HTML-escape custom placeholder tags inside JSON values.
        $value = preg_replace_callback(
            '~&lt;\s*(/?)\s*wpmu-ml-(\d+)\s*(/?)\s*&gt;~i',
            static function($m) {
                $close = (string)$m[1] === '/';
                $self = !$close && (string)$m[3] === '/';
                if ($close) {
                    return '</wpmu-ml-' . (int)$m[2] . '>';
                }
                return '<wpmu-ml-' . (int)$m[2] . ($self ? '/>' : '>');
            },
            $value
        );

        // Normalize case and insignificant whitespace, but never change IDs or structure.
        $value = preg_replace_callback(
            '~<\s*(/?)\s*wpmu-ml-(\d+)\s*(/?)\s*>~i',
            static function($m) {
                $close = (string)$m[1] === '/';
                $self = !$close && (string)$m[3] === '/';
                if ($close) {
                    return '</wpmu-ml-' . (int)$m[2] . '>';
                }
                return '<wpmu-ml-' . (int)$m[2] . ($self ? '/>' : '>');
            },
            (string)$value
        );

        return is_string($value) ? $value : '';
    }

    private function openai_translation_preserves_control_tokens($source, $translated) {
        $source = $this->openai_normalize_translation_control_placeholders((string)$source);
        $translated = $this->openai_normalize_translation_control_placeholders((string)$translated);

        // Legacy placeholders generated by older paths represent raw individual HTML tags;
        // retain the strict sequence check for those because reordering them can corrupt HTML.
        $legacy_pattern = '/(?:__WPMU_ML_(?!ATOMIC_)[A-Z0-9_]+__|%%WPMU_ML_[A-Z0-9_]+%%)/';
        preg_match_all($legacy_pattern, $source, $source_legacy);
        preg_match_all($legacy_pattern, $translated, $target_legacy);
        if (array_values((array)($source_legacy[0] ?? [])) !== array_values((array)($target_legacy[0] ?? []))) {
            return false;
        }

        // Atomic tokens can move with surrounding grammar, but every unique token must exist
        // exactly once in both source and translation.
        $atomic_pattern = '/__WPMU_ML_ATOMIC_\d+__/';
        preg_match_all($atomic_pattern, $source, $source_atomic);
        preg_match_all($atomic_pattern, $translated, $target_atomic);
        $source_atomic_values = array_values((array)($source_atomic[0] ?? []));
        $target_atomic_values = array_values((array)($target_atomic[0] ?? []));
        sort($source_atomic_values, SORT_STRING);
        sort($target_atomic_values, SORT_STRING);
        if ($source_atomic_values !== $target_atomic_values) {
            return false;
        }

        // Balanced custom tags may move as complete pairs for natural target-language word order.
        // Validate the exact multiset of tags and a well-nested target tree instead of forcing
        // every tag to remain at the same character position.
        $tag_pattern = '~</?wpmu-ml-\d+\s*/?>~i';
        preg_match_all($tag_pattern, $source, $source_tags);
        preg_match_all($tag_pattern, $translated, $target_tags);
        $source_tag_values = array_map('strtolower', array_values((array)($source_tags[0] ?? [])));
        $target_tag_values = array_map('strtolower', array_values((array)($target_tags[0] ?? [])));
        sort($source_tag_values, SORT_STRING);
        sort($target_tag_values, SORT_STRING);
        if ($source_tag_values !== $target_tag_values) {
            return false;
        }

        $stack = [];
        foreach (array_values((array)($target_tags[0] ?? [])) as $tag_token) {
            if (!preg_match('~^<\s*(/?)\s*wpmu-ml-(\d+)\s*(/?)\s*>$~i', (string)$tag_token, $m)) {
                return false;
            }
            $is_close = (string)$m[1] === '/';
            $is_self = !$is_close && (string)$m[3] === '/';
            $id = (int)$m[2];
            if ($is_self) {
                continue;
            }
            if (!$is_close) {
                $stack[] = $id;
                continue;
            }
            if (!$stack || (int)end($stack) !== $id) {
                return false;
            }
            array_pop($stack);
        }

        return !$stack;
    }

    private function normalize_openai_translated_value($value) {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->normalize_openai_translated_value($v);
            }
            return $value;
        }
        if (is_string($value)) {
            $map = [
                'u003c' => '<', 'u003C' => '<', 'u003e' => '>', 'u003E' => '>',
                'u0022' => '"', 'u0027' => "'", 'u0026' => '&', 'u002F' => '/', 'u002f' => '/',
            ];
            return strtr($value, $map);
        }
        return $value;
    }

    private function openai_normalize_plain_translation_fallback($content) {
        $content = trim((string)$content);
        if ($content === '') {
            return '';
        }

        // Remove common non-content wrappers that models add when they ignore the JSON-only contract.
        $content = preg_replace('~^```[a-zA-Z0-9_-]*\s*~', '', $content);
        $content = preg_replace('~\s*```$~', '', (string)$content);
        $content = trim((string)$content);

        // If the model prefixed a label, strip only obvious boilerplate labels.
        $content = preg_replace('~^(?:translation|translated text|译文|翻译结果)\s*[:：]\s*~iu', '', $content);
        $content = trim((string)$content);

        // Do not accept obvious refusals or explanations as a fallback translation.
        if (preg_match('~^(?:sorry|i\s+can\s*not|i\s+cannot|as\s+an\s+ai|抱歉|无法|不能)~iu', $content)) {
            return '';
        }

        return $content;
    }
    }
}
