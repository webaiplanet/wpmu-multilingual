<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Removes structural artifacts injected by browser/page translation tools.
 *
 * Cleanup is signature-based and deliberately conservative. It removes known
 * extension/browser attributes and wrapper elements, but keeps ordinary site
 * markup and legitimate site-authored .notranslate/.skiptranslate regions.
 */
final class WPMU_ML_Content_Sanitizer {
    private const VOID_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * Backward-compatible entry point retained for older callers/tests.
     *
     * @param array<string,int>|null $stats
     */
    public static function strip_immersive_translate_artifacts(string $html, ?array &$stats = null): string {
        return self::strip_translation_artifacts($html, $stats);
    }

    /**
     * Strip known translation/clipboard pollution from an HTML fragment.
     *
     * Supported signatures include:
     * - Immersive Translate data/classes/wrapper spans
     * - Chrome/Google Translate vertical-align font wrappers and UI nodes
     * - Firefox/Bergamot x-bergamot attributes and font wrappers
     * - Microsoft Edge _mst* attributes
     * - conservative DeepL/other translator data/class prefixes
     * - clipboard StartFragment/EndFragment comments
     *
     * @param array<string,int>|null $stats
     */
    public static function strip_translation_artifacts(string $html, ?array &$stats = null): string {
        $stats = [
            'attributes_removed' => 0,
            'class_tokens_removed' => 0,
            'notranslate_tokens_removed' => 0,
            'wrappers_unwrapped' => 0,
            'ui_nodes_removed' => 0,
            'fragment_markers_removed' => 0,
        ];

        if ($html === '' || !self::contains_known_artifact_marker($html)) {
            return $html;
        }

        $html = preg_replace_callback(
            '~<!--\s*(?:StartFragment|EndFragment)\s*-->~i',
            static function(array $m) use (&$stats): string {
                $stats['fragment_markers_removed']++;
                return '';
            },
            $html
        );
        if (!is_string($html) || $html === '') {
            return is_string($html) ? $html : '';
        }

        $tag_pattern = '~<!--.*?-->|<!\[CDATA\[.*?\]\]>|<\?.*?\?>|<![^>]*>|</?[A-Za-z][A-Za-z0-9:-]*(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~is';
        if (!preg_match_all($tag_pattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $out = '';
        $cursor = 0;
        $stack = [];

        foreach ($matches[0] as $match) {
            $token = (string)$match[0];
            $offset = (int)$match[1];
            $dropping_before = self::stack_is_dropping($stack);

            if (!$dropping_before) {
                $out .= substr($html, $cursor, $offset - $cursor);
            }
            $cursor = $offset + strlen($token);

            if (strncmp($token, '<!--', 4) === 0 || strncmp($token, '<!', 2) === 0 || strncmp($token, '<?', 2) === 0) {
                if (!$dropping_before) {
                    $out .= $token;
                }
                continue;
            }

            if (preg_match('~^</\s*([A-Za-z][A-Za-z0-9:-]*)~', $token, $m)) {
                $tag = strtolower((string)$m[1]);
                $matched_index = -1;
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if (($stack[$i]['tag'] ?? '') === $tag) {
                        $matched_index = $i;
                        break;
                    }
                }

                if ($matched_index < 0) {
                    if (!$dropping_before) {
                        $out .= $token;
                    }
                    continue;
                }

                $entry = $stack[$matched_index];
                $stack = array_slice($stack, 0, $matched_index);
                if (!$dropping_before && ($entry['mode'] ?? 'normal') === 'normal') {
                    $out .= $token;
                }
                continue;
            }

            if (!preg_match('~^<\s*([A-Za-z][A-Za-z0-9:-]*)(.*?)(/?)>$~s', $token, $m)) {
                if (!$dropping_before) {
                    $out .= $token;
                }
                continue;
            }

            $tag = strtolower((string)$m[1]);
            $attrs = (string)$m[2];
            $self_closing = trim((string)$m[3]) === '/' || in_array($tag, self::VOID_TAGS, true);

            $class_tokens = self::extract_class_tokens($attrs);
            $has_artifact_class = false;
            foreach ($class_tokens as $class_token) {
                if (self::is_artifact_class_token($class_token)) {
                    $has_artifact_class = true;
                    break;
                }
            }
            $has_artifact_attribute = self::has_artifact_attribute($attrs);
            $google_font_wrapper = self::is_google_translate_font_wrapper($tag, $attrs);
            $extension_owned = $has_artifact_class || $has_artifact_attribute || $google_font_wrapper;
            $drop_node = !$dropping_before && self::is_injected_translation_ui_node($tag, $attrs, $class_tokens);
            $unwrap = !$drop_node
                && in_array($tag, ['span', 'font'], true)
                && $extension_owned;

            if ($drop_node) {
                $stats['ui_nodes_removed']++;
            } elseif (!$dropping_before) {
                if ($unwrap) {
                    $stats['wrappers_unwrapped']++;
                    // Count stripped attributes/classes on wrappers even though the
                    // wrapper tag itself is omitted from output.
                    self::sanitize_opening_tag($token, true, $stats);
                } else {
                    $out .= self::sanitize_opening_tag($token, $extension_owned, $stats);
                }
            }

            if (!$self_closing) {
                $stack[] = [
                    'tag' => $tag,
                    'mode' => $drop_node ? 'drop' : ($unwrap ? 'unwrap' : 'normal'),
                ];
            }
        }

        if (!self::stack_is_dropping($stack)) {
            $out .= substr($html, $cursor);
        }
        return $out;
    }

    private static function contains_known_artifact_marker(string $html): bool {
        foreach ([
            'immersive-translate',
            'vertical-align: inherit',
            'x-bergamot-',
            'x-fxtranslations-',
            '_mst',
            'translated-ltr',
            'translated-rtl',
            'goog-te-',
            'goog-gt-',
            'VIpgJd-',
            'data-deepl-',
            'deepl-translate-',
            'deepl-translator-',
            'microsoft-translator-',
            'edge-translate-',
            'bing-translate-',
            'mate-translate-',
            'lingvanex-translate-',
            'data-google-translate-',
            'data-google-translation-',
            'data-bing-translate-',
            'data-microsoft-translator-',
            'data-edge-translate-',
            'data-mate-translate-',
            'data-lingvanex-translate-',
            '<!--StartFragment-->',
            '<!--EndFragment-->',
        ] as $needle) {
            if (stripos($html, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,array{tag:string,mode:string}> $stack
     */
    private static function stack_is_dropping(array $stack): bool {
        foreach ($stack as $entry) {
            if (($entry['mode'] ?? '') === 'drop') {
                return true;
            }
        }
        return false;
    }

    /**
     * @return string[]
     */
    private static function extract_class_tokens(string $attrs): array {
        if (!preg_match('~\bclass\s*=\s*(["\'])(.*?)\1~is', $attrs, $m)) {
            return [];
        }
        $tokens = preg_split('/\s+/u', trim((string)$m[2]));
        return array_values(array_filter((array)$tokens, static function($value): bool {
            return (string)$value !== '';
        }));
    }

    private static function is_artifact_class_token(string $class_name): bool {
        $class_name = trim($class_name);
        if ($class_name === '') {
            return false;
        }
        $lower = strtolower($class_name);
        if (in_array($lower, ['translated-ltr', 'translated-rtl'], true)) {
            return true;
        }
        foreach ([
            'immersive-translate-',
            'x-fxtranslations-',
            'goog-te-',
            'google-translate-',
            'deepl-translate-',
            'deepl-translator-',
            'microsoft-translator-',
            'edge-translate-',
            'bing-translate-',
            'mate-translate-',
            'lingvanex-translate-',
            'vipgjd-',
        ] as $prefix) {
            if (strpos($lower, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    private static function has_artifact_attribute(string $attrs): bool {
        return (bool)preg_match(
            '/(?:\bdata-(?:immersive-translate|deepl|google-translate|google-translation|bing-translate|microsoft-translator|edge-translate|mate-translate|lingvanex-translate)-[A-Za-z0-9_.:-]+|\bx-bergamot-[A-Za-z0-9_.:-]+|\b_mst[A-Za-z0-9_.:-]*)/i',
            $attrs
        );
    }

    private static function is_google_translate_font_wrapper(string $tag, string $attrs): bool {
        if ($tag !== 'font') {
            return false;
        }
        if (!preg_match('~\bstyle\s*=\s*(["\'])(.*?)\1~is', $attrs, $m)) {
            return false;
        }
        $style = strtolower((string)$m[2]);
        $style = preg_replace('/\s+/', '', $style);
        $style = trim((string)$style, ';');
        if ($style !== 'vertical-align:inherit') {
            return false;
        }

        // Keep a deliberately narrow signature: Google-created wrappers normally
        // carry only this style (optionally lang/dir), not site classes or IDs.
        return !preg_match('/\b(?:class|id)\s*=/i', $attrs);
    }

    /**
     * @param string[] $class_tokens
     */
    private static function is_injected_translation_ui_node(string $tag, string $attrs, array $class_tokens): bool {
        if (preg_match('~\bid\s*=\s*(["\'])(?:goog-gt-|goog-te-)[^"\']*\1~i', $attrs)) {
            return true;
        }
        if ($tag === 'iframe' && preg_match('~\bsrc\s*=\s*(["\'])[^"\']*(?:translate\.google|googleusercontent\.com/translate)[^"\']*\1~i', $attrs)) {
            return true;
        }

        $has_google_ui_class = false;
        $has_skiptranslate = false;
        foreach ($class_tokens as $class_name) {
            $lower = strtolower((string)$class_name);
            if (strpos($lower, 'vipgjd-') === 0 || strpos($lower, 'goog-te-') === 0) {
                $has_google_ui_class = true;
            }
            if ($lower === 'skiptranslate') {
                $has_skiptranslate = true;
            }
        }
        return $has_google_ui_class && ($has_skiptranslate || in_array($tag, ['div', 'iframe'], true));
    }

    /**
     * @param array<string,int> $stats
     */
    private static function sanitize_opening_tag(string $token, bool $extension_owned_element, array &$stats): string {
        $token = preg_replace_callback(
            '~\s+(?:data-(?:immersive-translate|deepl|google-translate|google-translation|bing-translate|microsoft-translator|edge-translate|mate-translate|lingvanex-translate)-[A-Za-z0-9_.:-]+|x-bergamot-[A-Za-z0-9_.:-]+|_mst[A-Za-z0-9_.:-]*)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+))?~i',
            static function(array $m) use (&$stats): string {
                $stats['attributes_removed']++;
                return '';
            },
            $token
        );

        $token = preg_replace_callback(
            '~(\s+)class(\s*=\s*)(["\'])(.*?)\3~is',
            static function(array $m) use ($extension_owned_element, &$stats): string {
                $classes = preg_split('/\s+/u', trim((string)$m[4]));
                $kept = [];
                foreach ((array)$classes as $class_name) {
                    $class_name = (string)$class_name;
                    if ($class_name === '') {
                        continue;
                    }
                    $lower = strtolower($class_name);
                    if (self::is_artifact_class_token($class_name)) {
                        $stats['class_tokens_removed']++;
                        continue;
                    }
                    if ($extension_owned_element && in_array($lower, ['notranslate', 'skiptranslate', 'no-translate'], true)) {
                        $stats['notranslate_tokens_removed']++;
                        continue;
                    }
                    $kept[] = $class_name;
                }

                if (!$kept) {
                    return '';
                }

                return (string)$m[1] . 'class' . (string)$m[2] . (string)$m[3] . implode(' ', $kept) . (string)$m[3];
            },
            (string)$token
        );

        if ($extension_owned_element) {
            $token = preg_replace_callback(
                '~\s+translate\s*=\s*(["\'])no\1~i',
                static function(array $m) use (&$stats): string {
                    $stats['attributes_removed']++;
                    return '';
                },
                (string)$token
            );
        }

        return is_string($token) ? $token : '';
    }
}
