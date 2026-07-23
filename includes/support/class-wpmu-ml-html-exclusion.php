<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Small, preservation-first HTML exclusion helper.
 *
 * It intentionally supports only simple selectors that can be matched without
 * reserializing the article HTML:
 *   tag
 *   .class / .class-a.class-b
 *   #id
 *   tag.class / tag#id / tag.class#id
 *   [attr] / [attr="value"]
 *   tag[attr] / tag.class[attr="value"]
 *
 * Combinators, pseudo selectors, wildcards and selector groups are rejected.
 */
final class WPMU_ML_HTML_Exclusion {
    private const VOID_TAGS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    public static function normalize_selector_list(string $raw): array {
        $items = self::split_selector_list($raw);
        $out = [];

        foreach ($items as $item) {
            $selector = self::normalize_single_selector((string)$item);
            if ($selector !== '') {
                $out[] = $selector;
            }
        }

        return array_values(array_unique($out));
    }

    public static function extract_tag_names(array $selectors): array {
        $out = [];
        foreach ($selectors as $selector) {
            $rule = self::parse_selector((string)$selector);
            if (!$rule || empty($rule['tag'])) {
                continue;
            }
            // A bare tag selector controls legacy code/pre behavior. Selectors such as
            // div.notice must not make every div excluded.
            if (empty($rule['classes']) && empty($rule['id']) && empty($rule['attributes'])) {
                $out[] = $rule['tag'];
            }
        }
        return array_values(array_unique($out));
    }

    public static function replace_selected_regions(string $html, array $selectors, callable $replacement): string {
        if ($html === '' || !$selectors) {
            return $html;
        }

        $ranges = self::find_selected_ranges($html, $selectors);
        if (!$ranges) {
            return $html;
        }

        // Work backwards so byte offsets stay valid and the original HTML outside the
        // selected element remains byte-for-byte unchanged.
        usort($ranges, static function(array $a, array $b): int {
            return $b['start'] <=> $a['start'];
        });

        foreach ($ranges as $range) {
            $start = (int)$range['start'];
            $length = (int)$range['length'];
            $region = substr($html, $start, $length);
            $html = substr($html, 0, $start) . (string)$replacement($region, $range) . substr($html, $start + $length);
        }

        return $html;
    }

    public static function remove_selected_regions(string $html, array $selectors): string {
        return self::replace_selected_regions($html, $selectors, static function(): string {
            return ' ';
        });
    }

    private static function split_selector_list(string $raw): array {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $items = [];
        $buffer = '';
        $quote = '';
        $bracket_depth = 0;
        $length = strlen($raw);

        for ($i = 0; $i < $length; $i++) {
            $char = $raw[$i];
            if ($quote !== '') {
                $buffer .= $char;
                if ($char === $quote && ($i === 0 || $raw[$i - 1] !== '\\')) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === '[') {
                $bracket_depth++;
                $buffer .= $char;
                continue;
            }
            if ($char === ']') {
                $bracket_depth = max(0, $bracket_depth - 1);
                $buffer .= $char;
                continue;
            }
            if (($char === "\n" || $char === ',') && $bracket_depth === 0) {
                if (trim($buffer) !== '') {
                    $items[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $items[] = trim($buffer);
        }

        return $items;
    }

    private static function normalize_single_selector(string $selector): string {
        $selector = trim(html_entity_decode($selector, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($selector === '') {
            return '';
        }

        // Friendly aliases for values administrators commonly paste from HTML.
        if (preg_match('~^</?\s*([a-z][a-z0-9:-]*)\s*>$~i', $selector, $m)) {
            $selector = strtolower($m[1]);
        } elseif (preg_match('~^class\s*=\s*(["\'])(.*?)\1$~is', $selector, $m)) {
            $classes = preg_split('/\s+/u', trim((string)$m[2]));
            $classes = array_values(array_filter((array)$classes, static function($v): bool {
                return (bool)preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', (string)$v);
            }));
            $selector = $classes ? '.' . implode('.', $classes) : '';
        } elseif (preg_match('~^class\s*=\s*([^\s]+)$~i', $selector, $m)) {
            $class = trim((string)$m[1], "\"'");
            $selector = preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $class) ? '.' . $class : '';
        } elseif (preg_match('~^id\s*=\s*(["\']?)([A-Za-z_][A-Za-z0-9_-]*)\1$~i', $selector, $m)) {
            $selector = '#' . $m[2];
        } elseif (preg_match('~^(data-[A-Za-z0-9_.:-]+)$~', $selector, $m)) {
            $selector = '[' . $m[1] . ']';
        } elseif (preg_match('~^(translate\s*=\s*["\']?no["\']?)$~i', $selector)) {
            $selector = '[translate="no"]';
        }

        $selector = trim($selector);
        if ($selector === '' || preg_match('/[>+~]|::?|\*/', $selector)) {
            return '';
        }

        $rule = self::parse_selector($selector);
        if (!$rule) {
            return '';
        }

        return self::serialize_rule($rule);
    }

    private static function parse_selector(string $selector): ?array {
        $selector = trim($selector);
        if ($selector === '' || preg_match('/\s(?![^\[]*\])/', $selector)) {
            return null;
        }

        $rule = [
            'tag' => '',
            'id' => '',
            'classes' => [],
            'attributes' => [],
        ];

        if (preg_match('/^([A-Za-z][A-Za-z0-9:-]*)/', $selector, $m)) {
            $rule['tag'] = strtolower($m[1]);
            $selector = substr($selector, strlen($m[0]));
        }

        while ($selector !== '') {
            if (preg_match('/^\.([A-Za-z_][A-Za-z0-9_-]*)/', $selector, $m)) {
                $rule['classes'][] = $m[1];
                $selector = substr($selector, strlen($m[0]));
                continue;
            }
            if (preg_match('/^#([A-Za-z_][A-Za-z0-9_-]*)/', $selector, $m)) {
                if ($rule['id'] !== '') {
                    return null;
                }
                $rule['id'] = $m[1];
                $selector = substr($selector, strlen($m[0]));
                continue;
            }
            if (preg_match('/^\[((?:[^\]"\']+|"[^"]*"|\'[^\']*\')+)\]/', $selector, $m)) {
                $condition = self::parse_attribute_condition((string)$m[1]);
                if (!$condition) {
                    return null;
                }
                $rule['attributes'][] = $condition;
                $selector = substr($selector, strlen($m[0]));
                continue;
            }
            return null;
        }

        $rule['classes'] = array_values(array_unique($rule['classes']));
        if ($rule['tag'] === '' && $rule['id'] === '' && !$rule['classes'] && !$rule['attributes']) {
            return null;
        }
        return $rule;
    }

    private static function parse_attribute_condition(string $condition): ?array {
        $condition = trim($condition);
        if (!preg_match('/^([A-Za-z_:][A-Za-z0-9_.:-]*)(?:\s*(=|\^=|\$=|\*=|~=|\|=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s]+)))?$/', $condition, $m)) {
            return null;
        }
        $value = '';
        if (array_key_exists(3, $m) && $m[3] !== '') {
            $value = $m[3];
        } elseif (array_key_exists(4, $m) && $m[4] !== '') {
            $value = $m[4];
        } elseif (array_key_exists(5, $m) && $m[5] !== '') {
            $value = $m[5];
        }
        return [
            'name' => strtolower($m[1]),
            'operator' => $m[2] ?? '',
            'value' => $value,
        ];
    }

    private static function serialize_rule(array $rule): string {
        $selector = (string)$rule['tag'];
        if ($rule['id'] !== '') {
            $selector .= '#' . $rule['id'];
        }
        foreach ((array)$rule['classes'] as $class) {
            $selector .= '.' . $class;
        }
        foreach ((array)$rule['attributes'] as $attribute) {
            $selector .= '[' . $attribute['name'];
            if ($attribute['operator'] !== '') {
                $value = str_replace(['\\', '"'], ['\\\\', '\\"'], (string)$attribute['value']);
                $selector .= $attribute['operator'] . '"' . $value . '"';
            }
            $selector .= ']';
        }
        return $selector;
    }

    private static function find_selected_ranges(string $html, array $selectors): array {
        $rules = [];
        foreach ($selectors as $selector) {
            $rule = self::parse_selector((string)$selector);
            if ($rule) {
                $rules[] = $rule;
            }
        }
        if (!$rules) {
            return [];
        }

        $tag_pattern = '~<!--.*?-->|<!\[CDATA\[.*?\]\]>|<\?.*?\?>|<![^>]*>|</?[A-Za-z][A-Za-z0-9:-]*(?:"[^"]*"|\'[^\']*\'|[^\'">])*>~is';
        if (!preg_match_all($tag_pattern, $html, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $stack = [];
        $ranges = [];
        foreach ($matches[0] as $match) {
            $token = (string)$match[0];
            $offset = (int)$match[1];
            if (strncmp($token, '<!--', 4) === 0 || strncmp($token, '<!', 2) === 0 || strncmp($token, '<?', 2) === 0) {
                continue;
            }

            if (preg_match('~^</\s*([A-Za-z][A-Za-z0-9:-]*)~', $token, $m)) {
                $tag = strtolower($m[1]);
                for ($i = count($stack) - 1; $i >= 0; $i--) {
                    if ($stack[$i]['tag'] !== $tag) {
                        continue;
                    }
                    $entry = $stack[$i];
                    $stack = array_slice($stack, 0, $i);
                    if (!empty($entry['matched'])) {
                        $ranges[] = [
                            'start' => (int)$entry['start'],
                            'length' => ($offset + strlen($token)) - (int)$entry['start'],
                            'selector' => (string)$entry['selector'],
                            'tag' => $tag,
                        ];
                    }
                    break;
                }
                continue;
            }

            if (!preg_match('~^<\s*([A-Za-z][A-Za-z0-9:-]*)(.*?)(/?)>$~s', $token, $m)) {
                continue;
            }
            $tag = strtolower($m[1]);
            $attrs = self::parse_html_attributes((string)$m[2]);
            $matched_selector = '';
            foreach ($rules as $index => $rule) {
                if (self::opening_tag_matches_rule($tag, $attrs, $rule)) {
                    $matched_selector = (string)$selectors[$index];
                    break;
                }
            }

            $self_closing = trim((string)$m[3]) === '/' || in_array($tag, self::VOID_TAGS, true);
            if ($self_closing) {
                if ($matched_selector !== '') {
                    $ranges[] = [
                        'start' => $offset,
                        'length' => strlen($token),
                        'selector' => $matched_selector,
                        'tag' => $tag,
                    ];
                }
                continue;
            }

            $stack[] = [
                'tag' => $tag,
                'start' => $offset,
                'matched' => $matched_selector !== '',
                'selector' => $matched_selector,
            ];
        }

        if (!$ranges) {
            return [];
        }

        usort($ranges, static function(array $a, array $b): int {
            if ($a['start'] === $b['start']) {
                return $b['length'] <=> $a['length'];
            }
            return $a['start'] <=> $b['start'];
        });

        $merged = [];
        foreach ($ranges as $range) {
            $start = (int)$range['start'];
            $end = $start + (int)$range['length'];
            if (!$merged) {
                $merged[] = $range;
                continue;
            }
            $last_index = count($merged) - 1;
            $last_start = (int)$merged[$last_index]['start'];
            $last_end = $last_start + (int)$merged[$last_index]['length'];
            if ($start >= $last_start && $end <= $last_end) {
                continue;
            }
            if ($start < $last_end) {
                $merged[$last_index]['length'] = max($last_end, $end) - $last_start;
                continue;
            }
            $merged[] = $range;
        }

        return $merged;
    }

    private static function parse_html_attributes(string $raw): array {
        $attributes = [];
        if (!preg_match_all('/([A-Za-z_:][A-Za-z0-9_.:-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $raw, $matches, PREG_SET_ORDER)) {
            return $attributes;
        }
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            $value = '';
            if (isset($match[2]) && $match[2] !== '') {
                $value = $match[2];
            } elseif (isset($match[3]) && $match[3] !== '') {
                $value = $match[3];
            } elseif (isset($match[4]) && $match[4] !== '') {
                $value = $match[4];
            }
            $attributes[$name] = $value;
        }
        return $attributes;
    }

    private static function opening_tag_matches_rule(string $tag, array $attributes, array $rule): bool {
        if ($rule['tag'] !== '' && $rule['tag'] !== $tag) {
            return false;
        }
        if ($rule['id'] !== '' && (($attributes['id'] ?? '') !== $rule['id'])) {
            return false;
        }
        if ($rule['classes']) {
            $classes = preg_split('/\s+/u', trim((string)($attributes['class'] ?? '')));
            $class_map = array_fill_keys(array_filter((array)$classes, 'strlen'), true);
            foreach ($rule['classes'] as $class) {
                if (!isset($class_map[$class])) {
                    return false;
                }
            }
        }
        foreach ($rule['attributes'] as $condition) {
            $name = $condition['name'];
            if (!array_key_exists($name, $attributes)) {
                return false;
            }
            $actual = (string)$attributes[$name];
            $expected = (string)$condition['value'];
            switch ($condition['operator']) {
                case '':
                    break;
                case '=':
                    if ($actual !== $expected) return false;
                    break;
                case '^=':
                    if (strncmp($actual, $expected, strlen($expected)) !== 0) return false;
                    break;
                case '$=':
                    if ($expected !== '' && substr($actual, -strlen($expected)) !== $expected) return false;
                    break;
                case '*=':
                    if (strpos($actual, $expected) === false) return false;
                    break;
                case '~=':
                    $tokens = preg_split('/\s+/u', trim($actual));
                    if (!in_array($expected, (array)$tokens, true)) return false;
                    break;
                case '|=':
                    if ($actual !== $expected && strncmp($actual, $expected . '-', strlen($expected) + 1) !== 0) return false;
                    break;
                default:
                    return false;
            }
        }
        return true;
    }
}
