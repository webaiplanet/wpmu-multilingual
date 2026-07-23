<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * OpenAI 文本片段、元数据与字段感知翻译。
 *
 * 本 trait 仅用于拆分历史 WPMU_Multilingual 大类；方法签名和可见性保持不变。
 */
if (!trait_exists('WPMU_ML_Core_OpenAI_Metadata_Trait')) {
    trait WPMU_ML_Core_OpenAI_Metadata_Trait {
    private function openai_fast_quality_enabled($settings) {
        return !array_key_exists('openai_fast_quality_pipeline', (array)$settings)
            || !empty($settings['openai_fast_quality_pipeline']);
    }

    private function openai_centralized_quality_enabled($settings) {
        return !array_key_exists('openai_centralized_quality_pipeline', (array)$settings)
            || !empty($settings['openai_centralized_quality_pipeline']);
    }

    private function openai_central_translation_batch_fields($settings) {
        return max(10, min(200, absint($settings['openai_central_translation_batch_fields'] ?? 120)));
    }

    private function openai_translate_plain_text($text, $target_label, $settings, $task_instruction = '') {
        $source = (string)$text;
        $instruction = $task_instruction ?: 'Translate the following plain text. Return ONLY valid JSON with key: text.';
        $centralized = $this->openai_centralized_quality_enabled($settings);
        $translated = $this->openai_translate_json_fields(
            ['text' => $source],
            $target_label,
            $settings,
            $instruction
        );
        if (is_wp_error($translated)) {
            return $translated;
        }
        $candidate = isset($translated['text']) ? (string)$translated['text'] : '';
        $audit = [];
        if ($centralized) {
            $this->openai_cli_trace_line('CENTRAL QUALITY DEFER scope=plain_text duplicate_language_ai_audit=skipped deterministic_and_final_checks=active');
        } else {
            $audit = $this->openai_audit_target_language_fields(
                ['text' => $source],
                ['text' => $candidate],
                $target_label,
                $settings
            );
            if (is_wp_error($audit)) {
                $this->openai_cli_trace_line('TARGET LANGUAGE QA status=unavailable scope=plain_text reason=' . $audit->get_error_message());
                if (!empty($settings['openai_agent_fail_on_qa'])) {
                    return $audit;
                }
                $audit = [];
            }
        }
        $reason = !empty($audit['text'])
            ? 'wrong_target_language:' . $audit['text']
            : $this->openai_translation_fragment_rejection_reason($source, $candidate, $settings, $target_label);
        if ($candidate !== '' && $reason === '') {
            return $candidate;
        }

        // Centralized mode keeps one recovery attempt for a genuinely empty or structurally
        // invalid translation, but does not add a second AI language-identification request.
        $max_passes = $centralized ? 1 : 2;
        for ($pass = 1; $pass <= $max_passes; $pass++) {
            $this->openai_cli_trace_line(sprintf(
                'PLAIN TEXT RETRY pass=%d reason=%s centralized=%s',
                $pass,
                $reason !== '' ? $reason : 'empty',
                $centralized ? 'yes' : 'no'
            ));
            $retry = $this->openai_translate_json_fields(
                ['text' => $source],
                $target_label,
                $settings,
                $instruction . ' Recovery request: return complete text only in the configured target language. Never switch to another natural language. Preserve facts, brands, numbers and protected technical tokens.'
            );
            if (is_wp_error($retry)) {
                return $retry;
            }
            $candidate = isset($retry['text']) ? (string)$retry['text'] : '';
            $retry_audit = [];
            if (!$centralized) {
                $retry_audit = $this->openai_audit_target_language_fields(
                    ['text' => $source],
                    ['text' => $candidate],
                    $target_label,
                    $settings
                );
                if (is_wp_error($retry_audit)) {
                    return $retry_audit;
                }
            }
            $reason = !empty($retry_audit['text'])
                ? 'wrong_target_language:' . $retry_audit['text']
                : $this->openai_translation_fragment_rejection_reason($source, $candidate, $settings, $target_label);
            if ($candidate !== '' && $reason === '') {
                return $candidate;
            }
        }

        return new WP_Error(
            'wpmu_ml_wrong_target_language',
            '纯文本翻译结果缺失、语言跑偏或保持源文，已停止保存。原因：' . ($reason !== '' ? $reason : 'empty')
        );
    }

    private function openai_request_fields_with_recursive_split($fields, $target_label, $settings, $instruction, $depth = 0, $trace_scope = 'BATCH') {
        $fields = is_array($fields) ? $fields : [];
        if (!$fields) {
            return [];
        }

        $request_settings = $settings;
        $request_settings['openai_internal_require_all_keys'] = 1;
        $request_settings['openai_internal_no_field_split'] = 1;

        $scope = strtoupper((string)$trace_scope);
        $fast_quality = $this->openai_fast_quality_enabled($settings);
        $qa_limit = max(1, min(20, absint($settings['openai_qa_batch_fields'] ?? 3)));
        $total_chars = 0;
        foreach ($fields as $value) {
            $total_chars += $this->wpmu_ml_strlen((string)$value);
        }
        $proactive_limit = 0;
        if ($fast_quality && in_array($scope, ['LANGUAGE AUDIT', 'ARTICLE EDITOR', 'ARTICLE REPAIR'], true)) {
            $proactive_limit = $qa_limit;
        } elseif ($fast_quality && $scope === 'TITLE EDITOR' && count($fields) > 1 && $total_chars > 800) {
            $proactive_limit = 1;
        }
        if ($depth === 0 && $proactive_limit > 0 && count($fields) > $proactive_limit) {
            $chunks = array_chunk($fields, $proactive_limit, true);
            $this->openai_cli_trace_line(sprintf(
                '%s PRECHUNK fields=%d chunks=%d batch_fields=%d chars=%d mode=fast_quality',
                $scope,
                count($fields),
                count($chunks),
                $proactive_limit,
                $total_chars
            ));
            $merged = [];
            foreach ($chunks as $chunk_index => $chunk) {
                $chunk_instruction = $instruction
                    . ' Proactive bounded batch ' . ((int)$chunk_index + 1) . '/' . count($chunks)
                    . ': return every supplied key exactly once; do not omit, merge, invent or rename keys.';
                $chunk_result = $this->openai_request_fields_with_recursive_split(
                    $chunk,
                    $target_label,
                    $request_settings,
                    $chunk_instruction,
                    1,
                    $trace_scope
                );
                if (is_wp_error($chunk_result)) {
                    return $chunk_result;
                }
                $merged = array_merge($merged, $chunk_result);
            }
            return $merged;
        }

        $translated = $this->openai_translate_json_fields($fields, $target_label, $request_settings, $instruction);
        if (!is_wp_error($translated)) {
            return $translated;
        }

        $error_code = (string)$translated->get_error_code();
        $splittable_codes = [
            'wpmu_ml_openai_empty',
            'wpmu_ml_openai_bad_json',
            'wpmu_ml_openai_incomplete_json',
            'wpmu_ml_openai_upstream_output_missing',
        ];
        if (count($fields) <= 1 || $depth >= 10 || !in_array($error_code, $splittable_codes, true)) {
            foreach ($fields as $key => $value) {
                $this->openai_cli_trace_line(sprintf(
                    '%s LEAF FAILED depth=%d key=%s chars=%d source=%s error_code=%s error=%s',
                    strtoupper((string)$trace_scope),
                    (int)$depth,
                    (string)$key,
                    $this->wpmu_ml_strlen((string)$value),
                    $this->openai_cli_trace_snippet((string)$value, 500),
                    $error_code !== '' ? $error_code : '-',
                    $translated->get_error_message()
                ));
            }
            return $translated;
        }

        $left_size = (int)ceil(count($fields) / 2);
        $chunks = array_chunk($fields, max(1, $left_size), true);
        $this->openai_cli_trace_line(sprintf(
            '%s SPLIT depth=%d fields=%d chunks=%s error_code=%s reason=%s',
            strtoupper((string)$trace_scope),
            (int)$depth,
            count($fields),
            implode('+', array_map('count', $chunks)),
            $error_code !== '' ? $error_code : '-',
            $translated->get_error_message()
        ));

        $merged = [];
        foreach ($chunks as $chunk_index => $chunk) {
            $chunk_instruction = $instruction
                . ' Split-recovery request ' . ((int)$chunk_index + 1) . '/' . count($chunks)
                . ': return every supplied key exactly once; do not omit, merge, invent or rename keys.';
            $chunk_result = $this->openai_request_fields_with_recursive_split(
                $chunk,
                $target_label,
                $request_settings,
                $chunk_instruction,
                $depth + 1,
                $trace_scope
            );
            if (is_wp_error($chunk_result)) {
                return $chunk_result;
            }
            $merged = array_merge($merged, $chunk_result);
        }
        return $merged;
    }

    private function openai_translate_fragment_field_batch($fields, $target_label, $settings, $instruction, $depth = 0) {
        return $this->openai_request_fields_with_recursive_split(
            $fields,
            $target_label,
            $settings,
            $instruction,
            $depth,
            'BATCH'
        );
    }

    private function openai_translate_fragment_map($fragments, $target_label, $settings, $task_instruction = '') {
        $max_chars = absint($settings['openai_max_chars'] ?? 8000);
        $max_fields = absint($settings['openai_fragment_batch_fields'] ?? 30);
        if ($this->openai_centralized_quality_enabled($settings) && empty($settings['openai_internal_preserve_fragment_limits'])) {
            $central_fields = $this->openai_central_translation_batch_fields($settings);
            if ($max_fields === 0 || $max_fields < $central_fields) {
                $max_fields = $central_fields;
            }
        }
        $recovery_batch_fields = $max_fields > 0
            ? max(5, min(20, (int)ceil($max_fields / 2)))
            : 15;
        $result = [];
        $batch = [];
        $batch_chars = 0;

        $flush = function() use (&$batch, &$batch_chars, &$result, $target_label, $settings, $task_instruction, $recovery_batch_fields) {
            if (!$batch) {
                return true;
            }

            $fields = [];
            $key_to_index = [];
            $n = 0;
            foreach ($batch as $idx => $txt) {
                $key = 't' . $n++;
                $fields[$key] = (string)$txt;
                $key_to_index[$key] = $idx;
            }

            $instruction = $task_instruction ?: 'Translate the following text fragments independently. Return ONLY valid JSON with the exact same keys. Preserve whitespace and punctuation where appropriate.';
            $translated = $this->openai_translate_fragment_field_batch(
                $fields,
                $target_label,
                $settings,
                $instruction,
                0
            );
            if (is_wp_error($translated)) {
                return $translated;
            }
            $defer_language_audit = $this->openai_fast_quality_enabled($settings)
                && !empty($settings['openai_editorial_review_enabled']);
            if ($defer_language_audit) {
                $language_audit = [];
                $this->openai_cli_trace_line(sprintf(
                    'FAST QUALITY PIPELINE stage=translation_batch fields=%d language_ai_audit=deferred_to_article_editor',
                    count($fields)
                ));
            } else {
                $language_audit = $this->openai_audit_target_language_fields($fields, $translated, $target_label, $settings);
                if (is_wp_error($language_audit)) {
                    return $language_audit;
                }
            }

            $resolved = [];
            $missing = [];
            $last_candidates = [];
            $last_rejection_reasons = [];
            $unchanged = 0;
            foreach ($fields as $key => $source_value) {
                $candidate = array_key_exists($key, $translated) ? $this->openai_normalize_translation_control_placeholders((string)$translated[$key]) : '';
                $rejection_reason = !empty($language_audit[$key])
                    ? 'wrong_target_language:' . $language_audit[$key]
                    : $this->openai_translation_fragment_rejection_reason((string)$source_value, $candidate, $settings, $target_label);
                if ($candidate !== '' && $rejection_reason === '') {
                    $resolved[$key] = $candidate;
                } else {
                    $missing[$key] = (string)$source_value;
                    $last_candidates[$key] = $candidate;
                    $last_rejection_reasons[$key] = $rejection_reason !== '' ? $rejection_reason : 'empty';
                    if ($candidate !== '') {
                        $unchanged++;
                        $this->openai_cli_trace_line(sprintf(
                            'TRANSLATION REJECT key=%s reason=%s source=%s returned=%s',
                            $key,
                            $rejection_reason !== '' ? $rejection_reason : 'empty',
                            $this->openai_cli_trace_snippet((string)$source_value),
                            $this->openai_cli_trace_snippet($candidate)
                        ));
                    }
                }
            }
            if ($unchanged > 0) {
                $this->openai_cli_trace_line(sprintf(
                    'TRANSLATION fragment recovery rejected=%d total=%d',
                    $unchanged,
                    count($fields)
                ));
            }

            // Compatible models may return valid JSON but silently omit keys or damage
            // placeholders. Recover only failed keys. Placeholder-heavy fields are retried one
            // at a time because the complete article context was already supplied in the main request.
            for ($pass = 1; $missing && $pass <= 2; $pass++) {
                $next_missing = [];
                $effective_recovery_batch_fields = $recovery_batch_fields;
                foreach ($missing as $missing_source_value) {
                    if (strpos((string)$missing_source_value, '<wpmu-ml-') !== false || strpos((string)$missing_source_value, '__WPMU_ML_ATOMIC_') !== false) {
                        // Placeholder-sensitive recovery favors correctness over throughput. The
                        // complete article was already supplied in the main request; only failed
                        // fields are retried individually so compatible models do not omit keys
                        // or detach markup while repairing several complex values at once.
                        $effective_recovery_batch_fields = 1;
                        break;
                    }
                }
                $this->openai_cli_trace_line(sprintf(
                    'PARTIAL JSON recovery pass=%d missing=%d total=%d batch_fields=%d',
                    $pass,
                    count($missing),
                    count($fields),
                    $effective_recovery_batch_fields
                ));
                foreach (array_chunk($missing, $effective_recovery_batch_fields, true) as $retry_fields) {
                    $retry_instruction = $instruction . ' Recovery request: the previous response omitted a field or damaged its placeholders. Return every input key exactly once. Do not omit, merge, rename or reorder keys. Preserve every <wpmu-ml-N>...</wpmu-ml-N> wrapper as a balanced pair around its translated phrase and preserve every __WPMU_ML_ATOMIC_N__ token exactly once.';
                    $retry = $this->openai_translate_json_fields($retry_fields, $target_label, $settings, $retry_instruction);
                    if (is_wp_error($retry)) {
                        return $retry;
                    }
                    if ($defer_language_audit) {
                        $retry_language_audit = [];
                    } else {
                        $retry_language_audit = $this->openai_audit_target_language_fields($retry_fields, $retry, $target_label, $settings);
                        if (is_wp_error($retry_language_audit)) {
                            return $retry_language_audit;
                        }
                    }
                    foreach ($retry_fields as $key => $source_value) {
                        $candidate = array_key_exists($key, $retry) ? $this->openai_normalize_translation_control_placeholders((string)$retry[$key]) : '';
                        $rejection_reason = !empty($retry_language_audit[$key])
                            ? 'wrong_target_language:' . $retry_language_audit[$key]
                            : $this->openai_translation_fragment_rejection_reason((string)$source_value, $candidate, $settings, $target_label);
                        if ($candidate !== '' && $rejection_reason === '') {
                            $resolved[$key] = $candidate;
                        } else {
                            $next_missing[$key] = (string)$source_value;
                            $last_candidates[$key] = $candidate;
                            $last_rejection_reasons[$key] = $rejection_reason !== '' ? $rejection_reason : 'empty';
                            $this->openai_cli_trace_line(sprintf(
                                'TRANSLATION RETRY REJECT pass=%d key=%s reason=%s source=%s returned=%s',
                                $pass,
                                $key,
                                $rejection_reason !== '' ? $rejection_reason : 'empty',
                                $this->openai_cli_trace_snippet((string)$source_value),
                                $this->openai_cli_trace_snippet($candidate)
                            ));
                        }
                    }
                }
                $missing = $next_missing;
            }

            if ($missing) {
                $sample_keys = array_slice(array_keys($missing), 0, 3);
                $sample_parts = [];
                foreach ($sample_keys as $sample_key) {
                    $sample_parts[] = $sample_key
                        . ' 原因=' . (string)($last_rejection_reasons[$sample_key] ?? 'unknown')
                        . ' 源文=' . $this->openai_cli_trace_snippet((string)$missing[$sample_key])
                        . ' 返回=' . $this->openai_cli_trace_snippet((string)($last_candidates[$sample_key] ?? ''));
                }
                return new WP_Error(
                    'wpmu_ml_openai_incomplete_json',
                    '模型返回的翻译 JSON 仍有 ' . count($missing) . ' 个字段缺失、占位符损坏、语言跑偏或保持源文，已停止保存不完整译文。示例：' . implode(' | ', $sample_parts)
                );
            }

            foreach ($key_to_index as $key => $idx) {
                $result[$idx] = (string)$resolved[$key];
            }
            $batch = [];
            $batch_chars = 0;
            return true;
        };

        foreach ($fragments as $idx => $txt) {
            $len = $this->wpmu_ml_strlen((string)$txt);
            if ($batch && ((($max_chars > 0) && (($batch_chars + $len) > $max_chars)) || (($max_fields > 0) && count($batch) >= $max_fields))) {
                $ok = $flush();
                if (is_wp_error($ok)) {
                    return $ok;
                }
            }
            if ($max_chars > 0 && $len > $max_chars) {
                $chunks = $this->split_text_for_translation_chunks((string)$txt, $max_chars);
                $translated_chunks = [];
                foreach ($chunks as $chunk) {
                    if (!$this->openai_contains_translatable_source_text($chunk)) {
                        $translated_chunks[] = $chunk;
                        continue;
                    }
                    $t = $this->openai_translate_plain_text($chunk, $target_label, $settings, $task_instruction);
                    if (is_wp_error($t)) {
                        return $t;
                    }
                    $translated_chunks[] = (string)$t;
                }
                $result[$idx] = implode('', $translated_chunks);
                continue;
            }
            $batch[$idx] = (string)$txt;
            $batch_chars += $len;
        }
        $ok = $flush();
        if (is_wp_error($ok)) {
            return $ok;
        }
        return $result;
    }

    private function openai_collect_meta_quality_pairs($source, $target, $base_key, $field_key, &$source_fields, &$target_fields, &$roles, &$locators, $path = []) {
        if (is_array($source) && is_array($target)) {
            foreach ($source as $key => $source_value) {
                if (!array_key_exists($key, $target)) {
                    continue;
                }
                if (is_string($key) && $this->openai_should_skip_data_key($key, [])) {
                    continue;
                }
                $this->openai_collect_meta_quality_pairs(
                    $source_value,
                    $target[$key],
                    $base_key,
                    is_string($key) && $key !== '' ? (string)$key : $field_key,
                    $source_fields,
                    $target_fields,
                    $roles,
                    $locators,
                    array_merge($path, [$key])
                );
            }
            return;
        }
        if (is_object($source) && is_object($target)) {
            foreach (get_object_vars($source) as $key => $source_value) {
                if (!property_exists($target, $key)) {
                    continue;
                }
                if ($this->openai_should_skip_data_key((string)$key, [])) {
                    continue;
                }
                $this->openai_collect_meta_quality_pairs(
                    $source_value,
                    $target->$key,
                    $base_key,
                    (string)$key !== '' ? (string)$key : $field_key,
                    $source_fields,
                    $target_fields,
                    $roles,
                    $locators,
                    array_merge($path, [$key])
                );
            }
            return;
        }
        if (!is_string($source) || !is_string($target)) {
            return;
        }

        $source_trim = trim($source);
        $target_trim = trim($target);
        if ($source_trim === '' || $target_trim === '' || !$this->openai_contains_translatable_source_text($source_trim)) {
            return;
        }

        $source_json = json_decode($source_trim, true);
        $target_json = json_decode($target_trim, true);
        if (is_array($source_json) && is_array($target_json)) {
            $json_source_fields = [];
            $json_target_fields = [];
            $json_roles = [];
            $json_locators = [];
            $this->openai_collect_meta_quality_pairs(
                $source_json,
                $target_json,
                $base_key . ':json',
                $field_key,
                $json_source_fields,
                $json_target_fields,
                $json_roles,
                $json_locators,
                []
            );
            foreach ($json_source_fields as $key => $value) {
                $new_key = $base_key . ':j' . count($source_fields);
                $source_fields[$new_key] = $value;
                $target_fields[$new_key] = $json_target_fields[$key];
                $roles[$new_key] = $json_roles[$key];
                $json_path = (array)($json_locators[$key]['path'] ?? []);
                $locators[$new_key] = [
                    'path' => $path,
                    'json_path' => $json_path,
                ];
            }
            return;
        }

        $qa_key = $base_key . ':f' . count($source_fields);
        $source_fields[$qa_key] = $source;
        $target_fields[$qa_key] = $target;
        $roles[$qa_key] = $this->openai_quality_role_from_type($this->openai_classify_agent_field_type($field_key));
        $locators[$qa_key] = ['path' => $path, 'json_path' => []];
    }

    private function openai_meta_quality_set_path(&$root, $path, $value) {
        $path = array_values((array)$path);
        if (!$path) {
            $root = $value;
            return true;
        }
        $ref =& $root;
        foreach ($path as $index => $part) {
            $last = $index === count($path) - 1;
            if (is_array($ref)) {
                if (!array_key_exists($part, $ref)) {
                    return false;
                }
                if ($last) {
                    $ref[$part] = $value;
                    return true;
                }
                $ref =& $ref[$part];
                continue;
            }
            if (is_object($ref)) {
                if (!property_exists($ref, (string)$part)) {
                    return false;
                }
                if ($last) {
                    $ref->$part = $value;
                    return true;
                }
                $ref =& $ref->$part;
                continue;
            }
            return false;
        }
        return false;
    }

    private function openai_meta_quality_apply_locator(&$root, $locator, $value) {
        $path = (array)($locator['path'] ?? []);
        $json_path = (array)($locator['json_path'] ?? []);
        if (!$json_path) {
            return $this->openai_meta_quality_set_path($root, $path, $value);
        }

        $container =& $root;
        foreach ($path as $part) {
            if (is_array($container) && array_key_exists($part, $container)) {
                $container =& $container[$part];
            } elseif (is_object($container) && property_exists($container, (string)$part)) {
                $container =& $container->$part;
            } else {
                return false;
            }
        }
        if (!is_string($container)) {
            return false;
        }
        $leading_len = strlen($container) - strlen(ltrim($container));
        $trailing_len = strlen($container) - strlen(rtrim($container));
        $leading = $leading_len > 0 ? substr($container, 0, $leading_len) : '';
        $trailing = $trailing_len > 0 ? substr($container, -$trailing_len) : '';
        $decoded = json_decode(trim($container), true);
        if (!is_array($decoded) || !$this->openai_meta_quality_set_path($decoded, $json_path, $value)) {
            return false;
        }
        $encoded = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            return false;
        }
        $container = $leading . $encoded . $trailing;
        return true;
    }


    /**
     * Collect plain human-readable leaves from arrays, objects and JSON strings without
     * translating them immediately. URLs, IDs, ACF references, machine values and data keys
     * continue to use the existing skip rules. Complex HTML/code leaves stay on the legacy
     * safe path, while ordinary ACF/Postmeta/Gutenberg text is translated in one bounded map.
     */
    private function openai_collect_batchable_data_translation_leaves($value, $field_key, $settings, &$fields, &$roles, &$locators, &$complex, $path = [], $json_path = [], $inside_json = false) {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key) && $this->openai_should_skip_data_key($key, $settings)) {
                    continue;
                }
                $next_key = is_string($key) && $key !== '' ? (string)$key : (string)$field_key;
                if ($inside_json) {
                    $this->openai_collect_batchable_data_translation_leaves($child, $next_key, $settings, $fields, $roles, $locators, $complex, $path, array_merge($json_path, [$key]), true);
                } else {
                    $this->openai_collect_batchable_data_translation_leaves($child, $next_key, $settings, $fields, $roles, $locators, $complex, array_merge($path, [$key]), [], false);
                }
            }
            return;
        }
        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $child) {
                if ($this->openai_should_skip_data_key((string)$key, $settings)) {
                    continue;
                }
                $next_key = (string)$key !== '' ? (string)$key : (string)$field_key;
                if ($inside_json) {
                    $this->openai_collect_batchable_data_translation_leaves($child, $next_key, $settings, $fields, $roles, $locators, $complex, $path, array_merge($json_path, [$key]), true);
                } else {
                    $this->openai_collect_batchable_data_translation_leaves($child, $next_key, $settings, $fields, $roles, $locators, $complex, array_merge($path, [$key]), [], false);
                }
            }
            return;
        }
        if (!is_string($value)) {
            return;
        }

        $source = $this->openai_strip_translation_artifacts((string)$value);
        $trim = trim($source);
        if ($trim === '' || !$this->openai_contains_translatable_source_text($trim)) {
            return;
        }

        // Decode JSON strings once and collect their actual text leaves. The locator keeps the
        // outer string path plus the inner JSON path so only values are rewritten, never keys.
        if (!$inside_json) {
            $first = substr($trim, 0, 1);
            $last = substr($trim, -1);
            if (($first === '{' && $last === '}') || ($first === '[' && $last === ']')) {
                $decoded = json_decode($trim, true);
                if (is_array($decoded)) {
                    $this->openai_collect_batchable_data_translation_leaves($decoded, $field_key, $settings, $fields, $roles, $locators, $complex, $path, [], true);
                    return;
                }
            }
        }

        $locator = [
            'path' => array_values((array)$path),
            'json_path' => $inside_json ? array_values((array)$json_path) : [],
            'field_key' => (string)$field_key,
            'source' => (string)$source,
        ];
        $is_encoded_html = strpos($source, '&lt;') !== false && strpos($source, '&gt;') !== false;
        $is_html = strpos($source, '<') !== false && strpos($source, '>') !== false;
        $is_code = $this->openai_data_string_looks_like_code($source, $field_key)
            && !$this->openai_data_string_looks_like_human_flow($source);
        if ($is_encoded_html || $is_html || $is_code) {
            $complex[] = $locator;
            return;
        }
        if (!$this->openai_should_translate_meta_string($source)) {
            return;
        }

        $key = 'd' . count($fields);
        $fields[$key] = (string)$source;
        $roles[$key] = $this->openai_quality_role_from_type($this->openai_classify_agent_field_type($field_key));
        $locators[$key] = $locator;
    }

    private function openai_translate_collected_data_leaves($fields, $roles, $target_label, $settings, $scope = 'postmeta') {
        $fields = is_array($fields) ? $fields : [];
        $roles = is_array($roles) ? $roles : [];
        if (!$fields) {
            return [];
        }
        $role_parts = [];
        foreach ($fields as $key => $unused) {
            $role_parts[] = (string)$key . '=' . (string)($roles[$key] ?? 'metadata');
        }
        $instruction = 'Translate these human-readable WordPress data-field values as one coherent batch. Values may come from ACF, Postmeta, Gutenberg or SEO fields. Translate only visible natural-language text. Preserve every JSON/HTML/template placeholder, URL, email, shortcode, machine token, brand, product name, number, amount, date, version and technical specification exactly. Apply the declared role: title/seo_title should be concise and search-natural; summary/seo_description should preserve all facts; heading and short_text should be concise; keyword_list must remain a keyword list; metadata/paragraph should remain complete and natural. FIELD ROLES: ' . implode(', ', $role_parts) . '. Return ONLY valid JSON with every exact key.';
        $total_chars = 0;
        foreach ($fields as $value) {
            $total_chars += $this->wpmu_ml_strlen((string)$value);
        }
        $this->openai_cli_trace_line(sprintf(
            'DATA TRANSLATION BATCH scope=%s fields=%d chars=%d batch_fields=%d',
            sanitize_key((string)$scope) ?: 'data',
            count($fields),
            $total_chars,
            $this->openai_central_translation_batch_fields($settings)
        ));
        return $this->openai_translate_fragment_map($fields, $target_label, $settings, $instruction);
    }

    private function openai_translate_target_post_meta_from_source($job, $target_label, $settings) {
        $translated_count = 0;
        $skipped_count = 0;
        switch_to_blog((int)$job['source_blog_id']);
        $source_meta = get_post_meta((int)$job['source_post_id']);
        restore_current_blog();

        if (!is_array($source_meta) || empty($source_meta)) {
            return ['translated' => 0, 'skipped' => 0, 'qa_fields' => 0, 'batch_fields' => 0, 'complex_fields' => 0];
        }

        $pending = [];
        $batch_fields = [];
        $batch_roles = [];
        $batch_locators = [];
        $complex = [];
        $allowed_meta_keys = $this->translation_job_meta_keys($job);

        foreach ($source_meta as $meta_key => $values) {
            if (is_array($allowed_meta_keys) && !in_array((string)$meta_key, $allowed_meta_keys, true)) {
                $skipped_count++;
                continue;
            }
            if ($this->openai_should_skip_meta_key($meta_key, $settings)) {
                $skipped_count++;
                continue;
            }
            $source_values = [];
            $target_values = [];
            foreach ((array)$values as $value_index => $raw_value) {
                $value = maybe_unserialize($raw_value);
                $source_values[$value_index] = $value;
                $target_values[$value_index] = $value;

                $before_batch = array_keys($batch_fields);
                $before_complex = count($complex);
                $this->openai_collect_batchable_data_translation_leaves(
                    $value,
                    (string)$meta_key,
                    $settings,
                    $batch_fields,
                    $batch_roles,
                    $batch_locators,
                    $complex,
                    [],
                    [],
                    false
                );
                foreach (array_diff(array_keys($batch_fields), $before_batch) as $field_key) {
                    $batch_locators[$field_key]['meta_key'] = (string)$meta_key;
                    $batch_locators[$field_key]['value_index'] = (int)$value_index;
                }
                for ($i = $before_complex; $i < count($complex); $i++) {
                    $complex[$i]['meta_key'] = (string)$meta_key;
                    $complex[$i]['value_index'] = (int)$value_index;
                }
            }
            $pending[(string)$meta_key] = [
                'source_values' => $source_values,
                'target_values' => $target_values,
            ];
        }

        if ($batch_fields) {
            $translated_batch = $this->openai_translate_collected_data_leaves($batch_fields, $batch_roles, $target_label, $settings, 'postmeta');
            if (is_wp_error($translated_batch)) {
                return $translated_batch;
            }
            foreach ($batch_fields as $field_key => $source_value) {
                if (!isset($batch_locators[$field_key]) || !array_key_exists($field_key, $translated_batch)) {
                    continue;
                }
                $locator = $batch_locators[$field_key];
                $meta_key = (string)$locator['meta_key'];
                $value_index = (int)$locator['value_index'];
                if (!isset($pending[$meta_key]['target_values'][$value_index])) {
                    continue;
                }
                $candidate = $this->openai_preserve_fragment_boundary_whitespace((string)$source_value, (string)$translated_batch[$field_key]);
                $this->openai_meta_quality_apply_locator(
                    $pending[$meta_key]['target_values'][$value_index],
                    $locator,
                    $candidate
                );
            }
        }

        // Keep complex HTML/code values on the existing structure-safe translator. In normal
        // ACF/SEO articles this set is small; all ordinary text leaves above were already batched.
        foreach ($complex as $locator) {
            $meta_key = (string)($locator['meta_key'] ?? '');
            $value_index = (int)($locator['value_index'] ?? 0);
            if ($meta_key === '' || !isset($pending[$meta_key]['target_values'][$value_index])) {
                continue;
            }
            $source_value = (string)($locator['source'] ?? '');
            $changed = false;
            $translated_value = $this->openai_translate_data_string(
                $source_value,
                $target_label,
                $settings,
                $changed,
                (string)($locator['field_key'] ?? $meta_key)
            );
            if (is_wp_error($translated_value)) {
                return $translated_value;
            }
            if ($changed || (string)$translated_value !== $source_value) {
                $this->openai_meta_quality_apply_locator(
                    $pending[$meta_key]['target_values'][$value_index],
                    $locator,
                    (string)$translated_value
                );
            }
        }

        // Drop meta keys whose values did not change. This preserves the old skip statistics
        // and avoids rewriting structural metadata that only contained URLs or references.
        foreach (array_keys($pending) as $meta_key) {
            if (serialize($pending[$meta_key]['source_values']) === serialize($pending[$meta_key]['target_values'])) {
                unset($pending[$meta_key]);
                $skipped_count++;
            }
        }

        $qa_source = [];
        $qa_target = [];
        $qa_roles = [];
        $qa_locators = [];
        foreach ($pending as $meta_key => $entry) {
            foreach ((array)$entry['source_values'] as $value_index => $source_value) {
                if (!array_key_exists($value_index, (array)$entry['target_values'])) {
                    continue;
                }
                $base_key = 'm' . count($qa_source);
                $before_keys = array_keys($qa_source);
                $this->openai_collect_meta_quality_pairs(
                    $source_value,
                    $entry['target_values'][$value_index],
                    $base_key,
                    (string)$meta_key,
                    $qa_source,
                    $qa_target,
                    $qa_roles,
                    $qa_locators,
                    []
                );
                foreach (array_diff(array_keys($qa_source), $before_keys) as $qa_key) {
                    $qa_locators[$qa_key]['meta_key'] = (string)$meta_key;
                    $qa_locators[$qa_key]['value_index'] = (int)$value_index;
                }
            }
        }

        if ($qa_source) {
            $reviewed = $this->openai_central_quality_review_fields(
                $qa_source,
                $qa_target,
                $qa_roles,
                $target_label,
                $settings,
                'postmeta'
            );
            if (is_wp_error($reviewed)) {
                return $reviewed;
            }
            foreach ($reviewed as $qa_key => $value) {
                if (!isset($qa_locators[$qa_key])) {
                    continue;
                }
                $locator = $qa_locators[$qa_key];
                $meta_key = (string)$locator['meta_key'];
                $value_index = (int)$locator['value_index'];
                if (!isset($pending[$meta_key]['target_values'][$value_index])) {
                    continue;
                }
                $this->openai_meta_quality_apply_locator(
                    $pending[$meta_key]['target_values'][$value_index],
                    $locator,
                    (string)$value
                );
            }
        }

        switch_to_blog((int)$job['target_blog_id']);
        foreach ($pending as $meta_key => $entry) {
            delete_post_meta((int)$job['target_post_id'], $meta_key);
            foreach ((array)$entry['target_values'] as $value) {
                $added = add_post_meta((int)$job['target_post_id'], $meta_key, $value, false);
                if ($added === false) {
                    $this->openai_cli_trace_line('WRITEBACK VERIFY scope=postmeta status=failed reason=add_failed meta_key=' . $meta_key);
                    restore_current_blog();
                    return new WP_Error('wpmu_ml_meta_writeback_failed', 'PHP 写回完整性检查失败：Meta Key ' . $meta_key . ' 写入失败。');
                }
            }
            $actual_values = get_post_meta((int)$job['target_post_id'], $meta_key, false);
            if (!$this->openai_writeback_values_equal(array_values((array)$entry['target_values']), array_values((array)$actual_values))) {
                $this->openai_cli_trace_line('WRITEBACK VERIFY scope=postmeta status=failed reason=readback_mismatch meta_key=' . $meta_key);
                restore_current_blog();
                return new WP_Error('wpmu_ml_meta_writeback_mismatch', 'PHP 写回完整性检查失败：Meta Key ' . $meta_key . ' 写入后回读不一致。');
            }
            $translated_count++;
        }
        restore_current_blog();
        $this->openai_cli_trace_line('WRITEBACK VERIFY scope=postmeta status=ok keys=' . $translated_count . ' post_id=' . (int)$job['target_post_id']);

        $this->openai_cli_trace_line(sprintf(
            'POSTMETA BATCH SUMMARY translated_keys=%d batch_text_fields=%d complex_fields=%d qa_fields=%d skipped=%d',
            $translated_count,
            count($batch_fields),
            count($complex),
            count($qa_source),
            $skipped_count
        ));
        return [
            'translated' => $translated_count,
            'skipped' => $skipped_count,
            'qa_fields' => count($qa_source),
            'batch_fields' => count($batch_fields),
            'complex_fields' => count($complex),
        ];
    }

    private function openai_translate_meta_value($value, $target_label, $settings, &$changed, $meta_key = '') {
        return $this->openai_translate_decoded_data_value($value, $target_label, $settings, $changed, (string)$meta_key);
    }

    private function openai_translate_decoded_data_value($value, $target_label, $settings, &$changed, $key = '') {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && $this->openai_should_skip_data_key($k, $settings)) {
                    $out[$k] = $v;
                    continue;
                }
                $out[$k] = $this->openai_translate_decoded_data_value($v, $target_label, $settings, $changed, is_string($k) ? $k : '');
                if (is_wp_error($out[$k])) {
                    return $out[$k];
                }
            }
            return $out;
        }

        if (is_object($value)) {
            foreach ($value as $k => $v) {
                if (is_string($k) && $this->openai_should_skip_data_key($k, $settings)) {
                    continue;
                }
                $nv = $this->openai_translate_decoded_data_value($v, $target_label, $settings, $changed, is_string($k) ? $k : '');
                if (is_wp_error($nv)) {
                    return $nv;
                }
                $value->$k = $nv;
            }
            return $value;
        }

        if (!is_string($value)) {
            return $value;
        }

        return $this->openai_translate_data_string($value, $target_label, $settings, $changed, $key);
    }

    private function openai_translate_data_string($value, $target_label, $settings, &$changed, $key = '') {
        $value = (string)$value;
        $cleaned_value = $this->openai_strip_translation_artifacts($value);
        if ($cleaned_value !== $value) {
            $value = $cleaned_value;
            $changed = true;
        }
        $trim = trim($value);
        if ($trim === '' || !$this->openai_contains_translatable_source_text($trim)) {
            return $value;
        }

        $field_type = $this->openai_classify_agent_field_type($key);
        if ($key !== '' && $field_type === 'text' && $this->openai_should_skip_data_key($key, $settings)) {
            return $value;
        }

        // ACF Block、页面构建器和部分主题会把字段值序列化为 JSON 字符串；需要先递归翻译 JSON 内部的文本。
        $json_translated = $this->openai_translate_json_string_if_needed($value, $target_label, $settings, $changed);
        if (is_wp_error($json_translated)) {
            return $json_translated;
        }
        if ($json_translated !== null) {
            return (string)$json_translated;
        }

        // 有些编辑器会把 HTML 作为实体保存，插件会先解码并仅翻译可见文字。
        if (strpos($value, '&lt;') !== false && strpos($value, '&gt;') !== false) {
            $decoded_html = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded_html !== $value && strpos($decoded_html, '<') !== false && strpos($decoded_html, '>') !== false && $this->openai_contains_translatable_source_text($decoded_html)) {
                $translated_html = $this->openai_translate_wp_content($decoded_html, $target_label, $settings, $this->openai_get_field_aware_instruction($this->openai_classify_agent_field_type($key), $key) . ' Preserve tags, attributes, shortcodes, URLs and code exactly.', false);
                if (is_wp_error($translated_html)) {
                    return $translated_html;
                }
                if ((string)$translated_html !== $decoded_html) {
                    $changed = true;
                    return htmlspecialchars((string)$translated_html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
                return $value;
            }
        }

        if (strpos($value, '<') !== false && strpos($value, '>') !== false) {
            $translated = $this->openai_translate_wp_content($value, $target_label, $settings, $this->openai_get_field_aware_instruction($this->openai_classify_agent_field_type($key), $key) . ' Preserve tags, attributes, shortcodes, URLs and code exactly.', false);
        } else {
            if ($this->openai_data_string_looks_like_code($value, $key)) {
                if ($this->openai_data_string_looks_like_human_flow($value)) {
                    $this->openai_cli_trace_line(sprintf(
                        'GUTENBERG HUMAN FLOW ROUTE key=%s chars=%d',
                        $key !== '' ? (string)$key : '-',
                        $this->wpmu_ml_strlen($value)
                    ));
                    $translated = $this->openai_translate_plain_text(
                        $value,
                        $target_label,
                        $settings,
                        $this->openai_get_field_aware_instruction('text', $key)
                            . ' This value is a human-readable architecture or workflow line, not executable code. Translate every natural-language label while preserving arrows, parentheses, product names and technical abbreviations.'
                    );
                } else {
                    $translated = $this->openai_translate_raw_code_text_line_locked($value, $target_label, $settings, $task_instruction ?? '');
                }
            } else {
                if (!$this->openai_should_translate_meta_string($value)) {
                    return $value;
                }
                $translated = $this->openai_translate_plain_text($value, $target_label, $settings, $this->openai_get_field_aware_instruction($field_type, $key));
            }
        }
        if (is_wp_error($translated)) {
            return $translated;
        }
        if ((string)$translated !== $value) {
            $changed = true;
        }
        return (string)$translated;
    }

    private function openai_data_string_looks_like_human_flow($value) {
        $value = trim((string)$value);
        if ($value === '' || !$this->openai_contains_translatable_source_text($value)) {
            return false;
        }
        if ($this->wpmu_ml_strlen($value) > 500) {
            return false;
        }
        // Architecture diagrams, process chains and UI flow labels often live in
        // Gutenberg fields named "code" even though they are ordinary prose.
        if (!preg_match('/(?:→|⇒|➜|⟶|\s->\s)/u', $value)) {
            return false;
        }
        // Keep actual programming expressions on the code-preserving path.
        $without_arrows = preg_replace('/(?:→|⇒|➜|⟶|\s->\s)/u', ' ', $value);
        if (preg_match('/[{}=;<>]|\$[A-Za-z_]|\b(function|class|const|let|var|if|else|for|while|return|SELECT|INSERT|UPDATE|DELETE|CREATE)\b/i', (string)$without_arrows)) {
            return false;
        }
        return true;
    }

    private function openai_data_string_looks_like_code($value, $key = '') {
        $value = (string)$value;
        $key = strtolower((string)$key);
        if ($value === '' || !$this->openai_contains_translatable_source_text($value)) {
            return false;
        }
        if (in_array($key, ['code', 'source', 'source_code', 'snippet', 'code_block'], true)) {
            return true;
        }
        if (preg_match('/(^|\n)\s*(?:\$[A-Za-z_][A-Za-z0-9_]*\s*=|(?:const|let|var)\s+[A-Za-z_][A-Za-z0-9_]*\s*=|function\s+[A-Za-z_]|class\s+[A-Za-z_]|#\s*[^\n]*\p{L}|\/\/\s*[^\n]*\p{L}|--\s*[^\n]*\p{L}|<\?php|SELECT\s+|CREATE\s+TABLE)/iu', $value)) {
            return true;
        }
        if (strpos($value, "\n") !== false && preg_match('/[;{}()=]|=>|->|::|\$[A-Za-z_]/', $value)) {
            return true;
        }
        return false;
    }

    private function openai_translate_raw_code_text_line_locked($code, $target_label, $settings, $task_instruction = '') {
        $code = (string)$code;
        if ($code === '' || !$this->openai_contains_translatable_source_text($code)) {
            return $code;
        }
        $wrapped = '<pre><code>' . htmlspecialchars($code, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code></pre>';
        $translated = $this->openai_translate_human_text_in_code_block($wrapped, $target_label, $settings, $task_instruction);
        if (is_wp_error($translated)) {
            return $translated;
        }
        $translated = (string)$translated;
        if (preg_match('~^<pre><code>(.*)</code></pre>$~s', $translated, $m)) {
            $out = html_entity_decode((string)$m[1], ENT_NOQUOTES | ENT_HTML5, 'UTF-8');
            if ($this->openai_linebreak_signature($out) === $this->openai_linebreak_signature($code)) {
                return $out;
            }
        }
        return $code;
    }

    private function openai_translate_json_string_if_needed($value, $target_label, $settings, &$changed) {
        $value = (string)$value;
        $trim = trim($value);
        if ($trim === '' || !$this->openai_contains_translatable_source_text($trim)) {
            return null;
        }
        $first = substr($trim, 0, 1);
        $last = substr($trim, -1);
        if (!(($first === '{' && $last === '}') || ($first === '[' && $last === ']'))) {
            return null;
        }

        $decoded = json_decode($trim, true);
        if (!is_array($decoded)) {
            return null;
        }

        $local_changed = false;
        $translated = $this->openai_translate_decoded_data_value($decoded, $target_label, $settings, $local_changed, '');
        if (is_wp_error($translated)) {
            return $translated;
        }
        if (!$local_changed) {
            return $value;
        }

        $new_json = wp_json_encode($translated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($new_json) || $new_json === '') {
            return $value;
        }

        $changed = true;
        $leading_len = strlen($value) - strlen(ltrim($value));
        $trailing_len = strlen($value) - strlen(rtrim($value));
        $leading = $leading_len > 0 ? substr($value, 0, $leading_len) : '';
        $trailing = $trailing_len > 0 ? substr($value, -$trailing_len) : '';
        return $leading . $new_json . $trailing;
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

    private function sanitize_meta_key_pattern_list($raw) {
        $lines = preg_split('/\r\n|\r|\n|,/', (string)$raw);
        $out = [];
        foreach ((array)$lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            $line = preg_replace('/[^A-Za-z0-9_\-\*\.]/', '', $line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return implode("\n", array_values(array_unique($out)));
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

    private function openai_meta_key_is_excluded_by_setting($meta_key, $settings) {
        $patterns = $this->default_excluded_meta_key_patterns();
        $raw = (string)($settings['openai_excluded_meta_keys'] ?? '');
        if ($raw !== '') {
            $extra = preg_split('/\r\n|\r|\n|,/', $raw);
            foreach ((array)$extra as $p) {
                $p = trim((string)$p);
                if ($p !== '' && strpos($p, '#') !== 0) {
                    $patterns[] = $p;
                }
            }
        }
        foreach (array_unique($patterns) as $pattern) {
            if ($this->meta_key_matches_pattern($meta_key, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function openai_should_skip_data_key($key, $settings) {
        $key = (string)$key;
        if ($key === '') {
            return false;
        }
        if (strpos($key, '_') === 0) {
            // ACF 的 _field_name / _content = field_xxx 是字段引用，不能翻译。
            return true;
        }

        $lower = strtolower($key);
        $skip_exact = [
            'id', 'ids', 'uuid', 'uid', 'key', 'ref', 'hash', 'token', 'nonce', 'code',
            'url', 'uri', 'link', 'href', 'src', 'path', 'slug', 'permalink', 'canonical',
            'file', 'files', 'image', 'images', 'img', 'icon', 'icons', 'attachment', 'attachments', 'media',
            'class', 'className', 'style', 'css', 'align', 'anchor', 'mode', 'name', 'namespace',
            'backgroundColor', 'textColor', 'gradient', 'fontSize', 'lock', 'supports',
        ];
        if (in_array($key, $skip_exact, true) || in_array($lower, array_map('strtolower', $skip_exact), true)) {
            return true;
        }
        if (preg_match('/(^|_)(slug|url|uri|link|href|src|permalink|canonical|path|file|image|images|img|icon|attachment|media|id|ids|uuid|hash|token|nonce|code|ref|key)(_|$)/i', $key)) {
            return true;
        }
        return false;
    }

    private function openai_should_skip_meta_key($meta_key, $settings) {
        $meta_key = (string)$meta_key;
        $skip_exact = [
            '_edit_lock', '_edit_last', '_thumbnail_id', '_wp_attached_file', '_wp_attachment_metadata',
            '_wp_page_template', '_wp_old_slug', '_menu_item_type', '_menu_item_menu_item_parent',
            '_menu_item_object_id', '_menu_item_object', '_menu_item_target', '_menu_item_classes',
            '_menu_item_xfn', '_menu_item_url', '_wp_trash_meta_status', '_wp_trash_meta_time',
        ];
        if (in_array($meta_key, $skip_exact, true)) {
            return true;
        }
        if ($this->openai_meta_key_is_excluded_by_setting($meta_key, $settings)) {
            return true;
        }
        $seo_keys = [
            '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw',
            '_aioseo_title', '_aioseo_description', '_aioseo_keywords', '_aioseo_og_title', '_aioseo_og_description', '_aioseo_twitter_title', '_aioseo_twitter_description',
            'rank_math_title', 'rank_math_description', 'rank_math_facebook_title', 'rank_math_facebook_description',
            'rank_math_twitter_title', 'rank_math_twitter_description', 'rank_math_focus_keyword',
        ];
        if (in_array($meta_key, $seo_keys, true)) {
            return empty($settings['openai_translate_seo_meta']);
        }
        if (strpos($meta_key, '_') === 0) {
            // ACF 的 _field_name = field_xxx 是结构引用，不翻译。
            return true;
        }
        if (preg_match('/(^|_)(slug|url|uri|link|permalink|canonical|path|file|image|img|attachment|media|id|ids|uuid|hash|token|nonce|code|ref|key)(_|$)/i', $meta_key)) {
            return true;
        }
        return false;
    }

    private function openai_classify_agent_field_type($key) {
        $key = strtolower(trim((string)$key));
        if ($key === '') {
            return 'text';
        }

        $seo_title_keys = [
            'rank_math_title', 'rank_math_facebook_title', 'rank_math_twitter_title',
            '_yoast_wpseo_title', '_aioseo_title', '_aioseo_og_title', '_aioseo_twitter_title',
            'seo_title', 'meta_title', 'og_title', 'twitter_title',
        ];
        $seo_description_keys = [
            'rank_math_description', 'rank_math_facebook_description', 'rank_math_twitter_description',
            '_yoast_wpseo_metadesc', '_aioseo_description', '_aioseo_og_description', '_aioseo_twitter_description',
            'seo_description', 'meta_description', 'metadesc', 'og_description', 'twitter_description',
        ];
        $seo_keyword_keys = [
            'rank_math_focus_keyword', '_yoast_wpseo_focuskw', '_aioseo_keywords',
            'seo_keywords', 'meta_keywords', 'focus_keyword', 'focus_keywords', 'keywords',
        ];

        if (in_array($key, $seo_title_keys, true) || preg_match('/(^|_)(seo|meta|og|twitter)_(title)$/', $key)) {
            return 'seo_title';
        }
        if (in_array($key, $seo_description_keys, true) || preg_match('/(^|_)(seo|meta|og|twitter)_(description|desc|metadesc)$/', $key)) {
            return 'seo_description';
        }
        if (in_array($key, $seo_keyword_keys, true) || preg_match('/(^|_)(focus_)?keywords?$/', $key)) {
            return 'seo_keywords';
        }
        if (preg_match('/(^|_)(heading|headline|subheading|sub_title|subtitle)$/', $key)) {
            return 'heading';
        }
        if (preg_match('/(^|_)(title|name|label|button_text|button|cta|caption)$/', $key)) {
            return 'short_ui';
        }
        if (preg_match('/(^|_)(description|desc|summary|excerpt|intro)$/', $key)) {
            return 'description';
        }
        return 'text';
    }

    private function openai_get_field_aware_instruction($field_type, $field_name = '') {
        $field_type = sanitize_key((string)$field_type);
        $field_name = trim((string)$field_name);
        $suffix = $field_name !== '' ? ' Field name: ' . $field_name . '.' : '';

        switch ($field_type) {
            case 'seo_title':
                return 'Field-aware translation: this is an SEO title. Do not translate literally if unnatural. Localize it into a concise, search-friendly title for the target language while preserving original search intent, core keywords, facts, numbers, prices, dates, product names and brand names. Do not add facts. Return ONLY valid JSON with key: text.' . $suffix;
            case 'seo_description':
                return 'Field-aware translation: this is an SEO meta description. Localize it as a natural search result snippet in the target language while preserving the original meaning, search intent, core keywords, facts, numbers, prices, dates, product names and brand names. Do not add facts or exaggerated claims. Return ONLY valid JSON with key: text.' . $suffix;
            case 'seo_keywords':
                return 'Field-aware translation: this is an SEO keyword field. Convert each source keyword into natural localized keyword phrases that real users of the target language may search for. Preserve separators where possible. Keep brand names, product names, facts, numbers and dates unchanged. Do not turn keywords into a sentence. Return ONLY valid JSON with key: text.' . $suffix;
            case 'heading':
                return 'Field-aware translation: this is a content heading. Localize naturally for the target language while preserving heading meaning, core keywords, numbers, prices, dates, product names and brand names. Do not translate word-for-word if unnatural. Do not add facts. Return ONLY valid JSON with key: text.' . $suffix;
            case 'short_ui':
                return 'Field-aware translation: this is a short title, label, button or UI phrase. Translate into concise natural target-language UI wording. Preserve brand names, product names, URLs, coupon codes, numbers and technical identifiers. Return ONLY valid JSON with key: text.' . $suffix;
            case 'description':
                return 'Field-aware translation: this is a description, summary or excerpt field. Translate naturally and locally while preserving original meaning, facts, numbers, prices, dates, product names, brand names, URLs, coupon codes and technical identifiers. Do not add facts. Return ONLY valid JSON with key: text.' . $suffix;
            case 'text':
            default:
                return 'Translate this ACF/custom-field text naturally into the target language. Preserve original meaning, facts, brand names, product names, URLs, coupon codes, prices, dates, numbers and technical identifiers. Return ONLY valid JSON with key: text.' . $suffix;
        }
    }

    private function openai_should_translate_meta_string($value) {
        $value = (string)$value;
        $trim = trim($value);
        if ($trim === '' || !$this->openai_contains_translatable_source_text($trim)) {
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
        if (((substr($trim, 0, 1) === '{') && (substr($trim, -1) === '}')) || ((substr($trim, 0, 1) === '[') && (substr($trim, -1) === ']'))) {
            $decoded = json_decode($trim, true);
            if (is_array($decoded)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Verify that translated human-readable fields use the configured target language.
     * The verifier is language-agnostic: source and target identities are read from the
     * backend language contexts, never from a hard-coded language map.
     *
     * @return array|WP_Error Map of rejected field keys to verifier reasons, or WP_Error.
     */
    }
}
