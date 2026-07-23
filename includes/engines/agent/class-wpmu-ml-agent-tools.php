<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Agent Tools API.
 *
 * This is separate from the article translation queue API. It gives a local/external
 * Agent a constrained WordPress tool surface to read and write specific translatable
 * objects such as taxonomy terms, tags, reusable blocks, templates and template parts.
 * The Agent owns its model and execution workflow; this plugin can expose shared translation rules and owns WordPress structure,
 * object lookup, relation tables and safe write-back.
 */
final class WPMU_ML_Agent_Tools {
    private $core;
    private $namespace = 'wpmu-ml/v1';

    public function __construct($core) {
        $this->core = $core;
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes() {
        register_rest_route($this->namespace, '/agent-tools/health', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'rest_health'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        register_rest_route($this->namespace, '/agent-tools/types', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'rest_types'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        register_rest_route($this->namespace, '/agent-tools/list', [
            'methods' => [WP_REST_Server::READABLE, WP_REST_Server::CREATABLE],
            'callback' => [$this, 'rest_list'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        register_rest_route($this->namespace, '/agent-tools/read', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rest_read'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);

        register_rest_route($this->namespace, '/agent-tools/write', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rest_write'],
            'permission_callback' => [$this, 'permission_callback'],
        ]);
    }

    public function permission_callback(WP_REST_Request $request) {
        $settings = $this->core->get_settings();
        $expected = trim((string)($settings['agent_tools_api_token'] ?? ''));
        if ($expected === '') {
            return new WP_Error('wpmu_ml_agent_tools_api_disabled', 'Agent Tools API Key 为空，工具接口未启用。', ['status' => 403]);
        }
        $auth = (string)$request->get_header('authorization');
        if (!preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
            return new WP_Error('wpmu_ml_agent_tools_missing_token', '缺少 Authorization: Bearer token。', ['status' => 401]);
        }
        $token = trim((string)$m[1]);
        if (!hash_equals($expected, $token)) {
            return new WP_Error('wpmu_ml_agent_tools_invalid_token', 'Agent Tools API Key 无效。', ['status' => 403]);
        }
        return true;
    }

    public function rest_health(WP_REST_Request $request) {
        return rest_ensure_response([
            'ok' => true,
            'plugin' => 'wpmu-multilingual',
            'version' => defined('WPMU_ML_VERSION') ? WPMU_ML_VERSION : '',
            'agent_tools_api' => true,
            'queue_api_separate' => true,
            'namespace' => $this->namespace,
            'supported_object_types' => ['term', 'post_like'],
            'endpoints' => [
                'types' => '/wp-json/' . $this->namespace . '/agent-tools/types',
                'list' => '/wp-json/' . $this->namespace . '/agent-tools/list',
                'read' => '/wp-json/' . $this->namespace . '/agent-tools/read',
                'write' => '/wp-json/' . $this->namespace . '/agent-tools/write',
            ],
        ]);
    }

    public function rest_types(WP_REST_Request $request) {
        $source_blog_id = absint($request->get_param('source_blog_id'));
        if (!$source_blog_id) {
            $source_blog_id = get_current_blog_id();
        }

        $taxonomies = [];
        switch_to_blog($source_blog_id);
        $taxonomy_objects = get_taxonomies(['show_ui' => true], 'objects');
        foreach ((array)$taxonomy_objects as $taxonomy => $object) {
            if (!$object || empty($object->name)) {
                continue;
            }
            $taxonomies[] = [
                'taxonomy' => (string)$object->name,
                'label' => (string)($object->label ?: $object->name),
                'hierarchical' => !empty($object->hierarchical),
                'object_type' => array_values((array)($object->object_type ?? [])),
            ];
        }

        $candidate_post_types = apply_filters('wpmu_ml_agent_tools_post_like_types', [
            'wp_block',
            'wp_template',
            'wp_template_part',
            'wp_navigation',
        ]);
        $post_like = [];
        foreach ((array)$candidate_post_types as $post_type) {
            $post_type = sanitize_key((string)$post_type);
            if ($post_type === '') {
                continue;
            }
            $object = get_post_type_object($post_type);
            if (!$object) {
                continue;
            }
            $post_like[] = [
                'post_type' => $post_type,
                'label' => (string)($object->label ?: $post_type),
                'public' => !empty($object->public),
                'show_ui' => !empty($object->show_ui),
            ];
        }
        restore_current_blog();

        return rest_ensure_response([
            'ok' => true,
            'object_types' => ['term', 'post_like'],
            'terms' => $taxonomies,
            'post_like' => $post_like,
            'usage' => [
                'list_terms' => 'POST /wp-json/' . $this->namespace . '/agent-tools/list with object_type=term and taxonomy=category/post_tag/...',
                'list_post_like' => 'POST /wp-json/' . $this->namespace . '/agent-tools/list with object_type=post_like and post_type=wp_block/wp_template/...',
            ],
        ]);
    }

    public function rest_list(WP_REST_Request $request) {
        $object_type = sanitize_key((string)$request->get_param('object_type'));
        if ($object_type === 'term' || $object_type === 'taxonomy_term') {
            return $this->list_terms($request);
        }
        if (in_array($object_type, ['post_like', 'post', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation'], true)) {
            return $this->list_post_like($request);
        }
        return new WP_Error('wpmu_ml_agent_tools_bad_object_type', '不支持的 object_type。当前支持 term / post_like。', ['status' => 400]);
    }

    private function list_terms(WP_REST_Request $request) {
        $source_blog_id = absint($request->get_param('source_blog_id'));
        $target_blog_id = absint($request->get_param('target_blog_id'));
        $target_lang = sanitize_key((string)$request->get_param('target_lang'));
        $taxonomy = sanitize_key((string)($request->get_param('taxonomy') ?: 'category'));
        $search = sanitize_text_field((string)$request->get_param('search'));
        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = min(100, max(1, absint($request->get_param('per_page') ?: 50)));

        if (!$source_blog_id || $taxonomy === '') {
            return new WP_Error('wpmu_ml_agent_tools_list_term_args', '列出 term 需要 source_blog_id 和 taxonomy。', ['status' => 400]);
        }

        switch_to_blog($source_blog_id);
        if (!taxonomy_exists($taxonomy)) {
            restore_current_blog();
            return new WP_Error('wpmu_ml_agent_tools_taxonomy_not_found', '源站 taxonomy 不存在。', ['status' => 404]);
        }
        $args = [
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
            'number' => $per_page,
            'offset' => ($page - 1) * $per_page,
        ];
        if ($search !== '') {
            $args['search'] = $search;
        }
        $terms = get_terms($args);
        restore_current_blog();
        if (is_wp_error($terms)) {
            return $terms;
        }

        $items = [];
        foreach ((array)$terms as $term) {
            $target_id = 0;
            $target_name = '';
            $has_target = false;
            if ($target_blog_id) {
                $target_id = $this->find_target_term_id($source_blog_id, (int)$term->term_id, $taxonomy, $target_blog_id);
                if ($target_id) {
                    switch_to_blog($target_blog_id);
                    $target_term = get_term($target_id, $taxonomy);
                    if ($target_term && !is_wp_error($target_term)) {
                        $target_name = (string)$target_term->name;
                        $has_target = true;
                    }
                    restore_current_blog();
                }
            }
            $items[] = [
                'source_id' => (int)$term->term_id,
                'taxonomy' => $taxonomy,
                'name' => (string)$term->name,
                'slug' => (string)$term->slug,
                'description' => (string)$term->description,
                'count' => (int)$term->count,
                'parent' => (int)$term->parent,
                'target_blog_id' => $target_blog_id,
                'target_lang' => $target_lang,
                'target_id' => $target_id,
                'target_name' => $target_name,
                'has_target' => $has_target,
            ];
        }

        return rest_ensure_response([
            'ok' => true,
            'object_type' => 'term',
            'taxonomy' => $taxonomy,
            'source_blog_id' => $source_blog_id,
            'target_blog_id' => $target_blog_id,
            'target_lang' => $target_lang,
            'page' => $page,
            'per_page' => $per_page,
            'items' => $items,
            'next' => count($items) === $per_page ? ['page' => $page + 1, 'per_page' => $per_page] : null,
        ]);
    }

    private function list_post_like(WP_REST_Request $request) {
        $source_blog_id = absint($request->get_param('source_blog_id'));
        $target_blog_id = absint($request->get_param('target_blog_id'));
        $target_lang = sanitize_key((string)$request->get_param('target_lang'));
        $post_type = sanitize_key((string)($request->get_param('post_type') ?: $request->get_param('object_type')));
        if (in_array($post_type, ['post_like', 'post'], true) || $post_type === '') {
            $post_type = 'wp_block';
        }
        $search = sanitize_text_field((string)$request->get_param('search'));
        $status = sanitize_key((string)($request->get_param('status') ?: 'any'));
        $page = max(1, absint($request->get_param('page') ?: 1));
        $per_page = min(100, max(1, absint($request->get_param('per_page') ?: 50)));

        if (!$source_blog_id || $post_type === '') {
            return new WP_Error('wpmu_ml_agent_tools_list_post_args', '列出 post_like 需要 source_blog_id 和 post_type。', ['status' => 400]);
        }

        switch_to_blog($source_blog_id);
        if (!post_type_exists($post_type)) {
            restore_current_blog();
            return new WP_Error('wpmu_ml_agent_tools_post_type_not_found', '源站 post_type 不存在。', ['status' => 404]);
        }
        $query_args = [
            'post_type' => $post_type,
            'post_status' => $status === '' ? 'any' : $status,
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'modified',
            'order' => 'DESC',
            'no_found_rows' => false,
        ];
        if ($search !== '') {
            $query_args['s'] = $search;
        }
        $query = new WP_Query($query_args);
        $posts = $query->posts;
        $total = (int)$query->found_posts;
        restore_current_blog();

        $items = [];
        foreach ((array)$posts as $post) {
            $target_id = 0;
            $target_title = '';
            $target_status = '';
            $has_target = false;
            if ($target_blog_id) {
                $target_id = $this->find_target_post_id($source_blog_id, (int)$post->ID, $target_blog_id);
                if ($target_id) {
                    switch_to_blog($target_blog_id);
                    $target_post = get_post($target_id);
                    if ($target_post) {
                        $target_title = (string)$target_post->post_title;
                        $target_status = (string)$target_post->post_status;
                        $has_target = true;
                    }
                    restore_current_blog();
                }
            }
            $items[] = [
                'source_id' => (int)$post->ID,
                'post_type' => (string)$post->post_type,
                'title' => (string)$post->post_title,
                'slug' => (string)$post->post_name,
                'status' => (string)$post->post_status,
                'modified' => (string)$post->post_modified,
                'target_blog_id' => $target_blog_id,
                'target_lang' => $target_lang,
                'target_id' => $target_id,
                'target_title' => $target_title,
                'target_status' => $target_status,
                'has_target' => $has_target,
            ];
        }

        return rest_ensure_response([
            'ok' => true,
            'object_type' => 'post_like',
            'post_type' => $post_type,
            'source_blog_id' => $source_blog_id,
            'target_blog_id' => $target_blog_id,
            'target_lang' => $target_lang,
            'page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => $per_page > 0 ? (int)ceil($total / $per_page) : 0,
            'items' => $items,
            'next' => ($page * $per_page) < $total ? ['page' => $page + 1, 'per_page' => $per_page] : null,
        ]);
    }

    public function rest_read(WP_REST_Request $request) {
        $object_type = sanitize_key((string)$request->get_param('object_type'));
        if ($object_type === 'term' || $object_type === 'taxonomy_term') {
            return $this->read_term($request);
        }
        if (in_array($object_type, ['post_like', 'post', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation'], true)) {
            return $this->read_post_like($request);
        }
        return new WP_Error('wpmu_ml_agent_tools_bad_object_type', '不支持的 object_type。当前支持 term / post_like。', ['status' => 400]);
    }

    public function rest_write(WP_REST_Request $request) {
        $object_type = sanitize_key((string)$request->get_param('object_type'));
        if ($object_type === 'term' || $object_type === 'taxonomy_term') {
            return $this->write_term($request);
        }
        if (in_array($object_type, ['post_like', 'post', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation'], true)) {
            return $this->write_post_like($request);
        }
        return new WP_Error('wpmu_ml_agent_tools_bad_object_type', '不支持的 object_type。当前支持 term / post_like。', ['status' => 400]);
    }

    private function read_term(WP_REST_Request $request) {
        $source_blog_id = absint($request->get_param('source_blog_id'));
        $source_id = absint($request->get_param('source_id'));
        $target_blog_id = absint($request->get_param('target_blog_id'));
        $target_id = absint($request->get_param('target_id'));
        $target_lang = sanitize_key((string)$request->get_param('target_lang'));
        $taxonomy = sanitize_key((string)$request->get_param('taxonomy'));
        if (!$source_blog_id || !$source_id || !$target_blog_id || $taxonomy === '') {
            return new WP_Error('wpmu_ml_agent_tools_term_args', '读取 term 需要 source_blog_id、source_id、target_blog_id 和 taxonomy。', ['status' => 400]);
        }
        if (!$target_id) {
            $target_id = $this->find_target_term_id($source_blog_id, $source_id, $taxonomy, $target_blog_id);
        }
        switch_to_blog($source_blog_id);
        $source_term = get_term($source_id, $taxonomy);
        $source_url = '';
        if ($source_term && !is_wp_error($source_term)) {
            $link = get_term_link($source_term);
            $source_url = is_wp_error($link) ? '' : $link;
        }
        restore_current_blog();
        if (!$source_term || is_wp_error($source_term)) {
            return new WP_Error('wpmu_ml_agent_tools_source_term_not_found', '源 term 不存在。', ['status' => 404]);
        }
        $target_term = null;
        $target_url = '';
        if ($target_id) {
            switch_to_blog($target_blog_id);
            $target_term = get_term($target_id, $taxonomy);
            if ($target_term && !is_wp_error($target_term)) {
                $link = get_term_link($target_term);
                $target_url = is_wp_error($link) ? '' : $link;
            }
            restore_current_blog();
        }
        $fields = [
            [
                'field_id' => 'term:name',
                'field_type' => 'term_name',
                'format' => 'plain_text',
                'source' => (string)$source_term->name,
                'translatable' => true,
                'required' => true,
            ],
            [
                'field_id' => 'term:description',
                'field_type' => 'term_description',
                'format' => 'plain_text',
                'source' => (string)$source_term->description,
                'translatable' => true,
                'required' => false,
            ],
            [
                'field_id' => 'term:slug',
                'field_type' => 'term_slug',
                'format' => 'slug',
                'source' => (string)$source_term->slug,
                'translatable' => false,
                'required' => false,
            ],
        ];
        return rest_ensure_response([
            'ok' => true,
            'api_version' => '1.0',
            'object_type' => 'term',
            'source_hash' => $this->fields_hash($fields),
            'source' => [
                'blog_id' => $source_blog_id,
                'id' => $source_id,
                'taxonomy' => $taxonomy,
                'url' => $source_url,
            ],
            'target' => [
                'blog_id' => $target_blog_id,
                'id' => $target_id,
                'lang' => $target_lang,
                'taxonomy' => $taxonomy,
                'url' => $target_url,
                'exists' => ($target_term && !is_wp_error($target_term)),
            ],
            'fields' => $fields,
            'write_contract' => 'Return fields with the same field_id and translated target values. The plugin writes to WordPress.',
        ]);
    }

    private function write_term(WP_REST_Request $request) {
        $source_blog_id = absint($request->get_param('source_blog_id'));
        $source_id = absint($request->get_param('source_id'));
        $target_blog_id = absint($request->get_param('target_blog_id'));
        $target_id = absint($request->get_param('target_id'));
        $target_lang = sanitize_key((string)$request->get_param('target_lang'));
        $taxonomy = sanitize_key((string)$request->get_param('taxonomy'));
        $fields = $request->get_param('fields');
        if (!$source_blog_id || !$source_id || !$target_blog_id || $taxonomy === '' || !is_array($fields)) {
            return new WP_Error('wpmu_ml_agent_tools_write_term_args', '写入 term 需要 source_blog_id、source_id、target_blog_id、taxonomy 和 fields。', ['status' => 400]);
        }
        if (!$target_id) {
            if ((int)$source_blog_id === (int)$target_blog_id) {
                // Same-site edit mode: allow Agent Tools to translate/update a term in place.
                // This is useful when the target site already contains source-language terms
                // and the user wants the Agent to rewrite them directly.
                $target_id = $source_id;
            } else {
                $target_id = $this->find_target_term_id($source_blog_id, $source_id, $taxonomy, $target_blog_id);
            }
        }
        $normalized_fields = $this->normalize_target_fields($fields);
        $updates = [];
        foreach ($normalized_fields as $field_id => $target) {
            if ($field_id === 'term:name') {
                $updates['name'] = $target;
            } elseif ($field_id === 'term:description') {
                $updates['description'] = $target;
            } elseif ($field_id === 'term:slug') {
                $updates['slug'] = sanitize_title($target);
            }
        }
        if (!$updates) {
            return new WP_Error('wpmu_ml_agent_tools_no_term_updates', '没有可写入的 term 字段。fields 里需要包含 term:name 或 term:description。', ['status' => 400]);
        }

        $created = false;
        if (!$target_id) {
            $create_if_missing = $request->get_param('create_if_missing');
            $create_if_missing = is_null($create_if_missing) ? true : filter_var($create_if_missing, FILTER_VALIDATE_BOOLEAN);
            if (!$create_if_missing) {
                return new WP_Error('wpmu_ml_agent_tools_target_term_missing', '未找到目标 term。请提供 target_id、建立 term 关联，或传 create_if_missing=true 自动创建。', ['status' => 400]);
            }

            switch_to_blog($source_blog_id);
            $source_term_for_create = get_term($source_id, $taxonomy);
            $source_slug_for_create = ($source_term_for_create && !is_wp_error($source_term_for_create)) ? (string)$source_term_for_create->slug : '';
            restore_current_blog();

            switch_to_blog($target_blog_id);
            if (!taxonomy_exists($taxonomy)) {
                restore_current_blog();
                return new WP_Error('wpmu_ml_agent_tools_target_taxonomy_not_found', '目标站 taxonomy 不存在，无法写入 term。', ['status' => 400]);
            }
            $maybe_existing = $source_slug_for_create !== '' ? get_term_by('slug', $source_slug_for_create, $taxonomy) : false;
            if ($maybe_existing && !is_wp_error($maybe_existing)) {
                $target_id = (int)$maybe_existing->term_id;
            } else {
                $term_name = trim((string)($updates['name'] ?? ''));
                if ($term_name === '' && $source_term_for_create && !is_wp_error($source_term_for_create)) {
                    $term_name = (string)$source_term_for_create->name;
                }
                if ($term_name === '') {
                    restore_current_blog();
                    return new WP_Error('wpmu_ml_agent_tools_create_term_name_missing', '无法自动创建目标 term：term:name 为空。', ['status' => 400]);
                }
                $insert_args = [];
                if (!empty($updates['description'])) {
                    $insert_args['description'] = $updates['description'];
                }
                if (!empty($updates['slug'])) {
                    $insert_args['slug'] = $updates['slug'];
                } elseif ($source_slug_for_create !== '') {
                    $insert_args['slug'] = $source_slug_for_create;
                }
                $created_term = wp_insert_term($term_name, $taxonomy, wp_slash($insert_args));
                if (is_wp_error($created_term)) {
                    restore_current_blog();
                    return $created_term;
                }
                $target_id = (int)($created_term['term_id'] ?? 0);
                $created = $target_id > 0;
            }
            restore_current_blog();
        }

        switch_to_blog($target_blog_id);
        if (!taxonomy_exists($taxonomy)) {
            restore_current_blog();
            return new WP_Error('wpmu_ml_agent_tools_target_taxonomy_not_found', '目标站 taxonomy 不存在，无法写入 term。', ['status' => 400]);
        }
        $target_term_check = get_term($target_id, $taxonomy);
        if (!$target_term_check || is_wp_error($target_term_check)) {
            restore_current_blog();
            return new WP_Error('wpmu_ml_agent_tools_target_term_missing', '目标 term 不存在或 taxonomy 不匹配。请检查 target_id / taxonomy。', ['status' => 400]);
        }
        $result = wp_update_term($target_id, $taxonomy, wp_slash($updates));
        restore_current_blog();
        if (is_wp_error($result)) {
            return $result;
        }
        $this->touch_term_relation($source_blog_id, $source_id, $taxonomy, $target_blog_id, $target_id, $target_lang);
        $this->log('info', 'agent_tools_write_term', 'Agent Tools 已写回 term 翻译', [
            'source_blog_id' => $source_blog_id,
            'source_term_id' => $source_id,
            'target_blog_id' => $target_blog_id,
            'target_term_id' => $target_id,
            'taxonomy' => $taxonomy,
            'fields' => array_keys($updates),
        ]);
        return rest_ensure_response([
            'ok' => true,
            'object_type' => 'term',
            'target_blog_id' => $target_blog_id,
            'target_id' => $target_id,
            'taxonomy' => $taxonomy,
            'updated_fields' => array_keys($updates),
            'created' => $created,
            'same_site' => ((int)$source_blog_id === (int)$target_blog_id),
        ]);
    }

    private function read_post_like(WP_REST_Request $request) {
        $source_blog_id = absint($request->get_param('source_blog_id'));
        $source_id = absint($request->get_param('source_id'));
        $target_blog_id = absint($request->get_param('target_blog_id'));
        $target_id = absint($request->get_param('target_id'));
        $target_lang = sanitize_key((string)$request->get_param('target_lang'));
        if (!$source_blog_id || !$source_id || !$target_blog_id) {
            return new WP_Error('wpmu_ml_agent_tools_post_args', '读取 post_like 需要 source_blog_id、source_id 和 target_blog_id。', ['status' => 400]);
        }
        if (!$target_id) {
            $target_id = $this->find_target_post_id($source_blog_id, $source_id, $target_blog_id);
        }
        switch_to_blog($source_blog_id);
        $source_post = get_post($source_id);
        $source_url = $source_post ? get_permalink($source_post) : '';
        restore_current_blog();
        if (!$source_post) {
            return new WP_Error('wpmu_ml_agent_tools_source_post_not_found', '源 post_like 对象不存在。', ['status' => 404]);
        }
        $target_post = null;
        $target_url = '';
        if ($target_id) {
            switch_to_blog($target_blog_id);
            $target_post = get_post($target_id);
            $target_url = $target_post ? get_permalink($target_post) : '';
            restore_current_blog();
        }
        $source_content = (string)$source_post->post_content;
        if (class_exists('WPMU_ML_Content_Sanitizer')) {
            if (method_exists('WPMU_ML_Content_Sanitizer', 'strip_translation_artifacts')) {
                $source_content = (string)WPMU_ML_Content_Sanitizer::strip_translation_artifacts($source_content);
            } else {
                $source_content = (string)WPMU_ML_Content_Sanitizer::strip_immersive_translate_artifacts($source_content);
            }
        }
        $fields = [
            [
                'field_id' => 'post_title',
                'field_type' => 'post_title',
                'format' => 'plain_text',
                'source' => (string)$source_post->post_title,
                'translatable' => true,
                'required' => false,
            ],
            [
                'field_id' => 'post_excerpt',
                'field_type' => 'post_excerpt',
                'format' => 'plain_text',
                'source' => (string)$source_post->post_excerpt,
                'translatable' => true,
                'required' => false,
            ],
            [
                'field_id' => 'post_content',
                'field_type' => 'post_content',
                'format' => 'wp_post_content',
                'source' => $source_content,
                'translatable' => true,
                'required' => false,
                'preserve_wp_blocks' => true,
                'preserve_shortcodes' => true,
                'preserve_html_tags' => true,
            ],
        ];
        return rest_ensure_response([
            'ok' => true,
            'api_version' => '1.0',
            'object_type' => 'post_like',
            'source_hash' => $this->fields_hash($fields),
            'source' => [
                'blog_id' => $source_blog_id,
                'id' => $source_id,
                'post_type' => (string)$source_post->post_type,
                'url' => $source_url,
            ],
            'target' => [
                'blog_id' => $target_blog_id,
                'id' => $target_id,
                'lang' => $target_lang,
                'url' => $target_url,
                'exists' => (bool)$target_post,
            ],
            'fields' => $fields,
            'write_contract' => 'Return fields with the same field_id and translated target values. The plugin writes to WordPress.',
        ]);
    }

    private function write_post_like(WP_REST_Request $request) {
        $source_blog_id = absint($request->get_param('source_blog_id'));
        $source_id = absint($request->get_param('source_id'));
        $target_blog_id = absint($request->get_param('target_blog_id'));
        $target_id = absint($request->get_param('target_id'));
        $fields = $request->get_param('fields');
        if (!$source_blog_id || !$source_id || !$target_blog_id || !is_array($fields)) {
            return new WP_Error('wpmu_ml_agent_tools_write_post_args', '写入 post_like 需要 source_blog_id、source_id、target_blog_id 和 fields。', ['status' => 400]);
        }
        if (!$target_id) {
            if ((int)$source_blog_id === (int)$target_blog_id) {
                // Same-site edit mode for reusable blocks/templates/navigation objects.
                $target_id = $source_id;
            } else {
                $target_id = $this->find_target_post_id($source_blog_id, $source_id, $target_blog_id);
            }
        }
        if (!$target_id) {
            return new WP_Error('wpmu_ml_agent_tools_target_post_missing', '未找到目标 post_like 对象。请提供 target_id 或先建立文章关联。', ['status' => 400]);
        }
        if ((int)$source_blog_id !== (int)$target_blog_id) {
            $relation = $this->core->get_post_relation($source_blog_id, $source_id, $target_blog_id);
            if (!$relation) {
                return new WP_Error('wpmu_ml_agent_tools_relation_missing', '跨站写入必须存在明确的文章关系。', ['status' => 409]);
            }
            $identity = $this->core->validate_translation_job_target([
                'source_blog_id' => $source_blog_id,
                'source_post_id' => $source_id,
                'target_blog_id' => $target_blog_id,
                'target_post_id' => $target_id,
                'post_type' => (string)($relation['post_type'] ?? ''),
            ], true);
            if (empty($identity['valid'])) {
                return new WP_Error(
                    'wpmu_ml_agent_tools_target_identity_invalid',
                    '目标文章身份校验失败：' . (string)($identity['message'] ?? ''),
                    ['status' => 409, 'error_code' => (string)($identity['error_code'] ?? 'relation_invalid')]
                );
            }
            if ((string)($identity['status'] ?? '') === 'legacy_relation') {
                $this->core->stamp_relation_target_identity($relation);
            }
        }
        $updates = ['ID' => $target_id];
        foreach ($this->normalize_target_fields($fields) as $field_id => $target) {
            if ($field_id === 'post_title') {
                $updates['post_title'] = $target;
            } elseif ($field_id === 'post_excerpt') {
                $updates['post_excerpt'] = $target;
            } elseif ($field_id === 'post_content') {
                $updates['post_content'] = $target;
            }
        }
        if (count($updates) <= 1) {
            return new WP_Error('wpmu_ml_agent_tools_no_post_updates', '没有可写入的 post_like 字段。', ['status' => 400]);
        }
        switch_to_blog($target_blog_id);
        $result = wp_update_post(wp_slash($updates), true);
        restore_current_blog();
        if (is_wp_error($result)) {
            return $result;
        }
        $this->log('info', 'agent_tools_write_post_like', 'Agent Tools 已写回 post_like 翻译', [
            'source_blog_id' => $source_blog_id,
            'source_post_id' => $source_id,
            'target_blog_id' => $target_blog_id,
            'target_post_id' => $target_id,
            'fields' => array_keys($updates),
        ]);
        return rest_ensure_response([
            'ok' => true,
            'object_type' => 'post_like',
            'target_blog_id' => $target_blog_id,
            'target_id' => $target_id,
            'updated_fields' => array_values(array_diff(array_keys($updates), ['ID'])),
        ]);
    }

    private function normalize_target_fields($fields) {
        $targets = [];
        foreach ((array)$fields as $key => $field) {
            if (is_array($field)) {
                $field_id = sanitize_text_field((string)($field['field_id'] ?? ''));
                $target_value = $field['target'] ?? ($field['value'] ?? ($field['translated'] ?? ($field['translation'] ?? ($field['text'] ?? ''))));
                $target = is_scalar($target_value) ? (string)$target_value : '';
            } else {
                $field_id = is_string($key) ? sanitize_text_field($key) : '';
                $target = is_scalar($field) ? (string)$field : '';
            }
            if ($field_id !== '') {
                $targets[$field_id] = $target;
            }
        }
        return $targets;
    }

    private function find_target_post_id($source_blog_id, $source_post_id, $target_blog_id) {
        global $wpdb;
        $table = $this->core->get_table_name('posts');
        if (!$table) {
            return 0;
        }
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT target_post_id FROM {$table} WHERE source_blog_id = %d AND source_post_id = %d AND target_blog_id = %d LIMIT 1",
            $source_blog_id,
            $source_post_id,
            $target_blog_id
        ));
    }

    private function find_target_term_id($source_blog_id, $source_term_id, $taxonomy, $target_blog_id) {
        global $wpdb;
        $table = $this->core->get_table_name('terms');
        if (!$table) {
            return 0;
        }
        return (int)$wpdb->get_var($wpdb->prepare(
            "SELECT target_term_id FROM {$table} WHERE source_blog_id = %d AND source_term_id = %d AND source_taxonomy = %s AND target_blog_id = %d LIMIT 1",
            $source_blog_id,
            $source_term_id,
            $taxonomy,
            $target_blog_id
        ));
    }

    private function touch_term_relation($source_blog_id, $source_term_id, $taxonomy, $target_blog_id, $target_term_id, $target_lang) {
        global $wpdb;
        $table = $this->core->get_table_name('terms');
        if (!$table || !$source_blog_id || !$source_term_id || !$target_blog_id || !$target_term_id || $taxonomy === '') {
            return;
        }
        $existing = (int)$wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE source_blog_id = %d AND source_term_id = %d AND source_taxonomy = %s AND target_blog_id = %d LIMIT 1",
            $source_blog_id,
            $source_term_id,
            $taxonomy,
            $target_blog_id
        ));
        $data = [
            'target_term_id' => $target_term_id,
            'target_taxonomy' => $taxonomy,
            'target_lang' => $target_lang,
            'relation_status' => 'translated',
            'updated_at' => current_time('mysql'),
        ];
        if ($existing) {
            $wpdb->update($table, $data, ['id' => $existing], ['%d','%s','%s','%s','%s'], ['%d']);
            return;
        }
        $settings = $this->core->get_settings();
        $source_lang = 'zh-hans';
        if (!empty($settings['source_blog_id'])) {
            $sites = $this->core->get_i18n_sites(true);
            foreach ((array)$sites as $site) {
                if ((int)($site['blog_id'] ?? 0) === (int)$source_blog_id) {
                    $source_lang = sanitize_key($site['lang_slug'] ?? $source_lang);
                    break;
                }
            }
        }
        $wpdb->insert($table, array_merge([
            'source_blog_id' => $source_blog_id,
            'source_term_id' => $source_term_id,
            'source_taxonomy' => $taxonomy,
            'target_blog_id' => $target_blog_id,
            'created_at' => current_time('mysql'),
            'source_lang' => $source_lang,
        ], $data), ['%d','%d','%s','%d','%s','%s','%d','%s','%s','%s','%s']);
    }

    private function fields_hash($fields) {
        $parts = [];
        foreach ((array)$fields as $field) {
            $parts[] = [(string)($field['field_id'] ?? ''), (string)($field['source'] ?? '')];
        }
        return hash('sha256', wp_json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function log($level, $action, $message, $context = []) {
        global $wpdb;
        $logs_table = $this->core->get_table_name('logs');
        if (!$logs_table) {
            return;
        }
        $wpdb->insert($logs_table, [
            'level' => sanitize_key($level),
            'action' => sanitize_key($action),
            'message' => (string)$message,
            'context' => wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => current_time('mysql'),
        ]);
    }
}
