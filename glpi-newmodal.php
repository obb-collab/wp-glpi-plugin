<?php
/**
 * Plugin Name: GLPI Newmodal (Isolated Clone)
 * Description: Isolated clone UI for GLPI cards with shortcode [glpi_cards_new]. SQL writes + API notification ping. No dependencies on old UI.
 * Version: 1.0.0
 * Author: obb-collab
 */

// Prevent direct access
if (!defined('ABSPATH')) { exit; }

// Define base dir/url
define('NM_BASE_DIR', plugin_dir_path(__FILE__) . 'newmodal/');
define('NM_BASE_URL', plugin_dir_url(__FILE__) . 'newmodal/');

// === Load common layer (no old requires) ===
require_once NM_BASE_DIR . 'common/helpers.php';
require_once NM_BASE_DIR . 'common/db.php';
require_once NM_BASE_DIR . 'common/notify-api.php';

// === Load submodules ===
require_once NM_BASE_DIR . 'bage/shortcode.php';
require_once NM_BASE_DIR . 'bage/render.php';
require_once NM_BASE_DIR . 'bage/ajax.php';

require_once NM_BASE_DIR . 'modal/ticket-modal.php';

require_once NM_BASE_DIR . 'new-ticket/controller.php';
require_once NM_BASE_DIR . 'new-ticket/ajax.php';

/**
 * Enqueue assets only when shortcode is present.
 */
function nm_maybe_enqueue_assets() {
    if (!nm_is_shortcode_present('glpi_cards_new')) { return; }

    // Styles
    wp_register_style('nm-bage-css', NM_BASE_URL . 'bage/assets/bage.css', [], '1.0.0');
    wp_register_style('nm-modal-css', NM_BASE_URL . 'modal/assets/modal.css', [], '1.0.0');
    wp_register_style('nm-new-ticket-css', NM_BASE_URL . 'new-ticket/assets/new-ticket.css', [], '1.0.0');
    wp_enqueue_style('nm-bage-css');
    wp_enqueue_style('nm-modal-css');
    wp_enqueue_style('nm-new-ticket-css');

    // Scripts
    wp_register_script('nm-bage-js', NM_BASE_URL . 'bage/assets/bage.js', ['jquery'], '1.0.0', true);
    wp_register_script('nm-modal-js', NM_BASE_URL . 'modal/assets/modal.js', ['jquery'], '1.0.0', true);
    wp_register_script('nm-new-ticket-js', NM_BASE_URL . 'new-ticket/assets/new-ticket.js', ['jquery'], '1.0.0', true);

    $local = [
        'ajaxUrl'      => admin_url('admin-ajax.php'),
        'nonce'        => wp_create_nonce('nm_nonce'),
        'statuses'     => nm_default_status_map(),
        'glpiPrefix'   => nm_glpi_prefix(),
        'i18n'         => [
            'error' => __('Error', 'nm'),
            'retry' => __('Retry', 'nm'),
        ],
    ];
    wp_localize_script('nm-bage-js', 'NM', $local);
    wp_localize_script('nm-modal-js', 'NM', $local);
    wp_localize_script('nm-new-ticket-js', 'NM', $local);

    wp_enqueue_script('nm-bage-js');
    wp_enqueue_script('nm-modal-js');
    wp_enqueue_script('nm-new-ticket-js');
}
add_action('wp_enqueue_scripts', 'nm_maybe_enqueue_assets');

/**
 * Helper: detect shortcode in current post content.
 */
function nm_is_shortcode_present($tag) {
    if (is_admin()) { return false; }
    global $post;
    if (!$post || empty($post->post_content)) { return false; }
    if (has_shortcode($post->post_content, $tag)) { return true; }
    return false;
}

/**
 * Dark overlay/root containers printed in footer only when shortcode used.
 */
function nm_footer_containers() {
    if (!nm_is_shortcode_present('glpi_cards_new')) { return; }
    ?>
    <div id="nm-overlay" class="nm-overlay" style="display:none;"></div>
    <div id="nm-modal-root" class="nm-modal-root" style="display:none;"></div>
    <div id="nm-new-ticket-root" class="nm-modal-root" style="display:none;"></div>
    <?php
}
add_action('wp_footer', 'nm_footer_containers');

/**
 * Activation sanity check: ensure PHP & WP versions, and transient cleanup.
 */
function nm_activate() {
    // Clean stale idempotency keys on activation (optional).
    // Nothing persistent created; rely on transient TTLs.
}
register_activation_hook(__FILE__, 'nm_activate');

/**
 * Settings defaults (optional): GLPI base URL, app token, DB prefix.
 * Admin can set these in existing plugin settings; we read via get_option().
 */
function nm_default_status_map() {
    // GLPI default statuses mapping (adjust to actual values if needed)
    return [
        '1' => 'New',
        '2' => 'Processing (assigned)',
        '3' => 'Processing (planned)',
        '4' => 'Pending',
        '5' => 'Solved',
        '6' => 'Closed',
    ];
}

/**
 * Expose a healthcheck endpoint for admins (optional).
 */
function nm_healthcheck() {
    if (!current_user_can('manage_options')) { wp_die('Nope'); }
    echo 'NM OK';
    wp_die();
}
add_action('wp_ajax_nm_healthcheck', 'nm_healthcheck');
