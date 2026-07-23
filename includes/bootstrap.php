<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Central file loader for WPMU Multilingual.
 *
 * File and directory names follow WordPress-style lower-case, hyphenated names.
 * PHP class names keep the historical WPMU_ML_* prefix.
 */
require_once WPMU_ML_PLUGIN_DIR . 'includes/support/class-wpmu-ml-html-exclusion.php';
require_once WPMU_ML_PLUGIN_DIR . 'includes/support/class-wpmu-ml-content-sanitizer.php';
require_once WPMU_ML_PLUGIN_DIR . 'includes/engines/openai/class-wpmu-ml-openai-helper.php';
require_once WPMU_ML_PLUGIN_DIR . 'includes/engines/agent/class-wpmu-ml-agent-payload.php';
require_once WPMU_ML_PLUGIN_DIR . 'includes/engines/agent/class-wpmu-ml-agent-validator.php';
require_once WPMU_ML_PLUGIN_DIR . 'includes/engines/agent/class-wpmu-ml-agent-result-applier.php';
require_once WPMU_ML_PLUGIN_DIR . 'includes/engines/agent/class-wpmu-ml-agent-tools.php';
require_once WPMU_ML_PLUGIN_DIR . 'includes/engines/agent/class-wpmu-ml-agent.php';
require_once WPMU_ML_PLUGIN_DIR . 'includes/cli/class-wpmu-ml-cli.php';
require_once WPMU_ML_PLUGIN_DIR . 'includes/core/class-wpmu-ml-core.php';
