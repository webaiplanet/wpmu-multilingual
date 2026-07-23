<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WP-CLI command registration for WPMU Multilingual.
 *
 * Kept outside the core class so queue/sync/diagnostic command maintenance does not
 * keep inflating includes/core/class-wpmu-ml-core.php.
 */
final class WPMU_ML_CLI {
    private static $registered = false;

    public static function register() {
        if (self::$registered || !defined('WP_CLI') || !WP_CLI) {
            return;
        }
        self::$registered = true;

                WP_CLI::add_command('wpmu-ml rebuild', function() {
                    $result = WPMU_Multilingual::instance()->rebuild_relations();
                    if (is_wp_error($result)) {
                        WP_CLI::error($result->get_error_message());
                    }
                    WP_CLI::success('关联重建完成：文章关联 ' . intval($result['post_relations']) . ' 条，分类关联 ' . intval($result['term_relations']) . ' 条。');
                });
                WP_CLI::add_command('wpmu-ml audit-relations', function($args, $assoc_args) {
                    if (!empty($assoc_args['summary'])) {
                        $summary = WPMU_Multilingual::instance()->audit_post_relations_summary(
                            isset($assoc_args['target_blog_id']) ? absint($assoc_args['target_blog_id']) : 0
                        );
                        if (is_wp_error($summary)) {
                            WP_CLI::error($summary->get_error_message());
                        }
                        foreach ((array)$summary['sites'] as $site) {
                            WP_CLI::line(
                                'blog=' . intval($site['blog_id']) . ' lang=' . (string)$site['lang_slug']
                                . ' relations=' . intval($site['relations'])
                                . ' source_missing=' . intval($site['source_missing'])
                                . ' target_missing=' . intval($site['target_missing'])
                                . ' source_type_conflict=' . intval($site['source_type_conflict'])
                                . ' target_type_conflict=' . intval($site['target_type_conflict'])
                                . ' meta_missing=' . intval($site['identity_meta_missing'])
                                . ' meta_conflict=' . intval($site['identity_meta_conflict'])
                                . ' strict_identity=' . intval($site['strict_identity'])
                                . ' fallback_review=' . intval($site['fallback_review'])
                                . ' slug_conflicts=' . intval($site['slug_conflicts'])
                                . ' invalid_status=' . intval($site['invalid_status'])
                                . ' duplicate_targets=' . intval($site['duplicate_targets'])
                            );
                        }
                        $totals = $summary['totals'];
                        WP_CLI::line('TOTAL ' . implode(' ', array_map(function($key) use ($totals) {
                            return $key . '=' . intval($totals[$key]);
                        }, array_keys($totals))));
                        $blocking = intval($totals['source_type_conflict']) + intval($totals['target_type_conflict']) + intval($totals['identity_meta_conflict']) + intval($totals['slug_conflicts']) + intval($totals['duplicate_targets']);
                        if ($blocking > 0) {
                            WP_CLI::warning('汇总审计发现身份或 slug 冲突；本命令未修改数据库。');
                        } else {
                            WP_CLI::success('汇总审计完成，未发现类型、身份 meta、slug 或重复目标冲突；本命令未修改数据库。');
                        }
                        return;
                    }
                    $result = WPMU_Multilingual::instance()->audit_post_relations([
                        'limit' => isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 500,
                        'offset' => isset($assoc_args['offset']) ? absint($assoc_args['offset']) : 0,
                        'target_blog_id' => isset($assoc_args['target_blog_id']) ? absint($assoc_args['target_blog_id']) : 0,
                        'source_post_id' => isset($assoc_args['source_post_id']) ? absint($assoc_args['source_post_id']) : 0,
                    ]);
                    WP_CLI::line('关系审计：扫描 ' . intval($result['scanned']) . ' 条；严格身份 ' . intval($result['strict_identity']) . '；历史兼容 ' . intval($result['legacy_relation']) . '；目标缺失 ' . intval($result['target_missing']) . '；身份冲突 ' . intval($result['target_identity_conflict']) . '；slug 冲突 ' . intval($result['target_slug_conflict']) . '；关系无效 ' . intval($result['relation_invalid']) . '。');
                    foreach ((array)$result['items'] as $item) {
                        WP_CLI::warning(
                            'relation_id=' . intval($item['relation_id'])
                            . ' source=' . intval($item['source_blog_id']) . ':' . intval($item['source_post_id'])
                            . ' target=' . intval($item['target_blog_id']) . ':' . intval($item['target_post_id'])
                            . ' code=' . (string)$item['error_code']
                            . ' message=' . (string)$item['message']
                        );
                    }
                    if (!empty($result['items'])) {
                        WP_CLI::warning('审计完成并发现异常；本命令未修改数据库。');
                        return;
                    }
                    WP_CLI::success('审计完成，当前扫描范围未发现阻断性异常；本命令未修改数据库。');
                });
                WP_CLI::add_command('wpmu-ml reconcile-relations', function($args, $assoc_args) {
                    $result = WPMU_Multilingual::instance()->reconcile_post_relations_from_meta([
                        'target_blog_id' => isset($assoc_args['target_blog_id']) ? absint($assoc_args['target_blog_id']) : 0,
                        'limit' => isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 500,
                        'offset' => isset($assoc_args['offset']) ? absint($assoc_args['offset']) : 0,
                        'apply' => !empty($assoc_args['apply']),
                        'confirm' => isset($assoc_args['confirm']) ? (string)$assoc_args['confirm'] : '',
                    ]);
                    if (is_wp_error($result)) {
                        WP_CLI::error($result->get_error_message());
                    }
                    WP_CLI::line('严格关系恢复：扫描 ' . intval($result['scanned']) . '；已有 ' . intval($result['existing']) . '；候选 ' . intval($result['candidates']) . '；写入 ' . intval($result['inserted']) . '；冲突 ' . intval($result['conflicts']) . '；错误 ' . intval($result['errors']) . '。');
                    foreach ((array)$result['items'] as $item) {
                        WP_CLI::line(' - source_post_id=' . intval($item['source_post_id']) . ' target_post_id=' . intval($item['target_post_id']) . ' post_type=' . (string)$item['post_type'] . ' code=' . (string)$item['code'] . ' message=' . (string)$item['message']);
                    }
                    if (!empty($result['dry_run'])) {
                        WP_CLI::success('dry-run 完成，未修改数据库。写入时必须显式增加 --apply --confirm=ADD_META_RELATIONS。');
                    } elseif (!empty($result['errors']) || !empty($result['conflicts'])) {
                        WP_CLI::warning('写入完成，但存在冲突或错误，请人工检查输出。');
                    } else {
                        WP_CLI::success('严格来源 meta 关系补充完成。');
                    }
                });
                WP_CLI::add_command('wpmu-ml sync', function($args, $assoc_args) {
                    $limit = isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 20;
                    $result = WPMU_Multilingual::instance()->batch_sync_recent_source_posts($limit);
                    WP_CLI::success('同步完成：处理 ' . intval($result['processed']) . ' 篇源站内容，目标处理 ' . intval($result['targets']) . ' 条，入队 ' . intval($result['queued']) . ' 条，阻断失败 ' . intval($result['failed'] ?? 0) . ' 条。');
                });
                WP_CLI::add_command('wpmu-ml status-sync', function($args, $assoc_args) {
                    $langs = [];
                    if (!empty($assoc_args['lang'])) {
                        $langs = array_filter(array_map('sanitize_key', preg_split('/[,\s]+/', (string)$assoc_args['lang'])));
                    }
                    $post_status = isset($assoc_args['post_status']) ? sanitize_key($assoc_args['post_status']) : 'no_change';
                    $relation_status = isset($assoc_args['relation_status']) ? sanitize_key($assoc_args['relation_status']) : 'no_change';
                    $post_types = [];
                    if (!empty($assoc_args['post_type'])) {
                        $post_types = array_filter(array_map('sanitize_key', preg_split('/[,\s]+/', (string)$assoc_args['post_type'])));
                    }
                    $result = WPMU_Multilingual::instance()->sync_language_statuses($langs, $post_status, $relation_status, $post_types);
                    WP_CLI::success('语言状态同步完成：语言 ' . intval($result['languages']) . ' 个，文章状态更新 ' . intval($result['posts_changed']) . ' 条，关联状态更新 ' . intval($result['relations_changed']) . ' 条。');
                });
                WP_CLI::add_command('wpmu-ml repair-slugs', function($args, $assoc_args) {
                    $lang = isset($assoc_args['lang']) ? sanitize_key($assoc_args['lang']) : '';
                    $limit = isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 0;
                    $dry_run = !empty($assoc_args['dry-run']);
                    $result = WPMU_Multilingual::instance()->repair_target_slugs_from_source($lang, $limit, $dry_run);
                    WP_CLI::line('slug 修复结果：扫描 ' . intval($result['scanned']) . ' 条，需修复 ' . intval($result['need_fix']) . ' 条，已修复 ' . intval($result['changed']) . ' 条，跳过 ' . intval($result['skipped']) . ' 条，错误 ' . intval($result['errors']) . ' 条。');
                    if (!empty($result['samples'])) {
                        foreach ($result['samples'] as $row) {
                            $line = ' - target_blog_id=' . intval($row['target_blog_id'] ?? 0) . ', target_post_id=' . intval($row['target_post_id'] ?? 0);
                            if (array_key_exists('old_slug', $row) || array_key_exists('new_slug', $row)) {
                                $line .= ': ' . (string)($row['old_slug'] ?? '') . ' => ' . (string)($row['new_slug'] ?? '');
                            } else {
                                $line .= ': error_code=' . (string)($row['error_code'] ?? '') . ' error=' . (string)($row['error'] ?? '');
                            }
                            WP_CLI::line($line);
                        }
                    }
                    if ($dry_run) {
                        WP_CLI::success('dry-run 完成，未修改数据库。');
                    } else {
                        WP_CLI::success('slug 修复完成。');
                    }
                });
                WP_CLI::add_command('wpmu-ml repair-one-slug', function($args, $assoc_args) {
                    $source_post_id = isset($assoc_args['post_id']) ? absint($assoc_args['post_id']) : (isset($assoc_args['source_post_id']) ? absint($assoc_args['source_post_id']) : 0);
                    $lang = isset($assoc_args['lang']) ? sanitize_key($assoc_args['lang']) : '';
                    $dry_run = !empty($assoc_args['dry-run']);
                    if (!$source_post_id || !$lang) {
                        WP_CLI::error('请提供 --post_id=源文章ID 和 --lang=目标语言，例如：wp wpmu-ml repair-one-slug --post_id=12346401 --lang=en --allow-root --skip-themes');
                    }
                    $result = WPMU_Multilingual::instance()->repair_one_target_slug_from_source($source_post_id, $lang, $dry_run);
                    if (is_wp_error($result)) {
                        WP_CLI::error($result->get_error_message());
                    }
                    WP_CLI::line('source_blog_id=' . intval($result['source_blog_id']) . ', source_post_id=' . intval($result['source_post_id']));
                    WP_CLI::line('target_blog_id=' . intval($result['target_blog_id']) . ', target_post_id=' . intval($result['target_post_id']));
                    WP_CLI::line('old_slug=' . $result['old_slug']);
                    WP_CLI::line('new_slug=' . $result['new_slug']);
                    if ($dry_run) {
                        WP_CLI::success('dry-run 完成，未修改数据库。');
                    } else {
                        WP_CLI::success('单篇 slug 强制修复完成。');
                    }
                });
                WP_CLI::add_command('wpmu-ml translate', function($args, $assoc_args) {
                    $job_id = isset($assoc_args['job_id']) ? absint($assoc_args['job_id']) : 0;
                    if ($job_id) {
                        $processed = WPMU_Multilingual::instance()->process_single_translation_job($job_id, 'wp-cli-job');
                        if (is_wp_error($processed)) {
                            WP_CLI::error($processed->get_error_message());
                        }
                        WP_CLI::success('指定任务处理完成：job_id=' . intval($job_id) . '。');
                        return;
                    }
                    $limit = isset($assoc_args['limit']) ? absint($assoc_args['limit']) : 2;
                    $lang = isset($assoc_args['lang']) ? sanitize_key($assoc_args['lang']) : '';
                    $engine = isset($assoc_args['engine']) ? sanitize_key($assoc_args['engine']) : '';
                    $retry_failed = !empty($assoc_args['retry-failed']);
                    $result = WPMU_Multilingual::instance()->process_translation_queue($limit, [
                        'target_lang' => $lang,
                        'engine' => $engine,
                        'retry_failed' => $retry_failed,
                        'runner' => 'wp-cli',
                        'respect_auto_enabled' => false,
                    ]);
                    WP_CLI::success('队列处理完成：扫描 ' . intval($result['scanned']) . ' 个，锁定 ' . intval($result['locked']) . ' 个，处理 ' . intval($result['processed']) . ' 个，跳过 ' . intval($result['skipped']) . ' 个，失败 ' . intval($result['failed']) . ' 个。');
                });
                WP_CLI::add_command('wpmu-ml translate-one', function($args, $assoc_args) {
                    $post_id = isset($assoc_args['post_id']) ? absint($assoc_args['post_id']) : (isset($assoc_args['source_post_id']) ? absint($assoc_args['source_post_id']) : 0);
                    $lang = isset($assoc_args['lang']) ? sanitize_key($assoc_args['lang']) : '';
                    $engine = isset($assoc_args['engine']) ? sanitize_key($assoc_args['engine']) : '';
                    $overwrite = !empty($assoc_args['force']) || !empty($assoc_args['overwrite']);
                    if (!empty($assoc_args['trace']) && !defined('WPMU_ML_CLI_TRACE')) {
                        define('WPMU_ML_CLI_TRACE', true);
                        WP_CLI::line('[WPMU-ML] 详细 API 过程追踪已开启。');
                    }
                    if (!$post_id || !$lang) {
                        WP_CLI::error('请提供 --post_id=文章ID --lang=目标语言，例如：wp wpmu-ml translate-one --post_id=123 --lang=en --force');
                    }
                    $prepared = WPMU_Multilingual::instance()->prepare_single_translation_job($post_id, $lang, [
                        'engine' => $engine,
                        'overwrite' => $overwrite,
                        'runner' => 'wp-cli',
                    ]);
                    if (is_wp_error($prepared)) {
                        WP_CLI::error($prepared->get_error_message());
                    }
                    $processed = WPMU_Multilingual::instance()->process_single_translation_job((int)$prepared['job_id'], 'wp-cli-one');
                    if (is_wp_error($processed)) {
                        WP_CLI::error($processed->get_error_message());
                    }
                    $job_row = WPMU_Multilingual::instance()->get_translation_job_row((int)$prepared['job_id']);
                    if (is_array($job_row) && (string)($job_row['status'] ?? '') === 'review_required') {
                        WP_CLI::warning('单篇翻译存在未完成字段或未通过质检内容，目标文章已保存为草稿：job_id=' . intval($prepared['job_id']) . '，source_post_id=' . intval($prepared['source_post_id']) . '，target_post_id=' . intval($prepared['target_post_id']) . '，target_lang=' . $lang . '。');
                    } elseif (is_array($job_row) && in_array((string)($job_row['status'] ?? ''), ['failed','agent_failed'], true)) {
                        WP_CLI::error('单篇翻译失败：' . wp_strip_all_tags((string)($job_row['last_error'] ?? '未知错误')));
                    } elseif (is_array($job_row) && (string)($job_row['status'] ?? '') === 'pending' && !empty($job_row['last_error'])) {
                        WP_CLI::warning('单篇翻译本次未完成，任务已等待重试：' . wp_strip_all_tags((string)$job_row['last_error']));
                    } elseif ($processed === false) {
                        WP_CLI::warning('单篇翻译本次未完成，请检查任务状态和任务说明。');
                    } else {
                        WP_CLI::success('单篇翻译完成：job_id=' . intval($prepared['job_id']) . '，source_post_id=' . intval($prepared['source_post_id']) . '，target_post_id=' . intval($prepared['target_post_id']) . '，target_lang=' . $lang . '。');
                    }
                    if (is_array($job_row)) {
                        WP_CLI::line('任务表：' . WPMU_Multilingual::instance()->get_table_name('jobs'));
                        WP_CLI::line('任务状态：' . ($job_row['status'] ?? ''));
                        WP_CLI::line('任务引擎：' . ($job_row['engine'] ?? ''));
                        WP_CLI::line('OpenAI翻译模式：' . WPMU_Multilingual::instance()->get_agent_mode_label());
                        WP_CLI::line('任务说明：' . wp_strip_all_tags((string)($job_row['last_error'] ?? '')));
                    } else {
                        WP_CLI::warning('翻译已返回成功，但未能回读任务记录。请执行：wp wpmu-ml doctor --job_id=' . intval($prepared['job_id']) . ' --allow-root --skip-themes');
                    }
                });
                WP_CLI::add_command('wpmu-ml doctor', function($args, $assoc_args) {
                    $job_id = isset($assoc_args['job_id']) ? absint($assoc_args['job_id']) : 0;
                    $info = WPMU_Multilingual::instance()->get_diagnostic_info($job_id);
                    WP_CLI::line('WPMU多语言诊断');
                    WP_CLI::line('版本：' . $info['version']);
                    WP_CLI::line('is_multisite：' . ($info['is_multisite'] ? 'yes' : 'no'));
                    WP_CLI::line('base_prefix：' . $info['base_prefix']);
                    WP_CLI::line('prefix：' . $info['prefix']);
                    WP_CLI::line('OpenAI翻译模式：' . $info['agent_mode']);
                    WP_CLI::line('AI质检：' . ($info['agent_quality_check'] ? 'on' : 'off'));
                    WP_CLI::line('AI质检失败不发布：' . ($info['agent_fail_on_qa'] ? 'on' : 'off'));
                    WP_CLI::line('表状态：');
                    foreach ($info['tables'] as $key => $row) {
                        WP_CLI::line(' - ' . $key . ': ' . $row['name'] . ' | exists=' . ($row['exists'] ? 'yes' : 'no') . ' | rows=' . $row['rows']);
                    }
                    if ($job_id) {
                        WP_CLI::line('指定任务：job_id=' . $job_id);
                        if (!empty($info['job'])) {
                            WP_CLI::line(' - status=' . $info['job']['status'] . ', engine=' . $info['job']['engine'] . ', target_lang=' . $info['job']['target_lang']);
                            WP_CLI::line(' - source_post_id=' . $info['job']['source_post_id'] . ', target_post_id=' . $info['job']['target_post_id']);
                            WP_CLI::line(' - last_error=' . wp_strip_all_tags((string)$info['job']['last_error']));
                        } else {
                            WP_CLI::warning('没有在当前任务表中找到该 job_id。');
                        }
                    }
                });
                WP_CLI::add_command('wpmu-ml job', function($args, $assoc_args) {
                    $job_id = isset($assoc_args['job_id']) ? absint($assoc_args['job_id']) : (isset($args[0]) ? absint($args[0]) : 0);
                    if (!$job_id) {
                        WP_CLI::error('请提供 --job_id=任务ID，例如：wp wpmu-ml job --job_id=62 --allow-root --skip-themes');
                    }
                    $job = WPMU_Multilingual::instance()->get_translation_job_row($job_id);
                    if (!$job) {
                        WP_CLI::error('没有找到任务。任务表：' . WPMU_Multilingual::instance()->get_table_name('jobs'));
                    }
                    foreach ($job as $k => $v) {
                        WP_CLI::line($k . ': ' . (is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE)));
                    }
                });
            
    }
}
