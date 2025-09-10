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

// === Load common layer ===
require_once NM_BASE_DIR . 'config.php';
require_once NM_BASE_DIR . 'helpers.php';
require_once NM_BASE_DIR . 'common/api.php';
require_once NM_BASE_DIR . 'common/ping.php';

// === Load submodules ===
require_once NM_BASE_DIR . 'bage/shortcode.php';
require_once NM_BASE_DIR . 'bage/ajax.php';
require_once NM_BASE_DIR . 'bage/ajax-extra.php';

require_once NM_BASE_DIR . 'modal/ajax.php';

require_once NM_BASE_DIR . 'new-ticket/ajax.php';
require_once NM_BASE_DIR . 'new-ticket/catalogs.php';

/**
 * Enqueue assets only when shortcode is present.
 */
function nm_maybe_enqueue_assets() {
    if (!nm_is_shortcode_present('glpi_cards_new')) { return; }

    // Styles
    wp_register_style('nm-bage-css', NM_BASE_URL . 'assets/css/bage.css', [], '1.0.0');
    wp_register_style('nm-modal-css', NM_BASE_URL . 'assets/css/modal.css', [], '1.0.0');
    wp_register_style('nm-modal-extra-css', NM_BASE_URL . 'assets/css/modal-extra.css', [], '1.0.0');
    wp_register_style('nm-newticket-css', NM_BASE_URL . 'assets/css/newticket.css', [], '1.0.0');
    wp_enqueue_style('nm-bage-css');
    wp_enqueue_style('nm-modal-css');
    wp_enqueue_style('nm-modal-extra-css');
    wp_enqueue_style('nm-newticket-css');

    // Scripts
    wp_register_script('nm-common-js', NM_BASE_URL . 'assets/js/common.js', ['jquery'], '1.0.0', true);
    wp_register_script('nm-bage-js', NM_BASE_URL . 'assets/js/bage.js', ['jquery','nm-common-js'], '1.0.0', true);
    wp_register_script('nm-modal-js', NM_BASE_URL . 'assets/js/modal.js', ['jquery','nm-common-js'], '1.0.0', true);
    wp_register_script('nm-newticket-js', NM_BASE_URL . 'assets/js/newticket.js', ['jquery','nm-common-js'], '1.0.0', true);

    $local = [
        'ajaxUrl'    => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('nm_nonce'),
        'statuses'   => nm_default_status_map(),
        'glpiPrefix' => nm_glpi_prefix(),
        'i18n'       => [
            'error' => __('Error', 'nm'),
            'retry' => __('Retry', 'nm'),
        ],
    ];
    wp_localize_script('nm-common-js', 'NM', $local);

    wp_enqueue_script('nm-common-js');
    wp_enqueue_script('nm-bage-js');
    wp_enqueue_script('nm-modal-js');
    wp_enqueue_script('nm-newticket-js');
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
 * Expose a healthcheck endpoint for admins (optional).
 */
function nm_healthcheck() {
    if (!current_user_can('manage_options')) { wp_die('Nope'); }
    echo 'NM OK';
    wp_die();
}
add_action('wp_ajax_nm_healthcheck', 'nm_healthcheck');
