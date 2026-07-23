<?php
if (!defined('ABSPATH')) {
    exit(1);
}

global $wpdb;
$core = WPMU_Multilingual::instance();
$sync = new ReflectionMethod($core, 'sync_one_target_secure');
$snapshot = new ReflectionMethod($core, 'build_source_field_snapshot');
$diff = new ReflectionMethod($core, 'diff_source_field_snapshots');
$wpdb->query('START TRANSACTION');

try {
    remove_action('save_post', [$core, 'maybe_auto_sync_source_post'], 30);
    $source_site = $wpdb->get_row("SELECT * FROM yzk_wpmu_ml_sites WHERE blog_id=2", ARRAY_A);
    $target_site = $wpdb->get_row("SELECT * FROM yzk_wpmu_ml_sites WHERE blog_id=1", ARRAY_A);
    $suffix = strtolower(wp_generate_password(8, false, false));

    $run_case = function($translated) use ($wpdb, $core, $sync, $snapshot, $diff, $source_site, $target_site, $suffix) {
        $case = $translated ? 'translated' : 'untranslated';
        $slug = 'wpmu-ml-incremental-' . $case . '-' . $suffix;

        switch_to_blog(2);
        $source_id = wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => '源标题 ' . $case,
            'post_excerpt' => '源摘要',
            'post_content' => '<p>源正文</p>',
            'post_name' => $slug,
        ], true);
        add_post_meta($source_id, 'wpmu_ml_delta_test_text', '新的源字段');
        $source_post = get_post($source_id);
        $source_meta = get_post_meta($source_id);
        restore_current_blog();

        switch_to_blog(1);
        $target_id = wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'draft',
            'post_title' => $translated ? 'Translated title' : $source_post->post_title,
            'post_excerpt' => $translated ? 'Translated excerpt' : $source_post->post_excerpt,
            'post_content' => $translated ? '<p>Translated body</p>' : $source_post->post_content,
            'post_name' => $slug,
        ], true);
        if ($translated) {
            add_post_meta($target_id, 'wpmu_ml_delta_test_text', 'Old translated field');
        }
        restore_current_blog();

        $wpdb->insert('yzk_wpmu_ml_post_relations', [
            'source_blog_id' => 2,
            'source_post_id' => $source_id,
            'target_blog_id' => 1,
            'target_post_id' => $target_id,
            'source_lang' => 'zh-hans',
            'target_lang' => 'en',
            'post_type' => 'post',
            'target_post_status' => 'draft',
            'relation_status' => $translated ? 'translated' : 'needs_translation',
            'source_modified' => $source_post->post_modified,
            'target_modified' => current_time('mysql'),
        ]);

        if ($translated) {
            $wpdb->insert('yzk_wpmu_ml_translation_jobs', [
                'source_blog_id' => 2,
                'source_post_id' => $source_id,
                'target_blog_id' => 1,
                'target_post_id' => $target_id,
                'source_lang' => 'zh-hans',
                'target_lang' => 'en',
                'post_type' => 'post',
                'engine' => 'openai',
                'status' => 'machine_translated',
                'translated_content' => 1,
            ]);
        }

        $context = [];
        if ($translated) {
            $old_meta = $source_meta;
            $old_meta['wpmu_ml_delta_test_text'] = ['旧的源字段'];
            $previous = $snapshot->invoke($core, $source_post, $old_meta);
            $current = $snapshot->invoke($core, $source_post, $source_meta);
            $context = [
                'previous_snapshot' => $previous,
                'current_snapshot' => $current,
                'changes' => $diff->invoke($core, $previous, $current),
            ];
        }

        $result = $sync->invoke($core, $source_site, $source_post, $source_meta, [], $target_site, false, $context);
        $job = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM yzk_wpmu_ml_translation_jobs WHERE source_blog_id=2 AND source_post_id=%d AND target_blog_id=1",
            $source_id
        ), ARRAY_A);
        $agent_field_ids = [];
        if (class_exists('WPMU_ML_Agent_Payload')) {
            $agent_payload = (new WPMU_ML_Agent_Payload($core))->build($job, 'smoke-token');
            if (is_array($agent_payload)) {
                $agent_field_ids = array_values(array_map(function($field) {
                    return (string)($field['field_id'] ?? '');
                }, (array)($agent_payload['fields'] ?? [])));
            }
        }
        switch_to_blog(1);
        $target_after = get_post($target_id);
        $target_meta = get_post_meta($target_id, 'wpmu_ml_delta_test_text', true);
        restore_current_blog();

        return [
            'case' => $case,
            'sync_result' => $result,
            'target_title' => $target_after->post_title,
            'target_content' => $target_after->post_content,
            'target_meta' => $target_meta,
            'job_status' => $job['status'] ?? '',
            'job_type' => $job['job_type'] ?? '',
            'translated_content' => (int)($job['translated_content'] ?? 0),
            'manifest' => json_decode((string)($job['change_manifest'] ?? ''), true),
            'agent_field_ids' => $agent_field_ids,
        ];
    };

    $output = [
        'untranslated' => $run_case(false),
        'translated' => $run_case(true),
    ];
    $untranslated = $output['untranslated'];
    $translated = $output['translated'];
    $checks = [
        $untranslated['target_meta'] === '新的源字段',
        $untranslated['job_status'] === 'pending',
        $untranslated['manifest']['core'] === [],
        $untranslated['manifest']['meta'] === ['wpmu_ml_delta_test_text'],
        count($untranslated['agent_field_ids']) === 1 && strpos($untranslated['agent_field_ids'][0], 'meta:') === 0,
        $translated['target_title'] === 'Translated title',
        $translated['target_content'] === '<p>Translated body</p>',
        $translated['target_meta'] === 'Old translated field',
        $translated['job_status'] === 'translated_update_pending',
        $translated['manifest']['core'] === [],
        $translated['manifest']['meta'] === ['wpmu_ml_delta_test_text'],
        count($translated['agent_field_ids']) === 1 && strpos($translated['agent_field_ids'][0], 'meta:') === 0,
    ];
    if (in_array(false, $checks, true)) {
        throw new RuntimeException('Incremental sync smoke assertions failed.');
    }
    echo wp_json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
} finally {
    $wpdb->query('ROLLBACK');
}
