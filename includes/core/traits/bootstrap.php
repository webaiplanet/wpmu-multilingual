<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WPMU_Multilingual 核心 trait 加载器。
 *
 * 顺序只用于提高可读性；各 trait 之间通过最终类的方法表协作。
 */
require_once __DIR__ . '/trait-wpmu-ml-core-foundation.php';
require_once __DIR__ . '/trait-wpmu-ml-core-admin-ui.php';
require_once __DIR__ . '/trait-wpmu-ml-core-engine-routing.php';
require_once __DIR__ . '/trait-wpmu-ml-core-queue.php';
require_once __DIR__ . '/trait-wpmu-ml-core-opencc.php';
require_once __DIR__ . '/trait-wpmu-ml-core-openai-translation.php';
require_once __DIR__ . '/trait-wpmu-ml-core-openai-content.php';
require_once __DIR__ . '/trait-wpmu-ml-core-openai-metadata.php';
require_once __DIR__ . '/trait-wpmu-ml-core-openai-quality.php';
require_once __DIR__ . '/trait-wpmu-ml-core-openai-client.php';
require_once __DIR__ . '/trait-wpmu-ml-core-admin-actions.php';
require_once __DIR__ . '/trait-wpmu-ml-core-incremental-sync.php';
require_once __DIR__ . '/trait-wpmu-ml-core-relation-safety.php';
require_once __DIR__ . '/trait-wpmu-ml-core-term-sync.php';
require_once __DIR__ . '/trait-wpmu-ml-core-sync.php';
require_once __DIR__ . '/trait-wpmu-ml-core-language-switcher.php';
