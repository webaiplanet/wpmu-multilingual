<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI 请求、响应解析、重试、追踪与分块。
 *
 * 本 trait 仅用于拆分历史 WPMU_Multilingual 大类；方法签名和可见性保持不变。
 */
if (!trait_exists('WPMU_ML_Core_OpenAI_Client_Trait')) {
    trait WPMU_ML_Core_OpenAI_Client_Trait {
    private $openai_performance_runtime = [];

    private function openai_performance_runtime_reset() {
        $this->openai_performance_runtime = [
            'requests' => 0,
            'seconds' => 0.0,
            'failures' => 0,
            'fallbacks' => 0,
            'stages' => [],
            'reported' => false,
        ];
    }

    private function openai_performance_record_request($stage, $elapsed) {
        if (!is_array($this->openai_performance_runtime) || !$this->openai_performance_runtime) {
            $this->openai_performance_runtime_reset();
        }
        $stage = sanitize_key((string)$stage) ?: 'unknown';
        $this->openai_performance_runtime['requests']++;
        $this->openai_performance_runtime['seconds'] += max(0, (float)$elapsed);
        if (!isset($this->openai_performance_runtime['stages'][$stage])) {
            $this->openai_performance_runtime['stages'][$stage] = ['requests' => 0, 'seconds' => 0.0];
        }
        $this->openai_performance_runtime['stages'][$stage]['requests']++;
        $this->openai_performance_runtime['stages'][$stage]['seconds'] += max(0, (float)$elapsed);
    }

    private function openai_performance_mark_failure() {
        if (!is_array($this->openai_performance_runtime) || !$this->openai_performance_runtime) {
            $this->openai_performance_runtime_reset();
        }
        $this->openai_performance_runtime['failures']++;
    }

    private function openai_performance_mark_fallback() {
        if (!is_array($this->openai_performance_runtime) || !$this->openai_performance_runtime) {
            $this->openai_performance_runtime_reset();
        }
        $this->openai_performance_runtime['fallbacks']++;
    }

    private function openai_performance_runtime_summary() {
        return is_array($this->openai_performance_runtime) ? $this->openai_performance_runtime : [];
    }

    /** Emit one performance line for every terminal outcome, including failures. */
    private function openai_cli_trace_performance_summary($outcome = '') {
        $summary = $this->openai_performance_runtime_summary();
        if (!$summary || !empty($summary['reported']) || (int)($summary['requests'] ?? 0) <= 0) {
            return $summary;
        }
        $stage_parts = [];
        foreach ((array)($summary['stages'] ?? []) as $stage => $stats) {
            $stage_parts[] = $stage . ':' . (int)($stats['requests'] ?? 0) . '/' . number_format((float)($stats['seconds'] ?? 0), 1, '.', '') . 's';
        }
        $request_count = (int)($summary['requests'] ?? 0);
        $total_seconds = (float)($summary['seconds'] ?? 0);
        if ($request_count >= 10 && $request_count <= 17 && $total_seconds <= 180.0) {
            $target_status = $total_seconds < 120.0 ? 'faster_than_baseline' : 'within_target';
        } else {
            $target_status = ($request_count <= 22 && $total_seconds <= 240.0) ? 'acceptable' : 'over_target';
        }
        $this->openai_cli_trace_line(sprintf(
            'API PERFORMANCE outcome=%s requests=%d failures=%d fallbacks=%d total_seconds=%.1f target_requests=10-17 target_seconds=120-180 acceptable_seconds=240 target_status=%s stages=%s',
            sanitize_key((string)$outcome) ?: 'unknown',
            $request_count,
            (int)($summary['failures'] ?? 0),
            (int)($summary['fallbacks'] ?? 0),
            $total_seconds,
            $target_status,
            $stage_parts ? implode(',', $stage_parts) : '-'
        ));
        $this->openai_performance_runtime['reported'] = true;
        return $this->openai_performance_runtime_summary();
    }

    private function openai_cli_trace_enabled() {
        return defined('WP_CLI') && WP_CLI && defined('WPMU_ML_CLI_TRACE') && WPMU_ML_CLI_TRACE && class_exists('WP_CLI');
    }

    private function openai_cli_trace_stage($fields, $task_instruction = '') {
        $keys = array_keys((array)$fields);
        if (in_array('title', $keys, true) || in_array('excerpt', $keys, true)) {
            return 'title_excerpt';
        }

        $instruction = (string)$task_instruction;
        if (strpos($instruction, '[WPMU_ML_ARTICLE_CONTEXT]') !== false || strpos($instruction, '[WPMU_ML_ARTICLE_TERMINOLOGY]') !== false) {
            return 'article_context';
        }
        if (strpos($instruction, '[WPMU_ML_ARTICLE_FINAL_REVIEW]') !== false) {
            return 'article_final_review';
        }
        if (strpos($instruction, '[WPMU_ML_LANGUAGE_AUDIT]') !== false) {
            return 'language_qa';
        }
        if (strpos($instruction, '[WPMU_ML_EDITORIAL_AUDIT]') !== false || strpos($instruction, '[WPMU_ML_CENTRAL_QA]') !== false) {
            return 'article_editor_qa';
        }
        if (preg_match('/these are H([1-4]) heading/i', $instruction, $m)) {
            return 'body_heading_h' . intval($m[1]);
        }

        $functions = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 18) as $frame) {
            if (!empty($frame['function'])) {
                $functions[] = (string)$frame['function'];
            }
        }

        if (in_array('openai_translate_human_text_in_code_block', $functions, true) || in_array('openai_translate_raw_code_text_line_locked', $functions, true)) {
            return 'code_block';
        }
        if (in_array('openai_translate_wp_block_comment', $functions, true) || in_array('openai_translate_wp_block_comments', $functions, true)) {
            return 'gutenberg_block_data';
        }
        if (in_array('openai_translate_target_post_meta_from_source', $functions, true)) {
            return 'postmeta';
        }
        if (in_array('openai_translate_translatable_html_attributes', $functions, true)) {
            return 'body_attributes';
        }
        if (in_array('openai_translate_residual_source_text', $functions, true) || in_array('openai_translate_residual_body_cjk_text', $functions, true)) {
            return 'body_residual';
        }
        return 'body';
    }

    private function openai_cli_trace_snippet($text, $limit = 120) {
        $text = html_entity_decode(wp_strip_all_tags((string)$text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', trim((string)$text));
        if (!is_string($text)) {
            $text = '';
        }
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($length > $limit) {
            $text = function_exists('mb_substr') ? mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
            $text .= '…';
        }
        return wp_json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function openai_cli_trace_line($message) {
        if ($this->openai_cli_trace_enabled()) {
            WP_CLI::line('[WPMU-ML] ' . (string)$message);
        }
    }

    /**
     * Decode a JSON object from a response that may contain BOMs, PHP notices,
     * HTML wrappers, Markdown fences or other leading/trailing pollution.
     * Candidate objects are scored so an OpenAI envelope or the object with the
     * expected translation keys wins over unrelated debug JSON.
     */

    private function openai_decode_json_object_from_mixed_text($text, $expected_keys = [], $prefer_envelope = false) {
        $text = (string)$text;
        if (trim($text) === '') {
            return null;
        }

        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        $text = str_replace("\0", '', (string)$text);
        $variants = [(string)$text];

        if (preg_match_all('~```(?:json|javascript|js)?\s*(.*?)\s*```~is', (string)$text, $fenced)) {
            foreach ((array)($fenced[1] ?? []) as $candidate) {
                $variants[] = (string)$candidate;
            }
        }

        if (preg_match_all('/^\s*data:\s*(.+)$/mi', (string)$text, $sse_lines)) {
            foreach ((array)($sse_lines[1] ?? []) as $candidate) {
                if (trim((string)$candidate) !== '[DONE]') {
                    $variants[] = (string)$candidate;
                }
            }
        }

        $entity_decoded = html_entity_decode((string)$text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($entity_decoded !== $text) {
            $variants[] = $entity_decoded;
        }
        $tag_stripped = wp_strip_all_tags($entity_decoded);
        if ($tag_stripped !== $entity_decoded) {
            $variants[] = $tag_stripped;
        }

        $decoded_candidates = [];
        foreach (array_values(array_unique($variants)) as $variant) {
            $variant = trim((string)$variant);
            if ($variant === '') {
                continue;
            }

            $decoded = json_decode($variant, true);
            if (is_array($decoded)) {
                $decoded_candidates[] = $decoded;
            } elseif (is_string($decoded)) {
                $decoded_twice = json_decode(trim($decoded), true);
                if (is_array($decoded_twice)) {
                    $decoded_candidates[] = $decoded_twice;
                }
            }

            foreach ($this->openai_extract_balanced_json_fragments($variant) as $fragment) {
                $decoded_fragment = json_decode($fragment, true);
                if (is_array($decoded_fragment)) {
                    $decoded_candidates[] = $decoded_fragment;
                } elseif (is_string($decoded_fragment)) {
                    $decoded_twice = json_decode(trim($decoded_fragment), true);
                    if (is_array($decoded_twice)) {
                        $decoded_candidates[] = $decoded_twice;
                    }
                }
            }
        }

        if (!$decoded_candidates) {
            return null;
        }

        $expected_keys = array_values(array_map('strval', (array)$expected_keys));
        $best = null;
        $best_score = -PHP_INT_MAX;
        foreach ($decoded_candidates as $candidate) {
            $score = $this->openai_score_json_candidate($candidate, $expected_keys, (bool)$prefer_envelope);
            if ($score > $best_score) {
                $best = $candidate;
                $best_score = $score;
            }
        }
        return is_array($best) ? $best : null;
    }

    /**
     * Extract top-level balanced JSON objects/arrays while respecting quoted strings.
     *
     * @return string[]
     */

    private function openai_extract_balanced_json_fragments($text) {
        $text = (string)$text;
        $length = strlen($text);
        $fragments = [];
        $limit = 24;

        for ($start = 0; $start < $length && count($fragments) < $limit; $start++) {
            $first = $text[$start];
            if ($first !== '{' && $first !== '[') {
                continue;
            }

            $stack = [$first];
            $in_string = false;
            $escaped = false;
            for ($i = $start + 1; $i < $length; $i++) {
                $char = $text[$i];
                if ($in_string) {
                    if ($escaped) {
                        $escaped = false;
                    } elseif ($char === '\\') {
                        $escaped = true;
                    } elseif ($char === '"') {
                        $in_string = false;
                    }
                    continue;
                }

                if ($char === '"') {
                    $in_string = true;
                    continue;
                }
                if ($char === '{' || $char === '[') {
                    $stack[] = $char;
                    continue;
                }
                if ($char !== '}' && $char !== ']') {
                    continue;
                }

                $open = end($stack);
                $valid_pair = ($open === '{' && $char === '}') || ($open === '[' && $char === ']');
                if (!$valid_pair) {
                    break;
                }
                array_pop($stack);
                if (!$stack) {
                    $fragments[] = substr($text, $start, $i - $start + 1);
                    $start = $i;
                    break;
                }
            }
        }

        return $fragments;
    }

    private function openai_score_json_candidate($candidate, $expected_keys = [], $prefer_envelope = false) {
        if (!is_array($candidate)) {
            return -1000;
        }
        $score = 1;
        $is_envelope = isset($candidate['choices']) || isset($candidate['output']) || isset($candidate['output_text']);
        if ($is_envelope) {
            $score += $prefer_envelope ? 300 : 40;
        } elseif ($prefer_envelope) {
            $score -= 20;
        }

        $expected_keys = (array)$expected_keys;
        if ($expected_keys) {
            $root_keys = array_map('strval', array_keys($candidate));
            $matched = count(array_intersect($expected_keys, $root_keys));
            $score += $matched * 25;
            if ($matched === count($expected_keys)) {
                $score += 150;
            }
        }
        if (isset($candidate['error']) && !$is_envelope) {
            $score -= 50;
        }
        return $score;
    }

    /**
     * Read content from common OpenAI-compatible envelopes, content-part arrays,
     * legacy text responses, or a direct translated JSON object.
     */

    private function openai_extract_response_content($json, $expected_keys = []) {
        if (!is_array($json)) {
            return '';
        }

        $content = $json['choices'][0]['message']['content'] ?? null;
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            // Some compatible APIs return the JSON object directly as message.content.
            if ($this->openai_score_json_candidate($content, (array)$expected_keys, false) >= 100) {
                return (string)wp_json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $parts = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                    continue;
                }
                if (!is_array($part)) {
                    continue;
                }
                if (isset($part['text']) && is_string($part['text'])) {
                    $parts[] = $part['text'];
                } elseif (isset($part['text']['value']) && is_string($part['text']['value'])) {
                    $parts[] = $part['text']['value'];
                } elseif (isset($part['content']) && is_string($part['content'])) {
                    $parts[] = $part['content'];
                } elseif (isset($part['value']) && is_string($part['value'])) {
                    $parts[] = $part['value'];
                }
            }
            if ($parts) {
                return implode('', $parts);
            }
        }

        $legacy_text = $json['choices'][0]['text'] ?? null;
        if (is_string($legacy_text)) {
            return $legacy_text;
        }
        if (isset($json['output_text']) && is_string($json['output_text'])) {
            return $json['output_text'];
        }

        if (isset($json['output']) && is_array($json['output'])) {
            $parts = [];
            foreach ($json['output'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach ((array)($item['content'] ?? []) as $part) {
                    if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                        $parts[] = $part['text'];
                    }
                }
            }
            if ($parts) {
                return implode('', $parts);
            }
        }

        // Some gateways wrap a complete OpenAI-compatible envelope inside data.
        if (isset($json['data']) && is_array($json['data'])
            && (isset($json['data']['choices']) || isset($json['data']['output']) || isset($json['data']['output_text']))) {
            $nested_content = $this->openai_extract_response_content($json['data'], $expected_keys);
            if (is_string($nested_content) && trim($nested_content) !== '') {
                return $nested_content;
            }
        }

        // A few gateways return the translated object directly instead of an envelope.
        if ($this->openai_score_json_candidate($json, (array)$expected_keys, false) >= 100) {
            return (string)wp_json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (isset($json['data']) && is_array($json['data']) && $this->openai_score_json_candidate($json['data'], (array)$expected_keys, false) >= 100) {
            return (string)wp_json_encode($json['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return '';
    }

    private function openai_trace_value_text($value) {
        if (is_string($value)) {
            return $value;
        }
        if ($value === null) {
            return '';
        }
        if (is_scalar($value)) {
            return (string)$value;
        }
        $encoded = wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '';
    }

    private function openai_safe_endpoint_for_trace($endpoint) {
        $parts = function_exists('wp_parse_url') ? wp_parse_url((string)$endpoint) : parse_url((string)$endpoint);
        if (!is_array($parts)) {
            return 'configured-endpoint';
        }
        $safe = '';
        if (!empty($parts['scheme'])) {
            $safe .= $parts['scheme'] . '://';
        }
        $safe .= (string)($parts['host'] ?? 'configured-host');
        if (!empty($parts['port'])) {
            $safe .= ':' . (int)$parts['port'];
        }
        $safe .= (string)($parts['path'] ?? '');
        return $safe !== '' ? $safe : 'configured-endpoint';
    }

    private function openai_response_diagnostics($json, $raw_body = '') {
        $diagnostic_json = $json;
        if (is_array($json) && empty($json['choices']) && isset($json['data']) && is_array($json['data'])) {
            $diagnostic_json = $json['data'];
        }

        $protocol = 'unknown';
        $message = [];
        $content = null;
        $content_key_present = false;
        $reasoning = null;
        $refusal = null;
        $finish_reason = '';
        $status = '';
        $message_keys = '-';

        if (is_array($diagnostic_json) && isset($diagnostic_json['choices'][0])) {
            $protocol = 'chat_completions';
            $choice = is_array($diagnostic_json['choices'][0]) ? $diagnostic_json['choices'][0] : [];
            $message = isset($choice['message']) && is_array($choice['message']) ? $choice['message'] : [];
            $content_key_present = array_key_exists('content', $message);
            $content = $message['content'] ?? null;
            $reasoning = $message['reasoning_content'] ?? ($message['analysis'] ?? null);
            $refusal = $message['refusal'] ?? null;
            $finish_reason = (string)($choice['finish_reason'] ?? '');
            $status = $finish_reason;
            $message_keys = $message ? implode(',', array_keys($message)) : '-';
        } elseif (is_array($diagnostic_json) && (isset($diagnostic_json['output']) || isset($diagnostic_json['output_text']))) {
            $protocol = 'responses';
            $status = (string)($diagnostic_json['status'] ?? '');
            $finish_reason = $status;
            $content = $this->openai_extract_response_content($diagnostic_json, []);
            $content_key_present = isset($diagnostic_json['output_text']);
            $output_keys = [];
            $part_keys = [];
            $reasoning_parts = [];
            $refusal_parts = [];
            foreach ((array)($diagnostic_json['output'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $output_keys = array_merge($output_keys, array_keys($item));
                if (($item['type'] ?? '') === 'reasoning') {
                    $reasoning_parts[] = $this->openai_trace_value_text($item['summary'] ?? ($item['content'] ?? ''));
                }
                foreach ((array)($item['content'] ?? []) as $part) {
                    if (!is_array($part)) {
                        continue;
                    }
                    $part_keys = array_merge($part_keys, array_keys($part));
                    $part_type = (string)($part['type'] ?? '');
                    if ($part_type === 'output_text' && array_key_exists('text', $part)) {
                        $content_key_present = true;
                    }
                    if ($part_type === 'refusal') {
                        $refusal_parts[] = $this->openai_trace_value_text($part['refusal'] ?? ($part['text'] ?? ''));
                    }
                }
            }
            $reasoning = implode("\n", array_filter($reasoning_parts, 'strlen'));
            $refusal = implode("\n", array_filter($refusal_parts, 'strlen'));
            $output_keys = array_values(array_unique(array_map('strval', $output_keys)));
            $part_keys = array_values(array_unique(array_map('strval', $part_keys)));
            $message_keys = 'output:' . ($output_keys ? implode(',', $output_keys) : '-')
                . ';parts:' . ($part_keys ? implode(',', $part_keys) : '-');
        }

        $embedded_error = is_array($diagnostic_json) ? ($diagnostic_json['error'] ?? null) : null;
        $usage = is_array($diagnostic_json) && isset($diagnostic_json['usage']) && is_array($diagnostic_json['usage']) ? $diagnostic_json['usage'] : [];

        return [
            'protocol' => $protocol,
            'status' => $status,
            'outer_keys' => is_array($json) ? implode(',', array_keys($json)) : '-',
            'message_keys' => $message_keys,
            'content_key_present' => $content_key_present,
            'content_type' => gettype($content),
            'content_text' => $this->openai_trace_value_text($content),
            'reasoning_text' => $this->openai_trace_value_text($reasoning),
            'refusal_text' => $this->openai_trace_value_text($refusal),
            'error_text' => $this->openai_trace_value_text($embedded_error),
            'finish_reason' => $finish_reason,
            'prompt_tokens' => (int)($usage['prompt_tokens'] ?? ($usage['input_tokens'] ?? 0)),
            'completion_tokens' => (int)($usage['completion_tokens'] ?? ($usage['output_tokens'] ?? 0)),
            'total_tokens' => (int)($usage['total_tokens'] ?? 0),
            'raw_bytes' => strlen((string)$raw_body),
        ];
    }

    private function openai_parse_single_status_fallback($content, $type) {
        $raw = trim((string)$content);
        if ($type === 'article_final_review') {
            $normalized_raw = strtolower(trim($raw, " \t\n\r\0\x0B\"'`."));
            if (in_array($normalized_raw, ['keep', 'ok', 'pass', 'accepted', 'unchanged', 'no_change'], true)) {
                return 'keep';
            }
            if (preg_match('/^rewrite\s*\|\|\|([\s\S]+)$/iu', $raw, $m)) {
                $target = trim((string)($m[1] ?? ''));
                return $target !== '' ? ('rewrite|||' . $target) : '';
            }
            return '';
        }
        $plain = strtolower(trim(wp_strip_all_tags($raw)));
        $plain = trim($plain, " \t\n\r\0\x0B\"'`.");
        if ($type === 'language_qa') {
            if (preg_match('/^(?:ok|pass|true|correct|target)$/iu', $plain)) {
                return 'ok';
            }
            if (preg_match('/^(?:wrong|fail|false|off[-_ ]?target|source[-_ ]?language)(?:\\s*[:：-]\\s*(.*))?$/iu', $plain, $m)) {
                $reason = trim((string)($m[1] ?? ''));
                return 'wrong:' . ($reason !== '' ? $reason : 'off_target_language');
            }
        }
        if ($type === 'article_editor_qa') {
            if (preg_match('/^(?:keep|ok|pass|true|correct|accept|accepted|no[-_ ]?change|unchanged)$/iu', $plain)) {
                return 'keep';
            }
            if (preg_match('/^(?:rewrite|wrong|fail|failed|reject|rejected|fix|revise|revision|improve|needs?[-_ ]?(?:rewrite|revision|fix))(?:\s*[:：-]\s*(.*))?$/iu', $plain, $m)) {
                $reason = trim((string)($m[1] ?? ''));
                return 'rewrite:' . ($reason !== '' ? $reason : 'editorial_defect');
            }
        }
        return '';
    }

    private function openai_split_empty_request_fields($fields) {
        $active = [];
        $empty = [];
        foreach ((array)$fields as $key => $value) {
            $key = (string)$key;
            $text = is_scalar($value) || $value === null
                ? (string)$value
                : (string)wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (trim($text) === '') {
                $empty[$key] = $value;
            } else {
                $active[$key] = $value;
            }
        }
        return [$active, $empty];
    }

    private function openai_restore_empty_request_fields($translated, $original_fields, $empty_fields) {
        if (!is_array($translated)) {
            return $translated;
        }
        $merged = [];
        foreach ((array)$original_fields as $key => $original_value) {
            $key = (string)$key;
            if (array_key_exists($key, $translated)) {
                $merged[$key] = $translated[$key];
            } elseif (array_key_exists($key, (array)$empty_fields)) {
                $merged[$key] = $original_value;
            }
        }
        foreach ($translated as $key => $value) {
            if (!array_key_exists((string)$key, $merged)) {
                $merged[(string)$key] = $value;
            }
        }
        return $merged;
    }

    private function openai_compact_prompt_component($text, $limit) {
        $text = trim((string)$text);
        $limit = max(0, (int)$limit);
        if ($text === '' || $limit === 0) {
            return '';
        }
        if ($this->wpmu_ml_strlen($text) <= $limit) {
            return $text;
        }
        return rtrim($this->wpmu_ml_substr($text, 0, $limit)) . "\n[truncated for compact fallback]";
    }

    private function openai_build_compact_system_prompt($target_label, $task_instruction, $settings, $trace_stage, $status_type) {
        $source_context = is_array($settings['openai_source_language_context'] ?? null)
            ? $settings['openai_source_language_context']
            : [];
        $target_context = is_array($settings['openai_target_language_context'] ?? null)
            ? $settings['openai_target_language_context']
            : [];
        $source_description = trim((string)($source_context['prompt_label'] ?? 'the configured source language'));
        $target_description = trim((string)($target_context['prompt_label'] ?? $target_label));
        $source_description = $source_description !== '' ? $source_description : 'the configured source language';
        $target_description = $target_description !== '' ? $target_description : (string)$target_label;

        if ($status_type === 'language_qa') {
            return 'You are a strict language auditor. The source is ' . $source_description
                . ' and the required target is ' . $target_description
                . '. Judge each candidate independently. Do not translate or rewrite. Allow brands, product names, code, commands, URLs, paths, identifiers, acronyms, model names, numbers and conventional technical terms. Ordinary prose must use the configured target language and locale.';
        }
        if ($status_type === 'article_final_review') {
            $review_prompt = 'You are the final publication-quality localization editor. Each input value is a JSON string containing role, source text, current target text, section context and advisory hints. Compare source and target directly. Return keep when the current target is faithful, natural and consistent. Return rewrite||| followed by the complete corrected target value only when a concrete defect exists. Never rewrite merely because PHP supplied a hint. Preserve facts, brands, product names, numbers, URLs, placeholders, markup and machine tokens.';
            $review_language = $this->openai_compact_prompt_component((string)($settings['openai_language_prompt'] ?? ''), 600);
            if ($review_language !== '') {
                $review_prompt .= "\n\nTarget-language guidance:\n" . $review_language;
            }
            $review_context = $this->openai_compact_prompt_component((string)($settings['openai_article_terminology_context'] ?? ''), 1500);
            if ($review_context !== '') {
                $review_prompt .= "\n\nSource article context:\n" . $review_context
                    . "\nUse source review fragments only to detect mistranslation and inconsistency; they are not fixed target-language replacements.";
            }
            return $review_prompt;
        }
        if ($status_type === 'article_editor_qa') {
            $editor_prompt = 'You are a publication-quality localization editor. Review every supplied source/current-target pair for fidelity, completeness, natural target-locale writing, article coherence and real editorial defects. Do not rewrite in this audit step. Return keep when acceptable and rewrite only for a concrete defect. PHP hints are advisory only. Preserve facts, brands, product names, numbers and technical meaning.';
            $editor_task = str_replace(['[WPMU_ML_EDITORIAL_AUDIT]', '[WPMU_ML_CENTRAL_QA]'], '', (string)$task_instruction);
            $editor_task = $this->openai_compact_prompt_component($editor_task, 1000);
            if ($editor_task !== '') {
                $editor_prompt .= "

Review contract:
" . $editor_task;
            }
            $editor_context = $this->openai_compact_prompt_component((string)($settings['openai_article_terminology_context'] ?? ''), 1200);
            if ($editor_context !== '') {
                $editor_prompt .= "

Article context:
" . $editor_context
                    . "
Use this only to understand the article and ambiguous source concepts; it does not prescribe target wording.";
            }
            return $editor_prompt;
        }
        if ($trace_stage === 'article_context') {
            return 'You are a translation planning editor. Read the ' . $source_description . ' article and produce only a compact planning brief for translation into ' . $target_description . '. Return topic, audience, style and high-risk SOURCE concepts only. Do not translate the article and do not propose target-language term replacements.';
        }

        $lines = [];
        $lines[] = 'You are a professional website localization translator.';
        $lines[] = 'Translate from ' . $source_description . ' into ' . $target_description . '. Follow the configured target locale exactly.';
        $lines[] = 'Produce natural, publication-ready target-language copy rather than literal source-language word order.';
        $lines[] = 'Preserve facts, numeric values, the original currency, brands, verified official product/service names, code, commands, URLs, file paths, identifiers, placeholders and markup. Never convert currencies or invent information.';
        $lines[] = 'Localize human-readable prices, billing periods, units, compact technical specifications and mixed alphanumeric source-language expressions naturally from context. Do not preserve source-language unit words merely because a fragment resembles a technical token.';
        $lines[] = 'Preserve the requested structure and do not add explanations.';
        if (!array_key_exists('openai_translation_self_review', (array)$settings) || !empty($settings['openai_translation_self_review'])) {
            $lines[] = 'Before returning the final JSON, silently self-review every value for fidelity, completeness, native target-locale wording, terminology consistency, numbers/units/entity relationships and placeholder integrity. Correct issues internally and output only final translations, never review notes.';
        }

        if ($trace_stage === 'article_context') {
            $lines[] = 'For article planning: return only a short topic/audience/style brief and high-risk source concepts. Do not propose target translations and do not translate the article.';
        } elseif ($trace_stage === 'title_excerpt') {
            $lines[] = 'For titles and excerpts: use concise, natural, SEO-aware target-locale wording; preserve search intent, facts, numbers, prices and brands; avoid overlong titles, keyword stuffing and awkward repetition.';
        } elseif ($trace_stage === 'postmeta') {
            $lines[] = 'For metadata and SEO fields: write concise, natural target-locale copy suitable for publication; preserve the field purpose and do not add claims.';
        } elseif (strpos($trace_stage, 'body_heading_h') === 0) {
            $lines[] = 'For headings: return concise, informative headings that fit the surrounding article.';
        } elseif (in_array($trace_stage, ['body', 'body_attributes', 'body_residual', 'gutenberg_block_data', 'code_block'], true)) {
            $lines[] = 'For article content: translate only human-readable text, preserve HTML/WordPress/code structure and use neighboring context to resolve short specifications, labels and fragments.';
        }

        $language_prompt = $this->openai_compact_prompt_component((string)($settings['openai_language_prompt'] ?? ''), 1200);
        if ($language_prompt !== '') {
            $lines[] = "Target-language guidance:\n" . $language_prompt;
        }

        $site_rules = $this->openai_compact_prompt_component((string)($settings['openai_agent_site_rules'] ?? ''), 1400);
        if ($site_rules !== '') {
            $lines[] = "Site rules:\n" . $site_rules;
        }

        if (class_exists('WPMU_ML_OpenAI_Helper')) {
            $bundle = WPMU_ML_OpenAI_Helper::build_shared_rules_bundle($settings, $target_context, $target_label);
            $glossary = $this->openai_compact_prompt_component((string)($bundle['glossary']['effective_for_target'] ?? ''), 1800);
            if ($glossary !== '') {
                $lines[] = "Terminology for this target:\n" . $glossary;
            }
        }


        $article_terms = $this->openai_compact_prompt_component((string)($settings['openai_article_terminology_context'] ?? ''), 1800);
        if ($article_terms !== '') {
            $lines[] = "Article-specific translation context:\n" . $article_terms;
            $lines[] = 'Use this brief only to understand the article and ambiguous source concepts. Choose natural target-language wording from real context; it is not a replacement table. An explicit configured glossary has higher priority.';
        }

        $task = str_replace(['[WPMU_ML_LANGUAGE_AUDIT]', '[WPMU_ML_EDITORIAL_AUDIT]'], '', (string)$task_instruction);
        $task = $this->openai_compact_prompt_component($task, 1000);
        if ($task !== '') {
            $lines[] = "Current task:\n" . $task;
        }

        return implode("\n\n", $lines);
    }

    private function openai_translate_json_fields($fields, $target_label, $settings, $task_instruction) {
        $api_key = trim((string)($settings['openai_api_key'] ?? ''));
        if ($api_key === '') {
            return new WP_Error('wpmu_ml_openai_no_key', 'OpenAI 兼容 API Key 为空。');
        }

        $original_fields = is_array($fields) ? $fields : [];
        list($fields, $empty_fields) = $this->openai_split_empty_request_fields($original_fields);
        $field_keys = array_map('strval', array_keys($fields));
        if (!$field_keys) {
            if ($this->openai_cli_trace_enabled() && $original_fields) {
                $this->openai_cli_trace_line('API SKIP reason=all_fields_empty keys=' . implode(',', array_map('strval', array_keys($original_fields))));
            }
            return $original_fields;
        }

        $base = trim((string)($settings['openai_api_base'] ?? 'https://api.openai.com/v1'));
        $chat_endpoint = rtrim($base, '/') . '/chat/completions';
        $responses_endpoint = rtrim($base, '/') . '/responses';
        $model = trim((string)($settings['openai_model'] ?? 'gpt-4o-mini'));
        $temperature = (float)($settings['openai_temperature'] ?? 0.2);
        $timeout = max(15, absint($settings['openai_timeout'] ?? 300));
        $is_article_final_review = strpos((string)$task_instruction, '[WPMU_ML_ARTICLE_FINAL_REVIEW]') !== false;
        $is_editorial_audit = strpos((string)$task_instruction, '[WPMU_ML_EDITORIAL_AUDIT]') !== false
            || strpos((string)$task_instruction, '[WPMU_ML_CENTRAL_QA]') !== false;
        $is_language_audit = strpos((string)$task_instruction, '[WPMU_ML_LANGUAGE_AUDIT]') !== false;
        $trace_stage = $this->openai_cli_trace_stage($fields, $task_instruction);

        if ($is_article_final_review) {
            $source_context = is_array($settings['openai_source_language_context'] ?? null)
                ? $settings['openai_source_language_context']
                : [];
            $target_context = is_array($settings['openai_target_language_context'] ?? null)
                ? $settings['openai_target_language_context']
                : [];
            $source_description = trim((string)($source_context['prompt_label'] ?? 'the configured source language'));
            $target_description = trim((string)($target_context['prompt_label'] ?? $target_label));
            $system_prompt_base = 'You are the final publication-quality localization editor. Review source/current-target pairs from ' . $source_description . ' into ' . $target_description . '. Each input value is a JSON string containing r=role, s=source, t=current target, c=section context and h=advisory hints. Compare source and target directly. Return keep when acceptable. Return rewrite||| followed immediately by the complete corrected target-language field only for a concrete fidelity, naturalness, source-language residue or article-consistency defect. Hints are advisory only. Preserve facts, brands, product names, numbers, URLs, HTML, placeholders and machine tokens. Do not output explanations.';
            $final_language_prompt = trim((string)($settings['openai_language_prompt'] ?? ''));
            if ($final_language_prompt !== '') {
                $system_prompt_base .= "\n\nTARGET-LANGUAGE GUIDANCE:\n" . $final_language_prompt;
            }
            $article_source_context = trim((string)($settings['openai_article_terminology_context'] ?? ''));
            if ($article_source_context !== '') {
                $system_prompt_base .= "\n\nSOURCE ARTICLE CONTEXT:\n" . $article_source_context
                    . "\nSource review fragments are review targets, not fixed target-language replacements.";
            }
        } elseif ($is_editorial_audit) {
            $source_context = is_array($settings['openai_source_language_context'] ?? null)
                ? $settings['openai_source_language_context']
                : [];
            $target_context = is_array($settings['openai_target_language_context'] ?? null)
                ? $settings['openai_target_language_context']
                : [];
            $source_description = trim((string)($source_context['prompt_label'] ?? 'the configured source language'));
            $target_description = trim((string)($target_context['prompt_label'] ?? $target_label));
            $source_description = $source_description !== '' ? $source_description : 'the configured source language';
            $target_description = $target_description !== '' ? $target_description : 'the configured target language';
            $system_prompt_base = 'You are a senior multilingual translation editor and publication-quality reviewer, not a translator in this step. The configured source is: '
                . $source_description
                . '. The configured target is: '
                . $target_description
                . '. Each JSON value contains a block type, a source block and its current target translation. Review the ordered values as one article. Use professional editorial judgment rather than fixed word lists or language-specific regex rules. Decide whether the current target is faithful, complete, contextually coherent, idiomatic and publication-ready. Do not rewrite or translate in this audit step. '
                . str_replace(['[WPMU_ML_EDITORIAL_AUDIT]', '[WPMU_ML_CENTRAL_QA]'], '', (string)$task_instruction);
            $editor_article_terms = trim((string)($settings['openai_article_terminology_context'] ?? ''));
            if ($editor_article_terms !== '') {
                $system_prompt_base .= "\n\nARTICLE-SPECIFIC TRANSLATION CONTEXT:\n" . $editor_article_terms
                    . "\nUse this brief to understand the article and detect inconsistent, literal or source-language-contaminated variants. It does not prescribe target wording; return keep when the current translation is already acceptable.";
            }
        } elseif ($is_language_audit) {
            $source_context = is_array($settings['openai_source_language_context'] ?? null)
                ? $settings['openai_source_language_context']
                : [];
            $target_context = is_array($settings['openai_target_language_context'] ?? null)
                ? $settings['openai_target_language_context']
                : [];
            $source_description = trim((string)($source_context['prompt_label'] ?? 'the configured source language'));
            $target_description = trim((string)($target_context['prompt_label'] ?? $target_label));
            $source_description = $source_description !== '' ? $source_description : 'the configured source language';
            $target_description = $target_description !== '' ? $target_description : 'the configured target language';
            $system_prompt_base = 'You are a strict multilingual language-identification auditor, not a translator. The configured source is: '
                . $source_description
                . '. The configured target is: '
                . $target_description
                . '. Inspect each SOURCE/CANDIDATE pair independently. Decide whether the candidate ordinary prose is written in the configured target language and locale. Do not translate, rewrite, improve or explain the text. Allow preserved brands, product names, code, commands, URLs, filenames, identifiers, acronyms, model names, numbers, quotations and conventional technical terms even when they use another language or script. '
                . str_replace('[WPMU_ML_LANGUAGE_AUDIT]', '', (string)$task_instruction);
        } else {
            $system_prompt_base = class_exists('WPMU_ML_OpenAI_Helper')
                ? WPMU_ML_OpenAI_Helper::build_system_prompt($target_label, $task_instruction, $settings)
                : ('You are a professional native-quality website translator. Translate content from the configured source language into ' . $target_label . '. ' . $task_instruction . ' Write natural, credible and idiomatic target-language text that reads as if written by a native technical writer, while preserving meaning and structure. Do not add explanations.');
            $system_prompt_base .= "\n\nUNIVERSAL LOCALIZATION RULES: Preserve verified official product and service names, facts, numeric values, the original currency and genuine machine-readable tokens. Never convert currencies. Localize surrounding human-readable product-category wording naturally. Translate source-language words embedded in prices, billing periods, units, compact technical specifications and mixed alphanumeric expressions according to context. Do not treat a value as immutable merely because it contains numbers, Latin letters, abbreviations or symbols. Avoid mechanical brand-plus-category repetition unless it is the verified official name. Do not use a growing list of one-off phrase substitutions; infer the correct target-language expression from context.";
            if (!array_key_exists('openai_translation_self_review', (array)$settings) || !empty($settings['openai_translation_self_review'])) {
                $system_prompt_base .= "\n\nINLINE SELF-REVIEW: Before returning the final JSON, silently compare every translated value with its source and the other ordered values from the same request. Correct fidelity errors, omissions, unsupported additions, literal source-language syntax, non-native target-locale wording, inconsistent terminology, wrong role wording, number/unit/entity attachment errors and damaged placeholders. Perform this review internally and output only the final corrected translations; never output confidence labels, reasoning or review notes.";
            }
        }

        if ($is_article_final_review) {
            $json_contract = 'STRICT OUTPUT FORMAT: Return ONLY one valid JSON object. Keep exactly the same keys as the input object. Each value must be either exactly "keep" or start with "rewrite|||" immediately followed by the complete corrected target value. The text after rewrite||| is publishable article text, not a reason. Do not use Markdown, code fences or explanations.';
            $plain_contract = 'PLAIN-TEXT FALLBACK: Return only keep or rewrite||| followed by the complete corrected target value. Do not return commentary.';
            $status_type = 'article_final_review';
        } elseif ($is_editorial_audit) {
            $json_contract = 'STRICT OUTPUT FORMAT: Return ONLY one valid JSON object. Do not use Markdown or code fences. Keep exactly the same keys as the input object. Each value must be only "keep" or "rewrite:<brief reason>". Use rewrite only for a real defect, not a subjective style preference.';
            $plain_contract = 'PLAIN-TEXT FALLBACK: Review the single input value and return only "keep" or "rewrite:<brief reason>". Do not return JSON, Markdown, commentary or any additional text.';
            $status_type = 'article_editor_qa';
        } elseif ($is_language_audit) {
            $json_contract = 'STRICT OUTPUT FORMAT: Return ONLY one valid JSON object. Do not use Markdown or code fences. Keep exactly the same keys as the input object. Each value must be only "ok" or "wrong:<brief reason>".';
            $plain_contract = 'PLAIN-TEXT FALLBACK: Audit the single input value and return only "ok" or "wrong:<brief reason>". Do not return JSON, Markdown, commentary or any additional text.';
            $status_type = 'language_qa';
        } else {
            $json_contract = 'STRICT OUTPUT FORMAT: Return ONLY one valid JSON object. Do not use Markdown or code fences. Keep exactly the same keys as the input object. Each value must be the translated value only.';
            $plain_contract = 'PLAIN-TEXT FALLBACK: Translate the single input value into the configured target language and locale. Preserve facts, numeric values, the original currency, brands, verified official product names and protected technical tokens. Never convert currencies or invent information. Return only the complete translated value. Do not return JSON, quotes, labels, Markdown, commentary or explanations. Never return an empty answer. If the value is already valid target-language content, return it unchanged.';
            $status_type = 'translation';
        }

        $compact_prompt_base = $this->openai_build_compact_system_prompt(
            $target_label,
            $task_instruction,
            $settings,
            $trace_stage,
            $status_type
        );
        $full_json_system_prompt = $system_prompt_base . "\n\n" . $json_contract;
        $compact_json_system_prompt = $compact_prompt_base . "\n\n" . $json_contract;
        $compact_plain_system_prompt = $compact_prompt_base . "\n\n" . $plain_contract;

        $json_user_prompt = wp_json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json_user_prompt)) {
            return new WP_Error('wpmu_ml_openai_encode_failed', '翻译请求 JSON 编码失败。');
        }
        $json_user_prompt_prefixed = in_array($trace_stage, ['postmeta', 'language_qa', 'title_excerpt'], true)
            ? ("Input JSON object:\n" . $json_user_prompt)
            : $json_user_prompt;

        $allow_response_format = !preg_match('~^https?://127\\.0\\.0\\.1:3000(?:/|$)~', $base);
        $lightweight_stage = in_array($trace_stage, ['article_context', 'article_terminology', 'title_excerpt', 'postmeta', 'language_qa', 'article_editor_qa', 'article_final_review', 'body_residual', 'gutenberg_block_data', 'body_attributes', 'code_block'], true)
            || strpos($trace_stage, 'body_heading_h') === 0;
        $modes = [];
        $centralized_pipeline = method_exists($this, 'openai_centralized_quality_enabled')
            && $this->openai_centralized_quality_enabled($settings);
        if ($lightweight_stage) {
            if ($allow_response_format) {
                $modes[] = 'compact_json_object';
            }
            $modes[] = 'compact_json';
        } elseif ($centralized_pipeline && $trace_stage === 'body') {
            // The compact prompt still contains the target locale, site rules, glossary,
            // fidelity and structure contract. Starting with it removes thousands of prompt
            // characters from each large body batch; the full prompt remains a fallback.
            $modes[] = 'compact_json';
            if ($allow_response_format) {
                $modes[] = 'full_json_object';
            }
            $modes[] = 'full_json';
        } else {
            if ($allow_response_format) {
                $modes[] = 'full_json_object';
            }
            $modes[] = 'full_json';
            $modes[] = 'compact_json';
        }
        if (count($field_keys) === 1) {
            $modes[] = 'plain_text_chat';
            $modes[] = 'plain_text_responses';
        }
        $modes = array_values(array_unique($modes));

        $max_request_attempts = max(1, absint($settings['openai_request_max_attempts'] ?? 6));
        $input_chars = 0;
        foreach ($fields as $value) {
            $input_chars += $this->wpmu_ml_strlen((string)$value);
        }
        $request_timeout = $timeout;
        if (in_array($trace_stage, ['postmeta', 'language_qa', 'title_excerpt'], true) && $input_chars <= 1000) {
            $request_timeout = min($timeout, 120);
        }

        static $trace_call_no = 0;
        static $trace_request_no = 0;
        $trace_call_id = ++$trace_call_no;
        $language_prompt_chars = $this->wpmu_ml_strlen((string)($settings['openai_language_prompt'] ?? ''));
        $site_rules_chars = $this->wpmu_ml_strlen((string)($settings['openai_agent_site_rules'] ?? ''));
        $glossary_chars = $this->wpmu_ml_strlen((string)($settings['openai_agent_terms'] ?? ''));
        if ($this->openai_cli_trace_enabled()) {
            $this->openai_cli_trace_line(sprintf(
                'API CALL #%d PLAN stage=%s chat_endpoint=%s responses_endpoint=%s model=%s fields=%d original_fields=%d removed_empty=%d chars=%d timeout=%ds modes=%s full_system_chars=%d compact_system_chars=%d task_chars=%d',
                $trace_call_id,
                $trace_stage,
                $this->openai_safe_endpoint_for_trace($chat_endpoint),
                $this->openai_safe_endpoint_for_trace($responses_endpoint),
                $model,
                count($fields),
                count($original_fields),
                count($empty_fields),
                $input_chars,
                $request_timeout,
                implode('>', $modes),
                $this->wpmu_ml_strlen($full_json_system_prompt),
                $this->wpmu_ml_strlen($compact_json_system_prompt),
                $this->wpmu_ml_strlen((string)$task_instruction)
            ));
            $this->openai_cli_trace_line(sprintf(
                'PROMPT COMPONENTS call=%d language_prompt_chars=%d site_rules_chars=%d glossary_chars=%d task_chars=%d full_base_chars=%d compact_base_chars=%d contract_chars=%d',
                $trace_call_id,
                $language_prompt_chars,
                $site_rules_chars,
                $glossary_chars,
                $this->wpmu_ml_strlen((string)$task_instruction),
                $this->wpmu_ml_strlen($system_prompt_base),
                $this->wpmu_ml_strlen($compact_prompt_base),
                $this->wpmu_ml_strlen($json_contract)
            ));
            foreach ($empty_fields as $field_key => $field_value) {
                $this->openai_cli_trace_line(sprintf(
                    'API FIELD SKIP call=%d key=%s reason=empty chars=%d',
                    $trace_call_id,
                    (string)$field_key,
                    $this->wpmu_ml_strlen((string)$field_value)
                ));
            }
            foreach ($fields as $field_key => $field_value) {
                $field_text = (string)$field_value;
                $this->openai_cli_trace_line(sprintf(
                    'API FIELD call=%d key=%s chars=%d sha256=%s source=%s',
                    $trace_call_id,
                    (string)$field_key,
                    $this->wpmu_ml_strlen($field_text),
                    substr(hash('sha256', $field_text), 0, 16),
                    $this->openai_cli_trace_snippet($field_text, 500)
                ));
            }
        }

        $request_count = 0;
        $mode_index = 0;
        $last_failure = 'unknown';
        $last_diagnostics = [];
        $last_content = '';

        while ($request_count < $max_request_attempts && isset($modes[$mode_index])) {
            $request_count++;
            $mode = (string)$modes[$mode_index];
            $trace_id = ++$trace_request_no;
            $is_responses_mode = $mode === 'plain_text_responses';
            $request_endpoint = $is_responses_mode ? $responses_endpoint : $chat_endpoint;
            $request_system_prompt = in_array($mode, ['full_json', 'full_json_object'], true)
                ? $full_json_system_prompt
                : $compact_json_system_prompt;
            $request_user_prompt = $json_user_prompt_prefixed;

            if (in_array($mode, ['plain_text_chat', 'plain_text_responses'], true)) {
                $only_key = (string)$field_keys[0];
                $request_system_prompt = $compact_plain_system_prompt;
                $request_user_prompt = (string)($fields[$only_key] ?? '');
            }

            if ($is_responses_mode) {
                $request_body = [
                    'model' => $model,
                    'instructions' => $request_system_prompt,
                    'input' => $request_user_prompt,
                ];
            } else {
                $request_body = [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $request_system_prompt],
                        ['role' => 'user', 'content' => $request_user_prompt],
                    ],
                    'temperature' => $temperature,
                ];
                if (in_array($mode, ['full_json_object', 'compact_json_object'], true)) {
                    $request_body['response_format'] = ['type' => 'json_object'];
                }
            }

            $trace_started_at = microtime(true);
            if ($this->openai_cli_trace_enabled()) {
                $this->openai_cli_trace_line(sprintf(
                    'API #%d START call=%d stage=%s attempt=%d/%d mode=%s protocol=%s endpoint=%s fields=%d chars=%d timeout=%ds model=%s response_format=%s system_chars=%d system_sha256=%s user_chars=%d',
                    $trace_id,
                    $trace_call_id,
                    $trace_stage,
                    $request_count,
                    $max_request_attempts,
                    $mode,
                    $is_responses_mode ? 'responses' : 'chat_completions',
                    $this->openai_safe_endpoint_for_trace($request_endpoint),
                    count($fields),
                    $input_chars,
                    $request_timeout,
                    $model,
                    isset($request_body['response_format']) ? 'json_object' : 'none',
                    $this->wpmu_ml_strlen($request_system_prompt),
                    substr(hash('sha256', $request_system_prompt), 0, 16),
                    $this->wpmu_ml_strlen($request_user_prompt)
                ));
            }

            $response = wp_remote_post($request_endpoint, [
                'timeout' => $request_timeout,
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode($request_body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $elapsed = max(0, microtime(true) - $trace_started_at);
            $this->openai_performance_record_request($trace_stage, $elapsed);
            if (is_wp_error($response)) {
                $last_failure = 'wp_error:' . $response->get_error_message();
                $this->openai_performance_mark_failure();
                if ($this->openai_cli_trace_enabled()) {
                    $this->openai_cli_trace_line(sprintf(
                        'API #%d END call=%d stage=%s elapsed=%.3fs mode=%s result=wp_error error=%s',
                        $trace_id,
                        $trace_call_id,
                        $trace_stage,
                        $elapsed,
                        $mode,
                        $response->get_error_message()
                    ));
                }
                if ($request_count < $max_request_attempts && $this->openai_should_retry_request_error($response)) {
                    $this->openai_sleep_before_retry($request_count, null, 'wp_error', $last_failure);
                    continue;
                }
                return new WP_Error('wpmu_ml_openai_request_failed', '请求失败：' . $response->get_error_message() . '（请求 ' . $request_count . '/' . $max_request_attempts . '）');
            }

            $code = (int)wp_remote_retrieve_response_code($response);
            $raw_body = (string)wp_remote_retrieve_body($response);
            $outer = $this->openai_decode_json_object_from_mixed_text($raw_body, [], true);
            $content = $this->openai_extract_response_content($outer, $field_keys);
            $diagnostics = $this->openai_response_diagnostics($outer, $raw_body);
            $last_diagnostics = $diagnostics;
            $last_content = is_string($content) ? $content : '';
            $inner = is_string($content) && trim($content) !== ''
                ? $this->openai_decode_json_object_from_mixed_text($content, $field_keys, false)
                : null;
            $returned_keys = is_array($inner) ? array_map('strval', array_keys($inner)) : [];
            $missing_keys = array_diff($field_keys, $returned_keys);

            if ($this->openai_cli_trace_enabled()) {
                $this->openai_cli_trace_line(sprintf(
                    'API #%d END call=%d stage=%s elapsed=%.3fs mode=%s protocol=%s http=%d bytes=%d outer_json=%s inner_json=%s keys=%d/%d missing=%d finish=%s',
                    $trace_id,
                    $trace_call_id,
                    $trace_stage,
                    $elapsed,
                    $mode,
                    $diagnostics['protocol'],
                    $code,
                    strlen($raw_body),
                    is_array($outer) ? 'yes' : 'no',
                    is_array($inner) ? 'yes' : 'no',
                    count($returned_keys),
                    count($field_keys),
                    count($missing_keys),
                    $diagnostics['finish_reason'] !== '' ? $diagnostics['finish_reason'] : '-'
                ));
                $this->openai_cli_trace_line(sprintf(
                    'API RESPONSE_DIAG call=%d request=%d stage=%s mode=%s protocol=%s status=%s outer_keys=%s message_keys=%s content_present=%s content_type=%s content_chars=%d content=%s reasoning_chars=%d reasoning=%s refusal=%s error=%s tokens=%d/%d/%d',
                    $trace_call_id,
                    $trace_id,
                    $trace_stage,
                    $mode,
                    $diagnostics['protocol'],
                    $diagnostics['status'] !== '' ? $diagnostics['status'] : '-',
                    $diagnostics['outer_keys'],
                    $diagnostics['message_keys'],
                    $diagnostics['content_key_present'] ? 'yes' : 'no',
                    $diagnostics['content_type'],
                    $this->wpmu_ml_strlen($diagnostics['content_text']),
                    $this->openai_cli_trace_snippet($diagnostics['content_text'], 1200),
                    $this->wpmu_ml_strlen($diagnostics['reasoning_text']),
                    $this->openai_cli_trace_snippet($diagnostics['reasoning_text'], 600),
                    $this->openai_cli_trace_snippet($diagnostics['refusal_text'], 600),
                    $this->openai_cli_trace_snippet($diagnostics['error_text'], 600),
                    $diagnostics['prompt_tokens'],
                    $diagnostics['completion_tokens'],
                    $diagnostics['total_tokens']
                ));
            }

            if ($code < 200 || $code >= 300) {
                $last_failure = 'http_' . $code;
                $this->openai_performance_mark_failure();
                if (in_array($mode, ['full_json_object', 'compact_json_object'], true) && $code === 400 && isset($modes[$mode_index + 1])) {
                    if ($this->openai_cli_trace_enabled()) {
                        $this->openai_cli_trace_line('API FALLBACK call=' . $trace_call_id . ' reason=http_400_json_mode next_mode=' . $modes[$mode_index + 1]);
                    }
                    $mode_index++;
                    $this->openai_performance_mark_fallback();
                    continue;
                }
                if ($is_responses_mode && in_array($code, [400, 404, 405], true)) {
                    $last_failure = 'responses_api_unsupported';
                    break;
                }
                if ($request_count < $max_request_attempts && $this->openai_should_retry_http_error($code, $raw_body)) {
                    $this->openai_sleep_before_retry($request_count, $response, 'http_' . $code, $raw_body);
                    continue;
                }
                return new WP_Error('wpmu_ml_openai_http_error', '接口返回 HTTP ' . $code . '：' . wp_trim_words(wp_strip_all_tags($raw_body), 40) . '（请求 ' . $request_count . '/' . $max_request_attempts . '）');
            }

            if (is_array($inner)) {
                $normalized = $this->normalize_openai_translated_value($inner);
                $filtered = $this->openai_filter_requested_response_fields($normalized, $field_keys, $trace_call_id, count($field_keys) === 1);
                $filtered_keys = array_map('strval', array_keys($filtered));
                $filtered_missing = array_values(array_diff($field_keys, $filtered_keys));
                if (!$filtered_missing || empty($settings['openai_internal_require_all_keys'])) {
                    return $this->openai_restore_empty_request_fields($filtered, $original_fields, $empty_fields);
                }

                $last_failure = 'incomplete_json';
                $this->openai_performance_mark_failure();
                if ($this->openai_cli_trace_enabled()) {
                    $this->openai_cli_trace_line(sprintf(
                        'API INCOMPLETE_JSON call=%d request=%d stage=%s mode=%s missing=%s returned=%s',
                        $trace_call_id,
                        $trace_id,
                        $trace_stage,
                        $mode,
                        implode(',', $filtered_missing),
                        implode(',', $filtered_keys)
                    ));
                }
                if (isset($modes[$mode_index + 1]) && $request_count < $max_request_attempts) {
                    $mode_index++;
                    $this->openai_performance_mark_fallback();
                    if ($this->openai_cli_trace_enabled()) {
                        $this->openai_cli_trace_line('API FALLBACK call=' . $trace_call_id . ' reason=incomplete_json next_mode=' . $modes[$mode_index]);
                    }
                    continue;
                }
                break;
            }

            if (count($field_keys) === 1 && is_string($content) && trim($content) !== '') {
                $only_key = (string)$field_keys[0];
                if ($status_type !== 'translation') {
                    $status = $this->openai_parse_single_status_fallback($content, $status_type);
                    if ($status !== '') {
                        if ($this->openai_cli_trace_enabled()) {
                            $this->openai_cli_trace_line(sprintf('API PLAIN STATUS RECOVERY call=%d key=%s status=%s mode=%s', $trace_call_id, $only_key, $status, $mode));
                        }
                        return $this->openai_restore_empty_request_fields([$only_key => $status], $original_fields, $empty_fields);
                    }
                } else {
                    $plain = $this->openai_normalize_plain_translation_fallback($content);
                    if ($plain !== '') {
                        if ($this->openai_cli_trace_enabled()) {
                            $this->openai_cli_trace_line(sprintf(
                                'API PLAIN TRANSLATION RECOVERY call=%d key=%s mode=%s chars=%d returned=%s',
                                $trace_call_id,
                                $only_key,
                                $mode,
                                $this->wpmu_ml_strlen($plain),
                                $this->openai_cli_trace_snippet($plain, 500)
                            ));
                        }
                        $normalized = $this->normalize_openai_translated_value([$only_key => $plain]);
                        return $this->openai_restore_empty_request_fields($normalized, $original_fields, $empty_fields);
                    }
                }
            }

            if (!is_array($outer)) {
                $last_failure = 'outer_json_invalid';
            } elseif ($diagnostics['error_text'] !== '') {
                $last_failure = 'embedded_error';
            } elseif ($diagnostics['refusal_text'] !== '') {
                $last_failure = 'model_refusal';
            } elseif ((!is_string($content) || trim($content) === '')
                && $diagnostics['completion_tokens'] > 0
                && !$diagnostics['content_key_present']) {
                $last_failure = 'upstream_output_missing';
            } elseif (!is_string($content) || trim($content) === '') {
                $last_failure = 'empty_content';
            } else {
                $last_failure = 'invalid_inner_json';
            }

            $this->openai_performance_mark_failure();
            if ($this->openai_cli_trace_enabled()) {
                $this->openai_cli_trace_line(sprintf(
                    'API SEMANTIC_FAILURE call=%d request=%d stage=%s mode=%s reason=%s source_keys=%s',
                    $trace_call_id,
                    $trace_id,
                    $trace_stage,
                    $mode,
                    $last_failure,
                    implode(',', $field_keys)
                ));
                if ($last_failure === 'upstream_output_missing') {
                    $this->openai_cli_trace_line(sprintf(
                        'API UPSTREAM_OUTPUT_MISSING call=%d request=%d completion_tokens=%d finish=%s message_keys=%s next_action=%s',
                        $trace_call_id,
                        $trace_id,
                        $diagnostics['completion_tokens'],
                        $diagnostics['finish_reason'] !== '' ? $diagnostics['finish_reason'] : '-',
                        $diagnostics['message_keys'],
                        isset($modes[$mode_index + 1]) ? $modes[$mode_index + 1] : 'fail'
                    ));
                }
                $this->openai_cli_trace_line('API RAW_BASE64 call=' . $trace_call_id . ' request=' . $trace_id . ' stage=' . $trace_stage . ' body=' . base64_encode($raw_body));
            }

            if (isset($modes[$mode_index + 1]) && $request_count < $max_request_attempts) {
                $mode_index++;
                $this->openai_performance_mark_fallback();
                if ($this->openai_cli_trace_enabled()) {
                    $this->openai_cli_trace_line('API FALLBACK call=' . $trace_call_id . ' reason=' . $last_failure . ' next_mode=' . $modes[$mode_index]);
                }
                continue;
            }
            break;
        }

        $can_split_directly = $status_type === 'translation'
            && count($field_keys) > 1
            && count($field_keys) <= 4
            && empty($settings['openai_internal_no_field_split'])
            && in_array($last_failure, ['empty_content', 'upstream_output_missing', 'invalid_inner_json', 'incomplete_json'], true);
        if ($can_split_directly) {
            $this->openai_cli_trace_line(sprintf(
                'API DIRECT FIELD SPLIT call=%d stage=%s fields=%d reason=%s',
                $trace_call_id,
                $trace_stage,
                count($field_keys),
                $last_failure
            ));
            $split_settings = $settings;
            $split_settings['openai_internal_no_field_split'] = 1;
            $split_result = [];
            foreach ($fields as $field_key => $field_value) {
                $single = $this->openai_translate_json_fields(
                    [(string)$field_key => $field_value],
                    $target_label,
                    $split_settings,
                    $task_instruction
                );
                if (is_wp_error($single)) {
                    return $single;
                }
                $split_result = array_merge($split_result, $single);
            }
            return $this->openai_restore_empty_request_fields($split_result, $original_fields, $empty_fields);
        }

        $field_detail = '';
        if (count($field_keys) === 1) {
            $only_key = (string)$field_keys[0];
            $field_detail = '，字段=' . $only_key . '，源文=' . $this->openai_cli_trace_snippet((string)($fields[$only_key] ?? ''), 240);
        }
        $finish = (string)($last_diagnostics['finish_reason'] ?? '');
        $refusal = (string)($last_diagnostics['refusal_text'] ?? '');
        $error_text = (string)($last_diagnostics['error_text'] ?? '');
        $diagnostic_detail = 'stage=' . $trace_stage
            . '，reason=' . $last_failure
            . '，protocol=' . (string)($last_diagnostics['protocol'] ?? '-')
            . '，finish=' . ($finish !== '' ? $finish : '-')
            . '，content_present=' . (!empty($last_diagnostics['content_key_present']) ? 'yes' : 'no')
            . '，content_chars=' . $this->wpmu_ml_strlen((string)($last_diagnostics['content_text'] ?? ''))
            . '，tokens=' . (int)($last_diagnostics['prompt_tokens'] ?? 0)
            . '/' . (int)($last_diagnostics['completion_tokens'] ?? 0)
            . '/' . (int)($last_diagnostics['total_tokens'] ?? 0);
        if ($refusal !== '') {
            $diagnostic_detail .= '，refusal=' . $this->openai_cli_trace_snippet($refusal, 240);
        }
        if ($error_text !== '') {
            $diagnostic_detail .= '，error=' . $this->openai_cli_trace_snippet($error_text, 240);
        }
        if ($last_failure === 'incomplete_json') {
            return new WP_Error('wpmu_ml_openai_incomplete_json', '接口返回的 JSON 缺少一个或多个请求字段，已拒绝接受不完整结果。' . $field_detail . '；' . $diagnostic_detail);
        }
        if (is_string($last_content) && trim($last_content) !== '') {
            return new WP_Error('wpmu_ml_openai_bad_json', '接口正文不是可用的完整 JSON。' . $field_detail . '；' . $diagnostic_detail);
        }
        if ($last_failure === 'upstream_output_missing') {
            return new WP_Error('wpmu_ml_openai_upstream_output_missing', '上游接口消耗了输出 Token，但没有返回可见模型正文。' . $field_detail . '；' . $diagnostic_detail);
        }
        return new WP_Error('wpmu_ml_openai_empty', '接口返回成功外壳，但没有可用模型正文。' . $field_detail . '；' . $diagnostic_detail);
    }

    private function openai_filter_requested_response_fields($normalized, $requested_keys, $trace_call_id = 0, $allow_single_alias = false) {
        $normalized = is_array($normalized) ? $normalized : [];
        $requested_keys = array_values(array_map('strval', (array)$requested_keys));
        $requested_lookup = array_fill_keys($requested_keys, true);
        $filtered = [];
        $dropped = [];

        if ($allow_single_alias && count($requested_keys) === 1 && count($normalized) === 1) {
            $returned_key = (string)array_key_first($normalized);
            $requested_key = (string)$requested_keys[0];
            $alias_keys = ['text', 'translation', 'translated_text', 'result', 'output', 'content', 'value'];
            if ($returned_key !== $requested_key
                && in_array(strtolower($returned_key), $alias_keys, true)
                && (is_scalar($normalized[$returned_key]) || $normalized[$returned_key] === null)) {
                $value = (string)$normalized[$returned_key];
                if (trim($value) !== '') {
                    if ($this->openai_cli_trace_enabled()) {
                        $this->openai_cli_trace_line(sprintf(
                            'API SINGLE FIELD ALIAS RECOVERY call=%d requested=%s returned=%s chars=%d',
                            (int)$trace_call_id,
                            $requested_key,
                            $returned_key,
                            $this->wpmu_ml_strlen($value)
                        ));
                    }
                    return [$requested_key => $value];
                }
            }
        }

        foreach ($normalized as $key => $value) {
            $key = (string)$key;
            if (!isset($requested_lookup[$key])) {
                $dropped[] = $key;
                continue;
            }
            $filtered[$key] = $value;
        }

        if ($dropped && $this->openai_cli_trace_enabled()) {
            $this->openai_cli_trace_line(sprintf(
                'API RESPONSE FIELD FILTER call=%d requested=%s dropped=%s',
                (int)$trace_call_id,
                implode(',', $requested_keys),
                implode(',', $dropped)
            ));
        }

        // Preserve request order. This also prevents an unexpected model field from
        // being written into a source field that was intentionally empty or omitted.
        $ordered = [];
        foreach ($requested_keys as $key) {
            if (array_key_exists($key, $filtered)) {
                $ordered[$key] = $filtered[$key];
            }
        }
        return $ordered;
    }

    private function openai_should_retry_request_error($error) {
        if (!is_wp_error($error)) {
            return false;
        }
        $message = strtolower((string)$error->get_error_message());
        if ($message === '') {
            return true;
        }
        foreach (['timeout', 'timed out', 'connection', 'connect', 'curl', 'reset', 'temporarily', 'temporary', 'dns', 'ssl', 'upstream'] as $needle) {
            if (strpos($message, strtolower($needle)) !== false) {
                return true;
            }
        }
        return true;
    }

    private function openai_should_retry_http_error($code, $raw_body) {
        $code = (int)$code;
        if (in_array($code, [408, 409, 425, 429, 500, 502, 503, 504, 520, 521, 522, 523, 524], true)) {
            return true;
        }
        $body = strtolower((string)$raw_body);
        foreach (['do_request_failed', 'model_not_found', 'no available channel', '无可用渠道', 'upstream error', 'openai_error', 'bad_response_status_code', 'rate limit', 'temporarily'] as $needle) {
            if (strpos($body, strtolower($needle)) !== false) {
                return true;
            }
        }
        return false;
    }

    private function openai_retry_after_seconds($response) {
        if (!$response || is_wp_error($response)) {
            return 0;
        }
        $retry_after = wp_remote_retrieve_header($response, 'retry-after');
        if (is_array($retry_after)) {
            $retry_after = reset($retry_after);
        }
        $retry_after = trim((string)$retry_after);
        if ($retry_after === '') {
            return 0;
        }
        if (is_numeric($retry_after)) {
            return max(1, min(120, (int)ceil((float)$retry_after)));
        }
        $ts = strtotime($retry_after);
        if ($ts) {
            return max(1, min(120, $ts - time()));
        }
        return 0;
    }

    private function openai_sleep_before_retry($attempt, $response = null, $reason = '', $message = '') {
        $retry_after = $this->openai_retry_after_seconds($response);
        if ($retry_after > 0) {
            $sleep = $retry_after;
        } else {
            $base = [2, 5, 10, 20, 35, 60];
            $idx = max(0, min(count($base) - 1, ((int)$attempt) - 1));
            $sleep = $base[$idx] + random_int(0, 3);
        }
        $this->log('warning', 'openai_request_retry', 'OpenAI/NewAPI 请求临时失败，等待后重试', [
            'attempt' => (int)$attempt,
            'sleep_seconds' => (int)$sleep,
            'reason' => (string)$reason,
            'message' => wp_trim_words(wp_strip_all_tags((string)$message), 30),
        ]);
        sleep(max(1, (int)$sleep));
    }

    private function split_text_for_translation_chunks($text, $max_chars) {
        $text = (string)$text;
        if ($text === '') {
            return [''];
        }

        $max_chars = max(1000, absint($max_chars));
        if ($this->wpmu_ml_strlen($text) <= $max_chars) {
            return [$text];
        }

        $parts = preg_split('/(\n\s*\n+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!$parts || !is_array($parts)) {
            return $this->split_text_by_length($text, $max_chars);
        }

        $chunks = [];
        $current = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            if ($current !== '' && $this->wpmu_ml_strlen($current . $part) > $max_chars) {
                $chunks[] = $current;
                $current = '';
            }

            if ($this->wpmu_ml_strlen($part) > $max_chars) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                foreach ($this->split_text_by_length($part, $max_chars) as $sub) {
                    $chunks[] = $sub;
                }
                continue;
            }

            $current .= $part;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks ?: [$text];
    }

    private function split_text_by_length($text, $max_chars) {
        $chunks = [];
        $text = (string)$text;
        $len = $this->wpmu_ml_strlen($text);
        $max_chars = max(1000, absint($max_chars));
        for ($offset = 0; $offset < $len; $offset += $max_chars) {
            $chunks[] = $this->wpmu_ml_substr($text, $offset, $max_chars);
        }
        return $chunks ?: [''];
    }

    private function wpmu_ml_strlen($text) {
        return function_exists('mb_strlen') ? mb_strlen((string)$text, 'UTF-8') : strlen((string)$text);
    }

    private function wpmu_ml_substr($text, $start, $length = null) {
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr((string)$text, $start, null, 'UTF-8') : mb_substr((string)$text, $start, $length, 'UTF-8');
        }
        return $length === null ? substr((string)$text, $start) : substr((string)$text, $start, $length);
    }
    }
}
