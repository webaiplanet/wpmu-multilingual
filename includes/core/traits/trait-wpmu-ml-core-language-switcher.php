<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * hreflang、语言切换菜单与后台工具栏。
 *
 * 本 trait 仅用于拆分历史 WPMU_Multilingual 大类；方法签名和可见性保持不变。
 */
if (!trait_exists('WPMU_ML_Core_Language_Switcher_Trait')) {
    trait WPMU_ML_Core_Language_Switcher_Trait {
    public function output_hreflang() {
        if (is_admin() || !is_singular()) {
            return;
        }
        $settings = $this->get_settings();
        if (empty($settings['enable_hreflang'])) {
            return;
        }
        $alternates = $this->get_alternate_urls(get_current_blog_id(), get_the_ID());
        if (!$alternates) {
            return;
        }
        $published_alternates = array_values(array_filter($alternates, function($alt) {
            return ($alt['status'] ?? '') === 'publish' && !empty($alt['url']) && !empty($alt['indexable']);
        }));
        foreach ($published_alternates as $alt) {
            echo '<link rel="alternate" hreflang="' . esc_attr($alt['hreflang']) . '" href="' . esc_url($alt['url']) . '" />' . "\n";
        }
        $x_default = $this->get_x_default_url($published_alternates);
        if ($x_default) {
            echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($x_default) . '" />' . "\n";
        }
    }

    private function get_x_default_url($alternates) {
        $settings = $this->get_settings();
        if ($settings['x_default_mode'] === 'none') {
            return '';
        }
        $target_blog = $settings['x_default_mode'] === 'source' ? absint($settings['source_blog_id']) : absint($settings['front_blog_id']);
        foreach ($alternates as $alt) {
            if ((int)$alt['blog_id'] === $target_blog) {
                return $alt['url'];
            }
        }
        return $alternates[0]['url'] ?? '';
    }

    private function is_enabled_language_switcher_blog($blog_id = 0) {
        $blog_id = $blog_id ? absint($blog_id) : get_current_blog_id();
        if (!$blog_id) {
            return false;
        }
        foreach ($this->get_i18n_sites(true) as $site) {
            if ((int)($site['blog_id'] ?? 0) === $blog_id) {
                return true;
            }
        }
        return false;
    }

    public function register_language_switcher_post_type() {
        if (!$this->is_enabled_language_switcher_blog()) {
            return;
        }
        if (post_type_exists(self::LANGUAGE_SWITCHER_POST_TYPE)) {
            return;
        }
        register_post_type(self::LANGUAGE_SWITCHER_POST_TYPE, [
            'label' => 'Language Switcher',
            'labels' => [
                'name' => 'Language Switcher',
                'singular_name' => 'Language Switcher',
                'menu_name' => 'Language Switcher',
                'all_items' => 'Language Switcher',
            ],
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_in_nav_menus' => true,
            'show_in_admin_bar' => false,
            'can_export' => false,
            'query_var' => false,
            'rewrite' => false,
            'supports' => ['title'],
        ]);
    }

    public function keep_language_switcher_metabox_visible($hidden) {
        if (!is_array($hidden)) {
            return $hidden;
        }
        $metabox_id = 'add-post-type-' . self::LANGUAGE_SWITCHER_POST_TYPE;
        return array_values(array_diff($hidden, [$metabox_id]));
    }

    public function maybe_sync_current_site_language_switcher_entries() {
        global $pagenow;
        if ($pagenow !== 'nav-menus.php' || !current_user_can('edit_theme_options') || !$this->is_enabled_language_switcher_blog()) {
            return;
        }
        $this->sync_language_switcher_entries_for_blog(get_current_blog_id());
        $this->sync_language_switcher_menu_for_blog(get_current_blog_id());
    }

    public function maybe_sync_language_switcher_menus_after_upgrade() {
        if (get_site_option('wpmu_ml_language_switcher_menu_sync_pending', '') !== self::VERSION) {
            return;
        }

        $lock_key = 'wpmu_ml_language_switcher_menu_sync_lock';
        if (get_site_transient($lock_key)) {
            return;
        }

        set_site_transient($lock_key, 1, 5 * MINUTE_IN_SECONDS);

        try {
            $this->sync_language_switcher_entries_all_sites();
            $this->sync_language_switcher_menus_all_sites();
            delete_site_option('wpmu_ml_language_switcher_menu_sync_pending');
        } finally {
            delete_site_transient($lock_key);
        }
    }

    private function get_language_switcher_site_label($site) {
        $label = trim((string)($site['language_name'] ?? ''));
        if ($label === '') {
            $locale = trim((string)($site['locale'] ?? ''));
            if ($locale !== '') {
                $label = $this->get_locale_language_name($locale);
            }
        }
        if ($label === '') {
            $label = sanitize_text_field((string)($site['lang_slug'] ?? ''));
        }
        if ($label === '') {
            $label = 'Site ' . absint($site['blog_id'] ?? 0);
        }
        return $label;
    }

    private function get_language_switcher_desired_entries() {
        $desired = [
            'current_language' => '当前语言',
        ];
        foreach ($this->get_i18n_sites(true) as $site) {
            $blog_id = absint($site['blog_id'] ?? 0);
            if (!$blog_id) {
                continue;
            }
            $desired['site:' . $blog_id] = $this->get_language_switcher_site_label($site);
        }
        return $desired;
    }

    private function sync_language_switcher_entries_all_sites() {
        $this->register_language_switcher_post_type();
        foreach ($this->get_i18n_sites(true) as $site) {
            $blog_id = absint($site['blog_id'] ?? 0);
            if (!$blog_id || !get_site($blog_id)) {
                continue;
            }
            $this->sync_language_switcher_entries_for_blog($blog_id);
        }
    }

    public function sync_language_switcher_menus_all_sites() {
        foreach ($this->get_i18n_sites(true) as $site) {
            $blog_id = absint($site['blog_id'] ?? 0);
            if (!$blog_id || !get_site($blog_id)) {
                continue;
            }
            $this->sync_language_switcher_menu_for_blog($blog_id);
        }
    }

    private function sync_language_switcher_menu_for_blog($blog_id) {
        $blog_id = absint($blog_id);
        if (!$blog_id || !get_site($blog_id)) {
            return;
        }

        $switched = get_current_blog_id() !== $blog_id;
        if ($switched) {
            switch_to_blog($blog_id);
        }

        try {
            $registered_locations = get_registered_nav_menus();
            if (!isset($registered_locations['language-menu'])) {
                return;
            }

            $locations = get_nav_menu_locations();
            $menu_id = absint($locations['language-menu'] ?? 0);
            $menu = $menu_id ? wp_get_nav_menu_object($menu_id) : false;
            if (!$menu) {
                $menu = wp_get_nav_menu_object(__('语言切换', 'wpmu-multilingual'));
            }
            if (!$menu) {
                $menu_id = wp_create_nav_menu(__('语言切换', 'wpmu-multilingual'));
                if (is_wp_error($menu_id) || !$menu_id) {
                    return;
                }
                $menu = wp_get_nav_menu_object($menu_id);
            }
            if (!$menu) {
                return;
            }
            $menu_id = (int)$menu->term_id;
            $locations['language-menu'] = $menu_id;
            set_theme_mod('nav_menu_locations', $locations);

            $entries = get_posts([
                'post_type'        => self::LANGUAGE_SWITCHER_POST_TYPE,
                'post_status'      => 'publish',
                'numberposts'      => -1,
                'orderby'          => 'ID',
                'order'            => 'ASC',
                'suppress_filters' => true,
            ]);
            $entry_ids = [];
            foreach ((array)$entries as $entry) {
                $token = trim((string)$entry->post_content);
                if ($token !== '') {
                    $entry_ids[$token] = (int)$entry->ID;
                }
            }

            if (empty($entry_ids['current_language'])) {
                return;
            }

            $menu_items = get_posts([
                'post_type'   => 'nav_menu_item',
                'post_status' => 'any',
                'numberposts' => -1,
                'tax_query'   => [[
                    'taxonomy' => 'nav_menu',
                    'field'    => 'term_id',
                    'terms'    => $menu_id,
                ]],
                'orderby'    => 'menu_order',
                'order'      => 'ASC',
            ]);
            $items_by_token = [];
            foreach ((array)$menu_items as $menu_item) {
                if (get_post_meta($menu_item->ID, '_menu_item_object', true) !== self::LANGUAGE_SWITCHER_POST_TYPE) {
                    continue;
                }

                $entry = get_post(absint(get_post_meta($menu_item->ID, '_menu_item_object_id', true)));
                if (!$entry || $entry->post_type !== self::LANGUAGE_SWITCHER_POST_TYPE) {
                    wp_delete_post($menu_item->ID, true);
                    continue;
                }

                $token = trim((string)$entry->post_content);
                if (!isset($entry_ids[$token])) {
                    wp_delete_post($menu_item->ID, true);
                    continue;
                }

                if (!isset($items_by_token[$token])) {
                    $items_by_token[$token] = (int)$menu_item->ID;
                }
            }

            $upsert_item = function ($item_id, $entry_id, $parent_id, $position) use ($menu_id) {
                return wp_update_nav_menu_item($menu_id, $item_id, [
                    'menu-item-object-id' => $entry_id,
                    'menu-item-object'    => self::LANGUAGE_SWITCHER_POST_TYPE,
                    'menu-item-parent-id' => $parent_id,
                    'menu-item-position'  => $position,
                    'menu-item-type'      => 'post_type',
                    'menu-item-status'    => 'publish',
                ]);
            };

            $current_item_id = $upsert_item(
                $items_by_token['current_language'] ?? 0,
                $entry_ids['current_language'],
                0,
                1
            );
            if (is_wp_error($current_item_id) || !$current_item_id) {
                return;
            }

            $position = 2;
            foreach ($this->get_i18n_sites(true) as $site) {
                $target_blog_id = absint($site['blog_id'] ?? 0);
                $token = 'site:' . $target_blog_id;
                if (!$target_blog_id || empty($entry_ids[$token])) {
                    continue;
                }

                $upsert_item(
                    $items_by_token[$token] ?? 0,
                    $entry_ids[$token],
                    $current_item_id,
                    $position
                );
                $position++;
            }
        } finally {
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    private function sync_language_switcher_entries_for_blog($blog_id) {
        $blog_id = absint($blog_id);
        if (!$blog_id || !get_site($blog_id)) {
            return;
        }

        $switched = get_current_blog_id() !== $blog_id;
        if ($switched) {
            switch_to_blog($blog_id);
        }

        $desired = $this->get_language_switcher_desired_entries();
        $posts = get_posts([
            'post_type' => self::LANGUAGE_SWITCHER_POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'private'],
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
        ]);
        $by_token = [];
        foreach ((array)$posts as $post) {
            $token = trim((string)$post->post_content);
            if ($token === '') {
                continue;
            }
            if (!isset($by_token[$token])) {
                $by_token[$token] = $post;
            } elseif ($post->post_status !== 'draft') {
                wp_update_post(['ID' => $post->ID, 'post_status' => 'draft']);
            }
        }

        foreach ($desired as $token => $title) {
            if (isset($by_token[$token])) {
                $post = $by_token[$token];
                if ($post->post_title !== $title || $post->post_content !== $token || $post->post_status !== 'publish') {
                    wp_update_post([
                        'ID' => $post->ID,
                        'post_title' => $title,
                        'post_content' => $token,
                        'post_status' => 'publish',
                    ]);
                }
                continue;
            }
            wp_insert_post([
                'post_type' => self::LANGUAGE_SWITCHER_POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $title,
                'post_content' => $token,
                'comment_status' => 'closed',
                'ping_status' => 'closed',
            ]);
        }

        foreach ($by_token as $token => $post) {
            if (!isset($desired[$token]) && $post->post_status !== 'draft') {
                wp_update_post(['ID' => $post->ID, 'post_status' => 'draft']);
            }
        }

        if ($switched) {
            restore_current_blog();
        }
    }

    private function get_language_switcher_runtime_context() {
        $settings = $this->get_settings();
        $unpublished_policy = sanitize_key((string)($settings['language_switcher_unpublished_policy'] ?? 'hide'));
        $include_unpublished_for_switcher = is_singular();
        $sites = [];
        foreach ($this->get_i18n_sites(true) as $site) {
            $blog_id = absint($site['blog_id'] ?? 0);
            if (!$blog_id) {
                continue;
            }
            $sites[$blog_id] = [
                'blog_id' => $blog_id,
                'label' => $this->get_language_switcher_site_label($site),
                'hreflang' => $this->normalize_hreflang((string)($site['hreflang'] ?? '')),
                'lang_slug' => sanitize_key((string)($site['lang_slug'] ?? '')),
                'locale' => sanitize_text_field((string)($site['locale'] ?? '')),
            ];
        }

        $links = [];
        if (is_search()) {
            $links = $this->get_search_language_urls();
        }
        if (!$links) {
            $object_id = absint(get_queried_object_id());
            if ($object_id) {
                $queried = get_queried_object();
                if ($queried instanceof WP_Post) {
                    $links = $this->get_alternate_urls(get_current_blog_id(), $object_id, $include_unpublished_for_switcher);
                } elseif ($queried instanceof WP_Term) {
                    $links = $this->get_term_language_urls($queried);
                }
            }
        }
        if (!$links && is_author()) {
            $links = $this->get_author_language_urls();
        }
        if (!$links && is_post_type_archive()) {
            $links = $this->get_post_type_archive_language_urls();
        }
        if (!$links && (is_date() || is_paged())) {
            $links = $this->get_same_request_path_language_urls();
        }
        if (!$links) {
            $links = $this->get_same_request_path_language_urls();
        }
        if (!$links) {
            $links = $this->get_site_language_urls();
        }

        $urls = [];
        foreach ((array)$links as $link) {
            $blog_id = absint($link['blog_id'] ?? 0);
            $url = (string)($link['url'] ?? '');
            $is_unavailable = !empty($link['unavailable']);
            if (!$blog_id || ($url === '' && !$is_unavailable)) {
                continue;
            }
            if ($is_unavailable && $unpublished_policy === 'hide') {
                continue;
            }
            $urls[$blog_id] = [
                'url' => $url,
                'hreflang' => $this->normalize_hreflang((string)($link['hreflang'] ?? ($sites[$blog_id]['hreflang'] ?? ''))),
                'unavailable' => $is_unavailable,
                'status' => sanitize_key((string)($link['status'] ?? '')),
            ];
        }

        $current_blog_id = get_current_blog_id();
        if (!isset($urls[$current_blog_id])) {
            $current_url = '';
            $current_object_id = absint(get_queried_object_id());
            $current_object = $current_object_id ? get_queried_object() : null;
            if ($current_object_id && $current_object instanceof WP_Post) {
                $current_url = get_permalink($current_object_id);
            } elseif ($current_object_id && $current_object instanceof WP_Term) {
                $term_link = get_term_link($current_object);
                $current_url = is_wp_error($term_link) ? '' : (string)$term_link;
            }
            if (!$current_url) {
                $current_url = $this->get_current_request_url();
            }
            if (!$current_url) {
                $current_url = get_home_url($current_blog_id, '/');
            }
            if ($current_url) {
                $urls[$current_blog_id] = [
                    'url' => $current_url,
                    'hreflang' => $sites[$current_blog_id]['hreflang'] ?? '',
                ];
            }
        }

        return [
            'sites' => $sites,
            'urls' => $urls,
            'current_blog_id' => $current_blog_id,
        ];
    }

    public function filter_language_switcher_menu_items($items, $menu, $args) {
        if (is_admin() || !is_array($items) || !$this->is_enabled_language_switcher_blog()) {
            return $items;
        }
        $settings = $this->get_settings();
        if (empty($settings['enable_menu_language_switcher'])) {
            return $items;
        }

        $context = $this->get_language_switcher_runtime_context();
        $sites = $context['sites'];
        $urls = $context['urls'];
        $current_blog_id = (int)$context['current_blog_id'];
        $custom_labels = [];

        foreach ($items as $item) {
            if (($item->object ?? '') !== self::LANGUAGE_SWITCHER_POST_TYPE) {
                continue;
            }
            $object_id = absint($item->object_id ?? 0);
            $switcher_post = $object_id ? get_post($object_id) : null;
            if (!$switcher_post || $switcher_post->post_type !== self::LANGUAGE_SWITCHER_POST_TYPE) {
                continue;
            }
            $token = trim((string)$switcher_post->post_content);
            if (!empty($item->post_title)) {
                $custom_labels[$token] = (string)$item->post_title;
            }
        }

        $current_present = false;
        $remove_indexes = [];
        $real_current_indexes = [];

        foreach ($items as $index => $item) {
            if (($item->object ?? '') !== self::LANGUAGE_SWITCHER_POST_TYPE) {
                continue;
            }
            $object_id = absint($item->object_id ?? 0);
            $switcher_post = $object_id ? get_post($object_id) : null;
            if (!$switcher_post || $switcher_post->post_type !== self::LANGUAGE_SWITCHER_POST_TYPE) {
                continue;
            }

            $token = trim((string)$switcher_post->post_content);
            $target_blog_id = 0;
            if ($token === 'current_language') {
                $current_present = true;
                $target_blog_id = $current_blog_id;
            } elseif (preg_match('/^site:(\d+)$/', $token, $matches)) {
                $target_blog_id = absint($matches[1]);
                if ($target_blog_id === $current_blog_id) {
                    $real_current_indexes[] = $index;
                }
            }

            if (!$target_blog_id || !isset($sites[$target_blog_id]) || !isset($urls[$target_blog_id])) {
                $remove_indexes[] = $index;
                continue;
            }

            $label = $token !== 'current_language' && isset($custom_labels[$token]) && $custom_labels[$token] !== ''
                ? $custom_labels[$token]
                : $sites[$target_blog_id]['label'];
            $item->title = $label;
            $item->wpmu_ml_language_item = 1;
            $item->wpmu_ml_flag_html = $this->render_language_switcher_flag($sites[$target_blog_id], [], 'menu');
            $is_unavailable = !empty($urls[$target_blog_id]['unavailable']);
            $item->url = $is_unavailable ? '#' : esc_url($urls[$target_blog_id]['url']);
            $item->classes = array_values(array_unique(array_merge((array)($item->classes ?? []), [
                'wpmu-ml-language-switcher-container',
                'wpmu-ml-menu-language-item',
                'wpmu-ml-language-site-' . $target_blog_id,
            ])));
            if ($target_blog_id === $current_blog_id) {
                $item->classes[] = 'current-language-menu-item';
            }
            if ($is_unavailable) {
                $item->classes[] = 'wpmu-ml-language-unavailable';
                $item->wpmu_ml_unavailable = 1;
                $item->wpmu_ml_unavailable_language = $label;
                $item->wpmu_ml_unavailable_message = '这个页面的对应语言版本还在准备中，请稍后再访问。';
            }
            $item->wpmu_ml_hreflang = $urls[$target_blog_id]['hreflang'] ?: ($sites[$target_blog_id]['hreflang'] ?? '');
        }

        if ($current_present) {
            $remove_indexes = array_merge($remove_indexes, $real_current_indexes);
        }
        $remove_indexes = array_values(array_unique(array_map('intval', $remove_indexes)));
        rsort($remove_indexes);
        foreach ($remove_indexes as $index) {
            if (isset($items[$index])) {
                unset($items[$index]);
            }
        }
        return array_values($items);
    }

    public function filter_language_switcher_link_attributes($atts, $item, $args, $depth) {
        if (!empty($item->wpmu_ml_hreflang)) {
            $atts['hreflang'] = sanitize_text_field((string)$item->wpmu_ml_hreflang);
        }
        if (!empty($item->wpmu_ml_unavailable)) {
            $message = sanitize_text_field((string)($item->wpmu_ml_unavailable_message ?? '这个页面的对应语言版本还在准备中，请稍后再访问。'));
            $language = sanitize_text_field((string)($item->wpmu_ml_unavailable_language ?? $item->title ?? ''));
            $atts['href'] = '#';
            $atts['aria-disabled'] = 'true';
            $atts['data-wpmu-ml-unavailable'] = '1';
            $atts['data-wpmu-ml-message'] = $message;
            if ($language !== '') {
                $atts['data-wpmu-ml-language'] = $language;
            }
            $atts['role'] = 'button';
        }
        return $atts;
    }

    public function shortcode_language_switcher($atts = []) {
        return $this->render_language_switcher(get_current_blog_id(), is_singular() ? get_the_ID() : 0, (array)$atts);
    }


    public function enqueue_language_switcher_assets() {
        if (is_admin()) {
            return;
        }
        $style_path = WPMU_ML_PLUGIN_DIR . 'assets/css/language-switcher.css';
        $style_version = is_readable($style_path) ? (string)filemtime($style_path) : WPMU_ML_VERSION;
        wp_enqueue_style('wpmu-ml-language-switcher', WPMU_ML_PLUGIN_URL . 'assets/css/language-switcher.css', [], $style_version);
        wp_enqueue_script('wpmu-ml-language-switcher', WPMU_ML_PLUGIN_URL . 'assets/js/language-switcher.js', [], WPMU_ML_VERSION, true);
    }

    public function output_language_unavailable_modal() {
        if (is_admin()) {
            return;
        }
        $settings = $this->get_settings();
        if (($settings['language_switcher_unpublished_policy'] ?? 'hide') !== 'notice') {
            return;
        }
        ?>
        <div class="wpmu-ml-language-notice-modal" id="wpmu-ml-language-notice-modal" aria-hidden="true">
            <div class="wpmu-ml-language-notice-dialog" role="dialog" aria-modal="true" aria-labelledby="wpmu-ml-language-notice-title">
                <button type="button" class="wpmu-ml-language-notice-close" data-wpmu-ml-modal-close aria-label="关闭">×</button>
                <div class="wpmu-ml-language-notice-icon" aria-hidden="true">!</div>
                <h2 class="wpmu-ml-language-notice-title" id="wpmu-ml-language-notice-title">该语言版本暂未发布</h2>
                <p class="wpmu-ml-language-notice-text" id="wpmu-ml-language-notice-text">这个页面的对应语言版本还在准备中，请稍后再访问。</p>
                <div class="wpmu-ml-language-notice-actions">
                    <button type="button" class="wpmu-ml-language-notice-ok" data-wpmu-ml-modal-close>知道了</button>
                </div>
            </div>
        </div>
        <?php
    }

    private function get_language_switcher_flag_url($site, $style = '4x3') {
        $style = in_array($style, ['1x1', '4x3'], true) ? $style : '4x3';
        $locale = trim((string)($site['locale'] ?? ''));
        $lang_slug = trim((string)($site['lang_slug'] ?? ''));
        $candidates = [];
        if ($locale !== '') {
            $candidates[] = $locale;
            $candidates[] = str_replace('_', '-', $locale);
            $candidates[] = strtolower($locale);
            $candidates[] = strtoupper($locale);
            $candidates[] = strtolower(str_replace('_', '-', $locale));
            $candidates[] = str_replace('-', '_', strtolower($locale));
        }
        if ($lang_slug !== '') {
            $candidates[] = $lang_slug;
            $candidates[] = strtolower($lang_slug);
        }
        $candidates = array_values(array_unique(array_filter($candidates)));
        foreach ($candidates as $candidate) {
            $path = WPMU_ML_PLUGIN_DIR . 'assets/flags/' . $style . '/' . $candidate . '.svg';
            if (is_readable($path)) {
                return WPMU_ML_PLUGIN_URL . 'assets/flags/' . $style . '/' . rawurlencode($candidate) . '.svg';
            }
            $png = WPMU_ML_PLUGIN_DIR . 'assets/flags/' . $style . '/' . $candidate . '.png';
            if (is_readable($png)) {
                return WPMU_ML_PLUGIN_URL . 'assets/flags/' . $style . '/' . rawurlencode($candidate) . '.png';
            }
        }
        return '';
    }

    private function render_language_switcher_flag($site, $args = [], $class = '') {
        $settings = $this->get_settings();
        $flag_mode = sanitize_key((string)($args['flag_mode'] ?? ''));
        if ($flag_mode === '') {
            $flag_mode = sanitize_key((string)($settings['language_switcher_flag_mode'] ?? 'none'));
        }
        if (!in_array($flag_mode, ['before', 'after'], true)) {
            return '';
        }
        $style = sanitize_key((string)($args['flag_style'] ?? ''));
        if ($style === '') {
            $style = sanitize_key((string)($settings['language_switcher_flag_style'] ?? '4x3'));
        }
        if (!in_array($style, ['4x3', '1x1'], true)) {
            $style = '4x3';
        }
        $size = absint($args['flag_size'] ?? 0);
        if (!$size) {
            $size = absint($settings['language_switcher_flag_size'] ?? 24);
        }
        if ($size < 12) {
            $size = 24;
        }
        if ($size > 64) {
            $size = 64;
        }
        $radius = $args['flag_radius'] ?? null;
        if ($radius === null || $radius === '') {
            $radius = $settings['language_switcher_flag_radius'] ?? 2;
        }
        $radius = absint($radius);
        if ($radius > 32) {
            $radius = 32;
        }
        $url = $this->get_language_switcher_flag_url($site, $style);
        if ($url === '') {
            return '';
        }
        $alt = trim((string)($site['label'] ?? ''));
        $classes = trim('wpmu-ml-language-flag ' . $class);
        $height = $style === '1x1' ? $size : max(1, (int)round($size * 0.75));
        return '<img class="' . esc_attr($classes) . '" src="' . esc_url($url) . '" alt="' . esc_attr($alt) . '" width="' . esc_attr((string)$size) . '" height="' . esc_attr((string)$height) . '" style="width:' . esc_attr((string)$size) . 'px;height:' . esc_attr((string)$height) . 'px;border-radius:' . esc_attr((string)$radius) . 'px;" loading="lazy" decoding="async">';
    }

    public function filter_language_switcher_menu_item_title($title, $item, $args, $depth) {
        if (empty($item->wpmu_ml_language_item) && empty($item->wpmu_ml_flag_html)) {
            return $title;
        }
        return '<span class="wpmu-ml-language-content">' . (string)($item->wpmu_ml_flag_html ?? '') . '<span class="wpmu-ml-language-name" dir="auto">' . esc_html(wp_strip_all_tags((string)$title)) . '</span></span>';
    }

    public function render_language_switcher($blog_id, $post_id = 0, $args = []) {
        $args = wp_parse_args((array)$args, [
            'display'       => 'full',
            'include_current_in_list' => false,
            'class'         => '',
            'current_label' => '',
            'flag_mode'     => '',
            'flag_style'    => '',
            'flag_size'     => 0,
            'flag_radius'   => null,
        ]);

        $settings = $this->get_settings();
        $context = $this->get_language_switcher_runtime_context();
        if (empty($context['sites']) || empty($context['urls'])) {
            return '';
        }

        $sites = (array)$context['sites'];
        $urls = (array)$context['urls'];
        $current_blog = absint($context['current_blog_id'] ?? get_current_blog_id());
        if (!$current_blog || empty($sites[$current_blog]) || empty($urls[$current_blog])) {
            return '';
        }

        $display_label = function($site) use ($args) {
            $display = sanitize_key((string)$args['display']);
            $slug = trim((string)($site['lang_slug'] ?? ''));
            $label = trim((string)($site['label'] ?? ''));

            if ($display === 'full') {
                return $label !== '' ? $label : strtoupper($slug);
            }
            if ($display === 'slug') {
                return $slug !== '' ? $slug : $label;
            }
            return $slug !== '' ? strtoupper($slug) : $label;
        };

        $flag_mode = sanitize_key((string)($args['flag_mode'] ?? ''));
        if ($flag_mode === '') {
            $flag_mode = sanitize_key((string)($settings['language_switcher_flag_mode'] ?? 'none'));
        }
        if (!in_array($flag_mode, ['none', 'before', 'after'], true)) {
            $flag_mode = 'none';
        }

        $classes = array_filter(array_merge([
            'wpmu-ml-language-switcher',
            'wpmu-ml-language-switcher-dropdown',
        ], preg_split('/\s+/', (string)$args['class'])));

        $current_site = $sites[$current_blog];
        $current_text = trim((string)$args['current_label']);
        if ($current_text === '') {
            $current_text = $display_label($current_site);
        }
        if ($current_text === '') {
            return '';
        }
        $current_flag_html = $this->render_language_switcher_flag($current_site, $args, 'current');
        $current_text_html = '<span class="wpmu-ml-language-name" dir="auto">' . esc_html($current_text) . '</span>';
        if ($current_flag_html !== '' && $flag_mode === 'before') {
            $current_text_html = $current_flag_html . $current_text_html;
        } elseif ($current_flag_html !== '' && $flag_mode === 'after') {
            $current_text_html = $current_text_html . $current_flag_html;
        }
        $current_text_html = '<span class="wpmu-ml-language-content">' . $current_text_html . '</span>';

        $html  = '<nav class="' . esc_attr(implode(' ', $classes)) . '" aria-label="语言切换" data-no-translation>';
        $html .= '<button type="button" class="wpmu-ml-current-language current-language-menu-item wpmu-ml-language-site-' . esc_attr((string)$current_blog) . '" aria-haspopup="true" aria-expanded="false">';
        $html .= $current_text_html;
        $html .= '</button>';
        $html .= '<ul class="wpmu-ml-language-list">';

        foreach ($sites as $target_blog_id => $site) {
            $target_blog_id = absint($target_blog_id);
            if (!$target_blog_id || !isset($urls[$target_blog_id]) || (empty($urls[$target_blog_id]['url']) && empty($urls[$target_blog_id]['unavailable']))) {
                continue;
            }
            $is_current = $target_blog_id === $current_blog;
            if ($is_current && empty($args['include_current_in_list'])) {
                continue;
            }

            $item_classes = [
                'wpmu-ml-language-item',
                'wpmu-ml-language-site-' . $target_blog_id,
            ];
            if ($is_current) {
                $item_classes[] = 'current-language-menu-item';
            }
            $is_unavailable = !empty($urls[$target_blog_id]['unavailable']);
            if ($is_unavailable) {
                $item_classes[] = 'wpmu-ml-language-unavailable';
            }

            $hreflang = $urls[$target_blog_id]['hreflang'] ?: ($site['hreflang'] ?? '');
            $label = $display_label($site);
            if ($label === '') {
                continue;
            }

            $flag_html = $this->render_language_switcher_flag($site, $args, $is_current ? 'current' : 'item');
            $text_html = '<span class="wpmu-ml-language-name" dir="auto">' . esc_html($label) . '</span>';
            if ($flag_html !== '' && $flag_mode === 'before') {
                $text_html = $flag_html . $text_html;
            } elseif ($flag_html !== '' && $flag_mode === 'after') {
                $text_html = $text_html . $flag_html;
            }
            $text_html = '<span class="wpmu-ml-language-content">' . $text_html . '</span>';

            $html .= '<li class="' . esc_attr(implode(' ', $item_classes)) . '">';
            $html .= '<a href="' . esc_url($is_unavailable ? '#' : $urls[$target_blog_id]['url']) . '"';
            if ($hreflang !== '') {
                $html .= ' hreflang="' . esc_attr($hreflang) . '" lang="' . esc_attr($hreflang) . '"';
            }
            if ($is_unavailable) {
                $message = '这个页面的对应语言版本还在准备中，请稍后再访问。';
                $html .= ' aria-disabled="true" role="button" data-wpmu-ml-unavailable="1" data-wpmu-ml-language="' . esc_attr($label) . '" data-wpmu-ml-message="' . esc_attr($message) . '"';
            }
            $html .= '>' . $text_html . '</a>';
            $html .= '</li>';
        }

        $html .= '</ul></nav>';
        return $html;
    }

    private function shorten_my_sites_language_name($language_name) {
        $language_name = trim((string)$language_name);
        if ($language_name === '') {
            return '';
        }
        $parts = preg_split('/\s+\/\s+/', $language_name, 2);
        if (!empty($parts[0])) {
            $language_name = trim((string)$parts[0]);
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            if (mb_strlen($language_name, 'UTF-8') > 32) {
                $language_name = rtrim(mb_substr($language_name, 0, 32, 'UTF-8')) . '…';
            }
        } elseif (strlen($language_name) > 64) {
            $language_name = rtrim(substr($language_name, 0, 64)) . '…';
        }
        return $language_name;
    }

    public function output_my_sites_language_card_meta() {
        $settings = $this->get_settings();
        if (empty($settings['show_my_sites_language_card_meta']) || !is_multisite()) {
            return;
        }
        $sites = $this->get_i18n_sites(false);
        if (!$sites) {
            return;
        }
        $meta = [];
        foreach ($sites as $site) {
            $blog_id = absint($site['blog_id'] ?? 0);
            if (!$blog_id) {
                continue;
            }
            $locale = trim((string)($site['locale'] ?? ''));
            if ($locale === '') {
                $locale = $this->get_site_wp_locale($blog_id);
            }
            $language_name = trim((string)($site['language_name'] ?? ''));
            if ($language_name === '' && $locale !== '') {
                $language_name = $this->get_locale_language_name($locale);
            }
            $language_name = $this->shorten_my_sites_language_name($language_name);
            $meta[$blog_id] = [
                'url' => untrailingslashit((string)get_home_url($blog_id, '/')),
                'language' => $language_name,
                'slug' => sanitize_key((string)($site['lang_slug'] ?? '')),
            ];
        }
        if (!$meta) {
            return;
        }
        ?>
        <style id="wpmu-ml-my-sites-card-language-css">
            .wpmu-ml-my-sites-language-meta { margin: 10px 0 0; color: #50575e; line-height: 1.45; font-size: 13px; }
            .wpmu-ml-my-sites-language-meta .wpmu-ml-my-sites-language-slug { display: block; margin-top: 2px; color: #1d2327; font-family: Consolas, Monaco, monospace; font-size: 12px; }
        </style>
        <script>
        (function(){
            var meta = <?php echo wp_json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
            if (!meta) return;
            function normalize(url) {
                if (!url) return '';
                var a = document.createElement('a');
                a.href = url;
                return (a.protocol + '//' + a.host + a.pathname).replace(/\/+$/, '');
            }
            var byUrl = {};
            Object.keys(meta).forEach(function(blogId){
                var item = meta[blogId] || {};
                var key = normalize(item.url);
                if (key) byUrl[key] = item;
            });
            document.querySelectorAll('.my-sites li, .my-sites .site, .my-sites .site-info, .my-sites .blog').forEach(function(card){
                if (card.querySelector('.wpmu-ml-my-sites-language-meta')) return;
                var visit = card.querySelector('a[href]');
                var item = null;
                card.querySelectorAll('a[href]').forEach(function(link){
                    var key = normalize(link.href);
                    if (!item && byUrl[key]) item = byUrl[key];
                });
                if (!item || (!item.language && !item.slug)) return;
                var anchor = visit;
                if (!anchor) return;
                var wrap = document.createElement('div');
                wrap.className = 'wpmu-ml-my-sites-language-meta';
                if (item.language) {
                    wrap.appendChild(document.createTextNode(item.language));
                }
                if (item.slug) {
                    var slug = document.createElement('span');
                    slug.className = 'wpmu-ml-my-sites-language-slug';
                    slug.textContent = item.slug;
                    wrap.appendChild(slug);
                }
                var actions = anchor.parentNode;
                if (actions && actions.parentNode) {
                    actions.parentNode.insertBefore(wrap, actions.nextSibling);
                }
            });
        })();
        </script>
        <?php
    }

    private function get_admin_bar_language_site_label($blog_id, $fallback_title = '') {
        global $wpdb;
        $blog_id = absint($blog_id);
        if (!$blog_id) {
            return (string)$fallback_title;
        }
        $site = $wpdb->get_row($wpdb->prepare(
            "SELECT lang_slug, locale, language_name FROM {$this->tables['sites']} WHERE blog_id = %d LIMIT 1",
            $blog_id
        ), ARRAY_A);
        $lang_slug = '';
        $language_name = '';
        if (is_array($site)) {
            $lang_slug = sanitize_key((string)($site['lang_slug'] ?? ''));
            $locale = trim((string)($site['locale'] ?? ''));
            $language_name = trim((string)($site['language_name'] ?? ''));
        } else {
            $locale = $this->get_site_wp_locale($blog_id);
        }
        if ($language_name === '' && $locale !== '') {
            $language_name = $this->get_locale_language_name($locale);
        }
        $language_name = $this->shorten_my_sites_language_name($language_name);
        if ($language_name !== '' && $lang_slug !== '') {
            return $language_name . ' / ' . $lang_slug;
        }
        if ($language_name !== '') {
            return $language_name;
        }
        if ($lang_slug !== '') {
            return $lang_slug;
        }
        return wp_strip_all_tags((string)$fallback_title);
    }

    public function maybe_rewrite_my_sites_admin_bar_links($wp_admin_bar) {
        if (!is_admin_bar_showing() || !is_object($wp_admin_bar) || !method_exists($wp_admin_bar, 'get_node')) {
            return;
        }
        $settings = $this->get_settings();
        $rewrite_current_page_links = !empty($settings['admin_bar_current_page_site_links']);
        $rewrite_language_labels = !empty($settings['admin_bar_language_site_labels']);
        if (!$rewrite_current_page_links && !$rewrite_language_labels) {
            return;
        }
        if (!is_user_logged_in() || !is_multisite()) {
            return;
        }

        $current_blog_id = get_current_blog_id();
        $sites = get_sites(['number' => 0]);
        foreach ($sites as $site) {
            $blog_id = (int)$site->blog_id;
            if (!$blog_id || !current_user_can_for_site($blog_id, 'read')) {
                continue;
            }
            $node_id = 'blog-' . $blog_id;
            $node = $wp_admin_bar->get_node($node_id);
            if (!$node) {
                continue;
            }
            $classes = [];
            if (!empty($node->meta['class'])) {
                $classes[] = (string)$node->meta['class'];
            }
            if ($blog_id === $current_blog_id) {
                $classes[] = 'wpmu-ml-current-site-page-link';
            }
            $meta = is_array($node->meta) ? $node->meta : [];
            $meta['class'] = trim(implode(' ', array_unique(array_filter($classes))));
            $wp_admin_bar->add_node([
                'id' => $node_id,
                'title' => $rewrite_language_labels ? esc_html($this->get_admin_bar_language_site_label($blog_id, $node->title)) : $node->title,
                'parent' => $node->parent,
                'href' => $rewrite_current_page_links ? $this->get_admin_bar_site_current_page_url($blog_id) : $node->href,
                'group' => $node->group,
                'meta' => $meta,
            ]);
        }
    }

    public function output_my_sites_admin_bar_css() {
        if (!is_admin_bar_showing()) {
            return;
        }
        echo '<style id="wpmu-ml-my-sites-current-page-css">#wpadminbar #wp-admin-bar-my-sites>.ab-sub-wrapper{max-height:calc(100vh - 32px)!important;overflow-y:auto!important;overflow-x:hidden!important;padding-bottom:30px!important;box-sizing:border-box!important;scrollbar-width:none;scrollbar-color:transparent transparent}#wpadminbar #wp-admin-bar-my-sites>.ab-sub-wrapper::-webkit-scrollbar{width:0!important;height:0!important;background:transparent!important}#wpadminbar #wp-admin-bar-my-sites>.ab-sub-wrapper::-webkit-scrollbar-track{background:transparent!important}#wpadminbar #wp-admin-bar-my-sites>.ab-sub-wrapper::-webkit-scrollbar-thumb{background:transparent!important;border-radius:0!important}#wpadminbar #wp-admin-bar-my-sites .menupop>.ab-sub-wrapper,#wpadminbar #wp-admin-bar-my-sites .menupop:hover>.ab-sub-wrapper,#wpadminbar #wp-admin-bar-my-sites .menupop.hover>.ab-sub-wrapper{display:none!important;visibility:hidden!important;opacity:0!important;pointer-events:none!important}#wpadminbar #wp-admin-bar-my-sites>.ab-sub-wrapper>.ab-submenu,#wpadminbar #wp-admin-bar-my-sites-default{min-width:180px!important}#wpadminbar #wp-admin-bar-my-sites .ab-sub-wrapper .ab-item{padding-inline-end:33px!important;white-space:nowrap!important}#wpadminbar .wpmu-ml-current-site-page-link > .ab-item,#wpadminbar .wpmu-ml-current-site-page-link > .ab-item .ab-label{background:#1d2327!important;color:#6c7feb !important;font-weight:600!important}#wpadminbar .wpmu-ml-current-site-page-link > .ab-item:before{color:#6c7feb !important}</style>';
    }

    private function get_admin_bar_site_current_page_url($target_blog_id) {
        $target_blog_id = absint($target_blog_id);
        if (!$target_blog_id) {
            return admin_url();
        }
        // Network-admin screens do not have equivalent per-site admin URLs.
        // Do not turn /wp-admin/network/... into each site's admin URL; fall
        // back to the target site's dashboard instead.
        if (is_network_admin()) {
            return get_admin_url($target_blog_id, '');
        }
        $post_url = $this->get_related_current_post_url_for_blog($target_blog_id);
        if ($post_url !== '') {
            return $post_url;
        }
        if (is_admin()) {
            return $this->get_equivalent_admin_url_for_blog($target_blog_id);
        }
        return get_home_url($target_blog_id, '/');
    }

    private function get_related_current_post_url_for_blog($target_blog_id) {
        global $wpdb;
        $current_blog_id = get_current_blog_id();
        $current_post_id = $this->get_current_admin_bar_post_id();
        if (!$current_post_id) {
            return '';
        }
        $settings = $this->get_settings();
        $source_blog_id = absint($settings['source_blog_id'] ?? 0);
        if (!$source_blog_id) {
            return '';
        }

        if ($current_blog_id === $source_blog_id) {
            $source_post_id = $current_post_id;
        } else {
            $current_relation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->tables['posts']} WHERE target_blog_id = %d AND target_post_id = %d LIMIT 1",
                $current_blog_id,
                $current_post_id
            ), ARRAY_A);
            $current_identity = $this->validate_post_relation($current_relation, true);
            if (empty($current_identity['valid'])) {
                return '';
            }
            $source_post_id = (int)$current_relation['source_post_id'];
        }
        if (!$source_post_id) {
            return '';
        }

        if ((int)$target_blog_id === $source_blog_id) {
            $target_post_id = $source_post_id;
            $target_relation = null;
        } else {
            $target_relation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->tables['posts']} WHERE source_blog_id = %d AND source_post_id = %d AND target_blog_id = %d LIMIT 1",
                $source_blog_id,
                $source_post_id,
                $target_blog_id
            ), ARRAY_A);
            $target_identity = $this->validate_post_relation($target_relation, true);
            if (empty($target_identity['valid'])) {
                return '';
            }
            $target_post_id = (int)$target_relation['target_post_id'];
        }
        if (!$target_post_id) {
            return '';
        }

        switch_to_blog($target_blog_id);
        $post = get_post($target_post_id);
        if (!$post) {
            restore_current_blog();
            return '';
        }
        $url = is_admin() ? get_edit_post_link($target_post_id, '') : get_permalink($target_post_id);
        restore_current_blog();
        return $url ? (string)$url : '';
    }

    private function get_current_admin_bar_post_id() {
        if (is_admin()) {
            if (!empty($_GET['post'])) {
                return absint($_GET['post']);
            }
            if (!empty($_POST['post_ID'])) {
                return absint($_POST['post_ID']);
            }
            return 0;
        }
        if (is_singular()) {
            return absint(get_queried_object_id());
        }
        return 0;
    }

    private function get_equivalent_admin_url_for_blog($target_blog_id) {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string)wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = parse_url($request_uri, PHP_URL_PATH);
        $query = parse_url($request_uri, PHP_URL_QUERY);
        $path = is_string($path) ? $path : '';
        $query = is_string($query) ? $query : '';

        $relative = '';
        $network_admin_path = parse_url(network_admin_url(), PHP_URL_PATH);
        $admin_path = parse_url(admin_url(), PHP_URL_PATH);
        foreach (array_filter([(string)$network_admin_path, (string)$admin_path]) as $base_path) {
            if ($base_path !== '' && strpos($path, $base_path) === 0) {
                $relative = ltrim(substr($path, strlen($base_path)), '/');
                break;
            }
        }
        if ($relative === '') {
            $relative = 'index.php';
        }
        if ($query !== '') {
            $relative .= '?' . $query;
        }
        return get_admin_url($target_blog_id, $relative);
    }

    private function get_term_language_urls($term) {
        global $wpdb;
        if (!$term instanceof WP_Term) {
            return [];
        }
        $settings = $this->get_settings();
        $source_blog_id = absint($settings['source_blog_id'] ?? 0);
        $current_blog_id = get_current_blog_id();
        if (!$source_blog_id) {
            return [];
        }

        $source_term_id = 0;
        $source_taxonomy = (string)$term->taxonomy;
        if ($current_blog_id === $source_blog_id) {
            $source_term_id = (int)$term->term_id;
        } else {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT source_term_id, source_taxonomy FROM {$this->tables['terms']} WHERE target_blog_id = %d AND target_term_id = %d AND target_taxonomy = %s LIMIT 1",
                $current_blog_id,
                (int)$term->term_id,
                (string)$term->taxonomy
            ), ARRAY_A);
            if ($row) {
                $source_term_id = (int)$row['source_term_id'];
                $source_taxonomy = (string)$row['source_taxonomy'];
            }
        }
        if (!$source_term_id || $source_taxonomy === '') {
            return [];
        }

        $out = [];
        foreach ($this->get_i18n_sites(true) as $site) {
            $blog_id = absint($site['blog_id'] ?? 0);
            if (!$blog_id) {
                continue;
            }
            $target_term_id = 0;
            $target_taxonomy = $source_taxonomy;
            if ($blog_id === $source_blog_id) {
                $target_term_id = $source_term_id;
            } else {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT target_term_id, target_taxonomy FROM {$this->tables['terms']} WHERE source_blog_id = %d AND source_term_id = %d AND source_taxonomy = %s AND target_blog_id = %d LIMIT 1",
                    $source_blog_id,
                    $source_term_id,
                    $source_taxonomy,
                    $blog_id
                ), ARRAY_A);
                if ($row) {
                    $target_term_id = (int)$row['target_term_id'];
                    $target_taxonomy = (string)$row['target_taxonomy'];
                }
            }
            if (!$target_term_id || $target_taxonomy === '') {
                continue;
            }
            switch_to_blog($blog_id);
            $target_term = get_term($target_term_id, $target_taxonomy);
            $url = '';
            if ($target_term && !is_wp_error($target_term)) {
                $term_link = get_term_link($target_term);
                $url = is_wp_error($term_link) ? '' : (string)$term_link;
            }
            restore_current_blog();
            if (!$url) {
                continue;
            }
            $out[] = [
                'blog_id' => $blog_id,
                'post_id' => 0,
                'lang_slug' => sanitize_key((string)($site['lang_slug'] ?? '')),
                'hreflang' => $this->normalize_hreflang((string)($site['hreflang'] ?? '')),
                'url' => $url,
            ];
        }
        return $out;
    }

    private function get_author_language_urls() {
        $author_id = absint(get_query_var('author'));
        if (!$author_id) {
            $author = get_queried_object();
            if ($author instanceof WP_User) {
                $author_id = (int)$author->ID;
            }
        }
        if (!$author_id) {
            return [];
        }
        $out = [];
        foreach ($this->get_i18n_sites(true) as $site) {
            $blog_id = absint($site['blog_id'] ?? 0);
            if (!$blog_id) {
                continue;
            }
            switch_to_blog($blog_id);
            $url = get_author_posts_url($author_id);
            restore_current_blog();
            if (!$url) {
                continue;
            }
            $out[] = [
                'blog_id' => $blog_id,
                'post_id' => 0,
                'lang_slug' => sanitize_key((string)($site['lang_slug'] ?? '')),
                'hreflang' => $this->normalize_hreflang((string)($site['hreflang'] ?? '')),
                'url' => $url,
            ];
        }
        return $out;
    }

    private function get_post_type_archive_language_urls() {
        $post_type = get_query_var('post_type');
        if (is_array($post_type)) {
            $post_type = reset($post_type);
        }
        $post_type = sanitize_key((string)$post_type);
        if ($post_type === '') {
            return [];
        }
        $out = [];
        foreach ($this->get_i18n_sites(true) as $site) {
            $blog_id = absint($site['blog_id'] ?? 0);
            if (!$blog_id) {
                continue;
            }
            switch_to_blog($blog_id);
            $url = get_post_type_archive_link($post_type);
            restore_current_blog();
            if (!$url) {
                continue;
            }
            $out[] = [
                'blog_id' => $blog_id,
                'post_id' => 0,
                'lang_slug' => sanitize_key((string)($site['lang_slug'] ?? '')),
                'hreflang' => $this->normalize_hreflang((string)($site['hreflang'] ?? '')),
                'url' => $url,
            ];
        }
        return $out;
    }

    private function get_current_request_url() {
        $scheme = is_ssl() ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? (string)wp_unslash($_SERVER['HTTP_HOST']) : '';
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string)wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($host === '' || $request_uri === '') {
            return '';
        }
        return esc_url_raw($scheme . '://' . $host . $request_uri);
    }

    private function get_same_request_path_language_urls() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string)wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($request_uri === '') {
            return [];
        }
        $path = parse_url($request_uri, PHP_URL_PATH);
        $query = parse_url($request_uri, PHP_URL_QUERY);
        $path = is_string($path) ? $path : '/';
        $query = is_string($query) ? $query : '';

        $current_blog_id = get_current_blog_id();
        $current_home_path = parse_url(get_home_url($current_blog_id, '/'), PHP_URL_PATH);
        $current_home_path = is_string($current_home_path) ? trailingslashit($current_home_path) : '/';
        $relative_path = ltrim($path, '/');
        if ($current_home_path !== '/' && strpos(trailingslashit($path), $current_home_path) === 0) {
            $relative_path = ltrim(substr($path, strlen($current_home_path)), '/');
        }
        if ($relative_path === '') {
            $relative_path = '/';
        }
        if ($query !== '') {
            $relative_path .= (strpos($relative_path, '?') === false ? '?' : '&') . $query;
        }

        $out = [];
        foreach ($this->get_i18n_sites(true) as $site) {
            $blog_id = absint($site['blog_id'] ?? 0);
            if (!$blog_id) {
                continue;
            }
            $url = get_home_url($blog_id, $relative_path);
            if (!$url) {
                continue;
            }
            $out[] = [
                'blog_id' => $blog_id,
                'post_id' => 0,
                'lang_slug' => sanitize_key((string)($site['lang_slug'] ?? '')),
                'hreflang' => $this->normalize_hreflang((string)($site['hreflang'] ?? '')),
                'url' => $url,
            ];
        }
        return $out;
    }

    private function get_search_language_urls() {
        $query = get_search_query(false);
        $out = [];
        foreach ($this->get_i18n_sites(true) as $site) {
            $blog_id = absint($site['blog_id'] ?? 0);
            if (!$blog_id) {
                continue;
            }
            switch_to_blog($blog_id);
            $url = get_search_link($query);
            restore_current_blog();
            if (!$url) {
                continue;
            }
            $out[] = [
                'blog_id' => $blog_id,
                'post_id' => 0,
                'lang_slug' => sanitize_key((string)($site['lang_slug'] ?? '')),
                'hreflang' => $this->normalize_hreflang((string)($site['hreflang'] ?? '')),
                'url' => $url,
            ];
        }
        return $out;
    }

    public function get_alternate_urls($current_blog_id, $current_post_id, $include_unpublished_notice = false) {
        global $wpdb;
        $settings = $this->get_settings();
        $source_blog_id = absint($settings['source_blog_id']);
        if (!$source_blog_id) {
            return [];
        }
        $source_post_id = 0;
        $current_relation = null;
        if ((int)$current_blog_id === $source_blog_id) {
            $source_post_id = (int)$current_post_id;
        } else {
            $current_relation = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->tables['posts']} WHERE target_blog_id = %d AND target_post_id = %d LIMIT 1",
                $current_blog_id,
                $current_post_id
            ), ARRAY_A);
            $current_identity = $this->validate_post_relation($current_relation, true);
            if (empty($current_identity['valid'])) {
                return [];
            }
            $source_post_id = (int)$current_relation['source_post_id'];
        }
        if (!$source_post_id) {
            return [];
        }

        switch_to_blog($source_blog_id);
        $source_post = get_post($source_post_id);
        restore_current_blog();
        if (!$source_post instanceof WP_Post) {
            return [];
        }
        if ($current_relation && sanitize_key((string)$current_relation['post_type']) !== sanitize_key((string)$source_post->post_type)) {
            return [];
        }

        $sites = $this->get_i18n_sites(true);
        $out = [];
        foreach ($sites as $site) {
            $bid = (int)$site['blog_id'];
            $pid = 0;
            if ($bid === $source_blog_id) {
                $pid = $source_post_id;
                $relation = null;
            } else {
                $relation = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$this->tables['posts']} WHERE source_blog_id = %d AND source_post_id = %d AND target_blog_id = %d LIMIT 1",
                    $source_blog_id,
                    $source_post_id,
                    $bid
                ), ARRAY_A);
                $identity = $this->validate_post_relation($relation, true);
                if (empty($identity['valid'])) {
                    continue;
                }
                $pid = (int)$relation['target_post_id'];
            }
            if (!$pid) {
                continue;
            }
            switch_to_blog($bid);
            $status = get_post_status($pid);
            $url = get_permalink($pid);
            $indexable = $status === 'publish' ? $this->is_post_indexable_for_hreflang($pid) : false;
            restore_current_blog();
            $is_unpublished = $status !== 'publish';
            if ($is_unpublished) {
                if (!$include_unpublished_notice && !empty($settings['hide_unpublished'])) {
                    continue;
                }
                if ($include_unpublished_notice) {
                    $url = '';
                }
            }
            if ($url || ($is_unpublished && $include_unpublished_notice)) {
                $out[] = [
                    'blog_id' => $bid,
                    'post_id' => $pid,
                    'lang_slug' => $site['lang_slug'],
                    'hreflang' => $site['hreflang'],
                    'url' => $url,
                    'status' => $status,
                    'indexable' => $indexable,
                    'unavailable' => $is_unpublished && $include_unpublished_notice,
                ];
            }
        }
        return $out;
    }

    private function is_post_indexable_for_hreflang($post_id) {
        $post_id = absint($post_id);
        $post = $post_id ? get_post($post_id) : null;
        $indexable = $post instanceof WP_Post
            && (string)$post->post_status === 'publish'
            && (string)$post->post_password === ''
            && (string)get_option('blog_public', '1') !== '0';
        if ($indexable) {
            $yoast_noindex = strtolower(trim((string)get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true)));
            if (in_array($yoast_noindex, ['1','yes','true','noindex'], true)) {
                $indexable = false;
            }
        }
        if ($indexable) {
            $rank_math_robots = get_post_meta($post_id, 'rank_math_robots', true);
            $rank_math_robots = is_array($rank_math_robots) ? $rank_math_robots : preg_split('/[\s,]+/', strtolower((string)$rank_math_robots));
            if (in_array('noindex', array_map('strtolower', array_map('strval', (array)$rank_math_robots)), true)) {
                $indexable = false;
            }
        }
        if ($indexable) {
            $aioseo_noindex = get_post_meta($post_id, '_aioseo_robots_noindex', true);
            if (in_array(strtolower(trim((string)$aioseo_noindex)), ['1','yes','true','noindex'], true)) {
                $indexable = false;
            }
        }
        return (bool)apply_filters('wpmu_ml_post_is_indexable_for_hreflang', $indexable, $post_id, get_current_blog_id());
    }
    }
}
