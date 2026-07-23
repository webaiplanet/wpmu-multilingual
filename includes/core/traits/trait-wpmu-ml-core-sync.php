<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 文章关系、同步、目标文章写入与入队。
 *
 * 本 trait 仅用于拆分历史 WPMU_Multilingual 大类；方法签名和可见性保持不变。
 */
if (!trait_exists('WPMU_ML_Core_Sync_Trait')) {
    trait WPMU_ML_Core_Sync_Trait {
    public function get_site_language_urls() {
        $items = [];
        foreach ($this->get_i18n_sites(true) as $site) {
            $blog_id = absint($site['blog_id'] ?? 0);
            if (!$blog_id) {
                continue;
            }
            $url = get_home_url($blog_id, '/');
            if (!$url) {
                continue;
            }
            $items[] = [
                'blog_id' => $blog_id,
                'post_id' => 0,
                'lang_slug' => sanitize_key((string)($site['lang_slug'] ?? '')),
                'hreflang' => $this->normalize_hreflang((string)($site['hreflang'] ?? '')),
                'url' => $url,
            ];
        }
        return $items;
    }

    public function rebuild_relations() {
        return new WP_Error(
            'wpmu_ml_relation_rebuild_disabled',
            '生产关系重建已禁用。请先运行只读关系审计；0.9.8 不再清空关系表后按 ID 或 slug 猜测关联。'
        );
    }

    public function get_post_relation($source_blog_id, $source_post_id, $target_blog_id) {
        global $wpdb;
        $source_blog_id = absint($source_blog_id);
        $source_post_id = absint($source_post_id);
        $target_blog_id = absint($target_blog_id);
        if (!$source_blog_id || !$source_post_id || !$target_blog_id) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['posts']} WHERE source_blog_id = %d AND source_post_id = %d AND target_blog_id = %d LIMIT 1",
            $source_blog_id,
            $source_post_id,
            $target_blog_id
        ), ARRAY_A) ?: null;
    }

    public function find_relation_by_target($target_blog_id, $target_post_id) {
        global $wpdb;
        $target_blog_id = absint($target_blog_id);
        $target_post_id = absint($target_post_id);
        if (!$target_blog_id || !$target_post_id) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['posts']} WHERE target_blog_id = %d AND target_post_id = %d ORDER BY id ASC LIMIT 1",
            $target_blog_id,
            $target_post_id
        ), ARRAY_A) ?: null;
    }

    private function sql_in($items) {
        global $wpdb;
        $items = array_filter(array_map('sanitize_key', (array)$items));
        if (!$items) {
            return '';
        }
        return implode(',', array_map(function($item) use ($wpdb) {
            return "'" . esc_sql($item) . "'";
        }, $items));
    }

    public function maybe_mark_target_post_translated($new_status, $old_status, $post) {
        static $running = false;
        if ($running || $new_status !== 'publish' || !$post || empty($post->ID)) {
            return;
        }
        $settings = $this->get_settings();
        $source_blog_id = absint($settings['source_blog_id']);
        $current_blog_id = get_current_blog_id();
        if (!$source_blog_id || $current_blog_id === $source_blog_id) {
            return;
        }
        if (!$this->is_managed_post_type($post->post_type)) {
            return;
        }
        global $wpdb;
        $relation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['posts']} WHERE target_blog_id = %d AND target_post_id = %d LIMIT 1",
            $current_blog_id,
            (int)$post->ID
        ), ARRAY_A);
        if (!$relation || $this->is_shared_post_type($relation['post_type'])) {
            return;
        }
        $identity = $this->validate_post_relation($relation, true);
        if (empty($identity['valid'])) {
            $this->mark_relation_invalid((int)$relation['id'], (string)$identity['error_code'], (string)$identity['message'], [
                'target_blog_id' => $current_blog_id,
                'target_post_id' => (int)$post->ID,
                'action' => 'target_publish',
            ]);
            return;
        }
        $running = true;
        $wpdb->update($this->tables['posts'], [
            'target_post_status' => 'publish',
            'relation_status' => 'translated',
            'target_modified' => $post->post_modified ?: current_time('mysql'),
        ], ['id' => (int)$relation['id']], ['%s','%s','%s'], ['%d']);
        $related_jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT id, engine, status FROM {$this->tables['jobs']}
             WHERE source_blog_id = %d
               AND source_post_id = %d
               AND target_blog_id = %d
               AND target_post_id = %d",
            (int)$relation['source_blog_id'],
            (int)$relation['source_post_id'],
            (int)$relation['target_blog_id'],
            (int)$relation['target_post_id']
        ), ARRAY_A);
        $updated_jobs = [];
        foreach ((array)$related_jobs as $related_job) {
            $done_status = $this->get_done_status_for_engine($related_job['engine'] ?? '', 'publish');
            $wpdb->update($this->tables['jobs'], [
                'status' => $done_status,
                'locked_at' => null,
                'locked_by' => '',
                'process_after' => null,
                'finished_at' => current_time('mysql'),
                'last_error' => null,
                'updated_at' => current_time('mysql'),
            ], ['id' => (int)$related_job['id']], ['%s','%s','%s','%s','%s','%s','%s'], ['%d']);
            $updated_jobs[] = [
                'job_id' => (int)$related_job['id'],
                'engine' => sanitize_key($related_job['engine'] ?? ''),
                'status' => $done_status,
            ];
        }
        $this->log('info', 'target_published_translated', '目标语言文章发布后自动标记为已翻译', [
            'target_blog_id' => $current_blog_id,
            'target_post_id' => (int)$post->ID,
            'source_post_id' => (int)$relation['source_post_id'],
            'updated_jobs' => $updated_jobs,
        ]);
        $running = false;
    }

    private function get_done_status_for_engine($engine, $target_post_status = '') {
        $engine = sanitize_key($engine);
        $target_post_status = sanitize_key($target_post_status);
        $published = ($target_post_status === 'publish');
        if ($engine === 'agent') {
            return $published ? 'agent_done_published' : 'agent_translated';
        }
        if ($engine === 'openai') {
            return $published ? 'machine_done_published' : 'machine_translated';
        }
        if ($this->is_opencc_engine($engine)) {
            return $published ? 'opencc_done_published' : 'opencc_converted';
        }
        if ($engine === 'manual') {
            return 'manual_done';
        }
        return $published ? 'translated' : 'machine_translated';
    }

    private function get_target_post_status_from_job($job) {
        $status = '';
        if (!empty($job['target_blog_id']) && !empty($job['target_post_id'])) {
            switch_to_blog((int)$job['target_blog_id']);
            $status = get_post_status((int)$job['target_post_id']) ?: '';
            restore_current_blog();
        }
        return $status;
    }

    private function update_relation_for_job($job, $relation_status) {
        global $wpdb;
        $target_status = $this->get_target_post_status_from_job($job);
        $wpdb->update($this->tables['posts'], [
            'relation_status' => sanitize_key($relation_status),
            'target_post_status' => $target_status ?: null,
            'target_modified' => current_time('mysql'),
        ], [
            'source_blog_id' => (int)$job['source_blog_id'],
            'source_post_id' => (int)$job['source_post_id'],
            'target_blog_id' => (int)$job['target_blog_id'],
        ], ['%s','%s','%s'], ['%d','%d','%d']);
    }

    private function mark_translation_job_done($job, $status = 'manual_done') {
        global $wpdb;
        $wpdb->update($this->tables['jobs'], [
            'status' => sanitize_key($status),
            'locked_at' => null,
            'locked_by' => '',
            'process_after' => null,
            'finished_at' => current_time('mysql'),
            'last_error' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => (int)$job['id']], ['%s','%s','%s','%s','%s','%s','%s'], ['%d']);
        $this->update_relation_for_job($job, 'translated');
        $this->mark_translation_content_completed($job);
        $this->log('info', 'translation_manual_done', '翻译任务已人工标记完成并清理锁', ['job_id' => (int)$job['id']]);
    }

    private function mark_translation_job_machine_pending($job) {
        global $wpdb;
        $engine = $this->get_translation_engine_for_lang($job['target_lang'], $job['engine']);
        $wpdb->update($this->tables['jobs'], [
            'engine' => $engine,
            'status' => 'machine_pending',
            'attempts' => 0,
            'locked_at' => null,
            'locked_by' => '',
            'process_after' => null,
            'last_error' => '任务已切换为当前语言配置的翻译方式，等待队列处理。',
            'started_at' => null,
            'finished_at' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => (int)$job['id']], ['%s','%s','%d','%s','%s','%s','%s','%s','%s','%s'], ['%d']);
        $fallback_status = $this->get_target_post_status_from_job($job) === 'publish' ? 'needs_update' : 'needs_translation';
        $this->update_relation_for_job($job, $this->translation_job_pending_relation_status($job, $fallback_status));
        $this->log('info', 'translation_machine_pending', '翻译任务已标记为待机器翻译并清理锁', ['job_id' => (int)$job['id'], 'engine' => $engine]);
    }

    private function mark_translation_job_retranslate($job) {
        global $wpdb;
        $fallback_status = $this->get_target_post_status_from_job($job) === 'publish' ? 'needs_update' : 'needs_translation';
        $relation_status = $this->translation_job_pending_relation_status($job, $fallback_status);
        $wpdb->update($this->tables['jobs'], [
            'status' => 'pending',
            'attempts' => 0,
            'locked_at' => null,
            'locked_by' => '',
            'process_after' => null,
            'last_error' => null,
            'started_at' => null,
            'finished_at' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => (int)$job['id']], ['%s','%d','%s','%s','%s','%s','%s','%s','%s'], ['%d']);
        $this->update_relation_for_job($job, $relation_status);
        $this->log('info', 'translation_retranslate', '翻译任务已重新排队并清理锁', ['job_id' => (int)$job['id'], 'relation_status' => $relation_status]);
    }

    public function maybe_auto_sync_source_post($post_id, $post, $update) {
        static $running = false;
        if ($running) {
            return;
        }
        $settings = $this->get_settings();
        if (empty($settings['auto_sync_enabled'])) {
            return;
        }
        $source_blog_id = absint($settings['source_blog_id']);
        if (!$source_blog_id || get_current_blog_id() !== $source_blog_id) {
            return;
        }
        if ($update && empty($settings['auto_sync_on_update'])) {
            return;
        }
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if (!$post || empty($post->post_type) || in_array($post->post_status, ['auto-draft', 'trash'], true)) {
            return;
        }
        if (!$this->is_managed_post_type($post->post_type)) {
            return;
        }
        $running = true;
        $this->sync_source_post_to_targets($post_id, false);
        $running = false;
    }

    public function maybe_sync_source_post_trashed($post_id) {
        $this->maybe_sync_source_post_removed($post_id, 'trash');
    }

    public function maybe_sync_source_post_deleted($post_id) {
        $this->maybe_sync_source_post_removed($post_id, 'delete');
    }

    public function maybe_sync_source_post_untrashed($post_id) {
        static $running = false;
        if ($running) {
            return;
        }
        $settings = $this->get_settings();
        if (empty($settings['restore_sync_enabled'])) {
            return;
        }
        $source_blog_id = absint($settings['source_blog_id']);
        if (!$source_blog_id || get_current_blog_id() !== $source_blog_id) {
            return;
        }
        $post = get_post($post_id);
        if (!$post || !$this->is_managed_post_type($post->post_type)) {
            return;
        }
        $running = true;
        $this->sync_source_post_restore_to_targets_secure($post_id);
        $running = false;
    }

    private function maybe_sync_source_post_removed($post_id, $mode) {
        static $running = false;
        if ($running) {
            return;
        }
        $settings = $this->get_settings();
        $source_blog_id = absint($settings['source_blog_id']);
        if (!$source_blog_id || get_current_blog_id() !== $source_blog_id) {
            return;
        }
        $policy_key = $mode === 'delete' ? 'delete_sync_policy' : 'trash_sync_policy';
        $policy = $settings[$policy_key] ?? 'drafts_only';
        if ($policy === 'none') {
            $this->mark_source_removed_relations($post_id, $mode, true);
            return;
        }
        $post = get_post($post_id);
        if (!$post || !$this->is_managed_post_type($post->post_type)) {
            return;
        }
        $running = true;
        $this->sync_source_post_remove_to_targets_secure($post_id, $mode, $policy);
        $running = false;
    }

    private function get_post_relations_for_source($source_post_id) {
        global $wpdb;
        $settings = $this->get_settings();
        $source_blog_id = absint($settings['source_blog_id']);
        if (!$source_blog_id) {
            return [];
        }
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->tables['posts']} WHERE source_blog_id = %d AND source_post_id = %d ORDER BY target_blog_id ASC",
            $source_blog_id,
            $source_post_id
        ), ARRAY_A);
    }

    private function target_relation_is_protected($relation, $target_post) {
        if (!$target_post) {
            return false;
        }
        if ($this->is_shared_post_type($relation['post_type'])) {
            return false;
        }
        if (in_array($relation['relation_status'], ['translated','needs_update','translated_update_pending'], true)) {
            return true;
        }
        return $target_post->post_status === 'publish';
    }

    private function mark_source_removed_relations($source_post_id, $mode, $touch_jobs = true) {
        global $wpdb;
        $relations = $this->get_post_relations_for_source($source_post_id);
        foreach ($relations as $relation) {
            $wpdb->update($this->tables['posts'], [
                'relation_status' => $mode === 'delete' ? 'source_deleted_keep' : 'source_trashed_keep',
            ], ['id' => (int)$relation['id']]);
        }
        if ($touch_jobs) {
            $settings = $this->get_settings();
            $wpdb->update($this->tables['jobs'], [
                'status' => $mode === 'delete' ? 'source_deleted' : 'source_trashed',
            ], [
                'source_blog_id' => absint($settings['source_blog_id']),
                'source_post_id' => (int)$source_post_id,
            ]);
        }
    }

    private function is_managed_post_type($post_type) {
        $settings = $this->get_settings();
        $types = array_values(array_unique(array_merge((array)$settings['translatable_post_types'], (array)$settings['shared_post_types'])));
        return in_array($post_type, $types, true);
    }

    private function is_shared_post_type($post_type) {
        $settings = $this->get_settings();
        return in_array($post_type, (array)$settings['shared_post_types'], true);
    }

    public function batch_sync_recent_source_posts($limit = 20) {
        $settings = $this->get_settings();
        $source_blog_id = absint($settings['source_blog_id']);
        if (!$source_blog_id) {
            return ['processed' => 0, 'targets' => 0, 'queued' => 0];
        }
        $types = array_values(array_unique(array_merge((array)$settings['translatable_post_types'], (array)$settings['shared_post_types'])));
        if (!$types) {
            return ['processed' => 0, 'targets' => 0, 'queued' => 0];
        }
        switch_to_blog($source_blog_id);
        $posts = get_posts([
            'post_type' => $types,
            'post_status' => ['publish','draft','pending','private','future'],
            'numberposts' => absint($limit),
            'orderby' => 'modified',
            'order' => 'DESC',
            'fields' => 'ids',
            'suppress_filters' => true,
        ]);
        restore_current_blog();

        $result = ['processed' => 0, 'targets' => 0, 'queued' => 0];
        foreach ($posts as $pid) {
            $r = $this->sync_source_post_to_targets((int)$pid, false);
            $result['processed']++;
            $result['targets'] += intval($r['targets'] ?? 0);
            $result['queued'] += intval($r['queued'] ?? 0);
        }
        return $result;
    }

    public function sync_source_post_to_targets($source_post_id, $force = false) {
        global $wpdb;
        $settings = $this->get_settings();
        $source_blog_id = absint($settings['source_blog_id']);
        if (!$source_blog_id) {
            return ['targets' => 0, 'queued' => 0];
        }
        $source_site = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['sites']} WHERE blog_id = %d", $source_blog_id), ARRAY_A);
        if (!$source_site) {
            return ['targets' => 0, 'queued' => 0];
        }

        switch_to_blog($source_blog_id);
        $source_post = get_post($source_post_id);
        if (!$source_post || !$this->is_managed_post_type($source_post->post_type) || in_array($source_post->post_status, ['auto-draft','trash'], true)) {
            restore_current_blog();
            return ['targets' => 0, 'queued' => 0];
        }
        $source_meta = get_post_meta($source_post_id);
        $source_terms = $this->collect_source_terms($source_post);
        $previous_snapshot = $this->decode_source_field_snapshot(
            get_post_meta($source_post_id, $this->incremental_snapshot_meta_key(), true)
        );
        $current_snapshot = $this->build_source_field_snapshot($source_post, $source_meta);
        $source_change_context = [
            'previous_snapshot' => $previous_snapshot,
            'current_snapshot' => $current_snapshot,
            'changes' => $this->diff_source_field_snapshots($previous_snapshot, $current_snapshot),
        ];
        restore_current_blog();

        $targets = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['sites']} WHERE enabled = 1 AND blog_id <> %d ORDER BY sort_order ASC", $source_blog_id), ARRAY_A);
        $done = 0;
        $queued = 0;
        $failed = 0;
        foreach ($targets as $target) {
            $r = $this->sync_one_target_secure($source_site, $source_post, $source_meta, $source_terms, $target, $force, $source_change_context);
            if (!empty($r['target_post_id'])) {
                $done++;
            }
            if (!empty($r['queued'])) {
                $queued++;
            }
            if (!empty($r['error'])) {
                $failed++;
            }
        }
        if ($failed === 0) {
            switch_to_blog($source_blog_id);
            update_post_meta(
                $source_post_id,
                $this->incremental_snapshot_meta_key(),
                wp_json_encode($current_snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
            restore_current_blog();
        }
        return ['targets' => $done, 'queued' => $queued, 'failed' => $failed];
    }

    private function collect_source_terms($source_post) {
        $out = [];
        $taxonomies = get_object_taxonomies($source_post->post_type);
        $sync_taxonomies = $this->get_effective_sync_taxonomies($taxonomies);
        foreach ($taxonomies as $tax) {
            if (!in_array($tax, $sync_taxonomies, true)) {
                continue;
            }
            $ids = wp_get_object_terms($source_post->ID, $tax, ['fields' => 'ids']);
            if (!is_wp_error($ids)) {
                $out[$tax] = array_map('intval', $ids);
            }
        }
        return $out;
    }

    private function sync_one_target($source_site, $source_post, $source_meta, $source_terms, $target, $force = false) {
        return $this->sync_one_target_secure($source_site, $source_post, $source_meta, $source_terms, $target, $force, []);
    }

    private function stamp_target_source_meta($target_post_id, $source_blog_id, $source_post_id, $source_lang = '', $target_lang = '') {
        $target_post_id = absint($target_post_id);
        $source_blog_id = absint($source_blog_id);
        $source_post_id = absint($source_post_id);
        if (!$target_post_id || !$source_blog_id || !$source_post_id) {
            return;
        }
        update_post_meta($target_post_id, '_wpmu_ml_source_blog_id', (string)$source_blog_id);
        update_post_meta($target_post_id, '_wpmu_ml_source_post_id', (string)$source_post_id);
        if ($source_lang !== '') {
            update_post_meta($target_post_id, '_wpmu_ml_source_lang', sanitize_key($source_lang));
        }
        if ($target_lang !== '') {
            update_post_meta($target_post_id, '_wpmu_ml_target_lang', sanitize_key($target_lang));
        }
        update_post_meta($target_post_id, '_wpmu_ml_relation_version', '2');
    }

    private function replace_target_post_meta($target_post_id, $source_meta) {
        $skip = ['_edit_lock', '_edit_last'];
        foreach ($source_meta as $key => $values) {
            if (in_array($key, $skip, true)) {
                continue;
            }
            delete_post_meta($target_post_id, $key);
            foreach ((array)$values as $value) {
                add_post_meta($target_post_id, $key, maybe_unserialize($value));
            }
        }
    }

    private function replace_target_post_meta_keys($target_post_id, $source_meta, $meta_keys, $deleted_keys = []) {
        foreach (array_values(array_unique(array_merge((array)$meta_keys, (array)$deleted_keys))) as $key) {
            $key = (string)$key;
            if ($key === '' || $this->incremental_sync_meta_is_internal($key)) {
                continue;
            }
            delete_post_meta($target_post_id, $key);
            if (in_array($key, (array)$deleted_keys, true) || !array_key_exists($key, (array)$source_meta)) {
                continue;
            }
            foreach ((array)$source_meta[$key] as $value) {
                add_post_meta($target_post_id, $key, maybe_unserialize($value));
            }
        }
    }

    private function enqueue_translation_job($source_site, $source_post, $target, $target_post_id, $status = 'pending', $manifest = []) {
        global $wpdb;
        $route = $this->resolve_translation_route($target['lang_slug'], $source_post->post_type);
        $engine = $route['engine'];
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->tables['jobs']} WHERE source_blog_id = %d AND source_post_id = %d AND target_blog_id = %d LIMIT 1",
            (int)$source_site['blog_id'],
            (int)$source_post->ID,
            (int)$target['blog_id']
        ));
        $data = [
            'source_blog_id' => (int)$source_site['blog_id'],
            'source_post_id' => (int)$source_post->ID,
            'target_blog_id' => (int)$target['blog_id'],
            'target_post_id' => (int)$target_post_id,
            'source_lang' => $source_site['lang_slug'],
            'target_lang' => $target['lang_slug'],
            'post_type' => $source_post->post_type,
            'engine' => $engine,
            'model' => (string)($route['model'] ?? ''),
            'route_reason' => (string)($route['route_reason'] ?? ''),
            'route_profile' => (string)($route['route_profile'] ?? ''),
            'complete_status' => (string)($route['complete_status'] ?? ''),
            'status' => sanitize_key($status),
            'job_type' => (string)($manifest['mode'] ?? 'full_translate'),
            'change_manifest' => wp_json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'priority' => 10,
        ];
        if ($exists) {
            $data['attempts'] = 0;
            $data['locked_at'] = null;
            $data['locked_by'] = '';
            $data['process_after'] = null;
            $data['last_error'] = '源站更新，重新加入翻译队列。';
            $data['started_at'] = null;
            $data['finished_at'] = null;
            $data['updated_at'] = current_time('mysql');
            $wpdb->update($this->tables['jobs'], $data, ['id' => (int)$exists]);
            return 1;
        }
        $wpdb->insert($this->tables['jobs'], $data);
        return $wpdb->insert_id ? 1 : 0;
    }

    public function maybe_mark_target_post_trashed($post_id) {
        $this->maybe_mark_target_lifecycle_status_secure($post_id, 'trashed');
    }

    public function maybe_mark_target_post_deleted($post_id) {
        $this->maybe_mark_target_lifecycle_status_secure($post_id, 'deleted');
    }

    public function maybe_mark_target_post_untrashed($post_id) {
        $this->maybe_mark_target_lifecycle_status_secure($post_id, 'untrashed');
    }

    }
}
