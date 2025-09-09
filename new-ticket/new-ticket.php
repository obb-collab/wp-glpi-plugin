<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/inc/nt-db.php';
require_once __DIR__ . '/inc/nt-auth.php';
require_once __DIR__ . '/inc/nt-sql.php';
require_once __DIR__ . '/inc/nt-validate.php';
require_once __DIR__ . '/inc/nt-response.php';

function nt_bootstrap() {
    add_shortcode('glpi_new_ticket2', 'nt_render_shortcode');
}
add_action('init', 'nt_bootstrap');

function nt_register_assets() {
    wp_register_script('nt-new-ticket', plugin_dir_url(__FILE__) . 'assets/new-ticket.js', [], '1.0.0', true);
    wp_localize_script('nt-new-ticket', 'ntAjax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('new_ticket_actions'),
    ]);
    wp_register_style('nt-new-ticket', plugin_dir_url(__FILE__) . 'assets/new-ticket.css', [], '1.0.0');
}
add_action('wp_enqueue_scripts', 'nt_register_assets');

function nt_render_shortcode() {
    wp_enqueue_style('nt-new-ticket');
    wp_enqueue_script('nt-new-ticket');
    ob_start();
    include __DIR__ . '/partials/form.php';
    return ob_get_clean();
}

function nt_ajax_nopriv() {
    nt_response_error('auth', 'Auth required');
}
add_action('wp_ajax_nopriv_nt_get_categories', 'nt_ajax_nopriv');
add_action('wp_ajax_nopriv_nt_get_locations', 'nt_ajax_nopriv');
add_action('wp_ajax_nopriv_nt_get_assignees', 'nt_ajax_nopriv');
add_action('wp_ajax_nopriv_nt_create_ticket', 'nt_ajax_nopriv');

function nt_verify_ajax() {
    if (!check_ajax_referer('new_ticket_actions', 'nonce', false)) {
        nt_response_error('csrf', 'Invalid nonce');
    }
    nt_require_glpi_user();
}

add_action('wp_ajax_nt_get_categories', 'nt_ajax_get_categories');
function nt_ajax_get_categories() {
    nt_verify_ajax();
    $list = nt_sql_get_categories();
    nt_response(['ok' => true, 'list' => $list]);
}

add_action('wp_ajax_nt_get_locations', 'nt_ajax_get_locations');
function nt_ajax_get_locations() {
    nt_verify_ajax();
    $list = nt_sql_get_locations();
    nt_response(['ok' => true, 'list' => $list]);
}

add_action('wp_ajax_nt_get_assignees', 'nt_ajax_get_assignees');
function nt_ajax_get_assignees() {
    nt_verify_ajax();
    $list = nt_sql_get_assignees();
    nt_response(['ok' => true, 'list' => $list]);
}

add_action('wp_ajax_nt_create_ticket', 'nt_ajax_create_ticket');
function nt_ajax_create_ticket() {
    nt_verify_ajax();
    $input = nt_validate_ticket_input($_POST);
    if (is_wp_error($input)) {
        nt_response_error('validation', $input->get_error_message());
    }
    $uid = nt_get_current_glpi_uid();
    $assignee = $input['self_assign'] ? $uid : (int) $input['assignee_id'];
    $res = nt_sql_create_ticket($input['title'], $input['content'], $input['category_id'], $input['location_id'], $uid, $assignee);
    nt_response($res);
}
