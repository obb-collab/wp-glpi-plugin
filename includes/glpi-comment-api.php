<?php
if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-api.php';

// AJAX handler for sending comment via GLPI API
add_action('wp_ajax_glpi_send_comment_api', 'gexe_glpi_send_comment_api');
function gexe_glpi_send_comment_api() {
    if (!check_ajax_referer('gexe_form_data', 'nonce', false)) {
        wp_send_json(['ok' => false, 'code' => 'forbidden', 'detail' => 'Invalid nonce']);
    }
    $ticket_id = isset($_POST['ticket_id']) ? intval($_POST['ticket_id']) : 0;
    $comment   = isset($_POST['comment']) ? sanitize_textarea_field((string) $_POST['comment']) : '';
    if (!$ticket_id || $comment === '') {
        wp_send_json(['ok' => false, 'code' => 'invalid_params', 'detail' => 'Missing ticket_id or comment']);
    }
    $resp = gexe_glpi_api_request('POST', "/Ticket/$ticket_id/ITILFollowup", [
        'input' => [
            'content'    => $comment,
            'is_private' => 0,
        ]
    ]);
    if (is_wp_error($resp)) {
        wp_send_json(['ok' => false, 'code' => 'glpi_api', 'detail' => $resp->get_error_message()]);
    }
    $code = isset($resp['code']) ? (int) $resp['code'] : 0;
    $body = $resp['body'] ?? [];
    if ($code === 201 && isset($body['id'])) {
        wp_send_json(['ok' => true, 'id' => $body['id']]);
    }
    wp_send_json(['ok' => false, 'code' => 'glpi_api', 'detail' => wp_json_encode($body)]);
}
