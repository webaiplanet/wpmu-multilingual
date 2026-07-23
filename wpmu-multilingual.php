<?php
/**
 * Plugin Name: WPMU多语言
 * Plugin URI: https://github.com/webaiplanet/wpmu-multilingual
 * Description: WordPress Multisite 多语言关联、hreflang、自动同步、翻译队列、OpenCC、OpenAI 兼容与 Agent API 翻译插件。建议网络启用。
 * Version: 0.9.8.9
 * Author: WPMU多语言
 * Text Domain: wpmu-multilingual
 * Network: true
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WPMU_ML_VERSION', '0.9.8.9');
define('WPMU_ML_PLUGIN_FILE', __FILE__);
define('WPMU_ML_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPMU_ML_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WPMU_ML_PLUGIN_DIR . 'includes/bootstrap.php';

register_activation_hook(__FILE__, ['WPMU_Multilingual', 'activate']);
WPMU_Multilingual::instance();

if (!function_exists('wpmu_ml_language_switcher')) {
    function wpmu_ml_language_switcher($args = [], $echo = true) {
        if (is_bool($args)) {
            $echo = $args;
            $args = [];
        }
        $html = WPMU_Multilingual::instance()->render_language_switcher(get_current_blog_id(), is_singular() ? get_the_ID() : 0, (array)$args);
        if ($echo) {
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        return $html;
    }
}
