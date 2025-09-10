<?php
// newmodal/new-ticket/controller.php
if (!defined('ABSPATH')) { exit; }

/**
 * Return modal HTML for "New ticket" (server-rendered snippet)
 */
function nm_ajax_new_ticket_form() {
    nm_require_logged_in();
    nm_check_nonce_or_fail();
    ob_start();
    include NM_BASE_DIR . 'new-ticket/tpl/new-ticket.php';
    $html = ob_get_clean();
    echo $html;
    wp_die();
}
add_action('wp_ajax_nm_new_ticket_form', 'nm_ajax_new_ticket_form');
