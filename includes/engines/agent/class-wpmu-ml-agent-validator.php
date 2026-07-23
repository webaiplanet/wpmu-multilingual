<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Server-side PHP integrity validation for Agent results before WordPress write-back.
 *
 * These checks are always enabled and intentionally limited to returned fields, non-empty
 * required values, protected structure and placeholders. Language residue, numeric differences
 * and length differences are editorial hints for optional AI review, never hard failures here.
 */
final class WPMU_ML_Agent_Validator {
    public function validate($payload, $targets) {
        $source_fields = [];
        foreach ((array)($payload['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $field_id = (string)($field['field_id'] ?? '');
            if ($field_id !== '') {
                $source_fields[$field_id] = $field;
            }
        }

        foreach ($source_fields as $field_id => $field) {
            if (!array_key_exists($field_id, (array)$targets)) {
                return new WP_Error('wpmu_ml_agent_missing_field', '缺少字段译文：' . $field_id, ['status' => 400]);
            }
            $source = (string)($field['source'] ?? '');
            $target = is_scalar($targets[$field_id]) || $targets[$field_id] === null
                ? (string)$targets[$field_id]
                : '';
            if (!empty($field['required']) && trim($source) !== '' && trim($target) === '') {
                return new WP_Error('wpmu_ml_agent_empty_required_field', '必填字段译文为空：' . $field_id, ['status' => 400]);
            }
            $check = $this->validate_structure($source, $target, (string)($field['format'] ?? ''), $field_id);
            if (is_wp_error($check)) {
                return $check;
            }
        }
        return true;
    }

    private function validate_structure($source, $target, $format, $field_id) {
        $source = (string)$source;
        $target = (string)$target;
        if ($source === '' || $target === '') {
            return true;
        }
        if (preg_match('/u00(?:3c|3e|22|27|26|2f)/i', $target)) {
            return new WP_Error('wpmu_ml_agent_escaped_html_pollution', '字段出现 u003c/u003e/u0022 等转义污染：' . $field_id, ['status' => 400]);
        }

        $patterns = [
            'Gutenberg 区块标记' => '/<!--\s*\/?wp:[^>]*-->/i',
            '短代码' => '/\[\/?[A-Za-z0-9_-]+(?:\s[^\]]*)?\]/u',
            '占位符' => '/%[A-Za-z0-9_-]+%|%%[A-Za-z0-9_-]+%%|\{\{[^{}]+\}\}|__WPMU_ML_[A-Z0-9_]+__|\[[A-Za-z0-9_-]+\]/u',
            'URL' => '~https?://[^\s"\'<>]+~i',
        ];
        foreach ($patterns as $label => $pattern) {
            if ($this->pattern_multiset($source, $pattern) !== $this->pattern_multiset($target, $pattern)) {
                return new WP_Error('wpmu_ml_agent_structure_loss', '字段结构校验失败：' . $field_id . ' 的' . $label . '发生变化。', ['status' => 400]);
            }
        }

        if (in_array($format, ['wp_post_content','html_fragment','html_entity_fragment'], true)) {
            if ($this->html_tag_multiset($source) !== $this->html_tag_multiset($target)) {
                return new WP_Error('wpmu_ml_agent_structure_loss', '字段结构校验失败：' . $field_id . ' 的 HTML 标签集合发生变化。', ['status' => 400]);
            }
            if ($this->code_block_line_signature($source) !== $this->code_block_line_signature($target)) {
                return new WP_Error('wpmu_ml_agent_structure_loss', '字段结构校验失败：' . $field_id . ' 的代码块换行结构发生变化。', ['status' => 400]);
            }
        }

        $source_skeleton = $this->json_key_skeleton($source);
        if ($format === 'json_string' || $source_skeleton !== null) {
            $target_skeleton = $this->json_key_skeleton($target);
            if ($target_skeleton === null || $source_skeleton !== $target_skeleton) {
                return new WP_Error('wpmu_ml_agent_invalid_json_field', '字段应保持 JSON 键与层级结构：' . $field_id, ['status' => 400]);
            }
        }
        return true;
    }

    private function pattern_multiset($text, $pattern) {
        preg_match_all($pattern, (string)$text, $matches);
        $values = array_values((array)($matches[0] ?? []));
        sort($values, SORT_STRING);
        return $values;
    }

    private function html_tag_multiset($text) {
        preg_match_all('/<\/?(?!wpmu-ml-\d+\b)([A-Za-z][A-Za-z0-9:-]*)\b/u', (string)$text, $matches);
        $values = array_map('strtolower', array_values((array)($matches[0] ?? [])));
        sort($values, SORT_STRING);
        return $values;
    }

    private function code_block_line_signature($text) {
        preg_match_all('/<(pre|code)\b[^>]*>(.*?)<\/\1>/is', (string)$text, $matches, PREG_SET_ORDER);
        $signature = [];
        foreach ((array)$matches as $match) {
            $body = str_replace(["\r\n", "\r"], "\n", (string)($match[2] ?? ''));
            $signature[] = substr_count($body, "\n");
        }
        return $signature;
    }

    private function json_key_skeleton($value) {
        $trimmed = trim((string)$value);
        if ($trimmed === '' || !in_array($trimmed[0], ['{','['], true)) {
            return null;
        }
        $decoded = json_decode($trimmed, true);
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
}
