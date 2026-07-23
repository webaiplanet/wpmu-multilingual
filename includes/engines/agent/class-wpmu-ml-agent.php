<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * External Agent translation engine.
 *
 * WordPress provides task payloads, shared translation rules and write-back tools.
 * The local/external Agent still owns its model and execution workflow, but it can read the
 * same site rules, glossary and excluded-field settings used by the internal engine.
 */
final class WPMU_ML_Agent {
    private static $instance = null;
    private $core;
    private $namespace = 'wpmu-ml/v1';
    private $payload_builder;
    private $result_applier;
    private $validator;
    private $tools;

    public static function instance($core = null) {
        if (null === self::$instance && $core) {
            self::$instance = new self($core);
        }
        return self::$instance;
    }

    private function __construct($core) {
        $this->core = $core;
        $this->payload_builder = new WPMU_ML_Agent_Payload($core);
        $this->result_applier = new WPMU_ML_Agent_Result_Applier($core);
        $this->validator = new WPMU_ML_Agent_Validator();
        if (class_exists('WPMU_ML_Agent_Tools')) {
            $this->tools = new WPMU_ML_Agent_Tools($core);
        }

        add_filter('wpmu_ml_registered_translation_engines', [$this, 'register_translation_engine'], 10, 3);
        add_filter('wpmu_ml_process_translation_job', [$this, 'process_translation_job'], 10, 4);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_translation_engine($engines, $context = 'default', $target_lang = '') {
        if (!is_array($engines)) {
            $engines = [];
        }
        $engines['agent'] = 'Agent API';
        return $engines;
    }

    /**
     * Queue runner marks the task as waiting for external Agent handoff.
     */
    public function process_translation_job($result, $job, $engine, $core) {
        if (sanitize_key($engine) !== 'agent') {
            return $result;
        }
        global $wpdb;
        $job_id = (int)($job['id'] ?? 0);
        if (!$job_id) {
            return false;
        }
        $jobs_table = $this->core->get_table_name('jobs');
        $updated = $wpdb->update($jobs_table, [
            'engine' => 'agent',
            'status' => 'agent_pending',
            'last_error' => 'Agent API 等待外部 Agent 领取任务。',
            'locked_at' => null,
            'locked_by' => '',
            'process_after' => null,
            'finished_at' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => $job_id], ['%s','%s','%s','%s','%s','%s','%s','%s'], ['%d']);

        $fallback_status = $this->target_post_is_published($job) ? 'needs_update' : 'needs_translation';
        $this->update_relation_for_job($job, $this->core->translation_job_pending_relation_status($job, $fallback_status));
        $this->log('info', 'agent_job_pending', 'Agent 翻译任务已进入外部领取状态', ['job_id' => $job_id]);
        return $updated !== false;
    }

    public function register_routes() {
        register_rest_route($this->namespace, '/agent/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'rest_health'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        register_rest_route($this->namespace, '/agent/rules', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'rest_rules'],
            'permission_callback' => [$this, 'permission_callback'],
            'args' => [
                'target_lang' => ['required' => false],
                'target_blog_id' => ['required' => false, 'type' => 'integer'],
            ],
        ]);

        register_rest_route($this->namespace, '/agent/next', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'rest_next_job'],
            'permission_callback' => [$this, 'permission_callback'],
            'args' => [
                'target_lang' => ['required' => false],
            ],
        ]);

        register_rest_route($this->namespace, '/agent/claim', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rest_claim_job'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        register_rest_route($this->namespace, '/agent/payload', [
            'methods' => [WP_REST_Server::READABLE, WP_REST_Server::CREATABLE],
            'callback' => [$this, 'rest_payload'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        register_rest_route($this->namespace, '/agent/result', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rest_result'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        register_rest_route($this->namespace, '/agent/fail', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rest_fail'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        register_rest_route($this->namespace, '/agent/heartbeat', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rest_heartbeat'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        register_rest_route($this->namespace, '/agent/release', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rest_release'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);
    }

    public function permission_callback(WP_REST_Request $request) {
        $settings = $this->core->get_settings();
        $expected = trim((string)($settings['agent_api_token'] ?? ''));
        if ($expected === '') {
            return new WP_Error('wpmu_ml_agent_api_disabled', 'Agent API Key 为空，Agent API 未启用。', ['status' => 403]);
        }
        $auth = (string)$request->get_header('authorization');
        if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return new WP_Error('wpmu_ml_agent_missing_token', '缺少 Authorization: Bearer token。', ['status' => 401]);
        }
        $token = trim((string)$m[1]);
        if (!hash_equals($expected, $token)) {
            return new WP_Error('wpmu_ml_agent_invalid_token', 'Agent API Key 无效。', ['status' => 403]);
        }
        return true;
    }

    public function rest_health(WP_REST_Request $request) {
        return rest_ensure_response([
            'ok' => true,
            'plugin' => 'wpmu-multilingual',
            'version' => defined('WPMU_ML_VERSION') ? WPMU_ML_VERSION : '',
            'multisite' => is_multisite(),
            'agent_api' => true,
            'namespace' => $this->namespace,
            'endpoints' => [
                'rules' => '/wp-json/' . $this->namespace . '/agent/rules',
                'next' => '/wp-json/' . $this->namespace . '/agent/next',
                'claim' => '/wp-json/' . $this->namespace . '/agent/claim',
                'payload' => '/wp-json/' . $this->namespace . '/agent/payload',
                'result' => '/wp-json/' . $this->namespace . '/agent/result',
                'fail' => '/wp-json/' . $this->namespace . '/agent/fail',
                'heartbeat' => '/wp-json/' . $this->namespace . '/agent/heartbeat',
                'release' => '/wp-json/' . $this->namespace . '/agent/release',
            ],
        ]);
    }

    public function rest_rules(WP_REST_Request $request) {
        $target_lang = sanitize_key((string)$request->get_param('target_lang'));
        $target_blog_id = absint($request->get_param('target_blog_id'));
        $rules = $this->payload_builder->get_shared_translation_rules($target_lang, $target_blog_id);

        return rest_ensure_response([
            'ok' => true,
            'api_version' => '1.1',
            'rules' => $rules,
            'usage' => [
                'Use rules.site_rules as the shared site-wide translation Skill.',
                'Use rules.target_language.translation_locale as the effective content language variant.',
                'Apply every instruction in rules.built_in_rules so the result reads like native-written, natural and trustworthy website content.',
                'Use rules.glossary.effective_for_target for target-specific fixed translations.',
                'Do not return or translate meta keys listed in rules.excluded_custom_fields.effective_patterns.',
                'The same rules object is also embedded in every /agent/payload response.',
            ],
        ]);
    }

    public function rest_next_job(WP_REST_Request $request) {
        global $wpdb;
        $this->release_expired_agent_locks();

        $jobs_table = $this->core->get_table_name('jobs');
        $settings = $this->core->get_settings();
        if (method_exists($this->core, 'can_claim_translation_engine_slot') && !$this->core->can_claim_translation_engine_slot('agent')) {
            return rest_ensure_response([
                'ok' => true,
                'job' => null,
                'message' => 'Agent API 已达到最大领取数，请等待已有任务完成或释放锁。',
                'active_claims' => method_exists($this->core, 'count_active_translation_jobs_by_engine') ? $this->core->count_active_translation_jobs_by_engine('agent') : null,
                'claim_limit' => method_exists($this->core, 'get_translation_engine_concurrency_limit') ? $this->core->get_translation_engine_concurrency_limit('agent') : null,
            ]);
        }
        $target_lang = sanitize_key((string)$request->get_param('target_lang'));
        $max_attempts = max(0, absint($settings['translation_max_attempts'] ?? 3));
        $now = current_time('mysql');

        $where = "engine = 'agent' AND status IN ('pending','needs_update','agent_pending') AND (locked_at IS NULL OR locked_at = '0000-00-00 00:00:00')";
        $where .= " AND (process_after IS NULL OR process_after = '0000-00-00 00:00:00' OR process_after <= '" . esc_sql($now) . "')";
        if ($target_lang) {
            $where .= $wpdb->prepare(' AND target_lang = %s', $target_lang);
        }
        if ($max_attempts > 0) {
            $where .= $wpdb->prepare(' AND attempts < %d', $max_attempts);
        }
        $job = $wpdb->get_row("SELECT * FROM {$jobs_table} WHERE {$where} ORDER BY priority ASC, updated_at ASC, id ASC LIMIT 1", ARRAY_A);
        return rest_ensure_response([
            'ok' => true,
            'job' => $job ? $this->summarize_job($job) : null,
        ]);
    }

    public function rest_claim_job(WP_REST_Request $request) {
        global $wpdb;
        $this->release_expired_agent_locks();

        $job_id = absint($request->get_param('job_id'));
        $agent_id = sanitize_text_field((string)$request->get_param('agent_id'));
        if (!$job_id) {
            return new WP_Error('wpmu_ml_agent_invalid_job_id', '缺少有效 job_id。', ['status' => 400]);
        }
        if ($agent_id === '') {
            $agent_id = 'external-agent';
        }
        if (method_exists($this->core, 'can_claim_translation_engine_slot') && !$this->core->can_claim_translation_engine_slot('agent')) {
            return new WP_Error('wpmu_ml_agent_claim_limit_reached', 'Agent API 已达到最大领取数，请等待已有任务完成或释放锁。', ['status' => 429]);
        }
        $claim_token = 'agent-' . substr(wp_generate_password(32, false, false), 0, 32);
        $locked_at = current_time('mysql');
        $jobs_table = $this->core->get_table_name('jobs');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$jobs_table}
             SET status = 'agent_claimed', locked_at = %s, locked_by = %s, started_at = %s, updated_at = %s, attempts = attempts + 1, last_error = %s
             WHERE id = %d
               AND engine = 'agent'
               AND status IN ('pending','needs_update','agent_pending')
               AND (locked_at IS NULL OR locked_at = '0000-00-00 00:00:00')",
            $locked_at,
            $claim_token,
            $locked_at,
            $locked_at,
            'Agent 已领取任务：' . $agent_id,
            $job_id
        ));
        if ($updated === false) {
            return new WP_Error('wpmu_ml_agent_claim_failed', '领取任务失败：' . $wpdb->last_error, ['status' => 500]);
        }
        if (!$updated) {
            return new WP_Error('wpmu_ml_agent_job_unavailable', '任务不存在、不是 agent 引擎、状态不可领取或已被锁定。', ['status' => 409]);
        }
        return rest_ensure_response([
            'ok' => true,
            'job_id' => $job_id,
            'claim_token' => $claim_token,
            'status' => 'agent_claimed',
            'lock_ttl_seconds' => $this->lock_ttl_seconds(),
        ]);
    }

    public function rest_payload(WP_REST_Request $request) {
        $job_id = absint($request->get_param('job_id'));
        $claim_token = sanitize_text_field((string)$request->get_param('claim_token'));
        if (!$job_id || $claim_token === '') {
            return new WP_Error('wpmu_ml_agent_payload_args', '请提供 job_id 和 claim_token。', ['status' => 400]);
        }
        $job = $this->get_claimed_job($job_id, $claim_token);
        if (is_wp_error($job)) {
            return $job;
        }
        $payload = $this->payload_builder->build($job, $claim_token);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $this->mark_payload_sent($job_id, $claim_token);
        return rest_ensure_response($payload);
    }

    public function rest_result(WP_REST_Request $request) {
        global $wpdb;
        $job_id = absint($request->get_param('job_id'));
        $claim_token = sanitize_text_field((string)$request->get_param('claim_token'));
        if (!$job_id || $claim_token === '') {
            return new WP_Error('wpmu_ml_agent_result_args', '请提供 job_id 和 claim_token。', ['status' => 400]);
        }
        $job = $this->get_claimed_job($job_id, $claim_token, ['agent_claimed','agent_payload_sent']);
        if (is_wp_error($job)) {
            return $job;
        }

        $payload = $this->payload_builder->build($job, $claim_token);
        if (is_wp_error($payload)) {
            return $payload;
        }
        $source_hash = sanitize_text_field((string)$request->get_param('source_hash'));
        if ($source_hash === '' || !hash_equals((string)$payload['source_hash'], $source_hash)) {
            $this->requeue_source_changed($job_id);
            return new WP_Error('wpmu_ml_agent_source_changed', 'source_hash 不匹配，源内容可能已更新，已重新入队，请重新领取任务。', ['status' => 409]);
        }

        $fields = $request->get_param('fields');
        if (!is_array($fields)) {
            return new WP_Error('wpmu_ml_agent_result_fields', 'fields 必须是数组。', ['status' => 400]);
        }
        $source_fields = [];
        foreach ((array)$payload['fields'] as $field) {
            $source_fields[(string)$field['field_id']] = $field;
        }
        $targets = [];
        foreach ($fields as $key => $field) {
            if (is_array($field)) {
                $field_id = sanitize_text_field((string)($field['field_id'] ?? ''));
                $target = (string)($field['target'] ?? '');
            } else {
                $field_id = is_string($key) ? sanitize_text_field($key) : '';
                $target = is_scalar($field) ? (string)$field : '';
            }
            if ($field_id === '' || !isset($source_fields[$field_id])) {
                return new WP_Error('wpmu_ml_agent_unknown_field', '返回了未知 field_id：' . $field_id, ['status' => 400]);
            }
            $targets[$field_id] = $target;
        }

        $validation = $this->validator->validate($payload, $targets);
        if (is_wp_error($validation)) {
            $this->mark_review_required($job, $validation->get_error_message());
            return $validation;
        }

        $apply = $this->result_applier->apply($job, $targets, $payload);
        if (is_wp_error($apply)) {
            return $apply;
        }
        if (method_exists($this->core, 'mark_translation_content_completed')) {
            $this->core->mark_translation_content_completed($job);
        }

        $complete_status = (string)$apply['post_status'];
        $job_status = $complete_status === 'publish' ? 'agent_done_published' : 'agent_translated';
        $message = 'Agent 翻译完成，目标文章状态：' . $complete_status;
        $message .= '；字段写回 ' . intval($apply['meta_translated'] ?? 0) . ' 项，跳过 ' . intval($apply['meta_skipped'] ?? 0) . ' 项';
        $wpdb->update($this->core->get_table_name('jobs'), [
            'status' => $job_status,
            'last_error' => $message,
            'locked_at' => null,
            'locked_by' => '',
            'process_after' => null,
            'finished_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $job_id], ['%s','%s','%s','%s','%s','%s','%s'], ['%d']);
        $this->update_relation_for_job($job, $complete_status === 'publish' ? 'translated' : 'machine_translated', $complete_status);
        $this->log('info', 'agent_job_done', 'Agent 翻译结果已写入目标文章', [
            'job_id' => $job_id,
            'status' => $job_status,
            'meta_translated' => intval($apply['meta_translated'] ?? 0),
        ]);
        return rest_ensure_response([
            'ok' => true,
            'job_id' => $job_id,
            'status' => $job_status,
            'target_post_id' => (int)$job['target_post_id'],
            'target_post_status' => $complete_status,
            'meta_translated' => intval($apply['meta_translated'] ?? 0),
        ]);
    }

    public function rest_fail(WP_REST_Request $request) {
        global $wpdb;
        $job_id = absint($request->get_param('job_id'));
        $claim_token = sanitize_text_field((string)$request->get_param('claim_token'));
        if (!$job_id || $claim_token === '') {
            return new WP_Error('wpmu_ml_agent_fail_args', '请提供 job_id 和 claim_token。', ['status' => 400]);
        }
        $job = $this->get_claimed_job($job_id, $claim_token, ['agent_claimed','agent_payload_sent']);
        if (is_wp_error($job)) {
            return $job;
        }
        $settings = $this->core->get_settings();
        $retry_param = $request->get_param('retryable');
        $retryable = ($retry_param === null) ? true : (bool)$retry_param;
        $max_attempts = max(0, absint($settings['translation_max_attempts'] ?? 3));
        $attempts = (int)($job['attempts'] ?? 0);
        $status = ($retryable && ($max_attempts <= 0 || $attempts < $max_attempts)) ? 'agent_pending' : 'agent_failed';
        $delay = max(0, absint($settings['translation_retry_delay_minutes'] ?? 10));
        $process_after = $status === 'agent_pending' && $delay > 0 ? date('Y-m-d H:i:s', current_time('timestamp') + ($delay * 60)) : null;
        $error = sanitize_textarea_field((string)$request->get_param('error'));
        if ($error === '') {
            $error = '外部 Agent 返回失败。';
        }
        $wpdb->update($this->core->get_table_name('jobs'), [
            'status' => $status,
            'last_error' => $error,
            'locked_at' => null,
            'locked_by' => '',
            'process_after' => $process_after,
            'finished_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => $job_id], ['%s','%s','%s','%s','%s','%s','%s'], ['%d']);
        $this->update_relation_for_job($job, $status === 'agent_pending' ? 'needs_translation' : 'review_required');
        return rest_ensure_response([
            'ok' => true,
            'job_id' => $job_id,
            'status' => $status,
            'process_after' => $process_after,
        ]);
    }

    public function rest_heartbeat(WP_REST_Request $request) {
        global $wpdb;
        $job_id = absint($request->get_param('job_id'));
        $claim_token = sanitize_text_field((string)$request->get_param('claim_token'));
        if (!$job_id || $claim_token === '') {
            return new WP_Error('wpmu_ml_agent_heartbeat_args', '请提供 job_id 和 claim_token。', ['status' => 400]);
        }
        $job = $this->get_claimed_job($job_id, $claim_token, ['agent_claimed','agent_payload_sent']);
        if (is_wp_error($job)) {
            return $job;
        }
        $now = current_time('mysql');
        $wpdb->update($this->core->get_table_name('jobs'), [
            'locked_at' => $now,
            'updated_at' => $now,
            'last_error' => 'Agent heartbeat 已续期。',
        ], ['id' => $job_id, 'locked_by' => $claim_token], ['%s','%s','%s'], ['%d','%s']);
        return rest_ensure_response([
            'ok' => true,
            'job_id' => $job_id,
            'locked_at' => $now,
            'lock_ttl_seconds' => $this->lock_ttl_seconds(),
        ]);
    }

    public function rest_release(WP_REST_Request $request) {
        global $wpdb;
        $job_id = absint($request->get_param('job_id'));
        $claim_token = sanitize_text_field((string)$request->get_param('claim_token'));
        if (!$job_id || $claim_token === '') {
            return new WP_Error('wpmu_ml_agent_release_args', '请提供 job_id 和 claim_token。', ['status' => 400]);
        }
        $job = $this->get_claimed_job($job_id, $claim_token, ['agent_claimed','agent_payload_sent']);
        if (is_wp_error($job)) {
            return $job;
        }
        $wpdb->update($this->core->get_table_name('jobs'), [
            'status' => 'agent_pending',
            'locked_at' => null,
            'locked_by' => '',
            'last_error' => 'Agent 主动释放任务，等待重新领取。',
            'updated_at' => current_time('mysql'),
        ], ['id' => $job_id], ['%s','%s','%s','%s','%s'], ['%d']);
        return rest_ensure_response([
            'ok' => true,
            'job_id' => $job_id,
            'status' => 'agent_pending',
        ]);
    }

    private function get_claimed_job($job_id, $claim_token, $statuses = ['agent_claimed','agent_payload_sent']) {
        global $wpdb;
        $jobs_table = $this->core->get_table_name('jobs');
        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));
        $params = array_merge([$job_id, $claim_token], $statuses);
        $sql = $wpdb->prepare("SELECT * FROM {$jobs_table} WHERE id = %d AND engine = 'agent' AND locked_by = %s AND status IN ({$placeholders}) LIMIT 1", $params);
        $job = $wpdb->get_row($sql, ARRAY_A);
        if (!$job) {
            return new WP_Error('wpmu_ml_agent_claim_not_found', '任务未领取、claim_token 不匹配或状态不可用。', ['status' => 409]);
        }
        return $job;
    }

    private function mark_payload_sent($job_id, $claim_token) {
        global $wpdb;
        $wpdb->update($this->core->get_table_name('jobs'), [
            'status' => 'agent_payload_sent',
            'last_error' => 'Agent payload 已发送，等待外部 Agent 回传译文。',
            'updated_at' => current_time('mysql'),
        ], ['id' => (int)$job_id, 'locked_by' => (string)$claim_token], ['%s','%s','%s'], ['%d','%s']);
    }

    private function requeue_source_changed($job_id) {
        global $wpdb;
        $wpdb->update($this->core->get_table_name('jobs'), [
            'status' => 'agent_pending',
            'last_error' => 'source_hash 不匹配，源内容可能已更新，已拒绝写回旧译文并重新入队。',
            'locked_at' => null,
            'locked_by' => '',
            'process_after' => null,
            'finished_at' => null,
            'updated_at' => current_time('mysql'),
        ], ['id' => (int)$job_id], ['%s','%s','%s','%s','%s','%s','%s'], ['%d']);
    }

    private function mark_review_required($job, $message) {
        global $wpdb;
        $wpdb->update($this->core->get_table_name('jobs'), [
            'status' => 'review_required',
            'last_error' => 'Agent 结果校验未通过：' . (string)$message,
            'locked_at' => null,
            'locked_by' => '',
            'process_after' => null,
            'finished_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ], ['id' => (int)$job['id']], ['%s','%s','%s','%s','%s','%s','%s'], ['%d']);
        $this->update_relation_for_job($job, 'review_required');
    }

    private function release_expired_agent_locks() {
        global $wpdb;
        $ttl = $this->lock_ttl_seconds();
        $threshold = date('Y-m-d H:i:s', current_time('timestamp') - $ttl);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->core->get_table_name('jobs')}
             SET status = 'agent_pending', locked_at = NULL, locked_by = '', last_error = %s, updated_at = %s
             WHERE engine = 'agent'
               AND status IN ('agent_claimed','agent_payload_sent')
               AND locked_at IS NOT NULL
               AND locked_at <> '0000-00-00 00:00:00'
               AND locked_at < %s",
            'Agent 锁超时，已自动释放，等待重新领取。',
            current_time('mysql'),
            $threshold
        ));
    }

    private function lock_ttl_seconds() {
        $settings = $this->core->get_settings();
        $minutes = max(5, absint($settings['agent_api_lock_ttl_minutes'] ?? 15));
        return $minutes * MINUTE_IN_SECONDS;
    }

    private function summarize_job($job) {
        return [
            'job_id' => (int)$job['id'],
            'source_blog_id' => (int)$job['source_blog_id'],
            'source_post_id' => (int)$job['source_post_id'],
            'target_blog_id' => (int)$job['target_blog_id'],
            'target_post_id' => (int)$job['target_post_id'],
            'source_lang' => (string)$job['source_lang'],
            'target_lang' => (string)$job['target_lang'],
            'post_type' => (string)$job['post_type'],
            'engine' => (string)$job['engine'],
            'model' => (string)($job['model'] ?? ''),
            'route_reason' => (string)($job['route_reason'] ?? ''),
            'complete_status' => (string)($job['complete_status'] ?? ''),
            'status' => (string)$job['status'],
            'attempts' => (int)$job['attempts'],
            'updated_at' => (string)$job['updated_at'],
        ];
    }

    private function target_post_is_published($job) {
        $published = false;
        switch_to_blog((int)($job['target_blog_id'] ?? 0));
        $post = get_post((int)($job['target_post_id'] ?? 0));
        if ($post && $post->post_status === 'publish') {
            $published = true;
        }
        restore_current_blog();
        return $published;
    }

    private function update_relation_for_job($job, $relation_status, $target_post_status = '') {
        global $wpdb;
        $posts_table = $this->core->get_table_name('posts');
        $data = [
            'relation_status' => sanitize_key($relation_status),
            'updated_at' => current_time('mysql'),
        ];
        $formats = ['%s','%s'];
        if ($target_post_status !== '') {
            $data['target_post_status'] = sanitize_key($target_post_status);
            $formats[] = '%s';
        }
        $wpdb->update($posts_table, $data, [
            'source_blog_id' => (int)$job['source_blog_id'],
            'source_post_id' => (int)$job['source_post_id'],
            'target_blog_id' => (int)$job['target_blog_id'],
        ], $formats, ['%d','%d','%d']);
    }

    private function log($level, $action, $message, $context = []) {
        global $wpdb;
        $logs_table = $this->core->get_table_name('logs');
        $wpdb->insert($logs_table, [
            'level' => sanitize_key($level),
            'action' => sanitize_key($action),
            'message' => (string)$message,
            'context' => wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => current_time('mysql'),
        ]);
    }
}
