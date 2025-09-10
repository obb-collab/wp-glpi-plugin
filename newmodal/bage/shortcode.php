<?php
if ( ! defined('ABSPATH') ) { exit; }

// Гарантируем, что базовые константы заданы
if ( ! defined('NM_BASE_DIR') ) {
    define('NM_BASE_DIR', trailingslashit(plugin_dir_path(dirname(__FILE__))));
}
if ( ! defined('NM_BASE_URL') ) {
    define('NM_BASE_URL', trailingslashit(plugin_dir_url(dirname(__FILE__))));
}
if ( ! defined('NM_VER') ) {
    define('NM_VER', '1.1.1');
}

/**
 * Шорткод [glpi_cards_new]: рендер страницы карточек.
 * Внутри подключаем только наш шаблон и ничего из старого плагина.
 */
function nm_shortcode_glpi_cards_new($atts = [], $content = '') {
    $tpl = NM_BASE_DIR . 'bage/tpl/cards.php';
    if ( ! file_exists($tpl) ) {
        return '<div class="nm-error">Template not found: <code>bage/tpl/cards.php</code></div>';
    }
    ob_start();
    // Шаблон может рассчитывать на то, что ассеты уже зарегистрированы/локализованы извне
    include $tpl;
    return ob_get_clean();
}
add_shortcode('glpi_cards_new', 'nm_shortcode_glpi_cards_new');

/**
 * AJAX: list, counts, single card
 */
// Хуки регистрируются в корневом файле плагина (glpi-newmodal-addon.php).
// Здесь оставляем только заглушки функций, если они не подтянулись,
// чтобы не словить фатал при вызове несуществующих обработчиков.
if ( ! function_exists('nm_ajax_get_cards') ) {
    function nm_ajax_get_cards() {
        wp_send_json_error(['message' => 'Handler nm_ajax_get_cards is missing.'], 500);
    }
}
if ( ! function_exists('nm_ajax_get_counts') ) {
    function nm_ajax_get_counts() {
        wp_send_json_error(['message' => 'Handler nm_ajax_get_counts is missing.'], 500);
    }
}
if ( ! function_exists('nm_ajax_get_card') ) {
    function nm_ajax_get_card() {
        wp_send_json_error(['message' => 'Handler nm_ajax_get_card is missing.'], 500);
    }
}

/**
 * Регистрация ассетов по требованию (на случай прямого рендера вне фильтра the_posts).
 */
add_action('wp', function () {
    if ( is_singular() && has_shortcode(get_post_field('post_content', get_queried_object_id()), 'glpi_cards_new') ) {
        if ( wp_style_is('nm-bage', 'registered') ) {
            wp_enqueue_style('nm-bage');
            wp_enqueue_style('nm-modal');
            wp_enqueue_style('nm-dark');
        }
        if ( wp_script_is('nm-bage', 'registered') ) {
            wp_enqueue_script('nm-bage');
            wp_enqueue_script('nm-new-ticket');
        }
    }
}, 11);

