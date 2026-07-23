<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Taxonomy term CRUD synchronization.
 */
if (!trait_exists('WPMU_ML_Core_Term_Sync_Trait')) {
    trait WPMU_ML_Core_Term_Sync_Trait
    {
        private $wpmu_ml_term_sync_running = false;

        public function maybe_sync_source_term_created($term_id, $tt_id = 0, $taxonomy = '', $args = [])
        {
            $this->sync_source_term_change_to_targets($term_id, $taxonomy, 'created');
        }

        public function maybe_sync_source_term_edited($term_id, $tt_id = 0, $taxonomy = '', $args = [])
        {
            $this->sync_source_term_change_to_targets($term_id, $taxonomy, 'updated');
        }

        public function maybe_sync_source_term_deleted($term_id, $tt_id = 0, $taxonomy = '', $deleted_term = null, $object_ids = [])
        {
            $taxonomy = sanitize_key((string)$taxonomy);
            if (!$this->should_handle_source_term_sync($taxonomy)) {
                return;
            }

            global $wpdb;
            $settings = $this->get_settings();
            $source_blog_id = absint($settings['source_blog_id'] ?? 0);
            $source_term_id = absint($term_id);
            if (!$source_blog_id || !$source_term_id || $taxonomy === '') {
                return;
            }

            $relations = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->tables['terms']}
                 WHERE source_blog_id = %d AND source_term_id = %d AND source_taxonomy = %s",
                $source_blog_id,
                $source_term_id,
                $taxonomy
            ), ARRAY_A);
            if (!$relations) {
                $this->log('info', 'term_sync_skipped', '源 term 删除时没有找到目标关系，已跳过。', [
                    'source_blog_id' => $source_blog_id,
                    'source_term_id' => $source_term_id,
                    'taxonomy' => $taxonomy,
                ]);
                return;
            }

            $this->wpmu_ml_term_sync_running = true;
            $deleted = 0;
            $missing = 0;
            $failed = 0;
            try {
                foreach ((array)$relations as $relation) {
                    $target_blog_id = absint($relation['target_blog_id'] ?? 0);
                    $target_term_id = absint($relation['target_term_id'] ?? 0);
                    $target_taxonomy = sanitize_key((string)($relation['target_taxonomy'] ?? $taxonomy));
                    if (!$target_blog_id || !$target_term_id || $target_taxonomy === '') {
                        $failed++;
                        continue;
                    }

                    switch_to_blog($target_blog_id);
                    if (!taxonomy_exists($target_taxonomy)) {
                        restore_current_blog();
                        $failed++;
                        $this->log('warning', 'term_sync_error', '目标站 taxonomy 不存在，无法删除目标 term。', [
                            'source_blog_id' => $source_blog_id,
                            'source_term_id' => $source_term_id,
                            'target_blog_id' => $target_blog_id,
                            'target_term_id' => $target_term_id,
                            'taxonomy' => $target_taxonomy,
                        ]);
                        continue;
                    }

                    $target_term = get_term($target_term_id, $target_taxonomy);
                    if ($target_term && !is_wp_error($target_term)) {
                        $result = wp_delete_term($target_term_id, $target_taxonomy);
                        if (is_wp_error($result) || !$result) {
                            restore_current_blog();
                            $failed++;
                            $wpdb->update($this->tables['terms'], [
                                'relation_status' => 'delete_failed',
                                'updated_at' => current_time('mysql'),
                            ], ['id' => absint($relation['id'])], ['%s','%s'], ['%d']);
                            $this->log('error', 'term_sync_error', '删除目标 term 失败。', [
                                'source_blog_id' => $source_blog_id,
                                'source_term_id' => $source_term_id,
                                'target_blog_id' => $target_blog_id,
                                'target_term_id' => $target_term_id,
                                'taxonomy' => $target_taxonomy,
                                'error' => is_wp_error($result) ? $result->get_error_message() : 'wp_delete_term returned false',
                            ]);
                            continue;
                        }
                        $deleted++;
                    } else {
                        $missing++;
                    }
                    restore_current_blog();

                    $wpdb->delete($this->tables['terms'], ['id' => absint($relation['id'])], ['%d']);
                }
            } finally {
                $this->wpmu_ml_term_sync_running = false;
            }

            $this->log('info', 'term_sync_deleted', '源 term 删除同步完成。', [
                'source_blog_id' => $source_blog_id,
                'source_term_id' => $source_term_id,
                'taxonomy' => $taxonomy,
                'deleted' => $deleted,
                'missing' => $missing,
                'failed' => $failed,
            ]);
        }

        private function sync_source_term_change_to_targets($term_id, $taxonomy, $event)
        {
            $taxonomy = sanitize_key((string)$taxonomy);
            if (!$this->should_handle_source_term_sync($taxonomy)) {
                return;
            }

            global $wpdb;
            $settings = $this->get_settings();
            $source_blog_id = absint($settings['source_blog_id'] ?? 0);
            $source_term_id = absint($term_id);
            if (!$source_blog_id || !$source_term_id || $taxonomy === '') {
                return;
            }

            switch_to_blog($source_blog_id);
            $source_term = get_term($source_term_id, $taxonomy);
            restore_current_blog();
            if (!$source_term || is_wp_error($source_term)) {
                $this->log('warning', 'term_sync_skipped', '源 term 不存在，已跳过同步。', [
                    'source_blog_id' => $source_blog_id,
                    'source_term_id' => $source_term_id,
                    'taxonomy' => $taxonomy,
                    'event' => $event,
                ]);
                return;
            }

            $source_site = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->tables['sites']} WHERE blog_id = %d LIMIT 1",
                $source_blog_id
            ), ARRAY_A);
            if (!$source_site) {
                $source_site = [
                    'blog_id' => $source_blog_id,
                    'lang_slug' => sanitize_key((string)($settings['source_lang'] ?? 'zh-hans')),
                ];
            }

            $targets = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->tables['sites']}
                 WHERE enabled = 1 AND blog_id <> %d
                 ORDER BY sort_order ASC, blog_id ASC",
                $source_blog_id
            ), ARRAY_A);
            if (!$targets) {
                return;
            }

            $this->wpmu_ml_term_sync_running = true;
            $created = 0;
            $updated = 0;
            $repaired = 0;
            $failed = 0;
            try {
                foreach ((array)$targets as $target) {
                    $result = $this->sync_one_target_term($source_site, $source_term, $target, $event, []);
                    if (is_wp_error($result)) {
                        $failed++;
                        continue;
                    }
                    if (!empty($result['created'])) {
                        $created++;
                    } else {
                        $updated++;
                    }
                    if (!empty($result['repaired'])) {
                        $repaired++;
                    }
                }
            } finally {
                $this->wpmu_ml_term_sync_running = false;
            }

            $this->log('info', $event === 'created' ? 'term_sync_created' : 'term_sync_updated', '源 term 同步完成。', [
                'source_blog_id' => $source_blog_id,
                'source_term_id' => $source_term_id,
                'taxonomy' => $taxonomy,
                'created' => $created,
                'updated' => $updated,
                'repaired' => $repaired,
                'failed' => $failed,
            ]);
        }

        private function should_handle_source_term_sync($taxonomy)
        {
            if (!empty($this->wpmu_ml_term_sync_running)) {
                return false;
            }

            $taxonomy = sanitize_key((string)$taxonomy);
            if ($taxonomy === '') {
                return false;
            }

            $settings = $this->get_settings();
            $source_blog_id = absint($settings['source_blog_id'] ?? 0);
            if (!$source_blog_id || (int)get_current_blog_id() !== $source_blog_id) {
                return false;
            }

            $sync_taxonomies = $this->get_effective_sync_taxonomies([$taxonomy]);
            if (!in_array($taxonomy, (array)$sync_taxonomies, true)) {
                $this->log('info', 'term_sync_skipped', 'taxonomy 未在同步白名单中，已跳过。', [
                    'source_blog_id' => $source_blog_id,
                    'taxonomy' => $taxonomy,
                ]);
                return false;
            }

            return true;
        }

        private function sync_one_target_term($source_site, $source_term, $target, $event = 'sync', $stack = [])
        {
            global $wpdb;
            if (!$source_term instanceof WP_Term) {
                return new WP_Error('wpmu_ml_source_term_invalid', '源 term 参数无效。');
            }

            $source_blog_id = absint($source_site['blog_id'] ?? 0);
            $source_term_id = absint($source_term->term_id);
            $taxonomy = sanitize_key((string)$source_term->taxonomy);
            $target_blog_id = absint($target['blog_id'] ?? 0);
            if (!$source_blog_id || !$source_term_id || !$target_blog_id || $taxonomy === '') {
                return new WP_Error('wpmu_ml_term_sync_args', 'term 同步参数不完整。');
            }

            $stack_key = $source_blog_id . ':' . $source_term_id . ':' . $taxonomy . ':' . $target_blog_id;
            if (isset($stack[$stack_key])) {
                return new WP_Error('wpmu_ml_term_sync_parent_loop', 'term 父级关系出现循环，已停止同步。');
            }
            $stack[$stack_key] = true;

            switch_to_blog($target_blog_id);
            $taxonomy_exists = taxonomy_exists($taxonomy);
            $taxonomy_object = $taxonomy_exists ? get_taxonomy($taxonomy) : null;
            restore_current_blog();
            if (!$taxonomy_exists) {
                $this->log('warning', 'term_sync_skipped', '目标站 taxonomy 不存在，已跳过。', [
                    'source_blog_id' => $source_blog_id,
                    'source_term_id' => $source_term_id,
                    'target_blog_id' => $target_blog_id,
                    'taxonomy' => $taxonomy,
                ]);
                return new WP_Error('wpmu_ml_target_taxonomy_missing', '目标站 taxonomy 不存在。');
            }

            $parent_target_id = 0;
            if ($taxonomy_object && !empty($taxonomy_object->hierarchical) && !empty($source_term->parent)) {
                switch_to_blog($source_blog_id);
                $source_parent = get_term(absint($source_term->parent), $taxonomy);
                restore_current_blog();
                if ($source_parent && !is_wp_error($source_parent)) {
                    $parent_result = $this->sync_one_target_term($source_site, $source_parent, $target, 'parent', $stack);
                    if (is_wp_error($parent_result)) {
                        return $parent_result;
                    }
                    $parent_target_id = absint($parent_result['target_term_id'] ?? 0);
                }
            }

            $relation = $this->get_term_relation($source_blog_id, $source_term_id, $taxonomy, $target_blog_id);
            $target_term_id = $relation ? absint($relation['target_term_id'] ?? 0) : 0;
            $resolution = $relation ? 'relation' : 'create';
            $repaired = false;

            switch_to_blog($target_blog_id);
            $target_term = $target_term_id ? get_term($target_term_id, $taxonomy) : null;
            if (!$target_term || is_wp_error($target_term)) {
                $target_term_id = 0;
                $target_term = null;
                if ($relation) {
                    $repaired = true;
                    $resolution = 'relation_target_missing';
                }
            }

            if (!$target_term && (string)$source_term->slug !== '') {
                $maybe = get_term_by('slug', (string)$source_term->slug, $taxonomy);
                if ($maybe && !is_wp_error($maybe)) {
                    $target_term = $maybe;
                    $target_term_id = absint($maybe->term_id);
                    $resolution = 'adopt_by_slug';
                    $repaired = true;
                }
            }

            if (!$target_term) {
                $same_id = get_term($source_term_id, $taxonomy);
                if ($same_id && !is_wp_error($same_id)) {
                    $target_term = $same_id;
                    $target_term_id = absint($same_id->term_id);
                    $resolution = 'adopt_by_same_id';
                    $repaired = true;
                }
            }

            $args = [
                'description' => (string)$source_term->description,
                'slug' => (string)$source_term->slug,
            ];
            if ($taxonomy_object && !empty($taxonomy_object->hierarchical)) {
                $args['parent'] = $parent_target_id;
            }

            $created = false;
            if ($target_term) {
                $args['name'] = (string)$source_term->name;
                $written = wp_update_term($target_term_id, $taxonomy, wp_slash($args));
            } else {
                $written = wp_insert_term((string)$source_term->name, $taxonomy, wp_slash($args));
                if (is_wp_error($written) && $written->get_error_code() === 'term_exists') {
                    $error_data = $written->get_error_data();
                    $existing_id = is_array($error_data) ? absint($error_data['term_id'] ?? 0) : absint($error_data);
                    if ($existing_id) {
                        $target_term_id = $existing_id;
                        $args['name'] = (string)$source_term->name;
                        $written = wp_update_term($target_term_id, $taxonomy, wp_slash($args));
                        $resolution = 'adopt_by_term_exists';
                        $repaired = true;
                    }
                }
                if (!is_wp_error($written)) {
                    $target_term_id = absint($written['term_id'] ?? $target_term_id);
                    $created = $resolution === 'create';
                }
            }
            restore_current_blog();

            if (is_wp_error($written) || !$target_term_id) {
                $this->log('error', 'term_sync_error', '写入目标 term 失败。', [
                    'source_blog_id' => $source_blog_id,
                    'source_term_id' => $source_term_id,
                    'target_blog_id' => $target_blog_id,
                    'taxonomy' => $taxonomy,
                    'event' => $event,
                    'resolution' => $resolution,
                    'error' => is_wp_error($written) ? $written->get_error_message() : 'target_term_id empty',
                ]);
                return new WP_Error('wpmu_ml_target_term_write_failed', '写入目标 term 失败。');
            }

            $this->upsert_term_relation(
                $source_blog_id,
                $source_term_id,
                $taxonomy,
                $target_blog_id,
                $target_term_id,
                sanitize_key((string)($source_site['lang_slug'] ?? '')),
                sanitize_key((string)($target['lang_slug'] ?? '')),
                'synced'
            );

            if ($repaired) {
                $this->log('info', 'term_sync_relation_repaired', 'term 关系已修复。', [
                    'source_blog_id' => $source_blog_id,
                    'source_term_id' => $source_term_id,
                    'target_blog_id' => $target_blog_id,
                    'target_term_id' => $target_term_id,
                    'taxonomy' => $taxonomy,
                    'resolution' => $resolution,
                ]);
            }

            return [
                'target_term_id' => $target_term_id,
                'created' => $created ? 1 : 0,
                'repaired' => $repaired ? 1 : 0,
                'resolution' => $resolution,
            ];
        }

        private function get_term_relation($source_blog_id, $source_term_id, $taxonomy, $target_blog_id)
        {
            global $wpdb;
            $source_blog_id = absint($source_blog_id);
            $source_term_id = absint($source_term_id);
            $target_blog_id = absint($target_blog_id);
            $taxonomy = sanitize_key((string)$taxonomy);
            if (!$source_blog_id || !$source_term_id || !$target_blog_id || $taxonomy === '') {
                return null;
            }
            return $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->tables['terms']}
                 WHERE source_blog_id = %d AND source_term_id = %d AND source_taxonomy = %s AND target_blog_id = %d
                 LIMIT 1",
                $source_blog_id,
                $source_term_id,
                $taxonomy,
                $target_blog_id
            ), ARRAY_A);
        }

        private function upsert_term_relation($source_blog_id, $source_term_id, $taxonomy, $target_blog_id, $target_term_id, $source_lang = '', $target_lang = '', $status = 'synced')
        {
            global $wpdb;
            $source_blog_id = absint($source_blog_id);
            $source_term_id = absint($source_term_id);
            $target_blog_id = absint($target_blog_id);
            $target_term_id = absint($target_term_id);
            $taxonomy = sanitize_key((string)$taxonomy);
            if (!$source_blog_id || !$source_term_id || !$target_blog_id || !$target_term_id || $taxonomy === '') {
                return false;
            }

            $existing = $this->get_term_relation($source_blog_id, $source_term_id, $taxonomy, $target_blog_id);
            $data = [
                'source_blog_id' => $source_blog_id,
                'source_term_id' => $source_term_id,
                'source_taxonomy' => $taxonomy,
                'target_blog_id' => $target_blog_id,
                'target_term_id' => $target_term_id,
                'target_taxonomy' => $taxonomy,
                'source_lang' => sanitize_key((string)$source_lang),
                'target_lang' => sanitize_key((string)$target_lang),
                'relation_status' => sanitize_key((string)$status),
                'updated_at' => current_time('mysql'),
            ];
            $formats = ['%d','%d','%s','%d','%d','%s','%s','%s','%s','%s'];

            if ($existing) {
                return false !== $wpdb->update($this->tables['terms'], $data, ['id' => absint($existing['id'])], $formats, ['%d']);
            }

            $data['created_at'] = current_time('mysql');
            return false !== $wpdb->insert($this->tables['terms'], $data, array_merge($formats, ['%s']));
        }

        private function map_source_term_ids_to_target_ids($source_site, $taxonomy, $term_ids, $target)
        {
            $taxonomy = sanitize_key((string)$taxonomy);
            $out = [];
            if ($taxonomy === '') {
                return $out;
            }

            $source_blog_id = absint($source_site['blog_id'] ?? 0);
            if (!$source_blog_id) {
                return $out;
            }

            foreach (array_values(array_unique(array_map('absint', (array)$term_ids))) as $source_term_id) {
                if (!$source_term_id) {
                    continue;
                }
                switch_to_blog($source_blog_id);
                $source_term = get_term($source_term_id, $taxonomy);
                restore_current_blog();
                if (!$source_term || is_wp_error($source_term)) {
                    continue;
                }

                $result = $this->sync_one_target_term($source_site, $source_term, $target, 'relationship', []);
                if (is_wp_error($result)) {
                    $this->log('warning', 'term_sync_error', '文章分类关系映射目标 term 失败。', [
                        'source_blog_id' => $source_blog_id,
                        'source_term_id' => $source_term_id,
                        'target_blog_id' => absint($target['blog_id'] ?? 0),
                        'taxonomy' => $taxonomy,
                        'error' => $result->get_error_message(),
                    ]);
                    continue;
                }
                $target_term_id = absint($result['target_term_id'] ?? 0);
                if ($target_term_id) {
                    $out[] = $target_term_id;
                }
            }

            return array_values(array_unique($out));
        }
    }
}
