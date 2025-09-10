<?php
// Minimal stubs for WordPress environment
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}
if (!defined('GEXE_USE_NEWMODAL')) {
    define('GEXE_USE_NEWMODAL', true);
}
if (!defined('GEXE_CARDS_TEMPLATE')) {
    define('GEXE_CARDS_TEMPLATE', __DIR__ . '/glpi_cards_test_template.php');
}

// Basic shortcode API implementation
$GLOBALS['shortcodes'] = [];
function add_shortcode($tag, $func) {
    $GLOBALS['shortcodes'][$tag] = $func;
}
function remove_shortcode($tag) {
    unset($GLOBALS['shortcodes'][$tag]);
}
function shortcode_exists($tag) {
    return isset($GLOBALS['shortcodes'][$tag]);
}
function do_shortcode($content) {
    return preg_replace_callback('/\[(\w+)]/', function ($m) {
        $tag = $m[1];
        return isset($GLOBALS['shortcodes'][$tag])
            ? call_user_func($GLOBALS['shortcodes'][$tag])
            : $m[0];
    }, $content);
}
function add_action($hook, $func, $prio = null) {
    if ($hook === 'init') {
        $func();
    }
}

// Escaping helpers
function esc_attr($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

require __DIR__ . '/../newmodal/bage/bage-loader.php';

$output = do_shortcode('[glpi_cards_new]');

if (strpos($output, 'gexe-modal_open') !== false) {
    fwrite(STDERR, "unsanitized class still present\n");
    exit(1);
}
if (strpos($output, 'data-open="comment"') !== false) {
    fwrite(STDERR, "unsanitized attribute still present\n");
    exit(1);
}
if (strpos($output, 'gexe-bage-scope') === false) {
    fwrite(STDERR, "scope wrapper missing\n");
    exit(1);
}

echo "OK\n";
