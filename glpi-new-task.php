<?php
/**
 * GLPI — создание новой заявки из WordPress UI.
 * Регистрирует AJAX:
 *  - gexe_get_form_data : выдаёт списки категорий, местоположений и исполнителей
 *  - gexe_create_ticket : создаёт заявку в glpi_tickets (+ заявители/исполнители)
 *
 * Также подключает CSS для окна создания заявки.
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/glpi-utils.php';
require_once __DIR__ . '/includes/glpi-form-data.php';

add_action('wp_enqueue_scripts', function () {
    // Стили окна создания заявки
    wp_register_style(
        'glpi-new-task',
        plugin_dir_url(__FILE__) . 'glpi-new-task.css',
        [],
        '1.0.0'
    );
    wp_enqueue_style('glpi-new-task');

    // Скрипт модального окна создания заявки
    wp_register_script(
        'glpi-new-task-js',
        plugin_dir_url(__FILE__) . 'glpi-new-task.js',
        [],
        '1.0.0',
        true
    );
    wp_enqueue_script('glpi-new-task-js');
    wp_localize_script('glpi-new-task-js', 'gexeAjax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('gexe_form_data'),
    ]);
});

// -------- AJAX: создание новой заявки --------
add_action('wp_ajax_gexe_create_ticket', 'gexe_create_ticket');
function gexe_create_ticket() {
    if (!check_ajax_referer('gexe_form_data', 'nonce', false)) {
        wp_send_json(['ok' => false, 'error' => 'AJAX_FORBIDDEN'], 403);
    }

    if (!is_user_logged_in()) {
        wp_send_json(['ok' => false, 'error' => 'not_logged_in']);
    }

    if (!current_user_can('create_glpi_ticket')) {
        wp_send_json(['ok' => false, 'error' => 'forbidden'], 403);
    }

    $payload_raw = isset($_POST['payload']) ? stripslashes((string)$_POST['payload']) : '';
    $payload = json_decode($payload_raw, true);
    if (!is_array($payload)) $payload = [];

    $name        = isset($payload['name'])        ? sanitize_text_field($payload['name']) : '';
    $content     = isset($payload['content'])     ? sanitize_textarea_field($payload['content']) : '';
    $cat_id      = isset($payload['category_id']) ? intval($payload['category_id']) : 0;
    $loc_id      = isset($payload['location_id']) ? intval($payload['location_id']) : 0;
    $assign_me   = !empty($payload['assign_me']);
    $assignee_id = isset($payload['assignee_id']) ? intval($payload['assignee_id']) : 0;

    if ($name === '' || $content === '') {
        wp_send_json(['ok' => false, 'error' => 'bad_request'], 400);
    }

    $glpi_uid = gexe_get_current_glpi_uid(); // может быть 0

    $input = [
        'name'             => $name,
        'content'          => $content,
        'itilcategories_id'=> $cat_id ?: 0,
        'locations_id'     => $loc_id ?: 0,
        'urgency'          => 3,
        'impact'           => 3,
        'priority'         => 3,
        'type'             => 1,
        'status'           => 1,
    ];
    if ($assign_me && $glpi_uid > 0) {
        $input['users_id_recipient'] = $glpi_uid;
    } elseif ($assignee_id > 0) {
        $input['users_id_assign'] = $assignee_id;
    }

    $resp = gexe_glpi_rest_request('ticket_create', 'POST', '/Ticket', [ 'input' => $input ]);
    if (is_wp_error($resp)) {
        wp_send_json(['ok' => false, 'error' => 'network_error'], 500);
    }

    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    if ($code === 200 || $code === 201) {
        $ticket_id = 0;
        if (is_array($body) && isset($body['id'])) {
            $ticket_id = intval($body['id']);
        } elseif (is_array($body) && isset($body['ticket']['id'])) {
            $ticket_id = intval($body['ticket']['id']);
        }
        wp_send_json([
            'ok'        => true,
            'ticket_id' => $ticket_id
        ]);
    }

    $message = '';
    if (is_array($body) && isset($body['message'])) {
        $message = (string) $body['message'];
    }
    if ($message === '') {
        $message = 'GLPI API error';
    }
    wp_send_json(['ok' => false, 'error' => $message], $code);
}
