<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Deterministic source-field snapshots and incremental translation manifests.
 */
if (!trait_exists('WPMU_ML_Core_Incremental_Sync_Trait')) {
    trait WPMU_ML_Core_Incremental_Sync_Trait {
    private function incremental_snapshot_meta_key() {
        return '_wpmu_ml_source_field_snapshot_v1';
    }

    private function incremental_translation_marker_key() {
        return '_wpmu_ml_has_translated_content';
    }

    private function incremental_sync_meta_is_internal($meta_key) {
        return in_array((string)$meta_key, [
            '_edit_lock', '_edit_last',
            '_wpmu_ml_source_blog_id', '_wpmu_ml_source_post_id', '_wpmu_ml_source_lang',
            '_wpmu_ml_target_lang', '_wpmu_ml_relation_version',
            '_wpmu_ml_slug_conflict_source_slug', '_wpmu_ml_slug_conflict_fallback_slug',
            '_wpmu_ml_slug_conflict_requires_review',
            $this->incremental_snapshot_meta_key(), $this->incremental_translation_marker_key(),
            '_wpmu_ml_translation_completed_at',
        ], true);
    }

    private function build_source_field_snapshot($source_post, $source_meta) {
        $snapshot = [
            'version' => 1,
            'core' => [],
            'meta' => [],
        ];
        foreach (['post_title','post_excerpt','post_content'] as $field) {
            $snapshot['core'][$field] = hash('sha256', (string)$source_post->{$field});
        }
        foreach ((array)$source_meta as $meta_key => $values) {
            if ($this->incremental_sync_meta_is_internal($meta_key)) {
                continue;
            }
            $snapshot['meta'][(string)$meta_key] = hash('sha256', serialize(array_values((array)$values)));
        }
        ksort($snapshot['meta'], SORT_STRING);
        $snapshot['hash'] = hash('sha256', wp_json_encode([
            $snapshot['core'],
            $snapshot['meta'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $snapshot;
    }

    private function decode_source_field_snapshot($raw) {
        if (is_array($raw)) {
            $snapshot = $raw;
        } else {
            $snapshot = json_decode((string)$raw, true);
        }
        return is_array($snapshot) && (int)($snapshot['version'] ?? 0) === 1 ? $snapshot : [];
    }

    private function diff_source_field_snapshots($previous, $current) {
        $out = ['core' => [], 'meta' => [], 'meta_deleted' => [], 'full' => false];
        if (!$previous) {
            $out['full'] = true;
            $out['core'] = array_keys((array)$current['core']);
            $out['meta'] = array_keys((array)$current['meta']);
            return $out;
        }
        foreach ((array)$current['core'] as $field => $hash) {
            if (!isset($previous['core'][$field]) || !hash_equals((string)$previous['core'][$field], (string)$hash)) {
                $out['core'][] = (string)$field;
            }
        }
        foreach ((array)$current['meta'] as $meta_key => $hash) {
            if (!isset($previous['meta'][$meta_key]) || !hash_equals((string)$previous['meta'][$meta_key], (string)$hash)) {
                $out['meta'][] = (string)$meta_key;
            }
        }
        foreach ((array)($previous['meta'] ?? []) as $meta_key => $hash) {
            if (!array_key_exists($meta_key, (array)$current['meta'])) {
                $out['meta_deleted'][] = (string)$meta_key;
            }
        }
        return $out;
    }

    private function infer_initial_source_changes($source_post, $source_meta, $target_post, $target_meta, $translated) {
        $current = $this->build_source_field_snapshot($source_post, $source_meta);
        if (!$target_post || $translated) {
            return $this->diff_source_field_snapshots([], $current);
        }
        $changes = ['core' => [], 'meta' => [], 'meta_deleted' => [], 'full' => false];
        foreach (['post_title','post_excerpt','post_content'] as $field) {
            if ((string)$source_post->{$field} !== (string)$target_post->{$field}) {
                $changes['core'][] = $field;
            }
        }
        foreach ((array)$source_meta as $meta_key => $values) {
            if ($this->incremental_sync_meta_is_internal($meta_key)) {
                continue;
            }
            if (serialize(array_values((array)$values)) !== serialize(array_values((array)($target_meta[$meta_key] ?? [])))) {
                $changes['meta'][] = (string)$meta_key;
            }
        }
        return $changes;
    }

    private function incremental_meta_key_is_translatable($meta_key, $settings) {
        if (empty($settings['openai_translate_meta']) && empty($settings['opencc_convert_meta'])) {
            return false;
        }
        if (method_exists($this, 'openai_should_skip_meta_key')) {
            return !$this->openai_should_skip_meta_key((string)$meta_key, $settings);
        }
        return strpos((string)$meta_key, '_') !== 0;
    }

    private function build_translation_change_manifest($changes, $settings, $translated) {
        $manifest = [
            'version' => 1,
            'mode' => $translated ? 'translated_update' : 'initial_translation',
            'core' => array_values(array_intersect(
                ['post_title','post_excerpt','post_content'],
                array_map('sanitize_key', (array)($changes['core'] ?? []))
            )),
            'meta' => [],
            'meta_deleted' => [],
            'full' => !empty($changes['full']),
        ];
        foreach ((array)($changes['meta'] ?? []) as $meta_key) {
            if ($this->incremental_meta_key_is_translatable($meta_key, $settings)) {
                $manifest['meta'][] = (string)$meta_key;
            }
        }
        foreach ((array)($changes['meta_deleted'] ?? []) as $meta_key) {
            if ($this->incremental_meta_key_is_translatable($meta_key, $settings)) {
                $manifest['meta_deleted'][] = (string)$meta_key;
            }
        }
        $manifest['meta'] = array_values(array_unique($manifest['meta']));
        $manifest['meta_deleted'] = array_values(array_unique($manifest['meta_deleted']));
        return $manifest;
    }

    public function get_translation_job_change_manifest($job) {
        $manifest = json_decode((string)($job['change_manifest'] ?? ''), true);
        if (!is_array($manifest) || (int)($manifest['version'] ?? 0) !== 1) {
            return ['version' => 0, 'mode' => 'full_translate', 'core' => ['post_title','post_excerpt','post_content'], 'meta' => [], 'meta_deleted' => [], 'full' => true];
        }
        return $manifest;
    }

    public function translation_job_is_incremental($job) {
        return (int)$this->get_translation_job_change_manifest($job)['version'] === 1;
    }

    public function translation_job_selects_core_field($job, $field) {
        $manifest = $this->get_translation_job_change_manifest($job);
        return !empty($manifest['full']) || in_array((string)$field, (array)$manifest['core'], true);
    }

    public function translation_job_meta_keys($job) {
        $manifest = $this->get_translation_job_change_manifest($job);
        return !empty($manifest['full']) && (int)$manifest['version'] === 0 ? null : array_values((array)$manifest['meta']);
    }

    public function translation_job_pending_relation_status($job, $fallback = 'needs_translation') {
        $manifest = $this->get_translation_job_change_manifest($job);
        return (string)($manifest['mode'] ?? '') === 'translated_update'
            ? 'translated_update_pending'
            : sanitize_key((string)$fallback);
    }

    private function translation_manifest_has_work($manifest) {
        return !empty($manifest['core']) || !empty($manifest['meta']);
    }

    private function target_core_matches_source_snapshot($target_post, $source_post, $source_snapshot = []) {
        if (!$target_post instanceof WP_Post || !$source_post instanceof WP_Post) {
            return false;
        }
        foreach (['post_title','post_excerpt','post_content'] as $field) {
            $expected_hash = (string)($source_snapshot['core'][$field] ?? '');
            if ($expected_hash !== '') {
                if (!hash_equals($expected_hash, hash('sha256', (string)$target_post->{$field}))) {
                    return false;
                }
            } elseif ((string)$target_post->{$field} !== (string)$source_post->{$field}) {
                return false;
            }
        }
        return true;
    }

    private function target_has_completed_translation($relation, $source_post, $target_post, $target_blog_id, $target_post_id, $previous_snapshot = []) {
        global $wpdb;
        switch_to_blog((int)$target_blog_id);
        $marker = (string)get_post_meta((int)$target_post_id, $this->incremental_translation_marker_key(), true) === '1';
        restore_current_blog();
        if ($marker) {
            return true;
        }
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT status,translated_content FROM {$this->tables['jobs']} WHERE source_blog_id=%d AND source_post_id=%d AND target_blog_id=%d LIMIT 1",
            (int)$relation['source_blog_id'],
            (int)$relation['source_post_id'],
            (int)$relation['target_blog_id']
        ), ARRAY_A);
        if (!empty($job['translated_content']) || in_array((string)($job['status'] ?? ''), [
            'machine_done_published','machine_translated','opencc_done_published','opencc_converted',
            'agent_done_published','agent_translated','manual_done','translated',
        ], true)) {
            return true;
        }
        if (!$source_post instanceof WP_Post || !$target_post instanceof WP_Post) {
            return false;
        }
        return !$this->target_core_matches_source_snapshot($target_post, $source_post, $previous_snapshot);
    }

    public function mark_translation_content_completed($job) {
        global $wpdb;
        $wpdb->update($this->tables['jobs'], ['translated_content' => 1], ['id' => (int)$job['id']], ['%d'], ['%d']);
        switch_to_blog((int)$job['target_blog_id']);
        update_post_meta((int)$job['target_post_id'], $this->incremental_translation_marker_key(), '1');
        update_post_meta((int)$job['target_post_id'], '_wpmu_ml_translation_completed_at', current_time('mysql'));
        restore_current_blog();
    }
    }
}
