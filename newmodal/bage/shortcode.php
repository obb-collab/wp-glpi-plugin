<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Register shortcode [glpi_cards_new]
 * Renders isolated cards page with own assets and markup only.
 */
function nm_shortcode_glpi_cards_new($atts = [], $content = '') {
    if (!is_user_logged_in()) {
        return '<div class="nm-auth-required">Требуется авторизация</div>';
    }
    // Enqueue isolated assets
    wp_enqueue_style('nm-bage');
    wp_enqueue_style('nm-modal');
    wp_enqueue_script('nm-bage');
    wp_enqueue_script('nm-modal');
    wp_enqueue_script('nm-new-ticket');

    ob_start();
    include NM_BASE_DIR . 'bage/tpl/cards.php';
    return ob_get_clean();
}
add_shortcode('glpi_cards_new', 'nm_shortcode_glpi_cards_new');
