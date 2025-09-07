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
require_once __DIR__ . '/includes/executors-cache.php';
require_once __DIR__ . '/includes/glpi-form-data.php';
require_once __DIR__ . '/includes/glpi-auth-map.php';

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
        wp_send_json(['error' => 'not_logged_in'], 401);
    }

    $wp_uid   = get_current_user_id();
    $glpi_uid = gexe_get_current_glpi_user_id($wp_uid);
    if (!$glpi_uid) {
        wp_send_json(['error' => 'no_glpi_id_for_current_user'], 422);
    }

    $payload_raw = isset($_POST['payload']) ? stripslashes((string)$_POST['payload']) : '';
    $payload = json_decode($payload_raw, true);
    if (!is_array($payload)) $payload = [];

    $name        = isset($payload['name'])        ? sanitize_text_field($payload['name']) : '';
    $content     = isset($payload['content'])     ? sanitize_textarea_field($payload['content']) : '';
    $cat_id      = isset($payload['category_id']) ? intval($payload['category_id']) : 0;
    $loc_id      = isset($payload['location_id']) ? intval($payload['location_id']) : 0;
    $assign_me   = !empty($payload['assign_me']);
    $assignee_wp_id = isset($payload['assignee_id']) ? intval($payload['assignee_id']) : 0;

    if ($name === '' || $content === '') {
        wp_send_json(['error' => 'bad_request'], 400);
    }

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
    $due_raw = isset($payload['due_date']) ? sanitize_text_field($payload['due_date']) : '';
    $due_iso = gexe_iso_datetime($due_raw);
    if (null !== $due_iso) {
        $input['due_date'] = $due_iso;
    }

    $input['users_id_recipient'] = $glpi_uid;

    $assign_glpi_id = $glpi_uid;
    if (!$assign_me) {
        if ($assignee_wp_id > 0) {
            $assign_glpi_id = gexe_get_current_glpi_user_id($assignee_wp_id);
            if (!$assign_glpi_id) {
                wp_send_json(['error' => 'assignee_not_mapped_to_glpi'], 422);
            }
        } else {
            $assign_glpi_id = null;
        }
    }
    if ($assign_glpi_id) {
        $input['users_id_assign'] = $assign_glpi_id;
    }

    $resp = gexe_glpi_rest_request('ticket_create', 'POST', '/Ticket', [ 'input' => $input ]);
    if (is_wp_error($resp)) {
        gexe_log_action(sprintf('[ticket-create] wp=%d glpi_recipient=%d glpi_assign=%d err="%s"', $wp_uid, $glpi_uid, (int)$assign_glpi_id, $resp->get_error_message()));
        wp_send_json(['error' => 'glpi_api_failed', 'details' => $resp->get_error_message()], 502);
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
        gexe_log_action(sprintf('[ticket-create] wp=%d glpi_recipient=%d glpi_assign=%d id=%d', $wp_uid, $glpi_uid, (int)$assign_glpi_id, $ticket_id));
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
    gexe_log_action(sprintf('[ticket-create] wp=%d glpi_recipient=%d glpi_assign=%d err="%s"', $wp_uid, $glpi_uid, (int)$assign_glpi_id, $message));
    $status = ($code >= 400 && $code < 500) ? 400 : 502;
    wp_send_json(['error' => 'glpi_api_failed', 'details' => $message], $status);
}
