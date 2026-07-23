<?php
if (!defined('ABSPATH')) {
    exit(1);
}

global $wpdb;

$core = WPMU_Multilingual::instance();
$option = WPMU_Multilingual::OPTION;
$original = get_site_option($option);
$settings = is_array($original) ? $original : $core->get_settings();
$source_blog_id = absint($settings['source_blog_id'] ?? 0);

if (!$source_blog_id) {
    throw new RuntimeException('source_blog_id is not configured.');
}

$source_site = $wpdb->get_row($wpdb->prepare(
    "SELECT blog_id, lang_slug, locale, hreflang FROM {$wpdb->base_prefix}wpmu_ml_sites WHERE blog_id = %d LIMIT 1",
    $source_blog_id
), ARRAY_A);
if (!$source_site) {
    throw new RuntimeException('source site row is not available.');
}

$english_site = $wpdb->get_row(
    "SELECT blog_id, lang_slug, locale, hreflang FROM {$wpdb->base_prefix}wpmu_ml_sites
     WHERE LOWER(lang_slug) = 'en' OR LOWER(locale) LIKE 'en%'
     ORDER BY blog_id ASC LIMIT 1",
    ARRAY_A
);
if (!$english_site) {
    throw new RuntimeException('an English site is required for non-Chinese source routing smoke.');
}

$resolve = new ReflectionMethod($core, 'resolve_translation_route');
$resolve->setAccessible(true);
$engines_for_lang = new ReflectionMethod($core, 'get_translation_engines_for_lang');
$engines_for_lang->setAccessible(true);

try {
    $current_route = $resolve->invoke($core, 'zh-hant', 'post');
    $current_engines = $engines_for_lang->invoke($core, 'zh-hant');

    if (sanitize_key((string)($source_site['lang_slug'] ?? '')) === 'zh-hans') {
        if (($current_route['engine'] ?? '') !== 'opencc_s2twp' && empty($current_engines['opencc_s2twp'])) {
            throw new RuntimeException('simplified Chinese source should offer OpenCC for zh-hant target.');
        }
    }

    $fake = $settings;
    $fake['source_blog_id'] = absint($english_site['blog_id']);
    update_site_option($option, $fake);

    $english_source_route = $resolve->invoke($core, 'zh-hant', 'post');
    $english_source_engines = $engines_for_lang->invoke($core, 'zh-hant');

    if (strpos((string)($english_source_route['engine'] ?? ''), 'opencc_') === 0) {
        throw new RuntimeException('non-Chinese source must not route zh-hant target to OpenCC.');
    }
    if (isset($english_source_engines['opencc_s2twp'])) {
        throw new RuntimeException('non-Chinese source must not show OpenCC engines for zh-hant target.');
    }

    echo wp_json_encode([
        'ok' => true,
        'current_source_blog_id' => $source_blog_id,
        'current_source_lang' => $source_site['lang_slug'] ?? '',
        'current_route' => $current_route,
        'english_source_blog_id' => absint($english_site['blog_id']),
        'english_source_route' => $english_source_route,
        'english_source_has_opencc' => isset($english_source_engines['opencc_s2twp']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
} finally {
    update_site_option($option, $original);
}
