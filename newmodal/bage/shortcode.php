<?php
// newmodal/bage/shortcode.php
if (!defined('ABSPATH')) { exit; }

/**
 * Register shortcode [glpi_cards_new]
 */
function nm_shortcode_glpi_cards_new($atts = [], $content = '') {
    ob_start();
    include NM_BASE_DIR . 'bage/tpl/cards.php';
    return ob_get_clean();
}
add_shortcode('glpi_cards_new', 'nm_shortcode_glpi_cards_new');

/**
 * AJAX: list, counts, single card
 */
add_action('wp_ajax_nm_get_cards', 'nm_ajax_get_cards');
add_action('wp_ajax_nm_get_counts', 'nm_ajax_get_counts');
add_action('wp_ajax_nm_get_card', 'nm_ajax_get_card');
