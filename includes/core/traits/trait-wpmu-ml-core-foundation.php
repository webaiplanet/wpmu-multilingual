<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 基础设施、设置、安装与诊断。
 *
 * 本 trait 仅用于拆分历史 WPMU_Multilingual 大类；方法签名和可见性保持不变。
 */
if (!trait_exists('WPMU_ML_Core_Foundation_Trait')) {
    trait WPMU_ML_Core_Foundation_Trait {
    public function repair_target_slugs_from_source($target_lang = '', $limit = 0, $dry_run = false) {
        global $wpdb;
        $target_lang = sanitize_key($target_lang);
        $limit = absint($limit);
        $dry_run = !empty($dry_run);

        $where = '1=1';
        $params = [];
        if ($target_lang !== '') {
            $where .= ' AND target_lang = %s';
            $params[] = $target_lang;
        }
        $sql = "SELECT id, source_blog_id, source_post_id, target_blog_id, target_post_id, target_lang FROM {$this->tables['posts']} WHERE {$where} ORDER BY id ASC";
        if ($limit > 0) {
            $sql .= ' LIMIT ' . intval($limit);
        }
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

        $out = ['scanned' => 0, 'need_fix' => 0, 'changed' => 0, 'skipped' => 0, 'errors' => 0, 'samples' => []];
        foreach ((array)$rows as $row) {
            $out['scanned']++;
            $source_blog_id = (int)$row['source_blog_id'];
            $source_post_id = (int)$row['source_post_id'];
            $target_blog_id = (int)$row['target_blog_id'];
            $target_post_id = (int)$row['target_post_id'];
            if (!$source_blog_id || !$source_post_id || !$target_blog_id || !$target_post_id) {
                $out['skipped']++;
                continue;
            }

            $identity = $this->validate_post_relation($row, true);
            if (empty($identity['valid'])) {
                $out['errors']++;
                if (count($out['samples']) < 20) {
                    $out['samples'][] = [
                        'target_blog_id' => $target_blog_id,
                        'target_post_id' => $target_post_id,
                        'error_code' => (string)$identity['error_code'],
                        'error' => (string)$identity['message'],
                    ];
                }
                continue;
            }

            switch_to_blog($source_blog_id);
            $source_post = get_post($source_post_id);
            $source_slug = $source_post ? (string)$source_post->post_name : '';
            restore_current_blog();

            switch_to_blog($target_blog_id);
            $target_post = get_post($target_post_id);
            $target_slug = $target_post ? (string)$target_post->post_name : '';
            restore_current_blog();

            if (!$source_post || !$target_post || $source_slug === '') {
                $out['skipped']++;
                continue;
            }
            if ($target_slug === $source_slug) {
                continue;
            }

            $out['need_fix']++;
            if (count($out['samples']) < 20) {
                $out['samples'][] = [
                    'target_blog_id' => $target_blog_id,
                    'target_post_id' => $target_post_id,
                    'old_slug' => $target_slug,
                    'new_slug' => $source_slug,
                ];
            }
            $slug_validation = $this->validate_target_slug_availability(
                $target_blog_id,
                $target_post_id,
                $source_slug,
                (string)$source_post->post_type
            );
            if (is_wp_error($slug_validation)) {
                $out['errors']++;
                if (count($out['samples']) < 20) {
                    $out['samples'][] = [
                        'target_blog_id' => $target_blog_id,
                        'target_post_id' => $target_post_id,
                        'error_code' => $slug_validation->get_error_code(),
                        'error' => $slug_validation->get_error_message(),
                    ];
                }
                continue;
            }
            if ($dry_run) {
                continue;
            }

            if ((string)$identity['status'] === 'legacy_relation') {
                $this->stamp_relation_target_identity($row);
            }

            $updated = $this->force_target_slug_value($target_blog_id, $target_post_id, $source_slug);
            if (is_wp_error($updated)) {
                $out['errors']++;
            } else {
                $out['changed']++;
            }
        }
        return $out;
    }

    public function repair_one_target_slug_from_source($source_post_id, $target_lang, $dry_run = false) {
        global $wpdb;
        $source_post_id = absint($source_post_id);
        $target_lang = sanitize_key($target_lang);
        $dry_run = !empty($dry_run);
        if (!$source_post_id || $target_lang === '') {
            return new WP_Error('wpmu_ml_invalid_slug_repair_args', '缺少源文章 ID 或目标语言。');
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, source_blog_id, source_post_id, target_blog_id, target_post_id, target_lang, post_type
             FROM {$this->tables['posts']}
             WHERE source_post_id = %d AND target_lang = %s
             ORDER BY id DESC LIMIT 1",
            $source_post_id,
            $target_lang
        ), ARRAY_A);
        if (!$row) {
            return new WP_Error('wpmu_ml_slug_relation_not_found', '没有找到该源文章到目标语言的关联。请先同步/生成该目标语言文章。');
        }

        $source_blog_id = (int)$row['source_blog_id'];
        $target_blog_id = (int)$row['target_blog_id'];
        $target_post_id = (int)$row['target_post_id'];
        $identity = $this->validate_post_relation($row, true);
        if (empty($identity['valid'])) {
            return new WP_Error('wpmu_ml_' . sanitize_key((string)$identity['error_code']), '目标文章身份校验失败：' . (string)$identity['message']);
        }
        $source_slug = $this->get_post_slug_from_blog($source_blog_id, $source_post_id);
        $old_slug = $this->get_post_slug_from_blog($target_blog_id, $target_post_id);
        if ($source_slug === '') {
            return new WP_Error('wpmu_ml_source_slug_empty', '源文章 slug 为空，不能修复。');
        }
        $slug_validation = $this->validate_target_slug_availability(
            $target_blog_id,
            $target_post_id,
            $source_slug,
            (string)($row['post_type'] ?? '')
        );
        if (is_wp_error($slug_validation)) {
            return $slug_validation;
        }
        if (!$dry_run && $old_slug !== $source_slug) {
            if ((string)$identity['status'] === 'legacy_relation') {
                $this->stamp_relation_target_identity($row);
            }
            $updated = $this->force_target_slug_value($target_blog_id, $target_post_id, $source_slug);
            if (is_wp_error($updated)) {
                return $updated;
            }
        }
        return [
            'source_blog_id' => $source_blog_id,
            'source_post_id' => $source_post_id,
            'target_blog_id' => $target_blog_id,
            'target_post_id' => $target_post_id,
            'old_slug' => $old_slug,
            'new_slug' => $source_slug,
        ];
    }

    private function get_post_slug_from_blog($blog_id, $post_id) {
        global $wpdb;
        $blog_id = (int)$blog_id;
        $post_id = (int)$post_id;
        if (!$blog_id || !$post_id) {
            return '';
        }
        switch_to_blog($blog_id);
        $slug = $wpdb->get_var($wpdb->prepare("SELECT post_name FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $post_id));
        restore_current_blog();
        return is_string($slug) ? (string)$slug : '';
    }

    public function force_target_slug_from_source($source_blog_id, $source_post_id, $target_blog_id, $target_post_id) {
        $relation = $this->get_post_relation($source_blog_id, $source_post_id, $target_blog_id);
        if (!$relation) {
            return new WP_Error('wpmu_ml_relation_missing', '没有找到明确文章关系，不能写入目标 slug。');
        }
        $identity = $this->validate_target_post_identity($source_blog_id, $source_post_id, $target_blog_id, $target_post_id, $relation['post_type'] ?? '', true);
        if (empty($identity['valid'])) {
            return new WP_Error('wpmu_ml_' . sanitize_key((string)$identity['error_code']), '目标文章身份校验失败：' . (string)$identity['message']);
        }
        $policy = $this->get_translation_job_slug_policy([
            'source_blog_id' => (int)$source_blog_id,
            'source_post_id' => (int)$source_post_id,
            'target_blog_id' => (int)$target_blog_id,
            'target_post_id' => (int)$target_post_id,
            'post_type' => (string)($relation['post_type'] ?? ''),
        ]);
        if (is_wp_error($policy)) {
            return $policy;
        }
        $target_slug = (string)($policy['target_slug'] ?? '');
        if ($target_slug === '') {
            return new WP_Error('wpmu_ml_source_slug_empty', '源文章 slug 为空，不能强制锁定目标 slug。');
        }
        return $this->force_target_slug_value((int)$target_blog_id, (int)$target_post_id, $target_slug);
    }

    public function validate_target_slug_availability($target_blog_id, $target_post_id, $source_slug, $post_type = '') {
        global $wpdb;
        $target_blog_id = absint($target_blog_id);
        $target_post_id = absint($target_post_id);
        $source_slug = (string)$source_slug;
        $post_type = sanitize_key((string)$post_type);
        if (!$target_blog_id || $source_slug === '') {
            return new WP_Error('invalid_target_slug_args', '目标站点或源文章 slug 参数不完整。');
        }

        switch_to_blog($target_blog_id);
        $target_post = $target_post_id ? get_post($target_post_id) : null;
        if ($target_post_id && !$target_post instanceof WP_Post) {
            restore_current_blog();
            return new WP_Error('target_post_missing', '目标文章不存在，不能校验 slug。');
        }
        if ($post_type === '' && $target_post instanceof WP_Post) {
            $post_type = sanitize_key((string)$target_post->post_type);
        }
        if ($post_type === '') {
            restore_current_blog();
            return new WP_Error('invalid_target_slug_args', '目标文章类型为空，不能校验 slug。');
        }

        $type_sql = '';
        $params = [$source_slug, $target_post_id];
        if ($post_type !== 'attachment') {
            $type_sql = ' AND post_type IN (%s, \'attachment\')';
            $params[] = $post_type;
        }
        $conflict = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_type, post_status, post_title
             FROM {$wpdb->posts}
             WHERE post_name = %s
               AND ID <> %d
               AND post_status NOT IN ('trash', 'auto-draft')
               AND post_type <> 'revision'
               {$type_sql}
             ORDER BY ID ASC
             LIMIT 1",
            $params
        ), ARRAY_A);
        restore_current_blog();

        if ($conflict) {
            $conflict_id = (int)$conflict['ID'];
            return new WP_Error(
                'target_slug_conflict',
                sprintf('目标站点 %d 的文章 %d 已占用 slug“%s”，已阻止写入文章 %d。', $target_blog_id, $conflict_id, $source_slug, $target_post_id),
                [
                    'target_blog_id' => $target_blog_id,
                    'target_post_id' => $target_post_id,
                    'source_slug' => $source_slug,
                    'post_type' => $post_type,
                    'conflict_post_id' => $conflict_id,
                    'conflict_post_type' => (string)$conflict['post_type'],
                    'conflict_post_status' => (string)$conflict['post_status'],
                ]
            );
        }
        return true;
    }

    public function force_target_slug_value($target_blog_id, $target_post_id, $source_slug) {
        global $wpdb;
        $target_blog_id = (int)$target_blog_id;
        $target_post_id = (int)$target_post_id;
        $source_slug = (string)$source_slug;
        if (!$target_blog_id || !$target_post_id || $source_slug === '') {
            return new WP_Error('wpmu_ml_invalid_force_slug_args', 'slug 强制锁定参数不完整。');
        }

        $slug_validation = $this->validate_target_slug_availability($target_blog_id, $target_post_id, $source_slug);
        if (is_wp_error($slug_validation)) {
            return $slug_validation;
        }

        switch_to_blog($target_blog_id);
        $post = get_post($target_post_id);
        if (!$post) {
            restore_current_blog();
            return new WP_Error('wpmu_ml_target_post_missing', '目标文章不存在，不能强制锁定 slug。');
        }

        // 这里故意不用 wp_update_post：部分主题/SEO/保存钩子可能会根据译文标题再次生成 post_name。
        // 直接更新 posts.post_name，随后清缓存，确保目标 URL 与源站 slug 完全一致。
        $updated = $wpdb->update(
            $wpdb->posts,
            ['post_name' => $source_slug],
            ['ID' => $target_post_id],
            ['%s'],
            ['%d']
        );
        clean_post_cache($target_post_id);
        restore_current_blog();

        if ($updated === false) {
            return new WP_Error('wpmu_ml_force_slug_failed', '直接写入目标 slug 失败。');
        }
        return true;
    }

    public function get_table_name($key) {
        return isset($this->tables[$key]) ? (string)$this->tables[$key] : '';
    }

    public function get_translation_job_row($job_id) {
        global $wpdb;
        $job_id = absint($job_id);
        if (!$job_id || empty($this->tables['jobs'])) {
            return null;
        }
        $table = $this->tables['jobs'];
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $job_id), ARRAY_A);
    }

    public function get_agent_mode_label() {
        $settings = $this->get_settings();
        $mode = sanitize_key($settings['openai_agent_mode'] ?? 'off');
        if (in_array($mode, ['rules_qa','agent_qa'], true)) {
            return 'ai_qa / AI + 质检';
        }
        if (in_array($mode, ['rules','agent'], true)) {
            return 'ai / AI 翻译';
        }
        return ($mode ?: 'off') . ' / 普通翻译';
    }

    public function get_diagnostic_info($job_id = 0) {
        global $wpdb;
        $settings = $this->get_settings();
        $tables = [];
        foreach ($this->tables as $key => $name) {
            $exists = (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === (string)$name;
            $rows = 'n/a';
            if ($exists) {
                $rows = (string)(int)$wpdb->get_var("SELECT COUNT(*) FROM {$name}");
            }
            $tables[$key] = [
                'name' => $name,
                'exists' => $exists,
                'rows' => $rows,
            ];
        }
        $job_id = absint($job_id);
        return [
            'version' => self::VERSION,
            'is_multisite' => is_multisite(),
            'base_prefix' => (string)$wpdb->base_prefix,
            'prefix' => (string)$wpdb->prefix,
            'agent_mode' => $this->get_agent_mode_label(),
            'agent_quality_check' => !empty($settings['openai_agent_quality_check']),
            'agent_fail_on_qa' => !empty($settings['openai_agent_fail_on_qa']),
            'tables' => $tables,
            'job' => $job_id ? $this->get_translation_job_row($job_id) : null,
        ];
    }

    public function notice_need_multisite() {
        echo '<div class="notice notice-error"><p>WPMU多语言 仅支持 WordPress Multisite。</p></div>';
    }

    public static function activate($network_wide) {
        if (!is_multisite()) {
            wp_die('WPMU多语言 仅支持 WordPress Multisite。');
        }
        self::instance()->create_tables();
        self::instance()->maybe_seed_default_settings();
        self::instance()->sync_sites_from_network();
    }

    public function maybe_upgrade() {
        $installed = get_site_option('wpmu_ml_version', '0');
        if (version_compare($installed, self::VERSION, '<')) {
            $this->create_tables();
            $this->maybe_seed_default_settings();

            // 0.9.5.0 performance migration: only replace the exact 0.9.4.9 defaults.
            // Explicitly customized values are preserved.
            if (version_compare($installed, '0.9.5.0', '<')) {
                $stored = $this->get_stored_settings_array();
                $settings_changed = false;
                if (absint($stored['openai_central_translation_batch_fields'] ?? 45) === 45) {
                    $stored['openai_central_translation_batch_fields'] = 120;
                    $settings_changed = true;
                }
                if (absint($stored['openai_central_qa_batch_fields'] ?? 20) === 20) {
                    $stored['openai_central_qa_batch_fields'] = 80;
                    $settings_changed = true;
                }
                if (absint($stored['openai_central_qa_batch_chars'] ?? 9000) === 9000) {
                    $stored['openai_central_qa_batch_chars'] = 16000;
                    $settings_changed = true;
                }
                if ($settings_changed) {
                    update_site_option(self::OPTION, $stored);
                }
            }

            // 0.9.6.0 adaptive-quality migration. Exhaustive second-pass AI review remains
            // available as an explicit diagnostic mode, but the prior exact default moves to
            // adaptive review so ordinary articles are not sent through a second full translation-sized pass.
            if (version_compare($installed, '0.9.6.0', '<')) {
                $stored = $this->get_stored_settings_array();
                $settings_changed = false;
                if (!isset($stored['openai_qa_coverage_mode']) || sanitize_key((string)$stored['openai_qa_coverage_mode']) === 'all') {
                    $stored['openai_qa_coverage_mode'] = 'adaptive';
                    $settings_changed = true;
                }
                if (!array_key_exists('openai_translation_self_review', $stored)) {
                    $stored['openai_translation_self_review'] = 1;
                    $settings_changed = true;
                }
                if (!array_key_exists('openai_adaptive_qa_max_fields', $stored)) {
                    $stored['openai_adaptive_qa_max_fields'] = 24;
                    $settings_changed = true;
                }
                if ($settings_changed) {
                    update_site_option(self::OPTION, $stored);
                }
            }

            // 0.9.6.1 simplified-settings migration. The request character limit is
            // now the only user-facing batching control; internal limits are derived.
            if (version_compare($installed, '0.9.6.1', '<')) {
                $stored = $this->get_stored_settings_array();
                $char_limit = max(1000, min(60000, absint($stored['openai_max_chars'] ?? 6000)));
                $stored['openai_max_chars'] = $char_limit;
                $stored['openai_fragment_batch_fields'] = 0;
                $stored['openai_central_translation_batch_fields'] = 200;
                $stored['openai_adaptive_qa_max_fields'] = max(12, min(32, (int)ceil($char_limit / 250)));
                $stored['openai_central_qa_batch_fields'] = max(40, min(120, (int)ceil($char_limit / 75)));
                $stored['openai_central_qa_batch_chars'] = max(8000, min(50000, $char_limit * 2));
                $stored['openai_translation_self_review'] = 1;
                $stored['openai_semantic_block_translation'] = 1;
                $stored['openai_residual_body_pass'] = 0;
                $stored['openai_fast_quality_pipeline'] = 1;
                $stored['openai_agent_quality_check'] = 1;
                if (!in_array(sanitize_key((string)($stored['openai_qa_coverage_mode'] ?? 'adaptive')), ['adaptive','all','off'], true)) {
                    $stored['openai_qa_coverage_mode'] = 'adaptive';
                }
                update_site_option(self::OPTION, $stored);
            }

            // 0.9.6.2 internal performance profile. Real 6,000-vs-30,000 tests showed
            // that larger requests materially increased latency without improving the
            // recurring quality outcome. The batching profile is therefore internal and
            // no longer user-configurable.
            if (version_compare($installed, '0.9.6.2', '<')) {
                $stored = $this->get_stored_settings_array();
                $stored['openai_max_chars'] = 6000;
                $stored['openai_fragment_batch_fields'] = 0;
                $stored['openai_central_translation_batch_fields'] = 200;
                $stored['openai_adaptive_qa_max_fields'] = 24;
                $stored['openai_central_qa_batch_fields'] = 80;
                $stored['openai_central_qa_batch_chars'] = 12000;
                update_site_option(self::OPTION, $stored);
            }

            // 0.9.6.3 quality-policy migration. PHP integrity checks are mandatory and
            // no longer configurable. The only editorial switch is whether a second AI quality
            // review is enabled. Legacy coverage/repair/draft settings are normalized from that
            // single switch, and per-language quality overrides are removed.
            if (version_compare($installed, '0.9.6.3', '<')) {
                $stored = $this->get_stored_settings_array();
                $legacy_mode = sanitize_key((string)($stored['openai_qa_coverage_mode'] ?? 'adaptive'));
                $quality_enabled = !empty($stored['openai_agent_quality_check'])
                    && $legacy_mode !== 'off'
                    && (!array_key_exists('openai_editorial_review_enabled', $stored) || !empty($stored['openai_editorial_review_enabled']));
                $stored['openai_agent_quality_check'] = $quality_enabled ? 1 : 0;
                $stored['openai_agent_mode'] = $quality_enabled ? 'rules_qa' : 'rules';
                $stored['openai_editorial_review_enabled'] = $quality_enabled ? 1 : 0;
                $stored['openai_fast_quality_pipeline'] = 1;
                $stored['openai_centralized_quality_pipeline'] = 1;
                $stored['openai_qa_coverage_mode'] = $quality_enabled ? 'adaptive' : 'off';
                $stored['openai_central_qa_auto_repair'] = $quality_enabled ? 1 : 0;
                $stored['openai_qa_draft_on_incomplete'] = $quality_enabled ? 1 : 0;
                $stored['openai_qa_strict_status'] = $quality_enabled ? 1 : 0;
                $stored['openai_agent_fail_on_qa'] = $quality_enabled ? 1 : 0;
                if (!empty($stored['openai_language_settings']) && is_array($stored['openai_language_settings'])) {
                    foreach ($stored['openai_language_settings'] as $lang => $profile) {
                        if (!is_array($profile)) {
                            continue;
                        }
                        unset($profile['quality_check'], $profile['editorial_review'], $profile['fail_on_qa']);
                        $stored['openai_language_settings'][$lang] = $profile;
                    }
                }
                update_site_option(self::OPTION, $stored);
            }

            if (version_compare($installed, '0.9.8.7', '<')) {
                global $wpdb;
                $completed_statuses = [
                    'machine_done_published','machine_translated','opencc_done_published','opencc_converted',
                    'agent_done_published','agent_translated','manual_done','translated',
                ];
                $quoted = implode(',', array_map(function($status) {
                    return "'" . esc_sql($status) . "'";
                }, $completed_statuses));
                $wpdb->query(
                    "UPDATE {$this->tables['jobs']} SET translated_content=1
                     WHERE status IN ({$quoted})
                        OR (status='review_required' AND (attempts>0 OR finished_at IS NOT NULL))"
                );
            }

            $this->sync_sites_from_network(false);
            update_site_option('wpmu_ml_language_switcher_menu_sync_pending', self::VERSION);
            update_site_option('wpmu_ml_version', self::VERSION);
        }
    }

    public function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        $sql_sites = "CREATE TABLE {$this->tables['sites']} (
            blog_id BIGINT(20) UNSIGNED NOT NULL,
            lang_slug VARCHAR(30) NOT NULL,
            locale VARCHAR(30) NOT NULL DEFAULT '',
            language_name VARCHAR(191) NOT NULL DEFAULT '',
            translation_locale VARCHAR(30) NOT NULL DEFAULT '',
            translation_language_name VARCHAR(191) NOT NULL DEFAULT '',
            hreflang VARCHAR(30) NOT NULL DEFAULT '',
            site_url VARCHAR(255) NOT NULL DEFAULT '',
            site_path VARCHAR(255) NOT NULL DEFAULT '',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            is_source TINYINT(1) NOT NULL DEFAULT 0,
            is_front_default TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (blog_id),
            UNIQUE KEY lang_slug (lang_slug),
            KEY enabled (enabled),
            KEY hreflang (hreflang)
        ) $charset;";

        $sql_posts = "CREATE TABLE {$this->tables['posts']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_blog_id BIGINT(20) UNSIGNED NOT NULL,
            source_post_id BIGINT(20) UNSIGNED NOT NULL,
            target_blog_id BIGINT(20) UNSIGNED NOT NULL,
            target_post_id BIGINT(20) UNSIGNED NOT NULL,
            source_lang VARCHAR(30) NOT NULL,
            target_lang VARCHAR(30) NOT NULL,
            post_type VARCHAR(64) NOT NULL,
            target_post_status VARCHAR(30) DEFAULT NULL,
            relation_status VARCHAR(40) NOT NULL DEFAULT 'needs_translation',
            source_modified DATETIME DEFAULT NULL,
            target_modified DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_target (source_blog_id, source_post_id, target_blog_id),
            UNIQUE KEY target_unique (target_blog_id, target_post_id),
            KEY target_lookup (target_blog_id, target_post_id),
            KEY source_lookup (source_blog_id, source_post_id),
            KEY target_lang (target_lang),
            KEY post_type (post_type),
            KEY relation_status (relation_status)
        ) $charset;";

        $sql_terms = "CREATE TABLE {$this->tables['terms']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_blog_id BIGINT(20) UNSIGNED NOT NULL,
            source_term_id BIGINT(20) UNSIGNED NOT NULL,
            source_taxonomy VARCHAR(64) NOT NULL,
            target_blog_id BIGINT(20) UNSIGNED NOT NULL,
            target_term_id BIGINT(20) UNSIGNED NOT NULL,
            target_taxonomy VARCHAR(64) NOT NULL,
            source_lang VARCHAR(30) NOT NULL,
            target_lang VARCHAR(30) NOT NULL,
            relation_status VARCHAR(40) NOT NULL DEFAULT 'synced',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY source_target_term (source_blog_id, source_term_id, source_taxonomy, target_blog_id),
            KEY target_lookup (target_blog_id, target_term_id, target_taxonomy),
            KEY target_lang (target_lang)
        ) $charset;";

        $sql_logs = "CREATE TABLE {$this->tables['logs']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            level VARCHAR(20) NOT NULL DEFAULT 'info',
            action VARCHAR(80) NOT NULL DEFAULT '',
            message TEXT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY level (level),
            KEY action (action),
            KEY created_at (created_at)
        ) $charset;";

        $sql_jobs = "CREATE TABLE {$this->tables['jobs']} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_blog_id BIGINT(20) UNSIGNED NOT NULL,
            source_post_id BIGINT(20) UNSIGNED NOT NULL,
            target_blog_id BIGINT(20) UNSIGNED NOT NULL,
            target_post_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            source_lang VARCHAR(30) NOT NULL DEFAULT '',
            target_lang VARCHAR(30) NOT NULL DEFAULT '',
            post_type VARCHAR(64) NOT NULL DEFAULT '',
            engine VARCHAR(60) NOT NULL DEFAULT 'manual',
            model VARCHAR(120) NOT NULL DEFAULT '',
            route_reason VARCHAR(80) NOT NULL DEFAULT '',
            route_profile VARCHAR(160) NOT NULL DEFAULT '',
            complete_status VARCHAR(20) NOT NULL DEFAULT '',
            status VARCHAR(40) NOT NULL DEFAULT 'pending',
            job_type VARCHAR(40) NOT NULL DEFAULT 'full_translate',
            change_manifest LONGTEXT NULL,
            translated_content TINYINT(1) NOT NULL DEFAULT 0,
            priority INT NOT NULL DEFAULT 10,
            attempts INT NOT NULL DEFAULT 0,
            locked_at DATETIME NULL,
            locked_by VARCHAR(120) NOT NULL DEFAULT '',
            process_after DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            finished_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_target (source_blog_id, source_post_id, target_blog_id),
            KEY status (status),
            KEY target_lang (target_lang),
            KEY engine (engine),
            KEY route_reason (route_reason),
            KEY locked_at (locked_at),
            KEY process_after (process_after),
            KEY post_type (post_type),
            KEY priority (priority)
        ) $charset;";

        dbDelta($sql_sites);
        dbDelta($sql_posts);
        dbDelta($sql_terms);
        dbDelta($sql_logs);
        dbDelta($sql_jobs);
    }

    private function get_stored_settings_array() {
        $settings = get_site_option(self::OPTION, null);
        $settings = is_array($settings) ? $settings : [];
        $original = $settings;

        // 兼容极早期版本或误存到主站 options 表的设置。只补充“缺失的键”，
        // 绝不覆盖当前 network option 中已经存在的值。
        $candidates = [];
        if (is_multisite() && function_exists('get_main_site_id') && function_exists('get_blog_option')) {
            $main_site_id = (int)get_main_site_id();
            if ($main_site_id > 0) {
                $main_value = get_blog_option($main_site_id, self::OPTION, null);
                if (is_array($main_value)) {
                    $candidates[] = $main_value;
                }
                foreach (['wpmu_multilingual_settings', 'wpmu_ml_options'] as $legacy_option) {
                    $legacy_value = get_blog_option($main_site_id, $legacy_option, null);
                    if (is_array($legacy_value)) {
                        $candidates[] = $legacy_value;
                    }
                }
            }
        }
        foreach (['wpmu_multilingual_settings', 'wpmu_ml_options'] as $legacy_option) {
            $legacy_value = get_site_option($legacy_option, null);
            if (is_array($legacy_value)) {
                $candidates[] = $legacy_value;
            }
        }

        foreach ($candidates as $candidate) {
            foreach ($candidate as $key => $value) {
                if (!array_key_exists($key, $settings)) {
                    $settings[$key] = $value;
                }
            }
        }

        // 兼容可能出现过的旧字段别名；正式字段名继续沿用 0.8 系列，未改名。
        $aliases = [
            'openai_base_url' => 'openai_api_base',
            'openai_endpoint' => 'openai_api_base',
            'openai_key' => 'openai_api_key',
            'openai_token' => 'openai_api_key',
            'openai_default_model' => 'openai_model',
            'openai_temp' => 'openai_temperature',
        ];
        foreach ($aliases as $legacy_key => $current_key) {
            if (!array_key_exists($current_key, $settings) && array_key_exists($legacy_key, $settings)) {
                $settings[$current_key] = $settings[$legacy_key];
            }
        }

        if ($settings !== $original && !empty($settings)) {
            update_site_option(self::OPTION, $settings);
        }
        return $settings;
    }

    public function maybe_seed_default_settings() {
        $settings = $this->get_stored_settings_array();
        if (!empty($settings)) {
            return;
        }
        $settings = [
            'source_blog_id' => 0,
            'front_blog_id' => 0,
            'enable_hreflang' => 1,
            'hide_unpublished' => 1,
            'enable_switcher_shortcode' => 1,
            'language_switcher_call_mode' => 'code',
            'language_switcher_flag_mode' => 'none',
            'language_switcher_flag_style' => '4x3',
            'language_switcher_flag_size' => 24,
            'language_switcher_flag_radius' => 2,
            'language_switcher_unpublished_policy' => 'hide',
            'enable_menu_language_switcher' => 0,
            'x_default_mode' => 'front',
            'translatable_post_types' => ['post', 'page', 'guide_post', 'solution_post', 'activity_post', 'doing_post', 'reviews_post', 'knowledge_post', 'provider_post', 'docs_post', 'tools_post', 'wp_block'],
            'shared_post_types' => ['tolink'],
            'excluded_post_types' => [],
            'excluded_taxonomies' => ['nav_menu', 'link_category'],
            'auto_sync_enabled' => 0,
            'auto_sync_on_update' => 1,
            'trash_sync_policy' => 'drafts_only',
            'delete_sync_policy' => 'drafts_only',
            'restore_sync_enabled' => 1,
            'target_default_status' => 'draft',
            'protect_translated' => 1,
            'queue_on_sync' => 1,
            'sync_title' => 1,
            'sync_content' => 1,
            'sync_excerpt' => 1,
            'sync_slug' => 1,
            'sync_meta' => 1,
            'sync_terms' => 1,
            'translate_term_name' => 0,
            'translate_term_description' => 0,
            'translation_default_engine' => 'openai',
            'translation_queue_runner' => 'manual',
            'translation_queue_limit' => 2,
            'translation_openai_concurrency' => 1,
            'translation_opencc_concurrency' => 5,
            'translation_agent_claim_limit' => 1,
            'translation_lock_ttl_minutes' => 10,
            'translation_max_attempts' => 3,
            'translation_retry_delay_minutes' => 10,
            'translation_complete_status' => 'pending',
            'translation_engines_by_lang' => [],
            'translation_auto_by_lang' => [],
            'translation_status_by_lang' => [],
            'translation_engines_by_post_type' => [],
            'translation_models_by_post_type' => [],
            'translation_status_by_post_type' => [],
            'translation_engines_by_lang_post_type' => [],
            'translation_models_by_lang_post_type' => [],
            'translation_status_by_lang_post_type' => [],
            'agent_api_token' => '',
            'agent_tools_api_token' => '',
            'openai_api_base' => 'https://api.openai.com/v1',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4o-mini',
            'openai_temperature' => '0.2',
            'openai_timeout' => 300,
            'openai_max_chars' => 6000,
            'openai_fragment_batch_fields' => 30,
            'openai_section_batch_fields' => 36,
            'openai_section_batch_chars' => 2200,
            'openai_semantic_block_translation' => 1,
            'openai_residual_body_pass' => 0,
            'openai_translate_meta' => 1,
            'openai_translate_seo_meta' => 1,
            'openai_excluded_meta_keys' => "_ai_generated_seo\n_ai_generated_summary\n_ai_seo_auto_generated\n_deepseek_slug_generated\n_deepseek_slug_last_value\n_auto_internal_links_done\n_mr_reactions_processed\n_initial_views_processed\npost_views\nutv_post_views\nviews",
            'openai_excluded_html_tags' => "pre",
            'openai_code_block_strategy' => 'smart_text',
            'openai_inline_code_strategy' => 'smart',
            'openai_agent_mode' => 'rules_qa',
            'openai_agent_quality_check' => 1,
            'openai_agent_cjk_residue_limit' => 0,
            'openai_agent_fail_on_qa' => 1,
            'openai_editorial_review_enabled' => 1,
            'openai_fast_quality_pipeline' => 1,
            'openai_centralized_quality_pipeline' => 1,
            'openai_central_translation_batch_fields' => 120,
            'openai_qa_coverage_mode' => 'adaptive',
            'openai_translation_self_review' => 1,
            'openai_adaptive_qa_max_fields' => 24,
            'openai_central_qa_max_fields' => 8,
            'openai_central_qa_batch_fields' => 80,
            'openai_central_qa_batch_chars' => 16000,
            'openai_central_qa_auto_repair' => 1,
            'openai_qa_draft_on_incomplete' => 1,
            'openai_qa_strict_status' => 1,
            'openai_qa_batch_fields' => 3,
            'openai_language_settings' => [],
            'openai_agent_site_rules' => '',
            'openai_agent_terms' => '',
            'opencc_binary_path' => '',
            'opencc_config' => 's2twp.json',
            'opencc_convert_meta' => 1,
            'opencc_convert_seo_meta' => 1,
            'admin_bar_current_page_site_links' => 0,
            'show_my_sites_language_card_meta' => 0,
            'admin_bar_language_site_labels' => 0,
        ];
        update_site_option(self::OPTION, $settings);
    }

    public function get_settings() {
        $defaults = [
            'source_blog_id' => 0,
            'front_blog_id' => 0,
            'enable_hreflang' => 1,
            'hide_unpublished' => 1,
            'enable_switcher_shortcode' => 1,
            'language_switcher_call_mode' => 'code',
            'language_switcher_flag_mode' => 'none',
            'language_switcher_flag_style' => '4x3',
            'language_switcher_flag_size' => 24,
            'language_switcher_flag_radius' => 2,
            'language_switcher_unpublished_policy' => 'hide',
            'enable_menu_language_switcher' => 0,
            'x_default_mode' => 'front',
            'translatable_post_types' => [],
            'shared_post_types' => [],
            'excluded_post_types' => [],
            'excluded_taxonomies' => ['nav_menu', 'link_category'],
            'auto_sync_enabled' => 0,
            'auto_sync_on_update' => 1,
            'trash_sync_policy' => 'drafts_only',
            'delete_sync_policy' => 'drafts_only',
            'restore_sync_enabled' => 1,
            'target_default_status' => 'draft',
            'protect_translated' => 1,
            'queue_on_sync' => 1,
            'sync_title' => 1,
            'sync_content' => 1,
            'sync_excerpt' => 1,
            'sync_slug' => 1,
            'sync_meta' => 1,
            'sync_terms' => 1,
            'translate_term_name' => 0,
            'translate_term_description' => 0,
            'translation_default_engine' => 'openai',
            'translation_queue_runner' => 'manual',
            'translation_queue_limit' => 2,
            'translation_openai_concurrency' => 1,
            'translation_opencc_concurrency' => 5,
            'translation_agent_claim_limit' => 1,
            'translation_lock_ttl_minutes' => 10,
            'translation_max_attempts' => 3,
            'translation_retry_delay_minutes' => 10,
            'translation_complete_status' => 'pending',
            'translation_engines_by_lang' => [],
            'translation_auto_by_lang' => [],
            'translation_status_by_lang' => [],
            'translation_engines_by_post_type' => [],
            'translation_models_by_post_type' => [],
            'translation_status_by_post_type' => [],
            'translation_engines_by_lang_post_type' => [],
            'translation_models_by_lang_post_type' => [],
            'translation_status_by_lang_post_type' => [],
            'agent_api_token' => '',
            'agent_tools_api_token' => '',
            'openai_api_base' => 'https://api.openai.com/v1',
            'openai_api_key' => '',
            'openai_model' => 'gpt-4o-mini',
            'openai_temperature' => '0.2',
            'openai_timeout' => 300,
            'openai_max_chars' => 6000,
            'openai_fragment_batch_fields' => 30,
            'openai_section_batch_fields' => 36,
            'openai_section_batch_chars' => 2200,
            'openai_semantic_block_translation' => 1,
            'openai_residual_body_pass' => 0,
            'openai_translate_meta' => 1,
            'openai_translate_seo_meta' => 1,
            'openai_excluded_meta_keys' => "_ai_generated_seo\n_ai_generated_summary\n_ai_seo_auto_generated\n_deepseek_slug_generated\n_deepseek_slug_last_value\n_auto_internal_links_done\n_mr_reactions_processed\n_initial_views_processed\npost_views\nutv_post_views\nviews",
            'openai_excluded_html_tags' => "pre",
            'openai_code_block_strategy' => 'smart_text',
            'openai_inline_code_strategy' => 'smart',
            'openai_agent_mode' => 'rules_qa',
            'openai_agent_quality_check' => 1,
            'openai_agent_cjk_residue_limit' => 0,
            'openai_agent_fail_on_qa' => 1,
            'openai_editorial_review_enabled' => 1,
            'openai_fast_quality_pipeline' => 1,
            'openai_centralized_quality_pipeline' => 1,
            'openai_central_translation_batch_fields' => 120,
            'openai_qa_coverage_mode' => 'adaptive',
            'openai_translation_self_review' => 1,
            'openai_adaptive_qa_max_fields' => 24,
            'openai_central_qa_max_fields' => 8,
            'openai_central_qa_batch_fields' => 80,
            'openai_central_qa_batch_chars' => 16000,
            'openai_central_qa_auto_repair' => 1,
            'openai_qa_draft_on_incomplete' => 1,
            'openai_qa_strict_status' => 1,
            'openai_qa_batch_fields' => 3,
            'openai_language_settings' => [],
            'openai_agent_site_rules' => '',
            'openai_agent_terms' => '',
            'opencc_binary_path' => '',
            'opencc_config' => 's2twp.json',
            'opencc_convert_meta' => 1,
            'opencc_convert_seo_meta' => 1,
            'admin_bar_current_page_site_links' => 0,
            'show_my_sites_language_card_meta' => 0,
            'admin_bar_language_site_labels' => 0,
        ];
        $settings = wp_parse_args($this->get_stored_settings_array(), $defaults);

        // 0.9.6.2: keep the performance profile deterministic across sites. These are
        // implementation safety limits, not editorial choices, so old stored values are
        // deliberately ignored at runtime.
        $settings['openai_max_chars'] = 6000;
        $settings['openai_fragment_batch_fields'] = 0;
        $settings['openai_central_translation_batch_fields'] = 200;
        $settings['openai_adaptive_qa_max_fields'] = 24;
        $settings['openai_central_qa_batch_fields'] = 80;
        $settings['openai_central_qa_batch_chars'] = 12000;

        // 0.9.6.4: one quality switch; PHP remains validation-only. PHP field/empty/structure/placeholder/write-back
        // integrity checks are always active; this flag controls only the optional AI review.
        $ai_quality_enabled = !empty($settings['openai_agent_quality_check']);
        $settings['openai_agent_mode'] = $ai_quality_enabled ? 'rules_qa' : 'rules';
        $settings['openai_editorial_review_enabled'] = $ai_quality_enabled ? 1 : 0;
        $settings['openai_fast_quality_pipeline'] = 1;
        // Keep the centralized path active even when AI QA is off so legacy per-fragment
        // language-audit calls are not re-enabled. Coverage=off skips the second AI request.
        $settings['openai_centralized_quality_pipeline'] = 1;
        $settings['openai_qa_coverage_mode'] = $ai_quality_enabled ? 'adaptive' : 'off';
        $settings['openai_central_qa_auto_repair'] = $ai_quality_enabled ? 1 : 0;
        $settings['openai_qa_draft_on_incomplete'] = $ai_quality_enabled ? 1 : 0;
        $settings['openai_qa_strict_status'] = $ai_quality_enabled ? 1 : 0;
        $settings['openai_agent_fail_on_qa'] = $ai_quality_enabled ? 1 : 0;
        // Local residue repair is permanently disabled. Residue/number/length signals may only
        // be supplied to the optional AI review and must never mutate content by themselves.
        $settings['openai_residual_body_pass'] = 0;

        // v0.8.0：agent 现在是正式内置翻译引擎，不再自动映射到 openai。
        $settings['sync_slug'] = 1;
        return $settings;
    }

    public function network_admin_menu() {
        add_menu_page(
            '翻译设置',
            'WPMU 多语言',
            'manage_network_options',
            'wpmu-multilingual',
            [$this, 'render_admin_page'],
            'dashicons-translation',
            58
        );

        add_submenu_page(
            'wpmu-multilingual',
            '翻译引擎',
            '翻译引擎',
            'manage_network_options',
            'wpmu-multilingual-engines',
            [$this, 'render_engine_admin_page']
        );

        // WordPress 会自动为一级菜单生成同名的第一个子菜单；只改显示名称，
        // 保持链接仍为 admin.php?page=wpmu-multilingual。
        global $submenu;
        if (isset($submenu['wpmu-multilingual'][0])) {
            $submenu['wpmu-multilingual'][0][0] = '翻译设置';
        }
    }

    private function log($level, $action, $message, $context = null) {
        global $wpdb;
        $wpdb->insert($this->tables['logs'], [
            'level' => sanitize_key($level),
            'action' => sanitize_key($action),
            'message' => (string)$message,
            'context' => $context ? wp_json_encode($context, JSON_UNESCAPED_UNICODE) : null,
        ], ['%s','%s','%s','%s']);
    }
    }
}
