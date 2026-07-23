<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 翻译队列、任务调度与任务状态。
 *
 * 本 trait 仅用于拆分历史 WPMU_Multilingual 大类；方法签名和可见性保持不变。
 */
if (!trait_exists('WPMU_ML_Core_Queue_Trait')) {
    trait WPMU_ML_Core_Queue_Trait {
    public function add_cron_schedules($schedules) {
        if (!isset($schedules['wpmu_ml_10_minutes'])) {
            $schedules['wpmu_ml_10_minutes'] = [
                'interval' => 600,
                'display' => 'WPMU多语言：每10分钟',
            ];
        }
        return $schedules;
    }

    private function maybe_schedule_queue_runner() {
        $settings = $this->get_settings();
        $timestamp = wp_next_scheduled('wpmu_ml_process_translation_queue');
        if (($settings['translation_queue_runner'] ?? 'manual') === 'wp_cron') {
            if (!$timestamp) {
                wp_schedule_event(time() + 300, 'wpmu_ml_10_minutes', 'wpmu_ml_process_translation_queue');
            }
        } elseif ($timestamp) {
            wp_unschedule_event($timestamp, 'wpmu_ml_process_translation_queue');
        }
    }

    public function cron_process_translation_queue() {
        $settings = $this->get_settings();
        if (($settings['translation_queue_runner'] ?? 'manual') !== 'wp_cron') {
            return;
        }
        $limit = max(1, min(20, absint($settings['translation_queue_limit'] ?? 2)));
        $this->process_translation_queue($limit, [
            'runner' => 'wp-cron',
            'respect_auto_enabled' => true,
        ]);
    }

    public function cron_process_manual_translation_queue($limit = 1, $target_lang = '', $job_id = 0, $token = '') {
        $token = $token ? sanitize_text_field((string)$token) : '';
        if ($token) {
            $payload = $this->consume_manual_queue_event_payload($token);
            if (!is_array($payload)) {
                return;
            }
            $limit = $payload['limit'];
            $target_lang = $payload['target_lang'];
            $job_id = $payload['job_id'];
        }

        $limit = max(1, min(20, absint($limit)));
        $target_lang = sanitize_key($target_lang);
        $job_id = absint($job_id);

        if ($job_id) {
            $this->process_single_translation_job($job_id, 'wp-cron-manual-one');
            return;
        }

        $this->process_translation_queue($limit, [
            'runner' => 'wp-cron-manual',
            'target_lang' => $target_lang,
            'respect_auto_enabled' => false,
        ]);
    }

    private function consume_manual_queue_event_payload($token) {
        $token = sanitize_text_field((string)$token);
        if (!$token) {
            return null;
        }
        $key = 'wpmu_ml_async_queue_' . md5($token);
        $payload = get_site_transient($key);
        if (!is_array($payload)) {
            return null;
        }
        delete_site_transient($key);
        return [
            'limit' => max(1, min(20, absint($payload['limit'] ?? 1))),
            'target_lang' => sanitize_key($payload['target_lang'] ?? ''),
            'job_id' => absint($payload['job_id'] ?? 0),
        ];
    }

    private function trigger_manual_queue_event($limit = 1, $target_lang = '', $job_id = 0) {
        $limit = max(1, min(20, absint($limit)));
        $target_lang = sanitize_key($target_lang);
        $job_id = absint($job_id);

        // 使用一次性 token 记录事件载荷。异步 loopback 和 WP-Cron 兜底共用同一个 token，
        // 谁先消费谁执行，避免同一个任务被 loopback 与 cron 重复处理。
        $token = wp_generate_password(48, false, false);
        $key = 'wpmu_ml_async_queue_' . md5($token);
        set_site_transient($key, [
            'limit' => $limit,
            'target_lang' => $target_lang,
            'job_id' => $job_id,
            'created_at' => time(),
        ], 10 * MINUTE_IN_SECONDS);

        // 1) 先登记 WP-Cron 单次事件，作为兜底。即使异步 loopback 被服务器拦截，
        //    后续 WP-Cron / 外部 cron 触发时也还能处理。
        $timestamp = time() + 2;
        wp_schedule_single_event($timestamp, 'wpmu_ml_process_manual_translation_queue', [$limit, $target_lang, $job_id, $token]);
        if (function_exists('spawn_cron')) {
            spawn_cron($timestamp);
        }

        // 2) 再发一个非阻塞 loopback 请求，尽量马上启动队列处理，但不让当前后台页面等待 API。
        //    使用一次性 token，不依赖登录 Cookie，避免 admin-post 异步请求因无会话而失效。

        $response = wp_remote_post(admin_url('admin-post.php'), [
            'timeout' => 1,
            'redirection' => 0,
            'blocking' => false,
            'sslverify' => apply_filters('https_local_ssl_verify', false),
            'body' => [
                'action' => 'wpmu_ml_async_process_queue',
                'token' => $token,
            ],
        ]);

        $this->log(is_wp_error($response) ? 'warning' : 'info', 'translation_queue_event_triggered', is_wp_error($response) ? '后台队列事件已登记，但 loopback 触发失败' : '后台队列事件已登记并尝试异步触发', [
            'limit' => $limit,
            'target_lang' => $target_lang,
            'job_id' => $job_id,
            'loopback_error' => is_wp_error($response) ? $response->get_error_message() : '',
        ]);

        return !is_wp_error($response);
    }

    public function handle_async_process_queue() {
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        if (!$token) {
            status_header(403);
            echo 'missing token';
            exit;
        }

        $payload = $this->consume_manual_queue_event_payload($token);
        if (!is_array($payload)) {
            status_header(403);
            echo 'invalid or expired token';
            exit;
        }

        $limit = $payload['limit'];
        $target_lang = $payload['target_lang'];
        $job_id = $payload['job_id'];

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $this->cron_process_manual_translation_queue($limit, $target_lang, $job_id);
        echo 'ok';
        exit;
    }

    public function handle_process_queue() {
        $this->verify_network_action();
        $settings = $this->get_settings();
        $limit = isset($_POST['limit']) ? absint($_POST['limit']) : absint($settings['translation_queue_limit']);
        $limit = max(1, min(20, $limit));
        $target_lang = isset($_POST['target_lang']) ? sanitize_key($_POST['target_lang']) : '';
        $job_id = isset($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        $redirect = network_admin_url('admin.php?page=wpmu-multilingual&tab=translation');

        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        /**
         * 手动处理队列必须真的处理任务。
         * 0.6.5 只触发 loopback/WP-Cron 事件，在部分服务器上会因为 loopback、伪 Cron、时区
         * 或 process_after 条件导致后台看起来“点了没反应”。这里改成当前请求直接处理，
         * 并忽略 process_after 延迟，适合少量任务/指定任务 ID 测试。
         * 长时间批量翻译仍建议用 WP-CLI 或系统 Cron。
         */
        if ($job_id) {
            /**
             * 指定任务 ID 是人工强制测试入口。
             * 这里先释放该任务自身的锁，避免上一次异步事件或超时请求留下 locked_by，
             * 导致用户明明手动测试却还要先点“释放超时锁”。
             */
            global $wpdb;
            $wpdb->update($this->tables['jobs'], [
                'status' => 'pending',
                'locked_at' => null,
                'locked_by' => '',
                'process_after' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ], ['id' => $job_id], ['%s','%s','%s','%s','%s'], ['%d']);

            $processed = $this->process_single_translation_job($job_id, 'manual-admin-queue-one');
            if (is_wp_error($processed)) {
                wp_safe_redirect(add_query_arg(['wpmu_ml_error' => rawurlencode($processed->get_error_message())], $redirect));
                exit;
            }
            $result = ['scanned' => 1, 'locked' => 1, 'processed' => 1, 'skipped' => 0, 'failed' => 0, 'job_id' => $job_id];
            $message = '已处理指定任务：job_id=' . intval($job_id) . '。';
        } else {
            $result = $this->process_translation_queue($limit, [
                'runner' => 'manual-admin-queue',
                'target_lang' => $target_lang,
                'respect_auto_enabled' => false,
                'ignore_process_after' => true,
            ]);
            $message = '队列处理完成：扫描 ' . intval($result['scanned']) . ' 个，锁定 ' . intval($result['locked']) . ' 个，处理 ' . intval($result['processed']) . ' 个，跳过 ' . intval($result['skipped']) . ' 个，失败 ' . intval($result['failed']) . ' 个。';
            if ($target_lang) {
                $message .= ' 目标语言：' . $target_lang . '。';
            }
        }

        $this->log('info', 'translation_queue_process_manual_done', '手动处理翻译队列完成', $result);
        wp_safe_redirect(add_query_arg(['updated' => 1, 'wpmu_ml_message' => rawurlencode($message)], $redirect));
        exit;
    }

    public function handle_release_queue_locks() {
        $this->verify_network_action();
        $released = $this->release_stale_translation_locks();
        $this->log('info', 'translation_queue_release_locks', '释放超时翻译任务锁', ['released' => $released]);
        $redirect = network_admin_url('admin.php?page=wpmu-multilingual&tab=translation');
        wp_safe_redirect(add_query_arg(['updated' => 1, 'wpmu_ml_message' => rawurlencode('已释放 ' . intval($released) . ' 个超时锁。')], $redirect));
        exit;
    }

    public function handle_translate_single() {
        $this->verify_network_action();
        $post_id = isset($_POST['single_post_id']) ? absint($_POST['single_post_id']) : 0;
        $target_lang = isset($_POST['single_target_lang']) ? sanitize_key($_POST['single_target_lang']) : '';
        $engine = isset($_POST['single_engine']) ? sanitize_key($_POST['single_engine']) : '';
        $overwrite = !empty($_POST['single_overwrite']);
        $run_now = !empty($_POST['single_run_now']);

        $redirect = network_admin_url('admin.php?page=wpmu-multilingual&tab=translation');
        $prepared = $this->prepare_single_translation_job($post_id, $target_lang, [
            'engine' => $engine,
            'overwrite' => $overwrite,
            'runner' => 'manual-admin-single',
        ]);

        if (is_wp_error($prepared)) {
            wp_safe_redirect(add_query_arg(['wpmu_ml_error' => rawurlencode($prepared->get_error_message())], $redirect));
            exit;
        }

        $message = '已为文章 ' . intval($prepared['source_post_id']) . ' 的 ' . $target_lang . ' 版本创建/重置翻译任务。';
        if ($run_now) {
            $processed = $this->process_single_translation_job((int)$prepared['job_id'], 'manual-admin-one');
            if (is_wp_error($processed)) {
                wp_safe_redirect(add_query_arg(['wpmu_ml_error' => rawurlencode($processed->get_error_message())], $redirect));
                exit;
            }
            $message = '单篇翻译已处理：文章 ' . intval($prepared['source_post_id']) . ' → ' . $target_lang . '。';
        } else {
            /**
             * 0.6.7：默认只创建/重置 pending 队列，不再自动触发异步后台事件。
             * 之前默认触发 loopback/WP-Cron，可能马上给任务加锁，但服务器未实际完成处理，
             * 后台看起来就像“单篇指定翻译后任务被锁住”。
             * 需要马上翻译时，勾选“立即翻译”；需要手动处理时，使用下方“处理队列”。
             */
            $message = '已加入队列：文章 ' . intval($prepared['source_post_id']) . ' → ' . $target_lang . '，job_id=' . intval($prepared['job_id']) . '。未勾选“立即翻译”，不会自动调用 API，也不会自动加锁。';
        }

        wp_safe_redirect(add_query_arg(['updated' => 1, 'wpmu_ml_message' => rawurlencode($message)], $redirect));
        exit;
    }

    public function prepare_single_translation_job($post_id, $target_lang, $args = []) {
        global $wpdb;
        $post_id = absint($post_id);
        $target_lang = sanitize_key($target_lang);
        $engine = sanitize_key($args['engine'] ?? '');
        $overwrite = !empty($args['overwrite']);

        if (!$post_id || !$target_lang) {
            return new WP_Error('wpmu_ml_missing_single_args', '请填写文章 ID 和目标语言。');
        }

        $relation = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->tables['posts']} WHERE target_lang = %s AND source_post_id = %d LIMIT 1",
            $target_lang,
            $post_id
        ), ARRAY_A);

        if (!$relation) {
            $relation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->tables['posts']} WHERE target_lang = %s AND target_post_id = %d LIMIT 1",
                $target_lang,
                $post_id
            ), ARRAY_A);
        }

        if (!$relation) {
            return new WP_Error('wpmu_ml_relation_not_found', '没有找到该文章与目标语言的关联。请先确认源站文章已经同步到该语言。');
        }

        $source_blog_id = (int)$relation['source_blog_id'];
        $source_post_id = (int)$relation['source_post_id'];
        $target_blog_id = (int)$relation['target_blog_id'];
        $target_post_id = (int)$relation['target_post_id'];
        if (!$source_blog_id || !$source_post_id || !$target_blog_id || !$target_post_id) {
            return new WP_Error('wpmu_ml_relation_incomplete', '关联记录不完整，缺少 source/target blog_id 或 post_id。');
        }

        $identity = $this->validate_translation_job_target([
            'source_blog_id' => $source_blog_id,
            'source_post_id' => $source_post_id,
            'target_blog_id' => $target_blog_id,
            'target_post_id' => $target_post_id,
            'post_type' => (string)$relation['post_type'],
        ], true);
        if (empty($identity['valid'])) {
            $this->mark_relation_invalid((int)$relation['id'], (string)$identity['error_code'], (string)$identity['message'], [
                'source_blog_id' => $source_blog_id,
                'source_post_id' => $source_post_id,
                'target_blog_id' => $target_blog_id,
                'target_post_id' => $target_post_id,
                'post_type' => (string)$relation['post_type'],
                'action' => 'prepare_translation_job',
            ]);
            return new WP_Error('wpmu_ml_' . sanitize_key((string)$identity['error_code']), '目标文章身份校验失败：' . (string)$identity['message']);
        }

        $route = $this->resolve_translation_route($target_lang, (string)$relation['post_type'], $engine ?: '');
        $engine = $route['engine'];
        if (!array_key_exists($engine, $this->get_translation_engines_for_lang($target_lang))) {
            return new WP_Error('wpmu_ml_invalid_engine', '无效翻译方式：' . $engine);
        }

        if ($overwrite) {
            $initial_status = !empty($identity['force_draft']) ? 'draft' : $this->get_sync_target_status_for_lang($target_lang);
            switch_to_blog($target_blog_id);
            $target_post = get_post($target_post_id);
            if ($target_post && !in_array($target_post->post_status, ['trash', 'auto-draft', 'inherit'], true)) {
                wp_update_post([
                    'ID' => $target_post_id,
                    'post_status' => $initial_status,
                ]);
            }
            restore_current_blog();

            $wpdb->update($this->tables['posts'], [
                'target_post_status' => $initial_status,
                'relation_status' => 'needs_translation',
                'updated_at' => current_time('mysql'),
            ], ['id' => (int)$relation['id']], ['%s','%s','%s'], ['%d']);
        }

        $job_type = 'full_translate';
        $priority = -9999;
        $now = current_time('mysql');
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->tables['jobs']} (
                source_blog_id, source_post_id, target_blog_id, target_post_id,
                source_lang, target_lang, post_type, engine, model, route_reason, route_profile, complete_status, status, job_type,
                priority, attempts, locked_at, locked_by, process_after, last_error,
                created_at, updated_at, started_at, finished_at
            ) VALUES (%d,%d,%d,%d,%s,%s,%s,%s,%s,%s,%s,%s,'pending',%s,%d,0,NULL,'',%s,%s,%s,%s,NULL,NULL)
            ON DUPLICATE KEY UPDATE
                target_post_id = VALUES(target_post_id),
                engine = VALUES(engine),
                model = VALUES(model),
                route_reason = VALUES(route_reason),
                route_profile = VALUES(route_profile),
                complete_status = VALUES(complete_status),
                status = 'pending',
                job_type = VALUES(job_type),
                priority = VALUES(priority),
                attempts = 0,
                locked_at = NULL,
                locked_by = '',
                process_after = VALUES(process_after),
                last_error = VALUES(last_error),
                updated_at = VALUES(updated_at),
                started_at = NULL,
                finished_at = NULL",
            $source_blog_id,
            $source_post_id,
            $target_blog_id,
            $target_post_id,
            (string)$relation['source_lang'],
            $target_lang,
            (string)$relation['post_type'],
            $engine,
            (string)($route['model'] ?? ''),
            (string)($route['route_reason'] ?? ''),
            (string)($route['route_profile'] ?? ''),
            (string)($route['complete_status'] ?? ''),
            $job_type,
            $priority,
            $now,
            '单篇指定翻译：' . ($overwrite ? '强制覆盖' : '不强制覆盖') . '，等待处理',
            $now,
            $now
        ));

        if ($inserted === false) {
            return new WP_Error('wpmu_ml_job_prepare_failed', '创建/重置翻译任务失败：' . $wpdb->last_error);
        }

        $job_id = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->tables['jobs']} WHERE source_blog_id = %d AND source_post_id = %d AND target_blog_id = %d LIMIT 1",
            $source_blog_id,
            $source_post_id,
            $target_blog_id
        ));

        return [
            'job_id' => $job_id,
            'source_blog_id' => $source_blog_id,
            'source_post_id' => $source_post_id,
            'target_blog_id' => $target_blog_id,
            'target_post_id' => $target_post_id,
            'target_lang' => $target_lang,
            'engine' => $engine,
        ];
    }

    public function process_single_translation_job($job_id, $runner = 'manual-one') {
        global $wpdb;
        $job_id = absint($job_id);
        if (!$job_id) {
            return new WP_Error('wpmu_ml_invalid_job_id', '无效任务 ID。');
        }
        $this->release_stale_translation_locks();
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['jobs']} WHERE id = %d LIMIT 1", $job_id), ARRAY_A);
        if (!$job) {
            return new WP_Error('wpmu_ml_job_not_found', '没有找到翻译任务。');
        }
        $token = sanitize_key($runner) . '-' . substr(wp_generate_password(12, false, false), 0, 12);
        $locked_at = current_time('mysql', true);
        $started_at = current_time('mysql');
        $locked = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->tables['jobs']}
             SET status = 'pending', locked_at = %s, locked_by = %s, started_at = %s, process_after = NULL, updated_at = %s
             WHERE id = %d AND (locked_at IS NULL OR locked_at = '0000-00-00 00:00:00')",
            $locked_at,
            $token,
            $started_at,
            $started_at,
            $job_id
        ));
        if ($locked === false) {
            return new WP_Error('wpmu_ml_job_lock_failed', '锁定翻译任务失败：' . $wpdb->last_error);
        }
        if (!$locked) {
            return new WP_Error('wpmu_ml_job_locked', '该任务正在处理或仍有任务锁；如确认已超时，请先点击“释放超时锁”。');
        }
        $job['status'] = 'pending';
        $job['locked_by'] = $token;
        $job['locked_at'] = $locked_at;
        $job['process_after'] = null;
        $processed = $this->process_translation_job($job);
        if ($processed !== true) {
            $failed_job = $wpdb->get_row($wpdb->prepare(
                "SELECT status, attempts, last_error FROM {$this->tables['jobs']} WHERE id = %d LIMIT 1",
                $job_id
            ), ARRAY_A);
            $status = is_array($failed_job) ? (string)($failed_job['status'] ?? '') : '';
            $attempts = is_array($failed_job) ? (int)($failed_job['attempts'] ?? 0) : 0;
            $detail = is_array($failed_job) ? trim(wp_strip_all_tags((string)($failed_job['last_error'] ?? ''))) : '';
            $message = '任务处理失败：job_id=' . $job_id;
            if ($status !== '') {
                $message .= '，status=' . $status;
            }
            if ($attempts > 0) {
                $message .= '，attempts=' . $attempts;
            }
            if ($detail !== '') {
                $message .= '。错误详情：' . $detail;
            } else {
                $message .= '。未能读取任务错误详情，请检查任务表或运行 doctor 命令。';
            }
            return new WP_Error('wpmu_ml_job_process_failed', $message);
        }
        return true;
    }

    private function release_stale_translation_locks() {
        global $wpdb;
        $settings = $this->get_settings();
        $ttl = max(1, absint($settings['translation_lock_ttl_minutes'] ?? 10));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($ttl * 60));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->tables['jobs']}
             SET locked_at = NULL, locked_by = ''
             WHERE locked_at = '0000-00-00 00:00:00'
                OR (locked_at IS NOT NULL AND locked_at < %s)",
            $cutoff
        ));
        return (int)$wpdb->rows_affected;
    }

    public function get_translation_engine_concurrency_limit($engine) {
        $settings = $this->get_settings();
        $engine = $this->normalize_translation_engine_key($engine, '');
        if ($engine === 'openai') {
            return max(1, min(10, absint($settings['translation_openai_concurrency'] ?? 1)));
        }
        if ($this->is_opencc_engine($engine)) {
            return max(1, min(50, absint($settings['translation_opencc_concurrency'] ?? 5)));
        }
        if ($engine === 'agent') {
            return max(1, min(20, absint($settings['translation_agent_claim_limit'] ?? 1)));
        }
        return 0;
    }

    public function count_active_translation_jobs_by_engine($engine) {
        global $wpdb;
        $engine = $this->normalize_translation_engine_key($engine, '');
        if ($engine === '') {
            return 0;
        }
        if ($this->is_opencc_engine($engine)) {
            $engine_sql = "engine LIKE 'opencc_%'";
        } else {
            $engine_sql = $wpdb->prepare('engine = %s', $engine);
        }
        $sql = "SELECT COUNT(*) FROM {$this->tables['jobs']}
                WHERE {$engine_sql}
                  AND locked_at IS NOT NULL
                  AND locked_at <> '0000-00-00 00:00:00'
                  AND status NOT IN ('machine_done_published','agent_done_published','opencc_done_published','manual_done','machine_translated','agent_translated','opencc_converted')";
        return (int)$wpdb->get_var($sql);
    }

    public function can_claim_translation_engine_slot($engine) {
        $limit = $this->get_translation_engine_concurrency_limit($engine);
        if ($limit <= 0) {
            return true;
        }
        return $this->count_active_translation_jobs_by_engine($engine) < $limit;
    }

    public function process_translation_queue($limit = 2, $args = []) {
        global $wpdb;
        $settings = $this->get_settings();
        $limit = max(1, min(20, absint($limit)));
        $runner = sanitize_key($args['runner'] ?? 'manual');
        $token = $runner . '-' . substr(wp_generate_password(12, false, false), 0, 12);
        $target_lang = sanitize_key($args['target_lang'] ?? '');
        $engine_filter = sanitize_key($args['engine'] ?? '');
        $retry_failed = !empty($args['retry_failed']);
        $respect_auto_enabled = !empty($args['respect_auto_enabled']);
        $ignore_process_after = !empty($args['ignore_process_after']);
        $max_attempts = max(0, absint($settings['translation_max_attempts'] ?? 3));
        $this->release_stale_translation_locks();

        $statuses = $retry_failed ? ['pending','needs_update','translated_update_pending','failed'] : ['pending','needs_update','translated_update_pending'];
        $status_sql = implode(',', array_map(function($status) { return "'" . esc_sql($status) . "'"; }, $statuses));
        $where = "status IN ({$status_sql}) AND (locked_at IS NULL OR locked_at = '0000-00-00 00:00:00')";
        if (!$ignore_process_after) {
            $now_for_queue = current_time('mysql');
            $where .= " AND (process_after IS NULL OR process_after = '0000-00-00 00:00:00' OR process_after <= '" . esc_sql($now_for_queue) . "')";
        }
        if ($target_lang) {
            $where .= $wpdb->prepare(" AND target_lang = %s", $target_lang);
        }
        if ($engine_filter) {
            $where .= $wpdb->prepare(" AND engine = %s", $engine_filter);
        }
        if ($max_attempts > 0) {
            $where .= $wpdb->prepare(" AND attempts < %d", $max_attempts);
        }
        $scan_limit = $limit * 5;
        $jobs = $wpdb->get_results("SELECT * FROM {$this->tables['jobs']} WHERE {$where} ORDER BY priority ASC, updated_at ASC, id ASC LIMIT {$scan_limit}", ARRAY_A);

        $result = ['scanned' => count($jobs), 'locked' => 0, 'processed' => 0, 'skipped' => 0, 'failed' => 0];
        $auto_by_lang = is_array($settings['translation_auto_by_lang']) ? $settings['translation_auto_by_lang'] : [];

        foreach ($jobs as $job) {
            if ($result['processed'] >= $limit) {
                break;
            }
            $lang = sanitize_key($job['target_lang']);
            if ($respect_auto_enabled && empty($auto_by_lang[$lang])) {
                $result['skipped']++;
                continue;
            }
            $engine_for_limit = $this->normalize_translation_engine_key($job['engine'] ?? '', $job['target_lang'] ?? '');
            if ($engine_for_limit === 'manual' && empty($job['engine'])) {
                $route_for_limit = $this->resolve_translation_route($job['target_lang'] ?? '', $job['post_type'] ?? '');
                $engine_for_limit = $route_for_limit['engine'] ?? 'manual';
            }
            if (!$this->can_claim_translation_engine_slot($engine_for_limit)) {
                $result['skipped']++;
                continue;
            }
            $locked_at = current_time('mysql', true);
            $started_at = current_time('mysql');
            $locked = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->tables['jobs']}
                 SET locked_at = %s, locked_by = %s, started_at = %s, updated_at = %s
                 WHERE id = %d AND (locked_at IS NULL OR locked_at = '0000-00-00 00:00:00')",
                $locked_at,
                $token,
                $started_at,
                $started_at,
                (int)$job['id']
            ));
            if (!$locked) {
                $result['skipped']++;
                continue;
            }
            $result['locked']++;
            $job['locked_by'] = $token;
            $job['locked_at'] = $locked_at;
            $processed = $this->process_translation_job($job);
            if ($processed === true) {
                $result['processed']++;
            } else {
                $result['failed']++;
            }
        }
        return $result;
    }

    private function process_translation_job($job) {
        global $wpdb;
        $identity = $this->validate_translation_job_target($job, true);
        if (empty($identity['valid'])) {
            $relation = is_array($identity['relation'] ?? null) ? $identity['relation'] : null;
            if ($relation) {
                $this->mark_relation_invalid((int)$relation['id'], (string)$identity['error_code'], (string)$identity['message'], [
                    'source_blog_id' => (int)($job['source_blog_id'] ?? 0),
                    'source_post_id' => (int)($job['source_post_id'] ?? 0),
                    'target_blog_id' => (int)($job['target_blog_id'] ?? 0),
                    'target_post_id' => (int)($job['target_post_id'] ?? 0),
                    'post_type' => (string)($job['post_type'] ?? ''),
                    'job_id' => (int)($job['id'] ?? 0),
                    'action' => 'translation_write',
                ]);
            }
            $wpdb->update($this->tables['jobs'], [
                'status' => 'relation_invalid',
                'attempts' => ((int)($job['attempts'] ?? 0)) + 1,
                'locked_at' => null,
                'locked_by' => '',
                'process_after' => null,
                'last_error' => '目标文章身份校验失败：' . (string)$identity['message'],
                'finished_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ], ['id' => (int)($job['id'] ?? 0)], ['%s','%d','%s','%s','%s','%s','%s','%s'], ['%d']);
            return false;
        }
        if ((string)$identity['status'] === 'legacy_relation' && !empty($identity['relation'])) {
            $this->stamp_relation_target_identity($identity['relation']);
        }

        $engine = $this->normalize_translation_engine_key($job['engine'] ?? '', $job['target_lang'] ?? '');
        if ($engine === 'manual' && empty($job['engine'])) {
            $route = $this->resolve_translation_route($job['target_lang'] ?? '', $job['post_type'] ?? '');
            $engine = $route['engine'];
        }

        if ($engine === 'openai') {
            return $this->process_openai_translation_job($job, $engine);
        }

        if ($this->is_opencc_engine($engine)) {
            return $this->process_opencc_translation_job($job, $engine);
        }

        $external_result = apply_filters('wpmu_ml_process_translation_job', null, $job, $engine, $this);
        if ($external_result !== null) {
            return (bool)$external_result;
        }

        return $this->process_translation_job_placeholder($job, $engine);
    }

    private function process_translation_job_placeholder($job, $engine = '') {
        global $wpdb;
        $job_id = (int)$job['id'];
        $engine = $engine ?: $this->get_translation_engine_for_lang($job['target_lang'], $job['engine']);
        $attempts = ((int)$job['attempts']) + 1;
        $status = $engine === 'manual' ? 'manual_waiting' : 'machine_pending';
        $message = $engine === 'manual'
            ? '该语言设置为人工翻译，队列不会自动改写正文；请编辑目标草稿并发布，或点击人工完成。'
            : '该机器引擎暂未接入真实翻译；本次只完成队列锁、限流和状态流转测试。';
        $updated = $wpdb->update($this->tables['jobs'], [
            'engine' => $engine,
            'status' => $status,
            'attempts' => $attempts,
            'last_error' => $message,
            'locked_at' => null,
            'locked_by' => '',
            'process_after' => null,
            'finished_at' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $job_id], ['%s','%s','%d','%s','%s','%s','%s','%s','%s'], ['%d']);

        $fallback_status = $this->get_target_post_status_from_job($job) === 'publish' ? 'needs_update' : 'needs_translation';
        $relation_status = $this->translation_job_pending_relation_status($job, $fallback_status);
        $this->update_relation_for_job($job, $relation_status);
        $this->log('info', 'translation_queue_placeholder', '翻译队列占位处理完成', [
            'job_id' => $job_id,
            'engine' => $engine,
            'status' => $status,
            'attempts' => $attempts,
        ]);
        return $updated !== false;
    }

    private function fail_translation_job($job, $attempts, $message) {
        global $wpdb;
        if (method_exists($this, 'openai_cli_trace_performance_summary')) {
            $this->openai_cli_trace_performance_summary('translation_failed');
        }
        $settings = $this->get_settings();
        $attempts = max(0, (int)$attempts);
        $max_attempts = max(0, absint($settings['translation_max_attempts'] ?? 3));
        $retry_delay = max(0, absint($settings['translation_retry_delay_minutes'] ?? 10));
        $final_failed = ($max_attempts > 0 && $attempts >= $max_attempts);
        $next_status = $final_failed ? 'failed' : 'pending';
        $process_after = null;
        $finished_at = $final_failed ? current_time('mysql') : null;
        $last_error = (string)$message;
        if (!$final_failed) {
            $process_after = $retry_delay > 0 ? date('Y-m-d H:i:s', current_time('timestamp') + ($retry_delay * 60)) : current_time('mysql');
            $last_error = '第 ' . $attempts . ' 次失败，等待重试：' . $last_error;
        } else {
            $last_error = '达到最大重试次数 ' . $attempts . ' 次，任务失败：' . $last_error;
        }

        $wpdb->update($this->tables['jobs'], [
            'status' => $next_status,
            'attempts' => $attempts,
            'last_error' => $last_error,
            'locked_at' => null,
            'locked_by' => '',
            'process_after' => $process_after,
            'finished_at' => $finished_at,
            'updated_at' => current_time('mysql'),
        ], ['id' => (int)$job['id']], ['%s','%d','%s','%s','%s','%s','%s','%s'], ['%d']);
        $this->log('error', $final_failed ? 'translation_job_failed' : 'translation_job_retry_scheduled', $final_failed ? '翻译任务失败' : '翻译任务已安排重试', [
            'job_id' => (int)$job['id'],
            'attempts' => $attempts,
            'max_attempts' => $max_attempts,
            'process_after' => $process_after,
            'message' => $message,
        ]);
        return false;
    }

    /**
     * Public read-only language context for translation engines and REST integrations.
     * The target site's explicit AI translation tag is the primary translated-content language signal;
     * WordPress Locale, hreflang and lang_slug retain their separate language-pack, SEO and
     * routing purposes. The returned data contains no API secrets.
     */

    public function get_translation_language_context($lang = '', $blog_id = 0) {
        return $this->get_language_prompt_context($lang, $blog_id);
    }

    private function get_language_prompt_context($lang = '', $blog_id = 0) {
        global $wpdb;

        $blog_id = absint($blog_id);
        $lang_slug = sanitize_key((string)$lang);
        $site = null;

        if ($blog_id > 0) {
            $site = $wpdb->get_row($wpdb->prepare(
                "SELECT blog_id, lang_slug, locale, language_name, translation_locale, translation_language_name, hreflang FROM {$this->tables['sites']} WHERE blog_id = %d LIMIT 1",
                $blog_id
            ), ARRAY_A);
        }

        if (!$site && $lang_slug !== '') {
            $site = $wpdb->get_row($wpdb->prepare(
                "SELECT blog_id, lang_slug, locale, language_name, translation_locale, translation_language_name, hreflang FROM {$this->tables['sites']} WHERE lang_slug = %s ORDER BY enabled DESC, sort_order ASC, blog_id ASC LIMIT 1",
                $lang_slug
            ), ARRAY_A);
        }

        if (is_array($site)) {
            if ($lang_slug === '') {
                $lang_slug = sanitize_key((string)($site['lang_slug'] ?? ''));
            }
            $locale = trim((string)($site['locale'] ?? ''));
            $language_name = trim((string)($site['language_name'] ?? ''));
            $translation_locale_override = $this->normalize_language_tag((string)($site['translation_locale'] ?? ''));
            $translation_language_name = trim((string)($site['translation_language_name'] ?? ''));
            $hreflang = $this->normalize_hreflang((string)($site['hreflang'] ?? ''));
            $stored_language_name = $language_name;
            if ($blog_id <= 0) {
                $blog_id = absint($site['blog_id'] ?? 0);
            }
        } else {
            $locale = $blog_id > 0 ? trim((string)$this->get_site_wp_locale($blog_id)) : '';
            $language_name = $locale !== '' ? $this->get_locale_language_name($locale) : '';
            $translation_locale_override = '';
            $translation_language_name = '';
            $hreflang = $locale !== '' ? $this->locale_to_hreflang($locale) : '';
        }

        // WordPress Locale is refreshed from the subsite on every translation job. It controls
        // WordPress language packs and admin UI, but does not override an explicit AI translation tag.
        if ($blog_id > 0) {
            $live_locale = trim((string)$this->get_site_wp_locale($blog_id));
            if ($live_locale !== '') {
                $live_language_name = $this->get_locale_language_name($live_locale);
                $legacy_live_language_name = $this->get_locale_language_name_legacy($live_locale);
                if ($locale !== $live_locale) {
                    $locale = $live_locale;
                    if ($language_name === '' || $language_name === ($stored_language_name ?? '') || $language_name === $legacy_live_language_name) {
                        $language_name = $live_language_name;
                    }
                } elseif ($language_name === '' || $language_name === $legacy_live_language_name) {
                    $language_name = $live_language_name;
                }
            }
        }

        if ($lang_slug === '' && $locale !== '') {
            $lang_slug = sanitize_key(strtolower((string)strtok(str_replace('-', '_', $locale), '_')));
        }
        if ($locale === '' && $blog_id > 0) {
            $locale = trim((string)$this->get_site_wp_locale($blog_id));
        }
        if ($language_name === '' && $locale !== '') {
            $language_name = $this->get_locale_language_name($locale);
        }
        if ($hreflang === '' && $locale !== '') {
            $hreflang = $this->locale_to_hreflang($locale);
        }
        if ($hreflang === '' && $lang_slug !== '') {
            $hreflang = $this->normalize_hreflang(str_replace('_', '-', $lang_slug));
        }

        $locale = preg_replace('/[^A-Za-z0-9_@.\-]/', '', (string)$locale);
        $hreflang = $this->normalize_hreflang((string)$hreflang);
        $translation_locale = $translation_locale_override !== ''
            ? $translation_locale_override
            : $this->normalize_language_tag($locale !== '' ? $locale : ($hreflang !== '' ? $hreflang : $lang_slug));

        $resolved_translation_language_name = $translation_locale !== ''
            ? $this->get_locale_ai_language_name($translation_locale)
            : $this->get_locale_ai_language_name($locale);
        if ($resolved_translation_language_name !== '') {
            $translation_language_name = $resolved_translation_language_name;
        } elseif ($translation_language_name === '') {
            $translation_language_name = $language_name;
        }

        if ($lang_slug === '') {
            $lang_slug = sanitize_key(strtolower((string)strtok(str_replace('_', '-', $translation_locale ?: ($locale ?: $hreflang)), '-')));
        }

        $primary_source = $translation_locale !== '' ? $translation_locale : ($locale !== '' ? $locale : ($hreflang !== '' ? $hreflang : $lang_slug));
        $primary_source = strtolower(str_replace('_', '-', (string)$primary_source));
        $primary_source = preg_replace('/@.*$/', '', $primary_source);
        $primary = sanitize_key((string)strtok($primary_source, '-'));

        if ($blog_id > 0 && is_array($site)) {
            $wpdb->update(
                $this->tables['sites'],
                [
                    'locale' => $locale,
                    'language_name' => $language_name,
                    'translation_language_name' => $translation_language_name,
                ],
                ['blog_id' => $blog_id],
                ['%s', '%s', '%s'],
                ['%d']
            );
        }

        $parts = [];
        if ($translation_language_name !== '') {
            $parts[] = 'AI translation language ' . $translation_language_name;
        }
        if ($translation_locale !== '') {
            $parts[] = 'AI translation locale ' . $translation_locale;
        }
        if ($locale !== '') {
            $parts[] = 'WordPress site locale ' . $locale;
        }
        if ($language_name !== '') {
            $parts[] = 'WordPress language name ' . $language_name;
        }
        if ($hreflang !== '') {
            $parts[] = 'SEO hreflang ' . $hreflang;
        }
        if ($lang_slug !== '') {
            $parts[] = 'site language key ' . $lang_slug;
        }
        $prompt_label = $parts ? implode('; ', $parts) : 'the configured target language';

        return [
            'blog_id' => $blog_id,
            'lang_slug' => $lang_slug,
            'locale' => $locale,
            'language_name' => $language_name,
            'translation_locale' => $translation_locale,
            'translation_locale_override' => $translation_locale_override,
            'translation_language_name' => $translation_language_name,
            'hreflang' => $hreflang,
            'primary' => $primary,
            'prompt_label' => $prompt_label,
        ];
    }

    /**
     * Backward-compatible wrapper kept for internal/extension callers.
     */

    private function get_language_label_for_prompt($lang, $blog_id = 0) {
        $context = $this->get_language_prompt_context($lang, $blog_id);
        return (string)$context['prompt_label'];
    }

    private function translation_status_label($status) {
        $map = [
            'pending' => '待处理',
            'needs_update' => '需更新',
            'translated_update_pending' => '更新已翻译的内容',
            'manual_waiting' => '待人工翻译',
            'manual_done' => '人工完成',
            'machine_pending' => '待机器翻译',
            'machine_translated' => '机器已翻译',
            'machine_done_published' => '机器翻译并发布',
            'agent_pending' => 'Agent 待领取',
            'agent_claimed' => 'Agent 已领取',
            'agent_payload_sent' => 'Agent 已取内容',
            'agent_translated' => 'Agent 已翻译',
            'agent_done_published' => 'Agent 翻译并发布',
            'agent_failed' => 'Agent 失败',
            'opencc_converted' => 'OpenCC 已转换',
            'opencc_done_published' => 'OpenCC 转换并发布',
            'failed' => '失败',
            'skipped' => '已跳过',
            'translated' => '已翻译',
            'review_required' => '需人工复查',
            'relation_invalid' => '关系无效',
            'target_missing' => '目标文章缺失',
            'target_identity_conflict' => '目标身份冲突',
        ];
        return $map[$status] ?? $status;
    }

    private function translation_engine_label($engine) {
        $engine = sanitize_key($engine);
        $engines = array_merge($this->get_translation_engines_for_lang('zh-hant'), $this->get_default_translation_engines());
        return $engines[$engine] ?? $engine;
    }
    }
}
