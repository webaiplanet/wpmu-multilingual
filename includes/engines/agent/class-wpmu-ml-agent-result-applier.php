<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies translated Agent fields back to target WordPress content.
 */
final class WPMU_ML_Agent_Result_Applier {
    private $core;

    public function __construct($core) {
        $this->core = $core;
    }

    public function apply($job, $targets, $payload) {
        $identity = $this->core->validate_translation_job_target($job, true);
        if (empty($identity['valid'])) {
            return new WP_Error(
                'wpmu_ml_agent_target_identity_invalid',
                '目标文章身份校验失败：' . (string)($identity['message'] ?? ''),
                ['error_code' => (string)($identity['error_code'] ?? 'relation_invalid')]
            );
        }
        if ((string)($identity['status'] ?? '') === 'legacy_relation' && !empty($identity['relation'])) {
            $this->core->stamp_relation_target_identity($identity['relation']);
        }
        $settings = $this->core->get_settings();
        $complete_status = $this->complete_status_for_job($job, $settings);
        if (!empty($identity['force_draft'])) {
            $complete_status = 'draft';
        }
        $source_slug = (string)($identity['target_slug'] ?? '');

        $postarr = [
            'ID' => (int)$job['target_post_id'],
            'post_status' => $complete_status,
        ];
        if (array_key_exists('post_content', $targets)) {
            $gutenberg_applied = $this->apply_gutenberg_comment_fields((string)$targets['post_content'], $targets, $payload);
            if (is_wp_error($gutenberg_applied)) {
                return $gutenberg_applied;
            }
            $targets['post_content'] = (string)$gutenberg_applied;
        }
        foreach (['post_title','post_excerpt','post_content'] as $field) {
            if (array_key_exists($field, $targets)) {
                $postarr[$field] = wp_slash((string)$targets[$field]);
            }
        }
        if ($source_slug !== '') {
            $postarr['post_name'] = $source_slug;
            $slug_lock = $this->core->force_target_slug_value(
                (int)$job['target_blog_id'],
                (int)$job['target_post_id'],
                $source_slug
            );
            if (is_wp_error($slug_lock)) {
                return $slug_lock;
            }
        }

        switch_to_blog((int)$job['target_blog_id']);
        update_post_meta((int)$job['target_post_id'], '_wpmu_ml_agent_result_writeback_running', '1');
        $updated = wp_update_post($postarr, true);
        delete_post_meta((int)$job['target_post_id'], '_wpmu_ml_agent_result_writeback_running');
        if (is_wp_error($updated)) {
            restore_current_blog();
            return $updated;
        }
        restore_current_blog();
        if ($source_slug !== '') {
            $slug_lock = $this->core->force_target_slug_value(
                (int)$job['target_blog_id'],
                (int)$job['target_post_id'],
                $source_slug
            );
            if (is_wp_error($slug_lock)) {
                return $slug_lock;
            }
        }

        switch_to_blog((int)$job['target_blog_id']);
        $written_post = get_post((int)$job['target_post_id']);
        if (!$written_post instanceof WP_Post) {
            restore_current_blog();
            return new WP_Error('wpmu_ml_agent_post_readback_failed', '目标文章写回后无法读取。');
        }
        $expected_post = [
            'post_status' => (string)$complete_status,
        ];
        foreach (['post_title','post_excerpt','post_content'] as $field) {
            if (array_key_exists($field, $targets)) {
                $expected_post[$field] = (string)$targets[$field];
            }
        }
        if ($source_slug !== '') {
            $expected_post['post_name'] = $source_slug;
        }
        foreach ($expected_post as $property => $expected_value) {
            $actual_value = isset($written_post->{$property}) ? (string)$written_post->{$property} : '';
            if (!$this->post_field_values_equal($property, $expected_value, $actual_value)) {
                restore_current_blog();
                return new WP_Error(
                    'wpmu_ml_agent_post_writeback_mismatch',
                    '目标文章写回完整性校验失败：' . $property . '。'
                );
            }
        }
        restore_current_blog();

        $meta_result = $this->apply_meta_fields($job, $targets, $payload);
        if (is_wp_error($meta_result)) {
            return $meta_result;
        }

        return [
            'post_id' => (int)$updated,
            'post_status' => $complete_status,
            'meta_translated' => (int)($meta_result['translated'] ?? 0),
            'meta_skipped' => (int)($meta_result['skipped'] ?? 0),
            'gutenberg_translated' => (int)$this->count_returned_gutenberg_fields($targets, $payload),
        ];
    }

    private function count_returned_gutenberg_fields($targets, $payload) {
        $count = 0;
        foreach ((array)($payload['fields'] ?? []) as $field) {
            if (!is_array($field) || (string)($field['field_scope'] ?? '') !== 'gutenberg') {
                continue;
            }
            $field_id = (string)($field['field_id'] ?? '');
            if ($field_id !== '' && array_key_exists($field_id, (array)$targets)) {
                $count++;
            }
        }
        return $count;
    }

    private function apply_gutenberg_comment_fields($content, $targets, $payload) {
        $content = (string)$content;
        $fields_by_comment = [];
        foreach ((array)($payload['fields'] ?? []) as $field) {
            if (!is_array($field) || (string)($field['field_scope'] ?? '') !== 'gutenberg') {
                continue;
            }
            $field_id = (string)($field['field_id'] ?? '');
            if ($field_id === '' || !array_key_exists($field_id, (array)$targets)) {
                continue;
            }
            $comment_index = isset($field['comment_index']) ? (int)$field['comment_index'] : -1;
            if ($comment_index < 0) {
                continue;
            }
            $fields_by_comment[$comment_index][] = $field;
        }
        if (!$fields_by_comment) {
            return $content;
        }

        preg_match_all('~<!--(.*?)-->~s', $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (!$matches) {
            return new WP_Error('wpmu_ml_agent_gutenberg_comments_missing', 'Agent 返回正文中缺少 Gutenberg 注释，无法写回区块数据字段。');
        }

        $replacements = [];
        foreach ($fields_by_comment as $comment_index => $fields) {
            if (empty($matches[$comment_index][0][0])) {
                return new WP_Error('wpmu_ml_agent_gutenberg_comment_missing', 'Agent 返回正文中缺少指定 Gutenberg 注释：' . (int)$comment_index . '。');
            }
            $source_comment = (string)$matches[$comment_index][0][0];
            $expected_hash = (string)($fields[0]['comment_hash'] ?? '');
            if ($expected_hash !== '' && !hash_equals($expected_hash, hash('sha256', $source_comment))) {
                return new WP_Error('wpmu_ml_agent_gutenberg_comment_changed', 'Agent 返回正文中的 Gutenberg 注释已变化，拒绝写回区块数据字段。');
            }
            $inner = substr($source_comment, 4, -3);
            $json_start = strpos($inner, '{');
            $json_end = strrpos($inner, '}');
            if ($json_start === false || $json_end === false || $json_end <= $json_start) {
                continue;
            }
            $decoded = json_decode(substr($inner, $json_start, $json_end - $json_start + 1), true);
            if (!is_array($decoded)) {
                continue;
            }
            $changed = false;
            foreach ($fields as $field) {
                $field_id = (string)($field['field_id'] ?? '');
                $path = isset($field['value_path']) && is_array($field['value_path']) ? $field['value_path'] : [];
                if ($field_id === '' || !$path) {
                    continue;
                }
                if ($this->set_path_value($decoded, $path, (string)$targets[$field_id])) {
                    $changed = true;
                }
            }
            if (!$changed) {
                continue;
            }
            $new_json = wp_json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($new_json) || $new_json === '') {
                return new WP_Error('wpmu_ml_agent_gutenberg_json_encode_failed', 'Gutenberg 区块数据重新编码失败。');
            }
            $replacements[] = [
                'offset' => (int)$matches[$comment_index][0][1],
                'length' => strlen($source_comment),
                'value' => '<!--' . substr($inner, 0, $json_start) . $new_json . substr($inner, $json_end + 1) . '-->',
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

    private function apply_meta_fields($job, $targets, $payload) {
        $meta_fields = [];
        foreach ((array)($payload['fields'] ?? []) as $field) {
            if (!is_array($field) || (string)($field['field_scope'] ?? '') !== 'meta') {
                continue;
            }
            $field_id = (string)($field['field_id'] ?? '');
            $meta_key = (string)($field['meta_key'] ?? '');
            if ($field_id === '' || $meta_key === '' || !array_key_exists($field_id, $targets)) {
                continue;
            }
            $index = isset($field['meta_index']) ? (int)$field['meta_index'] : 0;
            $meta_fields[$meta_key][$index][] = $field;
        }

        if (empty($meta_fields)) {
            return ['translated' => 0, 'skipped' => 0];
        }

        switch_to_blog((int)$job['source_blog_id']);
        $source_meta = get_post_meta((int)$job['source_post_id']);
        restore_current_blog();

        if (!is_array($source_meta)) {
            return ['translated' => 0, 'skipped' => 0];
        }

        $translated = 0;
        $skipped = 0;
        switch_to_blog((int)$job['target_blog_id']);
        foreach ($meta_fields as $meta_key => $by_index) {
            if (!array_key_exists($meta_key, $source_meta)) {
                $skipped++;
                continue;
            }
            $new_values = [];
            $changed = false;
            foreach ((array)$source_meta[$meta_key] as $index => $raw_value) {
                $decoded = maybe_unserialize($raw_value);
                if (isset($by_index[(int)$index])) {
                    foreach ((array)$by_index[(int)$index] as $field) {
                        $field_id = (string)($field['field_id'] ?? '');
                        $path = isset($field['value_path']) && is_array($field['value_path']) ? $field['value_path'] : [];
                        $target_value = (string)($targets[$field_id] ?? '');
                        $set = $this->set_path_value($decoded, $path, $target_value);
                        if ($set) {
                            $changed = true;
                        }
                    }
                }
                $new_values[] = $decoded;
            }
            if ($changed) {
                delete_post_meta((int)$job['target_post_id'], $meta_key);
                foreach ($new_values as $value) {
                    $added = add_post_meta((int)$job['target_post_id'], $meta_key, $value, false);
                    if ($added === false) {
                        restore_current_blog();
                        return new WP_Error('wpmu_ml_agent_meta_write_failed', '目标元数据写入失败：' . $meta_key . '。');
                    }
                }
                $written_values = get_post_meta((int)$job['target_post_id'], $meta_key, false);
                if (!$this->writeback_values_equal($new_values, $written_values)) {
                    restore_current_blog();
                    return new WP_Error('wpmu_ml_agent_meta_writeback_mismatch', '目标元数据写回完整性校验失败：' . $meta_key . '。');
                }
                $translated++;
            } else {
                $skipped++;
            }
        }
        restore_current_blog();

        return ['translated' => $translated, 'skipped' => $skipped];
    }

    private function set_path_value(&$value, $path, $new_value) {
        if (empty($path)) {
            if ((string)$value === (string)$new_value) {
                return false;
            }
            $value = (string)$new_value;
            return true;
        }

        $ref =& $value;
        $last_index = count($path) - 1;
        foreach ($path as $i => $key) {
            if ($i === $last_index) {
                if (is_array($ref)) {
                    if (!array_key_exists($key, $ref) || (string)$ref[$key] !== (string)$new_value) {
                        $ref[$key] = (string)$new_value;
                        return true;
                    }
                    return false;
                }
                if (is_object($ref)) {
                    if (!property_exists($ref, (string)$key) || (string)$ref->{$key} !== (string)$new_value) {
                        $ref->{$key} = (string)$new_value;
                        return true;
                    }
                    return false;
                }
                return false;
            }

            if (is_array($ref)) {
                if (!array_key_exists($key, $ref)) {
                    return false;
                }
                $ref =& $ref[$key];
                continue;
            }
            if (is_object($ref)) {
                if (!property_exists($ref, (string)$key)) {
                    return false;
                }
                $ref =& $ref->{$key};
                continue;
            }
            return false;
        }
        return false;
    }

    private function post_field_values_equal($property, $expected, $actual) {
        if (in_array((string)$property, ['post_content', 'post_excerpt'], true)) {
            $expected = wp_kses_normalize_entities((string)$expected);
            $actual = wp_kses_normalize_entities((string)$actual);
            $expected = preg_replace('/\s*\/>/', ' />', $expected);
            $actual = preg_replace('/\s*\/>/', ' />', $actual);
        }
        return $this->writeback_values_equal($expected, $actual);
    }

    private function writeback_values_equal($expected, $actual) {
        return $this->normalize_writeback_value($expected) === $this->normalize_writeback_value($actual);
    }

    private function normalize_writeback_value($value) {
        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                $normalized[(string)$key] = $this->normalize_writeback_value($item);
            }
            return ['__type' => 'array', '__value' => $normalized];
        }
        if (is_object($value)) {
            return [
                '__type' => 'object',
                '__class' => get_class($value),
                '__value' => $this->normalize_writeback_value(get_object_vars($value)),
            ];
        }
        // WordPress metadata storage normalizes null to an empty string and booleans to
        // scalar strings. Compare the persisted representation rather than PHP input types.
        if ($value === null) {
            return ['__type' => 'scalar', '__value' => ''];
        }
        return ['__type' => 'scalar', '__value' => (string)$value];
    }

    private function complete_status_for_job($job, $settings) {
        $status = sanitize_key((string)($job['complete_status'] ?? ''));
        if (in_array($status, ['draft','pending','publish'], true)) {
            return $status;
        }
        $target_lang = sanitize_key((string)($job['target_lang'] ?? ''));
        $by_lang = is_array($settings['translation_status_by_lang'] ?? null) ? $settings['translation_status_by_lang'] : [];
        $status = sanitize_key($by_lang[$target_lang] ?? ($settings['translation_complete_status'] ?? 'pending'));
        return in_array($status, ['draft','pending','publish'], true) ? $status : 'pending';
    }
}
