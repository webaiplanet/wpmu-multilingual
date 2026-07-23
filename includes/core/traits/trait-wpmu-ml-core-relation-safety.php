<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Article relation identity, audit and strict recovery safeguards.
 */
if (!trait_exists('WPMU_ML_Core_Relation_Safety_Trait')) {
    trait WPMU_ML_Core_Relation_Safety_Trait {
    private function relation_validation_result($overrides = []) {
        return array_merge([
            'valid' => false,
            'status' => 'relation_invalid',
            'error_code' => 'relation_incomplete',
            'message' => '文章关系参数不完整。',
            'relation' => null,
            'target_post' => null,
        ], is_array($overrides) ? $overrides : []);
    }

    public function find_target_posts_by_source_meta($source_blog_id, $source_post_id, $post_type, $limit = 2) {
        $source_blog_id = absint($source_blog_id);
        $source_post_id = absint($source_post_id);
        $post_type = sanitize_key((string)$post_type);
        $limit = max(1, min(20, absint($limit)));
        if (!$source_blog_id || !$source_post_id || $post_type === '') {
            return [];
        }
        $ids = get_posts([
            'post_type' => $post_type,
            'post_status' => ['publish','draft','pending','private','future','trash'],
            'numberposts' => $limit,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_wpmu_ml_source_blog_id',
                    'value' => (string)$source_blog_id,
                    'compare' => '=',
                ],
                [
                    'key' => '_wpmu_ml_source_post_id',
                    'value' => (string)$source_post_id,
                    'compare' => '=',
                ],
            ],
        ]);
        return array_values(array_map('intval', (array)$ids));
    }

    public function validate_target_post_identity($source_blog_id, $source_post_id, $target_blog_id, $target_post_id, $post_type = '', $allow_legacy_relation = false, $allow_trashed_target = false) {
        global $wpdb;
        $source_blog_id = absint($source_blog_id);
        $source_post_id = absint($source_post_id);
        $target_blog_id = absint($target_blog_id);
        $target_post_id = absint($target_post_id);
        $post_type = sanitize_key((string)$post_type);
        $base = $this->relation_validation_result();
        if (!$source_blog_id || !$source_post_id || !$target_blog_id || !$target_post_id) {
            return $base;
        }

        $relation = $this->get_post_relation($source_blog_id, $source_post_id, $target_blog_id);
        $base['relation'] = $relation;
        if ($relation && (int)$relation['target_post_id'] !== $target_post_id) {
            $base['error_code'] = 'relation_target_mismatch';
            $base['message'] = '关系表指向的目标文章与本次写入目标不一致。';
            return $base;
        }
        $other_claim = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['posts']}
             WHERE target_blog_id = %d AND target_post_id = %d
               AND NOT (source_blog_id = %d AND source_post_id = %d)
             ORDER BY id ASC LIMIT 1",
            $target_blog_id,
            $target_post_id,
            $source_blog_id,
            $source_post_id
        ), ARRAY_A);
        if ($other_claim) {
            $base['status'] = 'target_identity_conflict';
            $base['error_code'] = 'target_already_claimed';
            $base['message'] = '目标文章已被另一个源文章关系占用。';
            $base['conflicting_relation'] = $other_claim;
            return $base;
        }

        switch_to_blog($target_blog_id);
        try {
            $target_post = get_post($target_post_id);
            if (!$target_post instanceof WP_Post) {
                $base['status'] = 'target_missing';
                $base['error_code'] = 'target_missing';
                $base['message'] = '关系表指向的目标文章不存在。';
                return $base;
            }
            $base['target_post'] = $target_post;
            $relation_status = $relation ? (string)($relation['relation_status'] ?? '') : '';
            if (!$allow_trashed_target && (string)$target_post->post_status === 'trash' && $relation_status !== 'source_trashed') {
                $base['error_code'] = 'target_status_trashed';
                $base['message'] = '目标文章位于回收站，且不是由当前源站回收同步产生。';
                return $base;
            }
            if ($post_type !== '' && sanitize_key((string)$target_post->post_type) !== $post_type) {
                $base['status'] = 'target_identity_conflict';
                $base['error_code'] = 'target_post_type_conflict';
                $base['message'] = '目标文章类型与关系记录不一致。';
                return $base;
            }

            $meta_source_blog_id = trim((string)get_post_meta($target_post_id, '_wpmu_ml_source_blog_id', true));
            $meta_source_post_id = trim((string)get_post_meta($target_post_id, '_wpmu_ml_source_post_id', true));
            $blog_meta_missing = $meta_source_blog_id === '';
            $post_meta_missing = $meta_source_post_id === '';
            if ($blog_meta_missing && $post_meta_missing) {
                $identity_blocking_statuses = ['target_missing','target_identity_conflict','relation_invalid'];
                if ($allow_legacy_relation && $relation && !in_array($relation_status, $identity_blocking_statuses, true)) {
                    return array_merge($base, [
                        'valid' => true,
                        'status' => 'legacy_relation',
                        'error_code' => '',
                        'message' => '历史关系有效，目标文章来源 meta 尚未补写。',
                    ]);
                }
                $base['status'] = 'target_identity_conflict';
                $base['error_code'] = 'target_identity_missing';
                $base['message'] = '目标文章缺少来源身份 meta，且没有可兼容的既有关系。';
                return $base;
            }
            if ($blog_meta_missing || $post_meta_missing) {
                $base['status'] = 'target_identity_conflict';
                $base['error_code'] = 'target_identity_partial';
                $base['message'] = '目标文章的来源身份 meta 不完整。';
                return $base;
            }
            if ((int)$meta_source_blog_id !== $source_blog_id || (int)$meta_source_post_id !== $source_post_id) {
                $base['status'] = 'target_identity_conflict';
                $base['error_code'] = 'target_identity_conflict';
                $base['message'] = '目标文章来源 meta 指向另一个源文章。';
                return $base;
            }
            return array_merge($base, [
                'valid' => true,
                'status' => 'strict_identity',
                'error_code' => '',
                'message' => '目标文章身份校验通过。',
            ]);
        } finally {
            restore_current_blog();
        }
    }

    public function validate_post_relation($relation, $allow_legacy_relation = true, $allow_lifecycle_recovery = false) {
        if (!is_array($relation)) {
            return $this->relation_validation_result(['error_code' => 'relation_missing', 'message' => '文章关系不存在。']);
        }
        foreach (['id','source_blog_id','source_post_id','target_blog_id','target_post_id'] as $key) {
            if (empty($relation[$key])) {
                return $this->relation_validation_result(['relation' => $relation]);
            }
        }
        $current = $this->get_post_relation($relation['source_blog_id'], $relation['source_post_id'], $relation['target_blog_id']);
        if (!$current || (int)$current['id'] !== (int)$relation['id'] || (int)$current['target_post_id'] !== (int)$relation['target_post_id']) {
            return $this->relation_validation_result([
                'error_code' => 'relation_target_mismatch',
                'message' => '关系表记录不存在或已发生变化。',
                'relation' => $current ?: $relation,
            ]);
        }
        if (!$allow_lifecycle_recovery && in_array((string)$current['relation_status'], ['target_deleted','target_trashed'], true)) {
            return $this->relation_validation_result([
                'error_code' => 'target_lifecycle_blocked',
                'message' => '目标文章已在目标站被人工删除或移入回收站，必须人工确认后才能继续写入。',
                'relation' => $current,
            ]);
        }
        return $this->validate_target_post_identity(
            $current['source_blog_id'],
            $current['source_post_id'],
            $current['target_blog_id'],
            $current['target_post_id'],
            $current['post_type'] ?? '',
            $allow_legacy_relation,
            $allow_lifecycle_recovery
        );
    }

    private function build_slug_conflict_fallback_slug($source_slug, $source_post_id) {
        return sanitize_title((string)$source_slug . '-' . absint($source_post_id) . '-和源站ID重复');
    }

    private function get_relation_target_slug_policy($relation, $source_post = null) {
        if (!is_array($relation)) {
            return new WP_Error('relation_missing', '没有明确文章关系，不能确定目标 slug 策略。');
        }
        if (!$source_post instanceof WP_Post) {
            switch_to_blog((int)$relation['source_blog_id']);
            $source_post = get_post((int)$relation['source_post_id']);
            restore_current_blog();
        }
        if (!$source_post instanceof WP_Post) {
            return new WP_Error('source_missing', '源文章不存在，不能确定目标 slug 策略。');
        }
        $source_slug = (string)$source_post->post_name;
        $target_blog_id = (int)$relation['target_blog_id'];
        $target_post_id = (int)$relation['target_post_id'];
        switch_to_blog($target_blog_id);
        $target_post = get_post($target_post_id);
        $requires_review = (string)get_post_meta($target_post_id, '_wpmu_ml_slug_conflict_requires_review', true) === '1';
        $fallback_slug = (string)get_post_meta($target_post_id, '_wpmu_ml_slug_conflict_fallback_slug', true);
        restore_current_blog();
        if (!$target_post instanceof WP_Post) {
            return new WP_Error('target_missing', '目标文章不存在，不能确定目标 slug 策略。');
        }
        if ($requires_review) {
            if ($fallback_slug === '' || (string)$target_post->post_name !== $fallback_slug) {
                return new WP_Error('target_slug_fallback_invalid', '目标文章的 slug 冲突待复核标记与当前 slug 不一致，已阻止自动写入。');
            }
            return [
                'source_slug' => $source_slug,
                'target_slug' => $fallback_slug,
                'slug_conflict_fallback' => true,
                'force_draft' => true,
            ];
        }
        return [
            'source_slug' => $source_slug,
            'target_slug' => $source_slug,
            'slug_conflict_fallback' => false,
            'force_draft' => false,
        ];
    }

    public function validate_translation_job_target($job, $allow_legacy_relation = true) {
        if (!is_array($job)) {
            return $this->relation_validation_result(['error_code' => 'relation_missing', 'message' => '翻译任务参数无效。']);
        }
        $relation = $this->get_post_relation($job['source_blog_id'] ?? 0, $job['source_post_id'] ?? 0, $job['target_blog_id'] ?? 0);
        if (!$relation || (int)$relation['target_post_id'] !== absint($job['target_post_id'] ?? 0)) {
            return $this->relation_validation_result([
                'error_code' => 'relation_missing',
                'message' => '翻译任务没有对应的明确文章关系。',
                'relation' => $relation,
            ]);
        }
        $result = $this->validate_post_relation($relation, $allow_legacy_relation);
        if (empty($result['valid'])) {
            return $result;
        }
        switch_to_blog((int)$relation['source_blog_id']);
        $source_post = get_post((int)$relation['source_post_id']);
        restore_current_blog();
        if (!$source_post instanceof WP_Post) {
            return array_merge($result, [
                'valid' => false,
                'status' => 'relation_invalid',
                'error_code' => 'source_missing',
                'message' => '翻译任务指向的源文章不存在。',
            ]);
        }
        if (sanitize_key((string)$source_post->post_type) !== sanitize_key((string)$relation['post_type'])) {
            return array_merge($result, [
                'valid' => false,
                'status' => 'relation_invalid',
                'error_code' => 'source_post_type_conflict',
                'message' => '源文章类型与关系记录不一致。',
            ]);
        }
        $policy = $this->get_relation_target_slug_policy($relation, $source_post);
        if (is_wp_error($policy)) {
            return array_merge($result, [
                'valid' => false,
                'status' => 'relation_invalid',
                'error_code' => $policy->get_error_code(),
                'message' => $policy->get_error_message(),
            ]);
        }
        if ((string)$policy['target_slug'] !== '') {
            $slug_validation = $this->validate_target_slug_availability(
                $relation['target_blog_id'],
                $relation['target_post_id'],
                $policy['target_slug'],
                $relation['post_type']
            );
            if (is_wp_error($slug_validation)) {
                return array_merge($result, [
                    'valid' => false,
                    'status' => 'target_slug_conflict',
                    'error_code' => $slug_validation->get_error_code(),
                    'message' => $slug_validation->get_error_message(),
                    'error_data' => $slug_validation->get_error_data(),
                ]);
            }
        }
        return array_merge($result, $policy);
    }

    public function get_translation_job_slug_policy($job) {
        $identity = $this->validate_translation_job_target($job, true);
        if (empty($identity['valid'])) {
            return new WP_Error(
                sanitize_key((string)($identity['error_code'] ?? 'relation_invalid')),
                (string)($identity['message'] ?? '翻译目标校验失败。')
            );
        }
        return [
            'source_slug' => (string)($identity['source_slug'] ?? ''),
            'target_slug' => (string)($identity['target_slug'] ?? ''),
            'slug_conflict_fallback' => !empty($identity['slug_conflict_fallback']),
            'force_draft' => !empty($identity['force_draft']),
        ];
    }

    public function enforce_translation_target_status($job, $requested_status) {
        $policy = $this->get_translation_job_slug_policy($job);
        if (!is_wp_error($policy) && !empty($policy['force_draft'])) {
            return 'draft';
        }
        return sanitize_key((string)$requested_status);
    }

    public function target_matches_source($target_post_id, $source_blog_id, $source_post_id, $post_type, $allow_legacy_relation = false, $target_blog_id = 0) {
        $target_blog_id = $target_blog_id ? absint($target_blog_id) : get_current_blog_id();
        $result = $this->validate_target_post_identity($source_blog_id, $source_post_id, $target_blog_id, $target_post_id, $post_type, $allow_legacy_relation);
        return !empty($result['valid']);
    }

    public function stamp_relation_target_identity($relation) {
        if (!is_array($relation)) {
            return false;
        }
        $identity = $this->validate_post_relation($relation, true, true);
        if (empty($identity['valid'])) {
            return false;
        }
        switch_to_blog((int)$relation['target_blog_id']);
        $this->stamp_target_source_meta(
            (int)$relation['target_post_id'],
            (int)$relation['source_blog_id'],
            (int)$relation['source_post_id'],
            (string)($relation['source_lang'] ?? ''),
            (string)($relation['target_lang'] ?? '')
        );
        restore_current_blog();
        return true;
    }

    public function mark_relation_invalid($relation_id, $error_code, $message, $context = []) {
        global $wpdb;
        $relation_id = absint($relation_id);
        $error_code = sanitize_key((string)$error_code);
        if (!$relation_id) {
            return;
        }
        $status = 'target_identity_conflict';
        if ($error_code === 'target_missing') {
            $status = 'target_missing';
        } elseif ($error_code === 'target_status_trashed') {
            $status = 'target_trashed';
        } elseif ($error_code === 'target_slug_conflict') {
            $status = 'target_slug_conflict';
        } elseif (in_array($error_code, ['relation_missing','relation_incomplete','relation_target_mismatch','source_missing','source_post_type_conflict','target_slug_fallback_invalid'], true)) {
            $status = 'relation_invalid';
        }
        if ($error_code !== 'target_lifecycle_blocked') {
            $wpdb->update($this->tables['posts'], [
                'relation_status' => $status,
                'updated_at' => current_time('mysql'),
            ], ['id' => $relation_id], ['%s','%s'], ['%d']);
        }
        $context = is_array($context) ? $context : [];
        $context['relation_id'] = $relation_id;
        $context['error_code'] = $error_code;
        $action = $error_code === 'target_missing' ? 'relation_target_missing' : ($error_code === 'target_slug_conflict' ? 'target_slug_conflict' : 'relation_target_conflict');
        $this->log('error', $action, (string)$message, $context);
    }

    public function audit_post_relations($args = []) {
        global $wpdb;
        $limit = max(1, min(5000, absint($args['limit'] ?? 500)));
        $offset = absint($args['offset'] ?? 0);
        $target_blog_id = absint($args['target_blog_id'] ?? 0);
        $source_post_id = absint($args['source_post_id'] ?? 0);
        $where = ['1=1'];
        $params = [];
        if ($target_blog_id) {
            $where[] = 'target_blog_id = %d';
            $params[] = $target_blog_id;
        }
        if ($source_post_id) {
            $where[] = 'source_post_id = %d';
            $params[] = $source_post_id;
        }
        $sql = "SELECT * FROM {$this->tables['posts']} WHERE " . implode(' AND ', $where) . " ORDER BY id ASC LIMIT {$limit} OFFSET {$offset}";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);
        $summary = [
            'scanned' => 0,
            'strict_identity' => 0,
            'legacy_relation' => 0,
            'target_missing' => 0,
            'target_identity_conflict' => 0,
            'target_slug_conflict' => 0,
            'relation_invalid' => 0,
            'items' => [],
        ];
        foreach ((array)$rows as $relation) {
            $summary['scanned']++;
            switch_to_blog((int)$relation['source_blog_id']);
            $source_post = get_post((int)$relation['source_post_id']);
            restore_current_blog();
            if (!$source_post instanceof WP_Post) {
                $result = $this->relation_validation_result(['error_code' => 'source_missing', 'message' => '关系表指向的源文章不存在。']);
            } elseif (sanitize_key((string)$source_post->post_type) !== sanitize_key((string)$relation['post_type'])) {
                $result = $this->relation_validation_result(['error_code' => 'source_post_type_conflict', 'message' => '源文章类型与关系记录不一致。']);
            } else {
                $result = $this->validate_post_relation($relation, true);
            }
            if (!empty($result['valid'])) {
                $policy = $this->get_relation_target_slug_policy($relation, $source_post);
                if (is_wp_error($policy)) {
                    $result = $this->relation_validation_result([
                        'relation' => $relation,
                        'error_code' => $policy->get_error_code(),
                        'message' => $policy->get_error_message(),
                    ]);
                } elseif ((string)$policy['target_slug'] !== '') {
                    $slug_validation = $this->validate_target_slug_availability($relation['target_blog_id'], $relation['target_post_id'], $policy['target_slug'], $relation['post_type']);
                    if (is_wp_error($slug_validation)) {
                        $result = $this->relation_validation_result([
                            'status' => 'target_slug_conflict',
                            'relation' => $relation,
                            'error_code' => $slug_validation->get_error_code(),
                            'message' => $slug_validation->get_error_message(),
                        ]);
                    }
                }
            }
            $status = sanitize_key((string)($result['status'] ?? 'relation_invalid'));
            if (!array_key_exists($status, $summary)) {
                $status = 'relation_invalid';
            }
            $summary[$status]++;
            if (empty($result['valid'])) {
                $summary['items'][] = [
                    'relation_id' => (int)$relation['id'],
                    'source_blog_id' => (int)$relation['source_blog_id'],
                    'source_post_id' => (int)$relation['source_post_id'],
                    'target_blog_id' => (int)$relation['target_blog_id'],
                    'target_post_id' => (int)$relation['target_post_id'],
                    'post_type' => (string)$relation['post_type'],
                    'status' => $status,
                    'error_code' => (string)($result['error_code'] ?? ''),
                    'message' => (string)($result['message'] ?? ''),
                ];
            }
        }
        $summary['limit'] = $limit;
        $summary['offset'] = $offset;
        return $summary;
    }

    public function audit_post_relations_summary($target_blog_id = 0) {
        global $wpdb;
        $settings = $this->get_settings();
        $source_blog_id = absint($settings['source_blog_id'] ?? 0);
        $target_blog_id = absint($target_blog_id);
        if (!$source_blog_id) {
            return new WP_Error('wpmu_ml_audit_source_missing', '未配置源站，无法执行关系汇总审计。');
        }
        $where = 'enabled = 1 AND blog_id <> %d';
        $params = [$source_blog_id];
        if ($target_blog_id) {
            $where .= ' AND blog_id = %d';
            $params[] = $target_blog_id;
        }
        $sites = $wpdb->get_results($wpdb->prepare("SELECT blog_id, lang_slug FROM {$this->tables['sites']} WHERE {$where} ORDER BY blog_id ASC", $params), ARRAY_A);
        $source_prefix = $wpdb->get_blog_prefix($source_blog_id);
        $keys = [
            'relations','source_missing','target_missing','source_type_conflict','target_type_conflict',
            'identity_meta_missing','identity_meta_conflict','strict_identity','fallback_review','slug_conflicts','invalid_status','duplicate_targets',
        ];
        $result = ['source_blog_id' => $source_blog_id, 'sites' => [], 'totals' => array_fill_keys($keys, 0)];
        foreach ((array)$sites as $site) {
            $bid = (int)$site['blog_id'];
            $target_prefix = $wpdb->get_blog_prefix($bid);
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    COUNT(*) AS relations,
                    SUM(CASE WHEN s.ID IS NULL THEN 1 ELSE 0 END) AS source_missing,
                    SUM(CASE WHEN t.ID IS NULL THEN 1 ELSE 0 END) AS target_missing,
                    SUM(CASE WHEN s.ID IS NOT NULL AND s.post_type <> r.post_type THEN 1 ELSE 0 END) AS source_type_conflict,
                    SUM(CASE WHEN t.ID IS NOT NULL AND t.post_type <> r.post_type THEN 1 ELSE 0 END) AS target_type_conflict,
                    SUM(CASE WHEN t.ID IS NOT NULL AND COALESCE(m.blog_meta_count,0)=0 AND COALESCE(m.post_meta_count,0)=0 THEN 1 ELSE 0 END) AS identity_meta_missing,
                    SUM(CASE WHEN t.ID IS NOT NULL AND NOT (COALESCE(m.blog_meta_count,0)=0 AND COALESCE(m.post_meta_count,0)=0) AND NOT (
                        m.blog_meta_count=1 AND m.post_meta_count=1
                        AND CAST(m.source_blog_id AS UNSIGNED)=r.source_blog_id
                        AND CAST(m.source_post_id AS UNSIGNED)=r.source_post_id
                    ) THEN 1 ELSE 0 END) AS identity_meta_conflict,
                    SUM(CASE WHEN t.ID IS NOT NULL AND m.blog_meta_count=1 AND m.post_meta_count=1
                        AND CAST(m.source_blog_id AS UNSIGNED)=r.source_blog_id
                        AND CAST(m.source_post_id AS UNSIGNED)=r.source_post_id THEN 1 ELSE 0 END) AS strict_identity,
                    SUM(CASE WHEN t.ID IS NOT NULL AND COALESCE(f.requires_review,'')='1' AND t.post_name=COALESCE(f.fallback_slug,'') THEN 1 ELSE 0 END) AS fallback_review,
                    SUM(CASE WHEN s.ID IS NOT NULL AND t.ID IS NOT NULL AND s.post_name<>''
                        AND NOT (COALESCE(f.requires_review,'')='1' AND t.post_name=COALESCE(f.fallback_slug,''))
                        AND EXISTS (
                            SELECT 1 FROM `{$target_prefix}posts` c
                            WHERE c.ID<>t.ID AND c.post_name=s.post_name
                              AND c.post_status NOT IN ('trash','auto-draft') AND c.post_type<>'revision'
                              AND (r.post_type='attachment' OR c.post_type IN (r.post_type,'attachment'))
                        ) THEN 1 ELSE 0 END) AS slug_conflicts,
                    SUM(CASE WHEN r.relation_status IN ('target_missing','target_identity_conflict','target_slug_conflict','relation_invalid','target_deleted','target_trashed') THEN 1 ELSE 0 END) AS invalid_status
                 FROM {$this->tables['posts']} r
                 LEFT JOIN `{$source_prefix}posts` s ON s.ID=r.source_post_id
                 LEFT JOIN `{$target_prefix}posts` t ON t.ID=r.target_post_id
                 LEFT JOIN (
                    SELECT post_id,
                        MAX(CASE WHEN meta_key='_wpmu_ml_source_blog_id' THEN meta_value END) AS source_blog_id,
                        MAX(CASE WHEN meta_key='_wpmu_ml_source_post_id' THEN meta_value END) AS source_post_id,
                        SUM(CASE WHEN meta_key='_wpmu_ml_source_blog_id' THEN 1 ELSE 0 END) AS blog_meta_count,
                        SUM(CASE WHEN meta_key='_wpmu_ml_source_post_id' THEN 1 ELSE 0 END) AS post_meta_count
                    FROM `{$target_prefix}postmeta`
                    WHERE meta_key IN ('_wpmu_ml_source_blog_id','_wpmu_ml_source_post_id') GROUP BY post_id
                 ) m ON m.post_id=r.target_post_id
                 LEFT JOIN (
                    SELECT post_id,
                        MAX(CASE WHEN meta_key='_wpmu_ml_slug_conflict_requires_review' THEN meta_value END) AS requires_review,
                        MAX(CASE WHEN meta_key='_wpmu_ml_slug_conflict_fallback_slug' THEN meta_value END) AS fallback_slug
                    FROM `{$target_prefix}postmeta`
                    WHERE meta_key IN ('_wpmu_ml_slug_conflict_requires_review','_wpmu_ml_slug_conflict_fallback_slug') GROUP BY post_id
                 ) f ON f.post_id=r.target_post_id
                 WHERE r.source_blog_id=%d AND r.target_blog_id=%d",
                $source_blog_id,
                $bid
            ), ARRAY_A);
            $duplicate_targets = (int)$wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM (SELECT target_post_id FROM {$this->tables['posts']} WHERE target_blog_id=%d AND target_post_id>0 GROUP BY target_post_id HAVING COUNT(*)>1) d",
                $bid
            ));
            $summary = ['blog_id' => $bid, 'lang_slug' => (string)$site['lang_slug']];
            foreach ($keys as $key) {
                $summary[$key] = $key === 'duplicate_targets' ? $duplicate_targets : (int)($row[$key] ?? 0);
                $result['totals'][$key] += $summary[$key];
            }
            $result['sites'][] = $summary;
        }
        return $result;
    }

    public function reconcile_post_relations_from_meta($args = []) {
        global $wpdb;
        $target_blog_id = absint($args['target_blog_id'] ?? 0);
        $limit = max(1, min(5000, absint($args['limit'] ?? 500)));
        $offset = absint($args['offset'] ?? 0);
        $apply = !empty($args['apply']);
        $confirm = (string)($args['confirm'] ?? '');
        if (!$target_blog_id) {
            return new WP_Error('wpmu_ml_reconcile_target_required', '严格关系恢复必须指定 target_blog_id。');
        }
        if ($apply && $confirm !== 'ADD_META_RELATIONS') {
            return new WP_Error('wpmu_ml_reconcile_confirmation_required', '写入模式必须同时提供确认短语 ADD_META_RELATIONS。');
        }
        $settings = $this->get_settings();
        $source_blog_id = absint($settings['source_blog_id'] ?? 0);
        if (!$source_blog_id || $source_blog_id === $target_blog_id) {
            return new WP_Error('wpmu_ml_reconcile_invalid_sites', '源站或目标站参数无效。');
        }
        $target_prefix = $wpdb->get_blog_prefix($target_blog_id);
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID AS target_post_id,p.post_type,p.post_status,
                    MAX(CASE WHEN pm.meta_key='_wpmu_ml_source_blog_id' THEN pm.meta_value END) AS source_blog_id,
                    MAX(CASE WHEN pm.meta_key='_wpmu_ml_source_post_id' THEN pm.meta_value END) AS source_post_id,
                    SUM(CASE WHEN pm.meta_key='_wpmu_ml_source_blog_id' THEN 1 ELSE 0 END) AS blog_meta_count,
                    SUM(CASE WHEN pm.meta_key='_wpmu_ml_source_post_id' THEN 1 ELSE 0 END) AS post_meta_count
             FROM `{$target_prefix}posts` p
             JOIN `{$target_prefix}postmeta` pm ON pm.post_id=p.ID AND pm.meta_key IN ('_wpmu_ml_source_blog_id','_wpmu_ml_source_post_id')
             GROUP BY p.ID,p.post_type,p.post_status
             HAVING blog_meta_count=1 AND post_meta_count=1 AND CAST(source_blog_id AS UNSIGNED)=%d
             ORDER BY p.ID ASC LIMIT %d OFFSET %d",
            $source_blog_id,
            $limit,
            $offset
        ), ARRAY_A);
        $source_site = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['sites']} WHERE blog_id=%d", $source_blog_id), ARRAY_A);
        $target_site = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['sites']} WHERE blog_id=%d", $target_blog_id), ARRAY_A);
        $out = ['scanned' => 0, 'existing' => 0, 'candidates' => 0, 'inserted' => 0, 'conflicts' => 0, 'errors' => 0, 'dry_run' => !$apply, 'items' => []];
        foreach ((array)$rows as $row) {
            $out['scanned']++;
            $source_post_id = absint($row['source_post_id']);
            $target_post_id = absint($row['target_post_id']);
            $code = '';
            $message = '';
            switch_to_blog($source_blog_id);
            $source_post = get_post($source_post_id);
            restore_current_blog();
            if (!$source_post instanceof WP_Post) {
                $code = 'source_missing';
                $message = '来源 meta 指向的源文章不存在。';
                $out['errors']++;
            } elseif (sanitize_key((string)$source_post->post_type) !== sanitize_key((string)$row['post_type'])) {
                $code = 'source_post_type_conflict';
                $message = '来源 meta 对应源文章类型与目标类型不一致。';
                $out['conflicts']++;
            } else {
                $existing = $this->get_post_relation($source_blog_id, $source_post_id, $target_blog_id);
                if ($existing) {
                    if ((int)$existing['target_post_id'] === $target_post_id) {
                        $out['existing']++;
                        $code = 'existing';
                        $message = '严格关系已经存在。';
                    } else {
                        $out['conflicts']++;
                        $code = 'source_already_related';
                        $message = '源文章已关联该目标站的另一个目标文章。';
                    }
                } else {
                    $claim = $this->find_relation_by_target($target_blog_id, $target_post_id);
                    if ($claim) {
                        $out['conflicts']++;
                        $code = 'target_already_claimed';
                        $message = '目标文章已被另一个源文章关系占用。';
                    } else {
                        $out['candidates']++;
                        $code = 'candidate';
                        $message = '完整来源 meta 候选。';
                        if ($apply) {
                            $inserted = $wpdb->insert($this->tables['posts'], [
                                'source_blog_id' => $source_blog_id,
                                'source_post_id' => $source_post_id,
                                'target_blog_id' => $target_blog_id,
                                'target_post_id' => $target_post_id,
                                'source_lang' => (string)($source_site['lang_slug'] ?? ''),
                                'target_lang' => (string)($target_site['lang_slug'] ?? ''),
                                'post_type' => (string)$row['post_type'],
                                'target_post_status' => (string)$row['post_status'],
                                'relation_status' => (string)$row['post_status'] === 'publish' ? 'needs_update' : 'needs_translation',
                                'source_modified' => (string)$source_post->post_modified,
                                'target_modified' => current_time('mysql'),
                            ], ['%d','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s']);
                            if ($inserted === false) {
                                $out['errors']++;
                                $code = 'insert_failed';
                                $message = (string)$wpdb->last_error;
                            } else {
                                $out['inserted']++;
                                $code = 'inserted';
                                $message = '已按完整来源 meta 新增关系。';
                            }
                        }
                    }
                }
            }
            if (count($out['items']) < 100) {
                $out['items'][] = [
                    'source_post_id' => $source_post_id,
                    'target_post_id' => $target_post_id,
                    'post_type' => (string)$row['post_type'],
                    'code' => $code,
                    'message' => $message,
                ];
            }
        }
        return $out;
    }

    private function sync_one_target_secure($source_site, $source_post, $source_meta, $source_terms, $target, $force = false, $source_change_context = []) {
        global $wpdb;
        $settings = $this->get_settings();
        $source_blog_id = (int)$source_site['blog_id'];
        $target_blog_id = (int)$target['blog_id'];
        $source_post_id = (int)$source_post->ID;
        $is_shared = $this->is_shared_post_type($source_post->post_type);
        $relation = $this->get_post_relation($source_blog_id, $source_post_id, $target_blog_id);
        $target_post_id = $relation ? (int)$relation['target_post_id'] : 0;
        $target_post = null;
        $resolution = 'create';

        if ($relation) {
            $identity = $this->validate_post_relation($relation, true);
            if (empty($identity['valid'])) {
                $this->mark_relation_invalid((int)$relation['id'], (string)$identity['error_code'], (string)$identity['message'], [
                    'source_blog_id' => $source_blog_id,
                    'source_post_id' => $source_post_id,
                    'target_blog_id' => $target_blog_id,
                    'target_post_id' => $target_post_id,
                    'post_type' => (string)$source_post->post_type,
                    'action' => 'sync_update',
                ]);
                return ['target_post_id' => 0, 'queued' => 0, 'error' => (string)$identity['error_code']];
            }
            $target_post = $identity['target_post'];
            $resolution = (string)$identity['status'];
        } else {
            switch_to_blog($target_blog_id);
            $meta_target_ids = $this->find_target_posts_by_source_meta($source_blog_id, $source_post_id, (string)$source_post->post_type, 2);
            restore_current_blog();
            if (count($meta_target_ids) > 1) {
                $this->log('error', 'relation_target_conflict', '发现多个目标文章使用相同来源 meta，已阻止自动认领。', [
                    'source_blog_id' => $source_blog_id,
                    'source_post_id' => $source_post_id,
                    'target_blog_id' => $target_blog_id,
                    'target_post_ids' => $meta_target_ids,
                    'post_type' => (string)$source_post->post_type,
                    'error_code' => 'duplicate_source_meta_targets',
                ]);
                return ['target_post_id' => 0, 'queued' => 0, 'error' => 'duplicate_source_meta_targets'];
            }
            if ($meta_target_ids) {
                $target_post_id = (int)$meta_target_ids[0];
                $identity = $this->validate_target_post_identity(
                    $source_blog_id,
                    $source_post_id,
                    $target_blog_id,
                    $target_post_id,
                    (string)$source_post->post_type,
                    false
                );
                if (empty($identity['valid'])) {
                    $this->log('error', 'relation_target_conflict', (string)$identity['message'], [
                        'source_blog_id' => $source_blog_id,
                        'source_post_id' => $source_post_id,
                        'target_blog_id' => $target_blog_id,
                        'target_post_id' => $target_post_id,
                        'post_type' => (string)$source_post->post_type,
                        'action' => 'adopt_by_source_meta',
                        'error_code' => (string)$identity['error_code'],
                    ]);
                    return ['target_post_id' => 0, 'queued' => 0, 'error' => (string)$identity['error_code']];
                }
                $target_post = $identity['target_post'];
                $resolution = 'adopt_by_source_meta';
            }
        }

        $target_status = $target_post ? (string)$target_post->post_status : '';
        $target_meta = [];
        if ($target_post) {
            switch_to_blog($target_blog_id);
            $target_meta = get_post_meta($target_post_id);
            restore_current_blog();
        }
        $already_translated = !$is_shared && $target_post && $relation
            ? $this->target_has_completed_translation(
                $relation,
                $source_post,
                $target_post,
                $target_blog_id,
                $target_post_id,
                (array)($source_change_context['previous_snapshot'] ?? [])
            )
            : false;
        $changes = !empty($source_change_context['previous_snapshot'])
            ? (array)($source_change_context['changes'] ?? [])
            : $this->infer_initial_source_changes($source_post, $source_meta, $target_post, $target_meta, $already_translated);
        $translation_manifest = $this->build_translation_change_manifest($changes, $settings, $already_translated);
        $protect = !empty($settings['protect_translated']) && $already_translated && !$force;
        $target_lang = sanitize_key((string)($target['lang_slug'] ?? ''));
        $new_status = $is_shared ? 'publish' : $this->get_sync_target_status_for_lang($target_lang);
        if (!in_array($new_status, ['draft','pending','publish','private'], true)) {
            $new_status = 'draft';
        }

        $source_slug = (string)$source_post->post_name;
        $target_slug = $source_slug;
        $fallback_active = false;
        $fallback_new = false;
        if ($target_post) {
            $policy_relation = $relation ?: [
                'source_blog_id' => $source_blog_id,
                'source_post_id' => $source_post_id,
                'target_blog_id' => $target_blog_id,
                'target_post_id' => $target_post_id,
                'post_type' => (string)$source_post->post_type,
            ];
            $policy = $this->get_relation_target_slug_policy($policy_relation, $source_post);
            if (is_wp_error($policy)) {
                if ($relation) {
                    $this->mark_relation_invalid((int)$relation['id'], $policy->get_error_code(), $policy->get_error_message(), [
                        'source_blog_id' => $source_blog_id,
                        'source_post_id' => $source_post_id,
                        'target_blog_id' => $target_blog_id,
                        'target_post_id' => $target_post_id,
                        'action' => 'sync_slug_policy',
                    ]);
                }
                return ['target_post_id' => 0, 'queued' => 0, 'error' => $policy->get_error_code()];
            }
            $target_slug = (string)$policy['target_slug'];
            $fallback_active = !empty($policy['slug_conflict_fallback']);
            if (!empty($policy['force_draft'])) {
                $new_status = 'draft';
            }
        }

        if ($target_slug !== '') {
            $slug_validation = $this->validate_target_slug_availability(
                $target_blog_id,
                $target_post_id,
                $target_slug,
                (string)$source_post->post_type
            );
            if (is_wp_error($slug_validation)) {
                if (!$target_post && !$relation && $slug_validation->get_error_code() === 'target_slug_conflict') {
                    $fallback_slug = $this->build_slug_conflict_fallback_slug($source_slug, $source_post_id);
                    $fallback_validation = $this->validate_target_slug_availability($target_blog_id, 0, $fallback_slug, (string)$source_post->post_type);
                    if (is_wp_error($fallback_validation)) {
                        $this->log('error', 'target_slug_conflict', $fallback_validation->get_error_message(), [
                            'source_blog_id' => $source_blog_id,
                            'source_post_id' => $source_post_id,
                            'target_blog_id' => $target_blog_id,
                            'source_slug' => $source_slug,
                            'fallback_slug' => $fallback_slug,
                            'error_code' => $fallback_validation->get_error_code(),
                        ]);
                        return ['target_post_id' => 0, 'queued' => 0, 'error' => $fallback_validation->get_error_code()];
                    }
                    $target_slug = $fallback_slug;
                    $fallback_active = true;
                    $fallback_new = true;
                    $new_status = 'draft';
                    $this->log('warning', 'target_slug_conflict_fallback', '目标 slug 已被占用，已使用“源 slug-源文章 ID-和源站 ID 重复”的醒目标识创建草稿目标文章。', array_merge([
                        'source_blog_id' => $source_blog_id,
                        'source_post_id' => $source_post_id,
                        'target_blog_id' => $target_blog_id,
                        'source_slug' => $source_slug,
                        'fallback_slug' => $fallback_slug,
                        'forced_post_status' => 'draft',
                    ], (array)$slug_validation->get_error_data()));
                } else {
                    $context = [
                        'source_blog_id' => $source_blog_id,
                        'source_post_id' => $source_post_id,
                        'target_blog_id' => $target_blog_id,
                        'target_post_id' => $target_post_id,
                        'post_type' => (string)$source_post->post_type,
                        'action' => $target_post ? 'sync_update' : 'sync_create',
                    ];
                    if ($relation) {
                        $this->mark_relation_invalid((int)$relation['id'], $slug_validation->get_error_code(), $slug_validation->get_error_message(), $context);
                    } else {
                        $this->log('error', 'target_slug_conflict', $slug_validation->get_error_message(), $context);
                    }
                    return ['target_post_id' => 0, 'queued' => 0, 'error' => $slug_validation->get_error_code()];
                }
            }
        }

        $postarr = [
            'post_type' => $source_post->post_type,
            'post_status' => $fallback_active ? 'draft' : ($is_shared ? 'publish' : $new_status),
            'post_author' => (int)$source_post->post_author,
            'comment_status' => $source_post->comment_status,
            'ping_status' => $source_post->ping_status,
            'menu_order' => (int)$source_post->menu_order,
        ];
        if (!$protect) {
            if (!empty($settings['sync_title']) && in_array('post_title', (array)$changes['core'], true)) {
                $postarr['post_title'] = $source_post->post_title;
            }
            if (!empty($settings['sync_content']) && in_array('post_content', (array)$changes['core'], true)) {
                $postarr['post_content'] = $source_post->post_content;
            }
            if (!empty($settings['sync_excerpt']) && in_array('post_excerpt', (array)$changes['core'], true)) {
                $postarr['post_excerpt'] = $source_post->post_excerpt;
            }
            if ($target_slug !== '') {
                $postarr['post_name'] = $target_slug;
            }
        }

        $created = false;
        switch_to_blog($target_blog_id);
        if ($target_post) {
            $postarr['ID'] = $target_post_id;
            if ($protect && !$fallback_active) {
                unset($postarr['post_status']);
            }
            $written = wp_update_post(wp_slash($postarr), true);
        } else {
            $postarr['post_date'] = current_time('mysql');
            $postarr['post_date_gmt'] = current_time('mysql', 1);
            $written = wp_insert_post(wp_slash($postarr), true);
            if (!is_wp_error($written)) {
                $target_post_id = (int)$written;
                $created = true;
                $resolution = 'created';
            }
        }
        if (is_wp_error($written)) {
            restore_current_blog();
            $this->log('error', 'sync_target', $created ? '创建目标文章失败' : '写入目标文章失败', [
                'target_blog_id' => $target_blog_id,
                'source_post_id' => $source_post_id,
                'error' => $written->get_error_message(),
            ]);
            return ['target_post_id' => 0, 'queued' => 0, 'error' => 'target_write_failed'];
        }

        if ($target_slug !== '') {
            $slug_lock = $this->force_target_slug_value($target_blog_id, $target_post_id, $target_slug);
            if (is_wp_error($slug_lock)) {
                $created_post_id = $target_post_id;
                $deleted_post = false;
                $orphan_remaining = false;
                if ($created) {
                    $deleted_post = wp_delete_post($created_post_id, true);
                    $orphan_remaining = get_post($created_post_id) instanceof WP_Post;
                }
                restore_current_blog();
                $this->log('error', 'target_slug_conflict', $slug_lock->get_error_message(), [
                    'source_blog_id' => $source_blog_id,
                    'source_post_id' => $source_post_id,
                    'target_blog_id' => $target_blog_id,
                    'target_post_id' => $created_post_id,
                    'post_type' => (string)$source_post->post_type,
                    'action' => $created ? 'sync_create_slug_lock' : 'sync_update_slug_lock',
                    'created_post_cleanup' => $created ? ($orphan_remaining ? 'failed' : ($deleted_post ? 'deleted' : 'already_missing')) : 'not_applicable',
                ]);
                if ($created && $orphan_remaining) {
                    $this->log('error', 'relation_create_orphan', '新建目标文章的 slug 锁定失败，自动清理未成功，请人工检查该目标文章。', [
                        'source_blog_id' => $source_blog_id,
                        'source_post_id' => $source_post_id,
                        'target_blog_id' => $target_blog_id,
                        'target_post_id' => $created_post_id,
                    ]);
                }
                return ['target_post_id' => 0, 'queued' => 0, 'error' => $slug_lock->get_error_code()];
            }
        }

        if (!empty($settings['sync_meta'])) {
            $sync_meta_keys = [];
            foreach ((array)($changes['meta'] ?? []) as $meta_key) {
                if (!$protect || !$this->incremental_meta_key_is_translatable($meta_key, $settings)) {
                    $sync_meta_keys[] = (string)$meta_key;
                }
            }
            $sync_deleted_keys = [];
            foreach ((array)($changes['meta_deleted'] ?? []) as $meta_key) {
                $sync_deleted_keys[] = (string)$meta_key;
            }
            $this->replace_target_post_meta_keys($target_post_id, $source_meta, $sync_meta_keys, $sync_deleted_keys);
        }
        if (!empty($settings['sync_terms'])) {
            foreach ($source_terms as $taxonomy => $term_ids) {
                if (taxonomy_exists($taxonomy)) {
                    $target_term_ids = $this->map_source_term_ids_to_target_ids($source_site, $taxonomy, $term_ids, $target);
                    wp_set_object_terms($target_post_id, $target_term_ids, $taxonomy, false);
                }
            }
        }
        $this->stamp_target_source_meta($target_post_id, $source_blog_id, $source_post_id, (string)$source_site['lang_slug'], (string)$target['lang_slug']);
        if ($fallback_new) {
            update_post_meta($target_post_id, '_wpmu_ml_slug_conflict_source_slug', $source_slug);
            update_post_meta($target_post_id, '_wpmu_ml_slug_conflict_fallback_slug', $target_slug);
            update_post_meta($target_post_id, '_wpmu_ml_slug_conflict_requires_review', '1');
        }
        restore_current_blog();

        $has_translation_work = $this->translation_manifest_has_work($translation_manifest);
        $relation_status = $is_shared
            ? 'shared_published'
            : ($has_translation_work
                ? ($already_translated ? 'translated_update_pending' : 'needs_translation')
                : (string)($relation['relation_status'] ?? ($already_translated ? 'translated' : 'needs_translation')));
        $target_post_status = $fallback_active ? 'draft' : ($protect ? $target_status : ($is_shared ? 'publish' : $new_status));
        $relation_data = [
            'source_blog_id' => $source_blog_id,
            'source_post_id' => $source_post_id,
            'target_blog_id' => $target_blog_id,
            'target_post_id' => $target_post_id,
            'source_lang' => (string)$source_site['lang_slug'],
            'target_lang' => (string)$target['lang_slug'],
            'post_type' => (string)$source_post->post_type,
            'target_post_status' => $target_post_status,
            'relation_status' => $relation_status,
            'source_modified' => (string)$source_post->post_modified,
            'target_modified' => current_time('mysql'),
        ];
        $formats = ['%d','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s'];
        $relation_written = $relation
            ? $wpdb->update($this->tables['posts'], $relation_data, ['id' => (int)$relation['id']], $formats, ['%d'])
            : $wpdb->insert($this->tables['posts'], $relation_data, $formats);
        if ($relation_written === false) {
            $this->log('error', 'relation_create_failed', '目标文章已写入，但文章关系保存失败，未创建翻译任务。', [
                'source_blog_id' => $source_blog_id,
                'source_post_id' => $source_post_id,
                'target_blog_id' => $target_blog_id,
                'target_post_id' => $target_post_id,
                'post_type' => (string)$source_post->post_type,
                'resolution' => $resolution,
                'database_error' => (string)$wpdb->last_error,
            ]);
            return ['target_post_id' => $target_post_id, 'queued' => 0, 'error' => 'relation_write_failed'];
        }
        $queued = 0;
        if (!$is_shared && !empty($settings['queue_on_sync']) && $has_translation_work) {
            $queue_status = $already_translated ? 'translated_update_pending' : 'pending';
            $queued = $this->enqueue_translation_job($source_site, $source_post, $target, $target_post_id, $queue_status, $translation_manifest);
        }
        return [
            'target_post_id' => $target_post_id,
            'queued' => $queued,
            'slug_conflict_fallback' => $fallback_active ? 1 : 0,
        ];
    }

    private function sync_source_post_remove_to_targets_secure($source_post_id, $mode, $policy) {
        global $wpdb;
        $relations = $this->get_post_relations_for_source($source_post_id);
        $handled = 0;
        $kept = 0;
        $blocked = 0;
        foreach ((array)$relations as $relation) {
            $target_blog_id = (int)$relation['target_blog_id'];
            $target_post_id = (int)$relation['target_post_id'];
            if (!$target_blog_id || !$target_post_id) {
                $blocked++;
                continue;
            }
            $identity = $this->validate_post_relation($relation, true);
            if (empty($identity['valid'])) {
                $this->mark_relation_invalid((int)$relation['id'], (string)$identity['error_code'], (string)$identity['message'], [
                    'source_blog_id' => (int)$relation['source_blog_id'],
                    'source_post_id' => (int)$relation['source_post_id'],
                    'target_blog_id' => $target_blog_id,
                    'target_post_id' => $target_post_id,
                    'post_type' => (string)$relation['post_type'],
                    'action' => $mode,
                ]);
                $blocked++;
                continue;
            }
            $target_post = $identity['target_post'];
            $protected = $this->target_relation_is_protected($relation, $target_post);
            $should_process = $policy === 'all' || !$protected;
            if (!$should_process) {
                $wpdb->update($this->tables['posts'], [
                    'relation_status' => $mode === 'delete' ? 'source_deleted_keep' : 'source_trashed_keep',
                ], ['id' => (int)$relation['id']], ['%s'], ['%d']);
                $kept++;
                continue;
            }

            switch_to_blog($target_blog_id);
            if ($mode === 'delete') {
                update_post_meta($target_post_id, '_wpmu_ml_deleting_by_source', (string)$source_post_id);
                $deleted = wp_delete_post($target_post_id, true);
                restore_current_blog();
                if (!$deleted) {
                    $this->log('error', 'sync_delete_failed', '目标文章永久删除失败。', [
                        'source_post_id' => $source_post_id,
                        'target_blog_id' => $target_blog_id,
                        'target_post_id' => $target_post_id,
                    ]);
                    $blocked++;
                    continue;
                }
                $wpdb->update($this->tables['posts'], [
                    'target_post_status' => 'deleted',
                    'relation_status' => 'source_deleted_target_deleted',
                ], ['id' => (int)$relation['id']], ['%s','%s'], ['%d']);
            } else {
                update_post_meta($target_post_id, '_wpmu_ml_trashed_by_source', (string)$source_post_id);
                update_post_meta($target_post_id, '_wpmu_ml_trashed_from_status', (string)$target_post->post_status);
                $trashed = $target_post->post_status === 'trash';
                if (!$trashed) {
                    update_post_meta($target_post_id, '_wpmu_ml_trashing_by_source', (string)$source_post_id);
                    $trashed = (bool)wp_trash_post($target_post_id);
                    delete_post_meta($target_post_id, '_wpmu_ml_trashing_by_source');
                }
                restore_current_blog();
                if (!$trashed) {
                    $blocked++;
                    continue;
                }
                $wpdb->update($this->tables['posts'], [
                    'target_post_status' => 'trash',
                    'relation_status' => 'source_trashed',
                ], ['id' => (int)$relation['id']], ['%s','%s'], ['%d']);
            }
            $handled++;
        }
        $settings = $this->get_settings();
        $wpdb->update($this->tables['jobs'], [
            'status' => $mode === 'delete' ? 'source_deleted' : 'source_trashed',
            'locked_at' => null,
            'locked_by' => '',
            'process_after' => null,
            'last_error' => $mode === 'delete' ? '源文章已永久删除，翻译任务停止。' : '源文章已移入回收站，翻译任务暂停。',
            'updated_at' => current_time('mysql'),
        ], [
            'source_blog_id' => absint($settings['source_blog_id']),
            'source_post_id' => (int)$source_post_id,
        ]);
        $this->log('info', 'sync_' . $mode, '源站生命周期同步完成', [
            'source_post_id' => $source_post_id,
            'policy' => $policy,
            'handled' => $handled,
            'kept' => $kept,
            'blocked' => $blocked,
        ]);
        return ['handled' => $handled, 'kept' => $kept, 'blocked' => $blocked];
    }

    private function sync_source_post_restore_to_targets_secure($source_post_id) {
        global $wpdb;
        $relations = $this->get_post_relations_for_source($source_post_id);
        $restored = 0;
        $marked = 0;
        $blocked = 0;
        foreach ((array)$relations as $relation) {
            $identity = $this->validate_post_relation($relation, true, true);
            if (empty($identity['valid'])) {
                $this->mark_relation_invalid((int)$relation['id'], (string)$identity['error_code'], (string)$identity['message'], ['action' => 'restore']);
                $blocked++;
                continue;
            }
            $target_blog_id = (int)$relation['target_blog_id'];
            $target_post_id = (int)$relation['target_post_id'];
            $target_post = $identity['target_post'];
            switch_to_blog($target_blog_id);
            if ($target_post->post_status === 'trash' && (string)get_post_meta($target_post_id, '_wpmu_ml_trashed_by_source', true) === (string)$source_post_id) {
                update_post_meta($target_post_id, '_wpmu_ml_restoring_by_source', (string)$source_post_id);
                $untrashed = wp_untrash_post($target_post_id);
                delete_post_meta($target_post_id, '_wpmu_ml_restoring_by_source');
                delete_post_meta($target_post_id, '_wpmu_ml_trashed_by_source');
                delete_post_meta($target_post_id, '_wpmu_ml_trashed_from_status');
                $new_status = get_post_status($target_post_id) ?: 'draft';
                restore_current_blog();
                if (!$untrashed) {
                    $blocked++;
                    continue;
                }
                $wpdb->update($this->tables['posts'], [
                    'target_post_status' => $new_status,
                    'relation_status' => $this->is_shared_post_type($relation['post_type']) ? 'shared_published' : 'needs_translation',
                ], ['id' => (int)$relation['id']], ['%s','%s'], ['%d']);
                $restored++;
            } else {
                restore_current_blog();
                if (in_array((string)$relation['relation_status'], ['source_trashed_keep','source_deleted_keep'], true)) {
                    $wpdb->update($this->tables['posts'], ['relation_status' => 'needs_update'], ['id' => (int)$relation['id']], ['%s'], ['%d']);
                    $marked++;
                }
            }
        }
        $this->log('info', 'sync_restore', '源站恢复同步完成', compact('source_post_id', 'restored', 'marked', 'blocked'));
        return compact('restored', 'marked', 'blocked');
    }

    private function maybe_mark_target_lifecycle_status_secure($post_id, $event) {
        global $wpdb;
        $post_id = absint($post_id);
        $settings = $this->get_settings();
        $source_blog_id = absint($settings['source_blog_id'] ?? 0);
        $target_blog_id = get_current_blog_id();
        if (!$post_id || !$source_blog_id || $target_blog_id === $source_blog_id) {
            return;
        }
        $marker_key = $event === 'deleted' ? '_wpmu_ml_deleting_by_source' : ($event === 'trashed' ? '_wpmu_ml_trashing_by_source' : '_wpmu_ml_restoring_by_source');
        if ((string)get_post_meta($post_id, $marker_key, true) !== '') {
            return;
        }
        $relation = $this->find_relation_by_target($target_blog_id, $post_id);
        if (!$relation) {
            return;
        }
        $identity = $this->validate_post_relation($relation, true, true);
        if (empty($identity['valid'])) {
            $this->mark_relation_invalid((int)$relation['id'], (string)$identity['error_code'], (string)$identity['message'], [
                'target_blog_id' => $target_blog_id,
                'target_post_id' => $post_id,
                'action' => 'target_' . $event,
            ]);
            return;
        }
        $target_status = $event === 'deleted' ? 'deleted' : (get_post_status($post_id) ?: ($event === 'trashed' ? 'trash' : 'draft'));
        $relation_status = $event === 'deleted' ? 'target_deleted' : ($event === 'trashed' ? 'target_trashed' : 'needs_update');
        $wpdb->update($this->tables['posts'], [
            'target_post_status' => $target_status,
            'relation_status' => $relation_status,
            'target_modified' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => (int)$relation['id']], ['%s','%s','%s','%s'], ['%d']);
        if (in_array($event, ['deleted','trashed'], true)) {
            $wpdb->update($this->tables['jobs'], [
                'status' => 'relation_invalid',
                'locked_at' => null,
                'locked_by' => '',
                'process_after' => null,
                'last_error' => $event === 'deleted' ? '目标文章已在目标站被直接永久删除。' : '目标文章已在目标站被直接移入回收站。',
                'updated_at' => current_time('mysql'),
            ], [
                'source_blog_id' => (int)$relation['source_blog_id'],
                'source_post_id' => (int)$relation['source_post_id'],
                'target_blog_id' => $target_blog_id,
            ]);
        }
        $this->log('warning', 'relation_target_' . $event, '目标站文章生命周期发生人工变更。', [
            'relation_id' => (int)$relation['id'],
            'source_blog_id' => (int)$relation['source_blog_id'],
            'source_post_id' => (int)$relation['source_post_id'],
            'target_blog_id' => $target_blog_id,
            'target_post_id' => $post_id,
        ]);
    }
    }
}
