<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/traits/bootstrap.php';

if (!class_exists('WPMU_Multilingual')) {

    final class WPMU_Multilingual {
        const VERSION = '0.9.8.10';
        const OPTION = 'wpmu_ml_settings';
        const NONCE_ACTION = 'wpmu_ml_admin_action';
        const NONCE_NAME = 'wpmu_ml_nonce';
        const LANGUAGE_SWITCHER_POST_TYPE = 'wpmu_ml_switcher';

        private static $instance = null;
        private $tables = [];

        // 业务实现按职责拆分，加载清单见 includes/core/traits/bootstrap.php。
        use WPMU_ML_Core_Foundation_Trait;
        use WPMU_ML_Core_Admin_UI_Trait;
        use WPMU_ML_Core_Engine_Routing_Trait;
        use WPMU_ML_Core_Queue_Trait;
        use WPMU_ML_Core_OpenCC_Trait;
        use WPMU_ML_Core_OpenAI_Translation_Trait;
        use WPMU_ML_Core_OpenAI_Content_Trait;
        use WPMU_ML_Core_OpenAI_Metadata_Trait;
        use WPMU_ML_Core_OpenAI_Quality_Trait;
        use WPMU_ML_Core_OpenAI_Client_Trait;
        use WPMU_ML_Core_Admin_Actions_Trait;
        use WPMU_ML_Core_Incremental_Sync_Trait;
        use WPMU_ML_Core_Relation_Safety_Trait;
        use WPMU_ML_Core_Term_Sync_Trait;
        use WPMU_ML_Core_Sync_Trait;
        use WPMU_ML_Core_Language_Switcher_Trait;

        public static function instance() {
            if (null === self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        private function __construct() {
            global $wpdb;
            $base = $wpdb->base_prefix;
            $this->tables = [
                'sites'     => $base . 'wpmu_ml_sites',
                'posts'     => $base . 'wpmu_ml_post_relations',
                'terms'     => $base . 'wpmu_ml_term_relations',
                'logs'      => $base . 'wpmu_ml_logs',
                'jobs'      => $base . 'wpmu_ml_translation_jobs',
            ];

            add_action('plugins_loaded', [$this, 'load']);
        }

        public function load() {
            if (!is_multisite()) {
                add_action('admin_notices', [$this, 'notice_need_multisite']);
                return;
            }

            $this->maybe_upgrade();

            add_action('network_admin_menu', [$this, 'network_admin_menu']);
            add_action('admin_post_wpmu_ml_save_sites', [$this, 'handle_save_sites']);
            add_action('admin_post_wpmu_ml_save_settings', [$this, 'handle_save_settings']);
            add_action('admin_post_wpmu_ml_save_switcher_settings', [$this, 'handle_save_switcher_settings']);
            add_action('admin_post_wpmu_ml_save_sync_settings', [$this, 'handle_save_sync_settings']);
            add_action('admin_post_wpmu_ml_save_translation_settings', [$this, 'handle_save_translation_settings']);
            add_action('admin_post_wpmu_ml_save_misc_settings', [$this, 'handle_save_misc_settings']);
            add_action('admin_post_wpmu_ml_translation_job_action', [$this, 'handle_translation_job_action']);
            add_action('admin_post_wpmu_ml_process_queue', [$this, 'handle_process_queue']);
            add_action('admin_post_wpmu_ml_release_queue_locks', [$this, 'handle_release_queue_locks']);
            add_action('admin_post_wpmu_ml_translate_single', [$this, 'handle_translate_single']);
            add_action('admin_post_wpmu_ml_run_batch_sync', [$this, 'handle_run_batch_sync']);
            add_action('admin_post_wpmu_ml_rebuild_relations', [$this, 'handle_rebuild_relations']);
            add_action('admin_post_wpmu_ml_sync_same_id_drafts', [$this, 'handle_sync_same_id_drafts']);
            add_action('admin_post_wpmu_ml_sync_language_status', [$this, 'handle_sync_language_status']);
            add_action('wp_head', [$this, 'output_hreflang'], 2);
            add_action('save_post', [$this, 'maybe_auto_sync_source_post'], 30, 3);
            add_action('transition_post_status', [$this, 'maybe_mark_target_post_translated'], 30, 3);
            add_action('wp_trash_post', [$this, 'maybe_sync_source_post_trashed'], 30, 1);
            add_action('before_delete_post', [$this, 'maybe_sync_source_post_deleted'], 30, 1);
            add_action('untrash_post', [$this, 'maybe_sync_source_post_untrashed'], 30, 1);
            add_action('trashed_post', [$this, 'maybe_mark_target_post_trashed'], 40, 1);
            add_action('before_delete_post', [$this, 'maybe_mark_target_post_deleted'], 40, 1);
            add_action('untrashed_post', [$this, 'maybe_mark_target_post_untrashed'], 40, 1);
            add_action('created_term', [$this, 'maybe_sync_source_term_created'], 30, 4);
            add_action('edited_term', [$this, 'maybe_sync_source_term_edited'], 30, 4);
            add_action('delete_term', [$this, 'maybe_sync_source_term_deleted'], 30, 5);
            add_filter('cron_schedules', [$this, 'add_cron_schedules']);
            add_action('wpmu_ml_process_translation_queue', [$this, 'cron_process_translation_queue']);
            add_action('wpmu_ml_process_manual_translation_queue', [$this, 'cron_process_manual_translation_queue'], 10, 4);
            add_action('admin_post_wpmu_ml_async_process_queue', [$this, 'handle_async_process_queue']);
            add_action('admin_post_nopriv_wpmu_ml_async_process_queue', [$this, 'handle_async_process_queue']);
            add_action('admin_bar_menu', [$this, 'maybe_rewrite_my_sites_admin_bar_links'], 999);
            add_action('admin_head', [$this, 'output_my_sites_admin_bar_css']);
            add_action('wp_head', [$this, 'output_my_sites_admin_bar_css']);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_language_switcher_assets']);
            add_action('wp_footer', [$this, 'output_language_unavailable_modal']);
            add_action('admin_footer-my-sites.php', [$this, 'output_my_sites_language_card_meta']);

            $settings = $this->get_settings();
            if (!empty($settings['enable_menu_language_switcher'])) {
                add_action('init', [$this, 'register_language_switcher_post_type'], 1);
                add_action('init', [$this, 'maybe_sync_language_switcher_menus_after_upgrade'], 20);
                add_action('admin_init', [$this, 'maybe_sync_current_site_language_switcher_entries']);
                add_filter('get_user_option_metaboxhidden_nav-menus', [$this, 'keep_language_switcher_metabox_visible']);
                add_filter('wp_get_nav_menu_items', [$this, 'filter_language_switcher_menu_items'], 10, 3);
                add_filter('nav_menu_link_attributes', [$this, 'filter_language_switcher_link_attributes'], 10, 4);
                add_filter('nav_menu_item_title', [$this, 'filter_language_switcher_menu_item_title'], 10, 4);
            }

            $this->maybe_schedule_queue_runner();
            add_shortcode('wpmu_language_switcher', [$this, 'shortcode_language_switcher']);

            if (class_exists('WPMU_ML_Agent')) {
                WPMU_ML_Agent::instance($this);
            }

            if (class_exists('WPMU_ML_CLI')) {
                WPMU_ML_CLI::register();
            }
        }

    }
}
