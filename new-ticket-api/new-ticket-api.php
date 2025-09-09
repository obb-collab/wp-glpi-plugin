<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/inc/nta-response.php';
require_once __DIR__ . '/inc/nta-auth.php';
require_once __DIR__ . '/inc/nta-db.php';
require_once __DIR__ . '/inc/nta-sql.php';
require_once __DIR__ . '/inc/nta-validate.php';
require_once __DIR__ . '/inc/nta-api.php';

function nta_bootstrap_api(){
    add_shortcode('glpi_new_ticket_api', 'nta_render_shortcode_api');
}
add_action('init', 'nta_bootstrap_api');

function nta_register_assets_api(){
    wp_register_script('nta-new-ticket', plugin_dir_url(__FILE__) . 'assets/new-ticket-api.js', [], '1.0.0', true);
    wp_localize_script('nta-new-ticket', 'ntaAjax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('new_ticket_api_actions'),
    ]);
    wp_register_style('nta-new-ticket', plugin_dir_url(__FILE__) . 'assets/new-ticket-api.css', [], '1.0.0');
}
add_action('wp_enqueue_scripts', 'nta_register_assets_api');

function nta_render_shortcode_api(){
    wp_enqueue_style('nta-new-ticket');
    wp_enqueue_script('nta-new-ticket');
    ob_start();
    include __DIR__ . '/partials/form.php';
    return ob_get_clean();
}

// nopriv -> auth required
function nta_ajax_nopriv_api(){ nta_response_error('auth','Auth required'); }
add_action('wp_ajax_nopriv_nta_get_categories', 'nta_ajax_nopriv_api');
add_action('wp_ajax_nopriv_nta_get_locations',  'nta_ajax_nopriv_api');
add_action('wp_ajax_nopriv_nta_get_assignees',  'nta_ajax_nopriv_api');
add_action('wp_ajax_nopriv_nta_create_ticket_api', 'nta_ajax_nopriv_api');

// dictionaries
add_action('wp_ajax_nta_get_categories', function(){
    nta_check_nonce_and_auth();
    $list = nta_sql_get_categories();
    nta_response(['ok'=>true,'list'=>$list]);
});
add_action('wp_ajax_nta_get_locations', function(){
    nta_check_nonce_and_auth();
    $list = nta_sql_get_locations();
    nta_response(['ok'=>true,'list'=>$list]);
});
add_action('wp_ajax_nta_get_assignees', function(){
    nta_check_nonce_and_auth();
    $list = nta_sql_get_assignees();
    nta_response(['ok'=>true,'list'=>$list]);
});

// create ticket via API
add_action('wp_ajax_nta_create_ticket_api', function(){
    nta_check_nonce_and_auth();
    $validated = nta_validate_ticket_input($_POST);
    if (is_wp_error($validated)) {
        nta_response_error('validation', $validated->get_error_message());
    }
    list($uid, $token) = nta_require_glpi_user_and_token();

    // anti-duplicate (SQL)
    $dup = nta_sql_find_duplicate($uid, $validated['title'], $validated['content']);
    if ($dup) {
        nta_response(['ok'=>true, 'code'=>'already_exists', 'ticket_id'=>$dup]);
    }

    $assignee = $validated['self_assign'] ? $uid : (int)$validated['assignee_id'];
    $res = nta_api_create_ticket($token, $validated, $uid, $assignee);
    if(!$res['ok']){
        $msg = $res['message'] ?? 'API error';
        nta_response_error($res['code'] ?? 'api_error', $msg);
    }
    nta_response(['ok'=>true, 'ticket_id'=>$res['ticket_id']]);
});
