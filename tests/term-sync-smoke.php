<?php
if (!defined('ABSPATH')) {
    exit(1);
}

global $wpdb;
$core = WPMU_Multilingual::instance();
$settings = $core->get_settings();
$source_blog_id = absint($settings['source_blog_id'] ?? 0);
if (!$source_blog_id) {
    throw new RuntimeException('source_blog_id is not configured.');
}
if (!empty($settings['translate_term_name']) || !empty($settings['translate_term_description'])) {
    throw new RuntimeException('term translation switches must be disabled for this non-API smoke test.');
}

$source_site = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->base_prefix}wpmu_ml_sites WHERE blog_id = %d LIMIT 1",
    $source_blog_id
), ARRAY_A);
$target_site = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->base_prefix}wpmu_ml_sites WHERE enabled = 1 AND blog_id <> %d ORDER BY sort_order ASC, blog_id ASC LIMIT 1",
    $source_blog_id
), ARRAY_A);
if (!$source_site || !$target_site) {
    throw new RuntimeException('source/target site rows are not available.');
}

$sync_taxonomies = (new ReflectionMethod($core, 'get_effective_sync_taxonomies'))->invoke($core, ['post_tag', 'category']);
foreach (['post_tag', 'category'] as $taxonomy) {
    if (!in_array($taxonomy, (array)$sync_taxonomies, true)) {
        throw new RuntimeException($taxonomy . ' is not enabled in sync_taxonomies.');
    }
}

$wpdb->query('START TRANSACTION');

try {
    remove_action('save_post', [$core, 'maybe_auto_sync_source_post'], 30);
    $sync_one_target = new ReflectionMethod($core, 'sync_one_target_secure');
    $suffix = strtolower(wp_generate_password(8, false, false));
    $target_blog_id = absint($target_site['blog_id']);

    switch_to_blog($source_blog_id);
    $created_tag = wp_insert_term('WPMU ML Tag ' . $suffix, 'post_tag', [
        'slug' => 'wpmu-ml-tag-' . $suffix,
        'description' => 'source tag description',
    ]);
    if (is_wp_error($created_tag)) {
        throw new RuntimeException($created_tag->get_error_message());
    }
    $source_tag_id = absint($created_tag['term_id']);
    restore_current_blog();

    $tag_relation = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->base_prefix}wpmu_ml_term_relations
         WHERE source_blog_id = %d AND source_term_id = %d AND source_taxonomy = 'post_tag' AND target_blog_id = %d
         LIMIT 1",
        $source_blog_id,
        $source_tag_id,
        $target_blog_id
    ), ARRAY_A);
    if (!$tag_relation) {
        throw new RuntimeException('created post_tag relation was not written.');
    }

    switch_to_blog($target_blog_id);
    $target_tag = get_term(absint($tag_relation['target_term_id']), 'post_tag');
    restore_current_blog();
    if (!$target_tag || is_wp_error($target_tag) || (string)$target_tag->slug !== 'wpmu-ml-tag-' . $suffix) {
        throw new RuntimeException('created post_tag target was not synchronized.');
    }

    switch_to_blog($source_blog_id);
    $updated_tag = wp_update_term($source_tag_id, 'post_tag', [
        'name' => 'WPMU ML Tag Updated ' . $suffix,
        'slug' => 'wpmu-ml-tag-updated-' . $suffix,
        'description' => 'updated source tag description',
    ]);
    if (is_wp_error($updated_tag)) {
        throw new RuntimeException($updated_tag->get_error_message());
    }
    restore_current_blog();

    switch_to_blog($target_blog_id);
    $target_tag = get_term(absint($tag_relation['target_term_id']), 'post_tag');
    restore_current_blog();
    if (
        !$target_tag
        || is_wp_error($target_tag)
        || (string)$target_tag->slug !== 'wpmu-ml-tag-updated-' . $suffix
        || (string)$target_tag->description !== 'updated source tag description'
    ) {
        throw new RuntimeException('edited post_tag target was not updated.');
    }

    switch_to_blog($source_blog_id);
    $parent = wp_insert_term('WPMU ML Parent ' . $suffix, 'category', [
        'slug' => 'wpmu-ml-parent-' . $suffix,
    ]);
    if (is_wp_error($parent)) {
        throw new RuntimeException($parent->get_error_message());
    }
    $child = wp_insert_term('WPMU ML Child ' . $suffix, 'category', [
        'slug' => 'wpmu-ml-child-' . $suffix,
        'parent' => absint($parent['term_id']),
    ]);
    if (is_wp_error($child)) {
        throw new RuntimeException($child->get_error_message());
    }
    restore_current_blog();

    $parent_relation = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->base_prefix}wpmu_ml_term_relations
         WHERE source_blog_id = %d AND source_term_id = %d AND source_taxonomy = 'category' AND target_blog_id = %d
         LIMIT 1",
        $source_blog_id,
        absint($parent['term_id']),
        $target_blog_id
    ), ARRAY_A);
    $child_relation = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->base_prefix}wpmu_ml_term_relations
         WHERE source_blog_id = %d AND source_term_id = %d AND source_taxonomy = 'category' AND target_blog_id = %d
         LIMIT 1",
        $source_blog_id,
        absint($child['term_id']),
        $target_blog_id
    ), ARRAY_A);
    if (!$parent_relation || !$child_relation) {
        throw new RuntimeException('category parent/child relations were not written.');
    }

    switch_to_blog($target_blog_id);
    $target_child = get_term(absint($child_relation['target_term_id']), 'category');
    restore_current_blog();
    if (!$target_child || is_wp_error($target_child) || absint($target_child->parent) !== absint($parent_relation['target_term_id'])) {
        throw new RuntimeException('category parent mapping was not synchronized.');
    }

    switch_to_blog($source_blog_id);
    $source_post_id = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'draft',
        'post_title' => 'WPMU ML Term Mapping ' . $suffix,
        'post_content' => 'term mapping smoke',
        'post_name' => 'wpmu-ml-term-mapping-' . $suffix,
    ], true);
    if (is_wp_error($source_post_id)) {
        throw new RuntimeException($source_post_id->get_error_message());
    }
    wp_set_object_terms($source_post_id, [$source_tag_id], 'post_tag', false);
    wp_set_object_terms($source_post_id, [absint($child['term_id'])], 'category', false);
    $source_post = get_post($source_post_id);
    $source_meta = get_post_meta($source_post_id);
    restore_current_blog();

    $post_sync = $sync_one_target->invoke($core, $source_site, $source_post, $source_meta, [
        'post_tag' => [$source_tag_id],
        'category' => [absint($child['term_id'])],
    ], $target_site, false, []);
    $target_post_id = absint($post_sync['target_post_id'] ?? 0);
    if (!$target_post_id) {
        throw new RuntimeException('post sync for term mapping did not create a target post.');
    }

    switch_to_blog($target_blog_id);
    $target_post_tag_ids = wp_get_object_terms($target_post_id, 'post_tag', ['fields' => 'ids']);
    $target_post_category_ids = wp_get_object_terms($target_post_id, 'category', ['fields' => 'ids']);
    restore_current_blog();
    if (
        is_wp_error($target_post_tag_ids)
        || is_wp_error($target_post_category_ids)
        || !in_array(absint($tag_relation['target_term_id']), array_map('absint', (array)$target_post_tag_ids), true)
        || !in_array(absint($child_relation['target_term_id']), array_map('absint', (array)$target_post_category_ids), true)
    ) {
        throw new RuntimeException('post term relationship did not use mapped target term IDs.');
    }

    switch_to_blog($target_blog_id);
    wp_delete_term(absint($tag_relation['target_term_id']), 'post_tag');
    restore_current_blog();

    switch_to_blog($source_blog_id);
    $repair_tag = wp_update_term($source_tag_id, 'post_tag', [
        'name' => 'WPMU ML Tag Repaired ' . $suffix,
        'slug' => 'wpmu-ml-tag-repaired-' . $suffix,
        'description' => 'repaired source tag description',
    ]);
    if (is_wp_error($repair_tag)) {
        throw new RuntimeException($repair_tag->get_error_message());
    }
    restore_current_blog();

    $repaired_relation = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->base_prefix}wpmu_ml_term_relations
         WHERE source_blog_id = %d AND source_term_id = %d AND source_taxonomy = 'post_tag' AND target_blog_id = %d
         LIMIT 1",
        $source_blog_id,
        $source_tag_id,
        $target_blog_id
    ), ARRAY_A);
    if (!$repaired_relation || absint($repaired_relation['target_term_id']) === absint($tag_relation['target_term_id'])) {
        throw new RuntimeException('missing target term relation was not repaired.');
    }

    switch_to_blog($source_blog_id);
    wp_delete_term($source_tag_id, 'post_tag');
    restore_current_blog();

    switch_to_blog($target_blog_id);
    $deleted_target_tag = get_term(absint($repaired_relation['target_term_id']), 'post_tag');
    restore_current_blog();
    $deleted_relation = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->base_prefix}wpmu_ml_term_relations
         WHERE source_blog_id = %d AND source_term_id = %d AND source_taxonomy = 'post_tag' AND target_blog_id = %d
         LIMIT 1",
        $source_blog_id,
        $source_tag_id,
        $target_blog_id
    ), ARRAY_A);
    if (($deleted_target_tag && !is_wp_error($deleted_target_tag)) || $deleted_relation) {
        throw new RuntimeException('source term delete was not synchronized.');
    }

    switch_to_blog($source_blog_id);
    wp_delete_term(absint($child['term_id']), 'category');
    wp_delete_term(absint($parent['term_id']), 'category');
    restore_current_blog();

    $deleted_child_relation = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->base_prefix}wpmu_ml_term_relations
         WHERE source_blog_id = %d AND source_term_id = %d AND source_taxonomy = 'category' AND target_blog_id = %d
         LIMIT 1",
        $source_blog_id,
        absint($child['term_id']),
        $target_blog_id
    ), ARRAY_A);
    if ($deleted_child_relation) {
        throw new RuntimeException('category child delete relation was not cleaned.');
    }

    echo wp_json_encode([
        'ok' => true,
        'source_blog_id' => $source_blog_id,
        'target_blog_id' => $target_blog_id,
        'created_relation_target_id' => absint($tag_relation['target_term_id']),
        'repaired_relation_target_id' => absint($repaired_relation['target_term_id']),
        'category_parent_target_id' => absint($parent_relation['target_term_id']),
        'category_child_target_id' => absint($child_relation['target_term_id']),
        'mapped_post_target_id' => $target_post_id,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
} finally {
    restore_current_blog();
    $wpdb->query('ROLLBACK');
}
